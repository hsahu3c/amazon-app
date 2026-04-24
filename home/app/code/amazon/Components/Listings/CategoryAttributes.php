<?php

namespace App\Amazon\Components\Listings;

use App\Amazon\Components\Common\Helper as CommonHelper;

use App\Amazon\Components\Template\CategoryAttributeCache;
use Exception;
use App\Core\Components\Base as Base;

class CategoryAttributes extends Base
{
    public const LISTING = 'LISTING';
    // product facts and sales terms
    public const LISTING_OFFER_ONLY = 'LISTING_OFFER_ONLY';
    // sales terms only
    public const LISTING_PRODUCT_ONLY = 'LISTING_PRODUCT_ONLY';
    //product facts only
    public const OFFER_SUB_CATEGORY = 'DEFAULT';

    public const DEFAULT_PRODUCT_TYPE = 'PRODUCT';

    /** get json-category settings */
    public function getJsonCategorySettings($product)
    {
        try {
            $isJsonListing = false;
            $categoryAttributes = [];

            if (
                !empty($product['edited']['category_settings']['attributes_mapping']) ||
                !empty($product['parent_details']['edited']['category_settings']['attributes_mapping'])
            ) {
                $categoryAttributes = $product['edited'];
                unset($categoryAttributes['category_settings']);
            } elseif (!empty($product['profile_info']['category_settings'])) {
                $categoryAttributes = $product['profile_info']['category_settings'];
            } else if (
                !empty($product['edited']['default_category_settings']['attributes_mapping']) ||
                !empty($product['parent_details']['edited']['default_category_settings']['attributes_mapping'])
            ) {
                $product['edited']['category_settings'] = $product['edited']['default_category_settings'];
                // $product['parent_details']['edited']['category_settings'] = $product['edited']['default_category_settings'];
                $categoryAttributes = $product['edited'];
                unset($categoryAttributes['category_settings']);
            } else {
                return $isJsonListing;
            }


            if (!empty($product['edited']['category_settings']['attributes_mapping']['jsonFeed'])) {
                $isJsonListing = true;
                $categoryAttributes['attributes_mapping'] = $product['edited']['category_settings']['attributes_mapping']['jsonFeed'];
                $categoryAttributes['product_type'] = $product['edited']['category_settings']['product_type'];
                $categoryAttributes['browser_node_id'] = $product['edited']['category_settings']['browser_node_id'] ?? '';
                $categoryAttributes['barcode_exemption'] = $product['edited']['category_settings']['barcode_exemption'] ?? '';

                $categoryAttributes['marketplace_id'] = $product['edited']['category_settings']['attributes_mapping']['marketplace_id'] ?? null;
                $categoryAttributes['language_tag'] = $product['edited']['category_settings']['attributes_mapping']['language_tag'] ?? null;
                $categoryAttributes['variantsValueMapping'] = $product['edited']['category_settings']['variants_value_mapping'] ?? null;
            } elseif (!empty($product['parent_details']['edited']['category_settings']['attributes_mapping']['jsonFeed'])) {
                $isJsonListing = true;
                $categoryAttributes['product_type'] = $product['parent_details']['edited']['category_settings']['product_type'];
                $categoryAttributes['browser_node_id'] = $product['parent_details']['edited']['category_settings']['browser_node_id'] ?? '';
                $categoryAttributes['barcode_exemption'] = $product['parent_details']['edited']['category_settings']['barcode_exemption'] ?? '';
                $categoryAttributes['marketplace_id'] = $product['parent_details']['edited']['category_settings']['attributes_mapping']['marketplace_id'] ?? null;
                $categoryAttributes['language_tag'] = $product['parent_details']['edited']['category_settings']['attributes_mapping']['language_tag'] ?? null;
                $categoryAttributes['attributes_mapping'] = $product['parent_details']['edited']['category_settings']['attributes_mapping']['jsonFeed'];
                $categoryAttributes['variantsValueMapping'] = $product['parent_details']['edited']['category_settings']['variants_value_mapping'] ?? null;
            } elseif (!empty($product['profile_info']['attributes_mapping']['data']['jsonFeed'])) {
                $isJsonListing = true;
                $categoryAttributes = $product['profile_info']['category_settings'];
                $categoryAttributes['attributes_mapping'] = $product['profile_info']['attributes_mapping']['data']['jsonFeed'];
                $categoryAttributes['marketplace_id'] = $product['profile_info']['attributes_mapping']['data']['marketplace_id'] ?? null;
                $categoryAttributes['language_tag'] = $product['profile_info']['attributes_mapping']['data']['language_tag'] ?? null;
                $categoryAttributes['profile'] = true;
                if (isset($product['edited']['bulk_edit_attributes_mapping']) && !empty($product['edited']['bulk_edit_attributes_mapping'])) {
                    if (empty($categoryAttributes['attributes_mapping'])) {
                        $categoryAttributes['attributes_mapping'] = $product['edited']['bulk_edit_attributes_mapping'];
                    } else {
                        foreach ($product['edited']['bulk_edit_attributes_mapping'] as $attributeName => $attributeValue) {
                            $categoryAttributes['attributes_mapping'][$attributeName] = $attributeValue;
                        }
                    }

                    unset($categoryAttributes['bulk_edit_attributes_mapping']);
                }
            }


            if ($isJsonListing) {
                if (
                    isset($product['edited']['status'], $categoryAttributes['product_type'], $product['type'], $product['visibility']) &&
                    $product['edited']['status'] == 'Not Listed: Offer' && ($categoryAttributes['product_type'] != self::DEFAULT_PRODUCT_TYPE
                        && $product['type'] == "simple" && $product['visibility'] == "Not Visible Individually")
                ) {
                    // if variant product is offer-product
                    $categoryAttributes['product_type'] = self::DEFAULT_PRODUCT_TYPE;
                    // $categoryAttributes['attributes_mapping'] = [];
                }


                // if( !empty($product['edited']['category_settings']['product_type'])) {

                //     $categoryAttributes['primary_category'] = $product['edited']['category_settings']['primary_category'] ?? '';
                //     $categoryAttributes['sub_category'] = $product['edited']['category_settings']['sub_category'] ?? '';
                //     $categoryAttributes['product_type'] = $product['edited']['category_settings']['product_type'] ?? '';
                //     $categoryAttributes['browser_node_id'] = $product['edited']['category_settings']['browser_node_id'] ?? '';
                //     $categoryAttributes['barcode_exemption'] = $product['edited']['category_settings']['barcode_exemption'] ?? '';
                //     if (!isset($categoryAttributes['marketplace_id'])) {
                //         $categoryAttributes['marketplace_id'] = $product['edited']['category_settings']['attributes_mapping']['marketplace_id'] ?? null;
                //         $categoryAttributes['language_tag'] = $product['edited']['category_settings']['attributes_mapping']['language_tag'] ?? null;
                //     }
                // } elseif(!empty($categoryAttributes['displayPath'])) {
                //     unset($categoryAttributes['displayPath']);
                //     unset($categoryAttributes['parentNodes']);
                // }

                if (empty($categoryAttributes['product_type'])) {
                    return [];
                }

                if (isset($product['edited']['edited_variant_jsonFeed']) && !empty($product['edited']['edited_variant_jsonFeed'])) {
                    if (empty($categoryAttributes['attributes_mapping'])) {
                        $categoryAttributes['attributes_mapping'] = $product['edited']['edited_variant_jsonFeed'];
                    } else {
                        foreach ($product['edited']['edited_variant_jsonFeed'] as $attributeName => $attributeValue) {
                            $categoryAttributes['attributes_mapping'][$attributeName] = $attributeValue;
                        }
                    }

                    unset($categoryAttributes['edited_variant_jsonFeed']);
                }

                return $categoryAttributes;
            }

            return $isJsonListing;
        } catch (Exception $e) {
            print_r($e->getMessage());
            return false;
        }
    }


    public function unsetCategoryAttributes(&$attributesSchema, $unsetAttributesArray = null, $showCategory = false): void
    {
        if (!$unsetAttributesArray) {
            /** use 'all' to skip all attributes in group */
            /** use / seprator to skip nested attributes */
            /** use 'attribute/enum' sto skip nested amazon_resommendations */
            $unsetAttributesArray = [
                'images' => 'all',
                'variations' => ['child_parent_sku_relationship', 'parentage_level'],
                'product_details' => ['product_description'],
                'product_identity' => [
                    'item_name',
                    'externally_assigned_product_identifier',
                    'merchant_suggested_asin',
                    'supplier_declared_has_product_identifier_exemption'
                ],
                'offer' => [
                    'purchasable_offer/currency',
                    'purchasable_offer/our_price',
                    'purchasable_offer/discounted_price',
                    'purchasable_offer/minimum_seller_allowed_price',
                    'fulfillment_availability/fulfillment_channel_code',
                    'fulfillment_availability/quantity',
                    'fulfillment_availability/lead_time_to_ship_max_days'
                ]
            ];
        }
        if ($showCategory === false) {
            $unsetAttributesArray['product_identity'][] = 'recommended_browse_nodes';
            // unset($unsetAttributesArray['product_identity']['recommended_browse_nodes']);
        }
        foreach ($unsetAttributesArray as $group => $attributes) {
            if (is_array($attributes)) {
                foreach ($attributes as $index => $toUnsetAttribute) {
                    $attributes = explode('/', (string) $toUnsetAttribute);
                    if (!is_numeric($index) && isset($attributes[1]) && $attributes[1] == 'enum') {
                        if (isset($attributesSchema[$group]['Optional'][$index]['items']['properties'][$attributes[0]][$attributes[1]])) {
                            unset($attributesSchema[$group]['Optional'][$index]['items']['properties'][$attributes[0]][$attributes[1]]);
                            unset($attributesSchema[$group]['Optional'][$index]['items']['properties'][$attributes[0]]['enumNames']);
                        } elseif (isset($attributesSchema[$group]['Mandatory'][$index]['items']['properties'][$attributes[0]][$attributes[1]])) {
                            unset($attributesSchema[$group]['Mandatory'][$index]['items']['properties'][$attributes[0]][$attributes[1]]);
                            unset($attributesSchema[$group]['Mandatory'][$index]['items']['properties'][$attributes[0]]['enumNames']);
                        }
                    } elseif (is_array($attributes) && isset($attributes[1])) {
                        if (isset($attributesSchema[$group]['Optional'][$attributes[0]]['items']['properties'][$attributes[1]])) {
                            unset($attributesSchema[$group]['Optional'][$attributes[0]]['items']['properties'][$attributes[1]]);
                            $requiredValueIndexToRemove = array_search($attributes[1], $attributesSchema[$group]['Optional'][$attributes[0]]['items']['required']);
                            if ($requiredValueIndexToRemove !== false) {
                                array_splice($attributesSchema[$group]['Optional'][$attributes[0]]['items']['required'], $requiredValueIndexToRemove, 1);
                            }
                        } else if (isset($attributesSchema[$group]['Mandatory'][$attributes[0]]['items']['properties'][$attributes[1]])) {
                            unset($attributesSchema[$group]['Mandatory'][$attributes[0]]['items']['properties'][$attributes[1]]);
                            $requiredValueIndexToRemove = array_search($attributes[1], $attributesSchema[$group]['Mandatory'][$attributes[0]]['items']['required']);
                            if ($requiredValueIndexToRemove !== false) {
                                array_splice($attributesSchema[$group]['Mandatory'][$attributes[0]]['items']['required'], $requiredValueIndexToRemove, 1);
                            }
                        }
                    } elseif (isset($attributesSchema[$group]['Optional'][$toUnsetAttribute])) {
                        unset($attributesSchema[$group]['Optional'][$toUnsetAttribute]);
                    } elseif (isset($attributesSchema[$group]['Mandatory'][$toUnsetAttribute])) {
                        unset($attributesSchema[$group]['Mandatory'][$toUnsetAttribute]);
                    }
                }
            } elseif (is_string($attributes) && $attributes == 'all') {
                if (isset($attributesSchema[$group])) {
                    unset($attributesSchema[$group]);
                }
            }
        }
    }

    public function getJsonCategoryAttributes($data, $skipAttributes = true)
    {
        try {
            if (isset($data['product_type'], $data['remote_shop_id'], $data['marketplace_id'], $data['user_id'])) {
                $marketplaceId = $data['marketplace_id'];
                $productType = $data['product_type'];
                $showCategory = $data['showCategory'];
                $jsonHelper = $this->di->getObjectManager()->get(Helper::class);
                // if (!$jsonHelper->isBetaSeller($data['user_id']) && !$jsonHelper->isBetaMarketplace($marketplaceId)) return false;
                if (!$jsonHelper->isBetaSeller($data['user_id'], $marketplaceId)) return false;

                $attributeCacheObj = $this->di->getObjectManager()->get(CategoryAttributeCache::class);
                $requestParams = [
                    'user_id' => $data['user_id'],
                    'shop_id' => $data['shop_id'],
                    'marketplace_id' => $marketplaceId,
                    'product_type' => $productType
                ];
                $getJsonAttributesFromDBResponse = $attributeCacheObj->getJsonAttributesFromDB($requestParams);
                if (!$getJsonAttributesFromDBResponse['success']) {
                    // product-type will be array
                    if ($productType == 'PRODUCT') {
                        // offer-listing
                        $requirements = self::LISTING_OFFER_ONLY;
                    } else {
                        // product-listing
                        $requirements = self::LISTING;
                    }

                    $params = [
                        'shop_id' => $data['remote_shop_id'],
                        'product_type' => $productType,
                        'requirements' => $requirements,
                        'product_type_version' => null, //this will use the latest version
                        'use_seller_id' => true
                    ];
                    $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                    $response = $commonHelper->sendRequestToAmazon('fetch-schema', $params, 'GET');
                    if (isset($response['success']) && !empty($response['data'])) {
                        $remoteResponse =  $response['data'];
                        $schemaContentResponse = $attributeCacheObj->getSchemaContent($remoteResponse['schema']['link']['resource']);
                        if (!$schemaContentResponse['success']) {
                            return $schemaContentResponse;
                        }

                        $schemaContent = $schemaContentResponse['data'];

                        $additionalParams = [
                            'user_id' => $data['user_id'],
                            'shop_id' => $data['shop_id'],
                            'marketplace_id' => $marketplaceId
                        ];

                        $attributeCacheObj->saveAttributesInDB($remoteResponse, $additionalParams, $schemaContent);

                        $prepareAttributesParams = [
                            'schema_content' => $schemaContent,
                            'property_groups' => $remoteResponse['propertyGroups'],
                            'requirements' => $remoteResponse['requirements']
                        ];
                        $preparedDataResponse = $attributeCacheObj->prepareJsonAttributes($prepareAttributesParams);
                        if (!$preparedDataResponse['success']) {
                            return ['success' => false, 'message' => $preparedDataResponse['message']];
                        }

                        $preparedData = $preparedDataResponse['data'];

                        $returnResult = [
                            'attributes' => $preparedData,
                            'param' => $preparedDataResponse['param'],
                            'json_listing' => true, // used on front-end
                            'product_type' => $remoteResponse['productType'] ?? null,
                            'language_tag' => $remoteResponse['locale'], //used during feed-preparation
                            'marketplace_id' => $marketplaceId
                        ];
                    } else {
                        return ['success' => false, 'message' => $response['error'] ?? 'Error in fetching json schema'];
                    }
                } else {
                    $returnResult = $getJsonAttributesFromDBResponse['data'];
                }

                $attributeCacheObj->addSellerWiseAttributesIfAvailable($returnResult, $data);

                if (!empty($returnResult['attributes'])) {
                    if ($skipAttributes) {
                        $this->unsetCategoryAttributes($returnResult['attributes'], null, $showCategory);
                    }

                    return ['success' => true, 'data' => $returnResult];
                }
                return ['success' => false, 'message' => 'Error in fetching json schema'];
            }
            return ['success' => false, 'message' => 'Required param(s): product_type, shop_id not found'];
        } catch (Exception $e) {
            print_r($e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
        }
    }

    public function getProductType($data)
    {
        try {
            if (isset($data['title'], $data['source']['shopId'], $data['target']['shopId']) || isset($data['keywords'], $data['source']['shopId'], $data['target']['shopId']) ) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                // $userId = '66cdbb699c331d2fe701b4d8';
                $sourceShopId = $data['source']['shopId'];
                $homeShopId = $data['target']['shopId'];
                $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $remoteShop = $userDetails->getShop($homeShopId, $userId);
                $sourceMarketplace = $data['source']['marketplace'];

                $additionalData['source'] = [
                    'marketplace' => $sourceMarketplace,
                    'shopId' => (string)$sourceShopId
                ];
                $additionalData['target'] = [
                    'marketplace' => 'amazon',
                    'shopId' => (string)$homeShopId
                ];
                // print_r($remoteShop);die;
                // $source_product_id = $data['source_product_id'];
                if ($remoteShop && !empty($remoteShop)) {
                    $remoteShopId = $remoteShop['remote_shop_id'];
                    $params = [
                        'shop_id' => $remoteShopId,
                    ];
                    if (isset($data['title'])) {
                        $params['itemName'] = $data['title'];
                    }
                    if (isset($data['keywords'])) {
                        $params['keywords'] = $data['keywords'];
                    }
                    // print_r($params);die;
                    $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);
                    $result = $commonHelper->sendRequestToAmazon('fetch-product-types', $params, 'GET');
                    if (isset($result['success'], $result['data']) && !empty($result['data'])) {
                        return ['success' => true, 'data' => array_column($result['data'], 'name')];
                    }
                    return ['success' => false, 'message' => 'Error fetching product_types'];
                }
                return ['success' => false, 'message' => 'Target Shop Not Connected.'];
            }
            return ['success' => false, 'message' => 'Required Params Missing.'];
        } catch (Exception $e) {
            print_r($e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
        }
    }

    /** get variation-theme attribute path- required params:- product_type, variation_theme */
    public function getVariationThemeAttribute($data)
    {
        try {
            $error = 'Error fetching variation theme attributes.';
            $sizeClassAttributes = [
                'apparel_size' => 'apparel_size/size',
                'shapewear_size' => 'shapewear_size/size',
                'shirt_size' => 'shirt_size/0/size',
                'headwear_size' => 'headwear_size/size',
                'footwear_size' => 'footwear_size/size',
                'bottoms_size' => 'bottoms_size/size',
                'skirt_size' => 'skirt_size/size',
                'band' => 'band/size/value',
                'beam' => 'beam/size',
                'cap' => 'cap/size/',
                'case' => 'case/size',
                'container' => 'container/size',
                'display' => 'display/size',
                'flask' => 'flask/size',
                'frame' => 'frame/size',
                'graphics_ram' => 'graphics_ram/size/value',
                'hard_disk' => 'hard_disk/size',
                'head' => 'head/size',
                'optical_sensor' => 'optical_sensor/size',
                'photo_sensor' => 'photo_sensor/size',
                'rim' => 'rim/size',
                'ring' => 'ring/size',
                'rom' => 'rom/size'
            ];
            if (isset($data['product_type'], $data['variation_theme'])) {
                if (!isset($data['target']['shopId']) || empty($data['target']['shopId'])) {
                    $targetShopId = $this->di->getRequester()->getTargetId();
                } else {
                    $targetShopId = $data['target']['shopId'];
                }

                $userId = $this->di->getUser()->id;
                $variationThemeAttribue = explode('/', (string) $data['variation_theme']);
                if (!empty($variationThemeAttribue)) {
                    $listingHelper = $this->di->getObjectManager()->get(Helper::class);
                    $targetShop = $listingHelper->getTargetShop($userId, $targetShopId);
                    $marketplaceId = $targetShop['warehouses'][0]['marketplace_id'] ?? '';
                    $remoteShopId = $targetShop['remote_shop_id'] ?? '';
                    $payload = [
                        'product_type' => $data['product_type'],
                        'remote_shop_id' => (string)$remoteShopId,
                        'marketplace_id' => (string)$marketplaceId,
                        'category' => false,
                        'user_id' => $userId,
                        'shop_id' => $targetShopId
                    ];
                    $categoryAttributesData = $this->getJsonCategoryAttributes($payload);
                    if (isset($categoryAttributesData['success'], $categoryAttributesData['data']['attributes']) && !empty($categoryAttributesData['data']['attributes'])) {
                        $resultAttributes = [];
                        foreach ($variationThemeAttribue as $variantAttribute) {
                            $variantAttribute = strtolower($variantAttribute);
                            foreach ($categoryAttributesData['data']['attributes'] as $group => $groupedAttributes) {
                                if (isset($groupedAttributes['Optional'][$variantAttribute]) || isset($groupedAttributes['Required'][$variantAttribute])) {
                                    $resultAttributes[] = $group . DS . $variantAttribute;
                                    break;
                                } else {
                                    $variantAttributeName = explode('_', $variantAttribute);
                                    if (isset($variantAttributeName[1]) && $variantAttributeName[1] == 'name') {
                                        if (isset($groupedAttributes['Optional'][$variantAttributeName[0]]) || isset($groupedAttributes['Required'][$variantAttributeName[0]])) {
                                            $resultAttributes[] = $group . DS . $variantAttributeName[0];
                                            break;
                                        }
                                    } elseif ($variantAttribute == 'size') {
                                        foreach ($sizeClassAttributes as $sizeAttribute => $path) {
                                            if (isset($groupedAttributes['Optional'][$sizeAttribute]) || isset($groupedAttributes['Required'][$sizeAttribute])) {
                                                $resultAttributes[] = $group . DS . $path;
                                                break;
                                            }
                                        }
                                    } elseif ($variantAttribute == 'frame_type') {
                                        if (isset($groupedAttributes['Optional']['frame']['items']['properties']['type']) || isset($groupedAttributes['Required']['frame']['items']['properties']['type'])) {
                                            $resultAttributes[] = $group . DS . 'frame' . DS . 'type';
                                        }
                                    }
                                }
                            }
                        }

                        return ['success' => true, 'data' => $resultAttributes];
                    }
                } else {
                    $error = 'Variation theme attributes can not be empty.';
                }
            } else {
                $error = 'Required params(product_type, variation_theme) missing.';
            }

            return ['success' => false, 'message' => $error];
        } catch (Exception $e) {
            print_r($e->getMessage());
            return ['success' => false, 'message' => 'Error fetching variation theme attributes.'];
        }
    }

    public function getProductTypeDefinitionSchema($data, $skipAttributes = true)
    {
        if (!isset($data['remote_shop_id'])) {
            $shops = $this->di->getUser()->shops;
            foreach ($shops as $shop) {
                if ($data['shop_id'] == $shop['_id']) {
                    $data['remote_shop_id'] = $shop['remote_shop_id'];
                    break;
                }
            }
        }

        if (!isset($data['product_type'], $data['remote_shop_id'], $data['marketplace_id'], $data['user_id'])) {
            return ['success' => false, 'message' => 'Required parameters: product_type, remote_shop_id, marketplace_id, user_id not found'];
        }

        $marketplaceId = $data['marketplace_id'];
        $productType = $data['product_type'];
        $userId = $data['user_id'];
        $remoteShopId = $data['remote_shop_id'];
        $shopId = $data['shop_id'] ?? null;

        try {
            $attributeCacheObj = $this->di->getObjectManager()->get(CategoryAttributeCache::class);

            $requestParams = [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'marketplace_id' => $marketplaceId,
                'product_type' => $productType
            ];
            $getJsonAttributesFromDBResponse = $attributeCacheObj->getJsonAttributesFromDB($requestParams, $skipAttributes);

            if ($getJsonAttributesFromDBResponse['success']) {
                $returnResult = $getJsonAttributesFromDBResponse;
                $this->addSellerSpecificAttributes($returnResult, $data);
                return $returnResult;
                // return $getJsonAttributesFromDBResponse;
            }

            $requirements = ($productType === 'PRODUCT') ? self::LISTING_OFFER_ONLY : self::LISTING;

            $params = [
                'shop_id' => $remoteShopId,
                'product_type' => $productType,
                'requirements' => $requirements,
                'product_type_version' => null,
                'use_seller_id' => true
            ];

            $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
            $response = $commonHelper->sendRequestToAmazon('fetch-schema', $params, 'GET');

            if (!isset($response['success']) || empty($response['data'])) {
                return ['success' => false, 'message' => $response['error'] ?? 'Error fetching JSON schema from Amazon'];
            }

            $remoteResponse = $response['data'];
            $schemaContentResponse = $attributeCacheObj->getSchemaContent($remoteResponse['schema']['link']['resource']);

            if (!$schemaContentResponse['success']) {
                return $schemaContentResponse;
            }

            $schemaContent = $schemaContentResponse['data'];

            $additionalParams = [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'marketplace_id' => $marketplaceId
            ];

            $attributeCacheObj->saveAttributesInDB($remoteResponse, $additionalParams, $schemaContent);
            $preparedData = $attributeCacheObj->prepareForDB($remoteResponse, $additionalParams, $schemaContent);

            return ['success' => true, 'data' => $preparedData];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
        }
    }
    public function addSellerSpecificAttributes(&$data, $additionalParams): void
    {
        $attributeCacheObj = $this->di->getObjectManager()->get(CategoryAttributeCache::class);
        $jsonCollection = $this->di->getObjectManager()
            ->get('\App\Core\Models\BaseMongo')
            ->getCollectionForTable('amazon_seller_attributes');
        $query = [
            'user_id' => $additionalParams['user_id'] ?? $this->di->getUser()->id,
            'marketplace_id' => $additionalParams['marketplace_id']
        ];
        $options = ['typeMap'  => ['root' => 'array', 'document' => 'array']];

        $sellerWiseAttributes = $jsonCollection->findOne($query, $options);

        if (empty($sellerWiseAttributes['additional_attributes'])) {
            $fetchSellerWiseAttributesData = [
                'target_shop' => [
                    '_id' => $additionalParams['shop_id'],
                    "marketplace" => "amazon"
                ],
                'user_id' => $additionalParams['user_id'] ?? $this->di->getUser()->id,
            ];

            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\ConfigurationEvent')->addSellerWiseProductTypeAttributes($fetchSellerWiseAttributesData);

            if ($response['success']) {
                $sellerWiseAttributes['additional_attributes'] = $response['data'];
            }
        }

        foreach (CategoryAttributeCache::SELLER_WISE_AMAZON_ATTRIBUTES as $additionalAttribute) {


            $schema = json_decode((string)$data['data']['schema'], true);
            if (isset($schema['properties'][$additionalAttribute]['items']['properties']['value'])) {
                if (isset($sellerWiseAttributes['additional_attributes'][$additionalAttribute])) {
                    $schema['properties'][$additionalAttribute]['items']['properties']['value'] = array_merge($schema['properties'][$additionalAttribute]['items']['properties']['value'], $sellerWiseAttributes['additional_attributes'][$additionalAttribute]);
                }
                // if($additionalAttribute == "merchant_shipping_group"){

                //     print_r($sellerWiseAttributes['additional_attributes']);die;
                //     print_r($schema['properties'][$additionalAttribute]['items']['properties']['value']);die("yye");
                // }
            }
            $data['data']['schema'] = json_encode($schema, true);
        }
    }
}
