<?php

namespace App\Amazon\Components;

use App\Amazon\Components\Common\Helper;
use Exception;
use App\Core\Components\Base as Base;

class ShopDelete extends Base
{
    public const MARKETPLACE = 'amazon';

    private $commonHelper;

    private $shopEvent;

    private $userDetails;

    private $connectorHook;

    private ?string $logFile = null;

    public function _construct(): void
    {
        $this->commonHelper = $this->di->getObjectManager()->get(Helper::class);
        $this->shopEvent = $this->di->getObjectManager()->get(ShopEvent::class);
        $this->userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $this->connectorHook = $this->di->getObjectManager()->get("\App\Connector\Components\Hook");
    }

    /**
     * shopEraserBefore function
     * To delete data from amazon collection
     * @param [array] $data
     */
    public function shopEraserBefore($data): void
    {
        $this->logFile = "amazon/shopEraserBefore/" . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Shop eraser before process initiated, Data => ' . json_encode($data), 'info', $this->logFile);
        if (isset($data['_id'], $data['sources'])) {
            $userId = $this->di->getUser()->id;
            $targetShopId = $data['_id'];
            $targetMarketplace = self::MARKETPLACE;
            $targetAppCode = $data['apps'][0]['code'] ?? 'default';
            $disconnectData = [
                'user_id' => $userId,
                'shop_id' => $targetShopId
            ];
            foreach ($data['sources'] as $key => $sourceData) {
                foreach ($sourceData as $sourceKey => $value) {
                    if ($sourceKey == "disconnected") {
                        $disconnectData['source_shop_id'] = $data['sources'][$key]["shop_id"];
                        try {
                            $this->shopEvent->beforeAccountDisconnect($disconnectData);
                        } catch (Exception $e) {
                            $this->di->getLog()->logContent('Exception => ' . $e->getMessage(), 'info', $this->logFile);
                        }

                        $sourceAppCode = $data['sources'][$key]['app_code'];
                        $sourceMarketplace = $data['sources'][$key]['code'];
                        $appTag = $this->commonHelper->getAppTagFromAppCodes($sourceMarketplace, $sourceAppCode, $targetMarketplace, $targetAppCode);
                        if ($appTag) {
                            $this->di->getAppCode()->setAppTag($appTag);
                            $this->commonHelper->addAccountNotification(
                                [
                                    'target_shop_id' => $disconnectData['shop_id'],
                                    'source_shop_id' => $disconnectData['source_shop_id'],
                                    'activity_type' => Helper::ACTIVITY_TYPE_SHOP_DATA_ERASE
                                ]
                            );
                        }

                        // if (isset($data['remote_shop_id'], $data['warehouses'][0]['marketplace_id'])) {
                        //     $disconnectData['remote_shop_id'] = $data['remote_shop_id'];
                        //     $disconnectData['marketplace_id'] = $data['warehouses'][0]['marketplace_id'];
                        //     $this->saveSalesMatrix($disconnectData);
                        // }
                    }
                }
            }
        }
    }

    /**
     * shopEraserAfter function
     * This function is responsible for sending a notification to the source system
     * after the shop data has been deleted.
     * @param [array] $data
     */
    public function shopEraserAfter($data)
    {
        $this->logFile = "amazon/shopEraserAfter/" . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Shop eraser after process initiated, Data => ' . json_encode($data), 'info', $this->logFile);
        if ($data['marketplace'] == self::MARKETPLACE) {
            if (!empty($data['sources'])) {
                foreach ($data['sources'] as $sources) {
                    if (isset($sources['code'], $sources['shop_id'])) {
                        $moduleHome = ucfirst($sources['code']);
                        $moduleClass = '\App\\' . $moduleHome . '\Components\ShopDelete';
                        if (!class_exists($moduleClass)) {
                            $moduleHome = ucfirst($sources['code']) . "home";
                            $moduleClass = '\App\\' . $moduleHome . '\Components\ShopDelete';
                        }
                        if (class_exists($moduleClass) && method_exists($moduleClass, 'checkConnectedTargetsOrSources')) {
                            $moduleObject = $this->di->getObjectManager()->get($moduleClass);
                            $moduleObject->checkConnectedTargetsOrSources($this->di->getUser()->id, $sources['shop_id']);
                        }
                    }
                }
            }
            if($this->checkAllAccountsDisconnected()) {
                $this->performActionAfterAllAccountsDisconnected($data);
            }
        }
    }

    /**
     * saveSalesMatrix function
     * Save order matrix for user before deleting shop to show on admin panel
     * @param [array] $data
     */
    public function saveSalesMatrix($data): void {
        try {
            if(isset($data['user_id'], $data['shop_id'], $data['remote_shop_id'])) {
                $params = [
                    'start_time' => date('Y-m-d\TH:i:sP', strtotime('-3 months')),
                    'end_time' => date('Y-m-d\TH:i:sP'),
                    'shop_id' => (string)$data['remote_shop_id'],
                    'group_by' => 'Month'
                ];
                $response = $this->di->getObjectManager()->get(Helper::class)->sendRequestToAmazon('order-matrix', $params, 'GET');
                if (isset($response['success'], $response['response']) && $response['success']) {
                    $orderMatrix = [
                        'marketplace_id' => $data['marketplace_id'],
                        'sales' => $response['response']
                    ];
                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $uninstallUserDetailsCollection = $mongo->getCollectionForTable('uninstall_user_details');
                    $uninstallUserDetailsCollection->updateOne(
                        ['user_id' => (string)$data['user_id']],
                        ['$set' => ['order_matrix.' . $data['shop_id'] => $orderMatrix]]
                    );
                }
            }
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception Amazon\Components\ShopDelete:saveSalesMatrix() = ' . $e->getMessage(), 'info', 'exception.log');
        }
    }

    /**
     * temporarlyUninstall function
     * To initiate appUninstall or accountDisconnection process
     * @param [array] $data
     * @return array
    */
    public function temporarlyUninstall($data)
    {
        $this->logFile = "amazon/temporaryUninstall/" . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Temporary uninstall process initiated, Data => ' . json_encode($data), 'info', $this->logFile);
        if (isset($data['target_shop_id'])) {
            $this->accountDisconnection($data);
        } elseif (isset($data['shop_id'])) {
            $this->appUninstall($data);
        }

        if (isset($message)) {
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        }

        return ['success' => true, 'message' => 'Process Completed!!'];
    }

    /**
     * accountDisconnection function
     * Performing tasks to be done when user amazon account disconnected
     * @param [array] $data
     */
    private function accountDisconnection($data): void
    {
        $this->di->getLog()->logContent('Initiating Account Disconnection Process..', 'info', $this->logFile);
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $dataToSend = [
            'user_id' => $userId,
            'shop_id' => $data['target_shop_id']
        ];
        if (isset($data['deletion_in_progress']) && $data['deletion_in_progress']) {
            $dataToSend['deletion_in_progress'] = true;
        }

        $this->removeRegisterForOrderSync($dataToSend);
        $this->shopEvent->sendRemovedShopToAws($dataToSend);
        $this->removeSpecificDocsFromConfig($dataToSend);
        $this->removeEntryFromQueuedTask($dataToSend);
        $appCodes = $this->di->getAppCode()->get();
        $targetShopData = $this->userDetails->getShop($data['target_shop_id'], $userId);
        if (!empty($targetShopData)) {
            $targetAppCode = $appCodes[self::MARKETPLACE];
            $this->unRegisterWebhook($targetShopData, $targetAppCode);
        }

        if (isset($data['source_shop_id'])) {
            $sourceShopData = $this->userDetails->getShop($data['source_shop_id'], $userId);
            if (!empty($sourceShopData)) {
                $sourceMarketplace = $sourceShopData['marketplace'];
                $targetMarketplace = self::MARKETPLACE;
                $sourceAppCode = $appCodes[$sourceMarketplace];
                $targetAppCode = $appCodes[self::MARKETPLACE];
                $appTag = $this->commonHelper->getAppTagFromAppCodes($sourceMarketplace, $sourceAppCode, $targetMarketplace, $targetAppCode);
                if ($appTag) {
                    $this->di->getAppCode()->setAppTag($appTag);
                    $this->commonHelper->addAccountNotification(
                        [
                            'target_shop_id' => $data['target_shop_id'],
                            'source_shop_id' => $data['source_shop_id'],
                            'activity_type' => Helper::ACTIVITY_TYPE_ACCOUNT_DISCONNECTION
                        ]
                    );
                }
            }
        }

        $eventData = [
            'user_id' => $userId,
            'shop_id' => $data['target_shop_id'],
            'marketplace' => self::MARKETPLACE,
        ];
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:afterAccountDisconnect', $this, $eventData);
    }

    /**
     * appUninstall function
     * Performing tasks to be done when user uninstall our app
     * @param [array] $data
     */
    private function appUninstall($data): void
    {
        try {
            $this->di->getLog()->logContent('Initiating App Uninstall Process..', 'info', $this->logFile);
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $shopData = $this->userDetails->getShop($data['shop_id'], $userId);

            if (!empty($shopData['targets'])) {
                foreach ($shopData['targets'] as  $target) {
                    if (
                        isset($target['app_code']) && $target['app_code'] == self::MARKETPLACE
                        && !empty($target['shop_id'])
                    ) {
                        $dataToSend = [
                            'user_id' => $userId,
                            'shop_id' =>  $target['shop_id']
                        ];
                        if (isset($data['deletion_in_progress']) && $data['deletion_in_progress']) {
                            $dataToSend['deletion_in_progress'] = true;
                        }

                        $this->removeRegisterForOrderSync($dataToSend);
                        $this->shopEvent->sendRemovedShopToAws($dataToSend);
                        $this->removeSpecificDocsFromConfig($dataToSend);
                        $this->removeEntryFromQueuedTask($dataToSend);
                        $sourceMarketplace = $shopData['marketplace'];
                        $targetMarketplace = self::MARKETPLACE;
                        $sourceAppCode = $data['app_code'];
                        $targetAppCode = $target['app_code'];
                        $appTag = $this->commonHelper->getAppTagFromAppCodes($sourceMarketplace, $sourceAppCode, $targetMarketplace, $targetAppCode);
                        if ($appTag) {
                            $this->di->getAppCode()->setAppTag($appTag);
                            $this->commonHelper->addAccountNotification(
                                [
                                    'source_shop_id' => $data['shop_id'],
                                    'target_shop_id' => $target['shop_id'],
                                    'activity_type' => Helper::ACTIVITY_TYPE_ACCOUNT_DISCONNECTION
                                ]
                            );
                        } else {
                            $this->di->getLog()->logContent('Unable to add notification for target: ' . json_encode($target), 'info', $this->logFile);
                        }

                        $dataToBeDeleted = [
                            'shop_id' => $target['shop_id'],
                            'app_code' => $targetAppCode
                        ];
                        $this->di->getLog()->logContent('Data send on connector TemporarlyUninstall: ' . json_encode($dataToBeDeleted), 'info', $this->logFile);
                        $connectorResponse = $this->connectorHook->TemporarlyUninstall($dataToBeDeleted, $userId, false);
                        $this->di->getLog()->logContent('Response from connector TemporarlyUninstall: ' . json_encode($connectorResponse), 'info', $this->logFile);
                    } else {
                        $this->di->getLog()->logContent('Target marketplace or app_code not matched for data: ' . json_encode($target), 'info', $this->logFile);
                    }
                }
                $this->performActionAfterAllAccountsDisconnected($data);
            } else {
                $this->di->getLog()->logContent('Empty target data', 'info', $this->logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from appUninstall():' . json_encode($e->getMessage()), 'info', $this->logFile);
        }
    }

    /**
     * unRegisterWebhook function
     * Deleting subscription from marketplace
     * @param [array] $shopData
     * @param [string] $appCode
     * @return array
    */
    public function unRegisterWebhook($shopData, $appCode)
    {
        if (!empty($shopData) && !empty($appCode)) {
            return $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")
                ->routeUnregisterWebhooks($shopData, $shopData['marketplace'], $appCode, false);
        }
        return ['success' => false, 'message' => 'shop data or appCode is empty'];
    }

    /**
     * removeRegisterForOrderSync function
     * Removing register_for_order_sync from shop
     * @param [array] $data
     * @return null
    */
    public function removeRegisterForOrderSync($data)
    {
        if (isset($data['user_id'], $data['shop_id'])) {
            try {
                $userCollection = $this->userDetails->getCollectionForTable('user_details');
                $userCollection->updateOne(
                    [
                        'user_id' => (string)$data['user_id'],
                        'shops._id' => (string) $data['shop_id'],
                    ],
                    [
                        '$unset' => [
                            'shops.$.register_for_order_sync' => 1
                        ]
                    ]
                );
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Exception from Amazon\Components\ShopDelete:removeRegisterForOrderSync() => ' . $e->getMessage(), 'info', 'exception.log');
            }
        } else {
            return ['success' => false, 'message' => 'Required params missing'];
        }
    }

    /**
     * removeSpecificDocsFromConfig function
     * Removing specific docs from config
     * @param [array] $data
     * @return null
    */
    private function removeSpecificDocsFromConfig($data)
    {
        $groupCodeAndKeyPair = [
            'feedStatus' => [
                'accountStatus'
            ]
        ];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $configCollection = $mongo->getCollectionForTable('config');
        foreach ($groupCodeAndKeyPair as $groupCode => $keys) {
            $configCollection->deleteMany([
                'user_id' => $data['user_id'],
                'target_shop_id' => $data['shop_id'],
                'group_code' => $groupCode,
                'key' => ['$in' => $keys]
            ]);
        }
    }

    /**
     * checkAllAccountsDisconnected function
     * Checking if all accounts are disconnected
     * @return bool
    */
    private function checkAllAccountsDisconnected()
    {
        $shops = $this->di->getUser()->shops ?? [];
        foreach ($shops as $shop) {
            if (
                $shop['marketplace'] == self::MARKETPLACE
                && !empty($shop['apps'][0]['app_status'])
                && $shop['apps'][0]['app_status'] == 'active'
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * performActionAfterAllAccountsDisconnected function
     * Performing action after all accounts are disconnected
     * @param [array] $data
     * @return null
    */
    private function performActionAfterAllAccountsDisconnected($data)
    {
        try {
            $this->di->getLog()->logContent('Initiating Action After All Accounts Disconnected, data => ' . json_encode($data), 'info', $this->logFile);
            // Removing order fetch key from user details
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $userDetailsCollection->updateOne(
                [
                    'user_id' => $data['user_id'] ?? $this->di->getUser()->id
                ],
                ['$unset' => ['order_fetch_enabled' => '']]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from Amazon\Components\ShopDelete:performActionAfterAllAccountsDisconnected() => ' . $e->getMessage(), 'info', $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    private function removeEntryFromQueuedTask($data) {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $queuedTasksCollection = $mongo->getCollectionForTable('queued_tasks');

            $queuedTasksCollection->deleteMany([
                'user_id' => $data['user_id'],
                'shop_id' => $data['shop_id'],
                'marketplace' => self::MARKETPLACE
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from Amazon\Components\ShopDelete:removeEntryFromQueuedTask() => ' . $e->getMessage(), 'info', $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    /**
     * initiateAccountDisconnectionInactivePlan function
     * To initiate account disconnection for inactive plan users via queue
     * @param [array] $sqsData
     * @return void
     */
    public function initiateAccountDisconnectionInactivePlan($sqsData)
    {
        try {
            $logFile = "amazon/inactive_plan_disconnection/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('Initiating account disconnection for inactive plan user, Data => ' . json_encode($sqsData), 'info', $logFile);

            if (!empty($sqsData['data'])) {
                $helper = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
                $response = $helper->TemporarlyUninstall($sqsData['data']);
                $this->di->getLog()->logContent('Response from TemporarlyUninstall: ' . json_encode($response), 'info', $logFile);
            } else {
                $this->di->getLog()->logContent('Empty data in sqsData, cannot proceed with disconnection', 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in initiateAccountDisconnectionInactivePlan(): ' . $e->getMessage(), 'info', $logFile ?? 'exception.log');
            throw $e;
        }
    }

}
