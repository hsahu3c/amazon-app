<?php
// namespace App\Amazon\Components\Product;

// use App\Amazon\Components\LocalDelivery;
// use Exception;
// use App\Core\Components\Base;
// use App\Amazon\Components\Common\Helper;

// class LocalDeliveryPrice extends Base
// {
//     private ?string $_user_id = null;

//     private $_baseMongo;

//     private $configObj;

//     public function init($request = [])
//     {
//         if (isset($request['user_id'])) $this->_user_id = (string)$request['user_id'];
//         else $this->_user_id = (string)$this->di->getUser()->id;
//         $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
//         $this->configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');

//         return $this;
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
//                 'merchant_shipping_group_name'=>1, 'map_location_id'=>1,'price_setting'=>1, "price"=>1, 'type'=>1, 'status'=>1, 'marketplace'=>1],
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

//     public function upload($sqsData)
//     {
//         // print_r($sqsData);die;
//         $feedContent = [];
//         $response = [];
//         $addTagProductList = [];
//         $productErrorList = [];
//         $activeAccounts = [];
//         $multiplier = false;
//         $date = date('d-m-Y');
//         $container_ids = [];
//         $alreadySavedActivities = [];

//         $data = json_decode(json_encode($sqsData, true), true);
//         $changeSourceProductIds = $this->changeSourceProductIds($data['data']['params']);

//         $productComponent = $this->di->getObjectManager()->get(LocalDeliveryProduct::class);
//         $inventoryComponent = $this->di->getObjectManager()->get(LocalDeliveryInventory::class);

//         if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
//             $userId = $data['data']['params']['user_id'];
//             $targetShopId = $data['data']['params']['target']['shopId'];
//             $sourceShopId = $data['data']['params']['source']['shopId'];
//             $targetMarketplace = $data['data']['params']['target']['marketplace'];
//             $sourceMarketplace = $data['data']['params']['source']['marketplace'];
//             $logFile = "amazon/{$userId}/{$sourceShopId}/SyncPrice/{$date}.log";
// //            $this->di->getLog()->logContent('Receiving DATA = ' . print_r($data, true), 'info', $logFile);
//             $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
//             $targetShop = $user_details->getShop($targetShopId, $userId);

//             foreach($targetShop['warehouses'] as $warehouse){
//                 if($warehouse['status'] == "active"){
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

//                     $amazonCurrency = $this->configObj->getConfig('target_currency');
//                     $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);

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
//                     foreach ($data['data']['rows'] as $key => $value) {
//                         foreach($changeSourceProductIds as $key => $ldData){
//                             if($ldData['online_source_product_id'] == $value['source_product_id'] && $ldData['online_container_id'] == $value['container_id']){
//                                 $value['online_source_product_id'] = $value['source_product_id'];
//                                 $value['online_container_id'] = $value['container_id'];
//                                 $value['source_product_id'] = $ldData['source_product_id'];
//                                 $value['container_id'] = $ldData['container_id'];
//                                 // $value['marketplace'] = $ldData['marketplace'];


//                                 if($value['type'] == 'simple'){
//                                     if(isset($ldData['map_location_id']) && $ldData['map_location_id'] != NULL){
//                                         $value['map_location_id'] = $ldData['map_location_id'];
//                                     }

//                                     if(isset($ldData['price_setting']) && $ldData['price_setting'] == 'custom'){
//                                         $value['price'] = $ldData['price'];
//                                     }

//                                     if(isset($ldData['status']) && $ldData['status'] != NULL){
//                                         $value['status'] = $ldData['status'];
//                                     }

//                                     $value['sku'] = $ldData['sku'];
//                                     $value['merchant_shipping_group_name'] = $ldData['merchant_shipping_group_name'];
//                                     // unset($changeSourceProductIds[$key]);   
//                                 }

//                                 unset($changeSourceProductIds[$key]);
//                             }
//                         }

//                         $productComponent->removeTag([$value['source_product_id']], [Helper::PROCESS_TAG_PRICE_SYNC], $targetShopId, $sourceShopId, $userId, [], []);
//                         $priceTemplate = $this->prepare($value);
//                         $products = $data['data']['rows'];
//                         $productComponent->removeTag([$value['source_product_id']], [Helper::PROCESS_TAG_PRICE_SYNC], $targetShopId, $sourceShopId, $userId, [], []);
//                         if ($value['type'] == 'variation') {
//                             continue;
//                         }
//                         $target = [];
//                         $marketplaces = $value['marketplace'];
//                         foreach ($marketplaces as $marketplace) {
//                             if(isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == $targetMarketplace && $marketplace['shop_id'] == $targetShopId)
//                             {

//                                 $target = $marketplace;
//                             }
//                         }

//                         $target['status'] = 'Active';
//                         if(!isset($target['status']) || $target['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER)
//                         {
//                             $productErrorList[$value['source_product_id']] = ['Product not listed on amazon'];
//                         }
//                         else {
//                             $container_ids[$value['source_product_id']] = $value['container_id'];
//                             $alreadySavedActivities[$value['source_product_id']] = $value['edited']['last_activities'] ?? [];
//                             $calculateResponse = $this->calculate($value, $priceTemplate, $currencyCheck, false);
//                             if (!empty($calculateResponse)) {
//                                 $time = date(DATE_ISO8601);
//                                 $feedContent[$value['source_product_id']] = [
//                                     'Id' => $value['_id'] . $key,
//                                     'SKU' => $calculateResponse['SKU'],
//                                     'SalePrice' => $calculateResponse['SalePrice'],
//                                     'StandardPrice' => $calculateResponse['StandardPrice'],
//                                     'StartDate' => $calculateResponse['StartDate'],
//                                     'EndDate' => $calculateResponse['EndDate'],
//                                     'BusinessPrice' => $calculateResponse['BusinessPrice'],
//                                     'MinimumPrice' => $calculateResponse['MinimumPrice'],
//                                     'time' => $time
//                                 ];
//                             }
//                             else {
//                                 $productErrorList[$value['source_product_id']] = ['Price Syncing is Disabled. Please Enable it from the Settings and Try Again.'];
//                             }
//                         }
//                     }

//                     if (!empty($feedContent)) {


//                         $specifics = [
//                             'ids' => array_keys($feedContent),
//                             'home_shop_id' => $targetShop['_id'],
//                             'marketplace_id' => $activeAccounts['marketplace_id'],
//                             'sourceShopId' => $sourceShopId,
//                             'shop_id' => $targetShop['remote_shop_id'],
//                             'feedContent' => base64_encode(serialize($feedContent)),
//                             'user_id' => $userId,
//                             'operation_type' => 'Update'
//                         ];

//                         $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//                         $response = $commonHelper->sendRequestToAmazon('price-upload', $specifics, 'POST');
//                         // $this->di->getLog()->logContent('Response = ' . print_r($response, true), 'info', $logFile);
//                         if(isset($response['success']) && $response['success'])
//                         {
//                             $inventoryComponent->saveFeedInDb($specifics , 'price' , $container_ids , $data['data']['params'] ,true , $alreadySavedActivities);
//                         }

//                         $productSpecifics = $productComponent->init()
//                         ->processResponse($response,$feedContent, Helper::PROCESS_TAG_PRICE_FEED, 'price',$targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, Helper::PROCESS_TAG_PRICE_SYNC);
//                         $addTagProductList = $productSpecifics['addTagProductList'];
//                         $productErrorList = $productSpecifics['productErrorList'];
//                     return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);

//                     }
//                     $productSpecifics = $productComponent->init()
//                     ->processResponse($response,$feedContent, Helper::PROCESS_TAG_PRICE_SYNC, 'price',$targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList);
//                     return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);


//                 }
//                 return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'Currency of '.$sourceMarketplace.' and Amazon account are not same. Please fill currency settings from Global Price Adjustment in settings page.', 0, 0, 0);
//             }
//             return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0);
//         }
//         return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0);
//     }

//     public function calculate($product, $priceTemplateData, $changeCurrency, $forcedEnable = false)
//     {

//         $enabled = $priceTemplateData['settings_enabled'];
//         $enabled = true;


//         if ($enabled || $forcedEnable) {

//             $salePrice = false;
//             $businessPrice = false;
//             $minimumPrice = false;
//             $start = false;
//             $end = false;

//             $standardPriceMapping = $priceTemplateData['standard_price'];
//             $amazonProduct = [];
//             if (isset($product['edited'])) {
//                 $amazonProduct = $product['edited'];
//             }


//             //setting sku
//             if ($amazonProduct && isset($amazonProduct['sku'])) {
//                 $sku = $amazonProduct['sku'];
//             } elseif (isset($product['sku'])) {
//                 $sku = $product['sku'];
//             } else {
//                 $sku = $product['source_product_id'];
//             }

//             //setting standard price
//             if ($amazonProduct && isset($amazonProduct['price'])) {
//                 $productPrice = $amazonProduct['price'];
//                 $standardPrice = $productPrice;
//             } else {
//                 if (isset($product['offer_price']) && $product['offer_price']) {
//                     $productPrice = $product['offer_price'];
//                 } elseif (isset($product['price'])) {
//                     $productPrice = $product['price'];
//                 } else {
//                     $productPrice = 0;
//                 }

//                 $standardPrice = $productPrice;
//                 $standardPriceAttribute = $standardPriceMapping['attribute'];
//                 if ($standardPriceAttribute != 'default') {
//                     $standardPriceChangeValue = $standardPriceMapping['value'];
//                     $standardPrice = $this->changePrice($standardPrice, $standardPriceAttribute, $standardPriceChangeValue);
//                 }
//             }

//             if ($standardPrice < 0) {
//                 $standardPrice = 0;
//             }

//             $salePriceEnabled = $priceTemplateData['settings_selected']['sale_price'];
//             if ($salePriceEnabled) {
//                 $salePriceMapping = $priceTemplateData['sale_price'];
//                 $salePrice = $productPrice;
//                 $endDate = $priceTemplateData['sale_price']['end_date'];
//                 $startDate = $priceTemplateData['sale_price']['start_date'];
//                 $start = date('Y-m-d', strtotime((string) $startDate));
//                 $end = date('Y-m-d', strtotime((string) $endDate));
//                 $salePriceAttribute = $salePriceMapping['attribute'];
//                 if ($salePriceAttribute != 'default') {
//                     $salePriceChangeValue = $salePriceMapping['value'];
//                     $salePrice = $this->changePrice($salePrice, $salePriceAttribute, $salePriceChangeValue);
//                 }

//                 if ($salePrice < 0) {
//                     $salePrice = 0;
//                 }
//             }

//             $businessPriceEnabled = $priceTemplateData['settings_selected']['business_price'];
//             if ($businessPriceEnabled) {
//                 $businessPriceMapping = $priceTemplateData['business_price'];
//                 $businessPrice = $productPrice;
//                 $businessPriceAttribute = $businessPriceMapping['attribute'];
//                 if ($businessPriceAttribute != 'default') {
//                     $businessPriceChangeValue = $businessPriceMapping['value'];
//                     $businessPrice = $this->changePrice($businessPrice, $businessPriceAttribute, $businessPriceChangeValue);
//                 }

//                 if ($businessPrice < 0) {
//                     $businessPrice = 0;
//                 }
//             }

//             $minimumPriceEnabled = $priceTemplateData['settings_selected']['minimum_price'];
//             if ($minimumPriceEnabled) {
//                 $minimumPriceMapping = $priceTemplateData['minimum_price'];
//                 $minimumPrice = $productPrice;
//                 $minimumPriceAttribute = $minimumPriceMapping['attribute'];
//                 if ($minimumPriceAttribute != 'default') {
//                     $minimumPriceChangeValue = $minimumPriceMapping['value'];
//                     $minimumPrice = $this->changePrice($minimumPrice, $minimumPriceAttribute, $minimumPriceChangeValue);
//                 }

//                 if ($minimumPrice < 0) {
//                     $minimumPrice = 0;
//                 }
//             }

//             $standardPriceInCurrency = $standardPrice ? (float)$changeCurrency * $standardPrice : $standardPrice;
//             $salePriceInCurrency = $salePrice ? (float)$changeCurrency * $salePrice : $salePrice;
//             $minimumPriceInCurrency = $minimumPrice ? (float)$changeCurrency * $minimumPrice : $minimumPrice;
//             $businessPriceInCurrency = $businessPrice ? (float)$changeCurrency * $businessPrice : $businessPrice;

//             $finalStandardPrice = number_format((float)$standardPriceInCurrency, 2, '.', '');
//             $finalSalePrice = number_format((float)$salePriceInCurrency, 2, '.', '');
//             $finalMinimumPrice = number_format((float)$minimumPriceInCurrency, 2, '.', '');
//             $finalBusinessPrice = number_format((float)$businessPriceInCurrency, 2, '.', '');

//             if(isset($standardPriceMapping['price_roundoff_value']) && !isset($amazonProduct['price']))
//             {
//                 $finalStandardPrice = $this->priceRoundOff($finalStandardPrice,$standardPriceMapping);
//             }

//             $finalSalePrice = isset($salePriceMapping['price_roundoff_value']) ? $this->priceRoundOff($finalSalePrice,$salePriceMapping):$finalSalePrice ;
//             $finalBusinessPrice = isset($businessPriceMapping['price_roundoff_value']) ? $this->priceRoundOff($finalBusinessPrice,$businessPriceMapping):$finalBusinessPrice ;
//             $finalMinimumPrice = isset($minimumPriceMapping['price_roundoff_value']) ? $this->priceRoundOff($finalMinimumPrice,$minimumPriceMapping):$finalMinimumPrice ;

//             return [
//                 'SKU' => $sku,
//                 'StandardPrice' => (float)$finalStandardPrice,
//                 'SalePrice' => (float)$finalSalePrice,
//                 'StartDate' => $start,
//                 'EndDate' => $end,
//                 'MinimumPrice' => (float)$finalMinimumPrice,
//                 'BusinessPrice' => (float)$finalBusinessPrice
//             ];
//         }
//         return [];
//     }

//     public function priceRoundOff($oldPrice,$attribute)
//     {
//         $value = $attribute['price_roundoff_value'];
//         if($value == "")
//         {
//             return $oldPrice;
//         }

//         $initialPrice = (float)$oldPrice;
//         $finalPrice = $initialPrice;
//         switch ($value) {
//             case 'higherEndWith9':
//                 $initialPrice = ceil($initialPrice);
//                 $price = (int)($initialPrice - $initialPrice % 10);
//                 $finalPrice = $price + 9;
//                 break;
//             case 'lowerEndWith9':
//                 $initialPrice = ceil($initialPrice);
//                 $price = (int)($initialPrice - $initialPrice % 10);
//                 $finalPrice = $price - 1;
//                 break;
//             case 'higherWholeNumber':
//                 $price = (int)$initialPrice;
//                 $finalPrice = $price + 1;
//                 break; 
//             case 'higherEndWith0.49':
//                 $initialPrice = (int)$initialPrice;
//                 $finalPrice = (float)$initialPrice + 0.49;
//                 break;
//             case 'lowerEndWith0.49':
//                 $initialPrice = (int)$initialPrice;
//                 $finalPrice = (float)$initialPrice - 0.51;
//                 break;              
//             case 'lowerWholeNumber':
//                 $price = (int)$initialPrice;
//                 $finalPrice = $price - 1;
//                 break;
//             case 'higherEndWith0.99':
//                 $price = (int)$initialPrice;
//                 $finalPrice = $price + 0.99;
//                 break;
//             case 'lowerEndWith0.99':
//                 $price = (int)$initialPrice;
//                 $finalPrice = $price - 0.01;
//                 break; 
//             case 'higherEndWith10':
//                 $initialPrice = ceil($initialPrice);
//                 $price = (int)($initialPrice - $initialPrice % 10);
//                 $finalPrice = $price + 10;
//                 break;
//             case 'lowerEndWith10':
//                 $initialPrice = ceil($initialPrice);
//                 if(!($initialPrice % 10))
//                 {
//                     $price = (int)($initialPrice - $initialPrice % 10);
//                     $finalPrice = $price - 10;
//                 }
//                 else
//                 {
//                     $finalPrice = $price;
//                 }

//                 break;    
//             default:
//                 $finalPrice = $initialPrice;
//                 break;
//         }

//         if($finalPrice <= 0)
//         {
//             $finalPrice = $oldPrice;
//         }

//         return $finalPrice;
//     }

//     public function changePrice($price, $attribute, $changeValue)
//     {
//         if ($attribute == 'value_increase') {
//             $price = $price + $changeValue;
//         } elseif ($attribute == 'value_decrease') {
//             $price = $price - $changeValue;
//         } elseif ($attribute == 'percentage_increase') {
//             $price = $price + (($changeValue / 100) * $price);
//         } elseif ($attribute == 'percentage_decrease') {
//             $price = $price - (($changeValue / 100) * $price);
//         }

//         return $price;
//     }

//     // public function update($data)
//     // {
//     //     if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
//     //         $ids = $data['source_product_ids'];
//     //         $profileIds = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\Helper')
//     //             ->getProfileIdsByProductIds($data['source_product_ids']);

//     //         if (!empty($profileIds)) {
//     //             //get profiles from profile Ids
//     //             $profileCollection = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILES);
//     //             $profiles = $profileCollection->find(['profile_id' => ['$in' => $profileIds]], ["typeMap" => ['root' => 'array', 'document' => 'array']]);

//     //             foreach ($profiles as $profileKey => $profile) {
//     //                 $profileId = $profile['profile_id'];
//     //                 $accounts = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\Helper')
//     //                     ->getAccountsByProfile($profile);

//     //                 foreach ($accounts as $homeShopId => $account) {
//     //                     $productIds = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\Helper')
//     //                         ->getAssociatedProductIds($profileId, $ids);

//     //                     if (!empty($productIds)) {
//     //                         $priceTemplateId = $account['templates']['price'];
//     //                         $categoryTemplateId = $account['templates']['category'];
//     //                         foreach ($account['warehouses'] as $amazonWarehouse) {
//     //                             $marketplaceIds = $amazonWarehouse['marketplace_id'];
//     //                             if (!empty($marketplaceIds)) {
//     //                                 $specifics = [
//     //                                     'ids' => $productIds,
//     //                                     'home_shop_id' => $homeShopId,
//     //                                     'marketplace_id' => $marketplaceIds,
//     //                                     'profile_id' => $profile['profile_id'],
//     //                                     'price_template' => $priceTemplateId,
//     //                                     'category_template' => $categoryTemplateId,
//     //                                     'shop_id' => $account['remote_shop_id']
//     //                                 ];
//     //                                 $feedContent = $this->prepare($specifics);
//     //                                 if (!empty($feedContent)) {
//     //                                     $specifics['feedContent'] = $feedContent;
//     //                                     $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//     //                                     $response = $commonHelper->sendRequestToAmazon('price-upload', $specifics, 'POST');
//     //                                     $feed = $this->di->getObjectManager()
//     //                                         ->get(Feed::class)
//     //                                         ->init(['user_id' => $this->_user_id, 'shop_id' => $homeShopId])
//     //                                         ->process($response);
//     //                                 }
//     //                             }
//     //                         }
//     //                     }
//     //                 }
//     //             }
//     //         }
//     //     }
//     // }

//     public function prepare($data)
//     {

//         $profilePresent = false;
//         $priceTemplateData = [];
//         if (isset($data['profile_info']) && isset($data['profile_info']['data'])) {
//             foreach ($data['profile_info']['data'] as $value) {
//                 if ($value['data_type'] == 'pricing_settings') {
//                     $priceTemplateData = $value['data'];
//                     $profilePresent = true;
//                 }
//             }
//         }

//         if (!$profilePresent) {
//             $this->configObj->setGroupCode('price');
//             $priceConfig = $this->configObj->getConfig();
//             $priceConfig = json_decode(json_encode($priceConfig, true), true);
//             foreach ($priceConfig as $value) {
//                 $priceTemplateData[$value['key']] = $value['value'];
//                 $profilePresent = true;
//             }
//         }

//         if (!$profilePresent) {
//             $this->configObj->setUserId('all');
//             $this->configObj->setGroupCode('price');
//             $priceConfig = $this->configObj->getConfig();
//             $priceConfig = json_decode(json_encode($priceConfig, true), true);
//             // print_r($priceConfig);die;
//             foreach ($priceConfig as $value) {
//                 $priceTemplateData[$value['key']] = $value['value'];
//                 $profilePresent =true;
//             }
//         }

//         return $priceTemplateData;
//     }


//     public function getConversionRate($sourceMarketplace = false)
//     {

//         $sourceMarketplaceCurrency = false;
//         $changeCurrency = false;
//         /*check if currency is different on sourcemarketplace and amazon*/

//         $amazonHelper = $this->di->getObjectManager()->get('App\Frontend\Components\AmazonebaymultiHelper');
//         $connectedAccounts = $amazonHelper->getAllConnectedAcccounts($this->_user_id); //all rhe connected acc come togather


//         foreach ($connectedAccounts['data'] as $shop) {
//             if ($shop['marketplace'] === 'onyx') {
//                 if (isset($shop['shop_details']['currency'])) {
//                     $sourceMarketplaceCurrency = $shop['shop_details']['currency'];
//                     break;
//                 }
//             }
//         }

//         $configuration = $this->_baseMongo->getCollectionForTable(Helper::CONFIGURATION)->findOne(['user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//         // check both are same country

//         if (isset($configuration['data']['currency_settings']['settings_enabled']) && $configuration['data']['currency_settings']['settings_enabled']) {
//             if (isset($configuration['data']['currency_settings']['amazon_currency'])) {
//                 $changeCurrency = $configuration['data']['currency_settings']['amazon_currency'];
//             }
//         }

//         $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//         $activeAccounts = [];
//         $connectedAccounts = $commonHelper->getAllAmazonShops($this->_user_id);

//         foreach ($connectedAccounts as $account) {
//             if ($account['warehouses'][0]['status'] == 'active') {
//                 $marketplaceId = $account['warehouses'][0]['marketplace_id'];

//                 if (!$changeCurrency) {
//                     if (Helper::MARKETPLACE_CURRENCY[$marketplaceId] != $sourceMarketplaceCurrency) {
//                         //if marketplace id's currency != $shop currency
//                         return false;
//                     }
//                     $changeCurrency = 1;
//                 }
//             }
//         }

//         return $changeCurrency;
//     }
// }
