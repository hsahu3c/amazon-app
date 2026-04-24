<?php

namespace App\Plan\Components;

use App\Core\Components\Base;
use Exception;
use App\Core\Components\SendMail;
use App\Plan\Models\Plan;
use App\Plan\Models\BaseMongo as BaseMongo;
use App\Plan\Components\Common;
use App\Plan\Components\Recommendations;
use App\Connector\Components\Emails\Email as ConEmail;
use App\Core\Models\Config\Config;
use App\Plan\Models\Plan\Services;

class Email extends Base
{
    public const PLAN_UPGRADE = "plan_upgrade";

    public const PLAN_ACTIVATED = "plan_activated";

    public const PAYMENT_ACCEPTED = "payment_accepted";

    public const SYNCING_STOPPED = "syncing_stopped_sent";

    public const RECURRING_CANCELLED = 'recurring_cancelled';

    public const SETTLEMENT_SUCCESS_MAIL = 'settlement_success_mail';

    public const PLAN_DEACTIVATION_WARNING = 'plan_deactivation_warning';

    public const CREDIT_CONSUMED = "credit_consumed_100_sent";

    public const CREDIT_CONSUMED_90 = "credit_consumed_90_sent";

    public const CREDIT_CONSUMED_80 = "credit_consumed_80_sent";

    public const CREDIT_CONSUMED_70 = "credit_consumed_70_sent";

    public const CREDIT_CONSUMED_50 = "credit_consumed_50_sent";

    public const ORDER_LIMIT_EXCEEDED = "credit_consumed_100_sent";

    public const SEND_MAIL_ON_LIMIT_EXCEED = 'send_mail_on_limit_exceed';

    public const SEND_MAIL_FOR_LIMIT_EXCEED_90 = 'send_mail_on_90_limit_exceed';

    public const SEND_MAIL_FOR_LIMIT_EXCEED_70 = 'send_mail_on_70_limit_exceed';

    public const SEND_MAIL_FOR_LIMIT_EXCEED_60 = 'send_mail_on_60_limit_exceed';

    public const SEND_MAIL_FOR_LIMIT_EXCEED_50 = 'send_mail_on_50_limit_exceed';

    public const SUPPORTED_MAIL_LIMITS = [self::CREDIT_CONSUMED_50, self::CREDIT_CONSUMED_90];

    public const EMAIL_GROUP_CODE = 'email';

    public const EMAIL_SEND_NOTIFICATIONS = 'send_email_notifications';

    public const CAPPED_USAGE_90 = 'credit_capped_usage_90';

    public const DEFAULT_MAILS = [
        self::PLAN_UPGRADE,
        self::PAYMENT_ACCEPTED,
        self::PLAN_ACTIVATED,
        self::SETTLEMENT_SUCCESS_MAIL,
        self::ORDER_LIMIT_EXCEEDED,
        self::CREDIT_CONSUMED,
        self::SYNCING_STOPPED,
        self::CAPPED_USAGE_90
    ];

    public const SEND_RECOMMENDATIONS_IN_MAIL = false;

    /**
     * common function to send mail in plan
     */
    public function sendMail($mailData, $userId = false)
    {
        $success = false;
        $message = "Something went wrong!";
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        if (!isset($mailData['path']) || empty($mailData['path'])) {
            $message = 'Email path not provided!';
        } else {
            $planModel = $this->di->getObjectManager()->get(Plan::class);
            $userData = $this->di->getObjectManager()->get(Common::class)->getUserDetail($userId);
            if (!empty($userData)) {
                $canSendMail = true;
                if (isset($mailData['setting_name'])) {
                    $canSendMail = $this->canSendMailAsPerSetting($mailData['setting_name'], $userId, $userData);
                }

                if ($canSendMail) {
                    $mailData['email'] = $userData['source_email'] ?? ($userData['email'] ?? "");
                    $bccs = $this->di->getConfig()->get('mailer')->get('bcc') ?? [];
                    if (!is_array($bccs)) {
                        $bccs = [$bccs];
                    }
                    if (isset($userData['alternate_email'])) {
                        $bccs[] = $userData['alternate_email'];
                    }

                    $mailData['bccs'] = $bccs;
                    $mailData['username'] = $userData['username'];
                    $mailData['name'] = $userData['name'];
                    $mailData['sender'] = $this->di->getConfig()->get('mailer')->get('sender_name') ?? "";
                    $mailData['date'] = date("d-m-Y");
                    // $mailData['support_page'] = $this->di->getConfig()->admin_panel_base_url . 'support';
                    $mailData['support_page'] = 'https://support.cedcommerce.com/portal/en/newticket?departmentId=132692000000010772&layoutId=132692000092595993';
                    $mailData['subject'] .= ' - ' . $this->di->getConfig()->app_name;
                    $mailData['app_name'] = $this->di->getConfig()->app_name;
                    $mailData['has_paid_plan'] = isset($mailData['plan_details']['custom_price'])
                        ? ((int)$mailData['plan_details']['custom_price'] != 0)
                        : false;

                    $shopifyDomain = $planModel->getDomainName();
                    $redirectUrl =  $this->di->getObjectManager()->get(Common::class)->getDefaultRedirectUrl($shopifyDomain);
                    $redirectUrl = $redirectUrl .'panel/user/dashboard/pricing';
                    $mailData['page_link'] = $redirectUrl ?? false;

                    $userService = $planModel->getCurrentUserServices($userId, Plan::SERVICE_TYPE_ORDER_SYNC);
                    if (!empty($userService)) {
                        $mailData['postpaid_enabled'] = $userService['postpaid']['capped_credit'] ?? 0;
                        $mailData['is_capped'] = $userService['postpaid']['is_capped'] ?? false;
                        if ($mailData['is_capped']) {
                            if (!empty($userService['postpaid'])) {
                                $mailData['capped_data'] = [
                                    'capped_amount' => $userService['postpaid']['capped_amount'],
                                    'per_unit_usage' => $userService['postpaid']['per_unit_usage_price'],
                                    'capped_credit' => $userService['postpaid']['capped_credit']
                                ];
                            }
                        }
                    }

                    if (!isset($mailData['has_unsubscribe_link'])) {
                        $mailData['has_unsubscribe_link'] = false;
                    }

                    try {
                        $success = $this->di->getObjectManager()->get(SendMail::class)->send($mailData);
                        $message = 'email success';
                    } catch (Exception $e) {
                        $message = 'Error in sending mail. Error => ' . json_encode($e->getMessage());
                    }
                } else {
                    $message = 'Email settings disabled';
                }
            } else {
                $message = 'User details not found';
            }
        }

        return  [
            'success' => $success,
            'message' => $message
        ];
    }

    /**
     * to generate unsubscribe link
     */
    public function getUnsubscribeLink($userId, $settingName)
    {
        if (!empty($this->di->getConfig()->get('unsubscribe_url_route'))) {
            $frontendUrlForUnsubscribe = $this->di->getConfig()->get('unsubscribe_url_route');
            $tokenData = [
                'user_id' => $userId,
                'setting_name' => $settingName,
                'app_tag' => Plan::APP_TAG,
                'only_token' => true,
                'action' => 'email_unsubscribe',
                'source' => Plan::SOURCE_MARKETPLACE,
                'target' => Plan::TARGET_MARKETPLACE
            ];
            $token = $this->di->getObjectManager()->get(ConEmail::class)->generateTokenForEmailSubscription($tokenData);
            if ($token['success'] && isset($token['token'])) {
                return [
                    'success' => true,
                    'data' => $frontendUrlForUnsubscribe . '?token=' . $token['token'] . '&mail_type=Plan Limit Exceed Mails'
                ];
            }

            return [
                'success' => false,
                'message' => 'Unable to process unsubscribe link'
            ];
        }

        return [
            'success' => false,
            'message' => 'Url not exist for unsubscribe link'
        ];
    }

    /**
     * to calculate the % of credit used
     */
    public function calculateCreditPercentage($credits, $usedCredits)
    {
        $limitCredits = [
            self::CREDIT_CONSUMED_50 => 50,
            self::CREDIT_CONSUMED_70 => 70,
            self::CREDIT_CONSUMED_80 => 80,
            self::CREDIT_CONSUMED_90 => 90,
            self::CREDIT_CONSUMED => 100
        ];
        if ($credits == $usedCredits) {
            return [
                'success' => true,
                'code' => self::CREDIT_CONSUMED,
                'value' => 100
            ];
        }

        foreach ($limitCredits as $key => $value) {
            $percentUsed = ($value / 100) * $credits;
            if ($percentUsed == $usedCredits) {
                return [
                    'success' => true,
                    'code' => $key,
                    'value' => $value
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'No matched credit limit!'
        ];
    }

    /**
     * to check if settings enabled for limit exceed notifications
     */
    public function isSettingEnableForLimitExceed($userId, $limitSettingName)
    {
        $configObj = $this->di->getObjectManager()->get(Config::class);
        $configObj->setGroupCode(self::EMAIL_GROUP_CODE);
        $configObj->setUserId($userId);
        $configObj->setAppTag(null);

        $sendMailOnLimitExceedSetting = $configObj->getConfig(self::SEND_MAIL_ON_LIMIT_EXCEED);
        $val = true;
        $settingEnable = true;
        if (!empty($sendMailOnLimitExceedSetting)) {
            $settingEnable = $sendMailOnLimitExceedSetting['value'] ?? $sendMailOnLimitExceedSetting[0]['value'] ??  true;
            if ($settingEnable) {
                $limitRangeSetting = $configObj->getConfig($limitSettingName);
                if (!empty($limitRangeSetting)) {
                    return $limitRangeSetting['value'] ?? $limitRangeSetting[0]['value'] ??  true;
                }

                $this->setEmailConfig($userId, $limitSettingName, true);
                return true;
            }

            $val = $settingEnable;
        } else {
            $this->setEmailConfig($userId, self::SEND_MAIL_ON_LIMIT_EXCEED, true);
        }

        return $val;
    }

    /**
     * to check if mail is already send or not
     */
    public function isMailAlreadySend($userId, $mailType)
    {
        $val = true;
        if ($mailType) {
            $configObj = $this->di->getObjectManager()->get(Config::class);
            $configObj->setGroupCode(self::EMAIL_GROUP_CODE);
            $configObj->setUserId($userId);
            $configObj->setAppTag(null);

            $mailTypeSetting = $configObj->getConfig($mailType);
            if (!empty($mailTypeSetting) && isset($mailTypeSetting[0]['key'])) {
                $val = $mailTypeSetting[0]['value'];
            } elseif (empty($mailTypeSetting)) {
                $val = false;
            } else {
                $val = true;
            }
        }

        return $val;
    }

    /**to set settings for duplicacy check
     * 
     * @param $userId
     * @param $key
     * @param $value
     */
    public function setEmailConfig($userId, $key, $value)
    {
        $targetShopIDs = [];
        $sourceShopID = false;
        if ($this->di->getUser()->id) {
            $userData = $this->di->getUser()->toArray();
        } else {
            $userData = $this->di->getObjectManager()->get(Common::class)->getUserDetail($userId);
        }

        if (!empty($userData)) {
            if (isset($userData['shops'])) {
                foreach ($userData['shops'] as $shops) {
                    if ($shops['marketplace'] == Plan::SOURCE_MARKETPLACE) {
                        $sourceShopID = $shops['_id'];
                    }

                    if ($shops['marketplace'] == Plan::TARGET_MARKETPLACE) {
                        $targetShopIDs[] = $shops['_id'];
                    }
                }
            }

            $configObj = $this->di->getObjectManager()->get(Config::class);
            $configObj->setGroupCode(self::EMAIL_GROUP_CODE);
            $configObj->setUserId($userId);
            $settingEnabled = $configObj->getConfig(self::EMAIL_SEND_NOTIFICATIONS);
            $addSettingsEnabledKey = false;
            if (empty($settingEnabled)) {
                $addSettingsEnabledKey = true;
            }

            if ($sourceShopID) {
                foreach ($targetShopIDs as $targetId) {
                    $config = [
                        [
                            "key" => $key,
                            "value" => $value,
                            "group_code" => self::EMAIL_GROUP_CODE,
                            "source" => Plan::SOURCE_MARKETPLACE,
                            "target" => Plan::TARGET_MARKETPLACE,
                            "source_shop_id" => $sourceShopID,
                            "target_shop_id" => $targetId,
                            "app_tag" => Plan::APP_TAG,
                        ]
                    ];
                    $configObj->setUserId($userId);
                    $configObj->setConfig($config);
                    if ($addSettingsEnabledKey) {
                        $settingEnabledConfig = [
                            [
                                "key" => self::EMAIL_SEND_NOTIFICATIONS,
                                "value" => true,
                                "group_code" => self::EMAIL_GROUP_CODE,
                                "source" => Plan::SOURCE_MARKETPLACE,
                                "target" => Plan::TARGET_MARKETPLACE,
                                "source_shop_id" => $sourceShopID,
                                "target_shop_id" => $targetId,
                                "app_tag" => Plan::APP_TAG
                            ]
                        ];
                        $configObj->setConfig($settingEnabledConfig);
                    }
                }
            }

            return true;
        }

        return false;
    }

    /**
     * to check if we can send mail or not
     * @param $mailType
     * @param bool $userId
     * @return bool
     */
    public function canSendMailAsPerSetting($mailType, $userId = false, $userData = [])
    {
        $val = true;
        if ($mailType && !$this->isDefaultMail($mailType)) {
            if (!$userId) {
                $userId = $this->di->getUser()->id;
            }

            $configObj = $this->di->getObjectManager()->get(Config::class);
            $configObj->setGroupCode(self::EMAIL_GROUP_CODE);
            $configObj->setUserId($userId);
            $configObj->setAppTag(null);

            $notificationSetting = $configObj->getConfig(self::EMAIL_SEND_NOTIFICATIONS);
            if (!empty($notificationSetting) && isset($notificationSetting[0]['key'])) {
                if ($notificationSetting[0]['value']) {
                    $mailTypeSetting = $configObj->getConfig($mailType);
                    if (!empty($mailTypeSetting) && isset($mailTypeSetting[0]['value'])) {
                        $val = $mailTypeSetting[0]['value'];
                    } else {
                        $val = true;
                    }
                } else {
                    $val = false;
                }
            } else {
                $val = true;
            }
        }

        return $val;
    }

    /**
     * to check if the provided setting is default or not
     */
    public function isDefaultMail($code)
    {
        if (in_array($code, self::DEFAULT_MAILS)) {
            return true;
        }

        return false;
    }

    /**
     * to send plan activation mail
     */
    public function  sendPlanActivationMail($planDetails = [], $userId = false)
    {
        $mailData['setting_name'] = self::PLAN_ACTIVATED;
        $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'PlanActivated.volt';
        $mailData['price'] = $planDetails['custom_price'];
        $mailData['plan_details'] = $planDetails;
        $mailData['features'] = $planDetails['features'] ?? [];
        $mailData['subject'] = 'Plan activated Successfully';
        return $this->sendMail($mailData, $userId);
    }

    /**
     * to send payment success mail
     */
    public function  sendPaymentSuccessMail($paymentData = [], $userId = false)
    {
        $mailData['setting_name'] = self::PAYMENT_ACCEPTED;
        $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'PaymentSuccessful.volt';
        $mailData['date'] = date('Y-m-d');
        $mailData['price'] = $paymentData['price'];
        $mailData['plan_name'] = $paymentData['name'];
        $mailData['subject'] = 'Payment Acknowledgement for '. $paymentData['name'];
        return $this->sendMail($mailData, $userId);
    }

    /**
     * to send plan upgrade mail
     */
    public function  sendPlanUpgradeMail($mailData = [], $userId = false)
    {
        $mailData['setting_name'] = self::PLAN_UPGRADE;
        $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'PlanUpgraded.volt';
        $mailData['subject'] = 'Your '.$mailData['plan_details']['title'].' Has Been Successfully Upgraded';
        return $this->sendMail($mailData, $userId);
    }

    /**
     * to send mail on limit exceed
     */
    public function sendMailForOrderLimitExceed($serviceData, $userId = false)
    {
        if (isset($serviceData) && isset($serviceData['prepaid'])) {
            $res = $this->di->getObjectManager()->get(Common::class)->setDiRequesterAndTag($userId);
            if (!$res['success']) {
                return false;
            }

            $activePlan = $this->di->getObjectManager()->get(Plan::class)->getActivePlanForCurrentUser($userId);
            if (empty($activePlan) && !isset($activePlan['plan_details']['title'])) {
                return false;
            }

            if (!empty($activePlan) && $this->di->getObjectManager()->get(Plan::class)->isFreePlan($activePlan['plan_details']) && isset($activePlan['capped_amount']) && $activePlan['capped_amount']) {
                return false;
            }

            // $trial = false;
            // if (isset($activePlan['plan_details']['code']) && $activePlan['plan_details']['code'] == 'trial') {
            //     $trial = true;
            // }

            if (($serviceData['prepaid']['service_credits'] == $serviceData['prepaid']['total_used_credits'])
                && ($serviceData['postpaid']['capped_credit'] == $serviceData['postpaid']['total_used_credits'])
            ) {
                $this->sendOrderSyncPauseMail($userId);
            } else {
                $code = "";
                $mailData['plan_details'] = $activePlan['plan_details'];
                $isTrial = isset($activePlan['plan_details']['code']) && ($activePlan['plan_details']['code'] === 'trial' ) ? true : false;
                $sendMailForOrderLimit = false;
                $limitUsageRes = $this->calculateCreditPercentage($serviceData['prepaid']['service_credits'], $serviceData['prepaid']['total_used_credits']);
                if ($limitUsageRes['success'] && isset($limitUsageRes['code'])) {
                    $code = $limitUsageRes['code'];
                    $sendMailForOrderLimit = true;
                }

                $settingEnable = false;
                $emailLimitSetting = null;
                if ($sendMailForOrderLimit && !empty($code)) {
                    if ($this->isDefaultMail($code)) {
                        $settingEnable = true;
                        $mailData['setting_name'] = $code;
                    } else {
                        $limitMailSettings = [
                            self::SEND_MAIL_FOR_LIMIT_EXCEED_90 => self::CREDIT_CONSUMED_90,
                            self::SEND_MAIL_FOR_LIMIT_EXCEED_50 => self::CREDIT_CONSUMED_50
                        ];
                        foreach ($limitMailSettings as $setting => $credit) {
                            if ($code == $credit) {
                                $emailLimitSetting = $setting;
                                $settingEnable = $this->isSettingEnableForLimitExceed($userId, $setting);
                            }
                        }

                        $mailData['setting_name'] = self::SEND_MAIL_ON_LIMIT_EXCEED;
                    }

                    if ($settingEnable) {
                        $percentage = 0;
                        if ($code == self::CREDIT_CONSUMED) {
                            $percentage = 100;
                            $path = 'plan' . DS . 'view' . DS . 'email' . DS . 'CreditConsumed100.volt';
                            $subject = 'Order Limit Exhausted';
                        } elseif ($code == self::CREDIT_CONSUMED_90) {
                            $percentage = 90;
                            $path = 'plan' . DS . 'view' . DS . 'email' . DS . 'CreditConsumed90.volt';
                            $subject = '90% of Order Limit Reached';
                            if ($isTrial) {
                                $path = 'plan' . DS . 'view' . DS . 'email' . DS . 'CreditConsumed90Trial.volt';
                                $subject = 'Urgent: Action Required to Continue Order Syncing';
                            }
                        } elseif ($code == self::CREDIT_CONSUMED_80) {
                            $percentage = 80;
                            $path = 'plan' . DS . 'view' . DS . 'email' . DS . 'CreditConsumed80.volt';
                            $subject = '80% Of Your App Credits Have Been Used Up!';
                            $sendMailForOrderLimit = false; //to disable 80% mail
                        } elseif ($code == self::CREDIT_CONSUMED_70) {
                            $percentage = 70;
                            $path = 'plan' . DS . 'view' . DS . 'email' . DS . 'CreditConsumed70.volt';
                            $subject = 'You have used 70% of order credits!';
                            $sendMailForOrderLimit = false; //to disable 70% mail
                        } elseif ($code == self::CREDIT_CONSUMED_50) {
                            $percentage = 50;
                            $path = 'plan' . DS . 'view' . DS . 'email' . DS . 'CreditConsumed50.volt';
                            $subject = '50% of Order Limit Reached';
                        }

                        if ($sendMailForOrderLimit && !$this->isMailAlreadySend($userId, $code)) {
                            $mailData['path'] = $path;
                            $mailData['subject'] = $subject;
                            $mailData['has_recommended_plan_link'] = false;
                            $recommendedPlan = [];
                            if (self::SEND_RECOMMENDATIONS_IN_MAIL) {
                                $recommendedPlanInfo = $this->di->getObjectManager()->get(Recommendations::class)->getPersonalizedRecommendations($userId);
                                $recommendedPlan = (isset($recommendedPlanInfo['plans']) && !empty($recommendedPlanInfo['plans'])) ? $recommendedPlanInfo['plans'] : [];
                            }
                            if (!empty($recommendedPlan) && is_array($recommendedPlan) && !isset($recommendedPlan['custom'])) {
                                $mailData['recommended_plan'] = [];
                                foreach ($recommendedPlan as $plan) {
                                    if (isset($plan['plan_id'])) {
                                        $mailData['recommended_plan_name'] = $plan['title'];
                                        $discountAmt = $this->di->getObjectManager()->create(Common::class)->getDiscountedAmount($plan);
                                        $plan['offered_price'] = $discountAmt;
                                        $quoteId = $this->di->getObjectManager()->get(Plan::class)->setQuoteDetails(Plan::QUOTE_STATUS_ACTIVE, $userId, $plan['plan_id'], $plan, Plan::QUOTE_TYPE_PLAN);
                                        if (isset($plan['billed_type']) && $plan['billed_type'] == Plan::BILLED_TYPE_YEARLY) {
                                            $plan['montly_price'] = round(($plan['custom_price'] / 12), 3);
                                            $plan['discounted_price'] = $discountAmt;
                                            $plan['monthly_discounted_price'] = round((($plan['discounted_price']) / 12), 3);
                                        }

                                        $recommenedPlanLink = $this->di->getObjectManager()->get(Plan::class)->generateCustomPaymentLinkAsPerQuoteId($quoteId);
                                        if ($recommenedPlanLink['success'] && isset($recommenedPlanLink['payment_link'])) {
                                            $mailData['has_recommended_plan_link'] = true;
                                            $mailData['recommended_plan'][] = ['plan_data' => $plan, 'link' => $recommenedPlanLink['payment_link']];
                                        }
                                    }
                                }
                            }

                            $mailData['has_unsubscribe_link'] = false;
                            $unsubscribeLink = $this->getUnsubscribeLink($userId, $emailLimitSetting);
                            if (isset($unsubscribeLink['success']) && $unsubscribeLink['success']) {
                                $mailData['has_unsubscribe_link'] = true;
                                $mailData['unsubscribe_link'] = $unsubscribeLink['data'];
                            }

                            $mailResponse = $this->sendMail($mailData, $userId);
                            if ($percentage) {
                                $bulletinData = [
                                    'bulletinItemType' =>'PLAN_CREDIT_LIMIT_EXHAUST',
                                    'bulletinItemParameters' => ['usedCreditPercentage' => $percentage, 'usageAsOnDate' => date('Y-m-d')],
                                ];
                                $this->sendBulletin($bulletinData, $userId);
                            }

                            if ($mailResponse['success']) {
                                $this->setEmailConfig($userId, $code, true);
                            }
                        }
                    }
                    //event for credit limit exhausted
                    if ($code == self::ORDER_LIMIT_EXCEEDED || $code == self::CREDIT_CONSUMED) {
                        $eventData = [
                            'user_id' => $userId ?? $this->di->getUser()->id,
                            'username' => $this->di->getUser()->username,
                            'shopJson' => json_encode($this->di->getUser()->shops[0] ?? []),
                            'limit_exhausted' => true
                        ];
                        $this->di->getEventsManager()->fire('application:creditLimitExhausted', $this, $eventData);
                    }
                }
            }
        }
    }

    /**
     * to send sync pause mail
     */
    public function sendOrderSyncPauseMail($userId =  false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        if ($this->isMailAlreadySend($userId, self::SYNCING_STOPPED)) {
            return true;
        }

        $mailResponse = $this->notifySellersForPlanPause($userId);
        if ($mailResponse['success']) {
            $this->setEmailConfig($userId, self::SYNCING_STOPPED, true);
        }

        return true;
    }

    /**
     * to send mail for plan deactivation warning
     */
    public function sendMailForPlanDeactivationWarning($userId, $serviceAboutToInactivate, $activePlan = [], $lastMail = false)
    {
        $planDetails = $activePlan['plan_details'] ?? [];
        $mailData['setting_name'] = Email::PLAN_DEACTIVATION_WARNING;
        $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'DeactivationWarning.volt';
        $mailData['price'] = $planDetails['custom_price'];
        $mailData['plan_details'] = $planDetails;
        $mailData['deactivate_on'] = $serviceAboutToInactivate['deactivate_on'];
        $mailData['last_day'] = false;
        if ($lastMail) {
            $mailData['last_day'] = true;
        }

        $mailData['subject'] = 'Attention Required! Upgrade your plan';
        return $this->sendMail($mailData, $userId);
    }

    /**
     * to send mail on recurring cancel - currently not in use
     */
    public function sendMailForRecurringCancel($userId = false, $paymentDetail = [])
    {
        $mailData['setting_name'] = self::RECURRING_CANCELLED;
        $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'PlanDeactivated.volt';
        $mailData['chargeId'] = $paymentDetail['marketplace_data']['id'];
        $mailData['subject'] = 'Plan De-activated';
        return $this->sendMail($mailData, $userId);
    }

    /**
     * to send mail for custom payment success
     */
    public function sendMailForCustomPaymentSucess($paymentData, $userId)
    {
        $mailData['setting_name'] = self::PAYMENT_ACCEPTED;
        $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'CustomPaymentSuccess.volt';
        $mailData['date'] = date('Y-m-d');
        $mailData['price'] = $paymentData['price'];
        $mailData['payment_name'] = $paymentData['name'];
        $mailData['subject'] = 'Payment Done Successfully!';
        return $this->sendMail($mailData, $userId);
    }

    /**
     * to send settlement success mail
     */
    public function sendSettlmentSuccessMail($paymentData, $userId)
    {
        $mailData['setting_name'] = Email::SETTLEMENT_SUCCESS_MAIL;
        $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'SettlementPaid.volt';
        $mailData['subject'] = 'Excess Usage Charge Paid Successfully!';
        $mailData['payment_data'] = $paymentData;
        return $this->di->getObjectManager()->get(Email::class)->sendMail($mailData, $userId);
    }

    /**
     * to reset email config
     */
    public function resetEmailConfig($userId)
    {
        $this->setEmailConfig($userId, self::ORDER_LIMIT_EXCEEDED, false);
        $this->setEmailConfig($userId, self::SYNCING_STOPPED, false);
        $this->setEmailConfig($userId, self::CAPPED_USAGE_90, false);
        foreach (self::SUPPORTED_MAIL_LIMITS as $limitCode) {
            $this->setEmailConfig($userId, $limitCode, false);
        }

        return true;
    }

    /**
     * to notify sellers for their plan pause
     */
    public function notifySellersForPlanPause($userId = false)
    {
        $mailRes = ['success' => false, 'message' => 'Failed to send mail!'];
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $recommendation = $this->di->getObjectManager()->get(Recommendations::class);
        $commonObj = $this->di->getObjectManager()->get(Common::class);
        $paymentCollection = $baseMongo->getCollectionForTable(Plan::PAYMENT_DETAILS_COLLECTION_NAME);
        $previousMonthLastDate = date("Y-m-d", strtotime("last day of previous month"));

        $queryForPlanPauseServices = [
            'type' => Plan::PAYMENT_TYPE_USER_SERVICE,
            'service_type' => Plan::SERVICE_TYPE_ORDER_SYNC,
            '$or' => [
                [
                    'prepaid.available_credits' => 0,
                    'postpaid.available_credits' => 0
                ],
                [
                    'prepaid.available_credits' => 0,
                    'postpaid.total_used_credits' => ['$gt' => 0],
                    'expired_at' => ['$lte' => $previousMonthLastDate]
                ]
            ]
        ];


        if ($userId) {
            $queryForPlanPauseServices['user_id'] = $userId;
        }

        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'projection' => ['user_id' => 1, 'trial_service' => 1, 'postpaid' => 1]
        ];
        $usersWithPausePlan = $paymentCollection->find($queryForPlanPauseServices, $options);
        if (!empty($usersWithPausePlan)) {
            $usersWithPausePlan = $usersWithPausePlan->toArray();
        }
        if (!empty($usersWithPausePlan)) {
            foreach ($usersWithPausePlan as $user) {
                $userId = $user['user_id'] ?? '';
                $res = $commonObj->setDiRequesterAndTag($userId);
                if (!$res['success']) {
                    continue;
                }

                if (!empty($user['postpaid']) && isset($user['postpaid']['is_capped']) && $user['postpaid']['is_capped'] && ($user['postpaid']['available_credits'] == 0 && $user['prepaid']['available_credits'] == 0)) {
                    $activePlan = $planModel->getActivePlanForCurrentUser($userId);
                    $mailData['plan_details'] = $activePlan['plan_details'] ?? [];
                    $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'SyncPauseCapped.volt';
                    // $mailData['subject'] = 'Alert: Order Syncing Paused';
                    $mailData['subject'] = 'Action Required : Order Syncing Paused';
                    $mailData['date'] = date("Y-m-d");
                    $mailData['has_settlement'] = false;
                    $mailData['has_recommended_plan_link'] = false;
                    $mailRes = $this->sendMail($mailData, $userId);
                    $bulletinData = [
                        'bulletinItemType' =>'SYNCING_PAUSED',
                        'bulletinItemParameters' => [],
                    ];
                    $this->sendBulletin($bulletinData, $userId);
                    continue;
                }
                $sourceShopId = $commonObj->getShopifyShopId();
                $settlementInvoice = $planModel->getPendingSettlementInvoice($userId);
                $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'SyncPause.volt';
                // $mailData['subject'] = 'Important: Action Required to Resume Order Syncing';
                $mailData['subject'] = 'Action Required : Order Syncing Paused';
                $mailData['date'] = date("Y-m-d");
                $quoteId = null;
                $mailData['has_settlement'] = false;
                $mailData['has_recommended_plan_link'] = false;
                if (!empty($settlementInvoice)) {
                    $quoteId = $settlementInvoice['quote_id'];
                    $mailData['settlement_invoice'] = $settlementInvoice;
                    $mailData['has_settlement'] = true;
                    $data = $planModel->generateCustomPaymentLinkAsPerQuoteId($quoteId, Plan::QUOTE_TYPE_SETTLEMENT);
                    if ($data['success'] && isset($data['payment_link'])) {
                        $mailData['settlement_link'] = $data['payment_link'];
                    }

                    $mailData['subject'] = 'Priority Alert: Your Order Syncing Has Been Paused';
                } else {
                    $recommendedPlan = [];
                    if (self::SEND_RECOMMENDATIONS_IN_MAIL) {
                        $recommendedPlanInfo = $recommendation->getPersonalizedRecommendations($userId);
                        $recommendedPlan = (isset($recommendedPlanInfo['plans']) && !empty($recommendedPlanInfo['plans'])) ? $recommendedPlanInfo['plans'] : [];
                    }
                    if (!empty($recommendedPlan) && is_array($recommendedPlan) && !isset($recommendedPlan['custom'])) {
                        $mailData['recommended_plan'] = [];
                        foreach ($recommendedPlan as $plan) {
                            if (isset($plan['plan_id'])) {
                                $mailData['recommended_plan_name'] = $plan['title'];
                                $discountAmt = $commonObj->getDiscountedAmount($plan);
                                $plan['offered_price'] = $discountAmt;
                                $quoteId = $planModel->setQuoteDetails(Plan::QUOTE_STATUS_ACTIVE, $userId, $plan['plan_id'], $plan, Plan::QUOTE_TYPE_PLAN);
                                if (isset($plan['billed_type']) && $plan['billed_type'] == Plan::BILLED_TYPE_YEARLY) {
                                    $plan['montly_price'] = round(($plan['custom_price'] / 12), 3);
                                    $plan['discounted_price'] = $discountAmt;
                                    $plan['monthly_discounted_price'] = round((($plan['discounted_price']) / 12), 3);
                                }

                                $recommenedPlanLink = $planModel->generateCustomPaymentLinkAsPerQuoteId($quoteId);
                                if ($recommenedPlanLink['success'] && isset($recommenedPlanLink['payment_link'])) {
                                    $mailData['has_recommended_plan_link'] = true;
                                    $mailData['recommended_plan'][] = ['plan_data' => $plan, 'link' => $recommenedPlanLink['payment_link']];
                                }
                            }
                        }
                    }
                }

                if (isset($user['trial_service']) && $user['trial_service']) {
                    $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'TrialExhaust.volt';
                }

                $mailRes = $this->sendMail($mailData, $userId);
                $bulletinData = [
                    'bulletinItemType' =>'SYNCING_PAUSED',
                    'bulletinItemParameters' => [],
                ];
                $this->sendBulletin($bulletinData, $userId);
            }

            return $mailRes;
        }

        return [
            'success' => false,
            'message' => 'No user to send mail'
        ];
    }

    public function notifySellersForPlanDeactivation($userId = false): void
    {
        $commonObj = $this->di->getObjectManager()->get(Common::class);
        $weekDate = date('Y-m-d', strtotime('+1 week'));//the date after a week
	    $tomorrowDate = date('Y-m-d', strtotime('+1 day'));//starting from one day more than current
        $currentDate = date('Y-m-d');

        $queryForPlanDeactivatingAfterAWeek = [
            'type' => Plan::PAYMENT_TYPE_USER_SERVICE,
            'deactivate_on' => ['$exists' => true],
            'deactivate_on' => ['$lte' => $weekDate, '$gt' =>$tomorrowDate]
        ];

        $queryForPlanDeactivatingTomorrow = [
            'type' => Plan::PAYMENT_TYPE_USER_SERVICE,
            'deactivate_on' => ['$exists' => true],
            'deactivate_on' => $tomorrowDate
        ];

        if($userId) {
            $queryForPlanDeactivatingAfterAWeek['user_id'] = $userId;
            $queryForPlanDeactivatingTomorrow['user_id'] = $userId;
        }

        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $servicesAboutToInactivateInAWeek = $planModel->paymentCollection->find($queryForPlanDeactivatingAfterAWeek, Plan::TYPEMAP_OPTIONS)->toArray();
        $servicesAboutToInactivateTomorrow = $planModel->paymentCollection->find($queryForPlanDeactivatingTomorrow, Plan::TYPEMAP_OPTIONS)->toArray();

        if(!empty($servicesAboutToInactivateInAWeek)) {
            foreach($servicesAboutToInactivateInAWeek as $serviceAboutToInactivate) {
                if(isset($serviceAboutToInactivate['user_id'])) {
                    $userId = $serviceAboutToInactivate['user_id'];
                    $res = $commonObj->setDiRequesterAndTag($userId);
                    if (!$res['success']) {
                        continue;
                    }

                    $activePlan = $planModel->getActivePlanForCurrentUser($userId);
                    if(!empty($activePlan) && !isset($activePlan['week_warning_mail_send'])) {
                        $emailRes = $this->sendMailForPlanDeactivationWarning($userId, $serviceAboutToInactivate, $activePlan);
                        if($emailRes['success']) {
                            $planModel->paymentCollection->updateOne(
                                [
                                    '_id' => $activePlan['_id'],
                                    'type' => Plan::PAYMENT_TYPE_ACTIVE_PLAN,
                                    'user_id' => $userId,
                                ],
                                [
                                    '$set' =>[
                                        'week_warning_mail_send' => true,
                                        'week_warning_mail_send_on' => $currentDate
                                    ]
                                ]
                            );
                        }

                        $bulletinData = [
                            'bulletinItemType' =>'PLAN_EXPIRE',
                            'bulletinItemParameters' => ['expireDate' => $serviceAboutToInactivate['deactivate_on'] ?? ""],
                        ];
                        $this->sendBulletin($bulletinData, $userId);
                    }
                }
            }
        }

        if(!empty($servicesAboutToInactivateTomorrow)) {
            foreach($servicesAboutToInactivateTomorrow as $serviceAboutToInactivateTomorrow) {
                if(isset($serviceAboutToInactivateTomorrow['user_id'])) {
                    $userId = $serviceAboutToInactivateTomorrow['user_id'];
                    $res = $commonObj->setDiRequesterAndTag($userId);
                    if (!$res['success']) {
                        continue;
                    }

                    $activePlan = $planModel->getActivePlanForCurrentUser($userId);
                    if(!empty($activePlan) && !isset($activePlan['last_warning_mail_send'])) {
                        $emailRes = $this->sendMailForPlanDeactivationWarning($userId, $serviceAboutToInactivateTomorrow, $activePlan, true);
                        if($emailRes['success']) {
                            $planModel->paymentCollection->updateOne(
                                [
                                    'type' => Plan::PAYMENT_TYPE_ACTIVE_PLAN,
                                    'user_id' => $userId,
                                    '_id' => $activePlan['_id']
                                ],
                                [
                                    '$set' =>[
                                        'last_warning_mail_send' => true,
                                        'last_warning_mail_send_on' => $currentDate
                                    ]
                                ]
                            );
                        }

                        $bulletinData = [
                            'bulletinItemType' =>'PLAN_EXPIRE',
                            'bulletinItemParameters' => ['expireDate' => $serviceAboutToInactivateTomorrow['deactivate_on'] ?? ""],
                        ];
                        $this->sendBulletin($bulletinData, $userId);
                    }
                }
            }
        }
    }

    public function sendBulletin($data, $userId = false)
    {
        $bulletinData = [
            'bulletinItemType' => $data['bulletinItemType'],
            'bulletinItemParameters' => $data['bulletinItemParameters'],
            'source_shop_id' => $this->di->getRequester()->getSourceId(),
            'process' => 'plan'
        ];
        if (class_exists('App\Amazon\Components\Bulletin\Bulletin')) {
            $bulletinComp = $this->di->getObjectManager()->get('App\Amazon\Components\Bulletin\Bulletin');
            if (method_exists($bulletinComp, 'createBulletin')) {
                foreach ($this->di->getUser()->shops as $shop) {
                    if (isset($shop['marketplace']) && $shop['marketplace'] == Plan::TARGET_MARKETPLACE) {
                        $bulletinData['home_shop_id'] = $shop['_id'] ?? null;
                        $bulletinComp->createBulletin($bulletinData);
                    }
                }
            }
        }

        return [
            'success'=> true,
            'message' => 'bulletin processed'
        ];
    }

    public function sendMailForPostPaidLimit($serviceData, $userId = false)
    {
        if (isset($serviceData) && isset($serviceData['postpaid']['is_capped']) && $serviceData['postpaid']['is_capped']) {
            $res = $this->di->getObjectManager()->get(Common::class)->setDiRequesterAndTag($userId);
            if (!$res['success']) {
                return false;
            }

            $activePlan = $this->di->getObjectManager()->get(Plan::class)->getActivePlanForCurrentUser($userId);
            if (empty($activePlan) && !isset($activePlan['plan_details']['title'])) {
                return false;
            }            
            if (isset($serviceData['postpaid']['capped_amount'], $serviceData['postpaid']['balance_used'])) {
                $mailData['setting_name'] = self::CAPPED_USAGE_90;
                $percentage = ($serviceData['postpaid']['balance_used'] / $serviceData['postpaid']['capped_amount']) * 100;
                $used = ($serviceData['postpaid']['capped_credit'] -  $serviceData['postpaid']['available_credits']) < 0 ? 0 : ($serviceData['postpaid']['capped_credit'] -  $serviceData['postpaid']['available_credits']);
                if (($percentage < 90) && ($used > 0)) {
                    $percentage = ($used/ $serviceData['postpaid']['capped_credit']) * 100;
                }
                if (($percentage >= 90) && !$this->isMailAlreadySend($this->di->getUser()->id, $mailData['setting_name'])) {
                    $existingUsage = ($serviceData['postpaid']['total_used_credits'] * $serviceData['postpaid']['per_unit_usage_price']) + $serviceData['postpaid']['balance_used'];
                    $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'Usage90Exhaust.volt';
                    // $mailData['subject'] = 'You’ve Reached 90% of Your Additional Order Cap Limit';
                    $mailData['subject'] = 'Action Recommended: You’re at 90% of Your Order Usage Cap';
                    $mailData['plan_details'] = $activePlan['plan_details'];
                    $mailData['used_balance'] = $existingUsage;
                    // $mailData['postpaid'] = $serviceData['postpaid'];
                    $mailResponse = $this->sendMail($mailData);
                    if ($mailResponse['success']) {
                        $this->setEmailConfig($userId, self::CAPPED_USAGE_90, true);
                    }
                }
            }
        }
    }

    public function handleSqsData($sqsData)
    {
        if (!empty($sqsData) && isset($sqsData['action']) && !empty($sqsData['data'])) {
            $this->{$sqsData['action']}($sqsData['data'], $sqsData['user_id']);
            return true;
        }
    }

    public function sendeMailUsingSqs($emailData, $userId, $action)
    {
        $emailSqsData = [
            'type' => 'full_class',
            'class_name' => '\App\Plan\Components\Email',
            'method' => 'handleSqsData',
            'appCode' => Plan::APP_TAG,
            'appTag' => Plan::APP_TAG,
            'queue_name' => 'email_event_trigger',
            'user_id' => $userId,
            'data' => $emailData,
            'action' => $action
        ];
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $sqsHelper->pushMessage($emailSqsData);
        return true;
    }
}
