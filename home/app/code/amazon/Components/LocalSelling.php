<?php

namespace App\Amazon\Components;

use Exception;
use App\Connector\Contracts\Sales\OrderInterface;
use App\Core\Components\Base as Base;
use App\Amazon\Components\Common\Helper;

class LocalSelling extends Base
{
    public function storeDate(){
        $date=date_create();
        return date_format($date,"Y-m-d\TH:i:s\Z");
    }

    public function IsoDateFormat($date){
        $isoDate  =  date('Y-m-d\TH:i:s\Z', $date - 3600);
        return $isoDate;
    }

    public function fetchAllStoresFromAmazon($data){
        try{
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target']['shopId'];
            $targetMarketplace = $data['target']['marketplace'];
            $data['nextPageToken'] = (!empty($data['nextPageToken'])) ? $data['nextPageToken'] : '';
            $data['pageSize'] = 100;
            $fetchStore = [];
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Selling);
            $params = ['user_id'=>$userId,'target_shop_id'=>$targetShopId, 
            'target_marketplace' =>$targetMarketplace];
            $matchParams[] = ['$match'=>$params];
            $matchParams[] = ['$project'=>['supplySourceId'=> 1, '_id'=>0]];
            $result = $collection->aggregate($matchParams,["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
            if(count($result) != 0){
                $allStoresInDB = array_column($result, 'supplySourceId');
            }
            else{
                $allStoresInDB = [];
            }

            $params =[
                'user_id'=>$userId,
                'target_shop_id'=>$targetShopId,
                'target_marketplace' =>$targetMarketplace,
                'pageSize' => $data['pageSize'],
                'nextPageToken' =>$data['nextPageToken']
            ];
            $allAmazonStores = $this->getAllListSupplySource($params);
            if(isset($allAmazonStores['data']) && !empty($allAmazonStores['data']) && isset($allAmazonStores['data']['supplySources']) && !empty($allAmazonStores['data']['supplySources'])){
                $AllAmazonStores['Stores'] = array_column($allAmazonStores['data']['supplySources'], 'supplySourceId');
            }
            else{
                return ['success' =>false, 'message'=>'No Store Found to Fecth', 'data'=>$allAmazonStores['data']];
            }

            foreach($AllAmazonStores['Stores'] as $supplySourceIds){
                if(!in_array($supplySourceIds, $allStoresInDB)){
                    $params['supplySourceId'] = $supplySourceIds; 
                    $getSingleStore = $this->getDetailsSingleSupplySource($params, $data);
                }
            }

            return ['success' => true, 'message' => 'Store fetched successfully'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function checkAlreadyExistStore($data){
        try{
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target_marketplace'] ?? $this->di->getRequester()->getTargetName();
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Selling);
            $params = ['user_id'=>$userId,'target_shop_id'=>$targetShopId, 
            'target_marketplace' =>$targetMarketplace];
            if(isset($data['storeName']) && $data['storeName'] != NULL){
                $params['store_address.name'] = $data['storeName'];
                $projectParams = ['store_name'=> '$store_address.name', '_id'=>0];
            }

            if(isset($data['storeCode']) && $data['storeCode'] != NULL){
                $params['supplySourceCode'] = $data['storeCode'];
                $projectParams = ['supplySourceCode'=> 1, '_id'=>0];
            }

            $matchQuery[]= ['$match'=>$params];
            $matchQuery[] = ['$project'=>$projectParams];
            $result = $collection->aggregate($matchQuery,["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
            if(count($result) == 0){
                return ['success' => false, 'message' => 'Not Found', 'data'=> $result];
            }
            return ['success' => true, 'message' => 'Found', 'data'=> $result];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function createSupplySource($data){
        try{

            if (isset($data['target']['shopId']) && !empty($data['target']['shopId']) && isset($data['source']['shopId']) && !empty($data['source']['shopId']) && isset($data['createStore']) && !empty($data['createStore'])) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $targetShopId = $data['target']['shopId'];
                $targetMarketplace = $data['target']['marketplace'];
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $targetShop = $user_details->getShop($targetShopId, $userId);
                if (preg_match('/[\'^£$%&*()}{@#~?><>,|=+]/', (string) $data['createStore']['supplySourceCode'])){
                    return ['success' => false, 'message' => 'Please remove these \'^£$%&*()}{@#~?><>,|=+ special characters from store Code'];
                }

                if(preg_match('/[<>]/', (string) $data['createStore']['address']['name'])){
                    return ['success' => false, 'message' => 'Please remove these <> special characters from store name'];
                }

                $data['createStore']['supplySourceCode'] = str_replace(" ","",$data['createStore']['supplySourceCode']);
                $params['shop_id'] = $targetShop['remote_shop_id'];
                $params['postParams'] = $data['createStore'];
                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                $response = $commonHelper->sendRequestToAmazon('create-supplysource', $params, 'POST');

                if(isset($response['trace'])){
                    $response = json_decode((string) $response['response'],true);
                    return ['success' => false, 'message' => $response['errors'][0]['message']];
                }
                if(!$response['success']){
                    return ['success' => false, 'message' => $response['error']];  
                }
                // update Store details
                if(isset($data['updateStore']) && !empty($data['updateStore'])){
                    $prepData = [
                        "user_id" => $userId,
                        "target_marketplace" => $targetMarketplace,
                        "target_shop_id" => $targetShopId,
                        "postParams" => $data['updateStore'],
                        "supplySourceId" => $response['response']['supplySourceId']
                    ];
                    $updateStore = $this->updateSupplySource($prepData);
                }
                //update status of store
                if(isset($data['storeStatus']) && !empty($data['storeStatus'])){
                    $prepData = [
                        "user_id" => $userId,
                        "target_marketplace" => $targetMarketplace,
                        "target_shop_id" => $targetShopId,
                        "postParams" => $data['storeStatus'],
                        "supplySourceId" => $response['response']['supplySourceId']
                    ];
                    $updateStatusStore = $this->updateStatusSupplySource($prepData);
                }

                $prepData = [
                    "user_id" => $userId,
                    "target_marketplace" => $targetMarketplace,
                    "target_shop_id" => $targetShopId,
                    "supplySourceId" => $response['response']['supplySourceId']
                ];
                $data['user_id'] = $userId;
                $getSingleStore = $this->getDetailsSingleSupplySource($prepData, $data);
                return ['success' => true, 'message' => 'Store created successfully','data'=>$getSingleStore['data']];
            }
            return ['success' => false, 'message' => 'params not found'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

    }

    public function getSingleBopisOrders($data){
        try {
            if (isset($data['marketplace_reference_id']) && !empty($data['marketplace_reference_id'])) {
                $targetShopId = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
                $targetMarketplace = $data['target_marketplace'] ?? $this->di->getRequester()->getTargetName();
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $connectorOrderService = $this->di->getObjectManager()->get(OrderInterface::class);
                $matchFilter = [
                    'filter' => [
                        'user_id' => [
                            "1" => $userId
                        ],
                        'object_type' => [
                            "1" => 'source_order'
                        ],
                        'marketplace' => [
                            "1" => 'amazon'
                        ],
                        'marketplace_shop_id' => [
                            "1" => $targetShopId
                        ],
                        'marketplace_reference_id' => [
                            '1' => $data['marketplace_reference_id']
                        ]
                    ]
                ];
                $result = $connectorOrderService->getAll($matchFilter);
                if (!empty($result)) {
                    return ['success' => true, 'message' => 'BOPIS orders fetched Successfully', 'data' => $result['data']['rows'][0]];
                }
                return ['success' => false, 'message' => 'This Order is not in BOPIS.'];
            }
            return ['success' => false, 'message' => 'params not found'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getBopisOrdersCountStatus($params)
    {
        try {
            if (isset($params['target_shop_id']) && !empty($params['target_shop_id'])) {
                $userId = $params['user_id'] ?? $this->di->getUser()->id;
                $total = [];
                $status = ['ReadyForPickup', 'Failed', 'Pending', 'PickedUp'];

                $connectorOrderService = $this->di->getObjectManager()->get(OrderInterface::class);
                foreach ($status as $value) {
                    $statusWiseMatchParams = [
                        "filter" => [
                            "user_id" => [
                                "1" => $userId
                            ],
                            "object_type" => [
                                "1" => "source_order"
                            ],
                            "marketplace" => [
                                "1" => "amazon"
                            ],
                            "marketplace_shop_id" => [
                                "1" =>  $params['target_shop_id']
                            ],
                            "is_ispu" => [
                                "1" =>  true
                            ],
                            "marketplace_status" => [
                                "1" =>  $value
                            ],
                        ]
                    ];
                    //todo:- verify here the return data format for both the earlier and newer code
                    $totalCount = $connectorOrderService->getCount($statusWiseMatchParams);

                    $total[$value] = $totalCount['data']['count'] ?? 0;
                }

                $matchParams = [
                    "filter" => [
                        "user_id" => [
                            "1" => $userId
                        ],
                        "object_type" => [
                            "1" => "source_order"
                        ],
                        "marketplace" => [
                            "1" => "amazon"
                        ],
                        "marketplace_shop_id" => [
                            "1" =>  $params['target_shop_id']
                        ],
                        "is_ispu" => [
                            "1" =>  true
                        ]
                    ]
                ];
                $totalCount = $connectorOrderService->getCount($matchParams);
                //todo:- verify here the return data format for both the earlier and newer code
                $total["totalCount"] = $totalCount['data']['count'];
                // print_r($total);die;
                $limit = $params['count'] ?? 20;
                if (!isset($params['activePage'])) {
                    $params['activePage'] = 1;
                }

                return ['success' => true, 'message' => 'BOPIS orders fetched Successfully', 'data' => $total];
            }
            return ['success' => false, 'message' => 'params not found'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getAllLocation($data){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Selling);
            $targetMarketplace = $data['target_marketplace'] ?? $this->di->getRequester()->getTargetName();
            if (isset($data['target_shop_id']) && !empty($data['target_shop_id'])) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $matchParams = ["user_id"=>$userId,"target_shop_id"=>$data['target_shop_id'], "target_marketplace"=>$targetMarketplace];
                $totalCount = $collection->count($matchParams);

                $result = $collection->find($matchParams);
                $nameSupplyPairs = [];
                $i =0;
                foreach($result as $value){
                    $name = $value['store_address']['name'];
                    $supplySourceId = $value['supplySourceId'];
                    $nameSupplyPairs[$i]['label'] = $name;
                    $nameSupplyPairs[$i]['value'] = $supplySourceId;
                    $i++;
                }

                if(!empty($nameSupplyPairs)){
                    return ['success' => true, 'message' => 'All stores of user.', 'data'=>$nameSupplyPairs];
                }
                return ['success' => false, 'message' => 'No store found for this user'];
           }
            return ['success' => false, 'message' => 'params not found'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateStatusSupplySource($data, $key=false){
        try{
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target_marketplace'] ?? $this->di->getRequester()->getTargetName();
            if (!empty($targetShopId)) {
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $targetShop = $user_details->getShop($targetShopId, $userId);
                $params = [
                    'shop_id' => $targetShop['remote_shop_id'],
                    'supplySourceId' => $data['supplySourceId'],
                    'postParams' => $data['postParams']
                ];
                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                $response = $commonHelper->sendRequestToAmazon('updatestatus-supplysource', $params, 'POST');
                if(isset($response['trace'])){
                    $response = json_decode((string) $response['response'],true);
                    return ['success' => false, 'message' => $response['errors'][0]['message']];
                }
                if(!$response['success']){
                    return ['success' => false, 'message' => $response['error']];  
                }
                if($key =='update'){
                    $getParams = [
                        'target_shop_id' => $targetShopId,
                        'target_marketplace' => $targetMarketplace,
                        'supplySourceId' => $data['supplySourceId'],
                        'user_id' =>$userId,
                        'status'=>$data['postParams']['status']
                    ];
                    $res = $this->updateStatusInDB($getParams);
                    return ['success' => true, 'message' => 'Status Updates Successfully', 'data' => $getParams['status']];
                }
                return true;
            }
            return ['success' => false, 'message' => 'params not found'];    
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateStatusInDB($data){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Selling);
            $result = $collection->updateOne(['user_id'=>$data['user_id'],'target_shop_id'=>$data['target_shop_id'], 
            'target_marketplace' =>$data['target_marketplace'], 'supplySourceId'=>$data['supplySourceId']],['$set'=>['status'=>$data['status'], 'updated_at'=>(string) $this->storeDate()]]);
             $response = json_decode(json_encode($result),true);
             return $response;
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

    }

    public function getAllListSupplySource($data){
        try{
            if (isset($data['target_shop_id']) && !empty($data['target_shop_id'])) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $data['nextPageToken'] = (!empty($data['nextPageToken'])) ? $data['nextPageToken'] : '';
                $data['pageSize'] = (!empty($data['pageSize'])) ? $data['pageSize'] : '';
                $targetShopId = $data['target_shop_id'];
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $targetShop = $user_details->getShop($targetShopId, $userId);
                $params = [
                    'shop_id' => $targetShop['remote_shop_id'],
                    'pageSize' => $data['pageSize'],
                    'nextPageToken' =>$data['nextPageToken']
                ];
                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                $response = $commonHelper->sendRequestToAmazon('listall-supplysource', $params, 'GET');
                if(isset($response['trace'])){
                    $response = json_decode((string) $response['response'],true);
                    return ['success' => false, 'message' => $response['errors'][0]['message']];
                }
                if(!$response['success']){
                    return ['success' => false, 'message' => $response['error']];  
                }
                return ['success' => true, 'message' => 'List of All Stores', 'data'=>$response['response']];
            }
            return ['success' => false, 'message' => 'params not found'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateSupplySource($data, $key =false){
        try{
            if (isset($data['postParams']) && !empty($data['postParams'])) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $targetShopId = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
                $targetMarketplace = $data['target_marketplace'] ?? $this->di->getRequester()->getTargetName();
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $targetShop = $user_details->getShop($targetShopId, $userId);
                if(isset($data['status']) && !empty($data['status'])){
                    $statusParams=[
                        'supplySourceId' => $data['supplySourceId'],
                        'target_shop_id' => $targetShopId,
                        'user_id' =>$userId, 
                        'target_marketplace' => $targetMarketplace

                    ];
                    $statusParams['postParams'] = [
                        'status' => $data['status']
                    ];
                    $this->updateStatusSupplySource($statusParams, 'update');
                }

                $data['postParams']['configuration']['timezone'] = 'Asia/Kolkata';
                $postParams = $data['postParams'];
                if(isset($postParams['configuration']['operationalConfiguration']['handlingTime']))
                {
                    $handlingTime = $postParams['configuration']['operationalConfiguration']['handlingTime'];
                    $handlingTime['value'] = (int)$handlingTime['value'];
                    unset($postParams['configuration']['operationalConfiguration']['handlingTime']);
                    $postParams['configuration']['handlingTime'] = $handlingTime;
                }

                $params = [
                    'shop_id' => $targetShop['remote_shop_id'],
                    'supplySourceId' => $data['supplySourceId'],
                    'postParams' => $postParams,
                    'user_id' => $userId
                ];

                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                $response = $commonHelper->sendRequestToAmazon('update-supplysource', $params, 'POST');

                if(isset($response['trace'])){
                    $response = json_decode((string) $response['response'],true);
                    return ['success' => false, 'message' => $response['errors'][0]['message']];
                }
                if(!$response['success']){
                    return ['success' => false, 'message' => $response['error']];  
                }
                $data['postParams']['capabilities'] = $response['data']['postParams']['capabilities'];
                if($key == 'update'){
                    $this->saveSupplySource($data, []);
                    return ['success' => true, 'message' => 'Store data updated successfully'];   
                }
                return true;
            }
            return ['success' => false, 'message' => 'params not found'];    
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getDetailsSingleSupplySource($data, $specifics = []){
        try{
            if ((isset($data['target_shop_id']) && !empty($data['target_shop_id'])) || (isset($data['target']['shopId']) && !empty($data['target']['shopId'])) ) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $targetShopId = $data['target_shop_id'] ?? $data['target']['shopId'];
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $targetShop = $user_details->getShop($targetShopId, $userId);
                $params = [
                    'shop_id' => $targetShop['remote_shop_id'],
                    'supplySourceId' => $data['supplySourceId'],
                ];
                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                $response = $commonHelper->sendRequestToAmazon('getdetails-supplysource', $params, 'GET');
                if(!$response['success']){
                    return ['success' => false, 'message' => $response['error']];  
                }
                if(isset($response['trace'])){
                    $response = json_decode((string) $response['response'],true);
                    return ['success' => false, 'message' => $response['errors'][0]['message']];
                }
                if(!empty($specifics)){
                    $this->saveSupplySource($specifics,$response['response']);
                    return ['success' => true, 'message' => 'Details of Store Fetch Successfully', 'data'=>$response['response']];
                }
                return ['success' => true, 'message' => 'Details of Store Fetch Successfully', 'data'=>$response['response']];
            }
            return ['success' => false, 'message' => 'params not found'];    

        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function archiveSupplySource($data){
        try{
            if (isset($data['supplySourceId']) && !empty($data['supplySourceId'])) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $targetShopId = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
                $targetMarketplace = $data['target_marketplace'] ?? $this->di->getRequester()->getTargetName();
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $targetShop = $user_details->getShop($targetShopId, $userId);
                $params = [
                    'shop_id' => $targetShop['remote_shop_id'],
                    'supplySourceId' => $data['supplySourceId'],
                ];
                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                $response = $commonHelper->sendRequestToAmazon('archive-supplysource', $params, 'DELETE');
                if(isset($response['trace'])){
                    $response = json_decode((string) $response['response'],true);
                    return ['success' => false, 'message' => $response['errors'][0]['message']];
                }
                if(!$response['success']){
                    return ['success' => false, 'message' => $response['error']];  
                }
                $this->deleteStoreInDB($data);
                return ['success' => true, 'message' => 'Delete Store Successfully'];
            }
            return ['success' => false, 'message' => 'params not found'];

        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteStoreInDB($data){
        try{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Selling);
            $result = $collection->deleteOne(['supplySourceId'=>$data['supplySourceId']]); 
            return $result;
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

    }

    public function saveSupplySource($data, $specific){
        try{
            $address = [];
            if(isset($data['createStore']['address']))
            {
                $address = $data['createStore']['address'];
            }
            elseif(isset($specific['address']))
            {
                $address = $specific['address'];
            }

            $configuration = $specific['configuration'] ?? NULL;
            $capabilities = $specific['capabilities'] ?? NULL;
            if(isset($specific['alias'])){
                $address['name'] = $specific['alias'];
            }

            $createdAt = (isset($specific['createdAt'])) ? (string) $this->IsoDateFormat($specific['createdAt']) : (string) $this->storeDate();
            $updatedAt = (isset($specific['updatedAt'])) ? (string) $this->IsoDateFormat($specific['updatedAt']) : (string) $this->storeDate();
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Selling);
            if(isset($specific['supplySourceId']))
            {
                $supplySourceId = $specific['supplySourceId'];
            }
            elseif(isset($data['supplySourceId']))
            {
                $supplySourceId = $data['supplySourceId'];
            }

            $response = $collection->findOne(['supplySourceId'=>$supplySourceId]);
            $response = json_decode(json_encode($response),true);
            if(!empty($response)){
                  $collection->updateOne(['supplySourceId'=>$data['supplySourceId']],['$set'=>["configuration"=>$data['postParams']['configuration'], 'capabilities' => $data['postParams']['capabilities'], 'store_address.name' => $data['postParams']['alias'], 'updated_at'=>(string) $this->storeDate()]]);
            }
            else{
                $collection->insertOne(["user_id"=>$data['user_id'], "source_shop_id"=>$data['source']['shopId'], "source_marketplace" =>$data['source']['marketplace'],"target_shop_id"=>$data['target']['shopId'], "target_marketplace" =>$data['target']['marketplace'], 
                'store_address' => $address,"status"=>$specific['status'], 'supplySourceId'=>$specific['supplySourceId'], 'supplySourceCode'=> $specific['supplySourceCode'], 'configuration'=>$configuration,'capabilities' => $capabilities, 'created_at' =>$createdAt, 'updated_at' =>$updatedAt]);
            }
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getBopisOrders($params)
    {
        try {
            if (isset($params['target_shop_id']) && !empty($params['target_shop_id'])) {
                $userId = $params['user_id'] ?? $this->di->getUser()->id;

                //todo:- remove mongo object creation
                $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollectionForTable(Helper::ORDER_CONTAINER);
                // get order using status filter
                $connectorOrderService = $this->di->getObjectManager()->get(OrderInterface::class);


                if (isset($params['status']) && !empty($params['status'])) {
                    $matchParams = [
                        "filter" => [
                            "user_id" => [
                                "1" => $userId
                            ],
                            "object_type" => [
                                "1" => "source_order"
                            ],
                            "marketplace" => [
                                "1" => "amazon"
                            ],
                            "marketplace_shop_id" => [
                                "1" =>  $params['target_shop_id']
                            ],
                            "is_ispu" => [
                                "1" =>  true
                            ],
                            "marketplace_status" => [
                                "1" =>  $params['status']
                            ],
                        ]
                    ];
                } else {
                    $matchParams = [
                        "filter" => [
                            "user_id" => [
                                "1" => $userId
                            ],
                            "object_type" => [
                                "1" => "source_order"
                            ],
                            "marketplace" => [
                                "1" => "amazon"
                            ],
                            "marketplace_shop_id" => [
                                "1" =>  $params['target_shop_id']
                            ],
                            "is_ispu" => [
                                "1" =>  true
                            ],
                        ]
                    ];
                }

                if (isset($params['order_id'])) {
                    $matchParams['filter']['marketplace_reference_id'] = [ "3" => $params['order_id']];
                }

                if (isset($params['store'])) {
                    $matchParams['filter']['items.storechainId'] = [ "1" => $params['store']];
                }

                if(isset($params['count']))
                {
                    $matchParams['count'] = $params['count'];
                }

                if(isset($params['activePage']))
                {
                    $matchParams['activePage'] = $params['activePage'];
                }

                $source_data = $connectorOrderService->getAll($matchParams);
                $order['Totalcount'] = $source_data['data']['count'];
                $order['order'] = $source_data['data']['rows'];
                return ['success' => true, 'message' => 'BOPIS orders fetched Successfully', 'data' => $order];
            }
            return ['success' => false, 'message' => 'params not found'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getAllStoreFromDB($data){
        try{
            if (isset($data['target_shop_id']) && !empty($data['target_shop_id'])) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollectionForTable(Helper::Local_Selling);

                $matchParams = ["user_id"=>$userId,"target_shop_id"=>$data['target_shop_id'],
                'target_marketplace'=>$data['target_marketplace'] ];

                if(isset($data['search']) && isset($data['status'])){
                    $matchParams = ['$or'=>[
                        ['store_address.name' => ['$regex'=>$data['search'], '$options'=>'i']], 
                        ['supplySourceId'=>['$regex'=>$data['search'], '$options'=>'i']], 
                        ['supplySourceCode'=>['$regex'=>$data['search'], '$options'=>'i']]], 
                        'status'=>$data['status']];
                }
                elseif(isset($data['status'])){
                    $matchParams = ["user_id"=>$userId,"target_shop_id"=>$data['target_shop_id'],
                    'target_marketplace'=>$data['target_marketplace'],'status'=>$data['status'] ];
                }
                elseif(isset($data['search'])){
                    $matchParams = ['$or'=>[
                        ['store_address.name' => ['$regex'=>$data['search'], '$options'=>'i']], 
                        ['supplySourceId'=>['$regex'=>$data['search'], '$options'=>'i']], 
                        ['supplySourceCode'=>['$regex'=>$data['search'], '$options'=>'i']]
                        ]];
                }

                 $totalCount = $collection->count($matchParams);
                 $limit = $data['count'] ?? 20;
                     if (!isset($data['activePage'])) {
                         $data['activePage'] = 1;
                     }

                     $page = $data['activePage'];
                     $offset = ($page - 1) * $limit;
                     $storesParams = [];
                     $storesParams[] = ['$match'=> $matchParams];
                     $storesParams[] = ['$sort' => ['created_at'=> -1]];
                     $storesParams[] = ['$project' => ['status'=>1, 'store_address'=>1, 'supplySourceId'=>1, 'supplySourceCode'=>1 ,'capabilities'=>1, 
                     'configuration'=>1, 'updated_at'=>1, 'created_at'=>1, '_id'=>0]];
                     if (isset($offset)) {
                         $storesParams[] = ['$skip' => (int) $offset];
                        }

                 $storesParams[] = ['$limit' => (int)($limit)];
                 $response['stores'] = $collection->aggregate($storesParams, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
                 $response['stores'] = json_decode(json_encode($response['stores']),true);
                 $response['totalCount']= $totalCount;
                 if(!empty($response)){
                 return ['success' => true, 'message' => 'All created stores.', 'data'=>$response];
                }
                 return ['success' => false, 'message' => 'stores not found'];
           }
            return ['success' => false, 'message' => 'params not found'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getAllStores($data){
        try{
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target_marketplace'] ?? $this->di->getRequester()->getTargetName();
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Selling);
            if(isset($data['onlyActiveStore']) && $data['onlyActiveStore']){
                $matchParams = ["user_id"=>$userId,"target_shop_id"=>$targetShopId,
                'target_marketplace'=>$targetMarketplace,'status'=>'Active'];
                $storesParams = [];
                $storesParams[] = ['$match'=> $matchParams];
                $storesParams[] = ['$sort' => ['created_at'=> -1]];
                $storesParams[] = ['$project' => ['supplySourceId'=>1, 'store_name'=> '$store_address.name','status'=>1,'_id'=>0]];
                $response = $collection->aggregate($storesParams, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
            }else{
                $matchParams = ["user_id"=>$userId,"target_shop_id"=>$targetShopId,
                'target_marketplace'=>$targetMarketplace];
                $storesParams = [];
                $storesParams[] = ['$match'=> $matchParams];
                $storesParams[] = ['$sort' => ['created_at'=> -1]];
                $storesParams[] = ['$project' => ['supplySourceId'=>1, 'store_name'=> '$store_address.name','status'=>1,'_id'=>0]];
                $response = $collection->aggregate($storesParams, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
            }

            if(!empty($response)){
                return ['success' => true, 'message' => 'All Active stores.', 'data'=>$response];
            }
            return ['success' => false, 'message' => 'stores not found'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getAllActiveStores($data){
        try{
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
            $targetMarketplace = $data['target_marketplace'] ?? $this->di->getRequester()->getTargetName();
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Local_Selling);
            $matchParams = ["user_id"=>$userId,"target_shop_id"=>$targetShopId,'target_marketplace'=>$targetMarketplace, "status" =>Helper::ACTIVE_STATUS];
            $storesParams = [];
            $storesParams[] = ['$match'=> $matchParams];
            $storesParams[] = ['$project' => ['supplySourceId'=>1, 'store_name'=> '$store_address.name','status'=>1,'_id'=>0]];
            $response = $collection->aggregate($storesParams, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
            if(!empty($response)){
                return ['success' => true, 'message' => 'All Active stores.', 'data'=>$response];
            }
            return ['success' => false, 'message' => 'stores not found'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getAllInactiveStores($data){
        try{
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $targetShopId = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
                $targetMarketplace = $data['target_marketplace'] ?? $this->di->getRequester()->getTargetName();
                $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollectionForTable(Helper::Local_Selling);
                $matchParams = ["user_id"=>$userId,"target_shop_id"=>$targetShopId,'target_marketplace'=>$targetMarketplace, "status" =>Helper::INITIAL_STATUS];
                $storesParams = [];
                $storesParams[] = ['$match'=> $matchParams];
                $storesParams[] = ['$project' => ['supplySourceId'=>1, 'store_name'=> '$store_address.name','status'=>1,'_id'=>0]];
                $response = $collection->aggregate($storesParams, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
                if(!empty($response)){
                    return ['success' => true, 'message' => 'All Active stores.', 'data'=>$response];
                }
                return ['success' => false, 'message' => 'stores not found'];
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateBOPISOrderStatus($data)
    {
        try {
            if (isset($data['status'], $data['orderId']) && !empty($data['status']) && !empty($data['orderId'])) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $targetShopId = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
                $targetMarketplace = $data['target_marketplace'] ?? $this->di->getRequester()->getTargetName();
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $targetShop = $user_details->getShop($targetShopId, $userId);
                if (isset($targetShop['warehouses']) && !empty($targetShop['warehouses'])) {
                    foreach ($targetShop['warehouses'] as $marketplace) {
                        $marketplaceId = $marketplace['marketplace_id'];
                    }
                }

                $params['shop_id'] =  $targetShop['remote_shop_id'];
                $params['postParams'] = [
                    'marketplaceID' => $marketplaceId,
                    'status' => $data['status']
                ];
                $params['orderId'] =  $data['orderId'];
                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                $response = $commonHelper->sendRequestToAmazon('update-orderStatusBOPIS', $params, 'POST');
                if (isset($response['trace'])) {
                    $response = json_decode((string) $response['response'], true);
                    return ['success' => false, 'message' => $response['errors'][0]['message']];
                }
                if (!$response['success']) {
                    return ['success' => false, 'message' => $response['error']];
                }
                $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollectionForTable(Helper::ORDER_CONTAINER);
                $result = $collection->updateOne(
                    [
                        'user_id' => $userId,
                        'object_type' => 'source_order',
                        'marketplace' => 'amazon',
                        'marketplace_shop_id' => $targetShopId,
                        'marketplace_reference_id' => $data['orderId'],
                        'is_ispu' => true
                    ],
                    [
                        '$set' => [
                            'marketplace_status' => $data['status'],
                            'updated_at' => $this->storeDate(),
                        ],
                    ]
                );
                //check if updated
                return ['success' => true, 'message' => 'Status change successfully'];
            }
            return ['success' => false, 'message' => 'params not found'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}