<?php

namespace App\Amazon\Components\Listings;

use App\Amazon\Components\Common\Helper;
use MongoDB\BSON\ObjectId;
use Exception;
use App\Core\Components\Base as Base;
use App\Core\Models\BaseMongo;
use App\Amazon\Components\Template\CategoryAttributeCache;
use App\Amazon\Components\Listings\CategoryAttributes;

class ValueMapping extends Base
{
    private array $valueMappingEligibleAttributes = [];

    private array $shopifyMappedAttributes = [];

    private $alreadySavedValueMappingdata = [];

    private array $newlyAddedAttributes = [];

    private $userId = null;

    private $targetShopId = null;

    private $sourceShopId = null;

    public const RESTRICTED_VALUE_MAPPING_FIELDS = ['title', 'description', 'handle', 'type', 'sku', 'barcode', 'tags', 'price', 'quantity', 'brand'];


    /** process profile events */
    public function jsonValueMapping($profileData, $rawBody)
    {
        try {
            $this->valueMappingEligibleAttributes = [];
            $this->shopifyMappedAttributes = [];
            $this->alreadySavedValueMappingdata = [];
            $this->newlyAddedAttributes = [];
            if (isset($rawBody['user_id'])) {
                $userId = $rawBody['user_id'];
            } else {
                $userId = $this->di->getUser()->id;
            }

            $this->userId = $userId;
            $this->targetShopId = $rawBody['target']['shopId'] ?? "";
            $this->sourceShopId = $rawBody['source']['shopId'] ?? "";

            //check if updated template data have product_type assigned to it ( json )
            if (!$this->isProductTypeAssigned($profileData)) {
                return ['success' => false, 'response' => 'Template does not have product_type assigned.', 'old_upload' => true];
            }

            if (empty($profileData['manual_product_ids']) && empty($profileData['query']) &&
                empty($profileData['attributes_mapping']['_id']['$oid'])
            ) {
                // products are not assgined or removed in updated-template, check if value_mapping is assigned true
                $this->unsetValueMappingData($profileData);
                return ['success' => false, 'response' => 'Products are not assigned in template.'];
            }

            // if product is assigned in the json-type template, get attributes-mapping of the profile
            $attributeMappingObjectId = $profileData['attributes_mapping']['_id']['$oid'] ?? null;
            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $profileCollection = $baseMongo->getCollection(Helper::PROFILE);
            $attributesMappingData = $profileCollection->findOne(
                ['_id' => new ObjectId($attributeMappingObjectId)],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            );
            if (!empty($attributesMappingData['data']['jsonFeed'])) {
                if (!is_array($attributesMappingData)) {
                    $attributesMappingData = $attributesMappingData->toArray();
                }

                if (!empty($attributesMappingData['data']['value_mapping'])) {
                    $this->alreadySavedValueMappingdata = $attributesMappingData['data']['value_mapping'];
                }

                $this->newlyAddedAttributes = [];
                foreach ($attributesMappingData['data']['jsonFeed'] as $attributeLabel => $attrSchema) {
                    $this->getValueMappingEligibleAttributes($attrSchema, $attributeLabel);
                }

                if (!empty($this->valueMappingEligibleAttributes)) {
                    if (!empty($this->newlyAddedAttributes)) {
                        // get schema_attributes from DB, need to get enum, title, schema_path from schema
                        $marketplaceId = $attributesMappingData['data']['marketplace_id'] ?? null;
                        $productType = $profileData['category_id']['product_type'] ?? null;
                        $requestParams = [
                            'user_id' => $this->userId,
                            'shop_id' => $this->targetShopId,
                            'marketplace_id' => $marketplaceId,
                            'product_type' => $productType
                        ];
                        $jsonSchema = $this->getJsonSchema($requestParams);
                        if (isset($jsonSchema['success']) && !$jsonSchema['success']) {
                            return $jsonSchema;
                        }

                        foreach ($this->newlyAddedAttributes as $addedAttribute => $shopifyMappedAttribute) {
                            $attributePath = explode('/', $addedAttribute);
                            if (isset($jsonSchema[$attributePath[0]])) {
                                $parentKey = $attributePath[0];
                                $midSchema = $jsonSchema;
                                $schemaPath = false;
                                foreach ($attributePath as $path) {
                                    if (is_numeric($path) && isset($midSchema['items']['properties'])) {
                                        $midSchema = $midSchema['items']['properties'];
                                        $schemaPath = $schemaPath .  '/items/properties';
                                    } elseif (isset($midSchema[$path])) {
                                        $midSchema = $midSchema[$path];
                                        if (!$schemaPath) {
                                            $schemaPath = $path;
                                        } else {
                                            $schemaPath = $schemaPath . '/' . $path;
                                        }
                                    } elseif (isset($midSchema['properties'][$path])) {
                                        $midSchema = $midSchema['properties'][$path];
                                        $schemaPath = $schemaPath . '/' . 'properties/' . $path;
                                    } else {
                                        $schemaPath = false;
                                        break;
                                    }
                                }

                                if (!empty($schemaPath) || ($schemaPath != false)) {
                                    //  get amazon_recommendations value
                                    $this->valueMappingEligibleAttributes[$addedAttribute]['schema_path'] = $schemaPath;
                                    $this->valueMappingEligibleAttributes[$addedAttribute]['title'] = $midSchema['title'];
                                    $this->shopifyMappedAttributes[$this->valueMappingEligibleAttributes[$addedAttribute]['shopify_attribute']]['amazon_recommendations'] = array_values($this->getRecommendations($midSchema, $parentKey));
                                }
                            }

                            //  check if shopifyMappedAttribute is already mapped with another - this needs to be rechecked
                            if (count($this->shopifyMappedAttributes[$shopifyMappedAttribute]['amazon_attribute_paths']) > 1) {
                                foreach ($this->shopifyMappedAttributes[$shopifyMappedAttribute]['amazon_attribute_paths'] as $mappedKey => $val) {
                                    if ($mappedKey != $addedAttribute) {
                                        $keys = array_keys($this->valueMappingEligibleAttributes[$mappedKey][$shopifyMappedAttribute]);
                                        $newArray = array_fill_keys($keys, null);
                                        $this->valueMappingEligibleAttributes[$addedAttribute][$shopifyMappedAttribute] = $newArray;
                                        unset($this->newlyAddedAttributes[$addedAttribute]);
                                        break;
                                    }
                                }
                            }
                        }
                    }


                    // get if updated products in template
                    $profileId = $profileData['_id']['$oid'] ?? null;
                    $productContainer = $baseMongo->getCollection(Helper::PRODUCT_CONTAINER);
                    $productIds = $productContainer->distinct("container_id",['user_id' => (string)$userId, 'shop_id' => (string)$rawBody['source']['shopId'] ?? null, 'profile.profile_id' => new ObjectId($profileId)]
                    );
                    $products = [];
                    if (!empty($productIds)) {
                        // $products = $productContainer->find(
                        //     ['user_id' => (string)$userId, 'shop_id' => (string)$rawBody['source']['shopId'] ?? null, 'container_id' => ['$in' => $productIds]],
                        //     ['typeMap' => ['root' => 'array', 'document' => 'array']]
                        // );
                        $products = $productContainer->find(
                            [
                                'user_id' => (string)$userId,
                                'shop_id' => (string)($rawBody['source']['shopId'] ?? null),
                                'container_id' => ['$in' => $productIds],
                            ],
                            [
                                'projection' => [
                                    'visibility' => 1,
                                    'variant_attributes_values' => 1,
                                    'variant_attributes' => 1,
                                    'container_id' => 1,
                                    'source_product_id' => 1,
                                    'profile_id' => 1,
                                    'type' => 1,
                                    'weight' => 1,
                                    'weight_unit' => 1,
                                    'grams' => 1,
                                    'product_type' => 1
                                ],
                                'typeMap' => [
                                    'root' => 'array',
                                    'document' => 'array',
                                ],
                            ]
                        );

                    }

                    // get all container_ids that has profile_id assigned to it, TODO - add limit, skip

                    if (!empty($products)) {
                        if (!is_array($products)) {
                            $products = $products->toArray();
                        }

                        $this->prepareValueMappingData($products);
                        $this->updateValueMappingAttributesIfEmpty();
                        if (!empty($this->valueMappingEligibleAttributes)) {
                            // add a check to varify each value mapping array contains required fields instead of not empty
                            $profileCollection->updateOne(['_id' => new ObjectId($profileId)], [
                                '$set' => ['value_mapping' => true, 'json_listing' => true]
                            ]);
                            $profileCollection->updateOne(['_id' => new ObjectId($attributeMappingObjectId)], [
                                '$set' => ['data.value_mapping' => $this->valueMappingEligibleAttributes]
                            ]);
                            return ['success' => true, 'response' => 'Value mapping is successfully achieved'];
                        }
                        $this->unsetValueMappingData($profileData);
                        return ['success' => false, 'response' => 'No attributes elligible for value mapping.'];
                    }
                    $this->unsetValueMappingData($profileData);
                    return ['success' => false, 'response' => 'No product exist for mapping!'];                    
                }
                // no attribute eligible for value_mapping
                return $this->unsetValueMappingData($profileData);

                return ['success' => true, 'response' => 'Value mapping added successfully!'];
            }
            return $this->unsetValueMappingData($profileData);
        } catch (Exception $e) {
            print_r($e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function unsetValueMappingData($data)
    {
        if (isset($data['value_mapping'], $data['json_listing']) && $data['value_mapping'] && $data['json_listing']) {
            // if products are removed and value_mapping is assigned true
            $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $profileCollection = $baseMongo->getCollection(Helper::PROFILE);
            $profileCollection->updateOne(['_id' => new ObjectId($data['_id']['$oid'] ?? null)], [
                '$unset' => ['value_mapping' => 1, 'json_listing' => 1]
            ]);
            if (!empty($data['attributes_mapping']['_id']['$oid'])) {
                //  unset value_mapping data if saved in type = attribute profile_data
                $profileCollection->updateOne(['_id' => new ObjectId($data['attributes_mapping']['_id']['$oid'] ?? null)], [
                    '$unset' => ['data.value_mapping' => 1]
                ]);
            }

            return ['success' => true, 'message' => 'Value mapping removed successfully!'];
        }

        return ['success' => false, 'message' => 'Not eligible for value mapping!'];
    }

    public function isProductTypeAssigned($data)
    {
        return isset($data['category_id']['product_type']) && !empty($data['category_id']['product_type']);
    }

    public function prepareValueMappingData($products): void
    {
        $outerAttributes = ['weight', 'weight_unit', 'grams', 'product_type'];
        if (is_array($products) && !empty($products)) {
            foreach ($products as $product) {
                foreach ($this->shopifyMappedAttributes as $shopifyAttribute => $valueMappingsData) {
                    if (($shopifyAttribute == 'variant_title') && ($product['visibility'] == 'Catalog and Search')) {
                        continue;
                    }

                    $isOuterAttr = in_array($shopifyAttribute, $outerAttributes);
                    //here we need to skip the variant_title if it is a part product
                    foreach ($valueMappingsData['amazon_attribute_paths'] as $mappedAmazonAttributePath => $val) {
                        // loop through for already saved value_mapping data
                        if (!empty($product[$shopifyAttribute]) && !empty($valueMappingsData['amazon_recommendations']) && !in_array($product[$shopifyAttribute], $valueMappingsData['amazon_recommendations']) && empty($this->valueMappingEligibleAttributes[$mappedAmazonAttributePath][$shopifyAttribute][$product[$shopifyAttribute]])) {
                            if (empty($this->valueMappingEligibleAttributes[$mappedAmazonAttributePath][$shopifyAttribute][(string)$product[$shopifyAttribute]])) {
                                $this->valueMappingEligibleAttributes[$mappedAmazonAttributePath][$shopifyAttribute][(string)$product[$shopifyAttribute]] = null;
                            }
                        } elseif (isset($product['variant_attributes'], $product['variant_attributes_values']) && in_array($shopifyAttribute, $product['variant_attributes']) && !$isOuterAttr) {
                            foreach ($product['variant_attributes_values'] as $variantAttributesArray) {
                                if (isset($variantAttributesArray['key'], $variantAttributesArray['value']) && ($variantAttributesArray['key'] == $shopifyAttribute)) {
                                    foreach ($variantAttributesArray['value'] as $variantValue) {
                                        if (!empty($valueMappingsData['amazon_recommendations']) && !in_array($variantValue, $valueMappingsData['amazon_recommendations']) &&
                                            empty($this->valueMappingEligibleAttributes[$mappedAmazonAttributePath][$shopifyAttribute][(string)$variantValue])
                                        ) {
                                            $this->valueMappingEligibleAttributes[$mappedAmazonAttributePath][$shopifyAttribute][(string)$variantValue] = null;
                                        }
                                    }

                                    break;
                                }
                            }
                        } elseif (!empty($product['variant_attributes']) && !isset($product['variant_attributes_values']) && !$isOuterAttr) {
                            foreach ($product['variant_attributes'] as $variantAttributesArray) {
                                if (($shopifyAttribute == $variantAttributesArray['key']) && !empty($valueMappingsData['amazon_recommendations']) && !in_array($variantAttributesArray['value'], $valueMappingsData['amazon_recommendations']) &&
                                empty($this->valueMappingEligibleAttributes[$mappedAmazonAttributePath][$shopifyAttribute][(string)$variantAttributesArray['value']])
                                ) {
                                    $this->valueMappingEligibleAttributes[$mappedAmazonAttributePath][$shopifyAttribute][(string)$variantAttributesArray['value']] = null;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function updateValueMappingAttributesIfEmpty(): void
    {
        foreach($this->valueMappingEligibleAttributes as $path => $value) {
            if (empty($this->valueMappingEligibleAttributes[$path][$value['shopify_attribute']])) {
                unset($this->valueMappingEligibleAttributes[$path]);
            } elseif (!empty($this->alreadySavedValueMappingdata[$path][$value['shopify_attribute']])) {
                foreach($this->alreadySavedValueMappingdata[$path][$value['shopify_attribute']] as $sourceAttr => $targetAttr) {
                    array_key_exists($sourceAttr, $this->valueMappingEligibleAttributes[$path][$value['shopify_attribute']]) && ($this->valueMappingEligibleAttributes[$path][$value['shopify_attribute']][$sourceAttr] = $targetAttr);
                }
            }
        }
    }

    /** get all attributes label eligible for value_mapping */
    public function getValueMappingEligibleAttributes($mapping, $label)
    {
        if (isset($mapping['attribute_value'])) {
            // if attribute_value == shopify_attribute && recommendation_exists == true
            if (
                isset($mapping['recommendation_exists']) && $mapping['recommendation_exists'] &&
                $mapping['attribute_value'] == 'shopify_attribute' &&
                !in_array($mapping[$mapping['attribute_value']], self::RESTRICTED_VALUE_MAPPING_FIELDS)
            ) {
                $this->newlyAddedAttributes[$label] = $mapping[$mapping['attribute_value']];
                $this->valueMappingEligibleAttributes[$label] = [
                    'shopify_attribute' => $mapping[$mapping['attribute_value']],
                    'label' => $label,
                    $mapping[$mapping['attribute_value']] => []
                ];
                $this->shopifyMappedAttributes[$mapping[$mapping['attribute_value']]]['amazon_attribute_paths'][$label] = 1;
                return;
            }
            return null;
        }
        if (is_array($mapping)) {
            foreach ($mapping as $key => $value) {
                if ($key !== 'marketplace_id' && $key !== 'language_tag') {
                    $this->getValueMappingEligibleAttributes($value, $label . '/' . $key);
                }
            }
        }

        return null;
    }

    /**
     * to get all value mapping attribute data
     */
    public function getValueMappingAttributes($rawBody)
    {
        try {
            if (isset($rawBody['attribute_profile_id'])) {
                $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $profileCollection = $baseMongo->getCollection(Helper::PROFILE);
                $attributesMappingData = $profileCollection->findOne(
                    ['_id' => new ObjectId($rawBody['attribute_profile_id'])],
                    ['typeMap' => ['root' => 'array', 'document' => 'array'], 'projection' => ['data.value_mapping' => 1]]
                );
                if (!empty($attributesMappingData['data']['value_mapping'])) {
                    $page = $rawBody['page'] ?? 1;
                    $limit = $rawBody['limit'] ?? 5;
                    $totalCount = count($attributesMappingData['data']['value_mapping']);
                    $totalPages = ceil($totalCount / $limit);

                    // Calculate the starting index based on the page and limit
                    $offset = ($page - 1) * $limit;
                    // Slice the array to get the subset of data for the current page
                    $paginatedAttributes = array_slice($attributesMappingData['data']['value_mapping'], $offset, $limit);

                    // remove attributes value
                    foreach ($paginatedAttributes as $key => $data) {
                        if (isset($data['shopify_attribute'], $data[$data['shopify_attribute']])) {
                            unset($paginatedAttributes[$key][$data['shopify_attribute']]);
                        }
                    }

                    $pagination = ['current_page' => $page, 'total_pages' => $totalPages, 'total_items' => $totalCount, 'per_page' => $limit];
                    return ['success' => true, 'response' => $paginatedAttributes, 'pagination' => $pagination];
                }
                return ['success' => false, 'message' => 'Value mapping data not found'];
            }
            return ['success' => false, 'message' => 'Required param(s):attribute_profile_id missing'];
        } catch (Exception $e) {
            print_r($e->getMessage());
            return ['success' => false, 'message' => 'Something went wrong'];
        }
    }

    /**
     * to get the attributes values with the recommendations
     */
    public function getJsonMappedValues($data)
    {
        try {
            if (isset($data['attribute_profile_id'], $data['label'], $data['product_type'])) {
                $this->userId = $this->di->getUser()->id;
                $this->targetShopId = $this->di->getRequester()->getTargetId() ?? "";
                $this->sourceShopId = $this->di->getRequester()->getSourceId() ?? "";
                // if (isset($data['attribute_profile_id'], $data['label'])) {
                $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $profileCollection = $baseMongo->getCollection(Helper::PROFILE);
                $attributesMappingData = $profileCollection->findOne(
                    ['_id' => new ObjectId($data['attribute_profile_id'])],
                    ['typeMap' => ['root' => 'array', 'document' => 'array'], 'projection' => ['data.value_mapping' => 1, 'data.marketplace_id' => 1]]
                );                
                $requestParams = [
                    'user_id' => $this->userId,
                    'shop_id' => $this->targetShopId,
                    'marketplace_id' => $attributesMappingData['data']['marketplace_id'] ?? null,
                    'product_type' => $data['product_type']
                ];
                $jsonSchema = $this->getJsonSchema($requestParams);
                if (isset($jsonSchema['success']) && !$jsonSchema['success']) {
                    return $jsonSchema;
                }

                if (!empty($attributesMappingData['data']['value_mapping'][$data['label']]['shopify_attribute']) && !empty($jsonSchema)) {
                    $jsonPath = explode('/', (string) $attributesMappingData['data']['value_mapping'][$data['label']]['schema_path']);
                    $path = explode('/', (string) $data['label']);
                    $midSchema = $jsonSchema;

                    foreach ($jsonPath as $node) {
                        if (isset($midSchema[$node])) {
                            $midSchema = $midSchema[$node];
                        }
                    }

                    $recommendations = $this->getRecommendations($midSchema, $path[0] ?? null);
                    $mappedAttribute = $attributesMappingData['data']['value_mapping'][$data['label']]['shopify_attribute'];
                    $schemaPath = $attributesMappingData['data']['value_mapping'][$data['label']]['schema_path'];
                    $attributesMappingData = $attributesMappingData['data']['value_mapping'][$data['label']][$mappedAttribute];
                    $page = $data['page'] ?? 1;
                    $limit = $data['limit'] ?? 5;
                    $totalCount = count($attributesMappingData);
                    $totalPages = ceil($totalCount / $limit);            
                    $start = ($page - 1) * $limit;
                    $paginatedData = array_slice($attributesMappingData, $start, $limit, true);
                    $pagination = ['current_page' => $page, 'total_pages' => $totalPages, 'total_items' => $totalCount, 'per_page' => $limit];
                    return ['success' => true, 'response' => $paginatedData, 'recommendations' => $recommendations, 'pagination' => $pagination];
                }
                return ['success' => false, 'message' => 'Value mapping data not found'];
            }
            return ['success' => false, 'message' => 'Required param(s):attribute_profile_id or label or product_type missing'];
        } catch (Exception $e) {
            print_r($e->getMessage());
            return ['success' => false, 'message' => 'Something went wrong'];
        }
    }

    public function getJsonSchema($params)
    {
        $productTypeHelper = $this->di->getObjectManager()->get(CategoryAttributes::class);
        $jsonSchema = $productTypeHelper->getProductTypeDefinitionSchema($params, true);
        if (!isset($jsonSchema['success'], $jsonSchema['data']['schema'])) {
            return ['success' => false, 'response' => 'Failed getting attributes schema from DB.'];
        }

        $jsonSchema = json_decode((string) $jsonSchema['data']['schema'], true);
        $jsonSchema = $jsonSchema['properties'] ?? null;
        if ($jsonSchema == null || empty($jsonSchema)) {
            return ['success' => false, 'response' => 'Failed getting attributes schema.'];
        }

        return $jsonSchema;
    }

    public function getRecommendations($midSchema, $parentKey = null)
    {
        $client = ["67a7a3e71965ce578c01bcc2" , "679699dc74cfffdc5a08ad95"];    
        $recommendations = [];
        if ($parentKey == "merchant_shipping_group" || $parentKey == "brand" || (in_array($parentKey,["liquid_volume","unit_count"] ) && ($this->di->getUser()->id == in_array($this->userId,$client)))) {
            $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $amazonSellerAttributes = $baseMongo->getCollection('amazon_seller_attributes');
            $query = ['user_id' => $this->userId, "target_id" => $this->targetShopId, "source_id" => $this->sourceShopId];
            $additionalAttributesSchema = $amazonSellerAttributes->findOne($query, ['typeMap' => ['root' => 'array', 'document' => 'array'], 'projection' => ['additional_attributes' => 1]]);
            if (!empty($additionalAttributesSchema['additional_attributes'][$parentKey]['enum']) && !empty($additionalAttributesSchema['additional_attributes'][$parentKey]['enumNames'])) {
                // $recommendations = array_combine($additionalAttributesSchema['additional_attributes'][$parentKey]['enumNames'], $additionalAttributesSchema['additional_attributes'][$parentKey]['enum']);
                foreach($additionalAttributesSchema['additional_attributes'][$parentKey]['enumNames'] as $k => $v) {
                    $recommendations[$v.'('.$additionalAttributesSchema['additional_attributes'][$parentKey]['enum'][$k].')'] = $additionalAttributesSchema['additional_attributes'][$parentKey]['enum'][$k];
                }
            }
        } else {
            if (!empty($midSchema)) {
                //to find out the recommendations from the schema in all the possible cases
                if (isset($midSchema['enumNames'], $midSchema['enum'])) {
                    foreach($midSchema['enumNames'] as $key => $value){
                        $recommendations[$value.'('.$midSchema['enum'][$key].')'] = $midSchema['enum'][$key];
                    }

                    // $recommendations = array_combine($midSchema['enumNames'], $midSchema['enum']);
                } elseif (isset($midSchema['anyOf']) || isset($midSchema['oneOf'])) {
                    $optionsVal = $midSchema['anyOf'] ?? ($midSchema['oneOf'] ?? []);
                    foreach ($optionsVal as $options) {
                        if(isset($options['enumNames'], $options['enum'])) {
                            foreach($options['enumNames'] as $key => $value){
                                $recommendations[$value.'('.$options['enum'][$key].')'] = $options['enum'][$key];

                            }

                        }

                        // isset($options['enumNames'], $options['enum']) &&  $recommendations = array_combine($options['enumNames'], $options['enum']);
                    }
                } elseif (isset($midSchema['items']['anyOf']) || isset($midSchema['items']['oneOf'])) {
                    $optionsVal = $midSchema['items']['anyOf'] ?? ($midSchema['items']['oneOf'] ?? []);
                    foreach ($optionsVal as $options) {
                        if(isset($options['enumNames'], $options['enum'])) {
                            foreach($options['enumNames'] as $key => $value){
                                $recommendations[$value.'('.$options['enum'][$key].')'] = $options['enum'][$key];

                            }

                            //    $recommendations = array_combine($options['enumNames'].($options['enum']), $options['enum']);

                        }

                        // isset($options['enumNames'], $options['enum']) &&  $recommendations = array_combine($options['enumNames'], $options['enum']);
                    }
                }
            }
        }

        return $recommendations;
    }

    /**
     * to save mapped attributes mapping
     */
    public function saveJsonMappedValues($data)
    {
        try {
            if (isset($data['attribute_profile_id'], $data['label'], $data['mapped_data'])) {
                $savedMappedData = $data['mapped_data'];
                $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
                $profileCollection = $baseMongo->getCollection(Helper::PROFILE);
                $attributesData = $profileCollection->findOne(
                    ['_id' => new ObjectId($data['attribute_profile_id'])],
                    ['typeMap' => ['root' => 'array', 'document' => 'array'], 'projection' => ['data.value_mapping' => 1]]
                );
                if (!empty($attributesData) && isset($attributesData['data']['value_mapping'][$data['label']])) {
                    $mappedAttribute = $attributesData['data']['value_mapping'][$data['label']]['shopify_attribute'];
                    $attributesMappingData = $attributesData['data']['value_mapping'][$data['label']][$mappedAttribute];
                    foreach ($attributesMappingData as $shopifyAttr => $amazonAttr) {
                        if (isset($savedMappedData[$shopifyAttr]) && !is_null($savedMappedData[$shopifyAttr])) {
                            $attributesMappingData[$shopifyAttr] = $savedMappedData[$shopifyAttr];
                        }
                    }

                    $profileCollection->updateOne(
                        ['_id' => new ObjectId($data['attribute_profile_id'])],
                        [
                            '$set' => ['data.value_mapping.' . $data['label'] . '.' . $mappedAttribute => $attributesMappingData]
                        ]
                    );
                    return ['success' => true, 'message' => 'Values mapped successfully'];
                }
            } else {
                return ['success' => false, 'message' => 'Required param(s):attribute_profile_id or label missing'];
            }
        } catch (Exception $e) {
            print_r($e->getMessage());
            return ['success' => false, 'message' => 'Something went wrong'];
        }
    }
}