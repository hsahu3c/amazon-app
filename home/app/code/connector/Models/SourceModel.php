<?php

/**
 * CedCommerce
 *
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shoifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Connector\Models;

use App\Core\Models\SourceModel as BaseSourceModel;

class SourceModel extends BaseSourceModel implements SourceModelInterface
{
    const USER_DETAILS_CONTAINER = 'user_details';

    const PRODUCT_CONTAINER = 'product_container';

    const QUEUED_TASKS = 'queued_tasks';

    const REPORT_CONTAINER = 'report_container';

    const COUNT = 5;

    const singleHome = "singleHome";

    // Onboarding Code Starts

    public function checkSourceIsConnected($shop, $state): void
    {
        $targetShopId = $shop['_id'];
        $sourceShopId = $state['source_shop_id'];
        $sourceData = [
            'shop_id' => $sourceShopId,
            'source' => $state['source'],
        ];
        $targetData = [
            'shop_id' => $targetShopId,
            'target' => $shop['marketplace'],
        ];

        $this->addSourcesAndTargets($sourceData, $targetShopId, $state['user_id']);
        $this->addSourcesAndTargets($targetData, $sourceShopId, $state['user_id']);
    }

    public function setupHomeUser($data)
    {
        $data['data']['data']['app_code'] = $data['rawResponse']['app_code'];
        $remoteShopId = (string) $data['data']['data']['shop_id'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $eventsManager = $this->di->getEventsManager();

        if (!empty($data['rawResponse']['state'])) {
            $state = json_decode($data['rawResponse']['state'], true);
            if (isset($state['app_tag'])) {
                $this->di->getAppCode()->setAppTag($state['app_tag']);
            }

            if (isset($state['app_tag']) && isset($this->di->getConfig()->app_tags[$state['app_tag']]['app_code'], $state['app_tag']) && !empty($this->di->getConfig()->app_tags[$state['app_tag']]['app_code'])) {
                $appCode = $this->di->getConfig()->app_tags[$state['app_tag']]['app_code'];
                $appCodeArray = $appCode->toArray();
                $this->di->getAppCode()->set($appCodeArray);
            } else if (isset($state['app_code'])) {
                $this->di->getAppCode()->set($state['app_code']);
            }
        }

        $userRole = isset($data['rawResponse']['user_role']) ? $data['rawResponse']['user_role'] : 'customer';

        $userDetails = $this->di->getObjectManager()->get('App\Core\Models\User\Details');
        $users = $userDetails->getAllUserByRemoteShopId($remoteShopId, ['user_id' => 1, 'shops' => 1], $data['data']['data']['app_code']);
        $user = false;
        $shop = false;

        if (!empty($users)) {
            foreach ($users as $currentUser) {
                if (isset($currentUser['shops'])) {
                    foreach ($currentUser['shops'] as $sh) {
                        if (isset($state['user_id'])) {
                            if ($state['user_id'] == $currentUser['user_id'] && $sh['remote_shop_id'] == $remoteShopId) {
                                $user = $currentUser;
                                $shop = $sh;
                                break;
                            }
                        } else {
                            if ($sh['remote_shop_id'] == $remoteShopId) {
                                $user = $currentUser;
                                $shop = $sh;
                                break;
                            }
                        }
                    }
                }
            }
        }

        if ($data['data']['data']['marketplace'] == 'amazon' && $this->isShopifyAppUninstalled($user)) {
            return [
                'success' => false,
                'code' => "shopify_app_uninstalled",
                'message' => "Shopify app is uninstalled, please reinstall the app.",
            ];
        }

        if ($user && $shop) {
            $getUser = \App\Core\Models\User::findFirst([['_id' => $user['user_id']]]);
            $getUser->id = (string) $getUser->_id;
            $this->di->setUser($getUser);
            if (isset($state['user_id'])) {
                $this->checkSourceIsConnected($shop, $state);
            }

            if (isset($state['is_update']) && $state['is_update']) {
                $this->updateShop($shop, $state);
            }

            $this->updateLastLoginInShop($shop, $data, true, false);
            $eventsManager->fire('application:userShopPresent', $this, ['shopData' => $shop]);

            if (isset($shop['apps'])) {
                foreach ($shop['apps'] as $app) {
                    if ($app['code'] == $data['data']['data']['app_code']) {
                        $shop = $userDetails->getShop($shop['_id'], $this->di->getUser()->id, true);
                        if (isset($app['app_status']) && $app['app_status'] != 'active' || (isset($data['data']['app_reInstall']) && $data['data']['app_reInstall'])) {
                            //Unregister old webhooks if token is updated
                            $this->di->getObjectManager()
                                ->get("\App\Connector\Components\Hook")
                                ->handleWebhooksAfterAppUninstall($shop, $app['code']);

                            $shop = $userDetails->getShop(
                                $shop['_id'],
                                $this->di->getUser()->id,
                                true
                            );
                            $eventsManager->fire('application:appReInstall', $this, ['source_shop' => $shop, 're_install_app' => $app['code']]);
                        }

                        $eventsManager->fire('application:userShopAppPresent', $this, ['shopData' => $shop]);
                        break;
                    }
                }
            }

            if(isset($shop['reauth_required']) && $shop['reauth_required']) {
                $this->updateReauthKeys($user['user_id'], $shop);
            }

            return [
                'success' => true,
                "user_was_present" => 1,
                "shop_was_present" => 1,
                "app_was_present" => 1,
                'shop' => $shop,
            ];
        }
        $user = false;
        $shop = false;
        $marketplace = $data['data']['data']['marketplace'];
        $appCode = $data['data']['data']['app_code'];
        $users = $userDetails->getAllUserByRemoteShopId((string) $data['data']['data']['shop_id'], ['user_id' => 1, 'shops' => 1]);
        if (!empty($users)) {
            foreach ($users as $currentUser) {
                if (isset($currentUser['shops'])) {
                    foreach ($currentUser['shops'] as $sh) {
                        if (isset($state['user_id'])) {
                            if ($state['user_id'] == $currentUser['user_id'] && $sh['remote_shop_id'] == $remoteShopId) {
                                $user = $currentUser;
                                $shop = $sh;
                                break;
                            }
                        } else {
                            if ($sh['remote_shop_id'] == $remoteShopId) {
                                $user = $currentUser;
                                $shop = $sh;
                                break;
                            }
                        }
                    }
                }
            }
        }
        if ($user) {
            $getUser = \App\Core\Models\User::findFirst([['_id' => $user['user_id']]]);
            $getUser->id = (string) $getUser->_id;
            $this->di->setUser($getUser);
            if (isset($state['user_id'])) {
                $this->checkSourceIsConnected($shop, $state);
            }

            $app = [
                "code" => $appCode,
                'app_status' => "active",
            ];

            $update = $this->di
                ->getObjectManager()
                ->get('App\Connector\Models\User\Shop')
                ->addApp($remoteShopId, $app, $user['user_id']);
            if ($update) {
                $this->updateLastLoginInShop($shop, $data, false, true);
            }

            if (isset($state['is_update']) && $state['is_update']) {
                $this->updateShop($shop, $state);
            }

            $this->updateLastLoginInShop($shop, $data, true, false);
            $eventsManager->fire('application:userShopPresent', $this, ['shopData' => $shop]);

            return [
                'success' => true,
                "user_was_present" => 1,
                "shop_was_present" => 1,
                "app_was_present" => 0,
                'shop' => $shop,
            ];
        }
        $shopResponse = $this->di->getObjectManager()->get('\App\Connector\Components\ApiClient')->init($marketplace, true, $appCode)->call('/shop', [], [
            'shop_id' => $data['data']['data']['shop_id'],
            'app_code' => $appCode,
        ]);
        if (!$shopResponse['success'] || is_null($shopResponse) || empty($shopResponse)) {
            $this->di->getLog()->logContent(json_encode([
                'method' => __METHOD__,
                'line' => __LINE__,
                'data' => $shopResponse,
            ]), 'info', 'Authorization' . DS . date('d-m-Y') . DS . 'set_up_home_user.log');
            return [
                'success' => false,
                'code' => "marketplace_shop_call",
                'message' => isset($shopResponse['errors']) ? $shopResponse['errors'] : 'Error fetching data from ' . $marketplace . ', Please try again later or contact our next available support member.',
            ];
        }
        if (isset($state['user_id'])) {
            $filter = ['user_id' => $state['user_id']];
            $user = \App\Core\Models\User::findFirst([$filter]);
            if (empty($user)) {
                $shopData['username'] = $shopResponse['data']['username'] ?? $shopResponse['data']['name'];
                $shopData['name'] = $shopResponse['data']['name'] ?? $shopResponse['data']['username'];
                $shopData['email'] = $shopResponse['data']['email'];
                //set unique mail
                $apiconnectorConfig = $this->di->getConfig()->path('apiconnector.' . $marketplace . '.' . $appCode);
                if (isset($apiconnectorConfig['setuniquemail'], $shopResponse['data']['auto_generated_email'])) {
                    $shopData['email'] = $shopResponse['data']['auto_generated_email'];
                }

                $userModel = $this->di->getObjectManager()->create('\App\Core\Models\User');
                $shopData['password'] = isset($data['shop_data']['password']) ? $data['shop_data']['password'] : $userModel->generatePassword();

                $createUserResult = $userModel->createUser($shopData, $userRole, true);
                if (isset($createUserResult['success']) && !$createUserResult['success']) {
                    return $createUserResult;
                }

                $userModel->id = (string) $userModel->_id;
                $this->di->setUser($userModel);
                // sends reset link mail if marketPlace config has true value in key sendResetPassword
                $marketplaceConnector = $this->di->getConfig()->connectors->$marketplace;

                if (is_null($marketplaceConnector->get('sendResetPassword')) || $marketplaceConnector->get('sendResetPassword')) {
                    $userModel->sendResetMail($shopData);
                }

                $responseData = [
                    'success' => true,
                    "user_was_present" => 0,
                    "shop_was_present" => 0,
                    "app_was_present" => 0,
                ];
            } else {
                $user = \App\Core\Models\User::findFirst([['_id' => $user->_id]]);
                $user->id = (string) $user->_id;
                $this->di->setUser($user);
                $responseData = [
                    'success' => true,
                    "user_was_present" => 1,
                    "shop_was_present" => 0,
                    "app_was_present" => 0,
                ];
            }
        } else {
            $userModel = $this->di->getObjectManager()->create('\App\Core\Models\User');
            $shopData['username'] = $shopResponse['data']['username'] ?? $shopResponse['data']['name'];
            $shopData['name'] = $shopResponse['data']['name'] ?? $shopResponse['data']['username'];
            $shopData['email'] = $shopResponse['data']['email'];
            //set unique mail
            $apiconnectorConfig = $this->di->getConfig()->path('apiconnector.' . $marketplace . '.' . $appCode);
            if (isset($apiconnectorConfig['setuniquemail'], $shopResponse['data']['auto_generated_email'])) {
                $shopData['email'] = $shopResponse['data']['auto_generated_email'];
            }

            $shopData['password'] = isset($data['shop_data']['password']) ? $data['shop_data']['password'] : $userModel->generatePassword();
            $filter = ['email' => $shopData['email']];
            $user = \App\Core\Models\User::findFirst([$filter]);

            if (!empty($user)) {
                $shopData['source_email'] = $shopData['email'];
                $shopData['email'] = "duplicate_" . $mongo->getCounter("user_" . $shopData['email']) . "_" . $shopData['email'];
                $shopData['email_created_by'] = "cedcommerce";
            }

            $createUserResult = $userModel->createUser($shopData, $userRole, true);
            if (isset($createUserResult['success']) && !$createUserResult['success']) {
                $this->di->getLog()->logContent(json_encode([
                    'method' => __METHOD__,
                    'line' => __LINE__,
                    'data' => $shopResponse,
                ]), 'info', 'Authorization' . DS . date('d-m-Y') . DS . 'set_up_home_user.log');
                return $createUserResult;
            }

            $userModel->id = (string) $userModel->_id;
            $this->di->setUser($userModel);
            $marketplaceConnector = $this->di->getConfig()->connectors->$marketplace;

            if (is_null($marketplaceConnector->get('sendResetPassword')) || $marketplaceConnector->get('sendResetPassword')) {
                $userModel->sendResetMail($shopData);
            }

            $responseData = [
                'success' => true,
                "user_was_present" => 0,
                "shop_was_present" => 0,
                "app_was_present" => 0,
            ];
        }
        $shopData = $shopResponse['data'];
        $shopData['apps'] = [["code" => $appCode, 'app_status' => "active"]];
        $shopData['remote_shop_id'] = $remoteShopId;
        $shopData['marketplace'] = $marketplace;
        $shopData['last_login_at'] = date("Y-m-d H:i:s");
        $sourceModel = $this->di->getObjectManager()->get($this->di->getConfig()->connectors
            ->get($marketplace)->get('source_model'));
        if (method_exists($sourceModel, 'prepareShopData')) {
            $sourceModel->prepareShopData($data, $shopData);
        }
        if (method_exists($sourceModel, 'addWarehouseToShop')) {
            $sourceModel->addWarehouseToShop($data, $shopData);
        }
        if (isset($state) && isset($state['old_shop_id'])) {
            $shopData['old_remote_shop_id'] = $state['old_shop_id'];
        }

        $responseData['shop'] = $shopData;
        if ($this->di->has('isDev') && !$this->di->get('isDev')) {
            $userCollection = $mongo->getCollectionForTable('user_details');

            $userCollectionResult = $userCollection->aggregate([
                ['$match' => ['shops.remote_shop_id' => $shopData['remote_shop_id'], 'shops.deletion_in_progress' => ['$exists' => true]]],
            ])->toArray();


            if (count($userCollectionResult) != 0) {
                $this->di->getLog()->logContent(json_encode([
                    'method' => __METHOD__,
                    'line' => __LINE__,
                    'response' => $userCollectionResult,
                    'message' => "App Removal is in progress so please retry after sometime.",
                ]), 'info', 'Authorization' . DS . date('d-m-Y') . DS . 'set_up_home_user.log');
                return ['success' => false, 'code' => "removal_in_progress", 'message' => "App Removal is in progress so please retry after sometime.", "remote_shop_id" => "{$remoteShopId}"];
            }
            $userCollectionResult = $userCollection->aggregate([
                ['$match' => ['shops.remote_shop_id' => $shopData['remote_shop_id']]],
            ])->toArray();

            $configKey = 'allow_same_account_connection_remote_shop_ids';
            $allowedUsers = $this->getDi()->getConfig()->get($configKey) ? $this->getDi()->getConfig()->get($configKey)->toArray() : [];

            // if (count($userCollectionResult) != 0) {
            if (count($userCollectionResult) != 0 && !in_array($remoteShopId, $allowedUsers)) {

                $userCollectionResult = json_decode(json_encode($userCollectionResult), true);
                // $filteredData = array_filter($userCollectionResult[0]['shops'], function ($shop) {
                //     return isset($shop['marketplace']) && $shop['marketplace'] === 'amazon';
                // });
                // $seller_id = array_values($filteredData)[0]['warehouses'][0]['seller_id'];
                $filteredData = array_filter($userCollectionResult[0]['shops'], function ($shop) use ($shopData) {
                    return isset($shop['remote_shop_id']) && $shop['remote_shop_id'] === $shopData['remote_shop_id'];
                });
                $seller_id = array_values($filteredData)[0]['warehouses'][0]['seller_id'] ?? false;
                $this->di->getLog()->logContent(json_encode([
                    'method' => __METHOD__,
                    'line' => __LINE__,
                    'response' => $userCollectionResult,
                    'message' => "You can't connect same account with multiple users.",
                ]), 'info', 'Authorization' . DS . date('d-m-Y') . DS . 'set_up_home_user.log');
                $returnResponse = ['success' => false, 'code' => "same_target_account", "remote_shop_id" => "{$remoteShopId}"];
                if ($seller_id) {
                    $returnResponse['message'] = "You can't connect same account with multiple users for seller id : " . $seller_id;
                } else {
                    $returnResponse['message'] = "You can't connect same account with multiple users";
                }
                return $returnResponse;
            }
        }
        $shopRes = $userDetails->addShop($shopData, false, ["remote_shop_id"]);
        if (isset($shopRes['success']) && !$shopRes['success']) {
            return $shopRes;
        }

        if (isset($state['source'], $state['source_shop_id'], $state['user_id']) || isset($state['target'], $state['target_shop_id'], $state['user_id'])) {
            $sourceShopId = isset($state['source']) ? $state['source_shop_id'] : $shopRes['data']['shop_id'];
            $targetShopId = isset($state['source_shop_id']) ? $shopRes['data']['shop_id'] : $state['target_shop_id'];
            $sourceData = [
                'shop_id' => $sourceShopId,
                'source' => isset($state['source']) ? $state['source'] : $marketplace,
            ];
            $targetData = [
                'shop_id' => $targetShopId,
                'target' => isset($state['source']) ? $marketplace : $state['target'],
            ];
            $this->addSourcesAndTargets($sourceData, $targetShopId, $state['user_id']);
            $this->addSourcesAndTargets($targetData, $sourceShopId, $state['user_id']);
        } else {
            if (isset($shopRes['code']) && $shopRes['code'] == "added") {
                $userDetails = $this->di->getObjectManager()->get('App\Core\Models\User\Details');
                $sourceShop = $userDetails->getShop($shopRes['data']['shop_id'], $this->di->getUser()->id);
                $eventsManager->fire('application:afterAccountConnection', $this, ['source_shop' => $sourceShop]);
            }
        }
        return $responseData;
    }

    public function isShopifyAppUninstalled($data)
    {
        if (!isset($data['shops']) || !is_array($data['shops'])) {
            return false;
        }

        foreach ($data['shops'] as $shop) {
            if (isset($shop['marketplace']) && $shop['marketplace'] === 'shopify') {
                if (!empty($shop['apps']) && is_array($shop['apps'])) {
                    foreach ($shop['apps'] as $app) {
                        if (
                            isset($app['code'], $app['app_status']) &&
                            $app['code'] === 'amazon_sales_channel' &&
                            $app['app_status'] === 'uninstall'
                        ) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    public function addSourcesAndTargets($data, $shopId, $userId)
    {
        if($data['shop_id'] == $shopId) {
            $logFile = 'connector/addSourcesAndTargets/'.date('Y-m-d').'.log';
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $this->di->getLog()->logContent(json_encode([
                'data' => $data,
                'shopId' => $shopId,
                'userId' => $userId,
                'message' => "Both shopId(s) are same. Skipping the operation.",
                'backtrace' => json_encode($trace),
            ]), 'info', $logFile);
            return true;
        }
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetails = $this->di->getObjectManager()->get('App\Core\Models\User\Details');
        $collection = $mongo->getCollection('user_details');
        $fieldname = isset($data['source']) ? "sources" : "targets";

        $sourceTargets = [
            'shop_id' => $data['shop_id'],
            'code' => $data['source'] ?? $data['target'],
            'app_code' => $this->di->getAppCode()->get()[$data['source'] ?? $data['target']],
        ];

        $out = $collection->aggregate([
            [
                '$match' => [
                    'shops._id' => (string) $shopId,
                    'shops.' . $fieldname . '.shop_id' => (string) $sourceTargets['shop_id'],
                    'shops.' . $fieldname . '.code' => $sourceTargets['code'],
                    'shops.' . $fieldname . '.app_code' => $sourceTargets['app_code'],
                ],
            ],
            ['$unwind' => '$shops'],
            ['$unwind' => '$shops.' . $fieldname],
            [
                '$match' => [
                    'shops._id' => (string) $shopId,
                    'shops.' . $fieldname . '.shop_id' => (string) $sourceTargets['shop_id'],
                    'shops.' . $fieldname . '.code' => $sourceTargets['code'],
                    'shops.' . $fieldname . '.app_code' => $sourceTargets['app_code'],
                ],
            ],
            ['$project' => ['shops._id' => 1, 'shops.' . $fieldname . '.shop_id' => 1, 'shops.' . $fieldname . '.code' => 1, 'shops.' . $fieldname . '.app_code' => 1]],
        ])->toArray();

        $eventsManager = $this->di->getEventsManager();
        if (empty($out)) {
            $update = $collection->updateOne(
                [
                    'shops._id' => (string) $shopId,
                ],
                [
                    '$push' => [
                        'shops.$.' . $fieldname => $sourceTargets,
                    ],
                ]
            );
            $sourceShop = $userDetails->getShop($sourceTargets['shop_id'], $userId);
            $targetShop = $userDetails->getShop($shopId, $userId);
            if ($update->getModifiedCount()) {
                $this->updateLastLoginInShop($targetShop, $data, false, true);
            }

            if ($fieldname == "sources" && $update->getModifiedCount()) {
                $eventsManager->fire('application:afterAccountConnection', $this, ['source_shop' => $sourceShop, 'target_shop' => $targetShop]);
            }
        }
        #TODO: handle case to remove fiels from sources and targets which was added at the time of source / target disconnect
        else {
            $result = $collection->updateOne(
                [
                    'shops._id' => (string) $shopId,
                ],
                [
                    '$unset' => [
                        'shops.$.' . $fieldname . '.$[sourcesTargets].erase_data_after_date' => 1,
                        'shops.$.' . $fieldname . '.$[sourcesTargets].uninstall_date' => 1,
                        'shops.$.' . $fieldname . '.$[sourcesTargets].uninstall_app_code' => 1,
                        'shops.$.' . $fieldname . '.$[sourcesTargets].disconnected' => 1,
                    ],
                ],
                [
                    'arrayFilters' => [
                        [
                            'sourcesTargets.shop_id' => ['$eq' => (string) $sourceTargets['shop_id']],
                        ],
                    ],
                ]
            );
            $sourceShop = $userDetails->getShop($sourceTargets['shop_id'], $userId);
            $targetShop = $userDetails->getShop($shopId, $userId);
            if ($fieldname == "sources" && $result->getModifiedCount()) {
                $eventsManager->fire('application:sourceTargetReconnect', $this, ['source_shop' => $sourceShop, 'target_shop' => $targetShop]);
            }
        }

        return $out;
    }

    public function updateLastLoginInShop($shop, $remoteData = [], $lastLoginAt = false, $updatedAt = false): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('user_details');
        $datetime = date("Y-m-d H:i:s");
        $setData = [];
        if ($lastLoginAt) {
            $setData['shops.$.last_login_at'] = $datetime;
        }

        if ($updatedAt) {
            $setData['shops.$.updated_at'] = $datetime;
        }

        $collection->updateOne(
            [
                'shops._id' => (string) $shop['_id'],
            ],
            [
                '$set' => $setData,
            ]
        );

        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:shopUpdate', $this, ['shop' => $shop, 'remoteData' => $remoteData]);
    }

    public function commenceHomeAuth($remotePostData)
    {
        $response = $this->setupHomeUser($remotePostData);
        $remotePostData['data']['data']['app_code'] = $remotePostData['rawResponse']['app_code'];
        $marketplace = $remotePostData['data']['data']['marketplace'];
        $appCode = $remotePostData['data']['data']['app_code'];
        $result = ["success" => 0];
        if ($response['success']) {
            $result['success'] = 1;
            $result['heading'] = 'Congratulations!!';
            $result['message'] = 'Your ' . $marketplace . ' store has been succesfully set up.';

            try {
                $shop = $response['shop'];
                foreach ($shop['apps'] as $app) {
                    if ($app['code'] == $appCode) {
                        if (empty($app['webhooks'])) {
                            $registerOnboardingWebhooks = true;
                        } else {
                            $webhookCodes = array_column($app['webhooks'], 'code');
                            if (!in_array('app_delete', $webhookCodes) || !in_array('shop_update', $webhookCodes)) {
                                $registerOnboardingWebhooks = true;
                            }
                        }
                        break;
                    }
                }

                if (isset($registerOnboardingWebhooks) && $registerOnboardingWebhooks) {
                    $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init($marketplace, true, $appCode)
                        ->call('/appsWebhooks', [], ['shop_id' => $response['shop']['remote_shop_id']], 'GET');
                    if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                        $appWebhooks = $remoteResponse['data'];
                        foreach ($appWebhooks as $appWebhook) {
                            if ($appWebhook['code'] == "app_delete") {
                                $this->updatedRegisterUninstallWebhook($response['shop'], $appCode, $appWebhook);
                            } elseif($appWebhook['code'] == "shop_update") {
                                //Registering shop_update webhook when app installs
                                $this->updatedRegisterShopUpdateWebhook($response['shop'], $appCode, $appWebhook);
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->di->getLog()->logContent(json_encode([
                    'method' => __METHOD__,
                    'line' => __LINE__,
                    'message' => 'Error registering webhooks for ' . $this->di->getUser()->id,
                    'response' => $e->getMessage(),
                ]), 'info', 'Authorization' . DS . date('d-m-Y') . DS . 'commence_home_auth.log');
            }

            if (!isset($remotePostData['direct_call'])) {
                // Decode the state if it's present and not already decoded
                $rawState = $remotePostData['rawResponse']['state'] ?? null;
                $decodedState = is_string($rawState) ? json_decode($rawState, true) : [];

                // Try to get redirect type from session first
                $redirectType = $this->di->get('session')->get("redirect_return_type");
                // If not found in session, fallback to decoded state
                if (!$redirectType && isset($decodedState['redirect_return_type'])) {
                    $redirectType = $decodedState['redirect_return_type'];
                }
                switch ($redirectType) {
                    case "message":
                        $result['redirect_to_show_message'] = 'redirect_to_show_message';
                        break;

                    case "custom":
                        $result['redirect_custom_url'] = 'redirect_custom_url';
                        $result['custom_url'] = $this->di->get('session')->get("custom_url");
                        break;

                    default:
                        $result['redirect_to_dashboard'] = 1;
                }

                // Clean up session if used
                if ($this->di->get('session')->has("redirect_return_type")) {
                    $this->di->get('session')->delete("redirect_return_type");
                }
            }

            $result['shop'] = $marketplace;
            $result['connectionStatus'] = 1;
            $result['access_token'] = $this->di->getUser()->getToken();
            $result['refresh_token'] = $this->di->getUser()->getUserRefreshToken();
            $result['shop_data'] = $response;
        } else {
            if (isset($response['message'])) {
                $result['message'] = $response['message'];
            }

            if (isset($response['code'])) {
                $result['code'] = $response['code'];
            }

            if (isset($response['remote_shop_id'])) {
                $result['remote_shop_id'] = $response['remote_shop_id'];
            }
        }

        return $result;
    }

    public function updatedRegisterShopUpdateWebhook($shop, $appCode, $appWebhook)
    {
        $remoteShopId = $shop['remote_shop_id'];
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->Create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollectionForTable('user_details');
        $query = [
            [
                '$match' => [
                    'user_id' => $userId,
                    'shops.remote_shop_id' => $remoteShopId,
                ],
            ],
            [
                '$unwind' => '$shops',
            ],
            [
                '$match' => [
                    'user_id' => $userId,
                    'shops.remote_shop_id' => $remoteShopId,
                ],
            ],
        ];
        $result = $userDetailsCollection->aggregate($query)->toArray();
        if(empty($result[0]['shops'])) {
            return ['success' => false, 'message' => 'Shop data not found'];
        }
        $shop = $result[0]['shops'];
        $awsClient = require BP . '/app/etc/aws.php';
        $queueName = $this->di->getConfig()->app_code . "_" . $appWebhook['code'];
        $sqs = $this->di->getObjectManager()->get('App\Core\Components\Sqs');
        $sqsClient = $sqs->getClient($awsClient['region'], $awsClient['credentials']['key'] ?? null, $awsClient['credentials']['secret'] ?? null);
        $createQueueResponse = $sqs->createQueue($queueName, $sqsClient);

        $destinationRemotePayload = ['title' => 'User type Event Destination', 'type' =>  'user', 'event_handler' => 'sqs', 'destination_data' => [
            'type' => 'sqs',
            "sqs" => [
                "region" => $awsClient['region'],
                "key" => $awsClient['credentials']['key'] ?? null,
                "secret" => $awsClient['credentials']['secret'] ?? null,
                "queue_base_url" => $createQueueResponse['queue_url'] ? str_replace($queueName, "", $createQueueResponse['queue_url']) : "",
            ]
        ]];

        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('cedcommerce', false)
            ->call('event/destination', [], ['shop_id' => $remoteShopId, 'data' => $destinationRemotePayload, 'app_code' => "cedcommerce"], 'POST');

        if (isset($remoteResponse['destination_id']) && $remoteResponse['destination_id']) {
            $destinationId = $remoteResponse['destination_id'];
            $queueData = [
                'type' => 'full_class',
                'class_name' => '\App\Connector\Models\SourceModel',
                'method' => 'triggerWebhooks',
                'user_id' => $this->di->getUser()->id,
                'shop_id' => $shop['_id'],
                'action' => $appWebhook['code'],
                'queue_name' => $this->di->getConfig()->app_code . "_" . $appWebhook['code'],
                'app_code' => $appCode,
                'marketplace' => $shop['marketplace'] ?? '',
            ];

            $marketplaceData = [];
            if (isset($appWebhook['call_type'])) {
                $marketplaceData['call_type'] = $appWebhook['call_type'];
                $marketplaceData['plan_tier'] = 'default';
            }

            $subscriptionRemotePayload = [
                'event_code' => $appWebhook['topic'],
                'event_handler_id' => $destinationId,
                'marketplace_data' => $marketplaceData,
                'queue_data' => $queueData,
            ];
            $subscriptionResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('cedcommerce', true)
                ->call('event/subscription', [], ['shop_id' => $shop['remote_shop_id'], 'app_code' => "cedcommerce", 'data' => $subscriptionRemotePayload], 'POST');
            if (isset($subscriptionResponse['success']) && $subscriptionResponse['success']) {
                $this->di
                    ->getObjectManager()
                    ->get('App\Connector\Models\User\Shop')
                    ->addWebhook($shop, $appCode, [$appWebhook['code'] => ['dynamo_webhook_id' => $subscriptionResponse['config_save_result']['id'], 'marketplace_subscription_id' => ($subscriptionResponse['config_save_result']['marketplace_subscription_id'] ?? null)]]);
                return ['success' => true, 'message' => $appWebhook['code'] . ' webhook registered successfully'];
            } else {
                $this->di->getLog()->logContent(json_encode([
                    'method' => __METHOD__,
                    'line' => __LINE__,
                    'message' => 'Error creating shop_update event subscription',
                    'response' => $subscriptionResponse,
                ]), "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "remote_calls.log");
                return ['success' => false, 'message' => $subscriptionResponse];
            }
        } else {
            $message = 'Unable to Create/Get user destination for shop_update webhook';
            $this->di->getLog()->logContent(json_encode([
                'method' => __METHOD__,
                'line' => __LINE__,
                'message' => 'Error creating event destination for shop_update webhook',
                'response' => $remoteResponse,
            ]), "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "remote_calls.log");
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    // Onboarding Code Ends

    // Register Webhook code starts
    public function routeRegisterWebhooks($shop, $marketplace, $appCode, $destinationId = false, $destinationWisewebhooks = [], $activateAll = false)
    {
        if (empty($shop) || empty($shop['remote_shop_id']) || empty($marketplace) || empty($appCode)) {
            return ['success' => false, 'message' => "Either shop, appCode or marketplace data not passed properly to function might be one of the data is empty."];
        }

        $userId = $this->di->getUser()->id;
        $requiredShopKeys = ['_id', 'marketplace', 'remote_shop_id', 'apps'];
        foreach ($requiredShopKeys as $requiredShopKey) {
            $filteredShop[$requiredShopKey] = $shop[$requiredShopKey];
        }

        $handlerData = [
            'type' => 'full_class',
            'class_name' => '\App\Connector\Models\SourceModel',
            'method' => 'registerWebhooks',
            'queue_name' => 'register_webhook',
            'user_id' => $userId,
            'app_code' => $appCode,
            'shop_id' => $filteredShop['_id'],
            'data' => [
                'user_id' => $userId,
                'remote_shop_id' => $filteredShop['remote_shop_id'],
                'marketplace' => $marketplace,
                'appCode' => $appCode,
                'shop' => $filteredShop,
                'destination_wise_webhooks' => $destinationWisewebhooks,
                'destination_cursor' => 0,
                'cursor' => 0,
            ],
        ];

        $apiClient = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($marketplace, false, $appCode);

        // add shop if it doesn't exist
        $apiClient->call('cedcommerce/shop', [], [
            'shop_id' => $shop['remote_shop_id'],
        ], 'POST');

        if ($destinationId) {
            $handlerData['data']['destination_id'] = $destinationId;
            $updateCedAppShopResponse = $apiClient->call('update/ced_app_shops', [], ['shop_id' => $shop['remote_shop_id']], 'PUT');
        }

        if ($activateAll) {
            $handlerData['data']['activate_all'] = $activateAll;
        }

        if (!isset($updateCedAppShopResponse) || (isset($updateCedAppShopResponse['success']) && $updateCedAppShopResponse['success'])) {
            $result = [
                'success' => true,
                'message' => 'Registering Webhooks ...',
                'queue_sr_no' => $this->di->getMessageManager()->pushMessage($handlerData),
            ];
        }

        if (isset($updateCedAppShopResponse['success'], $updateCedAppShopResponse['message']) && !$updateCedAppShopResponse['success']) {
            $this->di->getLog()->logContent(json_encode([
                'method' => __METHOD__,
                'line' => __LINE__,
                'message' => '',
                'response' => $updateCedAppShopResponse,
            ]), "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "sqs.log");
            $result['success'] = $updateCedAppShopResponse['success'];
            $result['message'] = $updateCedAppShopResponse['message'];
        }

        return $result;
    }

    public function registerWebhooks($sqsData)
    {
        $userId = $this->di->getUser()->id;
        $shop = $sqsData['data']['shop'];
        $appCode = $sqsData['data']['appCode'];
        $marketplace = $sqsData['data']['marketplace'];
        $remoteShopId = $sqsData['data']['remote_shop_id'];

        #TODO: handle multiple destination wise webhook register process
        $destinationWiseWebhooks = $sqsData['data']['destination_wise_webhooks'];
        $destinationCount = count($destinationWiseWebhooks);
        $destinationCursor = $sqsData['data']['destination_cursor'];
        if ($destinationCount > $destinationCursor) {
            $destinationId = $destinationWiseWebhooks[$destinationCursor]['destination_id'];
            $eventCodes = $destinationWiseWebhooks[$destinationCursor]['event_codes'];
        } else {
            $eventCodes = [];
        }

        $webhookHanldingApps = [];
        $registeredWebhooksInApp = [];
        $registeredWebhooksInShop = [];

        if (!$this->di->getCache()->get('registeredWebhooksInApp_' . $userId . '_' . $appCode) || !$this->di->getCache()->get('registeredWebhooksInShop_' . $userId . '_' . $appCode)) {
            foreach ($shop['apps'] as $app) {
                if (isset($app['webhooks'])) {
                    $webhookHanldingApps[$app['code']] = $app;
                    if ($app['code'] == $appCode) {
                        foreach ($app['webhooks'] as $webhook) {
                            $registeredWebhooksInApp[$webhook['code']] = 1;
                        }
                    }

                    foreach ($app['webhooks'] as $webhook) {
                        if ($webhook['code'] != 'app_delete') {
                            $registeredWebhooksInShop[$webhook['code']] = $webhook['dynamo_webhook_id'];
                        }
                    }
                }
            }

            $this->di->getCache()->set('registeredWebhooksInApp_' . $userId . '_' . $appCode, $registeredWebhooksInApp);
            $this->di->getCache()->set('registeredWebhooksInShop_' . $userId . '_' . $appCode, $registeredWebhooksInShop);
            $this->di->getCache()->set('webhookHanldingApps_' . $userId . '_' . $appCode, $webhookHanldingApps);
        } else {
            $registeredWebhooksInShop = $this->di->getCache()->get('registeredWebhooksInShop_' . $userId . '_' . $appCode);
            $registeredWebhooksInApp = $this->di->getCache()->get('registeredWebhooksInApp_' . $userId . '_' . $appCode);
            $webhookHanldingApps = $this->di->getCache()->get('webhookHanldingApps_' . $userId . '_' . $appCode);
        }

        if (!$this->di->getCache()->get('marketplaceWebhooks_' . $userId . '_' . $appCode)) {
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($marketplace, true, $appCode)
                ->call('/marketplaceswebhooks', [], ['shop_id' => $remoteShopId], 'GET');
            if (isset($remoteResponse['success'])) {
                $webhooks = $remoteResponse['Webhooks'][$marketplace];
                $marketplaceswebhooks = [];
                if (!empty($webhooks)) {
                    foreach ($webhooks as $webhook) {
                        $webhook['marketplace'] = $marketplace;
                        $webhook['app_code'] = $appCode;
                        $webhook['action'] = $webhook['code'];
                        $webhook['queue_name'] ??= $webhook['code'];
                        $marketplaceswebhooks[$webhook['code']] = $webhook;
                    }
                }

                $this->di->getCache()->set('marketplaceWebhooks_' . $userId . '_' . $appCode, $marketplaceswebhooks);
            } else {
                $this->di->getLog()->logContent(json_encode([
                    'method' => __METHOD__,
                    'line' => __LINE__,
                    'message' => 'Error registering webhooks',
                    'response' => $remoteResponse,
                ]), "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "webhook.log");
                return true;
            }
        }

        if (!$this->di->getCache()->get('appsWebhooks_' . $userId . '_' . $appCode)) {
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($marketplace, true, $appCode)
                ->call('/appsWebhooks', [], ['shop_id' => $remoteShopId], 'GET');
            if (isset($remoteResponse['success'])) {
                $webhooks = $remoteResponse['data'];
                $appCodeWebhooks = [];

                if (!empty($webhooks)) {
                    #TODO: handle selected webhooks register process
                    if (!empty($eventCodes)) {
                        $selectedWebhooks = [];
                        foreach ($eventCodes as $code) {
                            foreach ($webhooks as $webhook) {
                                if ($webhook['code'] == $code) {
                                    array_push($selectedWebhooks, $webhook);
                                }
                            }
                        }

                        if (empty($selectedWebhooks)) {
                            $this->di->getLog()->logContent(date("d-m-y H:i:s") . " Inside registerWebhooks function : some event codes are invalid or not selected by app   ==>  " . print_r($eventCodes, true), "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "webhook.log");
                            return true;
                        }

                        $webhooks = $selectedWebhooks;
                    }

                    foreach ($webhooks as $webhook) {
                        $webhook['marketplace'] = $marketplace;
                        $webhook['app_code'] = $appCode;
                        $webhook['action'] = $webhook['code'];
                        $webhook['queue_name'] ??= $webhook['code'];
                        $appCodeWebhooks[$webhook['code']] = $webhook;
                    }
                }

                $this->di->getCache()->set('appsWebhooks_' . $userId . '_' . $appCode, $appCodeWebhooks);
            } else {
                $this->di->getLog()->logContent(json_encode([
                    'method' => __METHOD__,
                    'line' => __LINE__,
                    'response' => $remoteResponse,
                    'message' => 'Error registering webhooks',
                ]), "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "remote_calls.log");
                return true;
            }
        } else {
            $appCodeWebhooks = $this->di->getCache()->get('appsWebhooks_' . $userId . '_' . $appCode);
        }

        if (count($registeredWebhooksInApp)) {
            if (isset($webhookHanldingApps[$appCode])) {
                if (!isset($sqsData['data']['activate_all'])) {
                    foreach ($appCodeWebhooks as $code => $savedWebhook) {
                        if (isset($registeredWebhooksInApp[$code])) {
                            unset($appCodeWebhooks[$code]);
                        }
                    }
                }

                if (count($appCodeWebhooks)) {
                    $this->callRegisterWebhook($appCodeWebhooks, $sqsData, $registeredWebhooksInShop);
                } else {
                    $this->removeAppWiseUnusedWebhooks($sqsData);
                    $this->di->getCache()->delete('marketplaceWebhooks_' . $userId . '_' . $appCode);
                    $this->di->getCache()->delete('appsWebhooks_' . $userId . '_' . $appCode);
                    $this->di->getCache()->delete('registeredWebhooksInShop_' . $userId . '_' . $appCode);
                    $this->di->getCache()->delete('registeredWebhooksInApp_' . $userId . '_' . $appCode);
                    $this->di->getCache()->delete('webhookHanldingApps_' . $userId . '_' . $appCode);
                }
            } else {
                if (count($appCodeWebhooks)) {
                    $this->callRegisterWebhook($appCodeWebhooks, $sqsData, $registeredWebhooksInShop);
                }
            }
        } else {
            if (count($appCodeWebhooks)) {
                $this->callRegisterWebhook($appCodeWebhooks, $sqsData, $registeredWebhooksInShop);
            }
        }

        return true;
    }

    public function callRegisterWebhook($Webhooks, $sqsData, $registeredWebhooksInShop)
    {
        $userId = $this->di->getUser()->id;
        $shop = $sqsData['data']['shop'];
        $appCode = $sqsData['data']['appCode'];
        $cursor = $sqsData['data']['cursor'];
        $count = 0;
        $eventsManager = $this->di->getEventsManager();

        // #TODO: handle multiple destination wise webhook register process
        $destinationWiseWebhooks = $sqsData['data']['destination_wise_webhooks'];
        $destinationCount = count($destinationWiseWebhooks);
        $destinationCursor = $sqsData['data']['destination_cursor'];
        if ($destinationCount > $destinationCursor) {
            $destinationId = $destinationWiseWebhooks[$destinationCursor]['destination_id'];
            $sqsData['data']['destination_id'] = $destinationId;
        }

        while ($cursor < count($Webhooks) && $count < 5) {
            $webhookId = array_keys($Webhooks)[$cursor];

            if (isset($sqsData['data']['destination_id'])) {

                $destinationId = $sqsData['data']['destination_id'];

                #TODO: stop webhook registration process, if destination not found.
                if (!$this->di->getCache()->get("destination_id_" . $destinationId . "_" . $userId)) {
                    $destination = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init('cedcommerce', true)
                        ->call('event/destination', [], ['shop_id' => $shop['remote_shop_id'], 'event_handler_id' => $destinationId, 'app_code' => "cedcommerce"], 'GET');
                    $this->di->getCache()->set("destination_id_" . $destinationId . "_" . $userId, $destination);
                } else {
                    $destination = $this->di->getCache()->get("destination_id_" . $destinationId . "_" . $userId);
                }

                #TODO: In case of multiple  destination, skip the process if destination not found.
                if (!isset($destination['success'], $destination['data']) || empty($destination['data'])) {
                    $destinationCursor++;
                    if ($destinationCount > $destinationCursor) {
                        $this->di->getCache()->delete('appsWebhooks_' . $userId . '_' . $appCode);
                        $sqsData['data']['destination_cursor'] = $destinationCursor;
                        $sqsData['data']['cursor'] = 0;
                        $sqsData['data']['destination_id'] = $sqsData['data']['destination_wise_webhooks'][$sqsData['data']['destination_cursor']]['destination_id'];
                        $this->di->getMessageManager()->pushMessage($sqsData);
                    }

                    return true;
                }

                $registeredWebhookres = $this->updatedRegisterWebhook($shop, $appCode, $webhookId, $sqsData['data']['destination_id'] ?? $destinationId, $registeredWebhooksInShop);
            } else {
                $registeredWebhookres = $this->registerWebhook($shop, $appCode, $webhookId);
            }

            if (isset($registeredWebhookres['success']) && $registeredWebhookres['success']) {
                if (!isset($sqsData['data']['addWebhook'])) {
                    $sqsData['data']['addWebhook'] = [];
                }

                $sqsData['data']['addWebhook'][$webhookId] = $Webhooks[$webhookId];
                $sqsData['data']['addWebhook'][$webhookId]['dynamo_webhook_id'] = $registeredWebhookres['config_save_result']['id'] ?? $registeredWebhookres['cif_subscription_id'] ?? "test_id";
                $sqsData['data']['addWebhook'][$webhookId]['marketplace_subscription_id'] = $registeredWebhookres['config_save_result']['marketplace_subscription_id'] ?? null;
            }

            $count++;
            $cursor++;
        }

        $sqsData['data']['cursor'] = $cursor;
        if (isset(array_keys($Webhooks)[$cursor])) {
            $this->di->getMessageManager()->pushMessage($sqsData);
            return true;
        }
        if (!empty($sqsData['data']['addWebhook'])) {
            $res = $this->di
                ->getObjectManager()
                ->get('App\Connector\Models\User\Shop')
                ->addWebhook($shop, $appCode, $sqsData['data']['addWebhook']);
        }

        $this->removeWebhooksNotUsedByApps($shop['remote_shop_id'], $sqsData['data']['marketplace'], $sqsData);
        $this->di->getCache()->delete('marketplaceWebhooks_' . $userId . '_' . $appCode);
        $this->di->getCache()->delete('appsWebhooks_' . $userId . '_' . $appCode);
        $this->di->getCache()->delete('registeredWebhooksInShop_' . $userId . '_' . $appCode);
        $this->di->getCache()->delete('registeredWebhooksInApp_' . $userId . '_' . $appCode);
        $this->di->getCache()->delete('webhookHanldingApps_' . $userId . '_' . $appCode);
        if (isset($sqsData['data']['destination_id'])) {
            $this->di->getCache()->delete("destination_id_" . $sqsData['data']['destination_id'] . "_" . $userId);
        }

        $eventsManager->fire('application:afterWebhookRegister', $this, $sqsData);

        $destinationCursor++;

        #TODO: handle multiple destination wise webhook register process
        if ($destinationCount > $destinationCursor) {
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shop = $userDetails->getShop($sqsData['shop_id'], $this->di->getUser()->id, true);
            $shopData = [
                '_id' => $shop['_id'],
                'marketplace' => $shop['marketplace'],
                'remote_shop_id' => $shop['remote_shop_id'],
                'apps' => $shop['apps'],
            ];
            $sqsData['data']['destination_cursor'] = $destinationCursor;
            $sqsData['data']['cursor'] = 0;
            $sqsData['data']['shop'] = $shopData;
            $sqsData['data']['destination_id'] = $sqsData['data']['destination_wise_webhooks'][$sqsData['data']['destination_cursor']]['destination_id'];
            $this->di->getMessageManager()->pushMessage($sqsData);
        }
    }

    public function updatedRegisterWebhook($shop, $appCode, $webhookId, $destinationId, $registeredWebhooksInShop)
    {
        $userId = $this->di->getUser()->id;
        $webhook = $this->di->getCache()->get('marketplaceWebhooks_' . $userId . '_' . $appCode)[$webhookId]; //marketplace webhooks
        $appWebhook = $this->di->getCache()->get('appsWebhooks_' . $userId . '_' . $appCode)[$webhookId]; //appCode webhooks
        $defaultWebhook = $this->di->getConfig()->webhook->get('default')->get($webhookId) ? $this->di->getConfig()->webhook->get('default')->get($webhookId)->toArray() : ['queue_config_id' => $this->di->getConfig()->queue_config_id]; //default webhooks

        if ($this->di->getConfig()->webhook->get($appCode)) {
            $configAppWebhook = $this->di->getConfig()->webhook->get($appCode)->get($webhookId) ? $this->di->getConfig()->webhook->get($appCode)->get($webhookId)->toArray() : [];
            $appWebhook = array_merge($appWebhook ?? [], $configAppWebhook ?? []);
        }

        $webhook = array_merge($defaultWebhook ?? [], $webhook ?? []);
        $webhook = array_merge($webhook ?? [], $appWebhook ?? []);

        $queueName = isset($webhook['queue_name']) ? $this->di->getConfig()->app_code . '_' . $webhook['queue_name'] : $this->di->getConfig()->app_code . "_" . $webhookId;
        $queue_data = [
            'type' => 'full_class',
            'class_name' => $webhook['class_name'] ?? '\App\Connector\Models\SourceModel',
            'method' => $webhook['method'] ?? 'triggerWebhooks',
            'user_id' => $userId,
            'shop_id' => $shop['_id'],
            'action' => $webhook['action'],
            'queue_name' => strtolower($queueName),
            'app_code' => $appCode,
            'marketplace' => $shop['marketplace'] ?? '',
        ];

        $marketplaceData = [];
        if (isset($appWebhook['call_type'])) {
            $marketplaceData['call_type'] = $appWebhook['call_type'];
        }

        $webhooksToBeUpdatedAsPerPlanTier = $this->di->getConfig()->webhooks_to_be_updated_as_per_plan_tier->get($appCode)
            ? $this->di->getConfig()->webhooks_to_be_updated_as_per_plan_tier->get($appCode)->toArray() : [];
        if (!empty($webhooksToBeUpdatedAsPerPlanTier)) {
            if (in_array($webhook['topic'], $webhooksToBeUpdatedAsPerPlanTier)) {
                $marketplaceData['plan_tier'] = isset($this->di->getUser()->plan_tier) ? $this->di->getUser()->plan_tier : 'default';
            } else {
                $marketplaceData['plan_tier'] = 'default';
            }
        }

        $data = array(
            'event_code' => $webhook['topic'],
            'event_handler_id' => $destinationId,
            'marketplace_data' => $marketplaceData,
            'queue_data' => $queue_data,
        );

        if (!$this->di->getCache()->get("destination_id_" . $destinationId . "_" . $userId)) {
            $destination = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('cedcommerce', true)
                ->call('event/destination', [], ['shop_id' => $shop['remote_shop_id'], 'event_handler_id' => $destinationId, 'app_code' => "cedcommerce"], 'GET');
            $this->di->getCache()->set("destination_id_" . $destinationId . "_" . $userId, $destination);
        } else {
            $destination = $this->di->getCache()->get("destination_id_" . $destinationId . "_" . $userId);
        }

        if (isset($destination['success'], $destination['data']) && !empty($destination['data'])) {
            if ($destination['data'][0]['type'] == 'user_app' && $webhookId != 'app_delete') {
                $this->di->getLog()->logContent(date("d-m-y H:i:s") . " Inside updatedRegisterWebhook function : Cannot create subscription, error is   ==>  Invalid destination type. Allowed Destination types are user or app.", "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "remote_calls.log");
                return ['success' => false, 'message' => "Invalid destination type.Allowed Destination types are user or app."];
            }

            if ($destination['data'][0]['type'] != 'user_app' && $webhookId == 'app_delete') {
                $this->di->getLog()->logContent(date("d-m-y H:i:s") . " Inside updatedRegisterWebhook function : Cannot create subscription, error is   ==>  Invalid destination type. Allowed Destination types is user_app for app_delete .", "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "remote_calls.log");
                return ['success' => false, 'message' => "Invalid destination type.Allowed Destination types is user_app."];
            }

            if (array_key_exists($webhookId, $registeredWebhooksInShop)) {
                $this->di->getLog()->logContent(date("d-m-y H:i:s") . " Inside updatedRegisterWebhook function : Webhook already registered with id   ==>  " . print_r($registeredWebhooksInShop[$webhookId], true), "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "remote_calls.log");
                return ['success' => true, 'config_save_result' => ['id' => $registeredWebhooksInShop[$webhookId]]];
            }

            if ($destination['data'][0]['type'] == 'user_app' && $webhookId == 'app_delete') {
                $data['queue_data']['app_code'] = $appCode;
            }

            if (isset($shop['is_new_account_connection'])) {
                $data['is_new_account_connection'] = true;
            }

            $responseWbhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('cedcommerce', true)
                ->call('event/subscription', [], ['shop_id' => $shop['remote_shop_id'], 'app_code' => "cedcommerce", 'data' => $data], 'POST');

            return $responseWbhook;
        }
        $this->di->getLog()->logContent(json_encode([
            'method' => __METHOD__,
            'line' => __LINE__,
            'message' => "Can not get destination data",
            'response' => $destination,
        ]), "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "remote_calls.log");
        return true;
    }

    public function registerWebhook($shop, $appCode, $webhookId)
    {

        $userId = $this->di->getUser()->id;
        $awsConfig = include BP . DS . 'app' . DS . 'etc' . DS . 'aws.php';

        $webhook = $this->di->getCache()->get('marketplaceWebhooks_' . $userId . '_' . $appCode)[$webhookId]; //marketplace webhooks

        #TODO: if cache is not available, fetch the marketplace webhooks from api call
        if (empty($webhook)) {
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($shop['marketplace'], true, $appCode)
                ->call('/marketplaceswebhooks', [], ['shop_id' => $shop['remote_shop_id']], 'GET');

            if ($remoteResponse['success']) {
                $webhooks = $remoteResponse['data'];
                $appCodeWebhooks = [];
                if (!empty($webhooks)) {
                    foreach ($webhooks as $hook) {
                        $hook['marketplace'] = $shop['marketplace'];
                        $hook['app_code'] = $appCode;
                        $hook['action'] = $hook['code'];
                        $hook['queue_name'] = $hook['code'];
                        $appCodeWebhooks[$hook['code']] = $hook;
                    }
                }
            }

            $webhook = $appCodeWebhooks[$webhookId];
        }

        $appWebhook = $this->di->getCache()->get('appsWebhooks_' . $userId . '_' . $appCode)[$webhookId]; //appCode webhooks

        #TODO: if cache is not available, fetch the apps webhooks from api call

        if (empty($appWebhook)) {
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($shop['marketplace'], true, $appCode)
                ->call('/appsWebhooks', [], ['shop_id' => $shop['remote_shop_id']], 'GET');
            if ($remoteResponse['success']) {
                $webhooks = $remoteResponse['data'];
                $appCodeWebhooks = [];
                if (!empty($webhooks)) {
                    foreach ($webhooks as $webhook) {
                        $webhook['marketplace'] = $shop['marketplace'];
                        $webhook['app_code'] = $appCode;
                        $webhook['action'] = $webhook['code'];
                        $webhook['queue_name'] ??= $webhook['code'];
                        $appCodeWebhooks[$webhook['code']] = $webhook;
                    }
                }
            }

            $appWebhook = $appCodeWebhooks[$webhookId];
        }

        $defaultWebhook = $this->di->getConfig()->webhook->get('default')->get($webhookId) ? $this->di->getConfig()->webhook->get('default')->get($webhookId)->toArray() : ['queue_config_id' => $this->di->getConfig()->queue_config_id]; //default webhooks

        if ($this->di->getConfig()->webhook->get($appCode)) {
            $configAppWebhook = $this->di->getConfig()->webhook->get($appCode)->get($webhookId) ? $this->di->getConfig()->webhook->get($appCode)->get($webhookId)->toArray() : [];
            $appWebhook = array_merge($appWebhook, $configAppWebhook);
        }

        $webhook = array_merge($defaultWebhook, $webhook);
        $webhook = array_merge($webhook, $appWebhook);

        $queueName = isset($webhook['queue_name']) ? $this->di->getConfig()->app_code . '_' . $webhook['queue_name'] : $this->di->getConfig()->app_code . "_" . $webhookId;

        $webhookData = [
            'type' => 'sqs',
            'queue_config' => [
                'region' => $awsConfig['region'],
                'key' => $awsConfig['credentials']['key'] ?? null,
                'secret' => $awsConfig['credentials']['secret'] ?? null,
            ],
            'webhook_code' => $webhook['code'],
            'queue_config_id' => $webhook['queue_config_id'] ?? 'sqs-' . $this->di->getConfig()->app_code, // new index
            'format' => 'json',
            'queue_name' => strtolower($queueName),
            'queue_data' => [
                'type' => 'full_class',
                'class_name' => $webhook['class_name'] ?? '\App\Connector\Models\SourceModel',
                'method' => $webhook['method'] ?? 'triggerWebhooks',
                'user_id' => $userId,
                'shop_id' => $shop['_id'],
                'action' => $webhook['action'],
                'queue_name' => strtolower($queueName),
                'app_code' => $appCode,
                'marketplace' => $shop['marketplace'] ?? '',
            ],
        ];
        $model = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($shop['marketplace'])->get('source_model'));

        if (method_exists($model, 'prepareWebhookData')) {
            $model->prepareWebhookData($shop, $appCode, $webhook, $webhookData);
        }

        $webhook['queue_name'] = $webhookData['queue_name'];
        $responseWbhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($shop['marketplace'], 'true', $appCode)
            ->call('/webhook/register', [], ['shop_id' => $shop['remote_shop_id'], 'data' => $webhookData, 'webhook' => $webhook], 'POST');
        return $responseWbhook;
    }

    public function removeWebhooksNotUsedByApps($remoteShopId, $marketplace, $sqsData): void
    {
        // $shop = [];
        if (isset($sqsData['data']['destination_id'])) {
            $this->removeAppWiseUnusedWebhooks($sqsData);
        } else {
            $user = $this->di->getObjectManager()->create('\App\Core\Models\User\Details')->getUserByRemoteShopId($remoteShopId, ['shops' => 1]);
            if (isset($user['shops'])) {
                $shops = $user['shops'];
                $allWebhooks = [];
                $allSavedWebhhok = [];

                foreach ($shops as $sh) {
                    if ($sh['remote_shop_id'] == $remoteShopId) {

                        foreach ($sh['apps'] as $app) {
                            $appCode = $app['code'];
                            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                                ->init($marketplace, true, $appCode)
                                ->call('/appsWebhooks', [], ['shop_id' => $remoteShopId], 'GET');
                            $webhooks = $remoteResponse['data'];
                            // $allWebhooks = [];
                            if (!empty($webhooks)) {
                                foreach ($webhooks as $webhook) {
                                    $webhook['marketplace'] = $marketplace;
                                    $webhook['app_code'] = $appCode;
                                    $webhook['action'] = $webhook['code'];
                                    $webhook['queue_name'] ??= $webhook['code'];
                                    $allWebhooks[$webhook['code']] = $webhook;
                                }
                            }

                            if (isset($app['webhooks'])) {
                                $webhooks = $app['webhooks'];

                                foreach ($webhooks as $webhookCode) {
                                    $allSavedWebhhok[$webhookCode['code']] = ['savad_data' => $webhookCode, 'app_code' => $appCode];
                                }
                            }
                        }

                        $shop = $sh;
                        break;
                    }
                }
            }

            if (!empty($allSavedWebhhok)) {
                $removeWebhooks = array_diff_key($allSavedWebhhok, $allWebhooks);

                if (!empty($removeWebhooks)) {
                    $finalRemoveWebhook = [];
                    foreach ($removeWebhooks as $savedDataWithAppCode) {
                        $finalRemoveWebhook[$savedDataWithAppCode['app_code']][] = $savedDataWithAppCode['savad_data'];
                    }

                    foreach ($finalRemoveWebhook as $appCode => $webhook) {
                        $this->removeAppCodeWiseWebhhok($shop, $webhook, $appCode);
                    }
                }
            }
        }
    }

    public function removeAppCodeWiseWebhhok($shop, $webhooks, $appCode)
    {
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $user = $mongo->getCollectionForTable("user_details");
        $dynamoIds = [];
        $appWiseWebhookIds = [];
        foreach ($webhooks as $savedData) {
            $appWiseWebhookIds[$appCode][] = $savedData['code'];
            $dynamoIds[] = $savedData['dynamo_webhook_id'];
        }

        foreach ($appWiseWebhookIds as $appCode => $webhookIds) {
            $res = $user->updateOne(
                [
                    "_id" => new \MongoDB\BSON\ObjectId($userId),
                ],
                [
                    '$pull' => [
                        'shops.$[shop].apps.$[app].webhooks' => [
                            'code' => ['$in' => $webhookIds],
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

        if (!empty($dynamoIds)) {
            $responseWbhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($shop['marketplace'], 'true')
                ->call("/webhooknew/unregister", [], ['shop_id' => $shop['remote_shop_id'], 'dynamoIds' => $dynamoIds], 'DELETE');
            return $responseWbhook;
        }
    }

    public function registerUninstallWebhook($shop, $appCode): array
    {
        $isVersion2 = $this->di->getConfig()->path("webhooks.version", "v1") == "v2";
        if ($isVersion2) {
            return $this->subscribeUninstallWebhook($shop, $appCode);
        }
        $registeredWebhookres = $this->registerWebhook($shop, $appCode, 'app_delete');

        if (isset($registeredWebhookres['success']) && $registeredWebhookres['success']) {

            $webhooks['app_delete']['dynamo_webhook_id'] = $registeredWebhookres['config_save_result']['id'] ?? $registeredWebhookres['data']['id'] ?? "test_id";
            if (!empty($webhooks['app_delete']['dynamo_webhook_id'])) {
                $this->di
                    ->getObjectManager()
                    ->get('App\Connector\Models\User\Shop')
                    ->addWebhook($shop, $appCode, $webhooks);
            }
        }
        return [
            'success' => true,
            'message' => 'Webhook registered successfully',
        ];
    }

    public function subscribeUninstallWebhook($shop, $appCode): array
    {
        $subscriptionService = $this->di->getObjectManager()->get('App\Connector\Components\Webhook\SubscriptionService');
        return $subscriptionService->subscribe([
            'marketplace' => $shop['marketplace'],
            'shop_id' => $shop['_id'],
            'app_code' => $appCode,
            'webhooks' => [
                [
                    'topic' => 'app/uninstalled',
                    'code' => 'app_delete',
                    'queueable' => false,
                ],
            ],
        ]);
    }

    public function updatedRegisterUninstallWebhook($shop, $appCode, $appWebhook)
    {
        $isVersion2 = $this->di->getConfig()->path("webhooks.version", "v1") == "v2";
        if ($isVersion2) {
            return $this->subscribeUninstallWebhook($shop, $appCode);
        }
        try {
            $awsCredentials = require BP . '/app/etc/aws.php';
            $marketplace = $shop['marketplace'];
            $remoteShopId = $shop['remote_shop_id'];
            $userId = $this->di->getUser()->id;
            $mongo = $this->di->getObjectManager()->Create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $query = [
                [
                    '$match' => [
                        'user_id' => $userId,
                        'shops.remote_shop_id' => $remoteShopId,
                    ],
                ],
                [
                    '$unwind' => '$shops',
                ],
                [
                    '$match' => [
                        'user_id' => $userId,
                        'shops.remote_shop_id' => $remoteShopId,
                    ],
                ],
            ];
            $result = $userDetailsCollection->aggregate($query)->toArray();
            $shop = $result[0]['shops'];

            //Fetch marketplace credentials from aws.php if it exists else use the default credentials
            if (isset($awsCredentials[$marketplace][$appCode])) {
                $awsClient = $awsCredentials[$marketplace][$appCode];
            } else {
                $awsClient = $awsCredentials;
            }

            $queueName = $this->di->getConfig()->app_code . "_" . "app_delete";
            $sqs = $this->di->getObjectManager()->get('App\Core\Components\Sqs');
            if (isset($awsClient['region'], $awsClient['credentials']['key'], $awsClient['credentials']['secret'])) {
                $sqsClient = $sqs->getClient($awsClient['region'], $awsClient['credentials']['key'], $awsClient['credentials']['secret']);
            } else {
                $sqsClient = $this->di->getObjectManager()->get(\App\Core\Components\Message\Handler\Sqs::class)->getClient();
            }

            $createQueueResponse = $sqs->createQueue($queueName, $sqsClient);

            $cifDestinationData = [
                'title' => 'User_app type Event Destination',
                'type' => 'user_app',
                'event_handler' => 'sqs',
                "destination_data" => [
                    "type" => "sqs",
                    "sqs" => array_filter([
                        // Check if 'region', 'key', and 'secret' exist in $awsClient array and are not empty
                        "region" => !empty($awsClient['region']) ? $awsClient['region'] : null,
                        "key" => !empty($awsClient['credentials']['key']) ? $awsClient['credentials']['key'] : null,
                        "secret" => !empty($awsClient['credentials']['secret']) ? $awsClient['credentials']['secret'] : null,
                        "queue_base_url" => $createQueueResponse['queue_url'] ? str_replace($queueName, "", $createQueueResponse['queue_url']) : "",
                    ]),
                ],
            ];
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('cedcommerce', true)
                ->call('event/destination', [], ['shop_id' => $remoteShopId, 'data' => $cifDestinationData, 'app_code' => "cedcommerce"], 'POST');
            if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                $destinationId = $remoteResponse['destination_id'];

                $queue_data = [
                    'type' => 'full_class',
                    'class_name' => '\App\Connector\Models\SourceModel',
                    'method' => 'triggerWebhooks',
                    'user_id' => $userId,
                    'shop_id' => $shop['_id'],
                    'action' => "app_delete",
                    'queue_name' => $this->di->getConfig()->app_code . "_" . "app_delete",
                    'app_code' => $appCode,
                    'marketplace' => $shop['marketplace'] ?? '',
                ];

                $marketplaceData = [];
                if (isset($appWebhook['call_type'])) {
                    $marketplaceData['call_type'] = $appWebhook['call_type'];
                    $marketplaceData['plan_tier'] = 'default';
                }

                $data = array(
                    'event_code' => $appWebhook['topic'],
                    'event_handler_id' => $destinationId,
                    'marketplace_data' => $marketplaceData,
                    'queue_data' => $queue_data,
                );

                if (isset($createQueueResponse['success']) && $createQueueResponse['success']) {
                    $subscriptionResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init('cedcommerce', true)
                        ->call('event/subscription', [], ['shop_id' => $shop['remote_shop_id'], 'app_code' => "cedcommerce", 'data' => $data], 'POST');
                    if (isset($subscriptionResponse['success']) && $subscriptionResponse['success']) {
                        $this->di
                            ->getObjectManager()
                            ->get('App\Connector\Models\User\Shop')
                            ->addWebhook($shop, $appCode, ['app_delete' => ['dynamo_webhook_id' => $subscriptionResponse['config_save_result']['id'], 'marketplace_subscription_id' => ($subscriptionResponse['config_save_result']['marketplace_subscription_id'] ?? null)]]);
                    } else {
                        $this->di->getLog()->logContent(json_encode([
                            'method' => __METHOD__,
                            'line' => __LINE__,
                            'message' => 'Error creating event subscription',
                            'response' => $subscriptionResponse,
                        ]), "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "remote_calls.log");
                        return ['success' => false, 'message' => $subscriptionResponse];
                    }
                } else {
                    return ['success' => false, 'message' => $createQueueResponse];
                }
            } else {
                $this->di->getLog()->logContent(json_encode([
                    'method' => __METHOD__,
                    'line' => __LINE__,
                    'message' => 'Error creating event destination',
                    'response' => $remoteResponse,
                ]), "info", "webhook" . DS . $userId . "_" . $shop['_id'] . "_" . $appCode . DS . "register_webhook" . DS . date("Y-m-d") . DS . "remote_calls.log");
                return ['success' => false, 'message' => $remoteResponse];
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function initiateImport($rawData = [], $userId = null, $force = false)
    {
        $origin = $rawData['data']['import_origin'] ?? 'source';
        if (!empty($rawData[$origin]['marketplace'])) {
            $sourceData = $rawData[$origin];
            $marketplace = $sourceData['marketplace'];
            $shopId = $sourceData['shopId'];
            $userId ??= $this->di->getUser()->id;

            if (!$userId) {
                return ['success' => false, 'message' => 'Invalid User. Please check your login credentials'];
            }
            if ($marketplace && $shopId) {
                $shop = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")
                    ->getShop($shopId, $userId);
                return $this->checkAndPushToImportQueue($userId, $shop, $rawData, $force);
            }

            if (!empty($sourceData['data']['import_all'])) {
                $shops = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")
                    ->findShop(
                        [
                            'marketplace' => $marketplace,
                        ],
                        $userId
                    );
                $result = [];
                if (empty($shops)) {
                    return [
                        'success' => false,
                        'message' => 'Marketplace not found, please connect the marketplace first',
                    ];
                }

                foreach ($shops as $shop) {
                    $result[] = $this->checkAndPushToImportQueue($userId, $shop, $rawData, $force);
                }

                return $result[0] ?? ['success' => false, 'message' => 'Message not given by admin'];
            }
        } else {
            return [
                'success' => false,
                'message' => ucfirst($origin) . ' ' . 'data is required for importing',
            ];
        }
    }

    public function checkAndPushToImportQueue($userId, $shop, $data, $force)
    {
        try {
            $processPrefix = isset($data['data']['item_import_type']) ? $data['data']['item_import_type'] : '';
            $processCode = 'product_import';
            $queueName = $shop['marketplace'] . '_product_import';

            if (!empty($processPrefix)) {
                $processCode = $processPrefix . '_' . $processCode;
                $queueName = $shop['marketplace'] . '_' . $processCode;
            }

            $queueData = [
                'user_id' => $userId,
                // 'message' => "Just a moment! We're retrieving your products from the source catalog. We'll let you know once it's done.",
                // For TikTok
                'message' => $this->di->getLocale()->_('retrieving_your_products_from_the_source', [
                    'sourceName' => $shop['marketplace'],
                ]),
                // ----------
                'process_code' => $processCode,
                'marketplace' => $shop['marketplace'],
            ];
            $shop['frontend_request'] = $data;
            $sourceModel = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($shop['marketplace'])->get('source_model'));
            method_exists($sourceModel, 'getMarketplaceAppType') ? $sourceModel->getMarketplaceAppType($data, $queueData) : [];
            $marketplaceProductCount = method_exists($sourceModel, 'getAllProductCount') ? $sourceModel->getAllProductCount($shop) : false;

            if ($marketplaceProductCount && empty($marketplaceProductCount['success'])) {
                if ($marketplaceProductCount['count'] == 0) {
                    $eventData['source_marketplace'] = $shop['marketplace'];
                    $eventData['source_shop_id'] = $shop['_id'];
                    $eventData['target_marketplace'] =  $data['target']['marketplace'];
                    $eventData['target_shop_id'] =  $data['target']['shopId'];
                    $eventData['additional_data'] = $data['data'];
                    $eventData['user_id'] = $userId;
                    $eventData['isImported'] = $this->checkForReImporting($eventData)['data']['isImported'] ?? false;
                    $eventsManager = $this->di->getEventsManager();
                    $eventsManager->fire('application:afterProductImport', $this, $eventData);
                }
                return $marketplaceProductCount;
            }

            $result = $this->checKIfVariantDeleteQueueExists($shop, $userId);
            if (!$result['success']) {
                return ['success' => false, 'message' => $this->di->getLocale()->_('product_import_already_in_progress')];
            }

            $queuedTask = new \App\Connector\Models\QueuedTasks;
            $queuedTaskId = $queuedTask->setQueuedTask($shop['_id'], $queueData);
            if (!$queuedTaskId && !$force) {
                return ['success' => false, 'message' => $this->di->getLocale()->_('product_import_already_in_progress')];
            }

            if (isset($queuedTaskId['success']) && !$queuedTaskId['success']) {
                return $queuedTaskId;
            }

            $origin = $data['data']['import_origin'] ?? 'source';

            $appCode = $this->di->getAppCode()->get();
            $appTag = $this->di->getAppCode()->getAppTag();
            $marketplace = $data[$origin]['marketplace'] ?? 'default';
            $appCode = $appCode[$marketplace] ?? 'default';

            $handlerData = [
                'type' => 'full_class',
                'appCode' => $appCode,

                'appTag' => $appTag,
                'class_name' => '\App\Connector\Components\Route\ProductRequestcontrol',
                'method' => 'handleImport',
                'queue_name' => $queueName,
                'own_weight' => 100,
                'user_id' => $userId,
                'data' => [
                    'operation' => 'import_products_tempdb',
                    'user_id' => $userId,
                    'app_code' => $appCode,
                    'individual_weight' => 1,
                    'feed_id' => $queuedTaskId,
                    'webhook_present' => false,
                    'temp_importing' => false,
                    'shop' => $shop,
                ],
            ];
            if (isset($shop['apps'])) {
                foreach ($shop['apps'] as $app) {
                    if (isset($app['code'], $app['webhooks']) && $app['code'] == $appCode) {
                        foreach ($app['webhooks'] as $webhooks) {
                            if (isset($webhooks['code']) && in_array($webhooks['code'], ['product_update', 'product_delete'])) {
                                $handlerData['data']['webhook_present'] = true;
                                break;
                            }
                        }

                        break;
                    }
                }
            }

            if ($marketplaceProductCount && $marketplaceProductCount['data'] > 0) {
                $handlerData['data']['total_count'] = $marketplaceProductCount['data'];
            }

            $handlerData = $this->checkForReImporting($handlerData);
            if (isset($handlerData['success']) && !$handlerData['success']) {
                return ['success' => false, 'message' => $this->di->getLocale()->_('product_import_already_in_progress')];
            }

            if (!empty($data['target']['marketplace'])) {
                $handlerData['data']['target_marketplace'] = $data['target']['marketplace'];
            }

            if (!empty($data['target']['shopId'])) {
                $handlerData['data']['target_shop_id'] = $data['target']['shopId'];
            }

            if (!empty($data['target']['data'])) {
                $handlerData['data']['target_data'] = $data['target']['data'];
            }

            if (!empty($data['source']['data'])) {
                $handlerData['data']['source_data'] = $data['source']['data'];
            }

            if (!empty($data['data'])) {
                $handlerData['data']['extra_data'] = $data['data'];
            }

            $handlerData = $sourceModel->prepareProductImportQueueData($handlerData, $shop);

            // Check If Some Error Occured in prepareProductImportQueueData
            if (isset($handlerData['success']) && !$handlerData['success']) {
                return [
                    'success' => false,
                    'message' => $handlerData['message'] ?? 'Oops! Something went wrong',
                ];
            }

            if ($marketplaceProductCount && isset($handlerData['data']['individual_weight'], $handlerData['data']['limit'], $marketplaceProductCount['data'])) {
                $individual_weight = 100 / ceil($marketplaceProductCount['data'] / $handlerData['data']['limit']);
                $handlerData['data']['individual_weight'] = round($individual_weight, 4, PHP_ROUND_HALF_UP);
            }

            return [
                'success' => true,
                'message' => ucfirst($shop['marketplace']) . ' Product Import Initiated',
                'queue_sr_no' => $this->di->getMessageManager()->pushMessage($handlerData),
            ];
        } catch (\Exception $e) {
            $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
            $this->di->getLog()->logContent($time . PHP_EOL . print_r($e->getMessage(), true), 'info', 'importException.log');
        }
    }

    /**
     * This function check if a user has already imported the products
     */
    private function checkForReImporting(array $handlerData): array
    {
        try {
            $userId = $handlerData['user_id'];
            $marketplace = $handlerData['data']['shop']['marketplace'];
            $shopId = $handlerData['data']['shop']['_id'];
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(self::PRODUCT_CONTAINER);
            $result = $collection->count([
                'user_id' => $userId,
                'source_marketplace' => $marketplace,
                'shop_id' => $shopId,
            ]);
            $handlerData['data']['isImported'] = ($result > 0) ? true : false;
            return $handlerData;
        } catch (\Exception) {
            return [
                'success' => false,
                'message' => 'Something went wrong',
            ];
        }
    }

    private function checKIfVariantDeleteQueueExists(array $shop, $userId): array
    {
        $queueData = [
            'user_id' => $userId ?? $this->di->getUser()->id,
            'process_code' => 'variants_delete',
            'marketplace' => $shop['marketplace'],
            'shop_id' => $shop['_id'],
        ];
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(self::QUEUED_TASKS);
        $result = $collection->count($queueData);
        if ($result > 0) {
            return ['success' => false, 'message' => 'Variants delete process is already under progress.'];
        }

        return ['success' => true];
    }

    public function prepareProductImportQueueData($handlerData, $shop)
    {
        return $handlerData;
    }

    public function getMarketplaceProducts($sqsData)
    {
        return [];
    }

    // Product Import code ends
    public function triggerWebhooks($data)
    {
        $data['arrival_time'] = date('c');
        if (isset($data['user_id'])) {
            $this->di->getObjectManager()->get("\App\Core\Components\Helper")->setUserToken($data['user_id']);
        } else {
            return true;
        }

        $config = $this->di->getConfig();
        if (isset($config['detailed_webhook_response']) && $config['detailed_webhook_response']) {
            $detailedWebhookRes = true;
        }

        $response = $this->initWebhook($data);
        if ($response === 2) {
            return 2;
        }
        if (isset($detailedWebhookRes) && $detailedWebhookRes) {
            return $response;
        }

        return true;
    }

    public function initWebhook($data)
    {
        $actionToPerform = $data['action'];
        $getConnectedChannels = $this->getSellerConnectedChannels($data);
        $data['app_codes'] = $this->getAppCodesForSimilarWebhook($data['user_id'], $data['shop_id'], $data['action']);
        $connectedMarketplaces = $this->getConnectedMarketplaces($data['user_id'], $data['shop_id']);
        $classNotFound = " Class not found. ";
        $logFile = 'classMethodNotFound.log';
        switch ($actionToPerform) {
            case "orders_cancelled":
                $class = '\App\Connector\Components\Order\OrderCancel';
                if (class_exists($class)) {
                    if (method_exists($class, 'cancel')) {
                        return ['connector' => $this->di->getObjectManager()->get($class)->cancel($data)];
                    }
                    $this->createLog('Class found, cancel method not found.', $logFile);
                } else {
                    $this->createLog($class . $classNotFound, $logFile);
                }

                break;

            case "refunds_create":
                $class = '\App\Connector\Components\Order\OrderRefund';
                if (class_exists($class)) {
                    if (method_exists($class, 'refund')) {
                        return ['connector' => $this->di->getObjectManager()->get($class)->refund($data)];
                    }
                    $this->createLog('Class found, refund method not found. ', $logFile);
                } else {
                    $this->createLog($class . $classNotFound, $logFile);
                }

                break;

            case "returns_approve":
                $class = '\App\Connector\Components\Order\OrderReturn';
                $data['is_webhook'] = true;
                if (class_exists($class)) {
                    if (method_exists($class, 'return')) {
                        unset($data['type']);
                        return ['connector' => $this->di->getObjectManager()->get($class)->return("accept", $data)];
                    }
                    $this->createLog('Class found, return method not found. ', $logFile);
                } else {
                    $this->createLog($class . $classNotFound, $logFile);
                }

                break;

            case "returns_decline":
                $class = '\App\Connector\Components\Order\OrderReturn';
                $data['is_webhook'] = true;
                if (class_exists($class)) {
                    if (method_exists($class, 'return')) {
                        unset($data['type']);
                        return ['connector' => $this->di->getObjectManager()->get($class)->return("reject", $data)];
                    }
                    $this->createLog('Class found, return method not found. ', $logFile);
                } else {
                    $this->createLog($class . $classNotFound, $logFile);
                }

                break;

            case "orders_create":
                $class = '\App\Connector\Components\Order\Order';
                if (class_exists($class)) {
                    if (method_exists($class, 'createWebhook')) {
                        return ['connector' => $this->di->getObjectManager()->get($class)->createWebhook($data)];
                    }
                    $this->createLog('Class found, createWebhook method not found. ', $logFile);
                } else {
                    $this->createLog($class . $classNotFound, $logFile);
                }

                break;

            case 'shop_eraser':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Core\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'ShopEraserMarking')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->ShopEraserMarking($data);
                            } else {
                                $this->createLog('Class found, ShopEraserMarking method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'app_delete':
                return [
                    'connector' => $this->di->getObjectManager()
                        ->get("\App\Connector\Components\Hook")
                        ->TemporarlyUninstall($data),
                ];
            case 'shop_redact':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Shop\Shop';
                        if (class_exists($class)) {
                            if (method_exists($class, 'eraseShopData')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->eraseShopData($data);
                            } else {
                                $this->createLog('Class found,eraseShopData method not found.  ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }
            case 'customers_data_request':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Customer\Helper';
                        if (class_exists($class)) {
                            if (method_exists($class, 'customerDataRequest')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->customerDataRequest($data);
                            } else {
                                $this->createLog('Class found,customerDataRequest method not found.  ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }
            case 'app_subscriptions_update':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Subscription\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'appSubscriptionUpdate')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->appSubscriptionUpdate($data);
                            } else {
                                $this->createLog('Class found, appSubscriptionUpdate method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'product_create':
                /* Calling Connector Common function to create product*/
                if (empty($data['app_codes'])) {
                    break;
                }

                $data = $this->di->getObjectManager()
                    ->get("\App\Connector\Components\Product\Hook")
                    ->productCreateOrUpdate($data);
                $marketplaceResponse['connector'] = $data;
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Product\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'createQueueToCreateProduct')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->createQueueToCreateProduct($data);
                            } else {
                                $this->createLog('Class found,createQueueToCreateProduct method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'product_update':
                if (empty($data['app_codes'])) {
                    break;
                }

                /* Calling Connector Common function to update product*/
                $data = $this->di->getObjectManager()
                    ->get("\App\Connector\Components\Product\Hook")
                    ->productCreateOrUpdate($data);
                $marketplaceResponse['connector'] = $data;
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Product\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'createQueueToUpdateProduct')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->createQueueToUpdateProduct($data);
                            } else {
                                $this->createLog('Class found,createQueueToUpdateProduct method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'product_delete':
                if (empty($data['app_codes'])) {
                    break;
                }

                /* Calling Connector Common function to delete product*/
                $data = $this->di->getObjectManager()
                    ->get("\App\Connector\Components\Product\Hook")
                    ->productDelete($data);

                $marketplaceResponse['connector'] = $data;
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Product\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'createQueueToDeleteProduct')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->createQueueToDeleteProduct($data);
                            } else {
                                $this->createLog('Class found, createQueueToDeleteProduct method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'product_listings_add':
                if (empty($data['app_codes'])) {
                    break;
                }

                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Product\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'createQueueToCreateProduct')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->createQueueToCreateProduct($data);
                                echo '=> ' . date('d-m-Y H:i:s');
                            } else {
                                $this->createLog('Class found,createQueueToCreateProduct method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'product_listings_update':
                if (empty($data['app_codes'])) {
                    break;
                }

                /* Calling Connector Common function to update product*/

                /*$data = $this->di->getObjectManager()
                ->get("\App\Connector\Components\Product\Hook")
                ->productCreateOrUpdate($data);*/

                if (!empty($getConnectedChannels)) {
                    $processed_data = [];
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Product\Hook';

                        if (class_exists($class)) {
                            if (method_exists($class, 'createQueueToCreateProduct')) {
                                $request = $this->di->getObjectManager()->get($class)->createQueueToCreateProduct($data);
                                $processed_data = $request;
                                $marketplaceResponse[$moduleHome] = $processed_data;
                            } else {
                                $this->createLog('Class found,createQueueToCreateProduct method not found. ', $logFile);
                            }

                            if (!empty($processed_data)) {
                                if (isset($processed_data['success'], $processed_data['data']) && $processed_data['success']) {
                                    if (method_exists($class, 'updatedDataOfWebhook')) {
                                        $response = $this->di->getObjectManager()->get($class)->updatedDataOfWebhook($processed_data['data'], $channel);
                                        array_push($marketplaceResponse[$moduleHome], $response);
                                    }
                                }
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'product_listings_remove':
                if (empty($data['app_codes'])) {
                    break;
                }

                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . ucfirst($moduleHome) . '\Components\Product\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'removeProductWebhook')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->removeProductWebhook($data);
                            } else {
                                $this->createLog('Class found,removeProductWebhook method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'locations_create':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Location\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'createQueueLocationWebhook')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->createQueueLocationWebhook($data);
                            } else {
                                $this->createLog('Class found, createQueueLocationWebhook method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'locations_update':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Location\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'updateQueueLocationWebhook')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->updateQueueLocationWebhook($data);
                            } else {
                                $this->createLog('Class found, updateQueueLocationWebhook method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'locations_delete':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Location\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'deleteQueueLocationWebhook')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->deleteQueueLocationWebhook($data);
                            } else {
                                $this->createLog('Class found, deleteQueueLocationWebhook method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'shop_update':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Shop\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'shopUpdate')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->shopUpdate($data);
                            } else {
                                $this->createLog('Class found, shopUpdate method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'inventory_levels_update':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Product\Inventory\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'createQueueToupdateProductInventory')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->createQueueToupdateProductInventory($data);
                            } else {
                                $this->createLog('Class found,createQueueToupdateProductInventory method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;
            case 'inventory_levels_connect':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Product\Inventory\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'createQueueToupdateProductInventory')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->createQueueToupdateProductInventory($data);
                            } else {
                                $this->createLog('Class found,createQueueToupdateProductInventory method not found.  ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;
            case 'inventory_levels_disconnect':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Product\Inventory\Hook';
                        if (class_exists($class)) {
                            if (method_exists($class, 'createQueueToDisconnectProductInventory')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->createQueueToDisconnectProductInventory($data);
                            } else {
                                $this->createLog('Class found,createQueueToDisconnectProductInventory method not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;
            case 'fulfillments_update':
            case 'fulfillments_create':
                $marketplace = $data['marketplace'];
                try {
                    $moduleHome = ucfirst($marketplace);
                    $class = '\App\\' . $moduleHome . '\Components\Order\Shipment';
                    $this->di->getObjectManager()->get($class);
                } catch (\Exception) {
                    $moduleHome = ucfirst($marketplace) . "home";
                    $class = '\App\\' . $moduleHome . '\Components\Order\Shipment';
                    $this->di->getObjectManager()->get($class);
                }

                if (class_exists($class)) {
                    if (method_exists($class, 'prepare')) {
                        $marketplaceResponse[$marketplace] = $this->di->getObjectManager()->get($class)->prepare($data);
                    }
                }

                if (isset($marketplaceResponse[$marketplace])) {
                    return $marketplaceResponse[$marketplace];
                }

                break;
            case 'REPORT_PROCESSING_FINISHED':
                //call function webhookGetReport in components/common/helper
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Common\Helper';
                        if (class_exists($class)) {
                            if (method_exists($class, 'webhookGetReport')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->webhookGetReport($data);
                            } else {
                                $this->createLog('Class found,Components/Common/Helper method webhookGetReport not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'FEED_PROCESSING_FINISHED':
                //call function webhookFeedSync in components/common/helper
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Common\Helper';
                        if (class_exists($class)) {
                            if (method_exists($class, 'webhookFeedSync')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->webhookFeedSync($data);
                            } else {
                                $this->createLog('Class found,Components/Common/Helper method webhookFeedSync not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'LISTINGS_ITEM_STATUS_CHANGE':
                //call function webhookProductDelete in components/common/helper
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Common\Helper';
                        if (class_exists($class)) {
                            if (method_exists($class, 'webhookProductDelete')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->webhookProductDelete($data);
                            } else {
                                $this->createLog('Class found,Components/Common/Helper method webhookProductDelete not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;
            case 'LISTINGS_ITEM_ISSUES_CHANGE':
                //call function webhookProductIssueChange in components/common/helper
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Common\Helper';
                        if (class_exists($class)) {
                            if (method_exists($class, 'webhookProductIssueChange')) {
                                $this->di->getObjectManager()->get($class)->webhookProductIssueChange($data);
                            } else {
                                $this->di->getLog()->logContent('Class found,Components/Common/Helper method webhookProductIssueChange not found. ', 'info', $logFile);
                            }
                        } else {
                            $this->di->getLog()->logContent($class . $classNotFound, 'info', $logFile);
                        }
                    }
                }

                break;
            case 'ORDER_CHANGE':
            case 'ORDER_STATUS_CHANGE':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Common\Helper';
                        if (class_exists($class)) {
                            if (method_exists($class, 'getOrderNotificationsData')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->getOrderNotificationsData($data);
                            } else {
                                $this->createLog('Class found,Components/Common/Helper method getOrderNotificationsData not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;
            case 'PRODUCT_TYPE_DEFINITIONS_CHANGE':
                if (!empty($getConnectedChannels)) {
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\' . $moduleHome . '\Components\Listings\Helper';
                        if (class_exists($class)) {
                            if (method_exists($class, 'handleProductTypeDefinitionsChangeNotifications')) {
                                $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->handleProductTypeDefinitionsChangeNotifications($data);
                            } else {
                                $this->createLog('Class found,Components/Listings/Helper method handleProductTypeDefinitionsChangeNotifications not found. ', $logFile);
                            }
                        } else {
                            $this->createLog($class . $classNotFound, $logFile);
                        }
                    }
                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }
                break;
            case 'amazon_product_upload':
                //call function serverlessResponse in components/common/helper
                if (!empty($getConnectedChannels)) {
                    return $this->handleInternalWebhooks($getConnectedChannels, $data);
                }

                break;

            case 'amazon_product_update':
                //call function serverlessResponse in components/common/helper
                if (!empty($getConnectedChannels)) {
                    return $this->handleInternalWebhooks($getConnectedChannels, $data);
                }

                break;

            case 'amazon_inventory_sync':
                //call function serverlessResponse in components/common/helper
                if (!empty($getConnectedChannels)) {
                    return $this->handleInternalWebhooks($getConnectedChannels, $data);
                }

                break;

            case 'amazon_price_sync':
                //call function serverlessResponse in components/common/helper
                if (!empty($getConnectedChannels)) {
                    return $this->handleInternalWebhooks($getConnectedChannels, $data);
                }

                break;

            case 'amazon_image_sync':
                //call function serverlessResponse in components/common/helper
                if (!empty($getConnectedChannels)) {
                    return $this->handleInternalWebhooks($getConnectedChannels, $data);
                }

                break;

            case 'amazon_product_delete':
                //call function serverlessResponse in components/common/helper
                if (!empty($getConnectedChannels)) {
                    return $this->handleInternalWebhooks($getConnectedChannels, $data);
                }

                break;

            case 'amazon_shipment_sync':
                //call function serverlessResponse in components/common/helper
                if (!empty($getConnectedChannels)) {
                    return $this->handleInternalWebhooks($getConnectedChannels, $data);
                }

                break;

            case 'amazon_acknowledgement_sync':
                //call function serverlessResponse in components/common/helper
                if (!empty($getConnectedChannels)) {
                    $this->handleInternalWebhooks($getConnectedChannels, $data);
                }

                break;

            case 'inventory_adjustment_add_edit':
                $source = $data['marketplace'];
                $sourceModule = ucfirst($source) . 'home';
                $response = $this->di->getObjectManager()->get('\App\\' . $sourceModule . '\Components\Product\Inventory\Hook')->createQueueToupdateProductInventory($data);
                return [$source => $response];
            case 'woocommerce_product_created':
                $source = $data['marketplace'];
                $sourceModule = ucfirst($source) . 'home';
                $response = $this->di->getObjectManager()
                    ->get("\App\\" . $sourceModule . "\Components\Product\Product")
                    ->webhookManageCreate($data);
                return [$source => $response];
            case 'woocommerce_product_deleted':
                $source = $data['marketplace'];
                $sourceModule = ucfirst($source) . 'home';
                $response = $this->di->getObjectManager()
                    ->get("\App\\" . $sourceModule . "\Components\Product\Product")
                    ->webhookManageDelete($data);
                $this->di->getLog()->logContent('wooommerce_product.deleted init ' . print_r($response, true), 'info', 'webhook/wooommerce_product.deleted.log');
                return [$source => $response];
            case 'woocommerce_product_update':
                $source = $data['marketplace'];
                $sourceModule = ucfirst($source) . 'home';
                $response = $this->di->getObjectManager()
                    ->get("\App\\" . $sourceModule . "\Components\Product\Product")
                    ->webhookManageUpdate($data);
                $this->di->getLog()->logContent('wooommerce_product.updated init ' . print_r($response, true), 'info', 'webhook/wooommerce_product.updated.log');
                return [$source => $response];
            case 'woocommerce_orders_created':
                $source = $data['marketplace'];
                $sourceModule = ucfirst($source) . 'home';
                $response = $this->di->getObjectManager()->get('\App\\' . $sourceModule . '\Components\Order\OrderCreate')->createOrder($data);
                $this->di->getLog()->logContent('woocommerce order create init ' . print_r($response, true), 'info', 'webhook/wooommerce_order.created.log');
                return [$source => $response];
            case 'woocommerce_orders_updated':
                $source = $data['marketplace'];
                $sourceModule = ucfirst($source) . 'home';
                $response = $this->di->getObjectManager()->get('\App\\' . $sourceModule . '\Components\Order\Order')->webhookManageUpdate($data);
                $this->di->getLog()->logContent('woocommerce order create init ' . print_r($response, true), 'info', 'webhook/wooommerce_order.udpated.log');
                return [$source => $response];
            case 'magento_order_create':
                $source = $data['data']['source']['marketplace']; // magento marketplace
                $sourceModule = ucfirst($source) . 'home';
                $response = $this->di->getObjectManager()->get('\App\\' . $sourceModule . '\Components\Order\OrderCreate')->createOrder($data);
                $this->di->getLog()->logContent('magento order create init ' . print_r($response, true), 'info', 'webhook/magento_order.created.log');
                return [$source => $response];
            case 're_register_webhooks':
                $this->reRegisterWebhooks($data);
                break;
            case 'order_create':

                $marketplace = $data['marketplace'];
                try {
                    $moduleHome = ucfirst($marketplace);
                    $moduleObject = $this->di->getObjectManager()->get('\App\\' . $moduleHome . '\Components\Order\Hook');
                } catch (\Exception) {
                    $moduleHome = ucfirst($marketplace) . "home";
                    $moduleObject = $this->di->getObjectManager()->get('\App\\' . $moduleHome . '\Components\Order\Hook');
                }

                $class = '\App\\' . $moduleHome . '\Components\Order\Hook';
                if (class_exists($class)) {
                    if (method_exists($class, 'createOrderOnSource')) {
                        $sourceResponse = $moduleObject->createOrderOnSource($data);
                        $marketplaceResponse[$moduleHome] = $sourceResponse;
                        if (!empty($connectedMarketplaces)) {
                            foreach ($connectedMarketplaces as $marketplace) {
                                try {
                                    $moduleHome = ucfirst($marketplace);
                                    $moduleObject = $this->di->getObjectManager()->get('\App\\' . $moduleHome . '\Components\Order\Hook');
                                } catch (\Exception) {
                                    $moduleHome = ucfirst($marketplace) . "home";
                                    $moduleObject = $this->di->getObjectManager()->get('\App\\' . $moduleHome . '\Components\Order\Hook');
                                }

                                $class = '\App\\' . $moduleHome . '\Components\Order\Hook';
                                if (class_exists($class)) {
                                    if (method_exists($class, 'createOrder')) {
                                        $marketplaceResponse[$moduleHome] = $moduleObject->createOrder($sourceResponse);
                                    } else {
                                        $this->createLog('Class found,create method not found. ', $logFile);
                                    }
                                } else {
                                    $this->createLog($class . $classNotFound, $logFile);
                                }
                            }
                        }

                        if (isset($marketplaceResponse)) {
                            return $marketplaceResponse;
                        }
                    } else {
                        $this->createLog('Class found, createOrderOnSource method not found. ', $logFile);
                    }
                } else {
                    $this->createLog($class . $classNotFound, $logFile);
                }

                break;
            case 'salesorder_add_edit':
                $source = $data['marketplace'];
                $sourceModule = ucfirst($source) . 'home';
                $class = '\App\\' . $sourceModule . '\Components\Order\Shipment';
                if (isset($data['data']['salesorder']['status']) && ($data['data']['salesorder']['status'] == 'shipped' || $data['data']['salesorder']['status'] == 'fulfilled')) {
                    if (class_exists($class)) {
                        if (method_exists($class, 'prepare')) {
                            $marketplaceResponse[$source] = $this->di->getObjectManager()->get($class)->prepare($data);
                        } else {
                            $this->createLog('Class found, createOrderOnSource method not found. ', $logFile);
                        }
                    } else {
                        $this->createLog($class . $classNotFound, $logFile);
                    }
                }

                $cancelClass = '\App\\' . $sourceModule . '\Components\Order\CancelOrder';
                if (isset($data['data']['salesorder']['status']) && $data['data']['salesorder']['status'] == 'void') {
                    if (class_exists($cancelClass)) {
                        if (method_exists($cancelClass, 'prepareCancel')) {
                            $marketplaceResponse[$source] = $this->di->getObjectManager()->get($cancelClass)->prepareCancel($data);
                        } else {
                            $this->createLog('Class found, createOrderOnSource method not found. ', $logFile);
                        }
                    } else {
                        $this->createLog($cancelClass . $classNotFound, $logFile);
                    }
                }

                if (isset($marketplaceResponse)) {
                    return $marketplaceResponse;
                }

                break;

            case 'purchase_order':
                $source = $data['marketplace'];
                $sourceModule = ucfirst($source) . 'home';
                $class = '\App\\' . $sourceModule . '\Components\Order\PurchaseOrder';
                if (isset($data['data']['purchaseorder']['order_status']) && ($data['data']['purchaseorder']['order_status'] == 'closed')) {
                    if (class_exists($class)) {
                        if (method_exists($class, 'preparePurchaseOrderInventory')) {
                            $marketplaceResponse[$source] = $this->di->getObjectManager()->get($class)->preparePurchaseOrderInventory($data);
                        } else {
                            $this->createLog('Class found, createOrderOnSource method not found. ', $logFile);
                        }
                    } else {
                        $this->createLog($class . $classNotFound, $logFile);
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;

            case 'item_add_edit':
                $source = $data['marketplace'];
                $sourceModule = ucfirst($source) . 'home';
                $class = '\App\\' . $sourceModule . '\Components\Product\Route\Requestcontrol';
                if (class_exists($class)) {
                    if (method_exists($class, 'processItemWebhook')) {
                        $marketplaceResponse[$source] = $this->di->getObjectManager()->get($class)->processItemWebhook($data);
                    }

                    if (isset($marketplaceResponse)) {
                        return $marketplaceResponse;
                    }
                }

                break;
        }

        return true;
    }

    public function handleInternalWebhooks($getConnectedChannels, $data)
    {
        foreach ($getConnectedChannels as $moduleHome => $channel) {
            $class = '\App\\' . $moduleHome . '\Components\Common\Helper';
            if (class_exists($class)) {
                if (method_exists($class, 'serverlessResponse')) {
                    $marketplaceResponse[$moduleHome] = $this->di->getObjectManager()->get($class)->serverlessResponse($data);
                } else {
                    $this->di->getLog()->logContent('Class found,Components/Common/Helper method serverlessResponse not found. ' . json_encode($data), 'info', 'MethodNotFound.log');
                }
            } else {
                $this->di->getLog()->logContent($class . ' Class not found. ' . json_encode($data), 'info', 'ClassNotFound.log');
            }
        }

        if (isset($marketplaceResponse)) {
            return $marketplaceResponse;
        }
    }

    public function handleShipment($data)
    {
        //mold data format start
        // $mold_data = [
        //     "source_shop_id" => '',
        //     'target_shop_id'=>'',
        //     'target_marketplace'=>'',
        //     "marketplace_order_id" => '', //shopify oid
        //     "marketplace_shipment_id" =>  '',//
        //     "user_id" => '',
        //     "items"  => [
        //         [
        //         "type" => '', // virtual | real ?
        //         "title" => '',
        //         "sku" => '',
        //         "shipped_qty" => '',
        //         "weight" => ''
        //         ]
        //     ],
        //     "shipping_status" =>  '',
        //     "shipping_method" => [
        //         "code" => '',
        //         "title" => '',
        //         "carrier" => ''
        //     ],
        //     "tracking" => [
        //         [
        //             "company" =>  '',
        //             "number" => '',
        //             "name" => '',
        //             "url" => ''
        //         ]
        //         ],
        //     "shipment_created_at" => '',
        //     "shipment_updated_at" => ''

        // ];
        // mold data format ends

        $code = strtolower($data['code']);
        $pos = strpos($code, "home");
        $module = substr($code, 0, $pos);
        $sourceShipmentService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\ShipInterface::class, [], $module);
        $moldData = $sourceShipmentService->mold($data);
        $connectorShipmentService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\ShipInterface::class, []);
        $savedData = $connectorShipmentService->ship($moldData);
        $targetShipmentService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\ShipInterface::class, [], $savedData['target_marketplace']);
        $targetShipmentService->ship($savedData);
        return ['success' => true, 'message' => 'Shipment Initiated'];
    }

    public function getSellerConnectedChannels($data)
    {
        $returnDetails = [];
        $shops = $this->di->getUser()->shops;
        foreach ($shops as $shop) {
            if (is_array($shop)) {
                try {
                    $moduleHome = ucfirst($shop['marketplace']);
                    $class = '\App\\' . $moduleHome . '\Models\SourceModel';
                    $this->di->getObjectManager()->get($class);
                    $returnDetails[$moduleHome] = $shop;
                } catch (\Exception) {
                    $moduleHome = ucfirst($shop['marketplace']) . "home";
                    $class = '\App\\' . $moduleHome . '\Models\SourceModel';
                    $this->di->getObjectManager()->get($class);
                    $returnDetails[$moduleHome] = $shop;
                }
            }
        }

        return $returnDetails;
    }

    public function saveShipment($data): void
    {
        if (!empty($data['data']['fulfillments'])) {
            $targetOrderId = $data['data']['id'];
            $connectorOrderService = $this->di->getObjectManager()->get(\App\Connector\Service\Order::class, []);
            $params = ['targets.order_id' => (string) $targetOrderId, 'object_type' => 'source_order'];
            $order = $connectorOrderService->getByField($params);
            if (count($order) == 1) {
                $order = $order[0];
            }

            if ($order && !empty($order) && isset($order['_id'])) {
                $data['target_shop_id'] = $order['marketplace_shop_id'];
                foreach ($order['targets'] as $value) {
                    if ($value['marketplace'] == "shopify") {
                        $data['source_shop_id'] = $value['shop_id'];
                        break;
                    }
                }

                $data['order'] = $order;
                $connectorShipmentService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\ShipInterface::class, []);
                $connectorShipmentService->ship($data);
            }
        }
    }

    public function checkCurrency($userId, $targetMarketplace, $sourceMarketplace, $targetMarketplaceShopId = false)
    {

        $sourceMarketplaceCurrency = false;
        $targetMarketplaceCurrency = false;

        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shops = $userDetails->getUserDetailsByKey('shops', $userId);
        foreach ($shops as $shop) {
            $currency = $shop['currency'] ?? "";
            if ($currency) {
                if ($shop['marketplace'] == $sourceMarketplace) {
                    $sourceMarketplaceCurrency = $currency;
                } else {
                    if ($targetMarketplaceShopId) {
                        if ($shop['marketplace'] == $targetMarketplace && $targetMarketplaceShopId == $shop['_id']) {
                            $targetMarketplaceCurrency = $shop['currency'];
                        }
                    } else {
                        if ($shop['marketplace'] == $targetMarketplace) {
                            $targetMarketplaceCurrency = $shop['currency'];
                        }
                    }
                }
            }
        }

        return ($sourceMarketplaceCurrency === $targetMarketplaceCurrency);
    }

    public function checkCurrencyWithCache($userId, $targetMarketplace, $sourceMarketplace, $targetMarketplaceShopId = false, $sourceMarketplaceShopId = false, $uniqueKey = false)
    {

        $sourceMarketplaceCurrency = false;
        $targetMarketplaceCurrency = false;
        if ($uniqueKey) {
            $cacheData = $this->di->getCache()->get((string)$uniqueKey);

            if ($cacheData && isset($cacheData['sourceMarketplaceCurrency']) && isset($cacheData['targetMarketplaceCurrency'])) {
                return $cacheData['sourceMarketplaceCurrency'] === $cacheData['targetMarketplaceCurrency'];
            }
        }

        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shops = $userDetails->getUserDetailsByKey('shops', $userId);
        foreach ($shops as $shop) {
            $currency = $shop['currency'] ?? "";
            if ($currency) {
                if ($shop['marketplace'] == $sourceMarketplace) {
                    $sourceMarketplaceCurrency = $currency;
                } else {
                    if ($targetMarketplaceShopId) {
                        if ($shop['marketplace'] == $targetMarketplace && $targetMarketplaceShopId == $shop['_id']) {
                            $targetMarketplaceCurrency = $shop['currency'];
                        }
                    } else {
                        if ($shop['marketplace'] == $targetMarketplace) {
                            $targetMarketplaceCurrency = $shop['currency'];
                        }
                    }
                }
            }
        }
        $uniqueKeyForCache = $userId . 'target_id' . $targetMarketplaceShopId . 'source_id' . $sourceMarketplaceShopId;
        $cacheData['sourceMarketplaceCurrency'] = $sourceMarketplaceCurrency;
        $cacheData['targetMarketplaceCurrency'] = $targetMarketplaceCurrency;
        $this->di->getCache()->set((string)$uniqueKeyForCache, $cacheData);

        return ($sourceMarketplaceCurrency === $targetMarketplaceCurrency);
    }

    /**
     * @param $data
     * @return array
     */
    public function uploadProducts($data)
    {
        $userId = isset($data['user_id']) ? $data['user_id'] : $this->di->getUser()->id;
        if (!$userId) {
            return ['success' => false, 'message' => 'Invalid User Id'];
        }

        if (!isset($data['target']['marketplace']) || !isset($data['source']['marketplace']) || !isset($data['target']['shopId']) || !isset($data['source']['shopId'])) {
            return ['sucess' => false, 'message' => 'Invalid request'];
        }

        $targetShopIds = $data['target']['shopId'];
        $changeCurrency = $this->checkCurrency($userId, $data['target']['marketplace'], $data['source']['marketplace'], $targetShopIds);

        if (!($changeCurrency)) {
            return ['status' => false, 'message' => $this->di->getLocale()->_('unable_to_update_due_currency', [
                'sourceMarketplace' => ucfirst($data['source']['marketplace']),
                'targetMarketplace' => ucfirst($data['target']['marketplace']),
            ])];
        }

        $targetSourceModel = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($data['target']['marketplace'])->get('source_model'));
        if (count($data['source_product_ids']) <= self::COUNT) {
            // directily prepare the data;

        } else {
            // prepare and push to queue;
        }

        if (method_exists($targetSourceModel, 'productUpload')) {
            $responcefromTargetSourceModel = $targetSourceModel->productUpload($data);
            if (isset($responcefromTargetSourceModel['success']) && $responcefromTargetSourceModel['success']) {
                return ['success' => true, 'message' => $responcefromTargetSourceModel['message']];
            }
            if (isset($responcefromTargetSourceModel['success']) && !$responcefromTargetSourceModel['success']) {
                return ['success' => false, 'message' => $responcefromTargetSourceModel['message']];
            } else {
                $success_msg = '';
                $error_msg = '';
                foreach ($responcefromTargetSourceModel as $response) {
                    if (isset($response['success']) && $response['success']) {
                        $success_msg .= $response['message'];
                    } elseif (isset($response['success']) && !$response['success']) {
                        $error_msg .= $response['message'];
                    }
                }

                if ($success_msg) {
                    return ['success' => true, 'message' => $success_msg];
                }
                if ($error_msg) {
                    return ['success' => false, 'message' => $error_msg];
                } else {
                    return ['success' => false, 'message' => 'Error while uploading your products . please contact us'];
                }
            }
        }
        return ['success' => false, 'message' => $this->di->getLocale()->_('upload_method_not_exist', [
            'targetMarketplace' => ucfirst($data['target_marketplace']['marketplace']),
        ])];
    }

    public function disconnectAccount($data, $userId = false)
    {
        $isCustomDisconnect = true;
        $eventsManager = $this->di->getEventsManager();

        if (isset($data['target_marketplace']['shop_id']) && isset($data['target_marketplace']['marketplace'])) {
            if (!$userId) {
                $userId = $this->di->getUser()->id;
            }

            $shopId = $data['target_marketplace']['shop_id'];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollection(self::USER_DETAILS_CONTAINER);
            $shopData = [];
            $userDetails = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
            $shopData = $userDetails->getShop($shopId, $userId);

            $targetMarketplace = $data['target_marketplace']['marketplace'];
            $sourceMarketplace = $data['source_marketplace']['marketplace'];
            $remoteShopId = $shopData['remote_shop_id'];
            $eventResponse = ['success' => true, 'message' => 'Disconnect Process by Marketplace in Progress.'];
            $eventsManager->fire('application:beforeDisconnect', $this, ['custom_data' => &$shopData, 'isCustomDisconnect' => &$isCustomDisconnect, 'response' => &$eventResponse]);

            if ($isCustomDisconnect) {
                //for deletion of product
                $productContainer = $mongo->getCollection(self::PRODUCT_CONTAINER);
                $response = $productContainer->deleteMany([
                    'user_id' => $userId,
                    "target_marketplace" => $data['target_marketplace']['marketplace'],
                    'shop_id' => $data['target_marketplace']['shop_id'],
                ], ['w' => true]);

                //report_Contatiner
                $reportContainer = $mongo->getCollection(self::REPORT_CONTAINER);
                $reportContainerResponse = $reportContainer->deleteMany([
                    'user_id' => $userId,
                    'shop_id' => $data['target_marketplace']['shop_id'],
                ], ['w' => true]);
                //queuid_task
                $queuedTask = $mongo->getCollection(self::QUEUED_TASKS);
                $aggregate = [];

                $queuedTaskResponse = $queuedTask->deleteMany([
                    'user_id' => $userId,
                    'shop_id' => $data['target_marketplace']['shop_id'],
                ], ['w' => true]);

                //user_details
                $result = $collection->UpdateOne(
                    ['user_id' => (string) $userId],
                    ['$pull' => ["shops" => ['_id' => $shopId]]]
                );
                //remote shop
                $appCode = $this->di->getAppCode()->get();
                if (isset($appCode[$sourceMarketplace])) {
                    $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init($targetMarketplace, false, $appCode[$targetMarketplace])
                        ->call('app-shop', [], ['shop_id' => $remoteShopId], 'DELETE');
                }

                if (isset($shopId) && !empty($shopData)) {
                    return ['success' => true, "message" => "Account deleted successfully"];
                }
                return ['success' => false, "message" => "Shop not found"];
            }
            return $eventResponse;
        }
    }

    public function getAllConnectedAcccounts($userId = false, $data = [])
    {

        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');

        if (!($shops = $this->getUserCache('shops', $userId))) {
            $shops = $userDetails->getUserDetailsByKey('shops', $userId);
            $this->setUserCache('shops', $shops, $userId);
        }

        $shops = $shops ? $shops : [];
        $responcefromMarketplaceShopSourceModel = [];
        foreach ($shops as $key => $shop) {
            # call the sourcemodel of the shop
            $marketplaceShopSourceModel = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($shop['marketplace'])->get('source_model'));

            if (method_exists($marketplaceShopSourceModel, 'prepareConnectedShopData')) {
                $responcefromMarketplaceShopSourceModel[$key] = $marketplaceShopSourceModel->prepareConnectedShopData($shop, $data);
            }
        }

        return ['success' => true, 'data' => $responcefromMarketplaceShopSourceModel];
    }

    public function saveSellerName($data = [])
    {
        $targetId = $this->di->getRequester()->getTargetId();
        $userDetails = $this->di->getObjectManager()->get('App\Core\Models\User\Details');
        $userId = $this->di->getUser()->getId();
        $allShops = $userDetails->getAllConnectedShops($userId, $this->di->getRequester()->getTargetName());
        $shopData = $userDetails->getShop($targetId);
        if (isset($data['sellerName'])) {
            $unique = true;
            foreach ($allShops as $value) {
                if ($value['_id'] !== $targetId && $value['sellerName'] == $data['sellerName']) {
                    $unique = false;
                    break;
                }
            }

            if (!$unique) {
                return ['success' => false, 'message' => 'Seller Name already exists please choose unique seller name'];
            }

            $shopData['sellerName'] = $data['sellerName'];
        } else {
            return ['success' => false, 'message' => 'No seller name found'];
        }

        $shopRes = $userDetails->addShop($shopData, false, ["_id"]);
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:saveSellerName', $this, ['shopData' => $shopData]);
        return $shopRes;
    }

    public function saveSellerStatus($data)
    {
        $dataToConnector = [];
        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get('\App\Core\Models\Config\Config');
        $configObj->setUserId();
        $dataToConnector['key'] = 'sellerStatus';
        $dataToConnector['value'] = $data['sellerStatus'];
        $dataToConnector['group_code'] = 'onboarding';
        if (isset($data['app_tag'])) {
            $dataToConnector['app_tag'] = $data['app_tag'];
        } else {
            $dataToConnector['app_tag'] = $this->di->getAppCode()->getAppTag();
        }

        if (isset($data['target']['marketplace'])) {
            $dataToConnector['target'] = $data['target']['marketplace'];
            $dataToConnector['target_shop_id'] = $data['target']['shopId'];
        }

        if (isset($data['source']['marketplace'])) {
            $dataToConnector['source'] = $data['source']['marketplace'];
            $dataToConnector['source_shop_id'] = $data['source']['shopId'];
        }

        $res = $configObj->setConfig([$dataToConnector]);
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:userOnboarded', $this, [
            'username' => $this->di->getUser()->username,
            'name' => $this->di->getUser()->name,
            'email' => $this->di->getUser()->email,
            'shops' => json_decode(json_encode($this->di->getUser()->shops), true),
            'data' => $data,
        ]);
        return ['success' => true, 'data' => $res];
    }

    public function saveSettingPrefrences($data)
    {
        $res = false;
        $dataToConnectorConfig = [];
        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get('\App\Core\Models\Config\Config');
        $settingsPreferences = $data['settings_preferences'];

        if (isset($data['target']['marketplace']) && isset($data['target']['shopId'])) {
            $targetmarketplace = $data['target']['marketplace'];
        } else {
            return ['success' => false, 'message' => 'target shopId or not found'];
        }

        if (!(isset($data['source']['marketplace']) && isset($data['source']['shopId']))) {
            return ['success' => false, 'message' => 'source shopId or marketplace not found'];
        }

        $sourceModel = $this->di->getObjectManager()->get($this->di->getConfig()->connectors
            ->get($targetmarketplace)->get('source_model'));

        foreach ($settingsPreferences as $confKey => $value) {
            if (method_exists($sourceModel, 'prepareConfigData')) {
                $preparedConfigData = $sourceModel->prepareConfigData($confKey, $value, $data['source']['shopId']);
            } else {
                return ['success' => false, 'message' => 'update the setting prefrences'];
            }

            foreach ($preparedConfigData as $valueofconfig) {
                $configObj->setUserId();
                $dataToConnectorConfig['key'] = $valueofconfig['key'];
                $dataToConnectorConfig['value'] = $valueofconfig['value'];
                $dataToConnectorConfig['group_code'] = $valueofconfig['group_code'];
                if (isset($data['app_tag'])) {
                    $dataToConnectorConfig['app_tag'] = $data['app_tag'];
                } else {
                    $dataToConnectorConfig['app_tag'] = $this->di->getAppCode()->getAppTag();
                }

                $dataToConnectorConfig['target'] = $data['target']['marketplace'];
                $dataToConnectorConfig['target_shop_id'] = $data['target']['shopId'];

                $dataToConnectorConfig['source'] = $data['source']['marketplace'];
                $dataToConnectorConfig['source_shop_id'] = $data['source']['shopId'];

                $res = $configObj->setConfig([$dataToConnectorConfig]);
            }
        }

        return $res;
    }

    public function routeUnregisterWebhooks($shop, $marketplace, $appCode, $appUninstalled = false, $webhookCodes = [])
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
            'class_name' => '\App\Connector\Models\SourceModel',
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
        ];

        $subscriptionIds = array();
        if (isset($shop['apps'])) {
            $apps = $shop['apps'];
            $webhookIdUsedByNumberOfApps = [];
            foreach ($apps as $app) {
                if (isset($app['webhooks'])) {
                    foreach ($app['webhooks'] as $hook) {
                        if (!isset($hook['dynamo_webhook_id'])) {
                            continue;
                        }
                        if (isset($webhookIdUsedByNumberOfApps[$hook['dynamo_webhook_id']])) {
                            $webhookIdUsedByNumberOfApps[$hook['dynamo_webhook_id']] += 1;
                        } else {
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
                                if ($appUninstalled || ($webhook['code'] != 'app_delete' && $webhook['code'] != 'shop_update')) {
                                    $subscriptionIds[] = $webhook['dynamo_webhook_id'];
                                }
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
                                if (in_array($webhook['code'], $webhookCodes)) {
                                    $subscriptionIds[] = $webhook['dynamo_webhook_id'];
                                }
                            }

                            break;
                        }
                    }
                }
            }
        }

        if ($subscriptionIds) {
            $handlerData['data']['subscription_ids'] = $subscriptionIds;
        }

        if ($webhookIdUsedByNumberOfApps) {
            $handlerData['data']['webhookIdUsedByNumberOfApps'] = $webhookIdUsedByNumberOfApps;
        }

        if ($appUninstalled) {
            if (!empty($webhookIdUsedByNumberOfApps) && !empty($subscriptionIds)) {

                if (isset($shop['apps'])) {
                    foreach ($apps as $app) {
                        if ($app['code'] == $appCode) {
                            if (isset($app['webhooks'])) {
                                foreach ($app['webhooks'] as $webhook) {
                                    if (in_array($webhook['code'], $webhookCodes)) {
                                        $subscriptionIds[] = $webhook['dynamo_webhook_id'];
                                    }
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
        $maxUnregisterLimit = 5; // no of webhooks unregister in one process
        $cursor = $sqsData['data']['cursor'];
        $subscriptionIds = isset($sqsData['data']['subscription_ids']) ? $sqsData['data']['subscription_ids'] : [];

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
        return true;
    }

    public function removeWebhook($sqsData, $subscriptionId): void
    {
        $userId = $sqsData['data']['user_id'];
        $shop = $sqsData['data']['shop'];
        $appCode = $sqsData['data']['appCode'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $user = $mongo->getCollectionForTable("user_details");
        $webhookIdUsedByNumberOfApps = $sqsData['data']['webhookIdUsedByNumberOfApps'];
        if (isset($webhookIdUsedByNumberOfApps[$subscriptionId]) && $webhookIdUsedByNumberOfApps[$subscriptionId] > 1) {
            $this->di->getLog()->logContent(date("Y-m-d H:i:s") . " Inside removeWebhook function : This webhook is used by number of apps, id:  " . print_r($subscriptionId, true) . "So removing it from db only.", "info", "webhook" . DS . $sqsData['user_id'] . "_" . $sqsData['shop_id'] . "_" . $sqsData['app_code'] . DS . "unregister_webhook" . DS . date("Y-m-d") . DS . "webhook.log");
            $removeFromUserdetail = true;
        } else {
            $remoteData = ['shop_id' => $shop['remote_shop_id'], 'subscription_id' => $subscriptionId, 'app_code' => "cedcommerce"];
            if (isset($sqsData['app_uninstalled']) && $sqsData['app_uninstalled']) {
                $remoteData['app_uninstalled'] = $sqsData['app_uninstalled'];
            }

            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('cedcommerce', true)
                ->call('event/subscription', [], $remoteData, 'DELETE');
        }

        try {
            if (isset($remoteResponse['success']) && $remoteResponse['success'] || (isset($removeFromUserdetail) && $removeFromUserdetail)) {
                $res = $user->updateOne(
                    [
                        "_id" => new \MongoDB\BSON\ObjectId($userId),
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
        } catch (\Exception $e) {
            $this->di->getLog()->logContent(json_encode([
                'method' => __METHOD__,
                'line' => __LINE__,
                'response' => $remoteResponse,
                'message' => $e->getMessage(),
            ]), "info", "webhook" . DS . $sqsData['user_id'] . "_" . $sqsData['shop_id'] . "_" . $sqsData['app_code'] . DS . "unregister_webhook" . DS . date("Y-m-d") . DS . "remote_calls.log");
            print_r($e->getMessage());
        }
    }

    public function oldUnregisterWebhooks($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $user = $mongo->getCollectionForTable("user_details");

        $responseWebhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($data['marketplace'], 'true', $data['app_code'])
            ->call("/webhook/unregister", [], ['shop_id' => $data['remote_shop_id']], 'DELETE');
        if ($responseWebhook['success']) {
            $res = $user->updateOne(
                [
                    "_id" => new \MongoDB\BSON\ObjectId($data['user_id']),
                ],
                [
                    '$set' => [
                        'shops.$[shop].apps.$[app].webhooks' => [],
                    ],
                ],
                [
                    'arrayFilters' => [
                        [
                            'shop.remote_shop_id' => $data['remote_shop_id'],
                        ],
                        [
                            'app.code' => $data['app_code'],
                        ],
                    ],
                ]

            );

            if ($res->getModifiedCount()) {
                return ['success' => 'true', 'message' => 'All webhooks unregistered successfully!', 'data' => $responseWebhook['data']];
            }

            return ['success' => false, 'message' => 'Trouble removing webhooks from user details!'];
        }

        return ['success' => false, 'message' => 'Trouble unregister webhooks!'];
    }

    public function removeAppWiseUnusedWebhooks($sqsData): void
    {
        $userId = $this->di->getUser()->id;
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array'], 'projection' => ['shops.$' => 1, '_id' => 0]];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $appCode = $sqsData['data']['appCode'];
        $shopId = $sqsData['data']['shop']['_id'];
        $marketplace = $sqsData['data']['marketplace'];
        $remoteShopId = $sqsData['data']['remote_shop_id'];
        $userDetails = $collection->findOne(['user_id' => $userId, 'shops.apps.code' => $appCode, 'shops._id' => $shopId], $options);
        $shop = $userDetails['shops'][0];
        if (isset($shop) && $shop != null) {
            $webhookIdUsedByNumberOfApps = [];
            $webhookIdNotUsedByApp = [];
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($marketplace, true, $appCode)
                ->call('/appsWebhooks', [], ['shop_id' => $remoteShopId], 'GET');
            foreach ($remoteResponse['data'] as $webhook) {
                $appWebhook[$webhook['code']] = 1;
            }

            if (isset($shop['apps'])) {
                foreach ($shop['apps'] as $app) {
                    if (isset($app['webhooks'])) {
                        foreach ($app['webhooks'] as $hook) {
                            if (isset($webhookIdUsedByNumberOfApps[$hook['dynamo_webhook_id']])) {
                                $webhookIdUsedByNumberOfApps[$hook['dynamo_webhook_id']] += 1;
                            } else {
                                $webhookIdUsedByNumberOfApps[$hook['dynamo_webhook_id']] = 1;
                            }
                        }
                    }

                    if (isset($app['code']) && $app['code'] == $appCode && isset($app['webhooks'])) {
                        foreach ($app['webhooks'] as $hook) {
                            if (!isset($appWebhook[$hook['code']])) {
                                $webhookIdNotUsedByApp[] = $hook['dynamo_webhook_id'];
                            }
                        }
                    }
                }
            }

            $sqsData['method'] = 'unregisterWebhooks';
            $sqsData['data']['cursor'] = 0;
            if ($webhookIdNotUsedByApp) {
                $sqsData['data']['subscription_ids'] = $webhookIdNotUsedByApp;
            }

            if ($webhookIdUsedByNumberOfApps) {
                $sqsData['data']['webhookIdUsedByNumberOfApps'] = $webhookIdUsedByNumberOfApps;
            }

            $this->di->getMessageManager()->pushMessage($sqsData);
        }
    }

    /**
     * Fucntion checks how many apps use a particular webhook
     *
     * @param $shopId
     * @param $code
     * @param $userId
     * @return array of appCodes
     */
    public function getAppCodesForSimilarWebhook($userId, $shopId, $code)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection = $mongo->getCollectionForTable('user_details');
        $appCodes = [];
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

        $query = [
            ['$match' => ['user_id' => $userId, 'shops._id' => $shopId]],
            ['$unwind' => '$shops'],
            ['$unwind' => '$shops.apps'],
            ['$unwind' => '$shops.apps.webhooks'],
            ['$match' => ['shops.apps.webhooks.code' => $code]],
            ['$project' => ['shops.apps.code' => 1, 'shops.apps.app_status' => 1, '_id' => 0]],
        ];

        $userShop = $this->di->getUser()->shops ?? array();

        if (empty($userShop)) {
            $userShop = $mongoCollection->aggregate($query, $options)->toArray();
            if (isset($userShop) && count($userShop) > 0) {
                foreach ($userShop as $shop) {
                    $code = $shop['shops']['apps']['code'];
                    if ($shop['shops']['apps']['app_status'] == 'active') {
                        $appCodes[$code] = $code;
                    }
                }
            }
        } else {
            foreach ($userShop as $shop) {
                if ($shop['_id'] == $shopId) {
                    foreach ($shop['apps'] as $app) {
                        if (isset($app['webhooks'])) {
                            foreach ($app['webhooks'] as $webhook) {
                                if ($webhook['code'] == $code && $app['app_status'] == 'active') {
                                    $appCodes[$app['code']] = $app['code'];
                                }
                            }
                        }
                    }
                }
            }
        }

        $appCodes = array_values($appCodes);

        return $appCodes;
    }

    public function updateShop($shop, $state): void
    {
        $userDetails = $this->di->getObjectManager()->get('App\Core\Models\User\Details');
        $sourceModel = $this->di->getObjectManager()->get($this->di->getConfig()->connectors
            ->get($shop['marketplace'])->get('source_model'));
        if (method_exists($sourceModel, 'getUpdatedShopData')) {
            $updatedShopResponse = $sourceModel->getUpdatedShopData($shop, $state);
            if (isset($updatedShopResponse['success'], $updatedShopResponse['shop']) && $updatedShopResponse['success']) {
                $userDetails->addShop($updatedShopResponse['shop'], false, ["remote_shop_id"]);
            }
        }
    }

    public function reRegisterWebhooks($sqsData)
    {
        $userId = isset($sqsData['user_id']) ? $sqsData['user_id'] : $this->di->getUser()->id;
        $shopId = $sqsData['shop_id'] ?? "";
        $marketplace = $sqsData['marketplace'] ?? "";
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shop = $userDetails->getShop($shopId, $userId);
        $apps = $shop['apps'];
        $webhookIdsInShop = [];
        foreach ($apps as $app) {
            if (isset($app['webhooks'], $app['app_status']) && $app['app_status'] == 'active') {
                foreach ($app['webhooks'] as $webhook) {
                    if ($webhook['code'] != 'app_delete') {
                        array_push($webhookIdsInShop, $webhook['dynamo_webhook_id']);
                    }
                }
            }
        }

        $webhookIdsInShop = array_unique($webhookIdsInShop);
        $handlerData = [
            'type' => 'full_class',
            'class_name' => '\App\Connector\Models\SourceModel',
            'method' => 'updateEventSubscription',
            'queue_name' => 'update_event_subscription',
            'user_id' => $userId,
            'shop_id' => $shopId,
            'data' => [
                'user_id' => $userId,
                'remote_shop_id' => $shop['remote_shop_id'],
                'marketplace' => $marketplace,
                'subscription_ids' => $webhookIdsInShop,
                'cursor' => 0,
            ],
        ];
        $this->di->getMessageManager()->pushMessage($handlerData);
        return true;
    }

    public function updateEventSubscription($sqsData)
    {
        $userId = $sqsData['user_id'];
        $shopId = $sqsData['shop_id'];
        $cursor = isset($sqsData['data']['cursor']) ? $sqsData['data']['cursor'] : 0;
        $subscriptionIds = isset($sqsData['data']['subscription_ids']) ? $sqsData['data']['subscription_ids'] : [];
        if (count($subscriptionIds) == 0) {
            $this->di->getLog()->logContent(date("d-m-y H:i:s") . " Inside updateEventSubscription function : Response  ==>  There is no subscription to update.", "info", "webhook" . DS . $userId . "_" . $shopId . DS . "re_register_webhook" . DS . date("Y-m-d") . DS . "remote_response.log");
            return true;
        }

        $count = 0;
        while ($count < 5 && count($subscriptionIds) > $cursor) {
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('cedcommerce', true)
                ->call('event/subscription', [], ['shop_id' => $sqsData['data']['remote_shop_id'], 'subscription_id' => $subscriptionIds[$cursor], 'app_code' => "cedcommerce", 'data' => []], 'PUT');
            if (isset($remoteResponse['success']) && !$remoteResponse['success']) {
                $this->di->getLog()->logContent([
                    'method' => __METHOD__,
                    'line' => __LINE__,
                    'response' => $remoteResponse,
                ], "info", "webhook" . DS . $userId . "_" . $shopId . DS . "re_register_webhook" . DS . date("Y-m-d") . DS . "remote_response.log");
            }

            $count++;
            $cursor++;
        }

        $sqsData['data']['cursor'] = $cursor;
        if (count($subscriptionIds) > $cursor) {
            $this->di->getMessageManager()->pushMessage($sqsData);
            return true;
        }
        return true;
    }

    public function getConnectedMarketplaces($userId = null, $shopId = null)
    {
        if (!isset($userId)) {
            $userId = $this->di->getUser()->id;
        }

        $shop = $this->di->getUser()->shops ?? array();
        if (empty($shop)) {
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shop = $userDetails->getShop($shopId, $userId);
        } else {
            foreach ($shop as $sh) {
                if ($sh['_id'] == $shopId) {
                    $shop = $sh;
                }
            }
        }

        $marketplaces = [];
        if (!empty($shop)) {
            $sources = isset($shop['sources']) ? $shop['sources'] : [];
            $targets = isset($shop['targets']) ? $shop['targets'] : [];
            foreach ($sources as $source) {
                array_push($marketplaces, $source['code']);
            }

            foreach ($targets as $target) {
                array_push($marketplaces, $target['code']);
            }
        }

        return array_unique($marketplaces);
    }

    public function createLog($message, $fileName, $logType = "info"): void
    {
        if ($this->di->has('isDev') && !$this->di->get('isDev')) {
            $this->di->getLog()->logContent($message, $logType, $fileName);
        }
    }

    private function updateReauthKeys($user_id, $shop)
    {
        $baseMongo = $this->di->getObjectManager()->get('App\Core\Models\BaseMongo');
        $userDetails = $baseMongo->getCollectionForTable('user_details');
        $userDetails->updateOne(
            ['user_id' => $user_id, 'shops' => ['$elemMatch' => ['_id' => $shop['_id']]]],
            [
                '$set' => ['shops.$[shop].reauth_completed_at' => date('c')],
                '$unset' => ['shops.$[shop].reauth_required' => 1, 'shops.$[shop].last_reauth_mail_send_at' => 1]
            ],
            [
                'arrayFilters' => [['shop._id' => $shop['_id']]]
            ]
        );

    }
}
