<?php

namespace App\Frontend\Components\AmazonMulti;

use App\Core\Components\Base;
class OrderAnalysisHelper extends Base
{
    public function getMethod($methodName, $data)
    {
        return $this->$methodName($data);
    }

    private function getYearlyOrMonthlyOrders($data)
    {
        $csv = false;
        if (isset($data['csv'])) {
            $csv = $data['csv'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
        $aggregate[] = ['$match' => ['object_type' => 'source_order']];
        $aggregate[] = ['$addFields' => ['order_date' => ['$convert' => ['input' => '$source_created_at', 'to' => 'date']]]];
        $yearly = false;
        $userwise = false;
        if (isset($data['yearly']) && $data['yearly'] == 'true') {
            $yearly = true;
        }

        if (isset($data['userwise']) && $data['userwise'] == 'true') {
            $userwise = true;
        }

        if (isset($data['user'])) {
            $aggregate[] = ['$match' => ['user_id' => ['$in' => $data['user_ids']]]];
        }

        if (isset($data['from']) && isset($data['to'])) {
            $conditionalQuery = [
                'source_created_at' => [
                    '$gte' => $data['from'],
                    '$lte' => $data['to'],
                ],
            ];
            $aggregate[] = [
                '$match' => $conditionalQuery,
            ];
        }

        if ($yearly && $userwise) {
            $aggregate[] = ['$group' => ['_id' => ['date' => ['$dateToString' => ['date' => '$order_date', 'format' => '%Y']], 'user_id' => '$user_id'], 'total' => ['$sum' => 1]]];
            $aggregate[] = ['$group' => ['_id' => '$_id.date', 'users' => ['$push' => ['user_id' => '$_id.user_id', 'total' => '$total']]]];
        } else if ($yearly) {
            $aggregate[] = ['$group' => ['_id' => ['$dateToString' => ['date' => '$order_date', 'format' => '%Y']], 'total' => ['$sum' => 1]]];
        } else if ($userwise) {
            $aggregate[] = ['$group' => ['_id' => ['date' => ['$dateToString' => ['date' => '$order_date', 'format' => '%Y-%m']], 'user_id' => '$user_id'], 'total' => ['$sum' => 1]]];
            $aggregate[] = ['$group' => ['_id' => '$_id.date', 'users' => ['$push' => ['user_id' => '$_id.user_id', 'total' => '$total']]]];
        } else {
            $aggregate[] = ['$group' => ['_id' => ['$dateToString' => ['date' => '$order_date', 'format' => '%Y-%m']], 'total' => ['$sum' => 1]]];
        }

        $orders_data = $collection->aggregate($aggregate)->toArray();
        if ($csv) {
            return $orders_data;
        }

        return ['success' => true, 'data' => $orders_data];
    }

    private function getPlanWiseOrdersData($data)
    {
        if (isset($data['plan_id'])) {
            $plan = $data['plan_id'];
            $userIds = $this->getPlanWiseUserId($plan);
        }

        if (isset($data['from'])) $from = $data['from'];

        if (isset($data['to'])) $to = $data['to'];

        $csv = false;
        if (isset($data['csv'])) {
            $csv = $data['csv'];
        }

        if ($csv) {
            return $this->getYearlyOrMonthlyOrders(['yearly' => $data['yearly'], 'userwise' => "true", 'user_ids' => $userIds, 'user' => true, 'csv' => $csv]);
        }

        return $this->getYearlyOrMonthlyOrders(['yearly' => $data['yearly'], 'user_ids' => $userIds, 'user' => true, 'from' => $from, 'to' => $to]);
    }

    private function getPlanWiseUserId($plan_id)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('payment_details');
        $plan_id = $plan_id == '1' ? "1" : ['$gt' => "1"];
        $aggregate = [
            ['$match' => ['type' => 'active_plan', 'status' => 'active', 'plan_details.plan_id' => $plan_id]],
            ['$group' => ['_id' => null, 'user_id' => ['$push' => '$user_id']]]
        ];

        $userIds = $collection->aggregate($aggregate)->toArray();
        if (count($userIds)) {
            $userIds = $userIds[0]['user_id'];
        }

        return $userIds;
    }

    private function getUserOrdersCount($data): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
        $collection2  = $mongo->getCollectionForTable('user_details');
        $tempUserIds = [];
        if (isset($data['username']) && $data['username']) {
            $users = $collection2->find(['username' => [
                '$regex' => $data['username']
            ]])->toArray();
            foreach ($users as $v) {
                $tempUserIds[$v['user_id']] = $v['username'];
            }
        }

        $conditionalQuery = [];
        $conditionalQuery = [
            'source_created_at' => [
                '$gte' => $data['from'],
                '$lte' => $data['to'],
            ],
            'object_type' => "source_order"
        ];



        $aggregate = [];
        if (isset($data['username']) && $data['username']) {
            $aggregate[] = ['$match' => ['user_id' => ['$in' => array_keys($tempUserIds)]]];
        }

        $aggregate[] = [
            '$match' => $conditionalQuery,
        ];


        $aggregate[] = [
            '$group' => [
                '_id' => [
                    'user_id' => '$user_id',
                    'status' => '$status'
                ],
                'total' => [
                    '$sum' => 1,
                ],
            ],
        ];
        $aggregate[] = [
            '$group' => [
                '_id' => [
                    'username' => '$_id.user_id',
                ],
                'data' => [
                    '$push' => [
                        'status' => '$_id.status',
                        'total' => '$total',
                    ],
                ],
                'totalOrder' => ['$sum' => '$total'],
            ],
        ];
        $aggregate[] = ['$sort' => ['totalOrder' => -1]];
        $aggregate[] = ['$limit' => 100];

        $res = $collection->aggregate($aggregate)->toArray();
        if (isset($data['username']) && $data['username']) {
            foreach ($res as $k => $v) {
                $res[$k]['_id']['username'] = $tempUserIds[$res[$k]['_id']['username']];
            }

            $response = $this->formattedUsersOrdersCount(array_values($res));
        } else {
            foreach ($res as $k => $v) {
                $tempUserIds[$v['_id']['username']] = $v;
            }

            $users = $collection2->find(['user_id' => ['$in' => array_keys($tempUserIds)]])->toArray();
            foreach ($users as $v) {
                $tempUserIds[$v['user_id']]['_id']['username'] = $v['username'];
            }

            $response = $this->formattedUsersOrdersCount(array_values($tempUserIds));
        }

        return [
            'success' => true,
            'data' => $response['graphArray'],
            'userData' => $response['userArray'],
        ];
    }


    private function formattedUsersOrdersCount($res): array
    {
        $arr = [];
        $userArray = [];
        foreach ($res as $value) {
            if (isset($value['_id']['username']) && !empty($value['_id']['username'])) {
                $temp = [];
                $tempUser = [];
                foreach ($value['data'] as $v) {
                    $temp[$v['status'] ?? ""] = $v['total'] ?? 0;
                }

                $temp['users'] = $value['_id']['username'];
                $tempUser[$value['_id']['username']] = $value['_id']['username'];
                array_push($arr, $temp);
                array_push($userArray, $tempUser);
            }
        }

        return ['graphArray' => $arr, 'userArray' => $userArray];
    }

    private function getUsernames($data): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $data = $collection->aggregate(
            [['$match' => ['username' => ['$regex' => $data['name']], 'shops' => ['$exists' => true]]], ['$project' => ['label' => '$username', 'value' => '$username', 'id' => '$user_id', '_id' => 0]]]
        )->toArray();
        return ['success' => true, "data" => $data];
    }

    private function getUserConnectedAmazonAccounts($data): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("user_details");
        $userData = $collection->find(['username' => $data['username']])->toArray();
        $ids = [];
        $sellers = [];
        if (count($userData) > 0) {

            foreach ($userData[0]['shops'] as $v) {
                if (isset($v['sources'])) {
                    array_push($ids, $v['_id']);
                    array_push($sellers, [
                        "sellerName" => $v['sellerName'],
                        "seller_id" => $v['warehouses'][0]['seller_id'],
                        "marketplace_id" => $v['warehouses'][0]['marketplace_id']
                    ]);
                }
            }

            return ['success' => true, "data" => ["ids" => $ids, 'sellerNames' => $sellers, "user_id" => $userData[0]['user_id']]];
        }

        return ["success" => false, "message" => "invalid username"];
    }

    private function getAmazonAccountWiseOrders($data): array
    {
        $conditionalQuery = [];

        $conditionalQuery = [
            'source_created_at' => [
                '$gte' => $data['from'],
                '$lte' => $data['to'],
            ],
            'object_type' => "source_order",
            'marketplace_shop_id' => $data['id'],
            'user_id' => $data['user_id']
        ];


        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
        $aggregate = [];
        $aggregate[] = [
            '$match' => $conditionalQuery,
        ];

        $aggregate[] = [
            '$group' => [
                '_id' => '$status',
                'total' => [
                    '$sum' => 1,
                ],
            ],
        ];


        $res = $collection->aggregate($aggregate)->toArray();

        return [
            'success' => true,
            'data' => $res,

        ];
    }
}