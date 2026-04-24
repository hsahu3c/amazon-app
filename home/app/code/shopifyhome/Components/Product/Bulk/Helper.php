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

namespace App\Shopifyhome\Components\Product\Bulk;

use App\Shopifyhome\Components\Utility;
use GuzzleHttp\Client;
use App\Connector\Models\QueuedTasks;
use App\Shopifyhome\Components\Product\Bulk\Route\Requestcontrol;
use function GuzzleHttp\Psr7\stream_for;
use GuzzleHttp\Psr7\Utils;
use App\Shopifyhome\Components\Core\Common;
use Exception;

class Helper extends Common
{
    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public const SHOPIFY_QUEUE_INV_IMPORT_MSG = 'SHOPIFY_INVENTORY_SYNC';

    public function formatBulkDataForContainer($pData)
    {
        foreach($pData['inventory_levels'] as $inventory){
            $pData['locations'][] = [
                'inventory_item_id' => (string)$pData['inventory_item_id'],
                'location_id' => (string)$inventory['location']['id'],
                'available' => (int)$inventory['available'],
                'updated_at' => $inventory['updated_at'],
                'admin_graphql_api_id' => $inventory['admin_graphql_api_id']
            ];
        }

        unset($pData['inventory_levels']);

        return $pData;
    }

    public function saveFile($url)
    {
        $filePath = BP.DS.'var'.DS.'file'.DS.$this->di->getUser()->id.DS.'product.jsonl';
        if(file_exists($filePath)){
            if (!unlink($filePath)) return['success' => false, 'message' => 'Error deleting file. Kindly check permission.'];
        }

        $system = $this->di->getObjectManager()->get(Utility::class);

        $openTime = microtime(true);

        $system->init('shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'system.log', ' Before Downloading bulk product file ')->RAMConsumption();

        $dirname = dirname($filePath);
        if (!is_dir($dirname)) mkdir($dirname, 0755, true);

        $resource = fopen($filePath ,'wb');
        $client = new Client();
        $stream = Utils::streamFor($resource);
        $write = $client->request(
            'GET',
            $url,
            ['sink' => $stream]
        );

        $system->init('shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'system.log', ' After Downloading bulk product file ')->RAMConsumption();
        $system->init('shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'system.log')->CPUConsumption();

        $endTime = microtime(true);
        $elapsedtIME = $endTime - $openTime;

        $generalizedTime = $this->di->getObjectManager()->get(Utility::class)->secondsToTime($elapsedtIME);

        $this->di->getLog()->logContent('PROCESS 00000 | Shopifyhome\Components\Product\Bulk\Helper | saveFile | Total Time Taken to save File = '.$generalizedTime,'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'system.log');
        $this->di->getLog()->logContent('','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'system.log');

        if($write->getStatusCode() == 200) return ['success' => true, 'file_path' => $filePath];
        return ['success' => false];
    }

    public function shapeSqsData($userId, $remoteShopId)
    {
        try{
            $queuedTask = new QueuedTasks;
            $queuedTask->set([
                'user_id' => (int)$userId,
                /*'message' => SourceModel::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : initializing ...',*/
                'message' => Helper::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : Seeking Shopify to accept your Product(s) import Request.',
                'progress' => 0.00,
                'shop_id' => (int)$userId
            ]);
            $status = $queuedTask->save();
        } catch (Exception $e){
            echo $e->getMessage();
        }

        $handlerData = [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'method' => 'handleBulkImport',
            'queue_name' => 'shopify_product_import',
            'own_weight' => 100,
            'user_id' => $userId,
            'data' => [
                'user_id' => $userId,
                'individual_weight' => 0,
                'feed_id' => ($status ? $queuedTask->id : false),
                'operation' => 'make_request',
                'remote_shop_id' => $remoteShopId
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

        $product_count = $variant_count =0;

        if (isset($response['success']) && $response['success'] === true) {
            $data = $response['data']['Product'] ?? $response['data']['products'];

            foreach ($data as $product) {
                $formatResponse = array_merge($formatResponse, $this->productFormat($product));
                $product_count++;
                $variant_count += count($product['ProductVariant']);
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
        $formatted_product['source_sku'] = !empty($product['sku']) ? $product['sku'] : $product['id'];

        $methods = [
            'id' => function($product, $value, &$fproduct){
                $id_info = $this->getIdInfo($value);
                if(count($product['ProductVariant']) > 1) {
                    $fproduct['group_id'] = $id_info['id'];
                }

                $fproduct['container_id'] = $id_info['id'];
            },
            'title' => function($product, $value, &$fproduct){
                $fproduct['name'] = $value;
            },
            'productType' => function($product, $value, &$fproduct){
                $fproduct['product_type'] = $value;
            },
            'descriptionHtml' => function($product, $value, &$fproduct){
                $fproduct['description'] = $value;
            },
            'description' => function($product, $value, &$fproduct){
                // $fproduct['description_without_html'] = $value;
            },
            'tags' => function($product, $value, &$fproduct){
                $fproduct['tags'] = implode(',', $value);
            },
            'vendor' => function($product, $value, &$fproduct){
                $fproduct['brand'] = $value;
            },
            'ProductVariant' => function($product, $value, &$fproduct){
                if(count($value) > 1) {
                    $fproduct['type'] = 'variation';
                }

                $options = current($value)['selectedOptions'];
                if (!($options[0]['name']==='Title' && $options[0]['value']==='Default Title')) {
                    foreach ($options as $option) {
                        $fproduct['variant_attributes'][] = $option['name'];
                        $fproduct[$option['name']] = $option['value'];
                    }
                }
            },
            'ProductImage' => function($product, $value, &$fproduct){
                if(!empty($value)) {
                    foreach ($value as $image) {
                        if($image['id'] !== $product['featuredImage']['id'])
                            $fproduct['additional_images'][] = $image['originalSrc'];
                    }
                }
            },
            'featuredImage' => function($product, $value, &$fproduct){
                $fproduct['main_image'] = $value['originalSrc'];
            },
            'publishedAt' => function($product, $value, &$fproduct){
                $fproduct['published_at'] = $value;
            },
            'createdAt' => function($product, $value, &$fproduct){
                $fproduct['created_at'] = $value;
            },
            'updatedAt' => function($product, $value, &$fproduct){
                $fproduct['updated_at'] = $value;
            },
            'templateSuffix' => function($product, $value, &$fproduct){
                $fproduct['template_suffix'] = $value;
            },
            'Collection' => function($product, $value, &$fproduct){
                $canImportCollection = $this->getDi()->getConfig()->get('can_import_collection') ?? true;
                if($canImportCollection === true) {
                    $collectionImportLimit = $this->getDi()->getConfig()->get('import_collection_limit') ?? -1;
                    $count = 0;
                    $collections = [];
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

                    $fproduct['collection'] = $collections;
                }
            },
            'hasVariantsThatRequiresComponents' => function($product, $value, &$fproduct) {
                if ($value) {
                    $fproduct['is_bundle'] = true;
                }
            }
        ];

        foreach($product as $key => $value) {
            if (isset($methods[$key])) {
                $methods[$key]($product, $value, $formatted_product);
            } else {
                $formatted_product[$key] = $value;
            }
        }

        $result = [];
        foreach($product['ProductVariant'] as $variant) {
            $result[] = array_merge($formatted_product, $this->variantFormat($product, $variant, $formatted_product));
        }

        return $result;
    }

    private function variantFormat($product, $variant, $formatted_product)
    {
        $formatted_variant = [];

        $methods = [
            'id' => function($product, $value, &$fvariant){
                $fvariant['id'] = $this->getIdInfo($value)['id'];
            },
            '__parentId' => function($product, $value, &$fvariant){
            },
            'title' => function($product, $value, &$fvariant){
                $fvariant['variant_title'] = $value;
            },
            'price' => function($product, $value, $variant, &$fvariant){
                $fvariant['price'] = $variant['price'];
            },
            'compareAtPrice' => function($product, $value, $variant, &$fvariant){
                $fvariant['compare_at_price'] = $variant['compareAtPrice'];
            },
            'inventoryQuantity' => function($product, $value, &$fvariant){
                $fvariant['quantity'] = $value;
            },
            'fulfillmentService' => function($product, $value, &$fvariant){
                $fvariant['fulfillment_service'] = $value['handle'];
            },
            'sku' => function($product, $value, &$fvariant){
                $fvariant['sku'] = $value;
                $fvariant['source_sku'] = $value;
            },
            'image' => function($product, $value, &$fvariant){
                $fvariant['variant_image'] = $value['originalSrc'];
            },
            'selectedOptions' => function($product, $value, &$fvariant){
                if ($value[0]['name']!=='Title' && $value[0]['value']!=='Default Title') {
                    foreach ($value as $option) {
                        $fvariant[$option['name']] = $option['value'];
                    }
                }
            },
            'inventoryItem' => function($product, $value, &$fvariant){
                $fvariant['inventory_item_id']      = $this->getIdInfo($value['id'])['id'];
                // $fvariant['inventory_management']   = $value['tracked'];
                $fvariant['inventory_tracked']      = $value['tracked'];
                $fvariant['requires_shipping']      = $value['requiresShipping'];
            },
            'InventoryLevel' => function($product, $value, &$fvariant){
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

                    //$inventory_level['location']['id'] = $this->getIdInfo($inventoryLevel['location']['id'])['id'];

                    $inventory_levels[] = $inventory_level;
                }

                $fvariant['locations'] = $inventory_levels;
            },
            'createdAt' => function($product, $value, &$fvariant){
                $fvariant['created_at'] = $value;
            },
            'updatedAt' => function($product, $value, &$fvariant){
                $fvariant['updated_at'] = $value;
            },
            'inventoryPolicy' => function($product, $value, &$fvariant){
                $fvariant['inventory_policy'] = $value;
            },
            'weightUnit' => function($product, $value, $variant, &$fvariant){
                $fvariant['weight_unit'] = $value;
                $fvariant['grams'] = $this->convertWeightInGram($value, $variant['weight']);
            },
            'requiresComponents' => function($product, $value, &$fvariant) {
                if ($value) {
                    $fvariant['is_bundle'] = true;
                }
            }
        ];

        foreach($variant as $key => $value) {
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
        if(($pos = strpos((string) $id, '?')) !== false) {
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

}