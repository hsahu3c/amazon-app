<?php

namespace App\Amazon\Components\Product;

use App\Amazon\Components\Cron\Route\Requestcontrol;
use Exception;
use App\Amazon\Components\Template\Category;
use App\Amazon\Components\Common\Barcode;
use App\Connector\Models\ProductContainer;
use App\Core\Components\Base;

class Upload extends Base
{

    private ?string $_user_id = null;


    private $_baseMongo;


    private array $categoryAttributes = [];

    public const OPERATION_TYPE_UPDATE = 'Update';

    public const OPERATION_TYPE_DELETE = 'Delete';

    public const OPERATION_TYPE_PARTIAL_UPDATE = 'PartialUpdate';

    public const PRODUCT_ERROR_VALID = 'valid';

    public const PRODUCT_TYPE_PARENT = 'parent';

    public const PRODUCT_TYPE_CHILD = 'child';

    public const DEFAULT_MAPPING = [
        'item_sku' => 'sku',
        'sku' => 'sku',
        'brand_name' => 'brand_name',
        'item_name' => 'title',
        'standard_price' => 'price',
//        'maximum_retail_price' => 'price',
        'price' => 'price',
        'quantity' => 'quantity',
        'main_image_url' => 'main_image',
        'product-id' => 'barcode',
        'external_product_id' => 'barcode',
        'product_description' => 'description'
    ];

    public const IMAGE_ATTRIBUTES = [
        0 => 'other_image_url1',
        1 => 'other_image_url2',
        2 => 'other_image_url3',
        3 => 'other_image_url4',
        4 => 'other_image_url5',
        5 => 'other_image_url6',
        6 => 'other_image_url7',
        7 => 'other_image_url8'
    ];

    public function init($request = [])
    {
        if (isset($request['user_id'])) {
            $this->_user_id = (string)$request['user_id'];
        } else {
            $this->_user_id = (string)$this->di->getUser()->id;
        }
        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        return $this;
    }

    public function execute($data)
    {
        $source_marketplace = $data['source_marketplace']['marketplace'];
        $target_marketplace = $data['target_marketplace']['marketplace'];
        $accounts = $data['target_marketplace']['shop_id'];

        if ($target_marketplace == '' || $source_marketplace == '' || empty($accounts)) {
            return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
        }

        $productContainerCollection = $this->_baseMongo->getCollection(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

        $containerIds = [];
        foreach ($data['container_id'] as $containerId => $sourceProductIds) {
            $containerIds[] = $containerId;
            if (isset($data['source_product_id'])) {
                $data['source_product_id'] = array_merge($data['source_product_id'], $sourceProductIds);
            } else {
                $data['source_product_id'] = $sourceProductIds;
            }
        }

        $sourceProductIds = [];
        if (isset($data['container_id']) && !empty($data['container_id'])) {
            if (isset($data['source_product_id']) && !empty($data['source_product_id'])) {
                $query = [
                    'user_id' => (string)$this->_user_id,
                    'type' => ProductContainer::PRODUCT_TYPE_SIMPLE,
                    'container_id' => [
                        '$in' => $containerIds
                    ],
                    'source_product_id' => [
                        '$in' => $data['source_product_id']
                    ],
                    'source_marketplace' => $source_marketplace
                ];
            } else {
                $query = [
                    'user_id' => (string)$this->_user_id,
                    'type' => ProductContainer::PRODUCT_TYPE_SIMPLE,
                    'container_id' => [
                        '$in' => $containerIds
                    ],
                    'source_marketplace' => $source_marketplace
                ];
            }

            $sourceProductIdsArray = $productContainerCollection->find(
                $query,
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => ['source_product_id' => 1],
                ]
            )->toArray();
            if (!empty($sourceProductIdsArray)) {
                $sourceProductIds = array_column($sourceProductIdsArray, 'source_product_id');
            }
        }

        $shops = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class)->getAllCurrentAmazonShops();
        if (empty($shops)) {
            $shops = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class)->getAllAmazonShops();
        }

        if (!empty($shops)) {
            $returnArray = [];
            $errorArray = [];
            $successArray = [];

            foreach ($shops as $shop) {
                if (!in_array($shop['_id'], $accounts)) {
                    continue;
                }

                $marketplace_id = $shop['warehouses'][0]['marketplace_id'];
                if (isset($shop['warehouses'][0]['status']) && $shop['warehouses'][0]['status'] == 'active') {

                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => Requestcontrol::class,
                        'method' => 'processProductUpload',
                        'queue_name' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getProductUploadQueueName(),
                        'user_id' => $this->_user_id,
                        'data' => [
                            'home_shop_id' => $shop['_id'],
                            'remote_shop_id' => $shop['remote_shop_id'],
                            'source_marketplace' => $source_marketplace,
                            'target_marketplace' => $target_marketplace,
                            'source_shop_id' => $data['source_marketplace']['shop_id'],
                            'target_shop_id' => $shop['_id'],
                            'source_product_id' => $sourceProductIds,
//                            'operation_type' => $data['operation_type'] ?? self::OPERATION_TYPE_UPDATE,
                            'operation_type' => self::OPERATION_TYPE_UPDATE,
                            'shop' => $shop
                        ],
                    ];

                    $this->addMessageInQueue($handlerData);
                    $successArray[$marketplace_id] = 'Product upload is in-progress please wait for a while.';

                } else {
                    $errorArray[$marketplace_id] = $marketplace_id . ' Inactive marketplace id';
                }
            }
            foreach ($successArray as $marketplace_id => $success) {
                $returnArray[$marketplace_id] = ['success' => true, 'message' => $success];
            }
            foreach ($errorArray as $marketplace_id => $error) {
                $returnArray[$marketplace_id] = ['success' => false, 'message' => $error];
            }

            return $returnArray;

        }
        return ['success' => false, 'message' => 'Shops not found'];
    }

    public function addMessageInQueue($queueData)
    {
        $response = $this->di->getMessageManager()->pushMessage($queueData);
        return $response;
    }

    public function upload($data)
    {
        try {
            $sqsData = $data['data'];

            $params = [];
            $params['source_marketplace']['marketplace'] = $sqsData['source_marketplace'];
            $params['target_marketplace']['marketplace'] = $sqsData['target_marketplace'];
            $params['target_marketplace']['shop_id'] = $sqsData['target_shop_id'];
            $params['source_marketplace']['shop_id'] = $sqsData['source_shop_id'];
            $params['source_product_id'] = $sqsData['source_product_id'];
            $feedContent = [];

            $homeShopId = $sqsData['home_shop_id'];
            $remoteShopId = $sqsData['remote_shop_id'];
            $shop = $sqsData['shop'];
            $objectManager = $this->di->getObjectManager();
            $helper = $objectManager->get('\App\Connector\Models\Product\Index');
            $mergedData = $helper->getProducts($params);
            $productErrorList = [];
            $productSuccessList = [];
            if (!empty($mergedData)) {
                if (isset($mergedData['data']['rows'])) {
                    foreach ($mergedData['data']['rows'] as $product) {


                        if ($product['type'] == ProductContainer::PRODUCT_TYPE_SIMPLE && $product['visibility'] == 'Catalog and Search') {
                            $categorySettings = $this->getCategorySettings($product, []);

                            if ($categorySettings) {

                                $preparedContent = $this->setContent($categorySettings, $product, $homeShopId, false, false);

                                if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
                                    $productErrorList[$product['source_product_id']] = $preparedContent['error'];
                                } else {
                                    unset($preparedContent['error']);

                                    $parentCategory = $preparedContent['category'];
                                    $subCategory = $preparedContent['sub_category'];

                                    $feedContent[$parentCategory][$subCategory]['products'][$product['source_product_id']] = $preparedContent;
                                    $productSuccessList[$product['source_product_id']] = $product['source_product_id'];
                                }
                            }

                        } else {

                            $params = [
                                "source_product_id" => $product['container_id'],
                                'user_id' => (string)$this->di->getUser()->id,
                                'shop_id' => $shop['_id']
                            ];

                            $objectManager = $this->di->getObjectManager();
                            $helper = $objectManager->get('\App\Connector\Models\Product\Marketplace');
                            $parentProduct = $helper->getSingleProduct($params);

                            if ($parentProduct) {
                                if (isset($product['different_parent_sku']) && $product['different_parent_sku'] != '') {
                                    $productTypeParentSku = $product['different_parent_sku'];
                                } elseif (isset($product['parent_sku']) && $product['parent_sku']) {
                                    $productTypeParentSku = $product['parent_sku'];
                                } else {
                                    $productTypeParentSku = $product['container_id'];
                                }

                                $categorySettings = $this->getCategorySettings($parentProduct, $product);

                                if ($categorySettings) {
                                    unset($parentProduct['quantity']);
                                    unset($parentProduct['price']);

                                    $preparedContent = $this->setContent($categorySettings, $parentProduct, $homeShopId, self::PRODUCT_TYPE_PARENT, $productTypeParentSku);

                                    if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
                                        $productErrorList[$product['source_product_id']] = $preparedContent['error'];
                                    } else {
                                        unset($preparedContent['error']);

                                        $parentCategory = $preparedContent['category'];
                                        $subCategory = $preparedContent['sub_category'];

                                        $feedContent[$parentCategory][$subCategory]['products'][$parentProduct['source_product_id']] = $preparedContent;
                                        $productSuccessList[$parentProduct['source_product_id']] = $parentProduct['source_product_id'];

                                    }
                                }
                            }

                            $categorySettings = $this->getCategorySettings($product, []);

                            if ($categorySettings) {

                                $preparedContent = $this->setContent($categorySettings, $product, $homeShopId, self::PRODUCT_TYPE_CHILD, $productTypeParentSku);

                                if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
                                    $productErrorList[$product['source_product_id']] = $preparedContent['error'];
                                } else {
                                    unset($preparedContent['error']);

                                    $parentCategory = $preparedContent['category'];
                                    $subCategory = $preparedContent['sub_category'];

                                    $feedContent[$parentCategory][$subCategory]['products'][$product['source_product_id']] = $preparedContent;
                                    $productSuccessList[$product['source_product_id']] = $product['source_product_id'];

                                }
                            }


                        }
                    }

                    if (!empty($feedContent)) {
                        $specifics = [
                            'home_shop_id' => $homeShopId,
                            'marketplace_id' => $shop['warehouses'][0]['marketplace_id'],
                            'shop_id' => $remoteShopId,
                            'feedContent' => base64_encode(serialize($feedContent))
                        ];

                        $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                        $response = $commonHelper->sendRequestToAmazon('product-upload', $specifics, 'POST');

//                        $response = json_decode('{"success":true,"data":{"home_shop_id":"34","marketplace_id":"A21TJRUUN4KGV","shop_id":"39","feedContent":"YToxOntzOjQ6ImJhYnkiO2E6MTp7czo4OiJwYWNpZmllciI7YToxOntzOjg6InByb2R1Y3RzIjthOjE6e3M6MTA6InZGa3A0RnRjQ0MiO2E6MzA6e3M6MTc6ImZlZWRfcHJvZHVjdF90eXBlIjtzOjg6InBhY2lmaWVyIjtzOjg6Iml0ZW1fc2t1IjtzOjExOiJ0ZXN0ZHVwYmFyMyI7czoxMDoiYnJhbmRfbmFtZSI7czowOiIiO3M6OToiaXRlbV9uYW1lIjtzOjIyOiJUZXN0aW5nIFNpbXBsZSBQcm9kdWN0IjtzOjEyOiJtYW51ZmFjdHVyZXIiO3M6NToidGl0bGUiO3M6MTE6InBhcnRfbnVtYmVyIjtzOjM6InNrdSI7czoyNDoicmVjb21tZW5kZWRfYnJvd3NlX25vZGVzIjtpOjA7czoxMDoiY29sb3JfbmFtZSI7czozOiJSZWQiO3M6OToiY29sb3JfbWFwIjtzOjU6IkJsYWNrIjtzOjExOiJtZmdfbWluaW11bSI7czoyOiIxMCI7czoyNzoibWZnX21pbmltdW1fdW5pdF9vZl9tZWFzdXJlIjtzOjY6Ik1vbnRocyI7czoxMToibWZnX21heGltdW0iO3M6MjoiMTAiO3M6Mjc6Im1mZ19tYXhpbXVtX3VuaXRfb2ZfbWVhc3VyZSI7czo2OiJNb250aHMiO3M6MTE6Iml0ZW1fbGVuZ3RoIjtzOjI6IjMwIjtzOjExOiJpdGVtX2hlaWdodCI7czoyOiIyMCI7czoxMDoiaXRlbV93aWR0aCI7czoyOiIzMCI7czoxMDoidW5pdF9jb3VudCI7czoxOiI1IjtzOjE1OiJ1bml0X2NvdW50X3R5cGUiO3M6NToiT3VuY2UiO3M6MTc6ImNvdW50cnlfb2Zfb3JpZ2luIjtzOjU6IkluZGlhIjtzOjI4OiJleHRlcm5hbF9wcm9kdWN0X2luZm9ybWF0aW9uIjtzOjk6Ijg1Njk4NTY0NyI7czoxNzoiaXNfaGVhdF9zZW5zaXRpdmUiO3M6MzoiWWVzIjtzOjE0OiJzdGFuZGFyZF9wcmljZSI7aTo2MDtzOjg6InF1YW50aXR5IjtpOjA7czoyMDoibWF4aW11bV9yZXRhaWxfcHJpY2UiO3M6NToicHJpY2UiO3M6Mjc6ImlzX2V4cGlyYXRpb25fZGF0ZWRfcHJvZHVjdCI7czozOiJZZXMiO3M6MTQ6Im1haW5faW1hZ2VfdXJsIjtzOjA6IiI7czoxOToicHJvZHVjdF9kZXNjcmlwdGlvbiI7czozMToiVGVzdCBTaW1wbGUgUHJvZHVjdCBEZXNjcmlwdGlvbiI7czoxNzoiYmFyY29kZV9leGVtcHRpb24iO2I6MTtzOjg6ImNhdGVnb3J5IjtzOjQ6ImJhYnkiO3M6MTI6InN1Yl9jYXRlZ29yeSI7czo4OiJwYWNpZmllciI7fX19fX0="},"result":{"A21TJRUUN4KGV":{"success":true,"message":"data successfully saved in s3 bucket","response":{}}},"start_time":"03-23-2022 11:31:45","end_time":"03-23-2022 11:31:46","execution_time":1.5099399089813232,"ip":"127.0.0.1"}', true);

                        if (isset($response['success']) && $response['success']) {
                            if (!empty($response['result'])) {
                                foreach ($response['result'] as $result) {
                                    if (isset($result['success']) && $result['success']) {
                                        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');

                                        foreach ($productSuccessList as $source_product_id) {
                                            $updateData = [
                                                "source_product_id" => $source_product_id, // required
                                                'user_id' => $this->_user_id,
                                                'childInfo' => [
                                                    'source_product_id' => $source_product_id, // required
                                                    'shop_id' => $shop['_id'],// required
                                                    'status' => Helper::PRODUCT_STATUS_LISTING_IN_PROGRESS,
                                                    'target_marketplace' => 'amazon', // required
                                                    'seller_id' => $shop['warehouses'][0]['seller_id']
                                                ]
                                            ];

                                            $helper->marketplaceSaveAndUpdate($updateData);
                                        }
                                    }
                                }
                            }

                            $success = true;
                            $message = 'Product upload is in progress';
                        } else {
                            $success = false;
                            $message = 'Error from remote';
                        }
                    }

                } else {
                    $success = false;
                    $message = 'Product doesn\'t exist';

                    /*foreach ($productSuccessList as $source_product_id) {
                        $updateData = [
                            "source_product_id" => $source_product_id, // required
                            'user_id' => $this->_user_id,
                            'childInfo' => [
                                'source_product_id' => $source_product_id, // required
                                'shop_id' => $shop['_id'],// required
                                'status' => Helper::PRODUCT_STATUS_LISTING_IN_PROGRESS,
                                'target_marketplace' => 'amazon', // required
                                'seller_id' => $shop['warehouses'][0]['seller_id']
                            ]
                        ];

                        $helper->marketplaceSaveAndUpdate($updateData);
                    }*/
                }

            } else {
                $success = false;
                $message = 'Product doesn\'t exist';
            }
        } catch (Exception $e) {
            $success = false;
            $message = $e->getMessage();
        }

        return ['success' => $success, 'message' => $message];

    }

    public function getCategorySettings($product, $child)
    {
        $categorySettings = false;
        if ($product && isset($product['shops'])) {

            $shop = [$product['shops']];

            foreach ($shop as $value) {

                if (is_array($value) && array_key_exists('category_settings', $value)) {

                    if (isset($value['category_settings']['attributes_mapping'])) {

                        $categorySettings = $value['category_settings'];
                        if (isset($value['optional_attributes'])) {
                            $categorySettings['optional_variant_attributes'] = $value['optional_attributes'];
                        }

                        if (isset($value['variation_theme_attribute_name'])) {
                            $categorySettings['variation_theme_attribute_name'] = $value['variation_theme_attribute_name'];
                        }

                        if (isset($value['variation_theme_attribute_value'])) {
                            $categorySettings['variation_theme_attribute_value'] = $value['variation_theme_attribute_value'];
                        }
                    }
                }
            }
        }

        if (!$categorySettings && !empty($product)) {

            if ($child && isset($child['shops'])) {
                $shop = [$child['shops']];
                foreach ($shop as $value) {
                    if (is_array($value) && array_key_exists('category_settings', $value)) {
                        if (isset($value['category_settings']['attributes_mapping'])) {
                            $categorySettings = $value['category_settings'];
                            if (isset($value['optional_attributes'])) {
                                $categorySettings['optional_variant_attributes'] = $value['optional_attributes'];
                            }

                            if (isset($value['variation_theme_attribute_name'])) {
                                $categorySettings['variation_theme_attribute_name'] = $value['variation_theme_attribute_name'];
                            }

                            if (isset($value['variation_theme_attribute_value'])) {
                                $categorySettings['variation_theme_attribute_value'] = $value['variation_theme_attribute_value'];
                            }
                        }
                    }
                }
            }
        }

        return $categorySettings;
    }

    /**
     * This function is used to prepare the data of products
     *
     * @param $categorySettings
     * @param $product
     * @param $homeShopId
     * @param $type
     * @param $parentSku
     * @return array
     */
    public function setContent($categorySettings, $product, $homeShopId, $type, $parentSku)
    {

        if (isset($product['source_marketplace'])) {
            $sourceMarketplace = $product['source_marketplace'];
        } else {
            return ['success' => false, 'message' => 'source marketplace not found'];
        }

        $feedContent = [];
        if (!empty($categorySettings)) {
            $barcodeExemption = $categorySettings['barcode_exemption'] ?? '';
            $subCategory = $categorySettings['sub_category'];
            $category = $categorySettings['primary_category'];
            $requiredAttributes = $categorySettings['attributes_mapping']['required_attribute'] ?? [];
            $optionalAttributes = $categorySettings['attributes_mapping']['optional_attribute'] ?? [];
            $browseNodeId = $categorySettings['browser_node_id'];

            $attributes = [...(array)$requiredAttributes, ...(array)$optionalAttributes];

            $variant_attributes = [];

            if (isset($categorySettings['optional_variant_attributes'])) {
                $variant_attributes = array_merge($variant_attributes, $categorySettings['optional_variant_attributes']);
            }

            if (isset($categorySettings['variation_theme_attribute_name']) && $categorySettings['variation_theme_attribute_name']) {
                $variant_attributes['variation_theme'] = $categorySettings['variation_theme_attribute_name'];
            }

            if (isset($categorySettings['variation_theme_attribute_value']) && $categorySettings['variation_theme_attribute_value']) {
                $variant_attributes = array_merge($variant_attributes, $categorySettings['variation_theme_attribute_value']);
            }

            if ($type == self::PRODUCT_TYPE_PARENT && $category == 'default') {
                return $feedContent;
            }

            $categoryContainer = $this->di->getObjectManager()->get(Category::class);

            $params = [
                'shop_id' => $homeShopId,
                'category' => $category,
                'sub_category' => $subCategory,
                'browser_node_id' => $browseNodeId,
                'barcode_exemption' => $barcodeExemption,
                'browse_node_id' => $browseNodeId
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
            $images = $product['additional_images'];
            $defaultMapping = self::DEFAULT_MAPPING;

            if (!empty($mappedAttributeArray) && !empty($attributeArray)) {

                foreach ($attributeArray as $id => $attribute) {

                    $amazonAttribute = $id;

                    $sourceMarketplaceAttribute = false;

                    if (isset($defaultMapping[$id])) {
                        $sourceMarketplaceAttribute = $defaultMapping[$id];
                    } elseif (($type == self::PRODUCT_TYPE_CHILD || $id == 'variation_theme') && isset($variant_attributes[$id])) {
                        $sourceMarketplaceAttribute = 'variant_custom';
                    } elseif (isset($mappedAttributeArray[$id])) {
                        $sourceMarketplaceAttribute = $mappedAttributeArray[$id][$sourceMarketplace . '_select'];
                    } elseif (isset($attributeArray[$id]['premapped_values']) && !empty($attributeArray[$id]['premapped_values'])) {
                        $sourceMarketplaceAttribute = 'premapped_values';
                    }

                    if ($id == 'brand_name' && isset($mappedAttributeArray[$id])) {
                        $sourceMarketplaceAttribute = $mappedAttributeArray[$id][$sourceMarketplace . '_select'];
                    }

                    if ($id == 'product_description' && isset($mappedAttributeArray[$id])) {
                        $sourceMarketplaceAttribute = $mappedAttributeArray[$id][$sourceMarketplace . '_select'];
                    }

                    $value = '';
                    if ($sourceMarketplaceAttribute) {
                        if ($sourceMarketplaceAttribute == 'recommendation') {
                            $recommendation = $mappedAttributeArray[$id]['recommendation'];

                            if ($recommendation == 'custom') {
                                $customText = $mappedAttributeArray[$id]['custom_text'];
                                $value = $customText;
                            } else {
                                $value = $recommendation;
                            }
                        } elseif ($sourceMarketplaceAttribute == 'custom') {
                            $customText = $mappedAttributeArray[$id]['custom_text'];
                            $value = $customText;
                        } elseif ($sourceMarketplaceAttribute == 'Search') {

                            $customText = $mappedAttributeArray[$id][$sourceMarketplace . '_attribute'];
                            ($customText) ? $value = $customText : $value = $mappedAttributeArray[$id]['custom_text'];


                        } elseif (isset($product[$sourceMarketplaceAttribute])) {
                            $value = $product[$sourceMarketplaceAttribute];
                        } elseif ($sourceMarketplaceAttribute == 'variant_custom') {
                            $value = $variant_attributes[$id];
                        } elseif ($sourceMarketplaceAttribute == "premapped_values") {
                            $value = $attributeArray[$id]['premapped_values'];
                        }

                        if ($sourceMarketplaceAttribute == 'sku' && $type == self::PRODUCT_TYPE_PARENT && $parentSku) {
                            $value = $parentSku;
                        }

                        if ($id == 'main_image_url' && $type == self::PRODUCT_TYPE_CHILD && isset($product['variant_image'])) {
                            $value = $product['variant_image'];
                        }

                        if ($sourceMarketplaceAttribute == 'sku' && $type != self::PRODUCT_TYPE_PARENT) {

                            if ($product && isset($product[$sourceMarketplaceAttribute]) && $product[$sourceMarketplaceAttribute]) {
                                $value = $product[$sourceMarketplaceAttribute];
                            } elseif ($product && isset($product[$sourceMarketplaceAttribute]) && isset($product[$sourceMarketplaceAttribute])) {
                                $value = $product[$sourceMarketplaceAttribute];
                            } elseif (isset($product['source_product_id'])) {
                                $value = $product['source_product_id'];
                            }
                        }

                        if ($sourceMarketplaceAttribute == 'barcode' && $product && isset($product[$sourceMarketplaceAttribute])) {
                            $value = $product[$sourceMarketplaceAttribute];
                        }

                        if ($sourceMarketplaceAttribute == 'brand' && $product && isset($product[$sourceMarketplaceAttribute])) {
                            $value = $product[$sourceMarketplaceAttribute];
                        }

                        if ($sourceMarketplaceAttribute == 'barcode') {
                            $value = str_replace("-", "", $value);
                        }

                        if ($sourceMarketplaceAttribute == 'description' && $product && isset($product[$sourceMarketplaceAttribute])) {
                            $value = $product[$sourceMarketplaceAttribute];
                        }

                        if ($sourceMarketplaceAttribute == 'title' && $product && isset($product[$sourceMarketplaceAttribute])) {
                            $value = $product[$sourceMarketplaceAttribute];
                        }

                        if ($sourceMarketplaceAttribute == 'price' && $type != self::PRODUCT_TYPE_PARENT) {
                            $value = $product[$sourceMarketplaceAttribute];
                        }


                        if ($sourceMarketplaceAttribute == 'quantity' && $type != self::PRODUCT_TYPE_PARENT) {
                            $value = $product[$sourceMarketplaceAttribute];
                        }


                        if ($sourceMarketplaceAttribute == 'description') {

                            $value = str_replace(["\n", "\r"], ' ', $value);
                            $tag = 'span';
                            $value = preg_replace('#</?' . $tag . '[^>]*>#is', '', $value);

                            $value = strip_tags($value, '<p></p><ul></ul><li></li>');
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


                        if ($sourceMarketplaceAttribute == 'weight') {
                            if ((float)$value > 0) {
                                $value = number_format((float)$value, 2);
                            } else {
                                $value = '';
                            }
                        }

                        if ($id == 'item_name' && isset($product['variant_title']) && isset($product['group_id'])) {
                            $value = $value . ' ' . $product['variant_title'];
                        }

                        if ($id == 'fulfillment_center_id' && $type == self::PRODUCT_TYPE_PARENT) {
                            $value = '';
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

                        if ($feedContent[$amazonAttribute] == 'shirt') {
                            $feedContent[$amazonAttribute] = 'SHIRT';
                        }
                    }

                    if (in_array($amazonAttribute, self::IMAGE_ATTRIBUTES)) {
                        $imageKey = array_search($amazonAttribute, self::IMAGE_ATTRIBUTES);
                        if (isset($images[$imageKey])) {
                            $feedContent[$amazonAttribute] = $images[$imageKey];
                        }
                    }

                    if ($amazonAttribute == 'recommended_browse_nodes' || $amazonAttribute == 'recommended_browse_nodes1') {
                        $feedContent[$amazonAttribute] = $browseNodeId;

                    }

                    if ($amazonAttribute == 'parent_child' && ($type == self::PRODUCT_TYPE_PARENT || $type == self::PRODUCT_TYPE_CHILD)) {
                        $feedContent[$amazonAttribute] = $type;
                    }

                    if ($amazonAttribute == 'relationship_type' && $type == self::PRODUCT_TYPE_CHILD) {
                        $feedContent[$amazonAttribute] = 'variation';
                    }

                    if ($amazonAttribute == 'ASIN-hint' && isset($product['asin'])) {
                        $feedContent[$amazonAttribute] = $product['asin'];
                    }

                    if ($amazonAttribute == 'operation-type' || $amazonAttribute == 'update_delete') {
                        $feedContent[$amazonAttribute] = self::OPERATION_TYPE_UPDATE;
                    }

                    if ($amazonAttribute == 'sale-start-date' && isset($product['sale-start-date'])) {
                        $feedContent[$amazonAttribute] = $product['sale-start-date'];
                    }

                    if ($amazonAttribute == 'sale-end-date' && isset($product['sale-end-date'])) {
                        $feedContent[$amazonAttribute] = $product['sale-end-date'];
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

                $feedContent = $this->validate($feedContent, $product, $attributeArray, $params);
            }
        }

        return $feedContent;
    }

    /**
     * This function is used to validate the prepared product data
     *
     * @param $feedContent
     * @param $product
     * @param $attributes
     * @param $categoryParams
     * @return mixed
     */
    public function validate($feedContent, $product, $attributes, $categoryParams)
    {
        $error = [];
        $skuAttribute = 'sku';
        if (isset($attributes['sku'])) {
            $skuAttribute = 'sku';
        } elseif ($attributes['item_sku']) {
            $skuAttribute = 'item_sku';
        }

        if (!isset($feedContent[$skuAttribute]) || empty($feedContent[$skuAttribute])) {
            $error[] = 'Sku is a required field, please fill sku and upload again.';
        }

        if ($product['type'] != 'variation') {

            $priceAttribute = 'price';
            if (isset($feedContent['price'])) {
                $priceAttribute = 'price';
            } elseif (isset($feedContent['standard_price'])) {
                $priceAttribute = 'standard_price';
            }

            if (!isset($feedContent[$priceAttribute])) {
                $error[] = 'Price is a required field, please fill price and upload again.';
            }

            if (isset($feedContent[$priceAttribute]) && ($feedContent[$priceAttribute] === 0 || $feedContent[$priceAttribute] == '0')) {
                $error[] = 'Price should be greater that 0.01, please update it from listing page.';
            }

            if (isset($feedContent[$priceAttribute]) && $feedContent[$priceAttribute] === false) {
                $error[] = 'Price syncing is disabled, please enable it from assigned template.';
            }

            if (!isset($feedContent['quantity'])) {
                $error[] = 'Quantity is a required field, please fill quantity and upload again.';
            }

            if (!$categoryParams['barcode_exemption'] || $categoryParams['category'] == 'default') {

                $barcodeAttribute = 'product-id';
                if (isset($attributes['product-id'])) {
                    $barcodeAttribute = 'product-id';
                } elseif (isset($attributes['external_product_id'])) {
                    $barcodeAttribute = 'external_product_id';
                }

                if (!isset($feedContent[$barcodeAttribute]) || empty($feedContent[$barcodeAttribute])) {
                    $error[] = 'Barcode is a required field, please fill barcode and upload again.';
                } else {
                    $barcode = $this->di->getObjectManager()->get(Barcode::class);
                    $type = $barcode->setBarcode($feedContent[$barcodeAttribute]);

                    if (!$type) {
                        $error[] = 'Barcode is not valid, please provide valid barcode and upload again.';
                    }
                }
            }
        }

        if (!empty($error)) {
            $feedContent['error'] = $error;
        }

        return $feedContent;
    }

    public function setReadyToUpload($saveProfileData): void
    {
        $params =[];
        $updateProfileData= $saveProfileData['updated_profile_data'] ?? [];
        $shopDetails = $saveProfileData['shop_details'] ?? [];
        $oldProfileIds = [];

        //for advance search
        $querySearch['target']['shopId'] = (string)$shopDetails['target']['shopId'] ?? "";
        $querySearch['source']['shopId'] = (string)$shopDetails['source']['shopId'] ?? "";
        $querySearch['target']['marketplace'] = $shopDetails['target']['marketplace'] ?? "";
        $querySearch['source']['marketplace'] = $shopDetails['source']['marketplace'] ?? "";
        $querySearch['user_id'] = $this->di->getUser()->id;
        $querySearch['activePage'] = 1;
        $querySearch['limit'] = 1000;

        //for manual search
        $productQuery = [
            'user_id' => $this->di->getUser()->id,
            'targetShopID' => (string)$shopDetails['target']['shopId'] ?? "",
            'target_marketplace' => $shopDetails['target']['marketplace'] ?? "",
            'source_marketplace' => $shopDetails['source']['marketplace'] ?? "",
            'sourceShopID' => (string)$shopDetails['source']['shopId'] ?? ""
        ];

        //code to check if template has old profile data
        $productEditModel = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
        if(!empty($saveProfileData['old_profile_data'][0])) {
            $oldProfileData = $saveProfileData['old_profile_data'][0];
            if(isset($oldProfileData['manual_product_ids']) && !empty($oldProfileData['manual_product_ids'])) {
                foreach($oldProfileData['manual_product_ids'] as $productId) {
                    $productQuery["container_id"] = $productId;
                    $productsFound = $productEditModel->getProduct($productQuery);
                    if(isset($productsFound['success']) && $productsFound['success'] && !empty($productsFound['data']['rows'])) {
                        $this->getOldProfileIdsForStatus($productsFound['data']['rows'], $shopDetails, $oldProfileIds);
                    }
                }
            } elseif (isset($oldProfileData['query']) && !empty($oldProfileData['query'])) {
                $querySearch['query'] = $oldProfileData['query'];
                $oldqueryResult = $this->di->getObjectManager()->get('\App\Connector\Components\Profile\QueryProducts')->getQueryProducts($querySearch);
                if(isset($oldqueryResult['success']) && $oldqueryResult['success'] && !empty($oldqueryResult['data']['rows'])) {
                    $this->getOldProfileIdsForStatus($oldqueryResult['data']['rows'], $shopDetails, $oldProfileIds);
                }
            }
        }

        $editedData = [];
        $updateProfileIds = [];
        if(!empty($updateProfileData)) {
            if(isset($updateProfileData['manual_product_ids']) && !empty($updateProfileData['manual_product_ids'])) {
                foreach($updateProfileData['manual_product_ids'] as $productId) {
                        $productQuery["container_id"] = $productId;
                        $productsFound = $productEditModel->getProduct($productQuery);
                        if(isset($productsFound['success']) && $productsFound['success'] && !empty($productsFound['data']['rows'])) {
                            $this->prepareProductUploadStatusData($productsFound['data']['rows'], $shopDetails, $editedData, $updateProfileIds);
                        }
                }
            } elseif(isset($updateProfileData['query']) && !empty($updateProfileData['query'])) {
                $querySearch['query'] = $updateProfileData['query'];
                $queryResult = $this->di->getObjectManager()->get('\App\Connector\Components\Profile\QueryProducts')->getQueryProducts($querySearch);
                if(isset($queryResult['success']) && $queryResult['success'] && !empty($queryResult['data']['rows'])) {
                        $this->prepareProductUploadStatusData($queryResult['data']['rows'], $shopDetails, $editedData, $updateProfileIds);
                }
            }

            if(!empty($editedData)) {
                $filter['target']['shopId'] = (string)$shopDetails['target']['shopId'] ?? "";
                $filter['source']['shopId'] = (string)$shopDetails['source']['shopId'] ?? "";
                $filter['target']['marketplace'] = $shopDetails['target']['marketplace'] ?? "";
                $filter['source']['marketplace'] = $shopDetails['source']['marketplace'] ?? "";
                $filter['user_id'] = $this->di->getUser()->id;
                $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                $editHelper->saveProduct($editedData , false , $filter);
            }

            if(!empty($oldProfileIds) && !empty($updateProfileIds)) {
                $removeStatusData = [];
                $oldProfileIds = array_unique($oldProfileIds);
                $updateProfileIds = array_unique($updateProfileIds);
                $removedIds = array_diff($oldProfileIds, $updateProfileIds);
                if(!empty($removedIds)) {
                    foreach($removedIds as $removedProductId) {
                        $productQuery = [
                            "container_id" => $removedProductId,
                            'user_id' => $this->di->getUser()->id,
                            'targetShopID' => (string)$shopDetails['target']['shopId'] ?? "",
                            'target_marketplace' => $shopDetails['target']['marketplace'] ?? "",
                            'source_marketplace' => $shopDetails['source']['marketplace'] ?? "",
                            'sourceShopID' => (string)$shopDetails['source']['shopId'] ?? ""
                        ];
                        $productsFound = $productEditModel->getProduct($productQuery);
                        if(isset($productsFound['success']) && $productsFound['success'] && !empty($productsFound['data']['rows'])){
                            $this->removeStatusFromProducts($productsFound['data']['rows'], $shopDetails, $removeStatusData);
                        }
                }

                if(!empty($removeStatusData)) {
                    $filter['target']['shopId'] = (string)$shopDetails['target']['shopId'] ?? "";
                    $filter['source']['shopId'] = (string)$shopDetails['source']['shopId'] ?? "";
                    $filter['target']['marketplace'] = $shopDetails['target']['marketplace'] ?? "";
                    $filter['source']['marketplace'] = $shopDetails['source']['marketplace'] ?? "";
                    $filter['user_id'] = $this->di->getUser()->id;
                    $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                    $editHelper->saveProduct($removeStatusData , false , $filter);
                }
                }
            }
        }
    }

    public function prepareProductUploadStatusData($products, $shopDetails, &$editedData, &$updateProfileIds)
    {
        $status = 'Ready To Upload';
        $productComp = $this->di->getObjectManager()->get(Product::class);
        foreach($products as $product) {
            if((($product['type'] == 'variation') && ($product['visibility'] == 'Catalog and Search')) || (($product['type'] == 'simple') && ($product['visibility'] == 'Catalog and Search'))) {
                $addEntry = false;
                $dataToBeSaved = [];
                if (isset($product['marketplace'])) {
                    $marketplaceProduct = $productComp->getMarketplace($product, (string)$shopDetails['target']['shopId'], $product['source_product_id']);
                    if(!empty($marketplaceProduct) &&
                    (!isset($marketplaceProduct['status']) || (isset($marketplaceProduct['status']) && ($marketplaceProduct['status'] == 'Not Listed')))) {
                            $addEntry = true;
                        } elseif (empty($marketplaceProduct)) {
                            $addEntry = true;
                        }
                    } else {
                        $addEntry = true;
                    }

                    if($addEntry) {
                        $dataToBeSaved = [
                            'target_marketplace' => (string)$shopDetails['target']['marketplace'],
                            'shop_id' => (string)$shopDetails['target']['shopId'],
                            'container_id' => (string)$product['container_id'],
                            'source_product_id' => (string)$product['source_product_id'],
                            'user_id' => $this->di->getUser()->id,
                            'source_shop_id' => (string)$shopDetails['source']['shopId'],
                            'status' => $status
                        ];
                        $updateProfileIds[] = (string)$product['container_id'];
                        $editedData[] = $dataToBeSaved;
                    }
                }
        }

        return ['data' => $editedData, 'product_ids' => $updateProfileIds];
    }

    public function getOldProfileIdsForStatus($products, $shopDetails, &$oldProfileIds): void
    {
        $productComp = $this->di->getObjectManager()->get(Product::class);
        foreach($products as $product){
            if((($product['type'] == 'variation') && ($product['visibility'] == 'Catalog and Search')) || (($product['type'] == 'simple') && ($product['visibility'] == 'Catalog and Search'))) {
                if (!empty($product['marketplace'])) {
                    $marketplaceProduct = $productComp->getMarketplace($product, (string)$shopDetails['target']['shopId'], $product['source_product_id']);
                    if(!empty($marketplaceProduct) && isset($marketplaceProduct['status']) && (($marketplaceProduct['status'] == 'Not Listed') || ($marketplaceProduct['status'] == 'Ready To Upload'))) {
                            $oldProfileIds[] = (string)$product['container_id'];
                    }
                }
            }
        }
    }

    public function removeStatusFromProducts($products, $shopDetails, &$removeStatusData): void
    {
        $productComp = $this->di->getObjectManager()->get(Product::class);
        foreach($products as $product){
            if(($product['type'] == 'variation' || $product['type'] == 'simple') && ($product['visibility'] == 'Catalog and Search')) {
                if (isset($product['marketplace'])) {
                    $dataToBeSaved = [];
                    $marketplaceProduct = $productComp->getMarketplace($product, (string)$shopDetails['target']['shopId'], $product['source_product_id']);
                    if (!empty($marketplaceProduct) && isset($marketplaceProduct['status']) && (($marketplaceProduct['status'] == 'Not Listed') || ($marketplaceProduct['status'] == 'Ready To Upload'))) {
                        $dataToBeSaved = [
                            'target_marketplace' => (string)$shopDetails['target']['marketplace'],
                            'shop_id' => (string)$shopDetails['target']['shopId'],
                            'container_id' => (string)$product['container_id'],
                            'source_product_id' => (string)$product['source_product_id'],
                            'user_id' => $this->di->getUser()->id,
                            'source_shop_id' => (string)$shopDetails['source']['shopId'],
                            'unset' => [
                                'status' => 1
                            ]
                        ];
                        $removeStatusData[] = $dataToBeSaved;
                    }
                }
            }
        }
    }


    public function removeReadyToUpload($data): void
    {
        $profileData = $data['profile_data'] ?? [];
        $profileProductIds = [];
        if(!empty($profileData)) {
            if (!empty($profileData['shop_ids'])) {
                foreach($profileData['shop_ids'] as $shopData) {
                    $targetMarketplace = $data['marketplace'] ?? ($this->di->getRequester()->getTargetName() ?? "amazon");
                    $sourceMarketplace = $this->di->getRequester()->getSourceName() ?? '';
                    if(empty($sourceMarketplace)) {
                        $shops = $this->di->getUser()->shops;
                        if(!empty($shops)) {
                            foreach($shops as $shop) {
                                if($shop['_id'] == $shopData['source']) {
                                    $sourceMarketplace = $shop['marketplace'];
                                }
                            }
                        }
                    }

                    $shopDetails = [
                        'target' => [
                            'marketplace' => $targetMarketplace,
                            'shopId' => $shopData['target']
                        ],
                        'source' => [
                            'marketplace' => $sourceMarketplace,
                            'shopId' => $shopData['source']
                        ]
                    ];
                    $productQuery = [
                        'user_id' => $this->di->getUser()->id,
                        'targetShopID' => (string)$shopDetails['target']['shopId'] ?? "",
                        'target_marketplace' => $shopDetails['target']['marketplace'] ?? "",
                        'source_marketplace' => $shopDetails['source']['marketplace'] ?? "",
                        'sourceShopID' => (string)$shopDetails['source']['shopId'] ?? ""
                    ];

                    $querySearch['target']['shopId'] = (string)$shopDetails['target']['shopId'] ?? "";
                    $querySearch['source']['shopId'] = (string)$shopDetails['source']['shopId'] ?? "";
                    $querySearch['target']['marketplace'] = $shopDetails['target']['marketplace'] ?? "";
                    $querySearch['source']['marketplace'] = $shopDetails['source']['marketplace'] ?? "";
                    $querySearch['user_id'] = $this->di->getUser()->id;
                    $querySearch['activePage'] = 1;
                    $querySearch['limit'] = 1000;

                    $productEditModel = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                    if(isset($profileData['manual_product_ids']) && !empty($profileData['manual_product_ids'])) {
                        foreach($profileData['manual_product_ids'] as $productId) {
                            $productQuery["container_id"] = $productId;
                            $productsFound = $productEditModel->getProduct($productQuery);
                            if(isset($productsFound['success']) && $productsFound['success'] && !empty($productsFound['data']['rows'])){
                                $this->getOldProfileIdsForStatus($productsFound['data']['rows'], $shopDetails, $profileProductIds);
                            }
                        }
                    } elseif (isset($profileData['query']) && !empty($profileData['query'])) {
                        $querySearch['query'] = $profileData['query'];
                        $oldqueryResult = $this->di->getObjectManager()->get('\App\Connector\Components\Profile\QueryProducts')->getQueryProducts($querySearch);
                        if(isset($oldqueryResult['success']) && $oldqueryResult['success'] && !empty($oldqueryResult['data']['rows'])) {
                            $this->getOldProfileIdsForStatus($oldqueryResult['data']['rows'], $shopDetails, $profileProductIds);
                        }
                    }

                    if(!empty($profileProductIds)) {
                        $removeStatusData = [];
                        foreach($profileProductIds as $removedProductId) {
                            $productQuery = [
                                "container_id" => $removedProductId,
                                'user_id' => $this->di->getUser()->id,
                                'targetShopID' => (string)$shopDetails['target']['shopId'] ?? "",
                                'target_marketplace' => $shopDetails['target']['marketplace'] ?? "",
                                'source_marketplace' => $shopDetails['source']['marketplace'] ?? "",
                                'sourceShopID' => (string)$shopDetails['source']['shopId'] ?? ""
                            ];
                            $productsFound = $productEditModel->getProduct($productQuery);
                            if(isset($productsFound['success']) && $productsFound['success'] && !empty($productsFound['data']['rows'])){
                                $this->removeStatusFromProducts($productsFound['data']['rows'], $shopDetails, $removeStatusData);
                            }
                    }

                    if(!empty($removeStatusData)) {
                        $filter['target']['shopId'] = (string)$shopDetails['target']['shopId'] ?? "";
                        $filter['source']['shopId'] = (string)$shopDetails['source']['shopId'] ?? "";
                        $filter['target']['marketplace'] = $shopDetails['target']['marketplace'] ?? "";
                        $filter['source']['marketplace'] = $shopDetails['source']['marketplace'] ?? "";
                        $filter['user_id'] = $this->di->getUser()->id;
                        $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                        $editHelper->saveProduct($removeStatusData , false , $filter);
                    }
                    }
                }
            }
        }
    }
}