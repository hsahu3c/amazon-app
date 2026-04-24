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

use App\Core\Components\Concurrency;
use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Product\Helper;
use App\Shopifyhome\Components\Core\Common;
use Exception;

class Data extends Common
{
    use \App\Core\Components\Concurrency;

    public $_collection = '';

    public $_error = false;

    public const CHUNK_SIZE = 40;


    public function prepare($userId)
    {
        $userId ??= $this->di->getUser()->id;

        if(!$userId){
            return $this->_error = [
                'success' => false,
                'message' => 'Invalid User. Please check your login credentials'
            ];
        }

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $this->_collection = $mongo->getCollection("product_container");
    }

    public function fetchLocationsInChunk($data, $userId = false)
    {
        $this->prepare($userId);
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $cursor = $data['data']['cursor'] ?? 0;
        if($this->_error) return $this->_error;

        $invResponse = $this->_collection->distinct(
            'inventory_item_id', ['$and'=>[
            ['user_id' => $userId],
            ["source_marketplace" => $target]
        ]]);
        $arrayChunks = array_chunk($invResponse, Data::CHUNK_SIZE);
        print_r($arrayChunks);
        if($cursor == 0) return [
            'success' => true,
            'locations' => $arrayChunks[$cursor],
            'total_chunk' => count($arrayChunks),
            'total' => count($invResponse)
        ];

        return [
            'success' => true,
            'locations' => $arrayChunks[$cursor] ?? 'partial_error'
        ];        
    }

    public function deleteLocations($userId = null , $locationId = []): void
    {
        $this->prepare($userId);
        $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(["locations" => ['$exists' => true]],$userId);
        try{
            /*$delete = $this->_collection->updateMany(
                $query,
                [ '$unset' => [
                    "locations" => []
                ]
                ]
            );*/

            $collection = $this->_collection;
            $delete = $this->handleLock('product_container', function() use ($collection, $query) {
                return $collection->updateMany(
                $query,
                [   
                    '$unset' => [
                        "locations" => []
                    ]
                ]
                );
            });

        } catch(\Exception $e){
            // echo $e->getMessage();
        }        
    }

    /*public function updateInvAtLocation($data, $userId = null){
        $this->prepare($userId);
        $out = $this->_collection->aggregate([
                ['$match' => [
                    'variants.locations.inventory_item_id' => ['$eq' => (string)$data['inventory_item_id']],
                    'variants.locations.location_id' => ['$eq' => (string)$data['location_id']]
                ]],
                ['$unwind' => '$variants'],
                [ '$unwind' => '$variants.locations'],
                ['$match' => [
                    'variants.locations.inventory_item_id' => ['$eq' => (string)$data['inventory_item_id']],
                    'variants.locations.location_id' => ['$eq' => (string)$data['location_id']]
                ]],
                ['$project' => ['variants.locations.location_id' => 1 ]]
            ])->toArray();
        if(empty($out)){
                $update = $this->_collection->updateOne(
                    [
                        'variants.inventory_item_id' => (string)$data['inventory_item_id']
                    ],
                    [
                        '$push' => [
                            'variants.$.locations' => $data
                        ]
                    ]
                );
        } else {
                $update = $this->_collection->updateOne(
                    [
                        'variants.locations.inventory_item_id' => (string)$data['inventory_item_id']
                    ],
                    [
                        '$set' => [
                            'variants.$.locations.$[loc].available' => $data['available']
                        ]
                    ],
                    ['arrayFilters' => [
                        [
                            'loc.location_id' => ['$eq' => (string)$data['location_id']]]
                    ]
                    ]
                );
        }
        //$this->di->getLog()->logContent('inventory location update , data ='. json_encode($data),'info','webhook_updateInvAtLocation.log');
        return [
            'success' => true,
            'matched_count' => $update->getMatchedCount(),
            'modified_count' => $update->getModifiedCount()
        ];
        //$this->di->getLog()->logContent('matched count = '.$update->getMatchedCount(). "modified count = ".$update->getModifiedCount(),'info','webhook_updateInvAtLocation.log');
    }*/


    public function updateInvAtLocation($data, $userId = null)
    {

        try{

            $this->prepare($userId);

            /*$out = $this->_collection->aggregate([
                [ '$unwind' => '$locations'],
                ['$match' => [
                    'user_id'=>(string)$userId,
                    'locations.inventory_item_id' => ['$eq' => (string)$data['inventory_item_id']],
                    'locations.location_id' => ['$eq' => (string)$data['location_id']]
                ]],
                ['$project' => ['locations.location_id' => 1 ]]
            ])->toArray();*/

            $out = $this->_collection->aggregate([
                ['$match' => [
                    'user_id'=>(string)$userId,
                    'shop_id' => (string)$data['shop_id'],
                    'type' => 'simple'
                ]],
                ['$match' => [
                    'locations.inventory_item_id' => ['$eq' => (string)$data['inventory_item_id']],
                    'locations.location_id' => ['$eq' => (string)$data['location_id']]
                ]],
                ['$unwind' => '$locations'],
                ['$match' => [
                    'locations.inventory_item_id' => ['$eq' => (string)$data['inventory_item_id']],
                    'locations.location_id' => ['$eq' => (string)$data['location_id']]
                ]],
                ['$project' => ['locations.location_id' => 1 ]]
            ])->toArray();

            if(empty($out))
            {
                $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(['inventory_item_id' => (string)$data['inventory_item_id']],$userId);

                $collection = $this->_collection;
                $update = $this->handleLock('product_container', function() use ($collection, $query, $data) {
                    return $collection->updateMany(
                    $query,
                    [
                        '$push' => [
                            'locations' => $data
                        ]
                    ]
                    );
                });

            }
            else
            {
                $query = $this->di->getObjectManager()
                    ->get(Helper::class)
                    ->getAndQuery(
                        [
                            'locations.inventory_item_id' => (string)$data['inventory_item_id'],
                            'locations.location_id' => (string)$data['location_id'],
                            'shop_id' => $data['shop_id']
                        ],$userId);


                $collection = $this->_collection;
                $update = $this->handleLock('product_container', function() use ($collection, $query, $data) {
                    return $collection->updateMany(
                    $query,
                    [
                        '$set' => [
                            'locations.$.available' => $data['available']
                        ]
                    ]
                    );
                });

            }

            if(!empty($update)){
                return [
                    'success' => true,
                    'matched_count' => $update->getMatchedCount(),
                    'modified_count' => $update->getModifiedCount()
                ];
            }
            
        } catch(Exception $e){
            $this->di->getLog()->logContent('EXCEPTION 000001 | Inventory | Hook | UserId | '.$userId.PHP_EOL.' Exception' . $e->getMessage(). PHP_EOL. ' Exception Trace'. $e->getTraceAsString(), 'info', 'shopify' . DS . $userId . DS . 'exception' . DS . date("Y-m-d") . DS . 'webhook_inventory_update.log');

            return ['success' => false, 'message' => $e->getTraceAsString()];
        }

        return [
            'success' => false,
            'matched_count' => 0,
            'modified_count' => 0
        ];
    }


    public function getTotalQtyFromLocations($inventoryItemId)
    {
        $query = $this->di->getObjectManager()
            ->get(Helper::class)
            ->getAndQuery(
                [
                    'locations.inventory_item_id' =>
                        [
                            '$eq' => (string)$inventoryItemId
                        ],
                    'type' => 'simple'
                ],$this->di->getUser()->id);

        $out = $this->_collection->aggregate([
            ['$match' => $query],
            [ '$unwind' => '$locations'],
            ['$match' => $query],
            ['$project' => ['locations.available' => 1, 'locations.location_id' => 1 ]]
        ])->toArray();

        $total = 0;
        foreach ($out as $inv){
            $total += $inv['locations']['available'];
        }

        return [
            'success' => true,
            'total_qty' => $total
        ];
    }

    public function updateTotalQty($data)
    {
        /*$updateTotalInvData = [
            'user_id' => $this->di->getUser()->id,
            'shop_id' => (string)$data['shop_id'],
            'inventory_item_id' => (string)$data['data']['inventory_item_id'],
            'total_qty' => $totalQty['total_qty']
        ];*/
        /*$this->_collection->updateOne(
            [
                'variants.inventory_item_id' => (string)$inventoryItemId
            ],
            [
                '$set' => [
                    'variants.$.quantity' => (int)$qty
                ]
            ]
        );*/

        /*$this->_collection->updateOne(
            [
                'inventory_item_id' => (string)$inventoryItemId
            ],
            [
                '$set' => [
                    'quantity' => (int)$qty
                ]
            ]
        );*/

        //tested
        $collection = $this->_collection;
        $inventoryItemId = $data['inventory_item_id'];
        $qty = $data['total_qty'];
        $user_id = $data['user_id'];
        $shop_id = $data['shop_id'];
        return $this->handleLock('product_container', function() use ($collection, $inventoryItemId, $qty, $user_id, $shop_id) {
            return $collection->updateMany(
            [
                'user_id' => $user_id,
                'shop_id' => $shop_id,
                'inventory_item_id' => (string)$inventoryItemId
            ],
            [
                '$set' => [
                    'quantity' => (int)$qty
                ]
            ]
            );
        });
    }

    public function updateProductVariantInv($invId, $data, $userId = null): void
    {
        $this->prepare($userId);
        $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery([ 'inventory_item_id' => $invId],$userId);
        /*$update = $this->_collection->updateOne(
                        $query,
                        [ '$set' => [
                            "locations" => $data
                            ]
                        ]
                        );*/

        //tested
        $collection = $this->_collection;
        $update = $this->handleLock('product_container', function() use ($collection, $query, $data) {
            return $collection->updateOne(
            $query,
            [ '$set' => [
                "locations" => $data
                ]
            ]
            );
        });


        /* $this->di->getLog()->logContent('inventory item id = '.$data['inventory_item_id'].' Matched %d document(s) : '.$update->getMatchedCount() .'Modified %d document(s) : '.$update->getModifiedCount(),'info','Data.log');  */       
    }

    public function getInventoryItemIdsByContainerId($params)
    {
        if (!isset($params['container_ids'], $params['user_id'], $params['shop_id'])) {
            return [
                'success' => false,
                'message' => 'container_ids, shop_id and user_id are required'
            ];
        }

        $prepareResponse = $this->prepare($params['user_id']);
        if (isset($prepareResponse['success']) && !$prepareResponse['success']) {
            return $prepareResponse;
        }

        $query = [
            'user_id' => $params['user_id'],
            'shop_id' => $params['shop_id'],
            'container_id' => [
                '$in' => $params['container_ids']
                ]
            ];

        return $this->_collection->distinct("inventory_item_id", $query);
    }
}