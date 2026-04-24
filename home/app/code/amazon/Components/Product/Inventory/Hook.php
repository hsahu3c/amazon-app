<?php

namespace App\Amazon\Components\Product\Inventory;

use Exception;
use App\Core\Components\Base as Base;

class Hook extends Base
{
    public function createQueueToupdateProductInventory($webhook)
    {
        {
            try {
                $inventoryItemId = (string)$webhook['data']['inventory_item_id'];
                $sourceShopId = $webhook['shop_id'];
                $sourceMarketplace = $webhook['marketplace'];
                $userId = $webhook['user_id'] ?? $this->di->getUser()->id;
                $userId = (string)$userId;
                $date = date('d-m-Y');
                $logFile = "amazon/{$userId}/{$sourceShopId}/SyncInventorybyWebhook/{$date}.log";
                $this->di->getLog()->logContent('Webhook data = ' . print_r($webhook, true), 'info', $logFile);
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $userShops = $user_details->getShop( $sourceShopId, $userId);
                $targets = $userShops['targets'] ?? [];

                $this->di->getAppCode()->setAppTag('amazon_sales_channel');
                $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
                $appCodeArray = $appCode->toArray();
                $this->di->getAppCode()->set($appCodeArray);

                $productMarketplace = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                $options = [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => ['source_product_id' => 1]
                ];

                $query = [
                    'user_id' => $userId,
                    'shop_id' => (string)$sourceShopId,
                    'inventory_item_id' => (string)$inventoryItemId,
                    'type' => 'simple'
                ];
                $product =  $productMarketplace->getProductByQuery($query, $options);
                if (empty($product)) {
                    $this->di->getLog()->logContent('product not found, query = ' . json_encode($query), 'info', $logFile);
                    return ['success' => false, 'message' => 'product not found.'];
                }

                $dataToSend = [
                    'source' => [
                        'marketplace' => (string)$sourceMarketplace,
                        'shopId' => (string)$sourceShopId,
                    ],
                    'target' => [
                        'marketplace' => 'amazon',
                    ],
                    'operationType' => 'inventory_sync',
                    'source_product_ids' => [(string)$product[0]['source_product_id']],
                    'user_id' => $userId,
                    'limit' => 5,
                    'activePage' => 1,
                    'useRefinProduct' => true,
                    'process_type' => 'automatic',
                    'usePrdOpt' => true,
                    'projectData' => $this->getKeysForProjection(),
                ];

                $productProfile = $this->di->getObjectManager()->get('\App\Connector\Components\Profile\GetProductProfileMerge');
                $amazonSourceModel = $this->di->getObjectManager()->get('\App\Amazon\Models\SourceModel');

                // Sync inventory for each Amazon shop
                foreach ($targets as  $target) {
                    $dataToSend['target']['shopId'] = $target['shop_id'];

                    $response = $this->startSync($dataToSend, compact('productProfile', 'amazonSourceModel', 'logFile'));
                }

                return true;
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Exception = ' . print_r($e->getMessage(), true), 'error', $logFile);
                return false;
            }

            return true;
        }
    }

    private function startSync($params, $dependencies)
    {
        $productProfile = $dependencies['productProfile'] ?? null;
        $productData = $productProfile?->getProductsByProductIds($params,true);

        $uniqueKey = $productData['unique_key_for_sqs'] ?? [];
        if (!empty($uniqueKey)) {
            $dataInCache = [
                'params' => [
                    'unique_key_for_sqs' => $uniqueKey
                ]
            ];
            $productProfile->clearInfoFromCache(['data' => $dataInCache]);
            unset($productData['unique_key_for_sqs']);
        }
        unset($params['projectData']);

        $productAmazonStatus = $productData['data']['rows'][0]['edited']['status'] ?? null;

        if (!in_array($productAmazonStatus, ['Active','Inactive','Incomplete','Submitted'])) {
            $productDataEditedDoc = $productData['data']['rows'][0]['edited'] ?? [];
            $this->di->getLog()->logContent('Product not uploaded on Amazon, data = ' . json_encode(compact('productDataEditedDoc', 'params')), 'info', $dependencies['logFile']);

            return ['success' => false, 'message' => 'Product not uploaded on Amazon'];
        }

        $syncPayload = $productData;

        $syncPayload['data']['params'] = $params;
        $syncPayload['data']['operationType'] = $params['operationType'];

        $this->di->getLog()->logContent('Data sent to Amazon = ' . json_encode($syncPayload), 'info', $dependencies['logFile']);

        $amazonSourceModel = $dependencies['amazonSourceModel'] ?? null;
        $startSyncRes = $amazonSourceModel?->startSync($syncPayload);

        $message = $productProfile->getMessage($params);

        if (!empty($startSyncRes['success']) && !empty($startSyncRes['count'])) {
            return [
                'success' => $startSyncRes['success'],
                'message' => $message,
                'process_details' => $startSyncRes['count'],
                'data' => ['return_product_direct' => true]
            ];
        }
        // For backward compatibility with earlier implementations.
        return ['success' => true, 'message' => $message, 'data' => ['return_product_direct' => true]];
    }

    /**
     * Get the projection keys for product data.
     *
     * @return array
     */
    private function getKeysForProjection()
    {
        return [
            'additional_images' => 0,
            'barcode' => 0,
            'brand' => 0,
            'additional_images' => 0,
            'compare_at_price' => 0,
            'created_at' => 0,
            'description' => 0,
            'grams' => 0,
            'handle' => 0,
            'is_imported' => 0,
            'low_sku' => 0,
            'main_image' => 0,
            'price' => 0,
            'product_type' => 0,
            'seo' => 0,
            'source_created_at' => 0,
            'tags' => 0,
            'source_updated_at' => 0,
            'template_suffix' => 0,
            'variant_attributes' => 0,
            'variant_image' => 0,
            'weight' => 0,
            'weight_unit' => 0,
            'marketplace' => 0,
            'position' => 0,
            'published_at' => 0,
            'taxable' => 0,
            'variant_title' => 0
        ];
    }

    public function afterProductUpdate($event, $component, $params)
    {
        if (empty($params['updating_product'])) {
            return;
        }

        $productCollection = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollection('product_container');
        foreach ($params['updating_product'] as $products) {
            foreach ($products as $variant) {

                if (!empty($variant['db_data_enabled']['quantity'])) {
                    if (!$this->di->getConfig()->enable_bundle_products) {
                        return ['success' => false, 'message' => 'Bundle products feature is disabled.'];
                    }

                    $userId = $variant['user_id'];

                    $bundleSettings = $this->di->getConfig()->bundle_product_settings;
                    $isRestrictedPerUser = $bundleSettings->restrict_per_user ?? true;
                    $allowedUserIds = $bundleSettings->allowed_user_ids ? $bundleSettings->allowed_user_ids->toArray() : [];

                    if ($isRestrictedPerUser && !in_array($userId, $allowedUserIds, true)) {
                        return ['success' => false, 'message' => 'Bundle products not enabled for User.'];
                    }
                    $userHasBundleProd = $productCollection->findOne(
                        [
                            'user_id' => $variant['user_id'],
                            'shop_id' => $variant['shop_id'],
                            'is_bundle' => true
                        ]
                    );

                    if (empty($userHasBundleProd)) {
                        continue;
                    }

                    $message = [
                        'type' => 'full_class',
                        'class_name' => self::class,
                        'method' => 'syncInventoryForBundle',
                        'user_id' => $variant['user_id'],
                        'shop_id' => $variant['shop_id'],
                        'queue_name' => 'bundle_product_inventory_sync',
                        'appCode' => $this->di->getAppCode()->get(),
                        'appTag' => $this->di->getAppCode()->getAppTag(),
                        'marketplace' => $variant['source_marketplace'],
                        'data' => [
                            'source_product_id' => $variant['source_product_id'],
                            'container_id' => $variant['container_id'],
                        ]
                    ];

                    $this->pushMessageToQueue($message);
                }
            }
        }
    }

    public function syncInventoryForBundle($webhook)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

            $sourceShopId = $webhook['shop_id'] ?? null;
            $sourceMarketplace = $webhook['marketplace'] ?? null;
            $userId = $webhook['user_id'] ?? $this->di->getUser()->id;

            if (!$sourceShopId || !$sourceMarketplace || empty($webhook['data']['source_product_id'])) {
                return ['success' => false, 'message' => 'Missing required webhook data.'];
            }

            $logFile = "amazon/{$userId}/{$sourceShopId}/SyncInventorybyWebhookBundle/" . date('d-m-Y') . ".log";
            $this->di->getLog()->logContent('Webhook data = ' . print_r($webhook, true), 'info', $logFile);

            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $userShops = $userDetails->getShop($sourceShopId, $userId);
            $targets = $userShops['targets'] ?? [];

            $this->di->getAppCode()->setAppTag('amazon_sales_channel');
            $appCodeArray = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code']->toArray();
            $this->di->getAppCode()->set($appCodeArray);

            $productMarketplace = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
            $query = [
                'user_id' => $userId,
                'shop_id' => $sourceShopId,
                'source_product_id' => (string) $webhook['data']['source_product_id'],
                'marketplace.status' => ['$in' => ['Active', 'Inactive', 'Incomplete', 'Submitted']],
            ];

            $productList = $productMarketplace->getproductbyQuery($query, $options);

            if (empty($productList)) {
                return ['success' => false, 'message' => 'Product not found.'];
            }

            if (count($productList) > 1) {
                foreach ($productList as $product) {
                    if ($product['type'] === 'simple') {
                        $productList = [$product];
                        break;
                    }
                }
            }

            $product = $productList[0] ?? null;

            if (empty($product['source_product_id'])) {
                return ['success' => false, 'message' => 'Invalid product data.'];
            }

            $sourceProductId = $product['source_product_id'];

            $syncHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');

            foreach ($targets as $target) {
                $dataToSend = [
                    'source' => [
                        'marketplace' => $sourceMarketplace,
                        'shopId' => $sourceShopId,
                    ],
                    'target' => [
                        'marketplace' => 'amazon',
                        'shopId' => $target['shop_id'],
                    ],
                    'operationType' => 'inventory_sync',
                    'source_product_ids' => [$sourceProductId],
                    'user_id' => $userId,
                    'limit' => 10,
                    'activePage' => 1,
                    'process_type' => 'automatic',
                ];

                $this->di->getLog()->logContent('Data sent to connector = ' . print_r($dataToSend, true), 'info', $logFile);

                $syncHelper->startSync($dataToSend);
            }

            return true;
        } catch (Exception $e) {
            return json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function pushMessageToQueue($message)
    {
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        try {
            $response = $sqsHelper->pushMessage($message);
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'data' => $response];
    }
    public function processInventoryLevelsUpdateWebhook($data)
    {
        try {
            $inventoryItemId = (string)$data['data']['inventory_item_id'];
            $sourceShopId = $data['shop_id'];
            $sourceMarketplace = $data['marketplace'];
            $container_id = $data['data']['container_id'] ?? null;
            $sourceProductId = $data['data']['source_product_id'] ?? null;
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $userId = (string)$userId;
            $date = date('d-m-Y');
            $logFile = "amazon/{$userId}/{$sourceShopId}/SyncInventorybyWebhookOptimisedOne/{$date}.log";
            $this->di->getLog()->logContent('Webhook data = ' . json_encode($data), 'info', $logFile);
            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $userShops = $user_details->getShop($sourceShopId, $userId);
            $targets = $userShops['targets'] ?? [];
            $dataToSend = [
                'source' => [
                    'marketplace' => (string)$sourceMarketplace,
                    'shopId' => (string)$sourceShopId,
                ],
                'target' => [
                    'marketplace' => 'amazon',
                ],
                'operationType' => 'inventory_sync',
                'source_product_ids' => [(string)$sourceProductId],
                'user_id' => $userId,
                'limit' => 5,
                'activePage' => 1,
                'useRefinProduct' => true,
                'process_type' => 'automatic',
                'usePrdOpt' => true,
                'projectData' => $this->getKeysForProjection(),
            ];
            $betaArray = $this->di->getConfig()->get('BetaMultiAccountGroup') ? $this->di->getConfig()->get('BetaMultiAccountGroup')->toArray() : [];
            // Multi-marketplace optimization: batch all targets into a single API call
            if (count($targets) > 1 && in_array($userId, $betaArray)) {
                $batchTargets = [];
                foreach ($targets as $target) {
                    $targetShop = $user_details->getShop($target['shop_id'], $userId);
                    if ($targetShop && isset($targetShop['warehouses'][0]['marketplace_id'])
                        && ($targetShop['warehouses'][0]['status'] ?? '') == 'active') {
                        $batchTargets[] = [
                            'shop_id' => (string) $target['shop_id'],
                            'remote_shop_id' => $targetShop['remote_shop_id'],
                            'marketplace_id' => $targetShop['warehouses'][0]['marketplace_id'],
                            'home_shop_id' => $targetShop['_id'],
                        ];
                    }
                }

                if (count($batchTargets) > 1) {
                    $this->di->getLog()->logContent('Multi-marketplace batch: ' . count($batchTargets) . ' targets', 'info', $logFile);
                    $bulkInventory = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\BulkInventory');
                    $response = $bulkInventory->uploadMultiMarketplace($dataToSend, $batchTargets, $logFile);
                    $this->di->getLog()->logContent('Multi-marketplace response = ' . json_encode($response), 'info', $logFile);
                    return;
                }
            }

            // Single target or fallback: existing flow
            $amazonSourceModel = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
            foreach ($targets as $target) {
                $dataToSend['target']['shopId'] = (string) $target['shop_id'];
                $this->di->getLog()->logContent('Data To send to process = ' . json_encode($dataToSend), 'info', $logFile);
                $startSyncRes = $amazonSourceModel?->startSync($dataToSend);
            }
        } catch (\Exception $e) {
            $this->di->getLog()->logContent('Exception = ' . print_r($e->getMessage(), true), 'error', $logFile);
        }

    }
}
