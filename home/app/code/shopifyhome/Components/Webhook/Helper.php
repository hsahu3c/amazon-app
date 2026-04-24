<?php

namespace App\Shopifyhome\Components\Webhook;

use App\Connector\Models\QueuedTasks;
use App\Core\Components\Base;
use App\Core\Models\User;
use Exception;
use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\UserEvent;
use DateTime;

class Helper extends Base
{
    protected $logFile;
    protected $appTag;
    protected $destinationData;
    protected $target;
    const MARKETPLACE = 'shopify';

    /** Batch size for chunked $unset of sales_channel on product_container (avoids one huge updateMany). */
    private const UNSET_SALES_CHANNEL_BATCH = 500;

    public function getHomeShopId()
    {
        $shops = $this->di->getUser()->shops ?? [];
        foreach ($shops as $value) {
            if (isset($value['marketplace']) && $value['marketplace'] == 'shopify') {
                return $value['_id'] ?? false;
            }
        }

        return false;
    }

    public function handleBackupSync($sqsData = [])
    {
        $logFile = "shopify/handleBackupSync/" . date('d-m-Y') . '.log';
        if (!empty($sqsData['action']) && !empty($sqsData['date'])) {
            $userId = $this->di->getUser()->id;
            $shops = $this->di->getUser()->shops ?? [];
            if (!empty($shops)) {
                $shopData = [];
                foreach ($shops as $shop) {
                    if ($shop['marketplace'] == self::MARKETPLACE && $shop['apps'][0]['app_status'] == 'active' &&
                    !isset($shop['store_closed_at'])) {
                        $shopData = $shop;
                        break;
                    }
                }
                if (!empty($shopData)) {
                    $homeShopId = $shopData['_id'];
                    if ($sqsData['action'] == 'inventory') {
                        if(isset($sqsData['sync_to_target']) && $sqsData['sync_to_target']) {
                            $this->initateBulkInventorySyncProcess($sqsData, $shopData);
                            return true;
                        }
                        $formattedDate = (new DateTime($sqsData['date']))->format('Y-m-d\TH:i:s\Z');
                        $rawBody = [
                            "source" => [
                                "marketplace" => self::MARKETPLACE,
                                "shopId" => $homeShopId,
                            ],
                            "data" => [
                                'user_id' => $userId,
                                'filterType' => 'bulkinventory',
                                'updated_at' => $formattedDate
                            ]
                        ];
                        $product = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
                        $product->initiateImport($rawBody, $userId);
                    } elseif ($sqsData['action'] == 'shipment') {
                        $shopifyHelper = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Helper');
                        $date = $sqsData['date'];
                        $orderFetchParams = [
                            'shop_id'   => $shopData['remote_shop_id'],
                            'status'    => 'any',
                            'fulfillment_status' => 'shipped,partial',
                            'updated_at_min' => $date,
                            'limit' => 250,
                            'app_code' => 'shopify'
                        ];
                        if (isset($sqsData['next'])) {
                            $orderFetchParams['next'] = $sqsData['next'];
                        }
                        $remoteResponse = $shopifyHelper->sendRequestToShopify('/order', [], $orderFetchParams, 'GET');
                        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                            $orders = [];
                            $orderIds = [];
                            if (!empty($remoteResponse['data'])) {
                                if (isset($remoteResponse['cursors']) && isset($remoteResponse['cursors']['next']) && !empty($remoteResponse['cursors']['next'])) {
                                    $sqsData['acton'] = 'shipment';
                                    $sqsData['next'] = $remoteResponse['cursors']['next'];
                                    $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                                    $sqsHelper->pushMessage($sqsData);
                                }
                                foreach ($remoteResponse['data'] as $value) {
                                    $id = strval($value['id']);
                                    $orders[$id] = $value;
                                    $orderIds[] = strval($id);
                                }

                                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                                $orderContainer = $mongo->getCollectionForTable('order_container');
                                $sourceModel = $this->di->getObjectManager()->get('App\\Connector\\Models\\SourceModel');
                                $orderParams = [
                                    "user_id" => $userId,
                                    "object_type" => ['$in' => ['source_order', 'target_order']],
                                    "marketplace" => self::MARKETPLACE,
                                    'marketplace_shop_id' => $homeShopId,
                                    "marketplace_reference_id" => ['$in' => $orderIds],
                                    "status" => 'Created',
                                    "shipping_status" => ['$exists' => false]
                                ];
                                $unsyncedOrderIds = $orderContainer->distinct("marketplace_reference_id", $orderParams);
                                if (!empty($unsyncedOrderIds)) {
                                    $webhookError = $mongo->getCollectionForTable('webhook_backup_sync');
                                    $webhookError->insertOne(
                                        [
                                            'user_id' => $userId,
                                            'shop_id' => $homeShopId,
                                            'marketplace' => self::MARKETPLACE,
                                            'action' => $sqsData['action'],
                                            'order_ids' => $unsyncedOrderIds,
                                            'created_at' => date('c')
                                        ]
                                    );
                                    foreach ($unsyncedOrderIds as $value) {
                                        $order = $orders[$value];
                                        foreach ($order['fulfillments'] as $fulfillment) {
                                            $prepareData = [
                                                'shop' => $shopData['myshopify_domain'],
                                                'data' => $fulfillment,
                                                'user_id' => $userId,
                                                'shop_id' => $homeShopId,
                                                'type' => "full_class",
                                                'class_name' => '\\App\\Connector\\Models\\SourceModel',
                                                'method' => 'triggerWebhooks',
                                                'action' => 'fulfillments_update',
                                                'marketplace' => self::MARKETPLACE

                                            ];
                                            $sourceModel->triggerWebhooks($prepareData);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                $this->di->getLog()->logContent('Shop not found, User: ' . json_encode($userId), 'info', $logFile);
            }
        } else {
            $this->di->getLog()->logContent('Unable to start process as require params (action or date) missing', 'info', $logFile);
        }
        return true;
    }

    /**
     * initateBulkInventorySyncProcess function
     * Function reponsibe to syncing inventory from Shopify to app
     * from the desired given date.
     * @param string $data[].{user_id,date,app_tag} required
     * @param array shop
     * @return bool
     */
    public function initateBulkInventorySyncProcess($data, $shop)
    {
        $userId = $data['user_id'];
        $formattedDate = (new DateTime($data['date']))->format('Y-m-d\TH:i:s\Z');
        if (!empty($shop)) {
            $appCode = $shop['apps'][0]['code'] ?? 'default';
            $appTag = $data['app_tag'] ?? $this->di->getAppCode()->getAppTag();
            $this->di->getAppCode()->setAppTag($appTag);
            $appTag = $this->di->getAppCode()->getAppTag();
            $processCode = 'bulk_sync_inventory';
            $queueData = [
                'user_id' => $userId,
                'message' => "Just a moment! We're retrieving your products from the source catalog. We'll let you know once it's done.",
                'process_code' => $processCode,
                'marketplace' => self::MARKETPLACE
            ];
            $queuedTask = new QueuedTasks;
            $queuedTaskId = $queuedTask->setQueuedTask($shop['_id'], $queueData);
            if (!$queuedTaskId) {
                return ['success' => false, 'message' => 'BulkInventory sync is already in progress. Please check notification for updates.'];
            }
            if (isset($queuedTaskId['success']) && !$queuedTaskId['success']) {
                return $queuedTaskId;
            }

            $shop['frontend_request']['data']['fire_chunk_event'] = true;

            $prepareData = [
                'type' => 'full_class',
                'appCode' => $appCode,
                'appTag' => $appTag,
                'class_name' => \App\Shopifyhome\Components\Product\Vistar\Route\Requestcontrol::class,
                'method' => 'handleImport',
                'queue_name' => 'shopify_bulk_sync_inventory',
                'own_weight' => 100,
                'user_id' => $userId,
                'data' => [
                    'operation' => 'make_request',
                    'user_id' => $userId,
                    'remote_shop_id' => $shop['remote_shop_id'],
                    'app_code' => $appCode,
                    'individual_weight' => 1,
                    'feed_id' => $queuedTaskId,
                    'webhook_present' => true,
                    'temp_importing' => false,
                    'isImported' => false,
                    'shop' => $shop,
                    'process_code' => 'bulk_sync_inventory',
                    'extra_data' => [
                        'filterType' => 'bulkinventory',
                        'updated_at' => $formattedDate
                    ]
                ],
            ];
            $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Vistar\Data::class)
                ->pushToQueue($prepareData);
        }
        return true;
    }

    public function setDiForUser($user_id)
    {
        try {
            $getUser = User::findFirst([['_id' => $user_id]]);
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

    /**
     * validateUserGraphQLWebhooks function
     * Function validating users whose GraphQL Webhooks
     * are not registered by using SQS
     * @param array $data
     * @param required (user_id,username)
     * @return array
     */
    public function validateUserGraphQLWebhooks($data)
    {
        $logFile = "shopifyWebhookSubscription/script/" . date('d-m-Y') . '.log';
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $this->di->getLog()->logContent('Username : '
            . json_encode($data['username'], true), 'info', $logFile);
        $marketplace = $this->di->getObjectManager()
            ->get(Shop::class)->getUserMarkeplace();
        $remoteData = $this->getToken($data['username']);
        if (!$remoteData) {
            $this->di->getLog()->logContent('Token not found UserId: ' . json_encode($userId), 'info', $logFile);
        } else {
            $restWebhookFound = false;
            if (!empty($remoteData)) {
                $token = $remoteData['apps'][0]['token'];
                $getWebhookData = $this->getWebhook($data['username'], $token);
                if (isset($getWebhookData['success']) && $getWebhookData['success']) {
                    if (empty($getWebhookData['data'])) {
                        $message = 'No Webhooks Registered';
                        $this->di->getLog()->logContent($message . json_encode($userId), 'info', $logFile);
                    } else {
                        $graphqlBaseUrl = $this->di->getConfig()->get('graphql_base_url');
                        if (!empty($graphqlBaseUrl)) {
                            $baseWebhookUrl = $this->di->getConfig()->get('graphql_base_url')->get($marketplace);
                        } else {
                            $message = 'graphql_base_url key is missing in config';
                            $this->di->getLog()->logContent('Config Error: ' . $message, 'critical', $logFile);
                            return ['success' => false, 'message' => $message];
                        }

                        foreach ($getWebhookData['data'] as $webhook) {
                            if ($webhook['address'] != $baseWebhookUrl) {
                                $restWebhookFound = true;
                                $this->di->getLog()->logContent('Rest Webhook Found UserId: ' . json_encode($data['user_id']), 'info', $logFile);
                                break;
                            }
                        }

                        if (!$restWebhookFound) {
                            $this->setWebhookTypeKey($userId, $marketplace);
                        }

                        return ['success' => true, 'message' => 'User Processed Successfully!!'];
                    }
                } else {
                    $message = 'Unable to fetch webhook Error: ';
                    $this->di->getLog()->logContent($message . json_encode($getWebhookData), 'info', $logFile);
                }
            } else {
                $message = 'Empty response from apps_shops';
                $this->di->getLog()->logContent($message, 'info', $logFile);
            }
        }

        return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
    }

    /**
     * validateShopGraphQLWebhooks function
     * If webhooks with a different baseURL are detected,
     * the function migrates them to use GraphQL.
     * @param array $shop
     * @return array
     */

    public function validateShopGraphQLWebhooks($shop)
    {
        $graphqlBaseUrl = $this->di->getConfig()->get('graphql_base_url');
        if (empty($graphqlBaseUrl)) {
            return ['success' => false, 'message' => 'Unable to migrate as graphql_base_url not found in config'];
        }
        $logFile = "shopify/validateGraphQLWebhook/" . date('d-m-Y') . '.log';
        $baseWebhookUrl = $this->di->getConfig()->get('graphql_base_url')->get($shop['marketplace']);
        if (!empty($baseWebhookUrl)) {
            $userId = $this->di->getUser()->id;
            $toBeMigrated = false;
            $webhookIds = [];
            $getWebhookData = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('shopify', true)
                ->call('webhook', [], ['shop_id' => $shop['remote_shop_id']], 'GET');
            if (isset($getWebhookData['success']) && $getWebhookData['success']) {
                if (empty($getWebhookData['data'])) {
                    $message = 'No Webhooks Registered';
                    $toBeMigrated = true;
                } else {
                    foreach ($getWebhookData['data'] as $webhook) {
                        if ($webhook['address'] != $baseWebhookUrl) {
                            $message = 'Different base url found';
                            $toBeMigrated = true;
                            break;
                        }
                    }
                    $webhookIds = array_column($getWebhookData['data'], 'id');
                }
                $appCode = $shop['apps'][0]['code'] ?? 'default';
                if ($toBeMigrated) {
                    $this->di->getLog()->logContent("Migration Process Started as $message for user: " . json_encode($userId), 'info', $logFile);
                    if (!empty($webhookIds)) {
                        $specifics = [
                            'shop_id' => $shop['remote_shop_id'],
                            'app_code' => $appCode,
                            'ids' => implode(",", $webhookIds)
                        ];
                        $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                            ->init('shopify', true)
                            ->call('webhook', [], $specifics, 'DELETE');
                    }
                    if (!empty($shop['apps'][0]['webhooks'])) {
                        $dynamoWebhookIds = array_column($shop['apps'][0]['webhooks'], 'dynamo_webhook_id');
                        $remoteData = [
                            'shop_id' => $shop['remote_shop_id'],
                            'subscription_ids' => $dynamoWebhookIds,
                            'force_dynamo_delete' => true,
                            'app_code' => "cedcommerce"
                        ];
                        $bulkDeleteSubscriptionResponse = $this->di->getObjectManager()
                            ->get("\App\Connector\Components\ApiClient")
                            ->init('cedcommerce', true)
                            ->call('bulkDelete/subscription', [], $remoteData, 'DELETE');
                        if (isset($bulkDeleteSubscriptionResponse['success']) && !$bulkDeleteSubscriptionResponse['success']) {
                            $this->di->getLog()->logContent('Bulk Delete Subscription Response Error: '
                                . json_encode([
                                    'shop' => $shop,
                                    'remote_response' => $bulkDeleteSubscriptionResponse
                                ]), 'info', $logFile);
                        }
                        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                        $userDetails = $mongo->getCollectionForTable('user_details');
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
                    }
                }
                $shop['apps'][0]['webhooks'] = [];
                $connectorModel = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel");
                $appDeleteDestinationResponse = $this->di->getObjectManager()->get(UserEvent::class)->createDestination($shop, 'user_app');
                $userAppDestinationId = $appDeleteDestinationResponse['success'] ? $appDeleteDestinationResponse['destination_id'] : false;
                if ($userAppDestinationId) {
                    $destinationWise[] = [
                        'destination_id' => $userAppDestinationId,
                        'event_codes' =>  ["app_delete"]
                    ];
                    $connectorModel->routeRegisterWebhooks($shop, $shop['marketplace'], $appCode, false, $destinationWise);
                } else {
                    $this->di->getLog()->logContent('Unable to Create/Get user_app destination for user_id=> ' . json_encode($userId, true), 'info', $logFile);
                }
                if ((!empty($shop['targets']) || !empty($shop['sources']))) {
                    $createDestinationResponse = $this->di->getObjectManager()->get(UserEvent::class)->createDestination($shop);
                    $destinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                    if ($destinationId) {
                        $connectorModel->routeRegisterWebhooks(
                            $shop,
                            $shop['marketplace'],
                            $appCode,
                            $destinationId
                        );
                    } else {
                        $this->di->getLog()->logContent('Unable to Create/Get user destination for user_id=> ' . json_encode($userId, true), 'info', $logFile);
                    }
                }
            } else {
                $this->di->getLog()->logContent('Unable to fetch webhook data Error: '
                    . json_encode([
                        'shop' => $shop,
                        'remote_response' => $getWebhookData
                    ]), 'info', $logFile);
            }
        } else {
            $this->di->getLog()->logContent('baseUrl not found for this marketplace: '
                . json_encode($shop['marketplace'], true), 'info', $logFile);
        }
        return ['success' => true, 'message' => 'Process Completed'];
    }

    /**
     * migrateRestToGraphQLWebhook function
     * Function migrating shopify rest webhook to
     * graphql webhook subscription using SQS Approach
     * @param array $params
     * @return array
     */
    public function migrateRestToGraphQLWebhookSqsProcess($params = [])
    {
        $activePage = $params['page'] ?? 1;
        $this->logFile = "shopifywebhooksubscription/script/" . date('d-m-Y') . '/page-'.$activePage.'.log';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $migrationContainer = $mongo->getCollectionForTable('webhook_migration_container');
        $this->target = $params['target'] ?? null;
        $this->appTag = $params['appTag'] ?? 'default';
        if (!$this->di->getCache()->get('completed_pages')) {
            $getData = $migrationContainer->findOne(['process' => 'webhook', 'page_completed' => ['$exists' => true]]);
            if (!empty($getData)) {
                $this->di->getCache()->set("completed_pages", $getData['page_completed']);
            } else {
                $this->di->getCache()->set("completed_pages", 0);
            }
        }
        $completedPages = $this->di->getCache()->get('completed_pages');
        if ($completedPages >= $activePage) {
            $this->di->getLog()->logContent('This page already processed: ' . $activePage, 'info', $this->logFile);
            return ['success' => true, 'message' => 'This page already processed'];
        }
        $this->di->getLog()->logContent('Starting Page: ' . $activePage, 'info', $this->logFile);
        try {
            $limit = 1000;
            $query = $mongo->getCollectionForTable('user_details');
            if ($this->target) {
                $pipline = [
                    [
                        '$match' => [
                            'shops' => [
                                '$elemMatch' => [
                                    'apps.app_status' => 'active',
                                    'marketplace' => $this->target
                                ],
                            ],
                        ]
                    ],
                    ['$project' => ['user_id' => 1, 'username' => 1]],
                    ['$sort' => ['_id' => 1]],
                    ['$limit' => $limit]
                ];
            } else {
                $pipline = [
                    [
                        '$match' => [
                            'shops.1' => ['$exists' => false],
                            'shops' => [
                                '$elemMatch' => [
                                    'apps.app_status' => 'active',
                                    'marketplace' => self::MARKETPLACE
                                ],
                            ],
                        ]
                    ],
                    ['$project' => ['user_id' => 1, 'username' => 1]],
                    ['$sort' => ['_id' => 1]],
                    ['$limit' => $limit]
                ];
            }
            if (!empty($params['_id'])) {
                if (isset($params['_id']['$oid'])) {
                    $id = new \MongoDB\BSON\ObjectId($params['_id']['$oid']);
                } else {
                    $id = $params['_id'];
                }
                $preparePipline = ['$match' => ['_id' => ['$gt' => $id]]];
                array_splice($pipline, 0, 0, [$preparePipline]);
            }
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $userData = $query->aggregate($pipline, $arrayParams)->toArray();
            if (!empty($userData)) {
                $this->destinationData = $this->di->getConfig()->get('destination_data');
                if (empty($this->destinationData)) {
                    $this->di->getLog()->logContent('Destination Data not found', 'info', $this->logFile);
                    return ['success' => false, 'message' => 'Destination Data not found'];
                }
                $this->destinationData = ($this->destinationData)->toArray();
                foreach ($userData as $user) {
                    $createResponse = [];
                    $this->di->getLog()->logContent('Username : '
                        . json_encode($user['username']), 'info', $this->logFile);
                    $remoteData = $this->getToken($user['username']);
                    if (!$remoteData) {
                        $this->di->getLog()->logContent('Token not found', 'info', $this->logFile);
                    } else {
                        $token = $remoteData['apps'][0]['token'];
                        $remoteShopId = $remoteData['_id'];
                        $graphqlBaseUrl = $this->di->getConfig()->get('graphql_base_url');
                        if (!empty($graphqlBaseUrl)) {
                            $baseWebhookUrl = $this->di->getConfig()->get('graphql_base_url')->get(self::MARKETPLACE);
                        } else {
                            $message = 'graphql_base_url key is missing in config';
                            $this->di->getLog()->logContent('Config Error: ' . $message, 'critical', $this->logFile);
                            return ['success' => false, 'message' => $message];
                        }

                        $createResponse = $this->registerWebhook($user, $token, $baseWebhookUrl);
                        if (isset($createResponse['success']) && $createResponse['success']) {
                            if (!empty($createResponse['data'])) {
                                $this->updateWebhookDataInDB(
                                    $user,
                                    $token,
                                    $baseWebhookUrl,
                                    $remoteShopId,
                                    $createResponse['data']
                                );
                            } else {
                                $this->di->getLog()->logContent('Error From API call: '
                                    . json_encode($user['user_id']), 'info', $this->logFile);
                            }
                        } else {
                            $this->di->getLog()->logContent('registerWebhook function Response Error: '
                                . json_encode($createResponse['message']), 'info', $this->logFile);
                            break;
                        }
                    }
                }
                $this->di->getCache()->set("completed_pages", $activePage);
                $migrationContainer->updateOne(
                    ['process' => 'webhook'],
                    [
                        '$set' => ['page_completed' => $activePage],
                    ],
                    ['upsert' => true]
                );
                $this->di->getLog()->logContent('Chunk Completed for Active Page: ' . $activePage, 'info', $this->logFile);
                $endUser = end($userData);
                $activePage = $activePage + 1;
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => '\\App\\Shopifyhome\\Components\\Webhook\\Helper',
                    'method' => 'migrateRestToGraphQLWebhook',
                    'queue_name' => 'migrate_rest_to_graphql_webhook',
                    'user_id' => $params['user_id'] ?? $this->di->getUser()->id,
                    '_id' => $endUser['_id'],
                    'page' => $activePage,
                    'delay' => 5
                ];
                $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')
                    ->pushMessage($handlerData);
                $this->di->getLog()->logContent('Process Completed for this page', 'info', $this->logFile);
            } else {
                $this->di->getLog()->logContent('All pages completed', 'info', $this->logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception: ' . json_encode($e), 'info', $this->logFile);
        }
    }

    /**
     * migrateRestToGraphQLWebhookCliProcess function
     * Function migrating shopify rest webhook to
     * graphql webhook subscription using CLI approach
     * @return array
     */
    public function migrateRestToGraphQLWebhookCliProcess()
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $cronTask = $mongo->getCollectionForTable('cron_tasks');
            $activePage = 1;
            $userData = $this->fetchUserForProcessing($activePage);
            $this->logFile = "shopifywebhooksubscription/script/" . date('d-m-Y') . '/default.log';
            while (!empty($userData)) {
                $this->logFile = "shopifywebhooksubscription/script/" . date('d-m-Y') . '/page-' . $activePage . '.log';
                $lastProcessedUserId = '';
                $this->destinationData = $this->di->getConfig()->get('destination_data');
                if (empty($this->destinationData)) {
                    $this->di->getLog()->logContent('Destination Data not found', 'info', $this->logFile);
                    return ['success' => false, 'message' => 'Destination Data not found'];
                }
                $this->destinationData = ($this->destinationData)->toArray();
                foreach ($userData as $user) {
                    $createResponse = [];
                    $this->di->getLog()->logContent('Username : '
                        . json_encode($user['username']), 'info', $this->logFile);
                    $remoteData = $this->getToken($user['username']);
                    if (!$remoteData) {
                        $this->di->getLog()->logContent('Token not found', 'info', $this->logFile);
                    } else {
                        $token = $remoteData['apps'][0]['token'];
                        $remoteShopId = $remoteData['_id'];
                        $graphqlBaseUrl = $this->di->getConfig()->get('graphql_base_url');
                        if (!empty($graphqlBaseUrl)) {
                            $baseWebhookUrl = $this->di->getConfig()->get('graphql_base_url')->get(self::MARKETPLACE);
                        } else {
                            $message = 'graphql_base_url key is missing in config';
                            $this->di->getLog()->logContent('Config Error: ' . $message, 'critical', $this->logFile);
                            return ['success' => false, 'message' => $message];
                        }

                        $createResponse = $this->registerWebhook($user, $token, $baseWebhookUrl);
                        if (isset($createResponse['success']) && $createResponse['success']) {
                            if (!empty($createResponse['data'])) {
                                $this->updateWebhookDataInDB(
                                    $user,
                                    $token,
                                    $baseWebhookUrl,
                                    $remoteShopId,
                                    $createResponse['data']
                                );
                            } else {
                                $this->di->getLog()->logContent('Error From API call: '
                                    . json_encode($user['user_id']), 'info', $this->logFile);
                            }
                        } else {
                            $this->di->getLog()->logContent('registerWebhook function Response Error: '
                                . json_encode($createResponse['message']), 'info', $this->logFile);
                            break;
                        }
                    }
                    $lastProcessedUserId =  $user['user_id'];
                    $cronTask->updateOne(
                        ['task' => 'webhook_migration'],
                        [
                            '$set' => ['last_traversed_user_id' => $user['user_id'], 'completed_page' => $activePage],
                        ],
                        ['upsert' => true]
                    );
                }
                ++$activePage;
                $userData = $this->fetchUserForProcessing($activePage, $lastProcessedUserId);
                $this->di->getLog()->logContent('Process Completed for this page', 'info', $this->logFile);
            }
            $this->di->getLog()->logContent('Whole Process Completed no users found', 'info', $this->logFile);
            return ['success' => true, 'message' => 'Process Completed!!'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception: ' . json_encode($e), 'info', $this->logFile);
        }
    }

    /**
     * fetchUserForProcessing function
     * Fetching userData which has to be processed
     * using CRON task
     * @return array
     */
    public function fetchUserForProcessing(&$activePage, $lastProcessedUserId = null)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetails = $mongo->getCollectionForTable('user_details');
        if (is_null($lastProcessedUserId)) {
            $cronTask = $mongo->getCollectionForTable('cron_tasks');
            $taskData = $cronTask->findOne(['task' => 'webhook_migration'], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            if (!empty($taskData)) {
                $activePage = ++$taskData['completed_page'];
                $lastProcessedUserId = $taskData['last_traversed_user_id'];
            }
        }
        if (!empty($lastProcessedUserId)) {
            $userData = $userDetails->find(
                [
                    '_id' => ['$gt' => new \MongoDB\BSON\ObjectId($lastProcessedUserId)],
                    'shops.1' => ['$exists' => false],
                    'shops' => [
                        '$elemMatch' => [
                            'apps.app_status' => 'active',
                            'marketplace' => self::MARKETPLACE
                        ],
                    ]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => 500,
                    'projection' => ['user_id' => 1, 'username' => 1],
                    'sort' => ['_id' => 1]
                ]
            )->toArray();
        } else {
            $userData = $userDetails->find(
                [
                    'shops.1' => ['$exists' => false],
                    'shops' => [
                        '$elemMatch' => [
                            'apps.app_status' => 'active',
                            'marketplace' => self::MARKETPLACE
                        ],
                    ]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => 500,
                    'projection' => ['user_id' => 1, 'username' => 1],
                    'sort' => ['_id' => 1]
                ]
            )->toArray();
        }
        return $userData;
    }

    /**
     * registerWebhook function
     * Registering graphQL webhook subscription
     * @param array $userData
     * @param string $token
     * @param string $graphqlBaseUrl
     * @return array
     */
    public function registerWebhook(array $userData, $token, string $graphqlBaseUrl): array
    {
        $storeUrl = $userData['username'];
        $userId = $userData['user_id'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $webhookQuery = $mongo->getCollectionForTable('webhook_migration_container');
        $webhookData = [];
        $webhooks = [];
        $errors = [];
        if (!$this->target) {
            $webhooks[] = 'app_delete';
        } else {
            $getWebhookConfig = $this->di->getConfig()->webhook->get($this->appTag)->toArray();
            $webhooks = array_column($getWebhookConfig, "action");
        }
        if (!empty($webhooks)) {
            $this->di->getLog()->logContent('Registering Webhooks...', 'info', $this->logFile);
            foreach ($webhooks as $webhook) {
                if ($webhook == 'app_delete') {
                    $webhook = 'APP_UNINSTALLED';
                } else {
                    $webhook = strtoupper((string) $webhook);
                }

                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => "https://{$storeUrl}/admin/api/2024-04/graphql.json",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => '
                    mutation {
                        eventBridgeWebhookSubscriptionCreate(
                          topic: ' . $webhook . '
                          webhookSubscription: {
                            arn: "' . $graphqlBaseUrl . '"
                            format: JSON
                          }
                        ) {
                          webhookSubscription {
                            id
                          }
                          userErrors {
                            message
                          }
                        }
                      }',
                    CURLOPT_HTTPHEADER => array(
                        "X-Shopify-Access-Token: $token",
                        'Content-Type: application/graphql'
                    ),
                ));
                $response = json_decode(curl_exec($curl), true);
                curl_close($curl);
                if ($webhook == 'APP_UNINSTALLED') {
                    $webhook = 'app_delete';
                } else {
                    $webhook = strtolower($webhook);
                }

                if (isset($response['data']['eventBridgeWebhookSubscriptionCreate'])) {
                    $eventSubscription = $response['data']['eventBridgeWebhookSubscriptionCreate'];
                    if (isset($eventSubscription['webhookSubscription']['id'])) {
                        $parts = explode('/', (string) $eventSubscription['webhookSubscription']['id']);
                        $filteredParts = array_filter($parts);
                        $webhookData[] = [
                            'code' => $webhook,
                            'marketplace_subscription_id' => end($filteredParts)
                        ];
                    } elseif (
                        !empty($eventSubscription['userErrors'])
                        && str_contains((string) $eventSubscription['userErrors'][0]['message'], "topic has already been taken")
                    ) {
                        $webhookData[] = [
                            'code' => $webhook,
                            'message' => 'Topic Already registered'
                        ];
                    } else {
                        $errors[] = [
                            'code' => $webhook,
                            'error' => $response
                        ];
                    }
                } elseif (isset($response['errors'])) {
                    $errors[] = [
                        'code' => $webhook,
                        'error' => $response['errors']
                    ];
                    break;
                } else {
                    $errors[] = [
                        'code' => $webhook,
                        'error' => $response
                    ];
                }
            }

            $prepareData = [
                'user_id' => $userId,
                'username' => $storeUrl,
                'marketplace' => self::MARKETPLACE,
                'webhooks' => $webhookData
            ];
            if (!empty($errors)) {
                $prepareData['errors'] = $errors;
            }
            $webhookQuery->insertOne($prepareData);
            return ['success' => true, 'data' => $webhookData];
        }
        $message = 'Unable to fetch webhooks from config';

        return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
    }

    /**
     * updateWebhookDataInDB function
     * Deleting old webhook data and inserting new webhook data
     * @param array $userData
     * @param string $token
     * @param string $graphqlBaseUrl
     * @param string $remoteShopId
     * @param array $graphQlWebhookData
     * @return void
     */
    public function updateWebhookDataInDB(array $userData, $token, $graphqlBaseUrl, $remoteShopId, $graphQlWebhookData): void
    {
        $getWebhookData = $this->getWebhook($userData['username'], $token);
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $userDetails = $mongo->getCollection("user_details");
        $shopifyHelper = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Helper::class);
        $shops = [];
        $shopData = [];
        $deletedId = [];
        $dynamoWebhookIds = [];
        $userWebhookData = [];
        if (isset($getWebhookData['success']) && $getWebhookData['success']) {
            $response = $this->setDiForUser($userData['user_id']);
            if (isset($response['success']) && $response['success']) {
                $shops = $this->di->getUser()->shops;
                if (!empty($shops)) {
                    foreach ($shops as $shop) {
                        if ($shop['remote_shop_id'] == $remoteShopId) {
                            $shopData = $shop;
                            break;
                        }
                    }

                    if (!empty($shopData)) {
                        $this->di->getLog()->logContent('Webhook Fetched', 'info', $this->logFile);
                        $options = [
                            'arrayFilters' => [
                                ['shop.marketplace' => $shopData['marketplace']]
                            ]
                        ];
                        $this->di->getLog()->logContent('Unregistering old webhooks...', 'info', $this->logFile);
                        foreach ($getWebhookData['data'] as $webhook) {
                            $shouldDelete = false;

                            if (!$this->target && $webhook['topic'] != 'app/uninstalled') {
                                $shouldDelete = true;
                            } elseif ($webhook['address'] != $graphqlBaseUrl) {
                                $shouldDelete = true;
                            }

                            if ($shouldDelete) {
                                if ($this->unRegisterWebhook($userData['username'], $webhook['id'], $token)) {
                                    $deletedId[] = $webhook['id'];
                                } else {
                                    $this->di->getLog()->logContent(
                                        'Unable to Delete Webhook for User: ' . json_encode($userData['username']) 
                                        . ' Webhook Id: ' . json_encode($webhook['id']),
                                        'info', 
                                        $this->logFile
                                    );
                                }
                            }
                        }

                        if (!empty($deletedId)) {
                            $this->di->getLog()->logContent('Webhook Unregistered Successfully!!', 'info', $this->logFile);
                            if (!empty($shopData['apps'][0]['webhooks'])) {
                                $appWebhooks = $shopData['apps'][0]['webhooks'] ?? [];
                                if (!empty($appWebhooks)) {
                                    foreach ($appWebhooks as $webhook) {
                                        $dynamoWebhookIds[] = $webhook['dynamo_webhook_id'] ?? '';
                                    }
                                }
                                if (!empty($dynamoWebhookIds)) {
                                    $remoteData = [
                                        'shop_id' => $remoteShopId,
                                        'subscription_ids' => $dynamoWebhookIds,
                                        'force_dynamo_delete' => true,
                                        'app_code' => "cedcommerce"
                                    ];
                                    $remoteResponse = $this->di->getObjectManager()
                                        ->get("\App\Connector\Components\ApiClient")
                                        ->init('cedcommerce', true)
                                        ->call('bulkDelete/subscription', [], $remoteData, 'DELETE');
                                    if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                                        $this->di->getLog()->logContent('Bulk Deletion Completed!!', 'info', $this->logFile);
                                        $appCode = $shopData['apps'][0]['code'] ?? 'default';
                                        $queueAppCode = $this->di->getConfig()->app_code ?? 'default';
                                        foreach ($getWebhookData['data'] as $qlWebhookData) {
                                            if ($qlWebhookData['address'] == $graphqlBaseUrl) {
                                                if (!$this->target) {
                                                    if ($qlWebhookData['topic'] == 'app/uninstalled') {
                                                        $destinationId = $this->destinationData['user_app'] ?? '';
                                                        $type = 'user_app';
                                                        $action = 'app_delete';
                                                    } else {
                                                        continue;
                                                    }
                                                } else if ($qlWebhookData['topic'] == 'app/uninstalled') {
                                                    $destinationId = $this->destinationData['user_app'] ?? '';
                                                    $type = 'user_app';
                                                    $action = 'app_delete';
                                                } else {
                                                    $destinationId = $this->destinationData['user'] ?? '';
                                                    $type = 'user';
                                                    $action = str_replace('/', '_', $qlWebhookData['topic']);
                                                }

                                                $prepareCifSubscriptionData[] = [
                                                    "marketplace" => self::MARKETPLACE,
                                                    "remote_shop_id" => $remoteShopId,
                                                    "subscription_id" => (string)$qlWebhookData['id'],
                                                    "event_code" => $qlWebhookData['topic'],
                                                    "destination_id" => $destinationId ?? '',
                                                    "type" => $type ?? '',
                                                    "marketplace_data" => [
                                                        "call_type" => "QL"
                                                    ],
                                                    "queue_data" => [
                                                        "type" => "full_class",
                                                        "class_name" => "\\App\\Connector\\Models\\SourceModel",
                                                        "method" => "triggerWebhooks",
                                                        "user_id" => $userData['user_id'],
                                                        "shop_id" => $shopData['_id'],
                                                        "action" => $action,
                                                        "queue_name" => $queueAppCode . '_' . $action,
                                                        "marketplace" => self::MARKETPLACE,
                                                        "app_code" => $appCode
                                                    ],
                                                    "app_code" => $appCode

                                                ];
                                                $prepareMarketplaceSubscriptionData[] = [
                                                    "marketplace" => "shopify",
                                                    "event_code" => $qlWebhookData['topic'],
                                                    "remote_shop_id" => $remoteShopId,
                                                    "destination_id" => "test_destination_id",
                                                    "marketplace_subscription_id" => (string)$qlWebhookData['id'],
                                                    "created_at" => date('Y-m-d H:i:s')
                                                ];
                                            }
                                        }
                                        $requestParams = [
                                            'shop_id' => $remoteShopId,
                                            'app_code' => $appCode,
                                            'cif_data' => $prepareCifSubscriptionData,
                                            'marketplace_data' => $prepareMarketplaceSubscriptionData
                                        ];
                                        $cedData = $shopifyHelper->sendRequestToShopify(
                                            'cedwebapi/data',
                                            [],
                                            $requestParams,
                                            'POST'
                                        );
                                        if (!empty($cedData['data'])) {
                                            $userWebhookData = $cedData['data'];
                                        } else {
                                            $userWebhookData = $graphQlWebhookData;
                                            $this->di->getLog()->logContent('Error While Inserting Data into Tables
                                                UserId: ' . json_encode($userData['user_id']) . 'Remote Response: '
                                                . json_encode($cedData), 'info', $this->logFile);
                                        }
                                        $this->di->getLog()->logContent('Data Deleted From All Tables!!', 'info', $this->logFile);
                                        $userDetails->updateOne(
                                            [
                                                "user_id" => $userData['user_id'],
                                                "shops" => [
                                                    '$elemMatch' => [
                                                        '_id' => $shopData['_id'],
                                                        'marketplace' => $shopData['marketplace']
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
                                            $options
                                        );
                                        $this->di->getLog()->logContent('Docs Inserted and  Updated!!', 'info', $this->logFile);
                                    } else {
                                        $this->di->getLog()->logContent('Error While Deleting Data from Tables: ' . json_encode($remoteResponse), 'info', $this->logFile);
                                    }
                                } else {
                                    $this->di->getLog()->logContent('dynamoWebhookIds not found for User: ' . $userData['user_id'], 'info', $this->logFile);
                                }
                            } else {
                                $this->di->getLog()->logContent('Shop or Webhooks error not found for User: ' . $userData['user_id'], 'info', $this->logFile);
                            }
                            $userDetails->updateOne(
                                [
                                    "user_id" => $userData['user_id'],
                                    "shops" => [
                                        '$elemMatch' => [
                                            '_id' => $shopData['_id'],
                                            'marketplace' => $shopData['marketplace']
                                        ]
                                    ]
                                ],
                                [
                                    '$addToSet' => [
                                        'shops.$[shop].apps.0.webhooks' => [
                                            '$each' => $userWebhookData
                                        ]
                                    ]
                                ],
                                $options
                            );
                        } else {
                            $this->di->getLog()->logContent("No Rest Webhook Found UserId: " . $userData['user_id'], 'info', $this->logFile);
                        }
                    } else {
                        $this->di->getLog()->logContent("Unable find shop user_id: " . $userData['user_id'], 'info', $this->logFile);
                    }
                }
            } else {
                $this->di->getLog()->logContent("Unable to set Di user_id:" . $userData['user_id'], 'info', $this->logFile);
            }
        } else {
            $this->di->getLog()->logContent("Unable to fetch webhook: " . $getWebhookData['message'], 'info', $this->logFile);
        }
    }

    /**
     * unRegisterWebhook function
     * Unregister webhook from shopify
     * @param string $storeUrl
     * @param string $id
     * @param string $token
     * @return bool
     */
    public function unRegisterWebhook($storeUrl, string $id, $token): bool
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://{$storeUrl}/admin/api/2024-04/graphql.json",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '
            mutation {
                webhookSubscriptionDelete(id: "gid://shopify/WebhookSubscription/' . $id . '") {
                    deletedWebhookSubscriptionId
                    userErrors {
                    field
                    message
                    }
                }
                }',
            CURLOPT_HTTPHEADER => array(
                "X-Shopify-Access-Token: $token",
                'Content-Type: application/graphql'
            ),
        ));
        $response = json_decode((curl_exec($curl)), true);
        curl_close($curl);
        if (isset($response['data']['webhookSubscriptionDelete'])) {
            $deleteSubscription = $response['data']['webhookSubscriptionDelete'];
            if (!empty($deleteSubscription['deletedWebhookSubscriptionId'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * getToken function
     * Fetch app_shops data from remote
     * based on shop_url
     * @param string $shop
     * @return array
     * @return bool
     */
    public function getToken($shop)
    {
        $db = $this->di->get('remote_db');
        if ($db) {
            $appsCollections = $db->selectCollection('apps_shop');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
            return $appsCollections->findOne(['shop_url' => $shop], $options);
        }
        return false;
    }

    /**
     * getWebhook function
     * Shopify getAll webhook direct call
     * @param string $storeUrl
     * @param string $token
     * @return array
     */
    public function getWebhook($storeUrl, $token)
    {
        $curl = curl_init();
        curl_setopt_array($curl, [CURLOPT_URL => "https://{$storeUrl}/admin/api/2024-04/webhooks.json", CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '', CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 0, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_CUSTOMREQUEST => 'GET', CURLOPT_HTTPHEADER => ["X-Shopify-Access-Token: {$token}"]]);

        $response = json_decode(curl_exec($curl), true);
        curl_close($curl);
        if (isset($response['webhooks']) && !empty($response['webhooks'])) {
            return ['success' => true, 'data' => $response['webhooks']];
        }

        return ['success' => false, 'message' => $response];
    }

    /**
     * setWebhookTypeKey function
     * To set webhook_type key in apps
     * @param string $userId
     * @param string $marketplace
     */
    private function setWebhookTypeKey($userId, $marketplace): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetails = $mongo->getCollectionForTable('user_details');
        $userDetails->updateOne(
            [
                'user_id' => $userId,
                'shops' => [
                    '$elemMatch' => [
                        'apps.app_status' => 'active',
                        'marketplace' => $marketplace
                    ]
                ]
            ],
            [
                '$set' => ['shops.$.apps.0.webhook_type' => 'QL']
            ]
        );
    }

    public function getActionByQueueName($queueName)
    {
        switch ($queueName) {
            case str_contains((string) $queueName, "inventory_levels_update"):
                $action = 'inventory';
                break;
            case str_contains((string) $queueName, "locations_create"):
            case str_contains((string) $queueName, "locations_update"):
            case str_contains((string) $queueName, "locations_delete"):
                $action = 'location';
                break;
            case str_contains((string) $queueName, "product_listings_remove"):
                $action = 'product_delete';
                break;
            case str_contains((string) $queueName, "app_delete"):
                $action = 'app_delete';
                break;
            default:
                $action = null;
        }
        return $action ?? null;
    }

    /**
     * handleProductWebhook function
     * For Remote Call - /bulk/operation for particular users
     * @param [array] $sqsData
     */
    public function handleProductWebhook($sqsData)
    {
        $userId = $sqsData['user_id'];
        $logFile = "shopify/productRemoveContingency/$userId/" . date('d-m-Y') . '.log';
        $remoteShopID = $sqsData['remote_shop_id'];
        $params = [
            'shop_id' => $remoteShopID,
            'type' => 'productwebhook',
        ];
        $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['feed_id'],0,"Just a moment! We're retrieving your products from the source catalog. We'll let you know once it's done.");
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', true)
            ->call('/bulk/operation', [], $params, 'POST');
        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
            $this->di->getLog()->logContent('Bulk operation remote_response: '.json_encode($remoteResponse), 'info', $logFile);
            $handlerData = [
                'type' => 'full_class',
                'class_name' => Helper::class,
                'method' => 'requestStatus',
                'user_id' => $sqsData['user_id'],
                'shop_id' => $sqsData['shop_id'] ?? '',
                'remote_shop_id' => $remoteShopID,
                'queue_name' => 'shopify_product_webhook_backup_sync',
                'data' => $remoteResponse['data'],
                'process_code' => 'shopify_product_remove',
                'marketplace' => 'shopify',
                'feed_id'=>$sqsData['feed_id']

            ];
            $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($handlerData);
            return ['success' => true, 'message' => 'Remote Response received'];
        } elseif (isset($remoteResponse['msg']) && is_string($remoteResponse['msg']) && str_contains($remoteResponse['msg'], 'A bulk query operation for this app and shop is already in progress')) {
            $message = "A bulk operation is already running error will retry again after 3 min";
            $this->di->getLog()->logContent("$message, remoteResponse: " . json_encode($remoteResponse ?? 'Something went wrong'), 'info', $logFile);
            $sqsData['delay'] = 180;
            $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($sqsData);
        } else {
            $this->removeEntryFromQueuedTask($sqsData['process_code']);
            $message = 'Sales Channel Unassigned Product(s) retreival failed';
            $this->updateActivityLog($sqsData, false, $message);
            $this->di->getLog()->logContent($message, 'info', $logFile);
        }

        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    /**
     * requestStatus function
     * For requesting remote response status in order to check JSONL file completed
     * @param [array] $sqsData
     */
    public function requestStatus($sqsData)
    {
        $userId = $sqsData['user_id'];
        $logFile = "shopify/productRemoveContingency/$userId/" . date('d-m-Y') . '.log';
        $remoteShopID = $sqsData['remote_shop_id'];
        $params = [
            'shop_id' => $remoteShopID,
            'id' => $sqsData['data']['id'],
        ];
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', true)
            ->call('/bulk/operation', [], $params, 'GET');
        if (isset($remoteResponse['data']['status']) && (($remoteResponse['data']['status'] == 'RUNNING') || ($remoteResponse['data']['status'] == 'CREATED'))) {
            $this->di->getLog()->logContent('Still Running..', 'info', $logFile);
            $retry_count = 0;
            if (isset($remoteResponse['data']['retry'])) {
                $retry_count = (int)$remoteResponse['data']['retry'];
            }

            $retry_count = $retry_count + 1;
            $sqsData['data']['retry'] = $retry_count;
            $sqsData['delay'] = 180;
            $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($sqsData);
            return true;
        } elseif (isset($remoteResponse['data']['status']) && $remoteResponse['data']['status'] == 'COMPLETED') {
            $this->di->getLog()->logContent('Status Completed: '.json_encode($remoteResponse), 'info', $logFile);
            if (isset($remoteResponse['data']['objectCount']) && $remoteResponse['data']['objectCount'] == 0) {
                $this->removeEntryFromQueuedTask($sqsData['process_code']);
                $this->updateActivityLog($sqsData, false, 'No products have a Sales Channel Assigned');
                return ['success' => true, 'message' => 'No products there with Sales Channel Assigned']; //if no products returning true
            }
            if (!empty($remoteResponse['data']['partialDataUrl'])) {
                $this->removeEntryFromQueuedTask($sqsData['process_code']);
                $this->updateActivityLog($sqsData, false, 'Partial Data URL Found.Terminating process');
                return ['success' => false, 'message' => 'Partial Data URL Found'];
            }
            if (strlen((string) $remoteResponse['data']['url']) == 0) {
                $this->removeEntryFromQueuedTask($sqsData['process_code']);
                $this->updateActivityLog($sqsData, false, 'Shopify Product JSONL not found');
                return ['success' => false, 'message' => 'URL not found'];
            }
            $url = $remoteResponse['data']['url'];
            $isFileSaved = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Vistar\Helper')->saveJSONLFile($url, 'product_remove_contingency');
            if (!$isFileSaved['success']) {
                $this->di->getLog()->logContent('PROCESS 00201 | Import | requestStatus save file | ERROR | Response  = ' . print_r($isFileSaved, true), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'vistar_product_import.log');
                $this->removeEntryFromQueuedTask($sqsData['process_code']);
                $this->updateActivityLog($sqsData, false, 'Unable to save JSONFile');
                return ['success' => false, 'message' => 'Unable to save JSONFile'];
            }
            $sqsData['data']['file_path'] = $isFileSaved['file_path'];
            unset($sqsData['delay']);
            $this->readJSONLFile($sqsData);
            return ['success' => true, 'message' => 'Product Sucessfully deleted'];
        } else {
            if (empty($remoteResponse) || isset($remoteResponse['message']) && $remoteResponse['message'] == 'Error Obtaining token from Remote Server. Kindly contact Remote Host for more details.') {
                $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($sqsData);
                return ['success' => true, 'message' => 'Requeue for remote response message'];
            }
            $this->removeEntryFromQueuedTask($sqsData['process_code']);
            $this->updateActivityLog($sqsData, false, 'Something went wrong');
            return ['success' => false, 'message' => 'Error Obtaining token from Remote Server'];
        }
    }

    /**
     * readJSONLFile function
     * Read JSONL File
     * @param [array] $sqsData
     */
    public function readJSONLFile($sqsData)
    {
        $filePath = $sqsData['data']['file_path'];
        $userId=$sqsData['user_id'];
        $logFile = "shopify/productRemoveContingency/$userId/" . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Reading JSONL File --: ', 'info', $logFile);
        if (!file_exists($filePath)) {
            $this->removeEntryFromQueuedTask($sqsData['process_code']);
            $this->updateActivityLog($sqsData, false, 'File not found');
            return ['success' => false, 'message' => 'File not found'];
        }
        if (!isset($sqsData['data']['total_rows'])) {
            $totalRows = $this->countJsonlLines($filePath);
            $sqsData['data']['total_rows'] = $totalRows;
        }
        $batchSize = 1000;
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->removeEntryFromQueuedTask($sqsData['process_code']);
            $this->updateActivityLog($sqsData, false, 'Unable to read JSONL File');
            return ['success' => false, 'message' => 'Unable to read JSONL File'];
        }

        $seek = $sqsData['data']['seek'] ?? 0;
        fseek($handle, $seek);
        $batch = [];
        $linesRead = 0;
        while (!feof($handle) && $linesRead < $batchSize) {
            $line = fgets($handle);
            if ($line === false) break;

            $line = trim($line);
            if ($line === '') continue;

            $data = json_decode($line, true);
            if (!is_array($data) || !isset($data['id'])) continue;

            $parts = explode('/', $data['id']);
            $productId = end($parts);
            $batch[] = $productId;
            $linesRead++;
        }
        if (!empty($batch)) {
            $assignKeyData = [
                'user_id' => $sqsData['user_id'],
                'shop_id' => $sqsData['shop_id'] ?? '',
                'remote_shop_id' => $sqsData['remote_shop_id'],
                'data' => $batch,
            ];
            $this->assignUniqueKey($assignKeyData);
            $seek = ftell($handle);
            $sqsData['data']['seek'] = $seek;
            $sqsData['method'] = 'readJSONLFile';
            $sqsData['data']['rows_processed'] = ($sqsData['data']['rows_processed'] ?? 0) + count($batch);
            $totalRows = $sqsData['data']['total_rows'];
            $progress = round(($sqsData['data']['rows_processed'] / $totalRows) * 50, 2);
            $sqsData['progress'] = $progress;
            $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['feed_id'],$progress,'Reading Shopify Products', false);
            $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($sqsData);
            fclose($handle);
        } else {
            $data = [
                'user_id' => $sqsData['user_id'],
                'shop_id' => $sqsData['shop_id'],
                'remote_shop_id' => $sqsData['remote_shop_id'],
                'feed_id'=>$sqsData['feed_id']
            ];
            unlink($filePath);
            $this->getContainerIds($data);
        }
        return [
            'success' => true,
            'message' => 'JSONL File reading sucessfully completed'
        ];
    }

    private function countJsonlLines($filePath)
    {
        $lineCount = 0;
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['success' => false, 'message' => 'File not found'];
        }

        while (!feof($handle)) {
            $line = fgets($handle);
            if (trim($line) !== '') {
                $lineCount++;
            }
        }

        fclose($handle);
        return $lineCount;
    }


    /**
     * assignUniqueKey function
     * Assigning Unique key to the products in which sales channel is assigned
     * @param [array] $sqsData
     */
   public function assignUniqueKey($sqsData)
    {
        try {
            if (isset($sqsData['user_id'], $sqsData['shop_id'], $sqsData['data'])) {
                $shopId = $sqsData['shop_id'];
                $userId = $sqsData['user_id'];
                $batch = $sqsData['data'];
                $logFile = "shopify/productRemoveContingency/$userId/" . date('d-m-Y') . '.log';
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $productContainer = $mongo->getCollectionForTable('product_container');
                $filter = [
                    'user_id' => $userId,
                    'shop_id' => $shopId,
                    'container_id' => ['$in' => $batch],
                    'visibility' => 'Catalog and Search'
                ];
                $productContainer->updateMany(
                    $filter,
                    ['$set' => ['sales_channel' => true]]
                );
                $this->di->getLog()->logContent('Assigning sales_channel Key --: ', 'info', $logFile);
                return true;
            } else {
                return [
                    'success' => false,
                    'message' => 'Required params missing'
                ];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from getContainerIds(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * getContainerIds function
     * getContainerID chunk wise from DB in which sales channel is not assigned
     * @param [array] $data
     */
    public function getContainerIds($data)
    {
        if (isset($data['user_id'], $data['shop_id'])) {
            $userId = $data['user_id'];
            $shopId = $data['shop_id'];
            $logFile = "shopify/productRemoveContingency/$userId/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('Inside getConainerIds() --: ', 'info', $logFile);
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollection("product_container");
            try {
                if (!empty($data['last_traversed_object_id'])) {

                    $productData = $productContainer->find(
                        [
                            'user_id' => $userId,
                            'shop_id' => $shopId,
                            'visibility' => 'Catalog and Search',
                            'sales_channel' => ['$exists' => false],
                            '_id' => ['$gt' => $data['last_traversed_object_id']]
                        ],
                        [
                            'typeMap' => ['root' => 'array', 'document' => 'array'],
                            'limit' => 1000,
                            'projection' => ['container_id' => 1,'profile'=>1],
                            'sort' => ['_id' => 1]
                        ]
                    )->toArray();
                } else {
                    $count = $productContainer->countDocuments([
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                        'visibility' => 'Catalog and Search',
                        'sales_channel' => ['$exists' => false],
                    ]);
                    $data['count'] = $count;
                    $productData = $productContainer->find(
                        [
                            'user_id' => $userId,
                            'shop_id' => $shopId,
                            'visibility' => 'Catalog and Search',
                            'sales_channel' => ['$exists' => false]
                        ],
                        [
                            'typeMap' => ['root' => 'array', 'document' => 'array'],
                            'limit' => 1000,
                            'projection' => ['container_id' => 1 , 'profile'=>1],
                            'sort' => ['_id' => 1]
                        ]
                    )->toArray();
                }
                if (!empty($productData)) {
                    $containerIds = array_column($productData, 'container_id');
                    $result=[];
                    foreach ($productData as $product) {
                        $containerId = $product['container_id'];

                        if (!empty($product['profile']) && is_array($product['profile'])) {
                            foreach ($product['profile'] as $profileEntry) {
                                if (isset($profileEntry['type']) && $profileEntry['type'] === 'manual') {
                                    $profileId = (string) $profileEntry['profile_id'];

                                    if (!isset($result[$profileId])) {
                                        $result[$profileId] = [];
                                    }

                                    if (!in_array($containerId, $result[$profileId])) {
                                        $result[$profileId][] = $containerId;
                                    }
                                }
                            }
                        }
                    }

                    $handlerData = [
                        'user_id' => $data['user_id'],
                        'shop_id' => $data['shop_id'] ?? '',
                        'remote_shop_id' => $data['remote_shop_id'],
                        'data' => $containerIds,
                    ];
                    $this->removeProfileData($result,$data['user_id']);
                    $this->removeContainerIds($handlerData);

                    $lastProductData = end($productData);
                    $lastTraversedObjectId = $lastProductData['_id'];

                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => Helper::class,
                        'method' => 'getContainerIds',
                        'user_id' => $data['user_id'],
                        'shop_id' => $data['shop_id'],
                        'total_count' => $data['count'] ?? $data['total_count'],
                        'container_id_removed_count' => ($data['container_id_removed_count'] ?? 0) + count($containerIds),
                        'last_traversed_object_id' => $lastTraversedObjectId,
                        'remote_shop_id' => $data['remote_shop_id'],
                        'queue_name' => 'shopify_product_webhook_backup_sync',
                        'process_code' => 'shopify_product_remove',
                        // 'progress_start' => $data['progress_start'] ?? 50,
                        'feed_id'=>$data['feed_id']
                    ];

                    $progress = round($handlerData['progress_start'] ?? 50 + (($handlerData['container_id_removed_count'] / $handlerData['total_count']) * 45), 2);
                    $handlerData['progress_start'] = $progress;
                    $this->di->getLog()->logContent('Message Pushed for getContainerIDs --: ', 'info', $logFile);
                    $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($data['feed_id'],$progress,'Total ' . $handlerData['total_count'] . ' product(s) are getting removed as Sales Channel are unassigned from them', false);
                    $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($handlerData);
                } else {
                    //Calling when all products removed where sales_channel key is not set
                    $this->unsetSalesChannel($data);
                }
                return true;
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Exception from getContainerIds(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
            }
        } else {
            $message = 'product_id or shop_id not found in data';
        }
        return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
    }

    /**
     * removeContainerIds function
     * removeContainerIds chunk wise from DB in which sales channel is not assigned
     * @param [array] $sqsData
     */
    public function removeContainerIds($sqsData)
    {
        if (isset($sqsData['user_id'], $sqsData['shop_id'], $sqsData['data'])) {
            $userId = $sqsData['user_id'];
            $shopId = $sqsData['shop_id'];
            $productsWithoutSalesChannel = $sqsData['data'];
            $userId = $sqsData['user_id'];
            $logFile = "shopify/productRemoveContingency/$userId/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('In removeContainerIds(): ' . json_encode($productsWithoutSalesChannel), 'info', $logFile);
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollection("product_container");
            $refineProduct = $mongo->getCollection("refine_product");
            try {
                $productContainer->deleteMany([
                    'user_id' => $userId,
                    'container_id' => ['$in' => $productsWithoutSalesChannel],
                ]);

                $refineProduct->deleteMany(
                    [
                        'user_id' => $userId,
                        'source_shop_id' => $shopId,
                        'container_id' => ['$in' => $productsWithoutSalesChannel],
                    ]
                );
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $amazonCollection = $mongo->getCollection('amazon_listing');
                $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
                $query = ['user_id' => $userId, '$or' => [['source_container_id' => ['$in' => $productsWithoutSalesChannel]], [
                    'closeMatchedProduct.source_container_id' => ['$in' => $productsWithoutSalesChannel]
                ]]];
                $amazonListing = $amazonCollection->find($query, $options)->toArray();
                if (!empty($amazonListing)) {
                    $objectIds = array_column($amazonListing, '_id');
                    $this->initatetoInactive($userId, $shopId,$amazonListing);
                    $record = [
                        'unmap_record' => [
                            'source' => [
                                'removeProductWebhookBackup' => true
                            ],
                            'unmap_time' => date('c'),
                        ]
                    ];
                    $amazonCollection->updateMany(['_id' => ['$in' => $objectIds]], ['$set' => $record, '$unset' => [
                        'source_product_id' => 1,
                        'source_variant_id' => 1,
                        'source_container_id' => 1,
                        'matched' => 1,
                        'manual_mapped' => 1,
                        'matchedProduct' => 1,
                        'matchedwith' => 1,
                        'closeMatchedProduct' => 1
                    ]]);
                    $this->di->getLog()->logContent('amazonListings removeContainerIds(): ' . json_encode($objectIds), 'info', $logFile);
                } else {
                    return [
                        'success' => true,
                        'message' => 'No Linked Products Found'
                    ];
                }
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Exception from removeContainerIds(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
            }
        } else {
            return [
                'success' => false,
                'message' => 'shop_id not found'
            ];
        }
    }

    // Remove the product from the manually assigned template.
    public function removeProfileData($result, $userId)
    {
        $logFile = "shopify/productRemoveContingency/$userId/" . date('d-m-Y') . '.log';
        if (!empty($result) && !empty($userId)) {
            try {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $profileCollection = $mongo->getCollection("profile");
                $updateQueries = [];
                foreach ($result as $profileIdStr => $containerIdsToRemove) {
                    $updateQueries[] = [
                        'updateOne' => [
                            [
                                'user_id' => $userId,
                                '_id' => new \MongoDB\BSON\ObjectId($profileIdStr)
                            ],
                            [
                                '$pullAll' => [
                                    'manual_product_ids' => $containerIdsToRemove
                                ]
                            ]
                        ]
                    ];
                }
                $this->di->getLog()->logContent('removeProfileData updateQueries: ' . json_encode($updateQueries), 'info', $logFile);
                if (!empty($updateQueries)) {
                    $profileCollection->bulkWrite($updateQueries);
                }
                $this->di->getLog()->logContent('Profile Data Removed', 'info', $logFile);
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Exception from getContainerIds(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
            }
        }
    }

    public function initatetoInactive($userId,$shopId,$listings)
    {
        try {
            $activeSettings = [];
            $configData =  $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
            $appTag = 'amazon_sales_channel';
            $configData->setGroupCode('inventory');
            $configData->setUserId($userId);
            $configData->sourceSet('shopify');
            $configData->setTarget('amazon');
            $configData->setAppTag($appTag);
            $configData->setSourceShopId($shopId);
            $configData->setTargetShopId(null);
            $config = $configData->getConfig('inactivate_product');
            $decodedConfig = json_decode(json_encode($config), true);
            if (!empty($decodedConfig)) {
                foreach ($decodedConfig as $data) {
                    if ($data['value']) {
                        $activeSettings[$data['target_shop_id']] = true;
                    }
                }
            }
            $targetShopWise = [];
            foreach ($listings as $listing) {
                if(isset($activeSettings[$listing['shop_id']])) {
                    if (!isset($targetShopWise[$listing['shop_id']])) {
                        $targetShopWise[$listing['shop_id']]['source_shop_id'] = $listing['matchedProduct']['shop_id'];
                        $targetShopWise[$listing['shop_id']]['user_id'] = $listing['user_id'];
                        $targetShopWise[$listing['shop_id']]['data'][] = $listing;
                    } else {
                        $targetShopWise[$listing['shop_id']]['data'][] = $listing;
                    }
                }
            }
            $feedContentNew = [];
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            foreach ($targetShopWise as $targetShopId => $targetData) {
                $sourceShopId = $targetData['source_shop_id'];
                $amazonShop = $userDetails->getShop($targetShopId, $userId);
                foreach ($targetData['data'] as $data) {
                    $feedContentNew[$data['matchedProduct']['source_product_id']] = [
                        'Id' => $data['matchedProduct']['source_product_id'],
                        'SKU' => $data['seller-sku'],
                        'Quantity' => 0,
                        'Latency' => 2
                    ];
                    if (count($feedContentNew) == 500) {
                        $specifics = [
                            'ids' => array_keys($feedContentNew),
                            'home_shop_id' => $amazonShop['_id'],
                            'marketplace_id' => $amazonShop['warehouses'][0]['marketplace_id'],
                            'sourceShopId' => $sourceShopId,
                            'shop_id' => $amazonShop['remote_shop_id'],
                            'feedContent' => base64_encode(json_encode($feedContentNew)),
                            'user_id' => $userId,
                            'operation_type' => 'Update',
                            'unified_json_feed' => true
                        ];
                        $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper')->sendRequestToAmazon('inventory-upload', $specifics, 'POST');
                        $feedContentNew = [];
                    }
                }
                if (!empty($feedContentNew)) {
                    $specifics = [
                        'ids' => array_keys($feedContentNew),
                        'home_shop_id' => $amazonShop['_id'],
                        'marketplace_id' => $amazonShop['warehouses'][0]['marketplace_id'],
                        'sourceShopId' => $sourceShopId,
                        'shop_id' => $amazonShop['remote_shop_id'],
                        'feedContent' => base64_encode(json_encode($feedContentNew)),
                        'user_id' => $userId,
                        'operation_type' => 'Update',
                        'unified_json_feed' => true
                    ];
                    $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper')->sendRequestToAmazon('inventory-upload', $specifics, 'POST');
                }
                $feedContentNew = [];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * unsetSalesChannel function
     * Removes temporary sales_channel markers in chunks (SQS-driven) so a single updateMany
     * does not scan/update the entire shop catalog at once. Only documents that have sales_channel
     * set are touched (same end state as a full-catalog $unset of that field).
     *
     * @param array $data Must include user_id, shop_id; optional unset_sales_channel_last_id for continuation.
     */
    public function unsetSalesChannel($data)
    {
        try {
            $userId = $data['user_id'];
            $shopId = $data['shop_id'];
            $logFile = "shopify/productRemoveContingency/$userId/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollection("product_container");
            $filter = [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'visibility' => 'Catalog and Search',
                'sales_channel' => ['$exists' => true],
            ];
            if (!empty($data['unset_sales_channel_last_id'])) {
                $filter['_id'] = ['$gt' => $data['unset_sales_channel_last_id']];
            }
            $batch = $productContainer->find(
                $filter,
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => self::UNSET_SALES_CHANNEL_BATCH,
                    'projection' => ['_id' => 1],
                    'sort' => ['_id' => 1],
                ]
            )->toArray();

            if (!empty($batch)) {
                $ids = array_column($batch, '_id');
                $productContainer->updateMany(
                    [
                        '_id' => ['$in' => $ids],
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                    ],
                    ['$unset' => ['sales_channel' => '']]
                );
                $lastBatchDoc = end($batch);
                $lastId = $lastBatchDoc['_id'];
                $unsetSoFar = ($data['unset_sales_channel_chunk_count'] ?? 0) + count($ids);
                $this->di->getLog()->logContent(
                    'Sales channel unset chunk: ' . count($ids) . ' doc(s), cumulative ' . $unsetSoFar,
                    'info',
                    $logFile
                );

                $nextPayload = $data;
                $nextPayload['type'] = 'full_class';
                $nextPayload['class_name'] = Helper::class;
                $nextPayload['method'] = 'unsetSalesChannel';
                $nextPayload['queue_name'] = $data['queue_name'] ?? 'shopify_product_webhook_backup_sync';
                $nextPayload['unset_sales_channel_last_id'] = $lastId;
                $nextPayload['unset_sales_channel_chunk_count'] = $unsetSoFar;
                unset($nextPayload['delay']);
                $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($nextPayload);
                return;
            }

            $this->di->getLog()->logContent('Sales Channel Unassigned (chunked pass complete) --: ', 'info', $logFile);
            $adoption = $mongo->getCollectionForTable('adoption_metrics');
            $this->removeEntryFromQueuedTask($data['process_code'] ?? 'shopify_product_remove');
            $data['marketplace'] = 'shopify';
            $removeProducts = $data['container_id_removed_count'] ?? 0;
            $this->updateActivityLog($data, true, "Contigency process completed.Total $removeProducts  products(s) removed from app.");
            $adoption->insertOne([
                'user_id' => $userId,
                'process_code' => $data['process_code'] ?? 'shopify_product_remove',
                'shop_id' => $shopId,
                'removed_products_count' => $removeProducts,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from unsetSalesChannel(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
            throw $e;
        }
    }

    private function updateActivityLog($data, $status, $message)
    {
        $appTag = $this->di->getAppCode()->getAppTag();
        $notificationData = [
            'severity' => $status ? 'success' : 'critical',
            'message' => $message ?? 'Contigency Process',
            "user_id" => $this->di->getUser()->id,
            "marketplace" => $data['marketplace'],
            "appTag" => $appTag,
            'process_code' => $data['data']['process_code'] ?? 'shopify_product_remove'
        ];
        $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
            ->addNotification($data['shop_id'], $notificationData);
    }

    /**
     * Removes an entry from the queued task after the process is completed.
     *
     * @param string $processCode Unique process identifier.
     */
    private function removeEntryFromQueuedTask($processCode)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $queueTasksContainer = $mongo->getCollection("queued_tasks");
            $queueTasksContainer->deleteOne([
                'user_id' => $this->di->getUser()->id,
                'process_code' => $processCode ?? 'shopify_product_remove',
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception removeEntryFromQueuedTask(), Error: ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    public function updateShopifyWebhookAddressByPlanTier($data)
    {
        try {
            $logFile = "shopify/updateShopifyWebhookAddressByPlanTier/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('Starting process for data: ' . json_encode($data), 'info',  $logFile);
            if (!empty($data['data']['plan_tier'])) {
                $planTier = $data['data']['plan_tier'];
                $graphqlBaseUrlsByPlanTier = $this->di->getConfig()->get('webhook_graphql_base_url_by_plan_tier') ? $this->di->getConfig()->get('webhook_graphql_base_url_by_plan_tier')->toArray() : [];
                if (!empty($graphqlBaseUrlsByPlanTier)) {
                    $baseWebhookUrl = $graphqlBaseUrlsByPlanTier[self::MARKETPLACE][$planTier] ?? null;
                    if (!empty($baseWebhookUrl)) {
                        $shops = $this->di->getUser()->shops;
                        $shopData = [];
                        foreach ($shops as $shop) {
                            if ($shop['marketplace'] == self::MARKETPLACE) {
                                $shopData = $shop;
                                break;
                            }
                        }
                        if (!empty($shopData)) {
                            //Fetching webhook data
                            $getWebhookResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                                ->init(self::MARKETPLACE, true)
                                ->call('/webhook', [], ['shop_id' => $shopData['remote_shop_id']], 'GET');
                            if (isset($getWebhookResponse['success']) && $getWebhookResponse['success'] && !empty($getWebhookResponse['data'])) {
                                $appTag = $shopData['apps'][0]['code'] ?? 'default';
                                if (count($getWebhookResponse['data']) != $this->fetchStandardWebhookToBeRegisteredCount($appTag)) {
                                    if (isset($data['retry'])) {
                                        ++$data['retry'];
                                    } else {
                                        $data['retry'] = 1;
                                    }
                                    if ($data['retry'] > 1) {
                                        // Calling register webhooks
                                        $createDestinationResponse = $this->di->getObjectManager()->get("\App\Shopifyhome\Components\UserEvent")->createDestination($shopData);
                                        $destinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                                        if ($destinationId) {
                                            $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks($shopData, $shopData['marketplace'], $appTag, $destinationId);
                                            $this->di->getLog()->logContent('Create Webhook Request Response=> ' . json_encode($createWebhookRequest, true), 'info', $logFile);
                                        } else {
                                            $message = 'Destination not created';
                                            $this->di->getLog()->logContent('Destination not created, createDestinationResponse: ' . json_encode($createDestinationResponse), 'info', $logFile);
                                        }
                                    } else {
                                        $data['delay'] = 300; // Delay of 5min
                                        $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')
                                            ->pushMessage($data);
                                        return ['success' => true, 'message' => 'Some webhooks are not registered will retry after 5-minutes to update webhook address'];
                                    }
                                    $this->di->getLog()->logContent('Updating webhook address of present webhooks: ' . json_encode($getWebhookResponse['data']), 'info', $logFile);
                                }
                                $webhookIds = $this->fetchWebhookIdsToBeUpdated($getWebhookResponse['data'], $appTag);
                                if (!empty($webhookIds)) {
                                    // Updating webhook address
                                    $updateWebhookAddressResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                                        ->init(self::MARKETPLACE, true)
                                        ->call('update/webhookSubscriptionEventBridge', [], ['shop_id' => $shopData['remote_shop_id'], 'ids' => $webhookIds, 'address' => $baseWebhookUrl], 'POST');
                                    if (isset($updateWebhookAddressResponse['success']) && $updateWebhookAddressResponse['success'] && !empty($updateWebhookAddressResponse['data'])) {
                                        $this->di->getLog()->logContent('Address updated successfully!!', 'info',  $logFile);
                                        if (!empty($updateWebhookAddressResponse['data']['failedData'])) {
                                            $this->di->getLog()->logContent('Unable to update address of webhookIds: ' . json_encode($updateWebhookAddressResponse['data']['failedData']), 'info',  $logFile);
                                        }
                                        if ($planTier == 'default') {
                                            $notificationMessage = 'Your Shopify updates now sync through shared resources, which may be slower. Upgrade to a paid plan for faster processing';
                                        } else {
                                            $notificationMessage = 'You’ve chosen a paid plan, so your Shopify updates will now sync faster with our premium resources.';
                                        }
                                        $notificationData = [
                                            'message' => $notificationMessage,
                                            'severity' => 'success',
                                            "user_id" => $data['user_id'],
                                            "marketplace" => self::MARKETPLACE,
                                            "appTag" => $appTag,
                                            'process_code' => 'shopify_webhook_address_update'
                                        ];
                                        $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                                            ->addNotification($shopData['_id'], $notificationData);
                                        return ['success' => true, 'message' => 'Webhook address updated as per plan_tier'];
                                    } else {
                                        $message = $updateWebhookAddressResponse;
                                    }
                                } else {
                                    $message = 'Webhook ids not found';
                                }
                            } else {
                                $message = $getWebhookResponse;
                            }
                        } else {
                            $message = 'Shop data not found';
                        }
                    } else {
                        $message = 'Base webhook url not found';
                    }
                } else {
                    $message = 'webhook_graphql_base_url_by_plan_tier is not set in config so, unable to update webhook address';
                }
            } else {
                $message = 'Required params is missing($data["plan_tier"])';
            }
            $this->di->getLog()->logContent('Error occured: ' . json_encode($message ?? 'Something went wrong'), 'info',  $logFile);
            return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from updateShopifyWebhookAddress(): ' . json_encode($e->getMessage()), 'info',  $logFile ?? 'exception.log');
            throw $e;
        }
    }

    private function fetchWebhookIdsToBeUpdated($webhookData, $appTag)
    {
        $webhooksToBeUpdatedAsPerPlanTier = $this->di->getConfig()->webhooks_to_be_updated_as_per_plan_tier->get($appTag)
        ? $this->di->getConfig()->webhooks_to_be_updated_as_per_plan_tier->get($appTag)->toArray() : [];
        $webhookIds = [];
        if (!empty($webhooksToBeUpdatedAsPerPlanTier)) {
            foreach ($webhookData as $data) {
                if(in_array($data['topic'], $webhooksToBeUpdatedAsPerPlanTier)) {
                    $webhookIds[] = (string)$data['id'];
                }
            }
        }
        return $webhookIds;
    }

    private function fetchStandardWebhookToBeRegisteredCount($appTag)
    {
        $getWebhookConfig = $this->di->getConfig()->webhook->get($appTag) ? $this->di->getConfig()->webhook->get($appTag)->toArray() : [];
        return count($getWebhookConfig);
    }
}
