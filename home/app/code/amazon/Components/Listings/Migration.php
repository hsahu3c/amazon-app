<?php

namespace App\Amazon\Components\Listings;

use Exception;
use App\Core\Components\Base as Base;
use App\Core\Models\User;

use ZipArchive;

class Migration extends Base
{
    protected $mongo ;
    public const ProductContainer = 'product_container';
    public const PROFILE = 'profile';

   
    public function ProfileMigrate($data)
    {
      
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $filename = BP.DS.'var'.DS.'FlatFile-To-JSON-Mapping.xlsx';
        $Flat = $this->readXLSX($filename);
        $userId = $data['user_id']?? $this->di->getUser()->id;
        $targetShopId = $data['target']['shop_id'];
        $profileId = $data['profile_id'];
        $json = [];
        foreach($Flat as $ff)
        {
            if($ff[0] == "condition_type"){
                $json[$ff[0]]= $ff[2];
                
            }
            else{
                
                $json[$ff[0]]= $ff[1];
            }
        }
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $users = [];
        $AmazonProductType = $this->mongo->getCollectionForTable('amazon_product_type');

        $profileCollection = $this->mongo->getCollectionForTable(self::PROFILE);
        $pipline = [
            [
                '$match' => [
                    // '_id'=>new \MongoDB\BSON\ObjectId('63902d68f34d7bfce709f955'),
                    // 'user_id' => ['$exists' =>true],
                    'user_id' => $userId,
                    'type' => 'attribute',
                    'data.jsonFeed'=>['$exists' =>false],
                    'profile_id' =>new \MongoDB\BSON\ObjectId($profileId)
                    // 'data.jsonFeed'=>['$size'=>0],
                ]
            ]
        ];
        $profileDatas = $profileCollection->aggregate($pipline, $arrayParams)->toArray();
        // print_r($profileDatas);die;
        $counter =0;
        $count = 0;
        foreach($profileDatas as $profileData)
        {
            
            if(!empty($profileData['data']['required_attribute'])){
                $requiredAttribute = $profileData['data']['required_attribute'];
                $optionalAttribute = $profileData['data']['optional_attribute']??[];
                $attributes = array_merge($requiredAttribute,$optionalAttribute);
                $userId = $profileData['user_id'];
                $count ++;
                // print_r($count);
                $getUser = User::findFirst([['_id' => $userId]]);
                if(!empty($getUser)){

                    $getUser->id = (string)$getUser->_id;
                    $this->di->setUser($getUser);
                    
                    
                    $jsonFeed=$this->convertTojson($attributes,$json);
                    // print_r($jsonFeed);die;
                    if(!empty($jsonFeed)){
                        $data = $profileCollection->findOne(['_id'=>$profileData['profile_id'],'user_id'=>$profileData['user_id']], $arrayParams);
                        $primaryCategory = $data['category_id']['primary_category'];
                        $subcategory = $data['category_id']['sub_category'];
                        $displayPath =$data['category_id']['displayPath'];
                        // $browseNodeId = $data['category_id']['browser_node_id'];
                        
                        if(is_array($displayPath) && (in_array('Default',$displayPath) || in_array('default',$displayPath))){
                            $productType = 'PRODUCT';
                        }
                        else{
                            
                            // print_r($browseNodeId);die;
                            $targetShopId = $data['shop_ids'][0]['target'];
                            $sourceShopId = $data['shop_ids'][0]['source'];
                            $rawBody['source']['marketplace']= 'shopify';
                            $rawBody['target']['marketplace']= 'amazon';
                            $rawBody['source']['shopId'] =(string) $sourceShopId;
                            $rawBody['target']['shopId'] =(string) $targetShopId;
                            // $rawBody['shop_id'] = $targetShopId;
                            if($subcategory == "sportinggoods"){
                                $subcategory = "sporting_goods";
                            }
                            if($subcategory == "furnitureanddecor"){
                                $subcategory = 'furniture_and_decor';
                            }
                            if($subcategory == "guildhome"){
                                $subcategory = 'guild_home';
                            }
                            if($subcategory == "outdoorrecreationproduct"){
                                $subcategory = 'outdoor_recreation_product';
                            }
                            if($subcategory == "fabricappliquepatch"){
                                $subcategory = "fabric_applique_patch";
                            }
                            if($subcategory == "autoaccessorymisc"){
                                $subcategory = "auto_accessory";
                            }
                            if($subcategory == "sexualwellness"){
                                $subcategory = "sexual_wellness";
                            }
                            $rawBody['keywords']= [$subcategory];
                            
                            //   $rawBody['selected']= $browseNodeId;
                           
                            
                            $response = $this->di->getObjectManager()->get('App\Amazon\Components\Template\Category')
                            ->getAllProductTypes($rawBody);
                            // print_r($response);die;
                           
                            if(isset($response['success']) && $response['success']){
                                $productType = $response['data'][0]??"";
                            }
                            else{
                        $rawBody['keywords']= $displayPath;
                       
                      
                        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Template\Category')
                        ->getAllProductTypes($rawBody);
                        
                        if(isset($response['success']) && $response['success'])
                        {
                            $productType = $response['data'][0]??"";
                        }
                        else{
                            $aggregate =[];
                            $aggregate []= [
                                '$search' => [
                                    'index' => 'profile_amazon_type',
                                    'autocomplete' => [
                                        'query' => $subcategory,
                                        'path' => "product_type",
                                        'fuzzy' => ['maxEdits' => 2,"prefixLength" =>3]
                                    ],
                                ]
                            ];
                            $productTypeDatas = $AmazonProductType->aggregate($aggregate, $arrayParams)->toArray();
                            if(isset($productTypeDatas[0]['product_type']))
                            {
                                $productType = $productTypeDatas[0]['product_type'];
                                
                            }
                            else
                            {
                                  $this->di->getLog()->logContent('ProductType= '. $userId.'Response'. json_encode($response, true), 'info', 'ProfileMigration.log');
                                // print_r($DBresponse);die;
                                continue;
                            }
                      }
                      
                    }
                }
                // print_r($jsonFeed);die;
                // $ai_service_table->updateOne(['type' => "access_token"], ['$set' => ['print_raccess_token' => $access_token]], ['upsert' => true]);
                $updatedData = $profileCollection->updateOne(['_id'=>$profileData['profile_id'],'user_id'=>$profileData['user_id']],['$set'=>['category_id.product_type'=> $productType,'targets.0.category_id.product_type'=>$productType]]);
                $updated = $profileCollection->updateOne(['_id'=>$profileData['_id'],'user_id'=>$profileData['user_id']],['$set'=>['data.jsonFeed'=> $jsonFeed,'picked' =>true]],['$unset'=>['data.required_attribute'=>1]]);
                // print_r($updated);die;
                $counter++;

                  
                    
                    
                }
                    
                }
               
                

            }

        }
        if($counter>0){
            return ['success'=> true, 'message' => 'Profile Migrated'];
        }
        else{
            return ['success'=> false, 'message' => 'Profile Not In XML'];

        }
    }
    public function ProductEditedMigrate($data)
    {
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $AmazonProductType = $this->mongo->getCollectionForTable('amazon_product_type');
        $filename = BP.DS.'var'.DS.'FlatFile-To-JSON-Mapping.xlsx';
        $userId = $data['user_id']?? $this->di->getUser()->id;
        // print_r($data);die;
        $targetShopId = $data['target']['shop_id'];
        $container_id = $data['container_id'];
        $Flat = $this->readXLSX($filename);
        $json = [];
        foreach($Flat as $ff){
            if($ff[0] == "condition_type"){
                $json[$ff[0]]= $ff[2];
                
            }
            else{
                
                $json[$ff[0]]= $ff[1];
            }
        }
        
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $users = [];
        $productContainerCollection = $this->mongo->getCollectionForTable(self::ProductContainer);
        $pipline = [
            [
                '$match' => [
                    // '_id'=>new \MongoDB\BSON\ObjectId('64d79cb3e6f1a00f790919d4'),
                    // 'user_id' => ['$exists' =>true],
                    'user_id' => $userId,
                    'shop_id' => $targetShopId,
                    'container_id'=> $container_id,
                    'target_marketplace' => 'amazon',
                    'category_settings'=>['$exists' =>true],
                    // 'data.jsonFeed'=>['$size'=>0],
                    // 'category_settings.product_type'=>['$exists' =>false],

                ]
            ]
        ];
        // print_r(json_encode($pipline));die;
        $productDatas = $productContainerCollection->aggregate($pipline, $arrayParams)->toArray();
        $count = 0;
        $counter = 0;
        $arraay =[];
        foreach($productDatas as $productData)
        {
           
            if(!empty($productData['category_settings']['attributes_mapping']['required_attribute']))
            {
                $requiredAttribute = $productData['category_settings']['attributes_mapping']['required_attribute'];
                $optionalAttribute = $productData['category_settings']['attributes_mapping']['optional_attribute'];
                $attributes = array_merge($requiredAttribute,$optionalAttribute);
                $userId = $productData['user_id'];
                $count ++;

                // print_r($count);
                $getUser = User::findFirst([['_id' => $userId]]);
                if(!empty($getUser)){
                    $getUser->id = (string)$getUser->_id;
                    $this->di->setUser($getUser);
                    
                    $jsonFeed=$this->convertTojson($attributes,$json);
                    // print_r($jsonFeed);die;
                    if(!empty($jsonFeed))
                    {
                        $subcategory = $productData['category_settings']['sub_category'];
                        $displayPath =$productData['category_settings']['displayPath'];
                        // $browseNodeId = $data['category_id']['browser_node_id'];
                        
                        if($subcategory == 'default')
                        {
                            $productType = 'PRODUCT';
                        }
                        else{
                            
                            // print_r($browseNodeId);die;
                            $targetShopId = $productData['shop_id'];
                            $sourceShopId = $productData['source_shop_id'];
                            $rawBody['source']['marketplace']= 'shopify';
                            $rawBody['target']['marketplace']= 'amazon';
                            $rawBody['source']['shopId'] =(string) $sourceShopId;
                            $rawBody['target']['shopId'] =(string) $targetShopId;
                            // $rawBody['shop_id'] = $targetShopId;
                            if($subcategory == "sportinggoods"){
                                $subcategory = "sporting_goods";
                            }
                            if($subcategory == "furnitureanddecor"){
                                $subcategory = 'furniture_and_decor';
                            }
                            if($subcategory == "guildhome"){
                                $subcategory = 'guild_home';
                            }
                            if($subcategory == "outdoorrecreationproduct"){
                                $subcategory = 'outdoor_recreation_product';
                            }
                            if($subcategory == "fabricappliquepatch"){
                                $subcategory = "fabric_applique_patch";
                            }
                            $rawBody['keywords']= [$subcategory];
                            
                            //   $rawBody['selected']= $browseNodeId;
                           
                            $response = $this->di->getObjectManager()->get('App\Amazon\Components\Template\Category')
                            ->getAllProductTypes($rawBody);
                           
                            
                           
                            if(isset($response['success']) && $response['success']){
                                $productType = $response['data'][0]??"";
                            }
                            else{
                        $rawBody['keywords']= $displayPath;
                       
                       
                        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Template\Category')
                        ->getAllProductTypes($rawBody);
                        
                        if(isset($response['success']) && $response['success'])
                        {
                            $productType = $response['data'][0]??"";
                        }
                        else{
                            $aggregate =[];
                            $aggregate []= [
                                '$search' => [
                                    'index' => 'profile_amazon_type',
                                    'autocomplete' => [
                                        'query' => $subcategory,
                                        'path' => "product_type",
                                        'fuzzy' => ['maxEdits' => 2,"prefixLength" =>3]
                                    ],
                                ]
                            ];
                            $productTypeDatas = $AmazonProductType->aggregate($aggregate, $arrayParams)->toArray();
                            if(isset($productTypeDatas[0]['product_type']))
                            {
                                $productType = $productTypeDatas[0]['product_type'];
                                
                            }
                            else
                            {
                                  $this->di->getLog()->logContent('ProductType= '. $userId.'Response'. json_encode($response, true), 'info', 'ProfileMigration.log');
                                // print_r($DBresponse);die;
                                continue;
                            }
                      }


                    }
                }   
                if(!empty($productType) && !empty($jsonFeed)){
                    // array_push($arraay,$productType);
                    $executed = $productContainerCollection->updateOne(['_id'=>$productData['_id'],'user_id'=>$productData['user_id']],['$set'=>['category_settings.product_type'=> $productType ,'category_settings.attributes_mapping.jsonFeed' => $jsonFeed]]);
                }
                
                $counter ++;
                

            }

            }

            }
            elseif (!isset($productData['category_settings']['attributes_mapping']) || empty($productData['category_settings']['attributes_mapping'])
            ) {
                $userId = $productData['user_id'];
                $getUser = User::findFirst([['_id' => $userId]]);
                if (!empty($getUser)) {
                    $getUser->id = (string)$getUser->_id;
                    $this->di->setUser($getUser);
                    $query = [
                        'user_id' => $userId,
                        'shop_id' => $targetShopId,
                        'container_id' => $container_id,
                        'target_marketplace' => 'amazon',
                        'category_settings' => ['$exists' => true],
                    ];
                    $update = [
                        '$unset' => ['category_settings' => true]
                    ];
                    $productContainerCollection->updateOne($query, $update);
                    return ['success' => true, 'message' => 'Product Empty Data is Migrated'];
                } else {
                    return ['success' => false, 'message' => 'Product Empty Data is not  Migrated'];
                }
            }
        }
        if($counter>0){

            return ['success'=> true, 'message' => 'Product Migrated'];
        }
        else{
            return ['success'=> false, 'message' => 'Product Not In XML'];

        }


    }
    public function readXLSX($filename) {
        // Open the XLSX file as a ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($filename) === TRUE) {
            // Extract the shared strings (if any)
            $sharedStringsXML = $zip->getFromName('xl/sharedStrings.xml');
            $sharedStrings = [];
            if ($sharedStringsXML) {
                $xml = simplexml_load_string($sharedStringsXML);
                foreach ($xml->si as $val) {
                    $sharedStrings[] = (string)$val->t;
                }
            }
    
            // Extract the workbook data
            $sheetXML = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
    
            // Parse the sheet data
            $xml = simplexml_load_string($sheetXML);
            $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    
            $rows = [];
            foreach ($xml->sheetData->row as $row) {
                $rowData = [];
                foreach ($row->c as $cell) {
                    $value = (string)$cell->v;
    
                    // Check if cell contains a shared string
                    if (isset($cell['t']) && $cell['t'] == 's') {
                        $value = $sharedStrings[intval($value)];
                    }
                    $rowData[] = $value;
                }
                $rows[] = $rowData;
            }
            return $rows;
        } else {
            throw new Exception("Unable to open the file.");
        }
    }
    public function convertTojson($attributes,$json){
        // print_r($json);die;
        $jsonFeed = [];
        $notJSonFeed = [];
        $a = 1;
        foreach($attributes as $attribute){
           $att=[];
            if(isset($json[$attribute['amazon_attribute']]) || $attribute['amazon_attribute'] == "condition-type"){
                if($attribute['amazon_attribute'] == "condition-type"){
                    $attribute['amazon_attribute']  = "condition_type";
                }
                
                // $jsonPath = $json[$attribute['amazon_attribute']];
                // print_r($jsonPath);die;
                $inputString = $json[$attribute['amazon_attribute']];
               
               
        // Remove the leading slash if necessary
        $inputString = ltrim($inputString, '/');
        $inputString = ltrim($inputString, 'attributes');
        $inputString = ltrim($inputString, '/');
        
        
        // Split the string by the slash delimiter
        // print_r($inputString);die;
        $keys = explode('/', $inputString);
        
        
       
       
        // unset($keys[0]);
        // print_r(($keys));die;// Initialize the array
        $result = [];
        // Create a reference to the current position in the array
        $current = &$result;
       
        // Iterate through the keys to build the nested array
        foreach ($keys as $key) {
           
            $current[$key] = [];
           
            
            $current = &$current[$key];
           
        }
        // if(isset($attribute['recommendation']) && $attribute['shopify_attribute'] == "recommendation") {
        //     $att['attribute_value'] = $attribute['shopify_attribute'];
        //     $att['recommendation'] = $attribute['recommendation'];
        // }
        // elseif(isset($attribute['custom_text']) && $attribute['shopify_attribute'] == "custom") {
        //     $att['attribute_value'] = $attribute['shopify_attribute'];
        //     $att['custom'] = $attribute['custom_text'];
        // }
        
        if(isset($attribute['recommendation']) && $attribute['shopify_select'] == "recommendation") {
            $att['attribute_value'] = $attribute['shopify_select'];
            $att['recommendation'] = $attribute['recommendation'];
        }
        elseif(isset($attribute['custom_text']) && $attribute['shopify_select'] == "custom") {
            $att['attribute_value'] = $attribute['shopify_select'];
            $att['custom'] = $attribute['custom_text'];
        }
        elseif(isset($attribute['shopify_attribute']) && $attribute['shopify_select'] == "Search"){
            $att['attribute_value']= 'shopify_attribute';
            $att['shopify_attribute'] = $attribute['shopify_attribute'];
        }
        // if(isset($attribute['']))
       
        
        // if(isset($attribute['']))
       
       $this->updateValue($result, $inputString, $att);
                // print_r();die;
                // print_r($Flat[$att['amazon_attribute']]);die;
                // if($attribute['amazon_attribute'] == "unit_count"){
                //     print_r($result[$keys[0]]);die;
                //    }
                
            if(!empty($result)){
                if(isset($jsonFeed[$keys[0]])){
                    $a =sizeof($jsonFeed[$keys[0]]);
                    // print_r();die;
                    $jsonFeed[$keys[0]][$a] = $result[$keys[0]][0];
                    
                }
                else{

                    $jsonFeed[$keys[0]]=$result[$keys[0]];

                }
            }
           
        }
        else{
            $notJSonFeed[]=$attribute;
        }
       
        
        }
        
        return $jsonFeed;
        // print_r(json_encode($jsonFeed));die;

    }
    public function updateValue(&$array, $path, $newValue){
       
        $path = ltrim($path, '/');
        $keys = explode('/', $path);
        $current = &$array;
        
        foreach ($keys as $key) {
            
            if (!isset($current[$key])) {
                $current[$key] = [];
            }
            // print_r($current);die;
            $current = &$current[$key];
            
        }
      
        $current = $newValue;
    }
    
}
