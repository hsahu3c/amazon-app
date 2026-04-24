<?php

namespace App\Amazon\Components\Product;

use App\Amazon\Components\Listings\Error;
use App\Amazon\Components\Listings\CategoryAttributes;
use App\Amazon\Components\Template\Category;
use App\Amazon\Components\Common\Barcode;
use App\Amazon\Components\Listings\ListingHelper;
use App\Amazon\Components\LocalDeliveryHelper;
use App\Amazon\Components\ProductHelper;
use MongoDB\BSON\ObjectId;
use App\Core\Models\User;
use Exception;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\Feed\Feed;
use App\Core\Components\Base as Base;
use App\Connector\Components\Helper as ConnectorHelper;
use App\Amazon\Components\Listings\Error as ListingsError;


class Product extends Base
{
    private ?string $_user_id = null;




    private $_user_details;

    private $_baseMongo;

    private $configObj;

    public const OPERATION_TYPE_UPDATE = 'Update';

    public const OPERATION_TYPE_DELETE = 'Delete';

    public const OPERATION_TYPE_PARTIAL_UPDATE = 'PartialUpdate';

    public const PRODUCT_ERROR_VALID = 'valid';

    public const PRODUCT_TYPE_PARENT = 'parent';

    public const PRODUCT_TYPE_CHILD = 'child';

    public const DEFAULT_MAPPING = [
        'item_sku' => 'sku',
        'sku' => 'sku',
        // 'brand_name' => 'brand',
        'item_name' => 'title',
        //    'manufacturer' => 'brand',
        'standard_price' => 'price',
        'price' => 'price',
        'quantity' => 'quantity',
        'main_image_url' => 'main_image',
        'product-id' => 'barcode',
        'external_product_id' => 'barcode',
        'product_description' => 'description',
    ];

    public const IMAGE_ATTRIBUTES = [
        0 => 'other_image_url1',
        1 => 'other_image_url2',
        2 => 'other_image_url3',
        3 => 'other_image_url4',
        4 => 'other_image_url5',
        5 => 'other_image_url6',
        6 => 'other_image_url7',
        7 => 'other_image_url8',
    ];

    public const HANDMADE_IMAGE_ATTRIBUTES = [
        0 => 'pt1_image_url',
        1 => 'pt2_image_url',
        2 => 'pt3_image_url',
        3 => 'pt4_image_url',
        4 => 'pt5_image_url',
        5 => 'pt6_image_url',
        6 => 'pt7_image_url',
        7 => 'pt8_image_url',
    ];

    public $categoryAttributes = [];

    public $currency = false;

    public function init($request = [])
    {
        if (isset($request['user_id'])) {
            $this->_user_id = (string) $request['user_id'];
        } else {
            $this->_user_id = (string) $this->di->getUser()->id;
        }

        $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $this->configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
        if (isset($request['data']['source_shop_id'], $request['data']['source_marketplace'])) {
            $this->source_marketplace = $request['data']['source_marketplace'];
            $this->source_shop_id = (string) $request['data']['source_shop_id'];
        }

        if (isset($request['data']['target_shop_id'], $request['data']['target_marketplace'])) {
            $this->target_marketplace = $request['data']['target_marketplace'];
            $this->target_shop_id = (string) $request['data']['target_shop_id'];
        }

        if (isset($this->source_shop_id, $this->target_shop_id, $this->source_marketplace, $this->target_marketplace)) {
            $obj = [
                'Ced-Source-Id' => $this->source_shop_id,
                'Ced-Source-Name' => $this->source_marketplace,
                'Ced-Target-Id' => $this->target_shop_id,
                'Ced-Target-Name' => $this->target_marketplace
            ];
            $this->di->getObjectManager()->get('\App\Core\Components\Requester')->setHeaders($obj);
        }

        return $this;
    }

    public function getAmazonSubCategory(): void
    {
        print_r('hello');
        die;
    }

    public function getUploadCategorySettings($product)
    {
        $jsonHelper = $this->di->getObjectManager()->get(CategoryAttributes::class);
        $jsonAttributes = $jsonHelper->getJsonCategorySettings($product);
        if ($jsonAttributes && !empty($jsonAttributes)) {
            return $jsonAttributes;
        }

        $categorySettings = false;
        $amazonProduct = false;
        $optionalAttributes = false;
        $attributes = [];
        if (isset($product['edited'])) {
            $amazonProduct = $product['edited'];
        }

        if ($amazonProduct && isset($amazonProduct['category_settings'])) {

            if (isset($amazonProduct['category_settings']['attributes_mapping'])) {
                $categorySettings = $amazonProduct['category_settings'];
                if (isset($categorySettings['product_type'])) {
                    unset($categorySettings['product_type']);
                }

                if (isset($amazonProduct['category_settings']['optional_attributes'])) {
                    $categorySettings['optional_variant_attributes'] = $amazonProduct['optional_attributes'];
                }

                if (isset($amazonProduct['variation_theme_attribute_name'])) {
                    $categorySettings['variation_theme_attribute_name'] = $amazonProduct['variation_theme_attribute_name'];
                }

                if (isset($amazonProduct['variation_theme_attribute_value'])) {
                    $categorySettings['variation_theme_attribute_value'] = $amazonProduct['variation_theme_attribute_value'];
                }
            }

            if (isset($amazonProduct['category_settings']['primary_category']) && isset($amazonProduct['category_settings']['sub_category']) && !empty($amazonProduct['category_settings']['primary_category']) && !empty($amazonProduct['category_settings']['sub_category'])) {
                $categorySettings['primary_category'] = $amazonProduct['category_settings']['primary_category'];
                $categorySettings['sub_category'] = $amazonProduct['category_settings']['sub_category'];
                $categorySettings['browser_node_id'] = $amazonProduct['category_settings']['browser_node_id'];
                $categorySettings['barcode_exemption'] = $amazonProduct['category_settings']['barcode_exemption'];

                if (isset($amazonProduct['edited_variant_attributes'])) {
                    $optionalAttributes = $amazonProduct['edited_variant_attributes'];
                }

                if (isset($product['parent_details']['edited']['category_settings'])) {
                    if (isset($product['parent_details']['edited']['category_settings']['attributes_mapping'])) {

                        $op = [];
                        $req = $product['parent_details']['edited']['category_settings']['attributes_mapping']['required_attribute'];
                        $op = $product['parent_details']['edited']['category_settings']['attributes_mapping']['optional_attribute'];

                        $req = json_decode(json_encode($req), true);
                        $op = json_decode(json_encode($op), true);
                        if (empty($op)) {
                            $attributes = $req;
                        } else {
                            $attributes = array_merge($req, $op);
                        }

                        $newAttribute = [];
                        if ($optionalAttributes) {
                            foreach ($attributes as $key => $attribute) {
                                foreach ($optionalAttributes as $optionalAttribute) {
                                    if ($attribute['amazon_attribute'] == $optionalAttribute['amazon_attribute']) {
                                        array_push($newAttribute, $optionalAttribute);
                                        unset($attributes[$key]);
                                    }

                                    // else {
                                    //     array_push($newAttribute, $attribute);
                                    //     break;
                                    // }
                                }
                            }

                            $newAttribute = array_merge($newAttribute, $attributes);
                            $categorySettings['attributes_mapping']['required_attribute'] = $newAttribute;
                        } else {
                            $categorySettings['attributes_mapping']['required_attribute'] = $attributes;
                        }

                        if (isset($amazonProduct['variation_theme_fields']) && !empty($amazonProduct['variation_theme_fields'])) {
                            $categorySettings['variation_theme_fields'] = $amazonProduct['variation_theme_fields'];
                        }
                    }
                }

                if (empty($categorySettings['variation_theme_attribute_name']) && isset($product['parent_details']['edited']['variation_theme_attribute_name'])) {
                    $categorySettings['variation_theme_attribute_name'] = $product['parent_details']['edited']['variation_theme_attribute_name'];
                }
            }
        }


        if (!$categorySettings && isset($product['profile_info']) && is_array($product['profile_info'])) {
            $categorySettings = $product['profile_info']['category_settings'];
            $categorySettings['attributes_mapping'] = $product['profile_info']['attributes_mapping']['data'];
            if (isset($product['profile_info']['data']) && is_array($product['profile_info']['data'])) {
                foreach ($product['profile_info']['data'] as $setting) {
                    if ($setting['data_type'] == "fulfillment_settings") {
                        if (isset($setting['data']['fulfillment_type'])) {
                            $categorySettings['fulfillment_type'] = $setting['data']['fulfillment_type'];
                        }
                    }
                }
            }
        }

        /** offer product status */
        if (!empty($amazonProduct['status']) && $amazonProduct['status'] == 'Not Listed: Offer') {
            if (empty($categorySettings['primary_category']) || $categorySettings['primary_category'] !== 'default') {
                /** category should be default and condition-type in case of offer-listing */
                return ['errors' => ['AmazonError111']];
            }
            if (!empty($product['edited']['category_settings']['attributes_mapping']['required_attribute'])) {
                $categorySettings['attributes_mapping'] = $product['edited']['category_settings']['attributes_mapping'];
            }

            unset($categorySettings['variation_theme_fields']);
        }

        return $categorySettings;
    }

    public function getPartialUpdateCategorySettings($product)
    {
        $jsonHelper = $this->di->getObjectManager()->get(CategoryAttributes::class);
        $jsonAttributes = $jsonHelper->getJsonCategorySettings($product);
        if ($jsonAttributes && !empty($jsonAttributes)) {
            return $jsonAttributes;
        }

        $categorySettings = false;
        $amazonProduct = false;
        $optionalAttributes = false;
        $attributes = [];
        if (isset($product['edited'])) {
            $amazonProduct = $product['edited'];
        }

        if ($amazonProduct && isset($amazonProduct['category_settings'])) {
            if (isset($amazonProduct['category_settings']['attributes_mapping'])) {
                $categorySettings = $amazonProduct['category_settings'];
                if (isset($categorySettings['product_type'])) {
                    unset($categorySettings['product_type']);
                }

                if (isset($amazonProduct['category_settings']['optional_attributes'])) {
                    $categorySettings['optional_variant_attributes'] = $amazonProduct['optional_attributes'];
                }

                if (isset($amazonProduct['variation_theme_attribute_name'])) {
                    $categorySettings['variation_theme_attribute_name'] = $amazonProduct['variation_theme_attribute_name'];
                }

                if (isset($amazonProduct['variation_theme_attribute_value'])) {
                    $categorySettings['variation_theme_attribute_value'] = $amazonProduct['variation_theme_attribute_value'];
                }
            }

            if (isset($amazonProduct['category_settings']['primary_category']) && isset($amazonProduct['category_settings']['sub_category']) && !empty($amazonProduct['category_settings']['primary_category']) && !empty($amazonProduct['category_settings']['sub_category'])) {
                $categorySettings['primary_category'] = $amazonProduct['category_settings']['primary_category'];
                $categorySettings['sub_category'] = $amazonProduct['category_settings']['sub_category'];
                $categorySettings['browser_node_id'] = $amazonProduct['category_settings']['browser_node_id'];
                $categorySettings['barcode_exemption'] = $amazonProduct['category_settings']['barcode_exemption'];
                if (isset($amazonProduct['edited_variant_attributes'])) {
                    $optionalAttributes = $amazonProduct['edited_variant_attributes'];
                }

                if (isset($product['parent_details']['edited']['category_settings'])) {
                    if (isset($product['parent_details']['edited']['category_settings']['attributes_mapping'])) {
                        $op = [];
                        $req = $product['parent_details']['edited']['category_settings']['attributes_mapping']['required_attribute'];
                        $op = $product['parent_details']['edited']['category_settings']['attributes_mapping']['optional_attribute'];
                        $req = json_decode(json_encode($req), true);
                        $op = json_decode(json_encode($op), true);
                        if (empty($op)) {
                            $attributes = $req;
                        } else {
                            $attributes = array_merge($req, $op);
                        }

                        $newAttribute = [];
                        if ($optionalAttributes) {
                            foreach ($attributes as $key => $attribute) {
                                foreach ($optionalAttributes as $optionalAttribute) {
                                    if ($attribute['amazon_attribute'] == $optionalAttribute['amazon_attribute']) {
                                        array_push($newAttribute, $optionalAttribute);
                                        unset($attributes[$key]);
                                    }

                                    // else {
                                    //     array_push($newAttribute, $attribute);
                                    //     break;
                                    // }
                                }
                            }

                            $newAttribute = array_merge($newAttribute, $attributes);
                            $categorySettings['attributes_mapping']['required_attribute'] = $newAttribute;
                        } else {
                            $categorySettings['attributes_mapping']['required_attribute'] = $attributes;
                        }

                        if (isset($amazonProduct['variation_theme_fields']) && !empty($amazonProduct['variation_theme_fields'])) {
                            $categorySettings['variation_theme_fields'] = $amazonProduct['variation_theme_fields'];
                        }
                    }
                }

                if (empty($categorySettings['variation_theme_attribute_name']) && isset($product['parent_details']['edited']['variation_theme_attribute_name'])) {
                    $categorySettings['variation_theme_attribute_name'] = $product['parent_details']['edited']['variation_theme_attribute_name'];
                }
            }
        }

        if (!$categorySettings && isset($product['profile_info']) && is_array($product['profile_info'])) {
            $categorySettings = $product['profile_info']['category_settings'];
            $categorySettings['attributes_mapping'] = $product['profile_info']['attributes_mapping']['data'];
            if (isset($product['profile_info']['data']) && is_array($product['profile_info']['data'])) {
                foreach ($product['profile_info']['data'] as $setting) {
                    if ($setting['data_type'] == "fulfillment_settings") {
                        if (isset($setting['data']['fulfillment_type'])) {
                            $categorySettings['fulfillment_type'] = $setting['data']['fulfillment_type'];
                        }
                    }
                }
            }
        }

        return $categorySettings;
    }

    public function partialUpdate($data, $operationType = self::OPERATION_TYPE_PARTIAL_UPDATE)
    {
        $feedContent = [];

        if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
            $preparedContent = [];
            $userId = $data['data']['params']['user_id'] ?? $this->_user_id;
            $targetShopId = $data['data']['params']['target']['shopId'];
            $sourceShopId = $data['data']['params']['source']['shopId'];
            $targetMarketplace = $data['data']['params']['target']['marketplace'];
            $sourceMarketplace = $data['data']['params']['source']['marketplace'];
            $date = date('d-m-Y');
            $logFile = "amazon/{$userId}/{$sourceShopId}/SyncProduct/{$date}.log";
            $productErrorList = [];
            $response = [];
            $active = false;
            $categorySettings = false;
            $removeTagProductList = [];
            $categoryErrorProductList = [];
            $inventoryComponent = $this->di->getObjectManager()->get(Inventory::class);

            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $targetShop = $user_details->getShop($targetShopId, $userId);
            $sourceShop = $user_details->getShop($sourceShopId, $userId);

            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
            $activeAccounts = [];
            foreach ($targetShop['warehouses'] as $warehouse) {
                if ($warehouse['status'] == "active") {
                    $activeAccounts = $warehouse;
                }
            }

            if (!empty($activeAccounts)) {
                $connector = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
                $currencyCheck = $connector->checkCurrency($userId, $targetMarketplace, $sourceMarketplace, $targetShopId, $sourceShopId);

                $this->configObj->setUserId($userId);
                $this->configObj->setTarget($targetMarketplace);
                $this->configObj->setTargetShopId($targetShopId);
                $this->configObj->setSourceShopId($sourceShopId);


                if (!$currencyCheck) {

                    $this->configObj->setGroupCode('currency');
                    $sourceCurrency = $this->configObj->getConfig('source_currency');
                    $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);

                    $amazonCurrency = $this->configObj->getConfig('target_currency');
                    $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);

                    if (isset($sourceCurrency[0]['value'], $amazonCurrency[0]['value']) && $sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
                        $currencyCheck = true;
                    } else {
                        $amazonCurrencyValue = $this->configObj->getConfig('target_value');
                        $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
                        if (isset($amazonCurrencyValue[0]['value'])) {
                            $currencyCheck = $amazonCurrencyValue[0]['value'];
                        }
                    }
                }

                if ($currencyCheck) {
                    $this->currency = $currencyCheck;
                    $products = $data['data']['rows'];
                    foreach ($products as $product) {
                        $sync = [];
                        if ($operationType == self::OPERATION_TYPE_PARTIAL_UPDATE) {
                            $sync = $this->getSyncStatus($product);
                            if (isset($sync['priceInvSyncList']) && $sync['priceInvSyncList']) {
                                $priceInvSyncList['source_product_ids'][] = $product['source_product_id'];
                            }

                            if (isset($sync['imageSyncProductList']) && $sync['imageSyncProductList']) {
                                $imageSyncProductList['source_product_ids'][] = $product['source_product_id'];
                            }

                            if (isset($sync['productSyncProductList']) && $sync['productSyncProductList']) {
                                $categorySettings = $this->getPartialUpdateCategorySettings($product);

                                // $firstChild = reset($childArray);
                                $productSyncProductList['source_product_ids'][] = $product['source_product_id'];
                            }
                        }

                        $this->removeTag([$product['source_product_id']], [Helper::PROCESS_TAG_SYNC_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
                        if ($categorySettings) {
                            $container_ids[$product['source_product_id']] = $product['container_id'];
                            $alreadySavedActivities[$product['source_product_id']] = $product['edited']['last_activities'] ?? [];

                            if (isset($product['parent_details'])) {
                                $parentAmazonProduct = $product['parent_details'];
                                if ($parentAmazonProduct && isset($parentAmazonProduct['edited']['parent_sku']) && $parentAmazonProduct['edited']['parent_sku'] != '') {
                                    $productTypeParentSku = $parentAmazonProduct['edited']['parent_sku'];
                                } else if ($parentAmazonProduct && isset($parentAmazonProduct['sku']) && $parentAmazonProduct['sku'] != '') {
                                    $productTypeParentSku = $parentAmazonProduct['sku'];
                                } else {
                                    $productTypeParentSku = $parentAmazonProduct['source_product_id'];
                                }

                                $preparedContent = $this->setPartialUpdateContent($categorySettings, $product, $targetShopId, self::PRODUCT_TYPE_CHILD, $productTypeParentSku, $operationType, $sourceMarketplace);
                            } else if ($product['type'] == "variation") {
                                $preparedContent = $this->setPartialUpdateContent($categorySettings, $product, $targetShopId, self::PRODUCT_TYPE_PARENT, false, $operationType, $sourceMarketplace);
                                if (empty($preparedContent)) {
                                    continue;
                                }
                            } else {
                                $preparedContent = $this->setPartialUpdateContent($categorySettings, $product, $targetShopId, false, false, $operationType, $sourceMarketplace);
                                if (in_array($product['source_product_id'], ['40475270840475', '40473394708635', '40473394675867', '40473394643099', '40473394577563'])) {
                                    unset($preparedContent['variation_theme']);
                                }
                            }

                            if (isset($preparedContent['operation-type'])) {
                                $operationType = $preparedContent['operation-type'];
                            }

                            if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
                                $productErrorList[$product['source_product_id']] = $preparedContent['error'];
                            } else {
                                unset($preparedContent['error']);
                                $time = date(DATE_ISO8601);
                                $preparedContent['time'] = $time;
                                $feedContent[$categorySettings['primary_category']][$product['source_product_id']] =
                                    $preparedContent;
                                $feedContent[$categorySettings['primary_category']]['category'] =
                                    $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['category'];
                                $feedContent[$categorySettings['primary_category']]['sub_category'] =
                                    $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['sub_category'];
                                $feedContent[$categorySettings['primary_category']]['barcode_exemption'] =
                                    $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['barcode_exemption'];
                            }
                        } else {
                            $removeTagProductList[] = $product['source_product_id'];
                            if (!$sync['productSyncProductList']) {
                                $productErrorList[$product['source_product_id']] = ['AmazonError107'];
                            } else {
                                $productErrorList[$product['source_product_id']] = ['AmazonError101'];
                            }
                        }
                    }

                    $addTagProductList = [];
                    // $this->di->getLog()->logContent('FeedContent= ' . print_r($feedContent, true), 'info', $logFile);
                    if (!empty($feedContent)) {
                        foreach ($feedContent as $content) {
                            $specifics = [
                                'home_shop_id' => $targetShop['_id'],
                                'marketplace_id' => $targetShop['warehouses'][0]['marketplace_id'],
                                'shop_id' => $targetShop['remote_shop_id'],
                                'sourceShopId' => $sourceShopId,
                                'feedContent' => base64_encode(serialize($content)),
                                'user_id' => $userId,
                                'operation_type' => $operationType,
                            ];

                            $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');
                            if ($response == null) {
                                $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');
                            }

                            if (isset($response['success']) && $response['success']) {
                                unset($content['category']);
                                unset($content['sub_category']);
                                unset($content['barcode_exemption']);
                                $specifics['feedContent'] = $content;
                                $inventoryComponent->saveFeedInDb($content, 'update', $container_ids, $data['data']['params'], $alreadySavedActivities);
                            }

                            // $this->di->getLog()->logContent('Response = ' . print_r($response, true), 'info', $logFile);
                            $productSpecifics = $this->processResponse($response, $feedContent, Helper::PROCESS_TAG_UPDATE_FEED, 'product', $targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, Helper::PROCESS_TAG_SYNC_PRODUCT);
                            $addTagProductList = array_merge($addTagProductList, $productSpecifics['addTagProductList']);
                            $productErrorList = array_merge($productErrorList, $productSpecifics['productErrorList']);
                        }

                        return $this->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0, true);
                    }
                    $productSpecifics = $this->processResponse($response, $feedContent, Helper::PROCESS_TAG_SYNC_PRODUCT, 'product', $targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList);
                    return $this->setResponse(Helper::RESPONSE_SUCCESS, count($addTagProductList), 0, count($productErrorList), 0, true);
                }
                return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Currency of ' . $sourceMarketplace . 'and Amazon account are not same. Please fill currency settings from Global Price Adjustment in settings page.', 0, 0, 0, false);
            }
            return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0, false);
        }
        return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0, false);
    }

    public function setPartialUpdateContent($categorySettings, $product, $homeShopId, $type, $parentSku, $operationType, $sourceMarketplace = false)
    {
        $feedContent = [];
        $flag = true;

        if (!empty($categorySettings)) {
            $sourceSelect = $sourceMarketplace . '_select';
            $product = json_decode(json_encode($product), true);
            $barcodeExemption = $categorySettings['barcode_exemption'] ?? "";
            $subCategory = $categorySettings['sub_category'] ?? "";
            $category = $categorySettings['primary_category'] ?? "";
            $valueMappedAttributes = $categorySettings['attributes_mapping']['value_mapping'] ?? [];
            $requiredAttributes = $categorySettings['attributes_mapping']['required_attribute'] ?? [];
            $optionalAttributes = $categorySettings['attributes_mapping']['optional_attribute'] ?? [];
            $browseNodeId = $categorySettings['browser_node_id'] ?? "";
            $attributes = [...(array) $requiredAttributes, ...(array) $optionalAttributes];
            $variant_attributes = [];
            $productChangable = false;

            if (isset($categorySettings['optional_variant_attributes'])) {
                $variant_attributes = array_merge($variant_attributes, $categorySettings['optional_variant_attributes']);
            }

            if (isset($categorySettings['variation_theme_attribute_name']) && $categorySettings['variation_theme_attribute_name']) {
                $variant_attributes['variation_theme'] = $categorySettings['variation_theme_attribute_name'];
            }

            if (isset($categorySettings['variation_theme_attribute_value']) && $categorySettings['variation_theme_attribute_value']) {
                $variant_attributes = array_merge($variant_attributes, $categorySettings['variation_theme_attribute_value']);
            }

            // Offer cannot be created for parent product
            if ($product['type'] == 'variation' && $category == 'default') {
                return $feedContent;
            }

            $amazonProduct = false;
            if (isset($product['edited'])) {
                $amazonProduct = $product['edited'];
            }

            $categoryContainer = $this->di->getObjectManager()->get(Category::class);
            $params = [
                'data' => [
                    'category' => $category,
                    'sub_category' => $subCategory,
                    'browser_node_id' => $browseNodeId,
                    'barcode_exemption' => $barcodeExemption,
                    'browse_node_id' => $browseNodeId,
                ],
                'target' => [

                    'shopId' => $homeShopId,
                ],

            ];

            if (isset($this->categoryAttributes[$category][$subCategory])) {
                $attributeArray = $this->categoryAttributes[$category][$subCategory];
            } else {
                $allAttributes = $categoryContainer->init()->getAmazonAttributes($params, true);

                $attributeArray = [];

                if (isset($allAttributes['data'])) {
                    $attributeArray = $categoryContainer->init()->moldAmazonAttributes($allAttributes['data']);
                }

                $this->categoryAttributes[$category][$subCategory] = $attributeArray;
            }

            $arrayKeys = array_column($attributes, 'amazon_attribute');
            $mappedAttributeArray = array_combine($arrayKeys, $attributes);
            $images = false;
            if (isset($amazonProduct['additional_images'])) {
                $images = $amazonProduct['additional_images'];
            } elseif (isset($product['additional_images'])) {
                $images = $product['additional_images'];
            }

            $defaultMapping = self::DEFAULT_MAPPING;
            if (!empty($mappedAttributeArray) && !empty($attributeArray)) {
                foreach ($attributeArray as $id => $attribute) {
                    if ($id == 'main_image_url' && $this->_user_id == '61d29db38092370f1800a6a0') {
                        continue;
                    }

                    $amazonAttribute = $id;
                    $sourceAttribute = false;
                    if (isset($defaultMapping[$id])) {
                        $sourceAttribute = $defaultMapping[$id];
                    } elseif (($type == self::PRODUCT_TYPE_CHILD || $id == 'variation_theme') && isset($variant_attributes[$id])) {
                        $sourceAttribute = 'variant_custom';
                    } elseif (isset($mappedAttributeArray[$id]) && isset($mappedAttributeArray[$id][$sourceSelect]) && $mappedAttributeArray[$id][$sourceSelect] == "custom") {
                        $sourceAttribute = "custom";
                    } elseif (isset($mappedAttributeArray[$id]) && isset($mappedAttributeArray[$id][$sourceSelect]) && $mappedAttributeArray[$id][$sourceSelect] == "recommendation") {
                        $sourceAttribute = "recommendation";
                    } elseif (isset($mappedAttributeArray[$id])) {
                        $sourceAttribute = $mappedAttributeArray[$id][$sourceMarketplace . '_attribute'];
                    } elseif (isset($attributeArray[$id]['premapped_values']) && !empty($attributeArray[$id]['premapped_values'])) {
                        $sourceAttribute = 'premapped_values';
                    }

                    // if ($id == 'brand_name' && isset($mappedAttributeArray[$id])) {
                    //     $sourceAttribute = $mappedAttributeArray[$id]['shopify_attribute'];
                    // }

                    if ($id == 'product_description' && isset($mappedAttributeArray[$id])) {
                        $sourceAttribute = $mappedAttributeArray[$id][$sourceMarketplace . '_attribute'];
                    }

                    $value = '';

                    if ($sourceAttribute) {
                        if ($sourceAttribute == 'recommendation') {
                            $recommendation = $mappedAttributeArray[$id]['recommendation'];
                            if ($recommendation == 'custom') {
                                $customText = $mappedAttributeArray[$id]['custom_text'];
                                $value = $customText;
                            } else {
                                $value = $recommendation;
                            }
                        } elseif ($sourceAttribute == 'custom') {
                            $customText = $mappedAttributeArray[$id]['custom_text'];
                            $value = $customText;
                        } elseif (isset($product[$sourceAttribute])) {
                            $value = $product[$sourceAttribute];
                        } elseif ($sourceAttribute == 'variant_custom') {
                            $value = $variant_attributes[$id];
                        } elseif ($sourceAttribute == "premapped_values") {
                            $value = $attributeArray[$id]['premapped_values'];
                        } elseif (isset($product['variant_attributes']) && $type == self::PRODUCT_TYPE_CHILD) {
                            foreach ($product['variant_attributes'] as $variantatt) {
                                if ($variantatt['key'] == $sourceAttribute) {
                                    $value = $variantatt['value'];
                                }
                            }
                        }

                        if ($sourceAttribute == 'sku' && $type == self::PRODUCT_TYPE_PARENT && $parentSku) {
                            $value = $parentSku;
                        }

                        if (in_array($id, ['apparel_size', 'shirt_size', 'footwear_size_unisex', 'footwear_size', 'bottoms_size', 'skirt_size', 'shapewear_size', 'bottoms_waist_size']) && $type == self::PRODUCT_TYPE_PARENT) {
                            if (isset($feedContent['footwear_size_class']) || isset($feedContent['bottoms_size_class']) || isset($feedContent['apparel_size_class']) || isset($feedContent['skirt_size_class']) || isset($feedContent['shapewear_size_class']) || isset($feedContent['shirt_size_class'])) {
                                if ((isset($feedContent['footwear_size_class']) && ($feedContent['footwear_size_class'] == 'Numeric' || $feedContent['footwear_size_class'] == 'Numeric Range' || $feedContent['footwear_size_class'] == 'Numero' || $feedContent['footwear_size_class'] == 'Numérique')) || (isset($feedContent['bottoms_size_class']) && $feedContent['bottoms_size_class'] == 'Numeric') || (isset($feedContent['apparel_size_class']) && $feedContent['apparel_size_class'] == 'Numeric') || (isset($feedContent['skirt_size_class']) && $feedContent['skirt_size_class'] == 'Numeric') || (isset($feedContent['shirt_size_class']) && $feedContent['shirt_size_class'] == 'Numeric')) {
                                    if ($id == 'footwear_size') {
                                        $value = 36;
                                    } else {
                                        $value = 34;
                                    }
                                } elseif ((isset($feedContent['shapewear_size_class']) && $feedContent['shapewear_size_class'] == 'Age') || (isset($feedContent['shirt_size_class']) && $feedContent['shirt_size_class'] == 'Age') || (isset($feedContent['footwear_size_class']) && $feedContent['footwear_size_class'] == 'Age') || (isset($feedContent['bottoms_size_class']) && $feedContent['bottoms_size_class'] == 'Age') || (isset($feedContent['apparel_size_class']) && $feedContent['apparel_size_class'] == 'Age')) {
                                    $value = '4 Years';
                                } else {
                                    $value = 'M';
                                }
                            }

                            $productChangable = true;
                        }

                        if ($id == 'main_image_url') {
                            if (isset($amazonProduct['main_image'])) {
                                $value = $amazonProduct['main_image'];
                            } elseif ($type == self::PRODUCT_TYPE_CHILD && isset($product['variant_image'])) {
                                $value = $product['variant_image'];
                            }
                        }

                        if ($sourceAttribute == 'sku' && $type != self::PRODUCT_TYPE_PARENT) {
                            if ($amazonProduct && isset($amazonProduct[$sourceAttribute]) && $amazonProduct[$sourceAttribute]) {
                                $value = $amazonProduct[$sourceAttribute];
                            } elseif ($product && isset($product[$sourceAttribute]) && !empty($product[$sourceAttribute])) {
                                $value = $product[$sourceAttribute];
                            } elseif (isset($product['source_product_id'])) {
                                $value = $product['source_product_id'];
                            }
                        }

                        if ($sourceAttribute == 'barcode' && $amazonProduct && isset($amazonProduct[$sourceAttribute])) {
                            $value = $amazonProduct[$sourceAttribute];
                        }

                        if ($sourceAttribute == 'barcode') {
                            $value = str_replace("-", "", $value);
                        }

                        if ($sourceAttribute == 'description' && $amazonProduct && isset($amazonProduct[$sourceAttribute])) {
                            $value = $amazonProduct[$sourceAttribute];
                        }

                        if ($sourceAttribute == 'title' && $amazonProduct && isset($amazonProduct[$sourceAttribute])) {
                            $value = $amazonProduct[$sourceAttribute];
                        }

                        if ($sourceAttribute == 'price' && $type != self::PRODUCT_TYPE_PARENT) {
                            //                            if ($amazonProduct && isset($amazonProduct[$sourceAttribute])) {
                            //                                $value = $amazonProduct[$sourceAttribute];
                            //                            } else {
                            $productPrice = $this->di->getObjectManager()->get(Price::class);
                            //   $currency = $productPrice->init()->getConversionRate();
                            $currency = $this->currency;
                            // print_r($currency);die("why");
                            if ($currency) {
                                $priceTemplate = $productPrice->init()->prepare($product);
                                $priceList = $productPrice->init()->calculate($product, $priceTemplate, $currency, true);

                                if (isset($priceList['StandardPrice']) && $priceList['StandardPrice']) {
                                    $value = $priceList['StandardPrice'];
                                } else {
                                    if (!isset($priceList['StandardPrice'])) {
                                        $value = false;
                                    } else {
                                        $value = 0;
                                    }
                                }
                            } else {
                                $value = 0;
                            }

                            //                            }
                        }

                        // echo "here";
                        if ($sourceAttribute == 'quantity' && $type != self::PRODUCT_TYPE_PARENT) {
                            // echo "how ";
                            //                            $this->di->getLog()->logContent('getting quantity for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');
                            //                            if ($amazonProduct && isset($amazonProduct[$sourceAttribute])) {
                            //                                $value = $amazonProduct[$sourceAttribute];
                            //                            }
                            //                            else {
                            if (!$sourceMarketplace) {
                                $sourceMarketplace = $product['source_marketplace'];
                            }

                            $productInventory = $this->di->getObjectManager()->get(Inventory::class);
                            $inventoryTemplate = $productInventory->init()->prepare($product);
                            $inventoryList = $productInventory->init()->calculate($product, $inventoryTemplate, $sourceMarketplace);
                            if (isset($inventoryList['Quantity']) && $inventoryList['Quantity'] && $inventoryList['Quantity'] > 0) {
                                $value = $inventoryList['Quantity'];
                            } else {
                                $value = 0;
                            }

                            //                            }
                        }

                        if ($sourceAttribute == 'description') {
                            //  $value = strip_tags($value);
                            $value = str_replace(["\n", "\r"], ' ', $value);
                            $tag = 'span';
                            $value = preg_replace('#</?' . $tag . '[^>]*>#is', '', $value);

                            $value = strip_tags($value, '<p></p><ul></ul><li></li><strong></strong>');
                            $value = preg_replace('/(<[^>]+) style=".*?"/i', '$1', $value);
                            $value = preg_replace("/(<[^>]+) style='.*?'/i", '$1', $value);
                            $value = preg_replace('/(<[^>]+) class=".*?"/i', '$1', $value);
                            $value = preg_replace("/(<[^>]+) class='.*?'/i", '$1', $value);
                            $value = preg_replace('/(<[^>]+) data=".*?"/i', '$1', $value);
                            $value = preg_replace("/(<[^>]+) data='.*?'/i", '$1', $value);
                            $value = preg_replace('/(<[^>]+) data-mce-fragment=".*?"/i', '$1', $value);
                            $value = preg_replace("/(<[^>]+) data-mce-fragment='.*?'/i", '$1', $value);
                            $value = preg_replace('/(<[^>]+) data-mce-style=".*?"/i', '$1', $value);
                            $value = preg_replace("/(<[^>]+) data-mce-style='.*?'/i", '$1', $value);
                            $value = preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/si", '<$1$2>', $value);
                        }

                        if ($sourceAttribute == 'weight') {
                            if ((float) $value > 0) {
                                $value = number_format((float) $value, 2);
                            } else {
                                $value = '';
                            }
                        }

                        if ($id == 'item_name' && isset($product['variant_title']) && $product['type'] == "simple" && $product['visibility'] == "Not Visible Individually") {
                            if (isset($amazonProduct) && isset($amazonProduct['title'])) {
                                $value = $amazonProduct['title'];
                            } else {
                                $value = $product['title'] . ' ' . $product['variant_title'];
                            }
                        }


                        if ($id == 'fulfillment_center_id' && $type == self::PRODUCT_TYPE_PARENT) {
                            $value = '';
                        }

                        if ($this->_user_id == '612f48c95c77ca2b3904cda9' && $amazonAttribute == 'gem_type1') {
                            $value = 'Metal';
                        }

                        if ($sourceAttribute == 'title') {
                            $value = str_replace("’", "'", $value);
                        }

                        // if ($this->_user_id == "6289f0a2d3f6d710ce370f9d") {
                        //     // $value = $this->mapOptionLatest($id, $sourceAttribute, $value);
                        //     $value ='';
                        // } elseif($this->_user_id != "63b35aa89eb4193d1b06e508") {
                        //     $value = $this->mapOption($id, $sourceAttribute, $value);
                        // }
                        $value = $this->mapOption($id, $sourceAttribute, $value, $valueMappedAttributes, $sourceMarketplace);
                        if (is_array($value)) {
                            $value = implode(",", $value);
                        }

                        $feedContent[$amazonAttribute] = $value;
                    }

                    if ($amazonAttribute == 'feed_product_type') {
                        if (isset($attribute['amazon_recommendation'][$subCategory])) {
                            $acceptedValues = $attribute['amazon_recommendation'][$subCategory];
                            $feedContent[$amazonAttribute] = $acceptedValues;
                        } else {
                            $feedContent[$amazonAttribute] = $subCategory;
                        }

                        if ($feedContent[$amazonAttribute] == 'shirt' && $this->_user_id == '61182f2ad4fc9a5310349827') {
                            $feedContent[$amazonAttribute] = 'SHIRT';
                        }
                    }

                    if (in_array($amazonAttribute, self::IMAGE_ATTRIBUTES) && $this->_user_id != '61d29db38092370f1800a6a0') {
                        $imageKey = array_search($amazonAttribute, self::IMAGE_ATTRIBUTES);
                        if (isset($images[$imageKey])) {
                            $feedContent[$amazonAttribute] = $images[$imageKey];
                        }
                    }

                    if ($params['data']['category'] == 'handmade') {
                        if (in_array($amazonAttribute, self::HANDMADE_IMAGE_ATTRIBUTES)) {
                            $imageKey = array_search($amazonAttribute, self::HANDMADE_IMAGE_ATTRIBUTES);
                            if (isset($images[$imageKey])) {
                                $feedContent[$amazonAttribute] = $images[$imageKey];
                            }
                        }
                    }

                    if ($amazonAttribute == 'recommended_browse_nodes' || $amazonAttribute == 'recommended_browse_nodes1') {
                        if ($this->_user_id == '6116cad63715e60b00136653') {
                            $feedContent[$amazonAttribute] = '5977780031';
                        } elseif ($this->_user_id == '612f48c95c77ca2b3904cda9') {
                            $feedContent[$amazonAttribute] = '10459533031';
                        } else {
                            $feedContent[$amazonAttribute] = $browseNodeId;
                        }
                    }

                    if ($amazonAttribute == 'parent_child' && ($type == self::PRODUCT_TYPE_PARENT || $type == self::PRODUCT_TYPE_CHILD)) {
                        $feedContent[$amazonAttribute] = $type;
                    }

                    if ($amazonAttribute == 'relationship_type' && $type == self::PRODUCT_TYPE_CHILD) {
                        $feedContent[$amazonAttribute] = 'variation';
                    }

                    if ($amazonAttribute == 'parent_sku' && $type == self::PRODUCT_TYPE_CHILD) {
                        $feedContent[$amazonAttribute] = $parentSku;
                    }

                    if ($amazonAttribute == 'operation-type' || $amazonAttribute == 'update_delete') {
                        if ($category == 'default' && $operationType == self::OPERATION_TYPE_PARTIAL_UPDATE) {
                            $operationType = self::OPERATION_TYPE_UPDATE;
                        }

                        $feedContent[$amazonAttribute] = $operationType;
                    }

                    if ($amazonAttribute == 'ASIN-hint' && isset($amazonProduct['asin'])) {
                        $feedContent[$amazonAttribute] = $amazonProduct['asin'];
                    }
                }

                $feedContent['barcode_exemption'] = $barcodeExemption;
                if (isset($category, $subCategory)) {
                    $feedContent['category'] = $category;
                    $feedContent['sub_category'] = $subCategory;
                }

                if ($barcodeExemption) {
                    unset($feedContent['product-id']);
                    unset($feedContent['external_product_id']);
                }

                if (isset($feedContent['product-id']) && (!$barcodeExemption || $feedContent['category'] == 'default') && $feedContent['product-id']) {
                    $barcode = $this->di->getObjectManager()->get(Barcode::class);
                    $type = $barcode->setBarcode($feedContent['product-id']);
                    if ($type) {
                        $feedContent['product-id-type'] = $type;
                    }
                } elseif (isset($feedContent['external_product_id']) && (!$barcodeExemption || $feedContent['category'] == 'default') && $feedContent['external_product_id']) {
                    $barcode = $this->di->getObjectManager()->get(Barcode::class);
                    $type = $barcode->setBarcode($feedContent['external_product_id']);
                    if ($type) {
                        $feedContent['external_product_id_type'] = $type;
                    }
                }

                if ($this->_user_id = "6514eb6f510817e7c304d8f2") {
                    if (isset($feedContent['product_description'])) {
                        if (strlen((string) $feedContent['product_description']) > 2000) {
                            $feedContent['product_description'] = substr((string) $feedContent['product_description'], 0, 1990);
                        }
                    }
                }

                if ($productChangable) {
                    $feedContent['parentChangable'] = true;
                }

                if ($params['data']['category'] != 'handmade') {
                    $feedContent = $this->validate($feedContent, $product, $attributeArray, $params);
                }

                //                $this->di->getLog()->logContent('validation done for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');
            }

            //            $this->di->getLog()->logContent('done setting data in attributes for Id : '.$product['source_product_id'].' in setContentNew function', 'info', 'ProductUpload.log');
        }

        return $feedContent;
    }

    // public function upload($data, $localDelivery = false, $operationType = self::OPERATION_TYPE_UPDATE)
    // {
    //     $feedContent = [];
    //     $date = date('d-m-Y');

    //     if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
    //         $preparedContent = [];
    //         $userId = $data['data']['params']['user_id'];
    //         $targetShopId = $data['data']['params']['target']['shopId'];
    //         $sourceShopId = $data['data']['params']['source']['shopId'];
    //         $targetMarketplace = $data['data']['params']['target']['marketplace'];
    //         $sourceMarketplace = $data['data']['params']['source']['marketplace'];
    //         $productErrorList = [];
    //         $response = [];
    //         $active = false;
    //         $logFile = "amazon/{$userId}/{$sourceShopId}/ProductUpload/{$date}.log";
    //         // $this->di->getLog()->logContent('Receiving DATA = ' . print_r($data, true), 'info', $logFile);
    //         $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
    //         $inventoryComponent = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Inventory');
    //         $targetShop = $user_details->getShop($targetShopId, $userId);
    //         $sourceShop = $user_details->getShop($sourceShopId, $userId);
    //         $contentToSaved = [];
    //         $alreadySavedActivities = [];
    //         $merchantName = false;
    //         $merchantData = false;
    //         $merchantDataSourceProduct = false;
    //         if ($localDelivery) {
    //             $merchantName = $data['data']['params']['ld_source_product_ids'] ?? false;
    //         }

    //         $commonHelper = $this->di->getObjectManager()->get(Helper::class);
    //         $multiplier = false;
    //         $activeAccounts = [];

    //         foreach ($targetShop['warehouses'] as $warehouse) {
    //             if ($warehouse['status'] == "active") {
    //                 $activeAccounts = $warehouse;
    //             }
    //         }

    //         if (!empty($activeAccounts)) {

    //             $connector = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
    //             $currencyCheck = $connector->checkCurrency($userId, $targetMarketplace, $sourceMarketplace, $targetShopId, $sourceShopId);

    //             $this->configObj->setUserId($userId);
    //             $this->configObj->setTarget($targetMarketplace);
    //             $this->configObj->setTargetShopId($targetShopId);
    //             $this->configObj->setSourceShopId($sourceShopId);

    //             if (!$currencyCheck) {

    //                 $this->configObj->setGroupCode('currency');
    //                 $sourceCurrency = $this->configObj->getConfig('source_currency');
    //                 $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);
    //                 // $this->di->getLog()->logContent('sourceCurrency : '.json_encode($sourceCurrency), 'info', 'ProductUpload.log');

    //                 $amazonCurrency = $this->configObj->getConfig('target_currency');
    //                 $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);
    //                 // $this->di->getLog()->logContent('amazonCurrency : '.json_encode($amazonCurrency), 'info', 'ProductUpload.log');

    //                 if ($sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
    //                     $currencyCheck = true;
    //                 } else {
    //                     $amazonCurrencyValue = $this->configObj->getConfig('target_value');
    //                     $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
    //                     if (isset($amazonCurrencyValue[0]['value'])) {
    //                         $currencyCheck = $amazonCurrencyValue[0]['value'];
    //                     }
    //                 }
    //             }
    //             // $this->di->getLog()->logContent('Currency : '.json_encode($currencyCheck), 'info', 'ProductUpload.log');

    //             if ($currencyCheck) {
    //                 $this->currency = $currencyCheck;
    //                 $products = $data['data']['rows'];
    //                 foreach ($products as $product) {
    //                     $type = false;


    //                     if ($operationType == self::OPERATION_TYPE_UPDATE) {
    //                         $categorySettings = $this->getUploadCategorySettings($product);
    //                     }
    //                     $this->removeTag([$product['source_product_id']], [\App\Amazon\Components\Common\Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
    //                     if ($localDelivery && $merchantName) {
    //                         if (isset($merchantName[$product['source_product_id']])) {
    //                             $merchantData = $merchantName[$product['source_product_id']];
    //                         } elseif (isset($merchantName[$product['container_id']])) {
    //                             $merchantData = $merchantName[$product['container_id']];
    //                         }
    //                         $merchantDataSourceProduct[$product['source_product_id']] = $merchantData['merchant_shipping_group'] ?? [];
    //                     }
    //                     if ($categorySettings) {

    //                         //saving container_id in array
    //                         $container_ids[$product['source_product_id']] = $product['container_id'];
    //                         $alreadySavedActivities[$product['source_product_id']] = isset($product['edited']['last_activities']) ? $product['edited']['last_activities'] : [];

    //                         if (isset($product['parent_details'])) {
    //                             $parentAmazonProduct = $product['parent_details'];
    //                             if ($parentAmazonProduct && isset($parentAmazonProduct['edited']['parent_sku']) && $parentAmazonProduct['edited']['parent_sku'] != '') {
    //                                 $productTypeParentSku = $parentAmazonProduct['edited']['parent_sku'];
    //                             } else if ($parentAmazonProduct && isset($parentAmazonProduct['sku']) && $parentAmazonProduct['sku'] != '') {
    //                                 $productTypeParentSku = $parentAmazonProduct['sku'];
    //                             } else {
    //                                 $productTypeParentSku = $parentAmazonProduct['source_product_id'];
    //                             }

    //                             $preparedContent = $this->setContent($categorySettings, $product, $targetShopId, self::PRODUCT_TYPE_CHILD, $productTypeParentSku, $operationType, $sourceMarketplace, false, $merchantDataSourceProduct);
    //                         } else if ($product['type'] == 'variation') {
    //                             $preparedContent = $this->setContent($categorySettings, $product, $targetShopId, self::PRODUCT_TYPE_PARENT, false, $operationType, $sourceMarketplace, false, $merchantDataSourceProduct);
    //                             if (empty($preparedContent)) {
    //                                 continue;
    //                             }
    //                         } else {
    //                             $preparedContent = $this->setContent($categorySettings, $product, $targetShopId, false, false, $operationType, $sourceMarketplace, false, $merchantDataSourceProduct);
    //                             if (in_array($product['source_product_id'], ['40475270840475', '40473394708635', '40473394675867', '40473394643099', '40473394577563'])) {
    //                                 unset($preparedContent['variation_theme']);
    //                             }
    //                         }

    //                         if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
    //                             $productErrorList[$product['source_product_id']] = $preparedContent['error'];
    //                         } else {
    //                             $time = date(DATE_ISO8601);
    //                             $preparedContent['time'] = $time;
    //                             unset($preparedContent['error']);
    //                             $feedContent[$categorySettings['primary_category']][$product['source_product_id']] =
    //                                 $preparedContent;
    //                             $feedContent[$categorySettings['primary_category']]['category'] =
    //                                 $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['category'];
    //                             $feedContent[$categorySettings['primary_category']]['sub_category'] =
    //                                 $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['sub_category'];
    //                             $feedContent[$categorySettings['primary_category']]['barcode_exemption'] =
    //                                 $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['barcode_exemption'];
    //                         }
    //                     } else {
    //                         $removeTagProductList[] = $product['source_product_id'];
    //                         $productErrorList[$product['source_product_id']] = ['AmazonError101'];
    //                     }

    //                     $addTagProductList = [];
    //                 }
    //                 // $this->di->getLog()->logContent('FeedContent = ' . print_r($feedContent, true), 'info', $logFile);

    //                 if (!empty($feedContent)) {
    //                     foreach ($feedContent as $category => $content) {
    //                         $specifics = [

    //                             'home_shop_id' => $targetShop['_id'],
    //                             'marketplace_id' => $targetShop['warehouses'][0]['marketplace_id'],
    //                             'shop_id' => $targetShop['remote_shop_id'],
    //                             'sourceShopId' => $sourceShopId,
    //                             'feedContent' => base64_encode(serialize($content)),
    //                             'user_id' => $userId,
    //                             'operation_type' => $operationType,
    //                             'localDelivery' => $merchantDataSourceProduct
    //                         ];

    //                         $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');
    //                         if ($response == null) {
    //                             $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');
    //                         }

    //                         if (isset($response['success']) && $response['success'] && !$localDelivery) {
    //                             unset($content['category']);
    //                             unset($content['sub_category']);
    //                             unset($content['barcode_exemption']);
    //                             $specifics['feedContent'] = $content;
    //                             $inventoryComponent->saveFeedInDb($content, 'product', $container_ids, $data['data']['params'], $alreadySavedActivities);
    //                         }
    //                         // $this->di->getLog()->logContent('Response = ' . print_r($response, true), 'info', $logFile);

    //                         $productSpecifics = $this->processResponse($response, $feedContent, \App\Amazon\Components\Common\Helper::PROCESS_TAG_UPLOAD_FEED, 'product', $targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, \App\Amazon\Components\Common\Helper::PROCESS_TAG_UPLOAD_PRODUCT, $localDelivery, $merchantDataSourceProduct);
    //                         // $this->di->getLog()->logContent('Process1 : '.json_encode($productSpecifics), 'info', 'ProductUpload.log');

    //                         $addTagProductList = array_merge($addTagProductList, $productSpecifics['addTagProductList']);
    //                         $productErrorList = array_merge($productErrorList, $productSpecifics['productErrorList']);
    //                     }

    //                     return $this->setResponse(\App\Amazon\Components\Common\Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);
    //                 } else {
    //                     $productSpecifics = $this->processResponse($response, $feedContent, \App\Amazon\Components\Common\Helper::PROCESS_TAG_UPLOAD_PRODUCT, 'product', $targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, false, $localDelivery, $merchantDataSourceProduct);
    //                     // $this->di->getLog()->logContent('Process2 : '.json_encode($productSpecifics), 'info', 'ProductUpload.log');

    //                     return $this->setResponse(\App\Amazon\Components\Common\Helper::RESPONSE_SUCCESS, count($addTagProductList), 0, count($productErrorList), 0);
    //                 }

    //                 // }
    //             } else {
    //                 if (isset($data['data']['params']['source_product_ids']) && !empty($data['data']['params']['source_product_ids'])) {
    //                     $this->removeTag([$data['data']['params']['source_product_ids']], [\App\Amazon\Components\Common\Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
    //                 }
    //                 return $this->setResponse(\App\Amazon\Components\Common\Helper::RESPONSE_TERMINATE, 'Currency of ' . $sourceMarketplace . ' and Amazon account are not same. Please fill currency settings from Global Price Adjustment in settings page.', 0, 0, 0);
    //             }
    //         } else {
    //             return $this->setResponse(\App\Amazon\Components\Common\Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0);
    //         }
    //     } else {
    //         if (isset($data['data']['params'])) {
    //             $params = $data['data']['params'];
    //             $sourceShopId = isset($params['source']['shopId']) ? $params['source']['shopId'] : "";
    //             $targetShopId = isset($params['target']['shopId']) ? $params['target']['shopId'] : "";
    //             $userId = $params['user_id'] ?? $this->_user_id;
    //             if (isset($params['source_product_ids']) && !empty($params['source_product_ids'])) {
    //                 $this->removeTag([$params['source_product_ids']], [\App\Amazon\Components\Common\Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
    //             }
    //             if (isset($params['next_available']) && $params['next_available']) {
    //                 return $this->setResponse(\App\Amazon\Components\Common\Helper::RESPONSE_SUCCESS, "", 0, 0, 0);
    //             }
    //         }
    //         return $this->setResponse(\App\Amazon\Components\Common\Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0);
    //     }
    // }

     public function validateFeed($datas, $userId = null, $addtionalData = null)
    {
        if (is_array($datas)) {
            $categoryAttributes = [];
            $containerId = "";
            $sourceShopId = "";
            $targetShopId = "";
            $edited = [];
            foreach ($datas as $data) {
                $containerId = $data['container_id'] ?? "";
                $sourceProductId = $data['source_product_id'] ?? "";
                $sourceShopId = $data['source_shop_id'] ?? "";
                $targetShopId = $data['shop_id'] ?? "";
                $categorySettings = $data['category_settings'] ?? [];
                $description = $data['description'] ?? [];
                $edited = $data;
                // print_r($addtionalData);die;
                if (!empty($categorySettings)) {
                    if (!empty($categorySettings['variants_json_attributes_values'])) {
                        $categoryAttributes['variants_json_attributes_values'] = $categorySettings['variants_json_attributes_values'];
                    }

                    if (!empty($categorySettings['product_type'])) {
                        $categoryAttributes['product_type'] = $categorySettings['product_type'];
                    }

                    if (!empty($categorySettings['attributes_mapping']['jsonFeed'])) {
                        $categoryAttributes['attributes_mapping'] = $categorySettings['attributes_mapping']['jsonFeed'];
                    }

                    if (!empty($categorySettings['attributes_mapping']['marketplace_id'])) {
                        $categoryAttributes['marketplace_id'] = $categorySettings['attributes_mapping']['marketplace_id'];
                    }

                    if (!empty($categorySettings['attributes_mapping']['language_tag'])) {
                        $categoryAttributes['language_tag'] = $categorySettings['attributes_mapping']['language_tag'];
                    }

                    if (!empty($categorySettings['barcode_exemption'])) {
                        $categoryAttributes['barcode_exemption'] = $categorySettings['barcode_exemption'];
                    }

                    if (!empty($categorySettings['browser_node_id'])) {
                        $categoryAttributes['browser_node_id'] = $categorySettings['browser_node_id'];
                    }
                    if (!empty($categorySettings['variants_value_mapping'])) {
                        $categoryAttributes['variantsValueMapping'] = $categorySettings['variants_value_mapping'];
                    }

                    if (!empty($description)) {
                        $edited['description'] = $description;
                    }
                } else {
                    return ['success' => true, 'msg' => 'Amazon Category is not selected'];
                }
            }
            if($categoryAttributes['product_type'] == "PRODUCT"){

                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
                $products =  $productCollection->find(['user_id' => $userId, 'container_id' => $containerId, 'shop_id' => $targetShopId,'status' => 'NotListed:Offer'])->toArray();
                $products = json_decode(json_encode($products),true);
                if(!empty($products)){
                    $updatedSourceProductId = array_column($products,'source_product_id');
                }
            }
            // $products = json_decode(json_encode($products), true);
            $data = $addtionalData;
            $data['source_product_ids'] = [$sourceProductId];
            $data['activePage'] = 1;
            $data['useRefinProduct'] = true;
            $data['operationType'] = "product_upload";
            $data['limit'] = 2;
            $data['create_queued_tasks'] = true;
            $data['create_notification'] = true;
            $data['projectData'] = ["marketplace" => 0];
            $data['usePrdOpt']  = true;
            $data['user_id'] = $userId;
            $parent = 0;
            $child = 0;


            // $data=json_decode('{"target":{"marketplace":"amazon","shopId":"582"},"source":{"marketplace":"shopify","shopId":"580"},"source_product_ids":["7714185183286"],"activePage":1,"useRefinProduct":true,"operationType":"product_upload","limit":1000,"create_queued_tasks":true,"create_notification":true,"projectData":{"marketplace":0},"usePrdOpt":true,"user_id":"6726600aa77bc7b5cd0ca574"}',true);
            $productProfile = $this->di->getObjectManager()->get('App\Connector\Components\Profile\GetProductProfileMerge');
            $response = $productProfile->getproductsByProductIds($data, true);
            $response = json_decode(json_encode($response), true);
            // print_r($response);die;
            $products = $response['data']['rows'] ?? [];
            $allParentDetails = $response['data']['all_parent_details'];
            $allProfileInfo = $response['data']['all_profile_info'];
            $otherAttributeError = [];

            // print_r(json_encode($response));die;
            $ListingErrorHelper = $this->di->getObjectManager()->get(ListingsError::class);

            $productTypeHelper = $this->di->getObjectManager()->get(ListingHelper::class);
            $parentDetails = [];
            $feedContent = [];

            $temp = $edited;
   
            foreach ($products as $product) {
                // print_r($product);die;
                if(empty($edited)){
                    $edited = $temp;
                }
                $preparedContent = [];
                if (isset($product['edited']['asin']) && isset($product['edited']['status'])) {
                    $edited['asin'] = $product['edited']['asin'];
                    $edited['status'] = $product['edited']['status'];
                }
                if(isset($edited['status']) && $edited['status'] !='NotListed:Offer') {

                    return ['success' => true, 'msg' => 'No need to validate'];
                }
                // if(isset($categorySettings['product_type']) && $categorySettings['product_type'] == "PRODUCT" && !isset($edited['status']))
                // {
                //     continue;
                // }
                $type = "";
                if ($product['type'] == "variation") {
                    $type = self::PRODUCT_TYPE_PARENT;
                    $parent++;
                }
                if (isset($product['parent_details']) && is_string($product['parent_details'])) {
                    $product['parent_details'] = $allParentDetails[$product['parent_details']];
                    $type = self::PRODUCT_TYPE_CHILD;
                   
                    $child++;
                }
                if (isset($prodcut['profile_info']) && is_string($product['profile_info'])) {
                    $product['profile_info'] = $allProfileInfo[$product['profile_info']];
                }
                if (isset($product['edited']['category_settings']['variants_json_attributes_values'])) {
                    $categoryAttributes['variants_json_attributes_values'] = $product['edited']['category_settings']['variants_json_attributes_values'];
                    $edited['category_settings']['variants_json_attributes_values'] = $product['edited']['category_settings']['variants_json_attributes_values'];
                }

                if (!empty($edited)) {
                    if (isset($product['parent_details'])) {
                        $product['parent_details']['edited'] = $edited;
                        unset($edited['sku']);
                        unset($edited['title']);
                    }
                    $product['edited'] = $edited;
                    
                }
             
                if(!empty($categoryAttributes)){

                    $sourceMarketplace = $product['source_marketplace'];
                    $additionalData['categorySettings'] = $categoryAttributes;
                $additionalData['product']  = $product;
                $additionalData['sourceMarketplace'] = $sourceMarketplace;
                $additionalData['targetShopId'] = $targetShopId;
                $productTypeHelper->init($additionalData);
                // if($product['type'] == "variation"){
                //       print_r($product['edited']);die;   
                // }
                $preparedContent = $productTypeHelper->setContentJsonListing($categoryAttributes, $product,  $type, self::OPERATION_TYPE_UPDATE, $sourceMarketplace);
                
                if (isset($preparedContent['error'])) {
                    foreach ($preparedContent['error'] as $err) {
                        if ($err != 'AmazonError101') {
                          
                            $otherAttributeError[$err] = ($product['edited']['sku'] ?? $product['sku'] ?? $product['source_product_id']) . ':'.$ListingErrorHelper->getErrorByCode($err);
                        }
                    }
                    unset($preparedContent['error']);
                }
                if (isset($preparedContent['json_product_type']) && !empty($preparedContent['json_product_type'])) {
                    if (isset($preparedContent['feedToSave'])) {
                        unset($preparedContent['feedToSave']);
                    }
                }
                if(!empty($preparedContent)){

                    $productType = $preparedContent['json_product_type'];
                    $feedContent[$productType][$product['source_product_id']]['sku'] = $product['edited']['sku'] ?? $product['sku'] ?? $product['source_product_id'];
                    $feedContent[$productType]['json_product_type'] = $productType;
                    $feedContent[$productType]['language_tag'] = $preparedContent['language_tag'] ?? [];
                    $feedContent[$productType]['marketplace_id'] = $preparedContent['marketplace_id'] ?? [];
                unset($preparedContent['language_tag']);
                unset($preparedContent['marketplace_id']);

                $feedContent[$productType][$product['source_product_id']] = base64_encode(serialize($preparedContent));
                $validateProductArr[$product['source_product_id']] = ['container_id' => $product['container_id']];
                $edited = [];
                if ($parent > 1 && $child > 1) {
                    break;
                }
            }
            }

        }


            $jsonHelper = $this->di->getObjectManager()->get(ListingHelper::class);
            foreach ($feedContent as $productType => $content) {
                if (isset($content['json_product_type'])) {
                    $result = $jsonHelper->validateJsonFeed($content, $productType);
                    // print_r($result);die;
                    if (isset($result['success'], $result['errors']) && !empty($result['errors'])) {
                        foreach ($result['errors'] as $sourceProductId => $errorArray) {
                            if (!empty($validateProductArr[$sourceProductId])) {
                                $jsonProductErrorList[] = $errorArray[0]['AmazonValidationError'];

                                unset($feedContent[$productType][$sourceProductId]);
                            }
                        }

                        return ['success' => false, 'response' => $jsonProductErrorList, 'other_attributes_error' => $otherAttributeError];
                    }
                    return ['success' => true, 'msg' => 'Feed is valid'];
                }
                if (!empty($otherAttributeError)) {
                    return ['success' => false, 'response' => [], 'other_attributes_error' => $otherAttributeError];
                }
            }
        } else {
            return ['success' => true, 'msg' => 'Data given is incorrect'];
        }
    }

    public function validateFeedForProfile($data, $userId = null)
    {
        $categoryAttributes = [];
        $edited = [];

        $containerId = $data['container_id'] ?? "";
        $sourceProductId = $data['source_product_id'] ?? "";
        $sourceShopId = $data['source_shop_id'] ?? "";
        $targetShopId = $data['shop_id'] ?? "";
        $categorySettings = $data['category_settings'] ?? [];

        $edited = $data;

        if (empty($categorySettings)) {
            return ['success' => false, 'message' => 'Amazon Category is not selected'];
        }

        if (!empty($categorySettings['product_type'])) {
            $categoryAttributes['product_type'] = $categorySettings['product_type'];
        }

        if (!empty($categorySettings['attributes_mapping']['jsonFeed'])) {
            $categoryAttributes['attributes_mapping'] = $categorySettings['attributes_mapping']['jsonFeed'];
        }

        if (!empty($categorySettings['attributes_mapping']['marketplace_id'])) {
            $categoryAttributes['marketplace_id'] = $categorySettings['attributes_mapping']['marketplace_id'];
        }

        if (!empty($categorySettings['attributes_mapping']['language_tag'])) {
            $categoryAttributes['language_tag'] = $categorySettings['attributes_mapping']['language_tag'];
        }

        // Set default values for profile attributes, including barcode exemption and other required fields        $categoryAttributes['barcode_exemption'] = true;
        $edited['description'] = "Default description of the product";
        $edited['price'] = "100";
        $edited['title'] = "Default title of product";
        $edited['fulfillment_type'] = "FBM";
        $edited['asin'] = "B08XYZ1234"; //default value to bypass offer validation
        $edited['barcode_type'] = "GTIN";

        $categoryAttributes['barcode_exemption'] = true;

        if (!empty($categorySettings['browser_node_id'])) {
            $categoryAttributes['browser_node_id'] = $categorySettings['browser_node_id'];
        }

        function getMappableAmazonAttributePaths(array $data, string $currentPath = ''): array
        {
            $paths = [];

            foreach ($data as $key => $value) {
                $newPath = $currentPath === '' ? $key : "$currentPath.$key";

                if (is_array($value)) {
                    // Recursively search deeper
                    $paths = array_merge($paths, getMappableAmazonAttributePaths($value, $newPath));
                }

                // Check conditions for attribute_value and recommendation_exists
                if (
                    is_array($value) &&
                    isset($value['attribute_value']) && $value['attribute_value'] === 'shopify_attribute' &&
                    isset($value['recommendation_exists']) && $value['recommendation_exists'] &&
                    isset($value['shopify_attribute']) && !in_array($value['shopify_attribute'], ['title', 'description', 'handle', 'type', 'sku', 'barcode', 'tags', 'price', 'quantity', 'brand'])
                ) {
                    $paths[] = $newPath;
                }
            }

            return $paths;
        }

        $jsonFeed = $categoryAttributes['attributes_mapping'];

        $mappableAmazonAttributePaths = getMappableAmazonAttributePaths($jsonFeed);

        if (!empty($mappableAmazonAttributePaths)) {
            $getJsonAttrParams = [
                'user_id' => $userId ?? $this->di->getUser()->id,
                'shop_id' => $data['shop_id'] ?? null,
                'marketplace_id' => $categoryAttributes['marketplace_id'] ?? null,
                'product_type' => $categorySettings['product_type'] ?? null,
            ];

            $attributesData = $this->di->getObjectManager()->get(\App\Amazon\Components\Template\CategoryAttributeCache::class)->getJsonAttributesFromDB($getJsonAttrParams, true);

            $bulkEditExport = $this->di->getObjectManager()->get(\App\Amazon\Components\Template\BulkAttributesEdit\Export\Export::class);

            if (!empty($attributesData['data'])) {
                $schemaContent = json_decode($attributesData['data']['schema'], true);
                foreach ($mappableAmazonAttributePaths as $attributePath) {
                    $attributePathArray = explode('.', $attributePath);
                    $attributeRoot = $attributePathArray[0] ?? null;
                    $attributePropertyDef = $schemaContent['properties'][$attributeRoot] ?? null;
                    if (!empty($attributePropertyDef)) {
                        $attrRootDef = $bulkEditExport->extractDropdownOptionsFromSchema($attributePropertyDef, $attributePathArray);
                        $firstEnum = $attrRootDef['enum'][0] ?? null;
                        if ($firstEnum !== null) {
                            $target = &$jsonFeed;
                            $replacementArray = [
                                "attribute_value" => "recommendation",
                                "type" => $attrRootDef['type'] ?? "",
                                "recommendation" => $firstEnum
                            ];

                            foreach ($attributePathArray as $index => $path) {
                                if (!isset($target[$path])) {
                                    continue;
                                }
                                // If we are at the last level, replace the array
                                if ($index === count($attributePathArray) - 1) {
                                    $target[$path] = $replacementArray;
                                } else {
                                    $target = &$target[$path];
                                }
                            }
                        }
                    }
                }
                $categoryAttributes['attributes_mapping'] = $jsonFeed;
            }
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        $products = $productCollection->find(['user_id' => $userId, 'container_id' => $containerId, 'shop_id' => $sourceShopId])->toArray();

        $productTypeHelper = $this->di->getObjectManager()->get(ListingHelper::class);
        $listingsErrorComponent = $this->di->getObjectManager()->get(\App\Amazon\Components\Listings\Error::class);

        $parentDetails = [];
        $feedContent = [];
        foreach ($products as $product) {
            $preparedContent = [];
            $type = "";
            if ($product['type'] == "variation") {
                $parentDetails = $product;
                continue;
            }

            if (!empty($parentDetails)) {
                $product['parent_details'] = $parentDetails;
                $type = self::PRODUCT_TYPE_CHILD;
            }

            if (!empty($edited)) {
                $product['edited'] = $edited;
            }

            $sourceMarketplace = $product['source_marketplace'];
            $additionalData['categorySettings'] = $categoryAttributes;
            $additionalData['product'] = $product;
            $additionalData['sourceMarketplace'] = $sourceMarketplace;
            $additionalData['targetShopId'] = $targetShopId;
            $productTypeHelper->init($additionalData);
            $preparedContent = $productTypeHelper->setContentJsonListing($categoryAttributes, $product, $type, self::OPERATION_TYPE_UPDATE, $sourceMarketplace);

            if (!empty($preparedContent['error'])) {
                $errorMsg = $listingsErrorComponent->getErrorByCode($preparedContent['error'][0] ?? "");
                return ['success' => false, 'message' => $errorMsg, 'return_msg' => $errorMsg];
            }

            if (!empty($preparedContent['json_product_type']) && isset($preparedContent['feedToSave'])) {
                unset($preparedContent['feedToSave']);
            }

            $productType = $preparedContent['json_product_type'] ?? null;
            $feedContent[$productType][$product['source_product_id']]['sku'] = $product['edited']['sku'] ?? $product['sku'] ?? $product['source_product_id'];
            $feedContent[$productType]['json_product_type'] = $productType;
            $feedContent[$productType]['language_tag'] = $preparedContent['language_tag'] ?? [];
            $feedContent[$productType]['marketplace_id'] = $preparedContent['marketplace_id'] ?? [];
            unset($preparedContent['language_tag']);
            unset($preparedContent['marketplace_id']);

            $feedContent[$productType][$product['source_product_id']] = base64_encode(serialize($preparedContent));
            $validateProductArr[$product['source_product_id']] = ['container_id' => $product['container_id']];
            break;
        }

        $jsonHelper = $this->di->getObjectManager()->get(ListingHelper::class);
        foreach ($feedContent as $productType => $content) {
            if (isset($content['json_product_type'])) {
                $result = $jsonHelper->validateJsonFeed($content, $productType);
                if (isset($result['success'], $result['errors']) && !empty($result['errors'])) {
                    foreach ($result['errors'] as $sourceProductId => $errorArray) {
                        if (!empty($validateProductArr[$sourceProductId])) {
                            $jsonProductErrorList[] = $errorArray[0]['AmazonValidationError'];
                            unset($feedContent[$productType][$sourceProductId]);
                        }
                    }

                    return ['success' => false, 'response' => $jsonProductErrorList];
                }
                return ['success' => true, 'message' => 'Feed is valid'];
            }
        }
        return ['success' => false, 'message' => 'Something went wrong while validating.'];
    }

    public function syncProduct($data, $operationType = self::OPERATION_TYPE_PARTIAL_UPDATE)
    {
        $feedContent = [];
        if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
            $preparedContent = [];
            $userId = $data['data']['params']['user_id'] ?? $this->_user_id;
            $targetShopId = $data['data']['params']['target']['shopId'];
            $sourceShopId = $data['data']['params']['source']['shopId'];
            $targetMarketplace = $data['data']['params']['target']['marketplace'];
            $sourceMarketplace = $data['data']['params']['source']['marketplace'];
            $date = date('d-m-Y');
            $logFile = "amazon/{$userId}/{$sourceShopId}/SyncProduct/{$date}.log";
            $productErrorList = [];
            $response = [];
            $active = false;
            $categorySettings = null;
            $removeTagProductList = [];
            $specificData = $data['data']['params'];
            $specificData['type'] = 'product';
            $categoryErrorProductList = [];
            $preparedContent = [];
            $container_ids = [];
            $contentInActivity = [];
            $addTagProductList = [];
            $time = date(DATE_ISO8601);
            $productTypeHelper = $this->di->getObjectManager()->get(ListingHelper::class);


            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $targetShop = $user_details->getShop($targetShopId, $userId);
            $sourceShop = $user_details->getShop($sourceShopId, $userId);
            $inventoryComponent = $this->di->getObjectManager()->get(Inventory::class);

            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
            $activeAccounts = [];
            foreach ($targetShop['warehouses'] as $warehouse) {
                if ($warehouse['status'] == "active") {
                    $activeAccounts = $warehouse;
                    $marketplaceId = $warehouse['marketplace_id'];
                }
            }

            if (!empty($activeAccounts)) {
                $connector = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
                $currencyCheck = $connector->checkCurrency($userId, $targetMarketplace, $sourceMarketplace, $targetShopId, $sourceShopId);
                $this->configObj->setUserId($userId);
                $this->configObj->setTarget($targetMarketplace);
                $this->configObj->setTargetShopId($targetShopId);
                $this->configObj->setSourceShopId($sourceShopId);

                if (!$currencyCheck) {
                    $this->configObj->setGroupCode('currency');
                    $sourceCurrency = $this->configObj->getConfig('source_currency');
                    $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);
                    $amazonCurrency = $this->configObj->getConfig('target_currency');
                    $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);
                    if (isset($sourceCurrency[0]['value']) && isset($amazonCurrency[0]['value']) && $sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
                        $currencyCheck = true;
                    } else {
                        $amazonCurrencyValue = $this->configObj->getConfig('target_value');
                        $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
                        if (isset($amazonCurrencyValue[0]['value'])) {
                            $currencyCheck = $amazonCurrencyValue[0]['value'];
                        }
                    }
                }
                if ($currencyCheck) {
                    $products = $data['data']['rows'];
                    foreach ($products as $product) {
                        $alreadySavedActivities[$product['source_product_id']] = $product['edited']['last_activities'] ?? [];
                        $sync = [];
                        if ($operationType == self::OPERATION_TYPE_PARTIAL_UPDATE) {
                            $sync = $this->getSyncStatus($product);

                            if (isset($sync['priceInvSyncList']) && $sync['priceInvSyncList']) {
                                $priceInvSyncList['source_product_ids'][] = $product['source_product_id'];
                            }

                            if (isset($sync['imageSyncProductList']) && $sync['imageSyncProductList']) {
                                $imageSyncProductList['source_product_ids'][] = $product['source_product_id'];
                            }

                            if (isset($sync['productSyncProductList']) && $sync['productSyncProductList']) {
                                $categorySettings = $this->getUploadCategorySettings($product);
                                $categorySettings = json_decode(json_encode($categorySettings), true);

                                $productSyncProductList['source_product_ids'][] = $product['source_product_id'];
                            }

                            if (!is_null($categorySettings)) {
                                if (!empty($categorySettings['errors'])) {
                                    $productErrorList[$product['source_product_id']] = $categorySettings['errors'];
                                } elseif (!empty($categorySettings)) {
                                    $container_ids[$product['source_product_id']] = $product['container_id'];

                                    if ($product['type'] == 'variation') {
                                        $type = self::PRODUCT_TYPE_PARENT;
                                    } elseif ($product['visibility'] == 'Catalog and Search') {
                                        $type = null;
                                    } else {
                                        $type = self::PRODUCT_TYPE_CHILD;
                                    }

                                    $additionalData['categorySettings'] = $categorySettings;
                                    $additionalData['product']  = $product;
                                    $additionalData['sourceMarketplace'] = $sourceMarketplace;
                                    $additionalData['targetShopId'] = $targetShopId;
                                    $productTypeHelper->init($additionalData);
                                    $preparedContent = $productTypeHelper->setContentJsonListing($categorySettings, $product, $type, $operationType, $sourceMarketplace);

                                    if (empty($preparedContent)) {
                                        continue;
                                    }
                                    if (isset($preparedContent['feedToSave'])) {
                                        $preparedContent['feedToSave']['time'] = $time;
                                        $contentInActivity[$product['source_product_id']] = $preparedContent['feedToSave'];
                                        unset($preparedContent['feedToSave']);
                                    }
                                    if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
                                        $jsonProductErrorList[$product['source_product_id']] = [
                                            'error' => $preparedContent['error'],
                                            'container_id' => $product['container_id']
                                        ];
                                        $processTags = $productTypeHelper->getProgressTag($product, $product['source_product_id'], $targetShopId, $operationType);
                                        if (is_array($processTags)) {
                                            $jsonProductErrorList[$product['source_product_id']]['process_tags'] = $processTags;
                                        }

                                        $preparedContent = [];
                                        continue;
                                    }
                                    $productType = $preparedContent['json_product_type'];

                                    $feedContent[$productType][$product['source_product_id']]['sku'] = $product['edited']['sku'] ?? $product['sku'] ?? $product['source_product_id'];
                                    $feedContent[$productType]['json_product_type'] = $productType;
                                    $feedContent[$productType]['language_tag'] = $preparedContent['language_tag'];
                                    $feedContent[$productType]['marketplace_id'] = $preparedContent['marketplace_id'];
                                    unset($preparedContent['language_tag']);
                                    unset($preparedContent['marketplace_id']);
                                    $feedContent[$productType][$product['source_product_id']] = base64_encode(serialize($preparedContent));
                                }
                            } else {
                                $container_ids[$product['source_product_id']] = $product['container_id'];

                                if (in_array($product['source_product_id'], $priceInvSyncList['source_product_ids'])) {
                                    if ($product['type'] == "variation") {
                                        continue;
                                    } else {

                                        $additionalData['categorySettings']['marketplace_id'] = $marketplaceId;
                                        $additionalData['categorySettings']['product_type'] = 'PRODUCT';
                                        $additionalData['product']  = $product;
                                        $additionalData['sourceMarketplace'] = $sourceMarketplace;
                                        $additionalData['targetShopId'] = $targetShopId;
                                        $productTypeHelper->init($additionalData);
                                        $preparedContent = $productTypeHelper->priceInventoryFeed($product, 'child');

                                        if (isset($preparedContent['jsonFeedData'])) {
                                            $preparedContent['jsonFeedData']['time'] = $time;
                                            $contentInActivity[$product['source_product_id']] = $preparedContent['jsonFeedData'];
                                            unset($preparedContent['jsonFeedData']);
                                        }

                                        // $feedContent['PRODUCT']['json_product_type'] = 'PRODUCT';
                                        // $feedContent['PRODUCT']['language_tag'] = 'en_US';
                                        // $feedContent['PRODUCT']['marketplace_id'] = $marketplaceId;
                                        // $feedContent['PRODUCT'][$product['source_product_id']] = base64_encode(serialize($preparedContent));

                                    }
                                }
                                if (isset($product['source_product_id']) && isset($imageSyncProductList['source_product_ids']) && in_array($product['source_product_id'], $imageSyncProductList['source_product_ids'])) {
                                    $additionalData['categorySettings']['marketplace_id'] = $marketplaceId;
                                    $additionalData['categorySettings']['product_type'] = 'PRODUCT';
                                    $additionalData['product']  = $product;
                                    $additionalData['sourceMarketplace'] = $sourceMarketplace;
                                    $additionalData['targetShopId'] = $targetShopId;
                                    $productTypeHelper->init($additionalData);
                                    $ImageContent = $productTypeHelper->getImagesUrl('child');
                                    if (isset($ImageContent['main_product_image_locator'][0]['media_location'])) {
                                        $contentInActivity[$product['source_product_id']]['main_image'] = $ImageContent['main_product_image_locator'][0]['media_location'];
                                    }
                                    if (!empty($preparedContent)) {
                                        $completeFeed = array_merge($preparedContent, $ImageContent);
                                    } else {
                                        $completeFeed = $ImageContent;
                                    }
                                }
                                $feedContent['PRODUCT']['json_product_type'] = 'PRODUCT';
                                //  $feedContent['PRODUCT']['language_tag'] = 'en_US';
                                //  $feedContent['PRODUCT']['marketplace_id'] = $marketplaceId;
                                $feedContent['PRODUCT'][$product['source_product_id']] = base64_encode(serialize($completeFeed));
                            }
                        }
                    }

                    if (!empty($feedContent)) {
                        foreach ($feedContent as $content) {

                            $permission = $commonHelper->remotePermissionToSendRequest(
                                'handshake',
                                ["remote_shop_id" => $targetShop['remote_shop_id'], "action_name" => $operationType],
                                'POST'
                            );

                            if (!$permission['success']) {
                                return ["CODE" => "REQUEUE", 'time_out' => 10, 'message' => "Please wait 10 minutes before attempting the process again.", 'success' => false];
                            }

                            $specifics = [
                                'home_shop_id' => $targetShop['_id'],
                                'marketplace_id' => $targetShop['warehouses'][0]['marketplace_id'],
                                'shop_id' => $targetShop['remote_shop_id'],
                                'sourceShopId' => $sourceShopId,
                                'feedContent' => base64_encode(serialize($content)),
                                'user_id' => $userId,
                                'operation_type' => $operationType,
                                'handshake_token' => $permission['token'],
                                'uploadThroughS3' => true,
                                'unified_json_feed' => true
                            ];

                            $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');

                            if (isset($response['success']) && $response['success']) {
                                if (!empty($contentInActivity)) {
                                    $res = $inventoryComponent->saveFeedInDb($contentInActivity, 'product', $container_ids, $data['data']['params'], $alreadySavedActivities);
                                }
                                $this->di->getLog()->logContent('response from new remote success true: ', 'info', $logFile);
                                unset($content['category']);
                                unset($content['sub_category']);
                                unset($content['barcode_exemption']);
                            }
                            $specificData['marketplaceId']  = $marketplaceId;
                            $specificData['tag'] = Helper::PROCESS_TAG_UPDATE_FEED;
                            $specificData['unsetTag'] = Helper::PROCESS_TAG_SYNC_PRODUCT;
                            $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);
                            $addTagProductList = array_merge($addTagProductList, $productSpecifics['addTagProductList']);
                            $productErrorList = array_merge($productErrorList, $productSpecifics['productErrorList']);
                            return $this->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0, true);
                        }
                    }
                }
            }
        }
        if (isset($data['data']['params'])) {
            $params = $data['data']['params'];
            $sourceShopId = $params['source']['shopId'] ?? "";
            $targetShopId = $params['target']['shopId'] ?? "";
            $userId = $params['user_id'] ?? $this->_user_id;
            if (isset($params['source_product_ids']) && !empty($params['source_product_ids'])) {
                $this->removeTag([$params['source_product_ids']], [Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
            }

            if (isset($params['next_available']) && $params['next_available']) {
                return $this->setResponse(Helper::RESPONSE_SUCCESS, "", 0, 0, 0, true);
            }
        }
        return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0, false);
    }

public function uploadNew($data, $operationType = self::OPERATION_TYPE_UPDATE)
    {
        $date = date('d-m-Y');
        $productInsert = [];
        $logFile = "amazon/NewProductUpload/{$date}.log";
        // if($this->di->getUser()->id == "652e75669f424b50300561b3"){
        //     $this->di->getLog()->logContent('Data1' . print_r($data, true), 'info', 'INUpload.log');
        // }

        $feedContent = [];
        if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
            // if($this->di->getUser()->id == "652e75669f424b50300561b3"){
            //     $this->di->getLog()->logContent('Data2' . print_r($data, true), 'info', 'INUpload.log');
            // }
            $preparedContent = [];
            $userId = $data['data']['params']['user_id'];
            $targetShopId = $data['data']['params']['target']['shopId'];
            $sourceShopId = $data['data']['params']['source']['shopId'];
            $targetMarketplace = $data['data']['params']['target']['marketplace'];
            $sourceMarketplace = $data['data']['params']['source']['marketplace'];
            $productErrorList = [];
            $jsonProductErrorList = [];
            $container_ids = [];
            $specificData = $data['data']['params'];
            $specificData['type'] = 'product';
            $alreadySavedActivities = [];
            $response = [];
            $active = false;
            $contentInActivity = [];
            $logFile = "amazon/{$userId}/{$sourceShopId}/ProductUpload-S3/{$date}.log";
            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $targetShop = $user_details->getShop($targetShopId, $userId);
            $sourceShop = $user_details->getShop($sourceShopId, $userId);
            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
            $inventoryComponent = $this->di->getObjectManager()->get(Inventory::class);
            $multiplier = false;
            $marketplaceId = "";
            $activeAccounts = [];
            $changedProducts = [];
            $errorProductChangable = [];
            $productsChanged = [];
            $addTagProductList = [];
            $allParentDetails = $data['data']['all_parent_details'] ?? [];
            $allProfileInfo = $data['data']['all_profile_info'] ?? [];

            foreach ($targetShop['warehouses'] as $warehouse) {
                if ($warehouse['status'] == "active") {
                    $activeAccounts = $warehouse;
                    $marketplaceId = $warehouse['marketplace_id'] ?? [];
                    break;
                }
            }

            if (!empty($activeAccounts)) {
                $connector = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
                $currencyCheck = $connector->checkCurrency($userId, $targetMarketplace, $sourceMarketplace, $targetShopId, $sourceShopId);
                $this->configObj->setUserId($userId);
                $this->configObj->setTarget($targetMarketplace);
                $this->configObj->setTargetShopId($targetShopId);
                $this->configObj->setSourceShopId($sourceShopId);

                if (!$currencyCheck) {
                    $this->configObj->setGroupCode('currency');
                    $sourceCurrency = $this->configObj->getConfig('source_currency');
                    $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);
                    $amazonCurrency = $this->configObj->getConfig('target_currency');
                    $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);
                    if (isset($sourceCurrency[0]['value']) && isset($amazonCurrency[0]['value']) && $sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
                        $currencyCheck = true;
                    } else {
                        $amazonCurrencyValue = $this->configObj->getConfig('target_value');
                        $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
                        if (isset($amazonCurrencyValue[0]['value'])) {
                            $currencyCheck = $amazonCurrencyValue[0]['value'];
                        }
                    }
                }

                $this->di->getLog()->logContent('operation type' . print_r($operationType, true), 'info', $logFile);
                if ($currencyCheck) {
                    $this->currency = $currencyCheck;
                    $products = $data['data']['rows'];
                    $validateProductArr = [];
                    $sourceProductIds = array_column($products, 'source_product_id');
                    $productTypeHelper = $this->di->getObjectManager()->get(ListingHelper::class);
                    $productTypeHelper->resetclassVariables();
                    foreach ($products as $product) {
                        try {
                            $product = json_decode(json_encode($product), true);
                            if (isset($product['parent_details']) && is_string($product['parent_details'])) {
                                $product['parent_details'] = $allParentDetails[$product['parent_details']];
                            }
                            if (isset($product['profile_info']['$oid'])) {
                                $product['profile_info'] = $allProfileInfo[$product['profile_info']['$oid']];
                            }
                            if($operationType == self::OPERATION_TYPE_UPDATE && isset($product['edited']['status']) && in_array($product['edited']['status'], ['Active','Inactive','Incomplete'])){
                                $productErrorList[$product['source_product_id']] = ['AmazonError1099'];
                                continue;
                            }

                            else if($operationType == self::OPERATION_TYPE_PARTIAL_UPDATE && (!isset($product['edited']['status']) || isset($product['edited']['status'])  && in_array($product['edited']['status'], ['Not Listed','Not Listed: Offer']))){
                                $productErrorList[$product['source_product_id']] = ['AmazonError1098'];
                                continue;
                            }
                            $categorySettings = [];
                            $type = false;
                            $sync = [];
                            $container_ids[$product['source_product_id']] = $product['container_id'];
                            if ($operationType == self::OPERATION_TYPE_UPDATE) {
                                $categorySettings = $this->getUploadCategorySettings($product);
                                $categorySettings = json_decode(json_encode($categorySettings), true);
                                $this->removeTag([$product['source_product_id']], [Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
                            } else if ($operationType == self::OPERATION_TYPE_PARTIAL_UPDATE) {
                                $sync = $this->getSyncStatus($product);

                                if (isset($sync['priceInvSyncList']) && $sync['priceInvSyncList']) {
                                    $priceInvSyncList['source_product_ids'][] = $product['source_product_id'];
                                }

                                if (isset($sync['imageSyncProductList']) && $sync['imageSyncProductList']) {
                                    $imageSyncProductList['source_product_ids'][] = $product['source_product_id'];
                                }

                                if (isset($sync['productSyncProductList']) && $sync['productSyncProductList']) {
                                    $categorySettings = $this->getPartialUpdateCategorySettings($product);

                                    // $firstChild = reset($childArray);
                                    $productSyncProductList['source_product_ids'][] = $product['source_product_id'];
                                }

                                $this->removeTag([$product['source_product_id']], [Helper::PROCESS_TAG_SYNC_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
                            }

                            $container_ids[$product['source_product_id']] = $product['container_id'];
                            $alreadySavedActivities[$product['source_product_id']] = $product['edited']['last_activities'] ?? [];
                            if (!empty($categorySettings['errors'])) {
                                $productErrorList[$product['source_product_id']] = $categorySettings['errors'];
                            } elseif (!empty($categorySettings)) {
                                $preparedContent = [];
                                //saving container_id in array
                                $container_ids[$product['source_product_id']] = $product['container_id'];
                                $alreadySavedActivities[$product['source_product_id']] = $product['edited']['last_activities'] ?? [];
                                if (isset($categorySettings['product_type']) && !empty($categorySettings['product_type'])) {
                                    if ($product['type'] == 'variation') {
                                        $type = self::PRODUCT_TYPE_PARENT;
                                    } elseif ($product['visibility'] == 'Catalog and Search') {
                                        $type = null;
                                    } else {
                                        $type = self::PRODUCT_TYPE_CHILD;
                                    }

                                    $additionalData['categorySettings'] = $categorySettings;
                                    $additionalData['product']  = $product;
                                    $additionalData['sourceMarketplace'] = $sourceMarketplace;
                                    $additionalData['targetShopId'] = $targetShopId;
                                    $productTypeHelper->init($additionalData);
                                    $preparedContent = $productTypeHelper->setContentJsonListing($categorySettings, $product, $type, $operationType, $sourceMarketplace);
                                    if (empty($preparedContent)) {
                                        continue;
                                    }
                                    if ($userId != "658123d231e8d0d4970aad22") {

                                        if (isset($preparedContent['product_description']) && !mb_check_encoding($preparedContent['product_description'], 'UTF-8')) {
                                            $preparedContent['product_description'] = mb_convert_encoding($preparedContent['product_description'], 'UTF-8', 'ISO-8859-1');
                                        }
                                    }

                                    if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
                                        $jsonProductErrorList[$product['source_product_id']] = [
                                            'error' => $preparedContent['error'],
                                            'container_id' => $product['container_id']
                                        ];
                                        $processTags = $productTypeHelper->getProgressTag($product, $product['source_product_id'], $targetShopId, $operationType);
                                        if (is_array($processTags)) {
                                            $jsonProductErrorList[$product['source_product_id']]['process_tags'] = $processTags;
                                        }

                                        $preparedContent = [];
                                        continue;
                                    }
                                }

                                if (isset($preparedContent['operation-type'])) {
                                    $operationType = $preparedContent['operation-type'];
                                }

                                if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
                                    if (isset($product['parent_details'])) {
                                        $errorProductChangable[$product['container_id']] = $preparedContent;
                                    }

                                    $productErrorList[$product['source_product_id']] = $preparedContent['error'];
                                } else {
                                    $time = date(DATE_ISO8601);
                                    unset($preparedContent['error']);
                                    if (isset($preparedContent['json_product_type']) && !empty($preparedContent['json_product_type'])) {
                                        if (isset($preparedContent['feedToSave'])) {
                                            $preparedContent['feedToSave']['time'] = $time;
                                            $contentInActivity[$product['source_product_id']] = $preparedContent['feedToSave'];
                                            $contentInActivity[$product['source_product_id']]['product_type'] = $preparedContent['json_product_type'];
                                            unset($preparedContent['feedToSave']);
                                        }

                                        // unset($preparedContent['time']);
                                        $productType = $preparedContent['json_product_type'];

                                        if (is_array($productType)) {
                                            $productType = $productType[0] ?? '';
                                        }
                                        if (isset($feedContent[$productType][$product['source_product_id']]) && is_string($feedContent[$productType][$product['source_product_id']])) {
                                            $this->di->getLog()->logContent('UserId:' . print_r($userId, true), 'info', 'himanshu-error-product-23-sep.log');
                                            $this->di->getLog()->logContent('source_product_id:' . print_r($product['source_product_id'], true), 'info', 'himanshu-error-product-23-sep.log');
                                            $this->di->getLog()->logContent('productType:' . print_r($productType, true), 'info', 'himanshu-error-product-23-sep.log');
                                        }

                                        $feedContent[$productType][$product['source_product_id']]['sku'] = $product['edited']['sku'] ?? $product['sku'] ?? $product['source_product_id'];
                                        $feedContent[$productType]['json_product_type'] = $productType;
                                        $feedContent[$productType]['language_tag'] = $preparedContent['language_tag'];
                                        $feedContent[$productType]['marketplace_id'] = $preparedContent['marketplace_id'];
                                        unset($preparedContent['language_tag']);
                                        unset($preparedContent['marketplace_id']);
                                        $feedContent[$productType][$product['source_product_id']] = base64_encode(serialize($preparedContent));
                                        $validateProductArr[$product['source_product_id']] = ['marketplace' => $product['marketplace'], 'container_id' => $product['container_id']];
                                    } else {
                                        $preparedContent['time'] = $time;
                                        $feedContent[$categorySettings['primary_category']][$product['source_product_id']] =
                                            $preparedContent;
                                        $feedContent[$categorySettings['primary_category']]['category'] =
                                            $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['category'];
                                        $feedContent[$categorySettings['primary_category']]['sub_category'] =
                                            $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['sub_category'];
                                        $feedContent[$categorySettings['primary_category']]['barcode_exemption'] =
                                            $feedContent[$categorySettings['primary_category']][$product['source_product_id']]['barcode_exemption'];
                                    }
                                }
                            } else {
                                $removeTagProductList[] = $product['source_product_id'];
                                if ($operationType == self::OPERATION_TYPE_PARTIAL_UPDATE && isset($sync['productSyncProductList']) && !$sync['productSyncProductList']) {
                                    $productErrorList[$product['source_product_id']] = ['AmazonError107'];
                                } else {
                                    $productErrorList[$product['source_product_id']] = ['AmazonError101'];
                                }
                            }

                            $addTagProductList = [];
                        } catch (\Exception $e) {
                            // Log the exception details
                            $errorMessage = 'Exception occurred while processing product: ' . $e->getMessage();
                            $this->di->getLog()->logContent(
                                'Product Exception - Source Product ID: ' . ($product['source_product_id'] ?? 'UNKNOWN') . 
                                ' | Container ID: ' . ($product['container_id'] ?? 'UNKNOWN') . 
                                ' | Error: ' . $errorMessage . 
                                ' | File: ' . $e->getFile() . 
                                ' | Line: ' . $e->getLine() . 
                                ' | Trace: ' . $e->getTraceAsString(),
                                'error',
                               'Exception.log'
                            );
                            
                            // Add product to error list
                            if (isset($product['source_product_id'])) {
                                $productErrorList[$product['source_product_id']] = ['Exception: ' . $e->getMessage()];
                            }
                            
                            // Continue to next product instead of breaking the entire chunk
                            continue;
                        } catch (\Throwable $e) {
                            // Catch any other throwable errors (PHP 7+)
                            $errorMessage = 'Throwable error occurred while processing product: ' . $e->getMessage();
                            $this->di->getLog()->logContent(
                                'Product Throwable Error - Source Product ID: ' . ($product['source_product_id'] ?? 'UNKNOWN') . 
                                ' | Container ID: ' . ($product['container_id'] ?? 'UNKNOWN') . 
                                ' | Error: ' . $errorMessage . 
                                ' | File: ' . $e->getFile() . 
                                ' | Line: ' . $e->getLine() . 
                                ' | Trace: ' . $e->getTraceAsString(),
                                'error',
                                'Exception.log'
                            );
                            
                            // Add product to error list
                            if (isset($product['source_product_id'])) {
                                $productErrorList[$product['source_product_id']] = ['Throwable: ' . $e->getMessage()];
                            }
                            
                            // Continue to next product instead of breaking the entire chunk
                            continue;
                        }
                    }

                    if (!empty($productsChanged)) {
                        $errorListProductFound = [];
                        if (!empty($errorProductChangable)) {
                            if (array_intersect(array_keys($errorProductChangable), array_values($changedProducts))) {
                                $errorListProductFound = $errorProductChangable;
                            }
                        }

                        $feedContent = $this->updatePreparedContent($feedContent, $productsChanged, $data['data']['params'], $errorListProductFound);
                    }
                    if (!empty($data['data']['return_feed'])) {
                        $feed = $this->unserialize_recursive($feedContent);
                        if (!empty($feed)) {
                            return [
                                'success' => true,
                                'data' => $feed,
                                'jsonProductErrorList' => $this->getErrorMessages($jsonProductErrorList),

                            ];
                        }
                        return [
                            'success' => false,
                            'message' => "No feed is generated for this product",
                            'jsonProductErrorList' => $this->getErrorMessages($jsonProductErrorList),
                            'productErrorList' => $productErrorList
                        ];
                    }

                    if ($operationType == self::OPERATION_TYPE_UPDATE && !empty($validateProductArr) && !empty($feedContent)) {
                        // validate json-product before submitting feed
                        // $jsonHelper = $this->di->getObjectManager()->get('App\Amazon\Components\Listings\Helper');
                        foreach ($feedContent as $productType => $content) {
                            if (isset($content['json_product_type'])) {
                                // if feed is of json-type
                                $users = array("63624c0e720de16a8c7ebf33", "66fcea76d2977b9a2a0f7be2", "670177405e374b2f4d04efb2", "66d55b3f4b1d8ffaad028a42", "673861748e16da69210fa4b2");
                                if (in_array($userId, $users)) {
                                    $result = [];
                                } else {
                                    $result = $productTypeHelper->validateJsonFeed($content, $productType);
                                }
                                if (isset($result['success'], $result['errors']) && !empty($result['errors'])) {
                                    foreach ($result['errors'] as $sourceProductId => $errorArray) {
                                        if (!empty($validateProductArr[$sourceProductId])) {
                                            $jsonProductErrorList[$sourceProductId] = [
                                                'error' => $errorArray,
                                                'container_id' => $validateProductArr[$sourceProductId]['container_id']
                                            ];
                                            $processTags = $productTypeHelper->getProgressTag($validateProductArr[$sourceProductId], $sourceProductId, $targetShopId, $operationType);
                                            if (is_array($processTags)) {
                                                $jsonProductErrorList[$product['source_product_id']]['process_tags'] = $processTags;
                                            }

                                            unset($feedContent[$productType][$sourceProductId]);
                                            $key = array_search($sourceProductId, $sourceProductIds);
                                            // print_r($key);die;
                                            unset($feedContent[$productType][$sourceProductId]);
                                            unset($sourceProductIds[$key]);
                                        }
                                    }

                                    if (count($content) == 3 && isset($content['json_product_type'], $content['language_tag'], $content['marketplace_id'])) {
                                        // all product's feed is invalid of current product_type
                                        unset($feedContent[$productType]);
                                    }

                                    if (empty($sourceProductIds)) {
                                        $feedContent = [];
                                    }
                                }

                                if (isset($feedContent[$productType]['marketplace_id'])) {
                                    unset($feedContent[$productType]['marketplace_id']);
                                }

                                if (isset($feedContent[$productType]['language_tag'])) {
                                    unset($feedContent[$productType]['language_tag']);
                                }
                            }
                        }
                    } else if ($operationType == self::OPERATION_TYPE_PARTIAL_UPDATE) {
                        foreach ($feedContent as $productType => $content) {
                            if (isset($feedContent[$productType]['marketplace_id'])) {
                                unset($feedContent[$productType]['marketplace_id']);
                            }

                            if (isset($feedContent[$productType]['language_tag'])) {
                                unset($feedContent[$productType]['language_tag']);
                            }
                        }
                    }



                    if (!empty($feedContent)) {
                        foreach ($feedContent as $content) {
                            $permission = $commonHelper->remotePermissionToSendRequest(
                                'handshake',
                                ["remote_shop_id" => $targetShop['remote_shop_id'], "action_name" => $operationType],
                                'POST'
                            );
                            $this->di->getLog()->logContent('remote response for permission: ' . print_r($permission, true), 'info', $logFile);

                            if (!$permission['success']) {
                                return ["CODE" => "REQUEUE", 'time_out' => 10, 'message' => "Please wait 10 minutes before attempting the process again.", 'success' => false];
                            }

                            $specifics = [
                                'home_shop_id' => $targetShop['_id'],
                                'marketplace_id' => $targetShop['warehouses'][0]['marketplace_id'],
                                'shop_id' => $targetShop['remote_shop_id'],
                                'sourceShopId' => $sourceShopId,
                                'feedContent' => base64_encode(serialize($content)),
                                'user_id' => $userId,
                                'operation_type' => $operationType,
                                "handshake_token" => $permission['token'],
                                "uploadThroughS3" => true
                            ];
                            if (isset($content['json_product_type'])) {
                                $specifics['unified_json_feed'] = true;
                            }

                            $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');

                            if (isset($response['success']) && $response['success']) {
                                $this->di->getLog()->logContent('response from new remote success true: ', 'info', $logFile);
                                unset($content['category']);
                                unset($content['sub_category']);
                                unset($content['barcode_exemption']);
                                $specifics['feedContent'] = $content;
                                // if (!isset($content['json_product_type'])) {
                                //     $inventoryComponent->saveFeedInDb($content, 'product', $container_ids, $data['data']['params'], $alreadySavedActivities);
                                // } 
                            }

                            $this->di->getLog()->logContent('Response = ' . print_r($response, true), 'info', $logFile);

                            $type = $operationType == self::OPERATION_TYPE_PARTIAL_UPDATE ? Helper::PROCESS_TAG_UPDATE_FEED : Helper::PROCESS_TAG_UPLOAD_FEED;

                            $flag = $operationType == self::OPERATION_TYPE_PARTIAL_UPDATE ? Helper::PROCESS_TAG_SYNC_PRODUCT : Helper::PROCESS_TAG_UPLOAD_PRODUCT;

                            $this->di->getLog()->logContent('type' . print_r($type, true), 'info', $logFile);
                            $this->di->getLog()->logContent('flag' . print_r($flag, true), 'info', $logFile);
                            $specificData['marketplaceId']  = $marketplaceId;
                            $specificData['tag'] = $type;
                            $specificData['unsetTag'] =  $flag;
                            $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);
                            // $productSpecifics = $this->processResponse($response, $feedContent, $type, 'product', $targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, $flag);
                            $addTagProductList = array_merge($addTagProductList, $productSpecifics['addTagProductList']);
                            $productErrorList = array_merge($productErrorList, $productSpecifics['productErrorList']);
                        }
                        $inventoryComponent->saveFeedInDb($contentInActivity, 'product', $container_ids, $data['data']['params'], $alreadySavedActivities);


                        if (!empty($jsonProductErrorList)) {
                            $jsonErrorHelper = $this->di->getObjectManager()->get(Error::class);
                            $jsonErrorHelper->saveErrorInProduct($jsonProductErrorList, 'Listing', $targetShopId, $sourceShopId, $userId, $currencyCheck);
                        }

                        return $this->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0, true);
                    }
                    if (!empty($jsonProductErrorList)) {
                        $jsonErrorHelper = $this->di->getObjectManager()->get(Error::class);
                        $jsonErrorHelper->saveErrorInProduct($jsonProductErrorList, 'Listing', $targetShopId, $sourceShopId, $userId, $currencyCheck);
                    }

                    $specificData['unsetTag'] =  Helper::PROCESS_TAG_UPLOAD_PRODUCT;
                    $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);
                    return $this->setResponse(Helper::RESPONSE_SUCCESS, count($addTagProductList), 0, count($productErrorList), 0, true);
                }
                if (isset($data['data']['params']['source_product_ids']) && !empty($data['data']['params']['source_product_ids'])) {
                    $this->removeTag([$data['data']['params']['source_product_ids']], [Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
                }

                return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Currency of Shopify and Amazon account are not same. Please fill currency settings from Global Price Adjustment in settings page.', 0, 0, 0, false);
            }
            return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0, false);
        }
        if (isset($data['data']['params'])) {
            $params = $data['data']['params'];
            $sourceShopId = $params['source']['shopId'] ?? "";
            $targetShopId = $params['target']['shopId'] ?? "";
            $userId = $params['user_id'] ?? $this->_user_id;
            if (isset($params['source_product_ids']) && !empty($params['source_product_ids'])) {
                $this->removeTag([$params['source_product_ids']], [Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
            }
            // if($this->di->getUser()->id == "652e75669f424b50300561b3"){
            //     $this->di->getLog()->logContent('Data3' . print_r($data, true), 'info', 'INUpload.log');
            // }
            if (isset($params['next_available']) && $params['next_available']) {
                return $this->setResponse(Helper::RESPONSE_SUCCESS, "", 0, 0, 0, true);
            }
        }

        return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0, false);
    }

    public function unserialize_recursive($data)
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->unserialize_recursive($value);
            } elseif (is_string($value)) {
                $decoded_value = base64_decode($value, true);
                if ($decoded_value !== false) {
                    $unserialized_value = @unserialize($decoded_value);
                    if ($unserialized_value !== false || $decoded_value === 'b:0;') {
                        $value = $unserialized_value;
                    }
                }
            }
        }

        return $data;
    }

    public function getErrorMessages($data)
    {
        $result = [];
        foreach ($data as $id => $details) {
            if (isset($details['error'])) {
                foreach ($details['error'] as $errorCode) {
                    $result[$id]['error'][] = $this->getErrorByCode($errorCode);
                }
            }

            if (isset($details['container_id'])) {
                $result[$id]['container_id'] = $details['container_id'];
            }
        }

        return $result;
    }




    /**
     * used to automate the parent-data for the sizes as per the data in the first child
     * so to resolved the uneven data in parent-child relationship on amazon
     */
    public function updatePreparedContent($feedContent, $products, $params, $errorListFeed)
    {
        $targetShopId = (string)$params['target']['shopId'];
        $sourceMarketplace = $params['source']['marketplace'];
        $arrChanges = ['apparel_size', 'shirt_size', 'footwear_size_unisex', 'footwear_size', 'bottoms_size', 'skirt_size', 'shapewear_size', 'bottoms_waist_size'];
        foreach ($products as $category => $feeds) {
            $updatedFeeds = $feedContent[$category];
            foreach ($feeds as $key => $product) {
                $foundProductFeed = false;
                $preparedContent = $updatedFeeds[$product['container_id']];
                $sku = $preparedContent['item_sku']; //parent_sku of the product
                foreach ($updatedFeeds as $childFeed) {
                    if (isset($childFeed['parent_sku']) && $childFeed['parent_sku'] == $sku) {
                        $foundProductFeed = $childFeed;
                        break;
                    }
                }

                //if product is not in feedContent so check if it is present in errorList
                if (!$foundProductFeed) {
                    foreach ($errorListFeed as $key => $feedErr) {
                        if ($product['container_id'] == $key) {
                            $foundProductFeed = $feedErr;
                            break;
                        }
                    }
                }

                //if no child product is present in connectorData
                if (!$foundProductFeed) {
                    $foundProduct = $this->getAllChildProducts($params, $product, true);
                    if ($foundProduct) {
                        if (isset($foundProduct['container_id']) && $foundProduct['container_id'] == $product['container_id']) {
                            $categorySettings = $this->getUploadCategorySettings($foundProduct);
                            if ($categorySettings) {
                                $foundProductFeed = $this->setContent($categorySettings, $foundProduct, $targetShopId, self::PRODUCT_TYPE_CHILD, $sku, self::OPERATION_TYPE_UPDATE, $sourceMarketplace);
                            }
                        }
                    }
                }

                if ($foundProductFeed) {
                    $isChangable = array_intersect($arrChanges, array_keys($foundProductFeed));
                    foreach ($isChangable as $changeKey) {
                        $feedContent[$category][$product['container_id']][$changeKey] = $foundProductFeed[$changeKey] ?? $feedContent[$category][$product['container_id']][$changeKey];
                    }
                }
            }
        }

        return $feedContent;
    }

    /**
     * get the child-data if parent product is present
     */
    public function getAllChildProducts($params, $product, $singleChild = false)
    {
        $containerId = $product['container_id'];
        $dataToConnector = [
            'container_id' => (string) $containerId,
            'source_marketplace' => $params['source']['marketplace'],
            'target_marketplace' => $params['target']['marketplace'],
            'sourceShopID' => (string) $params['source']['shopId'],
            'targetShopID' => (string) $params['target']['shopId']
        ];
        $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
        $productsData = $editHelper->getProduct($dataToConnector);

        if (isset($productsData['data']['rows']) && !empty($productsData['data']['rows'])) {
            foreach ($productsData['data']['rows'] as $key => $prod) {
                $productsData['data']['rows'][$key]['parent_details'] = $product;
                if ($singleChild && $prod['container_id'] == $containerId && $prod['type'] == 'simple') {
                    return $productsData['data']['rows'][$key];
                }
            }

            return $productsData['data']['rows'];
        }

        return false;
    }

    public function setContent($categorySettings, $product, $homeShopId, $type, $parentSku, $operationType, $sourceMarketplace = false, $preview = false, $localDelivery = false)
    {
        if ($localDelivery) {
            $prodLocal = false;
            $merchantId = $localDelivery[$product['source_product_id']] ?? "";
            $localDeliveryComp = $this->di->getObjectManager()->get(LocalDeliveryHelper::class);
            $paramsOfLD = [
                'target_shop_id' => $homeShopId,
                'user_id' => (string) $this->_user_id,
                'container_id' => (string) $product['container_id'],
                'source_product_id' => (string) $product['source_product_id'],
                'items.merchant_shipping_group' => $merchantId
            ];
            $prodLocal = $localDeliveryComp->getSingleProductDB($paramsOfLD);
            if ($prodLocal) {
                $product['edited']['sku'] = $prodLocal['sku'] ?? "";
                $parentSku = $prodLocal['parentSKU'] ?? "";
                $merchantName = $prodLocal['merchant_shipping_group_name'] ?? "";
            }
        }

        $feedContent = [];
        $merchantData = [];
        if (!empty($categorySettings)) {
            $product = json_decode(json_encode($product), true);
            $barcodeExemption = $categorySettings['barcode_exemption'] ?? "";
            $subCategory = $categorySettings['sub_category'] ?? "";
            $category = $categorySettings['primary_category'] ?? "";
            $valueMappedAttributes = $categorySettings['attributes_mapping']['value_mapping'] ?? [];
            $requiredAttributes = $categorySettings['attributes_mapping']['required_attribute'] ?? [];
            $optionalAttributes = $categorySettings['attributes_mapping']['optional_attribute'] ?? [];
            $browseNodeId = $categorySettings['browser_node_id'] ?? "";
            $attributes = [...(array) $requiredAttributes, ...(array) $optionalAttributes];
            $variant_attributes = [];
            $attributeArray = [];
            $fulfillment_centre_id = false;
            $recommendedValidation = [];
            $label = [];
            $productChangable = false;
            if ($category == 'default' && empty($attributes)) {
                return ['error' => ['AmazonError111']];
            }

            // if(isset($categorySettings['fulfillment_type']) && $categorySettings['fulfillment_type']=="FBA"){
            //     $fulfillment_centre_id = "Amazon_NA";
            // }
            if (isset($categorySettings['optional_variant_attributes'])) {
                $variant_attributes = array_merge($variant_attributes, $categorySettings['optional_variant_attributes']);
            }

            if (isset($categorySettings['variation_theme_attribute_name']) && $categorySettings['variation_theme_attribute_name']) {
                $variant_attributes['variation_theme'] = $categorySettings['variation_theme_attribute_name'];
            }

            if (isset($categorySettings['variation_theme_attribute_value']) && $categorySettings['variation_theme_attribute_value']) {
                $variant_attributes = array_merge($variant_attributes, $categorySettings['variation_theme_attribute_value']);
            }

            // Offer cannot be created for parent product
            if ($product['type'] == 'variation' && $category == 'default') {
                return $feedContent;
            }

            $amazonProduct = false;
            if (isset($product['edited'])) {
                $amazonProduct = $product['edited'];
            }

            $categoryContainer = $this->di->getObjectManager()->get(Category::class);
            $params = [
                'target' => [
                    'shopId' => $homeShopId,

                ],
                'data' => [
                    'category' => $category,
                    'sub_category' => $subCategory,
                    'browser_node_id' => $browseNodeId,
                    'barcode_exemption' => $barcodeExemption,
                    'browse_node_id' => $browseNodeId,
                ],
            ];

            if (isset($this->categoryAttributes[$category][$subCategory])) {
                $attributeArray = $this->categoryAttributes[$category][$subCategory];
            } else {
                $allAttributes = $categoryContainer->init()->getAmazonAttributes($params, true);

                if (isset($allAttributes['data'])) {
                    $attributeArray = $categoryContainer->init()->moldAmazonAttributes($allAttributes['data']);
                }

                $this->categoryAttributes[$category][$subCategory] = $attributeArray;
            }

            $arrayKeys = array_column($attributes, 'amazon_attribute');
            $mappedAttributeArray = array_combine($arrayKeys, $attributes);
            $images = false;
            if (isset($amazonProduct['additional_images'])) {
                $images = $amazonProduct['additional_images'];
            } elseif (isset($product['additional_images'])) {
                $images = $product['additional_images'];
            }

            $defaultMapping = self::DEFAULT_MAPPING;
            if (!empty($mappedAttributeArray) && !empty($attributeArray)) {
                foreach ($attributeArray as $id => $attribute) {
                    if ($id == "fulfillment_center_id" && isset($categorySettings['fulfillment_type']) && $categorySettings['fulfillment_type'] == "FBA") {
                        $amazonRecommendations = $attribute['amazon_recommendation'];
                        foreach ($amazonRecommendations as $amazonRecommendation) {
                            if ($amazonRecommendation != "DEFAULT") {
                                $fulfillment_centre_id = $amazonRecommendation;
                            }
                        }
                    } else if ($id == "fulfillment_center_id") {
                        $fulfillment_centre_id = "DEFAULT";
                    }

                    if ($id == 'main_image_url' && $this->_user_id == '61d29db38092370f1800a6a0') {
                        continue;
                    }

                    $amazonAttribute = $id;
                    $shopifyAttribute = false;
                    $meta_attribute = false;
                    if (isset($defaultMapping[$id])) {
                        $shopifyAttribute = $defaultMapping[$id];
                    } elseif (($type == self::PRODUCT_TYPE_CHILD || $id == 'variation_theme') && isset($variant_attributes[$id])) {
                        $shopifyAttribute = 'variant_custom';
                    } elseif (isset($mappedAttributeArray[$id]) && isset($mappedAttributeArray[$id]['shopify_select']) && $mappedAttributeArray[$id]['shopify_select'] == "custom") {
                        $shopifyAttribute = "custom";
                    } elseif (isset($mappedAttributeArray[$id]) && isset($mappedAttributeArray[$id]['shopify_select']) && $mappedAttributeArray[$id]['shopify_select'] == "recommendation") {
                        $shopifyAttribute = "recommendation";
                    } elseif (isset($mappedAttributeArray[$id]) && isset($mappedAttributeArray[$id]['shopify_select']) && $mappedAttributeArray[$id]['shopify_select'] == "Search") {
                        $shopifyAttribute = $mappedAttributeArray[$id]['shopify_attribute'];
                    }
                    //  elseif (isset($mappedAttributeArray[$id])) {
                    //     $shopifyAttribute = $mappedAttributeArray[$id]['shopify_attribute'];
                    // }
                    elseif (isset($attributeArray[$id]['premapped_values']) && !empty($attributeArray[$id]['premapped_values'])) {
                        $shopifyAttribute = 'premapped_values';
                    }

                    // if ($id == 'brand_name' && isset($mappedAttributeArray[$id])) {
                    //     $shopifyAttribute = $mappedAttributeArray[$id]['shopify_attribute'];
                    // }
                    if (isset($mappedAttributeArray[$id]) && isset($mappedAttributeArray[$id]['meta_attribute_exist']) && $mappedAttributeArray[$id]['meta_attribute_exist']) {
                        $meta_attribute = $mappedAttributeArray[$id]['meta_attribute_exist'];
                    }

                    if ($id == 'product_description' && isset($mappedAttributeArray[$id])) {
                        $shopifyAttribute = $mappedAttributeArray[$id]['shopify_attribute'];
                    }

                    $value = '';

                    if ($shopifyAttribute) {
                        if ($shopifyAttribute == 'recommendation') {
                            $recommendation = $mappedAttributeArray[$id]['recommendation'];
                            if ($recommendation == 'custom') {
                                $customText = $mappedAttributeArray[$id]['custom_text'];
                                $value = $customText;
                            } else {
                                $value = $recommendation;
                            }
                        } elseif ($shopifyAttribute == 'custom') {
                            $customText = $mappedAttributeArray[$id]['custom_text'];
                            $value = $customText;
                        } elseif (isset($product[$shopifyAttribute])) {
                            $value = $product[$shopifyAttribute];
                        } elseif ($shopifyAttribute == 'variant_custom') {
                            $value = $variant_attributes[$id];
                        } elseif ($shopifyAttribute == "premapped_values") {
                            $value = $attributeArray[$id]['premapped_values'];
                        } elseif (isset($product['variant_attributes']) && $type == self::PRODUCT_TYPE_CHILD) {
                            foreach ($product['variant_attributes'] as $variantatt) {
                                if ($variantatt['key'] == $shopifyAttribute) {
                                    $value = $variantatt['value'];
                                }
                            }
                        }
                        //not working because we don't have $firstChild
                        elseif (in_array($id, ['apparel_size', 'shirt_size', 'footwear_size_unisex', 'footwear_size', 'bottoms_size', 'skirt_size', 'shapewear_size']) && $type == self::PRODUCT_TYPE_PARENT && !empty($firstChild)) {
                            if (isset($firstChild[$shopifyAttribute])) {
                                $value = $firstChild[$shopifyAttribute];
                            } elseif (isset($firstChild['variant_attributes_values'][$shopifyAttribute])) {
                                $value = $firstChild['variant_attributes_values'][$shopifyAttribute];
                            }
                        }

                        //meta attribute changes
                        if ($meta_attribute) {
                            if (isset($product['parentMetafield'][$shopifyAttribute], $product['parentMetafield'][$shopifyAttribute]['value'])) {
                                $value = $product['parentMetafield'][$shopifyAttribute]['value'];
                            } elseif (isset($product['variantMetafield'][$shopifyAttribute], $product['variantMetafield'][$shopifyAttribute]['value'])) {
                                $value = $product['variantMetafield'][$shopifyAttribute]['value'];
                            } elseif (isset($product['parent_details'], $product['parent_details']['parentMetafield'], $product['parent_details'][$shopifyAttribute], $product['parent_details']['parentMetafield'][$shopifyAttribute]['value'])) {
                                $value = $product['parent_details']['parentMetafield'][$shopifyAttribute]['value'];
                            }
                        }

                        if ($shopifyAttribute == 'sku' && $type == self::PRODUCT_TYPE_PARENT && $parentSku) {
                            $value = $parentSku;
                        }

                        if ($id == 'main_image_url' && $type == self::PRODUCT_TYPE_CHILD && isset($product['variant_image'])) {
                            $value = $product['variant_image'];
                        }

                        if (isset($mappedAttributeArray[$id], $attribute['amazon_recommendation']) && (!empty($attribute['amazon_recommendation'])) && (!isset($mappedAttributeArray['shopify_select']) || (isset($mappedAttributeArray[$id]['shopify_select']) && $mappedAttributeArray[$id]['shopify_select'] != "recommendation"))) {
                            $recommendedValidation[$id] = $attribute['amazon_recommendation'];
                        }

                        if (in_array($id, ['apparel_size', 'shirt_size', 'footwear_size_unisex', 'footwear_size', 'bottoms_size', 'skirt_size', 'shapewear_size', 'bottoms_waist_size', 'headwear_size']) && $type == self::PRODUCT_TYPE_PARENT) {
                            if (isset($feedContent['footwear_size_class']) || isset($feedContent['bottoms_size_class']) || isset($feedContent['apparel_size_class']) || isset($feedContent['skirt_size_class']) || isset($feedContent['shapewear_size_class']) || isset($feedContent['shirt_size_class']) || isset($feedContent['headwear_size_class'])) {
                                if ((isset($feedContent['footwear_size_class']) && ($feedContent['footwear_size_class'] == 'Numeric' || $feedContent['footwear_size_class'] == 'Numeric Range' || $feedContent['footwear_size_class'] == 'Numero' || $feedContent['footwear_size_class'] == 'Numérique')) || (isset($feedContent['bottoms_size_class']) && $feedContent['bottoms_size_class'] == 'Numeric') || (isset($feedContent['apparel_size_class']) && $feedContent['apparel_size_class'] == 'Numeric') || (isset($feedContent['skirt_size_class']) && $feedContent['skirt_size_class'] == 'Numeric') || (isset($feedContent['shirt_size_class']) && $feedContent['shirt_size_class'] == 'Numeric') || (isset($feedContent['apparel_size_class']) && $feedContent['apparel_size_class'] == 'numeric') ||  (isset($feedContent['headwear_size_class']) && $feedContent['headwear_size_class'] == 'Numeric')) {
                                    if ($id == 'footwear_size') {
                                        $value = 36;
                                    } else {
                                        $value = 34;
                                    }
                                } elseif ((isset($feedContent['shapewear_size_class']) && $feedContent['shapewear_size_class'] == 'Age') || (isset($feedContent['shirt_size_class']) && $feedContent['shirt_size_class'] == 'Age') || (isset($feedContent['footwear_size_class']) && $feedContent['footwear_size_class'] == 'Age') || (isset($feedContent['bottoms_size_class']) && $feedContent['bottoms_size_class'] == 'Age') || (isset($feedContent['apparel_size_class']) && $feedContent['apparel_size_class'] == 'Age') || (isset($feedContent['headwear_size_class']) && $feedContent['headwear_size_class'] == 'Age')) {
                                    $value = '4 Years';
                                } else {
                                    $value = 'Medium';
                                }
                            }

                            $productChangable = true;
                        }

                        if ($id == 'main_image_url') {
                            if (isset($amazonProduct['main_image'])) {
                                $value = $amazonProduct['main_image'];
                            } elseif ($type == self::PRODUCT_TYPE_CHILD && isset($product['variant_image'])) {
                                $value = $product['variant_image'];
                            }
                        }

                        if ($shopifyAttribute == 'sku' && $type != self::PRODUCT_TYPE_PARENT) {
                            if ($amazonProduct && isset($amazonProduct[$shopifyAttribute]) && $amazonProduct[$shopifyAttribute]) {
                                $value = $amazonProduct[$shopifyAttribute];
                            } elseif ($product && isset($product[$shopifyAttribute]) && !empty($product[$shopifyAttribute])) {
                                $value = $product[$shopifyAttribute];
                            } elseif (isset($product['source_product_id'])) {
                                $value = $product['source_product_id'];
                            }
                        }


                        if ($shopifyAttribute == 'barcode' && $amazonProduct && isset($amazonProduct[$shopifyAttribute])) {
                            $value = $amazonProduct[$shopifyAttribute];
                        }

                        if ($shopifyAttribute == 'barcode') {
                            $value = str_replace("-", "", $value);
                        }

                        if ($shopifyAttribute == 'description') {
                            if ($amazonProduct && isset($amazonProduct[$shopifyAttribute])) {
                                $value = $amazonProduct[$shopifyAttribute];
                            } elseif (isset($product['parent_details']['edited']) && isset($product['parent_details']['edited'][$shopifyAttribute])) {
                                $value = $product['parent_details']['edited'][$shopifyAttribute];
                            } elseif (isset($product[$shopifyAttribute])) {
                                $value = $product[$shopifyAttribute];
                            }
                        }

                        if ($shopifyAttribute == 'title' && $amazonProduct && isset($amazonProduct[$shopifyAttribute])) {
                            $value = $amazonProduct[$shopifyAttribute];
                        }

                        if ($shopifyAttribute == 'price' && $type != self::PRODUCT_TYPE_PARENT) {
                            $productPrice = $this->di->getObjectManager()->get(Price::class);
                            $currency = $this->currency;
                            if ($preview) {
                                $currency = $preview;
                            }

                            if ($currency) {
                                $priceTemplate = $productPrice->init()->prepare($product);
                                $priceList = $productPrice->init()->calculate($product, $priceTemplate, $currency, true);
                                if (isset($priceList['StandardPrice']) && $priceList['StandardPrice']) {
                                    $value = $priceList['StandardPrice'];
                                } else {
                                    if (!isset($priceList['StandardPrice'])) {
                                        $value = false;
                                    } else {
                                        $value = 0;
                                    }
                                }
                            } else {
                                $value = 0;
                            }
                        }

                        if ($shopifyAttribute == 'quantity' && $type != self::PRODUCT_TYPE_PARENT) {

                            if (isset($categorySettings['fulfillment_type']) && $categorySettings['fulfillment_type'] == "FBA") {
                                $value = "";
                            } else {
                                if (!$sourceMarketplace) {
                                    $sourceMarketplace = $product['source_marketplace'];
                                }

                                $productInventory = $this->di->getObjectManager()->get(Inventory::class);
                                $inventoryTemplate = $productInventory->init()->prepare($product);
                                $inventoryList = $productInventory->init()->calculate($product, $inventoryTemplate, $sourceMarketplace);

                                if (isset($inventoryList['Latency'])) {
                                    $latency = $inventoryList['Latency'];
                                }

                                if (isset($inventoryList['Quantity']) && $inventoryList['Quantity'] && $inventoryList['Quantity'] > 0) {
                                    $value = $inventoryList['Quantity'];
                                } else {
                                    $value = 0;
                                }
                            }
                        }

                        if ($shopifyAttribute == 'description') {
                            //  $value = strip_tags($value);
                            $value = str_replace(["\n", "\r"], ' ', $value);
                            $tag = 'span';
                            $value = preg_replace('#</?' . $tag . '[^>]*>#is', '', $value);

                            $value = strip_tags($value, '<p></p><ul></ul><li></li><strong></strong>');
                            $value = preg_replace('/(<[^>]+) style=".*?"/i', '$1', $value);
                            $value = preg_replace("/(<[^>]+) style='.*?'/i", '$1', $value);
                            $value = preg_replace('/(<[^>]+) class=".*?"/i', '$1', $value);
                            $value = preg_replace("/(<[^>]+) class='.*?'/i", '$1', $value);
                            $value = preg_replace('/(<[^>]+) data=".*?"/i', '$1', $value);
                            $value = preg_replace("/(<[^>]+) data='.*?'/i", '$1', $value);
                            $value = preg_replace('/(<[^>]+) data-mce-fragment=".*?"/i', '$1', $value);
                            $value = preg_replace("/(<[^>]+) data-mce-fragment='.*?'/i", '$1', $value);
                            $value = preg_replace('/(<[^>]+) data-mce-style=".*?"/i', '$1', $value);
                            $value = preg_replace("/(<[^>]+) data-mce-style='.*?'/i", '$1', $value);
                            $value = preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/si", '<$1$2>', $value);
                        }

                        //                      if ($shopifyAttribute == 'description' || $shopifyAttribute == 'title') {
                        //                            $value = str_replace(' ', '-', $value);
                        //                            $value = preg_replace('/[^A-Za-z0-9\-]/', '', $value);
                        //                            $value = str_replace('-', ' ', $value);
                        //                        }

                        if ($shopifyAttribute == 'weight') {
                            if ((float) $value > 0) {
                                $value = number_format((float) $value, 2);
                            } else {
                                $value = '';
                            }
                        }

                        if ($id == 'item_name' && isset($product['variant_title']) && isset($product['group_id']) && $this->_user_id != '612a4c7bae0bc20b20753452' && $this->_user_id != '61231fbf6ede040a43459660') {
                            $value = $value . ' ' . $product['variant_title'];
                        }

                        if ($id == 'item_name' && isset($product['variant_title']) && $product['type'] == "simple" && $product['visibility'] == "Not Visible Individually") {
                            if (isset($amazonProduct) && isset($amazonProduct['title'])) {
                                $value = $amazonProduct['title'];
                            } else {
                                $value = $product['title'] . ' ' . $product['variant_title'];
                            }
                        }


                        // if ($id == 'fulfillment_center_id' && $type == self::PRODUCT_TYPE_PARENT) {
                        //     $value = '';
                        // }

                        if ($this->_user_id == '612f48c95c77ca2b3904cda9' && $amazonAttribute == 'gem_type1') {
                            $value = 'Metal';
                        }

                        if ($shopifyAttribute == 'title') {
                            $value = str_replace("’", "'", $value);
                        }

                        // if ($this->_user_id == "6289f0a2d3f6d710ce370f9d1") {
                        //     // $value = $this->mapOptionLatest($id, $shopifyAttribute, $value);
                        //     $value = '';
                        // } else {
                        //     $value = $this->mapOption($id, $shopifyAttribute, $value);
                        // }
                        $value = $this->mapOption($id, $shopifyAttribute, $value, $valueMappedAttributes, $sourceMarketplace);
                        if (is_array($value)) {
                            $value = implode(",", $value);
                        }

                        $feedContent[$amazonAttribute] = $value;
                    }

                    if ($amazonAttribute == 'feed_product_type') {
                        if (isset($attribute['amazon_recommendation'][$subCategory])) {
                            $acceptedValues = $attribute['amazon_recommendation'][$subCategory];
                            $feedContent[$amazonAttribute] = $acceptedValues;
                        } else {
                            $feedContent[$amazonAttribute] = $subCategory;
                        }

                        if ($feedContent[$amazonAttribute] == 'shirt' && $this->_user_id == '61182f2ad4fc9a5310349827') {
                            $feedContent[$amazonAttribute] = 'SHIRT';
                        }
                    }

                    if (in_array($amazonAttribute, self::IMAGE_ATTRIBUTES) && $this->_user_id != '61d29db38092370f1800a6a0') {
                        $imageKey = array_search($amazonAttribute, self::IMAGE_ATTRIBUTES);
                        if (isset($images[$imageKey])) {
                            $feedContent[$amazonAttribute] = $images[$imageKey];
                        }
                    }

                    if ($params['data']['category'] == 'handmade') {
                        if (in_array($amazonAttribute, self::HANDMADE_IMAGE_ATTRIBUTES)) {
                            $imageKey = array_search($amazonAttribute, self::HANDMADE_IMAGE_ATTRIBUTES);
                            if (isset($images[$imageKey])) {
                                $feedContent[$amazonAttribute] = $images[$imageKey];
                            }
                        }
                    }

                    if ($amazonAttribute == 'recommended_browse_nodes' || $amazonAttribute == 'recommended_browse_nodes1') {
                        if ($this->_user_id == '6116cad63715e60b00136653') {
                            $feedContent[$amazonAttribute] = '5977780031';
                        } elseif ($this->_user_id == '612f48c95c77ca2b3904cda9') {
                            $feedContent[$amazonAttribute] = '10459533031';
                        } else {
                            $feedContent[$amazonAttribute] = $browseNodeId;
                        }
                    }

                    if ($amazonAttribute == 'parent_child' && ($type == self::PRODUCT_TYPE_PARENT || $type == self::PRODUCT_TYPE_CHILD)) {
                        $feedContent[$amazonAttribute] = $type;
                    }

                    if ($amazonAttribute == 'relationship_type' && $type == self::PRODUCT_TYPE_CHILD) {
                        $feedContent[$amazonAttribute] = 'variation';
                    }

                    if ($amazonAttribute == 'parent_sku' && $type == self::PRODUCT_TYPE_CHILD) {
                        $feedContent[$amazonAttribute] = $parentSku;
                    }

                    if ($amazonAttribute == 'operation-type' || $amazonAttribute == 'update_delete') {
                        if ($operationType == self::OPERATION_TYPE_UPDATE) {
                            // $operationType = self::OPERATION_TYPE_UPDATE;
                            $feedContent[$amazonAttribute] = $operationType;
                        }
                    }

                    if ($amazonAttribute == 'ASIN-hint' && isset($amazonProduct['asin'])) {
                        $feedContent[$amazonAttribute] = $amazonProduct['asin'];
                    }

                    $label[$amazonAttribute] = $attribute['label'];
                }

                if ($this->_user_id == '613bca66a4d23a0dca3fbd9d') {
                    if (isset($feedContent['shirt_size'], $feedContent['shirt_size_class'])) {
                        $f = $this->getSizeClassFromSize($feedContent['shirt_size']);
                        if ($f) {
                            $feedContent['shirt_size_class'] = $f;
                        }
                    }
                }

                $feedContent['barcode_exemption'] = $barcodeExemption;
                if (isset($category, $subCategory)) {
                    $feedContent['category'] = $category;
                    $feedContent['sub_category'] = $subCategory;
                }

                if ($fulfillment_centre_id) {
                    $feedContent['fulfillment_centre_id'] = $fulfillment_centre_id;
                }

                //setting handling time
                if (isset($latency)) {
                    $feedContent['fulfillment_latency'] = $latency;
                }

                if ($barcodeExemption) {
                    unset($feedContent['product-id']);
                    unset($feedContent['external_product_id']);
                }

                //sending ASIN as barcode in offer listing
                if ($category == 'default' && $subCategory == 'default' && isset($amazonProduct['asin']) && !empty($amazonProduct['asin'])) {
                    $asin = $amazonProduct['asin'];
                    if (isset($feedContent['product-id']) && !empty($feedContent['product-id'])) {
                        $feedContent['product-id'] = $asin;
                    } elseif (isset($feedContent['external_product_id']) && !empty($feedContent['external_product_id'])) {
                        $feedContent['external_product_id'] = $asin;
                    }
                }

                if (isset($feedContent['product-id']) && (!$barcodeExemption || $feedContent['category'] == 'default') && $feedContent['product-id']) {
                    $barcode = $this->di->getObjectManager()->get(Barcode::class);
                    $type = $barcode->setBarcode($feedContent['product-id']);
                    if ($type) {
                        $feedContent['product-id-type'] = $type;
                    }
                } elseif (isset($feedContent['external_product_id']) && (!$barcodeExemption || $feedContent['category'] == 'default') && $feedContent['external_product_id']) {
                    $barcode = $this->di->getObjectManager()->get(Barcode::class);
                    $type = $barcode->setBarcode($feedContent['external_product_id']);
                    if ($type) {
                        $feedContent['external_product_id_type'] = $type;
                    }
                }

                if (isset($categorySettings['variation_theme_fields']) && !empty($categorySettings['variation_theme_fields']) && $product['type'] == 'simple') {
                    $variationTheme = $categorySettings['variation_theme_fields'];
                    $feedContent = array_merge($feedContent, $variationTheme);
                }

                //mapping color_name and color_map together
                if (isset($feedContent['color_name']) && (!isset($feedContent['color_map']) || $feedContent['color_map'] == "")) {
                    $feedContent['color_map'] = $feedContent['color_name'];
                } elseif (isset($feedContent['color_map']) && (!isset($feedContent['color_name']) || $feedContent['color_name'] == "")) {
                    $feedContent['color_name'] = $feedContent['color_map'];
                }

                //mapping size_name and size_map together
                if (isset($feedContent['size_name']) && (!isset($feedContnet['size_map']) || $feedContent['size_map'] == "")) {
                    $feedContent['size_map'] = $feedContent['size_name'];
                } elseif (isset($feedContent['size_map']) && (!isset($feedContent['size_name']) || $feedContent['size_name'] == "")) {
                    $feedContent['size_name'] = $feedContent['size_map'];
                }

                if ($this->_user_id = "6514eb6f510817e7c304d8f2") {
                    if (isset($feedContent['product_description'])) {
                        if (strlen((string) $feedContent['product_description']) > 2000) {
                            $feedContent['product_description'] = substr((string) $feedContent['product_description'], 0, 1990);
                        }
                    }
                }
            }
        }

        if ($params['data']['category'] != 'handmade') {
            $feedContent = $this->validate($feedContent, $product, $attributeArray, $params);
        }

        if ($localDelivery) {
            $feedContent['merchant_shipping_group_name'] = $merchantName;
        }

        if ($preview) {
            $feedContent['previewLabel'] = $label;
        }

        return $feedContent;
    }

    public function getSizeClassFromSize($sizeValue)
    {
        if (in_array($sizeValue, ['4', '6', '7'])) {
            return 'Numeric';
        }
        if (in_array($sizeValue, ['12 Months', '18 Months', '24 Months', '6 Months'])) {
            return 'Age';
        }
        if (in_array($sizeValue, ["X-Large", "Small", "Medium", "Large", "XX-Large", "3X-Large", "4X-Large", "5X-Large"])) {
            return 'Alpha';
        } else {
            return false;
        }
    }

    public function getSyncStatus($product)
    {
        $syncProductTemplate = false;
        $sync = [
            'priceInvSyncList' => false,
            'imageSyncProductList' => false,
            'productSyncProductList' => false,
        ];
        $syncInvEnabled = false;
        $syncPriceEnabled = false;

        // //check if product sync is enabled in profile
        if (isset($product['profile_info']) && is_array($product['profile_info'])) {
            $profiles = $product['profile_info'];
            if ($profiles) {
                $syncProductTemplate = false;
                $syncProductStatusEnabled = false;
                foreach ($profiles['data'] as $profile) {
                    if (isset($profile['data_type']) && $profile['data_type'] == "product_settings") {
                        $syncProductTemplate = $profile;
                        break;
                    }
                }

                if ($syncProductTemplate) {
                    $syncProductStatusEnabled = $syncProductTemplate['data']['settings_enabled'];
                    if ($syncProductStatusEnabled == true) {
                        $sync['priceInvSyncList'] = true;
                        $selectedAttributes = $syncProductTemplate['data']['selected_attributes'];
                        $selectedAttributes = json_decode(json_encode($selectedAttributes), true);

                        if (in_array('images', $selectedAttributes)) {
                            $sync['imageSyncProductList'] = true;
                        }

                        $intersect = array_intersect(["title", "sku", "barcode", "brand", "description", 'product_details'], $selectedAttributes);
                        if (!empty($intersect)) {
                            $sync['productSyncProductList'] = true;
                        }
                    }
                }
            }
        }

        // if profile is not assigned, check product sync status in configuration

        if (!$syncProductTemplate) {
            $this->configObj->setGroupCode('inventory');
            $syncInvEnabled = $this->configObj->getConfig("settings_enabled");
            $syncInvEnabled = json_decode(json_encode($syncInvEnabled), true);
            $syncInvEnabled = $syncInvEnabled[0]['value'] ?? true;

            $this->configObj->setGroupCode('price');
            $syncPriceEnabled = $this->configObj->getConfig("settings_enabled");
            $syncPriceEnabled = json_decode(json_encode($syncPriceEnabled), true);
            $syncPriceEnabled = $syncPriceEnabled[0]['value'] ?? true;

            $this->configObj->setGroupCode('product');
            // $syncProductTemplates = $this->configObj->getConfig("settings_enabled");
            $syncProductAttributes = $this->configObj->getConfig("selected_attributes");
            // $syncProductTemplates = json_decode(json_encode($syncProductTemplates), true);
            $syncProductAttributes = json_decode(json_encode($syncProductAttributes), true);

            if ($syncInvEnabled && $syncPriceEnabled) {
                $sync['priceInvSyncList'] = true;
            }
            // foreach ($syncProductTemplates as $syncProductTemplate) {
            //     if ($syncProductTemplate) {
            //         $syncProductStatusEnabled = $syncProductTemplate['value'];
            //         $sync['priceInvSyncList'] = true;
            //     }
            // }

            // if ($syncProductStatusEnabled) {

            foreach ($syncProductAttributes as $syncProductAttribute) {
                if ($syncProductAttribute) {
                    $selectedAttributes = $syncProductAttribute['value'];
                    if (in_array('images', $selectedAttributes)) {
                        $sync['imageSyncProductList'] = true;
                    }

                    if (in_array('product_details', $selectedAttributes) || in_array('product_syncing', $selectedAttributes)) {
                        $sync['productSyncProductList'] = true;
                    }
                }

                // }
            }
        }

        return $sync;
    }

    public function delete($data, $operationType = self::OPERATION_TYPE_DELETE)
    {

        $feedContent = [];
        $productComponent = $this->di->getObjectManager()->get(\App\Amazon\Components\Product\Product::class);
        $addTagProductList = [];
        $date = date('d-m-Y');
        if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
            $preparedContent = [];
            $userId = $data['data']['params']['user_id'];
            $targetShopId = $data['data']['params']['target']['shopId'];
            $sourceShopId = $data['data']['params']['source']['shopId'];
            $targetMarketplace = $data['data']['params']['target']['marketplace'];
            $sourceMarketplace = $data['data']['params']['source']['marketplace'];
            $productErrorList = [];
            $specificData = $data['data']['params'];
            $specificData['type'] = 'delete';
            $response = [];
            $active = false;
            $inventoryComponent = $this->di->getObjectManager()->get(Inventory::class);
            $contentToSaved = [];
            $alreadySavedActivities = [];
            $allParentDetails = $data['data']['all_parent_details'] ?? [];
            $allProfileInfo = $data['data']['all_profile_info'] ?? [];

            $logFile = "amazon/{$userId}/{$sourceShopId}/ProductDelete/{$date}.log";
            // $this->di->getLog()->logContent('Receiving DATA = ' . print_r($data, true), 'info', $logFile);

            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $targetShop = $user_details->getShop($targetShopId, $userId);

            $activeAccounts = [];
            foreach ($targetShop['warehouses'] as $warehouse) {
                if ($warehouse['status'] == "active") {
                    $activeAccounts = $warehouse;
                    $specificData['marketplaceId'] = $warehouse['marketplace_id'];
                }
            }

            if (!empty($activeAccounts)) {
                $products = $data['data']['rows'];
                foreach ($products as $key => $product) {
                    $target = [];
                    $product = json_decode(json_encode($product), true);
                    if (isset($product['parent_details']) && is_string($product['parent_details'])) {
                        $product['parent_details'] = $allParentDetails[$product['parent_details']];
                    }
                    if (isset($product['profile_info']['$oid'])) {
                        $product['profile_info'] = $allProfileInfo[$product['profile_info']['$oid']];
                    }
                    $marketplaces = $product['marketplace'] ?? [];
                    foreach ($marketplaces as $marketplace) {
                        if (isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == $targetMarketplace && $marketplace['shop_id'] == $targetShopId && $marketplace['source_product_id'] == $product['source_product_id']) {
                            $target = $marketplace;
                        }
                    }

                    // if (!isset($target['status']) || $target['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER || $target['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED) {
                    //     $productErrorList[$product['source_product_id']] = ['AmazonError501'];
                    // } else {
                    if (isset($product['edited'])) {
                        $amazonProduct = $product['edited'];
                    } else {
                        $amazonProduct = [];
                    }

                    $container_ids[$product['source_product_id']] = $product['container_id'];
                    $alreadySavedActivities[$product['source_product_id']] = $product['edited']['last_activities'] ?? [];
                    $feedContent[$product['source_product_id']]['Id'] = $product['_id'] . $key;
                    if ($product['type'] == 'variation') {
                        if (isset($amazonProduct['parent_sku']) && !empty($amazonProduct['parent_sku'])) {
                            $feedContent[$product['source_product_id']]['SKU'] = $amazonProduct['parent_sku'];
                        } elseif (isset($product['sku']) && !empty($product['sku'])) {
                            $feedContent[$product['source_product_id']]['SKU'] = $product['sku'];
                        } else {
                            $feedContent[$product['source_product_id']]['SKU'] = $product['source_product_id'];
                        }
                    } else {
                        if (isset($amazonProduct['sku']) && !empty($amazonProduct['sku'])) {
                            $feedContent[$product['source_product_id']]['SKU'] = $amazonProduct['sku'];
                        } elseif (isset($product['sku']) && !empty($product['sku'])) {
                            $feedContent[$product['source_product_id']]['SKU'] = $product['sku'];
                        } else {
                            $feedContent[$product['source_product_id']]['SKU'] = $product['source_product_id'];
                        }
                    }

                    $time = date(DATE_ISO8601);
                    $feedContent[$product['source_product_id']]['time'] = $time;
                }
                if (!empty($feedContent)) {
                    if(count($feedContent) < 50){
                        $contentInActivity = $feedContent;
                        $specificData = [
                        'ids' => array_keys($feedContent),
                        'home_shop_id' => $targetShop['_id'],
                        'marketplace_id' => $activeAccounts['marketplace_id'],
                        'shop_id' => $targetShop['remote_shop_id'],
                        'sourceShopId' => $sourceShopId,
                        'feedContent' => base64_encode(json_encode($feedContent)),
                        'user_id' => $userId,
                        'operation_type' => $operationType,
                        'unified_json_feed' => true
                    ];
                    $res = $this->di->getObjectManager()->get(Helper::class)->sendRequestToAmazon('instant-delete', $specificData, 'POST');
                    // print_r($res);die;
                    
                    if(isset($res['success']) && $res['success']) {
                        $feedContent =unserialize(base64_decode((string) $res['feedContent']));
                               
                        $error = $res['error']??[];
                        $accepted = $res['accepted']??[];
                        
                                $tounsetStatus = array_keys($accepted);
                                $this->removeStatusFromProduct($tounsetStatus,$targetShopId, $sourceShopId ,$userId);
                                // need to unset status
                                foreach($error as $sourceProductId => $errorArray) {
                                    $jsonProductErrorList[$sourceProductId] = ['error' => $errorArray, 'container_id' => $container_ids[$sourceProductId] ?? null];
                                    unset($contentInActivity[$sourceProductId]);
                                }
                                foreach($accepted as $sourceProductId => $acceptedArray) {
                                    
                                    $PatchListingContent[$sourceProductId] = $contentInActivity[$sourceProductId];
                                    unset($contentInActivity[$sourceProductId]);
                                }
                                $inventoryComponent->saveFeedInDb($PatchListingContent, 'delete', $container_ids, $data['data']['params'], $alreadySavedActivities);
                    
                                if (!empty($jsonProductErrorList)) {
                                    
                                    $this->di->getObjectManager()->get(Error::class)->saveErrorInProduct($jsonProductErrorList, 'Listing', $targetShopId, $sourceShopId, $userId, $currencyCheck);
                                }


                            }
                    }
                    if(!empty($feedContent)){

                    
                  
                    $specifics = [
                        'ids' => array_keys($feedContent),
                        'home_shop_id' => $targetShop['_id'],
                        'marketplace_id' => $activeAccounts['marketplace_id'],
                        'shop_id' => $targetShop['remote_shop_id'],
                        'sourceShopId' => $sourceShopId,
                        'feedContent' => base64_encode(serialize($feedContent)),
                        'user_id' => $userId,
                        'operation_type' => $operationType,
                        'unified_json_feed' => true
                    ];

                    $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                    $response = $commonHelper->sendRequestToAmazon('product-delete', $specifics, 'POST');
                    if (isset($response['success']) && $response['success']) {
                        $inventoryComponent->saveFeedInDb($feedContent, 'delete', $container_ids, $data['data']['params'], $alreadySavedActivities);
                    }

                    $specificData['tag'] = Helper::PROCESS_TAG_DELETE_FEED;
                    $specificData['unsetTag'] = Helper::PROCESS_TAG_DELETE_PRODUCT;
                    $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices($response, $productErrorList, [], $products);
                    // $productSpecifics = $this
                    //     ->processResponse($response, $feedContent, \App\Amazon\Components\Common\Helper::PROCESS_TAG_DELETE_FEED, 'delete', $targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, \App\Amazon\Components\Common\Helper::PROCESS_TAG_DELETE_PRODUCT);
                    $addTagProductList = $productSpecifics['addTagProductList'];
                    $productErrorList = $productSpecifics['productErrorList'];
                }
                } else {
                    $specificData['tag'] = false;
                    $specificData['unsetTag'] = Helper::PROCESS_TAG_DELETE_FEED;
                    $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);
                    $addTagProductList = $productSpecifics['addTagProductList'];
                    $productErrorList = $productSpecifics['productErrorList'];
                }

                return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0, true);
            }
            return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0, false);
        }
        return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0, false);
    }

    public function prepareDelete($specifics)
    {
        $feedContent = [];
        $attributeSku = false;
        $type = null;
        if (isset($specifics) && !empty($specifics)) {
            $templatesCollection = $this->_baseMongo->getCollectionForTable(Helper::PROFILE_SETTINGS);
            if (isset($specifics['category_template']) && $specifics['category_template']) {
                $categoryTemplate = $templatesCollection->findOne(['_id' => (int) $specifics['category_template']], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
                $requiredAttributes = $categoryTemplate['data']['attributes_mapping']['required_attribute'];
                $skuAttributeIndex = array_search('SKU', array_column($requiredAttributes, 'amazon_attribute'));
                if (isset($requiredAttributes[$skuAttributeIndex]['onyx_attribute']) && !empty($requiredAttributes[$skuAttributeIndex]['onyx_attribute'])) {
                    $attributeSku = $requiredAttributes[$skuAttributeIndex]['onyx_attribute'];
                }
            }

            if (!empty($specifics['category_template'])) {
                $ids = $specifics['ids'];

                //get products from mongo db
                $productCollection = $this->_baseMongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
                $products = $productCollection->find(['source_product_id' => ['$in' => $ids]], ["typeMap" => ['root' => 'array', 'document' => 'array']]);

                foreach ($products as $key => $product) {
                    // case 1 : for configurable products
                    if ($product['type'] == 'variation' && !isset($product['group_id'])) {

                        // get childs for parent products
                        $childs = $productCollection->find(['group_id' => ['$in' => [$product['source_product_id']]]], ["typeMap" => ['root' => 'array', 'document' => 'array']]);

                        if (!empty($childs->toArray())) {
                            foreach ($childs as $childKey => $child) {
                                if (isset($product[$attributeSku]) && $product[$attributeSku]) {
                                    $sku = $product[$attributeSku];
                                    $feedContent[$sku]['SKU'] = $sku;
                                    $feedContent[$sku]['Id'] = $child['_id'] . $childKey;
                                }
                            }
                        }
                    } else {
                        if ($product[$attributeSku]) {
                            $sku = $product[$attributeSku];
                            $feedContent[$sku]['SKU'] = $sku;
                            $feedContent[$sku]['Id'] = $product['_id'] . $key;
                        }
                    }
                }
            }
        }

        return $feedContent;
    }

    public function getVendor($id)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        $userId = $this->di->getUser()->id;

        $response = $productCollection->aggregate([
            [
                '$match' => ['user_id' => $userId],
            ],
            [
                '$group' => [
                    '_id' => $userId,
                    'brand' => [
                        '$addToSet' => '$brand',
                    ],
                ],
            ],
        ])->toArray();

        return ['success' => true, 'data' => $response[0]['brand']];
    }

    public function getVariantAttributes()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        $userId = $this->di->getUser()->id;

        $response = $productCollection->aggregate([
            [

                '$match' => ['user_id' => $userId],
            ],
            [
                '$group' => [
                    '_id' => $userId,
                    'variant_attributes' => [
                        '$addToSet' => '$variant_attributes',
                    ],
                ],
            ],

        ])->toArray();
        if (count($response) == 0) {
            return ['success' => false, 'data' => $response];
        }
        return ['success' => true, 'data' => $response[0]['variant_attributes']];
    }

    public function getShopifyAttributes($id)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        if (isset($id['container_id'])) {
            $response = $productCollection->aggregate([
                [
                    '$match' => ['container_id' => $id['container_id']],
                ],
                [
                    '$unwind' => '$variant_attributes',
                ],
                [
                    '$group' => [
                        '_id' => $id['container_id'],
                        'variant_attributes' => [
                            '$addToSet' => '$variant_attributes',
                        ],
                    ],
                ],
            ])->toArray();
        } else {
            $userId = $this->di->getUser()->id;
            $response = $productCollection->aggregate([
                [
                    '$match' => ['user_id' => $userId],
                ],
                [
                    '$unwind' => '$variant_attributes',
                ],
                [
                    '$group' => [
                        '_id' => $userId,
                        'variant_attributes' => [
                            '$addToSet' => '$variant_attributes',
                        ],
                    ],
                ],
            ])->toArray();

            // print_r($response);
        }

        $newAttribute = [];
        $newAttribute = $response;

        $unique = [];
        $temp = [];

        $temp = array_unique((array) $newAttribute[0]['variant_attributes']);
        foreach ($temp as $value) {
            array_push($unique, ['code' => $value, 'title' => $value, 'required' => 0]);
        }

        $fixedAttributes = [
            //            [
            //                'code' => 'vendor',
            //                'title' => 'Vendor',
            //                'required' => 0,
            //            ],
            [
                'code' => 'handle',
                'title' => 'Handle',
                'required' => 0,
            ],
            [
                'code' => 'product_type',
                'title' => 'Product Type',
                'required' => 0,
            ],
            [
                'code' => 'type',
                'title' => 'type',
                'required' => 0,
            ],
            [
                'code' => 'title',
                'title' => 'title',
                'required' => 0,
            ],
            [
                'code' => 'brand',
                'title' => 'brand',
                'required' => 0,
            ],
            [
                'code' => 'description',
                'title' => 'description',
                'required' => 0,
            ],
            [
                'code' => 'tags',
                'title' => 'tags',
                'required' => 0,
            ],
            //            [
            //                'code' => 'collection',
            //                'title' => 'collection',
            //                'required' => 0
            //            ],
            //            [
            //                'code' => 'position',
            //                'title' => 'position',
            //                'required' => 0
            //            ],
            [
                'code' => 'sku',
                'title' => 'sku',
                'required' => 0,
            ],
            [
                'code' => 'price',
                'title' => 'price',
                'required' => 0,
            ],
            [
                'code' => 'quantity',
                'title' => 'quantity',
                'required' => 0,
            ],
            [
                'code' => 'weight',
                'title' => 'weight',
                'required' => 0,
            ],
            [
                'code' => 'weight_unit',
                'title' => 'weight_unit',
                'required' => 0,
            ],
            [
                'code' => 'grams',
                'title' => 'grams',
                'required' => 0,
            ],
            [
                'code' => 'barcode',
                'title' => 'barcode',
                'required' => 0,
            ],
            //            [
            //                'code' => 'inventory_policy',
            //                'title' => 'inventory_policy',
            //                'required' => 0
            //            ],
            //            [
            //                'code' => 'taxable',
            //                'title' => 'taxable',
            //                'required' => 0
            //            ],
            //            [
            //                'code' => 'fulfillment_service',
            //                'title' => 'fulfillment_service',
            //                'required' => 0
            //            ],
            //            [
            //                'code' => 'inventory_item_id',
            //                'title' => 'inventory_item_id',
            //                'required' => 0
            //            ],
            //            [
            //                'code' => 'inventory_tracked',
            //                'title' => 'inventory_tracked',
            //                'required' => 0
            //            ],
            //            [
            //                'code' => 'requires_shipping',
            //                'title' => 'requires_shipping',
            //                'required' => 0
            //            ],
            //            [
            //                'code' => 'locations',
            //                'title' => 'locations',
            //                'required' => 0
            //            ],
            //            [
            //                'code' => 'is_imported',
            //                'title' => 'is_imported',
            //                'required' => 0
            //            ],
            //            [
            //                'code' => 'visibility',
            //                'title' => 'visibility',
            //                'required' => 0
            //            ],
            [
                'code' => 'variant_title',
                'title' => 'Variant title',
                'required' => 0,
            ],
        ];
        $fixedAttributes = [...$fixedAttributes, ...$unique];
        return ['success' => true, 'data' => $fixedAttributes];
    }

    // public function updateProfiledataMarketplacetable($filter, $data, $options = [])
    // {
    //     $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
    //     $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::AMAZON_PRODUCT_CONTAINER);
    //     return $collection->updateMany($filter, $data, $options);
    // }

    //    public function addTags($sourceProductIds, $tag)
    //    {
    //
    //        if (!empty($sourceProductIds)) {
    //            $productCollection = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->setSource(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER)->getPhpCollection();
    //            //    $productCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
    //            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
    //            $connectedAccounts = $commonHelper->getAllAmazonShops($this->_user_id);
    //            foreach ($connectedAccounts as $account) {
    //                if ($account['warehouses'][0]['status'] == 'active') {
    //                    $activeAccounts[] = $account;
    //                    $homeShopId = $account['_id'];
    //                    $seller_id = $account['warehouses'][0]['seller_id'];
    //                    $bulkOpArray = [];
    //                    $removeError = [];
    //                    foreach ($sourceProductIds as $sourceProductId) {
    //                        $saveArray = [];
    //                        $amazonData = [];
    //                        $productData = $productCollection->findOne(['source_product_id' => (string)$sourceProductId, 'user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
    //
    //                        if (isset($productData['marketplace']['amazon']) && is_array($productData['marketplace']['amazon'])) {
    //                            foreach ($productData['marketplace']['amazon'] as $amazonShops) {
    //                                if (isset($amazonShops['shop_id']) && $amazonShops['shop_id'] == $homeShopId) {
    //                                    $saveArray = $amazonShops;
    //                                    if (!isset($saveArray['process_tags']) || array_search($tag, $saveArray['process_tags']) === false) {
    //                                        $saveArray['process_tags'][] = $tag;
    //                                    }
    //
    //                                    $bulkOpArray[] = [
    //                                        'updateOne' => [
    //                                            ['source_product_id' => (string)$sourceProductId, 'marketplace.amazon.shop_id' => (string)$amazonShops['shop_id'], 'user_id' => $this->_user_id],
    //                                            [
    //                                                '$set' => ['marketplace.amazon.$.process_tags' => $saveArray['process_tags'], 'marketplace.amazon.$.seller_id' => $seller_id]
    //                                            ],
    //                                        ],
    //                                    ];
    //                                    //                                    $mongo1 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
    //                                    //                                    $productCollection1 = $mongo1->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
    //                                    //                                    $productCollection1->updateOne(['source_product_id' => $sourceProductId, 'marketplace.amazon.shop_id' => $amazonShops['shop_id'], 'user_id' => $this->_user_id],
    //                                    //                                        ['$set' => ['marketplace.amazon.$.process_tags' => $saveArray['process_tags'], 'marketplace.amazon.$.seller_id' => $seller_id]]);
    //                                    break;
    //                                }
    //                            }
    //                            if (empty($saveArray)) {
    //                                $saveArray['shop_id'] = (string)$homeShopId;
    //                                $saveArray['process_tags'][] = $tag;
    //                                $saveArray['seller_id'] = $seller_id;
    //
    //                                $bulkOpArray[] = [
    //                                    'updateOne' => [
    //                                        ['source_product_id' => (string)$sourceProductId, 'user_id' => $this->_user_id],
    //                                        [
    //                                            '$push' => ['marketplace.amazon' => $saveArray]
    //                                        ],
    //                                    ],
    //                                ];
    //                                //                                $mongo2 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
    //                                //                                $productCollection2 = $mongo2->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
    //                                //                                $productCollection2->updateOne(['source_product_id' => $sourceProductId, 'user_id' => $this->_user_id], ['$push' => ['marketplace.amazon' => $saveArray]]);
    //                            }
    //                        }
    //
    //                        if (empty($saveArray)) {
    //                            $saveArray['shop_id'] = (string)$homeShopId;
    //                            $saveArray['process_tags'][] = $tag;
    //                            $saveArray['seller_id'] = $seller_id;
    //                            $amazonData['marketplace']['amazon'][] = $saveArray;
    //
    //                            $bulkOpArray[] = [
    //                                'updateOne' => [
    //                                    ['source_product_id' => (string)$sourceProductId, 'user_id' => $this->_user_id],
    //                                    [
    //                                        '$set' => $amazonData
    //                                    ],
    //                                ],
    //                            ];
    //                            //                            $mongo3 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
    //                            //                            $productCollection3 = $mongo3->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
    //                            //                           $res = $productCollection3->updateOne(['source_product_id' => $sourceProductId, 'user_id' => $this->_user_id], ['$set' => $amazonData]);
    //
    //                            //                            if ($sourceProductId == '36916246610079') {
    //                            //                                print_r($productCollection3->explain($res));
    //                            //                                die();
    //                            //                            }
    //                        }
    //                    }
    //
    //                    //set array for error remove
    //                    //                    if (in_array($tag, [\App\Amazon\Components\Common\Helper::PROCESS_TAG_UPLOAD_PRODUCT, \App\Amazon\Components\Common\Helper::PROCESS_TAG_SYNC_PRODUCT])) {
    //                    $removeError['product'] = $sourceProductIds;
    //                    //                    }
    //
    //                    //                    if ($tag == \App\Amazon\Components\Common\Helper::PROCESS_TAG_PRICE_SYNC) {
    //                    $removeError['price'] = $sourceProductIds;
    //                    //                    }
    //
    //                    //                    if ($tag == \App\Amazon\Components\Common\Helper::PROCESS_TAG_INVENTORY_SYNC) {
    //                    $removeError['inventory'] = $sourceProductIds;
    //                    //                    }
    //
    //                    //                    if ($tag == \App\Amazon\Components\Common\Helper::PROCESS_TAG_IMAGE_SYNC) {
    //                    $removeError['image'] = $sourceProductIds;
    //                    //                    }
    //
    //                    //                    if ($tag == \App\Amazon\Components\Common\Helper::PROCESS_TAG_DELETE_PRODUCT) {
    //                    $removeError['delete'] = $sourceProductIds;
    //                    //                    }
    //
    //                    $response = $productCollection->BulkWrite($bulkOpArray, ['w' => 1]);
    //
    //                    if (!empty($removeError)) {
    //                        foreach ($removeError as $errorType => $sourceProductIds) {
    //                            $feed = $this->di->getObjectManager()
    //                                ->get(Feed::class)
    //                                ->init(['user_id' => $this->_user_id])
    //                                ->removeErrorQuery($sourceProductIds, $homeShopId, $errorType);
    //                        }
    //                    }
    //                }
    //            }
    //        }
    //    }

    public function addTags($sourceProductIds, $tag, $targetShopId, $sourceShopId, $userId, $products = [], $unsetTag = false, $useMarketplace = true): void
    {
        $date = date(DATE_RFC2822);
        $time = str_replace("+0000", "GMT", $date);
        $message = $this->getMessage($tag);
        $sourceMarketplace = '';
        foreach ($this->di->getUser()->shops as $shop) {
            if ($shop['_id'] == $sourceShopId) {
                $sourceMarketplace = $shop['marketplace'] ?? "shopify";
            }
        }
        $additionalData['source'] = [
            'marketplace' => $sourceMarketplace,
            'shopId' => (string)$sourceShopId
        ];
        $additionalData['target'] = [
            'marketplace' => 'amazon',
            'shopId' => (string)$targetShopId
        ];

        if (!empty($sourceProductIds)) {
            $productInsert = [];
            foreach ($sourceProductIds as $sourceProductId) {
                $foundProduct = null;
                $processTag = [];
                $alreadySavedProcessTag = [];
                $marketplace = false;
                if (!empty($products)) {
                    $productKey = array_search($sourceProductId, array_column($products, 'source_product_id'));
                    if ($productKey !== false) {
                        $foundProduct = $products[$productKey];
                    }
                }

                if (!$foundProduct) {
                    $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                    $specifics = [
                        'source_product_id' => $sourceProductId,
                        'source_shop_id' => $sourceShopId,
                        'target_shop_id' => $targetShopId,
                        'user_id' => $userId,
                    ];
                    $productData = $helper->getSingleProduct($specifics);
                    $foundProduct = $productData;
                }

                if ($foundProduct) {
                    $foundProduct = json_decode(json_encode($foundProduct), true);
                    if (isset($foundProduct['marketplace']) || !$useMarketplace) {
                        $marketplace = $this->getMarketplace($foundProduct, $targetShopId, $sourceProductId);
                    }

                    if ($marketplace) {
                        if (isset($marketplace['process_tags'])) {
                            $processTag = $marketplace['process_tags'];
                            if (!in_array($tag, array_keys($processTag))) {
                                $processTag = $marketplace['process_tags'];
                                $alreadySavedProcessTag = $processTag;
                            } else if ($unsetTag) {
                                unset($processTag[$unsetTag]);
                            }
                        }

                        if (empty(($alreadySavedProcessTag)) || array_search($tag, array_keys($alreadySavedProcessTag)) === false) {
                            $data = [
                                'msg' => $message,
                                'time' => $time
                            ];
                            $processTag[$tag] = $data;
                        }
                    } else {
                        $data = [
                            'msg' => $message,
                            'time' => $time
                        ];
                        $processTag[$tag] = $data;
                    }

                    if (!empty($processTag) && !empty(array_diff(array_keys($processTag), array_keys($alreadySavedProcessTag)))) {

                        if (in_array($unsetTag, array_keys($processTag))) {
                            unset($processTag[$unsetTag]);
                        }

                        $product = [
                            "source_product_id" => (string) $sourceProductId,
                            // required
                            'user_id' => $userId,
                            'source_shop_id' => $sourceShopId,
                            'container_id' => (string) $foundProduct['container_id'],
                            'childInfo' => [
                                'source_product_id' => (string) $sourceProductId,
                                // required
                                'shop_id' => $targetShopId,
                                // required
                                'process_tags' => $processTag,
                                'target_marketplace' => 'amazon',
                                // required
                            ],
                        ];
                        if (!$useMarketplace) {
                            unset($product['childInfo']);
                            $product['target_marketplace'] = 'amazon';
                            $product['source_product_id'] = (string) $sourceProductId;
                            $product['shop_id'] = $targetShopId;
                            $product['process_tags'] = $processTag;
                        }

                        array_push($productInsert, $product);
                    }
                }
            }

            if (!empty($productInsert)) {
                $objectManager = $this->di->getObjectManager();
                if ($useMarketplace) {
                    $objectManager->get('\App\Connector\Models\Product\Marketplace')->marketplaceSaveAndUpdate($productInsert);
                } else {
                    $objectManager->get('\App\Connector\Models\Product\Edit')->saveProduct($productInsert, $userId, $additionalData);
                }
            }
        }
    }

    public function getMessage($tag)
    {
        $msg = "";
        $msg = match ($tag){
            Helper::PROCESS_TAG_UPLOAD_PRODUCT => Helper::UPLOAD_MESSAGE,
            Helper::PROCESS_TAG_INVENTORY_SYNC => Helper::INVENTORY_MESSAGE,
            Helper::PROCESS_TAG_IMAGE_SYNC => Helper::IMAGE_MESSAGE,
            Helper::PROCESS_TAG_GROUP_SYNC => Helper::GROUP_MESSAGE,
            Helper::PROCESS_TAG_PRICE_SYNC => Helper::PRICE_MESSAGE,
            Helper::PROCESS_TAG_DELETE_PRODUCT => Helper::DELETE_MESSAGE,
            Helper::PROCESS_TAG_SYNC_PRODUCT => Helper::SYNC_PRODUCT,
            Helper::PROCESS_TAG_UPLOAD_FEED => Helper::UPLOAD_MESSAGE_FEED,
            Helper::PROCESS_TAG_INVENTORY_FEED => Helper::INVENTORY_MESSAGE_FEED,
            Helper::PROCESS_TAG_GROUP_FEED => Helper::GROUP_MESSAGE_FEED,
            Helper::PROCESS_TAG_IMAGE_FEED => Helper::IMAGE_MESSAGE_FEED,
            Helper::PROCESS_TAG_PRICE_FEED => Helper::PRICE_MESSAGE_FEED,
            Helper::PROCESS_TAG_DELETE_FEED => Helper::DELETE_MESSAGE_FEED,
            Helper::PROCESS_TAG_UPDATE_FEED => Helper::SYNC_PRODUCT_FEED,
            default => $msg,
        };
        return $msg;
    }

    public function removeErrorQuery($sourceProductIds, $type, $targetShopId, $sourceShopId, $userId, $products = []): void
    {
        $useMarketplace = $this->di->getConfig()->use_marketplace_key ?? true;
        $sourceMarketplace = '';
        foreach ($this->di->getUser()->shops as $shop) {
            if ($shop['_id'] == $sourceShopId) {
                $sourceMarketplace = $shop['marketplace'] ?? "shopify";
            }
        }
        $additionalData['source'] = [
            'marketplace' => $sourceMarketplace,
            'shopId' => (string)$sourceShopId
        ];
        $additionalData['target'] = [
            'marketplace' => 'amazon',
            'shopId' => (string)$targetShopId
        ];

        if (!empty($sourceProductIds)) {
            $productInsert = [];
            foreach ($sourceProductIds as $sourceProductId) {
                $foundProduct = null;
                $error = [];
                $alreadySavedError = [];
                if (!empty($products)) {
                    $productKey = array_search($sourceProductId, array_column($products, 'source_product_id'));
                    if ($productKey !== false) {
                        $foundProduct = $products[$productKey];
                    }
                }

                if (!$foundProduct) {
                    $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                    $productData = $helper->getSingleProduct([
                        'source_product_id' => $sourceProductId,
                        'source_shop_id' => $sourceShopId,
                        'target_shop_id' => $targetShopId,
                        'user_id' => $userId,
                    ]);
                    $foundProduct = $productData;
                }

                if ($foundProduct) {
                    $foundProduct = json_decode(json_encode($foundProduct), true);
                    if (isset($foundProduct['marketplace']) || !$useMarketplace) {
                        $amazonData = $this->getMarketplace($foundProduct, $targetShopId, $sourceProductId);
                        // foreach ($foundProduct['marketplace'] as $amazonData) {
                        //     if ($amazonData['shop_id'] == $targetShopId && $amazonData['source_product_id'] == $sourceProductId) {
                        if (isset($amazonData['error'])) {
                            $alreadySavedError = $amazonData['error'];
                            $error = $amazonData['error'];
                            // condition for product delete
                            if ($type == "delete" || $type == "product") {
                                $error = [];
                            } else {
                                foreach ($error as $unsetKey => $singleError) {
                                    if (isset($singleError['type']) && $singleError['type'] === $type) {
                                        unset($error[$unsetKey]);
                                    }
                                }
                            }
                        }
                    }

                    if (!empty(array_diff(array_keys($alreadySavedError), array_keys($error)))) {
                        if (empty($error)) {
                            $product = [
                                "source_product_id" => (string) $sourceProductId,
                                // required
                                'user_id' => $userId,
                                'source_shop_id' => $sourceShopId,
                                'container_id' => (string) $foundProduct['container_id'],
                                'childInfo' => [
                                    'source_product_id' => (string) $sourceProductId,
                                    // required
                                    'shop_id' => $targetShopId,
                                    // required
                                    'target_marketplace' => 'amazon',
                                    // required
                                ],
                                'unset' => ['error'],
                            ];
                            if (!$useMarketplace) {
                                unset($product['childInfo']);
                                $product['source_product_id'] = (string) $sourceProductId;
                                $product['shop_id'] = $targetShopId;
                                $product['target_marketplace'] = 'amazon';
                            }
                        } else {
                            $product = [
                                "source_product_id" => (string) $sourceProductId,
                                // required
                                'user_id' => $userId,
                                'source_shop_id' => $sourceShopId,
                                'container_id' => (string) $foundProduct['container_id'],
                                'childInfo' => [
                                    'source_product_id' => (string) $sourceProductId,
                                    // required
                                    'shop_id' => $targetShopId,
                                    // required
                                    'error' => $error,
                                    'target_marketplace' => 'amazon',
                                    // required
                                ],
                            ];
                            if (!$useMarketplace) {
                                unset($product['childInfo']);
                                $product['source_product_id'] = (string) $sourceProductId;
                                $product['shop_id'] = $targetShopId;
                                $product['target_marketplace'] = 'amazon';
                            }
                        }

                        array_push($productInsert, $product);
                    }
                }
            }

            if (!empty($productInsert)) {

                $objectManager = $this->di->getObjectManager();

                if ($useMarketplace) {
                    $objectManager->get('\App\Connector\Models\Product\Marketplace')->marketplaceSaveAndUpdate($productInsert);
                } else {
                    $objectManager->get('\App\Connector\Models\Product\Edit')->saveProduct($productInsert, $userId, $additionalData);
                }

                // $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
                // $res = $helper->marketplaceSaveAndUpdate($productInsert);
            }
        }
    }

    public function removeTag($sourceProductIds, $tags, $targetShopId, $sourceShopId, $userId, $products = [], $feed = []): void
    {
        $date = date('d-m-Y');
        if (!$targetShopId) {
            $targetShopId = $feed['specifics']['shop_id'];
        }

        if (!$userId) {
            $userId = $feed['user_id'];
        }

        if (!$sourceShopId) {
            $sourceShopId = $feed['source_shop_id'];
        }

        if (empty($sourceProductIds)) {
            if ($feed['type'] == 'JSON_LISTINGS_FEED') {
                $ids = $feed['specifics']['ids'];
                $messageIds = [];
                foreach ($ids as $messageId) {
                    $msgs = explode('_', (string) $messageId);
                    $messageIds[$msgs[1]] = $msgs['0'];
                }

                $sourceProductIds = array_values($messageIds);
            } else {
                $sourceProductIds = $feed['specifics']['ids'];
            }
        }

        $logFile = "amazon/productNotFound/{$sourceShopId}/{$date}.log";
        if (empty($tags)) {
            $feedType = $feed['type'];

            $action = Feed::FEED_TYPE[$feedType];
            if ($action == 'product') {
                $tags = [
                    Helper::PROCESS_TAG_UPLOAD_FEED,
                    Helper::PROCESS_TAG_UPDATE_FEED
                ];
            } elseif ($action == 'inventory') {
                $tags = [
                    Helper::PROCESS_TAG_INVENTORY_FEED
                ];
            } elseif ($action == 'price') {
                $tags = [
                    Helper::PROCESS_TAG_PRICE_FEED

                ];
            } elseif ($action == 'image') {
                $tags = [
                    Helper::PROCESS_TAG_IMAGE_FEED
                ];
            } elseif ($action == 'delete') {
                $tags = [
                    Helper::PROCESS_TAG_DELETE_FEED
                ];
            } else {
                $tags = [
                    Helper::PROCESS_TAG_UPLOAD_FEED,
                    Helper::PROCESS_TAG_UPDATE_FEED
                ];
            }
        }

        //change array of int to array of string
        $encoded = implode(',', $sourceProductIds);
        $sourceProductIds = explode(',', $encoded);
        $productInsert = [];

        if (!empty($sourceProductIds)) {
            foreach ($sourceProductIds as $sourceProductId) {
                $foundProduct = null;
                $processTag = [];
                $alreadySavedProcessTag = [];
                if (!empty($products)) {
                    $productKey = array_search($sourceProductId, array_column($products, 'source_product_id'));
                    if ($productKey !== false) {
                        $foundProduct = $products[$productKey];
                    }
                }

                if (!$foundProduct) {
                    $data = ['source_product_id' => $sourceProductId, "target_shop_id" => (string) $targetShopId, 'source_shop_id' => (string) $sourceShopId, 'user_id' => (string) $userId];
                    $productMarketplace = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                    $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
                    $query = [
                        'user_id' => $userId,
                        'shop_id' => (string)$sourceShopId,
                        'source_product_id' => $sourceProductId
                    ];
                    $productFetch = $productMarketplace->getproductbyQuery($query, $options);
                    if (isset($productFetch) && isset($productFetch[0])) {
                        $foundProduct = $productFetch[0];
                    } else {
                        $this->di->getLog()->logContent('id ->: ' . print_r($sourceProductId, true), 'info', $logFile);
                    }

                    // $data = ['source_product_id' => (string) $sourceProductId, "target_shop_id" => (string) $targetShopId, 'source_shop_id' => (string) $sourceShopId, 'user_id' => (string) $userId];
                    // $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                    // $productData = $helper->getSingleProduct($data);
                    // $foundProduct = $productData;
                }

                if ($foundProduct) {
                    $foundProduct = json_decode(json_encode($foundProduct), true);

                    // if (isset($foundProduct['marketplace'])) {
                    $amazonData = $this->getMarketplace($foundProduct, $targetShopId, $sourceProductId);
                    // foreach ($foundProduct['marketplace'] as $amazonData) {
                    //     if ($amazonData['shop_id'] == $targetShopId && $amazonData['source_product_id'] == $sourceProductId) {

                    if (isset($amazonData['process_tags'])) {
                        $processTag = $amazonData['process_tags'];
                        $alreadySavedProcessTag = $amazonData['process_tags'];
                    }

                    if (!empty($tags) && !empty($processTag)) {
                        foreach ($tags as $tag) {
                            if (isset($processTag[$tag])) {
                                unset($processTag[$tag]);
                            }
                        }
                    }

                    // }

                    if (!empty(array_diff(array_keys($alreadySavedProcessTag), array_keys($processTag)))) {
                        if (empty($processTag)) {
                            $product = [
                                "source_product_id" => $sourceProductId,
                                // required
                                'user_id' => (string) $userId,
                                'shop_id' => (string) $targetShopId,
                                'source_shop_id' => (string) $sourceShopId,
                                'container_id' => (string) $foundProduct['container_id'],
                                'target_marketplace' => 'amazon',
                                'unset' => ['process_tags' => 1],
                            ];
                            // $product = [
                            //     "source_product_id" => (string) $sourceProductId,
                            //     // required
                            //     'user_id' => (string) $userId,
                            //     'source_shop_id' => (string) $sourceShopId,
                            //     'container_id' => (string) $foundProduct['container_id'],
                            //     'childInfo' => [
                            //         'source_product_id' => (string) $sourceProductId,
                            //         // required
                            //         'shop_id' => (string) $targetShopId,
                            //         // required
                            //         // 'process_tags' => array_values($processTag),
                            //         'target_marketplace' => 'amazon',
                            //         // required
                            //     ],
                            //     'unset' => ['process_tags'],
                            // ];
                        } else {
                            $product = [
                                "source_product_id" => $sourceProductId,
                                // required
                                'user_id' => (string) $userId,
                                'source_shop_id' => (string) $sourceShopId,
                                'container_id' => (string) $foundProduct['container_id'],
                                // required
                                'shop_id' => (string) $targetShopId,
                                // required
                                'process_tags' => $processTag,
                                'target_marketplace' => 'amazon',
                            ];
                            // $product = [
                            //     "source_product_id" => (string) $sourceProductId,
                            //     // required
                            //     'user_id' => (string) $userId,
                            //     'source_shop_id' => (string) $sourceShopId,
                            //     'container_id' => (string) $foundProduct['container_id'],
                            //     'childInfo' => [
                            //         'source_product_id' => (string) $sourceProductId,
                            //         // required
                            //         'shop_id' => (string) $targetShopId,
                            //         // required
                            //         'process_tags' => $processTag,
                            //         'target_marketplace' => 'amazon',
                            //         // required
                            //     ],
                            // ];
                        }

                        array_push($productInsert, $product);
                    }
                }
            }

            if (!empty($productInsert)) {
                $appTag = $this->di->getAppCode()->getAppTag();
                if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
                    print_r("enter");
                    /* Trigger websocket (if configured) */
                    $websocketConfig = $this->di->getConfig()->get("app_tags")
                        ->get($appTag)
                        ->get('websocket');
                    if (
                        isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['notification']) &&
                        $websocketConfig['client_id'] &&
                        $websocketConfig['allowed_types']['notification']
                    ) {

                        $helper = $this->di->getObjectManager()->get(ConnectorHelper::class);
                        $helper->handleMessage([
                            'user_id' => $userId,
                            'notification' => $productInsert
                        ]);
                    }

                    /* Trigger websocket (if configured) */
                }

                if (is_null($this->_user_details)) {
                    $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                }

                $amazonShop = $this->_user_details->getShop($targetShopId, $userId);
                $sourceMarketplace = "";
                if (!empty($amazonShop['sources'])) {
                    foreach ($amazonShop['sources'] as $source) {
                        if (isset($source['shop_id']) && $source['shop_id'] == $sourceShopId) {
                            $sourceMarketplace = $source['code'] ?? '';
                            break;
                        }
                    }
                }

                $additionalData = [
                    'source' => [
                        'shop_id' => (string) $sourceShopId,
                        'marketplace' => $sourceMarketplace
                    ],
                    'target' => [
                        'shop_id' => (string) $targetShopId,
                        'marketplace' => 'amazon'
                    ]
                ];
                // $objectManager = $this->di->getObjectManager();
                // $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
                // $res = $helper->marketplaceSaveAndUpdate($productInsert);
                $res = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit')->saveProduct($productInsert, false, $additionalData);
            }
        }
    }
    // public function setUploadedStatusNew($variantList, $feed): void
    // {

    //     $marketplaceHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');

    //     $homeShopId = $feed['shop_id'];
    //     $sourceShopId = $feed['source_shop_id'];
    //     $userId = $feed['user_id'];
    //     $sourceMarketplace = '';
    //     $uploadedVariant = [];
    //     $remoteShopId = 0;
    //     $additionalData = [];
    //     $reattemptVariant = [];
    //     $errors = [];
    //     $err = [];
    //     $shop = $this->_user_details->getShop($homeShopId, $userId);
    //     $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');

    //     if (isset($shop['sources']) && $shop['sources']) {
    //         foreach ($shop['sources'] as $source) {
    //             if (isset($source['shop_id']) && $source['shop_id'] == $sourceShopId) {
    //                 $sourceMarketplace = $source['code'] ?? '';
    //             }
    //         }
    //     }

    //     $additionalData['source'] = [
    //         'marketplace' => $sourceMarketplace,
    //         'shopId' => (string) $sourceShopId
    //     ];
    //     $additionalData['target'] = [
    //         'marketplace' => 'amazon',
    //         'shopId' => (string) $homeShopId
    //     ];
    //     if ($shop && isset($shop['remote_shop_id'])) {
    //         $remoteShopId = $shop['remote_shop_id'];
    //     }

    //     // converting int to string in array
    //     $variantList = implode(',', $variantList);
    //     $variantList = explode(',', $variantList);

    //     foreach ($variantList as $sourceProductId) {
    //         $canChangeStatus = true;
    //         $specifics = [
    //             'source_product_id' => $sourceProductId,
    //             'source_shop_id' => (string)$sourceShopId,
    //             'target_shop_id' => (string)$homeShopId,
    //             'user_id' => $userId,
    //         ];

    //         $productData = $marketplaceHelper->getSingleProduct($specifics);

    //         if (isset($productData['shop_id'], $productData['source_product_id']) && $productData['shop_id'] == $homeShopId && $productData['source_product_id'] == $sourceProductId) {
    //             if (!isset($productData['status']) || $productData['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED || $productData['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER) {
    //                 $sku = $productData['sku'];
    //                 $rawBody['sku'] = rawurlencode($sku);
    //                 $rawBody['shop_id'] = $remoteShopId;
    //                 $rawBody['includedData'] = "issues,attributes,summaries";
    //                 $commonHelper = $this->di->getObjectManager()->get(Helper::class);
    //                 $response = $commonHelper->sendRequestToAmazon('listing', $rawBody, 'GET');
    //                 if (isset($response['success']) && isset($response['response']) && $response['success']) {
    //                     if (!empty($response['response']['issues'])) {
    //                         $issues = $response['response']['issues'];
    //                         $errors[$sourceProductId] = (string) $productData['container_id'];
    //                         $err[$sourceProductId] = $issues;
    //                     } elseif (isset($response['response']['summaries'], $response['response']['attributes'])) {
    //                         $uploadedVariant[$sourceProductId] = (string) $productData['container_id'];
    //                     } else {
    //                         $reattemptVariant[$sourceProductId]['container_id']  = (string)$productData['container_id'];
    //                         $reattemptVariant[$sourceProductId]['sku']  = $sku;
    //                     }
    //                 } else {
    //                     $reattemptVariant[$sourceProductId]['container_id']  = (string)$productData['container_id'];
    //                     $reattemptVariant[$sourceProductId]['sku']  = $sku;
    //                 }
    //             }
    //         }
    //     }
    //     // print_r($reattemptVariant);
    //     // print_r($err);
    //     // print_r($uploadedVariant);die;
    //     $updateDataInsert = [];
    //     foreach ($uploadedVariant as $variant =>  $container_id) {

    //         // $updateData = [
    //         //     "source_product_id" => (string) $variant,
    //         //     // required
    //         //     'user_id' => $userId,
    //         //     'source_shop_id' => $sourceShopId,
    //         //     'childInfo' => [
    //         //         'source_product_id' => (string) $variant,
    //         //         // required
    //         //         'shop_id' => $homeShopId,
    //         //         // required
    //         //         'status' => \App\Amazon\Components\Common\Helper::PRODUCT_STATUS_UPLOADED,
    //         //         'target_marketplace' => 'amazon',
    //         //         // required
    //         //     ],
    //         // ];
    //         $updateData = [
    //             "source_product_id" => (string)$variant,
    //             // required
    //             'user_id' => $userId,
    //             'source_shop_id' => (string) $sourceShopId,
    //             'target_marketplace' => 'amazon',
    //             'status' => Helper::PRODUCT_STATUS_UPLOADED,
    //             'shop_id' => (string)$homeShopId,
    //             'container_id' => $container_id
    //         ];
    //         array_push($updateDataInsert, $updateData);
    //     }
    //     foreach ($errors as $variant => $container_id) {
    //         if (isset($err[$variant])) {

    //             $errorHelper = $this->di->getObjectManager()->get(Error::class);
    //             $formattedErrors = $errorHelper->formateErrorMessages($err[$variant]);
    //             $updateData = [
    //                 "source_product_id" => (string)$variant,
    //                 // required
    //                 'user_id' => $userId,
    //                 'source_shop_id' => (string) $sourceShopId,
    //                 'target_marketplace' => 'amazon',
    //                 // 'status' => Helper::PRODUCT_STATUS_UPLOADED,
    //                 'shop_id' => (string)$homeShopId,
    //                 'container_id' => $container_id
    //             ];
    //             if (isset($formattedErrors['error']) && !empty($formattedErrors['error'])) {
    //                 $updateData['error'] = $formattedErrors['error'];
    //                 $updateData['unset'] = ['process_tags' => 1];
    //             }

    //             if (isset($formattedErrors['warning']) && !empty($formattedErrors['warning'])) {
    //                 $updateData['warning'] = $formattedErrors['warning'];
    //             }
    //             array_push($updateDataInsert, $updateData);
    //         }
    //     }

    //     if (!empty($updateDataInsert)) {
    //         $appTag = $this->di->getAppCode()->getAppTag();
    //         if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
    //             /* Trigger websocket (if configured) */
    //             $websocketConfig = $this->di->getConfig()->get("app_tags")
    //                 ->get($appTag)
    //                 ->get('websocket');
    //             if (
    //                 isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['notification']) &&
    //                 $websocketConfig['client_id'] &&
    //                 $websocketConfig['allowed_types']['notification']
    //             ) {

    //                 $helper = $this->di->getObjectManager()->get(ConnectorHelper::class);
    //                 $helper->handleMessage([
    //                     'user_id' => $userId,
    //                     'notification' => $updateDataInsert
    //                 ]);
    //             }

    //             /* Trigger websocket (if configured) */
    //         }

    //         $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
    //         $res = $editHelper->saveProduct($updateDataInsert, $userId, $additionalData);

    //         if (!empty($reattemptVariant)) {
    //             $handlerData = [
    //                 'type' => 'full_class',
    //                 'class_name' => \App\Amazon\Components\Product\Product::class,
    //                 'method' => 'reattemptUploadedStatus',
    //                 'queue_name' => 'amazon_reattempt_upload',
    //                 'user_id' => $userId,
    //                 'shop_id' => $homeShopId,
    //                 'remoteShopId' => $remoteShopId,
    //                 'reattemptVariant' => $reattemptVariant,
    //                 'additionalData' => $additionalData,
    //                 'delay' => 600
    //             ];
    //             $rmqHelper->pushMessage($handlerData);
    //         }
    //     }
    // }
    // public function reattemptUploadedStatus($sqsData)
    // {
    //     $reattemptKeys = $sqsData['reattemptVariant'];
    //     $userId = $sqsData['user_id'];
    //     $additionalData = $sqsData['additionalData'];
    //     $remoteShopId = $sqsData['remoteShopId'];
    //     $reattemptVariant = [];
    //     $delay = $sqsData['delay'];
    //     $counter = $sqsData['counter'] ?? 1;
    //     foreach ($reattemptKeys as $sourceProductId => $variant) {
    //         $sku = $variant['sku'];
    //         $rawBody['sku'] = rawurlencode($sku);
    //         $rawBody['shop_id'] = $remoteShopId;
    //         $rawBody['includedData'] = "issues,attributes,summaries";
    //         $commonHelper = $this->di->getObjectManager()->get(Helper::class);
    //         $response = $commonHelper->sendRequestToAmazon('listing', $rawBody, 'GET');
    //         if (isset($response['success']) && isset($response['response']) && $response['success']) {
    //             if (!empty($response['response']['issues'])) {
    //                 $issues = $response['response']['issues'];
    //                 $errors[$sourceProductId] = (string) $variant['container_id'];
    //                 $err[$sourceProductId] = $issues;
    //             } elseif (isset($response['response']['summaries'], $response['response']['attributes'])) {
    //                 $uploadedVariant[$sourceProductId] = (string) $variant['container_id'];
    //             } else {
    //                 $reattemptVariant[$sourceProductId]['container_id']  = (string)$variant['container_id'];
    //                 $reattemptVariant[$sourceProductId]['sku']  = $sku;
    //             }
    //         } else {
    //             $reattemptVariant[$sourceProductId]['container_id']  = (string)$variant['container_id'];
    //             $reattemptVariant[$sourceProductId]['sku']  = $sku;
    //         }
    //     }
    //     $updateDataInsert = [];
    //     foreach ($uploadedVariant as $variant =>  $container_id) {
    //         $updateData = [
    //             "source_product_id" => (string)$variant,
    //             // required
    //             'user_id' => $userId,
    //             'source_shop_id' => (string) $additionalData['source']['shopId'],
    //             'target_marketplace' => 'amazon',
    //             'status' => Helper::PRODUCT_STATUS_UPLOADED,
    //             'shop_id' => (string)$additionalData['target']['shopId'],
    //             'container_id' => $container_id
    //         ];
    //         array_push($updateDataInsert, $updateData);
    //     }
    //     foreach ($errors as $variant => $container_id) {
    //         if (isset($err[$variant])) {

    //             $errorHelper = $this->di->getObjectManager()->get(Error::class);
    //             $formattedErrors = $errorHelper->formateErrorMessages($err[$variant]);
    //             $updateData = [
    //                 "source_product_id" => (string)$variant,
    //                 // required
    //                 'user_id' => $userId,
    //                 'source_shop_id' => (string) $additionalData['source']['shopId'],
    //                 'target_marketplace' => 'amazon',
    //                 // 'status' => Helper::PRODUCT_STATUS_UPLOADED,
    //                 'shop_id' => (string)$additionalData['target']['shopId'],
    //                 'container_id' => $container_id
    //             ];
    //             if (isset($formattedErrors['error']) && !empty($formattedErrors['error'])) {
    //                 $updateData['error'] = $formattedErrors['error'];
    //                 $updateData['unset'] = ['process_tags' => 1];
    //             }

    //             if (isset($formattedErrors['warning']) && !empty($formattedErrors['warning'])) {
    //                 $updateData['warning'] = $formattedErrors['warning'];
    //             }
    //             array_push($updateDataInsert, $updateData);
    //         }
    //     }
    //     $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');

    //     $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
    //     $res = $editHelper->saveProduct($updateDataInsert, $userId, $additionalData);
    //     if ($counter >= 3 || !empty($reattemptVariant)) {
    //         $sqsData['reattemptVariant'] = $reattemptVariant;
    //         $sqsData['delay'] = $delay + 600;
    //         $sqsData['counter'] = $counter++;
    //         $rmqHelper->pushMessage($sqsData);
    //     } else {
    //         return true;
    //     }
    // }

    public function getErrorByCode($code)
    {
        $err = false;
        $err = match ($code) {
            'AmazonError101' => "Amazon Category is not selected. Click on Product to edit & select an Amazon Category to upload this Product.",
            'AmazonError102' => "Sku is a required field, please fill sku and upload again.",
            'AmazonError103' => "Product Descriptions are exceeding the 2000 characters limit. HTML tags and spaces do count as characters.",
            'AmazonError104' => "SKU lengths are exceeding the 40 character limit.",
            'AmazonError105' => "Price is a required field, please fill price and upload again.",
            'AmazonError106' => "Price should be greater than 0.01, please update it from the shopify listing page.",
            'AmazonError108' => "Quantity is a required field, please fill quantity and upload again.",
            'AmazonError109' => "Barcode is a required field, please fill barcode and upload again.",
            'AmazonError110' => "Barcode is not valid, please provide a valid barcode and upload again.",
            'AmazonError111' => "'condition_type' is required but not supplied.",
            'AmazonError107' => "Product Syncing is Disabled. Please Enable it from the Settings and Try Again",
            'AmazonError121' => "Product you selected is not an FBM product.",
            'AmazonError123' => "Inventory Syncing is disabled. Please check the Settings and Try Again.",
            'AmazonError124' => 'The selected function cannot be performed on this product because it is not fulfilled by merchant (FBM) product.',
            'AmazonError125' => 'Warehouse in the selected Template Disabled - Please choose an active warehouse for this product.',
            'AmazonError141' => "Price Syncing is Disabled. Please Enable it from the Settings and Try Again.",
            'AmazonError161' => "Product does not have an image.",
            'AmazonError162' => "Image Syncing is Disabled. Please Enable it from Settings and Try Again.",
            'AmazonError501' => "Product not listed on Amazon.",
            'AmazonError900' => "Feed not generated. Kindly contact support.",
            'AmazonError901' => "The feed language is not English. Please change your language preference to English from your Amazon Seller Central account.",
            'AmazonError902' => "Feed Content not found.",
            'AmazonError903' => "Shop not found.",
            'AmazonError301' => "Product does not have a valid ASIN for offer creation. Please initiate lookup to fetch offer status.",
            'AmazonError997' => "Standard Price should be greater than Business Price.",
            'AmazonError998' => "Business Price should be greater than Quantity Tier Price.",
            'AmazonError999' => "Quantity Tier Price should be greater than Minimum Allowed Price.",
            'AmazonError996' => "Some error occur during sync processing. You can try after sometime or connect with support if not resolved!",
            'AmazonError1099' => "Product is already Listed Kindly execute Sync Product Action",
            'AmazonError1098' => "Sync Product can only be executed in Listed Product",
            default => "Value Must Have Some Problem Kindly check the Mapping.",
        };
        return $err;
    }

    public function saveErrorInProduct($sourceProductIds, $type, $targetShopId, $sourceShopId, $userId, $products = [], $feed = []): void
    {
        $date = date(DATE_RFC2822);
        $time = str_replace("+0000", "GMT", $date);
        $errorVariantList = [];

        if (!$targetShopId) {
            $targetShopId = $feed['specifics']['shop_id'];
        }
        $sourceMarketplace = '';
        if (!$type) {
            $feedType = $feed['type'];
            $type = Feed::FEED_TYPE[$feedType];
        }

        if (!$userId && isset($feed['user_id'])) {
            $userId = $feed['user_id'];
        }

        if (!$sourceShopId && isset($feed['source_shop_id'])) {
            $sourceShopId = $feed['source_shop_id'];
        }
        foreach ($this->di->getUser()->shops as $shop) {
            if ($shop['_id'] == $sourceShopId) {
                $sourceMarketplace = $shop['marketplace'] ?? "shopify";
            }
        }
        $additionalData['source'] = [
            'marketplace' => $sourceMarketplace,
            'shopId' => (string)$sourceShopId
        ];
        $additionalData['target'] = [
            'marketplace' => 'amazon',
            'shopId' => (string)$targetShopId
        ];

        $useMarketplace = $this->di->getConfig()->use_marketplace_key ?? true;

        if (!empty($sourceProductIds)) {
            $productInsert = [];
            foreach ($sourceProductIds as $sourceProductId => $errorMsg) {
                $errorVariantList[] = $sourceProductId;
                $foundProduct = null;
                $error = [];
                $alreadySavedError = [];
                if (!empty($products)) {
                    $productKey = array_search($sourceProductId, array_column($products, 'source_product_id'));
                    if ($productKey !== false) {
                        $foundProduct = $products[$productKey];
                    }
                }

                if (!$foundProduct) {
                    $data = ['source_product_id' => (string) $sourceProductId, "target_shop_id" => (string) $targetShopId, 'source_shop_id' => (string) $sourceShopId, 'user_id' => (string) $userId];
                    $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                    $productData = $helper->getSingleProduct($data);

                    $foundProduct = $productData;
                }

                if ($foundProduct) {
                    $msg = "";
                    $code = "";
                    foreach ($errorMsg as $err) {
                        # code...
                        if ($feed) {
                            $errData = explode(" : ", (string) $err);
                            if (count($errData) == '2') {
                                $code = $errData[0];
                                $msg = $errData[1];
                            }
                        } else {
                            $msg = $this->getErrorByCode($err);
                            if ($msg) {
                                $code = $err;
                            } else {
                                $code = "AmazonError001";
                                $msg = $err;
                            }
                        }

                        $error[] = [
                            'type' => $type,
                            'code' => $code,
                            'message' => $msg,
                            'time' => $time
                        ];
                    }

                    if (!empty($error)) {
                        $product = [
                            "source_product_id" => (string) $sourceProductId,
                            // required
                            'user_id' => $userId,
                            'source_shop_id' => $sourceShopId,
                            'container_id' => (string) $foundProduct['container_id'],
                            'childInfo' => [
                                'source_product_id' => (string) $sourceProductId,
                                // required
                                'shop_id' => $targetShopId,
                                // required
                                'error' => $error,
                                'target_marketplace' => 'amazon',
                                // required
                            ],
                        ];
                        if (!$useMarketplace) {
                            unset($product['childInfo']);
                            $product['target_marketplace'] = 'amazon';
                            $product['shop_id'] = $targetShopId;
                        }

                        array_push($productInsert, $product);
                    }
                }
            }

            if (!empty($productInsert)) {
                $objectManager = $this->di->getObjectManager();
                // $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
                // $res = $helper->marketplaceSaveAndUpdate($productInsert);
                if ($useMarketplace) {
                    $objectManager->get('\App\Connector\Models\Product\Marketplace')->marketplaceSaveAndUpdate($productInsert);
                } else {
                    $objectManager->get('\App\Connector\Models\Product\Edit')->saveProduct($productInsert, $userId, $additionalData);
                }
            }
        }

        $objectManager = $this->di->getObjectManager();
        $feedHelper = $objectManager->get(Feed::class);
        $feedHelper->removeErrorFromProduct($errorVariantList, $feed);
    }

    public function setUploadedStatus($variantList, $feed): void
    {
        $marketplaceHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');

        $homeShopId = $feed['shop_id'];
        $sourceShopId = $feed['source_shop_id'];
        $userId = $feed['user_id'];
        $sourceMarketplace = '';
        $uploadedVariant = [];
        $remoteShopId = 0;
        $additionalData = [];
        $errors = [];
        $err = [];
        $shop = $this->_user_details->getShop($homeShopId, $userId);

        if (isset($shop['sources']) && $shop['sources']) {
            foreach ($shop['sources'] as $source) {
                if (isset($source['shop_id']) && $source['shop_id'] == $sourceShopId) {
                    $sourceMarketplace = $source['code'] ?? '';
                }
            }
        }

        $additionalData['source'] = [
            'marketplace' => $sourceMarketplace,
            'shopId' => (string) $sourceShopId
        ];
        $additionalData['target'] = [
            'marketplace' => 'amazon',
            'shopId' => (string) $homeShopId
        ];
        if ($shop && isset($shop['remote_shop_id'])) {
            $remoteShopId = $shop['remote_shop_id'];
        }

        // converting int to string in array
        $variantList = implode(',', $variantList);
        $variantList = explode(',', $variantList);

        // OPTIMIZATION: Fetch all products using direct MongoDB query instead of N queries
        // Mimicking getSingleProduct behavior: fetch source products and target products, then merge
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('product_container');
        
        // Batch fetch all source products in a single query
        $sourceProducts = $productCollection->find([
            'source_product_id' => ['$in' => $variantList],
            'shop_id' => $sourceShopId,
            'user_id' => $userId
        ])->toArray();
        
        // Batch fetch all target products in a single query
        $targetProducts = $productCollection->find([
            'source_product_id' => ['$in' => $variantList],
            'shop_id' => $homeShopId,
            'user_id' => $userId
        ])->toArray();
        
        // Build indexed maps for quick lookups
        $sourceProductsMap = [];
        foreach ($sourceProducts as $product) {
            $product = json_decode(json_encode($product), true);
            if (isset($product['source_product_id'])) {
                $sourceProductsMap[$product['source_product_id']] = $product;
            }
        }
        
        $targetProductsMap = [];
        foreach ($targetProducts as $product) {
            $product = json_decode(json_encode($product), true);
            if (isset($product['source_product_id'])) {
                $targetProductsMap[$product['source_product_id']] = $product;
            }
        }
        
        // Now process each variant using the pre-fetched data
        foreach ($variantList as $sourceProductId) {
            $canChangeStatus = true;
            
            // Get source and target products from the pre-fetched maps
            $sourceProduct = $sourceProductsMap[$sourceProductId] ?? [];
            $targetProduct = $targetProductsMap[$sourceProductId] ?? [];
            
            // If target product doesn't exist, skip this product (won't process for upload status)
            if (empty($targetProduct)) {
                continue;
            }
            
            // Merge products similar to getSingleProduct: target + source (target takes precedence)
            $productData = $targetProduct + $sourceProduct;
             
            if (isset($productData['shop_id'], $productData['source_product_id']) && $productData['shop_id'] == $homeShopId && $productData['source_product_id'] == $sourceProductId) {
                if (!isset($productData['status']) || $productData['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED || $productData['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER) {
                   
                     $uploadedVariant[$sourceProductId] = (string) $productData['container_id'];
                    }
                }
            }
        

        // print_r($uploadedVariant);die;
        $updateDataInsert = [];
        foreach ($uploadedVariant as $variant =>  $container_id) {

            $updateData = [
                "source_product_id" => (string)$variant,
                // required
                'user_id' => $userId,
                'source_shop_id' => (string) $sourceShopId,
                'target_marketplace' => 'amazon',
                'status' => Helper::PRODUCT_STATUS_UPLOADED,
                'shop_id' => (string)$homeShopId,
                'container_id' => $container_id,
                'last_synced_at' => date('c')
            ];
            array_push($updateDataInsert, $updateData);
        }
        foreach ($errors as $variant => $container_id) {
            if (isset($err[$variant])) {

                $errorHelper = $this->di->getObjectManager()->get(Error::class);
                $formattedErrors = $errorHelper->formateErrorMessages($err[$variant]);
                $updateData = [
                    "source_product_id" => (string)$variant,
                    // required
                    'user_id' => $userId,
                    'source_shop_id' => (string) $sourceShopId,
                    'target_marketplace' => 'amazon',
                    // 'status' => Helper::PRODUCT_STATUS_UPLOADED,
                    'shop_id' => (string)$homeShopId,
                    'container_id' => $container_id
                ];
                if (isset($formattedErrors['error']) && !empty($formattedErrors['error'])) {
                    $updateData['error'] = $formattedErrors['error'];
                    $updateData['unset'] = ['process_tags' => 1];
                }

                if (isset($formattedErrors['warning']) && !empty($formattedErrors['warning'])) {
                    $updateData['warning'] = $formattedErrors['warning'];
                }
                array_push($updateDataInsert, $updateData);
            }
        }

        if (!empty($updateDataInsert)) {
            $appTag = $this->di->getAppCode()->getAppTag();
            if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
                /* Trigger websocket (if configured) */
                $websocketConfig = $this->di->getConfig()->get("app_tags")
                    ->get($appTag)
                    ->get('websocket');
                if (
                    isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['notification']) &&
                    $websocketConfig['client_id'] &&
                    $websocketConfig['allowed_types']['notification']
                ) {

                    $helper = $this->di->getObjectManager()->get(ConnectorHelper::class);
                    $helper->handleMessage([
                        'user_id' => $userId,
                        'notification' => $updateDataInsert
                    ]);
                }

                /* Trigger websocket (if configured) */
            }
           
            $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
            $res = $editHelper->saveProduct($updateDataInsert, $userId, $additionalData);

            // $res = $marketplaceHelper->marketplaceSaveAndUpdate($updateDataInsert);
        }
    }



    /**
     * @param $response
     * @param $feedContent
     * @param $targetShop
     * @param $targetShopId
     * @param $sourceShopId
     * @param $userId
     */
    #[ArrayShape(['addTagProductList' => "array", 'removeErrorProductList' => "array", 'removeTagProductList' => "array|int[]|string[]", 'productErrorList' => "array"])]
    public function processResponse($response, $feedContent, $tag, $type, $targetShop, $targetShopId, $sourceShopId, $userId, $products = [], $productErrorList = [], $unsetTag = false, $localDelivery = false, $merchantDataSourceProduct = []): array
    {
        $addTagProductList = [];
        $removeErrorProductList = [];
        $removeTagProductList = [];
        $removeWarningProductList = [];
        foreach ($targetShop['warehouses'] as $warehouse) {
            $marketplaceId = $warehouse['marketplace_id'];
        }

        if (isset($response['success']) && $response['success']) {
            if (isset($response['result'][$marketplaceId]['response'])) {
                $remoteResponse = $response['result'][$marketplaceId]['response'];
                if (isset($response['result'][$marketplaceId]['response'], $response['result'][$marketplaceId]['newFunction']) && $response['result'][$marketplaceId]['response'] && $response['result'][$marketplaceId]['newFunction']) {
                    foreach ($remoteResponse as $productResponse) {
                        $addTagProductList[] = $productResponse;
                        $removeErrorProductList[] = $productResponse;
                        $removeWarningProductList[] = $productResponse;
                    }
                } else {
                    foreach ($remoteResponse as $sourceProductId => $productResponse) {
                        if (isset($productResponse['success']) && $productResponse['success']) {
                            $addTagProductList[] = $sourceProductId;
                            $removeErrorProductList[] = $sourceProductId;
                            $removeWarningProductList[] = $sourceProductId;
                        } elseif (isset($productResponse['message'])) {
                            $removeTagProductList[] = $sourceProductId;
                            $productErrorList[$sourceProductId] = [$productResponse['message']];
                        } else {
                            $removeTagProductList[] = $sourceProductId;
                            $productErrorList[$sourceProductId] = ['AmazonError900'];
                        }
                    }
                }
            }
        } else {
            if (isset($response['msg'])) {
                $removeTagProductList = array_keys($feedContent);
                $productErrorList = array_fill_keys($removeTagProductList, ['AmazonError900']);
            }
        }

        if (!empty($productErrorList) && empty($removeTagProductList)) {
            $removeTagProductList = array_keys($productErrorList);
        }

        if (!$localDelivery) {
            $errorIds = array_keys($productErrorList);
            $sourceProductIds = [...$addTagProductList, ...$removeTagProductList, ...$removeErrorProductList, ...$errorIds];
            $sourceProductIds = array_unique($sourceProductIds);
            $specifics = ['addTagProductList' => $addTagProductList, 'removeErrorProductList' => $removeErrorProductList, 'removeTagProductList' => $removeTagProductList, 'productErrorList' => $productErrorList, 'removeWarningProductList' => $removeWarningProductList];
            $this->marketplaceUpdate($specifics, $errorIds, $sourceProductIds, $tag, $type, $targetShopId, $sourceShopId, $userId, $products, $unsetTag);
            // add tags in products
            // if (!empty($addTagProductList)) {
            //     $this->addTags($addTagProductList, $tag, $targetShopId, $sourceShopId, $userId, $products, $unsetTag);
            // }

            // // remove error in products
            // if (!empty($removeErrorProductList)) {
            //     $this->removeErrorQuery($removeErrorProductList, $type, $targetShopId, $sourceShopId, $userId, $products);
            // }

            // // remove tags in products
            // if (!empty($removeTagProductList)) {
            //     $finalTag = ($unsetTag) ? $unsetTag : $tag;
            //     $tags = [
            //         $finalTag,
            //     ];
            //     $this->removeTag($removeTagProductList, $tags, $targetShopId, $sourceShopId, $userId, $products, []);
            // }

            // //add error in products
            // if (!empty($productErrorList)) {
            //     $this->saveErrorInProduct($productErrorList, $type, $targetShopId, $sourceShopId, $userId, $products, []);
            // }
        }

        return [
            'addTagProductList' => $addTagProductList,
            'removeErrorProductList' => $removeErrorProductList,
            'removeTagProductList' => $removeTagProductList,
            'productErrorList' => $productErrorList,
        ];
    }

    public function marketplaceUpdate($data = [], $errorIds = [], $sourceProductIds = [], $tag = "", $type = "", $targetShopId = "", $sourceShopId = false, $userId = "", $products = [], $unsetTag = false, $feed = [], $warnIds = [])
    {
        $date = date(DATE_RFC2822);
        $time = str_replace("+0000", "GMT", $date);
        $res = [];
        $message = '';
        if ($tag) {
            $message = $this->getMessage($tag);
        }

        if (!$targetShopId) {
            $targetShopId = $feed['specifics']['shop_id'];
        }

        $useMarketplace = $this->di->getConfig()->use_marketplace_key ?? true;

        if (empty($type)) {
            $feedType = $feed['type'];
            $type = Feed::FEED_TYPE[$feedType];
        }

        if (!$userId && isset($feed['user_id'])) {
            $userId = $feed['user_id'];
        }

        if (!$sourceShopId) {
            $sourceShopId = $feed['source_shop_id'];
        }

        if (empty($sourceProductIds)) {
            if ($feed['type'] == 'JSON_LISTINGS_FEED') {
                $ids = $feed['specifics']['ids'];
                $messageIds = [];
                foreach ($ids as $messageId) {
                    $msgs = explode('_', (string) $messageId);
                    $messageIds[$msgs[1]] = $msgs['0'];
                }

                $sourceProductIds = array_values($messageIds);
            } else {
                $sourceProductIds = $feed['specifics']['ids'];
            }

            //change array of int to array of string
            // $encoded = implode(',', $sourceProductIds);
            // $sourceProductIds = explode(',', $encoded);
            $saveError = false;
            $addTagProductList = $data['addTagProductList'] ?? [];
            $removeErrorProductList = $data['removeErrorProductList'] ?? [];
            $productWarnList = $data['productWarnList'] ?? [];
            $removeTagProductList = $data['removeTagProductList'] ?? [];
            $productErrorList = $data['productErrorList'] ?? [];
            $removeWarningProductList = $data['removeWarningProductList'] ?? [];
            if (empty($errorIds) && !empty($feed) && !empty($productErrorList)) {
                $errorIds = array_keys($productErrorList);
                $saveError = true;
            }
        }

        //change array of int to array of string
        // $encoded = implode(',', $sourceProductIds);
        // $sourceProductIds = explode(',', $encoded);
        $saveError = false;
        $addTagProductList = $data['addTagProductList'] ?? [];
        $removeErrorProductList = $data['removeErrorProductList'] ?? [];
        $productWarnList = $data['productWarnList'] ?? [];
        $removeTagProductList = $data['removeTagProductList'] ?? [];
        $productErrorList = $data['productErrorList'] ?? [];
        $removeWarningProductList = $data['removeWarningProductList'] ?? [];
        if (empty($errorIds) && !empty($feed) && !empty($productErrorList)) {
            $errorIds = array_keys($productErrorList);
            $saveError = true;
        }

        $productInsert = [];
        $sourceProductIds = array_unique($sourceProductIds);

        foreach ($sourceProductIds as $sourceProductId) {
            $flag = false;
            $foundProduct = null;
            $processTag = [];
            $alreadySavedProcessTag = [];
            if (!empty($products)) {
                $productKey = array_search($sourceProductId, array_column($products, 'source_product_id'));
                if ($productKey !== false) {
                    $foundProduct = $products[$productKey];
                }
            }

            if (!$foundProduct) {
                $data = [
                    'source_product_id' => (string) $sourceProductId,
                    "target_shop_id" => (string) $targetShopId,
                    'source_shop_id' => (string) $sourceShopId,
                    'user_id' => (string) $userId
                ];
                $productMarketplace = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
                $query = [
                    'user_id' => $userId,
                    'shop_id' => (string)$sourceShopId,
                    'source_product_id' => (string) $sourceProductId
                ];
                $productFetch = $productMarketplace->getproductbyQuery($query, $options);
                if (isset($productFetch) && isset($productFetch[0])) {
                    $foundProduct = $productFetch[0];
                }
            }

            if ($foundProduct) {
                $foundProduct = json_decode(json_encode($foundProduct), true);
                $product = [
                    "source_product_id" => (string) $sourceProductId,
                    // required
                    'user_id' => (string) $userId,
                    'source_shop_id' => (string) $sourceShopId,
                    'container_id' => (string) $foundProduct['container_id'],
                    'childInfo' => [
                        'source_product_id' => (string) $sourceProductId,
                        // required
                        'shop_id' => (string) $targetShopId,
                        // required
                        // 'process_tags' => array_values($processTag),
                        'target_marketplace' => 'amazon',
                        // required
                    ],
                ];
                if (!$useMarketplace) {
                    unset($product['childInfo']);
                    $product['source_product_id'] = (string) $sourceProductId;
                    $product['shop_id'] = (string) $targetShopId;
                    $product['target_marketplace'] = 'amazon';
                }

                if (isset($foundProduct['marketplace']) || !$useMarketplace) {
                    $amazonData = $this->getMarketplace($foundProduct, $targetShopId, $sourceProductId);
                    if (in_array($sourceProductId, $removeTagProductList)) {
                        $finalTag = $unsetTag ?: $tag;
                        $tags = [
                            $finalTag,
                        ];
                        $alreadcySavedProcessTag = [];
                        $processTag = [];

                        if (isset($amazonData['process_tags'])) {
                            $processTag = $amazonData['process_tags'];
                            $alreadySavedProcessTag = $amazonData['process_tags'];
                        }

                        if (!empty($tags) && !empty($processTag)) {
                            foreach ($tags as $tag) {
                                if (isset($processTag[$tag])) {
                                    unset($processTag[$tag]);
                                }
                            }
                        }

                        if (!empty(array_diff(
                            array_keys($alreadcySavedProcessTag),
                            array_keys($processTag)
                        ))) {
                            if (empty($processTag)) {
                                $product['unset'][] = 'process_tags';
                                $flag = true;
                            } else {
                                $product['childInfo']['process_tags'] = $processTag;
                                $flag = true;
                                if (!$useMarketplace) {
                                    unset($product['childInfo']);
                                    $product['process_tags'] = $processTag;
                                }
                            }
                        }
                    }

                    if (in_array($sourceProductId, $errorIds)) {
                        $error = [];
                        $errorMsg = $productErrorList[$sourceProductId];
                        foreach ($errorMsg as $err) {
                            # code...
                            if ($feed) {
                                $errData = explode(" : ", (string) $err);
                                if (count($errData) == '2') {
                                    $code = $errData[0];
                                    $msg = $errData[1];
                                } elseif (count($errData) > 2) {
                                    $code = $errData[0];
                                    $msgs = array_slice($errData, 1);
                                    $msg = implode(':', $msgs);
                                }
                            } else {
                                $msg = $this->getErrorByCode($err);
                                if ($msg) {
                                    $code = $err;
                                } else {
                                    $code = "AmazonError001";
                                    $msg = $err;
                                }
                            }

                            $error[] = [
                                'type' => $type,
                                'code' => $code,
                                'message' => $msg
                            ];
                        }

                        if (!empty($error)) {
                            $product['childInfo']['error'] = $error;
                            $flag = true;
                            if (!$useMarketplace) {
                                unset($product['childInfo']);
                                $product['error'] = $error;
                            }
                        }
                    }

                    if (in_array($sourceProductId, $warnIds)) {
                        $warning = [];
                        $warnMsg = $productWarnList[$sourceProductId];
                        foreach ($warnMsg as $war) {
                            # code...
                            if ($feed) {
                                $code = $war['code'];
                                $msg = $war['message'];
                            }

                            $warning[] = [
                                'type' => $type,
                                'code' => $code,
                                'message' => $msg
                            ];
                        }

                        if (!empty($warning)) {
                            $product['childInfo']['warning'] = $warning;
                            $flag = true;
                            if (!$useMarketplace) {
                                unset($product['childInfo']);
                                $product['warning'] = $warning;
                            }
                        }
                    }

                    if (in_array($sourceProductId, $removeErrorProductList)) {
                        $alreadySavedError = [];
                        $error = [];
                        if (isset($amazonData['error'])) {
                            $alreadySavedError = $amazonData['error'];
                            $error = $amazonData['error'];
                            // condition for product delete
                            if ($type == "delete" || $type == "product") {
                                $error = [];
                            } else {
                                foreach ($error as $unsetKey => $singleError) {
                                    if (isset($singleError['type']) && $singleError['type'] === $type) {
                                        unset($error[$unsetKey]);
                                    }
                                }
                            }
                        }

                        if (!empty(array_diff(array_keys($alreadySavedError), array_keys($error)))) {
                            if (empty($error)) {
                                $product['unset'][] = 'error';
                                $flag = true;
                            } else {
                                $product['childInfo']['error'] = $error;
                                $flag = true;
                                if (!$useMarketplace) {
                                    unset($product['childInfo']);
                                    $product['error'] = $error;
                                }
                            }
                        }
                    }

                    if (in_array($sourceProductId, $removeWarningProductList)) {
                        $alreadySavedWarning = [];
                        $warning = [];
                        if (isset($amazonData['warning'])) {
                            $alreadySavedWarning = $amazonData['warning'];
                            $warning = $amazonData['warning'];
                            // condition for product delete
                            if ($type == "delete" || $type == "product") {
                                $warning = [];
                            } else {
                                foreach ($warning as $unsetKey => $singleWarning) {
                                    if (isset($singleWarning['type']) && $singleWarning['type'] === $type) {
                                        unset($warning[$unsetKey]);
                                    }
                                }
                            }
                        }

                        if (!empty(array_diff(array_keys($alreadySavedWarning), array_keys($warning)))) {
                            if (empty($warning)) {
                                $product['unset'][] = 'warning';
                                $flag = true;
                            } else {
                                $product['childInfo']['warning'] = $warning;
                                $flag = true;
                                if (!$useMarketplace) {
                                    unset($product['childInfo']);
                                    $product['warning'] = $warning;
                                }
                            }
                        }
                    }

                    if (in_array($sourceProductId, $addTagProductList)) {
                        if ($amazonData) {
                            $processTag = [];
                            $alreadySavedProcessTag = [];
                            if (isset($amazonData['process_tags'])) {
                                $processTag = $amazonData['process_tags'];
                                if (!in_array($tag, array_keys($processTag))) {
                                    $processTag = $amazonData['process_tags'];
                                    $alreadySavedProcessTag = $processTag;
                                } elseif ($unsetTag) {
                                    unset($processTag[$unsetTag]);
                                }
                            }

                            if (
                                empty(($alreadySavedProcessTag))
                                || array_search($tag, array_keys($alreadySavedProcessTag)) === false
                            ) {
                                $data = [
                                    'msg' => $message,
                                    'time' => $time
                                ];
                                $processTag[$tag] = $data;
                            }
                        } else {
                            $data = [
                                'msg' => $message,
                                'time' => $time
                            ];
                            $processTag[$tag] = $data;
                        }

                        if (
                            !empty($processTag) &&
                            !empty(array_diff(array_keys($processTag), array_keys($alreadySavedProcessTag)))
                        ) {
                            if (in_array($unsetTag, array_keys($processTag))) {
                                unset($processTag[$unsetTag]);
                            }

                            $product['childInfo']['process_tags'] = $processTag;
                            $flag = true;
                            if (!$useMarketplace) {
                                unset($product['childInfo']);
                                $product['process_tags'] = $processTag;
                            }
                        }
                    }

                    if ($flag) {
                        array_push($productInsert, $product);
                    }
                }
            }
        }

        if (!empty($productInsert)) {
            $appTag = $this->di->getAppCode()->getAppTag();
            if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
                /* Trigger websocket (if configured) */
                $websocketConfig = $this->di->getConfig()->get("app_tags")
                    ->get($appTag)
                    ->get('websocket');
                if (
                    isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['notification']) &&
                    $websocketConfig['client_id'] &&
                    $websocketConfig['allowed_types']['notification']
                ) {

                    $helper = $this->di->getObjectManager()->get(ConnectorHelper::class);
                    $helper->handleMessage([
                        'user_id' => $userId,
                        'notification' => $productInsert
                    ]);
                }

                /* Trigger websocket (if configured) */
            }

            $objectManager = $this->di->getObjectManager();

            // $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
            // $res = $helper->marketplaceSaveAndUpdate($productInsert);

            if ($useMarketplace) {
                $res = $objectManager->get('\App\Connector\Models\Product\Marketplace')->marketplaceSaveAndUpdate($productInsert);
            } else {

                if (is_null($this->_user_details)) {
                    $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                }

                $amazonShop = $this->_user_details->getShop($targetShopId, $userId);
                $sourceMarketplace = "";
                if (!empty($amazonShop['sources'])) {
                    foreach ($amazonShop['sources'] as $source) {
                        if (isset($source['shop_id']) && $source['shop_id'] == $sourceShopId) {
                            $sourceMarketplace = $source['code'] ?? '';
                            break;
                        }
                    }
                }

                $additionalData = [
                    'source' => [
                        'shop_id' => (string) $sourceShopId,
                        'marketplace' => $sourceMarketplace
                    ],
                    'target' => [
                        'shop_id' => (string) $targetShopId,
                        'marketplace' => 'amazon'
                    ]
                ];
                $res = $objectManager->get('\App\Connector\Models\Product\Edit')->saveProduct($productInsert, $userId, $additionalData);
            }
        }

        if ($saveError) {
            $objectManager = $this->di->getObjectManager();
            $feedHelper = $objectManager->get(Feed::class);
            $res = $feedHelper->removeErrorFromProduct($errorIds, $feed);
        }

        return $res;
    }

    /**
     * @param $code
     * @param $message
     * @param $success
     * @param $error
     * @param $warning
     * @return array
     */
    public function setResponse($code, $message, $success_product, $error, $warning, $success = true)
    {
        //add timeout in sec for requeue
        return [
            'CODE' => $code,
            'message' => $message,
            'success' => $success,
            'count' => [
                'success' => $success_product,
                'error' => $error,
                'warning' => $warning,
            ],
        ];
    }

    public function addStatus($filterArray, $status, $homeShopId): void
    {
        $shop = $this->_user_details->getShop($homeShopId, $this->_user_id);
        $sellerId = $shop['warehouses'][0]['seller_id'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);

        $productData = $productCollection->findOne($filterArray, ["typeMap" => ['root' => 'array', 'document' => 'array']]);

        if ($productData) {
            $saveArray = [];
            $amazonData = [];
            if (isset($productData['marketplace']['amazon']) && is_array($productData['marketplace']['amazon'])) {
                foreach ($productData['marketplace']['amazon'] as $amazonShops) {
                    if (isset($amazonShops['shop_id']) && $amazonShops['shop_id'] == $homeShopId) {
                        $saveArray = $amazonShops;
                        if (!isset($saveArray['status']) || $saveArray['status'] == Helper::PRODUCT_STATUS_AVAILABLE_FOR_OFFER) {
                            $saveArray['status'] = $status;
                            $saveArray['seller_id'] = $sellerId;
                            // $amazonData = $productData['marketplace']['amazon'];
                            $filter1Array = $filterArray;
                            $filter1Array['marketplace.amazon.shop_id'] = (string) $amazonShops['shop_id'];
                            $productCollection->updateOne(
                                $filter1Array,
                                ['$set' => ['marketplace.amazon.$.status' => $status, 'marketplace.amazon.$.seller_id' => $sellerId]]
                            );
                        }

                        break;
                    }
                }

                if (empty($saveArray)) {
                    $saveArray['shop_id'] = (string) $homeShopId;
                    $saveArray['status'] = $status;
                    $saveArray['seller_id'] = $sellerId;
                    //  $amazonData['marketplace']['amazon'][] = $saveArray
                    $productCollection->updateOne($filterArray, ['$push' => ['marketplace.amazon' => $saveArray]]);
                }
            }

            if (empty($saveArray)) {
                $saveArray['shop_id'] = (string) $homeShopId;
                $saveArray['status'] = $status;
                $saveArray['seller_id'] = $sellerId;
                $amazonData['marketplace']['amazon'][] = $saveArray;
                $productCollection->updateOne($filterArray, ['$set' => $amazonData]);
            }
        }
    }

    public function removeStatusFromProduct($variantList, $targetShopId, $sourceShopId, $userId, $feed = []): void
    {
        $remoteShopId = 0;
        $sourceMarketplace = "";
        $targetListingIds = [];
        $shop = $this->_user_details->getShop($targetShopId, $userId);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $amazonCollection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);

        if ($shop && isset($shop['remote_shop_id'])) {
            $remoteShopId = $shop['remote_shop_id'];
        }

        // converting int to string in array
        $variantList = implode(',', $variantList);
        $variantList = explode(',', $variantList);

        if (!$targetShopId) {
            $targetShopId = $feed['specifics']['shop_id'];
        }

        if (!$userId && isset($feed['user_id'])) {
            $userId = $feed['user_id'];
        }

        if (!$sourceShopId && isset($feed['source_shop_id'])) {
            $sourceShopId = $feed['source_shop_id'];
        }

        $sources = $shop['sources'] ?? [];

        if (!empty($sources)) {
            foreach ($sources as $source) {
                if (isset($source['shop_id']) && $source['shop_id'] == $sourceShopId) {
                    if (isset($source['code']) && !empty($source['code'])) {
                        $sourceMarketplace = $source['code'];
                    }
                }
            }
        }

        $searchSku = [];
        $notDeletedVariant = [];
        $productList = [];

        // Direct query to fetch all product data at once instead of calling getSingleProduct in a loop
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        
        // Query for source products with typeMap to return arrays directly
        $sourceProducts = $productCollection->find([
            'source_product_id' => ['$in' => $variantList],
            'user_id' => $userId,
            'shop_id' => (string)$sourceShopId,
        ], ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
        
        // Query for target products with typeMap to return arrays directly
        $targetProducts = $productCollection->find([
            'source_product_id' => ['$in' => $variantList],
            'user_id' => $userId,
            'shop_id' => (string)$targetShopId,
        ], ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
        
        // Create lookup arrays for efficient merging
        $sourceProductsBySourceId = [];
        foreach ($sourceProducts as $product) {
            $sourceProductsBySourceId[$product['source_product_id']] = $product;
        }
        
        $targetProductsBySourceId = [];
        foreach ($targetProducts as $product) {
            $targetProductsBySourceId[$product['source_product_id']] = $product;
        }
        
        // Merge source and target product data for each variant
        foreach ($variantList as $variant) {
            $sourceProduct = $sourceProductsBySourceId[$variant] ?? [];
            $targetProduct = $targetProductsBySourceId[$variant] ?? [];
            
            // Merge target product data with source product data (target takes precedence)
            $productData = $targetProduct + $sourceProduct;
            
            if (empty($sourceMarketplace) && isset($productData['source_marketplace'])) {
                $sourceMarketplace = $productData['source_marketplace'];
            }

            $productList[] = $productData;
            if (isset($productData['sku'])) {
                $searchSku[$productData['source_product_id']] = $productData['sku'];
            }
        }

        // if (!empty($searchSku)) {
        //     $skus = array_values($searchSku);
        //     $chunk = array_chunk($skus, 5);
        //     $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);

        //     foreach ($chunk as $value) {
        //         $specifics['id'] = $value;
        //         $specifics['shop_id'] = $remoteShopId;
        //         $specifics['home_shop_id'] = $targetShopId;
        //         $specifics['id_type'] = 'SKU';
        //         $response = $commonHelper->sendRequestToAmazon('product', $specifics, 'GET');
        //         if (isset($response['success']) && $response['success']) {
        //             if (isset($response['response']) && is_array($response['response'])) {
        //                 foreach ($response['response'] as $barcode => $asin) {
        //                     $notDeletedVariant[] = array_search($barcode, $searchSku);
        //                 }
        //             }
        //         }
        //     }
        // }

        $deletedVariant = $variantList;
        $asinData = [];
        $key = 0;
        foreach ($productList as $productData) {

            if (isset($productData['source_product_id']) && in_array($productData['source_product_id'], $deletedVariant)  && isset($productData['type'])) {

                if (isset($productData['status']) || isset($productData['error']) || isset($productData['asin'])) {

                    if(isset($productData['target_listing_id'])) {
                        $targetListingIds[] = new \MongoDB\BSON\ObjectId($productData['target_listing_id']);
                    }

                    // $params = ['data' => ['user_id' => $userId, 'source_product_id' => (string)$productData['source_product_id'] , 'removeProductWebhook' => true], 'source' => ['shopId' => (string)$sourceShopId, 'marketplace' => $sourceMarketplace], 'target' => ['shopId' => (string)$targetShopId, 'marketplace' => "amazon"]];
                    // $productHelper = $this->di->getObjectManager()->get(ProductHelper::class);
                    // $response = $productHelper->manualUnmap($params);
                    // print_r($response);die;
                    $params['source'] = [
                        'marketplace' => $sourceMarketplace,
                        'shopId' => (string) $sourceShopId
                    ];
                    $params['target'] = [
                        'marketplace' => 'amazon',
                        'shopId' => (string) $targetShopId
                    ];

                    $asinData[$key] =
                        [
                            'target_marketplace' => 'amazon',
                            'shop_id' => (string) $targetShopId,
                            'container_id' => (string)$productData['container_id'],
                            'source_product_id' => (string)$productData['source_product_id'],
                            'user_id' => $userId,
                            'source_shop_id' => (string)$sourceShopId,
                        ];
                    if (isset($productData['matchedwith'])) {
                        $asinData[$key]['unset']['matchedwith'] = 1;
                    }
                    if (isset($productData['target_listing_id'])) {
                        $asinData[$key]['unset']['target_listing_id'] = 1;
                    }
                    if (isset($productData['fulfillment_type'])) {
                        $asinData[$key]['unset']['fulfillment_type'] = 1;
                    }
                    if (isset($productData['shopify_sku'])) {
                        $asinData[$key]['unset']['shopify_sku'] = 1;
                    }
                    if (isset($productData['matched_at'])) {
                        $asinData[$key]['unset']['matched_at'] = 1;
                    }
                    if (isset($productData['asin'])) {
                        $asinData[$key]['unset']['asin'] = 1;
                    }
                    if (isset($productData['error'])) {
                        $asinData[$key]['unset']['error'] = 1;
                    }
                    if (isset($productData['warning'])) {
                        $asinData[$key]['unset']['warning'] = 1;
                    }                    
                    if (isset($productData['status']) && $productData['status'] != Helper::PRODUCT_STATUS_AVAILABLE_FOR_OFFER) {
                        $asinData[$key]['unset']['status'] = 1;
                    }
                    $key++;
                }
            }
        }

        $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
        $result = $editHelper->saveProduct($asinData, $userId, $params);
        // print_r($targetListingIds);die;
        if (!empty($targetListingIds)) {
            $amazonCollection->updateMany(
                ['_id' => ['$in' => $targetListingIds]], 
                ['$unset' => [
                    'source_product_id' => 1,
                    'source_variant_id' => 1,
                    'source_container_id' => 1,
                    'matched' => 1,
                    'manual_mapped' => 1,
                    'matchedProduct' => 1,
                    'matchedwith' => 1,
                    'closeMatchedProduct' => 1,
                    'matched_updated_at' => 1,
                    'matched_at' => 1
                ]]
            );
        }
    }

    public function saveDefaultCategory($foundProducts): void
    {
        $categorySettings = [
            'primary_category' => 'default',
            'sub_category' => 'default',
            'browser_node_id' => '0',
            'barcode_exemption' => false,
            'attributes_mapping' =>
            [
                'required_attribute' =>
                [
                    [
                        'amazon_attribute' => 'condition-type',
                        'shopify_attribute' => 'recommendation',
                        'recommendation' => 'New',
                        'custom_text' => '',
                    ],
                ],
                'optional_attribute' => [],

            ],

        ];
        foreach ($foundProducts as $product) {
            $sourceProductId = $product['source_product_id'];
            $containerId = $product['container_id'];

            if (isset($product['group_id'])) {
                $groupId = $product['group_id'];
            } else {
                $groupId = false;
            }

            $amazonSellerIds = $product['amazon_seller_ids'];

            $this->di->getObjectManager()
                ->get(ProductHelper::class)
                ->updateProductCategory($this->_user_id, $containerId, $sourceProductId, $groupId, $amazonSellerIds, $categorySettings);
        }
    }

    public function validate($feedContent, $product, $attributes, $categoryParams, $amazonRecommendation = false)
    {
        $error = [];
        $sourceMarketplace = $this->di->getRequester()->getSourceName();
        $skuAttribute = 'sku';
        if (isset($attributes['sku'])) {
            $skuAttribute = 'sku';
        } elseif (isset($attributes['item_sku'])) {
            $skuAttribute = 'item_sku';
        }

        // validating sku
        if (!isset($feedContent[$skuAttribute]) || empty($feedContent[$skuAttribute])) {
            $error[] = 'AmazonError102';
        }

        //validating description length
        if (!empty($feedContent['product_description'])) {
            $des = strval($feedContent['product_description']);
            $count = strlen($des);
            if ($count >= 2000) {
                $error[] = 'AmazonError103';
            }
        }

        //validating SKU length
        if (!empty($feedContent['item_sku'])) {
            $sku = strval($feedContent['item_sku']);
            $count = strlen($sku);
            if ($count >= 40) {
                $error[] = 'AmazonError104';
            }
        }

        if ($product['type'] != 'variation') {
            // validating price
            $priceAttribute = 'price';
            if (isset($feedContent['price'])) {
                $priceAttribute = 'price';
            } elseif (isset($feedContent['standard_price'])) {
                $priceAttribute = 'standard_price';
            }

            if (!isset($feedContent[$priceAttribute])) {
                $error[] = 'AmazonError105';
            }

            if (isset($feedContent[$priceAttribute]) && ($feedContent[$priceAttribute] === 0 || $feedContent[$priceAttribute] == '0')) {
                $error[] = 'AmazonError106';
            }

            if (isset($feedContent[$priceAttribute]) && $feedContent[$priceAttribute] === false) {
                $error[] = 'AmazonError141';
            }

            //validating quantity
            if (!isset($feedContent['quantity'])) {
                $error[] = 'AmazonError108';
            }

            //            if (isset($feedContent['quantity']) && $feedContent['quantity'] === false) {
            //                $error[] = 'Quantity syncing is disabled, please enable it from assigned template.';
            //            }

            //validating barcode

            if (!$categoryParams['data']['barcode_exemption'] || $categoryParams['data']['category'] == 'default') {

                $barcodeAttribute = 'product-id';
                if (isset($attributes['product-id'])) {
                    $barcodeAttribute = 'product-id';
                } elseif (isset($attributes['external_product_id'])) {
                    $barcodeAttribute = 'external_product_id';
                }

                if (!isset($feedContent[$barcodeAttribute]) || empty($feedContent[$barcodeAttribute])) {
                    $error[] = 'AmazonError109';
                } else {

                    $barcode = $this->di->getObjectManager()->get(Barcode::class);
                    $type = $barcode->setBarcode($feedContent[$barcodeAttribute]);

                    if (!$type) {
                        $error[] = 'AmazonError110';
                    }
                }
            }
        }

        if ($amazonRecommendation && $product['type'] != 'variation') {
            foreach ($amazonRecommendation as $key => $recommendation) {
                # code...
                if (isset($feedContent[$key])) {
                    if (!in_array($feedContent[$key], $recommendation) && ($key != 'color_map' && $key != 'size_map' && $key != 'color_name' && $key != 'size_name')) {
                        $error[] = $key . ' contains an invalid value , kindly send the accepted values';
                    }
                }
            }
        }

        if (!empty($error)) {
            $feedContent['error'] = $error;
        }

        return $feedContent;
    }

    public function mapOption($amazonAttribute, $sourceAttribute, $value, $valueMappingAttributes = false, $sourceMarketplace = false)
    {
        $mappedValue = $value;
        #Check Value Mapping Used

        // if ($valueMappingAttributes && !empty($valueMappingAttributes) && isset($valueMappingAttributes[$amazonAttribute][$sourceAttribute])) {
        //     foreach ($valueMappingAttributes[$amazonAttribute][$sourceAttribute] as $mappedKeys => $mappedValues) {
        //         if (isset($valueMappingAttributes[$amazonAttribute][$sourceAttribute][$mappedKeys][$sourceMarketplace]) && $valueMappingAttributes[$amazonAttribute][$sourceAttribute][$mappedKeys][$sourceMarketplace] == $value) {
        //             $mappedValue = $valueMappingAttributes[$amazonAttribute][$sourceAttribute][$mappedKeys]['amazon'];
        //         }
        //     }
        // }
        if ($valueMappingAttributes && !empty($valueMappingAttributes) && isset($valueMappingAttributes[$amazonAttribute][$sourceAttribute])) {

            if (!empty($valueMappingAttributes[$amazonAttribute][$sourceAttribute][$value])) {
                $mappedValue = $valueMappingAttributes[$amazonAttribute][$sourceAttribute][$value];
            }
        }


        $mappedOption['60d5d70cfd78c76e34776e12']['apparel_size']['Size'] = [
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
            '4XL' => '4X-Large',
            '5XL' => '5X-Large',
        ];
        $mappedOption['618865393bee1d48e665f7b7']['apparel_size']['Available Sizes'] = [
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',

        ];

        $mappedOption['61182f2ad4fc9a5310349827']['shirt_size']['Size'] = [
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
            '4XL' => '4X-Large',
            '5XL' => '5X-Large',
        ];

        $mappedOption['61157a91ec6ed62aec72b09a']['shirt_size']['Size'] = [
            'XS' => 'X-Small',
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
            '4XL' => '4X-Large',
            '5XL' => '5X-Large',
        ];

        $mappedOption['6141a0cf1c20da30c948ad7e']['apparel_size']['Size'] = [
            '(US 4-6)S' => 'S',
            '(US 8-10)M' => 'M',
            '(US 12-14)L' => 'L',
            '(US 16-18)XL' => 'X-Large',
            '(US 18-20)2XL' => 'XX-Large',
        ];

        $mappedOption['6116a3f33715e60b0013662f']['apparel_size']['Jacket-Coat Length'] = [
            'Short' => 'S',
            'Regular' => 'M',
            'Long' => 'L',
        ];

        $mappedOption['61434c8ac7908f17bc2bbf5b']['shirt_size']['Size'] = [
            'Small' => 'S',
            'Medium' => 'M',
            'Large' => 'L',
        ];

        $mappedOption['6153137d91f1c10efe3b8825']['shirt_size']['Size'] = [
            'small' => 'S',
            'medium' => 'M',
            'large' => 'L',
        ];

        $mappedOption['611cbdc45a71ee43e7008871']['footwear_size']['Size'] = [
            '45' => '11',
            '44' => '10',
            '43' => '9',
            '42' => '8',
            '41' => '7',
            '40' => '6',
        ];
        $mappedOption['6156554d67ab6201c97da4ad']['shirt_size']['Size'] = [
            'XS' => 'X-Small',
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
            '4XL' => '4X-Large',
            '5XL' => '5X-Large',
        ];

        $mappedOption['611cbdc45a71ee43e7008871']['bottoms_size']['Size'] = [
            'Xs' => 'X-Small',
            'Xl' => 'X-Large',
            'Xxl' => 'XX-Large',
            '3XL' => '3X-Large',
            '4XL' => '4X-Large',
            '5XL' => '5X-Large',
        ];

        $mappedOption['617720ab09002742092c98ed']['apparel_size']['Size'] = [
            'P' => 'X-Small',
            'S' => 'Small',
            'M' => 'Medium',
            'L' => 'Large',
            'XL' => 'X-Large',
            'XXL' => 'XX-Large',
            'XXXL' => '3X-Large',
            'Other' => 'One Size',
        ];

        $mappedOption['6169e5cab70bf4019f05ce71']['shirt_size']['Size'] = [
            'XS' => 'X-Small',
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
            '4XL' => '4X-Large',
            '5XL' => '5X-Large',
            '6XL' => '6X-Large',
        ];
        $mappedOption['612a9208ae19473e291d6a3b']['apparel_size']['Size'] = [

            'xl' => 'X-Large',
            'xxl' => 'XX-Large',
            'xxs' => 'XX-Small',
            'xs' => 'X-Small',

        ];
        $mappedOption['616f302ad6a6d84c05077285']['apparel_size']['Size'] = [

            'xl' => 'X-Large',
            'xxl' => 'XX-Large',
            'xxs' => 'XX-Small',
            'xs' => 'X-Small',
            'XS' => 'X-Small',
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
            '4XL' => '4X-Large',
            '5XL' => '5X-Large',
            '6XL' => '6X-Large',

        ];
        $mappedOption['618865393bee1d48e665f7b7']['apparel_size']['Size'] = [

            'S / 38' => 'Small',
            'M / 40' => 'Medium',
            'L / 42' => 'Large',
            'XL / 44' => 'X-Large',

        ];
        $mappedOption['616f302ad6a6d84c05077285']['apparel_size']['Size'] = [
            'small' => 'Small',
            'medium' => 'Medium',
            'large' => 'Large',
            'XL' => 'X-Large',
            'XXL' => 'XX-Large',
        ];

        $mappedOption['612a9208ae19473e291d6a3b']['shirt_size']['Size'] = [
            'xl' => 'X-Large',
            'xxl' => 'XX-Large',
            'xxxl' => '3X-Large',
            '4xl' => '4X-Large',
            '5xl' => '5X-Large',
        ];

        $mappedOption['61a80e6de0d69f5db261a158']['apparel_size']['Size'] = [

            'Age 3-4' => '3',
            'Age 4-5' => '4',
            '2- 3 Years' => '2',
            'Age 6-7' => '6',
            'Age 7-8' => '7',
            'Age 5-6' => '5',
        ];

        $mappedOption['61a80e6de0d69f5db261a158']['size_name']['Size'] = [

            'Age 3-4' => '3',
            'Age 4-5' => '4',
            '2- 3 Years' => '2',
            'Age 6-7' => '6',
            'Age 7-8' => '7',
            'Age 5-6' => '5',

        ];

        $mappedOption['61a80e6de0d69f5db261a158']['apparel_size_to']['Size'] = [
            'Age 3-4' => '4',
            'Age 4-5' => '5',
            '2- 3 Years' => '3',
            'Age 6-7' => '7',
            'Age 7-8' => '8',
            'Age 5-6' => '6',
        ];

        $mappedOption['619f4d1b1f99d0507a092146']['size_name']['Taglia'] = [

            '36M' => '3 anni',
            '4A' => '4 anni',
            '5A' => '5 anni',
            '6A' => '6 anni',

        ];

        $mappedOption['61c9288bfd4ab3717d55e134']['apparel_size']['Size'] = [
            'L' => 'Large',
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
            '4XL' => '4X-Large',
            '5XL' => '5X-Large',
            '6XL' => '6X-Large',
        ];

        $mappedOption['61705a22a4ece7266e124fe8']['shirt_size']['Size'] = [
            'L' => 'Large',
            'XL' => 'X-Large',
            'XXL' => 'XX-Large',
        ];

        $mappedOption['61d4af2626243b1835654668']['shirt_size']['Size'] = [
            'S' => 'Small',
            'M' => 'Medium',
            'L' => 'Large',
            'L' => 'Large',
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
        ];
        $mappedOption['61e05dd7b285c63da56fc519']['shirt_size']['Size'] = [
            'S' => 'Small',
            'M' => 'Medium',
            'L' => 'Large',
            'Extra Large' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
            '4XL' => '4X-Large',
        ];
        $mappedOption['61e9deafdb8cce7814407159']['shirt_size']['Size'] = [
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
        ];

        $mappedOption['61e6eb36a8e2b8020a1ed494']['shirt_size']['Size'] = [

            'S' => 'Small',
            'M' => 'Medium',
            'L' => 'Large',
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',

        ];

        $mappedOption['61e05dd7b285c63da56fc519']['color']['Variant_title'] = [

            'Black/Right' => 'Black',
            'Black/Left' => 'Black',

        ];

        $mappedOption['61d4af2626243b1835654668']['apparel_size']['Size'] = [
            'L' => 'Large',
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
            '4XL' => '4X-Large',
            '5XL' => '5X-Large',
            '6XL' => '6X-Large',
        ];

        $mappedOption['61898d347984797ef02a40d6']['shirt_size']['Size'] = [
            'S - Shirt Included ADD $12' => 'Small',
            'M - Shirt Included ADD $12' => 'Medium',
            'L - Shirt Included ADD $12' => 'Large',
            'XL - Shirt Included ADD $12' => 'X-Large',
            '2XL - Shirt Included ADD $12' => 'XX-Large',
            '3XL - Shirt Included ADD $12' => '3X-Large',
            '4XL - Shirt Included ADD $14' => '4X-Large',
            '5XL - Shirt Included ADD $15' => '5X-Large',
        ];

        $mappedOption['61ae8309461fcc7fdf663f62']['shirt_size']['Size'] = [
            'S' => 'Small',
            'M' => 'Medium',
            'L' => 'Large',
            'XL' => 'X-Large',
            '2XL' => 'XX-Large',
            '3XL' => '3X-Large',
            '4XL' => '4X-Large',
            '5XL' => '5X-Large',
            '6XL' => '6X-Large',
        ];

        $mappedOption['61f10aba6081ee4cdf602797']['footwear_size']['Size'] = [
            '5 US F' => '5',
            '6 US F' => '6',
            '7 US F' => '7',
            '8 US F' => '8',
            '9 US F' => '9',
            '10 US F' => '10',
            '11 US F' => '11',
            '6 US M' => '6',
            '7 US M' => '7',
            '8 US M' => '8',
            '9 US M' => '9',
            '10 US M' => '10',
            '11 US M' => '11',
            '12 US M' => '12',
            '13 US M' => '13',
        ];
        $mappedOption['63bee36521f6839a940f0882']['apparel_size']['Size'] = [
            'XLARGE' => 'X-Large',
            '2XLARGE' => 'XX-Large',
            '3XLARGE' => '3X-Large',
        ];

        $mappedOption['613bca66a4d23a0dca3fbd9d']['shirt_size']['Size'] = [
            "XL" => "X-Large",
            "S" => "Small",
            "M" => "Medium",
            "L" => "Large",
            "2XL" => "XX-Large",
            "3XL" => "3X-Large",
            "4XL" => "4X-Large",
            "5XL" => "5X-Large",
            "12M" => "12 Months",
            "18M" => "18 Months",
            "24M" => "24 Months",
            "6M" => "6 Months",
            "5/6" => "6"
        ];

        $mappedOption['64432eef896b47413c0a1b52']['color_name']['variant_title'] = [

            'POSTER / 40x60 / Weiss' => 'POSTER / Weiss',
            'POSTER / 40x60 / Schwarz' => 'POSTER / Schwarz',
            'POSTER / 40x60 / Ohne Rahmen	' => 'POSTER / Ohne Rahmen',
            'POSTER / 30x45 / Schwarz	' => '6POSTER / Schwarz',
            'POSTER / 30x45 / Ohne Rahmen	' => 'POSTER / Ohne Rahmen',
            'POSTER / 30x45 / Weiss	' => 'POSTER / Weiss',
            'POSTER / 60x90 / Ohne Rahmen	' => 'POSTER / Ohne Rahmen',
            'POSTER / 100x150 / Schwarz	' => 'POSTER / Schwarz',
            'POSTER / 60x90 / Weiss	' => 'POSTER / Weiss',
            'POSTER / 70x105 / Ohne Rahmen	' => 'POSTER / Ohne Rahmen',
            'LEINWAND / 60x90 / Schwarz	' => 'LEINWAND / Schwarz',
            'POSTER / 80x120 / Ohne Rahmen	' => 'POSTER / Ohne Rahmen',
            'LEINWAND / 30x45 / Ohne Rahmen	' => 'LEINWAND / Ohne Rahmen',
            'POSTER / 70x105 / Schwarz	' => 'POSTER / Schwarz',
            'POSTER / 60x90 / Schwarz	' => 'POSTER / Schwarz',
            'POSTER / 80x120 / Weiss	' => 'POSTER / Weiss',
            'LEINWAND / 60x90 / Ohne Rahmen	' => 'LEINWAND / Ohne Rahmen',
            'POSTER / 70x105 / Weiss	' => 'POSTER / Weiss',
            'LEINWAND / 40x60 / Schwarz	' => 'LEINWAND / Schwarz',
            'POSTER / 100x150 / Weiss	' => 'POSTER / Weiss',
            'POSTER / 80x120 / Schwarz	' => 'POSTER / Schwarz',
            'LEINWAND / 40x60 / Ohne Rahmen	' => 'LEINWAND / Ohne Rahmen',
            'POSTER / 100x150 / Ohne Rahmen	' => 'POSTER / Ohne Rahmen',
            'LEINWAND / 30x45 / Schwarz	' => 'LEINWAND / Schwarz',
            'LEINWAND / 30x45 / Weiss	' => 'LEINWAND / Weiss',
            'LEINWAND / 40x60 / Weiss	' => 'LEINWAND / Weiss',
            'LEINWAND / 60x90 / Weiss	' => 'LEINWAND / Weiss',
            'LEINWAND / 70x105 / Ohne Rahmen	' => 'LEINWAND / Ohne Rahmen',
            'LEINWAND / 70x105 / Weiss	' => 'LEINWAND / Weiss',
            'LEINWAND / 70x105 / Schwarz	' => 'LEINWAND / Schwarz',
            'LEINWAND / 100x150 / Ohne Rahmen	' => 'LEINWAND / Ohne Rahmen',
            'LEINWAND / 80x120 / Ohne Rahmen	' => 'LEINWAND / Ohne Rahmen',
            'LEINWAND / 80x120 / Schwarz	' => 'LEINWAND / Schwarz',
            'ACRYLGLAS / 60x90 / Schwarz	' => 'ACRYLGLAS / Schwarz',
            'LEINWAND / 100x150 / Schwarz	' => 'LEINWAND / Schwarz',
            'ACRYLGLAS / 40x60 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
            'LEINWAND / 80x120 / Weiss	' => 'LEINWAND / Weiss',
            'LEINWAND / 100x150 / Weiss	' => 'LEINWAND / Weiss',
            'ACRYLGLAS / 40x60 / Weiss	' => 'ACRYLGLAS / Weiss',
            'ACRYLGLAS / 30x45 / Schwarz	' => 'ACRYLGLAS / Schwarz',
            'ACRYLGLAS / 30x45 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
            'ACRYLGLAS / 60x90 / Weiss	' => 'ACRYLGLAS / Weiss',
            'ACRYLGLAS / 30x45 / Weiss	' => 'ACRYLGLAS / Weiss',
            'ACRYLGLAS / 70x105 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
            'ACRYLGLAS / 60x90 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
            'ACRYLGLAS / 40x60 / Schwarz	' => 'ACRYLGLAS / Schwarz',
            'ACRYLGLAS / 70x105 / Schwarz	' => 'ACRYLGLAS / Schwarz',
            'ACRYLGLAS / 70x105 / Weiss	' => 'ACRYLGLAS / Weiss',
            'ACRYLGLAS / 80x120 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
            'ACRYLGLAS / 80x120 / Weiss	' => 'ACRYLGLAS / Weiss',
            'ACRYLGLAS / 80x120 / Schwarz	' => 'ACRYLGLAS / Schwarz',
            'ACRYLGLAS / 100x150 / Ohne Rahmen	' => 'ACRYLGLAS / Ohne Rahmen',
            'ACRYLGLAS / 100x150 / Schwarz	' => 'ACRYLGLAS / Schwarz',
            'ACRYLGLAS / 100x150 / Weiss	' => 'ACRYLGLAS / Weiss'

        ];

        $mappedOption['62b0ad72338bfa2779686544']['item_display_length']['Length'] = [
            '16 inch' => '16',
            '18 inch' => '18',
            '20 inch' => '20',
        ];


        $mappedOption['640d7167ce4c562aac0e41ab']['shirt_size']['Size'] = [
            'One Size I Length: 19" Chest: 34"' => '34',
            'One Size I Length: 22" Chest: 34"' => '34'
        ];

        $mappedOption['640d7167ce4c562aac0e41ab']['skirt_size']['Size'] = [
            'One Size I Length: 15" Waist: 26-34" Hip: 34-42"' => '34'
        ];

        $mappedOption['618aa726e607ac4d487e86cc']['footwear_size']['Size'] = [
            '36 EU' => '4',
            '37 EU' => '4.5',
            '38 EU' => '5.5',
            '39 EU' => '6',
            '40 EU' => '7',
        ];

        $mappedOption['64569d37913d9ea23502d976']['shapewear_cup_size']['Cup Size'] = [
            'D/DD' => 'D',
            'G/GG' => 'G',
            'F/FF' => 'F',
            'H/HH' => 'H',
            'J/JJ' => 'J',
            'L/LL' => 'L',
            'K/KK' => 'K',
        ];


        $mappedOption['64569d37913d9ea23502d976']['cup_size']['Cup Size'] = [
            'D/DD' => 'D',
            'G/GG' => 'G',
            'F/FF' => 'F',
            'H/HH' => 'H',
            'J/JJ' => 'J',
            'L/LL' => 'L',
            'K/KK' => 'K',
        ];

        $mappedOption['62b0ad72338bfa2779686544']['item_display_length']['Length'] = [
            '22 inch' => '22',
            '24 inch' => '24',
            '30 inch' => '30',
            '16 inch' => '16',
            '18 inch' => '18',
            '20 inch' => '20',
        ];

        $mappedOption['6466559be3eafaeb7b0a44b4']['shirt_size']['Size'] = [
            '6-9M' => '9',
            '9-12M' => '12',
            '12-18M' => '18',
            '2-3Y' => '3',
            '4 Years' => '4',
            '18-24M' => '24'
        ];

        $mappedOption['62b0ad72338bfa2779686544']['item_display_length']['Pendant size'] = [
            '5mm' => '5',
            '6mm' => '6',
            '7mm' => '7',
            '8mm' => '8',
            '9mm' => '9'
        ];

        $mappedOption['64e37ba7eaede4f4d509f952']['item_length']['CHAIN LENGTH'] = [
            '20 INCHES' => '20',
            '22 INCHES' => '22',
            '24 INCHES' => '24',
            '26 INCHES' => '26',
            '28 INCHES' => '28',
            '30 INCHES' => '30'
        ];

        // $mappedOption['61263349f75b161bc92813e0']['apparel_size']['Size'][$value] = str_replace('US', '', $value);

        if (isset($mappedOption[$this->_user_id][$amazonAttribute][$sourceAttribute][$value])) {
            $mappedValue = $mappedOption[$this->_user_id][$amazonAttribute][$sourceAttribute][$value];
        }

        return $mappedValue;
    }

    /**
     * process amazon-look sqs data
     *
     * @param array $data
     * @return bool|array
     */
    // public function search($data)
    // {
    //     try {
    //         $response = [];
    //         $this->init($data);
    //         $objectManager = $this->di->getObjectManager();
    //         $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
    //         $sourceShopId = $data['data']['source_shop_id'];
    //         $targetShopId = $data['data']['target_shop_id'];
    //         $sourceMarketplace = $data['data']['source_marketplace'];
    //         $targetMarketplace = $data['data']['target_marketplace'];
    //         $userId = $data['user_id'] ?? $this->di->getUser()->id;
    //         $logFile = 'amazon/' . $userId . '/' . $sourceShopId . '/' . $targetShopId . '/lookup//' . date('Y-m-d') . '.log';
    //         if (isset($data['data']['source_product_ids']) && !empty($data['data']['source_product_ids'])) {
    //             $sourceProductIds = $data['data']['source_product_ids'];
    //             $products = [];
    //             $query = [
    //                 'user_id' => $userId,
    //                 'shop_id' => $sourceShopId,
    //                 'source_product_id' => ['$in' => $sourceProductIds],
    //                 'type' => 'simple'
    //             ];
    //             $options = [
    //                 'typeMap' => ['root' => 'array', 'document' => 'array'],
    //                 'projection' => ['source_product_id' => 1, 'container_id' => 1, 'barcode' => 1, 'marketplace' => 1],
    //             ];
    //             $products = $helper->getproductbyQuery($query, $options);
    //             $parentProductIds = array_diff($sourceProductIds, array_column($products, 'source_product_id'));
    //             if (!empty($parentProductIds)) {
    //                 $query = [
    //                     'user_id' => $userId,
    //                     'shop_id' => $sourceShopId,
    //                     'container_id' => ['$in' => array_values($parentProductIds)],
    //                     'type' => 'simple'
    //                 ];
    //                 $newProducts = $helper->getproductbyQuery($query, $options);
    //                 if (!empty($newProducts)) {
    //                     $products = array_merge($products, $newProducts);
    //                 }
    //             }
    //             if (!empty($products)) {
    //                 $response = $this->searchProductOnAmazon($targetShopId, $products);
    //                 // $this->di->getLog()->logContent(print_r($response, true), 'info', $logFile);
    //             }
    //             (int) $counter = $data['data']['counter'] ?? 0;
    //             if (isset($response['failed_source_product']) && $response['failed_source_product']['count'] > 0 && $counter <= 2) {
    //                 //prepare sqs data if not
    //                 if (!isset($data['type'], $data['class_name'])) {
    //                     $appCode = $this->di->getAppCode()->get();
    //                     $appTag = $this->di->getAppCode()->getAppTag();
    //                     $data = [
    //                         'type' => 'full_class',
    //                         'class_name' => '\App\Amazon\Components\Product\Product',
    //                         'method' => 'search',
    //                         'appCode' => $appCode,
    //                         'appTag' => $appTag,
    //                         'queue_name' => $this->di->getObjectManager()->get('\App\Amazon\Components\Cron\Helper')->getSearchOnAmazonQueueName(),
    //                         'user_id' => $userId,
    //                         'data' => [
    //                             'source_shop_id' => $sourceShopId,
    //                             'target_shop_id' => $targetShopId,
    //                             'source_marketplace' => $sourceMarketplace,
    //                             'target_marketplace' => $targetMarketplace,
    //                         ]
    //                     ];
    //                 }
    //                 $data['data']['source_product_ids'] = $res['failed_source_product']['message'] ?? [];
    //                 $data['data']['counter'] = $counter + 1;
    //                 $this->di->getMessageManager()->pushMessage($data);
    //             }
    //             return true;
    //         } else {
    //             if (!isset($data['data']['limit'])) {
    //                 $data['data']['limit'] = 500;
    //             }
    //             if (!isset($data['data']['page'])) {
    //                 $data['data']['page'] = 0;
    //             }
    //             $limit = $data['data']['limit'];
    //             $skip = $data['data']['page'] * $limit;
    //             $isLastPage = false;
    //             $products = [];
    //             $productsData = [];
    //             if (!isset($data['data']['isLastPage']) || !$data['data']['isLastPage']) {
    //                 $query = [
    //                     'user_id' => (string) $userId,
    //                     'type' => ProductContainer::PRODUCT_TYPE_SIMPLE,
    //                     'shop_id' => $data['data']['source_shop_id'],
    //                     '$or' => [
    //                         ["marketplace.target_marketplace" => ['$ne' => $targetMarketplace]],
    //                         [
    //                             "marketplace.status" => [
    //                                 '$nin' => [
    //                                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_ACTIVE,
    //                                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INACTIVE,
    //                                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INCOMPLETE,
    //                                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UPLOADED,
    //                                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_PARENT
    //                                 ]
    //                             ],
    //                             "marketplace.target_marketplace" => $targetMarketplace
    //                         ],
    //                         ["marketplace.target_marketplace" => $targetMarketplace, "marketplace.shop_id" => ['$ne' => (string) $targetShopId]],
    //                     ],
    //                 ];
    //                 $options = [
    //                     'typeMap' => ['root' => 'array', 'document' => 'array'],
    //                     'projection' => ['_id' => 1, 'user_id' => 1, 'source_product_id' => 1, 'shop_id' => 1, 'container_id' => 1, 'barcode' => 1, 'marketplace' => 1],
    //                     'skip' => $skip,
    //                     'limit' => $limit,
    //                 ];
    //                 $products = $helper->getproductbyQuery($query, $options);
    //             }
    //             $productCount = count($productsData);
    //             if (isset($data['failed_source_product']) && !empty($data['failed_source_product'])) {
    //                 $query = [
    //                     'user_id' => (string) $userId,
    //                     'shop_id' => $sourceShopId,
    //                     'type' => 'simple',
    //                     'source_product_id' => ['$in' => $data['failed_source_product']]
    //                 ];
    //                 $options = [
    //                     'typeMap' => ['root' => 'array', 'document' => 'array'],
    //                     'projection' => ['_id' => 1, 'user_id' => 1, 'source_product_id' => 1, 'shop_id' => 1, 'container_id' => 1, 'barcode' => 1, 'marketplace' => 1]
    //                 ];
    //                 $failed_products = $helper->getproductbyQuery($query, $options);
    //                 $products = array_merge($products, $failed_products);
    //             }
    //             $sqs_data = $data;
    //             if ($productCount < $limit) {
    //                 $isLastPage = true;
    //                 $sqs_data['data']['isLastPage'] = true;
    //             } else {
    //                 $sqs_data['data']['page'] = $sqs_data['data']['page'] + 1;
    //             }
    //             $res = [];
    //             if (!empty($products)) {
    //                 $res = $this->searchProductOnAmazon($targetShopId, $products);
    //                 // $this->di->getLog()->logContent(print_r($res, true), 'info', $logFile);
    //                 if (isset($res['remote_servre_eror']) && !empty($res['remote_servre_eror'])) {
    //                     $message = 'Amazon look-up could not be completed. Please contact support';
    //                     $notificationData = [
    //                         'message' => $message,
    //                         'severity' => 'false',
    //                         "user_id" => $userId,
    //                         "marketplace" => $targetMarketplace,
    //                         "appTag" => $this->di->getAppCode()->getAppTag()
    //                     ];
    //                     $objectManager->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($data['queued_task_id'], 100, $message);
    //                     $objectManager->get('\App\Connector\Models\Notifications')->addNotification($targetShopId, $notificationData);
    //                     return true;
    //                 }
    //             }
    //             (int) $counter = $sqs_data['data']['counter'] ?? 0;
    //             if ($isLastPage) {
    //                 if (isset($res['failed_source_product']) && $res['failed_source_product']['count'] > 0 && $counter <= 2) {
    //                     $sqs_data['failed_source_product'] = $res['failed_source_product']['message'] ?? [];
    //                     $sqs_data['data']['counter'] = $counter + 1;
    //                     $this->di->getMessageManager()->pushMessage($sqs_data);
    //                     return true;
    //                 } else {
    //                     $message = 'Amazon Lookup completed successfully';
    //                     $notificationData = [
    //                         'message' => $message,
    //                         'severity' => 'success',
    //                         "user_id" => $userId,
    //                         "marketplace" => $targetMarketplace,
    //                         "appTag" => $this->di->getAppCode()->getAppTag()
    //                     ];
    //                     $objectManager->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($data['queued_task_id'], 100, $message);
    //                     $objectManager->get('\App\Connector\Models\Notifications')->addNotification($targetShopId, $notificationData);
    //                     return true;
    //                 }
    //             } else {
    //                 $processed = round(($limit * $sqs_data['data']['page']) / $sqs_data['individual_weight'] * 100, 2);
    //                 $objectManager->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($data['queued_task_id'], $processed, '', false);
    //                 $sqs_data['failed_source_product'] = $res['failed_source_product']['message'] ?? [];
    //                 $this->di->getMessageManager()->pushMessage($sqs_data);
    //                 return true;
    //             }
    //         }
    //         return true;
    //     } catch (\Exception $e) {
    //         $this->di->getLog()->logContent('look-up exception search(): ' . print_r($e->getMessage(), true), 'info', 'exception.log');
    //         return false;
    //     }
    // }

    /**
     * send product-get request to amazon
     *
     * @param string $targetShopId
     * @param array $products
     * @return bool|array
     */
    // public function searchProductOnAmazon($targetShopId, $products)
    // {
    //     try {
    //         if (!empty($products)) {
    //             $returnArray = $successArray = $errorArray = $failed_source_product = $containerIds = [];
    //             $barcodeValidatedProducts = $sourceProductIds = [];
    //             $objectManager = $this->di->getObjectManager();
    //             $userId = $this->di->getUser()->id;
    //             $amazonShop = $objectManager->get('\App\Core\Models\User\Details')->getShop($targetShopId, $userId);
    //             // $sellerId = $amazonShop['warehouses'][0]['seller_id'];
    //             $sourceShopId = $this->source_shop_id;
    //             $barcode = $objectManager->create('App\Amazon\Components\Common\Barcode');
    //             $marketplacehelper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
    //             $offerStatus = \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER;
    //             $statusArray = [
    //                 \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_ACTIVE,
    //                 \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INACTIVE,
    //                 \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INCOMPLETE,
    //                 \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UPLOADED,
    //                 \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_PARENT
    //             ];
    //             foreach ($products as $product) {
    //                 $sourceProductId = $product['source_product_id'];
    //                 $marketplace = $this->getMarketplace($product, $targetShopId, $sourceProductId);
    //                 if (isset($marketplace['status']) && in_array($marketplace['status'], $statusArray)) {
    //                     $returnArray['not_processed'][$sourceProductId] = 'Product already listed.';
    //                 } else {
    //                     if (isset($marketplace['status']) && $marketplace['status'] == $offerStatus) {
    //                         $containerIds[$sourceProductId] = ['container_id' => $product['container_id'], 'asin' => $marketplace['asin'] ?? ''];
    //                         array_push($sourceProductIds, $sourceProductId);
    //                     }
    //                     $validBarcode = false;
    //                     if (isset($marketplace['barcode']) && !empty($marketplace['barcode'])) {
    //                         $validBarcode = trim($marketplace['barcode']);
    //                     } elseif (isset($product['barcode']) && !empty($product['barcode'])) {
    //                         $validBarcode = trim($product['barcode']);
    //                     }
    //                     if ($validBarcode && $barcode->setBarcode($validBarcode)) {
    //                         if (preg_match('/(\x{200e}|\x{200f})/u', $validBarcode)) {
    //                             $validBarcode = preg_replace('/(\x{200e}|\x{200f})/u', '', $validBarcode);
    //                         }
    //                         $barcodeValidatedProducts[$validBarcode][] = ['source_product_id' => $sourceProductId, 'container_id' => $product['container_id']];
    //                     } else {
    //                         $returnArray['not_processed'][$sourceProductId] = 'Barcode validation failed';
    //                     }
    //                 }
    //             }
    //             if (!empty($barcodeValidatedProducts)) {
    //                 $productChunks = array_chunk($barcodeValidatedProducts, 100, true);
    //                 $commonHelper = $objectManager->get(Helper::class);
    //                 $bulkupdateData = [];
    //                 $bulkupdateasinData = [];
    //                 foreach ($productChunks as $barcodeArr) {
    //                     $specifics = [
    //                         'id' => array_keys($barcodeArr),
    //                         'shop_id' => $amazonShop['remote_shop_id'],
    //                         'home_shop_id' => $targetShopId
    //                     ];
    //                     $response = $commonHelper->sendRequestToAmazon('product', $specifics, 'GET');
    //                     if (isset($response['success']) && $response['success'] && isset($response['response']) && is_array($response['response'])) {
    //                         foreach ($response['response'] as $barcode => $data) {
    //                             $productsData = $barcodeArr[$barcode] ?? false;
    //                             if ($productsData && isset($data['asin'])) {
    //                                 $asin = $data['asin'];
    //                                 foreach ($productsData as $productData) {
    //                                     //check if offer status already applied
    //                                     if (
    //                                         isset($containerIds[$productData['source_product_id']]) &&
    //                                         $containerIds[$productData['source_product_id']]['asin'] == $asin
    //                                     ) {
    //                                         $successArray[] = $productData['source_product_id'];
    //                                         continue;
    //                                     }
    //                                     // update status on product container
    //                                     $updateData = [
    //                                         "source_product_id" => $productData['source_product_id'],
    //                                         // required
    //                                         'user_id' => $userId,
    //                                         'source_shop_id' => $sourceShopId,
    //                                         // required
    //                                         'container_id' => $productData['container_id'],
    //                                         // required
    //                                         'childInfo' => [
    //                                             'source_product_id' => $productData['source_product_id'],
    //                                             // required
    //                                             'shop_id' => (string) $targetShopId,
    //                                             // required
    //                                             'asin' => $asin,
    //                                             'status' => $offerStatus,
    //                                             'target_marketplace' => 'amazon',
    //                                             // required
    //                                         ],
    //                                     ];
    //                                     array_push($bulkupdateData, $updateData);
    //                                     // update asin on product container
    //                                     $asinData = [
    //                                         'target_marketplace' => 'amazon',
    //                                         'shop_id' => (string) $targetShopId,
    //                                         'container_id' => $productData['container_id'],
    //                                         'source_product_id' => $productData['source_product_id'],
    //                                         'asin' => $asin,
    //                                         'status' => $offerStatus,
    //                                         'source_shop_id' => $sourceShopId
    //                                     ];
    //                                     array_push($bulkupdateasinData, $asinData);
    //                                     $successArray[] = $productData['source_product_id'];
    //                                 }
    //                             }
    //                         }
    //                     } else {
    //                         if (isset($response['response']) && !empty($response['response'])) {
    //                             $returnArray['remote_servre_eror'] = $response['response'];
    //                         } else {
    //                             $returnArray['remote_servre_eror'] = $response;
    //                         }
    //                         return $returnArray;
    //                     }
    //                     if (isset($response['failed_asin']) && is_iterable($response['failed_asin'])) {
    //                         foreach ($response['failed_asin'] as $failedBarcode) {
    //                             if (isset($barcodeArr[$failedBarcode])) {
    //                                 $failed_source_product[] = $product['source_product_id'];
    //                             }
    //                         }
    //                     }
    //                 }
    //                 if (!empty($bulkupdateData)) {
    //                     $marketplacehelper->marketplaceSaveAndUpdate($bulkupdateData);
    //                 }
    //                 if (!empty($bulkupdateasinData)) {
    //                     $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
    //                     $helper->saveProduct($bulkupdateasinData);
    //                 }
    //                 $notProcessedIds = array_diff($sourceProductIds, $successArray);
    //                 if (!empty($notProcessedIds)) {
    //                     foreach ($notProcessedIds as $id) {
    //                         $errorArray[$id] = 'Not found on amazon';
    //                     }
    //                 }
    //             }
    //             $bulkupdateData = [];
    //             $bulkupdateasinData = [];
    //             if (!empty($containerIds) && !empty($errorArray)) {
    //                 foreach ($errorArray as $sourceProductId => $error) {
    //                     if (isset($containerIds[$sourceProductId])) {
    //                         $asinData = [
    //                             'target_marketplace' => 'amazon',
    //                             'shop_id' => (string) $targetShopId,
    //                             'container_id' => $containerIds[$sourceProductId]['container_id'],
    //                             'source_product_id' => (string) $sourceProductId,
    //                             'source_shop_id' => $sourceShopId,
    //                             'unset' => [
    //                                 'asin' => 1,
    //                                 'status' => 1
    //                             ]
    //                         ];
    //                         array_push($bulkupdateasinData, $asinData);
    //                         $errorArray[$sourceProductId] = 'Offer status removed';
    //                     }
    //                 }
    //                 if (!empty($bulkupdateasinData)) {
    //                     $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
    //                     $res = $helper->saveProduct($bulkupdateasinData);
    //                 }
    //                 $returnArray['error'] = $errorArray;
    //             }
    //             if (!empty($successArray)) {
    //                 $returnArray['offerstatus'] = $successArray;
    //             }
    //             if (!empty($failed_source_product)) {
    //                 $returnArray['failed_source_product'] = ['count' => count($failed_source_product), 'message' => $failed_source_product];
    //             }
    //             return $returnArray;
    //         } else {
    //             return ['success' => true, 'message' => 'No Products Found'];
    //         }
    //     } catch (\Exception $e) {
    //         $this->di->getLog()->logContent('look-up exception search(): ' . print_r($e->getMessage(), true), 'info', 'exception.log');
    //         return false;
    //     }
    // }

    /**
     * @param $sqsData
     */
    public function deleteAmazonListing($sqsData): void
    {
        $user_id = $sqsData['user_id'];

        $data = $sqsData['data'];
        if (!isset($data['limit'])) {
            $limit = 100;
        } else {
            $limit = $data['limit'];
        }

        $isLastPage = false;
        if ($data['page'] == $data['total_pages']) {
            $isLastPage = true;
        }

        $skip = 0;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $amazonListingCollection = $mongo->getCollection(Helper::AMAZON_LISTING);

        $query = ['user_id' => $user_id, 'updated_at' => ['$lt' => date('Y-m-d')]];

        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'projection' => ['_id' => 1, 'user_id' => 1, 'shop_id' => 1, 'source_product_id' => 1, 'source_container_id' => 1, 'barcode' => 1, 'marketplace' => 1],
            'skip' => $skip,
            'limit' => $limit,
        ];

        $listings = $amazonListingCollection->find($query, $options)->toArray();

        if (!empty($listings)) {
            foreach ($listings as $listing) {
                $updateData = [
                    "source_product_id" => $listing['source_product_id'],
                    // required
                    'user_id' => $listing['user_id'],
                    'childInfo' => [
                        'source_product_id' => $listing['source_product_id'],
                        // required
                        'shop_id' => $listing['shop_id'],
                        // required
                        'status' => \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED,
                        'target_marketplace' => 'amazon',
                        // required
                    ],
                ];
                $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                $response = $helper->marketplaceSaveAndUpdate($updateData);
                if (isset($response['success']) && $response['success']) {
                    $amazonListingCollection->deleteOne(
                        ['user_id' => $user_id, 'source_product_id' => $listing['source_product_id']]
                    );
                }
            }
        }

        if (!$isLastPage) {
            $sqsData['data']['page'] = $data['page'] + 1;
            $this->di->getMessageManager()->pushMessage($sqsData);
        } else {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $queuedTasks = $mongo->getCollectionForTable(Helper::QUEUED_TASKS);
            $queuedTasks->deleteOne(['_id' => new ObjectId($data['queued_task_id'])]);
            $message = 'Deleted extra products from Amazon listing';
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($this->_user_id, $message, 'success');
        }
    }

    /**
     * It is called from edit page.
     * @param $data
     * @return array
     */
    // public function searchOnAmazon($data)
    // {

    //     $amazonShopsData = [];

    //     $source_marketplace = $target_marketplace = '';
    //     $accounts = [];

    //     if (isset($data['source_marketplace'])) {
    //         $source_marketplace = $data['source_marketplace']['marketplace'];
    //     }
    //     if (isset($data['target_marketplace'])) {
    //         $target_marketplace = $data['target_marketplace']['marketplace'];
    //         $accounts = $data['target_marketplace']['shop_id'];
    //     }

    //     if ($target_marketplace == '' || $source_marketplace == '' || empty($accounts)) {
    //         return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
    //     }
    //     $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
    //     //        $connectedAccounts = $commonHelper->getAllAmazonShops($this->di->getUser()->id);
    //     $connectedAccounts = $commonHelper->getAllCurrentAmazonShops();
    //     foreach ($connectedAccounts as $account) {
    //         if (!in_array($account['_id'], $accounts)) {
    //             continue;
    //         }
    //         if ($account['warehouses'][0]['status'] == 'active') {
    //             $amazonShopsData[] = $account;
    //         }
    //     }

    //     if (!empty($amazonShopsData)) {

    //         $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
    //         $productContainerCollection = $mongo->getCollection(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

    //         $successArray = $errorArray = $alreadyUploaded = [];
    //         $returnArray = [];
    //         $sourceProductIds = [];
    //         if (isset($data['container_ids']) && !empty($data['container_ids'])) {
    //             if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
    //                 $query = [
    //                     'user_id' => (string) $this->di->getUser()->id,
    //                     'type' => ProductContainer::PRODUCT_TYPE_SIMPLE,
    //                     'container_id' => [
    //                         '$in' => $data['container_ids'],
    //                     ],
    //                     'source_product_id' => [
    //                         '$in' => $data['source_product_ids'],
    //                     ],
    //                     'source_marketplace' => $source_marketplace,
    //                 ];
    //             } else {
    //                 $query = [
    //                     'user_id' => (string) $this->di->getUser()->id,
    //                     'type' => ProductContainer::PRODUCT_TYPE_SIMPLE,
    //                     'container_id' => [
    //                         '$in' => $data['container_ids'],
    //                     ],
    //                     'source_marketplace' => $source_marketplace,

    //                 ];
    //             }

    //             $sourceProductIdsArray = $productContainerCollection->find(
    //                 $query,
    //                 [
    //                     'typeMap' => ['root' => 'array', 'document' => 'array'],
    //                     'projection' => ['source_product_id' => 1],
    //                 ]
    //             )->toArray();
    //             if (!empty($sourceProductIdsArray)) {
    //                 $sourceProductIds = array_column($sourceProductIdsArray, 'source_product_id');
    //             }
    //         }

    //         if ($sourceProductIds) {

    //             foreach ($amazonShopsData as $shop) {
    //                 $products = [];
    //                 foreach ($sourceProductIds as $sourceProductId) {
    //                     $product = [
    //                         "source_product_id" => $sourceProductId,
    //                         'user_id' => (string) $this->di->getUser()->id,
    //                         'shop_id' => $shop['_id'],
    //                     ];

    //                     $objectManager = $this->di->getObjectManager();
    //                     $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
    //                     $productData = $helper->getSingleProduct($product);

    //                     if (isset($productData['marketplace']) && !empty($productData['marketplace'])) {
    //                         $isStatusExist = $existOnAmazon = false;
    //                         foreach ($productData['marketplace'] as $marketplace) {
    //                             if (isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == 'amazon') {
    //                                 $existOnAmazon = true;
    //                                 $statusArray = [
    //                                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED,
    //                                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_ERROR,
    //                                     \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER,
    //                                 ];
    //                                 if (isset($marketplace['status']) && !in_array($marketplace['status'], $statusArray) && $marketplace['shop_id'] == $shop['_id']) {
    //                                     $isStatusExist = true;
    //                                 }
    //                             }
    //                         }
    //                         if (!$existOnAmazon || !$isStatusExist) {
    //                             $products[$productData['source_product_id']] = $productData;
    //                         } else {
    //                             $alreadyUploaded[] = $productData['source_product_id'];
    //                         }
    //                     } else {
    //                         $products[$productData['source_product_id']] = $productData;
    //                     }
    //                 }

    //                 if (!empty($products)) {
    //                     $response = $this->searchProductOnAmazon($shop, $products);

    //                     if (isset($response['success'])) {
    //                         $successArray = array_merge($successArray, $response['success']['message']);
    //                     }
    //                     if (isset($response['error'])) {
    //                         $errorArray = array_merge($errorArray, $response['error']['message']);
    //                     }
    //                 }
    //             }

    //             if (!empty($alreadyUploaded)) {
    //                 $returnArray['already_uploaded'] = ['count' => count($alreadyUploaded), 'data' => $alreadyUploaded];
    //             }
    //             if (!empty($successArray)) {
    //                 $returnArray['success'] = ['count' => count($successArray), 'data' => $successArray];
    //             }
    //             if (!empty($errorArray)) {
    //                 $returnArray['error'] = ['count' => count($errorArray), 'data' => $errorArray];
    //             }
    //             return $returnArray;
    //         } else {
    //             return ['success' => false, 'message' => 'No Source Product Id found'];
    //         }
    //     } else {
    //         return ['success' => false, 'message' => 'Please enable your Amazon account from Settings section to initiate the processes.'];
    //     }
    // }

    public function getMarketplace($productData, $targetShopId, $sourceProductId)
    {
        if (isset($productData['marketplace'])) {
            foreach ($productData['marketplace'] as $marketplace) {
                if ($marketplace['source_product_id'] == $sourceProductId && $marketplace['shop_id'] == $targetShopId && isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == 'amazon') {
                    return $marketplace;
                }
            }
        }

        $productCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollection(Helper::PRODUCT_CONTAINER);
        $query = [
            'user_id' => $this->di->getUser()->id,
            'shop_id' => $targetShopId,
            'container_id' => $productData['container_id'],
            'source_product_id' => $sourceProductId,
            'target_marketplace' => 'amazon'
        ];
        return $productCollection->findOne($query, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
    }

    //
    public function getCustomValueMapping($data)
    {
        $filePath = BP . DS . 'app' . DS . 'code' . DS . 'amazon' . DS . 'utility' . DS . 'mapping.json';
        $mappedJson = file_get_contents($filePath);
        $mappedOption = json_decode($mappedJson, true);
        if (isset($data['user_id']) && isset($data['target_id'])) {
            $mappedOption = $mappedOption[$data['user_id']][$data['target_id']];
        }

        return ['success' => true, 'data' => $mappedOption];
    }

    public function deleteCustomValueMapping($data)
    {
        $filePath = BP . DS . 'app' . DS . 'code' . DS . 'amazon' . DS . 'utility' . DS . 'mapping.json';
        $mappedJson = file_get_contents($filePath);
        $mappedOption = json_decode($mappedJson, true);

        if (isset($data['user_id']) && isset($data['target_id'])) {
            if (count($mappedOption[$data['user_id']]) > 1) {
                unset($mappedOption[$data['user_id']][$data['target_id']]);
            } else {
                unset($mappedOption[$data['user_id']]);
            }

            // print_r($mappedOption[$data['user_id']][$data['target_id']]);
            // die;
            $updatedData = json_encode($mappedOption);
            file_put_contents($filePath, $updatedData);
            // die;
        } else {
            return ['success' => false, 'message' => "user_id required"];
        }

        return ['success' => true, 'data' => "deleted successfully"];
    }

    public function saveCustomValueMapping($data)
    {
        $filePath = BP . DS . 'app' . DS . 'code' . DS . 'amazon' . DS . 'utility' . DS . 'mapping.json';
        $mappedJson = file_get_contents($filePath);
        $mappedOption = json_decode($mappedJson, true);

        if (isset($data['user_id']) && isset($data['target_id']) && isset($data['data'])) {
            if (!$this->idValid($data['user_id'])) {
                return ['success' => false, 'message' => "invalid user id"];
            }

            if (count($data['data']) > 0) {
                $mappedOption[$data['user_id']][$data['target_id']] = $data['data'];
                $updatedData = json_encode($mappedOption);
                file_put_contents($filePath, $updatedData);
            } else {
                return ['success' => false, 'message' => "data cannot be empty or null"];
            }
        } else {
            return ['success' => false, 'message' => "user_id, target_id and data are required fields"];
        }

        return ['success' => true, 'data' => "data saved successfully"];
    }

    public function checkIfUserAndTargetExists($data)
    {
        if (isset($data['user_id']) && isset($data['target_id'])) {
            $filePath = BP . DS . 'app' . DS . 'code' . DS . 'amazon' . DS . 'utility' . DS . 'mapping.json';
            $mappedJson = file_get_contents($filePath);
            $mappedOption = json_decode($mappedJson, true);
            if (isset($mappedOption[$data['user_id']]) && isset($mappedOption[$data['user_id']][$data['target_id']])) {
                return ['success' => false, 'message' => 'already Exists'];
            }
        }

        return ['success' => true, 'message' => 'not exists'];
    }

    public function idValid($id)
    {
        return $id instanceof \MongoDB\BSON\ObjectID
            || preg_match('/^[a-f\d]{24}$/i', (string) $id);
    }

    public function removeTagInactiveAccount($rawBody)
    {
        try {
            $userId = $rawBody['user_id'] ?? $this->di->getUser()->id;
            $response = $this->setDiForUser($userId);
            if (isset($response['success']) && $response['success']) {
                $shops = $this->di->getUser()->shops;
                $removeTag = false;
                $remoteShopId = $rawBody['data']['remote_shop_id'];

                foreach ($shops as $shop) {
                    if (
                        $shop['remote_shop_id'] == $remoteShopId
                        && $shop['marketplace'] == Helper::TARGET
                        && $shop['apps'][0]['app_status'] == 'active'
                    ) {
                        if (!empty($shop['sources'])) {
                            foreach ($shop['sources'] as $source) {
                                $sourceShopId = $source['shop_id'];
                                $sourceMarketplace = $source['code'];
                                $sourceAppCode = $source['app_code'];
                                $targetShopId = $shop['_id'];
                                $targetMarketplace = $shop['marketplace'];
                                $this->di->getAppCode()->setAppTag($sourceAppCode);
                                $configData = [
                                    'user_id' => $userId,
                                    'source' => $sourceMarketplace,
                                    'target' => $targetMarketplace,
                                    'source_shop_id' => $sourceShopId,
                                    'target_shop_id' => $targetShopId,
                                    'group_code' => 'feedStatus',
                                    'key' => 'accountStatus',
                                    'app_tag' => $this->di->getAppCode()->getAppTag() ?? 'default'
                                ];
                                $errorCode = $rawBody['data']['error_code'];
                                $isActiveFeed = $rawBody['feed'] == 'active';
                                $configData['error_code'] = $errorCode;
                                $configData['value'] = 'active';
                                if (!$isActiveFeed) {
                                    switch ($errorCode) {
                                        case 'InvalidInput':
                                            $configData['value'] = 'inactive';
                                            break;
                                        case 'Unauthorized':
                                            $configData['value'] = 'access_denied';
                                            break;
                                        default:
                                            $configData['value'] = 'expired';
                                    }
                                    // Updating warehouse status to inactive to stop syncing actions
                                    $this->updateWarehouseStatus($userId, $shop['_id'], 'inactive');
                                    $removeTag = true;
                                } else {
                                    // Updating warehouse status to active
                                    $this->updateWarehouseStatus($userId, $shop['_id'], 'active');
                                }
                                $objectManager = $this->di->getObjectManager();
                                $configObj = $objectManager->get(\App\Core\Models\Config\Config::class);
                                $configObj->setUserId($userId);
                                $configObj->setAppTag($configData['app_tag']);
                                $configObj->setConfig([$configData]);
                                $s3ProductIds = [];
                                if ($removeTag) {
                                    if (!empty($rawBody['upload'])) {
                                        $feedComponent = $this->di->getObjectManager()->get(Feed::class);
                                        foreach ($rawBody['upload'] as $bucketName) {
                                            $s3Data['bucket'] = $bucketName;
                                            $s3Data['prefix'] = $remoteShopId;
                                            $s3ProductIds = $feedComponent->getDataByPrefixUsings3($s3Data);
                                            $feedComponent->deleteDataFromS3ByPrefix($s3Data);
                                        }
                                    }
                                    if (!empty($s3ProductIds)) {
                                        $actionWiseData['upload'] = $s3ProductIds;
                                        $this->removeProgressTag($userId, $targetShopId, $sourceShopId, $s3ProductIds);
                                        $this->insertLastSyncActionData($userId, $targetShopId, $sourceShopId, $actionWiseData, $errorCode);
                                    }
                                    $notificationData = [
                                        'message' => 'There is an issue with your Amazon Seller Central account. Please follow the instructions provided in the banner to resolve it.',
                                        'severity' => 'critical',
                                        'user_id' => $userId,
                                        'marketplace' => Helper::TARGET,
                                        'appTag' => $configData['app_tag'],
                                        'process_code' => 'account_status'
                                    ];
                                    $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                                        ->addNotification($shop['_id'], $notificationData);
                                    $warehouse = $shop['warehouses'][0];
                                    $marketplaceName = \App\Amazon\Components\Common\Helper::MARKETPLACE_NAME[$warehouse['marketplace_id']];
                                    $mailData = [
                                        'source' => $configData['source'],
                                        'target' => $configData['target'],
                                        'country' => $marketplaceName,
                                        'app_code' => $sourceAppCode,
                                        'error_code' => $errorCode
                                    ];
                                    $this->sendInactiveStatusMail($mailData);
                                }
                            }
                        } else {
                            $message = 'empty sources found, shop_id: ' . $shop['_id'];
                        }
                    }
                }
                return ['success' => true, 'message' => 'Account status updated'];
            } else {
                $message = 'Unable to set di for user, userId: ' . $userId;
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from removeTagInactiveAccount(), Error:' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }
    }

    public function removeProgressTag($userId, $targetShopId, $sourceShopId, $sourceProductIds)
    {
        $preparedContent = [];
        $sourceProductIds = array_unique($sourceProductIds);
        $content = [
            'user_id' => $userId,
            'source_shop_id' => $sourceShopId,
            'childInfo' => [
                'shop_id' => $targetShopId,
                'target_marketplace' => Helper::TARGET,
            ],
            'unset' => ['process_tags'],
        ];
        foreach ($sourceProductIds as $id) {
            $content['source_product_id'] = (string) $id;
            $content['childInfo']['source_product_id'] = (string) $id;
            $preparedContent[] = $content;
        }
        if (!empty($preparedContent)) {
            $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace')
                ->marketplaceSaveAndUpdate($preparedContent);
        }
    }

    public function insertLastSyncActionData($userId, $targetShopId, $sourceShopId, $actionWiseData, $errorCode)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $lastSyncActionData = $mongo->getCollectionForTable('last_sync_action_data');
            $filter = [
                'user_id' => $userId,
                'source_shop_id' => $sourceShopId,
                'target_shop_id' => $targetShopId,
                'marketplace' => Helper::TARGET,
                'error_code' => $errorCode
            ];
            foreach ($actionWiseData as $action => $productIds) {
                $update[] =
                    [
                        'updateOne' => [
                            $filter,
                            [
                                '$addToSet' => [
                                    "actionWiseIds.$action" => ['$each' => $productIds]
                                ],
                                '$set' => ['created_at' => date('c')]
                            ],
                            ['upsert' => true]
                        ],
                    ];
            }
            $lastSyncActionData->bulkWrite($update);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from insertLastSyncActionData(), Error:' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }
    }

    public function  initiateLastFailedSyncAction($userId, $targetShopId, $sourceMarketplace)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $lastSyncActionData = $mongo->getCollectionForTable('last_sync_action_data');
            $actionWiseData = $lastSyncActionData->findOne([
                'user_id' => $userId,
                'target_shop_id' => $targetShopId,
                'marketplace' => Helper::TARGET
            ]);
            if (!empty($actionWiseData)) {
                $this->setDiForUser($userId);
                foreach ($actionWiseData['actionWiseIds'] as $action => $productIds) {
                    $prepareData = [
                        'operationType' => $action . "_sync",
                        'target' => [
                            'marketplace' => HELPER::TARGET,
                            'shopId' => $actionWiseData['target_shop_id']
                        ],
                        'source' => [
                            'marketplace' => $sourceMarketplace,
                            'shopId' => $actionWiseData['source_shop_id']
                        ],
                        'source_product_ids' => $productIds,
                        'useRefinProduct' => true,
                        'filter' => [
                            'item.status' => [
                                '0' => ['Inactive', 'Incomplete', 'Active', 'Submitted']
                            ]
                        ]

                    ];

                    if ($action == 'upload') {
                        $prepareData['operationType'] = 'product_upload';
                    }

                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => \App\Amazon\Components\Product\Product::class,
                        'method' => 'startLastActionSyncProcess',
                        'queue_name' => 'initiate_last_failed_sync_actions',
                        'user_id' => $userId,
                        'shop_id' => $targetShopId,
                        'data' => $prepareData
                    ];
                    $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                    $sqsHelper->pushMessage($handlerData);
                }
                $lastSyncActionData->deleteOne([
                    'user_id' => $userId,
                    'target_shop_id' => $targetShopId,
                    'marketplace' => Helper::TARGET
                ]);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from initiateLastFailedSyncAction(), Error:' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }
    }

    public function startLastActionSyncProcess($sqsData)
    {
        if (!empty($sqsData['data'])) {
            $product = $this->di->getObjectManager()->get(\App\Connector\Models\Product\Syncing::class);
            $product->startSync($sqsData['data']);
        }
    }

    private function updateWarehouseStatus($userId, $shopId, $status = 'active')
    {
        if (!empty($userId) && !empty($shopId)) {
            $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
            $userDetails = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
            $userDetails->updateOne(
                [
                    'user_id' => $userId,
                    'shops' => [
                        '$elemMatch' => [
                            '_id' => $shopId,
                            'marketplace' => Helper::TARGET,
                            'apps.app_status' => 'active'
                        ]
                    ]
                ],
                [
                    '$set' => [
                        'shops.$[shop].warehouses.0.status' => $status
                    ]
                ],
                [
                    'arrayFilters' => [
                        [
                            'shop._id' => $shopId,
                            'shop.marketplace' => Helper::TARGET,
                            'shop.apps.app_status' => 'active'
                        ]
                    ]
                ]
            );
        }
    }


    private function sendInactiveStatusMail($data)
    {
        try {
            $configData = $this->di->getConfig()->get('apiconnector') ? $this->di->getConfig()->get('apiconnector')->toArray() : [];
            if (!empty($configData)) {
                $source = $data['source'];
                $target = $data['target'];
                $appName = $source . '_base_path';
                $appPath = $configData[$source][$data['app_code']][$appName];
                $userName = $this->di->getUser()->username;
                $name = $this->di->getUser()->name ?? 'there';
                $storeName = str_replace('.myshopify.com', '', $userName);
                $appName = $this->di->getConfig()->app_name;
                $mailData = [
                    'username' => $userName,
                    'name' => $name,
                    'email' => $this->di->getUser()->source_email ?? $this->di->getUser()->email,
                    'country' => $data['country'],
                    'app_name' => $appName,
                    // 'subject' => 'Urgent Action Required: Resolve Amazon Seller Account Issue -'.$appName,
                    'subject' => 'Action Required: Amazon ' . ucfirst($data['country']) . ' Account Disconnected -' . $appName,
                    'redirect_uri' => "https://admin.{$source}.com/store/{$storeName}{$appPath}/panel/user/dashboard",
                    'path' => $target . DS . 'view' . DS . 'email' . DS . 'TokenExpired.volt'
                ];

                if ($data['error_code'] == 'InvalidInput') {
                    // $mailData['subject'] = 'Alert: Resolve Issues to Resume Services';
                    $mailData['subject'] = 'Syncing Paused: Amazon Account in Vacation Mode - ' . $appName;
                    $mailData['path'] = $target . DS . 'view' . DS . 'email' . DS . 'VacationMode.volt';
                }
                $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($mailData);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from sendInactiveStatusMail(), Error:' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }
    }

    private function setDiForUser($userId)
    {
        try {
            $getUser = User::findFirst([['_id' => $userId]]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin.'
            ];
        }

        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }

    public function getViewModalData($params)
    {
        if (isset(
            $params['container_id'],
            $params['source_attribute'],
            $params['target_attribute'],
            $params['target_attribute_path']
        )) {
            $userId = $this->di->getUser()->id;
            $sourceShopId = $this->di->getRequester()->getSourceId() ?? $params['source_shop_id'];
            $targetShopId = $this->di->getRequester()->getTargetId()  ?? $params['target_shop_id'];
            $sourceMarketplace = $this->di->getRequester()->getSourceName() ?? $params['source_marketplace'];
            $targetMarketplace = $this->di->getRequester()->getTargetName() ?? $params['target_marketplace'];
            $limit = (int) ($params['limit'] ?? 20);
            $page = $params['activePage'] ?? 1;
            $offset = ($page - 1) * $limit;
            $matchConditions = [
                'user_id' => $userId,
                'shop_id' => $sourceShopId,
                'container_id' => $params['container_id'],
                'source_marketplace' => $sourceMarketplace,
                'visibility' => 'Not Visible Individually'
            ];

            if (isset($params['search'])) {
                $matchConditions['variant_title'] = [
                    '$regex' => $params['search'],
                    '$options' => 'i'
                ];
            }

            $pipeline = [
                ['$match' => $matchConditions],
                ['$skip' => $offset],
                ['$limit' => $limit],
                ['$sort' => ['_id' => 1]],
                ['$project' => [
                    '_id' => 0,
                    'variant_title' => 1,
                    'source_product_id' => 1,
                    $params['source_attribute'] => 1,
                    'variant_attributes' => 1
                ]]
            ];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollectionForTable('product_container');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $sourceData = $productContainer->aggregate($pipeline, $arrayParams)->toArray();
            $preparedData = [];
            if (!empty($sourceData)) {
                foreach ($sourceData as $source) {
                    if (!isset($source[$params['source_attribute']])) {
                        foreach ($source['variant_attributes'] as $attribute) {
                            if ($attribute['key'] == $params['source_attribute']) {
                                $source[$params['source_attribute']] = $attribute['value'];
                                break;
                            }
                        }
                    }
                    $preparedData[$source['source_product_id']] = $source;

                    $sourceProductIds[] = $source['source_product_id'];
                }
                $targetData = $productContainer->find(
                    [
                        'user_id' => $userId,
                        'shop_id' => $targetShopId,
                        'container_id' => $params['container_id'],
                        'target_marketplace' => $targetMarketplace,
                        'source_product_id' => ['$in' => $sourceProductIds]
                    ],
                    [
                        "projection" => ['_id' => 0, 'container_id' => 1, 'source_product_id' => 1, 'category_settings.variants_json_attributes_values.' . $params['target_attribute'] => 1],
                        "typeMap" => ['root' => 'array', 'document' => 'array'],
                    ]
                )->toArray();
                if (!empty($targetData)) {
                    foreach ($targetData as $data) {
                        if (isset($preparedData[$data['source_product_id']])) {
                            $mappedValue = $this->getValueByPath($data, $params['target_attribute_path']);
                            if ($mappedValue !== null) {
                                if (isset($preparedData[$data['source_product_id']])) {
                                    $preparedData[$data['source_product_id']]['mapped_variant_value'] = $mappedValue;
                                }
                            }
                        }
                    }
                }
                return ['success' => true, 'data' => $preparedData];
            } else {
                $message = 'Source data not found';
            }
        } else {
            $message = 'Required params missing(container_id, source_attribute and target_attribute,target_attribute_path)';
        }
        return ['success' => false, 'message' => $message];
    }

    private function getValueByPath($array, $path)
    {
        $keys = explode('.', $path);
        $value = $array;
        foreach ($keys as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } else {
                return null;
            }
        }
        return $value;
    }

    public function getAttributeData($params)
    {
        if (!isset($params['container_id'], $params['type'], $params['key'])) {
            return ['success' => false, 'message' => 'Required params missing (container_id, type, key)'];
        }

        $userId = $this->di->getUser()->id;
        $sourceShopId = $this->di->getRequester()->getSourceId() ?? $params['source_shop_id'];
        $sourceMarketplace = $this->di->getRequester()->getSourceName() ?? $params['source_marketplace'];


        $matchConditions = [
            'user_id' => $userId,
            'shop_id' => $sourceShopId,
            'container_id' => $params['container_id'],
            'source_marketplace' => $sourceMarketplace
        ];

        if ($params['type'] !== 'parentMetafield') {
            $matchConditions['visibility'] = 'Not Visible Individually';
        }

        $project = [];
        if ($params['type'] === 'normalAttribute') {
            $project = [$params['key'] => 1];
        } elseif ($params['type'] === 'variantAttribute') {
            $project = ['variant_attributes' => 1];
        } elseif ($params['type'] == 'parentMetafield' || $params['type'] == 'variantMetafield') {
            $project = [$params['type'] . "." . $params['key'] => 1];
        } else {
            return ['success' => false, 'message' => 'Invalid type'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productContainer = $mongo->getCollectionForTable('product_container');

        $pipeline = [
            ['$match' => $matchConditions],
            ['$project' => $project]
        ];
        $sourceData = $productContainer->aggregate($pipeline, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();

        $values = [];
        if ($params['type'] === 'normalAttribute') {
            foreach ($sourceData as $source) {
                if (!empty($source[$params['key']]) || in_array($source[$params['key']], [0, '0'])) {
                    $values[] = $source[$params['key']];
                }
            }
        } elseif ($params['type'] === 'variantAttribute') {
            foreach ($sourceData as $source) {
                foreach ($source['variant_attributes'] as $attributeData) {
                    if ($attributeData['key'] === $params['key']) {
                        if (!empty($attributeData['value']) || in_array($attributeData['value'], [0, '0'])) {
                            $values[] = $attributeData['value'];
                            break;
                        }
                    }
                }
            }
        } elseif ($params['type'] == 'parentMetafield' || $params['type'] == 'variantMetafield') {
            foreach ($sourceData as $source) {
                if (isset($source[$params['type']][$params['key']])) {
                    if (!empty($source[$params['type']][$params['key']]['value']) || in_array($source[$params['type']][$params['key']]['value'], [0, '0'])) {
                        $values[] = $source[$params['type']][$params['key']]['value'];
                    }
                }
            }
        }

        return ['success' => true, 'data' => array_values(array_unique($values))];
    }

    public function uploadProduct($data, $operationType = self::OPERATION_TYPE_UPDATE)
    {
        
        $date = date('d-m-Y');
        $feedContent = [];
        if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
            $userId = $data['data']['params']['user_id'];
            $targetShopId = $data['data']['params']['target']['shopId'];
            $sourceShopId = $data['data']['params']['source']['shopId'];
            $targetMarketplace = $data['data']['params']['target']['marketplace'];
            $sourceMarketplace = $data['data']['params']['source']['marketplace'];
            $productErrorList = [];
            $jsonProductErrorList = [];
            $container_ids = [];
            $specificData = $data['data']['params'];
            $specificData['type'] = 'product';
            $alreadySavedActivities = [];
            $response = [];
            $contentInActivity = [];
            $logFile = "amazon/{$userId}/{$sourceShopId}/ProductUpload-S3/{$date}.log";
            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $targetShop = $user_details->getShop($targetShopId, $userId);
            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
            $inventoryComponent = $this->di->getObjectManager()->get(Inventory::class);
            $marketplaceId = "";
            $activeAccounts = [];
            $addTagProductList = [];
            $allParentDetails = $data['data']['all_parent_details'] ?? [];
            $allProfileInfo = $data['data']['all_profile_info'] ?? [];
            $validateFeedContent = [];

            foreach ($targetShop['warehouses'] as $warehouse) {
                if ($warehouse['status'] == "active") {
                    $activeAccounts = $warehouse;
                    $marketplaceId = $warehouse['marketplace_id'] ?? [];
                    break;
                }
            }

            if (!empty($activeAccounts)) {
                $connector = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
                $currencyCheck = $connector->checkCurrency($userId, $targetMarketplace, $sourceMarketplace, $targetShopId, $sourceShopId);
                $this->configObj->setUserId($userId);
                $this->configObj->setTarget($targetMarketplace);
                $this->configObj->setTargetShopId($targetShopId);
                $this->configObj->setSourceShopId($sourceShopId);

                if (!$currencyCheck) {
                    $this->configObj->setGroupCode('currency');
                    $sourceCurrency = $this->configObj->getConfig('source_currency');
                    $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);
                    $amazonCurrency = $this->configObj->getConfig('target_currency');
                    $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);
                    if (isset($sourceCurrency[0]['value']) && isset($amazonCurrency[0]['value']) && $sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
                        $currencyCheck = true;
                    } else {
                        $amazonCurrencyValue = $this->configObj->getConfig('target_value');
                        $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
                        if (isset($amazonCurrencyValue[0]['value'])) {
                            $currencyCheck = $amazonCurrencyValue[0]['value'];
                        }
                    }
                }

                $this->di->getLog()->logContent('operation type' . print_r($operationType, true), 'info', $logFile);
                if ($currencyCheck) {
                    $this->currency = $currencyCheck;
                    $products = $data['data']['rows'];
                    $sourceProductIds = array_column($products, 'source_product_id');
                    $productTypeHelper = $this->di->getObjectManager()->get(ListingHelper::class);
                    $productTypeHelper->resetclassVariables();
                     if($this->di->getUser()->id == "690dbb0acdbc0498ca0e4159"){
                                $this->di->getLog()->logContent('Products' . json_encode($products), 'info', 'yash-amazon_product_upload_19Nov.log');
                            }
                   
                    foreach ($products as $product) {
                        try {
                            $product = json_decode(json_encode($product), true);
                            if (isset($product['parent_details']) && is_string($product['parent_details'])) {
                                $product['parent_details'] = $allParentDetails[$product['parent_details']];
                            }
                            if (isset($product['profile_info']['$oid'])) {
                                $product['profile_info'] = $allProfileInfo[$product['profile_info']['$oid']];
                            }
                            
                            if($operationType == self::OPERATION_TYPE_UPDATE && isset($product['edited']['status']) && in_array($product['edited']['status'], ['Active','Inactive','Incomplete'])){
                                $productErrorList[$product['source_product_id']] = ['AmazonError1099'];
                                continue;
                            } elseif($operationType == self::OPERATION_TYPE_PARTIAL_UPDATE && (!isset($product['edited']['status']) || in_array($product['edited']['status'], ['Not Listed','Not Listed: Offer']))){
                                $productErrorList[$product['source_product_id']] = ['AmazonError1098'];
                                continue;
                            }
                            
                            $categorySettings = [];
                            $sync = [];
                            $sourceProductId = $product['source_product_id'];
                            $container_ids[$sourceProductId] = $product['container_id'];
                            $alreadySavedActivities[$sourceProductId] = $product['edited']['last_activities'] ?? [];
                            
                            if ($operationType == self::OPERATION_TYPE_UPDATE) {
                                $categorySettings = json_decode(json_encode($this->getUploadCategorySettings($product)), true);
                                
                                $this->removeTag([$sourceProductId], [Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
                            } elseif ($operationType == self::OPERATION_TYPE_PARTIAL_UPDATE) {
                                $sync = $this->getSyncStatus($product);
                                if (isset($sync['productSyncProductList']) && $sync['productSyncProductList']) {
                                    $categorySettings = $this->getPartialUpdateCategorySettings($product);
                                }
                                $this->removeTag([$sourceProductId], [Helper::PROCESS_TAG_SYNC_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
                            }

                            if (!empty($categorySettings['errors'])) {
                                $productErrorList[$sourceProductId] = $categorySettings['errors'];
                                continue;
                            }
                            
                            if (empty($categorySettings) || empty($categorySettings['product_type'])) {
                                $productErrorList[$sourceProductId] = ($operationType == self::OPERATION_TYPE_PARTIAL_UPDATE && isset($sync['productSyncProductList']) && !$sync['productSyncProductList']) ? ['AmazonError107'] : ['AmazonError101'];
                                
                                continue;
                            }

                            $type = ($product['type'] == 'variation') ? self::PRODUCT_TYPE_PARENT : (($product['visibility'] == 'Catalog and Search') ? null : self::PRODUCT_TYPE_CHILD);
                            
                            $productTypeHelper->init([
                                'categorySettings' => $categorySettings,
                                'product' => $product,
                                'sourceMarketplace' => $sourceMarketplace,
                                'targetShopId' => $targetShopId
                            ]);
                            
                            $preparedContent = $productTypeHelper->setContentJsonListing($categorySettings, $product, $type, $operationType, $sourceMarketplace);
                           if($this->di->getUser()->id == "690dbb0acdbc0498ca0e4159"){
                                $this->di->getLog()->logContent('Prepared Content for source_product_id ' . $sourceProductId . ': ' . json_encode($preparedContent), 'info', 'yash-amazon_product_upload_19Nov.log');
                            }
                            if (empty($preparedContent)) {
                                continue;
                            }
                            if($this->di->getUser()->id == "690dbb0acdbc0498ca0e4159"){
                                $this->di->getLog()->logContent(' source_product_id processing' . $sourceProductId . ': ', 'info', 'yash-amazon_product_upload_19Nov.log');
                            }
                            
                            if ($userId != "658123d231e8d0d4970aad22" && isset($preparedContent['product_description']) && !mb_check_encoding($preparedContent['product_description'], 'UTF-8')) {
                                $preparedContent['product_description'] = mb_convert_encoding($preparedContent['product_description'], 'UTF-8', 'ISO-8859-1');
                            }

                            if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
                                $jsonProductErrorList[$sourceProductId] = [
                                    'error' => $preparedContent['error'],
                                    'container_id' => $product['container_id']
                                ];
                                $processTags = $productTypeHelper->getProgressTag($product, $sourceProductId, $targetShopId, $operationType);
                                if (is_array($processTags)) {
                                    $jsonProductErrorList[$sourceProductId]['process_tags'] = $processTags;
                                }
                                continue;
                            }

                            if (isset($preparedContent['operation-type'])) {
                                $operationType = $preparedContent['operation-type'];
                            }

                            if (!isset($preparedContent['json_product_type']) || empty($preparedContent['json_product_type'])) {
                                continue;
                            }

                            $time = date(DATE_ISO8601);
                            if (isset($preparedContent['feedToSave'])) {
                                $preparedContent['feedToSave']['time'] = $time;
                                $contentInActivity[$sourceProductId] = $preparedContent['feedToSave'];
                                $contentInActivity[$sourceProductId]['product_type'] = $preparedContent['json_product_type'];
                                unset($preparedContent['feedToSave']);
                            }
                           
                            $productType = is_array($preparedContent['json_product_type']) ? ($preparedContent['json_product_type'][0] ?? '') : $preparedContent['json_product_type'];
                            
                            $feedContent[$sourceProductId]['sku'] = $product['edited']['sku'] ?? $product['sku'] ?? $sourceProductId;
                            $feedContent['json_product_type'] = $productType;
                            $feedContent['language_tag'] = $preparedContent['language_tag'] ?? null;
                            $feedContent['marketplace_id'] = $preparedContent['marketplace_id'] ?? null;
                            unset($preparedContent['language_tag'], $preparedContent['marketplace_id']);
                            // $feedContent[$sourceProductId] =$preparedContent;

                            $feedContent[$sourceProductId] =base64_encode(serialize($preparedContent));
                            $validateFeedContent[$productType][$sourceProductId] =base64_encode(serialize($preparedContent));
                            $validateFeedContent[$productType]['json_product_type'] = $productType;
                            
                        } catch (\Throwable $e) {
                            $this->di->getLog()->logContent(
                                'Product Error - Source Product ID: ' . ($product['source_product_id'] ?? 'UNKNOWN') . 
                                ' | Container ID: ' . ($product['container_id'] ?? 'UNKNOWN') . 
                                ' | Error: ' . $e->getMessage() . 
                                ' | File: ' . $e->getFile() . 
                                ' | Line: ' . $e->getLine(),
                                'error',
                                'Exception.log'
                            );
                            if (isset($product['source_product_id'])) {
                                $productErrorList[$product['source_product_id']] = ['Exception: ' . $e->getMessage()];
                            }
                        }
                    }
                   
                   
                    if (!empty($data['data']['return_feed'])) {
                        $feed = $this->unserialize_recursive($feedContent);
                        return !empty($feed) ? [
                            'success' => true,
                            'data' => $feed,
                            'jsonProductErrorList' => $this->getErrorMessages($jsonProductErrorList),
                        ] : [
                            'success' => false,
                            'message' => "No feed is generated for this product",
                            'jsonProductErrorList' => $this->getErrorMessages($jsonProductErrorList),
                            'productErrorList' => $productErrorList
                        ];
                    }
                    if ($operationType == self::OPERATION_TYPE_UPDATE && !empty($feedContent)) {
                        $users = ["63624c0e720de16a8c7ebf33", "66fcea76d2977b9a2a0f7be2", "670177405e374b2f4d04efb2", "66d55b3f4b1d8ffaad028a42", "673861748e16da69210fa4b2"];
                        foreach ($validateFeedContent as $productType => $content) {
                            if (isset($content['json_product_type']) && !in_array($userId, $users)) {
                                // print_r('Validating JSON feed for product type: ' . $productType . PHP_EOL);
                                // die;
                                $result = $productTypeHelper->validateJsonFeed($content, $productType);
                                
                               
                                if (isset($result['success'], $result['errors']) && !empty($result['errors'])) {
                                    foreach ($result['errors'] as $sourceProductId => $errorArray) {
                                        $jsonProductErrorList[$sourceProductId] = ['error' => $errorArray, 'container_id' => $container_ids[$sourceProductId] ?? null];
                                        unset($feedContent[$sourceProductId]);
                                        unset($sourceProductIds[array_search($sourceProductId, $sourceProductIds)]);
                                    }
                                    if (empty($sourceProductIds)) {
                                        $feedContent = [];
                                    }
                                }
                            }
                            unset($feedContent['marketplace_id'], $feedContent['language_tag'],$feedContent['json_product_type']);
                        }
                    } else {
                        unset($feedContent['marketplace_id'], $feedContent['language_tag'],$feedContent['json_product_type']);
                    }
 
                    $customUser = ['691469c9112c21f8170693aa', '68cd3e7317ce14aeed0c2fd7',"68d40a685e23b0655309cd7e" , "68b083779cd1fc7db00e8137","659eb5e58efcd9064c0f9c72" ,"690257ed5077e1133200b5f7","641adf51fde0c9a1e0054481", "683b7a292c85241fa504ed32", "692a6efc5b0b5454530bbcc3", "690257ed5077e1133200b5f7","67f0313bd665833bba029f54"];                    if (!empty($feedContent)) 
                    {
                        unset($feedContent['marketplace_id'], $feedContent['language_tag']);
                        
                        if(count($feedContent) < 50 && in_array($this->di->getUser()->id, $customUser))
                        {
                            $specifics = [
                                'home_shop_id' => $targetShop['_id'],
                                'marketplace_id' => $targetShop['warehouses'][0]['marketplace_id'],
                                'shop_id' => $targetShop['remote_shop_id'],
                                'sourceShopId' => $sourceShopId,
                                'feedContent' => base64_encode(serialize($feedContent)),
                                'user_id' => $userId,
                                'operation_type' => $operationType,
                                'uploadThroughS3' => true,
                                'unified_json_feed' => true
                            ];
                            $res = $commonHelper->sendRequestToAmazon('small-chunk-upload', $specifics, 'POST');
                           
                            if(isset($res['success']) && $res['success']) {
                                $feedContent =unserialize(base64_decode((string) $res['feedContent']));
                               
                                $error = $res['error'];
                                $accepted = $res['accepted'];
                                
                                if($operationType == self::OPERATION_TYPE_UPDATE)
                                {
                                    $this->setUploadTag($accepted,$data['data']['params'], $container_ids); // need to remove error when uploaded tag is applied
                                }
                                else{
                                    $this->unsetError($accepted,$data['data']['params'], $container_ids);
                                }
                                foreach($error as $sourceProductId => $errorArray) {
                                    $jsonProductErrorList[$sourceProductId] = ['error' => $errorArray, 'container_id' => $container_ids[$sourceProductId] ?? null];
                                    unset($contentInActivity[$sourceProductId]);
                                }
                                foreach($accepted as $sourceProductId => $acceptedArray) {
                                    
                                    $PatchListingContent[$sourceProductId] = $contentInActivity[$sourceProductId];
                                    unset($contentInActivity[$sourceProductId]);
                                }
                                $inventoryComponent->saveFeedInDb($PatchListingContent, 'product', $container_ids, $data['data']['params'], $alreadySavedActivities);
                    
                                if (!empty($jsonProductErrorList)) {
                                    
                                    $this->di->getObjectManager()->get(Error::class)->saveErrorInProduct($jsonProductErrorList, 'Listing', $targetShopId, $sourceShopId, $userId, $currencyCheck);
                                }
                                if(empty($feedContent)){
                                    $flag = $operationType == self::OPERATION_TYPE_PARTIAL_UPDATE ? Helper::PROCESS_TAG_SYNC_PRODUCT : Helper::PROCESS_TAG_UPLOAD_PRODUCT;
                                    $specificData['marketplaceId'] = $marketplaceId;
                                    
                                     $specificData['unsetTag'] = $flag;
                                   $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices([], $productErrorList, $feedContent, $products);

                                }

                            }
                        }
                    //    print_r($feedContent);die;
                        if(!empty($feedContent)){

                            $type = $operationType == self::OPERATION_TYPE_PARTIAL_UPDATE ? Helper::PROCESS_TAG_UPDATE_FEED : Helper::PROCESS_TAG_UPLOAD_FEED;
                            $flag = $operationType == self::OPERATION_TYPE_PARTIAL_UPDATE ? Helper::PROCESS_TAG_SYNC_PRODUCT : Helper::PROCESS_TAG_UPLOAD_PRODUCT;
                            $specificData['marketplaceId'] = $marketplaceId;
                        $specificData['tag'] = $type;
                        $specificData['unsetTag'] = $flag;
                       
                        // foreach ($feedContent as $content) {
                            $permission = $commonHelper->remotePermissionToSendRequest('handshake', ["remote_shop_id" => $targetShop['remote_shop_id'], "action_name" => $operationType], 'POST');
                            if (!$permission['success']) {
                                return ["CODE" => "REQUEUE", 'time_out' => 10, 'message' => "Please wait 10 minutes before attempting the process again.", 'success' => false];
                            }

                            $specifics = [
                                'home_shop_id' => $targetShop['_id'],
                                'marketplace_id' => $targetShop['warehouses'][0]['marketplace_id'],
                                'shop_id' => $targetShop['remote_shop_id'],
                                'sourceShopId' => $sourceShopId,
                                'feedContent' => base64_encode(serialize($feedContent)),
                                'user_id' => $userId,
                                'operation_type' => $operationType,
                                "handshake_token" => $permission['token'],
                                "uploadThroughS3" => true,
                                'unified_json_feed' => true
                            ];
                            $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');
                            
                            if (isset($response['success']) && $response['success']) {
                                unset($content['category'], $content['sub_category'], $content['barcode_exemption']);
                                // $specifics['feedContent'] = $feedContent;
                            }

                            $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);
                            
                            $addTagProductList = array_merge($addTagProductList, $productSpecifics['addTagProductList']);
                            $productErrorList = array_merge($productErrorList, $productSpecifics['productErrorList']);
                        // }
                        
                        $inventoryComponent->saveFeedInDb($contentInActivity, 'product', $container_ids, $data['data']['params'], $alreadySavedActivities);
                        
                        if (!empty($jsonProductErrorList)) {
                            $this->di->getObjectManager()->get(Error::class)->saveErrorInProduct($jsonProductErrorList, 'Listing', $targetShopId, $sourceShopId, $userId, $currencyCheck);
                        }
                    }

                        return $this->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0, true);
                    }
                    
                    if (!empty($jsonProductErrorList)) {
                        $this->di->getObjectManager()->get(Error::class)->saveErrorInProduct($jsonProductErrorList, 'Listing', $targetShopId, $sourceShopId, $userId, $currencyCheck);
                    }
                    $specificData['unsetTag'] = Helper::PROCESS_TAG_UPLOAD_PRODUCT;
                    if($this->di->getUser()->id == "690dbb0acdbc0498ca0e4159"){
                                $this->di->getLog()->logContent('ProductError List' . json_encode($productErrorList), 'info', 'yash-amazon_product_upload_19Nov.log');
                    }
                    $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);
                    if($this->di->getUser()->id == "690dbb0acdbc0498ca0e4159"){
                                $this->di->getLog()->logContent('productSpecifics' . json_encode($productSpecifics), 'info', 'yash-amazon_product_upload_19Nov.log');
                    }
                    
                   
                    return $this->setResponse(Helper::RESPONSE_SUCCESS, count($addTagProductList), 0, count($productErrorList), 0, true);
                }
                
                if (isset($data['data']['params']['source_product_ids']) && !empty($data['data']['params']['source_product_ids'])) {
                    $this->removeTag([$data['data']['params']['source_product_ids']], [Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
                }

                return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Currency of Shopify and Amazon account are not same. Please fill currency settings from Global Price Adjustment in settings page.', 0, 0, 0, false);
            }
            return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0, false);
        }
        
        if (isset($data['data']['params'])) {
            $params = $data['data']['params'];
            $sourceShopId = $params['source']['shopId'] ?? "";
            $targetShopId = $params['target']['shopId'] ?? "";
            $userId = $params['user_id'] ?? $this->_user_id;
            if (isset($params['source_product_ids']) && !empty($params['source_product_ids'])) {
                $this->removeTag([$params['source_product_ids']], [Helper::PROCESS_TAG_UPLOAD_PRODUCT], $targetShopId, $sourceShopId, $userId, [], []);
            }
            if (isset($params['next_available']) && $params['next_available']) {
                return $this->setResponse(Helper::RESPONSE_SUCCESS, "", 0, 0, 0, true);
            }
        }

        return $this->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0, false);
    }

    public function setUploadTag($accepted, $params, $container_ids = []): void
    {
        if (empty($accepted) || empty($params)) {
            return;
        }
        $targetShopId = $params['target']['shopId'] ?? null;
        $sourceShopId = $params['source']['shopId'] ?? null;
        $targetMarketplace = $params['target']['marketplace'] ?? null;
        $sourceMarketplace = $params['source']['marketplace'] ?? null;
        $userId = $params['user_id'] ?? null;

        if (!$targetShopId || !$sourceShopId || !$userId) {
            return;
        }

        $sourceMarketplace = $sourceMarketplace ?? '';
        $targetMarketplace = $targetMarketplace ?? 'amazon';
        $additionalData = [];
        $updateDataInsert = [];

        

        $additionalData['source'] = [
            'marketplace' => $sourceMarketplace,
            'shopId' => (string) $sourceShopId
        ];
        $additionalData['target'] = [
            'marketplace' => 'amazon',
            'shopId' => (string) $targetShopId
        ];

        // Convert array keys to string (source_product_ids)
        $sourceProductIds = array_keys($accepted);
        $encoded = implode(',', $sourceProductIds);
        $sourceProductIds = explode(',', $encoded);

        // Prepare update data for each accepted product
        foreach ($sourceProductIds as $sourceProductId) {
            $containerId = null;
            
            if (isset($container_ids[$sourceProductId])) {
                $containerId = $container_ids[$sourceProductId];
            }
            
            if ($containerId) {
                $updateData = [
                    'source_product_id' => (string) $sourceProductId,
                    'user_id' => (string) $userId,
                    'source_shop_id' => (string) $sourceShopId,
                    'target_marketplace' => 'amazon',
                    'status' => Helper::PRODUCT_STATUS_UPLOADED,
                    'shop_id' => (string) $targetShopId,
                    'container_id' => (string) $containerId
                ];
                $updateData['unset']['error'] = 1;
                $updateDataInsert[] = $updateData;
            }
        }

        // Save products with updated status
      
        if (!empty($updateDataInsert)) {
            $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
            $editHelper->saveProduct($updateDataInsert, $userId, $additionalData);
        }
    }

    public function unsetError($accepted, $params, $container_ids = [], $operation = null): void
    {
        if (empty($accepted) || empty($params)) {
            return;
        }
        $targetShopId = $params['target']['shopId'] ?? null;
        $sourceShopId = $params['source']['shopId'] ?? null;
        $targetMarketplace = $params['target']['marketplace'] ?? null;
        $sourceMarketplace = $params['source']['marketplace'] ?? null;
        $userId = $params['user_id'] ?? null;

        if (!$targetShopId || !$sourceShopId || !$userId) {
            return;
        }

        $sourceMarketplace = $sourceMarketplace ?? '';
        $targetMarketplace = $targetMarketplace ?? 'amazon';
        $additionalData = [];
        $updateDataInsert = [];

        

        $additionalData['source'] = [
            'marketplace' => $sourceMarketplace,
            'shopId' => (string) $sourceShopId
        ];
        $additionalData['target'] = [
            'marketplace' => 'amazon',
            'shopId' => (string) $targetShopId
        ];

        if($operation === null){
            $sourceProductIds = array_keys($accepted);
        }else{
            $sourceProductIds = $accepted;
        }

        // Convert array keys to string (source_product_ids)
        $sourceProductIds = array_keys($accepted);
        $encoded = implode(',', $sourceProductIds);
        $sourceProductIds = explode(',', $encoded);

        // Prepare update data for each accepted product
        foreach ($sourceProductIds as $sourceProductId) {
            $containerId = null;
            
            if (isset($container_ids[$sourceProductId])) {
                $containerId = $container_ids[$sourceProductId];
            }
            
            if ($containerId) {
                $updateData = [
                    'source_product_id' => (string) $sourceProductId,
                    'user_id' => (string) $userId,
                    'source_shop_id' => (string) $sourceShopId,
                    'target_marketplace' => 'amazon',
                    'shop_id' => (string) $targetShopId,
                    'container_id' => (string) $containerId
                ];
                // $updateData['unset']['error'] = 1;
                if ($operation === null) {
                    $updateData['unset']['error'] = 1;
                } else {
                    $updateData['unset']['error'][$operation] = 1;
                }
                $updateDataInsert[] = $updateData;
            }
        }

        // Save products with unset error
      
        if (!empty($updateDataInsert)) {
            $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
            $editHelper->saveProduct($updateDataInsert, $userId, $additionalData);
        }
    }

    const ERROR_REFRESH_CHUNK_SIZE = 60;

    public function ErrorRefresh($variantList, $feed): bool
    {
        $marketplaceHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');

        $homeShopId = $feed['shop_id'];
        $sourceShopId = $feed['source_shop_id'];
        $userId = $feed['user_id'];
        $sourceMarketplace = '';
        $uploadedVariant = [];
        $remoteShopId = 0;
        $additionalData = [];
        $errors = [];
        $err = [];
        $amazonStatuses = [];
        $shop = $this->_user_details->getShop($homeShopId, $userId);

        if (isset($shop['sources']) && $shop['sources']) {
            foreach ($shop['sources'] as $source) {
                if (isset($source['shop_id']) && $source['shop_id'] == $sourceShopId) {
                    $sourceMarketplace = $source['code'] ?? '';
                }
            }
        }

        $additionalData['source'] = [
            'marketplace' => $sourceMarketplace,
            'shopId' => (string) $sourceShopId
        ];
        $additionalData['target'] = [
            'marketplace' => 'amazon',
            'shopId' => (string) $homeShopId
        ];
        if ($shop && isset($shop['remote_shop_id'])) {
            $remoteShopId = $shop['remote_shop_id'];
        }

        // converting int to string in array
        $variantList = implode(',', $variantList);
        $variantList = explode(',', $variantList);

        $logFile = 'amazon/errorRefresh/' . $userId . '/' . date('Y-m-d') . '.log';
        $this->di->getLog()->logContent('ErrorRefresh: started with ' . count($variantList) . ' source_product_ids, user=' . $userId . ', shop=' . $homeShopId, 'info', $logFile);

        // Step 1: Expand parent source_product_ids to include all children via refine_product
        // Skip expansion when called from SQS chunk consumer (IDs are already expanded)
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        if (empty($feed['skip_expansion'])) {
            $refineCollection = $mongo->getCollectionForTable('refine_product');
            $parentProducts = $refineCollection->find([
                'source_product_id' => ['$in' => $variantList],
                'user_id' => (string)$userId,
                'type' => 'variation',
            ], ['projection' => ['source_product_id' => 1, 'items.source_product_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();

            if (!empty($parentProducts)) {
                $childIds = [];
                foreach ($parentProducts as $parent) {
                    if (isset($parent['items']) && is_array($parent['items'])) {
                        foreach ($parent['items'] as $item) {
                            if (!empty($item['source_product_id'])) {
                                $childIds[] = (string)$item['source_product_id'];
                            }
                        }
                    }
                }
                $beforeCount = count($variantList);
                $variantList = array_values(array_unique(array_merge($variantList, $childIds)));
                $this->di->getLog()->logContent('ErrorRefresh: found ' . count($parentProducts) . ' parents, expanded with ' . (count($variantList) - $beforeCount) . ' new children, total=' . count($variantList) . ' variants', 'info', $logFile);
            } else {
                $this->di->getLog()->logContent('ErrorRefresh: no parent products found, ' . count($variantList) . ' variants unchanged', 'info', $logFile);
            }
        } else {
            $this->di->getLog()->logContent('ErrorRefresh: skipping parent expansion (already expanded), ' . count($variantList) . ' variants', 'info', $logFile);
        }

        $productCollection = $mongo->getCollectionForTable('product_container');

        // Step 2: If expanded list > 60, chunk via SQS queue
        if (count($variantList) > self::ERROR_REFRESH_CHUNK_SIZE) {
            $chunks = array_chunk($variantList, self::ERROR_REFRESH_CHUNK_SIZE);
            $totalChunks = count($chunks);
            $this->di->getLog()->logContent('ErrorRefresh: list too large (' . count($variantList) . '), splitting into ' . $totalChunks . ' SQS chunks of ' . self::ERROR_REFRESH_CHUNK_SIZE, 'info', $logFile);

            // Create queued task for progress tracking
            $feedId = null;
            $progressPerChunk = round(100.0 / $totalChunks, 2);
            try {
                $queuedTasksModel = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
                $feedId = $queuedTasksModel->setQueuedTask(
                    $homeShopId,
                    [
                        'user_id' => $userId,
                        'marketplace' => 'amazon',
                        'message' => 'Error Refresh in progress',
                        'process_code' => 'amazon_error_refresh',
                        'shop_id' => (string) $homeShopId,
                        'additional_data' => [
                            'process_label' => 'Error Refresh',
                            'total_items' => count($variantList),
                            'total_chunks' => $totalChunks,
                            'chunk_size' => self::ERROR_REFRESH_CHUNK_SIZE,
                            'processed_chunks' => 0,
                        ],
                    ]
                );
            } catch (\Exception $e) {
                $this->di->getLog()->logContent('ErrorRefresh: failed to create queued task: ' . $e->getMessage(), 'info', $logFile);
            }

            foreach ($chunks as $chunk) {
                $sqsData = [
                    'type' => 'full_class',
                    'class_name' => \App\Amazon\Components\ProductHelper::class,
                    'method' => 'processErrorRefreshChunk',
                    'user_id' => (string)$userId,
                    'data' => [
                        'source_product_ids' => $chunk,
                        'shop_id' => (string)$homeShopId,
                        'source_shop_id' => (string)$sourceShopId,
                        'user_id' => (string)$userId,
                        'feed_id' => is_string($feedId) ? $feedId : null,
                        'progress_per_chunk' => $progressPerChunk,
                    ],
                    'queue_name' => 'amazon_error_refresh',
                ];
                $this->di->getMessageManager()->pushMessage($sqsData);
            }
            return true;
        }

        // Fetch all source products in a single query
        $sourceProducts = $productCollection->find([
            'source_product_id' => ['$in' => $variantList],
            'shop_id' => $sourceShopId,
            'user_id' => $userId
        ])->toArray();

        // Fetch all target products in a single query
        $targetProducts = $productCollection->find([
            'source_product_id' => ['$in' => $variantList],
            'shop_id' => $homeShopId,
            'user_id' => $userId
        ])->toArray();

        // Build indexed maps for quick lookups
        $sourceProductsMap = [];
        foreach ($sourceProducts as $product) {
            $product = json_decode(json_encode($product), true);
            if (isset($product['source_product_id'])) {
                $sourceProductsMap[$product['source_product_id']] = $product;
            }
        }

        $targetProductsMap = [];
        foreach ($targetProducts as $product) {
            $product = json_decode(json_encode($product), true);
            if (isset($product['source_product_id'])) {
                $targetProductsMap[$product['source_product_id']] = $product;
            }
        }

        // Prepare product data map with SKUs for batch processing
        $productDataMap = [];
        $skuToSourceIdMap = [];
        
        foreach ($variantList as $sourceProductId) {
            // Get source and target products from the pre-fetched maps
            $sourceProduct = $sourceProductsMap[$sourceProductId] ?? [];
            $targetProduct = $targetProductsMap[$sourceProductId] ?? [];
            
            // If target product doesn't exist, skip this product
            if (empty($targetProduct)) {
                continue;
            }
            
            // Merge products similar to getSingleProduct: target + source (target takes precedence)
            $productData = $targetProduct + $sourceProduct;
            
            if (isset($productData['shop_id'], $productData['source_product_id'], $productData['sku']) 
                && $productData['shop_id'] == $homeShopId 
                && $productData['source_product_id'] == $sourceProductId) {
                
                $sku = $productData['sku'];
                $productDataMap[$sku] = [
                    'source_product_id' => $sourceProductId,
                    'container_id' => $productData['container_id'],
                    'product_data' => $productData
                ];
                $skuToSourceIdMap[$sku] = $sourceProductId;
                $productStatus[$sourceProductId] = $productData['status'] ?? '';
            }
        }
        
        // Batch process SKUs - 20 at a time
        $skuList = array_keys($productDataMap);
        $batchSize = 20;
        $skuBatches = array_chunk($skuList, $batchSize);

        $this->di->getLog()->logContent('ErrorRefresh: mapped ' . count($skuList) . ' SKUs from ' . count($variantList) . ' variants (' . (count($variantList) - count($skuList)) . ' skipped - no target product), ' . count($skuBatches) . ' batches', 'info', $logFile);

        $commonHelper = $this->di->getObjectManager()->get(Helper::class);

        foreach ($skuBatches as $skuBatch) {
            $rawBody = [
                'sku' => $skuBatch,
                'shop_id' => $remoteShopId,
                'includedData' => "issues,attributes,summaries",
                'page_size' => 20,
            ];

            $response = $commonHelper->sendRequestToAmazon('search-listing', $rawBody, 'GET');
            $this->di->getLog()->logContent('ErrorRefresh: batch sent ' . count($skuBatch) . ' SKUs, received ' . count($response['response']['items'] ?? []) . ' items, numberOfResults=' . ($response['response']['numberOfResults'] ?? 'n/a'), 'info', $logFile);

            // Process the batch response
            if (isset($response['success']) && $response['success']) {
                // Check if response has items array
                if (isset($response['response']['items']) && is_array($response['response']['items'])) {
                    $numberOfResults = $response['response']['numberOfResults'] ?? count($response['response']['items']);
                    
                    // If numberOfResults is 0 or items array is empty, SKUs are not available on Amazon - skip them
                    if ($numberOfResults == 0 || empty($response['response']['items'])) {
                        // SKUs not found on Amazon - skip this batch
                        continue;
                    }
                    
                    // Process each item in the response
                    foreach ($response['response']['items'] as $item) {
                        if (isset($item['sku'])) {
                            $itemSku = $item['sku'];
                            
                            // Check if this SKU is in our map
                            if (isset($productDataMap[$itemSku])) {
                                $sourceProductId = $productDataMap[$itemSku]['source_product_id'];
                                $containerId = $productDataMap[$itemSku]['container_id'];
                                
                                // Extract Amazon status from summaries
                                $amazonStatus = null;
                                if (isset($item['summaries']) && is_array($item['summaries']) && !empty($item['summaries'])) {
                                    if (isset($item['summaries'][0]['status']) && is_array($item['summaries'][0]['status'])) {
                                        // Status is an array, join with comma
                                        $amazonStatus = implode(',', $item['summaries'][0]['status']);
                                    }
                                }
                                
                                // Store amazon status if found
                                if ($amazonStatus !== null) {
                                    $amazonStatuses[$sourceProductId] = $amazonStatus;
                                }
                                
                                // Check if item has issues
                                if (isset($item['issues']) && !empty($item['issues'])) {
                                    $errors[$sourceProductId] = (string) $containerId;
                                    $err[$sourceProductId] = $item['issues'];
                                } else {
                                    // No issues found - mark as uploaded
                                    if(isset($productStatus[$sourceProductId]) && !in_array($productStatus[$sourceProductId], ['Active', 'Inactive','Incomplete','Not Listed:Offer'])) {
                                        // No issues found - mark as uploaded
                                        $uploadedVariant[$sourceProductId] = (string) $containerId;
                                    }
                                }
                            }
                        }
                    }
                } else {
                    // Handle single item response (backward compatibility)
                    if (isset($response['response']['issues']) && !empty($response['response']['issues'])) {
                        // If only one SKU was sent, map the issues to that SKU
                        if (count($skuBatch) == 1) {
                            $sku = $skuBatch[0];
                            if (isset($productDataMap[$sku])) {
                                $sourceProductId = $productDataMap[$sku]['source_product_id'];
                                $containerId = $productDataMap[$sku]['container_id'];
                                $errors[$sourceProductId] = (string) $containerId;
                                $err[$sourceProductId] = $response['response']['issues'];
                            }
                        }
                    } else {
                        // No issues - mark all SKUs in batch as uploaded
                        foreach ($skuBatch as $sku) {
                            if (isset($productDataMap[$sku])) {
                                $sourceProductId = $productDataMap[$sku]['source_product_id'];
                                $containerId = $productDataMap[$sku]['container_id'];
                                $uploadedVariant[$sourceProductId] = (string) $containerId;
                            }
                        }
                    }
                }
            } elseif (isset($response['success']) && $response['success'] == false && isset($response['code']) && $response['code'] == '404') {
                $this->di->getLog()->logContent('ErrorRefresh: batch 404 - SKUs not found on Amazon', 'info', $logFile);
                continue;
            } else {
                $this->di->getLog()->logContent('ErrorRefresh: API error for batch, response: ' . json_encode($response['message'] ?? $response['error'] ?? 'unknown'), 'info', $logFile);
            }
        }

        $this->di->getLog()->logContent('ErrorRefresh: results - ' . count($uploadedVariant) . ' uploaded, ' . count($errors) . ' with errors, ' . count($amazonStatuses) . ' amazonStatus updates', 'info', $logFile);
        

        // print_r($uploadedVariant);die;
        $updateDataInsert = [];
        foreach ($uploadedVariant as $variant =>  $container_id) {

            // $updateData = [
            //     "source_product_id" => (string) $variant,
            //     // required
            //     'user_id' => $userId,
            //     'source_shop_id' => $sourceShopId,
            //     'childInfo' => [
            //         'source_product_id' => (string) $variant,
            //         // required
            //         'shop_id' => $homeShopId,
            //         // required
            //         'status' => \App\Amazon\Components\Common\Helper::PRODUCT_STATUS_UPLOADED,
            //         'target_marketplace' => 'amazon',
            //         // required
            //     ],
            // ];
            $updateData = [
                "source_product_id" => (string)$variant,
                // required
                'user_id' => $userId,
                'source_shop_id' => (string) $sourceShopId,
                'target_marketplace' => 'amazon',
                'status' => Helper::PRODUCT_STATUS_UPLOADED,
                'shop_id' => (string)$homeShopId,
                'container_id' => $container_id,
                'last_synced_at' => date('c')
            ];
            
            // Add amazonStatus if available
            if (isset($amazonStatuses[$variant])) {
                $updateData['amazonStatus'] = $amazonStatuses[$variant];
            }
            
            array_push($updateDataInsert, $updateData);
        }
        foreach ($errors as $variant => $container_id) {
            if (isset($err[$variant])) {

                $errorHelper = $this->di->getObjectManager()->get(Error::class);
                $formattedErrors = $errorHelper->formateErrorMessages($err[$variant]);
                $updateData = [
                    "source_product_id" => (string)$variant,
                    // required
                    'user_id' => $userId,
                    'source_shop_id' => (string) $sourceShopId,
                    'target_marketplace' => 'amazon',
                    // 'status' => Helper::PRODUCT_STATUS_UPLOADED,
                    'shop_id' => (string)$homeShopId,
                    'container_id' => $container_id
                ];
                
                // Add amazonStatus if available
                if (isset($amazonStatuses[$variant])) {
                    $updateData['amazonStatus'] = $amazonStatuses[$variant];
                }
                
                if (isset($formattedErrors['error']) && !empty($formattedErrors['error'])) {
                    $updateData['error'] = $formattedErrors['error'];
                    $updateData['unset'] = ['process_tags' => 1];
                }

                if (isset($formattedErrors['warning']) && !empty($formattedErrors['warning'])) {
                    $updateData['warning'] = $formattedErrors['warning'];
                }
                array_push($updateDataInsert, $updateData);
            }
        }

        if (!empty($updateDataInsert)) {
            $appTag = $this->di->getAppCode()->getAppTag();
            if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
                /* Trigger websocket (if configured) */
                $websocketConfig = $this->di->getConfig()->get("app_tags")
                    ->get($appTag)
                    ->get('websocket');
                if (
                    isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['notification']) &&
                    $websocketConfig['client_id'] &&
                    $websocketConfig['allowed_types']['notification']
                ) {

                    $helper = $this->di->getObjectManager()->get(ConnectorHelper::class);
                    $helper->handleMessage([
                        'user_id' => $userId,
                        'notification' => $updateDataInsert
                    ]);
                }

                /* Trigger websocket (if configured) */
            }

            $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
            $res = $editHelper->saveProduct($updateDataInsert, $userId, $additionalData);

            // Update amazonlisting collection with amazonStatus
            if (!empty($amazonStatuses)) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $amazonListingCollection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);
                $amazonListingBulkOps = [];
                
                // Build SKU to source_product_id map from productDataMap
                foreach ($amazonStatuses as $sourceProductId => $amazonStatus) {
                    // Find the SKU for this source_product_id
                    foreach ($productDataMap as $sku => $data) {
                        if ($data['source_product_id'] == $sourceProductId) {
                            // Update amazonlisting with amazonStatus
                            $amazonListingBulkOps[] = [
                                'updateOne' => [
                                    [
                                        'seller-sku' => $sku,
                                        'shop_id' => (string) $homeShopId,
                                        'user_id' => $userId
                                    ],
                                    [
                                        '$set' => [
                                            'amazonStatus' => $amazonStatus,
                                            'updated_at' => date('c')
                                        ]
                                    ],
                                    ['upsert' => false]
                                ]
                            ];
                            break;
                        }
                    }
                }
                
                // Execute bulk update for amazonlisting
                if (!empty($amazonListingBulkOps)) {
                    $amazonListingCollection->BulkWrite($amazonListingBulkOps, ['w' => 1]);
                }
            }

            // $res = $marketplaceHelper->marketplaceSaveAndUpdate($updateDataInsert);
        }
        $this->di->getLog()->logContent('ErrorRefresh: complete', 'info', $logFile);
        return false;
    }
}
