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

use App\Connector\Components\Hook;
use App\Shopifyhome\Components\UserEvent;
use Exception;

class StoreClose extends Hook
{
    /**
     * Saving store close user data before uninstallation
     * @param array $data
     * @return array
     */
    public function initiateProcess($data)
    {
        try {
            $logFile = "shopify/storeClose/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('Starting process for user data: '.json_encode($data), 'info',  $logFile);
            if ($this->checkIsShopActive($data['user_id'], $data['data'])) {
                $this->di->getLog()->logContent('Shop token is active reverted store close process for this user: ' . json_encode($data['user_id']), 'info',  $logFile);
                $this->checkAndRegisterShopUpdateWebhook($data['data']);
                return ['success' => true, 'message' => 'Shop token is active reverting store close process for this user'];
            }
            $remoteData = $this->getToken($data['shop']);
            $shopData = $data['data'];
            if ($remoteData) {
                $remoteData = [
                    'remote_shop_id' => $shopData['remote_shop_id'],
                    'token' => $remoteData['apps'][0]['token']
                ];
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $uninstallUserDetailsCollection = $mongo->getCollectionForTable('uninstall_user_details');
                $uninstallUserDetailsCollection->updateOne(
                    [
                        'user_id' => $data['user_id'],
                        'username' => $data['shop']
                    ],
                    [
                        '$set' => [
                            'user_id' => $data['user_id'],
                            'username' => $data['shop'],
                            'email' => $shopData['email'],
                            'store_closed_at' => $shopData['store_closed_at'],
                            'remote_data' => $remoteData
                        ]
                    ],
                    ['upsert' => true]
                );
                $this->startUninstallationProcess($data);
                $this->di->getLog()->logContent('Store close data deletion process initiated...', 'info',  $logFile);
                return ['success' => true, 'message' => 'Store close data deletion process initiated...'];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception: initiateProcess()' . print_r($e->getMessage()), 'info', $logFile ?? 'exception.log');
        }
    }

    /**
     * Checking is shop active if yes then reverting storeclose process for particular shop
     * @param string $userId
     * @param array $shop
     * @return bool
     */
    private function checkIsShopActive($userId, $shop)
    {
        $remoteData = ['shop_id' => $shop['remote_shop_id']];
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($shop['marketplace'], true)
            ->call('/shop', [], $remoteData, 'GET');
        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
            //Reverting store close process for this shop as shop is active
            if (!empty($remoteResponse['data'])) {
                    $preparedData = [
                        'shop' => $shop['domain'],
                        'data' => $remoteResponse['data'],
                        'user_id' => $userId,
                        'shop_id' => $shop['_id'],
                        'marketplace' => 'shopify'
                    ];
                $this->di->getObjectManager()->get('\\App\\Shopifyhome\\Components\\Shop\\Hook')
                ->shopUpdate($preparedData);
                return true;
            }
        }

        return false;
    }

    /**
     * Registering shop_update webhook if missing in shop
     * @param array $shop
     * @return null
     */
    private function checkAndRegisterShopUpdateWebhook($shop)
    {
        if (!empty($shop['apps'][0]['webhooks'])) {
            $webhookCodes = array_column($shop['apps'][0]['webhooks'], 'code');
            if (!in_array('shop_update', $webhookCodes)) {
                $destinationWise = [];
                $userEvent = $this->di->getObjectManager()->get(UserEvent::class);
                $createDestinationResponse = $userEvent->createDestination($shop);
                $destinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                if ($destinationId) {
                    $appCode = $shop['apps'][0]['code'] ?? 'default';
                    $destinationWise[] = [
                        'destination_id' => $destinationId,
                        'event_codes' => ['shop_update']
                    ];
                    $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks($shop, $shop['marketplace'], $appCode, false, $destinationWise);
                }
            }
        }
    }

    /**
     * Initiating uninstallation process for store close users
     * @param array $data
     * @return array
     */
    public function startUninstallationProcess($data)
    {
        $shopData = $data['data'];
        $handlerData = [
            'shop' => $data['shop'],
            'data' => $shopData,
            'type' => 'full_class',
            'class_name' => '\\App\\Shopifyhome\\Components\\Shop\\StoreClose',
            'method' => 'TemporarlyUninstall',
            'user_id' => $data['user_id'],
            'action' => 'app_delete',
            'queue_name' => 'app_delete',
            'shop_id' => $shopData['_id'],
            'marketplace' => $shopData['marketplace'],
            'app_code' => $data['app_code']
        ];
        $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')
            ->pushMessage($handlerData);
        
    }

    /**
     * Fetching apps_shops data by shop_url
     * @param string $shop
     * @return bool
     */
    private function getToken($shop)
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
     * Overidden method of Connector TemporarlyUninstall
     * Bypassed appUninstall Event
     * @param array $data
     * @param string $userId
     * @return array
     */
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

                return ['success' => false, 'message' => "Error creating ShopDelete object"];
            }
        } else {
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
                $this->disconnectAccount($data, $eraseDataAfterDate, $uninstallDate);
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
                $this->setUninstallDateInApp($shop, $appCode, $eraseDataAfterDate, $uninstallDate);
                $this->setStatusInSourcesAndTargets($shop, $appCode);
                $this->uninstallUserDetails($shop, $appCode);
                $this->getHost();
                // $eventsManager->fire('application:appUninstall', $this, [
                //     'shop' => $shop,
                //     'app_code' => $appCode,
                //     "backend_host" => $host,
                //     "user" => $this->removeUserFields($this->di->getUser()->toArray()),
                // ]);
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

            $this->sendDataToMarketplace([['marketplace' => $shop['marketplace']]], "temporarlyUninstall", $dataToSendToMarketplace);
            // Delete shop related entries directly without waiting for CRON process in case of force_delete
            if (isset($data['force_delete']) && $data['force_delete']) {
                $this->appDelete();
            }

            return ['success' => true, 'message' => "Successfully added uninstall key and disconnected fields."];
        }
        return ['success' => false, 'message' => $appData];
    }

    /**
     * Fetching base url from config
     * @param null
     * @return bool
     */
    private function getHost()
    {
        $config = $this->di->getConfig();
        return $config->get('backend_base_url') ?? $config->get('backend_home_url') ?? $config->get('home_base_url') ?? $config->get('frontend_app_url') ?? gethostname();
    }
}
