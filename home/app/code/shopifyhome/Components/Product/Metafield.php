<?php

/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Product;

use App\Shopifyhome\Components\Core\Common;
use App\Core\Models\User;
use App\Connector\Models\QueuedTasks;
use Exception;

class Metafield extends Common
{

    const MARKETPLACE = 'shopify';
    const PROCESS_CODE = 'metafield_import';
    const QUEUE_NAME = 'shopify_metafield_import';
    const REQUEST_STATUS_DELAY = 180; // 3 mintues
    const READ_JSON_FILE_BATCH_SIZE = 2000;
    const BULK_WRITE_CHUNK = 500;
    private $logFile;
    private $appCode;
    private $appTag;
    private $metafieldStuckCheck;

    /**
     * Function to initilize objects
     */
    public function initilize()
    {
        $this->sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $this->apiClient = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient");
        $this->vistarHelper = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Vistar\Helper');
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->queuedTaskContainer = $this->mongo->getCollection("queued_tasks");
        $this->productContainer = $this->mongo->getCollection("product_container");
        $this->metafieldDefinitionsContainer = $this->mongo->getCollection("product_metafield_definitions_container");
    }

    /**
     * Function starting metafield sync process based on user_id and filter_type
     *
     * @param array $data
     * @return array
     */
    public function initiateMetafieldSync($data)
    {
        try {
            $this->initilize();
            if (isset($data['user_id'], $data['filter_type'])) {
                $userId = $data['user_id'];
                $this->metafieldStuckCheck = $data['metafield_stuck_check'] ?? 23;
                $this->logFile = "shopify/metafieldSync/$userId/" . date('d-m-Y') . '.log';
                $this->di->getLog()->logContent('Starting Process for UserId: ' . json_encode($userId, true), 'info', $this->logFile);
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    $shopData = $this->getShopData();
                    if (!empty($shopData)) {
                        $this->setAppTag($data, $shopData);
                        $queuedTaskId = $this->setQueuedTask($userId, $shopData);
                        if (is_string($queuedTaskId)) {
                            $data['feed_id'] = $queuedTaskId;
                            $this->sqsHelper->pushMessage($this->prepareSqsData($data, $shopData));
                            return ['success' => true, 'message' => 'Metafield field importing initiated'];
                        } else {
                            $message = $queuedTaskId;
                        }
                    } else {
                        $message = 'Shop not found';
                    }
                } else {
                    $message = 'Unable to set di';
                }
            } else {
                $message = 'Required params missing(user_id, filter_type)';
            }
            $this->di->getLog()->logContent(json_encode($message), 'info', $this->logFile);
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from initiateMetafieldSyncCRON(): ' . json_encode($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    /**
     * Function fetches source shopData
     *
     * @return array
     */
    private function getShopData()
    {
        $shops = $this->di->getUser()->shops ?? [];
        if (!empty($shops)) {
            foreach ($shops as $shop) {
                if (
                    $shop['marketplace'] == self::MARKETPLACE
                    && $shop['apps'][0]['app_status'] == 'active' &&
                    !isset($shop['store_closed_at'])
                ) {
                    return $shop;
                }
            }
        }
        return [];
    }

    /**
     * Function fetches source shopData
     *
     * @return null
     */
    private function setAppTag($data, $shopData)
    {
        $this->appCode = $shopData['apps'][0]['code'] ?? 'default';
        $this->appTag = $data['app_tag'] ?? $this->di->getAppCode()->getAppTag();
        $this->di->getAppCode()->setAppTag($this->appTag);
    }

    /**
     * Function to setQueued task based on userId and shop
     *
     * @return string | array
     */
    private function setQueuedTask($userId, $shop)
    {
        try {
            $this->logFile = "shopify/metafieldSync/$userId/" . date('d-m-Y') . '.log';
            $queueData = [
                'user_id' => $userId,
                'message' => "Just a moment! We're retrieving your products metafield from the source catalog. We'll let you know once it's done.",
                'process_code' => self::PROCESS_CODE,
                'marketplace' => $shop['marketplace']
            ];
            $queuedTask = new QueuedTasks;
            $queuedTaskId = $queuedTask->setQueuedTask($shop['_id'], $queueData);
            if (!$queuedTaskId) {
                if ($this->checkMetafieldProgressStuck($userId, $shop)) {
                    $queuedTask = new QueuedTasks;
                    $queuedTaskId = $queuedTask->setQueuedTask($shop['_id'], $queueData);
                    $this->di->getLog()->logContent('New queued task created queuedTaskId: ' . json_encode($queuedTaskId), 'info', $this->logFile);
                } else {
                    return ['success' => false, 'message' => 'Metafield Import process is already under progress. Please check notification for updates.'];
                }
            }
            return $queuedTaskId;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from Metafield setQueuedTask(): ' . json_encode($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    /**
     * Function to reinitiate metafield process if stuck from last 24 hours
     * @param [string] $userId
     * @param [array] $shop
     * @return bool
     */
    private function checkMetafieldProgressStuck($userId, $shop)
    {
        try {
            $this->logFile = "shopify/metafieldSync/$userId/" . date('d-m-Y') . '.log';
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $queuedTaskData = $this->queuedTaskContainer->findOne([
                'user_id' => $userId,
                'shop_id' => $shop['_id'],
                'process_code' => self::PROCESS_CODE,
                'marketplace' => self::MARKETPLACE
            ], $options);
            if (isset($queuedTaskData['updated_at'])) {
                $updatedAtDate = \DateTime::createFromFormat('Y-m-d H:i:s', $queuedTaskData['updated_at']);
                $now = new \DateTime();
                if ($updatedAtDate instanceof \DateTime) {
                    $intervalInSeconds = $now->getTimestamp() - $updatedAtDate->getTimestamp();
                    if ($intervalInSeconds > 24 * 3600) {
                        $this->di->getLog()->logContent('Metafield Sync Stuck for user: ' . json_encode($queuedTaskData), 'info', $this->logFile);
                        $this->queuedTaskContainer->deleteOne([
                            '_id' => $queuedTaskData['_id']
                        ]);
                        return true;
                    }
                }
            } elseif (isset($queuedTaskData['created_at'])) {
                $createdAtDate = new \DateTime($queuedTaskData['created_at']);
                $now = new \DateTime();
                if ($createdAtDate instanceof \DateTime) {
                    $intervalInSeconds = $now->getTimestamp() - $createdAtDate->getTimestamp();
                    if ($intervalInSeconds > $this->metafieldStuckCheck * 3600) {
                        $this->di->getLog()->logContent('Metafield Sync Stuck for user: ' . json_encode($queuedTaskData), 'info', $this->logFile);
                        $this->queuedTaskContainer->deleteOne([
                            '_id' => $queuedTaskData['_id']
                        ]);
                        return true;
                    }
                }
            }
            return false;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from global checkMetafieldProgressStuck(): ' . json_encode($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    /**
     * Function to SQS handler data
     * @param [string] $data
     * @param [array] $shop
     * @return array
     */
    private function prepareSqsData($data, $shop)
    {
        return [
            'type' => 'full_class',
            'class_name' => Metafield::class,
            'method' => 'startMetafieldImportProcess',
            'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
            'shop_id' => $shop['_id'],
            'shop' => $shop,
            'feed_id' => $data['feed_id'],
            'filter_type' => $data['filter_type'],
            'updated_at' => $data['updated_at'] ?? null,
            'queue_name' => self::QUEUE_NAME,
            'appCode' => $this->appCode,
            'app_code' => [$this->appCode => $this->appCode],
            'app_codes' => [$this->appCode],
            'appTag' => $this->appTag,

        ];
    }

    /**
     * Function to start metafield importing process and creating bulkOperation File
     * @param [array] $sqsData
     * @return array
     */
    public function startMetafieldImportProcess($sqsData = [])
    {
        try {
            $this->initilize();
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $this->logFile = "shopify/metafieldSync/$userId/" . date('d-m-Y') . '.log';
            $shop = $sqsData['shop'];
            $remotePayload = [
                'shop_id' => $shop['remote_shop_id'],
                'type' => $sqsData['filter_type']
            ];
            if (!empty($sqsData['updated_at'])) {
                $remotePayload['updated_at'] = $sqsData['updated_at'];
            }
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('shopify', true)
                ->call('/bulk/operation', [], $remotePayload, 'POST');
            if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                $this->di->getLog()->logContent("BulkOperation success remoteResponse: " . json_encode($remoteResponse), 'info', $this->logFile);
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => Metafield::class,
                    'method' => 'checkMetafieldBulkOperationStatus',
                    'user_id' => $sqsData['user_id'],
                    'shop_id' => $sqsData['shop_id'],
                    'remote_shop_id' => $shop['remote_shop_id'],
                    'queue_name' => self::QUEUE_NAME,
                    'data' => $remoteResponse['data'],
                    'feed_id' => $sqsData['feed_id'],
                    'delay' => self::REQUEST_STATUS_DELAY
                ];
                $this->sqsHelper->pushMessage($handlerData);
                return ['success' => true, 'message' => 'Metafield Bulk File Creation Process Completed'];
            } else {
                if (empty($remoteResponse) || isset($remoteResponse['message']) && $remoteResponse['message'] == 'Error Obtaining token from Remote Server. Kindly contact Remote Host for more details.') {
                    $message = 'Error Obtaining token from Remote Server. Kindly contact Remote Host for more details';
                    $this->di->getLog()->logContent("$message, remoteResponse: " . json_encode($remoteResponse ?? 'Something went wrong'), 'info', $this->logFile);
                    $this->sqsHelper->pushMessage($sqsData);
                } elseif (isset($remoteResponse['msg']) && is_string($remoteResponse['msg']) && str_contains($remoteResponse['msg'], 'A bulk query operation for this app and shop is already in progress')) {
                    $message = "A bulk operation is already running error will retry again after 3 min";
                    $this->di->getLog()->logContent("$message, remoteResponse: " . json_encode($remoteResponse ?? 'Something went wrong'), 'info', $this->logFile);
                    $sqsData['delay'] = self::REQUEST_STATUS_DELAY;
                    $this->sqsHelper->pushMessage($sqsData);
                } else {
                    $message = 'Something went wrong in startingMetafieldImportProcess';
                    $this->di->getLog()->logContent("$message, remoteResponse: " . json_encode($remoteResponse ?? 'Something went wrong'), 'info', $this->logFile);
                    $this->removeEntryFromQueuedTask(self::PROCESS_CODE);
                    $this->updateActivityLog($sqsData, false, $message ?? 'Something went wrong');
                }
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception startMetafieldImportProcess(), Error: ' . print_r($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    /**
     * Function to check the metafield bulk operation file status
     * @param [array] $sqsData
     * @return array
     */
    public function checkMetafieldBulkOperationStatus($sqsData = [])
    {
        try {
            $this->initilize();
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $this->logFile = "shopify/metafieldSync/$userId/" . date('d-m-Y') . '.log';
            $bulkOperationId = $sqsData['data']['id'];
            $remoteShopId = $sqsData['remote_shop_id'];
            $remotePayload = [
                'shop_id' => $remoteShopId,
                'id' => $bulkOperationId,
            ];
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('shopify', true)
                ->call('/bulk/operation', [], $remotePayload, 'GET');
            if (isset($remoteResponse['data']['status']) && (($remoteResponse['data']['status'] == 'RUNNING') || ($remoteResponse['data']['status'] == 'CREATED'))) {
                $this->di->getLog()->logContent('Still in Running Status', 'info', $this->logFile);
                $this->sqsHelper->pushMessage($sqsData);
                return ['success' => true, 'message' => 'Re-calling checkMetafieldBulkOperationStatus again status not COMPLETED yet.'];
            } elseif (isset($remoteResponse['data']['status']) && $remoteResponse['data']['status'] == 'COMPLETED') {
                $this->di->getLog()->logContent('Bulk Operation Status Completed' . json_encode($remoteResponse), 'info', $this->logFile);
                if (isset($remoteResponse['data']['objectCount']) && $remoteResponse['data']['objectCount'] == 0) {
                    $this->di->getLog()->logContent('No Products found terminating process', 'info', $this->logFile);
                    $this->removeEntryFromQueuedTask(self::PROCESS_CODE);
                    $this->updateActivityLog($sqsData, false, 'No Product(s) found');
                    return ['success' => false, 'message' => 'No Products found terminating process'];
                }
                if (!empty($remoteResponse['data']['url'])) {
                    $isFileSaved = $this->vistarHelper->productsMetafieldSaveFile($remoteResponse['data']['url']);
                    if ($isFileSaved) {
                        $sqsData['data']['file_path'] = $isFileSaved['file_path'];
                        unset($sqsData['delay']);
                        $sqsData['method'] = 'readMetafieldJSONL';
                        $this->sqsHelper->pushMessage($sqsData);
                        return ['success' => true, 'message' => 'File saved successfully'];
                    } else {
                        $message = 'Unable to save file something went wrong';
                        $this->di->getLog()->logContent("$message, saveFile response: " . json_encode($isFileSaved), 'info', $this->logFile);
                    }
                } else {
                    $message = 'URL not found';
                    $this->di->getLog()->logContent("$message, remoteResponse: " . json_encode($remoteResponse), 'info', $this->logFile);
                }
                $this->removeEntryFromQueuedTask(self::PROCESS_CODE);
                $this->updateActivityLog($sqsData, false, $message ?? 'Something went wrong');
                return ['success' => false, 'message' => $message ?? 'Something went wrong'];
            }
            $this->removeEntryFromQueuedTask(self::PROCESS_CODE);
            $this->updateActivityLog($sqsData, false, 'Something went wrong while importing metafield');
            $this->di->getLog()->logContent("Process terminated while checking bulkFile status, sqsData: " . json_encode($sqsData), 'info', $this->logFile);
            return ['success' => false, 'message' => 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception checkMetafieldBulkOperationStatus(), Error: ' . print_r($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    /**
     * Function to read product data chunk wise from JSONL file
     * @param [array] $sqsData
     * @return array
     */
    public function readMetafieldJSONL($sqsData)
    {
        try {
            $this->initilize();
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $this->logFile = "shopify/metafieldSync/$userId/" . date('d-m-Y') . '.log';
            $filePath = $sqsData['data']['file_path'];
            if (!file_exists($filePath)) {
                $this->removeEntryFromQueuedTask(self::PROCESS_CODE);
                $this->updateActivityLog($sqsData, false, 'File not found');
                return ['success' => false, 'message' => 'File not found'];
            }
            if (!isset($sqsData['data']['total_rows'])) {
                $totalRows = $this->countJsonlLines($filePath);
                $sqsData['data']['total_rows'] = $totalRows;
                $sqsData['data']['updated_products_count'] = 0;
            }
            $batchSize = self::READ_JSON_FILE_BATCH_SIZE;
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                $this->removeEntryFromQueuedTask(self::PROCESS_CODE);
                $this->updateActivityLog($sqsData, false, 'Unable to read JSONL File');
                return ['success' => false, 'message' => 'Unable to read JSONL File'];
            }
            $seek = $sqsData['data']['seek'] ?? 0;
            fseek($handle, $seek);
            $parentMetafieldData = [];
            $variantMetafieldData = [];
            $linesRead = 0;
            $currentParentId   = null;
            while (!feof($handle)) {
                $line = fgets($handle);
                if ($line === false) break;

                $line = trim($line);
                if ($line === '') continue;
                $data = json_decode($line, true);
                if (!is_array($data) || !isset($data['id'])) continue;
                $idParts = explode('/', $data['id']);

                if (isset($idParts[3]) && ($idParts[3] === 'Product' || $idParts[3] === 'ProductVariant')) {
                    if ($linesRead >= $batchSize && $currentParentId !== null) {
                        break;
                    }

                    $currentParentId   = $idParts[4] ?? null;
                }

                if (isset($idParts[3]) && $idParts[3] === 'Metafield') {
                    $parentIdParts = explode('/', $data['__parentId']);
                    if (isset($parentIdParts[3]) && $parentIdParts[3] == 'Product') {
                        $parentMetafieldData[$parentIdParts[4]][] = $data;
                    } elseif (isset($parentIdParts[3]) && $parentIdParts[3] == 'ProductVariant') {
                        $variantMetafieldData[$parentIdParts[4]][] = $data;
                    }
                }

                $linesRead++;
            }

            if ($linesRead == 0) {
                $totalUpdatedProducts = $sqsData['data']['updated_products_count'] ?? 0;
                $this->di->getLog()->logContent("$totalUpdatedProducts product(s) metafield imported successfully.", 'info', $this->logFile);
                $this->removeEntryFromQueuedTask(self::PROCESS_CODE);
                $this->updateActivityLog($sqsData, true, "$totalUpdatedProducts product(s) metafield imported successfully");
                unlink($filePath);
                return [
                    'success' => true,
                    'message' => 'Metafield importing completed.'
                ];
            }
            if (!empty($parentMetafieldData) || !empty($variantMetafieldData)) {
                $this->moldAndSaveMetafieldData($sqsData, $parentMetafieldData, $variantMetafieldData);
                $sqsData['data']['updated_products_count'] += count($parentMetafieldData) + count($variantMetafieldData);
            }

            $seek = ftell($handle) - strlen($line) - 1;
            $sqsData['data']['seek'] = $seek;
            $sqsData['data']['rows_processed'] = ($sqsData['data']['rows_processed'] ?? 0) + $linesRead;
            $progress = round(($sqsData['data']['rows_processed'] / $sqsData['data']['total_rows']) * 100, 2);
            $sqsData['progress'] = $progress;
            $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['feed_id'], $progress, "Importing metafield(s) from product catalogue", false);
            $this->sqsHelper->pushMessage($sqsData);
            fclose($handle);
            return ['success' => true, 'message' => 'JSON file chunk completed'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception readMetafieldJSONL(), Error: ' . print_r($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    /**
     * Function count objects present in JSONL file
     * @param [string] $filePath
     * @return int
     */
    private function countJsonlLines($filePath)
    {
        $lineCount = 0;
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['success' => false, 'message' => 'File not found'];
        }

        while (!feof($handle)) {
            $line = fgets($handle);
            if (trim($line) !== '') {
                $lineCount++;
            }
        }

        fclose($handle);
        return $lineCount;
    }

    /**
     * Function to prepare parentMetafield and variantMetafield data and save in product_container
     * @param [array] $sqsData
     * @param [array] $parentMetafieldData
     * @param [array] $variantMetafieldData
     * @return null
     */
    private function moldAndSaveMetafieldData($sqsData, $parentMetafieldData, $variantMetafieldData)
    {
        try {
            $acceptedMetafieldTypes = $this->di->getConfig()->get('acceptedMetafieldTypes')
                ? $this->di->getConfig()->get('acceptedMetafieldTypes')->toArray() : [];
            $saveMetafieldDefinitions = $this->di->getConfig()->get('saveMetafieldDefinitions')
                ? true : false;
            $filter = [];
            $parentMetafieldDefinitions = [];
            $variantMetafieldDefinitions = [];
            if (!empty($parentMetafieldData)) {
                foreach ($parentMetafieldData as $productId => $metafields) {
                    $resultData = [];
                    foreach ($metafields as $metafield) {
                        if (
                            isset($metafield['namespace'], $metafield['key'], $metafield['type']) &&
                            (empty($acceptedMetafieldTypes) || in_array($metafield['type'], $acceptedMetafieldTypes))
                        ) {
                            $code = $metafield['namespace'] . '->' . $metafield['key'];
                            if ($saveMetafieldDefinitions && !in_array($code, $parentMetafieldDefinitions)) {
                                $parentMetafieldDefinitions[] = $code;
                            }
                            $resultData[$code] = [
                                'namespace' => $metafield['namespace'],
                                'key' => $metafield['key'],
                                'value' => $metafield['value'],
                                'type' => $metafield['type'],
                                'created_at' => $metafield['createdAt'],
                                'updated_at' => $metafield['updatedAt']
                            ];
                        }
                    }
                    if (!empty($resultData)) {
                        $filter[] = $this->prepareDbData($sqsData, $productId, $resultData, 'parent');
                    }
                }
                if (!empty($parentMetafieldDefinitions)) {
                    $this->saveMetafieldDefinitions($sqsData, $parentMetafieldDefinitions, 'parent');
                }
            }
            if (!empty($variantMetafieldData)) {
                foreach ($variantMetafieldData as $productId => $metafields) {
                    $resultData = [];
                    foreach ($metafields as $metafield) {
                        if (
                            isset($metafield['namespace'], $metafield['key'], $metafield['type']) &&
                            (empty($acceptedMetafieldTypes) || in_array($metafield['type'], $acceptedMetafieldTypes))
                        ) {
                            $code = $metafield['namespace'] . '->' . $metafield['key'];
                            if ($saveMetafieldDefinitions && !in_array($code, $variantMetafieldDefinitions)) {
                                $variantMetafieldDefinitions[] = $code;
                            }
                            $resultData[$code] = [
                                'namespace' => $metafield['namespace'],
                                'key' => $metafield['key'],
                                'value' => $metafield['value'],
                                'type' => $metafield['type'],
                                'created_at' => $metafield['createdAt'],
                                'updated_at' => $metafield['updatedAt']
                            ];
                        }
                    }
                    if (!empty($resultData)) {
                        $filter[] = $this->prepareDbData($sqsData, $productId, $resultData, 'variant');
                    }
                }

                if (!empty($variantMetafieldDefinitions)) {
                    $this->saveMetafieldDefinitions($sqsData, $variantMetafieldDefinitions, 'variant');
                }
            }

            if (!empty($filter)) {
                $filterChunkWise = array_chunk($filter, self::BULK_WRITE_CHUNK);
                foreach ($filterChunkWise as $filterData) {
                    $this->productContainer->bulkWrite($filterData);
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from moldAndSaveMetafieldData(): ' . json_encode($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
            throw $e;
        }
    }


    /**
     * Function to save unique metafield definitions (parent or variant)
     *
     * @param [array] $data
     * @param [array] $metafieldData
     * @param [string] $type
     * @return void
     */
    private function saveMetafieldDefinitions($data, $metafieldData, $type)
    {
        try {
            $uniqueKey = $type . "_metafield_definitions";
            $setOnInsertData = [
                'metafield_format' => 'namespace->key',
                'marketplace' => self::MARKETPLACE,
                'created_at' => date('c')
            ];
            $this->metafieldDefinitionsContainer->updateOne(
                [
                    'user_id' => $data['user_id'],
                    'shop_id' => $data['shop_id']
                ],
                [
                    '$setOnInsert' =>  $setOnInsertData,
                    '$addToSet' => [
                        $uniqueKey => [
                            '$each' => $metafieldData
                        ]
                    ],
                ],
                [
                    'upsert' => true
                ]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from saveMetafieldDefinitions(): ' . json_encode($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    /**
     * Function to prepare bulkWrite filter
     * @param [array] $sqsData
     * @param [string] $productId
     * @param [array] $metafieldData
     * @param [string] $type
     * @return array
     */
    private function prepareDbData($sqsData, $productId, $metafieldData, $type)
    {
        if ($type == 'parent') {
            $metafieldType = 'parentMetafield';
            $updateFilter =  [
                'user_id' => $sqsData['user_id'],
                'shop_id' => $sqsData['shop_id'],
                'container_id' =>  (string)$productId,
                'visibility' => 'Catalog and Search'
            ];
        } elseif ($type == 'variant') {
            $metafieldType = 'variantMetafield';
            $updateFilter = [
                'user_id' => $sqsData['user_id'],
                'shop_id' => $sqsData['shop_id'],
                'source_product_id' =>  (string)$productId
            ];
        }
        return [
            'updateOne' => [
                $updateFilter,
                [
                    '$set' => [$metafieldType => $metafieldData, 'updated_at' => date('c')]
                ],
            ]
        ];
    }

    /**
     * Logs the activity of bulk label assignment.
     * Creates notifications based on success or failure.
     *
     * @param array $data Contains marketplace and process metadata.
     * @param bool $status Success or failure status.
     * @param string $message Message to log.
     */
    private function updateActivityLog($data, $status, $message)
    {
        $notificationData = [
            'severity' => $status ? 'success' : 'critical',
            'message' => $message ?? 'Metafield Sync Process',
            "user_id" => $data['user_id'] ?? $this->di->getUser()->id,
            "marketplace" => self::MARKETPLACE,
            "appTag" => $this->di->getAppCode()->getAppTag(),
            'process_code' => self::PROCESS_CODE
        ];
        $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
            ->addNotification($data['shop_id'], $notificationData);
    }

    /**
     * Removes an entry from the queued task after the process is completed.
     *
     * @param string $processCode Unique process identifier.
     */
    private function removeEntryFromQueuedTask($processCode)
    {
        try {
            $this->queuedTaskContainer->deleteOne([
                'user_id' => $this->di->getUser()->id,
                'process_code' => $processCode
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception removeEntryFromQueuedTask(), Error: ' . print_r($e->getMessage()), 'info',  'exception.log');
            throw $e;
        }
    }

    /**
     * Function set the di by userId
     *
     * @param string $userId
     * @return array
     */
    private function setDiForUser($userId)
    {
        try {
            if ($this->di->getUser()->id == $userId) {
                return [
                    'success' => true,
                    'message' => 'User di is already set'
                ];
            }
            $getUser = User::findFirst([['_id' => $userId]]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from setDiForUser(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found.'
            ];
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin.'
            ];
        }

        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }
}
