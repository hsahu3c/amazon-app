<?php

namespace App\Connector\Components\Profile;

use App\Core\Models\BaseMongo;

class PartialChunkHelper extends BaseMongo
{
    // profile_type = default | manual | query
    public $user_id = '';

    public $target = "";

    public $source = "";

    public $shopIds = [];

    public function init($data = []): void
    {
        if (isset($data['user_id'])) {
            $this->user_id = $data['user_id'];
        } else {
            $this->user_id = $this->di->getUser()->id;
        }

        $this->target = $this->di->getRequester()->getTargetId();
        $this->source = $this->di->getRequester()->getSourceId();
    }

    public function updateToMongoInstance(&$data, $key): void
    {
        if (!($data[$key] instanceof \MongoDB\BSON\ObjectID)) {
            $data[$key] = new \MongoDB\BSON\ObjectId(isset($data[$key]['$oid']) ? $data[$key]['$oid'] : $data[$key]);
        }
    }

    public function getCollectionForTable($table)
    {
        //        $this->setCollection($table); //before
        $collection = $this->getCollection($table);
        return $collection;
    }

    public function idValid($id)
    {
        if (isset($id['$oid'])) {
            $id = $id['$oid'];
        }

        return $id instanceof \MongoDB\BSON\ObjectID
            || preg_match('/^[a-f\d]{24}$/i', $id);
    }

    public function getProfileChunk($data)
    {

        if (isset($data['ids']) && count($data['ids']) > 0) {
            $preparedIds = [];
            foreach ($data['ids'] as $v) {
                if ($this->idValid($v)) {
                    array_push($preparedIds, new \MongoDB\BSON\ObjectId($v));
                }
            }

            $condition[] = ['$match' => [
                '_id' => ['$in' => $preparedIds]
            ]];
            // die(json_encode($condition));
            $profileArray = $this->getCollectionForTable('profile')->aggregate($condition)->toArray();
            return ['success' => true, 'data' => $profileArray];
        }

        return ['success' => false, 'message' => 'ids missing'];
    }

    public function validatePayload($dataTosave)
    {
        if (!isset($dataTosave['type'])) {
            return ['success' => false, 'message' => 'type missing', 'code' => 'type_missing'];
        }

        if (!isset($dataTosave['_id'])) {
            return ['success' => false, 'message' => '_id missing', 'code' => '_id_missing'];
        }

        if (!$this->idValid($dataTosave['_id'])) {
            return ['success' => false, 'message' => 'invalid id', 'code' => 'invalid_id'];
        }

        return ['success' => true];
    }

    public function savePartialChunk($data)
    {
        $model = $this->di->getConfig()->connectors->get($this->di->getRequester()->getTargetName())->get('source_model');
        if ((method_exists($this->di->getObjectManager()->get($model), "modifyPartialChunkParams"))) {
           $data =  $this->di->getObjectManager()->get($model)->modifyPartialChunkParams($data);
        }

        // die('sdf');
        $this->init($data);
        if (isset($data['refresh']) && $data['refresh']) {
            return $this->refreshProfile($data);
        }

        $dataTosave = $data['data'];
        $dataTosave['updated_at']=date('c');
        $delete = false;
        $validation = $this->validatePayload($dataTosave);
        if (!$validation['success']) {
            return $validation;
        }

        if (isset($data['delete']) && $data['delete']) {
            $delete = true;
        }

        if (isset($dataTosave['manual_product_ids'])) {
            return $this->updatePartialProduct($dataTosave, $delete);
        }

        return $this->saveChunk($dataTosave);
    }

    public function saveChunk($dataTosave)
    {
        $profileTable = $this->getCollectionForTable('profile');
        $this->updateToMongoInstance($dataTosave, '_id');
        $res = $profileTable->updateOne(['_id' => $dataTosave['_id'], 'type' => $dataTosave['type']], ['$set' => $dataTosave]);

        return ['success' => true, 'message' => 'Saved Successfully', 'data' => [
            'id saved' => $dataTosave['_id'], 'mongo_res' => $res
        ]];
    }

    public function updatePartialProduct($data, $delete)
    {
        $profileTable = $this->getCollectionForTable('profile');
        $profileData = $profileTable->find([
            '_id' => new \MongoDB\BSON\ObjectId($data['_id']),
            'type' => 'profile',
            'user_id' => $this->user_id,
            'shop_ids' => ['$elemMatch' => ['source' => $this->source, 'target' => $this->target]],
            // 'shop_ids.source' => $this->source,
            // 'shop_ids.target' => $this->target
        ])->toArray();

        if (count($profileData) == 0) {
            return ['success' => false, 'message' => "profile doest not exists", 'code' => "profile_not_exists"];
        }

        if ($this->checkForProfileSelectionType($profileData)) {
            if (count($data['manual_product_ids']) > 0) {
                if ($delete) {
                    return $this->deleteProfiles($data);
                }

                return $this->updateProfileAndProducts($profileData[0], $data['manual_product_ids']);
            }

            return ['success' => true, "message" => "data updated successfully"];
        }

        return ['success' => false, 'message' => 'operation is permitted for manual selection profile only', 'code' => "operation_not_permitted"];
    }

    public function deleteProfiles($data)
    {
        $profileTable = $this->getCollectionForTable('profile');
        $productTable = $this->getCollectionForTable("product_container");
        $refineTable = $this->getCollectionForTable("refine_product");

        // profile
        $profileTable->updateOne(['_id' => new \MongoDB\BSON\ObjectId($data['_id']), 'type' => 'profile'],
        [
            '$pull' => ['manual_product_ids' => ['$in' => $data['manual_product_ids']]]
        ]);
        $profileTable->updateOne(['_id' => new \MongoDB\BSON\ObjectId($data['_id']), 'type' => 'profile'],
        [
            '$set'=>['updated_at'=>date('c')]
        ]);

        // product_container
        $productTable->updateMany(
            [
                'container_id' => ['$in' => $data['manual_product_ids']],
                'shop_id' => $this->source,
                'user_id' => $this->user_id
            ],
            ['$pull' => ['profile' => ['target_shop_id' => $this->target, 'profile_id' => new \MongoDB\BSON\ObjectId($data['_id'])]]]
        );

        // refine_product
        $refineTable->updateMany([
            'container_id' => ['$in' => $data['manual_product_ids']],
            'profile.profile_id' => new \MongoDB\BSON\ObjectId($data['_id']),
            'user_id' => $this->user_id,
            'source_shop_id' => $this->source,
            'target_shop_id' => $this->target
        ], ['$unset' => ['profile' => 1]]);

        $assignedProfiles = [new \MongoDB\BSON\ObjectId($data['_id'])];
        $this->handleProfileUpdateSQS($assignedProfiles,'delete',new \MongoDB\BSON\ObjectId($data['_id']));
        return ['success' => true, "message" => "profile deleted successfully"];
    }

    public function updateProfileAndProducts($profileData, $data)
    {
        // die(json_encode($data));
        // die('sdfds');
        $profileTable = $this->getCollectionForTable('profile');
        $productTable = $this->getCollectionForTable("product_container");
        $refineTable = $this->getCollectionForTable("refine_product");
        $productContainerData = [
            'profile_id' => $profileData['_id'],
            'profile_name' => $profileData['name'],
            'target_shop_id' => $this->target,
            'type' => 'manual'
        ];
        $refineContainerData = [
            'profile_id' => $profileData['_id'],
            'profile_name' => $profileData['name'],
            'type' => 'manual'
        ];
        $manual_product_ids = array_merge(json_decode(json_encode($profileData['manual_product_ids']), true), $data);
        $manual_product_ids = array_values(array_unique($manual_product_ids));

        // profile
        $profiles = $profileTable->find([
            'type' => 'profile', 'user_id' => $this->user_id,
            'shop_ids' => ['$elemMatch' => ['source' => $this->source, 'target' => $this->target]],
            'manual_product_ids' => ['$in' => $data]
        ])->toArray();

        $assignedProfiles = [];
        array_push($assignedProfiles, $profileData['_id']);
        if (count($profiles) > 0) {
            foreach ($profiles as $v) {
                array_push($assignedProfiles, $v['_id']);
            }
        }

        $profileTable->updateMany(
            [
                'type' => 'profile',
                'user_id' => $this->user_id,
                'shop_ids' => ['$elemMatch' => ['source' => $this->source, 'target' => $this->target]]
            ],
            [
                '$pull' => ['manual_product_ids' => ['$in' => $data]]
            ]
        );

        $profileTable->updateOne(['_id' => $profileData['_id'], 'type' => 'profile'], ['$set' => [
            'manual_product_ids' => $manual_product_ids,
            'updated_at'=>date('c')
        ]]);

        $productTable->updateMany(
            [
                'container_id' => ['$in' => $manual_product_ids],
                'shop_id' => $this->source,
                'user_id' => $this->user_id
            ],
            ['$pull' => ['profile' => ['target_shop_id' => $this->target]]]
        );
        $productTable->updateMany(
            [
                'container_id' => ['$in' => $manual_product_ids],
                'shop_id' => $this->source,
                'user_id' => $this->user_id,
                'visibility' => 'Catalog and Search',
                // 'profile'=>['target_shop_id' => $this->target, 'profile_id' => ['$ne'=>$profileData['_id']]]
                // 'profile.profile_id'=>['$ne'=>$profileData['_id']],
                // 'profile.target_id'=>['$ne'=>$this->target]
            ],
            ['$push' => ['profile' => $productContainerData]]
        );


        // // refine_product
        $refineTable->updateMany([
            'container_id' => ['$in' => $data],
            'user_id' => $this->user_id,
            'source_shop_id' => $this->source,
            'target_shop_id' => $this->target,
            'profile.profile_id' => ['$ne' => $profileData['_id']],
        ], ['$set' => ['profile' => $refineContainerData]]);

        $this->handleProfileUpdateSQS($assignedProfiles,'update',$profileData['_id']);
        return ['success' => true, 'message' => "data updated successfully"];
    }

    public function checkForProducts($data)
    {
        $manual = false;
        foreach ($data as $v) {
            if (count($v['manual_product_ids']) > 1) {
                $manual = true;
            }
        }

        return $manual;
    }

    public function checkForProfileSelectionType($data)
    {
        $manual = false;
        foreach ($data as $v) {
            if (strlen($v['query']) == 0) {
                $manual = true;
            }
        }

        return $manual;
    }


    public function getTargetsAndSources($data)
    {
        $shops = ['sources' => [], 'targets' => []];
        if (isset($data['sources']) && count($data['sources']) > 0) {
            $shops['sources'] = $data['sources'];
        } else {
            $shops['sources'] = [$this->source];
        }

        if (isset($data['targets']) && count($data['targets']) > 0) {
            $shops['targets'] = $data['targets'];
        } else {
            $shops['targets'] = [$this->target];
        }

        return $shops;
    }

    public function setShopsQueryArray($shops): void
    {
        foreach ($shops['sources'] as $value) {
            foreach ($shops['targets'] as $v) {
                $this->shopIds[] = ['source' => $value, 'target' => $v];
            }
        }
    }


    public function handleProfileUpdateSQS($data = [],$operationType,$profileId)
    {

        $newData = [
            'user_id' => $this->user_id,
            'class_name' => '\App\Connector\Components\Profile\PartialChunkHelper',
            'method_name' => 'sendProfileUpdatedDataToMarketplace',
            'params' =>  [
                'marketplace' => $this->di->getRequester()->getTargetName() ?? "",
                'data' => [
                    'operation_type'=>$operationType,
                    'profile_id'=>$profileId,
                    'user_id' => $this->user_id,
                    'shop_details' => [
                        'source' => [
                            "marketplace" => $this->di->getRequester()->getSourceName() ?? '',
                            "shopId" => $this->di->getRequester()->getSourceId() ?? ''
                        ],
                        'target' => [
                            "marketplace" => $this->di->getRequester()->getTargetName() ?? '',
                            "shopId" => $this->di->getRequester()->getTargetId() ?? ''
                        ],
                    ],
                    'assigned_profiles' => $data
                ],
            ],

            'worker_name' => 'update_product_profile',
        ];

        $SqsObject = new  \App\Connector\Components\Profile\SQSWorker;

        $SqsObject->CreateWorker($newData, false);
        return true;
        // return ['success' => true, 'message' => 'Profile saved successfully, will reflect same in products within few minutes', 'profile_id' => $savedProfileId];

    }

    public function sendProfileUpdatedDataToMarketplace($sqsData)
    {
        $params = $sqsData['data']['params'];
        $model = $this->di->getConfig()->connectors->get($params['marketplace'])->get('source_model');
        if ((method_exists($this->di->getObjectManager()->get($model), "afterProfileSave"))) {
            $this->di->getObjectManager()->get($model)->afterProfileSave($params['data']);
        }

        return true;
    }

    // refresh profile
    public function refreshProfile($data)
    {
        if (!isset($data['profile_id'])) {
            return ['success' => false, "message" => 'profile_id missing'];
        }

        $updateAll = isset($data['updateAll']) ? $data['updateAll'] : false;
        $refine = isset($data['useRefinProduct']) && $data['useRefinProduct'] ? true : false;
        $shops = $this->getTargetsAndSources($data);
        $this->setShopsQueryArray($shops);
        $this->updateToMongoInstance($data, "profile_id");
        $profile = $this->getCollectionForTable("profile")->find(['_id' => $data['profile_id']])->toArray();

        if (count($profile) > 0) {
            if ($profile[0]['query'] !== "") {
                $dataToPrepareQuery = [
                    "query" => $profile[0]['query'],
                    "user_id" => $profile[0]['user_id'],
                    "overWriteExistingProducts" => $updateAll ? $profile[0]["overWriteExistingProducts"] : false
                ];
                $filterAndType = $this->getToUpdateQuery($dataToPrepareQuery, $shops, $refine);

                if ($filterAndType) {
                    $count = $this->getCollectionForTable($refine ?  'refine_product' : 'product_container')->count($filterAndType['filter']);
                } else {
                    $count = 0;
                }

                if ($filterAndType) {
                    $this->getCollectionForTable("profile")->updateOne(['_id' => $data['profile_id']], ['$set' => ['total_count' => $count, 'product_update_in_progress' => true]]);
                    $profileHelper = new \App\Connector\Components\Profile\ProfileHelper;
                    if ($refine) {
                        return $profileHelper->updateRefineProductProfile(
                            $filterAndType,
                            $shops['targets'],
                            $data['profile_id'],
                            [
                                'name' => $profile[0]['name'],
                                'count' => $count,
                                'saveProfileEventParams' => [],
                                'userId' => $this->user_id,
                                'checkProfiles' => false
                            ]
                        );
                    }

                    $profileHelper->updateProductProfile($filterAndType['filter'], $filterAndType['type'], $shops['targets'], $data['profile_id'], $profile[0]['name']);
                }
            } else {
                return ['success' => false, "message" => "operation is permitted for advance selection only"];
            }
        } else {
            return ['success' => false, 'message' => "no profile found!!!"];
        }

        return ['sucess' => true, "message" => "profile updation in progress and will reflect in sometime"];
    }

    public function getToUpdateQuery($data, $shopIds, $refineProducts = false)
    {

        $shopIdsForQuery = $this->prepareShopIdsForQuery($this->shopIds);
        if ($refineProducts) {
            if (isset($data['query']) && $data['query'] !== "") {
                return ['filter' => (new \App\Connector\Components\Profile\GetQueryConverted)->extractRuleForRefineData($data, 'find', $shopIds, $shopIdsForQuery), 'type' => 'query'];
            }
        }

        if (isset($data['query']) && $data['query'] !== "") {
            return ['filter' => (new \App\Connector\Components\Profile\GetQueryConverted)->extractRuleFromProfileData($data, 'find', $shopIds, $refineProducts), 'type' => 'query'];
        }

        if (isset($data['manual_product_ids']) && count($data['manual_product_ids'])) {
            $orCond = [];
            foreach ($data['manual_product_ids'] as $v) {
                $orCond[] = ['container_id' => $v];
            }

            $filter = $refineProducts ? ['user_id' => $this->user_id, '$and' => [['$or' => $orCond], ['$or' => $shopIdsForQuery['$or']]]] : ['user_id' => $this->user_id, '$or' => $orCond];


            if (isset($data['overWriteExistingProducts']) && !$data['overWriteExistingProducts'] && isset($shopIds['targets'])) {
                $keyDy = $refineProducts ? ['profile.profile_id' => ['$exists' => false]] : ['profile.target_shop_id' => ['$nin' => $shopIds['targets']]];
                $filter = $filter + $keyDy;
            }

            return ['filter' => $filter, 'type' => 'manual'];
        }

        return false;
    }

    public function prepareShopIdsForQuery($shopIds)
    {
        $tempIds = [];
        foreach ($shopIds as $shop) {
            $tempIds['$or'][] = ['$and' => [['source_shop_id' => $shop['source'], 'target_shop_id' => $shop['target']]]];
        }

        return $tempIds;
    }

    // unset and remove partial chunk
    public function unsetOrDeleteChunk($data){
        $profile = $this->getCollectionForTable('profile');
        if(!isset($data['id']) || !$this->idValid($data['id'])){
            return ['success'=>false,'messsage'=>"Id missing or invalid"];
        }

        if(isset($data['unset']) && count($data['unset'])>0){
            $condition = [];
            foreach($data['unset'] as $v){
                $condition[$v]=true;
            }

            $profile->updateOne(['_id'=>new \MongoDB\BSON\ObjectId($data['id'])],['$unset'=>$condition]);
            return ['success'=>true,'message'=>"key unset successfully"];
        }

        if(isset($data['delete']) && $data['delete']){
            $profile->deleteOne(['_id'=>new \MongoDB\BSON\ObjectId($data['id'])]);
            return ['success'=>true,'message'=>'data deleted successfully'];
        }

        return ['success'=>false,'message'=>'unset or delete are required params'];
    }
}