<?php

namespace App\Amazon\Components;

use MongoDB\BSON\ObjectId;
use Exception;
use App\Amazon\Components\Template\Category;
use App\Core\Components\Base as Base;
use App\Amazon\Components\Common\Helper;

class GlobalValueMapping extends Base
{
    public function saveGlobalValueMapping($data)
    {
        $userId = $data['user_id']?? $this->di->getUser()->id;
        $shopId = $this->di->getRequester()->getTargetId();
        try {
            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::Global_Value_Mapping);
            $res = $collection->findOne(["user_id" => $userId, 'target_shop_id'=> $shopId,"_id" => new ObjectId($data['id'])]);
            if (!empty($res)) {
                $collection->updateOne(["user_id" => $userId, 'target_shop_id'=>$shopId,"_id" => new ObjectId($data['id'])], ['$set' => ["mappings" => $data['mappings'],'updatedAt'=>date('c')]]);
            } else {
                $count = $collection->count(["user_id" => $userId, 'target_shop_id'=> $shopId]);
                if($count>=5){
                    return ['success' => false, 'message' => 'Creation of 5 Lists are allowed.'];
                }
                if(isset($data['name'],$data['mappings']))
                {
                $collection->insertOne(["user_id" => $userId, 'target_shop_id' => $shopId, 'createdAt'=>date('c'),'updatedAt'=>date('c') ,'name'=> $data['name'],"mappings" => $data['mappings']]);
                return ['success' => true, 'message' => 'Global Value Inserted Succesfully'];
                }
                else{
                    return ['success' => false, 'message' => 'Required Paramters missing'];
                }
            }
            return ['success' => true, 'message' => 'Global Value Mapping Updated'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

    }

    public function getGlobalValueMapping($data)
    {
        try {
            $userId = $this->di->getUser()->id;
            $shopId = $this->di->getRequester()->getTargetId();
            $shopId_source = $this->di->getRequester()->getSourceId();

            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::Global_Value_Mapping);
            if(isset($data['id'])){

                $response = $collection->findOne(
                    ["user_id" =>$userId,"target_shop_id"=> $shopId ,"_id" => new ObjectId($data['id'])],
                    ['typeMap' => ['root' => 'array', 'document' => 'array']]
                );


                return ['success' => true, 'message' => 'Get data for mapping attributes.', 'data' => $response];
            }
            else {
                return ['success' => false, 'message' => 'Required Paramters not supplied'];

            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getLists($data)
    {
        try {
            $userId = $this->di->getUser()->id;
            $shopId = $this->di->getRequester()->getTargetId();
            $shopId_source = $this->di->getRequester()->getSourceId();
            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::Global_Value_Mapping);
            $response = $collection->find(
                ["user_id" => $userId,"target_shop_id"=> $shopId],
                ['projection' => ['name' => 1,'createdAt'=>1,'updatedAt'=>1],'typeMap' => ['root' => 'array', 'document' => 'array']]
            );


            return ['success' => true, 'message' => 'Get Lists', 'data' => $response->ToArray()];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    public function deleteGlobalValueMapping($data)
    {
        try {
            $userId = $this->di->getUser()->id;
            $shopId = $this->di->getRequester()->getTargetId();
            $shopId_source = $this->di->getRequester()->getSourceId();
            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::Global_Value_Mapping);
            if(isset($data['id'])){

                $response = $collection->deleteOne(
                    ["user_id" =>$userId,"target_shop_id"=> $shopId ,"_id" => new ObjectId($data['id'])],
                    ['typeMap' => ['root' => 'array', 'document' => 'array']]
                );

                return ['success' => true, 'message' => 'Global Value Mapping deleted successfully.', 'data' => $response];
            }


            return ['success' => true, 'message' => 'Params Not sent'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}