<?php

namespace App\Core\Models\User;

use Exception;

class Details extends \App\Core\Models\BaseMongo
{
    protected $table = 'user_details';

    protected $isGlobal = true;

    /**
     * Set user config data by key
     *
     * @param string $key
     * @param mixed $value
     * @param int $userId
     * @return array with success true/false and message
     */
    public function setConfigByKey($key, $value, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }
        $collection = $this->getCollection();
        if ($key == 'shops') {
            if (is_array($value)) {
                foreach ($value as $val) {
                    $this->addShop($val);
                }
                return ['success' => true, 'message' => 'Shops Added Successfuly'];
            }
            return ['success' => false, 'message' => 'Shops Data format not correct', 'code' => 'wrong_format'];
        } else {
            $appTag = $this->di->getAppCode()->getAppTag();
            $settingValue = [
                'value' => $value,
                'updated_at' => date('c')
            ];
            $result = $collection->UpdateOne(
                ['user_id' => $userId],
                ['$set' => ["{$appTag}.{$key}" => $settingValue]]
            );
            if ($result->getMatchedCount()) {
                $eventsManager = $this->di->getEventsManager();
                $eventsManager->fire('application:afterUserConfigSave', $this, ['key'=> $key, 'value'=>$value]);
                return ['success' => true, 'message' => 'Shop Updated Successfuly'];
            } else {
                return ['success' => false, 'message' => 'No Record Matched 1', 'code' => 'no_record_found'];
            }
        }
    }

    /**
     * Get user config data by key
     *
     * @param string $key
     * @param int $userId
     * @return mixed
     */
    public function getConfigByKey($key, $userId = false)
    {
        try {
            if (!$userId) {
                $userId = $this->di->getUser()->id;
            }

            $appTag = $this->di->getAppCode()->getAppTag();
            $result = $this->loadByField(
                ['_id' => $userId],
                [
                    "typeMap" => ['root' => 'array', 'document' => 'array'],
                    "projection" => ["{$appTag}.{$key}" => 1],
                ]
            );
            if (!empty($result) && isset($result[$appTag][$key])) {
                return $result[$appTag][$key]['value'] ?? $result[$appTag][$key];
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get user config data
     *
     * @param int $userId
     * @return array
     */
    public function getConfig($userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }
        $collection = $this->getCollection();
        return $collection->findOne(
            ['user_id' => $userId],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        );
    }

    /**
     * Get shop details of user
     *
     * @param int $shopId
     * @param int $userId
     * @return array
     */
    public function getShop($shopId, $userId = false, $fetchFromDB = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }
        if (!$shopId) {
            return false;
        }
        if ($userId == $this->di->getUser()->id && !$fetchFromDB) {
            $shopData = [];
            $shops = $this->di->getUser()->shops ?? [];
            if (!empty($shops)) {
                foreach ($shops as $shop) {
                    if ($shop['_id'] == $shopId) {
                        $shopData = $shop;
                        break;
                    }
                }
                if (!empty($shopData)) {
                    return $shopData;
                }
            }
        }
        $collection = $this->getCollection();
        $result = $collection->findOne(
            ['user_id' => (string)$userId, 'shops._id' => (string)$shopId],
            [
                "projection" => ['shops.$' => 1],
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ]
        );
        if (!empty($result)) {
            return $result['shops'][0];
        }
        return false;
    }

    /**
     * Find shop details of user
     *
     * @param int $shopId
     * @param int $userId
     * @return array
     */
    public function findShop($data, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }
        $filters = [];
        $filters['user_id'] = $userId;

        foreach ($data as $key => $value) {
            $filters['shops.' . $key] = $value;
        }

        $collection = $this->getCollection();
        $result = $collection->findOne(
            $filters,
            [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
                "projection" => ['shops.$' => 1],
            ]
        );
        return $result['shops'];
    }

    /**
     * Get warehouse details of specific shop
     *
     * @param int $shopId
     * @param int $warehouseId
     * @param int $userId
     * @return array
     */
    public function getWarehouse($shopId, $warehouseId, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        if (is_string($userId)) {
            $userId = new \MongoDB\BSON\ObjectId($userId);
        }

        if (!$shopId || !$warehouseId) {
            return false;
        }
        $filters = ['_id' => $userId, 'shops._id' => $shopId, 'shops.warehouses._id' => $warehouseId];
        $finalQuery = [
            ['$match' => $filters],
            ['$unwind' => '$shops'],
            ['$unwind' => '$shops.warehouses'],
            ['$match' => $filters],
            ['$project' => ['shops.warehouses' => 1]],
        ];
        $collection = $this->getCollection();
        $result = $collection->aggregate(
            $finalQuery,
            ['typeMap' => ['root' => 'array', 'document' => 'array']]
        )->toArray();
        if (!empty($result)) {
            return $result[0]['shops']['warehouses'];
        }
        return false;
    }

    public function getWarehouseMarketplaceWise($marketplace, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }


        if (is_string($userId)) {
            $userId = new \MongoDB\BSON\ObjectId($userId);
        }

        $collection = $this->getCollection();
        $userDetails = $collection->findOne(['_id' => $userId]);

        if (empty($userDetails)) {
            return ['success' => false, 'message' => 'Shop details not found'];
        }
        $warehouses = [];

        if ($marketplace) {
            foreach ($userDetails['shops'] as $value) {
                if (
                    isset($value['warehouses'])
                    && $value['marketplace'] === $marketplace
                ) {
                    foreach ($value['warehouses'] as $warehouse) {
                        $warehouses[] = [
                            'id' => $warehouse['_id'],
                            'name' => $warehouse['name'] ?? $warehouse['_id']
                        ];
                    }
                }
            }
        }
        return $warehouses;
    }

    /**
     * Add Shop in user_details table
     *
     * @param array $shopDetails ['name'=>'','domain'=>'','marketplace'=>'','warehouses'=>[['name'=>'','location'=>'']]]
     * @param int $userId
     * @param array $uniqueKeys ['domain', '_id']
     * @return array with success true/false and message
     */
    public function addShop($shopDetails, $userId = false, $uniqueKeys = ['domain'])
    {

        if (empty($shopDetails)) {
            return ['success' => false, 'message' => 'Shop details not provided', 'code' => 'insuficiant_data'];
        }
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $username = $this->di->getUser()->username;
        $email = $this->di->getUser()->email;
        if($username == 'admin' || $username == 'app' || $username == 'anonymous') return ['success' => false,'message'=>"user not set properly. check user token."];

        if (is_string($userId)) {
            $userId = new \MongoDB\BSON\ObjectId($userId);
        }

        $collection = $this->getCollection();
        $filters = [];
        $filters['_id'] = $userId;
        foreach ($uniqueKeys as $key) {
            if (isset($shopDetails[$key])) {
                $filters["shops.{$key}"] = $shopDetails[$key];
            }
        }
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array'],
            "projection" => ['shops' => 1],
        ];

        try {
            $response = $collection->findOne($filters, $options);

            if (!empty($response)) {
                $index = 0;
                foreach ($response['shops'] as $keys => $shopValue) {
                    foreach ($uniqueKeys as $key) {
                        if (isset($shopValue[$key]) && $shopValue[$key] === $shopDetails[$key]) {
                            $index = $keys;
                        }
                    }
                }
                $foundShop = $response['shops'][$index];
                $finalShop = $this->mergeShopData($foundShop, $shopDetails);
                $finalShop['updated_at'] = new \MongoDB\BSON\UTCDateTime();

                $updateResult = $collection->updateOne(
                    ['_id' => $userId, 'shops' => ['$elemMatch' => ['_id' => $finalShop['_id']]]],
                    ['$set' => ['shops.$' => $finalShop]]
                );
                if ($updateResult->getModifiedCount()) {
                    $this->setUserCache('shops', false);
                    $eventsManager = $this->di->getEventsManager();
                    $eventsManager->fire(
                        'application:shopUpdate',
                        $this,
                        ['shop' => $finalShop, 'old_shop' => $foundShop]
                    );
                    return [
                        'success' => true,
                        'message' => 'Shop Updated Successfuly',
                        'code' => "updated",
                        'data' => [
                            'shop_id' => $finalShop['_id'],
                        ],
                    ];
                } else {
                    return ['success' => false, 'message' => 'Shop not updated', 'finalShop' => $finalShop];
                }
            } else {
                $oldShopData = $this->getSavedShopData($shopDetails, $userId, $uniqueKeys);
                if (!empty($oldShopData)) {
                    $newShopDetails = $shopDetails;

                    if (isset($newShopDetails['old_remote_shop_id'])) {
                        $newShopDetails['remote_shop_id'] = $shopDetails['old_remote_shop_id'];
                    }

                    $index = 0;
                    foreach ($oldShopData['shops'] as $keys => $shopValue) {
                        foreach ($uniqueKeys as $key) {
                            if (isset($shopValue[$key]) && $shopValue[$key] == $newShopDetails[$key]) {
                                $index = $keys;
                            }
                        }
                    }
                    $shopDetails['_id'] = (string)$oldShopData['shops'][$index]['_id'];
                } else {
                    $shopDetails['_id'] = $this->getCounter('shop_id');
                }
                $shopDetails['created_at'] = new \MongoDB\BSON\UTCDateTime();
                $shopDetails['updated_at'] = new \MongoDB\BSON\UTCDateTime();
                $appCode = $this->di->getAppCode()->get()[$shopDetails['marketplace']];
                $isBackupPresent = $this->isBackupPresent($username, $email, $appCode);
                if ($isBackupPresent['success'] && isset($shopDetails['apps'])) {
                    foreach ($shopDetails['apps'] as $key => $app) {
                        if ($app['code'] == $appCode) {
                            $shopDetails['apps'][$key]['reinstalled'] = true;
                        }
                    }
                }
                if (isset($shopDetails['warehouses'])) {
                    foreach ($shopDetails['warehouses'] as $key => $warehouse) {
                        if (
                            isset($shopDetails['marketplace'])
                            && in_array($shopDetails['marketplace'], ['ebay'])
                        ) {
                            $shopDetails['warehouses'][$key]['_id'] = $shopDetails['_id'];
                        } else {
                            $shopDetails['warehouses'][$key]['_id'] = $this->getCounter('warehouse_id');
                        }
                    }
                } else {
                    $shopDetails['warehouses'] = [];
                }

                $updateResult = $collection->updateOne(
                    ['_id' => $userId],
                    ['$push' => ['shops' => $shopDetails]]
                );
                $this->setUserCache('shops', false);

                #TODO: Trigger event to change user shop_status || user_status to active | deactive | uninstall
                $eventsManager = $this->di->getEventsManager();
                $eventsManager->fire(
                    'application:afterStatusChange',
                    $this,
                    ['userId' => (string)$userId, 'shopId' => $shopDetails['_id'], 'level' => 'app']
                );

                return [
                    'success' => true,
                    'message' => 'Shop Added Successfuly',
                    'code' => "added",
                    'data' => [
                        'shop_id' => $shopDetails['_id'],
                    ],
                ];
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
            die;
            return ['success' => false, 'message' => 'Something went wrong', 'code' => 'unknown_exception'];
        }
    }
    /**
     * Fetch shop data from user log collections
     * @param array $shopDetails
     * @param array $uniqueKeys
     * @param string $userId
     * @return array fetch data from user_log
     */

    public function getSavedShopData($shopDetails, $userId = false, $uniqueKeys = ['domain'])
    {
        if (isset($shopDetails['old_remote_shop_id'])) {
            $shopDetails['remote_shop_id'] = $shopDetails['old_remote_shop_id'];
        }
        $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
        $collection = $mongo->getCollectionForTable('user_log');
        $filters = [];
        $filters['_id'] = $userId;
        foreach ($uniqueKeys as $key) {
            if (isset($shopDetails[$key])) {
                $filters["shops.{$key}"] = $shopDetails[$key];
            }
        }
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array'],
        ];
        $response = $collection->findOne($filters, $options);
        if (empty($response) && in_array('remote_shop_id', $uniqueKeys)) {
            $filters['shops.remote_shop_id'] = (int)$filters['shops.remote_shop_id'];
            $response = $collection->findOne($filters, $options);
        }
        return $response;
    }


    /**
     * Merge Shop data including the warehoue. It wll be used in add/update Shop functions.
     *
     * @param array $shopDetails
     * @param array $uniqueKeys
     * @return array with merge shop data
     */
    private function mergeShopData($foundShop, $shopDetails)
    {
        $warehouses = [];
        if (isset($shopDetails['warehouses'])) {
            if (isset($foundShop['warehouses']) && !empty($foundShop['warehouses'])) {
                foreach ($foundShop['warehouses'] as $key => $warehouse) {
                    $warehouses[$foundShop['warehouses'][$key]['_id']] = $foundShop['warehouses'][$key];
                }
            }
            foreach ($shopDetails['warehouses'] as $key => $warehouse) {
                if (!isset($shopDetails['warehouses'][$key]['_id'])) {
                    if (isset($shopDetails['marketplace']) && in_array($shopDetails['marketplace'], ['ebay', 'amazon'])) {
                        $shopDetails['warehouses'][$key]['_id'] = $foundShop['_id'];
                    } else {
                        $shopDetails['warehouses'][$key]['_id'] = $this->getCounter('warehouse_id');
                    }
                } else {
                    $shopDetails['warehouses'][$key] = array_merge(
                        $warehouses[$shopDetails['warehouses'][$key]['_id']],
                        $shopDetails['warehouses'][$key]
                    );
                }
            }
        } elseif (isset($foundShop['warehouses'])) {
            $shopDetails['warehouses'] = $foundShop['warehouses'];
        }
        return array_merge($foundShop, $shopDetails);
    }

    /**
     * Update Shop in user_details table
     *
     * @param array $shopDetails ['name' = '', 'domain' => '', 'warehouses' => [ ['name'= > '', 'location' => '']]]
     * @param int $userId
     * @param array $uniqueKeys ['domain', '_id']
     * @return array with success true/false and message
     */
    public function updateShop($shopDetails, $userId = false, $uniqueKeys = ['domain'])
    {
        if (empty($shopDetails)) {
            return ['success' => false, 'message' => 'Shop details not provided', 'code' => 'insuficiant_data'];
        }
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        if (is_string($userId)) {
            $userId = new \MongoDB\BSON\ObjectId($userId);
        }
        $collection = $this->getCollection();
        $filters = [];
        $filters['_id'] = $userId;

        foreach ($uniqueKeys as $key) {
            if (isset($shopDetails[$key])) {
                $filters["shops.{$key}"] = $shopDetails[$key];
            }
        }
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array'],
            "projection" => ['shops.$' => 1],
        ];

        try {
            $response = $collection->find($filters, $options)->toArray();
            if (!empty($response)) {
                $foundShop = $response[0]['shops'][0];
                $finalShop = $this->mergeShopData($foundShop, $shopDetails);
                $finalShop['updated_at'] = date('c');
                $updateResult = $collection->updateOne(
                    ['_id' => $userId, 'shops' => ['$elemMatch' => ['_id' => $finalShop['_id']]]],
                    ['$set' => ['shops.$' => $finalShop]]
                );
                if ($updateResult->getMatchedCount()) {
                    $this->setUserCache('shops', false);
                    return ['success' => true, 'message' => 'Shop Updated Successfuly'];
                } else {
                    return [
                        'success' => false,
                        'message' => 'No Record Matched 3',
                        'code' => 'no_record_found',
                        'finalData' => $finalShop
                    ];
                }
            } else {
                return ['success' => false, 'message' => 'No shop found', 'code' => 'not_found'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Something went wrong', 'code' => 'unknown_exception'];
        }
    }

    /**
     * Delete Shop in user_details table
     *
     * @param int $shopId
     * @param int $userId
     * @return array with success true/false and message
     */
    public function deleteShop($shopId, $userId = false)
    {
        if (!$shopId) {
            return ['success' => false, 'message' => 'Shop Id not provided', 'code' => 'insuficiant_data'];
        }
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $collection = $this->getCollection();
        $filters = [];
        $filters['user_id'] = $userId;
        $filters['shops._id'] = $shopId;
        try {
            $updateResult = $collection->updateOne(
                $filters,
                ['$pull' => ['shops' => ['_id' => $shopId]]]
            );
            if ($updateResult->getMatchedCount()) {
                $this->setUserCache('shops', false);
                return ['success' => true, 'message' => 'Shop has been Deleted Successfuly'];
            } else {
                return ['success' => false, 'message' => 'No Record Matched 4', 'code' => 'no_record_found'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Something went wrong', 'code' => 'unknown_exception'];
        }
    }

    /**
     * Add Warehouse in user_details table
     *
     * @param int $shopId
     * @param array $warehouseDetails ['name'=> '', 'location' => '']
     * @param int $userId
     * @param array $uniqueKeys ['_id']
     * @return array with success true/false and message
     */
    public function addWarehouse($shopId, $warehouseDetails, $userId = false, $uniqueKeys = ['_id'])
    {

        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }
        $collection = $this->getCollection();
        $filters = [];
        $filters['user_id'] = $userId;
        $filters['shops._id'] = $shopId;
        if (!$shopId || empty($warehouseDetails)) {
            return [
                'success' => false,
                'message' => 'Shop Id or Warehouse details not provided',
                'code' => 'insufficient_data'
            ];
        }
        foreach ($uniqueKeys as $key) {
            if (isset($warehouseDetails[$key])) {
                $filters["shops.warehouses.{$key}"] = $warehouseDetails[$key];
            }
        }

        $finalQuery = [
            ['$match' => $filters],
            ['$unwind' => '$shops'],
            ['$match' => $filters],
            ['$project' => ['shops.warehouses' => 1]],
        ];

        try {
            $response = $collection->aggregate(
                $finalQuery,
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            )->toArray();

            if (
                empty($response) ||
                (isset($response[0]['shops']['warehouses']) &&
                    empty(($response[0]['shops']['warehouses'])))
            ) {
                $warehouseDetails['_id'] = $this->getCounter('warehouse_id');
                $updateResult = $collection->updateOne(
                    ['user_id' => $userId, 'shops' => ['$elemMatch' => ['_id' => $shopId]]],
                    ['$push' => ['shops.$.warehouses' => $warehouseDetails]]
                );
                if ($updateResult->getMatchedCount()) {
                    return ['success' => true, 'message' => 'Warehouse has been added Successfuly in Shop'];
                } else {
                    return ['success' => false, 'message' => 'No Record Matched', 'code' => 'no_record_found'];
                }
            } else {
                return $this->updateWarehouse($shopId, $warehouseDetails, $userId, $uniqueKeys);
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Something went wrong',
                'code' => 'unknown_exception',
                'msg_ss' => $e->getMessage(),
                'msg' => $e->getMessage()
            ];
        }
    }

    /**
     * Add Warehouse in user_details table
     *
     * @param int $shopId
     * @param array $warehouseDetails ['_id' => 'name' => '', 'location' => '']
     * @param int $userId
     * @param array $uniqueKeys ['_id']
     * @return array with success true/false and message
     */
    public function updateWarehouse($shopId, $warehouseDetails, $userId = false, $uniqueKeys = ['_id'])
    {
        if (!$shopId || empty($warehouseDetails)) {
            return ['success' => false, 'message' => 'Shop Id or Warehouse details not provided', 'code' => 'insuficiant_data'];
        }
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }
        $collection = $this->getCollection();
        $filters = [];
        $filters['user_id'] = $userId;
        $filters['shops._id'] = $shopId;
        $arraFilter = [];
        $update = [];
        foreach ($uniqueKeys as $key) {
            if (isset($warehouseDetails[$key])) {
                $filters["shops.warehouses.{$key}"] = $warehouseDetails[$key];
                $arraFilter[] = ["element.{$key}" => $warehouseDetails[$key]];
            }
        }
        foreach ($warehouseDetails as $key=>$value) {
            $update["shops.$[].warehouses.$[element].{$key}"] = $value;
        }
        try {
            $updateResult = $collection->updateOne($filters, ['$set' => $update], ['arrayFilters' => $arraFilter]);
            if ($updateResult->getMatchedCount()) {
                return ['success' => true, 'message' => 'Warehouse has been Updated Successfuly in Shop'];
            } else {
                return ['success' => false, 'message' => 'No Record Matched 7', 'code' => 'no_record_found'];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Something went wrong',
                'code' => 'unknown_exception',
                'msg' => $e->getMessage()
            ];
        }
    }

    /**
     * Delete warehouse in user_details table
     *
     * @param int $shopId
     * @param int $warehouseId
     * @param bool $userId
     * @return array with success true/false and message
     */
    public function deleteWarehouse($shopId, $warehouseId, $userId = false)
    {
        if (!$shopId || !$warehouseId) {
            return [
                'success' => false,
                'message' => 'Shop Id or Warehouse Id not provided',
                'code' => 'insuficiant_data'
            ];
        }
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }
        $collection = $this->getCollection();
        $filters = [];
        $filters['user_id'] = $userId;
        $filters['shops._id'] = $shopId;
        $filters["shops.warehouses._id"] = $warehouseId;

        try {
            $updateResult = $collection->updateOne(
                $filters,
                ['$pull' => ['shops.$.warehouses' => ['_id' => $warehouseId]]]
            );
            if ($updateResult->getMatchedCount()) {
                return ['success' => true, 'message' => 'Warehouse has been Deleted Successfuly from Shop'];
            } else {
                return ['success' => false, 'message' => 'No Record Matched 8', 'code' => 'no_record_found'];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Something went wrong',
                'code' => 'unknown_exception',
                'msg' => $e->getMessage()
            ];
        }
    }

    public function getUserbyShopId($shopId, $projectionData = ['user_id' => 1])
    {
        $collection = $this->getCollection();
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array'],
            "projection" => $projectionData,
        ];
        $filters = [
            'shops.remote_shop_id' => $shopId,
        ];
        try {
            return $collection->findOne($filters, $options);
        } catch (Exception $e) {
            // log $e->message internally
            return [
                'success' => false,
                'message' => 'Something went wrong',
                'code' => 'unknown_exception',
                'msg' => $e->getMessage()
            ];
        }
    }

    /**
     * @param bool $userId
     * @param bool $marketplace
     * @return array
     */
    public function getDataByUserID($userId = false, $marketplace = false, $warehouseId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $collection = $this->getCollection();
        $userDetails = $collection->findOne(['user_id' => (string)$userId]);

        if (empty($userDetails)) {
            return ['success' => false, 'message' => 'Shop details not found'];
        }
        $shops = [];

        if ($marketplace) {
            foreach ($userDetails['shops'] as $value) {
                if ($value['marketplace'] === $marketplace) {
                    if ($warehouseId) {
                        foreach ($value['warehouses'] as $warehouse) {
                            if ($warehouse['_id'] == $warehouseId) {
                                $shops = $value;
                            }
                        }
                    } else {
                        $shops = $value;
                    }
                }
            }
        }
        return $shops;
    }

    /**
     * Get user details for given key
     *
     * @param string $key
     * @param int $userId
     * @return mixed
     */
    public function getUserDetailsByKey($key, $userId = false)
    {
        try {
            if (!$userId) {
                $userId = $this->di->getUser()->id;
            }
            $result = $this->loadByField(
                ['_id' => $userId],
                [
                    "typeMap" => ['root' => 'array', 'document' => 'array'],
                    "projection" => ["{$key}" => 1],
                ]
            );
            if (!empty($result) && isset($result[$key])) {
                return $result[$key];
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }


    public function getUserByRemoteShopId($shopId, $projectionData = ['user_id' => 1], $appCode = false)
    {
        $collection = $this->getCollection();
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array'],
            "projection" => $projectionData,
        ];
        $filters = [
            'shops.remote_shop_id' => $shopId,
        ];
        if ($appCode) {
            $filters['shops.apps.code'] = $appCode;
        }

        try {
            return $collection->findOne($filters, $options);
        } catch (Exception $e) {
            // log $e->message internally
            return [
                'success' => false,
                'message' => 'Something went wrong',
                'code' => 'unknown_exception',
                'msg' => $e->getMessage()
            ];
        }
    }



    public function getAllUserByRemoteShopId($shopId, $projectionData = ['user_id' => 1], $appCode = false)
    {
        $collection = $this->getCollection();
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array'],
            "projection" => $projectionData,
        ];
        $filters = [
            'shops.remote_shop_id' => $shopId,
        ];
        if ($appCode) {
            $filters['shops.apps.code'] = $appCode;
        }

        try {
            return $collection->find($filters, $options)->toArray();
        } catch (Exception $e) {
            // log $e->message internally
            return [
                'success' => false,
                'message' => 'Something went wrong',
                'code' => 'unknown_exception',
                'msg' => $e->getMessage()
            ];
        }
    }

    /**
     * @param $userId
     * @param bool $marketplace
     * @param bool $shopId
     * @param bool $warehouseId
     * @return array
     */
    public function getAllConnectedShops($userId, $marketplace = false, $shopId = false, $warehouseId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $collection = $this->getCollection();
        $userDetails = $collection->findOne(['user_id' => (string)$userId]);

        if (empty($userDetails)) {
            return ['success' => false, 'message' => 'Shop details not found'];
        }
        $shops = [];

        if ($marketplace && isset($userDetails['shops'])) {
            foreach ($userDetails['shops'] as $value) {
                if ($value['marketplace'] === $marketplace) {
                    if ($warehouseId) {
                        foreach ($value['warehouses'] as $warehouse) {
                            if ($warehouse['_id'] == $warehouseId) {
                                $shops[] = $value;
                            }
                        }
                    } else {
                        $shops[] = $value;
                    }
                }
            }
        }
        return $shops;
    }


    /**
     * Function to change app statue to active | deactivate | uninstall | block
     *
     * @param string $status
     * @param string $shopId
     * @param string appCode
     * @param boolean $userId
     * @return void
     */
    public function setStatusInUser($status = "active", $userId = false, $shopId = null, $appCode = null)
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('user_details');
        $filter = [];
        $allowedStatus = ['active', 'inactive', 'uninstall', 'block'];


        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        if (isset($shopId) && isset($appCode)) {

            $level = 'app';
            $filter = [
                ['shop._id' => $shopId],
                ['app.code' =>  $appCode]
            ];

            $key = $userId . '_' . $shopId . '_' . $appCode;

            $query = ['shops.$[shop].apps.$[app].app_status' => $status];
        } elseif (isset($shopId)) {

            $level = 'shop';
            $filter = [
                ['shop._id' => $shopId]
            ];

            $key = $userId . '_' . $shopId;

            $query = ['shops.$[shop].shop_status' => $status];
        } elseif (isset($userId)) {

            $level = 'user';
            $key = $userId;
            $query = ['user_status' => $status];
        } else {

            return  [
                'success' => false,
                'message' => 'shop_status and user_id are required!'
            ];
        }

        if (!in_array($status, $allowedStatus)) {
            return  [
                'success' => false,
                'message' => $this->di->getLocale()->_('allowed_status_are', [
                    'status' => implode(', ', $allowedStatus)
                ])
            ];
        } elseif ($status == 'uninstall' && $level != 'app') {
            return  [
                'success' => false,
                'message' => $this->di->getLocale()->_('invalid_not_status_type', [
                    'level' => $level
                ])
            ];
        }

        try {
            $updateResult = $collection->updateOne(
                [
                    "_id" => new \MongoDB\BSON\ObjectId($userId),
                ],
                [
                    '$set' => $query
                ],
                [
                    'arrayFilters' => $filter
                ]

            );

            #TODO: remove status cache if exists
            if ($this->di->getCache()->get($key)) {
                $this->di->getCache()->delete($key);
            }

            if ($updateResult->getModifiedCount()) {

                $eventsManager = $this->di->getEventsManager();
                $eventsManager->fire(
                    'application:afterStatusChange',
                    $this,
                    ['userId' => $userId, 'shopId' => $shopId, 'level' => $level]
                );

                return [
                    'success' => true,
                    'message' => ($appCode . " " ?? '') . $level . ' status updated to ' . $status,
                ];
            }

            return [
                'success' => false,
                'message' => 'Trouble updating ' . $level . ' status to ' . $status . "!",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }


    public function isBackupPresent($userName, $email, $appCode)
    {
        $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
        $collection = $mongo->getCollectionForTable('uninstall_user_details');
        $query = [
            'username' => $userName,
            'email' => $email,
            'shops' => [
                '$elemMatch' => [
                    'apps.code' => $appCode
                ]
            ]
        ];
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $response = $collection->findOne($query, $options);
        if (!is_null($response)&&!empty($response['_id']))
        return ['success' => true, "message" => "App backup found"];
        return ['success' => false, 'message' => 'No backup found.'];
    }
}
