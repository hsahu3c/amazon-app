<?php

namespace App\Connector\Models\Product;

use App\Core\Models\Base;
use Phalcon\Mvc\Model\Message;

use function PHPSTORM_META\type;

class BulkEdit extends Base
{
    protected $table = 'product_container';

    public $errors = [];

    public $limit = 50;

    public function getProductforBulkEdit($data)
    {
        $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("refine_product")->getCollection();
        $aggregate = [];


        $query = [];
        if (isset($data['container_ids'])) {
            $allSourceProductIds = [];
            $ids = "container_id";
            foreach ($data['container_ids'] as $val) {
                $allSourceProductIds[] = $val;
            }

            $query = [
                'user_id' => $this->di->getUser()->id,
                'container_id' => ['$in' => $data['container_ids']],
                // 'source_product_id' => ['$in' => $data['container_ids']],
            ];
        } else {
            $allSourceProductIds = [];
            $ids = "source_product_id";
            $aggregate = [
                [
                    '$match' =>
                        [
                            'user_id' => $this->di->getUser()->id,
                            'source_shop_id' => $this->di->getRequester()->getSourceId(),
                            'target_shop_id' => $this->di->getRequester()->getTargetId(),
                        ]
                ],
                ['$sort' => ['_id' => 1]],
                ['$project' => ['_id' => 0, 'source_product_id' => '$items.source_product_id']]



            ];
            if (isset($data['count'])) {
                $this->limit = $data['count'];
            }

            $aggregate[] = [
                '$limit' => $this->limit
            ];
            $response = $collection->aggregate($aggregate)->toArray();

            foreach ($response as $item) {
                $sourceProductIds = (array) $item["source_product_id"];
                $allSourceProductIds = [...$allSourceProductIds, ...$sourceProductIds];
            }


            $query = [
                'user_id' => $this->di->getUser()->id,
                'source_product_id' => ['$in' => $allSourceProductIds],
            ];

        }

        $product_container = $mongo->setSource("product_container")->getCollection();

        // print_r($product_container);die;
        $allData = $product_container->find($query)->toArray();
        // print_r($allData);die;

        $prepareData = [];
        foreach ($allSourceProductIds as $sourceId) {
            $sId = [];
            $containerId = "";
            foreach ($allData as $productValue) {
                if ($productValue[$ids] == $sourceId) {
                    // $sId[] = $productValue;
                    if (isset($productValue['source_marketplace']) && $productValue['source_marketplace'] == 'shopify') {
                        $containerId = $productValue['container_id'];
                        $sId[] = $productValue;
                    }

                    if (isset($productValue['target_marketplace']) && $productValue['target_marketplace'] == 'amazon') {
                        // if(!empty($sId)){
                        $sId['edited'] = $productValue;
                        // }else{
                        // $sId[] = $productValue;
                        // }

                    }
                }

                // print_r($productValue);die;
            }

            if (isset($sId['edited'])) {
                $sId[0]['edited'] = $sId['edited'];
                unset($sId['edited']);
            }

            $prepareData[] = $sId[0];
            // print_r($prepareData);die;
        }

        // print_r($prepareData);die("gggggg");
        $res = $this->prepareDataForBulkEdit($prepareData);
        // print_r($res);die;
        return $res;
        // $parentProduct = [];
        // $editedProduct = [];
        // $childProduct = [];
        // $other = [];
        // foreach ($allData as $key => $value) {
        //     if (isset($value['type']) && $value['type'] == "simple" && isset($value['visibility']) && $value['visibility'] == "Catalog and Search" || isset($value['type']) && $value['type'] == "variation" && isset($value['visibility']) && $value['visibility'] == "Catalog and Search") {
        //         $parentProduct[] = $value;
        //     } elseif (isset($value['type']) && $value['type'] == "simple" && isset($value['visibility']) && $value['visibility'] == "Not Visible Individually") {
        //         $childProduct[] = $value;
        //     } elseif (isset($value['target_marketplace']) && $value['target_marketplace'] == "amazon") {
        //         $editedProduct[] = $value;

        //     } else {
        //         $other[] = $value;
        //     }
        // }



        // // Merge edited products into child products based on source_product_id
        // $mergedChildProducts = [];
        // $allNewChilds = [];
        // foreach ($childProduct as $childKey => $child) {
        //     $child['edited'] = []; // Initialize an empty 'edited' key
        //     $newchildData = [];

        //     foreach ($editedProduct as $editedKey => $edited) {
        //         if ($child['source_product_id'] === $edited['source_product_id']) {
        //             // Merge edited product into the 'edited' key of child product
        //             // $child['edited'] = $edited;
        //             $newchildData['container_id'] = $child['container_id'];
        //             $newchildData['source_product_id'] = $child['source_product_id'];
        //             $newchildData['title'] = $child['title'];
        //             $newchildData['main_image'] = $child['main_image'];
        //             $newchildData['edited'] = $edited;
        //             // $mergedChildProducts[] = $child;
        //             // Remove the merged edited product from the editedProduct array
        //             // unset($editedProduct[$editedKey]);
        //             $allNewChilds[] = $newchildData;
        //         }
        //     }
        //     if (empty($newchildData)) {
        //         $newchildData['container_id'] = $child['container_id'];
        //         $newchildData['source_product_id'] = $child['source_product_id'];
        //         $newchildData['title'] = $child['title'];
        //         $newchildData['main_image'] = $child['main_image'];
        //         $newchildData['edited'] = [];
        //         $allNewChilds[] = $newchildData;
        //     }

        //     // If no edits were found, just keep the original child product
        //     // if (empty($child['edited'])) {
        //     //     $mergedChildProducts[] = $child;
        //     // }

        // }

        // $finalProduct = [];
        // foreach ($parentProduct as $key => $parent) {
        //     $singleProduct = [];
        //     // $product=$parent['marketplace'];
        //     // foreach ($product as $key => $marketplace) {
        //     // if(isset($marketplace['target_marketplace'])){
        //     if (isset($parent['type']) && $parent['type'] == "variation" && isset($parent['visibility']) && $parent['visibility'] == "Catalog and Search") {
        //         foreach ($allNewChilds as $key => $childs) {
        //             if ($childs['container_id'] == $parent['container_id']) {
        //                 $singleProduct['title'] = $parent['title'];
        //                 $singleProduct['main_image'] = $parent['main_image'];
        //                 $singleProduct['source_product_id'] = $parent['source_product_id'];
        //                 $singleProduct['container_id'] = $parent['container_id'];
        //                 $singleProduct['type'] = 'variation';
        //                 $singleProduct['childs'][] = $childs;
        //             }
        //         }
        //         $finalProduct[] = $singleProduct;


        //     } elseif (isset($parent['type']) && $parent['type'] == "simple" && isset($parent['visibility']) && $parent['visibility'] == "Catalog and Search") {
        //         if (!empty($edited)) {
        //             foreach ($editedProduct as $editedKey => $edited) {
        //                 if ($parent['source_product_id'] == $edited['source_product_id']) {
        //                     $singleProduct['title'] = $parent['title'];
        //                     $singleProduct['main_image'] = $parent['main_image'];
        //                     $singleProduct['source_product_id'] = $parent['source_product_id'];
        //                     $singleProduct['container_id'] = $parent['container_id'];
        //                     $singleProduct['type'] = 'simple';
        //                     $singleProduct['edited'] = $edited;
        //                 }
        //                 if (empty($singleProduct)) {
        //                     $singleProduct['container_id'] = $parent['container_id'];
        //                     $singleProduct['source_product_id'] = $parent['source_product_id'];
        //                     $singleProduct['title'] = $parent['title'];
        //                     $singleProduct['type'] = 'simple';
        //                     $singleProduct['main_image'] = $parent['main_image'];
        //                     $singleProduct['edited'] = [];
        //                 }
        //                 $finalProduct[] = $singleProduct;
        //             }

        //         } else {
        //             $singleProduct['container_id'] = $parent['container_id'];
        //             $singleProduct['source_product_id'] = $parent['source_product_id'];
        //             $singleProduct['title'] = $parent['title'];
        //             $singleProduct['type'] = 'simple';
        //             $singleProduct['main_image'] = $parent['main_image'];
        //             $singleProduct['edited'] = [];
        //             $finalProduct[] = $singleProduct;
        //         }

        //     }

        // }
        // return ([
        //     "success" => true,
        //     'finalProduct' => $finalProduct,
        //     "query" => $query,
        //     'response'=>$response,
        //     // "data" => $allData,
        //     "parentProduct" => $parentProduct,
        //     "child" => $childProduct,
        //     "edited" => $editedProduct,
        //     // 'other' => $other,
        //     // 'allNewChilds' => $allNewChilds,
        //     // 'finalProduct' => $finalProducts
        // ]);


    }

    public function prepareDataForBulkEdit($data)
    {

        $dataPrepared = [];
        if (!empty($data)) {
            foreach ($data as $value) {
                $attributeNeeded = [
                    'brand_name',
                    'price',
                    'product_type',
                    'external_product_id_type',
                    'country_of_origin',
                    'item_weight_unit_of_measure',
                    'condition_type',
                    'sale_price',
                    'item_weight',
                    'sale_from_date',
                    'sale_end_date',
                    'are_batteries_included',
                    'batteries_required',
                    'fulfillment_latency',
                    'supplier_declared_dg_hz_regulation1',
                    'fulfillment_center_id'
                ];

                $dataPrepared[$value['container_id']] = [];
                $dataPrepared[$value['container_id']]['productid'] = isset($value['edited']['barcode']) ? $value['edited']['barcode'] : $value['barcode'];
                $dataPrepared[$value['container_id']]['description'] = isset($value['edited']['description']) ? $value['edited']['description'] : $value['description'];
                $dataPrepared[$value['container_id']]['sku'] = isset($value['edited']['sku']) ? $value['edited']['sku'] : $value['sku'];
                $dataPrepared[$value['container_id']]['country_of_origin'] = isset($value['edited']['country_of_origin']) ? $value['edited']['country_of_origin'] : isset($value['country_of_origin']) ? $value['country_of_origin'] : "";
                $dataPrepared[$value['container_id']]['item_weight_unit_of_measure'] = isset($value['edited']['item_weight_unit_of_measure']) ? $value['edited']['item_weight_unit_of_measure'] : isset($value['item_weight_unit_of_measure']) ? $value['item_weight_unit_of_measure'] : "";
                $dataPrepared[$value['container_id']]['condition_type'] = isset($value['edited']['condition_type']) ? $value['edited']['condition_type'] : isset($value['condition_type']) ? $value['condition_type'] : "";
                $dataPrepared[$value['container_id']]['sale_price'] = isset($value['edited']['sale_price']) ? $value['edited']['sale_price'] : isset($value['sale_price']) ? $value['sale_price'] : "";
                $dataPrepared[$value['container_id']]['item_weight'] = isset($value['edited']['item_weight']) ? $value['edited']['item_weight'] : isset($value['item_weight']) ? $value['item_weight'] : "";
                $dataPrepared[$value['container_id']]['sale_from_date'] = isset($value['edited']['sale_from_date']) ? $value['edited']['sale_from_date'] : isset($value['sale_from_date']) ? $value['sale_from_date'] : "";
                $dataPrepared[$value['container_id']]['sale_end_date'] = isset($value['edited']['sale_end_date']) ? $value['edited']['sale_end_date'] : isset($value['sale_end_date']) ? $value['sale_end_date'] : "";
                $dataPrepared[$value['container_id']]['are_batteries_included'] = isset($value['edited']['are_batteries_included']) ? $value['edited']['are_batteries_included'] : isset($value['are_batteries_included']) ? $value['are_batteries_included'] : "";
                $dataPrepared[$value['container_id']]['batteries_required'] = isset($value['edited']['batteries_required']) ? $value['edited']['batteries_required'] : (isset($value['batteries_required']) ? $value['batteries_required'] : "");
                $dataPrepared[$value['container_id']]['supplier_declared_dg_hz_regulation1'] = isset($value['edited']['supplier_declared_dg_hz_regulation1']) ? $value['edited']['supplier_declared_dg_hz_regulation1'] : isset($value['supplier_declared_dg_hz_regulation1']) ? $value['supplier_declared_dg_hz_regulation1'] : "";
                $dataPrepared[$value['container_id']]['fulfillment_center_id'] = isset($value['edited']['fulfillment_center_id']) ? $value['edited']['fulfillment_center_id'] : isset($value['fulfillment_center_id']) ? $value['fulfillment_center_id'] : "";

                $dataPrepared[$value['container_id']]['title'] = isset($value['edited']['title']) ? $value['edited']['title'] : $value['title'];
                $dataPrepared[$value['container_id']]['quantity'] = isset($value['edited']['quantity']) ? $value['edited']['quantity'] : $value['quantity'];
                $dataPrepared[$value['container_id']]['standard_price'] = isset($value['edited']['price']) ? $value['edited']['price'] : (isset($value['price']) ? $value['price'] : "");
                $dataPrepared[$value['container_id']]['brand_name'] = isset($value['edited']['brand_name']) ? $value['edited']['brand_name'] : isset($value['brand_name']) ? $value['brand_name'] : "";
                $dataPrepared[$value['container_id']]['fulfillment_latency'] = isset($value['edited']['fulfillment_latency']) ? $value['edited']['fulfillment_latency'] : isset($value['fulfillment_latency']) ? $value['fulfillment_latency'] : "";
                $dataPrepared[$value['container_id']]['sku'] = isset($value['edited']['sku']) ? $value['edited']['sku'] : isset($value['sku']) ? $value['sku'] : "";
                $dataPrepared[$value['container_id']]['product_type'] = 'default';
                if (isset($value['edited']['category_settings']['attributes_mapping'])) {
                    foreach ($value['edited']['category_settings']['attributes_mapping'] as $key => $editedDoc) {
                        if (count($editedDoc) > 0) {
                            foreach ($editedDoc as $attributes) {
                                foreach ($attributeNeeded as $new) {

                                    // print_r($attributes);die;
                                    if ($attributes['amazon_attribute'] == $new) {
                                        if (isset($attributes['shopify_attribute']) && !empty($attributes['shopify_attribute'])) {
                                            print_r($attributes['shopify_attribute']);
                                            unset($dataPrepared[$value['container_id']][$new]);
                                            $dataPrepared[$value['container_id']][$key][$new] = isset($value['edited'][$attributes['shopify_attribute']]) ? $value['edited'][$attributes['shopify_attribute']] : $value[$attributes['shopify_attribute']];

                                        } else if (isset($attributes['shopify_select']) && !empty($attributes['shopify_select'])) {
                                            if ($attributes['shopify_select'] == 'recommendation') {
                                                unset($dataPrepared[$value['container_id']][$new]);

                                                $dataPrepared[$value['container_id']][$key][$new] = $attributes['recommendation'];

                                            } else {
                                                // print_r([$dataPrepared[$value['container_id']][$key][$new] ,$attributes]);die;
                                                unset($dataPrepared[$value['container_id']][$new]);

                                                $dataPrepared[$value['container_id']][$key][$new] = $attributes['custom_text'];
                                            }
                                        } else {
                                            die("Hehehe");
                                            // print_r([$dataPrepared[$value['source_product_id']][$key][$new] ,$attributes]);die;

                                        }
                                    }
                                }
                            }
                        }

                    }

                }
            }

            return [
                'success' => true,
                'data' => $dataPrepared
            ];
        }
        return [
            'success' => false,
            'message' => "Data found empty"
        ];
    }

    public function getAllbulkCategories($data)
    {

        $targetShopId = $this->di->getRequester()->getTargetId();
        $targetMarketplace = $this->di->getRequester()->getTargetName();
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getCollection();
        $query =
            [
                [
                    '$match' =>
                        [
                            'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
                            'target_marketplace' => $targetMarketplace,
                            'shop_id' => $targetShopId,
                            'category_settings' => ['$exists' => true]
                        ]
                ],
                [
                    '$group' =>
                        [
                            '_id' =>
                                [
                                    'browser_node_id' => '$category_settings.browser_node_id',
                                    'displayPath' => '$category_settings.displayPath',
                                    'primary_category' => '$category_settings.primary_category',
                                    'sub_category' => '$category_settings.sub_category'
                                ],
                            // 'count' =>
                            //     [
                            //         '$sum' => 1
                            //     ]
                        ]
                ],
                [
                    '$project' =>
                        [
                            '_id' => 0,
                            'displayPath' => '$_id.displayPath',
                            'browser_node_id' => '$_id.browser_node_id',
                            'parentNodes' => '$_id.parentNodes',
                            'primary_category' => '$_id.primary_category',
                            'sub_category' => '$_id.sub_category'
                        ]
                ]
            ];
        $result = $collection->aggregate($query)->toArray();
        return ['success' => true, 'data' => $result, "query" => $query];
    }


    public function getAllbulkProducts($data)
    {

        $targetShopId = $this->di->getRequester()->getTargetId();

        if (!isset($data["browser_node_id"]) || !isset($data["primary_category"]) || !isset($data['sub_category'])) {
            return ['success' => false, 'message' => 'data missing'];
        }

        $type = "simple";
        $visibility = 'Catalog and Search';
        if (isset($data['type'])) {
            $type = $data['type'];
        }

        if (isset($data['visibility'])) {
            $visibility = $data['visibility'];
        }

        $targetMarketplace = $this->di->getRequester()->getTargetName();
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getCollection();
        $query =
            [
                [
                    '$match' => [
                        'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
                        'shop_id' => $this->di->getRequester()->getSourceId(),
                        'type' => $type,
                        'visibility' => $visibility
                    ]
                ],
                [
                    '$lookup' =>
                        [
                            'from' => 'product_container',
                            'let' => [
                                'container_id' => '$container_id',
                                'source_product_id' => 'source_product_id',
                                'user_id' => '$user_id'
                            ],
                            'pipeline' => [
                                [
                                    '$match' =>
                                        [
                                            '$expr' =>
                                                [
                                                    '$and' =>
                                                        [
                                                            [
                                                                '$eq' => ['$user_id', '$$user_id']
                                                            ],
                                                            [
                                                                '$eq' => ['$container_id', '$$container_id']
                                                            ],
                                                            [
                                                                '$eq' => ['$target_marketplace', $targetMarketplace]
                                                            ],
                                                            [
                                                                '$eq' => ['$shop_id', $targetShopId]
                                                            ],
                                                            [
                                                                '$eq' => ['$category_settings.browser_node_id', $data["browser_node_id"]]
                                                            ],
                                                            [
                                                                '$eq' => ['$category_settings.primary_category', $data["primary_category"]]
                                                            ],
                                                            [
                                                                '$eq' => ['$category_settings.sub_category', $data['sub_category']]
                                                            ]
                                                        ]
                                                ]
                                        ]
                                ],
                                [
                                    '$project' =>
                                        [
                                            "container_id" => 1,
                                            "shop_id" => 1,
                                            "source_product_id" => 1,
                                            "source_shop_id" => 1,
                                            "target_marketplace" => 1,
                                            "user_id" => 1,
                                            "category_settings" => 1,
                                            "description" => 1,
                                            "title" => 1
                                        ]
                                ],
                            ],
                            'as' => 'editedData'
                        ]
                ],
                ['$project' => ['container_id' => 1,'title'=>1, 'type' => 1, 'user_id' => 1, 'quantity' => 1, 'title' => 1, 'visibility' => 1,'description'=>1, 'editedData' => 1]],
                [
                    '$match' => ['editedData' => ['$exists' => true, '$ne' => []]]
                ]
            ];
        $result = $collection->aggregate($query)->toArray();
        return ['success' => true, 'data' => $result, "query" => $query];
    }

    public function saveAllBulEditProduct($data)
    {
        try {
            $products = new \App\Connector\Models\Product\Edit;
            return $this->prepareResponse($products->saveProduct($data));

        } catch (\Exception $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

}