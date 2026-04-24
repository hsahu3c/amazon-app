<?php

namespace App\Amazon\Components\Report;

use App\Core\Components\Base;
use Exception;

class VatInvoiceDataReportRequester extends Base
{
    /**
     * Requests the VAT Invoice Data report for a given Amazon order (business orders only),
     * and stores correlation in `amazon_vat_invoice_sync` so the report webhook can continue later.
     */
    public function requestForAmazonOrder(array $amazonOrder): void
    {
        try {
            $userId = (string)($amazonOrder['user_id'] ?? '');
            $amazonOrderId = (string)($amazonOrder['marketplace_reference_id'] ?? '');
            $amazonShopId = (string)($amazonOrder['marketplace_shop_id'] ?? '');

            if ($userId === '' || $amazonOrderId === '' || $amazonShopId === '') {
                return;
            }

            if (!$this->isVatNumberSyncEnabledForUser($userId)) {
                return;
            }

            if (empty($amazonOrder['is_business_order'])) {
                return;
            }

            $purchaseDate = $amazonOrder['source_created_at'] ?? null;
            if (empty($purchaseDate)) {
                return;
            }

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $vatSyncCollection = $mongo->getCollectionForTable('amazon_vat_invoice_sync');

            $syncId = "{$userId}_{$amazonShopId}_{$amazonOrderId}";
            $existing = $vatSyncCollection->findOne(
                ['_id' => $syncId],
                [
                    'projection' => ['processed_at' => 1, 'report_id' => 1],
                    'typeMap' => ['root' => 'array', 'document' => 'array']
                ]
            );
            if (!empty($existing) && empty($existing['processed_at']) && !empty($existing['report_id'])) {
                // Already requested and waiting for webhook
                return;
            }

            $shopDetails = $this->di->getObjectManager()
                ->get('\App\Core\Models\User\Details')
                ->getShop($amazonShopId, $userId);

            $remoteShopId = $shopDetails['remote_shop_id'] ?? null;
            if (empty($remoteShopId)) {
                return;
            }

            // Tight date window around purchase date
            $purchaseDt = new \DateTime((string)$purchaseDate);
            $startDt = (clone $purchaseDt)->modify('-1 day');
            $endDt = (clone $purchaseDt)->modify('+1 day');
            $startTime = $startDt->format(\DateTime::ATOM);
            $endTime = $endDt->format(\DateTime::ATOM);

            $response = $this->di->getObjectManager()
                ->get(Report::class)
                ->requestReport(
                    $remoteShopId,
                    $amazonShopId,
                    Report::GET_FLAT_FILE_VAT_INVOICE_DATA_REPORT,
                    $startTime,
                    $endTime
                );

            $reportId = null;
            if (isset($response['success'], $response['response']) && $response['success'] && !empty($response['response'])) {
                foreach ($response['response'] as $result) {
                    if (!empty($result['ReportRequestId'])) {
                        $reportId = (string)$result['ReportRequestId'];
                        break;
                    }
                }
            }

            if (empty($reportId)) {
                return;
            }

            $vatSyncCollection->updateOne(
                ['_id' => $syncId],
                [
                    '$set' => [
                        'user_id' => $userId,
                        'amazon_shop_id' => $amazonShopId,
                        'amazon_order_id' => $amazonOrderId,
                        'report_id' => $reportId,
                        'targets' => $amazonOrder['targets'] ?? [],
                        'created_at' => date('c'),
                        'processed_at' => null
                    ]
                ],
                ['upsert' => true]
            );
        } catch (Exception) {
            // silent
        }
    }

    private function isVatNumberSyncEnabledForUser(string $userId): bool
    {
        $configValue = $this->di->getConfig()->get('order_sync_vat_number');
        if ($configValue === null) {
            return false;
        }

        if (is_object($configValue) && method_exists($configValue, 'toArray')) {
            $configValue = $configValue->toArray();
        }

        if (!is_array($configValue)) {
            return false;
        }

        if (empty($configValue['enabled'])) {
            return false;
        }

        $userIds = [];
        if (isset($configValue['user_ids']) && is_array($configValue['user_ids'])) {
            $userIds = $configValue['user_ids'];
        }

        if (empty($userIds)) {
            return false;
        }

        return in_array($userId, $userIds, true);
    }
}

