<?php

namespace App\Connector\Models\Profile;

use App\Core\Models\BaseMongo;

class Template extends BaseMongo
{
    protected $table = 'profile_settings';

    protected $isGlobal = true;

    public function createUpdateTemplate($data)
    {
    	$userId = $this->di->getUser()->id;
    	if(isset($data['marketplace'],$data['name']))
        {
            $data['user_id'] = $userId;
            $filter = [
                'marketplace' => $data['marketplace'],
                'name' => $data['name'],
            ];
            if (isset($data['use_user_id']) && $data['use_user_id']) {
                $filter['user_id'] = $data['user_id'];
                unset($data['use_user_id']);
            }

            $exists = $this->loadByField($filter);
            if($exists)
            {
                $data['_id'] = $exists['_id'];
            }

            $this->setData($data);
            if($this->save()){
                $savedData = $this->getData();
                $savedData['id'] = $savedData['_id'];
                unset($savedData['_id']);
                return ['success'=>true,'message'=>'data inserted successfully','data'=>$savedData];
            }
            return ['success'=>false,'message'=>'something went wrong','code'=>'mongo_save_error'];
        }
     return ['success'=>false,'message'=>'marketplace or Template name missing','code'=>'data_missing'];

    }

    public function deleteTemplates($data)
    {
        $error = [];
        $deleteData = 0;
        $userId = $this->di->getUser()->id;

        foreach ($data as $value) {
                if(isset($value['marketplace'],$value['name']))
                {
                    $collection = $this->getCollection();
                    $collection->deleteOne([
                    "marketplace" => $value['marketplace'],
                    "name" => $value['name'],
                    "user_id"=>$userId
                        ], ['w' => true]);
                    $deleteData++;

                }elseif(isset($value['id'])){
                    $collection = $this->getCollection();
                    $collection->deleteOne([
                    "_id" => new \MongoDB\BSON\ObjectId($value['id']),
                    "user_id" =>  $userId
                        ], ['w' => true]);
                    $deleteData++;
                }else {
                    $error[] = ['message'=>'invalid message','data'=>$value];
                }
        }

        return ['success'=>true,'message'=>'data deleted successfully','data'=>['deleteData'=>$deleteData,'error'=>$error]]; 
    }

    public function searchTemplates($data)
    {
        $userId = $this->di->getUser()->id;

        $globalTemplates = $this->findByField([
                "marketplace" => 'global', 
                "user_id"=> $userId
            ]);
        $searchTemplates = [];


        if(isset($data['filters'])){
            $data = $data['filters'];
                if(!isset($data['user_id'] ))
                    $data['user_id'] = $userId;

            $searchTemplates = $this->findByField($data);
        }

        $returnData = array_merge($searchTemplates,$globalTemplates);
        return ['success'=>true,'message'=>'data retrieved successfully','data'=>$returnData]; 
    }

}   
