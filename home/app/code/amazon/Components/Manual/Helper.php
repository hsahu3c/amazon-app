<?php

namespace App\Amazon\Components\Manual;
use MongoDB\BSON\ObjectId;
use App\Amazon\Components\Product\Inventory;
use App\Core\Models\User;
use App\Connector\Components\Dynamo;
use Aws\DynamoDb\Marshaler;
use Exception;

class Helper
{
    /**
     * createAmazonWebhooks function
     * To create Amazon Webhooks for particular user
     * @param [array] $params
     * @return array
     */
    public function createAmazonWebhooks($params)
    {
        if (!empty($params['shop_id'])) {
            $targetShopIds = $params['shop_id'];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $config = $mongo->getCollectionForTable('config');
            $tokenExpiredIds = $config->distinct("target_shop_id", ['group_code' => 'feedStatus', 'target_shop_id' => ['$in' => $targetShopIds], 'value' => 'inactive']);
            $validIds = array_diff($targetShopIds, $tokenExpiredIds);
            $message = 'Registering Webhooks...';
            if (!empty($validIds)) {
                if (isset($params['user_id']) && !empty($params['user_id'])) {
                    $userId = $params['user_id'];
                } else {
                    $userData = $this->getUserShop($validIds[0]);
                    if (isset($userData['success']) && $userData['success']) {
                        $userId = $userData['user']['user_id'];
                    }
                }

                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    $logFile = "amazonRegisterWebhook/singleUser/" . date('d-m-Y') . '.log';
                    $this->di->getLog()->logContent('Starting Process for UserId: ' . json_encode($userId, true), 'info', $logFile);
                    foreach ($validIds as $id) {
                        $data = $this->getUserShop($id);
                        if (isset($data['success']) && $data['success']) {
                            $target['marketplace'] = $params['target_marketplace'];
                            $source['marketplace'] = $params['source_marketplace'];
                            $user = $data['user'];
                            $dataToSent['user_id'] = $user['user_id'];
                            foreach ($user['shops'] as $shop) {
                                $sources = $shop['sources'][0];
                                if ($shop['marketplace'] == $target['marketplace']) {
                                    $target['shop_id'] = $shop['_id'];
                                }

                                if ($source['marketplace'] == $sources['code']) {
                                    $source['shop_id'] = $sources['shop_id'];
                                }

                                if (isset($target['shop_id'], $source['shop_id'])) {
                                    $dataToSent['source_marketplace'] = $source;
                                    $dataToSent['target_marketplace'] = $target;
                                    $this->di->getLog()->logContent('Called Create Subscription', 'info', $logFile);
                                    $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                                        ->createSubscription($dataToSent);
                                }
                            }
                        } else {
                            $this->di->getLog()->logContent('Shop not Found=> ' . json_encode($id, true), 'info', $logFile);
                        }
                    }
                } else {
                    $message = 'Unable to set Di';
                }
            }

            if (!empty($tokenExpiredIds)) {
                $this->di->getLog()->logContent('Token Expired Ids: ' . json_encode($tokenExpiredIds, true), 'info', $logFile);
                $message = 'Refresh token is expired for one or more amazon accounts';
            }

            $this->di->getLog()->logContent('END', 'info', $logFile);
            return [
                'success' => true,
                'message' => $message
            ];
        }
    }

    /**
     * registerAllUsersWebhook function
     * To create Webhooks for all active users
     * @param [array] $params
     */
    public function registerAllUsersWebhook($params = []): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $createSubscriptionObject = $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class);
        $logFile = "amazonRegisterWebhook/allUsers/" . date('d-m-Y') . '.log';
        $message = 'Whole Process Completed';
        $pipline = [
            [
                '$match' => [
                    'shops' => [
                        '$elemMatch' => [
                            'apps.app_status' => 'active',
                            'marketplace' => 'amazon'
                        ],
                    ],
                ]
            ],
            ['$unwind' => '$shops'],
            [
                '$match' => [
                    'shops.marketplace' => 'amazon',
                    'shops.apps.app_status' => 'active',
                ]
            ],
            ['$project' => ['user_id' => 1, 'shops' => 1]],
            ['$group' => ['_id' => '$user_id', 'shops' => ['$addToSet' => '$shops']]],
            ['$sort' => ['_id' => 1]],
            ['$limit' => 50],
        ];
        if (!empty($params)) {
            if (isset($params['user_id']) && !empty($params['user_id'])) {
                $id = new ObjectId($params['user_id']);
                $preparePipline = ['$match' => ['_id' => ['$gt' => $id]]];
                array_splice($pipline, 0, 0, [$preparePipline]);
            }
        }

        $userData = $query->aggregate($pipline, $arrayParams)->toArray();
        if (!empty($userData)) {
            foreach ($userData as $user) {
                $userId = $user['_id'];
                $this->di->getLog()->logContent('Starting Process for UserId: ' . json_encode($userId, true), 'info', $logFile);
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    if (!empty($user['shops'])) {
                        foreach ($user['shops'] as $shop) {
                            $source = $shop['sources'][0];
                            $prepareData['source_marketplace'] = [
                                'marketplace' => $source['code'],
                                'shop_id' => $source['shop_id']
                            ];
                            $prepareData['target_marketplace'] = [
                                'marketplace' => $shop['marketplace'],
                                'shop_id' => $shop['_id']
                            ];
                            $prepareData['user_id'] = $userId;
                            $this->di->getLog()->logContent('Called Create Subscription ', 'info', $logFile);
                            $createSubscriptionObject->createSubscription($prepareData);
                        }
                    }
                }
            }

            $endUser = end($userData);
            $processedUsers = array_column($userData, '_id');
            $query->updateMany(
                ['user_id' => ['$in' => $processedUsers]],
                ['$set' => ['picked' => true]]
            );
            $userId = $endUser['_id'];
            $handlerData = [
                'type' => 'full_class',
                'class_name' => \App\Amazon\Components\Manual\Helper::class,
                'method' => 'registerAllUsersWebhook',
                'queue_name' => 'amazon_register_all_webhooks',
                'user_id' => $userId
            ];
            $sqsHelper->pushMessage($handlerData);
            $this->di->getLog()->logContent('Process Completed for this user', 'info', $logFile);
            $message = 'Processing from Queue...';
        }

        $this->di->getLog()->logContent($message, 'info', $logFile);
        $this->di->getLog()->logContent('-------------------------------', 'info', $logFile);
        return ['success' => true, 'message' => $message];
    }

    /**
     * deleteAmazonWebhooks function
     * To delete particular webhook code of for particular user
     * @param [array] $params
     * @return array
     */
    public function deleteAmazonWebhooks($params)
    {
        if (!empty($params['shop_id']) && !empty($params['target_marketplace'])) {
            $shopIds = $params['shop_id'];
            $message = 'Unregistering Webhooks...';
            $marketplace = $params['target_marketplace'] ?? 'amazon';
            if (!empty($shopIds) && !empty($params['codes'])) {
                if (isset($params['user_id']) && !empty($params['user_id'])) {
                    $userId = $params['user_id'];
                } else {
                    $userData = $this->getUserShop($shopIds[0]);
                    if (isset($userData['success']) && $userData['success']) {
                        $userId = $userData['user']['user_id'];
                    }
                }

                $response = $this->setDiForUser($userId);
                $webhookCodes = $params['codes'];
                if (isset($response['success']) && $response['success']) {
                    $this->di->getLog()->logContent('Starting Process for User_id: ' . json_encode($userId, true), 'info', 'deleteAmazonWebhook.log');
                    foreach ($shopIds as $id) {
                        $data = $this->getUserShop($id);
                        if (isset($data['success']) && $data['success']) {
                            $user = $data['user'];
                            foreach ($user['shops'] as $shop) {
                                if ($shop['remote_shop_id'] == $id) {
                                    $webhookShop = $shop;
                                    $appCode = $webhookShop['apps'][0]['code'] ?? 'default';
                                    $this->di->getLog()->logContent('Called Unregister Webhook for RemoteShopId: ' . $id, 'info', 'deleteAmazonWebhook.log');
                                    $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")
                                        ->routeUnregisterWebhooks($webhookShop, $marketplace, $appCode, false, $webhookCodes);
                                }
                            }
                        } else {
                            $this->di->getLog()->logContent('Shop not Found => ' . json_encode($id, true), 'info', 'deleteAmazonWebhook.log');
                        }
                    }
                } else {
                    $message = 'Unable to set Di';
                }
            }

            $this->di->getLog()->logContent('END', 'info', 'deleteAmazonWebhook.log');
            return [
                'success' => true,
                'message' => $message
            ];
        }
    }

    /**
     * registerAmazonWebhooksByCode function
     * To register particular webhook codes for sellers
     * @param [array] $params
     */
    public function registerAmazonWebhooksByCode($params = []): array
    {
        $message = 'Something Went Wrong';
        if (!empty($params['userIds']) && !empty($params['codes']) && isset($params['marketplace'])) {
            $logFile = "amazonRegisterWebhook/script/" . date('d-m-Y') . '.log';
            $connectorObject = $this->di->getObjectManager()
                ->get("\App\Connector\Models\SourceModel");
            foreach ($params['userIds'] as $userId) {
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    $shops = $this->di->getUser()->shops;
                    if (!empty($shops) && count($shops) > 1) {
                        $awsClient = require BP . '/app/etc/aws.php';
                        $data = [
                            'title' => 'User type Event Destination',
                            'type' => 'user',
                            'event_handler' => 'sqs',
                            'destination_data' => [
                                'type' => 'sqs',
                                "sqs" => [
                                    "region" => $awsClient['region'],
                                    "key" => $awsClient['credentials']['key'] ?? null,
                                    "secret" => $awsClient['credentials']['secret'] ?? null,
                                    'queue_base_url' => "https://sqs." . $awsClient['region']
                                        . ".amazonaws.com/" . $awsClient['account_id'] . "/"
                                ]
                            ]
                        ];

                        foreach ($shops as $shopData) {
                            $destinationWise = [];
                            if (isset($shopData['marketplace'])) {
                                if ($shopData['marketplace'] == $params['marketplace']) {
                                    if (isset($shopData['apps'], $shopData['remote_shop_id'])) {
                                        if ($shopData['apps'][0]['app_status'] == 'active') {
                                            $appCode = $shopData['apps'][0]['code'] ?? 'default';
                                            $remoteResponse = $this->di->getObjectManager()
                                                ->get("\App\Connector\Components\ApiClient")
                                                ->init('cedcommerce', false)
                                                ->call('event/destination', [], [
                                                    'shop_id' => $shopData['remote_shop_id'],
                                                    'data' => $data,
                                                    'app_code' => "cedcommerce"
                                                ], 'POST');
                                            $destinationId = $remoteResponse['success']
                                                ? $remoteResponse['destination_id'] : false;
                                            if (!$destinationId) {
                                                $this->di->getLog()->logContent('Unable to get Destination UserId: '
                                                    . json_encode($userId), 'info', $logFile);
                                            }

                                            $destinationWise[] = [
                                                'destination_id' => $destinationId,
                                                'event_codes' =>  array_values($params['codes'])
                                            ];
                                            $createWebhookRequest = $connectorObject->routeRegisterWebhooks(
                                                $shopData,
                                                $params['marketplace'],
                                                $appCode,
                                                false,
                                                $destinationWise
                                            );
                                            $this->di->getLog()->logContent('Create Webhook Request Response=> '
                                                . json_encode($createWebhookRequest, true), 'info', $logFile);
                                        }
                                    }
                                }
                            }
                        }

                        return ['success' => true, 'message' => 'Registering Webhooks...'];
                    }
                }
            }
        } else {
            $message = 'Missing Required Params';
        }

        return ['success' => false, 'message' => $message];
    }

    public function syncFBADetails( $params = []): void
    {
        if (!empty($params['user_id'])) {
            $response = $this->setDiForUser($params['user_id']);
            if (isset($response['success']) && $response['success']) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $config = $mongo->getCollectionForTable('config');
                $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
                $dynamoClientObj = $dynamoObj->getDetails();
                $targetMarketplace = 'amazon';
                $shops = $this->di->getUser()->shops;
                if (!empty($shops)) {
                    foreach ($shops as $shop) {
                        if ($shop['marketplace'] == $targetMarketplace
                            && $shop['apps'][0]['app_status'] == 'active') {
                            $targetShopId = $shop['_id'];
                            $remoteShopId = $shop['remote_shop_id'];
                            if (isset($shop['sources'])) {
                                $sources = $shop['sources'][0];
                                $sourceMarketplace = $sources['code'];
                                $sourceShopId = $sources['shop_id'];
                                $prepareConfigData = [
                                    'key' => 'fbo_order_fetch',
                                    'value' => true,
                                    'group_code' => 'order',
                                    'source_shop_id' => $sourceShopId,
                                    'target_shop_id' => $targetShopId,
                                    'app_tag' => $params['appTag'],
                                    'user_id' => $params['user_id'],
                                    'source' => $sourceMarketplace,
                                    'target' => $targetMarketplace,
                                    'created_at' => date('c'),
                                    'updated_at' => date('c')
                                ];
                                try {
                                    $config->updateOne(
                                        [
                                            'user_id' => $params['user_id'],
                                            'key' => $prepareConfigData['key'],
                                            'source_shop_id' => $sourceShopId,
                                            'target_shop_id' => $targetShopId
                                        ],
                                        [
                                            '$set' => $prepareConfigData,
                                        ],
                                        ['upsert' => true]
                                    );
                                    $dynamoClientObj->updateItem(['TableName' => 'user_details', 'Key' => ['id' => ['S' => (string)$remoteShopId]], 'ExpressionAttributeValues' =>  [':fetch' => ['S' => '1'], ':filter' => ['S' => '{}']], 'ConditionExpression' => 'attribute_exists(seller_id)', 'UpdateExpression' => 'SET fba_fetch = :fetch , fba_filter=:filter']);
                                } catch (Exception $e) {
                                    $this->di->getLog()->logContent('Exception From syncFBADetails=> '
                                    . json_encode($e, true), 'info', 'exception.log');
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * syncHandlingTime function
     * To sync handling time on Amazon
     * @param [string] $userId
     * @param [string] $target_shop_id
     * @return array
     */
    public function syncHandlingTime($params = [])
    {
        if (!empty($params['user_id'])) {
            $response = $this->setDiForUser($params['user_id']);
            if (isset($response['success']) && $response['success']) {
                $userId = $params['user_id'];
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $syncLeadTimeCollection = $mongo->getCollectionForTable('sync_handling_time');
                $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                $logFile = "amazon/sync_handling_time/" . $userId . "/" . date('d-m-Y') . '.log';
                $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
                $pipline = [
                    [
                        '$match' => [
                            'user_id' => $params['user_id']
                        ]
                    ],
                    ['$limit' => 5000]
                ];
                $productData = $syncLeadTimeCollection->aggregate($pipline, $arrayParams)->toArray();
                if (!empty($productData)) {
                    $sourceProductIds = array_column($productData, 'source_product_id');
                    $sourceMarketplace = $productData[0]['source_marketplace'];
                    $targetMarketplace = $productData[0]['target_marketplace'];
                    $dataToSend = [
                        'target' => [
                            'marketplace' => $targetMarketplace,
                            'shopId' => $params['target_shop_id']
                        ],
                        'source' => [
                            'marketplace' => $sourceMarketplace,
                            'shopId' => $productData[0]['source_shop_id']
                        ],
                        'source_product_ids' => $sourceProductIds,
                        'userId' => $userId
                    ];
                    $response = $this->di->getObjectManager()->get(Inventory::class)
                        ->bulkSyncInventory($dataToSend);
                    if (empty($response)) {
                        $this->di->getLog()->logContent('ERROR : Unable to sync handling_time for Products :'
                            . json_encode($sourceProductIds), 'info', $logFile);
                    }

                    $this->deleteProductData($userId, $sourceProductIds);
                    if (count($productData) < 5000) {
                        return ['success' => true, 'message' => 'All Products Data synced'];
                    }

                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => \App\Amazon\Components\Manual\Helper::class,
                        'method' => 'syncHandlingTime',
                        'queue_name' => $sourceMarketplace . '_process_metafield_users',
                        'user_id' => $userId,
                        'target_shop_id' => $params['target_shop_id'],
                        'delay' => 300
                    ];
                    $sqsHelper->pushMessage($handlerData);
                }
            }
        }
    }

    /**
     * deleteProductData function
     * Deletes product data for products
     * that have been synced with Amazon
     * @param [string] $userId
     * @param [array] $sourceProductIds
     */
    public function deleteProductData($userId, $sourceProductIds): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $syncLeadTimeCollection = $mongo->getCollectionForTable('sync_handling_time');
        $syncLeadTimeCollection->deleteMany(
            [
                'user_id' => $userId,
                'source_product_id' => ['$in' => $sourceProductIds]
            ]
        );
    }

    /**
     * setDiForUser function
     * To set current user in Di
     * @param [string] $userId
     */
    public function setDiForUser($userId, $forceSetDi = false): array
    {
        if($this->di->getUser()->id === $userId && !$forceSetDi) {
            return [
                'success' => true,
                'message' => 'Di already set'
            ];
        }
        try {
            $getUser = \App\Core\Models\User::findFirst([['_id' => $userId]]);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found.'
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

    /**
     * getUserShop function
     * To get Shop Details by shopId
     * @param [string] $targetShopId
     */
    public function getUserShop($targetShopId): array
    {
        if (!empty($targetShopId)) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $user_details = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
            $userData = $user_details->findOne(
                ['shops' => ['$elemMatch' => ['apps.app_status' => 'active', '_id' => $targetShopId]]],
                ['projection' => ["_id" => false, "shops.$" => true, 'user_id' => true], 
                'typeMap' => ['root' => 'array', 'document' => 'array']]
            );

            if (!empty($userData)) {
                return ['success' => true, 'user' => $userData];
            }
            return ['success' => false, 'message' => 'wrong remote_shop_id OR user not active'];
        }
        return ['success' => false, 'message' => 'remote_shop_id not sent in params'];
    }

    /**
     * updateTokenStatus function
     * Function is reponsible for updating token
     * status to active if access_token is generated
     * successfully
     * @param [string] $targetShopId
     */
    public function updateTokenStatus($params = [])
    {
        $logFile = "amazon/validateToken/" . date('d-m-Y') . '.log';
        $page = $params['page'] ?? 1;
        $this->di->getLog()->logContent('Starting Page: ' . $page, 'info', $logFile);
        try {
            $pipline = [
                [
                    '$match' => [
                        'key' => 'accountStatus',
                        'group_code' => 'feedStatus',
                        'target' => 'amazon',
                        'error_code' => [
                            '$in' => ['invalid_grant', 'Unauthorized']
                        ],
                        'value' => ['$in' => ['expired', 'inactive']]
                    ]
                ],
                ['$sort' => ['_id' => 1]],
                ['$limit' => 500]
            ];
            if (!empty($params['_id'])) {
                if (isset($params['_id']['$oid'])) {
                    $id = new ObjectId($params['_id']['$oid']);
                } else {
                    $id = $params['_id'];
                }

                $preparePipline = ['$match' => ['_id' => ['$gt' => $id]]];
                array_splice($pipline, 0, 0, [$preparePipline]);
            }
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $config = $mongo->getCollectionForTable('config');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $configData = $config->aggregate($pipline, $arrayParams)->toArray();
            $commonHelper =  $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
            if (!empty($configData)) {
                $prepareData = [];
                $tokenValidIds = [];
                foreach ($configData as $data) {
                    $prepareData[$data['user_id']]['target_shop_id'][] = $data['target_shop_id'];
                }
                foreach ($prepareData as $user => $data) {
                    $this->di->getLog()->logContent('Starting Process for UserId: ' .json_encode($user), 'info', $logFile);
                    $response = $this->setDiForUser($user);
                    if (isset($response['success']) && $response['success']) {
                        $shops = $this->di->getUser()->shops ?? [];
                        if (!empty($shops)) {
                            foreach ($shops as $shop) {
                                if (in_array($shop['_id'], $data['target_shop_id'])) {
                                    if ($shop['apps'][0]['app_status'] == 'active') {
                                        $remoteResponse = $commonHelper->sendRequestToAmazon('access-token', ['shop_id' => $shop['remote_shop_id']], 'GET');
                                        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                                            $tokenValidIds[] = $shop['_id'];
                                            $this->di->getLog()->logContent('Token Valid Data: ' .
                                                json_encode([
                                                    'home_shop_id' => $shop['_id'],
                                                    'remote_shop_id' => $shop['remote_shop_id'],
                                                    'response' => $remoteResponse['response'] ?? 'Token Generated'
                                                ]), 'info', $logFile);
                                        }
                                    } else {
                                        $this->di->getLog()->logContent('Inactive Shop found:' . json_encode([
                                            'home_shop_id' => $shop['_id'],
                                            'remote_shop_id' => $shop['remote_shop_id']
                                        ]), 'info', $logFile);
                                    }
                                }
                            }
                        }
                    } else {
                        $this->di->getLog()->logContent('Unable to set di for user: ' . json_encode($user), 'info', $logFile);
                    }
                }
                $this->di->getLog()->logContent('Total validIds found in this page: ' . json_encode(count($tokenValidIds)), 'info', $logFile);
                if (!empty($tokenValidIds)) {
                    $config->updateMany(
                        [
                            'user_id' => ['$ne' => null],
                            'key' => 'accountStatus',
                            'group_code' => 'feedStatus',
                            'target' => 'amazon',
                            'target_shop_id' => ['$in' => $tokenValidIds]
                        ],
                        [
                            '$set' => ['value' => 'active']
                        ]
                    );
                } else {
                    $this->di->getLog()->logContent('No valid ids found in this page', 'info', $logFile);
                }
                $endUser = end($configData);
                $this->di->getLog()->logContent('Last _id of this page' . json_encode($endUser['_id']), 'info', $logFile);
                $page = $page + 1;
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => \App\Amazon\Components\Manual\Helper::class,
                    'method' => 'updateTokenStatus',
                    'queue_name' => 'validate_refresh_token',
                    'user_id' => $this->di->getUser()->id,
                    '_id' => $endUser['_id'],
                    'page' => $page,
                    'delay' => 5
                ];
                $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')
                    ->pushMessage($handlerData);
                $this->di->getLog()->logContent('Process Completed for this page', 'info', $logFile);
                $message = 'Processing from Queue...';
            } else {
                $this->di->getLog()->logContent('All pages completed', 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception => ' . print_r($e->getMessage(), true), 'info', $logFile);
        }
        return ['success' => true, 'message' => $message ?? 'Process Completed'];
    }

    /**
     * validateAmazonOrderChangeWebhook function
     * Function is reponsible for validating amazon order_change
     * webhook and if not registered then unregistering all webhooks
     * and re-registering all webhooks
     * @param [array] $data
    */
    public function validateAmazonOrderChangeWebhook($data = [])
    {
        $logFile = "amazon/script/" . date('d-m-Y') . '.log';
        $userId = $this->di->getUser()->id ?? $data['user_id'];
        $this->di->getLog()->logContent('Starting Process for UserId: ' . json_encode($userId), 'info', $logFile);
        try {
            $response = $this->setDiForUser($userId);
            if (isset($response['success']) && $response['success']) {
                $commonHelper =  $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $userDetails = $mongo->getCollectionForTable('user_details');
                $shops = $this->di->getUser()->shops ?? [];
                $marketplace = 'amazon';
                if (!empty($shops)) {
                    foreach ($shops as $shop) {
                        if (in_array($shop['_id'], $data['shops'])) {
                            if (!empty($shop['apps'][0])
                                && $shop['apps'][0]['app_status'] == 'active'
                                && $shop['marketplace'] == $marketplace) {
                                $specifics = [
                                    'shop_id' => $shop['remote_shop_id'],
                                    'notificationtype' => 'ORDER_CHANGE'
                                ];
                                $remoteResponse = $commonHelper->sendRequestToAmazon('get-subscription', $specifics, 'GET');
                                if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                                } else {
                                    $errorCode = $remoteResponse['code'] ?? '';
                                    if ($errorCode == '400') {
                                        $this->di->getLog()->logContent('Token is expired for this => ' . json_encode([
                                            'home_shop_id' => $shop['_id'],
                                            'remote_shop_id' => $shop['remote_shop_id']
                                        ]), 'info', $logFile);
                                    } else if ($errorCode == '403') {
                                        $this->di->getLog()->logContent('Unauthorized Error => ' . json_encode([
                                            'home_shop_id' => $shop['_id'],
                                            'remote_shop_id' => $shop['remote_shop_id'],
                                            'remote_response' => $remoteResponse
                                        ]), 'info', $logFile);
                                    } else if ($errorCode == '404') {
                                        $this->di->getLog()->logContent('Webhook Unregister and Register Process Started => ' . json_encode([
                                            'home_shop_id' => $shop['_id'],
                                            'remote_shop_id' => $shop['remote_shop_id']
                                        ]), 'info', $logFile);
                                        $webhooks = $shop['apps'][0]['webhooks'] ?? [];
                                        $dataToSent['user_id'] = $userId;
                                        $dataToSent['target_marketplace'] = [
                                            'marketplace' => $shop['marketplace'],
                                            'shop_id' => $shop['_id']
                                        ];
                                        if (!empty($webhooks)) {
                                            $dynamoWebhookIds = array_column($webhooks, "dynamo_webhook_id");
                                            $remoteData = [
                                                'shop_id' => $shop['remote_shop_id'],
                                                'subscription_ids' => $dynamoWebhookIds,
                                                'app_code' => "cedcommerce"
                                            ];
                                            $bulkDeleteSubscriptionResponse = $this->di->getObjectManager()
                                                ->get("\App\Connector\Components\ApiClient")
                                                ->init('cedcommerce', true)
                                                ->call('bulkDelete/subscription', [], $remoteData, 'DELETE');
                                            if (isset($bulkDeleteSubscriptionResponse['success']) && $bulkDeleteSubscriptionResponse['success']) {
                                                $this->di->getLog()->logContent('Data Deleted from all tables', 'info', $logFile);
                                                $userDetails->updateOne(
                                                    [
                                                        "user_id" => $userId,
                                                        "shops" => [
                                                            '$elemMatch' => [
                                                                '_id' => $shop['_id'],
                                                                'marketplace' => $shop['marketplace']
                                                            ]
                                                        ]
                                                    ],
                                                    [
                                                        '$pull' => [
                                                            'shops.$[shop].apps.0.webhooks' => [
                                                                'dynamo_webhook_id' => [
                                                                    '$in' => $dynamoWebhookIds
                                                                ]
                                                            ]
                                                        ]
                                                    ],
                                                    [
                                                        'arrayFilters' => [
                                                            ['shop.marketplace' => $shop['marketplace']]
                                                        ]
                                                    ]
                                                );
                                                $this->setDiForUser($userId, true);
                                                $createSubscriptionResponse = $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                                                    ->createSubscription($dataToSent);
                                                $this->di->getLog()->logContent('createSubscriptionResponse=>' . json_encode($createSubscriptionResponse), 'info', $logFile);
                                            } else {
                                                $this->di->getLog()->logContent('Bulk Delete Subscription Response Error: '
                                                    . json_encode([
                                                        'home_shop_id' => $shop['_id'],
                                                        'remote_shop_id' => $shop['remote_shop_id'],
                                                        'remote_response' => $bulkDeleteSubscriptionResponse
                                                    ]), 'info', $logFile);
                                            }
                                        } else {
                                            $this->di->getLog()->logContent('Empty webhooks array found intiating registeration process only: '
                                                . json_encode([
                                                    'home_shop_id' => $shop['_id'],
                                                    'remote_shop_id' => $shop['remote_shop_id']
                                                ]), 'info', $logFile);
                                            $createSubscriptionResponse = $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                                                ->createSubscription($dataToSent);
                                            $this->di->getLog()->logContent('createSubscriptionResponse=>' . json_encode($createSubscriptionResponse), 'info', $logFile);
                                        }
                                    } else {
                                        $this->di->getLog()->logContent('Blank code from remote: ' . json_encode([
                                            'home_shop_id' => $shop['_id'],
                                            'remote_shop_id' => $shop['remote_shop_id'],
                                            'remote_response' => $remoteResponse
                                        ]), 'info', $logFile);
                                    }
                                }
                            } else {
                                $this->di->getLog()->logContent('Inactive shop data: '
                                    . json_encode([
                                        'home_shop_id' => $shop['_id'],
                                        'remote_shop_id' => $shop['remote_shop_id']
                                    ]), 'info', $logFile);
                            }
                        }
                    }
                }
            } else {
                $this->di->getLog()->logContent('Unable to set di for this user: ' . json_encode($userId), 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception Occured: ' . print_r($e, true), 'info', $logFile);
        }
    }

    /**
     * updateOrderFetchDate function
     * To update order_filter or fba_filter min_created_at date in DynamoDB user_details table
     * @param array $params (user_id, shop_id, order_type, order_fetch_date in Y-m-dTH:i format)
     * @return array
     */
    public function updateOrderFetchDate($params = []): array
    {
        try {
            if (isset($params['user_id'], $params['shop_id'], $params['order_type'], $params['order_fetch_date'])) {
                $userId = $params['user_id'];
                $shopId = $params['shop_id'];
                $orderType = strtoupper($params['order_type']);
                $orderFetchDate = $params['order_fetch_date'];

                if (in_array($orderType, ['FBM', 'FBA'])) {
                    $dateObj = \DateTime::createFromFormat('Y-m-d\TH:i', $orderFetchDate);
                    if ($dateObj && $dateObj->format('Y-m-d\TH:i') === $orderFetchDate) {
                        $currentYear = (int)date('Y');
                        $dateYear = (int)$dateObj->format('Y');

                        if ($dateYear == $currentYear) {
                            $shopData = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class)
                                ->getShop($shopId, $userId);

                            if (isset($shopData['remote_shop_id'])) {
                                $remoteShopId = $shopData['remote_shop_id'];
                                $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
                                $dynamoClientObj = $dynamoObj->getDetails();
                                $marshaler = new Marshaler();

                                $dynamoData = $dynamoClientObj->getItem([
                                    'TableName' => 'user_details',
                                    'Key' => $marshaler->marshalItem([
                                        'id' => (string)$remoteShopId,
                                    ]),
                                    'ConsistentRead' => true,
                                ]);

                                if (!empty($dynamoData['Item'])) {
                                    $userData = $marshaler->unmarshalItem($dynamoData['Item']);

                                    if (!isset($userData['disable_order_sync']) || !$userData['disable_order_sync']) {
                                        $convertedDate = $dateObj->format('Y-m-d\TH:i:s\Z');

                                        $updateExpression = [];
                                        $expressionAttributeValues = [];

                                        if ($orderType === 'FBM') {
                                            $orderFilter = [];
                                            if (!empty($userData['order_filter'])) {
                                                $orderFilter = is_string($userData['order_filter'])
                                                    ? json_decode($userData['order_filter'], true)
                                                    : $userData['order_filter'];
                                            }
                                            $orderFilter['min_created_at'] = $convertedDate;
                                            $updateExpression[] = 'order_filter = :orderFilter';
                                            $updateExpression[] = 'order_fetch = :orderFetch';
                                            $expressionAttributeValues[':orderFilter'] = json_encode($orderFilter);
                                            $expressionAttributeValues[':orderFetch'] = 1;

                                        } elseif ($orderType === 'FBA') {
                                            if (isset($userData['fba_fetch'])) {
                                                $fbaFilter = [];
                                                if (!empty($userData['fba_filter'])) {
                                                    $fbaFilter = is_string($userData['fba_filter'])
                                                        ? json_decode($userData['fba_filter'], true) 
                                                        : $userData['fba_filter'];
                                                }
                                                $fbaFilter['min_created_at'] = $convertedDate;
                                                $updateExpression[] = 'fba_filter = :fbaFilter';
                                                $updateExpression[] = 'fba_fetch = :fbaFetch';
                                                $expressionAttributeValues[':fbaFilter'] = json_encode($fbaFilter);
                                                $expressionAttributeValues[':fbaFetch'] = '1';
                                            } else {
                                                $message = 'FBA order fetch setting is disabled for this shop';
                                            }
                                        }

                                        if (!empty($updateExpression)) {
                                            $expressionAttributeValuesMarshalled = $marshaler->marshalItem($expressionAttributeValues);
                                            $updateParams = [
                                                'TableName' => 'user_details',
                                                'Key' => $marshaler->marshalItem([
                                                    'id' => (string)$remoteShopId,
                                                ]),
                                                'UpdateExpression' => 'SET ' . implode(', ', $updateExpression),
                                                'ExpressionAttributeValues' => $expressionAttributeValuesMarshalled
                                            ];

                                            $dynamoClientObj->updateItem($updateParams);

                                            return [
                                                'success' => true,
                                                'message' => ucfirst($orderType) . ' order filter updated'
                                            ];
                                        }
                                    } else {
                                        $message = 'Order syncing is disabled for this amazon shop either due to plan restriction or account status is inactive';
                                    }
                                } else {
                                    $message = 'Order fetching is yet not enabled for this user shop. Either plan is not selected or account status is inactive';
                                }
                            } else {
                                $message = 'Shop not found';
                            }
                        } else {
                            $message = 'The provided date must be of present year';
                        }
                    } else {
                        $message = 'Invalid date format. Expected Y-m-dTH:i format (e.g., 2025-04-22T09:25)';
                    }
                } else {
                    $message = 'Invalid order_type. Must be FBM or FBA';
                }
            } else {
                $message = 'Missing required parameters: (user_id, shop_id, order_type, order_fetch_date)';
            }

            return [
                'success' => false,
                'message' => $message ?? 'Something went wrong'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception occurred: ' . $e->getMessage()
            ];
        }
    }

    /**
     * createCustomDestination function
     * To create destination
     * @param array $params (user_id, shop_id, region, arn, event_code)
     * @return array
     */
    public function createCustomDestination($params = []): array
    {
        try {
            $logFile = "amazon/script/" . date('d-m-Y') . '.log';

            if (empty($params['user_id']) || empty($params['shop_id']) || empty($params['region']) || empty($params['arn']) || empty($params['event_code'])) {
                return [
                    'success' => false,
                    'message' => 'All parameters are required: user_id, shop_id, region, arn, event_code'
                ];
            }

            $userId = $params['user_id'];
            $shopId = $params['shop_id'];
            $region = $params['region'];
            $arn = $params['arn'];
            $eventCode = $params['event_code'];

            $this->di->getLog()->logContent('Starting createCustomDestination for user_id: ' . $userId . ', shop_id: ' . $shopId, 'info', $logFile);
            // Get shop details
            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $userShop = $user_details->getShop($shopId, $userId);
            if (!$userShop) {
                $this->di->getLog()->logContent('Invalid shopId or userId', 'error', $logFile);
                return [
                    'success' => false,
                    'message' => 'Invalid shopId or userId'
                ];
            }

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $destinationCollection = $mongo->getCollectionForTable('amazon_sqs_destination');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

            // Check if destination already exists
            $destinationData = $destinationCollection->findOne(['notification_type' => $eventCode], $options);
            if ($destinationData) {
                $this->di->getLog()->logContent('Destination already exists for event_code: ' . $eventCode, 'info', $logFile);
                return [
                    'success' => true,
                    'message' => 'Destination already exists for event_code: ' . $eventCode,
                    'data' => $destinationData
                ];
            }

            // Prepare specifics for sendRequestToAmazon
            $title = 'Event for ' . $eventCode;
            $specifics = [
                'shop_id' => $userShop['remote_shop_id'],
                'event_data' => [
                    'marketplace_data' => [
                        'sqs' => [
                            'arn' => $arn
                        ]
                    ],
                    'event_code' => $eventCode,
                    'title' => $title,
                    'type' => 'user',
                    'event_handler' => 'sqs'
                ]
            ];
            // Use the Helper component to send the request
            $commonHelper = $this->di->getObjectManager()->get('App\\Amazon\\Components\\Common\\Helper');
            $response = $commonHelper->sendRequestToAmazon('create/destination', $specifics, 'POST');

            if (isset($response['success']) && $response['success']) {
                $insert_data = [
                    'notification_type' => $eventCode,
                    'arn' => $arn,
                    'region' => $region,
                    'destination_id' => $response['destination_id'],
                    'created_at' => date('c')
                ];
                $destinationOut = $destinationCollection->insertOne($insert_data);

                $this->di->getLog()->logContent('Destination created successfully: ' . json_encode($response), 'info', $logFile);
                return [
                    'success' => true,
                    'message' => 'Destination created successfully',
                    'data' => [
                        'destination_id' => $response['destination_id'],
                        'inserted_count' => $destinationOut->getInsertedCount()
                    ]
                ];
            } else {
                $this->di->getLog()->logContent('Destination creation failed: ' . json_encode($response), 'error', $logFile);
                return [
                    'success' => false,
                    'message' => 'Destination creation failed: ' . ($response['error'] ?? 'Unknown error'),
                    'data' => $response
                ];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception createCustomDestination(): ' . $e->getMessage(), 'error', 'exception.log');
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * createCustomSubscription function
     * To create subscription
     * @param array $params (user_id, shop_id, event_code)
     * @return array
     */
    public function createCustomSubscription($params = []): array
    {
        try {
            $logFile = "amazon/script/" . date('d-m-Y') . '.log';

            if (empty($params['user_id']) || empty($params['shop_id']) || empty($params['event_code'])) {
                return [
                    'success' => false,
                    'message' => 'All parameters are required: user_id, shop_id, event_code'
                ];
            }

            $userId = $params['user_id'];
            $shopId = $params['shop_id'];
            $eventCode = $params['event_code'];

            $this->di->getLog()->logContent('Starting createCustomSubscription for user_id: ' . $userId . ', shop_id: ' . $shopId . ', event_code: ' . $eventCode, 'info', $logFile);

            // Get shop details
            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $userShop = $user_details->getShop($shopId, $userId);

            if (!$userShop) {
                $this->di->getLog()->logContent('Invalid shopId or userId', 'error', $logFile);
                return [
                    'success' => false,
                    'message' => 'Invalid shopId or userId'
                ];
            }

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $destinationCollection = $mongo->getCollectionForTable('amazon_sqs_destination');
            $subscriptionCollection = $mongo->getCollectionForTable('amazon_sqs_subscription');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

            // Check if subscription already exists
            $filter = [
                'seller_id' => $userShop['warehouses'][0]['seller_id'],
                'region' => $userShop['warehouses'][0]['region'],
                'topic' => $eventCode
            ];
            $subscriptionData = $subscriptionCollection->findOne($filter, $options);

            if ($subscriptionData) {
                $this->di->getLog()->logContent('Subscription already exists for event_code: ' . $eventCode, 'info', $logFile);
                return [
                    'success' => true,
                    'message' => 'Subscription already exists for event_code: ' . $eventCode,
                    'data' => $subscriptionData
                ];
            }

            // Fetch destination_id from amazon_sqs_destination collection
            $destinationData = $destinationCollection->findOne(['notification_type' => $eventCode], $options);
            if (!$destinationData) {
                $this->di->getLog()->logContent('Destination not found for event_code: ' . $eventCode, 'error', $logFile);
                return [
                    'success' => false,
                    'message' => 'Destination not found for event_code: ' . $eventCode . '. Please create destination first.'
                ];
            }

            // Prepare specifics for sendRequestToAmazon
            $specifics = [
                'shop_id' => $userShop['remote_shop_id'],
                'event_code' => $eventCode,
                'destination_id' => $destinationData['destination_id'],
            ];

            // Use the Helper component to send the request
            $commonHelper = $this->di->getObjectManager()->get('App\\Amazon\\Components\\Common\\Helper');
            $response = $commonHelper->sendRequestToAmazon('create-subscription', $specifics, 'POST');

            if (isset($response['success']) && $response['success']) {
                $insert_data = [
                    "user_id" => $userId,
                    "shop_id" => $shopId,
                    "seller_id" => $userShop['warehouses'][0]['seller_id'],
                    "region" => $userShop['warehouses'][0]['region'],
                    "topic" => $eventCode,
                    "subscriptionId" => $response['subscription_id'],
                    "destinationId" => $destinationData['destination_id'],
                    "payloadVersion" => "1.0",
                    "created_at" => date('c')
                ];
                $subscriptionOut = $subscriptionCollection->insertOne($insert_data);

                $this->di->getLog()->logContent('Subscription created successfully: ' . json_encode($response), 'info', $logFile);
                return [
                    'success' => true,
                    'message' => 'Subscription created successfully',
                    'data' => [
                        'subscription_id' => $response['subscription_id'],
                        'inserted_count' => $subscriptionOut->getInsertedCount()
                    ]
                ];
            } else {
                $this->di->getLog()->logContent('Subscription creation failed: ' . json_encode($response), 'error', $logFile);
                return [
                    'success' => false,
                    'message' => 'Subscription creation failed: ' . ($response['error'] ?? 'Unknown error'),
                    'data' => $response
                ];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception createCustomSubscription(): ' . $e->getMessage(), 'error', 'exception.log');
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    public function preventAccountDisconnection($params)
    {
        try {
            if (isset($params['old_prevent_details'], $params['new_prevent_details'], $params['user_id'])) {
                $oldPreventDetails = $params['old_prevent_details'];
                $newPreventDetails = $params['new_prevent_details'];
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $userDetailsCollection = $mongo->getCollectionForTable('user_details');
                if (!empty($newPreventDetails['shop_id'])) {
                    $userDetailsCollection->updateOne(
                        [
                            'user_id' => $params['user_id'],
                            'shops' => ['$elemMatch' => ['shop_id' => $newPreventDetails['shop_id']]]
                        ],
                        ['$set' => ['shops.$.prevent_disconnect' => true]]
                    );
                }

                if (!empty($oldPreventDetails['shop_id'])) {
                    $userDetailsCollection->updateOne(
                        [
                            'user_id' => $params['user_id'],
                            'shops' => ['$elemMatch' => ['shop_id' => $oldPreventDetails['shop_id']]]
                        ],
                        ['$unset' => ['shops.$.prevent_disconnect' => '']]
                    );
                }

                return ['success' => true, 'message' => 'Account marked as primary. Excluded from auto-disconnect.'];
            }
            return ['success' => false, 'message' => 'Unable to make this account as primary account'];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function getInactiveAccounts($params = []): array
    {
        try {
            $limit = isset($params['limit']) ? (int)$params['limit'] : 10;
            $page = isset($params['page']) ? (int)$params['page'] : 1;
            $selectedTab = $params['selected_tab'] ?? 'all';
            $userId = $params['user_id'] ?? null;
            $sort = $params['sort'] ?? [];
            $autoDisconnect = isset($params['auto_disconnect']) ? (bool)$params['auto_disconnect'] : false;

            $skip = ($page - 1) * $limit;

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $configCollection = $mongo->getCollectionForTable('config');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];

            // Build filter based on selected_tab
            $filter = [
                'group_code' => 'feedStatus',
                'key' => 'accountStatus'
            ];

            switch ($selectedTab) {
                case 'vacation':
                    $filter['error_code'] = 'InvalidInput';
                    $filter['value'] = 'inactive';
                    break;
                case 'token_expire':
                    $filter['error_code'] = 'invalid_grant';
                    $filter['value'] = 'expired';
                    break;
                case 'unauthorized':
                    $filter['error_code'] = 'Unauthorized';
                    $filter['value'] = 'access_denied';
                    break;
                case 'all':
                default:
                    $filter['error_code'] = ['$in' => ['invalid_grant', 'InvalidInput', 'Unauthorized']];
                    $filter['value'] = ['$in' => ['expired', 'inactive', 'access_denied']];
                    break;
            }

            // Add user_id filter if provided
            if (!empty($userId)) {
                $filter['user_id'] = $userId;
            }

            // Get total count from config collection using the same filter
            $totalCount = $configCollection->countDocuments($filter);

            // Query config collection
            $configData = $configCollection->find(
                $filter,
                [
                    'sort' => ['_id' => -1],
                    'skip' => $skip,
                    'limit' => $limit
                ] + $arrayParams
            )->toArray();

            if (empty($configData)) {
                return [
                    'success' => false,
                    'message' => 'No data found'
                ];
            }

            // Get unique user_ids from config data
            $userIds = array_unique(array_column($configData, 'user_id'));
            $userIds = array_filter($userIds); // Remove null/empty values

            if (empty($userIds)) {
                return [
                    'success' => true,
                    'data' => [],
                    'total' => $totalCount
                ];
            }

            // Query user_details collection
            $userDetailsCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
            $userDetailsPipeline = [
                [
                    '$match' => [
                        'user_id' => ['$in' => array_values($userIds)]
                    ]
                ],
                [
                    '$project' => [
                        'user_id' => 1,
                        'username' => 1,
                        'email' => 1,
                        'plan_tier' => 1,
                        'shops' => 1
                    ]
                ]
            ];

            $userDetailsData = $userDetailsCollection->aggregate($userDetailsPipeline, $arrayParams)->toArray();
            // Process user_details to get inactive shops
            $userDetailsMap = [];
            foreach ($userDetailsData as $user) {
                $userId = $user['user_id'] ?? null;
                if (empty($userId)) {
                    continue;
                }

                $inactiveShops = [];
                if (!empty($user['shops'])) {
                    foreach ($user['shops'] as $shop) {
                        // Check if it's an Amazon shop with inactive warehouse
                        if (isset($shop['marketplace']) && $shop['marketplace'] == 'amazon') {
                            $warehouses = [];
                            
                            // Check warehouses under apps (as per user requirement: apps.warehouse.status)
                            if (!empty($shop['apps']) && !empty($shop['apps'][0]['warehouses'])) {
                                $warehouses = $shop['apps'][0]['warehouses'];
                            }
                            // Fallback: check warehouses directly under shop
                            elseif (!empty($shop['warehouses'])) {
                                $warehouses = $shop['warehouses'];
                            }
                            
                            // Process warehouses
                            if (!empty($warehouses)) {
                                foreach ($warehouses as $warehouse) {
                                    if (isset($warehouse['status']) && $warehouse['status'] == 'inactive') {
                                        $shopId = (string)$shop['_id'];
                                        $inactiveShops[$shopId] = [
                                            'marketplace_id' => $warehouse['marketplace_id'] ?? '',
                                            'seller_id' => $warehouse['seller_id'] ?? ''
                                        ];
                                        break; // Only need one inactive warehouse per shop
                                    }
                                }
                            }
                        }
                    }
                }

                if (!empty($inactiveShops)) {
                    $userDetailsMap[$userId] = [
                        'inactive_shop_id' => $inactiveShops,
                        'plan_tier' => $user['plan_tier'] ?? 'N/A',
                        'user_id' => $userId,
                        'username' => $user['username'] ?? '',
                        'email' => $user['email'] ?? ''
                    ];
                }
            }
            // Combine config and user_details data
            $finalData = [];
            foreach ($configData as $config) {
                $configUserId = $config['user_id'] ?? null;
                $targetShopId = (string)($config['target_shop_id'] ?? '');
                $errorCode = $config['error_code'] ?? '';

                if (empty($configUserId) || empty($targetShopId)) {
                    continue;
                }

                // Check if user exists in userDetailsMap and has this shop as inactive
                if (isset($userDetailsMap[$configUserId]) && 
                    isset($userDetailsMap[$configUserId]['inactive_shop_id'][$targetShopId])) {
                    
                    $userInfo = $userDetailsMap[$configUserId];
                    $shopInfo = $userInfo['inactive_shop_id'][$targetShopId];
                    $marketplaceId = $shopInfo['marketplace_id'] ?? '';
                    $sellerId = $shopInfo['seller_id'] ?? '';
                    
                    // Get marketplace name
                    $country = 'N/A';
                    if (!empty($marketplaceId) && isset(\App\Amazon\Components\Common\Helper::MARKETPLACE_NAME[$marketplaceId])) {
                        $country = \App\Amazon\Components\Common\Helper::MARKETPLACE_NAME[$marketplaceId];
                    }

                    // Get inactive_from date (updated_at or created_at)
                    $inactiveFrom = $config['updated_at'] ?? $config['created_at'] ?? '';

                    // Create unique key using shop_id and error_code to handle multiple error codes for same shop
                    $uniqueKey = $targetShopId . '_' . $errorCode;

                    $finalData[$uniqueKey] = [
                        'shop_id' => $targetShopId,
                        'user_id' => $configUserId,
                        'username' => $userInfo['username'],
                        'email' => $userInfo['email'],
                        'plan_tier' => $userInfo['plan_tier'],
                        'country' => $country,
                        'error_code' => $errorCode,
                        'inactive_from' => $inactiveFrom,
                        'marketplace_id' => $marketplaceId,
                        'seller_id' => $sellerId,
                    ];
                }
            }

            // Handle auto_disconnect filter - only show default plan_tier users
            if ($autoDisconnect) {
                $finalData = array_filter($finalData, function($item) {
                    return ($item['plan_tier'] ?? 'N/A') === 'default';
                });
            }
            if(empty($finalData)) {
                return [
                    'success' => false,
                    'data' => 'No data found with this filters'
                ];
            }
            // Handle sorting
            if (!empty($sort)) {
                if (isset($sort['plan_tier'])) {
                    // Filter by plan_tier
                    $planTierFilter = $sort['plan_tier'];
                    $finalData = array_filter($finalData, function($item) use ($planTierFilter) {
                        return ($item['plan_tier'] ?? 'N/A') === $planTierFilter;
                    });
                }

                if (isset($sort['inactive_from'])) {
                    // Sort by inactive_from
                    $sortOrder = $sort['inactive_from']; // 1 = oldest to newest, -1 = newest to oldest
                    uasort($finalData, function($a, $b) use ($sortOrder) {
                        $dateA = strtotime($a['inactive_from'] ?? '');
                        $dateB = strtotime($b['inactive_from'] ?? '');
                        
                        if ($sortOrder == 1) {
                            return $dateA <=> $dateB; // Oldest to newest
                        } else {
                            return $dateB <=> $dateA; // Newest to oldest
                        }
                    });
                }
            }

            // If auto_disconnect, sort by inactive_from (oldest first) after filtering
            if ($autoDisconnect) {
                uasort($finalData, function($a, $b) {
                    $dateA = strtotime($a['inactive_from'] ?? '');
                    $dateB = strtotime($b['inactive_from'] ?? '');
                    return $dateA <=> $dateB; // Oldest to newest
                });
            }
            return [
                'success' => true,
                'data' => $finalData,
                'total' => $totalCount
            ];

        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception getInactiveAccounts(): ' . $e->getMessage(), 'error', 'exception.log');
            die('here');
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
}
