<?php

namespace App\Plan\Models;

use App\Plan\Components\Helper;
use App\Plan\Models\Plan\Services;
use App\Connector\Models\QueuedTasks;
use DateTime;
use App\Connector\Components\Dynamo;
use App\Plan\Components\Common;
use App\Plan\Models\BaseMongo as BaseMongo;
use App\Plan\Components\Transaction;
use App\Plan\Components\Email;
use App\Plan\Components\Method\ShopifyPayment;
use App\Plan\Components\PlanEvent;
use App\Plan\Components\Recommendations;
use Aws\DynamoDb\Marshaler;
use Exception;

/**
 * Class Plan
 * @package App\Plan\Models
 */
#[\AllowDynamicProperties]
class Plan extends BaseMongo
{

    public const APP_TAG = 'amazon_sales_channel';

    public const APP_NAME = 'CedCommerce Amazon Channel';

    public const PAYMENT_TYPE_QUOTE = 'quote';

    public const PAYMENT_TYPE_ACTIVE_PLAN = 'active_plan';

    public const PAYMENT_TYPE_MONTHLY_USAGE = 'monthly_usage';

    public const PAYMENT_TYPE_USER_SERVICE = 'user_service';

    public const PAYMENT_TYPE_PAYMENT = 'payment';

    public const QUOTE_STATUS_ACTIVE = 'active';

    public const QUOTE_STATUS_WAITING_FOR_PAYMENT = 'waiting_for_payment';

    public const QUOTE_STATUS_REJECTED = 'rejected';

    public const QUOTE_STATUS_APPROVED = 'approved';

    public const PAYMENT_STATUS_PENDING = 'pending';

    public const PAYMENT_STATUS_APPROVED = 'approved';

    public const PAYMENT_STATUS_ON_HOLD = 'on_hold';

    public const PAYMENT_STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_STATUS_REFUND = 'refund';

    public const QUOTE_EXPIRE_AFTER = '+3 day';

    public const SETTLEMENT_QUOTE_EXPIRE_AFTER = '+1 year';

    public const SOURCE_MARKETPLACE = 'shopify';

    public const TARGET_MARKETPLACE = 'amazon';

    public const PAYMENT_METHOD = 'shopify';

    public const USER_DETAILS_COLLECTION_NAME = 'user_details';

    public const PAYMENT_DETAILS_COUNTER_NAME = 'payment_id';

    public const USER_PLAN_STATUS_ACTIVE = 'active';

    public const USER_PLAN_STATUS_INACTIVE = 'inactive';

    protected $table = 'plan';

    public const SERVICE_TYPE_ORDER_SYNC = 'order_sync';

    public const DEFAULT_CAPPED_AMOUNT = 0.01;

    public const SETTLE_DAY_START_FROM = 1;

    public const SETTLE_DAY_END_AT = 'EOM';

    public const MARGIN_ORDER_COUNT = 0;

    public const RENEW_PLANS_BATCH_SIZE = 250;

    public const MARKETPLACE_GROUP_CODE = 'amazon_sales_channel';

    public const MARKETPLACE = 'shopify';

    public const FORCEFULLY_CANCELLED = 'forcefully_cancelled';

    public const SETTLEMENT_INVOICE = 'settlement_invoice';

    public const PLAN_JOURNEY_LOG = 'planJourney';

    public const QUOTE_TYPE_PLAN = 'plan';

    public const QUOTE_TYPE_SETTLEMENT = 'settlement';

    public const MAX_PLAN_CREDIT = 1000000;

    public const PAYMENT_STATUS_DEACTIVATED = 'deactivated';

    public const FREE_PLAN_CREDITS = 50;

    public const QUOTE_TYPE_CUSTOM = 'custom';

    public const BILLING_TYPE_ONETIME = 'onetime';

    public const BILLING_TYPE_RECURRING = "recurring";

    public const BILLING_TYPE_CAPPED = "capped";

    public const BILLED_TYPE_YEARLY = 'yearly';

    public const BILLED_TYPE_MONTHLY = 'monthly';

    public const QUOTE_TYPE_ADD_ON = 'add_on';

    public const SHOPIFY_BASE_PATH = 'https://admin.shopify.com/store/';

    public const CATERGORY_REGULAR ='regular';

    public const CATEGORY_MARKETING = 'marketing';

    public const CREDIT_LIMIT_UNLIMITED = 'unlimited';

    public const SERVICE_TYPE_ADDITIONAL_SERVICES = 'additional_services';


    /**
     * set days in which we have to renew plans of every month
     */
    private array $allowedDaysToRenewPlans = [
        1, 2
    ];

    // public const MAX_PLAN = [
    //     'regular' => [
    //         'monthly' => 'professional',
    //         'yearly' => 'unlimited'
    //     ],
    //     'marketing' => [
    //         'monthly' => 'scale_pro',
    //         'yearly' => 'professional'
    //     ]
    // ];

    public const MAX_MONTHLY_PAID_PLAN_PRICE = 249;

    public const LOWEST_PAID_PLAN_PRICE = [
        'regular' => [
            'monthly' => 19
        ]
    ];

    public const MAX_PLAN_ORDER_CREDIT_COUNT = 2500;

           /**
     * intialization of class variables
     */
    public function onConstruct(): void
    {
        $this->di = $this->getDi();
        $this->setSource($this->table);
        $this->initializeDb($this->getMultipleDbManager()->getDb());
        $this->baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $this->paymentCollection = $this->baseMongo->getCollectionForTable(self::PAYMENT_DETAILS_COLLECTION_NAME);
        $this->planCollection = $this->baseMongo->getCollectionForTable(self::PLAN_COLLECTION_NAME);
        $this->commonObj = $this->di->getObjectManager()->get(Common::class);
    }

    /**
     * for plan selection processing
     * @param $rawbody
     * @return array
     */
    public function choosePlan($rawbody)
    {
        $result = [];
        $activePlan = [];
        $userId = $this->di->getUser()->id;
        $planDetails = $this->getPlan($rawbody['plan_id'] ?? "");
         
        if ($planDetails['success'] && !empty($planDetails['data'])) {
            $planDetails = $planDetails['data'];
            //to restrict the plan if free and cleint has restircted from importing of products
            $choosenPlanFree = false;
            if($this->isFreePlan($planDetails) || $this->isTrialPlan($planDetails)) {
                $choosenPlanFree = true;
                $canProceed = true;
                $message = 'Sorry! you cannot proceed. Please follow the instructions!';
                $canProceedRes = $this->di->getObjectManager()->get(Helper::class)->processInitiateImport($userId);
                if(isset($canProceedRes['success']) && isset($canProceedRes['abort']) && ($canProceedRes['abort'] == true)) {
                    $canProceed = false;
                    $message = $canProceedRes['message'] ?? 'Sorry! you cannot proceed. Please follow the instructions!';
                }

                if (($canProceedRes['count'] <= 10000)) {
                    $canProceed = true;
                }

                if($canProceed == false) {
                    return [
                        'success' => false,
                        'can_proceed_import' => $canProceed,
                        'message' => $message
                    ];
                }
                //to restrict client from choosing a free or trial plan if their trial or free is already exhausted
                if ($this->trialPlanExhausted($userId)) {
                    return [
                        'success' => false,
                        'message' => 'You are not allowed to go with the "'.$planDetails['title'].'" plan as you already used the plan service on free!'
                    ];
                }
            }

            $activePlan = $this->getActivePlanForCurrentUser($userId);
            if (!empty($activePlan)) {
                $planVersion = $activePlan['plan_details']['version'] ?? null;
                $activePlanCustomPrice = (float)$activePlan['plan_details']['custom_price'];
                $selectedPlanCustomPrice = (float)$planDetails['custom_price'];
                $selectedPlanCategory = $planDetails['category'] ?? self::CATERGORY_REGULAR;
                $activePlanCategory = $activePlan['plan_details']['category'] ?? self::CATERGORY_REGULAR;

                //this is added as for plan version 2.0 as there are these plans will be allowing with recurring only
                $recurringType =  $activePlan['plan_details']['payment_type'] ?? self::BILLING_TYPE_RECURRING;
                if ($choosenPlanFree && !is_null($planVersion) && ($this->isFreePlan($activePlan['plan_details'] ?? []) || $this->isTrialPlan($activePlan['plan_details'] ?? []))) {
                    $result = [
                        'success' => false,
                        'message' => 'You have already selected \'' . $planDetails['title'] . '\' plan.'
                    ];
                }
                
                /* Restrict seller to not select current active plan start */
                if (($activePlanCustomPrice == $selectedPlanCustomPrice)
                    && ($selectedPlanCategory == $activePlanCategory)
                    && ($recurringType == self::BILLING_TYPE_RECURRING)
                    && (($activePlan['plan_details']['billed_type'] ?? self::BILLED_TYPE_MONTHLY) == $planDetails['billed_type'])
                    && ($activePlan['plan_details']['title'] == $planDetails['title'])
                    ) {
                        $paymentInfo = $this->getPaymentInfoForCurrentUser($userId);
                        if (!empty($paymentInfo) && isset($paymentInfo['marketplace_data']['id']) && $this->isRecurringActive($this->di->getRequester()->getSourceId(), $userId, $paymentInfo)) {
                            $result = [
                                'success' => false,
                                'message' => 'You have already selected \'' . $planDetails['title'] . '\' plan.'
                            ];
                        }
                }
                /* Restrict seller to not select current active plan end */

                /** if user is already on paid  plan restrict them from choosing free plan*/
                $userService = $this->getCurrentUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
                /* to restrict seller from selecting any plan if he has any pending settlement - end */

                $settlementCheckRes = $this->di->getObjectManager()->get(Settlement::class)->checkSettlementAndProcess($userId, $userService);
                if (isset($settlementCheckRes['success']) && !$settlementCheckRes['success']) {
                    $result = $settlementCheckRes;
                } else {
                    // if ($activePlanCustomPrice > 0 && ($selectedPlanCustomPrice == 0 || $planDetails['code'] == 'trial')) {
                    //     $result = ['success' => false, 'message' => 'You are not eligible for "'.$planDetails['title'].'" plan.'];
                    // } else {
                        /** this code will restrict user from selecting any plan whose service credits are less than current used credits
                         * suppose user is on 500 order limit plan and if his used credits are prepaid = 50 and postapid = 0 and total used 50+25 < 100 then he is allowed to choose a plan of 100 orders which means downgrading and we should not allow this
                         */
                        $totalUsedCredit = (int)($userService['prepaid']['total_used_credits'] ?? 0)
                            + (int)($userService['postpaid']['total_used_credits'] ?? 0)
                            + (int)($userService['add_on']['total_used_credits'] ?? 0);
                        $totalUsedCredit += self::MARGIN_ORDER_COUNT;
                        $planCredits = $this->di->getObjectManager()->create(Services::class)->getServiceCredits($planDetails);
                        if ($choosenPlanFree && (($totalUsedCredit - self::MARGIN_ORDER_COUNT) > self::FREE_PLAN_CREDITS)) {
                            $result = ['success' => false, 'message' => 'You are not allowed to select this plan as you used '.($totalUsedCredit - Plan::MARGIN_ORDER_COUNT).' credits, while this plan is limited to '.PLan::FREE_PLAN_CREDITS.' credits only!'];
                        }
                        if (!$choosenPlanFree && ($planCredits <= $totalUsedCredit)) {
                            $result = ['success' => false, 'message' => 'You are not allowed to select this plan as you used '.($totalUsedCredit - Plan::MARGIN_ORDER_COUNT).' credits, while this plan is limited to '.(int)$planCredits.' credits only!'];
                        }
                    // }
                }
                if (!empty($result)) {
                    return $result;
                }
            }

            $planId = $planDetails['plan_id'];
            $connector = $planDetails['source_marketplace'];
            $amount = $this->commonObj->getDiscountedAmount($planDetails);
            $planDetails['offered_price'] = $amount;
            //for coupen code feature
            if (isset($rawbody['coupon_code']) && !empty($rawbody['coupon_code'])) {
                $couponResponse = $this->validateCoupon($rawbody['coupon_code'], $planDetails['billed_type']);
                if (isset($couponResponse['success'], $couponResponse['data'])) {
                    if (!empty($activePlan)) {
                        $applicable = true;
                        if ($this->isFreePlan($activePlan['plan_details']) || $this->isTrialPlan($activePlan['plan_details']) || $this->isCustomPlanOnetime($activePlan['plan_details'])) {
                            $applicable = true;
                        } elseif(isset($activePlan['plan_details']['billed_type']) && ($activePlan['plan_details']['billed_type'] == 'monthly') && ($couponResponse['data']['billing_type_supported'] == 'monthly')) {
                            $applicable = false;
                        } elseif(isset($activePlan['plan_details']['billed_type']) && ($activePlan['plan_details']['billed_type'] == 'yearly')) {
                            $applicable = false;
                        }
                        if (!$applicable) {
                            return [
                                'success' => false,
                                'message' => 'Coupon code not applicable please read terms and conditions!'
                            ];
                        }
                    }
                    array_push($planDetails['discounts'], $couponResponse['data']);
                } else {
                    return $couponResponse;
                }
            }
            $title = $planDetails['title'];
            $services = $planDetails['services_groups'];
            $type = $planDetails['payment_type'] ?? self::BILLING_TYPE_RECURRING;
            $cappedAmount = (float)$this->getCappedAmountForSelectedPlan($userId, $activePlan);
            if (isset($planDetails['capped_price'])) {
                if ($cappedAmount <= (float)$planDetails['capped_price']) {
                    $cappedAmount = (float)$planDetails['capped_price'];
                }
            }
            $trialDays = $this->getTrialDays($planDetails, $activePlan);
            $planDetails['trial_days'] = $trialDays;
            $allPaymentMethods = $this->di->getConfig()->payment_methods;
            $allPaymentMethods = $allPaymentMethods->toArray();
            $toReturnData = [];
            if (isset($allPaymentMethods[$connector])) {
                $connectorPaymentMethod = $allPaymentMethods[$connector];
                if (count($connectorPaymentMethod) > 1) {
                    $toReturnData['show_payment_methods'] = true;
                    $toReturnData['payment_methods'] = $connectorPaymentMethod;
                    $result = ['success' => true, 'data' => $toReturnData];
                } else {
                    $paymentMethod = array_values($connectorPaymentMethod)[0];
                    $result = $this->processPayment(
                        $cappedAmount,
                        $userId,
                        $title,
                        $amount,
                        $services,
                        $type,
                        $planId,
                        $paymentMethod,
                        $planDetails
                    );
                }
            } else {
                $result = [
                    'success' => false,
                    'message' => 'No payment method found',
                    'code' => 'no_payment_method_found'
                ];
            }
        } else {
            $result = [
                'success' => false,
                'message' => 'Plan not found'
            ];
        }

        return $result;
    }

           /**
     * @param $planId
     * @return array
     */
    public function getPlan($planId = "")
    {
        $data = $this->planCollection->findOne(['plan_id' => $planId], self::TYPEMAP_OPTIONS);
        return ['success' => true, 'data' => $data];
    }

    /**
     * to get plan by query
     * @param $query
     * @return array
     */
    public function getPlanByQuery($query)
    {
        $data = $this->planCollection->find($query, self::TYPEMAP_OPTIONS)->toArray();
        return ['success' => true, 'data' => $data];
    }

    /**
     * to update user services and activate plan
     * @param $quoteId
     * @param int $cappedAmount
     * @return bool
     */
    public function updateUserPlanServices($quoteId, $cappedAmount = self::DEFAULT_CAPPED_AMOUNT, $paymentData = [])
    {
        if ($quoteId) {
            $quoteData = $this->getQuoteByQuoteId($quoteId);
            if (!empty($quoteData)) {
                $sourceId = $quoteData['source_id'] ??  ($this->di->getRequester()->getSourceId() ?? ($this->commonObj->getShopifyShopId() ?? null));
                $appTag =  $quoteData['app_tag'] ?? ($this->di->getAppCode()->getAppTag() ?? (Plan::APP_TAG ?? null));

                $balanceUsed = (float)($paymentData['balance_used'] ?? 0);
                $nextBillingDate = $paymentData['current_period_end'] ?? date('c', strtotime('+1month'));
                $hasTrialDays = $paymentData['trial_days'] ?? 0;
                /* Set Inactive status for all active plans start */
                $userId = $quoteData['user_id'];
                $currentDate = date('Y-m-d H:i:s');
                $statusUpdateFilterData = [
                    'user_id' => (string)$userId,
                    'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
                    'status' => self::USER_PLAN_STATUS_ACTIVE,
                    'source_marketplace' => self::SOURCE_MARKETPLACE,
                    'target_marketplace' => self::TARGET_MARKETPLACE,
                    'marketplace' => self::TARGET_MARKETPLACE,
                ];

                $activePlanDetails = $this->paymentCollection->findOneAndUpdate(
                    $statusUpdateFilterData,
                    [
                        '$set' =>
                        [
                            'status' => self::USER_PLAN_STATUS_INACTIVE,
                            'updated_at' => $currentDate,
                            'inactivated_on' => date('Y-m-d H:i:s')
                        ]
                    ],
                    [
                        "returnOriginal" => true,
                        "typeMap" => ['root' => 'array', 'document' => 'array']
                    ]
                );

                $oldPlanOnetime = false;
                if (!empty($activePlanDetails) && isset($activePlanDetails['plan_details']['payment_type'])) {
                    ($activePlanDetails['plan_details']['payment_type'] == self::BILLING_TYPE_ONETIME) && $oldPlanOnetime = true;
                }

                /* Set Inactive status for all active plans end */

                $cappedPriceForPlan = null;
                $cappingSet = false;
                if (isset($paymentData['capped_amount']) && ((float)$paymentData['capped_amount']) > $cappedAmount) {
                    $cappedAmount = (float)$paymentData['capped_amount'];
                }
                if (isset($quoteData['plan_details']['capped_price'])) {
                    $cappedPriceForPlan = (float)$quoteData['plan_details']['capped_price'];
                }

                /* Set new entry according to provided quote id start */
                $activePlanData = [
                    '_id' => (string)$this->baseMongo->getCounter(self::PAYMENT_DETAILS_COUNTER_NAME),
                    'user_id' => (string)$userId,
                    'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
                    'status' => self::USER_PLAN_STATUS_ACTIVE,
                    'source_marketplace' => self::SOURCE_MARKETPLACE,
                    'target_marketplace' => self::TARGET_MARKETPLACE,
                    'marketplace' => self::TARGET_MARKETPLACE,
                    'capped_amount' => (float)$cappedAmount,
                    'created_at' => $currentDate,
                    'updated_at' => $currentDate,
                    'plan_details' => $quoteData['plan_details'],
                ];
                if (!is_null($cappedPriceForPlan) && ($cappedAmount > self::DEFAULT_CAPPED_AMOUNT)) {
                    $activePlanData['capped_amount_for_plan'] = $cappedPriceForPlan;
                    $cappingSet = true;
                } else {
                    $cappingSet = false;
                }
                if ($hasTrialDays && (($hasTrialDays > 0) && ($hasTrialDays <=7))) {
                    $date = ($paymentData['created_at'] ?? date('c'));
                    $trailEndDate = gmdate("Y-m-d\TH:i:s\Z", strtotime($date . " +".(string)$hasTrialDays." days"));
                    $activePlanData['trial_days'] = $hasTrialDays;
                    $activePlanData['trial_end_at'] = $trailEndDate;
                }
                $onetimePlan = false;
                if (isset($quoteData['plan_details']['payment_type']) && ($quoteData['plan_details']['payment_type'] == self::BILLING_TYPE_ONETIME)) {
                    $onetimePlan = true;
                    $validity = $quoteData['plan_details']['validity'];
                    $deactivateDate = date('Y-m-d', strtotime('+' . $validity . ' days'));
                    $activePlanData['deactivate_on'] = $deactivateDate;
                }

                $activePlanData['source_id'] = $sourceId;
                $activePlanData['app_tag'] = $appTag;
                if ($this->isTestUser($userId)) {
                    $activePlanData['test_user'] = true;
                }

                $this->paymentCollection->insertOne($activePlanData);
                /* Set new entry according to provided quote id end */

                /* Update user services start */
                $newServices = [];
                $newServiceTypes= [];
                // $newServices = $this->getUpdatedServices($quoteData, $userId, $requiredData);
                $getUserServiceFilterData = [
                    'user_id' => (string)$userId,
                    'type' => self::PAYMENT_TYPE_USER_SERVICE,
                    'source_marketplace' => self::SOURCE_MARKETPLACE,
                    'target_marketplace' => self::TARGET_MARKETPLACE,
                    'marketplace' => self::TARGET_MARKETPLACE,
                    // 'service_type' => $quoteService['type'],
                ];
                $servicesData = $this->paymentCollection->find($getUserServiceFilterData, self::TYPEMAP_OPTIONS)->toArray();
                $savedServiceData = [];
                if (!empty($servicesData)) {
                    foreach($servicesData as $serviceData) {
                        if (isset($serviceData['service_type'])) {
                            $savedServiceData[$serviceData['service_type']] = $serviceData;
                        }
                    }
                }
                foreach ($quoteData['plan_details']['services_groups'] as $group) {
                    foreach ($group['services'] as $quoteService) {
                        $serviceType = $quoteService['type'];
                        $serviceData = [];
                        $newServiceTypes[] = $serviceType;
                        if ($serviceType == self::SERVICE_TYPE_ADDITIONAL_SERVICES) {
                            if (!empty($savedServiceData[$serviceType])) {
                                $serviceData = $savedServiceData[self::SERVICE_TYPE_ADDITIONAL_SERVICES];
                                $serviceData['_id'] = (string)$this->baseMongo->getCounter(self::PAYMENT_DETAILS_COUNTER_NAME);
                                $serviceData['supported_features'] = $quoteService['supported_features'] ?? [];
                                $serviceData['updated_at'] = $currentDate;
                                isset($quoteService['active']) && $serviceData['active'] = $quoteService['active'];
                            } else {
                                $data = [
                                    'sourceId' => $sourceId,
                                    'appTag' => $appTag
                                ];
                                $serviceData = $this->getPreparedServiceData($userId, $serviceType, $data, $quoteService);
                            }
                        } else {
                            if (!empty($savedServiceData[$serviceType])) {
                                $serviceData = $savedServiceData[$serviceType];
                                $serviceData['_id'] = (string)$this->baseMongo->getCounter(self::PAYMENT_DETAILS_COUNTER_NAME);
                                $serviceData['updated_at'] = $currentDate;
                                $serviceData['expired_at'] = date('Y-m-t');
                                $savedServicePostpaidData = $serviceData['postpaid'] ?? [];
                                $savedServicePrepaidData = $serviceData['prepaid'] ?? [];
                                isset($quoteService['active']) && $serviceData['active'] = $quoteService['active'];

                                $oldServiceTrial = false;

                                // Set active: true for product_import service when downgrading to Free/Trial plan
                                if ($serviceType == Services::SERVICE_TYPE_PRODUCT_IMPORT) {
                                    $isFreePlan = $this->isFreePlan($quoteData['plan_details']);
                                    $isTrialPlan = $this->isTrialPlan($quoteData['plan_details']);

                                    if ($isFreePlan || $isTrialPlan) {
                                        $serviceData['active'] = true;  // Activate restrictions for Free/Trial plans
                                    }
                                }
                                //if service is trial
                                if (isset($quoteService['trial_credit_limit']) && !empty($quoteService['trial_credit_limit'])) {
                                    $serviceData['trial_service'] = true;
                                } elseif(isset($serviceData['trial_service'])) {//if new service is not trial but old is
                                    unset($serviceData['trial_service']);
                                    $oldServiceTrial = true;
                                }
                                //for prepaid credits
                                $newPrepaidCredits = [
                                    'service_credits' => (int)($quoteService['prepaid']['service_credits'] ?? 0),
                                    'available_credits' => (int)($quoteService['prepaid']['service_credits'] ?? 0),
                                    'total_used_credits' => 0
                                ];
                                //for add validity we need to adjust the previous used credits and total used as per that\
                                //for replace validity we need to just replace all the credits as per that
                                //also in case of clients previous plan was onetime or new plan is onetime we need to call replace the credits only
                                if (!empty($quoteService['prepaid']) && !empty($savedServicePrepaidData)) {
                                    if ($oldPlanOnetime || $oldServiceTrial || $onetimePlan) {
                                        $newPrepaidCredits = [
                                            'service_credits' => (int)($quoteService['prepaid']['service_credits'] ?? 0),
                                            'available_credits' => (int)($quoteService['prepaid']['service_credits'] ?? 0),
                                            'total_used_credits' => 0
                                        ];
                                    } else {
                                        if (trim(strtolower((string) $quoteService['prepaid']['validity_changes'])) == 'add') {
                                            $totalUsedCredits = (int)($savedServicePrepaidData['total_used_credits'] ?? 0);
                                            $newCredits = (int)($quoteService['prepaid']['service_credits'] ?? 0);
                                            if ($totalUsedCredits >= $newCredits) {
                                                $newPrepaidCredits['total_used_credits'] = $newCredits;
                                                $newPrepaidCredits['available_credits'] = 0;
                                            } elseif($totalUsedCredits > 0) {
                                                $newPrepaidCredits['total_used_credits'] = $totalUsedCredits;
                                                $newPrepaidCredits['available_credits'] = ($newCredits - $totalUsedCredits);
                                            }
                                        }
                                    }
                                }
                                $serviceData['prepaid'] = $newPrepaidCredits;
                                //for postpaid credits
                                $newPostPaidCredits = [
                                    "per_unit_usage_price" => (float)($quoteService['postpaid']['per_unit_usage_price'] ?? 0),
                                    "unit_qty" => (int)($quoteService['postpaid']['unit_qty'] ?? 0),
                                    "capped_credit" => (int)($quoteService['postpaid']['capped_credit'] ?? 0),
                                    'available_credits' => (int)($quoteService['postpaid']['capped_credit'] ?? 0),
                                    'total_used_credits' => 0
                                ];
                            } else {
                                $data = [
                                    'sourceId' => $sourceId,
                                    'appTag' => $appTag
                                ];
                                $serviceData = $this->getPreparedServiceData($userId, $serviceType, $data, $quoteService);
                                // Set active: true for new product_import service when creating Free/Trial plan
                                if ($serviceType == Services::SERVICE_TYPE_PRODUCT_IMPORT) {
                                    $isFreePlan = $this->isFreePlan($quoteData['plan_details']);
                                    $isTrialPlan = $this->isTrialPlan($quoteData['plan_details']);

                                    if ($isFreePlan || $isTrialPlan) {
                                        $serviceData['active'] = true;  // Activate restrictions for Free/Trial plans
                                    }
                                }
                            }
                            $serviceData['subscription_billing_date'] = $nextBillingDate;

                            if (isset($quoteService['postpaid']['capped_amount']) && $quoteService['postpaid']['capped_amount'] && $cappingSet) {
                                //why we are using capped amount? - because capped amount set in postpaid reflects that this service has some capping amount but the cappedAmount that is coming after the payment is the actual capped we need to keep because it is the actual capping being activated for the payment
                                $balanceRemaining = ($cappedPriceForPlan - $balanceUsed);
                                $balanceRemaining = ($balanceRemaining < 0) ? 0 : $balanceRemaining;
                                $perUnitUsage = (float)($quoteService['postpaid']['per_unit_usage_price'] ?? 0);
                                $creditsAvailable =  ($balanceRemaining > 0) ? (int)ceil($balanceRemaining / $perUnitUsage) : 0;
                                $cappedCredits = (int)floor($cappedPriceForPlan / $perUnitUsage);
                                $newPostPaidCredits = [
                                    "is_capped" => true,
                                    "capped_amount" => $cappedPriceForPlan,
                                    "shopify_capped_amount" => $cappedAmount,
                                    "balance_used" => $balanceUsed,
                                    "per_unit_usage_price" => (float)($quoteService['postpaid']['per_unit_usage_price'] ?? 0),
                                    "unit_qty" => (int)($quoteService['postpaid']['unit_qty'] ?? 0),
                                    "capped_credit" => $cappedCredits,
                                    'available_credits' => $creditsAvailable,
                                    'total_used_credits' => 0
                                ];
                                if (isset($savedServicePostpaidData['previous_used_credits'])) {
                                    $newPostPaidCredits['previous_used_credits'] = $savedServicePostpaidData['previous_used_credits'];
                                }
                                if ($hasTrialDays && $trailEndDate) {
                                    $currentDate = gmdate("Y-m-d\TH:i:s\Z");
                                    if (strtotime($currentDate) < strtotime($trailEndDate)) {
                                        $newPostPaidCredits['available_credits'] = 0;
                                        $newPostPaidCredits['active'] = false;
                                    }
                                    $serviceData['trial_end_at'] = $trailEndDate;
                                }
                            }
                            $serviceData['postpaid'] = $newPostPaidCredits;
                            if ($onetimePlan) {
                                $serviceData['deactivate_on'] = date('Y-m-d', strtotime('+' . $validity . ' days'));
                            }
                            if (($onetimePlan || (($quoteData['plan_details']['billed_type'] ?? self::BILLED_TYPE_MONTHLY) == self::BILLED_TYPE_YEARLY))) {
                                $newPostPaidCredits = [
                                    "per_unit_usage_price" => 3,
                                    "unit_qty" => 10,
                                    "capped_credit" => 0,
                                    'available_credits' => 0,
                                    'total_used_credits' => 0
                                ];
                                $serviceData['postpaid'] = $newPostPaidCredits;
                            }
                            if (isset($quoteService['trial_credit_limit']) && !empty($quoteService['trial_credit_limit'])) {
                                $serviceData['trial_service'] = true;
                            } elseif(isset($serviceData['trial_service'])) {
                                unset($serviceData['trial_service']);
                            }
                             //if new plan is onetime, add deactivate_on date
                            if ($onetimePlan) {
                                $serviceData['deactivate_on'] = $deactivateDate;
                            } elseif (isset($serviceData['deactivate_on'])) {//if old service is onetime and new is not
                                unset($serviceData['deactivate_on']);
                                if (isset($serviceData['activate_free_plan'])) {//if free plan needs to be activated
                                    unset($serviceData['activate_free_plan']);
                                }
                            }

                            // Unset order limit activity keys during plan upgrade/downgrade for order_sync service
                            if ($serviceType === self::SERVICE_TYPE_ORDER_SYNC && 
                                (isset($serviceData['activity_type']) || isset($serviceData['freeze_reset']) || 
                                 isset($serviceData['old_service_credits']) || isset($serviceData['reset_date']))) {

                                $orderLimitActivityKeys = ['activity_type', 'freeze_reset', 'old_service_credits', 'reset_date'];
                                $keysToUnset = [];

                                foreach ($orderLimitActivityKeys as $key) {
                                    if (isset($serviceData[$key])) {
                                        unset($serviceData[$key]);
                                        $keysToUnset[] = $key;
                                    }
                                }

                                // Deactivate existing order limit activities in pricing_plan_activity
                                $this->deactivateUserOrderLimitActivities($userId);
                            }
                        }
                        $newServices[] = $serviceData;
                    }
                }

                $deleteQuery = [
                    'user_id' => (string)$userId,
                    'type' => self::PAYMENT_TYPE_USER_SERVICE,
                    'source_marketplace' => self::SOURCE_MARKETPLACE,
                    'target_marketplace' => self::TARGET_MARKETPLACE,
                    'marketplace' => self::TARGET_MARKETPLACE,
                ];
                $userServiceTypes = $this->paymentCollection->distinct("service_type", $deleteQuery);

                try {
                    $result = $this->paymentCollection->deleteMany($deleteQuery);
                    if ($result->isAcknowledged()) {
                        $res = $this->paymentCollection->insertMany($newServices);
                        if (!$res->isAcknowledged()) {
                            $this->commonObj->addLog("Error in inserting user service: " .json_encode($res), 'plan/mongo_errors/' . date('Y-m-d') . '.log');
                            $this->commonObj->addLog("New services: " . json_encode($newServices), 'plan/mongo_errors/' . date('Y-m-d') . '.log');
                        }
                    } else {
                        $this->commonObj->addLog("Error in deleting user service existing: " .json_encode($result), 'plan/mongo_errors/' . date('Y-m-d') . '.log');
                        $this->commonObj->addLog("New services: " . json_encode($newServices), 'plan/mongo_errors/' . date('Y-m-d') . '.log');
                    }
                } catch (Exception $e) {
                    $this->commonObj->addLog("Error in deleting and inserting data for user services: " . json_encode($e->getMessage()), 'plan/mongo_errors/' . date('Y-m-d') . '.log');
                    $this->commonObj->addLog("New services: " . json_encode($newServices), 'plan/mongo_errors/' . date('Y-m-d') . '.log');
                }

                $this->updateRegisterOrderSyncIfExist($userId);//to register user if not registered

                // to renew email config
                $this->di->getObjectManager()->get(Email::class)->resetEmailConfig($userId);
                if(!empty($userServiceTypes)) {
                    $servicesRemoved = array_diff($userServiceTypes, $newServiceTypes);
                    foreach($servicesRemoved as $serviceType) {
                        $this->di->getObjectManager()->get(Services::class)->handleServicesRemoved($serviceType, $userId);
                    }
                }

                $servicesAdded = [];
                foreach($newServices as $service) {
                    if (isset($service['service_type'])) {
                        $servicesAdded[$service['service_type']] = [
                            'prepaid' => $service['prepaid'] ?? [],
                            'postpaid' => $service['postpaid'] ?? [],
                            'supported_features' => $service['supported_features'] ?? [],
                            'active' => $service['active'] ?? true
                        ];
                    }
                }

                $updatedServices['services'] = $servicesAdded;
                $updatedServices['user_id'] = $userId;
                $updatedServices['app_tag'] = $appTag;
                $this->di->getObjectManager()->get(Services::class)->handleServicesUpdate($updatedServices);
                $this->di->getObjectManager()->get(PlanEvent::class)->sendServiceUpdateEvent($updatedServices);

                //for the cases where we need to provide remaining trial limits to the user's trial plan
                // $entryForTrialLimit = $this->paymentCollection->findOne(['user_id' => $userId, 'type' => 'trial_limit'], self::TYPEMAP_OPTIONS);
                // if (!empty($entryForTrialLimit) && isset($entryForTrialLimit['trial_limit_left']) && $quoteData['plan_details']['code'] == 'trial') {
                //     $userserviceFilterData = [
                //         'user_id' => (string)$userId,
                //         'type' => self::PAYMENT_TYPE_USER_SERVICE,
                //         'source_marketplace' => self::SOURCE_MARKETPLACE,
                //         'target_marketplace' => self::TARGET_MARKETPLACE,
                //         'marketplace' => self::TARGET_MARKETPLACE,
                //         'service_type' => self::SERVICE_TYPE_ORDER_SYNC
                //     ];
                //     $this->paymentCollection->updateOne($userserviceFilterData, ['$set' =>
                //     [
                //         'prepaid.total_used_credits' => $entryForTrialLimit['trial_limit_left'],
                //         'updated_at' => date('Y-m-d H:i:s')
                //     ]]);
                //     $this->paymentCollection->deleteOne(['user_id' => $userId, 'type' => 'trial_limit']);
                // } elseif (!empty($entryForTrialLimit)) {
                //     $this->paymentCollection->deleteOne(['user_id' => $userId, 'type' => 'trial_limit']);
                // }

                if (!empty($activePlanDetails)) {
                    $transactionData['user_id'] = $userId;
                    $transactionData['deactivated_plan_details'] = $activePlanDetails;
                    $this->di->getObjectManager()->get(Transaction::class)->planDeactivated($transactionData);
                }
                /* Update user services end */
                return true;
            }
        }

        return false;
    }
    /**
     * @param $userId
     * @param $planId
     * @return mixed
     */
    public function getNonExpiredQuote($userId, $planId, $quoteType = 'plan')
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $currentDate = date("Y-m-d H:i:s");
        $filterData = [
            "user_id" => $userId,
            "type" => self::PAYMENT_TYPE_QUOTE,
            "status" => [
                '$in' => [
                    self::QUOTE_STATUS_ACTIVE,
                    self::QUOTE_STATUS_WAITING_FOR_PAYMENT
                ]
            ],
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            "plan_id" => $planId,
            "expire_at" => [
                '$gt' => $currentDate
            ],
            'plan_type' => $quoteType,
        ];
        return $this->paymentCollection->findOne($filterData, self::TYPEMAP_OPTIONS);
    }

    /**
     * @param $userId
     * @return mixed
     */
    public function getPaymentInfoForCurrentUser($userId)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $paymentFilterData = [
            'user_id' => (string)$userId,
            'type' => self::PAYMENT_TYPE_PAYMENT,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            'plan_status' => ['$exists' => true],
            'plan_status' => self::USER_PLAN_STATUS_ACTIVE,
        ];
        $paymentDoc = $this->paymentCollection->findOne($paymentFilterData, self::TYPEMAP_OPTIONS);
        if (empty($paymentDoc)) {
            $paymentFilterData = [
                'user_id' => (string)$userId,
                'type' => self::PAYMENT_TYPE_PAYMENT,
                'source_marketplace' => self::SOURCE_MARKETPLACE,
                'target_marketplace' => self::TARGET_MARKETPLACE,
                'plan_status' => ['$exists' => false],
                'settlement_id' => ['$exists' => false],
                'custom_payment' => ['$exists' => false]
            ];
            $paymentDoc = $this->paymentCollection->findOne($paymentFilterData, self::TYPEMAP_OPTIONS);
        }

        return $paymentDoc;
    }

    /**
     * to get the domain name of the service
     */
    public function getDomainName($userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
            $shops = $this->di->getUser()->shops;
        } else {
            $userData = $this->commonObj->getUserDetail($userId);
            $shops = $userData['shops'] ?? [];
        }

        $shopifyDomain = '';
        foreach ($shops as $shop) {
            if ($shop['marketplace'] == self::SOURCE_MARKETPLACE) {
                $shopifyDomain = $shop['domain'] ?? "";
                break;
            }
        }

        return $shopifyDomain;
    }

    /**
     * @param string $paymentStatus
     * @param bool $userId
     * @param $planId
     * @param $planDetails
     * @return bool|mixed
     */
    public function setQuoteDetails(
        $paymentStatus = self::QUOTE_STATUS_ACTIVE,
        $userId = false,
        $planId = "",
        $planDetails = [],
        $quoteType = self::QUOTE_TYPE_PLAN
    ) {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $domainName = $this->getDomainName($userId);
        $currentDate = date("Y-m-d H:i:s");
        $expireAt = date("Y-m-d H:i:s", strtotime(self::QUOTE_EXPIRE_AFTER, strtotime($currentDate)));

        if ($quoteType == self::QUOTE_TYPE_SETTLEMENT) {
            $expireAt = date("Y-m-d H:i:s", strtotime(self::SETTLEMENT_QUOTE_EXPIRE_AFTER, strtotime($currentDate)));
        }

        $existingQuoteData = $this->getNonExpiredQuote($userId, $planId, $quoteType);
        if ($existingQuoteData) {
            $quoteData = $existingQuoteData;
            $quoteData['status'] = $paymentStatus;
            $quoteData['updated_at'] = $currentDate;
            $quoteData['expire_at'] = $expireAt;
            if ($quoteType == self::QUOTE_TYPE_SETTLEMENT) {
                $quoteData['settlement_details'] = $planDetails;
            } else {
                $quoteData['plan_details'] = $planDetails;
            }
        } else {
            $quoteData = [
                '_id' => (string)$this->baseMongo->getCounter(self::PAYMENT_DETAILS_COUNTER_NAME),
                'user_id' => $userId,
                'type' => self::PAYMENT_TYPE_QUOTE,
                'status' => $paymentStatus,
                'source_marketplace' => self::SOURCE_MARKETPLACE,
                'target_marketplace' => self::TARGET_MARKETPLACE,
                'plan_id' => $planId,
                'created_at' => $currentDate,
                'updated_at' => $currentDate,
                'expire_at' => $expireAt,
                'domain_name' => $domainName
            ];
            if ($quoteType == self::QUOTE_TYPE_SETTLEMENT) {
                $quoteData['settlement_details'] = $planDetails;
                $quoteData['settlement_id'] = $planId;
            } else {
                $quoteData['plan_details'] = $planDetails;
            }

            $quoteData['plan_type'] = $quoteType;
            if (!empty($this->di->getAppCode()->getAppTag())) {
                $quoteData['app_tag'] = $this->di->getAppCode()->getAppTag();
            } else {
                $quoteData['app_tag'] = Plan::APP_TAG;
            }

            // if (!empty($this->di->getRequester()->getSourceId())) {
            //     $quoteData['source_id'] = $this->di->getRequester()->getSourceId();
            // } elseif ($this->commonObj->getShopifyShopId()) {
            //     $quoteData['source_id'] = $this->commonObj->getShopifyShopId();
            // }
            $quoteData['source_id'] = $this->commonObj->getShopifyShopId($userId);
            if ($this->isTestUser($userId)) {
                $quoteData['test_user'] = true;
            }
        }

        $mongoResult = $this->paymentCollection->replaceOne(
            ['_id' => $quoteData['_id']],
            $quoteData,
            ['upsert' => true]
        );
        if ($mongoResult->isAcknowledged()) {
            return $quoteData['_id'];
        }

        return false;
    }

    /**
     * @param $quoteId
     * @return mixed
     */
    public function getQuoteByQuoteId($quoteId)
    {
        return $this->paymentCollection->findOne([
            "_id" => $quoteId,
            "type" => self::PAYMENT_TYPE_QUOTE
        ], self::TYPEMAP_OPTIONS);
    }

    /**
     * @param $quoteId
     * @return mixed
     */
    public function getPaymentDetailsByQuoteId($quoteId)
    {
        return $this->paymentCollection->findOne([
            "quote_id" => $quoteId,
            "type" => self::PAYMENT_TYPE_PAYMENT
        ], self::TYPEMAP_OPTIONS);
    }

    /**
     * @param $userId
     * @param $paymentStatus
     * @param $quoteId
     * @param array $markteplaceData
     * @return bool|mixed
     */
    public function setPaymentDetails($userId, $paymentStatus, $quoteId, $markteplaceData = [], $planType = 'plan')
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $quoteData = $this->getQuoteByQuoteId($quoteId);
        if (!empty($quoteData)) {
            $settlementPayment = false;
            if (isset($quoteData['settlement_id']) || ($planType == self::QUOTE_TYPE_SETTLEMENT)) {
                $settlementPayment = true;
                $settlementId = $quoteData['settlement_id'] ?? "";
                $planType = self::QUOTE_TYPE_SETTLEMENT;
            }

            $paymentData = $this->getPaymentDetailsByQuoteId($quoteId);
            $currentDate = date('Y-m-d H:i:s');
            if (!empty($paymentData)) {
                $paymentData['status'] = $paymentStatus;
                $paymentData['updated_at'] = $currentDate;
                $paymentData['marketplace_data'] = $markteplaceData;
                $paymentData['source_marketplace'] = self::SOURCE_MARKETPLACE;
                $paymentData['target_marketplace'] = self::TARGET_MARKETPLACE;
            } else {
                $paymentData = [
                    '_id' => (string)$this->baseMongo->getCounter(self::PAYMENT_DETAILS_COUNTER_NAME),
                    'user_id' => $userId,
                    'type' => self::PAYMENT_TYPE_PAYMENT,
                    'status' => $paymentStatus,
                    'source_marketplace' => self::SOURCE_MARKETPLACE,
                    'target_marketplace' => self::TARGET_MARKETPLACE,
                    'quote_id' => (string)$quoteData['_id'],
                    'plan_id' => (string)$quoteData['plan_id'],
                    'method' => self::PAYMENT_METHOD,
                    'marketplace_data' => $markteplaceData,
                    'created_at' => $currentDate,
                    'updated_at' => $currentDate
                ];
                $settlementPayment && $paymentData['settlement_id'] = $settlementId;

                isset($quoteData['source_id']) && $paymentData['source_id'] = (string)$quoteData['source_id'];
                isset($quoteData['app_tag']) && $paymentData['app_tag'] = (string)$quoteData['app_tag'];
                if ($this->isTestUser($userId)) {
                    $paymentData['test_user'] = true;
                }
            }

            if (($paymentStatus == self::PAYMENT_STATUS_APPROVED) && !$settlementPayment && ($planType == 'plan')) {
                $paymentData['plan_status'] = self::USER_PLAN_STATUS_ACTIVE;
            }

            ($planType == self::QUOTE_TYPE_CUSTOM) && $paymentData['custom_payment'] = true;
            $mongoResult = $this->paymentCollection->replaceOne(
                ['_id' => $paymentData['_id']],
                $paymentData,
                ['upsert' => true]
            );

            if ($paymentStatus == self::PAYMENT_STATUS_APPROVED && ($planType == self::QUOTE_TYPE_PLAN)) {
                $this->paymentCollection->updateMany(
                    [
                        'user_id' => $userId,
                        'type' => self::PAYMENT_TYPE_PAYMENT,
                        'quote_id' => [
                            '$ne' => (string)$quoteData['_id']
                        ],
                        'settlement_id' => [
                            '$exists' => false
                        ],
                        'custom_payment' => [
                            '$exists' => false
                        ],
                    ],
                    [
                        '$set' => [
                            'plan_status' => self::PAYMENT_STATUS_DEACTIVATED,
                            'updated_at' => $currentDate
                        ]
                    ]
                );
            }

            if ($mongoResult->isAcknowledged()) {
                return $paymentData['_id'];
            }
        }

        return false;
    }

    /**
     * @param bool $userId
     * @return bool
     */
    public function resetUsedLimit($userId = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $resetLimitFilterData = [
            'user_id' => (string)$userId,
            'type' => self::PAYMENT_TYPE_USER_SERVICE,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            'marketplace' => self::TARGET_MARKETPLACE,
	        'service_type' => self::SERVICE_TYPE_ORDER_SYNC,
            '$expr' => [
                '$gt' => [
                    '$postpaid.total_used_credits', 0
                ]
            ],
            'postpaid.is_capped' => ['$exists' => false] 
        ];
        $userServices = $this->paymentCollection->find($resetLimitFilterData, self::TYPEMAP_OPTIONS)->toArray();
        if (!empty($userServices)) {
            $currentDate = date('Y-m-d H:i:s');
            foreach ($userServices as $service) {
                $service['updated_at'] = $currentDate;
                $this->updatePrepaidIfPostpaidLeft($service);
                $service['postpaid']['total_used_credits'] = 0;
                 // $service['postpaid']['available_credits'] = (int)$service['postpaid']['capped_credit'];
                /**
                 * settlement process onetime
                 */
                $service['postpaid']['available_credits'] = 0;
		        $service['postpaid']['capped_credit'] = 0;
                /**
                 * settlement process onetime
                 */
                $this->paymentCollection->replaceOne(
                    ['_id' => $service['_id']],
                    $service,
                    ['upsert' => true]
                );
            }

            return true;
        }

        return false;
    }

    /**
     * to add left credits in prepaid
     */
    public function updatePrepaidIfPostpaidLeft(&$service)
    {
        if (isset($service['prepaid']['service_credits']) && $service['prepaid']['service_credits'] == self::CREDIT_LIMIT_UNLIMITED) {
            return [];
        }

        $postpaidUsed = $service['postpaid']['total_used_credits'] ?? 0;
        $unitQty = $service['postpaid']['unit_qty'] ?? 1;

        $postpaidLeft = ($postpaidUsed % $unitQty > 0)
            ? $unitQty - ($postpaidUsed % $unitQty)
            : ($postpaidUsed % $unitQty);

        $service['prepaid']['service_credits'] += $postpaidLeft;
        $service['prepaid']['available_credits'] += $postpaidLeft;
    }

    /**
     * @param $cappedAmount
     * @param $userId
     * @param $title
     * @param $amount
     * @param $services
     * @param $type
     * @param $planId
     * @param $paymentMethod
     * @param $planDetails
     * @param array $schema
     * @return array
     */
    public function processPayment(
        $cappedAmount,
        $userId,
        $title,
        $amount,
        $services,
        $type,
        $planId,
        $paymentMethod,
        $planDetails,
        $schema = []
    ) {
        $result = [];
        $this->logFile = 'plan/' . date('Y-m-d') . '/' . $userId . '_' . self::PLAN_JOURNEY_LOG . '.log';
        $this->commonObj->addLog('------ Plan Activation Process for ' . $userId . '--------', $this->logFile);
        $this->commonObj->addLog('Stage 1. Plan details : ' . json_encode($planDetails, true), $this->logFile);
        $hasCapping = isset($planDetails['capped_price']) ? true : false;
        if ($this->isFreePlan($planDetails) && !$hasCapping) {
            $result = $this->activateFreePlanNoneCapped($planDetails, $userId);
        }   elseif ($this->isTrialPlan($planDetails) && !$hasCapping) {
            $result = $this->activateTrialPlan($planDetails, $userId);
        } else {
            $this->commonObj->addLog('Paid plan processing =>', $this->logFile);
            $toReturnData = [];
            $quoteId = $this->setQuoteDetails(self::QUOTE_STATUS_ACTIVE, $userId, $planId, $planDetails, self::QUOTE_TYPE_PLAN);
            if ($quoteId) {
                if ($paymentMethod['type'] == 'redirect') {
                    $this->commonObj->addLog('1. redirect payment type', $this->logFile);
                    $confirmationUrl = $this->getConfirmationUrl(
                        $cappedAmount,
                        $amount,
                        $type,
                        $planId,
                        $title,
                        $quoteId,
                        $userId,
                        $schema,
                        false,
                        $planDetails
                    );
                    $this->commonObj->addLog('2. confirmationUrl', $this->logFile);
                    if ($confirmationUrl) {
                        $toReturnData['confirmation_url'] = $confirmationUrl;
                        /**in case of plan upgrade and and handling webhooks */
                        $activePlan = $this->getActivePlanForCurrentUser($userId);
                        if (!empty($activePlan)) {
                            $filterData = [
                                'user_id' => $userId,
                                'type' => 'process',
                            ];
                            $saveData = [
                                '$set' => [
                                    'user_id' => $userId,
                                    'type' => 'process',
                                    'upgrade' => true,
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'updated_at' => date('Y-m-d H:i:s')
                                ]
                            ];
                            $this->paymentCollection->updateOne(
                                $filterData,
                                $saveData,
                                ['upsert' => true]
                            );
                        }

                        $this->setQuoteDetails(self::QUOTE_STATUS_WAITING_FOR_PAYMENT, $userId, $planId, $planDetails, self::QUOTE_TYPE_PLAN);
                        $this->commonObj->addLog('QUOTE_STATUS_WAITING_FOR_PAYMENT', $this->logFile);
                        $result = ['success' => true, 'data' => $toReturnData];
                    } else {
                        $this->commonObj->addLog('failed_to_process_payment', $this->logFile);
                        $result = [
                            'success' => false,
                            'message' => 'Failed to process payment',
                            'code' => 'failed_to_process_payment'
                        ];
                    }
                } else {
                    $this->commonObj->addLog('payment type not found!', $this->logFile);
                    die("Need to manage this condition in_plan.php");
                }
            } else {
                $this->commonObj->addLog('quoteId not activated for plan processing!', $this->logFile);
                $result = [
                    'success' => false,
                    'message' => 'Transaction failed',
                    'code' => 'transaction_failed'
                ];
            }
        }

        return $result;
    }

    /**
     * @param $cappedAmount
     * @param $amount
     * @param $type
     * @param $planId
     * @param $planTitle
     * @param $quoteId
     * @param bool $userId
     * @param array $shopDetails
     * @param bool $testPayment
     * @return bool
     */
    public function getConfirmationUrl(
        $cappedAmount,
        $amount,
        $type,
        $planId,
        $planTitle,
        $quoteId,
        $userId = false,
        $shopDetails = [],
        $testPayment = false,
        $planDetails = []
    ) {
        $this->logFile = 'plan/' . date('Y-m-d') . '/' . $userId . '_' . self::PLAN_JOURNEY_LOG . '.log';
        $shop = $this->commonObj->getShop($this->di->getRequester()->getSourceId(), $userId);
        if (empty($shop) || !isset($shop['remote_shop_id'])) {
            return [
                'success' => false,
                'message' => 'Shop not found'
            ];
        }

        $data['shop_id'] = $shop['remote_shop_id'];
        $homeUrl = $this->di->getConfig()->get('base_url_frontend');
        $test = false;
        $yearly = false;
        if ($this->isTestUser($userId)) {
            $test = true;
        }

        if ($type == self::BILLING_TYPE_ONETIME) {
            /** for custom plans onetime start */
            $plan = [
                'name' => $planTitle,
                'price' => $amount,
                'test' => $test,
                'return_url' => $homeUrl . 'plan/plan/check?quote_id=' . $quoteId . '&shop=' . $shop['domain'] . '&state=' . $userId . '&plan_type=plan' . '&source_id=' . $this->getSourceId()
            ];
            $data['data'] = $plan;
            $this->commonObj->addLog('Data sent to remote for onetime custom plan: ' . json_encode($data), $this->logFile);
            $remoteResponse = $this->di->getObjectManager()->create(ShopifyPayment::class)->createRemotePayment($data, self::BILLING_TYPE_ONETIME);
            /** for custom plans onetime end */
        } else {
            $plan = [
                'name' => $planTitle,
                'price' => $amount,
                'test' => $test,
                'capped_amount' => $cappedAmount,
                'terms' => 'Along with your recurring plan, the ' . ($this->di->getConfig()->app_name ?? self::APP_NAME). ' app sets ' . $cappedAmount . '$ as capped amount to update your post usage-based charges, to provide you an interruption free App service in future.',
                'return_url' => $homeUrl . 'plan/plan/check?quote_id=' . $quoteId . '&type=' . $type . '&shop=' . $shop['domain'] . '&state=' . $userId
            ];
            (isset($planDetails['trial_days']) && $planDetails['trial_days']) && $plan['trial_days'] = $planDetails['trial_days'];
            $yearly = isset($planDetails['billed_type']) && $planDetails['billed_type'] == 'yearly' ? true : false;
            $hasDiscount = false;
            if (!empty($planDetails['discounts'])) {
                foreach($planDetails['discounts'] as $discount) {
                    if (isset($discount['name']) && ($discount['name'] !== "Yearly Offer")) {
                        $plan['discount']['durationLimitInIntervals'] = $discount['duration_interval'] ?? 0;
                        if (isset($discount['type']) && isset($discount['value'])) {
                            $type = $discount['type'];
                            $hasDiscount = true;
                            if ($type == 'fixed') {
                                $plan['discount']['value']['amount'] = $discount['value'];
                            } elseif ($type == 'percentage') {
                                $plan['discount']['value']['percentage'] = $discount['value']/100;
                            }
                        }
                    }
                }
            }

            $this->updateParamsIfYearlyPlan($yearly, $plan, $data, $hasDiscount);
            $data['data'] = $plan;
            $this->commonObj->addLog('Data sent to remote for recurring paid plan: ' . json_encode($data), $this->logFile);

            $remoteResponse = $this->di->getObjectManager()->create(ShopifyPayment::class)->createRemotePayment($data, self::BILLING_TYPE_RECURRING);
        }

        $this->commonObj->addLog('Remote response: ' . json_encode($remoteResponse), $this->logFile);

        if (isset($remoteResponse['success'], $remoteResponse['data']['confirmation_url']) && $remoteResponse['success']) {
            //to update generated charge id with quote to validate redirection cases
            $queuedTask = new QueuedTasks;
            $queuedTaskIds = [];
            $queueData = [
                'user_id' => $userId,
                'message' => 'Payment initiated for plan activation!',
                'tag' => 'payment_process',
                'progress' => 0,
                'created_at' => date('c'),
                'marketplace' => self::TARGET_MARKETPLACE,
                'process_code' => 'payment_process_plan',
                'additional_data' => [
                    'quote_id' => $quoteId
                ]
            ];
            if ($queuedTask->checkQueuedTaskwithProcessTag('payment_process_plan', $shop['_id'])) {
                $queuedTaskIds = $this->getExistingTaskIds('payment_process_plan', $userId);
            } else {
                $queuedTaskIds[] = $queuedTask->setQueuedTask($shop['_id'], $queueData);
            }

            if(isset($remoteResponse['data']['id'])) {
                if($yearly) {
                    $idData = $remoteResponse['data']['id'] ?? "";
                    $idDataArr = explode("AppSubscription/", (string) $idData);
                    $chargeId = $idDataArr[count($idDataArr) - 1] ?? "";
                } else {
                    $chargeId = (string)$remoteResponse['data']['id'];
                }

                $setData['generated_charge_id'] = $chargeId;
                $setData['url_generated_at'] = date('Y-m-d H:i:s');
                if (!empty($queuedTaskIds)) {
                    $setData['queue_task_ids'] = $queuedTaskIds;
                }

                $this->paymentCollection->updateOne([
                    '_id' => $quoteId,
                    'type' => 'quote',
                    'user_id' => $userId
                ], [
                    '$set' => $setData
                ]);
            }

            return $remoteResponse['data']['confirmation_url'];
        }

        $this->commonObj->addLog('Some failure occurs', $this->logFile);
        return false;
    }

    /**
     * to process plan quote for a payment link
     */
    public function processPlanQuotePayment($quote)
    {
        $result = [];
        if (!empty($quote)) {
            $userId = $this->di->getUser()->id;
            $shopifyDomain = $this->getDomainName();
            $redirectUrl = $this->commonObj->getDefaultRedirectUrl($shopifyDomain);
            if($quote['status'] == self::QUOTE_STATUS_APPROVED) {
                return [
                    'success' => false,
                    'message' => 'Quote already processed!',
                    'redirect_url' => $redirectUrl
                ];
            }

            $settlementAmount = $this->getSettlementAmount($userId);
            if($settlementAmount > 0) {
                return [
                    'success' => false,
                    'message' => 'Cannot activate payment as you have an excess usage charge pending!',
                    'redirect_url' => $redirectUrl
                ];
            }

            $activePlan = $this->getActivePlanForCurrentUser($userId);
            if(!empty($activePlan)
                && ($activePlan['plan_details']['plan_id'] == $quote['plan_details']['plan_id'])
                && ($activePlan['plan_details']['custom_price'] == $quote['plan_details']['custom_price'])
                && ($activePlan['plan_details']['billed_type'] == $quote['plan_details']['billed_type'])
                && ($activePlan['plan_details']['category'] == $quote['plan_details']['category'])) {
                    return [
                        'success' => false,
                        'message' => 'Plan already active',
                        'redirect_url' => $redirectUrl
                    ];
            }

            $planDetails = $quote['plan_details'] ?? [];
            $planId = $planDetails['plan_id'] ?? "";
            $amount = $this->commonObj->getDiscountedAmount($planDetails);
            $planDetails['offered_price'] = $amount;
            $title = $planDetails['title'];
            $services = $planDetails['services_groups'];
            if (isset($planDetails['payment_type']) && ($planDetails['payment_type'] == self::BILLING_TYPE_ONETIME)) {
                $type = self::BILLING_TYPE_ONETIME;
            } else {
                $type = self::BILLING_TYPE_RECURRING;
            }

            $cappedAmount = $this->getCappedAmountForSelectedPlan($userId, $activePlan);
            if (isset($planDetails['capped_price'])) {
                if ($cappedAmount <= (float)$planDetails['capped_price']) {
                    $cappedAmount = (float)$planDetails['capped_price'];
                }
            }
            $trialDays = $this->getTrialDays($planDetails, $activePlan);
            $planDetails['trial_days'] = $trialDays;
            $paymentMethod = ['type' => 'redirect'];
            $result = $this->processPayment(
                $cappedAmount,
                $userId,
                $title,
                $amount,
                $services,
                $type,
                $planId,
                $paymentMethod,
                $planDetails
            );
            // if($this->isFreePlan($planDetails) && $result['success']) {
            //     $this->paymentCollection->deleteOne(
            //         [
            //             'type' => 'process',
            //             'user_id' => $userId,
            //             'upgrade' => true
            //         ]
            //     );
            //     $result = [
            //         'success' => true,
            //         'data' => ['confirmation_url' => $redirectUrl.'?shop=' . $shopifyDomain . '&success=true&message='.$result['message'].'&isPlan=true']
            //     ];
            // }
        } else {
            $result = [
                'success' => false,
                'message' => 'Quote data not found'
            ];
        }

        return $result;
    }

    /**
     * to update query for yearly plan
     */
    public function updateParamsIfYearlyPlan($yearly, &$plan, &$data, $hasDiscount = false): void
    {
        if ($yearly) {
            $plan['interval'] = 'ANNUAL';
            unset($plan['capped_amount']);
            unset($plan['terms']);
            $data['call_type'] = 'QL';
        }
        if ($hasDiscount && !isset($data['call_type'])) {
            $data['call_type'] = 'QL';
        }
    }

    /**
     * @param $data
     * @param bool $userId
     * @return array|bool
     */
    public function settleServices($data, $userId = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        if (!isset($data['type']) || !isset($data['amount'])) {
            return ["success" => false, "message" => "Required parameter not present."];
        }

        $this->logFile = 'plan/' . date('Y-m-d') . '/' . $userId . '_' . self::PLAN_JOURNEY_LOG . '.log';
        $this->commonObj->addLog(' --- Settlement process initiated --- ', $this->logFile);
        $this->commonObj->addLog('Stage 1: Data received' . json_encode($data, true), $this->logFile);

        $shop = $this->commonObj->getShop($this->di->getRequester()->getSourceId(), $userId);
        if (!empty($shop) && isset($shop['remote_shop_id'])) {
            $data['shop_id'] = $shop['remote_shop_id'];
        } else {
            return ["success" => false, "message" => "Shop not found."];
        }

        $homeUrl = $this->di->getConfig()->base_url_frontend;
        // $settlementAmount = $this->getSettlementAmount($userId);
        $userService = $this->getCurrentUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
        $quoteId = null;
        if (!empty($userService)) {
            if (isset($userService['postpaid']['is_capped']) && $userService['postpaid']['is_capped']) {
                return ["success" => false, "message" => "This usage is auto-deducted at our end"];
            }
            $settlementInfo = $this->getSettlementInfo($userService);
            if (!empty($settlementInfo) && isset($settlementInfo['invoice']['quote_id'])) {
                $quoteId = $settlementInfo['invoice']['quote_id'];
            }
        }

        $cappedAmount = $data['amount'];
        if ($data['type'] == self::BILLING_TYPE_ONETIME) {
            if (is_null($quoteId) || empty($quoteId)) {
                return ["success" => false, "message" => "Quote Id not found."];
            }

            $test = false;
            if ($this->isTestUser($userId)) {
                $test = true;
            }

            $plan = [
                'name' => date("F", strtotime(date("Y-m-d"))) . ' ' . " month's Excess Usage Charge",
                'price' => $cappedAmount,
                'test' => $test,
                'return_url' => $homeUrl . 'plan/plan/OneTimePaymentCheck?quote_id=' . $quoteId . '&shop=' .
                    $shop['domain'] . '&state=' . $userId . '&source_id=' . $this->di->getRequester()->getSourceId() .
                    '&app_tag=' . $this->di->getAppCode()->getAppTag()
            ];
            $data['data'] = $plan;
            $this->commonObj->addLog('Remote data sent: ' . json_encode($data, true), $this->logFile);
            $remoteResponse = $this->di->getObjectManager()->create(ShopifyPayment::class)->createRemotePayment($data, self::BILLING_TYPE_ONETIME);
            $this->commonObj->addLog('Remote response ' . json_encode($remoteResponse, true), $this->logFile);
            if (isset($remoteResponse['data']['confirmation_url']) && $remoteResponse['success']) {
                $toReturnData['confirmation_url'] = $remoteResponse['data']['confirmation_url'];
                $queuedTask = new QueuedTasks;
                $queuedTaskIds = [];
                $queueData = [
                    'user_id' => $userId,
                    'message' => 'Excess charge payment initiated!',
                    'tag' => 'payment_process',
                    'progress' => 0,
                    'created_at' => date('c'),
                    'marketplace' => self::TARGET_MARKETPLACE,
                    'process_code' => 'payment_process_settlement',
                    'additional_data' => [
                        'quote_id' => $quoteId
                    ]
                ];
                if ($queuedTask->checkQueuedTaskwithProcessTag('payment_process_settlement', $shop['_id'])) {
                    $queuedTaskIds = $this->getExistingTaskIds('payment_process_settlement', $userId);
                } else {
                    $queuedTaskIds[] = $queuedTask->setQueuedTask($shop['_id'], $queueData);
                }

                $setData['generated_charge_id'] = (string)$remoteResponse['data']['id'];
                $setData['url_generated_at'] = date('Y-m-d H:i:s');
                if (!empty($queuedTaskIds)) {
                    $setData['queue_task_ids'] = $queuedTaskIds;
                }

                $this->paymentCollection->updateOne([
                    '_id' => $quoteId,
                    'type' => 'quote',
                    'user_id' => $userId
                ], [
                    '$set' => $setData
                ]);
                return ['success' => true, 'data' => $toReturnData];
            }

            return false;
        }

        if ($data['type'] == self::BILLING_TYPE_CAPPED) {
            $settlementAmount = $this->getSettlementAmount($userId);
            $activePlan = $this->getActivePlanForCurrentUser($userId);
            if ($this->isFreePlan($activePlan['plan_details'])) {
                return ['success' => false, 'message' => 'Capped credit not supported for free users!'];
            }

            $paymentInfo = $this->getPaymentInfoForCurrentUser($userId);
            if (isset($paymentInfo['marketplace_data']['id'])) {
                $chargeId = $paymentInfo['marketplace_data']['id'];
            } else {
                return ["success" => false, "message" => "Charge id not found!"];
            }

            if ($cappedAmount >= $settlementAmount) {
                if (
                    !empty($activePlan) && isset($activePlan['capped_amount'])
                    && (float)$activePlan['capped_amount'] == (float)$cappedAmount
                ) {
                    $this->commonObj->addLog('Capped amount already set', $this->logFile);
                    return ['success' => true, 'message' => 'Capped amount already set.'];
                }

                $plan = [
                    'capped_amount' => (float)$cappedAmount,
                    'id' => $chargeId,
                    'shop_id' => $shop['remote_shop_id']
                ];
                $this->commonObj->addLog('Remote data: ' . json_encode($plan), $this->logFile);

                $remoteResponse = $this->di->getObjectManager()->get(ShopifyPayment::class)->initApiClient()
                    ->call('billing/recurring', [], $plan, 'PUT');

                $this->commonObj->addLog('Remote response: ' . json_encode($remoteResponse), $this->logFile);

                if (isset($remoteResponse['success']) && $remoteResponse['success'] && isset($remoteResponse['data']['update_capped_amount_url'])) {
                    $toReturnData['confirmation_url'] = $remoteResponse['data']['update_capped_amount_url'];
                    return ['success' => true, 'data' => $toReturnData];
                }

                return false;
            }

            $this->commonObj->addLog('Capped amount must be greater than or equal to ' . ceil($settlementAmount), $this->logFile);
            return [
                "success" => false,
                "message" => 'Capped amount must be greater than or equal to ' . ceil($settlementAmount)
            ];
        }
    }

    /**
     * @param $quoteId
     * @return array
     */
    public function processQuotePayment($quote)
    {
        if (!empty($quote)) {
            $userId = $this->di->getUser()->id;
            $shopifyDomain = $this->getDomainName();
            $redirectUrl = $this->commonObj->getDefaultRedirectUrl($shopifyDomain);
            $shopId = $this->commonObj->getShopifyShopId();
            $homeUrl = $this->di->getConfig()->base_url_frontend;
            $shop = $this->commonObj->getShop($shopId, $userId);
            if(empty($shop)) {
                return [
                    'success' => false,
                    'message' => 'Shop not found!'
                ];
            }

            $data['shop_id'] = $shop['remote_shop_id'] ?? "";
            if (isset($quote['settlement_id'])) {
                $data = [
                    'amount' => $quote['settlement_details']['settlement_amount'],
                    'type' => 'onetime'
                ];
                $result = $this->settleServices($data, $userId);
            } else {
                $test = $this->isTestUser($userId);
                $plan = [
                    'name' => $quote['plan_details']['title'],
                    'price' => $quote['plan_details']['custom_price'],
                    'test' => $test,
                    'return_url' => $homeUrl . 'plan/plan/customPaymentCheck?shop=' . $shopifyDomain .
                        '&process_id=' . $quote['_id']
                ];
                $data['data'] = $plan;
                $remoteResponse = $this->di->getObjectManager()->create(ShopifyPayment::class)->createRemotePayment($data, self::BILLING_TYPE_ONETIME);
                $result = [];
                if (isset($remoteResponse['success'], $remoteResponse['data']['confirmation_url']) && $remoteResponse['success']) {
                    $toReturnData['confirmation_url'] = $remoteResponse['data']['confirmation_url'];
                    $queuedTask = new QueuedTasks;
                    $queuedTaskIds = [];
                    $queueData = [
                        'user_id' => $userId,
                        'message' => 'Payment initiated for customization!',
                        'tag' => 'payment_process',
                        'progress' => 0,
                        'created_at' => date('c'),
                        'marketplace' => self::TARGET_MARKETPLACE,
                        'process_code' => 'payment_process_custom',
                        'additional_data' => [
                            'quote_id' => $quote['_id']
                        ]
                    ];
                    if ($queuedTask->checkQueuedTaskwithProcessTag('payment_process_custom', $shop['_id'])) {
                        $queuedTaskIds = $this->getExistingTaskIds('payment_process_custom', $userId);
                    } else {
                        $queuedTaskIds[] = $queuedTask->setQueuedTask($shop['_id'], $queueData);
                    }

                    $setData['generated_charge_id'] = (string)$remoteResponse['data']['id'];
                    $setData['url_generated_at'] = date('Y-m-d H:i:s');
                    if (!empty($queuedTaskIds)) {
                        $setData['queue_task_ids'] = $queuedTaskIds;
                    }

                    $this->paymentCollection->updateOne([
                        '_id' => $quote['_id'],
                        'type' => 'quote',
                        'user_id' => $userId
                    ], ['$set' => $setData ]);
                    $result = ['success' => true, 'data' => $toReturnData, 'message' => ''];
                } else {
                    $result = ['success' => false, 'message' => 'Confirmation url not found in processing!', 'redirect_url' => $redirectUrl];
                }
            }

            return $result;
        }

        return [
            'success' => false,
            'message' => 'Quote not found.'
        ];
    }

    /**
     * @param $userId
     * @param $chargeData
     * @param int $settlementAmount
     * @return bool
     */
    public function canDeductSettlementAmount($userId, $chargeData, $settlementAmount = 0)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $settlementAmount = (float)$settlementAmount;
        $balanceRemaining = 0;
        $balanceRemaining = isset($chargeData['capped_amount'], $chargeData['balance_used']) ? ($chargeData['capped_amount'] - $chargeData['balance_used']) : 0;
        if ($balanceRemaining > 0 && $settlementAmount > 0 && $balanceRemaining >= $settlementAmount) {
            return true;
        }
        return false;
    }

    /**
     * @param bool $userId
     * @param $cappedAmount
     * @param $charge_id
     * @return bool
     */
    public function createUsage($userId, $cappedAmount, $chargeData)
    {
        $logFile = 'plan/usage-charge/'.date('Y-m-d').'.log';
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        // $this->commonObj->addLog('Usage charge generated for user => '.$userId, $logFile);
        // $this->commonObj->addLog('cappedAmount => '.$cappedAmount, $logFile);
        // $this->commonObj->addLog('chargeData => '.json_encode($chargeData), $logFile);

        $sourceId = $this->di->getRequester()->getSourceId();
        $userData = $this->commonObj->getUserDetail($userId);
        $shop = [];
        if (!empty($userData)) {
            foreach ($userData['shops'] as $shops) {
                if ($shops['_id'] == $sourceId) {
                    $shop = $shops;
                    break;
                } elseif ($shops['marketplace'] == self::SOURCE_MARKETPLACE) {
                    $shop = $shops;
                    break;
                }
            }
        } else {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }

        if (!empty($shop) && isset($shop['remote_shop_id'])) {
            $data['shop_id'] = $shop['remote_shop_id'];
            $data['description'] = date("F") . ' ' . " month Excess Usage Charge";
            $data['price'] = $cappedAmount;
            $data['charge_id'] = $chargeData['id'];
            $data['line_item_id'] = $chargeData['lineItemsId'];
            $this->commonObj->addLog('data to send => '.json_encode($data), $logFile);
            $remoteResponse = $this->di->getObjectManager()->get(ShopifyPayment::class)->initApiClient()->call('usageCharge', [], $data, 'POST');
            $this->commonObj->addLog('remoteResponse => '.json_encode($remoteResponse), $logFile);
            if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                return $remoteResponse;
            }

            return [
                'success' => false,
                'message' => 'Unable to process remote call'
            ];
        }

        return [
            'success' => false,
            'message' => 'Shop data not found'
        ];
    }

    public function getPlanInfo($userId = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $planInfo['user_services'] = $this->getCurrentUserServices($userId);
        $planInfo['active_plan'] = $this->getActivePlanForCurrentUser($userId);
        $planInfo['sync_activated'] = $this->isSyncAllowed();
        $planInfo['product_import_sync_activated'] = $this->isImportSyncAllowed($userId);
        $planInfo['payment_info'] = $this->getPaymentInfoForCurrentUser($userId);
        $planInfo['settlement_invoice']=$this->getPendingSettlementInvoice($userId);
        return $planInfo;
    }

    /**
     * @param bool $userId
     * @return array
     */
    public function getActivePlan($userId = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $userService = $this->getUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
        $activePlanData = $this->getActivePlanForCurrentUser($userId);
        $pendingProcesses = $this->getPendingProcesses($userId);
        if (!empty($activePlanData)) {
            $finalData['pending_processes'] = $pendingProcesses;
            $finalData['active_plan'] = $activePlanData;
            $finalData['is_max_plan'] = $this->isMaxPlan($activePlanData['plan_details'], $userService);
            $finalData['user_service'] = $userService;
            $finalData['sync_activated'] = $this->isSyncAllowed($userService);
            $finalData['product_import_sync_activated'] = $this->isImportSyncAllowed($userId);
            // $finalData['additional_services'] = $this->getCurrentUserServices($userId, self::SERVICE_TYPE_ADDITIONAL_SERVICES);
            $finalData['all_services'] = $this->getCurrentUserServices($userId);

            $finalData['payment_info'] = $this->getPaymentInfoForCurrentUser($userId);
            $recommendedPlans = $this->di->getObjectManager()->get(Recommendations::class)->getPersonalizedRecommendations($userId);
            $finalData['next_recommended_plan'] = (isset($recommendedPlans['plans']) && !empty($recommendedPlans['plans'])) ? $recommendedPlans['plans'] : [];
            $finalData['next_recommended_plan_msg'] = $recommendedPlans['message'] ?? "";
            $finalData['custom_recommendation'] = $recommendedPlans['custom'] ?? false;

            /* for add_on feature */
            // $addOnModel = $this->di->getObjectManager()->get('\App\Plan\Models\AddOn');
            // $finalData['active_add_on'] = $addOnModel->getActiveUserAddOns($userId);
            // $finalData['available_add_ons'] = $addOnModel->getUnusedUserAddOns($userId);
            $message = "";
            if (!$finalData['sync_activated']) {
                $message = "You have used your plan's complete credit for this month if your order rate is higher you can upgrade your plan or wait till the plan renew at the beginning of the month";
            }
            $settlementInfo = $this->getSettlementInfo($finalData['user_service']);
            if (!empty($settlementInfo)) {
                $finalData['settlement_info'] = $settlementInfo;
                if (isset($settlementInfo['invoice']) && !empty($settlementInfo['invoice']) && !isset($settlementInfo['is_capped'])) {
                    $finalData['settlement_info']['show_pay_now'] = $this->canShowSettlementInfo($finalData['user_service'], $settlementInfo);
                    if (!$finalData['sync_activated']) {
                        $message = "Your excess usage charges are pending. You've been directed to the pricing page to clear the outstanding amount and ensure uninterrupted access to the app. Thank you!";
                    }
                }
            }

            //need to confirm if this need to keep or need to be removed
            /* Temporary solution to maintain order progress bar, we will remove it later start*/
            if (isset($finalData['user_service']) && count($finalData['user_service'])) {
                $finalData['user_service']['prepaid']['total_used_credits'] = (int)$finalData['user_service']['prepaid']['total_used_credits'];
            }
            /* Temporary solution to maintain order progress bar, we will remove it later end*/

            return [
                'success' => true,
                'message' => $message,
                'data' => $finalData,
                'trial_exhausted' => $this->trialPlanExhausted($userId)
            ];
        }

        return [
            'trial_exhausted' => $this->trialPlanExhausted($userId),
            'success' => false,
            'message' => 'You have no active plan',
            'data' => ['pending_processes' => $pendingProcesses]
        ];
    }

    /**
     * @param bool $userId
     * @return array
     */
    public function isPlanActive($userId = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $activePlanData = $this->getActivePlanForCurrentUser($userId);
        if (!empty($activePlanData)) {
            return [
                'success' => true,
                'message' => ''
            ];
        }

        return [
            'success' => false,
            'message' => 'You have no active plan'
        ];
    }

    /**
     * @param array $userService
     * @return bool
     */
    public function canShowSettlementInfo($userService = [], $settlementInfo = [])
    {
        $response = false;
        /**
         * this code is updated as now client will be able to pay settlement on some parameters like:
         * If completely or 90% exhaust
         * if it is 2 days before the last payment date or 
         */
          if (!empty($settlementInfo['invoice'])) {
            if (
                isset($userService['postpaid']['capped_credit']) &&
                isset($userService['postpaid']['total_used_credits']) &&
                ((int)$userService['postpaid']['total_used_credits'] > 0)
            ) {
                $percentUsed = (90 / 100) * (int)$userService['postpaid']['capped_credit'];
                if ((int)$userService['postpaid']['total_used_credits'] == (int)$userService['postpaid']['capped_credit']) {
                    $response = true;
                } elseif($percentUsed <= (int)$userService['postpaid']['total_used_credits']) {
                    $response = true;
                } elseif(isset($settlementInfo['invoice']['last_payment_date'])) {
                    $previousMonthLastDate = date("Y-m-d", strtotime("last day of previous month"));
                    $rangeDate = date('Y-m-d', strtotime($settlementInfo['invoice']['last_payment_date'] . ' -2 day'));
                    if($settlementInfo['invoice']['last_payment_date'] <= $previousMonthLastDate) {
                        $response = true;
                    } elseif(date('Y-m-d') >= $rangeDate) {//to show pay now from 2 days
                        $response = true;
                    }
                }

                if (isset($userService['deactivate_on']) && ($userService['deactivate_on'] <= date('Y-m-d'))) {
                    $response = true;
                }
            }
        }

        return $response;
    }

    /**
     * @param array $userService
     * @return array
     */
    public function getSettlementInfo($userService = [])
    {
        $settlementInfo = [];
        $pendingSettlement = $this->getPendingSettlementInvoice($userService['user_id']);
        if(!empty($pendingSettlement)) {
            if (isset($pendingSettlement['is_capped']) && $pendingSettlement['is_capped']) {
                $settlementInfo['invoice'] = $pendingSettlement;
                $settlementInfo['is_capped'] = $pendingSettlement['is_capped'];
            } elseif (
                isset($userService['postpaid']['capped_credit']) &&
                isset($userService['postpaid']['total_used_credits']) &&
                (int)$userService['postpaid']['total_used_credits'] > 0
            ) {
                $unitQty = (int)$userService['postpaid']['unit_qty'];
                $perUnitUsagePrice = (float)$userService['postpaid']['per_unit_usage_price'];
                $exceedLimits = (int)$userService['postpaid']['total_used_credits'];
                $settlementInfo['settlement_price'] = round((ceil($exceedLimits / $unitQty) * $perUnitUsagePrice), 2);
                $settlementInfo['settlement_order'] = $exceedLimits;
                $settlementInfo['slider_min'] = ceil($settlementInfo['settlement_price']);
                $settlementInfo['slider_max'] = ceil(
                    ceil($userService['postpaid']['capped_credit'] / $unitQty) * $perUnitUsagePrice
                );
                $settlementInfo['invoice'] = $pendingSettlement;
            }
        }

        return $settlementInfo;
    }

    /**
     * @param bool $userId
     * @param $cappedAmount
     * @return bool
     */
    public function updateCappedAmountForActivePlan($userId = false, $cappedAmount = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        if (!$cappedAmount) {
            return false;
        }

        $activePlan = $this->getActivePlanForCurrentUser($userId);
        if (!empty($activePlan) && isset($activePlan['_id'])) {
            $activePlan['updated_at'] = date('Y-m-d H:i:s');
            $activePlan['capped_amount'] = round((float)$cappedAmount, 2);
            $mongoResult = $this->paymentCollection->replaceOne(
                ['_id' => $activePlan['_id']],
                $activePlan,
                ['upsert' => true]
            );
            if ($mongoResult->isAcknowledged()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool $userId
     * @return float
     */
    public function getCappedAmountForSelectedPlan($userId = false, $activePlan = [])
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        if (empty($activePlan)) {
            $activePlan = $this->getActivePlanForCurrentUser($userId);
        }
        return (float)($activePlan['capped_amount'] ?? self::DEFAULT_CAPPED_AMOUNT);
    }

    /**
     * @param bool $userId
     * @return mixed
     */
    public function getActivePlanForCurrentUser($userId = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $activePlanFilterData = [
            'user_id' => (string)$userId,
            'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
            'status' => self::USER_PLAN_STATUS_ACTIVE,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            'marketplace' => self::TARGET_MARKETPLACE,
        ];
        return $this->paymentCollection->findOne($activePlanFilterData, self::TYPEMAP_OPTIONS);
    }

    /**
     * @param bool $userId
     * @return float|int
     */
    public function getSettlementAmount($userId = false)
    {
        $settlementAmount = 0;
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $serviceData = $this->getCurrentUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
        if (!empty($serviceData)) {
            if (!empty($serviceData['postpaid'])) {
                if (isset($userService['credit_limit']) && $userService['credit_limit'] == self::CREDIT_LIMIT_UNLIMITED) {
                    return $settlementAmount;
                }

                $cappedCredit = (int)($serviceData['postpaid']['capped_credit'] ?? 0);
                if ($cappedCredit == 0) {
                    return $settlementAmount;
                }

                if (isset($serviceData['postpaid']['total_used_credits']) && $serviceData['postpaid']['total_used_credits'] > 0) {
                    $unitQty = (int)$serviceData['postpaid']['unit_qty'] ?? 0;
                    if ($unitQty <= 0) {
                        return $settlementAmount;
                    }

                    $perUnitUsagePrice = (float)$serviceData['postpaid']['per_unit_usage_price'] ?? 0;
                    $exceedLimits = (int)$serviceData['postpaid']['total_used_credits'] ?? 0;
                    $settlementAmount += round((ceil($exceedLimits / $unitQty) * $perUnitUsagePrice), 2);
                }
            }
        }

        return $settlementAmount;
    }

    /**
     * @param bool $userId
     * @param bool $serviceType
     * @return mixed
     */
    public function getUserServices($userId = false, $serviceType = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $userserviceFilterData = [
            'user_id' => (string)$userId,
            'type' => self::PAYMENT_TYPE_USER_SERVICE,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            'marketplace' => self::TARGET_MARKETPLACE,
        ];
        if ($serviceType !== false) {
            $userserviceFilterData['service_type'] = $serviceType;
            $userService = $this->paymentCollection->findOne($userserviceFilterData, self::TYPEMAP_OPTIONS);
            if (!empty($userService) && ($serviceType == self::SERVICE_TYPE_ORDER_SYNC)) {
                $this->checkAndRenewPlanForCurrentUser($userService, $userId);
            }

            return $userService;
        }

        return $this->paymentCollection->find($userserviceFilterData, self::TYPEMAP_OPTIONS)->toArray();
    }

    /** not used any where for now
     * 
     * @param bool $userId
     * @return float|int
     */
    public function getRemainingTrailDays($userId = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $getTrailDays = 42;
        $shop = $this->commonObj->getShop($this->di->getRequester()->getSourceId(), $userId);
        if (isset($shop['created_at']) && is_string($shop['created_at'])) {
            $activeDate = strtotime(substr($shop['created_at'], 0, 10));
            $today = time();
            $datediff = ceil(($today - $activeDate) / 86400);
            if ($datediff > $getTrailDays) {
                $days = 0;
            } else {
                $days = $getTrailDays - $datediff;
            }
        } else {
            $activeDate = isset($shop['created_at']) ? $shop['created_at']->toDateTime()->getTimestamp() : time();
            $today = time();
            $datediff = ceil(($today - $activeDate) / 86400);
            if ($datediff > $getTrailDays) {
                $days = 0;
            } else {
                $days = $getTrailDays - $datediff;
            }
        }

        return $days;
    }

    /**
     * for cancelling the recurring of the client
     */
    public function cancelRecurryingCharge($rawBody)
    {
        if (isset($rawBody['user_id'])) {
            $userId = $rawBody['user_id'];
        } else {
            return [
                'success' => false,
                'message' => "User Id required to cancel client's recurring"
            ];
        }

        $diRes = $this->commonObj->setDiForUser($userId);
        if (!$diRes['success']) {
            return [
                'success' => false,
                'message' => 'Unable to cancel recurring user not exist!'
            ];
        }

        $response = $this->getActivePlan($userId);
        if ($response['success']) {
            $planData = $response['data'];
            $shop = $this->commonObj->getShop($this->commonObj->getShopifyShopId(), $userId);
            $shopId = $shop['remote_shop_id'] ?? "";
            $status = $planData['active_plan']['status'];
            $title = $planData['active_plan']['plan_details']['title'];
            if ($this->isFreePlan($planData['active_plan']['plan_details']) || $this->isTrialPlan($planData['active_plan']['plan_details'])) {
                return ['success' => false, 'message' => 'This user is on free/trial plan so we cannot process!'];
            }

            if(($planData['active_plan']['payment_type'] ?? self::BILLING_TYPE_RECURRING) == self::BILLING_TYPE_ONETIME) {
                return ['success' => false, 'message' => 'This user is on onetime plan so we cannot cancel the recurring!'];
            }

            if (!empty($userId) && ($status == self::USER_PLAN_STATUS_ACTIVE) && isset($planData['payment_info']['marketplace_data']['id'])) {
                $chargeId = $planData['payment_info']['marketplace_data']['id'];
                $apiData = ['id' => $chargeId, 'shop_id' => $shopId];
                if (!isset($apiData['shop_id']) || empty($apiData['shop_id'])) {
                    return ['success' => false, 'message' => 'No Shop ID found'];
                }

                $this->commonObj->addLog('User : ' . json_encode($userId), 'plan/recurring-cancel' . date('Y-m-d') . '.log');
                $this->commonObj->addLog('Api data send: ' . json_encode($apiData), 'plan/recurring-cancel' . date('Y-m-d') . '.log');
                $remoteResponse = $this->di->getObjectManager()->get(ShopifyPayment::class)->cancelPayment($apiData);
                $this->commonObj->addLog('Recurring cancelled forceful response: ' . json_encode($remoteResponse), 'plan/recurring-cancel' . date('Y-m-d') . '.log');
                if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                    $this->deactivatePlan('paid', $userId, $chargeId);
                    $this->paymentCollection->updateOne(
                        [
                            'user_id' => $userId,
                            'type' => self::PAYMENT_TYPE_USER_SERVICE,
                            'source_marketplace' => self::SOURCE_MARKETPLACE,
                            'target_marketplace' => self::TARGET_MARKETPLACE,
                            'service_type' => self::SERVICE_TYPE_ORDER_SYNC,
                        ],
                        ['$set' => [self::FORCEFULLY_CANCELLED => true]]
                    );
                    return ['success' => true, 'message' => $title . ' plan cancelled successfully.'];
                }

                return ['success' => false, 'message' => 'Failure in recurring cancel at remote.'];
            }

            return ['success' => false, 'message' => 'Cannot cancel recurring!'];
        }

        return ['success' => false, 'message' => 'Unable to cancel recurring.'];
    }


    /**
     * to activate free plan forcefully
     * @param $rawBody
     * @return array
     */
    public function forcefullyFreePlanPurchase($rawBody)
    {
        $userId = $rawBody['user_id'] ?? $this->di->getUser()->id;
        $isPlanActiveResponse = $this->isPlanActive($userId);
        if (!$isPlanActiveResponse['success']) {
            $trialPlan = $this->planCollection->findOne(['code' => 'trial', 'title' => 'Trial', 'billed_type' => self::BILLED_TYPE_MONTHLY], self::TYPEMAP_OPTIONS);
            if (empty($trialPlan)) {
                return ['success' => false, 'message' => 'Unable to find trial plan.'];
            }
            $setRes = $this->commonObj->setDiRequesterAndTag($userId);
            if(!$setRes['success']) {
                return $setRes;
            }
            return  $this->activateTrialPlan($trialPlan, $userId);
            $freePlan = $this->planCollection->findOne(['title' => 'Free', 'billed_type' => self::BILLED_TYPE_MONTHLY], self::TYPEMAP_OPTIONS);
            if (empty($freePlan)) {
                return ['success' => false, 'message' => 'Unable to find monthly free plan.'];
            }

            $setRes = $this->commonObj->setDiRequesterAndTag($userId);
            if(!$setRes['success']) {
                return $setRes;
            }

            $transactionData['user_id'] = $userId;
            $transactionData['old_user_services'] = $this->getCurrentUserServices($userId);
            $planId = $freePlan['plan_id'] ?? "";
            $quoteId = $this->setQuoteDetails(Plan::QUOTE_STATUS_APPROVED, $userId, $planId, $freePlan, self::QUOTE_TYPE_PLAN);
            $transactionData['quote_id'] = $quoteId;
            $transactionData['app_tag'] = $this->di->getAppCode()->getAppTag();

            $paymentId = $this->setPaymentDetails($userId, Plan::PAYMENT_STATUS_APPROVED, $quoteId, []);
            $transactionData['payment_id'] = $paymentId;
            $response = $this->updateUserPlanServices($quoteId, $this->getCappedAmountForSelectedPlan($userId));
            $shop = $this->commonObj->getShop($this->commonObj->getShopifyShopId($userId), $userId);

            $mailResponse = $this->di->getObjectManager()->get(Email::class)->sendPlanActivationMail($freePlan, $userId);
            $transactionData['plan_activation_mail'] = $mailResponse;
            $this->di->getObjectManager()->get(PlanEvent::class)->sendAppPurchaseEvent($freePlan);
            $this->di->getObjectManager()->get(Transaction::class)->planActivated($transactionData);

            if (!empty($shop)) {
                $this->reUpdateEntry($userId, $shop);
            }

            if ($response) {
                return ['success' => true, 'message' => 'Free plan activated successfully.'];
            }

            return ['success' => false, 'message' => 'Unable to purchase Free plan.'];
        }

        return ['success' => false, 'message' => 'You already have an active plan.'];
    }

    /**to add defaults plans in db
     * 
     * @return array
     */
    public function addAmazonDefaultPlan($filter = [])
    {
        $path = BP . DS . 'app' . DS . 'code' . DS . 'plan' . DS . 'utility' . DS . 'plan.json';
        $planJson = file_get_contents($path);
        $plans = json_decode($planJson, true);
        $syncByCategory = false;
        if(isset($filter['category']) && !empty($filter['category'])) {
            $category = $filter['category'];
            $syncByCategory = true;
        }

        if($syncByCategory) {
            foreach ($plans as $planData) {
                if(isset($planData['category']) && $planData['category'] == $category) {
                    $planData['plan_id'] ??= (string)$this->baseMongo->getCounter('plan_id');
                    $planData['validity'] = (int)$planData['validity'];
                    $this->planCollection->replaceOne(
                        ['plan_id' => $planData['plan_id'], 'validity' => $planData['validity'], 'category' => $category],
                        $planData,
                        ['upsert' => true]
                    );
                }
            }
        } else {
            foreach ($plans as $planData) {
                $planData['plan_id'] ??= (string)$this->baseMongo->getCounter('plan_id');
                $planData['validity'] = (int)$planData['validity'];
                $this->planCollection->replaceOne(
                    ['plan_id' => $planData['plan_id'], 'validity' => $planData['validity']],
                    $planData,
                    ['upsert' => true]
                );
            }
        }

        return [
            'success' => true,
            'message' => "Successfully synced."
        ];
    }

    /**
     * while calling this function app_tag & source_id must be set.
     *
     * @param bool $userId
     * @param $serviceType
     * @param int $usedLimit
     * @return array
     */
    public function updateUsedLimitForCurrentUser($userId = false, $serviceType = self::SERVICE_TYPE_ORDER_SYNC, $usedLimit = 0)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        if (!empty($serviceType)) {
            $serviceData = $this->getCurrentUserServices($userId, $serviceType);
            if (!empty($serviceData)) {
                /**to handle unlimited credit limit */
                // if (isset($serviceData['credit_limit']) && $serviceData['credit_limit'] == self::CREDIT_LIMIT_UNLIMITED) {
                //     $userserviceFilterData = [
                //         'user_id' => (string)$userId,
                //         'type' => self::PAYMENT_TYPE_USER_SERVICE,
                //         'source_marketplace' => self::SOURCE_MARKETPLACE,
                //         'target_marketplace' => self::TARGET_MARKETPLACE,
                //         'marketplace' => self::TARGET_MARKETPLACE,
                //         'service_type' => $serviceType,
                //     ];
                //     $serviceData['updated_at'] = date('Y-m-d H:i:s');
                //     $serviceData['prepaid']['total_used_credits'] += $usedLimit;
                //     $serviceData['prepaid']['all_time_credits_used'] += $usedLimit;
                //     $this->paymentCollection->replaceOne(
                //         $userserviceFilterData,
                //         $serviceData,
                //         ['upsert' => true]
                //     );
                //     return [
                //         'success' => true,
                //         'message' => 'Successfully updated.'
                //     ];
                // }

                 /**to handle unlimited credit limit */

                $usedLimit = (int)$usedLimit;
                /** when the syncing is deactivated for the client */
                if (!$this->isSyncAllowed($serviceData)) {
                    $this->di->getObjectManager()->get(Email::class)->sendOrderSyncPauseMail($userId);
                    $this->di->getObjectManager()->get(Transaction::class)->syncDeactivated($userId);
                    $this->di->getObjectManager()->get(PlanEvent::class)->sendServiceExhaustedEvent($serviceData);
                    return [
                        'success' => false,
                        'message' => 'Sync off.',
                        'code' => 'exhaust'
                    ];
                }
                /** when the syncing is deactivated for the client */

                if ($usedLimit >= 0) {
                    $maxAvailableCredits = (int)$serviceData['prepaid']['available_credits']
                        + (int)$serviceData['postpaid']['available_credits'];

                    $this->updateMaxAvailableCreditsWithAddOnsIfNeeded(
                        $maxAvailableCredits,
                        $usedLimit,
                        $serviceData
                    );

                    if ($usedLimit <= $maxAvailableCredits) {
                        $postPaid = false;
                        $userserviceFilterData = [
                            'user_id' => (string)$userId,
                            'type' => self::PAYMENT_TYPE_USER_SERVICE,
                            'source_marketplace' => self::SOURCE_MARKETPLACE,
                            'target_marketplace' => self::TARGET_MARKETPLACE,
                            'marketplace' => self::TARGET_MARKETPLACE,
                            'service_type' => $serviceType,
                        ];
                        $serviceData['updated_at'] = date('Y-m-d H:i:s');
                        if ($usedLimit <= $serviceData['prepaid']['available_credits']) {
                            $serviceData['prepaid']['total_used_credits'] += $usedLimit;
                            $finalAvailableCredit = $serviceData['prepaid']['available_credits'] - $usedLimit;
                            $serviceData['prepaid']['available_credits'] = ($finalAvailableCredit < 0) ? 0 : $finalAvailableCredit;
                        } else {
                                $balanceLimit = $usedLimit - $serviceData['prepaid']['available_credits'];

                                /* partial deduction from prepaid */
                                $serviceData['prepaid']['total_used_credits'] += $serviceData['prepaid']['available_credits'];
                                $serviceData['prepaid']['available_credits'] = 0;
    
                                /* balance deduction from postpaid */
                                $serviceData['postpaid']['total_used_credits'] += $balanceLimit;
                                $finalAvailableCredit = $serviceData['postpaid']['available_credits'] - $balanceLimit;
                                $serviceData['postpaid']['available_credits'] = ($finalAvailableCredit < 0) ? 0 : $finalAvailableCredit;
                                $postPaid = true;
    
                                // if (isset($serviceData['postpaid']['is_capped']) && $serviceData['postpaid']['is_capped']) {
                                //     $cappedAmount = $serviceData['postpaid']['capped_amount'] ?? 0;
                                //     $balanceUsed = $serviceData['postpaid']['balance_used'] ?? 0;
                                //     $perUnitUsage = $serviceData['postpaid']['per_unit_usage_price'] ?? 0;
                                //     if ($cappedAmount) {
                                //         $balanceRemaining = (int)ceil(($cappedAmount - $balanceUsed) / $perUnitUsage);
                                //         if ($balanceRemaining <= 0) {
                                //             return [
                                //                 'success' => false,
                                //                 'message' => 'No limit available.',
                                //                 'code' => 'exhaust'
                                //             ];
                                //         }
                                //         if ($balanceRemaining >= $balanceLimit) {
                                //             $serviceData['postpaid']['total_used_credits'] += $balanceLimit;
                                //             $finalAvailableCredit = $balanceRemaining -  (int)$serviceData['postpaid']['total_used_credits'];
                                //             $serviceData['postpaid']['available_credits'] = ($finalAvailableCredit < 0) ? 0 : $finalAvailableCredit;
                                //         } else {
    
                                //         }
                                //     }
                                //     $postPaid = true;
                                // } else {
                                // }
                        }

                        $this->paymentCollection->replaceOne(
                            $userserviceFilterData,
                            $serviceData,
                            ['upsert' => true]
                        );
                        if ($postPaid) {
                            $userService = $this->getCurrentUserServices($userId, $serviceType);
                            $this->createSettlementInfo($userService, self::PAYMENT_STATUS_PENDING, $userId);
                        }

                        $this->di->getObjectManager()->get(Email::class)->sendMailForOrderLimitExceed($serviceData, $userId);

                        $totalAvailableCredit = ($serviceData['prepaid']['available_credits'] ?? 0)
                            + ($serviceData['postpaid']['available_credits'] ?? 0)
                            + ($serviceData['add_on']['available_credits'] ?? 0);

                        if ($totalAvailableCredit == 0 && (isset($serviceData['trial_service'])) && $serviceData['trial_service']) {
                            $this->disableTrialPlan($userId);
                        }

                        if ($totalAvailableCredit <= 0) {
                            //if it postpaid and is capped then need to process teh settlement deduction
                            if ($postPaid && !empty($userService) && isset($userService['postpaid']['is_capped'])) {
                                //we need to perform immediate credit deduction
                                $this->di->getObjectManager()->get(Settlement::class)->checkSettlementAndProcess($userId, $userService);
                            }

                            $this->di->getObjectManager()->get(Email::class)->sendOrderSyncPauseMail($userId);
                            $this->di->getObjectManager()->get(Transaction::class)->syncDeactivated($userId);
                            $this->di->getObjectManager()->get(PlanEvent::class)->sendServiceExhaustedEvent($serviceData);
                            return [
                                'success' => false,
                                'message' => 'sync stopped as no credits left',
                                'code' => 'exhaust'
                            ];
                        }

                        return [
                            'success' => true,
                            'message' => 'Successfully updated.'
                        ];
                    }

                    if ($maxAvailableCredits) {
                        return [
                            'success' => false,
                            'message' => 'You can use max "' . $maxAvailableCredits . '" limit.'
                        ];
                    }

                    $this->di->getObjectManager()->get(Email::class)->sendOrderSyncPauseMail($userId);
                    $this->di->getObjectManager()->get(Transaction::class)->syncDeactivated($userId);
                    $this->di->getObjectManager()->get(PlanEvent::class)->sendServiceExhaustedEvent($serviceData);
                    return [
                        'success' => false,
                        'message' => 'No limit available.',
                        'code' => 'exhaust'
                    ];
                }

                return [
                    'success' => false,
                    'code' => 'service_unavailable',
                    'message' => 'Used limit "' . $usedLimit . '" is not accepted!.'
                ];
            }

            return [
                'success' => false,
                'code' => 'service_unavailable',
                'message' => 'Service data not found.'
            ];
        }

        return [
            'success' => false,
            'code' => 'service_unavailable',
            'message' => 'Please provide service type.'
        ];
    }

    /**
     * to renew plans at month start using cron
     * @return array
     */
    public function renewPlanByCron()
    {
        $logFile = 'plan/renewPlanByCron/' . date('Y-m-d') . '.log';
        try {
            $currentDay = (int)date('d');
            if (!in_array($currentDay, $this->allowedDaysToRenewPlans)) {
                return [
                    'success' => false,
                    'message' => 'We cannot process renew plan today.'
                ];
            }

            $this->commonObj->addLog(' -- Running cron for plan renew ---', $logFile);
            $offset = 0;
            $options = [
                'limit' => self::RENEW_PLANS_BATCH_SIZE,
                'skip' => $offset,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];
            $previousMonthLastDate = date("Y-n-j", strtotime("last day of previous month"));
            $previousMonthLastDate = date('Y-m-d', strtotime($previousMonthLastDate));
            $conditionFilterData = [
                'type' => self::PAYMENT_TYPE_USER_SERVICE,
                'source_marketplace' => self::SOURCE_MARKETPLACE,
                'target_marketplace' => self::TARGET_MARKETPLACE,
                'marketplace' => self::TARGET_MARKETPLACE,
                'expired_at' => ['$lte' => $previousMonthLastDate],
                'service_type' => self::SERVICE_TYPE_ORDER_SYNC,
                'picked' => ['$exists' => false],
                'trial_service' => ['$exists' => false],
                'prepaid.service_credits' => ['$gt' => self::FREE_PLAN_CREDITS],
                'deactivate_on' => ['$exists' => false]
            ];
            // $commonObj = $this->di->getObjectManager()->get(Common::class);
            $userServicesFilter = $conditionFilterData;
            //pick all paid services apart from trial services, free plans and onetime plans
            $userServices = $this->paymentCollection->find($userServicesFilter, $options)->toArray();
            if (empty($userServices)) {
                $userServicesFilter['deactivate_on'] = ['$exists' => true];
                $userServices = $this->paymentCollection->find($userServicesFilter, $options)->toArray();
                if (empty($userServices)) {
                    unset($userServicesFilter['deactivate_on']);
                    $userServicesFilter['prepaid.service_credits'] = ['$lte' => self::FREE_PLAN_CREDITS]; //to fetch all free users
                    $userServices = $this->paymentCollection->find($userServicesFilter, $options)->toArray();
                    $this->commonObj->addLog(' -- Updating user services for free plans ---', $logFile);
                } else {
                    $this->commonObj->addLog(' -- Updating user services for onetime plans ---', $logFile);
                }
            } else {
                $this->commonObj->addLog(' -- Updating user services for paid plans ---', $logFile);
            }
            $this->commonObj->addLog('Total found: ' . count($userServices), $logFile);

            $bkpPaymentCollection = $this->baseMongo->getCollectionForTable('payment_details_uninstalled_users');
            if (count($userServices) >= 1) {
                $settlementClass = $this->di->getObjectManager()->get(Settlement::class);
                foreach ($userServices as $userService) {
                    if (!empty($userService['user_id'])) {
                        $userId = $userService['user_id'];
                        $this->paymentCollection->updateOne(['user_id' => $userId, 'type' => self::PAYMENT_TYPE_USER_SERVICE, 'service_type' => self::SERVICE_TYPE_ORDER_SYNC], ['$set' => ['picked' => true]]);
                        $res = $this->commonObj->setDiForUser($userId);
                        if (!$res['success']) {
                            $this->commonObj->addLog('User not found to set in di: ' . $userId, $logFile);
                            $this->commonObj->addLog('Removing data from payment collection!', $logFile);
                            $allPaymentData = $this->paymentCollection->find([
                                'user_id' => $userId
                            ], self::TYPEMAP_OPTIONS)->toArray();
                            if (!empty($allPaymentData)) {
                                $this->commonObj->addLog('Removing user data and adding in payment_details_uninstalled_users collection', $logFile);
                                $bkpResponse = $bkpPaymentCollection->insertMany($allPaymentData);
                                if ($bkpResponse->getInsertedCount() == count($allPaymentData)) {
                                    $this->commonObj->addLog('Insertion in other collection successfull', $logFile);
                                    $this->commonObj->addLog('Removing user data ', $logFile);
                                    $this->paymentCollection->deleteMany([
                                        'user_id' => $userId
                                    ]);
                                    $this->commonObj->addLog('Disabling dynamo entry for: ' . $userId, $logFile);
                                    $this->updateTargetEntriesInDynamo($userId);
                                    $this->di->getObjectManager()->get(Transaction::class)->syncDeactivated($userId);
                                    $this->di->getObjectManager()->get(Transaction::class)->userRemovedFromPayment($userId);
                                }
                            }
                            continue;
                        }
                    } else {
                        $this->commonObj->addLog('User not found in service: ' . json_encode($userService), $logFile);
                        continue;
                    }

                    $this->commonObj->addLog('Processing for user: ' . $this->di->getUser()->id, $logFile);
                    $this->di->getAppCode()->setAppTag($userService['app_tag'] ?? self::APP_TAG);
                    if (empty($userService['source_id'])) {
                        $userService['source_id'] = $this->commonObj->getShopifyShopId();
                        if (!$userService['source_id']) {
                            $this->commonObj->addLog('Unable to find source_id', $logFile);
                            continue;
                        }
                    }

                    $this->di->getRequester()->setSource(
                        [
                            'source_id' => $userService['source_id'],
                            'source_name' => ''
                        ]
                    );
                    /**
                     * for user_service which are not renewed from more than 3-5 months we need to decide what we can do for them if they have a active recurring
                     */
                    // $settlementAmount = $this->getSettlementAmount($userId);
                    // if ($settlementAmount > 0) {
                    //     $chargeData = [];//since there is no need of this call for now
                    //     // $chargeData = $this->getRemoteChargeData($userId);
                    //     // $this->commonObj->addLog('charge data: ' . json_encode($chargeData), $logFile);
                        // if(!$this->canDeductSettlementAmount($userId, $chargeData, $settlementAmount)) {
                        //     $this->di->getObjectManager()->get(Email::class)->sendOrderSyncPauseMail($userId);
                        //     $this->updateTargetEntriesInDynamo($userId);
                        //     $this->commonObj->addLog('disable_order_sync for users: ' . $userId, $logFile);
                        //     $this->commonObj->addLog('Processing stopped as user has left settlement and settlement cannot be deducted => ' . $settlementAmount, $logFile);
                        //     $this->di->getObjectManager()->get(Transaction::class)->syncDeactivated($userId);
                        //     continue;
                        // }
                    // }

                    // $settlementCheckRes = $settlementClass->checkSettlementAndProcess($userId, $userService);
                    // if (isset($settlementCheckRes['success']) && !$settlementCheckRes['success']) {
                    //     $this->di->getObjectManager()->get(Email::class)->sendOrderSyncPauseMail($userId);
                    //     $this->updateTargetEntriesInDynamo($userId);
                    //     $this->commonObj->addLog('disable_order_sync for users: ' . $userId, $logFile);
                    //     $this->commonObj->addLog('Processing stopped as user has pending settlement: '.json_encode($settlementCheckRes), $logFile);
                    //     $this->di->getObjectManager()->get(Transaction::class)->syncDeactivated($userId);
                    //     continue;
                    // }
                    $settlementInvoice = $this->getPendingSettlementInvoice($userId);
                    if (!empty($settlementInvoice) && !isset($settlementInvoice['is_capped'])) {
                        if ($this->canWaiveOffTheSettlement($settlementInvoice)) {
                            $this->commonObj->addLog('settlement can be waived off for: ' . $userId, $logFile);
                            // $this->waiveOffSettlement($settlementInvoice);
                        }
                        $this->di->getObjectManager()->get(Email::class)->sendOrderSyncPauseMail($userId);
                        $this->updateTargetEntriesInDynamo($userId);
                        $this->commonObj->addLog('disable_order_sync for user: ' . $userId, $logFile);
                        $this->commonObj->addLog('Processing stopped as user has pending settlement: '.json_encode($settlementInvoice['settlement_amount'] ?? 0), $logFile);
                        $this->di->getObjectManager()->get(Transaction::class)->syncDeactivated($userId);
                        continue;
                    }

                    $this->commonObj->addLog('Processing renew Plan!', $logFile);
                    $response = $this->processRenewPlans($userService);
                    if ($response === 'remote_fail') {
                        $this->commonObj->addLog('remote fail: '.json_encode($response), $logFile);
                        // $this->paymentCollection->updateOne(['user_id' => $userId, 'type' => self::PAYMENT_TYPE_USER_SERVICE, 'service_type' => self::SERVICE_TYPE_ORDER_SYNC], ['$unset' => ['picked' => true]]);
                    }
                    if ($response === 'reset') {
                        $this->commonObj->addLog('reset services as they are old and has expiry '.json_encode($userService['expired_at']), $logFile);
                        $this->paymentCollection->updateOne(
                            ['user_id' => $userId, 'type' => self::PAYMENT_TYPE_USER_SERVICE, 'service_type' => self::SERVICE_TYPE_ORDER_SYNC],
                            ['$set' => [
                                'prepaid.available_credits' => $userService['prepaid']['service_credits'],
                                'prepaid.total_used_credits' => 0
                                ]
                            ]
                        );
                    }
                }
                $this->commonObj->addLog(' ------- Process end for cron -------', $logFile);
            } else {
                return [
                    'success' => true,
                    'message' => 'No more user services to update!'
                ];
            }

            return [
                'success' => true,
                'message' => 'Successfully done.'
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage()
            ];
        }
    }

    /**
     * to process plan renew
     * @param array $userService
     */
    public function processRenewPlans($userService = [])
    {
        if (is_array($userService) && count($userService) && isset($userService['user_id'])) {
            $userId = $userService['user_id'];
            $logFile = 'plan/renewPlanByCron/' . date('Y-m-d') . '.log';
            $nextBillingDate = null;
            $this->commonObj->addLog(' --- Renew Plan for user ' . $userId . ' --- ', $logFile);
            $activePlan = $this->getActivePlanForCurrentUser($userId);
            if (!empty($activePlan)) {
                $renewServices = false;
                //trial plan will not get renew
                if ($this->isTrialPlan($activePlan['plan_details'])) {
                    $this->commonObj->addLog('Trial plan cannot be renewed!' . ' --- ', $logFile);
                    return false;
                }

                $shop = $this->commonObj->getShop($this->di->getRequester()->getSourceId(), $userId);
                if (empty($shop)) {
                    $this->commonObj->addLog('Shop not found', $logFile);
                    return false;
                }

                if(!empty($shop['store_closed_at'])) {
                    $this->commonObj->addLog('Store closed', $logFile);
                    return false;
                }

                $currentDate = date('Y-m-d');
                $isPlanOnetime = false;
                //for the case of plan onetime
                if(isset($activePlan['plan_details']['payment_type']) && ($activePlan['plan_details']['payment_type'] == self::BILLING_TYPE_ONETIME)) {
                    $isPlanOnetime = true;
                } elseif (isset($activePlan['plan_details']['custom_plan']) && !isset($activePlan['plan_details']['payment_type']) && $activePlan['plan_details']['custom_plan']) {
                    $isPlanOnetime = true;
                }

                if ($isPlanOnetime) {
                    if (isset($userService['deactivate_on']) && !empty($userService['deactivate_on'])) {
                        $deactivateDate = $userService['deactivate_on'];
                        //if plan need to bedeactivated - we will renew and then deactivate so that services get refreshed for next plan choose
                        if ($deactivateDate <= $currentDate) {
                            $this->commonObj->addLog('Custom plan deactivate => inactivating plan', $logFile);
                            $this->commonObj->addLog('deactivate date => ' . $deactivateDate, $logFile);
                            $this->renewUserServices(
                                $userId,
                                self::SERVICE_TYPE_ORDER_SYNC,
                                $userService,
                                self::TARGET_MARKETPLACE,
                                $activePlan
                            );
                            $this->deactivatePlan(self::BILLING_TYPE_ONETIME, $userId, false);
                            //not need to be added remove the coce from all places
                            if (isset($userService['activate_free_plan']) && $userService['activate_free_plan']) {
                                $this->commonObj->addLog('Need to activate a free plan after deactivation!', $logFile);
                                $freePlanActivateRes = $this->forcefullyFreePlanPurchase(['user_id' => $userId]);
                                $this->commonObj->addLog('freePlanActivateRes: '.json_encode($freePlanActivateRes), $logFile);
                            }
                            return true;
                        } else {
                            $renewServices = true;
                        }
                    } else {
                        //in case deactivation date is not found we need to make a log of such cases so that we can track then on
                        $this->commonObj->addLog('Plan is onetime but detactivate date is not set!' . ' --- ', $logFile);
                        $this->commonObj->addLog('Renewing custom plan onetime', $logFile);
                        $renewServices = true;
                    }
                    
                } else {
                    $checkRecurringActive = true;
                    if($this->isFreePlan($activePlan['plan_details']) && empty($activePlan['plan_details']['capped_price'])) {
                        $checkRecurringActive = false;
                        $renewServices = true;
                    }
                    if ($checkRecurringActive) {
                        $this->commonObj->addLog('Paid plan => get payment data from shopify', $logFile);
                        $remoteData = $this->getRemoteChargeData($userId);
                        $this->commonObj->addLog('remoteData received : ' . json_encode($remoteData), $logFile);
    
                        if (!empty($remoteData) && isset($remoteData['id'])) {
                            if (isset($remoteData['status']) && ($remoteData['status'] == ShopifyPayment::STATUS_ACTIVE)) {
                                $userService['subscription_billing_date'] = $remoteData['billing_on'] ?? $remoteData['current_period_end'] ?? null;
                                $renewServices = true;
                            } elseif (isset($remoteData['status']) && ($remoteData['status'] == ShopifyPayment::STATUS_CANCELLED)) {
                                $this->commonObj->addLog('Paid plan => inactivating plan', $logFile);
                                $this->deactivatePlan('paid', $userId, $remoteData['id']);
                                return true;
                            } else {
                                $this->commonObj->addLog('Status found = '.json_encode($remoteData['status'] ?? ''), $logFile);
                                return true;
                            }
                        } else {
                            $exceptionalDate = date('Y-m-d', strtotime('-2months'));
                            if (isset($userService['expired_at']) && ($exceptionalDate >= $userService['expired_at'])) {
                                $this->commonObj->addLog('critical concern as remote fail cause no plan renew and the service is not renewed from '.$userService['expired_at'], $logFile);
                            }
                            //to handle throttle
                            return 'remote_fail';
                        }
                    }
                }
                

                if ($renewServices) {
                    $this->renewUserServices(
                        $userId,
                        self::SERVICE_TYPE_ORDER_SYNC,
                        $userService,
                        self::TARGET_MARKETPLACE,
                        $activePlan
                    );
                    $this->commonObj->addLog('Plan => renew services and updating entry in dynamo', $logFile);
                    $syncRes = $this->reUpdateEntry($userId, $shop);
                    $this->commonObj->addLog('reUpdateEntry response: ' . json_encode($syncRes), $logFile);
                    return true;
                }
                return false;
            } else {
                $exceptionalDate = date('Y-m-d', strtotime('-6months'));
                if (isset($userService['expired_at']) && ($exceptionalDate >= $userService['expired_at']) && ($userService['prepaid']['total_used_credits'] > 0)) {
                    return 'reset';
                }
            }

            $this->commonObj->addLog('No active plan found!', $logFile);
            return false;
        }
    }

    /**
     * to deactivate any plan
     */
    public function deactivatePlan($paymentType = 'free', $userId = false, $chargeId = false, $current = false, $uninstalled = false): void
    {
        $logFile = 'plan/' . date('Y-m-d') . '/deactivate.log';
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $this->commonObj->addLog('Deactivate plan data received: PaymentType' . $paymentType . ' UserId: ' . $userId . ' chargeId=> ' . $chargeId, $logFile);
        $canDeactivate = false;
        $paymentDetail = $this->getPaymentInfoForCurrentUser($userId);
        if ($paymentType == 'free' || $paymentType == 'trial' || $paymentType == self::BILLING_TYPE_ONETIME) {
            $canDeactivate = true;
        } else {
            if ($paymentDetail && isset($paymentDetail['marketplace_data']['id'])) {
                $this->commonObj->addLog('received chargeId: ' . $chargeId, $logFile);
                $this->commonObj->addLog('current payment chargeId: ' . $paymentDetail['marketplace_data']['id'], $logFile);
                if ($chargeId && ($chargeId == $paymentDetail['marketplace_data']['id'])) {
                    $this->commonObj->addLog('Both ids are same', $logFile);
                    $chargeId = $paymentDetail['marketplace_data']['id'];
                    $shop = $this->commonObj->getShop($this->commonObj->getShopifyShopId(), $userId);
                    if (!empty($shop) && isset($shop['remote_shop_id'])) {
                        $data['shop_id'] = $shop['remote_shop_id'];
                        $data['id'] = $chargeId;
                        $this->commonObj->addLog('Data for remote call: ' . json_encode($data, true), $logFile);
                        $response = $this->di->getObjectManager()->get(ShopifyPayment::class)->getPaymentData($data, self::BILLING_TYPE_RECURRING);
                        $this->commonObj->addLog('Response received: ' . json_encode($response, true), $logFile);
                        if (isset($response['success'], $response['data']) && $response['success']) {
                            $this->commonObj->addLog('STATUS: ' . $response['data']['status'], $logFile);
                            if (isset($response['data']['status']) && ($response['data']['status'] == 'cancelled' || $response['data']['status'] == 'canceled')) {
                                $userServices = $this->getCurrentUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
                                if(!empty($userServices) && !isset($userServices['deactivate_on'])) {
                                    $canDeactivate = true;
                                } else {
                                    $this->commonObj->addLog('user service has a deactivate date found so cannot cancel based on charged id ', $logFile);
                                }
                            }
                        } elseif(empty($response)) {
                            $this->commonObj->addLog('No response received from remote server: ' . __FILE__.'/line '.__LINE__, 'plan/remote_errors.log');
                            $this->commonObj->addLog('Data sent to remote:' . json_encode($data), 'plan/remote_errors.log');
                            $this->commonObj->addLog('Deactivate plan data received: PaymentType' . $paymentType . ' UserId: ' . $userId . ' chargeId=> ' . $chargeId.' current=> '.$current.' uninstalled=>'.$uninstalled, 'plan/remote_errors.log');
                        }
                    } else {
                        $this->commonObj->addLog('Shop not found', $logFile);
                    }
                } elseif($uninstalled) {
                    $shop = $this->commonObj->getShop($this->commonObj->getShopifyShopId(), $userId);
                    if (!empty($shop) && isset($shop['remote_shop_id'])) {
                        $chargeId = $paymentDetail['marketplace_data']['id'] ?? "";
                        $data['shop_id'] = $shop['remote_shop_id'];
                        $data['id'] = $chargeId;
                        $response = $this->di->getObjectManager()->get(ShopifyPayment::class)->getPaymentData($data, self::BILLING_TYPE_RECURRING);
                        $this->commonObj->addLog('response: '.json_encode($response), $logFile);
                        if (isset($response['success']) && !$response['success'] && isset($response['msg']['errors']) && ($response['msg']['errors'] == "[API] Invalid API key or access token (unrecognized login or wrong password)")) {
                            $canDeactivate = true;
                        } elseif (empty($response)) {
                            $this->commonObj->addLog('No response received from remote server: ' . __FILE__.'/line '.__LINE__, 'plan/remote_errors.log');
                            $this->commonObj->addLog('Data sent to remote:' . json_encode($data), 'plan/remote_errors.log');
                            $this->commonObj->addLog('Deactivate plan data received: PaymentType' . $paymentType . ' UserId: ' . $userId . ' chargeId=> ' . $chargeId.' current=> '.$current.' uninstalled=>'.$uninstalled, 'plan/remote_errors.log');
                        }
                    }
                } elseif($current) {
                    if($this->cancelRecurryingForCurrentUser($userId)) {
                        $canDeactivate = true;
                        $chargeId = $paymentDetail['marketplace_data']['id'] ?? "";
                    }
                }
            }
        }

        $this->commonObj->addLog('canDeactivate: ' . $canDeactivate, $logFile);
        if ($canDeactivate && isset($paymentDetail['quote_id'])) {
            $quoteData = $this->getQuoteByQuoteId($paymentDetail['quote_id']);
            if (!empty($quoteData) && isset($quoteData['plan_details']['custom_price'])) {
                $statusUpdateFilterData = [
                    'user_id' => $userId,
                    'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
                    'status' => self::USER_PLAN_STATUS_ACTIVE,
                    'source_marketplace' => self::SOURCE_MARKETPLACE,
                    'target_marketplace' => self::TARGET_MARKETPLACE,
                    'marketplace' => self::TARGET_MARKETPLACE,
                    'plan_details.custom_price' => ['$exists' => true],
                    'plan_details.custom_price' => $quoteData['plan_details']['custom_price']
                ];
            } elseif(empty($quoteData)) {
                $statusUpdateFilterData = [
                    'user_id' => $userId,
                    'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
                    'status' => self::USER_PLAN_STATUS_ACTIVE,
                    'source_marketplace' => self::SOURCE_MARKETPLACE,
                    'target_marketplace' => self::TARGET_MARKETPLACE,
                    'marketplace' => self::TARGET_MARKETPLACE
                ];
            }

            $activePlanDetails = $this->paymentCollection->findOneAndUpdate(
                $statusUpdateFilterData,
                ['$set' => [
                    'status' => self::USER_PLAN_STATUS_INACTIVE,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'inactivated_on' => date('Y-m-d H:i:s')
                ]],
                [
                    "returnOriginal" => true,
                    "typeMap" => ['root' => 'array', 'document' => 'array']
                ]
            );
            if(empty($activePlanDetails)) {
                $activePlanDetails = $this->paymentCollection->findOneAndUpdate(
                    [
                        'user_id' => $userId,
                        'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
                        'status' => self::USER_PLAN_STATUS_ACTIVE,
                        'source_marketplace' => self::SOURCE_MARKETPLACE,
                        'target_marketplace' => self::TARGET_MARKETPLACE,
                        'marketplace' => self::TARGET_MARKETPLACE
                    ],
                    ['$set' => [
                        'status' => self::USER_PLAN_STATUS_INACTIVE,
                        'updated_at' => date('Y-m-d H:i:s'),
                        'inactivated_on' => date('Y-m-d H:i:s')
                    ]],
                    [
                        "returnOriginal" => true,
                        "typeMap" => ['root' => 'array', 'document' => 'array']
                    ]
                );
            }

            $this->commonObj->addLog('inactivating active plans', $logFile);
            $paymentFilter = [
                '_id' => $paymentDetail['_id']
            ];
            if ($paymentType == 'paid' && !empty($chargeId)) {
                $paymentFilter['marketplace_data.id'] = $chargeId;
            }

            $setData = [
                '$set' => [
                    'plan_status' => self::PAYMENT_STATUS_DEACTIVATED,
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            ];
            $this->commonObj->addLog('deactivating payment', $logFile);
            $this->paymentCollection->updateOne($paymentFilter, $setData);
            $currentActivePlan = $this->getActivePlanForCurrentUser($userId);
            if (empty($currentActivePlan)) {
                $this->commonObj->addLog('updateTargetEntriesInDynamo for user: ' . $userId, $logFile);
                $this->updateTargetEntriesInDynamo($userId);
            }

            $this->di->getObjectManager()->get(PlanEvent::class)->sendPlanDeactivateEvent($activePlanDetails ?? [], $chargeId);
            $transactionData['user_id'] = $userId;
            $transactionData['quote_id'] = $quoteData['_id'];
            $transactionData['deactivated_plan_details'] = $activePlanDetails;
            $transactionData['payment_details'] = $paymentDetail;
            $this->di->getObjectManager()->get(Transaction::class)->planDeactivated($transactionData);
        }
    }

    /** to renew plan if any at the time of login
     *
     * @param bool $userId
     */
    private function checkAndRenewPlanForCurrentUser($userService = [], $userId = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        if (isset($userService['expired_at']) || isset($userService['deactivate_on'])) {
            $currentDate = date('Y-m-d');
            $needToRenew = false;
            if(isset($userService['deactivate_on']) && ($userService['deactivate_on'] <= $currentDate)) {
                $needToRenew = true;
            } elseif(isset($userService['expired_at']) && ($userService['expired_at'] < $currentDate) && (!isset($userService['trial_service']))) {
                $needToRenew = true;
            }

            if ($needToRenew) {
                $settlementCheckRes = $this->di->getObjectManager()->get(Settlement::class)->checkSettlementAndProcess($userId, $userService);
                if (isset($settlementCheckRes['success']) && !$settlementCheckRes['success']) {
                    return $userService;
                }
                $this->processRenewPlans($userService);
            }
        }
    }

    /**
     * to get remote data for billing
     */
    public function getRemoteChargeData($userId,  $paymentDetail = [])
    {
        if(empty($paymentDetail)) {
            $paymentDetail = $this->getPaymentInfoForCurrentUser($userId);
        }

        $sourceShopId = $this->di->getRequester()->getSourceId();
        if(empty($sourceShopId)) {
            $sourceShopId = $this->commonObj->getShopifyShopId($userId);
        }

        $shop = $this->commonObj->getShop($sourceShopId, $userId);
        if (!empty($paymentDetail) && isset($paymentDetail['marketplace_data']['id'])) {
            $chargeId = $paymentDetail['marketplace_data']['id'];
            if (!empty($shop) && isset($shop['remote_shop_id'])) {
                $data['shop_id'] = $shop['remote_shop_id'];
                $data['id'] = $chargeId;
                $response = $this->di->getObjectManager()->get(ShopifyPayment::class)->getPaymentData($data, self::BILLING_TYPE_RECURRING);
                if (isset($response['data'])) {
                    return $response['data'] ?? [];
                }
                return $response;
            }
        }

        return [];
    }

    /**
     * @param array $userIds
     * @param array $serviceTypes
     */
    private function renewUserServices(
        $userId,
        $serviceType,
        $serviceData = [],
        $marketplace = self::TARGET_MARKETPLACE,
        $activePlan = []
    ) {
        if ($userId && $serviceType && $marketplace && is_array($serviceData) && count($serviceData)) {
            $latestVersion = false;
            if (empty($activePlan)) {
                $activePlan = $this->getActivePlanForCurrentUser($userId);
            }
            if (!empty($activePlan) && isset($activePlan['plan_details']['version'])) {
                $latestVersion = true;
            }

            $this->generateMonthlyUsageSummery($userId, $marketplace, $activePlan);
            $currentDate = date('Y-m-d');
            $currentMonthLastDate = date('Y-m-t');
            $previousMonthLastDate = date("Y-n-j", strtotime("last day of previous month"));
            $previousMonthLastDate = date('Y-m-d', strtotime($previousMonthLastDate));
            $needToUnsetActivityFields = false;
            if ($serviceType == self::SERVICE_TYPE_ORDER_SYNC) {
                $conditionFilterData = [
                    'user_id' => (string)$userId,
                    'type' => self::PAYMENT_TYPE_USER_SERVICE,
                    'source_marketplace' => self::SOURCE_MARKETPLACE,
                    'target_marketplace' => self::TARGET_MARKETPLACE,
                    'marketplace' => $marketplace,
                    'expired_at' => ['$lte' => $previousMonthLastDate],
                    'service_type' => $serviceType,
                ];
                if(isset($serviceData['credit_limit']) && $serviceData['credit_limit'] == self::CREDIT_LIMIT_UNLIMITED) {
                    $updatedServiceData = [
                        'prepaid.total_used_credits' => 0,
                        'activated_on' => $currentDate,
                        'expired_at' => $currentMonthLastDate,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    // $serviceData['prepaid']['all_time_credits_used'] = ($serviceData['prepaid']['all_time_credits_used'] ?? 0) + ($serviceData['prepaid']['total_used_credits'] ?? 0);
                } elseif (isset($serviceData['prepaid']['service_credits']) && is_numeric($serviceData['prepaid']['service_credits'])) {
                    $totalServiceCredits = (int)$this->di->getObjectManager()->create(Services::class)->getServiceCredits($activePlan['plan_details']) ?? (int)$serviceData['prepaid']['service_credits'];
                    // $totalServiceCredits = (int)$activePlan['plan_details']['services_groups'][0]['services'][0]['prepaid']['service_credits'] ?? (int)$serviceData['prepaid']['service_credits'];

                    // Check for activity_type increase_order_limit_date_range case
                    if (isset($serviceData['activity_type']) &&
                        $serviceData['activity_type'] === 'increase_order_limit_date_range' &&
                        isset($serviceData['old_service_credits']) &&
                        isset($serviceData['reset_date']) &&
                        $currentDate >= $serviceData['reset_date']) {

                        // Prepare specific updatedServiceData for activity reset case
                        $updatedServiceData = [
                            'prepaid.service_credits' => $serviceData['old_service_credits'],
                            'prepaid.available_credits' => $serviceData['old_service_credits'],
                            'prepaid.total_used_credits' => 0,
                            'activated_on' => $currentDate,
                            'expired_at' => $currentMonthLastDate,
                            'updated_at' => date('Y-m-d H:i:s')
                        ];

                        $conditionFilterData=[
                            'user_id' => (string)$userId,
                            'type' => self::PAYMENT_TYPE_USER_SERVICE,
                            'source_marketplace' => self::SOURCE_MARKETPLACE,
                            'target_marketplace' => self::TARGET_MARKETPLACE,
                            'marketplace' => $marketplace,
                            'service_type' => $serviceType,
                            'reset_date' => ['$lte' => $currentDate],
                        ];

                        $this->resetOrderLimitActivity($userId, $serviceData, $updatedServiceData);
                        // Mark that we need to unset activity fields from database
                        $needToUnsetActivityFields = true;

                    } elseif (isset($serviceData['activity_type']) &&
                              $serviceData['activity_type'] === 'freeze_recurring_order_reset' &&
                              isset($serviceData['freeze_reset']) &&
                              isset($serviceData['expired_at']) &&
                              $currentDate >= $serviceData['expired_at']) {

                        // Calculate new expired_at date (current date + freeze_reset months)
                        $freezeResetMonths = (int)$serviceData['freeze_reset'];
                        $newExpiredAt = date('Y-m-d', strtotime("+{$freezeResetMonths} months", strtotime($currentDate)));

                        // Prepare specific updatedServiceData for freeze reset case
                        $updatedServiceData = [
                            'prepaid.service_credits' => $totalServiceCredits,
                            'prepaid.available_credits' => $totalServiceCredits,
                            'prepaid.total_used_credits' => 0,
                            'activated_on' => $currentDate,
                            'expired_at' => $newExpiredAt,
                            'updated_at' => date('Y-m-d H:i:s')
                        ];

                        $conditionFilterData=[
                            'user_id' => (string)$userId,
                            'type' => self::PAYMENT_TYPE_USER_SERVICE,
                            'source_marketplace' => self::SOURCE_MARKETPLACE,
                            'target_marketplace' => self::TARGET_MARKETPLACE,
                            'marketplace' => $marketplace,
                            'service_type' => $serviceType,
                            'expired_at' => ['$lte' => $currentDate],
                        ];

                        $this->processFreezeRecurringOrderReset($userId, $serviceData, $newExpiredAt, $updatedServiceData);
                    } else {
                        // Normal case - existing logic
                    $updatedServiceData = [
                        'prepaid.service_credits' => $totalServiceCredits,
                        'prepaid.available_credits' => $totalServiceCredits,
                        'prepaid.total_used_credits' => 0,
                        'activated_on' => $currentDate,
                        'expired_at' => $currentMonthLastDate,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                     /**
                     * this code is added to add the postpaid credits only one time - settlement process 1 update
                     */
                    $postpaidEnabled = isset($serviceData['postpaid']['active']) ? $serviceData['postpaid']['active'] : true;
                    $isCapped = isset($serviceData['postpaid']['is_capped']) ? $serviceData['postpaid']['is_capped'] : false;
                    if ($isCapped || $latestVersion) {
                        $postpaidEnabled = false;
                    }
                    if ($postpaidEnabled) {
                        $postpaidCredits = $this->getPostpaidCreditsAsPerServiceCredits($totalServiceCredits);
                        $updatedServiceData['postpaid.capped_credit'] = $postpaidCredits;
                        $updatedServiceData['postpaid.available_credits'] = $postpaidCredits;
                        }
                    }
                }
                isset($serviceData['subscription_billing_date']) && $updatedServiceData['subscription_billing_date'] = $serviceData['subscription_billing_date'];

                if (!empty($updatedServiceData)) {
                     /**
                     * settlement process 1 - end
                     */
                    $updateData = [
                        '$set' => $updatedServiceData
                    ];

                    // Add unset operations if we need to remove activity fields
                    if ($needToUnsetActivityFields) {
                        $updateData['$unset'] = [
                            'activity_type' => '',
                            'reset_date' => '',
                            'old_service_credits' => ''
                        ];
                    }

                    $this->paymentCollection->updateOne(
                        $conditionFilterData,
                        $updateData
                    );
                }
                $nextBillingDate = $updatedServiceData['subscription_billing_date'] ?? $updatedServiceData['expired_at'] ?? null;
                $this->di->getObjectManager()->get(Email::class)->resetEmailConfig($userId);
                $this->di->getObjectManager()->get(PlanEvent::class)->sendPlanRenewEvent($activePlan, $nextBillingDate);
                return true;
            }
        }

        return false;
    }

    /**
     * Reset order limit activity and update service data
     * @param int $userId
     * @param array $serviceData
     * @param array $updatedServiceData
     */
    public function resetOrderLimitActivity($userId, $serviceData, $updatedServiceData)
    {
        try {
            $logFile = 'plan/resetOrderLimitActivity/' . date('Y-m-d') . '.log';
            $this->commonObj->addLog('Resetting order limit activity for user: ' . $userId, $logFile);

            // Get pricing_plan_activity collection
            $pricingActivityCollection = $this->baseMongo->getCollectionForTable('pricing_plan_activity');

            // Update pricing_plan_activity to set is_active = false
            $activityFilter = [
                'user_id' => (string)$userId,
                'activity_type' => 'increase_order_limit_date_range',
                'is_active' => true
            ];

            $activityUpdate = [
                '$set' => [
                    'is_active' => false,
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            ];

            $updateResult = $pricingActivityCollection->updateOne($activityFilter, $activityUpdate);

            if ($updateResult->isAcknowledged()) {
                $this->commonObj->addLog('Successfully deactivated ' . $updateResult->getModifiedCount() . ' activities for user: ' . $userId, $logFile);
            } else {
                $this->commonObj->addLog('Failed to update pricing_plan_activity for user: ' . $userId, $logFile);
            }

            // Update service_groups in active plan with old_service_credits
            if (isset($serviceData['old_service_credits'])) {
                $activePlan = $this->getActivePlanForCurrentUser($userId);

                if (!empty($activePlan)) {
                    $currentDate = date('Y-m-d H:i:s');
                    $statusUpdateFilterData = [
                        'user_id' => (string)$userId,
                        'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
                        'status' => self::USER_PLAN_STATUS_ACTIVE,
                        'source_marketplace' => self::SOURCE_MARKETPLACE,
                        'target_marketplace' => self::TARGET_MARKETPLACE,
                        'marketplace' => self::TARGET_MARKETPLACE,
                    ];

                    if (isset($activePlan['plan_details']['services_groups'])) {
                        $servicesGroups = $activePlan['plan_details']['services_groups'];

                        foreach ($servicesGroups as $key => $group) {
                            if (isset($group['title']) && $group['title'] === 'Order Management') {
                                if (isset($group['services'])) {
                                    foreach ($group['services'] as $serviceKey => $service) {
                                        if (isset($service['type']) && $service['type'] === 'order_sync') {
                                            $servicesGroups[$key]['services'][$serviceKey]['prepaid']['service_credits'] = $serviceData['old_service_credits'];
                                            $this->commonObj->addLog('Updated plan service_credits to old_service_credits: ' . $serviceData['old_service_credits'] . ' for user: ' . $userId, $logFile);
                                        }
                                    }
                                }
                                break;
                            }
                        }

                        $planUpdateData = [
                            'plan_details.services_groups' => $servicesGroups,
                            'updated_at' => $currentDate
                        ];

                        $planUpdateResult = $this->paymentCollection->updateOne(
                            $statusUpdateFilterData,
                            ['$set' => $planUpdateData]
                        );

                        if ($planUpdateResult->isAcknowledged()) {
                            $this->commonObj->addLog('Successfully updated plan service_groups for user: ' . $userId, $logFile);
                        } else {
                            $this->commonObj->addLog('Failed to update plan service_groups for user: ' . $userId, $logFile);
                        }
                    }
                }
            }

            $this->commonObj->addLog('Activity fields (activity_type, reset_date, old_service_credits) will be removed from database for user: ' . $userId, $logFile);

        } catch (Exception $e) {
            $this->commonObj->addLog('Error in resetOrderLimitActivity for user ' . $userId . ': ' . $e->getMessage(), $logFile);
            throw $e;
            return false;

        }
    }

    /**
     * Deactivate existing order limit activities for user during plan upgrade/downgrade
     * @param string $userId
     * @return void
     */
    private function deactivateUserOrderLimitActivities($userId)
    {
        try {
            // Get pricing_plan_activity collection
            $pricingActivityCollection = $this->baseMongo->getCollectionForTable('pricing_plan_activity');

            // Find active order limit activities for this user
            $filter = [
                'user_id' => (string)$userId,
                'is_active' => true,
                'activity_type' => [
                    '$in' => [
                        'freeze_recurring_order_reset',
                        'increase_order_limit_date_range'
                    ]
                ]
            ];

            // Update to set is_active = false
            $updateData = [
                '$set' => [
                    'is_active' => false,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            ];

            $pricingActivityCollection->updateOne($filter, $updateData);

        } catch (Exception $e) {
            $this->di->getLog()->logContent('deactivateUserOrderLimitActivities: ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * Process freeze recurring order reset activity
     *
     * @param int $userId
     * @param array $serviceData
     * @param string $newExpiredAt
     * @param array $updatedServiceData
     */
    public function processFreezeRecurringOrderReset($userId, $serviceData, $newExpiredAt, $updatedServiceData)
    {
        try {
            $logFile = 'plan/processFreezeRecurringOrderReset/' . date('Y-m-d') . '.log';
            $this->commonObj->addLog('Processing freeze recurring order reset for user: ' . $userId, $logFile);

            // Get pricing_plan_activity collection
            $pricingActivityCollection = $this->baseMongo->getCollectionForTable('pricing_plan_activity');

            // Update pricing_plan_activity to set is_active = false and update expired_at
            $activityFilter = [
                'user_id' => (string)$userId,
                'activity_type' => 'freeze_recurring_order_reset',
                'is_active' => true
            ];

            $activityUpdate = [
                '$set' => [
                    'expired_at' => $newExpiredAt,
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            ];

            $updateResult = $pricingActivityCollection->updateOne($activityFilter, $activityUpdate);

            if ($updateResult->isAcknowledged()) {
                $this->commonObj->addLog('Successfully updated freeze activity for user: ' . $userId . ' with new expired_at: ' . $newExpiredAt, $logFile);
            } else {
                $this->commonObj->addLog('Failed to update pricing_plan_activity for freeze reset for user: ' . $userId, $logFile);
            }

            // Update service data using orderSyncFilter
            $orderSyncFilter = [
                'user_id' => (string)$userId,
                'type' => self::PAYMENT_TYPE_USER_SERVICE,
                'source_marketplace' => self::SOURCE_MARKETPLACE,
                'target_marketplace' => self::TARGET_MARKETPLACE,
                'marketplace' => self::TARGET_MARKETPLACE,
                'service_type' => self::SERVICE_TYPE_ORDER_SYNC,
            ];

            $serviceUpdateData = [
                '$set' => $updatedServiceData
            ];

            $serviceUpdateResult = $this->paymentCollection->updateOne(
                $orderSyncFilter,
                $serviceUpdateData
            );
            
        } catch (Exception $e) {
            $this->di->getLog()->logContent('processFreezeRecurringOrderReset: ' . json_encode($e->getMessage()), 'info',  'exception.log');
            throw $e;
        }
    }

    /** to generate monthly usage
     *
     * @param bool $userId
     * @param string $marketplace
     * @param array $activePlan
     * @return bool
     */
    public function generateMonthlyUsageSummery(
        $userId = false,
        $marketplace = self::TARGET_MARKETPLACE,
        $activePlan = []
    ) {
        if ($userId && !empty($activePlan)) {
            $month = (int)date('m');
            $year = (int)date('Y');
            if (($month - 1) == 0) {
                $month = 12;
                $year -= 1;
            } else {
                $month -= 1;
            }

            $conditionFilterData = [
                'user_id' => (string)$userId,
                'type' => self::PAYMENT_TYPE_MONTHLY_USAGE,
                'source_marketplace' => self::SOURCE_MARKETPLACE,
                'target_marketplace' => self::TARGET_MARKETPLACE,
                'marketplace' => $marketplace,
                'month' => $month,
                'year' => $year,
            ];
            $services = $this->getCurrentUserServices($userId);
            $userServices = [];
            foreach ($services as $service) {
                $serviceUsage = [];
                $serviceUsage = [
                    'service_type' => $service['service_type'],
                    'prepaid' => $service['prepaid'] ?? [],
                    'postpaid' => $service['postpaid'] ?? [],
                ];
                isset($service['deactivate_on']) && $serviceUsage['deactivate_on'] = $service['deactivate_on'];
                isset($service['subscription_billing_date']) && $serviceUsage['subscription_billing_date'] = $service['subscription_billing_date'];

                isset($service['supported_features']) && $serviceUsage['supported_features'] = $service['supported_features'];
                $userServices[] = $serviceUsage;
            }

            $settlementInvoiceData = $this->settlementInfoFromPreviousMonth();
            $previousMonthLastDate = date("Y-n-j", strtotime("last day of previous month"));
            $previousMonthLastDate = date('Y-m-d', strtotime($previousMonthLastDate));
            $settlementInvoices = [];
            if (!empty($settlementInvoiceData)) {
                foreach ($settlementInvoiceData as $settlementInfo) {
                    if ($settlementInfo['generated_at'] < $previousMonthLastDate) {
                        $settlementInvoices[] = $settlementInfo['postpaid'];
                    }
                }
            }

            $dataToAdd = [
                'user_id' => (string)$userId,
                'type' => self::PAYMENT_TYPE_MONTHLY_USAGE,
                'source_marketplace' => self::SOURCE_MARKETPLACE,
                'target_marketplace' => self::TARGET_MARKETPLACE,
                'marketplace' => $marketplace,
                'month' => $month,
                'year' => $year,
                'created_at' => date('Y-m-d H:i:s'),
                'used_services' => $userServices,
                'plan' => [
                    'capped_amount' => $activePlan['capped_amount'] ?? self::DEFAULT_CAPPED_AMOUNT,
                    'details' => $activePlan['plan_details']
                ]
            ];
            if (!empty($settlementInvoices)) {
                $dataToAdd['settlement_credits_used'] = $settlementInvoices;
            }

            $updateData = [
                '$set' => $dataToAdd
            ];
            $this->paymentCollection->updateOne(
                $conditionFilterData,
                $updateData,
                ['upsert' => true]
            );
            return true;
        }

        return false;
    }

    /**
     * @param string $userId
     * @return array
     */
    public function validateRequiredParams($userId = '')
    {
        $validateResponse = [
            'success' => false,
            'message' => ''
        ];
        $appTag = $this->di->getAppCode()->getAppTag();
        if (!$appTag) {
            $validateResponse['message'] = 'App tag not available.';
            return $validateResponse;
        }

        if ($appTag != $this->commonObj->getApiConnectorConfigByKey('app_tag')) {
            $validateResponse['message'] = 'Invalid app tag.';
            return $validateResponse;
        }

        $sourceId = $this->di->getRequester()->getSourceId();
        if (!$sourceId) {
            $validateResponse['message'] = 'Source Id not available.';
            return $validateResponse;
        }

        $userId = !$userId ? $this->di->getUser()->id : $userId;
        // $userData = $this->commonObj->getUserDetail($userId);
        // $hasValidSource = false;
        // foreach ($userData['shops'] as $shops) {
        //     if ($shops['_id'] == $sourceId) {
        //         $hasValidSource = true;
        //         break;
        //     }
        // }
        $shopifySourceId = $this->commonObj->getShopifyShopId($userId);
        if ($shopifySourceId != $sourceId) {
            $validateResponse['message'] = 'Invalid source id.';
            return $validateResponse;
        }

        // if (!$hasValidSource) {
        //     $validateResponse['message'] = 'Invalid source id.';
        //     return $validateResponse;
        // }
        $validateResponse['success'] = true;
        return $validateResponse;
    }

    /**
     * to update entry in dynamo
     */
    public function reUpdateEntry($userId, $shop)
    {
        $response = [];
        if (isset($shop['targets']) && !empty($shop['targets'])) {
            foreach ($shop['targets'] as $target) {
                if (isset($target['disconnected']) &&  $target['disconnected']) {
                    $response = ['success' => false, 'message' => 'Target disconnected'];
                    //need to update that if shop disconnected then disable the syncing
                } else {
                    $targetShop = $this->commonObj->getShop($target['shop_id'], $userId);
                    if (!empty($targetShop) && isset($targetShop['remote_shop_id'])) {
                        $response = $this->updateEntryInDynamo($targetShop['remote_shop_id'], 0);
                    } else {
                        $response = ['success' => false, 'message' => 'target shop not found!'];
                    }
                }
            }
        } else {
            $response = ['success' => false, 'message' => 'Targets not found in shop data'];
        }

        if(isset($response['success']) && $response['success']) {
            $this->di->getObjectManager()->get(Transaction::class)->syncActivated($userId);
        }

        return $response;
    }

    /**
     * to update entry at dynamo
     */
    public function updateEntryInDynamo($remoteShopId, $value)
    {
        $tableName = 'user_details';
        if (in_array($remoteShopId, $this->di->getConfig()->get('amazon_priority_user_shop_ids')->toArray())) {
            $tableName = 'amazon_order_sync_priority_users';
        }
        try {
            $marshaler = new Marshaler();
            $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
            $dynamoClientObj = $dynamoObj->getDetails();
            $tableName = 'user_details';
            $dynamoData = $dynamoClientObj->getItem([
                'TableName' => $tableName,
                'Key' => $marshaler->marshalItem([
                    'id' => (string)$remoteShopId,
                ]),
                'ConsistentRead' => true,
            ]);
            if (!empty($dynamoData['Item'])) {
                $userData = $dynamoData['Item'];
                $updateExpression = [];
                $expressionAttributeValues = [];
                if (!$value) {
                    if (isset($userData['order_fetch'])) {
                        $updateExpression[] = 'order_fetch = :ofetch';
                        $expressionAttributeValues[':ofetch'] = 1;
                        // Check the min_created_at value if older than past 2 days then resetting it
                        if ($this->resetMinCreatedAtIfStale($userData['order_filter'], '2')) {
                            $updateExpression[] = 'order_filter = :ofil';
                            $expressionAttributeValues[':ofil'] = '{}';
                        }
                    }

                    if (isset($userData['fba_fetch'])) {
                        $updateExpression[] = 'fba_fetch = :ffetch';
                        $expressionAttributeValues[':ffetch'] = '1';
                        // Check the min_created_at value if older than past 1 day then resetting it
                        if ($this->resetMinCreatedAtIfStale($userData['fba_filter'], '1')) {
                            $updateExpression[] = 'fba_filter = :ffil';
                            $expressionAttributeValues[':ffil'] = '{}';
                        }
                    }
                    $this->di->getObjectManager()->get('App\Amazon\Components\ShopEvent')
                    ->updateOrderFetchKey($this->di->getUser()->id, true);
                } else {
                    if (isset($userData['order_fetch'])) {
                        $updateExpression[] = 'order_fetch = :ofetch';
                        $expressionAttributeValues[':ofetch'] = 0;
                    }

                    if (isset($userData['fba_fetch'])) {
                        $updateExpression[] = 'fba_fetch = :ffetch';
                        $expressionAttributeValues[':ffetch'] = '0';
                    }

                    $this->di->getObjectManager()->get('App\Amazon\Components\ShopEvent')
                    ->updateOrderFetchKey($this->di->getUser()->id, false);
                }

                $updateExpression[] = 'disable_order_sync = :dorder';
                $expressionAttributeValues[':dorder'] = $value;
                $expressionAttributeValues = $marshaler->marshalItem($expressionAttributeValues);
                $updateExpressionString = 'SET ' . implode(', ', $updateExpression);
                $updateParams = [
                    'TableName' => $tableName,
                    'Key' => $marshaler->marshalItem([
                        'id' => (string)$remoteShopId,
                    ]),
                    'UpdateExpression' => $updateExpressionString,
                    'ExpressionAttributeValues' => $expressionAttributeValues
                ];
                $dynamoClientObj->updateItem($updateParams);

                return ['success' => true, 'message' => 'Values updated'];
            } else {
                $message = 'Unable to find entry on dynamo';
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('App\Plan\Models\Plan:updateEntryInDynamo(), Error: ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * for managing credits in add ons
     */
    public function updateMaxAvailableCreditsWithAddOnsIfNeeded(&$maxAvailableCredits, &$usedLimit, &$serviceData): void
    {
        if ($maxAvailableCredits >= $usedLimit) {
            return;
        }

        $previousMaxAvailableCredits = $maxAvailableCredits;
        $updatedMaxAvailableCredits = $maxAvailableCredits;

        $updatedMaxAvailableCredits += (int)($serviceData['add_on']['available_credits'] ?? 0);

        $updatedMaxAvailableCredits = $this->updateAddOnIfNeeded(
            $updatedMaxAvailableCredits,
            $maxAvailableCredits,
            $previousMaxAvailableCredits,
            $usedLimit,
            $serviceData
        );

        if ($updatedMaxAvailableCredits >= $usedLimit) {
            $this->updateUserLimitAndServiceForLimitUpdate(
                $previousMaxAvailableCredits,
                $usedLimit,
                $serviceData
            );
        }
    }

    /**
     * to update add on in services
     */
    public function updateAddOnIfNeeded(
        $updatedMaxAvailableCredits,
        &$maxAvailableCredits,
        $originalMaxAvailableCredits,
        $usedLimit,
        &$serviceData
    ) {
        if ($updatedMaxAvailableCredits >= $usedLimit) {
            return $updatedMaxAvailableCredits;
        }

        $addOnModel = $this->di->getObjectManager()->get(AddOn::class);
        $addOnResponse = $addOnModel->addOnSync(
            $serviceData['user_id'],
            $updatedMaxAvailableCredits - $originalMaxAvailableCredits
        );

        if (true !== $addOnResponse['success']) {
            $maxAvailableCredits = $updatedMaxAvailableCredits;
            return $updatedMaxAvailableCredits;
        }

        $updatedAddOn = 0;
        $updatedUserService = $addOnResponse['updatedUserService'] ?? [];
        if (!empty($updatedUserService)) {
            $updatedAddOn = $updatedUserService['add_on']['available_credits'] ?? 0;
            $serviceData = $updatedUserService;
        }

        $newMaxAvailableCredits = $originalMaxAvailableCredits + $updatedAddOn;

        return $this->updateAddOnIfNeeded(
            $newMaxAvailableCredits,
            $maxAvailableCredits,
            $originalMaxAvailableCredits,
            $usedLimit,
            $serviceData
        );
    }

    /**
     * to update service credits
     */
    public function updateUserLimitAndServiceForLimitUpdate(
        $previousMaxAvailableCredits,
        &$usedLimit,
        &$serviceData
    ): void {
        $addOnUsed = $usedLimit - $previousMaxAvailableCredits;
        $serviceData['add_on']['total_used_credits'] += (int)$addOnUsed;
        $finalAvailableAddOn = $serviceData['add_on']['available_credits'] - (int)$addOnUsed;
        $serviceData['add_on']['available_credits'] = ($finalAvailableAddOn < 0) ? 0 : $finalAvailableAddOn;
        $usedLimit -= $addOnUsed;
    }

    /**
     * to update user services in case of services expired but didn't renewed due to pending settlement
     */
    public function updateUserServicesIfExpired($userId): void
    {
        $userServices = $this->getCurrentUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
        if (!empty($userServices)) {
            $previousMonthLastDate = date("Y-n-j", strtotime("last day of previous month"));
            $previousMonthLastDate = date('Y-m-d', strtotime($previousMonthLastDate));
            if ((isset($userServices['expired_at']) && ($userServices['expired_at'] <= $previousMonthLastDate) && !isset($userServices['trial_service'])) || (isset($userService['deactivate_on']) && $userService['deactivate_on'] <= date('Y-m-d'))) {
                $this->processRenewPlans($userServices);
            } else {
                /**
                 * this will be commented as after a settlement payment client's sync is not activated until it renews by itself - settlement process 1
                 */
                if ($this->isSyncAllowed($userServices)) {
                    $userDetails = $this->commonObj->getUserDetail($userId);
                    $shopData = [];
                    if (!empty($userDetails) && $userDetails['shops']) {
                        foreach ($userDetails['shops'] as $shop) {
                            if (isset($shop['marketplace'])  && $shop['marketplace'] == self::SOURCE_MARKETPLACE) {
                                $shopData = $shop;
                            }
                        }
                    }

                    if (!empty($shopData)) {
                        // $this->di->getObjectManager()->get(SyncServices::class)->reUpdateEntry($userId, $shopData, self::MANUAL_ENTRY);
                        $this->reUpdateEntry($userId, $shopData);
                        $this->di->getObjectManager()->get(Email::class)->resetEmailConfig($userId);
                    }
                }

                /**
                 * settlement process 1
                 */
            }
        }
    }

    /**
     * to create settlement invoice
     */
    public function createSettlementInfo($serviceData = [], $paymentStatus = self::PAYMENT_STATUS_PENDING, $userId = false)
    {
        $currentDate = date('Y-m-d H:i:s');
        $generatedAt = date('Y-m-d');
        $lastPaymentDate = date('Y-m-d', strtotime('last day of this month'));
        $settlementFilter = [
            'user_id' => $userId,
            'type' => self::SETTLEMENT_INVOICE,
            'status' => $paymentStatus,
        ];
        $newSettlement = false;
        if (isset($serviceData['postpaid']['is_capped'], $serviceData['postpaid']['capped_amount']) && $serviceData['postpaid']['is_capped']) {
            $settlementFilter['is_capped'] = true;
            unset($settlementFilter['status']);
            $settlementFilter['$or'] = [
               ['status' => $paymentStatus],
               ['status' => self::PAYMENT_STATUS_ON_HOLD]
            ];
        }
        $statusChanged = false;
        $existingPendingSettlement = $this->paymentCollection->findOne($settlementFilter, self::TYPEMAP_OPTIONS);
        if (!empty($existingPendingSettlement)) {
            $settlementAmount = $this->getSettlementAmount($userId);
            $existingPendingSettlement['credits_used'] = (int)$serviceData['postpaid']['total_used_credits'];
            $existingPendingSettlement['prepaid'] = $serviceData['prepaid'];
            $existingPendingSettlement['postpaid'] = $serviceData['postpaid'];
            $existingPendingSettlement['updated_at'] = $currentDate;
            $existingPendingSettlement['settlement_amount'] = $settlementAmount;
            if ($existingPendingSettlement['status'] == self::PAYMENT_STATUS_ON_HOLD) {
                $existingPendingSettlement['status'] = self::PAYMENT_STATUS_PENDING;
                $statusChanged = true;
            }
            $this->setQuoteDetails(
                self::QUOTE_STATUS_ACTIVE,
                $userId,
                $existingPendingSettlement['_id'],
                $existingPendingSettlement,
                self::QUOTE_TYPE_SETTLEMENT
            );
            $settlementPaymentData = $existingPendingSettlement;
        } else {
            $settlementAmount = $this->getSettlementAmount($userId);
            $settlementId = (string)$this->baseMongo->getCounter(self::PAYMENT_DETAILS_COUNTER_NAME);
            $settlementPaymentData = [
                '_id' => $settlementId,
                'user_id' => $userId,
                'type' => self::SETTLEMENT_INVOICE,
                'status' => $paymentStatus,
                'source_marketplace' => self::SOURCE_MARKETPLACE,
                'target_marketplace' => self::TARGET_MARKETPLACE,
                'settlement_amount' => $settlementAmount,
                'credits_used' => (int)$serviceData['postpaid']['total_used_credits'],
                'method' => self::PAYMENT_METHOD,
                'created_at' => $currentDate,
                'updated_at' => $currentDate,
                'generated_at' => $generatedAt,
                'last_payment_date' => $lastPaymentDate,
                'prepaid' => $serviceData['prepaid'],
                'postpaid' => $serviceData['postpaid']
            ];
            if (isset($serviceData['postpaid']['is_capped'], $serviceData['postpaid']['capped_amount']) && $serviceData['postpaid']['is_capped']) {
                $settlementPaymentData['is_capped'] = true;
                $settlementPaymentData['max_capped_amount'] = $serviceData['postpaid']['capped_amount'] ?? 0;
                $settlementPaymentData['subscription_billing_date'] = $serviceData['subscription_billing_date'] ?? null;
                $settlementPaymentData['per_unit_usage_price'] = $serviceData['postpaid']['per_unit_usage_price'] ?? 0;
            }
            $quoteId = $this->setQuoteDetails(
                self::QUOTE_STATUS_ACTIVE,
                $userId,
                $settlementId,
                $settlementPaymentData,
                self::QUOTE_TYPE_SETTLEMENT
            );
            if ($quoteId) {
                $settlementPaymentData['quote_id'] = $quoteId;
            }

            $newSettlement = true;
        }

        if ($this->isTestUser($userId)) {
            $settlementPaymentData['test_user'] = true;
        }

        $mongoResult = $this->paymentCollection->replaceOne(
            ['_id' => $settlementPaymentData['_id']],
            $settlementPaymentData,
            ['upsert' => true]
        );
        if ($mongoResult) {
            if ($newSettlement) {
                $this->di->getObjectManager()->get(Transaction::class)->settlementGenerated($userId);
            }
            if ($statusChanged && !$newSettlement) {
                $this->di->getObjectManager()->get(Transaction::class)->updateSettlementStatus($settlementPaymentData);
            }
            //this code is added to send emails for postpaid usage
            $this->di->getObjectManager()->get(Email::class)->sendMailForPostPaidLimit($serviceData, $userId);
            $this->di->setPlan($this->di->getObjectManager()->create('App\Plan\Models\PlanDetails')->getPlanInfo($this->di->getUser()->id));
            return true;
        }

        return false;
    }

    /**
     * to get pending settlement invoice
     */
    public function getPendingSettlementInvoice($userId)
    {
        return $this->paymentCollection->findOne([
            'user_id' => $userId,
            'type' => self::SETTLEMENT_INVOICE,
            'status' => self::PAYMENT_STATUS_PENDING,
        ], self::TYPEMAP_OPTIONS);
    }

    /**
     * to approve the pending settlement
     */
    public function approveSettlementInfo($marketplaceData = [], $userId = false, $quoteId = ""): void
    {
        $transactionData = [];
        $paymentId = $this->setPaymentDetails($userId, self::PAYMENT_STATUS_APPROVED, $quoteId, $marketplaceData);
        $paymentDetails['id'] = $paymentId;
        isset($marketplaceData['id']) && $paymentDetails['charge_id'] = $marketplaceData['id'];
        isset($marketplaceData['price']) && $paymentDetails['price'] = $marketplaceData['price'];
        $paymentDetails['paid_on'] = date('c');
        $paymentDetails['status'] = self::PAYMENT_STATUS_APPROVED;

        $settlementFilter = [
            'user_id' => $userId,
            'type' => self::SETTLEMENT_INVOICE,
            'status' => self::PAYMENT_STATUS_PENDING,
            'quote_id' => $quoteId
        ];
        $currentDate = date('Y-m-d H:i:s');
        $this->paymentCollection->updateOne(
            $settlementFilter,
            [
                '$set' =>
                [
                    'status' => self::PAYMENT_STATUS_APPROVED,
                    'updated_at' => $currentDate,
                    'payment_date' => $currentDate
                ]
            ]
        );
        $this->paymentCollection->updateOne(
            ['_id' => $quoteId],
            [
                '$set' =>
                [
                    'status' => self::PAYMENT_STATUS_APPROVED,
                    'updated_at' => $currentDate
                ]
            ]
        );
        $transactionData['user_id'] = $userId;
        $transactionData['payment_info'] = $paymentDetails;
        $transactionData['quote_id'] = $quoteId;
        $transactionData['status'] = self::PAYMENT_STATUS_APPROVED;
        $this->di->getObjectManager()->get(Transaction::class)->settlementPaid($transactionData);
    }

    /**
     * to get settlement info of last month plus current month
     *
     * @param boolean $userId
     * @return array
     */
    public function settlementInfoFromPreviousMonth($userId = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $currentDate = date('Y-m-d', strtotime('+1 day'));
        $previousMonthFirstDate = date('Y-m-d', strtotime('first day of previous month'));
        $query = [
            'user_id' => $userId,
            'type' => self::SETTLEMENT_INVOICE,
            'generated_at' => [
                '$gte' => $previousMonthFirstDate,
                '$lt' => $currentDate
            ],
        ];
        return $this->paymentCollection->find($query, self::TYPEMAP_OPTIONS)->toArray();
    }

    /**
     * to check if syncing is on or not
     */
    public function isSyncAllowed($userService = [])
    {
        $response = true;
        if (empty($userService)) {
            $userId = $this->di->getUser()->id;
            $userService = $this->getCurrentUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
        }

        if (empty($userService)) {
            $response = false;
        } else {
            $currentDate = date('Y-m-d');
            if (isset($userService['deactivate_on']) && ($userService['deactivate_on'] <= $currentDate)) {
                return false;
            }

            $totalAvailableCredit = ($userService['prepaid']['available_credits'] ?? 0)
                + ($userService['postpaid']['available_credits'] ?? 0)
                + ($userService['add_on']['available_credits'] ?? 0);

            if ($totalAvailableCredit == 0) {
                $response = false;
            }

            $settlementInvoice = $this->getPendingSettlementInvoice($this->di->getUser()->id);
            if (!empty($settlementInvoice)) {
                if (
                    !isset($settlementInvoice['is_capped']) &&
                    $settlementInvoice['status'] == self::PAYMENT_STATUS_PENDING
                    && $settlementInvoice['last_payment_date'] < $currentDate
                ) {
                    return false;
                }
            }
        }

        return $response;
    }

    /**
     * to generate payment link
     */
    public function generateCustomPaymentLinkAsPerQuoteId($quoteId = false, $planType = self::QUOTE_TYPE_PLAN)
    {
        if (!$quoteId) {
            return [
                'success' => false,
                'message' => 'Quote id not found'
            ];
        }

        $homeUrl = $this->di->getConfig()->base_url_frontend;
        /* prepare token info for generated quote id*/
        $time = '+72 hour';
        $date = new DateTime($time);
        $tokenInfo = [
            'quote_id' => $quoteId,
            'exp' => $date->getTimestamp(),
            'aud' => '',
            'plan_type' => $planType
        ];
        !empty($this->di->getUser()->id) && $tokenInfo['user_id'] = $this->di->getUser()->id;

        $paymentToken = $this->di->getObjectManager()->get('\App\Core\Components\Helper')->getJwtToken($tokenInfo);
        $paymentUrl = $homeUrl . 'plan/plan/processQuotePayment?payment_token=' . $paymentToken;
        /*generate link for payment to share with client */
        return [
            'success' => true,
            'message' => 'Payment link generated successfully.',
            'payment_link' => $paymentUrl
        ];
    }

    /**
     * to disable entries in dynamo
     */
    public function updateTargetEntriesInDynamo($userId): void
    {
        $userDetails = $this->commonObj->getUserDetail($userId);
        if (!empty($userDetails) && isset($userDetails['shops'])) {
            foreach ($userDetails['shops'] as $shop) {
                if (isset($shop['marketplace']) && ($shop['marketplace'] == self::TARGET_MARKETPLACE)) {
                    $this->updateEntryInDynamo($shop['remote_shop_id'], 1);
                }
            }
        }
    }

    /**
     * to validate user's plan
     */
    public function validateUserPlan($params)
    {
        $userId = $params['user_id'] ?? $this->di->getUser()->id;
        $activePlanFilterData = [
            'user_id' => (string)$userId,
            'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
            'status' => self::USER_PLAN_STATUS_ACTIVE,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            'marketplace' => self::TARGET_MARKETPLACE,
        ];
        $activePlan =  $this->paymentCollection->findOne($activePlanFilterData, self::TYPEMAP_OPTIONS);

        if (empty($activePlan)) {
            return [
                'success' => false,
                'message' => 'You have no active plan'
            ];
        }

        $responseData['data']['plan_active'] = true;

        $serviceType = $params['service_type'] ?? self::SERVICE_TYPE_ORDER_SYNC;

        $userServiceFilterData = [
            'user_id' => (string)$userId,
            'type' => self::PAYMENT_TYPE_USER_SERVICE,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            'marketplace' => self::TARGET_MARKETPLACE,
            'service_type' => $serviceType,
        ];

        $userService = $this->paymentCollection->findOne($userServiceFilterData, self::TYPEMAP_OPTIONS);

        $responseData['success'] = $responseData['data']['sync_activated'] = $this->isSyncAllowed($userService);
        return $responseData;
    }

    /**
     * to downgrade a plan to free
     */
    public function downgradeToFree($params)//need to work on
    {
        if (!isset($params['user_id'])) {
            return 'User Id required!';
        }

        $cancelRecurringResponse = $this->cancelRecurryingCharge($params);
        if (!$cancelRecurringResponse['success']) {
            return $cancelRecurringResponse;
        }

        return $this->forcefullyFreePlanPurchase($params);
    }

    public function getPaymentLinkForAdmin($data)
    {
        if (empty($data['payment_link_type'])) {
            return ['success' => false, 'message' => 'Please select payment_link_type'];
        }

        $methodToCall = $data['payment_link_type'] . 'PaymentLink';
        if (!method_exists($this, $methodToCall)) {
            return ['success' => false, 'message' => 'Selected payment_link_type does not exist!'];
        }

        if (isset($data['user_id'])) {
            $diRes = $this->commonObj->setDiForUser($data['user_id']);
            if (!$diRes['success']) {
                return $diRes;
            }
        }

        return $this->$methodToCall($data);
    }

    public function planPaymentLink($data)
    {
        if (!isset($data['plan_id'])) {
            return ['success' => false, 'message' => 'plan_id is required'];
        }

        if (!isset($data['user_id'])) {
            $data['user_id'] = $this->di->getUser()->id;
        }

        $getPlanResponse = $this->getPlan($data['plan_id']);
        if (!$getPlanResponse['success'] || empty($getPlanResponse['data'])) {
            return ['success' => false, 'message' => $getPlanResponse['message'] ?? 'plan not found'];
        }

        $quoteId = $this->setQuoteDetails(
            self::QUOTE_STATUS_ACTIVE,
            $data['user_id'],
            $data['plan_id'],
            $getPlanResponse['data'],
            self::QUOTE_TYPE_PLAN
        );
        return $this->generateCustomPaymentLinkAsPerQuoteId($quoteId);
    }

    public function recommendationsBasedOnOrderPaymentLink($data)
    {
        if (!isset($data['user_id'])) {
            $data['user_id'] = $this->di->getUser()->id;
        }

        $recommendedPlansInfo = $this->di->getObjectManager()->get(Recommendations::class)->getPersonalizedRecommendations($data['user_id']);
        $recommendedPlans = (isset($recommendedPlansInfo['plans']) && !empty($recommendedPlansInfo['plans'])) ? $recommendedPlansInfo['plans'] : [];
        $paymentLinks = [];
        $hasError = false;
        foreach ($recommendedPlans as $key => $recommendedPlan) {
            if (empty($recommendedPlan['plan_id'])) {
                continue;
            }

            $quoteId = $this->setQuoteDetails(
                self::QUOTE_STATUS_ACTIVE,
                $data['user_id'],
                $recommendedPlan['plan_id'],
                $recommendedPlan,
                self::QUOTE_TYPE_PLAN
            );
            $generateLinkResponse = $this->generateCustomPaymentLinkAsPerQuoteId($quoteId);
            if (!$generateLinkResponse['success']) {
                $hasError = true;
                continue;
            }

            $paymentLinks[$key] = $generateLinkResponse['payment_link'];
        }

        if (empty($paymentLinks)) {
            $message = $hasError === false ? 'No recommendations found' : 'Error in generating payment link!';
            return ['success' => false, 'message' => $message];
        }

        return ['success' => true, 'payment_link' => $paymentLinks];
    }

    public function settlementPaymentLink($data)
    {
        if (!isset($data['user_id'])) {
            $data['user_id'] = $this->di->getUser()->id;
        }

        $userService = $this->getCurrentUserServices($data['user_id'], self::SERVICE_TYPE_ORDER_SYNC);
        if (empty($userService)) {
            return ['success' => false, 'message' => 'user_service not found!'];
        }

        $settlementInfo = $this->getSettlementInfo($userService);
        if (empty($settlementInfo['invoice']['quote_id'])) {
            $message = empty($settlementInfo) ? 'No settlement pending' : 'quote_id missing in settlement invoice!';
            return ['success' => false, 'message' => $message];
        }

        $quoteId = $settlementInfo['invoice']['quote_id'];
        return $this->generateCustomPaymentLinkAsPerQuoteId($quoteId, self::QUOTE_TYPE_SETTLEMENT);
    }

    /**
     * to handle register_for_order_sync key in user details for entry in dynamo
     */
    public function updateRegisterOrderSyncIfExist($userId = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $userCollection = $this->baseMongo->getCollectionForTable(self::USER_DETAILS_COLLECTION_NAME);
        $query = [
            'user_id' => $userId,
            'shops' => [
                '$exists' => true,
            ],
            'shops.register_for_order_sync' => [
                '$exists' => false,
            ]
        ];
        $user = $userCollection->findOne($query, self::TYPEMAP_OPTIONS);
        $preparedData = [];
        $shopEvent = $this->di->getObjectManager()->get('App\Amazon\Components\ShopEvent');
        if (!empty($user) && !empty($user['shops'])) {
            foreach ($user['shops'] as $shop) {
                if ($shop['marketplace'] == Plan::TARGET_MARKETPLACE) {
                    $preparedData['target_marketplace'] = [
                            'shop_id' => $shop['_id'],
                            'marketplace' => $shop['marketplace']
                    ];
                    $shopEvent->syncUserToDynamo($shop);
                }

                if ($shop['marketplace'] == Plan::SOURCE_MARKETPLACE) {
                    $preparedData['source_marketplace'] = [
                        'shop_id' => $shop['_id'],
                        'marketplace' => $shop['marketplace']
                    ];
                }
            }
            $data['source_shop'] = [
                '_id' => $preparedData['source_marketplace']['shop_id'],
                'marketplace' => $preparedData['source_marketplace']['marketplace'],
            ];
            $data['target_shop'] = [
                '_id' => $preparedData['target_marketplace']['shop_id'],
                'marketplace' => $preparedData['target_marketplace']['marketplace'],
            ];
            $this->di->getObjectManager()->get("\App\Amazon\Components\ConfigurationEvent")->runAllAmzProcess($data);
        } else {
            $query = [
                'user_id' => $userId,
                'shops' => [
                    '$exists' => true,
                ],
                'shops.register_for_order_sync' => [
                    '$exists' => true,
                ],
                'shops.register_for_order_sync' => false
            ];
            $user = $userCollection->findOne($query, self::TYPEMAP_OPTIONS);
            if (!empty($user) && !empty($user['shops'])) {
                $filter = [];
                foreach ($user['shops'] as $k => $shop) {
                    if ($shop['marketplace'] == Plan::TARGET_MARKETPLACE) {
                        $filter['shops.' . $k . '.register_for_order_sync'] = true;
                        $preparedData['target_marketplace'] = [
                            'shop_id' => $shop['_id'],
                            'marketplace' => $shop['marketplace']
                        ];
                        $removeRegisterForOrderSyncData = [
                            'user_id' => $userId,
                            'shop_id' => $shop['_id']
                        ];
                        $this->di->getObjectManager()->get("\App\Amazon\Components\ShopDelete")
                        ->removeRegisterForOrderSync($removeRegisterForOrderSyncData);
                        unset($shop['register_for_order_sync']);
                        $shopEvent->syncUserToDynamo($shop);
                    }

                    if ($shop['marketplace'] == Plan::SOURCE_MARKETPLACE) {
                        $preparedData['source_marketplace'] = [
                            'shop_id' => $shop['_id'],
                            'marketplace' => $shop['marketplace']
                        ];
                    }
                }
                $data['source_shop'] = [
                    '_id' => $preparedData['source_marketplace']['shop_id'],
                    'marketplace' =>$preparedData['source_marketplace']['marketplace'],
                  ];
                  $data['target_shop'] = [
                    '_id' => $preparedData['target_marketplace']['shop_id'],
                    'marketplace' => $preparedData['target_marketplace']['marketplace'],
                  ];
                $this->di->getObjectManager()->get("\App\Amazon\Components\ConfigurationEvent")->runAllAmzProcess($data);
                // if (!empty($filter)) {
                //     $userCollection->updateOne(
                //         [
                //             'user_id' => $userId
                //         ],
                //         [
                //             '$unset' => $filter
                //         ]
                //     );
                // }
            }
        }

        if(!empty($preparedData)) {
            $this->di->getObjectManager()->get('\App\Amazon\Components\Notification\Helper')->createSubscription($preparedData);
            $eventData = [
                'username' => $this->di->getUser()?->username,
                'user_id' => $this->di->getUser()?->id,
                'data' => $preparedData,
                'key' => 'onboarding_completed',
                'value' => 1,
                'shop' => $this->di->getUser()?->shops ?? []
            ];
            $this->di->getEventsManager()->fire('application:afterCreateSubscriptionForOrderSync', $this, $eventData);
        }
    }

    /**
     * activate payment process - new one
     */
    public function activatePayment($quoteData, $paymentData, $manual = false)
    {
        $success = true;
        $message = "";
        $isPlan = true;

        $userId = $this->di->getUser()->id;
        $planDetails = $quoteData['plan_details'] ?? [];
        $paymentType = $planDetails['payment_type'] ?? self::BILLING_TYPE_RECURRING;
        $logFile = 'plan/' . date('Y-m-d') . '/' . $userId . '_' . self::PLAN_JOURNEY_LOG . '.log';
        // $paymentData = $remoteResponse['data'] ?? [];
        $status = $paymentData['status'] ?? "";
        //prepare payment data if we need to activate a manual plan
        if($manual) {
            $status = "active";
            $paymentData['price'] = $planDetails['custom_price'] ?? 0;
            $paymentData['name'] = $planDetails['title'] ?? "";
        }

        $cappedAmount = $paymentData['capped_amount'] ?? self::DEFAULT_CAPPED_AMOUNT;
        $hasActivePlan = false;
        $activateNewPlan = false;
        //proceed if payment status is active
        if ($status == "active") {
            $transactionData['quote_id'] = $quoteData['_id'];
            $transactionData['old_user_services'] = $this->getCurrentUserServices($userId);
            $activePlan = $this->getActivePlanForCurrentUser($userId);
            $serviceClass = $this->di->getObjectManager()->create(Services::class);
            if (!empty($activePlan)) {
                $this->commonObj->addLog('Active plan already exist ---> ', $logFile);
                $this->commonObj->addLog('new plan paymentType: ' . $paymentType, $logFile);
                $this->commonObj->addLog('cappedAmount: ' . $cappedAmount, $logFile);
                $hasActivePlan = true;
                $activePlanDetails = $activePlan['plan_details'] ?? [];
                $activePlanPaymentType = $activePlanDetails['payment_type'] ?? self::BILLING_TYPE_RECURRING;
                $this->commonObj->addLog('activePlanPaymentType: '.json_encode($activePlanPaymentType), $logFile);
                //if new plan to be activate is recurring
                if ($paymentType == self::BILLING_TYPE_RECURRING) {
                    //if active plan is recurring
                    if ($activePlanPaymentType == self::BILLING_TYPE_RECURRING) {
                        // $billedType = $planDetails['billed_type'] ?? self::BILLED_TYPE_MONTHLY;
                        $this->commonObj->addLog('Plan is upgrading to a new recurring', $logFile);
                        //this needs to be handled for new pricing plan
                        $oldCredits = $serviceClass->getServiceCredits($activePlan['plan_details']);
                    } else {//if active plan is onetime
                        $this->commonObj->addLog('Plan is upgrading from recurring to onetime', $logFile);
                    }
                    $activateNewPlan = true;
                } elseif ($paymentType == self::BILLING_TYPE_ONETIME) {//if new plan is onetime
                    //if payment_type is not set but custom_plan is set - means they are on plan with custom active and are onetime as they are old plans
                    if (!isset($activePlanDetails['payment_type']) && isset($activePlanDetails['custom_plan'])) {
                        //plan is onetime
                        $activateNewPlan = true;
                    } elseif($activePlanPaymentType == self::BILLING_TYPE_RECURRING) {
                        $cancelRecurring = false;
                        if ($this->isFreePlan($activePlanDetails) || $this->isTrialPlan($activePlanDetails)) {
                            $this->commonObj->addLog('activePlan is free!', $logFile);
                            if (isset($activePlanDetails['capped_price'])) {
                                $cancelRecurring = true;
                            } else {
                                $activateNewPlan = true;
                            }
                        } else {
                            $cancelRecurring = true;
                        }
                        if ($cancelRecurring) {
                            $this->commonObj->addLog('Need to cancel the current recurring for activation of onetime and old plan is recurring', $logFile);
                            $recurringCancelRes = $this->cancelRecurryingForCurrentUser($userId);
                            $this->commonObj->addLog('recurringCancelRes: ' . $recurringCancelRes, $logFile);
                            if ($recurringCancelRes) {
                                $activateNewPlan = true;
                            } else {
                                $success = false;
                                $message = 'Cancellation of existing recurring failed!';
                                $this->commonObj->addLog('Cancellation of existing recurring failed! userId: '.$userId, 'plan/critical/'.date('Y-m-d').'.log');
                            }
                        }
                    } else {
                        $this->commonObj->addLog('activating new plan!', $logFile);
                        $activateNewPlan = true;
                    }
                }
            } else {
                $this->commonObj->addLog('First plan ---> ', $logFile);
                $activateNewPlan = true;
            }

            if ($activateNewPlan) {
                $discountedAmount = $this->commonObj->getDiscountedAmount($planDetails);
                //need to get the credits only for the service with order sync
                $newCredits = $serviceClass->getServiceCredits($planDetails);
                $this->commonObj->addLog('Activating plan ====>', $logFile);
                $this->commonObj->addLog('Stage 2.3.2: Setup quote, payment and user_service', $logFile);
                $quoteId = $this->setQuoteDetails(self::QUOTE_STATUS_APPROVED, $userId, $quoteData['plan_id'], $planDetails, Plan::QUOTE_TYPE_PLAN);
                $paymentId = $this->setPaymentDetails($userId, self::PAYMENT_STATUS_APPROVED, $quoteId, $paymentData, Plan::QUOTE_TYPE_PLAN);
                $transactionData['payment_id'] = $paymentId;
                $this->updateUserPlanServices($quoteId, $cappedAmount, $paymentData);

                // if ($hasActivePlan && isset($activePlan['plan_details']['code']) && $activePlan['plan_details']['code'] == 'trial') {
                //         $this->exhaustTrialPlan($userId);
                // }

                // if ($hasActivePlan && ($this->isTrialPlan($activePlan['plan_details']) )) {
                //     $this->exhaustTrialPlan($userId);
                // }

                // $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
                // $planCollection = $baseMongo->getCollectionForTable(Plan::PAYMENT_DETAILS_COLLECTION_NAME);
                $this->paymentCollection->deleteOne(
                    [
                        'user_id' => $userId,
                        'type' => ['$in' => ['process', 'trial_days']]
                    ]
                );

                $this->di->getObjectManager()->get(Recommendations::class)->updatePlanCreditsInSalesMetrics($userId, $newCredits);
                $shops = $this->di->getUser()->shops;
                $shopData = [];
                foreach($shops as $shop) {
                    if(isset($shop['marketplace']) && ($shop['marketplace'] == self::SOURCE_MARKETPLACE)) {
                        $shopData = $shop;
                        break;
                    }
                }

                $syncRes = $this->reUpdateEntry($userId, $shopData);
                $this->commonObj->addLog('reUpdateEntry in dynamo response: ' . json_encode($syncRes), $logFile);
                $planDetails['payment_details']['charge_id'] = $paymentData['id'] ?? "";
                $planDetails['payment_details']['subscription_status'] = $paymentData['status'] ?? "";
                $planDetails['next_billing_date'] = $paymentData['current_period_end'] ?? date('c', strtotime('+1month'));
                $this->di->getObjectManager()->get(Transaction::class)->planActivated($transactionData);
                $this->di->getObjectManager()->get(PlanEvent::class)->sendAppPurchaseEvent($planDetails);
                if ($hasActivePlan) {
                    $this->setPlanTier($planDetails, $activePlan['plan_details']);
                } else {
                    $this->setPlanTier($planDetails, [], $shopData);
                }
                $configCollection = $this->baseMongo->getCollectionForTable('config');
                $configQuery = [
                    'user_id' => $userId,
                    'key' => 'restricted_import',
                    'value' => true,
                    'group_code' => 'plan'
                ];
                $configRestrictedData = $configCollection->findOne($configQuery, self::TYPEMAP_OPTIONS);
                if(!empty($configRestrictedData)) {
                    $this->commonObj->addLog('Initiating product import!', $logFile);
                    $canProceedRes = $this->di->getObjectManager()->get(Helper::class)->processInitiateImport($userId);
                    $this->commonObj->addLog('canProceedRes : '.json_encode($canProceedRes), $logFile);
                }

                $settlementFilter = [
                    'user_id' => $userId,
                    'type' => self::SETTLEMENT_INVOICE,
                    '$or' => [
                        ['status' => self::PAYMENT_STATUS_PENDING],
                        ['status' => self::PAYMENT_STATUS_ON_HOLD]
                    ],
                    'is_capped' => true
                ];
                $existingSettlement = $this->paymentCollection->findOne($settlementFilter, self::TYPEMAP_OPTIONS);
                if (!empty($existingSettlement)) {
                    $this->di->getObjectManager()->get(Settlement::class)->approveCappedSettlementInfo($existingSettlement);
                }
                $this->commonObj->addLog('send mail via sqs ', $logFile);
                if ($hasActivePlan && ($paymentType == self::BILLING_TYPE_RECURRING)) {
                    $senderName = $this->di->getConfig()->get('mailer')->get('sender_name');
                    $mailData = [
                        'increased_from' => $oldCredits,
                        'increased_to' => $newCredits,
                        'sender' => $senderName,
                        'plan_details' => $planDetails,
                    ];
                    $this->di->getObjectManager()->get(Email::class)->sendeMailUsingSqs($mailData, $userId, 'sendPlanUpgradeMail');
                    $transactionData['plan_upgraded'] = true;
                    $message = $quoteData['plan_details']['title'] . ' plan upgraded successfully!';
                } else {
                    $this->di->getObjectManager()->get(Email::class)->sendeMailUsingSqs($planDetails, $userId, 'sendPlanActivationMail');
                    $message = $quoteData['plan_details']['title'] . ' plan activated successfully!';
                }
                if(!$manual) {
                    $paymentData['price'] = $discountedAmount;
                    $this->di->getObjectManager()->get(Email::class)->sendeMailUsingSqs($paymentData, $userId, 'sendPaymentSuccessMail');
                }
                $success = true;
            }
        } else {
            $this->commonObj->addLog('Changing status in quote and payment for rejected status', $logFile);
            $quoteId = $this->setQuoteDetails(Plan::QUOTE_STATUS_REJECTED, $userId, $quoteData['plan_id'], $planDetails, Plan::QUOTE_TYPE_PLAN);
            $this->setPaymentDetails($userId, Plan::PAYMENT_STATUS_CANCELLED, $quoteId, [], 'plan');
            $success = false;
            $message = 'Payment not activated at shopify!';
            $this->commonObj->addLog($message, $logFile);
        }

        return [
            'success' => $success,
            'message' => $message,
            'isPlan' => $isPlan
        ];
    }

    public function cancelRecurryingForCurrentUser($userId = false, $flag = true)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $paymentInfo = $this->getPaymentInfoForCurrentUser($userId);
        $shopId = $this->commonObj->getShopifyShopId($userId);
        $shop = $this->commonObj->getShop($shopId, $userId);

        if (!empty($shop) && isset($shop['remote_shop_id'])) {
            $shopId = $shop['remote_shop_id'];
            if (!empty($paymentInfo) && isset($paymentInfo['marketplace_data']['id'])) {
                $chargeId = $paymentInfo['marketplace_data']['id'];
                $apiData = ['id' => $chargeId, 'shop_id' => $shopId];
                $remoteResponse = $this->di->getObjectManager()->get(ShopifyPayment::class)->cancelPayment($apiData);
                if(!isset($remoteResponse['success']) || (isset($remoteResponse['success']) && !$remoteResponse['success'])) {
                    sleep(3);
                    $remoteResponse = $this->di->getObjectManager()->get(ShopifyPayment::class)->cancelPayment($apiData);
                }

                return $remoteResponse['success'] ?? false;
            }
        }

        return false;
    }

    /**
     * to check if recurring is active or not
     */
    public function isRecurringActive($shopId, $paymentInfo, $userId)
    {
        $success = true;
        $shop = $this->commonObj->getShop($shopId, $userId);
        if (!empty($shop)) {
            $data['shop_id'] = $shop['remote_shop_id'] ?? "";
            $data['id'] = $paymentInfo['marketplace_data']['id'] ?? "";
            $response = $this->di->getObjectManager()->get(ShopifyPayment::class)->getPaymentData($data, self::BILLING_TYPE_RECURRING);
            if (isset($response['data'], $response['success']) && $response['success']) {
                $response = $response['data'];
                return (isset($response['id']) && $response['status'] == 'active') ? true : false;
            }
        }

        return $success;
    }

    public function refundApplicationCredits($data)
    {
        if (!isset($data['description'], $data['amount'])) {
            return ['success' => false, 'message' => 'description and amount are required'];
        }

        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $shop = $this->commonObj->getShop($this->di->getRequester()->getSourceId(), $userId);
        if (empty($shop)) {
            return ['success' => false, 'message' => 'Shop not found for the user'];
        }

        $apiData = [
            'data' => [
                'description' => $data['description'],
                'amount' => $data['amount']
            ],
            'shop_id' => $shop['remote_shop_id']
        ];
        if (isset($data['currency'])) {
            $apiData['data']['currency'] = $data['currency'];
        }

        if ($this->isTestUser($userId) || (isset($data['test']) && $data['test'])) {
            $apiData['data']['test'] = true;
        }

        $remoteResponse = $this->di->getObjectManager()->get(ShopifyPayment::class)->createRefund($apiData);
        if (isset($remoteResponse['success']) && !$remoteResponse['success']) {
            $remoteResponse['message'] = is_string($remoteResponse['message'])
                ? $remoteResponse['message']
                : json_encode($remoteResponse['message']);
        } else {
            $remoteResponse['message'] = "Credits of {$remoteResponse['data']['amount']} {$remoteResponse['data']['currency']} refunded successfully";
        }

        return $remoteResponse;
    }

    /**
     * to get user services without and renew check
     */
    public function getCurrentUserServices($userId = false, $serviceType = false)
    {
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $userserviceFilterData = [
            'user_id' => (string)$userId,
            'type' => self::PAYMENT_TYPE_USER_SERVICE,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            'marketplace' => self::TARGET_MARKETPLACE,
        ];
        if ($serviceType !== false) {
            $userserviceFilterData['service_type'] = $serviceType;
            return $this->paymentCollection->findOne($userserviceFilterData, self::TYPEMAP_OPTIONS);
        }

        return $this->paymentCollection->find($userserviceFilterData, self::TYPEMAP_OPTIONS)->toArray();
    }

    /**
     * to notify clients for plan deactivation warning
     */
    public function notifySellersForPlanDeactivation($userId = false)
    {
        $weekDate = date('Y-m-d', strtotime('+1 week'));//the date after a week
	    $tomorrowDate = date('Y-m-d', strtotime('+1 day'));//starting from one day more than current
        $currentDate = date('Y-m-d');
        $logFile = 'plan/deactivation_mail/'.date('Y-m-d').'.log';

        $queryForPlanDeactivatingAfterAWeek = [
            'type' => self::PAYMENT_TYPE_USER_SERVICE,
            'deactivate_on' => ['$exists' => true],
            'deactivate_on' => ['$lt' => $weekDate, '$gt' =>$tomorrowDate]
        ];

        $queryForPlanDeactivatingTomorrow = [
            'type' => self::PAYMENT_TYPE_USER_SERVICE,
            'deactivate_on' => ['$exists' => true],
            'deactivate_on' => $tomorrowDate
        ];
        if($userId) {
            $queryForPlanDeactivatingAfterAWeek['user_id'] = $userId;
            $queryForPlanDeactivatingTomorrow['user_id'] = $userId;
        }

        $servicesAboutToInactivateInAWeek = $this->paymentCollection->find($queryForPlanDeactivatingAfterAWeek, self::TYPEMAP_OPTIONS)->toArray();
        $servicesAboutToInactivateTomorrow = $this->paymentCollection->find($queryForPlanDeactivatingTomorrow, self::TYPEMAP_OPTIONS)->toArray();


        if(!empty($servicesAboutToInactivateInAWeek)) {
            $this->commonObj->addLog('Processing for week ==> ', $logFile);
            foreach($servicesAboutToInactivateInAWeek as $serviceAboutToInactivate) {
                if(isset($serviceAboutToInactivate['user_id'])) {
                    $userId = $serviceAboutToInactivate['user_id'];
                    $activePlan = $this->getActivePlanForCurrentUser($userId);
                    $this->commonObj->addLog('User: '.json_encode($userId), $logFile);
                    if(!empty($activePlan) && !isset($activePlan['week_warning_mail_send'])) {
                        $this->commonObj->addLog('Active plan found!', $logFile);
                        $emailRes = $this->di->getObjectManager()->get(Email::class)->sendMailForPlanDeactivationWarning($userId, $serviceAboutToInactivate, $activePlan);
                        $this->commonObj->addLog('emailRes : '.json_encode($emailRes), $logFile);
                        if($emailRes['success']) {
                            $this->paymentCollection->updateOne(
                                [
                                    'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
                                    'user_id' => $userId,
                                    '_id' => $activePlan['_id']
                                ],
                                [
                                    '$set' =>[
                                        'week_warning_mail_send' => true,
                                        'week_warning_mail_send_on' => $currentDate
                                    ]
                                ]
                            );
                        }
                    }
                }
            }
        } else {
            $this->commonObj->addLog('No mails to send for this week!', $logFile);
        }

        if(!empty($servicesAboutToInactivateTomorrow)) {
            $this->commonObj->addLog('Processing for tomorrow ==> ', $logFile);
            foreach($servicesAboutToInactivateTomorrow as $serviceAboutToInactivateTomorrow) {
                if(isset($serviceAboutToInactivateTomorrow['user_id'])) {
                    $userId = $serviceAboutToInactivateTomorrow['user_id'];
                    $activePlan = $this->getActivePlanForCurrentUser($userId);
                    $this->commonObj->addLog('User: '.json_encode($userId), $logFile);
                    if(!empty($activePlan) && !isset($activePlan['last_warning_mail_send'])) {
                        $this->commonObj->addLog('Active plan found!', $logFile);
                        $emailRes = $this->di->getObjectManager()->get(Email::class)->sendMailForPlanDeactivationWarning($userId, $serviceAboutToInactivateTomorrow, $activePlan, $lastMail = true);
                        $this->commonObj->addLog('emailRes : '.json_encode($emailRes), $logFile);
                        if($emailRes['success']) {
                            $this->paymentCollection->updateOne(
                                [
                                    'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
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
                    }
                }
            }
        } else {
            $this->commonObj->addLog('No mails to send for tomorrow!', $logFile);
        }
    }

    public function proceedImport($data)
    {
        if(isset($data['user_id'])) {
            $userId = $data['user_id'];
        } else {
            $userId = $this->di->getUser()->id;
        }

        if(!empty($userId)) {
            $activePlan = $this->getActivePlanForCurrentUser($userId);
            if(!empty($activePlan)) {
                $helper = $this->di->getObjectManager()->get(Helper::class);
                return $helper->processInitiateImport($userId);
            }

            $result = [
                'sucsess' => false,
                'message' => 'No active plan found!'
            ];
        } else {
            $result = [
                'success' => false,
                'message' => 'User Id not found'
            ];
        }

        return $result;
    }

    /**
     * for test APIs
     */
    public function updateCreditTestCall($rawBody)
    {
        if(!empty($rawBody)) {
            if(isset($rawBody['type'])) {
                $type = $rawBody['type'] ?? "";
                $userId = $this->di->getUser()->id ?? ($rawBody['user_id'] ?? "");
                if (!$this->isTestUser($userId)) {
                    return [
                        'success' => false,
                        'message' => 'You are not a test user'
                    ];
                }

                $query = [
                    'user_id' => $userId,
                    'type' => $type
                ];
                if($type == self::PAYMENT_TYPE_ACTIVE_PLAN) {
                    $query['status'] = self::QUOTE_STATUS_ACTIVE;
                } elseif($type == self::SETTLEMENT_INVOICE) {
                    $query['status'] = self::PAYMENT_STATUS_PENDING;
                } elseif($type == self::PAYMENT_TYPE_PAYMENT) {
                    $query['plan_status'] = self::QUOTE_STATUS_ACTIVE;
                } elseif($type == self::PAYMENT_TYPE_USER_SERVICE) {
                    $query['service_type'] = self::SERVICE_TYPE_ORDER_SYNC;
                }

                $data = $this->paymentCollection->findOne($query, self::TYPEMAP_OPTIONS);
                if(empty($data)) {
                    return [
                        'success' => true,
                        'message' => 'Data is empty'
                    ];
                }

                if (isset($rawBody['method']) && $rawBody['method'] == 'GET') {
                    return [
                        'success' => true,
                        'data' => $data
                    ];
                }

                if (isset($rawBody['method']) && $rawBody['method'] == 'POST') {
                    if($type == self::PAYMENT_TYPE_ACTIVE_PLAN) {
                        $data['created_at'] = $rawBody['created_at'] ?? $data['created_at'];
                    } elseif($type == self::PAYMENT_TYPE_USER_SERVICE) {
                        $data['prepaid']['total_used_credits'] = $rawBody['prepaid']['total_used_credits'] ?? $data['prepaid']['total_used_credits'];
                        $data['prepaid']['available_credits'] = $rawBody['prepaid']['available_credits'] ?? $data['prepaid']['available_credits'];
                        $data['postpaid']['total_used_credits'] = $rawBody['postpaid']['total_used_credits'] ?? $data['postpaid']['total_used_credits'];
                        $data['postpaid']['available_credits'] = $rawBody['postpaid']['available_credits'] ?? $data['postpaid']['available_credits'];
                        if (!isset($data['postpaid']['is_capped'])) {
                            $data['postpaid']['capped_credit'] = $rawBody['postpaid']['capped_credit'] ?? $data['postpaid']['capped_credit'];
                        }
                        $data['expired_at'] = $rawBody['expired_at'] ?? $data['expired_at'];
                        if(isset($data['deactivate_on']) && isset($rawBody['deactivate_on'])) {
                            $data['deactivate_on'] = $rawBody['deactivate_on'] ?? $data['deactivate_on'];
                        }
                    } elseif($type == self::SETTLEMENT_INVOICE) {
                        $data['last_payment_date'] = $rawBody['last_payment_date'] ?? $data['last_payment_date'];
                    }

                    $this->paymentCollection->replaceOne(
                        $query,
                        $data,
                        ['upsert' => true]
                    );
                    return [
                        'success' => true,
                        'message' => 'Updated successfully'
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Method not found'
                ];
            }

            return [
                'success' => false,
                'message' => 'type not found'
            ];
        }

        return [
            'success' => false,
            'message' => 'No data found!'
        ];
    }

    public function isImportSyncAllowed($userId, $marketplace = self::SOURCE_MARKETPLACE)
    {
        $shopId =  $this->di->getRequester()->getSourceId()?? $this->commonObj->getShopifyShopId($userId);
        $this->di->getObjectManager()->get(Services::class)->manageProductImportCredits($userId, $shopId);
        $cachedData = $this->di->getCache()->get("import_restrictions_".$userId, "plan");
        if(!empty($cachedData) && isset($cachedData['sync_activated']) && $marketplace == self::SOURCE_MARKETPLACE) {
            return $cachedData['sync_activated'];
        }

        return true;
    }

    /**
     * to schedule plan for downgrade to free on a given date
     */
    public function scheduleForPlanDowngradeToFree($params)
    {
        if (!isset($params['user_id'])) {
            return [
                'success' => false,
                'message' => 'User Id required!'
            ];
        }

        if (!isset($params['schedule_at'])) {
            return [
                'success' => false,
                'message' => 'schedule_at required!'
            ];
        }

        $updateService = false;
        $updatedData = [];
        $userId = $params['user_id'];

        $res = $this->commonObj->setDiForUser($userId);
        if (!$res['success']) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }

        $downgradeAt = $params['schedule_at'];
        $userService = $this->getCurrentUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
        $response = [];
        if (empty($userService)) {
            $response = [
                'success' => false,
                'message' => 'No user service available!'
            ];
        } else {
            if (!isset($params['cancel_recurring_immediately'])) {
                $response = [
                    'success' => false,
                    'message' => 'cancel_recurring_immediately not set'
                ];
            } elseif($params['cancel_recurring_immediately'] == 1) {
                $activePlan = $this->getActivePlanForCurrentUser($userId);
                if(!empty($activePlan) && !empty($activePlan['plan_details'])) {
                    if($this->cancelRecurryingForCurrentUser($userId)) {
                        $updateService = true;
                        $updatedData = [
                            '$set' => [
                                'deactivate_on' => $downgradeAt,
                                'activate_free_plan' => true
                            ]
                        ];
                        $this->paymentCollection->updateOne(['_id' => $activePlan['_id'], 'user_id' => $userId], [
                            '$set' => [
                                'plan_details.payment_type' => 'onetime',
                                'deactivate_on' => $downgradeAt
                            ]
                        ]);
                    } else {
                        $response = [
                            'success' => false,
                            'message' => 'Unable to cancel recurring!'
                        ];
                    }
                } else {
                    $response = [
                        'success' => false,
                        'message' => 'No active plan to cancel recurring!'
                    ];
                }
            } elseif($params['cancel_recurring_immediately'] != 1) {
                $updateService = true;
                $updatedData = [
                    '$set' => [
                        'downgrade_to_free' => true,
                        'downgrade_to_free_on' => $downgradeAt
                    ]
                ];
            }

            if($updateService && !empty($updatedData)) {
                $userserviceFilterData = [
                    'user_id' => (string)$userId,
                    'type' => self::PAYMENT_TYPE_USER_SERVICE,
                    'source_marketplace' => self::SOURCE_MARKETPLACE,
                    'target_marketplace' => self::TARGET_MARKETPLACE,
                    'marketplace' => self::TARGET_MARKETPLACE,
                    'service_type' => self::SERVICE_TYPE_ORDER_SYNC
                ];
                $response = $this->paymentCollection->updateOne($userserviceFilterData, $updatedData);
                return [
                    'success' => true,
                    'message' => 'Received for updation...'
                ];
            }
        }

        return $response;
    }

    public function getPostpaidCreditsAsPerServiceCredits($serviceCredits)
    {
        if($serviceCredits == self::FREE_PLAN_CREDITS) {
            return 0;
        }

        return $serviceCredits;
    }

    public function isTestUser($userId)
    {
        if (in_array($userId, $this->di->getConfig()->get('testUserIds')->toArray())) {
            return true;
        }

        return false;
    }

    public function activatePaymentBasedOnType($data)
    {
        $redirectUrl = $this->commonObj->getDefaultRedirectUrl();
        if (!isset($data['quote_id'])) {
            return [
                'success' => false,
                'message' => 'Quote Id not found!',
                'redirect_url' => $redirectUrl
            ];
        }

        $quoteData = $this->getQuoteByQuoteId($data['quote_id']);
        if(!empty($quoteData) && isset($quoteData['user_id'])) {
            $userId = $quoteData['user_id'];
            $logFile = 'plan/' . date('Y-m-d') . '/' . $userId . '_' . self::PLAN_JOURNEY_LOG . '.log';
            $this->commonObj->addLog('quoteData: ' . json_encode($quoteData), $logFile);
            $res = $this->commonObj->setDiRequesterAndTag($userId);
            if (!$res['success']) {
                $this->commonObj->addLog('Unable to set di', $logFile);
                return [
                    'success' => false,
                    'message' => 'User not found',
                    'redirect_url' => $redirectUrl
                ];
            }

            $shop = $this->commonObj->getShop($quoteData['source_id'], $userId);
            if(empty($shop)) {
                $shopId = $this->commonObj->getShopifyShopId($userId);
                if ($shopId === $quoteData['source_id']) {
                    $this->commonObj->addLog('shop not found', $logFile);
                    return [
                        'success' => false,
                        'message' => 'Shop not found!',
                        'redirect_url' => $redirectUrl
                    ];
                }

                $this->commonObj->addLog('quote saved shop id is not matching the di shop! saved -> '.json_encode($quoteData['source_id']).', actual -> '.json_encode($shopId), $logFile);
                $shop = $this->commonObj->getShop($shopId, $userId);
                if (empty($shop)) {
                    $this->commonObj->addLog('shop not found after geeting from di!', $logFile);
                    return [
                        'success' => false,
                        'message' => 'Shop not found!',
                        'redirect_url' => $redirectUrl
                    ];
                }

                $this->paymentCollection->updateOne(
                    ['_id' => $quoteData['_id']],
                    [
                        '$set' =>
                        [
                            'source_id' => $shopId
                        ]
                    ]
                );
            }

            $shopifyDomain = $shop['domain'] ?? "";
            $redirectUrl = $this->commonObj->getDefaultRedirectUrl($shopifyDomain);

            if ($quoteData['status'] == Plan::QUOTE_STATUS_APPROVED) {
                $this->commonObj->addLog('already processed', $logFile);
                return [
                    'success' => false,
                    'message' => 'Quote already processed!',
                    'redirect_url' => $redirectUrl
                ];
            }

            $planType = $quoteData['plan_type'] ?? "";
            if (empty($planType)) {
                if (isset($quoteData['settlement_details'])) {
                    $planType = self::QUOTE_TYPE_SETTLEMENT;
                } elseif (!isset($quoteData['plan_details']['services_groups'])) {
                    $planType = self::QUOTE_TYPE_CUSTOM;
                } else {
                    $planType = self::QUOTE_TYPE_PLAN;
                }
            } elseif (!isset($quoteData['plan_details']['services_groups']) && !isset($quoteData['settlement_details'])) {
                $planType = self::QUOTE_TYPE_CUSTOM;
            }

            $manual = false;
            $remoteResponse = [];
            if(isset($data['activation_method']) && ($data['activation_method'] == 'manual')) {
                $manual = true;
                $this->commonObj->addLog('This is a manual plan activation process ------ ', $logFile);
            } else {
                $paymentType = "";
                if ($planType == self::QUOTE_TYPE_PLAN) {
                    $planDetails = $quoteData['plan_details'] ?? [];
                    $paymentType = $planDetails['payment_type'] ?? self::BILLING_TYPE_RECURRING;
                } elseif ($planType == self::QUOTE_TYPE_SETTLEMENT || $planType == self::QUOTE_TYPE_CUSTOM) {
                    $paymentType = self::BILLING_TYPE_ONETIME;
                }

                $remoteQuery = [
                    'shop_id' => $shop['remote_shop_id'] ?? "",
                    'id' => $data['charge_id']
                ];

                $this->commonObj->addLog('remoteQuery data: ' . json_encode($remoteQuery), $logFile);

                if(!empty($paymentType)) {
                    $remoteResponse = $this->di->getObjectManager()->get(ShopifyPayment::class)->getPaymentData($remoteQuery, $paymentType);
                    if(!isset($remoteResponse['success']) || !$remoteResponse['success'] || !isset($remoteResponse['data']) || empty($remoteResponse['data'])) {
                        $this->commonObj->addLog('Remote response not received properly: ' . json_encode($remoteResponse), $logFile);
                        sleep(3);
                        $remoteResponse = $this->di->getObjectManager()->get(ShopifyPayment::class)->getPaymentData($remoteQuery, $paymentType);
                    }
                    $this->commonObj->addLog('GET remote response: ' . json_encode($remoteResponse), $logFile);
                }
            }

            if ((!empty($remoteResponse) && isset($remoteResponse['success']) && $remoteResponse['success'] && isset($remoteResponse['data']['id']) && !empty($remoteResponse['data'])) || $manual) {
                $remoteResponse = $remoteResponse['data'];
                if (isset($remoteResponse['id']) && ($remoteResponse['status'] !== 'active')) {
                    return [
                        'success' => false,
                        'message' => 'Payment is not activated at the end of shopify!',
                        'redirect_url' => $redirectUrl
                    ];
                }

                // $remoteResponse = $remoteResponse['data'] ?? [];
                if($planType == self::QUOTE_TYPE_PLAN) {
                    $paymentRes = $this->activatePayment($quoteData, $remoteResponse, $manual);
                } elseif ($planType == self::QUOTE_TYPE_SETTLEMENT) {
                    $paymentRes = $this->activateSettlement($quoteData, $remoteResponse);
                } elseif ($planType == self::QUOTE_TYPE_CUSTOM) {
                    $paymentRes = $this->activateCustomPayment($quoteData, $remoteResponse);
                } elseif($planType == self::QUOTE_TYPE_ADD_ON) {
                    $addonModel = $this->di->getObjectManager()->get(AddOn::class);
                    $paymentRes = $addonModel->activateAddOn($userId, $quoteData['_id'], $planType, $remoteResponse['data']);
                }

                $success = $paymentRes['success'] ?? false;
                $message = $paymentRes['message'] ?? 'Some error occured!';
                $nullIds = [];
                if($success) {
                    //need to mark the process as 100% in queue if available
                    if (isset($quoteData['queue_task_ids'])) {
                        $queuedTask = new QueuedTasks;
                        foreach($quoteData['queue_task_ids'] as $taskId) {
                            if (is_null($taskId)) {
                                $nullIds[] = $quoteData['_id'];
                            } else {
                                $queuedTask->updateFeedProgress((string)$taskId, 100, 'Payment is completed!');
                            }
                        }

                        if (!empty($nullIds)) {
                            $queuedTaskCollection = $this->baseMongo->getCollection('queued_tasks');
                            $queryForIds = [
                                'additional_data.quote_id' => ['$in' => $nullIds]
                            ];
                            $pendingQueues = $queuedTaskCollection->find($queryForIds, self::TYPEMAP_OPTIONS)->toArray();
                            if(!empty($pendingQueues)) {
                                foreach($pendingQueues as $queue) {
                                    $queuedTask->updateFeedProgress((string)$queue['_id'], 100, 'Payment is completed!');
                                }
                            }
                        }
                    }

                    $notificationData = [
                        'message' => $message,
                        'severity' => 'success',
                        "user_id" => $userId,
                        "marketplace" => self::SOURCE_MARKETPLACE,
                        "appTag" => Plan::APP_TAG,
                        'process_code' => 'payment'
                    ];
                    $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($shop['_id'], $notificationData);
                }
            } else {
                $handlerData = [
                    'type' => 'full_class',
                    'method' => 'triggerWebhooks',
                    'class_name' => '\\App\\Connector\\Models\\SourceModel',
                    'queue_name' => 'app_subscriptions_update',
                    'user_id' => $userId,
                    'data' => [
                        'throttle_handle' => [
                            'quote_id' => $quoteData['_id'],
                            'charge_id' => $data['charge_id']
                        ]
                    ],
                    'marketplace' => self::SOURCE_MARKETPLACE,
                    'shop_id' => $shop['_id'],
                    'remote_shop_id' => $shop['remote_shop_id'],
                    'delay' => 180,
                    "shop" => $shopifyDomain,
                    "appCode" => self::APP_TAG,
                    "app_code" => self::APP_TAG,
                    "appTag" => self::APP_TAG,
                    "action" => "app_subscriptions_update",
                    "app_codes"=> [
                        self::APP_TAG
                    ]
                ];
                $this->commonObj->addLog('sending data to queu: ' . json_encode($handlerData), $logFile);
                $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                $sqsHelper->pushMessage($handlerData);
                $message = 'There is some issue in processing the payment please wait for sometime your plan will reflect soon!';
                $success = false;
                $queuedTask = new QueuedTasks;
                foreach($quoteData['queue_task_ids'] as $taskId) {
                        $queuedTaskId = $queuedTask->updateFeedProgress($taskId, 20, 'Payment is under process!');
                }
            }
        }

        $response['redirect_url'] = $redirectUrl;
        $response['isPlan'] = true;
        $response['shop'] = $shopifyDomain;
        $response['message'] = $message;
        $response['success'] = $success;
        return $response;
    }


    public function activateSettlement($quoteData, $remoteData)
    {
        $userId = $this->di->getUser()->id;
        $logFile = 'plan/' . date('Y-m-d') . '/' . $userId . '_' . self::PLAN_JOURNEY_LOG . '.log';
        $this->commonObj->addLog('Resetting used limits', $logFile);
        $this->approveSettlementInfo($remoteData, $userId, $quoteData['_id']);
        $this->resetUsedLimit($userId);
        $this->updateUserServicesIfExpired($userId);
        $mailResponse = $this->di->getObjectManager()->get(Email::class)->sendSettlmentSuccessMail($remoteData, $userId);
        $this->commonObj->addLog('mailResponse: ' . json_encode($mailResponse), $logFile);
        $settlementAmount = '$' . $remoteData['price'] . ' ' ?? '';
        return [
            'success' => true,
            'message' => $settlementAmount . 'Excess usage charge successfully paid',
        ];
    }

    public function activateCustomPayment($quoteData, $remoteData)
    {
        $userId = $this->di->getUser()->id;
        $this->setQuoteDetails(
            self::QUOTE_STATUS_APPROVED,
            $userId,
            $quoteData['plan_id'],
            $quoteData['plan_details'],
            self::QUOTE_TYPE_CUSTOM
        );
        $this->setPaymentDetails($userId, Plan::PAYMENT_STATUS_APPROVED, $quoteData['_id'], $remoteData, self::QUOTE_TYPE_CUSTOM);
        $transactionData = [
            'quote_id' => $quoteData['_id'],
            'user_id' => $userId,
            'payment_details' => [
                'charge_id' => $remoteData['id']
            ]
        ];
        $this->di->getObjectManager()->create(Email::class)->sendMailForCustomPaymentSucess($remoteData, $userId);
        $this->di->getObjectManager()->get(Transaction::class)->customPaymentProcessed($transactionData);
        return [
            'success' => true,
            'message' => 'Your payment of $'.($remoteData['price'] ?? $quoteData['plan_details']['custom_price']).' is successfully received!'
        ];
    }

    public function processQuoteForCustom($quoteId, $quoteType = '')
    {
        $quote = $this->getQuoteByQuoteId($quoteId);
        if (!empty($quote) && isset($quote['user_id'])) {
            $userId = $quote['user_id'];
            $redirectUrl = $this->commonObj->getDefaultRedirectUrl();
            if (!empty($userId)) {
                $res = $this->commonObj->setDiRequesterAndTag($userId);
                if (!$res['success']) {
                    return [
                        'success' => false,
                        'message' => 'User not found!',
                        'redirect_url' => $redirectUrl
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'User not found!',
                    'redirect_url' => $redirectUrl
                ];
            }

            $shopifyDomain = $this->getDomainName();
            $redirectUrl = $this->commonObj->getDefaultRedirectUrl($shopifyDomain);
            $quoteType = $quote['plan_type'] ?? $quoteType;
            if($quote['status'] == self::QUOTE_STATUS_APPROVED) {
                return [
                    'success' => false,
                    'message' => 'Quote already processed!',
                    'redirect_url' => $redirectUrl
                ];
            }

            if($quoteType == self::QUOTE_TYPE_PLAN) {
                return $this->processPlanQuotePayment($quote);
            }

            return $this->processQuotePayment($quote);
        }

        return [
            'success' => false,
            'message' => 'Quote not found!'
        ];
    }

    public function getExistingTaskIds($processTag, $userId)
    {
        $collection = $this->baseMongo->getCollection('queued_tasks');
        $query = ['user_id' => $userId, "process_code" => $processTag];
        return $collection->distinct("_id", $query);
    }

    public function getPendingProcesses($userId)
    {
        $collection = $this->baseMongo->getCollection('queued_tasks');
        $query = ['user_id' => $userId, "tag" => 'payment_process', 'shop_id' => $this->di->getRequester()->getSourceId()];
        return $collection->find($query, self::TYPEMAP_OPTIONS)->toArray();
    }

    /**
     * the issues are if the client uninstalls the app we have no shop data at that time after data removal
     */
    public function refundApplicationCreditsQL($data)
    {
        if (!isset($data['description'], $data['amount'], $data['app_id'], $data['store_id'])) {
            return ['success' => false, 'message' => 'description|amount|app_id|store_id are required'];
        }

        $canRestore = (int)($data['can_restore'] ?? 1);
        if (!$canRestore && !isset($data['username'])) {
            return ['success' => false, 'message' => 'username required if user need to be restricted to restore!'];
        }

        $apiData = [
            'data' => [
                'description' => $data['description'],
                'amount' => $data['amount'],
                'store_id' => $data['store_id'],
                'app_id' => $data['app_id']
            ]
        ];
        if (isset($data['currency'])) {
            $apiData['data']['currency'] = $data['currency'];
        } else {
            $apiData['data']['currency'] = 'USD';
        }

        if (isset($data['test']) && $data['test']) {
            $apiData['data']['test'] = true;
        }

        $apiData['call_type'] = 'QL';
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
        ->init('shopify_partner', true, 'shopify_partner_sales_channel')
        ->call('credit/create', [], $apiData, 'POST');
        if (!$remoteResponse['success']) {
            $remoteResponse['message'] = is_string($remoteResponse['message'])
                ? $remoteResponse['message']
                : json_encode($remoteResponse['message']);
        } else {
            if (!$canRestore && !empty($data['username'])) {
                $actionContainer = $this->baseMongo->getCollectionForTable('action_container');
                $filter = [
                    'type' => 'restricted_users_to_restore',
                    'action' => 'plan_restore_restrictions'
                ];
                $collectionData = $actionContainer->findOne($filter, self::TYPEMAP_OPTIONS);
                if (!empty($collectionData) && !empty($collectionData['users'])) {
                    if (!in_array($data['username'], $collectionData['users'])) {
                        array_push($collectionData['users'], $data['username']);
                    }
                } else {
                    $collectionData = [
                        '_id' => random_int(1000, 100000000000),
                        'type' => 'restricted_users_to_restore',
                        'action' => 'plan_restore_restrictions',
                        'users' => [$data['username']],
                        'created_at' => date('c')
                    ];
                }

                $actionContainer->replaceOne(
                    ['_id' => $collectionData['_id']],
                    $collectionData,
                    ['upsert' => true]
                );
            }

            $remoteResponse['message'] = "Credits of {$data['amount']} {$data['currency']} refunded successfully";
        }

       return $remoteResponse;
    }

    public function canApplyDiscount($activePlanDetails)
    {
        $billingType = $activePlanDetails['billed_type'] ?? self::BILLED_TYPE_MONTHLY;
        if ($this->isFreePlan($activePlanDetails) && !isset($activePlanDetails['custom_plan'])) {
            return true;
        }

        if (($billingType == self::BILLED_TYPE_MONTHLY) && !isset($activePlanDetails['custom_plan'])) {
            if (isset($activePlanDetails['payment_type']) && ($activePlanDetails['payment_type'] == self::BILLING_TYPE_ONETIME)) {
                return false;
            }

            return true;
        }

        return false;
    }

    public function trialPlanExhausted($userId)
    {
        return false;
        $flag = false;
        if ($this->di->getCache()->has("trial_plan_exhausted_" . $userId, "plan")) {
            $flag = (bool)$this->di->getCache()->get("trial_plan_exhausted_" . $userId, "plan");
        } else {
            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $trialCollection = $baseMongo->getCollectionForTable('trial_restricted');
            $username = '';
            if ($this->di->getUser()->id == $userId) {
                $username = $this->di->getUser()->username;
            } else {
                $userDetails = $this->commonObj->getUserDetail($userId);
                $username = $userDetails['username'] ?? '';
            }

            $info = $trialCollection->findOne(['username' => $username]);
            if (!empty($info)) {
                $this->di->getCache()->set("trial_plan_exhausted_" . $userId, true, "plan");
                $flag = true;
            } else {
                $this->di->getCache()->set("trial_plan_exhausted_" . $userId, false, "plan");
            }
        }

        return $flag;
    }

    public function disableTrialPlan($userId)
    {
        $this->exhaustTrialPlan($userId);
        $this->deactivatePlan('trial', $userId);
        $this->di->getObjectManager()->get(Email::class)->notifySellersForPlanPause($userId);
        return true;
    }

    public function exhaustTrialPlan($userId)
    {
        return true;
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $trialCollection = $baseMongo->getCollectionForTable('trial_restricted');
        $username = '';
        if ($this->di->getUser()->id == $userId) {
            $username = $this->di->getUser()->username;
        } else {
            $userDetails = $this->commonObj->getUserDetail($userId);
            $username = $userDetails['username'] ?? '';
        }

        $trialCollection->replaceOne(
            ['username' => $username],
            [
                'username' => $username,
                'trial_plan_exhaust' => true,
                'created_at' => date('c'),
                'user_id' => $userId
            ],
            ['upsert' => true]
        );
        $this->di->getCache()->set("trial_plan_exhausted_" . $userId, true, "plan");
        return true;
    }

    public function activateTrialPlan($planDetails, $userId)
    {
        $this->logFile = 'plan/' . date('Y-m-d') . '/' . $userId . '_' . self::PLAN_JOURNEY_LOG . '.log';
        foreach($this->di->getUser()->shops as $shop) {
            if($shop['marketplace'] == self::SOURCE_MARKETPLACE) {
                $shopData = $shop;
            }
        }

        $quoteId = $this->setQuoteDetails(Plan::QUOTE_STATUS_APPROVED, $userId, $planDetails['plan_id'], $planDetails, self::QUOTE_TYPE_PLAN);
        $this->commonObj->addLog('QuoteId generated: ' . $quoteId, $this->logFile);
        // $transactionData['user_id'] = $userId;
        // $transactionData['quote_id'] = $quoteId;
        // $transactionData['app_tag'] = $this->di->getAppCode()->getAppTag();
        // $transactionData['old_user_services'] = $this->getCurrentUserServices($userId);

        $paymentId = $this->setPaymentDetails($userId, Plan::PAYMENT_STATUS_APPROVED, $quoteId, []);
        $this->commonObj->addLog('setPaymentDetails generated id: ' . $paymentId, $this->logFile);

        // $transactionData['payment_id'] = $paymentId;
        $this->updateUserPlanServices($quoteId, $this->getCappedAmountForSelectedPlan($userId));
        $this->commonObj->addLog('User services updated!', $this->logFile);
        $syncRes = $this->reUpdateEntry($userId, $shopData);
        $this->commonObj->addLog('reUpdateEntry in dynamo response: ' . json_encode($syncRes), $this->logFile);
        $mailResponse = $this->di->getObjectManager()->get(Email::class)->sendPlanActivationMail($planDetails, $userId);
        // $transactionData['plan_activation_mail'] = $mailResponse;
        $this->commonObj->addLog('Plan Activation mail response: ' . json_encode($mailResponse), $this->logFile);
        $this->commonObj->addLog('Plan activated for free plan!', $this->logFile);
        $this->di->getObjectManager()->get(PlanEvent::class)->sendAppPurchaseEvent($planDetails);
        // $this->di->getObjectManager()->get(Transaction::class)->planActivated($transactionData);
        return ['success' => true, 'data' => $planDetails, 'message' => 'Trial plan activated successfully!'];
    }

    /**
     * to activate free plan without capping
     */
    public function activateFreePlanNoneCapped($planDetails, $userId)
    {
        $this->logFile = 'plan/' . date('Y-m-d') . '/' . $userId . '_' . self::PLAN_JOURNEY_LOG . '.log';
        foreach($this->di->getUser()->shops as $shop) {
            if($shop['marketplace'] == self::SOURCE_MARKETPLACE) {
                $shopData = $shop;
            }
        }
        $quoteId = $this->setQuoteDetails(Plan::QUOTE_STATUS_APPROVED, $userId, $planDetails['plan_id'], $planDetails, self::QUOTE_TYPE_PLAN);
        $this->commonObj->addLog('QuoteId generated: ' . $quoteId, $this->logFile);

        $transactionData['user_id'] = $userId;
        $transactionData['quote_id'] = $quoteId;
        $transactionData['app_tag'] = $this->di->getAppCode()->getAppTag();
        $transactionData['old_user_services'] = $this->getCurrentUserServices($userId);

        $paymentId = $this->setPaymentDetails($userId, Plan::PAYMENT_STATUS_APPROVED, $quoteId, []);
        $this->commonObj->addLog('setPaymentDetails generated id: ' . $paymentId, $this->logFile);

        $transactionData['payment_id'] = $paymentId;
        $this->updateUserPlanServices($quoteId, $this->getCappedAmountForSelectedPlan($userId));
        $this->commonObj->addLog('User services updated!', $this->logFile);
        $syncRes = $this->reUpdateEntry($userId, $shopData);
        $this->commonObj->addLog('reUpdateEntry in dynamo response: ' . json_encode($syncRes), $this->logFile);
        $mailResponse = $this->di->getObjectManager()->get(Email::class)->sendPlanActivationMail($planDetails, $userId);
        $transactionData['plan_activation_mail'] = $mailResponse;
        $this->commonObj->addLog('Plan Activation mail response: ' . json_encode($mailResponse), $this->logFile);
        $this->commonObj->addLog('Plan activated for free plan!', $this->logFile);
        $this->di->getObjectManager()->get(PlanEvent::class)->sendAppPurchaseEvent($planDetails);
        $this->di->getObjectManager()->get(Transaction::class)->planActivated($transactionData);
        return ['success' => true, 'data' => $planDetails, 'message' => 'Free plan activated successfully!'];
    }

    /**
     * to process custom plans from users end
     */
    public function processCustomUserPlan($rawBody)
    {
        if (!isset($rawBody['service_credits'], $rawBody['billed_type'])) {
            return [
                'success' => false,
                'message' => 'service_credits/billed_type are required field!'
            ];
        }

        $totalOrders = (int)$rawBody['service_credits'];
        $billedType = $rawBody['billed_type'];
        $get = isset($rawBody['get']) &&  ((int)$rawBody['get'] === 1) ? true : false;
        if ($totalOrders < 50) {
            return [
                'success' => false,
                'message' => 'Order credits should be more than 50!'
            ];
        }

        if (!in_array($billedType, [self::BILLED_TYPE_MONTHLY, self::BILLED_TYPE_YEARLY])) {
            return [
                'success' => false,
                'message' => 'billed type not correct!'
            ];
        }

        $userId = $rawBody['user_id'] ?? $this->di->getUser()->id;
        $settlementAmount = $this->getSettlementAmount($userId);
        if ($settlementAmount > 0) {
            return [
                'success' => false,
                'message' => 'You have an excess usage charge still pending to settle. Please settle to switch to a new plan!'
            ];
        }

        $userService = $this->getCurrentUserServices($userId, self::SERVICE_TYPE_ORDER_SYNC);
        $totalUsedCredit = 0;
        if (!empty($userService)) {
            $totalUsedCredit = (int)($userService['prepaid']['total_used_credits'] ?? 0)
                            + (int)($userService['postpaid']['total_used_credits'] ?? 0)
                            + (int)($userService['add_on']['total_used_credits'] ?? 0);
            $totalUsedCredit += self::MARGIN_ORDER_COUNT;
        }

        if ($totalOrders <= $totalUsedCredit) {
            return ['success' => false, 'message' => 'You are not allowed to select this plan as you used '.($totalUsedCredit - Plan::MARGIN_ORDER_COUNT).' credits, while this plan is limited to '.$totalOrders.' credits only!'];
        }

        $price = $this->calculatePriceBasedOnCreditsAndBillingType($totalOrders, $billedType);
        $basePlan = [];
        $query = [];
        if ($totalOrders > 2500) {//to handle upper limit
            $query = ['custom_price' => ['$lte' => self::MAX_MONTHLY_PAID_PLAN_PRICE], 'billed_type' => $billedType, 'category' => 'regular'];
        } elseif ($totalOrders >= 25 && $totalOrders <= 100) {//to handle lower limit
            $query = ['custom_price' => ['$lte' => self::LOWEST_PAID_PLAN_PRICE['regular'][$billedType]], 'billed_type' => $billedType, 'category' => 'regular'];
        } else {
            $query = ['custom_price' => ['$lte' => $price], 'billed_type' => $billedType, 'category' => 'regular'];
        }

        $options = self::TYPEMAP_OPTIONS;
        $options['sort'] = ['custom_price' => -1];
        $options['limit'] = 1;
        $basePlan = $this->planCollection->findOne($query, $options);
        if(!empty($basePlan) && ($basePlan['custom_price'] != 0)) {
            $customPlanData = [
                'user_id' => $userId,
                'base_plan_id' => $basePlan['plan_id'],
                'plan_id' => $basePlan['plan_id'].'_custom',
                'title' => $basePlan['title'].' - Custom',
                'custom_price' => $price,
                'payment_type' => self::BILLING_TYPE_RECURRING,
                'category' => $basePlan['category'],
                'billed_type' => $billedType,
                'services' => [
                    [
                        'type' => 'order_sync',
                        'prepaid' => $totalOrders,
                        'postpaid' => $totalOrders
                    ]
                    ],
                'type' => 'plan'
            ];
            if ($billedType == self::BILLED_TYPE_YEARLY) {
                // $customPlanData['discounts'][] = [
                //     'name' => 'Yearly Offer',
                //     'type' => 'fixed',
                //     'value' => floor($price/12)
                // ];
            }

            $helper = $this->di->getObjectManager()->get(Helper::class);
            return $helper->prepareCustomPaymentLink($customPlanData, $get);
        }

        return [
            'success' => false,
            'message' => 'Plan not found eligible for provided count!'
        ];
    }

    public function calculatePriceBasedOnCreditsAndBillingType($totalOrders, $type)
    {
        $price = 0;

        /**
         * The $rate_per_order for calculating the cost of a custom plan is based on order slabs:
            *   Up to 100 orders: $0.15 per order
            *   101 to 500 orders: $0.10 per order
            *   501 to 1000 orders: $0.08 per order
            *   1001 to 2500 orders: $0.06 per order
            *   Above 2500 orders: $0.05 per order
         */
        if ($totalOrders > 2500) {
            $price += ($totalOrders - 2500) * 0.05;
            $totalOrders = 2500;
        }

        if ($totalOrders > 1000) {
            $price += ($totalOrders - 1000) * 0.06;
            $totalOrders = 1000;
        }

        if ($totalOrders > 500) {
            $price += ($totalOrders - 500) * 0.08;
            $totalOrders = 500;
        }

        if ($totalOrders > 100) {
            $price += ($totalOrders - 100) * 0.10;
            $totalOrders = 100;
        }

        if ($totalOrders > 0) {
            $price += $totalOrders * 0.15;
        }

        if ($type == self::BILLED_TYPE_YEARLY) {
            $price = $price * 12;
        }

        return $price;
    }

    public function convertFreeToTrial($userId = false, $skipService = false)
    {
        $logFile = 'plan/freetotrial/' . date('Y-m-d') . '.log';
        try {
            $offset = 0;
            $options = [
                'limit' => self::RENEW_PLANS_BATCH_SIZE,
                'skip' => $offset,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];
            $conditionFilterData = [
                'type' => self::PAYMENT_TYPE_USER_SERVICE,
                'source_marketplace' => self::SOURCE_MARKETPLACE,
                'target_marketplace' => self::TARGET_MARKETPLACE,
                'marketplace' => self::TARGET_MARKETPLACE,
                'service_type' => self::SERVICE_TYPE_ORDER_SYNC,
                'trial_service' => ['$exists' => false],
                'prepaid.service_credits' => ['$lte' => (self::FREE_PLAN_CREDITS + 20)],
                'convert' =>  ['$exists' => false],
                // 'postpaid.total_used_credits' => 0,
            ];
            if ($skipService) {
                unset($conditionFilterData['prepaid.service_credits']);
            }

            if ($userId) {
                $conditionFilterData['user_id'] = $userId;
            }

            $userServices = $this->paymentCollection->find($conditionFilterData, $options)->toArray();
            if (count($userServices) >= 1) {
                $trialPlan = $this->getPlanByQuery(['code' => 'trial']);
                if (!empty($trialPlan['data'][0])) {
                    $trialPlan = $trialPlan['data'][0];
                    $trialPlan['offered_price'] = 0;
                } else {
                    return [
                        'success' => false,
                        'message' => 'Trial Plan not found!'
                    ];
                }

                foreach ($userServices as $userService) {
                    if (!empty($userService['user_id'])) {
                        $userId = $userService['user_id'];
                        $this->paymentCollection->updateOne(['user_id' => $userId, 'type' => self::PAYMENT_TYPE_USER_SERVICE, 'service_type' => self::SERVICE_TYPE_ORDER_SYNC], ['$set' => ['convert' => true]]);
                        $res = $this->commonObj->setDiForUser($userId);
                        if (!$res['success']) {
                            $this->commonObj->addLog('User not found to set in di: ' . $userId, $logFile);
                            continue;
                        }
                    } else {
                        $this->commonObj->addLog('User not found in service: ' . json_encode($userService), $logFile);
                        continue;
                    }

                    $this->commonObj->addLog('Processing for user: ' . $this->di->getUser()->id, $logFile);
                    $this->di->getAppCode()->setAppTag($userService['app_tag'] ?? self::APP_TAG);
                    if (empty($userService['source_id'])) {
                        $userService['source_id'] = $this->commonObj->getShopifyShopId();
                        if (!$userService['source_id']) {
                            $this->commonObj->addLog('Unable to find source_id', $logFile);
                            continue;
                        }
                    }

                    $this->di->getRequester()->setSource(
                        [
                            'source_id' => $userService['source_id'],
                            'source_name' => ''
                        ]
                    );
                    $activePlan = $this->getActivePlanForCurrentUser($userId);
                    if (!empty($activePlan) && isset($activePlan['plan_details'])) {
                        if ($this->isFreePlan($activePlan['plan_details'])) {
                            if (isset($userService['postpaid']['total_used_credits']) && ($userService['postpaid']['total_used_credits'] > 0)) {
                                $pendingSettlement = $this->getPendingSettlementInvoice($userId);
                                if (!empty($pendingSettlement)) {
                                    $this->paymentCollection->updateOne(
                                        [
                                            'user_id' => $this->di->getUser()->id,
                                            'type' => Plan::SETTLEMENT_INVOICE,
                                            'status' => Plan::PAYMENT_STATUS_PENDING
                                        ],
                                        [
                                            '$set' => ['status' => 'waived_off', 'updated_at' => date('Y-m-d H:i:s')]
                                        ]
                                    );
                                    $resetLimitFilterData = [
                                        'user_id' => $this->di->getUser()->id,
                                        'type' => Plan::PAYMENT_TYPE_USER_SERVICE,
                                        'service_type' => Plan::SERVICE_TYPE_ORDER_SYNC,
                                        '$expr' => [
                                            '$gt' => [
                                                '$postpaid.total_used_credits', 0
                                            ]
                                        ]
                                    ];
                                    $this->paymentCollection->updateOne($resetLimitFilterData, [
                                        '$set' => [
                                            'postpaid.available_credits' => 0,
                                            'postpaid.total_used_credits' => 0
                                        ]
                                    ]);
                                    $this->commonObj->addLog('client has pending settlement which is waived off!', $logFile);
                                }
                            }

                            $this->deactivatePlan('free', $userId);
                            $this->activateTrialPlan($trialPlan, $userId);
                            //code to update user services
                            $query = [];
                            $query = [
                                'user_id' => $userId,
                                'type' => self::PAYMENT_TYPE_USER_SERVICE,
                                'source_marketplace' => self::SOURCE_MARKETPLACE,
                                'target_marketplace' => self::TARGET_MARKETPLACE,
                                'marketplace' => self::TARGET_MARKETPLACE,
                                'service_type' => self::SERVICE_TYPE_ORDER_SYNC
                            ];
                            $this->paymentCollection->updateOne($query, ['$set' => ['prepaid.available_credits' => 50, 'prepaid.total_used_credits' => 0]]);
                        } else {
                            $this->commonObj->addLog('User is not on free plan!', $logFile);
                        }
                    } else {
                        $this->commonObj->addLog('No active plan for this user', $logFile);
                    }
                }
            } else {
                $this->commonObj->addLog('No more user services to update!', $logFile);
                return [
                    'success' => true,
                    'message' => 'No more user services to update!'
                ];
            }

            return [
                'success' => true,
                'message' => 'Successfully done.'
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage()
            ];
        }
    }

    public function addAdditionalServices($data)
    {
        $offset = 0;
        $options = [
            // 'limit' => self::RENEW_PLANS_BATCH_SIZE,
            // 'skip' => $offset,
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'projection' => ['user_id' => 1, '_id' => 0, 'source_id' => 1]
        ];
        $conditionFilterData = [];
        if (isset($data['order_credit_based'], $data['credits']) && ($data['order_credit_based'] == 1) && ($data['credits'] > 0)) {
            $conditionFilterData = [
                'type' => self::PAYMENT_TYPE_USER_SERVICE,
                'source_marketplace' => self::SOURCE_MARKETPLACE,
                'target_marketplace' => self::TARGET_MARKETPLACE,
                'marketplace' => self::TARGET_MARKETPLACE,
                'service_type' => self::SERVICE_TYPE_ORDER_SYNC,
                // 'trial_service' => ['$exists' => false],
                // 'meta_service_add' =>  ['$exists' => false],
                'prepaid.service_credits' => ['$gte' => (int)$data['credits']]
            ];
        } elseif (isset($data['price_based'], $data['price']) && ($data['price_based'] == 1) && ($data['price'] > 0)) {
            $conditionFilterData = [
                'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
                'status' => self::USER_PLAN_STATUS_ACTIVE,
                'plan_details.custom_price' => ['$gte' => (int)$data['price']]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'You need to define your condition either order_credit_based/price_based'
            ];
        }

        if (isset($data['user_id'])) {
            $conditionFilterData['user_id'] = $data['user_id'];
        }

        $dataToUpdate = $this->paymentCollection->find($conditionFilterData, $options)->toArray();
        if (!empty($dataToUpdate)) {
            $currentDate = date('Y-m-d H:i:s');
            $count = 0;
            foreach($dataToUpdate as $v) {
                $count++;
                $sourceId = $this->commonObj->getShopifyShopId($v['user_id']);
                $savedServiceData = [];
                $savedServiceData = [
                    '_id' => (string)$this->baseMongo->getCounter(self::PAYMENT_DETAILS_COUNTER_NAME),
                    'user_id' => (string)$v['user_id'],
                    'type' => self::PAYMENT_TYPE_USER_SERVICE,
                    'source_marketplace' => self::SOURCE_MARKETPLACE,
                    'target_marketplace' => self::TARGET_MARKETPLACE,
                    'marketplace' => self::TARGET_MARKETPLACE,
                    'service_type' => self::SERVICE_TYPE_ADDITIONAL_SERVICES,
                    'created_at' => $currentDate,
                    'updated_at' => $currentDate,
                    'activated_on' => date('Y-m-d'),
                    'expired_at' => date('Y-m-t'),
                    'supported_features' => ['metafield_support' => true],
                    'source_id' => $sourceId,
                    'app_tag' => self::APP_TAG
                ];
                if ($this->isTestUser($v['user_id'])) {
                    $savedServiceData['test_user'] = true;
                }

                $this->paymentCollection->insertOne($savedServiceData);
            }

            return [
                'success' => true,
                'message' => $count .' services added'
            ];
        }

        return [
            'success' => false,
            'message' => 'no data found!'
        ];
    }

    public function validateCoupon($couponCode, $billedType = self::BILLED_TYPE_MONTHLY)
    {
        $couponCodeEntry = $this->getPlanByQuery(['type' => 'coupon', 'code' => trim($couponCode)]);
        if (!empty($couponCodeEntry) && isset($couponCodeEntry['data']) && (count($couponCodeEntry['data']) == 1)) {
            $couponCodeEntry = $couponCodeEntry['data'][0];
            if (isset($couponCodeEntry['validity_date']) && $couponCodeEntry['validity_date'] < date('Y-m-d')) {
                return [
                    'success' => false,
                    'message' => 'This coupon is not applicable now!'
                ];
            }
            if (isset($couponCodeEntry['billing_type_supported']) && (strtolower($couponCodeEntry['billing_type_supported']) !== strtolower($billedType))) {
                return [
                    'success' => false,
                    'message' => 'This coupon is valid only for '.$couponCodeEntry['billing_type_supported'].' plan offers!'
                ];
            }
            $offer = [
                'code' => $couponCodeEntry['code'],
                'name' => $couponCodeEntry['name'],
                'type' => $couponCodeEntry['discount_type'],
                'value' => $couponCodeEntry['value'],
                'duration_interval' => $couponCodeEntry['duration_interval'],
                'billing_type_supported' => $couponCodeEntry['billing_type_supported'],
            ];
            return [
                'success' => true,
                'data' => $offer
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Your provided coupon is not valid!'
            ];
        }
    }

    public function validateAndGetCouponData($rawbody)
    {
        $planDetails = $this->getPlan($rawbody['plan_id'] ?? "");
        if ($planDetails['success'] && !empty($planDetails['data'])) {
            $planDetails = $planDetails['data'];
            if (isset($rawbody['coupon_code']) && !empty($rawbody['coupon_code'])) {
                $couponResponse = $this->validateCoupon($rawbody['coupon_code'], $planDetails['billed_type']);
                if (isset($couponResponse['success'], $couponResponse['data'])) {
                    array_push($planDetails['discounts'], $couponResponse['data']);
                    $amount = $this->commonObj->getDiscountedAmount($planDetails);
                    $couponResponse['data']['offered_price'] = $amount;
                }
                return $couponResponse;
            }
            $message = 'Coupon Code not provided!';
        } else {
            $message = 'No plan found!';
        }
        return [
            'success' => false,
            'message' => $message
        ];
    }

    public function addAdditionalUserService($data)
    {
        try {
            if (!isset($data['user_id']) || empty($data['user_id'])) {
                return [
                    'success' => false,
                    'message' => 'user_id is required!'
                ];
            }
            $conditionForExistsData = [
                'user_id' => $data['user_id'],
                'type' => self::PAYMENT_TYPE_USER_SERVICE,
                'service_type' => self::SERVICE_TYPE_ADDITIONAL_SERVICES,
            ];
            $checkData = $this->paymentCollection->findOne($conditionForExistsData, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            if (empty($checkData)) {
                $conditionFilterData = [
                    'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
                    'status' => self::USER_PLAN_STATUS_ACTIVE,
                    'user_id' => $data['user_id']
                ];
                $dataToUpdate = $this->paymentCollection->findOne($conditionFilterData, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
                if (!empty($dataToUpdate)) {
                    $currentDate = date('Y-m-d H:i:s');
                    // foreach($dataToUpdate as $v) {
                    $sourceId = $this->commonObj->getShopifyShopId($dataToUpdate['user_id']);
                    $savedServiceData = [];
                    $savedServiceData = [
                        '_id' => (string)$this->baseMongo->getCounter(self::PAYMENT_DETAILS_COUNTER_NAME),
                        'user_id' => (string)$dataToUpdate['user_id'],
                        'type' => self::PAYMENT_TYPE_USER_SERVICE,
                        'source_marketplace' => self::SOURCE_MARKETPLACE,
                        'target_marketplace' => self::TARGET_MARKETPLACE,
                        'marketplace' => self::TARGET_MARKETPLACE,
                        'service_type' => self::SERVICE_TYPE_ADDITIONAL_SERVICES,
                        'created_at' => $currentDate,
                        'updated_at' => $currentDate,
                        'activated_on' => date('Y-m-d'),
                        'expired_at' => date('Y-m-t'),
                        'supported_features' => ['metafield_support' => true],
                        'source_id' => $sourceId,
                        'app_tag' => self::APP_TAG
                    ];
                    if ($this->isTestUser($dataToUpdate['user_id'])) {
                        $savedServiceData['test_user'] = true;
                    }
                    $this->paymentCollection->insertOne($savedServiceData);
                    // }
                    return [
                        'success' => true,
                        'message' =>  'service added for user'
                    ];
                }
                return [
                    'success' => false,
                    'message' => 'no active plan data found!'
                ];
            } else {
                if (isset($checkData['supported_features']) && ($checkData['supported_features']['metafield_support'] == true)) {
                    $message = 'Metafield support already activated for this user!';
                } else {
                    $this->paymentCollection->updateOne(
                        $conditionForExistsData,
                        ['$set' => ['supported_features.metafield_support' => true, 'updated_at' => date('Y-m-d H:i:s')]]
                    );
                    $message = 'Metafield support activated for this user!';
                }
                return [
                    'success' => true,
                    'message' => $message
                ];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('addAdditionalUserService(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }

    public function increaseOrderLimit($rawBody, $method = 'GET')
    {
        if (!isset($rawBody['user_id'])) {
            return [
                'success' => false,
                'message' => 'user_id/service_credits/available_credits/available_credits required!'
            ];
        }
        $orderSyncService = $this->getCurrentUserServices($rawBody['user_id'], self::SERVICE_TYPE_ORDER_SYNC);
        if (empty($orderSyncService)) {
            return [
                'success' => false,
                'message' => 'user_service not found!'
            ];
        } else {
            if ($method == 'GET') {
                return $orderSyncService;
            } elseif ($method == 'POST') {
                $settlementInvoice = $this->getPendingSettlementInvoice($rawBody['user_id']);
                if (!empty($settlementInvoice)) {
                    return [
                        'success' => false,
                        'message' => 'please clear/waive off settlement first!'
                    ];
                }
                if (!isset($rawBody['service_credits'], $rawBody['available_credits'], $rawBody['total_used_credits'])) {
                    return [
                        'success' => false,
                        'message' => 'service_credits/available_credits/available_credits required!'
                    ];
                }

                $orderSyncService['prepaid']['service_credits'] = (int)$rawBody['service_credits'];
                $orderSyncService['prepaid']['available_credits'] = (int)$rawBody['available_credits'];
                $orderSyncService['prepaid']['total_used_credits'] = (int)$rawBody['total_used_credits'];
                $orderSyncService['updated_at'] = date('c');
                $this->paymentCollection->replaceOne(
                    ['_id' => $orderSyncService['_id']],
                    $orderSyncService,
                    ['upsert' => true]
                );
                return [
                    'success' => true,
                    'message' => 'Updated successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'invalid method!'
                ];
            }
        }
    }

    /**
     * to get trial days
     */
    public function getTrialDays($planDetails, $activePlan)
    {
        $trialDays = 0;
        if (isset($planDetails['trial_days'])) {
            if (isset($planDetails['payment_type']) && ($planDetails['payment_type'] == self::BILLING_TYPE_ONETIME)) {
                return 0;
            }
            if ($this->isFreePlan($planDetails) || $this->isTrialPlan($planDetails)) {
                return $trialDays;
            } else {
                if (empty($activePlan)) {
                    $trialDaysEntry = $this->paymentCollection->findOne([
                        'user_id' => $this->di->getUser()->id,
                        'type' =>'trial_days'
                    ], self::TYPEMAP_OPTIONS);
                    if (!empty($trialDaysEntry) && (!empty($trialDaysEntry['last_plan_details']))) {
                        if (($trialDaysEntry['last_plan_details']['plan_id'] == $planDetails['plan_id'])
                            && ($trialDaysEntry['last_plan_details']['title'] == $planDetails['title'])
                        && ($trialDaysEntry['last_plan_details']['custom_price'] == $planDetails['custom_price'])) {
                            return (int)$trialDaysEntry['trial_days_left'] ?? 0;
                        } else {
                            return 0;
                        }
                    }
                    $hasPreviousPlan = $this->paymentCollection->countDocuments(['user_id' => $this->di->getUser()->id,'type' => 'active_plan', 'status' => 'inactive']);
                    if ($hasPreviousPlan > 0) {
                        return 0;
                    }
                    $trialDays = (int)$planDetails['trial_days'];
                } else {
                    return 0;
                }
            }
            ($trialDays < 0) && $trialDays = 0;
            return $trialDays;
        }
    }

    public function canWaiveOffTheSettlement($settlementInvoice)
    {
        //we need to check if plan is active, plan is recurring and plan's amount is higher than the capped amount or the user service not renewed till from many rotations causing higher amount than the amount
        $paymentData = $this->getRemoteChargeData($settlementInvoice['user_id']);
        if (!empty($paymentData) && isset($paymentData['id'])) {
            if (isset($paymentData['status']) && ($paymentData['status'] == ShopifyPayment::STATUS_ACTIVE)) {
                $createdAt = $paymentData['created_at'] ?? null;
                if (!is_null($createdAt)) {
                    $createdAt = new DateTime($createdAt);
                    $settlementGenerationDate = new DateTime($settlementInvoice['created_at']);
                    $currentDate = new DateTime(); // gets the current date and time
    
                    $interval = $currentDate->diff($settlementGenerationDate);
                    $recurringActiveDays = $currentDate->diff($createdAt);
                    $planActiveDays =  $recurringActiveDays->days;
                    $settlementPendingDays = $interval->days;
                    $cycles = floor($settlementPendingDays / 30);
                    if (($planActiveDays > $settlementPendingDays) && ($cycles > 0) && (($cycles * (float)$paymentData['price']) >= $settlementInvoice['settlement_amount'])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function waiveOffSettlement($settlementInvoice)
    {
        $this->paymentCollection->updateOne(
            [
                'user_id' => $settlementInvoice['user_id'],
                'type' => Plan::SETTLEMENT_INVOICE,
                'status' => Plan::PAYMENT_STATUS_PENDING,
                'is_capped' => ['$exists' => false]
            ],
            [
                '$set' => ['status' => 'waived_off', 'updated_at' => date('Y-m-d H:i:s')]
            ]
        );
        $resetLimitFilterData = [
            'user_id' => $settlementInvoice['user_id'],
            'type' => Plan::PAYMENT_TYPE_USER_SERVICE,
            'service_type' => Plan::SERVICE_TYPE_ORDER_SYNC,
            'postpaid.is_capped' => ['$exists' => false],
            '$expr' => [
                '$gt' => [
                    '$postpaid.total_used_credits', 0
                ]
            ]
        ];
        $this->paymentCollection->updateOne($resetLimitFilterData, [
            '$set' => [
                'postpaid.available_credits' => 0,
                'postpaid.total_used_credits' => 0
            ]
        ]);
        return true;
    }

    public function getPreparedServiceData($userId, $serviceType, $requiredData, $quoteService)
    {
        $currentDate = date('Y-m-d H:i:s');
        $serviceData = [
            '_id' => (string)$this->baseMongo->getCounter(self::PAYMENT_DETAILS_COUNTER_NAME),
            'user_id' => (string)$userId,
            'type' => self::PAYMENT_TYPE_USER_SERVICE,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            'marketplace' => self::TARGET_MARKETPLACE,
            'service_type' => $serviceType,
            'created_at' => $currentDate,
            'updated_at' => $currentDate,
            'activated_on' => date('Y-m-d'),
            'expired_at' => date('Y-m-t'),
            'source_id' => $requiredData['sourceId'],
            'app_tag' => $requiredData['appTag']
        ];
        if ($serviceType == self::SERVICE_TYPE_ADDITIONAL_SERVICES) {
            $serviceData['supported_features'] = $quoteService['supported_features'] ?? [];
        } else {
            $serviceData['prepaid'] = [
                'service_credits' => (int)($quoteService['prepaid']['service_credits'] ?? 0),
                'available_credits' => (int)($quoteService['prepaid']['service_credits'] ?? 0),
                'total_used_credits' => 0
            ];
            $serviceData['postpaid'] = [
                "per_unit_usage_price" => (float)($quoteService['postpaid']['per_unit_usage_price'] ?? 0),
                "unit_qty" => (int)($quoteService['postpaid']['unit_qty'] ?? 0),
                "capped_credit" => (int)($quoteService['postpaid']['capped_credit'] ?? 0),
                'available_credits' => (int)($quoteService['postpaid']['capped_credit'] ?? 0),
                'total_used_credits' => 0
            ];
        }
        isset($quoteService['active']) && $serviceData['active'] = $quoteService['active'];
        if ($this->isTestUser($userId)) {
            $serviceData['test_user'] = true;
        }
        return $serviceData;
    }



    /**
     * Unified order limit management function that validates basic requirements and routes to appropriate handler
     * 
     * @param array $rawBody
     * @param string $username
     * @return array
     */
    public function manageOrderLimitUnified($rawBody, $username)
    {
        // Validate required fields based on action type
        if (empty($rawBody['user_id']) || empty($rawBody['action_type'])) {
            return [
                'success' => false,
                'message' => 'user_id and action_type are required!'
            ];
        }

        $userId = $rawBody['user_id'];
        $actionType = $rawBody['action_type'];

        // Validate action type specific requirements
        switch ($actionType) {
            case 'monthly':
                if (empty($rawBody['increase_credit'])) {
                    return [
                        'success' => false,
                        'message' => 'increase_credit is required for this action!'
                    ];
                }
                break;
            case 'dateRange':
                if (empty($rawBody['increase_credit'])) {
                    return [
                        'success' => false,
                        'message' => 'increase_credit is required for this action!'
                    ];
                }
                if (empty($rawBody['end_date'])) {
                    return [
                        'success' => false,
                        'message' => 'end_date is required for this action!'
                    ];
                }
                break;
            case 'freeze':
                if (empty($rawBody['freeze_reset'])) {
                    return [
                        'success' => false,
                        'message' => 'freeze_reset is required for this action!'
                    ];
                }
                if (empty($rawBody['service_credits'])) {
                    return [
                        'success' => false,
                        'message' => 'service_credits is required for this action!'
                    ];
                }
                break;
            default:
                return [
                    'success' => false,
                    'message' => 'Invalid action_type. Allowed values: monthly, dateRange, freeze'
                ];
        }

        try {
            // Step 1: Check active plan
            $activePlan = [
                'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
                'status' => self::USER_PLAN_STATUS_ACTIVE,
                'user_id' => $userId
            ];
            $activePlanExists = $this->paymentCollection->findOne($activePlan, [
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ]);

            if (empty($activePlanExists)) {
                return [
                    'success' => false,
                    'message' => 'No active plan found for this user.'
                ];
            }
            if ($this->isFreePlan($activePlanExists['plan_details']) || $this->isTrialPlan($activePlanExists['plan_details'])) {
                return [
                    'success' => false,
                    'message' => 'User is on Free plan. Please upgrade the plan to avail this service.'
                ];
            }

            // Step 2: Check settlement
            $pendingSettlement = $this->getPendingSettlementInvoice($userId);
            if (!empty($pendingSettlement) && !$pendingSettlement['is_capped']) {
                return [
                    'success' => false,
                    'message' => 'Please waive off the pending settlement first.'
                ];
            }
            // Step 3: Fetch Order Sync Service
            $orderSyncFilter = [
                'user_id' => $userId,
                'type' => self::PAYMENT_TYPE_USER_SERVICE,
                'service_type' => self::SERVICE_TYPE_ORDER_SYNC,
            ];

            $OrderSyncService = $this->paymentCollection->findOne($orderSyncFilter, [
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ]);

            if (empty($OrderSyncService)) {
                return [
                    'success' => false,
                    'message' => 'No Order Sync service found for this user.'
                ];
            }

            // Route to appropriate handler based on action type
            switch ($actionType) {
                case 'monthly':
                    return $this->processMonthlyOrderLimitIncrease($rawBody, $username, $activePlanExists, $OrderSyncService, $orderSyncFilter);
                case 'dateRange':
                    return $this->processDateRangeOrderLimitIncrease($rawBody, $username, $activePlanExists, $OrderSyncService, $orderSyncFilter);
                case 'freeze':
                    return $this->processRecurringOrderResetFreeze($rawBody, $username, $activePlanExists, $OrderSyncService, $orderSyncFilter);
                default:
                    return [
                        'success' => false,
                        'message' => 'Invalid action type.'
                    ];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('manageOrderLimitUnified(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
            return [
                'success' => false,
                'message' => 'An error occurred while processing the request.'
            ];
        }
    }

    /**
     * Process monthly order limit increase
     * 
     * @param array $rawBody
     * @param string $username
     * @param array $activePlanExists
     * @param array $OrderSyncService
     * @param array $orderSyncFilter
     * @return array
     */
    private function processMonthlyOrderLimitIncrease($rawBody, $username, $activePlanExists, $OrderSyncService, $orderSyncFilter)
    {
        $userId = $rawBody['user_id'];
        $increaseCredit = (int)$rawBody['increase_credit'];

        // Update Order Sync Service
        $availableCredits = (int)$OrderSyncService['prepaid']['available_credits'];
        $serviceCredits = (int)$OrderSyncService['prepaid']['service_credits'];
        $usedCredits = (int)$OrderSyncService['prepaid']['total_used_credits'];

        $newServiceCredits = $serviceCredits + $increaseCredit;
        $newAvailableCredits = $newServiceCredits - $usedCredits;


        $this->paymentCollection->updateOne(
            $orderSyncFilter,
            [
                '$set' => [
                    'prepaid.available_credits' => $newAvailableCredits,
                    'prepaid.service_credits' => $newServiceCredits,
                ]
            ]
        );

        // Handle pricing activity
        $this->pricingActivity = $this->baseMongo->getCollectionForTable('pricing_plan_activity');

        $existingActivity = $this->pricingActivity->findOne([
            'user_id' => $userId,
            'activity_type' => 'increase_order_limit_monthly',
        ]);

        $currentDateTime = date('Y-m-d H:i:s');
        $activityData = [
            'user_id' => $userId,
            'username' => $username,
            'activity_type' => 'increase_order_limit_monthly',
            'increase_credits' => $increaseCredit,
            'old_service_credits' => $serviceCredits,
            'old_available_credits' => $availableCredits,
            'new_service_credits' => $newServiceCredits,
            'new_available_credits' => $newAvailableCredits,
            'is_monthly_increase' => true,
            'created_at' => $currentDateTime,
            'updated_at' => $currentDateTime
        ];

        if ($existingActivity) {
            $transactionHistory = $existingActivity['transaction_history'] ?? [];

            // Add new entry to transaction history with essential fields including service credits
            $transactionHistory[] = [
                'username' => $existingActivity['username'] ?? $username,
                'old_service_credits' => $existingActivity['old_service_credits'] ?? $serviceCredits,
                'new_service_credits' => $existingActivity['new_service_credits'] ?? $serviceCredits,
                'created_at' => $existingActivity['created_at'] ?? $existingActivity['updated_at']
            ];

            $activityData['transaction_history'] = $transactionHistory;

            $this->pricingActivity->updateOne(
                ['_id' => $existingActivity['_id']],
                ['$set' => $activityData]
            );
        } else {
            $this->pricingActivity->insertOne($activityData);
        }

        // Update DynamoDB entries
        $userDetails = $this->commonObj->getUserDetail($userId);
        if (!empty($userDetails) && isset($userDetails['shops'])) {
            foreach ($userDetails['shops'] as $shop) {
                if (isset($shop['marketplace']) && ($shop['marketplace'] == 'amazon')) {
                    $this->updateEntryInDynamo($shop['remote_shop_id'], 0);
                }
            }
        }

        return [
            'success' => true,
            'message' => 'Order limit increased successfully for one month!',
            'data' => [
                'previous_credits' => $serviceCredits,
                'increased_credits' => $increaseCredit,
                'new_total_credits' => $newServiceCredits,
            ]
        ];
    }

    /**
     * Process date range order limit increase
     * 
     * @param array $rawBody
     * @param string $username
     * @param array $activePlanExists
     * @param array $OrderSyncService
     * @param array $orderSyncFilter
     * @return array
     */
    private function processDateRangeOrderLimitIncrease($rawBody, $username, $activePlanExists, $OrderSyncService, $orderSyncFilter)
    {
        $userId = $rawBody['user_id'];
        $increaseCredit = (int)$rawBody['increase_credit'];
        $endDate = $rawBody['end_date'];
        // Validate end date format only
        if (strtotime($endDate) === false) {
            return [
                'success' => false,
                'message' => 'Invalid end_date format. Please use Y-m-d format.'
            ];
        }

        // Check if recurring order reset freeze is active for this user
        $this->pricingActivity = $this->baseMongo->getCollectionForTable('pricing_plan_activity');
        $existingFreezeActivity = $this->pricingActivity->findOne([
            'user_id' => $userId,
            'is_active' => true,
            'activity_type' => 'freeze_recurring_order_reset'
        ]);

        if ($existingFreezeActivity) {
            return [
                'success' => false,
                'message' => 'Cannot increase order limit for date range. Recurring order reset is currently frozen for this user. Please unfreeze first.'
            ];
        }

        // Get current service credits using getServiceCredits function
        $servicesClass = $this->di->getObjectManager()->get(\App\Plan\Models\Plan\Services::class);
        $currentServiceCredits = $servicesClass->getServiceCredits($activePlanExists['plan_details']);

        // Calculate new totals
        $totalServiceCredits = $currentServiceCredits + $increaseCredit;

        // Update Order Sync Service
        $availableCredits = (int)$OrderSyncService['prepaid']['available_credits'];
        $serviceCredits = (int)$OrderSyncService['prepaid']['service_credits'];
        $usedCredits = (int)$OrderSyncService['prepaid']['total_used_credits'];

        $newServiceCredits = $serviceCredits + $increaseCredit;
        $newAvailableCredits = $newServiceCredits - $usedCredits;

        $this->paymentCollection->updateOne(
            $orderSyncFilter,
            [
                '$set' => [
                    'activity_type' => 'increase_order_limit_date_range',
                    'old_service_credits' => $currentServiceCredits,
                    'prepaid.available_credits' => $newAvailableCredits,
                    'prepaid.service_credits' => $newServiceCredits,
                    'reset_date'=>$endDate,
                ]
            ]
        );

        // Update active plan with new service credits
        $statusUpdateFilterData = [
            'user_id' => (string)$userId,
            'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
            'status' => self::USER_PLAN_STATUS_ACTIVE,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            'marketplace' => self::TARGET_MARKETPLACE,
        ];

        if (isset($activePlanExists['plan_details']['services_groups'])) {
            $servicesGroups = $activePlanExists['plan_details']['services_groups'];
            foreach ($servicesGroups as $key => $group) {
                if (isset($group['title']) && $group['title'] === 'Order Management') {
                    if (isset($group['services'])) {
                        foreach ($group['services'] as $serviceKey => $service) {
                            if (isset($service['type']) && $service['type'] === 'order_sync') {
                                $servicesGroups[$key]['services'][$serviceKey]['prepaid']['service_credits'] = $totalServiceCredits;
                            }
                        }
                    }
                    break;
                }
            }

            $this->paymentCollection->updateOne(
                $statusUpdateFilterData,
                [
                    '$set' => [
                        'plan_details.services_groups' => $servicesGroups,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ]
            );
        }

        // Handle pricing activity
        $this->pricingActivity = $this->baseMongo->getCollectionForTable('pricing_plan_activity');

        $currentDateTime = date('Y-m-d H:i:s');
        $activityData = [
            'user_id' => $userId,
            'username' => $username,
            'is_active' => true,
            'activity_type' => 'increase_order_limit_date_range',
            'increase_credits' => $increaseCredit,
            'old_service_credits' => $currentServiceCredits,
            'old_available_credits' => $availableCredits,
            'new_service_credits' => $totalServiceCredits,
            'new_available_credits' => $newAvailableCredits,
            'expired_at' => $endDate,
            'created_at' => $currentDateTime,
            'updated_at' => $currentDateTime
        ];

        $existingActivity = $this->pricingActivity->findOne([
            'user_id' => $userId,
            'activity_type' => 'increase_order_limit_date_range',
        ]);

        if ($existingActivity) {
            $transactionHistory = $existingActivity['transaction_history'] ?? [];

            // Add new entry to transaction history with essential fields including service credits
            $transactionHistory[] = [
                'username' => $existingActivity['username'] ?? $username,
                'old_service_credits' => $existingActivity['old_service_credits'] ?? $existingActivity['new_service_credits'] ?? $currentServiceCredits,
                'new_service_credits' => $existingActivity['new_service_credits'] ?? $currentServiceCredits,
                'created_at' => $existingActivity['updated_at'] ?? $existingActivity['created_at']
            ];

            $activityData['transaction_history'] = $transactionHistory;

            $this->pricingActivity->updateOne(
                ['_id' => $existingActivity['_id']],
                ['$set' => $activityData]
            );
                } else {
            $this->pricingActivity->insertOne($activityData);
        }

        // Update DynamoDB entries
        $userDetails = $this->commonObj->getUserDetail($userId);
        if (!empty($userDetails) && isset($userDetails['shops'])) {
            foreach ($userDetails['shops'] as $shop) {
                if (isset($shop['marketplace']) && ($shop['marketplace'] == 'amazon')) {
                    $this->updateEntryInDynamo($shop['remote_shop_id'], 0);
                }
            }
        }

        return [
            'success' => true,
            'message' => 'Order limit increased successfully for the specified date range!',
            'data' => [
                'previous_credits' => $currentServiceCredits,
                'increased_credits' => $increaseCredit,
                'new_total_credits' => $totalServiceCredits,
                'end_date' => $endDate
            ]
        ];
    }

    /**
     * Process recurring order reset freeze
     * 
     * @param array $rawBody
     * @param string $username
     * @param array $activePlanExists
     * @param array $OrderSyncService
     * @param array $orderSyncFilter
     * @return array
     */
    private function processRecurringOrderResetFreeze($rawBody, $username, $activePlanExists, $OrderSyncService, $orderSyncFilter)
    {
        $userId = $rawBody['user_id'];
        $freezeReset = (int)$rawBody['freeze_reset']; // Number of months
        $newServiceCredits = (int)$rawBody['service_credits']; // Service credits from frontend

        // Validate freeze_reset (should be between 2-12 months)
        if ($freezeReset < 2 || $freezeReset > 12) {
            return [
                'success' => false,
                'message' => 'Invalid freeze_reset. Must be between 2 and 12 months.'
            ];
        }

        // Get plan credits using getServiceCredits function
        $servicesClass = $this->di->getObjectManager()->get(\App\Plan\Models\Plan\Services::class);
        $planCredits = $servicesClass->getServiceCredits($activePlanExists['plan_details']);

        // Validate service_credits >= planCredits
        if ($newServiceCredits < $planCredits) {
            return [
                'success' => false,
                'message' => "Service credits ({$newServiceCredits}) must be greater than or equal to plan credits ({$planCredits})."
            ];
        }

        // Calculate freeze_until_date and expired_At based on freeze_reset months
        $currentDate = date('Y-m-d H:i:s');
        $expiredAt = date('Y-m-d H:i:s', strtotime("+{$freezeReset} months"));

        // Check if date range order limit increase is active for this user
        $this->pricingActivity = $this->baseMongo->getCollectionForTable('pricing_plan_activity');
        $existingDateRangeActivity = $this->pricingActivity->findOne([
            'user_id' => $userId,
            'is_active' => true,
            'activity_type' => 'increase_order_limit_date_range'
        ]);

        if ($existingDateRangeActivity) {
            return [
                'success' => false,
                'message' => 'Cannot freeze recurring order reset. Order limit increase for date range is currently active for this user. Please wait for it to complete first.'
            ];
        }

        // Get current service credits and calculate new available credits
        $currentServiceCredits = (int)$OrderSyncService['prepaid']['service_credits'];
        $usedCredits = (int)$OrderSyncService['prepaid']['total_used_credits'];
        $newAvailableCredits = $newServiceCredits - $usedCredits;

        // Update Order Sync Service with freeze information and new service credits
        $updateData = [
            'activity_type'=>'freeze_recurring_order_reset',
            'freeze_reset' => $freezeReset,
            'old_service_credits' => $planCredits,
            'prepaid.service_credits' => $newServiceCredits,
            'prepaid.available_credits' => $newAvailableCredits,
            'expired_at' => $expiredAt,
            'updated_at' => $currentDate
        ];

        $this->paymentCollection->updateOne(
            $orderSyncFilter,
            ['$set' => $updateData]
        );

        // Update active plan with service_credits like in increaseOrderLimitForDateRange
        $statusUpdateFilterData = [
            'user_id' => (string)$userId,
            'type' => self::PAYMENT_TYPE_ACTIVE_PLAN,
            'status' => self::USER_PLAN_STATUS_ACTIVE,
            'source_marketplace' => self::SOURCE_MARKETPLACE,
            'target_marketplace' => self::TARGET_MARKETPLACE,
            'marketplace' => self::TARGET_MARKETPLACE,
        ];

        if (isset($activePlanExists['plan_details']['services_groups'])) {
            $servicesGroups = $activePlanExists['plan_details']['services_groups'];
            foreach ($servicesGroups as $key => $group) {
                if (isset($group['title']) && $group['title'] === 'Order Management') {
                    if (isset($group['services'])) {
                        foreach ($group['services'] as $serviceKey => $service) {
                            if (isset($service['type']) && $service['type'] === 'order_sync') {
                                $servicesGroups[$key]['services'][$serviceKey]['prepaid']['service_credits'] = $newServiceCredits;
                            }
                        }
                    }
                    break;
                }
            }

            $planUpdateData = [
                'plan_details.services_groups' => $servicesGroups,
                'updated_at' => $currentDate
            ];

            $this->paymentCollection->updateOne(
                $statusUpdateFilterData,
                ['$set' => $planUpdateData]
            );
        }

        // Handle pricing activity
        $this->pricingActivity = $this->baseMongo->getCollectionForTable('pricing_plan_activity');

        $activityData = [
            'user_id' => $userId,
            'username' => $username,
            'is_active' => true,
            'activity_type' => 'freeze_recurring_order_reset',
            'freeze_reset' => $freezeReset,
            'expired_at' => $expiredAt,
            'old_service_credits' => $planCredits,
            'new_service_credits' => $newServiceCredits,
            'new_available_credits' => $newAvailableCredits,
            'created_at' => $currentDate,
            'updated_at' => $currentDate
        ];


        // Check if freeze activity already exists
        $existingActivity = $this->pricingActivity->findOne([
            'user_id' => $userId,
            'activity_type' => 'freeze_recurring_order_reset'
        ]);

        if ($existingActivity) {
            $transactionHistory = $existingActivity['transaction_history'] ?? [];

            // Add new entry to transaction history with essential fields including service credits
            $transactionHistory[] = [
                'username' => $existingActivity['username'] ?? $username,
                'old_service_credits' => $existingActivity['old_service_credits'] ?? $existingActivity['new_service_credits'] ?? $currentServiceCredits,
                'new_service_credits' => $existingActivity['new_service_credits'] ?? $currentServiceCredits,
                'created_at' => $existingActivity['updated_at'] ?? $existingActivity['created_at']
            ];

            $activityData['transaction_history'] = $transactionHistory;

            $this->pricingActivity->updateOne(
                ['_id' => $existingActivity['_id']],
                ['$set' => $activityData]
            );
        } else {
            $this->pricingActivity->insertOne($activityData);
        }

        // Update DynamoDB entries
        $userDetails = $this->commonObj->getUserDetail($userId);
        if (!empty($userDetails) && isset($userDetails['shops'])) {
            foreach ($userDetails['shops'] as $shop) {
                if (isset($shop['marketplace']) && ($shop['marketplace'] == 'amazon')) {
                    $this->updateEntryInDynamo($shop['remote_shop_id'], 0);
                }
            }
        }

        return [
            'success' => true,
            'message' => 'Recurring order reset frozen successfully for ' . $freezeReset . ' month(s) until ' . $expiredAt . ' and service credits updated to ' . $planCredits . '!',
            'data' => [
                'freeze_reset' => $freezeReset,
                'expired_at' => $expiredAt,
                'old_service_credits' => $planCredits,
                'new_service_credits' => $newServiceCredits,
                'new_available_credits' => $newAvailableCredits
            ]
        ];
    }

    public function setPlanTier($newPlanDetails, $oldPlanDetails = [], $shopData = [])
    {
        try {
            $logFile = "plan/setPlanTier/" . date('d-m-Y') . '.log';
            $planTier = 'default';
            $planUpgradeOrDowngrade = false;
            $appReinstalled = false;
            $userDetailsCollection = $this->baseMongo->getCollection('user_details');
            if (empty($oldPlanDetails)) {
                if ($newPlanDetails['custom_price'] > 0) {
                    $planTier = 'paid';
                    if (!empty($shopData['apps'][0]['reinstalled'])) {
                        $appReinstalled = true;
                        $this->di->getLog()->logContent('App reinstalled shopData: ' . json_encode($shopData), 'info',  $logFile);
                    }
                }
            } else {
                // Condition to check if plan is upgraded or downgraded to free to paid or paid to free
                if ($oldPlanDetails['custom_price'] == 0 && $newPlanDetails['custom_price'] > 0) {
                    $planTier = 'paid';
                    $planUpgradeOrDowngrade = true;
                    // Check is just for few months for script purpose 
                    $userDetailsCollection->updateOne(
                        [
                            'user_id' => $this->di->getUser()->id
                        ],
                        ['$unset' => ['auto_disconnect_account_after' => 1, 'auto_remove_exta_templates_after' => 1]]
                    );
                } elseif ($oldPlanDetails['custom_price'] > 0 && $newPlanDetails['custom_price'] == 0) {
                    $planTier = 'default';
                    $planUpgradeOrDowngrade = true;
                } else {
                    return ['success' => false, 'message' => 'Plan tier not changed as not downgraded or upgraded to free to paid or paid to free'];
                }
            }

            $userDetailsCollection->updateOne(
                [
                    'user_id' => $this->di->getUser()->id
                ],
                ['$set' => ['plan_tier' => $planTier]],
                ['upsert' => true]
            );

            if ($appReinstalled || $planUpgradeOrDowngrade) {
                $sourceMarketplace = $newPlanDetails['source_marketplace'] ?? $this->di->getRequester()->getSourceMarketplace();
                $path = '\Components\Shop\Shop';
                $method = 'upgradeOrDowngradePlanTier';
                $class = $this->checkClassAndMethodExists($sourceMarketplace, $path, $method);
                if (!empty($class)) {
                    $functionPayload = [
                        'user_id' => $this->di->getUser()->id,
                        'plan_tier' => $planTier,
                        'old_plan_details' => $oldPlanDetails,
                        'new_plan_details' => $newPlanDetails
                    ];
                    $this->di->getLog()->logContent('Initiating upgradeOrDowngradePlanTier for: ' . json_encode($functionPayload), 'info',  $logFile);

                    $this->di->getObjectManager()->get($class)->$method($functionPayload);
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from setPlanTier(): ' . json_encode($e->getMessage()), 'info',  $logFile ?? 'exception.log');
            throw $e;
        }
    }


    public function checkClassAndMethodExists($marketplace, $path, $method)
    {
        $moduleHome = ucfirst($marketplace);
        $baseClass = '\App\\' . $moduleHome . $path;
        $altClass = '\App\\' . $moduleHome . 'home' . $path;
        if (class_exists($baseClass) && method_exists($baseClass, $method)) {
            return $baseClass;
        }
        if (class_exists($altClass) && method_exists($altClass, $method)) {
            return $altClass;
        }

        return null;
    }

    private function resetMinCreatedAtIfStale($orderFilter, $pastDays)
    {
        $decodedFilter = json_decode($orderFilter['S'], true);
        if (!empty($decodedFilter['min_created_at'])) {
            $pastDate = "-$pastDays days";
            if (date('Y-m-d\TH:i:s\Z', strtotime($pastDate)) > $decodedFilter['min_created_at']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reset Custom Order Services
     * Processes pricing_plan_activity documents where is_active=true and activity_type 
     * is either 'freeze_recurring_order_reset' or 'increase_order_limit_date_range'
     *
     * @return array
     */
    public function resetCustomOrderServices()
    {
        try {
            $logFile = 'plan/resetCustomOrderServices/' . date('Y-m-d') . '.log';
            $this->commonObj->addLog('Starting resetCustomOrderServices process', $logFile);

            // Get the pricing_plan_activity collection
            $pricingActivityCollection = $this->baseMongo->getCollectionForTable('pricing_plan_activity');

            // Query filter to find active documents with specific activity types and expired date
            $currentDate = date('Y-m-d');
            $filter = [
                'is_active' => true,
                'activity_type' => [
                    '$in' => ['freeze_recurring_order_reset', 'increase_order_limit_date_range']
                ],
                'expired_at' => [
                    '$lte' => $currentDate
                ]
            ];

            // Find all matching documents
            $activities = $pricingActivityCollection->find($filter, self::TYPEMAP_OPTIONS)->toArray();

            if (empty($activities)) {
                $this->commonObj->addLog('No active pricing activities found to process', $logFile);
                return [
                    'success' => true,
                    'message' => 'No active pricing activities found to process.',
                ];
            }

            // Process each document one by one
            foreach ($activities as $activity) {
                try {
                    // First set DI for the user (similar to line 3886)
                    if (isset($activity['user_id'])) {
                        $diRes = $this->commonObj->setDiForUser($activity['user_id']);
                        if (!$diRes['success']) {
                            $this->commonObj->addLog('DI not set for ' . $activity['user_id'], $logFile);
                            continue; // Skip this document and continue with the next one
                        }
                    } else {
                        continue;
                    }

                    // Set source_id if empty
                    if (empty($orderSyncService['source_id'])) {
                        $orderSyncService['source_id'] = $this->commonObj->getShopifyShopId();
                        if (!$orderSyncService['source_id']) {
                            $this->commonObj->addLog('Unable to find source_id for user ' . $activity['user_id'], $logFile);
                            continue;
                        }
                    }

                    $this->di->getRequester()->setSource(
                        [
                            'source_id' => $orderSyncService['source_id'],
                            'source_name' => ''
                        ]
                    );

                    // Find Order Sync service for the user
                    $orderSyncFilter = [
                        'user_id' => $activity['user_id'],
                        'type' => self::PAYMENT_TYPE_USER_SERVICE,
                        'service_type' => self::SERVICE_TYPE_ORDER_SYNC,
                    ];

                    $orderSyncService = $this->paymentCollection->findOne($orderSyncFilter, [
                        'typeMap' => ['root' => 'array', 'document' => 'array']
                    ]);

                    if (empty($orderSyncService)) {
                        $errorMsg = 'No Order Sync service found for user ' . $activity['user_id'];
                        $this->commonObj->addLog($errorMsg, $logFile);
                        continue;
                    }

                    // Call processRenewPlans for the orderSyncService
                    $this->commonObj->addLog('Calling processRenewPlans for user: ' . $activity['user_id'], $logFile);

                    $this->processRenewPlans($orderSyncService);
                } catch (Exception $e) {
                    $errorMsg = 'Error processing activity ' . $activity['_id'] . ': ' . $e->getMessage();
                    $this->commonObj->addLog($errorMsg, $logFile);
                    throw $e;
                }
            }

            return [
                'success' => true,
                'message' => 'resetCustomOrderServices completed successfully.',
            ];
        } catch (Exception $exception) {
            $this->commonObj->addLog('Error in resetCustomOrderServices: ' . $exception->getMessage(), $logFile);
            return [
                'success' => false,
                'message' => 'Error in resetCustomOrderServices: ' . $exception->getMessage()
            ];
        }
    }
}
