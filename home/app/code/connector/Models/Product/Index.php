<?php

namespace App\Connector\Models\Product;

use App\Connector\Models\ProductContainer;
use App\Core\Models\Base;


class Index extends Base
{

    public $_mongo;

    public $_userId;

    public $_childKey = 'marketplace';

    public function init($data): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_mongo = $mongo->getCollectionForTable('product_container');

        if (isset($data['user_id'])) {
            $this->_userId = $data['user_id'];
        } else {
            $this->_userId = $this->di->getUser()->id;
        }
    }

    /**
     * count : number
     * activePage: number
     * from : {
     *  shop_id :
     * }
     * to : {
     *  shop_id :
     * }
     * next: string
     * target_marketplace = string e.g shopify, facebook
     */
    public function getProducts($params)
    {
        $this->init($params);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getCollection();

        $totalPageRead = 1;
        $limit = $params['count'] ?? 50;
        $prev = [];

        if (isset($params['next'])) {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $prev = $nextDecoded['pointer'] ?? null;
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['prev'])) {
            $nextDecoded = json_decode(base64_decode($params['prev']), true);
            $prev = $nextDecoded['cursor'] ?? null;
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        $aggregation = $this->buildAggregateQuery($params);

        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int)$offset];
        }

        $aggregation[] = ['$limit' => (int)$limit + 1];

        try {
            $cursor = $collection->aggregate($aggregation);
            $it = new \IteratorIterator($cursor);
            $it->rewind(); // Very important
            $rows = [];
            while ($limit > 0 && $doc = $it->current()) {
                $doc = $this->modifyPData(json_decode(json_encode($doc), true), $params);
                $rows[] = $doc;
                $limit--;
                $it->next();
            }

            if ($it->valid()) {
                if (!isset($params['prev'])) {
                    $prev[] = $rows[0]['_id'];
                } else if (count($prev) > 1) {
                    array_pop($prev);
                }

                $next = base64_encode(json_encode([
                    'cursor' => ($doc)['_id'],
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
        if (count($prev) > 1 || isset($params['prev'])) {
            if (count($prev) === 1 && $rows[0]['_id'] === $prev[0]) {
                $prevCursor = null;
            } else {
                $prevCursor = base64_encode(json_encode([
                    'cursor' => $prev,
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }
        }

        $responseData = [
            'success' => true,
            'data' => [
                'totalPageRead' => $page ?? $totalPageRead,
                'current_count' => count($rows),
                'next' => $next ?? null,
                'prev' => $prevCursor,
                'rows' => $rows,
            ],
        ];

        return $responseData;
    }

    public function modifyPData($pData, $inputParams)
    {
        $variants = [];
        $completeProduct = $pData;
        if (isset($pData['variants']) && count($pData['variants']) > 0 && $pData['type'] === "variation") {
            foreach ($pData['variants'] as $variant) {
                if (isset($variant['source_marketplace']) && $variant['shop_id'] == $inputParams['source_marketplace']['shop_id']) {
                    $variants[] = $this->getMergeChildData($variant, $pData['variants'], $inputParams);
                }
            }

            $completeProduct['variants'] = $variants;
        } else if (isset($pData['variants']) && count($pData['variants']) > 0) {
            $completeProduct = $this->getMergeChildData($pData, $pData['variants'], $inputParams);
            unset($completeProduct['variants']);
        }

        return $completeProduct;
    }

    public function getMergeChildData($currentChild, $variants, $inputParams)
    {
        foreach ($variants as $variant) {
            if (isset($variant['source_id']) && !isset($variant['source_product_id'])) {
                $variant['source_product_id'] = $variant['source_id'];
            }

            if ($variant['source_product_id'] == $currentChild['source_product_id'] && isset($variant['target_marketplace']) && isset($variant['shop_id']) && $variant['shop_id'] == $inputParams['target_marketplace']['shop_id']) { //shop_id
                $currentChild = $variant + $currentChild;
            }
        }

        return $currentChild;
    }

    private function buildAggregateQuery(array $params, $callType = 'getProduct'): array
    {
        $aggregation = [];
        $orQuery = [];
        $userId = $this->_userId;

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        if (isset($params['filterType'])) {
            $filterType = '$' . $params['filterType'];
        }

        $matchQuery = [
            'user_id' => $userId,
        ];

        if (isset($params['container_id'])) {
            $matchQuery = [
                'user_id' => $userId,
                "visibility" => 'Catalog and Search',
                'container_id' => [
                    '$in' => $params['container_id']
                ]
            ];
        }

        if (isset($params['source_product_id'])) {
            $matchQuery['shop_id'] = $params['source_marketplace']['shop_id'];
            $matchQuery['source_product_id'] = [
                '$in' => $params['source_product_id']
            ];
        }

        if ($type != 'admin') {
            $aggregation[] = [
                '$match' => $matchQuery
            ];
        }

        if (isset($params['next']) && $callType === 'getProduct') {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $aggregation[] = [
                '$match' => ['_id' => [
                    '$gt' => $nextDecoded['cursor'],
                ]],
            ];
        } elseif (isset($params['prev']) && $callType === 'getProduct') {
            $prevDecoded = json_decode(base64_decode($params['prev']), true);
            if (count($prevDecoded['cursor']) != 0) {
                $lastIndex = $prevDecoded['cursor'][count($prevDecoded['cursor']) - 1];
                $aggregation[] = [
                    '$match' => ['_id' => [
                        '$gte' => $lastIndex,
                    ]],
                ];
            }
        }

        if (isset($params['container_ids'])) {
            $aggregation[] = [
                '$match' => ['container_id' => [
                    '$in' => $params['container_ids']
                ]],
            ];
        }

        if (isset($params['app_code'])) {
            $aggregation[] = [
                '$match' => ['app_codes' => ['$in' => [$params['app_code']]]],
            ];
        }

        if (!(isset($params['no_variants_merged']) && $params['no_variants_merged'] == true)) {
            if (!isset($params['source_product_id'])) {
                $aggregation[] = [
                    '$graphLookup' => [
                        "from" => "product_container",
                        "startWith" => '$container_id',
                        "connectFromField" => "container_id",
                        "connectToField" => "container_id",
                        "as" => "variants",
                        "restrictSearchWithMatch" => [
                            'user_id' => $userId,
                            "type" => [
                                '$ne' => ProductContainer::PRODUCT_TYPE_VARIANT
                            ]
                        ],
                    ]
                ];
            } else {
                $aggregation[] = [
                    '$graphLookup' => [
                        "from" => "product_container",
                        "startWith" => '$source_product_id',
                        "connectFromField" => "source_product_id",
                        "connectToField" => "source_product_id",
                        "as" => "variants",
                        "restrictSearchWithMatch" => [
                            'user_id' => $userId,
                            "type" => [
                                '$ne' => ProductContainer::PRODUCT_TYPE_VARIANT
                            ]
                        ],
                    ]
                ];
            }
        }


        if ($orQuery !== []) {
            $aggregation[] = ['$match' => [
                '$or' => $orQuery,
            ]];
        }

        return $aggregation;
    }
}
