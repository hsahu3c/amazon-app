<?php

namespace App\Amazon\Components\Price;

use App\Amazon\Models\SourceModel;
use Exception;
use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base;


class Price extends Base
{
    private ?string $_user_id = null;

    private $_user_details;

    private $_connectorSourceModel;

    public function init($request = [])
    {
        if (isset($request['user_id'])) {
            $this->_user_id = (string)$request['user_id'];
        } else {
            $this->_user_id = (string)$this->di->getUser()->id;
        }
        $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');

        $this->_connectorSourceModel = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
        return $this;
    }

    public function upload($sqs_data)
    {
        try {
            $data = $sqs_data['data'];
            $source_marketplace = $data['source_marketplace'];
            $target_marketplace = $data['target_marketplace'];
            $source_shop_id = $data['source_shop_id'];
            $amazon_shops = $data['amazon_shops'];
            $returnArray = [];
            $shops = [];
            $errorArray = [];
            $successArray = [];
            foreach ($amazon_shops as $active_account) {
                $changeCurrency = $this->_connectorSourceModel->checkCurrency($this->_user_id, $data['target_marketplace'], $data['source_marketplace'], $active_account['_id']);
                if ($changeCurrency) {
                    $shops[] = $active_account;
                }
            }

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

                $isEnablePrice = $this->checkPriceSync();
                if (isset($isEnablePrice['status']) && !$isEnablePrice['status']) {
                    $errorArray[$marketplace_id] = 'Price Sync Is Disabled';
                } else {
                    $params = [];
                    $feedContent = [];
                    $params['source_marketplace']['marketplace'] = $source_marketplace;
                    $params['target_marketplace']['marketplace'] = $target_marketplace;
                    $params['target_marketplace']['shop_id'] = $shop['_id'];
                    $params['source_marketplace']['shop_id'] = $source_shop_id;
                    $params['source_product_id'] = $data['source_product_ids'];

                    $objectManager = $this->di->getObjectManager();
                    $helper = $objectManager->get('\App\Connector\Models\Product\Index');
                    $mergedData = $helper->getProducts($params);

                    if (!empty($mergedData)) {
                        if (isset($mergedData['data']['rows'])) {
                            foreach ($mergedData['data']['rows'] as $product) {

                                $productStatus = $this->di->getObjectManager()->get(SourceModel::class)->getProductStatusByShopId($product, $shop['_id']);

                                if (in_array($productStatus, $allValidProductStatus)) {

                                    $preparedData = $this->prepareData($product);
                                    $feedContent[$product['source_product_id']] = $preparedData;
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
                                $response = $commonHelper->sendRequestToAmazon('price-upload', $specifics, 'POST');

                                if (isset($response['success']) && $response['success']) {
                                    $successArray[$marketplace_id] = 'Feed successfully uploaded on s3';
                                } else {
                                    $errorArray[$marketplace_id] = 'Error from remote';
                                }

                            } else {
                                $errorArray[$marketplace_id] = 'No products available for price update';
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
                $message = 'Price data successfully uploaded on s3 bucket.';

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

    public function checkPriceSync()
    {
        return ['status' => true];
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
                            $handlerData = $this->prepareSqsDataForPrice($data);
                            // $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($queuedTaskId, 50, '', '');
                        //    $this->di->getMessageManager()->pushMessage($handlerData);
                        // print_r($handlerData);die;
                       $this->priceSyncBackupProcessCron($handlerData);
                        return ['success' => true, 'message' => 'Price sync started'];
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
    public function prepareSqsDataForPrice($data = [])
    {
        $appCode = $this->di->getAppCode()->get();
        $appTag = $this->di->getAppCode()->getAppTag();
        $queueName = $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getPriceCronQueueName();
        return [
            'type' => 'full_class',
            'class_name' => 'App\Amazon\Components\Price\Price',
            'method' => 'priceSyncBackupProcessCron',
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
                'source_shop_id' =>$data['source_marketplace']['source_shop_id'],
            ]
        ];
    }
    public function priceSyncBackupProcessCron($data)
    {
        if (isset($data['data']['source_marketplace'])) {
            $shops = $this->di->getUser()->shops ??$this->_user_details->getShop($data['data']['home_shop_id'], $this->_user_id);
            if (!empty($shops)) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                // $amazonListingCollection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);
                //  $aggregate[] = 
                //     [
                //     '$match' => [
                //         'user_id' => $this->_user_id,
                //         'shop_id' => $data['data']['home_shop_id'],
                //         'matched' => true,
                //         'matchedProduct'=> ['$exists'=>true]
                //     ]
                //     ];

                //     $aggregate[] = [
                //         '$sort' => ['_id' => 1],
                //     ];
                //     // $aggregate[] = ['$project' => ['source_product_id'=>'$matchedProduct.source_product_id', '_id'=>0]];
                //     $aggregate[] = ['$group' => ["_id"=> null, 'source_product_id'=>[ '$addToSet' => '$matchedProduct.source_product_id' ]]];

                //     $amazonListings = $amazonListingCollection->aggregate($aggregate);  
                //     $products = json_decode(json_encode($amazonListings->toArray()),true);
                //     // $sourceProductIds = array_column($products,'matchedProduct');
                //     $sourceProductIds = $products[0]['source_product_id'];
                //    $cacheData['all_source_product_ids'] = $sourceProductIds;
                //    $getTime = time();
                //     $uniqueKeyForSqs = $this->_user_id . 'target_id' . $data['data']['home_shop_id'] . 'source_id' . $data['data']['source_shop_id'] .'price_sync'. $getTime;

                // $this->di->getCache()->set($uniqueKeyForSqs, $cacheData);
 
                $product = $this->di->getObjectManager()->get(\App\Connector\Models\Product\Syncing::class);
                    
                        $prepareData = [
                            'operationType' => 'price_sync',
                            'target' => [
                                'marketplace' => HELPER::TARGET,
                                'shopId' => (string)$data['data']['home_shop_id']
                            ],
                            'source' => [
                                'marketplace' => $data['data']['source_marketplace'],
                                'shopId' => (string)$data['data']['source_shop_id']
                            ],
                            'useRefinProduct' => true,
                            // 'source_product_ids'=>$sourceProductIds,

                            'profile_id'=>'all_products',
                            'projectData'=>[
                                'marketplace' => 0
                            ],
                            'usePrdOpt'=>true,
                            // 'unique_key_for_sqs' => $uniqueKeyForSqs,

                            'user_id' => $this->_user_id,
                            'limit' => 250,
                            'filter' => [
                                'items.status' => [
                                    '10' => ['Inactive', 'Incomplete', 'Active']
                                ]
                            ]

                        ];
                        // print_r($prepareData);die;
                        $this->di->getLog()->logContent('preparedData = ' . print_r($prepareData, true), 'info', 'priceSyncBackupProcessCron.log');

                        // $productProfile = $this->di->getObjectManager()->get('App\Connector\Components\Profile\GetProductProfileMerge');
                        // $sourceIds = $productProfile->getproductsByProductIds($prepareData, true);
                        // print_r($sourceIds);die("hh");
                      
                        // $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($data['data']['queued_task_id'], 100, 'Bulk User Inventory Sync Executed', 'success');
                        $product->startSync($prepareData);
                        // $this->di->getCache()->delete((string) $prepareData['unique_key_for_sqs']);

            } else {
                $message = 'shop not found';
            }
            return ['success' => true, 'message' => 'Process Completed...'];
        } else {
            $message = 'Required params missing';
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    public function prepareData($product)
    {
        $priceData = [];
        $unique_id = $product['source_product_id'];
        $sku = $product['sku'];

        if ($product['price'] < 0) {
            $product['price'] = 0;
        }

        $start = false;
        $end = false;
        $salePrice = false;
        $standardPrice = $product['price'];
        $minimumPrice = false;
        $businessPrice = false;

        $finalSalePrice = number_format((float)$salePrice, 2, '.', '');
        $finalStandardPrice = number_format((float)$standardPrice, 2, '.', '');
        $finalMinimumPrice = number_format((float)$minimumPrice, 2, '.', '');
        $finalBusinessPrice = number_format((float)$businessPrice, 2, '.', '');


        $priceData = [
            'Id' => $unique_id,
            'SKU' => $sku,
            'SalePrice' => (float)$finalSalePrice,
            'StandardPrice' => (float)$finalStandardPrice,
            'StartDate' => $start,
            'EndDate' => $end,
            'BusinessPrice' => (float)$finalBusinessPrice,
            'MinimumPrice' => (float)$finalMinimumPrice,
        ];

        return $priceData;
    }

}
