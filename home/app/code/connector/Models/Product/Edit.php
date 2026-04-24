<?php

namespace App\Connector\Models\Product;

use App\Core\Models\Base;
use Phalcon\Mvc\Model\Message;


class Edit extends Base
{
    protected $table = 'product_container';

    public $errors = [];


    public function getProduct($data)
    {
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getCollection();
        $aggregate = [];
        $product = [];
        if (isset($data['source_product_id'])) {
            $aggregate[] = ['$match' => [
                'source_product_id' => $data['source_product_id'],
                'user_id' => $userId,
                '$or' => [
                    [
                        'shop_id' => $data['sourceShopID'],
                        'source_marketplace' => $data['source_marketplace'],
                    ],
                    [
                        'shop_id' => $data['targetShopID'],
                        'target_marketplace' => $data['target_marketplace']
                    ]
                ]
            ]];
            $product = $collection->aggregate($aggregate)->toArray();
        } elseif (isset($data['container_id'])) {
            $aggregate[] = ['$match' => [
                'container_id' => $data['container_id'],
                'user_id' => $userId,
                '$or' => [
                    [
                        'shop_id' => $data['sourceShopID'],
                        'source_marketplace' => $data['source_marketplace'],
                    ],
                    [
                        'shop_id' => $data['targetShopID'],
                        'target_marketplace' => $data['target_marketplace']
                    ]
                ]
            ]];

            if (isset($data['cif_variant_wise'])) {
                $aggregate[] = ['$group' => [
                    '_id' => [
                        'container_id'      => '$container_id',
                        'source_product_id' => '$source_product_id',
                    ],
                    'sourceArr' => [
                        '$push' => [
                            '$cond' => [[
                                '$and' => [
                                    ['$eq' => ['$shop_id', $data['sourceShopID']]],
                                    ['$ne' => ['$source_marketplace', null]],
                                ]
                            ], '$$ROOT', '$$REMOVE']
                        ]
                    ],
                    'targetArr' => [
                        '$push' => [
                            '$cond' => [[
                                '$and' => [
                                    ['$eq' => ['$shop_id', $data['targetShopID']]],
                                    ['$ne' => ['$target_marketplace', null]],
                                ]
                            ], '$$ROOT', '$$REMOVE']
                        ]
                    ],
                ]];

                $aggregate[] = ['$project' => [
                    '_id'               => 0,
                    'container_id'      => '$_id.container_id',
                    'source_product_id' => '$_id.source_product_id',
                    'source'            => ['$arrayElemAt' => ['$sourceArr', 0]],
                    'target'            => ['$arrayElemAt' => ['$targetArr', 0]],
                ]];

                $aggregate[] = ['$addFields' => [
                    'isPinned' => [
                        '$and' => [
                            ['$ne' => ['$source', null]],
                            ['$eq' => ['$source.visibility', 'Catalog and Search']],
                        ]
                    ]
                ]];

                $facet = ['$facet' => [
                    'pinned' => [
                        ['$match' => ['isPinned' => true]],
                        ['$sort'  => ['source_product_id' => 1]],
                        ['$limit' => 1],
                    ],
                    'others' => [
                        ['$match' => ['isPinned' => ['$ne' => true]]],
                        ['$sort'  => ['source_product_id' => 1]],
                    ],
                ]];
                if(isset($data['skip'])){
                    $facet['others']['$skip'] = (int)$data['skip'];
                }
                if(isset($data['limit'])){
                    $facet['others']['$limit'] = (int)$data['limit'];
                }

                $aggregate[] = $facet;

                $aggregate[] = ['$project' => [
                    'items' => ['$concatArrays' => ['$pinned', '$others']]
                ]];

                $aggregate[] = ['$unwind' => '$items'];
                $aggregate[] = ['$replaceWith' => '$items'];
            }

            if(isset($data['projectData'])){
                $aggregate[] = ['$project' => $data['projectData']];
            }

            $product = $collection->aggregate($aggregate)->toArray();

            if (isset($data['cif_variant_wise'])) {
                foreach ($product as $value) {
                    $requiredFormattedProduct[] = $value['source'];
                    $requiredFormattedProduct[] = $value['target'];
                }
                $product = $requiredFormattedProduct;
            }
        }

        $variantProduts = [];
        $mappedProducts = [];

        $profileId = null;

        foreach ($product as $v) {
            if (isset($v['profile']) && $v['visibility'] == 'Catalog and Search') {
                $profileId = $this->getProfilId($data['targetShopID'], $v['profile']);
            }
        }

        if ($profileId) {
            $profileData = $this->getProfileInfo($profileId, $data);
        }


        foreach ($product as $value) {
            if (isset($value['source_product_id']) && isset($value['container_id'])) {
                $toMerge = isset($value['target_marketplace']) ? ['edited' => $value] : $value;
                $mappedProducts[$value['source_product_id']] = isset($mappedProducts[$value['source_product_id']]) ? [...(array)$mappedProducts[$value['source_product_id']], ...(array)$toMerge] : $toMerge;
                if ($value['source_product_id'] != $value['container_id']) {
                    $variantProduts[$value['source_product_id']] = isset($mappedProducts[$value['source_product_id']]) ? [...(array)$mappedProducts[$value['source_product_id']], ...(array)$toMerge] : $toMerge;
                }

                if ($profileId) {
                    $mappedProducts[$value['source_product_id']]['profile_info'] = $profileData;
                }
            }
        }

        $arr = array_values($mappedProducts);
        if (isset($data['source_product_id'])) {
            $varProduct = &$arr[0];
            if ($varProduct['visibility'] !== 'Catalog and Search') {
                $dataTOsend = $data;
                $dataTOsend['source_product_id'] = $varProduct['container_id'];
                $pr = $this->getProduct($dataTOsend);
                $varProduct['profile_info'] = isset($pr['data']['rows'][0]['profile_info']) ? $pr['data']['rows'][0]['profile_info'] : [];
                $varProduct['parent_details'] = $pr['data']['rows'][0];
            }
        }

        return ['success' => true, 'data' => ['rows' => $arr, 'user_id' => $userId]];
    }

    /**
     * description: save product in product container
     * @param $data
     * @param string $user_id
     * @param string $additionalData
     * @return array
     */
    public function saveProduct($data, $user_id = false, $additionalData = [])
    {

        $additionalData['source'] = [
            'marketplace' => $additionalData['source']['marketplace'] ?? $this->di->getRequester()->getSourceName(),
            'shopId' => (string) ($additionalData['source']['shopId']  ??  $this->di->getRequester()->getSourceId())
        ];
        $additionalData['target'] = [
            'marketplace' => $additionalData['target']['marketplace'] ?? $this->di->getRequester()->getTargetName(),
            'shopId' => (string) ($additionalData['target']['shopId']  ??  $this->di->getRequester()->getTargetId())
        ];

        $userId = $user_id ? $user_id : $this->di->getUser()->id;

        $validateSaveProduct = ['success' => true];
        //Call to a member function get() on null
        if (!isset($additionalData['dont_validate']) || !$additionalData['dont_validate']) {
            if (!empty($additionalData['target']['marketplace'])) {
                $model = $this->di->getConfig()->connectors->get($additionalData['target']['marketplace'])->get('source_model');
                if (method_exists($this->di->getObjectManager()->get($model), "validateSaveProduct")) {
                    $validateSaveProduct =  $this->di->getObjectManager()->get($model)->validateSaveProduct($data,  $userId, $additionalData);
                }
            }
        }

        //code proceed , terminate;
        if ($validateSaveProduct['success'] == false && $validateSaveProduct['code']  == 'terminate') {
            return $validateSaveProduct;
        }

        $marketplace_error_info = [];
        if (isset($validateSaveProduct['updated_data'])) {
            $data = $validateSaveProduct['updated_data'];
            $marketplace_error_info = $validateSaveProduct['data']['error_info'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productContainer = $mongo->setSource("product_container")->getCollection();

        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');

        $barcodeFail = [];
        $bulkOpArray = [];

        $marketplaceArr = [];
        foreach ($data as $key => $value) {
            if (isset($value['source_marketplace']) && isset($value['childInfo'])) {
                $res = $helper->marketplaceSaveAndUpdate($value);
                continue;
            }

            if ($this->validateSaveData($value)) {
                $validateBarcode = true;

                if ($validateBarcode == false) {
                    $barcodeFail[] = ['source_product_id' => $value['source_product_id'], 'sku' => $value['sku']];
                    if (!(isset($value['dont_validate_barcode']) && $value['dont_validate_barcode'])) {
                        continue;
                    }

                    unset($value['dont_validate_barcode']);
                }

                $condition = [];
                if (isset($value['source_marketplace']) && isset($value['childInfo'])) {

                    $res = $helper->marketplaceSaveAndUpdate($value);
                    continue;
                }
                $condition = [
                    'source_product_id' => $value['source_product_id'],
                    'container_id' => $value['container_id'],
                    'target_marketplace' => $value['target_marketplace'],
                    'shop_id' => $value['shop_id'],
                    'user_id' => $userId
                ];

                $filterData = $this->getFilterData($value);

                if (isset($value['marketplace'])) {
                    unset($value['marketplace']);
                }

                $marketplaceArr[] = [
                    'source_product_id' => $value['source_product_id'],
                    'childInfo' => $filterData,
                    'container_id' => $value['container_id'],
                    'source_marketplace' => $additionalData['source']['marketplace'],
                    'user_id' => $userId,
                    'unset' => isset($value['unset'])  ? array_keys($value['unset']) : [],
                    'source_shop_id' => $value['source_shop_id']
                ];
                $unset = (object)[];
                if (isset($value['unset'])) {
                    $unset = $value['unset'];
                    unset($value['unset']);
                    foreach ($unset as $k1 => $v1) {
                        if (isset($value[$k1])) {
                            unset($unset[$k1]);
                        }
                    }
                }

                if (isset($value['shops'])) {
                    $shops = $value['shops'];
                    unset($value['shops']);
                    $tempShops = [];
                    foreach ($shops as $key => $val) {
                        if ($key == 'category_settings') {
                            foreach ($val as $catKey => $catVal) {
                                $tempShops['shops.category_settings.' . $catKey] = $catVal;
                            }
                        } else {
                            $tempShops['shops.' . $key] = $val;
                        }
                    }

                    $value = [...(array)$value, ...$tempShops];
                }

                if (isset($value['category_settings'])) {
                    $shops = $value['category_settings'];
                    unset($value['category_settings']);
                    $tempShops = [];
                    foreach ($shops as $key => $val) {
                        if ($key == 'attributes_mapping') {
                            foreach ($val as $catKey => $catVal) {
                                $tempShops['category_settings.attributes_mapping.' . $catKey] = $catVal;
                            }
                        } else {
                            $tempShops['category_settings.' . $key] = $val;
                        }
                    }

                    $value = [...(array)$value, ...$tempShops];
                }

                $value['user_id'] = $userId;
                $value['updated_at'] = date('c');
                $bulkOpArray[] = [
                    'updateOne' => [
                        (object)$condition,
                        [
                            '$set' => (object)$value,
                            '$setOnInsert' => [
                                'created_at' => date('c')
                            ],
                            '$unset' => (object)$unset
                        ],
                        ['upsert' => true]
                    ]
                ];
            }
        };
        $saveData = empty($barcodeFail) ? true : false;
        if ($marketplaceArr !== []) {
            $helper->marketplaceSaveAndUpdate($marketplaceArr);
        }

        $nothingUpdated = false;
        if ($bulkOpArray !== []) {

            $repsonse = $productContainer->BulkWrite($bulkOpArray, ['w' => 1]);
        } else {
            $nothingUpdated = true;
        }

        //trigger after product save

        // $this->sendUpdatesToMarketplace($data);

        return ['success' => true, 'message' => $saveData ? 'Saved successfully' : 'Some product saved successfully and for some check Barcode Details', 'data' => ['barcode_fail' => $barcodeFail, 'nothing_update' => $nothingUpdated,  'errors' => $this->errors, 'bulk_info' => $nothingUpdated ? 'no update operation done' :  ['modified_count' => $repsonse->getModifiedCount(), 'inserted_count' => $repsonse->getInsertedCount() + $repsonse->getUpsertedCount()]], 'marketplace_error_info' => $marketplace_error_info, 'marketplace_message_error' => $validateSaveProduct['message'] ?? 'no error'];
    }

    public function sendUpdatesToMarketplace($data)
    {
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:afterProductEdit', $this, ['data' => $data]);
        return true;
    }


    public function validateTargetFunction($marketplace = "", $data = "")
    {
        $model = $this->di->getConfig()->connectors->get($marketplace)->get('source_model');
        if (!(method_exists($this->di->getObjectManager()->get($model), 'saveProductValidate'))) {
            return ['success' => true, "message" => "function_not_found"];
        }

        return $this->di->getObjectManager()->get($model)->saveProductValidate($data);
    }

    public function validateSaveData($value)
    {

        if (!isset($value['source_shop_id']) && isset($value['source_product_id'])) {
            $this->errors[] = ['source_product_id' => $value['source_product_id'], 'error_type' => 'source_shop_id missing'];
            return false;
        }

        return isset($value['source_product_id']) && isset($value['container_id']) && isset($value['target_marketplace']) && isset($value['shop_id']);
    }

    public function formatProfileDataForPriorityBasis($profile, $data)
    {

        $targetShopId = $data['targetShopID'];
        $targetMarketplace = $data['target_marketplace'];
        $sourceShopId = $data['sourceShopID'];

        $priorityArr = ['source_warehouse', 'source_shop', 'warehouse', 'shop', 'target', 'profile'];
        $holdArr = [];

        $idToArr = [];
        foreach ($profile as $value) {
            $idToArr[(string)$value['_id']] = $value;
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
        $holdArr['warehouse'] = isset($idToArr[(string)$holdArr['shop']['warehouses'][0]['id']]) ? $idToArr[(string)$holdArr['shop']['warehouses'][0]['id']] : [];
        $holdArr['source_warehouse'] = isset($idToArr[(string)$holdArr['source_shop']['warehouses'][0]['id']]) ? $idToArr[(string)$holdArr['source_shop']['warehouses'][0]['id']] : [];

        foreach ($priorityArr as $val) {
            if (count($attribute) !== 0 && count($category) !== 0 && count($dataToData)) {
                break;
            }

            if (isset($holdArr[$val]['category_id']) && count($category) == 0) {
                $category = $holdArr[$val]['category_id'];
            }

            if (isset($holdArr[$val]['attributes_mapping']) && count($attribute) == 0) {
                $attribute = $idToArr[(string)$holdArr[$val]['attributes_mapping']];
            }

            if (isset($holdArr[$val]['data']) && count($dataToData) == 0) {
                $dataToData = $holdArr[$val]['data'];
            }
        }

        return ['category_settings' => $category, 'attributes_mapping' => $attribute, 'data' => $dataToData];
    }

    public function getProfileInfo($profileId, $data)
    {
        $condition = [
            '$or' => [
                ['profile_id' => $profileId],
                ['_id' => $profileId]
            ]
        ];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("profile")->getCollection();
        $profileData = $collection->find($condition)->toArray();

        $profileInfo = $this->formatProfileDataForPriorityBasis($profileData, $data);
        return $profileInfo;
    }

    public function getProfilId($targetId, $data)
    {
        foreach ($data as $value) {
            if ($value['target_shop_id'] == $targetId) {
                return $value['profile_id'];
            }
        }

        return null;
    }


    public function getFilterData($dbData)
    {
        $filters = $this->di->getConfig()->allowed_filters ? $this->di->getConfig()->allowed_filters->toArray() : [];
        $filters[] = 'target_marketplace';
        $arr = [];
        foreach ($filters as $value) {
            if (isset($dbData[$value])) {
                $arr[$value] = $dbData[$value];
            }
        }

        return $arr;
    }

    /**
     * description: get edited doc of product from product container
     * @param $data -> array of source_product_id
     *
     * @return array
     */
    public function getEditedInBulk($data = [])
    {
        if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
            $userId = isset($data['user_id']) ? $data['user_id'] : $this->di->getUser()->id;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->setSource($this->table)->getCollection();
            $aggregate[] = [
                '$match' => [
                    "user_id" => $userId,
                    "source_shop_id" => $this->di->getRequester()->getSourceId() ?? false,
                    "shop_id" => $this->di->getRequester()->getTargetId() ?? false,
                    "target_marketplace" => $this->di->getRequester()->getTargetName() ?? false,
                    "source_product_id" => ['$in' => $data['source_product_ids']]
                ]
            ];

            if (isset($data['project_field']) && !empty($data['project_field'])) {
                $aggregate[] = [
                    '$project' => $data['project_field'] + ['_id' => 0, 'source_product_id' => 1]
                ];
            }

            $result = $collection->aggregate($aggregate)->toArray();
            return ['success' => true, "count" => count($result), 'message' => count($result) > 0 ? 'Fetched Successfully' : 'No Data Found', 'data' => $result];
        }
        return ['success' => false, 'message' => 'Params are missing'];
    }
}
