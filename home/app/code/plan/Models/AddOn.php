<?php

namespace App\Plan\Models;

use App\Plan\Components\Common;
use App\Plan\Components\Method\ShopifyPayment;
use App\Plan\Models\Plan;
use App\Plan\Models\BaseMongo;

/**
 * Class Plan
 * @package App\Plan\Models
 */
class AddOn extends \App\Core\Models\BaseMongo
{
    public const PAYMENT_TYPE_QUOTE = 'quote';

    public const PAYMENT_TYPE_ACTIVE_PLAN = 'active_plan';

    public const ADD_ON_ACTIVE_PLAN = 'active_add_on';

    public const USER_ADD_ON_STATUS_ACTIVE = 'active';

    public const USER_ADD_ON_STATUS_INACTIVE = 'inactive';

    public const USER_ADD_ON_STATUS_EXPIRED = 'expired';

    public const USER_ADD_ON_STATUS_UNUSED = 'unused';

    public const PAYMENT_TYPE_MONTHLY_USAGE = 'monthly_usage';

    public const PAYMENT_TYPE_USER_SERVICE = 'user_service';

    public const PAYMENT_TYPE_PAYMENT = 'payment';

    public const QUOTE_STATUS_ACTIVE = 'active';

    public const QUOTE_STATUS_WAITING_FOR_PAYMENT = 'waiting_for_payment';

    public const QUOTE_STATUS_REJECTED = 'rejected';

    public const QUOTE_STATUS_APPROVED = 'approved';

    public const PAYMENT_STATUS_PENDING = 'pending';

    public const PAYMENT_STATUS_APPROVED = 'approved';

    public const PAYMENT_STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_STATUS_REFUND = 'refund';

    public const QUOTE_EXPIRE_AFTER = '+3 day';

    public const SOURCE_MARKETPLACE = 'shopify';

    public const TARGET_MARKETPLACE = 'amazon';

    public const PAYMENT_METHOD = 'shopify';

    public const PAYMENT_DETAILS_COLLECTION_NAME = 'payment_details';

    public const USER_DETAILS_COLLECTION_NAME = 'user_details';

    public const CONFIGURATION_COLLECTION_NAME = 'config';

    public const PLAN_COLLECTION_NAME = 'plan';

    public const PAYMENT_DETAILS_COUNTER_NAME = 'payment_id';

    public const USER_PLAN_STATUS_ACTIVE = 'active';

    public const USER_PLAN_STATUS_INACTIVE = 'inactive';

    protected $table = 'plan';

    public const SERVICE_TYPE_ORDER_SYNC = 'order_sync';

    public const DEFAULT_CAPPED_AMOUNT = 0.01;

    public const SETTLE_DAY_START_FROM = 2;

    public const SETTLE_DAY_END_AT = 'EOM';

    public const MARGIN_ORDER_COUNT = 25;

    public const RENEW_PLANS_BATCH_SIZE = 100;

    public const CONFIGURATION_COLLECTION = 'configuration';

    public const MARKETPLACE_GROUP_CODE = 'amazon_sales_channel';

    public const MARKETPLACE = 'shopify';

    public const FORCEFULLY_CANCELLED = 'forcefully_cancelled';

    public const ADD_ON_PAYMENT_TYPE = 'add_on_payment';

    public const ADD_ON_MAIL_NOTIFICATIONS = 'add_on_mails';

    public const ADD_ON_EXPIRE_MAIL = 'add_on_expire_mail';

    public const ADD_ON_ACTIVATION_MAIL = 'add_on_activation_mail';

    public const ADD_ON_PAYMENT_MAIL = 'add_on_payment_done';

    public function chooseAddon($data)
    {
        $userId = $this->di->getUser()->id;
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $addonDetails = $this->getAddOn($data['add_on_id']);
        if($addonDetails['success'] && !empty($addonDetails['data'])) {
            $addonDetails = $addonDetails['data'];
        } else {
            return [
                'success' => false,
                'message' => 'Provided add-on id not exist'
            ];
        }

        $activePlan = $planModel->getActivePlanForCurrentUser($userId);
        if (empty($activePlan)) {
            return ['success' => false, 'message' => 'You have no active plan we cannot proceed!'];
        }

        if (
            !empty($activePlan)
            && ($activePlan['plan_details']['custom_price'] == 0
                || $activePlan['plan_details']['title'] == 'Free')
        ) {
            return ['success' => false, 'message' => 'You cannot use this service with your free plan!'];
        }

        $data['add_on_details'] = $addonDetails;
        $data['type'] = 'onetime';
        $data['add_on_id'] = $addonDetails['add_on_id'];
        return $this->processAddOnPayment($data, $userId);
    }

    public function getAddOn($addOnId)
    {
        $mongo = $this->di->getObjectManager()->create(BaseMongo::class);
        $collection = $mongo->getCollection("plan");
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        if ($addOnId) {
            $addOnData = $collection->findOne(['add_on_id' => $addOnId], $options);
        } else {
            return ['success' => false, 'code' => 'missing_required_params', 'message' => ''];
        }

        return ['success' => true, 'data' => $addOnData];
    }

    public function processAddOnPayment($data, $userId = false)
    {
        $this->di->getLog()->logContent(
            '--- ADD ON CHOOSE PLAN PAYMENT ---',
            'info',
            'plan/addOnCheck/' . date('Y-m-d') . '.log'
        );
        $response = [];
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $shop = $this->di->getObjectManager()->get(Common::class)->getShop($this->di->getRequester()->getSourceId(), $userId);
        if (empty($shop) || !isset($shop['remote_shop_id'])) {
            return [
                'success' => false,
                'message' => 'Shop not found'
            ];
        }

        $data['shop_id'] = $shop['remote_shop_id'];
        $homeUrl = $this->di->getConfig()->base_url_frontend;
        if ($data['type'] == 'onetime') {
            $quoteId = $planModel->setQuoteDetails(
                self::QUOTE_STATUS_WAITING_FOR_PAYMENT,
                $userId,
                $data['add_on_id'],
                $data['add_on_details']
            );
            $this->setAddOnPaymentDetails($userId, self::PAYMENT_STATUS_PENDING, $quoteId, []);
            $test = false;
            if (in_array($userId, $this->di->getConfig()->get('testUserIds')->toArray())) {
                $test = true;
            }

            $plan = [
                'name' => "Add on plan",
                'price' => $data['add_on_details']['custom_price'],
                'test' => $test,
                'return_url' => $homeUrl . 'plan/plan/OneTimePaymentCheck?quote_id=' . $quoteId . '&shop=' .
                    $shop['domain'] . '&state=' . $userId . '&source_id=' . $this->di->getRequester()->getSourceId() .
                    '&app_tag=' . $this->di->getAppCode()->getAppTag() . '&plan_type=add_on'
            ];
            $data['data'] = $plan;
            $this->di->getLog()->logContent(
                'Data sent for onetime add-on payment => ' . json_encode($data),
                'info',
                'plan/addOnCheck/' . date('Y-m-d') . '.log'
            );
            $remoteResponse = $this->di->getObjectManager()->get(ShopifyPayment::class)->initApiClient()
                ->call('billing/onetime', [], $data, 'POST');

            $this->di->getLog()->logContent(
                'Stage 1: for user_id ' . $userId . PHP_EOL .
                    'Remote response for onetime add-on payment => ' . json_encode($remoteResponse),
                'info',
                'plan/addOnCheck/' . date('Y-m-d') . '.log'
            );
            if ($remoteResponse['success']) {
                if (isset($remoteResponse['data']['confirmation_url'])) {
                    $confirmationUrl = $remoteResponse['data']['confirmation_url'];
                $toReturnData['confirmation_url'] = $confirmationUrl;
                $response = ['success' => true, 'data' => $toReturnData];
                } else {
                    $response = ["success" => false, "message" => "confirmation_url not found from remote response!"];
                }
            } else {
                $response = ["success" => false, "message" => "Request unsuccessful!"];
            }
        } else {
            $response = ["success" => false, "message" => "type not found!"];
        }

        return $response;
    }

    public function activateAddOn($userId, $quoteId, $planType, $response)
    {
        $this->di->getLog()->logContent(
            'Stage 2: user_id ' . $userId,
            'info',
            'plan/addOnCheck/' . date('Y-m-d') . '.log'
        );
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $userService = $planModel->getUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
        if (!empty($userService)) {
            $quoteData = $planModel->getQuoteByQuoteId($quoteId);
            if (!empty($quoteData)) {
                $quoteId = $planModel->setQuoteDetails(
                    Plan::QUOTE_STATUS_APPROVED,
                    $userId,
                    $quoteData['plan_details']['add_on_id'],
                    $quoteData['plan_details']
                );
            }

            $setAddOnResponse = $this->setAddOnPaymentDetails(
                $userId,
                Plan::PAYMENT_STATUS_APPROVED,
                $quoteId,
                $response
            );
            $this->di->getLog()->logContent(
                'setAddOnPaymentDetails response => ' . json_encode($setAddOnResponse),
                'info',
                'plan/addOnCheck/' . date('Y-m-d') . '.log'
            );
            $mailResponse = $this->sendPaymentSuccessMail($userId);
            $this->di->getLog()->logContent(
                'sendPaymentSuccessMail response => ' . json_encode($mailResponse),
                'info',
                'plan/addOnCheck/' . date('Y-m-d') . '.log'
            );
            $createActiveAddOnResponse = $this->createActiveAddOnPlan(
                $response['price'],
                $quoteId,
                self::USER_ADD_ON_STATUS_UNUSED,
                $userId
            );
            $this->di->getLog()->logContent(
                'createActiveAddOnPlan response => ' . json_encode($createActiveAddOnResponse),
                'info',
                'plan/addOnCheck/' . date('Y-m-d') . '.log'
            );
            $addOnSyncResponse = $this->addOnSync($userId);
            $this->di->getLog()->logContent(
                'addOnSyncResponse response => ' . json_encode($addOnSyncResponse),
                'info',
                'plan/addOnCheck/' . date('Y-m-d') . '.log'
            );
        } else {
            return [
                'success' => false,
                'message' => 'User service not found'
            ];
        }
    }

    public function setAddOnPaymentDetails($userId, $paymentStatus, $quoteId, $markteplaceData = [])
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $quoteData = $planModel->getQuoteByQuoteId($quoteId);
        if ($quoteData) {
            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $collection = $baseMongo->getCollectionForTable(self::PAYMENT_DETAILS_COLLECTION_NAME);
            $paymentData = $this->getAddOnPaymentDetails($quoteId);
            $currentDate = date('Y-m-d H:i:s');
            if (!empty($paymentData)) {
                $paymentData['status'] = $paymentStatus;
                $paymentData['updated_at'] = $currentDate;
                $paymentData['marketplace_data'] = $markteplaceData;
                $paymentData['source_marketplace'] = self::SOURCE_MARKETPLACE;
                $paymentData['target_marketplace'] = self::TARGET_MARKETPLACE;
                $paymentData['updated_at'] = $currentDate;
            } else {
                $paymentData = [
                    '_id' => (string)$baseMongo->getCounter(self::PAYMENT_DETAILS_COUNTER_NAME),
                    'type' => self::ADD_ON_PAYMENT_TYPE,
                    'user_id' => $userId,
                    'status' => $paymentStatus,
                    'plan_id' => (string)$quoteData['plan_id'],
                    'quote_id' => (string)$quoteData['_id'],
                    // 'source_id' => (string)$quoteData['source_id'],
                    // 'app_tag' => (string)$quoteData['app_tag'],
                    'source_marketplace' => self::SOURCE_MARKETPLACE,
                    'target_marketplace' => self::TARGET_MARKETPLACE,
                    'method' => self::PAYMENT_METHOD,
                    'marketplace_data' => $markteplaceData,
                    'created_at' => $currentDate,
                    'updated_at' => $currentDate
                ];
            }

            $mongoResult = $collection->replaceOne(
                ['_id' => $paymentData['_id']],
                $paymentData,
                ['upsert' => true]
            );
            if ($mongoResult->isAcknowledged()) {
                return $paymentData['_id'];
            }
        }

        return false;
    }

    public function getAddOnPaymentDetails($quoteId)
    {
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $collection = $baseMongo->getCollectionForTable(self::PAYMENT_DETAILS_COLLECTION_NAME);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $filterData = [
            "type" => self::ADD_ON_PAYMENT_TYPE,
            "quote_id" => $quoteId
        ];
        return $collection->findOne($filterData, $options);
    }

    public function createActiveAddOnPlan($price, $quoteId, $status, $userId = null)
    {
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $quoteData = $planModel->getQuoteByQuoteId($quoteId);
        if ($userId == null) {
            $userId = $quoteData['user_id'];
        }

        $userService = $planModel->getUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
        if (empty($userService)) {
            return [
                'success' => false,
                'message' => 'No user services found!'
            ];
        }

        if (empty($quoteData)) {
            return [
                'success' => false,
                'message' => 'Quote Data Not Found'
            ];
        }

        $currentDate = date('Y-m-d H:i:s');
        $expiryDate = date('Y-m-d', strtotime($userService['expired_at'] . ' + 2 days'));
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $collection = $baseMongo->getCollectionForTable(self::PAYMENT_DETAILS_COLLECTION_NAME);
        $id = (string)$baseMongo->getCounter(self::PAYMENT_DETAILS_COUNTER_NAME);
        $activePlanData = [
            '_id' => $id,
            'user_id' => (string)$userId,
            'type' => self::ADD_ON_ACTIVE_PLAN,
            'marketplace' => self::TARGET_MARKETPLACE,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            'status' => $status,
            'quote_id' => $quoteId,
            'add_on_amount' => (float)$price,
            'created_at' => $currentDate,
            'updated_at' => $currentDate,
            'expired_at' => $expiryDate,
            'plan_details' => $quoteData['plan_details'],
        ];
        $testUsers = $this->di->getConfig()->get('testUserIds');
        if ($testUsers && in_array($userId, $testUsers->toArray())) {
            $activePlanData['test_user'] = true;
        }

        $collection->insertOne($activePlanData);
        return $id;
    }

    public function addOnSync($userId = null, $addOnCreditLimit = 0)
    {
        if (is_null($userId)) {
            $userId = $this->di->getUser()->id;
        }

        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $activeAddOn = $this->getActiveUserAddOns($userId);
        $userService = $planModel->getUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $collection = $baseMongo->getCollectionForTable(self::PAYMENT_DETAILS_COLLECTION_NAME);
        if (empty($userService)) {
            $response = [
                'success' => false,
                'message' => 'No user services found!'
            ];
        } else {
            if ((isset($userService['add_on']['available_credits'])
                    && ($userService['add_on']['available_credits'] == $addOnCreditLimit)
                ) || (!isset($userService['add_on']['available_credits']))
            ) {
                $unusedUserAddOns = $this->getUnusedUserAddOns($userId);
                if (empty($unusedUserAddOns)) {
                    $response = [
                        'success' => false,
                        'message' => 'No ADD_ON to activate!'
                    ];
                } else {
                    if (!empty($activeAddOn)) {
                        $activeAddOn = $activeAddOn[0];
                        $currentDate = date('Y-m-d H:i:s');
                        $collection->updateOne(
                            [
                                '_id' => $activeAddOn['_id']
                            ],
                            [
                                '$set' => [
                                    'status' => self::USER_ADD_ON_STATUS_EXPIRED,
                                    'updated_at' => $currentDate
                                ]
                            ]
                        );
                        $this->sendDeactivationMail($activeAddOn, $userId);
                    }

                    $addOnToActivate = $unusedUserAddOns[0];
                    $addOnData = [
                        'add_on' => [
                            'service_credits' => (int)$addOnToActivate['plan_details']['services_groups'][0]['services'][0]['prepaid']['service_credits'],
                            'available_credits' => (int)$addOnToActivate['plan_details']['services_groups'][0]['services'][0]['prepaid']['service_credits'] + (int)$addOnCreditLimit,
                            'total_used_credits' => 0,
                            'expired_at' => $addOnToActivate['expired_at'],
                            'active_add_on_id' => $addOnToActivate['_id']
                        ]
                    ];

                    $options = ["typeMap" => ['root' => 'array', 'document' => 'array'], "returnDocument" => 2];
                    $updatedUserService = $collection->findOneAndUpdate(
                        ['_id' => $userService['_id']],
                        ['$set' => $addOnData],
                        $options
                    );
                    $collection->updateOne(
                        ['_id' => $addOnToActivate['_id']],
                        ['$set' => ['status' => self::USER_ADD_ON_STATUS_ACTIVE]]
                    );
                    $this->sendActivationMail($addOnToActivate, $userId);
                    return [
                        'success' => true,
                        'message' => 'ADD_ON activated',
                        'updatedUserService' => $updatedUserService
                    ];
                }
            } else {
                $response = [
                    'success' => true,
                    'message' => 'Addon is already activated!'
                ];
            }
        }

        return $response;
    }

    public function getActiveUserAddOns($userId)
    {
        return $this->getUserAddOns($userId, self::USER_ADD_ON_STATUS_ACTIVE);
    }

    public function getExpiredUserAddOns($userId)
    {
        return $this->getUserAddOns($userId, self::USER_ADD_ON_STATUS_EXPIRED);
    }

    public function getUnusedUserAddOns($userId)
    {
        return $this->getUserAddOns($userId, self::USER_ADD_ON_STATUS_UNUSED);
    }

    public function getAllUserAddOns($userId)
    {
        return $this->getUserAddOns($userId);
    }

    public function getUserAddOns($userId, $status = null)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $aggregate[] = [
            '$sort' => ['created_at' => 1]
        ];
        $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $baseMongo->getCollectionForTable(self::PAYMENT_DETAILS_COLLECTION_NAME);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $activePlanFilterData = [
            'user_id' => (string)$userId,
            'type' => self::ADD_ON_ACTIVE_PLAN,
            'marketplace' => self::TARGET_MARKETPLACE,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            // 'source_id' => (string)$this->getSourceId(),
            // 'app_tag' => (string)$this->getAppTag()
        ];
        if (!is_null($status)) {
            $activePlanFilterData['status'] = $status;
        }

        $aggregate[] = [
            '$match' => $activePlanFilterData
        ];
        return $collection->aggregate($aggregate, $options)->toArray();
    }

    public function sendDeactivationMail($addOnData, $userId)
    {
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $mailData['add_on_id'] = $addOnData['_id'];
        $mailData['title'] = $addOnData['plan_details']['title'];
        $mailData['subject'] = 'Add On Expired!';
        $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'OrderLimitExceeded.volt';
        $mailData['setting_name'] = self::ADD_ON_EXPIRE_MAIL;
        return $planModel->sendMail($mailData, $userId);
    }

    public function sendActivationMail($addOnData, $userId)
    {
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $mailData['add_on_id'] = $addOnData['_id'];
        $mailData['title'] = $addOnData['plan_details']['title'];
        $mailData['subject'] = 'Add On Activated!';
        $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'OrderLimitExceeded.volt';
        $mailData['setting_name'] = self::ADD_ON_EXPIRE_MAIL;
        return $planModel->sendMail($mailData, $userId);
    }

    public function sendPaymentSuccessMail($userId)
    {
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $mailData['subject'] = 'Add On Payment successfully done!';
        $mailData['path'] = 'plan' . DS . 'view' . DS . 'email' . DS . 'PaymentAccepted.volt';
        $mailData['setting_name'] = self::ADD_ON_PAYMENT_MAIL;
        return $planModel->sendMail($mailData, $userId);
    }
}
