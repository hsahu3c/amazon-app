<?php 
namespace App\Amazon\Components\Product;

use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\Listings\CategoryAttributes;
use App\Amazon\Components\Product\Inventory;

class AmazonInventory extends Inventory
{
    private ?array $_globalInventoryConfig = null;

    private ?array $_defaultInventoryConfig = null;

    private ?array $_config = null;

    public function fetchFBAListings($userId, $targetShopId, $sourceProductId)
    {
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
            $localSellingQuantity = [];
            $instantInventory = false;
            $merchantDataSourceProduct = false;
            $merchantName = [];

            if ($amazonShop['warehouses'][0]['status'] == 'active') {
                $products = $data['data']['rows'];

                if(!empty($products)) {
                    $sourceProductIdsList = array_unique(array_column($products, 'source_product_id'));
                    // print_r($sourceProductIdsList);die;
                    $fbaListings = $this->fetchFBAListings($userId, $targetShopId, $sourceProductIdsList);
                    // echo '<pre>';print_r($fbaListings);die;

                    foreach ($products as $product)
                    {
                        if ($product['type'] == 'variation') {
                            //need to discuss what to return for variant parent product as variant parent's inv will not sync to amazon
                            continue;
                        }

                        if(isset($fbaListings[$product['source_product_id']])) {
                            if($fbaListings[$product['source_product_id']] !== 'DEFAULT') {
                                $productErrorList[$product['source_product_id']] = ['AmazonError124'];
                                continue;
                            }
                            // Proceed to sync inventory

                        }
                        else {
                            $marketplaceData = $product['marketplace'];
                            $productStatus = '';
                            foreach ($marketplaceData as $marketplace) {
                                if (isset($marketplace['target_marketplace'], $marketplace['shop_id']) && $marketplace['target_marketplace'] == $targetMarketplace && $marketplace['shop_id'] == $targetShopId) {
                                    $productStatus = $marketplace['status'] ?? '';
                                    break;
                                }
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
                            $globalInvConfig = $this->getGlobalInventoryConfig();
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
                                    $res = $configHelper->saveConfigData($configData);
                                }

                                $productErrorList[$product['source_product_id']] = [$calculateResponse['error']];
                            }
                            else {
                                if (isset($calculateResponse['Quantity']) && $calculateResponse['Quantity'] < 0) {
                                    $calculateResponse['Quantity'] = 0;
                                }

                                if (!$calculateResponse['Latency']) {
                                    $calculateResponse['Latency'] = "2"; //if latency is less then 0 , then giving the default value
                                }

                                if (isset($calculateResponse['Quantity']) && $calculateResponse['Quantity'] <= 3 && !$localDelivery) {
                                    $instantInventory[$product['source_product_id']] = [
                                        'Id' => $product['_id'],
                                        'SKU' => $calculateResponse['SKU'],
                                        'Quantity' => $calculateResponse['Quantity'],
                                        'Latency' => $calculateResponse['Latency'],
                                        'time' => $time,
                                    ];
                                }
                                else {

                                    $feedContent[$product['source_product_id']] = [
                                        'Id' => $product['_id'],
                                        'SKU' => $calculateResponse['SKU'],
                                        'Quantity' => $calculateResponse['Quantity'],
                                        'Latency' => $calculateResponse['Latency'],
                                        'time' => $time,
                                    ];

                                    $inventoryAttribute = $this->getInventoryAttributeValues($product);
                                    if (isset($inventoryAttribute['restock_date'])) {
                                        $feedContent[$product['source_product_id']]['RestockDate'] = $inventoryAttribute['restock_date'];

                                    }
                                }
                            }
                        }
                        else {
                            $inventoryDisabledErrorProductList[] = $product['source_product_id'];
                            $productErrorList[$product['source_product_id']] = ['AmazonError123'];
                        }
                    }
                }

                $this->resetClassPropeties();
                print_r($feedContent);print_r($inventoryDisabledErrorProductList);print_r($productErrorList);die;

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
        $this->_config = null;
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
            $globalInvConfig = $this->getGlobalInventoryConfig();
            if(isset($globalInvConfig['global_setting_exists']) && $globalInvConfig['global_setting_exists']) {
                $profilePresent = true;
                $invTemplateData = $globalInvConfig['data'];
            }
        }

        if (!$profilePresent) {
            $defaultInvConfig = getDefaultInventoryConfig();
            if(isset($defaultInvConfig['global_setting_exists']) && $defaultInvConfig['global_setting_exists']) {
                $profilePresent = true;
                $invTemplateData = $defaultInvConfig['data'];
            }
        }

        return $invTemplateData;
    }

    public function getGlobalInventoryConfig()
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
        if(is_null($this->_config) || !array_key_exists('warehouse_data', $this->_config)) {
            $warehouses = [];
            if($this->di->getUser()->id === $userId && !empty($this->di->getUser()->shops)) {
                $sourceShop = [];
                $shops = $this->di->getUser()->shops;
                foreach ($shops as $shop) {
                    if($shop['_id'] == $sourceShopId) {
                        $sourceShop = $shop;
                        break;
                    }
                }

                $warehouses = $sourceShop['warehouses'] ?? [];
            }
            else {
                $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $sourceShop = $userDetails->getShop($sourceShopId, $userId);
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

            $this->_config['warehouse_data'] = [
                'active' => $activeWarehouses,
                'all' => $allWarehouses,
            ];
        }

        return $this->_config['warehouse_data'];
    }
}