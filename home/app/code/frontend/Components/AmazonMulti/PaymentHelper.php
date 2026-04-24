<?php

namespace App\Frontend\Components\AmazonMulti;

use App\Core\Components\Base;
use App\Core\Models\BaseMongo;

#[\AllowDynamicProperties]
class PaymentHelper extends Base
{
    public $baseMongo = null;

    public const TYPEMAP_OPTIONS = ["typeMap" => ['root' => 'array', 'document' => 'array']];

    public function getMethod($methodName, $data)
    {
        $this->di = $this->getDi();
        $this->baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        return $this->$methodName($data);
    }

    public function getPlanInsights($filter)
    {
        $status = $filter['status'] ?? 'active';
        $exhaustLimit = $filter['exhaust_limit'] ?? 100;
        $exhaustLimit = $exhaustLimit / 100;

        $userId = $filter['user_id'] ?? null;
        $category = $filter['category'] ?? null;
        $getPlanWiseInfo = $filter['get_plan_wise_info'] ?? 0;

        $paymentCollection = $this->baseMongo->getCollectionForTable('payment_details');
        $planCollection = $this->baseMongo->getCollectionForTable('plan');

        $planWiseInfo = [];
        $activeUsers = [];
        $totalActiveUsers = 0;
        $totalSettlement = 0;
        $totalSettlementUsers = 0;
        $totalExhaustedUsers = 0;
        $totalDeactivatedUsers = 0;
        $noActivePlanUsers = 0;

        //to get user counts which are about to deactivate
        $deactivateQuery = [
            'type' => 'user_Service',
            'service_type' => 'order_sync',
            'deactivate_on' => ['$exists' => true],
            'test_user' => [
                '$exists' => false
            ]
        ];
        //to add user_id filter
        if (!is_null($userId)) {
            $deactivateQuery['user_id'] = $userId;
        }

        //to add date filter
        if (isset($filter['deactivate_date_start'])) {
            $endDate = $filter['deactivate_date_end'] ?? date('Y-m-d', strtotime("last day of current month"));//be defualt till today else till the provided date
            $deactivateQuery['deactivate_on'] = [
                '$gte' => $filter['deactivate_date_start'],
                '$lt' => $endDate
            ];
        }

        $customPlanAboutToDeactivate = $paymentCollection->distinct("user_id", $deactivateQuery);
        $customPlanAboutToDeactivateCount = count($customPlanAboutToDeactivate);

        //to get active_plan
        $activeUserQuery = [
            'type' => 'active_plan',
            'status' => $status,
            // 'test_user' => [
            //     '$exists' => false
            // ]
        ];
        //to add user_id filter
        if (!is_null($userId)) {
            $activeUserQuery['user_id'] = $userId;
        }

        if (isset($filter['billed_type'])) {
            $activeUserQuery['plan_details.billed_type'] = $filter['billed_type'];
        }

        if ($category == 'regular') {
            $activeUserQuery['plan_details.category'] = ['$ne' => 'marketing'];
        } elseif(!is_null($category)) {
            $activeUserQuery['plan_details.category'] = $category;
        }

        $customPlans = false;
        if (isset($filter['custom']) && ($filter['custom'] == 1)) {
            $customPlans = true;
            $activeUserQuery['plan_details.custom_plan'] = true;
        } else {
            $activeUserQuery['plan_details.custom_plan'] = ['$exists' => false];
        }

        //to add date filter
        if (isset($filter['start_date'])) {
            $endDate = $filter['end_date'] ?? date('Y-m-d');//be defualt till today else till the provided date
            $dateFilter['created_at'] = [
                '$gte' => $filter['start_date'],
                '$lt' => $endDate
            ];
            $activeUserQuery = $dateFilter;
        }

        $userCollection = $this->baseMongo->getCollection('user_details');
        if($getPlanWiseInfo == 0) {
            $totalActiveUsers = $paymentCollection->countDocuments($activeUserQuery);
            $totalUsers = $userCollection->countDocuments([]);
            $noActivePlanUsers = $totalUsers - $totalActiveUsers;
            $settlementQuery['$match'] = [
                'type' => 'settlement_invoice',
                'status' => 'pending'
            ];
            if(!empty($dateFilter)) {
                $settlementQuery['$match'] = $dateFilter;
            }

            $settlementInfo = $paymentCollection->aggregate([
                $settlementQuery,
                [
                    '$group' => [
                        '_id' => '$type',
                        'total_amount' => [
                            '$sum' => '$settlement_amount'
                        ],
                        'user_ids' => ['$push' => '$user_id']
                    ]
                ]
            ], self::TYPEMAP_OPTIONS)->toArray();
            $totalSettlement = $settlementInfo['total_amount'] ?? 0;
            $totalSettlementUsers = count($settlementInfo['user_ids']);

            $totalExhaustedUsers = $paymentCollection->countDocuments([
                'type' => 'user_service',
                'service_type' => 'order_sync',
                'prepaid.available_credits' => 0,
                'postpaid.available_credits' => 0
            ]);
        } else {
             //to get the info grouping it with plan's title and billing type to keep all data in group for plan
            $matchQuery['$match'] = $activeUserQuery;
            $activePlanInfo = $paymentCollection->aggregate([
                $matchQuery,
                [
                    '$group' => [
                        '_id' => [
                            'title' => '$plan_details.title',
                            'billed_type' => ['$ifNull' => ['$plan_details.billed_type', 'monthly']],
                            'category' => ['$ifNull' => ['$plan_details.category', 'regular']]
                        ],
                        'user_ids' => ['$push' => '$user_id']
                    ]
                ],
                [
                    '$sort' => [
                        '_id.title' => 1
                    ]
                ]
            ], self::TYPEMAP_OPTIONS)->toArray();

            $planCategories = is_null($category) ? ['regular', 'marketing'] : [$category];
            foreach($planCategories as $planCategory) {
                $planNames = $planCollection->distinct("title", ['category' => $planCategory]);
                foreach($planNames as $planName) {
                    $planName = strtolower((string) $planName);
                    $planCategory = strtolower((string) $planCategory);
                    $planWiseInfo[$planCategory][$planName] = [
                        'monthly' => [],
                        'yearly' => [],
                    ];
                    foreach ($activePlanInfo as $info) {
                        if (isset($info['_id']['title']) && isset($info['_id']['billed_type']) && isset($info['_id']['category'])) {
                            $title = strtolower((string) $info['_id']['title']);
                            $billedType = strtolower((string) $info['_id']['billed_type']);
                            $activeCategory = strtolower((string) $info['_id']['category']);

                            if (!empty($info['user_ids'])) {
                                $userIds = $info['user_ids'];
                                $activeUsers[] = $userIds;
                                $planWiseInfo[$activeCategory][$title][$billedType]['total_users'] = count($info['user_ids'] ?? []);
                                $totalActiveUsers += count($info['user_ids'] ?? []);

                                //to get settlement info
                                $settlementInfo = $paymentCollection->aggregate([
                                    [
                                        '$match' => [
                                            'type' => 'settlement_invoice',
                                            'status' => 'pending',
                                            'user_id' => [
                                                '$in' => $userIds
                                            ]
                                        ]
                                    ],
                                    [
                                        '$group' => [
                                            '_id' => '$type',
                                            'total_amount' => [
                                                '$sum' => '$settlement_amount'
                                            ],
                                            'user_ids' => ['$push' => '$user_id']
                                        ]
                                    ]
                                ], self::TYPEMAP_OPTIONS)->toArray();

                                $totalSettlement += $settlementInfo['total_amount'] ?? 0;
                                $planWiseInfo[$activeCategory][$title][$billedType]['pending_settlement_amount'] = $settlementInfo['total_amount'] ?? 0;
                                $planWiseInfo[$activeCategory][$title][$billedType]['pending_settlement_users'] = count($settlementInfo['user_ids'] ?? []);
                                $totalSettlementUsers += count($settlementInfo['user_ids'] ?? []);

                                //to get limits info
                                $exhaustedUsers = $paymentCollection->countDocuments([
                                    'user_id' => [
                                        '$in' => $userIds
                                    ],
                                    'type' => 'user_service',
                                    'service_type' => 'user_service',
                                    'prepaid.available_credits' => 0,
                                    'postpaid.available_credits' => 0
                                ]);
                                $planWiseInfo[$activeCategory][$title][$billedType]['exhaust_limit_user_count'] = $exhaustedUsers;
                                $totalExhaustedUsers += $exhaustedUsers;
                            } else {
                                $planWiseInfo[$activeCategory][$title][$billedType]['total_users'] = 0;
                                $totalActiveUsers += 0;
                            }
                        }
                    }
                }
            }

            if(!empty($activeUsers)) {
                $userCollection = $this->baseMongo->getCollection('user_details');
                $noActivePlanUsers = $userCollection->countDocuments(['user_id'=> ['$nin' => $activeUsers]]);
            }
        }


        $responseData = [];
        $responseData['success'] = true;
        $responseData['data']['rows'] = [
            'total_active_users' => $totalActiveUsers,
            'total_pending_settlement_amount' => $totalSettlement,
            'total_pending_settlement_users' => $totalSettlementUsers,
            'total_exhaust_limit_users' => $totalExhaustedUsers,
            'deactivated_users' => $totalDeactivatedUsers,
            'plans_about_to_deactivate_users' => $customPlanAboutToDeactivate,
            'plans_about_to_deactivate_count' => $customPlanAboutToDeactivateCount,
            'users_with_no_active_plan' => $noActivePlanUsers,
            'plan_wise_data' => $planWiseInfo,
        ];
        $responseData['data']['count'] = count($planWiseInfo);
        return $responseData;
    }

    public function getTopPlanUsers($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        if(isset($data['filter']['activePlanDetails.custom_plan'])) {
            $data['filter']['activePlanDetails.custom_plan'] = ($data['filter']['activePlanDetails.custom_plan'] == "true") ? true : false;
        }

        if(isset($data['filter']) && !empty($data['filter'])) {
            $match['$match'] = $data['filter'];
        } else {
            $match['$match'] = ['activePlanDetails.category' => 'regular', 'activePlanDetails.billed_type' => 'monthly'];
        }

        $aggregate = [
            $match,
            [ '$sort' => [ "activePlanDetails.custom_price" => -1] ],
            [ '$limit' => 10 ]
        ];
        $res = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'message' => '', 'data' => $res];
    }
}