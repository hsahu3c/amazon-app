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

namespace App\Shopifyhome\Components\Product\Inventory;

use App\Shopifyhome\Components\Core\Common;
use Exception;

class Hook extends Common
{
    use \App\Core\Components\Concurrency;

    public function createQueueToupdateProductInventory($data)
    {
        $updateInventory = $this->updateProductInventory($data, $data['user_id']);
        return $updateInventory;
    }

    public function updateProductInventorybkp($data, $userId = null)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');

        $collection = $mongo->getCollection('product_container');

        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $res = $collection->find(
            [
                'user_id' => $this->di->getUser()->id,
                'shop_id' => (string)$data['shop_id'],
                'type' => 'simple',
                'inventory_item_id' => (string)$data['data']['inventory_item_id'],
            ],
            $options
        )->toArray();
        if (empty($res)) {
            $data['data']['retry'] = isset($data['data']['retry']) ? (int)($data['data']['retry'] + 1) : 1;

            $this->di->getLog()->logContent($data['data']['inventory_item_id'] . ' Inv Update Retry Count = ' . $data['data']['retry'], 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'inventory' . DS . date("Y-m-d") . DS . 'retry_inventory_update.log');

            return ['success' => true, 'requeue' => true, 'sqs_data' => $data];
        }
        $productInv = $this->di->getObjectManager()->get(Utility::class)->typeCastElement($data['data']);
        $productInv['shop_id'] = $data['shop_id'];
        $updateShopifyProductInv = $this->di->getObjectManager()->get(Data::class)->updateInvAtLocation($productInv, $userId);
        if (($updateShopifyProductInv['matched_count'] == 0) || ($updateShopifyProductInv['modified_count'] == 0)) {
            $this->di->getLog()->logContent('PROCESS 000003 | Inventory | Hook | Matched Count : ' . $updateShopifyProductInv['matched_count'] . ' | Modified Count : ' . $updateShopifyProductInv['modified_count'], 'info', 'shopify' . DS . 'global' . DS . date("Y-m-d") . DS . $this->di->getUser()->id . DS . 'ZeroMatch.log');
        }
        if (isset($updateShopifyProductInv['success'])) {
            $totalQty = $this->di->getObjectManager()->get(Data::class)->getTotalQtyFromLocations((string)$data['data']['inventory_item_id']);

            if (isset($totalQty['success'])) {
                $total_quantity = $totalQty['total_qty'];

                $updateTotalInvData = [
                    'user_id' => $this->di->getUser()->id,
                    'shop_id' => (string)$data['shop_id'],
                    'inventory_item_id' => (string)$data['data']['inventory_item_id'],
                    'total_qty' => $totalQty['total_qty']
                ];
                $totalQty = $this->di->getObjectManager()->get(Data::class)->updateTotalQty($updateTotalInvData);

                //updating in refine table
                $res = json_decode(json_encode($res), true);

                $refine_update = [
                    'source_product_id' => $res[0]['source_product_id'],
                    'source_shop_id' => $data['shop_id'],
                    'childInfo' => [
                        'quantity' => $total_quantity,
                        'source_product_id' => $res[0]['source_product_id'],
                        'shop_id' => $data['shop_id'],
                        'source_marketplace' => $data['marketplace']
                    ]
                ];



                $refine_update_helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                $refine_update_request = $refine_update_helper->marketplaceSaveAndUpdate($refine_update);
            }
        } else {
            $this->di->getLog()->logContent('PROCESS 000006 | Inventory | Hook | init inv update | No product has been updated', 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'inventory' . DS . date("Y-m-d") . DS . 'webhook_inventory_update.log');
        }

        return true;
    }
    public function prepareAndSetInventory($data, $invItemExists)
    {
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('product_container');


        $productInv = $this->di->getObjectManager()->get(Utility::class)->typeCastElement($data['data']);
        //$productInv['shop_id'] = $data['shop_id'];
        $isInventoryLevelsUpdate = isset($data['action']) && $data['action'] == 'inventory_levels_update' ? true : false;

        $addNewLocations = $isInventoryLevelsUpdate && $this->di->getConfig()->use_inventory_levels_connect_to_add_new_locations ? false : true;
        $locationsData = $this->prepareLocationsData($productInv, $userId, $invItemExists, $addNewLocations);
        if (isset($locationsData['success'])  && $locationsData['success']) {
            $locationIds = array_column($locationsData['data'], 'location_id');
            $this->addMissingLocationsInShop($locationIds, $data['shop_id']);

            $totalQty = $this->prepareTotalQty($locationsData['data']);

            if (isset($totalQty['success'])) {
                $total_quantity = (int)$totalQty['total_qty'];



                $updateInDb =  $this->handleLock('product_container', fn() => $collection->updateMany(
                    [
                        '_id' => $invItemExists['_id'],

                    ],
                    [
                        '$set' => [
                            'quantity' => $total_quantity,
                            'locations' => $locationsData['data']
                        ]
                    ]
                ), 10);

                $refine_update = [
                    'source_product_id' => $invItemExists['source_product_id'],
                    'source_shop_id' => $data['shop_id'],
                    'childInfo' => [
                        'quantity' => $total_quantity,
                        'source_product_id' => $invItemExists['source_product_id'],
                        'shop_id' => $data['shop_id'],
                        'source_marketplace' => $data['marketplace']
                    ]
                ];



                $refine_update_helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                $refine_update_request = $refine_update_helper->marketplaceSaveAndUpdate($refine_update);
            }
        } else {
            $this->di->getLog()->logContent('PROCESS 000006 | Inventory | Hook | init inv update | No product has been updated', 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'inventory' . DS . date("Y-m-d") . DS . 'webhook_inventory_update.log');
            return ['success' => false, 'response' => 'No product has been updated'];
        }


        return true;
    }


    public function updateProductInventory($data, $userId = null)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');

        $collection = $mongo->getCollection('product_container');

        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $isInventoryLevelsUpdate = isset($data['action']) && $data['action'] == 'inventory_levels_update' ? true : false;

        $inventory_item_id = (string)$data['data']['inventory_item_id'] ?? '';
        if ($inventory_item_id != '') {
            $invItemExists = $collection->findOne(
                [
                    'user_id' => $this->di->getUser()->id,
                    'shop_id' => (string)$data['shop_id'],
                    'inventory_item_id' => (string)$data['data']['inventory_item_id'],
                    'type' => 'simple',
                ],
                $options
            );

            if (empty($invItemExists)) {
                return ['success' => true, 'requeue' => false, 'sqs_data' => $data, 'message' => 'inventory item not found in db'];
            }
            $productInv = $this->di->getObjectManager()->get(Utility::class)->typeCastElement($data['data']);
            //$productInv['shop_id'] = $data['shop_id'];
            $addNewLocations = $isInventoryLevelsUpdate && $this->di->getConfig()->use_inventory_levels_connect_to_add_new_locations ? false : true;
            $locationsData = $this->prepareLocationsData($productInv, $userId, $invItemExists, $addNewLocations);
            if (isset($locationsData['success'])  && $locationsData['success']) {
                $locationIds = array_column($locationsData['data'], 'location_id');
                $this->addMissingLocationsInShop($locationIds, $data['shop_id']);

                $totalQty = $this->prepareTotalQty($locationsData['data']);

                if (isset($totalQty['success'])) {
                    $total_quantity = (int)$totalQty['total_qty'];
                    $updateInDb =  $this->handleLock('product_container', fn() => $collection->updateMany(
                        [
                            '_id' => $invItemExists['_id'],

                        ],
                        [
                            '$set' => [
                                'quantity' => $total_quantity,
                                'locations' => $locationsData['data']
                            ]
                        ]
                    ), 10);

                    $refine_update = [
                        'source_product_id' => $invItemExists['source_product_id'],
                        'source_shop_id' => $data['shop_id'],
                        'childInfo' => [
                            'quantity' => $total_quantity,
                            'source_product_id' => $invItemExists['source_product_id'],
                            'shop_id' => $data['shop_id'],
                            'source_marketplace' => $data['marketplace']
                        ]
                    ];



                    $refine_update_helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                    $refine_update_request = $refine_update_helper->marketplaceSaveAndUpdate($refine_update);
                }
            } else {
                $this->di->getLog()->logContent('PROCESS 000006 | Inventory | Hook | init inv update | No product has been updated', 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'inventory' . DS . date("Y-m-d") . DS . 'webhook_inventory_update.log');
                return ['success' => false, 'response' => 'No product has been updated'];
            }
        }

        return true;
    }


    public function prepareLocationsData($data, $userId = null, $invItemExists = [], $addNewLocations = true)
    {
        try {
            if (!empty($invItemExists)) {
                $locationIdPresent = false;
                $locationsInVar = $invItemExists['locations'] ?? [];
                foreach ($locationsInVar as $locKey => $locValue) {
                    if ($locValue['location_id'] == $data['location_id']) {
                        if (!isset($locValue['updated_at'], $data['updated_at']) || (new \DateTime($locValue['updated_at']) < new \DateTime($data['updated_at']))) {
                            $locationsInVar[$locKey]['available'] = $data['available'] ?? 0;
                            $locationsInVar[$locKey]['updated_at'] = $data['updated_at'] ?? date('c');
                        }
                        $locationIdPresent = true;
                        break;
                    }
                }

                if (!$locationIdPresent && ($addNewLocations || $data['available'] != 0)) {
                    $locationsInVar[] = $data;
                }

                if (!empty($locationsInVar)) {
                    return [
                        'success'   => true,
                        'data'      => $locationsInVar
                    ];
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('EXCEPTION 000001 | Inventory | Hook | UserId | ' . $userId . PHP_EOL . ' Exception' . $e->getMessage() . PHP_EOL . ' Exception Trace' . $e->getTraceAsString(), 'info', 'shopify' . DS . $userId . DS . 'exception' . DS . date("Y-m-d") . DS . 'webhook_inventory_update.log');

            return ['success' => false, 'message' => $e->getTraceAsString()];
        }

        return [
            'success' => false,
            'data' => [],
            'message' => 'data not found'
        ];
    }

    public function prepareTotalQty($locationsArray)
    {
        $total = 0;
        foreach ($locationsArray as $value) {
            $total += $value['available'] ?? 0;
        }

        return [
            'success' => true,
            'total_qty' => $total
        ];
    }

    public function addMissingLocationsInShop($locationIds, $shopId)
    {
        $userDetailsObj = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shopData = $userDetailsObj->getShop($shopId, $this->di->getUser()->id);

        $locationIdsInShopifyShop = array_column($shopData['warehouses'], 'id');

        $missingLocations = array_diff($locationIds, $locationIdsInShopifyShop);

        if (!empty($missingLocations)) {
            $appCode = $shopData['apps'][0]['code'] ?? 'default';

            $apiClientObj = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('shopify', true, $appCode);

            $remoteShopId = $shopData['remote_shop_id'];
            foreach ($missingLocations as $locationId) {
                $locationResponse = $apiClientObj->call('shop/location', [], ['id' => $locationId, 'shop_id' => $remoteShopId], 'GET');

                if (isset($locationResponse['success']) && !empty($locationResponse['data'])) {
                    $locationUpdateData = [
                        'user_id' => $this->di->getUser()->id,
                        'shop_id' => $shopId,
                        'data' => $locationResponse['data']
                    ];
                    $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Shop\Hook::class)->addAndUpdateLocation($locationUpdateData);
                }
            }
        }
    }

    public function deleteandUpdateProductLocationInventory($data) {}

    public function createQueueToDisconnectProductInventory($data)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');

        $collection = $mongo->getCollection('product_container');

        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $inventory_item_id = (string)$data['data']['inventory_item_id'] ?? '';
        if ($inventory_item_id != '') {
            $invItemExists = $collection->findOne(
                [
                    'user_id' => $this->di->getUser()->id,
                    'shop_id' => (string)$data['shop_id'],
                    'inventory_item_id' => (string)$data['data']['inventory_item_id'],
                    'type' => 'simple',
                ],
                $options
            );

            if (empty($invItemExists)) {
                return ['success' => true, 'requeue' => false, 'sqs_data' => $data, 'message' => 'inventory item not found in db'];
            }
            $productInv = $this->di->getObjectManager()->get(Utility::class)->typeCastElement($data['data']);
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $locationsData = $this->updateLocationsData($productInv, $userId, $invItemExists);
            if (isset($locationsData['success'])  && $locationsData['success']) {
                $updateInDb =  $this->handleLock('product_container', fn() => $collection->updateMany(
                    [
                        '_id' => $invItemExists['_id'],

                    ],
                    [
                        '$set' => [
                            'locations' => $locationsData['data']
                        ]
                    ]
                ), 10);
            } else {
                $this->di->getLog()->logContent('PROCESS 000006 | Inventory | Hook | init inv update | No product has been updated', 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'inventory' . DS . date("Y-m-d") . DS . 'webhook_disconnect_inventory_update.log');
            }
        }

        return true;
    }


    public function updateLocationsData($data, $userId = null, $invItemExists = [])
    {
        try {
            if (!empty($invItemExists)) {
                $locationIdPresent = false;
                $locationsInVar = $invItemExists['locations'] ?? [];
                foreach ($locationsInVar as $locKey => $locValue) {
                    if ($locValue['location_id'] == $data['location_id']) {
                        $locationIdPresent = true;
                        array_splice($locationsInVar, $locKey, 1);
                        break;
                    }
                }

                if (!$locationIdPresent) {
                    $locationsInVar[] = $data;
                }

                //at least one location should be present in the product
                if (!empty($locationsInVar)) {
                    return [
                        'success'   => true,
                        'data'      => $locationsInVar
                    ];
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('EXCEPTION 000001 | Inventory | Hook | UserId | ' . $userId . PHP_EOL . ' Exception' . $e->getMessage() . PHP_EOL . ' Exception Trace' . $e->getTraceAsString(), 'info', 'shopify' . DS . $userId . DS . 'exception' . DS . date("Y-m-d") . DS . 'webhook_disconnect_inventory_update.log');

            return ['success' => false, 'message' => $e->getTraceAsString()];
        }

        return [
            'success' => false,
            'data' => [],
            'message' => 'data not found'
        ];
    }

    public function processInventoryLevelsUpdateWebhook($data)
    {
        if (isset($data['data']['inventory_item_id'])) {
            $sourceShopId = $data['shop_id'];
            $sourceMarketplace = $data['marketplace'];
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $userId = (string)$userId;
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');

            $collection = $mongo->getCollection('product_container');

            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];


            $inventory_item_id = (string)$data['data']['inventory_item_id'] ?? '';
            if ($inventory_item_id != '') {
                $invItemExists = $collection->findOne(
                    [
                        'user_id' => $this->di->getUser()->id,
                        'shop_id' => (string)$data['shop_id'],
                        'inventory_item_id' => (string)$data['data']['inventory_item_id'],
                        'type' => 'simple',
                    ],
                    $options
                );

                if (!empty($invItemExists)) {
                    $updateInventory = $this->prepareAndSetInventory($data, $invItemExists);
                    $processData = $this->processData($data, $invItemExists);
                    if (isset($processData['success']) && isset($processData['process_now']) && $processData['success'] && $processData['process_now']) {
                        $marketplaceResponse = [];
                        $shop = $this->di->getUser()->shops ?? array();
                        $data['data']['source_product_id'] = $invItemExists['source_product_id'];
                        $data['data']['container_id'] = $invItemExists['container_id'];

                        if (!empty($shop)) {
                            $targets = isset($shop[0]['targets']) ? $shop[0]['targets'] : [];
                            foreach($targets as $target)
                            {
                                $marketplaces[$target['code']] = $target['code'];
                            }


                            foreach ($marketplaces as $marketplace) {
                                $class = '\App\\' . $marketplace . '\Components\Product\Inventory\Hook';


                                if (class_exists($class)) {
                                    if (method_exists($class, 'processInventoryLevelsUpdateWebhook')) {

                                        $marketplaceResponse[$marketplace] = $this->di->getObjectManager()->get($class)->processInventoryLevelsUpdateWebhook($data);
                                    } else {
                                        $this->di->getLog()->logContent('Method processInventoryLevelsUpdateWebhook does not exist in class ' . $class, 'info', 'shopify' . DS . 'global' . DS . date("Y-m-d") . DS . $this->di->getUser()->id . DS . 'ZeroMatch.log');
                                    }
                                }
                            }
                        }
                    } else {
                        $this->di->getLog()->logContent('No active Product found for this product id' . json_encode($invItemExists['source_product_id']), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'inventory' . DS . date("Y-m-d") . DS . 'ProductNotSyncedToTarget.log');
                    }

                    return $updateInventory;
                    // Process the existing inventory item
                } else {
                    return ['success' => true, 'requeue' => false, 'sqs_data' => $data, 'message' => 'inventory item not found in db'];
                }
            }
        }
    }
    public function processData($data, $invItemExists)
    {
        try {

            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $refineCollection = $mongo->getCollection('refine_product');
            $container_id = $invItemExists['container_id'] ?? '';
            $source_product_id = $invItemExists['source_product_id'] ?? '';
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array'], 'projection' => [
                'container_id' => 1,
                'items.status' => 1
            ]];
            $refineProductData = $refineCollection->find(
                [
                    'user_id' => $data['user_id'],
                    'source_shop_id' => $data['shop_id'],
                    'container_id' => (string) $container_id,
                    // 'source_marketplace' => 'shopify'

                ],
                $options
            )->toArray();

            if (!empty($refineProductData)) {
                // check if any item.status is in inactive ['Active','Inactive','Incomplete'], set FLag true for process now or re-queue
                $flag =  false;
                foreach ($refineProductData as $product) {
                    if ($flag) {
                        break;
                    }
                    // loop through items and use strtolower
                    foreach ($product['items'] as $item) {
                        if (
                            strtolower($item['status']) == 'active'
                            || strtolower($item['status']) == 'inactive'
                            || strtolower($item['status']) == 'incomplete'
                        ) {
                            $flag = true;
                            break;
                        }
                    }
                }
                return ['success' => true, 'data' => $refineProductData, 'process_now' => $flag];
            }
            $message = 'product not found for update';
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (\Exception $e) {
            $this->di->getLog()->logContent('Exception, processListing() => ' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }
}
