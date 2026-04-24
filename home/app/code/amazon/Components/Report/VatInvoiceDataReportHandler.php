<?php
namespace App\Amazon\Components\Report;

use App\Core\Components\Base;
use Exception;

class VatInvoiceDataReportHandler extends Base
{
    /**
     * VAT Invoice Data report webhook handler.
     * Finds correlation in `amazon_vat_invoice_sync`, downloads the report, extracts buyer VAT number,
     * then updates connected Shopify order note.
     */
    public function handleWebhook(
        array $data,
        array $notificationData,
        string $userId,
        string $amazonShopId,
        string $reportId,
        string $logFile,
        string $errorLogFile
    ): void {
        try {
            if (
                !isset($notificationData['processingStatus'], $notificationData['reportDocumentId'])
                || $notificationData['processingStatus'] !== 'DONE'
            ) {
                return;
            }

            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $vatSyncCollection = $mongo->getCollectionForTable('amazon_vat_invoice_sync');

            $correlation = $vatSyncCollection->findOne(
                ['user_id' => $userId, 'report_id' => $reportId],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            );
            if (empty($correlation)) {
                $this->di->getLog()->logContent(
                    'VAT report webhook: correlation not found for reportId ' . $reportId,
                    'info',
                    $errorLogFile
                );
                return;
            }
            if (!empty($correlation['processed_at'])) {
                return;
            }

            $amazonOrderId = (string)($correlation['amazon_order_id'] ?? '');
            if ($amazonOrderId === '') {
                $this->di->getLog()->logContent(
                    'VAT report webhook: amazon_order_id missing for reportId ' . $reportId,
                    'info',
                    $errorLogFile
                );
                return;
            }

            // Amazon shop that requested the report (source of reportDocumentId + remoteShopId).
            $amazonShopIdForReport = (string)($correlation['amazon_shop_id'] ?? $amazonShopId);
            if ($amazonShopIdForReport === '') {
                $this->di->getLog()->logContent(
                    'VAT report webhook: amazon_shop_id missing for reportId ' . $reportId,
                    'info',
                    $errorLogFile
                );
                return;
            }

            $targets = $correlation['targets'] ?? [];
            if (empty($targets)) {
                // FBA timing: targets might not have been saved yet at report-request time.
                // Fetch latest order to resolve target.
                $orderCollection = $mongo->getCollectionForTable('order_container');
                $orderDoc = $orderCollection->findOne(
                    [
                        'user_id' => $userId,
                        'object_type' => 'source_order',
                        'marketplace' => 'amazon',
                        'marketplace_reference_id' => $amazonOrderId,
                    ],
                    [
                        'projection' => ['targets' => 1],
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                    ]
                );
                if (!empty($orderDoc['targets']) && is_array($orderDoc['targets'])) {
                    $targets = $orderDoc['targets'];
                }
            }
            $targetsToUpdate = $this->getValidTargetsFromTargets($targets);
            if (empty($targetsToUpdate)) {
                $this->di->getLog()->logContent(
                    'VAT report webhook: target not found for amazonOrderId ' . $amazonOrderId,
                    'info',
                    $errorLogFile
                );
                return;
            }

            $targetShop = $this->di->getObjectManager()
                ->get('\App\Core\Models\User\Details')
                ->getShop($amazonShopIdForReport, $userId);
            $remoteShopId = $targetShop['remote_shop_id'] ?? null;
            if (empty($remoteShopId)) {
                $this->di->getLog()->logContent(
                    'VAT report webhook: remote_shop_id missing for amazon_shop_id ' . $amazonShopIdForReport,
                    'info',
                    $errorLogFile
                );
                return;
            }

            $reportDocumentId = $notificationData['reportDocumentId'];
            $path = BP . DS . 'var' . DS . 'file' . DS . 'vat_invoice' . DS . $userId . DS . $amazonShopIdForReport . DS . $reportId . '.tsv';
            $reportHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Report\Report');
            $fileOrResponse = $reportHelper->fetchReportFromAmazon($remoteShopId, $notificationData['reportType'], $reportDocumentId, $path);

            // `fetchReportFromAmazon` returns either a file path (string) or ['success'=>true,'response'=>string]
            $reportContent = null;
            if (is_string($fileOrResponse) && file_exists($fileOrResponse)) {
                $reportContent = file_get_contents($fileOrResponse);
            } elseif (is_array($fileOrResponse) && isset($fileOrResponse['success'], $fileOrResponse['response']) && $fileOrResponse['success']) {
                $reportContent = $fileOrResponse['response'];
            } elseif (is_array($fileOrResponse) && isset($fileOrResponse['success'], $fileOrResponse['throttle']) && !$fileOrResponse['success'] && $fileOrResponse['throttle']) {
                // Retry later (throttled) - requeue the webhook payload
                $sqsData = $data;
                $sqsData['delay'] = 60;
                $sqsData['handle_added'] = true;
                $this->di->getMessageManager()->pushMessage($sqsData);
                return;
            }

            if (empty($reportContent)) {
                $this->di->getLog()->logContent(
                    'VAT report webhook: report content empty for reportId ' . $reportId,
                    'info',
                    $errorLogFile
                );
                return;
            }

            $buyerVatNumber = $this->extractBuyerVatNumberFromVidrTsv($reportContent, $amazonOrderId);
            if ($buyerVatNumber === null || $buyerVatNumber === '') {
                $this->di->getLog()->logContent(
                    'VAT report webhook: buyer-vat-number not found for order ' . $amazonOrderId,
                    'info',
                    $logFile
                );
                $vatSyncCollection->updateOne(
                    ['_id' => $correlation['_id']],
                    ['$set' => ['processed_at' => date('c'), 'result' => 'buyer_vat_missing']]
                );
                return;
            }

            $appendText = 'Buyer VAT Number: ' . $buyerVatNumber;

            $path = '\Service\Order';
            $method = 'appendOrderNote';
            $targetUpdateResponses = [];
            foreach ($targetsToUpdate as $target) {
                $targetOrderId = (string)($target['order_id'] ?? '');
                $targetShopIdForOrder = (string)($target['shop_id'] ?? '');
                $targetMarketplace = (string)($target['marketplace'] ?? '');
                if ($targetOrderId === '' || $targetShopIdForOrder === '' || $targetMarketplace === '') {
                    continue;
                }

                $class = $this->di->getObjectManager()
                    ->get(\App\Amazon\Components\Common\Helper::class)
                    ->checkClassAndMethodExists($targetMarketplace, $path, $method);

                if (empty($class)) {
                    $targetUpdateResponses[] = [
                        'marketplace' => $targetMarketplace,
                        'shop_id' => $targetShopIdForOrder,
                        'order_id' => $targetOrderId,
                        'success' => false,
                        'message' => 'appendOrderNote handler not found',
                    ];
                    continue;
                }

                $resp = $this->di->getObjectManager()
                    ->get($class)
                    ->$method($targetShopIdForOrder, $targetOrderId, $appendText, 'default');

                $targetUpdateResponses[] = [
                    'marketplace' => $targetMarketplace,
                    'shop_id' => $targetShopIdForOrder,
                    'order_id' => $targetOrderId,
                    'response' => isset($resp['success']) && $resp['success'] ? ['success' => true] : $resp,
                ];
            }

            $vatSyncCollection->updateOne(
                ['_id' => $correlation['_id']],
                [
                    '$set' => [
                        'processed_at' => date('c'),
                        'buyer_vat_number' => $buyerVatNumber,
                        'target_update_responses' => $targetUpdateResponses
                    ]
                ]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('VAT report webhook exception: ' . $e->getMessage(), 'info', $errorLogFile);
        }
    }

    private function getValidTargetsFromTargets(array $targets): array
    {
        $valid = [];
        foreach ($targets as $target) {
            if (!empty($target['marketplace']) && !empty($target['order_id']) && !empty($target['shop_id'])) {
                $valid[] = $target;
            }
        }
        return $valid;
    }

    /**
     * Extract buyer-vat-number from a Flat File VAT Invoice Data Report TSV for a specific order-id.
     */
    private function extractBuyerVatNumberFromVidrTsv(string $content, string $amazonOrderId): ?string
    {
        $rows = preg_split("/\r\n|\n|\r/", $content);
        if (empty($rows)) {
            return null;
        }

        $headerLine = array_shift($rows);
        if ($headerLine === null) {
            return null;
        }

        $headers = explode("\t", trim((string)$headerLine));
        $orderIdIndex = array_search('order-id', $headers, true);
        $buyerVatIndex = array_search('buyer-vat-number', $headers, true);
        if ($orderIdIndex === false || $buyerVatIndex === false) {
            return null;
        }

        foreach ($rows as $row) {
            if ($row === '') {
                continue;
            }
            $cols = explode("\t", (string)$row);
            if (!isset($cols[$orderIdIndex]) || (string)$cols[$orderIdIndex] !== $amazonOrderId) {
                continue;
            }
            $vat = $cols[$buyerVatIndex] ?? '';
            $vat = trim((string)$vat);
            if ($vat !== '') {
                return $vat;
            }
        }

        return null;
    }
}


