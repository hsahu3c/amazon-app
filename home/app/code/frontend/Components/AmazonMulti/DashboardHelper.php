<?php

namespace App\Frontend\Components\AmazonMulti;

use App\Core\Components\Base;
class DashboardHelper extends Base
{
    public function getMethod($methodName, $data)
    {
        return $this->$methodName($data);
    }

    private function getInstallationStats(): array
    {
        $aggregate = [
            ['$addFields' => [
                'install_at' => ['$dateToString' => ['format' => '%Y-%m', 'date' => '$install_at']],
            ]],
            ['$group' => [
                '_id' => '$install_at',
                'not_connected' => [
                    '$sum' => ['$cond' => ['if' => ['$eq' => ['$connected_accounts', 0]], 'then' => 1, 'else' => 0]]
                ],
                'connected' => [
                    '$sum' => ['$cond' => ['if' => ['$gt' => ['$connected_accounts', 0]], 'then' => 1, 'else' => 0]]
                ],
                'uninstalled' => [
                    '$sum' => ['$cond' => ['if' => ['$ne' => ['$install_status', "installed"]], 'then' => 1, 'else' => 0]]
                ],
                'total' => [
                    '$sum' => 1
                ],
                'plan_ids' => [
                    '$push' => ['$cond' => ['if' => ['$eq' => ['$planId', null]], 'then' => 1, 'else' => '$planId']]
                ]
            ]], ['$sort' => ['_id' => 1]]
        ];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        $stats = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'data' => $stats];
    }

    private function getDailyTopSales($data): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('sales_container');
        $aggregate = [['$addFields' => ['order_date' => ['$substr' => [ '$order_date', 0, 10]]]], ['$match' => ['order_date' => $data['date']]], ['$sort' => ['totalOrderCount' => -1]], ['$limit' => 10], ['$lookup' => ['from' => 'user_details', 'localField' => 'user_id', 'foreignField' => 'user_id', 'as' => 'user']]];
        $sales = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'user_data' => $sales, 'data' => $this->formatDailySalesChart($sales)];
    }

    /**
     * @return \non-empty-array<(\int | \string), \mixed>[]
     */
    private function formatDailySalesChart($data): array
    {
        $chartData = [];
        foreach ($data as $value) {
            $value = json_decode(json_encode($value), true);
            $temp = [];
            $temp['users'] = $value['user'][0]['username'] ?? "";
            $temp['order_count'] = $value["order_count"];
            $temp[$value['marketplace_currency']] = $value['order_amount']['$numberDecimal'];
            array_push($chartData, $temp);
        }

        return $chartData;
    }

    private function getMonthlyTopSales($data): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('sales_container');
        $aggregate = [['$addFields' => ['order_month' => ['$substr' => ['$order_date', 0, 7]]]], ['$match' => ['order_month' => $data['date']]], ['$group' => ['_id' => '$user_id', 'totalOrderCount' => ['$sum' => '$order_count'], 'revenue' => ['$push' => ['currency' => '$marketplace_currency', 'total' => '$order_amount']]]], ['$sort' => ['totalOrderCount' => -1]], ['$limit' => 10], ['$lookup' => ['from' => 'user_details', 'localField' => '_id', 'foreignField' => 'user_id', 'as' => 'user']]];

        $sales = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'user_data' => $sales, 'data' => $this->formatMonthlySalesChart($sales)];
    }

    /**
     * @return non-empty-array[]
     */
    private function formatMonthlySalesChart($data): array
    {
        $chartData = [];
        foreach ($data as $value) {
            $temp = [];
            $temp['users'] = $value['user'][0]['username'] ?? "";
            $temp['order_count'] = $value["totalOrderCount"];
            foreach ($value['revenue'] as $v) {
                $v = json_decode(json_encode($v), true);
                if (isset($temp[$v['currency']])) {
                    $temp[$v['currency']] = $temp[$v['currency']] + (float) $v['total']['$numberDecimal'];
                } else {
                    $temp[$v['currency']] = $v['total']['$numberDecimal'];
                }
            }

            array_push($chartData, $temp);
        }

        return $chartData;
    }

    private function getUserConnected(): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        $aggregate = [
            ['$group' => ['_id' => '$connected_accounts', 'total' => ['$sum' => 1]]]
        ];
        $res = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'message' => '', 'data' => $res];
    }

    private function getUserPlan(): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        $aggregate = [
            [
                '$group' => [
                    '_id' => [
                        '$arrayElemAt' => [
                            '$shops.plan_display_name', 0
                        ]
                    ],
                    'total' => [
                        '$sum' => 1
                    ]
                ]
            ],
        ];
        $res = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'message' => '', 'data' => $res];
    }

    private function getAmazonLocation($data): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $aggregate = [
            [
                '$addFields' => [
                    'creationDate' => [
                        '$dateToString' => [
                            'format' => '%Y-%m-%d',
                            'date' => '$install_at'
                        ]
                    ],
                ],
            ],
            [
                '$match' => [
                    'shops.0.updated_at' => [
                        '$gte' => $data['from'],
                        '$lte' => $data['to'],
                    ],
                ],
            ],
            ['$match' => ['shops' => ['$exists' => true]]],
            ['$addFields' => ['size' => ['$size' => '$shops']]],
            ['$match' => ['size' => ['$gt' => 1]]],
            ['$unwind' => ['path' => '$shops']],
            ['$match' => ['shops.marketplace' => 'amazon']],
            ['$group' => ['_id' => '$shops.warehouses.region', 'total' => ['$sum' => 1]]]

        ];

        $res = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'message' => '', 'data' => $res];
    }

    private function getPlanDetails($data): array
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("payment_details");
        $aggregate = [];
        $aggregate[] = ['$match' => ["type" => "active_plan", "status" => "active"]];
        if (isset($data['from']) && isset($data['to'])) {
            $aggregate[] = ['$match' => ['created_at' => [
                '$gte' => $data['from'],
                '$lte' => $data['to']
            ]]];
        }

        $aggregate[] = ['$group' => [
            '_id' => '$plan_details.title',
            'total' => ['$sum' => 1]
        ]];
        $pricingData = $collection->aggregate($aggregate)->toArray();

        return ['success' =>  true, 'data' => $pricingData, 'query' => $aggregate];
    }

    /**
     * grids
     */

    private function getSales($data): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('sales_container');
        $activePage = $data['activePage'] ?? 1;
        $skip = ((int)$activePage - 1) * 100;
        $filters = isset($data['filter']) ? $data : [];

        $aggregate[] = ['$addFields' => ['order_date' => ['$substr' => ['$order_date', 0, 7]]]];
        $aggregate[] = ['$group' =>
        [
            '_id' => ['order_date' => '$order_date', 'user_id' => '$user_id', 'marketplace_currency' => '$marketplace_currency'],
            'totalOrderCount' => ['$sum' => '$order_count'],
            'totalRevenue' => ['$sum' => '$order_amount']
        ]];
        $aggregate[] = ['$addFields' => [
            'currency' => '$_id.marketplace_currency',
            'user_id' => '$_id.user_id',
            'order_date' => '$_id.order_date'
        ]];
        $aggregate[] = ['$sort' => ['order_date' => -1]];

        if (count($filters)) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => 10];
        $aggregate[] = ['$lookup' => ['from' => 'user_details', 'localField' => 'user_id', 'foreignField' => 'user_id', 'as' => 'user']];
        // die(json_encode($aggregate));
        $sales = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'data' => $sales];
    }

    private function getSalesCount($data): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('sales_container');
        $filters = isset($data['filter']) ? $data : [];
        $aggregate[] = ['$addFields' => ['order_date' => ['$substr' => ['$order_date', 0, 7]]]];
        $aggregate[] = ['$group' =>
        [
            '_id' => ['order_date' => '$order_date', 'user_id' => '$user_id', 'marketplace_currency' => '$marketplace_currency'],
            'totalOrderCount' => ['$sum' => '$order_count']
        ]];
        $aggregate[] = ['$addFields' => [
            'currency' => '$_id.marketplace_currency',
            'user_id' => '$_id.user_id',
            'order_date' => '$_id.order_date'
        ]];
        $aggregate[] = ['$sort' => ['order_date' => -1]];
        if ($filters !== []) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$count' => 'total'];

        $sales = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'data' => $sales[0]['total'] ?? 0];
    }

    private function getUninstallGrid($userData): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');

        $count = 10;
        $activePage = 1;
        if (isset($userData['count'])) {
            $count = $userData['count'];
        }

        if (isset($userData['activePage'])) {
            $activePage = $userData['activePage'] - 1;
        }

        $skip = (int)$count * $activePage;

        $filters = isset($userData['filter']) ? $userData : [];

        $conditionalQuery = [];
        $aggregate[] =  ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$match' => ['install_status' => "uninstalled"]];
        $aggregate[] = ['$addFields' => ['installed_at' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$install_at']]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shops', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shop_uninstall.apps', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => '$shop_uninstall.uninstall_date']];
        $aggregate[] = ['$addFields' => ['shop_uninstall_date' => ['$arrayElemAt' => [['$split' => ['$shop_uninstall', 'T']], 0]]]];

        $helper = $this->di->getObjectManager()->get(Helper::class);
        if ($filters !== []) {
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => (int)$count];

        $user_details = $collection->aggregate($aggregate)->toArray();
        if(!empty($user_details)) {
            $userArray = [];
            $userObjectIds = [];
            foreach($user_details as $user) {
                $userArray[$user['username']] = $user;
                $userObjectIds[] = $user['user_id'];
            }

            if(!empty($userArray) && !empty($userObjectIds)) {
                $uninstalledDates = [];
                $uninstalledUserDetailsCollection = $mongo->getCollectionForTable('uninstall_user_details');
                $uninstalledUserDetails = $uninstalledUserDetailsCollection->find(['user_id' => ['$in' => $userObjectIds]],
                    ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
                if(!empty($uninstalledUserDetails)) {
                    foreach($uninstalledUserDetails as $uninstalledUser) {
                        if(isset($uninstalledUser['reason'])) {
                            if(is_array($uninstalledUser['reason'])) {
                                $uninstalledUser['reason'] =  implode(', ', $uninstalledUser['reason']);
                            }

                            $userArray[$uninstalledUser['username']]['uninstall_reason'] = $uninstalledUser['reason'];
                        } elseif(isset($uninstalledUser['shops'])) {
                            foreach($uninstalledUser['shops'] as $shop) {
                                if(isset($shop['marketplace'], $shop['apps'][0]['uninstall_date']) && $shop['marketplace'] == 'shopify') {
                                    array_push($uninstalledDates, $shop['apps'][0]['uninstall_date']);
                                }
                            }
                        }

                        if(isset($uninstalledUser['order_matrix'])) {
                            $userArray[$uninstalledUser['username']]['order_matrix'] = $uninstalledUser['order_matrix'];
                        }
                    }

                    if(isset($data['amazonlive']) && $data['amazonlive'] == true && !empty($uninstalledDates)) {
                        $minUninstallDate = date('Y-m-d\TH:i:s', strtotime(min($uninstalledDates). '-1 hour'));
                        $res = $helper->getReasonUninstall($minUninstallDate);
                        if(isset($res['success'], $res['response']['data']['app']['events']['edges']) && $res['success']) {
                            $bulkUpdateData = [];
                            foreach ($res['response']['data']['app']['events']['edges'] as $value) {
                                if(isset($value['node']['shop']['myshopifyDomain'])) {
                                    $domain = $value['node']['shop']['myshopifyDomain'];
                                    $reason = $value['node']['reason'] ?? $value['node']['description'] ?? null;

                                    $bulkUpdateData[] =  [
                                        'updateOne' => [
                                            ["username" => $domain],
                                            ['$set' => ['reason' => $reason]]
                                        ]
                                    ];
                                    if(isset($userArray[$domain])) {
                                        if(is_array($reason)) {
                                            $reason = implode(', ', $uninstalledUser['reason']);
                                        }

                                        $userArray[$domain]['uninstall_reason'] = $reason;
                                    }
                                }
                            }

                            if(!empty($bulkUpdateData)) {
                                $res = $uninstalledUserDetailsCollection->BulkWrite($bulkUpdateData, ['w' => 1]);
                            }
                        }
                    }
                }

                $user_details = array_values($userArray);
            }
        }

        return ['success' => true, "data" => ['rows' => $user_details]];
    }

    private function getUninstallGridCount($userData): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        $filters = isset($userData['filter']) ? $userData : [];
        $conditionalQuery = [];

        $aggregate[] =  ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$match' => ['install_status' => "uninstalled"]];
        $aggregate[] = ['$addFields' => ['installed_at' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$install_at']]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shops', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shop_uninstall.apps', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => '$shop_uninstall.uninstall_date']];
        $aggregate[] = ['$addFields' => ['shop_uninstall_date' => ['$arrayElemAt' => [['$split' => ['$shop_uninstall', 'T']], 0]]]];
        if ($filters !== []) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$count' => 'total'];

        $count =  $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'count' => $count[0]['total']];
    }

    private function getFreePlanGrid($userData): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');

        $count = 10;
        $activePage = 1;
        if (isset($userData['count'])) {
            $count = $userData['count'];
        }

        if (isset($userData['activePage'])) {
            $activePage = $userData['activePage'] - 1;
        }

        $skip = (int)$count * $activePage;

        $filters = isset($userData['filter']) ? $userData : [];

        $conditionalQuery = [];
        $aggregate[] =  ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$match' => ['$or'=>[
            ['activePlanDetails.plan_id' => "1"],
            ['activePlanDetails.plan_id' => "9"]
        ]]];
        $aggregate[] = ['$addFields' => ['installed_at' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$install_at']]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shops', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shop_uninstall.apps', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => '$shop_uninstall.uninstall_date']];
        $aggregate[] = ['$addFields' => ['shop_uninstall_date' => ['$arrayElemAt' => [['$split' => ['$shop_uninstall', 'T']], 0]]]];


        if ($filters !== []) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => (int)$count];

        $user_details = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, "data" => ['rows' => $user_details]];
    }

    private function getFreePlanGridCount($userData): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        $filters = isset($userData['filter']) ? $userData : [];
        $conditionalQuery = [];

        $aggregate[] =  ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$match' => ['$or'=>[
            ['activePlanDetails.plan_id' => "1"],
            ['activePlanDetails.plan_id' => "9"]
        ]]];
        $aggregate[] = ['$addFields' => ['installed_at' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$install_at']]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shops', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shop_uninstall.apps', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => '$shop_uninstall.uninstall_date']];
        $aggregate[] = ['$addFields' => ['shop_uninstall_date' => ['$arrayElemAt' => [['$split' => ['$shop_uninstall', 'T']], 0]]]];
        if ($filters !== []) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$count' => 'total'];

        $count =  $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'count' => $count[0]['total']];
    }

    private function getPaidPlanGrid($userData): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');

        $count = 10;
        $activePage = 1;
        if (isset($userData['count'])) {
            $count = $userData['count'];
        }

        if (isset($userData['activePage'])) {
            $activePage = $userData['activePage'] - 1;
        }

        $skip = (int)$count * $activePage;

        $filters = isset($userData['filter']) ? $userData : [];

        $conditionalQuery = [];
        $aggregate[] =  ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$match' => ['$or'=>[
            ['activePlanDetails.plan_id' => ['$ne'=>"1"]],
            ['activePlanDetails.plan_id' => ['$ne'=>"9"]]
        ]]];
        $aggregate[] = ['$addFields' => ['installed_at' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$install_at']]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shops', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shop_uninstall.apps', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => '$shop_uninstall.uninstall_date']];
        $aggregate[] = ['$addFields' => ['shop_uninstall_date' => ['$arrayElemAt' => [['$split' => ['$shop_uninstall', 'T']], 0]]]];


        if ($filters !== []) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => (int)$count];

        $user_details = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, "data" => ['rows' => $user_details]];
    }

    private function getPaidPlanGridCount($userData): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        $filters = isset($userData['filter']) ? $userData : [];
        $conditionalQuery = [];

        $aggregate[] =  ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$match' => ['activePlanDetails.plan_id' => ['$gt' => "1"]]];
        $aggregate[] = ['$addFields' => ['installed_at' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$install_at']]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shops', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => ['$arrayElemAt' => ['$shop_uninstall.apps', 0]]]];
        $aggregate[] = ['$addFields' => ['shop_uninstall' => '$shop_uninstall.uninstall_date']];
        $aggregate[] = ['$addFields' => ['shop_uninstall_date' => ['$arrayElemAt' => [['$split' => ['$shop_uninstall', 'T']], 0]]]];
        if ($filters !== []) {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $conditionalQuery['$and'][] = $helper->searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$count' => 'total'];

        $count =  $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'count' => $count[0]['total']];
    }
}
