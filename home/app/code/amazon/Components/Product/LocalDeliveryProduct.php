<?php
// namespace App\Amazon\Components\Product;

// use App\Amazon\Components\Template\Category;
// use App\Amazon\Components\Common\Barcode;
// use App\Amazon\Components\LocalDelivery;
// use Exception;
// use App\Amazon\Components\LocalDeliveryHelper;
// use App\Amazon\Components\ProductHelper;
// use MongoDB\BSON\ObjectId;
// use App\Core\Models\User;
// use App\Amazon\Components\Common\Helper;
// use App\Amazon\Components\Feed\Feed;
// use App\Connector\Models\ProductContainer;
// use App\Core\Components\Base as Base;

// class LocalDeliveryProduct extends Base
// {
//     private ?string $_user_id = null;

//     private $_user_details;

//     private $_baseMongo;

//     private $configObj;

//     public const OPERATION_TYPE_UPDATE = 'Update';

//     public const OPERATION_TYPE_DELETE = 'Delete';

//     public const OPERATION_TYPE_PARTIAL_UPDATE = 'PartialUpdate';

//     public const PRODUCT_ERROR_VALID = 'valid';

//     public const PRODUCT_TYPE_PARENT = 'parent';

//     public const PRODUCT_TYPE_CHILD = 'child';

//     public const DEFAULT_MAPPING = [
//         'item_sku' => 'sku',
//         'sku' => 'sku',
//         // 'brand_name' => 'brand',
//         'item_name' => 'title',
//         //    'manufacturer' => 'brand',
//         'standard_price' => 'price',
//         'price' => 'price',
//         'quantity' => 'quantity',
//         'main_image_url' => 'main_image',
//         'product-id' => 'barcode',
//         'external_product_id' => 'barcode',
//         'product_description' => 'description',
//     ];

//     public const IMAGE_ATTRIBUTES = [
//         0 => 'other_image_url1',
//         1 => 'other_image_url2',
//         2 => 'other_image_url3',
//         3 => 'other_image_url4',
//         4 => 'other_image_url5',
//         5 => 'other_image_url6',
//         6 => 'other_image_url7',
//         7 => 'other_image_url8',
//     ];

//     public const HANDMADE_IMAGE_ATTRIBUTES = [
//         0 => 'pt1_image_url',
//         1 => 'pt2_image_url',
//         2 => 'pt3_image_url',
//         3 => 'pt4_image_url',
//         4 => 'pt5_image_url',
//         5 => 'pt6_image_url',
//         6 => 'pt7_image_url',
//         7 => 'pt8_image_url',
//     ];

//     public $categoryAttributes = [];

//     public $currency = false;

//     public function init($request = [])
//     {
//         if (isset($request['user_id'])) {
//             $this->_user_id = (string) $request['user_id'];
//         } else {
//             $this->_user_id = (string) $this->di->getUser()->id;
//         }

//         $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
//         $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
//         $this->configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
//         if (isset($request['data']['source_shop_id'], $request['data']['source_marketplace'])) {
//             $this->source_marketplace = $request['data']['source_marketplace'];
//             $this->source_shop_id = (string)$request['data']['source_shop_id'];
//         }

//         if (isset($request['data']['target_shop_id'], $request['data']['target_marketplace'])) {
//             $this->target_marketplace = $request['data']['target_marketplace'];
//             $this->target_shop_id = (string)$request['data']['target_shop_id'];
//         }

//         if (isset($this->source_shop_id, $this->target_shop_id, $this->source_marketplace, $this->target_marketplace)) {
//             $obj = [
//                 'Ced-Source-Id' => $this->source_shop_id,
//                 'Ced-Source-Name' => $this->source_marketplace,
//                 'Ced-Target-Id' => $this->target_shop_id,
//                 'Ced-Target-Name' => $this->target_marketplace
//             ];
//             $this->di->getObjectManager()->get('\App\Core\Components\Requester')->setHeaders($obj);
//         }

//         return $this;

//     }

//     public function getAmazonSubCategory(): void
//     {
//         print_r('hello');
//         die;
//     }

//     public function getUploadCategorySettings($product)
//     {
//         // print_r($product);die;
//         $categorySettings = false;
//         $amazonProduct = false;
//         $optionalAttributes = false;
//         $attributes = [];
//         if (isset($product['edited'])) {
//             $amazonProduct = $product['edited'];
//         }

//         if ($amazonProduct && isset($amazonProduct['category_settings'])) {

//             if (isset($amazonProduct['category_settings']['attributes_mapping'])) {
//                 $categorySettings = $amazonProduct['category_settings'];
//                 if (isset($amazonProduct['category_settings']['optional_attributes'])) {
//                     $categorySettings['optional_variant_attributes'] = $amazonProduct['optional_attributes'];
//                 }

//                 if (isset($amazonProduct['variation_theme_attribute_name'])) {
//                     $categorySettings['variation_theme_attribute_name'] = $amazonProduct['variation_theme_attribute_name'];
//                 }

//                 if (isset($amazonProduct['variation_theme_attribute_value'])) {
//                     $categorySettings['variation_theme_attribute_value'] = $amazonProduct['variation_theme_attribute_value'];
//                 }
//             }

//             if (isset($amazonProduct['category_settings']['primary_category']) && isset($amazonProduct['category_settings']['sub_category'])) {
//                 $categorySettings['primary_category'] = $amazonProduct['category_settings']['primary_category'];
//                 $categorySettings['sub_category'] = $amazonProduct['category_settings']['sub_category'];
//                 $categorySettings['browser_node_id'] = $amazonProduct['category_settings']['browser_node_id'];
//                 $categorySettings['barcode_exemption'] = $amazonProduct['category_settings']['barcode_exemption'];
//                 if (isset($amazonProduct['edited_variant_attributes'])) {
//                     $optionalAttributes = $amazonProduct['edited_variant_attributes'];
//                 }

//                 if (isset($product['parent_details']['edited']['category_settings'])) {
//                     if (isset($product['parent_details']['edited']['category_settings']['attributes_mapping'])) {

//                             $op =[];
//                             $req = $product['parent_details']['edited']['category_settings']['attributes_mapping']['required_attribute'];
//                             $op = $product['parent_details']['edited']['category_settings']['attributes_mapping']['optional_attribute'];

//                             $req = json_decode(json_encode($req),true);
//                             $op = json_decode(json_encode($op),true);
//                             if(empty($op)){
//                                 $attributes = $req;
//                             }else{
//                                 $attributes = array_merge($req,$op);
//                             }

//                         $newAttribute = [];
//                         if ($optionalAttributes) {
//                             foreach ($attributes as $key => $attribute) {
//                                 foreach ($optionalAttributes as $optionalAttribute) {
//                                     if ($attribute['amazon_attribute'] == $optionalAttribute['amazon_attribute']) {
//                                         array_push($newAttribute, $optionalAttribute);
//                                         unset($attributes[$key]);
//                                     }

//                                     // else {
//                                     //     array_push($newAttribute, $attribute);
//                                     //     break;
//                                     // }
//                                 }
//                             }

//                             $newAttribute = array_merge($newAttribute , $attributes);
//                             $categorySettings['attributes_mapping']['required_attribute'] = $newAttribute;
//                         }
//                         else{
//                             $categorySettings['attributes_mapping']['required_attribute'] = $attributes;
//                         }
//                     }
//                 }

//             }
//         }

//         if(empty($categorySettings['variation_theme_attribute_name']) && isset($product['parent_details']['edited']['variation_theme_attribute_name'])){
//             $categorySettings['variation_theme_attribute_name']= $product['parent_details']['edited']['variation_theme_attribute_name'];
//         }

//         if (!$categorySettings && isset($product['profile_info']) && is_array($product['profile_info'])) {
//             $categorySettings = $product['profile_info']['category_settings'];
//             $categorySettings['attributes_mapping'] = $product['profile_info']['attributes_mapping']['data'];
//         }

//         // print_r($categorySettings);die;


//         return $categorySettings;
//     }

//     public function getPartialUpdateCategorySettings($product)
//     {

//         $categorySettings = false;
//         $amazonProduct = false;
//         $optionalAttributes = false;
//         $attributes = [];

//         if(isset($product['edited'])){

//             $amazonProduct = $product['edited'];
//         }

//         if ($amazonProduct && isset($amazonProduct['category_settings'])) {

//             if (isset($amazonProduct['category_settings']['attributes_mapping'])) {
//                 $categorySettings = $amazonProduct['category_settings'];
//                 if (isset($amazonProduct['category_settings']['optional_attributes'])) {
//                     $categorySettings['optional_variant_attributes'] = $amazonProduct['optional_attributes'];
//                 }

//                 if (isset($amazonProduct['variation_theme_attribute_name'])) {
//                     $categorySettings['variation_theme_attribute_name'] = $amazonProduct['variation_theme_attribute_name'];
//                 }

//                 if (isset($amazonProduct['variation_theme_attribute_value'])) {
//                     $categorySettings['variation_theme_attribute_value'] = $amazonProduct['variation_theme_attribute_value'];
//                 }
//             }

//             if (isset($amazonProduct['category_settings']['primary_category']) && isset($amazonProduct['category_settings']['sub_category']) && isset($amazonProduct['category_settings']['attributes_mapping'])) {
//                 $categorySettings['primary_category'] = $amazonProduct['category_settings']['primary_category'];
//                 $categorySettings['sub_category'] = $amazonProduct['category_settings']['sub_category'];
//                 $categorySettings['browser_node_id'] = $amazonProduct['category_settings']['browser_node_id'];
//                 $categorySettings['barcode_exemption'] = $amazonProduct['category_settings']['barcode_exemption'];
//                 if (isset($amazonProduct['edited_variant_attributes'])) {
//                     $optionalAttributes = $amazonProduct['edited_variant_attributes'];
//                 }

//                 if (isset($product['parent_details']['edited']['category_settings'])) {
//                     if (isset($product['parent_details']['edited']['category_settings']['attributes_mapping'])) {
//                         $op =[];
//                         $req = $product['parent_details']['edited']['category_settings']['attributes_mapping']['required_attribute'];
//                         $op = $product['parent_details']['edited']['category_settings']['attributes_mapping']['optional_attribute'];

//                         $req = json_decode(json_encode($req),true);
//                         $op = json_decode(json_encode($op),true);
//                         // print_r($req);die;
//                         if(empty($op)){
//                             $attributes = $req;
//                         }else{
//                             $attributes = array_merge($req,$op);

//                         }

//                         $newAttribute = [];
//                         if ($optionalAttributes) {
//                             foreach ($attributes as $attribute) {
//                                 foreach ($optionalAttributes as $optionalAttribute) {
//                                     if ($attribute['amazon_attribute'] == $optionalAttribute['amazon_attribute']) {
//                                         array_push($newAttribute, $optionalAttribute);
//                                     } else {
//                                         array_push($newAttribute, $attribute);
//                                         break;
//                                     }
//                                 }

//                             }

//                             $categorySettings['attributes_mapping']['required_attribute'] = $newAttribute;
//                         }
//                         else{
//                             $categorySettings['attributes_mapping']['required_attribute'] = $attributes;
//                         }
//                     }
//                 }

//             }

//         }

//         if (!$categorySettings && isset($product['profile_info']) && is_array($product['profile_info'])) {
//             $categorySettings = $product['profile_info']['category_settings'];
//             $categorySettings['attributes_mapping'] = $product['profile_info']['attributes_mapping']['data'];
//         }

//         return $categorySettings;
//     }

//     public function partialUpdate($data, $operationType = self::OPERATION_TYPE_PARTIAL_UPDATE)
//     {
//         $feedContent = [];

//         if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
//             $preparedContent = [];
//             $userId = $data['data']['params']['user_id'] ?? $this->_user_id;
//             $targetShopId = $data['data']['params']['target']['shopId'];
//             $sourceShopId = $data['data']['params']['source']['shopId'];
//             $targetMarketplace = $data['data']['params']['target']['marketplace'];
//             $sourceMarketplace = $data['data']['params']['source']['marketplace'];
//             $date = date('d-m-Y');
//             $logFile = "amazon/{$userId}/{$sourceShopId}/SyncProduct/{$date}.log";
//             // $this->di->getLog()->logContent('Receiving DATA = ' . print_r($data, true), 'info', $logFile);
//             $productErrorList = [];
//             $response = [];
//             $active = false;
//             $categorySettings = false;
//             $removeTagProductList = [];
//             $categoryErrorProductList = [];

//             $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
//             $targetShop = $user_details->getShop($targetShopId, $userId);
//             $sourceShop = $user_details->getShop($sourceShopId, $userId);

//             $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//             // $homeShopId = $shop['_id'];
//             $activeAccounts = [];
//             foreach ($targetShop['warehouses'] as $warehouse) {
//                 if ($warehouse['status'] == "active") {
//                     $activeAccounts = $warehouse;
//                 }
//             }

//             if (!empty($activeAccounts)) {
//                 $connector = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
//                 $currencyCheck = $connector->checkCurrency($userId, $targetMarketplace, $sourceMarketplace, $targetShopId, $sourceShopId);

//                 $this->configObj->setUserId($userId);
//                 $this->configObj->setTarget($targetMarketplace);
//                 $this->configObj->setTargetShopId($targetShopId);
//                 $this->configObj->setSourceShopId($sourceShopId);


//                 if (!$currencyCheck) {

//                     $this->configObj->setGroupCode('currency');
//                     $sourceCurrency = $this->configObj->getConfig('source_currency');
//                     $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);
//                     // $this->di->getLog()->logContent('sourceCurrency : '.json_encode($sourceCurrency), 'info', 'ProductUpload.log');

//                     $amazonCurrency = $this->configObj->getConfig('target_currency');
//                     $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);
//                     // $this->di->getLog()->logContent('amazonCurrency : '.json_encode($amazonCurrency), 'info', 'ProductUpload.log');

//                     if ($sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
//                         $currencyCheck = true;
//                     } else {
//                         $amazonCurrencyValue = $this->configObj->getConfig('target_value');
//                         $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
//                         if (isset($amazonCurrencyValue[0]['value'])) {
//                             $currencyCheck = $amazonCurrencyValue[0]['value'];
//                         }
//                     }
//                 }

//                 if ($currencyCheck) {
//                     $this->currency = $currencyCheck;
//                     $products = $data['data']['rows'];
//                     foreach ($products as $product) {

//                         if ($operationType == self::OPERATION_TYPE_PARTIAL_UPDATE) {
//                             $sync = $this->getSyncStatus($product);
//                             if (isset($sync['priceInvSyncList']) && $sync['priceInvSyncList']) {
//                                 $priceInvSyncList['source_product_ids'][] = $product['source_product_id'];
//                             }

//                             if (isset($sync['imageSyncProductList']) && $sync['imageSyncProductList']) {
//                                 $imageSyncProductList['source_product_ids'][] = $product['source_product_id'];
//                             }

//                             if (isset($sync['productSyncProductList']) && $sync['productSyncProductList']) {

//                                 $categorySettings = $this->getPartialUpdateCategorySettings($product);

//                                 // $firstChild = reset($childArray);
//                                 $productSyncProductList['source_product_ids'][] = $product['source_product_id'];
//                             }

//                         }

//                         $this->removeTag([$product['source_product_id']], [Helper::PROCESS_TAG_SYNC_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
//                         if ($categorySettings) {
//                             if (isset($product['parent_details'])) {
//                                 $parentAmazonProduct = $product['parent_details'];
//                                 if ($parentAmazonProduct && isset($parentAmazonProduct['edited']['parent_sku']) && $parentAmazonProduct['edited']['parent_sku'] != '') {
//                                     $productTypeParentSku = $parentAmazonProduct['edited']['parent_sku'];
//                                 } else if ($parentAmazonProduct && isset($parentAmazonProduct['sku']) && $parentAmazonProduct['sku'] != '') {
//                                     $productTypeParentSku = $parentAmazonProduct['sku'];
//                                 } else {
//                                     $productTypeParentSku = $parentAmazonProduct['source_product_id'];
//                                 }

//                                 $preparedContent = $this->setPartialUpdateContent($categorySettings, $product, $targetShopId, self::PRODUCT_TYPE_CHILD, $productTypeParentSku, $operationType);

//                             } else if ($product['type'] == "variation") {
//                                 $preparedContent = $this->setPartialUpdateContent($categorySettings, $product, $targetShopId, self::PRODUCT_TYPE_PARENT, false, $operationType);
//                                 if(empty($preparedContent))
//                                 {
//                                     continue;
//                                 }
//                             } else {
//                                 $preparedContent = $this->setPartialUpdateContent($categorySettings, $product, $targetShopId, false, false, $operationType);
//                                 if (in_array($product['source_product_id'], ['40475270840475', '40473394708635', '40473394675867', '40473394643099', '40473394577563'])) {
//                                     unset($preparedContent['variation_theme']);
//                                 }
//                             }

//                             if (isset($preparedContent['operation-type'])) {
//                                 $operationType = $preparedContent['operation-type'];
//                             }

//                             if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
//                                 $productErrorList[$product['source_product_id']] = $preparedContent['error'];
//                             } else {
//                                 unset($preparedContent['error']);
//                                 $feedContent[$categorySettings['primary_category']][$product['source_product_id']] =
//                                     $preparedContent;
//                                 $feedContent[$categorySettings['primary_category']]['category'] =
//                                     $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['category'];
//                                 $feedContent[$categorySettings['primary_category']]['sub_category'] =
//                                     $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['sub_category'];
//                                 $feedContent[$categorySettings['primary_category']]['barcode_exemption'] =
//                                     $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['barcode_exemption'];
//                             }
//                         } 
//                         else {
//                             $removeTagProductList[] = $product['source_product_id'];
//                             if(!$sync['productSyncProductList']){
//                                 $productErrorList[$product['source_product_id']] = ['Product Syncing is Disabled. Please Enable it from the Settings and Try Again'];
//                             }
//                             else{
//                                 $productErrorList[$product['source_product_id']] = ['Amazon Category is not selected. Click on Product to edit & select an Amazon Category to upload this Product.'];
//                             }
//                         }
//                     }

//                     $addTagProductList = [];
//                     // $this->di->getLog()->logContent('FeedContent= ' . print_r($feedContent, true), 'info', $logFile);
//                     if (!empty($feedContent)) {
//                         foreach ($feedContent as $content) {
//                             $specifics = [
//                                 'home_shop_id' => $targetShop['_id'],
//                                 'marketplace_id' => $targetShop['warehouses'][0]['marketplace_id'],
//                                 'shop_id' => $targetShop['remote_shop_id'],
//                                 'sourceShopId' => $sourceShopId,
//                                 'feedContent' => base64_encode(serialize($content)),
//                                 'user_id' => $userId,
//                                 'operation_type' => $operationType,
//                             ];

//                             $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');
//                             if ($response == null) {
//                                 $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');
//                             }

//                             // $this->di->getLog()->logContent('Response = ' . print_r($response, true), 'info', $logFile);
//                             $productSpecifics = $this->processResponse($response, $feedContent, Helper::PROCESS_TAG_UPDATE_FEED, 'product', $targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, Helper::PROCESS_TAG_SYNC_PRODUCT);
//                             $addTagProductList = array_merge($addTagProductList, $productSpecifics['addTagProductList']);
//                             $productErrorList = array_merge($productErrorList, $productSpecifics['productErrorList']);

//                         }

//                         return $this->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);
//                     }
//                     $productSpecifics = $this->processResponse($response, $feedContent, Helper::PROCESS_TAG_SYNC_PRODUCT, 'product', $targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList);
//                     return $this->setResponse(Helper::RESPONSE_SUCCESS, count($addTagProductList), 0, count($productErrorList), 0);

//                 }
//                 return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Currency of '. $sourceMarketplace .'and Amazon account are not same. Please fill currency settings from Global Price Adjustment in settings page.', 0, 0, 0);

//             }
//             return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0);

//         }
//         return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0);
//     }

//     public function setPartialUpdateContent($categorySettings, $product, $homeShopId, $type, $parentSku, $operationType)
//     {
//         $feedContent = [];

//         if (!empty($categorySettings)) {
//             $sourceMarketplace = $this->di->getRequester()->getSourceName();
//             $sourceSelect = $sourceMarketplace.'_select';
//             $product = json_decode(json_encode($product),true);
//             $barcodeExemption = $categorySettings['barcode_exemption'] ?? "";
//             $subCategory = $categorySettings['sub_category'] ?? "";
//             $category = $categorySettings['primary_category'] ?? "";
//             $valueMappedAttributes = $categorySettings['attributes_mapping']['value_mapping'] ?? [];
//             $requiredAttributes = $categorySettings['attributes_mapping']['required_attribute'] ?? [];
//             $optionalAttributes = $categorySettings['attributes_mapping']['optional_attribute'] ?? [];
//             $browseNodeId = $categorySettings['browser_node_id'] ?? "";
//             $attributes = [...(array) $requiredAttributes, ...(array) $optionalAttributes];
//             $variant_attributes = [];
//             // print_r($attributes);die;
//             // print_r($categorySettings);die;

//             if (isset($categorySettings['optional_variant_attributes'])) {
//                 $variant_attributes = array_merge($variant_attributes, $categorySettings['optional_variant_attributes']);
//             }

//             if (isset($categorySettings['variation_theme_attribute_name']) && $categorySettings['variation_theme_attribute_name']) {
//                 $variant_attributes['variation_theme'] = $categorySettings['variation_theme_attribute_name'];
//             }

//             if (isset($categorySettings['variation_theme_attribute_value']) && $categorySettings['variation_theme_attribute_value']) {
//                 $variant_attributes = array_merge($variant_attributes, $categorySettings['variation_theme_attribute_value']);
//             }

//             // Offer cannot be created for parent product
//             if ($product['type'] == 'variation' && $category == 'default') {
//                 return $feedContent;
//             }

//             $amazonProduct =false;
//             if(isset($product['edited'])){

//                 $amazonProduct = $product['edited'];
//             }

//             $categoryContainer = $this->di->getObjectManager()->get(Category::class);
//             $params = [
//                 'data' => [
//                     'category' => $category,
//                     'sub_category' => $subCategory,
//                     'browser_node_id' => $browseNodeId,
//                     'barcode_exemption' => $barcodeExemption,
//                     'browse_node_id' => $browseNodeId,
//                 ],
//                 'target' => [

//                     'shopId' => $homeShopId,
//                 ],

//             ];
//             // print_r($params);die;

//             if (isset($this->categoryAttributes[$category][$subCategory])) {
//                 $attributeArray = $this->categoryAttributes[$category][$subCategory];

//             } else {
//                 $allAttributes = $categoryContainer->init()->getAmazonAttributes($params, true);

//                 $attributeArray = [];

//                 if (isset($allAttributes['data'])) {
//                     $attributeArray = $categoryContainer->init()->moldAmazonAttributes($allAttributes['data']);

//                 }

//                 $this->categoryAttributes[$category][$subCategory] = $attributeArray;
//             }

//             $arrayKeys = array_column($attributes, 'amazon_attribute');
//             // print_r($arrayKeys);die("yes");
//             $mappedAttributeArray = array_combine($arrayKeys, $attributes);
//             $images = false;
//             if (isset($product['additional_images'])) {
//                 $images = $product['additional_images'];

//             }

//             $defaultMapping = self::DEFAULT_MAPPING;
//             if (!empty($mappedAttributeArray) && !empty($attributeArray)) {
//                 foreach ($attributeArray as $id => $attribute) {
//                     if ($id == 'main_image_url' && $this->_user_id == '61d29db38092370f1800a6a0') {
//                         continue;
//                     }

//                     $amazonAttribute = $id;
//                     $sourceAttribute = false;
//                     if (isset($defaultMapping[$id])) {
//                         $sourceAttribute = $defaultMapping[$id];
//                     } elseif (($type == self::PRODUCT_TYPE_CHILD || $id == 'variation_theme') && isset($variant_attributes[$id])) {
//                         $sourceAttribute = 'variant_custom';
//                     }
//                     elseif(isset($mappedAttributeArray[$id]) && isset($mappedAttributeArray[$id][$sourceSelect]) && $mappedAttributeArray[$id][$sourceSelect]=="custom"){
//                         $sourceAttribute = "custom";
//                     }
//                     elseif(isset($mappedAttributeArray[$id]) && isset($mappedAttributeArray[$id][$sourceSelect]) && $mappedAttributeArray[$id][$sourceSelect]=="recommendation"){
//                         $sourceAttribute = "recommendation";
//                     }  
//                     elseif (isset($mappedAttributeArray[$id])) {
//                         $sourceAttribute = $mappedAttributeArray[$id][$sourceMarketplace.'_attribute'];
//                     } elseif (isset($attributeArray[$id]['premapped_values']) && !empty($attributeArray[$id]['premapped_values'])) {
//                         $sourceAttribute = 'premapped_values';
//                     }

//                     // if ($id == 'brand_name' && isset($mappedAttributeArray[$id])) {
//                     //     $sourceAttribute = $mappedAttributeArray[$id]['shopify_attribute'];
//                     // }

//                     if ($id == 'product_description' && isset($mappedAttributeArray[$id])) {
//                         $sourceAttribute = $mappedAttributeArray[$id][$sourceMarketplace.'_attribute'];
//                     }

//                     $value = '';

//                     if ($sourceAttribute) {
//                         if ($sourceAttribute == 'recommendation') {
//                             $recommendation = $mappedAttributeArray[$id]['recommendation'];
//                             if ($recommendation == 'custom') {
//                                 $customText = $mappedAttributeArray[$id]['custom_text'];
//                                 $value = $customText;
//                             } else {
//                                 $value = $recommendation;
//                             }
//                         } elseif ($sourceAttribute == 'custom') {
//                             $customText = $mappedAttributeArray[$id]['custom_text'];
//                             $value = $customText;
//                         } elseif (isset($product[$sourceAttribute])) {
//                             $value = $product[$sourceAttribute];
//                         } elseif ($sourceAttribute == 'variant_custom') {
//                             $value = $variant_attributes[$id];
//                         } elseif ($sourceAttribute == "premapped_values") {
//                             $value = $attributeArray[$id]['premapped_values'];
//                         } 
//                         elseif (isset($product['variant_attributes'])&& $type == self::PRODUCT_TYPE_CHILD) {
//                             foreach( $product['variant_attributes'] as $variantatt ){
//                                 if($variantatt['key'] == $sourceAttribute){
//                                     $value = $variantatt['value'];
//                                 }
//                             }
//                         } 
//                         elseif (in_array($id, ['apparel_size', 'shirt_size', 'footwear_size_unisex', 'footwear_size', 'bottoms_size', 'skirt_size', 'shapewear_size']) && $type == self::PRODUCT_TYPE_PARENT && !empty($firstChild)) {
//                             if (isset($firstChild[$sourceAttribute])) {
//                                 $value = $firstChild[$sourceAttribute];
//                             } elseif (isset($firstChild['variant_attributes_values'][$sourceAttribute])) {
//                                 $value = $firstChild['variant_attributes_values'][$sourceAttribute];
//                             }
//                         }

//                         if ($sourceAttribute == 'sku' && $type == self::PRODUCT_TYPE_PARENT && $parentSku) {
//                             $value = $parentSku;
//                         }

//                         if ($id == 'main_image_url' && $type == self::PRODUCT_TYPE_CHILD && isset($product['variant_image'])) {
//                             $value = $product['variant_image'];
//                         }

//                         //                        if (in_array($id, ['apparel_size', 'shirt_size', 'footwear_size_unisex', 'footwear_size', 'bottoms_size','skirt_size', 'shapewear_size']) && $type == self::PRODUCT_TYPE_PARENT) {
//                         //                            if (in_array($this->_user_id, ['6116766b6bfb371e3d214126', '61263349f75b161bc92813e0', '6122ec836ede040a4345963e'])) {
//                         //                                $value = 5;
//                         //                            } elseif ($feedContent['footwear_size_class'] == 'Numeric' || $feedContent['footwear_size_class'] == 'Numeric Range' || $feedContent['footwear_size_class'] == 'Numero' || $feedContent['footwear_size_class'] == 'Numérique' || $feedContent['bottoms_size_class'] == 'Numeric' || $feedContent['apparel_size_class'] == 'Numeric' || $feedContent['skirt_size_class'] == 'Numeric') {
//                         //                                $value = 36;
//                         //                            }
//                         //                            elseif ($feedContent['shapewear_size_class'] == 'Age' || $feedContent['shirt_size_class'] == 'Age' || $feedContent['footwear_size_class'] == 'Age'  || $feedContent['bottoms_size_class'] == 'Age' || $feedContent['apparel_size_class'] == 'Age') {
//                         //                                $value = '4 Years';
//                         //                            }
//                         //                            else {
//                         //                                $value = 'M';
//                         //                            }
//                         //                        }

//                         if ($sourceAttribute == 'sku' && $type != self::PRODUCT_TYPE_PARENT) {
//                             if ($amazonProduct && isset($amazonProduct[$sourceAttribute]) && $amazonProduct[$sourceAttribute]) {
//                                 $value = $amazonProduct[$sourceAttribute];
//                             } elseif ($product && isset($product[$sourceAttribute]) && !empty($product[$sourceAttribute])) {
//                                 $value = $product[$sourceAttribute];
//                             } elseif (isset($product['source_product_id'])) {
//                                 $value = $product['source_product_id'];
//                             }
//                         }

//                         if ($sourceAttribute == 'barcode' && $amazonProduct && isset($amazonProduct[$sourceAttribute])) {
//                             $value = $amazonProduct[$sourceAttribute];
//                         }

//                         if ($sourceAttribute == 'barcode') {
//                             $value = str_replace("-", "", $value);
//                         }

//                         if ($sourceAttribute == 'description' && $amazonProduct && isset($amazonProduct[$sourceAttribute])) {
//                             $value = $amazonProduct[$sourceAttribute];
//                         }

//                         if ($sourceAttribute == 'title' && $amazonProduct && isset($amazonProduct[$sourceAttribute])) {
//                             $value = $amazonProduct[$sourceAttribute];
//                         }

//                         if ($sourceAttribute == 'price' &&  $type != self::PRODUCT_TYPE_PARENT) {
//                             //                            if ($amazonProduct && isset($amazonProduct[$sourceAttribute])) {
//                             //                                $value = $amazonProduct[$sourceAttribute];
//                             //                            } else {
//                             $productPrice = $this->di->getObjectManager()->get(Price::class);
//                             //   $currency = $productPrice->init()->getConversionRate();
//                             $currency = $this->currency;
//                             // print_r($currency);die("why");
//                             if ($currency) {
//                                 $priceTemplate = $productPrice->init()->prepare($product);
//                                 $priceList = $productPrice->init()->calculate($product, $priceTemplate, $currency, true);

//                                 if (isset($priceList['StandardPrice']) && $priceList['StandardPrice']) {
//                                     $value = $priceList['StandardPrice'];
//                                 } else {
//                                     if (!isset($priceList['StandardPrice'])) {
//                                         $value = false;
//                                     } else {
//                                         $value = 0;
//                                     }
//                                 }
//                             } else {
//                                 $value = 0;
//                             }

//                             //                            }
//                         }

//                         // echo "here";
//                         if ($sourceAttribute == 'quantity' && $type != self::PRODUCT_TYPE_PARENT) {
//                             // echo "how ";
//                             //                            $this->di->getLog()->logContent('getting quantity for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');
//                             //                            if ($amazonProduct && isset($amazonProduct[$sourceAttribute])) {
//                             //                                $value = $amazonProduct[$sourceAttribute];
//                             //                            }
//                             //                            else {
//                             $productInventory = $this->di->getObjectManager()->get(Inventory::class);
//                             $inventoryTemplate = $productInventory->init()->prepare($product);
//                             $inventoryList = $productInventory->init()->calculate($product, $inventoryTemplate);
//                             if (isset($inventoryList['Quantity']) && $inventoryList['Quantity'] && $inventoryList['Quantity'] > 0) {
//                                 $value = $inventoryList['Quantity'];
//                             } else {
//                                 $value = 0;
//                             }

//                             //                            }
//                         }

//                         if ($sourceAttribute == 'description') {
//                             //  $value = strip_tags($value);
//                             $value = str_replace(["\n", "\r"], ' ', $value);
//                             $tag = 'span';
//                             $value = preg_replace('#</?' . $tag . '[^>]*>#is', '', $value);

//                             $value = strip_tags($value, '<p></p><ul></ul><li></li><strong></strong>');
//                             $value = preg_replace('/(<[^>]+) style=".*?"/i', '$1', $value);
//                             $value = preg_replace("/(<[^>]+) style='.*?'/i", '$1', $value);
//                             $value = preg_replace('/(<[^>]+) class=".*?"/i', '$1', $value);
//                             $value = preg_replace("/(<[^>]+) class='.*?'/i", '$1', $value);
//                             $value = preg_replace('/(<[^>]+) data=".*?"/i', '$1', $value);
//                             $value = preg_replace("/(<[^>]+) data='.*?'/i", '$1', $value);
//                             $value = preg_replace('/(<[^>]+) data-mce-fragment=".*?"/i', '$1', $value);
//                             $value = preg_replace("/(<[^>]+) data-mce-fragment='.*?'/i", '$1', $value);
//                             $value = preg_replace('/(<[^>]+) data-mce-style=".*?"/i', '$1', $value);
//                             $value = preg_replace("/(<[^>]+) data-mce-style='.*?'/i", '$1', $value);
//                             $value = preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/si", '<$1$2>', $value);
//                         }

//                         //                        if ($sourceAttribute == 'description' || $sourceAttribute == 'title') {
//                         //                            $value = str_replace(' ', '-', $value);
//                         //                            $value = preg_replace('/[^A-Za-z0-9\-]/', '', $value);
//                         //                            $value = str_replace('-', ' ', $value);
//                         //                        }

//                         if ($sourceAttribute == 'weight') {
//                             if ((float) $value > 0) {
//                                 $value = number_format((float) $value, 2);
//                             } else {
//                                 $value = '';
//                             }
//                         }

//                         if ($id == 'item_name' && isset($product['variant_title']) && $product['type']=="simple" && $product['visibility'] == "Not Visible Individually") {
//                             if(isset($amazonProduct) && isset($amazonProduct['title']))
//                             {
//                                 $value = $amazonProduct['title'];
//                             }
//                             else{
//                                 $value = $product['title'] . ' ' . $product['variant_title'];
//                             }
//                         }


//                         if ($id == 'fulfillment_center_id' && $type == self::PRODUCT_TYPE_PARENT) {
//                             $value = '';
//                         }

//                         if ($this->_user_id == '612f48c95c77ca2b3904cda9' && $amazonAttribute == 'gem_type1') {
//                             $value = 'Metal';
//                         }

//                         if ($sourceAttribute == 'title') {
//                             $value = str_replace("’", "'", $value);
//                         }

//                         // if ($this->_user_id == "6289f0a2d3f6d710ce370f9d") {
//                         //     // $value = $this->mapOptionLatest($id, $sourceAttribute, $value);
//                         //     $value ='';
//                         // } elseif($this->_user_id != "63b35aa89eb4193d1b06e508") {
//                         //     $value = $this->mapOption($id, $sourceAttribute, $value);
//                         // }
//                         $value = $this->mapOption($id, $sourceAttribute, $value, $valueMappedAttributes);
//                         if(is_array($value))
//                         {
//                             $value = implode(",",$value);
//                         }

//                         $feedContent[$amazonAttribute] = $value;
//                     }

//                     if ($amazonAttribute == 'feed_product_type') {
//                         if (isset($attribute['amazon_recommendation'][$subCategory])) {
//                             $acceptedValues = $attribute['amazon_recommendation'][$subCategory];
//                             $feedContent[$amazonAttribute] = $acceptedValues;
//                         } else {
//                             $feedContent[$amazonAttribute] = $subCategory;
//                         }

//                         if ($feedContent[$amazonAttribute] == 'shirt' && $this->_user_id == '61182f2ad4fc9a5310349827') {
//                             $feedContent[$amazonAttribute] = 'SHIRT';
//                         }
//                     }

//                     if (in_array($amazonAttribute, self::IMAGE_ATTRIBUTES) && $this->_user_id != '61d29db38092370f1800a6a0') {
//                         $imageKey = array_search($amazonAttribute, self::IMAGE_ATTRIBUTES);
//                         if (isset($images[$imageKey])) {
//                             $feedContent[$amazonAttribute] = $images[$imageKey];
//                         }
//                     }

//                     if ($params['data']['category'] == 'handmade') {
//                         if (in_array($amazonAttribute, self::HANDMADE_IMAGE_ATTRIBUTES)) {
//                             $imageKey = array_search($amazonAttribute, self::HANDMADE_IMAGE_ATTRIBUTES);
//                             if (isset($images[$imageKey])) {
//                                 $feedContent[$amazonAttribute] = $images[$imageKey];
//                             }
//                         }
//                     }

//                     if ($amazonAttribute == 'recommended_browse_nodes' || $amazonAttribute == 'recommended_browse_nodes1') {
//                         if ($this->_user_id == '6116cad63715e60b00136653') {
//                             $feedContent[$amazonAttribute] = '5977780031';
//                         } elseif ($this->_user_id == '612f48c95c77ca2b3904cda9') {
//                             $feedContent[$amazonAttribute] = '10459533031';
//                         } else {
//                             $feedContent[$amazonAttribute] = $browseNodeId;
//                         }
//                     }

//                     if ($amazonAttribute == 'parent_child' && ($type == self::PRODUCT_TYPE_PARENT || $type == self::PRODUCT_TYPE_CHILD)) {
//                         $feedContent[$amazonAttribute] = $type;
//                     }

//                     if ($amazonAttribute == 'relationship_type' && $type == self::PRODUCT_TYPE_CHILD) {
//                         $feedContent[$amazonAttribute] = 'variation';
//                     }

//                     if ($amazonAttribute == 'parent_sku' && $type == self::PRODUCT_TYPE_CHILD) {
//                         $feedContent[$amazonAttribute] = $parentSku;
//                     }

//                     if ($amazonAttribute == 'operation-type' || $amazonAttribute == 'update_delete') {
//                         if ($category == 'default' && $operationType == self::OPERATION_TYPE_PARTIAL_UPDATE) {
//                             $operationType = self::OPERATION_TYPE_UPDATE;
//                         }

//                         $feedContent[$amazonAttribute] = $operationType;
//                     }

//                     if ($amazonAttribute == 'ASIN-hint' && isset($amazonProduct['asin'])) {
//                         $feedContent[$amazonAttribute] = $amazonProduct['asin'];
//                     }
//                 }

//                 $feedContent['barcode_exemption'] = $barcodeExemption;
//                 if (isset($category, $subCategory)) {
//                     $feedContent['category'] = $category;
//                     $feedContent['sub_category'] = $subCategory;
//                 }

//                 if ($barcodeExemption) {
//                     unset($feedContent['product-id']);
//                     unset($feedContent['external_product_id']);
//                 }

//                 if (isset($feedContent['product-id']) && (!$barcodeExemption || $feedContent['category'] == 'default') && $feedContent['product-id']) {
//                     $barcode = $this->di->getObjectManager()->get(Barcode::class);
//                     $type = $barcode->setBarcode($feedContent['product-id']);
//                     if ($type) {
//                         $feedContent['product-id-type'] = $type;
//                     }
//                 } elseif (isset($feedContent['external_product_id']) && (!$barcodeExemption || $feedContent['category'] == 'default') && $feedContent['external_product_id']) {
//                     $barcode = $this->di->getObjectManager()->get(Barcode::class);
//                     $type = $barcode->setBarcode($feedContent['external_product_id']);
//                     if ($type) {
//                         $feedContent['external_product_id_type'] = $type;
//                     }
//                 }

//                 //                $this->di->getLog()->logContent('validating data for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');

//                 if ($params['data']['category'] != 'handmade') {
//                     $feedContent = $this->validate($feedContent, $product, $attributeArray, $params);
//                 }

//                 //                $this->di->getLog()->logContent('validation done for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');
//             }

//             //            $this->di->getLog()->logContent('done setting data in attributes for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');
//         }

//         return $feedContent;
//     }

//     public function changeSourceProductIds($data){
//         try{
//             $getProductForLD = $this->di->getObjectManager()->get(LocalDelivery::class);
//             // die("dcbsj");

//             $ldSourceProductIds = array_keys($data['ld_source_product_ids']);

//             $userId = $data['user_id'];
//             $sourceShopId = $data['source']['shopId'];
//             $sourceMarketplace = $data['source']['marketplace'];
//             $new = [];

//             foreach($ldSourceProductIds as $sourceProductId){

//                 $query = [
//                     'user_id' => $userId, 'shop_id' => $sourceShopId, 'source_product_id' => $sourceProductId
//                 ];
//                 $options = ['projection'=> ['_id' => 0,'sku'=>1, 'container_id'=>1,'source_product_id'=>1,'online_source_product_id'=>1, 'online_container_id'=>1, 
//                 'merchant_shipping_group_name'=>1,'merchant_shipping_group'=>1, 'map_location_id'=>1,'price_setting'=>1, "price"=>1, 'type'=>1],
//                 'typeMap'   => ['root' => 'array', 'document' => 'array']];

//                 $localDeliveryProduct =  $getProductForLD->getproductbyQueryLocalDelivery($query, $options);
//                 // print_r($localDeliveryProduct);die;
//                 if($localDeliveryProduct[0]['type'] == 'variation'){
//                     $params = [
//                         'source_product_id'=>$sourceProductId,'container_id'=>$localDeliveryProduct[0]['container_id'], 'shop_id'=>$sourceShopId, 'source_marketplace'=>$sourceMarketplace,
//                         'user_id'=> $userId, 'merchant_shipping_group_name' => $localDeliveryProduct[0]['merchant_shipping_group_name']
//                     ];
//                     $localDeliveryProducts =  $getProductForLD->getProductsForLocalDelivery($params);
//                     // $merging = array_column($localDeliveryProducts['data'], 'source_product_id', 'online_source_product_id');

//                     $keys = array_keys($options['projection'], 1);

//                     $result = array_map(fn($item): array => array_intersect_key($item, array_flip($keys)), $localDeliveryProducts['data']);
//                 }
//                 else{
//                     $keys = array_keys($options['projection'], 1);

//                     $result = array_map(fn($item): array => array_intersect_key($item, array_flip($keys)), $localDeliveryProduct);

//                 }



//                 $new = [...$new, ...$result];
//                 // print_r($new);die;
//             }

//             return $new;

//         }
//         catch (Exception $e) {
//             return ['success' => false, 'message' => $e->getMessage()];
//         }

//     }

//     public function upload($data, $operationType = self::OPERATION_TYPE_UPDATE)
//     {
//         $feedContent = [];
//         $date = date('d-m-Y');
//         // $ldSourceProductIds = array_keys($data['data']['params']['ld_source_product_ids']);
//         $changeSourceProductIds = $this->changeSourceProductIds($data['data']['params']);
//         // print_r($changeSourceProductIds);die;





//         if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {

//             $preparedContent = [];
//             $userId = $data['data']['params']['user_id'];
//             // print_r($userId);die;
//             $targetShopId = $data['data']['params']['target']['shopId'];
//             $sourceShopId = $data['data']['params']['source']['shopId'];
//             $targetMarketplace = $data['data']['params']['target']['marketplace'];
//             $sourceMarketplace = $data['data']['params']['source']['marketplace'];
//             $productErrorList = [];
//             $response = [];
//             $active = false;

//             $logFile = "amazon/{$userId}/{$sourceShopId}/ProductUpload/{$date}.log";
//             // $this->di->getLog()->logContent('Receiving DATA = ' . print_r($data, true), 'info', $logFile);
//             $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details'); 
//             $targetShop = $user_details->getShop($targetShopId, $userId);
//             $sourceShop = $user_details->getShop($sourceShopId, $userId);
//             // print_r($sourceShop);die;
//             // $productMarketplace = $this->di->getObjectManager()->get('\App\Amazon\Components\LocalDelivery');

//             $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//             $multiplier = false;
//             $activeAccounts = [];

//             foreach ($targetShop['warehouses'] as $warehouse) {
//                 if ($warehouse['status'] == "active") {
//                     $activeAccounts = $warehouse;

//                 }
//             }

//             if (!empty($activeAccounts)) {

//                 $connector = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
//                 $currencyCheck = $connector->checkCurrency($userId, $targetMarketplace, $sourceMarketplace, $targetShopId, $sourceShopId);

//                 $this->configObj->setUserId($userId);
//                 $this->configObj->setTarget($targetMarketplace);
//                 $this->configObj->setTargetShopId($targetShopId);
//                 $this->configObj->setSourceShopId($sourceShopId);

//                 if (!$currencyCheck) {

//                     $this->configObj->setGroupCode('currency');
//                     $sourceCurrency = $this->configObj->getConfig('source_currency');
//                     $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);
//                     // $this->di->getLog()->logContent('sourceCurrency : '.json_encode($sourceCurrency), 'info', 'ProductUpload.log');

//                     $amazonCurrency = $this->configObj->getConfig('target_currency');
//                     $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);
//                     // $this->di->getLog()->logContent('amazonCurrency : '.json_encode($amazonCurrency), 'info', 'ProductUpload.log');

//                     if ($sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
//                         $currencyCheck = true;
//                     } else {
//                         $amazonCurrencyValue = $this->configObj->getConfig('target_value');
//                         $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
//                         if (isset($amazonCurrencyValue[0]['value'])) {
//                             $currencyCheck = $amazonCurrencyValue[0]['value'];
//                         }
//                     }
//                 }

//                 // $this->di->getLog()->logContent('Currency : '.json_encode($currencyCheck), 'info', 'ProductUpload.log');

//                 if ($currencyCheck) {
//                     $this->currency = $currencyCheck;
//                     $products = $data['data']['rows'];
//                     // print_r($products);die;
//                     foreach ($products as $product) {


//                         $type = false;
//                         foreach($changeSourceProductIds as $key => $ldData){
//                             if($ldData['online_source_product_id'] == $product['source_product_id'] && $ldData['online_container_id'] == $product['container_id']){
//                                 $product['online_source_product_id'] = $product['source_product_id'];
//                                 $productp['online_container_id'] = $product['container_id'];
//                                 $product['source_product_id'] = $ldData['source_product_id'];
//                                 $product['container_id'] = $ldData['container_id'];
//                                 if($product['type'] == 'simple'){
//                                     if(isset($ldData['map_location_id']) && $ldData['map_location_id'] != NULL){
//                                         $product['map_location_id'] = $ldData['map_location_id'];
//                                     }

//                                     if(isset($ldData['price_setting']) && $ldData['price_setting'] == 'custom'){
//                                         $product['price'] = $ldData['price'];
//                                     }

//                                     $sku = $ldData['sku'];
//                                     $merchantShippingGroupName = $ldData['merchant_shipping_group_name'];

//                                     // unset($changeSourceProductIds[$key]);

//                                 }


//                                 unset($changeSourceProductIds[$key]);
//                             }

//                         }


//                         if ($operationType == self::OPERATION_TYPE_UPDATE) {
//                             $categorySettings = $this->getUploadCategorySettings($product);

//                         }

//                         // print_r($categorySettings);die;

//                         $this->removeTag([$product['source_product_id']], [Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
//                         if ($categorySettings) {
//                             $categorySettings['user_id']=$userId;
//                             if (isset($product['parent_details'])) {
//                                 $parentAmazonProduct = $product['parent_details'];
//                                 if ($parentAmazonProduct && isset($parentAmazonProduct['edited']['parent_sku']) && $parentAmazonProduct['edited']['parent_sku'] != '') {
//                                     $productTypeParentSku = $parentAmazonProduct['edited']['parent_sku'];
//                                 } else if ($parentAmazonProduct && isset($parentAmazonProduct['sku']) && $parentAmazonProduct['sku'] != '') {
//                                     $productTypeParentSku = $parentAmazonProduct['sku'];
//                                 } else {
//                                     $productTypeParentSku = $parentAmazonProduct['source_product_id'];
//                                 }

//                                 // $categorySettings['user_id'] = $userId;

//                                 $preparedContent = $this->setContent($categorySettings, $product, $targetShopId, self::PRODUCT_TYPE_CHILD, $productTypeParentSku, $operationType,);

//                             } else if ($product['type'] == 'variation') {
//                                 $preparedContent = $this->setContent($categorySettings, $product, $targetShopId, self::PRODUCT_TYPE_PARENT, false, $operationType);
//                                 if(empty($preparedContent))
//                                 {
//                                     continue;
//                                 }
//                             } else {
//                                 $preparedContent = $this->setContent($categorySettings, $product, $targetShopId, false, false, $operationType);
//                                 if (in_array($product['source_product_id'], ['40475270840475', '40473394708635', '40473394675867', '40473394643099', '40473394577563'])) {
//                                     unset($preparedContent['variation_theme']);
//                                 }

//                             }

//                             // print_r($preparedContent);die;
//                             unset($preparedContent['error']);
//                             if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
//                                 $productErrorList[$product['source_product_id']] = $preparedContent['error'];
//                             } 
//                              else {
//                                 // print_r($productErrorList);die{;
//                                 unset($preparedContent['error']);
//                                 $feedContent[$categorySettings['primary_category']][$product['source_product_id']] =
//                                     $preparedContent;
//                                 $feedContent[$categorySettings['primary_category']]['category'] =
//                                     $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['category'];
//                                 $feedContent[$categorySettings['primary_category']]['sub_category'] =
//                                     $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['sub_category'];
//                                 $feedContent[$categorySettings['primary_category']]['barcode_exemption'] =
//                                     $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['barcode_exemption'];
//                                     if(isset($feedContent[$categorySettings['primary_category']][$product['source_product_id']]['item_sku']) && $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['item_sku'] != null){

//                                         $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['item_sku'] = $ldData['sku'];
//                                     }else{
//                                         $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['sku'] = $ldData['sku'];
//                                     }

//                                     $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['merchant_shipping_group_name'] =  $merchantShippingGroupName;

//                                     if($categorySettings['primary_category'] == 'default'){
//                                         $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
//                                         $collection = $mongo->getCollectionForTable(Helper::Local_Delivery);
//                                         $response = $collection->findOne(['user_id'=>$userId,'source.shop_id'=>$sourceShopId,
//                                          'target.shop_id'=>$targetShopId,'merchant_shipping_group_name'=>$merchantShippingGroupName]);
//                                          $response = json_decode(json_encode($response),true);
//                                         $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['merchant_shipping_group'] = $response['merchant_shipping_group'];

//                                     }
//                             }
//                         } else {
//                             $removeTagProductList[] = $product['source_product_id'];
//                             $productErrorList[$product['source_product_id']] = ['Amazon Category is not selected. Click on Product to edit & select an Amazon Category to upload this Product.'];                       
//                          }

//                         $addTagProductList = [];


//                     }

//                     // print_r($feedContent);
//                     // die;



//                     // $this->di->getLog()->logContent('FeedContent = ' . print_r($feedContent, true), 'info', 'feed.log');
//                     // die;
// // 
//                         if (!empty($feedContent)) {
//                             foreach ($feedContent as $content) {
//                                 $specifics = [

//                                     'home_shop_id' => $targetShop['_id'],
//                                     'marketplace_id' => $targetShop['warehouses'][0]['marketplace_id'],
//                                     'shop_id' => $targetShop['remote_shop_id'],
//                                     'sourceShopId' => $sourceShopId,
//                                     'feedContent' => base64_encode(serialize($content)),
//                                     'user_id' => $userId,
//                                     'operation_type' => $operationType,
//                                     'execute' => 'LOCAL_DELIVERY'
//                                 ];

//                                 // print_r($specifics);die;
//                                 $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');
//                                 if ($response == null) {
//                                     $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');
//                                 }

//                                 // $this->di->getLog()->logContent('Response = ' . print_r($response, true), 'info', $logFile);

//                                 // print_r($response);die;
//                                 $productSpecifics = $this->processResponse($response, $feedContent, Helper::PROCESS_TAG_UPLOAD_FEED, 'product', $targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, Helper::PROCESS_TAG_UPLOAD_PRODUCT);
//                                 // $this->di->getLog()->logContent('Process1 : '.json_encode($productSpecifics), 'info', 'ProductUpload.log');

//                                 $addTagProductList = array_merge($addTagProductList, $productSpecifics['addTagProductList']);
//                                 $productErrorList = array_merge($productErrorList, $productSpecifics['productErrorList']);
//                                 // $this->di->getLog()->logContent('FeedContent = ' . print_r($addTagProductList, true), 'info', 'addTags.log');
//                                 // die;

//                             }

//                             return $this->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);
//                         }
//                         $productSpecifics = $this->processResponse($response, $feedContent, Helper::PROCESS_TAG_UPLOAD_PRODUCT, 'product', $targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList);
//                         // $this->di->getLog()->logContent('Process2 : '.json_encode($productSpecifics), 'info', 'ProductUpload.log');
//                         return $this->setResponse(Helper::RESPONSE_SUCCESS, count($addTagProductList), 0, count($productErrorList), 0);

//                         // }

//                 } else {
//                     if(isset($data['data']['params']['source_product_ids']) && !empty($data['data']['params']['source_product_ids']))
//                     {
//                         $this->removeTag([$data['data']['params']['source_product_ids']], [Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
//                     }

//                     return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Currency of '.$sourceMarketplace.' and Amazon account are not same. Please fill currency settings from Global Price Adjustment in settings page.', 0, 0, 0);
//                 }
//             } else {
//                 return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0);
//             }
//         } else {
//             if(isset($data['data']['params']))
//             {
//                 $params = $data['data']['params'];
//                 $sourceShopId = $params['source']['shopId'] ?? "";
//                 $targetShopId = $params['target']['shopId'] ?? "";
//                 $userId = $params['user_id'] ?? $this->_user_id;          
//                 if(isset($params['source_product_ids']) && !empty($params['source_product_ids']))
//                 {
//                     $this->removeTag([$params['source_product_ids']], [Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
//                 }

//                 if(isset($params['next_available']) && $params['next_available'])
//                 {
//                     return $this->setResponse(Helper::RESPONSE_SUCCESS, "", 0 , 0 , 0);
//                 }
//             }

//             return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0);
//         }
//     }

//     public function setContent($categorySettings, $product, $homeShopId, $type, $parentSku, $operationType, $multiplier = false)
//     {
//         $feedContent = [];

//         if (!empty($categorySettings)) {
//             $userId = $categorySettings['user_id'] ?? $this->di->getUser()->id;
//             $sourceMarketplace = $this->di->getRequester()->getSourceName();
//             // print_r($sourceMarketplace);die;

//             $sourceSelect = $sourceMarketplace.'_select';
//             $product = json_decode(json_encode($product),true);
//             $barcodeExemption = $categorySettings['barcode_exemption'] ?? "";
//             $subCategory = $categorySettings['sub_category'] ?? "";
//             $category = $categorySettings['primary_category'] ?? "";
//             $valueMappedAttributes = $categorySettings['attributes_mapping']['value_mapping'] ?? [];
//             $requiredAttributes = $categorySettings['attributes_mapping']['required_attribute'] ?? [];
//             $optionalAttributes = $categorySettings['attributes_mapping']['optional_attribute'] ?? [];
//             $browseNodeId = $categorySettings['browser_node_id'] ?? "";
//             $attributes = [...(array) $requiredAttributes, ...(array) $optionalAttributes];
//             $variant_attributes = [];
//             $fulfillment_centre_id = false;

//             if (isset($categorySettings['optional_variant_attributes'])) {
//                 $variant_attributes = array_merge($variant_attributes, $categorySettings['optional_variant_attributes']);
//             }

//             if (isset($categorySettings['variation_theme_attribute_name']) && $categorySettings['variation_theme_attribute_name']) {
//                 $variant_attributes['variation_theme'] = $categorySettings['variation_theme_attribute_name'];
//             }

//             if (isset($categorySettings['variation_theme_attribute_value']) && $categorySettings['variation_theme_attribute_value']) {
//                 $variant_attributes = array_merge($variant_attributes, $categorySettings['variation_theme_attribute_value']);
//             }

//             // Offer cannot be created for parent product
//             if ($product['type'] == 'variation' && $category == 'default') {
//                 return $feedContent;
//             }

//             $amazonProduct = false;
//             if (isset($product['edited'])) {
//                 $amazonProduct = $product['edited'];
//             }

//             $categoryContainer = $this->di->getObjectManager()->get(Category::class);

//             $params = [
//                 'target' => [
//                     'shopId' => $homeShopId,

//                 ],
//                 'data' => [
//                     'category' => $category,
//                     'sub_category' => $subCategory,
//                     'browser_node_id' => $browseNodeId,
//                     'barcode_exemption' => $barcodeExemption,
//                     'browse_node_id' => $browseNodeId,
//                 ],
//                 'user_id' => $userId
//             ];

//             if (isset($this->categoryAttributes[$category][$subCategory])) {
//                 $attributeArray = $this->categoryAttributes[$category][$subCategory];


//             } else {

//                 $allAttributes = $categoryContainer->init()->getAmazonAttributes($params, true);

//                 $attributeArray = [];

//                 if (isset($allAttributes['data'])) {
//                     $attributeArray = $categoryContainer->init()->moldAmazonAttributes($allAttributes['data']);

//                 }

//                 $this->categoryAttributes[$category][$subCategory] = $attributeArray;
//             }

//             // print_r($attributes);die;

//             $arrayKeys = array_column($attributes, 'amazon_attribute');
//             // print_r($arrayKeys);die("yes");
//             $mappedAttributeArray = array_combine($arrayKeys, $attributes);
//             $images = false;
//             if (isset($product['additional_images'])) {
//                 $images = $product['additional_images'];

//             }

//             $defaultMapping = self::DEFAULT_MAPPING;
//             // print_r($mappedAttributeArray);die;
//             if (!empty($mappedAttributeArray) && !empty($attributeArray)) {
//                 foreach ($attributeArray as $id => $attribute) {

//                     if($id == "fulfillment_center_id" && isset($categorySettings['fulfillment_type'])&& $categorySettings['fulfillment_type'] == "FBA"){
//                         $amazonRecommendations = $attribute['amazon_recommendation'];
//                         foreach($amazonRecommendations as $amazonRecommendation){
//                             if($amazonRecommendation != "DEFAULT"){
//                                 $fulfillment_centre_id = $amazonRecommendation;
//                             }
//                         }
//                     }
//                     else{
//                         $fulfillment_centre_id = "DEFAULT";
//                     }

//                     if ($id == 'main_image_url' && $this->_user_id == '61d29db38092370f1800a6a0') {
//                         continue;
//                     }

//                     $amazonAttribute = $id;
//                     $sourceAttribute = false;
//                     if (isset($defaultMapping[$id])) {
//                         $sourceAttribute = $defaultMapping[$id];
//                     } elseif (($type == self::PRODUCT_TYPE_CHILD || $id == 'variation_theme') && isset($variant_attributes[$id])) {
//                         $sourceAttribute = 'variant_custom';
//                     }
//                     elseif(isset($mappedAttributeArray[$id]) && isset($mappedAttributeArray[$id][$sourceSelect]) && $mappedAttributeArray[$id][$sourceSelect]=="custom"){
//                         $sourceAttribute = "custom";
//                     }
//                     elseif(isset($mappedAttributeArray[$id]) && isset($mappedAttributeArray[$id][$sourceSelect])  && $mappedAttributeArray[$id][$sourceSelect]=="recommendation"){
//                         $sourceAttribute = "recommendation";
//                     } 
//                     elseif(isset($mappedAttributeArray[$id]) && isset($mappedAttributeArray[$id][$sourceSelect]) && $mappedAttributeArray[$id][$sourceSelect]=="Search"){
//                         $sourceAttribute = $mappedAttributeArray[$id][$sourceMarketplace.'_attribute'];
//                     }
//                     //  elseif (isset($mappedAttributeArray[$id])) {
//                     //     $sourceAttribute = $mappedAttributeArray[$id]['shopify_attribute'];
//                     // } 
//                     elseif (isset($attributeArray[$id]['premapped_values']) && !empty($attributeArray[$id]['premapped_values'])) {
//                         $sourceAttribute = 'premapped_values';
//                     }

//                     // if ($id == 'brand_name' && isset($mappedAttributeArray[$id])) {
//                     //     $sourceAttribute = $mappedAttributeArray[$id]['shopify_attribute'];
//                     // }

//                     if ($id == 'product_description' && isset($mappedAttributeArray[$id])) {
//                         $sourceAttribute = $mappedAttributeArray[$id][$sourceMarketplace.'_attribute'];
//                     }

//                     $value = '';


//                     if ($sourceAttribute) {
//                         if ($sourceAttribute == 'recommendation') {
//                             $recommendation = $mappedAttributeArray[$id]['recommendation'];
//                             if ($recommendation == 'custom') {
//                                 $customText = $mappedAttributeArray[$id]['custom_text'];
//                                 $value = $customText;
//                             } else {
//                                 $value = $recommendation;
//                             }
//                         } elseif ($sourceAttribute == 'custom') {
//                             $customText = $mappedAttributeArray[$id]['custom_text'];
//                             $value = $customText;

//                         } elseif (isset($product[$sourceAttribute])) {
//                             $value = $product[$sourceAttribute];
//                         } elseif ($sourceAttribute == 'variant_custom') {
//                             $value = $variant_attributes[$id];
//                         } elseif ($sourceAttribute == "premapped_values") {
//                             $value = $attributeArray[$id]['premapped_values'];
//                         } 
//                         elseif (isset($product['variant_attributes'])&& $type == self::PRODUCT_TYPE_CHILD) {
//                             foreach( $product['variant_attributes'] as $variantatt ){
//                                 if($variantatt['key'] == $sourceAttribute){
//                                     $value = $variantatt['value'];
//                                 }
//                             }
//                         }
//                         //not working because we don't have $firstChild
//                         elseif (in_array($id, ['apparel_size', 'shirt_size', 'footwear_size_unisex', 'footwear_size', 'bottoms_size', 'skirt_size', 'shapewear_size']) && $type == self::PRODUCT_TYPE_PARENT && !empty($firstChild)) {
//                             if (isset($firstChild[$sourceAttribute])) {
//                                 $value = $firstChild[$sourceAttribute];
//                             } elseif (isset($firstChild['variant_attributes_values'][$sourceAttribute])) {
//                                 $value = $firstChild['variant_attributes_values'][$sourceAttribute];
//                             }
//                         }

//                         if ($sourceAttribute == 'sku' && $type == self::PRODUCT_TYPE_PARENT && $parentSku) {
//                             $value = $parentSku;
//                         }

//                         if ($id == 'main_image_url' && $type == self::PRODUCT_TYPE_CHILD && isset($product['variant_image'])) {
//                             $value = $product['variant_image'];
//                         }

//                         if (in_array($id, ['apparel_size', 'shirt_size', 'footwear_size_unisex', 'footwear_size', 'bottoms_size','skirt_size', 'shapewear_size']) && $type == self::PRODUCT_TYPE_PARENT) {
//                             if(isset($feedContent['footwear_size_class']) || isset($feedContent['bottoms_size_class']) || isset($feedContent['apparel_size_class']) || isset($feedContent['skirt_size_class']) || isset($feedContent['shapewear_size_class']) || isset($feedContent['shirt_size_class']) )
//                             {
//                                 if ((isset($feedContent['footwear_size_class']) && ($feedContent['footwear_size_class'] == 'Numeric' || $feedContent['footwear_size_class'] == 'Numeric Range' || $feedContent['footwear_size_class'] == 'Numero' || $feedContent['footwear_size_class'] == 'Numérique')) || (isset($feedContent['bottoms_size_class']) && $feedContent['bottoms_size_class'] == 'Numeric') || (isset($feedContent['apparel_size_class']) && $feedContent['apparel_size_class'] == 'Numeric') || (isset($feedContent['skirt_size_class']) && $feedContent['skirt_size_class'] == 'Numeric') || (isset($feedContent['shirt_size_class']) && $feedContent['shirt_size_class'] == 'Numeric')) {
//                                     if ($id == 'footwear_size') {
//                                         $value = 7;
//                                     } else {
//                                         $value = 34;
//                                     }
//                                 }
//                                 elseif ((isset($feedContent['shapewear_size_class']) && $feedContent['shapewear_size_class'] == 'Age') || (isset($feedContent['shirt_size_class']) && $feedContent['shirt_size_class'] == 'Age') || (isset($feedContent['footwear_size_class']) && $feedContent['footwear_size_class'] == 'Age')  || (isset($feedContent['bottoms_size_class']) && $feedContent['bottoms_size_class'] == 'Age') || (isset($feedContent['apparel_size_class']) && $feedContent['apparel_size_class'] == 'Age')) {
//                                     $value = '4 Years';
//                                 }
//                                 else {
//                                     $value = 'M';
//                                 }
//                             }
//                         }

//                         if ($sourceAttribute == 'sku' && $type != self::PRODUCT_TYPE_PARENT) {
//                             if ($amazonProduct && isset($amazonProduct[$sourceAttribute]) && $amazonProduct[$sourceAttribute]) {
//                                 $value = $amazonProduct[$sourceAttribute];
//                             } elseif ($product && isset($product[$sourceAttribute]) && !empty($product[$sourceAttribute])) {
//                                 $value = $product[$sourceAttribute];
//                             } elseif (isset($product['source_product_id'])) {
//                                 $value = $product['source_product_id'];
//                             }
//                         }

//                         if ($sourceAttribute == 'barcode' && $amazonProduct && isset($amazonProduct[$sourceAttribute])) {
//                             $value = $amazonProduct[$sourceAttribute];
//                         }

//                         if ($sourceAttribute == 'barcode') {
//                             $value = str_replace("-", "", $value);
//                         }

//                         if($sourceAttribute == 'description')
//                         {
//                             if ($amazonProduct && isset($amazonProduct[$sourceAttribute])) {
//                                 $value = $amazonProduct[$sourceAttribute];
//                             }
//                             elseif(isset($product['parent_details']['edited']) && isset($product['parent_details']['edited'][$sourceAttribute]))
//                             {
//                                 $value = $product['parent_details']['edited'][$sourceAttribute];
//                             }
//                             elseif(isset($product[$sourceAttribute]))
//                             {
//                                 $value = $product[$sourceAttribute];
//                             }
//                         }

//                         if ($sourceAttribute == 'title' && $amazonProduct && isset($amazonProduct[$sourceAttribute])) {
//                             $value = $amazonProduct[$sourceAttribute];
//                         }

//                         if ($sourceAttribute == 'price' &&  $type != self::PRODUCT_TYPE_PARENT) {
//                             //                            $this->di->getLog()->logContent('getting price for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');
//                             //                            if ($amazonProduct && isset($amazonProduct[$sourceAttribute])) {
//                             //                                $value = $amazonProduct[$sourceAttribute];
//                             //                            } else {
//                             $productPrice = $this->di->getObjectManager()->get(Price::class);
//                             //   $currency = $productPrice->init()->getConversionRate();
//                             $currency = $this->currency;

//                             if ($currency) {
//                                 $priceTemplate = $productPrice->init()->prepare($product);
//                                 $priceList = $productPrice->init()->calculate($product, $priceTemplate, $currency, true);
//                                 if (isset($priceList['StandardPrice']) && $priceList['StandardPrice']) {
//                                     $value = $priceList['StandardPrice'];
//                                 } else {
//                                     if (!isset($priceList['StandardPrice'])) {
//                                         $value = false;
//                                     } else {
//                                         $value = 0;
//                                     }
//                                 }
//                             } else {
//                                 $value = 0;
//                             }

//                             //                            }
//                             //                            $this->di->getLog()->logContent('got price for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');
//                         }

//                         // echo "here";
//                         if ($sourceAttribute == 'quantity' && $type != self::PRODUCT_TYPE_PARENT) {
//                             // echo "how ";
//                             //                            $this->di->getLog()->logContent('getting quantity for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');
//                             //                            if ($amazonProduct && isset($amazonProduct[$sourceAttribute])) {
//                             //                                $value = $amazonProduct[$sourceAttribute];
//                             //                            }
//                             if(isset($categorySettings['fulfillment_type']) && $categorySettings['fulfillment_type'] == "FBA"){
//                                 $value = "";
//                             }else{

//                                 //                            else {
//                                     $productInventory = $this->di->getObjectManager()->get(LocalDeliveryInventory::class);
//                                     $inventoryTemplate = $productInventory->init()->prepare($product);
//                                     $inventoryList = $productInventory->init()->calculate($product, $inventoryTemplate, $sourceMarketplace);
//                                     // $this->di->getLog()->logContent(print_r($inventoryTemplate, true), 'info', 'inventoryTemplate.log');
//                                     // $this->di->getLog()->logContent(print_r($inventoryList, true), 'info', 'inventoryList.log');

//                                     if (isset($inventoryList['Latency'])) {
//                                         $latency = $inventoryList['Latency'];
//                                     }

//                                     if (isset($inventoryList['Quantity']) && $inventoryList['Quantity'] && $inventoryList['Quantity'] > 0) {
//                                         $value = $inventoryList['Quantity'];
//                                     } else {
//                                         $value = 0;
//                                     }

//                                     //                            }
//                                     //                            $this->di->getLog()->logContent('got quantity for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');
//                                 }
//                         }


//                         if ($sourceAttribute == 'description') {
//                             //  $value = strip_tags($value);
//                             $value = str_replace(["\n", "\r"], ' ', $value);
//                             $tag = 'span';
//                             $value = preg_replace('#</?' . $tag . '[^>]*>#is', '', $value);

//                             $value = strip_tags($value, '<p></p><ul></ul><li></li><strong></strong>');
//                             $value = preg_replace('/(<[^>]+) style=".*?"/i', '$1', $value);
//                             $value = preg_replace("/(<[^>]+) style='.*?'/i", '$1', $value);
//                             $value = preg_replace('/(<[^>]+) class=".*?"/i', '$1', $value);
//                             $value = preg_replace("/(<[^>]+) class='.*?'/i", '$1', $value);
//                             $value = preg_replace('/(<[^>]+) data=".*?"/i', '$1', $value);
//                             $value = preg_replace("/(<[^>]+) data='.*?'/i", '$1', $value);
//                             $value = preg_replace('/(<[^>]+) data-mce-fragment=".*?"/i', '$1', $value);
//                             $value = preg_replace("/(<[^>]+) data-mce-fragment='.*?'/i", '$1', $value);
//                             $value = preg_replace('/(<[^>]+) data-mce-style=".*?"/i', '$1', $value);
//                             $value = preg_replace("/(<[^>]+) data-mce-style='.*?'/i", '$1', $value);
//                             $value = preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/si", '<$1$2>', $value);
//                         }

//                         //                      if ($sourceAttribute == 'description' || $sourceAttribute == 'title') {
//                         //                            $value = str_replace(' ', '-', $value);
//                         //                            $value = preg_replace('/[^A-Za-z0-9\-]/', '', $value);
//                         //                            $value = str_replace('-', ' ', $value);
//                         //                        }

//                         if ($sourceAttribute == 'weight') {
//                             if ((float) $value > 0) {
//                                 $value = number_format((float) $value, 2);
//                             } else {
//                                 $value = '';
//                             }
//                         }

//                         if ($id == 'item_name' && isset($product['variant_title']) && isset($product['group_id']) && $this->_user_id != '612a4c7bae0bc20b20753452' && $this->_user_id != '61231fbf6ede040a43459660') {
//                             $value = $value . ' ' . $product['variant_title'];
//                         }

//                         if ($id == 'item_name' && isset($product['variant_title']) && $product['type']=="simple" && $product['visibility'] == "Not Visible Individually") {
//                             if(isset($amazonProduct) && isset($amazonProduct['title']))
//                             {
//                                 $value = $amazonProduct['title'];
//                             }
//                             else{
//                                 $value = $product['title'] . ' ' . $product['variant_title'];
//                             }
//                         }

//                         if ($id == 'fulfillment_center_id' && $type == self::PRODUCT_TYPE_PARENT) {
//                             $value = '';
//                         }

//                         if ($this->_user_id == '612f48c95c77ca2b3904cda9' && $amazonAttribute == 'gem_type1') {
//                             $value = 'Metal';
//                         }

//                         if ($sourceAttribute == 'title') {
//                             $value = str_replace("’", "'", $value);
//                         }

//                         // if ($this->_user_id == "6289f0a2d3f6d710ce370f9d1") {
//                         //     // $value = $this->mapOptionLatest($id, $sourceAttribute, $value);
//                         //     $value = '';
//                         // } else {
//                         //     $value = $this->mapOption($id, $sourceAttribute, $value);
//                         // }
//                         $value = $this->mapOption($id, $sourceAttribute, $value, $valueMappedAttributes);
//                         if(is_array($value))
//                         {
//                             $value = implode(",",$value);
//                         }

//                         $feedContent[$amazonAttribute] = $value;
//                     }

//                     if ($amazonAttribute == 'feed_product_type') {
//                         if (isset($attribute['amazon_recommendation'][$subCategory])) {
//                             $acceptedValues = $attribute['amazon_recommendation'][$subCategory];
//                             $feedContent[$amazonAttribute] = $acceptedValues;
//                         } else {
//                             $feedContent[$amazonAttribute] = $subCategory;
//                         }

//                         if ($feedContent[$amazonAttribute] == 'shirt' && $this->_user_id == '61182f2ad4fc9a5310349827') {
//                             $feedContent[$amazonAttribute] = 'SHIRT';
//                         }
//                     }

//                     if (in_array($amazonAttribute, self::IMAGE_ATTRIBUTES) && $this->_user_id != '61d29db38092370f1800a6a0') {
//                         $imageKey = array_search($amazonAttribute, self::IMAGE_ATTRIBUTES);
//                         if (isset($images[$imageKey])) {
//                             $feedContent[$amazonAttribute] = $images[$imageKey];
//                         }
//                     }

//                     if ($params['data']['category'] == 'handmade') {
//                         if (in_array($amazonAttribute, self::HANDMADE_IMAGE_ATTRIBUTES)) {
//                             $imageKey = array_search($amazonAttribute, self::HANDMADE_IMAGE_ATTRIBUTES);
//                             if (isset($images[$imageKey])) {
//                                 $feedContent[$amazonAttribute] = $images[$imageKey];
//                             }
//                         }
//                     }


//                     if ($amazonAttribute == 'recommended_browse_nodes' || $amazonAttribute == 'recommended_browse_nodes1') {
//                         if ($this->_user_id == '6116cad63715e60b00136653') {
//                             $feedContent[$amazonAttribute] = '5977780031';
//                         } elseif ($this->_user_id == '612f48c95c77ca2b3904cda9') {
//                             $feedContent[$amazonAttribute] = '10459533031';
//                         } else {
//                             $feedContent[$amazonAttribute] = $browseNodeId;
//                         }
//                     }

//                     if ($amazonAttribute == 'parent_child' && ($type == self::PRODUCT_TYPE_PARENT || $type == self::PRODUCT_TYPE_CHILD)) {
//                         $feedContent[$amazonAttribute] = $type;
//                     }

//                     if ($amazonAttribute == 'relationship_type' && $type == self::PRODUCT_TYPE_CHILD) {
//                         $feedContent[$amazonAttribute] = 'variation';
//                     }

//                     if ($amazonAttribute == 'parent_sku' && $type == self::PRODUCT_TYPE_CHILD) {
//                         $feedContent[$amazonAttribute] = $parentSku;
//                     }

//                     if ($amazonAttribute == 'operation-type' || $amazonAttribute == 'update_delete') {
//                         if ($operationType == self::OPERATION_TYPE_UPDATE) {
//                             // $operationType = self::OPERATION_TYPE_UPDATE;
//                             $feedContent[$amazonAttribute] = $operationType;
//                         }

//                     }

//                     if ($amazonAttribute == 'ASIN-hint' && isset($amazonProduct['asin'])) {
//                         $feedContent[$amazonAttribute] = $amazonProduct['asin'];
//                     }

//                     // print_r($feedContent);die;

//                 }

//                 if ($this->_user_id == '613bca66a4d23a0dca3fbd9d') {
//                     if (isset($feedContent['shirt_size'], $feedContent['shirt_size_class'])) {
//                         $f = $this->getSizeClassFromSize($feedContent['shirt_size']);
//                         if($f){
//                            $feedContent['shirt_size_class'] = $f;
//                         }
//                     }
//                 }

//                 $feedContent['barcode_exemption'] = $barcodeExemption;
//                 if (isset($category, $subCategory)) {
//                     $feedContent['category'] = $category;
//                     $feedContent['sub_category'] = $subCategory;
//                 }

//                 //setting handling time
//                 if (isset($latency)) {
//                     $feedContent['fulfillment_latency'] = $latency;
//                 }

//                 if ($barcodeExemption) {
//                     unset($feedContent['product-id']);
//                     unset($feedContent['external_product_id']);
//                 }

//                 if (isset($feedContent['product-id']) && (!$barcodeExemption || $feedContent['category'] == 'default') && $feedContent['product-id']) {
//                     $barcode = $this->di->getObjectManager()->get(Barcode::class);
//                     $type = $barcode->setBarcode($feedContent['product-id']);
//                     if ($type) {
//                         $feedContent['product-id-type'] = $type;
//                     }
//                 } elseif (isset($feedContent['external_product_id']) && (!$barcodeExemption || $feedContent['category'] == 'default') && $feedContent['external_product_id']) {
//                     $barcode = $this->di->getObjectManager()->get(Barcode::class);
//                     $type = $barcode->setBarcode($feedContent['external_product_id']);
//                     if ($type) {
//                         $feedContent['external_product_id_type'] = $type;
//                     }
//                 }

//                 //                $this->di->getLog()->logContent('validating data for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');

//                 if ($params['data']['category'] != 'handmade') {
//                     $feedContent = $this->validate($feedContent, $product, $attributeArray, $params);
//                 }

//                 //                $this->di->getLog()->logContent('validation done for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');
//             }

//             //            $this->di->getLog()->logContent('done setting data in attributes for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');

//         }

//         // print_r($feedContent);die;

//         return $feedContent;
//     }

//     public function getSizeClassFromSize($sizeValue)
//     {
//         if (in_array($sizeValue, ['4','6','7'])) {
//             return 'Numeric';
//         }
//         if (in_array($sizeValue, ['12 Months','18 Months','24 Months', '6 Months'])) {
//             return 'Age';
//         }
//         if (in_array($sizeValue, ["X-Large","Small","Medium", "Large","XX-Large","3X-Large", "4X-Large","5X-Large"])) {
//             return 'Alpha';
//         }
//         else {
//             return false;
//         }
//     }

//     public function getSyncStatus($product)
//     {

//         $syncProductTemplate = false;
//         $sync = [
//             'priceInvSyncList' => false,
//             'imageSyncProductList' => false,
//             'productSyncProductList' => false,
//         ];

//         // //check if product sync is enabled in profile
//         if (isset($product['profile_info']) && is_array($product['profile_info'])) {
//             $profiles = $product['profile_info'];
//             if ($profiles) {

//                 $syncProductTemplate = false;
//                 $syncProductStatusEnabled = false;
//                 foreach ($profiles['data'] as $profile) {
//                     if (isset($profile['data_type']) && $profile['data_type'] == "product_settings") {
//                         $syncProductTemplate = $profile;
//                         break;
//                     }
//                 }

//                 if ($syncProductTemplate) {
//                     $syncProductStatusEnabled = $syncProductTemplate['data']['settings_enabled'];
//                     if ($syncProductStatusEnabled == true) {
//                         $sync['priceInvSyncList'] = true;
//                         $selectedAttributes = $syncProductTemplate['data']['selected_attributes'];
//                         $selectedAttributes = json_decode(json_encode($selectedAttributes), true);

//                         if (in_array('images', $selectedAttributes)) {
//                             $sync['imageSyncProductList'] = true;
//                         }

//                         $intersect = array_intersect(["title", "sku", "barcode", "brand", "description",'product_details'], $selectedAttributes);
//                         if (!empty($intersect)) {
//                             $sync['productSyncProductList'] = true;
//                         }
//                     }
//                 }

//             }
//         }

//         // if profile is not assigned, check product sync status in configuration

//         if (!$syncProductTemplate) {

//             $this->configObj->setGroupCode('product');
//             $syncProductTemplates = $this->configObj->getConfig("settings_enabled");
//             $syncProductAttributes = $this->configObj->getConfig("selected_attributes");
//             $syncProductTemplates = json_decode(json_encode($syncProductTemplates), true);
//             $syncProductAttributes = json_decode(json_encode($syncProductAttributes), true);
//             foreach ($syncProductTemplates as $syncProductTemplate) {
//                 if ($syncProductTemplate) {
//                     $syncProductStatusEnabled = $syncProductTemplate['value'];
//                     $sync['priceInvSyncList'] = true;
//                 }
//             }

//             // if ($syncProductStatusEnabled) {

//                 foreach ($syncProductAttributes as $syncProductAttribute) {
//                     if ($syncProductAttribute) {
//                         $selectedAttributes = $syncProductAttribute['value'];
//                         if (in_array('images', $selectedAttributes)) {
//                             $sync['imageSyncProductList'] = true;
//                         }

//                         if (in_array('product_details', $selectedAttributes) || in_array('product_syncing', $selectedAttributes)) {
//                             $sync['productSyncProductList'] = true;
//                         }
//                     }

//                 // }
//             }

//         }

//         return $sync;
//     }

//     public function delete($data, $operationType = self::OPERATION_TYPE_DELETE)
//     {

//         $feedContent = [];
//         $productComponent = $this->di->getObjectManager()->get(Product::class);
//         $addTagProductList = [];
//         $date = date('d-m-Y');
//         if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
//             $preparedContent = [];
//             $userId = $data['data']['params']['user_id'];
//             $targetShopId = $data['data']['params']['target']['shopId'];
//             $sourceShopId = $data['data']['params']['source']['shopId'];
//             $targetMarketplace = $data['data']['params']['target']['marketplace'];
//             $sourceMarketplace = $data['data']['params']['source']['marketplace'];
//             $productErrorList = [];
//             $response = [];
//             $active = false;
//             $logFile = "amazon/{$userId}/{$sourceShopId}/ProductDelete/{$date}.log";
//             // $this->di->getLog()->logContent('Receiving DATA = ' . print_r($data, true), 'info', $logFile);

//             $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
//             $targetShop = $user_details->getShop($targetShopId, $userId);

//             $activeAccounts = [];
//             foreach ($targetShop['warehouses'] as $warehouse) {
//                 if ($warehouse['status'] == "active") {
//                     $activeAccounts = $warehouse;
//                 }
//             }

//             if (!empty($activeAccounts)) {
//                 $products = $data['data']['rows'];
//                 foreach ($products as $key => $product) {
//                     if (isset($product['edited'])) {
//                         $amazonProduct = $product['edited'];
//                     } else {
//                         $amazonProduct = [];
//                     }

//                     $feedContent[$product['source_product_id']]['Id'] = $product['_id'] . $key;
//                     if ($product['type'] == 'variation') {
//                         if (isset($amazonProduct['parent_sku']) && !empty($amazonProduct['parent_sku'])) {
//                             $feedContent[$product['source_product_id']]['SKU'] = $amazonProduct['parent_sku'];
//                         } elseif (isset($product['sku']) && !empty($product['sku'])) {
//                             $feedContent[$product['source_product_id']]['SKU'] = $product['sku'];
//                         } else {
//                             $feedContent[$product['source_product_id']]['SKU'] = $product['source_product_id'];
//                         }
//                     } else {
//                         if (isset($amazonProduct['sku']) && !empty($amazonProduct['sku'])) {
//                             $feedContent[$product['source_product_id']]['SKU'] = $amazonProduct['sku'];
//                         } elseif (isset($product['sku']) && !empty($product['sku'])) {
//                             $feedContent[$product['source_product_id']]['SKU'] = $product['sku'];
//                         } else {
//                             $feedContent[$product['source_product_id']]['SKU'] = $product['source_product_id'];
//                         }
//                     }
//                 }

//                 // $this->di->getLog()->logContent('Feedcontent = ' . print_r($feedContent, true), 'info', $logFile);

//                 if (!empty($feedContent)) {
//                     $specifics = [
//                         'ids' => array_keys($feedContent),
//                         'home_shop_id' => $targetShop['_id'],
//                         'marketplace_id' => $activeAccounts['marketplace_id'],
//                         'shop_id' => $targetShop['remote_shop_id'],
//                         'sourceShopId' => $sourceShopId,
//                         'feedContent' => base64_encode(serialize($feedContent)),
//                         'user_id' => $userId,
//                         'operation_type' => $operationType,
//                     ];
//                     $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//                     $response = $commonHelper->sendRequestToAmazon('product-delete', $specifics, 'POST');
//                     // $this->di->getLog()->logContent('Response = ' . print_r($response, true), 'info', $logFile);

//                     $productSpecifics = $this
//                     ->processResponse($response, $feedContent, Helper::PROCESS_TAG_DELETE_FEED, 'delete', $targetShop, $targetShopId, $sourceShopId, $userId, $products,[],Helper::PROCESS_TAG_DELETE_PRODUCT);
//                     $addTagProductList = $productSpecifics['addTagProductList'];
//                     $productErrorList = $productSpecifics['productErrorList'];
//                 }

//                 return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);
//             }
//             return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0);
//         }
//         return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0);
//     }

//     public function prepareDelete($specifics)
//     {
//         $feedContent = [];
//         $attributeSku = false;
//         $type = null;
//         if (isset($specifics) && !empty($specifics)) {
//             $templatesCollection = $this->_baseMongo->getCollectionForTable(Helper::PROFILE_SETTINGS);
//             if (isset($specifics['category_template']) && $specifics['category_template']) {
//                 $categoryTemplate = $templatesCollection->findOne(['_id' => (int) $specifics['category_template']], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//                 $requiredAttributes = $categoryTemplate['data']['attributes_mapping']['required_attribute'];
//                 $skuAttributeIndex = array_search('SKU', array_column($requiredAttributes, 'amazon_attribute'));
//                 if (isset($requiredAttributes[$skuAttributeIndex]['onyx_attribute']) && !empty($requiredAttributes[$skuAttributeIndex]['onyx_attribute'])) {
//                     $attributeSku = $requiredAttributes[$skuAttributeIndex]['onyx_attribute'];
//                 }
//             }

//             if (!empty($specifics['category_template'])) {
//                 $ids = $specifics['ids'];

//                 //get products from mongo db
//                 $productCollection = $this->_baseMongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
//                 $products = $productCollection->find(['source_product_id' => ['$in' => $ids]], ["typeMap" => ['root' => 'array', 'document' => 'array']]);

//                 foreach ($products as $key => $product) {
//                     // case 1 : for configurable products
//                     if ($product['type'] == 'variation' && !isset($product['group_id'])) {

//                         // get childs for parent products
//                         $childs = $productCollection->find(['group_id' => ['$in' => [$product['source_product_id']]]], ["typeMap" => ['root' => 'array', 'document' => 'array']]);

//                         if (!empty($childs->toArray())) {
//                             foreach ($childs as $childKey => $child) {
//                                 if (isset($product[$attributeSku]) && $product[$attributeSku]) {
//                                     $sku = $product[$attributeSku];
//                                     $feedContent[$sku]['SKU'] = $sku;
//                                     $feedContent[$sku]['Id'] = $child['_id'] . $childKey;
//                                 }
//                             }
//                         }
//                     } else {
//                         if ($product[$attributeSku]) {
//                             $sku = $product[$attributeSku];
//                             $feedContent[$sku]['SKU'] = $sku;
//                             $feedContent[$sku]['Id'] = $product['_id'] . $key;
//                         }
//                     }
//                 }
//             }
//         }

//         return $feedContent;
//     }

//     public function getVendor($id)
//     {

//         $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//         $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
//         $userId = $this->di->getUser()->id;

//         $response = $productCollection->aggregate([
//             [
//                 '$match' => ['user_id' => $userId],
//             ],
//             [
//                 '$group' => [
//                     '_id' => $userId,
//                     'brand' => [
//                         '$addToSet' => '$brand',
//                     ],
//                 ],
//             ],
//         ])->toArray();

//         return ['success' => true, 'data' => $response[0]['brand']];
//     }

//     public function getVariantAttributes()
//     {

//         $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//         $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
//         $userId = $this->di->getUser()->id;

//         $response = $productCollection->aggregate([
//             [

//                 '$match' => ['user_id' => $userId],
//             ],
//             [
//                 '$group' => [
//                     '_id' => $userId,
//                     'variant_attributes' => [
//                         '$addToSet' => '$variant_attributes',
//                     ],
//                 ],
//             ],

//         ])->toArray();
//         if (count($response) == 0) {
//             return ['success' => false, 'data' => $response];
//         }
//         return ['success' => true, 'data' => $response[0]['variant_attributes']];
//     }

//     public function getShopifyAttributes($id)
//     {
//         $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//         $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
//         if (isset($id['container_id'])) {
//             $response = $productCollection->aggregate([
//                 [
//                     '$match' => ['container_id' => $id['container_id']],
//                 ],
//                 [
//                     '$unwind' => '$variant_attributes',
//                 ],
//                 [
//                     '$group' => [
//                         '_id' => $id['container_id'],
//                         'variant_attributes' => [
//                             '$addToSet' => '$variant_attributes',
//                         ],
//                     ],
//                 ],
//             ])->toArray();
//         } else {
//             $userId = $this->di->getUser()->id;
//             $response = $productCollection->aggregate([
//                 [
//                     '$match' => ['user_id' => $userId],
//                 ],
//                 [
//                     '$unwind' => '$variant_attributes',
//                 ],
//                 [
//                     '$group' => [
//                         '_id' => $userId,
//                         'variant_attributes' => [
//                             '$addToSet' => '$variant_attributes',
//                         ],
//                     ],
//                 ],
//             ])->toArray();

//             // print_r($response);
//         }

//         $newAttribute = [];
//         $newAttribute = $response;

//         $unique = [];
//         $temp = [];

//         $temp = array_unique((array) $newAttribute[0]['variant_attributes']);
//         foreach ($temp as $value) {
//             array_push($unique, ['code' => $value, 'title' => $value, 'required' => 0]);
//         }

//         $fixedAttributes = [
//             //            [
//             //                'code' => 'vendor',
//             //                'title' => 'Vendor',
//             //                'required' => 0,
//             //            ],
//             [
//                 'code' => 'handle',
//                 'title' => 'Handle',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'product_type',
//                 'title' => 'Product Type',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'type',
//                 'title' => 'type',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'title',
//                 'title' => 'title',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'brand',
//                 'title' => 'brand',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'description',
//                 'title' => 'description',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'tags',
//                 'title' => 'tags',
//                 'required' => 0,
//             ],
//             //            [
//             //                'code' => 'collection',
//             //                'title' => 'collection',
//             //                'required' => 0
//             //            ],
//             //            [
//             //                'code' => 'position',
//             //                'title' => 'position',
//             //                'required' => 0
//             //            ],
//             [
//                 'code' => 'sku',
//                 'title' => 'sku',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'price',
//                 'title' => 'price',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'quantity',
//                 'title' => 'quantity',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'weight',
//                 'title' => 'weight',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'weight_unit',
//                 'title' => 'weight_unit',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'grams',
//                 'title' => 'grams',
//                 'required' => 0,
//             ],
//             [
//                 'code' => 'barcode',
//                 'title' => 'barcode',
//                 'required' => 0,
//             ],
//             //            [
//             //                'code' => 'inventory_policy',
//             //                'title' => 'inventory_policy',
//             //                'required' => 0
//             //            ],
//             //            [
//             //                'code' => 'taxable',
//             //                'title' => 'taxable',
//             //                'required' => 0
//             //            ],
//             //            [
//             //                'code' => 'fulfillment_service',
//             //                'title' => 'fulfillment_service',
//             //                'required' => 0
//             //            ],
//             //            [
//             //                'code' => 'inventory_item_id',
//             //                'title' => 'inventory_item_id',
//             //                'required' => 0
//             //            ],
//             //            [
//             //                'code' => 'inventory_tracked',
//             //                'title' => 'inventory_tracked',
//             //                'required' => 0
//             //            ],
//             //            [
//             //                'code' => 'requires_shipping',
//             //                'title' => 'requires_shipping',
//             //                'required' => 0
//             //            ],
//             //            [
//             //                'code' => 'locations',
//             //                'title' => 'locations',
//             //                'required' => 0
//             //            ],
//             //            [
//             //                'code' => 'is_imported',
//             //                'title' => 'is_imported',
//             //                'required' => 0
//             //            ],
//             //            [
//             //                'code' => 'visibility',
//             //                'title' => 'visibility',
//             //                'required' => 0
//             //            ],
//             [
//                 'code' => 'variant_title',
//                 'title' => 'Variant title',
//                 'required' => 0,
//             ],
//         ];
//         $fixedAttributes = [...$fixedAttributes, ...$unique];
//         return ['success' => true, 'data' => $fixedAttributes];
//     }

//     // public function updateProfiledataMarketplacetable($filter, $data, $options = [])
//     // {
//     //     $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//     //     $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::AMAZON_PRODUCT_CONTAINER);
//     //     return $collection->updateMany($filter, $data, $options);
//     // }

// //    public function addTags($sourceProductIds, $tag)
//     //    {
//     //
//     //        if (!empty($sourceProductIds)) {
//     //            $productCollection = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->setSource(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER)->getPhpCollection();
//     //            //    $productCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
//     //            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//     //            $connectedAccounts = $commonHelper->getAllAmazonShops($this->_user_id);
//     //            foreach ($connectedAccounts as $account) {
//     //                if ($account['warehouses'][0]['status'] == 'active') {
//     //                    $activeAccounts[] = $account;
//     //                    $homeShopId = $account['_id'];
//     //                    $seller_id = $account['warehouses'][0]['seller_id'];
//     //                    $bulkOpArray = [];
//     //                    $removeError = [];
//     //                    foreach ($sourceProductIds as $sourceProductId) {
//     //                        $saveArray = [];
//     //                        $amazonData = [];
//     //                        $productData = $productCollection->findOne(['source_product_id' => (string)$sourceProductId, 'user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//     //
//     //                        if (isset($productData['marketplace']['amazon']) && is_array($productData['marketplace']['amazon'])) {
//     //                            foreach ($productData['marketplace']['amazon'] as $amazonShops) {
//     //                                if (isset($amazonShops['shop_id']) && $amazonShops['shop_id'] == $homeShopId) {
//     //                                    $saveArray = $amazonShops;
//     //                                    if (!isset($saveArray['process_tags']) || array_search($tag, $saveArray['process_tags']) === false) {
//     //                                        $saveArray['process_tags'][] = $tag;
//     //                                    }
//     //
//     //                                    $bulkOpArray[] = [
//     //                                        'updateOne' => [
//     //                                            ['source_product_id' => (string)$sourceProductId, 'marketplace.amazon.shop_id' => (string)$amazonShops['shop_id'], 'user_id' => $this->_user_id],
//     //                                            [
//     //                                                '$set' => ['marketplace.amazon.$.process_tags' => $saveArray['process_tags'], 'marketplace.amazon.$.seller_id' => $seller_id]
//     //                                            ],
//     //                                        ],
//     //                                    ];
//     //                                    //                                    $mongo1 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//     //                                    //                                    $productCollection1 = $mongo1->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
//     //                                    //                                    $productCollection1->updateOne(['source_product_id' => $sourceProductId, 'marketplace.amazon.shop_id' => $amazonShops['shop_id'], 'user_id' => $this->_user_id],
//     //                                    //                                        ['$set' => ['marketplace.amazon.$.process_tags' => $saveArray['process_tags'], 'marketplace.amazon.$.seller_id' => $seller_id]]);
//     //                                    break;
//     //                                }
//     //                            }
//     //                            if (empty($saveArray)) {
//     //                                $saveArray['shop_id'] = (string)$homeShopId;
//     //                                $saveArray['process_tags'][] = $tag;
//     //                                $saveArray['seller_id'] = $seller_id;
//     //
//     //                                $bulkOpArray[] = [
//     //                                    'updateOne' => [
//     //                                        ['source_product_id' => (string)$sourceProductId, 'user_id' => $this->_user_id],
//     //                                        [
//     //                                            '$push' => ['marketplace.amazon' => $saveArray]
//     //                                        ],
//     //                                    ],
//     //                                ];
//     //                                //                                $mongo2 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//     //                                //                                $productCollection2 = $mongo2->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
//     //                                //                                $productCollection2->updateOne(['source_product_id' => $sourceProductId, 'user_id' => $this->_user_id], ['$push' => ['marketplace.amazon' => $saveArray]]);
//     //                            }
//     //                        }
//     //
//     //                        if (empty($saveArray)) {
//     //                            $saveArray['shop_id'] = (string)$homeShopId;
//     //                            $saveArray['process_tags'][] = $tag;
//     //                            $saveArray['seller_id'] = $seller_id;
//     //                            $amazonData['marketplace']['amazon'][] = $saveArray;
//     //
//     //                            $bulkOpArray[] = [
//     //                                'updateOne' => [
//     //                                    ['source_product_id' => (string)$sourceProductId, 'user_id' => $this->_user_id],
//     //                                    [
//     //                                        '$set' => $amazonData
//     //                                    ],
//     //                                ],
//     //                            ];
//     //                            //                            $mongo3 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//     //                            //                            $productCollection3 = $mongo3->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
//     //                            //                           $res = $productCollection3->updateOne(['source_product_id' => $sourceProductId, 'user_id' => $this->_user_id], ['$set' => $amazonData]);
//     //
//     //                            //                            if ($sourceProductId == '36916246610079') {
//     //                            //                                print_r($productCollection3->explain($res));
//     //                            //                                die();
//     //                            //                            }
//     //                        }
//     //                    }
//     //
//     //                    //set array for error remove
//     //                    //                    if (in_array($tag, [\App\Amazon\Components\Common\Helper::PROCESS_TAG_UPLOAD_PRODUCT, \App\Amazon\Components\Common\Helper::PROCESS_TAG_SYNC_PRODUCT])) {
//     //                    $removeError['product'] = $sourceProductIds;
//     //                    //                    }
//     //
//     //                    //                    if ($tag == \App\Amazon\Components\Common\Helper::PROCESS_TAG_PRICE_SYNC) {
//     //                    $removeError['price'] = $sourceProductIds;
//     //                    //                    }
//     //
//     //                    //                    if ($tag == \App\Amazon\Components\Common\Helper::PROCESS_TAG_INVENTORY_SYNC) {
//     //                    $removeError['inventory'] = $sourceProductIds;
//     //                    //                    }
//     //
//     //                    //                    if ($tag == \App\Amazon\Components\Common\Helper::PROCESS_TAG_IMAGE_SYNC) {
//     //                    $removeError['image'] = $sourceProductIds;
//     //                    //                    }
//     //
//     //                    //                    if ($tag == \App\Amazon\Components\Common\Helper::PROCESS_TAG_DELETE_PRODUCT) {
//     //                    $removeError['delete'] = $sourceProductIds;
//     //                    //                    }
//     //
//     //                    $response = $productCollection->BulkWrite($bulkOpArray, ['w' => 1]);
//     //
//     //                    if (!empty($removeError)) {
//     //                        foreach ($removeError as $errorType => $sourceProductIds) {
//     //                            $feed = $this->di->getObjectManager()
//     //                                ->get(Feed::class)
//     //                                ->init(['user_id' => $this->_user_id])
//     //                                ->removeErrorQuery($sourceProductIds, $homeShopId, $errorType);
//     //                        }
//     //                    }
//     //                }
//     //            }
//     //        }
//     //    }

//     public function addTags($sourceProductIds, $tag, $targetShopId, $sourceShopId, $userId, $products = [],$unsetTag = false): void
//     {
//         $date = date(DATE_RFC2822);
//         $time = str_replace("+0000","GMT", $date);
//         $message = $this->getMessage($tag);

//         if (!empty($sourceProductIds)) {
//             $productInsert = [];
//             foreach ($sourceProductIds as $sourceProductId) {
//                 $foundProduct = null;
//                 $processTag = [];
//                 $alreadySavedProcessTag = [];
//                 $marketplace = false;
//                 if (!empty($products)) {
//                     $productKey = array_search($sourceProductId, array_column($products, 'source_product_id'));
//                     if ($productKey !== false) {
//                         $foundProduct = $products[$productKey];
//                     }
//                 }

//                 if (!$foundProduct) {
//                     $helper = $this->di->getObjectManager()->get(LocalDeliveryHelper::class);
//                     $specifics = [
//                         'source_product_id' => $sourceProductId,
//                         'source_shop_id' => $sourceShopId,
//                         'target_shop_id' => $targetShopId,
//                         'user_id' => $userId,
//                     ];
//                     $productData = $helper->getSingleProduct($specifics);
//                     // print_r();die;
//                     $foundProduct = $productData;
//                     $this->di->getLog()->logContent(print_r($foundProduct, true), 'info', 'newbee.log');

//                 }

//                 if ($foundProduct) {
//                     $foundProduct = json_decode(json_encode($foundProduct), true);
//                     if (isset($foundProduct['marketplace'])) {
//                         $marketplace = $this->getMarketplace($foundProduct, $targetShopId, $sourceProductId);
//                     }

//                     $onlineSourceProductId = $foundProduct['online_source_product_id'];
//                     $sku = $foundProduct['sku'];
//                     // if(isset($foundProduct['map_location_id']) && $foundProduct['map_location_id'] != null){

//                     // }
//                     $merchantShippingGroupName = $foundProduct['merchant_shipping_group_name'];
//                     if ($marketplace) {
//                         if (isset($marketplace['process_tags'])) {
//                             $processTag = $marketplace['process_tags'];
//                             if (!in_array($tag,array_keys($processTag))){   
//                                 $processTag = $marketplace['process_tags'];
//                                 $alreadySavedProcessTag = $processTag;
//                             }
//                             else if($unsetTag)
//                             {
//                                 unset($processTag[$unsetTag]);
//                             }
//                         }   

//                     if (empty(($alreadySavedProcessTag)) || array_search($tag, array_keys($alreadySavedProcessTag)) === false) {
//                         $data = [
//                                 'msg' => $message,
//                                 'time' => $time
//                                 ];
//                         $processTag[$tag] = $data;
//                     }
//                     } else {
//                         $data = ['msg' => $message,
//                                  'time' => $time
//                                 ];
//                         $processTag[$tag] = $data;
//                     }

//                     if (!empty($processTag) && !empty(array_diff(array_keys($processTag), array_keys($alreadySavedProcessTag)))) {

//                         if(in_array($unsetTag,array_keys($processTag)))
//                         {
//                             unset($processTag[$unsetTag]);
//                         }

//                         $product = [
//                             "source_product_id" => (string) $sourceProductId, // required
//                             'user_id' => $userId,
//                             'source_shop_id' => $sourceShopId,
//                             'container_id' => (string) $foundProduct['container_id'],
//                             'childInfo' => [
//                                 'sku' => $sku,
//                                 "merchant_shipping_group_name" => $merchantShippingGroupName,
//                                 'online_source_product_id' => (string) $sourceProductId,
//                                 'source_product_id' => $onlineSourceProductId, // required
//                                 'shop_id' => $targetShopId, // required
//                                 'process_tags' => $processTag,
//                                 'target_marketplace' => 'amazon',
//                                 'local_delivery' => true // required
//                             ],
//                         ];
//                         array_push($productInsert, $product);

//                     }
//                 }
//             }

//             $this->di->getLog()->logContent(print_r($productInsert, true), 'info', 'addTag.log');
//             // die;
//             if (!empty($productInsert)) {
//                 $objectManager = $this->di->getObjectManager();
//                 $helper = $objectManager->get(LocalDeliveryHelper::class);
//                 $res = $helper->marketplaceSaveAndUpdate($productInsert);

//             }
//         }
//     }

//         public function getMessage($tag)
//         {
//             $msg = "";
//                 $msg = match ($tag) {
//                     Helper::PROCESS_TAG_UPLOAD_PRODUCT => Helper::UPLOAD_MESSAGE,
//                     Helper::PROCESS_TAG_INVENTORY_SYNC => Helper::INVENTORY_MESSAGE,
//                     Helper::PROCESS_TAG_IMAGE_SYNC => Helper::IMAGE_MESSAGE,
//                     Helper::PROCESS_TAG_PRICE_SYNC => Helper::PRICE_MESSAGE,
//                     Helper::PROCESS_TAG_DELETE_PRODUCT => Helper::DELETE_MESSAGE,
//                     Helper::PROCESS_TAG_SYNC_PRODUCT => Helper::SYNC_PRODUCT,
//                     Helper::PROCESS_TAG_UPLOAD_FEED => Helper::UPLOAD_MESSAGE_FEED,
//                     Helper::PROCESS_TAG_INVENTORY_FEED => Helper::INVENTORY_MESSAGE_FEED,
//                     Helper::PROCESS_TAG_IMAGE_FEED => Helper::IMAGE_MESSAGE_FEED,
//                     Helper::PROCESS_TAG_PRICE_FEED => Helper::PRICE_MESSAGE_FEED,
//                     Helper::PROCESS_TAG_DELETE_FEED => Helper::DELETE_MESSAGE_FEED,
//                     Helper::PROCESS_TAG_UPDATE_FEED => Helper::SYNC_PRODUCT_FEED,
//                     default => $msg,
//                 };
//                 return $msg;
//         }

//     public function removeErrorQuery($sourceProductIds, $type, $targetShopId, $sourceShopId, $userId, $products = []): void
//     {
//         if (!empty($sourceProductIds)) {
//             $productInsert = [];
//             foreach ($sourceProductIds as $sourceProductId) {
//                 $foundProduct = null;
//                 $error = [];
//                 $alreadySavedError = [];
//                 if (!empty($products)) {
//                     $productKey = array_search($sourceProductId, array_column($products, 'source_product_id'));
//                     if ($productKey !== false) {
//                         $foundProduct = $products[$productKey];
//                     }
//                 }

//                 if (!$foundProduct) {
//                     $helper = $this->di->getObjectManager()->get(LocalDeliveryHelper::class);
//                     $productData = $helper->getSingleProduct([
//                         'source_product_id' => $sourceProductId,
//                         'source_shop_id' => $sourceShopId,
//                         'target_shop_id' => $targetShopId,
//                         'user_id' => $userId,
//                     ]);
//                     $foundProduct = $productData;
//                 }

//                 if ($foundProduct) {
//                     $foundProduct = json_decode(json_encode($foundProduct), true);
//                     if (isset($foundProduct['marketplace'])) {
//                         $amazonData = $this->getMarketplace($foundProduct, $targetShopId, $sourceProductId);
//                         $onlineSourceProductId = $foundProduct['online_source_product_id'];
//                         $sku = $foundProduct['sku'];
//                         $merchantShippingGroupName = $foundProduct['merchant_shipping_group_name'];
//                         // foreach ($foundProduct['marketplace'] as $amazonData) {
//                         //     if ($amazonData['shop_id'] == $targetShopId && $amazonData['source_product_id'] == $sourceProductId) {
//                         if (isset($amazonData['error'])) {
//                             $alreadySavedError = $amazonData['error'];
//                             $error = $amazonData['error'];
//                             if (isset($error[$type])) {
//                                 unset($error[$type]);
//                             }
//                             // condition for product delete
//                             else if($type == "delete")
//                             {
//                                 $error = [];
//                             }
//                         }
//                     }

//                     //     }
//                     // }

//                     if (!empty(array_diff(array_keys($alreadySavedError), array_keys($error)))) {
//                         if (empty($error)) {
//                             $product = [
//                                 "source_product_id" => (string) $sourceProductId, // required
//                                 'user_id' => $userId,
//                                 'source_shop_id' => $sourceShopId,
//                                 'container_id' => (string) $foundProduct['container_id'],
//                                 'childInfo' => [
//                                     'sku' => $sku,
//                                     "merchant_shipping_group_name" => $merchantShippingGroupName,
//                                     'online_source_product_id' => (string) $sourceProductId,
//                                     'source_product_id' => $onlineSourceProductId, // required
//                                     'shop_id' => $targetShopId, // required
//                                     'target_marketplace' => 'amazon',
//                                     'local_delivery' => true // required
//                                 ],
//                                 'unset' => ['error'],
//                             ];
//                             // $product = [
//                             //     "source_product_id" => (string) $sourceProductId, // required
//                             //     'user_id' => $userId,
//                             //     'source_shop_id' => $sourceShopId,
//                             //     'container_id' => (string) $foundProduct['container_id'],
//                             //     'childInfo' => [
//                             //         'source_product_id' => (string) $sourceProductId, // required
//                             //         'shop_id' => $targetShopId, // required
//                             //         'target_marketplace' => 'amazon', // required
//                             //     ],
//                             //     'unset' => ['error'],
//                             // ];

//                         } else {
//                             $product = [
//                                 "source_product_id" => (string) $sourceProductId, // required
//                                 'user_id' => $userId,
//                                 'source_shop_id' => $sourceShopId,
//                                 'container_id' => (string) $foundProduct['container_id'],
//                                 'childInfo' => [
//                                     'sku' => $sku,
//                                     "merchant_shipping_group_name" => $merchantShippingGroupName,
//                                     'online_source_product_id' => (string) $sourceProductId,
//                                     'source_product_id' => $onlineSourceProductId, // required
//                                     'shop_id' => $targetShopId, // required
//                                     'target_marketplace' => 'amazon',
//                                     'local_delivery' => true // required
//                                 ],
//                                 'unset' => ['error'],
//                             ];
//                         }

//                         array_push($productInsert, $product);

//                     }
//                 }
//             }

//             if (!empty($productInsert)) {

//                 $objectManager = $this->di->getObjectManager();
//                 $helper = $objectManager->get('\App\Connector\Amazon\LocalDeliveryHelper');
//                 $res = $helper->marketplaceSaveAndUpdate($productInsert);
//             }
//         }
//     }

//     public function removeTag($sourceProductIds, $tags, $targetShopId, $sourceShopId, $userId, $products = [], $feed = []): void
//     {
//         // $this->di->getLog()->logContent(print_r($sourceProductIds, true), 'info', 'lll.log');
//         $date = date('d-m-Y');
//         if (!$targetShopId) {
//             $targetShopId = $feed['specifics']['shop_id'];
//         }

//         if (!$userId) {
//             $userId = $feed['user_id'];
//         }

//         if (!$sourceShopId) {
//             $sourceShopId = $feed['source_shop_id'];
//         }

//         if (empty($sourceProductIds)) {
//             $sourceProductIds = $feed['specifics']['ids'];
//         }

//         $logFile = "amazon/productNotFound/{$sourceShopId}/{$date}.log";
//         if (empty($tags)) {
//             $feedType = $feed['type'];

//             $action = Feed::FEED_TYPE[$feedType];
//             if ($action == 'product') {
//                 $tags = [
//                     Helper::PROCESS_TAG_UPLOAD_FEED,
//                     Helper::PROCESS_TAG_UPDATE_FEED
//                 ];
//             } elseif ($action == 'inventory') {
//                 $tags = [
//                     Helper::PROCESS_TAG_INVENTORY_FEED
//                 ];
//             } elseif ($action == 'price') {
//                 $tags = [
//                     Helper::PROCESS_TAG_PRICE_FEED

//                 ];
//             } elseif ($action == 'image') {
//                 $tags = [
//                     Helper::PROCESS_TAG_IMAGE_FEED
//                 ];
//             } elseif ($action == 'delete') {
//                 $tags = [
//                     Helper::PROCESS_TAG_DELETE_FEED
//                 ];
//             }
//         }

//         //change array of int to array of string

//         $encoded = implode(',', $sourceProductIds);
//         // print_r($encoded);die;
//         $sourceProductIds = explode(',', $encoded);
//         $productInsert = [];

//         if (!empty($sourceProductIds)) {
//             foreach ($sourceProductIds as $sourceProductId) {
//                 $foundProduct = null;
//                 $processTag = [];
//                 $alreadySavedProcessTag = [];
//                 if (!empty($products)) {
//                     $productKey = array_search($sourceProductId, array_column($products, 'source_product_id'));
//                     if ($productKey !== false) {
//                         $foundProduct = $products[$productKey];
//                     }
//                 }

//                 if (!$foundProduct) {
//                     $data = ['source_product_id' => $sourceProductId, "target_shop_id" => (string) $targetShopId, 'source_shop_id' => (string) $sourceShopId, 'user_id' => (string) $userId];
//                     $productMarketplace = $this->di->getObjectManager()->get(LocalDelivery::class);
//                     $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
//                     $query = [
//                         'user_id' => $userId, 'shop_id' => $sourceShopId, 'source_product_id' => $sourceProductId
//                     ];
//                     $productFetch =  $productMarketplace->getproductbyQueryLocalDelivery($query, $options);

//                     if(isset($productFetch) && isset($productFetch[0]))
//                     {
//                         $foundProduct = $productFetch[0];
//                     }
//                     else
//                     {
//                         $this->di->getLog()->logContent('id ->: ' . print_r($sourceProductId, true), 'info', $logFile);
//                     }

//                     // $data = ['source_product_id' => (string) $sourceProductId, "target_shop_id" => (string) $targetShopId, 'source_shop_id' => (string) $sourceShopId, 'user_id' => (string) $userId];
//                     // $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
//                     // $productData = $helper->getSingleProduct($data);
//                     // $foundProduct = $productData;
//                 }

//                 if ($foundProduct) {

//                     $foundProduct = json_decode(json_encode($foundProduct), true);
//                     // $this->di->getLog()->logContent(print_r($foundProduct, true), 'info', 'lll.log');


//                     if (isset($foundProduct['marketplace'])) {
//                         $amazonData = $this->getMarketplace($foundProduct, $targetShopId, $sourceProductId);
//                         $onlineSourceProductId = $foundProduct['online_source_product_id'];
//                         $sku = $foundProduct['sku'];
//                         $merchantShippingGroupName = $foundProduct['merchant_shipping_group_name'];
//                         // $this->di->getLog()->logContent(print_r($foundProduct, true), 'info', 'lll.log');

//                         // foreach ($foundProduct['marketplace'] as $amazonData) {
//                         //     if ($amazonData['shop_id'] == $targetShopId && $amazonData['source_product_id'] == $sourceProductId) {

//                         if (isset($amazonData['process_tags'])) {
//                             $processTag = $amazonData['process_tags'];
//                             $alreadySavedProcessTag = $amazonData['process_tags'];
//                         }

//                         if (!empty($tags) && !empty($processTag)) {
//                             foreach ($tags as $tag) {
//                                 if(isset($processTag[$tag]))
//                                 {
//                                     unset($processTag[$tag]);
//                                 }
//                             }
//                         }
//                     }


//                     if (!empty(array_diff(array_keys($alreadySavedProcessTag), array_keys($processTag)))) {
//                         if (empty($processTag)) {
//                             $product = [
//                                 "source_product_id" => $sourceProductId, // required
//                                 'user_id' => $userId,
//                                 'source_shop_id' => $sourceShopId,
//                                 'container_id' => (string) $foundProduct['container_id'],
//                                 'childInfo' => [
//                                     'sku' => $sku,
//                                     "merchant_shipping_group_name" => $merchantShippingGroupName,
//                                     'online_source_product_id' => $sourceProductId,
//                                     'source_product_id' => $onlineSourceProductId, // required
//                                     'shop_id' => $targetShopId, // required
//                                     'process_tags' => array_values($processTag),
//                                     'target_marketplace' => 'amazon',
//                                     'local_delivery' => true // required
//                                 ],
//                                 'unset' => ['process_tags'],
//                             ];

//                             // $product = [
//                             //     "source_product_id" => (string) $sourceProductId, // required
//                             //     'user_id' => $userId,
//                             //     'source_shop_id' => $sourceShopId,
//                             //     'container_id' => (string) $foundProduct['container_id'],
//                             //     'childInfo' => [
//                             //         'source_product_id' => (string) $sourceProductId, // required
//                             //         'shop_id' => $targetShopId, // required
//                             //         // 'process_tags' => array_values($processTag),
//                             //         'target_marketplace' => 'amazon', // required
//                             //     ],
//                             //     'unset' => ['process_tags'],
//                             // ];
//                         } else {
//                             $product = [
//                                 "source_product_id" => $sourceProductId, // required
//                                 'user_id' => $userId,
//                                 'source_shop_id' => $sourceShopId,
//                                 'container_id' => (string) $foundProduct['container_id'],
//                                 'childInfo' => [
//                                     'sku' => $sku,
//                                     "merchant_shipping_group_name" => $merchantShippingGroupName,
//                                     'online_source_product_id' => $sourceProductId,
//                                     'source_product_id' => $onlineSourceProductId, // required
//                                     'shop_id' => $targetShopId, // required
//                                     'target_marketplace' => 'amazon',
//                                     'local_delivery' => true, // required
//                                     'process_tags' => $processTag,
//                                 ]
//                             ];
//                             // $product = [
//                             //     "source_product_id" => (string) $sourceProductId, // required
//                             //     'user_id' => $userId,
//                             //     'source_shop_id' => $sourceShopId,
//                             //     'container_id' => (string) $foundProduct['container_id'],
//                             //     'childInfo' => [
//                             //         'source_product_id' => (string) $sourceProductId, // required
//                             //         'shop_id' => $targetShopId, // required
//                             //         'process_tags' => $processTag,
//                             //         'target_marketplace' => 'amazon', // required
//                             //     ],
//                             // ];
//                         }

//                         array_push($productInsert, $product);
//                     }
//                 }
//             }

//             // $this->di->getLog()->logContent(print_r($productInsert, true), 'info', 'lll.log');
//             // print_r($productInsert);die;

//             if (!empty($productInsert)) {
//                 $objectManager = $this->di->getObjectManager();
//                 $helper = $objectManager->get(LocalDeliveryHelper::class);
//                 $res = $helper->marketplaceSaveAndUpdate($productInsert);
//             }
//         }
//     }

//     public function saveErrorInProduct($sourceProductIds, $type, $targetShopId, $sourceShopId, $userId, $products = [], $feed = []): void
//     {
//         $errorVariantList = [];

//         $this->di->getLog()->logContent(print_r($sourceProductIds, true), 'info', 'saveErrorInProduct.log');

//         if (!$targetShopId) {
//             $targetShopId = $feed['specifics']['shop_id'];
//         }

//         if (!$type) {
//             $feedType = $feed['type'];
//             $type = Feed::FEED_TYPE[$feedType];
//         }

//         if (!$userId && isset($feed['user_id'])) {
//             $userId = $feed['user_id'];
//         }

//         if (!$sourceShopId && isset($feed['source_shop_id'])) {
//             $sourceShopId = $feed['source_shop_id'];
//         }

//         if (!empty($sourceProductIds)) {
//             $productInsert = [];
//             foreach ($sourceProductIds as $sourceProductId => $errorMsg) {
//                 $errorVariantList[] = $sourceProductId;
//                 $foundProduct = null;
//                 $error = [];
//                 $alreadySavedError = [];
//                 if (!empty($products)) {
//                     $productKey = array_search($sourceProductId, array_column($products, 'source_product_id'));
//                     if ($productKey !== false) {
//                         $foundProduct = $products[$productKey];
//                     }
//                 }

//                 if (!$foundProduct) {
//                     $data = ['source_product_id' => (string) $sourceProductId, "target_shop_id" => (string) $targetShopId, 'source_shop_id' => (string) $sourceShopId, 'user_id' => (string) $userId];
//                     $helper = $this->di->getObjectManager()->get(LocalDeliveryHelper::class);
//                     $productData = $helper->getSingleProduct($data);

//                     $foundProduct = $productData;
//                     $onlineSourceProductId = $foundProduct['online_source_product_id'];
//                     $sku = $foundProduct['sku'];
//                     $merchantShippingGroupName = $foundProduct['merchant_shipping_group_name'];

//                 }

//                 if ($foundProduct) {
//                     $error[$type] = $errorMsg;
//                     if (!empty($error)) {
//                         $product = [
//                             "source_product_id" => (string) $sourceProductId, // required
//                             'user_id' => $userId,
//                             'source_shop_id' => $sourceShopId,
//                             'container_id' => (string) $foundProduct['container_id'],
//                             'childInfo' => [
//                                 'source_product_id' => (string) $onlineSourceProductId,
//                                 // required
//                                 'shop_id' => $targetShopId,
//                                 // required
//                                 'error' => $error,
//                                 'target_marketplace' => 'amazon',
//                                     // required
//                                 // 'sku' => $sku,
//                                 // "merchant_shipping_group_name" => $merchantShippingGroupName,
//                                 // 'online_source_product_id' => (string) $onlineSourceProductId,
//                                 // 'source_product_id' => $sourceProductId, // required
//                                 // 'shop_id' => $targetShopId, // required
//                                 // 'error' => $error,
//                                 // 'target_marketplace' => 'amazon',
//                                 // 'local_delivery' => true // required
//                             ]
//                         ];
//                         // $product = [
//                         //     "source_product_id" => (string) $sourceProductId, // required
//                         //     'user_id' => $userId,
//                         //     'source_shop_id' => $sourceShopId,
//                         //     'container_id' => (string) $foundProduct['container_id'],
//                         //     'childInfo' => [
//                         //         'source_product_id' => (string) $sourceProductId, // required
//                         //         'shop_id' => $targetShopId, // required
//                         //         'error' => $error,
//                         //         'target_marketplace' => 'amazon', // required
//                         //     ],
//                         // ];
//                         array_push($productInsert, $product);

//                     }
//                 }
//             }

//             if (!empty($productInsert)) {
//                 $objectManager = $this->di->getObjectManager();
//                 $helper = $objectManager->get(LocalDeliveryHelper::class);
//                 $res = $helper->marketplaceSaveAndUpdate($productInsert);

//             }
//         }

//         $objectManager = $this->di->getObjectManager();
//         $feedHelper = $objectManager->get(Feed::class);
//         $feedHelper->removeErrorFromProduct($errorVariantList, $feed);
//     }

//     public function setUploadedStatus($variantList, $feed): void
//     {
//         $marketplaceHelper = $this->di->getObjectManager()->get(LocalDeliveryHelper::class);

//         $homeShopId = $feed['shop_id'];
//         $sourceShopId = $feed['source_shop_id'];
//         $userId = $feed['user_id'];

//         $uploadedVariant = [];
//         $remoteShopId = 0;
//         $shop = $this->_user_details->getShop($homeShopId, $userId);
// //        $sellerId = $shop['warehouses'][0]['seller_id'];

//         if ($shop && isset($shop['remote_shop_id'])) {
//             $remoteShopId = $shop['remote_shop_id'];
//         }

//         // converting int to string in array
//         $variantList = implode(',', $variantList);
//         $variantList = explode(',', $variantList);

//         foreach ($variantList as $sourceProductId) {
//             $canChangeStatus = true;
//             $specifics = [
//                 'source_product_id' => $sourceProductId,
//                 'source_shop_id' => $sourceShopId,
//                 'target_shop_id' => $homeShopId,
//                 'user_id' => $userId,
//             ];
//             $productData = $marketplaceHelper->getSingleProduct($specifics);
//             if (isset($productData['marketplace']) && !empty($productData['marketplace'])) {
//                 // foreach ($productData['marketplace'] as $marketplaceData) {
//                 $marketplaceData = $this->getMarketplace($productData, $homeShopId, $sourceProductId);
//                 if ($marketplaceData['shop_id'] == $homeShopId && $marketplaceData['source_product_id'] == $sourceProductId) {
//                     if (isset($amazonShops['status']) && $amazonShops['status'] != \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER) {
//                         $canChangeStatus = false;
//                     }

//                     // break;
//                 }
//             }

//             // }

//             if ($canChangeStatus) {
//                 $amazonProduct =false;
//                 if(isset($product['edited'])){

//                     $amazonProduct = $productData['edited'];
//                 }

//                 if ($amazonProduct && isset($amazonProduct['parent_sku']) && !empty($amazonProduct['parent_sku'])) {
//                     $sku = $amazonProduct['parent_sku'];
//                 } elseif ($amazonProduct && isset($amazonProduct['sku']) && !empty($amazonProduct['sku'])) {
//                     $sku = $amazonProduct['sku'];
//                 } elseif (isset($productData['sku']) && !empty($productData['sku'])) {
//                     $sku = $productData['sku'];
//                 } else {
//                     $sku = $productData['source_product_id'];
//                 }

//                 $searchSku[$productData['source_product_id']] = $sku;
//             }
//         }

//         if (!empty($searchSku)) {
//             $skus = array_values($searchSku);
//             $chunk = array_chunk($skus, 5);
//             $commonHelper = $this->di->getObjectManager()->get(Helper::class);

//             foreach ($chunk as $value) {
//                 $specifics['id'] = $value;
//                 $specifics['shop_id'] = $remoteShopId;
//                 $specifics['home_shop_id'] = $homeShopId;
//                 $specifics['id_type'] = 'SKU';
//                 $response = $commonHelper->sendRequestToAmazon('product', $specifics, 'GET');

//                 if (isset($response['success']) && $response['success']) {
//                     if (isset($response['response']) && is_array($response['response'])) {
//                         foreach ($response['response'] as $barcode => $asin) {
//                             $uploadedVariant[] = array_search($barcode, $searchSku);
// //                            $uploadedContainerIds[] = $productData['container_id']; //060921
//                         }
//                     }
//                 }
//             }
//         }

//         $updateDataInsert = [];
//         foreach ($uploadedVariant as $variant) {
//             $product = [
//                 "source_product_id" => $sourceProductId, // required
//                 'user_id' => $userId,
//                 'source_shop_id' => $sourceShopId,
//                 'container_id' => (string) $foundProduct['container_id'],
//                 'childInfo' => [
//                     'sku' => $sku,
//                     "merchant_shipping_group_name" => $merchantShippingGroupName,
//                     'online_source_product_id' => $sourceProductId,
//                     'source_product_id' => $onlineSourceProductId, // required
//                     'shop_id' => $homeShopId, // required
//                     'status' => Helper::PRODUCT_STATUS_UPLOADED,
//                     'target_marketplace' => 'amazon',
//                     'local_delivery' => true // required
//                 ]
//             ];


//             // $updateData = [
//             //     "source_product_id" => (string) $variant, // required
//             //     'user_id' => $userId,
//             //     'source_shop_id' => $sourceShopId,
//             //     'childInfo' => [
//             //         'source_product_id' => (string) $variant, // required
//             //         'shop_id' => $homeShopId, // required
//             //         'status' => \App\Amazon\Components\Common\Helper::PRODUCT_STATUS_UPLOADED,
//             //         'target_marketplace' => 'amazon', // required
//             //     ],
//             // ];
//             array_push($updateDataInsert, $updateData);
//         }

//         if (!empty($updateDataInsert)) {
//             $res = $marketplaceHelper->marketplaceSaveAndUpdate($updateDataInsert);

//         }

//     }

//     /**
//      * @param $response
//      * @param $feedContent
//      * @param $targetShop
//      * @param $targetShopId
//      * @param $sourceShopId
//      * @param $userId
//      */
//     #[ArrayShape(['addTagProductList' => "array", 'removeErrorProductList' => "array", 'removeTagProductList' => "array|int[]|string[]", 'productErrorList' => "array"])]
//     public function processResponse($response = [], $feedContent = null, $tag = null, $type = null, $targetShop = null, $targetShopId = null, $sourceShopId = null, $userId = null, $products = [], $productErrorList = [], $unsetTag = false): array
//     {
//         $this->di->getLog()->logContent(print_r($response, true), 'info', 'fedu.log');
//         $addTagProductList = [];
//         $removeErrorProductList = [];
//         $removeTagProductList = [];
//         // $productErrorList = [];

//         foreach ($targetShop['warehouses'] as $warehouse) {
//             $marketplaceId = $warehouse['marketplace_id'];
//         }

//         if (isset($response['success']) && $response['success']) {
//             if (isset($response['result'][$marketplaceId]['response'])) {
//                 $remoteResponse = $response['result'][$marketplaceId]['response'];
//                 foreach ($remoteResponse as $sourceProductId => $productResponse) {
//                     if (isset($productResponse['success']) && $productResponse['success']) {
//                         $addTagProductList[] = $sourceProductId;
//                         $removeErrorProductList[] = $sourceProductId;
//                     } elseif (isset($productResponse['message'])) {
//                         $removeTagProductList[] = $sourceProductId;
//                         $productErrorList[$sourceProductId] = [$productResponse['message']];
//                     } else {
//                         $removeTagProductList[] = $sourceProductId;
//                         $productErrorList[$sourceProductId] = ['Feed not generated. Kindly contact with support'];
//                     }
//                 }
//             }
//         } else {
//             if (isset($response['msg'])) {
//                 $removeTagProductList = array_keys($feedContent);
//                 $productErrorList = array_fill_keys($removeTagProductList, ['Feed not generated. Kindly contact with support.']);
//                 $this->di->getLog()->logContent(print_r($productErrorList, true), 'info', 'processResponse.log');
//             }
//         }

//         if (!empty($productErrorList) && empty($removeTagProductList)) {
//             $removeTagProductList = array_keys($productErrorList);
//             $this->di->getLog()->logContent(print_r($removeTagProductList, true), 'info', 'removeTagProduct.log');

//         }

//         // add tags in products
//         if (!empty($addTagProductList)) {

//             $this->addTags($addTagProductList, $tag, $targetShopId, $sourceShopId, $userId, $products, $unsetTag);
//             //  $this->di->getLog()->logContent(print_r($addTagProductList, true), 'info', 'addTagProductList.log');

//         }

//         // remove error in products
//         if (!empty($removeErrorProductList)) {
//             $this->removeErrorQuery($removeErrorProductList, $type, $targetShopId, $sourceShopId, $userId, $products);
//             // $this->di->getLog()->logContent(print_r($removeErrorProductList, true), 'info', 'removeErrorProductList.log');

//         }

//         // remove tags in products
//         if (!empty($removeTagProductList)) {
//             $finalTag = $unsetTag ?: $tag; 
//             $tags = [
//                 $finalTag,
//             ];
//             $this->removeTag($removeTagProductList, $tags, $targetShopId, $sourceShopId, $userId, $products, []);
//             // $this->di->getLog()->logContent(print_r($removeTagProductList, true), 'info', 'removeTagProductList.log');
//         }

//         //add error in products
//         if (!empty($productErrorList)) {
//             $this->saveErrorInProduct($productErrorList, $type, $targetShopId, $sourceShopId, $userId, $products, []);
//         }

//         return [
//             'addTagProductList' => $addTagProductList,
//             'removeErrorProductList' => $removeErrorProductList,
//             'removeTagProductList' => $removeTagProductList,
//             'productErrorList' => $productErrorList,
//         ];
//     }

//     /**
//      * @param $code
//      * @param $message
//      * @param $success
//      * @param $error
//      * @param $warning
//      * @return array
//      */
//     public function setResponse($code, $message, $success, $error, $warning)
//     {
//         //add timeout in sec for requeue
//         return [
//             'CODE' => $code,
//             'message' => $message,
//             'count' => [
//                 'success' => $success,
//                 'error' => $error,
//                 'warning' => $warning,
//             ],
//         ];
//     }

//     public function addStatus($filterArray, $status, $homeShopId): void
//     {
//         $shop = $this->_user_details->getShop($homeShopId, $this->_user_id);
//         $sellerId = $shop['warehouses'][0]['seller_id'];
//         $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//         $productCollection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);

//         $productData = $productCollection->findOne($filterArray, ["typeMap" => ['root' => 'array', 'document' => 'array']]);

//         if ($productData) {
//             $saveArray = [];
//             $amazonData = [];
//             if (isset($productData['marketplace']['amazon']) && is_array($productData['marketplace']['amazon'])) {
//                 foreach ($productData['marketplace']['amazon'] as $amazonShops) {
//                     if (isset($amazonShops['shop_id']) && $amazonShops['shop_id'] == $homeShopId) {
//                         $saveArray = $amazonShops;
//                         if (!isset($saveArray['status']) || $saveArray['status'] == Helper::PRODUCT_STATUS_AVAILABLE_FOR_OFFER) {
//                             $saveArray['status'] = $status;
//                             $saveArray['seller_id'] = $sellerId;
//                             // $amazonData = $productData['marketplace']['amazon'];
//                             $filter1Array = $filterArray;
//                             $filter1Array['marketplace.amazon.shop_id'] = (string) $amazonShops['shop_id'];
//                             $productCollection->updateOne(
//                                 $filter1Array,
//                                 ['$set' => ['marketplace.amazon.$.status' => $status, 'marketplace.amazon.$.seller_id' => $sellerId]]
//                             );
//                         }

//                         break;
//                     }
//                 }

//                 if (empty($saveArray)) {
//                     $saveArray['shop_id'] = (string) $homeShopId;
//                     $saveArray['status'] = $status;
//                     $saveArray['seller_id'] = $sellerId;
//                     //  $amazonData['marketplace']['amazon'][] = $saveArray
//                     $productCollection->updateOne($filterArray, ['$push' => ['marketplace.amazon' => $saveArray]]);
//                 }
//             }

//             if (empty($saveArray)) {
//                 $saveArray['shop_id'] = (string) $homeShopId;
//                 $saveArray['status'] = $status;
//                 $saveArray['seller_id'] = $sellerId;
//                 $amazonData['marketplace']['amazon'][] = $saveArray;
//                 $productCollection->updateOne($filterArray, ['$set' => $amazonData]);
//             }
//         }
//     }

//     public function removeStatusFromProduct($variantList, $targetShopId, $sourceShopId, $userId, $feed = []): void
//     {
//         $remoteShopId = 0;
//         $sourceMarketplace = "" ;
//         $shop = $this->_user_details->getShop($targetShopId, $userId);

//         if ($shop && isset($shop['remote_shop_id'])) {
//             $remoteShopId = $shop['remote_shop_id'];
//         }

//         // converting int to string in array
//         $variantList = implode(',', $variantList);
//         $variantList = explode(',', $variantList);

//         if (!$targetShopId) {
//             $targetShopId = $feed['specifics']['shop_id'];
//         }

//         if (!$userId && isset($feed['user_id'])) {
//             $userId = $feed['user_id'];
//         }

//         if (!$sourceShopId && isset($feed['source_shop_id'])) {
//             $sourceShopId = $feed['source_shop_id'];
//         }

//         $sources = $shop['sources'] ?? [];

//         if(!empty($sources))
//         {
//             foreach ($sources as $source) {
//                 if(isset($source['shop_id']) && $source['shop_id'] == $sourceShopId)
//                 {
//                     if(isset($source['code']) && !empty($osurce['code']))
//                     {
//                         $sourceMarketplace = $source['code'];
//                     }
//                 }
//             }
//         }

//         $searchSku = [];
//         $notDeletedVariant = [];
//         $productList = [];

//         foreach ($variantList as $variant) {
//             $product = [
//                 "source_product_id" => $variant,
//                 'user_id' => $userId,
//                 'target_shop_id' => $targetShopId,
//                 'source_shop_id' => $sourceShopId,
//             ];

//             $objectManager = $this->di->getObjectManager();
//             $helper = $objectManager->get(LocalDeliveryHelper::class);
//             $productData = $helper->getSingleProduct($product);
//             if(!empty($sourceMarketplace) && isset($productData['source_marketplace']))
//             {
//                 $sourceMarketplace = $productData['source_marketplace'];
//             }

//             $productList[] = $productData;
//             if (isset($productData['sku'])) {
//                 $searchSku[$productData['source_product_id']] = $productData['sku'];
//             }
//         }

//         if (!empty($searchSku)) {
//             $skus = array_values($searchSku);
//             $chunk = array_chunk($skus, 5);
//             $commonHelper = $this->di->getObjectManager()->get(Helper::class);

//             foreach ($chunk as $value) {
//                 $specifics['id'] = $value;
//                 $specifics['shop_id'] = $remoteShopId;
//                 $specifics['home_shop_id'] = $targetShopId;
//                 $specifics['id_type'] = 'SKU';
//                 $response = $commonHelper->sendRequestToAmazon('product', $specifics, 'GET');
//                 if (isset($response['success']) && $response['success']) {
//                     if (isset($response['response']) && is_array($response['response'])) {
//                         foreach ($response['response'] as $barcode => $asin) {
//                             $notDeletedVariant[] = array_search($barcode, $searchSku);
//                         }
//                     }
//                 }
//             }
//         }

//         $deletedVariant = array_diff($variantList, $notDeletedVariant);

//         foreach ($productList as $productData) {
//             if (in_array($productData['source_product_id'], $deletedVariant) && isset($productData['marketplace']) && is_array($productData['marketplace']) && isset($productData['type'])) {

//                 foreach ($productData['marketplace'] as $marketplaceData) {
//                     if (isset($marketplaceData['shop_id']) && $marketplaceData['shop_id'] == $targetShopId
//                         && ($marketplaceData['source_product_id'] == $productData['source_product_id'] || $productData['type'] == 'variation')) {

//                         if (isset($marketplaceData['status']) || isset($marketplaceData['error'])) {
//                             $product = [
//                                 "source_product_id" => (string) $marketplaceData['source_product_id'], // required
//                                 'user_id' => $userId,
//                                 'source_shop_id' => $sourceShopId,
//                                 'container_id' => (string) $productData['container_id'],
//                                 'childInfo' => [
//                                     'source_product_id' => (string) $marketplaceData['source_product_id'], // required
//                                     'shop_id' => $targetShopId, // required
//                                     'target_marketplace' => 'amazon', // required
//                                 ],
//                                 'unset' => [
//                                     'asin'
//                                 ]
//                             ];

//                             foreach ($marketplaceData as $mKey => $mData) {
//                                 if (in_array($mKey, ['source_product_id', 'shop_id', 'target_marketplace'])) {
//                                     continue;
//                                 }

//                                 if (in_array($mKey, ['asin'])) {
//                                     $product['unset'] = ['asin'];
//                                 }

//                                 if (in_array($mKey, ['error'])) {
//                                     $product['unset'] = ['error'];
//                                 }

//                                 if ($mKey == 'status' && $mData != Helper::PRODUCT_STATUS_AVAILABLE_FOR_OFFER) {
//                                     $product['unset'] = ['status'];
//                                 }

//                                 // $product['childInfo'][$mKey] = $mData;
//                             }

//                             $objectManager = $this->di->getObjectManager();
//                             $helper = $objectManager->get(LocalDeliveryHelper::class);
//                             $res = $helper->marketplaceSaveAndUpdate($product);
//                         }

//                         if (isset($productData['asin'])) {

//                             $params['source'] = [
//                                 'marketplace' => $sourceMarketplace,
//                                 'shopId' => (string) $sourceShopId
//                             ];
//                             $params['target'] = [
//                                 'marketplace' => 'amazon',
//                                 'shopId' => (string) $targetShopId
//                             ];

//                             $asinData = [
//                                 [
//                                     'target_marketplace' => 'amazon',
//                                     'shop_id' => (string) $targetShopId,
//                                     'container_id' => $productData['container_id'],
//                                     'source_product_id' => $productData['source_product_id'],
//                                     'user_id' => $userId,
//                                     'source_shop_id' => $sourceShopId,
//                                     'unset' => [
//                                         'asin' => 1,
//                                     ]
//                                 ],
//                             ];

//                             $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
//                             $result = $editHelper->saveProduct($asinData , $userId , $params);
//                         }

//                     }
//                 }
//             }
//         }
//     }

//     public function saveDefaultCategory($foundProducts): void
//     {
//         $categorySettings = [
//             'primary_category' => 'default',
//             'sub_category' => 'default',
//             'browser_node_id' => '0',
//             'barcode_exemption' => false,
//             'attributes_mapping' =>
//             [
//                 'required_attribute' =>
//                 [
//                     [
//                         'amazon_attribute' => 'condition-type',
//                         'shopify_attribute' => 'recommendation',
//                         'recommendation' => 'New',
//                         'custom_text' => '',
//                     ],
//                 ],
//                 'optional_attribute' => [],

//             ],

//         ];
//         foreach ($foundProducts as $product) {
//             $sourceProductId = $product['source_product_id'];
//             $containerId = $product['container_id'];

//             if (isset($product['group_id'])) {
//                 $groupId = $product['group_id'];
//             } else {
//                 $groupId = false;
//             }

//             $amazonSellerIds = $product['amazon_seller_ids'];

//             $this->di->getObjectManager()
//                 ->get(ProductHelper::class)
//                 ->updateProductCategory($this->_user_id, $containerId, $sourceProductId, $groupId, $amazonSellerIds, $categorySettings);
//         }
//     }

//     public function validate($feedContent, $product, $attributes, $categoryParams)
//     {
//         $error = [];
//         $sourceMarketplace = $this->di->getRequester()->getSourceName();
//         $skuAttribute = 'sku';
//         if (isset($attributes['sku'])) {
//             $skuAttribute = 'sku';
//         } elseif ($attributes['item_sku']) {
//             $skuAttribute = 'item_sku';
//         }

//         // validating sku
//         if (!isset($feedContent[$skuAttribute]) || empty($feedContent[$skuAttribute])) {
//             $error[] = 'Sku is a required field, please fill sku and upload again.';
//         }

//         //validating description length
//         if (!empty($feedContent['product_description'])) {
//             $des = strval($feedContent['product_description']);
//             $count = strlen($des);
//             if ($count >= 2000) {
//                 $error[] = 'Product Descriptions are exceeding the 2000 characters limit. HTML tags and spaces do count as characters.';
//             }
//         }

//         //validating SKU length
//         if (!empty($feedContent['item_sku'])) {
//             $sku = strval($feedContent['item_sku']);
//             $count = strlen($sku);
//             if ($count >= 40) {
//                 $error[] = 'SKU lengths are exceeding the 40 character limit.';
//             }
//         }

//         if ($product['type'] != 'variation') {
//             // validating price
//             $priceAttribute = 'price';
//             if (isset($feedContent['price'])) {
//                 $priceAttribute = 'price';
//             } elseif (isset($feedContent['standard_price'])) {
//                 $priceAttribute = 'standard_price';
//             }

//             if (!isset($feedContent[$priceAttribute])) {
//                 $error[] = 'Price is a required field, please fill price and upload again.';
//             }

//             if (isset($feedContent[$priceAttribute]) && ($feedContent[$priceAttribute] === 0 || $feedContent[$priceAttribute] == '0')) {
//                 $error[] = 'Price should be greater that 0.01, please update it from '.$sourceMarketplace.' listing page.';
//             }

//             if (isset($feedContent[$priceAttribute]) && $feedContent[$priceAttribute] === false) {
//                 $error[] = 'Price syncing is disabled, please enable it from assigned template.';
//             }

//             //validating quantity
//             if (!isset($feedContent['quantity'])) {
//                 $error[] = 'Quantity is a required field, please fill quantity and upload again.';
//             }

//             //            if (isset($feedContent['quantity']) && $feedContent['quantity'] === false) {
//             //                $error[] = 'Quantity syncing is disabled, please enable it from assigned template.';
//             //            }

//             //validating barcode

//             if (!$categoryParams['data']['barcode_exemption'] || $categoryParams['data']['category'] == 'default') {

//                 $barcodeAttribute = 'product-id';
//                 if (isset($attributes['product-id'])) {
//                     $barcodeAttribute = 'product-id';
//                 } elseif (isset($attributes['external_product_id'])) {
//                     $barcodeAttribute = 'external_product_id';
//                 }

//                 if (!isset($feedContent[$barcodeAttribute]) || empty($feedContent[$barcodeAttribute])) {
//                     $error[] = 'Barcode is a required field, please fill barcode and upload again.';
//                 } else {

//                     $barcode = $this->di->getObjectManager()->get(Barcode::class);
//                     $type = $barcode->setBarcode($feedContent[$barcodeAttribute]);

//                     if (!$type) {
//                         $error[] = 'Barcode is not valid, please provide valid barcode and upload again.';
//                     }
//                 }
//             }
//         }

//         if (!empty($error)) {
//             $feedContent['error'] = $error;
//         }

//         return $feedContent;
//     }

//     public function mapOption($amazonAttribute, $sourceAttribute, $value, $valueMappingAttributes = false)
//     {
//         $mappedValue = $value;
//         #Check Value Mapping Used

//        $sourceMarketplace = $this->di->getRequester()->getSourceName();
//        if($valueMappingAttributes && !empty($valueMappingAttributes) && isset($valueMappingAttributes[$amazonAttribute][$sourceAttribute])){         
//            foreach($valueMappingAttributes[$amazonAttribute][$sourceAttribute] as $mappedKeys => $mappedValues){
//                if($valueMappingAttributes[$amazonAttribute][$sourceAttribute][$mappedKeys][$sourceMarketplace] == $value){
//                        $mappedValue = $valueMappingAttributes[$amazonAttribute][$sourceAttribute][$mappedKeys]['amazon'];
//               }
//            }    
//        }


//        $mappedOption['60d5d70cfd78c76e34776e12']['apparel_size']['Size'] = [
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//         '4XL' => '4X-Large',
//         '5XL' => '5X-Large',
//     ];
//     $mappedOption['618865393bee1d48e665f7b7']['apparel_size']['Available Sizes'] = [
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',

//     ];

//     $mappedOption['61182f2ad4fc9a5310349827']['shirt_size']['Size'] = [
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//         '4XL' => '4X-Large',
//         '5XL' => '5X-Large',
//     ];

//     $mappedOption['61157a91ec6ed62aec72b09a']['shirt_size']['Size'] = [
//         'XS' => 'X-Small',
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//         '4XL' => '4X-Large',
//         '5XL' => '5X-Large',
//     ];

//     $mappedOption['6141a0cf1c20da30c948ad7e']['apparel_size']['Size'] = [
//         '(US 4-6)S' => 'S',
//         '(US 8-10)M' => 'M',
//         '(US 12-14)L' => 'L',
//         '(US 16-18)XL' => 'X-Large',
//         '(US 18-20)2XL' => 'XX-Large',
//     ];

//     $mappedOption['6116a3f33715e60b0013662f']['apparel_size']['Jacket-Coat Length'] = [
//         'Short' => 'S',
//         'Regular' => 'M',
//         'Long' => 'L',
//     ];

//     $mappedOption['61434c8ac7908f17bc2bbf5b']['shirt_size']['Size'] = [
//         'Small' => 'S',
//         'Medium' => 'M',
//         'Large' => 'L',
//     ];

//     $mappedOption['6153137d91f1c10efe3b8825']['shirt_size']['Size'] = [
//         'small' => 'S',
//         'medium' => 'M',
//         'large' => 'L',
//     ];

//     $mappedOption['611cbdc45a71ee43e7008871']['footwear_size']['Size'] = [
//         '45' => '11',
//         '44' => '10',
//         '43' => '9',
//         '42' => '8',
//         '41' => '7',
//         '40' => '6',
//     ];
//     $mappedOption['6156554d67ab6201c97da4ad']['shirt_size']['Size'] = [
//         'XS' => 'X-Small',
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//         '4XL' => '4X-Large',
//         '5XL' => '5X-Large',
//     ];

//     $mappedOption['611cbdc45a71ee43e7008871']['bottoms_size']['Size'] = [
//         'Xs' => 'X-Small',
//         'Xl' => 'X-Large',
//         'Xxl' => 'XX-Large',
//         '3XL' => '3X-Large',
//         '4XL' => '4X-Large',
//         '5XL' => '5X-Large',
//     ];

//     $mappedOption['617720ab09002742092c98ed']['apparel_size']['Size'] = [
//         'P' => 'X-Small',
//         'S' => 'Small',
//         'M' => 'Medium',
//         'L' => 'Large',
//         'XL' => 'X-Large',
//         'XXL' => 'XX-Large',
//         'XXXL' => '3X-Large',
//         'Other' => 'One Size',
//     ];

//     $mappedOption['6169e5cab70bf4019f05ce71']['shirt_size']['Size'] = [
//         'XS' => 'X-Small',
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//         '4XL' => '4X-Large',
//         '5XL' => '5X-Large',
//         '6XL' => '6X-Large',
//     ];
//     $mappedOption['612a9208ae19473e291d6a3b']['apparel_size']['Size'] = [

//         'xl' => 'X-Large',
//         'xxl' => 'XX-Large',
//         'xxs' => 'XX-Small',
//         'xs' => 'X-Small',

//     ];
//     $mappedOption['616f302ad6a6d84c05077285']['apparel_size']['Size'] = [

//         'xl' => 'X-Large',
//         'xxl' => 'XX-Large',
//         'xxs' => 'XX-Small',
//         'xs' => 'X-Small',
//         'XS' => 'X-Small',
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//         '4XL' => '4X-Large',
//         '5XL' => '5X-Large',
//         '6XL' => '6X-Large',

//     ];
//     $mappedOption['618865393bee1d48e665f7b7']['apparel_size']['Size'] = [

//         'S / 38' => 'Small',
//         'M / 40' => 'Medium',
//         'L / 42' => 'Large',
//         'XL / 44' => 'X-Large',

//     ];
//     $mappedOption['616f302ad6a6d84c05077285']['apparel_size']['Size'] = [
//         'small' => 'Small',
//         'medium' => 'Medium',
//         'large' => 'Large',
//         'XL' => 'X-Large',
//         'XXL' => 'XX-Large',
//     ];

//     $mappedOption['612a9208ae19473e291d6a3b']['shirt_size']['Size'] = [
//         'xl' => 'X-Large',
//         'xxl' => 'XX-Large',
//         'xxxl' => '3X-Large',
//         '4xl' => '4X-Large',
//         '5xl' => '5X-Large',
//     ];

//     $mappedOption['61a80e6de0d69f5db261a158']['apparel_size']['Size'] = [

//         'Age 3-4' => '3',
//         'Age 4-5' => '4',
//         '2- 3 Years' => '2',
//         'Age 6-7' => '6',
//         'Age 7-8' => '7',
//         'Age 5-6' => '5',
//     ];

//     $mappedOption['61a80e6de0d69f5db261a158']['size_name']['Size'] = [

//         'Age 3-4' => '3',
//         'Age 4-5' => '4',
//         '2- 3 Years' => '2',
//         'Age 6-7' => '6',
//         'Age 7-8' => '7',
//         'Age 5-6' => '5',

//     ];

//     $mappedOption['61a80e6de0d69f5db261a158']['apparel_size_to']['Size'] = [
//         'Age 3-4' => '4',
//         'Age 4-5' => '5',
//         '2- 3 Years' => '3',
//         'Age 6-7' => '7',
//         'Age 7-8' => '8',
//         'Age 5-6' => '6',
//     ];

//     $mappedOption['619f4d1b1f99d0507a092146']['size_name']['Taglia'] = [

//         '36M' => '3 anni',
//         '4A' => '4 anni',
//         '5A' => '5 anni',
//         '6A' => '6 anni',

//     ];

//     $mappedOption['61c9288bfd4ab3717d55e134']['apparel_size']['Size'] = [
//         'L' => 'Large',
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//         '4XL' => '4X-Large',
//         '5XL' => '5X-Large',
//         '6XL' => '6X-Large',
//     ];

//     $mappedOption['61705a22a4ece7266e124fe8']['shirt_size']['Size'] = [
//         'L' => 'Large',
//         'XL' => 'X-Large',
//         'XXL' => 'XX-Large',
//     ];

//     $mappedOption['61d4af2626243b1835654668']['shirt_size']['Size'] = [
//         'S' => 'Small',
//         'M' => 'Medium',
//         'L' => 'Large',
//         'L' => 'Large',
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//     ];
//     $mappedOption['61e05dd7b285c63da56fc519']['shirt_size']['Size'] = [
//         'S' => 'Small',
//         'M' => 'Medium',
//         'L' => 'Large',
//         'Extra Large' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//         '4XL' => '4X-Large',
//     ];
//     $mappedOption['61e9deafdb8cce7814407159']['shirt_size']['Size'] = [
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//     ];

//     $mappedOption['61e6eb36a8e2b8020a1ed494']['shirt_size']['Size'] = [

//         'S' => 'Small',
//         'M' => 'Medium',
//         'L' => 'Large',
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',

//     ];

//     $mappedOption['61e05dd7b285c63da56fc519']['color']['Variant_title'] = [

//         'Black/Right' => 'Black',
//         'Black/Left' => 'Black',

//     ];

//     $mappedOption['61d4af2626243b1835654668']['apparel_size']['Size'] = [
//         'L' => 'Large',
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//         '4XL' => '4X-Large',
//         '5XL' => '5X-Large',
//         '6XL' => '6X-Large',
//     ];

//     $mappedOption['61898d347984797ef02a40d6']['shirt_size']['Size'] = [
//         'S - Shirt Included ADD $12' => 'Small',
//         'M - Shirt Included ADD $12' => 'Medium',
//         'L - Shirt Included ADD $12' => 'Large',
//         'XL - Shirt Included ADD $12' => 'X-Large',
//         '2XL - Shirt Included ADD $12' => 'XX-Large',
//         '3XL - Shirt Included ADD $12' => '3X-Large',
//         '4XL - Shirt Included ADD $14' => '4X-Large',
//         '5XL - Shirt Included ADD $15' => '5X-Large',
//     ];

//     $mappedOption['61ae8309461fcc7fdf663f62']['shirt_size']['Size'] = [
//         'S' => 'Small',
//         'M' => 'Medium',
//         'L' => 'Large',
//         'XL' => 'X-Large',
//         '2XL' => 'XX-Large',
//         '3XL' => '3X-Large',
//         '4XL' => '4X-Large',
//         '5XL' => '5X-Large',
//         '6XL' => '6X-Large',
//     ];

//     $mappedOption['61f10aba6081ee4cdf602797']['footwear_size']['Size'] = [
//         '5 US F' => '5',
//         '6 US F' => '6',
//         '7 US F' => '7',
//         '8 US F' => '8',
//         '9 US F' => '9',
//         '10 US F' => '10',
//         '11 US F' => '11',
//         '6 US M' => '6',
//         '7 US M' => '7',
//         '8 US M' => '8',
//         '9 US M' => '9',
//         '10 US M' => '10',
//         '11 US M' => '11',
//         '12 US M' => '12',
//         '13 US M' => '13',
//     ];
//     $mappedOption['63bee36521f6839a940f0882']['apparel_size']['Size'] = [
//         'XLARGE' => 'X-Large',
//        '2XLARGE' => 'XX-Large',
//        '3XLARGE' => '3X-Large',
//      ];

//      $mappedOption['613bca66a4d23a0dca3fbd9d']['shirt_size']['Size'] = [
//         "XL" => "X-Large",
//         "S" => "Small",
//         "M" => "Medium",
//         "L" => "Large",
//         "2XL" => "XX-Large",
//         "3XL" => "3X-Large",
//         "4XL" => "4X-Large",
//         "5XL" => "5X-Large",
//         "12M" => "12 Months",
//         "18M" => "18 Months",
//         "24M" => "24 Months",
//         "6M" => "6 Months",
//         "5/6" => "6"
//     ];

//     $mappedOption['64432eef896b47413c0a1b52']['color_name']['variant_title'] = [

//         'POSTER / 40x60 / Weiss' => 'POSTER / Weiss',
//         'POSTER / 40x60 / Schwarz' => 'POSTER / Schwarz',
//         'POSTER / 40x60 / Ohne Rahmen	' => 'POSTER / Ohne Rahmen',
//         'POSTER / 30x45 / Schwarz	' => '6POSTER / Schwarz',
//         'POSTER / 30x45 / Ohne Rahmen	' => 'POSTER / Ohne Rahmen',
//         'POSTER / 30x45 / Weiss	' => 'POSTER / Weiss',
//         'POSTER / 60x90 / Ohne Rahmen	'=> 'POSTER / Ohne Rahmen',
//         'POSTER / 100x150 / Schwarz	' => 'POSTER / Schwarz',
//         'POSTER / 60x90 / Weiss	' => 'POSTER / Weiss',
//         'POSTER / 70x105 / Ohne Rahmen	' => 'POSTER / Ohne Rahmen',
//         'LEINWAND / 60x90 / Schwarz	' => 'LEINWAND / Schwarz',
//         'POSTER / 80x120 / Ohne Rahmen	' => 'POSTER / Ohne Rahmen',
//         'LEINWAND / 30x45 / Ohne Rahmen	' => 'LEINWAND / Ohne Rahmen',
//         'POSTER / 70x105 / Schwarz	' => 'POSTER / Schwarz',
//         'POSTER / 60x90 / Schwarz	'  => 'POSTER / Schwarz',
//         'POSTER / 80x120 / Weiss	' => 'POSTER / Weiss',
//         'LEINWAND / 60x90 / Ohne Rahmen	' => 'LEINWAND / Ohne Rahmen',
//         'POSTER / 70x105 / Weiss	'=> 'POSTER / Weiss',
//         'LEINWAND / 40x60 / Schwarz	'=> 'LEINWAND / Schwarz',
//         'POSTER / 100x150 / Weiss	' => 'POSTER / Weiss',
//         'POSTER / 80x120 / Schwarz	'  => 'POSTER / Schwarz',
//         'LEINWAND / 40x60 / Ohne Rahmen	' => 'LEINWAND / Ohne Rahmen',
//         'POSTER / 100x150 / Ohne Rahmen	' => 'POSTER / Ohne Rahmen',
//         'LEINWAND / 30x45 / Schwarz	' => 'LEINWAND / Schwarz',
//         'LEINWAND / 30x45 / Weiss	' => 'LEINWAND / Weiss',
//         'LEINWAND / 40x60 / Weiss	' => 'LEINWAND / Weiss',
//         'LEINWAND / 60x90 / Weiss	'  => 'LEINWAND / Weiss',
//         'LEINWAND / 70x105 / Ohne Rahmen	'=> 'LEINWAND / Ohne Rahmen',
//         'LEINWAND / 70x105 / Weiss	' => 'LEINWAND / Weiss',
//         'LEINWAND / 70x105 / Schwarz	' => 'LEINWAND / Schwarz',
//         'LEINWAND / 100x150 / Ohne Rahmen	' => 'LEINWAND / Ohne Rahmen',
//         'LEINWAND / 80x120 / Ohne Rahmen	' => 'LEINWAND / Ohne Rahmen',
//         'LEINWAND / 80x120 / Schwarz	' => 'LEINWAND / Schwarz',
//         'ACRYLGLAS / 60x90 / Schwarz	' => 'ACRYLGLAS / Schwarz',
//         'LEINWAND / 100x150 / Schwarz	' => 'LEINWAND / Schwarz',
//         'ACRYLGLAS / 40x60 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
//         'LEINWAND / 80x120 / Weiss	'=> 'LEINWAND / Weiss',
//         'LEINWAND / 100x150 / Weiss	' => 'LEINWAND / Weiss',
//         'ACRYLGLAS / 40x60 / Weiss	' => 'ACRYLGLAS / Weiss',
//         'ACRYLGLAS / 30x45 / Schwarz	' => 'ACRYLGLAS / Schwarz',
//         'ACRYLGLAS / 30x45 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
//         'ACRYLGLAS / 60x90 / Weiss	' => 'ACRYLGLAS / Weiss',
//         'ACRYLGLAS / 30x45 / Weiss	' => 'ACRYLGLAS / Weiss',
//         'ACRYLGLAS / 70x105 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
//         'ACRYLGLAS / 60x90 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
//         'ACRYLGLAS / 40x60 / Schwarz	' => 'ACRYLGLAS / Schwarz',
//         'ACRYLGLAS / 70x105 / Schwarz	' => 'ACRYLGLAS / Schwarz',
//         'ACRYLGLAS / 70x105 / Weiss	' => 'ACRYLGLAS / Weiss',
//         'ACRYLGLAS / 80x120 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
//         'ACRYLGLAS / 80x120 / Weiss	' => 'ACRYLGLAS / Weiss',
//         'ACRYLGLAS / 80x120 / Schwarz	' => 'ACRYLGLAS / Schwarz',
//         'ACRYLGLAS / 100x150 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
//         'ACRYLGLAS / 100x150 / Schwarz	' => 'ACRYLGLAS / Schwarz',
//         'ACRYLGLAS / 100x150 / Weiss	' =>'ACRYLGLAS / Weiss'

//     ];

//     $mappedOption['62b0ad72338bfa2779686544']['item_display_length']['Length'] = [
//         '16 inch' => '16',
//         '18 inch' => '18',
//         '20 inch' => '20',
//     ];


//     $mappedOption['640d7167ce4c562aac0e41ab']['shirt_size']['Size'] = [
//         'One Size I Length: 19" Chest: 34"' => '34',
//         'One Size I Length: 22" Chest: 34"' => '34'
//     ];

//     $mappedOption['640d7167ce4c562aac0e41ab']['skirt_size']['Size'] = [
//         'One Size I Length: 15" Waist: 26-34" Hip: 34-42"' => '34'
//     ];

//     $mappedOption['618aa726e607ac4d487e86cc']['footwear_size']['Size'] = [
//         '36 EU' => '4',
//         '37 EU' => '4.5',
//         '38 EU' => '5.5',
//         '39 EU' => '6',
//         '40 EU' => '7',
//     ];

//     $mappedOption['64569d37913d9ea23502d976']['shapewear_cup_size']['Cup Size'] = [
//         'D/DD' => 'D',
//         'G/GG' => 'G',
//         'F/FF' => 'F',
//         'H/HH' => 'H',
//         'J/JJ' => 'J',
//         'L/LL' => 'L',
//         'K/KK' => 'K',
//     ];  


//     $mappedOption['64569d37913d9ea23502d976']['cup_size']['Cup Size'] = [
//         'D/DD' => 'D',
//         'G/GG' => 'G',
//         'F/FF' => 'F',
//         'H/HH' => 'H',
//         'J/JJ' => 'J',
//         'L/LL' => 'L',
//         'K/KK' => 'K',
//     ]; 

//     $mappedOption['62b0ad72338bfa2779686544']['item_display_length']['Length'] = [
//         '22 inch' => '22',
//         '24 inch' => '24',
//         '30 inch' => '30',
//         '16 inch' => '16',
//         '18 inch' => '18',
//         '20 inch' => '20',
//     ];

//     $mappedOption['6466559be3eafaeb7b0a44b4']['shirt_size']['Size'] = [
//         '6-9M' => '9',
//         '9-12M' => '12',
//         '12-18M' => '18',
//         '2-3Y' => '3',
//         '4 Years' => '4',
//         '18-24M' => '24'
//     ];

//         // $mappedOption['61263349f75b161bc92813e0']['apparel_size']['Size'][$value] = str_replace('US', '', $value);

//         if (isset($mappedOption[$this->_user_id][$amazonAttribute][$sourceAttribute][$value])) {
//             $mappedValue = $mappedOption[$this->_user_id][$amazonAttribute][$sourceAttribute][$value];
//         }

//         return $mappedValue;
//     }

//     /**
//      * process amazon-look sqs data
//      *
//      * @param array $data
//      * @return bool|array
//      */
//     public function search($data)
//     {
//         try {
//             $response = [];
//             $this->init($data);
//             $objectManager = $this->di->getObjectManager();
//             $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
//             $sourceShopId = $data['data']['source_shop_id'];
//             $targetShopId = $data['data']['target_shop_id'];
//             $sourceMarketplace = $data['data']['source_marketplace'];
//             $targetMarketplace = $data['data']['target_marketplace'];
//             $userId = $data['user_id'] ?? $this->di->getUser()->id;
//             $logFile = 'amazon/' . $userId . '/' . $sourceShopId . '/' . $targetShopId . '/lookup//' . date('Y-m-d') . '.log';
//             if (isset($data['data']['source_product_ids']) && !empty($data['data']['source_product_ids'])) {
//                 $sourceProductIds = $data['data']['source_product_ids'];
//                 $products = [];
//                 $query = [
//                     'user_id' => $userId,
//                     'shop_id' => $sourceShopId,
//                     'source_product_id' => ['$in' => $sourceProductIds],
//                     'type' => 'simple'
//                 ];
//                 $options = [
//                     'typeMap' => ['root' => 'array', 'document' => 'array'],
//                     'projection' => ['source_product_id' => 1, 'container_id' => 1, 'barcode' => 1, 'marketplace' => 1],
//                 ];
//                 $products = $helper->getproductbyQuery($query, $options);
//                 $parentProductIds = array_diff($sourceProductIds, array_column($products, 'source_product_id'));
//                 if (!empty($parentProductIds)) {
//                     $query = [
//                         'user_id' => $userId,
//                         'shop_id' => $sourceShopId,
//                         'container_id' => ['$in' => array_values($parentProductIds)],
//                         'type' => 'simple'
//                     ];
//                     $newProducts = $helper->getproductbyQuery($query, $options);
//                     if (!empty($newProducts)) {
//                         $products = array_merge($products, $newProducts);
//                     }
//                 }

//                 if (!empty($products)) {
//                     $response = $this->searchProductOnAmazon($targetShopId, $products);
//                     // $this->di->getLog()->logContent(print_r($response, true), 'info', $logFile);
//                 }

//                 (int)$counter = $data['data']['counter'] ?? 0;
//                 if (isset($response['failed_source_product']) && $response['failed_source_product']['count'] > 0 && $counter <= 2) {
//                     //prepare sqs data if not
//                     if (!isset($data['type'], $data['class_name'])) {
//                         $appCode = $this->di->getAppCode()->get();
//                         $appTag = $this->di->getAppCode()->getAppTag();
//                         $data = [
//                             'type' => 'full_class',
//                             'class_name' => Product::class,
//                             'method' => 'search',
//                             'appCode' => $appCode,
//                             'appTag' => $appTag,
//                             'queue_name' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getSearchOnAmazonQueueName(),
//                             'user_id' => $userId,
//                             'data' => [
//                                 'source_shop_id' => $sourceShopId,
//                                 'target_shop_id' => $targetShopId,
//                                 'source_marketplace' => $sourceMarketplace,
//                                 'target_marketplace' => $targetMarketplace,
//                             ]
//                         ];
//                     }

//                     $data['data']['source_product_ids'] = $res['failed_source_product']['message'] ?? [];
//                     $data['data']['counter'] = $counter + 1;
//                     $this->di->getMessageManager()->pushMessage($data);
//                 }

//                 return true;
//             }
//             if (!isset($data['data']['limit'])) {
//                 $data['data']['limit'] = 500;
//             }
//             if (!isset($data['data']['page'])) {
//                 $data['data']['page'] = 0;
//             }
//             $limit = $data['data']['limit'];
//             $skip = $data['data']['page'] * $limit;
//             $isLastPage = false;
//             $products = [];
//             $productsData = [];
//             if (!isset($data['data']['isLastPage']) || !$data['data']['isLastPage']) {
//                 $query = [
//                     'user_id' => (string) $userId,
//                     'type' => ProductContainer::PRODUCT_TYPE_SIMPLE,
//                     'shop_id' => $data['data']['source_shop_id'],
//                     '$or' => [
//                         ["marketplace.target_marketplace" => ['$ne' => $targetMarketplace]],
//                         [
//                             "marketplace.status" => ['$nin' => [
//                                 \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_ACTIVE,
//                                 \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INACTIVE,
//                                 \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INCOMPLETE,
//                                 \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UPLOADED,
//                                 \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_PARENT
//                             ]],
//                             "marketplace.target_marketplace" => $targetMarketplace
//                         ],
//                         ["marketplace.target_marketplace" => $targetMarketplace, "marketplace.shop_id" => ['$ne' => (string) $targetShopId]],
//                     ],
//                 ];
//                 $options = [
//                     'typeMap' => ['root' => 'array', 'document' => 'array'],
//                     'projection' => ['_id' => 1, 'user_id' => 1, 'source_product_id' => 1, 'shop_id' => 1, 'container_id' => 1, 'barcode' => 1, 'marketplace' => 1],
//                     'skip' => $skip,
//                     'limit' => $limit,
//                 ];
//                 $products = $helper->getproductbyQuery($query, $options);
//             }
//             $productCount = count($productsData);
//             if (isset($data['failed_source_product']) && !empty($data['failed_source_product'])) {
//                 $query = [
//                     'user_id' => (string) $userId,
//                     'shop_id' => $sourceShopId,
//                     'type' => 'simple',
//                     'source_product_id' => ['$in' => $data['failed_source_product']]
//                 ];
//                 $options = [
//                     'typeMap' => ['root' => 'array', 'document' => 'array'],
//                     'projection' => ['_id' => 1, 'user_id' => 1, 'source_product_id' => 1, 'shop_id' => 1, 'container_id' => 1, 'barcode' => 1, 'marketplace' => 1]
//                 ];
//                 $failed_products = $helper->getproductbyQuery($query, $options);
//                 $products = array_merge($products, $failed_products);
//             }
//             $sqs_data = $data;
//             if ($productCount < $limit) {
//                 $isLastPage = true;
//                 $sqs_data['data']['isLastPage'] = true;
//             } else {
//                 $sqs_data['data']['page'] = $sqs_data['data']['page'] + 1;
//             }
//             $res = [];
//             if (!empty($products)) {
//                 $res = $this->searchProductOnAmazon($targetShopId, $products);
//                 // $this->di->getLog()->logContent(print_r($res, true), 'info', $logFile);
//                 if (isset($res['remote_servre_eror']) && !empty($res['remote_servre_eror'])) {
//                     $message = 'Amazon look-up could not be completed. Please contact support';
//                     $notificationData = [
//                         'message' => $message,
//                         'severity' => 'false',
//                         "user_id" => $userId,
//                         "marketplace" => $targetMarketplace,
//                         "appTag" => $this->di->getAppCode()->getAppTag()
//                     ];
//                     $objectManager->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($data['queued_task_id'], 100, $message);
//                     $objectManager->get('\App\Connector\Models\Notifications')->addNotification($targetShopId, $notificationData);
//                     return true;
//                 }
//             }
//             $counter = $sqs_data['data']['counter'] ?? 0;
//             if ($isLastPage) {
//                 if (isset($res['failed_source_product']) && $res['failed_source_product']['count'] > 0 && $counter <= 2) {
//                     $sqs_data['failed_source_product'] = $res['failed_source_product']['message'] ?? [];
//                     $sqs_data['data']['counter'] = $counter + 1;
//                     $this->di->getMessageManager()->pushMessage($sqs_data);
//                     return true;
//                 }
//                 $message = 'Amazon Lookup completed successfully';
//                 $notificationData = [
//                     'message' => $message,
//                     'severity' => 'success',
//                     "user_id" => $userId,
//                     "marketplace" => $targetMarketplace,
//                     "appTag" => $this->di->getAppCode()->getAppTag()
//                 ];
//                 $objectManager->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($data['queued_task_id'], 100, $message);
//                 $objectManager->get('\App\Connector\Models\Notifications')->addNotification($targetShopId, $notificationData);
//                 return true;
//             }
//             $processed = round(($limit * $sqs_data['data']['page']) / $sqs_data['individual_weight'] * 100, 2);
//             $objectManager->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($data['queued_task_id'], $processed, '', false);
//             $sqs_data['failed_source_product'] = $res['failed_source_product']['message'] ?? [];
//             $this->di->getMessageManager()->pushMessage($sqs_data);
//             return true;

//             return true;
//         } catch (Exception $e) {
//             $this->di->getLog()->logContent('look-up exception search(): ' . print_r($e->getMessage(), true), 'info', 'exception.log');
//             return false;
//         }
//     }

//     /**
//      * send product-get request to amazon
//      *
//      * @param string $targetShopId
//      * @param array $products
//      * @return bool|array
//      */
//     public function searchProductOnAmazon($targetShopId, $products)
//     {
//         try {
//             if (!empty($products)) {
//                 $returnArray = [];
//                 $successArray = [];
//                 $errorArray = [];
//                 $failed_source_product = [];
//                 $containerIds = [];
//                 $barcodeValidatedProducts = [];
//                 $sourceProductIds = [];
//                 $objectManager = $this->di->getObjectManager();
//                 $userId = $this->di->getUser()->id;
//                 $amazonShop = $objectManager->get('\App\Core\Models\User\Details')->getShop($targetShopId, $userId);
//                 // $sellerId = $amazonShop['warehouses'][0]['seller_id'];
//                 $sourceShopId = $this->source_shop_id;
//                 $barcode = $objectManager->create(Barcode::class);
//                 $marketplacehelper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
//                 $offerStatus = \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER;
//                 $statusArray = [
//                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_ACTIVE,
//                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INACTIVE,
//                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INCOMPLETE,
//                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UPLOADED,
//                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_PARENT
//                 ];
//                 foreach ($products as $product) {
//                     $sourceProductId = $product['source_product_id'];
//                     $marketplace = $this->getMarketplace($product, $targetShopId, $sourceProductId);
//                     if (isset($marketplace['status']) && in_array($marketplace['status'], $statusArray)) {
//                         $returnArray['not_processed'][$sourceProductId] = 'Product already listed.';
//                     } else {
//                         if (isset($marketplace['status']) && $marketplace['status'] == $offerStatus) {
//                             $containerIds[$sourceProductId] = ['container_id' => $product['container_id'], 'asin' => $marketplace['asin'] ?? ''];
//                             array_push($sourceProductIds, $sourceProductId);
//                         }

//                         $validBarcode = false;
//                         if (isset($marketplace['barcode']) && !empty($marketplace['barcode'])) {
//                             $validBarcode = trim((string) $marketplace['barcode']);
//                         } elseif (isset($product['barcode']) && !empty($product['barcode'])) {
//                             $validBarcode = trim((string) $product['barcode']);
//                         }

//                         if ($validBarcode && $barcode->setBarcode($validBarcode)) {
//                             if (preg_match('/(\x{200e}|\x{200f})/u', $validBarcode)) {
//                                 $validBarcode = preg_replace('/(\x{200e}|\x{200f})/u', '', $validBarcode);
//                             }

//                             $barcodeValidatedProducts[$validBarcode][] = ['source_product_id' => $sourceProductId, 'container_id' => $product['container_id']];
//                         } else {
//                             $returnArray['not_processed'][$sourceProductId] = 'Barcode validation failed';
//                         }
//                     }
//                 }

//                 if (!empty($barcodeValidatedProducts)) {
//                     $productChunks = array_chunk($barcodeValidatedProducts, 100, true);
//                     $commonHelper = $objectManager->get(Helper::class);
//                     $bulkupdateData = [];
//                     $bulkupdateasinData = [];
//                     foreach ($productChunks as $barcodeArr) {
//                         $specifics = [
//                             'id' => array_keys($barcodeArr),
//                             'shop_id' => $amazonShop['remote_shop_id'],
//                             'home_shop_id' => $targetShopId
//                         ];
//                         $response = $commonHelper->sendRequestToAmazon('product', $specifics, 'GET');
//                         if (isset($response['success']) && $response['success'] && isset($response['response']) && is_array($response['response'])) {
//                             foreach ($response['response'] as $barcode => $data) {
//                                 $productsData = $barcodeArr[$barcode] ?? false;
//                                 if ($productsData && isset($data['asin'])) {
//                                     $asin = $data['asin'];
//                                     foreach ($productsData as $productData) {
//                                         //check if offer status already applied
//                                         if (
//                                             isset($containerIds[$productData['source_product_id']]) &&
//                                             $containerIds[$productData['source_product_id']]['asin'] == $asin
//                                         ) {
//                                             $successArray[] = $productData['source_product_id'];
//                                             continue;
//                                         }

//                                         // update status on product container
//                                         $updateData = [
//                                             "source_product_id" => $productData['source_product_id'], // required
//                                             'user_id' => $userId,
//                                             'source_shop_id' => $sourceShopId, // required
//                                             'container_id' => $productData['container_id'], // required
//                                             'childInfo' => [
//                                                 'source_product_id' => $productData['source_product_id'], // required
//                                                 'shop_id' => (string) $targetShopId, // required
//                                                 'asin' => $asin,
//                                                 'status' => $offerStatus,
//                                                 'target_marketplace' => 'amazon', // required
//                                             ],
//                                         ];
//                                         array_push($bulkupdateData, $updateData);
//                                         // update asin on product container
//                                         $asinData = [
//                                             'target_marketplace' => 'amazon',
//                                             'shop_id' => (string) $targetShopId,
//                                             'container_id' => $productData['container_id'],
//                                             'source_product_id' => $productData['source_product_id'],
//                                             'asin' => $asin,
//                                             'status' => $offerStatus,
//                                             'source_shop_id' => $sourceShopId
//                                         ];
//                                         array_push($bulkupdateasinData, $asinData);
//                                         $successArray[] = $productData['source_product_id'];
//                                     }
//                                 }
//                             }
//                         } else {
//                             if (isset($response['response']) && !empty($response['response'])) {
//                                 $returnArray['remote_servre_eror'] = $response['response'];
//                             } else {
//                                 $returnArray['remote_servre_eror'] = $response;
//                             }

//                             return $returnArray;
//                         }

//                         if (isset($response['failed_asin']) && is_iterable($response['failed_asin'])) {
//                             foreach ($response['failed_asin'] as $failedBarcode) {
//                                 if (isset($barcodeArr[$failedBarcode])) {
//                                     $failed_source_product[] = $product['source_product_id'];
//                                 }
//                             }
//                         }
//                     }

//                     if (!empty($bulkupdateData)) {
//                         $marketplacehelper->marketplaceSaveAndUpdate($bulkupdateData);
//                     }

//                     if (!empty($bulkupdateasinData)) {
//                         $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
//                         $helper->saveProduct($bulkupdateasinData);
//                     }

//                     $notProcessedIds = array_diff($sourceProductIds, $successArray);
//                     foreach ($notProcessedIds as $id) {
//                         $errorArray[$id] = 'Not found on amazon';
//                     }
//                 }

//                 $bulkupdateData = [];
//                 $bulkupdateasinData = [];
//                 if (!empty($containerIds) && !empty($errorArray)) {
//                     foreach ($errorArray as $sourceProductId => $error) {
//                         if (isset($containerIds[$sourceProductId])) {
//                             $asinData = [
//                                 'target_marketplace' => 'amazon',
//                                 'shop_id' => (string) $targetShopId,
//                                 'container_id' => $containerIds[$sourceProductId]['container_id'],
//                                 'source_product_id' => (string)$sourceProductId,
//                                 'source_shop_id' => $sourceShopId,
//                                 'unset' => [
//                                     'asin' => 1,
//                                     'status' => 1
//                                 ]
//                             ];
//                             array_push($bulkupdateasinData, $asinData);
//                             $errorArray[$sourceProductId] = 'Offer status removed';
//                         }
//                     }

//                     if (!empty($bulkupdateasinData)) {
//                         $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
//                         $res = $helper->saveProduct($bulkupdateasinData);
//                     }

//                     $returnArray['error'] = $errorArray;
//                 }

//                 if (!empty($successArray)) {
//                     $returnArray['offerstatus'] = $successArray;
//                 }

//                 if (!empty($failed_source_product)) {
//                     $returnArray['failed_source_product'] = ['count' => count($failed_source_product), 'message' => $failed_source_product];
//                 }

//                 return $returnArray;
//             }
//             return ['success' => true, 'message' => 'No Products Found'];
//         } catch (Exception $e) {
//             $this->di->getLog()->logContent('look-up exception search(): ' . print_r($e->getMessage(), true), 'info', 'exception.log');
//             return false;
//         }
//     }

//     /**
//      * @param $sqsData
//      */
//     public function deleteAmazonListing($sqsData): void
//     {
//         $user_id = $sqsData['user_id'];

//         $data = $sqsData['data'];
//         if (!isset($data['limit'])) {
//             $limit = 100;
//         } else {
//             $limit = $data['limit'];
//         }

//         $isLastPage = false;
//         if ($data['page'] == $data['total_pages']) {
//             $isLastPage = true;
//         }

//         $skip = 0;
//         $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//         $amazonListingCollection = $mongo->getCollection(Helper::AMAZON_LISTING);

//         $query = ['user_id' => $user_id, 'updated_at' => ['$lt' => date('Y-m-d')]];

//         $options = [
//             'typeMap' => ['root' => 'array', 'document' => 'array'],
//             'projection' => ['_id' => 1, 'user_id' => 1, 'shop_id' => 1, 'source_product_id' => 1, 'source_container_id' => 1, 'barcode' => 1, 'marketplace' => 1],
//             'skip' => $skip,
//             'limit' => $limit,
//         ];

//         $listings = $amazonListingCollection->find($query, $options)->toArray();

//         if (!empty($listings)) {
//             foreach ($listings as $listing) {
//                 $updateData = [
//                     "source_product_id" => $listing['source_product_id'], // required
//                     'user_id' => $listing['user_id'],
//                     'childInfo' => [
//                         'source_product_id' => $listing['source_product_id'], // required
//                         'shop_id' => $listing['shop_id'], // required
//                         'status' => \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED,
//                         'target_marketplace' => 'amazon', // required
//                     ],
//                 ];
//                 $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
//                 $response = $helper->marketplaceSaveAndUpdate($updateData);
//                 if (isset($response['success']) && $response['success']) {
//                     $amazonListingCollection->deleteOne(
//                         ['user_id' => $user_id, 'source_product_id' => $listing['source_product_id']]
//                     );
//                 }
//             }
//         }

//         if (!$isLastPage) {
//             $sqsData['data']['page'] = $data['page'] + 1;
//             $this->di->getMessageManager()->pushMessage($sqsData);
//         } else {
//             $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//             $queuedTasks = $mongo->getCollectionForTable(Helper::QUEUED_TASKS);
//             $queuedTasks->deleteOne(['_id' => new ObjectId($data['queued_task_id'])]);
//             $message = 'Deleted extra products from Amazon listing';
//             $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($this->_user_id, $message, 'success');
//         }
//     }

//     /**
//      * It is called from edit page.
//      * @param $data
//      * @return array
//      */
//     public function searchOnAmazon($data)
//     {

//         $amazonShopsData = [];
//         $source_marketplace = '';
//         $target_marketplace = '';
//         $accounts = [];

//         if (isset($data['source_marketplace'])) {
//             $source_marketplace = $data['source_marketplace']['marketplace'];
//         }

//         if (isset($data['target_marketplace'])) {
//             $target_marketplace = $data['target_marketplace']['marketplace'];
//             $accounts = $data['target_marketplace']['shop_id'];
//         }

//         if ($target_marketplace == '' || $source_marketplace == '' || empty($accounts)) {
//             return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
//         }

//         $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//         //        $connectedAccounts = $commonHelper->getAllAmazonShops($this->di->getUser()->id);
//         $connectedAccounts = $commonHelper->getAllCurrentAmazonShops();
//         foreach ($connectedAccounts as $account) {
//             if (!in_array($account['_id'], $accounts)) {
//                 continue;
//             }

//             if ($account['warehouses'][0]['status'] == 'active') {
//                 $amazonShopsData[] = $account;
//             }
//         }

//         if (!empty($amazonShopsData)) {

//             $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//             $productContainerCollection = $mongo->getCollection(Helper::PRODUCT_CONTAINER);
//             $successArray = [];
//             $errorArray = [];
//             $alreadyUploaded = [];
//             $returnArray = [];
//             $sourceProductIds = [];
//             if (isset($data['container_ids']) && !empty($data['container_ids'])) {
//                 if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
//                     $query = [
//                         'user_id' => (string) $this->di->getUser()->id,
//                         'type' => ProductContainer::PRODUCT_TYPE_SIMPLE,
//                         'container_id' => [
//                             '$in' => $data['container_ids'],
//                         ],
//                         'source_product_id' => [
//                             '$in' => $data['source_product_ids'],
//                         ],
//                         'source_marketplace' => $source_marketplace,
//                     ];
//                 } else {
//                     $query = [
//                         'user_id' => (string) $this->di->getUser()->id,
//                         'type' => ProductContainer::PRODUCT_TYPE_SIMPLE,
//                         'container_id' => [
//                             '$in' => $data['container_ids'],
//                         ],
//                         'source_marketplace' => $source_marketplace,

//                     ];
//                 }

//                 $sourceProductIdsArray = $productContainerCollection->find(
//                     $query,
//                     [
//                         'typeMap' => ['root' => 'array', 'document' => 'array'],
//                         'projection' => ['source_product_id' => 1],
//                     ]
//                 )->toArray();
//                 if (!empty($sourceProductIdsArray)) {
//                     $sourceProductIds = array_column($sourceProductIdsArray, 'source_product_id');
//                 }
//             }

//             if ($sourceProductIds) {

//                 foreach ($amazonShopsData as $shop) {
//                     $products = [];
//                     foreach ($sourceProductIds as $sourceProductId) {
//                         $product = [
//                             "source_product_id" => $sourceProductId,
//                             'user_id' => (string) $this->di->getUser()->id,
//                             'shop_id' => $shop['_id'],
//                         ];

//                         $objectManager = $this->di->getObjectManager();
//                         $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
//                         $productData = $helper->getSingleProduct($product);

//                         if (isset($productData['marketplace']) && !empty($productData['marketplace'])) {
//                             $isStatusExist = false;
//                             $existOnAmazon = false;
//                             foreach ($productData['marketplace'] as $marketplace) {
//                                 if (isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == 'amazon') {
//                                     $existOnAmazon = true;
//                                     $statusArray = [
//                                         \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED,
//                                         \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_ERROR,
//                                         \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER,
//                                     ];
//                                     if (isset($marketplace['status']) && !in_array($marketplace['status'], $statusArray) && $marketplace['shop_id'] == $shop['_id']) {
//                                         $isStatusExist = true;
//                                     }
//                                 }
//                             }

//                             if (!$existOnAmazon || !$isStatusExist) {
//                                 $products[$productData['source_product_id']] = $productData;
//                             } else {
//                                 $alreadyUploaded[] = $productData['source_product_id'];
//                             }
//                         } else {
//                             $products[$productData['source_product_id']] = $productData;
//                         }
//                     }

//                     if (!empty($products)) {
//                         $response = $this->searchProductOnAmazon($shop, $products);

//                         if (isset($response['success'])) {
//                             $successArray = array_merge($successArray, $response['success']['message']);
//                         }

//                         if (isset($response['error'])) {
//                             $errorArray = array_merge($errorArray, $response['error']['message']);
//                         }
//                     }
//                 }

//                 if (!empty($alreadyUploaded)) {
//                     $returnArray['already_uploaded'] = ['count' => count($alreadyUploaded), 'data' => $alreadyUploaded];
//                 }

//                 if (!empty($successArray)) {
//                     $returnArray['success'] = ['count' => count($successArray), 'data' => $successArray];
//                 }

//                 if (!empty($errorArray)) {
//                     $returnArray['error'] = ['count' => count($errorArray), 'data' => $errorArray];
//                 }

//                 return $returnArray;
//             }
//             return ['success' => false, 'message' => 'No Source Product Id found'];
//         }
//         return ['success' => false, 'message' => 'Please enable your Amazon account from Settings section to initiate the processes.'];
//     }

//     public function getMarketplace($productData, $TargetshopId, $sourceProductId)
//     {
//         if (isset($productData['marketplace'])) {
//             foreach ($productData['marketplace'] as $marketplace) {
//                 if ($marketplace['source_product_id'] == $sourceProductId && $marketplace['shop_id'] == $TargetshopId && isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == 'amazon') {
//                     return $marketplace;
//                 }
//             }
//         }
//     }

//     // 
//     public function getCustomValueMapping($data)
//     {
//         $filePath = BP . DS . 'app' . DS . 'code' . DS . 'amazon' . DS . 'utility' . DS . 'mapping.json';
//         $mappedJson = file_get_contents($filePath);
//         $mappedOption = json_decode($mappedJson, true);
//         if (isset($data['user_id']) && isset($data['target_id'])) {
//             $mappedOption = $mappedOption[$data['user_id']][$data['target_id']];
//         }

//         return ['success' => true, 'data' => $mappedOption];
//     }

//     public function deleteCustomValueMapping($data)
//     {
//         $filePath = BP . DS . 'app' . DS . 'code' . DS . 'amazon' . DS . 'utility' . DS . 'mapping.json';
//         $mappedJson = file_get_contents($filePath);
//         $mappedOption = json_decode($mappedJson, true);

//         if (isset($data['user_id']) && isset($data['target_id'])) {
//             if (count($mappedOption[$data['user_id']]) > 1) {
//                 unset($mappedOption[$data['user_id']][$data['target_id']]);
//             } else {
//                 unset($mappedOption[$data['user_id']]);
//             }

//             // print_r($mappedOption[$data['user_id']][$data['target_id']]);
//             // die;
//             $updatedData = json_encode($mappedOption);
//             file_put_contents($filePath, $updatedData);
//             // die;
//         } else {
//             return ['success' => false, 'message' => "user_id required"];
//         }

//         return ['success' => true, 'data' => "deleted successfully"];
//     }

//     public function saveCustomValueMapping($data)
//     {
//         $filePath = BP . DS . 'app' . DS . 'code' . DS . 'amazon' . DS . 'utility' . DS . 'mapping.json';
//         $mappedJson = file_get_contents($filePath);
//         $mappedOption = json_decode($mappedJson, true);

//         if (isset($data['user_id']) && isset($data['target_id']) && isset($data['data'])) {
//             if (!$this->idValid($data['user_id'])) {
//                 return ['success' => false, 'message' => "invalid user id"];
//             }

//             if (count($data['data']) > 0) {
//                 $mappedOption[$data['user_id']][$data['target_id']] = $data['data'];
//                 $updatedData = json_encode($mappedOption);
//                 file_put_contents($filePath, $updatedData);
//             } else {
//                 return ['success' => false, 'message' => "data cannot be empty or null"];
//             }
//         } else {
//             return ['success' => false, 'message' => "user_id, target_id and data are required fields"];
//         }

//         return ['success' => true, 'data' => "data saved successfully"];
//     }

//     public function checkIfUserAndTargetExists($data)
//     {
//         if (isset($data['user_id']) && isset($data['target_id'])) {
//             $filePath = BP . DS . 'app' . DS . 'code' . DS . 'amazon' . DS . 'utility' . DS . 'mapping.json';
//             $mappedJson = file_get_contents($filePath);
//             $mappedOption = json_decode($mappedJson, true);
//             if (isset($mappedOption[$data['user_id']]) && isset($mappedOption[$data['user_id']][$data['target_id']])) {
//                 return ['success' => false, 'message' => 'already Exists'];
//             }
//         }

//         return ['success' => true, 'message' => 'not exists'];
//     }

//     public function idValid($id)
//     {
//         return $id instanceof \MongoDB\BSON\ObjectID
//             || preg_match('/^[a-f\d]{24}$/i', (string) $id);
//     }

//     public function removeTagInactiveAccount($rawBody)
//     {
//         try {
//             $userId = $rawBody['user_id'] ?? $this->di->getUser()->id;
//             $getUser = User::findFirst([['_id' => $userId]]);
//             $getUser->id = (string) $getUser->_id;
//             $this->di->setUser($getUser);
//             $userDetails = $getUser->toArray();
//             $userShopsDetails = $userDetails['shops'];

//             foreach($userShopsDetails as $shops){

//                 if($shops['remote_shop_id'] == $rawBody['data']['remote_shop_id']){

//                 if(isset($shops['sources'])){
//                     foreach($shops['sources'] as $source){
//                         $source_shop_Id = $source['shop_id'];
//                         $source_marketplace = $source['code'];
//                         $appCode = $source['app_code'];
//                         $targetshopId =  $shops['_id'];

//                         if (isset($shops['marketplace']) && $shops['marketplace'] == Helper::TARGET) {
//                            $targetMarketplace = $shops['marketplace'];
//                         }

//                         $this->di->getAppCode()->setAppTag($appCode);

//                         if (isset($shops['marketplace']) && $shops['marketplace'] == 'amazon') {
//                             $targetMarketplace = $shops['marketplace'];
//                         }

//                         $Query = ['user_id' => $userId, 'marketplace.shop_id' => $targetshopId, 'shop_id' => $source_shop_Id, "marketplace"=> [
//                             '$elemMatch'=> [
//                                  "process_tags"=> [ '$exists'=> true ] 
//                             ]
//                         ]];
//                         $productQuery[] = ['$match'=>$Query];
//                         $productQuery[] = ['$project'=>['source_product_id'=>1,'_id'=>0]];
//                         $productQuery[] = ['$limit' => 1000];

//                         $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
//                         $productContainer= $mongo->getCollectionForTable('product_container');
//                         $productData = $productContainer->aggregate($productQuery)->toArray();
//                         $products = json_decode(json_encode($productData), true);
//                         $sourceProductIds = array_column($products, 'source_product_id');

//                          $data['source']['shopId'] = $source_shop_Id;
//                          $data['target']['shopId'] = $targetshopId;
//                          $data['source']['marketplace'] = $source_marketplace;
//                          $data['target']['marketplace'] = $targetMarketplace;
//                          $data['data']['source_product_id'] = $sourceProductIds;
//                          $data['data']['isParent'] = true;
//                          $data['user_id'] = $userId;

//                          $dataToConnector['user_id'] = $userId;
//                          $dataToConnector['source'] = $source_marketplace;
//                          $dataToConnector['target'] = $targetMarketplace;
//                          $dataToConnector['source_shop_id'] = $source_shop_Id;
//                          $dataToConnector['target_shop_id'] = $targetshopId;
//                          $dataToConnector['group_code']='feedStatus';
//                          $dataToConnector['key'] = 'accountStatus';

//                          if($rawBody['data']['error_code'] == 'InvalidInput'){
//                             if($rawBody['feed'] == 'active'){
//                                 $dataToConnector['error_code'] = $rawBody['data']['error_code'];
//                                 $dataToConnector['value'] = 'active';
//                             }
//                             else {
//                                 $dataToConnector['error_code'] = $rawBody['data']['error_code'];
//                                 $dataToConnector['value'] = 'inactive';
//                                 $objectManager = $this->di->getObjectManager();
//                                 $helper = $objectManager->get(Action::class);
//                                 $result = $helper->removeProcessTag($data);
//                             }
//                        }
//                        else{
//                            if($rawBody['feed'] == 'active'){
//                                $dataToConnector['error_code'] = $rawBody['data']['error_code'];
//                                $dataToConnector['value'] = 'active';
//                            }
//                            else {
//                                $dataToConnector['error_code'] = $rawBody['data']['error_code'];
//                                $dataToConnector['value'] = 'expired';
//                                $objectManager = $this->di->getObjectManager();
//                                $helper = $objectManager->get(Action::class);
//                                $result = $helper->removeProcessTag($data);
//                            }
//                        }

//                     if ($this->di->getAppCode()->getAppTag()) {
//                         $dataToConnector['app_tag'] = $this->di->getAppCode()->getAppTag();
//                     } else {
//                         $dataToConnector['app_tag'] = 'default';
//                     }

//                     $objectManager = $this->di->getObjectManager();
//                     $configObj = $objectManager->get('\App\Core\Models\Config\Config');
//                     $configObj->setUserId($userId);
//                     $configObj->setAppTag($dataToConnector['app_tag']);
//                     $configObj->setConfig([$dataToConnector]);
//                     return ['success' => true, 'message' => 'feedStatus Set in Config'];
//                     }
//                 }
//             }

//           }
//         } catch (Exception $e) {
//             return ['success' => false, 'message' => $e->getMessage()];
//         }
//     }
// }
