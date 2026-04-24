<?php

namespace App\Connector\Components\Profile;

use App\Core\Models\BaseMongo;
use \MongoDB\BSON\ObjectId;

class GetProductProfileMerge extends BaseMongo
{
    public $defaultLimit = 10;

    public $userId = '';

    public $def_prj = ['test' => 0];

    public $allSourceProductIds = [];

    private $profileId = null;

    public function init($data = []): void
    {
        if (isset($data['user_id'])) {
            $this->userId = $data['user_id'];
        } else {
            $this->userId = $this->di->getUser()->id;
        }
        $dynamicProfile = $this->di->getObjectManager()->get(DynamicProfile::class);
        if ($dynamicProfile->enabled()) {
            $this->profileId = new ObjectId($data['profile_id']);
            if (count($filters = $dynamicProfile->getFilters($data, $this->profileId))) {
                $data['filter'] = $filters;
            }
        } else {
            $this->profileId = null;
        }
    }

    public function getMessage($data, $appendKey = ' initiated', $findVaraintCountOnly = 0)
    {
        if ($data['operationType'] === "product_upload" && $findVaraintCountOnly) {
            return ucfirst(explode("_", $data['operationType'])[0] . " variants upload" . $appendKey);
        } else {
            return ucfirst(implode(" ", explode("_", $data['operationType'])) . $appendKey);
        }
    }

    public function getproductsByProfile($data, $directReturnProducts = false)
    {

        $this->init($data);
        $data['user_id'] = $this->userId;
        if ($directReturnProducts) {
            $aggregate = $this->getProfileIdAggregate($data);

            return $this->getProducts($data, $aggregate, "getVariantFormatted", "getProfileInfo", $data['next_available']);
        }

        $message = $this->getMessage($data);
        $SqsObject = new \App\Connector\Components\Profile\SQSWorker;
        $newData = [
            'user_id' => $this->userId,
            'class_name' => '\App\Connector\Components\Profile\GetProductProfileMerge',
            'method_name' => 'getProductsWithMergedDataSqsMethod',
            'params' => array_merge($data, ['activePage' => 1, 'aggregate_method' => 'getProfileIdAggregate']),
            'worker_name' => isset($data['workerName']) ? $data['workerName'] : 'product_profile_id_upload_danish',
            'message' => $message,
            'process_code' => $data['operationType'],
            "additional_data" => ['bulk' => true, 'source_info' => $data['source'], $data['key_name'] => $data[$data['key_name']] ?? 'source_info_not_found'],
        ];
        $SqsObject->CreateWorker($newData, $data['create_queued_tasks']);

        return ['success' => true, 'message' => $message];
    }

    public function getproductsByProductIds($data, $directReturnProducts = false)
    {
        $this->init($data);
        $data['user_id'] = $this->userId;
        if ($directReturnProducts) {
            $aggregate = $this->getProductIdsAggregate($data);
            return $products = $this->getProducts($data, $aggregate, "getVariantFormatted", "getProfileInfo", $data['next_available']);
        }

        $mesasge = $this->getMessage($data);
        $SqsObject = new \App\Connector\Components\Profile\SQSWorker;
        $newData = [
            'user_id' => $this->userId,
            'class_name' => '\App\Connector\Components\Profile\GetProductProfileMerge',
            'method_name' => 'getProductsWithMergedDataSqsMethod',
            'params' => array_merge($data, ['activePage' => 1, 'aggregate_method' => 'getProductIdsAggregate']),
            'worker_name' => isset($data['workerName']) ? $data['workerName'] : 'product_id_profile_upload_danish',
            'message' => $mesasge,
            'process_code' => $data['operationType'],
            "additional_data" => ['bulk' => false, 'source_info' => $data['source'] ?? 'source_info_not_found'],
        ];
        $SqsObject->CreateWorker($newData, $data['create_queued_tasks']);
        return ['success' => true, 'message' => $mesasge];
    }

    public function getProductsWithMergedDataSqsMethod($data)
    {
        //getProfileIdAggregate
        $params = $data['data']['params'];
        $aggregateMethod = $params['aggregate_method'];
        $aggregate = $this->$aggregateMethod($params);
        //graph look up ke sath
        $products = $this->getProducts($params, $aggregate, "getVariantFormatted", "getProfileInfo", $params['next_available']);

        $products['raw_data'] = $data;

        if (isset($params['totalParentProccessed'])) {
            $params['totalParentProccessed'] = $params['totalParentProccessed'] + $products['parentInCurrentChunk'];
        } else {
            $params['totalParentProccessed'] = $products['parentInCurrentChunk'];
        }

        $products['data']['operationType'] = $params['operationType'];
        $products['data']['params'] = $params;
        $products['data']['count_status'] = $data['data']['count'] ?? [];
        $SqsObject = new \App\Connector\Components\Profile\SQSWorker;
        $model = $this->di->getConfig()->connectors->get($params['target']['marketplace'])->get('source_model');
        $syncFunc = $this->di->getObjectManager()->get($model)->startSync($products);

        if (!isset($syncFunc['CODE'])) {
            $syncFunc['CODE'] = 'SUCCESS';
        }

        if (isset($syncFunc['marketplace_additional_data'])) {
            $data['data']['marketplace_additional_data'] = $syncFunc['marketplace_additional_data'];
        }

        if (isset($syncFunc['count']['success']) && isset($syncFunc['count']['error']) && isset($syncFunc['count']['warning'])) {
            if (!isset($data['data']['count'])) {
                $data['data']['count'] = [
                    'success' => 0,
                    'error' => 0,
                    'warning' => 0,
                ];
            }

            $data['data']['count'] = [
                'success' => $data['data']['count']['success'] + $syncFunc['count']['success'],
                'error' => $data['data']['count']['error'] + $syncFunc['count']['error'],
                'warning' => $data['data']['count']['warning'] + $syncFunc['count']['warning'],
            ];
        }

        $addtional_data = ['bulk' => isset($params['source_product_ids']) ? false : true];

        //test and then process
        $processCodeAdd = isset($params['operationType']) ? ["process_code" => $params['operationType']] : [];

        if ($syncFunc['CODE'] == 'REQUEUE') {
            $data['apply_limit'] = isset($syncFunc['time_out']) ? $syncFunc['time_out'] : 60;
            $params['requeue_count'] = isset($params['requeue_count']) ? $params['requeue_count'] + 1 : 1;
        } else if ($syncFunc['CODE'] == 'SUCCESS') {
            $progress = (1 / $params['totalPages']) * 100;
            if ($params['create_queued_tasks']) {
                if ($data['data']['feed_id']) {
                    $this->updateFeedProgress($params, $progress, $data);
                }
            }

            $params['activePage'] = $params['activePage'] + 1;
        } else if ($syncFunc['CODE'] == 'TERMINATE') {
            $data['data']['params'] = $params;
            $this->clearInfoFromCache($data);
            if($data['uniqueKeyForCurrency']){
                $uniqueKeyForCurrency = $data['uniqueKeyForCurrency'];
                $this->di->getCache()->delete($uniqueKeyForCurrency);
            }
            if ($params['create_queued_tasks']) {
                if ($data['data']['feed_id']) {
                    $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')
                        ->updateFeedProgress($data['data']['feed_id'], 100, "Upload Terminated", $addNupdate = true); //updating progresss
                }

                if ($params['create_notification']) {
                    $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification(
                        $params['target']['shopId'],
                        [
                            "user_id" => $params['user_id'],
                            "marketplace" => $params['target']['marketplace'],
                            "message" => !empty($syncFunc['message'])?$syncFunc['message']:$this->getMessage($params, " terminated"),
                            "severity" => "error",
                            "shop_id" => $params['target']['shopId'],
                            "additional_data" => $addtional_data,
                        ] + ($processCodeAdd)
                    ); //adding notification
                }
            }

            return true;
        }

        $data['data']['params'] = $params;
        if (!$params['next_available'] && $syncFunc['CODE'] != 'REQUEUE') {
            $this->clearInfoFromCache($data);
             if($data['uniqueKeyForCurrency']){
                $uniqueKeyForCurrency = $data['uniqueKeyForCurrency'];
                $this->di->getCache()->delete($uniqueKeyForCurrency);
            }
            if ($params['create_notification']) {
                $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification(
                    $params['target']['shopId'],
                    [
                        "user_id" => $params['user_id'],
                        "marketplace" => $params['target']['marketplace'],
                        "message" => $this->getMessage($params, ' completed on ' . $params['target']['marketplace']),
                        "severity" => "success",
                        "shop_id" => $params['target']['shopId'],
                        "additional_data" => $addtional_data,
                    ] + ($processCodeAdd)
                ); //adding notification
            }

            return true;
        }

        $SqsObject->pushToQueue($data);
    }

    public function updateFeedProgress($params = [], $progress = 0, $data = []): void
    {
    $findVaraintCountOnly = isset($params['findVaraintCountOnly']) ? $params['findVaraintCountOnly'] : false;

    $findCatalogAndSearchPrdProduct = isset($params['findCatalogAndSearchPrdProduct']) ? $params['findCatalogAndSearchPrdProduct'] : false;

    if ($findCatalogAndSearchPrdProduct) {
        $processed = $params['totalParentProccessed'];
        $total = $params['catalogAndSearchPrdProduct'];
    } else {
        // Calculate the maximum possible processed count
        $calculatedProcessed = $findVaraintCountOnly ? 
            (($params['activePage'] * $params['limit']) - $params['totalParentProccessed']) : 
            ($params['activePage'] * $params['limit']);
        
        // Calculate the total count
        $total = $findVaraintCountOnly ? 
            $params['totalCount'] - $params['parentCount'] : 
            $params['totalCount'];
        
        // Ensure processed count doesn't exceed total count
        $processed = min($calculatedProcessed, $total);
    }

    $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')
        ->updateFeedProgress($data['data']['feed_id'], $progress, $processed . ' of ' . $total . " " .
            $this->getMessage($params, ' in progress', $findVaraintCountOnly), $addNupdate = true); //updating progresss
    }

    /**
     *
     * @param [array] $data
     * @return array
     */
    public function getSingleProductData($data)
    {
        $params = $data['data']['params'];

        $aggregateMethod = $params['aggregate_method'];
        $aggregate = $this->$aggregateMethod($params);

        $products = $this->getProducts($params, $aggregate, "getVariantFormatted", "getProfileInfo", $params['next_available']);

        $products['data']['operationType'] = $params['operationType'];
        $products['data']['params'] = $params;

        $model = $this->di->getConfig()->connectors->get($params['target']['marketplace'])->get('source_model');
        $syncFunc = $this->di->getObjectManager()->get($model)->startSync($products);
        return $syncFunc;
    }

    public function getAllSourceIds(&$data)
    {
        $aggregate = [];
        $cacheInfo = [];

        $tableTOuse = isset($data['useRefinProduct']) ? ($data['useRefinProduct'] ? 'refine_product' : 'product_container') : 'product_container';

        if (isset($data['unique_key_for_sqs'])) {
            $cacheInfo = $this->di->getCache()->get((string) $data['unique_key_for_sqs']);
        }

        if (isset($cacheInfo['all_source_product_ids'])) {
            return $cacheInfo['all_source_product_ids'];
        }

        if (isset($data['source_product_ids'])) {
            $this->handleDefAggregateForProductIds($data, $aggregate, 'forSourceIds');
        } else if (isset($data['profile_id'])) {
            //5
            $this->handleDefAggregateForProfileId($data, $aggregate, 'forSourceIds');
        }

        $flag = isset($data['mergeVariants']) ? $data['mergeVariants'] : false;

        $findVaraintCountOnly = isset($data['findVaraintCountOnly']) ? $data['findVaraintCountOnly'] : false;

        $findCatalogAndSearchPrdProduct = isset($data['findCatalogAndSearchPrdProduct']) ? $data['findCatalogAndSearchPrdProduct'] : false;

        $parentCount = 0;

        $aggregateAdd = ($findVaraintCountOnly || $findCatalogAndSearchPrdProduct) ? ['container_id' => ['$addToSet' => '$container_id'], 'type' => ['$addToSet' => '$type']] : [];
        if ($tableTOuse == 'product_container') {
            $aggregate[] = [
                '$group' => [
                    // '_id' => '$' . ($flag ? '' : 'marketplace.') . 'source_product_id',
                    '_id' => '$source_product_id',
                ] + $aggregateAdd,
            ];
        } else {
            $aggregate[] = [
                '$group' => [
                    '_id' => '$' . ($flag ? '' : 'items.') . 'source_product_id',
                ] + $aggregateAdd,
            ];
        }

        $aggregate[] = [
            '$sort' => ['_id' => 1],
        ];

        $allSourceProductIds = [];
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array'],
            'allowDiskUse' => true,
        ];
        $productArr = $this->getCollectionForTable($tableTOuse)->aggregate($aggregate, $options)->toArray();
        $counter = 0;
        foreach ($productArr as $value) {
            $counter++;
            if (is_array($value['_id'])) {
                foreach ($value['_id'] as $_val) {
                    $allSourceProductIds[] = $_val;
                }
            } else {
                $allSourceProductIds[] = $value['_id'];
            }

            if (isset($value['type']) && $value['type'][0] == 'variation') {
                $parentCount++;
            }
        }

        $data['parentCount'] = $parentCount;

        $data['catalogAndSearchPrdProduct'] = $counter;
        $getTime = microtime(true);
        $getTime = str_replace(".", "_", $getTime);
        $rand = rand();
        $getTime = $getTime . "_" . $rand;

        $data['unique_key_for_sqs'] = $data['user_id'] . 'target_id' . $data['target']['shopId'] . 'source_id' . $data['source']['shopId'] . $data['operationType'] . $getTime;

        $allSourceProductIds = array_unique($allSourceProductIds);
        $allSourceProductIds = array_values($allSourceProductIds);

        $cacheData = (array) $cacheInfo;
        $cacheData['all_source_product_ids'] = $allSourceProductIds;
        $this->di->getCache()->set($data['unique_key_for_sqs'], $cacheData);
        return $allSourceProductIds;
    }

    public function getProfilId($target_id, $data)
    {
        foreach ($data as $value) {
            if ($value['target_shop_id'] == $target_id) {
                return $value['profile_id'];
            }
        }

        return null;
    }

    public function getProducts($data, $aggregateAl = [], $getVariantFormatted = null, $getProfileInfo = null, $nextAvailable = false)
    {
        if (empty($aggregateAl)) {
            return ['success' => false, 'message' => 'send Pipeline Array', 'code' => 'data_missing'];
        }

        $limit = $data['limit'] ?? $this->defaultLimit;
        $onPage = 0;
        $pointerArr = [];

        // Decode pagination pointer
        if (!empty($data['next']) || !empty($data['prev'])) {
            $decodedVal = json_decode(base64_decode($data['next'] ?? $data['prev']), true);
            $onPage = $decodedVal['onPage'] ?? 0;
            $pointerArr = $decodedVal['pointers'] ?? [];
            if (!empty($decodedVal['cursor'])) {
                $aggregateAl[] = ['$match' => ['_id' => ['$gt' => new \MongoDB\BSON\ObjectId($decodedVal['cursor'])]]];
            }
        }

        // Apply projection to reduce data volume
        if (!empty($data['projectData'])) {
            $aggregateAl[] = ['$project' => $data['projectData']];
        }

        $aggregateAl[] = ['$limit' => (int)$limit + 1];

        $tableToUse = $data['useForcedRefineProductTable'] ?? false ? 'refine_product' : 'product_container';

        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'allowDiskUse' => true,
            'batchSize' => 500, // Adjust batch size based on workload
            'noCursorTimeout' => true, // Prevent cursor timeout
        ];

        $rows = [];
        $parentInCurrentChunk = 0;

        try {

            $cursor = $this->getCollectionForTable($tableToUse)->aggregate($aggregateAl, $options);
            $profileInfo = [];
            $parentInfo = [];

            foreach ($cursor as $doc) {
                if (count($rows) >= $limit) {
                    break;
                }

                if (($doc['visibility'] ?? '') === 'Catalog and Search') {
                    $parentInCurrentChunk++;
                    if ($this->profileId) {
                        $doc['profile'] = [
                            [
                                'profile_id' => $this->profileId,
                                'target_shop_id' => $data['target']['shopId'] ?? null,
                            ],
                        ];
                    }
                }

                if ($getVariantFormatted) {
                    $this->$getVariantFormatted($doc, $doc['variants'] ?? [], $data);
                    //we are receiving parent_details in it, we can extract that here , and unset the value from this document and assign it in new var
                    if(!isset($doc['profile']) && isset($doc['parent_details']['profile'])) {
                        $doc['profile'] = $doc['parent_details']['profile'];
                    }
                    if (isset($doc['parent_details']) && isset($data['usePrdOpt']) && $data['usePrdOpt']) {
                        $key = $doc['parent_details']['source_product_id'] . $doc['parent_details']['container_id'];
                        $parentInfo[$key] = $doc['parent_details'];
                        $doc['parent_details'] = $key;
                    }

                }

                if (!empty($doc['profile']) && $getProfileInfo) {
                    $profileId = $this->getProfilId($data['target']['shopId'] ?? null, $doc['profile']);

                    if ($profileId) {
                        $profileInfoDocument = $this->$getProfileInfo($profileId, $data);
                        if ($data['usePrdOpt']) {

                            $profileInfo[(string)$profileId] = $profileInfoDocument;

                            $doc['profile_info'] = $profileId;
                        } else {

                            $doc['profile_info'] = $profileInfoDocument;
                        }
                    }
                }

                $rows[] = $doc;
            }
        } catch (\MongoDB\Driver\Exception\Exception $e) {
            // Handle MongoDB cursor errors
            return ['success' => false, 'message' => 'Error fetching data: ' . $e->getMessage(), 'code' => 'cursor_error'];
        }

        // Handle pagination tokens
        $next = null;
        if (count($rows) > $limit) {
            $next = base64_encode(json_encode([
                'cursor' => $rows[$limit]['_id'] ?? null,
                'pointers' => [...$pointerArr, $rows[0]['_id'] ?? null],
                'onPage' => $onPage + 1,
            ]));
            array_pop($rows); // Remove the extra document
        }

        $prev = null;
        if ($onPage > 0) {
            $prev = base64_encode(json_encode([
                'cursor' => $pointerArr[$onPage - 1] ?? null,
                'pointers' => $pointerArr,
                'onPage' => $onPage - 1,
            ]));
        }

        return [
            'success' => true,
            'unique_key_for_sqs' => $data['unique_key_for_sqs'] ?? '',
            'message' => 'Fetched Successfully',
            'data' => [
                'rows' => $rows,
                'count' => count($rows),
                'next' => $nextAvailable ? true : $next,
                'prev' => $nextAvailable ? null : $prev,
                'all_parent_details' => $parentInfo,
                'all_profile_info' => $profileInfo
            ],
            'parentInCurrentChunk' => $parentInCurrentChunk,
        ];
    }



    public function getVariantFormatted(&$data, $variantDetails, $params): void
    {
        $mappedProducts = [];
        $parent_details = [];
        foreach ($variantDetails as $value) {
            if (isset($value['source_id']) && !isset($value['source_product_id'])) {
                $value['source_product_id'] = $value['source_id'];
            }

            if (isset($value['source_product_id']) && isset($value['container_id'])) {
                if ($data['source_product_id'] == $value['source_product_id'] && isset($value['target_marketplace'])) {
                    $data['edited'] = $value;
                    continue;
                }

                $toMerge = isset($value['target_marketplace']) ? ['edited' => $value] : $value;
                $mappedProducts[$value['source_product_id']] = isset($mappedProducts[$value['source_product_id']]) ? [...(array) $mappedProducts[$value['source_product_id']], ...(array) $toMerge] : $toMerge;
            }
        }

        unset($data['variants']);
        if (isset($params['mergeVariants']) && $params['mergeVariants']) {
            unset($mappedProducts[$data['source_product_id']]);
            $data['variants'] = array_values($mappedProducts);
        }

        if ($data['type'] == 'simple') {
            if ($data['visibility'] != 'Catalog and Search') {
                if (isset($data['container_id']) && isset($mappedProducts[$data['container_id']])) {
                    $parent_details = $mappedProducts[$data['container_id']];
                    if (isset($parent_details['profile']) && $profileId = $this->getProfilId($params['target']['shopId'], $parent_details['profile'])) {
                        if(isset($params['usePrdOpt']) && $params['usePrdOpt']) {
                            $parent_details['profile_info'] =$profileId;
                        }
                        else {
                            $parent_details['profile_info'] = $this->getProfileInfo($profileId, $params);
                        }
                        $data['profile_info'] = $parent_details['profile_info'];
                    }
                    $data['parent_details'] = $parent_details;
                }
            }
        }
    }

    public function formatProfileDataForPriorityBasis($profile, $data)
    {

        $targetShopId = $data['target']['shopId'];
        $targetMarketplace = $data['target']['marketplace'];
        $sourceShopId = $data['source']['shopId'];

        $priorityArr = ['source_warehouse', 'source_shop', 'warehouse', 'shop', 'target', 'profile'];
        $holdArr = [];

        $idToArr = [];
        foreach ($profile as $value) {
            $idToArr[(string) $value['_id']] = $value;
            if ($value['type'] == 'shop' && $value['shop_id'] == $targetShopId) {
                $holdArr['shop'] = $value;
            }

            if ($value['type'] == 'shop' && $value['shop_id'] == $sourceShopId) {
                $holdArr['source_shop'] = $value;
            }

            if ($value['type'] == 'profile') {
                $holdArr['profile'] = $value;
                if (isset($value['targets'])) {
                    foreach ($value['targets'] as $targetData) {
                        if (isset($targetData['target_marketplace']) && $targetData['target_marketplace'] == $targetMarketplace) {
                            $holdArr['target'] = $targetData;
                        }
                    }
                }
            }
        }

        $attribute = [];
        $category = [];
        $dataToData = [];
        $holdArr['warehouse'] = isset($holdArr['shop']['warehouses'][0]['id'])
            ? (isset($idToArr[(string) $holdArr['shop']['warehouses'][0]['id']]) ? $idToArr[(string) $holdArr['shop']['warehouses'][0]['id']] : [])
            : [];
        $holdArr['source_warehouse'] = isset($holdArr['source_shop']['warehouses'][0]['id'])
            ? (isset($idToArr[(string) $holdArr['source_shop']['warehouses'][0]['id']]) ? $idToArr[(string) $holdArr['source_shop']['warehouses'][0]['id']] : [])
            : [];

        foreach ($priorityArr as $val) {
            if (count($attribute) !== 0 && !empty($category) && count($dataToData)) {
                break;
            }

            if (isset($holdArr[$val]['category_id']) && empty($category)) {
                $category = $holdArr[$val]['category_id'];
            }

            if (isset($holdArr[$val]['attributes_mapping']) && count($attribute) == 0) {
                $attribute = $idToArr[(string) $holdArr[$val]['attributes_mapping']];
            }

            if (isset($holdArr[$val]['data']) && count($dataToData) == 0) {
                $dataToData = $holdArr[$val]['data'];
            }
        }

        return ['category_settings' => $category, 'attributes_mapping' => $attribute, 'data' => $dataToData];
    }

    public function getProfileInfo($profileId, $data)
    {

        $cacheInfo = $this->di->getCache()->get((string) $data['unique_key_for_sqs']);
        if (isset($cacheInfo[(string) $profileId])) {
            return $cacheInfo[(string) $profileId];
        }

        $condition = [
            '$or' => [
                ['profile_id' => $profileId],
                ['_id' => $profileId],
            ],
        ];
        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array']
        ];
        $profileData = $this->getCollectionForTable('profile')->find($condition, $options)->toArray();
        if (count($profileData) == 0) {
            $this->di->getCache()->set((string) $data['unique_key_for_sqs'], array_merge((array) $cacheInfo ?? [], [(string) $profileId => false]));
            return false;
        }

        $profileInfo = $this->formatProfileDataForPriorityBasis($profileData, $data);
        $this->di->getCache()->set((string) $data['unique_key_for_sqs'], array_merge((array) $cacheInfo ?? [], [(string) $profileId => $profileInfo]));
        return $profileInfo;
    }

    public function getProfileIdAggregate(&$data)
    {
        //1
        $aggregate = [];
        $this->handleDefAggregateForProfileId($data, $aggregate, 'getProducts');

        //cache , or => ['']

        $addTagetOr = $this->sourceCondtions($data);
        $addTagetOr[] = [
            'shop_id' => $data['target']['shopId'],
            'target_marketplace' => $data['target']['marketplace'],
        ];

        //check if we can avoid graphlookup
        $flag = isset($data['mergeVariants']) ? $data['mergeVariants'] : false;

        if ($flag) {
            $aggregate[] = [
                '$graphLookup' => [
                    'from' => 'product_container',
                    'startWith' => '$container_id',
                    'connectFromField' => 'container_id',
                    'connectToField' => 'container_id',
                    'restrictSearchWithMatch' => [
                        '$or' => $addTagetOr,
                        'user_id' => $data['user_id'],
                    ],
                    'as' => 'variants',
                ],
            ];
        } else {
            //this cannot go as titok team needs the whole data or I can go and manage it with a variable -- -- --
            $aggregate[] = [
                '$lookup' => [
                    'from' => 'product_container',
                    'as' => 'variants',
                    'localField' => 'container_id',
                    'foreignField' => 'container_id',
                    'let' => ['container_id' => '$container_id', 'source_product_id' => '$source_product_id'],
                    'pipeline' => [
                        [
                            '$match' => [
                                '$or' => [
                                    [
                                        '$expr' => [
                                            '$eq' => ['$$container_id', '$source_product_id'],
                                        ],
                                    ],
                                    [
                                        '$expr' => [
                                            '$eq' => ['$$source_product_id', '$source_product_id'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            '$match' => [
                                '$or' => $addTagetOr,
                                'user_id' => $data['user_id'],
                            ],
                        ],
                        [
                            '$project' => $data['projectData'] ?? $this->def_prj,
                        ],
                    ],
                ],
            ];
        }

        return $aggregate;
    }

    public function handleDefAggregateForProfileId(&$data, &$aggregate, $for = 'getProducts'): void
    {

        //2
        $tableTOuse = isset($data['useRefinProduct']) ? ($data['useRefinProduct'] ? 'refine_product' : 'product_container') : 'product_container';

        $orCond = $this->sourceCondtions($data, $for);

        $aggregate[] = [
            '$match' => [
                'user_id' => $data['user_id'],
            ],
        ];
        if ($for != 'getProducts') {
            if ($data['profile_id'] !== 'all_products' && is_null($this->profileId)) {
                $aggregate[] = [
                    '$match' => [
                        'profile.profile_id' => new \MongoDB\BSON\ObjectId($data['profile_id']),
                    ],
                ];
            }

            if (!empty($data['or_filter'])) {
                $containerModel = new \App\Connector\Models\ProductContainer();
                $filter = ['filter' => $data['or_filter']];
                $orQuery = $containerModel->search($filter);
                $filterType = '$or';
                $aggregate[] = ['$match' => [
                    $filterType => [
                        $orQuery,
                    ],
                ]];
            }

            if (!empty($data['filter'])) {
                $filter = ['filter' => $data['filter']];
                $containerModel = new \App\Connector\Models\ProductContainer();
                $andQuery = $containerModel->search($filter);
                $filterType = '$and';
                $aggregate[] = ['$match' => [
                    $filterType => [
                        $andQuery,
                    ],
                ]];
            }
        }

        if ($for != 'getProducts' && $tableTOuse == 'product_container') {
            $aggregate[] = [
                '$match' => [
                    'visibility' => 'Catalog and Search',
                ],
            ];
        }

        //3
        if ($for == 'getProducts') {
            $this->getProductsAggregateDefBySourceIds($data, $aggregate);
        }

        $aggregate[] = [
            '$match' => [
                '$or' => $orCond,
            ],
        ];
    }

    public function getProductIdsAggregate(&$data)
    {
        $aggregate = [];
        $this->handleDefAggregateForProductIds($data, $aggregate, 'getProducts');
        $addTagetOr = $this->sourceCondtions($data);

        $addTagetOr[] = [
            'shop_id' => $data['target']['shopId'],
            'target_marketplace' => $data['target']['marketplace'],
        ];

        $flag = isset($data['mergeVariants']) ? $data['mergeVariants'] : false;

        if ($flag) {
            $aggregate[] = [
                '$graphLookup' => [
                    'from' => 'product_container',
                    'startWith' => '$container_id',
                    'connectFromField' => 'container_id',
                    'connectToField' => 'container_id',
                    'restrictSearchWithMatch' => [
                        '$or' => $addTagetOr,
                        'user_id' => $data['user_id'],
                    ],
                    'as' => 'variants',
                ],
            ];
        } else {
            //this cannot go as titok team needs the whole data or I can go and manage it with a variable -- -- --
            $aggregate[] = [
                '$lookup' => [
                    'from' => 'product_container',
                    'as' => 'variants',
                    'localField' => 'container_id',
                    'foreignField' => 'container_id',
                    'let' => ['container_id' => '$container_id', 'source_product_id' => '$source_product_id'],
                    'pipeline' => [
                        [
                            '$match' => [
                                '$or' => [
                                    [
                                        '$expr' => [
                                            '$eq' => ['$$container_id', '$source_product_id'],
                                        ],
                                    ],
                                    [
                                        '$expr' => [
                                            '$eq' => ['$$source_product_id', '$source_product_id'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            '$match' => [
                                '$or' => $addTagetOr,
                                'user_id' => $data['user_id'],
                            ],
                        ],
                        [
                            '$project' => $data['projectData'] ?? $this->def_prj,
                        ],
                    ],
                ],
            ];
        }

        return $aggregate;
    }

    public function handleDefAggregateForProductIds(&$data, &$aggregate, $for = 'getProducts'): void
    {
        $aggregate[] = [
            '$match' => [
                'user_id' => $data['user_id'],
            ],
        ];

        $orCond = $this->sourceCondtions($data, $for);

        $aggregate[] = [
            '$match' => [
                '$or' => $orCond,
            ],
        ];
        if ($for == 'forSourceIds') {
            $this->addOrCondForIds($data['source_product_ids'], $aggregate, $data['useRefinProduct']);
        }

        if ($for == 'getProducts') {
            $this->getProductsAggregateDefBySourceIds($data, $aggregate);
        }
    }

    public function getProductsAggregateDefBySourceIds(&$data, &$aggregate): void
    {
        //4
        $allSourceProductIds = $this->getAllSourceIds($data);
        $limit = isset($data['limit']) ? $data['limit'] : $this->defaultLimit;
        $skip = ($data['activePage'] - 1) * $limit;
        $data['next_available'] = ($skip + $limit) < count($allSourceProductIds);
        $data['totalCount'] = count($allSourceProductIds);
        $data['totalPages'] = $data['totalCount'] == 0 ? 1 : ceil($data['totalCount'] / $data['limit']);
        $toIterateonArray = array_splice($allSourceProductIds, $skip, $limit);
        if (count($toIterateonArray) == 0) {
            $aggregate[] = [
                '$match' => [
                    'user_id' => $this->userId . $this->userId . "empty", //here it is to create a condition in which no products will be available
                ],
            ];
        } else {
            //5
            if (!empty($toIterateonArray)) {
                $aggregate[] = [
                    '$match' => [
                        'source_product_id' => ['$in' => array_values($toIterateonArray)],
                    ],
                ];
            }

            // $this->addOrCondForIds($toIterateonArray, $aggregate);
        }
    }

    public function addOrCondForIds($arr, &$aggregate, $useRefineProducts = false): void
    {
        $stringifiedIds = array_map('strval', $arr);

        if ($useRefineProducts) {
            $aggregate[] = [
                '$match' => [
                    '$or' => [
                        ['items.source_product_id' => ['$in' => array_values($stringifiedIds)]],
                        ['source_product_id' => ['$in' => array_values($stringifiedIds)]],
                    ],
                ],
            ];

            $aggregate[] = [
                '$project' => [
                    'items' => [
                        '$cond' => [
                            'if' => ['$in' => ['$source_product_id', array_values($stringifiedIds)]],
                            'then' => '$items',
                            'else' => [
                                '$filter' => [
                                    'input' => '$items',
                                    'as' => 'item',
                                    'cond' => ['$in' => ['$$item.source_product_id', array_values($stringifiedIds)]],
                                ],
                            ],
                        ],
                    ],
                    'source_product_id' => 1,
                    '_id' => 1
                ],
            ];

            return;
        }

        $orCondition = [];
        foreach ($arr as $value) {
            $orCondition[] = ['container_id' => (string) $value];
            $orCondition[] = ['source_product_id' => (string) $value];
        }

        if (!empty($orCondition)) {
            $aggregate[] = [
                '$match' => [
                    '$or' => $orCondition,
                ],
            ];
        }
    }

    public function sourceCondtions($data, $for = 'getProducts')
    {
        $tableTOuse = isset($data['useRefinProduct']) ? ($data['useRefinProduct'] ? 'refine_product' : 'product_container') : 'product_container';

        if ($tableTOuse == 'product_container' || $for == 'getProducts') {
            return [
                [
                    'shop_id' => $data['source']['shopId'],
                    'source_marketplace' => $data['source']['marketplace'],
                ],
            ];
        }

        return [
            [
                'source_shop_id' => $data['source']['shopId'],
            ],
        ];
    }

    public function clearInfoFromCache($data): void
    {
        $this->di->getCache()->delete((string) $data['data']['params']['unique_key_for_sqs']);
    }

    public function nextPrevActivePageHandle($data, &$aggregate): void
    {
        if (isset($data['next'])) {
            $nextDecoded = json_decode(base64_decode($data['next']), true);
            $aggregate[] = [
                '$match' => ['_id' => [
                    '$gt' => $nextDecoded['cursor'],
                ]],
            ];
        } else if (isset($data['prev'])) {
            $nextDecoded = json_decode(base64_decode($data['prev']), true);
            $aggregate[] = [
                '$match' => ['_id' => [
                    '$gte' => $nextDecoded['cursor'],
                ]],
            ];
        } else if (isset($data['activePage'])) {
            $limit = isset($data['limit']) ? $data['limit'] : $this->defaultLimit;
            $aggregate[] = [
                '$skip' => ($data['activePage'] - 1) * $limit,
            ];
        }
    }
}
