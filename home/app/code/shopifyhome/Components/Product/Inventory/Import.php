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

use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Core\Common;

class Import extends Common
{
    public const MARKETPLACE_CODE = 'shopify';

    public function _construct()
    {
        /* $this->_callName = 'shopify_core';
        parent::_construct(); */
        //$this->di->getLog()->logContent('Home Shopify : indside constructor of Import.php','info','shopify_product_import.log');
    }

    public function getProductInventory($locations, $remoteShopId = 0)
    {
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target, true)
            ->call('/inventory',[],['shop_id'=> $remoteShopId, 'inventory_item_ids' => $locations], 'GET');

        if(empty($remoteResponse['data'])){

            $this->di->getLog()->logContent('', 'info', 'shopify'.DS.'temp_inv_errorFlag_'.$this->di->getUser()->id.'.log');

            $this->di->getLog()->logContent('CRITICAL 00000 | Inventory\Import | getProductInventory | WHOLE CHUNK FAILED | Probable Error Cause : Product Delete Webhook Did not worked :(  | Input Data : '.json_encode(['shop_id'=> $remoteShopId, 'inventory_item_ids' => $locations]).' | Response From Remote : '.print_r($remoteResponse, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'inventory'.DS.date("Y-m-d").DS.'import_error.log');

            return ['success' => true, 'message' => 'location import error'];
        }

        if(isset($remoteResponse['success']) && $remoteResponse['success']) {
            foreach($remoteResponse['data'] as $productInv)
                {
                    $newPro[$productInv['inventory_item_id']][] = $productInv;
                }

            foreach($newPro as $id => $productInv){
                $productInv = $this->di->getObjectManager()->get(Utility::class)->typeCastElement($productInv);
                $this->di->getObjectManager()->get(Data::class)->updateProductVariantInv((string)$id, $productInv); 
            }

            return ['success' => true, 'message' => 'location imported'];
        }
        return [
            'success' => false,
            'message' => 'Error obtaining Data from Shopify.'
        ];

        // perform db operations
    }

    public function getInventoryByInventoryItemId($inventoryItemIds, $remoteShopId)
    {
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target, true)
            ->call('/inventory',[],['shop_id'=> $remoteShopId, 'inventory_item_ids' => $inventoryItemIds], 'GET');

        if (empty($remoteResponse['data'])) {
            return [
                'success' => false,
                'message' => 'No inventory_item_ids found for given inventory_item_ids!'
            ];
        }

        return $remoteResponse;
    }

    public function getInventoryByContainerId($params)
    {
        if (!isset($params['container_ids'], $params['remote_shop_id'], $params['shop_id'])) {
            return [
                'success' => false,
                'message' => 'container_ids, shop_id and remote_shop_id are required'
            ];
        }

        $remoteShopId = $params['remote_shop_id'];
        $containerIds = $params['container_ids'];

        if (!is_array($containerIds)) {
            $containerIds = str_contains((string) $containerIds, ',')
                ? explode(',', (string) $containerIds)
                : [$containerIds];
        }

        $getInventoryIdData = [
            'user_id' => $this->di->getUser()->id,
            'shop_id' => $params['shop_id'],
            'container_ids' => $containerIds
        ];
        $inventoryItemIds = $this->di->getObjectManager()
                ->get(Data::class)
                ->getInventoryItemIdsByContainerId($getInventoryIdData);
        if (empty($inventoryItemIds)) {
            return [
                'success' => false,
                'message' => 'Verify the container_ids. No inventory_item_ids found for given container_ids!'
            ];
        }

        return $this->getInventoryByInventoryItemId($inventoryItemIds, $remoteShopId);
    }

    public function getInventoryByContainerIdAndSyncOnTargets($params)
    {
        if (!isset($params['container_ids'], $params['shop_id'])) {
            return [
                'success' => false,
                'message' => 'container_ids and shop_id are required'
            ];
        }

        if (!empty($params['use_queue'])) {
            return $this->enqueueInventorySyncByContainerIdAndSyncOnTargets($params);
        }

        $shops = $this->di->getUser()->shops ?? [];
        $shopifyShop = array_filter($shops, fn($shop) => $shop['_id'] == $params['shop_id']);
        $shopifyShop = reset($shopifyShop);

        $remoteShopId = $shopifyShop['remote_shop_id'] ?? false;
        if (!$remoteShopId) {
            return ['success' => false, 'message' => 'remote_shop_id not found'];
        }

        $params['remote_shop_id'] = $remoteShopId;
        $getInventoryByContainerIdResponse = $this->getInventoryByContainerId($params);
        if (!$getInventoryByContainerIdResponse['success']) {
            return $getInventoryByContainerIdResponse;
        }

        $shopifyInventoriesData = $getInventoryByContainerIdResponse['data'];

        $shopifyInventoryHook = $this->di->getObjectManager()->get(Hook::class);

        $basePrepareData = [
            'user_id' => $this->di->getUser()->id,
            'marketplace' => 'shopify',
            'shop_id' => $shopifyShop['_id'],
        ];

        $errorInUpdateInv = false;
        $errorInUpdateInvMsg = [];
        foreach ($shopifyInventoriesData as $inventoryData) {
            $preparedData = [...$basePrepareData, 'data' => $inventoryData];
            $updateInvResponse = $shopifyInventoryHook
                ->updateProductInventory($preparedData,  $this->di->getUser()->id);
            if (isset($updateInvResponse['success']) && !$updateInvResponse['success']) {
                $errorInUpdateInv = true;
                $errorInUpdateInvMsg[] = $updateInvResponse['message'] ?? $updateInvResponse['msg'] ?? 'Error';
            }
        }

        if ($errorInUpdateInv) {
            return [
                'success' => false,
                'message' => json_encode($errorInUpdateInvMsg)
            ];
        }

        $targets = $shopifyShop['targets'] ?? [];

        if (isset($params['manual']) && $params['manual']) {
            $basePrepareData['process_type'] = 'manual';
        }

        $hasError = false;
        $hasSuccess = false;
        foreach ($targets as $target) {
            $class = '\App\\' . ucfirst((string) $target['code']) . '\Components\Product\Inventory\Hook';
            if (!class_exists($class)) {
                $class = '\App\\' . ucfirst((string) $target['code']) . "home" . '\Components\Product\Inventory\Hook';
                if (!class_exists($class)) {
                    continue;
                }
            }

            if (!method_exists($class, 'createQueueToupdateProductInventory')) {
                continue;
            }

            $targetObject =  $this->di->getObjectManager()->get($class);
            foreach ($shopifyInventoriesData as $inventoryData) {
                $preparedData = [...$basePrepareData, 'data' => $inventoryData];
                $createQueueResponse = $targetObject->createQueueToupdateProductInventory($preparedData);
                if (isset($createQueueResponse['success']) && !$createQueueResponse['success']) {
                    $hasError = true;
                } elseif ($createQueueResponse === true) {
                    $hasSuccess = true;
                }
            }
        }

        if ($hasError) {
            $message = 'Inventories imported but';

            if ($hasSuccess) {
                $message .= ' something went wrong in syncing!';
                $success = false;
            } else {
                $message .= ' some inventories encountered syncing errors!';
                $success = false;
            }
        } else {
            $message = 'Inventories imported successfully and syncing initiated';
            $success = true;
        }

        return ['success' => $success, 'message' => $message];
    }

    public  function enqueueInventorySyncByContainerIdAndSyncOnTargets($params)
    {
        if (!isset($params['container_ids'], $params['shop_id'])) {
            return [
                'success' => false,
                'message' => 'container_ids and shop_id are required'
            ];
        }

        $containerIds = $params['container_ids'];
        if (!is_array($containerIds)) {
            $containerIds = str_contains((string) $containerIds, ',')
                ? explode(',', (string) $containerIds)
                : [$containerIds];
        }

        $sqsHelper = $this->di->getObjectManager()->get(\App\Core\Components\Message\Handler\Sqs::class);

        foreach ($containerIds as $containerId) {
            $message = [
                'type' => 'full_class',
                'class_name' => \App\Shopifyhome\Components\Product\Inventory\Import::class,
                'method' => 'getInventoryByContainerIdAndSyncOnTargets',
                'queue_name' => 'admin_panel_sync_actions',
                'user_id' => $this->di->getUser()->id,
                'shop_id' => $params['shop_id'],
                'container_ids' => $containerId,
            ];
            try {
                $sqsHelper->pushMessage($message);
            } catch (\Exception $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }
        return ['success' => true, 'message' => 'Inventory sync initiated'];
    }
}
