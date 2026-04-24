<?php

namespace App\Plan\Components;

use App\Plan\Models\Plan\Restore;
use App\Core\Components\Base;
use Exception;
use App\Plan\Models\Plan\Services;
use App\Plan\Components\Method\ShopifyPayment;
use App\Plan\Models\Plan;
use App\Plan\Models\Settlement;
use Phalcon\Events\Event;

/**
 * class to handle events in plan
 */
class HandleEvent extends Base
{
    public const FREE_PLAN_TIER = 'default';
    public const PAID_PLAN_TIER = 'paid';
    /**
     * @param $myComponent
     * @param $orderData
     * to handle event on order fetch
     */
    public function afterOrderFetch(Event $event, $myComponent, $orderData)
    {
        $userId = $this->di->getUser()->id;
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $limitUpdateResponse = $planModel->updateUsedLimitForCurrentUser(
            $userId,
            Plan::SERVICE_TYPE_ORDER_SYNC,
            1
        );
        if (
            !$limitUpdateResponse['success']
            && isset($limitUpdateResponse['code'])
            && ($limitUpdateResponse['code'] == 'exhaust' || $limitUpdateResponse['code'] == 'service_unavailable')
        ) {
            //this will disable all the amazon accounts to sync the orders if limit reached
            $planModel->updateTargetEntriesInDynamo($userId);
        }
    }

    /**
     * to handle event after account connection for plan
     */
    public function afterAccountConnection(Event $event, $myComponent, $shops)
    {
        $logFilePath = 'plan/afterAccountConnection/restore/' . date('Y-m-d') . '.log';
        try{
            if (isset($shops['source_shop'], $shops['target_shop']) && !empty($shops['source_shop']) && !empty($shops['target_shop'])) {
                $userId = $this->di->getUser()->id;
                $this->di->getObjectManager()->get(Common::class)->setDiRequesterAndTag($userId);
                $activePlanData = $this->di->getObjectManager()->get(Plan::class)->getActivePlan($userId);
                if($activePlanData['success'] == false) {
                    $res = $this->di->getObjectManager()->get(Restore::class)->restorePlan($userId);
                    $this->di->getLog()->logContent('Plan restore response => '.$res, 'info', $logFilePath);
                } else {
                    // We are checking plan_tier here because we don't want to update credits for paid plan
                    $planTier = isset($this->di->getUser()->plan_tier) ? $this->di->getUser()->plan_tier : false;
                    if ($planTier && $planTier == self::FREE_PLAN_TIER) {
                        $serviceModel = $this->di->getObjectManager()->get(Services::class);
                        $serviceModel->updateCreditsAsPerServiceType(Services::SERVICE_TYPE_ACCOUNT_CONNECTION);
                    }
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error occured in processing plan restoration! => '.json_encode($e->getMessage()), 'info', $logFilePath);
        }
    }

    /**
     * to update product import limits
     */
    public function afterProductCreateServiceUpdate(Event $event, $myComponent, $eventData)
    {
        $userId = $eventData['user_id'] ?? $this->di->getUser()->id;
        if($eventData['marketplace'] == Plan::SOURCE_MARKETPLACE) {
            $serviceModel = $this->di->getObjectManager()->get(Services::class);
            $serviceModel->updateCredits(
                Services::SERVICE_TYPE_PRODUCT_IMPORT,
               $userId,
               $eventData['shop_id'] ?? "",
                1,
                "add",
                $eventData['pending_count'] ?? 1
            );
        }
    }

    /**
     * to update limit on product delete
     */
    public function afterProductDelete(Event $event, $myComponent, $eventData)
    {
        $userId = $eventData['user_id'] ?? $this->di->getUser()->id;
        if($eventData['marketplace'] == Plan::SOURCE_MARKETPLACE) {
            $serviceModel = $this->di->getObjectManager()->get(Services::class);
            $serviceModel->updateCredits(
                Services::SERVICE_TYPE_PRODUCT_IMPORT,
               $userId,
               $eventData['shop_id'] ?? "",
                1,
                "deduct"
            );
        }
    }

    /**
     * to handle app uninstall events
     */
    public function appUninstall(Event $event, $myComponent, $data)
    {
        $userId = $this->di->getUser()->id;
        // $shops = $this->di->getUser()->shops;
        $userDetails = $this->di->getObjectManager()->get('App\Plan\Components\Common')->getUserDetail($userId);
        if (!empty($userDetails) && !empty($userDetails['shops'])) {
            foreach($userDetails['shops'] as $shop) {
                if (isset($shop['marketplace']) && ($shop['marketplace'] == Plan::SOURCE_MARKETPLACE) && !empty($shop['apps'])) {
                    foreach($shop['apps'] as $app) {
                        if (isset($app['app_status']) && ($app['app_status'] == 'uninstall')) {
                            $this->deactivatePlan($userId);
                            $this->unsetPlanTierKey($userId, $shop['_id']);
                        }
                    }
                }
            }
        }
    }

    /**
     * to handle cases before app uninstall
     */
    public function beforeAppUninstall(Event $event, $myComponent, $data)
    {
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $userService = $this->di->getObjectManager()->get(Plan::class)->getCurrentUserServices($userId, Plan::SERVICE_TYPE_ORDER_SYNC);
        if (!empty($userService)) {
            $this->di->getObjectManager()->get(Settlement::class)->checkSettlementAndProcess($userId, $userService);
        }
    }

    public function deactivatePlan($userId)
    {
        $userId = $this->di->getUser()->id;
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $activePlanData = $planModel->getActivePlanForCurrentUser($userId);
        if (!empty($activePlanData)) {
            if ($planModel->isFreePlan($activePlanData['plan_details'] ?? [])) {
                $planModel->deactivatePlan('free', $userId);
            } elseif ($planModel->isTrialPlan($activePlanData['plan_details'] ?? [])) {
                $planModel->deactivatePlan('trial', $userId);
            } elseif ($planModel->isCustomPlanOnetime($activePlanData['plan_details'] ?? [])) {
                $planModel->deactivatePlan('onetime', $userId);
            } else {
                $planModel->deactivatePlan('paid', $userId, "", true, true);
            }
        }
    }

    public function appReInstall(Event $event, $myComponent, $data)
    {
        $logFilePath = 'plan/afterAccountConnection/restore/' . date('Y-m-d') . '.log';
        try{
                if (!empty($data) && isset($data['source_shop']['marketplace']) && $data['source_shop']['marketplace'] == Plan::SOURCE_MARKETPLACE) {
                    $appActive = false;
                    // $shops = $this->di->getUser()->shops;
                    $userId = $this->di->getUser()->id;
                    $userDetails = $this->di->getObjectManager()->get('App\Plan\Components\Common')->getUserDetail($userId);
                    if (!empty($userDetails) && !empty($userDetails['shops'])) {
                        foreach($userDetails['shops'] as $shop) {
                            if (isset($shop['marketplace']) && ($shop['marketplace'] == $data['source_shop']['marketplace']) && !empty($shop['apps'])) {
                                foreach($shop['apps'] as $app) {
                                    if (isset($app['app_status']) && ($app['app_status'] == 'active')) {
                                        $appActive = true;
                                    }
                                }
                            }
                        }
                        if (!$appActive && isset($data['re_install_app']) && ($data['re_install_app'] == Plan::APP_TAG)) {
                            $appActive = true;
                        }
                    }
                    if ($appActive) {
                        $this->di->getLog()->logContent('Processing plan deactivation on reintsall if recurring is inactive for user: '.$userId, 'info', $logFilePath);
                        $this->di->getObjectManager()->get(Common::class)->setDiRequesterAndTag($userId);
                        $paymentInfo = $this->di->getObjectManager()->get(Plan::class)->getPaymentInfoForCurrentUser($userId);
                        if (!empty($paymentInfo) && isset($paymentInfo['marketplace_data']['id'])) {
                            $shop = $this->di->getObjectManager()->get(Common::class)->getShop($this->di->getRequester()->getSourceId(), $userId);
                            $remoteData['shop_id'] = $shop['remote_shop_id'] ?? "";
                            $remoteData['id'] = $paymentInfo['marketplace_data']['id'];
                            $response = $this->di->getObjectManager()->get(ShopifyPayment::class)->getPaymentData($remoteData, Plan::BILLING_TYPE_RECURRING);
                            if (isset($response['success'], $response['data']) && ($response['data']['status'] === ShopifyPayment::STATUS_CANCELLED)) {
                                $this->di->getLog()->logContent('Recurring is CANCELLED, so deactivating the plan', 'info', $logFilePath);
                                $this->di->getObjectManager()->get(Plan::class)->deactivatePlan('paid', $userId, $paymentInfo['marketplace_data']['id']);
                            } else {
                                $this->di->getLog()->logContent('Recurring is active, so we cannot deactivate the plan', 'info', $logFilePath);
                                if (empty($response)) {
                                    $this->di->getLog()->logContent('No response received from remote server: ' . __FILE__.'/line '.__LINE__, 'plan/remote_errors.log');
                                    $this->di->getLog()->logContent('Data sent to remote:' . json_encode($remoteData), 'plan/remote_errors.log');
                                }
                            }
                        } else {
                            $this->deactivatePlan($userId);
                        }
                        $activePlanData = $this->di->getObjectManager()->get(Plan::class)->getActivePlan($userId);
                        if($activePlanData['success'] == false) {
                            $this->di->getLog()->logContent('Active plan not found!', 'info', $logFilePath);
                            $res = $this->di->getObjectManager()->get(Restore::class)->restorePlan($userId);
                            $this->di->getLog()->logContent('Plan restore response => '.$res, 'info', $logFilePath);
                        }
                    }
                } elseif (!empty($data) && isset($data['source_shop']['marketplace']) && $data['source_shop']['marketplace'] == Plan::TARGET_MARKETPLACE) {
                    $planTier = isset($this->di->getUser()->plan_tier) ? $this->di->getUser()->plan_tier : false;
                    if ($planTier && $planTier == self::FREE_PLAN_TIER) {
                        $serviceModel = $this->di->getObjectManager()->get(Services::class);
                        $serviceModel->updateCreditsAsPerServiceType(Services::SERVICE_TYPE_ACCOUNT_CONNECTION);
                    }
                }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error occured in processing plan restoration! => '.json_encode($e->getMessage()), 'info', $logFilePath);
        }
    }

    private function unsetPlanTierKey($userId, $shopId)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollection('user_details');
            $userDetailsCollection->updateOne([
                'user_id' => $userId,
                'shops' => ['$elemMatch' => ['_id' => $shopId]]
            ], [
                '$unset' => ['plan_tier' => '']
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error Plan HandleEvent:unsetPlanTierKey() => ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }

    public function afterAccountDisconnect(Event $event, $myComponent, $data)
    {
        if (isset($data['marketplace']) && $data['marketplace'] == Plan::TARGET_MARKETPLACE) {
            $planTier = isset($this->di->getUser()->plan_tier) ? $this->di->getUser()->plan_tier : false;
            if ($planTier && $planTier == self::FREE_PLAN_TIER) {
                $serviceModel = $this->di->getObjectManager()->get(Services::class);
                $serviceModel->updateCreditsAsPerServiceType(Services::SERVICE_TYPE_ACCOUNT_CONNECTION);
            }
        }
    }
    public function afterProfileCreated(Event $event, $myComponent, $data)
    {
            $planTier = isset($this->di->getUser()->plan_tier) ? $this->di->getUser()->plan_tier : false;
            if ($planTier && $planTier == self::FREE_PLAN_TIER) {
                $serviceModel = $this->di->getObjectManager()->get(Services::class);
                $serviceModel->updateCreditsAsPerServiceType(Services::SERVICE_TYPE_TEMPLATE_CREATION);
            }
    }

    public function afterProfileDeleted(Event $event, $myComponent, $data)
    {
        $planTier = isset($this->di->getUser()->plan_tier) ? $this->di->getUser()->plan_tier : false;
        if ($planTier && $planTier == self::FREE_PLAN_TIER) {
            $serviceModel = $this->di->getObjectManager()->get(Services::class);
            $serviceModel->updateCreditsAsPerServiceType(Services::SERVICE_TYPE_TEMPLATE_CREATION);
        }
    }
}
