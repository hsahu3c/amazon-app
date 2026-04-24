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

namespace App\Shopifyhome\Components\Product;

use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Models\SourceModel;
use App\Shopifyhome\Components\Core\Common;
use App\Connector\Models\Product as ConnectorProductModel;

use Exception;

class Hook extends Common
{
    public function createProduct($data): void{
        // set user incase requied + unset non-requied index from data
        $this->di->getObjectManager()->get(Import::class)->updateProductandKeepLocation($data['data'], $data);
    }

    public function updateProduct($data): void{
        $logdata = $data;
        
        $this->di->getLog()->logContent('PROCESS 000000 | Product | Hook | init Product Update | webhook data :'.print_r($logdata['data'], true).' Shop Url = '.json_encode($logdata['shop']),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'webhook_product_import.log');


        $openTime = microtime(true);
        $this->di->getObjectManager()->get(Import::class)->updateProductandKeepLocation($data['data'], $data);
        $endTime = microtime(true);
        $elapsedtIME = $endTime - $openTime;
        $this->di->getLog()->logContent('User Id : '. $data['user_id'].' | Id : '.$data['data'][0]['container_id'].' | v_id '.$data['data'][0]['id'] .' |  '.$elapsedtIME. ' secs','info','shopify'.DS.'global'.DS.date("Y-m-d").DS.'webhook_users_products_update_system.log');
    }

    public function removeProductWebhook($data)
    {
        $logFile = 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'remove.log';
        $log = 'shopify' . DS . $this->di->getUser()->id . DS . date("Y-m-d") . DS . 'productDelete.log';
        $this->di->getLog()->logContent('Starting removeProductWebhook Process, Data: ' . json_encode($data), 'info', $logFile);
        $this->di->getLog()->logContent('Starting Product Delete from removeProductWebhook Process: ', 'info', $log);
        if (isset($data['shop_id'], $data['data']['product_listing']['product_id'])) {
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $shopId = $data['shop_id'];
            $productId = (string)$data['data']['product_listing']['product_id'];
            $marketplace = $this->di->getObjectManager()
                ->get(Shop::class)->getUserMarkeplace() ?? 'shopify';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollection("product_container");
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            try {
                $findProductInDb = $productContainer->findOne(
                    [
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                        'container_id' => (string)$productId,
                        'visibility' => ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH,
                    ],
                    $options
                );
                if (!empty($findProductInDb) && isset($findProductInDb['source_product_id'])) {
                    $productContainer->deleteMany([
                        'container_id' => (string)$productId,
                        "user_id" => $userId,
                        'shop_id' => $shopId
                    ]);
                    $productContainer->deleteMany(
                        [
                            'container_id' => (string)$productId,
                            "user_id" => $userId,
                            'source_shop_id' => $shopId
                        ]
                    );
                    $deleteFromRefineArray['deleteArray'] = [
                        [
                            'source_product_id' => (string)$findProductInDb['source_product_id'],
                            'source_shop_id' => $shopId,
                            'type' => ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH,
                        ]
                    ];
                    $deleteFromRefineArray['user_id'] = $userId;
                    $this->di->getObjectManager()->get('App\Connector\Models\Product\Marketplace')
                        ->marketplaceDelete($deleteFromRefineArray);
                    $this->di->getLog()->logContent('Product Deleted from removeProductWebhook Process: ' . json_encode($productId), 'info', $log);
                    $eventData = [
                        'data' => $findProductInDb,
                        'user_id' => $userId,
                        'marketplace' => $marketplace,
                        'shop_id' => $shopId,
                    ];
                    $eventsManager = $this->di->getEventsManager();
                    $eventsManager->fire('application:afterProductDelete', $this, $eventData);
                    return ['success' => true, 'message' => 'Process Completed Successfully!!', 'requeue' => false];
                }
                $message = 'product data or source_product_id not found';
                $this->di->getLog()->logContent($message, 'info', $logFile);
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Exception from ShopifyHome/Components/Product/Hook.php removeProductWebhook(): ' . json_encode($e), 'info', $logFile);
            }
        } else {
            $message = 'product_id or shop_id not found in data';
            $this->di->getLog()->logContent($message, 'info', $logFile);
        }

        return ['success' => false, 'message' => $message ?? 'Something Went Wrong', 'requeue' => false];
    }

    public function createQueueToCreateProduct($data)
    {
        $action = $data['action'] ?? '';

        $useBatchImport = $this->di->getConfig()->use_batch_import;
        // $batchImportBetaUsersConfig = $this->di->getConfig()->get('batch_import_beta_users');
        // $batchImportBetaUsers = $batchImportBetaUsersConfig ? $batchImportBetaUsersConfig->toArray() : [];
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $exemptUsersForBatchImport = $this->di->getConfig()->get('batch_import_exempt_users')?->toArray() ?? [];
        if ($useBatchImport && !in_array($userId, $exemptUsersForBatchImport)) {
            $batchImport = $this->di->getObjectManager()->get(BatchImport::class);
            $handleBatchResponse = $batchImport->handleBatchImport($data, $action);
            if ($handleBatchResponse['success']) {
                return $handleBatchResponse;
            }
        }

        $sourceModel = $this->di->getObjectManager()->get(SourceModel::class);

        $processListing = $sourceModel->processListing($data, $action);
        if(isset($processListing['success']) && $processListing['success'])
        {
            if(isset($processListing['process_now']) && $processListing['process_now']) {
                //User Should Requeue if not on paid plan, only priority users go here
                $processListingFunction = $processListing['function'];
                $processListingFunctionExec = $this->di->getObjectManager()->get('App\Shopifyhome\Components\Product\Webhooks\Productupdate')
                    ->initMethod([])
                    ->index($data);
                return $processListingFunctionExec;
                
            } else {
                // requeue the job
                $data['data']['process_now'] = true;
                $data['queue_name'] = 'product_listings_update_not_listed';
                // push to queue
                $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                $rmqHelper->pushMessage($data);
            }
        }
        return $processListing;
    }

    public function formatProductInMarketplace(&$webhookData)
    {
        $productHelper = $this->di->getObjectManager()->get(Helper::class);
        $productData = [];

        /*if (isset($webhookData['is_saleschannel'], $webhookData['data']['product_listing']) && $webhookData['is_saleschannel'])
        {
            $productData = $this->formatSalesChannelProductInMarketplace($webhookData);
            if (!$productData) {
                return [];
            }
            #setting container_id to remove is_exist key saved in document, done by unsetIsExistKeyFromWebhookData()
            $webhookData['data']['product_listing']['container_id'] = $webhookData['data']['product_listing']['product_id'];
        } else {
            foreach ($webhookData['data'] as $key => $product) {
                $product['db_data'] = $webhookData['product_db_data'] ?? [];
                $formatted_product = $productHelper->formatCoreDataForVariantData($product);
                if (!empty($formatted_product))
                {
                    //unset($formatted_product['db_data']);
                    $productData[$key] = $formatted_product;
                }
            }
        }*/

        foreach ($webhookData['data'] as $key => $product) {
            $product['db_data'] = $webhookData['product_db_data'] ?? [];
            $formatted_product = $productHelper->formatCoreDataForVariantData($product);
            if (!empty($formatted_product))
            {
                //unset($formatted_product['db_data']);
                $productData[$key] = $formatted_product;
            }
        }
        
        $productData = $productHelper->formatVariantAttributeValuesByWebhook($productData, $webhookData['shop_id']);

        return ['pushToMaindb' => $productData];
    }


    public function formatSalesChannelProductInMarketplace($webhookData)
    {
        #Setting Basic Required Keys
        $productId = $webhookData['data']['id'] ?? $webhookData['data']['product_listing']['product_id'];
        $appCode = $webhookData['app_code'] ?? ($webhookData['app_codes'][0] ?? "");
        $marketplace = $webhookData['marketplace'] ?? 'shopify';
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $userShop = $user_details->getShop($webhookData['shop_id'], $webhookData['user_id'] ?? $this->di->getUser()->id);

        $shopifySourceModel = $this->di->getObjectManager()->get(SourceModel::class);
        $response = $shopifySourceModel->newProductListingCreate($productId, $appCode, $marketplace, $userShop);
        /*if (empty($webhookData['product_db_data']['db_data']))
        {
            $response = $shopifySourceModel->newProductListingCreate($productId, $appCode, $marketplace, $userShop);

        }
        else
        {
            //$response = $shopifySourceModel->newProductListingUpdate($webhookData);
            $response = $shopifySourceModel->productListingUpdate($webhookData);
        }*/
        return $response;
    }

}
