<?php

namespace App\Frontend\Components;

use App\Core\Components\Base;
class MarketplaceProduct extends Base
{
    /**
     * Create marketplace specific product table
     * @param $userId
     * @param $marketplace
     * @param array $product
     * @return bool
     */
    public function createMarketplaceProduct($userId, $marketplace, $product = [])
    {
        $this->di->getObjectManager()->get('\App\Connector\Models\MarketplaceProduct')
            ->createMarketplaceTables($userId, $marketplace, $product);
        return true;
    }

    public function getproductStatus($marketplace)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('product_container');

        $totalAggreagte = [];

        $totalAggreagte[] = ['$count' => 'count'];

        $aggregate = [];

        $aggregateStatus[] = [
            '$group' => [
                '_id' => '$status',
                'count' => ['$sum' => 1],
            ],
        ];

        $facet = [
            [
                '$match' => [
                    'user_id' => $marketplace['user_id'],
                ]
            ],
            [
                '$facet' => [
                    'total' => $totalAggreagte,
                    'uploaded' => $aggregate,
                    'status' => $aggregateStatus,
                ],
            ],
        ];

        $data = $collection->aggregate($facet)->toArray();
        $data = json_decode(json_encode($data), true);

        $newData = [];
        foreach ($data[0] as $key => $value) {
            if ($key === 'status') {
                $newData[$key] = $value;
            } elseif (isset($value[0]['count'])) {
                $newData[$key] = $value[0]['count'];
            } else {
                $newData[$key] = 0;
            }
        }

        return [
            'success' => true,
            'data' => $newData,
        ];
    }
}