<?php

namespace App\Amazon\Components\Buybox;

use App\Core\Models\BaseMongo;
use App\Core\Components\Base;
use App\Amazon\Components\Common\Helper;
use App\Connector\Components\Profile\SQSWorker;
use App\Amazon\Components\Buybox\ListingOffersBatch;

class FeaturedOfferExpectedPriceBatch extends Base
{
    protected $mongo;
    protected $helper;
    protected $sqsWorker;
    protected $buybox;

    // const QUEUE_NAME         = 'amazon_buybox_bulk_status_sync';
    // const BATCH_PROCESS_CODE = 'amazon_buybox_status_sync';
    
    public function __construct()
    {

    }

    public function init()
    {
        $this->mongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $this->helper = $this->di->getObjectManager()->get(Helper::class);
        $this->sqsWorker = $this->di->getObjectManager()->get(SQSWorker::class);
        $this->buybox = $this->di->getObjectManager()->get(Buybox::class);
    }

    /**
     * Initiate batch process for featured offer expected price
     * 
     * @param string $userId
     * @param string $shopId
     * @param array $options
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
            $queuedTask = $queuedTaskCollection->findOne(['user_id' => $userId, 'shop_id' => $shopId, 'process_code' => ListingOffersBatch::BATCH_PROCESS_CODE]);
            
            if ($queuedTask) {
                return ['success' => false, 'message' => 'Process already running'];
            }

            // Get total count of amazon_listing items for this shop
            $amazonListingCollection = $this->mongo->getCollectionForTable(Helper::AMAZON_LISTING);
            $totalCount = $amazonListingCollection->count([
                'user_id' => $userId,
                'shop_id' => $shopId,
                'status' => ['$ne' => 'Deleted']
            ]);

            if ($totalCount == 0) {
                return ['success' => false, 'message' => 'No active listings found for this shop'];
            }

            // Calculate number of batches needed (40 SKUs per batch)
            $batchSize = 40;
            $totalBatches = ceil($totalCount / $batchSize);

            // Create queued task
            $queuedTaskData = [
                'user_id' => $userId,
                'marketplace' => 'amazon',
                'app_tag' => 'amazon_sales_channel',
                'process_code' => ListingOffersBatch::BATCH_PROCESS_CODE,
                'shop_id' => $shopId,
                'additional_data' => [
                    'total_items' => $totalCount,
                    'total_batches' => $totalBatches,
                    'batch_size' => $batchSize,
                    'seller_id' => $sellerId,
                    'processed_batches' => 0,
                    'processed_items' => 0
                ],
                'process_initiation' => $options['process_initiation'] ?? 'manual',
                'message' => 'Amazon Buybox Status Batch Process initiated',
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
                'class_name' => 'App\Amazon\Components\Buybox\FeaturedOfferExpectedPriceBatch',
                'method_name' => 'processBatch',
                'worker_name' => ListingOffersBatch::QUEUE_NAME
            ], true);

            return [
                'success' => true,
                'message' => 'Batch process initiated successfully',
                // 'queued_task_id' => $queuedTaskId,
                'total_items' => $totalCount,
                'total_batches' => $totalBatches
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
            $queuedTask = $queuedTaskCollection->findOne(['_id' => new \MongoDB\BSON\ObjectId($feedId)]);
            
            if (!$queuedTask) {
                return ['success' => false, 'message' => 'Queued task not found'];
            }

            $additionalData = $queuedTask['additional_data'] ?? [];
            $processedBatches = $additionalData['processed_batches'] ?? 0;
            $totalBatches = $additionalData['total_batches'] ?? 1;
            $batchSize = $additionalData['batch_size'] ?? 40;
            $sellerId = $additionalData['seller_id'] ?? null;

            if ($processedBatches >= $totalBatches) {
                // All batches processed
                $this->updateQueuedTaskProgress($feedId, 100, 'Buybox status sync completed successfully', false);

                $notificationData = [
                    'user_id'       => $userId,
                    'shop_id'       => $shopId,
                    'marketplace'   => 'amazon',
                    'message'       => "Buybox status sync completed successfully",
                    'appTag'        => $queuedTask['appTag'],
                    'severity'      => 'success',
                    'process_code'  => ListingOffersBatch::BATCH_PROCESS_CODE
                ];
                $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                    ->addNotification($shopId, $notificationData);

                return ['success' => true, 'message' => 'Buybox status sync completed successfully'];
            }

            // Get next batch of SKUs
            $amazonListingCollection = $this->mongo->getCollectionForTable(Helper::AMAZON_LISTING);
            $skip = $processedBatches * $batchSize;
            
            $listings = $amazonListingCollection->find(
                [
                    'user_id' => $userId,
                    'shop_id' => $shopId,
                    'status' => ['$ne' => 'Deleted']
                ],
                [
                    'limit' => $batchSize,
                    'skip' => $skip,
                    'projection' => ['seller-sku' => 1, 'asin1' => 1, '_id' => 1, 'asin1' => 1, 'item-name' => 1, 'image-url' => 1, 'listing-id' => 1, 'item-condition' => 1, 'fulfillment-channel' => 1, 'price' => 1],
                    'typeMap' => ['root' => 'array', 'document' => 'array']
                ]
            )->toArray();

            if (empty($listings)) {
                // No more listings to process
                $this->updateQueuedTaskProgress($feedId, 100, 'Buybox status sync completed successfully', false);

                $notificationData = [
                    'user_id'       => $userId,
                    'shop_id'       => $shopId,
                    'marketplace'   => 'amazon',
                    'message'       => "Buybox status sync completed successfully",
                    'appTag'        => $queuedTask['appTag'],
                    'severity'      => 'success',
                    'process_code'  => ListingOffersBatch::BATCH_PROCESS_CODE
                ];
                $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                    ->addNotification($shopId, $notificationData);

                return ['success' => true, 'message' => 'Buybox status sync completed successfully. No more listings to process.'];
            }

            // Prepare SKUs for API call
            $skus = [];
            $listingsData = [];
            foreach ($listings as $listing) {
                if (!empty($listing['seller-sku'])) {
                    $skus[] = $listing['seller-sku'];
                    $listingsData[$listing['seller-sku']] = $listing;
                }
            }
            unset($listings);
            
            if (empty($skus)) {
                // No SKUs found, mark this batch as processed and continue
                $this->updateQueuedTaskProgress($feedId, 100, "Process Terminated. No SKUs found in batch:{$processedBatches} out of {$totalBatches} batches.", false);

                $notificationData = [
                    'user_id'       => $userId,
                    'shop_id'       => $shopId,
                    'marketplace'   => 'amazon',
                    'message'       => "Process Terminated. No SKUs found in batch:{$processedBatches} out of {$totalBatches} batches.",
                    'appTag'        => $queuedTask['appTag'],
                    'severity'      => 'critical',
                    'process_code'  => ListingOffersBatch::BATCH_PROCESS_CODE
                ];
                $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                    ->addNotification($shopId, $notificationData);

                return ['success' => true, 'message' => 'No SKUs found in current batch'];
            }

            // Call Amazon API
            $apiResponse = $this->callFeaturedOfferExpectedPriceBatchAPI($userId, $shopId, $skus);
            
            if (!$apiResponse['success']) {
                // delete queued task
                $this->updateQueuedTaskProgress($feedId, 100, "Process failed after processing {$processedBatches} batches out of {$totalBatches} due to API error", false);
                
                $notificationData = [
                    'user_id'       => $userId,
                    'shop_id'       => $shopId,
                    'marketplace'   => 'amazon',
                    'message'       => "Process failed after processing {$processedBatches} batches out of {$totalBatches} due to API error",
                    'appTag'        => $queuedTask['appTag'],
                    'severity'      => 'critical',
                    'process_code'  => ListingOffersBatch::BATCH_PROCESS_CODE
                ];
                $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                    ->addNotification($shopId, $notificationData);

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
            }
            else {
                $notificationData = [
                    'user_id'       => $userId,
                    'shop_id'       => $shopId,
                    'marketplace'   => 'amazon',
                    'message'       => "Buybox status sync completed successfully",
                    'appTag'        => $queuedTask['appTag'],
                    'severity'      => 'success',
                    'process_code'  => ListingOffersBatch::BATCH_PROCESS_CODE
                ];
                $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                    ->addNotification($shopId, $notificationData);
            }
            // die("Batch {$newProcessedBatches} processed successfully");
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
     * Call Amazon Featured Offer Expected Price Batch API
     * 
     * @param string $userId
     * @param string $shopId
     * @param array $skus
     * @return array
     */
    private function callFeaturedOfferExpectedPriceBatchAPI($userId, $shopId, $skus, $attempt=1)
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
            $response = $this->helper->sendRequestToAmazon('featured-offer-expected-price-batch', $requestData, 'POST');

            if (isset($response['success']) && $response['success']) {
                return ['success' => true, 'data' => $response];
            } else {
                // print_r($response);
                $lower_case_err = strtolower($response['error']);
                if(isset($response['error']) && (strpos($lower_case_err, 'quotaexceeded')!==false || strpos($lower_case_err, 'too many requests')!==false)) {
                    $attempt++;
                    if($attempt <= 2) {
                        // print_r("attempt {$attempt} after API throttle");
                        sleep(30);
                        return $this->callFeaturedOfferExpectedPriceBatchAPI($userId, $shopId, $skus, $attempt);
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
            // print_r($apiResponse);die;
            $this->init();

            $pricingHealthCollection = $this->mongo->getCollectionForTable('amazon_pricing_health');
            
            $bulkUpdates = [];
            foreach ($apiResponse['data']['responses'] as $skuResponse) {
                $sku = $skuResponse['request']['sku'];
                $marketplaceId = $skuResponse['request']['marketplaceId'];
                $time = date('c');
                if($skuResponse['status']['statusCode'] === 200) {
                    // Calculate buybox status using existing method
                    $isBuyboxWinner = $this->buybox->calculateBuyBoxStatus($sellerId, $skuResponse);
                    $FeaturedOfferDetails = [
                        'featuredOfferExpectedPrice' => $skuResponse['body']['featuredOfferExpectedPriceResults'][0]['featuredOfferExpectedPrice'] ?? [],
                        'currentFeaturedOffer' => $skuResponse['body']['featuredOfferExpectedPriceResults'][0]['currentFeaturedOffer'] ?? [],
                        'competingFeaturedOffer' => $skuResponse['body']['featuredOfferExpectedPriceResults'][0]['competingFeaturedOffer'] ?? []
                    ];

                    // Prepare data for amazon_pricing_health collection
                    $updateData = [
                        'offer_status' => $skuResponse['body']['featuredOfferExpectedPriceResults'][0]['resultStatus'] ?? '',
                        'buybox' => $isBuyboxWinner ? 'win' : 'loss',
                        // 'pricing_health' => $skuResponse,
                        'featured_offer' => $FeaturedOfferDetails,
                        'updated_at' => $time
                    ];
                }
                else {
                    // Prepare data for amazon_pricing_health collection
                    $updateData = [
                        'offer_status' => 'ERROR',
                        'error' => [
                            'status_code' => $skuResponse['status']['statusCode'],
                            'reason' => $skuResponse['status']['reasonPhrase'],
                            'message' => $skuResponse['body']['errors'][0]['message'],
                            'code' => $skuResponse['body']['errors'][0]['code']
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
                    'asin' => $listings[$sku]['asin1'] ?? null,
                    'image' => $listings[$sku]['image-url'] ?? null,
                    'sku' => $sku,
                    'title' => $listings[$sku]['item-name'] ?? null,
                    'listing_id' => $listings[$sku]['listing-id'] ?? null,
                    'item_condition'=> $listings[$sku]['item-condition'] ?? null,
                    'price' => $listings[$sku]['price'] ?? null,
                    'fulfillment_channel' => $listings[$sku]['fulfillment-channel'] ?? null,
                    'created_at' => $time
                ];

                // Update or insert pricing health data
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
            // echo '<pre>';
            // print_r($apiResponse);
            // print_r($listings);
            // print_r($bulkUpdates);

            if (!empty($bulkUpdates)) {
                $bulkWriteResp = $pricingHealthCollection->bulkWrite($bulkUpdates);
                // print_r($bulkWriteResp);
            }

        } catch (\Exception $e) {
            // Log error but don't fail the entire batch
            // error_log("Error processing API response: " . $e->getMessage());
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
            // error_log("Error updating queued task progress: " . $e->getMessage());
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
                'class_name' => 'App\Amazon\Components\Buybox\FeaturedOfferExpectedPriceBatch',
                'method' => 'processBatch',
                'queue_name' => ListingOffersBatch::QUEUE_NAME,
                'user_id' => $data['params']['user_id'],
                'data' => [
                    'feed_id' => $feedId,
                    'params' => $data['params']
                ],
                'DelaySeconds' => 31, // 31 second delay between batches
                'apply_limit' => 31
            ];

            $this->sqsWorker->pushToQueue($sqsData);
        } catch (\Exception $e) {
            // error_log("Error queuing next batch: " . $e->getMessage());
            $this->di->getLog()->logContent("Error queuing next batch: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', 'buybox' .DS. date('Y-m-d') .DS. 'batch-process-error.log');
        }
    }

    private function terminateQueuedTask($userId, $shopId, $feedId, $message, $appTag)
    {
        // delete queued task
        $this->updateQueuedTaskProgress($feedId, 100, $message, false);
        
        $notificationData = [
            'user_id'       => $userId,
            'shop_id'       => $shopId,
            'marketplace'   => 'amazon',
            'message'       => $message,
            'appTag'        => $appTag,
            'severity'      => 'critical',
            'process_code'  => ListingOffersBatch::BATCH_PROCESS_CODE
        ];
        $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
            ->addNotification($shopId, $notificationData);
    }
}

