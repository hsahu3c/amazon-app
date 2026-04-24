<?php


namespace App\Amazon\Components\Report;

use Exception;
use MongoDB\BSON\ObjectID;
use App\Connector\Components\Dynamo;
use Aws\DynamoDb\Marshaler;
use App\Core\Components\Base;

class Helper extends Base
{
    public const SYNC_STATUS_CONFIG_KEY = 'sync_product_with';

    public const FULFILLED_BY_MERCHANT = 'FBM';

    public const FULFILLED_BY_AMAZON = 'FBA';

    public const MATCH_WITH_SKU_PRIORITY = 'sku_barcode';

    public const MATCH_WITH_BARCODE_PRIORITY = 'barcode_sku';

    public const MATCH_WITH_BARCODE = 'barcode';

    public const MATCH_WITH_SKU = 'sku';

    public const MATCH_WITH_SKU_AND_BARCODE  = 'sku,barcode';

    public const AMAZON_REPORT_CONTAINER_USERS = 'amazon_report_container_users';

    // public function requestReport($data)
    // {
    //     $initiateImage = false;
    //     if(isset($data['onboarding']) && $data['onboarding']) {
    //         $initiateImage = true;
    //         $data['importProduct']= true;
    //     }

    //     $res = $this->di->getObjectManager()->get(Report::class)->init($data)
    //         ->execute($initiateImage);
    //     return $res;
    // }
    public function requestReport($data)
    {
        $res = $this->di->getObjectManager()->get(SyncStatus::class)->startSyncStatus($data);
        return $res;
    }

    /** initiate product matching after product-import */
    // public function initiateSyncAfterImport($data, $directInitiation = false)
    // {
    //     $returnResult = false;
    //     $reportHelper = $this->di->getObjectManager()->get(Report::class)->init($data);
    //     if ($directInitiation) {
    //         $handlerData = $data;
    //     } elseif (isset($data['data']['queued_task_id'])) {
    //         /** case when re-direct from saveAmazonListingProduct after amazon-product-import */
    //         if (!empty($data['lookup']) && $data['lookup']) {
    //             /** check if shopify product import is in progress - in on-boarding case */
    //             $importingTask = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->checkQueuedTaskwithProcessTag('saleschannel_product_import', (string)$data['data']['source_shop_id']);
    //             if($importingTask) {
    //                 $additionalData['amazon_import'] = true;
    //                 $reportHelper->updateProcess($data['data']['queued_task_id'], 20, 'Success! Product saved for Product Linking & Status Synchronization will start after product importing.', 'success', $additionalData);
    //                 return;
    //             }
    //         }

    //         $handlerData = $data;
    //         if (isset($handlerData['initiateImage']) && $handlerData['initiateImage']) {
    //             $this->initiateImageImport($handlerData);
    //         }
    //     } elseif (isset($data['source_marketplace'], $data['target_marketplace'], $data['user_id'])) {
    //         /** case when re-direct after shopify-product-import on-boarding*/
    //         $userId = (string)$data['user_id'];
    //         $targetShopId = (string)$data['target_marketplace']['target_shop_id'];
    //         $reportHelper = $this->di->getObjectManager()->get(Report::class)->init($data);
    //         $searchResponse = $reportHelper->getSyncStatusQueuedTask($userId, $targetShopId);
    //         if (isset($searchResponse['_id'])) {
    //             /** check if amazon-product import is already done */
    //             if (isset($searchResponse['additional_data']['amazon_import']) && $searchResponse['additional_data']['amazon_import']) {
    //                 $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
    //                 $shop = $userDetails->getShop($targetShopId, $userId);
    //                 $data = [
    //                     'queued_task_id' => (string)$searchResponse['_id'],
    //                     'remote_shop_id' => $shop['remote_shop_id']
    //                 ];
    //                 $handlerData = $reportHelper->prepareSqsData($reportHelper::MATCH_STATUS_OPERATION, $data);
    //                 if (!empty($searchResponse['additional_data']['initiateImage']) && $searchResponse['additional_data']['initiateImage']) {
    //                     // initiate image import
    //                     $this->initiateImageImport($handlerData);
    //                 }

    //                 /** lookup true for on-boarding */
    //                 $handlerData['lookup'] = true;
    //             } else {
    //                 /** amazon-product import is not completed, matching will initiate after that */
    //                 return;
    //             }
    //         } else {
    //             $date = date('Y-m-d');
    //             $this->di->getLog()->logContent('product reimport case  data received=' . json_encode($data), 'info',"amazon/reimporting/initiateSyncAfterImport_{$date}.log");
    //             return;
    //         }
    //     }

    //     /** initiate match status */
    //     $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
    //     /** get total count of amazon-products (matching process) for dynamic progress track in queued task */
    //     $amazonCollection = $baseMongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);
    //     $query = ['user_id' => (string)$handlerData['user_id'], 'shop_id' => (string)$handlerData['data']['home_shop_id'], 'manual_mapped' => null];
    //     $productsCount = $amazonCollection->count($query);
    //         /** if amazon-listing has product(s) for matching */
    //     if ($productsCount) {
    //         /** get sync-preference settings */
    //         $syncSettings = $this->getProductSettingForSync($handlerData['user_id'], $handlerData['data']['source_shop_id'], $handlerData['data']['home_shop_id'], $handlerData['data']['source_marketplace'], $handlerData['data']['target_marketplace']) ?? [];
    //         if (empty($syncSettings)) {
    //             $reportHelper->updateProcess($data['data']['queued_task_id'], 100, $reportHelper::ERROR_SYNC_STATUS);
    //         } else {
    //             $handlerData['data']['sync_preference'] = $syncSettings;
    //             $handlerData['data']['individual_weight'] =  $productsCount;
    //             $handlerData['data']['page'] = 0;
    //             $handlerData['data']['limit'] = 500;
    //             $handlerData['data']['operation'] = $reportHelper::MATCH_STATUS_OPERATION;
    //             $this->di->getMessageManager()->pushMessage($handlerData);
    //             if ($directInitiation) {
    //                 $message = 'Success! Status Synchronization is now in progress.';
    //             } else {
    //                 $message = 'Success! Product saved for Product Linking & Status Synchronization is now in progress.';
    //             }

    //             $reportHelper->updateProcess($handlerData['data']['queued_task_id'], 20, $message);
    //             $returnResult = true;
    //         }
    //     } else {
    //         $reportHelper->updateProcess($handlerData['data']['queued_task_id'], 100, 'Synchronization stopped: To proceed with status sync successfully, at least one product is required.', 'warning');
    //     }

    //     return $returnResult;
    // }

    /**
     * initiate Inventory Sync After Linking
     *
     * @param array $sourceProductIds
     * @param array $params
     * $params = ['source' => ['shopId','marketplace'], 'target' => ['shopId', 'marketplace'], 'user_id']
     * @return array
     */
    public function inventorySyncAfterLinking($sourceProductIds, $params)
    {
        try {
            $dataToSent = [
                'source' => $params['source'],
                'target' => $params['target'],
                'operationType' => 'inventory_sync',
                'source_product_ids' => $sourceProductIds,
                'user_id' => $params['user_id'] ?? $this->di->getUser()->id,
                'useRefinProduct' => true,
                'usePrdOpt' => true
            ];
            $productSync = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
            return  $productSync->startSync($dataToSent);
        } catch(Exception $e) {
            print_r($e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    /** setting for auto sync on/off */
    public function setAutomateSync($data)
    {
        try {
            if (
                isset($data['data']['product_ids'], $data['data']['operation'])
                && is_array($data['data']['product_ids']) && is_bool($data['data']['operation'])
            ) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $amazonCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);

                // Initialize update query array
                $updateQuery = [];
                $messages = [];

                // Handle Auto Sync operation
                if ($data['data']['operation']) {
                    $updateQuery['$unset']['automate_sync'] = 1;
                    $messages[] = 'Product Sync Enabled: Changes to this product will be automatically synchronized.';
                } else {
                    $updateQuery['$set']['automate_sync'] = false;
                    $messages[] = 'Product Sync Disabled: Changes to this product will not be automatically synchronized.';
                }

                // Handle Auto Status Update operation if provided
                if (isset($data['data']['status_operation']) && is_bool($data['data']['status_operation'])) {
                    if ($data['data']['status_operation']) {
                        $updateQuery['$unset']['automate_status_update'] = 1;
                        $messages[] = 'Auto Status Update Enabled: Status updates will be automatically synchronized.';
                    } else {
                        $updateQuery['$set']['automate_status_update'] = false;
                        $messages[] = 'Auto Status Update Disabled: Status updates will not be automatically synchronized.';
                    }

                }

                // Ensure we have proper structure for MongoDB update operations
                if (!isset($updateQuery['$set'])) {
                    $updateQuery['$set'] = [];
                }
                if (!isset($updateQuery['$unset'])) {
                    $updateQuery['$unset'] = [];
                }

                // Clean up empty operations
                if (empty($updateQuery['$set'])) {
                    unset($updateQuery['$set']);
                }
                if (empty($updateQuery['$unset'])) {
                    unset($updateQuery['$unset']);
                }

                $message = implode(' ', $messages);

                if ($data['data']['product_ids'] == 'all') {
                    /** sync-off for all not listed products */
                    $userId = $this->di->getUser()->id;
                    $targetShopId = (string) ($data['target']['shopId']  ??  $this->di->getRequester()->getTargetId());
                    if ($userId && $targetShopId && $userId != '' && $targetShopId != '') {
                        $res = $amazonCollection->updateMany(['user_id' => $userId, 'shop_id' => $targetShopId, 'matched' => null], $updateQuery);
                    } else {
                        return ['success' => false, 'message' => "Required Parameters Missing: 'target_shop_id' parameter is required"];
                    }
                } elseif (!empty($data['data']['product_ids'])) {
                    if ($data['data']['product_ids'][0] instanceof ObjectID) {
                        $objectIds = $data['data']['product_ids'];
                    } else {
                        $objectIds = array_map(fn($id): \MongoDB\BSON\ObjectId => new \MongoDB\BSON\ObjectId($id), $data['data']['product_ids']);
                    }

                    /** update key in amazon-listing */
                    if (count($objectIds) == 1) {
                        $res = $amazonCollection->updateOne(['_id' => $objectIds[0]], $updateQuery);
                    } else {
                        $res = $amazonCollection->updateMany(['_id' => ['$in' => $objectIds]], $updateQuery);
                    }
                } else {
                    return ['success' => false, 'message' => "Required Parameters Missing: 'product_ids' parameter is required"];
                }

                return ['success' => true, 'message' => $message];
            }
            return ['success' => false, 'message' => "Required Parameters Missing: 'product_ids' and 'operation' parameters are required"];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Something went wrong while processing request', 'trace' => $e->getMessage()];
        }
    }

    /** mark deleted product during sync status */
    public function updateDeleteStatus($data): void
    {
        try {
            if (!isset($data['data']['queued_task_id'])) {
                return;
            }
            $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $queueTable = $baseMongo->getCollection('queued_tasks');
            $searchResponse = $queueTable->findOne(
                ['_id' => new \MongoDB\BSON\ObjectId($data['data']['queued_task_id'])],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
                );
            if (!empty($searchResponse) && isset($searchResponse['created_at'], $searchResponse['process_code']) && ($searchResponse['process_code'] == 'amazon_product_report')) {
                $taskStartedDate = $searchResponse['created_at'];
                $userId = $data['user_id'] ?? '';
                $targetShopId = $data['data']['home_shop_id'] ?? '';
                $sourceShopId = $data['data']['source_shop_id'] ?? '';
                $sourceMarketplace = $data['data']['source_marketplace'] ?? "";
                $targetMarketplace = $data['data']['target_marketplace'] ?? 'amazon';
                $amazonCollection = $baseMongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);
                /** remove Deleted marked product of 7 days */
                $sevenDaysListing = date('c', strtotime('-7 days'));
                $deletedListing = $amazonCollection->find(
                    [
                        'user_id' => $userId,
                        'shop_id' => $targetShopId,
                        'status' => 'Deleted',
                        'updated_at' => ['$lt' => $sevenDaysListing],
                        'matched' => true
                    ],
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'projection' => ['source_container_id' => 1, 'source_product_id' => 1]
                    ])->toArray();
                if(!empty($deletedListing) && is_array($deletedListing)) {
                    /** remove matching before deleting */
                    $toRemoveData = [];
                    foreach($deletedListing as $listing) {
                        if(isset($listing['source_container_id'], $listing['source_product_id'])) {
                            $toRemoveData[] = [
                                'user_id' => $userId,
                                'target_marketplace' => 'amazon',
                                'shop_id' => (string)$targetShopId,
                                'container_id' => $listing['source_container_id'],
                                'source_product_id' => $listing['source_product_id'],
                                'source_shop_id' => $sourceShopId,
                                'unset' => [
                                    'asin' => 1,
                                    'sku' => 1,
                                    'matchedwith' => 1,
                                    'status' => 1,
                                    'target_listing_id' => 1,
                                    'fulfillment_type' => 1
                                ]
                            ];
                        }
                    }

                    if(!empty($toRemoveData)) {
                        $additionalData['source'] = [
                            'marketplace' => $sourceMarketplace,
                            'shopId' => (string)$sourceShopId
                        ];
                        $additionalData['target'] = [
                            'marketplace' => $targetMarketplace,
                            'shopId' => (string)$targetShopId
                        ];
                        $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit')->saveProduct($toRemoveData , $userId, $additionalData);
                    }
                }

                $amazonCollection->deleteMany([
                        'user_id' => $userId,
                        'shop_id' => $targetShopId,
                        'status' => 'Deleted',
                        'updated_at' => ['$lt' => $sevenDaysListing]
                ]);
                /** mark Deleted status */
                $amazonCollection->updateMany(
                    ['user_id' => $userId, 'shop_id' => $targetShopId, 'updated_at' => ['$lt' => $taskStartedDate]],
                    ['$set' => ['status' => 'Deleted']]
                );
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                print_r('exception during updateDeleteStatus: '.json_encode($e->getMessage()), true),
                'info',
                'exception.log'
            );
        }
    }

    /** handler for removing deleted status from product if found on amazon */
    public function removeDeleteStatus($data)
    {
        try {
            if (isset($data['target']['shopId'], $data['deleteData']['sku'], $data['deleteData']['user_id'])) {
                $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $amazonCollection = $baseMongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);
                $amazonCollection->updateOne(
                    ['user_id' => $data['deleteData']['user_id'], 'shop_id' => $data['target']['shopId'], 'seller-sku' => $data['deleteData']['sku']],
                    ['$set' => ['status' => 'Active']]
                );
                return ['success' => true, 'message' => 'Synced Successfully'];
            }
            return ['success' => false, 'message' => 'Required param(s) missing: target_shop_id, sku, user_id'];
        } catch (Exception $e) {
            return ['success' => true, 'message' => $e->getMessage()];
        }
    }

    /** get sync-status config setting by FBA/FBM and sku,barcode with priority */
    public function getProductSettingForSync($userId, $sourceShopId, $targetShopId, $sourceMarketplace, $targetMarketplace)
    {
        $configModel = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
        $appTag = $this->di->getAppCode()->getAppTag();
        $configModel->setUserId($userId);
        $configModel->setSourceShopId($sourceShopId);
        $configModel->setTargetShopId($targetShopId);
        $configModel->sourceSet($sourceMarketplace);
        $configModel->setTarget($targetMarketplace);
        $configModel->setAppTag($appTag);
        $configModel->setGroupCode('product');

        $configData = $configModel->getConfig(self::SYNC_STATUS_CONFIG_KEY);
        $configData = $configData[0]['value'] ?? [];

        $settings = [];
        if (isset($configData[self::FULFILLED_BY_AMAZON]['value'], $configData[self::FULFILLED_BY_MERCHANT]['value'])) {
            if ($configData[self::FULFILLED_BY_AMAZON]['value']) {
                $settings[self::FULFILLED_BY_AMAZON] = ($configData[self::FULFILLED_BY_AMAZON][self::MATCH_WITH_SKU] &&
                    $configData[self::FULFILLED_BY_AMAZON][self::MATCH_WITH_BARCODE]
                )
                    ? ($configData[self::FULFILLED_BY_AMAZON]['priority'][0] == self::MATCH_WITH_SKU
                        ? self::MATCH_WITH_SKU_PRIORITY
                        : self::MATCH_WITH_BARCODE_PRIORITY
                    )
                    : ($configData[self::FULFILLED_BY_AMAZON][self::MATCH_WITH_BARCODE]
                        ? self::MATCH_WITH_BARCODE
                        : self::MATCH_WITH_SKU
                    );
            }

            if ($configData[self::FULFILLED_BY_MERCHANT]['value']) {
                $settings[self::FULFILLED_BY_MERCHANT] = ($configData[self::FULFILLED_BY_MERCHANT][self::MATCH_WITH_SKU] &&
                    $configData[self::FULFILLED_BY_MERCHANT][self::MATCH_WITH_BARCODE]
                )
                    ? ($configData[self::FULFILLED_BY_MERCHANT]['priority'][0] == self::MATCH_WITH_SKU
                        ? self::MATCH_WITH_SKU_PRIORITY
                        : self::MATCH_WITH_BARCODE_PRIORITY
                    )
                    : ($configData[self::FULFILLED_BY_MERCHANT][self::MATCH_WITH_BARCODE]
                        ? self::MATCH_WITH_BARCODE
                        : self::MATCH_WITH_SKU
                    );
            }
        } elseif (isset($configData['fba'], $configData['fbm'])) {
            $syncStatusConfig = $configModel->getConfig('sync_status_with');
            $syncStatusConfig = $syncStatusConfig[0]['value'] ?? [];
            if (isset($syncStatusConfig['self::MATCH_WITH_BARCODE]'], $syncStatusConfig[self::MATCH_WITH_SKU])) {
                $choice = ($syncStatusConfig[self::MATCH_WITH_BARCODE] && $syncStatusConfig[self::MATCH_WITH_SKU]) ? self::MATCH_WITH_SKU_PRIORITY :
                ($syncStatusConfig[self::MATCH_WITH_BARCODE] ? self::MATCH_WITH_BARCODE : self::MATCH_WITH_SKU);
            } else {
                $choice = self::MATCH_WITH_SKU;
            }

            if($configData['fba']) {
                $settings[self::FULFILLED_BY_AMAZON] = $choice;
            }

            if($configData['fbm']) {
                $settings[self::FULFILLED_BY_MERCHANT] = $choice;
            }
        }

        return $settings;
    }

    /** initiate process of image-import from amazon for amazon-listing */
    // public function initiateImageImport($sqsData): void
    // {
    //     $date = date('d-m-Y');
    //     try {
    //         $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
    //         $amazonCollection = $baseMongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);
    //         $query = ['user_id' => (string)$sqsData['user_id'], 'shop_id' => (string)$sqsData['data']['home_shop_id'], 'image-url' => null];
    //         $eligibleProducts = $amazonCollection->count($query);
    //         if ($eligibleProducts > 0) {
    //             $queueName = $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->imageImportQueueName();
    //             $appTag = $this->di->getAppCode()->getAppTag();
    //             $queueData = [
    //                 'user_id' => $sqsData['user_id'],
    //                 'message' => "Synchronization of Amazon Listing's images with Amazon Started",
    //                 'process_code' => $queueName,
    //                 'marketplace' =>  'amazon',
    //                 'app_tag' => $appTag
    //             ];
    //             $queuedTask = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
    //             $queuedTaskId = $queuedTask->setQueuedTask((string)$sqsData['data']['home_shop_id'], $queueData);
    //             if ($queuedTaskId) {
    //                 $options = ['projection' => ['_id' => 1], 'sort' => ['_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']];
    //                 $firstIndex = $amazonCollection->findOne($query, $options);
    //                 $sqsData['data']['queued_task_id'] = $queuedTaskId;
    //                 $sqsData['queue_name'] = $queueName;
    //                 unset($sqsData['handle_added']);
    //                 $sqsData['data']['operation'] = 'import_image_product';
    //                 $sqsData['data']['page'] = 0;
    //                 $sqsData['data']['limit'] = 60;
    //                 $sqsData['data']['lastPointer'] = (string)$firstIndex['_id'] ?? '';
    //                 $sqsData['data']['individual_weight'] = ceil($eligibleProducts / 60);
    //                 $this->di->getMessageManager()->pushMessage($sqsData);
    //                 $this->di->getLog()->logContent('import_image_product = ' . json_encode($sqsData), 'info', "syncStatusCommand/{$date}.log");
    //             }
    //         }
    //     } catch(Exception $e) {
    //         print_r($e->getMessage());
    //         $this->di->getLog()->logContent('initiateImageImport exception= ' . print_r($e->getMessage(), true), 'info', "syncStatusCommand/{$date}.log");
    //     }
    // }

    /** update status of manually linked product 
     * $statusArray =  [seller-sku => 'status']
    */
    // public function updateManualLinkedProductStatus($statusArray, $userId, $sourceShopId, $targetShopId): void
    // {
    //     try {
    //         if (!empty($statusArray)) {
    //             $bulkupdateasinData = [];
    //             $filter = ["typeMap" => ['root' => 'array', 'document' => 'array']];
    //             // $pipeline = ['user_id' => (string)$userId, 'shop_id' => (string)$targetShopId, 'seller-sku' => ['$in' => array_keys($statusArray)], 'manual_mapped' => true, 'matchedwith' => 'manual'];
    //             $pipeline = ['user_id' => (string)$userId, 'shop_id' => (string)$targetShopId, 'seller-sku' => ['$in' => array_map('strval',array_keys($statusArray))], 'manual_mapped' => true, 'matchedwith' => 'manual'];
    //             $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
    //             $amazonCollection = $baseMongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);
    //             $amazonListing = $amazonCollection->find($pipeline, $filter)->toArray();
    //             if (!empty($amazonListing)) {
    //                 foreach($amazonListing as $listing) {
    //                     if(isset($listing['status']) && $listing['status'] != $statusArray[$listing['seller-sku']]) {
    //                         if (isset($listing['fulfillment-channel']) && ($listing['fulfillment-channel'] != 'DEFAULT' || $listing['fulfillment-channel'] != 'default')) {
    //                             $channel = 'FBA';
    //                         } else {
    //                             $channel = 'FBM';
    //                         }

    //                         $asinData = [
    //                             'source_product_id' => $listing['source_product_id'],
    //                             'container_id' => $listing['source_container_id'],
    //                             'shop_id' => (string)$targetShopId,
    //                             'status' => $statusArray[$listing['seller-sku']],
    //                             'target_marketplace' => 'amazon',
    //                             'source_shop_id'=> (string)$sourceShopId,
    //                             'fulfillment_type' => $channel
    //                         ];
    //                         array_push($bulkupdateasinData, $asinData);
    //                     }
    //                 }

    //                 if (!empty($bulkupdateasinData)) {
    //                     $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
    //                     $helper->saveProduct($bulkupdateasinData);
    //                 }
    //             }
    //         }
    //     } catch(Exception $e) {
    //         print_r($e->getMessage());
    //     }
    // }

    /** initiate fetch report for seller if not completed in 6 hr */
    public function initiateFetchReport()
    {
        try {
            // set di
            $this->di->getAppCode()->setAppTag('amazon_sales_channel');
            $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
            $appCodeArray = $appCode->toArray();
            $this->di->getAppCode()->set($appCodeArray);
            $appTag = $this->di->getAppCode()->getAppTag();
            $processCode = $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getProductReportQueueName();
            $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $queueTable = $baseMongo->getCollection('queued_tasks');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
            $preThirtyMinTime = date('Y-m-d H:i:s', strtotime('-30 minutes'));
            $query = ['appTag' => $appTag, 'process_code' => $processCode, 'updated_at' => ['$lt' => $preThirtyMinTime], 'progress' => ['$lte' => 5]];
            $searchResponse = $queueTable->find($query, $options)->toArray();
            if (!empty($searchResponse)) {
                $date = date('d-m-Y');
                // need remote_shop_id and di not set
                $reportHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\SyncStatus::class);
                $commonHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Common');
                foreach ($searchResponse as $queuedTask) {
                    if (!isset($queuedTask['user_id'])) {
                        $this->di->getLog()->logContent('userid not set during sync status fetch cron!', 'info', "syncStatusCommand/{$date}.log");
                        continue;
                    }
                    // check if queued task is stuck in 2nd phase
                    if (isset($queuedTask['progress'], $queuedTask['additional_data']['report_id']) && $queuedTask['message'] == 'Success! Sync Status Report request submitted on Amazon.') {
                        $diRes = $commonHelper->setDiForUser($queuedTask['user_id']);
                        $this->di->getLog()->logContent('processing for user: '.json_encode($queuedTask['user_id']), 'info', "syncStatusCommand/{$date}.log");
                        if (!$diRes['success']) {
                            $this->di->getLog()->logContent('di error!', 'info', "syncStatusCommand/{$date}.log");
                            continue;
                        }
                        $targetShop = [];
                        if (!empty($this->di->getUser()->shops)) {
                            foreach($this->di->getUser()->shops as $shop) {
                                if (($shop['_id'] == $queuedTask['shop_id'])
                                && ($shop['marketplace'] == $queuedTask['marketplace'])) {
                                    if (isset($shop['warehouses'][0]['status']) && ($shop['warehouses'][0]['status'] == 'active')) {
                                        $targetShop = $shop;
                                        break; 
                                    } else {
                                        $this->di->getLog()->logContent('warehouse inactive', 'info', "syncStatusCommand/{$date}.log");
                                        $reportHelper->updateProcess((string)$queuedTask['_id'], 100, $reportHelper::WAREHOUSE_INACTIVE_ERROR);
                                        continue;
                                    }
                                }
                            }
                        }
                        if (isset($targetShop['remote_shop_id']) && !empty($targetShop['sources'])) {
                            $data = [
                                'user_id' => $queuedTask['user_id'],
                                'remote_shop_id' => $targetShop['remote_shop_id'],
                                'target_shop_id' => $queuedTask['shop_id'],
                                'target_marketplace' => $queuedTask['marketplace'],
                                'queued_task_id' => (string)$queuedTask['_id']
                            ];
                            foreach ($targetShop['sources'] as $sources) {
                                if (isset($sources['shop_id'], $sources['code'])) {
                                    $data['source_shop_id'] = $sources['shop_id'];
                                    $data['source_marketplace'] = $sources['code'];
                                    $sqsData = $reportHelper->prepareSqsData($reportHelper::REPORT_FETCH_OPERATION, $data);
                                    $sqsData['data']['report_id'] = $queuedTask['additional_data']['report_id'];
                                    if (isset($queuedTask['additional_data']['initiateImage']) && $queuedTask['additional_data']['initiateImage']) {
                                        $sqsData['initiateImage'] = true;
                                    }

                                    if (isset($queuedTask['additional_data']['lookup']) && $queuedTask['additional_data']['lookup']) {
                                        $sqsData['lookup'] = true;
                                    }

                                    if (isset($queuedTask['process_initiation'])) {
                                        $sqsData['initiated'] = $queuedTask['process_initiation'];
                                    }
                                    $this->di->getLog()->logContent('sqsData= ' . json_encode($sqsData, true), 'info', "syncStatusCommand/{$date}.log");
                                    $this->di->getMessageManager()->pushMessage($sqsData);
                                }
                            }
                        }
                    } elseif ($queuedTask['updated_at'] <  date('Y-m-d H:i:s', strtotime('-1day'))) {
                        //we need to ecide what needs to be done
                        // stuck because of another reason for too long (6hr), remove queued task and add log
                        $this->di->getLog()->logContent('removedQueuedTask= ' . json_encode($queuedTask, true), 'info', "syncStatusCommand/{$date}.log");
                        $reportHelper->updateProcess((string)$queuedTask['_id'], 100, $reportHelper::ERROR_SYNC_STATUS);
                    }
                }
            }
        } catch(Exception $e) {
            $this->di->getLog()->logContent('initiateFetchReport exception= ' . print_r($e->getMessage(), true), 'info', "syncStatusCommand/{$date}.log");
        }
        return true;
    }

    public function enableServerlessReportFetchForAmazonShop($data) {
        try {
            if(isset($data['user_id'], $data['shop_id'], $data['report_type'])) {
                $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $shop = $userDetails->getShop($data['shop_id'], $data['user_id']);
                if(!empty($shop['remote_shop_id'])) {
                    $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
                    $dynamoClientObj = $dynamoObj->getDetails();
                    $marshaler = new Marshaler();
                    $reportTableData = $dynamoClientObj->getItem([
                        'TableName' => self::AMAZON_REPORT_CONTAINER_USERS,
                        'Key' => $marshaler->marshalItem([
                            'activate' => '1',
                            'id' => $shop['remote_shop_id'].'_'.$data['report_type'],
                        ]),
                        'ConsistentRead' => true,
                    ]);
                    if(empty($reportTableData['Item'])) {
                        $preparedData = [
                            'activate' => '1',
                            'id' => $shop['remote_shop_id'].'_'.$data['report_type'],
                            'report_type' => $data['report_type'],
                            'remote_shop_id' => $shop['remote_shop_id'],
                            'user_id' => $data['user_id']
                        ];
                        $insert = [
                            'TableName' => self::AMAZON_REPORT_CONTAINER_USERS,
                            'Item' => $preparedData
                        ];
                        $dynamoClientObj->putItem($insert);
                        return ['success' => true, 'message' => 'Serverless report fetch enabled for this shop'];
                    } else {
                        $message = "This shop is already enabled for serverless report fetch";
                    }
                } else {
                    $message = "Unable to find remote_shop_id for this shop";
                }
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch(Exception $e) {
            $this->di->getLog()->logContent('enableServerlessReportFetchForAmazonShop Error: ' . json_encode($e->getMessage()), 'info', "exception.log");
            throw $e;
        }
        return true;
    }

}