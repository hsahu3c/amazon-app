<?php

namespace App\Shopifyhome\Components\Product\Webhooks;

use App\Shopifyhome\Components\Core\Common;
use App\Shopifyhome\Components\Parser\Product;
use App\Shopifyhome\Components\Product\BatchImport;
use App\Connector\Components\Route\ProductRequestcontrol;
use App\Core\Models\Config\Config;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Exception;

class Contingency extends Common
{
    const MARKETPLACE = 'shopify';
    const QUEUE_NAME = 'shopify_product_webhook_contingency';
    const SINGLE_PRODUCT_IMPORT_QUEUE = 'shopify_single_product_import';
    const SHOPIFY_WEBHOOK_CONTINGENCY_DATA_READ = 'shopify_product_webhook_contingency_data_read';
    const REQUEST_STATUS_DELAY = 180; // 3 mintues delay
    const READ_JSON_FILE_BATCH_SIZE = 250;
    const PROCESS_USER_DELAY = 120; // 2 min
    const JSON_FILE_NAME = 'product_add_update_metafield_contingency';
    const CONTINGENCY_COLLECTION = 'webhook_contingency_container';
    const BATCH_IMPORT_UPDATE_ACTION = 'product_listings_update';
    const BATCH_IMPORT_ADD_ACTION = 'product_listings_add';
    const PROCESS_CODE = 'product_add_update_metafield_contingency';
    const UPDATED_AT_NOT_UPDATED_FROM = '10'; // 10 min


    /**
     * initilize function
     * To initialize required dependencies such as SQS handler, API client, and MongoDB collections.
     * @return void
     */
    public function initilize()
    {
        $this->sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $this->apiClient = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient");
        $this->vistarHelper = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Vistar\Helper');
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->queuedTaskContainer = $this->mongo->getCollection("queued_tasks");
        $this->productContainer = $this->mongo->getCollection("product_container");
        $this->notificationContainer = $this->mongo->getCollection("notifications");
    }

    /**
     * initiateWebhookContigencyProcess function
     * To initiate the webhook contingency process for the provided user by creating a bulk operation request.
     * Handles missing shop data or required parameters gracefully.
     * @param [array] $sqsData
     * @return array
     */

    public function initiateProductWebhookContingencyProcess($sqsData)
    {
        try {
            $this->initilize();
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $this->logFile = "shopify/productWebhookContingency/$userId/" . date('d-m-Y') . '.log';
            if (isset($sqsData['filter_type'], $sqsData['feed_id'])) {
                $userId = $sqsData['user_id'];
                $shopData = $this->getShopData();
                if (!empty($shopData)) {
                    $this->createBulkOperationRequest($sqsData, $shopData);
                    return ['success' => true, 'message' => 'Create Bulk Operation Request Completed'];
                } else {
                    $message = 'Shop not found';
                    $this->removeEntryFromQueuedTask($userId, $sqsData['shop_id']);
                    $this->updateActivityLog($sqsData, false, $message);
                }
            } else {
                $message = 'Required params missing(user_id, filter_type)';
                $this->removeEntryFromQueuedTask($userId, $sqsData['shop_id']);
                $this->updateActivityLog($sqsData, false, $message);
            }
            $this->di->getLog()->logContent(json_encode($message), 'info', $this->logFile);
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from initiateProductWebhookContingencyProcess(): ' . json_encode($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
        }
    }
    /**
     * getShopData function
     * To fetch the active shop data for the user associated with the Shopify marketplace.
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
     * createBulkOperationRequest function
     * To call remote bulk API and push task to SQS for further monitoring.
     * Updates MongoDB with the status of the operation.
     * @param [array] $sqsData
     * @param [array] $shop
     * @return array|null
     */
    private function createBulkOperationRequest($sqsData, $shop)
    {
        $this->initilize();
        $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
        $this->logFile = "shopify/productWebhookContingency/$userId/" . date('d-m-Y') . '.log';
        $remotePayload = [
            'shop_id' => $shop['remote_shop_id'],
            'type' => $sqsData['filter_type']
        ];

        if(isset($sqsData['updated_at'])) {
            $remotePayload['updated_at'] = $sqsData['updated_at'];
        }

        if(!empty($sqsData['sales_channel'])) {
            $remotePayload['sales_channel'] = 1;
        }

        if(!empty($sqsData['end_date'])) {
            $remotePayload['end_date'] = $sqsData['end_date'];
        }

        if ($this->checkMetafieldSettingEnabled($userId, $shop)) {
            $remotePayload['metafield_sync'] = true;
        }
        $this->di->getLog()->logContent("createBulkOperationRequest remotePayload: ".json_encode($remotePayload), 'info', $this->logFile);
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', true)
            ->call('/bulk/operation', [], $remotePayload, 'POST');
        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
            $this->di->getLog()->logContent("Bulk operation created for Product add, update, metafield, remoteResponse: " . json_encode($remoteResponse), 'info', $this->logFile);
            $handlerData = [
                'type' => 'full_class',
                'class_name' => Contingency::class,
                'method' => 'checkBulkOperationStatus',
                'user_id' => $sqsData['user_id'],
                'shop_id' => $shop['_id'],
                'remote_shop_id' => $shop['remote_shop_id'],
                'queue_name' => self::QUEUE_NAME,
                'feed_id' => $sqsData['feed_id'],
                'data' => $remoteResponse['data'],
                'delay' => self::REQUEST_STATUS_DELAY
            ];
            $setData = [
                'additional_data' => [
                    'bulk_operation_id' => $remoteResponse['data']['id'],
                    'bulk_operation_status' => $remoteResponse['data']['status']
                ]
            ];
            $this->updateQueuedTask($sqsData['feed_id'], $setData);
            $this->sqsHelper->pushMessage($handlerData);
            return ['success' => true, 'message' => 'Bulk File Creation Process Completed'];
        } else {
            // Need to handle case if a bulkOperation is already runnig for example batch import or something
            if (empty($remoteResponse) || isset($remoteResponse['message']) && $remoteResponse['message'] == 'Error Obtaining token from Remote Server. Kindly contact Remote Host for more details.') {
                $message = 'Error Obtaining token from Remote Server. Kindly contact Remote Host for more details';
                $this->di->getLog()->logContent("$message, remoteResponse: " . json_encode($remoteResponse ?? 'Something went wrong'), 'info', $this->logFile);
                $sqsData['delay'] = self::REQUEST_STATUS_DELAY;
                $this->sqsHelper->pushMessage($sqsData);
            } elseif (isset($remoteResponse['msg']) && is_string($remoteResponse['msg']) && str_contains($remoteResponse['msg'], 'A bulk query operation for this app and shop is already in progress')) {
                $message = "A bulk operation is already running error will retry again after 3 min";
                $this->di->getLog()->logContent("$message, remoteResponse: " . json_encode($remoteResponse ?? 'Something went wrong'), 'info', $this->logFile);
                $sqsData['delay'] = self::REQUEST_STATUS_DELAY;
                $setData = [
                    'additional_data' => [
                        'bulk_operation_status' => 'RUNNING'
                    ],
                    'updated_at' => date('c')
                ];
                $this->updateQueuedTask($sqsData['feed_id'], $setData);
                $this->sqsHelper->pushMessage($sqsData);
            } else {
                $message = 'Something went wrong during fetching products from Shopify';
                $this->removeEntryFromQueuedTask($userId, $sqsData['shop_id']);
                $this->updateActivityLog($sqsData, false, $message, [
                    'bulk_operation_id' => $remoteResponse['data']['id'] ?? null
                ]);
                $this->di->getLog()->logContent("Something went wrong in createBulkOperation request check logs, remoteResponse: " . json_encode($remoteResponse ?? 'Something went wrong'), 'info', $this->logFile);
            }
        }

        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    /**
     * checkMetafieldSettingEnabled function
     * To check if metafield sync setting is enabled for the given user and shop.
     * @param [string] $userId
     * @param [array] $shopData
     * @return bool
     */
    private function checkMetafieldSettingEnabled($userId, $shopData)
    {
        $configObj = $this->di->getObjectManager()
            ->get(Config::class);
        $configObj->setGroupCode('product');
        $configObj->setUserId($userId);
        $configObj->setSource($shopData['marketplace']);
        $configObj->setSourceShopId($shopData['_id']);
        $configObj->setAppTag(null);
        $settingConfiguration = $configObj->getConfig('metafield');
        if (!empty($settingConfiguration) && $settingConfiguration[0]['value']) {
            return true;
        }

        return false;
    }

    /**
     * updateQueuedTask function
     * To update the queued_task collection with the latest status or data.
     * @param [string] $objectId
     * @param [array] $setData
     * @return void
     */
    private function updateQueuedTask($objectId, $setData)
    {
        try {
            $id = new ObjectId($objectId);
            $this->queuedTaskContainer->updateOne(
                [
                    '_id' => $id
                ],
                [
                    '$set' => $setData
                ]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception updateQueuedTask(), Error: ' . json_encode($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
        }
    }

    /**
     * checkBulkOperationStatus function
     * To check the current status of the bulk operation (RUNNING/COMPLETED).
     * Re-pushes message to SQS if still running, or proceeds to next steps if completed.
     * @param [array] $sqsData
     * @return array
     */
    public function checkBulkOperationStatus($sqsData)
    {
        try {
            $this->initilize();
            $userId = $sqsData['user_id'];
            $this->logFile = "shopify/productWebhookContingency/$userId/" . date('d-m-Y') . '.log';
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
                $this->di->getLog()->logContent("Still in CREATED or RUNNING Status will check again after 3 mintues".json_encode($remoteResponse), 'info', $this->logFile);
                $setData = [
                    'additional_data.bulk_operation_status' => 'RUNNING',
                    'updated_at' => date('c')
                ];
                $this->updateQueuedTask($sqsData['feed_id'], $setData);
                $this->sqsHelper->pushMessage($sqsData);
                return ['success' => true, 'message' => 'Re-calling checkBulkOperationStatus again status not COMPLETED yet.'];
            } elseif (isset($remoteResponse['data']['status']) && $remoteResponse['data']['status'] == 'COMPLETED') {
                if (isset($remoteResponse['data']['objectCount']) && $remoteResponse['data']['objectCount'] == 0) {
                    $this->di->getLog()->logContent('No Products data found from Shopify', 'info', $this->logFile);
                    $this->removeEntryFromQueuedTask($sqsData['user_id'], $sqsData['shop_id']);
                    $this->updateActivityLog($sqsData, false, 'No Products data found from Shopify', ['additional_data.bulk_operation_id' => $bulkOperationId]);
                    return ['success' => false, 'message' => 'No Products data found from Shopify'];
                }
                $this->di->getLog()->logContent('Bulk operation status completed, remote_response: ' . json_encode($remoteResponse), 'info', $this->logFile);
                if (!empty($remoteResponse['data']['url'])) {
                    $setData = [
                        'message' => "We're fetching your new products, updated products, and metafields from the source catalog. We'll notify you as soon as it's complete.",
                        'additional_data.bulk_operation_status' => 'COMPLETED',
                        'additional_data.url' => $remoteResponse['data']['url'],
                        'updated_at' => date('c')
                    ];
                    $this->updateQueuedTask($sqsData['feed_id'], $setData);
                } else {
                    $message = 'Unable fetch products URL from Shopify';
                    $this->removeEntryFromQueuedTask($sqsData['user_id'], $sqsData['shop_id']);
                    $this->updateActivityLog($sqsData, false, $message, ['additional_data.bulk_operation_id' => $bulkOperationId]);
                    $this->di->getLog()->logContent("$message, remoteResponse: " . json_encode($remoteResponse), 'info', $this->logFile);
                }
                return ['success' => false, 'message' => $message ?? 'Something went wrong'];
            }
            $message = 'Process terminated some error occured';
            $this->di->getLog()->logContent("Process terminated while checking bulkFile status, data: " . json_encode([
                'sqsData' => $sqsData,
                'remoteResponse' => $remoteResponse
            ]), 'info', $this->logFile);
            $this->removeEntryFromQueuedTask($sqsData['user_id'], $sqsData['shop_id']);
            $this->updateActivityLog($sqsData, false, $message, ['additional_data.bulk_operation_id' => $bulkOperationId]);
            return ['success' => false, 'message' => 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception checkMetafieldBulkOperationStatus(), Error: ' . json_encode($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
        }
    }


    /**
     * processBulkOperationCompletedUsers function
     * To process users whose bulk operations are marked as COMPLETED and initiate data reading for inventory sync.
     * Used to handle post-bulk-operation inventory sync by marking processing status and triggering the next step.
     * @param [array] $sqsData
     * @return array|null
     */
    public function processBulkOperationCompletedUsers($sqsData)
    {
        try {
            $this->initilize();
            $this->logFile = "shopify/productWebhookContingency/processUsers/" . date('d-m-Y') . '.log';
            $options = [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ];
            $eligibleData = $this->queuedTaskContainer->find([
                'process_code' => self::PROCESS_CODE,
                'additional_data.bulk_operation_status' => 'COMPLETED',
                'additional_data.user_processing' => ['$exists' => false],
                'created_at' => ['$gt' => date('Y-m-d')],
                'updated_at' => ['$lt' => (new \DateTime('-5 minutes'))->format('Y-m-d\TH:i:sP')]
            ], $options)->toArray();
            if (!empty($eligibleData)) {
                foreach ($eligibleData as $userData) {
                    $this->di->getLog()->logContent("Starting data reading process for userId: " . json_encode($userData['user_id']), 'info', $this->logFile);
                    $objectId = new ObjectId((string)$userData['_id']);
                    $this->queuedTaskContainer->updateOne(
                        [
                            '_id' => $objectId
                        ],
                        [
                            '$set' => [
                                'additional_data.user_processing' => 'IN_PROGRESS'
                            ]
                        ]
                    );
                    if (!empty($sqsData['sync_on_target'])) {
                        $userData['sync_on_target'] = $sqsData['sync_on_target'];
                    }
                    $this->saveJSONFileAndIntiateReadingProcess($userData);
                }
            } else {
                $bulkOperationUsers = $this->queuedTaskContainer->find([
                    'process_code' => self::PROCESS_CODE,
                    'additional_data.bulk_operation_status' => ['$in' => ['CREATED', 'RUNNING', 'COMPLETED']],
                    'additional_data.user_processing' => ['$exists' => false],
                    'created_at' => ['$gt' => date('Y-m-d')]
                ], $options)->toArray();
                if (empty($bulkOperationUsers)) {
                    $this->di->getLog()->logContent("No users found to process product webhook data", 'info', $this->logFile);
                    return ['success' => true, 'message' => 'All users processed'];
                } else {
                    $countUsers = count($bulkOperationUsers);
                    $this->di->getLog()->logContent("Total $countUsers users are still present with CREATED or COMPLETED status will retry after 2 min again to process them", 'info', $this->logFile);
                }
            }
            $sqsData['delay'] = self::PROCESS_USER_DELAY;
            $this->sqsHelper->pushMessage($sqsData);
            return ['success' => true, 'message' => 'Users Process Initiated'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception processBulkOperationCompletedUsers(), Error: ' . json_encode($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
        }
    }

    /**
     * saveJSONFileAndIntiateReadingProcess function
     * To initiate data reading process.
     * @param [array] $data
     * @return array
     */
    public function saveJSONFileAndIntiateReadingProcess($data)
    {
        try {
            $this->initilize();
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $logFile = "shopify/productWebhookContingency/$userId/" . date('d-m-Y') . '.log';
            $handlerData = [
                'type' => 'full_class',
                'class_name' => Contingency::class,
                'method' => 'initiateDataReadingProcess',
                'user_id' => $userId,
                'shop_id' => $data['shop_id'],
                'data' => [
                    'bulk_operation_id' => $data['additional_data']['bulk_operation_id'],
                    'url' => $data['additional_data']['url'],
                ],
                'queue_name' => self::SHOPIFY_WEBHOOK_CONTINGENCY_DATA_READ,
                'feed_id' => (string)$data['_id']
            ];
            if (isset($data['sync_on_target'])) {
                $handlerData['data']['sync_on_target'] = $data['sync_on_target'];
            }
            $this->di->getLog()->logContent('Calling initiateDataReadingProcess handlerData: ' . json_encode($handlerData), 'info', $logFile);
            $this->sqsHelper->pushMessage($handlerData);
            return ['success' => true, 'message' => 'Process initiated'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception saveJSONFileAndIntiateReadingProcess(), Error: ' . json_encode($e->getMessage()), 'info',  $logFile ?? 'exception.log');
        }
    }

    /**
     * initiateDataReadingProcess function
     * Handles the SQS message for initiating the reading of JSONL data.
     * Verifies if the message visibility timeout is valid before saving the file and processing it.
     * Updates the database accordingly in case of failure or timeout.
     *
     * @param array $sqsData The SQS message payload containing user and file metadata.
     * @return array Response indicating the status and message of the process execution.
     */
    public function initiateDataReadingProcess($sqsData)
    {
        $this->initilize();
        $userId = $sqsData['user_id'];
        $logFile = "shopify/productWebhookContingency/$userId/" . date('d-m-Y') . '.log';
        $checkQueueMessageStatus = $this->checkMessageVisibilityTimeOut($sqsData);
        if (isset($checkQueueMessageStatus['success']) && $checkQueueMessageStatus['success']) {
            $isFileSaved = $this->vistarHelper->saveJSONLFile($sqsData['data']['url'], self::JSON_FILE_NAME);
            if ($isFileSaved) {
                $sqsData['data']['file_path'] = $isFileSaved['file_path'];
                $this->di->getLog()->logContent("Called readJSONL file for filePath:" . json_encode($isFileSaved['file_path']), 'info', $logFile);
                $this->readJSONLFile($sqsData);
            } else {
                $message = 'Unable to save products file something went wrong';
                $this->removeEntryFromQueuedTask($sqsData['user_id'], $sqsData['shop_id']);
                $this->updateActivityLog($sqsData, false, $message);
                $this->di->getLog()->logContent("$message, saveFile response: " . json_encode($isFileSaved), 'info', $logFile);
            }
        } elseif (isset($checkQueueMessageStatus['success'], $checkQueueMessageStatus['skip_delete_message'])) {
            return ['success' => false, 'message' => 'Visibility Timeout or Worker kill case', 'skip_delete_message' => true];
        }
        return ['success' => true, 'message' => 'Process completed'];
    }

    /**
     * checkMessageVisibilityTimeOut function
     * Checks whether the message visibility timeout has expired by verifying if data_reading is already marked 'IN_PROGRESS'.
     * If not marked, it sets the data_reading flag and allows the process to continue.
     *
     * @param array $sqsData The SQS message payload used to identify the record in the collection.
     * @return bool True if visibility timeout is valid and processing can proceed, false otherwise.
     */
    private function checkMessageVisibilityTimeOut($sqsData)
    {
        try {
            $userId = $sqsData['user_id'];
            $this->logFile = "shopify/productWebhookContingency/$userId/" . date('d-m-Y') . '.log';
            $id = new ObjectId($sqsData['feed_id']);
            $options = [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ];

            $queuedTaskData = $this->queuedTaskContainer->findOne([
                '_id' => $id
            ], $options);
            if (empty($queuedTaskData)) {
                $this->di->getLog()->logContent('Empty queued task terminating process', 'info', $this->logFile);
                return ['success' => false];
            } elseif (empty($queuedTaskData['additional_data']['data_reading'])) {
                $this->queuedTaskContainer->updateOne(
                    [
                        '_id' => $id
                    ],
                    [
                        '$set' => ['additional_data.data_reading' => 'IN_PROGRESS', 'additional_data.user_processing' => 'COMPLETED']
                    ]
                );
                return ['success' => true];
            } else {
                if ($queuedTaskData['additional_data']['data_reading'] == 'COMPLETED') {
                    return ['success' => false];
                } elseif ($queuedTaskData['additional_data']['data_reading'] == 'IN_PROGRESS') {
                    $lastUpdated = strtotime($queuedTaskData['updated_at']);
                    $currentTime = time();
                    $timeDiffInMinutes = ($currentTime - $lastUpdated) / 60;

                    if ($timeDiffInMinutes > self::UPDATED_AT_NOT_UPDATED_FROM) {
                        // If last updated more than 10 minutes ago, allow message to process
                        $this->queuedTaskContainer->updateOne(
                            ['_id' => $id],
                            ['$set' => ['updated_at' => date('c')]]
                        );
                        $this->di->getLog()->logContent('Process stuck from last 10 min re-initiating for queuedTask: ' . json_encode($queuedTaskData), 'info', $this->logFile);

                        return ['success' => true];
                    } else {
                        $this->di->getLog()->logContent('Visibility Timeout re-queuing message sqsData: ' . json_encode($sqsData), 'info', $this->logFile);
                        return ['success' => false, 'skip_delete' => true];
                    }
                }
            }
            return ['success' => false];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception checkMessageVisibilityTimeOut(), Error: ' . json_encode($e->getMessage()), 'info', $this->logFile ?? 'exception.log');
        }
    }

    /**
     * readJSONLFile function
     * To read JSONL file in batches, process the product data, and push remaining data to SQS if needed.
     * Marks the operation as completed in MongoDB after reading finishes.
     * @param [array] $sqsData
     * @return array
     */
    public function readJSONLFile($sqsData)
    {
        try {
            $this->initilize();
            $userId = $sqsData['user_id'];
            $this->logFile = "shopify/productWebhookContingency/$userId/" . date('d-m-Y') . '.log';

            $filePath = $sqsData['data']['file_path'];
            $filter = [
                'limit' => self::READ_JSON_FILE_BATCH_SIZE,
            ];
            if (!isset($sqsData['data']['total_rows'])) {
                $totalRows = $this->countVariantProductsInJsonl($filePath);
                $sqsData['data']['total_rows'] = $totalRows;
                $sqsData['data']['updated_products_count'] = 0;
            }
            if (isset($sqsData['data']['cursor']) && $sqsData['data']['cursor'] != 1) {
                $filter['next'] = $sqsData['data']['cursor'];
            } else {
                $lastProcessedData = $this->checkLastProcessedData($sqsData['feed_id']);
                if (!empty($lastProcessedData)) {
                    if (isset($lastProcessedData['additional_data']['next_cursor'])) {
                        $filter['next'] = $lastProcessedData['additional_data']['next_cursor'];
                        $sqsData['data']['rows_processed'] = $lastProcessedData['additional_data']['rows_processed'];
                        $sqsData['data']['updated_products_count'] = $lastProcessedData['additional_data']['updated_products_count'];
                    }
                }
            }

            $parserObj = $this->di->getObjectManager()->create(Product::class, [$filePath]);
            $response =  $parserObj->getVariants($filter);
            $productData = $this->vistarHelper->shapeProductResponse($response);

            foreach ($productData['data'] as $key => $product) {
                if (isset($product['id'])) unset($product['id']);
                $formatProduct = $product;
                $productData['data'][$key] = $formatProduct;
            }

            $totalVariant = ($sqsData['data']['total_count'] ?? 0) + $productData['count']['variant'];
            $syncUpdatedDataResponse = $this->syncUpdatedDataInDBAndTarget($sqsData, $productData['data']);
            $sqsData['data']['rows_processed'] = ($sqsData['data']['rows_processed'] ?? 0) + $totalVariant;
            $progress = round(($sqsData['data']['rows_processed'] / $sqsData['data']['total_rows']) * 100, 2);
            $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['feed_id'], $progress, "Syncing products(s) data from product catalogue", false);
            $sqsData['data']['updated_products_count'] = $sqsData['data']['updated_products_count'] + ($syncUpdatedDataResponse['data'] ?? 0);
            if (isset($productData['cursors']['next'])) {
                $sqsData['data']['cursor'] = $productData['cursors']['next'];
                $setData = [
                    'progress' => $progress,
                    'message' => 'Syncing products(s) data from product catalogue',
                    'additional_data.next_cursor' => $productData['cursors']['next'],
                    'additional_data.updated_products_count' => $sqsData['data']['updated_products_count'],
                    'additional_data.rows_processed' => $sqsData['data']['rows_processed']
                ];
                $this->updateQueuedTask($sqsData['feed_id'], $setData);
                $this->readJSONLFile($sqsData);
            } else {
                //Not updating progress if data is less than 250 products as no need to update progress and then delete queued task
                unlink($filePath);
                $totalUpdatedCount = $sqsData['data']['updated_products_count'] ?? 0;
                $message = "Total $totalUpdatedCount product(s) data updated successfully";
                $this->di->getLog()->logContent("Process Completed $message", 'info', $this->logFile);
                $this->removeEntryFromQueuedTask($userId, $sqsData['shop_id']);
                $this->updateActivityLog($sqsData, true, $message, ['bulk_operation_id' => $sqsData['data']['bulk_operation_id']]);
            }
            return ['success' => true, 'message' => 'Process completed'];
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception readJSONLFile(), Error: ' . json_encode($e->getMessage()), 'info', $this->logFile ?? 'exception.log');
        }
    }

    /**
     * Function count ProductVariant objects present in JSONL file
     * @param [string] $filePath
     * @return int
     */
    private function countVariantProductsInJsonl($filePath)
    {
        $count = 0;

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['success' => false, 'message' => 'File not found'];
        }

        while (!feof($handle)) {
            $line = fgets($handle);
            if (trim($line) === '') {
                continue;
            }

            $json = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE || empty($json['id'])) {
                continue;
            }

            // Match only pure "ProductVariant" type (not Parent, Metafield, etc.)
            if (preg_match('#^gid://shopify/ProductVariant/\d+$#', $json['id'])) {
                $count++;
            }
        }

        fclose($handle);
        return $count;
    }

    /**
     * checkLastProcessedData function
     * To retrieve the last processed feed data from the queued task collection.
     * Used to check whether a feed has already been processed to avoid duplication.
     * @param [string] $feedId
     * @return array|null
     */
    private function checkLastProcessedData($feedId)
    {
        try {
            $options = [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ];
            $contingencyUserData = $this->queuedTaskContainer->findOne([
                '_id' => new ObjectId($feedId)
            ], $options);

            if (!empty($contingencyUserData)) {
                return $contingencyUserData;
            }

            return null;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception checkLastProcessedData(), Error: ' . json_encode($e->getMessage()), 'info', $this->logFile ?? 'exception.log');
        }
    }

    /**
     * syncUpdatedDataInDBAndTarget function
     * To compare newly fetched product data with existing data and update or import accordingly.
     * Also syncs inventory on target if required.
     * @param [array] $data
     * @param [array] $productsData
     * @return void
     */
    private function syncUpdatedDataInDBAndTarget($data, $productsData)
    {
        try {
            $userId = $data['user_id'];
            $this->logFile = "shopify/productWebhookContingency/$userId/" . date('d-m-Y') . '.log';
            $containerIds = array_column($productsData, 'container_id');
            $oldExistingData = [];
            $options = [
                "projection" => ['_id' => 0, 'container_id' => 1, 'source_product_id' => 1, 'source_updated_at' => 1, 'quantity' => 1, 'variant_attributes' => 1],
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ];
            $existingProductData = $this->productContainer->find([
                'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
                'shop_id' => $data['shop_id'],
                'source_marketplace' => self::MARKETPLACE,
                'container_id' => ['$in' => $containerIds]
            ], $options)->toArray();
            $existingContainerIds = [];
            if (!empty($existingProductData)) {
                foreach ($existingProductData as $productData) {
                    $oldExistingData[$productData['source_product_id']] = $productData;
                    $existingContainerIds[$productData['container_id']] = true;
                }
            }
            $skipContainerIds = [];
            $productToBeUpdatedData = [];
            foreach ($productsData as $product) {
                if (in_array($product['container_id'], $skipContainerIds)) {
                    continue;
                }
                if (isset($oldExistingData[$product['source_product_id']])) {
                    if (
                        strtotime($product['source_updated_at'])
                        > strtotime($oldExistingData[$product['source_product_id']]['source_updated_at'])
                    ) {
                        //Simple to variant and variant to simple case handling
                        if (
                            empty($oldExistingData[$product['source_product_id']]['variant_attributes'])
                            && !empty($product['variant_attributes']) || (!empty($oldExistingData[$product['source_product_id']]['variant_attributes']) && empty($product['variant_attributes']))
                        ) {
                            $skipContainerIds[] = $product['container_id'];
                            $this->di->getLog()->logContent('Initiating single product import for container_id: ' . json_encode($product['container_id']), 'info', $this->logFile);
                            $this->simpleToVariantOrVariantToSimpleCase($data, $product['container_id']);
                        } else {
                            $productToBeUpdatedData[] = $product;
                        }
                    }
                } else if (isset($existingContainerIds[$product['container_id']])) {
                    $skipContainerIds[] = $product['container_id'];
                    $this->di->getLog()->logContent('Initiating single product import as product not found for container_id: ' . json_encode($product['container_id']), 'info', $this->logFile);
                    $this->simpleToVariantOrVariantToSimpleCase($data, $product['container_id']);
                } else {
                    //Inititating Batch Import in case of new product
                    $skipContainerIds[] = $product['container_id'];
                    $preparedData = [
                        'product_listing' => [
                            'product_id' => $product['container_id'],
                            'title' => $product['title']
                        ]
                    ];
                    $batchImportPreparedData = [
                        'data' => $preparedData,
                        'user_id' => $data['user_id'],
                        'shop_id' => $data['shop_id'],
                        'action' => self::BATCH_IMPORT_ADD_ACTION
                    ];
                    $this->di->getLog()->logContent('Initiating batch product import for container_id: ' . json_encode($product['container_id']), 'info', $this->logFile);
                    $batchImport = $this->di->getObjectManager()->get(BatchImport::class);
                    $batchImport->handleBatchImport($batchImportPreparedData, self::BATCH_IMPORT_ADD_ACTION);
                }
            }

            if (!empty($productToBeUpdatedData)) {
                $additionalData = [
                    'shop_id' => $data['shop_id'],
                    'marketplace' => self::MARKETPLACE,
                    'isImported' => false,
                    'webhook_present' => true,
                ];
                $this->di->getLog()->logContent('Updating source_product_ids: ' . json_encode(array_column($productToBeUpdatedData, 'source_product_id')), 'info', $this->logFile);
                $pushToProductContainerRes = $this->di->getObjectManager()->get(ProductRequestcontrol::class)
                    ->pushToProductContainer($productToBeUpdatedData, $additionalData);
                if(isset($pushToProductContainerRes['success']) && $pushToProductContainerRes['success']) {
                    if (isset($data['data']['sync_on_target'])) {
                        $this->syncInventoryOnTarget($data, $oldExistingData, $productToBeUpdatedData);
                    }
                } else {
                    $this->di->getLog()->logContent('Unable to update products of this chunk : ' . json_encode(['productsData' => $productsData, 'data' => $data, 'pushToProductContainerRes' => $pushToProductContainerRes]), 'info', $this->logFile);
                    return ['success' => false, 'message' => 'Process Completed ', 'data' => 0];
                }

            }
            return ['success' => true, 'message' => 'Process Completed ', 'data' => count($productToBeUpdatedData)];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception syncUpdatedDataInDBAndTarget(), Error: ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * simpleToVariantOrVariantToSimpleCase function
     * To handle the edge case when a product switches between simple and variant type or vice versa.
     * Triggers a re-import of the product.
     * @param [array] $data
     * @param [string] $containerId
     * @return void
     */
    private function simpleToVariantOrVariantToSimpleCase($data, $containerId)
    {
        $handlerData = [
            'type' => 'full_class',
            'class_name' => Contingency::class,
            'method' => 'singleProductImportByContainerId',
            'user_id' => $data['user_id'],
            'shop_id' => $data['shop_id'],
            'container_id' => $containerId,
            'marketplace' => self::MARKETPLACE,
            'queue_name' => self::SINGLE_PRODUCT_IMPORT_QUEUE,
            'feed_id' => $data['feed_id']
        ];
        $this->sqsHelper->pushMessage($handlerData);
    }

    /**
     * singleProductImportByContainerId function
     * To re-import a single product from remote based on container ID.
     * Used in case of variant/simple product structure changes or missing product issues.
     * @param [array] $sqsData
     * @return array
     */
    public function singleProductImportByContainerId($sqsData)
    {
        try {
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $this->logFile = "shopify/productWebhookContigency/$userId/" . date('d-m-Y') . '.log';
            $containerId = (string) $sqsData['container_id'];
            if ($containerId) {
                $preparedData = [
                    'user_id' => $userId,
                    'container_id' => $containerId
                ];
                $response = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Import::class)
                    ->importOrUpdateProductById($preparedData);
                if (isset($response['success']) && !$response['success']) {
                    $this->di->getLog()->logContent('Error from productListingUpdate single product import: ' . json_encode([
                        'sqsData' => $sqsData,
                        'response' => $response
                    ]), 'info', $this->logFile);
                }
            } else {
                $this->di->getLog()->logContent('Blank containerId found: ' . json_encode([
                    'sqsData' => $sqsData,
                ]), 'info', $this->logFile);
            }

            return ['success' => true, 'message' => 'Single product importing completed'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception singleProductImportByContainerId(): ' . json_encode($e), 'info', 'exception.log');
        }
    }

    /**
     * syncInventoryOnTarget function
     * To sync inventory for modified products where quantity has changed on the target marketplace.
     * @param [array] $data
     * @param [array] $oldExistingData
     * @param [array] $productToBeUpdatedData
     * @return void
     */
    private function syncInventoryOnTarget($data, $oldExistingData, $productToBeUpdatedData)
    {
        $userId = $data['user_id'];
        $this->logFile = "shopify/productWebhookContingency/$userId/" . date('d-m-Y') . '.log';
        $sourceProductIds = [];
        foreach ($productToBeUpdatedData as  $productData) {
            if (
                isset($productData['quantity'], $oldExistingData[$productData['source_product_id']]['quantity'])
                && $productData['quantity'] != $oldExistingData[$productData['source_product_id']]['quantity']
            ) {
                $sourceProductIds[] = $productData['source_product_id'];
            }
        }
        if (!empty($sourceProductIds)) {
            $preparedData = [
                'source_marketplace' => self::MARKETPLACE,
                'source_shop_id' => $data['shop_id'],
                'source_product_ids' => $sourceProductIds
            ];
            $this->di->getLog()->logContent('Inventory sync initiated for source_product_ids: ' . json_encode($sourceProductIds), 'info', $this->logFile);
            // Improvement point we can skip one call of init connector where we fetch these source_product_ids again and set in cache
            $className = '\Components\Inventory\Inventory';
            $method = 'inventorySyncBackupProcess';
            $class = $this->checkClassAndMethodExists($data['data']['sync_on_target'], $className, $method);
            if (!empty($class)) {
                $inventorySyncResponse = $this->di->getObjectManager()->get($class)->$method($preparedData);
                $this->di->getLog()->logContent('Inventory Sync initated response: ' . json_encode($inventorySyncResponse), 'info', $this->logFile);
            }
        } else {
            $this->di->getLog()->logContent('No, ProductIds inventory synced in this chunk', 'info', $this->logFile);
        }
    }

    /**
     * checkClassAndMethodExists function
     * To verify if the class and method exist for inventory sync on a given marketplace.
     * Used to dynamically call marketplace-specific functionality.
     * @param [string] $marketplace
     * @param [string] $path
     * @param [string] $method
     * @return string|null
     */
    private function checkClassAndMethodExists($marketplace, $path, $method)
    {
        $moduleHome = ucfirst($marketplace);
        $baseClass = '\App\\' . $moduleHome . $path;
        $altClass = '\App\\' . $moduleHome . 'home' . $path;
        if (class_exists($baseClass) && method_exists($baseClass, $method)) {
            return $baseClass;
        }
        if (class_exists($altClass) && method_exists($altClass, $method)) {
            return $altClass;
        }

        return null;
    }

    /**
     * removeEntryFromQueuedTask function
     * To remove a specific entry from the queued task collection for the given user and shop.
     * Used during cleanup of the contingency process to avoid redundant processing.
     * @param [string] $userId
     * @param [string] $shopId
     * @return void
     */
    private function removeEntryFromQueuedTask($userId, $shopId)
    {
        try {
            $this->queuedTaskContainer->deleteOne([
                'user_id' => $userId ?? $this->di->getUser()->id,
                'shop_id' => $shopId,
                'process_code' => self::PROCESS_CODE
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception removeEntryFromQueuedTask(), Error: ' . json_encode($e->getMessage()), 'info',  $this->logFile ?? 'exception.log');
        }
    }

    /**
     * updateActivityLog function
     * To insert a new log entry into the notification container for tracking activity status.
     * Used to log success or failure messages for product contingency operations.
     * @param [array] $data
     * @param [bool] $status
     * @param [string] $message
     * @param [array|null] $additionalData
     * @return void
     */
    private function updateActivityLog($data, $status, $message, $additionalData = null)
    {
        $notificationData = [
            'severity' => $status ? 'success' : 'critical',
            'message' => $message ?? 'Product add, update and metafield contingency process',
            'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
            'shop_id' => $data['shop_id'],
            'marketplace' => self::MARKETPLACE,
            'appTag' => $this->di->getAppCode()->getAppTag(),
            'process_code' => self::PROCESS_CODE,
            'created_at' => new UTCDateTime((new \DateTime('now', new \DateTimeZone('UTC')))->getTimestamp() * 1000),
            'tag' => ""
        ];
        if (!empty($additionalData)) {
            $notificationData['additional_data'] = $additionalData;
        }
        $this->notificationContainer->insertOne(
            $notificationData
        );
    }
}
