<?php

namespace App\Connector\Components\Profile;

use App\Core\Models\BaseMongo;
use function GuzzleHttp\json_decode;

class ProfileHelper extends BaseMongo
{
    public $idsToArrayMap = [];

    public $maintainAttributeIds = [];

    protected $table = 'profile';

    public $sourceMarketplace;

    public $userId = '';

    private $shopIds = [];

    public function init($data = []): void
    {
        if (isset($data['user_id'])) {
            $this->userId = $data['user_id'];
        } else {
            $this->userId = $this->di->getUser()->id;
        }
    }

    public function setShopIds(array $data)
    {
        $this->shopIds = $data;
    }

    /*
    @profile save and update start
     */
    public function validateData($data)
    {
        return (isset($data['name']) && isset($data['category_id']));
    }

    public function updateToMongoInstance(&$data, $key): void
    {
        if (!($data[$key] instanceof \MongoDB\BSON\ObjectID)) {
            $data[$key] = new \MongoDB\BSON\ObjectId($data[$key]['$oid']);
        }
    }

    public function saveChunk(&$data)
    {
        $data['user_id'] = $this->userId;
        $profileTable = $this->getCollectionForTable('profile');
        $data['updated_at'] = date('c');
        if (isset($data['_id'])) {
            $this->updateToMongoInstance($data, '_id');
            if ($data == $this->checkPresentReturnValue((string) $data['_id'], $this->idsToArrayMap)) {
                return $data['_id'];
            }
        } else {
            $data['created_at'] = date('c');
        }

        if (isset($data['profile_id'])) {
            $this->updateToMongoInstance($data, 'profile_id');
        }

        $tempData = $data;
        if ($tempData['type'] == 'attribute') {

            if (isset($tempData['_id'])) {
                // echo(json_encode($data['_id']));

                $id = json_decode((json_encode($tempData['_id'])), true)['$oid'];
                $attributtes = $this->idsToArrayMap[$id] ?? [];
                if ($attributtes && count($attributtes) > 0) {
                    foreach ($tempData['data'] as $k => $v) {
                        $attributtes['data'][$k] = $v;
                    }

                    $tempData['data'] = $attributtes['data'];
                }
            }
        }

        if ($tempData['type'] == 'profile') {
            $tempData['value_mapping'] = false;
        }

        if (isset($tempData['_id'])) {
            $mongo = $profileTable->updateOne(['_id' => $tempData['_id']], ['$set' => $tempData]);
        } else {
            $mongo = $profileTable->insertOne($tempData);
        }

        return isset($tempData['_id']) ? $tempData['_id'] : $mongo->getInsertedId();
    }

    public function saveProfileData($data)
    {
        $profileId = $this->saveChunk($data['profile']);
        unset($data['profile']);
        foreach ($data as $value) {
            foreach ($value as $val) {
                $val['profile_id'] = $profileId;
                $this->saveChunk($val);
            }
        }

        return $profileId;
    }

    public function &checkPresentReturnValue($key, &$data)
    {
        $returnval = [];
        if (isset($data[$key])) {
            $returnval = &$data[$key];
        }

        return $returnval;
    }

    public function checkObjectPresentInArray($obj, $arr, $keyChecking)
    {

        unset($obj['_id']);
        unset($obj['type']);
        unset($obj['user_id']);
        foreach ($arr as $value) {
            $id = $value['_id'];

            unset($value['type']);
            if ($keyChecking == 'attributes_mapping') {
                $value = $value['data'];
            }

            unset($value['_id']);
            unset($value['user_id']);
            if ($obj == $value) {
                return $id;
            }
        }

        return false;
    }

    public function attributeMappingStructured($attributeData, &$dataToUpdateProfileId, &$mainData)
    {
        if (count($attributeData) == 0) {
            return false;
        }

        $dataToSend = [];
        $dataToSend['type'] = 'attribute';
        $dataToSend['data'] = $attributeData;
        $CheckIdPresent = $this->checkObjectPresentInArray($attributeData, $this->checkPresentReturnValue('attributes_mapping', $dataToUpdateProfileId), 'attributes_mapping');
        if (isset($attributeData['_id'])) {
            $dataToSend['_id'] = $attributeData['_id'];
            $this->updateToMongoInstance($dataToSend, '_id');
            $dataToSend['data']['_id'] = $dataToSend['_id'];
        }

        $dataToSend['_id'] = $CheckIdPresent ? $CheckIdPresent : $this->saveChunk($dataToSend);

        $attrInfos = &$dataToUpdateProfileId['attributes_mapping'];

        if (isset($attrInfos[(string) $dataToSend['_id']]) && !$CheckIdPresent) {
            unset($dataToSend['_id']);
            unset($dataToSend['data']['_id']);
            $dataToSend['_id'] = $this->saveChunk($dataToSend);
            $dataToSend['data']['_id'] = $dataToSend['_id'];
            $CheckIdPresent = $dataToSend['_id'];
        }

        $attrInfos[(string) $dataToSend['_id']] = $dataToSend;
        $mainData['attributes_mapping'] = $dataToSend['_id'];
    }

    public function warehousesAndShopsMappingStructured(&$warehouseOrShopData, &$dataToUpdateProfileId, $keyToMap): void
    {
        $dataToSend = $warehouseOrShopData;
        $dataToSend['type'] = $keyToMap == 'shops' ? 'shop' : 'warehouse';
        $CheckIdPresent = $this->checkObjectPresentInArray($warehouseOrShopData, $this->checkPresentReturnValue($keyToMap, $dataToUpdateProfileId), $keyToMap);
        if (isset($dataToSend['_id'])) {
            $this->updateToMongoInstance($dataToSend, '_id');
        } else {
        }

        $nameId = $keyToMap == 'shops' ? 'shop_id' : 'warehouse_id';
        if ($dataToSend['type'] == 'warehouse' && !isset($dataToSend['warehouse_id'])) {
            $dataToSend[$nameId] = "dummy";
        }

        $dataToSend['_id'] = $CheckIdPresent ? $CheckIdPresent : $this->saveChunk($dataToSend);
        $warehouseOrShopData = [
            $nameId => $dataToSend[$nameId],
            'id' => $dataToSend['_id'],
        ];
        !$CheckIdPresent && $dataToUpdateProfileId[$keyToMap][] = $dataToSend;
    }

    public function iterateTillSourceWarehouse(&$data, &$dataToUpdateProfileId): void
    {
        $dataToIterateOn = ['targets', 'shops', 'sources', 'warehouses'];
        for ($i = 0; $i < 4; $i++) {
            $keyItem = $dataToIterateOn[$i];
            if (isset($data[$keyItem])) {
                $val = &$data[$keyItem];
                foreach ($val as &$value) {
                    $this->attributeMappingStructured($this->checkPresentReturnValue('attributes_mapping', $value), $dataToUpdateProfileId, $value);
                    $this->iterateTillSourceWarehouse($value, $dataToUpdateProfileId);

                    if ($keyItem == 'warehouses' || $keyItem == 'shops') {
                        $this->warehousesAndShopsMappingStructured($value, $dataToUpdateProfileId, $keyItem);
                    }
                }

                if ($keyItem == 'warehouses' || $keyItem == 'shops') {
                    $val = array_values($val);
                }

                break;
            }
        }
    }

    public function getShoppIds($data, $key, $target_id = null)
    {
        $targetIds = [];
        $sourceIds = [];
        if (isset($data[$key])) {
            foreach ($data[$key] as $value) {
                foreach ($value['shops'] as $sValue) {
                    $targetIds[] = $sValue['shop_id'];
                    if ($target_id) {
                        $this->shopIds[] = [
                            'source' => $sValue['shop_id'],
                            'target' => $target_id,
                        ];
                    }

                    if (isset($sValue['warehouses'][0]['sources'])) {
                        $sourceIds = array_unique(array_merge($this->getShoppIds($sValue['warehouses'][0], 'sources', $sValue['shop_id'])['targets'], $sourceIds));
                    }
                }
            }
        }

        return ['targets' => $targetIds, 'sources' => $sourceIds];
    }

    public function pushProfileIdArr($name, $targetIds, $savedProfileId, $type = 'query')
    {
        $pushArr = [];

        foreach ($targetIds as $value) {
            $pushArr[] = [
                'profile_id' => $savedProfileId,
                'profile_name' => $name,
                'target_shop_id' => $value,
                'type' => $type,
            ];
        }

        return $pushArr;
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

            $filter = $refineProducts ? ['user_id' => $this->userId, '$and' => [['$or' => $orCond], ['$or' => $shopIdsForQuery['$or']]]] : ['user_id' => $this->userId, '$or' => $orCond];

            if (isset($data['overWriteExistingProducts']) && !$data['overWriteExistingProducts'] && isset($shopIds['targets'])) {
                $keyDy = $refineProducts ? ['profile.profile_id' => ['$exists' => false]] : ['profile.target_shop_id' => ['$nin' => $shopIds['targets']]];
                $filter = $filter + $keyDy;
            }

            return ['filter' => $filter, 'type' => 'manual'];
        }

        return false;
    }

    public function compareProfileFilterData($profileData, $data)
    {
        if (isset($data['manual_product_ids']) && isset($data['query'])) {
            if ($profileData[0]['query'] == $data['query'] && json_encode($profileData[0]['manual_product_ids']) == json_encode($data['manual_product_ids']) && $profileData[0]['name'] == $data['name']) {
                return false;
            }
        } else if (isset($data['manual_product_ids'])) {
            if (json_encode($profileData[0]['manual_product_ids']) == json_encode($data['manual_product_ids']) && $profileData[0]['name'] == $data['name']) {
                return false;
            }
        } else if (isset($data['query']) && $profileData[0]['name'] == $data['name']) {
            if ($profileData[0]['query'] == $data['query']) {
                return false;
            }
        }

        return true;
    }

    public function saveProfile($params)
    {

        $this->init($params);
        $data = $params['data'];
        $donotUnAssignPrd = $params['donotUnAssignPrd'] ?? false;

        if (!$this->validateData($data)) {
            return ['success' => false, 'message' => 'category_id or name missing', 'code' => 'data_missing'];
        }

        $shopIds = $this->getShoppIds($data, 'targets');
        $targetIds = $shopIds['targets'];
        $updateQyery = true;
        $profileData = [];
        if (isset($data['_id'])) {
            $this->updateToMongoInstance($data, '_id');
            $profileData = $this->getProfileFormattedDataAndIdsMapping($data['_id'], "");
            $updateQyery = $this->compareProfileFilterData($profileData, $data);

            if (isset($params['useForceQueryUpdate'])) {
                $updateQyery = $params['useForceQueryUpdate'];
            }

            if ($updateQyery && !$donotUnAssignPrd) {
                //delete profile from products
                $this->getCollectionForTable('product_container')->updateMany(['profile.profile_id' => $data['_id'], 'user_id' => $this->userId], ['$pull' => ['profile' => ['target_shop_id' => ['$in' => $targetIds]]]]);
                $this->getCollectionForTable('refine_product')->updateMany(['profile.profile_id' => $data['_id'], 'user_id' => $this->userId], ['$unset' => ['profile' => 1]]);
            }
        }

        $dataToUpdateProfileId = [];

        $this->attributeMappingStructured($this->checkPresentReturnValue('attributes_mapping', $data), $dataToUpdateProfileId, $data);

        $this->iterateTillSourceWarehouse($data, $dataToUpdateProfileId);

        $refine = isset($params['useRefinProduct']) && $params['useRefinProduct'] ? true : false;

        $data['type'] = 'profile';

        $data['shop_ids'] = $this->shopIds;

        $filterAndType = $this->getToUpdateQuery($data, $shopIds, $refine);

        $affectedProfiles = [];
        if(isset($data['partialTemplate'])){
            $partialTemplate = $data['partialTemplate'];
        }
        if ($filterAndType) {
            $count = $params['count'] ?? $this->getCollectionForTable($refine ? 'refine_product' : 'product_container')->count($filterAndType['filter']);
            // i need to check profile exists, then give me distinct profiles from products
            $affectedProfiles = $params['return_affected_profiles'] ? $this->getCollectionForTable($refine ? 'refine_product' : 'product_container')->distinct('profile.profile_id', $filterAndType['filter']) : [];
        } else {
            $count = 0;
        }

        $data['total_count'] = $count;
        $saveProfileEventParams = $this->formatDataToSend($data, $profileData, $params);
        $saveProfileEventParams['affected_profiles'] = $affectedProfiles ?? [];
        $checkProfiles = $this->checkProfile();
        $usingDynamicProfile = $this->di->getObjectManager()->get(DynamicProfile::class)->enabled();
        if ($updateQyery && $filterAndType) {
            $data['product_update_in_progress'] = true;
            $dataToUpdateProfileId['profile'] = $data;
            $savedProfileId = $this->saveProfileData($dataToUpdateProfileId);
            if ($usingDynamicProfile) {
                return [
                    'success' => true,
                    'message' => 'Updated successfully',
                    'profile_id' => $savedProfileId,
                ];
            }
            $saveProfileEventParams['profile_id'] = $savedProfileId;
            if ($refine) {
                return $this->updateRefineProductProfile(
                    $filterAndType,
                    $targetIds,
                    $savedProfileId,
                    [
                        'name' => $data['name'],
                        'count' => $count,
                        'saveProfileEventParams' => $saveProfileEventParams,
                        'userId' => $this->userId,
                        'checkProfiles' => $checkProfiles,
                    ],
                    $partialTemplate
                );
            }

            $this->updateProductProfile($filterAndType, $targetIds, $savedProfileId, $data['name']);
        } else {
            $dataToUpdateProfileId['profile'] = $data;
            $savedProfileId = $this->saveProfileData($dataToUpdateProfileId);
            $eventManager = $this->di->getEventsManager();
            $eventManager->fire('application:afterProfileCreated', $this, $params);
            if ($usingDynamicProfile) {
                return [
                    'success' => true,
                    'message' => 'Created successfully',
                    'profile_id' => $savedProfileId,
                ];
            }
            $saveProfileEventParams['profile_id'] = $savedProfileId;
            return $this->profileDataUpdate($saveProfileEventParams);
        }
        $eventManager = $this->di->getEventsManager();
        $eventManager->fire('application:afterProfileCreated', $this, $params);
        return ['success' => true, 'message' => 'Saved Successfully', 'profile_id' => $savedProfileId];
    }

    /*
    @profile save and update end
     */

    public function prepareShopIdsForQuery($shopIds)
    {
        $tempIds = [];
        foreach ($shopIds as $shop) {
            $tempIds['$or'][] = ['$and' => [['source_shop_id' => $shop['source'], 'target_shop_id' => $shop['target']]]];
        }

        return $tempIds;
    }

    public function updateProductProfile($filterAndType, $targetIds, $savedProfileId, $name): void
    {
        $savedProfileId = is_string($savedProfileId) ? new \MongoDB\BSON\ObjectId($savedProfileId) : $savedProfileId;
        $this->getCollectionForTable('product_container')->updateMany($filterAndType['filter'], [
            '$pull' => ['profile' => ['target_shop_id' => ['$in' => $targetIds]]],
        ]);

        $this->getCollectionForTable('product_container')->updateMany($filterAndType['filter'], [
            '$push' => ['profile' => ['$each' => $this->pushProfileIdArr($name, $targetIds, $savedProfileId, $filterAndType['type'])]],
        ]);
    }

    public function updateRefineProductProfile($filterAndType, $targetIds, $savedProfileId, $additionalData, $partialTemplate = null)
    {
        $newData = [
            'user_id' => $additionalData['userId'] ?? $this->userId,
            'class_name' => '\App\Connector\Components\Profile\ProfileHelper',
            'method_name' => 'updateRefineProductProfileSQS',
            'params' => [
                'marketplace' => $this->di->getRequester()->getTargetName() ?? '',
                'partialTemplate' => $partialTemplate,
                'type' => $filterAndType['type'],
                'filter' => $filterAndType['filter'],
                'targetIds' => $targetIds,
                'profileId' => $savedProfileId,
                'name' => $additionalData['name'],
                'user_id' => $additionalData['userId'] ?? $this->userId,
                'checkProfiles' => $additionalData['checkProfiles'],
                'saveProfileEventParams' => $additionalData['saveProfileEventParams'],
            ],
            'worker_name' => 'update_product_profile',
        ];
        $SqsObject = new \App\Connector\Components\Profile\SQSWorker;

        $SqsObject->CreateWorker($newData, false);
        return ['success' => true, 'message' => 'Profile saved successfully, will reflect same in products within few minutes', 'profile_id' => $savedProfileId];
    }

    public function updateRefineProductProfileSQS($sqsData)
    {
        $params = $sqsData['data']['params'];
        $filter = $params['filter'];
        $partialTemplate = $params['partialTemplate'] ?? null;
        $type = $params['type'];
        $targetIds = $params['targetIds'];
        $this->updateToMongoInstance($params, 'profileId');
        $savedProfileId = $params['profileId'];
        $name = $params['name'];
        $checkProfiles = $params['checkProfiles'];
        $marketplace = $params['marketplace'];
        $profiles = [];
        if ($checkProfiles) {
            $filterAggregate = ['$match' => $filter];
            $aggregate = [];
            $aggregate[] = $filterAggregate;
            $aggregate[] = ['$match' => ['profile' => ['$exists' => true]]];
            $aggregate[] = ['$group' => ['_id' => '$profile.profile_id', 'total' => ['$sum' => 1]]];
            $productsProfileCount = $this->getCollectionForTable('refine_product')->aggregate($aggregate)->toArray();
            if (count($productsProfileCount) > 0) {
                foreach ($productsProfileCount as $v) {
                    if (isset($v['_id'])) {
                        array_push($profiles, $v['_id']);
                    }
                }
            }
        }

        $savedProfileId = is_string($savedProfileId) ? new \MongoDB\BSON\ObjectId($savedProfileId) : $savedProfileId;
        $this->runServerSideJS($filter, $targetIds, $name, $savedProfileId, $type,$partialTemplate);
        if ($marketplace) {

            return $this->sendUpdatesToMarketplace($params['saveProfileEventParams'], $marketplace);
        }

        return true;
    }

    public function runServerSideJS($filter, $targetIds, $name, $savedProfileId, $type,$partialTemplate = null)
    {
        $feed_server_side = $this->getCollectionForTable('feed_server_side');
        if($partialTemplate == null){
        $infoR = $feed_server_side->insertOne(['filter' => json_encode($filter, true), 'target_ids' => $targetIds, 'name' => $name, "profileId" => $savedProfileId, 'type' => $type , 'created_at' => date('c')]);
        }
        else{
            $infoR = $feed_server_side->insertOne(['filter' => json_encode($filter, true), 'target_ids' => $targetIds, 'name' => $name, "profileId" => $savedProfileId, 'type' => $type , 'partialTemplate' => $partialTemplate, 'created_at' => date('c')]);  
        }


        $id = (string) $infoR->getInsertedId();

        $idTo = "'{$id}'";

        $mongo_info = $this->di->getConfig()->get('databases')['db_mongo'];
        $host = $mongo_info['host_server_side'];

        $username = $mongo_info['username'];

        $password = $mongo_info['password'];

        $base_path = BP . DS . 'app/code/connector/Components/Profile/UpdateProfile.js';

        $eval = '"var id = ' . $idTo . ' " ';

        $create_command = "mongosh " . $host . ' --username ' . $username . ' -p ' . $password . ' --eval ' . $eval . $base_path;
        microtime(true);
        $execute = shell_exec($create_command);
        microtime(true);

        // if ($end - $start > 1) {
        //     $this->di->getLog()->logContent('refineProfileUpdate = ' . json_encode((['timeStamp' => time(), 'time' => $end - $start, 'filter' => $filter]), true), 'info', 'bulktimeTest.log');
        // }
        return $execute;
    }

    public function formatDataToSend($data, $profileData, $params)
    {
        return [
            'operation_type' => 'update',
            'name' => $data['name'],
            'marketplace' => $this->di->getRequester()->getTargetName() ?? "",
            'old_profile_data' => $profileData,
            'additional_data' => $params['additional_data'] ?? [],
            'updated_profile_data' => $data,
            'shop_details' => [
                'source' => [
                    "marketplace" => $this->di->getRequester()->getSourceName() ?? '',
                    "shopId" => $this->di->getRequester()->getSourceId() ?? '',
                ],
                'target' => [
                    "marketplace" => $this->di->getRequester()->getTargetName() ?? '',
                    "shopId" => $this->di->getRequester()->getTargetId() ?? '',
                ],
            ],
            'user_id' => $this->userId,
        ];
    }

    public function checkProfile()
    {
        $module = $this->di->getRequester()->getTargetName() ?? "";
        $moduleData = $this->di->getConfig()->connectors->get($module) ?
            $this->di->getConfig()->connectors->get($module)->toArray() : false;
        if (
            $moduleData && isset($moduleData["get_assigned_profiles"]) && $moduleData["get_assigned_profiles"] == true && isset($data['overWriteExistingProducts']) &&
            $data['overWriteExistingProducts']
        ) {
            $model = $this->di->getConfig()->connectors->get($module)->get('source_model');
            if ((method_exists($this->di->getObjectManager()->get($model), "afterProfileSave"))) {
                return true;
            }
        }

        return false;
    }

    public function profileDataUpdate($saveProfileEventParams)
    {
        $newData = [
            'user_id' => $this->userId,
            'class_name' => '\App\Connector\Components\Profile\ProfileHelper',
            'method_name' => 'updateProductProfileSQS',
            'params' => $saveProfileEventParams,
            'worker_name' => 'update_profile_data',
        ];

        $SqsObject = new \App\Connector\Components\Profile\SQSWorker;

        $SqsObject->CreateWorker($newData, false);
        return ['success' => true, 'message' => 'Profile saved successfully', 'profile_id' => $saveProfileEventParams['profile_id']];
    }

    public function updateProductProfileSQS($sqsData)
    {
        $params = $sqsData['data']['params'];
        if (!empty($params['marketplace'])) {
            $marketplace = $params['marketplace'];
            return $this->sendUpdatesToMarketplace($params, $marketplace);
        }

        return true;
    }

    public function sendUpdatesToMarketplace($data, $marketplace)
    {
        $model = $this->di->getConfig()->connectors->get($marketplace)->get('source_model');
        if ((method_exists($this->di->getObjectManager()->get($model), "afterProfileSave"))) {
            $this->di->getObjectManager()->get($model)->afterProfileSave($data);
        }

        return true;
    }

    public function mongoUpdateProductsProfile($filter, $targetIds, $name, $savedProfileId, $type)
    {
        $this->getCollectionForTable('refine_product')->updateMany($filter, [
            '$set' => ['profile' => ['profile_id' => $savedProfileId, 'profile_name' => $name, 'type' => $type]],
        ]);
        unset($filter['$and'][1]);
        $this->getCollectionForTable('product_container')->updateMany($filter, [
            '$pull' => ['profile' => ['target_shop_id' => ['$in' => $targetIds]]],
        ]);

        $this->getCollectionForTable('product_container')->updateMany($filter, [
            '$push' => ['profile' => ['$each' => $this->pushProfileIdArr($name, $targetIds, $savedProfileId, $type)]],
        ]);

        return true;
    }

    /*
    @profile get start
     */

    public function getProfileFormattedDataAndIdsMapping($idStr, $name = "", $data = [])
    {
        $id = $idStr != "" ? new \MongoDB\BSON\ObjectId($idStr) : "";

        if ($idStr == "") {
            if (isset($data['all_profile_name'])) {
                $sort_order = 1;
                $sort_by = "_id";
                if (isset($data['sort_order'])) {
                    $sort_order = $data['sort_order'] === "desc" ? -1 : 1;
                }

                if (isset($data['sort_by'])) {
                    $sort_by = strlen($data['sort_by']) > 0 ? $data['sort_by'] : "_id";
                }

                return $this->getAllProfilesName($sort_order, $sort_by);
            }

            return $this->getProfileDataByName($name, $data);
        }

        return $this->getProfileByProfileId($id, $data);
    }

    /**
     * getProfileByProfileId($id)
     *
     * @param MongoDB\BSON\ObjectID() $id
     * @return array
     */
    public function getProfileByProfileId($id, $data = [])
    {
        $condition[] = [
            '$match' => [
                '$or' => [
                    ['profile_id' => $id],
                    ['_id' => $id],
                ],
            ],
        ];
        $condition[] = $this->getAggregateForLookup($data);
        $profileArray = $this->getCollectionForTable('profile')->aggregate($condition)->toArray();
        $formattedData = [];

        foreach ($profileArray as $value) {
            if ($value['type'] == 'profile') {
                $formattedData[] = $value;
            }

            $id = (string) $value['_id'];
            $this->idsToArrayMap[$id] = $value;
        }

        return $formattedData;
    }

    /**
     * getAllProfilesName()
     *
     * function to return all profiles name
     *
     * @return array
     */
    public function getAllProfilesName($sort, $sortBy)
    {
        $condition[] = [
            '$match' => [
                'type' => "profile",
                'user_id' => $this->userId,
                'shop_ids' => [
                    '$elemMatch' => [
                        'target' => $this->di->getRequester()->getTargetId(),
                        'source' => $this->di->getRequester()->getSourceId(),
                    ],
                ],
            ],
        ];
        $condition[] = ['$sort' => [$sortBy => $sort]];
        $condition[] = [
            '$project' => [
                '_id' => 1,
                'name' => 1,
                'manual_product_ids' => 1,
                'query' => 1,
                'category_id' => 1,
                'fulfillment_type' => 1,
                'partialTemplate' => 1,                
            ],
        ];

        $profileArray = $this->getCollectionForTable('profile')->aggregate($condition)->toArray();
        $arr = [];
        $anotherArr = [];
        foreach ($profileArray as $v) {
            $arr[] = $v['name'];
            $anotherArr[] = ['name' => $v['name'], 'type' => (isset($v['query']) && trim($v['query']) === "") ? 'manual' : 'query', 'manual_product_ids' => $v['manual_product_ids'], '_id' => $v['_id'], 'fulfillment_type' => $v['fulfillment_type'] ?? '', 'category_id' => $v['category_id'], 'partialTemplate' => $v['partialTemplate'] ?? false];
        }

        return ['arr' => $arr, 'anotherArr' => $anotherArr];
    }

    /**
     * getProfileDataByName
     *
     * function to run aggregate when ids is not given
     *
     * @param string $name
     * @param [array] $data
     * @return array
     */
    public function getProfileDataByName($name = "", $data = [])
    {
        if ((isset($data['next']) || isset($data['prev'])) && isset($data['active_page'])) {
            return ['success' => false, 'message' => "active_page and next or prev cannot be sent together"];
        }

        if (isset($data['next']) && isset($data['prev'])) {
            return ['success' => false, 'message' => "next and prev cannot be sent together"];
        }

        $aggregateData = $this->getAggregateForProfileDataByName($name, $data);
        $condition = $aggregateData['aggregate'];

        if (isset($data['active_page'])) {
            $rows = $this->getCollectionForTable('profile')->aggregate($condition)->toArray();
            $responseData = [
                'activePage' => $data['active_page'],
                'current_count' => count($rows),
                'rows' => $rows,
            ];
            return $responseData;
        }

        $helper = new \App\Connector\Components\PaginationHelper;
        return $helper->getNextPrevData($aggregateData, $data, 'profile');
    }

    /**
     * getAggregateForLookup
     *
     * function to return aggregate query for getProfileDataByName function
     */
    public function getAggregateForLookup($data)
    {
        if (isset($data['useRefinProduct']) && $data['useRefinProduct']) {
            return [
                '$lookup' => [
                    'from' => 'refine_product',
                    'as' => 'product_count',
                    'localField' => '_id',
                    'foreignField' => 'profile.profile_id',
                    'let' => ['profile_id' => '$_id', 'type' => '$type'],
                    'pipeline' => $this->getLookupPipeline(),
                ],
            ];
        }

        return [
            '$lookup' => [
                'from' => 'product_container',
                'as' => 'product_count',
                'localField' => '_id',
                'foreignField' => 'profile.profile_id',
                'let' => ['profile_id' => '$_id', 'type' => '$type'],
                'pipeline' => [
                    [
                        '$match' => [
                            'user_id' => $this->userId,
                            'visibility' => 'Catalog and Search',
                        ],
                    ],
                    [
                        '$match' => [
                            '$expr' => [
                                '$eq' => ['$$type', 'profile'],
                            ],
                        ],
                    ],
                    [
                        '$count' => 'count',
                    ],
                ],
            ],
        ];
    }

    public function getLookupPipeline()
    {
        $pipeline = [];
        $pipeline[] = [
            '$match' => [
                'user_id' => $this->userId,
                '$expr' => [
                    '$eq' => ['$$type', 'profile'],
                ],
            ],
        ];
        $module = $this->di->getRequester()->getTargetName() ?? "";
        $moduleData = $this->di->getConfig()->connectors->get($module) ?
            $this->di->getConfig()->connectors->get($module)->toArray() : false;
        if ($moduleData && !empty($moduleData["active_product_statuses"])) {
            $statusCond = [];
            foreach ($moduleData["active_product_statuses"] as $val) {
                array_push($statusCond, ['$in' => [$val, '$items.status']]);
            }

            $pipeline[] = [
                '$addFields' => [
                    'active_product' => [
                        '$cond' => [
                            'if' => ['$or' => $statusCond],
                            'then' => 1,
                            'else' => 0,
                        ],
                    ],
                ],
            ];
            $pipeline[] = [
                '$group' => [
                    '_id' => null,
                    'count' => ['$sum' => 1],
                    'active_product' => [
                        '$sum' => '$active_product',
                    ],
                ],
            ];
        } else {
            $pipeline[] = [
                '$count' => 'count',
            ];
        }

        return $pipeline;
    }

    /**
     * getProfileDataUsingActivePageAndCount
     */
    public function getAggregateForProfileDataByName($name, $data)
    {
        $totalPageRead = 0;
        $count = (int) $data['count'] ?? 20;
        $limit = (int) $data['count'] ?? 20;
        $prev = [];
        $skip = 0;
        $sort = $data['sort_order'] ?? "asc";
        $sortBy = $data['sort_by'] ?? "_id";
        $sortBy = strlen($sortBy) > 0 ? $sortBy : "_id";

        $aggregation =
            $name == "" ? [
                '$match' => ["type" => "profile"],
            ] : ['$match' => ["type" => "profile", "name" => ['$regex' => $name, '$options' => 'i']]];

        $aggregation['$match']['shop_ids'] = [
            '$elemMatch' => [
                'target' => $this->di->getRequester()->getTargetId(),
                'source' => $this->di->getRequester()->getSourceId(),
            ],
        ];

        $filters = isset($data['filter']) ? $data : [];
        if (count($filters)) {
            // $conditionalQuery =  $this->di->getObjectManager()->get("\App\Connector\Components\PaginationHelper")->searchMongo($filters);
            $aggregation['$match']['$and'][] = $this->di->getObjectManager()->get("\App\Connector\Components\PaginationHelper")->searchMongo($filters);
        }

        if (isset($data['next'])) {
            $nextDecoded = json_decode(base64_decode($data['next']), true);
            $prev = $nextDecoded['pointer'] ?? null;
            $totalPageRead = $nextDecoded['totalPageRead'];
            $aggregation['$match'][$sortBy] = ($sort === "desc") ?
                [
                    '$lt' => ($sortBy == "_id") ? new \MongoDB\BSON\ObjectID($nextDecoded['cursor']['$oid']) : $nextDecoded['cursor'],
                ] : [
                    '$gt' => ($sortBy == "_id") ? new \MongoDB\BSON\ObjectID($nextDecoded['cursor']['$oid']) : $nextDecoded['cursor'],
                ];
        }

        if (isset($data['prev'])) {
            $nextDecoded = json_decode(base64_decode($data['prev']), true);
            $prev = $nextDecoded['cursor'] ?? null;
            $totalPageRead = $nextDecoded['totalPageRead'];
            if (count($nextDecoded['cursor']) != 0) {
                $lastIndex = $nextDecoded['cursor'][count($nextDecoded['cursor']) - 1];
                $aggregation['$match'][$sortBy] = ($sort === "desc") ? [
                    '$lte' => ($sortBy == "_id") ? new \MongoDB\BSON\ObjectID($lastIndex['$oid']) : $lastIndex,
                ] : [
                    '$gte' => ($sortBy == "_id") ? new \MongoDB\BSON\ObjectID($lastIndex['$oid']) : $lastIndex,
                ];
            }
        }

        if (isset($data['active_page'])) {
            $skip = ($data['active_page'] - 1) * $limit;
        } else {
            $limit = $limit + 1;
        }

        ($sort === 'desc') && $condition[] = ['$sort' => [$sortBy => -1]];
        $condition[] = $aggregation;

        isset($data['active_page']) && $condition[] = [
            '$skip' => $skip,
        ];
        $condition[] = $this->getAggregateForLookup($data);
        $condition[] = ['$limit' => $limit];

        return ['totalPageRead' => $totalPageRead, 'prev' => $prev, 'aggregate' => $condition, 'limit' => $count];
    }

    /**
     * getNextPrevProfileData()
     *
     * function to get profile data with pointers
     */
    public function getNextPrevProfileData($aggregateData, $data, $collection = "profile")
    {
        $condition = $aggregateData['aggregate'];
        $totalPageRead = $aggregateData['totalPageRead'];
        $prev = $aggregateData['prev'];
        $limit = $aggregateData['limit'];
        $sortBy = $data['sort_by'] ?? "_id";
        $sortBy = strlen($sortBy) > 0 ? $sortBy : "_id";

        $profileArray = $this->getCollectionForTable($collection)->aggregate($condition);

        try {

            $cursor = $profileArray;
            $it = new \IteratorIterator($cursor);
            $it->rewind();

            $rows = [];
            while ($limit > 0 && $doc = $it->current()) {
                $rows[] = $doc;
                $limit--;
                $it->next();
            }

            if ($it->valid()) {
                if (!isset($data['prev'])) {
                    $prev[] = $rows[0][$sortBy];
                } else if (count($prev) > 1) {
                    array_pop($prev);
                }

                $next = base64_encode(json_encode([
                    'cursor' => $doc[$sortBy],
                    'pointer' => $prev,
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        $prevCursor = null;

        if (count($prev) >= 1 || isset($data['prev'])) {
            if (count($prev) === 1 && $this->checkObjectIds($rows[0][$sortBy], $prev[0])) {
                $prevCursor = null;
                $totalPageRead = 0;
            } else {

                $this->checkObjectIds($prev[count($prev) - 1], $rows[0][$sortBy]) && array_pop($prev);
                $prevCursor = base64_encode(json_encode([
                    'cursor' => $prev,
                    'totalPageRead' => $totalPageRead - 1,
                ]));
            }
        }

        $responseData = [
            'activePage' => $totalPageRead + 1,
            'current_count' => count($rows),
            'next' => $next ?? null,
            'prev' => $prevCursor,
            'rows' => $rows,
        ];

        return $responseData;
    }

    // function to check objectIds are equal or not
    public function checkObjectIds($obj1, $obj2)
    {
        return json_decode(json_encode($obj1), true)['$oid'] === json_decode(json_encode($obj2), true)['$oid'];
    }

    public function unwindAttributeData(&$attributeArray)
    {
        if (count((array) $attributeArray) == 0) {
            return false;
        }

        $id = (string) $attributeArray;
        $attributeData = isset($this->idsToArrayMap[$id]) ? clone ($this->idsToArrayMap[$id]['data']) : [];
        $attributeData['_id'] = $attributeArray;
        $attributeArray = $attributeData;
    }

    public function mergeAllArrays(&$profileMainArray): void
    {
        $arr = ['targets', 'shops', 'warehouses', 'sources'];
        for ($i = 0; $i < 4; $i++) {
            $keyItem = $arr[$i];
            if (isset($profileMainArray[$keyItem])) {
                $val = &$profileMainArray[$keyItem];
                foreach ($val as &$value) {
                    if ($keyItem == 'shops' || $keyItem == 'warehouses') {
                        $value = isset($this->idsToArrayMap[(string) $value['id']]) ? clone ($this->idsToArrayMap[(string) $value['id']]) : [];
                        unset($value['user_id']);
                    }

                    if (isset($value['attributes_mapping'])) {
                        $this->unwindAttributeData($value['attributes_mapping']);
                    }

                    $this->mergeAllArrays($value);
                }
            }
        }
    }

    public function idValid($id)
    {
        return $id instanceof \MongoDB\BSON\ObjectID
            || preg_match('/^[a-f\d]{24}$/i', $id);
    }

    public function getProfileData($data)
    {
        $this->init($data);
        if (isset($data['active_page'])) {
            if ($data['active_page'] < 1) {
                return ['success' => false, 'message' => 'active page must be >=1', 'code' => 'invalid_active_page'];
            }
        }

        if (isset($data['count']) && !isset($data['id'])) {
            if ($data['count'] < 1) {
                return ['success' => false, 'message' => 'count must be >=1', 'code' => 'invalid_count'];
            }
        }

        if (isset($data['id']) && !$this->idValid($data['id'])) {
            return ['success' => false, 'message' => 'Invalid Profile ID', 'code' => 'invalid_profie_id'];
        }

        // conditions to handle name and id
        if (isset($data['id'])) {
            $id = isset($data['id']) ? $data['id'] : "";
            $name = "";
            $success = false;
            $message = "Wrong Profile ID";
            $code = "wrong_profile_id";
        } else if (isset($data['name'])) {
            $name = $data['name'];
            $id = "";
            $success = false;
            $message = "Wrong Profile Name";
            $code = "wrong_profile_name";
        } else {
            $id = "";
            $name = "";
            $success = true;
            $message = "No Data Found";
            $code = "no_data_found";
        }

        $profileMainArray = $this->getProfileFormattedDataAndIdsMapping($id, $name, $data);

        if (count($profileMainArray) == 0) {
            if ($name == "" && $id == "") {
                return ['success' => $success, 'data' => ['rows' => []], 'message' => $message, 'code' => $code];
            }

            return ['success' => $success, 'message' => $message, 'code' => $code];
        }

        if ($id == "") {
            if (isset($data['all_profile_name'])) {
                return ['success' => true, 'data' => $profileMainArray['arr'], 'projectData' => $profileMainArray['anotherArr']];
            }

            $model = $this->di->getConfig()->connectors->get($this->di->getRequester()->getTargetName())->get('source_model');
            if ((method_exists($this->di->getObjectManager()->get($model), "modifiedProfileData"))) {
                return ['success' => true, 'data' => $this->di->getObjectManager()->get($model)->modifiedProfileData($profileMainArray)];
            }

            if (isset($data['count'])) {
                return ['success' => true, 'data' => $profileMainArray];
            }

            return ['success' => false, 'message' => 'ActivePage or Count Missing', 'code' => 'data_missing'];
        }

        $dataAllProfiles = [];
        $len = 0;

        foreach ($profileMainArray as $profileData) {
            $len++;
            $this->unwindAttributeData($profileData['attributes_mapping']);

            $this->mergeAllArrays($profileData);

            $dataAllProfiles[] = $profileData;
        }

        return ['success' => true, 'data' => $dataAllProfiles, 'count' => $len];
    }

    /*
    @profile get end
     */

    public function deleteProfile($data)
    {
        $this->init($data);
        if (!isset($data['id'])) {
            return ['success' => false, 'message' => 'No Profile id given', 'code' => 'missing_data'];
        }

        if (isset($data['id']) && !$this->idValid($data['id'])) {
            return ['success' => false, 'message' => 'Invalid Profile ID', 'code' => 'invalid_profie_id'];
        }

        $id = new \MongoDB\BSON\ObjectId($data['id']);
        $condition = [
            '$or' => [
                ['profile_id' => $id],
                ['_id' => $id],
            ],
        ];
        $profile_data = $this->getCollectionForTable('profile')->find($condition, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();

        $this->getCollectionForTable('profile')->deleteMany($condition);

        $this->getCollectionForTable('product_container')->updateMany(['profile.profile_id' => $id, 'user_id' => $this->userId], ['$pull' => ['profile' => ['profile_id' => $id]]]);
        $this->getCollectionForTable('refine_product')->updateMany(['profile.profile_id' => $id, 'user_id' => $this->userId], ['$unset' => ['profile' => 1]]);
        $this->profileDataUpdate([
            'operation_type' => "delete",
            'profile_id' => $id,
            'profile_data' => $profile_data,
            'marketplace' => $this->di->getRequester()->getTargetName() ?? "",
        ]);
        $eventManager = $this->di->getEventsManager();
        $eventManager->fire('application:afterProfileDeleted', $this, $data);
        return ['success' => true, 'message' => 'Successfully deleted'];
    }

    public function getProfileDataCount($data)
    {

        $this->init($data);
        $condition = [];
        $condition[] = [
            '$match' => [
                'type' => 'profile',
                'user_id' => $this->userId,
            ],
        ];
        $condition[0]['$match']['shop_ids'] = [
            '$elemMatch' => [
                'target' => $this->di->getRequester()->getTargetId(),
                'source' => $this->di->getRequester()->getSourceId(),
            ],
        ];
        if (isset($data['name']) && strlen($data['name']) > 0) {
            $condition[0]['$match']['name'] = ['$regex' => $data['name'], '$options' => 'i'];
        }

        $condition[] = [
            '$count' => "count",
        ];
        $profileArray = $this->getCollectionForTable('profile')->aggregate($condition)->toArray();
        return ['success' => true, 'total_count' => $profileArray[0]['count']];
    }

    public function getProfiledataByProjection($data)
    {

        if (!isset($data['active_page']) || !isset($data['count'])) {
            return ['success' => false, "message" => "Active Page or Count Missing"];
        }

        $this->init($data);
        $skip = ($data['active_page'] - 1) * $data['count'];
        $limit = $data['count'] * 1;
        $condition = [];
        $condition[] = [
            '$match' => [
                'type' => 'profile',
                'user_id' => $this->userId,
            ],
        ];
        $condition[0]['$match']['shop_ids'] = [
            '$elemMatch' => [
                'target' => $this->di->getRequester()->getTargetId(),
                'source' => $this->di->getRequester()->getSourceId(),
            ],
        ];
        $condition[]['$skip'] = $skip;
        $condition[]['$limit'] = $limit;

        if (isset($data['keys'])) {
            $keys = explode(",", trim($data['keys'], ","));
            $tmp = [];
            if (!in_array("_id", $keys)) {
                $tmp['_id'] = 0;
            }

            foreach ($keys as $v) {
                $tmp[$v] = 1;
            }

            $condition[]['$project'] = $tmp;
        } else {
            $condition[]['$project'] = ["_id" => 1, "name" => 1, "category_id" => 1, "user_id" => 1];
        }

        $profileArray = $this->getCollectionForTable('profile')->aggregate($condition)->toArray();
        return ['success' => true, 'data' => $profileArray];
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

            $condition[] = [
                '$match' => [
                    '_id' => ['$in' => $preparedIds],
                ],
            ];
            $profileArray = $this->getCollectionForTable('profile')->aggregate($condition)->toArray();
            return ['success' => true, 'data' => $profileArray];
        }

        return ['success' => false, 'message' => 'ids missing'];
    }
}
