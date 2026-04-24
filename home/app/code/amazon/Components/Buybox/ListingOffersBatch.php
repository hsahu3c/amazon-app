<?php

namespace App\Amazon\Components\Buybox;

use App\Core\Models\BaseMongo;
use App\Core\Components\Base;
use App\Amazon\Components\Common\Helper;
use App\Connector\Components\Profile\SQSWorker;

class ListingOffersBatch extends Base
{
    protected $mongo;
    protected $helper;
    protected $sqsWorker;

    const QUEUE_NAME           = 'amazon_buybox_bulk_status_sync';
    const BATCH_PROCESS_CODE   = 'amazon_buybox_status_sync';
    const LISTING_OFFERS_TABLE = 'amazon_listing_offers';
    const BATCH_SIZE           = 20;
    const MAX_RETRY_ATTEMPTS   = 2;
    const RETRY_DELAY_SECONDS  = 30;
    const QUEUE_DELAY_SECONDS  = 31;
    
    // MongoDB projection fields for listings
    private static $LISTING_PROJECTION = [
        'seller-sku' => 1,
        'asin1' => 1,
        '_id' => 1,
        'item-name' => 1,
        'image-url' => 1,
        'listing-id' => 1,
        'item-condition' => 1,
        'fulfillment-channel' => 1,
        'price' => 1
    ];
    
    public function __construct()
    {

    }

    public function init()
    {
        $this->mongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $this->helper = $this->di->getObjectManager()->get(Helper::class);
        $this->sqsWorker = $this->di->getObjectManager()->get(SQSWorker::class);
    }

    /**
     * Initiate batch process for buybox status sync
     * 
     * @param string $userId
     * @param string $shopId
     * @param array $options Options array that may contain:
     *                      - 'skus' (array): Optional array of SKUs to sync. If provided, only these SKUs will be synced.
     *                      - 'app_tag' (string): Application tag
     *                      - 'process_initiation' (string): Process initiation type
     * @return array
     */
    public function initiateBatchProcess($userId, $shopId, $options = [])
    {
        try {
            $this->init();

            // Get shop details
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shop = $userDetails->getShop($shopId, $userId);
            
            if (!$shop) {
                return ['success' => false, 'message' => 'Shop not found'];
            }

            $this->di->getAppCode()->setAppTag($options['app_tag']??'amazon_sales_channel');

            // Get seller ID from shop
            $sellerId = $shop['warehouses'][0]['seller_id'] ?? null;
            if (!$sellerId) {
                return ['success' => false, 'message' => 'Seller ID not found for shop'];
            }

            $queuedTaskCollection = $this->mongo->getCollectionForTable('queued_tasks');
            $queuedTask = $queuedTaskCollection->findOne(['user_id' => $userId, 'shop_id' => $shopId, 'process_code' => self::BATCH_PROCESS_CODE]);
            
            if ($queuedTask) {
                return ['success' => false, 'message' => 'Process already running'];
            }

            $selectedSkus = $options['skus'] ?? null;
            $isSelectedSkusMode = !empty($selectedSkus) && is_array($selectedSkus);

            // Build query based on whether selected SKUs are provided
            $query = $this->buildListingQuery($userId, $shopId, $isSelectedSkusMode ? $selectedSkus : null);

            // Get total count of listings to process
            $amazonListingCollection = $this->mongo->getCollectionForTable(Helper::AMAZON_LISTING);
            $totalCount = $amazonListingCollection->count($query);

            if ($totalCount == 0) {
                $message = $isSelectedSkusMode 
                    ? 'No active listings found for the selected SKUs' 
                    : 'No active listings found for this shop';
                return ['success' => false, 'message' => $message];
            }

            // Calculate number of batches needed
            $batchSize = self::BATCH_SIZE;
            $totalBatches = ceil($totalCount / $batchSize);

            // Create queued task
            $processMessage = $isSelectedSkusMode 
                ? 'Amazon Buybox Status Batch Process initiated for selected SKUs' 
                : 'Amazon Buybox Status Batch Process initiated';
            
            $queuedTaskData = [
                'user_id' => $userId,
                'marketplace' => 'amazon',
                'app_tag' => $options['app_tag'] ?? 'amazon_sales_channel',
                'process_code' => self::BATCH_PROCESS_CODE,
                'shop_id' => $shopId,
                'additional_data' => [
                    'total_items' => $totalCount,
                    'total_batches' => $totalBatches,
                    'batch_size' => $batchSize,
                    'seller_id' => $sellerId,
                    'processed_batches' => 0,
                    'processed_items' => 0,
                    'selected_skus' => $isSelectedSkusMode ? $selectedSkus : null,
                    'is_selected_skus_mode' => $isSelectedSkusMode
                ],
                'process_initiation' => $options['process_initiation'] ?? 'manual',
                'message' => $processMessage,
                'progress' => 0.0
            ];

            $queuedTaskId = $this->sqsWorker->CreateWorker([
                'user_id' => $userId,
                'params' => [
                    'target' => [
                        'shopId' => $shopId,
                        'marketplace' => 'amazon'
                    ],
                    'user_id' => $userId
                ],
                'message' => $queuedTaskData['message'],
                'process_code' => $queuedTaskData['process_code'],
                'additional_data' => $queuedTaskData['additional_data'],
                'class_name' => 'App\Amazon\Components\Buybox\ListingOffersBatch',
                'method_name' => 'processBatch',
                'worker_name' => self::QUEUE_NAME
            ], true);

            return [
                'success' => true,
                'message' => 'Batch process initiated successfully',
                // 'queued_task_id' => $queuedTaskId,
                'total_items' => $totalCount,
                'total_batches' => $totalBatches,
                'is_selected_skus_mode' => $isSelectedSkusMode,
                'selected_skus_count' => $isSelectedSkusMode ? count($selectedSkus) : null
            ];

        } catch (\Exception $e) {
            $this->di->getLog()->logContent("Error initiating batch process: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', 'buybox' .DS. date('Y-m-d') .DS. 'batch-process-error.log');
            return [
                'success' => false,
                'message' => 'Failed to initiate batch process: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process a single batch of SKUs
     * 
     * @param array $data
     * @return array
     */
    public function processBatch($sqsData)
    {
        try {
            $this->init();

            $data = $sqsData['data'];
            $feedId = $data['feed_id'] ?? null;
            $params = $data['params'] ?? [];
            $userId = $params['user_id'] ?? null;
            $shopId = $params['target']['shopId'] ?? null;

            if (!$userId || !$shopId) {
                return ['success' => false, 'message' => 'Missing required parameters'];
            }

            // Get queued task details
            $queuedTaskCollection = $this->mongo->getCollectionForTable('queued_tasks');
            $queuedTask = $queuedTaskCollection->findOne(['_id' => new \MongoDB\BSON\ObjectId($feedId)],['typeMap' => ['root' => 'array', 'document' => 'array']]);
            
            if (!$queuedTask) {
                return ['success' => false, 'message' => 'Queued task not found'];
            }

            $additionalData = $queuedTask['additional_data'] ?? [];
            $processedBatches = $additionalData['processed_batches'] ?? 0;
            $totalBatches = $additionalData['total_batches'] ?? 1;
            $batchSize = $additionalData['batch_size'] ?? self::BATCH_SIZE;
            $sellerId = $additionalData['seller_id'] ?? null;
            $selectedSkus = $additionalData['selected_skus'] ?? null;
            $isSelectedSkusMode = $additionalData['is_selected_skus_mode'] ?? false;

            if ($processedBatches >= $totalBatches) {
                // All batches processed
                $this->updateQueuedTaskProgress($feedId, 100, 'Buybox status sync completed successfully', false);
                $this->sendNotification($userId, $shopId, $queuedTask['appTag'] ?? 'amazon_sales_channel', 
                    'Buybox status sync completed successfully', 'success');

                return ['success' => true, 'message' => 'Buybox status sync completed successfully'];
            }

            // Get next batch of SKUs
            $amazonListingCollection = $this->mongo->getCollectionForTable(Helper::AMAZON_LISTING);
            $skip = $processedBatches * $batchSize;
            
            // Build query based on whether selected SKUs are provided
            $query = $this->buildListingQuery($userId, $shopId, $isSelectedSkusMode && !empty($selectedSkus) ? $selectedSkus : null);
            
            $listings = $amazonListingCollection->find(
                $query,
                [
                    'limit' => $batchSize,
                    'skip' => $skip,
                    'projection' => self::$LISTING_PROJECTION,
                    'typeMap' => ['root' => 'array', 'document' => 'array']
                ]
            )->toArray();

            if (empty($listings)) {
                // No more listings to process
                $this->updateQueuedTaskProgress($feedId, 100, 'Buybox status sync completed successfully', false);
                $this->sendNotification($userId, $shopId, $queuedTask['appTag'] ?? 'amazon_sales_channel', 
                    'Buybox status sync completed successfully', 'success');

                return ['success' => true, 'message' => 'Buybox status sync completed successfully. No more listings to process.'];
            }

            // Prepare SKUs for API call
            $skus = [];
            $listingsData = [];
            foreach ($listings as $listing) {
                if (!empty($listing['seller-sku'])) {
                    $skus[] = [
                        'sku' => $listing['seller-sku'],
                        'item_condition' => $listing['item-condition']
                    ];
                    $listingsData[$listing['seller-sku']] = $listing;
                }
            }
            unset($listings);

            if (empty($skus)) {
                // No SKUs found, mark this batch as processed and continue
                $message = "Process Terminated. No SKUs found in batch:{$processedBatches} out of {$totalBatches} batches.";
                $this->updateQueuedTaskProgress($feedId, 100, $message, false);
                $this->sendNotification($userId, $shopId, $queuedTask['appTag'] ?? 'amazon_sales_channel', 
                    $message, 'critical');

                return ['success' => true, 'message' => 'No SKUs found in current batch'];
            }

            // Call Amazon API
            $apiResponse = $this->callListingOffersBatchAPI($userId, $shopId, $skus);
            
            if (!$apiResponse['success']) {
                // delete queued task
                $message = "Process failed after processing {$processedBatches} batches out of {$totalBatches} due to API error";
                $this->updateQueuedTaskProgress($feedId, 100, $message, false);
                $this->sendNotification($userId, $shopId, $queuedTask['appTag'] ?? 'amazon_sales_channel', 
                    $message, 'critical');

                return ['success' => false, 'message' => 'API call failed: ' . $apiResponse['message']];
            }

            // Process API response and calculate buybox status
            $this->processAPIResponse($userId, $shopId, $listingsData, $apiResponse['data'], $sellerId);

            // Update progress
            $newProcessedBatches = $processedBatches + 1;
            $progress = ($newProcessedBatches / $totalBatches) * 100;
            
            $additionalData['processed_batches'] = $newProcessedBatches;
            $additionalData['processed_items'] = $newProcessedBatches * $batchSize;

            $this->updateQueuedTaskProgress(
                $feedId, 
                $progress, 
                "Processed batch {$newProcessedBatches} of {$totalBatches}",
                false,
                $additionalData
            );

            // If there are more batches, queue the next one
            if ($newProcessedBatches < $totalBatches) {
                $this->queueNextBatch($data, $feedId);
            } else {
                $this->sendNotification($userId, $shopId, $queuedTask['appTag'] ?? 'amazon_sales_channel', 
                    'Buybox status sync completed successfully', 'success');
            }

            return ['success' => true, 'message' => "Batch {$newProcessedBatches} processed successfully"];

        } catch (\Exception $e) {
            $this->di->getLog()->logContent("Error processing batch message: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', 'buybox' .DS. date('Y-m-d') .DS. 'batch-process-error.log');

            if(isset($additionalData['processed_batches'], $additionalData['total_batches'])) {
                $errMsg = "Process failed after processing {$additionalData['processed_batches']} batches out of {$additionalData['total_batches']} due to an Exception";
            } else {
                $errMsg = "Process failed due to an Exception";
            }
            $appTag = $queuedTask['appTag'] ?? 'amazon_sales_channel';
            $this->terminateQueuedTask($userId, $shopId, $feedId, $errMsg, $appTag);
            
            return ['success' => false, 'message' => 'Batch processing failed: ' . $e->getMessage()];
        }
    }

    /**
     * Call Amazon Get Listing Offers Batch API (getListingOffersBatch)
     * 
     * @param string $userId
     * @param string $shopId
     * @param array $skus
     * @return array
     */
    private function callListingOffersBatchAPI($userId, $shopId, $skus, $attempt=1)
    {
        try {
            $this->init();

            // Get shop details for API credentials
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shop = $userDetails->getShop($shopId, $userId);
            
            if (!$shop) {
                return ['success' => false, 'message' => 'Shop not found'];
            }

            // Prepare API request data
            $requestData = [
                'shop_id' => $shop['remote_shop_id'],
                'skus' => $skus
            ];

            // Use the existing Helper to send request to Amazon
            $response = $this->helper->sendRequestToAmazon('listing-offers-batch', $requestData, 'POST');

            if (isset($response['success']) && $response['success']) {
                return ['success' => true, 'data' => $response];
            } else {
                if (isset($response['error'])) {
                    $lower_case_err = strtolower($response['error']);
                    if (strpos($lower_case_err, 'quotaexceeded') !== false || strpos($lower_case_err, 'too many requests') !== false) {
                        if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                            sleep(self::RETRY_DELAY_SECONDS);
                            return $this->callListingOffersBatchAPI($userId, $shopId, $skus, $attempt + 1);
                        }
                    }
                }
                return ['success' => false, 'message' => $response['error'] ?? 'API call failed'];
            }

        } catch (\Exception $e) {
            $this->di->getLog()->logContent("Error in API call: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', 'buybox' .DS. date('Y-m-d') .DS. 'batch-process-error.log');
            return ['success' => false, 'message' => 'API call exception: ' . $e->getMessage()];
        }
    }

    /**
     * Process API response and calculate buybox status
     * 
     * @param string $userId
     * @param string $shopId
     * @param array $listings
     * @param array $apiResponse
     * @param string $sellerId
     */
    private function processAPIResponse($userId, $shopId, $listings, $apiResponse, $sellerId)
    {
        try {
            $this->init();

            $pricingHealthCollection = $this->mongo->getCollectionForTable(self::LISTING_OFFERS_TABLE);
            $bulkUpdates = [];
            $time = date('c');
            
            foreach ($apiResponse['data']['responses'] as $skuResponse) {
                $sku = $skuResponse['request']['SellerSKU'] ?? $skuResponse['request']['sku'];
                $marketplaceId = $skuResponse['request']['MarketplaceId'] ?? $skuResponse['request']['marketplaceId'];
                $listing = $listings[$sku] ?? [];
                
                if ($skuResponse['status']['statusCode'] === 200) {
                    $isBuyboxWinner = $this->calculateBuyBoxStatus($sellerId, $skuResponse);
                    $updateData = [
                        'buybox'  => $isBuyboxWinner ? 'win' : 'loss',
                        // 'identifier'=> $skuResponse['body']['payload']['Identifier'] ?? null,
                        'summary' => $skuResponse['body']['payload']['Summary'] ?? null,
                        'offers'  => $skuResponse['body']['payload']['Offers'] ?? null,
                        'offer_status'  => $skuResponse['body']['payload']['status'] ?? null,
                        'updated_at' => $time
                    ];
                } else {
                    $errorBody = $skuResponse['body']['errors'][0] ?? [];
                    $updateData = [
                        'offer_status' => 'ERROR',
                        'error' => [
                            'status_code' => $skuResponse['status']['statusCode'],
                            'reason' => $skuResponse['status']['reasonPhrase'] ?? '',
                            'message' => $errorBody['message'] ?? '',
                            'code' => $errorBody['code'] ?? ''
                        ],
                        'buybox' => 'loss',
                        'updated_at' => $time
                    ];
                }

                $insertData = [
                    'user_id' => $userId,
                    'shop_id' => $shopId,
                    'seller_id' => $sellerId,
                    'marketplace_id' => $marketplaceId,
                    'asin' => $listing['asin1'] ?? null,
                    'image' => $listing['image-url'] ?? null,
                    'sku' => $sku,
                    'title' => $listing['item-name'] ?? null,
                    'listing_id' => $listing['listing-id'] ?? null,
                    'item_condition' => $listing['item-condition'] ?? null,
                    'price' => $listing['price'] ?? null,
                    'fulfillment_channel' => $listing['fulfillment-channel'] ?? null,
                    'created_at' => $time
                ];

                $bulkUpdates[] = [
                    'updateOne' => [
                        ['user_id' => $userId, 'shop_id' => $shopId, 'sku' => $sku],
                        [
                            '$set' => $updateData,
                            '$setOnInsert' => $insertData
                        ],
                        ['upsert' => true]
                    ]
                ];
            }

            if (!empty($bulkUpdates)) {
                $pricingHealthCollection->bulkWrite($bulkUpdates);
            }

        } catch (\Exception $e) {
            $this->di->getLog()->logContent("Error processing API response: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', 'buybox' .DS. date('Y-m-d') .DS. 'batch-process-error.log');
        }
    }

    /**
     * Update queued task progress
     * 
     * @param string $feedId
     * @param float $progress
     * @param string $message
     * @param bool $addToExisting
     * @param array $additionalData
     */
    private function updateQueuedTaskProgress($feedId, $progress, $message = '', $addToExisting = false, $additionalData = [])
    {
        try {
            $queuedTaskModel = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
            $queuedTaskModel->updateFeedProgress($feedId, $progress, $message, $addToExisting, $additionalData);
        } catch (\Exception $e) {
            $this->di->getLog()->logContent("Error updating queued task progress: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', 'buybox' .DS. date('Y-m-d') .DS. 'batch-process-error.log');
        }
    }

    /**
     * Queue next batch for processing
     * 
     * @param array $data
     * @param string $feedId
     */
    private function queueNextBatch($data, $feedId)
    {
        try {
            $this->init();

            // Add delay before processing next batch to avoid rate limiting
            $sqsData = [
                'type' => 'full_class',
                'class_name' => 'App\Amazon\Components\Buybox\ListingOffersBatch',
                'method' => 'processBatch',
                'queue_name' => self::QUEUE_NAME,
                'user_id' => $data['params']['user_id'],
                'data' => [
                    'feed_id' => $feedId,
                    'params' => $data['params']
                ],
                'DelaySeconds' => self::QUEUE_DELAY_SECONDS,
                'apply_limit' => self::QUEUE_DELAY_SECONDS
            ];

            $this->sqsWorker->pushToQueue($sqsData);
        } catch (\Exception $e) {
            $this->di->getLog()->logContent("Error queuing next batch: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', 'buybox' .DS. date('Y-m-d') .DS. 'batch-process-error.log');
        }
    }

    private function terminateQueuedTask($userId, $shopId, $feedId, $message, $appTag)
    {
        $this->updateQueuedTaskProgress($feedId, 100, $message, false);
        $this->sendNotification($userId, $shopId, $appTag, $message, 'critical');
    }

    /**
     * Build MongoDB query for listing collection
     * 
     * @param string $userId
     * @param string $shopId
     * @param array|null $selectedSkus
     * @return array
     */
    private function buildListingQuery($userId, $shopId, $selectedSkus = null)
    {
        $query = [
            'user_id' => $userId,
            'shop_id' => $shopId,
            'status' => ['$ne' => 'Deleted']
        ];

        if (!empty($selectedSkus) && is_array($selectedSkus)) {
            $query['seller-sku'] = ['$in' => $selectedSkus];
        }

        return $query;
    }

    /**
     * Send notification for batch process
     * 
     * @param string $userId
     * @param string $shopId
     * @param string $appTag
     * @param string $message
     * @param string $severity
     */
    private function sendNotification($userId, $shopId, $appTag, $message, $severity = 'info')
    {
        try {
            $notificationData = [
                'user_id'       => $userId,
                'shop_id'       => $shopId,
                'marketplace'   => 'amazon',
                'message'       => $message,
                'appTag'        => $appTag,
                'severity'      => $severity,
                'process_code'  => self::BATCH_PROCESS_CODE
            ];
            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                ->addNotification($shopId, $notificationData);
        } catch (\Exception $e) {
            // Log but don't fail if notification fails
            $this->di->getLog()->logContent("Error sending notification: " . $e->getMessage(), 'info', 'buybox' .DS. date('Y-m-d') .DS. 'notification-error.log');
        }
    }

    public function calculateBuyBoxStatus($sellerId, $listingOffersResponse)
    {
        if (!isset($listingOffersResponse['body']['payload']['Offers'])) {
            return false;
        }

        foreach ($listingOffersResponse['body']['payload']['Offers'] as $offer) {
            if (($offer['IsBuyBoxWinner'] ?? false) === true && ($offer['SellerId'] ?? '') === $sellerId) {
                return true;
            }
        }

        return false;
    }
}