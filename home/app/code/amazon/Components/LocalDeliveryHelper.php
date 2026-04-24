<?php

namespace App\Amazon\Components;

use App\Connector\Models\Product\MarketplaceHelper\Delete;
use App\Amazon\Components\Product\Product;
use App\Amazon\Components\Feed\Feed;
use App\Connector\Components\Profile\SQSWorker;
use App\Core\Models\Base;
// use App\Core\Components\Base;
use App\Amazon\Components\Common\Helper;


class LocalDeliveryHelper extends Base
{
    public $_mongo;

    public $_refineProductsTable;

    public $_userId;


    public $_productsUpdates = [];

    public $_allProductData = [];

    public $_updateRefineProduct = [];

    public $_sourceShopId = false;

    public $_allTargetIds = false;

    public $_errors = [];

    public $_containerIdExists = false;

    // in case, we decided to change it in future
    public $_childKey = 'marketplace';

    public $_sourceMarketplace;

    public function init($data): void
    {
        $this->_productsUpdates = [];
        $this->_allProductData = [];
        $this->_updateRefineProduct = [];
        $this->_errors = [];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_mongo = $mongo->getCollectionForTable('amazon_local_delivery_product_listing');
        $this->_refineProductsTable = $mongo->getCollectionForTable('amazon_local_delivery_refine_product');

        if (isset($data['childInfo']['source_marketplace']) || isset($data['source_marketplace'])) {
            $this->_sourceMarketplace = $data['childInfo']['source_marketplace'] ?? $data['source_marketplace'];
            $sourceM = $this->di->getConfig()->connectors->toArray();

            $this->_containerIdExists = $sourceM[$this->_sourceMarketplace]['useContainerId'] ?? false;
        }

        if (isset($data['user_id'])) {
            $this->_userId = $data['user_id'];
        } else {
            $this->_userId = $this->di->getUser()->id;
        }

        if (isset($data['source_shop_id'])) {
            $this->_sourceShopId = $data['source_shop_id'];
        }
    }

    public function testRecieved($params)
    {
        print_r($params);
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
        $this->di->getLog()->logContent('marketplace data ' . print_r($data, true), 'info', 'marketplaceDataCheck.log');                                                                                                                                                                                                                                                                                                                
        $allStart = microtime(true);
        if ($this->di->has('isDev') && $this->di->get('isDev')) {
            // $this->di->getLog()->logContent('marketplace data ' . print_r($data, true), 'info', 'marketplaceDataCheck.log');
        }

        if (!$this->isSequentialArray($data)) {
            $data = [$data];
        }

        $this->init($data[0]);

        $countTotalDef =  count($data);

        $timeStamp = time();

        $start = microtime(true);
        $this->getAllSourceProductIds($data, $allSourceProductIdsData);
        $end = microtime(true);

        if ($end - $start > 1) {
            $this->di->getLog()->logContent('getAllSourceProductIds = ' . print_r(['time' => $end - $start, 'total_count' => $countTotalDef], true), 'info', 'marketplaceDataTime.log');
        }

        $start = microtime(true);

        if ($this->_sourceShopId) {
            $this->_allTargetIds = $this->getAllTargetIds(['source' => ['shopId' => $this->_sourceShopId]], true);
        }

        foreach ($data as $k => $v) {
            $key = $this->getKey($v);
            if (isset($this->_allProductData[$key])) {
                $this->processMarketplaceSaveAndUpdate($v, $this->_allProductData[$key]);
            }
        }

        $end = microtime(true);

        if ($end - $start > 1) {
            $this->di->getLog()->logContent('processMarketplaceSaveAndUpdate = ' . print_r(['time' => $end - $start, 'total_count' => $countTotalDef], true), 'info', 'marketplaceDataTime.log');
        }


        $bulkOpArray = array_values($this->_productsUpdates);

        $start = microtime(true);

        $r = $this->executeBulk($this->_mongo, $bulkOpArray);

        $end = microtime(true);

        if ($end - $start > 1) {
            $this->di->getLog()->logContent('product_container bulk = ' . print_r(['time' => $end - $start, 'total_count' => $countTotalDef, 'total_bulk' => count($bulkOpArray)], true), 'info', 'marketplaceDataTime.log');
        }

        $refineBulkOP = [];

        foreach ($this->_updateRefineProduct as $v) {
            foreach($v as $val){
                $update = $val['updateOne'];
                $array_key = array_column($update, '$set');
                $new_array =  array_column($array_key, 'items');
                foreach($new_array as $val2) {
                    // if($val2['inventory_management'] == 'shopify'){
                    //     unset($new_array[$key2]);
                    // }
                //     else{
                        $val2['source_product_id'] = $val2['online_source_product_id'];

                //     }

                    $this->di->getLog()->logContent('marketplace data ' . print_r($val2, true), 'info', 'uuuu.log');
                }
            }

            $refineBulkOP = [...$refineBulkOP, ...array_values($v)];
        }

        if ($this->di->has('isDev') && $this->di->get('isDev')) {
        }

        $start = microtime(true);

        // $this->di->getLog()->logContent(print_r($refineBulkOP, true), 'info', 'executeBulk.log');
        $res = $this->executeBulk($this->_refineProductsTable, $refineBulkOP);

        $end = microtime(true);

        if ($end - $start > 1) {
            $this->di->getLog()->logContent('refine_product_comtainer bulk = ' . print_r(['time' => $end - $start, 'total_count' => $countTotalDef, 'total_bulk' => count($refineBulkOP)], true), 'info', 'marketplaceDataTime.log');
        }

        $allEnd = microtime(true);

        if ($allEnd - $allStart > 1) {
            $this->di->getLog()->logContent('marketplace upadte total time turantUPdate =' . print_r(['user_id' => $this->_userId, 'time' => $allEnd - $allStart, 'total_count' => $countTotalDef, 'timestamp' => $timeStamp], true), 'info', 'marketplaceDataTime.log');
        }

        return [
            'success' => true,
            'errors' => $this->_errors,
            'bulk_info' => [
                'amazon_local_delivery_product_listing' => $r,
                'amazon_local_delivery_refine_product' => $res
            ]
        ];
    }

    public function  marketplaceDelete($data)
    {
        return (new Delete)->initiate($data);
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
        $product_container_marketplace = $this->_mongo->updateMany(
            ['user_id' => $this->_userId, 'shop_id' => $data['source_shop_id'], 'marketplace.shop_id' => $data['target_shop_id']],
            ['$unset' => ['marketplace.$.' . $data['unset_key'] => 1]]
        );

        $refine_product = $this->_refineProductsTable->updateMany(
            ['user_id' => $this->_userId, 'source_shop_id' => $data['source_shop_id'], 'target_shop_id' => $data['target_shop_id'], 'items.shop_id' => $data['target_shop_id']],
            ['$unset' => ['items.$.' . $data['unset_key'] => 1]]
        );

        if (isset($data['syncRefine']) && $data['syncRefine']) {
            $this->createRefinProducts(['source' => ['shopId' => $data['source_shop_id'], 'marketplace' => $data['source_marketplace']], 'target' => ['shopId' => $data['target_shop_id'], 'marketplace' => $data['target_marketplace']], 'user_id' => $data['user_id']]);
        }

        return ['refine_bulk_info' => $refine_product, 'product_container_edited_bulk_info' => $product_container, 'product_container_bulk_info' => $product_container_marketplace];
    }

    public function executeBulk($mongo, $bulk)
    {
        // $this->di->getLog()->logContent(print_r($mongo, true), 'info', 'executeBulk.log');
        if (count($bulk) != 0) {
            return $mongo->BulkWrite($bulk);
        }

        return false;
    }


    public function processMarketplaceSaveAndUpdate($data, $dbData = false)
    {
        if (!$dbData) {
            $dbData = $this->_mongo->findOne([
                'source_product_id' => $data['source_product_id'],
                'user_id' => $this->_userId
            ]);
        }


        $validate = $this->validateData($data);

        if (isset($validate['success']) && $validate['success'] === false) {
            $this->_errors[] = [
                'source_product_id' => $validate
            ];
            return $validate;
        }

        if ($this->isArray($dbData) &&  count($dbData) > 0) {
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
            'success' => true, 'query' => $getQuery
        ];
    }

    public function validateData($data)
    {
        $childInfo = $data['childInfo'];
        if ($this->_containerIdExists && (!isset($data['container_id']) || strlen((string) $data['container_id']) == 0)) {
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
        $dbData = json_decode(json_encode($dbData), true);

        $flag = true;
        foreach ($dbData[$this->_childKey] as $key => $value) {
            if (isset($value['source_product_id'])) {
                if ($childInfo['source_product_id'] === $value['source_product_id']) {
                    if ($childInfo['shop_id'] === $value['shop_id']) {
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

    public function saveErrorInProduct($sourceProductIds, $type, $targetShopId, $sourceShopId, $userId, $products = [], $feed = [] , $merchantData = null): void
    {
        $productComponent = $this->di->getObjectManager()->get(Product::class);
        $errorVariantList = [];
        $bulkOpArray = [];

        if (!$targetShopId) {
            $targetShopId = $feed['specifics']['shop_id'];
        }

        if (!$type) {
            $feedType = $feed['type'];
            $type = Feed::FEED_TYPE[$feedType];
        }

        if (!$userId && isset($feed['user_id'])) {
            $userId = $feed['user_id'];
        }

        if (!$sourceShopId && isset($feed['source_shop_id'])) {
            $sourceShopId = $feed['source_shop_id'];
        }

        if(!$merchantData && isset($feed['specifics']['localDelivery']) && !empty($feed['specifics']['localDelivery']))
        {
            $merchantData = $feed['specifics']['localDelivery'];
        }

        if (!empty($sourceProductIds)) {
            $productInsert = [];
            foreach ($sourceProductIds as $sourceProductId => $errorMsg) {
                $errorVariantList[] = $sourceProductId;
                $foundProduct = null;
                $error = [];
                $alreadySavedError = [];
                $merchantId = $merchantData[$sourceProductId];
                $specifics = [
                    'source_product_id' => (string)$sourceProductId,
                    'source_shop_id' => (string)$sourceShopId,
                    'target_shop_id' => (string)$targetShopId,
                    'user_id' => (string)$userId,
                    'merchant_shipping_group' => (string)$merchantId
                ];
                $productData = $this->getSingleProductDB($specifics);
                $foundProduct = $productData;
                if ($foundProduct) {
                    $msg = "";
                    $code = "";
                    foreach ($errorMsg as $err) {
                        # code...
                        if ($feed) {
                            $errData = explode(" : ", (string) $err);
                            if (count($errData) == '2') {
                                $code = $errData[0];
                                $msg = $errData[1];
                            }
                        }
                        else
                        {
                            $msg = $productComponent->getErrorByCode($err);
                            if ($msg) {
                               $code = $err;
                            } else {
                                $code = "AmazonError001";
                                $msg = $err;
                            }
                        }

                        $error[] = [
                            'type' => $type,
                            'code' => $code,
                            'message' => $msg
                        ];
                    }

                    if (!empty($error)) {
                        $condition = [
                            // required
                            'user_id' => (string)$userId,
                            'source_shop_id' => (string)$sourceShopId,
                            'merchant_shipping_group' => (string)$merchantId,
                            'target_shop_id' => (string)$targetShopId , 
                            'items.source_product_id' => (string)$sourceProductId
                        ];
                        $newparams['items.$.error'] = $error;
                        $bulkOpArray[] = [
                            'updateOne' => [
                                (object) $condition,
                                [
                                    '$set' => (object) $newparams,
                                ],
                                ['upsert' => true]
                            ]
                        ];
                    }
                }
            }

            if (!empty($bulkOpArray)) {
                $productData = $this->getSingleProductDB([] , $bulkOpArray);
            }

            if($feed)
            {
                $ids = array_keys($merchantData);
                $sourceIds = [];
                $removeError = array_diff($ids , $sourceIds);
                $this->removeErrorQuery($removeError , $type , $targetShopId , $sourceShopId , $userId , [] , $merchantData);
                $this->removeTag($ids , false , false , false , false , false , $feed , []);
            }
        }

    }

    public function removeTag($sourceProductIds, $tags, $targetShopId, $sourceShopId, $userId, $products = [], $feed = [] , $merchantData = null): void
    {
        $date = date('d-m-Y');
        if (!$targetShopId) {
            $targetShopId = $feed['specifics']['shop_id'];
        }

        if (!$userId) {
            $userId = $feed['user_id'];
        }

        if (!$sourceShopId) {
            $sourceShopId = $feed['source_shop_id'];
        }

        if (empty($sourceProductIds)) {
            if ($feed['type'] == 'JSON_LISTINGS_FEED') {
                $ids = $feed['specifics']['ids'];
                $messageIds = [];
                foreach ($ids as $messageId) {
                    $msgs = explode('_', (string) $messageId);
                    $messageIds[$msgs[1]] = $msgs['0'];
                }

                $sourceProductIds = array_values($messageIds);
            } else {
                $sourceProductIds = $feed['specifics']['ids'];
            }
        }

        if(!$merchantData && isset($feed['specifics']['localDelivery']) && !empty($feed['specifics']['localDelivery']))
        {
            $merchantData = $feed['specifics']['localDelivery'];
        }

        $logFile = "amazon/productNotFound/{$sourceShopId}/{$date}.log";
        if (empty($tags)) {
            $feedType = $feed['type'];

            $action = Feed::FEED_TYPE[$feedType];
            if ($action == 'product') {
                $tags = [
                    Helper::PROCESS_TAG_UPLOAD_FEED,
                    Helper::PROCESS_TAG_UPDATE_FEED
                ];
            } elseif ($action == 'inventory') {
                $tags = [
                    Helper::PROCESS_TAG_INVENTORY_FEED
                ];
            } elseif ($action == 'price') {
                $tags = [
                    Helper::PROCESS_TAG_PRICE_FEED

                ];
            } elseif ($action == 'image') {
                $tags = [
                    Helper::PROCESS_TAG_IMAGE_FEED
                ];
            } elseif ($action == 'delete') {
                $tags = [
                    Helper::PROCESS_TAG_DELETE_FEED
                ];
            }
        }


        //change array of int to array of string
        $encoded = implode(',', $sourceProductIds);
        $sourceProductIds = explode(',', $encoded);
        $productInsert = [];

        if (!empty($sourceProductIds)) {
            foreach ($sourceProductIds as $sourceProductId) {
                $foundProduct = null;
                $processTag = [];
                $alreadySavedProcessTag = [];
                $merchantId = $merchantData[$sourceProductId];
                $specifics = [
                    'source_product_id' => $sourceProductId,
                    'source_shop_id' => (string)$sourceShopId,
                    'target_shop_id' => (string)$targetShopId,
                    'user_id' => (string)$userId,
                    'merchant_shipping_group' => (string)$merchantId
                ];
                $productData = $this->getSingleProductDB($specifics);
                $foundProduct = $productData;
                if ($foundProduct) {
                    if (isset($foundProduct['process_tags'])) {
                        $processTag = $foundProduct['process_tags'];
                        $alreadySavedProcessTag = $foundProduct['process_tags'];
                    }

                    if (!empty($tags) && !empty($processTag)) {
                        foreach ($tags as $tag) {
                            if (isset($processTag[$tag])) {
                                unset($processTag[$tag]);
                            }
                        }
                    }

                    if (!empty(array_diff(array_keys($alreadySavedProcessTag), array_keys($processTag)))) {
                        $condition = [
                            // required
                            'user_id' => (string)$userId,
                            'source_shop_id' => (string)$sourceShopId,
                            'merchant_shipping_group' => (string)$merchantId,
                            'target_shop_id' => (string)$targetShopId , 
                            'items.source_product_id' => $sourceProductId
                        ];
                        if (empty($processTag)) {
                            $newparams = ['items.$.process_tags' => true];
                            $bulkOpArray[] = [
                                'updateOne' => [
                                    (object) $condition,
                                    [
                                        '$unset' => (object) $newparams,
                                    ]
                                ]
                            ];
                        } else {
                            $newparams = ['items.$.process_tags' => $processTag];
                            $bulkOpArray[] = [
                                'updateOne' => [
                                    (object) $condition,
                                    [
                                        '$unset' => (object) $newparams,
                                    ]
                                ]
                            ];
                        }
                    }
                }
            }

            if (!empty($bulkOpArray)) {
                $productData = $this->getSingleProductDB([] , $bulkOpArray);
            }
        }
    }

    public function removeErrorQuery($sourceProductIds, $type, $targetShopId, $sourceShopId, $userId, $products = [] , $merchantData = null): void
    {
        if (!empty($sourceProductIds)) {
            $bulkOpArray = [];
            $productInsert = [];
            foreach ($sourceProductIds as $sourceProductId) {
                $foundProduct = null;
                $error = [];
                $alreadySavedError = [];
                $merchantId = $merchantData[$sourceProductId];
                $specifics = [
                    'source_product_id' => (string)$sourceProductId,
                    'source_shop_id' => (string)$sourceShopId,
                    'target_shop_id' => (string)$targetShopId,
                    'user_id' => (string)$userId,
                    'merchant_shipping_group' => (string)$merchantId
                ];
                $productData = $this->getSingleProductDB($specifics);
                $foundProduct = $productData;

                if ($foundProduct) {                                   
                    if (isset($foundProduct['error'])) {
                        $alreadySavedError = $foundProduct['error'];
                        $error = $foundProduct['error'];
                        // condition for product delete
                        if ($type == "delete" || $type == "product") {
                            $error = [];
                        }
                        else
                        {
                            foreach ($error as $unsetKey => $singleError) {
                                if(isset($singleError['type']) && $singleError['type'] === $type)
                                {
                                    unset($error[$unsetKey]);
                                }
                            }
                        }
                    }

                    if (!empty(array_diff(array_keys($alreadySavedError), array_keys($error)))) {
                        $condition = [
                            // required
                            'user_id' => (string)$userId,
                            'source_shop_id' => (string)$sourceShopId,
                            'merchant_shipping_group' => (string)$merchantId,
                            'target_shop_id' => (string)$targetShopId , 
                            'items.source_product_id' => (string)$sourceProductId
                        ];
                        if (empty($error)) {
                            $newparams = ['items.$.error' => true];
                            $bulkOpArray[] = [
                                'updateOne' => [
                                    (object) $condition,
                                    [
                                        '$unset' => (object) $newparams,
                                    ]
                                ]
                            ];

                        } else {
                            $newparams = ['items.$.error' => $error];
                            $bulkOpArray[] = [
                                'updateOne' => [
                                    (object) $condition,
                                    [
                                        '$set' => (object) $newparams,
                                    ]
                                ]
                            ];
                        }
                    }
                }
            }

            if (!empty($bulkOpArray)) {
                $productData = $this->getSingleProductDB([] , $bulkOpArray);
            }
        }
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
            'params' =>  $params,
            'worker_name' => 'create_refine_product',
        ];
        $SqsObject = new  SQSWorker;

        $SqsObject->CreateWorker($newData, false);
    }

    public function createRefinProductsSQS($data)
    {
        $params = $data['data']['params'];

        if (!($this->validateDataDuplicate($params))) {
            return ['success' => false, 'message' => 'data missing'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $feedServerSide = $mongo->getCollectionForTable('feed_server_side');

        $extraKeys = $this->di->getConfig()->get('refine_additional_keys')->toArray();

        $infoR = $feedServerSide->insertOne(['data' => $params, 'config' => $extraKeys]);

        $id = (string)$infoR->getInsertedId();

        $idTo = "'{$id}'";

        $mongoInfo = $this->di->getConfig()->get('databases')['db_mongo'];

        $host  = $mongoInfo['host_server_side'];

        $username = $mongoInfo['username'];

        $password = $mongoInfo['password'];

        $basePath = BP . DS . 'app/code/connector/Models/Product/UpdateTarget.js';

        $eval = '"var id = ' . $idTo . ' " ';

        $createCommand = "mongosh " . $host . ' --username ' . $username . ' -p ' . $password .   ' --eval ' . $eval . $basePath;

        $start = microtime(true);
        $execute = shell_exec($createCommand);
        $end = microtime(true);


        if ($end - $start > 1) {
            $this->di->getLog()->logContent('refineTablecreateForallProduct = ' . json_encode((['timeStamp' => time(), 'time' => $end - $start, 'user_id' => $params['user_id']]), true), 'info', 'bulktimeTest.log');
        }

        return $execute;
    }

    public function appendContainerId($data)
    {
        return isset($data['container_id']) ? ['container_id' => (string)$data['container_id']] : [];
    }

    public function getSingleProductDB($params , $update = false)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
        if($update)
        {
            $response = $collection->BulkWrite($update, ['w' => 1]);
            return true;
        }
        $sourceProductId = $params['source_product_id'];
        unset($params['source_product_id']);
        $query = $params;
        $query['items.source_product_id'] = (string)$sourceProductId;
        $data = $collection->findOne($query , ['projection' => ['items.$' => 1 , "_id" => 0 , 'sku' => 1] , "typeMap" => ['root' => 'array', 'document' => 'array']]);
        $data['items'][0]['parentSKU'] = $data['sku'];
        return $data['items'][0] ?? false;
    }

    public function addTags($sourceProductIds, $tag, $targetShopId, $sourceShopId, $userId, $products = [], $unsetTag = false , $merchantData = null): void
    {
        $date = date(DATE_RFC2822);
        $time = str_replace("+0000", "GMT", $date);
        $productComponent = $this->di->getObjectManager()->get(Product::class);
        $message = $productComponent->getMessage($tag);

        if (!empty($sourceProductIds)) {
            $productInsert = [];
            foreach ($sourceProductIds as $sourceProductId) {
                $merchantId = $merchantData[$sourceProductId];
                $foundProduct = null;
                $processTag = [];
                $alreadySavedProcessTag = [];
                $marketplace = false;
                $specifics = [
                    'source_product_id' => $sourceProductId,
                    'source_shop_id' => $sourceShopId,
                    'target_shop_id' => $targetShopId,
                    'user_id' => $userId,
                    'merchant_shipping_group' => $merchantId
                ];
                $productData = $this->getSingleProductDB($specifics);

                $foundProduct = $productData;
                if ($foundProduct) {

                    $processTag = $foundProduct['process_tags'] ?? [];
                    if(!empty($processTag))
                    {
                        if (!in_array($tag, array_keys($processTag))) {
                            $alreadySavedProcessTag = $processTag;
                        } else if ($unsetTag) {
                            unset($processTag[$unsetTag]);
                        }
                    }

                    if (empty(($alreadySavedProcessTag)) || array_search($tag, array_keys($alreadySavedProcessTag)) === false) {
                        $data = [
                            'msg' => $message,
                            'time' => $time
                        ];
                        $processTag[$tag] = $data;
                    }

                    if (!empty($processTag) && !empty(array_diff(array_keys($processTag), array_keys($alreadySavedProcessTag)))) {

                        if (in_array($unsetTag, array_keys($processTag))) {
                            unset($processTag[$unsetTag]);
                        }

                        $condition = [
                            // required
                            'user_id' => $userId,
                            'source_shop_id' => $sourceShopId,
                            'merchant_shipping_group' => $merchantId,
                            'target_shop_id' => $targetShopId , 
                            'items.source_product_id' => (string)$sourceProductId
                        ];
                        $newparams['items.$.process_tags'] = $processTag;


                        $bulkOpArray[] = [
                            'updateOne' => [
                                (object) $condition,
                                [
                                    '$set' => (object) $newparams,
                                ],
                                ['upsert' => true]
                            ]
                        ];
                    }
                }
            }

            if (!empty($bulkOpArray)) {
                $productData = $this->getSingleProductDB([] , $bulkOpArray);
            }
        }
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
                    'source_marketplace' => [
                        '$exists' => true
                    ]
                ] + $this->appendContainerId($var), [
                    '$set' => [
                        $this->_childKey => $data[$this->_childKey]
                    ]
                ]
            ]
        ];
    }

    public function isChildOfProduct($data)
    {
        return ($data['type'] === "simple"
            && $data['visibility'] === "Not Visible Individually"
            && isset($data['container_id'])
        ) ?  true : false;
    }

    public function isSequentialArray($arr)
    {
        return array_keys($arr) == range(0, count($arr) - 1);
    }

    public function updateRefindProducts($var, $dbData): void
    {
        $dbData = json_decode(json_encode($dbData), true);
        $childInfo = $var['childInfo'];
        $targetUPdates = $this->di->getObjectManager()->get('\App\Connector\Models\Product\TargetUpdates');
        if (isset($childInfo['source_marketplace'])) {
            $valueFormatted = $this->prepareDataTosendForAllTargtes($var);


            $allTargetIds = $this->_allTargetIds ?: $this->getAllTargetIds($valueFormatted, true);

            $getUpdatedMarketplace = $targetUPdates
                ->updateTargetForNewProduct($valueFormatted, $dbData, true, $this->_mongo, $allTargetIds);
        } else if (isset($childInfo['target_marketplace'])) {
            $dataTosend = [
                'shopId' => $childInfo['shop_id'],
                'marketplace' => $childInfo['target_marketplace'],
                'user_id' => $this->_userId
            ];
            $getUpdatedMarketplace = $targetUPdates->getFormattedForSingleTarget($dbData, $dataTosend, $this->_mongo);
        }

        if ($this->di->has('isDev') && $this->di->get('isDev')) {
        }

        // $this->di->getLog()->logContent('marketplace data ' . print_r($getUpdatedMarketplace, true), 'info', 'refineResponse.log');


        if ($getUpdatedMarketplace['success']) {
            $this->_updateRefineProduct[$dbData['source_product_id']] = $getUpdatedMarketplace['data'];
        }

        $formatShopIdWiseData = [];
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
        $extraKeys = $this->di->getConfig()->get('refine_additional_keys')->toArray();

        $extraProjection = [];
        foreach ($extraKeys as $v) {
            $extraProjection[$v] = 1;
        }

        if ($useContainerId) {
            $findQuery = [
                'user_id' => $this->_userId, 'container_id' => ['$in' => $allSourceProductIds]
            ] + $this->appendCheck();
        } else {
            $findQuery = [
                'user_id' => $this->_userId, 'source_product_id' => ['$in' => $allSourceProductIds]
            ] + $this->appendCheck();
        }


        return $this->_mongo->find(
            $findQuery,
            [
                'marketplace' => 1, 'source_product_id' => 1, 'container_id' => 1, 'type' => 1, 'visibility' => 1, 'tags' => 1, 'asin' => 1, 'variant_attributes' => 1
            ] + $extraProjection
        )->toArray();
    }

    public function getAllSourceProductIds($data, $allSourceProductIdsData = false): void
    {

        if ($allSourceProductIdsData) {
            $allProducts = $this->getProducts($allSourceProductIdsData, true);
            foreach ($allProducts as $v) {
                $key =  $this->getKey($v);
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

            $allParentProducts = $this->getProducts($allParentProductSourceIds);

            foreach ($allParentProducts as $v) {
                $key =  $this->getKey($v);
                $this->_allProductData[$key] = $v;
            }
        }
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
                        $allTargetIds[] = [
                            'marketplace' => $vT['code'] ?? $vT['marketplace'],
                            'shopId' => $vT['shop_id']
                        ];
                    }
                }

                break;
            }
        }

        return $allTargetIds;
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

        $targetProduct = $this->_mongo->findOne([
            'source_product_id' => $data['source_product_id'],
            'shop_id' => $data['target_shop_id'],
            'user_id' => $this->_userId
        ]);

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
                'sku' => $data['sku'],
                "source_shop_id"=>['$exists'=>true]
            ]);
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

        $targetProduct = $this->_mongo->find([
            'sku' => $data['sku'],
            'shop_id' => $data['target_shop_id'],
            'user_id' => $this->_userId
        ])->toArray();
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

        $targetParams = [
            'source_sku' => $data['sku'],
            'shop_id' => $data['target_shop_id'],
            'user_id' => $this->_userId
        ];

        if (isset($data['source_marketplace'])) {
            $targetParams['source_marketplace'] = $data['source_marketplace'];
        }

        $targetProduct = $this->_mongo->find($targetParams)->toArray();

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
        return $productData;
    }

    public function updateproductbyQuery($filter, $option)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_mongo = $mongo->getCollectionForTable('product_container');
        $this->_mongo->updateOne($filter, $option);
        return true;
    }

    public function getproductCountbyQuery($query)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_mongo = $mongo->getCollectionForTable('product_container');
        $productCount = $this->_mongo->count($query);
        return $productCount;
    }

    public function updateTargetForNewProduct($data, $dbData = false, $returnMarketPlace = false, $_mongoIndex = null, $allTargetsIdsReci = null)
    {
        if ($_mongoIndex) {
            $this->_mongo = $_mongoIndex;
        }

        if (!(isset($data['source']['shopId'], $data['source']['marketplace'], $data['user_id'], $data['source_product_id']))) {
            return ['success' => false, 'message' => 'source id or source marketplace or user_id or source_product_id missing'];
        }

        $this->init($data);
        $allTargetIds = $allTargetsIdsReci ?: $this->getAllTargetIds($data);
        if (count($allTargetIds) == 0) {
            return ['success' => false, 'message' => 'No target Found'];
        }

        if ($dbData) {
            $product = [];
            $product[] = $dbData;
        } else {
            $product = $this->getProduct($data);
            if (count($product) == 0 && isset($product[0]['marketplace'])) {
                return ['success' => false, 'message' => 'No product Found or marketplace key not present'];
            }
        }


        $updatedMarketplace = [];
        foreach ($allTargetIds as $value) {
            $updatedMarketplace = $this->formateMarketplaceArray($value, $product[0]);
            $product[0]['marketplace'] = $updatedMarketplace;
        }

        if ($returnMarketPlace) {
            return ['success' => true, 'data' => $this->_formattedRefineProductDetails];
        }

        $this->_mongo->updateMany($this->getQueryForOneProduct($data), ['$set' => ['marketplace' => $updatedMarketplace]]);
        return ['success' => true, 'message' => 'succesfully added'];
    }
}
