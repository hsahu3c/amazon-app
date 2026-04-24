<?php

namespace App\Connector\Components\Profile;

use App\Core\Models\BaseMongo;

use function GuzzleHttp\json_decode;

class QueryProducts extends BaseMongo
{
    public $userId = '';

    public function init(&$data = []): void
    {
        if (isset($data['user_id'])) {
            $this->userId = $data['user_id'];
        } else {
            $this->userId = $this->di->getUser()->id;
            $data['user_id'] = $this->userId;
        }
    }

    public function getDefAggregateForQueryProducts($data, $count = false)
    {
        $aggregate = [];
        $data['user_id'] = $this->userId;
        $refin = false;
        if (isset($data['useRefinProduct']) && $data['useRefinProduct']) {
            $refin = true;
        }

        $shopIds =  [
            'sources' => [$data['source']['shopId']],
            'targets' => [$data['target']['shopId']]
        ];

        if ($refin) {
            $shopIdsForQuery = ['$or' => [[
                '$and' => [['source_shop_id' => $data['source']['shopId'], 'target_shop_id' => $data['target']['shopId']]]
            ]]];
            $aggregate = (new \App\Connector\Components\Profile\GetQueryConverted)->extractRuleForRefineData($data, 'aggregate', $shopIds, $shopIdsForQuery);
        } else {
            $queryAggregate =  (new \App\Connector\Components\Profile\GetQueryConverted)->extractRuleFromProfileData($data, 'aggregate', $shopIds);
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
        }

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
            $this->nextPrevActivePageHandle($data, $aggregate);
            return (new \App\Connector\Components\Profile\GetProductProfileMerge)->getProducts($data, $aggregate);
        }

        return ['success' => false, 'message' => 'Query or Source Marketplace info missing', 'code' => 'data_missing'];
    }

    public function getQueryProductsCount($data)
    {
        $this->init($data);
        $overwrite = $data["overWriteExistingProducts"] ?? false;
        if (isset($data['query']) && isset($data['source'])) {
            $aggregateCollection = "product_container";
            if (isset($data['useRefinProduct']) && $data['useRefinProduct']) {
                $aggregateCollection = 'refine_product';
            }

            $aggregate = $this->getDefAggregateForQueryProducts($data, true);
            $data["overWriteExistingProducts"] = !$overwrite;
            $tempAggregate = $this->getDefAggregateForQueryProducts($data, true);
            $productArr = $this->getCollectionForTable($aggregateCollection)->aggregate($aggregate)->toArray();
            $tempProductArr = $this->getCollectionForTable($aggregateCollection)->aggregate($tempAggregate)->toArray();
            $overwriteTrue = $overwrite ? ($productArr[0]['count'] ?? 0) : ($tempProductArr[0]['count'] ?? 0);
            $overwriteFalse = !$overwrite ? ($productArr[0]['count'] ?? 0) : ($tempProductArr[0]['count'] ?? 0);
            $data = [
                'count' => $productArr[0]['count'] ?? 0,
                'overwrite_true' => $overwriteTrue,
                'overwrite_false' => $overwriteFalse
            ];
            return ['success' => true, 'message' => 'Count fecthed Successfully', 'data' => $data];
        }

        return ['success' => false, 'message' => 'Query or Source Marketplace info missing', 'code' => 'data_missing'];
    }

    public function getProfileProductsCount($data)
    {
        $tableTOuse = isset($data['useRefinProduct']) ? ($data['useRefinProduct'] ?  'refine_product' : 'product_container') : 'product_container';
        $productCollection = $this->getCollectionForTable($tableTOuse);
        $messageResponse = ['success' => true];
        if ($tableTOuse == 'refine_product') {

            if (isset($data['name']) && strlen($data['name']) > 0) {
                $aggregation = [
                    ['$match' => [
                        "user_id" => (string)$this->di->getUser()->id,
                        "profile.profile_id" => new \MongoDB\BSON\ObjectId($data['profile_id']),
                        "name" => ['$regex' => $data['name'], '$options' => 'i']
                    ]]
                ];
            } else {
                $aggregation = [
                    ['$match' => [
                        "user_id" => (string)$this->di->getUser()->id,
                        "profile.profile_id" => new \MongoDB\BSON\ObjectId($data['profile_id'])
                    ]]
                ];
            }

            $module = $this->di->getRequester()->getTargetName() ?? "";
            $moduleData = $this->di->getConfig()->connectors->get($module) ?
                $this->di->getConfig()->connectors->get($module)->toArray() : false;
            if ($moduleData && !empty($moduleData["active_product_statuses"])) {
                $statusCond = [];
                foreach ($moduleData["active_product_statuses"] as $val) {
                    array_push($statusCond, ['$in' => [$val, '$items.status']]);
                }

                $aggregation[] =  [
                    '$addFields' => [
                        'active_product' => [
                            '$cond' => [
                                'if' => ['$or' => $statusCond],
                                'then' => 1,
                                'else' => 0,
                            ]
                        ]
                    ]
                ];
                $aggregation[] = [
                    '$group' => [
                        '_id' => null,
                        'count' => ['$sum' => 1],
                        'active_product' => [
                            '$sum' => '$active_product'
                        ]
                    ]
                ];
            } else {
                $aggregation[] =  [
                    '$count' => 'count'
                ];
            }

            $response = $productCollection->aggregate($aggregation)->toArray();
            $messageResponse['active_product'] = $response[0]['active_product'] ?? 0;
        } else {
            $response = $productCollection->aggregate([
                ['$match' => ["user_id" => (string)$this->di->getUser()->id, "shop_id" => $data['source']['shopId'], "visibility" => 'Catalog and Search', "profile.profile_id" => new \MongoDB\BSON\ObjectId($data['profile_id'])]],
                [
                    '$count' => "count"
                ]
            ])->toArray();
        }

        $messageResponse['count'] = $response[0]['count'] ?? 0;

        return $messageResponse;
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
                '$skip' => ($data['activePage'] - 1) * $limit
            ];
        }
    }
}
