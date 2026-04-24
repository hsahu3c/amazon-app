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

use App\Shopifyhome\Components\Helper;
use App\Shopifyhome\Components\Core\Common;
use App\Connector\Components\ApiClient;
use App\Connector\Components\Hook;
use App\Core\Models\User;
use Exception;

class Shop extends Common
{
    public const UNIQUE_SHOP_IDENTIFIER = 'id';

    public const UNIQUE_WAREHOUSE_IDENTIFIER = 'id';

    public const MARKETPLACE = 'shopify';

    public function addShopToUser($data = [], $remoteshopId = 0)
    {
        if (empty($data)) {
            $target = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Shop\Shop::class)->getUserMarkeplace();
            $data = $this->di->getObjectManager()->get('\App\Connector\Components\ApiClient')->init($target, true)->call('/shop', [], [
                'shop_id' => $remoteshopId,
            ]);
            if (isset($data['errors']) || is_null($data) || empty($data)) {
                return [
                    'success' => false,
                    'message' => $data['errors'] ?? 'Error fetching data from Shopify, Please try again later or contact our next available support member.',
                ];
            }
        }

        $shopData = $this->di->getObjectManager()->get(Utility::class)->shopNodeToExcludeAndFormatData($data['data']);
        if ($shopData['marketplace'] == "shopify") {
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('shopify', 'true')
                ->call('/savehomeadminid', [], ['shop_id' => $data['data']['remote_shop_id'], 'admin_home_id' => $this->di->getUser()->id], 'POST');
        }

        $this->di->getLog()->logContent('shop_data =' . print_r($shopData, true), 'info', 'user_login.log');

        if (!isset($shopData['marketplace'])) {
            $shopData['marketplace'] = Shop::MARKETPLACE;
        }

        $shopCollection = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $shopRes = $shopCollection->addShop($shopData, false, [Shop::UNIQUE_SHOP_IDENTIFIER]);

        if ($shopData['marketplace'] == "shopify_vendor") {
            $mongoCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $mongoCollection->setSource('user_details');
            $phpMongoCollection = $mongoCollection->getPhpCollection();
            $adminuser = $phpMongoCollection->findOne(['shops.0.marketplace' => 'shopify']);
            if (isset($adminuser['user_id'])) {
                $phpMongoCollection->updateOne(['shops.0._id' => $shopRes['data']['shop_id']], ['$set' => ['shops.0.admin_home_id' => $adminuser['user_id']]]);
            }
        }

        $this->di->getLog()->logContent('shop_res =' . print_r($shopRes, true), 'info', 'user_login.log');
        if ($shopRes['success']) {
            return [
                'success' => true,
                'home_internal_shop' => $shopRes['data']['shop_id'],
                'message' => 'New Shop has been successfully added.',
            ];
        }
        return $shopRes;

    }

    /*
    $remoteshopId : not required during initial authentication
     */
    public function addWarehouseToUser($data,&$shopData,$appCode='default')
    {
        $locationResponse = $this->di->getObjectManager()->get('\App\Connector\Components\ApiClient')->init($data['data']['data']['marketplace'], true,$appCode)->call('/shop/location', [], [
            'call_type' => '',
            'shop_id' => $data['data']['data']['shop_id'],
        ]);


        if (isset($locationResponse['errors']) || is_null($locationResponse) || empty($locationResponse)) {
            return [
                'success' => false,
                'message' => $locationResponse['errors'] ?? 'Error fetching data from Shopify, Please try again later or contact our next available support member.',

            ];
        }

        if (isset($locationResponse['success']) && count($locationResponse['data'])>0){
            $shopData['warehouses'] = [];
            foreach ($locationResponse['data'] as $warehouse){
                $warehouse = $this->di->getObjectManager()->get(Utility::class)->locationNodeToExcludeAndFormatData($warehouse);

                $shopData['warehouses'][] = $warehouse;
            }
        }
    }

    public function getUserMarkeplace()
    {
        return "shopify";

        $mId = (string) $this->di->getUser()->id;

        $mongoCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection->setSource('user_details');

        $phpMongoCollection = $mongoCollection->getPhpCollection();

        $options = [];

        $pipeline = [
            [
                '$match' => [
                    "user_id" => $mId,
                ],
            ],
            [
                '$project' => [
                    'marketplace' => [
                        '$arrayElemAt' => [
                            '$shops.marketplace',
                            0,
                        ],
                    ],
                ],
            ],
        ];
        $userData = $phpMongoCollection->aggregate($pipeline, $options)->toArray();

        if (isset($userData[0]['marketplace'])) {
            return $userData[0]['marketplace'];
        }
    }

    public function matchMarketplaceWithRemoteSubAppId($remoteShopId)
    {
        $marketplace[$this->di->getConfig()->apiconnector->shopify->sub_app_id] = 'shopify';
        $marketplace[$this->di->getConfig()->apiconnector->shopify_ig->sub_app_id] = 'shopify_ig';

        $endMarket = $marketplace[$remoteShopId];
        return ($endMarket == 'shopify_ig') ? 'shopify_ig' : 'shopify';
    }

    public function getShopDetailByUserID($user_id = false, $marketplace = false)
    {
        if (!$user_id) {
            $user_id = $this->di->getUser()->id;
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("user_details")->getCollection();
        $user_details = $collection->findOne(["user_id" => $user_id]);

        if (empty($user_details)) {
            return ['success' => false, 'message' => 'Shop details not found'];
        }

        if ($marketplace) {
            foreach ($user_details['shops'] as $value) {
                if ($value['marketplace'] === $marketplace) {
                    return $value;
                }
            }
        }

        return [];
    }

    /**
     * function used to get scopes stored on remote collection: apps
     *
     * @param array $postData
     * @return array
     */
    public function getScopesFromApp($postData)
    {
        $apiConnector = $this->di->getConfig()->get('apiconnector');
        $marketplace = $postData['marketplace'] ?? $this->di->getRequester()->getSourceName();
        $appCode = $postData['appCode'] ?? $this->di->getAppCode()->get()[$marketplace] ?? false;
        $sAppId = !empty($apiConnector->$marketplace[$appCode]) ?
            $apiConnector->$marketplace[$appCode]['sub_app_id'] : false;

        if (empty($marketplace) || empty($appCode) || empty($sAppId)) {
            $response = [
                'success' => false,
                'message' => 'Required data: `source_marketplace` or `appCode` is missing'
            ];
        } else {
            $response = $this->di->getObjectManager()->get(ApiClient::class)
                ->init($marketplace, true, $appCode)
                ->call('/app', [], ['sAppId' => $sAppId], 'GET');

            if (empty($response['success'])) {
                $this->di->getLog()->logContent(
                    json_encode(['app_config_response' => $response]),
                    'info',
                    'shopify/auth/' . $this->di->getUser()->user_id . '/scopes.log'
                );
                $response = [
                    'success' => false,
                    'message' => 'Unable to fetch scopes from Remote endpoint. Kindly check logs.'
                ];
            } else {
                $appConfig = $response['app_config'] ?? [];
                $scopes = $appConfig['live']['scope'] ?? [];
                $response = [
                    'success' => true,
                    'data' => (explode(',', (string) $scopes)) ?? [],
                ];
            }
        }

        return $response;
    }

    public function appUninstall($params = [])
    {
        $userId = $this->di->getUser()->id;
        $sourceShopId = $this->di->getRequester()->getSourceId();
        $shops = $this->di->getUser()->shops;
        $shopData = [];
        if (!empty($shops)) {
            $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
            foreach ($shops as $shop) {
                if ($shop['_id'] == $sourceShopId) {
                    $shopData = $shop;
                    break;
                }
            }

            $appCode = $shopData['apps'][0]['code'] ?? 'default';
            $remoteShopId = $shopData['remote_shop_id'];
            $requestParams = [
                'shop_id' => $remoteShopId,
                'app_code' => $appCode
            ];
            $dataToSent = [
                'user_id' => $userId,
                'marketplace' => self::MARKETPLACE,
                'shop' => $shopData
            ];
            $this->di->getEventsManager()->fire(
                "application:beforeAppUninstall",
                $this,
                $dataToSent
            );
            $response = $shopifyHelper->sendRequestToShopify('app/remove', [], $requestParams, 'DELETE');
            if (isset($response['success']) && $response['success']) {
                $reason = !empty($params['reason']) ? $params['reason'] : 'Reason Not Defined';
                $feedback = !empty($params['feedback']) ? $params['feedback'] : 'Not Mentioned';
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $uninstallUserDetailsCollection = $mongo->getCollectionForTable('uninstall_user_details');
                $uninstallUserDetailsCollection->updateOne(
                    [
                        'user_id' => $userId,
                        'username' => $shopData['username']
                    ],
                    [
                        '$set' => [
                            'user_id' => $userId,
                            'username' => $shopData['username'],
                            'email' => $shopData['email'],
                            'reason' => $reason,
                            'feedback' => $feedback
                        ]
                    ],
                    ['upsert' => true]
                );
                return ['success' => true, 'message' => 'App Uninstalled Successfully!!'];
            }
        }

        return ['success' => false, 'message' => 'Unable to Uninstall'];
    }

    /**
     * eraseShopData function
     * Earsing completed shop data from DB
     * when receving shop/redact webhook
     * @param [array] $data
     * @return array
     */
    public function eraseShopData($data = [])
    {
        $logFile = "shopify/shop_redact/" . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Shop redact data: '.json_encode($data), 'info',  $logFile);
        if (isset($data['shop'])) {
            $userId = $this->di->getUser()->id;
            $shops = $this->di->getUser()->shops ?? [];
            $shopData = [];
            $targetShopIds = [];
            if (!empty($shops)) {
                foreach ($shops as $shop) {
                    if ($shop['_id'] == $data['shop_id']) {
                        $shopData = $shop;
                        break;
                    }
                }
                if (!empty($shopData)) {
                    $dataToSend['shop'] = $shopData;
                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => '\App\Connector\Components\Hook',
                        'method' => 'shopEraser',
                        'queue_name' => 'uninstall_shop_eraser',
                        'data' => $dataToSend,
                        'user_id' => $userId,
                        'shop_id' => $shopData['_id'],
                        'marketplace' => $shopData['marketplace'],
                        'app_code' => $data['app_code']
                    ];
                    // $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')
                        // ->pushMessage($handlerData);
                    $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\SqsS3Publisher')
                        ->pushMessage($handlerData);
                    if (!empty($shopData['targets'])) {
                        $targetShopIds = array_column($shopData['targets'], 'shop_id');
                        foreach ($shops as $shop) {
                            if (in_array($shop['_id'], $targetShopIds)) {
                                $appCode = $shop['apps'][0]['code'] ?? 'default';
                                if (!empty($shop['sources']) && count($shop['sources']) == 1) {
                                    $sourceShopId = $shop['sources'][0]['shop_id'];
                                    if ($sourceShopId == $shopData['_id']) {
                                        $dataToSend['shop'] = $shop;
                                        $handlerData = [
                                            'type' => 'full_class',
                                            'class_name' => '\App\Connector\Components\Hook',
                                            'method' => 'shopEraser',
                                            'queue_name' => 'uninstall_shop_eraser',
                                            'data' => $dataToSend,
                                            'user_id' => $userId,
                                            'shop_id' => $shop['_id'],
                                            'marketplace' => $shop['marketplace'],
                                            'app_code' => $appCode
                                        ];
                                        $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')
                                            ->pushMessage($handlerData);
                                    } else {
                                        $this->di->getLog()->logContent('Target not connected with this source:  ' . json_encode([
                                            'source' => $shopData,
                                            'target' => $shop
                                        ]), 'info', $logFile);
                                    }
                                }
                            }
                        }
                    }

                    return ['success' => true, 'message' => 'Process Completed'];
                } else {
                    $message = 'Shop not matched';
                }
            }
        } else {
            $message = 'Shop not found in data';
        }
        $this->di->getLog()->logContent('Error Occured:  ' . json_encode($message ?? 'Something went wrong'), 'info', $logFile);
        return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
    }

    public function fetchAndUpdateStoreCloseUsers()
    {
        $logFile = "shopify/script/" . date('d-m-Y') . '.log';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsContainer = $mongo->getCollectionForTable('user_details');
        $options = [
            "projection" => ['_id' => 0, 'user_id' => 1, 'shops' => 1, 'username' => 1],
            "typeMap" => ['root' => 'array', 'document' => 'array'],
            "limit" => 500
        ];
        $storeClosedUsers = $userDetailsContainer->find(
            [
                'shops' => [
                    '$elemMatch' => [
                        'marketplace' => self::MARKETPLACE,
                        'plan_name' => [
                            '$in' => ['frozen', 'cancelled', 'dormant', 'fraudulent']
                        ],
                        'store_closed_at' => [
                            '$exists' => false
                        ],
                        'store_reopened_at' => [
                            '$exists' => false
                        ]
                    ]
                ],
                'shops.1' => [
                    '$exists' => true
                ]
            ],
            $options
        )->toArray();
        $eventsManager = $this->di->getEventsManager();
        $filter = [];
        if (!empty($storeClosedUsers)) {
            foreach ($storeClosedUsers as $user) {
                $storeClosedAt = date('c');
                $userId = $user['user_id'];
                $this->di->getLog()->logContent('Starting Process for User: ' . json_encode($userId), 'info',  $logFile);
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    foreach ($user['shops'] as $shop) {
                        if ($shop['marketplace'] == self::MARKETPLACE) {
                            $lastUpdatedAt =  $shop['updated_at'];
                            $dateTime = $lastUpdatedAt->toDateTime();
                            $storeClosedAt = $dateTime->format('Y-m-d\TH:i:s.vP');
                            $filter[] =
                                [
                                    'updateOne' => [
                                        [
                                            'user_id' => $userId,
                                            'shops' => [
                                                '$elemMatch' => [
                                                    'marketplace' => self::MARKETPLACE,
                                                    '_id' => $shop['_id']
                                                ]
                                            ]
                                        ],
                                        ['$set' => ["shops.$.store_closed_at" => (string)$storeClosedAt]],
                                    ],
                                ];
                            $dataToSent = [
                                'user_id' => $userId,
                                'marketplace' => self::MARKETPLACE,
                                'status' => 'closed',
                                'shops' => $user['shops']
                            ];
                            $eventsManager->fire(
                                "application:shopStatusUpdate",
                                $this,
                                $dataToSent
                            );
                            $this->di->getLog()->logContent('Event fired successfully', 'info',  $logFile);
                            break;
                        }
                    }
                } else {
                    $this->di->getLog()->logContent('Unable to setDi for user: ' . json_encode($userId), 'info',  $logFile);
                }
            }
            if (!empty($filter)) {
                $userDetailsContainer->bulkWrite($filter);
            }
        } else {
            $this->di->getLog()->logContent('All users processed', 'info',  $logFile);
        }
        return ['success' => true, 'message' => 'Script completed'];
    }

    public function setDiForUser($userId)
    {
        try {
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

    public function checkAndSetStoreClosedAtForUnavailableShop($data)
    {
        try {
            $logFile = "shopify/script/" . date('d-m-Y') . '.log';
            if (isset($data['user_id'], $data['username'])) {
                $shops = $this->di->getUser()->shops;
                $shopData = [];
                if (!empty($shops)) {
                    foreach ($shops as $shop) {
                        if ($shop['marketplace'] == self::MARKETPLACE) {
                            $shopData = $shop;
                            break;
                        }
                    }
                    if (!empty($shopData['remote_shop_id'])) {
                        $remoteData = ['shop_id' => $shopData['remote_shop_id'], 'call_type' => 'QL'];
                        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                            ->init(self::MARKETPLACE, true)
                            ->call('/shop', [], $remoteData, 'GET');
                        if (isset($remoteResponse['success']) && !$remoteResponse['success']) {
                            $message = $remoteResponse['msg'] ?? $remoteResponse['message'] ?? '';
                            if ($message == 'Unavailable Shop') {
                                $this->di->getLog()->logContent('Unavailable shop found for user:' . json_encode([
                                    'user_id' => $data['user_id'],
                                    'username' => $data['username']
                                ]), 'info', $logFile);
                                    $storeClosedAt = date('c');
                                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                                    $userDetails = $mongo->getCollectionForTable('user_details');
                                    $userDetails->updateOne(
                                        [
                                            'user_id' => $data['user_id'],
                                            'shops' => [
                                                '$elemMatch' => [
                                                    'marketplace' => self::MARKETPLACE,
                                                    '_id' => $shopData['_id']
                                                ]
                                            ]
                                        ],
                                        [
                                            '$set' => [
                                                'shops.$.store_closed_at' => (string)$storeClosedAt,
                                                'shops.$.plan_name' => 'cancelled'
                                            ]
                                        ]
                                    );
                            }
                        }
                        return ['success' => true, 'message' => 'Process completed'];
                    } else {
                        $message = 'Remote shop not found';
                    }
                } else {
                    $message = 'Unable to fetch shops';
                }
            } else {
                $message = 'Required params missing(user_id, username)';
            }
            $this->di->getLog()->logContent('Error occured:' . json_encode([
                'data' => $data,
                'error' => $message ?? 'Something went wrong'
            ]), 'info', $logFile);
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from checkUnavailableShopUser():' . json_encode($e->getMessage()), 'info', $logFile ?? 'exception.log');
        }
    }

    public function fetchAndSavePublicationId($data)
    {
        try {
            if(!empty($data['shop'])) {
                $shop = $data['shop'];
                $logFile = "shopify/fetchAndSavePublicationId/" . date('d-m-Y') . '.log';
                $this->di->getLog()->logContent('Starting process for userId: '.json_encode($data['user_id'] ?? $this->di->getUser()->id), 'info',  $logFile);
                $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init($shop['marketplace'], true)
                    ->call('/shop/save/publication', [], ['shop_id' => $shop['remote_shop_id'], 'call_type' => 'QL'], 'GET');
                if(isset($remoteResponse['success']) && $remoteResponse['success']) {
                    $this->di->getLog()->logContent('PublicationId saved successfully', 'info',  $logFile);
                    return $remoteResponse;
                } else {
                    $message = 'Unable to fetch and save publicationId';
                    $this->di->getLog()->logContent('Unable to fetch and save publicationId, data: '.json_encode([
                        'shop' => $shop,
                        'remote_response' => $remoteResponse
                    ]), 'info',  $logFile);
                }
            } else {
                $message = 'Required params missing(shop)';
            }
            return ['success' => false, 'message' => $message, 'data' => $remoteResponse ?? []];
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception from fetchAndSavePublicationId(): '.json_encode($e->getMessage()), 'info',  $logFile ?? 'exception.log');
        }
    }

    public function upgradeOrDowngradePlanTier($data)
    {
        try {
            $logFile = "shopify/upgradeOrDowngradePlanTier/" . date('d-m-Y') . '.log';
            if(!empty($data['user_id']) && !empty($data['plan_tier'])) {
                $this->di->getLog()->logContent('Starting process for data: '.json_encode($data), 'info',  $logFile);
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => '\App\Shopifyhome\Components\Webhook\Helper',
                    'method' => 'updateShopifyWebhookAddressByPlanTier',
                    'queue_name' => 'shopify_update_webhook_address',
                    'user_id' => $data['user_id'],
                    'data' => [
                        'plan_tier' => $data['plan_tier']
                    ]
                ];
                $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')
                    ->pushMessage($handlerData);
                $this->di->getLog()->logContent('Message pushed handlerData: '.json_encode($handlerData), 'info',  $logFile);
                return ['success' => true, 'message' => 'Updated webhook address by plan tier process initiated'];
            }
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception from upgradeOrDowngradePlanTier(): '.json_encode($e->getMessage()), 'info',  $logFile ?? 'exception.log');
            throw $e;
        }
    }

    public function checkAndUninstallTokenExpiredUsers($data)
    {
        try {
            $logFile = "shopify/script/" . date('d-m-Y') . '.log';
            if (isset($data['user_id'], $data['username'])) {
                $shops = $this->di->getUser()->shops;
                $shopData = [];
                if (!empty($shops)) {
                    foreach ($shops as $shop) {
                        if ($shop['marketplace'] == self::MARKETPLACE) {
                            $shopData = $shop;
                            break;
                        }
                    }
                    if (!empty($shopData['remote_shop_id'])) {
                        $remoteData = ['shop_id' => $shopData['remote_shop_id'], 'call_type' => 'QL'];
                        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                            ->init(self::MARKETPLACE, true)
                            ->call('/shop', [], $remoteData, 'GET');
                        if (isset($remoteResponse['success']) && !$remoteResponse['success']) {
                            $message = $remoteResponse['msg'] ?? $remoteResponse['message'] ?? '';
                            if (is_string($message) && str_contains($message, 'Invalid API key or access token')) {
                                $this->di->getLog()->logContent('Invalid API key or access token shop found for user:' . json_encode([
                                    'user_id' => $data['user_id'],
                                    'username' => $data['username']
                                ]), 'info', $logFile);
                                $preparedData = [
                                    'shop' => $data['username'],
                                    'data' => $shopData,
                                    'user_id' => $data['user_id'],
                                    'app_code' => $shopData['apps'][0]['code'],
                                ];
                                $object = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Shop\StoreClose');
                                $object->startUninstallationProcess($preparedData);
                            }
                        }
                        return ['success' => true, 'message' => 'Process completed'];
                    } else {
                        $message = 'Remote shop not found';
                    }
                } else {
                    $message = 'Unable to fetch shops';
                }
            } else {
                $message = 'Required params missing(user_id, username)';
            }
            $this->di->getLog()->logContent('Error occured:' . json_encode([
                'data' => $data,
                'error' => $message ?? 'Something went wrong'
            ]), 'info', $logFile);
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from checkAndUninstallTokenExpiredUsers():' . json_encode($e->getMessage()), 'info', $logFile ?? 'exception.log');
        }
        return ['success' => false, 'message' => 'Something went wrong'];
    }

    public function checkAccessScopesForShop($data)
    {
        $logFile = "shopify/script/checkAccessScopesForShop/" . date('d-m-Y') . '.log';
        $message = '';
        
        if (isset($data['user_id'], $data['username'])) {
            try {
                $shops = $this->di->getUser()->shops;
                $shopData = [];
                if (!empty($shops)) {
                    foreach ($shops as $shop) {
                        if (isset($shop['marketplace']) && $shop['marketplace'] == $data['marketplace']) {
                            $shopData = $shop;
                            break;
                        }
                    }
                } else {
                    $message = 'Shop not found from di shops';
                }
                
                if (!empty($shopData)) {
                    // Check if store_closed_at key is set
                    if (isset($shopData['store_closed_at'])) {
                        // Set reauth_required: true at shop level and continue
                        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                        $userDetailsCollection = $mongo->getCollectionForTable('user_details');
                        $userDetailsCollection->updateOne(
                            [
                                'user_id' => $data['user_id'],
                                'shops' => [
                                    '$elemMatch' => [
                                        'marketplace' => $data['marketplace'],
                                        '_id' => $shopData['_id']
                                    ]
                                ]
                            ],
                            [
                                '$set' => ['shops.$.reauth_required' => true]
                            ]
                        );
                        $this->di->getLog()->logContent('Reauth key added for user (store_closed_at exists): ' . json_encode([
                            'user_id' => $data['user_id'],
                            'username' => $data['username']
                        ]), 'info', $logFile);
                        return ['success' => true, 'message' => 'Reauth required set due to store_closed_at'];
                    }

                    // If store_closed_at is not set, check access scopes via remote API
                    if (!empty($shopData['remote_shop_id'])) {
                        $response =$this->checkAccessScopeAndSetReauthIfMissing($shopData, true);
                        if (isset($response['success']) && $response['success']) {
                            if(isset($response['message']) && $response['message'] == 'Reauth required due to missing scopes') {
                                $this->di->getLog()->logContent('Reauth required set due to missing scopes'. json_encode(['user_id' => $data['user_id'], 'username' => $data['username']]), 'info', $logFile);
                                return ['success' => true, 'message' => 'Reauth required due to missing scopes'];
                            } elseif(isset($response['message']) && $response['message'] == 'All access scopes present') {
                                $this->di->getLog()->logContent('All access scopes present'. json_encode(['user_id' => $data['user_id'], 'username' => $data['username']]), 'info', $logFile);
                                return ['success' => true, 'message' => 'All access scopes present'];
                            } else {
                                $this->di->getLog()->logContent('Something went wrong'. json_encode(['user_id' => $data['user_id'], 'username' => $data['username'], 'response' => $response]), 'info', $logFile);
                            }
                        } else {
                            return ['success' => false, 'message' => $response['message'] ?? 'Something went wrong'];
                        }
                    } else {
                        $message = 'Remote shop ID not found';
                    }
                } else {
                    $message = 'Shop not found for marketplace: ' . ($data['marketplace'] ?? 'unknown');
                }
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Exception in checkAccessScopesForShop: ' . $e->getMessage(), 'info', $logFile);
                $message = 'Exception: ' . $e->getMessage();
            }
        } else {
            $message = 'Required params missing(user_id, username)';
        }

        $this->di->getLog()->logContent('Error in checkAccessScopesForShop: ' . json_encode([
            'data' => $data,
            'error' => $message ?? 'Something went wrong'
        ]), 'info', $logFile);
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    /**
     * Check access scopes for a shop and set reauth_required if scopes are missing
     * This function can be reused for other cases as well
     *
     * @param array $shopData Shop data array
     * @return array Response with success status and message
     */
    public function checkAccessScopeAndSetReauthIfMissing($shopData, $sendMail = false)
    {
        $logFile = "shopify/shopUpdate/reauth_required/" . date('Y-m-d') . '.log';

        try {
            if (empty($shopData['remote_shop_id'])) {
                $this->di->getLog()->logContent('Remote shop ID not found for user_id: ' . json_encode(['user_id' => $this->di->getUser()->id, 'shop_data' => $shopData]), 'info', $logFile);
                return [
                    'success' => false,
                    'message' => 'Remote shop ID not found'
                ];
            }

            $userId = $this->di->getUser()->id;
            $marketplace = $shopData['marketplace'] ?? self::MARKETPLACE;

            $appCode = $shopData['apps'][0]['code'] ?? 'default';
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init(self::MARKETPLACE, true, $appCode)
                ->call('shop/access_scopes', [], ['shop_id' => $shopData['remote_shop_id']], 'POST');

            if (isset($remoteResponse['success']) && !$remoteResponse['success']) {
                $responseMessage = $remoteResponse['message'] ?? $remoteResponse['msg'] ?? '';
                if (isset($responseMessage) && is_string($responseMessage) && str_contains($responseMessage, 'Not enough scopes')) {
                    // Set reauth_required: true
                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $userDetailsCollection = $mongo->getCollectionForTable('user_details');
                    $userDetailsCollection->updateOne(
                        [
                            'user_id' => $userId,
                            'shops' => [
                                '$elemMatch' => [
                                    '_id' => $shopData['_id'],
                                    'marketplace' => $marketplace
                                ]
                            ]
                        ],
                        [
                            '$set' => ['shops.$.reauth_required' => true]
                        ]
                    );
                    $this->di->getLog()->logContent('Reauth key added for user_id: ' . json_encode($userId), 'info', $logFile);
                    if($sendMail) {
                        $this->sendMailForReauthRequired($shopData);
                    }
                    return ['success' => true, 'message' => 'Reauth required due to missing scopes'];
                }
            } elseif (isset($remoteResponse['success']) && $remoteResponse['success']) {
                $this->di->getLog()->logContent('All access scopes present for user_id: ' . json_encode($userId), 'info', $logFile);
                return ['success' => true, 'message' => 'All access scopes present'];
            } else {
                return [
                    'success' => false,
                    'message' => 'Unable to fetch access scopes'
                ];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in checkAccessScopeAndSetReauthIfMissing: ' . $e->getMessage(), 'info', $logFile);
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send mail for reauthorization required
     * This function checks if mail was sent within last 2 days and sends mail if needed
     *
     * @param array $shopData Shop data array
     * @return array Response with success status and message
     */
    public function sendMailForReauthRequired($shopData)
    {
        $logFile = "shopify/shopUpdate/reauth_required/" . date('Y-m-d') . '.log';

        try {
            // Check if last_reauth_mail_send_at exists and is less than 2 days old
            if (isset($shopData['last_reauth_mail_send_at'])) {
                $lastMailDate = $shopData['last_reauth_mail_send_at'];
                $lastMailTimestamp = strtotime($lastMailDate);
                $twoDaysAgo = strtotime('-2 days');
                if ($lastMailTimestamp > $twoDaysAgo) {
                    return [
                        'success' => true,
                        'message' => 'Mail already sent within 2 days'
                    ];
                }
            }

            $userId = $this->di->getUser()->id;
            $username = $this->di->getUser()->getConfig()['username'] ?? '';
            $email = $this->di->getUser()->source_email ?? $this->di->getUser()->email ?? '';

            if (empty($username) || empty($email)) {
                return [
                    'success' => false,
                    'message' => 'Username or email not found'
                ];
            }

            $source = $shopData['marketplace'] ?? self::MARKETPLACE;
            $appCode = $shopData['apps'][0]['code'] ?? 'default';

            $configData = $this->di->getConfig()->get('apiconnector') ? $this->di->getConfig()->get('apiconnector')->toArray() : [];
            $appPath = '';
            if (!empty($configData) && isset($configData[$source][$appCode])) {
                $appName = $source . '_base_path';
                $appPath = $configData[$source][$appCode][$appName] ?? '';
            }

            $storeName = str_replace('.myshopify.com', '', $username);

            $redirectUri = "https://admin.{$source}.com/store/{$storeName}/{$appPath}/panel/user/dashboard";

            $msgData = [
                'app_name' => $this->di->getConfig()->app_name,
                'name' => $username,
                'email' => $email,
                'subject' => 'Action Needed: Update App Permissions',
                'redirect_uri' => $redirectUri
            ];

            $msgData['path'] = 'shopifyhome' . DS . 'view' . DS . 'email' . DS . 'reauthorization.volt';
            $mailResponse = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($msgData);
            if ($mailResponse) {
                $this->di->getLog()->logContent('Mail sent successfully for user_id: ' . json_encode($userId), 'info', $logFile);

                // Set last_reauth_mail_send_at
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $userDetailsCollection = $mongo->getCollectionForTable('user_details');
                $userDetailsCollection->updateOne(
                    [
                        'user_id' => $userId,
                        'shops' => [
                            '$elemMatch' => [
                                '_id' => $shopData['_id'],
                                'marketplace' => $source
                            ]
                        ]
                    ],
                    [
                        '$set' => ['shops.$.last_reauth_mail_send_at' => date('c')]
                    ]
                );

                return [
                    'success' => true,
                    'message' => 'Mail sent successfully'
                ];
            } else {
                $this->di->getLog()->logContent('Error while sending mail for user_id: ' . json_encode($userId) . ' - Response: ' . json_encode($mailResponse), 'info', $logFile);
                return [
                    'success' => false,
                    'message' => 'Unable to send mail'
                ];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in sendMailForReauthRequired: ' . $e->getMessage(), 'info', $logFile);
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    public function apiFailedStatusCodes($event, $component, $data)
    {
        if (($data['marketplace'] ?? '') !== 'shopify') {
            return;
        }
        $statusCode = $data['status_code'] ?? null;
        switch ($statusCode) {
            case 423:
                $this->shop423StatusCodeHandling();
                break;
            default:
                break;
        }
    }

    private function shop423StatusCodeHandling()
    {
        try {
            $logFile = 'shopify/shop423StatusCodeHandling/' . date('d-m-Y') . '.log';
            $userId = $this->di->getUser()->id;
            $shops = $this->di->getUser()->shops ?? [];

            if (empty($shops)) {
                return;
            }

            $sourceShop = null;
            foreach ($shops as $shop) {
                if (($shop['marketplace'] ?? '') === self::MARKETPLACE) {
                    $sourceShop = $shop;
                    break;
                }
            }

            if (empty($sourceShop)) {
                return;
            }
            if (isset($sourceShop['store_locked_at'])) {
                $this->di->getLog()->logContent(
                    'store_locked_at already set for user_id: ' . $userId,
                    'info',
                    $logFile
                );
                return;
            }

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userCollection = $mongo->getCollectionForTable('user_details');

            $userCollection->updateOne(
                [
                    'user_id' => $userId,
                    'shops' => ['$elemMatch' => ['_id' => $sourceShop['_id']]]
                ],
                [
                    '$set' => [
                        'shops.$.store_closed_at' => date('c'),
                        'shops.$.store_locked_at' => date('c'),
                        'shops.$.plan_name' => 'frozen',
                    ]
                ]
            );

            $this->di->getLog()->logContent(
                'Set store_locked_at, store_closed_at, plan_name=frozen for user_id: ' . $userId . ', shop_id: ' . $sourceShop['_id'],
                'info',
                $logFile
            );
        } catch (\Exception $e) {
            $this->di->getLog()->logContent(
                'Exception in shop423StatusCodeHandling: ' . $e->getMessage(),
                'info',
                'shopify/shop423StatusCodeHandling/exception.log'
            );
        }
    }
}
