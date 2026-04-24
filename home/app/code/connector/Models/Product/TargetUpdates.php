<?php

namespace App\Connector\Models\Product;

use App\Core\Models\Base;


class TargetUpdates extends Base
{

    public $limit = 10000;

    public $userId = "";

    public $_mongo;

    public $_userDetails;

    public $_formattedRefineProductDetails = [];

    public $_childKeyRefineProduct = 'items';

    public $sourceMarketplace = false;

    public $abortRefineSyncingForMarketplacesArr = [];

    public function init(&$data = []): void
    {
        $this->_formattedRefineProductDetails = [];
        if (!$this->_mongo) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $this->_mongo = $mongo->getCollectionForTable('product_container');
        }

        if (isset($data['user_id'])) {
            $this->userId = $data['user_id'];
        } else {
            $this->userId = $this->di->getUser()->id;
            $data['user_id'] = $this->userId;
        }

        if ($this->di->getConfig()->get('abort_refine_syncing')) {
            $this->abortRefineSyncingForMarketplacesArr = $this->di->getConfig()->abort_refine_syncing->toArray() ?? [];
        }
    }

    public function getAllTargetIds($data)
    {
        $sourceId = $data['source']['shopId'];

        $userShops = $this->di->getUser()->shops;

        $allTargetIds = [];

        foreach ($userShops as $v) {
            if ($v['_id'] == $sourceId) {
                if (isset($v['targets'])) {
                    foreach ($v['targets'] as $vT) {
                        if (!$this->validateRefineAbortProcess($vT['code'] ?? $vT['marketplace'])) {
                            $allTargetIds[] = [
                                'marketplace' => $vT['code'] ?? $vT['marketplace'],
                                'shopId' => $vT['shop_id']
                            ];
                        }
                    }
                }

                break;
            }
        }

        return $allTargetIds;
    }

    public function getQueryForOneProduct($data)
    {
        return ['user_id' => $this->userId, 'shop_id' => $data['source']['shopId'], 'source_product_id' => $data['source_product_id']];
    }

    public function getProduct($data)
    {
        $aggregate = [];
        $aggregate[] = [
            '$match' => $this->getQueryForOneProduct($data)
        ];
        $lookup = [
            'from' => 'product_container', // target collection
            'let' => [ 'srcContainerId' => '$container_id', 'srcSourceId' => '$source_product_id' ],
            'pipeline' => [
                [
                    '$match' => [
                        '$expr' => [
                            '$and' => [
                                [ '$eq' => [ '$user_id', $this->_userId ] ],               // static user_id variable
                                [ '$eq' => [ '$container_id', '$$srcContainerId' ] ]
                            ]
                        ]
                    ]
                ],
                [
                    '$project' =>  [ 
                        "_id"=> 0, 
                        "category_settings"=> 0, 
                        "description"=> 0, 
                        "marketplace"=> 0, 
                        "locations" => 0, 
                        "location" => 0,
                        "parentMetafield" => 0,
                        "variantMetafield" => 0,
                        "additional_images" => 0,
                        "seo" => 0,
                        "last_activities" => 0,
                    ]
                ]
            ],
            'as' => 'marketplace'
        ];
        $aggregate[] = ['$lookup' => $lookup];

        return $this->_mongo->aggregate($aggregate, ['typeMap'   => ['root' => 'array', 'document' => 'array']])->toArray();
        // return $this->_mongo->find($this->getQueryForOneProduct($data))->toArray();
    }

    public function getFormattedForSingleTarget($dbData, $target, $_mongoIndex = null)
    {
        if ($_mongoIndex) {
            $this->_mongo = $_mongoIndex;
        }

        $this->sourceMarketplace = $dbData['source_marketplace']; // set source marketplace
        $this->init($target);
        $this->formateMarketplaceArray($target, $dbData);
        if ($this->di->has('isDev') && $this->di->get('isDev')) {
            $this->di->getLog()->logContent('marketplace data =' . print_r(['product_data' => $dbData, 'target' => $target], true), 'info', 'inUPdateTargetFike.log');
        }

        return ['success' => true, 'data' => $this->_formattedRefineProductDetails];
    }

    public function updateTargetForNewProduct($data, $dbData = false, $returnMarketPlace = false, $_mongoIndex = null, $allTargetsIdsReci = null)
    {
        if ($_mongoIndex) {
            $this->_mongo = $_mongoIndex;
        }

        if (!(isset($data['source']['shopId'], $data['source']['marketplace'], $data['user_id'], $data['source_product_id']))) {
            return ['success' => false, 'message' => 'source id or source marketplace or user_id or source_product_id missing'];
        }

        $this->sourceMarketplace = $data['source']['marketplace']; // set source marketplace
        $this->init($data);
        $allTargetIds = $allTargetsIdsReci  ? $allTargetsIdsReci : $this->getAllTargetIds($data);
        if (count($allTargetIds) == 0) {
            return ['success' => false, 'message' => 'No target Found'];
        }

        if ($dbData) {
            $product = [];
            $product[] = $dbData;
        } else {
            $product = $this->getProduct($data);
            if (count($product) == 0 && isset($product[0]['marketplace'])) {
                return ['success' => false, 'message' => 'No product Found or marketplace key not present'];
            }
        }


        $updatedMarketplace = [];
        foreach ($allTargetIds as $value) {
            $updatedMarketplace = $this->formateMarketplaceArray($value, $product[0]);
            $product[0]['marketplace'] = $updatedMarketplace;
        }

        if ($returnMarketPlace) {
            return ['success' => true, 'data' => $this->_formattedRefineProductDetails];
        }

        // $this->_mongo->updateMany($this->getQueryForOneProduct($data), ['$set' => ['marketplace' => $updatedMarketplace]]);
        return ['success' => true, 'message' => 'succesfully added'];
    }

    public function updateMarketplaceTargetValues($data)
    {
        $data['limit'] = $this->limit;
        $this->init($data);

        return $this->sendDataInSqs(['data' => ['params' => $data]]);
    }

    public function getFilterData($dbData, $unsetShopId = false)
    {
        $filters = $this->di->getConfig()->allowed_filters ? $this->di->getConfig()->allowed_filters->toArray() : [];
        $arr = [];
        foreach ($filters as $value) {
            if (isset($dbData[$value])) {
                $arr[$value] = $dbData[$value];
            }
        }

        if ($unsetShopId) {
            unset($arr['shop_id']);
        }

        $arr['direct'] = true;
        $arr['source_marketplace'] = 'shopify';
        return $arr;
    }

    public function sendDataInSqs($data)
    {
        $params = $data['data']['params'];
        $params['activePage'] = 1;
        $this->init($params);

        new  \App\Connector\Components\Profile\SQSWorker;
        $index = new \App\Connector\Components\Profile\GetProductProfileMerge;
        $products = $index->getProducts($params, $this->getAggregate($params));
        if ($products['success']) {
            $rows = &$products['data']['rows'];
            foreach ($rows as &$value) {
                $target = $params['target'];
            }
        }

        return $products;
    }

    public function getAggregate($params)
    {
        $aggregate = [];
        $aggregate[] = [
            '$match' => [
                'user_id' => $this->userId,
                'source_marketplace' => 'shopify',
                'container_id' => '4597654028425'
            ],
        ];
        $this->nextPrevActivePageHandle($params, $aggregate);
        return $aggregate;
    }

    public function unsetBy(&$data, $key): void
    {
        if (isset($data[$key])) {
            unset($data[$key]);
        }
    }

    public function createTargetWithSourceData($target, $sourceArr)
    {
        $targetArr = json_decode(json_encode($sourceArr), true);
        $targetArr['target_marketplace'] = $target['marketplace'];
        $targetArr['shop_id'] = $target['shopId'];
        $this->unsetBy($targetArr, 'direct');
        $this->unsetBy($targetArr, 'source_marketplace');
        return (array)$targetArr;
    }

    public function formateMarketplaceArray($target, $product)
    {
        $marketplace = $product['marketplace'] ?? [];
        $notOfCurrentTarget = [];
        $currenTargetDetaisWithSource = [];
        $targetId = $target['shopId'] ?? null;

        $parentTitle = $product['title'] ?? '';
        foreach ($marketplace as $value) {
            if (isset($value['source_product_id'])) {
                $dontPush = false;
                $val = $currenTargetDetaisWithSource[$value['source_product_id']] ?? ['target_marketplace' => $target['marketplace'] ?? null];
                if ((isset($value['direct']) && $value['direct'] == true) || isset($value['source_marketplace'])) {
                    $currenTargetDetaisWithSource[$value['source_product_id']] = array_merge($this->createTargetWithSourceData($target, $value), $val);
                }

                if ($value['shop_id'] == $targetId) {
                    $currenTargetDetaisWithSource[$value['source_product_id']] = array_merge($val, (array)$value);
                    $dontPush = true;
                }

                if (isset($currenTargetDetaisWithSource[$value['source_product_id']]) && $currenTargetDetaisWithSource[$value['source_product_id']]['source_product_id'] == $product['source_product_id']) {
                    $parentTitle = $currenTargetDetaisWithSource[$value['source_product_id']]['title'] ?? '';
                }

                !$dontPush && $notOfCurrentTarget[] = $value;
            }
        }

        $valuesCurrentTarget = array_values($currenTargetDetaisWithSource);
        $updatedMarketplace = [...$notOfCurrentTarget, ...$valuesCurrentTarget];

        if ($valuesCurrentTarget !== [] && !$this->validateRefineAbortProcess($target['marketplace'])) {
            $this->_formattedRefineProductDetails[$targetId] = $this->getUpadateQueryForRefineProduct($product, $valuesCurrentTarget, $targetId, $parentTitle);
        }

        return $updatedMarketplace;
    }

    public function getUpadateQueryForRefineProduct($product, $valuesCurrentTarget, $targetId, $parentTitle)
    {
        $extraKeys = $this->di->getConfig()->get('refine_additional_keys')->toArray();

        $additionalInfo = [];
        foreach ($extraKeys as $v) {
            if (isset($product[$v])) {
                $additionalInfo[$v] = $product[$v];
            }
        }

        unset($additionalInfo['profile']);

        if (isset($product["profile"]) && count($product["profile"]) > 0) {
            foreach ($product["profile"] as $key) {
                if ($key['target_shop_id'] == $targetId) {
                    $temp = [
                        'profile_name' => $key['profile_name'],
                        'profile_id' => new \MongoDB\BSON\ObjectID($key['profile_id']['$oid']),
                        'type' => $key['type']
                    ];
                    $additionalInfo['profile'] = $temp;
                }
            }
        }
        // print_r($valuesCurrentTarget);
        foreach($valuesCurrentTarget as $key => $value) {
            if ( $value['source_product_id'] == $value['container_id'] ) continue;
            if (!isset($value['title']) || !isset($value['quantity']) || !isset($value['price']) || !isset($value['sku']) ) {
                unset($valuesCurrentTarget[$key]);
            }
        }
        $valuesCurrentTarget = array_values($valuesCurrentTarget);

        // unset($additionalInfo['locale']);

        // if (isset($product["locale"])) {
        //     foreach ($product["locale"] as $key=>$value) {
        //         foreach($value as $k2=>$v2){

        //         }
        //     }
        // }

        return [
            'updateOne' => [
                $this->findQuery($product, $targetId, false),
                [
                    '$set' => $this->findQuery($product, $targetId) + [
                        $this->_childKeyRefineProduct => $valuesCurrentTarget,
                        'target_product_id' => $product['asin'] ?? null,
                        'title' => $parentTitle,
                        'updated_at' => date('c')
                    ] + $additionalInfo,
                    '$setOnInsert' => [
                        'created_at' => date('c')
                    ]
                ],
                [
                    'upsert' => true
                ]
            ]
        ];
    }

    public function findQuery($product, $targetId, $applyNullTargetOr = false)
    {
        $targetShopId = $applyNullTargetOr ? ['target_shop_id' => ['$in' => [null, $targetId]]] : ['target_shop_id' => $targetId];

        return [
            'user_id' => $this->userId, 'source_shop_id' => $product['shop_id'], 'source_product_id' => $product['source_product_id'],
            'container_id' => $product['container_id']
        ] + $targetShopId;
    }

    public function nextPrevActivePageHandle($data, &$aggregate): void
    {
        if (isset($data['next'])) {
            $nextDecoded = json_decode(base64_decode($data['next']), true);
            $aggregate[] = [
                '$match' => ['_id' => [
                    '$gt' => (float)$nextDecoded['cursor'],
                ]],
            ];
        } else if (isset($data['prev'])) {
            $nextDecoded = json_decode(base64_decode($data['prev']), true);
            $aggregate[] = [
                '$match' => ['_id' => [
                    '$gte' => (float)$nextDecoded['cursor'],
                ]],
            ];
        } else if (isset($data['activePage'])) {
            $limit = isset($data['limit']) ? $data['limit'] : $this->default_limit;
            $aggregate[] = [
                '$skip' => ($data['activePage'] - 1) * $limit
            ];
        }
    }

    /**
     * @return boolean value for source_target marketplace exists
     */
    public function validateRefineAbortProcess($targetMarketplace = '')
    {
        $data = $this->sourceMarketplace . '_' . $targetMarketplace;
        if (in_array($data, $this->abortRefineSyncingForMarketplacesArr)) {
            return true;
        }
        return false;
    }
}
