<?php

namespace App\Connector\Models\Product;

use App\Core\Models\Base;


class Marketplace extends Base
{
    public $_mongo;

    public $_refineProductsTable;

    public $_userId;


    public $_productsUpdates = [];

    public $_allProductData = [];

    public $_updateRefineProduct = [];

    public $_sourceShopId = false;
    public $_targetShopId = false;

    public $_allTargetIds = false;

    public $_errors = [];
    public $_db_queries = 0;

    public $_containerIdExists = false;

    // in case, we decided to change it in future
    public $_childKey = 'marketplace';

    public $_sourceMarketplace;

    public $abortRefineSyncingForMarketplacesArr = [];

    public $_targetUpdates;

    public function init($data): void
    {
        $this->_productsUpdates = [];
        $this->_allProductData = [];
        $this->_updateRefineProduct = [];
        $this->_errors = [];
        $this->_allTargetIds = false;
        $this->_targetShopId = false;
        $this->_db_queries = 0;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_mongo = $mongo->getCollectionForTable('product_container');
        $this->_refineProductsTable = $mongo->getCollectionForTable('refine_product');

        if (isset($data['childInfo']['source_marketplace']) || isset($data['source_marketplace'])) {
            $this->_sourceMarketplace = isset($data['childInfo']['source_marketplace']) ? $data['childInfo']['source_marketplace'] : $data['source_marketplace'];
            $sourceM = $this->di->getConfig()->connectors->toArray();

            $this->_containerIdExists = isset($sourceM[$this->_sourceMarketplace]['useContainerId']) ? $sourceM[$this->_sourceMarketplace]['useContainerId'] : false;
        }

        if (isset($data['user_id'])) {
            $this->_userId = $data['user_id'];
        } else {
            $this->_userId = $this->di->getUser()->id;
        }

        if (isset($data['source_shop_id'])) {
            $this->_sourceShopId = $data['source_shop_id'];
        }

        if (isset($data['childInfo']['shop_id']) && isset($data['childInfo']['target_marketplace'])) {
            $this->_targetShopId = $data['childInfo']['shop_id'];
        }

        if ($this->di->getConfig()->get('abort_refine_syncing')) {
            $this->abortRefineSyncingForMarketplacesArr = $this->di->getConfig()->get('abort_refine_syncing')->toArray() ?? [];
        }
    }

    public function testRecieved($params)
    {
        // print_r($params);
        return true;
    }

    /**
     * $data = [
     * source_product_id: "string",
     * 'childInfo' = []
     * ]
     */
    public function marketplaceSaveAndUpdate($data, $allSourceProductIdsData = false, $containerIdExists = false)
    {
        // if ( $this->di->getUser()->id == "641adf51fde0c9a1e0054481" ) {
        //     // print_r($data);
        //     // print_r($allSourceProductIdsData);
        //     // print_r($containerIdExists);die;
        //     $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        //     $this->di->getLog()->logContent('-' , 'info', 'Came_to_save_new.log');
        //     $this->di->getLog()->logContent('DEBUG_BACKTRACE_IGNORE_ARGS: ' . json_encode($trace), 'info', 'Came_to_save_new.log');
        //     $this->di->getLog()->logContent('marketplaceSaveAndUpdate' . json_encode($data), 'info', 'Came_to_save_new.log');
        //     $this->di->getLog()->logContent('allSourceProductIdsData' . json_encode($allSourceProductIdsData), 'info', 'Came_to_save_new.log');
        //     $this->di->getLog()->logContent('containerIdExists' . json_encode($containerIdExists), 'info', 'Came_to_save_new.log');
        // //     $response =  $this->di->getObjectManager()->get('App\Connector\Models\Product\MarketplaceV2')
        // //     ->marketplaceSaveAndUpdate($data, $allSourceProductIdsData);
        // //     $this->di->getLog()->logContent('marketplaceSaveAndUpdate response: ' . json_encode($response), 'info', 'Came_to_save.log');
        // //     return true;
        // }

        $allStart = microtime(true);
        $isDev = $this->di->has('isDev') && $this->di->get('isDev');
        if ($isDev) {
            $this->di->getLog()->logContent('marketplace data ' . print_r($data, true), 'info', 'marketplaceDataCheck.log');
        }

        if (!$this->isSequentialArray($data)) {
            $data = [$data];
        }

        $this->init($data[0]);

        $countTotalDef = count($data);

        $timeStamp = time();

        $start = microtime(true);
        $this->getAllSourceProductIds($data, $allSourceProductIdsData);
        $end = microtime(true);

        if ($end - $start > 1 && $isDev) {
            $this->di->getLog()->logContent('getAllSourceProductIds = ' . print_r(['time' => $end - $start, 'total_count' => $countTotalDef], true), 'info', 'marketplaceDataTime.log');
        }

        $start = microtime(true);

        if ($this->_sourceShopId) {
            $this->_allTargetIds = $this->getAllTargetIds(['source' => ['shopId' => $this->_sourceShopId]], true);
        }
        $this->_targetUpdates = $this->di->getObjectManager()->get('\App\Connector\Models\Product\TargetUpdates');

        foreach ($data as $k => $v) {
            $key = $this->getKey($v);
            if (isset($this->_allProductData[$key])) {
                $this->processMarketplaceSaveAndUpdate($v, $this->_allProductData[$key]);
            }
        }

        $end = microtime(true);

        if ($end - $start > 1 && $isDev) {
            $this->di->getLog()->logContent('processMarketplaceSaveAndUpdate = ' . print_r(['time' => $end - $start, 'total_count' => $countTotalDef], true), 'info', 'marketplaceDataTime.log');
        }


        // $bulkOpArray = array_values($this->_productsUpdates);

        $start = microtime(true);

        // $r = $this->executeBulk($this->_mongo, $bulkOpArray);
        // $r = false; //$this->executeBulk($this->_mongo, $bulkOpArray);

        $end = microtime(true);

        if ($end - $start > 1 && $isDev) {
            $this->di->getLog()->logContent('product_container bulk = ' . print_r(['time' => $end - $start, 'total_count' => $countTotalDef, 'total_bulk' => count($bulkOpArray)], true), 'info', 'marketplaceDataTime.log');
        }

        $refineBulkOP = [];

        foreach ($this->_updateRefineProduct as $v) {
            foreach ($v as $item) {
                $refineBulkOP[] = $item;
            }
        }

        if ($isDev) {
            $this->di->getLog()->logContent('marketplace data ' . print_r($refineBulkOP, true), 'info', 'refineBulkOp.log');
        }

        $start = microtime(true);
        // echo json_encode($refineBulkOP);die;

        // if ( $this->di->getUser()->id == "686646048a3e9c4b890c5e82" ) {
        //     print_r($refineBulkOP);die;
        // }

        // if ( $this->di->getUser()->id == "6726600aa77bc7b5cd0ca574" ) {
        //     print_r($refineBulkOP);die('refineBulkOP');
        //     // $this->di->getLog()->logContent('refineBulkOP' . json_encode($refineBulkOP), 'info', 'Came_to_save_new.log');
        // }

        $res = $this->executeBulk($this->_refineProductsTable, $refineBulkOP);

        $this->_db_queries = $this->_db_queries + 1;

        $end = microtime(true);

        if ($end - $start > 1 && $isDev) {
            $this->di->getLog()->logContent('refine_product_comtainer bulk = ' . print_r(['time' => $end - $start, 'total_count' => $countTotalDef, 'total_bulk' => count($refineBulkOP)], true), 'info', 'marketplaceDataTime.log');
        }

        $allEnd = microtime(true);

        if ($allEnd - $allStart > 1 && $isDev) {
            $this->di->getLog()->logContent('marketplace upadte total time turantUPdate =' . print_r(['user_id' => $this->_userId, 'time' => $allEnd - $allStart, 'total_count' => $countTotalDef, 'timestamp' => $timeStamp], true), 'info', 'marketplaceDataTime.log');
        }
        // if ( $this->di->getUser()->id == "6726600aa77bc7b5cd0ca574" ) {
        //     // print_r($refineBulkOP);die;
        //     $this->di->getLog()->logContent('refineBulkOP' . json_encode([
        //         'success' => true,
        //         'errors' => $this->_errors,
        //         'bulk_info' => [
        //             'product_container' => $r,
        //             'refine_product' => $res
        //         ]
        //     ]), 'info', 'Came_to_save_new.log');
        // }

        return [
            'success' => true,
            'errors' => $this->_errors,
            'db_queries' => $this->_db_queries,
            'bulk_info' => [
                'product_container' => 0, // $r, we are not executing bulk for product container now
                'refine_product' => $res
            ]
        ];
    }

    public function marketplaceDelete($data)
    {
        return(new \App\Connector\Models\Product\MarketplaceHelper\Delete)->initiate($data);
    }

    public function marketplaceUnsetBulk($data)
    {
        if (!isset($data['user_id'], $data['source_shop_id'], $data['target_shop_id'], $data['unset_key'])) {
            return ['success' => false, 'message' => 'user_id, source_shop_id,target_shop_id or unset_key missing', 'code' => 'data_missing'];
        }

        $this->init($data);

        $product_container = $this->_mongo->updateMany(
            ['user_id' => $this->_userId, 'shop_id' => $data['target_shop_id'], 'source_shop_id' => $data['source_shop_id']],
            ['$unset' => [$data['unset_key'] => 1]]
        );

        $this->_db_queries = $this->_db_queries + 1;

        $product_container_marketplace = $this->_mongo->updateMany(
            [
                'user_id' => $this->_userId,
                'shop_id' => $data['source_shop_id'],
                'marketplace.shop_id' => $data['target_shop_id']
            ],
            [
                '$unset' => [
                    'marketplace.$[item].' . $data['unset_key'] => 1
                ]
            ],
            [
                'arrayFilters' => [
                    [
                        'item.shop_id' => $data['target_shop_id']
                    ]
                ]
            ]
        );

        $this->_db_queries = $this->_db_queries + 1;

        $refine_product = $this->_refineProductsTable->updateMany(
            [
                'user_id' => $this->_userId,
                'source_shop_id' => $data['source_shop_id'],
                'target_shop_id' => $data['target_shop_id'],
                'items.shop_id' => $data['target_shop_id']
            ],
            [
                '$unset' => [
                    'items.$[item].' . $data['unset_key'] => 1
                ]
            ],
            [
                'arrayFilters' => [
                    [
                        'item.shop_id' => $data['target_shop_id']
                    ]
                ]
            ]
        );

        if (isset($data['syncRefine']) && $data['syncRefine']) {
            $this->createRefinProducts(['source' => ['shopId' => $data['source_shop_id'], 'marketplace' => $data['source_marketplace']], 'target' => ['shopId' => $data['target_shop_id'], 'marketplace' => $data['target_marketplace']], 'user_id' => $data['user_id']]);
        }

        return ['refine_bulk_info' => $refine_product, 'product_container_edited_bulk_info' => $product_container, 'product_container_bulk_info' => $product_container_marketplace];
    }

    public function executeBulk($mongo, $bulk)
    {
        $this->_db_queries = $this->_db_queries + 1;

        if (count($bulk) != 0) {
            return $mongo->BulkWrite($bulk);
        }

        return false;
    }


    public function processMarketplaceSaveAndUpdate($data, $dbData = false)
    {
        if (!$dbData) {
            $this->_db_queries = $this->_db_queries + 1;
            $dbData = $this->_mongo->findOne([
                'source_product_id' => $data['source_product_id'],
                'user_id' => $this->_userId
            ]);
        }

        $dbData = json_decode(json_encode($dbData), true);
        $validate = $this->validateData($data);

        if (isset($validate['success']) && $validate['success'] === false) {
            $this->_errors[] = [
                'source_product_id' => $validate
            ];
            return $validate;
        }

        if ($this->isArray($dbData) && count($dbData) > 0) {
            if (isset($dbData[$this->_childKey])) {
                $getQuery = $this->updateChild($data, $dbData);
            } else {
                $getQuery = $this->insertChild($data, $dbData);
            }

            $this->checkForParent($data, $dbData);
        } else {
            return [
                'success' => false,
                'message' => 'Product Not Found!!',
            ];
        }

        return [
            'success' => true,
            'query' => $getQuery
        ];
    }

    public function validateData($data)
    {
        $childInfo = $data['childInfo'];
        if ($this->_containerIdExists && (!isset($data['container_id']) || strlen($data['container_id']) == 0)) {
            return ['success' => false, 'message' => 'container_id is missing or wrong'];
        }

        if (!isset($childInfo['source_product_id']) || !isset($childInfo['shop_id'])) {
            return ['success' => false, 'message' => 'source_product_id or shop_id is missing'];
        }

        if (!isset($childInfo['source_marketplace']) && !isset($childInfo['target_marketplace'])) {
            return ['success' => false, 'message' => 'source or target marketplace must be define'];
        }

        return ['success' => true];
    }

    public function checkForParent($data, $dbData)
    {
        $key = $this->_containerIdExists ? $dbData['container_id'] . '_' . $dbData['container_id'] : $dbData['container_id'];

        if ($this->isChildOfProduct($dbData)) {
            if (isset($this->_allProductData[$key])) {
                $dbData = $this->_allProductData[$key];
            } else {
                $dbData = [];
            }

            $dbData = json_decode(json_encode($dbData), true);

            if ($this->isArray($dbData) && count($dbData) > 0) {
                $data['source_product_id'] = $dbData['source_product_id'];
                if (isset($dbData[$this->_childKey])) {
                    $this->updateChild($data, $dbData);
                } else {
                    $this->insertChild($data, $dbData);
                }
            }
        }

        return true;
    }

    public function insertChild($var, $dbData)
    {
        $dbData[$this->_childKey] = [];
        if (isset($var['childInfo']['target_marketplace'])) {
            $var['childInfo']['direct'] = false;
        }

        $dbData[$this->_childKey][] = $var['childInfo'];

        $this->updateGlobalDetails($dbData, $var);

        if ($dbData['visibility'] == 'Catalog and Search') {
            $this->updateRefindProducts($var, $dbData);
        }
        return true;
    }

    public function updateChild($var, $dbData)
    {
        $childInfo = $var['childInfo'];
        // $dbData = json_decode(json_encode($dbData), true);

        $flag = true;
        foreach ($dbData[$this->_childKey] as $key => $value) {
            if (isset($value['source_product_id'])) {
                $Tkey = isset($value['source_marketplace']) ? 'source_marketplace' : 'target_marketplace';
                $childInfoKey = isset($childInfo['source_marketplace']) ? 'source_marketplace' : 'target_marketplace';
                if ($childInfo['source_product_id'] === $value['source_product_id']) {
                    if ($childInfo['shop_id'] === $value['shop_id'] && $Tkey == $childInfoKey) {
                        $flag = false;
                        $dbData[$this->_childKey][$key] = $childInfo + $dbData[$this->_childKey][$key];

                        if (isset($var['unset'])) {
                            $dbData[$this->_childKey][$key] = $this->unsetData($dbData[$this->_childKey][$key], $var['unset']);
                        }
                    }
                }
            }
        }

        if ($flag) {
            $dbData[$this->_childKey][] = $var['childInfo'];
        }

        $this->updateGlobalDetails($dbData, $var);

        if ($dbData['visibility'] == 'Catalog and Search') {
            $this->updateRefindProducts($var, $dbData);
        }

        return true;
    }

    public function isArray($dbData)
    {
        return is_object($dbData) || is_array($dbData);
    }

    public function unsetData($marketplace, $unsetArray)
    {
        foreach ($unsetArray as $unsetValue) {
            if (isset($marketplace[$unsetValue])) {
                unset($marketplace[$unsetValue]);
            }
        }

        return $marketplace;
    }

    public function validateDataDuplicate($data)
    {
        return isset($data['source']['marketplace']) && isset($data['user_id']) && isset($data['source']['shopId']) && isset($data['target']['marketplace']) && isset($data['target']['shopId']);
    }

    public function syncRefineProducts($params)
    {
        if (!(isset($params['source']['marketplace']) && isset($params['user_id']) && isset($params['source']['shopId']))) {
            return ['success' => false, 'message' => 'data missing'];
        }

        $this->init($params);
        $allTargets = $this->getAllTargetIds($params);

        $this->_db_queries = $this->_db_queries + 1;
        $productCount = $this->_mongo->count(['user_id' => $this->_userId, 'shop_id' => $params['source']['shopId'], 'visibility' => 'Catalog and Search']);

        foreach ($allTargets as $v) {
            $refineTargetCount = $this->_refineProductsTable->count(['user_id' => $this->_userId, 'source_shop_id' => $params['source']['shopId'], 'target_shop_id' => $v['shopId']]);

            if ($productCount > $refineTargetCount) {
                $paramsTosend = $params + ['target' => $v];

                $this->di->getLog()->logContent('syncRefineProductsTriggered = ' . print_r(['user_id' => $this->_userId, 'source_shop_id' => $params['source']['shopId'], 'target_shop_id' => $v['shopId'], 'productCount' => $productCount, 'refineTargetCount' => $refineTargetCount], true), 'info', 'syncRefineProductsTriggered.log');

                $this->createRefinProducts($paramsTosend);
            }
        }
    }

    public function createRefinProducts($params)
    {
        if (!($this->validateDataDuplicate($params))) {
            return ['success' => false, 'message' => 'data missing'];
        }

        $newData = [
            'user_id' => $params['user_id'],
            'class_name' => '\App\Connector\Models\Product\Marketplace',
            'method_name' => 'createRefinProductsSQS',
            'params' => $params,
            'worker_name' => 'create_refine_product',
        ];
        $SqsObject = new \App\Connector\Components\Profile\SQSWorker;

        $SqsObject->CreateWorker($newData, false);
    }

    public function createRefinProductsSQS($data)
    {
        $params = $data['data']['params'];
        $isDev = $this->di->has('isDev') && $this->di->get('isDev');

        if (!($this->validateDataDuplicate($params))) {
            return ['success' => false, 'message' => 'data missing'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $feedServerSide = $mongo->getCollectionForTable('feed_server_side');

        $extraKeys = $this->di->getConfig()->get('refine_additional_keys')->toArray();

        $this->di->getLog()->logContent('data in db : ' . json_encode(['data' => $params, 'config' => $extraKeys]),'info', 'checkupdatetarget.js.log');

        $infoR = $feedServerSide->insertOne(['data' => $params, 'config' => $extraKeys]);

        $id = (string) $infoR->getInsertedId();

        $idTo = "'{$id}'";

        $mongoInfo = $this->di->getConfig()->get('databases')['db_mongo'];

        $host = $mongoInfo['host_server_side'];

        $username = $mongoInfo['username'];

        $password = $mongoInfo['password'];

        $basePath = BP . DS . 'app/code/connector/Models/Product/UpdateTarget.js';

        $eval = '"var id = ' . $idTo . ' " ';

        $createCommand = "mongosh " . $host . ' --username ' . $username . ' -p ' . $password . ' --eval ' . $eval . $basePath;
        // $this->di->getLog()->logContent('$createCommand in db : ' . ),'info', 'checkupdatetarget.js.log');

        $start = microtime(true);
        $execute = shell_exec($createCommand);
        $end = microtime(true);


        if ($end - $start > 1 && $isDev) {
            $this->di->getLog()->logContent('refineTablecreateForallProduct = ' . json_encode((['timeStamp' => time(), 'time' => $end - $start, 'user_id' => $params['user_id']]), true), 'info', 'bulktimeTest.log');
        }

        return $execute;
    }

    public function appendContainerId($data)
    {
        return isset($data['container_id']) ? ['container_id' => (string) $data['container_id']] : [];
    }

    public function updateGlobalDetails($data, $var): void
    {
        $key = $this->getKey($data);
        $this->_allProductData[$key] = $data;
        $this->_productsUpdates[$key] = [
            'updateMany' => [
                [
                    'source_product_id' => $var['source_product_id'],
                    'user_id' => $this->_userId,
                    'shop_id' => $data['shop_id']
                ] + $this->appendContainerId($var),
                [
                    '$set' => [
                        $this->_childKey => $data[$this->_childKey]
                    ]
                ]
            ]
        ];
    }

    public function isChildOfProduct($data)
    {
        return($data['type'] === "simple"
            && $data['visibility'] === "Not Visible Individually"
            && isset($data['container_id'])
        ) ? true : false;
    }

    public function isSequentialArray($arr)
    {
        return array_keys($arr) == range(0, count($arr) - 1);
    }

    public function updateRefindProducts($var, $dbData): void
    {
        $childInfo = $var['childInfo'];
        $targetUPdates = $this->_targetUpdates;
        if (isset($childInfo['source_marketplace'])) {
            $valueFormatted = $this->prepareDataTosendForAllTargtes($var);


            $allTargetIds = $this->_allTargetIds ? $this->_allTargetIds : $this->getAllTargetIds($valueFormatted, true);

            $getUpdatedMarketplace = $targetUPdates
                ->updateTargetForNewProduct($valueFormatted, $dbData, true, $this->_mongo, $allTargetIds);
            $this->_db_queries = $this->_db_queries + 1;
        } else if (isset($childInfo['target_marketplace'])) {
            $dataTosend = [
                'shopId' => $childInfo['shop_id'],
                'marketplace' => $childInfo['target_marketplace'],
                'user_id' => $this->_userId
            ];
            $getUpdatedMarketplace = $targetUPdates->getFormattedForSingleTarget($dbData, $dataTosend, $this->_mongo);
            $this->_db_queries = $this->_db_queries + 1;
        }

        if ($this->di->has('isDev') && $this->di->get('isDev')) {
            $this->di->getLog()->logContent('marketplace data ' . print_r($getUpdatedMarketplace, true), 'info', 'refineResponse.log');
        }


        if ($getUpdatedMarketplace['success']) {
            $this->_updateRefineProduct[$dbData['source_product_id']] = $getUpdatedMarketplace['data'];
        }
    }

    public function getFilterData($dbData, $unsetShopId = false)
    {
        $filters = $this->di->getConfig()->allowed_filters ? $this->di->getConfig()->allowed_filters->toArray() : [];
        $filters[] = 'target_marketplace';
        $arr = [];
        foreach ($filters as $value) {
            if (isset($dbData[$value])) {
                $arr[$value] = $dbData[$value];
            }
        }

        if ($unsetShopId) {
            unset($arr['shop_id']);
        }

        return $arr;
    }

    public function getEditedDocument($dbData, $childInfo)
    {
        $source_product_id = $childInfo['source_product_id'];
        $allEdited = $this->_mongo->find(['source_product_id' => $source_product_id, 'target_marketplace' => ['$exists' => true]])->toArray();
        $this->_db_queries = $this->_db_queries + 1;
        $shopIdWiseFormatted = [];
        foreach ($allEdited as $v) {
            $shopIdWiseFormatted[$v['shop_id']] = $this->getFilterData($v) + $this->getFilterData($childInfo + $dbData);
        }

        return $shopIdWiseFormatted;
    }

    public function prepareDataTosendForAllTargtes($data)
    {
        return [
            'source' => [
                'marketplace' => $data['childInfo']['source_marketplace'],
                'shopId' => $data['childInfo']['shop_id']
            ],
            'source_product_id' => $data['source_product_id'],
            'user_id' => $this->_userId
        ];
    }


    public function appendCheck()
    {
        return  $this->_sourceShopId ? ['shop_id' => $this->_sourceShopId] : ['source_marketplace' => ['$exists' => true]];
    }

    public function getProducts($allSourceProductIds, $useContainerId = false)
    {
        $allowedFilter =  $this->di->getConfig()->get('allowed_filters')->toArray();

        $extraKeys = $this->di->getConfig()->get('refine_additional_keys')->toArray() + $allowedFilter;

        $extraProjection = [];
        foreach ($extraKeys as $v) {
            $extraProjection[$v] = 1;
        }
        $targetIds = [];
        if ($this->_sourceShopId && $this->_allTargetIds && is_array($this->_allTargetIds)) {
            foreach ($this->_allTargetIds as $targeInfo) {
                $targetIds[] = $targeInfo['shopId'];
            }
        } else if ($this->_targetShopId) {
            $targetIds[] = $this->_targetShopId;
        } else if (!empty($this->_allTargetIds)) {
            #discuss with Anshuman sir
            foreach ($this->_allTargetIds as $targeInfo) {
                $targetIds[] = $targeInfo['shopId'];
            }
        }

        if ($useContainerId) {
            $findQuery = [
                'user_id' => $this->_userId,
                'container_id' => ['$in' => $allSourceProductIds]
            ] + $this->appendCheck();
        } else {
            $findQuery = [
                'user_id' => $this->_userId,
                'source_product_id' => ['$in' => $allSourceProductIds]
            ] + $this->appendCheck();
        }
        
        $idToMatch = [ '$eq' => [ '$container_id', '$$srcContainerId' ] ];

        $project = [
            'source_product_id' => 1,
            'container_id' => 1,
            'type' => 1,
            'visibility' => 1,
            'tags' => 1,
            'asin' => 1,
            'variant_attributes' => 1,
            'source_marketplace' => 1,
            'shop_id' => 1,
            'source_product_id'    => 1,
            'title'                => 1,
            'variant_title'        => 1,
            'sku'                  => 1,
            'quantity'             => 1,
            'price'                => 1,
            'main_image'           => 1,
            // 'status' => 1,
            'shopify_sku' => 1,
            'barcode' => 1,
            'barcode_type' => 1,
            'inventory_tracked'    => 1,
            'inventory_policy'     => 1,
            'inventory_management' => 1,
            'source_marketplace'   => 1,
            'status' => 1,
        ] + $extraProjection;
        $ssID = [];
        if ( !empty($targetIds) ) {
            $ssID = [
                '$match' => [
                    '$expr' => [
                        '$and' => [
                            [ '$eq' => [ '$user_id', $this->_userId ] ],               // static user_id variable
                            [
                            '$or' => [
                                    [ '$eq' => [ '$shop_id', $this->_sourceShopId ] ],  // single shop_id
                                    [ '$in' => [ '$shop_id', $targetIds ] ]             // shop_id in array
                                ]
                            ],
                            $idToMatch
                        ]
                    ]
                ]
            ];
        } else {
           $ssID = [
                '$match' => [
                    '$expr' => [
                        '$and' => [
                            [ '$eq' => [ '$user_id', $this->_userId ] ],               // static user_id variable
                            $idToMatch
                        ]
                    ]
                ]
            ];
        }

        $lookup = [
            'from' => 'product_container',
            'let' => [
                'srcContainerId' => '$container_id',
                'srcSourceId' => '$source_product_id',
                'srcVisibility' => '$visibility'
            ],
            'pipeline' => [
                [
                    '$match' => [
                        '$expr' => [
                            '$and' => [
                                [
                                    '$eq' => [ '$user_id', $this->_userId]
                                ],
                                [
                                    '$cond' => [
                                        ['$eq' => ['$$srcVisibility', 'Catalog and Search']], 
                                        ['$eq' => ['$container_id', '$$srcContainerId']], 
                                        ['$eq' => ['$source_product_id', '$$srcSourceId']]]
                                ]
                            ]
                        ]
                    ]
                ],
                ['$limit' => 500],
                ['$project' => $project]
            ],
            'as' => 'marketplacev2'
        ];

        

        $project['marketplace'] = '$marketplacev2';

        $aggregatepipeline = [
            ['$match' => $findQuery],
            ['$lookup' => $lookup],
            ['$project' => $project]
        ];

        $dbData = $this->_mongo->aggregate($aggregatepipeline, ['typeMap'   => ['root' => 'array', 'document' => 'array']])->toArray();
        $this->_db_queries = $this->_db_queries + 1;

        foreach ($dbData as $k => $v) {
            if (isset($v['marketplace']) && is_array($v['marketplace']) && count($v['marketplace']) > 0) {
                // if marketplace key is greater than 499, then fetch it from product_container db as their can be more data
                if ( count($v['marketplace']) > 499 ) {
                    $aggregatepipeline = [
                        ['$match' => [
                            'user_id' => $this->_userId,
                            'container_id' => $v['container_id']
                        ]],
                        ['$project' => $project]
                    ];
                    $liveData = $this->_mongo->aggregate($aggregatepipeline, [
                        'typeMap'   => ['root' => 'array', 'document' => 'array']])
                        ->toArray();
                    $this->_db_queries = $this->_db_queries + 1;
                    $dbData[$k]['marketplace'] = $liveData;
                }

                foreach ($dbData[$k]['marketplace'] as $mk => $mv) {
                    if (isset($mv['source_marketplace']) && $mv['source_marketplace'] == $this->_sourceMarketplace) {
                        $dbData[$k]['marketplace'][$mk]['direct'] = true;
                    }
                }
            }
        }
        
        return $dbData;
    }

    public function getAllSourceProductIds($data, $allSourceProductIdsData = false)
    {

        if ($allSourceProductIdsData) {
            $allProducts = $this->getProducts($allSourceProductIdsData, true);
            foreach ($allProducts as $v) {
                $key = $this->getKey($v);
                $this->_allProductData[$key] = $v;
            }
        } else {
            $allSourceProductIds = [];

            foreach ($data as $k => $v) {
                $allSourceProductIds[] = $v['source_product_id'];
            }

            $allParentProductSourceIds = [];

            $allProducts = $this->getProducts($allSourceProductIds);

            foreach ($allProducts as $k => $v) {
                $key = $this->getKey($v);
                $this->_allProductData[$key] = $v;
                if ($this->isChildOfProduct($v)) {
                    $allParentProductSourceIds[] = $v['container_id'];
                }
            }

            if ( !empty($allParentProductSourceIds) ) {
                $allParentProducts = $this->getProducts($allParentProductSourceIds);

                foreach ($allParentProducts as $v) {
                    $key = $this->getKey($v);
                    $this->_allProductData[$key] = $v;
                }
            }

            
        }

        return  $this->_allProductData;
    }

    public function getAllTargetIds($data, $useCache = false)
    {

        if ($this->_sourceShopId) {
            $sourceId = $this->_sourceShopId;
        } else {
            $sourceId = $data['source']['shopId'];
        }

        if ($useCache) {
            $userShops = $this->di->getUser()->shops;
        } else {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('user_details');
            $user = $collection->find(['user_id' => $this->_userId])->toArray()[0];
            $userShops = $user['shops'];
        }

        $allTargetIds = [];

        foreach ($userShops as $v) {
            if ($v['_id'] == $sourceId) {
                if (isset($v['targets'])) {
                    foreach ($v['targets'] as $vT) {
                        if (!$this->validateRefineAbortProcess($vT['code'] ?? $vT['marketplace'])) {
                            $allTargetIds[] = [
                                'marketplace' => $vT['code'] ?? $vT['marketplace'],
                                'shopId' => $vT['shop_id']
                            ];
                        }
                    }
                }

                break;
            }
        }

        return $allTargetIds;
    }

    /**
     * @return boolean value for source_target marketplace exists
     */
    public function validateRefineAbortProcess($targetMarketplace = '')
    {
        $data = $this->_sourceMarketplace . '_' . $targetMarketplace;
        if (in_array($data, $this->abortRefineSyncingForMarketplacesArr)) {
            return true;
        }
        return false;
    }

    public function getKey($data, $useAllContainerId = false)
    {
        return $this->_containerIdExists ? $data['source_product_id'] . '_' . $data['container_id'] : $data['source_product_id'];
    }

    /**
     * $data = ['source_product_id', 'shop_id', 'user_id']
     */
    public function getSingleProduct($data)
    {
        $this->init($data);

        $sourceProduct = $this->_mongo->findOne([
            'source_product_id' => $data['source_product_id'],
            'user_id' => $this->_userId,
            'shop_id' => $data['source_shop_id'],
        ]);
        $this->_db_queries = $this->_db_queries + 1;
        $targetProduct = $this->_mongo->findOne([
            'source_product_id' => $data['source_product_id'],
            'shop_id' => $data['target_shop_id'],
            'user_id' => $this->_userId
        ]);
        $this->_db_queries = $this->_db_queries + 1;

        $sourceProduct = json_decode(json_encode($sourceProduct), true);
        $sourceProduct = !is_null($sourceProduct) ? $sourceProduct : [];

        $targetProduct = json_decode(json_encode($targetProduct), true);
        $targetProduct = !is_null($targetProduct) ? $targetProduct : [];

        return $targetProduct + $sourceProduct;
    }

    /**
     * @param $data
     * @return array|mixed
     * $data = ['source_product_id', 'shop_id', 'user_id']
     */
    public function getProductBySku($data)
    {
        $this->init($data);
        $sourceExist = false;
        if (isset($data['source_shop_id'])) {

            $sourceProduct = $this->_mongo->findOne([
                'user_id' => $this->_userId,
                'shop_id' => $data['source_shop_id'],
                'sku' => $data['sku']
            ]);
            $this->_db_queries = $this->_db_queries + 1;
            $sourceProduct = json_decode(json_encode($sourceProduct), true);
            $sourceProduct = !is_null($sourceProduct) ? $sourceProduct : [];
            if (!empty($sourceProduct)) {
                $sourceExist = true;
            }
        } else {
            $sourceProduct = [];
        }


        if (!empty($sourceProduct)) {
            $sourceProductId = $sourceProduct['source_product_id'];
            if (isset($data['target_shop_id'])) {
                $targetProduct = $this->_mongo->findOne([
                    'user_id' => $this->_userId,
                    'shop_id' => $data['target_shop_id'],
                    'source_product_id' => $sourceProductId

                ]);
                $this->_db_queries = $this->_db_queries + 1;
                $targetProduct = json_decode(json_encode($targetProduct), true);
                $targetProduct = !is_null($targetProduct) ? $targetProduct : [];

                if (!empty($targetProduct)) {
                    $sourceProduct['source_shop_id'] = $data['source_shop_id'];
                    $sourceProduct['target_shop_id'] = $data['target_shop_id'];
                    $newProduct = ($targetProduct ?? []) + ($sourceProduct ?? []);
                    return $newProduct;
                }
                return [];
            }
            $sourceProduct['source_shop_id'] = $data['source_shop_id'];
            return $sourceProduct;
        }
        if (isset($data['target_shop_id'])) {
            $targetProduct = $this->_mongo->findOne([
                'user_id' => $this->_userId,
                'shop_id' => $data['target_shop_id'],
                'sku' => $data['sku']

            ]);
            $this->_db_queries = $this->_db_queries + 1;


            $targetProduct = json_decode(json_encode($targetProduct), true);
            $targetProduct = !is_null($targetProduct) ? $targetProduct : [];

            if (!empty($targetProduct)) {
                $sourceProductId = $targetProduct['source_product_id'];
                if ($sourceExist) {
                    $sourceProduct = $this->_mongo->findOne([
                        'user_id' => $this->_userId,
                        'shop_id' => $data['source_shop_id'],
                        'source_product_id' => $sourceProductId,

                    ]);
                    $this->_db_queries = $this->_db_queries + 1;
                    $sourceProduct = json_decode(json_encode($sourceProduct), true);
                    $sourceProduct = !is_null($sourceProduct) ? $sourceProduct : [];
                    if (!empty($sourceProduct)) {
                        $sourceProduct['source_shop_id'] = $data['source_shop_id'];
                        $sourceProduct['target_shop_id'] = $data['target_shop_id'];
                        $newProduct = ($targetProduct ?? []) + ($sourceProduct ?? []);
                        return $newProduct;
                    }
                    return [];
                }
                $targetProduct['target_shop_id'] = $data['target_shop_id'];
                return $targetProduct;
            }
            return [];
        }
        return $sourceProduct;
    }

    public function getMultipleProductBySku($data)
    {
        $this->init($data);
        $sourceProduct = $this->_mongo->find([
            'sku' => $data['sku'],
            'shop_id' => $data['source_shop_id'],
            'user_id' => $this->_userId
        ])->toArray();
        $this->_db_queries = $this->_db_queries + 1;
        $targetProduct = $this->_mongo->find([
            'sku' => $data['sku'],
            'shop_id' => $data['target_shop_id'],
            'user_id' => $this->_userId
        ])->toArray();
        $this->_db_queries = $this->_db_queries + 1;
        $sourceProduct = json_decode(json_encode($sourceProduct), true);
        $sourceProduct = !is_null($sourceProduct) ? $sourceProduct : [];

        $targetProduct = json_decode(json_encode($targetProduct), true);
        $targetProduct = !is_null($targetProduct) ? $targetProduct : [];

        $newProduct = ($targetProduct ?? []) + ($sourceProduct ?? []);

        return $newProduct;
    }

    public function fetchMultipleProductBySku($data)
    {
        $this->init($data);
        $sourceParams = [
            'source_sku' => $data['sku'],
            'shop_id' => $data['source_shop_id'],
            'user_id' => $this->_userId,
        ];

        if (isset($data['source_marketplace'])) {
            $sourceParams['source_marketplace'] = $data['source_marketplace'];
        }

        $sourceProduct = $this->_mongo->find($sourceParams)->toArray();
        $this->_db_queries = $this->_db_queries + 1;
        $targetParams = [
            'source_sku' => $data['sku'],
            'shop_id' => $data['target_shop_id'],
            'user_id' => $this->_userId
        ];

        if (isset($data['source_marketplace'])) {
            $targetParams['source_marketplace'] = $data['source_marketplace'];
        }

        $targetProduct = $this->_mongo->find($targetParams)->toArray();
        $this->_db_queries = $this->_db_queries + 1;
        $sourceProduct = json_decode(json_encode($sourceProduct), true);
        $sourceProduct = !is_null($sourceProduct) ? $sourceProduct : [];

        $targetProduct = json_decode(json_encode($targetProduct), true);
        $targetProduct = !is_null($targetProduct) ? $targetProduct : [];

        $newProduct = ($targetProduct ?? []) + ($sourceProduct ?? []);

        return $newProduct;
    }

    public function getproductbyQuery($query, $option = [])
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_mongo = $mongo->getCollectionForTable('product_container');
        $productData = $this->_mongo->find($query, $option)->toArray();
        $this->_db_queries = $this->_db_queries + 1;
        return $productData;
    }

    public function updateproductbyQuery($filter, $option)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_mongo = $mongo->getCollectionForTable('product_container');
        $this->_mongo->updateOne($filter, $option);
        $this->_db_queries = $this->_db_queries + 1;
        return true;
    }

    public function getproductCountbyQuery($query)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_mongo = $mongo->getCollectionForTable('product_container');
        $productCount = $this->_mongo->count($query);
        $this->_db_queries = $this->_db_queries + 1;
        return $productCount;
    }
}
