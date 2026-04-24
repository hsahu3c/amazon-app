<?php 
namespace App\Amazon\Components\Product;

use App\Amazon\Components\Common\Helper;
use App\Core\Models\User;
use App\Amazon\Components\Listings\CategoryAttributes;
use App\Amazon\Components\Product\Inventory;
use Exception;

class BulkInventory extends Inventory
{
    const DUAL_FULFILLMENT_TYPE = 'fba_then_fbm';

    private ?array $_globalInventoryConfig = null;

    private ?array $_defaultInventoryConfig = null;

    private ?array $_properties = null;

    public function init($request = []): self|false
    {
        $payload = json_decode(json_encode($request, true), true);
        if(isset($payload['data']['params'])) {
            $params = $payload['data']['params'];

            if (isset($params['user_id'])) {
                $this->_user_id = (string) $params['user_id'];
            }

            $this->configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
            $appTag = $this->di->getAppCode()->getAppTag();
            if(isset($payload['raw_data']['appTag'])) {
                $appTag = $payload['raw_data']['appTag'];
            }

            if ($appTag == 'default') {
                $this->configObj->setAppTag(null);
            } else {
                $this->configObj->setAppTag($appTag);
            }

            $this->configObj->setUserId($params['user_id']);
            $this->configObj->setTargetShopId($params['target']['shopId']);
            $this->configObj->setSourceShopId($params['source']['shopId']);
            $this->configObj->setTarget($params['target']['marketplace']);

            $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');

            return $this;
        }
        return false;

    }

    public function fetchFBAListings($userId, $targetShopId, $sourceProductId)
    {
        // die("yahan pr fetchFBAListings");
        $amazonCollection = $this->_baseMongo->getCollectionForTable(Helper::AMAZON_LISTING);
        if(is_array($sourceProductId)) {
            $amazonListing = $amazonCollection->find(
                ['user_id' => (string) $userId, 'shop_id' => (string) $targetShopId, 'source_product_id' => ['$in' => $sourceProductId]],
                ['projection' => ["_id" => false, "source_product_id" => true, "fulfillment-channel" => true], 'typeMap' => ['root' => 'array', 'document' => 'array']]
            );
        }
        else {
            $amazonListing = $amazonCollection->find(
                ['user_id' => (string) $userId, 'shop_id' => (string) $targetShopId, 'source_product_id' => $sourceProductId],
                ['projection' => ["_id" => false, "source_product_id" => true, "fulfillment-channel" => true], 'typeMap' => ['root' => 'array', 'document' => 'array']]
            );
        }

        $listingData = $amazonListing->toArray();
        if(!empty($listingData)) {
            // $response = [];
            // foreach ($listingData as $value) {
            //     $response[$value['source_product_id']] = $value;
            // }
            // return $response;

            return array_column($listingData, 'fulfillment-channel', 'source_product_id');
        }
        return [];
    }

    public function getUserShop($userId, $shopId)
    {
        if(isset($this->_properties['user']) && $this->_properties['user']['user_id'] == $userId) {
            $userDetails = $this->_properties['user'];
        }
        else {
            if($this->di->getUser()->id === $userId && !empty($this->di->getUser()->shops)) {
                $userDetails = json_decode(json_encode($this->di->getUser()), true);
            }
            else {
                $userDetails = User::findFirst([['_id' => $userId]]);
                $userDetails = json_decode(json_encode($userDetails), true);   
            }

            $this->_properties['user'] = $userDetails;
        }


        $shopData = [];
        $shops = $userDetails['shops'];
        foreach ($shops as $shop) {
            if($shop['_id'] == $shopId) {
                $shopData = $shop;
                break;
            }
        }

        return $shopData;
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
        $processType = $data['data']['params']['process_type'] ?? 'manual';

        $productComponent = $this->di->getObjectManager()->get(Product::class);

        if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
            $userId = $data['data']['params']['user_id'];
            $targetShopId = $data['data']['params']['target']['shopId'];
            $sourceShopId = $data['data']['params']['source']['shopId'];
            $targetMarketplace = $data['data']['params']['target']['marketplace'];
            $sourceMarketplace = $data['data']['params']['source']['marketplace'];
            $allParentDetails = $data['data']['all_parent_details']??[];
            $allProfileInfo = $data['data']['all_profile_info'] ?? [];
            $amazonShop = $this->getUserShop($userId, $targetShopId);
            $date = date('d-m-Y');
            $logFile = "amazon/{$userId}/{$sourceShopId}/SyncInventory/{$date}.log";
            // $localSellingQuantity = [];
            $instantInventory = false;
            $merchantDataSourceProduct = false;

            if ($amazonShop['warehouses'][0]['status'] == 'active') {
                $products = $data['data']['rows'];

                if(!empty($products)) {
                    $sourceProductIdsList = array_unique(array_column($products, 'source_product_id'));
                    $sourceProductIdsList = array_values($sourceProductIdsList);
                    // print_r($sourceProductIdsList);die;
                    $fbaListings = $this->fetchFBAListings($userId, $targetShopId, $sourceProductIdsList);
                    // echo '<pre>';print_r($fbaListings);die;

                    foreach ($products as $product)
                    {
                        $product = json_decode(json_encode($product),true);
                        if ($product['type'] == 'variation') {
                            //need to discuss what to return for variant parent product as variant parent's inv will not sync to amazon
                            continue;
                        }
                        if(isset($product['parent_details']) && is_string($product['parent_details'])){
                            $product['parent_details'] = $allParentDetails[$product['parent_details']];
                        }
                        if(isset($product['profile_info']['$oid'])){
                            $product['profile_info'] = $allProfileInfo[$product['profile_info']['$oid']];
                        }

                        $hasDualFulfillmentEnabled = $this->hasDualFulfillmentEnabled($product);

                        if ($this->di->getUser()->id == "683dc3e33b0eafbbe903a323") {
                            $hasDualFulfillmentEnabled = true;
                            if ($hasDualFulfillmentEnabled && $fbaListings[$product['source_product_id']] == 'DEFAULT') {
                                $hasDualFulfillmentEnabled = false;
                                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                                $amazonListing = $mongo->getCollectionForTable('amazon_listing');
                                $options = [
                                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                                    'projection' => ['_id' => 0, 'is_fba' => 1],
                                ];
                                $listingData = $amazonListing->findOne(
                                    [
                                        'user_id' => $userId,
                                        'shop_id' => $targetShopId,
                                        'source_product_id' => $product['source_product_id']
                                    ],
                                    $options
                                );
                                if (!empty($listingData['is_fba'])) {
                                    $hasDualFulfillmentEnabled = true;
                                }
                            }
                        }

                        if(isset($fbaListings[$product['source_product_id']])) {
                            if($fbaListings[$product['source_product_id']] !== 'DEFAULT' && !$hasDualFulfillmentEnabled) {
                                $productErrorList[$product['source_product_id']] = ['AmazonError124'];
                                continue;
                            }
                            // Proceed to sync inventory

                        }
                        else {
                            if (isset($product['marketplace'])) {
                                $marketplaceData = $product['marketplace'];
                                $productStatus = '';
                                foreach ($marketplaceData as $marketplace) {
                                    if (isset($marketplace['target_marketplace'], $marketplace['shop_id']) && $marketplace['target_marketplace'] == $targetMarketplace && $marketplace['shop_id'] == $targetShopId) {
                                        $productStatus = $marketplace['status'] ?? '';
                                        break;
                                    }
                                }
                            } else {
                                $productStatus = $product['edited']['status'] ?? '';
                            }

                            if($productStatus == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UPLOADED) {
                                // Proceed to sync inventory
                            }
                            else {
                                $productErrorList[$product['source_product_id']] = ['AmazonError501'];
                                continue;
                            }
                        }

                        $invTemplateData = $this->prepare($product);
                        $calculateResponse = $this->calculate($product, $invTemplateData, $sourceMarketplace, $merchantDataSourceProduct);

                        $container_ids[$product['source_product_id']] = $product['container_id'];
                        $alreadySavedActivities[$product['source_product_id']] = $product['edited']['last_activities'] ?? [];

                        $type = $invTemplateData['type'] ?? false;
                        if ($type && $type != 'Product Settings') {
                            // $invTemplateData = $this->prepare($product, true);
                            $globalInvConfig = $this->getUserGlobalInventoryConfig();
                            if(isset($globalInvConfig['global_setting_exists']) && $globalInvConfig['global_setting_exists']) {
                                $invTemplateData = $globalInvConfig['data'];
                            } else {
                                $invTemplateData = [];
                            }
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
                                    // $res = $configHelper->saveConfigData($configData);
                                    $res = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper')->saveConfigData($configData);
                                }

                                $productErrorList[$product['source_product_id']] = [$calculateResponse['error']];
                            }
                            else {
                                if (isset($calculateResponse['Quantity']) && $calculateResponse['Quantity'] < 0) {
                                    $calculateResponse['Quantity'] = 0;
                                }

                                // if (!$calculateResponse['Latency']) {
                                //     $calculateResponse['Latency'] = "2"; //if latency is less then 0 , then giving the default value
                                // }

                                //handle fba inventory sync when reverted to fbm
                                if (!empty($product['edited']) && array_key_exists('dual_listing', $product['edited'])) {
                                    $hasDualFulfillmentEnabled = $product['edited']['dual_listing'];
                                }

                                $fbaChannelCode = null;
                                if ($hasDualFulfillmentEnabled) {
                                    $fbaChannelCode = $this->getFbaChannelCode($amazonShop['warehouses'][0]['marketplace_id']);
                                }

                                if (!isset($calculateResponse['Latency'])) {
                                    $calculateResponse['Latency'] = "2"; //if latency is less then 0 , then giving the default value
                                }

                                $feedUniqueId = $product['_id'];
                                if ($feedUniqueId instanceof \MongoDB\BSON\ObjectId || !empty($feedUniqueId['$oid'])) {
                                    $feedUniqueId = $feedUniqueId['$oid'] ?? (string) $feedUniqueId;
                                }

                                // if (isset($calculateResponse['Quantity']) && $calculateResponse['Quantity'] <= 20) {
                                //     $instantInventory[$product['source_product_id']] = [
                                //         'Id' => $feedUniqueId,
                                //         'SKU' => rawurlencode($calculateResponse['SKU']),
                                //         'Quantity' => $calculateResponse['Quantity'],
                                //         'Latency' => $calculateResponse['Latency'],
                                //         'time' => $time,
                                //         'processed' => $processType,
                                //         'fbaFulfillment' => [
                                //             'enabled' => $hasDualFulfillmentEnabled,
                                //             'channel_code' => $fbaChannelCode
                                //         ]
                                //     ];
                                // }
                                // else {

                                    $feedContent[$product['source_product_id']] = [
                                        'Id' => $feedUniqueId,
                                        'SKU' => (string)$calculateResponse['SKU'],
                                        'Quantity' => $calculateResponse['Quantity'],
                                        'Latency' => $calculateResponse['Latency'],
                                        'time' => $time,
                                        'processed' => $processType,
                                        'fbaFulfillment' => [
                                            'enabled' => $hasDualFulfillmentEnabled,
                                            'channel_code' => $fbaChannelCode
                                        ]
                                    ];

                                    $inventoryAttribute = $this->getInventoryAttributeValues($product);
                                    if (isset($inventoryAttribute['restock_date'])) {
                                        $feedContent[$product['source_product_id']]['RestockDate'] = $inventoryAttribute['restock_date'];

                                    }
                                // }
                            }
                        }
                        else {
                            $inventoryDisabledErrorProductList[] = $product['source_product_id'];
                            $productErrorList[$product['source_product_id']] = ['AmazonError123'];
                        }
                    }
                }

                $this->resetClassPropeties();

                // $time = microtime(true); echo 'End time:'.$time;
                // // die('finish');
                // print_r($feedContent);print_r($productErrorList);print_r($instantInventory);die;

                $marketplaceComponent = $this->di->getObjectManager()->get(Marketplace::class);
                $baseParams = [
                    'user_id' => $userId,
                    'tag' => Helper::PROCESS_TAG_INVENTORY_FEED,
                    'type' => 'inventory',
                    'target' => [
                        'shopId' => $targetShopId,
                        'marketplace' => $targetMarketplace
                    ],
                    'source' => [
                        'shopId' => $sourceShopId,
                        'marketplace' => $sourceMarketplace
                    ],
                    'marketplaceId' => $amazonShop['warehouses'][0]['marketplace_id'],
                    'unsetTag' => Helper::PROCESS_TAG_INVENTORY_SYNC
                ];

                if (!empty($feedContent)) {
                    if(count($feedContent) < 50 || $processType == "automatic"){
                        $instantInventory = $feedContent;
                        $feedContent = [];
                    }

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
                        $productComponent->unsetError(array_keys($instantInventory), $data['data']['params'], $container_ids, 'inventory');

                        $this->di->getLog()->logContent('Instant Inventory Response=> ' . json_encode($res), 'info', $logFile);
                        if (isset($res['success']) && $res['success']) {
                            $productComponent->unsetError(array_keys($instantInventory), $data['data']['params'], $container_ids, 'inventory');

                            $productComponent->removeTag(array_keys($instantInventory), [Helper::PROCESS_TAG_INVENTORY_SYNC], $targetShopId, $sourceShopId, $userId, [], []);
                            $this->saveFeedInDb($instantInventory, 'inventory', $container_ids, $data['data']['params'], $alreadySavedActivities);
                            if(!empty($res['error'])){

                                $error = $res['error'];
                                $this->di->getLog()->logContent('InstantInv= ' . json_encode($error, true), 'info', $logFile);
                            }if(!empty($res['toBeSync'])){ 
                                $invtoSync = json_decode(base64_decode((string) $res['toBeSync']), true);
                                $feedContent = $feedContent + $invtoSync;
                            }
                        }

                        if(empty($feedContent)){
                            return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($instantInventory), 0, 0);
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
                        'localDelivery' => $merchantDataSourceProduct,
                        'process_type' => ($data['data']['params']['process_type'] ?? 'manual'),
                    ];

                    /* Home Call */
                    $response = $this->uploadInventoryDirect($specifics);
                    $this->di->getLog()->logContent('Feed Inventory Response=> ' . json_encode($response), 'info', $logFile);


                    /* Remote Call */
                    // $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                    // $response = $commonHelper->sendRequestToAmazon('inventory-upload', $specifics, 'POST');

                    if (isset($response['success']) && $response['success']) {
                        $feedActivtiy = [];
                        $this->saveFeedInDb($feedContent, 'inventory', $container_ids, $data['data']['params'], $alreadySavedActivities);
                    }

                    // $productSpecifics = $productComponent->init()
                    //     ->processResponse($response, $feedContent, \App\Amazon\Components\Common\Helper::PROCESS_TAG_INVENTORY_FEED, 'inventory', $amazonShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, \App\Amazon\Components\Common\Helper::PROCESS_TAG_INVENTORY_SYNC, $localDelivery, $merchantDataSourceProduct);

                    $baseParams['tag'] = Helper::PROCESS_TAG_INVENTORY_FEED;
                    $baseParams['unsetTag'] = Helper::PROCESS_TAG_INVENTORY_SYNC;

                    $productSpecifics = $marketplaceComponent->init($baseParams)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);

                    $addTagProductList = $productSpecifics['addTagProductList'];
                    $productErrorList = $productSpecifics['productErrorList'];
                    $responseLocal = $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);
                    if (!empty($responseLocal) && !empty($responseOnline)) {
                        $responseData = array_merge($responseLocal, $responseOnline);
                    } elseif (!empty($responseLocal)) {
                        $responseData = $responseLocal;
                    } elseif (!empty($responseOnline)) {
                        $responseData = $responseOnline;
                    }

                    return $responseData;
                }
                // $productSpecifics = $productComponent->init()
                //     ->processResponse($response, $feedContent, \App\Amazon\Components\Common\Helper::PROCESS_TAG_INVENTORY_SYNC, 'inventory', $amazonShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, false, $localDelivery, $merchantDataSourceProduct);
                $baseParams['tag'] = Helper::PROCESS_TAG_INVENTORY_SYNC;
                $baseParams['unsetTag'] = false;
                $marketplaceComponent->init($baseParams)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);
                return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);

            }
            // return error msg 'Selected target shop is not active'
            return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'Selected target shop is not active.', 0, 0, 0);

        }
        //return error msg 'One of rows data or target or source shops not found'
        return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'One of rows data or target or source shops not found.', 0, 0, 0);
    }
    public function getInventoryAttributeValues($product)
    {
        $jsonHelper = $this->di->getObjectManager()->get(CategoryAttributes::class);
        $jsonAttributes = $jsonHelper->getJsonCategorySettings($product);
        if ($jsonAttributes && !empty($jsonAttributes)) {
            return $jsonAttributes;
        }
        $categorySettings = [];
        // Variant Level Attribute Mapping/Editing
        // $product['edited']['edited_variant_attributes'];
        // $product['edited']['fulfillment_type'];
        if(isset($product['edited']['edited_variant_attributes']) && is_array($product['edited']['edited_variant_attributes'])) {
            foreach ($product['edited']['edited_variant_attributes'] as $mapping) {
                if(isset($mapping['amazon_attribute']) && $mapping['amazon_attribute'] === 'restock_date') {
                    $categorySettings['restock_date'] = $this->prepareAttributeValue($product, $mapping);
                    break;
                }
            }
        }

        if(isset($product['edited']['fulfillment_type'])) {
            $categorySettings['fulfillment_type'] = $product['edited']['fulfillment_type'];
        }
        // Parent Level Attribute Mapping/Editing
        // $product['parent_details']['edited']['category_settings']['attributes_mapping']['optional_attribute'];
        // $product['parent_details']['edited']['category_settings']['attributes_mapping']['required_attribute'];
        // $product['parent_details']['edited']['fulfillment_type'];
        if(!isset($categorySettings['restock_date'])) {
            if(isset($product['parent_details']['edited']['category_settings']['attributes_mapping'])) {
                $parentLevelAttributeMapping = $product['parent_details']['edited']['category_settings']['attributes_mapping'];
                if(isset($parentLevelAttributeMapping['optional_attribute'])) {
                    foreach ($parentLevelAttributeMapping['optional_attribute'] as $mapping) {
                        if(isset($mapping['amazon_attribute']) && $mapping['amazon_attribute'] === 'restock_date') {
                            $categorySettings['restock_date'] = $this->prepareAttributeValue($product, $mapping);
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
        // $product['profile_info']['attributes_mapping']['data']['optional_attribute'];
        // $product['profile_info']['attributes_mapping']['data']['required_attribute'];
        // $product['profile_info']['data']['<integer-index>']['data']['fulfillment_type'];
        if(!isset($categorySettings['restock_date']) && isset($product['profile_info']['attributes_mapping']['data'])) {
            $templateLevelAttributeMapping = $product['profile_info']['attributes_mapping']['data'];
            if($templateLevelAttributeMapping['optional_attribute']) {
                foreach ($templateLevelAttributeMapping['optional_attribute'] as $mapping) {
                    if(isset($mapping['amazon_attribute']) && $mapping['amazon_attribute'] === 'restock_date') {
                        $categorySettings['restock_date'] = $this->prepareAttributeValue($product, $mapping);
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

    public function prepareAttributeValue($product, $mapping)
    {
        // [amazon_attribute] => restock_date
        // [custom_text] => 28/04/2024
        // [recommendation] => 
        // [shopify_attribute] => 
        // [shopify_select] => custom
        // [meta_attribute_exist] => 

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

    public function resetClassPropeties(): void
    {
        $this->_globalInventoryConfig = null;
        $this->_defaultInventoryConfig = null;
        $this->_properties = null;
    }

    public function prepare($data, $getConfig = false)
    {
        $profilePresent = false;
        if (isset($data['profile_info']) && isset($data['profile_info']['data']) && !$getConfig) {
            $invTemplateData = [];
            foreach ($data['profile_info']['data'] as $value) {
                if ($value['data_type'] == 'inventory_settings') {
                    $invTemplateData = $value['data'];
                    $profilePresent = true;
                }
            }

            $invTemplateData['type'] = $data['profile'][0]['profile_id']['$oid'] ?? false;
        }

        if (!$profilePresent || $getConfig) {            
            $globalInvConfig = $this->getUserGlobalInventoryConfig();
            if(isset($globalInvConfig['global_setting_exists']) && $globalInvConfig['global_setting_exists']) {
                $profilePresent = true;
                $invTemplateData = $globalInvConfig['data'];
            }
        }

        if (!$profilePresent) {
            $defaultInvConfig = $this->getDefaultInventoryConfig();
            if(isset($defaultInvConfig['global_setting_exists']) && $defaultInvConfig['global_setting_exists']) {
                $profilePresent = true;
                $invTemplateData = $defaultInvConfig['data'];
            }
        }

        return $invTemplateData;
    }

    public function getUserGlobalInventoryConfig()
    {
        if(is_null($this->_globalInventoryConfig)) {
            $this->configObj->setGroupCode('inventory');
            $invConfigData = $this->configObj->getConfig();
            $invConfigData = json_decode(json_encode($invConfigData, true), true);

            $invSettingData = [];
            foreach ($invConfigData as $value) {
                $invSettingData[$value['key']] = $value['value'];
            }

            if(empty($invSettingData)) {
                $this->_globalInventoryConfig = [
                    'global_setting_exists' => false
                ];
            }
            else {
                $invSettingData['type'] = 'Product Settings';

                $this->_globalInventoryConfig = [
                    'global_setting_exists' => true,
                    'data' => $invSettingData
                ];
            }
        }

        return $this->_globalInventoryConfig;
    }

    public function getDefaultInventoryConfig()
    {
        if(is_null($this->_defaultInventoryConfig)) {
            $this->configObj->setUserId('all');
            $this->configObj->setGroupCode('inventory');
            $invConfigData = $this->configObj->getConfig();
            $invConfigData = json_decode(json_encode($invConfigData, true), true);

            // Reset user_id in config
            $this->configObj->setUserId($this->_user_id);

            $invSettingData = [];
            foreach ($invConfigData as $value) {
                $invSettingData[$value['key']] = $value['value'];
            }

            if(empty($invSettingData)) {
                $this->_defaultInventoryConfig = [
                    'global_setting_exists' => false
                ];
            }
            else {
                $invSettingData['type'] = 'default';

                $this->_defaultInventoryConfig = [
                    'global_setting_exists' => true,
                    'data' => $invSettingData
                ];
            }
        }

        return $this->_defaultInventoryConfig;
    }

    public function getActiveWarehouses($userId, $sourceShopId)
    {
        if(is_null($this->_properties) || !array_key_exists('warehouse_data', $this->_properties)) {
            $warehouses = [];

            $sourceShop = $this->getUserShop($userId, $sourceShopId);
            if(!empty($sourceShop)) {
                $warehouses = $sourceShop['warehouses'] ?? [];
            }

            $activeWarehouses = [];
            $allWarehouses = [];
            foreach ($warehouses as $warehouse) {
                $allWarehouses[] = $warehouse['id'];
                if (isset($warehouse['active']) && $warehouse['active']) {
                    $activeWarehouses[] = $warehouse['id'];
                }
            }

            $this->_properties['warehouse_data'] = [
                'active' => $activeWarehouses,
                'all' => $allWarehouses,
            ];
        }

        return $this->_properties['warehouse_data'];
    }

    private function hasDualFulfillmentEnabled(array $product): bool
    {
        if (!empty($product['edited']['fulfillment_type'])) {
            return $product['edited']['fulfillment_type'] === self::DUAL_FULFILLMENT_TYPE;
        }

        if (!empty($product['parent_details']['edited']['fulfillment_type'])) {
            return $product['parent_details']['edited']['fulfillment_type'] === self::DUAL_FULFILLMENT_TYPE;
        }

        // $profileInfo = $this->product['profile_info']['data'] ?? [];
        $profileInfo = $product['profile_info']['data'] ?? [];
        $profileFulfillmentType = null;

        foreach ($profileInfo as $item) {
            if (isset($item['data_type']) && $item['data_type'] === 'fulfillment_settings') {
                $profileFulfillmentType = $item['data']['fulfillment_type'] ?? null;
                break;
            }
        }


        if (!empty($profileFulfillmentType)) {
            return $profileFulfillmentType === self::DUAL_FULFILLMENT_TYPE;
        }

        return false;
    }

    private function getRegionFromMarketplaceId(string $marketplaceId): ?string
    {
        $marketplaceIdToRegionMap = [
            // North America
            'A2EUQ1WTGCTBG2' => 'NA', // Canada
            'ATVPDKIKX0DER'  => 'NA', // United States
            'A1AM78C64UM0Y8' => 'NA', // Mexico
            'A2Q3Y263D00KWC' => 'NA', // Brazil

            // Europe
            'A28R8C7NBKEWEA' => 'EU', // Ireland
            'A1RKKUPIHCS9HS' => 'EU', // Spain
            'A1F83G8C2ARO7P' => 'EU', // United Kingdom
            'A13V1IB3VIYZZH' => 'EU', // France
            'AMEN7PMS3EDWL'  => 'EU', // Belgium
            'A1805IZSGTT6HS' => 'EU', // Netherlands
            'A1PA6795UKMFR9' => 'EU', // Germany
            'APJ6JRA9NG5V4'  => 'EU', // Italy
            'A2NODRKZP88ZB9' => 'EU', // Sweden
            'AE08WJ6YKNBMC'  => 'EU', // South Africa
            'A1C3SOZRARQ6R3' => 'EU', // Poland
            'ARBP9OOSHTCHU'  => 'EU', // Egypt
            'A33AVAJ2PDY3EV' => 'EU', // Turkey
            'A17E79C6D8DWNP' => 'EU', // Saudi Arabia
            'A2VIGQ35RCS4UG' => 'EU', // UAE
            'A21TJRUUN4KGV'  => 'IN', // India

            // Far East
            'A19VAU5U5O7RUS' => 'FE', // Singapore
            'A39IBJ37TRP1C6' => 'FE', // Australia
            'A1VC38T7YXB528' => 'JP', // Japan
        ];
        return $marketplaceIdToRegionMap[$marketplaceId] ?? null;
    }

    private function getFbaChannelCode($marketplaceId): ?string
    {
        $region = $this->getRegionFromMarketplaceId($marketplaceId);
        return $region ? "AMAZON_{$region}" : null;
    }

    public function uploadInventoryDirect($data)
    {
        try {
                $feedContent = $data['feedContent'];
                $feedContent = json_decode(base64_decode((string) $feedContent), true);
                $errors = [];
                $marketplaceId = $data['marketplace_id'];
                $result = [];
                if (is_array($feedContent) && !empty($feedContent) || isset($data['enable'])) {
                     $envelopeArray = $this->prepareFeedData($feedContent, 'Online_Selling');

                        $message = $envelopeArray['envelope'];
                        $sourceProductIds = $envelopeArray['envelopeData'];
                        if (!empty($message) && !empty($sourceProductIds)) {
                            $dynamoData = [];
                            $dynamoTable = 'amazon_inventory_sync_spapi';
                            if (isset($data['unified_json_feed'])) {
                                $dynamoTable = 'amazon_unified_json_listing_sync_spapi';
                            }
                            if(isset($data['unique_table'])) {
                                $dynamoTable = 'amazon_inventory_sync_spapi';

                            }
                           $subscriptionKey = isset($this->di->getConfig()->get('amazon_subscription_key_by_action')['inventory']) ? $this->di->getConfig()->get('amazon_subscription_key_by_action')['inventory'] : null;
                           if(empty($subscriptionKey)) {
                            return [
                                'success' => false,
                                'data' => $data,
                                'message' => 'Subscription key not found'
                            ];
                           }
                            $dynamoObj = $this->di->getObjectManager()->get('\App\Connector\Components\Dynamo');
                            $dynamoObj->setTable($dynamoTable);
                            $dynamoObj->setUniqueKeys(['remote_shop_id', 'source_identifier', 'action']);
                            $dynamoObj->setTableUniqueColumn('id');
                            $sentToDynamo = [];
                            $preparedResponseData = [];
                            foreach ($message as $value) {
                                $sentToDynamo[$sourceProductIds[$value['sku']]] = [
                                    'home_shop_id' => $data['home_shop_id'],
                                    'remote_shop_id' => $data['shop_id'],
                                    'source_shop_id' => $data['sourceShopId'],
                                    'source_identifier' => $sourceProductIds[$value['sku']],
                                    'messageId' => $value['messageId'],
                                    'action' => 'inventory',
                                    'feedContent' => json_encode($value),
                                    'user_id' => $data['user_id'],
                                    'SK' => $subscriptionKey,
                                    'contentType' => 'JSON',
                                    'timestamp' => $feedContent[$sourceProductIds[$value['sku']]]['time'] ?? ""

                                ];
                                if (isset($data['process_type'])) {
                                    $sentToDynamo[$sourceProductIds[$value['sku']]]['process_type'] =  $data['process_type'];
                                }
                                $dynamoData[$sourceProductIds[$value['sku']]] = $sentToDynamo[$sourceProductIds[$value['sku']]];
                                $preparedResponseData[$sourceProductIds[$value['sku']]] = [
                                    'success' => true,
                                    "message" => "Data inserted successfully!!"
                                ];
                            }
                            if(empty($sentToDynamo)) {
                                return [
                                    'success' => false,
                                    'data' => $data,
                                    'message' => 'Unable to prepare sendToDynamo data'
                                ];
                            }
                            $dynamoResponse = $dynamoObj->save($sentToDynamo);
                            if(isset($dynamoResponse['success']) && !$dynamoResponse['success']) {
                                return [
                                    'success' => false,
                                    'data' => $data,
                                    'message' => 'Unable to save data to Dynamo',
                                    'dynamoData' => $dynamoData,
                                    'dynamoResponse' => $dynamoResponse
                                ];
                            }
                            $result[$marketplaceId] = [
                                'success' => true,
                                'error' => $errors,
                                'response' => $preparedResponseData,
                                'dynamoData' => $dynamoData
                            ];
                            return [
                                'success' => true,
                                'data' => $data,
                                'result' => $result
                            ];
                        } else {
                            if (isset($envelopeArray['error'])) {
                                $errorMsg = $envelopeArray['error'];
                            } else {
                                $errorMsg = 'Envelope not created';
                            }
                            $result[$marketplaceId] = [
                                'success' => false,
                                'data' => $data,
                                'msg' => $errorMsg
                            ];
                        }
                }

                return [
                    'success' => true,
                    'data' => $data,
                    'result' => $result
                ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'data' => $data,
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    private function prepareFeedData($feedContent, $feedType)
    {
        $envelope = null;
        $envelopeHeader = null;
        $envelopData = [];
        $error = false;
        $testfeedData = null;


        switch ($feedType) {
            case 'Online_Selling':
                $message = [];
                $envelope = [];
                $feedType = "JSON_LISTINGS_FEED";
                $i = 0;
                $sourceSku = [];
                foreach ($feedContent as $key => $value) {
                    $sourceSku[$value['SKU']] = $key;
                    $local_data = [];

                    if (empty($value['use_mli'])) {
                        $local_data[] = [
                            "fulfillment_channel_code" => 'DEFAULT',
                            "quantity" => $value['Quantity'],
                            "lead_time_to_ship_max_days" => $value['Latency']
                        ];
                        if (isset($value['RestockDate'])) {
                            $local_data[0]['restock_date'] = $value['RestockDate'];
                        }

                        if (!empty($value['fbaFulfillment']['enabled']) && !empty($value['fbaFulfillment']['channel_code'])) {
                            $local_data[] = [
                                'fulfillment_channel_code' => $value['fbaFulfillment']['channel_code']
                            ];
                        }
                    } else {
                        $local_data = $value['mli_inventory'] ?? [];
                    }

                    $trimmedInt = $this->getuniqueInteger();
                    $message[$i] = [
                        "messageId" => $trimmedInt,
                        "sku" => $value['SKU'],
                        "operationType" => "PARTIAL_UPDATE",
                        "productType" => "PRODUCT",
                        "attributes" => [
                            "fulfillment_availability" => $local_data
                        ]
                    ];
                    $i++;
                }

                return [
                    'envelope' => $message,
                    'envelopeHeader' => $envelopeHeader,
                    'envelopeData' => $sourceSku,
                    'error' => $error
                ];
        }

        return [
            'envelope' => $envelope,
            'envelopeHeader' => $envelopeHeader,
            'envelopeData' => $envelopData,
            'testfeedData' => $testfeedData,
            'error' => $error
        ];
    }

    private function getuniqueInteger()
    {
        $t = microtime();
        $time = explode(" ", $t);
        $usec = explode(".", $time[0]);
        $trimmedInt = (int) $usec[1];
        return $trimmedInt;
    }

    /**
     * Upload inventory for multiple marketplaces in a single remote API call.
     * Fetches product data and calculates inventory per target (data can differ per target shop).
     * Groups identical inventory data within the same region for optimized SPAPI calls.
     *
     * @param array $params Base params with source, user_id, source_product_ids, process_type
     * @param array $targets Array of target shop data, each with: shop_id, remote_shop_id, marketplace_id, home_shop_id
     * @param string $logFile Log file path
     * @return array Response with per-marketplace results
     */
    public function uploadMultiMarketplace(array $params, array $targets, string $logFile): array
    {
        $userId = $params['user_id'];
        $sourceShopId = $params['source']['shopId'];
        $sourceMarketplace = $params['source']['marketplace'];
        $time = date(DATE_ISO8601);
        $processType = $params['process_type'] ?? 'automatic';

        $this->di->getLog()->logContent('uploadMultiMarketplace: started for ' . count($targets) . ' targets', 'info', $logFile);

        // Phase 1: Group targets by seller_id + region
        $groupedTargets = [];
        foreach ($targets as $target) {
            $amazonShop = $this->getUserShop($userId, $target['shop_id']);
            if (empty($amazonShop) || ($amazonShop['warehouses'][0]['status'] ?? '') !== 'active') {
                $this->di->getLog()->logContent('uploadMultiMarketplace: shop inactive for target ' . $target['shop_id'], 'info', $logFile);
                continue;
            }
            $sellerId = $amazonShop['warehouses'][0]['seller_id'] ?? '';
            $region = $this->getRegionFromMarketplaceId($target['marketplace_id']) ?? 'unknown';
            $groupKey = $sellerId . '|' . $region;
            if (!isset($groupedTargets[$groupKey])) {
                $groupedTargets[$groupKey] = [
                    'targets' => [],
                    'remote_shop_id' => $target['remote_shop_id'],
                ];
            }
            if ($groupedTargets[$groupKey]['remote_shop_id'] !== $target['remote_shop_id']) {
                $this->di->getLog()->logContent(
                    'uploadMultiMarketplace: WARNING - group ' . $groupKey
                    . ' has mixed remote_shop_ids: ' . $groupedTargets[$groupKey]['remote_shop_id']
                    . ' vs ' . $target['remote_shop_id'],
                    'warning', $logFile
                );
            }
            $groupedTargets[$groupKey]['targets'][] = $target;
        }

        $this->di->getLog()->logContent('uploadMultiMarketplace: grouped into ' . count($groupedTargets) . ' groups: ' . implode(', ', array_keys($groupedTargets)), 'info', $logFile);

        if (empty($groupedTargets)) {
            $this->di->getLog()->logContent('uploadMultiMarketplace: no active targets found', 'info', $logFile);
            return ['success' => false, 'message' => 'No active targets found'];
        }

        $productProfile = $this->di->getObjectManager()->get('\App\Connector\Components\Profile\GetProductProfileMerge');
        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        $marketplaceComponent = $this->di->getObjectManager()->get(Marketplace::class);

        $allResults = [];

        // Phase 2: Process each group — calculate and group by inventory values in single pass
        foreach ($groupedTargets as $groupKey => $group) {
            $sellerId = explode('|', $groupKey)[0] ?? '';
            $region = explode('|', $groupKey)[1] ?? '';

            // Single pass: calculate inventory per product per target, group directly by inventoryGroupKey
            $inventoryGroups = [];
            $perMarketplaceMetadata = [];
            $targetCount = 0;
            $productCount = 0;

            foreach ($group['targets'] as $target) {
                $targetShopId = $target['shop_id'];
                $marketplaceId = $target['marketplace_id'];

                $targetParams = $params;
                $targetParams['target'] = [
                    'marketplace' => 'amazon',
                    'shopId' => $targetShopId,
                ];
                $targetParams['projectData'] = $this->getProjectionKeys();

                $productData = $productProfile->getProductsByProductIds($targetParams, true);
                $products = $productData['data']['rows'] ?? [];
                $payload['data']['params'] = $targetParams;
                $this->init($payload);

                if (empty($products)) {
                    $this->di->getLog()->logContent('uploadMultiMarketplace: no products found for target ' . $targetShopId, 'info', $logFile);
                    continue;
                }

                $amazonShop = $this->getUserShop($userId, $targetShopId);
                if (empty($amazonShop) || ($amazonShop['warehouses'][0]['status'] ?? '') !== 'active') {
                    continue;
                }

                $allParentDetails = $productData['data']['all_parent_details'] ?? [];
                $allProfileInfo = $productData['data']['all_profile_info'] ?? [];
                $sourceProductIdsList = array_values(array_unique(array_column($products, 'source_product_id')));
                $fbaListings = $this->fetchFBAListings($userId, $targetShopId, $sourceProductIdsList);

                $targetCount++;

                foreach ($products as $product) {
                    $product = json_decode(json_encode($product), true);
                    if ($product['type'] == 'variation') {
                        continue;
                    }

                    if (isset($product['parent_details']) && is_string($product['parent_details'])) {
                        $product['parent_details'] = $allParentDetails[$product['parent_details']] ?? [];
                    }
                    if (isset($product['profile_info']['$oid'])) {
                        $product['profile_info'] = $allProfileInfo[$product['profile_info']['$oid']] ?? [];
                    }

                    $hasDualFulfillmentEnabled = $this->hasDualFulfillmentEnabled($product);

                    if (isset($fbaListings[$product['source_product_id']])) {
                        if ($fbaListings[$product['source_product_id']] !== 'DEFAULT' && !$hasDualFulfillmentEnabled) {
                            continue;
                        }
                    } else {
                        if (isset($product['marketplace'])) {
                            $productStatus = '';
                            foreach ($product['marketplace'] as $marketplace) {
                                if (isset($marketplace['target_marketplace'], $marketplace['shop_id']) && $marketplace['target_marketplace'] == 'amazon' && $marketplace['shop_id'] == $targetShopId) {
                                    $productStatus = $marketplace['status'] ?? '';
                                    break;
                                }
                            }
                        } else {
                            $productStatus = $product['edited']['status'] ?? '';
                        }

                        if ($productStatus != Helper::PRODUCT_STATUS_UPLOADED) {
                            continue;
                        }
                    }

                    $invTemplateData = $this->prepare($product);
                    $calculateResponse = $this->calculate($product, $invTemplateData, $sourceMarketplace, false);

                    if (empty($calculateResponse) || isset($calculateResponse['error'])) {
                        continue;
                    }

                    if (isset($calculateResponse['Quantity']) && $calculateResponse['Quantity'] < 0) {
                        $calculateResponse['Quantity'] = 0;
                    }

                    if (!empty($product['edited']) && array_key_exists('dual_listing', $product['edited'])) {
                        $hasDualFulfillmentEnabled = $product['edited']['dual_listing'];
                    }

                    $fbaChannelCode = null;
                    if ($hasDualFulfillmentEnabled) {
                        $fbaChannelCode = $this->getFbaChannelCode($amazonShop['warehouses'][0]['marketplace_id']);
                    }

                    if (!isset($calculateResponse['Latency'])) {
                        $calculateResponse['Latency'] = "2";
                    }

                    $feedUniqueId = $product['_id'];
                    if ($feedUniqueId instanceof \MongoDB\BSON\ObjectId || !empty($feedUniqueId['$oid'])) {
                        $feedUniqueId = $feedUniqueId['$oid'] ?? (string) $feedUniqueId;
                    }

                    $feedEntry = [
                        'Id' => $feedUniqueId,
                        'SKU' => (string) $calculateResponse['SKU'],
                        'Quantity' => $calculateResponse['Quantity'],
                        'Latency' => $calculateResponse['Latency'],
                        'time' => $time,
                        'processed' => $processType,
                        'fbaFulfillment' => [
                            'enabled' => $hasDualFulfillmentEnabled,
                            'channel_code' => $fbaChannelCode
                        ]
                    ];

                    $sourceProductId = $product['source_product_id'];

                    // Build grouping key from actual values: seller_id|region|SKU|Quantity|Latency
                    $inventoryGroupKey = $sellerId . '|' . $region . '|' . $calculateResponse['SKU'] . '|' . $calculateResponse['Quantity'] . '|' . $calculateResponse['Latency'];

                    // Directly accumulate into inventory groups (no intermediate structure needed)
                    if (!isset($inventoryGroups[$inventoryGroupKey])) {
                        $inventoryGroups[$inventoryGroupKey] = [
                            'marketplace_ids' => [],
                            'feedContent' => [],
                        ];
                        $productCount++;
                    }
                    if (!in_array($marketplaceId, $inventoryGroups[$inventoryGroupKey]['marketplace_ids'])) {
                        $inventoryGroups[$inventoryGroupKey]['marketplace_ids'][] = $marketplaceId;
                    }
                    $inventoryGroups[$inventoryGroupKey]['feedContent'][$sourceProductId] = $feedEntry;

                    // Store per-marketplace metadata for response processing (not sent to remote)
                    if (!isset($perMarketplaceMetadata[$marketplaceId])) {
                        $perMarketplaceMetadata[$marketplaceId] = [
                            'feedContent' => [],
                            'container_ids' => [],
                            'alreadySavedActivities' => [],
                            'target_shop_id' => $targetShopId,
                        ];
                    }
                    $perMarketplaceMetadata[$marketplaceId]['feedContent'][$sourceProductId] = $feedEntry;
                    $perMarketplaceMetadata[$marketplaceId]['container_ids'][$sourceProductId] = $product['container_id'];
                    $perMarketplaceMetadata[$marketplaceId]['alreadySavedActivities'][$sourceProductId] = $product['edited']['last_activities'] ?? [];
                }

                $this->resetClassPropeties();
            }

            $this->di->getLog()->logContent(
                'uploadMultiMarketplace: calculated and grouped ' . $productCount . ' inventory groups across ' . $targetCount . ' targets in group ' . $groupKey,
                'info', $logFile
            );

            if (empty($inventoryGroups)) {
                $this->di->getLog()->logContent('uploadMultiMarketplace: no feed content for group ' . $groupKey, 'info', $logFile);
                continue;
            }

            $this->di->getLog()->logContent(
                'uploadMultiMarketplace: using remote_shop_id=' . $group['remote_shop_id'] . ' for seller+region group ' . $groupKey,
                'info', $logFile
            );

            // Send one request per inventory group to remote, process response immediately
            $groupIdx = 0;
            foreach ($inventoryGroups as $invGroupKey => $invGroup) {
                $this->di->getLog()->logContent(
                    'uploadMultiMarketplace: group ' . $groupIdx . ': marketplace_ids=[' . implode(', ', $invGroup['marketplace_ids']) . '], products=' . count($invGroup['feedContent']) . ', groupKey=' . $invGroupKey,
                    'info', $logFile
                );

                $payload = [
                    'user_id' => $userId,
                    'source_shop_id' => $sourceShopId,
                    'shop_id' => $group['remote_shop_id'],
                    'marketplace_ids' => $invGroup['marketplace_ids'],
                    'feedContent' => base64_encode(json_encode($invGroup['feedContent'])),
                    'grouped_by_inventory' => true,
                ];

                $this->di->getLog()->logContent(
                    'uploadMultiMarketplace: sending group ' . $groupIdx . ' to remote for seller+region group ' . $groupKey,
                    'info', $logFile
                );

                $response = $commonHelper->sendRequestToAmazon('instant-inventory-multi-marketplace', $payload, 'POST');

                $this->di->getLog()->logContent(
                    'uploadMultiMarketplace: response for group ' . $groupIdx . ' of ' . $groupKey . ' = ' . json_encode($response),
                    'info', $logFile
                );

                // Process response for this group immediately
                if (isset($response['success']) && $response['success'] && isset($response['results'])) {
                    foreach ($response['results'] as $marketplaceId => $mpResult) {
                        $meta = $perMarketplaceMetadata[$marketplaceId] ?? null;
                        if (!$meta) {
                            continue;
                        }

                        if (isset($mpResult['success']) && $mpResult['success']) {
                            $targetParams = [
                                'user_id' => $userId,
                                'target' => ['shopId' => $meta['target_shop_id'], 'marketplace' => 'amazon'],
                                'source' => ['shopId' => $sourceShopId, 'marketplace' => $sourceMarketplace],
                            ];
                            $this->saveFeedinDb($meta['feedContent'], 'inventory', $meta['container_ids'], $targetParams, $meta['alreadySavedActivities']);
                            $this->di->getLog()->logContent('uploadMultiMarketplace: saved activity for marketplace ' . $marketplaceId, 'info', $logFile);
                        }

                        if (!empty($mpResult['error'])) {
                            $this->di->getLog()->logContent('uploadMultiMarketplace: errors for marketplace ' . $marketplaceId . ' = ' . json_encode($mpResult['error']), 'info', $logFile);
                        }

                        if (!empty($mpResult['toBeSync'])) 
                        {
                             $targetParams = [
                                'user_id' => $userId,
                                'target' => ['shopId' => $meta['target_shop_id'], 'marketplace' => 'amazon'],
                                'source' => ['shopId' => $sourceShopId, 'marketplace' => $sourceMarketplace],
                            ];
                             $invtoSync = json_decode(base64_decode((string) $mpResult['toBeSync']), true);
                            
                            $specifics = [
                                'ids' => array_keys($invtoSync),
                                'home_shop_id' => $meta['target_shop_id'],
                                'marketplace_id' => $marketplaceId,
                                'sourceShopId' => $sourceShopId,
                                'shop_id' => $group['remote_shop_id'],
                                'feedContent' => base64_encode(json_encode($invtoSync)),
                                'user_id' => $userId,
                                'operation_type' => 'Update',
                                'process_type' => ($data['data']['params']['process_type'] ?? 'manual'),
                            ];
                                /* Home Call */
                                $res = $this->uploadInventoryDirect($specifics);
                                // print_r($res);die;
                                if (isset($res['success']) && $res['success']) {
                                    $feedActivtiy = [];
                                    $this->saveFeedInDb($invtoSync, 'inventory', $meta['container_ids'], $targetParams, $meta['alreadySavedActivities']);
                                }
                                $baseParams['tag'] = Helper::PROCESS_TAG_INVENTORY_FEED;
                                $baseParams['unsetTag'] = Helper::PROCESS_TAG_INVENTORY_SYNC;
                                $baseParams = [
                                'user_id' => $userId,
                                'tag' => Helper::PROCESS_TAG_INVENTORY_FEED,
                                'type' => 'inventory',
                                'target' => [
                                    'shopId' => $meta['target_shop_id'],
                                    'marketplace' => 'amazon'
                                ],
                                'source' => [
                                    'shopId' => $sourceShopId,
                                    'marketplace' => $sourceMarketplace
                                ],
                                'marketplaceId' => $marketplaceId,
                                'unsetTag' => Helper::PROCESS_TAG_INVENTORY_SYNC
                            ];

                            $productSpecifics = $marketplaceComponent->init($baseParams)->prepareAndProcessSpecifices($res, [], $invtoSync, $products);
                             
                             
                            // print_r($invtoSync);die;
                            // print_r($mpResult['toBeSync']);die;
                            $this->di->getLog()->logContent('uploadMultiMarketplace: toBeSync for marketplace ' . $marketplaceId, 'info', $logFile);
                        }
                    }
                    $allResults = array_merge($allResults, $response['results']);
                } else {
                    $this->di->getLog()->logContent(
                        'uploadMultiMarketplace: request FAILED for group ' . $groupIdx . ' of ' . $groupKey
                        . ', affected marketplaces: ' . implode(', ', $invGroup['marketplace_ids'])
                        . ', response: ' . json_encode($response),
                        'error', $logFile
                    );
                }

                $groupIdx++;
            }
        }

        return ['success' => !empty($allResults), 'results' => $allResults];
    }

    /**
     * Get projection keys for product data fetching (lightweight query).
     */
    private function getProjectionKeys(): array
    {
        return [
            'additional_images' => 0,
            'barcode' => 0,
            'brand' => 0,
            'compare_at_price' => 0,
            'created_at' => 0,
            'description' => 0,
            'grams' => 0,
            'handle' => 0,
            'is_imported' => 0,
            'low_sku' => 0,
            'main_image' => 0,
            'price' => 0,
            'product_type' => 0,
            'seo' => 0,
            'source_created_at' => 0,
            'tags' => 0,
            'source_updated_at' => 0,
            'template_suffix' => 0,
            'variant_attributes' => 0,
            'variant_image' => 0,
            'weight' => 0,
            'weight_unit' => 0,
            'marketplace' => 0,
            'position' => 0,
            'published_at' => 0,
            'taxable' => 0,
            'variant_title' => 0
        ];
    }
}
