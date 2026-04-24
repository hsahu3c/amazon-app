<?php

namespace App\Connector\Models\Profile;

use App\Core\Models\BaseMongo;
use function GuzzleHttp\json_decode;

class ProfileHelper extends BaseMongo
{
    public $idsToArrayMap = [];

    protected $table = 'profile';

    public $sourceMarketplace;

    public $userId = '';

    public function init($data = []): void
    {
        if (isset($data['user_id'])) {
            $this->userId = $data['user_id'];
        } else {
            $this->userId = $this->di->getUser()->id;
        }
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

    public function saveChunk($data)
    {
        $data['user_id'] = $this->userId;
        $profileTable = $this->getCollectionForTable('profile');
        if (isset($data['_id'])) {
            $this->updateToMongoInstance($data, '_id');
            if ($data == $this->checkPresentReturnValue((string) $data['_id'], $this->idsToArrayMap)) {
                return $data['_id'];
            }
        }

        if (isset($data['profile_id'])) {
            $this->updateToMongoInstance($data, 'profile_id');
        }

        $mongo = isset($data['_id']) ? $profileTable->updateOne(['_id' => $data['_id']], ['$set' => $data]) : $profileTable->insertOne($data);
        return isset($data['_id']) ? $data['_id'] : $mongo->getInsertedId();
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
        foreach ($arr as $value) {
            $id = $value['_id'];
            unset($value['_id']);
            unset($value['type']);
            if ($keyChecking == 'attributes_mapping') {
                $value = $value['data'];
            }

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
        } else {
            $dataToSend['_id'] = $CheckIdPresent ? $CheckIdPresent : $this->saveChunk($dataToSend);
        }

        $mainData['attributes_mapping'] = $dataToSend['_id'];
        !$CheckIdPresent && $dataToUpdateProfileId['attributes_mapping'][] = $dataToSend;
    }

    public function warehousesAndShopsMappingStructured(&$warehouseOrShopData, &$dataToUpdateProfileId, $keyToMap): void
    {

        $dataToSend = $warehouseOrShopData;
        $dataToSend['type'] = $keyToMap == 'shops' ? 'shop' : 'warehouse';
        $CheckIdPresent = $this->checkObjectPresentInArray($warehouseOrShopData, $this->checkPresentReturnValue($keyToMap, $dataToUpdateProfileId), $keyToMap);
        if (!(isset($dataToSend['_id']))) {
            $dataToSend['_id'] = $CheckIdPresent ? $CheckIdPresent : $this->saveChunk($dataToSend);
        } else {
            $this->updateToMongoInstance($dataToSend, '_id');
        }

        $nameId = $keyToMap == 'shops' ? 'shop_id' : 'warehouse_id';
        $warehouseOrShopData = [
            $nameId => $dataToSend[$nameId],
            'id' => $dataToSend['_id'],
        ];
        !$CheckIdPresent && $dataToUpdateProfileId[$keyToMap][] = $dataToSend;
    }

    // public function checkForUniqueCategory

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

    public function saveProfile($data)
    {
        $this->init($data);
        if ($this->validateData($data)) {
            if (isset($data['_id'])) {
                $this->updateToMongoInstance($data, '_id');
                $this->getCollectionForTable('product_container')->updateMany(['profile_id' => $data['_id'], 'user_id' => $this->userId], ['$unset' => ['profile_id' => 1]]);
                $this->getProfileFormattedDataAndIdsMapping($data['_id']);
            }

            $dataToUpdateProfileId = [];

            $finalQuery = (new \App\Connector\Models\Profile\GetQueryConverted)->extractRuleFromProfileData($data);

            $this->attributeMappingStructured($this->checkPresentReturnValue('attributes_mapping', $data), $dataToUpdateProfileId, $data);

            $this->iterateTillSourceWarehouse($data, $dataToUpdateProfileId);

            $data['type'] = 'profile';

            $dataToUpdateProfileId['profile'] = $data;

            $savedProfileId = $this->saveProfileData($dataToUpdateProfileId);

            if (isset($data['query']) && $data['query'] !== "") {
                $this->getCollectionForTable('product_container')->updateMany($finalQuery, ['$set' => ['profile_id' => $savedProfileId]]);
            }

            return ['success' => true, 'message' => 'Saved Successfully', 'profile_id' => $savedProfileId];
        }
        return ['success' => false, 'message' => 'category_id or name missing', 'code' => 'data_missing'];
    }

    /*
    @profile save and update end
     */

    /*
    @profile get start
     */

    public function getProfileFormattedDataAndIdsMapping($idStr)
    {

        $id = $idStr != "" ? new \MongoDB\BSON\ObjectId($idStr) : "";
        $condition = [
            '$or' => [
                ['profile_id' => $id], ['_id' => $id],
            ],
        ];
        if ($idStr == "") {
            $condition = [];
        }

        $profileArray = $this->getCollectionForTable('profile')->find($condition)->toArray();

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

    public function unwindAttributeData(&$attributeArray)
    {
        if (count($attributeArray) == 0) {
            return false;
        }

        $id = (string) $attributeArray;
        $attributeData = clone ($this->idsToArrayMap[$id]['data']);
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
                        $value = clone ($this->idsToArrayMap[(string) $value['id']]);
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
        if (isset($data['id']) && !$this->idValid($data['id'])) {
            return ['success' => false, 'message' => 'Invalid Profile ID', 'code' => 'invalid_profie_id'];
        }

        $id = isset($data['id']) ? $data['id'] : "";
        $profileMainArray = $this->getProfileFormattedDataAndIdsMapping($id);
        if (count($profileMainArray) == 0) {
            return ['success' => false, 'message' => 'Wrong Profile ID', 'code' => 'wrong_profie_id'];
        }

        $dataAllProfiles = [];
        $len = 0;

        foreach ($profileMainArray as $profileData) {
            $len++;
            $this->unwindAttributeData($profileData['attributes_mapping']);

            $this->mergeAllArrays($profileData);

            $dataAllProfiles[] = $profileData;
        }

        $this->unwindAttributeData($profileMainArray['attributes_mapping']);

        $this->mergeAllArrays($profileMainArray);

        return ['success' => true, 'data' => $dataAllProfiles, 'count' => $len];
    }

    /*
    @profile get end
     */

    /*
    @GET PRODUCTS BY PROFILE_ID START
     */

    //source_shop_id
    // target_shop_id

    public function getVariantFormatted(&$parentDetails, $variantDetails, $params)
    {
        $mappedProducts = [];
        foreach ($variantDetails as $value) {
            if (isset($value['source_id']) && !isset($value['source_product_id'])) {
                $value['source_product_id'] = $value['source_id'];
            }

            if (isset($value['source_product_id']) && isset($value['container_id'])) {
                if ($parentDetails['source_product_id'] == $value['source_product_id'] && isset($value['target_marketplace'])) {
                    $parentDetails['edited'] = clone ($value);
                    continue;
                }

                $toMerge = isset($value['target_marketplace']) ? ['edited' => $value] : $value;
                $mappedProducts[$value['source_product_id']] = isset($mappedProducts[$value['source_product_id']]) ? [ ...(array) $mappedProducts[$value['source_product_id']], ...(array) $toMerge] : $toMerge;
            }
        }

        if ($parentDetails['type'] == 'simple') {
            unset($parentDetails['variants']);
        }

        return array_values($mappedProducts);
    }

    public function getProfileIdAggregate($data)
    {
        $data['profile_id'] = new \MongoDB\BSON\ObjectId($data['profile_id']);
        $orCond = [
            [
                'shop_id' => $data['source']['shopId'],
                'source_marketplace' => $data['source']['marketplace'],
            ],
        ];
        $addTagetOr = $orCond;
        $addTagetOr[] = [
            'shop_id' => $data['target']['shopId'],
            'target_marketplace' => $data['target']['marketplace'],
        ];
        $aggregate = [];

        $aggregate[] = [
            '$match' => [
                'user_id' => $data['user_id'],
                'profile_id' => $data['profile_id'],
                'visibility' => 'Catalog and Search',
            ],
        ];

        if (isset($data['next'])) {
            $nextDecoded = json_decode(base64_decode($data['next']), true);
            $aggregate[] = [
                '$match' => ['_id' => [
                    '$gt' => $nextDecoded['cursor'],
                ]],
            ];
        }

        if (isset($data['prev'])) {
            $nextDecoded = json_decode(base64_decode($data['prev']), true);
            $aggregate[] = [
                '$match' => ['_id' => [
                    '$gte' => $nextDecoded['cursor'],
                ]],
            ];
        }

        $aggregate[] = [
            '$match' => [
                '$or' => $orCond,
            ],
        ];

        $aggregate[] = [
            '$graphLookup' => [
                'from' => 'product_container',
                'startWith' => '$container_id',
                'connectFromField' => 'container_id',
                'connectToField' => 'container_id',
                'restrictSearchWithMatch' => [
                    "type" => [
                        '$ne' => 'variation',
                    ],
                    '$or' => $addTagetOr,
                    'user_id' => $data['user_id'],
                ],
                'as' => 'variants',
            ],
        ];

        return $aggregate;
    }

    public function getProductIdsAggregate($data)
    {
        $aggregate = [];
        if (isset($data['next'])) {
            $nextDecoded = json_decode(base64_decode($data['next']), true);
            $aggregate[] = [
                '$match' => ['_id' => [
                    '$gt' => $nextDecoded['cursor'],
                ]],
            ];
        }

        if (isset($data['prev'])) {
            $nextDecoded = json_decode(base64_decode($data['prev']), true);
            $aggregate[] = [
                '$match' => ['_id' => [
                    '$gte' => $nextDecoded['cursor'],
                ]],
            ];
        }

        $orCondition = [];
        foreach ($data['product_ids'] as $value) {
            $orCondition[] = ['source_product_id' => $value];
        }

        $aggregate[] = [
            '$match' => [
                'user_id' => $data['user_id'],
            ],
        ];
        $aggregate[] = [
            '$match' => [
                '$or' => $orCondition,
            ],
        ];

        $orCond = [
            [
                'shop_id' => $data['source']['shopId'],
                'source_marketplace' => $data['source']['marketplace'],
            ],
        ];
        $addTagetOr = $orCond;
        $addTagetOr[] = [
            'shop_id' => $data['target']['shopId'],
            'target_marketplace' => $data['target']['marketplace'],
        ];

        $aggregate[] = [
            '$match' => [
                '$or' => $orCond,
            ],
        ];

        $aggregate[] = [
            '$graphLookup' => [
                'from' => 'product_container',
                'startWith' => '$container_id',
                'connectFromField' => 'container_id',
                'connectToField' => 'container_id',
                'restrictSearchWithMatch' => [
                    "type" => [
                        '$ne' => 'variation',
                    ],
                    'user_id' => $data['user_id'],
                    '$or' => $addTagetOr,
                ],
                'as' => 'variants',
            ],
        ];

        return $aggregate;
    }

    public function formatProfileDataForPriorityBasis($profile, $data)
    {

        $targetShopId = $data['target']['shopId'];
        $targetMarketplace = $data['target']['marketplace'];

        $priorityArr = ['warehouse', 'shop', 'target', 'profile'];
        $holdArr = [];

        $idToArr = [];
        foreach ($profile as $value) {
            $idToArr[(string) $value['_id']] = $value;
            if ($value['type'] == 'shop' && $value['shop_id'] == $targetShopId) {
                $holdArr['shop'] = $value;
            }

            if ($value['type'] == 'profile') {
                $holdArr['profile'] = $value;
                foreach ($value['targets'] as $targetData) {
                    if ($targetData['target_marketplace'] == $targetMarketplace) {
                        $holdArr['target'] = $targetData;
                    }
                }
            }
        }

        $attribute = [];
        $category = [];
        $holdArr['warehouse'] = $idToArr[(string) $holdArr['shop']['warehouses'][0]['id']];
        foreach ($priorityArr as $val) {
            if (count($attribute) !== 0 && count($category) !== 0) {
                break;
            }

            if (isset($holdArr[$val]['category_id']) && count($category) == 0) {
                $category = $holdArr[$val]['category_id'];
            }

            if (isset($holdArr[$val]['attributes_mapping']) && count($attribute) == 0) {
                $attribute = $idToArr[(string) $holdArr[$val]['attributes_mapping']];
            }
        }

        return ['category_settings' => $category, 'attribute_mapping' => $attribute];
    }

    public function getProfileInfo($profileId, $data)
    {
        $profileInfo = $this->di->getCache()->get((string) $profileId);
        if ($profileInfo == null) {
            $condition = [
                '$or' => [
                    ['profile_id' => $profileId], ['_id' => $profileId],
                ],
            ];
            $profileData = $this->getCollectionForTable('profile')->find($condition)->toArray();

            $profileInfo = $this->formatProfileDataForPriorityBasis($profileData, $data);

            $this->di->getCache()->set((string) $profileId, $profileInfo);

        }

        return $profileInfo;
    }

    public function getproductsByProfile($data): void
    {
        die('getproductsByProfile');
    }

    public function getproductsByProductIds($data): void
    {
        die('getproductsByProductIds');
    }

    public function getProducts($data, $aggregateAl = [])
    {
        $this->init($data);
        $aggregate = [];

        if (isset($data['product_ids'])) {
            $aggregate = $this->getProductIdsAggregate($data);
        } else if (isset($data['profile_id'])) {
            $aggregate = $this->getProfileIdAggregate($data);
        } else if (count($aggregateAl) != 0) {
            $aggregate = $aggregateAl;
        }

        $limit = isset($data['limit']) ? $data['limit'] : 10;

        $aggregation[] = ['$limit' => (int) $limit + 1];

        $productArr = $this->getCollectionForTable('product_container')->aggregate($aggregate);

        $onPage = 0;

        $pointerArr = [];
        if (isset($data['next'])) {
            $decodedVal = json_decode(base64_decode($data['next']), true);
            $onPage = $decodedVal['onPage'];
            $pointerArr = $decodedVal['pointers'];
        }

        if (isset($data['prev'])) {
            $decodedVal = json_decode(base64_decode($data['prev']), true);
            $onPage = $decodedVal['onPage'];
            $pointerArr = $decodedVal['pointers'];
        }

        $it = new \IteratorIterator($productArr);
        $it->rewind();

        $rows = [];
        while ($limit > 0 && $doc = $it->current()) {

            if (count($aggregateAl) == 0) {
                $doc['variants'] = $this->getVariantFormatted($doc, $doc['variants'], $data);
                if ($doc['type'] == 'simple') {
                    unset($doc['variants']);
                }

                if (isset($doc['profile_id'])) {
                    $doc['profile_info'] = $this->getProfileInfo($doc['profile_id'], $data);
                }
            }

            $rows[] = $doc;

            $limit--;

            $it->next();
        }

        $next = null;
        $cursor = $onPage != 0 ? $pointerArr[$onPage - 1] : null;
        if (isset($data['prev'])) {
            array_pop($pointerArr);
        } else {
            $pointerArr[] = $rows[0]['_id'];
        }

        if ($it->valid()) {
            $next = base64_encode(json_encode([
                'cursor' => ($doc)['_id'],
                'pointers' => $pointerArr,
                'onPage' => $onPage + 1,
            ]));
        }

        $prev = null;
        if (isset($decodedVal) && isset($decodedVal['pointers']) && $onPage != 0) {
            $prev = base64_encode(json_encode([
                'cursor' => $cursor,
                'pointers' => $pointerArr,
                'onPage' => $onPage - 1,
            ]));
        }

        return ['success' => true, 'message' => 'Fetched Successfully', 'data' => ['rows' => $rows, 'count' => count($rows), 'next' => $next, 'prev' => $prev]];
    }

    /*
    @GET PRODUCTS BY PROFILE_ID END
     */

    public function getDefAggregateForQueryProducts($data, $count = false)
    {
        $aggregate = [];
        $queryAggregate = (new \App\Connector\Models\Profile\GetQueryConverted)->extractRuleFromProfileData($data, 'aggregate');
        $aggregate[] = [
            '$match' =>
            [
                'user_id' => $this->userId,
                'visibility' => 'Catalog and Search',
                'source_marketplace' => $data['source']['marketplace'],
                'shop_id' => $data['source']['shopId'],
            ],
        ];
        $aggregate[] = $queryAggregate[0];
        if ($count) {
            $aggregate[] = ['$count' => 'count'];
        } else {
        }

        return $aggregate;
    }

    public function getQueryProducts($data)
    {
        $this->init($data);
        if (isset($data['query']) && isset($data['source'])) {
            $aggregate = $this->getDefAggregateForQueryProducts($data);
            return $this->getProducts($data, $aggregate);
        }
        return ['success' => false, 'message' => 'Query or Source Marketplace info missing', 'code' => 'data_missing'];
    }

    public function getQueryProductsCount($data)
    {
        $this->init($data);
        if (isset($data['query']) && isset($data['source'])) {
            $aggregate = $this->getDefAggregateForQueryProducts($data, true);
            $productArr = $this->getCollectionForTable('product_container')->aggregate($aggregate)->toArray();
            return ['success' => true, 'message' => 'Count fecthed Successfully', 'data' => ['count' => $productArr[0]['count']]];
        }
        return ['success' => false, 'message' => 'Query or Source Marketplace info missing', 'code' => 'data_missing'];
    }

    public function getProfileById($profileId)
    {
        return $this->getCollection()->findOne(['_id' => $profileId], [
            'typeMap' => [
                'root' => 'array',
                'document' => 'array',
                'array' => 'array',
            ],
        ]);
    }
}
