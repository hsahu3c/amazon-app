<?php

namespace App\Frontend\Components;

use App\Core\Components\Base;
use MongoDB\BSON\ObjectId;
use App\Core\Models\Acl\Role;
use App\Core\Models\User;
use DateTime;
use App\Shopify\Models\Shop\Details;
use function MongoDB\BSON\toJSON;
use function MongoDB\BSON\fromPHP;
use GuzzleHttp\Client;

class AdminHelper extends Base
{
    public const IS_EQUAL_TO = 1;

    public const IS_NOT_EQUAL_TO = 2;

    public const IS_CONTAINS = 3;

    public const IS_NOT_CONTAINS = 4;

    public const START_FROM = 5;

    public const END_FROM = 6;

    public const RANGE = 7;

    public $_client;

    public function __construct()
    {
        //$this->_data = $this->di->getObjectManager()->get('\App\Amazonimporter\Components\Data');
        // $this->_client = new Client();
    }

    public function getAdminConfig()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $collection = $mongo->getCollectionFortable('admin_config');
        $collection2 = $mongo->getCollectionFortable('user_details');
        $k = $collection->find()->toArray();
        $k2 = $collection2->aggregate([
            [
                '$lookup' => [
                    "from" => "admin_config",
                    "localField" => "bda_allign",
                    "foreignField" => "_id",
                    "as" => "user",
                ],
            ],
            [
                '$group' => [
                    "_id" => [
                        "id" => '$bda_allign',
                        "name" => [
                            '$arrayElemAt' => [
                                '$user.name',
                                0,
                            ],
                        ],
                    ],
                    "total" => [
                        '$sum' => 1,
                    ],
                ]
            ],
        ])->toArray();
        foreach ($k as $key => $value) {
            foreach ($k2 as $value1) {
                if ($value['_id'] == $value1['_id']['id']) {
                    $k[$key]['count'] = $value1['total'];
                }
            }
        }

        return ['success' => true, 'message' => '', 'data' => $k];
    }

    // public function getStepCompletedCount($data)
    // {
    //     $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
    //     $collection = $mongo->getCollectionForTable("admin_config");
    //     $collection1 = $mongo->getCollectionForTable("user_details");
    //     $aggregate = [
    //         [
    //             '$match' => ['type' => 'app_tag']
    //         ],
    //         [
    //             '$group' => ['_id' => 'app_tags', 'app_tags' => ['$addToSet' => '$app_tag']]
    //         ]
    //     ];
    //     $res = $collection->aggregate($aggregate)->toArray();
    //     $aggregate1 = [];
    //     $temp_data = [];
    //     $steps_data = [];
    //     foreach ($res[0]['app_tags'] as $value) {
    //         $aggregate1 = [
    //             [
    //                 '$addFields' => [
    //                     'creationDate' => [
    //                         '$arrayElemAt' => [
    //                             [
    //                                 '$split' => [
    //                                     '$created_at',
    //                                     'T',
    //                                 ],
    //                             ],
    //                             0,
    //                         ],
    //                     ]
    //                 ]
    //             ],
    //             [
    //                 '$match' => [
    //                     'creationDate' => [
    //                         '$gte' => $data['from'],
    //                         '$lte' => $data['to'],
    //                     ],
    //                     $value => ['$exists' => true]
    //                 ]
    //             ],
    //             [
    //                 '$project' => [$value => 1]
    //             ]
    //         ];
    //         $res1 = $collection1->aggregate($aggregate1)->toArray();
    //         foreach ($res1 as $key1 => $value1) {
    //             if (!isset($temp_data[$value1[$value]['step_completed'] . ' steps completed'])) {
    //                 $temp_data[$value1[$value]['step_completed'] . ' steps completed'] = 1;
    //             } else {
    //                 $temp_data[$value1[$value]['step_completed'] . ' steps completed']++;
    //             }
    //         }
    //     }

    //     foreach ($temp_data as $key => $value) {
    //         array_push($steps_data, ['_id'=>$key, 'total' => $value]);
    //     }

    //     return ['success' => true, 'message' => '', 'data' => $steps_data];
    // }
    public function getStepCompletedCount($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $collection = $mongo->getCollectionForTable("user_details");
        $aggregate = [
            [
                '$addFields' => [
                    'creationDate' => [
                        '$arrayElemAt' => [
                            [
                                '$split' => [
                                    '$created_at',
                                    'T',
                                ],
                            ],
                            0,
                        ],
                    ],
                ],
            ],
            [
                '$match' => [
                    'created_at' => [
                        '$gte' => $data['from'],
                        '$lte' => $data['to'],
                    ],
                ],
            ], ['$group' => ['_id' => '$step_completed', 'total' => ['$sum' => 1]]],
        ];
        $res = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'message' => '', 'data' => $res];
    }

    public function getUserOrdersCount($data)
    {
        $conditionalQuery = [];
        if (isset($data['username'])) {
            $userConditionalQuery = [
                'username' => [
                    '$regex' => $data['username']
                ]
            ];
        }

        $conditionalQuery = [
            'placed_at' => [
                '$gte' => $data['from'],
                '$lte' => $data['to'],
            ],
        ];


        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $collection = $mongo->getCollectionForTable('order_container');
        $aggregate = [];

        $aggregate[] = [
            '$match' => $conditionalQuery,
        ];
        $aggregate[] = [
            '$lookup' => [
                'from' => 'user_details',
                'localField' => 'user_id',
                'foreignField' => 'user_id',
                'as' => 'data',
            ],
        ];
        $aggregate[] = [
            '$addFields' => [
                'user' => [
                    '$arrayElemAt' => [
                        '$data',
                        0,
                    ],
                ],
            ],
        ];
        $aggregate[] = [
            '$addFields' => [
                'username' => '$user.username',
            ],
        ];
        if (isset($userConditionalQuery)) {
            $aggregate[] = [
                '$match' => $userConditionalQuery
            ];
        }

        $aggregate[] = [
            '$group' => [
                '_id' => [
                    'm_id' => '$user_id',
                    'username' => '$username',
                    'status' => '$target_status',
                ],
                'total' => [
                    '$sum' => 1,
                ],
            ],
        ];
        $aggregate[] = [
            '$group' => [
                '_id' => [
                    'user_id' => '$_id.m_id',
                    'username' => '$_id.username',
                ],
                'data' => [
                    '$push' => [
                        'status' => '$_id.status',
                        'total' => '$total',
                    ],
                ],
            ],
        ];
        // print_r($aggregate);die;
        $res = $collection->aggregate($aggregate)->toArray();
        //print_r($res);die;
        $response = $this->formattedUsersOrdersCount($res);
        // $mongo->setSource("user_details");
        // $collection = $mongo->getCollection();
        return [
            'success' => true,
            // 'message' => '',
            'data' => $response,
            // 'userData' => $response['userArray'],
            //  'databaseResponse' => $res,
            // 'aggregate' => $aggregate,
            ];
    }

    public function formattedUsersOrdersCount($res)
    {
        $arr = [];
        foreach ($res as $key => $value) {
            array_push($arr, [
                'users' => $value['_id']['username'],
            ]);
            foreach ($value['data'] as $val) {
                $arr[$key][$val['status']] = $val['total'];
            }
        }

        return $arr;
    }

    public function getAppOrdersCount($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $collection = $mongo->getCollectionForTable('order_container');
        $aggregate = [
            [
                '$addFields' => [
                    'month' => [
                        '$concat' => [
                            [
                                '$arrayElemAt' => [
                                    ['$split' =>
                                        ['$placed_at', "-"]], 0,
                                ],
                            ],
                            "-",
                            [
                                '$arrayElemAt' => [
                                    ['$split' =>
                                        ['$placed_at', "-"]], 1,
                                ],
                            ],
                        ],
                    ],
                    'creationDate' => [
                        '$arrayElemAt' => [
                            [
                                '$split' => [
                                    '$placed_at',
                                    'T',
                                ],
                            ],
                            0,
                        ],
                    ],
                ],
            ],
            [
                '$match' => [
                    'placed_at' => [
                        '$gte' => $data['from'],
                        '$lte' => $data['to'],
                    ],
                ],
            ],
            [
                '$group' => [
                    '_id' => [
                        'month' => '$month',
                        'status' => '$source_status',
                    ],
                    'total' => [
                        '$sum' => 1,
                    ],
                ],
            ],
            [
                '$group' => [
                    '_id' => '$_id.month',
                    'data' => [
                        '$push' => [
                            'status' => '$_id.status',
                            'total' => '$total',
                        ],
                    ],
                ],
            ],
        ];
        $res = $collection->aggregate($aggregate)->toArray();
        $response = $this->formattedAppOrdersCount($res);
        return ['success' => true, 'message' => '', 'data' => $response];
    }

    public function formattedAppOrdersCount($res)
    {
        $arr = [];
        foreach ($res as $key => $value) {
            array_push($arr, [
                'month' => $value['_id'],
            ]);
            foreach ($value['data'] as $val) {
                $arr[$key][$val['status']] = $val['total'];
            }
        }

        return $arr;
    }

    public function getUserTypeCount($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        // $mongo->setSource("user_details");
        // die("dsf");
        $collection = $mongo->getCollectionForTable('user_details');
        $aggregate = [
            [
                '$addFields' => [
                    'creationDate' => [
                        '$arrayElemAt' => [
                            [
                                '$split' => [
                                    '$created_at',
                                    'T',
                                ],
                            ],
                            0,
                        ],
                    ],
                ],
            ],
            [
                '$match' => [
                    'created_at' => [
                        '$gte' => $data['from'],
                        '$lte' => $data['to'],
                    ],
                ],
            ],
            ['$lookup' => ['from' => 'admin_config', 'localField' => 'user_type', 'foreignField' => '_id', 'as' => 'user_data']], ['$group' => ['_id' => ['$arrayElemAt' => ['$user_data.name', 0]], 'total' => ['$sum' => 1]]],
        ];
        $res = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'message' => '', 'data' => $res];
    }

    public function getAppConfig()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //$mongo->setSource("app_config");
        $collection = $mongo->getCollectionForTable('app_config');
        $k = $collection->aggregate([['$limit' => 100]])->toArray();
        return ['success' => true, 'message' => '', 'data' => $k];
    }

    public function setAdminConfig($userData)
    {
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        if ($userData['key'] == "bda_allign" || $userData['key'] == "user_type" || $userData['key'] == "com_med") {
            $store_admin_config = $user_details->setConfigByKey($userData['key'], new ObjectId($userData['value']), $userData['user_id']);
        } else {
            $store_admin_config = $user_details->setConfigByKey($userData['key'], $userData['value'], $userData['user_id']);
        }

        return $store_admin_config;
    }

    public function editAdminConfig($item)
    {
        // die('dfg');
        unset($item['target_marketplace']);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //$mongo->setSource("admin_config");
        $collection = $mongo->getCollectionForTable('admin_config');
        if (isset($item['name']) && $item['name'] != "") {
            $res = $collection->updateOne(["_id" => new ObjectId($item['value'])], ['$set' => ["name" => $item['name']]]);
            if ($res->getModifiedCount() > 0) {
                $data = ["success" => "true", "message" => "Data updated Successfully."];
            } else {
                $data = ["success" => "false", "message" => "Some Error Occured!"];
            }
        } else {
            $res = $collection->deleteOne(["_id" => new ObjectId($item['value'])]);
            if ($res->getDeletedCount() > 0) {
                $data = ["success" => "true", "message" => "Data deleted successfully."];
            } else {
                $data = ["success" => "false", "message" => "Some Error Occured!"];
            }
        }

        return $data;
    }

    public function addAdminConfig($item)
    {
        unset($item['target_marketplace']);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //$mongo->setSource("admin_config");
        $collection = $mongo->getCollectionForTable('admin_config');
        $add_admin_config = $collection->insertOne($item);
        if ($add_admin_config->getInsertedCount() > 0) {
            return ['data' => "Inserted Successfully", "success" => true, "count" => $add_admin_config->getInsertedCount()];
        }
        return ['data' => "Some Error Occured", "success" => false];
    }

    public function orderStatusFile($id, $date)
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
        $totalAggreagte = [];

        $totalAggreagte[] = ['$count' => 'count'];

        $aggregateStatus[] = [
            '$group' => [
                '_id' => '$status',
                'count' => ['$sum' => 1],
            ],
        ];

        $facet = [
            [
                '$match' => [
                    'user_id' => $id,
                ],
            ],
            [
                '$facet' => [
                    'total' => $totalAggreagte,
                    'status' => $aggregateStatus,
                ],
            ],
        ];

        $data = $collection->aggregate($facet)->toArray();
        $data = json_decode(json_encode($data), true);

        $newData = [];
        foreach ($data[0] as $key => $value) {
            if ($key === 'status') {
                $newData[$key] = $value;
            } elseif (isset($value[0]['count'])) {
                $newData[$key] = $value[0]['count'];
            } else {
                $newData[$key] = 0;
            }
        }

        return [
            'success' => true,
            'data' => $newData,
        ];

    }

    public static function search($filterParams = [], $fullTextSearchColumns = null)
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim((string) $key);
                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    $conditions[] = "" . $key . " = '" . trim(addslashes((string) $value[self::IS_EQUAL_TO])) . "'";
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[] = "" . $key . " != '" . trim(addslashes((string) $value[self::IS_NOT_EQUAL_TO])) . "'";
                } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                    $conditions[] = "" . $key . " LIKE '%" . trim(addslashes((string) $value[self::IS_CONTAINS])) . "%'";
                } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                    $conditions[] = "" . $key . " NOT LIKE '%" . trim(addslashes((string) $value[self::IS_NOT_CONTAINS])) . "%'";
                } elseif (array_key_exists(self::START_FROM, $value)) {
                    $conditions[] = "" . $key . " LIKE '" . trim(addslashes((string) $value[self::START_FROM])) . "%'";
                } elseif (array_key_exists(self::END_FROM, $value)) {
                    $conditions[] = "" . $key . " LIKE '%" . trim(addslashes((string) $value[self::END_FROM])) . "'";
                } elseif (array_key_exists(self::RANGE, $value)) {
                    if (trim((string) $value[self::RANGE]['from']) && !trim((string) $value[self::RANGE]['to'])) {
                        $conditions[] = "" . $key . " >= '" . $value[self::RANGE]['from'] . "'";
                    } elseif (trim((string) $value[self::RANGE]['to']) && !trim((string) $value[self::RANGE]['from'])) {
                        $conditions[] = "" . $key . " >= '" . $value[self::RANGE]['to'] . "'";
                    } else {
                        $conditions[] = "" . $key . " between '" . $value[self::RANGE]['from'] . "' AND '" . $value[self::RANGE]['to'] . "'";
                    }
                }
            }
        }

        if (isset($filterParams['search']) && $fullTextSearchColumns) {
            $conditions[] = " MATCH (" . $fullTextSearchColumns . ") AGAINST ('" . trim(addslashes((string) $filterParams['search'])) . "' IN NATURAL LANGUAGE MODE)";
        }

        $conditionalQuery = "";
        if (is_array($conditions) && count($conditions)) {
            $conditionalQuery = implode(' AND ', $conditions);
        }

        return $conditionalQuery;
    }

    public function getAllUsers($userData)
    {

        /*$mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $userId = '61029a1d11772268357a6a84';
        $homeShopId = $mongo->getCounter('shop_id');

        $shopDetails = [
            '_id'               => $homeShopId,
            'remote_shop_id'    => 51,
            'marketplace'       => 'amazon',
            'warehouses'        => [
                [
                    "region"            => "IN",
                    "marketplace_id"    => "A21TJRUUN4KGV",
                    "seller_id"         => "A3VSMOL1YWESR0",
                    "status"            => "active",
                    "seller_name"       => "Cedcommerce IN",
                    // "_id"               => $mongo->getCounter('warehouse_id')
                    "_id"               => $homeShopId
                ]
            ]
        ];

        $mongo->setSource("user_details");
        $collection = $mongo->getCollection();

        $updateResult = $collection->updateOne(
            ['user_id' =>  $userId],
            ['$push' => ['shops' => $shopDetails]]
        );*/

        // die('amazon shop create');

        $count = 25;
        $activePage = 1;
        if (isset($userData['count'])) {
            $count = $userData['count'];
        }

        if (isset($userData['activePage'])) {
            $activePage = $userData['activePage'] - 1 ;
        }

//        $db = $userData['db'];
//        $filters = isset($userData['filter']) ? $userData : [];
//        $limit = $count;
//        $offset = ($limit * $activePage) - $limit;
//        $dateLimit = [];
//        if (isset($userData['dateFilter'])) {
//            foreach ($userData['dateFilter'] as $key => $value) {
//                if ($key !== 'u.last_login') {
//                    $dateLimit = [
//                        'field' => $key,
//                        'from' => $value['from'],
//                        'to' => $value['to']
//                    ];
//                }
//            }
//        }
//        if (isset($userData['uninstall']) &&
//            $userData['uninstall'] == 'true') {
//            $query = 'SELECT DISTINCT u.id, u.username, u.email, u.created_at, u.updated_at, u.status, u.confirmation FROM user as u';
//            $countQuery = 'SELECT COUNT(DISTINCT u.id) FROM user as u';
//            if (count($filters)) {
//                $conditionalQuery = self::search($filters);
//                $query .= ' AND ' . $conditionalQuery;
//                $countQuery .= ' AND ' . $conditionalQuery;
//            }
//            if (count($dateLimit)) {
//                $query .= ' AND ' . $dateLimit['field'] . ' BETWEEN "' . $dateLimit['from'] . '" AND "' . $dateLimit['to'] . '"';
//                $countQuery .= ' AND ' . $dateLimit['field'] . ' BETWEEN "' . $dateLimit['from'] . '" AND "' . $dateLimit['to'] . '"';
//            }
//            if (isset($userData['dateFilter']) && isset($userData['dateFilter']['u.last_login'])) {
//                $userarray = $this->di->getObjectManager()->get('\App\Frontend\Components\AdminHelper')->getUserLastloginFilter($userData['dateFilter']['u.last_login']);
//                if (count($userarray) > 0) {
//                    $query .= ' AND u.id IN (' . implode(',', $userarray) . ')';
//                    $countQuery .= ' AND u.id IN (' . implode(',', $userarray) . ')';
//                }
//            }
//            $query .= ' ORDER BY u.created_at DESC';
//            $query .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
//            $connection = $this->di->get($db);
//            $users = $connection->fetchAll($query);
//            $usersCount = $connection->fetchAll($countQuery);
//            $usersCount = $usersCount[0]['COUNT(DISTINCT u.id)'];
//            foreach ($users as $key => $userdata) {
//                $lastLogindetails = $this->di->getObjectManager()->get('\App\Frontend\Components\Helper')->getLastLoginDetails($userdata['id']);
//                if ($lastLogindetails['success']) {
//                    $users[$key]['last_login'] = $lastLogindetails['data']['last_login'];
//                } else {
//                    $users[$key]['last_login'] = 'Not available';
//                }
//
//            }
//            return ['success' => true, 'message' => '', 'data' => ['rows' => $users, 'count' => $usersCount]];
//        } else {
//            $query = "SELECT u.id, u.username, u.email, u.created_at, u.updated_at, u.status, u.confirmation FROM user as u ";
//            $countQuery = "SELECT COUNT(*) FROM user as u";
//            if (count($filters)) {
//                $conditionalQuery = self::search($filters);
//                $query .= ' WHERE ' . $conditionalQuery;
//                $countQuery .= ' WHERE ' . $conditionalQuery;
//            }
//            if (count($dateLimit)) {
//                if (count($filters)) {
//                    $query .= ' AND ' . $dateLimit['field'] . ' BETWEEN "' . $dateLimit['from'] . '" AND "' . $dateLimit['to'] . '"';
//                    $countQuery .= ' AND ' . $dateLimit['field'] . ' BETWEEN "' . $dateLimit['from'] . '" AND "' . $dateLimit['to'] . '"';
//                } else {
//                    $query .= ' WHERE ' . $dateLimit['field'] . ' BETWEEN "' . $dateLimit['from'] . '" AND "' . $dateLimit['to'] . '"';
//                    $countQuery .= ' WHERE ' . $dateLimit['field'] . ' BETWEEN "' . $dateLimit['from'] . '" AND "' . $dateLimit['to'] . '"';
//                }
//            }
//            if (isset($userData['dateFilter']) && isset($userData['dateFilter']['u.last_login'])) {
//                $userarray = $this->di->getObjectManager()->get('\App\Frontend\Components\AdminHelper')->getUserLastloginFilter($userData['dateFilter']['u.last_login']);
//                if (count($userarray) > 0) {
//                    $query .= ' AND u.id IN (' . implode(',', $userarray) . ')';
//                    $countQuery .= ' AND u.id IN (' . implode(',', $userarray) . ')';
//                }
//            }
//            $query .= ' ORDER BY u.created_at DESC';
//            $query .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
//
        ////            print_r($query);die;
//            $connection = $this->di->get($db);
//            $users = $connection->fetchAll($query);
//            $usersCount = $connection->fetchAll($countQuery);
//            $usersCount = $usersCount[0]['COUNT(*)'];
//
//            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//            $mongo->setSource("user_details");
//            $collection = $mongo->getCollection();
//
//            foreach ($users as $key => $userdata) {
//                $users[$key] = $this->getUSerDetailsFromMongo($userdata, $collection);
//            }
//            return ['success' => true, 'message' => '', 'data' => ['rows' => $users, 'count' => $usersCount]];

        // $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        // $mongo->setSource("user_details");
        // $collection = $mongo->getCollection();
        $mongo          = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection     = $mongo->getCollectionFortable('user_details');
        $prodContainer  = $mongo->getCollectionForTable('product_container');
        $orderContainer = $mongo->getCollectionForTable('order_container');
        $customerId     = Role::findFirst( [ 'code' => 'customer' ] )->toArray()['_id'] ?? false;
        // print_r($userData);
        // die('->>');
        $skip=(int)$count*$activePage;

        $filters = isset($userData['filter']) ? $userData : [];
        $conditionalQuery = ['role_id'=>$customerId];
//        if (count($filters)) {
//            $conditionalQuery = self::searchMongo($filters);
//            $aggregate=[['$match'=>$conditionalQuery],['$addFields'=>['user_ids'=>['$toInt'=>'$user_id']]],['$sort'=>['user_ids'=>-1]],['$skip'=>$skip],['$limit'=>(int)$count]];
//        }else{
//            $aggregate=[['$addFields'=>['user_ids'=>['$toInt'=>'$user_id']]],['$sort'=>['user_ids'=>-1]],['$skip'=>$skip],['$limit'=>(int)$count]];
//        }
        if (count($filters)) {
            $conditionalQuery = self::searchMongo($filters);
            $aggregate=[['$match'=>$conditionalQuery],['$skip'=>$skip],['$limit'=>(int)$count]];
        } else {
            $aggregate=[['$match'=>['role_id'=>$customerId]],['$skip'=>$skip],['$limit'=>(int)$count]];
        }

        $k=json_decode( toJSON( fromPHP( $collection->aggregate($aggregate)->toArray() ) ), true );



        foreach ($k as $key => $value) {
            if ( empty( $value['shops'] ) ) {
                $k[$key]['shops'] = [];
            }

            $k[$key]['id'] = $k[$key]['user_id'];
            if (isset($k[$key]['shops'][0]['form_details'])) {
                $k[$key]['catalog']=$k[$key]['shops'][0]['form_details']['catalog_sync_only'];
            } else {
                $k[$key]['catalog']='';
            }

            $k[$key]['email']=$k[$key]['shops'][0]['email'] ?? ( $k[$key]['email']?? 'NA');
            $k[$key]['shop_id']=$k[$key]['shops'][0]['remote_shop_id'] ?? 'NA';
            $k[$key]['shop_url']=$k[$key]['shops'][0]['myshopify_domain'] ?? 'NA';
            $k[$key]['activation_date'] = ! empty( $k[$key]['created_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $k[$key]['created_at'] ) )  : 'NA';
            $k[$key]['created_at']=! empty( $k[$key]['shops'][0]['created_at']['$date'] ) ? gmdate( 'Y-m-d H:i:s', $k[$key]['shops'][0]['created_at']['$date'] / 1000 ) : (! empty( $k[$key]['shops'][0]['created_at'] ) ? $k[$key]['shops'][0]['created_at'] : 'NA');
            $k[$key]['updated_at']=! empty( $k[$key]['shops'][0]['updated_at']['$date'] ) ? gmdate( 'Y-m-d H:i:s', $k[$key]['shops'][0]['updated_at']['$date'] / 1000 ) : (! empty( $k[$key]['shops'][0]['updated_at'] ) ? $k[$key]['shops'][0]['updated_at'] : 'NA');
            $k[$key]['shopify_plan']=$k[$key]['shops'][0]['plan_name'] ?? 'NA';
            $k[$key]['user_details']['mobile']=$k[$key]['shops'][0]['phone'] ?? 'NA';
            $k[$key]['user_details']['full_name']=($k[$key]['shops'][0]['shop_owner'] ?? '')."(".($k[$key]['shops'][0]['name'] ?? '').")";
            $k[$key]['user_details']['primary_time_zone']=$k[$key]['shops'][0]['timezone'] ?? 'NA';
            if (isset($k[$key]['shops'][0]['warehouses'][1])) {
                $k[$key]['facebook'] = $k[$key]['shops'][0]['warehouses'][1];
                unset($k[$key]['shops'][0]['warehouses'][1]);
            }

            $k[$key]['shopify'] = $k[$key]['shops'][0] ?? 'NA';
            $k[$key]['product_count'] = $prodContainer->count(['user_id' => $value['user_id']]);
            $k[$key]['order_count'] = $orderContainer->count(['user_id' => $value['user_id']]);
            // unset($k[$key]['shops']);
        }

        return ['success' => true, 'message' => '', 'data' => ['rows' =>$k,
                'count' =>$collection->count($conditionalQuery)]];
    }

    public static function searchMongo($filterParams = [])
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim((string) $key);
                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    $conditions[$key] = self::checkInteger($key, $value[self::IS_EQUAL_TO]);
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[$key] =  ['$ne' => self::checkInteger($key, trim((string) $value[self::IS_NOT_EQUAL_TO]))];
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
                    } elseif (trim((string) $value[self::RANGE]['to']) &&
                        !trim((string) $value[self::RANGE]['from'])) {
                        $conditions[$key] =  ['$lte' => self::checkInteger($key, trim((string) $value[self::RANGE]['to']))];
                    } else {
                        $conditions[$key] =  [
                            '$gte' => self::checkInteger($key, trim((string) $value[self::RANGE]['from'])),
                            '$lte' => self::checkInteger($key, trim((string) $value[self::RANGE]['to']))
                        ];
                    }
                }

                /*if($key == 'variants.quantity'){
                    $conditions[$key] = null;
                }*/
            }
        }

        if (isset($filterParams['search'])) {
            $conditions['$text'] = ['$search' => self::checkInteger($key, trim(addslashes((string) $filterParams['search'])))];
        }

        return $conditions;
    }

    public static function checkInteger($key, $value)
    {
        if ($key == 'variants.price' ||
            $key == 'variants.quantity') {
            $value = trim((string) $value);
            return (float)$value;
        }

        if ($key=='shops.0.form_details.catalog_sync_only') {
            return (int)$value;
        }

        return trim((string) $value);
    }

    public function getAllAmazonUsers($userData)
    {
        // $id = '34069315092617';
        // $locations = [[
        //     "admin_graphql_api_id" => "gid://shopify/InventoryLevel/74951557257?inventory_item_id=34069315092617",
        //     "updated_at" => "2020-02-25T14:38:06Z",
        //     "available" => 2010,
        //     "location_id" => "41102737545",
        //     "inventory_item_id" => "34069315092617"
        // ]];
        // $user_id = '6076e2840c4e5d306804a7b3';
        // $this->di->getObjectManager()->get("App\Shopifyhome\Components\Product\Inventory\Data")->updateProductVariantInv($id, $locations, $user_id);
        // die('yahna pr');

        $count = $userData['count'] ?? 25;
        $activePage = isset($userData['activePage']) ? $userData['activePage']-1 : 0;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //$mongo->setSource("user_details");
        $collection = $mongo->getCollectionForTable('user_details');

        $skip=(int)$count*$activePage;

        $filters = isset($userData['filter']) ? $userData : [];

        $conditionalQuery = ['shops.marketplace' => 'shopify'];

        if (count($filters)) {
            $conditionalQuery = array_merge($conditionalQuery, self::searchMongo($filters));
        }

        $facet = [
            'users'         => [['$skip'=>$skip],['$limit'=>(int)$count]],
            'total_users'   => [['$count' => 'count']]
        ];

        // $aggregate = [['$match'=>$conditionalQuery],['$skip'=>$skip],['$limit'=>(int)$count]];

        $aggregate = [['$match'=>$conditionalQuery],['$facet'=>$facet]];

        $facetResp = $collection->aggregate($aggregate)->toArray();

        $allUsersData = [];
        if (isset($facetResp[0]['users'])) {
            $users = $facetResp[0]['users'];

            foreach ($users as $user) {
                $shopifyData = [];

                if (isset($user['shops'])) {
                    $shops = $user['shops'];
                    foreach ($shops as $shop) {
                        if ($shop['marketplace'] == 'shopify') {
                            $shopifyData = $shop;
                        }
                    }

                    if (!empty($shopifyData)) {
                        $userData = [
                            'id'                => $user['user_id'],
                            'user_id'           => $user['user_id'],
                            'username'          => $user['username'],
                            'email'             => $user['email'],
                            'shop_url'          => $shopifyData['myshopify_domain'],
                            'shopify_plan'      => $shopifyData['plan_name'],
                            'user_details'      => [
                                'mobile'            => $shopifyData['phone'] ?? '',
                                'full_name'         => "{$shopifyData['shop_owner']}({$shopifyData['name']})",
                                'primary_time_zone' => $shopifyData['timezone']
                            ]
                        ];

                        foreach ($shops as $shop) {
                            if (!empty($shop['marketplace'])) {
                                $userData[$shop['marketplace']] = $shop;
                            }
                        }

                        $allUsersData[] = $userData;
                    }
                }
            }
        }

        $user_count = $facetResp[0]['total_users'][0]['count'] ?? 0;

        return ['success' => true, 'message' => '', 'data' => ['rows' =>$allUsersData, 'count' => $user_count]];
    }

    public function getUSerDetailsFromMongo($user, $collection)
    {
        $user_data = $collection->findOne(['user_id' => (string)$user['id']]);
        if (count($user_data)) {
            foreach ($user_data['shops'] as $shopData) {
                if ($shopData['marketplace'] == 'shopify') {
                    $user['shop_id'] = $shopData['remote_shop_id'];
                    $user['shop_url'] = $shopData['myshopify_domain'];
                    $user['shopify_plan'] = $shopData['plan_display_name'];
                    $user['user_details'] = [
                        'mobile' => $shopData['phone'],
                        'full_name' => $shopData['shop_owner'] . ' ( ' . $shopData['name'] . ' )',
                        'primary_time_zone' => $shopData['timezone'],
                    ];
                }

                $user[$shopData['marketplace']] = $shopData;
            }
        }

        return $user;
    }

    /**
     * Will update user active step ( modified ).
     *
     * @param array $params array containing the required params.
     * @since 4.0.0
     * @return array
     */
    public function updateUserStep( $params ) {
        if ( ! isset( $params['user_id'] ) || ! isset( $params['step_completed'] ) ) {
            return [ 'success' => false, 'msg' => 'Required Params missing' ];
        }

        $mongo      = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $collection->updateOne( [ 'user_id' => $params['user_id'] ], [ '$set' => [ 'default.step_completed' => $params['step_completed'] ] ] );
        return [ 'success' => true, 'msg' => 'User step updated successfully' ];
    }

    public function getUser($userData)
    {
        $userId = $userData['user_id'];
        $db = $userData['db'];
        $connectedTables = $this->di->getConfig()->connected_tables;
        $joins = [];
        $joinConditions = [];
        $joinColumns = [];
        $additionalFields = [];
        if (isset($connectedTables[$db])) {
            foreach ($connectedTables[$db] as $value) {
                $joins[] = ' INNER JOIN ' . $value['table'] . ' as ' . $value['abbreviation'] . ' ';
                $joinConditions[] = ' AND u.id = ' . $value['abbreviation'] . '.user_id ';
                $joinColumns[] = ' , ' . $value['abbreviation'] . '.' . $value['field'] . ' as ' . $value['prefix'] . '_shop ';
                $additionalFields[$value['prefix'] . '_shop'] = [];
            }
        }

        $query = 'SELECT u.id, u.username, u.email, u.created_at, u.updated_at, u.status, u.confirmation ' . implode(' ', $joinColumns) . ' FROM user as u WHERE u.id = ' . $userId . implode(' ', $joinConditions);
        $userDataQuery = 'SELECT * FROM user_data WHERE user_id = ' . $userId;
        $registrationStepsQuery = 'SELECT * FROM user_config_data WHERE user_id = ' . $userId . ' AND path = "/App/User/Step/ActiveStep"';
        $connection = $this->di->get($db);
        $user = $connection->fetchAll($query);
        $userData = $connection->fetchAll($userDataQuery);
        $registrationSteps = $connection->fetchAll($registrationStepsQuery);
        $additionalUserData = [];
        foreach ($userData as $key => $value) {
            $additionalUserData[$value['key']] = $value['value'];
        }

        if (!count($user)) {
            $query = 'SELECT u.id, u.username, u.email, u.created_at, u.updated_at, u.status, u.confirmation FROM user as u WHERE u.id = ' . $userId;
            $user = $connection->fetchAll($query);
        }

        $mainUserData = $user[0];
        foreach ($user as $userInfo) {
            foreach ($userInfo as $key => $value) {
                if (isset($additionalFields[$key])) {
                    $additionalFields[$key][$value] = $value;
                } elseif ($key == 'shopify_shop') {
                    $additionalFields[$key][$value] = $value;
                }
            }
        }

        foreach ($mainUserData as $key => $value) {
            if (isset($additionalFields[$key])) {
                $mainUserData[$key] = array_values($additionalFields[$key]);
            }
        }

        $mainUserData['user_details'] = $additionalUserData;
        $mainUserData['registration_step'] = count($registrationSteps) ? $registrationSteps[0]['value'] : 0;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //$mongo->setSource("user_details");
        $collection = $mongo->getCollectionForTable('user_details');

        $mainUserData = $this->getUSerDetailsFromMongo($mainUserData, $collection);

        return ['success' => true, 'data' => $mainUserData];
    }

    public function getLoginAsUserUrl($userData)
    {
        $userId = $userData['user_id'];
        $db = $userData['db'] ?? '';

        $user = User::findFirst([['user_id'=>$userId]]);
        if ($user) {
            $date = new DateTime('+4 hour');
            $tokenObject = [
                "user_id" => (string)$userId,
                "role" => 'customer',
                "exp" => $date->getTimestamp()
            ];

            $token = $this->di->getObjectManager()->get('\App\Core\Components\Helper')->getJwtToken($tokenObject, 'RS256', true);

            $frontendAppDetails = $this->di->getConfig()->frontend_app_details ?? [];
            if ($db && isset($frontendAppDetails[$db])) {
                $url = $frontendAppDetails[$db]['frontend_app_url'] . '?admin_user_token=' . $token;
            } else {
                $shops = $user->shops ?? [];
                $marketplace = $shops[0]['marketplace'] ?? false;
                $ignore_global_frontend_url = $this->di->getConfig()->get('apiconnector')->$marketplace->$marketplace->ignore_global_frontend_url ?? false;
                if( $ignore_global_frontend_url ) {
                    $frontedURL= $this->di->getConfig()->get('apiconnector')->$marketplace->$marketplace->frontend_app_url ?? false;
                    $url = $frontedURL . '?admin_user_token=' . $token;
                }
                else{
                    $frontedURL=$this->di->getConfig()->frontend_app_url ?? false;
                    $url = $frontedURL . 'auth/login?admin_user_token=' . $token;
                }
            }

            //$this->updateLastactivitytime( $userId );
            return ['success' => true, 'data' => $url];
        }

        return ['success' => false, 'message' => 'User not found', 'code' => 'user_not_found'];
    }


    /**
     * update last login time and activity(modified).
     *
     * @param array $userId current user id.
     * @since 2.0.0
     */
    public function updateLastactivitytime( $userId ): void {
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $user_details->setConfigByKey('last_activity_time', gmdate( 'Y-m-d H:i:s' ), $userId);

    }

    public function getPlans($dbDetails)
    {
        $db = $dbDetails['db'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->initializeDb($db);
        //$mongo->setSource("plan");
        $collection = $mongo->getCollectionForTable('plan');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        if (isset($dbDetails['plan_id'])) {
            $planId = $dbDetails['plan_id'];
            $data = $collection->findOne(['plan_id' => $planId], $options);
        } else {
            $params = $dbDetails;
            $data = $mongo->getFilteredResults('plan', $params);
        }

        return ['success' => true, 'data' => $data];
    }

    public function getAllShops($dbDetails)
    {
        $db = $dbDetails['db'];
        $connection = $this->di->get($db);
        $shopQuery = 'SELECT id, user_id, shop_url FROM shopify_shop_details';
        $shops = $connection->fetchAll($shopQuery);
        return ['success' => true, 'data' => $shops];
    }

    public function getPaymentLink($shopDetails)
    {
        $db = $shopDetails['db'];
        $userDetails = $shopDetails['shop_details'];
        $planDetails = $shopDetails['plan_details'];
        $testPayment = isset($shopDetails['test_payment']) ? ($shopDetails['test_payment'] === 'true') : false;
        $planId = $planDetails['plan_id'];
        $userId = $userDetails['user_id'];
        $shop = $userDetails['shop_url'];
        $connector = $planDetails['connectors'];
        $amount = $planDetails['main_price'];
        $description = $planDetails['title'];
        $services = $planDetails['services'];
        $type = 'recurrying';
        if ($planDetails['validity'] == 365) {
            $type = 'onetime';
        }

        $allPaymentMethods = $this->di->getConfig()->payment_methods;
        $allPaymentMethods = $allPaymentMethods->toArray();

        $toReturnData = [];
        if (isset($allPaymentMethods[$connector])) {
            $connectorPaymentMethod = $allPaymentMethods[$connector];
            $paymentMethod = array_values($connectorPaymentMethod)[0];
            $schema = ['shop' => $shop];
            return $this->processPayment($userId, $db, $description, $amount, $services, $type, $planId, $paymentMethod, [], $testPayment);
        }

        return ['success' => false, 'message' => 'No payment method found', 'code' => 'no_payment_method_found'];
    }

    public function processPayment($userId, $db, $description, $amount, $services, $type, $planId, $paymentMethod, $schema = [], $testPayment = false)
    {
        $toReturnData = [];
        $transactionId = $this->createTransactionLog($description, $amount, 'pending', $services, $userId, $db);
        if ($transactionId) {
            if ($paymentMethod['type'] == 'redirect') {
                $confirmationUrl = $this->di->getObjectManager()->get($paymentMethod['source_model'])->getConfirmationUrl($amount, $type, $planId, $description, $transactionId, $userId, $schema, $testPayment);
                if ($confirmationUrl) {
                    $toReturnData['confirmation_url'] = $confirmationUrl;
                    return ['success' => true, 'data' => $toReturnData];
                }

                return ['success' => false, 'message' => 'Failed to process payment', 'code' => 'failed_to_process_payment'];
            }
            $paymentStatus = $this->di->getObjectManager()->get($paymentMethod['source_model'])->makePayment($amount, $type, $planId, $description, $transactionId, $userId, $schema);
            if ($paymentStatus) {
                $toReturnData['payment_done'] = true;
                return ['success' => true, 'data' => $toReturnData];
            }

            return ['success' => false, 'message' => 'Failed to process payment', 'code' => 'failed_to_process_payment'];
        }
        return ['success' => false, 'message' => 'Transaction failed', 'code' => 'transaction_failed'];
    }

    public function createTransactionLog($description, $amount, $paymentStatus = 'pending', $services = [], $userId = false, $db = 'db_express')
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $baseMongo->initializeDb($db);
        //$baseMongo->setSource("transaction_log");
        $collection = $baseMongo->getCollectionForTable('transaction_log');
        $transactionData = [
            '_id' => (string)$baseMongo->getCounter('transaction_id', $userId),
            'amount' => $amount,
            'created_at' => date("Y-m-d H:i:s"),
            'services' => $services,
            'description' => $description,
            'user_id' => $userId,
            'payment' => $paymentStatus
        ];
        $transactionLog = $this->di->getObjectManager()->get('\App\Payment\Models\TransactionLog');
        $transactionLog->setData($transactionData);

        $status = $transactionLog->save();
        if ($status) {
            return $transactionData['_id'];
        }

        return false;
    }

    public function getServiceCredits($userDetails)
    {
        $userId = $userDetails['user_id'];
        $db = false;
        if (isset($userDetails['db'])) {
            $db = $userDetails['db'];
        }

        $serviceHelper = $this->di->getObjectManager()->get('\App\Connector\Components\Services');
        $credits = [];
        if (!isset($userDetails['service_codes'])) {
            $service = $serviceHelper->getServiceModel($serviceHelper->getByCode('google_uploader'), $userId, $db);
            $credits = [
                'available_credits' => $service->getAvailableCredits(),
                'total_used_credits' => $service->getUsedCredits()
            ];
        } else {
            $serviceCodes = $userDetails['service_codes'];
            foreach ($serviceCodes as $value) {
                $service = $serviceHelper->getServiceModel($serviceHelper->getByCode($value), $userId, $db);
                $credits[$value] = [
                    'available_credits' => $service->getAvailableCredits(),
                    'total_used_credits' => $service->getUsedCredits()
                ];
            }
        }

        return ['success' => true, 'data' => $credits];
    }

    public function updateUsedCredits($userDetails)
    {
        $userId = $userDetails['user_id'];
        $creditsUsed = $userDetails['credits_used'];
        $serviceHelper = $this->di->getObjectManager()->get('\App\Connector\Components\Services');
        $service = $serviceHelper->getServiceModel($serviceHelper->getByCode('google_uploader'), $userId);
        $response = $service->updateUsedCredits($creditsUsed);
        if ($response) {
            return ['success' => true, 'message' => 'Credits are updated successfully'];
        }

        return ['success' => false, 'message' => 'Failed to update credits'];
    }

    public function addPlanAndServices($planDetails)
    {
        $userId = $planDetails['user_id'];
        $planId = $planDetails['plan_id'];
        $services = $planDetails['services'];
        $mainPrice = $planDetails['main_price'];
        $db = 'db_express';
        if (isset($planDetails['db'])) {
            $db = $planDetails['db'];
        }

        $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $baseMongo->initializeDb($db);
        //$baseMongo->setSource("plan");
        $collection = $baseMongo->getCollectionForTable('plan');
        $selectedPlan = $collection->findOne([
            'plan_id' => (string)$planId
        ], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        $chargeId = 999999;
        $createdAt = date("Y-m-d H:i:s");
        $activeRecurrying = $this->getActiveRecurryingDetails($userId);
        if ($activeRecurrying) {
            $chargeId = $activeRecurrying['id'];
            preg_match('/\d{4}-\d{2}-\d{2}/', (string) $activeRecurrying['created_at'], $matches, PREG_OFFSET_CAPTURE);
            $createdAt = $matches[0][0];
        }

        $selectedPlan['merchant_id'] = $userId;
        $selectedPlan['services'] = $services;
        $selectedPlan['charge_id'] = $chargeId;
        $selectedPlan['main_price'] = $mainPrice;
        $selectedPlan['activated_at'] = $createdAt;
        $this->insertServicesByDb($services, $userId, $db);
        $this->createActivePlanByDb($selectedPlan, $db);
        return ['success' => true, 'message' => 'Plan and services successfully updated for this client'];
    }

    public function getPlan($planDetails)
    {
        $db = $planDetails['db'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->initializeDb($db);
        //$mongo->setSource("plan");
        $collection = $mongo->getCollectionForTable('plan');
        if ($collection) {
            $data = $collection->find();
            $data = $data->toArray();
            return ['success' => true, 'data' => $data];
        }
        return ['success' => false, 'message' => 'Invalid Data'];
    }

    public function getActivePlan($userDetails)
    {
        $userId = $userDetails['user_id'];
        $db = $userDetails['db'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->initializeDb($db);
        //$mongo->setSource("active_plan");
        $collection = $mongo->getCollectionForTable('active_plan');
        $activePlans = $collection->find([
            "merchant_id" => (string)$userId
        ],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if ($activePlans) {
            $activePlan = $activePlans->toArray()[count($activePlans) - 1];
            if ($activePlan) {
                return ['success' => true, 'data' => $activePlan];
            }
        }

        return ['success' => false, 'message' => 'You have no active plan', 'code' => 'no_active_plan'];
    }

    public function insertServicesByDb($services, $userId, $db = 'db_express')
    {
        $data = [];
        foreach ($services as $value) {
            $serviceData = [
                'code' => $value['code'],
                'merchant_id' => $userId,
                'type' => $value['type'],
                'charge_type' => $value['charge_type']
            ];
            if (isset($value['prepaid'])) {
                $serviceData['available_credits'] = (int)$value['prepaid']['service_credits'];
                $serviceData['total_used_credits'] = 0;
            }

            $data[] = $serviceData;
        }

        $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $baseMongo->initializeDb($db);
        //$baseMongo->setSource("user_service");
        $collection = $baseMongo->getCollectionForTable('user_service');
        $collection->deleteMany(["merchant_id" => (string)$userId]);
        $collection->insertMany($data);
        return true;
    }

    public function createActivePlanByDb($planData, $db = 'db_express')
    {
        $baseMongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $baseMongo->initializeDb($db);
        //$baseMongo->setSource('active_plan');
        $collection = $baseMongo->getCollectionForTable('active_plan');
        $collection->deleteMany(["merchant_id" => (string)$planData['merchant_id']]);
        unset($planData['_id']);
        $collection->insert($planData);
        return true;
    }

    public function getConfirmationUrlForOne($paymentData)
    {
        $amount = $paymentData['amount'];
        $userId = $paymentData['user_id'];
        $name = $paymentData['name'];
        $db = 'db_express';
        if (isset($paymentData['db'])) {
            $db = $paymentData['db'];
        }

        $apiKeys = $this->getApiKeys($db);
        $shopName = false;
        if ($db == 'db_express') {
            $data = Details::findFirst(["user_id='{$userId}'"]);
            $shopName = $data->getShopUrl();
            $token = $data->getToken();
        } else {
            $connection = $this->di->get($db);
            $shopQuery = 'SELECT id, token, shop_url FROM shopify_shop_details WHERE user_id = ' . $userId;
            $shops = $connection->fetchAll($shopQuery);
            $shopName = $shops[0]['shop_url'];
            $token = $shops[0]['token'];
        }

        if (!$shopName) {
            return ['success' => false, 'message' => 'Shop Not Found'];
        }

        $shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
            ['type' => 'parameter', 'value' => $shopName], ['type' => 'parameter', 'value' => $token], ['type' => 'parameter', 'value' => $apiKeys['auth_key']], ['type' => 'parameter', 'value' => $apiKeys['secret_key']]
        ]);
        $plan = [
            'application_charge' => [
                'name' => $name,
                'price' => $amount,
                'return_url' => $this->getReturnUrl($db, $shopName)
            ]
        ];
        $response = $shopifyClient->call('POST', '/admin/application_charges.json', $plan);
        if ($response && !(isset($response['errors']))) {
            return ['success' => true, 'data' => $response['confirmation_url'], 'rawData' => $response];
        }
        $message = '';
        if (is_array($response['errors'])) {
            $message = implode(',', $response['errors']);
        } else {
            $message = $response['errors'];
        }
        return ['success' => false, 'message' => $message];
    }

    public function getRecurryingConfirmationUrlForOne($paymentData)
    {
        $amount = $paymentData['amount'];
        $userId = $paymentData['user_id'];
        $name = 'App + digital-marketing';
        $db = 'db_express';
        if (isset($paymentData['db'])) {
            $db = $paymentData['db'];
        }

        $apiKeys = $this->getApiKeys($db);
        $shopName = false;
        if ($db == 'db_express') {
            $data = Details::findFirst(["user_id='{$userId}'"]);
            $shopName = $data->getShopUrl();
            $token = $data->getToken();
        } else {
            $connection = $this->di->get($db);
            $shopQuery = 'SELECT id, token, shop_url FROM shopify_shop_details WHERE user_id = ' . $userId;
            $shops = $connection->fetchAll($shopQuery);
            $shopName = $shops[0]['shop_url'];
            $token = $shops[0]['token'];
        }

        if (!$shopName) {
            return ['success' => false, 'message' => 'Shop Not Found'];
        }

        $shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
            ['type' => 'parameter', 'value' => $shopName], ['type' => 'parameter', 'value' => $token], ['type' => 'parameter', 'value' => $apiKeys['auth_key']], ['type' => 'parameter', 'value' => $apiKeys['secret_key']]
        ]);
        $planId = 5;
        $transactionId = $userId;
        $type = 'recurrying';
        $plan = [
            'recurring_application_charge' => [
                'name' => $name,
                'price' => $amount,
                'return_url' => $this->di->getUrl()->get('shopify/payment/check?plan_id=' . $planId . '&log_id=' . $transactionId . '&type=' . $type . '&shop=' . $shopName)
            ]
        ];
        $response = "";
        $response = $shopifyClient->call('POST', '/admin/recurring_application_charges.json', $plan);
        if ($response && !(isset($response['errors']))) {
            return ['success' => true, 'data' => $response['confirmation_url']];
        }
        $message = '';
        if (is_array($response['errors'])) {
            $message = implode(',', $response['errors']);
        } else {
            $message = $response['errors'];
        }
        return ['success' => false, 'message' => $message];
    }

    public function getApiKeys($db)
    {
        $tokens = [
            'auth_key' => false,
            'secret_key' => false
        ];
        switch ($db) {
            case 'db_express':
                $tokens = [
                    'auth_key' => $this->di->getConfig()->security->shopify->auth_key,
                    'secret_key' => $this->di->getConfig()->security->shopify->secret_key
                ];
                break;

            default:
                if ($this->di->getConfig()->get('other_app_secret_keys')) {
                    $otherAppSecretKeys = $this->di->getConfig()->other_app_secret_keys;
                    $otherAppSecretKeys = $otherAppSecretKeys->toArray();
                    if (isset($otherAppSecretKeys[$db])) {
                        $tokens = [
                            'auth_key' => $otherAppSecretKeys[$db]['auth_key'],
                            'secret_key' => $otherAppSecretKeys[$db]['secret_key']
                        ];
                    }
                }

                break;
        }

        return $tokens;
    }

    public function getReturnUrl($db, $shopName)
    {
        return match ($db) {
            'db_importer' => 'https://importer.sellernext.com/frontend/admin/adminCheck?shop=' . $shopName,
            default => $this->di->getUrl()->get('frontend/admin/adminCheck?shop=' . $shopName),
        };
    }

    public function getTempPlansAndServices($userDetails)
    {
        return ['success' => true];
        $userId = $userDetails['user_id'];
        $db = $userDetails['db'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->initializeDb($db);
        //$mongo->setSource("active_plan_temp");
        $collection = $mongo->getCollectionForTable('active_plan_temp');
        $activePlans = $collection->find([
            "merchant_id" => (string)$userId
        ]);
        if ($activePlans) {
            $activePlans = $activePlans->toArray();
        } else {
            $activePlans = [];
        }

        //$mongo->setSource("user_service_temp");
        $collection = $mongo->getCollectionForTable('active_plan_temp');
        $userServices = $collection->find([
            "merchant_id" => (string)$userId
        ]);
        if ($userServices) {
            $userServices = $userServices->toArray();
        } else {
            $userServices = [];
        }

        $data = [
            'user_plans' => $activePlans,
            'user_services' => $userServices
        ];
        return ['success' => true, 'data' => $data];
    }

    public function getTempData($userDetails)
    {
        $userId = $userDetails['user_id'];
        $db = $userDetails['db'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->initializeDb($db);
        //$mongo->setSource('user_temp_' . $userId);
        $collection = $mongo->getCollectionForTable('user_temp_' . $userId);
        $response = $collection->find();
        $response = $response->toArray();
        if (count($response)) {
            $tempTableData = $response[0];
            return ['success' => true, 'data' => $tempTableData];
        }

        $tempTableData = [
            'steps_completed' => 0,
            'trial_days_used' => 0
        ];
        return ['success' => true, 'data' => $tempTableData];
    }

    public function cancelRecurryingCharge($userDetails)
    {
        $userId = $userDetails['user_id'];
        $shopData = Details::findFirst(["user_id='{$userId}'"]);
        if ($shopData) {
            $shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
                ['type' => 'parameter', 'value' => $shopData->shop_url], ['type' => 'parameter', 'value' => $shopData->token], ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->auth_key], ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->secret_key]
            ]);
            $response = $shopifyClient->call('GET', '/admin/recurring_application_charges.json');
            $chargeId = false;
            foreach ($response as $value) {
                if ($value['status'] == 'active') {
                    $chargeId = $value['id'];
                    break;
                }
            }

            if ($chargeId) {
                $shopifyClient->call('DELETE', '/admin/recurring_application_charges/' . $chargeId . '.json');
                return ['success' => true, 'message' => 'Recurrying charge cancelled successfully'];
            }

            return ['success' => false, 'message' => 'No active recurrying found.'];
        }

        return ['success' => false, 'message' => 'Shop details not found.'];
    }

    public function getActiveRecurrying($userDetails)
    {
        $userId = $userDetails['user_id'];
        $activeRecurrying = $this->getActiveRecurryingDetails($userId);
        if ($activeRecurrying) {
            return ['success' => true, 'data' => $activeRecurrying];
        }

        return ['success' => false, 'message' => 'No active recurrying found.'];
    }

    public function getActiveRecurryingDetails($userId)
    {
        $shopData = Details::findFirst(["user_id='{$userId}'"]);
        if ($shopData) {
            $shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
                ['type' => 'parameter', 'value' => $shopData->shop_url], ['type' => 'parameter', 'value' => $shopData->token], ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->auth_key], ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->secret_key]
            ]);
            $response = $shopifyClient->call('GET', '/admin/recurring_application_charges.json');
            $activeRecurrying = false;
            foreach ($response as $value) {
                if (isset($value['status']) && $value['status'] == 'active') {
                    $activeRecurrying = $value;
                    break;
                }
            }

            return $activeRecurrying;
        }

        return false;
    }

    public function getBigServiceCredits($userDetails)
    {
        $userId = $userDetails['user_id'];
        $db = false;
        if (isset($userDetails['db'])) {
            $db = $userDetails['db'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->initializeDb($db);
        //$mongo->setSource("product_container_" . $userId);
        $collection = $mongo->getCollectionForTable('product_container_');
        $total_used_count = $collection->count();

        $connection = $this->di->get($db);
        $exeQuery = 'SELECT `product_limit` FROM bigcommerce_shop_details WHERE user_id = ' . $userId;
        $exeResult = $connection->fetchOne($exeQuery);
        $total_limit = $exeResult['product_limit'];

        $available_credits = $total_limit - $total_used_count;

        $credits = [
            'available_credits' => $available_credits,
            'total_used_credits' => $total_used_count
        ];
        return ['success' => true, 'data' => $credits];
    }

    public function updateBigUsedCredits($userDetails)
    {
        $userId = $userDetails['user_id'];
        $creditsUsed = $userDetails['credits_used'];
        $db = $userDetails['db'];

        $connection = $this->di->get($db);
        $exeQuery = 'UPDATE bigcommerce_shop_details SET `product_limit`=' . $creditsUsed . ' WHERE `user_id` = ' . $userId;
        $connection->query($exeQuery);
        return ['success' => true, 'message' => 'Chnages Successfully Updated.'];
    }

    public function updateBigcomPlan($details)
    {
        $userId = $details['user_id'];
        $db = $details['db'];
        $plan = $details['plan'];
        $getPlan = $this->getWholePlan($plan, $userId);

        if ($db === 'db_bigcommerce') {
            $table = 'bigcommerce_shop_details';
        }

        $connection = $this->di->get($db);
        $exeQuery = "UPDATE `bigcommerce_shop_details` SET `active_plan`='" . $getPlan . "' WHERE `user_id` = " . $userId;
        $connection->query($exeQuery);
        return ['success' => true, 'message' => 'Plan Upgraded!'];
    }

    public function getBigcomPlan($details)
    {
        $userId = $details['user_id'];
        $db = $details['db'];
        if ($db === 'db_bigcommerce') {
            $table = 'bigcommerce_shop_details';
        }

        $connection = $this->di->get($db);
        $exeQuery = "SELECT `active_plan` FROM `bigcommerce_shop_details` WHERE `user_id` = " . $userId;
        $data = $connection->fetchOne($exeQuery);
        $response = [];
        if (isset($data['active_plan']) && $data['active_plan'] != '') {
            $data = json_decode((string) $data['active_plan'], true);
            $response['data'] = $data['validity'];
            return $response;
        }
        return ['data' => false];

        return ['data' => false];
    }

    public function getWholePlan($plan, $userid)
    {
        if ($plan == 365) {
            $title = 'Yearly Plan';
            $main_price = '449-499';
            $validity = $plan;
            $merchant_id = $userid;
            $activated_at = date('m/d/Y h:i:s a', time());
        } elseif ($plan == 180) {
            $title = 'Half-Yearly Plan';
            $main_price = '299-329';
            $validity = $plan;
            $merchant_id = $userid;
            $activated_at = date('m/d/Y h:i:s a', time());
        } elseif ($plan == 90) {
            $title = 'Quarterly Plan';
            $main_price = '149-199';
            $validity = $plan;
            $merchant_id = $userid;
            $activated_at = date('m/d/Y h:i:s a', time());
        }

        $data = ['title' => $title, 'description' => 'Upload Upto 10,000 SKU. Sync unlimited orders from Express to BigCommerce.', 'validity' => $validity, 'connectors' => 'bigcommerce', 'custom_price' => $main_price, 'discount_type' => 'Fixed', 'discount' => '1', 'services_groups' => ['0' => ['title' => 'Services', 'description' => 'Services for ' . $title, 'services' => ['0' => ['title' => 'Unlimited orders sync', 'code' => 'shopify_importer', 'type' => 'importer', 'charge_type' => 'Prepaid', 'required' => '1', 'service_charge' => '0', 'prepaid' => ['service_credits' => '10', 'validity_changes' => 'Replace', 'fixed_price' => $main_price, 'reset_credit_after' => '30', 'expiring_at' => '30'], 'postpaid' => ['per_unit_usage_price' => '', 'capped_amount' => '']], '1' => ['title' => 'Real Time Product Sync', 'code' => 'google_uploader', 'type' => 'uploader', 'charge_type' => 'Prepaid', 'required' => '1', 'service_charge' => '0', 'prepaid' => ['service_credits' => '10000', 'validity_changes' => 'Replace', 'fixed_price' => $main_price, 'reset_credit_after' => $validity, 'expiring_at' => $validity], 'postpaid' => ['per_unit_usage_price' => '', 'capped_amount' => '']]]]], 'plan_id' => '12', 'merchant_id' => $merchant_id, 'services' => ['0' => ['title' => 'Unlimited orders sync', 'code' => 'bigcommerce_importer', 'type' => 'importer', 'charge_type' => 'Prepaid', 'required' => '1', 'service_charge' => '0', 'prepaid' => ['service_credits' => '10', 'validity_changes' => 'Replace', 'fixed_price' => $main_price, 'reset_credit_after' => $validity, 'expiring_at' => $validity], 'postpaid' => ['per_unit_usage_price' => '', 'capped_amount' => '']], '1' => ['title' => 'Real Time Product Sync', 'code' => 'google_uploader', 'type' => 'uploader', 'charge_type' => 'Prepaid', 'required' => '1', 'service_charge' => '0', 'prepaid' => ['service_credits' => '10000', 'validity_changes' => 'Replace', 'fixed_price' => $main_price, 'reset_credit_after' => $validity, 'expiring_at' => $validity], 'postpaid' => ['per_unit_usage_price' => '', 'capped_amount' => '']], '2' => ['title' => 'Manage up-to 10,000 SKU(s)', 'code' => 'bigcommerce_importer', 'type' => 'uploader', 'charge_type' => 'Prepaid', 'required' => '1', 'service_charge' => '0', 'prepaid' => ['service_credits' => '10000', 'validity_changes' => 'Replace', 'fixed_price' => $main_price, 'reset_credit_after' => $validity, 'expiring_at' => $validity], 'postpaid' => ['per_unit_usage_price' => '', 'capped_amount' => '']], '3' => ['title' => 'Order Shipment', 'code' => 'order_shipment', 'type' => 'uploader', 'charge_type' => 'Prepaid', 'required' => '1', 'service_charge' => '0', 'prepaid' => ['service_credits' => '10000', 'validity_changes' => 'Replace', 'fixed_price' => $main_price, 'reset_credit_after' => $validity, 'expiring_at' => $validity], 'postpaid' => ['per_unit_usage_price' => '', 'capped_amount' => '']]], 'charge_id' => '999999', 'main_price' => $main_price, 'activated_at' => $activated_at];
        $data = json_encode($data);
        return $data;
    }

    /*        public function getActiveRecurryingOmni($userDetails) {
            $shopifyConfig = $this->di->getConfig()->get('shopifyConfig');
            $userId = $userDetails['user_id'];
            $db = $userDetails['db'];
            $connection = $this->di->get($db);
            $query = 'SELECT shop_url,token from shopify_shop_details where user_id='.$userId;
            $shop_details = $connection->fetchAll($query);
            if ($shop_details) {
                $shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
                    ['type' => 'parameter', 'value' => $shop_details[0]['shop_url']], ['type' => 'parameter', 'value' => $shop_details[0]['token']], ['type' => 'parameter', 'value' => '5b1d8296277176f72fcfbdb371c4a6e8'], ['type' => 'parameter', 'value' => '79d188a496d54675ded13c691f463ed6']
                ]);
                $response = $shopifyClient->call('GET', '/admin/api/'.$shopifyConfig['rest']['current']['version'].'/recurring_application_charges.json');
                  print_r($shop_details);
                die("he is dead again");
                $chargeId = false;
                foreach ($response as $key => $value) {
                    if ($value['status'] == 'active') {
                        $chargeId = $value['id'];
                        break;
                    }
                }
                if ($chargeId) {
                    $shopifyClient->call('DELETE', '/admin/api/'.$shopifyConfig['rest']['current']['version'].'/recurring_application_charges/' . $chargeId . '.json');
                    return ['success' => true, 'message' => 'Recurrying charge cancelled successfully'];
                }
                else{
                return ['success' => false, 'message' => 'No active recurrying found.'];
                }
            }
            else {
                return ['success' => false, 'message' => 'Shop details not found.'];
            }
        }*/
    public function getActiveRecurryingOmni($userDetails)
    {
        $shopifyConfig = $this->di->getConfig()->get('shopifyConfig');
        $userId = $userDetails['user_id'];
        $db = $userDetails['db'];
        $connection = $this->di->get($db);
        $query = 'SELECT shop_url,token from shopify_shop_details where user_id=' . $userId;
        $shop_details = $connection->fetchAll($query);
        if ($shop_details) {
            /*$shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
                ['type' => 'parameter', 'value' => $shop_details[0]['shop_url']], ['type' => 'parameter', 'value' => $shop_details[0]['token']], ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->auth_key], ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->secret_key]
            ]);
            $response = $shopifyClient->call('GET', '/admin/recurring_application_charges.json');*/
            $send_url = 'https://' . $shop_details[0]['shop_url'] . '/admin/api/' . $shopifyConfig['rest']['current']['version'] . '/recurring_application_charges.json';
            print_r($send_url);
            $token = $shop_details[0]['token'];
            $response = $this->sendGet($send_url, $token);
            $chargeId = false;
            foreach ($response['data']['recurring_application_charges'] as $value) {
                if ($value['status'] == 'active') {
                    $chargeId = $value['id'];
                    break;
                }
            }

            if ($chargeId) {
                $send_url = 'https://' . $shop_details[0]['shop_url'] . '/admin/api/' . $shopifyConfig['rest']['current']['version'] . '/recurring_application_charges/' . $chargeId . '.json';
                $response = $this->sendGet($send_url, $token, 'DELETE');
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $mongo->initializeDb("db_importer");
                //$mongo->setSource("user_service");
                $collection = $mongo->getCollectionForTable('user_service');
                $collection->deleteOne(['merchant_id' => (string)$userId, 'code' => 'product_sync']);
                //$mongo->setSource("active_plan");
                $collection = $mongo->getCollectionForTable('user_service');
                $collection->deleteOne(['merchant_id' => (string)$userId]);
                return ['success' => true, 'message' => 'Recurrying charge cancelled successfully', 'url' => $send_url];
            }
            return ['success' => false, 'message' => 'No active recurrying found.'];
        }
        return ['success' => false, 'message' => 'Shop details not found.'];
    }


    public function sendGet($serverUrl, $token = '', $type = 'GET')
    {
        $headers = [
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json'
        ];
        $client = new Client();
        $response = $client->request($type, $serverUrl, [
            'headers' => $headers,
            'http_errors' => false
        ]);
        return [
            'status_code' => $response->getStatusCode(),
            'header' => $response->getHeaders(),
            'data' => json_decode((string) $response->getBody()->getContents(), true)
        ];
    }

    public function getUserLastloginFilter($from_to)
    {

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('login_log');
        $start_format = (new DateTime($from_to['from']))->format('Y-m-d\TH:i:s\Z');
        $end_format = (new DateTime($from_to['to']))->format('Y-m-d\T23:59:59\Z');
        $user_ids = $collection->aggregate(
            [
                [
                    '$match' => [
                        'last_login' => [
                            '$gte' => $start_format
                            ,
                            '$lte' => $end_format
                        ],
                    ],
                ],
                [
                    '$group' => [
                        '_id' => null,
                        'user_array' => [
                            '$push' => '$user_id'
                        ]
                    ]
                ],


            ],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        )->toArray();
        if (count($user_ids) > 0) {
            $user_ids = $user_ids[0]['user_array'];
        } else {
            $user_ids = [-1];
        }

        return $user_ids;
    }
}