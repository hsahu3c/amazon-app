<?php

namespace App\Amazon\Components\Order;

use App\Core\Components\Base;

class Grid extends Base
{
    public static $defaultPagination = 10;

    const ORDER_CONTAINER = 'order_container';

    const ATLAS_INDEX_NAME = 'order_stat';

    public function getData(array $params): array
    {
        $limit = $params['count'] ?? self::$defaultPagination;
        $page = $params['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;

        $aggregation = [];

        $compoundQuery = $this->buildQuery($params);

        if (!$compoundQuery['success']) {
            return $compoundQuery;
        }

        $compoundQuery = $compoundQuery['data'];

        $aggregation[] = [
            '$search' => [
                'index' => self::ATLAS_INDEX_NAME,
                'compound' => $compoundQuery,
                'sort' => [
                    '_id' => -1
                ]
            ]
        ];

        if (!empty($params['projection'])) {
            $aggregation[] = $this->buildProjectionStage($params['projection']);
        }

        $sortBy = $params['sort']['by'] ?? '_id';
        $sortOrder = isset($params['sort']['order']) && is_numeric($params['sort']['order'])
            ? intval($params['sort']['order']) : -1;

        // $aggregation[] = [
        //     '$sort' => [
        //         $sortBy => $sortOrder
        //     ]
        // ];

        $aggregation[] = ['$skip' => (int)$offset];
        $aggregation[] = ['$limit' => (int)$limit];

        try {
            $orderContainerCollection = $this->getMongoCollection();

            $rows = $orderContainerCollection->aggregate($aggregation)->toArray();

            $responseData = [
                'success' => true,
                'query' => $aggregation,
            ];

            $responseData['data']['count'] = $this->getCount($params)['data']['count'][0]['count']['total'] ?? 0;
            $responseData['data']['rows'] = $rows;

            return $responseData;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Unable to fetch orders at this time.',
                'error' => 'Failed to search: ' . $e->getMessage(),
                'query' => $compoundQuery
            ];
        }
    }

    public function getCount(array $params): array
    {
        $compoundQuery = $this->buildQuery($params);

        if (!$compoundQuery['success']) {
            return $compoundQuery;
        }
        $compoundQuery = $compoundQuery['data'];
        try {
            $orderContainerCollection = $this->getMongoCollection();

            $countResult = $orderContainerCollection->aggregate([
                [
                    '$searchMeta' => [
                        'index' => self::ATLAS_INDEX_NAME,
                        'compound' => $compoundQuery,
                        'count' => [
                            'type' => 'total'
                        ]
                    ]
                ]
            ])->toArray();

            return [
                'success' => true,
                'data' => ['count' => $countResult],
                'query' => $compoundQuery
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to search: ' . $e->getMessage(),
                'message' => 'Unable to fetch orders count at this time.',
                'query' => $compoundQuery
            ];
        }
    }

    public function getCountsByKeys($params)
    {
        if (empty($params['filters'])) {
            return ['success' => false, 'message' => 'No filters found'];
        }

        $response = [];
        foreach ($params['filters'] as $key => $filter) {
            $getCountResponse = $this->getCount($filter);
            if ($getCountResponse['success']) {
                $response[$key] = $getCountResponse['data']['count']['total'] ?? 0;
            }
        }

        if(empty($response)){
            return ['success' => false, 'message' => 'No data found'];
        }

        return ['success' => true, 'data' => $response];
    }

    private function buildQuery(array $params): array
    {
        $must = $params['filters']['must'] ?? [];
        $mustNot = $params['filters']['mustNot'] ?? [];
        $should = $params['filters']['should'] ?? [];
        $searchWith = $params['filters']['search_with'] ?? [
            'marketplace_reference_id'
        ];

        $minimumShouldMatch = $params['filters']['min_should_match'] ?? 1;

        $globalSearch = $params['filters']['global_search'] ?? null;

        if ($globalSearch && in_array('marketplace_reference_id', $searchWith)) {
            $should[] = [
                'autocomplete' => [
                    'query' => $globalSearch,
                    'path' => 'marketplace_reference_id',
                ]
            ];
        }

        $must[] = [
            'equals' => [
                'path' => 'user_id',
                'value' => $this->di->getUser()->id
            ]
        ];

        $compoundQuery = [
            'must' => $must,
        ];

        if (!empty($mustNot)) {
            $compoundQuery['mustNot'] = $mustNot;
        }

        if (!empty($should)) {
            $compoundQuery['should'] = $should;
            $compoundQuery['minimumShouldMatch'] = $minimumShouldMatch;
        }

        return [
            'success' => true,
            'data' => $compoundQuery
        ];
    }

    private function getMongoCollection($collection = self::ORDER_CONTAINER)
    {
        return $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')
            ->getCollection($collection);
    }

    private function buildProjectionStage(array $projection): array
    {
        return [
            '$project' => array_map(function ($value) {
                return is_numeric($value) ? intval($value) : $value;
            }, $projection)
        ];
    }
}
