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

namespace App\Shopifyhome\Components\Shop;

use App\Shopifyhome\Components\Core\Common;
use App\Core\Models\User;
use Exception;

class Hook extends Common
{
    public function addAndUpdateLocation($data)
    {

        $target = $this->di->getObjectManager()
            ->get(Shop::class)->getUserMarkeplace();
        $userDetailsObj = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shopId = $data['shop_id'] ?? $userDetailsObj->getDataByUserID((string)$data['user_id'], $target)->_id;
        $warehouse = $this->di->getObjectManager()
            ->get(Utility::class)->locationNodeToExcludeAndFormatData($data['data']);
        $warehouseResponse = $userDetailsObj->addWarehouse($shopId, $warehouse, (string)$data['user_id'], ['id']);
        $this->di->getLog()->logContent('PROCESS 000020 | Hook | addLocation | Response = \n'
            . print_r($warehouseResponse, true), 'info', 'shopify' . DS . $this->di->getUser()->id
            . DS . 'locations' . DS . date("Y-m-d") . DS . 'webhook_locations.log');

        return $warehouseResponse;
    }

    public function deleteLocation($data)
    {
        if (isset($data['shop_id'], $data['data']['id'])) {
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $userDetails = $mongo->getCollection("user_details");
            $warehouse = $userDetails->aggregate([
                ['$unwind' => '$shops'],
                ['$unwind' => '$shops.warehouses'],
                [
                    '$match' => [
                        'shops.warehouses.id' => (string)$data['data']['id']
                    ]
                ],
                [
                    '$group' => [
                        '_id' => '$shops.warehouses._id'
                    ]
                ]
            ])->toArray();
            if (empty($warehouse)) {
                $this->di->getLog()->logContent('PROCESS X00030 | shop | Hook | deleteLocation | Location not found', 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'locations' . DS . date("Y-m-d") . DS . 'webhook_locations.log');
                return true;
            }

            $warehouse = json_decode(json_encode($warehouse), true);
            $warehouseId = (string)$warehouse[0]['_id'];
            $_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $warehouseResponse = $_user_details->deleteWarehouse($data['shop_id'], $warehouseId);
            $this->di->getLog()->logContent('PROCESS 000030 | shop | Hook | deleteLocation | Response = \n' . print_r($warehouseResponse, true), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'locations' . DS . date("Y-m-d") . DS . 'webhook_locations.log');
            if (isset($warehouseResponse['success']) && $warehouseResponse['success']) {
                $this->deleteLocationsFromProducts($data);
            }

            return $warehouseResponse;
        }
    }

    public function deleteLocationsFromProducts($params = [])
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $productContainer = $mongo->getCollection("product_container");
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $message = $this->di->getLocale()->_('shopify_unable_delete_location');
        $userId = $params['user_id'] ?? $this->di->getUser()->id;
        $shopId = $params['shop_id'];
        $locationId = (string) ($params['data']['id'] ?? $params['location_id']);
        $pipline = [
            [
                '$match' => [
                    'user_id' => $userId,
                    'shop_id' => $shopId,
                    'locations' => [
                        '$elemMatch' => [
                            'location_id' => $locationId
                        ]
                    ]
                ]
            ],
            ['$project' => ['_id' => 1, 'source_product_id' => 1]],
            ['$limit' => 500]
        ];
        $productData = $productContainer->aggregate($pipline, $arrayParams)->toArray();
        if (!empty($productData)) {
            $sourceProductIds = array_column($productData, 'source_product_id');
            $productContainer->updateMany(
                [
                    'user_id' => $userId,
                    'shop_id' => $shopId,
                    'source_product_id' => ['$in' => $sourceProductIds],
                    'locations' => [
                        '$elemMatch' => [
                            'location_id' => $locationId
                        ]
                    ]
                ],
                ['$pull' => ['locations' => ['location_id' => $locationId]]]
            );
            if (count($productData) < 500) {
                return ['success' => true, 'message' => 'Locations removed from all products'];
            }
            $handlerData = [
                'type' => 'full_class',
                'class_name' => \App\Shopifyhome\Components\Shop\Hook::class,
                'method' => 'deleteLocationsFromProducts',
                'queue_name' => 'delete_locations_from_products',
                'user_id' => $userId,
                'shop_id' => $shopId,
                'marketplace' => $params['marketplace'],
                'location_id' => $locationId
            ];
            $sqsHelper->pushMessage($handlerData);
        } else {
            $message = $this->di->getLocale()->_('shopify_unable_delete_location');
        }

        return ['success' => false, 'message' => $message];
    }

    public function shopUpdate($data)
    {
        try {
            $logFile = "shopify/shopUpdate/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('Shop update data: '.json_encode($data), 'info',  $logFile);
            $webhookData = $data['data'];
            $userDetails = $this->di->getObjectManager()->get('App\Core\Models\User\Details');
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $homeShopId = $data['shop_id'];
            $sourceShop = $userDetails->getShop($homeShopId, $userId);
            $storeClosePlans = ['cancelled', 'frozen', 'fraudulent'];
            $storeReopened = false;
            $storeClosed = false;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userCollection = $mongo->getCollectionForTable('user_details');
            if (!empty($sourceShop) && isset($sourceShop['_id'], $sourceShop['remote_shop_id'], $sourceShop['plan_name'])) {
                if (!in_array($webhookData['plan_name'], $storeClosePlans) && in_array($sourceShop['plan_name'], $storeClosePlans)) {
                    $storeReopened = true;
                    if (isset($sourceShop['store_locked_at'])) {
                        $webhookData['store_unlocked_at'] = date('c');
                    } else {
                        $webhookData['store_reopened_at'] = date('c');
                    }
                    if (isset($sourceShop['store_closed_at']) || isset($sourceShop['store_locked_at'])) {
                        $userCollection->updateOne(
                            ['user_id' => $userId, 'shops' => ['$elemMatch' => ['_id' => $sourceShop['_id']]]],
                            ['$unset' => ['shops.$.store_closed_at' => true, 'shops.$.store_locked_at' => true]]
                        );
                    }
                    // Validating GraphQL Webhooks
                    $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Webhook\Helper::class)
                        ->validateShopGraphQLWebhooks($sourceShop);

                    // Checking and saving publication id if not present
                    $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Shop\Shop::class)
                    ->fetchAndSavePublicationId(['user_id' => $this->di->getUser()->id,'shop' => $sourceShop]);

                    // Checking the access_scopes for shop
                    $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Shop\Shop::class)
                        ->checkAccessScopeAndSetReauthIfMissing($sourceShop, true);

                    } elseif (!in_array($sourceShop['plan_name'], $storeClosePlans) && in_array($webhookData['plan_name'], $storeClosePlans)) {
                        $storeClosed = true;
                        $webhookData['store_closed_at'] = date('c');
                        if (isset($sourceShop['store_reopened_at'])) {
                            $userCollection->updateOne(
                                ['user_id' => $userId, 'shops' => ['$elemMatch' => ['_id' => $sourceShop['_id']]]],
                                ['$unset' => ['shops.$.store_reopened_at' => true]]
                            );
                        }
                        $this->sendStoreCloseMail($userId);
                }
                $webhookData['_id'] = $sourceShop['_id'];
                $response = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->addShop($webhookData, $userId, ['_id']);
                if (isset($response['success']) && $response['success']) {
                    if ($storeReopened) {
                        $this->notifyTargets($data['marketplace'], 'reopened');
                        $this->di->getLog()->logContent('Notified targets as store reopened', 'info',  $logFile);
                    }
                    if ($storeClosed) {
                        $this->notifyTargets($data['marketplace'], 'closed');
                        $this->di->getLog()->logContent('Notified targets as store closed', 'info',  $logFile);
                    }

                    return $response;
                } else {
                    $this->di->getLog()->logContent('Error response from addShop: '.json_encode($response), 'info',  $logFile);
                }
            }

            return ['success' => false, 'message' => 'Unable to update ShopDetails'];
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception from shopUpdate(): '.json_encode($e->getMessage()), 'info',  'exception.log');
        }
    }
    
    public function notifyTargets($marketplace, $status)
    {
        $shops = $this->di->getUser()->shops;
        if (!empty($shops) && count($shops) > 1) {
            $dataToSent = [
                'user_id' => $this->di->getUser()->id,
                'shops' => $shops,
                'status' => $status,
                'marketplace' => $marketplace
            ];
            $eventsManager = $this->di->getEventsManager();
            $eventsManager->fire(
                "application:shopStatusUpdate",
                $this,
                $dataToSent
            );
        }

        return ['success' => true, 'message' => 'Process initiated!!'];
    }

    public function checkStoreStatus($params = [])
    {
        $userId = $this->di->getUser()->id ?? $params['user_id'];
        $sourceShopId = $this->di->getRequester()->getSourceId()  ?? $params['source_shop_id'];
        $sourceMarketplace = $this->di->getRequester()->getSourceName()  ?? $params['source_marketplace'];
        $sourceShop = $this->di->getObjectManager()->get('App\Core\Models\User\Details')->getShop($sourceShopId, $userId);
        if (empty($sourceShop)) {
            return ['success' => false, 'message' => 'Unable to fetch shop data'];
        }
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($sourceMarketplace, true)
            ->call('/shop', [], ['shop_id' => $sourceShop['remote_shop_id']], 'GET');
        if (isset($remoteResponse['success']) && $remoteResponse['success'] && !empty($remoteResponse['data'])) {
            $handlerData = [
                'type' => 'full_class',
                'class_name' => \App\Shopifyhome\Components\Shop\Hook::class,
                'method' => 'shopUpdate',
                'queue_name' => 'shop_update',
                'user_id' => $userId,
                'shop_id' => $sourceShopId,
                'marketplace' => $sourceMarketplace,
                'data' => $remoteResponse['data']
            ];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $query = $mongo->getCollectionForTable('user_details');
            $query->updateOne(
                ['user_id' => $userId, 'shops' => ['$elemMatch' => ['_id' => $sourceShopId]]],
                ['$unset' => ['shops.$.store_closed_at' => true]]
            );
            $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')
                ->pushMessage($handlerData);
            return ['success' => true, 'message' => 'Your store is now active. Our services will resume shortly.'];
        }

        return ['success' => false, 'message' => 'Your store is still closed or plan is inactive.'];
    }

    public function sendStoreCloseMail($userId)
    {
        try {
                $response = $this->setDiForUser($userId);
                if(isset($response['success']) && $response['success']) {
                    $userName = $this->di->getUser()->username;
                    $marketplace = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
                    $mailData = [
                        'name' => $userName,
                        'email' => $this->di->getUser()->source_email ?? $this->di->getUser()->email,
                        'app_name' => $this->di->getConfig()->app_name,
                        'subject' => 'Attention! App Services Suspended',
                        'path' => $marketplace.'home' . DS . 'view' . DS . 'email' . DS . 'store_close.volt'
                    ];
                    $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($mailData);
                }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from sendStoreCloseMail(), Error:' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }
    }

    public function setDiForUser($userId)
    {
        try {
            if ($this->di->getUser()->id === $userId) {
                return [
                    'success' => true,
                    'message' => 'Di already set'
                ];
            }
            $getUser = User::findFirst([['_id' => $userId]]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found'
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
