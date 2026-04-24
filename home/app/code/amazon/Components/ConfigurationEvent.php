<?php

namespace App\Amazon\Components;

use App\Core\Components\Base;
use App\Amazon\Components\Common\Helper;
use Exception;
use App\Amazon\Components\Bulletin\Bulletin;
use App\Amazon\Components\Template\CategoryAttributeCache;
use Phalcon\Events\Event;

class ConfigurationEvent extends Base
{
    /**
     * @param $data
     */
    public function afterAccountConnection($event, $myComponent, $data = [], $userId = false): void
    {

        try {
            $date = date('d-m-Y');
            $logFile = "amazon/AfterAccountConnection/{$date}.log";
            $dataToConnector = [];
            if (!$userId) {
                $userId = $this->di->getUser()->id;
            }

            $this->di->getLog()->logContent('afterAccountConnection data =>' . json_encode(
                [
                    'user_id' => $userId,
                    'data' => $data
                ]
            ), 'info', $logFile);
            if (isset($data['source_shop']['marketplace']) && isset($data['target_shop']['marketplace']) && $data['target_shop']['marketplace'] == 'amazon') {
                $dataToConnector = [
                    'user_id' => $userId,
                    'source' =>  $data['source_shop']['marketplace'],
                    'target' => $data['target_shop']['marketplace'],
                    'source_shop_id' => $data['source_shop']['_id'],
                    'target_shop_id' => $data['target_shop']['_id'],
                    'source_warehouse_id' =>  $data['source_shop']['warehouses'][0]['_id'],
                    'target_warehouse_id' =>  $data['target_shop']['warehouses'][0]['_id'],
                    'group_code' => 'onboarding',
                    'key' => Helper::STEP_COMPLETED,
                    'value' => 1
                ];
                if ($this->di->getAppCode()->getAppTag()) {
                    $dataToConnector['app_tag'] = $this->di->getAppCode()->getAppTag();
                } else {
                    $dataToConnector['app_tag'] = 'default';
                }
                $objectManager = $this->di->getObjectManager();
                $configObj = $objectManager->get('\App\Core\Models\Config\Config');
                $configObj->setUserId();
                $configObj->setConfig([$dataToConnector]);
                $this->di->getLog()->logContent('config data set =' . json_encode($dataToConnector), 'info', $logFile);
                $requesterObj = $objectManager->get('\App\Core\Components\Requester');
                $request = [
                    'Ced-Source-Id' => $data['source_shop']['_id'],
                    'Ced-Source-Name' => $data['source_shop']['marketplace'],
                    'Ced-Target-Id' => $data['target_shop']['_id'],
                    'Ced-Target-Name' => $data['target_shop']['marketplace']
                ];
                $this->di->getLog()->logContent('request data =' . json_encode($request), 'info', $logFile);
                $requesterObj->setHeaders($request);

                $preparedData = [
                    'source_marketplace' => [
                        'shop_id' => $data['source_shop']['_id'], // shopify shop id
                        'marketplace' => $data['source_shop']['marketplace'] // shopify
                    ],
                    'target_marketplace' => [
                        'shop_id' => $data['target_shop']['_id'], // amazon shop id
                        'marketplace' => $data['target_shop']['marketplace'] // amazon
                    ]
                ];
                $servelessUsage = $this->di->getConfig()->get('serverlessUsage') ?? true;
                if ($servelessUsage) {
                    $syncUserResponse = $this->di->getObjectManager()->get(ShopEvent::class)
                        ->syncUserToDynamo($data['target_shop']);
                    if (isset($syncUserResponse['success']) && $syncUserResponse['success']) {
                        $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                            ->createSubscription($preparedData);
                    } else if(isset($syncUserResponse['message']) && $syncUserResponse['message'] == 'No active Plan') {
                        $webhookCodes = $this->di->getConfig()->get('mandatory_webhook_before_plan_selection') ? $this->di->getConfig()->get('mandatory_webhook_before_plan_selection')->toArray() : [];
                        $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Notification::class)
                            ->registerSpecificWebhookByCode($userId, $data['target_shop'], $webhookCodes);
                    } else {
                        $this->di->getLog()->logContent('Unable to sync user to dynamo, Response:' . json_encode($syncUserResponse), 'info', $logFile);
                    }
                }

                $checkPlan = $objectManager->get('\App\Plan\Models\Plan');
                $planExists = $checkPlan->getActivePlanForCurrentUser($userId);
    
                if(!empty($planExists) && $planExists['status'] && $planExists['status'] == 'active'){
                    $this->runAllAmzProcess($data);
                }
               

               
                // $preparedData = [
                //     'source_marketplace' => [
                //         'source_shop_id' => $data['source_shop']['_id'], // source shop id
                //         'marketplace' => $data['source_shop']['marketplace'] // source
                //     ],
                //     'target_marketplace' => [
                //         'target_shop_id' => $data['target_shop']['_id'], // amazon shop id
                //         'marketplace' => $data['target_shop']['marketplace'] // amazon
                //     ],
                //     'lookup' => true,
                //     'initiateImage' => true
                // ];

                // $this->di->getObjectManager()->get(\App\Amazon\Components\Report\SyncStatus::class)->startSyncStatus($preparedData);

                // $this->di->getLog()->logContent('report request initiated, prepared Data =' . json_encode($preparedData), 'info', $logFile);

                // $productContainerModel = $objectManager->get('\App\Connector\Models\ProductContainer');
                // $count = $productContainerModel->getProductsCount([]);
                // $this->di->getLog()->logContent('count data =' . json_encode($count), 'info', $logFile);
                // if (isset($count['data']['count'])) {
                //     $count = $count['data']['count'];
                //     if ($count > 0) {
                //         $params = [
                //             'match' => [
                //                 'user_id' => $this->di->getUser()->id,
                //                 'shop_id' => (string)$data['source_shop']['_id'],
                //                 'type' => 'default_product_import'
                //             ]
                //         ];

                //         $tasks = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')
                //             ->getAllQueuedTasks($params);
                //         $this->di->getLog()->logContent('queued tasks =' . json_encode($tasks), 'info', $logFile);
                //         if (!isset($tasks['data']['count'][0]['count']) || (int)$tasks['data']['count'][0]['count'] == 0) {
                //             try {

                //                 //creating products in refine products
                //                 $data = [
                //                     'source' => [ //required
                //                         'marketplace' => $data['source_shop']['marketplace'],
                //                         'shopId' => $data['source_shop']['_id']
                //                     ],
                //                     'target' => [ //required
                //                         'marketplace' => $data['target_shop']['marketplace'],
                //                         'shopId' => $data['target_shop']['_id']
                //                     ],
                //                     'user_id' => $this->di->getUser()->id //required
                //                 ];

                //                 $marketplaceObj = $objectManager->get('\App\Connector\Models\Product\Marketplace');
                //                 $res = $marketplaceObj->createRefinProducts($data);
                //             } catch (Exception $e) {
                //                 $this->di->getLog()->logContent('creating products in refine product, exception msg = ' . $e->getMessage() . ', trace = ' . json_encode($e->getTrace()), 'info', $logFile);
                //             }
                //         }
                //     }
                // }

                // $this->addSellerWiseProductTypeAttributes($data);
                // if (!empty($data['source_shop']) && !empty($data['target_shop'])) {
                //     if (isset($data['source_shop']['currency'], $data['target_shop']['currency']) && ($data['source_shop']['currency'] != $data['target_shop']['currency'])) {
                //         $bulletinData = [
                //             'user_id' => $userId,
                //             'home_shop_id' => $data['target_shop']['_id'] ?? '',
                //             'bulletinItemType' => 'ENABLE_PRICE_ADJUSTMENT',
                //             'bulletinItemParameters' => [],
                //             'source_shop_id' => $data['source_shop']['_id'] ?? '',
                //             'process' => 'price_settings'
                //         ];
                //         $this->di->getObjectManager()->get(Bulletin::class)->createBulletin($bulletinData);
                //     }
                // }
            }
        } catch (Exception $e) {
            $date = date('d-m-Y');
            $logFile = "amazon/AfterAccountConnection/{$date}.log";
            $this->di->getLog()->logContent('after account connection, exception msg = ' . $e->getMessage() . ', trace = ' . json_encode($e->getTrace()), 'info', $logFile);
        }
    }

    public function runAllAmzProcess($data){

        $date = date('d-m-Y');
        $objectManager = $this->di->getObjectManager();
        $logFile = "amazon/runAllAmzProcess/{$date}.log";


                $preparedData = [
                    'source_marketplace' => [
                        'source_shop_id' => $data['source_shop']['_id'], // source shop id
                        'marketplace' => $data['source_shop']['marketplace'] // source
                    ],
                    'target_marketplace' => [
                        'target_shop_id' => $data['target_shop']['_id'], // amazon shop id
                        'marketplace' => $data['target_shop']['marketplace'] // amazon
                    ],
                    'lookup' => true,
                    'initiateImage' => true
                ];

                $this->di->getObjectManager()->get(\App\Amazon\Components\Report\SyncStatus::class)->startSyncStatus($preparedData);

                $this->di->getLog()->logContent('report request initiated, prepared Data =' . json_encode($preparedData), 'info', $logFile);

                $productContainerModel = $objectManager->get('\App\Connector\Models\ProductContainer');
                $count = $productContainerModel->getProductsCount([]);
                $this->di->getLog()->logContent('count data =' . json_encode($count), 'info', $logFile);
                if (isset($count['data']['count'])) {
                    $count = $count['data']['count'];
                    if ($count > 0) {
                        $params = [
                            'match' => [
                                'user_id' => $this->di->getUser()->id,
                                'shop_id' => (string)$data['source_shop']['_id'],
                                'type' => 'default_product_import'
                            ]
                        ];

                        $tasks = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')
                            ->getAllQueuedTasks($params);
                        $this->di->getLog()->logContent('queued tasks =' . json_encode($tasks), 'info', $logFile);
                        if (!isset($tasks['data']['count'][0]['count']) || (int)$tasks['data']['count'][0]['count'] == 0) {
                            try {

                                //creating products in refine products
                                $data = [
                                    'source' => [ //required
                                        'marketplace' => $data['source_shop']['marketplace'],
                                        'shopId' => $data['source_shop']['_id']
                                    ],
                                    'target' => [ //required
                                        'marketplace' => $data['target_shop']['marketplace'],
                                        'shopId' => $data['target_shop']['_id']
                                    ],
                                    'user_id' => $this->di->getUser()->id //required
                                ];

                                $marketplaceObj = $objectManager->get('\App\Connector\Models\Product\Marketplace');
                                $res = $marketplaceObj->createRefinProducts($data);
                            } catch (Exception $e) {
                                $this->di->getLog()->logContent('creating products in refine product, exception msg = ' . $e->getMessage() . ', trace = ' . json_encode($e->getTrace()), 'info', $logFile);
                            }
                        }
                    }
                }


                $this->addSellerWiseProductTypeAttributes($data);
                if (!empty($data['source_shop']) && !empty($data['target_shop'])) {
                    if (isset($data['source_shop']['currency'], $data['target_shop']['currency']) && ($data['source_shop']['currency'] != $data['target_shop']['currency'])) {
                        $bulletinData = [
                            'user_id' => $userId,
                            'home_shop_id' => $data['target_shop']['_id'] ?? '',
                            'bulletinItemType' => 'ENABLE_PRICE_ADJUSTMENT',
                            'bulletinItemParameters' => [],
                            'source_shop_id' => $data['source_shop']['_id'] ?? '',
                            'process' => 'price_settings'
                        ];
                        $this->di->getObjectManager()->get(Bulletin::class)->createBulletin($bulletinData);
                    }
                }
    }

    public function sourceTargetReconnect($event, $myComponent, $data = [], $userId = false): void
    {
        //add notification for account re-connection
        $amazonHelper = $this->di->getObjectManager()->get(Helper::class);
        $amazonHelper->addAccountNotification(
            [
                'target_shop_id' => $data['target_shop']['_id'],
                'source_shop_id' => $data['source_shop']['_id'],
                'source_marketplace' => $data['source_shop']['marketplace'],
                'target_marketplace' => $data['target_shop']['marketplace'],
                'activity_type' => Helper::ACTIVITY_TYPE_ACCOUNT_RECONNECTION
            ]
        );
    }

    public function saveSellerName($event, $myComponent, $data = [], $userId = false): void
    {

        $productHelper = $this->di->getObjectManager()->get(ProductHelper::class);
        $stepDetails = $productHelper->getStepsDetails([]);

        if (isset($stepDetails['stepsCompleted']) && in_array($stepDetails['stepsCompleted'], ['1', 1])) {
            //add notification for account connection

            $amazonHelper = $this->di->getObjectManager()->get(Helper::class);
            $amazonHelper->addAccountNotification(
                [
                    'target_shop_id' => $this->di->getRequester()->getTargetId(),
                    'source_shop_id' => $this->di->getRequester()->getSourceId(),
                    'source_marketplace' => $this->di->getRequester()->getSourceName(),
                    'target_marketplace' => $this->di->getRequester()->getTargetName(),
                    'activity_type' => Helper::ACTIVITY_TYPE_ACCOUNT_CONNECTION
                ]
            );
        }

        $eventData = [
            'user_id' => $this->di->getUser()->id,
            'username' => $this->di->getUser()->username,
            'data' => $stepDetails,
            'shopJson' => json_encode($this->di->getUser()->shops[0] ?? []),
        ];
        $this->di->getEventsManager()->fire('application:stepsCompletedUpdate', $this, $eventData);
    }

    public function addSellerWiseProductTypeAttributes($data)
    {
        if (empty($data['target_shop']) || empty($data['source_shop']) || empty($data['user_id'])) {
            return ['success' => false, "message" => 'target shop, source shop and user id required'];
        }

        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $amazonShop = $userDetails->getShop($data['target_shop']['_id'], $data['user_id']);
        if (empty($amazonShop)) {
            return ['success' => false, "message" => 'amazon shop not found'];
        }

        $params = [
            'shop_id' => $amazonShop['remote_shop_id'],
            'product_type' => $data['product_type'] ?? 'PRODUCT',
            'requirements' => 'LISTING',
            'product_type_version' => null, //this will use the latest version
            'use_seller_id' => true,
        ];
        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        $response = $commonHelper->sendRequestToAmazon('fetch-schema', $params, 'GET');
        if (!$response['success']) {
            return $response;
        }

        $categoryAttribute = $this->di
            ->getObjectManager()
            ->get(CategoryAttributeCache::class);

        $getSchemaContentResponse = $categoryAttribute->getSchemaContent($response['data']['schema']['link']['resource']);
        if (!$getSchemaContentResponse['success']) {
            return $getSchemaContentResponse;
        }

        $properties = $getSchemaContentResponse['data']['properties'] ?? [];
        $preparedData = [];
        if (!empty($properties['brand']['items']['properties']['value']['enum'])) {
            $preparedData['brand'] = [
                'enum' => $properties['brand']['items']['properties']['value']['enum'],
                'enumNames' => $properties['brand']['items']['properties']['value']['enumNames'],
            ];
        }

        if (!empty($properties['merchant_shipping_group']['items']['properties']['value']['enum'])) {
            $preparedData['merchant_shipping_group'] = [
                'enum' => $properties['merchant_shipping_group']['items']['properties']['value']['enum'],
                'enumNames' => $properties['merchant_shipping_group']['items']['properties']['value']['enumNames'],
            ];
        }

        if (empty($preparedData)) {
            return [
                'success' => true,
                'data' => [
                    'brand' => [],
                    'merchant_shipping_group' => []
                ]
            ];
        }

        $queryFilter = [
            'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
            'source' =>  $data['source_shop']['marketplace'],
            'source_id' => $data['source_shop']['_id'],
            'target' =>  $data['target_shop']['marketplace'],
            'target_id' => $data['target_shop']['_id'],
            'seller_id' => $amazonShop['warehouses'][0]['seller_id'],
            'marketplace_id' => $amazonShop['warehouses'][0]['marketplace_id'],
        ];
        $jsonCollection = $this->di->getObjectManager()
            ->get('\App\Core\Models\BaseMongo')
            ->getCollectionForTable('amazon_seller_attributes');

        $jsonCollection->updateOne(
            $queryFilter,
            [
                '$setOnInsert' => ['created_at' => date('c')],
                '$set' => [
                    'additional_attributes' => $preparedData,
                    'updated_at' => date('c')
                ]
            ],
            ['upsert' => true]
        );
        return [
            'success' => true,
            'data' => [
                'brand' => $properties['brand'] ?? [],
                'merchant_shipping_group' => $properties['merchant_shipping_group'] ?? []
            ]
        ];
    }
}
