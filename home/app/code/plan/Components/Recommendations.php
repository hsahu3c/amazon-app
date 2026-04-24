<?php

namespace App\Plan\Components;

use App\Core\Components\Base;
use DateTime;
use Exception;
use App\Plan\Models\Plan;
use App\Plan\Models\BaseMongo as BaseMongo;
use App\Plan\Components\Common;
use App\Plan\Models\Plan\Services;

/**
 * class Recommendations
 * for generating recommendations
 */
class Recommendations extends Base
{
    public $activePlan = [];
    /**
     * for generating personalized recommendations for the users
     */
    public function getPersonalizedRecommendations($userId = false, $category = 'regular')
    {
        if (!($this->di->getConfig()->get('recommendations_active') ?? true)) {
            return [];
        }
        $planModel = $this->di->getObjectManager()->create(Plan::class);
        $userId = !$userId ? $this->di->getUser()->id : $userId;
        $sourceShopId = $this->di->getObjectManager()->create(Common::class)->getShopifyShopId($userId);
        $recommendedPlan = [];

        $userService = $planModel->getCurrentUserServices($userId, Plan::SERVICE_TYPE_ORDER_SYNC);
        $this->activePlan = $this->di->getObjectManager()->create(Plan::class)->getActivePlanForCurrentUser($userId);
        if($this->canShowRecommendations($userId, $userService) == false) {
            return [];
        }

        $inAppCreditUsageCount = $salesMetricsOrderCount = $orderCount = 0;
        if (!empty($userService)) {
            if(isset($userService['created_at'])) {
                $createdAt = $userService['created_at'];
                $rangeDate = date("Y-m-d", strtotime("-45 days"));
                if (!empty($sourceShopId) && ($createdAt > $rangeDate)) {
                    $salesMetricsOrderCount = $this->salesMetricsOrderCount($userId, $sourceShopId, $category, $userService);
                }
            }
            $inAppCreditUsageCount = $this->inAppCreditUsageCount($userId, $userService, $category);
        } else {
            $salesMetricsOrderCount = $this->salesMetricsOrderCount($userId, $sourceShopId, $category, $userService);
        }
        $message = 'No recommendations to show!';
        if ($inAppCreditUsageCount || $salesMetricsOrderCount) {
            if ($salesMetricsOrderCount > $inAppCreditUsageCount) {
                $orderCount = $salesMetricsOrderCount;
                $message = 'On the basis of your order history on Amazon';
            } else {
                $orderCount = $inAppCreditUsageCount;
                $message = 'On the basis of order usage in the app';
            }
        }
        $planRecommendations = $this->getRecommendedPlanBasedOnCount($orderCount, $userId, $category);
        if (!empty($planRecommendations)) {
            return ['plans' => $planRecommendations, 'message' => $message];
        }
        if (empty($planRecommendations) && ($inAppCreditUsageCount > Plan::MAX_PLAN_ORDER_CREDIT_COUNT)) {
            $customizedPlan = $this->getEnhacedRecommendedPlan($inAppCreditUsageCount, $userId, $userService);
            if (isset($customizedPlan['success']) && $customizedPlan['success'] && !empty($customizedPlan['data'])) {
                $customizedPlan['data']['in_app_recommended'] = true;
                $planRecommendations[$customizedPlan['data']['billed_type']] = $customizedPlan['data'];
                return ['plans' => $planRecommendations, 'message' => 'On the basis of order usage in the app', 'custom' => true];
            }
        }
        return $recommendedPlan;
    }

    /**
     * get plans based on the count of the orders used
     */
    public function getRecommendedPlanBasedOnCount($orderCount, $userId, $category = Plan::CATERGORY_REGULAR)
    {
        $planModel = $this->di->getObjectManager()->create(Plan::class);
        $serviceClass = $this->di->getObjectManager()->create(Services::class);
        $recommendedPlan = [];
        // $activePlan = $planModel->getActivePlanForCurrentUser($userId);
        $activePlan = $this->activePlan;
        $activeCredits = 0;
        $activeBilledType = Plan::BILLED_TYPE_MONTHLY;
        if($orderCount == 0) {
            return $recommendedPlan;
        }

        if (!is_numeric($orderCount)) {
            return $recommendedPlan;
        }
        $orderCount += Plan::MARGIN_ORDER_COUNT;

        $basicBilledType = null;
        //to handle the case that sometime we only used to support only one type of billed type like 'monthly' only
        if ($this->di->getConfig()->has('basic_billed_type')) {
            $basicBilledType = $this->di->getConfig()->get('basic_billed_type');
        }
        $hasActivePlan = false;
        if (!empty($activePlan)) {
            $hasActivePlan = true;
            $activeBilledType = !empty($activePlan['plan_details']['billed_type']) ? $activePlan['plan_details']['billed_type'] : Plan::BILLED_TYPE_MONTHLY;
            $activeBilledType = 'monthly';
            if (!is_null($basicBilledType) && ($basicBilledType !== $activeBilledType)) {
                return $recommendedPlan;
            }
            $activeCredits = $serviceClass->getServiceCredits($activePlan['plan_details']);
            $category = $activePlan['plan_details']['category'] ?? Plan::CATERGORY_REGULAR;
        }
        //to handle users with already on an unlimited plan
        if ($activeCredits == Plan::CREDIT_LIMIT_UNLIMITED) {
            return $recommendedPlan;
        }

        //we cannot show the plan as it is already at highest paid plan exist
        if(Plan::MAX_PLAN_ORDER_CREDIT_COUNT <= ($activeCredits + Plan::MARGIN_ORDER_COUNT)) {
            return $recommendedPlan;
        }

        //this is done to show only monthly plans
        if (!is_null($basicBilledType)) {
            $params['filter']['billed_type'][1] = $basicBilledType;
        }

        $params['filter']['type'][1] = 'plan';
        $params['filter']['category'][1] = $category;
        $sort = [
            "custom_price" => 1
        ];
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $data = $baseMongo->getFilteredResults(Plan::PLAN_COLLECTION_NAME, $params, $sort);
        if (!empty($data['data']['rows'])) {
            foreach ($data['data']['rows'] as $plan) {
                $serviceCredits = $serviceClass->getServiceCredits($plan);
                if ($hasActivePlan && ($serviceCredits <= $activeCredits)) {
                    continue;
                }
                if (($serviceCredits > $orderCount) && ($plan['billed_type'] == $activeBilledType)) {
                    $recommendedPlan[$plan['billed_type']] = $plan;
                    break;
                }
            }
        }
        return $recommendedPlan;
    }

    /**
     * to check if we can show recommendations or not
     */
    public function canShowRecommendations($userId, $userService = [])
    {
        $canShow = true;
        //since unlimited is the highest plan of a time so we need to handle that
        if (isset($userService['credit_limit']) && $userService['credit_limit'] == Plan::CREDIT_LIMIT_UNLIMITED) {
            return false;
        }

        if(!empty($this->activePlan) && isset($this->activePlan['created_at'])) {
            if ( (($this->activePlan['plan_details']['billed_type'] ?? Plan::BILLED_TYPE_MONTHLY) == Plan::BILLED_TYPE_MONTHLY)
            && (($this->activePlan['plan_details']['category'] ?? Plan::CATERGORY_REGULAR) == Plan::CATERGORY_REGULAR)
            ) {
                //to allow recommnedations only when the active plan is 10 days older and is not freshly active
                $limitOfDays = date('Y-m-d', strtotime('-10 days'));
                if($this->activePlan['created_at'] > $limitOfDays) {
                    $canShow = false;
                }

                //to allow recommendations only when the used credits are more than 70%
                if(!empty($userService)) {
                    $percentUsed = (70 / 100) * $userService['prepaid']['service_credits'];
                    if ($percentUsed < $userService['prepaid']['total_used_credits']) {
                        $canShow = true;
                    }
                }
            } else {
                $canShow = false;
            }
        }
        return $canShow;
    }

    /**
     * for plan recommendations
     */
    public function inAppCreditUsageCount($userId = false, $userService = [], $category = 'regular')
    {
        $planModel = $this->di->getObjectManager()->create(Plan::class);
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $recommendedPlan = [];
        $monthlyUsageOrderCount = $serviceOrderCount = 0;
        if (empty($userService)) {
            $userService = $planModel->getCurrentUserServices($userId, Plan::SERVICE_TYPE_ORDER_SYNC);
            if(empty($userService)) {
                return 0;
            }
        }

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
            'type' => Plan::PAYMENT_TYPE_MONTHLY_USAGE,
            'source_marketplace' => Plan::SOURCE_MARKETPLACE,
            'target_marketplace' => Plan::TARGET_MARKETPLACE,
            'month' => $month,
            'year' => $year,
        ];
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $paymentCollection = $baseMongo->getCollectionForTable(Plan::PAYMENT_DETAILS_COLLECTION_NAME);
        $monthlyUsage = $paymentCollection->findOne($conditionFilterData, Plan::TYPEMAP_OPTIONS);
        if (!empty($monthlyUsage)) {
            if (isset($monthlyUsage['used_services'])) {
                foreach ($monthlyUsage['used_services'] as $service) {
                    $monthlyUsageOrderCount += isset($service['prepaid']['total_used_credits']) ? (int)$service['prepaid']['total_used_credits'] : 0;
                }
            }

            if (isset($monthlyUsage['settlement_credits_used'])) {
                foreach ($monthlyUsage['settlement_credits_used'] as $settlementCredits) {
                    $monthlyUsageOrderCount +=  isset($settlementCredits['postpaid']['total_used_credits']) ?(int)$settlementCredits['postpaid']['total_used_credits'] : 0;
                }
            }
        }
        if (!empty($userService)) {
            /** for recommending a plan for the number of days used in a month */
            $totalUsedCreditsThisMonth = (int)$userService['prepaid']['total_used_credits'] ?? 0
                + (int)$userService['postpaid']['total_used_credits'] ?? 0;

            if ($totalUsedCreditsThisMonth == 0) {
                return 0;
            }

            // $activePlan = $planModel->getActivePlanForCurrentUser($userId);
            $purchasedDate = new DateTime($this->activePlan['created_at']);
            $currentDate = new DateTime('c');
            $planActiveForDays = ($currentDate->diff($purchasedDate)->d > 0)
                ? $currentDate->diff($purchasedDate)->d
                : 1;
            $serviceOrderCount = $totalUsedCreditsThisMonth / $planActiveForDays * 30;
        }
        if ($serviceOrderCount > $monthlyUsageOrderCount) {
            return $serviceOrderCount;
        }
        return $monthlyUsageOrderCount;
        // return $this->getRecommendedPlanBasedOnCount($orderCount, $userId, $category);
    }

    /**
     * to generate recommendations based on historical count for the client
     */
    public function salesMetricsOrderCount($userId, $sourceShopId, $category, $userService = [])
    {
        $startDate = date('Y-m-d\TH:i:s-00:00', strtotime('-1 month'));
        $endDate = date('Y-m-d\TH:i:s-00:00');
        $historicalDataRecommendations = [];
        $planModel = $this->di->getObjectManager()->create(Plan::class);

        $orderCount = 0;
        $fetchDataFromApi = true;
        $dataExistInActionContainer = false;
        $orderCountDataShopWise = [];
        $commonComp = $this->di->getObjectManager()->get(Common::class);
        $shop = $commonComp->getShop($sourceShopId, $userId);
        if (!empty($shop) && isset($shop['targets']) && !empty($shop['targets'])) {
            $actionDataFilter = [
                'type' => 'sales_metrics_info',
                'user_id' => $userId,
                'action' => 'plan_recommendations',
                'username' => $this->di->getUser()->username
            ];
            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $actionCollection = $baseMongo->getCollectionForTable('action_container');
            $salesDataInContainer = [];
            $salesDataInContainer = $actionCollection->findOne($actionDataFilter, Plan::TYPEMAP_OPTIONS);
            if(!empty($salesDataInContainer)) {
                if (isset($salesDataInContainer['updated_at'])  && !empty($salesDataInContainer['data']) && !($salesDataInContainer['updated_at'] < date('Y-m-d H:i:s', strtotime('-24hours')))) {
                    $dataExistInActionContainer = true;
                    if(count($salesDataInContainer['data']) == count($shop['targets'])) {
                        $orderCount = $salesDataInContainer['total_order_count'];
                        $fetchDataFromApi = false;
                    }
                } else {
                    $dataExistInActionContainer = true;
                    $fetchDataFromApi = true;
                }
            }

            if($fetchDataFromApi) {
                try {
                    //this will fetch order's historical count for that client's previous 1 month orders at amazon for each of his connected account
                    $salesObj = $this->di->getObjectManager()->get('\App\Amazon\Components\Sales\Sales');
                    foreach ($shop['targets'] as $target) {
                        $params = [
                            'start_time' => $startDate,
                            'end_time' => $endDate,
                            'group_by' => 'Total',
                            'user_id' => $userId
                        ];
                        $response = $salesObj->getOrderMatric($target['shop_id'] ?? null, $userId, $params);
                        if ($response['success'] && !empty($response['data'])) {
                            $orderCountDataShopWise[$target['shop_id']] = $response['data'];
                        }
                    }
                } catch(Exception $e) {
                    $planModel->commonObj->addLog("Errors in recommendations in fetching sales data: ".json_encode($e->getMessage(), true), 'plan/errors/'.date('Y-m-d').'.log');
                    return 0;
                }
            }

            if($fetchDataFromApi && !empty($orderCountDataShopWise)) {
                foreach($orderCountDataShopWise as $orderSalesData) {
                    foreach ($orderSalesData as $orderInfo) {
                        $orderCount += $orderInfo['orderCount'] ?? 0;
                    }
                }

                if($dataExistInActionContainer) {
                    $salesDataInContainer['data'] = $orderCountDataShopWise;
                    $salesDataInContainer['updated_at'] = date('Y-m-d H:i:s');
                    $salesDataInContainer['total_order_count'] = $orderCount;
                } else {
                    $salesDataInContainer = [
                        'type' => 'sales_metrics_info',
                        'user_id' => $userId,
                        'username' => $this->di->getUser()->username,
                        'action' => 'plan_recommendations',
                        'data' => $orderCountDataShopWise,
                        'total_order_count' => $orderCount,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ];
                }

                $salesDataInContainer['current_plan_credits'] = $userService['prepaid']['service_credits'] ?? 0;
                if (isset($userService['credit_limit']) && $userService['credit_limit'] == Plan::CREDIT_LIMIT_UNLIMITED) {
                    $salesDataInContainer['plan'] = Plan::CREDIT_LIMIT_UNLIMITED;
                }

                $actionCollection->replaceOne(
                    $actionDataFilter,
                    $salesDataInContainer,
                    ['upsert' => true]
                );
            }

            if (empty($userService) && $orderCount == 0) {
                $orderCount += Plan::MARGIN_ORDER_COUNT;
            }

            // $historicalDataRecommendations = $this->getRecommendedPlanBasedOnCount($orderCount, $userId, $category);
        }

        return $orderCount;
    }

    public function updatePlanCreditsInSalesMetrics($userId, $currentPlanCredits)
    {
        $actionDataFilter = [
            'type' => 'sales_metrics_info',
            'user_id' => $userId,
            'action' => 'plan_recommendations',
            'username' => $this->di->getUser()->username
        ];
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $actionCollection = $baseMongo->getCollectionForTable('action_container');
        $salesDataInContainer = [];
        $salesDataInContainer = $actionCollection->findOne($actionDataFilter, Plan::TYPEMAP_OPTIONS);
        if(!empty($salesDataInContainer)) {
            $updatedData['current_plan_credits'] = $currentPlanCredits;
            $updatedData['updated_at'] = date('Y-m-d H:i:s');
            $actionCollection->updateOne(
                $actionDataFilter,
                [
                    '$set' => $updatedData
                ]
            );
        }

        return true;
    }

    public function getEnhacedRecommendedPlan($orderCount = 0, $userId = false, $userService = [])
    {
         if (!empty($userService) && isset($userService['prepaid']['service_credits'])) {
            $generate = false;
            if ((int)$userService['prepaid']['service_credits'] >= $orderCount) {
                return [];
            } else {
                if (!$this->di->getObjectManager()->create(Plan::class)->isSyncAllowed($userService) && $this->di->getObjectManager()->create(Plan::class)->isMaxPlan($this->activePlan['plan_details'], $userService)) {
                    $generate = true;
                }
            }
            if ($generate) {
                $orderCount = ceil($orderCount);
                $planPrice = $orderCount * 0.1;
                if ($planPrice > 1000) {
                    $orderCount = $orderCount + 1000;
                }
                $data['user_id'] = $userId;
                $data['type'] = 'plan';
                $data['title'] = 'Enterprise';
                $data['custom_price'] = ceil($planPrice);
                $data['plan_id'] = 'enterprise-recommended';
                $data['enhanced_recommendation'] = true;
                $data['base_plan_id'] = '11000';
                $data['payment_type'] = Plan::BILLING_TYPE_RECURRING;
                $data['convert_to_enterprise'] = true;
                $data['billed_type'] = Plan::BILLED_TYPE_MONTHLY;
                $data['services'] = [
                    [
                        'type' => Plan::SERVICE_TYPE_ORDER_SYNC,
                        'prepaid' => $orderCount
                    ]
                ];
                $data['convert_to_enterprise'] = true;
                return $this->di->getObjectManager()->get('App\Plan\Components\Helper')->prepareCustomPaymentLink($data, true);
            }
         }
        return [];
    }
}
