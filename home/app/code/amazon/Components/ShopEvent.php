<?php

namespace App\Amazon\Components;

use App\Core\Components\Base;
use App\Amazon\Components\Order\Order;
use App\Core\Models\Config\Config;
use App\Amazon\Components\Common\Helper;
use Phalcon\Events\Event;
use \App\Connector\Components\Dynamo;
use Exception;

class ShopEvent extends Base
{

    public function beforeAccountDisconnect($data): void
    {
        $logFile = "amazon/beforeAccountDisconnect/" . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Initiating beforeAccountDisconnect Data Deletion Process, Data=>' . json_encode($data), 'info', $logFile);
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $feedContainer = $mongo->getCollectionForTable('feed_container');
            $feedContainer->deleteMany([
                'user_id' => (string)$data['user_id'],
                'shop_id' => (string)$data['shop_id'],
                'source_shop_id' => (string)$data['source_shop_id']
            ]);
            $amazonListingContainer = $mongo->getCollectionForTable('amazon_listing');
            if ($this->isShopConnectedToAnotherSource($data)) {
                $amazonListingContainer->updateMany(
                    [
                        'user_id' => $data['user_id'],
                        'shop_id' => $data['shop_id'],
                        'matched' => true,
                        'matchedProduct.shop_id' => $data['source_shop_id']
                    ],
                    ['$unset' => [
                        'matchedwith' => 1,
                        'matched' => 1,
                        'source_product_id' => 1,
                        'source_container_id' => 1,
                        'matchedProduct' => 1
                    ]]
                );
                $amazonListingContainer->updateMany(
                    [
                        'user_id' => $data['user_id'],
                        'shop_id' => $data['shop_id']
                    ],
                    ['$unset' => ['closeMatchedProduct' => 1]]
                );
            } else {
                $amazonListingContainer->deleteMany(
                    [
                        'user_id' => (string)$data['user_id'],
                        'shop_id' => (string)$data['shop_id']
                    ]
                );
            }
            $bulletinContainer = $mongo->getCollectionForTable('amazon_bulletin_container');
            $bulletinContainer->deleteMany([
                'user_id' => (string)$data['user_id'],
                'target_shop_id' => (string)$data['shop_id'],
                'source_shop_id' => (string)$data['source_shop_id']
            ]);
            $lastSyncActionData = $mongo->getCollectionForTable('last_sync_action_data');
            $lastSyncActionData->deleteMany([
                'user_id' => (string)$data['user_id'],
                'target_shop_id' => (string)$data['shop_id'],
                'source_shop_id' => (string)$data['source_shop_id']
            ]);

            $productTypeDefinition = $mongo->getCollectionForTable('product_type_definitions_schema');
            $productTypeDefinition->deleteMany([
                'user_id' => (string)$data['user_id'],
                'shop_id' => (string)$data['shop_id']
            ]);

            $csvExportContainer = $mongo->getCollectionForTable('csv_export_product_container');
            $csvExportContainer->deleteMany([
                'user_id' => (string)$data['user_id'],
                'target_shop_id' => (string)$data['shop_id'],
                'source_shop_id' => (string)$data['source_shop_id']
            ]);

            $amazonSellerAttributeContainer = $mongo->getCollectionForTable('amazon_seller_attributes');
            $amazonSellerAttributeContainer->deleteMany([
                'target_id' => (string)$data['shop_id'],
                'source_id' => (string)$data['source_shop_id']
            ]);

            $amazonValueMappingContainer = $mongo->getCollectionForTable('amazon_value_mapping');
            $amazonValueMappingContainer->deleteMany([
                'user_id' => (string)$data['user_id'],
                'target.shopId' => (string)$data['shop_id'],
                'source.shopId' => (string)$data['source_shop_id']
            ]);

            $sellerPerformanceListContainer = $mongo->getCollectionForTable('seller_performance_list');
            $sellerPerformanceListContainer->deleteMany([
                'user_id' => (string)$data['user_id'],
                'shop_id' => (string)$data['shop_id']
            ]);

            $orderService = $this->di->getObjectManager()->get('\App\Connector\Service\Order');
            //removing order data having source_shop_id as shop id
            $orderService->delete(['user_id' => $data['user_id'], 'shop_id' => $data['source_shop_id'], 'targets.shop_id' => $data['shop_id']]);
            //removing order data having deleted shop_id as shop_id
            $orderService->delete(['user_id' => $data['user_id'], 'shop_id' => $data['shop_id'], 'targets.shop_id' => $data['source_shop_id']]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception => ' . $e->getMessage(), 'info', $logFile);
        }
    }

    public function sendRemovedShopToAws($data)
    {
        $logFile = "amazon/temporaryUninstall/" . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Initating sendRemovedShopToAws process, Data =>' . json_encode($data), 'info', $logFile);
        $user = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shopData = $user->getShop($data['shop_id'], $data['user_id']);
        $afterDeleteData = [];
        $message = 'Something went wrong';
        if(!empty($shopData)) {
            $remoteShopId = $shopData['remote_shop_id'];
            $internalShopIds = $this->di->getConfig()->get('internal_amazon_shop_ids');
            if (isset($internalShopIds)) {
                $internalShopIds = $internalShopIds->toArray();
                if (in_array($remoteShopId, $internalShopIds)) {
                    return ['success'=>false, 'message' => 'internal shop found'];
                }
            }

            $dataToSent['remote_shop_id'] = $remoteShopId;
            if (isset($data['deletion_in_progress']) && $data['deletion_in_progress']) {
                $afterDeleteData = $this->getAfterDeletionForceDeleteQueueData($data, $shopData);
            }

            if (!empty($afterDeleteData)) {
                $dataToSent['update_after_delete'] = true;
                $dataToSent['after_deletion_data'] = json_encode($afterDeleteData);
            }

            $this->di->getLog()->logContent('Data send =>' . json_encode($data), 'info', $logFile);
            $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
            $dynamoClientObj = $dynamoObj->getDetails();
            try {
            $dynamoClientObj->deleteItem([
                'TableName' => 'user_details',
                'Key' => [
                    'id'  => ['S' => $remoteShopId]
                ]
            ]);
            $dynamoObj->setTable('amazon_account_delete');
            $dynamoObj->setUniqueKeys(['id', 'remote_shop_id']);
            $dynamoObj->setTableUniqueColumn('id');
            $dynamoObj->saveSingle($dataToSent);
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Exception sendRemovedShopToAws => ' . json_encode($e->getMessage()), 'emergency', $logFile ?? 'exception.log');
            }

            return ['success' => true, 'message' => 'Data send to dynamo successfully!!'];
        }
        $message = 'Shop not found';

        $this->di->getLog()->logContent('Error: '.json_encode($message) , 'info', $logFile);
        return ['success' => false, 'message' => $message];
    }

    public function deleteSubscription($rawBody): void
    {
        $date = date('d-m-Y');
        $logFile = "amazon/BeforeAccountDisconnect/{$date}.log";

        $shop = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')->getShop($rawBody['shop_id'], $rawBody['user_id']);
        $remote_shop_id = $shop['remote_shop_id'];

        //get all the subscription for current remote_shop
        $getSubscriptionResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('cedcommerce', false, 'cedcommerce')
            ->call('event/subscription', [], ['shop_id' => $remote_shop_id,'app_code'=>"cedcommerce"], 'GET');

        $this->di->getLog()->logContent('GetSubscription Response =' . json_encode($getSubscriptionResponse), 'info', $logFile);


        //delete subscription using subscription ids
        if ($getSubscriptionResponse['success'] && !empty($getSubscriptionResponse['data'])) {
            foreach ($getSubscriptionResponse['data'] as $subscription) {
                $subscriptionId  = $subscription['_id'];
             $deleteSubscription =   $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init('cedcommerce', false, 'cedcommerce')
                    ->call('event/subscription',[], ['shop_id' => $remote_shop_id, 'subscription_id' => $subscriptionId, 'app_code' => 'cedcommerce'], 'DELETE');
                $this->di->getLog()->logContent('subscription Id =' . $subscriptionId.', Event code ='.$subscription['event_code'].' Remote response ='.$deleteSubscription['message'] ?? $deleteSubscription['data'] ?? json_encode($deleteSubscription), 'info', $logFile);
            }
        }
    }

    public function isShopConnectedToAnotherSource($data)
    {
        $targetShopId = $data['shop_id'];
        $userId = $data['user_id'];
        $removedFromSource = [$data['source_shop_id']];
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $targetShop = $user_details->getShop($targetShopId, $userId);
        if (isset($targetShop['sources'])) {
            $sources = $targetShop['sources'];
            $sourceShopIdsConnected = array_column($sources, 'shop_id');
            $diffSourceShopId = array_diff_assoc($sourceShopIdsConnected, $removedFromSource);
            if (!empty($diffSourceShopId)) {
                return true;
            }
        }

        return false;
    }

    public function getAfterDeletionForceDeleteQueueData($data, $shop)
    {
        return [
            'type' => 'full_class',
            'class_name' => '\App\Connector\Models\SourceModel',
            'method' => 'triggerWebhooks',
            'user_id' => $data['user_id'],
            'shop_id' => $data['shop_id'],
            'action' => 'app_delete',
            'queue_name' => 'app_delete',
            'app_code' => $shop['apps'][0]['code'],
            'force_delete' => true,
        ];
    }

    /**
     * syncUserToDynamo function
     * Sync new user shop to Dynamo
     * @param [array] $shop
     * @return array
    */
    public function syncUserToDynamo($shop)
    {
        try {
            $logFile = "amazon/syncUserToDynamo/" . date('d-m-Y') . '.log';
            if (!isset($shop['register_for_order_sync'])) {
                if (isset($shop['marketplace'], $shop['_id'], $shop['remote_shop_id'], $shop['warehouses'][0]['status'])
                    && $shop['warehouses'][0]['status'] == 'active') {
                    $userId = $this->di->getUser()->id;
                    $this->di->getLog()->logContent('Starting Process: '
                        . json_encode([
                            'user_id' => $userId,
                            'shop_id' => $shop['_id'],
                            'remote_shop_id' => $shop['remote_shop_id']
                        ]), 'info', $logFile);
                    $planExists = false;
                    $class = '\App\Plan\Models\Plan';
                    if (class_exists($class) && method_exists($class, 'getActivePlanForCurrentUser')) {
                        $planChecker = $this->di->getObjectManager()->get($class);
                        $planData = $planChecker->getActivePlanForCurrentUser($userId);
                        if (empty($planData)) {
                            $this->updateRegisterForOrderSync($userId, $shop['_id'], false);
                            $message = 'No active Plan';
                            $this->di->getLog()->logContent($message, 'info', $logFile);
                            return ['success' => false, 'message' => $message];
                        }

                        $planExists = true;
                    }

                    $priority = 2;
                    $checkFbaSetting = true;
                    if ($planExists) {
                        $customPrice = $planData['plan_details']['custom_price'];
                        if ($customPrice > 100) {
                            $priority = 1;
                        }
    
                        if ($customPrice == 0) {
                            $checkFbaSetting = false;
                            $this->createFbaSettingDocs($userId, $shop);
                        }
                    }

                    $prepareData = [
                        'order_status'      => ['Unshipped'],
                        'order_channel'     => 'MFN',
                        'order_filter'      => '{}',
                        'order_fetch'       =>  1,
                        'disable_order_sync' => 0,
                        'home_shop_id'      => $shop['_id'],
                        'remote_shop_id'    => $shop['remote_shop_id'],
                        'queue_name'        => 'amazon_orders',
                        'priority'          => $priority,
                        'message_data'      => [
                            'user_id'           => $userId,
                            'handle_added'      => '1',
                            'queue_name'        => 'amazon_orders',
                            'type'              => 'full_class',
                            'class_name'        => Order::class,
                            'method'            => 'syncOrder'
                        ]
                    ];
                    if (!$checkFbaSetting) {
                        $prepareData['fba_fetch'] = "1";
                        $prepareData['fba_filter'] = '{}';
                    } else {
                        $configObj = $this->di->getObjectManager()
                            ->get(Config::class);
                        $configObj->setGroupCode('order');
                        $configObj->setUserId($userId);
                        $configObj->setTarget('amazon');
                        $configObj->setTargetShopId($shop['_id']);
                        $configObj->setAppTag(null);
                        $fbaSetting = $configObj->getConfig('fbo_order_fetch');
                        if (!empty($fbaSetting) && $fbaSetting[0]['value']) {
                            $prepareData['fba_fetch'] = "1";
                            $prepareData['fba_filter'] = '{}';
                        }
                    }
    
                    $dynamoData[] = $prepareData;
                    $this->di->getLog()->logContent('Requested remote data: ' . json_encode($dynamoData), 'info', $logFile);
                    $object = $this->di->getObjectManager()->get(Helper::class);
                    $response = $object->sendRequestToAmazon('order-sync/register', $dynamoData, 'POST', 'json');
                    if (isset($response['success']) && $response['success'] && !empty($response['registered'])) {
                        $this->updateRegisterForOrderSync($userId, $shop['_id'], true);
                        $message = 'User Sync To Dynamo Successfully!!';
                        $this->di->getLog()->logContent($message, 'info', $logFile);
                        $this->updateOrderFetchKey($userId, true);
                        return ['success' => true, 'message' => $message];
                    } else {
                        $this->di->getLog()->logContent('order-sync/register request failed, Response: '
                            . json_encode($response), 'info', $logFile);
                    }
                    if (isset($response['message'])) {
                        $message = $response['message'];
                        $this->di->getLog()->logContent($message, 'info', $logFile);
                    }
                } else {
                    $message = 'Required params missing or warehouse is inactive';
                }
            } else {
                $message = 'User is already synced to dynamo!!';
            }

            return ['success' => false, 'message' => $message];
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception from syncUserToDynamo(), Error:'.json_encode($e->getMessage()), 'info', $logFile ?? 'exception.log');
        }
    }

    public function createFbaSettingDocs($userId, $shop): void
    {
        if (!empty($shop['sources'])) {
            $sources = $shop['sources'][0];
            $sourceMarketplace = $sources['code'];
            $sourceShopId = $sources['shop_id'];
            $targetShopId = $shop['_id'];
            $appCode = $sources['app_code'] ?? 'default';
            $this->di->getAppCode()->setAppTag($appCode);
            $appTag = $this->di->getAppCode()->getAppTag();
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $config = $mongo->getCollectionForTable('config');
            $prepareConfigData = [
                'key' => 'fbo_order_fetch',
                'value' => true,
                'group_code' => 'order',
                'source_shop_id' => $sourceShopId,
                'target_shop_id' => $targetShopId,
                'app_tag' => $appTag,
                'user_id' => $userId,
                'source' => $sourceMarketplace,
                'target' => $shop['marketplace'] ?? 'amazon',
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];
            try {
                $config->updateOne(
                    [
                        'user_id' => $userId,
                        'key' => $prepareConfigData['key'],
                        'source_shop_id' => $sourceShopId,
                        'target_shop_id' => $targetShopId
                    ],
                    [
                        '$set' => $prepareConfigData,
                    ],
                    ['upsert' => true]
                );
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Exception From createFbaSettingDoc Function=> '
                    . json_encode($e->getMessage()), 'emergency', 'exception.log');
            }
        }
    }

    public function appReInstall($event, $myComponent, $data)
    {
        $logFile = 'amazon/appReInstall/' . $this->di->getUser()->id . '/' . date('d-m-Y') . '/data.log';
        $this->di->getLog()->logContent('Starting Process of appReInstall,Data=> ' . print_r($data, true), 'info', $logFile);
        $marketplace = 'amazon';
        $userId = $this->di->getUser()->id;
        $filter = [];
        $disconnectedFilter = [];
        if (!empty($data['source_shop']) && $data['source_shop']['marketplace'] == $marketplace) {
            $shopData = $data['source_shop'];
            if (!empty($shopData)) {
                $dataToSent['user_id'] = $userId;
                $dataToSent['target_marketplace'] = [
                    'marketplace' => $shopData['marketplace'],
                    'shop_id' => $shopData['_id']
                ];
                $shopData['apps'][0]['app_status'] = 'active';
                $this->di->getObjectManager()->get('\App\Amazon\Components\Shop\Hook')
                ->updateWarehouseStatus($userId, $shopData['_id'], 'active');
                $syncUserResponse = $this->syncUserToDynamo($shopData);
                if (isset($syncUserResponse['success']) && $syncUserResponse['success']) {
                    $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                        ->createSubscription($dataToSent);
                    $message = 'User Synced and Webhooks Registered!!';
                    $this->di->getLog()->logContent($message, 'info', $logFile);
                } else {
                    $message = 'Unable to sync user to dynamo';
                    $this->di->getLog()->logContent($message . json_encode($syncUserResponse), 'info', $logFile);
                }
                return [
                    'success' => true,
                    'message' => $message
                ];
            }
            $message = 'Shop not found';
            $this->di->getLog()->logContent($message, 'info', $logFile);
        } else {
            if (!empty($data['source_shop']['sources'])) {
                $key = 'sources';
            } elseif (!empty($data['source_shop']['targets'])) {
                $key = 'targets';
            } else {
                return ['success' => false, 'message' => 'Sources or targets not found'];
            }

            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $userDetails = $mongo->getCollection("user_details");
            $sourceOrTargets = $data['source_shop'][$key];
            $shops = $this->di->getUser()->shops;
            if (!empty($shops)) {
                foreach ($sourceOrTargets as $sourceTargetData) {
                    if ($sourceTargetData['code'] == $marketplace) {
                        foreach ($shops as $shopData) {
                            $dataToSent = [];
                            if ($shopData['_id'] == $sourceTargetData['shop_id']) {
                                $this->di->getLog()->logContent('Starting Process for ShopId: '
                                    . json_encode($shopData['_id']), 'info', $logFile);
                                $filter[] = $this->unsetKeys($userId, $shopData, $sourceTargetData['app_code']);
                                $dataToSent['user_id'] = $userId;
                                $dataToSent['target_marketplace'] = [
                                    'marketplace' => $shopData['marketplace'],
                                    'shop_id' => $shopData['_id']
                                ];
                                $shopData['apps'][0]['app_status'] = 'active';
                                $this->di->getObjectManager()->get('\App\Amazon\Components\Shop\Hook')
                                    ->updateWarehouseStatus($userId, $shopData['_id'], 'active');
                                $syncUserResponse = $this->syncUserToDynamo($shopData);
                                if (isset($syncUserResponse['success']) && $syncUserResponse['success']) {
                                    $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                                        ->createSubscription($dataToSent);
                                    $message = 'User Synced and Webhooks Registered!!';
                                    $this->di->getLog()->logContent($message, 'info', $logFile);
                                } else {
                                    $message = 'Unable to sync user to dynamo';
                                    $this->di->getLog()->logContent($message . json_encode($syncUserResponse), 'info', $logFile);
                                }
                                break;
                            }
                        }
                    }
                    if (isset($sourceTargetData['disconnected'])) {
                        $disconnectedFilter[] = [
                            'updateOne' => [
                                [
                                    "user_id" => $userId,
                                    "shops.targets" => [
                                        '$elemMatch' => [
                                            "shop_id" => $sourceTargetData['shop_id'],
                                            "disconnected" => ['$exists' => true]
                                        ]
                                    ]
                                ],
                                [
                                    '$unset' => [
                                        "shops.$.targets.$[targetElem].disconnected" => ""
                                    ]
                                ],
                                [
                                    'arrayFilters' => [
                                        ["targetElem.shop_id" => $sourceTargetData['shop_id']]
                                    ]
                                ]
                            ]
                        ];
                    }
                }

                if (!empty($disconnectedFilter)) {
                    $userDetails->bulkWrite($disconnectedFilter);
                }

                if (!empty($filter)) {
                    $userDetails->bulkWrite($filter);
                }

                $message = 'User Synced and Webhooks Registered!!';
                $this->di->getLog()->logContent($message, 'info', $logFile);
                return ['success' => true, 'message' => $message];
            }
            $message = 'Unable to fetch shops from Di';
        }

        return ['success' => false, 'message' => $message ?? 'Required Params Missing or marketplace not matched'];
    }

    public function unsetKeys($userId, $shopData, $appCode)
    {
        return [
            'updateOne' => [
                [
                    "user_id" => $userId,
                    "shops" => [
                        '$elemMatch' => [
                            "marketplace" => $shopData['marketplace'],
                            "_id" => $shopData['_id'],
                        ]
                    ],
                ],
                [
                    '$set' => [
                        "shops.$.apps.$[appElem].app_status" => 'active',
                    ],
                    '$unset' => [
                        "shops.$.apps.$[appElem].erase_data_after_date" => true,
                        "shops.$.apps.$[appElem].uninstall_date" => true,
                        "shops.$.apps.$[appElem].temporarly_uninstall" => true,

                    ]
                ],
                [
                    'arrayFilters' => [
                        ['appElem.code' => $appCode]
                    ],
                ]
            ]
        ];
    }

    public function shopStatusUpdate($event, $myComponent, $data)
    {
        try {
            $logFile = "amazon/shopStatusUpdate/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('Starting shopUpdate process for: '.json_encode($data), 'info', $logFile);
            if (isset($data['shops'], $data['status']) && !empty($data['shops'])) {
                $shopData = $data['shops'];
                $sourceModel = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel");
                $marketplace = 'amazon';
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                foreach ($shopData as $shop) {
                    if ($shop['marketplace'] == $marketplace
                        && $shop['apps'][0]['app_status'] == 'active') {
                        $appCode = $shop['apps'][0]['code'] ?? 'default';
                        if ($data['status'] == 'closed') {
                            $dataTosent = [
                                'user_id' => $userId,
                                'shop_id' => $shop['_id'],
                                'remote_shop_id' => $shop['remote_shop_id']
                            ];
                            $this->sendRemovedShopToAws($dataTosent);
                            $sourceModel->routeUnregisterWebhooks($shop, 'amazon', $appCode, false);
                            $this->updateRegisterForOrderSync($userId, $shop['_id'], false);
                            $this->setSourceStatus($userId, $shop, 'closed');
                            $this->di->getLog()->logContent('Store close action completed successfully', 'info', $logFile);
                        } elseif ($data['status'] == 'reopened') {
                            $preparedData = [
                                'user_id' => $userId,
                                'shop_id' => $shop['_id']
                            ];
                            $this->di->getObjectManager()->get(\App\Amazon\Components\Shop\Hook::class)
                            ->updateWarehouseStatus($userId, $shop['_id'], 'active');
                            $shop['warehouses'][0]['status'] = 'active';
                            $this->di->getObjectManager()->get("\App\Amazon\Components\ShopDelete")
                            ->removeRegisterForOrderSync($preparedData);
                            unset($shop['register_for_order_sync']);
                            $this->syncUserToDynamo($shop);
                            $dataToSent = [
                                'user_id' => $userId,
                                'target_marketplace' => [
                                    'marketplace' => $shop['marketplace'],
                                    'shop_id' => $shop['_id']
                                ]
                            ];
                            $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                                ->createSubscription($dataToSent);
                            $this->setSourceStatus($userId, $shop, 'active');
                            $this->di->getLog()->logContent('Store reopen action completed successfully', 'info', $logFile);
                        }
                    }
                }

                return ['success' => true, 'message' => 'Process Completed'];
            }
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception from shopStatusUpdate(), Error: '.json_encode($e->getMessage()), 'info', $logFile);
        }

        return ['success' => false, 'message' => 'Required params (shops or status) missing'];
    }

    public function updateRegisterForOrderSync($userId, $shopId, $value): void {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userCollection = $mongo->getCollectionForTable('user_details');
        $userCollection->updateOne(
            ['user_id' => $userId, 'shops._id' => $shopId],
            ['$set' => ['shops.$.register_for_order_sync' => $value]]
        );
    }

    public function setSourceStatus($userId, $shop, $status)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userCollection = $mongo->getCollectionForTable('user_details');
            $userCollection->updateOne(
                ['user_id' => $userId, 'shops' => ['$elemMatch' => ['_id' => $shop['_id'], 'marketplace' => Helper::TARGET]]],
                ['$set' => ['shops.$.sources.0.status' => $status]]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from setSourceStatus(), Error: ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }

    public function updateOrderFetchKey($userId, $orderSyncEnabled)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollection('user_details');
        $userDetailsCollection->updateOne(
            [
                'user_id' => $userId
            ],
            ['$set' => ['order_fetch_enabled' => $orderSyncEnabled]],
            ['upsert' => true]
        );
    }
}