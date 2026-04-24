<?php

namespace App\Amazon\Components;

use Exception;
use App\Connector\Models\Product\Edit;
use MongoDB\BSON\UTCDateTime;
use App\Core\Components\Base as Base;
use App\Amazon\Components\Common\Helper;

#[\AllowDynamicProperties]
class LocalDelivery extends Base
{
    public const IS_EQUAL_TO = 1;

    public const IS_NOT_EQUAL_TO = 2;

    public const IS_CONTAINS = 3;

    public const IS_NOT_CONTAINS = 4;

    public const START_FROM = 5;

    public const END_FROM = 6;

    public const RANGE = 7;

    public const CHECK_KEY_EXISTS = 8;

    public const GREATER_THAN = 9;

    public const LESS_THAN = 10;

    public function shippingTemplateCreationDate(){
        $date=date_create();
        return date_format($date,"Y-m-d\TH:i:s\Z");
    }

    public function IsoDateFormat($date){
        $isoDate  =  date('Y-m-d\TH:i:s\Z', $date - 3600);
        return $isoDate;
    }

    public function getAllShippingTemplateFromAmazon($data){
        try{
            if (isset($data['target']['shop_id']) && !empty($data['target']['shop_id']) && isset($data['source']['shop_id'])) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $targetShopId = $data['target']['shop_id'];
                $targetMarketplace = $data['target']['marketplace'];
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $targetShop = $user_details->getShop($targetShopId, $userId);
                $params['shop_id'] = $targetShop['remote_shop_id'];
                $params['productType'] = $data['productType'];

                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                $response = $commonHelper->sendRequestToAmazon('ProductType-Definitions', $params, 'GET');
                $link = $response['response']['schema']['link']['resource'];
                $curl = curl_init();

                curl_setopt_array($curl, [CURLOPT_URL => $link, CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '', CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 0, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_CUSTOMREQUEST => 'GET']);

                $response2 = curl_exec($curl);

                curl_close($curl);

                $merchant_shipping_group_name = json_decode($response2, true);

                $shippingTemplateIds = $merchant_shipping_group_name['properties']['merchant_shipping_group']['items']['properties']['value']['enum'];
                $shippingTemplateNames = $merchant_shipping_group_name['properties']['merchant_shipping_group']['items']['properties']['value']['enumNames'];
                $shippingTemplates = array_combine($shippingTemplateNames, $shippingTemplateIds);
                $res = $this->createAndUpdateShippingTemplate($data, $shippingTemplates);
                return $res;


            }
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createAndUpdateShippingTemplate($data, $shippingTemplates){
        try{
            // print_r($shippingTemplates);die;
            $response= $this->getAllShippingTemplateFromDB($data);
            $savedShippingTemplates = array_column($response['data'],'merchant_shipping_group_name', 'merchant_shipping_group');
            // print_r($savedShippingTemplate);die;
            foreach($shippingTemplates as $shippingTemplateName => $shippingTemplateId){
                if(!array_key_exists($shippingTemplateId, $savedShippingTemplates)){
                    $data['merchant_shipping_group_name'] = $shippingTemplateName;
                    $data['merchant_shipping_group'] = $shippingTemplateId;
                    $key = 'create';
                    $this->createShippingTemplate($data, $key);
                    // print_r($shippingTemplateId);
                }
               else{
                if(strcmp((string) $savedShippingTemplates[$shippingTemplateId], (string) $shippingTemplateName) != 0){
                    $data['merchant_shipping_group_name'] = $savedShippingTemplates[$shippingTemplateId];
                    $data['new_merchant_shipping_group_name'] = $shippingTemplateName;
                    $data['merchant_shipping_group'] = $shippingTemplateId;
                    $key = 'update';
                    $this->updateShippingTemplate($data, $key);
                    // print_r($savedShippingTemplates[$shippingTemplateId]);

                }
               }

            }

            return ['success'=>true, 'message'=>'shipping-template has been fetched and updated successfully'];

        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }




    public function createShippingTemplate($data, $key = false){
        try{
            if(isset($data['target']['shop_id']) && $data['target']['shop_id'] != ""){
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
                $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
                $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
                $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
                if(isset($data['merchant_shipping_group_name']) && $data['merchant_shipping_group_name'] != ""){
                    $merchantShippingGroupName = $data['merchant_shipping_group_name'];
                    $id = $this->idForShippingTemplate($merchantShippingGroupName);
                }else{
                    return ['success' => false, 'message' => 'Shipping-Template not found'];
                }

                if(isset($data['merchant_shipping_group']) && $data['merchant_shipping_group'] != ""){
                    $shippingTemplateId = $data['merchant_shipping_group'];

                }else{
                    return ['success' => false, 'message' => 'Shipping-Template-Id not found'];
                }

                $params = ['user_id'=>$userId,
                            'target'=>['shop_id'=>$targetShopId,'marketplace'=>$targetMarketplace],
                            'source'=>['shop_id'=>$sourceShopId,'marketplace'=>$sourceMarketplace],
                            'merchant_shipping_group_name'=>$merchantShippingGroupName, 
                            'merchant_shipping_group' =>$shippingTemplateId,
                            'Shipping_template_id' => $id
                        ];
                $response = $this->sendToShippingTemplate($params, $key);
                return $response;

            }
            return ['success' => false, 'message' => 'target shop Id not found'];


        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function idForShippingTemplate($data){
        try{
            $Id = hash('crc32b', (string) $data);
            return $Id.'-LD';
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateShippingTemplate($data, $key = false){
        try{
            if((isset($data['target']['shop_id']) && $data['target']['shop_id'] != "") && (isset($data['new_merchant_shipping_group_name']) && $data['new_merchant_shipping_group_name'] != "")){
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
                $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
                $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
                $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
                $newMerchantShippingGroupName = $data['new_merchant_shipping_group_name'];
                if(isset($data['merchant_shipping_group_name']) && $data['merchant_shipping_group_name'] != ""){
                    $merchantShippingGroupName = $data['merchant_shipping_group_name'];
                }
                else{
                    return ['success' => false, 'message' => 'Shipping-Template not found'];
                }

                if(isset($data['merchant_shipping_group']) && $data['merchant_shipping_group'] != ""){
                    $merchantShippingGroup = $data['merchant_shipping_group'];
                }
                else{
                    return ['success' => false, 'message' => 'Shipping-Template-Id not found'];
                }

                if($newMerchantShippingGroupName == $merchantShippingGroupName){
                    return ['success' => false, 'message' => 'Change Shipping-Template-Name'];
                }

                $id = $this->idForShippingTemplate($merchantShippingGroupName);

                $params = ['user_id'=>$userId,
                            'target'=>['shop_id'=>$targetShopId,'marketplace'=>$targetMarketplace],
                            'source'=>['shop_id'=>$sourceShopId,'marketplace'=>$sourceMarketplace],
                            'merchant_shipping_group_name'=>$merchantShippingGroupName,
                            'merchant_shipping_group'=>$merchantShippingGroup,
                            'new_merchant_shipping_group_name'=>$newMerchantShippingGroupName,
                            'Shipping_template_id'=> $id
                        ];
                $response = $this->sendToShippingTemplate($params, $key);
                return $response;

            }
            return ['success' => false, 'message' => 'target shop Id not found'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteShippingTemplate($data, $key = false){
        try{
            if(isset($data['target']['shop_id']) && $data['target']['shop_id'] != ""){
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
                $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
                $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
                $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();

                if(isset($data['merchant_shipping_group_name']) && $data['merchant_shipping_group_name'] != ""){
                    $merchantShippingGroupName = $data['merchant_shipping_group_name'];
                }else{
                    return ['success' => false, 'message' => 'Shipping-Template not found'];
                }

                $params = ['user_id'=>$userId,
                            'target'=>['shop_id'=>$targetShopId,'marketplace'=>$targetMarketplace],
                            'source'=>['shop_id'=>$sourceShopId,'marketplace'=>$sourceMarketplace],
                            'merchant_shipping_group_name'=>$merchantShippingGroupName,
                        ];
                $response = $this->sendToShippingTemplate($params, $key);
                return $response;

            }
            return ['success' => false, 'message' => 'target shop Id not found'];

        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function sendToShippingTemplate($data, $key= false){
        try{
        if(isset($data['merchant_shipping_group_name']) && $data['merchant_shipping_group_name'] != ""){
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery);
            $productContainerLD = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
            $refineContainerLD = $mongo->getCollectionForTable(Helper::Refine_Product_Local_Delivery);
            $createdAt = (string) $this->shippingTemplateCreationDate();
            $updatedAt = (string) $this->shippingTemplateCreationDate();

            $response = $collection->findOne(['user_id'=>$data['user_id'],'source.shop_id'=>$data['source']['shop_id'],
            'target.shop_id'=>$data['target']['shop_id'],'merchant_shipping_group_name'=>$data['merchant_shipping_group_name'],
            'merchant_shipping_group'=>$data['merchant_shipping_group'] ]);
            $response = json_decode(json_encode($response),true);

            switch ($key) {
                case "create":
                    if(!empty($response)){
                        return ['success' => false, 'message' => 'Shipping-Template already assigned'];
                    }
                    $data['created_at'] = $createdAt;
                    $data['updated_at'] = $updatedAt;
                    $res = $collection->insertOne($data);
                    $res = json_decode(json_encode($res),true);
                    return ['success' => true, 'message' => 'Shipping-Template is created'];

                  break;

                case "update":
                    if(!empty($response)){
                        $res = $collection->updateOne(['user_id'=>$data['user_id'],'source.shop_id'=>$data['source']['shop_id'],'target.shop_id'=>$data['target']['shop_id'],'merchant_shipping_group_name'=>$data['merchant_shipping_group_name'],'merchant_shipping_group'=>$data['merchant_shipping_group']],
                    ['$set' => ['merchant_shipping_group_name'=>$data['new_merchant_shipping_group_name'], 'Shipping_template_id' => $data['Shipping_template_id'], 'updated_at'=>$updatedAt]]);
                    $res = json_decode(json_encode($res),true);   

                    $updateInProducts = $productContainerLD->updateMany(['user_id'=>$data['user_id'], 'shop_id'=>$data['source']['shop_id'],'merchant_shipping_group_name'=>$data['merchant_shipping_group_name']],
                    ['$set' => ['merchant_shipping_group_name'=>$data['new_merchant_shipping_group_name']]]); 

                    $updateProdutsInRefine = $refineContainerLD->updateMany(['user_id'=>$data['user_id'],'source_shop_id'=>$data['source']['shop_id'],'target_shop_id'=>$data['target']['shop_id'],'items.merchant_shipping_group_name'=>$data['merchant_shipping_group_name']],
                    ['$set' => ['items.$.merchant_shipping_group_name'=>$data['new_merchant_shipping_group_name']]]);

                    return ['success' => true, 'message' => 'Shipping-Template is updated'];
                    }
                    return ['success' => false, 'message' => 'Shipping-Template is not created or already updated'];

                  break;

                case "delete":
                    if(!empty($response)){
                        $res = $collection->deleteOne(['user_id'=>$data['user_id'],'source.shop_id'=>$data['source']['shop_id'],
                        'target.shop_id'=>$data['target']['shop_id'],'merchant_shipping_group_name'=>$data['merchant_shipping_group_name']]);

                        $updateInProducts = $productContainerLD->updateMany(['user_id'=>$data['user_id'], 'shop_id'=>$data['source']['shop_id'],'merchant_shipping_group_name'=>$data['merchant_shipping_group_name']],
                        ['$set' => ['merchant_shipping_group_name'=>""]]);

                        $updateProdutsInRefine = $refineContainerLD->updateMany(['user_id'=>$data['user_id'],'source_shop_id'=>$data['source']['shop_id'],'target_shop_id'=>$data['target']['shop_id'],'items.merchant_shipping_group_name'=>$data['merchant_shipping_group_name']],
                    ['$set' => ['items.$.merchant_shipping_group_name'=>""]]);
                        return ['success' => true, 'message' => 'Shipping-Template is deleted'];
                    }
                    return ['success' => false, 'message' => 'Shipping-Template is not created or already deleted'];

                  break;

                default:
                return ['success' => false, 'message' => 'No operation found!!'];
              }
        }
    else{
        return ['success' => false, 'message' => 'Shipping-Template not found'];
    }

    }

    catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
 }

 public function createSkuForLocalDelivery($data){
    try{

        if(isset($data['target']['shop_id']) && $data['target']['shop_id'] != ""){
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();

            $sourceProductIds = $data['source_product_ids'];
            $key = $data['operation'];

            if(isset($data['merchant_shipping_group_name']) && $data['merchant_shipping_group_name'] != ""){
                $merchantShippingGroupName = $data['merchant_shipping_group_name'];
            }else{
                return ['success' => false, 'message' => 'Shipping-Template not found'];
            }            

            switch($key){      

                case "create":      
                    $response = $this->createShippingTemplate($data, $key);    
                break;      

                case "select":    
                    $params = [
                        "user_id"=>$userId,
                        "target.shop_id" => $targetShopId,
                        "target.marketplace"=>$targetMarketplace, 
                        "source.marketplace"=>$sourceMarketplace,
                        "source.shop_id"=>$sourceShopId,
                        "merchant_shipping_group_name"=>$merchantShippingGroupName
                    ];

                $option = [
                   'projection'=> ['_id' => 0,'Shipping_template_id'=>1],
                    'typeMap'   => ['root' => 'array', 'document' => 'array']
                ];

                    $result = $this->getSingleShippingTemplate($params, $option);  
                    if(!empty($result)){
                       $response =  ['success' => true, 'message' => 'shipping-Template found'];
                    }else{
                        $response =  ['success' => false, 'message' => 'shipping-Template is not found'];
                    }

                break;            
                default:      
                 return ['success' => false, 'message' => 'No operation found!!'];      
                }     

            if(isset($response['success']) && $response['success']){
                if(!empty($sourceProductIds)){
                    $res = $this->createNewSKU($data);
                    return $res;
                }
                return ['success' => false, 'message' => 'kindly select products'];
            }
            return $response;
        }
        return ['success' => false, 'message' => 'target shop Id not found'];
    }
    catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
 }

    public function createNewSKU($data)
    {
        try{
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
            $sourceProductIds = $data['source_product_ids'];
            $merchantShippingGroupName = $data['merchant_shipping_group_name'];
            $localDeliveryEdited = [
                'merchant_shipping_group_name' => $data['merchant_shipping_group_name'],
                'merchant_shipping_group' => $data['merchant_shipping_group']
            ];
            $bulkOpArray = [];
            $productFlag = false;

            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery);
            $LocalDeliverycollection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);

            if(!empty($sourceProductIds)){
                $paramsForConnector['count'] = 50;
                $paramsForConnector['activePage'] = 1;
                $paramsForConnector['filter'] = ['source_product_id' => [10 => $data['source_product_ids']]];
                $paramsForConnector['productOnly'] = true;

                $productProfile = $this->di->getObjectManager()->get('App\Connector\Models\ProductContainer');
                $productsData = $productProfile->getRefineProducts($paramsForConnector);
                $productsData = json_decode(json_encode($productsData), true);

                // $products = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace')->getproductbyQuery($params,$option);
                if(isset($productsData['success']) && $productsData['success'] && !empty($productsData['data']['rows']))
                {
                    $productFlag = true;
                    $products = $productsData['data']['rows'];
                    $parentSKU = false;
                    foreach($products as $product){
                        $item = [];
                        $editedData = [];
                        $newparams = [
                            "container_id" =>(string) $product['container_id'],
                            "source_product_id" =>(string) $product['source_product_id'],
                            "merchant_shipping_group" =>(string) $data['merchant_shipping_group'],
                            "merchant_shipping_group_name" =>(string) $data['merchant_shipping_group_name'],
                            "user_id"=> (string)$userId, 
                            "target_shop_id"=>(string)$product['target_shop_id'],
                            "source_shop_id"=>(string)$product['source_shop_id'],
                            "source_marketplace"=>(string)$sourceMarketplace,
                        ];

                        $condition = $newparams;
                        foreach ($product['items'] as $sourceProduct) {
                            $alreadySavedData = [];
                            $query = $condition;
                            $query['items.source_product_id'] = $sourceProduct['source_product_id'];

                            //check if source_product_id is already present or not
                            $alreadySavedData = $LocalDeliverycollection->findOne($query , ['projection' => ['items.$' => 1 , "_id" => 0] , "typeMap" => ['root' => 'array', 'document' => 'array']]);

                            $sku = $alreadySavedData['items'][0]['sku'] ?? false;
                            if(!$sku)
                            {
                                $sku = 'LD'.random_int(10,100).'_'.$sourceProduct['sku'];    
                                $item[] = [
                                    'sku' => $sku,
                                    'source_product_id' => $sourceProduct['source_product_id'],
                                    "merchant_shipping_group" =>(string) $data['merchant_shipping_group'],
                                    "merchant_shipping_group_name" =>(string) $data['merchant_shipping_group_name']
                                ];
                            }

                            if($sourceProduct['source_product_id'] == $product['container_id'])
                            {
                                $parentSKU = $sku;
                            }
                        }

                        $newparams['sku'] = $parentSKU;
                        if(!empty($item))
                        {
                            $bulkOpArray[] = [
                                'updateOne' => [
                                    (object) $condition,
                                    [
                                        '$set' => (object) $newparams,
                                        '$setOnInsert' => [
                                            'created_at' => date('c')
                                        ],
                                        '$push' =>  ['items' => ['$each' => $item]]
                                    ],
                                    ['upsert' => true]
                                ]
                            ];
                        }

                    }    
                }
                else
                {
                    return ['success' => false, 'message' => 'No data found'];
                }
            }
            else
            {
                return ['success' => false, 'message' => 'No Source Id found'];
            }

            if (!empty($bulkOpArray)) {
                $response = $LocalDeliverycollection->BulkWrite($bulkOpArray, ['w' => 1]);
                $nUpserted = $response->getUpsertedCount();
                $nModified = $response->getModifiedCount();
                if ($nUpserted) {
                    return ['success' => true, 'message' => 'Products Inserted. You can view products from Overview > Local Delivery > Products'];
                }
                if ($nModified) {
                    return ['success' => true, 'message' => 'Products already existed ,updated the selected products. You can view products from Overview > Local Delivery > Products'];
                }
                else
                {
                    return ['success' => false, 'message' => 'No product found'];
                }
            }
            if ($productFlag) {
                return ['success' => true, 'message' => 'Products already existed ,updated the selected products. You can view products from Overview > Local Delivery > Products'];
            }
            else
            {
                return ['success' => false, 'message' => 'No product found'];
            }
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

    }

 public function checkLengthOfSKU($data, $specifics){
    try{
        $userId = $specifics['user_id'] ?? $this->di->getUser()->id;
        $targetShopId = $specifics['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
        $targetMarketplace = $specifics['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
        $sourceMarketplace = $specifics['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
        $sourceShopId = $specifics['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
        $merchantShippingGroupName = $specifics['merchant_shipping_group_name'];
        $params = [
            "user_id"=>$userId,
            "target.shop_id" => $targetShopId,
            "target.marketplace"=>$targetMarketplace, 
            "source.marketplace"=>$sourceMarketplace,
            "source.shop_id"=>$sourceShopId,
            "merchant_shipping_group_name"=>$merchantShippingGroupName
        ];

        $option = [
           'projection'=> ['_id' => 0,'Shipping_template_id'=>1],
            'typeMap'   => ['root' => 'array', 'document' => 'array']
        ];
        $res = $this->getSingleShippingTemplate($params, $option);

        if(isset($data['sku']) && strlen($data['sku'] <= 28)){
            $newSku = $data['sku'].'_'.$res['Shipping_template_id'];

            $response = $this->checkSkuExistsForProduct($data, $newSku);
            if($response['success']){

                return $response['sku'];
            }
            return ['success' => false, 'message' => 'issue to get sku'];  
        }
        $sku = substr((string) $data['sku'], 0, 28);
        $newSku = $sku.'_'.$res['Shipping_template_id'];
        $response = $this->checkSkuExistsForProduct($data, $newSku);
        if($response['success']){
            return $response['sku'];
        }
        return ['success' => false, 'message' => 'issue to get sku'];
    }
    catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}


    public function createRefineProductForLocalDelivery($products, $targetShopId, $targetMarketplace){
        try{

            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Refine_Product_Local_Delivery);

            foreach($products as $product){
                 if($product['type'] == 'simple' && $product['visibility'] == 'Catalog and Search'){
                     $productType = 'simple';

                 }

                 if($product['type'] == 'simple' && $product['visibility'] == 'Not Visible Individually'){
                    $productType = 'child';
                 }

                 switch($productType){
                 //   case 'parent':
                 //     $res = $this->createRefineProductForParent($product, $targetShopId, $targetMarketplace);
                 //     return $res;
                 //     break;
                   case 'simple':
                     $res = $this->createRefineProductForSimple($product, $targetShopId, $targetMarketplace);

                     break;
                   case 'child':
                     $res = $this->createRefineProductForChild($product, $targetShopId, $targetMarketplace);

                     break;  
                 }
            }

            return true;

        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createRefineProductForParent($products, $targetShopId, $targetMarketplace){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Refine_Product_Local_Delivery);
            foreach($products as $product){

                $product['source_shop_id'] = $product['shop_id'];
                $product['target_shop_id'] = $targetShopId;
                $product['source_product_id'] = $product['container_id'];


                $product['items'][] = [
                    'shop_id' => $targetShopId,
                    'source_product_id' => $product['source_product_id'],
                    'merchant_shipping_group_name'=>$product['merchant_shipping_group_name'],
                    // 'source_product_id' => $product['source_product_id'],
                    'online_source_product_id' => $product['online_source_product_id'],
                    'target_marketplace' => $targetMarketplace,
                    'sku' => $product['sku'],
                    'title' => $product['title'],
                    'main_image' => $product['main_image']
                ];
                unset($product['shop_id'], $product['marketplace']);
                $res = $collection->insertOne($product);

            }

            return true;
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

    }

    public function createRefineProductForChild($product, $targetShopId, $targetMarketplace){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Refine_Product_Local_Delivery);

                // print_r($);
                $params = [
                    'container_id'=>$product['container_id'],
                    'source_product_id'=>$product['container_id'],
                    'source_shop_id'=>$product['shop_id'],
                    'target_shop_id'=>$targetShopId,
                    'merchant_shipping_group_name'=>$product['merchant_shipping_group_name']
                ];
                $option = [
                    '$projection'=>[
                    'id'=>0,
                    'items' =>1,
                    'merchant_shipping_group_name'=>1],
                    'typeMap'   => ['root' => 'array', 'document' => 'array']
                ];
                $foundProduct = $collection->find($params, $option)->toArray();

                if(count($foundProduct) > 0){
                    $setData = [
                        'shop_id' => $targetShopId,
                        'source_product_id' => $product['source_product_id'],
                        'merchant_shipping_group_name'=>$product['merchant_shipping_group_name'],
                        'target_marketplace' => $targetMarketplace,
                        // 'source_product_id' => $product['source_product_id'],
                        'online_source_product_id' => $product['online_source_product_id'],
                        'sku' => $product['sku'],
                        'title' => $product['title'],
                        'main_image' => $product['main_image'],
                        'price'=> $product['price']
                    ];
                $updateProduct = $collection->updateOne($params, ['$push' =>['items'=>$setData]]);
                }
                else{

                    $product['source_shop_id'] = $product['shop_id'];
                    $product['target_shop_id'] = $targetShopId;
                    // $product['online_container_id'] = $product['ld_source_product_id'];
                    $product['visibility'] = 'Catalog and Search';

                    $product['items'][] = [
                        'shop_id' => $targetShopId,
                        'source_product_id' => $product['source_product_id'],
                        'merchant_shipping_group_name'=>$product['merchant_shipping_group_name'],
                        'target_marketplace' => $targetMarketplace,
                        'online_source_product_id' => $product['online_source_product_id'],
                        'sku' => $product['sku'],
                        'title' => $product['title'],
                        'main_image' => $product['main_image'],
                        'price' => $product['price']
                    ];
                    unset($product['shop_id'], $product['marketplace']);
                    $res = $collection->insertOne($product);

                return $res;
            }


        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

    }

    public function createRefineProductForSimple($product, $targetShopId, $targetMarketplace){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Refine_Product_Local_Delivery);
            $product['source_shop_id'] = $product['shop_id'];
                $product['target_shop_id'] = $targetShopId;

                $product['items'][] = [
                    'shop_id' => $targetShopId,
                    'source_product_id' => $product['source_product_id'],
                    'merchant_shipping_group_name'=>$product['merchant_shipping_group_name'],
                    'target_marketplace' => $targetMarketplace,
                    'sku' => $product['sku'],
                    'title' => $product['title'],
                    'online_source_product_id' => $product['online_source_product_id'],
                    'main_image' => $product['main_image'],
                    'price' => $product['price']
                ];
                unset($product['shop_id'], $product['marketplace']);
                $res = $collection->insertOne($product);
                return $res;

        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getSingleShippingTemplate($query, $option){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery);
            $result = $collection->findOne($query, $option);
            return $result;
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateSingleShippingTemplate($query, $setData){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery);
            $createdAt = (string) $this->shippingTemplateCreationDate();
            $updatedAt = (string) $this->shippingTemplateCreationDate();

            $setData['created_at'] = $createdAt;
            $setData['updated_at'] = $updatedAt;

            $result = $collection->updateOne($query, ['$set'=>$setData]);
            return $result;
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function checkSkuExistsForProduct($data, $specifics){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
            // print_r($data);die;
            $params = [
                "shop_id"=>$data['shop_id'],
                "online_source_product_id" => $data['source_product_id'],
                "user_id"=>$data['user_id'], 
                "source_marketplace"=>$data['source_marketplace'],
                "sku"=>$specifics,
            ];
            $result = $collection->find($params)->toArray();
            $result = json_decode(json_encode($result),true);
            // print_r($result);die;

            if(!empty($result)){
                $sku = substr((string) $specifics, 5);
                $newsku = 'LS'.random_int(10,100).'_'.$sku;
                $res = $this->checkSkuExistsForProduct($params, $newsku);
                if($res['success']){
                    return ['success'=>true, 'sku'=>$newsku];  
                }
            }
            else{
                return ['success'=>true, 'message'=>'Product already exist', 'sku'=>$specifics];
            }
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getAllShippingTemplateFromDB($data){
        try{
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery);

            $params = [
                "user_id"=>$userId,
                "target.shop_id" => $targetShopId,
                "target.marketplace"=>$targetMarketplace, 
                "source.marketplace"=>$sourceMarketplace,
                "source.shop_id"=>$sourceShopId,
                "merchant_shipping_group_name"=>['$exists'=>1]
            ];

            if(isset($data['search']) && $data['search'] != ""){
                $params['merchant_shipping_group_name']=['$regex'=>$data['search'], '$options'=>'i'];
            }

        // $option = [
        //    'projection'=> ['_id' => 0,'merchant_shipping_group_name'=>1,'merchant_shipping_group'=>1, 'created_at'=>1, 'updated_at'=>1],
        //     'typeMap'   => ['root' => 'array', 'document' => 'array']
        // ];
            $totalShippingTemplates = $collection->count($params);
            // $totalShippingTemplates = count($result);
            $limit = $data['count'] ?? 20;
            if (!isset($data['activePage'])) {
                $data['activePage'] = 1;
            }

            $page = $data['activePage'];
            $offset = ($page - 1) * $limit;
            $templateParams = [];
            $templateParams[] = ['$match'=> $params];
            // $templateParams[] = ['$sort' => ['created_at'=> -1]];
            $templateParams[] = ['$project' => ['_id' => 0,'merchant_shipping_group_name'=>1,'merchant_shipping_group'=>1, 'created_at'=>1, 'updated_at'=>1]];
            if (isset($offset)) {
                $templateParams[] = ['$skip' => (int) $offset];
               }

        $templateParams[] = ['$limit' => (int)($limit)];
        // print_r($templateParams);die;

        $result= $collection->aggregate($templateParams, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
        // $result['templates'] = json_decode(json_encode($response['templates']),true);
        // $result['totalCount']= $totalShippingTemplates;
        // print_r($result);die;
        if($totalShippingTemplates == 0){
            return ['success'=>false, 'message'=>'No shipping-template is found', 'data'=>count($result['totalCount'])];
        }
        $response = $this->templateWiseProductCount($userId,$sourceShopId, $sourceMarketplace, $result);
        return  ['success'=>true, 'message'=>'All shipping-templates', 'data'=>$response, 'totalCount' =>$totalShippingTemplates];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function templateWiseProductCount($userId,$sourceShopId,$sourceMarketplace, $data){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
            $params = [
                'user_id'=>$userId,
                'source_shop_id'=>$sourceShopId,
                'source_marketplace'=>$sourceMarketplace,  
            ];
            $newArr = [];
            foreach($data as $template){

               $params['merchant_shipping_group_name']= $template['merchant_shipping_group_name'];
               $count = $collection->count($params);
               $template['product_count'] = $count; 
               $newArr[] = $template;

            }

            return $newArr;

        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getAllActiveWarehouses($data){
        try{
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();

            $shops = $this->di->getObjectManager()->get('App\Core\Models\User\Details')->findShop(['marketplace' => 'shopify'], $userId);

            $configHelper = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');
            $warehouseIds =  $configHelper->getConfigData($data);

            $warehouseIds = json_decode(json_encode($warehouseIds),true);

            foreach($shops as $data){
                $warehouses = array_column($data['warehouses'], 'name', 'id');
            }

            foreach($warehouseIds['data'] as $Id){
                $enabledWarehouses = $Id['value'];
            }

            foreach($enabledWarehouses as $Ids){
               if(array_key_exists("{$Ids}", $warehouses)){
                $Activewarehouses[$Ids]=$warehouses[$Ids];
               }
            }

            if(count($Activewarehouses) == 0){
                return ['success'=>false, 'message'=>'No ActiveWarehouses', 'data'=>count($Activewarehouses)];
            }
            return  ['success'=>true, 'message'=>'All ActiveWarehouses', 'data'=>$Activewarehouses];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function mapLocationWithShippingTemplate($data){
        try{
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
            if(isset($data['mapped_locations']) && !empty($data['mapped_locations'])){
                foreach($data['mapped_locations'] as $shippingTemplate => $location){  

                    $params = [
                    "user_id"=>$userId,
                    "target.shop_id" => $targetShopId,
                    "target.marketplace"=>$targetMarketplace, 
                    "source.marketplace"=>$sourceMarketplace,
                    "source.shop_id"=>$sourceShopId,
                    "Shipping_template_id"=>$shippingTemplate
                     ];

                     $setData = [
                        'location_id'=>$location['location_id'], 
                        'location_name' => $location['location_name']
                    ];

                    $result = $this->updateSingleShippingTemplate($params, $setData);

                }

                return true;
            }
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }


    public function getAllActiveLocation($data){
        try{

            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
            if(isset($data['source_product_id']) && $data['source_product_id'] != ""){
                 $params = [
                    "shop_id"=>$sourceShopId,
                    "source_product_id" => $data['source_product_id'],
                    "user_id"=>$userId, 
                    "source_marketplace"=>$sourceMarketplace,
                    "container_id" => $data['container_id']
                ];
                $option = [
                    'projection'=> ['_id' => 0,'locations.location_id'=>1, 'locations.available'=>1],
                    'typeMap'   => ['root' => 'array', 'document' => 'array']
                ];

                $productLocations = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace')->getproductbyQuery($params, $option);
                if(count($productLocations[0]['locations']) > 0){

                    $location_Ids = array_column($productLocations[0]['locations'],'available','location_id');
                }
                else{
                    return  ['success'=>false, 'message'=>'No location availble from shopify for this product', 'data'=>count($location_Ids)];
                }



                $shops = $this->di->getObjectManager()->get('App\Core\Models\User\Details')->findShop(['marketplace' => $sourceMarketplace], $userId);


                foreach($shops as $data){
                    foreach($data['warehouses'] as  $boolean){
                        if($boolean['active'] && array_key_exists($boolean['id'], $location_Ids)){
                            $activeLocations[] = ["location_name" => $boolean['name'],
                            "location_id" => $boolean['id'],"inventory"=> $location_Ids[$boolean['id']]];
                        }
                    }
                }

                if($activeLocations !== []){

                    return  ['success'=>true, 'message'=>'All Active Locations', 'data'=>[$activeLocations]];
                }
                return  ['success'=>false, 'message'=>'No active location availble from shopify', 'data'=>count($activeLocations)];

            }

            return  ['success'=>false, 'message'=>'Source_product_id not found', 'data'=> null];

        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

  public function linkLocationWithProduct($data){
    try{
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
        $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
        $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
        $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
        if(isset($data['source_product_id']) && $data['source_product_id'] != ""){
            if(isset($data['map_location']) && !empty($data['map_location'])){

                foreach($data['map_location'] as $location_name =>$location){
                    foreach($location as $location_id => $inventory){

                        $params = [
                            "user_id"=>$userId, 
                            "source_marketplace"=>$sourceMarketplace,
                            "shop_id"=>$sourceShopId,
                            "sku"=>$data['sku'],
                            "merchant_shipping_group_name"=>$data['merchant_shipping_group_name']
                        ];

                        $setData = [
                           'quantity'=>$inventory, 
                           'map_location_name' =>$location_name,
                           'map_location_id' => (string)$location_id
                        ];

                            $result = $this->singleProductForLocalDelivery($params, $setData);
                    }
                }

                return  ['success'=>true, 'message'=>'location is map with shipping-template'];
            }
            return  ['success'=>false, 'message'=>'location is not found for maping', 'data'=> null];

        }
    }
    catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
  }

  public function getproductbyQueryLocalDelivery($query, $option){
    try{
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_mongo = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
        print_r($query);
        $productData = $this->_mongo->find($query, $option)->toArray();
        print_r($productData);die;

    }
    catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }

  }

  public function getChildSkuForLocalDelivery($userId, $sourceShopId, $sku){
    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_mongo = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
        $query = [
            'user_id' => $userId, 'shop_id' => $sourceShopId, 'sku' => $sku
        ];
        $options = ['projection'=> ['_id' => 0,'container_id'=>1, 'type'=>1],
                        'typeMap'   => ['root' => 'array', 'document' => 'array']];
        $productData = $this->_mongo->find($query, $options)->toArray();

        // foreach($productData as $id => $value){
            if($productData[0]['type']=='variation'){
                $query = ['container_id'=>$productData[0]['container_id'],'shop_id' =>$sourceShopId,'type'=>$productData[0]['type'], 'user_id'=>$userId];
                $options = ['projection'=> ['_id' => 0,'sku'=>1],
                'typeMap'   => ['root' => 'array', 'document' => 'array']];

                $productData = $this->getproductbyQueryLocalDelivery($query, $options);
            // }
        }

        return $productData;

  }

  public function getSingleProduct($data)
    {
        // $this->init($data);
        $this->_mongo = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);

        $this->_userId = $data['user_id'] ?? $this->di->getUser()->id;
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

    public function singleProductForLocalDelivery($params, $setData){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
            $createdAt = (string) $this->shippingTemplateCreationDate();
            $updatedAt = (string) $this->shippingTemplateCreationDate();

            $setData['created_at'] = $createdAt;
            $setData['updated_at'] = $updatedAt;


            $result = $collection->updateOne($params, ['$set'=>$setData]);
            return $result;

        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

    }

    public function updateShippingTemplateForProduct($data){
        try{

            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
            if(isset($data['sku']) && $data['sku'] != null){
                $sku = $data['sku'];
            }else{
                return ['success' => false, 'message' => 'SKU not found!!'];
            }

            if(isset($data['merchant_shipping_group_name']) && $data['merchant_shipping_group_name'] != ""){
                $merchantShippingGroupName = $data['merchant_shipping_group_name'];
            }
            else{
                return ['success' => false, 'message' => 'Shipping-Template not found'];
            }

            if(isset($data['source_product_id'], $data['container_id']) && $data['source_product_id'] != "" && $data['container_id']){
                $sourceProductId = $data['source_product_id'];
                $containerId = $data['container_id'];
            }else{
                return ['success' => false, 'message' => 'source_product_id or container id not found'];
            }

            $params = ['user_id'=>$userId,
            'container_id' => $containerId,
            'shop_id' => $sourceShopId,
            'source_product_id'=> $sourceProductId,
            'sku'=>$sku,
            'source_marketplace' =>$sourceMarketplace];
            $setData = [
                'merchant_shipping_group_name' => $merchantShippingGroupName
            ];
            $UpdateInProductContainer = $this->updateSingleProductForLocalDelivery($params, $setData);
            $uspdateInRefineContainer = $this->updateRefineProductForLocalDelivery($params, $setData);

            return ['success'=>true, 'message' =>'Shipping-template updated successfully.'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateSingleProductForLocalDelivery($params, $setData){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
            $updatedAt = (string) $this->shippingTemplateCreationDate();

            $setData['updated_at'] = $updatedAt;
            $result = $collection->updateOne($params,['$set' => $setData]);
            return $result;
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getProductsForLocalDelivery($data){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $params = [
                'target_marketplace' => $targetMarketplace,
                'source_marketplace' => $sourceMarketplace,
                'sourceShopID' => $sourceShopId,
                'targetShopID' => $targetShopId
            ];
            if(isset($data['source_product_id']) && $data['source_product_id'] != NULL && ($data['source_product_id'] != $data['container_id'])){
               $params['source_product_id'] = $data['source_product_id'];
               $sourceProductLd = $data['source_product_id'];
               $containerId = $data['container_id'];
            }
            else{
               $params['container_id'] = $data['container_id'] ?? "";
               $sourceProductLd = $data['container_id'];
               $containerId = $sourceProductLd;
            }

            $query = [
                'source_marketplace' => $sourceMarketplace,
                'source_shop_id' => $sourceShopId,
                'target_shop_id' => $targetShopId,
                'user_id' => $userId,
                'merchant_shipping_group_name' => $data['merchant_shipping_group_name']
            ];
            $products = new Edit;
            $productsData = $products->getProduct($params);
            $productsData = json_decode(json_encode($productsData) , true);
            foreach ($productsData['data']['rows'] as $key => $product) {
                $itemProd = [];
                $query['items.source_product_id'] = $product['source_product_id'];
                $alreadySavedData = $collection->findOne($query , ['projection' => ['items.$' => 1 , "_id" => 0] , "typeMap" => ['root' => 'array', 'document' => 'array']]);
                $alreadySavedData = json_decode(json_encode($alreadySavedData) , true);
                $item = $alreadySavedData['items'][0] ?? [];
                $editedUpdate = [];
                if(isset($item['price']) && $item['price'] != "")
                {
                    $editedUpdate['price'] = $item['price'];
                }

                if(isset($item['map_location_id']) && !empty($item['map_location_id']))
                {
                    $editedUpdate['map_location_id'] = $item['map_location_id'];
                }

                if($product['container_id'] == $containerId)
                {
                    if(!empty($editedUpdate))
                    {
                        unset($product['edited']);
                        $product['edited'] = $editedUpdate;
                    }

                    $itemProd = array_merge($product , $item);
                    $productsData['data']['rows'][$key] = $itemProd;
                }
            }

            return $productsData;
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }


    public function saveProductForLocalDelivery($data){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $unsetPrice = false;
            $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
            if(isset($data['source_product_id']) && $data['source_product_id'] != null){
                $sku = $data['sku'];
            }else{
                return ['success' => false, 'message' => 'SKU not found!!'];
            }

            if(isset($data['merchant_shipping_group_name']) && $data['merchant_shipping_group_name'] != ""){
                $merchantShippingGroupName = $data['merchant_shipping_group_name'];
            }
            else{
                return ['success' => false, 'message' => 'Shipping-Template not found'];
            }

            $localDelivery = [];
            if(isset($data['map_location_id']) && !empty($data['map_location']))
            {  
                $localDelivery['map_location_id'] = $data['map_location_id'];  
            }

            if(isset($data['price_setting']) && $data['price_setting'] == 'default')
            {
                $unsetPrice = true;
            }
            elseif(isset($data['price']))
            {
                if($data['price'] != "")
                {
                    $localDelivery['price'] = (float)$data['price'];
                }
            }

            $params = [
                'user_id'=>$userId,
                'container_id' => $data['container_id'],
                'source_shop_id' => $sourceShopId,
                'target_shop_id' => $targetShopId,
                'sku'=>$sku,
                'source_marketplace' =>$sourceMarketplace,
                'merchant_shipping_group_name' => $merchantShippingGroupName
            ];
            $productData = $this->getProductBySKU($params);
            if($productData)
            {
                $item = $productData[0]['items'][0] ?? [];
                if(!empty($item))
                {
                    $this->updateLocalDeliveryData($item , $localDelivery , $unsetPrice);
                }
            }

            return ['success'=>true, 'message' =>'Shipping-template updated successfully.'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateLocalDeliveryData($filter , $update , $unsetPrice = false)
    {
        $unsetArr = [];
        foreach ($filter as $key => $value) {
            # code...
            if($key == "price" || $key == "map_location_id")
            {
                unset($filter[$key]);
                continue;
            }

            $filter['items.'.$key] = $value;
            unset($filter[$key]);
        }

        foreach ($update as $k => $val) {
            # code...
            $update['items.$.'.$k] = $val;
            unset($update[$k]);
        }

        if(!empty($update))
        {
            $update = ['$set' => $update];
        }

        if($unsetPrice)
        {
            $unsetArr = ['$unset' => ['items.$.price' => true]];
        }

        $update = array_merge($update , $unsetArr);
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
        $result = $collection->updateOne($filter , $update , ['upsert' => true]);
        return true;
    }

    public function getProductBySKU($params , $prod = false)
    {
        $sku = false;
        if(isset($params['sku']))
        {
            $sku = $params['sku'];
            unset($params['sku']);
        }
        elseif(!$prod)
        {
            return false;
        }

        $query = $params;
        if($sku)
        {
            $query['items.sku'] = $sku;
        }

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
        $product = $collection->find($query , ['projection'=> ['_id' => 0, "items.$"=>1],
        'typeMap'   => ['root' => 'array', 'document' => 'array']]);
        if(!empty($product))
        {
            $product = $product->toArray();
            return $product;
        }

        return false;
    }

    public function getRefineProductsForLD($data)
    {
        try
        {
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
            $count = 50;
            $matchParams = [];
            $matchParamsFilter = [];

            if (isset($data['filter'])) {
                foreach ($data['filter'] as $key => $value) {
                    $key = trim((string) $key);
                    // if ($key ==='cif_amazon_multi_activity' && array_key_exists(self::CHECK_KEY_EXISTS, $value)) {
                    //     $matchParams['items.'.$value[self::CHECK_KEY_EXISTS]] = ['$exists'  => true];
                    // }
                    // if ($key ==='cif_amazon_multi_inactive' && array_key_exists(self::CHECK_KEY_EXISTS, $value)) {
                    //     $matchParams['items.'.$value[self::CHECK_KEY_EXISTS]] = ['$exists'  => false];
                    //     print_r(self::CHECK_KEY_EXISTS);
                    // }


                    if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                        $matchParamsFilter[$key] = self::checkInteger($key, $value[self::IS_EQUAL_TO]);
                    } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                        $matchParamsFilter[$key] =  ['$ne' => self::checkInteger($key, trim((string) $value[self::IS_NOT_EQUAL_TO]))];
                    } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                        $matchParamsFilter[$key] = [
                            '$regex' =>  self::checkInteger($key, trim(addslashes((string) $value[self::IS_CONTAINS]))),
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                        $matchParamsFilter[$key] = [
                            '$regex' => "^((?!" . self::checkInteger($key, trim(addslashes((string) $value[self::IS_NOT_CONTAINS]))) . ").)*$",
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::GREATER_THAN, $value)) {
                        $matchParamsFilter[$key] =  ['$gte' => self::checkInteger($key, trim((string) $value[self::GREATER_THAN]))];
                    } elseif (array_key_exists(self::LESS_THAN, $value)) {
                        $matchParamsFilter[$key] =  ['$lte' => self::checkInteger($key, trim((string) $value[self::LESS_THAN]))];
                    }
                    elseif (array_key_exists(self::START_FROM, $value)) {
                        $matchParamsFilter[$key] = [
                            '$regex' => "^" . self::checkInteger($key, trim(addslashes((string) $value[self::START_FROM]))),
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::END_FROM, $value)) {
                        $matchParamsFilter[$key] = [
                            '$regex' => self::checkInteger($key, trim(addslashes((string) $value[self::END_FROM]))) . "$",
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::RANGE, $value)) {
                        if ($key == 'shops.1.created_at' || $key == 'created_at') {
                            if ($key == 'shops.1.created_at') {
                                $matchParamsFilter[$key] =  [
                                    '$gte' => $value[self::RANGE]['from'],
                                    '$lte' => $value[self::RANGE]['to']
                                ];
                            } else {
                                $matchParamsFilter[$key] =  [
                                    '$gte' => new UTCDateTime(strtotime((string) $value[self::RANGE]['from']) * 1000),
                                    '$lte' => new UTCDateTime((strtotime((string) $value[self::RANGE]['to']) + 86399) * 1000)
                                ];
                            }
                        } else {
                            if (trim((string) $value[self::RANGE]['from']) && !trim((string) $value[self::RANGE]['to'])) {
                                $matchParamsFilter[$key] =  ['$gte' => self::checkInteger($key, trim((string) $value[self::RANGE]['from']))];
                            } elseif (
                                trim((string) $value[self::RANGE]['to']) &&
                                !trim((string) $value[self::RANGE]['from'])
                            ) {
                                $matchParamsFilter[$key] =  ['$lte' => self::checkInteger($key, trim((string) $value[self::RANGE]['to']))];
                            } else {
                                $matchParamsFilter[$key] =  [
                                    '$gte' => self::checkInteger($key, trim((string) $value[self::RANGE]['from'])),
                                    '$lte' => self::checkInteger($key, trim((string) $value[self::RANGE]['to']))
                                ];
                            }
                        }
                    } 
                    elseif ($key === 'cif_amazon_multi_activity' && array_key_exists(self::CHECK_KEY_EXISTS, $value)) {
                        $matchParamsFilter['items.'.$value[self::CHECK_KEY_EXISTS]] = ['$exists'  => true];
                    }
                    elseif ($key === 'cif_amazon_multi_inactive' && array_key_exists(self::CHECK_KEY_EXISTS, $value)) {
                        $matchParamsFilter['items.status'] = ['$exists'  => false];
                    }
                }
            }

            if(isset($data['activePage']))
            {
                $activePage = (int) $data['activePage'];
            }
            else
            {
                $activePage = 1;
            }

            if(isset($data['count']))
            {
                $count = (int)$data['count'];
            }

            $skip = ($activePage - 1 ) * $count;

            $matchParams = [
                'user_id'=>$userId,
                'source_shop_id'=>$sourceShopId,
                'target_shop_id' => $targetShopId,
                'source_marketplace'=> $sourceMarketplace
            ];

            if(!empty($matchParamsFilter))
            {
                $matchParams = array_merge($matchParams , $matchParamsFilter);
            }

            $ldProducts = $collection->find($matchParams , ['limit' => $count, 'skip' => $skip, "typeMap" => ['root' => 'array', 'document' => 'array']]);
            if(!empty($ldProducts))
            {
                $products = $ldProducts->toArray();
                $products = json_decode(json_encode($products),true);

                $sourceProductIds = array_column($products , 'source_product_id');
                $data['filter'] = ['source_product_id' => [10 => $sourceProductIds]];
                $productProfile = $this->di->getObjectManager()->get('App\Connector\Models\ProductContainer');
                $productsData = $productProfile->getRefineProducts($data);
                $productsData = json_decode(json_encode($productsData), true);
                if(isset($productsData['success']) && $productsData['success'] && !empty($productsData['data']['rows']))
                {
                    $moldLdProducts = [];
                    foreach ($products as $key => $ldProduct) {
                        $moldLdProducts[$ldProduct['container_id']] = $ldProduct;
                    }

                    $productsRefine = $productsData['data']['rows'];
                    $finalData = [];
                    $keysToExclude = ['process_tags', 'error', 'status'];
                    foreach ($productsRefine as $product) {
                        $ldProd = $moldLdProducts[$product['container_id']] ?? [];
                        if($ldProd)
                        {
                            $countLd = count($ldProd['items']);
                            $countRp = count($product['items']);
                            if($countLd == $countRp)
                            {
                                $ldItem = $ldProd['items'];
                                $finalItem = [];
                                $result = [];
                                foreach ($product['items'] as $key => $item) {
                                    # code...
                                    $finalArr = array_diff_key($item, array_flip($keysToExclude));
                                    $result[] = array_merge($finalArr, $ldItem[$key]);
                                }

                                $product['items'] = $result;
                                $finalData[] = array_merge($ldProd,$product);
                            }
                        }
                    }

                    $productsData['data']['rows'] = $finalData;
                    return $productsData;
                }
                return ['success' => false, 'message' => 'No products found'];
            }
            $result = ['success' => true , 'message' => 'No products found'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getRefineProductsCountForLD($data){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Refine_Product_Local_Delivery);
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();
            $params = [
                'user_id'=>$userId,
                'source_shop_id'=>$sourceShopId,
                'target_shop_id' => $targetShopId,
                'source_marketplace'=> $sourceMarketplace
            ];
            $Inactiveparams =[ 'user_id'=>$userId,
            'source_shop_id'=>$sourceShopId,
            'target_shop_id' => $targetShopId,
            'source_marketplace'=> $sourceMarketplace, 'items.status' => 'inactive'];

            $Activeparams =[ 'user_id'=>$userId,
                'source_shop_id'=>$sourceShopId,
                'target_shop_id' => $targetShopId,
                'source_marketplace'=> $sourceMarketplace,
                'items.status' => 'active'];

            $Incomeleteparams =[ 'user_id'=>$userId,
            'source_shop_id'=>$sourceShopId,
            'target_shop_id' => $targetShopId,
            'source_marketplace'=> $sourceMarketplace,
            'items.status' => 'incomplete'];

            $Errorparams =[ 'user_id'=>$userId,
                'source_shop_id'=>$sourceShopId,
                'target_shop_id' => $targetShopId,
                'source_marketplace'=> $sourceMarketplace,
                'items.error'=>['$exists'=>true]];

            $countOfProducts = $collection->count($params);
            $countOfInactiveProducts = $collection->count($Inactiveparams);
            $countOfActiveProducts = $collection->count($Activeparams);
            $countOfIncompleteProducts = $collection->count($Incomeleteparams);
            $countOfError = $collection->count($Errorparams);
            $data = [
                'all'=>$countOfProducts,
                'inactive'=>$countOfInactiveProducts,
                'active' =>$countOfActiveProducts,
                'incomplete' =>$countOfIncompleteProducts,
                'error' =>$countOfError
            ];


            return ['success' => true, 'message' => 'count of all products from refine', 'count' => $data];


        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

    }

    public function updateRefineProductForLocalDelivery($params, $filters){
        try{


            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Refine_Product_Local_Delivery);
            $option = ['projection'=> ['_id' => 0, "visibility"=>1, 'type' =>1],
            'typeMap'   => ['root' => 'array', 'document' => 'array']];
            $res = $this->getproductbyQueryLocalDelivery($params,$option);
            $setData = [];
            if(isset($filters['price_setting'], $filters['price']) && $filters['price_setting'] != "" && $filters['price'] != ""){
                $setData['items.$.price_setting'] = $filters['price_setting'];
                $setData['items.$.price'] = $filters['price'];

            }

            if(isset($filters['map_location'], $filters['map_location_id']) && $filters['map_location'] != NULL && $filters['map_location_id'] != NULL){
                $setData['items.$.map_location'] = $filters['map_location'];
                $setData['items.$.map_location_id'] = $filters['map_location_id'];
            }

            if(isset($filters['merchant_shipping_group_name']) && $filters['merchant_shipping_group_name'] != ""){
                $setData['merchant_shipping_group_name'] = $filters['merchant_shipping_group_name'];
            }

            // print_r($setData);die;


            foreach($res as $productType){
                if($productType['type'] == 'simple' && $productType['visibility'] == 'Not Visible Individually'){

                    $refineParams = [
                        'user_id' => $params['user_id'],
                        'container_id'=>$params['container_id'],
                        'source_product_id' => $params['container_id'],
                        'source_shop_id' => $params['shop_id'],
                        'items.source_product_id'=>$params['source_product_id'],
                        'items.sku' => $params['sku'],
                        // 'items.merchant_shipping_group_name' => $merchantShippingGroupName
                    ];

                    $refineProduct = $collection->updateOne($refineParams, ['$set'=> $setData]);
                    return true;
                }
                $refineParams = [
                    'user_id' => $params['user_id'],
                    'container_id'=>$params['container_id'],
                    'source_shop_id' => $params['shop_id'],
                    'source_product_id' => $params['source_product_id'],
                    'items.source_product_id'=>$params['source_product_id'],
                    'items.sku' => $params['sku'],
                    // 'items.merchant_shipping_group_name' => $merchantShippingGroupName
                ];
                // $setData = [
                //     'merchant_shipping_group_name' => $merchantShippingGroupName,
                //     'items.$.merchant_shipping_group_name'=> $merchantShippingGroupName
                // ];
                // print_r($refineParams);die;
                $refineProduct = $collection->updateOne($refineParams, ['$set'=> $setData]);
                return true;
            }

        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function checkInteger($key, $value)
    {
        return trim((string) $value);
    }

    public function syncDetailsForLD($data){
        try{

            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery);
            $productCollection = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
            $productContainerLD = $mongo->getCollectionForTable(Helper::Local_Delivery_Product);
            $refineContainerLD = $mongo->getCollectionForTable(Helper::Refine_Product_Local_Delivery);
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target']['shopId'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shopId'] ?? $this->di->getRequester()->getSourceId();
            $params = [
                'user_id' => $userId,
                'shop_id' => $sourceShopId,
                'source_marketplace' => $sourceMarketplace
            ];
            $options = ['projection'=> [
                '_id' =>0, 'title' =>1, 'price'=>1, 'quantity' =>1, 'type' => 1, 'main_image' => 1
            ]];

            if(isset($data['ld_source_product_ids']) && count($data['ld_source_product_ids']) > 0){
                foreach($data['ld_source_product_ids'] as $sourceProductId => $ldSourceProductId){
                    $params['source_product_id'] = $ldSourceProductId;
                    $product = $productCollection->getproductbyQuery($params, $options);
                    $product = json_decode(json_encode($product),true);
                    if(isset($product[0]['type']) && $product[0]['type'] != 'variation'){
                        $updatedTitle = $product[0]['title'];
                        $updatedPrice = $product[0]['price'];
                        $updatedQuantity = $product[0]['quantity'];
                        $updatedImage = $product[0]['main_image'];
                        $updateInProducts = $productContainerLD->updateOne(['user_id'=>$userId, 'shop_id'=>$sourceShopId, 'source_product_id' => $sourceProductId],
                        ['$set' => ['title'=>$updatedTitle, 'price' => $updatedPrice, 'quantity' => $updatedQuantity, 'main_image' =>$updatedImage]]); 
                        if($product[0]['visibility'] ='Catalog and Search'){
                            $updateProdutsInRefine = $refineContainerLD->updateOne(['user_id'=>$userId,'source_shop_id'=>$sourceShopId,'target_shop_id'=>$targetShopId,'items.source_product_id'=>$sourceProductId],
                            ['$set' => ['items.$.title'=>$updatedTitle, 'items.$.price' =>$updatedPrice, 'items.$.quantity' =>$updatedQuantity, 'items.$.main_image' =>$updatedImage,
                                       'title' => $updatedTitle, 'price' =>$updatedPrice, 'quantity' =>$updatedQuantity, 'main_image' =>$updatedImage]]);     
                        }
                        else{
                            $updateProdutsInRefine = $refineContainerLD->updateOne(['user_id'=>$userId,'source_shop_id'=>$sourceShopId,'target_shop_id'=>$targetShopId,'items.source_product_id'=>$sourceProductId],
                            ['$set' => ['items.$.title'=>$updatedTitle, 'items.$.price' =>$updatedPrice, 'items.$.quantity' =>$updatedQuantity, 'items.$.main_image' =>$updatedImage]]);
                        }
                    }
                }
            }

            return ['success' => true, 'message'=>'Sync Details From online delivery products to local delivery product'];

        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function shippingTemplateCountLD($data){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Delivery);
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target']['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $sourceMarketplace = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
            $sourceShopId = $data['source']['shop_id'] ?? $this->di->getRequester()->getSourceId();

            $params = [
                "user_id"=>$userId,
                "target.shop_id" => $targetShopId,
                "target.marketplace"=>$targetMarketplace, 
                "source.marketplace"=>$sourceMarketplace,
                "source.shop_id"=>$sourceShopId,
                "merchant_shipping_group_name"=>['$exists'=>1]
            ];
            $countOfTemplates = $collection->count($params);

            return ['success'=>true, 'message' =>'total count of shipping-templates', 'count'=>$countOfTemplates];


        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    

    

}
