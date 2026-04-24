<?php
/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Product\Quickimport;

use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Product\Quickimport\Route\Requestcontrol;
use Phalcon\Logger\Logger;
use App\Shopifyhome\Components\Core\Common;

class Helper extends Common
{
    public const QUICK_IMPORT_PRODUCT_LIMIT = 50;

    public function initiateImport($marketplaceDetails = [], $userId = null)
    {
        $userId ??= $this->di->getUser()->id;

        if(!$userId) return ['success' => false, 'message' => 'Invalid User. Please check your login credentials'];

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();

        $userData = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getDataByUserID($userId, $target);
        if(isset($userData['message'])) return $userData;

        $remoteShopId = $userData->remote_shop_id;

        $quickImportHandlerData = $this->shapeQuickImportSqsData($userId, $remoteShopId, $marketplaceDetails);

        //this is just for testing purpose
        // $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Quickimport\Route\Requestcontrol')->fetchAndSaveProducts($quickImportHandlerData);
        // die('fetchAndSaveProducts');

        //this is just for testing purpose
        /*$sqs_data = array (
  'type' => 'full_class',
  'class_name' => '\\App\\Shopifyhome\\Components\\Product\\Quickimport\\Route\\Requestcontrol',
  'method' => 'handleImport',
  'queue_name' => 'ebay_shopify_product_import',
  'own_weight' => 100,
  'user_id' => '99',
  'data' =>
  array (
    'user_id' => '99',
    'operation' => 'collection_import',
    'remote_shop_id' => '4',
    'sqs_timeout' => 240,
    'filters' =>
    array (
      '_url' => '/connector/product/import',
      'marketplace' => 'shopify',
      'status' => 'active',
      'quickimport' => '1',
    ),
  ),
  'handle_added' => 1,
);

        $response = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Quickimport\Route\Requestcontrol')->fetchAndSaveCollection($sqs_data['user_id'], $sqs_data);
        var_dump($response);
        die('fetchAndSaveCollection');*/

        //this is just for testing purpose
        // $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Quickimport\Route\Requestcontrol')->fetchAndSaveInventory($quickImportHandlerData);
        // die('fetchAndSaveInventory');

        $this->createUserWiseLogs($userId, 'PROCESS 0000 : Data pushed to SQS : '.print_r($quickImportHandlerData, true));

        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        $rmqHelper->createQueue($quickImportHandlerData['queue_name'], $quickImportHandlerData);

        return ['success' => true];
    }

    public function shapeQuickImportSqsData($userId, $remoteShopId, $filters=[])
    {
        $handlerData = [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'method' => 'handleImport',
            'queue_name' => 'shopify_product_import',
            'own_weight' => 100,
            'user_id' => $userId,
            'data' => [
                'user_id' => $userId,
                // 'individual_weight' => 1,
                // 'feed_id' => ($status ? $queuedTask->id : false),
                'operation' => 'product_import',
                'remote_shop_id' => $remoteShopId,
                'sqs_timeout' => $this->di->getConfig()->throttle->shopify->sqs_timeout,
                'filters' => $filters
            ]
        ];

        return $handlerData;
    }

    /**
     * @param $response
     * @return Array
     */

    public function shapeProductResponse($response)
    {
        // return $response;
        $formatResponse = [];

        if (isset($response['success']) && $response['success'] === true) {
            $products = $response['data']['products'];
            foreach ($products as $key=>$product) {
                $formatResponse[$key] = $this->productFormat($product);
            }

            unset($response['data']['products']);
            $response['data']['Product'] = $formatResponse;
        }

        return $response;
    }

    protected function productFormat($product)
    {
        $formatted_product = [];

        $methods = [
            'id' => function($product, $value, &$fproduct){
                //$fproduct['id'] = $product['admin_graphql_api_id'] ?? "gid://shopify/Product/{$value}";

                $id_info = $this->getIdInfo($value);
                $id = !empty($id_info['id']) ? $id_info['id'] : $value;
                if(isset($product['admin_graphql_api_id']))
                {
                    $fproduct['id'] = $product['admin_graphql_api_id'] ?? '';//"gid://shopify/Product/{$value}";
                }
                else{
                    $fproduct['id'] = "gid://shopify/Product/{$id}";
                }

                $fproduct['container_id'] = $id;
                $fproduct['source_product_id'] = $id;
                $fproduct['user_id'] = $this->di->getUser()->id;
                $fproduct['source_sku'] = !empty($product['sku']) ? $product['sku'] : null;
                $fproduct['sku'] = !empty($product['sku']) ? $product['sku'] : $id;
            },

            'body_html' => function($product, $value, &$fproduct){
                $fproduct['descriptionHtml'] = $value;
            },
            'product_type' => function($product, $value, &$fproduct){
                $fproduct['productType'] = $value;
            },
            'created_at' => function($product, $value, &$fproduct){
                $fproduct['createdAt'] = $value;
            },
            'updated_at' => function($product, $value, &$fproduct){
                $fproduct['updatedAt'] = $value;
            },
            'published_at' => function($product, $value, &$fproduct){
                $fproduct['publishedAt'] = $value;
            },
            'template_suffix' => function($product, $value, &$fproduct){
                $fproduct['templateSuffix'] = $value;
            },
            'images' => function($product, $value, &$fproduct){
                if(isset($value['edges'])) {
                    foreach ($value['edges'] as $key => $img) {
                        $fproduct['ProductImage'][$key] = $img['node'];
                    }
                } else {
                    foreach ($value as $key => $img) {
                        $fproduct['ProductImage'][$key] = $img;
                        if(isset($img['src'])) {
                            $fproduct['ProductImage'][$key]['originalSrc'] = $img['src'];
                            unset($fproduct['ProductImage'][$key]['src']);
                        }
                    }
                }
            },
            'image' => function($product, $value, &$fproduct){
                $fproduct['featuredImage'] = $value;
                if(isset($value['src'])) {
                    $fproduct['featuredImage']['originalSrc'] = $value['src'];
                    unset($fproduct['featuredImage']['src']);
                }
            },
            'tags' => function($product, $value, &$fproduct){
                if($value != '')
                {
                    $fproduct['tags'] = explode(',', $value);
                }
                else{
                    $fproduct['tags'] = '';
                }
            },
            'collections' => function ($product, $value, &$fproduct) {
                $canImportCollection = $this->getDi()->getConfig()->get('can_import_collection') ?? true;
                if($canImportCollection === true) {
                    $collectionImportLimit = $this->getDi()->getConfig()->get('import_collection_limit') ?? -1;
                    $count = 0;
                    $collections = [];
                    if(isset($value['edges'])) {
                        foreach ($value['edges'] as $key => $collectionInfo) {
                            $count++;
                            $collections[] = [
                                'collection_id' => str_replace('gid://shopify/Collection/', '', $collectionInfo['node']['id']),
                                'title'         => $collectionInfo['node']['title'],
                                'gid'           => $collectionInfo['node']['id'],
                                '__parentId'    => $product['id']
                            ];

                            if($collectionImportLimit !== -1 && $count >= $collectionImportLimit) {
                                break;
                            }
                        }
                    }
                    else {
                        foreach ($value as $key => $value) {
                            $count++;
                            if(isset($value['__parentId'])) {
                                $collections[] = [
                                    'collection_id' => (string)$key,
                                    'title'         => $value['title'],
                                    '__parentId'    => $value['__parentId'],
                                    'gid'           => $value['id']
                                ];
                            } else {
                                $collections[] = [
                                    'collection_id' => (string)$value['id'],
                                    'title'         => $value['title'],
                                    '__parentId'    => $fproduct['id'],
                                    'gid'           => "gid://shopify/Collection/{$value['id']}"
                                ];
                            }
                            

                            if($collectionImportLimit !== -1 && $count >= $collectionImportLimit) {
                                break;
                            }
                        }
                    }

                    if (!empty($collections)) {
                        $fproduct['collection'] = $collections;
                    } else {
                        $fproduct['collection'] = [];
                    }
                }
            },
        ];

        foreach($product as $key => $value) {
            if (isset($methods[$key])) {
                $methods[$key]($product, $value, $formatted_product);
            } elseif (in_array($key, ['admin_graphql_api_id', 'published_scope', 'variants', 'options'])) {

            } else {
                /*if($key == 'status')
                {
                    $key = 'source_status';
                    unset($product['status']);
                }*/
                $formatted_product[$key] = $value;
            }
        }

        $productVariants = [];
        if(isset($product['variants']['edges'])) {
            foreach($product['variants']['edges'] as $key => $variant) {
                $productVariants[$key] = $this->variantFormat($product, $variant['node']);
            }
        }
        else {
            foreach($product['variants'] as $key => $variant) {
                $productVariants[$key] = $this->variantFormat($product, $variant);
            }
        }

        $formatted_product['ProductVariant'] = $productVariants;

        return $formatted_product;
    }

    protected function variantFormat($product, $variant)
    {
        $formatted_variant = [];

        $methods = [
            'id' => function($product, $value, $variant, &$fvariant){
                if(isset($variant['admin_graphql_api_id'])) {
                    $fvariant['id'] = $variant['admin_graphql_api_id'];//"gid://shopify/ProductVariant/{$value}";
                } else {
                    $fvariant['id'] = "gid://shopify/ProductVariant/{$value}";
                }
            },
            'compare_at_price' => function($product, $value, &$fvariant){
                $fvariant['compareAtPrice'] = $value;
            },
            'inventory_quantity' => function($product, $value, &$fvariant){
                $fvariant['inventoryQuantity'] = $value;
            },
            'fulfillment_service' => function($product, $value, &$fvariant){
                $fvariant['fulfillmentService'] = [
                    'handle'      => $value,
                    'serviceName' => ucfirst($value)
                ];
            },
            'image_id' => function($product, $value, &$fvariant){
                if(!empty($value)) {
                    foreach ($product['images'] as $image) {
                        if($value == $image['id']) {
                            $fvariant['image'] = [
                                'id'          => $image['id'],
                                'position'    => $image['position'],
                                'originalSrc' => $image['src']
                            ];
                        }
                    }
                }
            },
            'selectedOptions' => function($product, $value, &$fvariant){
                if ($value[0]['name']!=='Title' && $value[0]['value']!=='Default Title') {
                    foreach ($value as $option) {
                        $fvariant[$option['name']] = $option['value'];
                    }
                }
            },
            'inventory_item_id' => function($product, $value, $variant, &$fvariant){
                $fvariant['inventoryItem'] = [
                    'id'               => "gid://shopify/InventoryItem/{$value}",
                    'tracked'          => is_null($variant['inventory_management']) ? false : true,
                    'requiresShipping' => $variant['requires_shipping'] ? true : false
                ];
            },
            'created_at' => function($product, $value, &$fvariant){
                $fvariant['createdAt'] = $value;
            },
            'updated_at' => function($product, $value, &$fvariant){
                $fvariant['updatedAt'] = $value;
            },
            'inventory_policy' => function($product, $value, &$fvariant){
                $fvariant['inventoryPolicy'] = $value;
            },
            'weight_unit' => function($product, $value, &$fvariant){
                $unit = '';
                switch ($value) {
                    case 'kg':
                        $unit = 'KILOGRAMS';
                        break;

                    case 'g':
                        $unit = 'GRAMS';
                        break;

                    case 'lb':
                        $unit = 'POUNDS';
                        break;

                    case 'oz':
                        $unit = 'OUNCES';
                        break;
                }

                $fvariant['weightUnit'] = $unit;
            },
            'option1' => function($product, $value, &$fvariant) {
                if(!is_null($value)) {
                    $fvariant['selectedOptions'][0] = ['name'=>$product['options'][0]['name'], 'value' => $value];
                }
            },
            'option2' => function($product, $value, &$fvariant) {
                if(!is_null($value)) {
                    $fvariant['selectedOptions'][1] = ['name'=>$product['options'][1]['name'], 'value' => $value];
                }
            },
            'option3' => function($product, $value, &$fvariant) {
                if(!is_null($value)) {
                    $fvariant['selectedOptions'][2] = ['name'=>$product['options'][2]['name'], 'value' => $value];
                }
            }
        ];

        foreach($variant as $key => $value) {
            if (isset($methods[$key])) {
                if (in_array($key, ['id', 'inventory_item_id'])) {
                    $methods[$key]($product, $value, $variant, $formatted_variant);
                }else {
                    $methods[$key]($product, $value, $formatted_variant);
                }
            } else {
                if (!in_array($key, ['product_id', 'admin_graphql_api_id', 'requires_shipping', 'old_inventory_quantity'])) {
                    $formatted_variant[$key] = $value;
                }
            }
        }

        return $formatted_variant;
    }

    private function getIdInfo($id)
    {
        if(($pos = strpos((string) $id, '?')) !== false) {
            $id = substr((string) $id, 0, $pos);
        }

        $id = str_replace('gid://shopify/', '', $id);

        $id_info = explode('/', $id);
        return [
            'type'  => $id_info[0] ?? '',
            'id'    => $id_info[1] ?? ''
        ];


    }

    public function createQuickImportLog($message, $type='info'): void
    {
        if($type == 'exception') {
            $logFile = 'shopify'. DS . 'quick_product_import'. DS . 'exception' . DS . date("Y-m-d").'.log';    
            $type = Logger::CRITICAL;
        }
        elseif($type == 'error') {
            $logFile = 'shopify'. DS . 'quick_product_import'. DS . 'error' . DS . date("Y-m-d").'.log';    
            $type = Logger::CRITICAL;
        }
        else {
            $logFile = 'shopify'. DS . 'quick_product_import'. DS . date("Y-m-d").'.log';
        }
        

        $this->di->getLog()->logContent($message, $type, $logFile);
    }

    public function createUserWiseLogs($userId, $message): void
    {
        $logFile = 'shopify'. DS . 'quick_product_import'. DS . 'product_import' . DS . $userId . DS . date("Y-m-d").'.log';

        $this->di->getLog()->logContent($message, Logger::INFO, $logFile);
    }

    /**
     * @param $response
     * @return Array | Boolean
     */
    public function shapeProductListingResponse($product_listing)
    {

        if(isset($product_listing['product_id'])) {

            return $this->productListingFormat($product_listing);
        }
        return false;
    }

    protected function productListingFormat($product)
    {
        $formatted_product = [];
        $methods = [
            'product_id' => function($product, $value, &$fproduct){
                $fproduct['id'] = "gid://shopify/Product/{$value}";
            },
            'body_html' => function($product, $value, &$fproduct){
                $fproduct['descriptionHtml'] = $value;
            },
            'product_type' => function($product, $value, &$fproduct){
                $fproduct['productType'] = $value;
            },
            'created_at' => function($product, $value, &$fproduct){
                $fproduct['createdAt'] = $value;
            },
            'updated_at' => function($product, $value, &$fproduct){
                $fproduct['updatedAt'] = $value;
            },
            'published_at' => function($product, $value, &$fproduct){
                $fproduct['publishedAt'] = $value;
            },
            // 'template_suffix' => function($product, $value, &$fproduct){
            //     $fproduct['templateSuffix'] = $value;
            // },
            'images' => function($product, $value, &$fproduct)
            {
                if(!empty($value))
                {
                    $featuredImageSet = false;
                    foreach ($value as $key => $img) {
                        $fproduct['ProductImage'][$key] = $img;
                        if(isset($img['src'])) {
                            $fproduct['ProductImage'][$key]['originalSrc'] = $img['src'];
                            unset($fproduct['ProductImage'][$key]['src']);
                        }
                        if($img['position'] == 1) {
                            $fproduct['featuredImage'] = $img;
                            if(isset($img['src'])) {
                                $fproduct['featuredImage']['originalSrc'] = $img['src'];
                                unset($fproduct['featuredImage']['src']);
                                $featuredImageSet = true;
                            }
                        }
                    }
                    // added this code due to shopify listing update webhook in which image postion is coming null
                    if(!isset($fproduct['featuredImage']) && isset($value[0]['src']) && !$featuredImageSet){
                        $fproduct['featuredImage'] = $value[0];
                        $fproduct['featuredImage']['originalSrc'] = $value[0]['src'];
                        unset($fproduct['featuredImage']['src']);
                    }
                }
                else{
                    $fproduct['ProductImage']['originalSrc'] = '';
                    $fproduct['featuredImage'] = '';
                }
            },
            // 'image' => function($product, $value, &$fproduct){
            //     $fproduct['featuredImage'] = $value;
            //     if(isset($value['src'])) {
            //         $fproduct['featuredImage']['originalSrc'] = $value['src'];
            //         unset($fproduct['featuredImage']['src']);
            //     }
            // },
            'tags' => function($product, $value, &$fproduct)
            {
                if(!empty($value))
                {
                    $fproduct['tags'] = explode(',', $value);
                }
                else{
                    $fproduct['tags'] = [];
                }
            },
        ];

        foreach($product as $key => $value) {
            if (isset($methods[$key])) {
                $methods[$key]($product, $value, $formatted_product);
            } elseif (in_array($key, ['admin_graphql_api_id', 'published_scope', 'variants', 'options', 'available'])) {

            } else {
                $formatted_product[$key] = $value;
            }
        }

        $productVariants = [];
        foreach($product['variants'] as $key => $variant) {
            $productVariants[$key] = $this->variantListingFormat($product, $variant);
        }

        $formatted_product['ProductVariant'] = $productVariants;

        return $formatted_product;
    }

    protected function variantListingFormat($product, $variant)
    {
        $formatted_variant = [];

        $methods = [
            'id' => function($product, $value, $variant, &$fproduct){
                $fproduct['id'] = "gid://shopify/ProductVariant/{$value}";
            },
            'compare_at_price' => function($product, $value, &$fvariant){
                $fvariant['compareAtPrice'] = $value;
            },
            'inventory_quantity' => function($product, $value, &$fvariant){
                $fvariant['inventoryQuantity'] = $value;
            },
            'fulfillment_service' => function($product, $value, &$fvariant){
                $fvariant['fulfillmentService'] = [
                    'handle'      => $value,
                    'serviceName' => ucfirst($value)
                ];
            },
            'image_id' => function($product, $value, &$fvariant){
                if(!empty($value)) {
                    foreach ($product['images'] as $image) {
                        if($value == $image['id']) {
                            $fvariant['image'] = [
                                'id'          => $image['id'],
                                'position'    => $image['position'],
                                'originalSrc' => $image['src']
                            ];
                        }
                    }
                }
            },
             'inventory_item_id' => function($product, $value, &$fvariant){
                 $fvariant['inventoryItem']['id'] = "gid://shopify/InventoryItem/{$value}";
             },
            'created_at' => function($product, $value, &$fvariant){
                $fvariant['createdAt'] = $value;
            },
            'updated_at' => function($product, $value, &$fvariant){
                $fvariant['updatedAt'] = $value;
            },
            'inventory_policy' => function($product, $value, &$fvariant){
                $fvariant['inventoryPolicy'] = $value;
            },
            'weight_unit' => function($product, $value, &$fvariant){
                $unit = '';
                switch ($value) {
                    case 'kg':
                        $unit = 'KILOGRAMS';
                        break;

                    case 'g':
                        $unit = 'GRAMS';
                        break;

                    case 'lb':
                        $unit = 'POUNDS';
                        break;

                    case 'oz':
                        $unit = 'OUNCES';
                        break;
                }

                $fvariant['weightUnit'] = $unit;
            },
            'option_values' => function($product, $value, &$fvariant) {
                if(!empty($value)) {
                    foreach ($value as $_key => $_value) {
                        $fvariant['selectedOptions'][$_key] = ['name'=>$_value['name'], 'value' => $_value['value']];   
                    }
                }
            },
            'requires_shipping' => function($product, $value, &$fvariant) {
                $fvariant['inventoryItem']['requiresShipping'] = $value ? true : false;
            },
            'inventory_management' => function($product, $value, &$fvariant) {
                $fvariant['inventoryItem']['tracked'] = is_null($value) ? false : true;
                $fvariant['inventory_management'] = $value;
            }
        ];

        foreach($variant as $key => $value) {
            if (isset($methods[$key])) {
                if (in_array($key, ['id'])) {
                    $methods[$key]($product, $value, $variant, $formatted_variant);
                }else {
                    $methods[$key]($product, $value, $formatted_variant);
                }
            } else {
                if (!in_array($key, ['product_id', 'admin_graphql_api_id', 'old_inventory_quantity', 'formatted_price', 'available'])) {
                    $formatted_variant[$key] = $value;
                }
            }
        }

        return $formatted_variant;
    }
}