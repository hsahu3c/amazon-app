<?php
namespace App\Frontend\Controllers;

use App\Core\Controllers\BaseController;

use MongoDB\BSON\ObjectID;

class CountryController extends BaseController
{
    public function getQueryForAllCountries(){
        $query = [['$project' => ['name' => 1, 'iso2' => 1, 'phone_code' => 1, 'timezones' => 1]]];
        return $query;
    }

    public function getQueryForAllStates($_id, $country_name){
        $query = [['$match' => ['name' => $country_name, '_id' =>  new ObjectID($_id)]], ['$unwind' => ['path' => '$states']], ['$project' => ['name' => '$states.name', 'state_id' => '$states.id', 'state_code' => '$states.state_code','_id'=>0]]];
        return $query;
    }

    public function getQueryForAllCities($_id,$country_name,$state_name,$state_id){
        $state_id = (int)$state_id;
        // print_r(gettype($state_id));die;
        $query = [['$match' => ['_id' => new ObjectID($_id), 'name' => $country_name]], ['$unwind' => ['path' => '$states']], ['$match' => ['states.name' => $state_name, 'states.id' => $state_id]], ['$unwind' => ['path' => '$states.cities']], ['$project' => ['id' => '$states.cities.id', 'name' => '$states.cities.name', '_id'=>0]]];
        return $query;
    }

    public function getDetailsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $userId = $this->di->getUser()->id ?? null;
        // print_r(['raw'=>$rawBody,'user_id'=>$userId]);die;
        // print_r(gettype($rawBody['state_id']));die;
        $query = '';

        if(!empty($userId)) {
            if(isset($rawBody['iso2'])) {
                $query = [['$match' => ['iso2' => $rawBody['iso2'], 'iso2' => $rawBody['iso2']]],['$project' => ['states'=>0]]];
                // print_r($query);
                // die('sdf');
            }
            else {
                if(isset($rawBody['country_name']) && isset($rawBody['_id']) && isset($rawBody['state_name']) && isset($rawBody['state_id'])){
                $query = $this->getQueryForAllCities($rawBody['_id'],$rawBody['country_name'],$rawBody['state_name'],$rawBody['state_id']);
                }
                else if(isset($rawBody['country_name']) && isset($rawBody['_id'])){
                $query = $this->getQueryForAllStates($rawBody['_id'],$rawBody['country_name']);
                }
                else {
                $query = $this->getQueryForAllCountries();
                }
            }

            // print_r($query);die;
            $collection = $this->di->getObjectManager()->get("\App\Core\Models\BaseMongo")->getCollectionForTable("mycollection");
            $data = $collection->aggregate($query);
            // print_r($data->toArray());die;
            return $this->prepareResponse(['success' => true, 'message' =>"Data fetched successfully.", 'data'=>$data->toArray(),'query'=>$query]);

            // $updateUser = $collection->updateOne(['user_id' => $userId],['$set'=>['freshchat_restore_id' => $rawBody['freshchat_restore_id']]]);
            // return $this->prepareResponse(['success' => true, 'message' => 'Freshchat restore_id updated successfully','restore_id' => $rawBody['freshchat_restore_id']]);
        }

        return $this->prepareResponse(['success' => true, 'message' =>"User ID missing"]);
    }
}