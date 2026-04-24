<?php
// namespace App\Amazon\Components\Product;

// use App\Amazon\Components\LocalDelivery;
// use Exception;
// use App\Amazon\Components\LocalSelling;
// use App\Core\Components\Base;
// use App\Amazon\Components\Common\Helper;

// #[\AllowDynamicProperties]
// class LocalDeliveryInventory extends Base
// {
//     private ?string $_user_id = null;

//     private $_user_details;

//     private $_baseMongo;

//     private $configObj;

//     public function init($request = [])
//     {
//         if (isset($request['user_id']))
//             $this->_user_id = (string) $request['user_id'];
//         else
//             $this->_user_id = (string) $this->di->getUser()->id;

//         $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
//         if (isset($request['shop_id'])) {
//             $this->_shop_id = (string) $request['shop_id'];
//             $shop = $this->_user_details->getShop($this->_shop_id, $this->_user_id);
//         } else {
//             $shop = $this->_user_details->getDataByUserID($this->_user_id, 'amazon');
//             $this->_shop_id = $shop['_id'];
//         }

//         $this->_remote_shop_id = $shop['remote_shop_id'];
//         /*$this->_site_id = $shop['warehouses'][0]['seller_id'];*/

//         $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
//         $this->configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
//         $appTag = $this->di->getAppCode()->getAppTag();
//         if ($appTag == 'default') {
//             $this->configObj->setAppTag(null);
//         }

//         return $this;
//     }

//     public function checkFba($sourceProductId, $userId, $targetShopId)
//     {
//         $amazonListing = false;
//         $amazonCollection = $this->_baseMongo->getCollectionForTable(Helper::AMAZON_LISTING);
//         $amazonListing = $amazonCollection->find(
//             ['user_id' => (string) $userId, 'shop_id' => (string) $targetShopId, 'source_product_id' => $sourceProductId],
//             ['projection' => ["_id" => false, "fulfillment-channel" => true], 'typeMap' => ['root' => 'array', 'document' => 'array']]
//         );
//         $listingData = $amazonListing->toArray();

//         if (!empty($listingData)) {
//             if (isset($listingData[0]['fulfillment-channel']) && $listingData[0]['fulfillment-channel'] !== 'DEFAULT') {
//                 return ['success' => false, 'msg' => ['Product you selected is not FBM product.']];
//             }
//             return ['success' => true];
//         }
//         return ['success' => false, 'msg' => ['Product not listed on amazon']];
//     }

//     public function changeSourceProductIds($data){
//         try{
//             $getProductForLD = $this->di->getObjectManager()->get(LocalDelivery::class);
//             // die("dcbsj");

//             $ldSourceProductIds = array_keys($data['ld_source_product_ids']);

//             $userId = $data['user_id'];
//             $sourceShopId = $data['source']['shopId'];
//             // print_r($sourceShopId);die;
//             $sourceMarketplace = $data['source']['marketplace'];
//             $new = [];

//             foreach($ldSourceProductIds as $sourceProductId){

//                 $query = [
//                     'user_id' => $userId, 'shop_id' => $sourceShopId, 'source_product_id' => $sourceProductId
//                 ];
//                 $options = ['projection'=> ['_id' => 0,'sku'=>1, 'container_id'=>1,'source_product_id'=>1,'online_source_product_id'=>1, 'online_container_id'=>1, 
//                 'merchant_shipping_group_name'=>1, 'map_location_id'=>1,'price_setting'=>1, "price"=>1, 'type'=>1, 'status'=>1],
//                 'typeMap'   => ['root' => 'array', 'document' => 'array']];

//                 $localDeliveryProduct =  $getProductForLD->getproductbyQueryLocalDelivery($query, $options);

//                 if($localDeliveryProduct[0]['type'] == 'variation'){
//                     $params = [
//                         'source_product_id'=>$sourceProductId,'container_id'=>$localDeliveryProduct[0]['container_id'], 'shop_id'=>$sourceShopId, 'source'=>['marketplace'=>$sourceMarketplace],
//                         'user_id'=> $userId, 'merchant_shipping_group_name' => $localDeliveryProduct[0]['merchant_shipping_group_name']
//                     ];
//                     $localDeliveryProducts =  $getProductForLD->getProductsForLocalDelivery($params);
//                     // print_r($localDeliveryProducts);
//                     // $merging = array_column($localDeliveryProducts['data'], 'source_product_id', 'online_source_product_id');

//                     $keys = array_keys($options['projection'], 1);

//                     $result = array_map(fn($item): array => array_intersect_key($item, array_flip($keys)), $localDeliveryProducts['data']);
//                 }
//                 else{
//                     $keys = array_keys($options['projection'], 1);

//                     $result = array_map(fn($item): array => array_intersect_key($item, array_flip($keys)), $localDeliveryProduct);

//                 }

//                 $new = [...$new, ...$result];

//             }

//             return $new;

//         }
//         catch (Exception $e) {
//             return ['success' => false, 'message' => $e->getMessage()];
//         }

//     }

//     public function upload($sqsData)
//     {

//         $feedContent = [];

//         $addTagProductList = [];
//         $productErrorList = [];
//         $activeAccounts = [];
//         $uploadStatus = [];
//         $response = [];
//         $data = json_decode(json_encode($sqsData, true), true);
//         $changeSourceProductIds = $this->changeSourceProductIds($data['data']['params']);


//         $productComponent = $this->di->getObjectManager()->get(LocalDeliveryProduct::class);

//         if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
//             $userId = $data['data']['params']['user_id'] ?? $this->_user_id;
//             $targetShopId = $data['data']['params']['target']['shopId'];
//             $sourceShopId = $data['data']['params']['source']['shopId'];
//             $targetMarketplace = $data['data']['params']['target']['marketplace'];
//             $sourceMarketplace = $data['data']['params']['source']['marketplace'];
//             $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
//             $amazonShop = $userDetails->getShop($targetShopId, $userId);
//             $notUploadStatus = true;
//             $date = date('d-m-Y');
//             $logFile = "amazon/{$userId}/{$sourceShopId}/SyncInventory/{$date}.log";
//             $localSellingQuantity = [];
//             $container_ids = [];
//             $alreadySavedActivities = [];

//             // $this->di->getLog()->logContent('Receiving DATA = ' . print_r($data, true), 'info', $logFile);
//             if ($amazonShop['warehouses'][0]['status'] == 'active') {
//                 $products = $data['data']['rows'];
//                 $localSelling = [];
//                 foreach ($products as $product) {
//                     $productComponent->removeTag([$product['source_product_id']], [Helper::PROCESS_TAG_INVENTORY_SYNC], $targetShopId, $sourceShopId, $userId, [], []);
//                     foreach($changeSourceProductIds as $key => $ldData){
//                         if($ldData['online_source_product_id'] == $product['source_product_id'] && $ldData['online_container_id'] == $product['container_id']){
//                             $product['online_source_product_id'] = $product['source_product_id'];
//                             $product['online_container_id'] = $product['container_id'];
//                             $product['source_product_id'] = $ldData['source_product_id'];
//                             $product['container_id'] = $ldData['container_id'];


//                             if($product['type'] == 'simple'){
//                                 if(isset($ldData['map_location_id']) && $ldData['map_location_id'] != NULL){
//                                     $product['map_location_id'] = $ldData['map_location_id'];
//                                 }

//                                 if(isset($ldData['price_setting']) && $ldData['price_setting'] == 'custom'){
//                                     $product['price'] = $ldData['price'];
//                                 }

//                                 if(isset($ldData['status']) && $ldData['status'] != NULL){
//                                     $product['status'] = $ldData['status'];
//                                 }

//                                 $product['sku'] = $ldData['sku'];
//                                 $product['merchant_shipping_group_name'] = $ldData['merchant_shipping_group_name'];
//                                 // unset($changeSourceProductIds[$key]);

//                             }


//                             unset($changeSourceProductIds[$key]);
//                         }

//                         // print_r($product['online_container_id']);die;

//                     }

//                     if ($product['type'] == 'variation') {
//                         //need to discuss what to return for variant parent product as variant parent's inv will not sync to amazon
//                         continue;
//                     }

//                     $container_ids[$product['source_product_id']] = $product['container_id'];
//                     $alreadySavedActivities[$product['source_product_id']] = $product['edited']['last_activities'] ?? [];

//                     $marketplaceData = $product['marketplace'];
//                     foreach ($marketplaceData as $marketplace) {
//                         if (isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == $targetMarketplace && $marketplace['shop_id'] == $targetShopId) {
//                             if (isset($marketplace['status']) && ($marketplace['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UPLOADED)) {
//                                 $notUploadStatus = false;
//                             }
//                         }
//                     }

//                     $validProduct = $this->checkFba($product['online_source_product_id'], $userId, $targetShopId);

//                     $notUploadStatus = false;
//                     if (!$validProduct['success'] && $notUploadStatus) {
//                         if (isset($validProduct['msg'])) {
//                             $productErrorList[$product['source_product_id']] = $validProduct['msg'];
//                         }
//                     } else {
//                         $rawBody = ["group_code" => ['order']];
//                         $this->configObj->setUserId($userId);
//                         $this->configObj->setTargetShopId($targetShopId);
//                         $this->configObj->setSourceShopId($sourceShopId);
//                         $this->configObj->setTarget($targetMarketplace);
//                         $configHelper = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');
//                         $configResponse = $configHelper->getConfigData($rawBody);
//                         $configResponse = $configResponse['data'] ?? [];
//                         $invTemplateData = $this->prepare($product);
//                         $calculateResponse = $this->calculate($product, $invTemplateData, $sourceMarketplace);
//                         // print_r($calculateResponse);die;
//                         // $this->di->getLog()->logContent('Template data => ' . print_r($data, true), 'info', $logFile);
//                         $type = $invTemplateData['type'] ?? false;
//                         if($type && $type != 'Product Settings')
//                         {
//                             $invTemplateData = $this->prepare($product , true);
//                         }

//                         if (!empty($calculateResponse)) {
//                                 if(isset($calculateResponse['error'] , $calculateResponse['saveInConfig']))
//                                 {
//                                     $update = false;
//                                     if(isset($invTemplateData['warehouse_disabled']))
//                                     {
//                                         $value = $invTemplateData['warehouse_disabled'];
//                                         if(!in_array($type , $value))
//                                         {
//                                             $update = true;
//                                             array_push($value , $type);
//                                         }
//                                     } 
//                                     else
//                                     {
//                                         $update = true;
//                                         $value[] = $type;
//                                     }

//                                     if($update)
//                                     {
//                                         $savedData = [
//                                             'group_code' => 'inventory',
//                                             'data' => [
//                                                 'warehouse_disabled' => $value
//                                                 ]
//                                             ];
//                                         $configData =  $data['data']['params'];
//                                         $configData['data'][0] = $savedData;
//                                         $res = $configHelper->saveConfigData($configData); 
//                                     }

//                                     $productErrorList[$product['source_product_id']] = [$calculateResponse['error']];
//                                 }
//                                 else
//                                 {
//                                     if (isset($calculateResponse['Quantity']) && $calculateResponse['Quantity'] < 0) {
//                                         $calculateResponse['Quantity'] = 0;
//                                     }

//                                     // if (isset($calculateResponse['delete_out_of_stock']) && $calculateResponse['delete_out_of_stock']) {
//                                     //     $deleteOutOfStock[] = $product['source_product_id'];
//                                     // }
//                                     $time = date(DATE_ISO8601);
//                                     $feedContent[$product['source_product_id']] = [
//                                         'Id' => $product['_id'],
//                                         'SKU' => $calculateResponse['SKU'],
//                                         'Quantity' => $calculateResponse['Quantity'],
//                                         'Latency' => $calculateResponse['Latency'],
//                                         'time' => $time
//                                     ];
//                                 }

//                         } else {
//                             $inventoryDisabledErrorProductList[] = $product['source_product_id'];
//                             $productErrorList[$product['source_product_id']] = ['Inventory Syncing is disabled. Please check the Settings and Try Again.'];
//                         }
//                     }
//                 }

//                 // print_r($feedContent);die;
//                 $this->di->getLog()->logContent('FeedContent= ' . print_r($feedContent, true), 'info', 'localDeliveryInventory.php');

//                 // if (!empty($deleteOutOfStock)) {
//                 //     $dataToSent['source']['marketplace'] = $sourceMarketplace;
//                 //     $dataToSent['target']['marketplace'] = $targetMarketplace;
//                 //     $dataToSent['source']['shopId'] = $sourceShopId;
//                 //     $dataToSent['target']['shopId'] = $targetShopId;
//                 //     $dataToSent['operationType'] = 'product_delete';
//                 //     $dataToSent['source_product_ids'] = $deleteOutOfStock;
//                 //     $dataToSent['user_id'] = $userId;
//                 //     $dataToSent['limit'] = 500;
//                 //     $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
//                 //     // $this->di->getLog()->logContent('DeleteOutOfStock = ' . print_r($dataToSent, true), 'info', $logFile);
//                 //     $product->startSync($dataToSent);
//                 // }
//                 // print_r($productErrorList);die;



//                 if (!empty($feedContent) || !empty($localSelling)) {


//                     if (!empty($feedContent)) {
//                         $specifics = [
//                             'ids' => array_keys($feedContent),
//                             'home_shop_id' => $amazonShop['_id'],
//                             'marketplace_id' => $amazonShop['warehouses'][0]['marketplace_id'],
//                             'sourceShopId' => $sourceShopId,
//                             'shop_id' => $amazonShop['remote_shop_id'],
//                             'feedContent' => base64_encode(json_encode($feedContent)),
//                             'user_id' => $userId,
//                             'operation_type' => 'Update'
//                         ];
//                         $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//                         $response = $commonHelper->sendRequestToAmazon('inventory-upload', $specifics, 'POST');

//                         if(isset($response['success']) && $response['success'])
//                         {
//                             $specifics['feedContent'] = json_decode(base64_decode($specifics['feedContent']),true);
//                             $this->saveFeedInDb($specifics , 'inventory' , $container_ids , $data['data']['params'] , false , $alreadySavedActivities);
//                         }

//                         // $this->di->getLog()->logContent('Response = ' . print_r($response, true), 'info', $logFile);

//                         //                    if(isset($products[0]['edited'])){
//                         //                        $products[0]['edited']['Quantity']=$calculateResponse['Quantity'];
//                         //                        $products[0]['edited']['Latency']=$calculateResponse['Latency'];
//                         //                    }
//                         $productSpecifics = $productComponent->init()
//                             ->processResponse($response, $feedContent, Helper::PROCESS_TAG_INVENTORY_FEED, 'inventory', $amazonShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, Helper::PROCESS_TAG_INVENTORY_SYNC);

//                         $addTagProductList = $productSpecifics['addTagProductList'];
//                         $productErrorList = $productSpecifics['productErrorList'];
//                         $responseLocal = $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);
//                     }

//                     if (!empty($responseLocal) && !empty($responseOnline)) {
//                         $responseData = array_merge($responseLocal, $responseOnline);
//                     } elseif (!empty($responseLocal)) {
//                         $responseData = $responseLocal;
//                     } elseif (!empty($responseOnline)) {
//                         $responseData = $responseOnline;
//                     }

//                     return $responseData;
//                 }
//                 $productSpecifics = $productComponent->init()
//                     ->processResponse($response, $feedContent, Helper::PROCESS_TAG_INVENTORY_SYNC, 'inventory', $amazonShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList);
//                 return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);

//             }
//             // return error msg 'Selected target shop is not active'
//             return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'Selected target shop is not active.', 0, 0, 0);

//         }
//         //return error msg 'One of rows data or target or source shops not found'
//         return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'One of rows data or target or source shops not found.', 0, 0, 0);
//     }

//     public function saveFeedinDb($data , $action , $container_ids , $params, $serialize = false, $alreadySavedActivities = null): void
//     {
//         $editedData = [];
//         $feedContent = $data['feedContent'];
//         $userId = $data['data']['params']['user_id'] ?? $this->_user_id;
//         $targetShopId = $params['target']['shopId'];
//         $sourceShopId = $params['source']['shopId'];
//         $targetMarketplace = $params['target']['marketplace'];
//         $sourceMarketplace = $params['source']['marketplace'];
//         if($serialize)
//         {
//             $feedContent = unserialize(base64_decode((string) $data['feedContent']));
//         }

//         if($action == 'image')
//         {
//             $feedData = [];
//             foreach ($feedContent as $key => $value) {
//                 $feedData[$value['SourceProductId']] = $feedContent[$key];
//             }

//             $feedContent = $feedData;
//         }

//         $alreadySavedActivity = [];
//         $containerId = false;
//         $dataToSave = [];
//         $current_activity = false;

//         foreach ($feedContent as $sourceProductId => $feed) {
//             $source_id = (string) $sourceProductId;
//             $current_activity = [];
//             $containerId = $container_ids[$sourceProductId] ?? "";
//             $alreadySavedActivity = $alreadySavedActivities[$sourceProductId];
//             $time = $feed['time'];
//             unset($feed['time']);
//             $current_activity = [
//                 'type' => $action,
//                 'updated_at' => $time,
//                 'status' => 'in_progress',
//                 'data' => $feed
//             ];

//             if(!empty($alreadySavedActivity))
//             {
//                 $updatedActivity = $this->getUpdatedActivity($alreadySavedActivity , $current_activity , $action);
//             }
//             else
//             {
//                 $updatedActivity[] = $current_activity;
//             }

//             $editedData[] = [
//                 'target_marketplace' => $targetMarketplace,
//                 'shop_id' => (string) $targetShopId,
//                 'container_id' =>(string) $containerId,
//                 'source_product_id' => (string)$sourceProductId,
//                 'user_id' => $this->_user_id,
//                 'source_shop_id' => $sourceShopId,
//                 'last_activities' => $updatedActivity
//             ];
//         }

//         // print_r($editedData);die;
//         if(!empty($editedData))
//         {
//             $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
//             $result = $editHelper->saveProduct($editedData , false , $params);
//         }
//     }

//     public function getUpdatedActivity($alreadySavedActivity , $current_activity , $action)
//     {
//         $updatedActivity = [];
//         $actionInActivity = [];

//         foreach ($alreadySavedActivity as $activity) {
//             if(isset($activity['type']) && $activity['type'] == $action)
//             {
//                 array_push($actionInActivity , $activity);
//             }
//             else
//             {
//                 array_push($updatedActivity , $activity);
//             }
//         }

//         if(!empty($actionInActivity) && count($actionInActivity) > 4)
//         {
//             array_shift($actionInActivity);
//             array_push($actionInActivity , $current_activity);

//         }
//         else
//         {
//             array_push($actionInActivity , $current_activity);
//         }

//         return array_merge($updatedActivity , $actionInActivity);
//     }

//     public function prepare_data_bopis($product, $localSellingQuantity, $invTemplateData)
//     {
//         $traveller = 0;
//         $va = [];

//         $bopis_data = [];
//         $count = 0;
//         // get store from db
//         $data['user_id'] = $this->di->getUser()->id;
//         $data['target_shop_id'] = $this->di->getRequester()->getTargetId();
//         $data['onlyActiveStore'] = true;
//         $data['target_marketplace'] = $this->di->getRequester()->getTargetName();
//         $helper = $this->di->getObjectManager()->get(LocalSelling::class);
//         $result = $helper->getAllStores($data);

//         foreach ($product as $main_data) {


//             foreach ($main_data['edited'] as $key => $value) {
//                 if ($key == 'onlineSelling' && $value == true) {
//                     $bopis_data[$count]['local'] = [
//                         'quantity' => $localSellingQuantity[$main_data['source_product_id']],
//                     ];
//                 }

//                 if ($key == 'localSelling' && $value == true) {
//                     foreach ($main_data['edited']['localsellingStoreData'] as $online_value) {
//                         if ($online_value['store_enabled']) {
//                             $action = $this->checkstore($online_value['store_id'], $result);
//                             // print_r($online_value);

//                             if ($action) {
//                                 $storeValue = $this->getTotalInventoryForBOPIS($main_data, $online_value, $invTemplateData);
//                                 $online_value['store_value'] = $storeValue;
//                                 $bopis_data[$count]['online'][] = $online_value;
//                             }


//                         }
//                     }
//                 }

//                 $bopis_data[$count]['sku'] = $main_data['edited']['sku'] ?? $main_data['sku'];
//                 $bopis_data[$count]['source_product_id'] = $main_data['source_product_id'];

//             }

//             $count++;
//         }

//         return $bopis_data;
//     }

//     public function getTotalInventoryForBOPIS($product, $onlineValue, $invTemplateData){
//         try{

//             $EnabledWareHouses = $invTemplateData['warehouses_settings'];

//             $locations = $product['locations'];
//             $userId = $product['user_id'];
//             $sourceShopId = $product['shop_id'];
//             $warehouses = $this->getActiveWarehouses($userId , $sourceShopId);
//             $BOPISlocations = $onlineValue['shopify_location'];
//             $Activewarehouses = $warehouses['active'];
//             $TotalInventory = 0;
//             foreach($locations as $singleLocation){
//                 if(in_array($singleLocation['location_id'], $EnabledWareHouses) && in_array($singleLocation['location_id'], $BOPISlocations)){
//                     if(in_array($singleLocation['location_id'], $Activewarehouses)){
//                         $TotalInventory = $TotalInventory + $singleLocation['available'];
//                     }    

//                 }


//             }

//             return $TotalInventory;


//         }
//         catch (Exception $e) {
//             return ['success' => false, 'message' => $e->getMessage()];
//         }
//     }

//     public function checkstore($chain_id, $result)
//     {
//         $action = false;
//         foreach ($result['data'] as $value) {
//             if ($value['supplySourceId'] == $chain_id && $value['status'] == 'Active') {
//                 $action = true;
//             }
//         }

//         return $action;
//     }

//     public function prepare($data , $getConfig = false)
//     {
//         $value = [];
//         $type = false;
//         $profilePresent = false;
//         $invTemplateData = [];
//         if (isset($data['profile_info']) && isset($data['profile_info']['data']) && !$getConfig) {
//             $type = $data['profile'][0]['profile_id']['$oid'] ?? false;
//             foreach ($data['profile_info']['data'] as $value) {
//                 if ($value['data_type'] == 'inventory_settings') {
//                     $invTemplateData = $value['data'];
//                     $profilePresent = true;
//                 }
//             }
//         }

//         $invConfigData = [];
//         if (!$profilePresent || $getConfig) {
//             $type = 'Product Settings';
//             $this->configObj->setGroupCode('inventory');
//             $invConfigData = $this->configObj->getConfig();
//             $invConfigData = json_decode(json_encode($invConfigData, true), true);
//             foreach ($invConfigData as $value) {
//                 $invTemplateData[$value['key']] = $value['value'];
//                 $profilePresent = true;
//             }

//             if($getConfig)
//             {
//                 return $invTemplateData;
//             }
//         }

//         if (!$profilePresent) {
//             $this->configObj->setUserId('all');
//             $this->configObj->setGroupCode('inventory');
//             $type = 'default';
//             $invConfigData = $this->configObj->getConfig();
//             $invConfigData = json_decode(json_encode($invConfigData, true), true);
//             foreach ($invConfigData as $value) {
//                 $invTemplateData[$value['key']] = $value['value'];
//                 $profilePresent = true;
//             }
//         }

//         $invTemplateData['type'] = $type;
//         return $invTemplateData;
//     }

//     public function getActiveWarehouses($userId , $sourceShopId)
//     {
//         $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
//         $sourceShop = $userDetails->getShop($sourceShopId, $userId);
//         $warehouses = $sourceShop['warehouses'];
//         $activeWarehouses = [];
//         $allWarehouses = [];
//         foreach ($warehouses as $warehouse) {
//             $allWarehouses[] = $warehouse['id'];
//             if(isset($warehouse['active']) && $warehouse['active'])
//             {
//                 $activeWarehouses[] = $warehouse['id'];
//             }
//         }

//         return [
//             'active' => $activeWarehouses, 
//             'all'    => $allWarehouses
//         ];
//     }


//     public function calculate($product, $invTemplateData, $sourceMarketplace = false)
//     {

//         if(!$sourceMarketplace)
//         {
//             $sourceMarketplace = $product['source_marketplace'];
//         }

//         $sourceShopId = $product['shop_id'];
//         $userId = $this->_user_id;
//         $warehouses = [];
//         $isPresent = false;

//         $enabled = $invTemplateData['settings_enabled'] ?? false;
//         // print_r($enabled);die;
//         $amazonProduct = [];
//         if ($enabled) {
//             if (isset($product['edited'])) {
//                 $amazonProduct = $product['edited'];
//             }

//             // set SKU
//             if ($amazonProduct && isset($amazonProduct['sku'])) {
//                 $sku = $amazonProduct['sku'];
//             } elseif (isset($product['sku'])) {
//                 $sku = $product['sku'];
//             } else {
//                 $sku = $product['source_product_id'];
//             }

//             // set latency
//             if ($amazonProduct && isset($amazonProduct['inventory_fulfillment_latency'])) {
//                 $latency = $amazonProduct['inventory_fulfillment_latency'];
//             } elseif (isset($invTemplateData['inventory_fulfillment_latency'])) {
//                 $latency = $invTemplateData['inventory_fulfillment_latency'];
//             } else {
//                 $latency = 1;
//             }

//             //set inventory policy & set quantity

//             if (isset($invTemplateData['max_inventory_level']) && $invTemplateData['max_inventory_level'] != "") {
//                 $max_inventory_level = (int) $invTemplateData['max_inventory_level'];
//             } else {
//                 $max_inventory_level = 999;
//             }

//             if (isset($invTemplateData['enable_max_inventory_level'])) {
//                 $enable_max_inventory_level = $invTemplateData['enable_max_inventory_level'];
//             } else {
//                 $enable_max_inventory_level = true;
//             }

//             $warehouses = $this->getActiveWarehouses($userId , $sourceShopId);
//             $activeWarehouses = $warehouses['active'] ?? [];
//             $allWarehouses = $warehouses['all'] ?? [];
//             $qty = 0;
//             if (array_key_exists('inventory_management', $product) && $enable_max_inventory_level == true) {
//                 if (is_null($product['inventory_management'])) {
//                     $qty = $max_inventory_level;
//                 } else {
//                     if ($product['inventory_management'] == $sourceMarketplace) {
//                         if (isset($product['inventory_policy']) && $product['inventory_policy'] == "CONTINUE") {
//                             // then set qty either 999 or whatever filled in setting
//                             $qty = $max_inventory_level;
//                         } else {
//                             if ($amazonProduct && isset($amazonProduct['quantity'])) {
//                                 $qty = $amazonProduct['quantity'];
//                             } else {

//                                 /* code for selected warehouse */
//                                 $qty = 0;
//                                 $qtyChange = false;

//                                 if(isset($product['map_location_id'], $product['locations']) && !empty($product['map_location_id'])  && is_array($product['locations'])){
//                                    foreach($product['map_location_id'] as $LdLocationId){
//                                        if (in_array($LdLocationId, $invTemplateData['warehouses_settings'])) {
//                                            foreach($product['locations'] as $location){
//                                                if($LdLocationId == $location['location_id']){
//                                                    $qty =  $qty + $location['available'];
//                                                    $qtyChange=true;
//                                                 }
//                                             }
//                                         }
//                                     }
//                                 } 
//                                 else{
//                                     if (isset($product['locations']) && is_array($product['locations']) && !($qtyChange)) {
//                                         foreach ($product['locations'] as $location) {
//                                             if(isset($invTemplateData['warehouses_settings']) && $invTemplateData['warehouses_settings'] == 'all')
//                                             {
//                                                 $qty = $qty + $location['available'];
//                                                 $qtyChange = true;
//                                             }
//                                             elseif(in_array($location['location_id'], $invTemplateData['warehouses_settings']) && in_array($location['location_id'] , $activeWarehouses)) {
//                                                 $qty = $qty + $location['available'];
//                                                 $qtyChange = true;
//                                             }
//                                             elseif(count($activeWarehouses) == 1 && $activeWarehouses[0] == $location['location_id'])
//                                             {
//                                                 $qty = $qty + $location['available'];
//                                                 $qtyChange = true;
//                                             }
//                                             elseif(in_array($location['location_id'] , $allWarehouses))
//                                             {
//                                                 $isPresent = true;
//                                             }
//                                         }

//                                         if(isset($invTemplateData['warehouses_settings'][0]) && count($activeWarehouses) > 1 && !$qtyChange && $isPresent)
//                                         {
//                                             return [
//                                                 'success' => false , 
//                                                 'saveInConfig' => true,
//                                                 'error' => 'Warehouse in the selected Template Disabled - Please choose an active warehouse for this product.'
//                                             ];
//                                         }
//                                     }
//                                 }    

//                                 //for handling location error exception while type conversion
//                                 if ($qtyChange == false && $qty == 0 && isset($product['quantity'])) {
//                                     $qty = (int) $product['quantity'];
//                                 }

//                                 $availableQty = $qty;
//                                 /*Customise Inventory and Fixed Inventory have same (lowest)priority and then Threshold and then delete product (highest)*/
//                                 if ($invTemplateData['settings_selected']['customize_inventory']) {
//                                     $qty = $this->changeInv((int) $availableQty, 'value_decrease', $invTemplateData['customize_inventory']['value']);
//                                 } elseif ($invTemplateData['settings_selected']['fixed_inventory']) {
//                                     $qty = $this->changeInv((int) $availableQty, 'set_fixed_inventory', $invTemplateData['fixed_inventory']);
//                                 } elseif ($invTemplateData['settings_selected']['delete_out_of_stock'] && (int) $availableQty == 0) {
//                                     return [
//                                         'SKU' => $sku,
//                                         'delete_out_of_stock' => true,
//                                         'Quantity' => $qty,
//                                         'Latency' => $latency
//                                     ];
//                                 }

//                                 if ($invTemplateData['settings_selected']['threshold_inventory']) {
//                                     if ((int) $invTemplateData['threshold_inventory'] >= (int) $availableQty) {
//                                         $qty = 0;
//                                     }
//                                 }
//                             }
//                         }
//                     } else {
//                         $qty = $max_inventory_level;
//                     }
//                 }
//             } else {
//                 // when inv management does not exist
//                 if ($amazonProduct && isset($amazonProduct['quantity'])) {
//                     $qty = $amazonProduct['quantity'];
//                 } else {

//                     /* code for selected warehouse */
//                     $qty = 0;
//                     $qtyChange = false;
//                     if(isset($product['map_location_id'], $product['locations']) && $product['map_location_id'] != null && is_array($product['locations'])){
//                         if (in_array($product['map_location_id'], $invTemplateData['warehouses_settings'])) {
//                         foreach($product['locations'] as $location){
//                             if($product['map_location_id'] == $location['location_id'] && !($qtyChange)){
//                                $qty =  $location['available'];
//                                $qtyChange=true;
//                             }
//                         }
//                       }
//                     }
//                     else{
//                         if (isset($product['locations']) && is_array($product['locations']) && !($qtyChange)) {
//                             foreach ($product['locations'] as $location) {
//                                 if(isset($invTemplateData['warehouses_settings']) && $invTemplateData['warehouses_settings'] == 'all')
//                                 {
//                                     $qty = $qty + $location['available'];
//                                     $qtyChange = true;
//                                 }
//                                 elseif(in_array($location['location_id'], $invTemplateData['warehouses_settings']) && in_array($location['location_id'] , $activeWarehouses)) {
//                                     $qty = $qty + $location['available'];
//                                     $qtyChange = true;
//                                 }
//                                 elseif(count($activeWarehouses) == 1 && $activeWarehouses[0] == $location['location_id'])
//                                 {
//                                     $qty = $qty + $location['available'];
//                                     $qtyChange = true;
//                                 }
//                                 elseif(in_array($location['location_id'] , $allWarehouses))
//                                 {
//                                     $isPresent = true;
//                                 }
//                             }

//                             if(isset($invTemplateData['warehouses_settings'][0]) && count($activeWarehouses) > 1 && !$qtyChange && $isPresent)
//                             {
//                                 return [
//                                     'success' => false , 
//                                     'saveInConfig' => true,
//                                     'error' => 'Warehouse in the selected Template Disabled - Please choose an active warehouse for this product.'
//                                 ];
//                             }
//                         }
//                     }

//                     //for handling location error exception while type conversion
//                     if ($qtyChange == false && $qty == 0 && isset($product['quantity'])) {
//                         $qty = (int) $product['quantity'];
//                     }

//                     $availableQty = $qty;
//                     /*Customise Inventory and Fixed Inventory have same (lowest)priority and then Threshold and then delete product (highest)*/
//                     if ($invTemplateData['settings_selected']['customize_inventory']) {
//                         $qty = $this->changeInv((int) $availableQty, 'value_decrease', $invTemplateData['customize_inventory']['value']);
//                     } elseif ($invTemplateData['settings_selected']['fixed_inventory']) {
//                         $qty = $this->changeInv((int) $availableQty, 'set_fixed_inventory', $invTemplateData['fixed_inventory']);
//                     } elseif ($invTemplateData['settings_selected']['delete_out_of_stock'] && (int) $availableQty == 0) {
//                         return [
//                             'SKU' => $sku,
//                             'delete_out_of_stock' => true,
//                             'Quantity' => $qty,
//                             'Latency' => $latency
//                         ];
//                     }

//                     if ($invTemplateData['settings_selected']['threshold_inventory']) {
//                         if ((int) $invTemplateData['threshold_inventory'] >= (int) $availableQty) {
//                             $qty = 0;
//                         }
//                     }
//                 }
//             }

//             return [
//                 'SKU' => $sku,
//                 'Quantity' => $qty,
//                 'Latency' => $latency
//             ];

//         }
//         return [];
//     }

//     public function changeInv($qty, $attribute, $changeValue)
//     {
//         switch ($attribute) {
//             case 'value_increase':
//                 $qty = $qty + $changeValue;
//                 break;
//             case 'value_decrease':
//                 $qty = $qty - $changeValue;
//                 break;
//             case 'percentage_increase':
//                 $qty = $qty + (($changeValue / 100) * $qty);
//                 break;
//             case 'percentage_decrease':
//                 $qty = $qty - (($changeValue / 100) * $qty);
//                 break;
//             case 'set_fixed_inventory':
//                 $qty = $changeValue;
//                 break;
//             default:
//                 break;
//         }

//         if ((int) $qty < 0) {
//             $qty = 0;
//         }

//         return ceil($qty);
//     }
// }