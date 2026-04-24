<?php

namespace App\Frontend\Components\AdminPanel;

use App\Core\Components\Base;
use MongoDB\BSON\ObjectId;
use App\Core\Models\User;
use DateTime;
use App\Frontend\Components\AdminpanelHelper;
use App\Amazon\Components\Common\Helper;
class ShopStatus extends Base
{
    public const IS_EQUAL_TO = 1;

    public const IS_NOT_EQUAL_TO = 2;

    public const IS_CONTAINS = 3;

    public const IS_NOT_CONTAINS = 4;

    public const START_FROM = 5;

    public const END_FROM = 6;

    public const RANGE = 7;

    public function ShopStatusCreateQueue($user_id = false)
    {
        if ( !$user_id ) {
            $user_id = $this->di->getUser()->id;
        }

        $handlerData = [
            'type' => 'full_class',
            'class_name' => \App\Frontend\Components\AdminPanel\ShopStatus::class,
            'method' => 'ShopStatusUpdate',
            'queue_name' => 'app_shop_status_update',
            'user_id' => $user_id,
            'data' => [
                'user_id' => $user_id,
                'cursor' => 0,
                'skip'=>0,
                'limit'=>50
            ]
        ];
        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        $rmqHelper->createQueue($handlerData['queue_name'], $handlerData);
        return true;
    }

    public function ShopStatusUpdate($sqsData)
    {
        echo 'totalUninstalled:' . ($sqsData['data']['totalUninstalled'] ?? 0 ) . PHP_EOL;
        echo 'Cursor:' . $sqsData['data']['cursor'] . PHP_EOL;
        echo '************' . PHP_EOL;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');

        $aggregate = [];

        $aggregate[] = [
            '$unwind' => '$shops'
        ];

        $aggregate[] = [
            '$match' => [
                'shops.marketplace' => 'shopify'
            ]
        ];

        $aggregate[] = [
            '$skip' => $sqsData['data']['cursor'] * $sqsData['data']['limit']
        ];

        $aggregate[] = [
            '$limit' => $sqsData['data']['limit']
        ];

        $aggregate[] = [
            '$project' => [
                'username' => 1,
                'user_id' => 1,
                '_id' => 0,
                'shops.remote_shop_id' => 1,
                'shops._id' => 1,
            ]
        ];

        $user_details = $collection->aggregate($aggregate)->toArray();

        $totalUninstalled = 0;

        if ( $sqsData['data']['cursor'] > 10 ) {
            echo "Done";
            return true;
        }

        if ( count($user_details) <= 0 ) {
            return true;
        }

        foreach($user_details as $user) {
            echo "-i-";
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                            ->init('shopify')
                            ->call('/shop',[],['shop_id'=> $user['shops']['remote_shop_id']], 'GET');
            if ( !isset($remoteResponse['data']['id']) ) {
                $collection->updateOne(['user_id' => $user['user_id']], ['$set' => [
                    'uninstall_status' => 'ready_to_uninstall',
                    'uninstall_date' => date('c')
                ]]);
                $totalUninstalled++;
            }
        }

        $sqsData['data']['cursor'] =  $sqsData['data']['cursor'] + 1;
        $sqsData['data']['totalUninstalled'] =( $sqsData['data']['totalUninstalled'] ?? 0 ) + $totalUninstalled;

        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        $rmqHelper->createQueue($sqsData['queue_name'], $sqsData);
        return true;
    }

    public function getAdminConfig()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo2 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("admin_config");
        $mongo2->setSource("user_details");
        $collection = $mongo->getCollection();
        $collection2 = $mongo2->getCollection();
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


    public function getAllUsers($userData)
    {
        $count = 25;
        $activePage = 1;
        if (isset($userData['count'])) {
            $count = $userData['count'];
        }

        if (isset($userData['activePage'])) {
            $activePage = $userData['activePage'] - 1 ;
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("user_details");

        $collection = $mongo->getCollection();

        $skip=(int)$count*$activePage;

        $filters = isset($userData['filter']) ? $userData : [];
        $conditionalQuery = [];
        // $pushQueryInst=[];
        // die(json_encode($filters));

        $synsForm=[
            'type'=>'modal',
            'code'=>'sync_user',
            'required' => 'true',
            'title'=>'Sync',
            'name'=>'Sync Modal',
            'field_groups'=>[
                [
                'type'=>'flexLayout',
                'flexprops'=>[
                    'valign'=> 'center',
                    'direction'=> 'none',
                    'spacing'=> 'loose',
                    'halign'=> 'center',
                    'wrap'=> 'wrap',
                ],
                'field_groups'=>[
                    [
                        'type' => 'button', //'textfield | select | checkbox | radio | multiselect | button | read_only',
                        'required' => 'true',
                        'name'=>'Order Sync',
                        'code' => 'order_sync',
                        'action'=>[
                            'type'=>'api_call',
                            'api'=>'frontend/adminpanel/userSync',
                            'api_type'=>'GET',
                            'params'=>[
                               'user'=>'$user_id',
                               'sync_type'=>'order_sync'
                            ]
                        ]
                    ],
                    [
                        'type' => 'button', //'textfield | select | checkbox | radio | multiselect | button | read_only',
                        'required' => 'true',
                        'name'=>'Shipment Sync',
                        'code' => 'shipment_sync',
                        'action'=>[
                            'type'=>'api_call',
                            'api'=>'frontend/adminpanel/userSync',
                            'api_type'=>'GET',
                            'params'=>[
                               'user'=>'$user_id',
                               'sync_type'=>'shipment_sync'
                            ]
                        ]
                    ],
                    [
                        'type' => 'button', //'textfield | select | checkbox | radio | multiselect | button | read_only',
                        'required' => 'true',
                        'name'=>'Register for Order Sync',
                        'code' => 'register_order_sync',
                        'action'=>[
                            'type'=>'api_call',
                            'api'=>'frontend/adminpanel/userSync',
                            'api_type'=>'GET',
                            'params'=>[
                               'user'=>'$user_id',
                               'sync_type'=>'register_order_sync'
                            ]
                        ]
                    ],
                ]
            ]
            ]
         ];

        if (count($filters)) {
            // die(json_encode($filters['filter']));
            if (isset($filters['filter']['install_at'])) {
                $installAtFilter=$filters['filter']['install_at'][2];
                $pushQueryInst=                [
                    '$match' => [
                        'install_at' => [
                            '$gte' => explode('to', (string) $installAtFilter)[0],
                            '$lte' => explode('to', (string) $installAtFilter)[1],
                        ],
                    ],
                ];
                // unset($filters['filter']['install_at']);
            }

            $conditionalQuery = self::searchMongo($filters);
            // $conditionalQuery=['install_at'=>'sd'];

            // die(json_encode($conditionalQuery));

            $aggregate=[
                [
                    '$addFields' => [
                        'install_at' => [
                            '$dateToString'=>[
                                'format'=>'%Y-%m-%d',
                                'date'=>'$created_at'
                            ]
                        ],
                    ],
                ],
                [
                    '$addFields' => [
                        'syncs' => ['sysn'=> $synsForm],
                    ],
                ],
                ['$sort' => ['_id' => -1]],['$match'=>$conditionalQuery],['$skip'=>$skip],['$limit'=>(int)$count]];
            $pushQueryInst && $aggregate[]=$pushQueryInst;
        } else {
            // $synscFile=
            $aggregate=[
                [
                    '$addFields' => [
                        'install_at' => [
                            '$dateToString'=>[
                                'format'=>'%Y-%m-%d',
                                'date'=>'$created_at'
                            ]
                        ],
                    ],
                ],
                [
                    '$addFields' => [
                        'syncs' => ['sysn'=> $synsForm],
                    ],
                ],
                ['$sort' => ['_id' => -1]],['$skip'=>$skip],['$limit'=>(int)$count]];
        }

        $k=$collection->aggregate($aggregate)->toArray();

        // die(json_encode($k));
        foreach ($k as $key => $value) {
            $k[$key]['username'] = $k[$key]['shops'][0]['domain']?? null;
            $k[$key]['id'] = $k[$key]['user_id'];
            if (isset($k[$key]['shops'][0]['form_details'])) {
                $k[$key]['catalog']=$k[$key]['shops'][0]['form_details']['catalog_sync_only'];
            } else {
                $k[$key]['catalog']='';
            }

            $k[$key]['email']=$k[$key]['shops'][0]['email'] ?? null;
            $k[$key]['shop_id']=$k[$key]['shops'][0]['remote_shop_id'] ?? null;
            $k[$key]['shop_url']=$k[$key]['shops'][0]['myshopify_domain'] ?? null;
            $k[$key]['created_at']=$k[$key]['shops'][0]['created_at'] ?? null;
            $k[$key]['updated_at']=$k[$key]['shops'][0]['updated_at'] ?? null;
            $k[$key]['shopify_plan']=$k[$key]['shops'][0]['plan_name'] ?? null;
            $k[$key]['user_details']['mobile']=$k[$key]['shops'][0]['phone'] ?? null;
            $k[$key]['user_details']['full_name']=$k[$key]['shops'][0]['shop_owner']."(".$k[$key]['shops'][0]['name'].")";
            $k[$key]['user_details']['primary_time_zone']=$k[$key]['shops'][0]['timezone'] ?? null;
            if (isset($k[$key]['shops'][0]['warehouses'][1])) {
                $k[$key]['facebook'] = $k[$key]['shops'][0]['warehouses'][1];
                unset($k[$key]['shops'][0]['warehouses'][1]);
            }

            $k[$key]['shopify'] = $k[$key]['shops'][0] ?? null;
            // unset($k[$key]['shops']);
        }

        return ['success' => true, 'message' => '', 'data' => ['rows' =>$k,
                'count' =>$collection->count($conditionalQuery)]];
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

        if ($key=='default.user_type' || $key =='default.bda_allign' || $key =='default.com_med') {
            return $value;
        }

        return trim((string) $value);
    }

    public static function searchMongo($filterParams = [])
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            if (isset($filterParams['filter']['default.bda_allign'][1])) {
                $filterParams['filter']['default.bda_allign']['1'] = new ObjectId($filterParams['filter']['default.bda_allign']['1']);
            }

            if (isset($filterParams['filter']['default.com_med'][1])) {
                $filterParams['filter']['default.com_med']['1'] = new ObjectId($filterParams['filter']['default.com_med']['1']);
            }

            if (isset($filterParams['filter']['user_type']['1'])) {
                $filterParams['filter']['user_type']['1'] = new ObjectId($filterParams['filter']['user_type']['1']);
            }

            if (isset($filterParams['filter']['default.user_type']['1'])) {
                $filterParams['filter']['default.user_type']['1'] = new ObjectId($filterParams['filter']['default.user_type']['1']);
            }

            foreach ($filterParams['filter'] as $key => $value) {
                // if($key=='install_at'){
                //     continue;
                // }
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
                $url = $frontendAppDetails[$db]['frontend_app_url'] . 'auth/login?admin_user_token=' . $token;
            } else {
                $frontedURL=$this->di->getConfig()->frontend_app_url;
                // die($frontedURL);
                $url = $frontedURL . '/auth/login?admin_user_token=' . $token;
            }

            return ['success' => true, 'data' => $url];
        }

        return ['success' => false, 'message' => 'User not found', 'code' => 'user_not_found'];
    }

    /*public function getLoginAsUserUrl($userData)
    {
        $userId = $userData['user_id'];
        $db = $userData['db'];
        $connection = $this->di->get($db);
        $userQuery = 'SELECT id FROM user WHERE id = ' . $userId;
        $user = $connection->fetchAll($userQuery);
        if (count($user)) {
            $date = new \DateTime('+4 hour');
            $tokenObject = [
                "user_id" => $userId,
                "role" => 'customer',
                "exp" => $date->getTimestamp()
            ];
            $dateCurrent = new \DateTime();
            $tokenObject["iat"] = $dateCurrent->getTimestamp();
            $tokenObject["iss"] = HOST;
            $tokenObject["aud"] = $_SERVER['REMOTE_ADDR'] ?? 'example.com';
            $tokenObject["nbf"] = $dateCurrent->getTimestamp();
            $tokenObject["token_id"] = $dateCurrent->getTimestamp();
            $dir = BP . DS . 'app' . DS . 'etc' . DS . 'security' . DS;
            $privateKey = file_get_contents($dir . 'connector.pem');
            $token = JWT::encode($tokenObject, $privateKey, 'RS256');
            $insertTokenQuery = 'INSERT INTO `token_manager`(`user_id`, `token_id`) VALUES (' . $userId . ',' . $tokenObject["token_id"] . ')';
            $connection->query($insertTokenQuery);
            $frontendAppDetails = $this->di->getConfig()->frontend_app_details;
            if (isset($frontendAppDetails[$db])) {
                $url = $frontendAppDetails[$db]['frontend_app_url'] . 'auth/login?admin_user_token=' . $token;
            } else {
                $url = $this->di->getConfig()->frontend_app_url . '/auth/login?admin_user_token=' . $token;
            }
            return ['success' => true, 'data' => $url];
        }
        return ['success' => false, 'message' => 'User not found', 'code' => 'user_not_found'];
    }*/


    public function getAppConfig()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("app_config");

        $collection = $mongo->getCollection();
        $k = $collection->aggregate([['$limit' => 100]])->toArray();
        return ['success' => true, 'message' => '', 'data' => $k];
    }

    public function addAdminConfig($item)
    {
        unset($item['target_marketplace']);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("admin_config");

        $collection = $mongo->getCollection();
        $add_admin_config = $collection->insertOne($item);
        if ($add_admin_config->getInsertedCount() > 0) {
            return ['data' => "Inserted Successfully", "success" => true, "count" => $add_admin_config->getInsertedCount()];
        }
        return ['data' => "Some Error Occured", "success" => false];
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
        $mongo->setSource("order_container");

        $collection = $mongo->getCollection();
        $aggregate = [];
        // $aggregate[] = [
        //     '$addFields' => [
        //         'creationDate' => [
        //             '$arrayElemAt' => [
        //                 [
        //                     '$split' => [
        //                         '$placed_at',
        //                         'T',
        //                     ],
        //                 ],
        //                 0,
        //             ],
        //         ],
        //     ],
        // ];
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
        if ( isset($userConditionalQuery) ) {
            $aggregate[] = [
                '$match' => $userConditionalQuery
            ];
        }

        $aggregate[] = [
            '$group' => [
                '_id' => [
                    'm_id' => '$user_id',
                    'username' => '$username',
                    'status' => '$source_status',
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
        // die(json_encode($res));
        $response = $this->formattedUsersOrdersCount($res);
        // $mongo->setSource("user_details");
        // $collection = $mongo->getCollection();
        return [
            'success' => true, 
            // 'message' => '', 
            'data' => $response['graphArray'], 
            // 'userData' => $response['userArray'],
            //  'databaseResponse' => $res, 
            // 'aggregate' => $aggregate, 
            ];
    }

    public function formattedUsersOrdersCount($res)
    {
        //  die(json_encode($res));
        $arr = [];
        $userArray = [];
        foreach ($res as $value) {
            if (isset($value['_id']['username']) && !empty($value['_id']['username'])) {
                $temp = [];
                $tempUser = [];

                foreach ($value['data'] as $val) {
                    $temp[$val['status']] = $val['total'];
                }

                $temp['users'] = $value['_id']['username'];
                $tempUser[$value['_id']['username']] = $value['_id']['user_id'];
                array_push($arr, $temp);
                array_push($userArray, $tempUser);
            }
        }

        return ['graphArray' => $arr, 'userArray' => $userArray];
        // return $arr;
    }

    public function getAppOrdersCount($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("order_container");

        $collection = $mongo->getCollection();
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
                                    [
                                        '$split' =>
                                        ['$placed_at', "-"]
                                    ], 1,
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
                    'creationDate' => [
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
        return ['success' => true, 'message' => '', 'data' => $response, 'erd' => $res, 'hsf' => $data];
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
        $mongo->setSource("user_details");
        // die("dsf");
        $collection = $mongo->getCollection();
        $aggregate = [
            [
                '$addFields' => [
                    'creationDate' => [
                        '$dateToString'=>[
                            'format'=>'%Y-%m-%d',
                            'date'=>'$created_at'
                        ]
                    ],
                ],
            ],
            [
                '$match' => [
                    'creationDate' => [
                        '$gte' => $data['from'],
                        '$lte' => $data['to'],
                    ],
                ],
            ],
            [
                '$lookup' =>[
                'from' => 'admin_config',
                'localField' => 'default.user_type',
                'foreignField' => '_id', 'as' => 'user_data'
                ]
            ],
            [
                '$group' => [
                    '_id' =>[
                        '$arrayElemAt' =>[
                            '$user_data.name', 0]
                        ],
                    'total' => [
                        '$sum' => 1
                        ]
                    ]
            ],
        ];
        // die("Sdf");
        $res = $collection->aggregate($aggregate)->toArray();
        // die()
        return ['success' => true, 'message' => '', 'data' => $res];
    }

    public function getStepCompletedCount($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("admin_config");
        $collection1 = $mongo->getCollectionForTable("user_details");
        $aggregate = [
            [
                '$match' => ['type' => 'app_tag']
            ],
            [
                '$group' => ['_id' => 'app_tags', 'app_tags' => ['$addToSet' => '$app_tag']]
            ]
        ];
        $res = $collection->aggregate($aggregate)->toArray();
        $aggregate1 = [];
        $temp_data = [];
        $steps_data = [];
        foreach ($res[0]['app_tags'] as $value) {
            $aggregate1 = [
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
                        ]
                    ]
                ],
                [
                    '$match' => [
                        'creationDate' => [
                            '$gte' => $data['from'],
                            '$lte' => $data['to'],
                        ],
                        $value => ['$exists' => true]
                    ]
                ],
                [
                    '$project' => [$value => 1]
                ]
            ];
            $res1 = $collection1->aggregate($aggregate1)->toArray();
            foreach ($res1 as $value1) {
                if (!isset($temp_data[$value1[$value]['step_completed'] . ' steps completed'])) {
                    $temp_data[$value1[$value]['step_completed'] . ' steps completed'] = 1;
                } else {
                    $temp_data[$value1[$value]['step_completed'] . ' steps completed']++;
                }
            }
        }

        foreach ($temp_data as $key => $value) {
            array_push($steps_data, ['_id'=>$key, 'total' => $value]);
        }

        return ['success' => true, 'message' => '', 'data' => $steps_data];
    }

    public function addAppConfig($item)
    {
        // unset($item['target_marketplace']);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("app_config");

        $collection = $mongo->getCollection();
        $add_app_config = $collection->insertOne($item['params']);
        if ($add_app_config->getInsertedCount() > 0) {
            return ['data' => "Inserted Successfully", "success" => true, "count" => $add_app_config->getInsertedCount()];
        }
        return ['data' => "Some Error Occured", "success" => false];
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

    public function orderStatusFile($id, $date)
    {
        // die($id);
        // die("df");
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
        $data = $collection->aggregate([
            [
                '$match' => ["user_id" => $id]
            ],
            [
                '$addFields' => [
                    'date_fields' => [
                        [
                            '$arrayElemAt' => [
                                ['$split' => ["\$placed_at", "T"]]
                                ,0
                            ]
                        ]
                    ]
                ]
            ],
            ["\$match" =>
                [
                    "date_fields" => [
                        '$gte' => $date['prevdate'],
                        '$lte' => $date['postdate'],
                    ]
                ]
            ],
            ["\$group" => [
                "_id" => "\$source_status",
                "count" => ["\$sum" => 1]
            ]
        ]
        ])->toArray();
        // die(json_encode($data));
        return ["data" => $data, "success" => true];
    }

    public function editAdminConfig($item)
    {
        // die('dfg');
        unset($item['target_marketplace']);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("admin_config");

        $collection = $mongo->getCollection();
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



    //csv STart

    public function getProductDeatils($data)
    {
        // die($this->di->getUser()->id);

        // die(json_encode($data));\App\Connector\Models\ProductContainer
        // $ProductController = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper');
        // $dataCount = $this->getAllUsers($data);
        $count =10;
        // die($count."sdf");
        // die($count."dsf");

        $userId = $this->di->getUser()->id;

        $basePath=BP.DS.'var/log/adminCSV/';

        if (!file_exists($basePath)) {
            mkdir($basePath);
            chmod($basePath, 0777);
        }




        $path=$basePath."admin_panel_csv_".$userId.".csv";

        if (!file_exists($path)) {
            $file =fopen($path, 'w');
            chmod($path, 0777);
        } else {
            $file =fopen($path, 'w');
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $notifications = $mongo->getCollectionForTable('notifications');
        $notifications->deleteMany(['message'=>'CSV Export Completed']);

        // die("no done");


        $user_detail= $collection->findOne(['user_id'=>$userId]);

        // die(json_encode($user_detail));

        // die(json_encode($user_detail));
        // echo "<pre>";
        // die(json_encode($user_detail['CSVcolumn']));

        $params=$data;
        // $toEditUpdate= $data['editUpdate'];
        // print_r(json_encode($toEditUpdate));
        // echo "<br>chnages <br>";
        // if (isset($toEditUpdate['source_product_id'])) {
        //     unset($toEditUpdate['source_product_id']);
        // }

        // die(json_encode($toEditUpdate));
        $mandotaryField=["user_id", "username",'email','marketplace'];
        if (isset($user_detail['CSVcolumn'])) {
            $line=json_decode((string) $user_detail['CSVcolumn']);
            foreach ($mandotaryField as $valueMan) {
                if (!(in_array($valueMan, $line))) {
                    $line[]=$valueMan;
                }
            }
        } else {
            $line=$mandotaryField;
        }

        $line=$mandotaryField;

        $len= count($line);

        // print_r(json_encode($line));
        // die;

        // $variantLine=["type","option1 name","option1 value",
        // "option2 name","option2 value","option3 name","option3 value"];

        // foreach($line as $ke=>$va){
        //     if(in_array($va,$variantLine)){
        //         unset($line[$ke]);
        //     }
        // }

        // $line1=array_unique(array_merge($line, $variantLine));

        fputcsv($file, $line);
        // die("df");
        $feed_id= $this->di->getObjectManager()->get('App\Connector\Components\Helper')
        ->setQueuedTasks('CSV Export in progress', '', $userId);
        $CSVFile = [
            'file_path' => $path
        ];
        $token = $this->di->getObjectManager()
            ->get('App\Core\Components\Helper')
            ->getJwtToken($CSVFile, 'RS256', false);
        // $url = $this->di->getConfig()->base_url . 'frontend/csv/downloadCSV?file_token=' . $token;
        // $url = 'https://amazon-sales-channel.demo.cedcommerce.com/home/public/' . 'frontend/adminpanel/downloadCSV?file_token=' . $token;
        $url = 'https://home.connector.sellernext.com/' . 'frontend/adminpanel/downloadCSV?file_token=' . $token;


        $appTag = $this->di->getAppCode()->getAppTag();
        $handlerData = [
            'type' => 'full_class',
            'class_name' => AdminpanelHelper::class,
            'method' => 'ProductWriteCSVSQS',
            'queue_name' => 'admin_panel_csv_export',
            'app_tag'=>$appTag,
            'user_id'=>$userId,
            'app_code'=>$data['app_code'],
            'data' => [
                'url'=>$url,
                'totalCount'=>$count,
                'feed_id'=>$feed_id,
                'cursor' => 1,
                'skip'=>0,
                'path'=>$path,
                'limit'=>200,
                'params'=>$params,
                'target_marketplace'=>$data['marketplace'],
                'columns'=>$line,
                'variant_line'=>$variantLine
            ]
        ];
        // die("sdf");
        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        $rmqHelper->createQueue($handlerData['queue_name'], $handlerData);
        // $url = 'http://amazon-sales-channel.demo.cedcommerce.com/home/public/frontend/csv/downloadCSV?file_token=' . $token;
        return $url;
    }

    public function createLine($columns, $value, $toEditUpdate)
    {
        $line=[];
        foreach ($columns as $column) {
            $newCol=true;
            if (isset($value[$column])) {
                $line[]=$value[$column];
                $newCol=false;
            } else {
                $line[]="";
            }
        }

        // $line[]=$type;
        // $i=0;
        // if ($type=="child") {
        //     $variant_value=explode('/',$value['variant_title']);
        //     foreach($variantAttributes as $var_attr){
        //         $line[]=$var_attr;
        //         $line[]=$variant_value[$i];
        //         $i++;
        //     }
        //     // if (isset($value['variant_attributes'])) {
        //     //     foreach ($value['variant_attributes'] as $variant_attributes) {
        //     //         $i++;
        //     //         $line[]=$variant_attributes;
        //     //         $line[]=$value[$variant_attributes] ?? "";
        //     //     }
        //     // }
        // }
        // for ($j=1;$j<=3-$i;$j++) {
        //     $line[]="";
        //     $line[]="";
        // }
        return $line;
    }

    public function ProductWriteCSVSQS($sqsData)
    {
        $path=$sqsData['data']['path'];
        // die($path."path");
        $totalDataRead=$sqsData['data']['totalDataRead']?? 0;
        // $totalCount=$sqsData['data']['totalCount'];
        $userId = $this->di->getUser()->id;
        $file =fopen($path, 'a');
        $params=$sqsData['data']['params'];
        // $variantLine=$sqsData['data']['variant_line'];
        $toEditUpdate="";
        // $toEditUpdate=$params['editUpdate'];
        // $columnToEdit=array_keys($toEditUpdate);
        $columns=$sqsData['data']['columns'];
        // $ProductController =$ProductController = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper');
        $row = $this->getAllUsers($params);
        $rowsToWrite= $row['data']['rows'];
        // $next =$row['data']['next'];
        $RowsCount=count($rowsToWrite);
        print_r($params);
        // die(print_r($rowsToWrite));
        foreach ($rowsToWrite  as $value) {
            $line=$this->createLine($columns, $value, $toEditUpdate);
            fputcsv($file, $line);
            // if ($value['type']=="variation" && isset($value['variants'])) {
            //     foreach ($value['variants'] as $variant) {
            //         $line=[];
            //         $line=$this->createLine($columns, $variant, $toEditUpdate);
            //         fputcsv($file, $line);
            //     }
            // }
        }
        ;
        // $totalDataRead=$totalDataRead+$row['data']['current_count'];

        $params['activePage']=$params['activePage']+1;

        // $params['next']=$next;
        // if(isset($params['activePage'])){
        //     unset($params['activePage']);
        // }
        // print_r($params);

        if ($RowsCount<$params['count']) {
            $message="CSV Export Completed";
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')
                ->addNotification($sqsData['user_id'], $message, 'success', $sqsData['data']['url']);
            $this->di->getObjectManager()->get('App\Connector\Components\Helper')
                ->updateFeedProgress($sqsData['data']['feed_id'], 100, $message);
            return true;
        }
        // $progress=round(($row['data']['current_count']/$totalCount)*100, "3");
        // echo $progress;
        // return true;
        $sqsData['data']['totalDataRead']=$totalDataRead;
        $message="CSV Export in progress";
        $this->di->getObjectManager()->get('App\Connector\Components\Helper')
        ->updateFeedProgress($sqsData['data']['feed_id'], 0, $message);
        $sqsData['data']['cursor']++;
        $sqsData['data']['params']=$params;
        $this->pushToQueue($sqsData);
    }

    public function pushToQueue($sqsData)
    {
        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        return $rmqHelper->createQueue($sqsData['queue_name'], $sqsData);
    }

    public function BulkEdit($sqsData)
    {
        // will help to determine the char need to skip
        $skip_character = $sqsData['skip_character'] ?? 0;
        // how many line need to Read
        $row_chunk = $sqsData['chunk'] ?? 500;
        $scaned = $sqsData['scanned'] ?? 0;
        $myFile=$sqsData['data']['path'];
        $editUpdate=$sqsData['data']['editUpdate'];


        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("user_details");

        $mongoCollection = $mongo->getCollection();

        $bulkOpArray = [];
        // used to break the loop
        $i = 0;
        $dontAdd=["type","option1 name","option1 value",
        "option2 name","option2 value","option3 name","option3 value"];
        if (($handle = fopen($myFile, 'r')) !== false) {
            // get the first row, which contains the column-titles (if necessary)
            // verfiy the header before you start the work on Vidaxl different CSV
            $header = fgetcsv($handle);
            // it will help you the Skip the charater which are already been used
            fseek($handle, $skip_character);
            // loop through the file line-by-line
            $productArrayData = [];
            while (($product = fgetcsv($handle)) !== false && $i < $row_chunk) {
                // Give the The Row Data, in Array
                $prepareProductArr=[];
                foreach ($product as $key=>$eachEle) {
                    if (!(in_array($header[$key], $dontAdd))) {
                        $prepareProductArr=$prepareProductArr+ [
                        $header[$key]=>$eachEle
                    ];
                    }
                }

                // $prepareProductArr =$prepareProductArr+$editUpdate[$prepareProductArr['source_product_id']] ?? [];
                //update if extra info given



                $bulkOpArray[] = [
                    'updateOne' => [
                        ['user_id'=>$prepareProductArr['user_id']],
                        [
                            '$set' => $prepareProductArr
                        ],
                    ],
                ];
                // Will Give the number of Character read till now
                $skip_character = ftell($handle);
                unset($product);
                $i++;
                $scaned++;
            }

            $bulkObj = $mongoCollection->BulkWrite($bulkOpArray, ['w' => 1]);
            fclose($handle);
        }

        if ($i < $row_chunk) {
            echo "completed";
            $message="CSV Import Completed";
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')
                ->addNotification($sqsData['user_id'], $message, 'success');
            $this->di->getObjectManager()->get('App\Connector\Components\Helper')
                ->updateFeedProgress($sqsData['data']['feed_id'], 100, $message);
            return true;
        }
        $sqsData['skip_character'] = $skip_character;
        $sqsData['chunk'] = 100;
        $sqsData['scanned']=$scaned;
        $sqsData['data']['cursor']++;
        echo  $sqsData['data']['cursor'];
        $message="CSV Import Progress";
        $progress=round($i/$sqsData['data']['count']*100, '3');
        $this->di->getObjectManager()->get('App\Connector\Components\Helper')
        ->updateFeedProgress($sqsData['data']['feed_id'], 0, $message);
        $this->pushToQueue($sqsData);
    }

    public function importCSVAndUpdate($params)
    {
        $userId = $this->di->getUser()->id;
        $appTag = $this->di->getAppCode()->getAppTag();
        $feed_id= $this->di->getObjectManager()->get('App\Connector\Components\Helper')
        ->setQueuedTasks('CSV Import in progress', '', $userId);
        $handlerData = [
            'type' => 'full_class',
            'class_name' => AdminpanelHelper::class,
            'method' => 'BulkEdit',
            'queue_name' => 'admin_panel_csv_import',
            'app_tag'=>$appTag,
            'user_id'=>$userId,
            'data' => [
                'path'=>$params['path'].$params['name'],
                'feed_id'=>$feed_id,
                'cursor' => 0,
                'skip'=>0,
                'limit'=>200,
                'count'=>$params['count'],
                'editUpdate'=>$params['editUpdate']
            ]
        ];
        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        $rmqHelper->createQueue($handlerData['queue_name'], $handlerData);
        return true;
        die();
    }

    public function orderSync($params): void
    {
        // die(json_encode($params));
        $cronTask = 'order_sync';
        $limit = 100;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $amazonCronCollection = $mongo->getCollectionForTable(Helper::AMAZON_CRON_TASKS);
        $cronData = $amazonCronCollection->findOne(['task' => $cronTask], ['typeMap' => ['root' => 'array', 'document' => 'array']]);


        $skip = 0;
        if (!empty($cronData) && isset($cronData['offset'])) {
            $skip = $cronData['offset'];
        }

        $collection = $mongo->getCollectionForTable('user_details');

        $options = [
            'limit' => $limit,
            'skip' => $skip,
            'typeMap' => ['root' => 'array', 'document' => 'array']
        ];

        $user_details = $collection->find(['shops.marketplace' => 'amazon','user_id'=>$params['user']], $options)->toArray();

        // die(json_encode($user_details));

        if (empty($user_details)) {
            $options = [
                'limit' => $limit,
                'skip' => 0,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];

            $user_details = $collection->find(['shops.marketplace' => 'amazon'], $options)->toArray();
        }

        // $users_list = ['shoxtec-suspension.myshopify.com']; // remove this for production

        foreach ($user_details as $user) {
            // remove this for production
            // if(isset($user['username']) && in_array($user['username'], $users_list)) {
            //     continue;
            // }
            // remove this for production

            if (isset($user['user_id'])) {
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => '\App\Amazon\Components\Cron\Route\Requestcontrol',
                    'method' => 'syncOrder',
                    'queue_name' => $this->di->getObjectManager()->get('\App\Amazon\Components\Cron\Helper')->getOrderSyncQueueName(),
                    'user_id' => $user['user_id'],
                ];

                $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');

                $helper->createQueue($handlerData['queue_name'], $handlerData);
            }
        }

        if (!empty($cronData)) {
            $offset = $limit+$skip;
            $user_count = $collection->count(['shops.marketplace' => 'amazon','user_id'=>$params['user']]);
            if ($offset >= $user_count) {
                $offset = 0;
            }

            $status = $amazonCronCollection->updateOne(['task' => $cronTask], ['$set' => ['offset' => $offset]]);
            $status->getModifiedCount();
        } else {
            $status = $amazonCronCollection->insertOne(['task' => $cronTask, 'offset' => $limit+$skip]);
            $status->getInsertedCount();
        }

        echo count($user_details)." merchants pushed in sqs for {$cronTask}";
    }

    public function registerForOrderSync($params): void
    {
        // die(json_encode($params));
        $cronTask = 'register_for_order_sync';
        $limit = 100;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $collection = $mongo->getCollectionForTable('user_details');

        $options = [
            'limit' => $limit,
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'projection' => ['user_id' =>  1, 'username' => 1, 'shops' => 1],
        ];

        // $users_list = ['shoxtec-suspension.myshopify.com']; // remove this for production

        $user_details = $collection->find([
            'shops' => ['$elemMatch' => [
                'marketplace' => 'amazon',
                'register_for_order_sync' => ['$exists' => false]
            ]],'user_id'=>$params['user']
        ], $options)->toArray();

        // die(json_encode($user_details));

        if (!empty($user_details)) {
            $chunks = array_chunk($user_details, 20);

            foreach ($chunks as $users) {
                $bulkOpArray = [];

                $amazon_shops = [];

                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $configuration = $mongo->getCollectionForTable(Helper::CONFIGURATION);

                foreach ($users as $user) {
                    // remove this for production
                    // if(!isset($user['username']) || !in_array($user['username'], $users_list)) {
                    //     continue;
                    // }
                    // remove this for production

                    if (isset($user['shops'],$user['user_id'])) {
                        $shops = $user['shops'];

                        foreach ($shops as $shop) {
                            if (isset($shop['marketplace']) && $shop['marketplace']=='amazon' && !isset($shop['register_for_order_sync'])) {
                                if (isset($shop['warehouses'][0]['status']) && $shop['warehouses'][0]['status'] == 'active') {
                                    $orderEnabled = false;

                                    $configurationSettings = $configuration->findOne(['user_id' => $user['user_id']]);
                                    if (isset($configurationSettings['data']['order_settings'])) {
                                        $orderEnabled = $configurationSettings['data']['order_settings']['settings_enabled'];
                                    } else {
                                        $orderEnabled = true;
                                    }

                                    if ($orderEnabled) {
                                        $amazon_shops[] = [
                                            'order_status'      => ['Unshipped'],
                                            'order_channel'     => 'MFN',
                                            'home_shop_id'      => $shop['_id'],
                                            'remote_shop_id'    => $shop['remote_shop_id'],
                                            'queue_name'        => 'amazon_orders',
                                            'message_data'      => [
                                                'user_id'           => $user['user_id'],
                                                'handle_added'      => '1',
                                                'queue_name'        => 'amazon_orders',
                                                'type'              => 'full_class',
                                                'class_name'        => '\App\Amazon\Components\Order\Order',
                                                'method'            => 'syncOrder'
                                            ]
                                        ];

                                        $bulkOpArray[$shop['_id']] = [
                                            'updateOne' => [
                                                ['_id' => $user['_id'], 'shops._id' => $shop['_id']],
                                                ['$set' => ['shops.$.register_for_order_sync' => true]]
                                            ]
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }

                if (!empty($amazon_shops)) {
                    $logfile = "amazon/{$cronTask}/".date('d-m-Y').'.log';
                    $this->di->getLog()->logContent('request data : '.print_r($amazon_shops, true), 'info', $logfile);

                    $helper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
                    $response = $helper->sendRequestToAmazon('order-sync/register', $amazon_shops, 'POST');

                    $this->di->getLog()->logContent('remote response : '.print_r($response, true), 'info', $logfile);

                    if (isset($response['success']) && $response['success'] && !empty($response['registered'])) {
                        $newBulkOpArray = [];

                        foreach ($response['registered'] as $registered) {
                            if (isset($bulkOpArray[$registered['home_shop_id']])) {
                                $newBulkOpArray[] = $bulkOpArray[$registered['home_shop_id']];
                            }
                        }

                        if (!empty($newBulkOpArray)) {
                            $bulkObj = $collection->BulkWrite($newBulkOpArray, ['w' => 1]);
                            $returenRes = [
                                'acknowledged' => $bulkObj->isAcknowledged(),
                                'inserted' => $bulkObj->getInsertedCount(),
                                'modified' => $bulkObj->getModifiedCount(),
                                'matched' => $bulkObj->getMatchedCount()
                            ];

                            $this->di->getLog()->logContent('bulk update response : '.print_r($returenRes, true), 'info', $logfile);
                        }
                    }
                }
            }
        }

        echo count($user_details)." merchants pushed in sqs for {$cronTask}";
    }

    public function shipmentSync($params): void
    {
        // die(json_encode($params));
        $cronTask = 'shipment_sync';
        $limit = 100;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $amazonCronCollection = $mongo->getCollectionForTable(Helper::AMAZON_CRON_TASKS);
        $cronData = $amazonCronCollection->findOne(['task' => $cronTask], ['typeMap' => ['root' => 'array', 'document' => 'array']]);

        $skip = 0;
        if (!empty($cronData) && isset($cronData['offset'])) {
            $skip = $cronData['offset'];
        }

        $collection = $mongo->getCollectionForTable('user_details');

        $options = [
            'limit' => $limit,
            'skip' => $skip,
            'typeMap' => ['root' => 'array', 'document' => 'array']
        ];

        $user_details = $collection->find(['shops.marketplace' => 'amazon','user_id'=>$params['user']], $options)->toArray();
        // die(json_encode($user_details));
        if (empty($user_details)) {
            $options = [
                'limit' => $limit,
                'skip' => 0,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];

            $user_details = $collection->find(['shops.marketplace' => 'amazon','user_id'=>$params['user']], $options)->toArray();
        }

        foreach ($user_details as $user) {
            if (isset($user['user_id'])) {
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => '\App\Amazon\Components\Cron\Route\Requestcontrol',
                    'method' => 'syncShipment',
                    'queue_name' => $this->di->getObjectManager()->get('\App\Amazon\Components\Cron\Helper')->getShipmentSyncQueueName(),
                    'user_id' => $user['user_id'],
                ];

                $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');

                $helper->createQueue($handlerData['queue_name'], $handlerData);
            }
        }

        if (!empty($cronData)) {
            $offset = $limit+$skip;
            $user_count = $collection->count(['shops.marketplace' => 'amazon','user_id'=>$params['user']]);
            if ($offset >= $user_count) {
                $offset = 0;
            }

            $status = $amazonCronCollection->updateOne(['task' => $cronTask], ['$set' => ['offset' => $offset]]);
            $status->getModifiedCount();
        } else {
            $status = $amazonCronCollection->insertOne(['task' => $cronTask, 'offset' => $limit+$skip]);
            $status->getInsertedCount();
        }

        echo count($user_details)." merchants pushed in sqs for {$cronTask}";
    }
}
