<?php

namespace App\Amazon\Components\Product;

use App\Amazon\Components\Listings\CategoryAttributes;
use App\Amazon\Components\LocalSelling;
use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base;
use Exception;

class Inventory extends Base
{
    protected $_user_id;

    protected $_user_details;

    protected $_baseMongo;

    protected $configObj;
    
    protected $_shop_id;

    protected $_remote_shop_id;

    public function init($request = [])
    {
        if (isset($request['user_id'])) {
            $this->_user_id = (string) $request['user_id'];
        } else {
            $this->_user_id = (string) $this->di->getUser()->id;
        }

        $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        if (isset($request['shop_id'])) {
            $this->_shop_id = (string) $request['shop_id'];
            $shop = $this->_user_details->getShop($this->_shop_id, $this->_user_id);
        } else {
            $shop = $this->_user_details->getDataByUserID($this->_user_id, 'amazon');
            $this->_shop_id = $shop['_id'];
        }

        $this->_remote_shop_id = $shop['remote_shop_id'];
        /*$this->_site_id = $shop['warehouses'][0]['seller_id'];*/

        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $this->configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
        $appTag = $this->di->getAppCode()->getAppTag();
        if ($appTag == 'default') {
            $this->configObj->setAppTag(null);
        }

        return $this;
    }

    public function checkFba($sourceProductId, $userId, $targetShopId)
    {
        $amazonListing = false;
        $amazonCollection = $this->_baseMongo->getCollectionForTable(Helper::AMAZON_LISTING);
        $amazonListing = $amazonCollection->find(
            ['user_id' => (string) $userId, 'shop_id' => (string) $targetShopId, 'source_product_id' => $sourceProductId],
            ['projection' => ["_id" => false, "fulfillment-channel" => true], 'typeMap' => ['root' => 'array', 'document' => 'array']]
        );
        $listingData = $amazonListing->toArray();

        if (!empty($listingData)) {
            if (isset($listingData[0]['fulfillment-channel']) && $listingData[0]['fulfillment-channel'] !== 'DEFAULT') {
                return ['success' => false, 'msg' => ['AmazonError124']];
            }
            return ['success' => true];
        }
        return ['success' => false, 'msg' => ['AmazonError501']];
    }

    public function prepareValue($product, $mapping)
    {
        if(isset($mapping['shopify_select']))
        {
            if($mapping['shopify_select'] === 'custom') {
                if(!empty($mapping['custom_text'])) {
                    return $mapping['custom_text'];
                }
            }
            elseif($mapping['shopify_select'] === 'recommendation') {
                if(!empty($mapping['recommendation'])) {
                    return $mapping['recommendation'];
                }
            }
            elseif($mapping['shopify_select'] === 'Search' && !empty($mapping['shopify_attribute'])) {
                $shopifyAttribute = $mapping['shopify_attribute'];
                if (isset($mapping['meta_attribute_exist']) && $mapping['meta_attribute_exist']) {
                    if (isset($product['parentMetafield'][$shopifyAttribute])) {
                        return $product['parentMetafield'][$shopifyAttribute];
                    }
                    if (isset($product['variantMetafield'][$shopifyAttribute])) {
                        return $product['variantMetafield'][$shopifyAttribute];
                    }
                    if (isset($product['parent_details']['parentMetafield'][$shopifyAttribute])) {
                        return $product['parent_details']['parentMetafield'][$shopifyAttribute];
                    }
                }
                else {
                    return $product[$shopifyAttribute];
                }
            }
        }

        return '';
    }

    public function getRestockAttribute($product)
    {
        $jsonHelper = $this->di->getObjectManager()->get(CategoryAttributes::class);
        $jsonAttributes = $jsonHelper->getJsonCategorySettings($product);
        if ($jsonAttributes && !empty($jsonAttributes)) {
            return $jsonAttributes;
        }
        $categorySettings = [];
        if(isset($product['edited']['edited_variant_attributes']) && is_array($product['edited']['edited_variant_attributes'])) {
            foreach ($product['edited']['edited_variant_attributes'] as $mapping) {
                if(isset($mapping['amazon_attribute']) && $mapping['amazon_attribute'] === 'restock_date') {
                    $categorySettings['restock_date'] = $this->prepareValue($product, $mapping);
                    break;
                }
            }
        }

        if(isset($product['edited']['fulfillment_type'])) {
            $categorySettings['fulfillment_type'] = $product['edited']['fulfillment_type'];
        }
        // Parent Level Attribute Mapping/Editing
        if(!isset($categorySettings['restock_date'])) {
            if(isset($product['parent_details']['edited']['category_settings']['attributes_mapping'])) {
                $parentLevelAttributeMapping = $product['parent_details']['edited']['category_settings']['attributes_mapping'];
                if(isset($parentLevelAttributeMapping['optional_attribute'])) {
                    foreach ($parentLevelAttributeMapping['optional_attribute'] as $mapping) {
                        if(isset($mapping['amazon_attribute']) && $mapping['amazon_attribute'] === 'restock_date') {
                            $categorySettings['restock_date'] = $this->prepareValue($product, $mapping);
                            break;
                        }
                    }
                }
            }
        }

        if(!isset($categorySettings['fulfillment_type'])) {
            if(isset($product['parent_details']['edited']['fulfillment_type'])) {
                $categorySettings['fulfillment_type'] = $product['parent_details']['edited']['fulfillment_type'];
            }
        }
        // Profile Level Attribute Mapping/Editing
        if(!isset($categorySettings['restock_date']) && isset($product['profile_info']['attributes_mapping']['data'])) {
            $templateLevelAttributeMapping = $product['profile_info']['attributes_mapping']['data'];
            if($templateLevelAttributeMapping['optional_attribute']) {
                foreach ($templateLevelAttributeMapping['optional_attribute'] as $mapping) {
                    if(isset($mapping['amazon_attribute']) && $mapping['amazon_attribute'] === 'restock_date') {
                        $categorySettings['restock_date'] = $this->prepareValue($product, $mapping);
                        break;
                    }
                }

                if (isset($product['profile_info']['data']) && is_array($product['profile_info']['data'])) {
                    foreach ($product['profile_info']['data'] as $setting) {
                        if ($setting['data_type'] === "fulfillment_settings") {
                            if (isset($setting['data']['fulfillment_type'])) {
                                $categorySettings['fulfillment_type'] = $setting['data']['fulfillment_type'];
                                break;
                            }
                        }
                    }
                }
            }
        }
        return $categorySettings;
    }

    public function upload($sqsData, $localDelivery = false)
    {
        $feedContent = [];

        $addTagProductList = [];
        $productErrorList = [];
        $activeAccounts = [];
        $uploadStatus = [];
        $response = [];
        $time = date(DATE_ISO8601);
        $data = json_decode(json_encode($sqsData, true), true);

        $productComponent = $this->di->getObjectManager()->get(Product::class);

        if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
            $userId = $data['data']['params']['user_id'] ?? $this->_user_id;
            $targetShopId = $data['data']['params']['target']['shopId'];
            $sourceShopId = $data['data']['params']['source']['shopId'];
            $targetMarketplace = $data['data']['params']['target']['marketplace'];
            $sourceMarketplace = $data['data']['params']['source']['marketplace'];
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $amazonShop = $userDetails->getShop($targetShopId, $userId);
            $notUploadStatus = true;
            $instantInventory = false;
            $date = date('d-m-Y');
            $instantInventory = false;
            $logFile = "amazon/{$userId}/{$sourceShopId}/SyncInventory/{$date}.log";
            $sourceProductIds = array_column($data['data']['rows'],'source_product_id');
            // $localSellingQuantity = [];
            $instantInventory = false;
            $productComponent->removeTag($sourceProductIds, [Helper::PROCESS_TAG_INVENTORY_SYNC], $targetShopId, $sourceShopId, $userId, [], []);
            if ($amazonShop['warehouses'][0]['status'] == 'active') {
                $products = $data['data']['rows'];
                foreach ($products as $product) {
                    $categorySettings = false;
                    $restockDate = false;
                    if ($product['type'] == 'variation') {
                        //need to discuss what to return for variant parent product as variant parent's inv will not sync to amazo
                        continue;
                    }

                    $inventoryAttribute = $this->getRestockAttribute($product);
                    if (isset($inventoryAttribute['restock_date'])) {
                        $restockDate = $inventoryAttribute['restock_date'];

                    }

                    $marketplaceData = $product['marketplace'];
                    foreach ($marketplaceData as $marketplace) {
                        if (isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == $targetMarketplace && $marketplace['shop_id'] == $targetShopId) {
                            if (isset($marketplace['status']) && ($marketplace['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UPLOADED)) {
                                $notUploadStatus = false;
                            }
                        }
                    }

                    $validProduct = $this->checkFba($product['source_product_id'], $userId, $targetShopId);
                    if (!$validProduct['success'] && $notUploadStatus) {
                        if (isset($validProduct['msg'])) {
                            $productErrorList[$product['source_product_id']] = $validProduct['msg'];
                        }
                    } else {
                        $rawBody = ["group_code" => ['order']];
                        $this->configObj->setUserId($userId);
                        $this->configObj->setTargetShopId($targetShopId);
                        $this->configObj->setSourceShopId($sourceShopId);
                        $this->configObj->setTarget($targetMarketplace);
                        $configHelper = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');
                        $configResponse = $configHelper->getConfigData($rawBody);
                        $configResponse = $configResponse['data'] ?? [];
                        $invTemplateData = $this->prepare($product);
                        $calculateResponse = $this->calculate($product, $invTemplateData, $sourceMarketplace, $merchantDataSourceProduct);

                        $container_ids[$product['source_product_id']] = $product['container_id'];
                        $alreadySavedActivities[$product['source_product_id']] = $product['edited']['last_activities'] ?? [];

                        $type = $invTemplateData['type'] ?? false;
                        if ($type && $type != 'Product Settings') {
                            $invTemplateData = $this->prepare($product, true);
                        }

                        if (!empty($calculateResponse)) {

                                if (isset($calculateResponse['error'], $calculateResponse['saveInConfig'])) {
                                    $update = false;
                                    if (isset($invTemplateData['warehouse_disabled'])) {
                                        $value = $invTemplateData['warehouse_disabled'];
                                        if (!in_array($type, $value)) {
                                            $update = true;
                                            array_push($value, $type);
                                        }
                                    } else {
                                        $update = true;
                                        $value[] = $type;
                                    }

                                    if ($update) {
                                        $savedData = [
                                            'group_code' => 'inventory',
                                            'data' => [
                                                'warehouse_disabled' => $value,
                                            ],
                                        ];
                                        $configData = $data['data']['params'];
                                        $configData['data'][0] = $savedData;
                                        $res = $configHelper->saveConfigData($configData);
                                    }

                                    $productErrorList[$product['source_product_id']] = [$calculateResponse['error']];
                                } else {
                                    if (isset($calculateResponse['Quantity']) && $calculateResponse['Quantity'] < 0) {
                                        $calculateResponse['Quantity'] = 0;
                                    }

                                    if (!$calculateResponse['Latency']) {
                                        $calculateResponse['Latency'] = "2"; //if latency is less then 0 , then giving the default value
                                    }

                                    if (isset($calculateResponse['Quantity']) && $calculateResponse['Quantity'] <= 3 ) {

                                        $instantInventory[$product['source_product_id']] = [
                                            'Id' => $product['_id'],
                                            'SKU' => $calculateResponse['SKU'],
                                            'Quantity' => $calculateResponse['Quantity'],
                                            'Latency' => $calculateResponse['Latency'],
                                            'time' => $time,
                                        ];
                                    } else {

                                        $feedContent[$product['source_product_id']] = [
                                            'Id' => $product['_id'],
                                            'SKU' => $calculateResponse['SKU'],
                                            'Quantity' => $calculateResponse['Quantity'],
                                            'Latency' => $calculateResponse['Latency'],
                                            'time' => $time,
                                        ];
                                        if ($restockDate) {
                                            $feedContent[$product['source_product_id']]['RestockDate'] = $restockDate;

                                        }
                                    }
                                }

                        } else {
                            $inventoryDisabledErrorProductList[] = $product['source_product_id'];
                            $productErrorList[$product['source_product_id']] = ['AmazonError123'];
                        }
                    }
                }

                // $this->di->getLog()->logContent('FeedContent= ' . print_r($feedContent, true), 'info', $logFile);
                // print_r($feedContent);die;

                if (!empty($feedContent) || !empty($instantInventory)) {
                    if (!empty($feedContent) || !empty($instantInventory)) {
                        if (!empty($instantInventory)) {
                            $payload = [
                                'ids' => array_keys($instantInventory),
                                'home_shop_id' => $amazonShop['_id'],
                                'marketplace_id' => $amazonShop['warehouses'][0]['marketplace_id'],
                                'sourceShopId' => $sourceShopId,
                                'shop_id' => $amazonShop['remote_shop_id'],
                                'feedContent' => base64_encode(json_encode($instantInventory)),
                                'user_id' => $userId,
                                'operation_type' => 'Update',
                            ];
                            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                            $res = $commonHelper->sendRequestToAmazon('instant-inventory', $payload, 'POST');
                            if (isset($res['success']) && $res['success']) {
                                $this->saveFeedInDb($instantInventory, 'inventory', $container_ids, $data['data']['params'], $alreadySavedActivities);
                                $error = $res['error'];
                                $this->di->getLog()->logContent('InstantInv= ' . json_encode($error, true), 'info', $logFile);

                                $invtoSync = json_decode(base64_decode((string) $res['toBeSync']), true);
                                $feedContent = $feedContent + $invtoSync;
                            }
                        }

                        $specifics = [
                            'ids' => array_keys($feedContent),
                            'home_shop_id' => $amazonShop['_id'],
                            'marketplace_id' => $amazonShop['warehouses'][0]['marketplace_id'],
                            'sourceShopId' => $sourceShopId,
                            'shop_id' => $amazonShop['remote_shop_id'],
                            'feedContent' => base64_encode(json_encode($feedContent)),
                            'user_id' => $userId,
                            'operation_type' => 'Update',
                        ];
                        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                        $response = $commonHelper->sendRequestToAmazon('inventory-upload', $specifics, 'POST');
                        // print_r($response);die;
                        if (isset($response['success']) && $response['success']) {
                            $feedActivtiy = [];
                            $this->saveFeedInDb($feedContent, 'inventory', $container_ids, $data['data']['params'], $alreadySavedActivities);
                        }

                        // $this->di->getLog()->logContent('Response = ' . print_r($response, true), 'info', $logFile);

                        //                    if(isset($products[0]['edited'])){
                        //                        $products[0]['edited']['Quantity']=$calculateResponse['Quantity'];
                        //                        $products[0]['edited']['Latency']=$calculateResponse['Latency'];
                        //                    }
                        $productSpecifics = $productComponent->init()
                            ->processResponse($response, $feedContent, Helper::PROCESS_TAG_INVENTORY_FEED, 'inventory', $amazonShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, Helper::PROCESS_TAG_INVENTORY_SYNC, $localDelivery, $merchantDataSourceProduct);

                        $addTagProductList = $productSpecifics['addTagProductList'];
                        $productErrorList = $productSpecifics['productErrorList'];
                    }

                    // if (!empty($responseLocal) && !empty($responseOnline)) {
                    //     $responseData = array_merge($responseLocal, $responseOnline);
                    // } elseif (!empty($responseLocal)) {
                    //     $responseData = $responseLocal;
                    // } elseif (!empty($responseOnline)) {
                    //     $responseData = $responseOnline;
                    // }
                    return $responseData;
                }
                $productSpecifics = $productComponent->init()
                    ->processResponse($response, $feedContent, Helper::PROCESS_TAG_INVENTORY_SYNC, 'inventory', $amazonShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, false, $localDelivery, $merchantDataSourceProduct);
                return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);

            }
            // return error msg 'Selected target shop is not active'
            return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'Selected target shop is not active.', 0, 0, 0);

        }
        //return error msg 'One of rows data or target or source shops not found'
        return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'One of rows data or target or source shops not found.', 0, 0, 0);
    }

    public function saveFeedinDb($feedContent, $action, $container_ids, $params, $alreadySavedActivities): void
    {
        $editedData = [];
        $userId = $params['user_id'] ?? $this->_user_id;
        $targetShopId = $params['target']['shopId'];
        $sourceShopId = $params['source']['shopId'];
        $targetMarketplace = $params['target']['marketplace'];
        $sourceMarketplace = $params['source']['marketplace'];
        $alreadySavedActivity = [];
        $containerId = false;
        $dataToSave = [];
        $current_activity = false;

        $bulkUpdateLastFeedQuery = [];

        foreach ($feedContent as $sourceProductId => $feed) {
            $updatedActivity = [];
            $source_id = (string) $sourceProductId;
            $current_activity = [];
            $containerId = $container_ids[$sourceProductId] ?? "";
            $alreadySavedActivity = $alreadySavedActivities[$sourceProductId];

            if (isset($feed['time'])) {
                $time = $feed['time'];
                unset($feed['time']);
            }

            //for image case handling the feed data as it is prepared on home only
            if ($action == 'image' && isset($feed['feed']) && is_array($feed['feed'])) {
                $feed = array_merge($feed, $feed['feed']);
                unset($feed['feed']);
            }
            if($action == "product" && isset($feed['product_description'] ) && is_string($feed['product_description']) && (mb_detect_encoding($feed['product_description'], 'UTF-8', true) != 'UTF-8')){
              
                $feed['product_description'] = mb_convert_encoding($feed['product_description'],'UTF-8','ISO-8859-1');

            }

            $current_activity = [
                'type' => $action,
                'updated_at' => $time,
                'status' => 'in_progress',
                'data' => $feed,
            ];

            // if (!empty($alreadySavedActivity)) {
            //     $updatedActivity = $this->getUpdatedActivity($alreadySavedActivity, $current_activity, $action);
            // } else {
            //     $updatedActivity[] = $current_activity;
            // }

            $editedData[] = [
                'target_marketplace' => $targetMarketplace,
                'shop_id' => (string) $targetShopId,
                'container_id' => (string) $containerId,
                'source_product_id' => (string) $sourceProductId,
                'user_id' => $this->_user_id,
                'source_shop_id' => $sourceShopId,
                // 'last_activities' => $updatedActivity,
            ];

            $bulkUpdateLastFeedQuery[] = [
                'updateOne' => [
                    [
                        'user_id' => $this->_user_id ?? $this->di->getUser()->id,
                        'shop_id' => (string) $targetShopId,
                        'target_marketplace' => $targetMarketplace,
                        'container_id' => (string) $containerId,
                        'source_product_id' => (string) $sourceProductId,
                    ],
                    [
                        [
                            '$set' => [
                                'last_activities' => $this->buildLastActivitiesAggregation($current_activity, $action)
                            ]
                        ]
                    ]
                ],
            ];
        }

        if (!empty($editedData)) {
            $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
            $result = $editHelper->saveProduct($editedData, false, $params);

            $productContainerCollection = $this->di->getObjectManager()
                ->create('\App\Core\Models\BaseMongo')
                ->getCollection('product_container');

            $productContainerCollection->bulkWrite($bulkUpdateLastFeedQuery, ['w' => 1]);
        }
    }

    /**
    * Build the aggregation query for updating last_activities.
    */
    private function buildLastActivitiesAggregation(array $newActivity, string $action): array
    {
        return [
            '$let' => [
                'vars' => [
                    'newActivity' => $newActivity,
                    'existingActivities' => ['$ifNull' => ['$last_activities', []]],
                ],
                'in' => [
                    '$let' => [
                        'vars' => [
                            'existingSameTypeActivities' => [
                                '$filter' => [
                                    'input' => '$$existingActivities',
                                    'as' => 'activity',
                                    'cond' => ['$eq' => ['$$activity.type', $action]],
                                ],
                            ],
                            'existingDifferentTypeActivities' => [
                                '$filter' => [
                                    'input' => '$$existingActivities',
                                    'as' => 'activity',
                                    'cond' => ['$ne' => ['$$activity.type', $action]],
                                ],
                            ],
                        ],
                        'in' => [
                            '$concatArrays' => [
                                '$$existingDifferentTypeActivities',
                                [
                                    '$cond' => [
                                        ['$gt' => [['$size' => '$$existingSameTypeActivities'], 4]],
                                        [
                                            '$concatArrays' => [
                                                ['$slice' => ['$$existingSameTypeActivities', 1, 4]],
                                                ['$$newActivity'],
                                            ],
                                        ],
                                        [
                                            '$concatArrays' => [
                                                '$$existingSameTypeActivities',
                                                ['$$newActivity'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function getUpdatedActivity($alreadySavedActivity, $current_activity, $action)
    {
        $updatedActivity = [];
        $actionInActivity = [];

        foreach ($alreadySavedActivity as $activity) {
            if (isset($activity['type']) && $activity['type'] == $action) {
                array_push($actionInActivity, $activity);
            } else {
                array_push($updatedActivity, $activity);
            }
        }

        if (!empty($actionInActivity) && count($actionInActivity) > 4) {
            array_shift($actionInActivity);
            array_push($actionInActivity, $current_activity);

        } else {
            array_push($actionInActivity, $current_activity);
        }

        return array_merge($updatedActivity, $actionInActivity);
    }

    public function prepare_data_bopis($product, $localSellingQuantity, $invTemplateData)
    {
        $traveller = 0;
        $va = [];

        $bopis_data = [];
        $count = 0;
        // get store from db
        $data['user_id'] = $this->di->getUser()->id;
        $data['target_shop_id'] = $this->di->getRequester()->getTargetId();
        $data['onlyActiveStore'] = true;
        $data['target_marketplace'] = $this->di->getRequester()->getTargetName();
        $helper = $this->di->getObjectManager()->get(LocalSelling::class);
        $result = $helper->getAllStores($data);

        foreach ($product as $main_data) {

            foreach ($main_data['edited'] as $key => $value) {
                if ($key == 'onlineSelling' && $value == true) {
                    $bopis_data[$count]['local'] = [
                        'quantity' => $localSellingQuantity[$main_data['source_product_id']],
                    ];
                }

                if ($key == 'localSelling' && $value == true) {
                    foreach ($main_data['edited']['localsellingStoreData'] as $online_value) {
                        if ($online_value['store_enabled']) {
                            $action = $this->checkstore($online_value['store_id'], $result);
                            if ($action) {
                                $storeValue = $this->getTotalInventoryForBOPIS($main_data, $online_value, $invTemplateData);
                                $online_value['store_value'] = $storeValue;
                                $bopis_data[$count]['online'][] = $online_value;
                            }

                        }
                    }
                }

                $bopis_data[$count]['sku'] = $main_data['edited']['sku'] ?? $main_data['sku'];
                $bopis_data[$count]['source_product_id'] = $main_data['source_product_id'];

            }

            $count++;
        }

        return $bopis_data;
    }

    public function getTotalInventoryForBOPIS($product, $onlineValue, $invTemplateData)
    {
        try {

            $EnabledWareHouses = $invTemplateData['warehouses_settings'];

            $locations = $product['locations'];
            $userId = $product['user_id'];
            $sourceShopId = $product['shop_id'];
            $warehouses = $this->getActiveWarehouses($userId, $sourceShopId);
            $BOPISlocations = $onlineValue['shopify_location'];
            $Activewarehouses = $warehouses['active'];
            $TotalInventory = 0;
            foreach ($locations as $singleLocation) {
                if (in_array($singleLocation['location_id'], $EnabledWareHouses) && in_array($singleLocation['location_id'], $BOPISlocations)) {
                    if (in_array($singleLocation['location_id'], $Activewarehouses)) {
                        $TotalInventory = $TotalInventory + $singleLocation['available'];
                    }

                }

            }

            return $TotalInventory;

        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function checkstore($chain_id, $result)
    {
        $action = false;
        foreach ($result['data'] as $value) {
            if ($value['supplySourceId'] == $chain_id && $value['status'] == 'Active') {
                $action = true;
            }
        }

        return $action;
    }

    public function prepare($data, $getConfig = false)
    {
        $value = [];
        $type = false;
        $profilePresent = false;
        $invTemplateData = [];
        if (isset($data['profile_info']) && isset($data['profile_info']['data']) && !$getConfig) {
            $type = $data['profile'][0]['profile_id']['$oid'] ?? false;
            foreach ($data['profile_info']['data'] as $value) {
                if ($value['data_type'] == 'inventory_settings') {
                    $invTemplateData = $value['data'];
                    $profilePresent = true;
                }
            }
        }

        $invConfigData = [];
        if (!$profilePresent || $getConfig) {
            $type = 'Product Settings';
            $this->configObj->setGroupCode('inventory');
            $invConfigData = $this->configObj->getConfig();
            $invConfigData = json_decode(json_encode($invConfigData, true), true);
            foreach ($invConfigData as $value) {
                $invTemplateData[$value['key']] = $value['value'];
                $profilePresent = true;
            }

            if ($getConfig) {
                return $invTemplateData;
            }
        }

        if (!$profilePresent) {
            $this->configObj->setUserId('all');
            $this->configObj->setGroupCode('inventory');
            $type = 'default';
            $invConfigData = $this->configObj->getConfig();
            $invConfigData = json_decode(json_encode($invConfigData, true), true);
            foreach ($invConfigData as $value) {
                $invTemplateData[$value['key']] = $value['value'];
                $profilePresent = true;
            }
        }

        $invTemplateData['type'] = $type;
        return $invTemplateData;
    }

    public function getActiveWarehouses($userId, $sourceShopId)
    {
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $sourceShop = $userDetails->getShop($sourceShopId, $userId);
        $warehouses = $sourceShop['warehouses'];
        $activeWarehouses = [];
        $allWarehouses = [];
        foreach ($warehouses as $warehouse) {
            $allWarehouses[] = $warehouse['id'];
            if (isset($warehouse['active']) && $warehouse['active']) {
                $activeWarehouses[] = $warehouse['id'];
            }
        }

        return [
            'active' => $activeWarehouses,
            'all' => $allWarehouses,
        ];
    }

    public function bulkSyncInventory($data)
    {
        $this->init($data);
        $productProfile = $this->di->getObjectManager()->get('App\Connector\Components\Profile\GetProductProfileMerge');
        if (!isset($data['user_id'])) {
            $data['user_id'] = (string) $this->di->getUser()->id;
        }

        $targetShopId = $data['target']['shopId'];
        $user = $this->di->getUser();
        $user = $user->toArray();

        $shops = $user['shops'];
        foreach ($shops as $shop) {
            if ($shop['_id'] == $targetShopId) {
                $amazonShop = $shop;
            }
        }

        $userId = $data['user_id'];
        $notUploadStatus = true;
        $validProduct = false;
        $data['operationType'] = 'inventory_sync';
        $data['limit'] = 1000;
        if (!isset($data['activePage'])) {
            $data['activePage'] = 1;
        }

        $mergedData = $productProfile->getproductsByProductIds($data, true);
        $uniqueKey = $mergedData['unique_key_for_sqs'] ?? [];
        if (!empty($uniqueKey)) {
            $dataInCache = [
                'params' => [
                    'unique_key_for_sqs' => $uniqueKey,
                ],
            ];
            $productProfile->clearInfoFromCache(['data' => $dataInCache]);
        }

        $products = $mergedData['data']['rows'] ?? [];
        $amazonCollection = $this->_baseMongo->getCollectionForTable(Helper::AMAZON_LISTING);
        $sourceShopId = $data['source']['shopId'];
        $targetMarketplace = $data['target']['marketplace'];
        $sourceMarketplace = $data['source']['marketplace'];
        $feedContent = [];
        foreach ($products as $product) {
            if ($product['type'] == "variation") {
                continue;
            }

            $marketplaceData = $product['marketplace'];
            foreach ($marketplaceData as $marketplace) {
                if (isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == $targetMarketplace && $marketplace['shop_id'] == $targetShopId) {
                    if (isset($marketplace['status']) && ($marketplace['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UPLOADED)) {
                        $notUploadStatus = false;
                    }
                }
            }

            $amazonListing = false;
            $amazonListing = $amazonCollection->find(
                ['user_id' => (string) $userId, 'shop_id' => (string) $targetShopId, 'source_product_id' => $product['source_product_id']],
                ['projection' => ["_id" => false, "fulfillment-channel" => true], 'typeMap' => ['root' => 'array', 'document' => 'array']]
            );
            $listingData = $amazonListing->toArray();
            if (!empty($listingData)) {
                if (isset($listingData[0]['fulfillment-channel']) && $listingData[0]['fulfillment-channel'] != 'DEFAULT') {
                    $validProduct = true;
                }
            }

            if (!$validProduct && $notUploadStatus) {
                $this->configObj->setUserId($userId);
                $this->configObj->setTargetShopId($targetShopId);
                $this->configObj->setSourceShopId($sourceShopId);
                $this->configObj->setTarget($targetMarketplace);
                $invTemplateData = $this->prepare($product);
                $calculateResponse = $this->calculate($product, $invTemplateData, $sourceMarketplace);
                if (isset($calculateResponse['Quantity']) && $calculateResponse['Quantity'] < 0) {
                    $calculateResponse['Quantity'] = 0;
                }

                if (!$calculateResponse['Latency'] || isset($product['parentMetafield']) || isset($product['variantMetafield'])) {
                    if (isset($product['parentMetafield']['custom->lead_time']['value'])) {
                        $calculateResponse['Latency'] = $product['parentMetafield']['custom->lead_time']['value'];
                    } else if (isset($product['variantMetafield']['custom->lead_time']['value'])) {
                        $calculateResponse['Latency'] = $product['variantMetafield']['custom->lead_time']['value'];
                    } else {
                        $calculateResponse['Latency'] = "2"; //if latency is less then 0 , then giving the default value
                    }
                }

                if (isset($calculateResponse['Quantity'])) {
                    $feedContent[$product['source_product_id']] = [
                        'Id' => $product['_id'],
                        'SKU' => $calculateResponse['SKU'],
                        'Quantity' => $calculateResponse['Quantity'],
                        'Latency' => $calculateResponse['Latency'],
                    ];
                }
            }
        }

        if (!empty($feedContent)) {
            $specifics = [
                'ids' => array_keys($feedContent),
                'home_shop_id' => $amazonShop['_id'],
                'marketplace_id' => $amazonShop['warehouses'][0]['marketplace_id'],
                'sourceShopId' => $sourceShopId,
                'shop_id' => $amazonShop['remote_shop_id'],
                'feedContent' => base64_encode(json_encode($feedContent)),
                'user_id' => $userId,
                'operation_type' => 'Update',
                'admin' => true,
            ];
            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
            $response = $commonHelper->sendRequestToAmazon('inventory-upload', $specifics, 'POST');
            if (isset($response['result'][$amazonShop['warehouses'][0]['marketplace_id']]['response']['response']['FeedSubmissionId']) && $response['result'][$amazonShop['warehouses'][0]['marketplace_id']]['response']['success']) {
                $data = [
                    'feedId' => $response['result'][$amazonShop['warehouses'][0]['marketplace_id']]['response']['response']['FeedSubmissionId'],
                    'feedType' => $response['result'][$amazonShop['warehouses'][0]['marketplace_id']]['response']['response']['FeedType'],
                    'submittedDate' => $response['result'][$amazonShop['warehouses'][0]['marketplace_id']]['response']['response']['SubmittedDate'],
                ];
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $syncLeadTimeCollection = $mongo->getCollectionForTable('feed_container');
                $syncLeadTimeCollection->insertOne(
                    [
                        "feed_id" => $data['feedId'],
                        "type" => "JSON_LISTINGS_FEED",
                        "status" => "_DONE_",
                        "feed_created_date" => date('c'),
                        "marketplace_id" => $amazonShop['warehouses'][0]['marketplace_id'],
                        "shop_id" => $targetShopId,
                        "user_id" => $userId,
                        "source_shop_id" => $sourceShopId,
                        "specifics" => [
                            "type" => "JSON_LISTINGS_FEED",
                            "marketplace" => [
                                $amazonShop['warehouses'][0]['marketplace_id'],
                            ],
                            "ids" => $specifics['ids'],
                            "shop_id" => $targetShopId,
                        ],
                        'submit_data' => json_encode($feedContent),
                        'phalcon' => true,
                    ]
                );
                return ['success' => true, 'message' => 'Feed Submitted Successfully', 'data' => $data];
            }
            $users = $this->di->getConfig()->get('mail_for_sync_handling_time');
            if (!empty($users)) {
                $users = $users->toArray();
                foreach ($users as $user) {
                    $mailData = [
                        'subject' => 'Syncing Handling Time Error',
                        'content' => 'Error while syncing handling time for UserId=> ' . $userId,
                        'email' => $user,
                        'path' => 'amazon' . DS . 'view' . DS . 'email' . DS . 'TextTemplate.volt',
                    ];
                    try {
                        $this->di->getObjectManager()->get('App\Core\Components\SendMail')
                            ->send($mailData);
                    } catch (Exception $e) {
                        $this->di->getLog()->logContent('Exception : bulkInventorySync Function :'
                            . json_encode($e), 'info', 'exception.log');
                    }
                }
            }

            // $specifics = [
            //     'ids' => array_keys($feedContent),
            //     'home_shop_id' => $amazonShop['_id'],
            //     'marketplace_id' => $amazonShop['warehouses'][0]['marketplace_id'],
            //     'sourceShopId' => $sourceShopId,
            //     'shop_id' => $amazonShop['remote_shop_id'],
            //     'feedContent' => base64_encode(json_encode($feedContent)),
            //     'user_id' => $userId,
            //     'operation_type' => 'Update'
            // ];
            // $commonHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
            // $response = $commonHelper->sendRequestToAmazon('inventory-upload', $specifics, 'POST');
            return [];
        }
    }

    // inventory settings calculation by @yash sir
    // public function calculate($product, $invTemplateData, $sourceMarketplace = false, $merchantData = [])
    // {
    //     if(!$sourceMarketplace)
    //     {
    //         $sourceMarketplace = $product['source_marketplace'];
    //     }
    //     if($merchantData)
    //     {
    //         $prodLocal = false;
    //         $merchantId = $merchantData[$product['source_product_id']] ?? "";
    //         $localDeliveryComp = $this->di->getObjectManager()->get('App\Amazon\Components\LocalDeliveryHelper');
    //         $paramsOfLD = [
    //             'source_shop_id' => $product['shop_id'],
    //             'user_id' => (string)$this->_user_id,
    //             'container_id' => (string)$product['container_id'],
    //             'source_product_id' => (string)$product['source_product_id'],
    //             'items.merchant_shipping_group' => $merchantId
    //         ];
    //         $prodLocal = $localDeliveryComp->getSingleProductDB($paramsOfLD);
    //         if($prodLocal)
    //         {
    //             $product['edited']['sku'] = $prodLocal['sku'] ?? "";
    //             $invTemplateData['warehouses_settings'] = $prodLocal['map_location_id'] ?? [];
    //         }
    //     }
    //     $sourceShopId = $product['shop_id'];
    //     $userId = $this->_user_id;
    //     $activeWarehouses = [];
    //     $allWarehouses = [];
    //     $isPresent = false;
    //     $enabled = $invTemplateData['settings_enabled'] ?? false;
    //     $amazonProduct = [];
    //     if ($enabled) {
    //         if (isset($product['edited'])) {
    //             $amazonProduct = $product['edited'];
    //         }
    //         // set SKU
    //         if ($amazonProduct && isset($amazonProduct['sku'])) {
    //             $sku = $amazonProduct['sku'];
    //         } elseif (isset($product['sku'])) {
    //             $sku = $product['sku'];
    //         } else {
    //             $sku = $product['source_product_id'];
    //         }
    //         // set latency
    //         if ($amazonProduct && isset($amazonProduct['inventory_fulfillment_latency'])) {
    //             $latency = $amazonProduct['inventory_fulfillment_latency'];
    //         } elseif (isset($invTemplateData['inventory_fulfillment_latency'])) {
    //             $latency = $invTemplateData['inventory_fulfillment_latency'];
    //         } else {
    //             $latency = 1;
    //         }
    //         //set inventory policy & set quantity
    //         if (isset($invTemplateData['max_inventory_level']) && $invTemplateData['max_inventory_level'] != "") {
    //             $max_inventory_level = (int) $invTemplateData['max_inventory_level'];
    //         } else {
    //             $max_inventory_level = 999;
    //         }
    //         if (isset($invTemplateData['enable_max_inventory_level'])) {
    //             $enable_max_inventory_level = $invTemplateData['enable_max_inventory_level'];
    //         } else {
    //             $enable_max_inventory_level = true;
    //         }
    //         $warehouses = $this->getActiveWarehouses($userId , $sourceShopId);
    //         $activeWarehouses = $warehouses['active'] ?? [];
    //         $allWarehouses = $warehouses['all'] ?? [];
    //         $qty = 0;
    //         if (array_key_exists('inventory_management', $product) && $enable_max_inventory_level == true) {
    //             if (is_null($product['inventory_management'])) {
    //                 $qty = $max_inventory_level;
    //             } else {
    //                 if ($product['inventory_management'] == $sourceMarketplace) {
    //                     if (isset($product['inventory_policy']) && $product['inventory_policy'] == "CONTINUE") {
    //                         // then set qty either 999 or whatever filled in setting
    //                         $qty = $max_inventory_level;
    //                     } else {
    //                         if ($amazonProduct && isset($amazonProduct['quantity'])) {
    //                             $qty = $amazonProduct['quantity'];
    //                         } else {
    //                             /* code for selected warehouse */
    //                             $qty = 0;
    //                             $qtyChange = false;
    //                             if (isset($product['locations']) && is_array($product['locations'])) {
    //                                 foreach ($product['locations'] as $location) {
    //                                     if(isset($invTemplateData['warehouses_settings']) && $invTemplateData['warehouses_settings'] == 'all')
    //                                     {
    //                                         $qty = $qty + $location['available'];
    //                                         $qtyChange = true;
    //                                     }
    //                                     elseif(isset($invTemplateData['warehouses_settings']) && in_array($location['location_id'], $invTemplateData['warehouses_settings']) && in_array($location['location_id'] , $activeWarehouses)) {
    //                                         $qty = $qty + $location['available'];
    //                                         $qtyChange = true;
    //                                     }
    //                                     elseif(count($activeWarehouses) == 1 && $activeWarehouses[0] == $location['location_id'])
    //                                     {
    //                                         $qty = $qty + $location['available'];
    //                                         $qtyChange = true;
    //                                     }
    //                                     elseif(in_array($location['location_id'] , $allWarehouses))
    //                                     {
    //                                         $isPresent = true;
    //                                     }
    //                                 }
    //                                 if(isset($invTemplateData['warehouses_settings'][0]) && count($activeWarehouses) > 1 && !$qtyChange && $isPresent)
    //                                 {
    //                                     return [
    //                                         'success' => false ,
    //                                         'saveInConfig' => true,
    //                                         'error' => 'AmazonError125'
    //                                     ];
    //                                 }
    //                             }
    //                             //for handling location error exception while type conversion
    //                             if ($qtyChange == false && $qty == 0 && isset($product['quantity'])) {
    //                                 $qty = (int) $product['quantity'];
    //                             }
    //                             $availableQty = $qty;
    //                             /*Customise Inventory and Fixed Inventory have same (lowest)priority and then Threshold and then delete product (highest)*/
    //                             if (isset($invTemplateData['settings_selected']['customize_inventory']) && $invTemplateData['settings_selected']['customize_inventory']) {
    //                                 $qty = $this->changeInv((int) $availableQty, 'value_decrease', $invTemplateData['customize_inventory']['value']);
    //                             } elseif (isset($invTemplateData['settings_selected']['fixed_inventory']) && $invTemplateData['settings_selected']['fixed_inventory']) {
    //                                 $qty = $this->changeInv((int) $availableQty, 'set_fixed_inventory', $invTemplateData['fixed_inventory']);
    //                             }
    //                             if (isset($invTemplateData['settings_selected']['threshold_inventory']) && $invTemplateData['settings_selected']['threshold_inventory']) {
    //                                 if ((int) $invTemplateData['threshold_inventory'] >= (int) $availableQty) {
    //                                     $qty = 0;
    //                                 }
    //                             }
    //                         }
    //                     }
    //                 } else {
    //                     $qty = $max_inventory_level;
    //                 }
    //             }
    //         } else {
    //             // when inv management does not exist
    //             if ($amazonProduct && isset($amazonProduct['quantity'])) {
    //                 $qty = $amazonProduct['quantity'];
    //             } else {
    //                 /* code for selected warehouse */
    //                 $qty = 0;
    //                 $qtyChange = false;
    //                 if (isset($product['locations']) && is_array($product['locations'])) {
    //                     foreach ($product['locations'] as $location) {
    //                         if(isset($invTemplateData['warehouses_settings']) && $invTemplateData['warehouses_settings'] == 'all')
    //                         {
    //                             $qty = $qty + $location['available'];
    //                             $qtyChange = true;
    //                         }
    //                         elseif(isset($invTemplateData['warehouses_settings']) && in_array($location['location_id'], $invTemplateData['warehouses_settings']) && in_array($location['location_id'] , $activeWarehouses)) {
    //                             $qty = $qty + $location['available'];
    //                             $qtyChange = true;
    //                         }
    //                         elseif(count($activeWarehouses) == 1 && $activeWarehouses[0] == $location['location_id'])
    //                         {
    //                             $qty = $qty + $location['available'];
    //                             $qtyChange = true;
    //                         }
    //                         elseif(in_array($location['location_id'] , $allWarehouses))
    //                         {
    //                             $isPresent = true;
    //                         }
    //                     }
    //                     if(isset($invTemplateData['warehouses_settings'][0]) && count($activeWarehouses) > 1 && !$qtyChange && $isPresent)
    //                     {
    //                         return [
    //                             'success' => false ,
    //                             'saveInConfig' => true,
    //                             'error' => 'AmazonError125'
    //                         ];
    //                     }
    //                 }
    //                 //for handling location error exception while type conversion
    //                 if ($qtyChange == false && $qty == 0 && isset($product['quantity'])) {
    //                     $qty = (int) $product['quantity'];
    //                 }
    //                 $availableQty = $qty;
    //                 /*Customise Inventory and Fixed Inventory have same (lowest)priority and then Threshold and then delete product (highest)*/
    //                 if (isset($invTemplateData['settings_selected']['customize_inventory']) && $invTemplateData['settings_selected']['customize_inventory']) {
    //                     $qty = $this->changeInv((int) $availableQty, 'value_decrease', $invTemplateData['customize_inventory']['value']);
    //                 } elseif (isset($invTemplateData['settings_selected']['fixed_inventory']) && $invTemplateData['settings_selected']['fixed_inventory']) {
    //                     $qty = $this->changeInv((int) $availableQty, 'set_fixed_inventory', $invTemplateData['fixed_inventory']);
    //                 }
    //                 if (isset($invTemplateData['settings_selected']['threshold_inventory']) && $invTemplateData['settings_selected']['threshold_inventory']) {
    //                     if ((int) $invTemplateData['threshold_inventory'] >= (int) $availableQty) {
    //                         $qty = 0;
    //                     }
    //                 }
    //             }
    //         }
    //         return [
    //             'SKU' => $sku,
    //             'Quantity' => $qty,
    //             'Latency' => $latency
    //         ];
    //     } else {
    //         return [];
    //     }
    // }

    //inventory settings calculation by qaiser
    public function calculate($product, $invTemplateData, $sourceMarketplace = false, $merchantData = [])
    {
        if (!$sourceMarketplace) {
            $sourceMarketplace = $product['source_marketplace'];
        }

        // $this->handleMerchantData($product, $merchantData, $invTemplateData);
        $enabled = $invTemplateData['inventory_settings'] ?? false;
        if ($enabled) {
            $amazonProduct = $product['edited'] ?? [];
            $sku = $this->getSKU($amazonProduct, $product);
            $latency = $this->getLatency($amazonProduct, $invTemplateData, $product);
            $max_inventory_level = isset($invTemplateData['continue_selling']) && isset($invTemplateData['continue_selling']['enabled']) ? (int) $invTemplateData['continue_selling']['value'] : 999;
            $enable_max_inventory_level = $invTemplateData['continue_selling']['enabled'] ?? true;

            $qtyResponse = $this->calculateQuantity($amazonProduct, $product, $invTemplateData, $max_inventory_level, $enable_max_inventory_level);

            $qty = 0;
            if (isset($qtyResponse['success']) && $qtyResponse['success']) {
                $qty = $qtyResponse['calculated_quantity'];
            } else {
                return $qtyResponse;
            }

            return [
                'SKU' => $sku,
                'Quantity' => $qty,
                'Latency' => $latency,
            ];
        }
        return [];
    }

    // returns calculated SKU
    private function getSKU($amazonProduct, $product)
    {
        return $amazonProduct['sku'] ?? $product['sku'] ?? $product['source_product_id'];
    }

    // returns calculated handling time
    private function getLatency($amazonProduct, $invTemplateData, $product)
    {
        $users = $this->di->getConfig()->get('sync_handling_time_users');
        if (!empty($users)) {
            $users = $users->toArray();
            $checkUsers = array_keys($users);
        }

        if (isset($checkUsers) && in_array($this->_user_id, $checkUsers)) {
            if (isset($product['parentMetafield']['custom->lead_time']['value'])) {
                $latency = $product['parentMetafield']['custom->lead_time']['value'];
            } else if (isset($product['variantMetafield']['custom->lead_time']['value'])) {
                $latency = $product['variantMetafield']['custom->lead_time']['value'];
            }
            else if (isset($product['parentMetafield']['custom->az_lead_time']['value'])){
                $latency = $product['parentMetafield']['custom->az_lead_time']['value'];
            }
            else if (isset($product['variantMetafield']['custom->az_lead_time']['value'])){
                $latency = $product['variantMetafield']['custom->az_lead_time']['value'];
            }  
            else {
                $latency = $amazonProduct['inventory_fulfillment_latency'] ?? $invTemplateData['inventory_fulfillment_latency'] ?? 2;
                // $latency = "2"; //if latency is less then 0 , then giving the default value
            }
        } else {
            $latency = $amazonProduct['inventory_fulfillment_latency'] ?? $invTemplateData['inventory_fulfillment_latency'] ?? 2;
        }

        return $latency;
    }

    //checks if track inventory is ON on shopify or not
    private function isTrackOn($product): bool
    {
        return array_key_exists('inventory_management', $product) && !empty($product['inventory_management']);
    }

    //checks if continue selling is ON on shopify or not
    private function isContinueSellingOn($product): bool
    {
        $product['inventory_policy'] = strtolower($product['inventory_policy'] ?? '');
        return isset($product['inventory_policy']) && ($product['inventory_policy'] == "continue");
    }

    //returns calculated inventory
    private function calculateQuantity($amazonProduct, $product, $invTemplateData, $max_inventory_level, $enable_max_inventory_level): array
    {
        $sourceShopId = $product['shop_id'];
        $userId = (string) $this->_user_id;
        $qty = 0;

        $useQtyRules = false;
        $qtyRules = [];
        $useCustom = false;
        $customQty = null;
        $sameAsShopify = false;
        $shopifyTotalQty = null;

        $quantityRulesResponse = $this->useQuantityRules($product);
        if ($quantityRulesResponse['success'] && !empty($quantityRulesResponse['data'])) {
            $useQtyRules = true;
            $qtyRules = $quantityRulesResponse['data'];

            switch ($qtyRules['attribute']) {
                case 'effective' :
                    $invTemplateData['effective_inventory'] = $qtyRules['effective_inventory'];
                    $enable_max_inventory_level = $qtyRules['continue_selling']['enabled'];
                    $max_inventory_level = $qtyRules['continue_selling']['value'];
                    break;
                case 'custom' :
                    $useCustom = true;
                    $customQty = $this->getInventoryByRule($product, $qtyRules, 'custom');                    break;
                case 'default' :
                    $sameAsShopify = true;
                    $shopifyTotalQty = $this->getInventoryByRule($product, $qtyRules, 'default');
                    break;
            }
        }

        if (($amazonProduct && isset($amazonProduct['quantity']) && !$useQtyRules) || ($useQtyRules && $useCustom && !is_null($customQty))) {
            $qty = !is_null($customQty) ? $customQty : $amazonProduct['quantity'];
        } else {
            if (($this->isTrackOn($product) && $this->isContinueSellingOn($product) && $enable_max_inventory_level && !$sameAsShopify) || (!$this->isTrackOn($product) && $enable_max_inventory_level && !$sameAsShopify)) {
                $qty = $max_inventory_level;
            } elseif($sameAsShopify) {
                $qty = $shopifyTotalQty;
            } else {
                /* code for selected warehouse/locations */
                if (isset($product['locations']) && is_array($product['locations']) && empty($product['is_bundle'])) {
                    $warehouses = $this->getActiveWarehouses($userId, $sourceShopId);
                    $activeWarehouses = $warehouses['active'] ?? [];
                    $allWarehouses = $warehouses['all'] ?? [];
                    $locations_qty = 0;
                    $qtyChange = false;
                    foreach ($product['locations'] as $location) {
                        if (isset($invTemplateData['warehouses']) && $invTemplateData['warehouses'] == 'all') {
                            $locations_qty = $locations_qty + $location['available'];
                            $qtyChange = true;
                        } elseif (in_array($location['location_id'], $invTemplateData['warehouses']) && in_array($location['location_id'], $activeWarehouses)) {
                            $locations_qty = $locations_qty + $location['available'];
                            $qtyChange = true;
                        } elseif (count($activeWarehouses) == 1 && $activeWarehouses[0] == $location['location_id']) {
                            $locations_qty = $locations_qty + $location['available'];
                            $qtyChange = true;
                        } elseif (in_array($location['location_id'], $allWarehouses)) {
                            $isPresent = true;
                        }
                    }

                    if (isset($invTemplateData['warehouses'][0]) && count($activeWarehouses) > 1 && !$qtyChange && $isPresent) {
                        $assignedLocationsIds = array_column($product['locations'], 'location_id');
                        $errorCode = array_diff($assignedLocationsIds, $activeWarehouses) ? 'AmazonError125' : 'AmazonError126';
                        $eventsManager = $this->di->getEventsManager();
                        $eventsManager->fire('application:warehouseDisabled', $this, [
                            'user_id' => $userId,
                            'source_shop_id' => $sourceShopId,
                            'target_shop_id' => $amazonProduct['shop_id'] ?? ""
                        ]);
                        return [
                            'success' => false,
                            'saveInConfig' => true,
                            'error' => 'AmazonError125'
                        ];
                    }
                    $qty = $locations_qty;
                } else {
                    $qty = (int) $product['quantity'];
                }

                $qty = $this->adjustQtyUsingTemplate($invTemplateData, $qty);
            }
        }

        return [
            'success' => true,
            'calculated_quantity' => $qty,
        ];
    }

    private function useQuantityRules($allProductData)
    {
        $quantityRules = $allProductData['edited']['quantity_rules']
            ?? $allProductData['parent_details']['edited']['quantity_rules']
            ?? [];

        if (empty($quantityRules)) {
            return ['success' => false];
        }

        return ['success' => true, 'data' => $quantityRules];
    }

    private function getInventoryByRule($product, $qtyRules, $selectedRule = 'default')
    {
        if ($selectedRule == 'default') {
            $totalInventory = 0;
            foreach ($product['locations'] as $location) {
                $totalInventory += $location['available'];
            }
            return $totalInventory;
        } elseif ($selectedRule == 'custom') {
            return $qtyRules['value'];
        }
    }

    private function adjustQtyUsingTemplate(array $invTemplateData, int $totalAvailableQty)
    {
        if (empty($invTemplateData['effective_inventory']['enabled'])) {
            return $totalAvailableQty;
        }

        $adjustmentType  = $invTemplateData['effective_inventory']['type'] ?? null;
        $adjustmentValue = (int) ($invTemplateData['effective_inventory']['value'] ?? 0);

        switch ($adjustmentType) {
            case 'threshold':
                $adjustedQty = $adjustmentValue >= $totalAvailableQty ? 0 : $totalAvailableQty;
                break;

            case 'reserved':
                $adjustedQty = $this->changeInv($totalAvailableQty, 'value_decrease', $adjustmentValue);
                break;

            case 'fixed':
                $adjustedQty = $this->changeInv($totalAvailableQty, 'set_fixed_inventory', $adjustmentValue);
                break;

            default:
                $adjustedQty = $totalAvailableQty;
                break;
        }

        return $adjustedQty;
    }

    public function changeInv($qty, $attribute, $changeValue)
    {
        switch ($attribute) {
            case 'value_increase':
                $qty = $qty + $changeValue;
                break;
            case 'value_decrease':
                $qty = $qty - $changeValue;
                break;
            case 'percentage_increase':
                $qty = $qty + (($changeValue / 100) * $qty);
                break;
            case 'percentage_decrease':
                $qty = $qty - (($changeValue / 100) * $qty);
                break;
            case 'set_fixed_inventory':
                $qty = $changeValue;
                break;
            default:
                break;
        }

        if ((int) $qty < 0) {
            $qty = 0;
        }

        return ceil($qty);
    }
}
