<?php

namespace App\Core\Models\Config;

use Exception;

/**
  {
        "user_id": "\"all\" | \"user_id\"",
        "key": "string",
        "group_code": "string",
        "value" : "string",
        "app_tag": "string",
        "source" : "string",
        "target" : "string",
        "source_shop_id" : "string",
        "target_shop_id" : "string",
        "source_warehouse_id" : "string",
        "target_warehouse_id" : "string",
        "updated_at" : "timestamp",
        "created_at" : "timestamp"
    }
 */

class Config extends \App\Core\Models\Base
{
    protected $table = 'config';
    protected $isGlobal = true;

    private $userId = '';
    private $source = null;
    private $sourceShopId = null;
    private $sourceWarehouseId = null;
    private $target = null;
    private $target_shop_id = null;
    private $target_warehouse_id = null;
    private $app_tag = 'default';
    private $groupCode = null;
    private $mongo = null;
    private $getDefaultValueFromConfig = "yes";

    const CREATED_CONFIG_CODE = 'created';
    const UPDATED_CONFIG_CODE = 'updated';
    const DELETED_CONFIG_CODE = 'deleted';


    public function onConstruct()
    {
        $this->reset();
        $mongo = $this->getDi()->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->mongo = $mongo->getCollectionForTable($this->table);
    }

    public function reset()
    {
        $this->user_id = 'all';
        $this->source = null;
        $this->sourceShopId = null;
        $this->sourceWarehouseId = null;
        $this->groupCode = null;
        $this->target = null;
        $this->target_shop_id = null;
        $this->getDefaultValueFromConfig = true;
        $this->target_warehouse_id = null;
        $this->app_tag = $this->getDi()->getAppCode()->getAppTag() ?? 'default';
    }

    public function setGroupCode($group_code)
    {
        $this->groupCode = $group_code;
    }

    public function setUserId($userId = null)
    {
        if (!isset($userId)) {
            $this->userId = $this->di->getUser()->id;
        } else {
            $this->userId = $userId;
        }
    }

    public function sourceSet($source)
    {
        $this->source = $source;
    }

    public function setTarget($target)
    {
        $this->target = $target;
    }

    public function setAppTag($app_tag)
    {
        $this->app_tag = $app_tag;
    }

    public function getDefaultValueFromConfig($should = true)
    {
        $this->getDefaultValueFromConfig = $should;
    }

    public function setSourceShopId($shop_id = null)
    {
        if (!isset($shop_id)) {
            return ['success' => false, 'message' => 'Shop Id is empty'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $data = $collection->findOne(['shops._id' => $shop_id]);

        if (isset($data['shops']) && count($data['shops']) > 0) {
            foreach ($data['shops'] as $shop) {
                if ($shop['_id'] == $shop_id) {
                    $this->source = $shop['marketplace'] ?? 'UNKNOW';
                    $this->sourceShopId = $shop['_id'];
                }
            }
        }
    }

    public function setTargetShopId($shop_id = null)
    {
        if (!isset($shop_id)) return ['success' => false, 'message' => 'Shop Id is empty'];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $data = $collection->findOne(['shops._id' => $shop_id]);

        if (isset($data['shops']) && count($data['shops']) > 0) {
            foreach ($data['shops'] as $shop) {
                if ($shop['_id'] == $shop_id) {
                    $this->target = $shop['marketplace'] ?? 'UNKNOW';
                    $this->target_shop_id = $shop['_id'];
                }
            }
        }
    }

    public function setSourceWarehouseId($warehouseId = null)
    {
        if (!isset($warehouseId)) {
            return ['success' => false, 'message' => 'warehouse_id Id is empty'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $data = $collection->findOne(['shops.warehouses._id' => $warehouseId]);

        if (isset($data['shops']) && count($data['shops']) > 0) {
            foreach ($data['shops'] as $shop) {
                if (isset($shop['warehouses']) && count($shop['warehouses']) > 0) {
                    foreach ($shop['warehouses'] as $warehouse) {
                        if ($warehouse['_id'] == $warehouseId) {
                            $this->source = $shop['marketplace'] ?? 'UNKNOW';
                            $this->sourceShopId = $shop['_id'];
                            $this->sourceWarehouseId = $warehouse['_id'];
                        }
                    }
                }
            }
        }
    }

    public function setTargetWarehouseId($warehouseId = null)
    {
        if (!isset($warehouseId)) {
            return ['success' => false, 'message' => 'warehouse_id Id is empty'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $data = $collection->findOne(['shops.warehouses._id' => $warehouseId]);

        if (isset($data['shops']) && count($data['shops']) > 0) {
            foreach ($data['shops'] as $shop) {
                if (isset($shop['warehouses']) && count($shop['warehouses']) > 0) {
                    foreach ($shop['warehouses'] as $warehouse) {
                        if ($warehouse['_id'] == $warehouseId) {
                            $this->target = $shop['marketplace'] ?? 'UNKNOW';
                            $this->target_shop_id = $shop['_id'];
                            $this->target_warehouse_id = $warehouse['_id'];
                        }
                    }
                }
            }
        }
    }

    public function setConfig($configDatas)
    {
        $res = [];
        foreach ($configDatas as $configData) {
            if (!isset($configData['key'])) {
                return [
                    'success' => false,
                    'message' => 'Key is missing, send key'
                ];
            }

            if (!isset($configData['group_code'])) {
                $configData['group_code'] = 'default';
            }

            $configData['user_id'] = $this->userId;

            $query = [
                'user_id' => $this->userId,
                'key' => $configData['key'],
                'group_code' => $configData['group_code'],
            ];

            if (isset($configData['source_shop_id'])) {
                $query['source_shop_id'] = $configData['source_shop_id'];
            }

            if (isset($configData['target_shop_id'])) {
                $query['target_shop_id'] = $configData['target_shop_id'];
            }

            if (isset($configData['source_warehouse_id'])) {
                $query['source_warehouse_id'] = $configData['source_warehouse_id'];
            }

            if (isset($configData['target_warehouse_id'])) {
                $query['target_warehouse_id'] = $configData['target_warehouse_id'];
            }

            $config = $this->mongo->findOne($query);

            $date = date('c');

            if (isset($config) && !empty($config)) {
                $configData['updated_at'] = $date;
                $this->mongo->updateOne(['_id' => $config['_id']], ['$set' => $configData]);
                $res['data'] = [
                    'success' => true,
                    'message' => 'Config updated',
                    'code' => self::UPDATED_CONFIG_CODE
                ];
                $this->fireAfterSaveConfigEvent(
                    [
                        'config_data' => $configData,
                        'response' => $res
                    ]
                );
            } else {
                $res['data'] = $this->saveNewConfig($configData);
                if ($res['data']['success']) {
                    $res['data']['code'] = self::CREATED_CONFIG_CODE;
                }
                $this->fireAfterSaveConfigEvent(
                    [
                        'config_data' => $configData,
                        'response' => $res
                    ]
                );
            }
        }
        return $res;
    }

    /**
     * @param array $eventData
     */
    private function fireAfterSaveConfigEvent($eventData = [])
    {
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire(
            'application:afterSaveConfig',
            $this,
            $eventData
        );
    }

    public function saveNewConfig($configData)
    {

        if (isset($configData['source_warehouse_id'])) {
            $this->setSourceWarehouseId($configData['source_warehouse_id']);
            $configData['source_shop_id'] = $this->sourceShopId;
            $configData['source'] = $this->source;
        }

        if (isset($configData['target_warehouse_id'])) {
            $this->setTargetWarehouseId($configData['target_warehouse_id']);
            $configData['target_shop_id'] = $this->target_shop_id;
            $configData['target'] = $this->target;
        }

        if (isset($configData['source_shop_id'])) {
            $this->setSourceShopId($configData['source_shop_id']);
            $configData['source'] = $this->source;
        }

        if (!isset($configData['app_tag'])) {
            $configData['app_tag'] = $this->app_tag;
        }

        if (isset($configData['target_shop_id'])) {
            $this->setTargetShopId($configData['target_shop_id']);
            $configData['target'] = $this->target;
        }

        if (!isset($configData['source']) && !isset($configData['target'])) {
            if (!isset($configData['source'])) return [
                'success' => false,
                'message' => 'Source is UNKNOW'
            ];
            if (!isset($configData['target'])) return [
                'success' => false,
                'message' => 'Target is UNKNOW'
            ];
        }



        $configData['created_at'] = date('c');

        $in = $this->mongo->insertOne($configData);

        if ($in->getInsertedCount()) {
            return [
                'success' => true,
                'message' => 'New Config Doc created'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'No New Config Doc created'
            ];
        }
    }

    public function getConfig($key = null)
    {
        $query = [];
        $aggregate = [];

        $query['user_id'] = $this->userId;

        if (isset($this->source)) {
            $query['source'] = $this->source;
        }

        if (isset($this->target)) {
            $query['target'] = $this->target;
        }

        if (isset($key)) {
            $query['key'] = is_array($key) ? ['$in' => $key] : $key;
        }

        if (isset($this->groupCode)) {
            $query['group_code'] = $this->groupCode;
        }

        if (isset($this->target_shop_id)) {
            $query['target_shop_id'] = $this->target_shop_id;
        }

        if (isset($this->target_warehouse_id)) {
            $query['target_warehouse_id'] = $this->target_warehouse_id;
        }
        if (isset($this->sourceWarehouseId)) {
            $query['source_warehouse_id'] = $this->sourceWarehouseId;
        }

        if (isset($this->sourceShopId)) {
            $query['source_shop_id'] = $this->sourceShopId;
        }

        if (isset($this->app_tag)) {
            $query['app_tag'] = $this->app_tag;
        }

        $aggregate[] = [
            '$match' => $query
        ];

        $configData = $this->mongo->aggregate($aggregate)->toArray();

        if ($this->getDefaultValueFromConfig) {
            $configData = $this->getDefaultConfig($key, $configData);
        }

        return $configData;
    }

    public function getDefaultConfig($key = null, $configData = [])
    {
        $query = [];
        $aggregate = [];

        $defaultConfigThatWillBeMerged = [];

        $query['user_id'] = 'all';

        if (isset($this->source)) {
            $query['source'] = $this->source;
        }

        if (isset($this->target)) {
            $query['target'] = $this->target;
        }

        if (isset($key)) {
            $query['key'] = $key;
        }

        if (isset($this->groupCode)) {
            $query['group_code'] = $this->groupCode;
        }

        $aggregate[] = [
            '$match' => $query
        ];

        $defaultconfigData = $this->mongo->aggregate($aggregate)->toArray();

        foreach ($defaultconfigData as $key => $default_value) {
            $flag = true;
            foreach ($configData as $value) {
                if ($default_value['key'] === $value['key']) {
                    $flag = false;
                }
            }
            if ($flag) {
                $defaultConfigThatWillBeMerged[] = $default_value;
            }
        }

        foreach ($defaultConfigThatWillBeMerged as $value) {
            $configData[] = $value;
        }

        return $configData;
    }

    /**
     * deleteConfig Function
     * Handles the deletion of configuration documents using the BulkWrite operation.
     * Mandatory Parameters: [key, group_code]
     * @param array $configDatas
     * @return array
     */
    public function deleteConfig($configDatas) {
        $filter = [];
        foreach ($configDatas as $configData) {
            if (!isset($configData['key'],$configData['group_code'])) {
                return [
                    'success' => false,
                    'message' => 'key and group_code is mandatory'
                ];
            }

            $configData['user_id'] = $this->userId;

            $query = [
                'user_id' => $this->userId,
                'key' => $configData['key'],
                'group_code' => $configData['group_code'],
            ];

            if (isset($configData['source_shop_id'])) {
                $query['source_shop_id'] = $configData['source_shop_id'];
            }

            if (isset($configData['target_shop_id'])) {
                $query['target_shop_id'] = $configData['target_shop_id'];
            }
            $filter[] =
            [
                'deleteOne' => [$query]
            ];
        }
        if(!empty($filter)) {
            $result = $this->mongo->bulkWrite($filter);
            $response['data'] = [
                'success' => true,
                'message' => 'Config data deleted',
                'code' => self::DELETED_CONFIG_CODE,
                'result' => $result
            ];
        }

        return $response ?? [];
    }
}
