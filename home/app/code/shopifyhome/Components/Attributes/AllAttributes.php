<?php

namespace App\Shopifyhome\Components\Attributes;

use App\Shopifyhome\Components\Core\Common;

class AllAttributes extends Common
{
    public function getProductAttributes($data)
    {
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('product_container');

        $response = $productCollection->distinct("variant_attributes.key", ["user_id" => $userId, "shop_id" => $data['source']['shopId']]);


        if (isset($data['fromNewCollection']) && $data['fromNewCollection'] == 'true') {
            // This block will execute only if 'fromNewCollection' is set and true in $data
            $metafields = $this->getProductAttributesFromNewCollection($data);
            $parentmeta_attributes = ['Parent Meta attributes' => $metafields['Parent Meta attributes']];
            $variantmeta_attributes = ['Variant Meta attributes' => $metafields['Variant Meta attributes']];
        } else {

            $parentmeta_attributes['Parent Meta attributes'] = $variantmeta_attributes['Variant Meta attributes'] = $parentmetafield = $variantmetafield = [];
            $parentmetaattribute = false;
            $variantmetaattribute = false;
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $pipline = [
                [
                    '$match' => [
                        'user_id' => $userId,
                        'shop_id' => $data['source']['shopId'],
                        'parentMetafield' => ['$exists' => true]
                    ]
                ],
                ['$project' => ['_id' => 0, 'parentMetafield' => 1]]
            ];
            $parentmetaattribute = $productCollection->aggregate($pipline, $arrayParams)->toArray();
            $parentmetaattribute = array_column($parentmetaattribute, 'parentMetafield');
            if ($parentmetaattribute) {
                foreach ($parentmetaattribute as $parentMetafieldData) {
                    $parentMetafieldData = (json_decode(json_encode($parentMetafieldData), true));
                    foreach ($parentMetafieldData as $key => $value) {
                        $parentmetafield[$key] = true;
                    }
                }
            }

            $pipline = [
                [
                    '$match' => [
                        'user_id' => $userId,
                        'shop_id' => $data['source']['shopId'],
                        'variantMetafield' => ['$exists' => true]
                    ]
                ],
                ['$project' => ['_id' => 0, 'variantMetafield' => 1]]
            ];
            $variantmetaattribute = $productCollection->aggregate($pipline, $arrayParams)->toArray();
            $variantmetaattribute = array_column($variantmetaattribute, 'variantMetafield');
            if ($variantmetaattribute) {
                foreach ($variantmetaattribute as $variantMetafieldData) {
                    $variantMetafieldData = (json_decode(json_encode($variantMetafieldData), true));
                    foreach ($variantMetafieldData as $key => $value) {
                        $variantmetafield[$key] = true;
                    }
                }
            }

            foreach ($parentmetafield as $metakey => $metavalue) {
                array_push($parentmeta_attributes['Parent Meta attributes'], ['code' => $metakey, 'title' => $metakey, 'required' => 0]);
            }
            foreach ($variantmetafield as $metakey => $metavalue) {
                array_push($variantmeta_attributes['Variant Meta attributes'], ['code' => $metakey, 'title' => $metakey, 'required' => 0]);
            }
        }




        $variant_attribute['Variant Attributes'] = [
            [
                'code' => 'variant_title',
                'title' => 'Variant title',
                'required' => 0
            ]
        ];
        foreach ($response as $value) {
            array_push($variant_attribute['Variant Attributes'], ['code' => $value, 'title' => $value, 'required' => 0]);
        }

        $cedcommerce_attribute['Cedcommerce Attributes'] = [
            [
                'code' => 'type',
                'title' => 'type',
                'required' => 0
            ]
        ];

        $shopify_core_attributes['Shopify Core Attributes'] = [
            [
                'code' => 'handle',
                'title' => 'Handle',
                'required' => 0
            ],
            [
                'code' => 'product_type',
                'title' => 'Product Type',
                'required' => 0
            ],
            [
                'code' => 'title',
                'title' => 'title',
                'required' => 0
            ],
            [
                'code' => 'brand',
                'title' => 'brand',
                'required' => 0
            ],
            [
                'code' => 'description',
                'title' => 'description',
                'required' => 0
            ],
            [
                'code' => 'tags',
                'title' => 'tags',
                'required' => 0
            ],
            [
                'code' => 'sku',
                'title' => 'sku',
                'required' => 0
            ],
            [
                'code' => 'price',
                'title' => 'price',
                'required' => 0
            ],
            [
                'code' => 'quantity',
                'title' => 'quantity',
                'required' => 0
            ],
            [
                'code' => 'weight',
                'title' => 'weight',
                'required' => 0
            ],
            [
                'code' => 'weight_unit',
                'title' => 'weight_unit',
                'required' => 0
            ],
            [
                'code' => 'grams',
                'title' => 'grams',
                'required' => 0
            ],
            [
                'code' => 'barcode',
                'title' => 'barcode',
                'required' => 0
            ]
        ];


        $shopify_attribute = [...$shopify_core_attributes, ...$variant_attribute, ...$cedcommerce_attribute, ...$parentmeta_attributes, ...$variantmeta_attributes];
        return $shopify_attribute;
    }
    public function getProductAttributesFromNewCollection($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('product_metafield_definitions_container');

        $userId = $this->di->getUser()->id;
        $shopId = $data['source']['shopId'];

        $parentMeta = [];
        $variantMeta = [];

        if (!$shopId) {
            return [
                "Parent Meta attributes" => [],
                "Variant Meta attributes" => []
            ];
        }

        // Single query fetching both parent & variant metafield definitions
        $cursor = $productCollection->find(
            [
                "user_id" => $userId,
                "shop_id" => $shopId
            ],
            [
                "projection" => [
                    "parent_metafield_definitions" => 1,
                    "variant_metafield_definitions" => 1
                ],
                "typeMap" => ['root' => 'array', 'document' => 'array']
            ]
        );

        foreach ($cursor as $doc) {
            // Collect parent metafields
            foreach ($doc['parent_metafield_definitions'] ?? [] as $fullKey) {
                $parentMeta[$fullKey] = [
                    "code" => $fullKey,
                    "title" => $fullKey,
                    "required" => 0
                ];
            }

            // Collect variant metafields
            foreach ($doc['variant_metafield_definitions'] ?? [] as $fullKey) {
                $variantMeta[$fullKey] = [
                    "code" => $fullKey,
                    "title" => $fullKey,
                    "required" => 0
                ];
            }
        }

        return [
            "Parent Meta attributes" => array_values($parentMeta),
            "Variant Meta attributes" => array_values($variantMeta)
        ];
    }


    public function getAttributeValue($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('product_container');
        $userId = $this->di->getUser()->id;
        $metaAttribute = false;
        if (isset($data['attribute'])) {
            $attribute = $data['attribute'];
        }

        if (isset($data['meta_attribute'])) {
            $metaAttribute = $data['meta_attribute'];
        }

        if (isset($data['container_id'])) {
            $container_id = $data['container_id'];
        }

        $isValueforAll = true;
        $values = [];
        $shopifyAttribute = ['handle', 'product_type', 'title', 'brand', 'description', 'tags', 'sku', 'price', 'quantity', 'weight', 'weight_unit', 'grams', 'barcode'];
        $products = $productCollection->find(["user_id" => $userId, "shop_id" => $data['source']['shopId'], "container_id" => $container_id, "type" => "simple"], [
            // '$projection'=>[],
            'typeMap'   => ['root' => 'array', 'document' => 'array']
        ])->toArray();
        foreach ($products as $product) {
            if (in_array($attribute, $shopifyAttribute)) {
                $value[$product['source_product_id']] = $product[$attribute];
                $isValueforAll = false;
            } elseif ($metaAttribute) {
                $variantMetafields = $product['variantMetafield'];
                foreach ($variantMetafields as $variantMetafield) {
                    if ($variantMetafield['code'] == $attribute) {
                        $value[$product['source_product_id']] = $variantMetafield['value'];
                    }
                }
            } else {
                $variantAttributes = $product['variant_attributes'];
                foreach ($variantAttributes as $variantAttribute) {
                    if ($variantAttribute['key'] == $attribute) {
                        $value[$product['source_product_id']] = $variantAttribute['value'];
                    }
                }
            }
        }

        return ['success' => true, "response" => $value, $isValueforAll = false];
    }

    public function getSingleProductAttribute($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('product_container');
        $userId = $this->di->getUser()->id;
        if (isset($data['container_id'])) {
            $container_id = $data['container_id'];
        }

        $response = $productCollection->distinct("variant_attributes.key", ["user_id" => $userId, "shop_id" => $data['source']['shopId'], "container_id" => $container_id]);
        $variant_attribute['Variant Attributes'] = [
            [
                'code' => 'variant_title',
                'title' => 'Variant title',
                'required' => 0
            ]
        ];
        foreach ($response as $value) {
            array_push($variant_attribute['Variant Attributes'], ['code' => $value, 'title' => $value, 'required' => 0]);
        }

        return ['success' => true, "response" => $variant_attribute];
    }

    public function getSingleAlltAttributes($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('product_container');
        $userId = $this->di->getUser()->id;
        if (isset($data['container_id'])) {
            $container_id = $data['container_id'];
        }

        $response = $productCollection->distinct("variant_attributes.key", ["user_id" => $userId, "shop_id" => $data['source']['shopId'], "container_id" => $container_id]);
        $variantmeta_attributes['Variant Meta attributes'] = [];

        $parentmeta_attributes['Parent Meta attributes'] = [];
        $parentmetaattribute = false;
        $variantmetaattribute = false;
        $variant_attribute['Variant Attributes'] = $parentmetafield = $variantmetafield = [];
        $parentmetaattribute = $productCollection->distinct("parentMetafield", ["user_id" => $userId, "shop_id" => $data['source']['shopId'], "container_id" => $container_id]);
        if ($parentmetaattribute) {
            foreach ($parentmetaattribute as $parentMetafieldData) {
                $parentMetafieldData = (json_decode(json_encode($parentMetafieldData), true));
                foreach ($parentMetafieldData as $key => $value) {
                    $parentmetafield[$key] = true;
                }
            }
        }

        $variantmetaattribute = $productCollection->distinct("variantMetafield", ["user_id" => $userId, "shop_id" => $data['source']['shopId'], 'container_id' => $container_id]);
        if ($variantmetaattribute) {
            foreach ($variantmetaattribute as $variantMetafieldData) {
                $variantMetafieldData = (json_decode(json_encode($variantMetafieldData), true));
                foreach ($variantMetafieldData as $key => $value) {
                    $variantmetafield[$key] = true;
                }
            }
        }

        foreach ($parentmetafield as $metakey => $metavalue) {
            array_push($parentmeta_attributes['Parent Meta attributes'], ['code' => $metakey, 'title' => $metakey, 'required' => 0]);
        }
        foreach ($variantmetafield as $metakey => $metavalue) {
            array_push($variantmeta_attributes['Variant Meta attributes'], ['code' => $metakey, 'title' => $metakey, 'required' => 0]);
        }

        if (isset($data['type']) && $data['type'] == "variation") {
            $variant_attribute['Variant Attributes'] = [
                [
                    'code' => 'variant_title',
                    'title' => 'Variant title',
                    'required' => 0
                ]
            ];
        }

        foreach ($response as $value) {
            array_push($variant_attribute['Variant Attributes'], ['code' => $value, 'title' => $value, 'required' => 0]);
        }

        $cedcommerce_attribute['Cedcommerce Attributes'] = [
            [
                'code' => 'type',
                'title' => 'type',
                'required' => 0
            ]
        ];

        $shopify_core_attributes['Shopify Core Attributes'] = [
            [
                'code' => 'handle',
                'title' => 'Handle',
                'required' => 0
            ],
            [
                'code' => 'product_type',
                'title' => 'Product Type',
                'required' => 0
            ],
            [
                'code' => 'title',
                'title' => 'title',
                'required' => 0
            ],
            [
                'code' => 'brand',
                'title' => 'brand',
                'required' => 0
            ],
            [
                'code' => 'description',
                'title' => 'description',
                'required' => 0
            ],
            [
                'code' => 'tags',
                'title' => 'tags',
                'required' => 0
            ],
            [
                'code' => 'sku',
                'title' => 'sku',
                'required' => 0
            ],
            [
                'code' => 'price',
                'title' => 'price',
                'required' => 0
            ],
            [
                'code' => 'quantity',
                'title' => 'quantity',
                'required' => 0
            ],
            [
                'code' => 'weight',
                'title' => 'weight',
                'required' => 0
            ],
            [
                'code' => 'weight_unit',
                'title' => 'weight_unit',
                'required' => 0
            ],
            [
                'code' => 'grams',
                'title' => 'grams',
                'required' => 0
            ],
            [
                'code' => 'barcode',
                'title' => 'barcode',
                'required' => 0
            ]
        ];


        $shopify_attribute = array_merge($shopify_core_attributes, $variant_attribute, $cedcommerce_attribute, $parentmeta_attributes, $variantmeta_attributes);
        return ['success' => true, "data" => $shopify_attribute];
    }

    public function getProductTypeOptions($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('product_container');
        $aggregate = [];
        // $aggregate[] = [ '$match' => [ "user_id" => "6267f4954dbe15233438a048"]];
        $aggregate[] = ['$match' => ["user_id" => (string)$this->di->getUser()->id, "shop_id" => $data['source']['shopId']]];
        $aggregate[] = ['$group' => [
            '_id' => '$product_type',
            'title' => ['$addToSet' => '$product_type']
        ]];


        $collection = $collection->aggregate($aggregate)->toArray();

        $options = [];

        foreach ($collection as $value) {
            if ($value['_id'] && !empty($value['_id'] && count($value['title']) > 0 && $value['title'][0] && !empty($value['title'][0]))) {
                $options[] = ['label' => $value['title'][0], 'value' => $value['_id']];
            }
        }

        return $options;
    }

    public function getCollectionsOptions($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('product_container');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $aggregate = [];
        // $aggregate[] = [ '$match' => [ "user_id" => "6267f4954dbe15233438a048"]];
        $aggregate[] = ['$match' => ["user_id" => (string)$this->di->getUser()->id, "shop_id" => $data['source']['shopId']]];
        $aggregate[] = ['$unwind' => '$collection'];
        $aggregate[] = ['$group' => [
            '_id' => '$collection.collection_id',
            'title' => ['$addToSet' => '$collection.title']
        ]];
        $collection = $collection->aggregate($aggregate, $options)->toArray();
        $options = [];
        foreach ($collection as $value) {
            if ($value['title'] && isset($value['title']) && count($value['title']) > 0 &&  !empty($value['_id'])) {
                $options[] = ['label' => $value['title'][0], 'value' => $value['_id']];
            }
        }

        return $options;
    }

    public function getVariantAttributes($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('product_container');
        $variantAttributesArr = [];
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $response = $productCollection->aggregate([
            ['$match' => ["user_id" => (string)$this->di->getUser()->id, 'visibility' => 'Catalog and Search', "shop_id" => $data['source']['shopId']]],
            [
                '$group' => [
                    '_id' => '$variant_attributes' // chnages to be modified acc to new schema
                ]
            ]
        ], $options)->toArray();
        foreach ($response as $value) {
            if (isset($value['_id']) &&  $value['_id'] && count($value['_id']) > 0) {
                $valueToSave = implode(',', $value['_id']);
                array_push($variantAttributesArr, [
                    'label' => $valueToSave,
                    'value' => $valueToSave,
                ]);
            }
        }

        return $variantAttributesArr;
    }


    public function getVendor($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('product_container');
        $aggregate = [];
        if (isset($data['use_atlas'])) {
            $limit = $data['limit'] ?? 100;
            if (isset($data['filters']['brand'])) {
                $searchValue = $data['filters']['brand']['textValue'];
                $query = [
                    [
                        '$searchMeta' =>
                        [
                            'index' => 'status',
                            'facet' =>
                            [
                                'operator' =>
                                [
                                    'compound' =>
                                    [
                                        'must' =>
                                        [
                                            [
                                                'text' =>
                                                [
                                                    'path' => 'user_id',
                                                    'query' => (string)$this->di->getUser()->id
                                                ]
                                            ],
                                            [
                                                'text' => ['path' => 'shop_id', 'query' => $data['source']['shopId']]
                                            ],
                                            [
                                                'autocomplete' =>
                                                [
                                                    'path' => 'brand',
                                                    'query' => $searchValue,
                                                    'fuzzy' => ['maxEdits' => 1, 'prefixLength' => 3]
                                                ]
                                            ]

                                        ]
                                    ]
                                ],
                                'facets' =>
                                [
                                    'value' =>
                                    [
                                        'type' => 'string',
                                        'path' => 'brand',
                                        'numBuckets' => $limit
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
            } else {
                $query = [
                    [
                        '$searchMeta' =>
                        [
                            'index' => 'status',
                            'facet' =>
                            [
                                'operator' =>
                                [
                                    'compound' =>
                                    [
                                        'must' =>
                                        [
                                            [
                                                'text' =>
                                                [
                                                    'path' => 'user_id',
                                                    'query' => (string)$this->di->getUser()->id
                                                ]
                                            ],
                                            [
                                                'text' => ['path' => 'shop_id', 'query' => $data['source']['shopId']]
                                            ]
                                        ]
                                    ]
                                ],
                                'facets' =>
                                [
                                    'value' =>
                                    [
                                        'type' => 'string',
                                        'path' => 'brand',
                                        'numBuckets' => $limit
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
            }
            $vendorAttr = $collection->aggregate($query)->toArray();
            foreach ($vendorAttr[0]['facet']['value']['buckets'] as $value) {
                if ($value['_id'] && !empty($value['_id'])) {
                    $options[] = ['label' => $value['_id'], 'value' => $value['_id']];
                }
            }
        } else {
            $aggregate[] = ['$match' => ["user_id" => (string)$this->di->getUser()->id, "shop_id" => $data['source']['shopId']]];
            $aggregate[] = ['$group' => [
                '_id' => '$brand',
                'title' => ['$addToSet' => '$brand']
            ]];
            $vendorAttr = $collection->aggregate($aggregate)->toArray();
            foreach ($vendorAttr as $value) {
                if ($value['_id'] && !empty($value['_id'] && count($value['title']) > 0 && $value['title'][0] && !empty($value['title'][0]))) {
                    $options[] = ['label' => $value['title'][0], 'value' => $value['_id']];
                }
            }
        }

        return $options;
    }
}
