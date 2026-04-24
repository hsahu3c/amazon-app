<?php

namespace App\Shopifyhome\Components\Product;

use App\Shopifyhome\Components\Core\Common;
use App\Shopifyhome\Components\Shop\Shop as ShopifyShopHelper;
use App\Connector\Models\QueuedTasks;
use Exception;
use App\Shopifyhome\Components\Product\Vistar\Helper as VistarFileHelper;
use App\Shopifyhome\Components\Parser\JSONLParser;

class BundleComponent extends Common
{
    private const MARKETPLACE = 'shopify';
    private const PROCESS_CODE = 'shopify_bundle_component_import';
    private const PROCESS_CODE_FULL = 'shopify_bundle_component_import';
    private const PROCESS_CODE_IMPORT = 'saleschannel_product_import';
    private const QUEUE_NAME = 'shopify_bundle_component_import';
    private const REQUEST_STATUS_DELAY_SECONDS = 180; // 3 minutes
    private const MAX_CONTAINER_IDS_PER_SHOPIFY_FILTER = 2000; // Shopify's query length limits
    private const CACHE_LIFETIME_SECONDS = 7200; // 2 hours
    private const MAX_RETRY_ATTEMPTS = 10;
    private const BULK_WRITE_CHUNK = 500;
    private const READ_JSON_FILE_BATCH_SIZE = 5000;

    private ?string $logFile = null;
    private ?string $currentUserId = null;
    private ?string $currentShopId = null;
    private ?string $appCode = null;
    private ?string $appTag = null;

    private $queuedTaskContainer;
    private $productContainer;

    private $sqsHelper;
    private $apiClient;
    private ?VistarFileHelper $fileHelper = null;
    private ?JSONLParser $jsonlParser = null;


    /**
     * Initialises core services and MongoDB collections.
     */
    public function initilise(): void
    {
        $this->sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $this->apiClient = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient");

        $this->fileHelper = $this->di->getObjectManager()->get(VistarFileHelper::class);

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $this->queuedTaskContainer = $mongo->getCollection("queued_tasks");
        $this->productContainer = $mongo->getCollection("product_container");
    }

    public function afterProductImport($event, $component, $params)
    {
        if (isset($params['source_marketplace']) && $params['source_marketplace'] !== self::MARKETPLACE) {
            return true;
        }

        if (isset($params['additional_data']['filterType']) && $params['additional_data']['filterType'] == 'metafield') {
            return true;
        }

        return $this->initiateFullBundleComponentImport($params);
    }

    public function afterBatchProductImport($event, $component, $params)
    {
        if (isset($params['source_marketplace']) && $params['source_marketplace'] !== self::MARKETPLACE) {
            return true;
        }

        return $this->initiateFullBundleComponentImport($params);
    }

    /**
     * Initiates the targeted bundle component synchronization process (with product IDs).
     *
     * @param array $data Contains initial parameters, notably 'user_id'.
     * @return array Success status and message.
     */
    public function initiateBundleComponentImport(array $data): array
    {
        try {
            $this->initilise();

            if (!isset($data['user_id'])) {
                $this->logError("User ID missing for initiateBundleComponentImport.", true);
                return ['success' => false, 'message' => 'Required parameter user_id is missing.'];
            }

            if (!$this->di->getConfig()->enable_bundle_products) {
                return ['success' => false, 'message' => 'Bundle products feature is disabled.'];
            }

            $userId = $data['user_id'];

            $bundleSettings = $this->di->getConfig()->bundle_product_settings;
            $isRestrictedPerUser = $bundleSettings->restrict_per_user ?? true;
            $allowedUserIds = $bundleSettings->allowed_user_ids ? $bundleSettings->allowed_user_ids->toArray() : [];

            if ($isRestrictedPerUser && !in_array($userId, $allowedUserIds, true)) {
                return ['success' => false, 'message' => 'Bundle products not enabled for User.'];
            }

            $this->currentUserId = (string) $data['user_id'];
            $this->logFile = "shopify/bundle-component-import/{$this->currentUserId}/" . date('Y-m-d') . '.log';
            $this->logInfo("Starting ID wise Bundle Component Import Process for UserId: {$this->currentUserId}");

            $shopData = $this->getShopDataFromDiUser();
            if (!$shopData) {
                $this->logError("No active Shopify shop found for User {$this->currentUserId} from DI.");
                return ['success' => false, 'message' => 'Active Shopify shop not found. Ensure user DI is correctly set.'];
            }
            $this->currentShopId = (string) $shopData['_id'];
            $this->setAppTag($data, $shopData);

            $initialDbQueryParts = [
                'filter' => ['user_id' => $this->currentUserId, 'shop_id' => $this->currentShopId, 'is_bundle' => true],
                'projection' => ['container_id' => 1, '_id' => 0],
                'sort' => ['_id' => 1]
            ];
            $bundleProductCount = $this->productContainer->countDocuments($initialDbQueryParts['filter']);

            if ($bundleProductCount === 0) {
                $message = 'No products marked as bundles (is_bundle: true) found in our database. Skipping ID wise component import.';
                $this->logInfo($message);
                $userMsg = 'No products bundles found to import';
                $this->updateActivityLog(['user_id' => $this->currentUserId, 'shop_id' => $this->currentShopId, 'appTag' => $this->appTag], true, $userMsg, self::PROCESS_CODE);
                return ['success' => true, 'message' => $message];
            }
            $this->logInfo("Found {$bundleProductCount} bundle products in our DB for User {$this->currentUserId} for ID wise import.");

            $queuedTaskId = $this->setQueuedTask($this->currentUserId, $shopData, "Fetching Bundle Components data for {$bundleProductCount} bundle products.", self::PROCESS_CODE);
            if (!is_string($queuedTaskId)) {
                return $queuedTaskId;
            }

            $sqsMessageData = $this->buildSqsMessage(
                $queuedTaskId,
                'fetchAllBundleContainerIdsAndCache',
                [
                    'process_type' => 'targeted',
                    'initial_db_query_parts' => $initialDbQueryParts
                ]
            );
            $this->sqsHelper->pushMessage($sqsMessageData);
            return ['success' => true, 'message' => "ID wise bundle component import initiated (Feed: {$queuedTaskId}). Fetching product container IDs."];
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__, ($data['user_id'] ?? 'unknown_user'), null, $data);
            return ['success' => false, 'message' => 'Unexpected error initiating targeted bundle sync: ' . $e->getMessage()];
        }
    }

    /**
     * Initiates the full bundle component synchronization process (without product IDs filter).
     * This will trigger a bulk export of all products and process them in chunks.
     *
     * @param array $data Contains initial parameters, notably 'user_id'.
     * @return array Success status and message.
     */
    public function initiateFullBundleComponentImport(array $data): array
    {
        try {
            $this->initilise();

            if (!isset($data['user_id'])) {
                $this->logError("User ID missing for initiateFullBundleComponentImport.", true);
                return ['success' => false, 'message' => 'Required parameter user_id is missing.'];
            }

            if (!$this->di->getConfig()->enable_bundle_products) {
                return ['success' => false, 'message' => 'Bundle products feature is disabled.'];
            }

            $userId = $data['user_id'];

            $bundleSettings = $this->di->getConfig()->bundle_product_settings;
            $isRestrictedPerUser = $bundleSettings->restrict_per_user ?? true;
            $allowedUserIds = $bundleSettings->allowed_user_ids ? $bundleSettings->allowed_user_ids->toArray() : [];

            if ($isRestrictedPerUser && !in_array($userId, $allowedUserIds, true)) {
                return ['success' => false, 'message' => 'Bundle products not enabled for User.'];
            }

            $this->currentUserId = (string) $data['user_id'];
            $this->logFile = "shopify/bundle-component-import/{$this->currentUserId}/" . date('Y-m-d') . '.log';
            $this->logInfo("Starting Full Bundle Component Import Process for UserId: {$this->currentUserId}");

            $shopData = $this->getShopDataFromDiUser();
            if (!$shopData) {
                $this->logError("No active Shopify shop found for User {$this->currentUserId} from DI context.");
                return ['success' => false, 'message' => 'Active Shopify shop not found. Ensure user DI context is correctly set.'];
            }
            $this->currentShopId = (string) $shopData['_id'];
            $this->setAppTag($data, $shopData);

            $initialDbQueryParts = [
                'filter' => [
                    'user_id' => $this->currentUserId,
                    'shop_id' => $this->currentShopId,
                    'type' => 'simple',
                    'is_bundle' => true,
                    'components' => ['$exists' => false],
                ],
                'projection' => ['container_id' => 1, '_id' => 0],
                'sort' => ['_id' => 1]
            ];
            $hasBundleProducts = $this->productContainer->findOne(
                $initialDbQueryParts['filter'],
                ['projection' => ['_id' => 1]]
            );

            if (empty($hasBundleProducts)) {
                $message = 'No products marked as bundles (is_bundle: true) found in our database. Skipping ID wise component import.';
                $this->logInfo($message);
                $userMsg = 'No products bundles found to import';

                $this->updateActivityLog(['user_id' => $this->currentUserId, 'shop_id' => $this->currentShopId, 'appTag' => $this->appTag], true, $userMsg, self::PROCESS_CODE);
                return ['success' => true, 'message' => $message];
            }

            $queuedTaskId = $this->setQueuedTask($this->currentUserId, $shopData, "Fetching Components data for bundle products.", self::PROCESS_CODE_FULL);
            if (!is_string($queuedTaskId)) {
                return $queuedTaskId;
            }

            $sqsMessageData = $this->buildSqsMessage(
                $queuedTaskId,
                'triggerShopifyFullBulkOperation',
                [
                    'process_type' => 'full_import',
                    'remote_shop_id' => $shopData['remote_shop_id'] ?? null
                ]
            );

            if (empty($sqsMessageData['data']['remote_shop_id'])) {
                $this->logError("Feed {$queuedTaskId}: remote_shop_id missing for full bulk operation trigger. Cannot proceed.", true);
                $this->finaliseProcess($queuedTaskId, $sqsMessageData, false, "Missing remote shop ID for full bulk operation.", self::PROCESS_CODE_FULL);
                return ['success' => false, 'message' => "Configuration error: remote_shop_id missing."];
            }

            $this->sqsHelper->pushMessage($sqsMessageData);
            return ['success' => true, 'message' => "Full bundle component import initiated (Feed: {$queuedTaskId}). Triggering Shopify bulk export."];
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__, ($data['user_id'] ?? 'unknown_user'), null, $data);
            return ['success' => false, 'message' => 'Unexpected error initiating full bundle sync: ' . $e->getMessage()];
        }
    }


    /**
     * Fetches all bundle product container IDs from the DB and caches them (for targeted import).
     *
     * @param array $sqsData SQS message data.
     * @return array Success status and message.
     */
    public function fetchAllBundleContainerIdsAndCache(array $sqsData): array
    {
        $feedId = null;
        try {
            $this->initiliseCommon($sqsData);
            $feedId = $sqsData['feed_id'];

            $dbQueryParts = $sqsData['data']['initial_db_query_parts'] ?? null;
            if (!$dbQueryParts || !isset($dbQueryParts['filter'])) {
                $this->logError("Feed {$feedId}: DB query parts for fetching container IDs are missing or malformed in SQS data. Critical failure.");
                $this->finaliseProcess($feedId, $sqsData, false, "Configuration error: DB query for container IDs missing or corrupted in SQS.", $sqsData['data']['process_type'] == 'full_import' ? self::PROCESS_CODE_FULL : self::PROCESS_CODE);
                return ['success' => false, 'message' => "Configuration error for fetching container IDs."];
            }

            $this->logInfo("Feed {$feedId}: Fetching bundle product container_ids from our DB. Query Filter: " . json_encode($dbQueryParts['filter']));
            $cursor = $this->productContainer->find(
                $dbQueryParts['filter'],
                [
                    'projection' => ($dbQueryParts['projection'] ?? ['container_id' => 1, '_id' => 0]),
                    'sort' => ($dbQueryParts['sort'] ?? ['_id' => 1])
                ]
            );

            $bundleContainerIds = [];
            foreach ($cursor as $doc) {
                if (isset($doc['container_id']) && !empty($doc['container_id'])) {
                    $bundleContainerIds[] = (string)$doc['container_id'];
                }
            }
            $bundleContainerIds = array_values(array_unique($bundleContainerIds));

            if (empty($bundleContainerIds)) {
                $message = "No bundle products found in our DB for targeted component import. Completing for Feed {$feedId}.";
                $this->logWarning("Feed {$feedId}: " . $message);
                $this->finaliseProcess($feedId, $sqsData, true, $message, $sqsData['data']['process_type'] == 'full_import' ? self::PROCESS_CODE_FULL : self::PROCESS_CODE);
                return ['success' => true, 'message' => $message];
            }

            $totalContainerIds = count($bundleContainerIds);
            $cacheKey = $this->generateCacheKeyForContainerIds($feedId);
            $this->di->getCache()->set($cacheKey, $bundleContainerIds, self::CACHE_LIFETIME_SECONDS);
            $this->logInfo("Feed {$feedId}: Stored {$totalContainerIds} container_ids in cache with key: {$cacheKey}");
            $this->updateFeedProgress($feedId, 5, "Collected {$totalContainerIds} bundle product IDs. Preparing component fetch.", $sqsData['data']['process_type'] == 'full_import' ? self::PROCESS_CODE_FULL : self::PROCESS_CODE);

            $nextSqsData = $this->buildSqsMessageFromExisting($sqsData, 'processBundleChunk', [
                'container_ids_cache_key' => $cacheKey,
                'total_container_ids_count' => $totalContainerIds,
                'current_processing_offset' => 0,
                'initial_db_query_parts' => $dbQueryParts
            ]);
            $this->sqsHelper->pushMessage($nextSqsData);
            return ['success' => true, 'message' => 'Bundle container_ids cached. Starting chunk processing.'];
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__, $this->currentUserId, $feedId, $sqsData);
            return $this->checkRetryLimitAndRequeue($sqsData, "Error fetching/caching container IDs: " . $e->getMessage());
        }
    }

    /**
     * Generates a unique cache key for the list of container IDs for a given feed.
     *
     * @param string $feedId The ID of the queued task/feed.
     * @return string The generated cache key.
     */
    private function generateCacheKeyForContainerIds(string $feedId): string
    {
        return self::PROCESS_CODE . "_cids_{$this->currentUserId}_{$this->currentShopId}_{$feedId}";
    }

    /**
     * Processes a chunk of bundle product container IDs (for targeted import).
     *
     * @param array $sqsData SQS message data containing chunking information.
     * @return array Success status and message.
     */
    public function processBundleChunk(array $sqsData): array
    {
        $feedId = null;
        try {
            $this->initiliseCommon($sqsData);
            $feedId = $sqsData['feed_id'];

            $cacheKey = $sqsData['data']['container_ids_cache_key'] ?? null;
            $totalContainerIds = isset($sqsData['data']['total_container_ids_count']) ? (int)$sqsData['data']['total_container_ids_count'] : null;
            $offset = isset($sqsData['data']['current_processing_offset']) ? (int)$sqsData['data']['current_processing_offset'] : 0;
            $initialDbQueryParts = $sqsData['data']['initial_db_query_parts'] ?? null;

            if (!$cacheKey || $totalContainerIds === null) {
                $this->logError("Feed {$feedId}: Missing cache_key or total_container_ids_count in SQS data for processBundleChunk. Re-fetching all IDs.");
                $rebuildSqs = $this->buildSqsMessageFromExisting($sqsData, 'fetchAllBundleContainerIdsAndCache', [
                    'initial_db_query_parts' => $initialDbQueryParts,
                    'delay' => self::REQUEST_STATUS_DELAY_SECONDS
                ]);
                $this->sqsHelper->pushMessage($rebuildSqs);
                return ['success' => true, 'message' => "Essential state missing, re-initiating ID fetch for feed {$feedId}."];
            }

            if ($offset >= $totalContainerIds) {
                $finalMessage = ($totalContainerIds > 0)
                    ? "All {$totalContainerIds} bundle products' components processed for targeted import."
                    : "No bundle products found to process components for targeted import.";
                $this->logInfo("Feed {$feedId}: {$finalMessage}");
                $this->finaliseProcess($feedId, $sqsData, true, $finalMessage, self::PROCESS_CODE);
                return ['success' => true, 'message' => $finalMessage];
            }

            $allContainerIds = $this->di->getCache()->get($cacheKey);
            if ($allContainerIds === null || !is_array($allContainerIds)) {
                $this->logWarning("Feed {$feedId}: Cache miss or invalid data for key '{$cacheKey}'. Re-queuing to rebuild ID cache.");
                $rebuildSqs = $this->buildSqsMessageFromExisting($sqsData, 'fetchAllBundleContainerIdsAndCache', [
                    'initial_db_query_parts' => $initialDbQueryParts,
                    'delay' => self::REQUEST_STATUS_DELAY_SECONDS
                ]);
                $this->sqsHelper->pushMessage($rebuildSqs);
                return ['success' => true, 'message' => "Cache miss, rebuilding ID list for feed {$feedId}."];
            }

            $containerIdsForThisChunk = array_slice($allContainerIds, $offset, self::MAX_CONTAINER_IDS_PER_SHOPIFY_FILTER);
            if (empty($containerIdsForThisChunk)) {
                $this->logInfo("Feed {$feedId}: No more container IDs in this slice (offset {$offset}, total {$totalContainerIds}). Finalizing targeted import.");
                $this->finaliseProcess($feedId, $sqsData, true, "Targeted bundle component import completed.", self::PROCESS_CODE);
                return ['success' => true, 'message' => "Targeted bundle component import completed."];
            }

            $shopifyQueryFilter = 'id:' . implode(' OR id:', $containerIdsForThisChunk);
            $progress = min(round((($offset + count($containerIdsForThisChunk)) / $totalContainerIds) * 90, 0) + 5, 95);
            $chunkNumber = floor($offset / self::MAX_CONTAINER_IDS_PER_SHOPIFY_FILTER) + 1;
            $totalChunks = ceil($totalContainerIds / self::MAX_CONTAINER_IDS_PER_SHOPIFY_FILTER);
            $this->updateFeedProgress($feedId, $progress, "Processing components for chunk {$chunkNumber} of {$totalChunks} (targeted import).", self::PROCESS_CODE);
            $this->logInfo("Feed {$feedId}: Processing chunk {$chunkNumber}/{$totalChunks} with " . count($containerIdsForThisChunk) . " container_ids. Offset: {$offset}.");

            $nextSqsData = $this->buildSqsMessageFromExisting($sqsData, 'triggerShopifyBulkOperationForChunk', [
                'shopify_query_filter' => $shopifyQueryFilter,
                'num_container_ids_in_this_chunk' => count($containerIdsForThisChunk),
                'offset_for_this_chunk_attempt' => $offset,
            ]);
            $this->sqsHelper->pushMessage($nextSqsData);
            return ['success' => true, 'message' => "Feed {$feedId}: Dispatched chunk {$chunkNumber} for Shopify bulk operation (targeted)."];
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__, $this->currentUserId, $feedId, $sqsData);
            return $this->checkRetryLimitAndRequeue($sqsData, "Error processing bundle chunk: " . $e->getMessage());
        }
    }

    /**
     * Triggers a Shopify bulk operation for a given chunk of product IDs (for targeted import).
     *
     * @param array $sqsData SQS message data.
     * @return array Success status and message.
     */
    public function triggerShopifyBulkOperationForChunk(array $sqsData): array
    {
        $feedId = null;
        try {
            $this->initiliseCommon($sqsData);
            $feedId = $sqsData['feed_id'];

            $shopifyQueryFilter = $sqsData['data']['shopify_query_filter'] ?? null;
            $numContainerIdsInChunk = isset($sqsData['data']['num_container_ids_in_this_chunk']) ? (int)$sqsData['data']['num_container_ids_in_this_chunk'] : null;
            $offsetForThisChunkAttempt = isset($sqsData['data']['offset_for_this_chunk_attempt']) ? (int)$sqsData['data']['offset_for_this_chunk_attempt'] : null;
            $initialDbQueryParts = $sqsData['data']['initial_db_query_parts'] ?? null;

            $requiredKeys = ['shopify_query_filter', 'num_container_ids_in_this_chunk', 'shop', 'container_ids_cache_key', 'total_container_ids_count', 'offset_for_this_chunk_attempt', 'initial_db_query_parts'];
            foreach ($requiredKeys as $key) {
                if ($key === 'shop' && !isset($sqsData[$key])) {
                    $this->logError("Feed {$feedId}: Missing required SQS key '{$key}' for triggerShopifyBulkOperationForChunk. Re-queuing to processBundleChunk.");
                    return $this->checkRetryLimitAndRequeue($sqsData, "Missing SQS data (top level) for bulk op trigger.", 'processBundleChunk', self::REQUEST_STATUS_DELAY_SECONDS);
                } elseif ($key !== 'shop' && !isset($sqsData['data'][$key])) {
                    $this->logError("Feed {$feedId}: Missing required SQS key 'data->{$key}' for triggerShopifyBulkOperationForChunk. Re-queuing to processBundleChunk.");
                    return $this->checkRetryLimitAndRequeue($sqsData, "Missing SQS data (in 'data' array) for bulk op trigger.", 'processBundleChunk', self::REQUEST_STATUS_DELAY_SECONDS);
                }
            }


            $remoteShopId = $sqsData['shop']['remote_shop_id'] ?? null;
            if (!$remoteShopId) {
                $this->logError("Feed {$feedId}: remote_shop_id missing from shop data in SQS.");
                $this->finaliseProcess($feedId, $sqsData, false, "Configuration error: remote_shop_id missing.", self::PROCESS_CODE);
                return ['success' => false, 'message' => "Configuration error: remote_shop_id missing."];
            }

            $this->logInfo("Feed {$feedId}: Triggering Shopify bulk op (type: productWithFilter) for chunk (targeted). Filter: " . substr($shopifyQueryFilter, 0, 100));
            $paramsToShopify = [
                'shop_id' => $remoteShopId,
                'query_filter' => $shopifyQueryFilter,
                'type' => 'productWithFilter',
                'save_file' => 1
            ];
            $remoteResponse = $this->apiClient->init(self::MARKETPLACE, true)->call('/bulk/operation', [], $paramsToShopify, 'POST');

            $operationId = null;
            $operationStatus = null;
            if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                if (isset($remoteResponse['data']['bulkOperationRunQuery']['bulkOperation']['id'])) {
                    $operationId = $remoteResponse['data']['bulkOperationRunQuery']['bulkOperation']['id'];
                    $operationStatus = $remoteResponse['data']['bulkOperationRunQuery']['bulkOperation']['status'];
                } elseif (isset($remoteResponse['data']['id'])) {
                    $operationId = $remoteResponse['data']['id'];
                    $operationStatus = $remoteResponse['data']['status'] ?? 'CREATED';
                }
            }

            if (!$operationId) {
                $errorMsg = $this->formatRemoteErrorMessage("Feed {$feedId}: Failed to start Shopify bulk operation for chunk (targeted).", $remoteResponse);
                $this->logError($errorMsg . " Raw Shopify response: " . json_encode($remoteResponse));
                $this->updateActivityLog($sqsData, false, "Failed to start Shopify import for a component batch (targeted). Feed {$feedId}. Will retry.", self::PROCESS_CODE);
                return $this->checkRetryLimitAndRequeue($sqsData, $errorMsg, null, self::REQUEST_STATUS_DELAY_SECONDS * 2);
            }

            $this->logInfo("Feed {$feedId}: Shopify Bulk op started (targeted). ID: {$operationId}, Status: {$operationStatus}.");

            $sqsForStatusCheck = $this->buildSqsMessageFromExisting($sqsData, 'checkShopifyBulkOperationStatus', [
                'bulk_operation_id' => $operationId,
                'remote_shop_id' => $remoteShopId,
                'delay' => self::REQUEST_STATUS_DELAY_SECONDS
            ]);
            $this->sqsHelper->pushMessage($sqsForStatusCheck);
            return ['success' => true, 'message' => "Feed {$feedId}: Shopify bulk op for chunk dispatched for status check (targeted)."];
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__, $this->currentUserId, $feedId, $sqsData);
            return $this->checkRetryLimitAndRequeue($sqsData, 'Exception triggering Shopify bulk op (targeted): ' . $e->getMessage(), null, self::REQUEST_STATUS_DELAY_SECONDS * 3);
        }
    }

    /**
     * Checks the status of a Shopify bulk operation (for targeted import).
     *
     * @param array $sqsData SQS message data including bulk operation ID.
     * @return array Success status and message.
     */
    public function checkShopifyBulkOperationStatus(array $sqsData): array
    {
        $feedId = null;
        try {
            $this->initiliseCommon($sqsData);
            $feedId = $sqsData['feed_id'];

            $bulkOpId = $sqsData['data']['bulk_operation_id'] ?? null;
            $remoteShopId = $sqsData['data']['remote_shop_id'] ?? null;
            $offsetForThisChunkAttempt = $sqsData['data']['offset_for_this_chunk_attempt'] ?? 0;
            $numContainerIdsInThisChunk = $sqsData['data']['num_container_ids_in_this_chunk'] ?? 0;

            if (!$bulkOpId || !$remoteShopId) {
                $this->logError("Feed {$feedId}: Missing bulk_operation_id or remote_shop_id for status check (targeted). Re-queuing to processBundleChunk.");
                return $this->checkRetryLimitAndRequeue($sqsData, "Missing bulk op ID or shop ID for status check (targeted).", 'processBundleChunk', self::REQUEST_STATUS_DELAY_SECONDS);
            }

            $this->logInfo("Feed {$feedId}: Checking status for Shopify bulk op ID: {$bulkOpId} (targeted).");

            $filterForStatus = [
                'shop_id' => $remoteShopId,
                'id' => $bulkOpId,
                'save_file' => 1
            ];
            $targetMarketplace = $this->di->getObjectManager()->get(ShopifyShopHelper::class)->getUserMarkeplace();
            $remoteResponse = $this->apiClient->init($targetMarketplace, true)->call('/bulk/operation', [], $filterForStatus, 'GET');

            if (!isset($remoteResponse['success']) || !$remoteResponse['success'] || !isset($remoteResponse['data']['status'])) {
                $errorMsg = $this->formatRemoteErrorMessage("Feed {$feedId}: Unsuccessful or malformed response from status check API for bulk op {$bulkOpId} (targeted).", $remoteResponse);
                $this->logError($errorMsg . " Raw Response: " . json_encode($remoteResponse));
                return $this->checkRetryLimitAndRequeue($sqsData, $errorMsg, null, self::REQUEST_STATUS_DELAY_SECONDS * 2);
            }

            $operationStatus = $remoteResponse['data']['status'];
            $objectCount = $remoteResponse['data']['objectCount'] ?? 0;
            $this->logInfo("Feed {$feedId}: Shopify Bulk op {$bulkOpId} status: {$operationStatus} (targeted). Object count: {$objectCount}.");

            switch ($operationStatus) {
                case 'RUNNING':
                case 'CREATED':
                    $this->logInfo("Feed {$feedId}: Shopify Bulk op {$bulkOpId} still {$operationStatus} (targeted). Re-queuing status check.");
                    $this->updateFeedProgress($feedId, -1, "Shopify is processing components batch (Status: {$operationStatus}, targeted). Products in batch: {$objectCount}.", self::PROCESS_CODE);
                    return $this->checkRetryLimitAndRequeue($sqsData, "Operation {$operationStatus} (targeted).", null, self::REQUEST_STATUS_DELAY_SECONDS);

                case 'COMPLETED':
                    $downloadUrl = $remoteResponse['data']['url'] ?? null;
                    $filePathFromCedClient = $remoteResponse['data']['file_path'] ?? null;

                    $actualFilePath = null;

                    if ($filePathFromCedClient && file_exists($filePathFromCedClient)) {
                        $actualFilePath = $filePathFromCedClient;
                        $this->logInfo("Feed {$feedId}: Using file_path '{$filePathFromCedClient}' provided by CED API client for completed bulk op {$bulkOpId} (targeted).");
                    } elseif ($downloadUrl && $this->fileHelper) {
                        $this->logInfo("Feed {$feedId}: Downloading remote file from {$downloadUrl} for op {$bulkOpId} (targeted).");
                        $fileSaveResult = $this->fileHelper->saveFile($downloadUrl);
                        if (is_array($fileSaveResult) && isset($fileSaveResult['success']) && $fileSaveResult['success'] && isset($fileSaveResult['file_path'])) {
                            $actualFilePath = $fileSaveResult['file_path'];
                            $this->logInfo("Feed {$feedId}: Successfully downloaded bulk operation result to '{$actualFilePath}' (targeted).");
                        } else {
                            $message = "Feed {$feedId}: Failed to save downloaded file from remote URL for op {$bulkOpId} (targeted). Helper response: " . json_encode($fileSaveResult);
                            $this->logError($message);
                            $this->updateActivityLog($sqsData, false, $message, self::PROCESS_CODE);
                            $this->requeueToProcessNextChunk($feedId, $sqsData, $offsetForThisChunkAttempt, $numContainerIdsInThisChunk);
                            return ['success' => true, 'message' => $message . " Proceeding to next product chunk (targeted)."];
                        }
                    } else {
                        $message = "Feed {$feedId}: Bulk op {$bulkOpId} COMPLETED, but no usable file path obtained (targeted).";
                        $this->logError($message);
                        $this->updateActivityLog($sqsData, false, $message, self::PROCESS_CODE);
                        $this->requeueToProcessNextChunk($feedId, $sqsData, $offsetForThisChunkAttempt, $numContainerIdsInThisChunk);
                        return ['success' => true, 'message' => $message . " Proceeding to next product chunk (targeted)."];
                    }

                    if ($objectCount == 0) {
                        $this->logInfo("Feed {$feedId}: Shopify Bulk op {$bulkOpId} completed with 0 objects (targeted). No data for this chunk. Cleaning file.");
                        if ($actualFilePath && file_exists($actualFilePath)) {
                            unlink($actualFilePath);
                            $this->logInfo("Feed {$feedId}: Cleaned up empty file {$actualFilePath} (targeted).");
                        }
                        $this->requeueToProcessNextChunk($feedId, $sqsData, $offsetForThisChunkAttempt, $numContainerIdsInThisChunk);
                        return ['success' => true, 'message' => "Bulk op completed with no data (targeted), proceeding to next chunk."];
                    }

                    if ($actualFilePath) {
                        $sqsForRead = $this->buildSqsMessageFromExisting($sqsData, 'readAndProcessBundleComponentFile', [
                            'jsonl_file_path' => $actualFilePath,
                            'bulk_operation_id' => $bulkOpId,
                        ]);
                        $this->sqsHelper->pushMessage($sqsForRead);
                        return ['success' => true, 'message' => "Feed {$feedId}: File ready at '{$actualFilePath}', proceeding to parse (targeted)."];
                    } else {
                        $message = "Feed {$feedId}: Bulk op {$bulkOpId} COMPLETED, but no usable file path obtained after all attempts (targeted).";
                        $this->logError($message);
                        $this->updateActivityLog($sqsData, false, $message, self::PROCESS_CODE);
                        $this->requeueToProcessNextChunk($feedId, $sqsData, $offsetForThisChunkAttempt, $numContainerIdsInThisChunk);
                        return ['success' => true, 'message' => $message . " Proceeding to next product chunk (targeted)."];
                    }

                case 'FAILED':
                case 'CANCELED':
                case 'EXPIRED':
                    $errorCode = $remoteResponse['data']['errorCode'] ?? 'UNKNOWN';
                    $message = "Feed {$feedId}: Shopify Bulk op {$bulkOpId} {$operationStatus} (targeted). ErrorCode: {$errorCode}.";
                    $this->logError($message);
                    $this->updateActivityLog($sqsData, false, $message, self::PROCESS_CODE);
                    $this->requeueToProcessNextChunk($feedId, $sqsData, $offsetForThisChunkAttempt, $numContainerIdsInThisChunk);
                    return ['success' => true, 'message' => $message . " Proceeding to next product chunk (targeted)."];

                default:
                    $message = "Feed {$feedId}: Shopify Bulk op {$bulkOpId} has unexpected status: {$operationStatus} (targeted).";
                    $this->logError($message . " Raw Shopify response: " . json_encode($remoteResponse));
                    $this->updateActivityLog($sqsData, false, $message, self::PROCESS_CODE);
                    return $this->checkRetryLimitAndRequeue($sqsData, $message, null, self::REQUEST_STATUS_DELAY_SECONDS);
            }
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__, $this->currentUserId, $feedId, $sqsData);
            return $this->checkRetryLimitAndRequeue($sqsData, 'Exception checking Shopify bulk op status (targeted): ' . $e->getMessage(), null, self::REQUEST_STATUS_DELAY_SECONDS * 2);
        }
    }

    /**
     * Reads and processes the downloaded JSONL file containing bundle component data (for targeted import).
     *
     * @param array $sqsData SQS message data.
     * @return array Success status and message.
     */
    public function readAndProcessBundleComponentFile(array $sqsData): array
    {
        $feedId = null;
        $jsonlFilePath = null;
        try {
            $this->initiliseCommon($sqsData);
            $feedId = $sqsData['feed_id'];

            $offsetForThisChunkAttempt = $sqsData['data']['offset_for_this_chunk_attempt'] ?? 0;
            $numContainerIdsInThisChunk = $sqsData['data']['num_container_ids_in_this_chunk'] ?? 0;

            if (!isset($sqsData['data']['jsonl_file_path'])) {
                $this->logError("Feed {$feedId}: jsonl_file_path missing for readAndProcessBundleComponentFile (targeted).");
                $this->requeueToProcessNextChunk($feedId, $sqsData, $offsetForThisChunkAttempt, $numContainerIdsInThisChunk);
                return ['success' => true, 'message' => "File path missing, proceeding to next product chunk for feed {$feedId} (targeted)."];
            }
            $jsonlFilePath = $sqsData['data']['jsonl_file_path'];

            $this->logInfo("Feed {$feedId}: Reading and processing bundle component file: {$jsonlFilePath} (targeted).");

            if (!file_exists($jsonlFilePath)) {
                $this->logError("Feed {$feedId}: Downloaded JSONL file not found at {$jsonlFilePath} (targeted).");
                $this->updateActivityLog($sqsData, false, "Downloaded component file not found for feed {$feedId} (targeted).", self::PROCESS_CODE);
                $this->requeueToProcessNextChunk($feedId, $sqsData, $offsetForThisChunkAttempt, $numContainerIdsInThisChunk);
                return ['success' => true, 'message' => "File not found, proceeding to next product chunk for feed {$feedId} (targeted)."];
            }

            $handle = @fopen($jsonlFilePath, 'r');
            if (!$handle) {
                $this->logError("Feed {$feedId}: Unable to open JSONL File: {$jsonlFilePath} (targeted). Error: " . (error_get_last()['message'] ?? 'Unknown error.'));
                $this->updateActivityLog($sqsData, false, "Unable to read component file for feed {$feedId} (targeted).", self::PROCESS_CODE);
                $this->requeueToProcessNextChunk($feedId, $sqsData, $offsetForThisChunkAttempt, $numContainerIdsInThisChunk);
                return ['success' => true, 'message' => "Unable to read file, proceeding to next product chunk for feed {$feedId} (targeted)."];
            }

            $productsFromFile = [];
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $productData = json_decode($line, true);
                if (is_array($productData) && isset($productData['id'])) {
                    $productsFromFile[] = $productData;
                } else {
                    $this->logWarning("Feed {$feedId}: Skipping invalid line in JSONL: " . substr($line, 0, 150) . " (targeted).");
                }
            }
            fclose($handle);

            if (!empty($productsFromFile)) {
                $updatedCount = $this->saveBundleComponentsToDb($sqsData, $productsFromFile);
                $this->logInfo("Feed {$feedId}: Saved components for {$updatedCount} products from file {$jsonlFilePath} (targeted).");
            } else {
                $this->logInfo("Feed {$feedId}: No valid product data found in file {$jsonlFilePath} to process (targeted).");
            }

            $this->requeueToProcessNextChunk(
                $feedId,
                $sqsData,
                $offsetForThisChunkAttempt,
                $numContainerIdsInThisChunk
            );
            return ['success' => true, 'message' => "Feed {$feedId}: Component data from file processed. Queued for next product chunk (targeted)."];
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__, $this->currentUserId, $feedId, $sqsData);
            return $this->checkRetryLimitAndRequeue($sqsData, 'Exception reading/processing bundle file (targeted): ' . $e->getMessage(), null, self::REQUEST_STATUS_DELAY_SECONDS);
        } finally {
            if ($jsonlFilePath && file_exists($jsonlFilePath)) {
                unlink($jsonlFilePath);
                $this->logInfo("Feed " . ($feedId ?? 'N/A') . ": Cleaned up file {$jsonlFilePath} (targeted).");
            }
        }
    }

    /**
     * Requeues a message to processBundleChunk for the *next* set of container_ids (for targeted import).
     * This method handles incrementing the offset.
     *
     * @param string $feedId The current feed ID.
     * @param array $currentSqsData The SQS data from the current message being processed.
     * @param int $offsetOfProcessedChunk The starting offset of the chunk that was just processed (or attempted).
     * @param int $countInProcessedChunk The number of items expected in the chunk that was just processed (or attempted).
     * @param array $options Optional parameters to merge into the SQS message (e.g., 'delay').
     * @return void
     */
    private function requeueToProcessNextChunk(string $feedId, array $currentSqsData, int $offsetOfProcessedChunk, int $countInProcessedChunk, array $options = []): void
    {
        $nextOffset = $offsetOfProcessedChunk + $countInProcessedChunk;

        $sqsMessageForNextChunk = $this->buildSqsMessageFromExisting($currentSqsData, 'processBundleChunk', array_merge([
            'current_processing_offset' => $nextOffset,
        ], $options));

        unset(
            $sqsMessageForNextChunk['data']['bulk_operation_id'],
            $sqsMessageForNextChunk['data']['remote_shop_id'],
            $sqsMessageForNextChunk['data']['shopify_query_filter'],
            $sqsMessageForNextChunk['data']['num_container_ids_in_this_chunk'],
            $sqsMessageForNextChunk['data']['offset_for_this_chunk_attempt'],
            $sqsMessageForNextChunk['data']['jsonl_file_path'],
            $sqsMessageForNextChunk['data']['retry_count']
        );

        $this->sqsHelper->pushMessage($sqsMessageForNextChunk);
        $this->logInfo("Feed {$feedId}: Requeued to processBundleChunk with new offset {$nextOffset}.");
    }

    // --- New Full Import Flow Methods ---

    /**
     * Triggers a Shopify bulk operation to export ALL products (for full import).
     *
     * @param array $sqsData SQS message data containing shop_id.
     * @return array Success status and message.
     */
    public function triggerShopifyFullBulkOperation(array $sqsData): array
    {
        $feedId = null;
        try {
            $this->initiliseCommon($sqsData);
            $feedId = $sqsData['feed_id'];

            $remoteShopId = $sqsData['data']['remote_shop_id'] ?? null;
            if (!$remoteShopId) {
                $this->logError("Feed {$feedId}: remote_shop_id missing from SQS data for full bulk operation trigger. Critical failure.");
                $this->finaliseProcess($feedId, $sqsData, false, "Configuration error: remote_shop_id missing for full bulk operation.", self::PROCESS_CODE_FULL);
                return ['success' => false, 'message' => "Configuration error: remote_shop_id missing."];
            }

            $this->logInfo("Feed {$feedId}: Triggering Shopify full bulk export for all products.");
            $paramsToShopify = [
                'shop_id' => $remoteShopId,
                'type' => 'bundleProducts',
                'save_file' => 1
            ];
            $remoteResponse = $this->apiClient->init(self::MARKETPLACE, true)->call('/bulk/operation', [], $paramsToShopify, 'POST');

            $operationId = null;
            $operationStatus = null;
            // The original code has this:
            // if (isset($remoteResponse['data']['bulkOperationRunQuery']['bulkOperation']['id'])) {
            //     $operationId = $remoteResponse['data']['bulkOperationRunQuery']['bulkOperation']['id'];
            //     $operationStatus = $remoteResponse['data']['bulkOperationRunQuery']['bulkOperation']['status'];
            // } elseif (isset($remoteResponse['data']['id'])) {
            //     $operationId = $remoteResponse['data']['id'];
            //     $operationStatus = $remoteResponse['data']['status'] ?? 'CREATED';
            // }
            // Let's assume the previous `if (!empty($remoteResponse['success']) && !empty($remoteResponse['data']['id']))` was simplified and correct,
            // but the original more robust check from `triggerShopifyBulkOperationForChunk` is better to keep here:
            if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                if (isset($remoteResponse['data']['bulkOperationRunQuery']['bulkOperation']['id'])) { // Direct GQL-like response from proxy
                    $operationId = $remoteResponse['data']['bulkOperationRunQuery']['bulkOperation']['id'];
                    $operationStatus = $remoteResponse['data']['bulkOperationRunQuery']['bulkOperation']['status'];
                } elseif (isset($remoteResponse['data']['id'])) { // Simpler proxy response
                    $operationId = $remoteResponse['data']['id'];
                    $operationStatus = $remoteResponse['data']['status'] ?? 'CREATED';
                }
            }

            if (!$operationId) {
                $errorMsg = $this->formatRemoteErrorMessage("Feed {$feedId}: Failed to start Shopify full bulk operation.", $remoteResponse);
                $this->logError($errorMsg . " Raw Shopify response: " . json_encode($remoteResponse));
                $this->updateActivityLog($sqsData, false, "Failed to start Shopify full product export. Feed {$feedId}. Will retry.", self::PROCESS_CODE_FULL);
                return $this->checkRetryLimitAndRequeue($sqsData, $errorMsg, null, self::REQUEST_STATUS_DELAY_SECONDS * 2);
            }

            $this->logInfo("Feed {$feedId}: Shopify Full Bulk op started. ID: {$operationId}, Status: {$operationStatus}.");

            $sqsForStatusCheck = $this->buildSqsMessageFromExisting($sqsData, 'checkShopifyFullBulkOperationStatus', [
                'bulk_operation_id' => $operationId,
                'remote_shop_id' => $remoteShopId,
                'delay' => self::REQUEST_STATUS_DELAY_SECONDS
            ]);
            $this->sqsHelper->pushMessage($sqsForStatusCheck);
            return ['success' => true, 'message' => "Feed {$feedId}: Shopify full bulk op dispatched for status check."];
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__, $this->currentUserId, $feedId, $sqsData);
            return $this->checkRetryLimitAndRequeue($sqsData, 'Exception triggering Shopify full bulk op: ' . $e->getMessage(), null, self::REQUEST_STATUS_DELAY_SECONDS * 3);
        }
    }

    /**
     * Checks the status of a Shopify full bulk operation.
     *
     * @param array $sqsData SQS message data.
     * @return array Success status and message.
     */
    public function checkShopifyFullBulkOperationStatus(array $sqsData): array
    {
        $feedId = null;
        try {
            $this->initiliseCommon($sqsData);
            $feedId = $sqsData['feed_id'];

            $bulkOpId = $sqsData['data']['bulk_operation_id'] ?? null;
            $remoteShopId = $sqsData['data']['remote_shop_id'] ?? null;

            if (!$bulkOpId || !$remoteShopId) {
                $this->logError("Feed {$feedId}: Missing bulk_operation_id or remote_shop_id for full bulk status check. Critical failure.");
                $this->finaliseProcess($feedId, $sqsData, false, "Missing data for full bulk status check.", self::PROCESS_CODE_FULL);
                return ['success' => false, 'message' => "Missing data for full bulk status check."];
            }

            $this->logInfo("Feed {$feedId}: Checking status for Shopify full bulk op ID: {$bulkOpId}.");

            $filterForStatus = [
                'shop_id' => $remoteShopId,
                'id' => $bulkOpId,
                'save_file' => 1
            ];
            $targetMarketplace = $this->di->getObjectManager()->get(ShopifyShopHelper::class)->getUserMarkeplace();
            $remoteResponse = $this->apiClient->init($targetMarketplace, true)->call('/bulk/operation', [], $filterForStatus, 'GET');

            if (!isset($remoteResponse['success']) || !$remoteResponse['success'] || !isset($remoteResponse['data']['status'])) {
                $errorMsg = $this->formatRemoteErrorMessage("Feed {$feedId}: Unsuccessful or malformed response from status check API for full bulk op {$bulkOpId}.", $remoteResponse);
                $this->logError($errorMsg . " Raw Response: " . json_encode($remoteResponse));
                return $this->checkRetryLimitAndRequeue($sqsData, $errorMsg, null, self::REQUEST_STATUS_DELAY_SECONDS * 2);
            }

            $operationStatus = $remoteResponse['data']['status'];
            $objectCount = $remoteResponse['data']['objectCount'] ?? 0;
            $this->logInfo("Feed {$feedId}: Shopify Full Bulk op {$bulkOpId} status: {$operationStatus}. Object count: {$objectCount}.");

            switch ($operationStatus) {
                case 'RUNNING':
                case 'CREATED':
                    $this->logInfo("Feed {$feedId}: Shopify Full Bulk op {$bulkOpId} still {$operationStatus}. Re-queuing status check.");
                    $this->updateFeedProgress($feedId, -1, "Shopify is processing full product export (Status: {$operationStatus}). Products: {$objectCount}.", self::PROCESS_CODE_FULL);
                    return $this->checkRetryLimitAndRequeue($sqsData, "Full operation {$operationStatus}.", null, self::REQUEST_STATUS_DELAY_SECONDS);

                case 'COMPLETED':
                    $downloadUrl = $remoteResponse['data']['url'] ?? null;

                    $actualFilePath = null;

                    if ($downloadUrl && $this->fileHelper) {
                        $this->logInfo("Feed {$feedId}: Downloading remote file from {$downloadUrl} for full op {$bulkOpId}.");
                        $fileSaveResult = $this->fileHelper->saveFile($downloadUrl);
                        if (is_array($fileSaveResult) && isset($fileSaveResult['success']) && $fileSaveResult['success'] && isset($fileSaveResult['file_path'])) {
                            $actualFilePath = $fileSaveResult['file_path'];
                            $this->logInfo("Feed {$feedId}: Successfully downloaded full bulk operation result to '{$actualFilePath}'.");
                        } else {
                            $message = "Feed {$feedId}: Failed to save downloaded file from remote URL for full op {$bulkOpId}. Helper response: " . json_encode($fileSaveResult);
                            $this->logError($message);
                            $this->updateActivityLog($sqsData, false, $message, self::PROCESS_CODE_FULL);
                            $this->finaliseProcess($feedId, $sqsData, false, $message, self::PROCESS_CODE_FULL);
                            return ['success' => false, 'message' => $message];
                        }
                    } else {
                        $message = "Feed {$feedId}: Full Bulk op {$bulkOpId} COMPLETED, but no URL obtained for import.";
                        $this->logError($message);
                        $this->updateActivityLog($sqsData, false, $message, self::PROCESS_CODE_FULL);
                        $this->finaliseProcess($feedId, $sqsData, false, $message, self::PROCESS_CODE_FULL);
                        return ['success' => false, 'message' => $message];
                    }

                    if ($objectCount == 0) {
                        $this->logInfo("Feed {$feedId}: Shopify Full Bulk op {$bulkOpId} completed with 0 objects. No data for full import. Cleaning file.");
                        if ($actualFilePath && file_exists($actualFilePath)) {
                            unlink($actualFilePath);
                            $this->logInfo("Feed {$feedId}: Cleaned up empty file {$actualFilePath} (full).");
                        }
                        $this->finaliseProcess($feedId, $sqsData, true, "Full Bundle Components completed with no products.", self::PROCESS_CODE_FULL);
                        return ['success' => true, 'message' => "Full import completed with no data."];
                    }

                    if ($actualFilePath) {
                        $sqsForRead = $this->buildSqsMessageFromExisting($sqsData, 'readAndProcessFullBundleComponentFileChunk', [
                            'jsonl_file_path' => $actualFilePath,
                            'total_rows' => $objectCount,
                            'jsonl_next_cursor' => null, // Start from beginning of file (null implies first read)
                            'updated_products_count' => 0,
                            'rows_processed' => 0,
                            'bulk_operation_id' => $bulkOpId,
                        ]);
                        $this->sqsHelper->pushMessage($sqsForRead);
                        return ['success' => true, 'message' => "Feed {$feedId}: Full file ready at '{$actualFilePath}', starting chunked parse."];
                    } else {
                        $message = "Feed {$feedId}: Full Bulk op {$bulkOpId} COMPLETED, but no usable file path obtained for full import after all attempts.";
                        $this->logError($message);
                        $this->updateActivityLog($sqsData, false, $message, self::PROCESS_CODE_FULL);
                        $this->finaliseProcess($feedId, $sqsData, false, $message, self::PROCESS_CODE_FULL);
                        return ['success' => false, 'message' => $message];
                    }

                case 'FAILED':
                case 'CANCELED':
                case 'EXPIRED':
                    $errorCode = $remoteResponse['data']['errorCode'] ?? 'UNKNOWN';
                    $message = "Feed {$feedId}: Shopify Full Bulk op {$bulkOpId} {$operationStatus}. ErrorCode: {$errorCode}.";
                    $this->logError($message);
                    $this->updateActivityLog($sqsData, false, $message, self::PROCESS_CODE_FULL);
                    $this->finaliseProcess($feedId, $sqsData, false, $message, self::PROCESS_CODE_FULL);
                    return ['success' => false, 'message' => $message];

                default:
                    $message = "Feed {$feedId}: Shopify Full Bulk op {$bulkOpId} has unexpected status: {$operationStatus}.";
                    $this->logError($message . " Raw Shopify response: " . json_encode($remoteResponse));
                    $this->updateActivityLog($sqsData, false, $message, self::PROCESS_CODE_FULL);
                    return $this->checkRetryLimitAndRequeue($sqsData, $message, null, self::REQUEST_STATUS_DELAY_SECONDS);
            }
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__, $this->currentUserId, $feedId, $sqsData);
            return $this->checkRetryLimitAndRequeue($sqsData, 'Exception checking Shopify full bulk op status: ' . $e->getMessage(), null, self::REQUEST_STATUS_DELAY_SECONDS * 2);
        }
    }

    /**
     * Reads and processes a chunk of the full JSONL file for bundle components.
     * This method is part of the 'full import' flow.
     *
     * @param array $sqsData SQS message data including file path, seek, total_rows etc.
     * @return array Success status and message.
     */
    public function readAndProcessFullBundleComponentFileChunk(array $sqsData): array
    {
        $feedId = null;
        $jsonlFilePath = null;
        $processCode = self::PROCESS_CODE_FULL;
        try {
            $this->initiliseCommon($sqsData);
            $feedId = $sqsData['feed_id'];

            $jsonlFilePath = $sqsData['data']['jsonl_file_path'] ?? null;
            $totalRows = $sqsData['data']['total_rows'] ?? 0;
            $jsonlNextCursor = $sqsData['data']['jsonl_next_cursor'] ?? null; // The cursor from JSONLParser
            $updatedProductsCount = $sqsData['data']['updated_products_count'] ?? 0;
            $rowsProcessedOverall = $sqsData['data']['rows_processed'] ?? 0;

            if (!$jsonlFilePath) {
                $this->logError("Feed {$feedId}: jsonl_file_path missing for readAndProcessFullBundleComponentFileChunk.");
                $this->finaliseProcess($feedId, $sqsData, false, "File path missing for full import file processing.", $processCode);
                return ['success' => false, 'message' => "File path missing for full import."];
            }

            if (!file_exists($jsonlFilePath)) {
                $this->logError("Feed {$feedId}: Full JSONL file not found at {$jsonlFilePath}.");
                $this->updateActivityLog($sqsData, false, "Full import file not found for feed {$feedId}.", $processCode);
                $this->finaliseProcess($feedId, $sqsData, false, "Full import file not found.", $processCode);
                return ['success' => false, 'message' => "Full import file not found."];
            }

            // Instantiate JSONLParser for this chunk
            $this->jsonlParser = $this->di->getObjectManager()->create(JSONLParser::class, [$jsonlFilePath]);

            $this->logInfo("Feed {$feedId}: Reading and processing full bundle component file chunk: {$jsonlFilePath}. Cursor: " . (string)$jsonlNextCursor);

            $getItemsParams = [
                'limit' => self::READ_JSON_FILE_BATCH_SIZE
            ];
            if ($jsonlNextCursor) {
                $getItemsParams['next'] = $jsonlNextCursor;
            }

            $parserResponse = $this->jsonlParser->getItems($getItemsParams);

            if (!isset($parserResponse['success']) || !$parserResponse['success']) {
                $message = "Feed {$feedId}: Failed to read JSONL file chunk using JSONLParser. Error: " . ($parserResponse['msg'] ?? 'Unknown parser error.');
                $this->logError($message);
                $this->updateActivityLog($sqsData, false, $message, $processCode);
                return $this->checkRetryLimitAndRequeue($sqsData, $message, null, self::REQUEST_STATUS_DELAY_SECONDS);
            }

            $productsFromFile = $parserResponse['data'] ?? [];
            $newJsonlNextCursor = $parserResponse['cursors']['next'] ?? null;
            $linesReadThisChunk = count($productsFromFile);

            if (!empty($productsFromFile)) {
                $savedCount = $this->saveBundleComponentsToDb($sqsData, $productsFromFile);
                $this->logInfo("Feed {$feedId}: Saved components for {$savedCount} products from full file chunk. Records in this chunk: " . count($productsFromFile));
                $updatedProductsCount += $savedCount;
            } else {
                $this->logInfo("Feed {$feedId}: No valid product data found in this full file chunk to process or end of file reached.");
            }

            $rowsProcessedOverall += $linesReadThisChunk;

            // Determine if more chunks need to be processed by checking the next cursor
            if ($newJsonlNextCursor) {
                $progress = ($totalRows > 0) ? min(round(($rowsProcessedOverall / $totalRows) * 100, 2), 99) : 0; // Cap at 99% before finalization
                $this->updateFeedProgress($feedId, $progress, "Processing full import: {$rowsProcessedOverall} of {$totalRows} products processed. ({$progress}%)", $processCode);

                $nextSqsData = $this->buildSqsMessageFromExisting($sqsData, 'readAndProcessFullBundleComponentFileChunk', [
                    'jsonl_file_path' => $jsonlFilePath,
                    'total_rows' => $totalRows,
                    'jsonl_next_cursor' => $newJsonlNextCursor, // Pass the new cursor
                    'updated_products_count' => $updatedProductsCount,
                    'rows_processed' => $rowsProcessedOverall
                ]);
                $this->sqsHelper->pushMessage($nextSqsData);
                return ['success' => true, 'message' => "Feed {$feedId}: Full file chunk processed. Queued for next chunk."];
            } else {
                // No next cursor, meaning end of file/all data processed
                $message = "Bundle component import completed. Total products processed: {$updatedProductsCount}.";
                $this->logInfo("Feed {$feedId}: " . $message);
                $this->finaliseProcess($feedId, $sqsData, true, $message, $processCode);
                return ['success' => true, 'message' => $message];
            }
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__, $this->currentUserId, $feedId, $sqsData);
            return $this->checkRetryLimitAndRequeue($sqsData, 'Exception reading/processing full bundle file chunk: ' . $e->getMessage(), null, self::REQUEST_STATUS_DELAY_SECONDS);
        }
    }

    /**
     * Requeues a message for the *next* chunk in the full import flow.
     * This method is distinct from requeueToProcessNextChunk as it handles a single file's state.
     * (This method is actually integrated directly into `readAndProcessFullBundleComponentFileChunk` now for simplicity).
     * I'll leave it as a placeholder/commented out for reference if you have external calls to it.
     * If not, it can be removed.
     */
    /*
    private function requeueFullImportFileChunk(string $feedId, array $currentSqsData, string $jsonlNextCursor, int $totalRows, int $updatedProductsCount, int $rowsProcessedOverall, array $options = []): void
    {
        $sqsMessageForNextChunk = $this->buildSqsMessageFromExisting($currentSqsData, 'readAndProcessFullBundleComponentFileChunk', array_merge([
            'jsonl_file_path' => $currentSqsData['data']['jsonl_file_path'], 
            'total_rows' => $totalRows,
            'jsonl_next_cursor' => $jsonlNextCursor, 
            'updated_products_count' => $updatedProductsCount,
            'rows_processed' => $rowsProcessedOverall,
        ], $options));

        unset($sqsMessageForNextChunk['data']['retry_count']); 

        $this->sqsHelper->pushMessage($sqsMessageForNextChunk);
        $this->logInfo("Feed {$feedId}: Requeued to readAndProcessFullBundleComponentFileChunk with new cursor.");
    }
    */

    /**
     * Extracts the numeric Shopify ID from a Shopify GID.
     * Example: gid://shopify/Product/7507612008525 -> 7507612008525
     *
     * @param string $gid The Shopify Global ID.
     * @return string|null The numeric ID, or null if parsing fails.
     */
    private function parseShopifyNumericIdFromGid(string $gid): ?string
    {
        $parts = explode('/', $gid);
        return end($parts) ?: null;
    }

    /**
     * Saves extracted bundle component data to the product_container in the database.
     * This method is updated to parse the nested structure from JSONLParser->getItems().
     *
     * @param array $sqsData SQS message data for context (e.g., feedId, user_id, shop_id).
     * @param array $productsDataChunk An array of product data parsed from the Shopify bulk operation file.
     * Expected format: ['Product' => ['product_id' => [...], ...]]
     * @return int The number of products for which components were successfully updated.
     */
    private function saveBundleComponentsToDb(array $sqsData, array $productsDataChunk): int
    {
        $bulkDbOperations = [];
        $savedProductCount = 0;
        $componentsBeingAdded = 0;
        $feedId = $sqsData['feed_id'] ?? 'N/A';

        $productsFromParser = $productsDataChunk['Product'] ?? [];

        foreach ($productsFromParser as $numericShopifyProductId => $productDataFromShopify) {
            $productGid = $productDataFromShopify['id'] ?? null;
            if (!$productGid || $this->parseShopifyNumericIdFromGid($productGid) != $numericShopifyProductId) {
                $this->logWarning("Feed {$feedId}: Skipping product data for key {$numericShopifyProductId} due to inconsistent or missing GID: {$productGid}.");
                continue;
            }

            // Only process products where 'hasVariantsThatRequiresComponents' is true
            if (!isset($productDataFromShopify['hasVariantsThatRequiresComponents']) || $productDataFromShopify['hasVariantsThatRequiresComponents'] !== true) {
                $this->logInfo("Feed {$feedId}: Skipping product with numeric ID {$numericShopifyProductId} (GID: {$productGid}), 'hasVariantsThatRequiresComponents' is not true.");
                continue;
            }

            $productVariants = $productDataFromShopify['ProductVariant'] ?? [];

            foreach ($productVariants as $variantNumericId => $variantData) {
                $formattedComponentsForDb = [];

                // If the product variant itself 'requiresComponents', then it's a bundle parent,
                // and its children are the components.
                if (isset($variantData['requiresComponents']) && $variantData['requiresComponents'] === true) {
                    $productVariantComponents = $variantData['ProductVariantComponent'] ?? [];

                    foreach ($productVariantComponents as $componentNumericId => $componentData) {
                        $componentProductVariantData = $componentData['productVariant'] ?? null;

                        if (!$componentProductVariantData || !isset($componentProductVariantData['id'])) {
                            $this->logWarning("Feed {$feedId}: Skipping a component for product {$numericShopifyProductId} (variant: {$variantNumericId}) due to missing componentProductVariant details or GID.");
                            continue;
                        }

                        $componentVariantGid = $componentProductVariantData['id'];
                        $numericComponentVariantId = $this->parseShopifyNumericIdFromGid($componentVariantGid);
                        $numericComponentParentId = $this->parseShopifyNumericIdFromGid($componentProductVariantData['product']['id'] ?? "N/A");

                        $variantTitle = $componentProductVariantData['title'] ?? null;
                        $productTitle = $componentProductVariantData['product']['title'] ?? null;

                        if ($variantTitle && $variantTitle !== 'Default Title') {
                            $componentTitle = $productTitle ? $productTitle . ' ' . $variantTitle : $variantTitle;
                        } else {
                            $componentTitle = $productTitle ?? 'N/A';
                        }

                        $dbComponentEntry = [
                            'id' => $componentData['id'],
                            'source_product_id' => $numericComponentVariantId,
                            'container_id' => $numericComponentParentId,
                            'title' => $componentTitle,
                            'bundle_qty' => (int)($componentData['quantity'] ?? 1), // Quantity of this component in the bundle
                            'sku' => $componentProductVariantData['sku'] ?? null,
                            'price' => $componentProductVariantData['price'] ?? null,
                            'image' => $componentProductVariantData['image']['url'] ?? ''
                        ];

                        // Variants of the component itself: this is tricky.
                        // The provided structure only gives one specific productVariant for the component.
                        // If 'Default Title' is the only variant, we might not need a 'variants' array.
                        // If it's a specific variant of a multi-variant component product, the 'variants' array
                        // might ideally list ALL variants of that component product for selection.
                        // For now, based on the provided data, we can only represent this specific variant.

                        // if (isset($componentProductVariantData['title']) && $componentProductVariantData['title'] !== 'Default Title') {
                        //     $dbComponentEntry['variants'] = [
                        //         [
                        //             'id' => $componentVariantGid,
                        //             'container_id' => $numericComponentVariantId,
                        //             'title' => $componentProductVariantData['title'],
                        //             'bundle_qty' => (int)($componentData['quantity'] ?? 1), // This is the quantity of THIS specific variant in the bundle
                        //             'sku' => $componentProductVariantData['sku'] ?? null, // Add SKU as per example
                        //         ]
                        //     ];
                        // } else {
                        //     // If it's a "Default Title" variant, the component is effectively just the product,
                        //     // or the variant isn't explicitly defined. We might still want to capture its SKU.
                        //     $dbComponentEntry['sku'] = $componentProductVariantData['sku'] ?? null;
                        // }

                        $formattedComponentsForDb[] = $dbComponentEntry;
                    }
                    if (!empty($formattedComponentsForDb)) {
                        $bulkDbOperations[] = [
                            'updateOne' => [
                                [
                                    'user_id' => $this->currentUserId,
                                    'shop_id' => $this->currentShopId,
                                    'container_id' => (string)$numericShopifyProductId,
                                    'source_product_id' => (string)$variantNumericId,
                                    'is_bundle' => true // Ensure we only update products marked as bundles in our DB
                                ],
                                [
                                    '$set' => [
                                        'components' => $formattedComponentsForDb,
                                        'updated_at' => date('c')
                                    ]
                                ],
                            ]
                        ];
                        $componentsBeingAdded++;
                    }
                }
                $savedProductCount++;
            }
        }

        if (!empty($bulkDbOperations)) {
            $this->logInfo("Feed {$feedId}: Preparing to bulkWrite DB updates for {$componentsBeingAdded} bundle variants. Processed {$savedProductCount} products/variants with bundle components");
            $dbChunks = array_chunk($bulkDbOperations, self::BULK_WRITE_CHUNK);
            foreach ($dbChunks as $dbChunk) {
                try {
                    $this->productContainer->bulkWrite($dbChunk);
                } catch (Exception $e) {
                    $this->logError("Feed {$feedId}: Error during DB bulkWrite for bundle components: " . $e->getMessage() . ". First op in chunk: " . json_encode($dbChunk[0] ?? []));
                }
            }
        }
        return $savedProductCount;
    }

    /**
     * Builds an SQS message array.
     *
     * @param string $feedId The ID of the current queued task.
     * @param string $nextMethod The method name to be called next.
     * @param array $additionalParams Additional parameters to include in the SQS message.
     * @return array The structured SQS message.
     */
    private function buildSqsMessage(string $feedId, string $nextMethod, array $additionalParams = []): array
    {
        $message = [
            'type' => 'full_class',
            'class_name' => self::class,
            'method' => $nextMethod,
            'user_id' => $this->currentUserId,
            'shop_id' => $this->currentShopId,
            'shop' => $this->getShopDataFromDiUser(),
            'feed_id' => $feedId,
            'queue_name' => self::QUEUE_NAME,
            'appCode' => $this->appCode,
            'appTag' => $this->appTag,
            'data' => [
                'retry_count' => 0
            ]
        ];

        foreach ($additionalParams as $key => $value) {
            if ($key === 'delay') {
                $message[$key] = $value;
            } else {
                $message['data'][$key] = $value;
            }
        }
        return $message;
    }

    /**
     * Builds an SQS message array from existing SQS data, preserving common state.
     *
     * @param array $existingSqsData The SQS message data from the current message being processed.
     * @param string $nextMethod The method name to be called next.
     * @param array $additionalParams Additional parameters to merge into the SQS message (overwriting existing ones if keys match).
     * @return array The structured SQS message.
     */
    private function buildSqsMessageFromExisting(array $existingSqsData, string $nextMethod, array $additionalParams = []): array
    {
        $baseMessage = $existingSqsData;
        $baseMessage['method'] = $nextMethod;
        $baseMessage['queue_name'] = self::QUEUE_NAME;

        if (!isset($baseMessage['data']) || !is_array($baseMessage['data'])) {
            $baseMessage['data'] = [];
        }
        $baseMessage['data']['retry_count'] = (isset($baseMessage['data']['retry_count']))
            ? (int)$baseMessage['data']['retry_count']
            : 0;

        foreach ($additionalParams as $key => $value) {
            if ($key === 'delay') {
                $baseMessage[$key] = $value;
            } elseif (array_key_exists($key, $baseMessage) && $key !== 'data') {
                $baseMessage[$key] = $value;
            } else {
                $baseMessage['data'][$key] = $value;
            }
        }

        if (isset($baseMessage['handle_added'])) {
            unset($baseMessage['handle_added']);
        }


        return array_filter($baseMessage, function ($value, $key) {
            return $key === 'data' || $value !== null;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Increments retry count and either re-queues the message or finalizes the process as failed.
     *
     * @param array $sqsData The original SQS message data.
     * @param string $failureMessage A message describing why the current step failed.
     * @param string|null $requeueMethod Optional. If provided, re-queues to this specific method (e.g., to retry a previous step).
     * @param int|null $delaySeconds Optional. Specific delay for the re-queued message.
     * @return array The result array indicating success/failure and message.
     */
    private function checkRetryLimitAndRequeue(array $sqsData, string $failureMessage, ?string $requeueMethod = null, ?int $delaySeconds = null): array
    {
        $feedId = $sqsData['feed_id'] ?? 'N/A';
        $currentMethod = $sqsData['method'] ?? 'unknown_method';
        $retryCount = (isset($sqsData['data']['retry_count'])) ? (int)$sqsData['data']['retry_count'] : 0;
        $retryCount++;

        if ($retryCount <= self::MAX_RETRY_ATTEMPTS) {
            $this->logWarning("Feed {$feedId}: {$currentMethod} failed. Retrying (attempt {$retryCount}/" . self::MAX_RETRY_ATTEMPTS . "). Reason: {$failureMessage}");

            $nextMethod = $requeueMethod ?? $currentMethod;
            $newDelay = $delaySeconds ?? (self::REQUEST_STATUS_DELAY_SECONDS * $retryCount);

            $requeueSqsData = $this->buildSqsMessageFromExisting($sqsData, $nextMethod, [
                'delay' => $newDelay,
                'retry_count' => $retryCount
            ]);
            $this->sqsHelper->pushMessage($requeueSqsData);
            return ['success' => true, 'message' => "Re-queued {$nextMethod} for retry. Attempt {$retryCount}. "];
        } else {
            $finalMessage = "Feed {$feedId}: {$currentMethod} failed after " . self::MAX_RETRY_ATTEMPTS . " retries. Final failure. Reason: {$failureMessage}";
            $this->logError($finalMessage);
            $processCode = ($sqsData['data']['process_type'] ?? '') == 'full_import' ? self::PROCESS_CODE_FULL : self::PROCESS_CODE;
            $this->finaliseProcess($feedId, $sqsData, false, $finalMessage, $processCode);
            return ['success' => false, 'message' => $finalMessage];
        }
    }


    private function initiliseCommon(array &$sqsData): void
    {
        $this->initilise();

        if (!isset($sqsData['user_id'])) {
            throw new Exception("User ID not found in SQS data for method '{$sqsData['method']}'. Cannot proceed.");
        }
        $this->currentUserId = (string) $sqsData['user_id'];
        $this->logFile = "shopify/bundle-component-import/{$this->currentUserId}/" . date('Y-m-d') . '.log';

        $currentShopData = $sqsData['shop'] ?? $this->getShopDataFromDiUser();
        if (!$currentShopData || !isset($currentShopData['_id'])) {
            throw new Exception("Shop data (or _id) missing or could not be loaded for user {$this->currentUserId} in method '{$sqsData['method']}'.");
        }
        $this->currentShopId = (string) $currentShopData['_id'];
        if (empty($sqsData['shop_id'])) {
            $sqsData['shop_id'] = $this->currentShopId;
        }
        if (empty($sqsData['shop'])) {
            $sqsData['shop'] = $currentShopData;
        }


        $this->appCode = $sqsData['appCode'] ?? ($currentShopData['apps'][0]['code'] ?? 'default_app_code');
        $this->appTag = $sqsData['appTag'] ?? ($this->di->has('appCode') ? $this->di->getAppCode()->getAppTag() : 'default_app_tag');
        if ($this->di->has('appCode')) {
            $this->di->getAppCode()->setAppTag($this->appTag);
        }
        if (empty($sqsData['appCode'])) {
            $sqsData['appCode'] = $this->appCode;
        }
        if (empty($sqsData['appTag'])) {
            $sqsData['appTag'] = $this->appTag;
        }


        if (empty($sqsData['feed_id']) && $sqsData['method'] !== 'initiateBundleComponentImport' && $sqsData['method'] !== 'initiateFullBundleComponentImport') {
            throw new Exception("feed_id missing in SQS data for method: {$sqsData['method']}");
        }
    }

    /**
     * Retrieves active Shopify shop data for the current user from DI context.
     *
     * @return array|null The active Shopify shop data, or null if not found.
     */
    private function getShopDataFromDiUser(): ?array
    {
        $userInDi = $this->di->getUser();
        if ($this->currentUserId && $userInDi->id !== $this->currentUserId) {
            $this->logError("Mismatch: DI user ({$userInDi->id}) does not match SQS user ({$this->currentUserId}). This indicates a DI context issue.");
            return null;
        }

        $shops = $userInDi->shops ?? [];
        if (!empty($shops)) {
            foreach ($shops as $shop) {
                if (
                    isset($shop['marketplace']) && $shop['marketplace'] === self::MARKETPLACE &&
                    isset($shop['apps'][0]['app_status']) && $shop['apps'][0]['app_status'] === 'active' &&
                    !isset($shop['store_closed_at'])
                ) {
                    return $shop;
                }
            }
        }
        return null;
    }

    private function setAppTag(array $data, array $shopData): void
    {
        $this->appCode = $shopData['apps'][0]['code'] ?? ($this->appCode ?? 'default');
        $this->appTag = $data['app_tag'] ?? ($this->appTag ?? ($this->di->has('appCode') ? $this->di->getAppCode()->getAppTag() : 'shopify'));
        if ($this->di->has('appCode')) {
            $this->di->getAppCode()->setAppTag($this->appTag);
        }
    }

    private function setQueuedTask(string $userId, array $shop, string $messageText, string $processCode): string|array
    {
        try {
            $queueData = [
                'user_id' => $userId,
                'message' => $messageText,
                'process_code' => 'saleschannel_product_import',
                'marketplace' => self::MARKETPLACE,
                'status' => 'pending',
                'progress' => 0,
                'additional_data' => [
                    'process_label' => ucwords(str_replace('_', ' ', $processCode))
                ]
            ];
            $queuedTask = new QueuedTasks();
            $queuedTaskId = $queuedTask->setQueuedTask((string)$shop['_id'], $queueData);

            if (!$queuedTaskId || !is_string($queuedTaskId)) {
                if ($this->checkProgressStuck($userId, $shop, $processCode)) {
                    $this->logInfo("A stuck task for " . $processCode . " was handled for user {$userId}. Retrying to set new task.");
                    $queuedTask = new QueuedTasks();
                    $queuedTaskId = $queuedTask->setQueuedTask((string)$shop['_id'], $queueData);
                    if ($queuedTaskId && is_string($queuedTaskId)) {
                        $this->logInfo("Stuck task cleared. New queued task created for " . $processCode . ": {$queuedTaskId}");
                    } else {
                        return ['success' => false, 'message' => "Failed to create new task for " . $processCode . " after handling stuck one."];
                    }
                } else {
                    return ['success' => false, 'message' => ($queuedTaskId['message'] ?? ($processCode . " process is already in progress or failed to create task."))];
                }
            }
            return (string)$queuedTaskId;
        } catch (Exception $e) {
            $this->logError("Exception from setQueuedTask for " . $processCode . " (User: {$userId}): " . $e->getMessage());
            return ['success' => false, 'message' => "Error setting up task for " . $processCode . ": " . $e->getMessage()];
        }
    }

    /**
     * Checks if a previous process is stuck.
     *
     * @param string $userId
     * @param array $shopData
     * @param string $processCode
     * @return bool
     */
    private function checkProgressStuck(string $userId, array $shopData, string $processCode): bool
    {
        //TODO

        // $this->logWarning("checkProgressStuck called (placeholder implementation).");
        return false;
    }

    private function finaliseProcess(string $feedId, array $sqsData, bool $status, string $message, string $processCode): void
    {
        $activityLogData = [
            'user_id' => $this->currentUserId,
            'shop_id' => $this->currentShopId,
            'appTag' => $sqsData['appTag'] ?? $this->appTag,
        ];
        $this->updateActivityLog($activityLogData, $status, $message, $processCode);

        $updateFields = [
            'updated_at' => date('c'),
            'message' => $message,
            'status' => $status ? 'completed' : 'failed'
        ];
        if ($status) {
            $updateFields['progress'] = 100;
        }

        $this->di->getObjectManager()
            ->get('\App\Connector\Models\QueuedTasks')
            ->updateFeedProgress($feedId, 100, $message);

        if (($sqsData['data']['process_type'] ?? '') == 'targeted') {
            $cacheKey = $sqsData['data']['container_ids_cache_key'] ?? null;
            if (!$cacheKey && $this->currentUserId && $this->currentShopId) {
                $cacheKey = $this->generateCacheKeyForContainerIds($feedId);
            }
            if ($cacheKey && $this->di->getCache()->has($cacheKey)) {
                $this->di->getCache()->delete($cacheKey);
                $this->logInfo("Feed {$feedId}: Deleted cache key {$cacheKey} (targeted).");
            }
        } elseif (($sqsData['data']['process_type'] ?? '') == 'full_import') {
            $jsonlFilePath = $sqsData['data']['jsonl_file_path'] ?? null;
            if ($jsonlFilePath && file_exists($jsonlFilePath)) {
                // unlink($jsonlFilePath);
                $this->logInfo("Feed {$feedId}: Cleaned up full import file {$jsonlFilePath}.");
            }
        }

        $this->logInfo("Feed {$feedId}: Finalized process. Status: " . ($status ? 'Success' : 'Failure') . ". Message: {$message}. Process Type: {$processCode}");
    }

    private function updateActivityLog(array $data, bool $status, string $message, string $processCode): void
    {
        try {
            $userId = $data['user_id'] ?? $this->currentUserId;
            $shopId = $data['shop_id'] ?? $this->currentShopId;
            $appTag = $data['appTag'] ?? $this->appTag;

            if (!$userId || !$shopId) {
                $this->logWarning("Cannot update activity log for " . $processCode . ": UserID ({$userId}) or ShopID ({$shopId}) missing. Message: {$message}");
                return;
            }

            $notificationData = [
                'severity' => $status ? 'success' : 'critical',
                'message' => $message ?? ucfirst(str_replace('_', ' ', $processCode)) . ' Process Update',
                "user_id" => $userId,
                "marketplace" => self::MARKETPLACE,
                "appTag" => $appTag,
                'process_code' => 'saleschannel_product_import',
                'additional_data' => [
                    'process_label' => ucwords(str_replace('_', ' ', $processCode))
                ]
            ];
            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($shopId, $notificationData);
        } catch (Exception $e) {
            $this->logError("Exception in updateActivityLog for " . $processCode . ": " . $e->getMessage());
        }
    }

    public function shopifyBundleProductSkipped($event, $component, $data): void
    {
        // Batch mode: handle multiple skipped bundle products in one bulkWrite
        if (!empty($data['batch']) && !empty($data['products'])) {
            $this->handleBatchBundleProductsSkipped($data['products']);
            return;
        }

        // Single-product mode (existing behavior for non-batch callers)
        $userId = (string)($data['user_id'] ?? ($this->di->getUser()->id ?? ''));
        $this->logFile = "shopify/bundle-component-import-skipped/{$userId}/" . date('Y-m-d') . '.log';
        try {
            if (!is_array($data)) {
                $this->logWarning('shopifyBundleProductSkipped called with invalid data payload.');
                return;
            }
            $userId = (string)($data['user_id'] ?? ($this->di->getUser()->id ?? ''));
            if ($userId === '') {
                $this->logWarning('shopifyBundleProductSkipped: user_id missing in payload.');
                return;
            }

            $shopData = $this->getShopDataFromDiUser();
            $shopId = (string)$shopData['_id'] ?? '';
            if ($shopId === '') {
                $this->logWarning("shopifyBundleProductSkipped: shop_id missing for user {$userId}.");
                return;
            }

            $containerId = (string)($data['container_id'] ?? $data['id'] ?? '');
            if ($containerId === '') {
                $this->logWarning("shopifyBundleProductSkipped: container_id missing for user {$userId}.");
                return;
            }
            $sourceProductId = (string)($data['source_product_id'] ?? $data['source_variant_id'] ?? $data['id'] ?? $containerId);
            $title = $data['title'] ?? ($data['name'] ?? '');
            $mainImage = $data['main_image'] ?? ($data['image'] ?? '');
            $now = new \MongoDB\BSON\UTCDateTime((int)(microtime(true) * 1000));
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('restricted_products');
            $collection->updateOne(
                [
                    'user_id' => $userId,
                    'shop_id' => $shopId,
                    'container_id' => $containerId
                ],
                [
                    '$setOnInsert' => [
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                        'marketplace' => self::MARKETPLACE,
                        'container_id' => $containerId,
                        'source_product_id' => $sourceProductId,
                        'title' => $title,
                        'main_image' => $mainImage,
                        'error' => $data['error'] ?? $data['message'] ?? 'Bundle Product',
                        'created_at' => $now
                    ],
                    '$set' => [
                        'updated_at' => $now
                    ]
                ],
                ['upsert' => true]
            );
            $this->logInfo("shopifyBundleProductSkipped stored: {$containerId} for user {$userId}.");
        } catch (Exception $e) {
            $this->logError("Exception in shopifyBundleProductSkipped: " . $e->getMessage());
        }
    }

    /**
     * Handles batch of skipped bundle products in a single bulkWrite instead of per-product writes.
     * Called from BatchImport pre-filter optimization.
     */
    private function handleBatchBundleProductsSkipped(array $products): void
    {
        $userId = (string)($this->di->getUser()->id ?? '');
        $this->logFile = "shopify/bundle-component-import-skipped/{$userId}/" . date('Y-m-d') . '.log';
        try {
            if ($userId === '') {
                $this->logWarning('handleBatchBundleProductsSkipped: user_id missing.');
                return;
            }

            $shopData = $this->getShopDataFromDiUser();
            $shopId = (string)($shopData['_id'] ?? '');
            if ($shopId === '') {
                $this->logWarning("handleBatchBundleProductsSkipped: shop_id missing for user {$userId}.");
                return;
            }

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('restricted_products');
            $now = new \MongoDB\BSON\UTCDateTime((int)(microtime(true) * 1000));

            $bulkOps = [];
            foreach ($products as $product) {
                $containerId = (string)($product['container_id'] ?? $product['id'] ?? '');
                if ($containerId === '') {
                    continue;
                }

                $bulkOps[] = [
                    'updateOne' => [
                        ['user_id' => $userId, 'shop_id' => $shopId, 'container_id' => $containerId],
                        [
                            '$setOnInsert' => [
                                'user_id' => $userId,
                                'shop_id' => $shopId,
                                'marketplace' => self::MARKETPLACE,
                                'container_id' => $containerId,
                                'source_product_id' => (string)($product['source_product_id'] ?? $product['source_variant_id'] ?? $product['id'] ?? $containerId),
                                'title' => $product['title'] ?? ($product['name'] ?? ''),
                                'main_image' => $product['main_image'] ?? ($product['image'] ?? ''),
                                'error' => 'Bundle Product',
                                'created_at' => $now
                            ],
                            '$set' => ['updated_at' => $now]
                        ],
                        ['upsert' => true]
                    ]
                ];
            }

            if (!empty($bulkOps)) {
                $chunks = array_chunk($bulkOps, self::BULK_WRITE_CHUNK);
                foreach ($chunks as $chunk) {
                    $collection->bulkWrite($chunk);
                }
                $this->logInfo("Batch stored " . count($bulkOps) . " skipped bundle products for user {$userId}.");
            }
        } catch (Exception $e) {
            $this->logError("Exception in handleBatchBundleProductsSkipped: " . $e->getMessage());
        }
    }
    public function hasRestrictedProducts(array $params): array
    {
        $userId = $params['user_id'] ?? null;
        $shopId = $params['shop_id'] ?? null;
        if (!$userId || !$shopId) {
            return ['success' => false, 'message' => 'user_id and shop_id required'];
        }
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('restricted_products');
        $doc = $collection->findOne(
            [
                'user_id' => (string)$userId,
                'shop_id' => (string)$shopId,
                'marketplace' => self::MARKETPLACE
            ],
            [
                'projection' => ['_id' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ]
        );
        $restricted = !empty($doc);
        if (!$restricted) {
            return ['success' => false, 'message' => 'No restricted products found'];
        }
        return ['success' => true, 'message' => 'Restricted products found'];
    }
    public function getRestrictedProducts(array $params): array
    {
        if (!isset($params['user_id'], $params['shop_id'])) {
            return ['success' => false, 'message' => 'user_id and shop_id required'];
        }
        $query = [
            'user_id' => (string)$params['user_id'],
            'shop_id' => (string)$params['shop_id'],
            'marketplace' => self::MARKETPLACE
        ];
        if (isset($params['search']) && !empty(trim((string)$params['search']))) {
            $search = trim(addslashes((string)$params['search']));
            $query['$or'] = [
                ['title' => ['$regex' => "(?i)" . $search]],
                ['container_id' => ['$regex' => $search]],
            ];
        }
        if (isset($params['error']) && !empty(trim((string)$params['error']))) {
            $errors = array_map('trim', array_map('addslashes', explode(',', trim((string)$params['error']))));
            $query['error'] = ['$in' => $errors];
        }
        if (isset($params['errors']) && is_array($params['errors']) && !empty($params['errors'])) {
            $errors = array_filter(
                array_map(
                    function ($value) {
                        return addslashes(trim((string)$value));
                    },
                    $params['errors']
                ),
                function ($value) {
                    return $value !== '';
                }
            );
            if (!empty($errors)) {
                $query['error'] = ['$in' => array_values($errors)];
            }
        }
        $limit = $params['count'] ?? 20;
        $page = $params['activePage'] ?? 1;
        $skip = ($page - 1) * $limit;
        $options = [
            'limit' => (int)$limit,
            'skip' => (int)$skip,
            'sort' => ['updated_at' => -1],
            'projection' => [
                '_id' => 0,
                'container_id' => 1,
                'source_product_id' => 1,
                'title' => 1,
                'main_image' => 1,
                'error' => 1,
                'created_at' => 1,
                'updated_at' => 1
            ],
            'typeMap' => ['root' => 'array', 'document' => 'array']
        ];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('restricted_products');
        $total = $collection->countDocuments($query);
        $data = ['count' => $total];
        if ($total == 0 || !empty($params['count_only'])) {
            return ['success' => true, 'data' => $data];
        }
        $data['rows'] = $collection->find($query, $options)->toArray();
        return ['success' => true, 'data' => $data];
    }

    /**
     * Handles exceptions, logs them, and updates activity log if possible.
     *
     * @param Exception $e The caught exception.
     * @param string $methodName The name of the method where the exception occurred.
     * @param string|null $userIdContext User ID in context, if available.
     * @param string|null $feedId Feed ID, if available.
     * @param array $sqsData The SQS data that triggered the method.
     * @return void
     */
    private function handleException(Exception $e, string $methodName, ?string $userIdContext, ?string $feedId, array $sqsData): void
    {
        $uid = $userIdContext ?? ($sqsData['user_id'] ?? 'unknown_user');
        if (empty($this->logFile) && $uid !== 'unknown_user') {
            $this->logFile = "shopify/bundle-component-import/{$uid}/" . date('Y-m-d') . '.log';
        }

        $logMessage = "Exception in {$methodName} (Feed: " . ($feedId ?? 'N/A') . ", User: {$uid}): " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString();
        $this->logError($logMessage);
        $this->di->getLog()->logContent($logMessage, 'error', 'exception.log');

        if ($feedId) {
            $activityData = [
                'user_id' => $uid,
                'shop_id' => $sqsData['shop_id'] ?? ($this->currentShopId ?? null),
                'appTag'  => $sqsData['appTag'] ?? ($this->appTag ?? null),
            ];
            $processCode = ($sqsData['data']['process_type'] ?? '') == 'full_import' ? self::PROCESS_CODE_FULL : self::PROCESS_CODE;
            if ($activityData['user_id'] !== 'unknown_user' && $activityData['shop_id']) {
                $this->updateActivityLog($activityData, false, "Error during bundle component import (step: {$methodName}). Check logs for feed {$feedId}.", $processCode);
            } else {
                $this->logWarning("Cannot update activity log for error in {$methodName} (Feed: {$feedId}, User: {$uid}) due to missing shop ID. SQS keys: " . implode(',', array_keys($sqsData)));
            }
        }
    }

    /**
     * Formats a remote API error message for logging.
     *
     * @param string $baseMessage A base message describing the failure.
     * @param array $remoteResponse The raw response from the remote API.
     * @return string The formatted error message.
     */
    private function formatRemoteErrorMessage(string $baseMessage, array $remoteResponse): string
    {
        $details = '';
        if (isset($remoteResponse['errors'])) {
            $details = json_encode($remoteResponse['errors']);
        } elseif (isset($remoteResponse['message'])) {
            $details = $remoteResponse['message'];
        } elseif (isset($remoteResponse['data']['userErrors'])) {
            $details = json_encode($remoteResponse['data']['userErrors']);
        }
        return $baseMessage . (!empty($details) ? " Details: {$details}" : " No specific details available.");
    }

    /**
     * Updates the progress and message of a queued task.
     *
     * @param string $feedId The ID of the queued task.
     * @param int $progress The progress percentage (0-100).
     * @param string $message The message describing the current progress.
     * @param string $processCode The specific process code to use for the update (optional, defaults to PROCESS_CODE).
     * @return void
     */
    private function updateFeedProgress(string $feedId, int $progress, string $message, string $processCode = self::PROCESS_CODE): void
    {
        try {
            $queuedTasksModel = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
            $queuedTasksModel->updateFeedProgress($feedId, $progress, $message, self::PROCESS_CODE_IMPORT);
        } catch (Exception $e) {
            $this->logWarning("Feed {$feedId} ({$processCode}): Failed to update feed progress to {$progress}% ('{$message}'): " . $e->getMessage());
        }
    }

    private function logInfo(string $message): void
    {
        if ($this->logFile) {
            $this->di->getLog()->logContent($message, 'info', $this->logFile);
        } else {
            $this->di->getLog()->logContent($message, 'info', 'shopify/bundle-component-import/general.log');
        }
    }
    private function logWarning(string $message): void
    {
        if ($this->logFile) {
            $this->di->getLog()->logContent($message, 'warning', $this->logFile);
        } else {
            $this->di->getLog()->logContent($message, 'warning', 'shopify/bundle-component-import/general.log');
        }
    }
    private function logError(string $message, bool $generalLogOnly = false): void
    {
        if ($this->logFile && !$generalLogOnly) {
            $this->di->getLog()->logContent($message, 'error', $this->logFile);
        } else {
            $this->di->getLog()->logContent($message, 'error', 'shopify/bundle-component-import/general_error.log');
        }
    }
}
