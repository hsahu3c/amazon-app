<?php

namespace App\Plan\Models;

use App\Plan\Components\Helper;
use App\Plan\Models\BaseMongo as BaseMongo;
use App\Plan\Models\Plan;
use Exception;

/**
 * Class Insights
 * @package App\Plan\Models
 */
#[\AllowDynamicProperties]
class Insights extends \App\Core\Models\BaseMongo
{
    public $paymentCollection = null;

    public $baseMongo = null;

    public $planCollection = null;

    public function onConstruct(): void
    {
        $this->di = $this->getDi();
        $this->setSource($this->table);
        $this->initializeDb($this->getMultipleDbManager()->getDb());
        $this->baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $this->paymentCollection = $this->baseMongo->getCollectionForTable(Plan::PAYMENT_DETAILS_COLLECTION_NAME);
        $this->planCollection = $this->baseMongo->getCollectionForTable(Plan::PLAN_COLLECTION_NAME);
    }

    public function getConvertibleClientsInfo($rawBody)
    {
        $query = [
            'type' => 'sales_metrics_info',
            'action' => 'plan_recommendations',
            '$expr' => [
                '$gt' => ['$total_order_count', '$current_plan_credits']
            ]
        ];
        $limit = 10;
        if(isset($rawBody['limit'])) {
            $limit = (int)$rawBody['limit'];
        }

        $offset = 0;
        if(isset($rawBody['page'])) {
            $offset = $limit * ($rawBody['page'] - 1);
        }

        if(isset($rawBody['sort']) && is_array($rawBody['sort']) && isset($rawBody['sort']['key']) && isset($rawBody['sort']['value'])) {
            $sort[$rawBody['sort']['key']] = (int)$rawBody['sort']['value'];
        } else {
            $sort = ["_id" => 1];
        }

        $options = [
            'sort' => $sort,
            'limit' => $limit,
            'skip' => $offset,
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'projection' => ['user_id' => 1, 'username' => 1, 'total_order_count' => 1, 'current_plan_credits' => 1, '_id' => 0]
        ];
        if(isset($rawBody['user_id']) && !empty($rawBody['user_id'])) {
            $query['user_id'] = $rawBody['user_id'];
        }

        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $actionCollection = $baseMongo->getCollectionForTable('action_container');
        $data = $actionCollection->find($query, $options)->toArray();
        $countOptions = [
            '$sort' => $sort,
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'projection' => ['user_id' => 1, 'username' => 1, 'total_order_count' => 1, 'current_plan_credits' => 1, '_id' => 0]
        ];
        $totalCount = $actionCollection->count($query, $countOptions);
        if(isset($rawBody['csv_export']) && $rawBody['csv_export']) {
            try {
                $csvFileDir = $this->createDir();
                $csvFile = $csvFileDir . '/statics.csv';
                $keys = ['user_id', 'username', 'amazon_sales_order_count', 'current_plan_credits'];
                if (!file_exists($csvFile)) {
                    $file = fopen($csvFile, 'w');
                    chmod($csvFile, 0777);
                } else {
                    $file = fopen($csvFile, 'w');
                }

                fputcsv($file, $keys);
                foreach ($data as $row) {
                    fputcsv($file, array_values($row));
                }

                fclose($csvFile);
                if (file_exists($csvFile)) {
                    return [
                        'success' => true,
                        'message' => 'CSV successfully created!'
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'CSV fail to progress'
                ];
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'message' => 'Some error in generating csv!'
                ];
            }
        } elseif(isset($rawBody['download_csv']) && $rawBody['download_csv']) {
                return [
                    'success' => true,
                    'data' => '/plan/insights/downloadCSV?download_csv=true',
                    'message' => 'Retrive the file from here'
                ];
        }

        if(!empty($data)) {
            return [
                'success' => true,
                'data' => ['rows' =>$data, 'count' => $totalCount]
            ];
        }

        return [
            'success' => false,
            'message' => 'No such data exist'
        ];
    }

    public function downloadCSV($filePath)
    {
        $success = false;
        try {
            $extension = 'csv';
            if (file_exists($filePath)) {
                header('Content-Type: ' . 'csv' . '; charset=utf-8');
                header('Content-Disposition: attachment; filename=statics.' . $extension);
                @readfile($filePath);
                $success = true;
            }
        } catch (Exception $e) {
            $message = 'Invalid Url. No report found.';
        }

        if($success) {
            return [
                'success' => true,
                'message' => 'File is ready!'
            ];
        }

        return [
            'success' => true,
            'message' => 'Invalid Url. No report found.'
        ];
    }

    public function createDir()
    {
        $csvFilePath = BP . DS . 'var' . DS . 'log' . DS . 'planAdminPanel'.DS. date('Y-m-d');
        if (!file_exists($csvFilePath)) {
            mkdir($csvFilePath, 0777, true);
            chmod($csvFilePath, 0777);
        }

        return $csvFilePath;
    }

    /**
     * for fetching data for payments
     */
    public function getAll(array $params): array
    {
        $conditionalQuery = [];
        if (isset($params['filter']) || isset($params['search'])) {
            $conditionalQuery = Helper::search($params);
        }

        $limit = $params['count'] ?? Helper::$defaultPagination;
        $page = $params['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;

        $aggregation = [];
        if (!empty($conditionalQuery)) {
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        $countAggregation = $aggregation;
        $countAggregation[] = [
            '$count' => 'count'
        ];
        $totalRows = $this->paymentCollection->aggregate($countAggregation, Plan::TYPEMAP_OPTIONS);
        $aggregation[] = [
            '$sort' => [
                '_id' => -1
            ]
        ];
        $aggregation[] = ['$skip' => (int)$offset];
        $aggregation[] = ['$limit' => (int)$limit];

        $rows = $this->paymentCollection->aggregate($aggregation, Plan::TYPEMAP_OPTIONS)->toArray();

        $responseData = [];
        $responseData['success'] = true;
        $responseData['data']['rows'] = $rows;
        $totalRows = $totalRows->toArray();

        $totalRows = $totalRows[0]['count'] ?? 0;
        $responseData['data']['count'] = $totalRows;
        if (isset($count)) {
            $responseData['data']['mainCount'] = $count;
        }

        return $responseData;
    }


    public function getCount(array $params): array
    {
        $conditionalQuery = [];
        if (isset($params['filter']) || isset($params['search'])) {
            $conditionalQuery = Helper::search($params);
        }

        $aggregation = [];
        if (count($conditionalQuery) > 0) {
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        $aggregation[] = [
            '$count' => 'count'
        ];
        $documentCount = $this->paymentCollection->aggregate($aggregation, Plan::TYPEMAP_OPTIONS)->toArray();
        $documentCount = $documentCount[0]['count'] ?? 0;
        $responseData['success'] = true;
        $responseData['data']['count'] = $documentCount;
        return $responseData;
    }

    public function getInsights($params): void
    {
        //if provided filter and having data as - filter['range'], filter['plan_details']['title'], filter['plan_details']['billed_type']

        $query = [
            'type' => 'active_plan',
            'status' => 'active'
        ];
        if (isset($params['filter']['plan_details'])) {
            $query[] = $params['filter'];
        }

        if (isset($params['range'])) {
            $start = $params['range']['date']['start'] ?? date('Y-m-d', strtotime('first day of this month'));
            $end = $params['range']['date']['end'] ?? date('Y-m-d');
            $query = ['created_at' => [
                '$gte' => $start,
                '$lt' => $end
            ]];
        }

        $activePlans = $this->searchFromCollection($query);

        $insightData = [
            'active_users' => count($activePlans)
        ];
        print_r($insightData);
    }

    public function searchFromCollection($data)
    {
        return $this->paymentCollection->find($data, Plan::TYPEMAP_OPTIONS)->toArray();
    }

    public function getBasicInsights($filter)
    {
        $status = $filter['status'] ?? 'active';
        // $exhaustLimit = ($filter['exhaust_limit'] ?? 100) / 100;
        // $exhaustLimit = $exhaustLimit / 100;

        $userId = $filter['user_id'] ?? null;
        $category = $filter['category'] ?? null;
        $getPlanWiseInfo = $filter['get_plan_wise_info'] ?? 0;

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
            'service_type' => Plan::SERVICE_TYPE_ORDER_SYNC,
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

        $customPlanAboutToDeactivate = $this->paymentCollection->distinct("user_id", $deactivateQuery);
        $customPlanAboutToDeactivateCount = count($customPlanAboutToDeactivate);

        //to get active_plan
        $activeUserQuery = [
            'type' => Plan::PAYMENT_TYPE_ACTIVE_PLAN,
            'status' => $status,
            'test_user' => [
                '$exists' => false
            ]
        ];
        //to add user_id filter
        if (!is_null($userId)) {
            $activeUserQuery['user_id'] = $userId;
        }

        if (isset($filter['billed_type'])) {
            $activeUserQuery['plan_details.billed_type'] = $filter['billed_type'];
        }

        if ($category == Plan::CATERGORY_REGULAR) {
            $activeUserQuery['plan_details.category'] = ['$ne' => Plan::CATEGORY_MARKETING];
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
            $totalActiveUsers = $this->paymentCollection->countDocuments($activeUserQuery);
            $totalUsers = $userCollection->countDocuments([]);
            $noActivePlanUsers = $totalUsers - $totalActiveUsers;
            $settlementQuery['$match'] = [
                'type' => Plan::SETTLEMENT_INVOICE,
                'status' => 'pending'
            ];
            if(!empty($dateFilter)) {
                $settlementQuery['$match'] = $dateFilter;
            }

            $settlementInfo = $this->paymentCollection->aggregate([
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
            ], Plan::TYPEMAP_OPTIONS)->toArray();
            $totalSettlement = $settlementInfo['total_amount'] ?? 0;
            $totalSettlementUsers = count($settlementInfo['user_ids']);

            $totalExhaustedUsers = $this->paymentCollection->countDocuments([
                'type' => Plan::PAYMENT_TYPE_USER_SERVICE,
                'service_type' => Plan::SERVICE_TYPE_ORDER_SYNC,
                'prepaid.available_credits' => 0,
                'postpaid.available_credits' => 0
            ]);
        } else {
             //to get the info grouping it with plan's title and billing type to keep all data in group for plan
            $matchQuery['$match'] = $activeUserQuery;
            $activePlanInfo = $this->paymentCollection->aggregate([
                $matchQuery,
                [
                    '$group' => [
                        '_id' => [
                            'title' => '$plan_details.title',
                            'billed_type' => ['$ifNull' => ['$plan_details.billed_type', 'monthly']],
                            'category' => ['$ifNull' => ['$plan_details.category', Plan::CATERGORY_REGULAR]]
                        ],
                        'user_ids' => ['$push' => '$user_id']
                    ]
                ],
                [
                    '$sort' => [
                        '_id.title' => 1
                    ]
                ]
            ], Plan::TYPEMAP_OPTIONS)->toArray();

            $planCategories = is_null($category) ? [Plan::CATERGORY_REGULAR, Plan::CATEGORY_MARKETING] : [$category];
            foreach($planCategories as $planCategory) {
                $planNames = $this->planCollection->distinct("title", ['category' => $planCategory]);
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
                                $settlementInfo = $this->paymentCollection->aggregate([
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
                                ], Plan::TYPEMAP_OPTIONS)->toArray();

                                $totalSettlement += $settlementInfo['total_amount'] ?? 0;
                                $planWiseInfo[$activeCategory][$title][$billedType]['pending_settlement_amount'] = $settlementInfo['total_amount'] ?? 0;
                                $planWiseInfo[$activeCategory][$title][$billedType]['pending_settlement_users'] = count($settlementInfo['user_ids'] ?? []);
                                $totalSettlementUsers += count($settlementInfo['user_ids'] ?? []);

                                //to get limits info
                                $exhaustedUsers = $this->paymentCollection->countDocuments([
                                    'user_id' => [
                                        '$in' => $userIds
                                    ],
                                    'type' => 'user_service',
                                    'service_type' => Plan::SERVICE_TYPE_ORDER_SYNC,
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

    public function getUserTransactionInfo($filter)
    {
        $transactionCollection = $this->baseMongo->getCollection('transaction_details');

        $returnData = [];

        if (isset($filter['user_id'])) {
            $userData = [];
            $userId = $filter['user_id'];
            $basicInfo = $this->getBasicInsights($filter);
            if ($basicInfo['success'] && isset($basicInfo['data']['rows'])) {
                $userData['basic_info'] = $basicInfo['data']['rows'];
            }

            $transactionQuery['user_id'] = $userId;
            $transactionQuery['type'] = 'payment_transaction';
            if (isset($filter['year'])) {
                $transactionQuery['year'] = $filter['year'];
            }

            if (isset($filter['month'])) {
                $transactionQuery['month'] = (int)$filter['month'];
            }

            if (!isset($transactionQuery['month']) && !isset($transactionQuery['year'])) {
                $transactionQuery['year'] = date('Y');
            }


            $userData['transaction_data'] = $transactionCollection->find($transactionQuery, Plan::TYPEMAP_OPTIONS)->toArray();
            $userData['active_plan_data'] = $this->getActivePlan($userId);

            $returnData = [];
            $returnData['success'] = true;
            $returnData['data']['rows'] = $userData;
            $returnData['data']['count'] = count($userData);
        } else {
            $returnData['success'] = false;
            $returnData['message'] = 'User Id not found';
        }

        return $returnData;
    }


    public function getTopUsers($filter)
    {
        $userCollection = $this->baseMongo->getCollection('user_details');
        $orderCollection = $this->baseMongo->getCollectionForTable('order_container');

        $matchquery['object_type'] = 'source_order';

        if (isset($filter['start_date'])) {
            $endDate = $filter['end_date'] ?? date('Y-m-d');
            $matchquery['created_at'] = [
                '$gte' => $filter['start_date'],
                '$lt' => $endDate
            ];
        } else {
            $matchquery['created_at'] = [
                '$gte' => date('Y-m-01')
            ];
        }

        $query[] = ['$match' => $matchquery];
        $query[] = ['$group' => [
            '_id' => '$user_id',
            'orderCount' => ['$sum' => 1]
        ]];
        $query[] = ['$sort' => ['orderCount' => -1]];
        $query[] = ['$limit' => 10];
        $data = $orderCollection->aggregate($query, Plan::TYPEMAP_OPTIONS)->toArray();

        if (!empty($data)) {
            foreach ($data as $k => $orderInfo) {
                if (isset($orderInfo['_id'])) {
                    $userInfo = $userCollection->findOne(['user_id' => $orderInfo['_id']], Plan::TYPEMAP_OPTIONS);
                    if (!empty($userInfo) && isset($userInfo['username'])) {
                        $data[$k]['username'] = $userInfo['username'];
                    }
                }
            }
        }

        return [
            'success' => true,
            'data' => [
                'rows' => $data,
                'count' => count($data)
            ]
        ];
    }

    public function getCustomDataUsers($filter)
    {
        $userId = null;
        if (isset($filter['user_id'])) {
            $userId = $filter['user_id'];
        }

        $query = [
            'type' => 'quote',
            'status' => 'approved',
            'plan_details.services_groups' => ['$exists' => false],
            'settlement_details' => ['$exists' => false],
        ];
        if (!is_null($userId)) {
            $query['user_id'] = $userId;
        }

        if (isset($filter['start_date'])) {
            $query['created_at']['$gte'] = $filter['start_date'];
        }

        if (isset($filter['end_date'])) {
            $query['created_at']['$lt'] = $filter['end_date'];
        }

        $groupQuery = [
            '_id' => '$user_id',
            'plan_details' => ['$push' => '$plan_details'],
            'total_payment' => ['$sum' => '$plan_details.custom_price']
        ];

        $aggregateQuery = [
            ['$match' => $query],
            ['$group' => $groupQuery]
        ];

        $customPayments = $this->paymentCollection->aggregate($aggregateQuery, Plan::TYPEMAP_OPTIONS)->toArray();
        $customPlanInfo = [];
        $totalCustomPayment = 0;
        if (!empty($customPayments)) {
            $userIds = [];
            foreach ($customPayments as $customPayment) {
                $userIds[] = $customPayment['_id'];
                $totalCustomPayment += $customPayment['total_payment'];
            }

            $customPlanQuery = [
                'type' => 'active_plan',
                'status' => 'active',
                'plan_details.custom_plan' => ['$exists' => true],
                'plan_details.custom_plan' => true,
                'user_id' => ['$in' => $userIds]
            ];
            $customPlanUsers = $this->paymentCollection->distinct("user_id", $customPlanQuery);
            $customPlanUserData = $this->paymentCollection->find(
                [
                    'user_id' => ['$in' => $customPlanUsers]
                ],
                Plan::TYPEMAP_OPTIONS
            )->toArray();
            if (!empty($customPlanUserData)) {
                $userCollection = $this->baseMongo->getCollection('user_details');
                $userInfo = $userCollection->find(['user_id' => ['$in' => $customPlanUsers]], Plan::TYPEMAP_OPTIONS)->toArray();
                foreach ($customPlanUsers as $customPlanUser) {
                    $customPlanDetails = [];
                    foreach ($customPlanUserData as $paymentData) {
                        if ($paymentData['user_id'] == $customPlanUser) {
                            $userData = $this->getUserData($userInfo, $customPlanUser);
                            if (!empty($userData)) {
                                $customPlanDetails['username'] = $userData['username'] ?? "";
                            } else {
                                $customPlanDetails['username'] = "";
                            }

                            $customPlanDetails['user_id'] = $customPlanUser;
                            if ($paymentData['type'] == 'user_service') {
                                $customPlanDetails['credits'] = $paymentData['prepaid']['service_credits'];
                                $customPlanDetails['deactivate_on'] = $paymentData['deactivate_on'] ?? ($paymentData['expired_at'] ?? "");
                            }

                            if ($paymentData['type'] == 'active_plan' && $paymentData['status'] == 'active') {
                                $customPlanDetails['title'] = $paymentData['plan_details']['title'];
                            }
                        }
                    }

                    $customPlanInfo[] = $customPlanDetails;
                }
            }

            return [
                'success' => true,
                'custom_payments' => ['data' => $customPayments, 'count' => count($customPayments), 'total_payment' => $totalCustomPayment],
                'custom_plans' => ['data' => $customPlanInfo, 'count' => count($customPlanInfo)],
            ];
        }

        return [
            'success' => true,
            'data' => []
        ];
    }

    public function getUserData($userData, $userId)
    {
        $resultArray = [];
        foreach ($userData as $item) {
            if ($item['user_id'] == $userId) {
                return $item;
            }
        }

        return $resultArray;
    }

}