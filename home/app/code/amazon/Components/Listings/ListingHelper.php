<?php

namespace App\Amazon\Components\Listings;

use App\Amazon\Components\Template\CategoryAttributeCache;
use App\Amazon\Components\Common\Barcode;
use App\Amazon\Components\Common\Helper as CommonHelper;
use MongoDB\BSON\ObjectId;
use Exception;
use App\Amazon\Components\Product\Inventory;
use App\Amazon\Components\Product\Image;
use App\Core\Components\Base as Base;

class ListingHelper extends Base
{
    protected $product = [];

    protected $jsonFeedDataRes = '';

    protected $marketplaceId = null;

    protected $languageTag = null;

    protected $sourceMarketplace = '';

    protected $categorySettings = '';

    protected $targetShopId = '';

    protected $properties = [];

    public $attributesData = [];

    public $RemoveAttribute = [];

    public $errorOnProduct = [];

    public $variantJsonAttributeValues = [];

    public const PRODUCT_TYPE_PARENT = 'parent';

    public const PRODUCT_TYPE_CHILD = 'child';

    public const OFFER_SUB_CATEGORY = 'DEFAULT';

    public const BARCODE_EXEMPTION_LABEL = 'supplier_declared_has_product_identifier_exemption';

    public const BARCODE_LABEL = 'externally_assigned_product_identifier';

    public const MAIN_PRODUCT_IMAGE_LABEL = 'main_product_image_locator';

    public const OTHER_PRODUCT_IMAGE_LABEL = 'other_product_image_locator';

    public const VARIATION_THEME_LABEL = 'variation_theme';

    public const LISTING = 'LISTING';
    // product facts and sales terms
    public const LISTING_OFFER_ONLY = 'LISTING_OFFER_ONLY';
    // sales terms only
    public const MAXIMUM_ITEM_NAME_LENGTH = 200;

    public const MARKETPLACE_ID_TAG = 'marketplace_id';

    public const LANGUAGE_TAG = 'language_tag';

    public $NEW_LISTING_DEFAULT_ATTRIBUTES = [
        'item_name',
        'merchant_suggested_asin',
        'supplier_declared_has_product_identifier_exemption',
        // 'recommended_browse_nodes',
        'purchasable_offer',
        'product_description',
        'fulfillment_availability',
        'skip_offer',
        'parentage_level',
        'child_parent_sku_relationship'
    ];
    public $variantParentJsonListingData = [
        "item_name",
        "brand",
        // "recommended_browse_nodes",
        "product_description",
        "bullet_point",
        "country_of_origin",
        "supplier_declared_dg_hz_regulation",
        "parentage_level",
        "child_parent_sku_relationship",
        "variation_theme",
        "batteries_required",
        "item_type_keyword",
        "fabric_type",
        "batteries_included",
        "battery",
        "lithium_battery",
        "num_batteries",
        "number_of_lithium_ion_cells",
        "ghs",
        "safety_data_sheet_url"
    ];

    public const OFFER_LISTING_DEFAULT_ATTRIBUTES = [
        'merchant_suggested_asin',
        'purchasable_offer',
        'fulfillment_availability',
        'skip_offer'
    ];
    public $OFFER_PRODUCT_ATTRIBUTES = [
        'item_package_weight',
        'item_package_dimensions',
        'item_dimensions',
        'ships_globally',
        'item_weight',
        'safety_data_sheet_url',
        'hazmat',
        'liquid_volume',
        'contains_liquid_contents',
        'ghs',
        'supplier_declared_dg_hz_regulation',
        'lithium_battery',
        'number_of_lithium_ion_cells',
        'number_of_lithium_metal_cells',
        'num_batteries',
        'battery',
        'batteries_included',
        'batteries_required',
        'country_of_origin',
        'manufacturer_contact_information',
        'gift_options',
        'max_order_quantity',
        'merchant_shipping_group',
        'merchant_release_date',
        'product_tax_code',
        'condition_note',
        'condition_type',
        'skip_offer',
        'national_stock_number',
        'unspsc_code',
        'merchant_suggested_asin',
        'externally_assigned_product_identifier',
        'external_product_information'
    ];

    public const DEFAULT_ATTRIBUTES_GROUP = [
        'item_name' => 'product_identity',
        'merchant_suggested_asin' => 'product_identity',
        'supplier_declared_has_product_identifier_exemption' => 'product_identity',
        'externally_assigned_product_identifier' => 'product_identity',
        'recommended_browse_nodes' => 'product_identity',
        'purchasable_offer' => 'offer',
        'fulfillment_availability' => 'offer',
        'skip_offer' => 'offer',
        'merchant_shipping_group' => 'offer',
        'parentage_level' => 'variations',
        'child_parent_sku_relationship' => 'variations',
        'product_description' => 'product_details',
    ];

    public const ALLOWED_BARCODE_TYPE = ['EAN', 'GTIN', 'UPC', 'EAN-8'];

    public const MAX_DESCRIPTION_SIZE = 2000;

    public function init($data): void
    {

        $sourceMarketplace = $data['sourceMarketplace'] ?? "";
        $product = $data['product'] ?? "";
        $categorySettings = $data['categorySettings'] ?? "";
        $targetShopId = $data['targetShopId'] ?? "";
        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');

        $this->sourceMarketplace = !$sourceMarketplace ? $product['source_marketplace'] : $sourceMarketplace;
        $this->categorySettings = $categorySettings ?? [];
        $this->product = $product ?? [];
        if (!empty($categorySettings['marketplace_id'])) {
            $this->marketplaceId = $categorySettings['marketplace_id'];
        }
        if (isset($this->product['edited']['category_settings']['variants_json_attributes_values']) && !empty($this->product['edited']['category_settings']['variants_json_attributes_values'])) {
            $this->variantJsonAttributeValues = $this->product['edited']['category_settings']['variants_json_attributes_values'];
        } elseif ($this->product['type'] == "variation" && !isset($this->product['edited']['category_settings']['variants_json_attributes_values'])) {
            $params = [
                'user_id' => $this->di->getUser()->id,
                'shop_id' => $targetShopId,
                'container_id' => $product['container_id'],
                'category_settings.variants_json_attributes_values' => ['$exists' => true],
                'target_marketplace' => 'amazon',
            ];

            $allEditedProductsData = $helper->getProductByQuery($params);
            $allEditedProductsData = json_decode(json_encode($allEditedProductsData), true);
            foreach ($allEditedProductsData as $allEditedData) {
                if (!empty($allEditedData['category_settings']['variants_json_attributes_values'])) {
                    $this->variantJsonAttributeValues = $allEditedData['category_settings']['variants_json_attributes_values'];
                }
            }
        }

        if (!empty($categorySettings['language_tag'])) {
            $this->languageTag = $categorySettings['language_tag'];
        }

        if (isset($categorySettings['product_type']) && is_array($categorySettings['product_type'])) {
            $categorySettings['product_type'] = $categorySettings['product_type'][0] ?? "";
        }

        if (!isset($this->attributesData[$categorySettings['product_type']]) && isset($categorySettings['product_type']) && is_string($categorySettings['product_type'])) {
            $params['user_id'] = $product['user_id'] ?? $this->di->getUser()->id;
            $params['marketplace_id'] = $categorySettings['marketplace_id'] ?? "";
            $params['product_type'] = $categorySettings['product_type'] ?? "";
            $params['shop_id'] = $data['targetShopId'] ?? "";
            $jsonAttributesDB = $this->di->getObjectManager()->get(CategoryAttributes::class)->getProductTypeDefinitionSchema($params, true);
            $this->attributesData[$categorySettings['product_type']] = $jsonAttributesDB;
        }

        // $this->attributesData [$categorySettings['product_type']]= $this->di->getObjectManager()->get('App\Amazon\Components\Template\CategoryAttributeCache')->getJsonAttributesFromDB($this->marketplaceId, $categorySettings['product_type'], true);
    }
    public function resetclassVariables()
    {
        $this->attributesData = [];
        $this->variantJsonAttributeValues = [];
        $this->product = [];
        $this->categorySettings = [];
        $this->marketplaceId = "";
        $this->languageTag = "";
        $this->RemoveAttribute = [];
    }

    /** get process tags to be remove on product */
    public function getProgressTag($product, $sourceProductId, $targetShopId, $operationType)
    {
        if (isset($product['marketplace']) && !empty($product['marketplace'])) {
            if ($operationType == 'Update') {
                $tagToRemove = 'Product Upload Feed Submitted Successfully';
            } else {
                $tagToRemove = 'Product Update Feed Submitted Successfully';
            }

            foreach ($product['marketplace'] as $marketplace) {
                if (
                    isset($marketplace['source_product_id'], $marketplace['shop_id']) && $marketplace['source_product_id'] == $sourceProductId &&
                    $marketplace['shop_id'] == $targetShopId
                ) {
                    if (isset($marketplace['process_tags']) && !empty($marketplace['process_tags'])) {
                        if (isset($marketplace['process_tags'][$tagToRemove])) {
                            $tags = [];
                            if (count($marketplace['process_tags']) > 1) {
                                /** remove specific tag */
                                foreach ($marketplace['process_tags'] as $message => $processTag) {
                                    if ($message != $tagToRemove) {
                                        $tags[$message] = $processTag;
                                    }
                                }
                            }

                            return $tags;
                        }
                        return false;
                    }

                    break;
                }
            }
        } elseif (isset($product['edited']) && (!empty($product['edited']))) {
            if ($operationType == 'Update') {
                $tagToRemove = 'Product Upload Feed Submitted Successfully';
            } else {
                $tagToRemove = 'Product Update Feed Submitted Successfully';
            }

            $edited = $product['edited'];
            if (isset($edited['process_tags']) && !empty($edited['process_tags'])) {
                if (isset($edited['process_tags'][$tagToRemove])) {
                    $tags = [];
                    if (count($edited['process_tags']) > 1) {
                        /** remove specific tag */
                        foreach ($edited['process_tags'] as $message => $processTag) {
                            if ($message != $tagToRemove) {
                                $tags[$message] = $processTag;
                            }
                        }
                    }

                    return $tags;
                }
                return false;
            }
        }

        return false;
    }

    /** set feed content json */
    public function setContentJsonListing($categorySettings, $product, $type, $operationType, $sourceMarketplace = false, $preview = false)
    {
        try {
            $product = json_decode(json_encode($product), true);
            $this->sourceMarketplace = !$sourceMarketplace ? $product['source_marketplace'] : $sourceMarketplace;
            if ((!empty($product['edited']['status']) && $product['edited']['status'] === 'Not Listed: Offer') || $this->categorySettings['product_type'] == "PRODUCT") {
                $isOfferProduct = true;
                $this->categorySettings['product_type'] = 'PRODUCT';
                $categorySettings['product_type'] = 'PRODUCT';
            } else {
                $isOfferProduct = false;
            }

            $this->categorySettings = $categorySettings ?? [];
            $this->product = $product ?? [];
            if (!empty($categorySettings['marketplace_id'])) {
                $this->marketplaceId = $categorySettings['marketplace_id'];
            }

            if (!empty($categorySettings['language_tag'])) {
                $this->languageTag = $categorySettings['language_tag'];
            }

            $offerApplicable = false;

            if ((isset($this->categorySettings['primary_category'], $this->categorySettings['sub_category'])
                    && ($this->categorySettings['primary_category'] == 'default' && $this->categorySettings['sub_category'] == 'default')) ||
                (isset($this->categorySettings['product_type'])
                    && ($this->categorySettings['product_type'] == 'PRODUCT'))
            ) {
                $offerApplicable = true;
            }

            if (
                $offerApplicable &&
                (
                    (!$isOfferProduct || empty($this->product['edited']['asin'])) &&
                    $this->product['type'] != 'variation'
                ) &&
                (
                    !isset($this->product['edited']['status']) ||
                    (isset($this->product['edited']['status']) && $this->product['edited']['status'] === 'Not Listed: Offer')
                )
            ) {
                return ['error' => ['AmazonError301']];
            }

            if ($isOfferProduct) {
                if ($product['type'] == 'variation') {
                    // Offer cannot be created for parent product
                    return [];
                }
                if (!isset($categorySettings['attributes_mapping']) || empty($categorySettings['attributes_mapping'])) {
                    return ['error' => ['AmazonError101']];
                }
                if (!isset($categorySettings['attributes_mapping']['condition_type'][0]['value'])) {
                    return ['error' => ['AmazonError111']];
                }
                // elseif(!isset($categorySettings['asin'])) {
                //     return ['error' => ['AmazonError301']];
                // }
                if ($operationType == 'Update') {
                    // check if eligible for creating offer
                    $schema = $categorySettings['attributes_mapping']['condition_type'][0]['value'] ?? '';
                    if (isset($schema['attribute_value'])) {
                        if ($schema['attribute_value'] == 'recommendation' || $schema['attribute_value'] == 'custom') {
                            $conditionTypeValue = $schema[$schema['attribute_value']];
                        } elseif ($schema['attribute_value'] == 'shopify_attribute') {
                            if (isset($schema['meta_attribute_exist']) && $schema['meta_attribute_exist']) {
                                if (isset($this->product['parentMetafield'][$schema['shopify_attribute']])) {
                                    $conditionTypeValue = $this->product['parentMetafield'][$schema['shopify_attribute']]['value'];
                                } elseif (isset($this->product['variantMetafield'][$schema['shopify_attribute']])) {
                                    $conditionTypeValue = $this->product['variantMetafield'][$schema['shopify_attribute']]['value'];
                                } elseif (isset($this->product['parent_details']['parentMetafield'][$schema['shopify_attribute']])) {
                                    $conditionTypeValue = $this->product['parent_details']['parentMetafield'][$schema['shopify_attribute']]['value'];
                                }
                            } else {
                                $conditionTypeValue = $this->product['edited'][$schema['shopify_attribute']] ?? $this->product[$schema['shopify_attribute']] ?? '';
                            }
                        }

                        if (!empty($conditionTypeValue)) {
                            $params = [
                                'condition_type' => $conditionTypeValue,
                                'asin' => $this->product['edited']['asin'],
                                'user_id' => $product['user_id'],
                                'shop_id' => $this->product['edited']['shop_id']
                            ];
                            $listingHelper = $this->di->getObjectManager()->get(Helper::class);
                            $result = $listingHelper->ifOfferRestricted($params);
                            if (isset($result['error']) && !empty($result['error'])) {
                                return $result;
                            }
                        } else {
                            return ['error' => ['AmazonError111']];
                        }
                    }
                }
            }

            if (isset($product['edited']['shop_id'])) {
                $this->targetShopId = $product['edited']['shop_id'];
            } elseif (isset($product['profile'][0]['target_shop_id'])) {
                $this->targetShopId = $product['profile'][0]['target_shop_id'];
            }
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $configCollection = $mongo->getCollection('config');
            $query = [
                'user_id' => $this->di->getUser()->id,
                'group_code' => 'product',
                'key' => 'formula',
                'target_shop_id' => $this->targetShopId
            ];


            $configData = $configCollection->findOne($query);

            $configData = json_decode(json_encode($configData), true);
            if (isset($configData['value']) && $configData['value'] && isset($configData['value']['package_weight']) && !in_array('item_package_weight', $this->NEW_LISTING_DEFAULT_ATTRIBUTES)) {
                $this->NEW_LISTING_DEFAULT_ATTRIBUTES[] = 'item_package_weight';
                $this->variantParentJsonListingData[] = 'item_package_weight';
                // $this->OFFER_LISTING_DEFAULT_ATTRIBUTES [] = 'item_package_weight';

            }

            $feedContent = [];
            $jsonFeedData = [];
            $errorsOnProduct = [];
            $themeExist = true;

            //Illegal offset type in isset or empty
            if (is_array($this->categorySettings['product_type'])) {
                $this->categorySettings['product_type'] = $this->categorySettings['product_type'][0] ?? '';
            }

            if (!empty($this->attributesData) && isset($this->attributesData[$this->categorySettings['product_type']])) {
                $attributesData = $this->attributesData[$this->categorySettings['product_type']];
            } else {
                $params['marketplace_id'] = $this->marketplaceId;
                $params['product_type'] = $this->categorySettings['product_type'] ?? "";
                $params['shop_id'] = $this->targetShopId;
                $params['user_id'] = $this->di->getUser()->id;

                $attributesData = $this->di->getObjectManager()->get(CategoryAttributeCache::class)->getJsonAttributesFromDB($params, true);
            }

            // $attributesData = $this->di->getObjectManager()->get('App\Amazon\Components\Template\CategoryAttributeCache')->getJsonAttributesFromDB($this->marketplaceId, $this->categorySettings['product_type'], true);
            if (!empty($attributesData['data']['schema'])) {
                //this check is added to skip parentage_level and child_parent_sku_relationship keys if variation_theme key not available in the schema
                $schema = json_decode((string) $attributesData['data']['schema'], true);
                if (!empty($schema['properties']) && !array_key_exists('variation_theme', $schema['properties'])) {
                    if ($type == self::PRODUCT_TYPE_PARENT) {
                        return [];
                    }

                    $themeExist = false;
                }

                if (!empty($schema['properties']) && array_key_exists('recommended_browse_nodes', $schema['properties']) && !in_array('recommended_browse_nodes', $this->NEW_LISTING_DEFAULT_ATTRIBUTES)) {


                    $this->NEW_LISTING_DEFAULT_ATTRIBUTES[] = 'recommended_browse_nodes';
                    $this->variantParentJsonListingData[] = 'recommended_browse_nodes';
                }
            }

            if ($isOfferProduct) {
                foreach (self::OFFER_LISTING_DEFAULT_ATTRIBUTES as $attributeName) {
                    if (!$themeExist && ($attributeName == 'parentage_level' || $attributeName == 'child_parent_sku_relationship')) {
                        continue;
                    }

                    $res = $this->getDefaultAttributes($type, $attributeName);
                    $jsonFeedData[$attributeName] = $res['feedValue'] ?? "";
                    unset($res['feedValue']);
                    if (!empty($res['error'])) {
                        $errorsOnProduct = array_merge($errorsOnProduct, $res['error']);
                    } elseif (!empty($res) && $attributeName == "fulfillment_availability" && !empty($res['fulfillment_availability'])) {
                        if (empty($feedContent[$attributeName])) {
                            $feedContent[$attributeName] = [];
                        }
                        $feedContent[$attributeName] = [...$feedContent[$attributeName], ...$res['fulfillment_availability']];
                    } elseif (!empty($res)) {
                        $feedContent[$attributeName][] = $res;
                    }
                }
            } else {
                foreach ($this->NEW_LISTING_DEFAULT_ATTRIBUTES as $attributeName) {
                    if (!$themeExist && ($attributeName == 'parentage_level' || $attributeName == 'child_parent_sku_relationship')) {
                        continue;
                    }

                    $res = $this->getDefaultAttributes($type, $attributeName);
                    if (isset($res['feedValue'])) {
                        $jsonFeedData[$attributeName] = $res['feedValue'];
                        unset($res['feedValue']);
                    }

                    if (!empty($res['error'])) {
                        $errorsOnProduct = array_merge($errorsOnProduct, $res['error']);
                    } elseif (!empty($res) && $attributeName == "purchasable_offer" && isset($res['business_price'])) {
                        if (isset($res['business_price'])) {
                            $feedContent[$attributeName][] = $res['business_price'];
                            unset($res['business_price']);
                        }
                        $feedContent[$attributeName][] = $res;
                    } elseif (!empty($res) && $attributeName == "fulfillment_availability" && !empty($res['fulfillment_availability'])) {
                        if (empty($feedContent[$attributeName])) {
                            $feedContent[$attributeName] = [];
                        }
                        $feedContent[$attributeName] = [...$feedContent[$attributeName], ...$res['fulfillment_availability']];
                    } elseif (!empty($res)) {
                        $feedContent[$attributeName][] = $res;
                    }
                }

                $imageAttributes = $this->getImagesUrl($type);
                if (!empty($imageAttributes)) {
                    foreach ($imageAttributes as $key => $value) {
                        $jsonFeedData[$key] = $value[0]['media_location'] ?? "";
                    }
                }

                $feedContent = array_merge($feedContent, $imageAttributes);
                unset($imageAttributes);
            }

            /** to-do map parent edited data extra key in parent */
            // remove special characters from product_description
            if (isset($feedContent['product_description'][0]['value'])) {
                if (mb_strlen((string) $feedContent['product_description'][0]['value'],'UTF-8') > self::MAX_DESCRIPTION_SIZE) {
                    array_push($errorsOnProduct, 'AmazonError103');
                } else {
                    $value = $feedContent['product_description'][0]['value'];
                    // newline and carriage return characters replaced by spaces.
                    $value = str_replace(["\n", "\r"], ' ', $value);
                    // removes any content enclosed within <span> tags, including the tags themselves
                    $value = preg_replace('#</?span[^>]*>#is', '', $value);
                    // any HTML tags other than <p>, <ul>, <li>, and <strong> will be removed
                    $value = strip_tags($value, '<p></p><ul></ul><li></li><strong></strong>');
                    // remove inline style attributes from HTML tags
                    $value = preg_replace('/(<[^>]+) style=".*?"/i', '$1', $value);
                    // removes the entire style attribute from HTML tags while preserving the rest of the tag
                    $value = preg_replace("/(<[^>]+) style='.*?'/i", '$1', $value);
                    // removes the class attribute from HTML tags
                    $value = preg_replace('/(<[^>]+) class=".*?"/i', '$1', $value);
                    // removes the class attribute from HTML tags, where value is enclosed in single quotes
                    $value = preg_replace("/(<[^>]+) class='.*?'/i", '$1', $value);
                    // remove data attribute from HTML tags.
                    $value = preg_replace('/(<[^>]+) data=".*?"/i', '$1', $value);
                    // remove data attribute from HTML tags, where the attribute value is enclosed in single quotes
                    $value = preg_replace("/(<[^>]+) data='.*?'/i", '$1', $value);
                    // removes the data-mce-fragment attribute from HTML tags
                    $value = preg_replace('/(<[^>]+) data-mce-fragment=".*?"/i', '$1', $value);
                    // removes the data-mce-fragment attribute where value is enclosed in single quotes
                    $value = preg_replace("/(<[^>]+) data-mce-fragment='.*?'/i", '$1', $value);
                    // removes the data-mce-style attribute from HTML tags.
                    $value = preg_replace('/(<[^>]+) data-mce-style=".*?"/i', '$1', $value);
                    // normalizes HTML tags by removing unnecessary attributes, styles, and classes
                    $value = preg_replace("/(<[^>]+) data-mce-style='.*?'/i", '$1', $value);
                    $value = preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/si", '<$1$2>', $value);
                    $feedContent['product_description'][0]['value'] = $value;
                    $jsonFeedData['product_description'] = $value;
                }
            }

            if (
                $isOfferProduct ||
                (isset($feedContent[self::BARCODE_EXEMPTION_LABEL][0]['value']) && !$feedContent[self::BARCODE_EXEMPTION_LABEL][0]['value'])
            ) {
                if ($type != self::PRODUCT_TYPE_PARENT) {
                    // map externally_assigned_product_identifier (barcode), external_product_id_type
                    $barcode = $this->product['edited']['barcode'] ?? $this->product['barcode'] ?? '';
                    $barcode = str_replace("-", "", $barcode);
                    $barcodeHelper = $this->di->getObjectManager()->get(Barcode::class);
                    if (!empty($this->product['edited']['barcode_type'])) {
                        $barcodeType = $this->product['edited']['barcode_type'];
                    } else {
                        $barcodeType = $barcodeHelper->setBarcode($barcode);
                    }
                    if (!$barcodeType) {
                        array_push($errorsOnProduct, 'AmazonError110');
                    } elseif (in_array($barcodeType, self::ALLOWED_BARCODE_TYPE)) {
                        if ($barcodeType == 'EAN-8') {
                            $barcodeType = 'EAN';
                        }

                        $feedContent[self::BARCODE_LABEL][] = [
                            'type' => strtolower((string) $barcodeType),
                            'value' => $barcode,
                            'marketplace_id' => $this->marketplaceId
                        ];
                        $jsonFeedData['barcode_type'] = $barcodeType;
                    } else {
                        unset($feedContent[self::BARCODE_LABEL]);
                    }
                } else {
                    unset($feedContent[self::BARCODE_LABEL]);
                }
            } else {
                unset($feedContent[self::BARCODE_LABEL]);
            }

            if (!empty($errorsOnProduct)) {
                return ['error' => $errorsOnProduct];
            }

            if (!empty($categorySettings['attributes_mapping']) && !empty($feedContent)) {
                $this->errorOnProduct = [];
                $valueMapping = $this->product['profile_info']['attributes_mapping']['data']['value_mapping'] ?? null;
                foreach ($categorySettings['attributes_mapping'] as $attr_code => $attr_schema) {
                    if ($attr_code == "_id") {
                        continue;
                    }
                    if ($isOfferProduct) {
                        if (in_array($attr_code, $this->OFFER_PRODUCT_ATTRIBUTES)) {
                            $result = $this->createArrayBasedMappingRecursive($feedContent[$attr_code], $attr_schema, $attr_code, $valueMapping);
                        } else {
                            continue;
                        }
                    } else {

                        $result = $this->createArrayBasedMappingRecursive($feedContent[$attr_code], $attr_schema, $attr_code, $valueMapping);
                    }
                    if (!isset($result[0])) {
                        $feedContent[$attr_code] = [$result];
                    } else {
                        $feedContent[$attr_code] = $result;
                    }

                    $jsonFeedData[$attr_code] = $result[0]['value'] ?? $result;
                }
                if ($this->product['type'] == "variation") {
                    $this->RemoveAttribute = array_diff(array_keys($feedContent), $this->variantParentJsonListingData);
                    // print_r($this->RemoveAttribute);die;
                }

                if (!empty($this->RemoveAttribute) && $this->product['type'] == "variation") {
                    foreach ($this->RemoveAttribute as $toRemove) {
                        $AttributeToRemove = explode('/', (string) $toRemove);
                        unset($feedContent[$AttributeToRemove[0]]);
                        unset($jsonFeedData[$AttributeToRemove[0]]);
                    }
                }
                if ($this->di->getUser()->id == "63624c0e720de16a8c7ebf33" && isset($feedContent['purchasable_offer'][0]['maximum_retail_price'][0]['schedule'][0]['value_with_tax'])) {
                    $chaange  = ($feedContent['purchasable_offer'][0]['maximum_retail_price'][0]['schedule'][0]['value_with_tax'] * 0.20);
                    $feedContent['purchasable_offer'][0]['maximum_retail_price'] = [['schedule' => [['value_with_tax' => (float)$feedContent['purchasable_offer'][0]['maximum_retail_price'][0]['schedule'][0]['value_with_tax'] + $chaange]]]];
                }
                if ($this->di->getUser()->id == "6838a6d33688830cc1049893" && isset($this->product['compare_at_price']) && !empty($this->product['compare_at_price'])) {
                    $feedContent['purchasable_offer'][0]['maximum_seller_allowed_price'] = [['schedule' => [['value_with_tax' => (float)$this->product['compare_at_price']]]]];
                }
                // issue of mrp attributes
                if (isset($feedContent['purchasable_offer'][0]['maximum_retail_price']['schedule'])) {
                    $feedContent['purchasable_offer'][0]['maximum_retail_price'] = [['schedule' => [['value_with_tax' => (float)$feedContent['purchasable_offer'][0]['maximum_retail_price']['schedule']]]]];
                }
            } else {
                return ['error' => ['AmazonError101']];
            }

            if (is_array($this->errorOnProduct) && !empty($this->errorOnProduct)) {
                return ['error' => $this->errorOnProduct];
            }

            // priority will be given as -> edited_variant_jsonFeed >> variants_json_attributes_values >> attributes_mapping
            //     if (isset($this->product['edited']['category_settings']['variants_json_attributes_values']) &&
            //         !empty($this->product['edited']['category_settings']['variants_json_attributes_values'])) {
            //         foreach($this->product['edited']['category_settings']['variants_json_attributes_values'] as $attributeName => $attributeValue) {
            //             if (empty($this->product['edited']['category_settings']['edited_variant_jsonFeed'][$attributeName])) {
            //                 $feedContent[$attributeName] = $attributeValue;
            //                 $jsonFeedData[$attributeName] = $attributeValue[0]['value'] ?? $attributeValue;
            //             }
            //         }
            //     }
            //     else if (isset($categorySettings['variants_json_attributes_values']) &&
            //     !empty($categorySettings['variants_json_attributes_values'])) {
            //     foreach($categorySettings['variants_json_attributes_values'] as $attributeName => $attributeValue) {
            //         if (empty($categorySettings['edited_variant_jsonFeed'][$attributeName])) {
            //             $feedContent[$attributeName] = $attributeValue;
            //             $jsonFeedData[$attributeName] = $attributeValue[0]['value'] ?? $attributeValue;
            //         }
            //     }
            // }

            // normalize item_weight
            if (isset($feedContent['item_weight']['value'])) {
                if ((float)$feedContent['item_weight']['value'] > 0) {
                    $feedContent['item_weight']['value'] = number_format((float) $value, 2);
                } else {
                    $feedContent['item_weight']['value'] = 0;
                }

                $jsonFeedData['item_weight'] = $feedContent['item_weight']['value'];
            }


            if (!empty($feedContent)) {
                $feedContent['feedToSave'] = $jsonFeedData;
                if ($preview) {
                    $feedContent['error'] = $result['errors'];
                    return $feedContent;
                }

                $feedContent['json_product_type'] = $categorySettings['product_type'];
                $feedContent['language_tag'] = $this->languageTag;
                $feedContent['marketplace_id'] = $this->marketplaceId;
                $feedContent['sku'] = $this->product['edited']['sku'] ?? $this->product['sku'] ?? $this->product['source_product_id'];
                unset($this->product);
                unset($this->categorySettings);
                return $feedContent;
            }
            return ['error' => ['AmazonError900']];
        } catch (Exception $e) {
            print_r($e->getMessage());
            return ['error' => ['AmazonError900']];
        }
    }

    public function validateJsonFeed($feed, $productType)
    {
        try {
            $url = $this->di->getConfig()->get('json-validator-api-endpoint') ?? null;
            $categoryHelper = $this->di->getObjectManager()->get(CategoryAttributeCache::class);
            $marketplaceId = $feed['marketplace_id'] ?? '';

            $params['shop_id'] = $this->targetShopId;
            $params['user_id'] = $this->di->getUser()->id;
            $params['marketplace_id'] = $marketplaceId;
            $params['product_type'] = $productType;
            unset($feed['marketplace_id']);
            if (!empty($this->attributesData) && isset($this->attributesData[$productType])) {
                $attributesData = $this->attributesData[$productType];
            } else {
                $attributesData = $categoryHelper->getJsonAttributesFromDB($params, true);
            }

            $jsonHelper = $this->di->getObjectManager()->get(Helper::class);

            if (isset($attributesData['success'], $attributesData['data']['schema'])) {
                $cacheFilePath = $jsonHelper->getFeedFilePath($productType);
                $dirname = dirname((string) $cacheFilePath);
                if (!is_dir($dirname)) mkdir($dirname, 0775, true);

                $dataToWriteInFile = [
                    'feed' => $feed,
                    'schema' => base64_encode(gzcompress(serialize($attributesData['data']['schema'])))
                ];
                file_put_contents($cacheFilePath, json_encode($dataToWriteInFile));
            } else {
                return ['success' => false, 'error' => $attributesData['message'] ?? 'Error fetching attributes data.'];
            }

            if ($url && $cacheFilePath) {
                $headers = [];
                $payload = [
                    'filePath' => $cacheFilePath
                ];

                // $response = $this->di->getObjectManager()->get('App\Core\Components\Guzzle')
                //     ->call($url, $headers, $payload, 'POST', 'json');

                $response = $this->di->getObjectManager()->get('App\Amazon\Components\Validator\Validate')->execute($payload);
                // remove filePath
                unlink($cacheFilePath);
                if (isset($response['success'], $response['errors']) && !empty($response['errors'])) {
                    $validatorErrors = [];
                    foreach ($response['errors'] as $sourceProductId => $errorOnProduct) {
                        $errorArr = [];
                        foreach ($errorOnProduct as $errors) {
                            foreach ($errors as $error) {
                                $errorArr[] = ['AmazonValidationError' => $error];
                                // if(!ctype_digit($label)) {
                                //     $errorArr[] = 'AmazonValidationError : ' . $label . ' ' . $error;
                                // } else {
                                //     $errorArr[] = 'AmazonValidationError : ' . $error;
                                // }
                            }
                        }

                        if (!empty($errorArr)) {
                            $validatorErrors[$sourceProductId] = $errorArr;
                        }
                    }

                    return ['success' => true, 'errors' => $validatorErrors];
                }
                return ['success' => false, 'result' => 'feed is valid'];
            }
            return ['success' => false, 'error' => 'Required parameter(s) missing'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function priceInventoryFeed($type)
    {
        $feedContent = [];
        $attributeNames =  [
            'purchasable_offer',
            'fulfillment_availability'
        ];
        foreach ($attributeNames as $attributeName) {
            $res = $this->getDefaultAttributes($type, $attributeName);
            $feedContent['jsonFeedData'][$attributeName] = $res['feedValue'] ?? "";
            unset($res['feedValue']);
            $feedContent[$attributeName][] = $res;
        }
        return $feedContent;
    }

    public function extractEnumsAndNames($schema, $parentKey = "")
    {
        $results = [];

        // Check if the schema is an array
        if (is_array($schema)) {

            // Check if both "enum" and "enumNames" exist at this level
            if (isset($schema['enum']) && isset($schema['enumNames'])) {
                // print_r($schema);die;
                $enumName = $parentKey;
                $results[$enumName] = [
                    "enum" => $schema['enum'],
                    "enumNames" => $schema['enumNames']
                ];
            }

            // Recursively traverse nested arrays and objects
            foreach ($schema as $key => $value) {
                $nestedKey = $parentKey ? $parentKey . "." . $key : $key;
                $results = array_merge($results, $this->extractEnumsAndNames($value, $nestedKey));
            }
        }

        return $results;
    }


    public function createArrayBasedMappingRecursive(&$final_mapping, $schema, $attributePath, $valueMapping = null)
    {
        $variantValueMapping = null;
        if (!empty($this->categorySettings['variantsValueMapping'])) {
            $variantValueMapping = $this->categorySettings['variantsValueMapping'];
        }

        $attributesData = $this->attributesData[$this->categorySettings['product_type']];
        $ogschema = json_decode((string) $attributesData['data']['schema'], true);
        $flag = false;
        $ogschema = $ogschema['properties'];

        if (isset($schema['attribute_value'])) {
            if ($schema['attribute_value'] == 'recommendation' || $schema['attribute_value'] == 'custom') {
                return $schema[$schema['attribute_value']];
            }
            if ($schema['attribute_value'] == 'shopify_attribute') {
                if(!isset($this->categorySettings['profile'])){

                    if($this->product['type'] !="variation" && isset($this->product['edited']) && is_null($variantValueMapping))
                    {
                    $amazonProduct = $this->product['edited'];
                    $variants_json_attributes_values = $amazonProduct['category_settings']['variants_json_attributes_values'] ?? [];
                    $keys = explode('/', $attributePath);
                    $att = [];

                    foreach ($keys as $key) {
                        if (empty($att) && isset($variants_json_attributes_values[$key])) {
                            $att = $variants_json_attributes_values[$key];
                        }
                        if (isset($att[$key])) {
                            $att = $att[$key];
                        }
                    }
                    if (!empty($att)) {

                        return $att;
                    }

                    // if(!empty($variants_json_attributes_values) && isset($variants_json_attributes_values[$attributePath])){
                        //     return $variants_json_attributes_values[$attributePath];
                        // }
                    }
                    if($this->product['type'] == "variation" && !empty($this->variantJsonAttributeValues)){
                        
                    $keys = explode('/',$attributePath);
                    $att = [];

                    foreach ($keys as $key) {
                        if (empty($att) && isset($this->variantJsonAttributeValues[$key])) {
                            $att = $this->variantJsonAttributeValues[$key];
                        }
                        if (isset($att[$key])) {
                            $att = $att[$key];
                        }
                    }
                    if (!empty($att)) {

                        return $att;
                    }
                }
            }
                if($attributePath){
                    $keys = explode('/',$attributePath);
                    if(!empty($keys)){

                        $rootIndex = $keys[0];
                        if (isset($ogschema[$rootIndex])) {

                            $indexschema = $ogschema[$rootIndex];

                            $enum = $this->extractEnumsAndNames($indexschema);

                            $toUpdate = '';
                            foreach (array_keys($enum) as $trave) {
                                $k = explode('.', $trave);
                                if (in_array($keys[2], $k)) {
                                    $toUpdate = $trave;
                                }
                            }
                            if (isset($enum[$toUpdate]) && isset($enum[$toUpdate]['enum']) && isset($enum[$toUpdate]['enumNames'])) {
                                $enums = $enum[$toUpdate]['enum'];
                                $enumNames = $enum[$toUpdate]['enumNames'];
                                $flag = true;
                            }
                        }
                    }
                }
                if (isset($schema['meta_attribute_exist']) && $schema['meta_attribute_exist']) {
                    if ($attributePath == "country_of_origin/0/value" && $this->di->getUser()->id == "66e3bb73874a559c040cd492") {
                        $enums = $ogschema['country_of_origin']['items']['properties']['value']['enum'];
                        $enumNames = $ogschema['country_of_origin']['items']['properties']['value']['enumNames'];
                        $flag = true;
                    }
                    if ($attributePath == "bottoms_size/0/height_type" && $this->di->getUser()->id == "66e3bb73874a559c040cd492") {
                        $enums = $ogschema['bottoms_size']['items']['properties']['height_type']['enum'];
                        $enumNames = $ogschema['bottoms_size']['items']['properties']['height_type']['enumNames'];
                        $flag = true;
                    }
                    if (isset($this->product['parentMetafield'][$schema['shopify_attribute']])) {
                        if ($flag) {
                            $val = $this->product['parentMetafield'][$schema['shopify_attribute']]['value'];
                            if (in_array($val, $enumNames)) {
                                $res = array_search($val, $enumNames);
                                if (isset($enums[$res])) {
                                    return $enums[$res];
                                }
                            }
                        }
                        return $this->product['parentMetafield'][$schema['shopify_attribute']]['value'];
                    }
                    if (isset($this->product['variantMetafield'][$schema['shopify_attribute']])) {
                        if ($flag) {
                            $val = $this->product['variantMetafield'][$schema['shopify_attribute']]['value'];
                            if (in_array($val, $enumNames)) {
                                $res = array_search($val, $enumNames);
                                if (isset($enums[$res])) {
                                    return $enums[$res];
                                }
                            }
                        }
                        return $this->product['variantMetafield'][$schema['shopify_attribute']]['value'];
                    }
                    if (isset($this->product['parent_details']['parentMetafield'][$schema['shopify_attribute']])) {
                        if ($flag) {
                            $val = $this->product['parent_details']['parentMetafield'][$schema['shopify_attribute']]['value'];
                            if (in_array($val, $enumNames)) {
                                $res = array_search($val, $enumNames);
                                if (isset($enums[$res])) {
                                    return $enums[$res];
                                }
                            }
                        }
                        return $this->product['parent_details']['parentMetafield'][$schema['shopify_attribute']]['value'];
                    }
                } else {
                    if (isset($this->product['edited'][$schema['shopify_attribute']])) {
                        // return attribute from product-edit
                        return $this->product['edited'][$schema['shopify_attribute']];
                    }
                    if (isset($this->product[$schema['shopify_attribute']])) {
                        // return attribute from shopify
                        if (!empty($variantValueMapping[$attributePath]) ) {

                            return $variantValueMapping[$attributePath][$this->product[$schema['shopify_attribute']]] ?? $this->product[$schema['shopify_attribute']];
                        }
                        if (!empty($valueMapping[$attributePath][$schema['shopify_attribute']])) {
                            return $valueMapping[$attributePath][$schema['shopify_attribute']][$this->product[$schema['shopify_attribute']]] ?? $this->product[$schema['shopify_attribute']];
                        }
                        if (isset($schema['global_value_mapping_exists']) && $schema['global_value_mapping_exists']) {
                        $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(CommonHelper::Global_Value_Mapping);
                        $globalValueData = $collection->findOne(
                            ["user_id" => $this->di->getUser()->id, 'target_shop_id' => $this->targetShopId, "_id" => new ObjectId($schema['global_value'])],
                            ['typeMap' => ['root' => 'array', 'document' => 'array']]
                        );
                       
                        if (isset($globalValueData['mappings']) && isset($this->product[$schema['shopify_attribute']]) && !empty($globalValueData['mappings'][$this->product[$schema['shopify_attribute']]])) {
                            return $globalValueData['mappings'][$this->product[$schema['shopify_attribute']]];
                        }
                    }
                        return $this->product[$schema['shopify_attribute']];
                    }
                    if (isset($this->product['parent_details']['variant_attributes'], $this->product['variant_attributes']) && in_array($schema['shopify_attribute'], $this->product['parent_details']['variant_attributes'])) {
                        foreach ($this->product['variant_attributes'] as $variantAttributes) {
                            if (isset($variantAttributes['key'], $variantAttributes['value']) && $variantAttributes['key'] == $schema['shopify_attribute']) {
                                if(!empty($variantValueMapping[$attributePath][$variantAttributes['value']])){
                                    return $variantValueMapping[$attributePath][$variantAttributes['value']] ?? ($variantAttributes['value'] ?? '');

                                }
                                if (!empty($valueMapping[$attributePath][$schema['shopify_attribute']][$variantAttributes['value']])) {
                                    return $valueMapping[$attributePath][$schema['shopify_attribute']][$variantAttributes['value']] ?? ($variantAttributes['value'] ?? '');
                                }
                                if (isset($schema['global_value_mapping_exists']) && $schema['global_value_mapping_exists']) {
                                    $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(CommonHelper::Global_Value_Mapping);
                                    $globalValueData = $collection->findOne(
                                        ["user_id" => $this->di->getUser()->id, 'target_shop_id' => $this->targetShopId, "_id" => new ObjectId($schema['global_value'])],
                                        ['typeMap' => ['root' => 'array', 'document' => 'array']]
                                    );
                                       
                                    if (isset($globalValueData['mappings']) && isset($variantAttributes['value']) && !empty($globalValueData['mappings'][$variantAttributes['value']])) {
                                        return $globalValueData['mappings'][$variantAttributes['value']];
                                    }
                                }

                                return $variantAttributes['value'];
                            }
                        }
                    } elseif (
                        isset($this->product['type'], $this->product['variant_attributes'], $this->product['variant_attributes_values'])
                        && $this->product['type'] == 'variation' && in_array($schema['shopify_attribute'], $this->product['variant_attributes'])
                    ) {
                        //  in case of variant-attribute mapping - map first child's variant-attribute in parent
                        foreach ($this->product['variant_attributes_values'] as $variantAttributes) {
                            if (isset($variantAttributes['key']) && $variantAttributes['key'] == $schema['shopify_attribute']) {
                                if (!empty($variantValueMapping[$attributePath])) {
                                    
                                    if (is_array($variantAttributes['value'])) {
                                        // $values = [];
                                        $values = null;
                                        foreach($variantAttributes['value'] as $k => $v) {
                                            // $values[] = $valueMapping[$attributePath][$schema['shopify_attribute']][$v] ?? $v;
                                            
                                            $values = $variantValueMapping[$attributePath][$v] ?? $v;
                                        }
                                        // print_r($values);die;
                                        return $values;
                                    }
                                   
                                    return $variantValueMapping[$attributePath][$variantAttributes['value']] ?? ($variantValueMapping[$attributePath][$variantAttributes['value'][0]] ?? $variantAttributes['value'][0]??"");
                                }
                                if (!empty($valueMapping[$attributePath][$schema['shopify_attribute']])) {
                                    if (is_array($variantAttributes['value'])) {
                                        // $values = [];
                                        $values = null;
                                        foreach ($variantAttributes['value'] as $k => $v) {
                                            // $values[] = $valueMapping[$attributePath][$schema['shopify_attribute']][$v] ?? $v;
                                            $values = $valueMapping[$attributePath][$schema['shopify_attribute']][$v] ?? $v;
                                        }
                                        return $values;
                                    }
                                    return $valueMapping[$attributePath][$schema['shopify_attribute']][$variantAttributes['value']] ?? ($valueMapping[$attributePath][$schema['shopify_attribute']][$variantAttributes['value'][0]] ?? $variantAttributes['value'][0] ?? "");
                                }
                               

                                return $variantAttributes['value'][0] ?? '';
                            }
                        }
                    } else {
                        if (!isset($this->product[$schema['shopify_attribute']]) && $this->product['type'] == "variation") {
                            $this->RemoveAttribute[] = $attributePath;
                        }

                        return '';
                    }
                }
            }
        } elseif (is_string($schema)) {
            return $schema;
        } elseif (is_array($schema)) {

            foreach ($schema as $key => $value) {
                $final_mapping[$key]  = $this->createArrayBasedMappingRecursive($final_mapping[$key], $value, $attributePath . '/' . $key, $valueMapping);

                if ($final_mapping[$key] !== null && isset($value['type']) && !empty($value['type'])) {
                    $mappedAttribute = $final_mapping[$key];
                    switch ($value['type']) {
                        case 'string':
                            if (is_numeric($mappedAttribute)) {
                                $mappedAttribute = (string)$mappedAttribute;
                            } elseif (is_array($mappedAttribute)) {
                                $mappedAttribute = implode(' ', $mappedAttribute);
                            } elseif (is_bool($mappedAttribute)) {
                                $mappedAttribute = (string)$mappedAttribute;
                            }

                            break;
                        case 'number':
                            if (is_numeric($mappedAttribute)) {
                                $mappedAttribute = floatval($mappedAttribute);
                            } else {
                                // array_push($this->errorOnProduct, 'AmazonValidationError : Invalid value provided for ' . $attributePath . '. Value must be of type number.');
                            }

                            break;
                        case 'integer':
                            if (is_numeric($mappedAttribute)) {
                                $mappedAttribute = (int)$mappedAttribute;
                            } else {
                                // array_push($this->errorOnProduct, 'AmazonValidationError : Invalid value provided for ' . $attributePath . '. Value must be of type integer.');
                            }

                            break;
                        case 'boolean':
                            $mappedAttribute = (bool)$mappedAttribute;
                            break;
                        case 'array':
                            $mappedAttribute = (array)$mappedAttribute;
                            break;
                    }

                    $final_mapping[$key] = $mappedAttribute;
                }

                if ($this->languageTag === null && $key === self::LANGUAGE_TAG) {
                    $this->languageTag = $final_mapping[$key];
                } elseif ($this->marketplaceId === null && $key === self::MARKETPLACE_ID_TAG) {
                    $this->marketplaceId = $final_mapping[$key];
                }
            }

            return $final_mapping;
        }

        return $final_mapping;
    }


    /** map default attributes in json array-NEW_LISTING_DEFAULT_ATTRIBUTES */
    public function getDefaultAttributes($type, $attributeLabel)
    {
        $returnData = [];
        $feedValue = "";
        $customUser = ['671e05d23b6bc864a8050f34'];
        $formulaValueUpdated = false;
        $titlemergeEnabled = true;
        $profileSettings = [];
        $profileInfo = $this->product['profile_info'] ?? [];

        switch ($attributeLabel) {
            case 'item_name':
                $value = '';
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $configCollection = $mongo->getCollection('config');
                $priceHelper = $this->di->getObjectManager()->get(Price::class);

                $query = [
                    'user_id' => $this->di->getUser()->id,
                    'group_code' => 'product',
                    'key' => 'variant_title',
                    'target_shop_id' => $this->targetShopId
                ];

                $configData = $configCollection->findOne($query);
                $configData = json_decode(json_encode($configData), true);
                if ($type == self::PRODUCT_TYPE_CHILD) {
                    if (isset($this->categorySettings['profile_Settings'])) {
                        $profileSettings = (array)$this->categorySettings['profile_Settings'];
                        foreach ($profileSettings as $profileSetting) {
                            if (isset($profileSetting['data_type']) && $profileSetting['data_type'] == "product_settings") {

                                if (isset($profileSetting['data']['variant_title'])) {
                                    $titlemergeEnabled = $profileSetting['data']['variant_title'];
                                }
                            }
                        }
                    } elseif (isset($configData['value'])) {

                        $titlemergeEnabled = $configData['value'];
                    }
                    if(!empty($this->di->getConfig()->get('metafield_sync_user_feature')) && (!empty($this->product['variantMetafield'])))
                    {
                        $metafieldSyncConfig = $this->di->getConfig()->get('metafield_sync_user_feature')->toArray();
                        $metafieldType = 'variantMetafield';
                        if( isset($metafieldSyncConfig[$this->di->getUser()->id][$metafieldType]['variant_title']) && $priceHelper->isMetafieldSyncEnable($this->di->getUser()->id, $this->product['shop_id'])) {
                            $syncKey = $metafieldSyncConfig[$this->di->getUser()->id][$metafieldType]['variant_title'] ?? null;
                            if (isset($this->product['edited']['title'])) {
                                $variantTitle = $this->product['edited']['title'];
                            } elseif (isset($this->product['variantMetafield'][$syncKey]['value'])) {
                                $variantTitle = $this->product['variantMetafield'][$syncKey]['value'];
                            } else {
                                $variantTitle = $this->product['variant_title'] ?? '';
                            }
                        } else {
                            $variantTitle = $this->product['edited']['title'] ?? $this->product['variant_title'] ?? '';
                        }

                    }
                    else 
                    {
                        $variantTitle = $this->product['edited']['title'] ?? $this->product['variant_title'] ?? '';
                        
                    }

                    if (
                        !empty($this->di->getConfig()->get('metafield_sync_user_feature')) && (!empty($this->product['parent_details']['parentMetafield'])) ||
                        (!empty($this->product['parentMetafield']))
                    ) {
                        $metafieldSyncConfig = $this->di->getConfig()->get('metafield_sync_user_feature')->toArray();
                        $parentDetails = isset($this->product['parent_details']['parentMetafield']) ? true : false;

                        $metafieldType = 'parentMetafield';
                        if (isset($metafieldSyncConfig[$this->di->getUser()->id][$metafieldType]['title']) && $priceHelper->isMetafieldSyncEnable($this->di->getUser()->id, $this->product['shop_id'])) {
                            $syncKey = $metafieldSyncConfig[$this->di->getUser()->id][$metafieldType]['title'] ?? null;
                            if (isset($this->product['parent_details']['edited']['title'])) {
                                $parentTitle = $this->product['parent_details']['edited']['title'];
                            } elseif (isset($this->product['parent_details'][$metafieldType][$syncKey]['value']) && $parentDetails) {
                                $parentTitle = $this->product['parent_details'][$metafieldType][$syncKey]['value'];
                            } elseif (isset($this->product['parentMetafield'][$syncKey]['value']) && !$parentDetails) {
                                $parentTitle = $this->product['parentMetafield'][$syncKey]['value'];
                            } else {
                                $parentTitle =  $this->product['parent_details']['title'] ?? '';
                            }
                            // $parentTitle = isset($this->product['parent_details'][$metafieldType][$syncKey]['value']) && $parentDetails ? $this->product['parent_details'][$metafieldType][$syncKey]['value']  ;                
                        } else {
                            $parentTitle = $this->product['parent_details']['edited']['title'] ?? $this->product['parent_details']['title'] ?? '';
                        }
                    }

                    // if (in_array($this->di->getUser()->id, $customUser)) {

                    //     if (!empty($this->product['parentMetafield']) && $this->product['parentMetafield']['custom->amazon_title'] && isset($this->product['parentMetafield']['custom->amazon_title']['value'])) {
                    //         $parentTitle = $this->product['parentMetafield']['custom->amazon_title']['value'];
                    //     } elseif (!empty($this->product['parent_details']['parentMetafield']) && $this->product['parent_details']['parentMetafield']['custom->amazon_title'] && isset($this->product['parent_details']['parentMetafield']['custom->amazon_title']['value'])) {
                    //         $parentTitle = $this->product['parent_details']['parentMetafield']['custom->amazon_title']['value'];
                    //     } else {
                    //         $parentTitle = $this->product['parent_details']['edited']['title'] ?? $this->product['parent_details']['title'] ?? '';
                    //     }
                    // }
                    else {
                        $parentTitle = $this->product['parent_details']['edited']['title'] ?? $this->product['parent_details']['title'] ?? '';
                    }
                    // $parentTitle = $this->product['parent_details']['edited']['title'] ?? $this->product['parent_details']['title'] ?? '';
                    if($this->di->getUser()->id == "6822b7a42a5b04a15e0a6dc2" && isset($this->product['variantMetafield']['custom->amazon_variant_title']['value']))
                    {
                        $variantTitle = $this->product['variantMetafield']['custom->amazon_variant_title']['value'].' ' . $variantTitle;
                    }

                    if ($titlemergeEnabled) {
                        $value = $parentTitle . ' ' . $variantTitle;
                    } else {
                        $value = $variantTitle;
                    }
                } else {
                    if (!empty($this->di->getConfig()->get('metafield_sync_user_feature')) && (!empty($this->product['parentMetafield']))) {
                        $metafieldSyncConfig = $this->di->getConfig()->get('metafield_sync_user_feature')->toArray();
                        // $parentDetails = isset($this->product['parentMetafield']) ? true:false;
                        $metafieldType = 'parentMetafield';
                        if (isset($metafieldSyncConfig[$this->di->getUser()->id][$metafieldType]['title']) && $priceHelper->isMetafieldSyncEnable($this->di->getUser()->id, $this->product['shop_id'])) {
                            $syncKey = $metafieldSyncConfig[$this->di->getUser()->id][$metafieldType]['title'] ?? null;
                            if (isset($this->product['edited']['title'])) {
                                $value = $this->product['edited']['title'];
                            } elseif (isset($this->product['parentMetafield'][$syncKey]['value'])) {
                                $value = $this->product['parentMetafield'][$syncKey]['value'];
                            } else {
                                $value = $this->product['title'] ?? '';
                            }
                        } else {
                            $value = $this->product['edited']['title'] ?? $this->product['title'] ?? '';
                        }
                    } else {
                        $value = $this->product['edited']['title'] ?? $this->product['title'] ?? '';
                    }

                    if($this->di->getUser()->id == "6822b7a42a5b04a15e0a6dc2" && $this->product['type'] == "simple" && $this->product['visibility'] == "Catalog and Search" && isset($this->product['parentMetafield']['custom->amazon_title']['value'])){
                        $value = $this->product['parentMetafield']['custom->amazon_title']['value'];;
                    }
                    // $value = $this->product['edited']['title'] ?? $this->product['title'] ?? '';
                }
                $query = [
                    'user_id' => $this->di->getUser()->id,
                    'group_code' => 'product',
                    'key' => 'formula',
                    'target_shop_id' => $this->targetShopId
                ];

                $configData = $configCollection->findOne($query);
                if (isset($profileInfo['data'])) {

                    foreach ($profileInfo['data'] as $profileSettingData) {
                        if (isset($profileSettingData['data_type']) && $profileSettingData['data_type'] == "formula_settings") {
                            $formulas = $profileSettingData['data']['item_name'];
                            $product = $this->product;
                            $product['title'] = $value;
                            $result = $this->formatProductTitle($formulas, $product);

                            $value = $result;
                            $formulaValueUpdated = true;
                        }
                    }
                }
                if (isset($configData['value']) && !$formulaValueUpdated) {
                    $formulas = $configData['value']['title'] ?? "";
                    if (!empty($formulas)) {

                        $product = $this->product;
                        $product['title'] = $value;
                        $result = $this->formatProductTitle($formulas, $product);
                        $value = $result;
                    }
                }

                if (strlen((string) $value) > self::MAXIMUM_ITEM_NAME_LENGTH) {
                    return ['error' => ['AmazonError305']];
                }

                $returnData =  [
                    'value' => $value,
                    self::MARKETPLACE_ID_TAG => $this->marketplaceId,
                    self::LANGUAGE_TAG => $this->languageTag
                ];
                $feedValue = $value;
                break;
            case 'merchant_suggested_asin':
                if (isset($this->product['edited']['asin']) && !empty($this->product['edited']['asin'])) {
                    $length = strlen($this->product['edited']['asin']);
                    if ($length == 10 && !ctype_digit($this->product['edited']['asin'])) {   // validating Asin
                        $returnData = [
                            'value' => $this->product['edited']['asin'],
                            self::MARKETPLACE_ID_TAG => $this->marketplaceId,
                        ];
                        $feedValue = $returnData['value'];
                    }
                }

                break;
            case 'supplier_declared_has_product_identifier_exemption':
                //map supplier_declared_has_product_identifier_exemption
                if (isset($this->categorySettings['barcode_exemption']) && $this->categorySettings['barcode_exemption']) {
                    $returnData = ['value' => true];
                } elseif (isset($this->product['parent_details']['edited']['category_settings']['barcode_exemption']) && $this->product['parent_details']['edited']['category_settings']['barcode_exemption']) {
                    $returnData = ['value' => true];
                } else {
                    $returnData = ['value' => false];
                }

                $returnData[self::MARKETPLACE_ID_TAG] = $this->marketplaceId;
                $feedValue = $returnData['value'];
                break;
            case 'recommended_browse_nodes':
                // map recommended_browse_nodes
                // skip HANDBAG product_type for US country
                // if ($this->categorySettings['product_type'] == 'HANDBAG' || $this->marketplaceId == 'ATVPDKIKX0DER') return null;
                // $browseNodeId = $this->categorySettings['browser_node_id'] ?? null;
                if (isset($this->categorySettings['browser_node_id'])) {
                    $browseNodeId = $this->categorySettings['browser_node_id'];
                } else if (isset($this->product['parent_details']['edited']['category_settings']['browser_node_id'])) {
                    $browseNodeId = $this->product['parent_details']['edited']['category_settings']['browser_node_id'];
                } else {
                    $browseNodeId = null;
                }
                if ($browseNodeId) {
                    $returnData = [
                        'value' => $browseNodeId,
                        self::MARKETPLACE_ID_TAG => $this->marketplaceId,
                    ];
                    $feedValue = $returnData['value'];
                }

                break;
            case 'purchasable_offer':
                if ($type == self::PRODUCT_TYPE_PARENT) break;

                $currency = $this->getCurrency($this->product['user_id'], $this->targetShopId);
                // map price-details
                $priceHelper = $this->di->getObjectManager()->get(Price::class);
                $priceList = $priceHelper->getProductPriceDetails($this->product, $this->targetShopId, 'amazon', null, 1);
                if (isset($priceList['StandardPrice']) && $priceList['StandardPrice'] > 0) {
                    $returnData['our_price'] = [['schedule' => [['value_with_tax' => (float)$priceList['StandardPrice']]]]];
                    $returnData['audience'] = "ALL";
                } else {
                    $returnData['error'][] = 'AmazonError106';
                }

                if (isset($priceList['BusinessPrice']) && $priceList['BusinessPrice'] > 0) {
                    $returnData['business_price']['audience'] = "B2B";
                    $returnData['business_price']['our_price'] = [['schedule' => [['value_with_tax' => (float)$priceList['BusinessPrice']]]]];
                    $returnData['business_price']['currency'] = $currency;
                    $returnData['business_price'][self::MARKETPLACE_ID_TAG] = $this->marketplaceId;
                    if (isset($priceList['quantityPriceType']) && isset($priceList['quantity'])) {
                        $returnData['business_price']['quantity_discount_plan'] = [['schedule' => [['levels' => $priceList['quantity'], 'discount_type' => $priceList['quantityPriceType']]]]];
                    }
                }

                if ($this->marketplaceId == "A21TJRUUN4KGV" && $this->di->getUser()->id != "63624c0e720de16a8c7ebf33") {
                    $returnData['maximum_retail_price'] = [['schedule' => [['value_with_tax' => (float)$priceList['StandardPrice']]]]];
                }

                if (isset($priceList['SalePrice'], $priceList['StartDate'], $priceList['EndDate']) && $priceList['SalePrice'] > 0) {
                    if ($priceList['SalePrice'] <= $priceList['StandardPrice']) {
                        $returnData['discounted_price'] = [['schedule' => [[
                            'value_with_tax' => (float)$priceList['SalePrice'],
                            'start_at' => $priceList['StartDate'],
                            'end_at' => $priceList['EndDate']
                        ]]]];
                    } else {
                        $returnData['error'][] = 'AmazonError303';
                    }
                }

                if (!empty($priceList['MinimumPrice']) && $priceList['MinimumPrice'] > 0) {
                    if ($priceList['MinimumPrice'] <= $priceList['StandardPrice']) {
                        $returnData['minimum_seller_allowed_price'] = [['schedule' => [['value_with_tax' => (float)$priceList['MinimumPrice']]]]];
                    } else {
                        $returnData['error'][] = 'AmazonError304';
                    }
                }

                // map currency
                $returnData['currency'] = $currency;
                $returnData[self::MARKETPLACE_ID_TAG] = $this->marketplaceId;
                $feedValue = $priceList['StandardPrice'];
                break;
            case 'product_description':
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $priceHelper = $this->di->getObjectManager()->get(Price::class);
                $configCollection = $mongo->getCollection('config');
                $query = [
                    'user_id' => $this->di->getUser()->id,
                    'group_code' => 'product',
                    'key' => 'trim_description',
                    'target_shop_id' => $this->targetShopId
                ];
                if (isset($this->product['edited']['description'])) {
                    $description = $this->product['edited']['description'] ?? '';
                } elseif ($type == self::PRODUCT_TYPE_CHILD && isset($this->product['parent_details']['edited']['description'])) {
                    $description = $this->product['parent_details']['edited']['description'] ?? '';
                } elseif (!empty($this->di->getConfig()->get('metafield_sync_user_feature')) && (!empty($this->product['parentMetafield']) || !empty($this->product['variantMetafield']))) {
                    $metafieldSyncConfig = $this->di->getConfig()->get('metafield_sync_user_feature')->toArray();
                    $metafieldType = isset($this->product['variantMetafield']) ? 'variantMetafield' : 'parentMetafield';

                    if (isset($metafieldSyncConfig[$this->di->getUser()->id][$metafieldType]['description']) && $priceHelper->isMetafieldSyncEnable($this->di->getUser()->id, $this->product['shop_id'])) {

                        $syncKey = $metafieldSyncConfig[$this->di->getUser()->id][$metafieldType]['description'] ?? null;
                        $description =  $this->product[$metafieldType][$syncKey]['value'] ?? $this->product['description'];
                    } else {
                        $description = $this->product['description'] ?? '';
                    }
                } elseif (!empty($this->di->getConfig()->get('metafield_sync_user_feature')) && (!empty($this->product['parent_details']['parentMetafield']))) {
                    $metafieldSyncConfig = $this->di->getConfig()->get('metafield_sync_user_feature')->toArray();
                    $metafieldType = 'parentMetafield';

                    if (isset($metafieldSyncConfig[$this->di->getUser()->id][$metafieldType]['description']) && $priceHelper->isMetafieldSyncEnable($this->di->getUser()->id, $this->product['shop_id'])) {

                        $syncKey = $metafieldSyncConfig[$this->di->getUser()->id][$metafieldType]['description'] ?? null;
                        $description =  $this->product['parent_details'][$metafieldType][$syncKey]['value'] ?? $this->product['description'];
                    } else {
                        $description = $this->product['description'] ?? '';
                    }
                } else {
                    $description = $this->product['description'] ?? '';
                }
                $configData = $configCollection->findOne($query);
                if ((isset($configData['value']) && $configData['value'] && strlen($description) > 2000) || (is_null($configData) && strlen($description) > 2000)) {
                    $returnData = [
                        'value' => substr((string) $description, 0, 2000),
                        self::MARKETPLACE_ID_TAG => $this->marketplaceId,
                        self::LANGUAGE_TAG => $this->languageTag,
                    ];
                } else {
                    $returnData = [
                        'value' => trim((string) $description),
                        self::MARKETPLACE_ID_TAG => $this->marketplaceId,
                        self::LANGUAGE_TAG => $this->languageTag
                    ];
                }
                $feedValue = $returnData['value'];
                break;
            case 'fulfillment_availability':
                if ($type == self::PRODUCT_TYPE_PARENT) break;

                // map fulfillment-channel
                $profileInfo = $this->product['profile_info']['data'] ?? [];
                $profileFulfillmentSettings = [];

                foreach ($profileInfo as $item) {
                    if (isset($item['data_type']) && $item['data_type'] === 'fulfillment_settings') {
                        $profileFulfillmentSettings = $item;
                        break;
                    }
                }

                $fulfillmentType = $this->product['edited']['fulfillment_type'] ?? $this->product['parent_details']['edited']['fulfillment_type'] ?? $profileFulfillmentSettings['data']['fulfillment_type'] ?? null;

                if ($fulfillmentType == "fba_then_fbm") {
                    $fbaData = [];
                    $fbmData = [];

                    $attributesData = $this->attributesData[$this->categorySettings['product_type']];
                    if (!empty($attributesData['data']['schema'])) {
                        $schema = json_decode($attributesData['data']['schema'], true);
                        $enums = $schema['properties']['fulfillment_availability']['items']['properties']['fulfillment_channel_code']['enum'] ?? [];
                        $searchString = "AMAZON";
                        foreach ($enums as $element) {
                            if (strpos($element, $searchString) === 0) {
                                $fbaData['fulfillment_channel_code'] = $element;
                                break;
                            }
                        }
                        if (empty($fbaData)) {
                            $returnData['error'][] = 'AmazonError309';
                        }
                    }

                    $fbmData['fulfillment_channel_code'] = self::OFFER_SUB_CATEGORY;
                    $inventoryHelper = $this->di->getObjectManager()->get(Inventory::class);
                    $inventoryTemplate = $inventoryHelper->init()->prepare($this->product);
                    $inventoryList = $inventoryHelper->init()->calculate($this->product, $inventoryTemplate, $this->sourceMarketplace);
                    $fbmData['quantity'] = isset($inventoryList['Quantity']) && $inventoryList['Quantity'] > 0
                        ? (int)$inventoryList['Quantity'] : 0;

                    if (isset($inventoryList['Latency'])) {
                        $fbmData['lead_time_to_ship_max_days'] = (int)$inventoryList['Latency'];
                    }

                    $returnData['fulfillment_availability'] = [$fbaData, $fbmData];
                } elseif ($fulfillmentType == "FBA") {
                    $attributesData = $this->attributesData[$this->categorySettings['product_type']];
                    if (!empty($attributesData['data']['schema'])) {
                        //this check is added to skip parentage_level and child_parent_sku_relationship keys if variation_theme key not available in the schema
                        $schema = json_decode($attributesData['data']['schema'], true);
                        $enums = $schema['properties']['fulfillment_availability']['items']['properties']['fulfillment_channel_code']['enum'] ?? [];
                        $searchString = "AMAZON";
                        $index = -1;
                        foreach ($enums as $key => $element) {
                            if (strpos($element, $searchString) === 0) {
                                $index = $key;
                                break;
                            }
                        }
                        if ($index != -1 && isset($enums[$index])) {
                            $returnData['fulfillment_channel_code'] = $enums[$index];
                        } else {
                            $returnData['error'][] = 'AmazonError309';
                        }
                    }
                } else {
                    $returnData['fulfillment_channel_code'] = self::OFFER_SUB_CATEGORY;
                    $inventoryHelper = $this->di->getObjectManager()->get(Inventory::class);
                    $inventoryTemplate = $inventoryHelper->init()->prepare($this->product);
                    $inventoryList = $inventoryHelper->init()->calculate($this->product, $inventoryTemplate, $this->sourceMarketplace);
                    if (isset($inventoryList['Quantity']) && $inventoryList['Quantity'] && $inventoryList['Quantity'] > 0) {
                        $returnData['quantity'] = (int)$inventoryList['Quantity'];
                    } else {
                        $returnData['quantity'] = 0;
                    }

                    // map lead_time_to_ship_max_days
                    if (isset($inventoryList['Latency'])) {
                        $returnData['lead_time_to_ship_max_days'] = (int)$inventoryList['Latency'];
                    }

                    $feedValue = $returnData['quantity'] ?? 0;
                }

                break;
            case 'parentage_level':
                if ($type == self::PRODUCT_TYPE_PARENT || $type == self::PRODUCT_TYPE_CHILD) {
                    $returnData =  [
                        'value' => $type,
                        self::MARKETPLACE_ID_TAG => $this->marketplaceId
                    ];
                    $feedValue = $type;
                }

                break;
            case 'child_parent_sku_relationship':
                if ($type) {
                    $returnData = [
                        'child_relationship_type' => 'variation',
                        self::MARKETPLACE_ID_TAG => $this->marketplaceId
                    ];
                    if ($type == self::PRODUCT_TYPE_CHILD) {
                        if (isset($this->product['parent_details']['edited']['parent_sku']) && !empty($this->product['parent_details']['edited']['parent_sku'])) {
                            $parentSku = $this->product['parent_details']['edited']['parent_sku'];
                        } elseif (isset($this->product['parent_details']['edited']['sku']) && !empty($this->product['parent_details']['edited']['sku'])) {
                            $parentSku = $this->product['parent_details']['edited']['sku'];
                        } else {
                            $parentSku = $this->product['parent_details']['sku'] ?? $this->product['parent_details']['source_product_id'];
                        }

                        $returnData['parent_sku'] = $parentSku;
                        $feedValue = $returnData['parent_sku'];
                    }
                }

                break;
            // case 'skip_offer':
            //     $returnData = [
            //         'value' => false,
            //         self::MARKETPLACE_ID_TAG => $this->marketplaceId
            //     ];
            //     $feedValue = $returnData['value'];
            //     break;
            case 'item_package_weight':
                $value = '';
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $configCollection = $mongo->getCollection('config');
                $query = [
                    'user_id' => $this->di->getUser()->id,
                    'group_code' => 'product',
                    'key' => 'formula',
                    'target_shop_id' => $this->targetShopId
                ];

                $configData = $configCollection->findOne($query);
                $configData = json_decode(json_encode($configData), true);
                $unitsMapping = ['GRAMS' => 'grams', 'POUNDS' => "pounds", "OUNCES" => "ounces", "KILOGRAMS" => 'kilograms'];

                $unit = $unitsMapping[$this->product['weight_unit']];
                if (isset($profileInfo['data'])) {

                    foreach ($profileInfo['data'] as $profileSettingData) {
                        if (isset($profileSettingData['data_type']) && $profileSettingData['data_type'] == "formula_settings" && isset($profileSettingData['data']['package_weight'])) {
                            $formulas = $profileSettingData['data']['package_weight'];
                            $product = $this->product;
                            $result = $this->calculateAttributeFromFormula($formulas, $product);


                            $value = $result;
                            $formulaValueUpdated = true;
                        }
                    }
                }
                if (isset($configData['value']) && !$formulaValueUpdated) {
                    $formulas = $configData['value']['package_weight'] ?? "";

                    if (!empty($formulas)) {

                        $product = $this->product;
                        $result = $this->calculateAttributeFromFormula($formulas, $product);
                        $value = $result;
                        $feedValue = $value . $unit;
                    }
                }
                $returnData =  [
                    'value' => $value,
                    'unit' => $unit,
                ];

                break;
            default:
                break;
        }

        $returnData['feedValue'] = $feedValue;
        return $returnData;
    }

    public function reactivateListing($userId, $listingData, $sourceShopId, $targetShopId)
    { 
        if(isset($listingData['seller-sku']) && !empty($listingData['seller-sku'])){
            $userShops = $this->di->getUser()->shops ?? [];
                if (!empty($userShops)) {
                    $targetShop = array_filter($userShops, function (array $shop) use ($targetShopId) {
                        if ($shop['_id'] == $targetShopId) {
                            return $shop;
                        }
                    });
                } else {
                    $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                    $targetShop = $user_details->getShop($targetShopId, $userId);
                }

                if (is_array($targetShop)) {
                    foreach ($targetShop as $shop) {
                        if (isset($shop['remote_shop_id'])) {
                            $remoteShopId = $shop['remote_shop_id'];
                        }
                    }
                }

                $sku = $listingData['seller-sku'];
                $result = [];
                $sucess = 0;
                $failed = 0;
                $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(CommonHelper::AMAZON_LISTING);
                 $rawBody['sku'] = rawurlencode($sku);
                        $rawBody['shop_id'] = $remoteShopId;
                        $rawBody['includedData'] = "issues,attributes,summaries,fulfillmentAvailability";
                        $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);
                        $response = $commonHelper->sendRequestToAmazon('listing', $rawBody, 'GET');
                        if (!empty($response['success']) && $response['success']) {
                            $attributes = $response['response']['attributes'] ?? "";
                            $purchasableOffer = $attributes['purchasable_offer'] ?? "";
                            if(!empty($purchasableOffer)){

                                foreach ($purchasableOffer as $key => $audience) {
                                    if ($audience['audience']  == "ALL") {
                                    // $purchasableOffer[$key]['start_at']['value'] = date('c');
                                    $toUpdatePrice[$key]['audience'] =$audience['audience'];
                                    $toUpdatePrice[$key]['currency'] = $audience['currency'];
                                    $currentTimeStamp = date('c');
                                    $toUpdatePrice[$key]['start_at']['value'] = $currentTimeStamp;
                                    
                                    $toUpdatePrice[$key]['end_at']['value'] =  date('c', strtotime('+365 days'));
                                    $toUpdatePrice[$key]['marketplace_id'] = $audience['marketplace_id'];
                                    $toUpdatePrice[$key]['our_price'] = $audience['our_price'];
                                    
                                    // $purchasableOffer[$key]['end_at']['value'] = date('c');
                                }
                            }
                            // print_r($toUpdatePrice);die;
                            $payload['shop_id'] = $remoteShopId;
                            $payload['product_type'] = 'PRODUCT';
                            $payload['patches'] = [
                                [
                                    "op" => "replace",
                                    "operation_type" => "PARTIAL_UPDATE",
                                    "path" => "/attributes/purchasable_offer",
                                    "value" => $toUpdatePrice
                                ]
                            ];
                            $payload['sku'] = rawurlencode($sku);
                            $res = $commonHelper->sendRequestToAmazon('listing-update', $payload, 'POST');
                            
                             if (isset($res['success']) && $res['success'] && isset($res['response']['status']) && $res['response']['status'] == "ACCEPTED") 
                                {
                                $result[$sku] = $res['response'];
                                $sucess ++;
                                } 
                                else 
                                {
                                    $result[$sku] = $res['response']['issues'];
                                $error['reactivate'] = $res['response']['issues'];
                                $failed ++;
                                }
                            } else 
                            {
                                $error['reactivate'] = 'SKU not found';
                                $failed ++;
                            }
                        }
        }

    }



    public function calculateAttributeFromFormula(string $formula, array $product): float|int
    {
        // 1. Find all unique placeholders (e.g., {{weight}}) in the formula.
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $formula, $matches);
        $placeholders = array_unique($matches[1] ?? []);

        // If there are no placeholders, the formula should just be a number.
        if (empty($placeholders)) {
            if (is_numeric($formula)) {
                return $formula;
            }
            throw new Exception("The formula must contain at least one placeholder or be a numeric value.");
        }

        $expression = $formula;

        // 2. Replace each placeholder with its value from the $product array.
        foreach ($placeholders as $placeholder) {
            // Check if the placeholder exists as a key in the product data.
            if (!array_key_exists($placeholder, $product)) {
                throw new Exception("Placeholder '{$placeholder}' not found in product data.");
            }

            $value = $product[$placeholder];

            // Ensure the value is numeric before substitution.
            if (!is_numeric($value)) {
                throw new Exception("Value for placeholder '{$placeholder}' is not numeric.");
            }

            // Replace all occurrences of the placeholder with its value.
            $expression = str_replace("{{{$placeholder}}}", $value, $expression);
        }

        // 3. Safely evaluate the resulting mathematical expression.
        // WARNING: Using eval() is inherently risky. We must rigorously sanitize the expression
        // to prevent arbitrary code execution. This regex allows only numbers, arithmetic operators,
        // comparison operators, the ternary operator, parentheses, and whitespace.
        $safe_pattern = '/^[\s\d\.\+\-\*\/\(\)\?\:\<\>\=\!]+$/';
        if (!preg_match($safe_pattern, $expression)) {
            throw new Exception("Invalid or unsafe characters found in the formula expression: '{$expression}'.");
        }

        // Suppress errors from eval (e.g., division by zero) and handle them manually.
        $result = @eval("return {$expression};");

        if ($result === false) {
            $error = error_get_last();
            throw new Exception("Failed to evaluate the expression '{$expression}'. Error: " . ($error['message'] ?? 'syntax error'));
        }

        return $result;
    }
    public function formatProductTitle(string $formula, array $product): string
    {
        // Use preg_replace_callback to find all {{...}} placeholders and apply a custom replacement logic.
        // The regex '/\{\{(.*?)\}\}/' matches any string enclosed in double curly braces.
        // $matches[0] will be the full matched string (e.g., '{{variant_attribute.color}}')
        // $matches[1] will be the content inside the braces (e.g., 'variant_attribute.color')
        return preg_replace_callback('/\{\{(.*?)\}\}/', function ($matches) use ($product) {
            $path = $matches[1]; // Get the variable path from inside the braces (e.g., 'variant_attribute.color')

            // Split the path into individual segments using the dot '.' as a delimiter.
            // For 'variant_attribute.color', $parts will be ['variant_attribute', 'color'].
            $parts = explode('.', $path);

            // Initialize the current value with the entire product array.
            $currentValue = $product;

            // Traverse the product array based on the segments in the path.
            foreach ($parts as $part) {
                // Check if the current value is an array and if the current part exists as a key in it.
                if (is_array($currentValue) && array_key_exists($part, $currentValue)) {
                    $currentValue = $currentValue[$part]; // Move to the next level in the array.
                } else {
                    // If any part of the path is not found, or if an intermediate value is not an array,
                    // return an empty string as requested.
                    return '';
                }
            }

            // After successfully traversing the path, check the type of the final found value.
            if (is_array($currentValue) || is_object($currentValue)) {
                // If the final value is an array or an object, convert it to a JSON string.
                // This is a sensible default for displaying complex data in a string context.
                // If an empty string is desired for arrays/objects, change this return.
                return json_encode($currentValue);
            }

            // For scalar values (strings, numbers, booleans, null), cast them to string.
            return (string) $currentValue;
        }, $formula);
    }

    /** upload images in aws and return bucket url */
    public function getImagesUrl($type)
    {
        $returnData = [];
        $mainImage = null;
        $additionalImages = [];
       
        if (isset($this->product['edited']['main_image']) && !empty($this->product['edited']['main_image'])) {
            $mainImage = $this->product['edited']['main_image'];
        } elseif (isset($this->product['parent_details']['edited']['main_image']) && !empty($this->product['parent_details']['edited']['main_image'])) {
            $mainImage = $this->product['parent_details']['edited']['main_image'];
        } elseif ($type == self::PRODUCT_TYPE_CHILD && isset($this->product['edited']['variant_image']) && !empty($this->product['variant_image'])) {
            $mainImage = $this->product['edited']['variant_image'];
        } elseif (isset($this->product['main_image']) && !empty($this->product['main_image'])) {
            // Check for ImagePriority global config
            // die("here");
            $userId = (string) $this->di->getUser()->id;
            $profileSetting = [];
            $imagePriorityConfig = $this->di->getConfig()->get('ImagePriority');
            if(!empty($imagePriorityConfig)){
                $imagePriorityConfig = $imagePriorityConfig->toArray();
            }
            $profileSettings = $this->product['profile_info']['data']??[];
            
            if(!empty($profileSettings)){
            foreach($profileSettings as $proSetting){
                if(isset($proSetting['data_type']) && $proSetting['data_type'] == 'product_settings'){
                    $profileSetting = $proSetting['data'];
                    break;
                }
                }
            }
            if(!empty($profileSetting) && isset($profileSetting['image_change']['main_image']))
            {
                $config = $profileSetting['image_change']['main_image'];
                
                $sourceKey = $config['key']; // e.g., 'additional_images'
                $objKey = $config['obj_key'] ?? null; // e.g., 'az_image'
                $position = $config['position'] ?? 0;
                $type = $config['type'] ?? 'string';
                $operation = $config['operation'] ?? 'replace';
                
                // Get value from the configured source
                if (isset($this->product[$sourceKey])) {
                    $sourceValue = $this->product[$sourceKey];
                    if ($type === 'array' && is_array($sourceValue)) {
                        // Get value at specific position
                        if (isset($sourceValue[$position])) {
                            $mainImage = is_array($sourceValue[$position]) && $objKey 
                                ? ($sourceValue[$position][$objKey] ?? null)
                                : $sourceValue[$position];
                        }
                    } elseif ($type === 'string') {
                        $mainImage = $sourceValue[$objKey];
                    } elseif ($type === 'metafield') {
                        // Handle metasfield type if needed
                        $mainImage = $sourceValue[$objKey]['value'];
                    }

                    }
                   
            }
            
            elseif (isset($imagePriorityConfig[$userId]['main_image'])) {
                $config = $imagePriorityConfig[$userId]['main_image'];
                
                $sourceKey = $config['key']; // e.g., 'additional_images'
                $objKey = $config['obj_key'] ?? null; // e.g., 'az_image'
                $position = $config['position'] ?? 0;
                $type = $config['type'] ?? 'string';
                $operation = $config['operation'] ?? 'replace';
                
                // Get value from the configured source
                if (isset($this->product[$sourceKey])) {
                    $sourceValue = $this->product[$sourceKey];

                    // Handle different types
                    if ($type === 'array' && is_array($sourceValue)) {
                        // Get value at specific position
                        if (isset($sourceValue[$position])) {
                            $mainImage = is_array($sourceValue[$position]) && $objKey 
                                ? ($sourceValue[$position][$objKey] ?? null)
                                : $sourceValue[$position];
                        }
                    } elseif ($type === 'string') {
                        $mainImage = $sourceValue[$objKey];
                    } elseif ($type === 'metafield') {
                        // Handle metasfield type if needed
                        $mainImage = $sourceValue[$objKey]['value'];
                    }
                }
            }
            
            // Fallback to default if config didn't set mainImage
            if (!$mainImage) {
                $mainImage = $this->product['main_image'];
            }
        }
        
        if (isset($this->product['edited']['additional_images'])) {
            $additionalImages = $this->product['edited']['additional_images'];
        } elseif (isset($this->product['parent_details']['edited']['additional_images']) && !empty($this->product['parent_details']['edited']['additional_images'])) {
            $additionalImages = $this->product['parent_details']['edited']['additional_images'];
        } elseif (isset($this->product['additional_images']) && !empty($this->product['additional_images'])) {
            $additionalImages = $this->product['additional_images'];
            if($operation == 'swap'){
                $additionalImages[$position] = $this->product['main_image'];
            }
        }

        // $imageHelper = $this->di->getObjectManager()->get(Image::class);
        
        if ($mainImage) {
            // $result = $imageHelper->uploadImageOnS3($mainImage, $this->product['shop_id'], $this->product['source_product_id'], 'main');
            $returnData[self::MAIN_PRODUCT_IMAGE_LABEL][] = [
                'media_location' => $mainImage,
                self::MARKETPLACE_ID_TAG => $this->marketplaceId,
            ];
        }

        if (!empty($additionalImages)) {
            foreach ($additionalImages as $index => $image) {
                if ($index > 7) {
                    break;
                }

                $imageCounter = $index + 1;
                // $result = $imageHelper->uploadImageOnS3($image, $this->product['shop_id'], $this->product['source_product_id'], "other_{$imageCounter}");
                $returnData[self::OTHER_PRODUCT_IMAGE_LABEL . '_' . $imageCounter][] = [
                    'media_location' => $image,
                    self::MARKETPLACE_ID_TAG => $this->marketplaceId,
                ];
            }
        }
        
        return $returnData;
    }

    /** return currency of user used in json-mapping */
    public function getCurrency($userId = false, $targetShopId = false)
    {
        if (!$userId) $userId = (string) $this->di->getUser()->id;

        if (!$targetShopId) $targetShopId = (string)$this->di->getRequester()->getTargetId();

        $listingHelper = $this->di->getObjectManager()->get(Helper::class);
        $targetShop = $listingHelper->getTargetShop($userId, $targetShopId);
        $this->marketplaceId = $targetShop['warehouses'][0]['marketplace_id'] ?? '';
        return $targetShop['currency'] ?? '';
    }
}
