<?php

namespace App\Connector\Models\Profile;

use App\Core\Models\BaseMongo;

class ProfileHeper extends BaseMongo
{

    protected $table = 'profile';

    const modulesTobreak=['attributes_mapping','shops','warehouses'];

    public function validateAndFormatData($data)
    {
        $correctData = true;
        $formatData = [];
        $noId=false;
        foreach ($data as $value) {
            if (!(isset($value['type']) && ($value['type'] != 'profile' ||  (isset($value['name']) && isset($value['category_id']))))) {
                $correctData = false;
                break;
            } else {
                $formatData[$value['type']] = $value;
            }

            if(!isset($value['_id']) && $value['type']!='profile'){
                $noId=true;
            }
        }

        if(isset($formatData['profile']) && $noId){
            $correctData=false;
        }

        return ['dataCorrect' => $correctData, 'formattedData' => $formatData];
    }

    public function saveChunk($data)
    {
        $profileTable = $this->getCollectionForTable('profile');
        $mongo = isset($data['_id']) ? $profileTable->updateOne(['_id' => $data['_id']], ['$set' => $data]) : $profileTable->insertOne($data);

        return isset($data['_id']) ? $data['_id'] : $mongo->getInsertedId();
    }

    public function saveProfileData($data): void{
        $profileId = $this->saveChunk($data['profile']);
        unset($data['profile']);

        foreach ($data as $value) {
            $value['profile_id'] = $profileId;
            $this->saveChunk($value);
        }
    }

    public function saveAndGetKeyChunk($data){
        $total = count((array)$data);
        if($total>1){
            return false;               
        }

        return $this->saveChunk(array_values($data)[0]);
    }


    public function saveProfile1($data)
    {
        $getValidateAndFormatData = $this->validateAndFormatData($data);
        if ($getValidateAndFormatData['dataCorrect']) {

            $formattedData = $getValidateAndFormatData['formattedData'];

            if (isset($formattedData['profile'])) {

                $this->saveProfileData($formattedData);

                return ['success' => true, 'message' => 'Profile Saved Successfully'];
            }
            $infoGetId =$this->saveAndGetKeyChunk($formattedData);
            if($infoGetId){
                return ['success'=>true, 'message'=>'Ids fetched', 'id'=>$infoGetId];
            }
            return ['success' => false,'message'=>'Multiple Ids unable to fetch'];
        }
        return ['success' => false, 'message' => 'type or category_id or name missing or id of smalled object missing', 'code' => 'data_missing'];
    }

    

    public function saveProfile($data)
    {
    }
}
