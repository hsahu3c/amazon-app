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

namespace App\Shopifyhome\Components\Product\Vistar;

use App\Connector\Models\QueuedTasks;
use App\Shopifyhome\Components\Product\Vistar\Route\Requestcontrol;
use GuzzleHttp\Client;
use function GuzzleHttp\Psr7\stream_for;
use GuzzleHttp\Psr7\Utils;
use App\Shopifyhome\Components\Core\Common;

class Helper extends Common
{
    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public function shapeSqsData($userId, $shop, $queuedTaskId)
    {
        if ($queuedTaskId instanceof QueuedTasks) {
            $queuedTaskId = (string)$queuedTaskId->_id;
        }

        $remoteShopId = $shop['remote_shop_id'];

        $handlerData = [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'method' => 'handleImport',
            'queue_name' => 'shopify_product_import',
            'own_weight' => 100,
            'user_id' => $userId,
            'data' => [
                'user_id' => $userId,
                'individual_weight' => 1,
                'feed_id' => $queuedTaskId,
                'operation' => 'make_request',
                'remote_shop_id' => $remoteShopId,
                'sqs_timeout' => $this->di->getConfig()->throttle->shopify->sqs_timeout
            ]
        ];


        return $handlerData;
    }


    public function saveFile($url)
    {
        $filePath = BP . DS . 'var' . DS . 'file' . DS . $this->di->getUser()->id . DS . 'product.jsonl';
        if (file_exists($filePath)) {
            if (!unlink($filePath)) return ['success' => false, 'message' => 'Error deleting file. Kindly check permission.'];
        }

        $dirname = dirname($filePath);
        if (!is_dir($dirname)) mkdir($dirname, 0775, true);

        $resource = fopen($filePath, 'wb');
        $client = new Client();
        $stream = Utils::streamFor($resource);
        $write = $client->request(
            'GET',
            $url,
            ['sink' => $stream]
        );

        if ($write->getStatusCode() == 200) return ['success' => true, 'file_path' => $filePath];
        return ['success' => false];
    }

    public function productsMetafieldSaveFile($url)
    {
        $filePath = BP . DS . 'var' . DS . 'file' . DS . $this->di->getUser()->id . DS . 'metafields.jsonl';
        if (file_exists($filePath)) {
            if (!unlink($filePath)) return ['success' => false, 'message' => 'Error deleting file. Kindly check permission.'];
        }

        $dirname = dirname($filePath);
        if (!is_dir($dirname)) mkdir($dirname, 0755, true);

        $resource = fopen($filePath, 'wb');
        $client = new Client();
        $stream = Utils::streamFor($resource);
        $write = $client->request(
            'GET',
            $url,
            ['sink' => $stream]
        );

        if ($write->getStatusCode() == 200) return ['success' => true, 'file_path' => $filePath];
        return ['success' => false];
    }

    public function productsInventorySaveFile($url)
    {
        $filePath = BP . DS . 'var' . DS . 'file' . DS . $this->di->getUser()->id . DS . 'productinventory.jsonl';
        if (file_exists($filePath)) {
            if (!unlink($filePath)) return ['success' => false, 'message' => 'Error deleting file. Kindly check permission.'];
        }

        $dirname = dirname($filePath);
        if (!is_dir($dirname)) mkdir($dirname, 0775, true);

        $resource = fopen($filePath, 'wb');
        $client = new Client();
        $stream = Utils::streamFor($resource);
        $write = $client->request(
            'GET',
            $url,
            ['sink' => $stream]
        );

        if ($write->getStatusCode() == 200) return ['success' => true, 'file_path' => $filePath];
        return ['success' => false];
    }

    public function saveJSONLFile($url, $fileName)
    {
        $filePath = BP . DS . 'var' . DS . 'file' . DS . $this->di->getUser()->id . DS . "$fileName.jsonl";
        if (file_exists($filePath)) {
            if (!unlink($filePath)) return ['success' => false, 'message' => 'Error deleting file. Kindly check permission.'];
        }

        $dirname = dirname($filePath);
        if (!is_dir($dirname)) mkdir($dirname, 0775, true);

        $resource = fopen($filePath, 'wb');
        $client = new Client();
        $stream = Utils::streamFor($resource);
        $write = $client->request(
            'GET',
            $url,
            ['sink' => $stream]
        );

        if ($write->getStatusCode() == 200) return ['success' => true, 'file_path' => $filePath];
        return ['success' => false];
    }

    /**
     * @param $response
     * @return Array
     */
    public function shapeProductResponse($response)
    {
        $formatResponse = [];
        $product_count = $variant_count = 0;

        if (isset($response['success']) && $response['success'] === true) {
            $data = $response['data']['Product'];
            foreach ($data as $product) {
                // if (!(isset($product['status']) && $product['status'] == "draft") && !(isset($product['published_at']) && $product['published_at'] == "") && !(isset($product['available']) && !$product['available']) && !(empty($product['publishedAt']))) {
                //     $formatResponse = array_merge($formatResponse, $this->productFormat($product));
                //     $product_count++;
                //     $variant_count += count($product['ProductVariant']);
                // }
                if(isset($product['tags'])){
                    $trim = $product['tags'];
                    if(!is_array($trim)) {
                        $trim = explode(',', (string) $trim);
                    }

                    foreach($trim as $key=>$value){
                        $value = trim((string) $value," ");
                        $trim[$key] =$value;
                    }

                    $product['tags'] = $trim;
                }

                /**
                 *  custom work to allow importing of products only from selected vendor [22th April 2024]
                 */
                // if ($this->di->getUser()->id == '65a6a553e44291db860eb52e') {
                //     $allowedVendors = ['Crayola', 'Grateful Dead', 'Van Gogh Museum', 'Casetry', 'get.casely'];
                //     if (!in_array($product['vendor'], $allowedVendors)) {
                //         continue;
                //     }
                // }

                $formatResponse = array_merge($formatResponse, $this->productFormat($product));
                $product_count++;
                $variant_count += count($product['ProductVariant'] ?? []);
            }

            $response['data'] = $formatResponse;

            $response['count'] = [
                'product' => $product_count,
                'variant' => $variant_count
            ];
        }

        return $response;
    }


    private function productFormat($product)
    {
        $formatted_product['type'] = 'simple';

        $methods = [
            'id' => function ($product, $value, &$fproduct) {
                $id_info = $this->getIdInfo($value);

                $fproduct['shop_id'] = $product['shop_id'] ?? null;
                $fproduct['container_id'] = $id_info['id'];
                $fproduct['source_product_id'] = $id_info['id'];
                $fproduct['source_sku'] = $product['sku'] ?? null;
                $fproduct['sku'] = (isset($product['sku']) && !empty($product['sku'])) ? $product['sku'] : $id_info['id'];
            },
            'title' => function ($product, $value, &$fproduct) {
                $fproduct['title'] = $value;
            },
            'productType' => function ($product, $value, &$fproduct) {
                $fproduct['product_type'] = $value;
            },
            'descriptionHtml' => function ($product, $value, &$fproduct) {
                $fproduct['description'] = $value;
            },
            'description' => function ($product, $value, &$fproduct) {
                // $fproduct['description_without_html'] = $value;
            },
            'tags' => function ($product, $value, &$fproduct) {
                //$fproduct['tags'] = implode(',', $value);
                if (!empty($value) || $value != "") {
                    $fproduct['tags'] = $value;
                } else {
                    $fproduct['tags'] = [];
                }
            },
            'vendor' => function ($product, $value, &$fproduct) {
                $fproduct['brand'] = $value;
            },
            'ProductVariant' => function ($product, $value, &$fproduct) {
                if (count($value) > 1) {
                    $fproduct['type'] = 'variation';
                }

                $options = current($value)['selectedOptions'];
                if ($options[0]['name'] === 'Title' && $options[0]['value'] === 'Default Title' && count($value) == 1) {
                    $fproduct['variant_attributes'] = [];
                    $fproduct['variant_attributes_values'] = [];
                } else {
                    foreach ($options as $option) {
                        $fproduct['variant_attributes'][] = $option['name'];
                    }

                    foreach ($value as $single_value) {
                        foreach ($single_value['selectedOptions'] as $opt) {
                            if (!empty($fproduct['variant_attributes_values'])) {
                                foreach ($fproduct['variant_attributes_values'] as $key => $attr) {
                                    $attributes = array_column($fproduct['variant_attributes_values'], 'key');
                                    if ($attr['key'] == $opt['name'] && !in_array($opt['value'], $fproduct['variant_attributes_values'][$key]['value'])) {
                                        $fproduct['variant_attributes_values'][$key]['value'][] = $opt['value'] ?? '';   
                                    } elseif (!in_array($opt['name'], $attributes)) {
                                        $fproduct['variant_attributes_values'][] = [
                                            'key' => $opt['name'],
                                            'value' => [$opt['value']] ?? []
                                        ];
                                    }
                                }  
                            } else {
                                $fproduct['variant_attributes_values'][] = [
                                    'key' => $opt['name'],
                                    'value' => [$opt['value']] ?? []
                                ];

                            }
                        }
                    }
                }
            },
            'ProductImage' => function ($product, $value, &$fproduct) {
                if (!empty($value)) {
                    $additional_images = 0;
                    foreach ($value as $image) {
                        if (isset($product['featuredImage']['id']) && $image['id'] !== $product['featuredImage']['id']) {
                            $fproduct['additional_images'][] = $image['originalSrc'] ?? null;
                            $additional_images = $additional_images + 1;
                        }
                    }

                    if ($additional_images == 0) {
                        $fproduct['additional_images'] = [];
                    }
                } else {
                    $fproduct['additional_images'] = [];
                }
            },
            'featuredImage' => function ($product, $value, &$fproduct) {
                $fproduct['main_image'] = $value['originalSrc'] ?? '';
            },
            'publishedAt' => function ($product, $value, &$fproduct) {
                $fproduct['published_at'] = $value;
            },
            'createdAt' => function ($product, $value, &$fproduct) {
                $fproduct['source_created_at'] = $value;
            },
            'updatedAt' => function ($product, $value, &$fproduct) {
                $fproduct['source_updated_at'] = $value;
            },
            'resourcePublicationOnCurrentPublication' => function ($product, $value, &$fproduct) {
                $fproduct['publication_assigned_at'] = $value['publishDate'] ?? null;
            },
            'templateSuffix' => function ($product, $value, &$fproduct) {
                $fproduct['template_suffix'] = $value;
            },
            'Collection' => function ($product, $value, &$fproduct) {
                $canImportCollection = $this->getDi()->getConfig()->get('can_import_collection') ?? true;
                if($canImportCollection === true) {
                    $collectionImportLimit = $this->getDi()->getConfig()->get('import_collection_limit') ?? -1;
                    $count = 0;
                    foreach ($value as $key => $value) {
                        $count++;
                        $collections[] = [
                            'collection_id' => (string)$key,
                            'title' => $value['title'],
                            '__parentId' => $value['__parentId'],
                            'gid' => $value['id']
                        ];

                        if($collectionImportLimit !== -1 && $count >= $collectionImportLimit) {
                            break;
                        }
                    }

                    if (!empty($collections)) {
                        $fproduct['collection'] = $collections;
                    } else {
                        $fproduct['collection'] = [];
                    }
                }
            },
            'Metafield' => function ($product, $value, &$fproduct) {
                $getValues = array_values($value);
                $resultData = [];
                $acceptedMetafieldTypes = $this->di->getConfig()->get('acceptedMetafieldTypes')
                ? $this->di->getConfig()->get('acceptedMetafieldTypes')->toArray() : [];
                foreach ($getValues as $metafield) {
                    if (isset($metafield['namespace'], $metafield['key'], $metafield['type']) &&
                        (empty($acceptedMetafieldTypes) || in_array($metafield['type'], $acceptedMetafieldTypes))) {
                        $code = $metafield['namespace'] . '->' . $metafield['key'];
                        $resultData[$code] = [
                            'namespace' => $metafield['namespace'],
                            'key' => $metafield['key'],
                            'value' => $metafield['value'],
                            'type' => $metafield['type'],
                            'created_at' => $metafield['createdAt'],
                            'updated_at' => $metafield['updatedAt']
                        ];
                    }
                }

                $fproduct['parentMetafield'] = $resultData;
            },
            "productCategory" => function($product, $value, &$fproduct) {
                if (isset($value['productTaxonomyNode'])) {
                    $fproduct['product_category'] = $value['productTaxonomyNode'];
                }
            },
            'hasVariantsThatRequiresComponents' => function($product, $value, &$fproduct) {
                if ($value) {
                    $fproduct['is_bundle'] = true;
                }
            },
        ];

        foreach ($product as $key => $value) {
            if (isset($methods[$key])) {
                $methods[$key]($product, $value, $formatted_product);
            } else {
                $formatted_product[$key] = $value;
            }
        }

        $candidateDates = [];
        if (!empty($product['updatedAt'])) {
            $candidateDates[] = $product['updatedAt'];
        }
        if (!empty($product['ProductVariant']) && is_array($product['ProductVariant'])) {
            foreach ($product['ProductVariant'] as $v) {
                if (!empty($v['updatedAt'])) {
                    $candidateDates[] = $v['updatedAt'];
                }
            }
        }

        $latestUpdatedAt = null;
        if (!empty($candidateDates)) {
            $timestamps = array_map('strtotime', $candidateDates);

            $latestUpdatedAt = $candidateDates[array_search(max($timestamps), $timestamps)];
        } else {
            $latestUpdatedAt = $product['updatedAt'] ?? $product['createdAt'] ?? null;
        }

        if ($latestUpdatedAt) {
            $formatted_product['source_updated_at'] = $latestUpdatedAt;
        }

        $result = [];
        foreach ($product['ProductVariant'] as $variant) {
            $vf     = $this->variantFormat($product, $variant, $formatted_product);
            $merged = array_merge($formatted_product, $vf);

            $merged['source_updated_at'] = $latestUpdatedAt;

            $result[] = $merged;
        }

        return $result;
    }

    private function variantFormat($product, $variant, $formatted_product)
    {
        $formatted_variant = [];

        $methods = [
            'id' => function ($product, $value, &$fvariant) {

                $id = $this->getIdInfo($value)['id'];
                $container_id = $id_info = $this->getIdInfo($product['id'])['id'];
                $fvariant['id'] = $id;
                $fvariant['shop_id'] = $product['shop_id'] ?? null;
                $fvariant['source_product_id'] = $id;
                $fvariant['container_id'] = $container_id;

                $fvariant['source_sku'] = $value['sku'] ?? null;
                $fvariant['sku'] = (isset($value['sku']) && !empty($value['sku'])) ? $value['sku'] : $id;
            },
            '__parentId' => function ($product, $value, &$fvariant) {
            },
            'title' => function ($product, $value, &$fvariant) {
                $fvariant['variant_title'] = $value;
            },
            'price' => function ($product, $value, $variant, &$fvariant) {
                $fvariant['price'] = floatval($variant['price']);
            },
            'compareAtPrice' => function ($product, $value, $variant, &$fvariant) {
                $fvariant['compare_at_price'] = $variant['compareAtPrice'];
            },
            'inventoryQuantity' => function ($product, $value, &$fvariant) {
                $fvariant['quantity'] = $value;
            },
            'fulfillmentService' => function ($product, $value, &$fvariant) {
                $fvariant['fulfillment_service'] = $value['handle'];
            },
            'image' => function ($product, $value, &$fvariant) {
                $fvariant['variant_image'] = $value['originalSrc'] ?? null;
                //$fvariant['main_image'] = $value['originalSrc'];
            },
            'selectedOptions' => function ($product, $value, &$fvariant) {
                if (count($value) > 0) {
                    $variant_attributes = [];

                    if ((count($value) == 1) && ($value[0]['name'] == 'Title') && ($value[0]['value'] == 'Default Title')) {
                        $fvariant['variant_attributes'] = $variant_attributes;
                    } else {
                        foreach ($value as $options) {
                            $options_name = $options['name'];
                            $fvariant[$options_name] = $options['value'];
                        }
                    }
                }

                if (isset($value[0]['name']) && isset($value[0]['value'])) {
                    if (($value[0]['name'] !== 'Title' && $value[0]['value'] !== 'Default Title') || (strtolower($value[0]['name']) === 'title' && $value[0]['value'] !== 'Default Title')) {
                        foreach ($value as $option) {
                            $fvariant[$option['name']] = $option['value'];
                        }

                        $fvariant['type'] = 'simple';
                    }
                }
            },
            'inventoryItem' => function ($product, $value, &$fvariant) {
                if (array_key_exists('id', $value)) {
                    $fvariant['inventory_item_id'] = $this->getIdInfo($value['id'])['id'];
                }

                if (array_key_exists('tracked', $value)) {
                    $fvariant['inventory_tracked'] = $value['tracked'];
                    if ($value['tracked']) {
                        $fvariant['inventory_management'] = 'shopify';
                    } else {
                        $fvariant['inventory_management'] = '';
                    }
                }

                if (array_key_exists('requiresShipping', $value)) {
                    $fvariant['requires_shipping'] = $value['requiresShipping'];
                }

                if (array_key_exists('measurement', $value)) {
                    $measurement = $value['measurement'];
                    if(!empty($measurement['weight'])) {
                        $fvariant['weight'] = $measurement['weight']['value'];
                        $fvariant['weight_unit'] = $measurement['weight']['unit'];
                        $fvariant['grams'] = $this->convertWeightInGram($fvariant['weight_unit'], $fvariant['weight']);
                     }
                }
            },
            'InventoryLevel' => function ($product, $value, &$fvariant) {
                $inventory_levels = [];

                foreach ($value as $inventoryLevel) {
                    $inventory_level = [
                        'admin_graphql_api_id' => $inventoryLevel['id'],
                        'updated_at'           => $inventoryLevel['updatedAt'],
                        'available'            => $inventoryLevel['available'] ?? 0,
                        'location_id'          => (string)$this->getIdInfo($inventoryLevel['location']['id'])['id'],
                        'inventory_item_id'    =>  (string)$fvariant['inventory_item_id']
                    ];
                    if(!empty($inventoryLevel['quantities'])) {
                        foreach($inventoryLevel['quantities'] as $values) {
                            if($values['name'] == 'available') {
                                $inventory_level['available'] = $values['quantity'];
                                break;
                            }
                        }
                    }

                    $inventory_level['fulfillment_service'] = $inventoryLevel['location']['fulfillmentService']['handle'] ?? 'manual';

                    //$inventory_level['location']['id'] = $this->getIdInfo($inventoryLevel['location']['id'])['id'];

                    $inventory_levels[] = $inventory_level;
                }

                $fvariant['locations'] = $inventory_levels;
            },
            'createdAt' => function ($product, $value, &$fvariant) {
                $fvariant['created_at'] = $value;
            },
            'updatedAt' => function ($product, $value, &$fvariant) {
                $fvariant['updated_at'] = $value;
            },
            'inventoryPolicy' => function ($product, $value, &$fvariant) {
                // converted to upper case because graphql bulk api provides this value in upper case.
                $fvariant['inventory_policy'] = strtoupper($value);
            },
            'weightUnit' => function ($product, $value, $variant, &$fvariant) {
                $fvariant['weight_unit'] = $value;
                $fvariant['grams'] = $this->convertWeightInGram($value, $variant['weight']);
            },
            'Metafield' => function ($product, $value, &$fvariant) {
                $getValues = array_values($value);
                $resultData = [];
                $acceptedMetafieldTypes = $this->di->getConfig()->get('acceptedMetafieldTypes')
                ? $this->di->getConfig()->get('acceptedMetafieldTypes')->toArray() : [];
                foreach ($getValues as $metafield) {
                    if (isset($metafield['namespace'], $metafield['key'], $metafield['type']) &&
                        (empty($acceptedMetafieldTypes) || in_array($metafield['type'], $acceptedMetafieldTypes))) {
                        $code = $metafield['namespace'] . '->' . $metafield['key'];
                        $resultData[$code] = [
                            'namespace' => $metafield['namespace'],
                            'key' => $metafield['key'],
                            'value' => $metafield['value'],
                            'type' => $metafield['type'],
                            'created_at' => $metafield['createdAt'],
                            'updated_at' => $metafield['updatedAt']
                        ];
                    }
                }

                $fvariant['variantMetafield'] = $resultData;
            },
            "productCategory" => function($product, $value, &$fvariant) {
                $fvariant['product_category'] = $value['productTaxonomyNode'];
            },
            'requiresComponents' => function($product, $value, &$fvariant) {
                if ($value) {
                    $fvariant['is_bundle'] = true;
                }
            },
        ];



        foreach ($variant as $key => $value) {
            if (isset($methods[$key])) {
                if (in_array($key, ['price', 'compareAtPrice', 'weightUnit'])) {
                    $methods[$key]($product, $value, $variant, $formatted_variant);
                } else {
                    $methods[$key]($product, $value, $formatted_variant);
                }
            } else {
                $formatted_variant[$key] = $value;
            }
        }

        return $formatted_variant;
    }

    private function getIdInfo($id)
    {
        if (($pos = strpos((string) $id, '?')) !== false) {
            $id = substr((string) $id, 0, $pos);
        }

        $id = str_replace('gid://shopify/', '', $id);

        $id_info = explode('/', $id);

        return [
            'type'  => $id_info[0],
            'id'    => $id_info[1]
        ];
    }

    private function convertWeightInGram($unit, $weight)
    {
        $grams = null;

        switch ($unit) {
            case 'KILOGRAMS':
                $grams = $weight * 1000;
                break;

            case 'GRAMS':
                $grams = $weight;
                break;

            case 'POUNDS':
                $grams = $weight * 453.6;
                break;

            case 'OUNCES':
                $grams = $weight * 28.3;
                break;
        }

        return $grams;
    }

    public function shapeSqsDataForSyncCatalog($userId, $shop, $queuedTaskId)
    {
        if ($queuedTaskId instanceof QueuedTasks) {
            $queuedTaskId = (string)$queuedTaskId->_id;
        }

        $remoteShopId = $shop['remote_shop_id'];

        $handlerData = [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'method' => 'handleSyncCatalog',
            'queue_name' => 'shopify_product_sync',
            'own_weight' => 100,
            'user_id' => $userId,
            'data' => [
                'user_id' => $userId,
                'individual_weight' => 1,
                'feed_id' => $queuedTaskId,
                'operation' => 'make_request',
                'remote_shop_id' => $remoteShopId,
                'sqs_timeout' => $this->di->getConfig()->throttle->shopify->sqs_timeout
            ]
        ];


        return $handlerData;
    }
}
