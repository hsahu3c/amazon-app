<?php

namespace App\Frontend\Components;

use App\Core\Components\Base;
use MongoDB\BSON\ObjectId;
use App\Core\Models\User;
use DateTime;
use App\Frontend\Components\AmazonMulti\Helper;
use DateTimeZone;
use Exception;
use MongoDB\BSON\UTCDateTime;
use App\Shopifyhome\Components\Shop\Shop;

use function GuzzleHttp\json_encode;

class AdminpanelamazonmultiHelper extends Base
{
    public const IS_EQUAL_TO = 1;

    public const IS_NOT_EQUAL_TO = 2;

    public const IS_CONTAINS = 3;

    public const IS_NOT_CONTAINS = 4;

    public const START_FROM = 5;

    public const END_FROM = 6;

    public const RANGE = 7;

    public const CHECK_KEY_EXISTS = 8;

    public const GREATER_THAN = 9;

    public const LESS_THAN = 10;


    public function getUserPlanAvailed()
    {
        // die('dfg');
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //$mongo->setSource("active_plan");
        $collection = $mongo->getCollectionForTable('active_plan');
        $aggregate = [];
        $collection = $collection->find()->toArray();
        // die(json_encode($collection));
        $userPlan = [];
        foreach ($collection as $value) {
            $userPlan = $userPlan + [
                $value['user_id'] => [
                    'title' => $value['title'],
                    'activated_at' => $value['activated_at'],
                    'validity' => $value['validity']
                ]
            ];
        }

        return $userPlan;
    }

    public function getUserPlanAvailedTransaction()
    {
        // die('dfg');
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //$mongo->setSource("transaction_log");
        $collection = $mongo->getCollectionForTable('transaction_log');
        $aggregate = [];
        $collection = $collection->find()->toArray();
        // die(json_encode($collection));
        $collection = array_reverse($collection);

        $userPlan = [];
        foreach ($collection as $value) {
            if ($value['payment'] == 'paid') {
                $userPlan = $userPlan + [
                    $value['user_id'] => [
                        'title' => $value['plan_title'],
                        'activated_at' => $value['created_at'],
                    ]
                ];
            }
        }

        return $userPlan;
    }

    public function getAllUserOrdersCount($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
        $aggregate = [];
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
        $aggregate[] = [
            '$group' => [
                '_id' => [
                    'username' => '$username',
                ],
                'total' => [
                    '$sum' => 1,
                ],
            ],
        ];
        $res = $collection->aggregate($aggregate)->toArray();
        // die(json_encode($res));
        $userToOrder = [];
        foreach ($res as $value) {
            $userToOrder = $userToOrder + [
                $value['_id']['username'] => $value['total']
            ];
        }

        return $userToOrder;
    }

    public function getUsersData($userData)
    {
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
        $aggregate = [
            [
                '$match' => [
                    "shops" => ['$exists' => "true"]
                ]
            ],
            ['$sort' => ['_id' => -1]]
        ];
        if (count($filters)) {
            $conditionalQuery['$and'][] = self::searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => (int)$count];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $userDetails = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'message' => '', 'query' => $conditionalQuery, 'data' => [
            'rows' => $userDetails,
            'count' => $collection->count($filters)
        ]];
    }

    public function getRefineUsersData($userData)
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
        // die(json_encode($filters));
        $conditionalQuery = [];
        $aggregate[] =  ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$match' => ['shops' => ['$exists' => true], "exists" => true]];
        $aggregate[] = ['$addFields' => ['installed_at' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$install_at']]]];
        $aggregate[] = [
            '$addFields' => [
                'last_installed_date' => [
                    '$cond' => [
                        'if' => ['$eq' => [['$type' => '$last_install_date'], 'date']],
                        'then' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$last_install_date']],
                        'else' => '$last_install_date',
                    ],
                ],
            ],
        ];
        $aggregate[] = ['$addFields' => ['migration_start' => ['$arrayElemAt' => [['$split' => ['$migrationStatus.start_migration', 'T']], 0]]]];
        $aggregate[] = ['$addFields' => ['migration_end' => ['$arrayElemAt' => [['$split' => ['$migrationStatus.end_migration', 'T']], 0]]]];
        if (count($filters)) {
            // die("here");
            $conditionalQuery['$and'][] = self::searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => (int)$count];
        // die(json_encode($aggregate));
        $user_details = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, "data" => ['rows' => $user_details]];
    }

    public function getRefineFilteredCount($userData)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        $filters = isset($userData['filter']) ? $userData : [];
        $conditionalQuery = [];

        $aggregate[] =  ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$match' => ['shops' => ['$exists' => true], "exists" => true]];
        $aggregate[] = ['$addFields' => ['installed_at' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$install_at']]]];
        $aggregate[] = ['$addFields' => ['migration_start' => ['$arrayElemAt' => [['$split' => ['$migrationStatus.start_migration', 'T']], 0]]]];
        $aggregate[] = ['$addFields' => ['migration_end' => ['$arrayElemAt' => [['$split' => ['$migrationStatus.end_migration', 'T']], 0]]]];
        if (count($filters)) {
            $conditionalQuery['$and'][] = self::searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$count' => 'total'];
        // die(json_encode($aggregate));
        $count =  $collection->aggregate($aggregate)->toArray();
        $amazonCount = $count[0]['total'] ?? 0;
        return ['success' => true, 'count' => $amazonCount];
    }

    public function getSettleMentAmount($data)
    {
        $settlementAmount = 0;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('payment_details');
        $res = $collection->find(["user_id" => $data, "postpaid" => ['$exists' => true]])->toArray();
        if ($res && $res[0] && $res[0]['postpaid']['total_used_credits']) {
            $settlementAmount = ($res[0]['postpaid']['total_used_credits']) / ($res[0]['postpaid']['unit_qty']) * ($res[0]['postpaid']['per_unit_usage_price']);
            return $settlementAmount;
        }

        return $settlementAmount;
    }

    public function getMigrationStatus($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_migrate_progress');
        $res = $collection->find(["user_id" => $data])->toArray();
        if ($res) {
            return [
                'status' => $res[0]['completed'] ? "completed" : "in progress",
                'isMigrated' => true,
                'isCompleted' => $res[0]['completed'] ? true : false,
                'end_time' => $res[0]['end_migration'] ?? "NA",
                'start_time' => $res[0]['start_migration'] ?? "NA",
            ];
        }

        // print_r($res);
        // die();
        return ['isMigrated' => false, "status" => "New User"];
    }

    public function get_time_ago($time)
    {
        $time_diff = time() - $time;

        if ($time_diff < 1) {
            return 'less than 1 second ago';
        }

        $condition = [12 * 30 * 24 * 60 * 60 =>  'year', 30 * 24 * 60 * 60 =>  'month', 24 * 60 * 60 =>  'day', 60 * 60 =>  'hour', 60 =>  'minute', 1 =>  'second'];

        foreach ($condition as $secs => $str) {
            $d = $time_diff / $secs;

            if ($d >= 1) {
                $t = round($d);
                return 'about ' . $t . ' ' . $str . ($t > 1 ? 's' : '') . ' ago';
            }
        }
    }

    public function delayCheck($time)
    {
        $time_diff = time() - $time;
        $condition = [12 * 30 * 24 * 60 * 60 =>  'year', 30 * 24 * 60 * 60 =>  'month', 24 * 60 * 60 =>  'day', 60 * 60 =>  'hour', 60 =>  'minute', 1 =>  'second'];

        foreach ($condition as $secs => $str) {
            $d = $time_diff / $secs;

            if ($d >= 4 && $secs >= 60 * 60) {
                $t = round($d);
                return true;
            }

            return false;
        }
    }

    public function formatShopsData($data, &$install_status, $CollectionConfig)
    {
        $tempShops = [];
        $source_exists = false;
        $target_exists = false;
        foreach ($data['shops'] as $key => $value) {
            if (isset($value['sources'])) {
                $target_exists = true;
            }

            if (isset($value['targets'])) {
                $source_exists = true;
                $tempShops[$key]['shop_url'] = $value['domain'] ?? "NA";
                $tempShops[$key]['name'] = $value['name'] ?? "NA";
                $tempShops[$key]['shopify_plan'] = $value['plan_display_name'] ?? "NA";
                $tempShops[$key]['marketplace'] = $value['marketplace'] ?? "NA";
                $tempShops[$key]['marketplace'] = $value['marketplace'] ?? "NA";
                $tempShops[$key]['country_name'] = $value['country_name'] ?? "NA";
                $tempShops[$key]['country_code'] = $value['country_code'] ?? "NA";
                $tempShops[$key]['phone'] = $value['phone'] ?? "NA";
                $tempShops[$key]['currency'] = $value['currency'] ?? "NA";
                $tempShops[$key]['customer_email'] = $value['customer_email'] ?? "NA";
                $tempShops[$key]["created_at"] = $value["created_at"] ?? "NA";
                $tempShops[$key]['last_login_at'] = $value['last_login_at'] ?? "NA";
                $tempShops[$key]['last_updated_at'] = $value['updated_at'] ?? "NA";
                $tempShops[$key]['targets'] = $this->formatTargetShopsData($data, $value['targets'], $value['_id'], $CollectionConfig);
                $tempShops[$key]['id'] = $value['_id'];
            }
        }

        if ($source_exists && $target_exists) {
            $install_status = true;
        }

        return $tempShops;
    }

    public function formatTargetShopsData($data, $targets, $id, $CollectionConfig)
    {
        $tempShops = [];
        $targets = $this->getAllTargetIds($targets);

        $configBrand = $CollectionConfig->aggregate([
            ['$match' => ['user_id' => $data['user_id'], 'key' => "brand_registered", "group_code" => "product"]],
        ])->toArray();
        $configs = [];
        foreach ($configBrand as $convalue) {
            if ($convalue['target_shop_id'])
                $configs[$convalue['target_shop_id']] = $convalue['value'] ?? "NA";
        }

        foreach ($data['shops'] as $key => $value) {
            if (isset($value['sources'])) {
                $tempShops[$key]["marketplace"] = $value["marketplace"] ?? "NA";
                $tempShops[$key]["last_login_at"] = $value["last_login_at"] ?? "NA";
                $tempShops[$key]["last_updated_at"] = $value["updated_at"] ?? "NA";
                $tempShops[$key]["currency"] = $value["currency"] ?? "NA";
                $tempShops[$key]['seller_id'] =  $value['warehouses'][0]['seller_id'] ?? "NA";
                $tempShops[$key]['status'] = $value['apps'][0]['app_status'] ?? "NA";
                $tempShops[$key]["sellerName"] = $value["sellerName"] ?? "NA";
                $tempShops[$key]["warehouses"] = $value["warehouses"] ?? "NA";
                $tempShops[$key]['source_id'] = $id ?? "NA";
                $tempShops[$key]['target_id'] = $value['_id'] ?? "NA";
                // $tempShops[$key]['brand_registered'] =  $configs[$value['_id']] ? $configs[$value['_id']] : "NA";
                $tempShops[$key]['brand_registered'] =  $configs[$value['_id']] ?? "NA";
            }
        }

        return $tempShops;
    }

    public function getAllTargetIds($data)
    {
        $tempTargets = [];
        foreach ($data as $v) {
            array_push($tempTargets, $v['shop_id']);
        }

        return $tempTargets;
    }

    public function getShopPlan($plans)
    {
        $temp = [];
        foreach ($plans as $v) {
            if ($v['type'] == 'active_plan') {
                $temp['title'] = $v['plan_details']["title"] . "/" . $v['plan_details']["custom_price"];
                $temp['plan'] = $v['plan_details']["title"];
            }

            if ($v['type'] == 'user_service') {
                $temp["activated_on"] = $v["activated_on"];
                $temp["expired_at"] = $v["expired_at"];
                $temp["prepaid"]['available'] = $v["prepaid"]["available_credits"];
                $temp["prepaid"]['used'] = $v["prepaid"]['total_used_credits'];
                $temp["postpaid"]['available'] = $v["postpaid"]["available_credits"];
                $temp["postpaid"]['used'] = $v["postpaid"]['total_used_credits'];
            }
        }

        return $temp;
    }

    public static function checkInteger($key, $value)
    {

        if ($key == "connected_accounts" || $key == 'product_count' || $key == 'order_count' || $key == 'service_credits' || $key == "postpaid_credits") {
            $value = trim((string) $value);
            return (int)$value;
        }

        //  checking value of credits
        if (
            $key == "activePlanDetails.prepaid_available_credits" ||
            $key == "activePlanDetails.prepaid_used_credits" ||
            $key == "activePlanDetails.postpaid_available_credits" ||
            $key == "activePlanDetails.postpaid_used_credits"
        ) {
            $value = trim((string) $value);
            return (float)$value;
        }

        return trim((string) $value);
    }

    public static function searchMongo($filterParams = [])
    {

        $conditions = [];
        if (isset($filterParams['filter'])) {
            if (isset($filterParams['filter']['shops.marketplace'][1]) && $filterParams['filter']['shops.marketplace'][1] == 'amz') {
                unset($filterParams['filter']['shops.marketplace'][1]);
                $filterParams['filter']['shops.marketplace'][2] = 'amazon';
            }

            if (isset($filterParams['filter']['default.bda_allign.value'][1])) {
                $filterParams['filter']['default.bda_allign.value']['1'] = new ObjectId($filterParams['filter']['default.bda_allign.value']['1']);
            }

            if (isset($filterParams['filter']['default.com_med.value'][1])) {
                $filterParams['filter']['default.com_med.value']['1'] = new ObjectId($filterParams['filter']['default.com_med.value']['1']);
            }

            if (isset($filterParams['filter']['user_type.value']['1'])) {
                $filterParams['filter']['user_type.value']['1'] = new ObjectId($filterParams['filter']['user_type.value']['1']);
            }

            if (isset($filterParams['filter']['default.user_type.value']['1'])) {

                $filterParams['filter']['default.user_type.value']['1'] = new ObjectId($filterParams['filter']['default.user_type.value']['1']);
            }

            if (isset($filterParams['filter']['active_plan_app.plan_details.plan_id']['1'])) {
                $filterParams['filter']['active_plan_app.plan_details.plan_id']['1'] = (string)($filterParams['filter']['active_plan_app.plan_details.plan_id']['1']);
            }

            foreach ($filterParams['filter'] as $key => $value) {
                // if($key=='install_at'){
                //     continue;
                // }
                $key = trim((string) $key);

                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    //migrationStatus.completed

                    if ($value[self::IS_EQUAL_TO] == "noplan") {
                        $conditions['active_plan_app.plan_details.plan_id'] = ['$exists' => false];
                    } else if ($key == "completed") {
                        $conditions[$key] = $value[self::IS_EQUAL_TO] === "false" ? false : true;
                    } else if ($key == "migrationStatus.completed") {
                        if ($value[self::IS_EQUAL_TO] === "new user") {
                            $conditions[$key] = ['$exists' => false];
                        } else {
                            $conditions[$key] = $value[self::IS_EQUAL_TO] === "false" ? false : true;
                        }
                    } else if ($key == "step") {
                        $conditions[$key] = (int)$value[self::IS_EQUAL_TO];
                    } elseif ($key == 'reinstall') {
                        $conditions[$key] = $value[self::IS_EQUAL_TO] === "false" ? false : true;
                    } elseif ($key == 'store_closed_at') {
                        if ($value[self::IS_EQUAL_TO] == 'open') {
                            $conditions["shops.store_closed_at"] = ['$exists' => false];
                        } else if ($value[self::IS_EQUAL_TO] == 'close') {
                            $conditions["shops.store_closed_at"] = ['$exists' => true];
                        }
                    } else {
                        $conditions[$key] = self::checkInteger($key, $value[self::IS_EQUAL_TO]);
                    }

                    // $conditions[$key] = self::checkInteger($key, $value[self::IS_EQUAL_TO]);
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[$key] =  ['$ne' => self::checkInteger($key, trim((string) $value[self::IS_NOT_EQUAL_TO]))];
                } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                    if ($key == "completed") {
                        $conditions[$key] = $value[self::IS_CONTAINS] === "false" ? false : true;
                    } else {
                        $conditions[$key] = [
                            '$regex' =>  self::checkInteger($key, trim(addslashes((string) $value[self::IS_CONTAINS]))),
                            '$options' => 'i'
                        ];
                    }
                } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^((?!" . self::checkInteger($key, trim(addslashes((string) $value[self::IS_NOT_CONTAINS]))) . ").)*$",
                        '$options' => 'i'
                    ];
                    //
                } elseif (array_key_exists(self::GREATER_THAN, $value)) {
                    $conditions[$key] =  ['$gte' => self::checkInteger($key, trim((string) $value[self::GREATER_THAN]))];
                } elseif (array_key_exists(self::LESS_THAN, $value)) {
                    $conditions[$key] =  ['$lte' => self::checkInteger($key, trim((string) $value[self::LESS_THAN]))];
                }
                //
                elseif (array_key_exists(self::START_FROM, $value)) {
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
                    if ($key == 'shops.1.created_at' || $key == 'created_at') {
                        if ($key == 'shops.1.created_at') {
                            $conditions[$key] =  [
                                '$gte' => $value[self::RANGE]['from'],
                                '$lte' => $value[self::RANGE]['to']
                            ];
                        } else {
                            $conditions[$key] =  [
                                '$gte' => new UTCDateTime(strtotime((string) $value[self::RANGE]['from']) * 1000),
                                '$lte' => new UTCDateTime((strtotime((string) $value[self::RANGE]['to']) + 86399) * 1000)
                            ];
                        }
                    } else {
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
                    }
                } elseif (array_key_exists(self::CHECK_KEY_EXISTS, $value)) {
                    $conditions[$key] = ['$exists' => $value[self::CHECK_KEY_EXISTS] == 'true' ? true : false];
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
        $url = $userData['url'] ?? 'https://multi-account.sellernext.com/apps/amazon-multi/auth/login?admin_user_token= ';

        $user = User::findFirst([['user_id' => $userId]]);
        if ($user) {
            $date = new DateTime('+4 hour');
            $tokenObject = [
                "user_id" => (string)$userId,
                "role" => 'customer',
                "bda_user_id" => $this->di->getUser()->id,
                "bda_role" => $this->di->getUser()->role->code,
                "bda_username" => $this->di->getUser()->username,
                "exp" => $date->getTimestamp()
            ];

            $token = $this->di->getObjectManager()->get('\App\Core\Components\Helper')->getJwtToken($tokenObject, 'RS256', true);
            $url = $url . $token;
            $this->di->getObjectManager()->get(Helper::class)->createActivityLog([
                'action_type' => "store_login",
                'message' => $data['username'] ?? $this->di->getUser()->username . " performed store login for store - " . $user->username,
                "payload" => [
                    "user_id" => $userId,
                    "url" => $url
                ]
            ]);
            return ['success' => true, 'url' => $url];
        }

        return ['success' => false, 'message' => 'User not found', 'code' => 'user_not_found'];
    }


    public function getAppConfig()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //$mongo->setSource("app_config");
        $collection = $mongo->getCollectionForTable('app_config');
        $k = $collection->aggregate([['$limit' => 100]])->toArray();
        return ['success' => true, 'message' => '', 'data' => $k];
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


    public function getAppOrdersCount($data)
    {
        $mongo      = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
        $currentDate =  $data['from'] ?? 'today';
        $case = $data['type'];
        $aggregate = [];
        $userId = $this->di->getUser()->id;
        $timezone = "UTC";
        $specifier = [
            '$dayOfMonth' => '$converted_date'
        ];

        switch ($case) {
            case "Weekly":
                try {

                    $startDate = (new DateTime($currentDate, new DateTimeZone($timezone)));
                    $startDate->modify("monday this week");
                    $endDate = (new DateTime($currentDate, new DateTimeZone($timezone)));
                    $endDate->modify("sunday this week");
                } catch (Exception $e) {
                    return [
                        "success" => false,
                        "message" => $e->getMessage()
                    ];
                }

                $specifier = [
                    '$dayOfWeek' => '$converted_date'
                ];
                $subaggregate[] = [
                    '$match' => [
                        "placed_at" => [
                            '$gte' => $startDate->format(DateTime::ATOM),
                            '$lte' => $endDate->format(DateTime::ATOM)
                        ],
                        'status' => 'closed',
                        'user_id' => $userId
                    ]
                ];
                $subaggregate[] = [
                    '$project' => [
                        'totalPerListing' => [
                            '$sum' => [
                                '$map' => [
                                    'input' => '$line_items',
                                    'as' => 'listItem',
                                    'in' => [
                                        '$multiply' => [
                                            ['$toInt' => '$$listItem.quantity'],
                                            ['$toDouble' => '$$listItem.price']
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'converted_date' => [
                            '$dateFromString' => [
                                'dateString' => '$placed_at',
                                'format' => '%Y-%m-%d %H:%M:%S',
                            ]
                        ]
                    ]
                ];
                $subaggregate[] = [
                    '$group' => [
                        '_id' => $specifier,
                        'totalSales' => [
                            '$sum' => '$totalPerListing'
                        ],
                        'averageSales' => [
                            '$avg' => '$totalPerListing'
                        ]
                    ]
                ];

                break;
            case "Monthly":
                try {
                    $startDate = (new DateTime($currentDate, new DateTimeZone($timezone)));
                    $startDate->modify("first day of this month");
                    $endDate = (new DateTime($currentDate, new DateTimeZone($timezone)));
                    $endDate->modify("last day of this month");
                } catch (Exception $e) {
                    return [
                        "success" => false,
                        "message" => $e->getMessage()
                    ];
                }

                $specifier = [
                    '$ltrim' => ['input' => ['$substr' =>  ['$placed_at', 5, 2]], 'chars' => '0']
                ];
                $aggregate[] = [
                    '$match' => [
                        'status' => 'closed',
                        'user_id' => $userId
                    ]
                ];

                break;
            case "Yearly":
                try {
                    $startDate = (new DateTime($currentDate, new DateTimeZone($timezone)));
                    $startDate->modify("first day of january this year");
                    $endDate = (new DateTime($currentDate, new DateTimeZone($timezone)));
                    $endDate->modify("last day of december this year");
                } catch (Exception $e) {
                    return [
                        "success" => false,
                        "message" => $e->getMessage()
                    ];
                }

                $specifier = [
                    '$substr' => ['$placed_at', 0, 4]
                ];
                $aggregate[] = [
                    '$match' => [
                        'status' => 'closed',
                        'user_id' => $userId
                    ]
                ];
                break;
            default:
                try {
                    $startDate = (new DateTime($currentDate, new DateTimeZone($timezone)));
                    $endDate = (new DateTime($currentDate . ' +1 day', new DateTimeZone($timezone)));
                } catch (Exception $e) {
                    return [
                        "success" => false,
                        "message" => $e->getMessage()
                    ];
                }

                $aggregate[] = [
                    '$match' => [
                        "placed_at" => [
                            '$gte' => $startDate->format(DateTime::ATOM),
                            '$lte' => $endDate->format(DateTime::ATOM)
                        ],
                        'status' => 'closed',
                        'user_id' => $userId
                    ]
                ];
        }

        $aggregate[] = [
            '$group' => [
                '_id' => $specifier,
                'totalSales' => [
                    '$sum' => '$total_amount'
                ],
                'averageSales' => [
                    '$avg' => '$total_amount'
                ]
            ]
        ];

        $options = [
            [
                'typeMap' => [
                    'root' => "array",
                    'document' => "array"
                ]
            ]
        ];
        try {
            if ('Weekly' === $case) {
                $aggregate = $subaggregate;
            }

            $sales = $collection->aggregate($aggregate, $options);
            $aggregate = [];
            $aggregate[] = [
                '$match' => [
                    'status' => "closed"
                ]
            ];
            $aggregate[] = [
                '$group' => [
                    '_id' => '$status',
                    'count' => [
                        '$sum' => 1
                    ]
                ]
            ];
            $statusCount = $collection->aggregate($aggregate, $options);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if ($sales && $statusCount) {
            return [
                'success' => true,
                'data' => $sales->toArray(),
                'status_count' => $statusCount->toArray(),
            ];
        }

        return [
            'success' => false,
            'data' => []
        ];
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
                    'shops.0.updated_at' => [
                        '$gte' => $data['from'],
                        '$lte' => $data['to'],
                    ],
                ],
            ],
            ['$lookup' => ['from' => 'admin_config', 'localField' => 'default.user_type.value', 'foreignField' => '_id', 'as' => 'user_data']],
            ['$group' => ['_id' => ['$arrayElemAt' => ['$user_data.name', 0]], 'total' => ['$sum' => 1]]],
        ];
        $res = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'message' => '', 'data' => $res];
    }


    public function getFailedOrder($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //$mongo->setSource("order_container");
        // die("dsf");
        $collection = $mongo->getCollectionForTable('order_container');
        $aggregate = [
            [


                '$addFields' => [
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
                    'created_at' => [
                        '$gte' => $data['from'],
                        '$lte' => $data['to'],
                    ],
                ],
            ],



            // [
            //     '$lookup' =>[
            //     'from' => 'admin_config',
            //     'localField' => 'default.user_type',
            //     'foreignField' => '_id', 'as' => 'user_data'
            //     ]
            // ],
            [
                '$group' => [
                    '_id' => '$status',
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
            array_push($steps_data, ['_id' => $key, 'total' => $value]);
        }

        return ['success' => true, 'message' => '', 'data' => $steps_data];
    }

    public function addAppConfig($item)
    {
        // unset($item['target_marketplace']);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //$mongo->setSource("app_config");
        $collection = $mongo->getCollectionForTable('app_config');
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

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
        // $data = $collection->aggregate([
        //     [
        //         '$match' => ["user_id" => $id],
        //     ],
        //     [
        //         "\$group" => [
        //             "_id" => "\$status",
        //             "count" => ["\$sum" => 1],
        //         ],
        //     ],
        // ])->toArray();
        // return ["data" => $data, "success" => true];

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


    public function editAdminConfig($item)
    {

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



    ///csv STart
    public const Exportkeys = [
        "last_sync" => [
            'key' => 'last sync',
            'value' => 'last_sync'
        ],
        "install_status" => [
            'key' => 'install_status',
            'value' => 'install_status'
        ],
        "order_sync" => [
            'key' => 'order_sync',
            'value' => 'order_sync'
        ],
        "seller_type" => [
            'key' => 'seller_type',
            'value' => 'seller_type'
        ],
        "user_id" => [
            'key' => 'user_id',
            'value' => 'user_id'
        ],
        "name" => [
            'key' => 'name',
            'value' => 'name'
        ],
        "username" => [
            'key' => 'username',
            'value' => 'username'
        ],
        "email" => [
            'key' => 'email',
            'value' => 'email'
        ],
        "shop_url" => [
            'key' => 'shop url',
            'value' => 'shop_url'
        ],
        "migrationStatus" => [
            'key' => 'migrationStatus',
            'value' => 'migrationStatus'
        ],
        "contact_number" => [
            'key' => 'contact number',
            'value' => 'contact_no'
        ],
        "shopify_plan" => [
            'key' => 'shopify plan',
            'value' => 'shopify_plan'
        ],
        "connected_accounts" => [
            'key' => 'connected accounts',
            'value' => 'connected_accounts'
        ],
        "product_count" => [
            'key' => 'product count',
            'value' => 'product_count'
        ],
        "order_count" => [
            'key' => 'order count',
            'value' => 'order_count'
        ],
        "app_plan" => [
            'key' => 'app plan',
            'value' => 'activePlanDetails.app_plan'
        ],
        "install_at" => [
            'key' => 'install at',
            'value' => 'install_at'
        ],
        "country" => [
            'key' => 'country',
            'value' => 'shops.country_name'
        ],
        "reinstall_user" => [
            'key' => 'reinstall_user',
            'value' => 'reinstall'
        ],
        "last_install_date" => [
            'key' => 'last_install_date',
            'value' => 'last_install_date'
        ],
        "last_uninstall_date" => [
            'key' => 'last_uninstall_date',
            'value' => 'last_uninstall_date'
        ],
        "app_plan_billing_type" => [
            'key' => 'app_plan_billing_type',
            'value' => 'activePlanDetails.billed_type'
        ],
        "app_plan_category" => [
            'key' => 'app_plan_category',
            'value' => 'activePlanDetails.category'
        ],
        "app_offer_user" => [
            'key' => 'app_offer_user',
            'value' => 'offer'
        ],
    ];

    public function getAndSaveFilePath($fileName)
    {
        $userId = $this->di->getUser()->id;
        $basePath = BP . DS . 'var/log/adminCSV/' . $userId . "/";

        if (!file_exists($basePath)) {
            mkdir($basePath, 0777, true);
            chmod($basePath, 0777);
        }

        $path = $basePath . $fileName . ".csv";
        return $path;
    }

    public function createHeader($path, $line)
    {
        if (!file_exists($path)) {
            $file = fopen($path, 'w');
            chmod($path, 0777);
        } else {
            $file = fopen($path, 'w');
        }

        if (count($line) > 0) {
            fputcsv($file, $line);
        } else {
            return false;
        }

        return true;
    }

    public function getCSVDownloadURL($path, $name)
    {
        $CSVFile = [
            'file_path' => $path
        ];
        $token = $this->di->getObjectManager()
            ->get('App\Core\Components\Helper')
            ->getJwtToken($CSVFile, 'RS256', false);
        $url = 'frontend/adminpanelamazonmulti/downloadCSV?file_token=' . $token;
        $this->di->getObjectManager()->get(Helper::class)->createActivityLog(['action_type' => "csv_export", "message" => "csv export", "payload" => [
            "url" => "'frontend/adminpanelamazonmulti/downloadCSV?file_token=' . {$token}",
            "message" => "initiated csv export process (" . $name . ").",
            "username" => $this->di->getUser()->username,
            "role" => $this->di->getUser()->role->code
        ]]);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        $collection->UpdateOne(['user_id' => $this->di->getUser()->id, "type" => "urls"], ['$set' => [$name => $url]], ['upsert' => true]);
        return $url;
    }

    public function getUserCsvDownloadUrl($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');
        $collectionQueued = $mongo->getCollectionForTable('queued_tasks');
        $task = $collectionQueued->count(['user_id' => $this->di->getUser()->id, 'worker_name' => "amazon_admin_panel_csv_export", "type" => $data['name']]);
        if ((int)$task) {
            return ['success' => false, 'message' => 'export in progress'];
        }

        $user = $collection->find(['user_id' => $this->di->getUser()->id, 'type' => 'urls'])->toArray();
        $url = false;
        foreach ($user as $v) {
            if (isset($v[$data['name']]))
                $url = $v[$data['name']];
        }

        if ($url) {
            return ['success' => true, 'url' => $url];
        }

        return ['success' => false, 'message' => 'no url found'];
    }

    public function generateReportCSV($report)
    {
        $headers = ['user_id'];
        $lines = [];

        // $path = $this->getAndSaveFilePath($data['name']);
        if ($report === 'yearlyOrders') {
            $orders = $this->getYearlyOrMonthlyOrders(['yearly' => 'true', 'userwise' => 'true', 'csv' => true]);
        } else if ($report == 'monthlyOrders') {
            $orders = $this->getYearlyOrMonthlyOrders(['userwise' => 'true', 'csv' => true]);
        } else if ($report == '1') {
            $orders = $this->getPlanWiseOrdersData(['yearly' => 'false', 'plan_id' => 1, 'csv' => true]);
        } else if ($report == '2') {
            $orders = $this->getPlanWiseOrdersData(['yearly' => 'false', 'plan_id' => 2, 'csv' => true]);
        } else if ($report == '3') {
            $orders = $this->getPlanWiseOrdersData(['yearly' => 'false', 'plan_id' => 3, 'csv' => true]);
        } else if ($report == '4') {
            $orders = $this->getPlanWiseOrdersData(['yearly' => 'false', 'plan_id' => 4, 'csv' => true]);
        } else if ($report == '5') {
            $orders = $this->getPlanWiseOrdersData(['yearly' => 'false', 'plan_id' => 5, 'csv' => true]);
        } else if ($report == '6') {
            $orders = $this->getPlanWiseOrdersData(['yearly' => 'false', 'plan_id' => 6, 'csv' => true]);
        } else if ($report == '7') {
            $orders = $this->getPlanWiseOrdersData(['yearly' => 'false', 'plan_id' => 7, 'csv' => true]);
        } else if ($report == '8') {
            $orders = $this->getPlanWiseOrdersData(['yearly' => 'false', 'plan_id' => 8, 'csv' => true]);
        }

        foreach ($orders as $key => $value) {
            array_push($headers, $value['_id'] ?? $key);
            foreach ($value['users'] as $v) {
                $lines[$v['user_id']][$value['_id']] = $v['total'];
            }
        }

        $header = $this->createHeader($path, $headers);
        // $file = fopen($path, 'a');
        $url = $this->getCSVDownloadURL($path, $report);
        $csvData = [$headers];
        foreach ($lines as $key => $value) {
            // fputcsv($file, $this->getReportLine($headers,$key,$value));
            array_push($csvData, $this->getReportLine($headers, $key, $value));
        }

        return ['success' => true, 'data' => $csvData, 'url' => $url];
    }

    public function getReportLine($columns, $user_id, $orders)
    {
        $line = [];
        foreach ($columns as $v) {
            if ($v == 'user_id') {
                array_push($line, $user_id);
            } else {
                array_push($line, $orders[$v] ?? 0);
            }
        }

        return $line;
    }

    public function exportCSV($data)
    {
        if (isset($data['report'])) {
            return $this->generateReportCSV($data['report']);
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('queued_tasks');
        $task = $collection->count(['user_id' => $this->di->getUser()->id, 'worker_name' => "amazon_admin_panel_csv_export", "type" => $data['name']]);
        if ((int)$task) {
            return ['success' => false, 'message' => 'export already in progress'];
        }

        $mandotaryField = [];
        $filter_keys = false;

        if (isset($data['keys']) && strlen((string) $data['keys']) > 0) {
            $data['keys'] = explode(",", (string) $data['keys']);
            // die(json_encode($mandotaryField));
            foreach ($data['keys'] as $v) {
                array_push($mandotaryField, SELF::Exportkeys[$v]['key']);
            }

            $filter_keys = true;
            // die(json_encode($mandotaryField));
        } else {
            $mandotaryField = [
                'install_status',
                'user_id',
                'username',
                'name',
                'email',
                'order_sync',
                'shop url',
                'migration_status',
                'contact number',
                'shopify plan',
                'app plan',
                'connected accounts',
                'product count',
                'order count',
                'install at'
            ];
        }

        $path = $this->getAndSaveFilePath($data['name']);
        $header = $this->createHeader($path, $mandotaryField);
        $filter = false;
        $aggregate = [];
        $filters = isset($data['filter']) ? $data : [];
        if (count($filters)) {
            $filter = true;
            $conditionalQuery['$and'][] = self::searchMongo($filters);
            $aggregate = ['$match' => $conditionalQuery];
        }

        if ($header) {
            $url = $this->getCSVDownloadURL($path, $data['name']);
            $sqsData = [
                'keys' => 'all',
                'path' => $path,
                'skip' => 0,
                'type' => $data['name'],
                'filter' => $filter,
                'filterAggregate' => $aggregate
            ];
            if ($filter_keys) {
                $sqsData['keys'] = $data['keys'];
            }

            $this->startCSVExportSQS($sqsData);
            return ['success' => true, 'url' => $url, 'message' => 'export in progress'];
        }
        return ['success' => false, 'message' => 'no keys found for exporting'];
    }

    public function startCSVExportSQS($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('queued_tasks');

        $SqsObject = new  SQSWorker;
        $newData = [
            'class_name' => \App\Frontend\Components\AdminpanelamazonmultiHelper::class,
            'method_name' => 'WriteCSVSQS',
            'params' => $data,
            'user_id' => $this->di->getUser()->id,
            'worker_name' => "amazon_admin_panel_csv_export",
            'message' => "export Process Initiated",
            'process_code' => "amazon_admin_panel_csv_export",
        ];

        $collection->insertOne(['user_id' => $this->di->getUser()->id, 'worker_name' => "amazon_admin_panel_csv_export", "type" => $data['type']]);
        $SqsObject->CreateWorker($newData, true);
        return ['success' => true, 'message' => 'process initiated'];
    }

    public function WriteCSVSQS($sqsData)
    {
        $params = $sqsData['data']['params'];
        $path = $params['path'];
        $skip = $params['skip'];
        $type = $params['type'] ?? "all";
        $filter = $params['filter'] ?? false;
        $file = fopen($path, 'a');
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_user_details');

        $aggregate[] = ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$match' => ['shops' => ['$exists' => true], "exists" => true]];
        if ($filter) {
            $aggregate[] = ['$addFields' => ['installed_at' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$install_at']]]];
            $aggregate[] = ['$addFields' => ['migration_start' => ['$arrayElemAt' => [['$split' => ['$migrationStatus.start_migration', 'T']], 0]]]];
            $aggregate[] = ['$addFields' => ['migration_end' => ['$arrayElemAt' => [['$split' => ['$migrationStatus.end_migration', 'T']], 0]]]];

            $aggregate[] = $params['filterAggregate'];
        }

        if ($type == "uninstall Users") {
            $aggregate[] = ['$match' => ['install_status' => "uninstalled"]];
        }

        if ($type == "Free Plan Users") {
            $aggregate[] = ['$match' => ['activePlanDetails.plan_id' => "1"]];
        }

        if ($type == "Paid Plan Users") {
            $aggregate[] = ['$match' => ['activePlanDetails.plan_id' => ['$gt' => "1"]]];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => 1000];
        // print_r($aggregate) . PHP_EOL;
        $users = $collection->aggregate($aggregate)->toArray();
        // print_r($users);
        $lastKey = array_key_last($users);
        if ($lastKey >= 999) {
            foreach ($users as $v) {
                fputcsv($file, $this->createLine($params['keys'], $v));
            }

            $params['skip'] = $skip + 1000;
            $sqsData['data']['params'] = $params;
            $SqsObject = new  SQSWorker;
            $SqsObject->pushToQueue($sqsData);
        } else {
            if (count($users) > 0) {
                foreach ($users as $v) {
                    // print_r($this->createLine($params['keys'], $v));
                    fputcsv($file, $this->createLine($params['keys'], $v));
                }

                // echo "process completed";
                $collection1 = $mongo->getCollectionForTable('queued_tasks');
                $collection1->deleteOne(['user_id' => $this->di->getUser()->id, 'worker_name' => "amazon_admin_panel_csv_export", "type" => $type]);
                return true;
            }
            $collection1 = $mongo->getCollectionForTable('queued_tasks');
            $collection1->deleteOne(['user_id' => $this->di->getUser()->id, 'worker_name' => "amazon_admin_panel_csv_export", "type" => $type]);
            return true;
        }
    }

    public function createLine($columns, $value)
    {
        $line = [];
        if ($columns == 'all') {
            $columns = array_keys(SELF::Exportkeys);
        }

        foreach ($columns as $v) {
            if ($v == 'migrationStatus') {
                $val = isset($value['migrationStatus']) ? "migrated" : "new user";
            } else if ($v == 'app_plan') {
                $val = $value['activePlanDetails']['app_plan'] ?? "NA";
            } else if ($v === 'country') {
                $val = $value['shops'][0]['country_name'] ?? "NA";
            } else {
                $val = $value[SELF::Exportkeys[$v]['value']] ?? "NA";
            }

            array_push($line, $val);
        }

        return $line;
    }

    public function pushToQueue($sqsData)
    {
        $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        return
            $rmqHelper->pushMessage($sqsData);;
    }

    //    csv end
    // reporting section



    public function getYearlyOrMonthlyOrders($data)
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

    public function getPlanWiseOrdersData($data)
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

    public function getPlanWiseUserId($plan_id)
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

    // reporting section end

    public function getUserProductsCount($id, $source = false)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('product_container');
        if ($source) {
            $data = $collection->count(["user_id" => $id, "shop_id" => $source, 'visibility' => "Catalog and Search"]);
            return ['success' => true, 'count' => $data];
        }
        $data = $collection->count(["user_id" => $id]);

        return $data;
    }

    public function getSingleUserOrdersCount($id, $shop_id = false)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
        if ($shop_id) {
            $data = $collection->count(["user_id" => $id, "marketplace_shop_id" => $shop_id, "object_type" => "source_order"]);
            return ['success' => true, 'count' => $data, 'failed' => $this->getUserFailedOrdersCount($id)];
        }

        $data = $collection->count(["user_id" => $id, 'object_type' => 'source_order', '$or' => [['status' => 'failed'], ['status' => 'Failed']]]);
        return $data;
    }

    public function getUserFailedOrdersCount($id)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
        $data = $collection->count(['user_id' => $id, "object_type" => "source_order", 'status' => "failed"]);

        // return ["data" => $data, 'ordercount' => $data, "success" => true];
        return $data;
    }

    public function getOrderAnalysis($params)
    {
        if (isset($params)) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            // $mongo->setSource("user_details");
            // $collection = $mongo->getCollection();
            $collection = $mongo->getCollectionForTable('user_details');
            $aggregation = [];
            if (isset($params['count'])) {
                $limit = $params['count'];
            }

            $aggregation[] = ['$match' => ['shops.marketplace' => 'amazon']]; //If amazon is connected
            $aggregation[] = [
                '$project' => ['_id' => 0, 'user_id' => 1, 'username' => 1]
            ];
            if (isset($params['filters']['text'])) {
                $aggregation[] = [
                    '$match' => [
                        'username' => ['$regex' => (string)$params['filters']['text']],
                    ]
                ];
            }

            $aggregation[] = [
                '$sort' => ['_id' => 1]
            ];
            $aggregation2 = $aggregation;
            $count = count($collection->aggregate($aggregation2)->toArray());

            if (isset($params['activePage']) && $params['activePage'] > 1) {
                $page = $params['activePage'];
                $offset = ($page - 1) * $limit;
                $aggregation[] = ['$skip' => $offset];
            }

            $aggregation[] = ['$limit' => (int) $limit];
            $temp = [];
            $response = $collection->aggregate($aggregation)->toArray();
            $data = [];
            foreach ($response as $value) {
                $data[] = $this->getOrdersCount($value['user_id'], $value['username'], $params['filters']['date']);
            }

            return ['success' => true, 'data' => $data, 'count' => count($data), 'totalCount' => $count];
        }
        return ['success' => false, 'data' => ''];
    }

    public function getOrdersCount($id, $name, $filterbydate)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
        $aggregation = [];
        $aggregation[] = [
            '$match' => ["user_id" => $id]
        ];
        if (isset($filterbydate)) {
            $aggregation[] =
                ['$match' => ['placed_at' => [
                    '$gte' => '2021-' . $filterbydate . '-01',
                    '$lte' => '2021-' . $filterbydate . '-31',
                ]]];
        }

        $aggregate = [
            [
                '$addFields' => [
                    'date_fields' => [
                        [
                            '$arrayElemAt' => [
                                ['$split' => ["\$placed_at", "T"]],
                                0
                            ]
                        ]
                    ]
                ]
            ],
            [
                "\$group" => [
                    "_id" => "\$target_status",
                    "count" => ["\$sum" => 1],
                    "total_price" => ['$push' => ['price' => '$total_price', 'currency' => '$currency']]
                ]
            ]
        ];
        $aggregation = [...$aggregation, ...$aggregate];
        $data = $collection->aggregate($aggregation)->toArray();
        $totalOrder = 0;
        foreach ($data as $value) {
            $totalOrder = $totalOrder + $value['count'];
        }

        return ['id' => $id, 'total_order' => $totalOrder, 'name' => $name];
    }


    public function getTotalOrders($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');


        $collection = $mongo->getCollectionForTable('order_container');
        $aggregate = [
            [


                '$addFields' => [
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
                    'created_at' => [
                        '$gte' => $data['from'],
                        '$lte' => $data['to'],
                    ],
                ],
            ],



            // [
            //     '$lookup' =>[
            //     'from' => 'admin_config',
            //     'localField' => 'default.user_type',
            //     'foreignField' => '_id', 'as' => 'user_data'
            //     ]
            // ],
            [
                '$group' => [
                    '_id' => '$status',
                    'total' => [
                        '$sum' => 1
                    ]
                ]
            ],

        ];

        $res = $collection->aggregate($aggregate)->toArray();


        return ['success' => true, 'message' => '', 'data' => $res];
    }

    public function getAllPlans()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("plan");
        $data = $collection->find()->toArray();
        return ['success' => true,  'data' => $data];
    }

    public function getPlan($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("plan");
        $data = $collection->find(['plan_id' => $data['id']])->toArray();
        return ['success' => true,  'data' => $data];
    }

    //
    public function getProduct($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("product_container");
        $aggregate = [
            'user_id' => $data['user_id'],
            'shop_id' => $data['shop_id'],
            '$or' => [['source_product_id' => $data['id']], ['container_id' => $data['id']]]
        ];

        $data = $collection->find($aggregate)->toArray();

        if (count($data) < 1) {
            return ['success' => false, 'message' => "Invalid Id"];
        }

        return ['success' => true, 'data' => $this->prepareProductData($data)];
    }

    public function prepareProductData($data)
    {
        foreach ($data as $v) {
            if ($v["visibility"] === "Catalog and Search") {
                $product['parent'] = $v;
            } else {
                $product['variants'][] = $v;
            }
        }

        return $product;
    }



    // order section new apis


    public function getAllUserFailedOrdersCount($data)
    {
        $conditionalQuery = [];
        $conditionalQuery = [
            'source_created_at' => [
                '$gte' => $data['from'],
                '$lte' => $data['to'],
            ],
            'object_type' => "source_order",
            "targets.status" => "failed"
        ];


        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');
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
        if (isset($data['username'])) {
            $aggregate[] = [
                '$match' => ['username' => [
                    '$regex' => $data['username']
                ]]
            ];
        }

        $aggregate[] = [
            '$group' => [
                '_id' => [
                    'username' => '$username',
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

        $res = $collection->aggregate($aggregate)->toArray();
        $response = $this->formatFailedOrderCountResponse($res);
        return [
            'success' => true,
            'test' => $res,
            // 'data' => $response['graphArray'],
            // 'userData' => $response['userArray'],
        ];
    }

    public function formatFailedOrderCountResponse($data): void
    {
        $tempResponse = [];
        foreach ($data as $v) {
            array_push(['users' => $v['_id']['username']]);
        }
    }





    public function getMigrationCount()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        // $collection1 = $mongo->getCollectionForTable('user_details');
        $collection2 = $mongo->getCollectionForTable('user_migrate_progress');
        // $totalUsers = $collection1->count(['shops'=>['$exists'=>true]]);
        $totalMigration = $collection2->aggregate([['$group' => ['_id' => '$completed', 'total' => ['$sum' => 1]]]])->toArray();
        $response = [];
        foreach ($totalMigration as $v) {
            $response[$v['_id'] ? "completed" : "inProgress"] = $v["total"];
        }

        $response['total'] = $response['completed'] + $response['inProgress'];
        return ['success' => true, 'data' => $response];
    }



    public function syncUserCollections($data)
    {
        if (isset($data['user_id']) || isset($data['username'])) {
            return $this->startUserCollectionSync($data, isset($data['stream']) ? true : false);
        }
        if (isset($data['duration'])) {
            if ($data['duration'] == 'today') {
                return $this->startCollectionSyncSQS(['users' => 'today', 'user_id' => 'start', 'skip' => 0, 'limit' => 1000, 'sync_id' => random_int(0, mt_getrandmax())]);
            }
        } else {
            return $this->startCollectionSyncSQS(['users' => 'all', 'user_id' => 'start', 'skip' => 0, 'limit' => 1000, 'sync_id' => random_int(0, mt_getrandmax())]);
        }
    }

    public function startCollectionSyncSQS($data)
    {
        $SqsObject = new  SQSWorker;
        $newData = [
            'class_name' => \App\Frontend\Components\AdminpanelamazonmultiHelper::class,
            'method_name' => 'startUserCollectionSync',
            'params' => $data,
            'user_id' => $this->di->getUser()->id,
            'worker_name' => "admin_panel_user_sync",
            'message' => "Sync Process Initiated",
            'process_code' => "admin_panel_user_sync"
        ];
        $SqsObject->CreateWorker($newData, true);
        return ['success' => true, 'message' => 'process initiated'];
        // $this->startUserCollectionSync($data);
    }

    public function startUserCollectionSync($data, $stream = false)
    {
        echo "process start";
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $syncId = false;
        $condition = ['shops' => ['$exists' => true]];
        if (isset($data['data']['params'])) {
            $params = $data['data']['params'];
            $syncId = $params['sync_id'];
            // echo $params['skip'] . " ";
            if ($params['users'] == "all") {

                if ($params['user_id'] == 'start') {
                    $user_details = $collection->aggregate(
                        [
                            ['$sort' => ['_id' => 1]],
                            [
                                '$match' => [
                                    'shops' => ['$exists' => true]
                                ]
                            ],
                            ['$limit' => 100]
                        ]
                    )->toArray();
                } else {
                    $user_details = $collection->aggregate(
                        [
                            ['$sort' => ['_id' => 1]],
                            ['$match' => [
                                'shops' => ['$exists' => true],
                                '_id' => ['$gt' => new ObjectId($params['user_id'])]
                            ]],
                            ['$limit' => 100]
                        ]
                    )->toArray();
                }
            } else if ($params['users'] == 'today') {
                if ($params['user_id'] == 'start') {
                    $user_details = $collection->aggregate(
                        [
                            ['$sort' => ['_id' => 1]],
                            ['$addFields' => ['install_at' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at']]]],
                            [
                                '$match' => [
                                    'shops' => ['$exists' => true],
                                    'install_at' => ['$gte' => date('Y-m-d')],
                                ]
                            ],
                            ['$limit' => 100]
                        ]
                    )->toArray();
                } else {
                    $user_details = $collection->aggregate(
                        [
                            ['$sort' => ['_id' => 1]],
                            ['$addFields' => ['install_at' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at']]]],
                            [
                                '$match' => [
                                    'shops' => ['$exists' => true],
                                    'install_at' => ['$gte' => date('Y-m-d')],
                                    '_id' => ['$gt' => new ObjectId($params['user_id'])]
                                ]
                            ],
                            ['$limit' => 100]
                        ]
                    )->toArray();
                }
            }
        } else {
            if (isset($data['user_id'])) {
                $condition['user_id'] = $data['user_id'];
            }

            if (isset($data['username'])) {
                $condition['username'] = $data['username'];
            }

            $user_details = $collection->find(
                $condition
            )->toArray();
        }

        $lastKey = array_key_last($user_details);
        if ($lastKey >= 99) {
            // print_r($lastKey);
            $success =  $this->formatRefineUserData($user_details, $mongo, $syncId, $stream);
            if ($success['success']) {
                $params['skip'] = $params['skip'] + $params['limit'];
                $params['user_id'] = $user_details[$lastKey]['user_id'];
                $data['data']['params'] = $params;
                $SqsObject = new  SQSWorker;
                $SqsObject->pushToQueue($data);
            } else {
                return true;
            }
        } else {
            if (count($user_details) > 0) {

                $success =  $this->formatRefineUserData($user_details, $mongo, $syncId, $stream);
                if (isset($data['data']['params'])) {
                    if ($params['users'] == "all") {
                        $this->updateNonExistingUsers($syncId, $mongo);
                        // $helper = $this->di->getObjectManager()->get('\App\Frontend\Components\AmazonMulti\FreshSalesRequiredData');
                        // $helper->getProductAndOrdersCount();
                        // $today = date('j'); // Get the current day of the month (1-31)
                        // if ($today === '1') {
                        //     $helper->sendGMVData();
                        // }
                    }

                    return true;
                }

                return ['success' => true, 'message' => "sync successful", 'data' => $success['data']];
            }
            return true;
        }
    }

    public function addReinstallInfo($userData, $source, $refineData)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('uninstall_user_details');
        $usercollection = $mongo->getCollection('user_details');

        // check the data source app_status
        $userDetailsQuery = [
            'username' => (string)$userData['username'],
            'email' => (string)$userData['email'],
        ];
        $userDetails = $usercollection->find($userDetailsQuery)->toArray();
        //  $Details = json_decode($userDetails, true);
        foreach ($userDetails as $user) {
            $shops = $user['shops'][0];
        }
        $oldUserQuery = [ //need to test as created data can be in iso format as well
            ['$match' => [
                'username' => $userData['username'] ?? "",
                'email' => $userData['email'] ?? "",
                'shops' => ['$exists' => true]
            ]],
            [
                '$unwind' => '$shops'
            ],
            [
                '$match' => [
                    'shops.marketplace' => $source
                ]
            ],
            [
                '$sort' => [
                    "shops.apps.uninstall_date" => -1
                ]
            ],
            [
                '$limit' => 1
            ]
        ];
        $oldUserDetails = $collection->aggregate($oldUserQuery, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
        $oldUserDetails = $oldUserDetails[0] ?? [];
        if (!empty($oldUserDetails) && $shops['apps'][0]['app_status'] == 'active') {
            $refineData['reinstall'] = true;
            $refineData['last_install_date'] = $oldUserDetails['installation_date'] ?? ($oldUserDetails['shops']['created_at'] ?? "NA");
            $refineData['last_uninstall_date'] = 'NA';
            if (isset($oldUserDetails['shops']['apps'])) {
                foreach ($oldUserDetails['shops']['apps'] as $app) {
                    $refineData['last_uninstall_date'] = $app['uninstall_date'] ?? 'NA';
                }
            }
        } else {
            $refineData['reinstall'] = false;
        }

        return $refineData;
    }

    // public function updateNonExistingUsers($syncId, $mongo)
    // {
    //     $collectionRefine = $mongo->getCollectionForTable('refine_user_details');
    //     $filter = ['sync_id' => ['$ne' => $syncId], 'exists' => true];
    //     $update = ['$set' => ["exists" => false, "install_status" => "uninstalled"]];
    //     $chunkSize = 1000;
    //     $cursorItem = [];
    //     $totalDocuments = $collectionRefine->count($filter);
    //     $start = 0;

    //     while ($start < $totalDocuments) {
    //         $cursorItem = $collectionRefine->aggregate([['$match' => $filter], ['$skip' => $start], ['$limit' => $chunkSize]])->toArray();
    //         $bulkQueryArray = [];
    //         foreach ($cursorItem as $document) {
    //             array_push($bulkQueryArray, [
    //                 "updateOne" => [
    //                     ["_id" => $document['_id']],
    //                     $update
    //                 ]
    //             ]);
    //         }
    //         $collectionRefine->bulkWrite($bulkQueryArray);
    //         $start += $chunkSize;
    //     }
    //     // $collectionRefine->updateMany(['sync_id' => ['$ne' => $syncId], 'exists' => true], ['$set' => ["exists" => false, "install_status" => "uninstalled"]]);
    // }
    public function updateNonExistingUsers($syncId, $mongo): void
    {
        $collectionRefine = $mongo->getCollectionForTable('refine_user_details');
        $collectionRefine->updateMany(['sync_id' => ['$ne' => $syncId], 'exists' => true], ['$set' => ["exists" => false, "install_status" => "uninstalled"]]);
    }

    public function formatRefineUserData($data = [], $mongo = null, $syncId = null, $stream = null)
    {
        // die(json_encode($data));
        if (!count($data)) {
            return ['success' => false, "message" => "user not exists"];
        }

        $collectionRefine = $mongo->getCollectionForTable('refine_user_details');
        $CollectionConfig = $mongo->getCollectionForTable('config');
        $refineUserData = [];
        $tempUserIds = [];
        foreach ($data as $value) {
            array_push($tempUserIds, $value['user_id'] ?? "NA");
            $refineUserData[$value['user_id']]['_id'] = new ObjectId($value['user_id']);
            $date = new DateTime('+4 hour');
            $tokenObject = [
                "user_id" => (string)$value['user_id'],
                "role" => 'customer',
                "bda_user_id" => $this->di->getUser()->id,
                "bda_role" => $this->di->getUser()->role->code,
                "bda_username" => $this->di->getUser()->username,
                "exp" => $date->getTimestamp()
            ];

            $token = $this->di->getObjectManager()->get('\App\Core\Components\Helper')->getJwtToken($tokenObject, 'RS256', true);
            $listing = $CollectionConfig->aggregate([
                ['$match' => ['user_id' => $value['user_id'], 'key' => "sellerStatus"]],
            ])->toArray();
            $configSync = $CollectionConfig->aggregate([
                ['$match' => ['user_id' => $value['user_id'], 'key' => "order_sync"]],
            ])->toArray();
            $configBrand = $CollectionConfig->aggregate([
                ['$match' => ['user_id' => $value['user_id'], 'key' => "brand_registered", "group_code" => "product"]],
            ])->toArray();
            $superChargeStatus = $CollectionConfig->find(
                ['key' => 'super_charge_listing', 'user_id' => $value['user_id']]
            )->toArray();
            $superChargeStatus = !empty($superChargeStatus) ? $superChargeStatus[0]['value'] : "not_set";
            $superChargeAction = $superChargeStatus === "not_set" ? false : true;
            $refineUserData[$value['user_id']]['seller_type'] = !empty($listing) ? $listing[0]['value'] : false;
            $refineUserData[$value['user_id']]['token'] = $token;
            $refineUserData[$value['user_id']]['superChargeStatus'] = $superChargeStatus;
            $refineUserData[$value['user_id']]['superChargeAction'] = $superChargeAction;
            $refineUserData[$value['user_id']]['user_id'] = $value['user_id'];
            $refineUserData[$value['user_id']]['username'] = $value['username'];
            $refineUserData[$value['user_id']]['installation_source'] = !empty($value['installation_source']) ? $value['installation_source'] : "shopify";
            $refineUserData[$value['user_id']]['email'] = $value['email'];
            $refineUserData[$value['user_id']]['shops'] = $value['shops'] ?? "NA";
            $refineUserData[$value['user_id']]['order_sync'] = count($configSync) > 0 ? $configSync[0]['value'] ? "enabled" : "disabled" : "enabled";
            $refineUserData[$value['user_id']]['brand_registered'] = count($configBrand) > 0 ? !empty($configBrand[0]['value']) ? $configBrand[0]['value'] : [] : [];
            $refineUserData[$value['user_id']]['install_status'] = $value['shops'][0]['apps'][0]['app_status'] == "active" ? "installed" : "uninstalled";
            if (isset($value['created_at'])) {
                $refineUserData[$value['user_id']]['install_at'] = $value['created_at'] ?? "NA";
            }

            if (isset($value['shops'])) {
                $install_status = false;
                $formatted_data = $this->formatShopsData($value, $install_status, $CollectionConfig);
                $refineUserData[$value['user_id']]['formatted_shops'] = $formatted_data;
                $refineUserData[$value['user_id']]['last_login'] = $value['shops'][0]['last_login_at'] ?? "NA";
                $refineUserData[$value['user_id']]['name'] = $value['shops'][0]['name'] ?? "NA";
                $refineUserData[$value['user_id']]['shop_url'] = $value['username'] ?? "NA";
                $refineUserData[$value['user_id']]['contact_no'] = $value['shops'][0]['phone'] ?? "NA";
                $refineUserData[$value['user_id']]['shopify_plan'] = $value['shops'][0]['plan_display_name'] ?? "NA";
                // if(isset($formatted_data[0]['targets'])){
                //     $temparr = count(array_filter($formatted_data[0]['targets'], function($shops) {
                //         return $shops['status'] === "active";
                //     }));
                // }
                // $refineUserData[$value['user_id']]['connected_accounts'] = $tempArr;
                $refineUserData[$value['user_id']]['connected_accounts'] = count($formatted_data[0]['targets'] ?? []);
            }

            $refineUserData[$value['user_id']]['last_sync'] = date("c");
            $refineUserData[$value['user_id']]['reinstall'] = false;
            $refineUserData[$value['user_id']] = $this->addReinstallInfo($value, 'shopify', $refineUserData[$value['user_id']]);
            $refineUserData[$value['user_id']]['exists'] = true;
            if ($syncId) $refineUserData[$value['user_id']]['sync_id'] = $syncId;
        }


        $plan = $this->getAndPreparePlanData($tempUserIds, $refineUserData, $mongo);
        if (!$stream) {
            $migrationStats = $this->getAndPrepareMigrationData($tempUserIds, $refineUserData, $mongo);
            $counts = $this->getProductAndOrderCount($tempUserIds, $refineUserData, $mongo);
        }

        // $sync = $this->getAndFormatOrderSync($tempUserIds, $refineUserData, $mongo);
        $bulkQueryArray = [];
        $res = [];
        foreach ($refineUserData as $v) {
            $res = $v;
            array_push($bulkQueryArray, [
                "updateOne" => [
                    ["_id" => $v['_id']],
                    ['$set' => $v],
                    ['upsert' => true]
                ]
            ]);
        }

        $success = $collectionRefine->bulkWrite($bulkQueryArray);
        return ['success' => true, "message" => "sync successfull", "data" => $res];
    }

    // public function getAndFormatOrderSync($userIds, &$refineUserData, $mongo)
    // {
    //     $collection = $mongo->getCollectionForTable('config');
    //     $aggregate[] = [
    //         '$match' => [
    //             'user_id' => ['$in' => $userIds],
    //             'value' => "0"
    //         ]
    //     ];
    //     $orderSyncStats = $collection->aggregate($aggregate)->toArray();
    //     foreach ($orderSyncStats as $key => $value) {
    //         $refineUserData[$value['user_id']]['order_sync'] = "disabled";
    //     }
    // }
    public function getAndPreparePlanData($userIds, &$refineUserData, $mongo): void
    {

        $collection = $mongo->getCollectionForTable('payment_details');
        $aggregate[] = [
            '$match' => [
                'user_id' => ['$in' => $userIds],
                '$or' => [['type' => 'active_plan', 'status' => "active"], [
                    'type' => 'user_service',
                ]],

            ]
        ];
        $planData = $collection->aggregate(
            $aggregate
        )->toArray();
        foreach ($planData as $value) {

            if ($value['type'] == 'active_plan') {
                $refineUserData[$value['user_id']]['activePlanDetails']['app_plan'] = $value["plan_details"]['title'] . "/" . $value["plan_details"]['custom_price'];
                $refineUserData[$value['user_id']]['activePlanDetails']['custom_price'] = $value["plan_details"]['custom_price'];
                $refineUserData[$value['user_id']]['activePlanDetails']['created_at'] = $value["created_at"];
                $refineUserData[$value['user_id']]['activePlanDetails']['plan_id'] = $value["plan_details"]['plan_id'];
                $refineUserData[$value['user_id']]['activePlanDetails']['category'] = $value["plan_details"]['category'] ?? 'regular';
                $refineUserData[$value['user_id']]['activePlanDetails']['payment_type'] = $value["plan_details"]['payment_type'] ?? "recurring";
                $refineUserData[$value['user_id']]['activePlanDetails']['billed_type'] = !empty($value["plan_details"]['billed_type']) ? $value["plan_details"]['billed_type'] : 'monthly';
                $refineUserData[$value['user_id']]['activePlanDetails']['offers'] = $value["plan_details"]['discounts'] ?? [];
                $refineUserData[$value['user_id']]['offer'] = "N/A";
                if (!empty($value["plan_details"]['discounts'])) {
                    $offer = "N/A";
                    foreach ($value["plan_details"]['discounts'] as $of) {
                        if ($offer !== "Festive Offer") {
                            $offer = $of['name'] ?? "N/A";
                        }
                    }

                    $refineUserData[$value['user_id']]['offer'] = $offer;
                }

                if (isset($value["plan_details"]['custom_plan']) && $value["plan_details"]['custom_plan']) {
                    $refineUserData[$value['user_id']]['activePlanDetails']['custom_plan'] = true;
                }
            }

            if ($value['type'] == 'user_service' && $value["expired_at"] >= date("Y-m-d") && ($value['service_type'] == 'order_sync')) {
                $refineUserData[$value['user_id']]['activePlanDetails']['activated_at'] = $value["activated_on"];
                $refineUserData[$value['user_id']]['activePlanDetails']["expired_at"] = $value["expired_at"];
                $refineUserData[$value['user_id']]['activePlanDetails']['prepaid_available_credits'] = $value['prepaid']['available_credits'];
                $refineUserData[$value['user_id']]['activePlanDetails']['prepaid_used_credits'] = $value['prepaid']['total_used_credits'];
                $refineUserData[$value['user_id']]['activePlanDetails']['postpaid_available_credits'] = $value['postpaid']['available_credits'];
                $refineUserData[$value['user_id']]['activePlanDetails']['postpaid_used_credits'] = $value['postpaid']['total_used_credits'];
                $refineUserData[$value['user_id']]['activePlanDetails']['unit_qty']  = $value['postpaid']['unit_qty'];
                $refineUserData[$value['user_id']]['activePlanDetails']['per_unit_usage_price']  = $value['postpaid']['per_unit_usage_price'];
                // $refineUserData[$value['user_id']]['activePlanDetails']['settlement_amount'] = $value['postpaid']['total_used_credits'] / $value['postpaid']['unit_qty'] * $value['postpaid']['per_unit_usage_price'];
                if (isset($value["deactivate_on"]) && !empty($value["deactivate_on"])) {
                    $refineUserData[$value['user_id']]['activePlanDetails']['deactivate_on'] = $value["deactivate_on"];
                }

                if (isset($value["forcefully_cancelled"]) && $value["forcefully_cancelled"]) {
                    $refineUserData[$value['user_id']]['activePlanDetails']['downgraded_to_free'] = $value["forcefully_cancelled"];
                }
            }

            if ($value['type'] == 'user_service' && ($value['service_type'] == 'product_import')) {
                $refineUserData[$value['user_id']]['activePlanDetails']['product_import_sync_activated'] = $value["sync_activated"] ?? true;
            }
        }
    }

    public function getAndPrepareMigrationData($tempUserIds, &$refineUserData, $mongo): void
    {
        $collection = $mongo->getCollectionForTable('user_migrate_progress');
        $aggregate[] = [
            '$match' => [
                'user_id' => ['$in' => $tempUserIds],
            ]
        ];
        $migratedUsers = $collection->aggregate($aggregate)->toArray();
        foreach ($migratedUsers as $v) {
            $refineUserData[$v['user_id']]['migrationStatus'] = $v;
        }
    }

    public function getProductAndOrderCount($userIds, &$refineUserData, $mongo): void
    {
        $collectionOrder = $mongo->getCollectionForTable('order_container');
        $collectionProduct = $mongo->getCollectionForTable('product_container');

        $products = $collectionProduct->aggregate([
            ['$match' => ['user_id' => ['$in' => $userIds], "visibility" => "Catalog and Search"]],
            ['$group' => ['_id' => '$user_id', 'total' => ['$sum' => 1]]]
        ])->toArray();
        $orders = $collectionOrder->aggregate([
            ['$match' => ['user_id' => ['$in' => $userIds], "object_type" => "source_order"]],
            ['$group' => ['_id' => '$user_id', 'total' => ['$sum' => 1]]]
        ])->toArray();
        foreach ($orders as $key => $value) {
            $refineUserData[$value['_id']]['order_count'] = $value['total'];
        }

        foreach ($products as $value) {
            $refineUserData[$value['_id']]['product_count'] = $value['total'];
        }
    }

    public function getMigrationGrid($data)
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_migrate_progress');
        $activePage = $data['activePage'] ?? 1;
        $skip = ((int)$activePage - 1) * 100;

        $filters = isset($data['filter']) ? $data : [];

        $aggregate[] = ['$sort' => ["_id" => -1]];
        $aggregate[] = ['$addFields' => ['migration_start' => ['$arrayElemAt' => [['$split' => ['$start_migration', 'T']], 0]]]];
        $aggregate[] = ['$addFields' => ['migration_end' => ['$arrayElemAt' => [['$split' => ['$end_migration', 'T']], 0]]]];

        if (count($filters)) {
            $conditionalQuery['$and'][] = self::searchMongo($filters);
            $aggregate[] = ['$match' => $conditionalQuery];
        }

        $aggregate[] = ['$skip' => $skip];
        $aggregate[] = ['$limit' => 100];

        $migrationData = $collection->aggregate($aggregate)->toArray();

        return ['success' => true, 'data' => $this->prepareMigrationGridData($migrationData), 'count' => $collection->count(self::searchMongo($filters))];
    }

    public function prepareMigrationGridData($data)
    {
        $migrateResponse = [];
        foreach ($data as $key => $value) {
            $migrateResponse[$key]['user_id'] = $value['user_id'] ?? "NA";
            $migrateResponse[$key]['status'] = $value['completed'] ? "completed" : "in progress";
            $migrateResponse[$key]['message'] = $value['msg'] ?? "NA";
            $migrateResponse[$key]['start_time'] = $value['start_migration'] ?? "NA";
            $migrateResponse[$key]['end_time'] = $value["end_migration"] ?? "NA";
            $migrateResponse[$key]['username'] = $value['username'] ?? "NA";
            $migrateResponse[$key]['step'] = $value['step'] ?? "NA";
            if (isset($value['start_migration'])) {
                $migrateResponse[$key]['color'] = $this->delayCheck($value['start_migration']) &&  !($value['completed']) ? "delay" : "success";
            }
        }

        return $migrateResponse;
    }

    public function getAllMigrationRelatedCounts()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_migrate_progress');
        $totalCount = $collection->count();
        $progress = $collection->count(['completed' => false]);
        $complete = $collection->count(['completed' => true]);
        return ['success' => true, 'data' => ['total' => $totalCount, 'progress' => $progress, 'complete' => $complete]];
    }

    public function updateFaq($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('solutions');
        $dataUpdate = ['type' => "faq", "marketplace" => $data["marketplace"], "title" => $data['title'], "answer" => $data['answer'], "group" => $data['group']];
        $collection->updateOne(["_id" => new ObjectId($data['id'])], ['$set' => $dataUpdate]);
        return ['success' => true, 'message' => "updated successfully"];
    }

    public function getUserWiseChartData($data)
    {
        $user_id = $data['user_id'];
        $aggregate = [['$match' => ['user_id' => $user_id]], ['$sort' => ['order_date' => -1]], ['$limit' => 30], ['$sort' => ['order_date' => 1]]];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('sales_container');
        $userData = $collection->aggregate($aggregate)->toArray();
        return ['success' => true, 'data' => $this->formatUserSalesData($userData)];
    }

    public function formatUserSalesData($data)
    {
        $chartData = [];
        foreach ($data as $value) {
            $temp = [];
            $temp['users'] = $value['order_date'] ?? "";
            $temp['order_count'] = $value["totalOrderCount"];
            foreach ($value['currency'] as $k => $v) {
                $temp[$k] = $v;
            }

            array_push($chartData, $temp);
        }

        return $chartData;
    }

    public function validateWebhooks($data)
    {
        if (isset($data['source_marketplace']) && isset($data['target_marketplace'])) {
            $prepareData = [];
            $sourceMarketplace = $data['source_marketplace'];
            $targetMarketplace = $data['target_marketplace'];
            if (!isset($data['filter'])) {
                $prepareData = $this->findSourceWebhooks($data);
                $prepareData = $this->findTargetWebhooks($data, $prepareData);
            } else {
                if (isset($data['filter']) && isset($data['filter'][$sourceMarketplace])) {
                    $prepareData = $this->findSourceWebhooks($data);
                }

                if (isset($data['filter']) && isset($data['filter'][$targetMarketplace])) {
                    $prepareData = $this->findTargetWebhooks($data, $prepareData);
                }
            }

            if (!empty($prepareData)) {
                $prepareData['counts'] = $this->getUsersCount($data);
                return ['success' => true, 'data' => $prepareData];
            }
            return ['success' => false, 'message' => 'No data Found'];
        }
        return ['success' => false, 'message' => 'source or target marketplace not found'];
    }

    public function findSourceWebhooks($params)
    {
        $getSourceWebhooks = $this->di->getConfig()->webhook->get('amazon_sales_channel')->toArray();
        if (!empty($getSourceWebhooks)) {
            $standardSourceWebhooks = array_column($getSourceWebhooks, 'action');
        }

        $sourceMarketplace = $params['source_marketplace'];
        $limit = $params['count'] ?? 20;
        if (!isset($params['activePage'])) {
            $params['activePage'] = 1;
        }

        $page = $params['activePage'];
        $offset = ($page - 1) * $limit;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $sourcePipeline = [
            ['$match' => ['shops.1' => ['$exists' => true]]],
            ['$unwind' => '$shops'],
            ['$match' => ['shops.marketplace' => $sourceMarketplace]],
            ['$unwind' => '$shops.apps'],
            [
                '$match' => [
                    'shops.apps.app_status' => 'active',
                    'shops.apps.webhooks.code' => [
                        '$not' => [
                            '$all' => $standardSourceWebhooks
                        ]
                    ]
                ]
            ],
            [
                '$project' => ['shops.apps.webhooks.code' => 1, 'shops._id' => 1, 'user_id' => 1, 'username' => 1]
            ],

            ['$group' => ['_id' => '$user_id', 'shops' => ['$addToSet' => '$shops'], 'username' => ['$first' => '$username']]],
            ['$sort' => ['_id' => 1]],
            ['$skip' => $offset],
            ['$limit' => $limit]
        ];
        if (isset($params['username']) && !empty($params['username'])) {
            $userParams = ['$match' => ['username' => ['$regex' => $params['username']]]];
            array_splice($sourcePipeline, 0, 0, [$userParams]);
        }

        $sourceWebhooks = $query->aggregate($sourcePipeline, $arrayParams)->toArray();
        if (!empty($sourceWebhooks)) {
            foreach ($sourceWebhooks as $user) {
                if (!empty($user['shops'])) {
                    foreach ($user['shops'] as $shop) {
                        if (!empty($shop['apps']) && !empty($shop['apps']['webhooks'])) {
                            if (!isset($prepareData[$user['_id']])) {
                                $prepareData['users'][$user['_id']][$sourceMarketplace] = [
                                    $shop['_id'] => array_column($shop['apps']['webhooks'], 'code')

                                ];
                            } else {
                                $prepareData['users'][$user['_id']][$sourceMarketplace][$shop['_id']] = array_column($shop['apps']['webhooks'], 'code');
                            }
                        } else {
                            $prepareData['users'][$user['_id']][$sourceMarketplace][$shop['_id']] = [];
                        }
                    }

                    $prepareData['users'][$user['_id']]['username'] = $user['username'];
                }
            }
        }

        if (!empty($prepareData)) {
            $prepareData['standard'][$sourceMarketplace] = $standardSourceWebhooks;
            return $prepareData;
        }
    }

    public function findTargetWebhooks($params, $prepareData = [])
    {
        $getTargetWebhooks = $this->di->getConfig()->webhook->get('amazon')->toArray();
        if (!empty($getTargetWebhooks)) {
            $standardTargetWebhooks = array_column($getTargetWebhooks, 'code');
        }

        $targetMarketplace = $params['target_marketplace'];
        $limit = $params['count'] ?? 100;
        if (!isset($params['activePage'])) {
            $params['activePage'] = 1;
        }

        $page = $params['activePage'];
        $offset = ($page - 1) * $limit;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $targetPipline = [
            ['$unwind' => '$shops'],
            ['$match' => ['shops.marketplace' => $targetMarketplace]],
            ['$unwind' => '$shops.apps'],
            [
                '$match' => [
                    'shops.apps.app_status' => 'active',
                    'shops.apps.webhooks.code' => [
                        '$not' => [
                            '$all' => $standardTargetWebhooks
                        ]
                    ]
                ]
            ],

            [
                '$project' => ['shops.apps.webhooks.code' => 1, 'shops._id' => 1, 'user_id' => 1, 'username' => 1]
            ],
            ['$group' => ['_id' => '$user_id', 'shops' => ['$addToSet' => '$shops'], 'username' => ['$first' => '$username']]]
        ];
        if (isset($params['username']) && !empty($params['username'])) {
            $userParams = ['$match' => ['username' => ['$regex' => $params['username']]]];
            array_splice($targetPipline, 0, 0, [$userParams]);
        }

        if (!empty($prepareData)) {
            $getIds = array_keys($prepareData['users']);
            $idsParams = ['$match' => ['user_id' => ['$in' => $getIds]]];
            array_splice($targetPipline, 0, 0, [$idsParams]);
        } else {
            $targetPipline[] = ['$sort' => ['_id' => 1]];
            $targetPipline[] = ['$skip' => $offset];
            $targetPipline[] = ['$limit' => $limit];
        }

        $targetWebhooks = $query->aggregate($targetPipline, $arrayParams)->toArray();
        if (!empty($targetWebhooks)) {
            foreach ($targetWebhooks as $user) {
                if (!empty($user['shops'])) {
                    foreach ($user['shops'] as $shop) {
                        if (!empty($shop['apps']) && !empty($shop['apps']['webhooks'])) {
                            if (!isset($prepareData['users'][$user['_id']])) {
                                $prepareData['users'][$user['_id']][$targetMarketplace] = [
                                    $shop['_id'] => array_column($shop['apps']['webhooks'], 'code')
                                ];
                            } else {
                                $prepareData['users'][$user['_id']][$targetMarketplace][$shop['_id']] = array_column($shop['apps']['webhooks'], 'code');
                            }
                        } else {
                            $prepareData['users'][$user['_id']][$targetMarketplace][$shop['_id']] = [];
                        }
                    }

                    if (!isset($prepareData['users'][$user['_id']]['username'])) {
                        $prepareData['users'][$user['_id']]['username'] = $user['username'];
                    }
                }
            }
        }

        if (!empty($prepareData)) {
            $prepareData['standard'][$targetMarketplace] = $standardTargetWebhooks;
            return $prepareData;
        }
    }

    public function getUsersCount($params)
    {
        if (isset($params['username']) && !empty($params['username'])) {
            $counts['totalCount'] = 1;
            return $counts;
        }

        $getSourceWebhooks = $this->di->getConfig()->webhook->get('amazon_sales_channel')->toArray();
        $getTargetWebhooks = $this->di->getConfig()->webhook->get('amazon')->toArray();
        if (!empty($getSourceWebhooks) && !empty($getTargetWebhooks)) {
            $standardSourceWebhooks = array_column($getSourceWebhooks, 'action');
            $standardTargetWebhooks = array_column($getTargetWebhooks, 'code');
        }

        $sourceMarketplace = $params['source_marketplace'];
        $targetMarketplace = $params['target_marketplace'];
        $sourceUsers = [];
        $targetUsers = [];
        if (!isset($params['filter'])) {
            $sourceUsers = $this->executeQuery($sourceMarketplace, $standardSourceWebhooks);
            $targetUsers = $this->executeQuery($targetMarketplace, $standardTargetWebhooks);
        } else {
            if (isset($params['filter']) && isset($params['filter'][$sourceMarketplace])) {
                $sourceUsers = $this->executeQuery($sourceMarketplace, $standardSourceWebhooks);
            }

            if (isset($params['filter']) && isset($params['filter'][$targetMarketplace])) {
                $targetUsers = $this->executeQuery($targetMarketplace, $standardTargetWebhooks);
            }
        }

        if (!empty($sourceUsers)) {
            $counts['sourceCount'] = count($sourceUsers);
        }

        if (!empty($targetUsers)) {
            $counts['targetCount'] = count($targetUsers);
        }

        if (!empty($sourceUsers) && !empty($targetUsers)) {
            $mergeUsers = array_merge($sourceUsers, $targetUsers);
            $users = array_unique($mergeUsers);
            $counts['totalCount'] = count($users);
        }

        return $counts;
    }

    public function executeQuery($marketplace, $webhooks)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
        $response = $query->distinct(
            "user_id",
            [
                'shops.1' => [
                    '$exists' => true
                ],
                'shops' => [
                    '$elemMatch' => [
                        'marketplace' => $marketplace,
                        'apps.webhooks.code' => [
                            '$not' => [
                                '$all' => $webhooks
                            ]
                        ],
                        'apps.app_status' => 'active'
                    ]
                ]
            ]
        );
        return $response;
    }

    /**
     *  category helper method start
     */


    /**
     * Operation getTargetSelectedAttributes
     *
     *  @param  array $data inclueds remote_shop_id (string), category (string), sub_category (string), attributes (string array)
     *
     * @return array success:bool, message:string
     */
    public function getTargetSelectedAttributes($data)
    {
        if (isset($data['remote_shop_id'], $data['category'], $data['sub_category'], $data['attributes']) && $data['category'] && $data['sub_category'] && !empty($data['attributes'])) {
            $categoryComponent = $this->di->getObjectManager()->create('\App\Amazon\Components\Template\Category');
            return $categoryComponent->getSelectedAttributes($data);
        }
        return [
            'success' => false,
            'message' => 'shop_id, category, sub_category and attributes are required'
        ];
    }

    /**
     * Operation changeAttributeRequirement
     *
     *  @param  array $data inclueds data (array), currentState (array), initialState (array)
     *
     * @param array $data['data'] inclued remote_shop_id (string), category (string), sub_category (string)
     *
     * @return array success:bool, message:string
     */
    public function changeAttributeRequirement($data)
    {
        if (isset($data['data']['remote_shop_id'], $data['data']['category'], $data['data']['sub_category'], $data['currentState'], $data['initialState']) && $data['data']['category'] && $data['data']['sub_category']) {
            $changedAttributes = [];
            foreach ($data['currentState'] as $attributeName => $condition) {
                if (
                    isset($data['initialState'][$attributeName])
                    && $data['initialState'][$attributeName]['condition'] != $condition['condition']
                ) {
                    $changedAttributes[$attributeName] = $condition['condition'];
                }
            }

            if (!empty($changedAttributes)) {
                $categoryComponent = $this->di->getObjectManager()->create('\App\Amazon\Components\Template\Category');
                $specifics = [
                    'updated_attributes' => $changedAttributes,
                    'shop_id' => $data['data']['remote_shop_id'],
                    'category' => $data['data']['category'],
                    'sub_category' => $data['data']['sub_category']
                ];
                return $categoryComponent->changeAttributesRequirement($specifics);
            }
            return ['success' => true, 'message' => "Nothing is updated."];
        }
        return ['success' => false, 'message' => 'shop_id, category, sub_category and attributes are required'];
    }

    /**
     *  category helper method end
     */

    public function addUserResetKey($data)
    {
        if (isset($data['user_id'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable("user_details");
            $collection->updateOne(['user_id' => $data['user_id']], ['$set' => ['do_not_erase' => true]]);
            return ['success' => true, 'message' => "key updated successfully"];
        }

        return ['success' => false, 'message' => "user id missing"];
    }


    public function getAllInterviewUsers($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');


        $collection = $mongo->getCollectionForTable('user_details');

        $res = $collection->find(['for_ai_interview' => "true"])->toArray();
        return ['success' => 'kuch', 'data' => $res];
    }

    public function saveInterviewUser($data)
    {

        if (!isset($data['email']) && !$data['email']) {
            return ['success' => false];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');


        $collection = $mongo->getCollectionForTable('user_details');

        $checkIfAlready = $collection->findOne(['$or' => [['username' => $data['email']], ['email' => $data['email']]]]);

        if (count($checkIfAlready) !== 0) {
            return ['success' => false, 'message' => 'user with id  or same email already present'];
        }

        $res = $collection->insertOne(['username' => $data['email']]);

        $id = $res->getInsertedId();

        $dataToInsert = $this->insertIntervIewUser();

        $user_id = (string)$id;

        $dataToInsert['username'] = $data['email'];

        $dataToInsert['email'] = $data['email'];

        $dataToInsert['user_id'] = $user_id;

        unset($dataToInsert['_id']);

        $mongoRes = $collection->updateOne(['_id' =>  new ObjectId($user_id)], ['$set' => $dataToInsert]);

        $ai_services = $mongo->getCollectionForTable('ai_services');

        $ai_services->updateOne(['user_id' => $user_id], ['$set' => ['user_id' => $user_id, 'marketplace' => 'amazon', 'credit_left' => 100]], ['upsert' => true]);

        $this->insertConfigDetails($user_id, $data['email']);
        $this->insertPlan($user_id, $data['email']);
        $this->insertProduct($user_id, $data['email']);
        $this->insertRefine($user_id, $data['email']);

        return ['success' => 'true', 'data' => $mongoRes];
    }

    public function deleteInterviewUser($data)
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $user_details = $mongo->getCollectionForTable('user_details');

        $getUser = $user_details->findOne(['user_id' => $data['user_id'], 'for_ai_interview' => 'true']);

        if (isset($getUser['for_ai_interview']) && $getUser['for_ai_interview'] == 'true') {
            $config = $mongo->getCollectionForTable('config');

            $payment_details = $mongo->getCollectionForTable('payment_details');

            $product_container = $mongo->getCollectionForTable('product_container');

            $refine_product = $mongo->getCollectionForTable('refine_product');

            $ai_services = $mongo->getCollectionForTable('ai_services');


            $ai_services->deleteMany(['user_id' => $data['user_id']]);

            $user_details->deleteMany(['user_id' => $data['user_id']]);

            $payment_details->deleteMany(['user_id' => $data['user_id']]);

            $product_container->deleteMany(['user_id' => $data['user_id']]);

            $refine_product->deleteMany(['user_id' => $data['user_id']]);
        } else {
            return ['success' => 'false', 'message' => 'why'];
        }




        return ['success' => 'delet', 'data' => $data];
    }

    public function showOnlyGrid($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');


        $collection = $mongo->getCollectionForTable('user_details');

        $res = $collection->find(['for_ai_interview' => "true"])->toArray();

        $res = json_decode(json_encode($res), true);

        $toCheck = [];

        foreach ($res as $v) {
            $toCheck[] = $v['user_id'];
        }

        $user = $this->di->getUser()->id;

        $showGrid = in_array($user, $toCheck);
        return ['success' => $showGrid];
    }


    public function insertIntervIewUser()
    {
        $data = [
            "status" => 2,
            "sqlConfig" => null,
            "name" => "sourabh-check",
            "for_ai_interview" => "true",
            "password" => PASS_HASH,
            "source_email" => "sourabhsingh@cedcommerce.com",
            "email_created_by" => "cedcommerce",
            "role_id" => "ObjectId(\"6336adb6ac301d0b1a04edd4\")",
            "confirmation" => 1,
            "user_id" => "63e47a3275be7bc5b101a983",
            "shops" => [
                [
                    "id" => "Long(\"62738923688\")",
                    "name" => "sourabh-check",
                    "email" => "sourabhsingh@cedcommerce.com",
                    "domain" => "sourabh-check.myshopify.com",
                    "province" => "Uttar Pradesh",
                    "country" => "IN",
                    "address1" => "lknw",
                    "zip" => "226003",
                    "city" => "Lucknow",
                    "source" => null,
                    "phone" => "",
                    "latitude" => 26.872781,
                    "longitude" => 80.8820535,
                    "primary_locale" => "en",
                    "address2" => "",
                    "country_code" => "IN",
                    "country_name" => "India",
                    "currency" => "INR",
                    "customer_email" => "sourabhsingh@cedcommerce.com",
                    "timezone" => "(GMT+05:30) Asia/Calcutta",
                    "iana_timezone" => "Asia/Calcutta",
                    "shop_owner" => "sourabh-check Admin",
                    "money_format" => "Rs. {{amount}}",
                    "money_with_currency_format" => "Rs. {{amount}}",
                    "weight_unit" => "kg",
                    "province_code" => "UP",
                    "taxes_included" => false,
                    "auto_configure_tax_inclusivity" => null,
                    "tax_shipping" => null,
                    "county_taxes" => true,
                    "plan_display_name" => "Developer Preview",
                    "plan_name" => "partner_test",
                    "has_discounts" => false,
                    "has_gift_cards" => false,
                    "myshopify_domain" => "sourabh-check.myshopify.com",
                    "google_apps_domain" => null,
                    "google_apps_login_enabled" => null,
                    "money_in_emails_format" => "Rs. {{amount}}",
                    "money_with_currency_in_emails_format" => "Rs. {{amount}}",
                    "eligible_for_payments" => false,
                    "requires_extra_payments_agreement" => false,
                    "password_enabled" => true,
                    "has_storefront" => true,
                    "finances" => true,
                    "primary_location_id" => "Long(\"68051992744\")",
                    "cookie_consent_level" => "implicit",
                    "visitor_tracking_consent_preference" => "allow_all",
                    "checkout_api_supported" => false,
                    "multi_location_enabled" => true,
                    "setup_required" => false,
                    "pre_launch_enabled" => false,
                    "enabled_presentment_currencies" => ["INR"],
                    "username" => "sourabh-check.myshopify.com",
                    "apps" => [
                        [
                            "code" => "amazon_sales_channel",
                            "app_status" => "active",
                            "webhooks" => [
                                ["code" => "app_delete", "dynamo_webhook_id" => "35420"],
                                ["code" => "fulfillments_update", "dynamo_webhook_id" => "35425"],
                                ["code" => "inventory_levels_update", "dynamo_webhook_id" => "35426"],
                                ["code" => "locations_create", "dynamo_webhook_id" => "35427"],
                                ["code" => "locations_delete", "dynamo_webhook_id" => "35428"],
                                ["code" => "locations_update", "dynamo_webhook_id" => "35429"],
                                ["code" => "orders_create", "dynamo_webhook_id" => "35430"],
                                ["code" => "product_listings_add", "dynamo_webhook_id" => "35431"],
                                ["code" => "product_listings_remove", "dynamo_webhook_id" => "35432"],
                                ["code" => "product_listings_update", "dynamo_webhook_id" => "35433"],
                                ["code" => "refunds_create", "dynamo_webhook_id" => "35434"],
                                ["code" => "shop_update", "dynamo_webhook_id" => "35435"],
                                ["code" => "fulfillments_create", "dynamo_webhook_id" => "35436"],
                                ["code" => "app_subscriptions_update", "dynamo_webhook_id" => "294980"]
                            ]
                        ]
                    ],
                    "remote_shop_id" => "10661",
                    "marketplace" => "shopify",
                    "last_login_at" => "2023-09-11 10:19:21",
                    "warehouses" => [
                        [
                            "id" => "68051992744",
                            "name" => "lknw",
                            "address1" => "lknw",
                            "address2" => null,
                            "city" => "Lucknow",
                            "zip" => "226003",
                            "province" => "Uttar Pradesh",
                            "country" => "IN",
                            "phone" => "",
                            "country_code" => "IN",
                            "country_name" => "India",
                            "province_code" => "UP",
                            "legacy" => false,
                            "active" => true,
                            "admin_graphql_api_id" => "gid://shopify/Location/68051992744",
                            "localized_country_name" => "India",
                            "localized_province_name" => "Uttar Pradesh",
                            "_id" => "121034"
                        ]
                    ],
                    "_id" => "61890",
                    "shop_status" => "active",
                    "targets" => [
                        ["shop_id" => "61891", "code" => "amazon", "app_code" => "amazon"]
                    ],
                    "transactional_sms_disabled" => true,
                    "marketing_sms_consent_enabled_at_checkout" => false
                ],
                [
                    "apps" => [
                        [
                            "code" => "amazon",
                            "app_status" => "active",
                            "webhooks" => [
                                ["code" => "REPORT_PROCESSING_FINISHED", "dynamo_webhook_id" => "35421"],
                                ["code" => "FEED_PROCESSING_FINISHED", "dynamo_webhook_id" => "35422"],
                                ["code" => "LISTINGS_ITEM_STATUS_CHANGE", "dynamo_webhook_id" => "35424"],
                                ["code" => "ACCOUNT_STATUS_CHANGED", "dynamo_webhook_id" => "117171"],
                                ["code" => "ORDER_CHANGE", "dynamo_webhook_id" => "277712"]
                            ]
                        ]
                    ],
                    "remote_shop_id" => "10662",
                    "marketplace" => "amazon",
                    "last_login_at" => "2023-08-09 12:03:45",
                    "currency" => "INR",
                    "warehouses" => [
                        [
                            "region" => "EU",
                            "marketplace_id" => "A21TJRUUN4KGV",
                            "seller_id" => "A1VRBOQBWVGV3L",
                            "status" => "active",
                            "_id" => "121035"
                        ]
                    ],
                    "_id" => "61891",
                    "shop_status" => "active",
                    "sources" => [
                        ["shop_id" => "61890", "code" => "shopify", "app_code" => "amazon_sales_channel"]
                    ],
                    "sellerName" => "smartLister",
                    "register_for_order_sync" => true
                ]
            ],
            "user_stats" => [
                "last_login" => [
                    "device" => "Desktop",
                    "os" => "Linux"
                ]
            ],
            "click_up_task_id_bkp" => "863g1w2c3",
            "click_up_task_id" => "863gmv97b",
            "user_status" => "active",
            "do_not_erase" => true,
            "picked" => true
        ];
        return $data;
    }

    public function insertConfigDetails($user_id, $username): void
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');


        $collection = $mongo->getCollectionForTable('config');

        $getAllConfig = $collection->find(['user_id' => '63e47a3275be7bc5b101a983'])->toArray();

        $getAllConfig = json_decode(json_encode($getAllConfig), true);

        $newData = [];
        foreach ($getAllConfig as $v) {
            $v['user_id'] = $user_id;
            $v['username'] = $username;
            unset($v['_id']);
            unset($v['created_at']);
            unset($v['updated_at']);
            $newData[] = $v;
        }

        // print_r($newData);die;

        $collection->insertMany($newData);
    }

    public function insertPlan($user_id, $username): void
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');


        $collection = $mongo->getCollectionForTable('payment_details');

        $getAllConfig = $collection->find(['user_id' => '63e47a3275be7bc5b101a983'])->toArray();

        $getAllConfig = json_decode(json_encode($getAllConfig), true);

        $newData = [];
        foreach ($getAllConfig as $v) {
            $v['user_id'] = $user_id;
            $v['username'] = $username;
            unset($v['_id']);
            unset($v['created_at']);
            unset($v['updated_at']);
            $newData[] = $v;
        }

        // print_r($newData);die;

        $collection->insertMany($newData);
    }

    public function insertProduct($user_id, $username): void
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');


        $collection = $mongo->getCollectionForTable('product_container');

        $getAllConfig = $collection->find(['user_id' => '63e47a3275be7bc5b101a983'])->toArray();

        $getAllConfig = json_decode(json_encode($getAllConfig), true);

        $newData = [];
        foreach ($getAllConfig as $v) {
            $v['user_id'] = $user_id;
            $v['username'] = $username;
            unset($v['_id']);
            unset($v['created_at']);
            unset($v['updated_at']);
            $newData[] = $v;
        }

        // print_r($newData);die;

        $collection->insertMany($newData);
    }

    public function insertRefine($user_id, $username): void
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');


        $collection = $mongo->getCollectionForTable('refine_product');

        $getAllConfig = $collection->find(['user_id' => '63e47a3275be7bc5b101a983'])->toArray();

        $getAllConfig = json_decode(json_encode($getAllConfig), true);

        $newData = [];
        foreach ($getAllConfig as $v) {
            $v['user_id'] = $user_id;
            $v['username'] = $username;
            unset($v['_id']);
            unset($v['created_at']);
            unset($v['updated_at']);
            $newData[] = $v;
        }

        // print_r($newData);die;

        $collection->insertMany($newData);
    }

    public function confirmShipmentHistory($data)
    {
        if (isset($data['order_id'], $data['shop_id'], $data['marketplace'])) {
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $orderCollection = $mongo->getCollectionForTable('order_container');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $params = [
                'user_id' => $userId,
                'object_type' => ['$in' => ['source_shipment', 'target_shipment']],
                'marketplace' => $data['marketplace'],
                'marketplace_shop_id' => $data['shop_id'],
                'marketplace_reference_id' => (string) $data['order_id'],
                'shipment_sync_history' => ['$exists' => true]
            ];
            $shipmentData = $orderCollection->find($params, $arrayParams)->toArray();
            if (!empty($shipmentData)) {
                $shipmentHistory = array_column($shipmentData, "shipment_sync_history");
                return ['success' => true, 'data' => $shipmentHistory];
            }
            $message = 'Shipment data not found';
        } else {
            $message = 'Missing Required Parameters';
        }

        return ['success' => false, 'message' => $message];
    }

    /**
     * Retrieves the Shopify location and corresponding inventory for a given containerId
     *
     * @param string $data
     * @return array
     */
    public function getInventorybyId($data)
    {

        if (isset($data['sku'], $data['shop_id'])) {
            $id = $data['sku'];
            $shopId = $data['shop_id'];
            $userId = $this->di->getUser()->id ?? $data['user_id'];
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $source = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
            $shop = $userDetails->getShop($shopId, $userId);

            try {
                if (!empty($shop) && isset($shop["warehouses"]) && is_array($shop["warehouses"])) {
                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $productContainer = $mongo->getCollectionForTable('product_container');
                    $warehouseName = [];
                    $locationName = [];
                    $remoteShopId = $shop['remote_shop_id'];
                    foreach ($shop["warehouses"] as $warehouse) {
                        $warehouseName[$warehouse["id"]] = $warehouse["name"];
                    }

                    $query = [
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                        'sku' => $id,
                        'type' => 'simple'
                    ];

                    $projection = [
                        'projection' => [
                            'sku' => 1,
                            'inventory_item_id' => 1,
                            '_id' => 0
                        ]
                    ];
                    $result = $productContainer->findOne($query, $projection);
                    if (empty($result)) {
                        return [
                            'success' => false,
                            'message' => 'SKU does not exist or SKU provided is Parent SKU'
                        ];
                    }
                    $inventoryItemId = $result['inventory_item_id'];
                    $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init($source, true)
                        ->call('/inventory', [], ['shop_id' => $remoteShopId, 'inventory_item_ids' => [$inventoryItemId]], 'GET');
                    if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                        if (is_null($remoteResponse['data'][0]['available'])) {
                            return [
                                'success' => false,
                                'message' => "Inventory Not Tracked is set on shopify",
                            ];
                        }
                        foreach ($remoteResponse["data"] as $inventory) {
                            $location_id = $inventory["location_id"];
                            $name = $warehouseName[$location_id];
                            $locationName[$name] = [
                                "quantity" => $inventory["available"]
                            ];
                        }
                        return [
                            'success' => true,
                            'message' => $locationName
                        ];
                    } else {
                        $message = $remoteResponse['error'] ?? 'Something went wrong';
                    }
                } else {
                    $message = "Warehouse does not exist";
                }
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'data' => $data,
                    'message' => $e->getMessage(),
                ];
                $this->di->getLog()->logContent('Shopify-Inventory exception getInventorybyID(): ' . print_r($e->getMessage(), true), 'info', 'exception.log');
            }
        } else {
            $message = "Product and ShopId not found";
        }
        return [
            'success' => false,
            'message=' => $message,
        ];
    }

    /**
     * Verify whether the Amazon Sales Channel is Assigned or not for a given containerId
     *
     * @param string $data
     * @return boolean
     */
    public function verifySalesChannel($data)
    {
        if (isset($data['id'], $data['shop_id'])) {
            $id = $data['id'];
            $shopId = $data['shop_id'];
            $userId = $this->di->getUser()->id ?? $data['user_id'];
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $source = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
            $shop = $userDetails->getShop($shopId, $userId);
            if (!empty($shop)) {
                try {
                    $remoteShopId = $shop['remote_shop_id'];
                    $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init($source, true)
                        ->call('/product', [], ['shop_id' => $remoteShopId, 'call_type' => 'QL', 'id' => $id], 'GET');
                    if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                        if (!empty($remoteResponse['data'][0]['published_on_current_publication'])) {
                            return [
                                'success' => true,
                                'message' => 'Sales Channel is Assigned'
                            ];
                        } else {
                            $message = 'Sales Channel not assigned';
                        }
                    } else {
                        $message = $remoteResponse['message'] ?? "Something went wrong";
                    }
                } catch (Exception $e) {
                    return [
                        'success' => false,
                        'data' => $data,
                        'message' => $e->getMessage(),
                    ];
                    $this->di->getLog()->logContent('Exception verifySalesChannel(): ' . print_r($e->getMessage(), true), 'info', 'exception.log');
                }
            } else {
                $message = 'shop not found';
            }
        } else {
            $message = "Required params missing(product_id,shop_id)";
        }
        return [
            'success' => false,
            'message' => $message,
        ];
    }

    /**
     * Delete the Product from Product Container and Refine Product Collections for a given containerId
     *
     * @param string $data
     * @return boolean
     */
    public function deleteProductbyProductId($data)
    {
        if (isset($data['id'], $data['shop_id'])) {
            $userId = $this->di->getUser()->id ?? $data['user_id'];
            $prepareData = [
                'user_id' => $userId,
                'shop_id' => $data['shop_id'],
                'marketplace' => 'shopify',
                'data' => [
                    'product_listing' => [
                        'product_id' => $data['id']
                    ]
                ]
            ];
            try {
                $salesChannelResponse = $this->verifySalesChannel($data);
                if (!$salesChannelResponse) {
                    $response = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Hook::class)->removeProductWebhook($prepareData);
                    if (!empty($response['success'])) {
                        $response = $this->di->getObjectManager()->get(\App\Amazon\Components\Product\Hook::class)->removeProductWebhook($prepareData);
                        if ($response['success']) {
                            return [
                                'success' => true,
                                'message' => "Product ID {$data['id']} sucessfully deleted"
                            ];
                        } else {
                            $message = $response['message'] ?? "Something went Wrong";
                        }
                    } else {
                        $message = $response['message'] ?? "something went Wrong";
                    }
                } else {
                    $message = $salesChannelResponse['message'] ?? 'Sales Channel is assigned in this product';
                }
                return [
                    'success' => false,
                    'message' => $message
                ];
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'data' => $data,
                    'message' => $e->getMessage(),
                ];
                $this->di->getLog()->logContent('Exception deleteProductbyProductId(): ' . print_r($e->getMessage(), true), 'info', 'exception.log');
            }
        } else {
            $message = "Required params missing(id,shop_id)";
        }
        return [
            'success' => false,
            'message' => $message
        ];
    }

    /**
     * Get Shopify Webhook Data
     *
     * @param string $data
     * @return array
     */
    public function getShopifyWebhookData($data)
    {
        if (isset($data['user_id'], $data['shop_id'])) {
            $shopId = $data['shop_id'];
            $userId = $this->di->getUser()->id ?? $data['user_id'];
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shop = $userDetails->getShop($shopId, $userId);
            if (!empty($shop)) {
                try {
                    $webhookData = [];
                    $remoteShopId = $shop['remote_shop_id'];
                    $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init('shopify', 'true')
                        ->call('/webhook', [], ['shop_id' => $remoteShopId], 'GET');
                    if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                        if (!empty($remoteResponse['data'])) {
                            foreach ($remoteResponse["data"] as $webhook) {
                                $webhookName = $webhook['topic'];
                                $webhookData[$webhookName] = [
                                    'id' => $webhook['id'],
                                    'api_version' => $webhook['api_version'],
                                    'address' => $webhook['address'],
                                    'created_at' => $webhook['created_at'],
                                    'updated_at' => $webhook['updated_at'],
                                ];
                            }
                            return [
                                'success' => true,
                                'message' => $webhookData
                            ];
                        } else {
                            $message = 'Webhhok not Registered';
                        }
                    } else {
                        $message = 'Remote response not received';
                    }
                } catch (Exception $e) {
                    return [
                        'success' => false,
                        'data' => $data,
                        'message' => $e->getMessage(),
                    ];
                }
            } else {
                $message = 'Shop Data not Found';
            }
        } else {
            $message = 'Required params not found';
        }
        return [
            'success' => true,
            'message' => $message
        ];
    }

    /**
     * Get Amazon Webhook Data
     *
     * @param string $data
     * @return array
     */
    public function getAmazonWebhookData($data)
    {
        if (isset($data['webhookType'], $data['shop_id'])) {
            $webhookType = $data['webhookType'];
            $shopId = $data['shop_id'];
            $userId = $this->di->getUser()->id ?? $data['user_id'];
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shop = $userDetails->getShop($shopId, $userId);
            if (!empty($shop)) {
                try {
                    $webhookData = [];
                    $remoteShopId = $shop['remote_shop_id'];
                    $specifics = [
                        'shop_id' => $remoteShopId,
                        'notificationtype' => $webhookType
                    ];
                    $helper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
                    $response = $helper->sendRequestToAmazon('get-subscription', $specifics, 'GET');
                    if (!empty($response['success'])) {
                        $subscriptionId = $response['response']['payload']['subscriptionId'] ?? '';
                        $destinationId = $response['response']['payload']['destinationId'] ?? '';
                        $webhookData[$webhookType] = [
                            'subscriptionId' => $subscriptionId,
                            'destinationId' => $destinationId
                        ];
                        return [
                            'success' => true,
                            'message' => $webhookData
                        ];
                    } else {
                        $message = 'Response not Received';
                    }
                } catch (Exception $e) {
                    return [
                        'success' => false,
                        'data' => $data,
                        'message' => $e->getMessage(),
                    ];
                }
            } else {
                $message = 'Shop Data is empty';
            }
        } else {
            $message = 'WebhookType or shop_id is missing';
        }
        return [
            'success' => false,
            'message' => $message
        ];
    }

    public function getMetafieldType($data)
    {
        if (isset($data['id'], $data['shop_id'])) {
            $id = $data['id'];
            $shopId = $data['shop_id'];
            $userId = $this->di->getUser()->id ?? $data['user_id'];
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $source = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
            $shop = $userDetails->getShop($shopId, $userId);

            try {
                if (!empty($shop)) {
                    $remoteShopId = $shop['remote_shop_id'];
                    $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init($source, true)
                        ->call('product/getMetafieldType', [], ['shop_id' => $remoteShopId, 'id' => $id], 'GET');
                    if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                        $metafield = [];
                        $metafield[$id] = $remoteResponse["data"];
                        return [
                            'success' => true,
                            'message' => $metafield
                        ];
                    } elseif ($remoteResponse['message']) {
                        $message = 'Metafield ID not found';
                    } else {
                        $message = $remoteResponse['error'] ?? 'Something went wrong';
                    }
                } else {
                    $message = "Warehouse does not exist";
                }
                return [
                    'success' => false,
                    'message' => $message,
                ];
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Shopify-metaifeld exception getMetafieldType(): ' . print_r($e->getMessage(), true), 'info', 'exception.log');
            }
        } else {
            return [
                'success' => false,
                'message=' => 'Metafield ID not provided',
            ];
        }
    }

    /**
     * Get Fulfillment Data
     *
     * @param string $data
     * @return array
     */
    public function getFulfillmentDetails($data)
    {
        if (isset($data['id'], $data['shop_id'])) {
            $id = $data['id'];
            $shopId = $data['shop_id'];
            $userId = $this->di->getUser()->id ?? $data['user_id'];
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shop = $userDetails->getShop($shopId, $userId);

            try {
                if (!empty($shop)) {
                    $remoteShopId = $shop['remote_shop_id'];
                    $requestParams = [
                        'shop_id' => $remoteShopId,
                        'order_id' => $id,
                        'app_code' => 'amazon_sales_channel'
                    ];
                    $shopifyHelper = $this->di->getObjectManager()->get('App\Shopifyhome\Components\Helper');
                    $remoteResponse = $shopifyHelper->sendRequestToShopify('get/ordershipment', [], $requestParams, 'GET');
                    if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                        if (!empty($remoteResponse["data"]['fulfillments']) && is_array($remoteResponse["data"]['fulfillments'])) {
                            $fullfillmentDetails = [];
                            foreach ($remoteResponse["data"]['fulfillments'] as $fulfillments) {
                                $fulfillmentId = $fulfillments["id"];
                                $fullfillmentDetails[$fulfillmentId] = [
                                    "createdAt" => $fulfillments["created_at"],
                                    "updatedAt" => $fulfillments["updated_at"],
                                    "TrackingCompany" => $fulfillments["tracking_company"],
                                    "TrackingNumber" => $fulfillments["tracking_number"],
                                    "TrackingURL" => $fulfillments["tracking_url"]
                                ];
                                if (!empty($fulfillments['line_items'] && is_array($fulfillments['line_items']))) {
                                    foreach ($fulfillments['line_items'] as $lineItems) {
                                        $sku = $lineItems['sku'];
                                        $quantity = $lineItems['quantity'];
                                        $fullfillmentDetails[$fulfillmentId][$sku] = [
                                            'quantity' => $quantity
                                        ];
                                    }
                                }
                            }
                            return [
                                'success' => true,
                                'message' => $fullfillmentDetails
                            ];
                        } else {
                            $message = 'Fulfillment Data not found';
                        }
                    } elseif ($remoteResponse['message']) {
                        $message = 'Order ID not found';
                    } else {
                        $message = $remoteResponse['error'] ?? 'Something went wrong';
                    }
                } else {
                    $message = "Shop not Found";
                }
                return [
                    'success' => false,
                    'message' => $message,
                ];
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Shopify-Fulfillment exception getMetafieldType(): ' . print_r($e->getMessage(), true), 'info', 'exception.log');
            }
        } else {
            return [
                'success' => false,
                'message=' => 'Order ID not provided',
            ];
        }
    }
}
