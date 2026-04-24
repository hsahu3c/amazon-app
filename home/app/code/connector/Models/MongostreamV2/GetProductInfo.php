<?php

namespace App\Connector\Models\MongostreamV2;

use App\Core\Models\Base;


class GetProductInfo extends Base
{
    public $userId;

    public function init(): void
    {
        $this->userId = $this->di->getUser()->getUserId();
    }

    public function getProductData($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getCollection();
        $aggregate = $this->buildAggregateQuery($data);

        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];

        return $collection->aggregate($aggregate, $options)->toArray();
    }

    private function buildAggregateQuery(array $data): array
    {
        $aggregate = [];
        $match = [
            'user_id' => $this->userId,
            '$or' => $this->buildOrConditions($data)
        ];

        if (isset($data['source_product_id'])) {
            $match['source_product_id'] = $data['source_product_id'];
        } elseif (isset($data['container_id'])) {
            $match['container_id'] = $data['container_id'];
        }

        $aggregate[] = ['$match' => $match];
        if (isset($data['project'])) {
            $aggregate[] = ['$project' => $data['project']];
        }

        return $aggregate;
    }

    private function buildOrConditions(array $data): array
    {
        $conditions = [
            [
                'shop_id' => $data['source']['shopId'],
                'source_marketplace' => $data['source']['marketplace']
            ]
        ];

        if (isset($data['targets'])) {
            foreach ($data['targets'] as $target) {
                if (isset($target['shopId'], $target['marketplace']) || isset($target['shop_id'], $target['code']))
                    $conditions[] = [
                        'shop_id' => $target['shopId'] ?? $target['shop_id'],
                        'target_marketplace' => $target['marketplace'] ?? $target['code']
                    ];
            }
        }

        return $conditions;
    }

    public function handleProfiles($product, $data)
    {
        $profileData = null;
        foreach ($product as $item) {
            if (isset($item['profile']) && $item['visibility'] == 'Catalog and Search') {
                $profileId = $this->getProfileId($data['source']['shopId'], $item['profile']);
                if ($profileId) {
                    $profileData = $this->getProfileInfo($profileId, $data);
                }

                break;
            }
        }

        return $profileData;
    }

    public function mapProducts($product, $profileData, $appendProfileData = false)
    {
        $mappedProducts = [];
        foreach ($product as $value) {
            if (isset($value['source_product_id']) && isset($value['container_id'])) {
                $sourceProductId = $value['source_product_id'];
                $mappedProducts[$sourceProductId] ??= $value;

                // Check if current product data is from a target shop and append it under 'edited'
                if (isset($value['target_marketplace']) && isset($value['shop_id'])) {
                    $shopId = $value['shop_id'];
                    $mappedProducts[$sourceProductId]['edited'][$shopId] = $value;
                }

                if ($appendProfileData && $profileData) {
                    $mappedProducts[$sourceProductId]['profile_info'] = $profileData;
                }
            }
        }

        return array_values($mappedProducts);
    }

    public function processProductData($data, $appendProfileData = true)
    {
        $this->init();
        $product = $this->getProductData($data);
        if ($appendProfileData) {
            $profileData = $this->handleProfiles($product, $data);
        }

        $mappedProducts = $this->mapProducts($product, $profileData, $appendProfileData);

        return ['success' => true, 'data' => ['rows' => $mappedProducts, 'user_id' => $this->userId]];
    }
}
