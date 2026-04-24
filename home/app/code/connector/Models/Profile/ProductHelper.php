<?php

namespace App\Connector\Models\Profile;

use App\Core\Models\BaseMongo;

class ProductHelper extends BaseMongo
{
    public $targetMarketplace;

    public $profileId;

    public $targetShopId;

    public $warehouseId;

    public $sourceMarketplace;

    public $processData;

    public $sourceShopId;

    public $profileData;

    public $sourceShopWarehouseId;

    public $containerIds;

    public $appTag;


    public function requiredKey()
    {
        return [
            'profile_id',
            'target_marketplace',
            'target_shop_id',
            'warehouse_id',
            'source_maketplace',
            'source_shop_id',
            'source_shop_warehouse_id',
            'app_tag'
        ];
    }

    public function requiredKeyWithProductIds()
    {
        return [
            'target_marketplace',
            'target_shop_id',
            'warehouse_id',
            'source_maketplace',
            'source_shop_id',
            'source_shop_warehouse_id',
            'app_tag'
        ];
    }

    public function validateData()
    {
        $requiredKey = $this->requiredKey();

        foreach ($requiredKey as $value) {
            if (isset($this->processData[$value])) {
                $this->{$value} = $this->processData[$value];
            } else {
                return ['success' => false, 'message' => $this->di->getLocale()->_('value_key_missing', [
                    'value' => $value
                ])];
            }
        }
    }

    public function validateDataWithProductIds()
    {
        $requiredKey = $this->requiredKeyWithProductIds();

        foreach ($requiredKey as $value) {
            if (isset($this->processData[$value])) {
                $this->{$value} = $this->processData[$value];
            } else {
                return ['success' => false, 'message' => $this->di->getLocale()->_('value_key_missing', [
                    'value' => $value
                ])];
            }
        }
    }


    public function getProductByProfileId()
    {
        $validateData = $this->validateData();
        if (isset($validateData['success']) && !$validateData['success']) {
            return $validateData;
        }

        $profileData = $this->getProfileData();

        if (!empty($profileData)) {
            $allProductData = $this->getProduct();
            if (isset($allProductData['success']) && !$allProductData['success']) {
                return $allProductData;
            }

            $productData = $allProductData['data'];
            $prepareProduct = $this->prepareProduct($productData);
            $prepareProduct['next'] = $allProductData['next'];
            return $prepareProduct;
        }
        return ['success' => false, 'message' => 'profile data is empty'];
    }

    public function prepareProfileAttribute()
    {
        $helperDataArr = [];
        $helperDataArr['profile_data'] = $this->profileData;
        $helperDataArr['marketplace'] = $this->targetMarketplace;
        $helperDataArr['source_marketplace'] = $this->sourceMarketplace;

        $profileHelperObj = new Helper();
        $profileHelperObj->processData = $helperDataArr;
        $attributePath = "targets.{$this->targetMarketplace}.shops.{$this->targetShopId}.warehouses.{$this->warehouseId}.sources.{$this->sourceMarketplace}.shops.{$this->sourceShopId}.warehouses.{$this->sourceShopWarehouseId}.attributes_mapping";

        $attributeRes = $profileHelperObj->getProfileAttribute($attributePath);

        return $attributeRes;
    }

    public function prepareProduct($productData)
    {
        $attributeRes = $this->prepareProfileAttribute();

        if (isset($attributeRes['success'])) {
            $mappedAttributes = $attributeRes['data'];

            foreach ($productData as $product) {
                $marketplaceIndex = $this->processData['target_marketplace'] . "_marketplace";
                $editedAData = [];

                if (!empty($product[$marketplaceIndex])) {
                    foreach ($product[$marketplaceIndex] as $maketplaceConatainerData) {
                        $editedAData[$maketplaceConatainerData['source_product_id']] = $maketplaceConatainerData;
                    }
                }

                if (!empty($editedAData) && isset($editedAData[$product['source_product_id']])) {
                    $product = array_merge($product, $editedAData[$product['source_product_id']]);
                }

                foreach ($mappedAttributes as $columnName => $mappedData) {

                    $namespace = "\\App\\Connector\\Models\\Profile\\Attribute\\Type\\" . ucfirst($mappedData['type']);
                    if (class_exists($namespace)) {
                        $obj = new $namespace();
                        $product = $obj->changeData($columnName, $mappedData, $product);
                    }
                }

                if (!empty($product['variants'])) {
                    foreach ($product['variants'] as $proCol => $variant) {
                        if (!empty($editedAData) && isset($editedAData[$variant['source_product_id']])) {
                            $variant = array_merge($product, $editedAData[$variant['source_product_id']]);
                        }

                        foreach ($mappedAttributes as $columnName => $mappedData) {
                            $namespace = "\\App\\Connector\\Models\\Profile\\Attribute\\Type\\" . ucfirst($mappedData['type']);
                            if (class_exists($namespace)) {
                                $obj = new $namespace();
                                $variant = $obj->changeData($columnName, $mappedData, $variant);
                            }
                        }

                        $product['variants'][$proCol] = $variant;
                    }

                    $newProductData[] = $product;
                } else {
                    $newProductData[] = $product;
                }
            }
        } else {
            return ['success' => false, 'message' => "Trouble processing product!"];
        }

        return ['success' => true, 'data' => $newProductData];
    }

    public function getProductByProductIds()
    {
        $validateData = $this->validateDataWithProductIds();
        if (isset($validateData['success']) && !$validateData['success']) {
            return $validateData;
        }

        $productIds = $this->processData['container_ids'];
        $finalQuery = [];

        $finalQuery[] = [
            '$match' => [
                'user_id' => $this->di->getUser()->id,
                'container_id' => ['$in' => $productIds],
            ]
        ];

        $commonQuery = $this->commonQuery();
        $finalQuery = array_merge($finalQuery, $commonQuery);

        $collection = $this->getCollectionForTable('product_container');
        $response = $collection->aggregate($finalQuery, ["typeMap" => ['root' => 'array', 'document' => 'array', 'object' => 'array', 'array' => 'array']]);
        $response = $response->toArray();
        if (!empty($response)) {
            $profileWiseProduct = [];

            foreach ($response as $productData) {
                if (isset($productData['profile'])) {

                    foreach ($productData['profile'] as $profile) {
                        if ($this->appTag == $profile['app_tag']) {
                            $profileWiseProduct[$profile['profile_id']][] = $productData;
                        }
                    }
                }
            }

            if (!empty($profileWiseProduct)) {
                $prepareProductData = [];

                foreach ($profileWiseProduct as $profile_id => $products) {
                    $this->profileId = $profile_id;
                    $profileData = $this->getProfileData();
                    if (!empty($profileData)) {
                        $prepareProduct = $this->prepareProduct($products);
                        $prepareProductData[] = $prepareProduct;
                    }
                }

                if (!empty($prepareProductData)) {
                    return ['success' => true, 'data' => $prepareProductData];
                }
                return ['success' => false, 'message' => 'no profile wise data found'];
            }
            return ['success' => false, 'message' => 'no profile wise data found'];
        }
        return ['success' => false, 'message' => 'no save data found'];
    }


    public function getProduct()
    {
        $finalQuery = [];

        $finalQuery[] = [
            '$match' => [
                'user_id' => $this->di->getUser()->id,
                'profile.profile_id' => $this->profileId
            ]
        ];

        if (isset($this->processData['page'], $this->processData['limit'])) {
            $limit = (int) $this->processData['limit'];
            $page = (int) $this->processData['page'] - 1;
            $skip = ($limit * $page);
        } else {
            $skip = 0;
            $limit = 50;
        }

        $finalQuery[] = [
            '$skip' => $skip,
        ];

        $finalQuery[] = [
            '$limit' => $limit + 1,
        ];
        $collection = $this->getCollectionForTable('product_container');
        $countresponse = $collection->aggregate($finalQuery, ["typeMap" => ['root' => 'array', 'document' => 'array', 'object' => 'array', 'array' => 'array']]);
        $countresponse = $countresponse->toArray();


        $commonQuery = $this->commonQuery();
        $finalQuery = array_merge($finalQuery, $commonQuery);


        $response = $collection->aggregate($finalQuery, ["typeMap" => ['root' => 'array', 'document' => 'array', 'object' => 'array', 'array' => 'array']]);
        $response = $response->toArray();

        if (!empty($response)) {
            if (count($countresponse) <= $limit) {
                return ['success' => true, 'data' => $response, 'next' => false];
            }
            return ['success' => true, 'data' => $response, 'next' => true];
        }
        return ['success' => false, 'message' => 'No product data'];
    }

    public function commonQuery()
    {
        $finalQuery = [];

        $finalQuery[] = [
            '$lookup' => [
                'from' => $this->processData['target_marketplace'] . '_product_container',
                'localField' => 'source_product_id',
                'foreignField' => 'source_product_id',
                'as' => $this->processData['target_marketplace'] . "_marketplace",
            ],
        ];


        $finalQuery[] = [
            '$graphLookup' => [
                "from" => "product_container",
                "startWith" => '$container_id',
                "connectFromField" => "container_id",
                "connectToField" => "group_id",
                "as" => "variants",
                "maxDepth" => 1,
            ],
        ];


        $finalQuery[] = [
            '$match' => [
                '$or' => [
                    ['$and' => [
                        ['type' => 'variation'],
                        ["visibility" => 'Catalog and Search'],
                    ]],
                    ['$and' => [
                        ['type' => 'simple'],
                        ["visibility" => 'Catalog and Search'],
                    ]],
                ],
            ],
        ];

        return $finalQuery;
    }

    public function getProfileData()
    {

        $this->di->getUser()->id;
        $profileParams = [];

        $profileParams['filters'] = ['id' => $this->profileId];
        $obj = new Model();
        $profileData = $obj->getProfile($profileParams);
        if (!empty($profileData['data'])) {
            $this->profileData = $profileData['data'][0];
            return $this->profileData;
        }

        return [];
    }
}
