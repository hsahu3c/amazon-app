<?php

namespace App\Shopifyhome\Components\Product;

use Exception;
use App\Connector\Models\QueuedTasks;
use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Product\Vistar\Route\Requestcontrol;
use App\Shopifyhome\Components\Product\Vistar\Data;
use App\Core\Models\User;
use Phalcon\Events\Event;
use App\Shopifyhome\Components\Core\Common;
use App\Connector\Components\Route\ProductRequestcontrol;
use App\Shopifyhome\Components\Product\Bulk\Bulkimport as ShopifyBulkImport;
use App\Plan\Models\Plan;
use MongoDB\BSON\UTCDateTime;

class BatchImport extends Common
{
    public const BATCH_IMPORT_COLLECTION = 'batch_import_product_container';

    public const BATCH_IMPORT_TIME_THRESHOLD = '15 minutes';

    public const BATCH_IMPORT_PRODUCT_COUNT_THRESHOLD = 50;

    public const BATCH_IMPORT_CONTAINER_ID_CHUNK = 2000;

    public const BATCH_IMPORT_DIRECT_PROCESSING_CHUNK = 100;

    public const MAX_VARIANT_COUNT_PROCESSABLE = 100;

    public const BATCH_PRODUCT_DIRECT_IMPORT_QUEUE = 'shopify_batch_product_direct_import';

    public const BATCH_PRODUCT_DIRECT_IMPORT_PROCESS_CODE = 'batch_product_direct_import';

    public const MAX_VARIANT_ALLOWED_FOR_BATCH_IMPORT = 100;

    public $baseLogPath = 'shopify-batch-product-import';

    public $batchProductDirectImportLogPath = 'shopify-batch-product-direct-import';

    public const BATCH_IMPORT_UNASSIGN_PRODUCT_IDS_CHUNK = 50;

    public const CACHE_KEY_2048_VARIANT_SUPPORT_USERS = '2048_variant_support_users';

    public const UNASSIGN_PRODUCT_IN_BULK_QUEUE = 'shopify_unassign_product_in_bulk';

    public const MAX_PROCESSING_TIME_ALLOWED_FOR_EACH_CHUNK = 10; // 10 seconds

    public $logFile = null;

    public function handleBatchImport($sqsData, $webhook)
    {
        $response = $this->getMethodForBatchImport($sqsData, $webhook);
        if (empty($response['method'])) {
            return $response;
        }

        $method = $response['method'] ?? '';

        if($method == 'productListingCreate' && !empty($sqsData['user_id']) && $this->isProductImportRestricted($sqsData['user_id'])) {
            $this->checkReauthAndUnassignProduct([
                'user_id' => $sqsData['user_id'],
                'shop_id' => $sqsData['shop_id'],
                'container_id' => $sqsData['data']['product_listing']['product_id'] ?? null,
            ]);
            // Returning true as if return false then in that case backup cases might got called
            return ['success' => true, 'message' => 'Product Importing cannot be proceeded due to plan restriction'];
        }

        return match ($method) {
            'productListingCreate' => $this->handleListingsCreateWebhook($sqsData),
            'productListingUpdate' => $this->handleListingsUpdateWebhook($sqsData),
            default => ['success' => false, 'message' => 'Provided method invalid.'],
        };
    }

    public function getMethodForBatchImport($data, $webhook)
    {
        if (isset($data['data']['product_listing']['product_id'])) {
            $containerId = $data['data']['product_listing']['product_id'];
            $userId = $data['user_id'];
            $shopId = $data['shop_id'];

            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');

            $mongoCollection = $mongo->getCollection("product_container");
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array'], 'projection' => ['container_id' => 1]];

            $productsData = $mongoCollection->findOne(
                [
                    'user_id' => $userId,
                    'shop_id' => $shopId,
                    'container_id' => (string) $containerId,
                    'source_marketplace' => 'shopify'

                ],
                $options
            );

            $methodToProcess = null;
            $listingsCreateAllowedWebhooks = [
                'product_listing_create',
                'product_listings_add',
                'product_listings_update'
            ];

            if (!empty($productsData)) {
                $methodToProcess = 'productListingUpdate';
            } elseif (in_array($webhook, $listingsCreateAllowedWebhooks)) {
                $methodToProcess = 'productListingCreate';
            }

            if (!is_null($methodToProcess)) {
                return [
                    'success' => true,
                    'method' => $methodToProcess,
                ];
            }

            return ['success' => false, 'message' => 'method not found for update.'];
        }

        return ['success' => false, 'message' => "product_id key not found"];
    }

    public function handleListingsCreateWebhook($data)
    {
        $containerId = $data['data']['product_listing']['product_id'];
        $user_id = $data['user_id'];
        $shop_id = $data['shop_id'];
        $title = $data['data']['product_listing']['title'];

        $this->logFile = $this->baseLogPath . DS . $user_id . DS . 'listings-create' . DS . date('Y-m-d') . '.log';

        $userFilter = [
            'user_id' => $user_id,
            'shop_id' => $shop_id,
            'type' => 'listings_add',
            'source_marketplace' => 'shopify',
            'container_id' => (string) $containerId,
        ];

        $productData = [
            'title' => $title,
            'updated_at' => date('c'),
        ];
        if (!empty($data['data']['product_listing']['images'][0]['src'])) {
            $productData['image'] = $data['data']['product_listing']['images'][0]['src'];
        }

        $shopifyVariants = $data['data']['product_listing']['variants'] ?? [];
        if (!empty($shopifyVariants)) {
            $variants = [];
            foreach ($shopifyVariants as $variant) {
                $variants[] = [
                    'id' => (string) $variant['id'],
                    'title' => $variant['title']
                ];
            }

            $productData['variants'] = $variants;
            if (count($variants) == 1 && $variants[0]['title'] == 'Default Title') {
                unset($productData['variants']);
            }
        }

        //@to_do - above implementation can override already existings variants
        $batchProductImportCollection = $this->getMongoCollection(self::BATCH_IMPORT_COLLECTION);
        $error = null;
        $setOnInsertData = [
            'created_at' => date('c'),
            'image' => $data['data']['product_listing']['images'][0]['src'] ?? '',
        ];
        if (isset($productData['image'])) {
            unset($setOnInsertData['image']);
        }

        try {
            $queryResponse = $batchProductImportCollection->updateOne(
                $userFilter,
                [
                    '$setOnInsert' => $setOnInsertData,
                    '$set' => $productData
                ],
                ['upsert' => true]
            );

            // Check Reauth Required if yes try to send mail
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shopData = $userDetails->getShop($shop_id, $user_id);
            if (!empty($shopData) && isset($shopData['reauth_required']) && $shopData['reauth_required']) {
                $this->di->getObjectManager()->get('App\Shopifyhome\Components\Shop\Shop')->sendMailForReauthRequired($shopData);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        if ($error) {
            //log here for error
            $this->logger('exception in listings create, error', $error);
            return ['success' => false, 'message' => $error];
        }

        //log here something related to $queyResponse
        $this->logger('listings create db response', $queryResponse);

        return ['success' => true, 'message' => 'added to batch import collection.'];
    }

    public function handleListingsUpdateWebhook($data)
    {
        $productContainer = $this->getMongoCollection('product_container');
        // $options = ["typeMap" => ['root' => 'array', 'document' => 'array'],];
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array'],
            'projection' => ['user_id' => 1, 'shop_id' => 1, 'container_id' => 1, 'source_product_id' => 1]
        ];
        $containerId = $data['data']['product_listing']['product_id'];
        $user_id = $data['user_id'];
        $shop_id = $data['shop_id'];
        $title = $data['data']['product_listing']['title'];

        $this->logFile = $this->baseLogPath . DS . $user_id . DS . 'listings-update' . DS . date('Y-m-d') . '.log';

        // $variantIds = array_column($data['data']['product_listing']['variants'], 'id');
        $shopifyVariants = $data['data']['product_listing']['variants'] ?? [];
        $variantIds = [];
        foreach ($shopifyVariants as $variant) {
            $variantIds[] = (string)$variant['id'];
        }

        $allVariantsDbData = $productContainer->find(
            [
                'user_id' => $user_id,
                'shop_id' => $shop_id,
                'container_id' => (string) $containerId,
                'source_marketplace' => 'shopify',
                'source_product_id' => ['$in' => $variantIds],
            ],
            $options
        )->toArray();
        if (!empty($allVariantsDbData) && count($allVariantsDbData) == count($shopifyVariants)) {
            return ['success' => false, 'message' => 'all variants already exists.'];
        }

        if ($this->isProductImportRestricted($user_id)) {
            $this->checkReauthAndUnassignProduct([
                'user_id' => $user_id,
                'shop_id' => $shop_id,
                'container_id' => $data['data']['product_listing']['product_id'] ?? null,
            ]);
            // Returning true as if return false then in that case backup cases might got called
            return ['success' => true, 'message' => 'Product Importing cannot be proceeded due to plan restriction'];
        }

        $userFilter = [
            'user_id' => $user_id,
            'shop_id' => $shop_id,
            'source_marketplace' => 'shopify',
            'container_id' => (string) $containerId,
        ];
        $setOnInsertData = [
            'created_at' => date('c'),
            'type' => 'listings_update',
        ];
        $setData = [
            'title' => $title,
            'updated_at' => date('c'),
            'image' => $data['data']['product_listing']['images'][0]['src'] ?? '',
        ];

        $allArrayFilters = [];
        $allArrayFilterSet = [];

        $useArrayFilter = false;
        $batchProductImportCollection = $this->getMongoCollection(self::BATCH_IMPORT_COLLECTION);

        $pushData = [];

        $alreadyExistingSourceProductIds = array_column($allVariantsDbData, 'source_product_id');
        $newShopifyVariants = array_filter($shopifyVariants, fn($variant) => !in_array($variant['id'], $alreadyExistingSourceProductIds));
        // $shopifyVariants = $data['data']['product_listing']['variants'] ?? [];
        if (!empty($newShopifyVariants)) {
            $variants = [];
            foreach ($newShopifyVariants as $index => $variant) {
                $hasArrayFilter = false;
                $variantData = $batchProductImportCollection->findOne(
                    [
                        'user_id' => $user_id,
                        'shop_id' => $shop_id,
                        'source_marketplace' => 'shopify',
                        'container_id' => (string) $containerId,
                        'variants.id' => (string) $variant['id']
                    ]
                );
                if (!empty($variantData)) {
                    $useArrayFilter = true;
                    $hasArrayFilter = true;
                    $arrayFilterSet = [
                        "variants.$[variant{$index}].title" => $variant['title'],
                    ];
                    $arrayFilter = [
                        "variant{$index}.id" => (string) $variant['id'],
                    ];
                } else {
                    $variants[] = [
                        'id' => (string)$variant['id'],
                        'title' => $variant['title']
                    ];
                }

                if ($hasArrayFilter) {
                    $allArrayFilters[] = $arrayFilter;  // Add each filter as a separate element
                    $allArrayFilterSet = array_merge($allArrayFilterSet, $arrayFilterSet);
                }
            }

            $pushData['variants'] = $variants;
        }

        $options = ['upsert' => true];

        try {
            $queryResponse = $batchProductImportCollection->updateOne(
                $userFilter,
                [
                    '$setOnInsert' => $setOnInsertData,
                    '$set' => $setData,
                    '$push' => ['variants' => ['$each' => $pushData['variants'] ?? []]],
                ],
                $options
            );
            if ($useArrayFilter) {
                $options['arrayFilters'] = $allArrayFilters;
                $queryResponse = $batchProductImportCollection->updateOne(
                    $userFilter,
                    [
                        '$set' => $allArrayFilterSet,
                    ],
                    $options
                );
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            //log here for error
            $this->logger('exception in listings update, error', $error);
            return ['success' => false, 'message' => $error];
        }

        $this->logger('listings update db response', $queryResponse);
        // Check Reauth Required if yes try to send mail
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shopData = $userDetails->getShop($shop_id, $user_id);
        if (!empty($shopData) && isset($shopData['reauth_required']) && $shopData['reauth_required']) {
            $this->di->getObjectManager()->get('App\Shopifyhome\Components\Shop\Shop')->sendMailForReauthRequired($shopData);
        }
        return ['success' => true, 'message' => 'added to batch import collection.'];
    }

    public function getUsersForListingsAddBatchImport()
    {
        //remove users already in batch import queue collection
        $batchProductImportCollection = $this->getMongoCollection(self::BATCH_IMPORT_COLLECTION);
        $thresholdTime = self::BATCH_IMPORT_TIME_THRESHOLD;
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array'],
            'projection' => ['user_id' => 1, 'shop_id' => 1, '_id' => 0],
        ];
        $usersAnsShopsToSyncForTimeThreshold = $batchProductImportCollection->find(
            [
                'source_marketplace' => 'shopify',
                // 'type' => 'listings_add',//comment this to process for both
                'created_at' => ['$lte' => date('c', strtotime("- {$thresholdTime}"))],
            ],
            $options
        )->toArray();
        $usersAnsShopsToSyncForTimeThreshold = array_values(
            array_unique($usersAnsShopsToSyncForTimeThreshold, SORT_REGULAR)
        );

        $usersToSyncForTimeThreshold = array_column($usersAnsShopsToSyncForTimeThreshold, 'user_id');

        $usersAnsShopsForProductLimitAggregation = [
            [
                '$match' => [
                    'user_id' => ['$nin' => $usersToSyncForTimeThreshold],
                    // 'type' => 'listings_add',
                    'source_marketplace' => 'shopify',
                ]
            ],
            [
                '$group' => [
                    '_id' => [
                        'user_id' => '$user_id',
                        'shop_id' => '$shop_id',
                    ],
                    'product_count' => ['$sum' => 1],
                ]
            ],
            [
                '$match' => [
                    'product_count' => ['$gt' => self::BATCH_IMPORT_PRODUCT_COUNT_THRESHOLD],
                ]
            ],
            [
                '$project' => [
                    '_id' => 0,
                    'user_id' => '$_id.user_id',
                    'shop_id' => '$_id.shop_id',
                ],
            ],
        ];
        $usersAndShopsToSyncForProductLimit = $batchProductImportCollection->aggregate(
            $usersAnsShopsForProductLimitAggregation,
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        )->toArray();
        return array_merge($usersAnsShopsToSyncForTimeThreshold, $usersAndShopsToSyncForProductLimit);
    }

    public function pushToBatchImportQueue($data)
    {
        if (!isset($data['user_id']) || !isset($data['shop_id'])) {
            return ['success' => false, 'message' => 'user_id and shop_id required'];
        }

        $this->logFile = $this->baseLogPath . DS . $data['user_id'] . DS . 'push-to-batch-import-queue' . DS . date('Y-m-d') . '.log';

        $this->logger('starting batch import for', $data);

        $queueDataRes = $this->prepareQueueData($data);
        if (!$queueDataRes['success']) {
            return $queueDataRes;
        }

        $queueData = $queueDataRes['data'];
        if (!$this->canInitiateBatchImport($queueData)) {
            $this->logger('Batch Product Import is already under progress for', $data);
            return ['success' => false, 'message' => 'Batch Product Import is already under progress.'];
        }

        $pushMessageRes = $this->pushMessageToQueue($queueData);
        if (!$pushMessageRes['success']) {
            $this->logger('Push Message Failed', $pushMessageRes);
            $this->deleteCanInitiateEntry($queueData);
            //delete initiated queue entry
        }

        return $pushMessageRes;
    }

    public function prepareQueueData($data)
    {
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shopifyShop = $user_details->getShop($data['shop_id'], $data['user_id']);

        if (empty($shopifyShop)) {
            $this->logger('shopify shop not found', $data);
            return ['success' => false, 'message' => 'Shopify shop not found.'];
        }

        $appCodes = $this->di->getAppCode()->get();
        $appCode = $appCodes['shopify'];

        $preparedQueueData =  [
            'type' => 'full_class',
            'class_name' => \App\Shopifyhome\Components\Product\BatchImport::class,
            'method' => 'initiateBatchImportForListingsAdd',
            'user_id' => $data['user_id'],
            'shop_id' => $data['shop_id'],
            'queue_name' => 'shopify_batch_product_import',
            'appCode' => $appCode,
            'appTag' => $this->di->getAppCode()->getAppTag(),
            'own_weight' => 100,
            'data' => [
                'operation' => '',
                'user_id' => $data['user_id'],
                'app_code' => $appCode,
                'individual_weight' => '',
                'feed_id' => '',
                'webhook_present' => '',
                'shop' => $shopifyShop,
                'total_count' => '',
                'temp_importing' => '',
                'isImported' => true,
                // 'target_marketplace' => '',
                // 'target_shop_id' => '',
                'limit' => '',
                'remote_shop_id' => $shopifyShop['remote_shop_id'],
                'add_notification' => false,
            ],
            'app_code' => $appCodes
        ];

        // $moveUsersInDifferentSqsForBatchProductImport = $this->di->getConfig()->get('route_users_to_separate_sqs_for_batch_import') ? $this->di->getConfig()->get('route_users_to_separate_sqs_for_batch_import')->toArray() : [];
        // if (in_array($data['user_id'], $moveUsersInDifferentSqsForBatchProductImport)) {
        //     $preparedQueueData['queue_name'] = 'test_batch_product_import';
        //     $preparedQueueData['handle_added'] = 1;
        // }

        if (isset($shopifyShop['apps'])) {
            foreach ($shopifyShop['apps'] as $app) {
                if (isset($app['code'], $app['webhooks']) && $app['code'] == $appCode) {
                    foreach ($app['webhooks'] as $webhooks) {
                        if (isset($webhooks['code']) && in_array(
                            $webhooks['code'],
                            ['product_update', 'product_delete']
                        )) {
                            $preparedQueueData['data']['webhook_present'] = true;
                            break;
                        }
                    }

                    break;
                }
            }
        }

        return ['success' => true, 'data' => $preparedQueueData];
    }

    public function canInitiateBatchImport($data)
    {
        $insertData = [
            '_id' => $data['user_id'] . '_' . $data['shop_id'],
            'user_id' => $data['user_id'],
            'shop_id' => $data['shop_id'],
            'marketplace' => 'shopify',
            'created_at' => date('c')
        ];
        $canImport = false;
        $batchImportQueueCollection = $this->getMongoCollection('batch_import_product_queue');
        try {
            $resp = $batchImportQueueCollection->insertOne($insertData);
            if ($resp->getInsertedCount() > 0) {
                $canImport = true;
            }
        } catch (Exception $e) {
            $e->getMessage();
        }

        return $canImport;
    }

    public function deleteCanInitiateEntry($data): void
    {
        $batchImportQueueCollection = $this->getMongoCollection('batch_import_product_queue');
        $query = ['_id' => $data['user_id'] . '_' . $data['shop_id']];
        $batchImportQueueCollection->deleteOne($query);
    }

    public function initiateBatchImportForListingsAdd($data)
    {
        $shopId = $data['shop_id'];
        $userId = $data['user_id'];
        // Check to handle store close or uninstall user cases
        if(!$this->checkUserShopActive($userId, $shopId)) {
            $this->logFile = $this->baseLogPath . DS  . 'restrict-batch-product-import' . DS . date('Y-m-d') . '.log';
            $this->deleteCanInitiateEntry($data);
            $this->removeEntryFromBatchImportContainer($data);
            $this->logger('User shop is not active or store is closed, skipping batch import', $data);
            return true;
        }
        $batchImportDirectProcessingUsers = $this->di->getConfig()->get('skip_direct_batch_import_for_users') ? $this->di->getConfig()->get('skip_direct_batch_import_for_users')->toArray() : [];
        if (!in_array($userId, $batchImportDirectProcessingUsers)) {
            $batchImportDirectProcessing = $this->initiateDirectProcessing($shopId);
            if (isset($batchImportDirectProcessing['requeue']) && $batchImportDirectProcessing['requeue']) {
                $this->pushMessageToQueue($data);
            }
        } else {
            $containerIds = $this->getContainerIdsToSync($userId, $shopId);
            $queryFilter = $this->prepareQueryFilterFromContainerIds($containerIds);
            $createBulkOpResp = $this->createBulkOperation($queryFilter, $shopId, $data, $containerIds);

            //if createBulkOpResp is not successful, then delete the can initiate entry
            if (isset($createBulkOpResp['success']) && !$createBulkOpResp['success']) {
                $this->deleteCanInitiateEntry($data);
                return true;
            }

            if (isset($createBulkOpResp['requeue']) && $createBulkOpResp['requeue']) {
                $this->pushMessageToQueue($createBulkOpResp['sqs_data']);
            }
        }
        return true;
    }

    private function initiateDirectProcessing($shopId)
    {
        $queueData = [
            'user_id' => $this->di->getUser()->id,
            'message' => "Just a moment! We're updating some of your products from the Shopify. We'll let you know once it's done.",
            'process_code' => self::BATCH_PRODUCT_DIRECT_IMPORT_PROCESS_CODE,
            'marketplace' => 'shopify',
            'additional_data' => [
                'process_label' => 'Shopify Batch Product Direct Import'
            ]
        ];
        $queuedTask = new QueuedTasks;
        $queuedTaskId = $queuedTask->setQueuedTask($shopId, $queueData);
        if (!$queuedTaskId) {
            return [
                'success' => false,
                'message' => 'Shopify Batch Product Direct Import process is already under progress.',
                'requeue' => true
            ];
        }

        if (isset($queuedTaskId['success']) && !$queuedTaskId['success']) {
            return $queuedTaskId;
        }

        $appCodes = $this->di->getAppCode()->get();
        $appCode = $appCodes['shopify'];

        $handlerData = [
            'type' => 'full_class',
            'class_name' => \App\Shopifyhome\Components\Product\BatchImport::class,
            'method' => 'batchProductDirectImport',
            'user_id' => $this->di->getUser()->id,
            'data' => [
                'feed_id' => $queuedTaskId
            ],
            'shop_id' => $shopId,
            'marketplace' => 'shopify',
            'queue_name' => self::BATCH_PRODUCT_DIRECT_IMPORT_QUEUE,
            'appCode' => $appCode,
            'appTag' => $this->di->getAppCode()->getAppTag(),
            'app_code' => $appCodes
        ];
        $this->pushMessageToQueue($handlerData);
        return ['success' => true];
    }

    public function batchProductDirectImport($sqsData)
    {
        try {
            $userId = $sqsData['user_id'];
            $shopId = $sqsData['shop_id'];
            $this->logFile = $this->batchProductDirectImportLogPath . DS . $userId . DS . date('Y-m-d') . '.log';
            $containerIds = $this->getContainerIdsToSync($userId, $shopId);
            if (empty($containerIds)) {
                $this->logger('ContainerIds not found for sqsData: ', $sqsData);
                $this->removeEntryFromQueuedTask($userId, $shopId, self::BATCH_PRODUCT_DIRECT_IMPORT_PROCESS_CODE);
                return ['success' => false, 'message' => 'No container IDs found'];
            }

            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shopData = $userDetails->getShop($shopId, $userId);

            if (empty($shopData)) {
                $this->logger('Shop not found for sqsData: ', $sqsData);
                $this->removeEntryFromQueuedTask($userId, $shopId, self::BATCH_PRODUCT_DIRECT_IMPORT_PROCESS_CODE);
                return ['success' => false, 'message' => 'Shop not found'];
            }
            $remoteShopId = $shopData['remote_shop_id'];
            $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
            $apiClient = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($target, true);
            $vistarHelper = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Vistar\Helper');
            $productRequestControlObj = $this->di->getObjectManager()->get(ProductRequestcontrol::class);
            $chunks = array_chunk($containerIds, self::BATCH_IMPORT_DIRECT_PROCESSING_CHUNK);
            $totalChunks = count($chunks);
            $processedChunks = 0;
            $totalSuccessContainerIdsCount = 0;
            $bulkProcessingContainerIds = [];
            $successContainerIds = [];
            $allSuccessContainerIds = [];
            $functionPayload = [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'marketplace' => $sqsData['marketplace'],
                'data' => [
                    'feed_id' => $sqsData['data']['feed_id'],
                    'process_code' => self::BATCH_PRODUCT_DIRECT_IMPORT_PROCESS_CODE
                ]
            ];
            $prepareHandleWebhookData = [
                'method' => 'triggerWebhooks',
                'user_id' => $this->di->getUser()->id,
                'shop_id' => $shopId,
                'queue_name' => self::BATCH_PRODUCT_DIRECT_IMPORT_QUEUE
            ];
            $this->updateBatchImportProductQueueContainer($functionPayload);
            $appCodes = $this->di->getAppCode()->get();
            $appCode = $appCodes['shopify'];
            $internalServerError = false;
            $this->logger('Processing containerIds: ', $containerIds, 'info');
            // Check if user has 2048 variant support enabled

            $enabledUserIds = $this->di->getCache()->get(self::CACHE_KEY_2048_VARIANT_SUPPORT_USERS);
            if ($enabledUserIds === null) {
                $enabledUserIds = $this->fetchAndSet2048VariantSupportUsers();
            }
            $userHas2048VariantSupport = !empty($enabledUserIds) && is_array($enabledUserIds)
                && in_array((string)$userId, array_map('strval', $enabledUserIds));

            // Set False for now, to skip products with large variant count
            // $userHas2048VariantSupport = false;

            //check if bundle products support is enabled
            $bundleSkippedProducts = [];
            $bundleEnabled = (bool) $this->di->getConfig()->enable_bundle_products;
            $isBundleUserRestricted = false;

            if ($bundleEnabled) {
                $bundleSettings = $this->di->getConfig()->bundle_product_settings;
                $isRestrictedPerUser = $bundleSettings->restrict_per_user ?? true;
                $allowedUserIds = $bundleSettings->allowed_user_ids
                    ? $bundleSettings->allowed_user_ids->toArray() : [];
                $isBundleUserRestricted = $isRestrictedPerUser
                    && !in_array($this->di->getUser()->id, $allowedUserIds, true);
            }

            // Processing chunkwise
            foreach ($chunks as $idsChunk) {
                $increaseSuccessCount = true;
                $chunkPushWriteCount = null;
                $largeVariantCountContainerIds = [];
                $largeVariantCountContainerIdsMetaData = [];
                $remoteResponse = $apiClient->call(
                    'get/productByIdsFragment',
                    ['Response-Modifier' => '0'],
                    ['ids' => $idsChunk, 'shop_id' => $remoteShopId, 'call_type' => 'QL'],
                    'GET'
                );
                if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                    if (!empty($remoteResponse['data']['products'])) {
                        $formattedProductsData = $this->formatGetProductByIdFragmentResponse($remoteResponse['data']);
                        if (!empty($formattedProductsData)) {
                            $directProcessingContainerIds = [];
                            $unAssingedContainerIds = [];
                            foreach ($formattedProductsData as $containerId => $product) {
                                if (!empty($product['publishedOnCurrentPublication'])) {
                                    $variantCount = isset($product['variantCount']) ? (int)$product['variantCount'] : 0;
                                    if ($variantCount > self::MAX_VARIANT_COUNT_PROCESSABLE) {
                                        if ($userHas2048VariantSupport) {
                                            // If user has 2048 variant support enabled, then process the product(s)
                                            $bulkProcessingContainerIds[] = [(string)$containerId];
                                        } else {
                                            $largeVariantCountContainerIds[] = (string)$containerId;
                                            $largeVariantCountContainerIdsMetaData[$containerId] = [
                                                'title' => $product['title'],
                                                'image' => $product['featuredImage']['originalSrc'] ?? null
                                            ];
                                        }
                                    } else {
                                        unset($product['variantCount'], $product['publishedOnCurrentPublication']);
                                        $directProcessingContainerIds[$containerId] = $product;
                                    }
                                } else {
                                    $unAssingedContainerIds[] = (string)$containerId;
                                }
                            }
                            if (!empty($directProcessingContainerIds)) {
                                $formatParams = [
                                    'success' => true,
                                    'data' => [
                                        'Product' => $directProcessingContainerIds
                                    ]
                                ];
                                $cifFormattedProductData = $vistarHelper->shapeProductResponse($formatParams);

                                if (!empty($cifFormattedProductData['data'])) {
                                    $helper = $this->di->getObjectManager()->get(Helper::class);
                                    foreach ($cifFormattedProductData['data'] as $key => $product) {

                                        // Skip bundle products if not allowed
                                        if ((!$bundleEnabled || $isBundleUserRestricted) && !empty($product['is_bundle'])) {
                                            $bundleSkippedProducts[] = $product;
                                            continue;
                                        }

                                        $formatted_product = $helper->formatCoreDataForVariantData($product);
                                        if (!empty($formatted_product)) {
                                            $cifFormattedProductData['data'][$key] = $formatted_product;
                                        } else {
                                            unset($cifFormattedProductData['data'][$key]);
                                        }
                                    }

                                    if (!empty($cifFormattedProductData['data'])) {
                                        $additionalData = [
                                            'shop_id' => $shopId,
                                            'marketplace' => 'shopify',
                                            'isImported' => true,
                                            'webhook_present' => true,
                                            'isWebhook' => true,
                                            'app_code' => $appCode
                                        ];
                                        $pushContainerStartTimestamp = microtime(true);
                                        $pushToProductContainerRes = $productRequestControlObj->pushToProductContainer($cifFormattedProductData['data'], $additionalData);
                                        $stats = $pushToProductContainerRes['stats'] ?? [];
                                        $chunkPushWriteCount = ($stats['inserted'] ?? 0) + ($stats['upserted'] ?? 0) + ($stats['modified'] ?? 0);
                                        $pushContainerEndTimestamp = microtime(true);
                                        $pushContainerProcessingTime = $pushContainerEndTimestamp - $pushContainerStartTimestamp;
                                        if ($pushContainerProcessingTime > self::MAX_PROCESSING_TIME_ALLOWED_FOR_EACH_CHUNK) {
                                            $this->di->getLog()->logContent('Push to container processing time is too long: ' . json_encode(['user_id' => $userId, 'start_time' => $pushContainerStartTimestamp, 'end_time' => $pushContainerEndTimestamp, 'processing_time' => $pushContainerProcessingTime, 'container_ids' => array_map('strval', array_keys($directProcessingContainerIds))]), 'info', $this->batchProductDirectImportLogPath . DS . 'processing-time-too-long' . DS . date('Y-m-d') . '.log');
                                        }
                                        if (isset($pushToProductContainerRes['success']) && $pushToProductContainerRes['success']) {
                                            $prepareHandleWebhookData['data'] = $cifFormattedProductData['data'];
                                            $deleteVariantStartTimestamp = microtime(true);
                                            $deleteVariantResponse = $productRequestControlObj->deleteIsExistsKeyAndVariants($prepareHandleWebhookData);
                                            $deleteVariantEndTimestamp = microtime(true);
                                            $deleteVariantProcessingTime = $deleteVariantEndTimestamp - $deleteVariantStartTimestamp;
                                            if ($deleteVariantProcessingTime > self::MAX_PROCESSING_TIME_ALLOWED_FOR_EACH_CHUNK) {
                                                $this->di->getLog()->logContent('Delete variant processing time is too long: ' . json_encode(['user_id' => $userId, 'start_time' => $deleteVariantStartTimestamp, 'end_time' => $deleteVariantEndTimestamp, 'processing_time' => $deleteVariantProcessingTime, 'container_ids' => array_map('strval', array_keys($directProcessingContainerIds))]), 'info', $this->batchProductDirectImportLogPath . DS . 'processing-time-too-long' . DS . date('Y-m-d') . '.log');
                                            }
                                            if (isset($deleteVariantResponse['success']) && $deleteVariantResponse['success']) {
                                                $successContainerIds = array_map('strval', array_keys($directProcessingContainerIds));
                                            } else {
                                                $bulkProcessingContainerIds[] = array_map('strval', array_keys($directProcessingContainerIds));
                                                $this->logger('Error from deleteIsExistsKeyAndVariants, response: ', $deleteVariantResponse);
                                                $this->logger('Affected chunk data: ', $cifFormattedProductData);
                                            }
                                        } else {
                                            $bulkProcessingContainerIds[] = array_map('strval', array_keys($directProcessingContainerIds));
                                            $this->logger('Error from pushToProductContainer, response: ', $pushToProductContainerRes);
                                        }
                                    } else {
                                        // Bundle Product Case
                                        $successContainerIds = array_map('strval', array_keys($directProcessingContainerIds));
                                        $increaseSuccessCount = false;
                                        if (isset($shopData['reauth_required']) && $shopData['reauth_required']) {
                                            $this->di->getObjectManager()->get('App\Shopifyhome\Components\Shop\Shop')->sendMailForReauthRequired($shopData);
                                        } else {
                                            $containerIdChunks = array_chunk($successContainerIds, self::BATCH_IMPORT_UNASSIGN_PRODUCT_IDS_CHUNK);
                                            foreach ($containerIdChunks as $containerIdsBatch) {
                                                $handlerData = [
                                                    'type' => 'full_class',
                                                    'class_name' => BatchImport::class,
                                                    'method' => 'unassignProductInBulk',
                                                    'user_id' => $userId,
                                                    'data' => [
                                                        'container_ids' => $containerIdsBatch,
                                                        'user_id' => $userId,
                                                        'shop_id' => $shopId,
                                                    ],
                                                    'shop_id' => $shopId,
                                                    'marketplace' => 'shopify',
                                                    'queue_name' => self::UNASSIGN_PRODUCT_IN_BULK_QUEUE,
                                                    'appCode' => $appCode,
                                                    'appTag' => $this->di->getAppCode()->getAppTag(),
                                                    'app_code' => $appCodes
                                                ];
                                                $this->pushMessageToQueue($handlerData);
                                            }
                                        }
                                        $this->logger('No formatted product data found for containerIds: ', $idsChunk);
                                    }
                                } else {
                                    $bulkProcessingContainerIds[] = array_map('strval', array_keys($directProcessingContainerIds));
                                    $this->logger('Unable to shapeResponse, formatParams: ', $formatParams);
                                }
                            } else {
                                $this->logger('Variant count exceed limit product(s) containerIds: ', $largeVariantCountContainerIds);
                                $this->logger('Unassigned product(s) containerIds: ', $unAssingedContainerIds);
                            }
                        } else {
                            $bulkProcessingContainerIds[] = $idsChunk;
                            $this->logger('Unable to format fragments API response, remoteResponse: ', $remoteResponse);
                        }
                    } else {
                        $bulkProcessingContainerIds[] = $idsChunk;
                        $this->logger('Empty data from get/productByIdsFragment, remoteResponse: ', $remoteResponse);
                    }
                } elseif (isset($remoteResponse['message']) && $remoteResponse['message'] == 'Product(s) not found') {
                    $successContainerIds = $idsChunk;
                    $increaseSuccessCount = false;
                    $this->logger('No product(s) found for containerIds: ', $idsChunk);
                } elseif (isset($remoteResponse['message']) && $remoteResponse['message'] == 'Internal Server Error') {
                    $this->logger('Internal Server Error Intiating Bulk Operation, remoteResponse: ', $remoteResponse);
                    $internalServerError = true;
                    break;
                } else {
                    $bulkProcessingContainerIds[] = $idsChunk;
                    $this->logger('Error from get/productByIdsFragment, remoteResponse: ', $remoteResponse);
                }
                $processedChunks++;
                $progress = round(($processedChunks / $totalChunks) * 100, 2);
                $this->di->getObjectManager()
                    ->get('\App\Connector\Models\QueuedTasks')
                    ->updateFeedProgress(
                        $functionPayload['data']['feed_id'],
                        $progress,
                        "Importing and syncing Shopify product(s)",
                        false
                    );
                if (!empty($successContainerIds)) {
                    if ($increaseSuccessCount) {
                        $successCount = count($successContainerIds);
                        if ($chunkPushWriteCount !== null && $successCount !== $chunkPushWriteCount) {
                            $totalSuccessContainerIdsCount += $chunkPushWriteCount;
                        } else {
                            $totalSuccessContainerIdsCount += $successCount;
                        }
                    }
                    if (!empty($unAssingedContainerIds)) {
                        // Deleting unAssingedContainerIds from batch collection
                        $successContainerIds = array_merge($successContainerIds, $unAssingedContainerIds);
                        $successContainerIds = array_values(array_unique($successContainerIds));
                    }
                    $allSuccessContainerIds[] = $successContainerIds;
                    $this->deleteContainerIdsFromBatchCollection($successContainerIds, $functionPayload);
                } elseif (!empty($unAssingedContainerIds)) {
                    $this->logger('Product(s) not published on current publication: ', $unAssingedContainerIds);
                    $this->deleteContainerIdsFromBatchCollection($unAssingedContainerIds, $functionPayload);
                }

                // Unassign product in bulk if user do not have 2048 variant support and insert in restricted_products collection
                if (!$userHas2048VariantSupport && !empty($largeVariantCountContainerIds)) {
                    if (isset($shopData['reauth_required']) && $shopData['reauth_required']) {
                        $this->di->getObjectManager()->get('App\Shopifyhome\Components\Shop\Shop')->sendMailForReauthRequired($shopData);
                    } else {
                        $containerIdChunks = array_chunk($largeVariantCountContainerIds, self::BATCH_IMPORT_UNASSIGN_PRODUCT_IDS_CHUNK);
                        foreach ($containerIdChunks as $containerIdsBatch) {
                            $handlerData = [
                                'type' => 'full_class',
                                'class_name' => BatchImport::class,
                                'method' => 'unassignProductInBulk',
                                'user_id' => $userId,
                                'data' => [
                                    'container_ids' => $containerIdsBatch,
                                    'user_id' => $userId,
                                    'shop_id' => $shopId,
                                ],
                                'shop_id' => $shopId,
                                'marketplace' => 'shopify',
                                'queue_name' => self::UNASSIGN_PRODUCT_IN_BULK_QUEUE,
                                'appCode' => $appCode,
                                'appTag' => $this->di->getAppCode()->getAppTag(),
                                'app_code' => $appCodes
                            ];
                            $this->pushMessageToQueue($handlerData);
                        }
                        // Inserting data in restricted_products collection
                        $this->insertDataInRestrictedProductsCollection($functionPayload, $largeVariantCountContainerIdsMetaData);
                    }
                }
            }

            if (empty($bulkProcessingContainerIds)) {
                $this->removeEntryByFeedIdFromBatchQueueCollection($functionPayload);
            } else {
                $bulkProcessingContainerIds = array_merge(...$bulkProcessingContainerIds);
            }

            $this->removeEntryFromQueuedTask($userId, $shopId, self::BATCH_PRODUCT_DIRECT_IMPORT_PROCESS_CODE);
            $message = "Total $totalSuccessContainerIdsCount product(s) data synced successfully";
            $this->updateActivityLog($functionPayload, 'success', $message);

            if ($internalServerError) {
                $allSuccessContainerIds = array_merge(...$allSuccessContainerIds);
                $containerIdsToBeProcessed = array_diff($containerIds, $allSuccessContainerIds);
                $bulkProcessingContainerIds = array_merge($bulkProcessingContainerIds, $containerIdsToBeProcessed);
            }

            // Fire single batch event for all skipped bundle products
            if (!empty($bundleSkippedProducts)) {
                $this->di->getEventsManager()->fire(
                    'application:shopifyBundleProductSkipped',
                    $this,
                    ['products' => $bundleSkippedProducts, 'batch' => true]
                );
            }

            if (!empty($bulkProcessingContainerIds)) {
                $this->logger('Processing failed containerIds through bulk Operation: ', $bulkProcessingContainerIds);
                $preparedQueueData = $this->prepareQueueData($functionPayload);
                $queryFilter = $this->prepareQueryFilterFromContainerIds($bulkProcessingContainerIds);
                $createBulkOpResp = $this->createBulkOperation($queryFilter, $shopId, $preparedQueueData['data'], $bulkProcessingContainerIds);
                if (isset($createBulkOpResp['success']) && !$createBulkOpResp['success']) {
                    $this->deleteCanInitiateEntry($functionPayload);
                    return true;
                }
                if (isset($createBulkOpResp['requeue']) && $createBulkOpResp['requeue']) {
                    $this->pushMessageToQueue($createBulkOpResp['sqs_data']);
                }
            }
            return true;
        } catch (Exception $e) {
            $this->logger('Exception batchProductDirectImport(), Error: ', $e->getMessage());
            throw $e;
        }
    }

    private function deleteContainerIdsFromBatchCollection($containerIds, $data)
    {
        try {
            $batchImportContainerCollection = $this->getMongoCollection('batch_import_product_container');
            $query = [
                'user_id' => $data['user_id'],
                'shop_id' => $data['shop_id'],
                'container_id' => ['$in' => $containerIds]
            ];
            $batchImportContainerCollection->deleteMany($query);
        } catch (Exception $e) {
            $this->logger('Exception deleteContainerIdsFromBatchCollection(), Error: ', $e->getMessage());
            throw $e;
        }
    }

    private function removeEntryByFeedIdFromBatchQueueCollection($data)
    {
        try {
            $batchImportQueueCollection = $this->getMongoCollection('batch_import_product_queue');
            $query = [
                '_id' => $data['user_id'] . '_' . $data['shop_id'],
                'feed_id' => $data['data']['feed_id']
            ];
            $batchImportQueueCollection->deleteOne($query);
        } catch (Exception $e) {
            $this->logger('Exception removeEntryByFeedIdFromBatchQueueCollection(), Error: ', $e->getMessage());
            throw $e;
        }
    }

    private function removeEntryFromQueuedTask($userId, $shopId, $processCode)
    {
        try {
            $queueTasksContainer = $this->getMongoCollection('queued_tasks');
            $queueTasksContainer->deleteOne([
                'user_id' => $userId ?? $this->di->getUser()->id,
                'shop_id' => $shopId,
                'process_code' => $processCode
            ]);
        } catch (Exception $e) {
            $this->logger('Exception removeEntryFromQueuedTask(), Error: ', $e->getMessage());
            throw $e;
        }
    }

    private function updateActivityLog($data, $status, $message)
    {
        $appTag = $this->di->getAppCode()->getAppTag();
        $notificationData = [
            'severity' => $status ? 'success' : 'critical',
            'message' => $message ?? 'Batch Import Direct Process',
            "user_id" => $this->di->getUser()->id,
            "marketplace" => $data['marketplace'],
            "appTag" => $appTag,
            'process_code' => $data['data']['process_code']
        ];
        $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
            ->addNotification($data['shop_id'], $notificationData);
    }

    public function formatGetProductByIdFragmentResponse($data)
    {
        $result = [];

        if (empty($data['products']) || !is_array($data['products'])) {
            return $result;
        }

        foreach ($data['products'] as $product) {
            $productId = isset($product['id']) ? preg_replace('/\D+/', '', $product['id']) : null;
            if (!$productId) {
                continue;
            }

            $normalizedProduct = [
                'id' => $product['id'],
                'title' => $product['title'] ?? null,
                'vendor' => $product['vendor'] ?? null,
                'productType' => $product['productType'] ?? null,
                'description' => $product['description'] ?? null,
                'descriptionHtml' => $product['descriptionHtml'] ?? null,
                'handle' => $product['handle'] ?? null,
                'tags'  => $product['tags'] ?? [],
                'templateSuffix' => $product['templateSuffix'] ?? null,
                'seo' => $product['seo'] ?? null,
                'featuredImage' => $product['featuredImage'] ?? null,
                'publishedAt' => $product['publishedAt'] ?? null,
                'createdAt' => $product['createdAt'] ?? null,
                'updatedAt' => $product['updatedAt'] ?? null,
                'variantCount' => $product['variantsCount']['count'] ?? 0,
                'publishedOnCurrentPublication' => $product['publishedOnCurrentPublication'] ?? null,
                'hasVariantsThatRequiresComponents' => $product['hasVariantsThatRequiresComponents'] ?? null,
                'resourcePublicationOnCurrentPublication' => $product['resourcePublicationOnCurrentPublication'] ?? null,
                'productCategory' => $product['productCategory'] ?? null
            ];

            $productImages = [];
            if (!empty($product['images']['edges'])) {
                foreach ($product['images']['edges'] as $imgEdge) {
                    $img = $imgEdge['node'];
                    if (!empty($img['id'])) {
                        $imgId = preg_replace('/\D+/', '', $img['id']);
                        $productImages[$imgId] = [
                            'id'            => $img['id'],
                            'originalSrc'   => $img['originalSrc'] ?? null,
                            'transformedSrc' => $img['transformedSrc'] ?? null,
                            '__parentId'    => $product['id'],
                        ];
                    }
                }
            }
            if ($productImages) {
                $normalizedProduct['ProductImage'] = $productImages;
            }

            $variantsMap = [];
            if (!empty($product['variants']['edges'])) {
                foreach ($product['variants']['edges'] as $variantEdge) {
                    $variant = $variantEdge['node'];
                    $varId = isset($variant['id']) ? preg_replace('/\D+/', '', $variant['id']) : null;
                    if (!$varId) continue;

                    $variantData = [
                        'id' => $variant['id'],
                        'title' => $variant['title'] ?? null,
                        'position' => $variant['position'] ?? null,
                        'sku' => $variant['sku'] ?? null,
                        'price' => (float)$variant['price'] ?? null,
                        'compareAtPrice'  => $variant['compareAtPrice'] ?? null,
                        'inventoryQuantity' => $variant['inventoryQuantity'] ?? null,
                        'barcode' => $variant['barcode'] ?? null,
                        'inventoryPolicy' => $variant['inventoryPolicy'] ?? null,
                        'taxable' => $variant['taxable'] ?? null,
                        'createdAt' => $variant['createdAt'] ?? null,
                        'updatedAt' => $variant['updatedAt'] ?? null,
                        'requiresComponents' => $variant['requiresComponents'] ?? null,
                        'image' => $variant['image'] ?? null,
                        'selectedOptions' => $variant['selectedOptions'] ?? [],
                        '__parentId' => $product['id'],
                    ];

                    if (!empty($variant['inventoryItem'])) {
                        $variantData['inventoryItem'] = [
                            'id' => $variant['inventoryItem']['id'],
                            'tracked'  => $variant['inventoryItem']['tracked'] ?? null,
                            'requiresShipping' => $variant['inventoryItem']['requiresShipping'] ?? null,
                            'measurement' => $variant['inventoryItem']['measurement'] ?? null,
                        ];

                        $inventoryLevels = [];
                        if (!empty($variant['inventoryItem']['inventoryLevels']['edges'])) {
                            foreach ($variant['inventoryItem']['inventoryLevels']['edges'] as $invEdge) {
                                $inv = $invEdge['node'];

                                if (preg_match('/InventoryLevel\/(\d+)/', $inv['id'], $m)) {
                                    $invId = $m[1];
                                } else {
                                    $invId = $inv['id'];
                                }

                                $inventoryLevels[$invId] = [
                                    'id' => $inv['id'],
                                    'quantities' => $inv['quantities'] ?? [],
                                    'updatedAt' => $inv['updatedAt'] ?? null,
                                    'location' => $inv['location'] ?? null,
                                    '__parentId' => $variant['id'],
                                ];
                            }
                        }
                        if ($inventoryLevels) {
                            $variantData['InventoryLevel'] = $inventoryLevels;
                        }
                    }

                    $variantsMap[$varId] = $variantData;
                }
            }

            if ($variantsMap) {
                $normalizedProduct['ProductVariant'] = $variantsMap;
            }

            $result[$productId] = $normalizedProduct;
        }

        return $result;
    }

    public function getContainerIdsToSync($userId, $shopId)
    {
        $batchProductImportCollection = $this->getMongoCollection(self::BATCH_IMPORT_COLLECTION);
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array'],
            'projection' => ['container_id' => 1],
            'limit'    => self::BATCH_IMPORT_CONTAINER_ID_CHUNK
        ];

        $productsToSync = $batchProductImportCollection->find(
            [
                'user_id' => $userId,
                // 'type' => 'listings_add',
                'source_marketplace' => 'shopify',
                'shop_id' => $shopId
            ],
            $options
        )->toArray();
        return array_column($productsToSync, 'container_id');
    }

    public function prepareQueryFilterFromContainerIds($containerIds)
    {
        $filterQuery = 'NOT (status:draft)';
        if (empty($containerIds)) {
            return $filterQuery;
        }

        $filterQuery .= ' AND ';
        $filterQuery .= 'id:' . implode(' OR id:', $containerIds);
        return $filterQuery;
    }

    public function createBulkOperation($queryFilter, $shopId, $sqsData, $containerIds)
    {
        $remoteShopId = $this->getShopifyRemoteShop($shopId);
        if (!$remoteShopId) {
            return ['success' => false, 'message' => 'No remote shop found.'];
        }
        if (!empty($containerIds)) {
            $queryFilter = $this->prepareQueryFilterFromContainerIds($containerIds);
        } else {
            return ['success' => false, 'message' => 'No container IDs to process.'];
        }
        $queueData = [
            'user_id' => $this->di->getUser()->id,
            'message' => "Just a moment! We're updating some of your products from the Shopify. We'll let you know once it's done.",
            'process_code' => 'saleschannel_product_import', //saleschannel_product_import//shopify_batch_product_import
            'marketplace' => 'shopify',
            'additional_data' => [
                'process_label' => 'Shopify Batch Product Import'
            ]
        ];

        if (empty($sqsData['data']['feed_id'])) {
            $queuedTask = new QueuedTasks;
            $queuedTaskId = $queuedTask->setQueuedTask($shopId, $queueData);
        } else {
            $queuedTaskId = $sqsData['data']['feed_id'];
        }
        if (!$queuedTaskId) {
            return [
                'success' => false,
                'message' => 'Product Import process is already under progress.',
                'requeue' => true,
                'sqs_data' => $sqsData
            ];
        }

        if (isset($queuedTaskId['success']) && !$queuedTaskId['success']) {
            //log here and delete this user's entry in queue collection
            return $queuedTaskId;
        }

        $sqsData['data']['feed_id'] = $queuedTaskId;

        $params = [
            'shop_id' => $remoteShopId,
            'query_filter' => $queryFilter,
            'type' => 'productWithFilter'
        ];
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target, 'true')
            ->call('/bulk/operation', [], $params, 'POST');

        if (!isset($remoteResponse['success']) || !$remoteResponse['success'] || !$remoteResponse) {
            $this->completeProgressBarForError($sqsData);
            return ['success' => false, 'requeue' => false, 'sqs_data' => $sqsData];
        }

        $this->updateContainerIdsInImport($containerIds, $sqsData);

        $sqsData['data']['operation'] = 'get_status';
        $sqsData['data']['total_count'] = count($containerIds);
        $sqsData['data']['id'] = $remoteResponse['data']['id'];
        $sqsData['data']['process_code'] = 'batch_product_import';
        $sqsData['class_name'] = Requestcontrol::class;
        $sqsData['method'] = 'handleImport';
        $sqsData['delay'] = (int)10;

        $msg = ShopifyBulkImport::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG
            . ' : Product(s) import Request is accepted by Shopify.';

        $this->di->getObjectManager()
            ->get('\App\Connector\Components\Helper')
            ->updateFeedProgress($sqsData['data']['feed_id'], 0, $msg);

        return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
    }

    public function updateContainerIdsInImport($containerIds, $sqsData, $updateBatchProductQueue = true): void
    {

        $batchImportContainerCollection = $this->getMongoCollection('batch_import_product_container');
        $query = [
            'user_id' => $sqsData['user_id'],
            'shop_id' => $sqsData['shop_id'],
            'container_id' => ['$in' => $containerIds]
        ];
        $update = ['$set' => ['picked' => true, 'updated_at' => date('c')]];
        $batchImportContainerCollection->updateMany($query, $update);
        if ($updateBatchProductQueue) {
            $this->updateBatchImportProductQueueContainer($sqsData);
        }
    }

    private function updateBatchImportProductQueueContainer($data)
    {
        $batchImportQueueCollection = $this->getMongoCollection('batch_import_product_queue');
        $filter = [
            '_id' => $data['user_id'] . '_' . $data['shop_id']
        ];

        $setData = [
            'user_id' => $data['user_id'],
            'shop_id' => $data['shop_id'],
            'marketplace' => 'shopify',
            'feed_id' => $data['data']['feed_id']
        ];

        $setOnInsertData = [
            '_id' => $data['user_id'] . '_' . $data['shop_id'],
            'created_at' => date('c')
        ];

        $batchImportQueueCollection->updateOne(
            $filter,
            [
                '$set' => $setData,
                '$setOnInsert' => $setOnInsertData
            ],
            ['upsert' => true]
        );
    }

    public function pushMessageToQueue($message)
    {
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        try {
            $response = $sqsHelper->pushMessage($message);
        } catch (Exception $e) {
            //log here
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'data' => $response];
    }

    public function afterQueuedTaskComplete(Event $event, $component, $eventData): void
    {
        $feedId = $eventData['feed_id'] ?? false;
        if (!$feedId) {
            return;
        }

        $batchImportQueueCollection = $this->getMongoCollection('batch_import_product_queue');
        $query = [
            'user_id' => $this->di->getUser()->id,
            'feed_id' => $feedId
        ];
        $batchImportData = $batchImportQueueCollection->findOne($query);
        if (empty($batchImportData)) {
            return;
        }

        $batchImportContainerCollection = $this->getMongoCollection('batch_import_product_container');
        $query = [
            'user_id' => $batchImportData['user_id'],
            'shop_id' => $batchImportData['shop_id'],
            'picked' => true
        ];
        $batchImportContainerCollection->deleteMany($query);

        $batchImportQueueCollection->deleteOne(['_id' => $batchImportData['_id']]);

        $this->di->getEventsManager()->fire('application:afterBatchProductImport', $this, [
            'user_id' => $batchImportData['user_id'],
            'shop_id' => $batchImportData['shop_id'],
            'source_marketplace' => 'shopify'
        ]);
        //using feedId from the event data, get adoc from batch_import_product_queue collection
        //get container_ids from that doc, and then delete container_ids from batch_import_product_container
        //along with picked - true key
        //then delete from batch_import_product_queue collection

    }

    public function completeProgressBarForError($sqsData): void
    {
        $this->di->getObjectManager()
            ->get(Data::class)
            ->completeProgressBar($sqsData);
    }

    public function getShopifyRemoteShop($shopId)
    {
        $shops = $this->di->getUser()->shops ?? [];

        $remoteShopId = null;
        foreach ($shops as $shop) {
            if ($shop['_id'] == $shopId) {
                $remoteShopId = $shop['remote_shop_id'];
                break;
            }
        }

        return $remoteShopId;
    }

    public function getPendingBatchImportProducts($params)
    {
        if (!isset($params['user_id'], $params['shop_id'])) {
            return ['success' => false, 'message' => 'user_id and shop_id required'];
        }

        $query = [
            'user_id' => $params['user_id'],
            'source_marketplace' => 'shopify',
            'shop_id' => $params['shop_id'],
        ];
        if (isset($params['filter']) && !empty(trim((string) $params['filter']))) {
            $params['filter'] = trim(addslashes((string) $params['filter']));
            $filterQuery = [
                '$or' => [
                    ['title' => ['$regex' => "(?i)" . $params['filter']]],
                    ['container_id' => ['$regex' => $params['filter']]],
                    ['variants.id' => ['$regex' => $params['filter']]],
                ]
            ];
            $query = [...$query, ...$filterQuery];
        }

        $limit = $params['count'] ?? 20;
        $page = $params['activePage'] ?? 1;
        $skip = ($page - 1) * $limit;
        $options = [
            'limit' => (int)$limit,
            'skip' => (int)$skip,
            'typeMap' => ['root' => 'array', 'document' => 'array']
        ];

        $batchImportContainerCollection = $this->getMongoCollection(self::BATCH_IMPORT_COLLECTION);
        $pendingProductsCount = $batchImportContainerCollection->countDocuments($query);
        $data['count'] = $pendingProductsCount;
        if ($pendingProductsCount == 0 || !empty($params['count_only'])) {
            return ['success' => true, 'data' => $data];
        }

        $data['rows'] = $batchImportContainerCollection->find($query, $options)->toArray();
        return ['success' => true, 'data' => $data];
    }

    public function setDiForUser($userId)
    {
        try {
            $getUser = User::findFirst([['_id' => $userId]]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found.'
            ];
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin.'
            ];
        }

        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }

    public function getMongoCollection($collection)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        return $mongo->getCollection($collection);
    }

    public function logger($key, $message, $type = 'info'): void
    {
        $this->di->getLog()
            ->logContent(
                str_repeat('-', 50) . PHP_EOL . $key . ' = ' . json_encode($message),
                $type,
                $this->logFile
            );
    }

    public function isProductImportRestricted($userId)
    {
        $logFile = $this->baseLogPath . DS  . 'restrict-batch-product-import' . DS . date('Y-m-d') . '.log';
        $appCodes = $this->di->getAppCode()->get();
        $marketplace = 'shopify';
        $appCode = $appCodes[$marketplace];
        $isRestricted = false;
        $isRestricted = $this->di->getConfig()->get("plan_restrictions")[$appCode]['product']['product_import']['restricted'] ?? false;
        if ($isRestricted) {
            if (class_exists('App\Plan\Models\Plan')) {
                $planModel = $this->di->getObjectManager()->create('App\Plan\Models\Plan');
                if (method_exists($planModel, 'isImportSyncAllowed') && ($planModel->isImportSyncAllowed($userId, $marketplace) == false)) {
                    $this->di->getLog()->logContent('Product Importing cannot be proceeded for user as product limit exhausted, user_id: '.json_encode($userId), 'info', $logFile);
                    return true;
                }
            }
        }

        return false;
    }

    public function fetchAndSet2048VariantSupportUsers()
    {
        try {
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $paymentCollection = $mongo->getCollectionForTable(Plan::PAYMENT_DETAILS_COLLECTION_NAME);
            $filter = [
                'service_type' => 'additional_services',
                'supported_features.2048_variant_support' => true,
            ];
            $enabledUserIds = $paymentCollection->distinct('user_id', $filter);
            $enabledUserIds = is_array($enabledUserIds) ? array_values(array_map('strval', $enabledUserIds)) : [];
            $this->di->getCache()->set('2048_variant_support_users', $enabledUserIds);
            return $enabledUserIds;
        } catch (\Exception $e) {
            $this->logger('fetchAndSet2048VariantSupportUsers error', [$e->getMessage()]);
            return [];
        }
    }

    private function fetchVariantCountByIds($remoteShopId, $containerIds)
    {
        if (empty($containerIds)) {
            return ['success' => false, 'message' => 'No container IDs provided.'];
        }

        $aggregatedProducts = [];
        $errors = [];
        $chunks = array_chunk($containerIds, self::BATCH_IMPORT_DIRECT_PROCESSING_CHUNK);
        try {
            foreach ($chunks as $chunk) {
                $finalIds = array_map(function ($id) {
                    return "gid://shopify/Product/" . $id;
                }, $chunk);
                $idsString = '"' . implode('","', $finalIds) . '"';
                $query = '
                    query Products {
                        nodes(ids: [' . $idsString . ']) {
                            ... on Product {
                                id
                                title
                                featuredImage {
                                    originalSrc
                                }
                                variantsCount {
                                    count
                                }
                            }
                        }
                    }';
                $response = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init('shopify', true)
                    ->call('/shopifygql/query', [], ['shop_id' => $remoteShopId, 'query' => $query], 'POST');
                    if (isset($response['success']) && $response['success'] && !empty($response['data']['nodes'])) {
                        $aggregatedProducts = array_merge($aggregatedProducts, $response['data']['nodes']);
                    } else {
                        $errors[] = $response['message'] ?? 'Unable to fetch variant count.';
                    }
            }
            if (!empty($aggregatedProducts)) {
                return ['success' => true, 'data' => $aggregatedProducts];
            }
        } catch (\Exception $e) {
            $this->logger('fetchVariantCountByIds error', [$e->getMessage()]);
        }
        return ['success' => false, 'message' => 'Unable to fetch variant counts from Shopify.', 'errors' => $errors];
    }

    private function insertDataInRestrictedProductsCollection($requestParams, $restrictedProductMetaData)
    {
        $userId = $requestParams['user_id'] ?? $this->di->getUser()->id;
        $shopId = $requestParams['shop_id'];
        $now = new UTCDateTime((int)(microtime(true) * 1000));

        if (empty($restrictedProductMetaData)) {
            $this->logger('insertDataInRestrictedProductsCollection error', ['No restricted product meta data found']);
            return;
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $restrictedProductsCollection = $mongo->getCollectionForTable('restricted_products');
        $operations = [];
        foreach ($restrictedProductMetaData as $containerId => $metaData) {
            $filter = [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'container_id' => (string) $containerId
            ];

            $restricedProductDataUpdate = [
                    '$setOnInsert' => [
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                        'marketplace' => 'shopify',
                        'container_id' => (string)$containerId,
                        'title' => $metaData['title'],
                        'main_image' => $metaData['image'] ?? null,
                        'error' => 'Product Variant Limit Exceeded',
                        'created_at' => $now
                    ],
                    '$set' => [
                        'updated_at' => $now
                    ]
                ];

            $restrictedProductOperations[] = [
                'updateOne' => [
                    $filter,
                    $restricedProductDataUpdate,
                    ['upsert' => true]
                ]
            ];
        }
        if (empty($operations) && empty($restrictedProductOperations)) {
            return;
        }

        try {
            $restrictedProductsCollection->bulkWrite($restrictedProductOperations);
        } catch (\Exception $e) {
            $this->logger('insertDataInRestrictedProductsCollection error', [$e->getMessage()]);
            throw $e;
        }
    }

    private function checkUserShopActive($userId, $shopId)
    {
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shopData = $userDetails->getShop($shopId, $userId);
        if(!isset($shopData['store_closed_at']) && !empty($shopData['apps'])) {
            foreach($shopData['apps'] as $app) {
                if($app['app_status'] == 'active') {
                    return true;
                }
            }
        }
        return false;
    }

    private function removeEntryFromBatchImportContainer($data)
    {
        try  {
            $batchImportProductContainerCollection = $this->getMongoCollection('batch_import_product_container');
            $batchImportProductContainerCollection->deleteMany(['user_id' => $data['user_id'], 'shop_id' => $data['shop_id']]);
        } catch (Exception $e) {
            $this->logger('Exception from  removeEntryFromBatchImportContainer(), Error: ', [$e->getMessage()]);
            throw $e;
        }
    }

    public function checkReauthAndUnassignProduct($data)
    {
        if (empty($data['container_id'])) {
            return;
        }
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $shopId = $data['shop_id'];
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shopData = $userDetails->getShop($shopId, $userId);
        if (isset($shopData['reauth_required']) && $shopData['reauth_required']) {
            $this->di->getObjectManager()->get('App\Shopifyhome\Components\Shop\Shop')->sendMailForReauthRequired($shopData);
        } else {
            $this->unassignProductFromSalesChannel((string)$data['container_id'], $shopData);
        }
    }

    /**
     * Unassign multiple products from sales channel. Stops on first failure and logs.
     */
    public function unassignProductInBulk($sqsData)
    {
        $userId = $sqsData['user_id'] ?? $sqsData['data']['user_id'] ?? $this->di->getUser()->id;
        $shopId = $sqsData['shop_id'] ?? $sqsData['data']['shop_id'] ?? null;
        $logFile = $this->baseLogPath . DS . 'unassign-product-sales-channel-in-bulk' . DS . $userId . DS . date('Y-m-d') . '.log';
        if (!empty($sqsData['data']['container_ids']) && !empty($shopId)) {
            $containerIds = $sqsData['data']['container_ids'];
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shopData = $userDetails->getShop($shopId, $userId);
            if (!empty($shopData)) {
                $allSucceeded = true;
                foreach ($containerIds as $containerId) {
                    $unassignProductResponse = $this->unassignProductFromSalesChannel((string)$containerId, $shopData);
                    if (!isset($unassignProductResponse['success']) || !$unassignProductResponse['success']) {
                        $this->di->getLog()->logContent(
                            'Unable to unassignProductInBulk: ' . json_encode([
                                'user_id' => $userId,
                                'shop_id' => $shopId,
                                'unassignProductResponse' => $unassignProductResponse
                            ]),
                            'info',
                            $logFile
                        );
                        $allSucceeded = false;
                        break;
                    }
                }
                if ($allSucceeded) {
                    return ['success' => true, 'message' => 'Bulk Unassignment process completed'];
                }
                $message = $unassignProductResponse['message'] ?? 'Unassign failed for one or more products';
            } else {
                $message = 'Shop Data not found';
            }
        } else {
            $message = 'container_ids required in data';
        }
        $this->di->getLog()->logContent(
            'unassignProductInBulk failed:'. json_encode([
                'user_id' => $userId,
                'shop_id' => $shopId,
                'error' => $message,
                'sqsData' => $sqsData,
            ]),
            'info',
            $logFile
        );
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    public function unassignProductFromSalesChannel($productId, $shopData)
    {
        $logFile = $this->baseLogPath . DS  . 'unassign-product-from-sales-channel' . DS . $this->di->getUser()->id . DS . date('Y-m-d') . '.log';
        if (!isset($shopData['remote_shop_id'])) {
            return [
                'success' => false,
                'message' => 'Remote shop ID not found'
            ];
        }
        try {
            $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($target, true)
                ->call('saleschannel/product/unassign', [], ['id' => $productId, 'shop_id' => $shopData['remote_shop_id']], 'POST');

            if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                $this->di->getLog()->logContent('Product successfully unassigned from sales channel: ' . json_encode([
                    'user_id' => $this->di->getUser()->id,
                    'productId' => $productId,
                ]), 'info', $logFile);
                return [
                    'success' => true,
                    'message' => 'Product successfully unassigned from sales channel'
                ];
            } else {
                $this->di->getLog()->logContent('unassignProductFromSalesChannel error: ' . json_encode([
                    'user_id' => $this->di->getUser()->id,
                    'productId' => $productId,
                    'remoteResponse' => $remoteResponse
                ]), 'info', $logFile);
                if(isset($remoteResponse['message']) && is_string($remoteResponse['message']) && str_contains($remoteResponse['message'], 'Access denied')) {
                    $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                    $userDetailsCollection = $mongo->getCollectionForTable('user_details');
                    $userDetailsCollection->updateOne(
                        [
                            'user_id' => $this->di->getUser()->id,
                            'shops' => [
                                '$elemMatch' => [
                                    '_id' => $shopData['_id'],
                                    'marketplace' => 'shopify'
                                ]
                            ]
                        ],
                        [
                            '$set' => ['shops.$.reauth_required' => true]
                        ]
                    );
                    $this->di->getObjectManager()->get('App\Shopifyhome\Components\Shop\Shop')->sendMailForReauthRequired($shopData);
                }
                return [
                    'success' => false,
                    'message' => $remoteResponse['message'] ?? json_encode($remoteResponse)
                ];
            }
        } catch (Exception $e) {
            $this->logger('Exception in unassignProductFromSalesChannel, Error: ', $e->getMessage());
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ];
        }
    }
}
