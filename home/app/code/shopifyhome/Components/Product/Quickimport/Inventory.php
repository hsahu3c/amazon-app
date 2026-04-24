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

use App\Shopifyhome\Components\Product\Quickimport\Route\Requestcontrol;
use App\Shopifyhome\Components\Product\Inventory\Utility;
use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Core\Common;

class Inventory extends Common
{
    private $userId;

    private $sqsData;

    private $inventoryItemIdsChunk = [];

    public const CHUNK_SIZE = 50;

    public function importInventory($userId, $sqsData)
    {
        $this->userId = $userId;
        $this->sqsData = $sqsData;

        $terminate = false;
        $totalTime = 0;
        $openTime = microtime(true);

        $inventoryUpdateResponse = [];
        do {
            $remoteResponse = $this->fetchInventoryFromShopify();

            $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0302 | Inventory | Fetch Inventory From Remote Response : '.print_r($remoteResponse, true));

            if(isset($remoteResponse['success']) && $remoteResponse['success'])
            {
                $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0302 | Inventory | Fetched Inventory From Shopify.');

                $newProInv = [];
                foreach($remoteResponse['data'] as $productInv) {
                    $newProInv[$productInv['inventory_item_id']][] = $productInv;
                }

                $productInvUtility = $this->di->getObjectManager()->get(Utility::class);
                $quickImport = $this->di->getObjectManager()->get(Import::class);

                foreach($newProInv as $invId => $productInv) {
                    $this->parseResponse($quickImport->updateProductInventory($this->userId, (string)$invId, $productInvUtility->typeCastElement($productInv)), $inventoryUpdateResponse); 
                }

                $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0303 | Inventory | Inventory Updated Successfully : '.print_r($inventoryUpdateResponse, true));
            }
            else
            {
                if(isset($remoteResponse['msg']) && $remoteResponse['msg']=='completed') {
                    
                    $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0302 | Inventory | All Inventory Updated Successfully.');

                    return ['success' => true, 'message' => 'location imported.', 'completed' => true];
                }
                $message = $remoteResponse['msg'] ?? $remoteResponse['message'];
                $realMsg = '';
                if(is_string($message)) {
                    $realMsg = $message;
                } elseif(is_array($message)) {
                    if(isset($message['errors'])) $realMsg = $message['errors'];
                }
                if(!empty($realMsg) && (strpos($realMsg, "Reduce request rates") || strpos($realMsg, "Try after sometime"))) {
                    $this->di->getObjectManager()->get(Requestcontrol::class)->pushToQueue($this->sqsData, time() + 8);

                    $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0302 | API throttle, pushing data again in sqs.');

                    return ['success' => true, 'message' => 'API throttle, pushing data again in sqs.'];
                }
                $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0302 | Error obtaining Data from Shopify due to : '.print_r($remoteResponse, true));
                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                return ['success' => false, 'message' => 'Error obtaining Data from Shopify.'];
            }


            $endTime = microtime(true);

            $elapsedtIME = $endTime - $openTime;
            $totalTime += $elapsedtIME;

            $terminate = $this->canTerminateExecution($elapsedtIME, $totalTime);

            if($terminate)
            {
                if(isset($this->sqsData['cursor']) || !isset($this->sqsData['is_last'])) {
                    $this->di->getObjectManager()->get(Requestcontrol::class)->pushToQueue($this->sqsData);

                    $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0304 | Inventory | Inventory Updated Partially, So for remaining products request pushed to queue.'.print_r($this->sqsData, true));

                    return ['success' => true, 'requeue' => true, 'completed' => false];
                }
                $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0305 | Inventory | All Inventory Updated Successfully.');
                return ['success' => true, 'message' => 'location imported.', 'completed' => true];
            }
        }
        while (!$terminate);
    }

    private function fetchInventoryFromShopify()
    {
        $remoteShopId = $this->sqsData['data']['remote_shop_id'];

        $filters = ['shop_id'=> $remoteShopId];

        if(isset($this->sqsData['cursor'])) {
            $filters['next'] = $this->sqsData['cursor'];
        }
        elseif(!isset($this->sqsData['is_last'])) {
            $inv_item_ids = $this->fetchInvItemIdsChunk();
            if(!empty($inv_item_ids)) {
                $filters['inventory_item_ids'] = $inv_item_ids;
            } else {
                return ['success'=>false, 'msg'=>'completed'];    
            }
        }
        else {
            return ['success'=>false, 'msg'=>'completed'];
        }

        $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0301 | Inventory | Filters for fetching.'.print_r($filters, true));

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();

        $inventoryData = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target)
            ->call('/inventory', [], $filters, 'GET');

        if(isset($inventoryData['cursors']['next'])) {
            $this->sqsData['cursor'] = $inventoryData['cursors']['next'];
        }
        else {
            if(isset($this->sqsData['cursor'])) {
                unset($this->sqsData['cursor']);
            }
        }

        return $inventoryData;
    }

    private function fetchInvItemIdsChunk()
    {
        if(empty($this->inventoryItemIdsChunk))
        {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $mongo->setSource("product_container");
            $collection = $mongo->getPhpCollection();

            $response = $collection->distinct(
                'inventory_item_id', ['$and'=>[
                ['user_id' => $this->userId],
                ["add_locations" => true]
                // ['locations' => 'import_via_quickimport']
            ]]);

            $this->inventoryItemIdsChunk = array_chunk($response, self::CHUNK_SIZE);
            
            $arrayChunks = $this->inventoryItemIdsChunk;

            foreach ($arrayChunks as $key => $value) {
                $return = $value;
                unset($this->inventoryItemIdsChunk[$key]);
                if(count($this->inventoryItemIdsChunk) == 0) {
                    $this->sqsData['is_last'] = true;
                }

                break;
            }

            return $return;
        }
        $arrayChunks = $this->inventoryItemIdsChunk;
        foreach ($arrayChunks as $key => $value) {
            $return = $value;
            unset($this->inventoryItemIdsChunk[$key]);
            if(count($this->inventoryItemIdsChunk) == 0) {
                $this->sqsData['is_last'] = true;
            }

            break;
        }
        return $return;
    }    

    private function canTerminateExecution($currentTime, $totalTime)
    {
        $allocatedTime = $this->sqsData['data']['sqs_timeout'];

        if(($totalTime + (2 * $currentTime)) > $allocatedTime) return true;
        return false;
    }

    private function parseResponse($response, &$parsedResponse): void
    {
        $arr = ['Matched', 'Modified', 'Upserted'];
        foreach ($arr as $key) {
            $method = "get{$key}Count";
            if(isset($parsedResponse[$key])) {
                $parsedResponse[$key] += $response->$method();
            } else {
                $parsedResponse[$key] = $response->$method();
            }            
        }

        // var_dump($response->getMatchedCount());
        // var_dump($response->getModifiedCount());
        // var_dump($response->getUpsertedCount());
    }

    public function getImportHelper()
    {
        return $this->di->getObjectManager()->get(Helper::class);
    }
}