<?php

namespace App\Connector\Components;

use App\Connector\Components\Webhook\SubscriptionService;

class Hook extends \App\Core\Components\Base

{
    public function TemporarlyUninstall($data, $userId = false, $appUninstallEvent = true)
    {
        $userId = $this->di->getUser()->id;
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array'], 'projection' => ['shops.$' => 1, '_id' => 0]];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollectionForTable('user_details');
        $dataToSendToMarketplace['user_id'] = $userId;

        if (isset($data['disconnected']['target']) && $data['disconnected']['target']) {
            $appCode = $this->di->getAppCode()->get()[$data['target']['marketplace']] ?? "";
            $userDetails = $userDetailsCollection->findOne([
            'user_id' => $userId, 
            'shops' => [
                    '$elemMatch' => [
                        'apps.code' => $appCode,
                        '_id' => $data['target']['shopId']
                    ]
                ]
            ], $options);
            $shop = $userDetails['shops'][0];
        } else if (isset($data['disconnected']['source']) && $data['disconnected']['source']) {
            $appCode = $this->di->getAppCode()->get()[$data['source']['marketplace']] ?? "";
            $userDetails = $userDetailsCollection->findOne([
                'user_id' => $userId,
                'shops' => [
                    '$elemMatch' => [
                        'apps.code' => $appCode,
                        '_id' => $data['source']['shopId']
                    ]
                ]
            ], $options);
            $shop = $userDetails['shops'][0];
        } else {
            $appCode = $data['app_code'] ?? $data['app_codes'][0] ?? "";
            if ($appCode !== "" && !isset($data['app_code'])) {
                $data['app_code'] = $appCode;
            }
            $userDetails = $userDetailsCollection->findOne([
                'user_id' => $userId,
                'shops' => [
                    '$elemMatch' => [
                        'apps.code' => $appCode,
                        '_id' => $data['shop_id']
                    ]
                ]
            ], $options);
            $shop = $userDetails['shops'][0];
        }
        if (empty($shop)) {
            $this->log($userId, "", 'result', 'Shop data not found');
            return ['success' => false, 'message' => "ShopData Not Found"];
        }

        if (!(isset($data['force_delete']) && $data['force_delete'])) {
            $appData = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($shop['marketplace'], false, $appCode)
                ->call('/app', [], ['shop_id' => $shop['remote_shop_id']], 'GET');
        }

        $moduleHome = ucfirst($shop['marketplace']);
        $moduleClass = '\App\\' . $moduleHome . '\Components\ShopDelete';

        if (!class_exists($moduleClass)) {
            $moduleHome = ucfirst($shop['marketplace']) . "home";
            $moduleClass = '\App\\' . $moduleHome . '\Components\ShopDelete';
        }

        if (class_exists($moduleClass)) {
            try {
                $moduleObject = $this->di->getObjectManager()->get($moduleClass);
            } catch (\Exception $e) {
                $this->log($userId, $shop['_id'], 'result', 'Error creating ShopDelete object');
                return ['success' => false, 'message' => "Error creating ShopDelete object"];
            }
        } else {
            $this->log($userId, $shop['_id'], 'result', 'ShopDelete Class Not Found');
            return ['success' => false, 'message' => "ShopDelete Class Not Found"];
        }

        if (class_exists('\App\\' . $moduleHome . '\Components\ShopDelete') && method_exists('\App\\' . $moduleHome . '\Components\ShopDelete', 'isAppActive')) {
            $marketplaceResponse = $moduleObject->isAppActive([
                $shop['marketplace'],
                $shop['remote_shop_id'],
                $appCode,
                "marketplace" => $shop['marketplace'],
                'remote_shop_id' => $shop['remote_shop_id'],
                'app_code' => $appCode,
            ]);
            if (isset($marketplaceResponse['success']) && $marketplaceResponse['success']) {
                return;
            }
        }

        $eventsManager = $this->di->getEventsManager();
        if ((isset($appData['success']) && $appData['success']) || (isset($data['force_delete']) && $data['force_delete'])) {
            $appData = $appData['app_config'] ?? "";
            $eraseDataAfterDays = $appData['erase_data_after_uninstall'] ?? 0;
            $eraseDataAfterUnit = $appData['erase_data_after_unit'] ?? 'minutes';
            $eraseDataAfterDate = date('c', strtotime("+{$eraseDataAfterDays} {$eraseDataAfterUnit}"));
            $uninstallDate = date('c');
            if (isset($data['source'], $data['target'], $data['disconnected'])) {
                $disconnectAccountResponse = $this->disconnectAccount($data, $eraseDataAfterDate, $uninstallDate);
                if (isset($data['disconnected']['target'])) {
                    $targetAppCode = $this->di->getAppCode()->get()[$data['target']['marketplace']] ?? "";
                    $result = $this->setUninstallStatusInAppForDisconnectAccount($data['target']['shopId'], $targetAppCode);
                    if (isset($result['success']) && $result['success']) {
                        $this->uninstallUserDetails($shop, $appCode);
                        $eventsManager->fire('application:targetDisconnect', $this, ['shop' => $shop, 'app_code' => $appCode]);
                    }
                }

                if (isset($data['disconnected']['source'])) {
                    $sourceAppCode = $this->di->getAppCode()->get()[$data['source']['marketplace']] ?? "";
                    $result = $this->setUninstallStatusInAppForDisconnectAccount($data['source']['shopId'], $sourceAppCode);
                    if (isset($result['success']) && $result['success']) {
                        $this->uninstallUserDetails($shop, $appCode);
                        $eventsManager->fire('application:sourceDisconnect', $this, ['shop' => $shop, 'app_code' => $appCode]);
                    }
                }

                $dataToSendToMarketplace['source_shop_id'] = $data['source']['shopId'];
                $dataToSendToMarketplace['target_shop_id'] = $data['target']['shopId'];
                if (isset($data['deletion_in_progress'])) {
                    $dataToSendToMarketplace['deletion_in_progress'] = true;
                }

                if (isset($data['disconnected']['target'])) {
                    $this->sendDataToMarketplace([$data['source']], "temporarlyUninstall", $dataToSendToMarketplace);
                }

                if (isset($data['disconnected']['source'])) {
                    $this->sendDataToMarketplace([$data['target']], "temporarlyUninstall", $dataToSendToMarketplace);
                }
            } else {
                $setUninstallDateInApp = $this->setUninstallDateInApp($shop, $appCode, $eraseDataAfterDate, $uninstallDate);
                $setStatusInSourcesAndTargets = $this->setStatusInSourcesAndTargets($shop, $appCode);
                $uninstallUserDetails = $this->uninstallUserDetails($shop, $appCode);
                $host = $this->getHost();
                if($appUninstallEvent) {
                    $eventsManager->fire('application:appUninstall', $this, [
                        'shop' => $shop,
                        'app_code' => $appCode,
                        "backend_host" => $host,
                        "user" => $this->removeUserFields($this->di->getUser()->toArray()),
                    ]);
                }
                $dataToSendToMarketplace['shop_id'] = $data['shop_id'];
                $dataToSendToMarketplace['app_code'] = $data['app_code'];
                if (isset($data['deletion_in_progress'])) {
                    $dataToSendToMarketplace['deletion_in_progress'] = true;
                }

                $eventData = $this->prepareDataToSendMarketplacesForShopEraser($shop, $appCode);
                if (isset($eventData['success']) && $eventData['success']) {
                    $this->sendDataToMarketplace($eventData['shop']['targets'], "temporarlyUninstall", $dataToSendToMarketplace);
                }
            }

            $sendDataToMarketplaceResponse = $this->sendDataToMarketplace([['marketplace' => $shop['marketplace']]], "temporarlyUninstall", $dataToSendToMarketplace);
            // Delete shop related entries directly without waiting for CRON process in case of force_delete
            if (isset($data['force_delete']) && $data['force_delete']) {
                $this->appDelete();
            }

            return ['success' => true, 'message' => "Successfully added uninstall key and disconnected fields."];
        }

        $this->log($userId, $shop['_id'], 'result', json_encode($appData));
        return ['success' => false, 'message' => $appData];
    }

    private function removeUserFields(array $userDetails): array
    {
        unset(
            $userDetails['password'],
            $userDetails["source_email"],
            $userDetails['last_used_passwords'],
            $userDetails['created_at'],
            $userDetails['role_id'],
            $userDetails['confirmation'],
            $userDetails['status'],
            $userDetails['sqlConfig'],
            $userDetails['_id']
        );
        return $userDetails;
    }

    private function getHost()
    {
        $config = $this->di->getConfig();
        return $config->get('backend_base_url') ?? $config->get('backend_home_url') ?? $config->get('home_base_url') ?? $config->get('frontend_app_url') ?? gethostname();
    }

    public function appDelete()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $query = [
            [
                '$match' => [
                    'shops' => [
                            '$elemMatch' => [
                                '$or' => [
                                    [
                                        'apps.app_status' => "uninstall",
                                        'apps.erase_data_after_date' => ['$lte' => date('c')],
                                        'apps.temporarly_uninstall' => true,
                                        'pushed_to_uninstall_queue' => ['$exists' => false],
                                        'do_not_erase' => ['$exists' => false],
                                    ],
                                    [
                                        'sources.disconnected' => true,
                                        'sources.erase_data_after_date' => ['$lte' => date('c')],
                                        'pushed_to_uninstall_queue' => ['$exists' => false],
                                        'do_not_erase' => ['$exists' => false],
                                    ],
                                    [
                                        'targets.disconnected' => true,
                                        'targets.erase_data_after_date' => ['$lte' => date('c')],
                                        'pushed_to_uninstall_queue' => ['$exists' => false],
                                        'do_not_erase' => ['$exists' => false],
                                    ],
                                ]
                            ]
                        ],

                ],
            ],
            [
                '$unwind' => '$shops',
            ],
            [
                '$match' => [
                    '$or' => [
                        [
                            'shops.apps.app_status' => "uninstall",
                            'shops.apps.erase_data_after_date' => ['$lte' => date('c')],
                            'shops.apps.temporarly_uninstall' => true,
                            'shops.pushed_to_uninstall_queue' => ['$exists' => false],
                            'shops.do_not_erase' => ['$exists' => false],
                        ],
                        [
                            'shops.sources.disconnected' => true,
                            'shops.sources.erase_data_after_date' => ['$lte' => date('c')],
                            'shops.pushed_to_uninstall_queue' => ['$exists' => false],
                            'shops.do_not_erase' => ['$exists' => false],
                        ],
                        [
                            'shops.targets.disconnected' => true,
                            'shops.targets.erase_data_after_date' => ['$lte' => date('c')],
                            'shops.pushed_to_uninstall_queue' => ['$exists' => false],
                            'shops.do_not_erase' => ['$exists' => false],
                        ],
                    ],

                ],
            ],
            [
                '$limit' => 100,
            ],
        ];

        $handlerData = [
            'type' => 'full_class',
            'class_name' => '\App\Connector\Components\Hook',
            'method' => 'shopEraser',
            'queue_name' => 'uninstall_shop_eraser',
        ];
        try {
            while (true) {
                $userDetails = $collection->aggregate($query)->toArray();
                foreach ($userDetails as $user) {
                    if(!$this->checkShopErasable($user['shops'])) {
                        continue;
                    }
                    $data['shop'] = $user['shops'];
                    $handlerData['data'] = $data;
                    $handlerData['user_id'] = $user['user_id'];
                    $handlerData['shop_id'] = $user['shops']['_id'];
                    $handlerData['marketplace'] = $user['shops']['marketplace'];
                    $this->di->getMessageManager()->pushMessage($handlerData);
                    $collection->updateOne(
                        ['user_id' => $user['user_id']],
                        ['$set' => ['shops.$[shop].pushed_to_uninstall_queue' => true]],
                        [
                            'arrayFilters' => [
                                ['shop._id' => $user['shops']['_id']],
                            ],
                        ]
                    );
                }

                if (count($userDetails) == 0 || count($userDetails) < 99) {
                    break;
                }
            }

            /*$collection->updateMany(
            ['shops' => ['$exists' => true]],
            [
            '$unset' => [
            'shops.$[shop].pushed_to_uninstall_queue' => true
            ]
            ],
            [
            'arrayFilters' => [
            ['shop.pushed_to_uninstall_queue' => ['$exists' => true]]
            ]
            ]
            );*/
            return true;
        } catch (\Exception $e) {
            $this->log($user['user_id'], $user['shops']['_id'], 'result', $e->getMessage());
            return false;
        }
    }

    private function checkShopErasable($shop)
    {
        $sourceTargetDisconnect = false;
        if (!empty($shop['apps'])) {
            foreach ($shop['apps'] as $app) {
                if ($app['app_status'] == 'uninstall' && empty($app['erase_data_after_date'])) {
                    $sourceTargetDisconnect = true;
                    break;
                }
                if (!empty($app['erase_data_after_date']) &&
                    strtotime($app['erase_data_after_date']) <= strtotime('now')) {
                    return true;
                }
            }
        }
        if ($sourceTargetDisconnect) {
            $key = !empty($shop['sources']) ? 'sources' : (!empty($shop['targets']) ? 'targets' : null);
            if($key) {
                foreach ($shop[$key] as $sourceOrTarget) {
                    if (!empty($sourceOrTarget['erase_data_after_date']) &&
                        strtotime($sourceOrTarget['erase_data_after_date']) <= strtotime('now')) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function shopEraser($sqsData)
    {
        try {
            $userId = $sqsData['user_id'];
            $shopId = $sqsData['shop_id'];
            $marketplace = $sqsData['marketplace'];
            $shop = $sqsData['data']['shop'];
            $apps = $shop['apps'];
            foreach ($apps as $app) {
                if (isset($app['app_status']) && $app['app_status'] == 'active') {
                    return $this->revertShopDelete($userId, $shopId, $app);
                }


                if (isset($app['app_status'], $app['uninstall_date'], $app['erase_data_after_date']) && $app['app_status'] == "uninstall" && $app['erase_data_after_date'] <= date('c')) {
                    $appCode = $app['code'];
                    $dbShop = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')->getShop($shopId, $userId);
                    foreach ($dbShop['apps'] as $appInDbShop) {
                        if ($appInDbShop['code'] == $appCode && $appInDbShop['app_status'] == "active" && !isset($appInDbShop['erase_data_after_date'], $appInDbShop['uninstall_date'], $appInDbShop['temporarly_uninstall'])) {
                            $pingResponse['success'] = true;
                            break;
                        }
                    }

                    if (isset($pingResponse['success']) && $pingResponse['success']) {
                        return $this->revertShopDelete($userId, $shopId, $app);
                    }

                    $eventData = $this->prepareDataToSendMarketplacesForShopEraser($shop, $appCode);
                    if (isset($eventData['success']) && $eventData['success']) {
                        $eventData['shop']['uninstalling_app_code'] = $appCode;
                        $this->sendDataToMarketplace($eventData['shop']['targets'], "shopEraserBefore", $eventData['shop']);
                        $this->sendDataToMarketplace([['marketplace' => $marketplace]], "shopEraserBefore", $eventData['shop']);
                    }

                    $this->removeDisconnectedTargetsAndSources($shop, $appCode);
                    $this->deleteDocumentsOnBasisOfAppCode($shop, $appCode);
                }
            }

            if (!isset($appCode)) {
                $eventData = $this->prepareDataToSendMarketplacesForShopEraser($shop);
                if (isset($eventData['success']) && $eventData['success']) {
                    $this->sendDataToMarketplace([['marketplace' => $shop['marketplace']]], "shopEraserBefore", $eventData['shop']);
                    $this->sendDataToMarketplace($eventData['shop']['targets'], "shopEraserBefore", $eventData['shop']);
                }
            }

            $this->removeDisconnectedTargetsAndSources($shop);
            $this->deleteAppIfNoSourcesTargetsConnnected($shopId);
            $this->deleteUninstallShop($sqsData);
            $this->deleteUninstallShopsUser($sqsData);
            if (isset($eventData['success']) && $eventData['success']) {
                $this->sendDataToMarketplace([['marketplace' => $marketplace]], "shopEraserAfter", $eventData['shop']);
                $this->sendDataToMarketplace($eventData['shop']['targets'], "shopEraserAfter", $eventData['shop']);
            }

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function revertShopDelete(string $userId, string $shopId, array $app)
    {
        if (!isset($app['erase_data_after_date'], $app['uninstall_date'], $app['code'])) {
            return true;
        }
        $appCode = $app['code'];
        $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
        $userDetailCollection = $mongo->getCollectionForTable("user_details");
        $result = $userDetailCollection->updateOne(
            ["user_id" => $userId],
            [
                '$unset' => [
                    'shops.$[shop].apps.$[app].erase_data_after_date' => $app['erase_data_after_date'],
                    'shops.$[shop].apps.$[app].uninstall_date' => $app['uninstall_date'],
                    'shops.$[shop].apps.$[app].temporarly_uninstall' => true,
                ],
            ],
            [
                'arrayFilters' => [
                    [
                        'shop._id' => $shopId,
                    ],
                    [
                        'app.code' => $appCode,
                    ],
                ],
            ]
        );

        return true;
    }

    public function deleteUninstallShop($sqsData)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailCollection = $mongo->getCollectionForTable('user_details');
            $userId = $sqsData['user_id'];
            $shopId = $sqsData['shop_id'];
            $result = $userDetailCollection->findOne([
                "user_id" => $userId,
                "shops" => [
                    '$elemMatch' => [
                        '_id' => $shopId,
                        "apps" => ['$size' => 0],
                    ],
                ],
            ]);

            if ($result) {
                // 1.Remove all data present in other collections using shop_id
                $notificationCollection = $mongo->getCollectionForTable('notifications');
                $response = $notificationCollection->deleteMany(['user_id' => $userId, 'shop_id' => $shopId]);
                $queuedTaskCollection = $mongo->getCollectionForTable('queued_tasks');
                $response = $queuedTaskCollection->deleteMany(['user_id' => $userId, 'shop_id' => $shopId]);
                // 2. Remove shop entry from user_detals collection
                $userDetailCollection->updateOne(
                    ["user_id" => $userId],
                    [
                        '$pull' => ["shops" => ["_id" => $shopId]],
                    ]
                );
                // 3. Delete temp_webhook_container
                $tempWebhookContainer = $mongo->getCollectionForTable('temp_webhook_container');
                $tempWebhookContainer->deleteMany(
                    ['user_id' => $userId, 'shop_id' => $shopId]
                );
            } else {
                $this->log($userId, $shopId, 'result', 'Shop contains active shops');
            }

            return true;
        } catch (\Exception $e) {
            $this->log($userId, $shopId, 'result', $e->getMessage());
            return false;
        }
    }

    public function deleteUninstallShopsUser($sqsData)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailCollection = $mongo->getCollectionForTable('user_details');
            $tokenManagerCollection = $mongo->getCollectionForTable('token_manager');
            $userId = $sqsData['user_id'];
            $shopId = $sqsData['shop_id'];
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $result = $userDetailCollection->find(["user_id" => $userId, "shops" => ['$size' => 0]], $options)->toArray();
            if ($result) {
                $tokenResult = $tokenManagerCollection->deleteMany(['user_id' => $this->di->getUser()->_id]);

                // Delete user form user_detail collection
                $this->di->getEventsManager()->fire('application:userShopDataFlush', $this, [
                    'user' => $result,
                    'shop' => $sqsData['data']['shop'] ?? []
                ]);
                $result = $userDetailCollection->deleteOne(['user_id' => $userId]);
                if ($result->getDeletedCount() === 0) {
                    $this->log($userId, $shopId, 'result', 'User holds running shops');
                }
            } else {
                $this->log($userId, $shopId, 'result', 'User holds running shops');
            }

            return true;
        } catch (\Exception $e) {
            $this->log($userId, $shopId, 'result', $e->getMessage());
            return false;
        }
    }

    public function handleWebhooksAfterAppUninstall($shop, $appCode)
    {
        $isVersion2 = $this->di->getConfig()->path("webhooks.version", "v1") == "v2";
        if ($isVersion2) {
            $this->cancelSubscriptions($shop, $appCode);
            $this->setTemporarlyUninstallCompleted($shop['_id'], $appCode);
            return;
        }
        try {
            $userId = $this->di->getUser()->id;
            $shopId = $shop['_id'];
            $marketplace = $shop['marketplace'];
            foreach ($shop['apps'] as $app) {
                if ($app['code'] == $appCode) {
                    if (isset($app['webhooks'])) {
                        $response = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeUnregisterWebhooks($shop, $shop['marketplace'], $appCode, true);
                        if (isset($response['success']) && $response['success']) {
                            $this->deleteSubscriptions($shop, $response['webhook_id_used_by_number_of_apps'], $response['subscription_ids'], $appCode);
                        }
                    }
                    break;
                }
            }

            $updateCedAppShopResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($marketplace, false, $appCode)
                ->call('update/ced_app_shops', [], ['shop_id' => $shop['remote_shop_id']], 'PUT');
            $setTemporarlyUninstallResponse = $this->setTemporarlyUninstallCompleted($shopId, $appCode);
            return ['success' => true, 'message' => 'webhooks handled successfully.'];
        } catch (\Exception $e) {
            $this->log($userId, $shopId, 'result', $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function cancelSubscriptions(array $shop, string $appCode): void
    {
        $userId = $this->di->getUser()->id;
        $shopId = $shop['_id'];
        $marketplace = $shop['marketplace'];
        foreach ($shop['apps'] as $app) {
            if ($app['code'] != $appCode || !isset($app['webhooks'])) {
                continue;
            }
            try {
                $response = $this->di->getObjectManager()->get(SubscriptionService::class)->unsubscribe([
                    'marketplace' => $marketplace,
                    'app_code' => $appCode,
                    'shop_id' => $shopId,
                ]);
            } catch (\Exception $e) {
                $this->log($userId, $shopId, 'result', $e->getMessage());
            }
        }
    }

    public function deleteSubscriptions($shop, $webhookIdUsedByNumberOfApps, $subscriptionsIds, $appCode)
    {
        $userId = $this->di->getUser()->id;
        $shopId = $shop['_id'];
        $bulkDeleteIds = [];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailCollection = $mongo->getCollectionForTable('user_details');
        foreach ($subscriptionsIds as $subscriptionId) {
            if (isset($webhookIdUsedByNumberOfApps[$subscriptionId]) && $webhookIdUsedByNumberOfApps[$subscriptionId] == 1) {
                array_push($bulkDeleteIds, $subscriptionId);
            }
        }

        // remove entries from webhook array
        $userDetailCollection->updateOne(
            [
                "_id" => new \MongoDB\BSON\ObjectId($userId),
            ],
            [
                '$pull' => [
                    'shops.$[shop].apps.$[app].webhooks' => [
                        'dynamo_webhook_id' => ['$in' => $bulkDeleteIds],
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

        if (!empty($bulkDeleteIds)) {
            $remoteData = ['shop_id' => $shop['remote_shop_id'], 'subscription_ids' => $bulkDeleteIds, 'app_code' => "cedcommerce"];
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('cedcommerce', true)
                ->call('bulkDelete/subscription', [], $remoteData, 'DELETE');
            if (!$remoteResponse['success']) {
                $this->log($userId, $shopId, 'result', json_encode($remoteResponse));
            }
        }

        return ['success' => true, 'message' => 'subscription deleted successfully.'];
    }

    public function disconnectAccount($data, $eraseDataAfterDate, $uninstallDate)
    {
        try {
            $userId = $this->di->getUser()->id;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailCollection = $mongo->getCollectionForTable('user_details');
            $targetShopId = $data['target']['shopId'];
            $sourceShopId = $data['source']['shopId'];
            $targetAppCode = $this->di->getAppCode()->get()[$data['target']['marketplace']] ?? "";
            $sourceAppCode = $this->di->getAppCode()->get()[$data['source']['marketplace']] ?? "";
            $deletionInProgress = $data['deletion_in_progress'] ?? false;
            $appTag = $this->di->getAppCode()->getAppTag() ?? "";
            $query = $deletionInProgress ? ['shops.$[shop].deletion_in_progress' => true] : [];

            if (isset($sourceShopId, $targetShopId, $sourceAppCode, $targetAppCode, $eraseDataAfterDate)) {
                if (isset($data['disconnected']['target']) && $data['disconnected']['target']) {
                    $targetUpdateResponse = $userDetailCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$set' => [
                                'shops.$[shop].targets.$[target].disconnected' => true,
                            ],
                        ],
                        [
                            'arrayFilters' => [
                                ['shop._id' => $sourceShopId],
                                ['target.shop_id' => $targetShopId, 'target.app_code' => $targetAppCode],
                            ],
                        ]
                    );
                    $sourceUpdateResponse = $userDetailCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$set' => [
                                'shops.$[shop].sources.$[source].disconnected' => true,
                                'shops.$[shop].sources.$[source].erase_data_after_date' => $eraseDataAfterDate,
                                'shops.$[shop].sources.$[source].uninstall_date' => $uninstallDate,
                                'shops.$[shop].sources.$[source].uninstall_app_code' => $targetAppCode,
                                'shops.$[shop].sources.$[source].app_tag' => $appTag,
                            ] + $query,
                        ],
                        [
                            'arrayFilters' => [
                                ['shop._id' => $targetShopId],
                                ['source.shop_id' => $sourceShopId, 'source.app_code' => $sourceAppCode],
                            ],
                        ]
                    );
                }

                if (isset($data['disconnected']['source']) && $data['disconnected']['source']) {
                    $targetUpdateResponse = $userDetailCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$set' => [
                                'shops.$[shop].targets.$[target].disconnected' => true,
                                'shops.$[shop].targets.$[target].erase_data_after_date' => $eraseDataAfterDate,
                                'shops.$[shop].targets.$[target].uninstall_date' => $uninstallDate,
                                'shops.$[shop].targets.$[target].uninstall_app_code' => $sourceAppCode,
                                'shops.$[shop].targets.$[target].app_tag' => $appTag,
                            ] + $query,
                        ],
                        [
                            'arrayFilters' => [
                                ['shop._id' => $sourceShopId],
                                ['target.shop_id' => $targetShopId, 'target.app_code' => $targetAppCode],
                            ],
                        ]
                    );
                    $sourceUpdateResponse = $userDetailCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$set' => [
                                'shops.$[shop].sources.$[source].disconnected' => true,
                            ],
                        ],
                        [
                            'arrayFilters' => [
                                ['shop._id' => $targetShopId],
                                ['source.shop_id' => $sourceShopId, 'source.app_code' => $sourceAppCode],
                            ],
                        ]
                    );
                }

                if ($targetUpdateResponse->getModifiedCount() && $sourceUpdateResponse->getModifiedCount()) {
                    return ['success' => true, 'message' => 'Successfully inserted uninstall keys'];
                }

                return ['success' => false, 'message' => 'Trouble in inserting uninstall keys'];
            }

            return ['success' => false, 'message' => 'Information missing'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function setUninstallStatusInAppForDisconnectAccount($shopId, $appCode)
    {
        try {
            $userId = $this->di->getUser()->id;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailCollection = $mongo->getCollectionForTable('user_details');
            // We have to write code to set app_status if all of the source and targets using this appCode is disconnected
            $result = $userDetailCollection->aggregate([
                ['$match' => ['user_id' => $userId]],
                ['$unwind' => '$shops'],
                ['$unwind' => '$shops.targets'],
                ['$match' => ['shops.targets.shop_id' => $shopId, 'shops.targets.app_code' => $appCode, 'shops.targets.disconnected' => ['$exists' => false]]],
            ])->toArray();
            if (empty($result)) {
                $result = $userDetailCollection->aggregate([
                    ['$match' => ['user_id' => $userId]],
                    ['$unwind' => '$shops'],
                    ['$unwind' => '$shops.sources'],
                    ['$match' => ['shops.sources.shop_id' => $shopId, 'shops.sources.app_code' => $appCode, 'shops.sources.disconnected' => ['$exists' => false]]],
                ])->toArray();
                if (empty($result)) {
                    $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->setStatusInUser("uninstall", $userId, $shopId, $appCode);
                    return ['success' => true, 'message' => 'App status set to uninstall successfully'];
                }
            }

            return ['success' => false, 'message' => 'App contains active sources and targets'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function setStatusInSourcesAndTargets($shop, $appCode)
    {
        try {
            $userId = $this->di->getUser()->id;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailCollection = $mongo->getCollectionForTable('user_details');
            $sources = $shop['sources'] ?? [];
            $targets = $shop['targets'] ?? [];
            foreach ($sources as $source) {
                $result = $userDetailCollection->updateOne(
                    ['user_id' => $userId],
                    ['$set' => ['shops.$[shop].targets.$[target].disconnected' => true]],
                    [
                        'arrayFilters' => [
                            ['shop._id' => $source['shop_id']],
                            ['target.shop_id' => $shop['_id'], 'target.app_code' => $appCode],
                        ],
                    ]

                );
                if ($result->getModifiedCount() > 0) {
                    $userDetailCollection->updateOne(
                        ['user_id' => $userId],
                        ['$set' => ['shops.$[shop].sources.$[source].disconnected' => true]],
                        [
                            'arrayFilters' => [
                                ['shop._id' => $shop['_id']],
                                ['source.shop_id' => $source['shop_id'], 'source.app_code' => $source['app_code']],
                            ],
                        ]

                    );
                }
            }

            foreach ($targets as $target) {
                $result = $userDetailCollection->updateOne(
                    ['user_id' => $userId],
                    ['$set' => ['shops.$[shop].sources.$[source].disconnected' => true]],
                    [
                        'arrayFilters' => [
                            ['shop._id' => $target['shop_id']],
                            ['source.shop_id' => $shop['_id'], 'source.app_code' => $appCode],
                        ],
                    ]
                );
                if ($result->getModifiedCount() > 0) {
                    $userDetailCollection->updateOne(
                        ['user_id' => $userId],
                        ['$set' => ['shops.$[shop].targets.$[target].disconnected' => true]],
                        [
                            'arrayFilters' => [
                                ['shop._id' => $shop['_id']],
                                ['target.shop_id' => $target['shop_id'], 'target.app_code' => $target['app_code']],
                            ],
                        ]

                    );
                }
            }

            return ['success' => true, 'message' => "Disconnected key added successfully."];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function setUninstallDateInApp($shop, $appCode, $eraseDataAfterDate, $uninstallDate)
    {
        try {
            $userId = $this->di->getUser()->id;
            $shopId = $shop['_id'];
            $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->setStatusInUser("uninstall", $userId, $shopId, $appCode);
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $result = $userDetailsCollection->updateMany(
                [
                    "user_id" => $userId,
                ],
                [
                    '$set' => [
                        'shops.$[shop].apps.$[app].uninstall_date' => $uninstallDate,
                        'shops.$[shop].apps.$[app].erase_data_after_date' => $eraseDataAfterDate,
                    ],
                    '$unset' => [
                        'shops.$[shop].apps.$[app].webhooks' => true,
                    ],
                ],
                [
                    'arrayFilters' => [
                        [
                            'shop._id' => $shopId,
                        ],
                        [
                            'app.code' => $appCode,
                        ],
                    ],
                ]
            );
            if ($result->getModifiedCount()) {
                if ($mongo->getCollectionForTable('user_details')->count(['shops.remote_shop_id' => $shop['remote_shop_id']]) == 1) {
                    $setUninstallKeyInAppShop = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init($shop['marketplace'], false, $appCode)
                        ->call('set/appUninstall', [], ['shop_id' => $shop['remote_shop_id']], 'PUT');

                    if (!$setUninstallKeyInAppShop['success']) {
                        $this->log($userId, $shopId, 'result', json_encode($setUninstallKeyInAppShop));
                    }
                }

                $handleWebhookResponse = $this->handleWebhooksAfterAppUninstall($shop, $appCode);
                return ['success' => true, 'message' => "Uninstall and erase date set successfully", 'response' => $handleWebhookResponse];
            }

            return ['success' => false, 'message' => "Trouble in setting uninstall and erase date"];
        } catch (\Exception $e) {
            $this->log($userId, $shopId, 'result', $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sendDataToMarketplace($destinations, $functionName, $data)
    {
        try {
            foreach ($destinations as $destination) {
                $moduleHome = ucfirst($destination['code'] ?? $destination['marketplace']);
                $moduleClass = '\App\\' . $moduleHome . '\Components\ShopDelete';

                if (!class_exists($moduleClass)) {
                    $moduleHome = ucfirst($destination['code'] ?? $destination['marketplace']) . "home";
                    $moduleClass = '\App\\' . $moduleHome . '\Components\ShopDelete';
                }

                if (class_exists($moduleClass)) {
                    try {
                        $moduleObject = $this->di->getObjectManager()->get($moduleClass);
                    } catch (\Exception $e) {
                        return ['success' => false, 'message' => "Error creating ShopDelete object"];
                    }
                } else {
                    return ['success' => false, 'message' => "ShopDelete Class Not Found"];
                }

                if (class_exists('\App\\' . $moduleHome . '\Components\ShopDelete') && method_exists('\App\\' . $moduleHome . '\Components\ShopDelete', $functionName)) {
                    $moduleObject->$functionName($data);
                }
            }

            return ['success' => true, 'message' => "Successfully send data to marketplaces"];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function removeDisconnectedTargetsAndSources($shop, $appCode = false)
    {
        try {
            $userId = $this->di->getUser()->id;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $sources = isset($shop['sources']) ? $shop['sources'] : [];
            $targets = isset($shop['targets']) ? $shop['targets'] : [];

            foreach ($sources as $source) {
                if (isset($source['disconnected']) && $source['disconnected'] && $appCode != false) {
                    $result = $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        ['$pull' => ['shops.$[shop].targets' => ['shop_id' => $shop['_id'], 'app_code' => $appCode, 'disconnected' => true]]],
                        [
                            'arrayFilters' => [
                                ['shop._id' => $source['shop_id']],
                            ],
                        ]
                    );
                    if ($result->getModifiedCount() > 0) {
                        $userDetailsCollection->updateOne(
                            ['user_id' => $userId],
                            ['$pull' => ['shops.$[shop].sources' => ['shop_id' => $source['shop_id'], 'app_code' => $source['app_code'], 'disconnected' => true]]],
                            [
                                'arrayFilters' => [
                                    ['shop._id' => $shop['_id']],
                                ],
                            ]
                        );
                        $this->deleteDocumentsOnBasisOfAppTagSourceIdTargetId($source['shop_id'], $shop['_id']);
                    } else {
                        $this->log($userId, $source['shop_id'], 'result', 'Failed to delete source');
                    }
                }

                if (isset($source['disconnected'], $source['uninstall_date'], $source['uninstall_app_code'], $source['erase_data_after_date']) && $source['disconnected'] && $source['erase_data_after_date'] <= date("c")) {
                    $isReconnected = $userDetailsCollection->aggregate([
                        ['$match' => ['user_id' => $userId]],
                        ['$unwind' => '$shops'],
                        ['$match' => ['user_id' => $userId, 'shops._id' => $shop['_id'], 'shops.sources.shop_id' => $source['shop_id'], 'shops.sources.app_code' => $source['app_code'], 'shops.sources.disconnected' => ['$exists' => false]]],
                    ])->toArray();
                    if (empty($isReconnected)) {
                        $this->beforeLastTargetDisconnect($userId, $source['shop_id'], $shop['_id']);
                        $result = $userDetailsCollection->updateOne(
                            ['user_id' => $userId],
                            ['$pull' => ['shops.$[shop].targets' => ['shop_id' => $shop['_id'], 'app_code' => $source['uninstall_app_code'], 'disconnected' => true]]],
                            [
                                'arrayFilters' => [
                                    ['shop._id' => $source['shop_id']],
                                ],
                            ]
                        );
                        if ($result->getModifiedCount() > 0) {
                            $userDetailsCollection->updateOne(
                                ['user_id' => $userId],
                                ['$pull' => ['shops.$[shop].sources' => ['shop_id' => $source['shop_id'], 'app_code' => $source['app_code'], 'uninstall_app_code' => $source['uninstall_app_code'], 'disconnected' => true]]],
                                [
                                    'arrayFilters' => [
                                        ['shop._id' => $shop['_id']],
                                    ],
                                ]
                            );
                            $appTag = $source['app_tag'];
                            $this->deleteDocumentsOnBasisOfAppTagSourceIdTargetId($source['shop_id'], $shop['_id'], $appTag);
                            $this->deleteDocumentsOnBasisOfShopIdAppTag($shop['_id'], $appTag);
                        } else {
                            $this->log($userId, $shop['_id'], 'result', 'Source could not be deleted');
                        }
                    } else {
                        $this->log($userId, $shop['_id'], 'result', 'This source is reconnected');
                    }
                }
            }

            foreach ($targets as $target) {
                if (isset($target['disconnected']) && $target['disconnected'] && $appCode != false) {
                    $result = $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        ['$pull' => ['shops.$[shop].sources' => ['shop_id' => $shop['_id'], 'app_code' => $appCode, 'disconnected' => true]]],
                        [
                            'arrayFilters' => [
                                ['shop._id' => $target['shop_id']],
                            ],
                        ]
                    );
                    if ($result->getModifiedCount() > 0) {
                        $userDetailsCollection->updateOne(
                            ['user_id' => $userId],
                            ['$pull' => ['shops.$[shop].targets' => ['shop_id' => $target['shop_id'], 'app_code' => $target['app_code'], 'disconnected' => true]]],
                            [
                                'arrayFilters' => [
                                    ['shop._id' => $shop['_id']],
                                ],
                            ]
                        );
                        $this->deleteDocumentsOnBasisOfAppTagSourceIdTargetId($shop['_id'], $target['shop_id']);
                    } else {
                        $this->log($userId, $target['shop_id'], 'result', 'Failed to delete the target');
                    }
                }

                if (isset($target['disconnected'], $target['uninstall_date'], $target['uninstall_app_code'], $target['erase_data_after_date']) && $target['disconnected'] && $target['erase_data_after_date'] <= date("c")) {
                    $isReconnected = $userDetailsCollection->aggregate([
                        ['$match' => ['user_id' => $userId]],
                        ['$unwind' => '$shops'],
                        ['$match' => ['user_id' => $userId, 'shops._id' => $shop['_id'], 'shops.sources.shop_id' => $target['shop_id'], 'shops.sources.app_code' => $target['app_code'], 'shops.sources.disconnected' => ['$exists' => false]]],
                    ])->toArray();
                    if (empty($isReconnected)) {
                        $result = $userDetailsCollection->updateOne(
                            ['user_id' => $userId],
                            ['$pull' => ['shops.$[shop].sources' => ['shop_id' => $shop['_id'], 'app_code' => $target['uninstall_app_code'], 'disconnected' => true]]],
                            [
                                'arrayFilters' => [
                                    ['shop._id' => $target['shop_id']],
                                ],
                            ]
                        );
                        if ($result->getModifiedCount() > 0) {
                            $userDetailsCollection->updateOne(
                                ['user_id' => $userId],
                                ['$pull' => ['shops.$[shop].targets' => ['shop_id' => $target['shop_id'], 'app_code' => $target['app_code'], 'uninstall_app_code' => $target['uninstall_app_code'], 'disconnected' => true]]],
                                [
                                    'arrayFilters' => [
                                        ['shop._id' => $shop['_id']],
                                    ],
                                ]
                            );
                            $appTag = $target['app_tag'];
                            $this->deleteDocumentsOnBasisOfAppTagSourceIdTargetId($shop['_id'], $target['shop_id'], $appTag);
                            $this->deleteDocumentsOnBasisOfShopIdAppTag($shop['_id'], $appTag);
                        }
                    } else {
                        $this->log($userId, $shop['_id'], 'result', 'This target is reconnected');
                    }
                }
            }

            if (empty($sources) && empty($targets)) {
                $this->deleteDocumentsOnBasisOfAppTagSourceIdTargetId($shop['_id']);
            }

            return true;
        } catch (\Exception $e) {
            $this->log($userId, $shop['_id'], 'result', $e->getMessage());
            return false;
        }
    }

    public function deleteDocumentsOnBasisOfAppCode($shop, $appCode)
    {
        try {
            $userId = $this->di->getUser()->id;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $marketplace = $shop['marketplace'];
            $shopId = $shop["_id"];

            // 1. Making call to remote to delete app and store its credentials
            if ($mongo->getCollectionForTable('user_details')->count(['shops.remote_shop_id' => $shop['remote_shop_id']]) == 1) {
                $appDeleteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init($marketplace, false, $appCode)
                    ->call('app/uninstall', [], ['shop_id' => $shop['remote_shop_id'], "app_code" => $appCode], 'DELETE');
                if (isset($appDeleteResponse) && !$appDeleteResponse['success']) {
                    $this->log($userId, $shopId, 'result', json_encode($appDeleteResponse));
                }
            }

            //3. Remove data from product_container
            $productCollection = $mongo->getCollectionForTable('product_container');
            $response = $productCollection->updateMany(
                ['user_id' => $userId, 'shop_id' => $shopId],
                ['$pull' => ['app_codes' => $appCode]]
            );
            $productCollection->deleteMany(
                ['user_id' => $userId, 'shop_id' => $shopId, "app_codes" => ['$size' => 0]]
            );

            //4. Delete data from user details
            $userDetailCollection = $mongo->getCollectionForTable('user_details');
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $result = $userDetailCollection->findOne(['user_id' => $userId], $options);

            $response = $userDetailCollection->updateOne(
                ["user_id" => $userId],
                [
                    '$pull' => [
                        'shops.$[shop].apps' => ['code' => $appCode],
                    ],
                ],
                [
                    'arrayFilters' => [
                        [
                            'shop._id' => $shopId,
                        ],
                    ],
                ]
            );
            return true;
        } catch (\Exception $e) {
            $this->log($userId, $shopId, 'result', $e->getMessage());
            return false;
        }
    }

    public function prepareDataToSendMarketplacesForShopEraser($shop, $appCode = false)
    {
        try {
            $userId = $this->di->getUser()->id;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable("user_details");
            $sources = $shop['sources'] ?? [];
            $targets = $shop['targets'] ?? [];
            $disconnectedSources = [];
            $disconnectedTargets = [];
            if (isset($appCode) && $appCode != false) {
                foreach ($sources as $source) {
                    if (isset($source['disconnected']) && $source['disconnected']) {
                        $result = $userDetailsCollection->aggregate([
                            ['$match' => ['user_id' => $userId]],
                            ['$unwind' => '$shops'],
                            ['$match' => ['shops._id' => $source['shop_id'], 'shops.targets.shop_id' => $shop['_id'], 'shops.targets.app_code' => $appCode, 'shopd.targets.disconnected' => true]],
                        ])->toArray();
                        if (!empty($result)) {
                            array_push($disconnectedSources, $source);
                        }
                    }
                }

                foreach ($targets as $target) {
                    if (isset($target['disconnected']) && $target['disconnected']) {
                        $result = $userDetailsCollection->aggregate([
                            ['$match' => ['user_id' => $userId]],
                            ['$unwind' => '$shops'],
                            ['$match' => ['shops._id' => $target['shop_id'], 'shops.sources.shop_id' => $shop['_id'], 'shops.sources.app_code' => $appCode, 'shops.sources.disconnected' => true]],
                        ])->toArray();
                        if (!empty($result)) {
                            array_push($disconnectedTargets, $target);
                        }
                    }
                }
            } else {
                foreach ($sources as $source) {
                    if (isset($source['disconnected'], $source['uninstall_date'], $source['uninstall_app_code'], $source['erase_data_after_date']) && $source['erase_data_after_date'] <= date('c') && $source['disconnected']) {
                        array_push($disconnectedSources, $source);
                    }
                }

                foreach ($targets as $target) {
                    if (isset($target['disconnected'], $target['uninstall_date'], $target['uninstall_app_code'], $target['erase_data_after_date']) && $target['erase_data_after_date'] <= date('c') && $target['disconnected']) {
                        array_push($disconnectedTargets, $target);
                    }
                }
            }

            $shop['sources'] ??= $disconnectedSources;
            $shop['targets'] ??= $disconnectedTargets;
            return ['success' => true, 'shop' => $shop];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteDocumentsOnBasisOfAppTagSourceIdTargetId($sourceShopId, $targetShopId = null, $appTag = null)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userId = $this->di->getUser()->id;
            if (isset($sourceShopId)) {
                $object = $this->di->getObjectManager()->get("\App\Connector\Models\Product\Marketplace");
                $result = $object->marketplaceDelete(["deleteArray" => [['target_shop_id' => $targetShopId, 'source_shop_id' => $sourceShopId]]]);
            }

            if (isset($sourceShopId, $targetShopId)) {
                $configCollection = $mongo->getCollectionForTable("config");
                $configCollectionResult = $configCollection->deleteMany(['source_shop_id' => $sourceShopId, 'target_shop_id' => $targetShopId]);
                $profileCollection = $mongo->getCollectionForTable("profile");
                $profiles = $profileCollection->find([
                    "type" => "profile",
                    "shop_ids" => [
                        '$elemMatch' => ["source" => $sourceShopId, "target" => $targetShopId],
                    ],
                ])->toArray();
                if (!empty($profiles)) {
                    foreach ($profiles as $profile) {
                        $profileId = $profile["_id"];
                        $profileObject = $this->di->getObjectManager()->get("\App\Connector\Components\Profile\ProfileHelper");
                        $result = $profileObject->deleteProfile(["id" => (string) $profileId]);
                    }
                }

                $productContainerCollection = $mongo->getCollectionForTable("product_container");
                $result = $productContainerCollection->deleteMany(["user_id" => $userId, "shop_id" => $targetShopId, "source_shop_id" => $sourceShopId]);
            }

            return true;
        } catch (\Exception $e) {
            $this->log($userId, $sourceShopId, 'result', $e->getMessage());
            return false;
        }
    }

    public function deleteDocumentsOnBasisOfShopIdAppTag($shopId, $appTag)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userId = $this->di->getUser()->id;
        $notificationCollection = $mongo->getCollectionForTable('notifications');
        $notificationCollection->deleteMany(['user_id' => $userId, 'shop_id' => $shopId, 'appTag' => $appTag]);
        $queuedTaskCollection = $mongo->getCollectionForTable('queued_tasks');
        $queuedTaskCollection->deleteMany(['user_id' => $userId, 'shop_id' => $shopId, 'appTag' => $appTag]);
        return true;
    }

    public function deleteAppIfNoSourcesTargetsConnnected($shopId)
    {
        try {
            $userId = $this->di->getUser()->id;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $result = $userDetailsCollection->aggregate([
                ['$match' => ['user_id' => $userId, 'shops._id' => $shopId]],
                ['$unwind' => '$shops'],
                ['$match' => ['user_id' => $userId, 'shops._id' => $shopId]],
            ])->toArray();
            $shop = $result[0]['shops'];
            if ((empty($shop['sources']) || count($shop['sources']) == 0) && (empty($shop['targets']) || count($shop['targets']) == 0)) {
                foreach ($shop['apps'] as $app) {
                    if ((isset($app['app_status']) && $app['app_status'] == "uninstall") && (!isset($app['uninstall_date'], $app['erase_data_after_date']))) {
                        $this->deleteDocumentsOnBasisOfAppCode($shop, $app['code']);
                    }
                }

                return ['success' => true, 'message' => "Shop contains no active apps, sources and targets."];
            }

            return ['success' => true, 'message' => "Shop contains active apps, sources and targets."];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function uninstallUserDetails($shopDetail, $appCode)
    {

        try {
            $userId = $this->di->getUser()->id;
            $shopId = $shopDetail['_id'];
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            if ($userId == $this->di->getUser()->user_id) {
                $userEmail = $this->di->getUser()->email;
                $userName = $this->di->getUser()->username;
                $installation_date = $this->di->getUser()->created_at;
            } else {
                $userDetails = $this->di->getObjectManager()->create('App\Core\Models\User\Details')->getConfig($userId);
                $userName = $userDetails['username'];
                $userEmail = $userDetails['email'];
                $installation_date = $userDetails['created_at'];
            }

            // fetching source and target from the uninstalled app
            $keysToCheck = ['sources', 'targets'];
            $connectedApps = array_intersect_key($shopDetail, array_flip($keysToCheck));
            $moduleHome = ucfirst($shopDetail['marketplace']);
            $moduleClass = '\App\\' . $moduleHome . '\Components\ShopDelete';

            if (!class_exists($moduleClass)) {
                $moduleHome = ucfirst($shopDetail['marketplace']) . "home";
                $moduleClass = '\App\\' . $moduleHome . '\Components\ShopDelete';
            }

            if (class_exists($moduleClass)) {
                try {
                    $moduleObject = $this->di->getObjectManager()->get($moduleClass);
                } catch (\Exception $e) {
                    return ['success' => false, 'message' => "Error creating ShopDelete object"];
                }
            } else {
                return ['success' => false, 'message' => "ShopDelete Class Not Found"];
            }

            if (class_exists('\App\\' . $moduleHome . '\Components\ShopDelete') && method_exists('\App\\' . $moduleHome . '\Components\ShopDelete', 'uninstallUserDetails')) {
                $result = $moduleObject->uninstallUserDetails(['marketplace' => $shopDetail['marketplace'], 'shop_id' => $shopDetail['_id'], 'app_code' => $appCode]);
            }

            $appData = ['code' => $appCode, 'uninstall_date' => date('c')];
            if (!empty($result)) {
                $appData = array_merge($appData, $result);
            }

            $uninstallUserDetailsCollection = $mongo->getCollectionForTable('uninstall_user_details');
            $result = $uninstallUserDetailsCollection->findOne(['username' => $userName, 'email' => $userEmail], $options);
            if (!empty($result)) {
                $isShopPresent = false;
                $shopData = [];
                if (!empty($result['shops'])) {
                    foreach ($result['shops'] as $shop) {
                        if ($shop['_id'] == $shopId) {
                            $isShopPresent = true;
                            $shopData = $shop;
                            break;
                        }
                    }
                }

                if ($isShopPresent) {
                    $isAppPresent = false;
                    foreach ($shopData['apps'] as $app) {
                        if ($app['code'] == $appCode) {
                            $isAppPresent = true;
                            break;
                        }
                    }

                    if (!$isAppPresent) {
                        $uninstallUserDetailsCollection->updateOne(
                            ['username' => $userName, 'email' => $userEmail],
                            ['$push' => ['shops.$[shop].apps' => $appData]],
                            [
                                'arrayFilters' => [
                                    ['shop._id' => $shopId],
                                ],
                            ]
                        );
                        return ['success' => true, 'message' => 'Backup created successfully.'];
                    }

                    return ['success' => false, 'message' => 'Backup already exist'];
                }

                $currentShop = [
                    '_id' => $shopId,
                    'marketplace' => $shopDetail['marketplace'],
                    'last_login_at' => $shopDetail['last_login_at'],
                    'created_at' => $shopDetail['created_at'],
                    'apps' => [
                        $appData,
                    ],
                ] + $connectedApps;
                $marketplaceFound = $uninstallUserDetailsCollection->count(
                    [
                        'username' => $userName,
                        'email' => $userEmail,
                        'shops.marketplace' => $shopDetail['marketplace'],
                    ]
                );
                if ($marketplaceFound) {
                    $uninstallUserDetailsCollection->updateOne(
                        [
                            'username' => $userName,
                            'email' => $userEmail,
                            'shops.marketplace' => $shopDetail['marketplace'],
                        ],
                        [
                            '$set' => [
                                'user_id' => $userId,
                                'shops.$' => $currentShop,
                            ],
                        ]
                    );
                } else {
                    $uninstallUserDetailsCollection->updateOne(
                        [
                            'username' => $userName,
                            'email' => $userEmail,
                        ],
                        [
                            '$set' => [
                                'user_id' => $userId,
                            ],
                            '$addToSet' => [
                                'shops' => $currentShop,
                            ],
                        ]
                    );
                }

                return ['success' => true, 'message' => 'Backup created successfully.'];
            }

            $uninstallUserDetailsCollection->insertOne([
                'user_id' => $userId,
                'username' => $userName,
                'email' => $userEmail,
                'installation_date' => $installation_date,
                'shops' => [
                    [
                        '_id' => $shopId,
                        'marketplace' => $shopDetail['marketplace'],
                        'last_login_at' => $shopDetail['last_login_at'],
                        'created_at' => $shopDetail['created_at'],
                        'apps' => [
                            $appData,
                        ],
                    ] + $connectedApps,
                ],
            ]);
            return ['success' => true, 'message' => 'Backup created successfully.'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function setTemporarlyUninstallCompleted($shopId, $appCode)
    {
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $user = $mongo->getCollectionForTable("user_details");
        $result = $user->updateOne(
            ['user_id' => $userId],
            ['$set' => ['shops.$[shop].apps.$[app].temporarly_uninstall' => true]],
            [
                'arrayFilters' => [
                    ['shop._id' => $shopId],
                    ['app.code' => $appCode],
                ],
            ]
        );
        if ($result->getModifiedCount()) {
            return ['success' => true, 'message' => 'temporarly_uninstall key added successfully.'];
        }

        return ['success' => false, 'message' => 'Trouble in adding temporarly_uninstall key OR key already exists.'];
    }

    public function resetAccount($data)
    {
        try {
            $userId = $this->di->getUser()->id;
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array'], 'projection' => ['shops.$' => 1, '_id' => 0]];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $userId = $this->di->getUser()->id;
            $eventsManager = $this->di->getEventsManager();

            if (isset($data['reconnect']['target']) && $data['reconnect']['target']) {
                $appCode = $this->di->getAppCode()->get()[$data['target']['marketplace']] ?? "";
                $userDetails = $userDetailsCollection->findOne(['user_id' => $userId, 'shops.apps.code' => $appCode, 'shops._id' => $data['target']['shopId']], $options);
                $shop = $userDetails['shops'][0];
            } elseif (isset($data['reconnect']['source']) && $data['reconnect']['source']) {
                $appCode = $this->di->getAppCode()->get()[$data['source']['marketplace']] ?? "";
                $userDetails = $userDetailsCollection->findOne(['user_id' => $userId, 'shops.apps.code' => $appCode, 'shops._id' => $data['source']['shopId']], $options);
                $shop = $userDetails['shops'][0];
            }

            $response = $this->prepareDataToSendMarketplacesForShopEraser($shop, $appCode);

            $targetAppCode = $this->di->getAppCode()->get()[$data['target']['marketplace']] ?? "";
            $sourceAppCode = $this->di->getAppCode()->get()[$data['source']['marketplace']] ?? "";

            if ($response['success']) {

                $sources = $response['shop']['sources'] ?? [];
                $targets = $response['shop']['targets'] ?? [];

                if (!empty($sources)) {
                    foreach ($sources as $source) {
                        $targetUpdateResponse = $userDetailsCollection->updateOne(
                            ['user_id' => $userId],
                            [
                                '$unset' => [
                                    'shops.$[shop].targets.$[target].disconnected' => true,
                                ],
                            ],
                            [
                                'arrayFilters' => [
                                    ['shop._id' => $source['shop_id']],
                                    ['target.shop_id' => $response['shop']['_id'], 'target.app_code' => $targetAppCode],
                                ],
                            ]
                        );
                        $sourceUpdateResponse = $userDetailsCollection->updateOne(
                            ['user_id' => $userId],
                            [
                                '$unset' => [
                                    'shops.$[shop].sources.$[source].disconnected' => true,
                                    'shops.$[shop].sources.$[source].erase_data_after_date' => true,
                                    'shops.$[shop].sources.$[source].uninstall_date' => true,
                                    'shops.$[shop].sources.$[source].uninstall_app_code' => true,
                                    'shops.$[shop].sources.$[source].app_tag' => true,
                                    'shops.$[shop].deletion_in_progress' => true,
                                ],
                            ],
                            [
                                'arrayFilters' => [
                                    ['shop._id' => $response['shop']['_id']],
                                    ['source.shop_id' => $source['shop_id'], 'source.app_code' => $source['app_code']],
                                ],
                            ]
                        );

                        $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->setStatusInUser("active", $userId, $response['shop']['_id'], $targetAppCode);
                    }
                }

                if (!empty($targets)) {
                    foreach ($targets as $target) {
                        $targetUpdateResponse = $userDetailsCollection->updateOne(
                            ['user_id' => $userId],
                            [
                                '$unset' => [
                                    'shops.$[shop].targets.$[target].disconnected' => true,
                                    'shops.$[shop].targets.$[target].erase_data_after_date' => true,
                                    'shops.$[shop].targets.$[target].uninstall_date' => true,
                                    'shops.$[shop].targets.$[target].uninstall_app_code' => true,
                                    'shops.$[shop].targets.$[target].app_tag' => true,
                                    'shops.$[shop].deletion_in_progress' => true,
                                ],
                            ],
                            [
                                'arrayFilters' => [
                                    ['shop._id' => $response['shop']['_id']],
                                    ['target.shop_id' => $target['shop_id'], 'target.app_code' => $target['app_code']],
                                ],
                            ]
                        );
                        $sourceUpdateResponse = $userDetailsCollection->updateOne(
                            ['user_id' => $userId],
                            [
                                '$unset' => [
                                    'shops.$[shop].sources.$[source].disconnected' => true,
                                ],
                            ],
                            [
                                'arrayFilters' => [
                                    ['shop._id' => $target['shop_id']],
                                    ['source.shop_id' => $response['shop']['_id'], 'source.app_code' => $sourceAppCode],
                                ],
                            ]
                        );
                    }

                    $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->setStatusInUser("active", $userId, $response['shop']['_id'], $sourceAppCode);
                }

                if ($targetUpdateResponse->getModifiedCount() && $sourceUpdateResponse->getModifiedCount()) {
                    // Event to register webhooks if more than one apps is connected to the shops.
                    $eventsManager->fire('application:afterAccountReconnect', $this, []);
                    return ['success' => true, 'message' => 'Successfully removed uninstall keys'];
                }
            }

            return ['success' => false, 'message' => 'Trouble in removing uninstall keys'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function beforeLastTargetDisconnect($userId, $sourceShopId, $targetShopId): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollectionForTable('user_details');
        $targetCount = $userDetailsCollection->aggregate([
            ['$match' => ['user_id' => $userId]],
            ['$unwind' => '$shops'],
            ['$match' => ['user_id' => $userId, 'shops._id' => $sourceShopId]],
            ['$project' => ['shops.targets' => 1]],
        ])->toArray();
        if (!empty($targetCount[0]['shops']['targets']) && count($targetCount[0]['shops']['targets']) == 1) {
            $eventsManager = $this->di->getEventsManager();
            $eventsManager->fire('application:beforeLastTargetDisconnect', $this, [
                'sourceShopId' => $sourceShopId,
                'targetShopId' => $targetShopId,
                'userId' => $userId
            ]);
        }
    }

    private function log($userId, $shopId, $logFileName, $message): void
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);

        $method = isset($backtrace[1]['function']) ? $backtrace[1]['function'] : "unknown";
        $class = isset($backtrace[1]['class']) ? $backtrace[1]['class'] : "unknown";
        if (empty($shopId)) {
            $logPath = "uninstall" . DS . $userId . DS . date("Y-m-d") . DS . "{$logFileName}.log";
        } else {
            $logPath = "uninstall" . DS . $userId . "_" . $shopId . DS . date("Y-m-d") . DS . "{$logFileName}.log";
        }

        $this->di->getLog()->logContent(json_encode([
            'method' => "{$class}::{$method}",
            'message' => $message,
        ]), \Phalcon\Logger\Logger::ERROR, $logPath);
    }
}
