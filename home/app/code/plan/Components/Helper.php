<?php

namespace App\Plan\Components;

use App\Core\Components\Base;
use App\Plan\Models\Plan;
use App\Plan\Models\BaseMongo as BaseMongo;
use App\Plan\Components\Common;
use App\Plan\Models\Settlement;
use App\Plan\Models\Plan\Services;

/**
 * class Helper for helper functions in plan module
 */
class Helper extends Base
{
    public static $defaultPagination = 20;

    public const IS_EQUAL_TO = 1;

    public const IS_NOT_EQUAL_TO = 2;

    public const IS_CONTAINS = 3;

    public const IS_NOT_CONTAINS = 4;

    public const START_FROM = 5;

    public const END_FROM = 6;

    public const RANGE = 7;

    public const IS_GREATER_THAN = 8;

    public const IS_LESS_THAN = 9;

    public const IS_EXISTS = 10;

    public const CONTAIN_IN_ARRAY = 11;

    public static function search($filterParams = [])
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim((string) $key);
                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    $conditions[$key] = self::checkInteger($key, $value[self::IS_EQUAL_TO]);
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[$key] =  [
                        '$ne' => self::checkInteger($key, trim(addslashes((string) $value[self::IS_NOT_EQUAL_TO])))
                    ];
                } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' =>  self::checkInteger($key, trim(addslashes((string) $value[self::IS_CONTAINS]))),
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^((?!" . self::checkInteger($key, trim(addslashes((string) $value[self::IS_NOT_CONTAINS]))) . ").)*$",
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::START_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^" . self::checkInteger($key, trim(addslashes((string) $value[self::START_FROM]))),
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::END_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => self::checkInteger($key, trim(addslashes((string) $value[self::END_FROM]))) . "$",
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::RANGE, $value)) {
                    if (trim((string) $value[self::RANGE]['from']) && !trim((string) $value[self::RANGE]['to'])) {
                        $conditions[$key] =  ['$gte' => self::checkInteger($key, trim((string) $value[self::RANGE]['from']))];
                    } elseif (
                        trim((string) $value[self::RANGE]['to']) &&
                        !trim((string) $value[self::RANGE]['from'])
                    ) {
                        $conditions[$key] =  ['$lte' => self::checkInteger($key, trim((string) $value[self::RANGE]['to']))];
                    } else {
                        $conditions[$key] =  [
                            '$gte' => self::checkInteger($key, trim((string) $value[self::RANGE]['from'])),
                            '$lte' => self::checkInteger($key, trim((string) $value[self::RANGE]['to']))
                        ];
                    }
                } elseif (array_key_exists(self::IS_GREATER_THAN, $value)) {
                    $conditions[$key] = ['$gte' => self::checkInteger($key, trim((string) $value[self::IS_GREATER_THAN]))];
                } elseif (array_key_exists(self::IS_LESS_THAN, $value)) {
                    $conditions[$key] = ['$lte' => self::checkInteger($key, trim((string) $value[self::IS_LESS_THAN]))];
                } elseif (array_key_exists(self::IS_EXISTS, $value)) {
                    if (is_bool($value[self::IS_EXISTS] = filter_var(
                        trim((string) $value[self::IS_EXISTS]),
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    ))) {
                        $conditions[$key] = ['$exists' => $value[self::IS_EXISTS]];
                    }
                } elseif (array_key_exists(self::CONTAIN_IN_ARRAY, $value)) {
                    $value[self::CONTAIN_IN_ARRAY] = explode(",", (string) $value[self::CONTAIN_IN_ARRAY]);
                    $conditions[$key] = [
                        '$in' => $value[self::CONTAIN_IN_ARRAY]
                    ];
                }
            }
        }

        if (isset($filterParams['search'])) {
            $conditions['$text'] = ['$search' => self::checkInteger($key, trim(addslashes((string) $filterParams['search'])))];
        }

        return $conditions;
    }

    public static function checkInteger($key, $value)
    {
        $value = trim((string) $value);
        if (is_numeric($value)) {
            $value = intval($value);
        }

        return $value;
    }

    /**
     * to handle capped credits via webhooks but then it is not implemented as the cases are not manageable from webhooks in the cases
     */
    public function handleSubsciptionUpdate($subscriptionData)
    {
        return true;
    }

    /**
     * for custom payment and plan activations
     */
    public function prepareCustomPaymentLink($data, $get = false)
    {
        $result = [];
        $response = $this->validateData($data);
        if(!$response['success']) {
            return $response;
        }

        $userFilter = [];
        if(isset($data['user_id'])) {
            $userFilter = ['user_id' => $data['user_id']];
        } elseif(isset($data['username'])) {
            $userFilter = ['username' => trim((string) $data['username'])];
        }

        $userDetails = $this->getUserDetails($userFilter);
        if(empty($userDetails) || !isset($userDetails['user_id'])) {
            $result = [
                'success' => false,
                'message' => 'User not found with this username!'
            ];
        } else {
            $planModel = $this->di->getObjectManager()->create(Plan::class);
            $userId = $userDetails['user_id'];
            $diRes = $this->di->getObjectManager()->create(Common::class)->setDiRequesterAndTag($userId);
            if (!$diRes['success']) {
                return $diRes;
            }

            $planDetails = $this->preparePlanDetailsAsPerType($data);
            if(!$planDetails['success'] || empty($planDetails['data'])) {
                return $planDetails;
            }

            $planDetails = $planDetails['data'];
            $planModel = $this->di->getObjectManager()->create(Plan::class);
            if ($get) {
                return [
                    'success' => true,
                    'data' => $planDetails
                ];
            }
            $pendingSettlement = $planModel->getPendingSettlementInvoice($this->di->getUser()->id);
            if (!empty($pendingSettlement)) {
                if (isset($pendingSettlement['is_capped']) && $pendingSettlement['is_capped']) {
                    $userService = $planModel->getCurrentUserServices($this->di->getUser()->id, Plan::SERVICE_TYPE_ORDER_SYNC);
                    if (!empty($userService)) {
                        $settlementCheckRes = $this->di->getObjectManager()->get(Settlement::class)->checkSettlementAndProcess($this->di->getUser()->id, $userService);
                        if (isset($settlementCheckRes['success']) && !$settlementCheckRes['success']) {
                        return $settlementCheckRes;
                        }
                    }
                }
                return [
                    'success' => false,
                    'message' => "Client's settlement is pending we cannot process the plan!"
                ];
            }
            if ($data['type'] == "combo") {
                if(($data['cancel_recurring'] != 1) || (is_bool($data['cancel_recurring']) && !$data['cancel_recurring'])) {
                    return [
                        'success' => false, 'message' => 'Recurring is active you need to cancel it!'
                    ];
                }
                return $this->addOnetimeComboPlanManual($planDetails, $userId);
            }

            $quoteId = $planModel->setQuoteDetails(
                Plan::QUOTE_STATUS_ACTIVE,
                $userId,
                $planDetails['plan_id'],
                $planDetails,
                $planDetails['type']
            );

            if($quoteId) {
                $result = $planModel->generateCustomPaymentLinkAsPerQuoteId($quoteId, $planDetails['type']);
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Error in creating quote!'
                ];
            }
        }

        return $result;
    }

    public function validateData($data)
    {
        $missingParamters = [];
        $success = true;
        $msg = "";
        if(!isset($data['type']) || empty(trim((string) $data['type']))) {
            $missingParamters[] = 'type';
        }

        if(!isset($data['username']) && !isset($data['user_id'])) {
            $missingParamters[] = 'username/user_id';
        } elseif(isset($data['username']) && empty(trim((string) $data['username']))) {
            $msg .= "Username cannot be empty! ";
        } elseif(isset($data['user_id']) && empty($data['user_id'])) {
            $msg .= "user_id cannot be empty! ";
        }

        if(!isset($data['title']) || empty(trim((string) $data['title']))) {
            $missingParamters[] = 'title';
        }

        if(!isset($data['custom_price']) || empty(trim((string) $data['custom_price']))) {
            $missingParamters[] = 'custom_price';
        } elseif(!is_numeric($data['custom_price'])) {
            $msg .= "Invalid price value! ";
        } elseif((float)$data['custom_price'] < 0) {
            $msg .= "cannot accept a negative value";
        } elseif((float)$data['custom_price'] == 0) {
            $msg .= "cannot accept 0 value! ";
        }

        if(!isset($data['plan_id']) || empty(trim((string) $data['plan_id']))) {
            $missingParamters[] = 'plan_id';
        }

        if(isset($data['type'])) {
            $supportedTypes = ['custom', 'plan', 'combo'];
            if(!in_array($data['type'], $supportedTypes)) {
                $msg .= "provided 'type' not supported! ";
            }
        }

        if ($data['type'] == 'plan') {
            //payment_type validation
            if(!isset($data['payment_type']) || empty(trim((string) $data['payment_type']))) {
                $missingParamters[] = 'payment_type';
            } else {
                if ($data['payment_type'] != "onetime" && $data['payment_type'] != "recurring") {
                    $msg .= "invalid 'payment_type'! ";
                } else {
                    $paymentType = $data['payment_type'];
                    (!isset($data['base_plan_id']) || (empty(trim((string) $data['base_plan_id'])))) && $missingParamters[] = 'base_plan_id';
                    if($paymentType == 'onetime') {
                        if (!isset($data['validity']) || empty(trim((string) $data['validity']))){
                            $missingParamters[] = 'validity';
                        } elseif(!is_numeric($data['validity'])) {
                            $msg .= "Invalid value for validity! ";
                        } elseif((int)$data['validity'] != (float)($data['validity'])) {
                            $msg .= "Validity cannot be a decimal value! ";
                        } elseif((int)$data['validity'] == 0 || (float)$data['validity'] < 0) {
                            $msg .= "Validity cannot be accepted! ";
                        }
                    }
                }
            }
        }

        if ($data['type'] == 'combo') {
            (!isset($data['base_plan_id']) || (empty(trim((string) $data['base_plan_id'])))) && $missingParamters[] = 'base_plan_id';
            if (!isset($data['validity']) || empty(trim((string) $data['validity']))){
                $missingParamters[] = 'validity';
            } elseif(!is_numeric($data['validity'])) {
                $msg .= "Invalid value for validity! ";
            } elseif((int)$data['validity'] != (float)($data['validity'])) {
                $msg .= "Validity cannot be a decimal value! ";
            } elseif((int)$data['validity'] == 0 || (float)$data['validity'] < 0) {
                $msg .= "Validity cannot be accepted! ";
            }
            (!isset($data['cancel_recurring'])) && $missingParamters[] = 'cancel_recurring';
        }

        if ($data['type'] == 'combo' || $data['type'] == 'plan') {
            if(isset($data['services']) && !empty($data['services'])) {
                foreach($data['services'] as $service) {
                    if(!isset($service['type']) || (empty(trim((string) $service['type'])))) {
                        $missingParamters[] = 'service type';
                    }

                    if(!isset($service['prepaid']) || (empty(trim((string) $service['prepaid'])))) {
                        $missingParamters[] = 'prepaid';
                    } elseif(!is_numeric($service['prepaid']) || ((int)$service['prepaid'] != (float)($service['prepaid'])) || ((int)$service['prepaid'] == 0) || ((float)$service['prepaid'] < 0)) {
                        $msg .= "prepaid value not accepted! ";
                    }

                    if(isset($service['postpaid'])) {
                        if(trim((string) $service['postpaid']) == "") {
                            $msg .= "postpaid is empty";
                        } elseif(!is_numeric($service['postpaid']) || ((int)$service['postpaid'] != (float)($service['postpaid'])) || ((float)$service['postpaid'] < 0)) {
                            $msg .= "postpaid value not accepted! ";
                        }
                    }
                }
            } else {
                $missingParamters[] = 'services';
            }
        }


        $message = "";
        if(!empty($missingParamters)) {
            $keys = implode(", ", $missingParamters);
            $message = $keys. " missing!";
            $success = false;
        }

        if($msg != "") {
            $success = false;
            if ($message != "") {
                $message .= " & ".$msg;
            } else {
                $message .= $msg;
            }
        }

        return [
            'success' => $success,
            'message' => $message
        ];
    }

    public function getUserDetails($filter)
    {
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $collection = $baseMongo->getCollectionForTable('user_details');
        return $collection->findOne($filter);
    }

    public function preparePlanDetailsAsPerType($data)
    {
      $type = $data['type'];
      if($type == 'custom') {
        return $this->prepareDataForCustomPayment($data);
      }

      if($type == 'plan') {
        return $this->prepareDataForCustomPlan($data);
      }

      if($type == 'combo') {
        return $this->prepareDataForCustomPlan($data, "combo");
      }
    }

    public function prepareDataForCustomPayment($data)
    {
        $planDetails = [
            'title' => trim((string) $data['title']),
            'custom_price' => (int)trim((string) $data['custom_price']),
            'plan_id' => trim((string) $data['plan_id']),
            'type' => Plan::QUOTE_TYPE_CUSTOM
        ];
        return [
            'success' => true,
            'data' => $planDetails
        ];
    }

    public function prepareDataForCustomPlan($data, $type = "plan")
    {
        $planModel = $this->di->getObjectManager()->create(Plan::class);

        if (isset($data['enhanced_recommendation']) && $data['enhanced_recommendation']) {
            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $planCollection = $baseMongo->getCollectionForTable(Plan::PLAN_COLLECTION_NAME);
            $query = ['category' => Plan::CATERGORY_REGULAR, 'billed_type' => PLan::BILLED_TYPE_MONTHLY];
            $options = Plan::TYPEMAP_OPTIONS;
            $options['sort'] = ['custom_price' => -1];
            $options['limit'] = 1;
            $basePlanDetails = $planCollection->find($query, $options)->toArray();
            $basePlanDetails = ['success' => true, 'data' => $basePlanDetails];
            // $basePlanDetails = $planModel->getPlanByQuery(['plan_id' => trim((string) $data['base_plan_id'])]);
        } else {
            $basePlanDetails = $planModel->getPlanByQuery(['plan_id' => trim((string) $data['base_plan_id'])]);
        }
        if($basePlanDetails['success'] && !empty($basePlanDetails['data']) && (count($basePlanDetails['data']) == 1)) {
            $basePlanDetails = $basePlanDetails['data'][0];
        } else {
            return [
                'success' => false,
                'message' => 'Plan not found!'
            ];
        }

        $convertToEnterprise = false;
        if(isset($data['convert_to_enterprise']) && $data['convert_to_enterprise']) {
            $convertToEnterprise = true;
        }

        $originalTitle = $basePlanDetails['title'];
        $basePlanDetails['plan_id'] = trim((string) $data['plan_id']);
        // $basePlanDetails['title'] = trim((string) $data['title']);
        $basePlanDetails['title'] = $originalTitle;

        if ($convertToEnterprise) {
            $basePlanDetails['title'] = 'Enterprise';
        }
        $basePlanDetails['custom_price'] = (float)trim((string) $data['custom_price']);
        if($type == "combo") {
            $basePlanDetails['payment_type'] = "onetime";
        } else {
            $basePlanDetails['payment_type'] = trim((string) $data['payment_type']);
        }

        $basePlanDetails['custom_plan'] = true;
        $basePlanDetails['type'] = 'plan';
        isset($data['category']) && $basePlanDetails['category'] = trim((string) $data['category']);
        $basePlanDetails['billed_type'] = !empty(trim((string) $data['billed_type'])) ? trim((string) $data['billed_type']) : (!empty($basePlanDetails['billed_type']) ? $basePlanDetails['billed_type'] : 'monthly');
        isset($data['validity']) && $basePlanDetails['validity'] = (int)trim((string) $data['validity']);

        if (!empty($basePlanDetails['capped_price']) && ($basePlanDetails['payment_type'] == 'onetime' || $basePlanDetails['billed_type'] == 'yearly')) {
            $basePlanDetails['capped_price'] = Plan::DEFAULT_CAPPED_AMOUNT;
        } elseif (!empty($basePlanDetails['capped_price']) && !empty($data['capped_price'])) {
            $basePlanDetails['capped_price'] = (float)$data['capped_price'];
        }

        $validityChanges = 'Add';
        if($basePlanDetails['payment_type'] == Plan::BILLING_TYPE_ONETIME) {
            $validityChanges = 'Replace';
        }

        //in general we will remove discounts but in case there is any requirement of discount application they can use it as given
        if(!(isset($data['include_discount']) && $data['include_discount'] == true)) {
            $basePlanDetails['discounts'] = [];
        }

        if(isset($data['discounts'])) {
            $basePlanDetails['discounts'] = $data['discounts'];
        }

        if (isset($data['coupon_code']) && !empty($data['coupon_code'])) {
            $couponResponse = $planModel->validateCoupon($data['coupon_code'], $basePlanDetails['billed_type']);
            if (isset($couponResponse['success'], $couponResponse['data'])) {
                array_push($basePlanDetails['discounts'], $couponResponse['data']);
            } else {
                return $couponResponse;
            }
        }

        if (isset($data['marketing_offer_added']) && $data['marketing_offer_added'] && isset($data['marketing_offer_id'])) {
            $marketingOfferPlan = $planModel->getPlanByQuery(['plan_id' => trim($data['marketing_offer_id']), 'type' => 'plan', 'category' => 'marketing_offer']);
            if($marketingOfferPlan['success'] && !empty($marketingOfferPlan['data']) && (count($marketingOfferPlan['data']) == 1)) {
                $marketingOfferPlanDetails = $marketingOfferPlan['data'][0];
                $basePlanDetails['title'] = $originalTitle. ' with '. $marketingOfferPlanDetails['title'];
                if (isset($data['marketing_offer_price']) && !empty($data['marketing_offer_price'])) {
                    $mprice = $data['marketing_offer_price'];
                } else {
                    $mprice = $this->di->getObjectManager()->create(Common::class)->getDiscountedAmount($marketingOfferPlanDetails);
                }
                $marketingOfferDetails = [
                    'marketing_offer_id' => $marketingOfferPlanDetails['plan_id'],
                    'title' => $marketingOfferPlanDetails['title'],
                    'price' => $marketingOfferPlanDetails['custom_price'],
                    'billed_type' => $marketingOfferPlanDetails['billed_type'],
                    'discounts' => $marketingOfferPlanDetails['discounts'] ?? [],
                    'offered_price' => $mprice
                ];
                $basePlanDetails['marketing_offer_details'] = $marketingOfferDetails;
                if (isset($data['add_plan_price_with_marketing_offer']) && $data['add_plan_price_with_marketing_offer']) {
                    $basePlanDetails['custom_price'] = (float)$basePlanDetails['custom_price'] + (float) $mprice;
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'Marketing Offer Plan not found!'
                ];
            }
        }
        /**
         * the format to provide service is as follows:
         * {
         * "services" : [
         * {
         * "type" : "<service_type>",
         * "prepaid": "<credits>",
         * "postpaid" : "<credits>" // if not set then postpaid will be equal to prepaid
         * }
         * ]
         * }
         */
        if(isset($data['services']) && !empty($data['services'])) {
            foreach($data['services'] as $service) {
                foreach($basePlanDetails['services_groups'] as $k => $serviceGroups) {
                    foreach($serviceGroups['services'] as $i => $serviceType) {
                        if($serviceType['type'] == $service['type']) {
                            if (isset($service['add']) && !$service['add']) {
                                unset($basePlanDetails['services_groups'][$k]['services'][$i]);
                                if (count($basePlanDetails['services_groups'][$k]['services']) == 0) {
                                    unset($basePlanDetails['services_groups'][$k]);
                                }
                                continue;
                            }
                            isset($service['prepaid']) && $basePlanDetails['services_groups'][$k]['services'][$i]['prepaid']['service_credits'] = (int)trim((string) $service['prepaid']);
                            $basePlanDetails['services_groups'][$k]['services'][$i]['prepaid']['validity_changes'] = trim($validityChanges);
                            if(isset($service['postpaid'])) {
                                $basePlanDetails['services_groups'][$k]['services'][$i]['postpaid']['capped_credit'] = (int)$service['postpaid'];
                            } else {
                                isset($service['prepaid']) && $basePlanDetails['services_groups'][$k]['services'][$i]['postpaid']['capped_credit'] = (int)trim((string) $service['prepaid']);
                            }

                            isset($service['prepaid']) && $basePlanDetails['description'] = 'Up to '.$service['prepaid'].' orders';
                        }
                        if ($convertToEnterprise && $serviceType['type'] == 'product_import') {
                            unset($basePlanDetails['services_groups'][$k]['services'][$i]);
                            if (count($basePlanDetails['services_groups'][$k]['services']) == 0) {
                                unset($basePlanDetails['services_groups'][$k]);
                            } 
                        }
                        if ($convertToEnterprise && $serviceType['type'] == 'additional_services') {
                            foreach($serviceType['supported_features'] as $supportedFeature => $isAllowed) {
                                if (!$isAllowed) {
                                    $serviceType['supported_features'][$supportedFeature] = true;
                                }
                            }
                            $basePlanDetails['services_groups'][$k]['services'][$i] = $serviceType;
                        }
                    }
                }
            }
        }

        if (isset($basePlanDetails['trial_days'])) {
            $activePlan = $planModel->getActivePlanForCurrentUser($this->di->getUser()->id);
            $basePlanDetails['trial_days'] = $planModel->getTrialDays($basePlanDetails, $activePlan);
            // unset($basePlanDetails['trial_days']);
        }
        return [
            'success' => true,
            'data' => $basePlanDetails
        ];
    }

    // public function prepareDataForCustomPlanCombo($data)
    // {

    // }

    public function processInitiateImport($userId)
    {
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $configCollection = $baseMongo->getCollectionForTable('config');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $query = [
            'user_id' => $userId,
            'key' => 'restricted_import',
            'group_code' => 'plan'
        ];
        $configData = $configCollection->findOne($query, $options);
        if(!empty($configData)) {
            $isRestricted = $configData['value'] ?? false;
            if($isRestricted && isset($configData['data']) && !empty($configData['data'])) {
                $savedData = $configData['data'];
                $userEvent = $this->di->getObjectManager()->get('App\Shopifyhome\Components\UserEvent');
                $response = $userEvent->beforeProductImport($savedData);
                if($response['success'] && isset($response['data']['can_proceed']) && ($response['data']['can_proceed'] == false)) {
                    return [
                        'success' => false,
                        'message' => 'Your product count is '.($response['data']['count'] ?? 0).', due to which you cannot proceed with this plan. Upgrade to a paid plan or connect with support.',
                        // 'message' => "Your product count is ".($response['data']['count'] ?? 0). " due to which you cannot proceed with this plan please upgrade to a paid plan or connect to the support!",
                        'abort' => true,
                        'count' => $response['data']['count']
                    ];
                }

                $userEvent->initiateShopifyImporting($savedData);
                $configCollection->deleteOne($query);
                return [
                    'success' => true,
                    'message' => 'Product import intiated!',
                    'abort' => false
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Can proceed'
        ];
    }

    public function isPlanActiveAndNotFree($userId)
    {
        $planModel = $this->di->getObjectManager()->create(Plan::class);
        $activePlan = $planModel->getActivePlanForCurrentUser($userId);
        // if(!empty($activePlan) && !($planModel->isFreePlan($activePlan['plan_details'] ?? []) || $planModel->isTrialPlan($activePlan['plan_details'] ?? []))) {
        //     return true;
        // }

        if(!empty($activePlan)) {
            return true;
        }

        return false;
    }

    public function addOnetimeComboPlanManual($planDetails, $userId)
    {
        $planModel = $this->di->getObjectManager()->create(Plan::class);
        $quoteId = $planModel->setQuoteDetails(
            Plan::QUOTE_STATUS_ACTIVE,
            $userId,
            $planDetails['plan_id'],
            $planDetails,
            $planDetails['type']
        );
        $data = [
            'quote_id' => $quoteId,
            'charge_id' => "",
            'shop_name' => "",
            'user_id' => $userId,
            'payment_type' => Plan::BILLING_TYPE_ONETIME,
            'activation_method' => 'manual'
        ];
        return $planModel->activatePaymentBasedOnType($data);
    }

    /**
     * Get order limit activities from pricing_plan_activity collection with pagination
     * 
     * @param array $rawBody
     * @return array
     */


    public function getOrderLimitActivities($rawBody = [])
    {
        try {
            $logFile = 'plan/getOrderLimitActivities/' . date('Y-m-d') . '.log';

            // Get the pricing_plan_activity collection
            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $pricingActivityCollection = $baseMongo->getCollectionForTable('pricing_plan_activity');

            // Build query filter
            $filter = [
                'activity_type' => [
                    '$in' => [
                        'increase_order_limit_monthly',
                        'increase_order_limit_date_range',
                        'freeze_recurring_order_reset'
                    ]
                ]
            ];

            // Pagination parameters
            $limit = isset($rawBody['limit']) ? (int)$rawBody['limit'] : 50;
            $offset = isset($rawBody['offset']) ? (int)$rawBody['offset'] : 0;

            // Check if mongoQuery is provided from frontend
            if (isset($rawBody['mongoQuery']) && is_array($rawBody['mongoQuery']) && !empty($rawBody['mongoQuery'])) {
                // Merge frontend-prepared MongoDB query with base filter
                $frontendQuery = $rawBody['mongoQuery'];

                // Merge the queries using $and to ensure both conditions are met
                $filter = [
                    '$and' => [
                        $filter, // Base activity type filter
                        $frontendQuery // Frontend filters
                    ]
                ];
            }

            // Get total count for pagination
            $totalCount = $pricingActivityCollection->countDocuments($filter);

            // Find documents with pagination
            $options = [
                'sort' => ['created_at' => -1],
                'skip' => $offset,
                'limit' => $limit
            ];

            $cursor = $pricingActivityCollection->find($filter, $options);
            $activities = $cursor->toArray();

            return [
                'success' => true,
                'data' => $activities,
                'total' => $totalCount,
                'count' => count($activities),
                'limit' => $limit,
                'offset' => $offset
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch activities: ' . $e->getMessage(),
                'data' => [],
                'total' => 0,
                'count' => 0
            ];
        }
    }

    public function fetchActivePlanDetails($params)
    {
        try {
            if (!empty($params['user_id'])) {
                $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
                if (isset($params['fetch_user_details']) && $params['fetch_user_details']) {
                    $userCollection = $baseMongo->getCollectionForTable('user_details');
                    $options = [
                        'projection' => [
                            '_id' => 0,
                            'username' => 1,
                            'name' => 1,
                        ],
                        "typeMap" => ['root' => 'array', 'document' => 'array']
                    ];
                    $userDetails = $userCollection->findOne(['user_id' => $params['user_id']], $options);
                }
                $paymentCollection = $baseMongo->getCollectionForTable('payment_details');
                $options = [
                    'projection' => [
                        'plan_details' => 1
                    ],
                    "typeMap" => ['root' => 'array', 'document' => 'array']
                ];
                $activePlanDetails = $paymentCollection->findOne(
                    [
                        'user_id' => $params['user_id'],
                        'status' => 'active',
                        'type' => 'active_plan'
                    ],
                    $options
                );
                if (!empty($activePlanDetails['plan_details'])) {
                    // Get user service types and filter services_groups
                    $userServiceTypes = $this->getUserServiceTypes($params['user_id']);
                    $planDetails = $activePlanDetails['plan_details'];

                    // Filter and simplify services_groups based on user service types
                    if (!empty($userServiceTypes)) {
                        $planDetails['services_groups'] = $this->filterAndSimplifyServicesGroups($userServiceTypes);
                    }

                    return ['success' => true, 'data' => $planDetails, 'user_details' => $userDetails ?? []];
                } else {
                    $message = 'No active plan found';
                }
            } else {
                $message = 'Required params missing(user_id)';
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to fetch active plan details: ' . $e->getMessage()];
        }
    }

    public function fetchServiceByType($params)
    {
        try {
            if (isset($params['user_id'], $params['service_type'])) {
                $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
                $paymentCollection = $baseMongo->getCollectionForTable('payment_details');
                $options = [
                    'projection' => [
                        'supported_features' => 1,
                        'prepaid' => 1
                    ],
                    "typeMap" => ['root' => 'array', 'document' => 'array']
                ];
                $serviceDetails = $paymentCollection->findOne(
                    [
                        'user_id' => $params['user_id'],
                        'service_type' => $params['service_type']
                    ],
                    $options
                );
                if (!empty($serviceDetails)) {
                    if ($params['service_type'] == 'additional_services') {
                        $serviceData['supported_features'] = $serviceDetails['supported_features'];
                    } else {
                        $serviceData['credits'] = $serviceDetails['prepaid'];
                    }
                    return ['success' => true, 'data' => $serviceData];
                } else {
                    $message = 'No service found';
                }
            } else {
                $message = 'Required params missing(user_id, service_type)';
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to fetch service by type: ' . $e->getMessage()];
        }
    }

    public function increaseServiceCredits($params)
    {
        try {
            if (isset($params['user_id'], $params['service_type'], $params['new_credits'], $params['type'], $params['old_data'])) {
                $this->validateServiceType($params['service_type']);
                if (!$this->validateServiceType($params['service_type'])) {
                    return ['success' => false, 'message' => 'Invalid service type'];
                }
                $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
                $userDetailsCollection = $baseMongo->getCollectionForTable('user_details');
                $paymentCollection = $baseMongo->getCollectionForTable('payment_details');

                // Calculate new values
                $oldServiceCredits = $params['old_data']['service_credits'];
                $oldAvailableCredits = $params['old_data']['available_credits'];
                $oldTotalUsedCredits = $params['old_data']['total_used_credits'] ?? 0;

                $newServiceCredits = $oldServiceCredits + $params['new_credits'];
                $newAvailableCredits = $oldAvailableCredits + $params['new_credits'];
                $newTotalUsedCredits = $newServiceCredits - $newAvailableCredits;

                $update = [
                    '$inc' => [
                        'prepaid.service_credits' => $params['new_credits'],
                        'prepaid.available_credits' => $params['new_credits']
                    ],
                    '$set' => [
                        'prepaid.total_used_credits' => $newTotalUsedCredits
                    ]
                ];
                if ($params['service_type'] == 'product_import') {
                    $update['$set']['product_import_sync_activated'] = true;
                }
                $paymentCollection->updateOne(['user_id' => $params['user_id'], 'service_type' => $params['service_type']], $update);
                $pricingActivityCollection = $baseMongo->getCollectionForTable('pricing_plan_activity');
                $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
                $existingActivity = $pricingActivityCollection->findOne([
                    'user_id' => $params['user_id'],
                    'activity_type' => "increase_" . $params['service_type'] . "_limit",
                    'type' => $params['type']
                ], $options);
                $activityData = [
                    'user_id' => $params['user_id'],
                    'name' => $this->di->getUser()->username,
                    'activity_type' => "increase_" . $params['service_type'] . "_limit",
                    'type' => $params['type'],
                    'increase_credits' => $params['new_credits'],
                    'old_service_credits' => $oldServiceCredits,
                    'old_available_credits' => $oldAvailableCredits,
                    'old_total_used_credits' => $oldTotalUsedCredits,
                    'new_service_credits' => $newServiceCredits,
                    'new_available_credits' => $newAvailableCredits,
                    'new_total_used_credits' => $newTotalUsedCredits,
                    'created_at' => date('c')
                ];

                if($params['service_type'] == Services::SERVICE_TYPE_ACCOUNT_CONNECTION && $oldAvailableCredits < 0 && $newAvailableCredits >= 0) {
                    $userDetailsCollection->updateOne(['user_id' => $params['user_id']], ['$unset' => ['auto_disconnect_account_after' => '']]);
                    $activityData['auto_disconnect_account_after'] = true;
                 }
                 if($params['service_type'] == Services::SERVICE_TYPE_TEMPLATE_CREATION && $oldAvailableCredits < 0 && $newAvailableCredits >= 0) {
                    $userDetailsCollection->updateOne(['user_id' => $params['user_id']], ['$unset' => ['auto_remove_exta_templates_after' => false]]);
                    $activityData['auto_remove_exta_templates_after'] = true;
                 }

                // Create History for the increase in credits
                if (!empty($existingActivity)) {
                    $transactionHistory = $existingActivity['transaction_history'] ?? [];

                    $transactionHistory[] = [
                        'username' => $existingActivity['name'] ?? $this->di->getUser()->username,
                        'old_service_credits' => $existingActivity['old_service_credits'],
                        'new_service_credits' => $existingActivity['new_service_credits'],
                        'old_total_used_credits' => $existingActivity['old_total_used_credits'] ?? 0,
                        'new_total_used_credits' => $existingActivity['new_total_used_credits'] ?? 0,
                        'increase_credits' => $existingActivity['increase_credits'],
                        'created_at' => $existingActivity['updated_at'] ?? $existingActivity['created_at']
                    ];

                    $activityData['transaction_history'] = $transactionHistory;

                    $pricingActivityCollection->updateOne(
                        ['_id' => $existingActivity['_id']],
                        ['$set' => $activityData]
                    );
                } else {
                    $pricingActivityCollection->insertOne($activityData);
                }
                return ['success' => true, 'message' => $params['new_credits'] . " Service credits increased"];
            } else {
                $message = 'Required params missing(user_id, service_type, new_credits, type, old_data)';
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to increase service credits: ' . $e->getMessage()];
        }
    }

    public function activateOrDeactivateAdditionalServices($params)
    {
        try {
            if (isset($params['user_id'], $params['service_name'], $params['value'])) {
                $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
                $paymentCollection = $baseMongo->getCollectionForTable('payment_details');
                $update = [
                    '$set' => [
                        'supported_features.' . $params['service_name'] => $params['value']
                    ]
                ];
                $updateResponse = $paymentCollection->updateOne(['user_id' => $params['user_id'], 'service_type' => 'additional_services'], $update);
                if (!$updateResponse->getModifiedCount()) {
                    return ['success' => false, 'message' => 'Failed to update additional services'];
                }
                if ($params['value']) {
                    $message = 'Additional services activated successfully';
                } else {
                    $message = 'Additional services deactivated successfully';
                }
                return ['success' => true, 'message' => $message];
            } else {
                $message = 'Required params missing(user_id, service_name, value)';
            }
            return ['success' => false, 'message' => 'Required params missing(user_id, service_name, value)'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to activate or deactivate additional services: ' . $e->getMessage()];
        }
    }

    private function validateServiceType($serviceType)
    {
        $serviceTypes = ['product_import', 'additional_services', 'product_listings', 'account_connection', 'template_creation'];
        if (!in_array($serviceType, $serviceTypes)) {
            return false;
        }
        return true;
    }

    private function filterAndSimplifyServicesGroups($userServiceTypes)
    {
        $filteredGroups = [];

        // Map service types to their group titles
        $serviceToGroupMap = [
            'product_import' => 'Product Management',
            'template_creation' => 'Product Management',
            'account_connection' => 'Account Management',
            'additional_services' => 'Additional Services'
        ];

        // Map service types to their service titles
        $serviceTitleMap = [
            'product_import' => 'Product Import',
            'template_creation' => 'Template Creation',
            'account_connection' => 'Connected Account',
            'additional_services' => 'additional_services'
        ];

        // Group services by their group title
        $groupedServices = [];
        foreach ($userServiceTypes as $serviceType) {
            // Skip service types that are not present in $serviceToGroupMap
            if (!isset($serviceToGroupMap[$serviceType])) {
                continue;
            }
            
            $groupTitle = $serviceToGroupMap[$serviceType];
            $serviceTitle = $serviceTitleMap[$serviceType] ?? $serviceType;
            
            if (!isset($groupedServices[$groupTitle])) {
                $groupedServices[$groupTitle] = [];
            }

            $groupedServices[$groupTitle][] = [
                'title' => $serviceTitle,
                'type' => $serviceType
            ];
        }

        // Define the desired order of groups
        $groupOrder = [
            'Account Management',
            'Product Management',
            'Additional Services'
        ];

        // Convert grouped services to the required format in the specified order
        foreach ($groupOrder as $groupTitle) {
            if (isset($groupedServices[$groupTitle])) {
                $filteredGroups[] = [
                    'title' => $groupTitle,
                    'services' => $groupedServices[$groupTitle]
                ];
            }
        }

        return $filteredGroups;
    }

    private function getUserServiceTypes($userId)
    {
        try {
            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $paymentCollection = $baseMongo->getCollectionForTable('payment_details');
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

            $query = [
                'user_id' => $userId,
                'type' => 'user_service'
            ];

            $serviceTypes = $paymentCollection->distinct('service_type', $query, $options);
            return is_array($serviceTypes) ? $serviceTypes : [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
