<?php

namespace App\Amazon\Components\Template\BulkAttributesEdit\Cleanup;

/**
 * Handles cleanup of bulk-edited attributes when products are removed from templates
 * or when template product types change.
 * 
 * Scenarios handled:
 * - Override enabled + manual product assignment (product may have been in another template)
 * - Product removed from template (manual filter)
 * - Override enabled + advanced filter
 * - Product type changed in template
 */
class Cleanup
{
    const CLEANUP_CHUNK_SIZE = 5000;
    const CLEANUP_PROCESS_CODE = 'template_bulk_attr_cleanup';
    const CLEANUP_QUEUE_NAME = 'template_bulk_attr_cleanup';

    /** Config group_code and key used to track if user has ever run bulk-edit import (skip cleanup if not). */
    const CONFIG_GROUP_CODE_BULK_EDIT = 'template_bulk_attributes_edit';
    const CONFIG_KEY_HAS_BULK_ATTRIBUTES_EDIT_IMPORTED = 'has_imported';

    /**
     * Entry point for initiating the bulk attribute cleanup process.
     * Validates params, checks if user has ever performed bulk edit import; if not, returns success without processing.
     * Otherwise pushes job to SQS queue for async processing.
     *
     * @param array $params
     * @return array
     */
    public function cleanup(array $params): array
    {
        $this->logInfo('Initiating bulk attribute cleanup with params: ' . json_encode($params));

        $validationResult = $this->validateParams($params);
        if (!$validationResult['success']) {
            $this->logInfo('validateParams false = ' . json_encode($validationResult));
            return $validationResult;
        }

        if (!$this->hasBulkEditImported(
            $params['user_id'],
            $params['source_shop_id'],
            $params['target_shop_id']
        )) {
            return [
                'success' => true,
                'message' => 'No bulk edit to cleanup.',
            ];
        }

        return $this->pushToCleanupQueue($params);
    }

    /**
     * Check if this user/source/target has ever had bulk-edit import run (config key set and true).
     *
     * @param string $userId
     * @param string $sourceShopId
     * @param string $targetShopId
     * @return bool
     */
    private function hasBulkEditImported(string $userId, string $sourceShopId, string $targetShopId): bool
    {
        try {
            $configModel = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
            $configModel->reset();
            $configModel->setUserId($userId);
            $configModel->setSourceShopId($sourceShopId);
            $configModel->setTargetShopId($targetShopId);
            $configModel->setGroupCode(self::CONFIG_GROUP_CODE_BULK_EDIT);
            $configModel->getDefaultValueFromConfig(false);

            $rows = $configModel->getConfig(self::CONFIG_KEY_HAS_BULK_ATTRIBUTES_EDIT_IMPORTED);

            if (empty($rows) || !is_array($rows)) {
                return false;
            }

            foreach ($rows as $row) {
                if (isset($row['value']) && $row['value'] === true) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            $this->logError('Failed to check bulk-edit config: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate required parameters for cleanup.
     *
     * @param array $params
     * @return array
     */
    private function validateParams(array $params): array
    {
        $requiredParams = [
            'user_id',
            'source_shop_id',
            'target_shop_id',
            'template_id',
            'template_name',
            'reason',
            'filter_type',
            'product_count'
        ];

        foreach ($requiredParams as $param) {
            if (empty($params[$param])) {
                return [
                    'success' => false,
                    'message' => "Required parameter '{$param}' is missing."
                ];
            }
        }

        $filterType = $params['filter_type'];
        if ($filterType === 'manual') {
            if (!isset($params['container_ids']) || !is_array($params['container_ids']) || empty($params['container_ids'])) {
                return [
                    'success' => false,
                    'message' => "Parameter 'container_ids' is required for manual filter type."
                ];
            }
        } elseif ($filterType === 'advanced') {
            if (!isset($params['query']) || empty($params['query'])) {
                return [
                    'success' => false,
                    'message' => "Parameter 'query' is required for advanced filter type."
                ];
            }
        } else {
            return [
                'success' => false,
                'message' => "Invalid filter_type. Must be 'manual' or 'advanced'."
            ];
        }

        return ['success' => true];
    }

    /**
     * Push cleanup job to SQS queue.
     *
     * @param array $params
     * @return array
     */
    private function pushToCleanupQueue(array $params): array
    {
        $preparedQueueData = [
            'type' => 'full_class',
            'class_name' => self::class,
            'method' => 'processCleanup',
            'user_id' => $params['user_id'],
            'source_shop_id' => $params['source_shop_id'],
            'target_shop_id' => $params['target_shop_id'],
            'queue_name' => self::CLEANUP_QUEUE_NAME,
            'data' => [
                'user_id' => $params['user_id'],
                'source_shop_id' => $params['source_shop_id'],
                'target_shop_id' => $params['target_shop_id'],
                'template_id' => $params['template_id'],
                'template_name' => $params['template_name'],
                'reason' => $params['reason'],
                'filter_type' => $params['filter_type'],
                'product_count' => $params['product_count'],
                'container_ids' => $params['container_ids'] ?? [],
                'query' => $params['query'] ?? '',
                'global_and' => $params['global_and'] ?? '',
                'last_processed_id' => null,
                'total_modified_count' => 0,
            ],
        ];

        return $this->pushMessageToQueue($preparedQueueData);
    }

    /**
     * Push message to SQS queue.
     *
     * @param array $message
     * @return array
     */
    public function pushMessageToQueue(array $message): array
    {
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        try {
            $response = $sqsHelper->pushMessage($message);
        } catch (\Exception $e) {
            $this->logError('Failed to push message to queue: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Bulk attribute cleanup initiated. You will be notified upon completion.',
            'data' => $response
        ];
    }

    /**
     * Process cleanup job (called by SQS worker).
     *
     * @param array $queueData
     * @return bool
     */
    public function processCleanup(array $queueData): bool
    {
        $params = $queueData['data'] ?? [];

        $userId = $params['user_id'] ?? null;
        $sourceShopId = $params['source_shop_id'] ?? null;
        $targetShopId = $params['target_shop_id'] ?? null;
        $templateId = $params['template_id'] ?? null;
        $templateName = $params['template_name'] ?? '';
        $reason = $params['reason'] ?? '';
        $filterType = $params['filter_type'] ?? '';
        $productCount = $params['product_count'] ?? 0;
        $lastProcessedId = $params['last_processed_id'] ?? null;
        $totalModifiedCount = $params['total_modified_count'] ?? 0;

        if (empty($userId) || empty($targetShopId) || empty($templateId) || empty($filterType) || empty($templateId) || empty($templateName)) {
            $this->logError('Missing required parameters: user_id or target_shop_id or template_id or filter_type or template_name');
            return true;
        }

        try {
            if ($filterType === 'manual') {
                $result = $this->processManualCleanup($params);
                $totalModifiedCount = $result['modified_count'];
            } else {
                $result = $this->processAdvancedCleanup($params);
                $totalModifiedCount += $result['modified_count'];

                // Check if we need to process more chunks
                if ($result['has_more'] && !empty($result['last_processed_id'])) {
                    $queueData['data']['last_processed_id'] = $result['last_processed_id'];
                    $queueData['data']['total_modified_count'] = $totalModifiedCount;
                    $this->pushMessageToQueue($queueData);
                    return true; // Continue processing in next queue message
                }
            }

            $this->sendCompletionNotification([
                'user_id' => $userId,
                'shop_id' => $targetShopId,
                'template_name' => $templateName,
                'modified_count' => $totalModifiedCount,
                'reason' => $reason,
            ]);

            $this->logInfo("Cleanup completed for template '{$templateName}' (ID: {$templateId}). " .
                "Reason: {$reason}. Modified count: {$totalModifiedCount}");

        } catch (\Exception $e) {
            $this->logError('Cleanup failed: ' . $e->getMessage());
            $this->sendErrorNotification([
                'user_id' => $userId,
                'shop_id' => $targetShopId,
                'template_name' => $templateName,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Process cleanup for manual filter type.
     * Container IDs are provided directly in the request.
     *
     * @param array $params
     * @return array
     */
    private function processManualCleanup(array $params): array
    {
        $containerIds = $params['container_ids'] ?? [];

        if (empty($containerIds)) {
            return ['success' => true, 'modified_count' => 0];
        }

        $modifiedCount = $this->unsetBulkAttributes(
            $params['user_id'],
            $params['target_shop_id'],
            $containerIds
        );

        return [
            'success' => true,
            'modified_count' => $modifiedCount,
        ];
    }

    /**
     * Process cleanup for advanced filter type.
     * Queries refine_product to get container_ids,
     * then unsets bulk_edit_attributes_mapping in product_container.
     *
     * @param array $params
     * @return array
     */
    private function processAdvancedCleanup(array $params): array
    {
        $userId = $params['user_id'];
        $sourceShopId = $params['source_shop_id'];
        $targetShopId = $params['target_shop_id'];
        $query = $params['query'];
        $globalAnd = $params['global_and'] ?? '';
        $lastProcessedId = $params['last_processed_id'] ?? null;

        $containerIds = $this->getContainerIdsFromRefineProduct(
            $userId,
            $sourceShopId,
            $targetShopId,
            $query,
            $globalAnd,
            $lastProcessedId
        );

        if (empty($containerIds['ids'])) {
            return [
                'success' => true,
                'modified_count' => 0,
                'has_more' => false,
                'last_processed_id' => null,
            ];
        }

        $modifiedCount = $this->unsetBulkAttributes($userId, $targetShopId, $containerIds['ids']);

        return [
            'success' => true,
            'modified_count' => $modifiedCount,
            'has_more' => $containerIds['has_more'],
            'last_processed_id' => $containerIds['last_id'],
        ];
    }

    /**
     * Get container IDs from refine_product collection using the converted query.
     * @param string $userId
     * @param string $sourceShopId
     * @param string $targetShopId
     * @param string $query
     * @param string $globalAnd
     * @param string|null $lastProcessedId  Cursor for pagination (_id of last processed doc)
     * @return array { ids: string[], has_more: bool, last_id: string|null }
     */
    private function getContainerIdsFromRefineProduct(
        string $userId,
        string $sourceShopId,
        string $targetShopId,
        string $query,
        string $globalAnd = '',
        ?string $lastProcessedId = null
    ): array {
        $queryConverter = $this->di->getObjectManager()->get('\App\Connector\Components\Profile\GetQueryConverted');

        $profileData = [
            'user_id' => $userId,
            'query' => $query,
            'global_and' => $globalAnd,
        ];

        $shopIdsForQuery = [
            '$or' => [
                ['$and' => [['source_shop_id' => $sourceShopId, 'target_shop_id' => $targetShopId]]]
            ]
        ];

        $mongoFilter = $queryConverter->extractRuleForRefineData($profileData, 'find', [], $shopIdsForQuery);

        if (!$mongoFilter) {
            return ['ids' => [], 'has_more' => false, 'last_id' => null];
        }

        // Cursor-based pagination: continue from where the last chunk left off
        if ($lastProcessedId) {
            $mongoFilter['$and'][] = ['_id' => ['$gt' => new \MongoDB\BSON\ObjectId($lastProcessedId)]];
        }

        $refineProductCollection = $this->di->getObjectManager()
            ->get('\App\Core\Models\BaseMongo')
            ->getCollectionForTable('refine_product');

        // Fetch one extra to detect whether more pages exist
        $fetchLimit = self::CLEANUP_CHUNK_SIZE + 1;

        $results = $refineProductCollection->find($mongoFilter, [
            'projection' => ['container_id' => 1, '_id' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'sort' => ['_id' => 1],
            'limit' => $fetchLimit,
        ])->toArray();

        $hasMore = count($results) > self::CLEANUP_CHUNK_SIZE;
        if ($hasMore) {
            array_pop($results); // Remove the extra sentinel doc
        }

        $containerIds = array_values(array_unique(array_column($results, 'container_id')));
        $lastId = !empty($results) ? (string)end($results)['_id'] : null;

        return [
            'ids' => $containerIds,
            'has_more' => $hasMore,
            'last_id' => $lastId,
        ];
    }

    /**
     * Unset bulk_edit_attributes_mapping from product_container for given container IDs.
     *
     * @param string $userId
     * @param string $targetShopId
     * @param array $containerIds
     * @return int Modified count
     */
    private function unsetBulkAttributes(string $userId, string $targetShopId, array $containerIds): int
    {
        if (empty($containerIds)) {
            return 0;
        }

        // Always use getCollectionForTable - consistent with Import/Delete and ProfileHelper
        $productContainer = $this->di->getObjectManager()
            ->get('\App\Core\Models\BaseMongo')
            ->getCollectionForTable('product_container');

        $filter = [
            'user_id' => $userId,
            'shop_id' => $targetShopId,
            'target_marketplace' => 'amazon',
            'container_id' => ['$in' => $containerIds],
        ];

        $update = [
            '$unset' => ['bulk_edit_attributes_mapping' => 1]
        ];

        try {
            $result = $productContainer->updateMany($filter, $update);
            return $result->getModifiedCount();
        } catch (\Exception $e) {
            $this->logError('Failed to unset bulk attributes: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Send completion notification to user.
     *
     * @param array $params
     * @return void
     */
    private function sendCompletionNotification(array $params): void
    {
        $templateName = $params['template_name'] ?? 'Unknown';
        $modifiedCount = $params['modified_count'] ?? 0;

        $message = "Bulk edited attributes removed for {$modifiedCount} product(s) in template '{$templateName}'.";

        $notificationData = [
            'user_id' => $params['user_id'],
            'message' => $message,
            'severity' => 'success',
            'created_at' => date('c'),
            'marketplace' => 'amazon',
            'process_code' => self::CLEANUP_PROCESS_CODE,
            'additional_data' => [
                'process_label' => 'Bulk Product Attributes Cleanup',
            ]
        ];

        $notification = $this->di->getObjectManager()->get('App\Connector\Models\Notifications');
        $notification->addNotification($params['shop_id'], $notificationData);
    }

    /**
     * Send error notification to user.
     *
     * @param array $params
     * @return void
     */
    private function sendErrorNotification(array $params): void
    {
        $templateName = $params['template_name'] ?? 'Unknown';
        $error = $params['error'] ?? 'Unknown error';

        $message = "Failed to cleanup bulk attributes for template '{$templateName}'. Error: {$error}";

        $notificationData = [
            'user_id' => $params['user_id'],
            'message' => $message,
            'severity' => 'error',
            'created_at' => date('c'),
            'marketplace' => 'amazon',
            'process_code' => self::CLEANUP_PROCESS_CODE,
        ];

        $notification = $this->di->getObjectManager()->get('App\Connector\Models\Notifications');
        $notification->addNotification($params['shop_id'], $notificationData);
    }

    /**
     * Get MongoDB collection.
     *
     * @param string $collection
     * @return object
     */
    private function getBaseMongoCollection(string $collection): object
    {
        return $this->di->getObjectManager()
            ->create('\App\Core\Models\BaseMongo')
            ->getCollection($collection);
    }

    /**
     * Log error message.
     *
     * @param string $message
     * @return void
     */
    private function logError(string $message): void
    {
        $logPath = 'amazon' . DS . 'bulk_attr_edit' . DS . $this->di->getUser()->id . DS . 'cleanup.log';
        $this->di->getLog()->logContent($message, 'error', $logPath);
    }

    /**
     * Log info message.
     *
     * @param string $message
     * @return void
     */
    private function logInfo(string $message): void
    {
        $logPath = 'amazon' . DS . 'bulk_attr_edit' . DS . $this->di->getUser()->id . DS . 'cleanup.log';
        $this->di->getLog()->logContent($message, 'info', $logPath);
    }
}
