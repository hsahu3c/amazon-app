<?php

namespace App\Shopifyhome\Components\Manual;

use App\Shopifyhome\Components\UserEvent;
use MongoDB\BSON\ObjectId;
use App\Core\Models\User;
use Exception;
use App\Shopifyhome\Components\Core\Common;

class Helper extends Common
{

    /**
     * createShopifyWebhooks function
     * To create Shopify Webhooks for particular user
     * @param [array] $params
     * @return array
     */
    public function createShopifyWebhooks($params)
    {
        $logFile = "shopifyRegisterWebhook/singleUser/" . date('d-m-Y') . '.log';
        if (isset($params['shop_id'], $params['source_marketplace'])) {
            $missingWebhooks = $shopData = [];
            if (isset($params['missing_webhooks'])) {
                $missingWebhooks = $params['missing_webhooks'];
            }

            $shopId =  is_array($params['shop_id']) ? $params['shop_id'][0] : $params['shop_id'];
            $marketplace = $params['source_marketplace'];
            if (!isset($params['user_id'])) {
                $userData = $this->getUserShop($shopId);
                if (isset($userData['success']) && $userData['success']) {
                    $userId = $userData['user']['user_id'];
                    $shopData = $userData['user']['shops'][0] ?? [];
                } else {
                    $this->di->getLog()->logContent('Unable to find user', 'info',  $logFile);
                    return ['success' => false, 'message' => 'Unable to find user'];
                }
            } else {
                $userId = $params['user_id'];
            }

            $this->di->getLog()->logContent('Starting Process for User_id: ' . json_encode($userId, true), 'info',  $logFile);
            $response = $this->setDiForUser($userId);
            if (isset($response['success']) && $response['success']) {
                $this->di->getLog()->logContent('Di Set successfully ', 'info',  $logFile);
                if (empty($shopData)) {
                    $shops = $this->di->getUser()->shops;
                    foreach ($shops as $shop) {
                        if ($shop['_id'] == $shopId) {
                            $shopData = $shop;
                            break;
                        }
                    }
                }

                if (!empty($shopData)) {
                    $appCode = $shopData['apps'][0]['code'] ?? 'default';
                    $userEvent = $this->di->getObjectManager()->get(UserEvent::class);
                    $sourceModel = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel");
                    if (!empty($missingWebhooks)) {
                        if (in_array('app_delete', $missingWebhooks)) {
                            $createDestinationResponse = $userEvent->createDestination($shopData, 'user_app');
                            $appDeleteDestinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                            if ($appDeleteDestinationId) {
                                $destinationWise[] = [
                                    'destination_id' => $appDeleteDestinationId,
                                    'event_codes' =>  ["app_delete"]
                                ];
                            } else {
                                $this->di->getLog()->logContent('Unable to Create/Get user_app destination for user_id=> ' . json_encode($userId, true), 'info', $logFile);
                            }
                        }

                        $missingWebhooks = array_diff($missingWebhooks, ["app_delete"]);
                        if (!empty($missingWebhooks)) {
                            $createDestinationResponse = $userEvent->createDestination($shopData);
                            $destinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                            if ($destinationId) {
                                $destinationWise[] = [
                                    'destination_id' => $destinationId,
                                    'event_codes' => array_values($missingWebhooks)
                                ];
                            } else {
                                $this->di->getLog()->logContent('Unable to Create/Get user destination for user_id=> ' . json_encode($userId, true), 'info', $logFile);
                            }
                        }

                        if (!empty($destinationWise)) {
                            $sourceModel->routeRegisterWebhooks($shopData, $marketplace, $appCode, false, $destinationWise);
                            $this->di->getLog()->logContent('END', 'info', $logFile);
                            return ['success' => true, 'message' => 'Registering Webhooks..'];
                        }
                    } else {
                        $createDestinationResponse = $userEvent->createDestination($shopData, 'user_app');
                        $appDeleteDestinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                        if ($appDeleteDestinationId) {
                            $sourceModel->routeRegisterWebhooks($shopData, $marketplace, $appCode, $appDeleteDestinationId);
                        } else {
                            $message = 'User app destination not created';
                            $this->di->getLog()->logContent($message, 'info',  $logFile);
                        }

                        $createDestinationResponse = $userEvent->createDestination($shopData);
                        $destinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                        if ($destinationId) {
                            $sourceModel->routeRegisterWebhooks($shopData, $marketplace, $appCode, $destinationId);
                            return ['success' => true, 'message' => 'Registering Webhooks'];
                        }
                        $message = 'User destination not created';
                        $this->di->getLog()->logContent($message, 'info',  $logFile);
                    }
                } else {
                    $message = $this->di->getLocale()->_('shopify_shop_not_found');
                    $this->di->getLog()->logContent($message, 'info',  $logFile);
                }
            } else {
                $message = $this->di->getLocale()->_('shopify_unable_to_set_di');
                $this->di->getLog()->logContent($message, 'info',  $logFile);
            }
        } else {
            $message = 'Required Params Missing';
            $this->di->getLog()->logContent($message, 'info',  $logFile);
        }

        return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
    }

    /**
     * registerAllUsersWebhooksFromApp function
     * To create Webhooks for all active users
     * @param [array] $params
     * @param [string] $params['target'](mandatory)
     * @return array
     */
    public function registerAllUsersWebhooksFromApp($params = [])
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('user_details');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $logFile = "shopifyRegisterWebhook/allUsers/" . date('d-m-Y') . '.log';
        $message = $this->di->getLocale()->_('shopify_webhook_process_completed');
        $sourceMarketplace = 'shopify';
        if (isset($params['target']) && !empty($params['target'])) {
            $targetMarketplace = $params['target'];
            $pipline = [
                [
                    '$match' => [
                        'shops' => [
                            '$elemMatch' => [
                                'apps.app_status' => 'active',
                                'marketplace' => $targetMarketplace
                            ],
                        ],
                    ]
                ],
                ['$project' => ['user_id' => 1, '_id' => 0]],
                ['$sort' => ['user_id' => 1]],
                ['$limit' => 50]
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
                    $userId = $user['user_id'];
                    $this->di->getLog()->logContent('Starting Process for UserId: ' . json_encode($userId, true), 'info', $logFile);
                    $response = $this->setDiForUser($userId);
                    if (isset($response['success']) && $response['success']) {
                        $this->di->getLog()->logContent('Di Set successfully ', 'info', $logFile);
                        $shops = $this->di->getUser()->shops;
                        foreach ($shops as $shop) {
                            if ($shop['marketplace'] == $sourceMarketplace) {
                                $shopData = $shop;
                                break;
                            }
                        }

                        if (!empty($shopData)) {
                            $appCode = $shopData['apps'][0]['code'] ?? 'default';
                            $createDestinationResponse = $this->di->getObjectManager()->get(UserEvent::class)
                                ->createDestination($shopData);
                            $destinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                            if (!$destinationId) {
                                $this->di->getLog()->logContent('Destination Not created ', 'info', $logFile);
                            } else {
                                $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks($shopData, $sourceMarketplace, $appCode, $destinationId);
                                $this->di->getLog()->logContent('Create Webhook Request Response=> ' . json_encode($createWebhookRequest, true), 'info', $logFile);
                                $this->di->getLog()->logContent('END', 'info', $logFile);
                            }
                        }
                    }
                }

                $endUser = end($userData);
                $processedUsers = array_column($userData, 'user_id');
                $query->updateMany(
                    ['user_id' => ['$in' => $processedUsers]],
                    ['$set' => ['picked' => true]]
                );
                $userId = $endUser['user_id'];
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => \App\Shopifyhome\Components\Manual\Helper::class,
                    'method' => 'registerAllUsersWebhooksFromApp',
                    'queue_name' => 'shopify_register_all_webhooks',
                    'user_id' => $userId,
                    'target' => $params['target']

                ];
                $sqsHelper->pushMessage($handlerData);
                $this->di->getLog()->logContent('Process Completed for this user', 'info', $logFile);
                $message = $this->di->getLocale()->_('shopify_webhook_processing_queue');
            }

            $this->di->getLog()->logContent($message, 'info', $logFile);
            $this->di->getLog()->logContent('-------------------------------', 'info', $logFile);
        }

        return ['success' => true, 'message' => $message];
    }

    /**
     * bulkWebhookRegistrationByCode function
     * Bulk webhook registration by code for batches of 50 users
     * This function accepts an array of webhook
     * codes and performs bulk registration for users
     * which does not have these webhook codes.
     * @param [array] $params
     * @param [array] $params['webhookCodes'](mandatory)
     * @return array
     */
    public function bulkWebhookRegistrationByCode($params = [])
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('user_details');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $logFile = "shopifyRegisterWebhook/allUsers/" . date('d-m-Y') . '.log';
        $message = $this->di->getLocale()->_('shopify_webhook_process_completed');
        $marketplace = 'shopify';
        $shopData = [];
        if (!empty($params) && isset($params['webhookCodes'])) {
            $webhookCodes = $params['webhookCodes'];
            $pipline = [
                [
                    '$match' => [
                        'shops' => [
                            '$elemMatch' => [
                                'marketplace' => $marketplace,
                                'apps.app_status' => 'active',
                                'apps.webhooks.code' => [
                                    '$not' => [
                                        '$all' => $webhookCodes
                                    ]
                                ]
                            ],
                        ],
                    ]
                ],
                ['$project' => ['user_id' => 1, '_id' => 0]],
                ['$sort' => ['user_id' => 1]],
                ['$limit' => 50]
            ];
            if (!empty($params)) {
                if (isset($params['user_id']) && !empty($params['user_id'])) {
                    $id = new ObjectId($params['user_id']);
                    $preparePipline = ['$match' => ['_id' => ['$gt' => $id]]];
                    array_splice($pipline, 0, 0, [$preparePipline]);
                }

                if (isset($params['target']) && !empty($params['target'])) {
                    $preparePipline = [
                        '$match' => [
                            'shops.1' => ['$exists' => true],
                            'shops' => [
                                '$elemMatch' => [
                                    'marketplace' => $params['target'],
                                    'apps.app_status' => 'active'

                                ]
                            ]
                        ]
                    ];
                    array_splice($pipline, 0, 0, [$preparePipline]);
                }
            }

            $userData = $query->aggregate($pipline, $arrayParams)->toArray();
            if (!empty($userData)) {
                $destinationId = false;
                $appDeleteDestinationId = false;
                foreach ($userData as $user) {
                    $destinationWise = [];
                    $userId = $user['user_id'];
                    $this->di->getLog()->logContent('Starting Process for UserId: ' . json_encode($userId, true), 'info', $logFile);
                    $response = $this->setDiForUser($userId);
                    if (isset($response['success']) && $response['success']) {
                        $this->di->getLog()->logContent('Di Set successfully ', 'info', $logFile);
                        $shops = $this->di->getUser()->shops;
                        foreach ($shops as $shop) {
                            if ($shop['marketplace'] == $marketplace) {
                                $shopData = $shop;
                                break;
                            }
                        }

                        if (!empty($shopData)) {
                            $appCode = $shopData['apps'][0]['code'] ?? 'default';
                            $object = $this->di->getObjectManager()->get(UserEvent::class);
                            if (in_array('app_delete', $webhookCodes)) {
                                $createDestinationResponse = $object->createDestination($shopData, 'user_app');
                                $appDeleteDestinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                                if (!$appDeleteDestinationId) {
                                    $this->di->getLog()->logContent('Unable to Create/Get app_delete destination user_id=> ' . json_encode($this->di->getUser()->id, true), 'info', $logFile);
                                }

                                foreach ($webhookCodes as $key => $code) {
                                    if ($code == 'app_delete') {
                                        unset($webhookCodes[$key]);
                                    }
                                }
                            }

                            if (!empty($webhookCodes)) {
                                $createDestinationResponse = $object->createDestination($shopData);
                                $destinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                                if ($destinationId) {
                                    $destinationWise[] = [
                                        'destination_id' => $destinationId,
                                        'event_codes' => array_values($webhookCodes)

                                    ];
                                } else {
                                    $this->di->getLog()->logContent('Unable to Create/Get destination user_id=> ' . json_encode($this->di->getUser()->id, true), 'info', $logFile);
                                }
                            }

                            if ($appDeleteDestinationId) {
                                $destinationWise[] = [
                                    'destination_id' => $appDeleteDestinationId,
                                    'event_codes' => [
                                        'app_delete'
                                    ]
                                ];
                            }

                            if ($destinationId || $appDeleteDestinationId) {
                                $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks($shopData, $marketplace, $appCode, false, $destinationWise);
                                $this->di->getLog()->logContent('Create Webhook Request Response=> ' . json_encode($createWebhookRequest, true), 'info', $logFile);
                                $this->di->getLog()->logContent('END', 'info', $logFile);
                            }
                        }
                    }
                }

                $endUser = end($userData);
                $processedUsers = array_column($userData, 'user_id');
                $query->updateMany(
                    ['user_id' => ['$in' => $processedUsers]],
                    ['$set' => ['picked' => true]]
                );
                $userId = $endUser['user_id'];
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => \App\Shopifyhome\Components\Manual\Helper::class,
                    'method' => 'bulkWebhookRegistrationByCode',
                    'queue_name' => 'shopify_register_all_webhooks',
                    'user_id' => $userId,
                    'webhookCodes' => $params['webhookCodes']

                ];
                if (!empty($params['target'])) {
                    $handlerData['target'] = $params['target'];
                }

                $sqsHelper->pushMessage($handlerData);
                $this->di->getLog()->logContent('Process Completed for this user', 'info', $logFile);
                $message = $this->di->getLocale()->_('shopify_webhook_processing_queue');
            }

            $this->di->getLog()->logContent($message, 'info', $logFile);
            $this->di->getLog()->logContent('-------------------------------', 'info', $logFile);
        }

        return ['success' => true, 'message' => $message];
    }

    /**
     * registerWebhookByCode function
     * Webhook registration by single code for batches of 50 users
     * This function accepts a single webhook code
     * and register that webhook for all users which
     * does not have this particular webhook
     * @param [array] $params
     * @param [string] $params['webhookCode'](mandatory)
     * @return array
     */
    public function registerWebhookByCode($params = [])
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('user_details');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $logFile = "shopifyRegisterWebhook/allUsers/" . date('d-m-Y') . '.log';
        $message = $this->di->getLocale()->_('shopify_webhook_process_completed');
        $marketplace = 'shopify';
        $shopData = [];
        if (!empty($params) && isset($params['webhookCode'])) {
            $webhookCode = $params['webhookCode'];
            $pipline = [
                [
                    '$match' => [
                        'shops' => [
                            '$elemMatch' => [
                                'marketplace' => $marketplace,
                                'apps.app_status' => 'active',
                                'apps.webhooks.code' => [
                                    '$not' => [
                                        '$eq' => $webhookCode
                                    ]
                                ]
                            ],
                        ],
                    ]
                ],
                ['$project' => ['user_id' => 1, '_id' => 0]],
                ['$sort' => ['user_id' => 1]],
                ['$limit' => 50]
            ];
            if (isset($params['target']) && !empty($params['target'])) {
                $preparePipline = [
                    '$match' => [
                        'shops.1' => ['$exists' => true],
                        'shops' => [
                            '$elemMatch' => [
                                'marketplace' => $params['target'],
                                'apps.app_status' => 'active'

                            ]
                        ]
                    ]
                ];
                array_splice($pipline, 0, 0, [$preparePipline]);
            }

            if (isset($params['user_id']) && !empty($params['user_id'])) {
                $id = new ObjectId($params['user_id']);
                $preparePipline = ['$match' => ['_id' => ['$gt' => $id]]];
                array_splice($pipline, 0, 0, [$preparePipline]);
            }

            $userData = $query->aggregate($pipline, $arrayParams)->toArray();
            if (!empty($userData)) {
                foreach ($userData as $user) {
                    $destinationWise = [];
                    $userId = $user['user_id'];
                    $this->di->getLog()->logContent('Starting Process for UserId: ' . json_encode($userId, true), 'info', $logFile);
                    $response = $this->setDiForUser($userId);
                    if (isset($response['success']) && $response['success']) {
                        $this->di->getLog()->logContent('Di Set successfully ', 'info', $logFile);
                        $shops = $this->di->getUser()->shops;
                        foreach ($shops as $shop) {
                            if ($shop['marketplace'] == $marketplace) {
                                $shopData = $shop;
                                break;
                            }
                        }

                        if (!empty($shopData)) {
                            $appCode = $shopData['apps'][0]['code'] ?? 'default';
                            $object = $this->di->getObjectManager()->get(UserEvent::class);
                            if ($webhookCode == 'app_delete') {
                                $createDestinationResponse = $object->createDestination($shopData, 'user_app');
                            } else {
                                $createDestinationResponse = $object->createDestination($shopData);
                            }

                            $destinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                            if (!$destinationId) {
                                $this->di->getLog()->logContent('Unable to Create/Get app_delete destination user_id=> ' . json_encode($this->di->getUser()->id, true), 'info', $logFile);
                            } else {
                                $destinationWise[] = [
                                    'destination_id' => $destinationId,
                                    'event_codes' => [
                                        $webhookCode
                                    ]
                                ];
                                $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks($shopData, $marketplace, $appCode, false, $destinationWise);
                                $this->di->getLog()->logContent('Create Webhook Request Response=> ' . json_encode($createWebhookRequest, true), 'info', $logFile);
                                $this->di->getLog()->logContent('END', 'info', $logFile);
                            }
                        }
                    }
                }

                $endUser = end($userData);
                $processedUsers = array_column($userData, 'user_id');
                $query->updateMany(
                    ['user_id' => ['$in' => $processedUsers]],
                    ['$set' => ['picked' => true]]
                );
                $userId = $endUser['user_id'];
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => \App\Shopifyhome\Components\Manual\Helper::class,
                    'method' => 'registerWebhookByCode',
                    'queue_name' => 'shopify_register_all_webhooks',
                    'user_id' => $userId,
                    'webhookCode' => $params['webhookCode']

                ];
                if (!empty($params['target'])) {
                    $handlerData['target'] = $params['target'];
                }

                $sqsHelper->pushMessage($handlerData);
                $this->di->getLog()->logContent('Process Completed for this user', 'info', $logFile);
                $message = $this->di->getLocale()->_('shopify_webhook_processing_queue');
            }

            $this->di->getLog()->logContent($message, 'info', $logFile);
            $this->di->getLog()->logContent('-------------------------------', 'info', $logFile);
        }

        return ['success' => true, 'message' => $message];
    }

    /**
     * registerWebhookSubscriptionForAllUsers function
     * Function to Register Shopify Webhook Subscription
     * using GraphQL and removing old registered webhooks
     * for all users
     * @param [array] $params
     * @param [string] $params['target'](mandatory)
     * @return array
     */
    public function registerWebhookSubscriptionForAllUsers($params = [])
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('user_details');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $logFile = "shopifyWebhookSubscription/unregister/" . date('d-m-Y') . '.log';
        $message = $this->di->getLocale()->_('shopify_webhook_process_completed');
        $sourceMarketplace = 'shopify';
        if (isset($params['target']) && !empty($params['target'])) {
            $targetMarketplace = $params['target'];
            $pipline = [
                [
                    '$match' => [
                        'shops' => [
                            '$elemMatch' => [
                                'apps.app_status' => 'active',
                                'marketplace' => $targetMarketplace
                            ],
                        ],
                    ]
                ],
                ['$project' => ['user_id' => 1, '_id' => 0]],
                ['$sort' => ['user_id' => 1]],
                ['$limit' => 50]
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
                    $userId = $user['user_id'];
                    $this->di->getLog()->logContent('Unregistering Webhooks for UserId: ' . json_encode($userId, true), 'info', $logFile);
                    $response = $this->setDiForUser($userId);
                    if (isset($response['success']) && $response['success']) {
                        $this->di->getLog()->logContent('Di Set successfully ', 'info', $logFile);
                        $shops = $this->di->getUser()->shops;
                        if (!empty($shops)) {
                            foreach ($shops as $shop) {
                                if ($shop['marketplace'] == $sourceMarketplace) {
                                    $shopData = $shop;
                                    break;
                                }
                            }

                            if (!empty($shopData)) {
                                $this->di->getLog()->logContent('HomeShopId: ' . json_encode($shopData['_id'], true), 'info', $logFile);
                                $appCode = $shopData['apps'][0]['code'] ?? 'default';
                                $this->routeUnregisterWebhooks($shopData, $sourceMarketplace, $appCode, false);
                            }
                        }
                    }
                }

                $endUser = end($userData);
                $processedUsers = array_column($userData, 'user_id');
                $query->updateMany(
                    ['user_id' => ['$in' => $processedUsers]],
                    ['$set' => ['picked' => true]]
                );
                $userId = $endUser['user_id'];
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => \App\Shopifyhome\Components\Manual\Helper::class,
                    'method' => 'registerWebhookSubscriptionForAllUsers',
                    'queue_name' => 'shopify_register_all_webhooks',
                    'user_id' => $userId,
                    'target' => $params['target'],
                    'delay' => 60

                ];
                $sqsHelper->pushMessage($handlerData);
                $this->di->getLog()->logContent('Process Completed for this chunk', 'info', $logFile);
                $message = $this->di->getLocale()->_('shopify_webhook_processing_queue');
            }

            $this->di->getLog()->logContent($message, 'info', $logFile);
            $this->di->getLog()->logContent('-------------------------------', 'info', $logFile);
        }

        return ['success' => true, 'message' => $message];
    }

    /**
     * registerWebhookSubscriptionForAllUsers function
     * Function to Register Shopify Webhook Subscription
     * using GraphQL and removing old registered webhooks
     * for users whose has not connected the targets
     * @param [array] $params
     * @return array
     */
    public function registerWebhookSubcriptionForNotConnectedTargets($params = [])
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('user_details');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $logFile = "shopifyWebhookSubscription/unregister/" . date('d-m-Y') . '.log';
        $message = $this->di->getLocale()->_('shopify_webhook_process_completed');
        $sourceMarketplace = 'shopify';
        $pipline = [
            [
                '$match' => [
                    'shops.1' => ['$exists' => false],
                    'shops' => [
                        '$elemMatch' => [
                            'apps.app_status' => 'active',
                            'marketplace' => $sourceMarketplace
                        ],

                    ],
                ]
            ],
            ['$project' => ['user_id' => 1, '_id' => 0]],
            ['$sort' => ['user_id' => 1]],
            ['$limit' => 50]
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
                $userId = $user['user_id'];
                $this->di->getLog()->logContent('Unregistering Webhooks for UserId: ' . json_encode($userId, true), 'info', $logFile);
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    $this->di->getLog()->logContent('Di Set successfully ', 'info', $logFile);
                    $shops = $this->di->getUser()->shops;
                    if (!empty($shops)) {
                        foreach ($shops as $shop) {
                            if ($shop['marketplace'] == $sourceMarketplace) {
                                $shopData = $shop;
                                break;
                            }
                        }

                        if (!empty($shopData)) {
                            $this->di->getLog()->logContent('HomeShopId: ' . json_encode($shopData['_id'], true), 'info', $logFile);
                            $appCode = $shopData['apps'][0]['code'] ?? 'default';
                            $this->routeUnregisterWebhooks($shopData, $sourceMarketplace, $appCode, false, [], false);
                        }
                    }
                }
            }

            $endUser = end($userData);
            $processedUsers = array_column($userData, 'user_id');
            $query->updateMany(
                ['user_id' => ['$in' => $processedUsers]],
                ['$set' => ['picked' => true]]
            );
            $userId = $endUser['user_id'];
            $handlerData = [
                'type' => 'full_class',
                'class_name' => \App\Shopifyhome\Components\Manual\Helper::class,
                'method' => 'registerWebhookSubcriptionForNotConnectedTargets',
                'queue_name' => 'shopify_register_all_webhooks',
                'user_id' => $userId,
                'delay' => 60

            ];
            $sqsHelper->pushMessage($handlerData);
            $this->di->getLog()->logContent('Process Completed for this chunk', 'info', $logFile);
            $message = $this->di->getLocale()->_('shopify_webhook_processing_queue');
        }

        $this->di->getLog()->logContent($message, 'info', $logFile);
        $this->di->getLog()->logContent('-------------------------------', 'info', $logFile);

        return ['success' => true, 'message' => $message];
    }


    /**
     * deleteShopifyWebhooks function
     * To delete particular webhook code of for particular user
     * @param [array] $params
     * @return array
     */
    public function deleteShopifyWebhooks($params = [])
    {
        if (!empty($params['shop_id'])) {
            $shopId = $params['shop_id'];
            $message = 'Unregistering Webhooks ...';
            $marketplace = 'shopify';
            if (!empty($shopId) && !empty($params['codes'])) {
                $userData = $this->getUserShop($shopId);
                if (isset($userData['success']) && $userData['success']) {
                    $userId = $userData['user']['user_id'];
                    $shopData = $userData['user']['shops'][0];
                } else {
                    return ['success' => false, 'message' => 'Shop not found'];
                }

                $webhookCodes = $params['codes'];
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    $this->di->getLog()->logContent('Starting Process for User_id: ' . json_encode($userId, true), 'info', 'deleteShopifyWebhook.log');
                    $appCode = $shopData['apps'][0]['code'] ?? 'default';
                    $this->di->getLog()->logContent('Called Unregister Webhook for ShopId: ' . $shopId, 'info', 'deleteShopifyWebhook.log');
                    $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")
                        ->routeUnregisterWebhooks($shopData, $marketplace, $appCode, false, $webhookCodes);
                } else {
                    $message = $this->di->getLocale()->_('shopify_unable_to_set_di');
                }
            }

            $this->di->getLog()->logContent('END', 'info', 'deleteShopifyWebhook.log');
            return [
                'success' => true,
                'message' => $message
            ];
        }
    }

    /**
     * setDiForUser function
     * To set current user in Di
     * @param [string] $userId
     * @return array
     */
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

    /**
     * getUserShop function
     * To get Shop Details by shopId
     * @param [string] $shopId
     * @return array
     */
    public function getUserShop($shopId)
    {
        if (!empty($shopId)) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $user_details = $mongo->getCollectionForTable('user_details');
            $userData = $user_details->findOne(
                ["shops.apps.app_status" => "active", "shops._id" => $shopId],
                ['projection' => ["_id" => false, "shops.$" => true, 'user_id' => true], 'typeMap' => ['root' => 'array', 'document' => 'array']]
            );

            if (!empty($userData)) {
                return ['success' => true, 'user' => $userData];
            }
            return ['success' => false, 'message' => 'wrong shop_id OR user not active'];
        }
        return ['success' => false, 'message' => 'shop_id not sent in params'];
    }

    public function routeUnregisterWebhooks($shop, $marketplace, $appCode, $appUninstalled = false, $webhookCodes = [], $targetConnected = true)
    {
        #TODO: remove unnecessary fields from shop
        $shopData = [
            '_id' => $shop['_id'],
            'marketplace' => $shop['marketplace'],
            'remote_shop_id' => $shop['remote_shop_id'],
            'apps' => $shop['apps'],
        ];

        $userId = $this->di->getUser()->id;
        $handlerData = [
            'type' => 'full_class',
            'class_name' => \App\Shopifyhome\Components\Manual\Helper::class,
            'method' => 'unregisterWebhooks',
            'queue_name' => 'unregister_webhook',
            'user_id' => $userId,
            'shop_id' => $shopData['_id'],
            'app_code' => $appCode,
            'data' => [
                'user_id' => $userId,
                'remote_shop_id' => $shop['remote_shop_id'],
                'marketplace' => $marketplace,
                'appCode' => $appCode,
                'shop' => $shopData,
                'cursor' => 0,
            ],
            'target_connected' => $targetConnected
        ];

        $subscriptionIds = [];
        if (isset($shop['apps'])) {
            $apps = $shop['apps'];
            $webhookIdUsedByNumberOfApps = [];
            foreach ($apps as $app) {
                if (isset($app['webhooks'])) {
                    foreach ($app['webhooks'] as $hook) {
                        if (isset($webhookIdUsedByNumberOfApps[$hook['dynamo_webhook_id']]))
                            $webhookIdUsedByNumberOfApps[$hook['dynamo_webhook_id']] += 1;
                        else {
                            $webhookIdUsedByNumberOfApps[$hook['dynamo_webhook_id']] = 1;
                        }
                    }
                }
            }
        }

        if (empty($webhookCodes)) {
            if (isset($shop['apps'])) {
                foreach ($apps as $app) {
                    if ($app['code'] == $appCode) {
                        if (isset($app['webhooks'])) {
                            foreach ($app['webhooks'] as $webhook) {
                                $subscriptionIds[] = $webhook['dynamo_webhook_id'];
                            }

                            break;
                        }
                    }
                }
            }
        } else {
            if (isset($shop['apps'])) {
                foreach ($apps as $app) {
                    if ($app['code'] == $appCode) {
                        if (isset($app['webhooks'])) {
                            foreach ($app['webhooks'] as $webhook) {
                                if (in_array($webhook['code'], $webhookCodes))
                                    $subscriptionIds[] = $webhook['dynamo_webhook_id'];
                            }

                            break;
                        }
                    }
                }
            }
        }

        if ($subscriptionIds) $handlerData['data']['subscription_ids'] = $subscriptionIds;

        if ($webhookIdUsedByNumberOfApps) $handlerData['data']['webhookIdUsedByNumberOfApps'] = $webhookIdUsedByNumberOfApps;

        if ($appUninstalled) {
            if (!empty($webhookIdUsedByNumberOfApps) && !empty($subscriptionIds)) {

                if (isset($shop['apps'])) {
                    foreach ($apps as $app) {
                        if ($app['code'] == $appCode) {
                            if (isset($app['webhooks'])) {
                                foreach ($app['webhooks'] as $webhook) {
                                    if (in_array($webhook['code'], $webhookCodes))
                                        $subscriptionIds[] = $webhook['dynamo_webhook_id'];
                                }

                                break;
                            }
                        }
                    }
                }

                return ['success' => true, "webhook_id_used_by_number_of_apps" => $webhookIdUsedByNumberOfApps, "subscription_ids" => $subscriptionIds];
            }
            return ['success' => false, "message" => "No webhooks to delete."];
        }

        $this->di->getLog()->logContent(date("Y-m-d H:i:s") . " Inside routeUnregisterWebhooks function : handlerData   ==>  " . print_r($handlerData, true), "info", "webhook" . DS . $userId . "_" . $handlerData['shop_id'] . "_" . $handlerData['app_code'] . DS . "unregister_webhook" . DS . date("Y-m-d") . DS . "sqs.log");
        return [
            'success' => true,
            'message' => 'Unregistering Webhooks ...',
            'queue_sr_no' => $this->di->getMessageManager()->pushMessage($handlerData),
        ];
    }

    public function unregisterWebhooks($sqsData)
    {
        $count = 0;
        $maxUnregisterLimit = 5;  // no of webhooks unregister in one process
        $cursor = $sqsData['data']['cursor'];
        $subscriptionIds = $sqsData['data']['subscription_ids'] ?? [];

        if (count($subscriptionIds) == 0) {
            $this->di->getLog()->logContent(date("Y-m-d H:i:s") . " Inside unregisterWebhooks function : There is no subscription to delete.", "info", "webhook" . DS . $sqsData['user_id'] . "_" . $sqsData['shop_id'] . "_" . $sqsData['app_code'] . DS . "unregister_webhook" . DS . date("Y-m-d") . DS . "sqs.log");
            return true;
        }

        while ($count < $maxUnregisterLimit && count($subscriptionIds) > $cursor) {
            if (count($subscriptionIds) > $cursor) {
                $this->removeWebhook($sqsData, $subscriptionIds[$cursor]);
            }

            $cursor += 1;
            $count += 1;
        }

        $sqsData['data']['cursor'] = $cursor;
        if (count($subscriptionIds) > $cursor) {
            $this->di->getMessageManager()->pushMessage($sqsData);
            return true;
        }
        if ($sqsData['target_connected']) {
            $this->registerSubscriptions(true);
        } else {
            $this->registerSubscriptions(false);
        }

        return true;
    }

    public function removeWebhook($sqsData, $subscriptionId): void
    {
        $this->di->getLog()->logContent(date("Y-m-d H:i:s") . " Inside removeWebhook function : Webhook id to unregister   ==>  " . print_r($subscriptionId, true), "info", "webhook" . DS . $sqsData['user_id'] . "_" . $sqsData['shop_id'] . "_" . $sqsData['app_code'] . DS . "unregister_webhook" . DS . date("Y-m-d") . DS . "webhook.log");
        $userId = $sqsData['data']['user_id'];
        $shop = $sqsData['data']['shop'];
        $appCode = $sqsData['data']['appCode'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $user = $mongo->getCollectionForTable("user_details");
        $webhookIdUsedByNumberOfApps  = $sqsData['data']['webhookIdUsedByNumberOfApps'];
        if (isset($webhookIdUsedByNumberOfApps[$subscriptionId]) && $webhookIdUsedByNumberOfApps[$subscriptionId] > 1) {
            $this->di->getLog()->logContent(date("Y-m-d H:i:s") . " Inside removeWebhook function : This webhook is used by number of apps, id:  " . print_r($subscriptionId, true) . "So removing it from db only.", "info", "webhook" . DS . $sqsData['user_id'] . "_" . $sqsData['shop_id'] . "_" . $sqsData['app_code'] . DS . "unregister_webhook" . DS . date("Y-m-d") . DS . "webhook.log");
            $removeFromUserdetail = true;
        } else {
            $remoteData = ['shop_id' => $shop['remote_shop_id'], 'subscription_id' => $subscriptionId, 'app_code' => "cedcommerce"];
            if (isset($sqsData['app_uninstalled']) && $sqsData['app_uninstalled']) $remoteData['app_uninstalled'] = $sqsData['app_uninstalled'];

            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('cedcommerce', true)
                ->call('event/subscription', [], $remoteData, 'DELETE');

            $this->di->getLog()->logContent(date("Y-m-d H:i:s") . " Inside removeWebhook function : Remove webhook remote response " . print_r($remoteResponse, true), "info", "webhook" . DS . $sqsData['user_id'] . "_" . $sqsData['shop_id'] . "_" . $sqsData['app_code'] . DS . "unregister_webhook" . DS . date("Y-m-d") . DS . "remote_calls.log");
        }

        try {
            if (isset($remoteResponse['success']) && $remoteResponse['success'] || (isset($removeFromUserdetail) && $removeFromUserdetail)) {
                $res = $user->updateOne(
                    [
                        "_id" => new ObjectId($userId),
                    ],
                    [
                        '$pull' => [
                            'shops.$[shop].apps.$[app].webhooks' => [
                                'dynamo_webhook_id' => $subscriptionId,
                            ],
                        ],
                    ],
                    [
                        'arrayFilters' => [
                            [
                                'shop.remote_shop_id' => $shop['remote_shop_id'],
                            ],
                            [
                                'app.code' => $appCode,
                            ],
                        ],
                    ]

                );
            }
        } catch (Exception $e) {
            print_r($e->getMessage());
        }
    }

    public function registerSubscriptions($targetConnected)
    {
        $shops = $this->di->getUser()->shops;
        $sourceMarketplace = 'shopify';
        $userId = $this->di->getUser()->id;
        $logFile = "shopifyWebhookSubscription/register/" . date('d-m-Y') . '.log';
        $object = $this->di->getObjectManager()->get(UserEvent::class);
        foreach ($shops as $shop) {
            if ($shop['marketplace'] == $sourceMarketplace) {
                $shopData = $shop;
                break;
            }
        }

        if (!empty($shopData)) {
            $this->di->getLog()->logContent('Registering Subscriptions for UserId=> ' . json_encode($userId, true), 'info', $logFile);
            $this->di->getLog()->logContent('HomeShopId => ' . json_encode($shopData['_id'], true), 'info', $logFile);
            $appCode = $shopData['apps'][0]['code'] ?? 'default';
            if ($targetConnected) {
                $getWebhookConfig = $this->di->getConfig()->webhook->get('amazon_sales_channel')->toArray();
                $webhookCodes = array_column($getWebhookConfig, "action");
                foreach ($webhookCodes as $key => $code) {
                    if ($code == 'app_delete') {
                        unset($webhookCodes[$key]);
                    }
                }
            } else {
                $webhookCodes = [
                    'product_listings_add',
                    'product_listings_update',
                    'product_listings/remove',
                    'inventory_levels_update',
                    'shop_update'
                ];
            }

            $createDestinationResponse = $object->createDestination($shopData, 'user_app');
            $appDeleteDestinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
            if (!$appDeleteDestinationId) {
                $this->di->getLog()->logContent('Unable to Create/Get app_delete destination user_id=> ' . json_encode($userId, true), 'info', $logFile);
            } else {
                $destinationWise[] = [
                    'destination_id' => $appDeleteDestinationId,
                    'event_codes' => [
                        'app_delete'
                    ]
                ];
            }

            $createDestinationResponse = $object->createDestination($shopData);
            $destinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
            if (!$destinationId) {
                $this->di->getLog()->logContent('Destination Not created ', 'info', $logFile);
                return ['success' => false, 'message' => 'Destination Not created'];
            }
            $destinationWise[] = [
                'destination_id' => $destinationId,
                'event_codes' => array_values($webhookCodes)
            ];
            unset($shopData['apps'][0]['webhooks']);
            $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks($shopData, $sourceMarketplace, $appCode, false, $destinationWise);
            $this->di->getLog()->logContent('Create Webhook Request Response=> ' . json_encode($createWebhookRequest, true), 'info', $logFile);
            $this->di->getLog()->logContent('END', 'info', $logFile);
        }
    }

    public function checkShopifyOrderByAttribute($data)
    {
        if (isset($data['user_id'], $data['shop_id'], $data['attribute_name'], $data['attribute_value'])) {
            $shopData = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class)
                ->getShop($data['shop_id'], $data['user_id']);
            if (!empty($shopData)) {
                $remoteShopId = $shopData['remote_shop_id'];
                $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init($shopData['marketplace'], true)
                    ->call('/order', [], ['shop_id' => $remoteShopId, $data['attribute_name'] => $data['attribute_value'], 'status' => 'any'], 'GET');
                if (isset($remoteResponse['success']) && $remoteResponse['success'] && !empty($remoteResponse['data'])) {
                    return ['success' => true, 'message' => 'Order exists on Shopify', 'data' => $remoteResponse['data']];
                } else {
                    $message = 'Order not found';
                }
            } else {
                $message = 'Shop not found';
            }
        } else {
            $message = 'Required fields are missing(user_id, shop_id, attribute_name, attribute_value)';
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    public function getReauthAppUrl($params)
    {
        if (isset($params['user_id'], $params['username'], $params['shop_id'])) {
            $shopData = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class)
                ->getShop($params['shop_id'], $params['user_id']);
            if (!empty($shopData)) {
                $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init($shopData['marketplace'], true)
                    ->call('shop/reauth/url', [], ['shop_url' => $params['username']], 'POST');
                return $remoteResponse;
            } else {
                $message = 'Shop not found';
            }
        } else {
            $message = 'Required fields are missing(user_id, username, shop_id)';
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    public function getStoreLockedUsers()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userCollection = $mongo->getCollectionForTable('user_details');
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

        $users = $userCollection->find(
            [
                'shops' => [
                    '$elemMatch' => [
                        'marketplace' => 'shopify',
                        'store_locked_at' => ['$exists' => true]
                    ]
                ]
            ],
            array_merge($options, [
                'projection' => [
                    'username' => 1,
                    'user_id' => 1,
                    'shops._id' => 1,
                    'shops.marketplace' => 1,
                    'shops.store_locked_at' => 1,
                ]
            ])
        )->toArray();

        if (!empty($users)) {
            $result = [];
            foreach ($users as $user) {
                foreach ($user['shops'] as $shop) {
                    if (($shop['marketplace'] ?? '') === 'shopify' && isset($shop['store_locked_at'])) {
                        $result[] = [
                            'username' => $user['username'] ?? '',
                            'user_id' => $user['user_id'] ?? '',
                            'shop_id' => $shop['_id'] ?? '',
                            'store_locked_at' => $shop['store_locked_at'] ?? '',
                        ];
                        break;
                    }
                }
            }
            return ['success' => true, 'data' => $result];
        }
        return ['success' => false, 'message' => 'No store locked users found'];
    }

    public function resolveStoreLocked($params)
    {
        if (isset($params['user_id'], $params['shop_id'])) {
            // Doing setDiForUser because updateShop not working in shopUpdate
            $this->setDiForUser($params['user_id']);
            $shop = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class)
                ->getShop($params['shop_id'], $params['user_id']);
            if (!empty($shop)) {
                $remoteData = ['shop_id' => $shop['remote_shop_id']];
                $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init($shop['marketplace'], true)
                    ->call('/shop', [], $remoteData, 'GET');
                if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                    if (!empty($remoteResponse['data'])) {
                        $preparedData = [
                            'shop' => $shop['domain'],
                            'data' => $remoteResponse['data'],
                            'user_id' => $params['user_id'],
                            'shop_id' => $shop['_id'],
                            'marketplace' => 'shopify'
                        ];
                        $this->di->getObjectManager()->get('\\App\\Shopifyhome\\Components\\Shop\\Hook')
                            ->shopUpdate($preparedData);
                        return ['success' => true, 'message' => 'Store lock resolved, shop reactivated'];
                    } else {
                        $message = 'Remote response data is empty';
                    }
                } else {
                    $message = 'Shop is still locked or unreachable';
                }
            } else {
                $message = 'Shop not found';
            }
        } else {
            $message = 'Required fields are missing(user_id, shop_id)';
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }
}
