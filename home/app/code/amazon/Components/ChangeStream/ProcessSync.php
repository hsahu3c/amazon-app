<?php

namespace App\Amazon\Components\ChangeStream;

use App\Core\Components\Base as Base;
use Exception;
use Aws\S3\S3Client;
use App\Amazon\Models\SourceModel;

class ProcessSync extends Base
{
    public function priceSync($streamData)
    {
        if (!empty($streamData)) {
            $nextPrevData = $streamData['nextPrevData'];
             foreach($nextPrevData as $shopId => $productData) {
                $params = [];
                $shopInfo = $this->getShopTypeAndLinkedIds($shopId);
                if (!empty($shopInfo)) {
                    if ($shopInfo['type'] == 'source') {
                        foreach($shopInfo['targets'] as $target) {
                            if (isset($target['disconnected']) && $target['disconnected']) {
                                continue;
                            }
                            $sourceProductIds = array_map('strval', array_keys($productData));
                            $params = [
                                'target' => [
                                    'marketplace' => $target['code'],
                                    'shopId' => $target['shop_id']
                                ],
                                'source' => [
                                    'marketplace' => $shopInfo['marketplace'],
                                    'shopId' => $shopInfo['id']
                                ],
                                'operationType' => 'price_sync',
                                'user_id' => $this->di->getUser()->id,
                                'source_product_ids' => $sourceProductIds,
                                 'process_type'=> 'automatic',
                                'activePage' => 1,
                                'filter' => [
                                    'items.status' => [
                                        "10" => [
                                            "Inactive",
                                            "Incomplete",
                                            "Active",
                                            "Submitted"
                                        ]
                                        ],
                                        // 'items.source_product_id' => [
                                        //     "10" => $sourceProductIds
                                        // ]
                                ],
                                'useRefinProduct' => true
                            ];
                            $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing')->startSync($params);
                        }
                    } else {
                        foreach($shopInfo['sources'] as $source) {
                            if (isset($source['disconnected']) && $source['disconnected']) {
                                continue;
                            }
                            $sourceProductIds = array_map('strval', array_keys($productData));
                            $params = [
                                'source' => [
                                    'marketplace' => $source['code'],
                                    'shopId' => $source['shop_id']
                                ],
                                'target' => [
                                    'marketplace' => $shopInfo['marketplace'],
                                    'shopId' => $shopInfo['id']
                                ],
                                'operationType' => 'price_sync',
                                'user_id' => $this->di->getUser()->id,
                                'source_product_ids' => $sourceProductIds,
                                'process_type'=> 'automatic',
                                'activePage' => 1,
                                'filter' => [
                                    'items.status' => [
                                        "10" => [
                                            "Inactive",
                                            "Incomplete",
                                            "Active",
                                            "Submitted"
                                        ]
                                        ],
                                        // 'items.source_product_id' => [
                                        //     "10" => $sourceProductIds
                                        // ]
                                ],
                                'useRefinProduct' => true
                            ];
                            $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing')->startSync($params);
                        }
                    }
                }
             }
        }
    }

    public function proceedWithSync($dataToSent)
    {
        $sourceModel = $this->di->getObjectManager()->get(SourceModel::class);
        $sourceModel->startSync($dataToSent);
    }

    public function getShopTypeAndLinkedIds($shopId)
    {
        $shops = $this->di->getUser()->shops;
        $shopData = [];
        if (!empty($shops)) {
            foreach($shops as $shop) {
                if (isset($shop['_id']) && ($shop['_id'] == $shopId)) {
                    if (isset($shop['targets'])) {
                        $shopData = [
                            'id' => $shop['_id'],
                            'marketplace' => $shop['marketplace'],
                            'targets' => $shop['targets'],
                            'type' => 'source'
                        ];
                    } else {
                        $shopData = [
                            'id' => $shop['_id'],
                            'marketplace' => $shop['marketplace'],
                            'sources' => $shop['sources'],
                            'type' => 'target'
                        ];
                    }
                }
            }
        }
        return $shopData;
    }

    public function imageSync()
    {

    }

    public function valueMappingSync()
    {

    }

    public function getFormattedProductsDataByQuery($query, $marketplaceName)
    {
        $baseMongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $baseMongo->getCollectionForTable('product_container');
        $products = $productCollection->find($query, ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();
        $formattedProducts = [];
        if (!empty($products)) {
            foreach ($products as $product) {
                if (isset($product['description'])) {
                    unset($product['description']);
                }
    
                if (isset($product['target_marketplace'])) {
                    if (!empty($formattedProducts)) {
                        $formattedProducts['target'] = $product;
                    }
                } else {
                    $formattedProducts['source'] = $product;
                    $formattedProducts['source']['isParent'] = $product['visibility'] === 'Catalog and Search' ? true : false;
                }
            }
        }
        $finalData = [];
        $statusArray = ['active', 'inactive', 'incomplete', 'uploaded'];
        if (!empty($formattedProducts['source']) && !empty($formattedProducts['target']) && isset($formattedProducts['target']['status'])) {
            if (in_array(strtolower($formattedProducts['target']['status']), $statusArray)) {
                $finalData = $formattedProducts['source'];
                $finalData['edited'] = $formattedProducts['target'];
                if (isset($formattedProducts['source']['isParent']) && !$formattedProducts['source']['isParent']) {
                    unset($query['source_product_id']);
                    $query['container_id'] = (string)$formattedProducts['source']['container_id'] ?? '';
                    $query['source_product_id'] = (string)$formattedProducts['source']['container_id'] ?? '';
                    $parentData = $productCollection->find($query, ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();
                     
                    if (!empty($parentData)) {
                        $profile = [];
                        foreach($parentData as $data) {
                            if (isset($data['description'])) {
                                unset($data['description']);
                            }
                            if (isset($data['target_marketplace'])) {
                                $finalData['parent_details']['edited'] = $data;
                            } else {
                                if (!empty($finalData['parent_details']['edited'])) {
                                    $finalData['parent_details'] = array_merge($data, $finalData['parent_details']);//nned tp check
                                    $finalData['parent_details'] = $data;
                                } else {
                                    $finalData['parent_details'] = $data;
                                    if (!empty($data['profile'])) {
                                        foreach ($data['profile'] as  $profile) {
                                            $profile[$profile['target_shop_id']][$profile['profile_name']] =  $this->getProfileInfo($profile,$product['shop_id'], $marketplaceName);
                                        }
                                    }
                                }
                            }
                        }
                        $finalData['profile_info'] = $profile;
                        $finalData['parent_details']['profile_info'] = $profile;
                    }
                }
            }
        }
        return $finalData;
    }

    public function getProfileInfo($prf,$shopId, $marketplaceName)
    {   
        $tempProfile = $this->di->getObjectManager()->get("\App\Connector\Models\Product\Edit")->getProfileInfo($prf['profile_id'], [
            'targetShopID' => $prf['target_shop_id'],
            'target_marketplace' => $marketplaceName,
            'sourceShopID' => $shopId
        ]);
        return $tempProfile;
    }
}