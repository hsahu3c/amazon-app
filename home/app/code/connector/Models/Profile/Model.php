<?php

namespace App\Connector\Models\Profile;

use App\Core\Models\BaseMongo;
use Phalcon\Events\Manager as EventsManager;



class Model extends BaseMongo
{
    protected $table = 'profile';

    protected $isGlobal = true;

    public function createUpdateProfile($data,$overWriteExistingProducts=true)
    {
    	$userId = $this->di->getUser()->id;
    	if(isset($data['category_id'],$data['name']))
        {
            $marketplace = $data['marketplace'] ?? 'cedcommerce';
            $data['marketplace'] = $marketplace;

            $data['user_id'] = $userId;
            $exists = $this->loadByField([
                    "category_id" => $data['category_id'],
                    "user_id"=> $userId,
                    "marketplace"=>$marketplace
            ]);

            if($exists)
            {
                if(isset($data['targets'],$exists['targets']))
                {
                    $exists['targets'] = (array)$exists['targets'];
                    $data['targets'] = array_merge($exists['targets'],$data['targets']);

                } elseif(isset($exists['targets']))
                {
                    $exists['targets'] = (array)$exists['targets'];
                    $data['targets'] = $exists['targets'];
                }

                $data['_id'] = $exists['_id'];
            } 

            $eventsManager = $this->di->getEventsManager();

            $eventsManager->fire('profile:beforeSave', $this, ['custom_data'=>&$data]);

            if(!empty($data['targets']))
            {
                $targets = (array) $data['targets'];

                foreach ($targets as $targetMarketPlace => $value) {

                    if(!isset($data['targets'][$targetMarketPlace]['category_id'])) {
                        $catModel = new \App\Connector\Models\Category;
                        $mappedData = $catModel->loadByField(['marketplace_id'=>$data['category_id']]);

                        if($mappedData)
                        {
                            if(!empty($mappedData['mapping']))
                            {
                                if(isset($mappedData['mapping'][$targetMarketPlace]))
                                {

                                    $data['targets'][$targetMarketPlace]['category_id'] = $mappedData['mapping'][$targetMarketPlace];
                                    $data['targets'][$targetMarketPlace]['category_info'] = ['full_path'=>$mappedData['full_path'] ?? '','category_id'=>$mappedData['mapping'][$targetMarketPlace]];

                                }else {
                                    $savedData['targets'][$targetMarketPlace]['category_id'] = $data['category_id'];
                                }
                            }else {
                                $data['targets'][$targetMarketPlace]['category_id'] = $data['category_id'];
                            }
                        } else {
                            $data['targets'][$targetMarketPlace]['category_id'] = $data['category_id'];
                        }
                    }
                }

            }

            $this->setData($data);

            if($this->save()){

                $eventsManager->fire('profile:afterSave', $this, ['custom_data'=>&$data]);
                $savedData = $this->getData();
                $savedData['id'] = (string)$savedData['_id'];

                unset($savedData['_id']);
                $helperObj = new Helper();
                $ruleExtract = $savedData;
                $ruleExtract['query'] = $ruleExtract['query'].' && user_id == '.$this->di->getUser()->id;
                $getAllRule = $helperObj->extractRuleFromProfileData($ruleExtract);

                if ($getAllRule['success']) {
                    $customizedFilterQuery = $getAllRule['data'];
                    if ($customizedFilterQuery) {


                        if(!$overWriteExistingProducts){
                            $customizedFilterQuery['$and'][] = [
                                    'profile'=>['$exists'=>false]
                            ];
                        }

                        $collection = $this->getCollectionForTable('product_container');
                        $profileData = [];
                        $profileData['profile_id'] = $savedData['id'];
                        $profileData['profile_name'] = $savedData['name'];
                        $profileData['app_tag'] = $this->di->getAppCode()->getAppTag();

                        $collection->updateMany($customizedFilterQuery,['$pull'=>['profile'=>['app_tag'=>$profileData['app_tag']]]]);

                        $collection->updateMany($customizedFilterQuery,['$push'=>['profile'=>$profileData]]);

                    }
                }

                return ['success'=>true,'message'=>'data inserted successfully','data'=>$savedData];
            }
            return ['success'=>false,'message'=>'something went wrong','code'=>'mongo_save_error'];

        }
     return ['success'=>false,'message'=>'category_id or name missing','code'=>'data_missing'];
    }




    public function getProfile($data)
    {
        $userId = $this->di->getUser()->id;

        if(isset($data['filters'])){
            $data = $data['filters'];
            if(!isset($data['user_id'] ))
                $data['user_id'] = $userId;

            $filterParams = [];
            $allwedFilters = $this->allwedFilters();

            foreach ($data as $key => $value) {
                if(isset($allwedFilters[$key]))
                {
                    if($allwedFilters[$key] instanceof \Closure)
                    {
                        if(call_user_func($allwedFilters[$key],$data)) {
                            $getData = call_user_func($allwedFilters[$key],$data);
                            $filterParams = array_merge($filterParams,$getData);
                        }
                    } else {
                        $filterParams[$key] = $value;
                    }

                } else {
                    $filterParams = []; 
                    $returnArray = ['success'=>false,'code'=>'invalid filter(s)','message'=>$key.' is a invalid filter key'];
                    break;
                }
            }

            if(!empty($filterParams))
            {
                $getProfile = $this->findByField($filterParams);
                return ['success'=>true,'data' => $getProfile ?? []];
            }
        } else {
            $getProfile = $this->findByField(['user_id'=> $userId]);
            return ['success'=>true,'data' => $getProfile ?? []];
        }

        return $returnArray;


    }


    public function allwedFilters()
    {
        return [
            'id'=>function(array $data): array{
                return ['_id' => new \MongoDB\BSON\ObjectId($data['id'])];
            },
            'category_id'=>1,
            'marketplace'=>function(array $data): array{
                return ['targets.'.$data['marketplace'] => ['$exists'=>true]];
            },
            'name'=>1,
            'user_id'=>1
        ];
    }

    public function allwedDeleteFilters()
    {
        return [
            'id'=>function(array $data): array{

                return ['_id' => new \MongoDB\BSON\ObjectId((string)$data['id'])];

            },
            'user_id'=>1,
        ];
    }

    public function deleteProfile($data)
    {
        $deleteData = 0;
        $userId = $this->di->getUser()->id;
        $filterParams = [];
        $allwedFilters = $this->allwedDeleteFilters();
        if(!isset($data['user_id'] ))
            $data['user_id'] = $userId;

        $data1=[];
        foreach ($data as $key => $value) {
            if($key!='target_marketplace')
            {
                $data1[$key]=$data[$key];
            }
        }

        $data=$data1;
        foreach ($data as $key => $value) {
            if(isset($allwedFilters[$key]))
            {

                if($allwedFilters[$key] instanceof \Closure)
                {

                    if(call_user_func($allwedFilters[$key],$data)) {

                        $getData = call_user_func($allwedFilters[$key],$data);
                        $filterParams = array_merge($filterParams,$getData);
                    } 
                    else {
                        $returnArray = ['success'=>false,'code'=>'invalid_value','message'=>$key.' value is invalid'];
                        break;
                    }
                } else {
                    $filterParams[$key] = $value;
                }
            } else {
                $filterParams = []; 
                $returnArray = ['success'=>false,'code'=>'invalid_index','message'=>$key.' is a invalid  index'];
                break;
            }
        }

        if(!empty($filterParams))
        {
            $eventsManager = $this->di->getEventsManager();

            $eventsManager->fire('profile:beforeDelete', $data);

            if($this->getCollection()->deleteOne($filterParams, ['w' => true])){
                $deleteData++;
            }

            $eventsManager->fire('profile:afterDelete', $data);
            $returnArray = ['success'=>true,'message'=>'data deleted successfully','data'=>['deleteData'=>$deleteData]];
        }

        return $returnArray; 
    }



}   
