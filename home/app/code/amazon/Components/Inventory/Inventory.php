<?php

namespace App\Amazon\Components\Inventory;

use App\Amazon\Models\SourceModel;
use Exception;
use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base;


class Inventory extends Base
{

    private ?string $_user_id = null;
    private $_user_details;


    public function init($request = [])
    {
        if (isset($request['user_id'])) {
            $this->_user_id = (string)$request['user_id'];
        } else {
            $this->_user_id = (string)$this->di->getUser()->id;
        }

        $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');

        return $this;
    }

    public function upload($sqs_data)
    {
        try {

            $data = $sqs_data['data'];
            $source_marketplace = $data['source_marketplace'];
            $target_marketplace = $data['target_marketplace'];
            $source_shop_id = $data['source_shop_id'];
            $shops = $data['amazon_shops'];
            $returnArray = [];
            $errorArray = [];
            $successArray = [];

            if ($target_marketplace == '' || $source_marketplace == '' || empty($shops)) {
                return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
            }

            $commonHelper = $this->di->getObjectManager()->get(Helper::class);

            $allValidProductStatus = [
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_LISTING_IN_PROGRESS,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_ACTIVE,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INACTIVE,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INCOMPLETE,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_SUPRESSED,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UNKNOWN,
            ];

            foreach ($shops as $shop) {
                $marketplace_id = $shop['warehouses'][0]['marketplace_id'];

                $isEnableInventory = $this->checkInventorySync();
                if (isset($isEnableInventory['status']) && !$isEnableInventory['status']) {
                    $errorArray[$marketplace_id] = 'Inventory Sync Is Disabled';
                } else {
                    $params = [];
                    $feedContent = [];
                    $params['source_marketplace']['marketplace'] = $source_marketplace;
                    $params['target_marketplace']['marketplace'] = $target_marketplace;
                    $params['target_marketplace']['shop_id'] = $shop['_id'];
                    $params['source_marketplace']['shop_id'] = $source_shop_id;
                    $params['source_product_id'] = [$data['source_product_ids']];

                    $objectManager = $this->di->getObjectManager();
                    $helper = $objectManager->get('\App\Connector\Models\Product\Index');
                    $mergedData = $helper->getProducts($params);


                    if (!empty($mergedData)) {
                        if (isset($mergedData['data']['rows'])) {
                            foreach ($mergedData['data']['rows'] as $product) {

                                $productStatus = $this->di->getObjectManager()->get(SourceModel::class)->getProductStatusByShopId($product, $shop['_id']);

                                if (in_array($productStatus, $allValidProductStatus)) {
                                    // $preparedData = $this->prepareData($product);
                                    $preparedData = $this->newData($product, $source_marketplace);
                                    $this->di->getLog()->logContent('preparedData = ' . print_r($preparedData, true), 'info', 'InventoryUpdate.log');
                                    die;
                                }
                            }

                            if (!empty($feedContent)) {
                                $specifics = [
                                    'ids' => array_keys($feedContent),
                                    'home_shop_id' => $shop['_id'],
                                    'marketplace_id' => $marketplace_id,
                                    'shop_id' => $shop['remote_shop_id'],
                                    'feedContent' => base64_encode(json_encode($feedContent))
                                ];

                                $response = $commonHelper->sendRequestToAmazon('inventory-upload', $specifics, 'POST');

                                $this->di->getLog()->logContent('response = ' . print_r($response, true), 'info', 'InventoryUpdate.log');


                                if (isset($response['success']) && $response['success']) {
                                    $successArray[$marketplace_id] = 'Feed successfully uploaded on s3';
                                } else {
                                    $errorArray[$marketplace_id] = 'Error from remote';
                                }
                            } else {
                                $errorArray[$marketplace_id] = 'No products available for inventory update';
                            }
                        } else {
                            $errorArray[$marketplace_id] = 'No Products Found';
                        }
                    } else {
                        $errorArray[$marketplace_id] = 'No Products Found';
                    }
                }
            }

            if (!empty($errorArray)) {
                $returnArray['error'] = ['status' => true, 'message' => $errorArray];
            }

            if (!empty($successArray)) {
                $returnArray['success'] = ['status' => true, 'message' => $successArray];
            }

            if (isset($sqs_data['queued_task_id'])) {
                $message = 'Inventory data successfully uploaded on s3 bucket.';

                $this->di->getObjectManager()->get('App\Connector\Components\Helper')
                    ->updateFeedProgress(
                        $sqs_data['queued_task_id'],
                        100,
                        $message
                    );

                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')
                    ->addNotification((string)$this->_user_id, $message, 'success');
            }

            return $returnArray;
        } catch (Exception $e) {
            $success = false;
            $message = $e->getMessage();
            return ['success' => $success, 'message' => $message];
        }
    }

    public function checkInventorySync()
    {
        return ['status' => true];
    }

    public function prepareData($product)
    {
        $latency = 1;
        $quantity = ($product['quantity'] <= 0) ? 0 : $product['quantity'];
        return [
            'SKU' => $product['sku'],
            'Quantity' => $quantity,
            'Latency' => $latency
        ];
    }

    public function newData($product, $sourceMarketplace, $invTemplateData = [], $defaultProfile = true)
    {
        $activeWarehouses = $this->getActiveSourceWarehouses($sourceMarketplace);
        if (isset($product['profile'])) {
            return "profile";
        }
        if ($defaultProfile) {
            return $defaultProfile;
        }
    }

    public function getActiveSourceWarehouses($sourceMarketplace, $warehouses = [])
    {
        // $userDetailsTable =  $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $userDetailsTable = $mongo->getCollectionForTable("user_details");
        $userDetails = $userDetailsTable->findOne(['user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        foreach ($userDetails['shops'] as $value) {
            if ($value['marketplace'] === $sourceMarketplace) {
                if (isset($value['warehouses'])) {
                    foreach ($value['warehouses'] as $warehouse) {
                        if ($warehouse['active']) {
                            $warehouses[] =  $warehouse['id'];
                        }
                    }
                }

                break;
            }
        }

        return $warehouses;
    }

    /**
     * Synchronizes inventory on Amazon for all connected active targets.
     *
     * @function inventorySyncBackupProcess
     * @param {array[]} data - Array of data for syncing inventory.
     * @param  required {string} data[].{source_marketplace,source_shop_id,source_product_ids}
     */
    public function inventorySyncBackupProcess($data)
    {
        if (isset($data['source_marketplace'], $data['source_shop_id'], $data['source_product_ids'])) {
            $shops = $this->di->getUser()->shops ?? [];
            if (!empty($shops)) {
                $product = $this->di->getObjectManager()->get(\App\Connector\Models\Product\Syncing::class);
                foreach ($shops as $shop) {
                    if ($shop['marketplace'] == HELPER::TARGET && $shop['apps'][0]['app_status'] == 'active') {
                        $prepareData = [
                            'operationType' => 'inventory_sync',
                            'target' => [
                                'marketplace' => HELPER::TARGET,
                                'shopId' => $shop['_id']
                            ],
                            'source' => [
                                'marketplace' => $data['source_marketplace'],
                                'shopId' => $data['source_shop_id']
                            ],
                            'source_product_ids' => $data['source_product_ids'],
                            'useRefinProduct' => true,
                            'filter' => [
                                'item.status' => [
                                    '0' => ['Inactive', 'Incomplete', 'Active', 'Submitted']
                                ]
                            ]

                        ];
                        $product->startSync($prepareData);
                    }
                }
            } else {
                $message = 'shop not found';
            }

            return ['success' => true, 'message' => 'Process Completed...'];
        } else {
            $message = 'Required params missing';
        }
        return ['sucess' => false, 'message' => $message ?? 'Something went wrong'];
    }

    public function prepareSqsDataForInvnetory($data = [])
    {
        $appCode = $this->di->getAppCode()->get();
        $appTag = $this->di->getAppCode()->getAppTag();
        $queueName = $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getInvnetoryCronQueueName();
        return [
            'type' => 'full_class',
            'class_name' => 'App\Amazon\Components\Inventory\Inventory',
            'method' => 'inventorySyncBackupProcessCron',
            'appCode' => $appCode,
            'appTag' => $appTag,
            'queue_name' => $queueName,
            'user_id' => $data['user_id'] ?? $this->_user_id,
            'data' => [
                // 'queued_task_id' => $data[0]['queued_task_id'] ?? null,
                'home_shop_id' => $data['target_marketplace']['target_shop_id'],
                'remote_shop_id' => $data[0]['remote_shop_id'] ?? null,
                'source_marketplace' => $data['source_marketplace']['marketplace'],
                'target_marketplace' => $data['target_marketplace']['marketplace'],
                'source_shop_id' => $data['source_marketplace']['source_shop_id'],
            ]
        ];
    }

    public function execute($data)
    {
        try {

            $shop = $this->_user_details->getShop($data['target_marketplace']['target_shop_id'], $this->_user_id);
            if (!empty($shop)) {
                if (isset($shop['warehouses'][0]['status']) && $shop['warehouses'][0]['status'] == 'active') {
                    // $queueName = $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getInvnetoryCronQueueName();
                    // $appTag = $this->di->getAppCode()->getAppTag();
                    // $queueTaskData = [
                    //     'user_id' => $this->_user_id,
                    //     'message' => 'Bulk User Invnetory Sync initiated',
                    //     'process_code' => $queueName,
                    //     'marketplace' => $data['target_marketplace']['marketplace'],
                    //     'app_tag' => $appTag,
                    // ];
                    // $queuedTask = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
                    // $queuedTaskId = $queuedTask->setQueuedTask($data['target_marketplace']['target_shop_id'], $queueTaskData);
                    // if ($queuedTaskId) {
                    $data[] = [
                        // 'queued_task_id' => $queuedTaskId,
                        'remote_shop_id' => $shop['remote_shop_id']
                    ];
                    $handlerData = $this->prepareSqsDataForInvnetory($data);
                    // $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($queuedTaskId, 50, '', '');
                    $this->di->getMessageManager()->pushMessage($handlerData);
                    //    $this->inventorySyncBackupProcessCron($handlerData);
                    return ['success' => true, 'message' => 'Inventory sync started'];
                    // }
                    // return ['success' => false, 'message' => 'Under process.'];
                }
                return ['success' => false, 'message' => 'Account is inactive.'];
            }
            return ['success' => false, 'message' => 'Shops not found'];
        } catch (Exception $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }


   public function inventorySyncBackupProcessCron($data)
    {
        if (isset($data['data']['source_marketplace'])) {
            $shops = $this->di->getUser()->shops ?? $this->_user_details->getShop($data['data']['home_shop_id'], $this->_user_id);
           
            if (!empty($shops)) {
                $product = $this->di->getObjectManager()->get(\App\Connector\Models\Product\Syncing::class);
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $cronCollection = $mongo->getCollectionForTable('inventoryCron');
                $cronTime = $cronCollection->findOne(['user_id'=> $this->_user_id,'task'=> 'last_inventory_sync', 'shop_id' =>(string) $data['data']['home_shop_id'] ]); // time to be saved in cron collection
                $cronTime = json_decode(json_encode($cronTime),true);
              
                
                if(isset($cronTime['last_sync_time'])){

                    $productContainer = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
                   
                    $productFetch = $productContainer->find([
                        'user_id' => $this->_user_id,
                        'shop_id' => $data['data']['source_shop_id'],
                        'locations.updated_at' => [
                            '$gte' => $cronTime['last_sync_time']
                            ]
                        ], ['typeMap' => ['root' => 'array', 'document' => 'array'],'projection'=> ['_id'=>0 , 'source_product_id' =>1]]);
                $productFetch = $productFetch->toArray();
                $sourceProductIds = array_column($productFetch, 'source_product_id');
                // print_r($sourceProductIds);die;
                $productData = $productContainer->find(['user_id' => $this->_user_id, 'shop_id' => $data['data']['home_shop_id'], 'target_marketplace' => HELPER::TARGET, 'source_product_id' => ['$in'=>$sourceProductIds], 'status' => ['$in' => ['Active', 'Inactive', 'Incomplete', 'Submitted']]], ['typeMap' => ['root' => 'array', 'document' => 'array'],'projection'=> ['_id'=>0 , 'source_product_id' =>1]]);
                $productData = $productData->toArray();
                $newSourceProductIds = array_column($productData, 'source_product_id');
                
                
                $prepareData = [
                    'operationType' => 'inventory_sync',
                    'target' => [
                        'marketplace' => HELPER::TARGET,
                        'shopId' => (string)$data['data']['home_shop_id']
                    ],
                    'source' => [
                        'marketplace' => $data['data']['source_marketplace'],
                        'shopId' => (string)$data['data']['source_shop_id']
                    ],
                    'useRefinProduct' => true,
                    // 'profile_id'=>'all_products',
                    'projectData' => [
                        'marketplace' => 0
                    ],
                    'source_product_ids' => $newSourceProductIds,
                    'usePrdOpt' => true,
                    'user_id' => $this->_user_id,
                    'limit' => 250,
                    // 'filter' => [
                    //         'items.status' => [
                    //                 '10' => ['Inactive', 'Incomplete', 'Active']
                    //             ]
                    //         ]
                            
                        ];
                        $this->di->getLog()->logContent('preparedData = ' . print_r($prepareData, true), 'info', 'inventorySyncBackupProcessCron.log');
                        
                        // $productProfile = $this->di->getObjectManager()->get('App\Connector\Components\Profile\GetProductProfileMerge');
                        // $sourceIds = $productProfile->getproductsByProductIds($prepareData, true);
                        // print_r($sourceIds);die("hh");
                        
                        // $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($data['data']['queued_task_id'], 100, 'Bulk User Inventory Sync Executed', 'success');
                        $product->startSync($prepareData);
                        $cronCollection->updateOne(['user_id'=> $this->_user_id,'task'=> 'last_inventory_sync', 'shop_id' =>(string) $data['data']['home_shop_id']],['$set'=>['last_sync_time'=> date('c')]]); // time to be saved in cron collection
                    }
                    else{
                         $prepareData = [
                    'operationType' => 'inventory_sync',
                    'target' => [
                        'marketplace' => HELPER::TARGET,
                        'shopId' => (string)$data['data']['home_shop_id']
                    ],
                    'source' => [
                        'marketplace' => $data['data']['source_marketplace'],
                        'shopId' => (string)$data['data']['source_shop_id']
                    ],
                    'useRefinProduct' => true,
                    'profile_id'=>'all_products',
                    'projectData' => [
                        'marketplace' => 0
                    ],
                    // 'source_product_ids' => $newSourceProductIds,
                    'usePrdOpt' => true,
                    'user_id' => $this->_user_id,
                    'limit' => 250,
                    'filter' => [
                            'items.status' => [
                                    '10' => ['Inactive', 'Incomplete', 'Active']
                                ]
                            ]
                            
                        ];
                        $this->di->getLog()->logContent('preparedData = ' . print_r($prepareData, true), 'info', 'inventorySyncBackupProcessCron.log');

                        $product->startSync($prepareData);
                        $cronCollection->updateOne(['user_id'=> $this->_user_id,'task'=> 'last_inventory_sync', 'shop_id' =>(string) $data['data']['home_shop_id']],['$set'=>['last_sync_time'=> date('c')]]); // time to be saved in cron collection

                    }
            } else {
                $message = 'shop not found';
            }
            return ['success' => true, 'message' => 'Process Completed...'];
        } else {
            $message = 'Required params missing';
        }
        return ['sucess' => false, 'message' => $message ?? 'Something went wrong'];
    }
}
