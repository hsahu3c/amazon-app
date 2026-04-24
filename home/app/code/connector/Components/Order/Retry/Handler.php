<?php

namespace App\Connector\Components\Order\Retry;

use App\Core\Components\Base as BaseComponent;
use App\Connector\Components\Order\Retry\Scheduler;
use App\Connector\Components\Order\Order;
use App\Core\Models\BaseMongo;
use MongoDB\BSON\UTCDateTime as MongoDate;

class Handler extends BaseComponent
{
    const ORDER_CONTAINER_COLLECTION   = 'order_container';
    const RETRY_CONTAINER_COLLECTION   = 'order_retry_container';
    const FIRST_FAIL_RETRY_DELAY       = 43200;  // 12h
    const NEXT_FAIL_RETRY_DELAY        = 86400;  // 24h
    const LAST_DAY_FAIL_RETRY_DELAY    = 43200;  // 12h

    private Scheduler $scheduler;
    private string $logFile;

    private function init(): void
    {
        $this->scheduler = $this->di->getObjectManager()->get(Scheduler::class);
        $this->logFile   = 'order_retry.log';
    }

    /**
     * Schedule a retry
     */
    public function scheduleRetry(
        string $userId,
        string $shopId,
        array $data,
        int $delayInSeconds = self::FIRST_FAIL_RETRY_DELAY,
        int $retryCount = 0,
        string $queueName = "retry_failed_order",
        bool $queuePrefixAdded = false,
    ): void {
        $this->init();

        $payload = [
            'user_id' => $userId,
            'shop_id' => $shopId,
            'data' => $data
        ];
        if (!$this->validateMessagePayload($payload, true)) {
            return;
        }

        if (!$queuePrefixAdded && $this->getDi()->getConfig()->get('app_code')) {
            $queueName = $this->getDi()->getConfig()->get('app_code') . '_' . $queueName;
        }

        $message = [
            'type'        => 'full_class',
            'class_name'  => self::class,
            'method'      => 'handle',
            'user_id'     => $userId,
            'shop_id'     => $shopId,
            'queue_name'  => $queueName,
            'data'        => $data,
            'retry_count' => $retryCount,
        ];

        $this->scheduler->schedule($message, $delayInSeconds, $userId ?: null);

        $this->recordRetryAttempt($userId, $shopId, $data, $retryCount, 'scheduled');
    }

    public function handle(array $messageBody)
    {
        $this->init();

        if (!$this->validateMessagePayload($messageBody)) {
            $this->recordRetryAttempt(
                $messageBody['user_id'] ?? 'na',
                $messageBody['shop_id'] ?? 'na',
                $messageBody['data'] ?? [],
                'invalid_payload',
                'Payload missing required keys'
            );
            return true;
        }

        $userId = $messageBody['user_id'] ?? null;
        $shopId = $messageBody['shop_id'] ?? null;
        $queueName  = $messageBody['queue_name'] ?? '';
        $retryCount = $messageBody['retry_count'] ?? 0;
        $data       = $messageBody['data'] ?? [];

        try {
            $order = $this->fetchOrder($userId, $shopId, $data);

            if (empty($order)) {
                $this->log('ORDER_NOT_FOUND', compact('userId','shopId','retryCount','data'), 'error');
                $this->recordRetryAttempt($userId, $shopId, $data, $retryCount, 'permanent_failure', 'Order not found');
                return true;
            }

            if ($this->isPermanentFailure($order)) {
                $this->log('PERMANENT_FAILURE', compact('userId','shopId','retryCount','data'), 'error');
                $this->recordRetryAttempt($userId, $shopId, $data, $retryCount, 'permanent_failure', 'Order marked CANCELLED');
                return true;
            }

            //adding this to prevent duplicate retry schedule
            $order['use_failed_order_scheduler'] = false;

            $retryResponse = $this->executeRetry($userId, $shopId, $order, $retryCount);

            if (!empty($retryResponse['success'])) {
                $this->log('SUCCESS', compact('userId','shopId','retryCount','data'));
                $this->recordRetryAttempt($userId, $shopId, $data, $retryCount, 'success');
                return true;
            }

            $errorMsg = $retryResponse['message']['save'][$shopId] ?? $retryResponse['message'] ?? $retryResponse['error'] ?? null;

            $this->log('RETRY_FAILED', compact('userId','shopId','retryCount','data', 'retryResponse'), 'error');
            $this->recordRetryAttempt($userId, $shopId, $data, $retryCount, 'failure', $$errorMsg);

            $nextDelay = $this->calculateNextDelay($retryCount);
            $this->scheduleRetry($userId, $shopId, $data, $nextDelay, $retryCount + 1, $queueName, true);
            return true;
        } catch (\Throwable $e) {
            $this->log('EXCEPTION_IN_HANDLE', compact('userId','shopId','retryCount','data') + ['error' => $e->getMessage()], 'error');
            $this->recordRetryAttempt($userId, $shopId, $data, $retryCount, 'exception', $e->getMessage());
            throw $e;
        }
    }

    private function executeRetry(string $userId, string $shopId, array $order, int $retryCount): ?array
    {
        $orderComponent = $this->di->getObjectManager()->get(Order::class);
        $orderParams = ['savedData' => $order];

        return $orderComponent->createSavedOrder($orderParams);
    }

    private function fetchOrder(string $userId, string $shopId, array $data): ?array
    {
        $orderContainer = $this->getMongoCollection(self::ORDER_CONTAINER_COLLECTION);

        $query = [
            'user_id' => $userId,
            'object_type' => 'source_order',
            'marketplace' => $data['marketplace'] ?? null,
            'marketplace_shop_id' => $data['marketplace_shop_id'] ?? null,
            'marketplace_reference_id'=> $data['marketplace_reference_id'] ?? null,
        ];

        return $orderContainer->findOne($query, $this->getMongoOptions());
    }

    private function isPermanentFailure(array $order): bool
    {
        return isset($order['status']) && $order['status'] === 'Cancelled';
    }

    private function calculateNextDelay(int $retryCount, ?bool $isLastShipDate = null): int
    {
        $baseDelay = $retryCount > 0 ? self::NEXT_FAIL_RETRY_DELAY : self::FIRST_FAIL_RETRY_DELAY;

        if ($isLastShipDate) {
            $baseDelay = self::LAST_DAY_FAIL_RETRY_DELAY;
        }

        $jitter = rand(-1200, 1200); // ±20min

        return max(1, $baseDelay + $jitter);
    }

    private function recordRetryAttempt(
        string $userId,
        string $shopId,
        array $data,
        int $retryCount,
        string $status,
        ?string $error = null
    ): void {
        try {
            $collection = $this->getMongoCollection(self::RETRY_CONTAINER_COLLECTION);

            $doc = [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'marketplace_reference_id' => $data['marketplace_reference_id'] ?? null,
                'retry_count'   => $retryCount,
                'status' => $status,
                'data'          => $data,
                'retry_type'    => in_array($status, ['scheduled']) ? 'scheduled' : 'executed',
                'created_at'    => new MongoDate(),
            ];

            if ($error) {
                $doc['error'] = $error;
            }

            $collection->insertOne($doc);

            $this->log('RETRY_LOGGED', $doc);
        } catch (\Throwable $e) {
            $this->log('RETRY_LOG_ERROR', [
                'user_id'     => $userId,
                'shop_id'     => $shopId,
                'retry_count' => $retryCount,
                'status'      => $status,
                'error'       => $e->getMessage(),
            ], 'error');
        }
    }

    private function validateMessagePayload(array $payload): bool
    {
        $requiredKeys = ['user_id', 'shop_id', 'data'];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $payload) || $payload[$key] === null) {
                $this->log('INVALID_PAYLOAD', [
                    'missing_key' => $key,
                    'payload'     => $payload,
                ], 'error');
                return false;
            }
        }
        return true;
    }

    private function log(string $msg, array $data, string $level = 'info'): void
    {
        $this->di->getLog()->logContent($msg . ': ' . json_encode($data), $level, $this->logFile);
    }

    private function getMongoCollection(string $collection)
    {
        return $this->di->getObjectManager()->get(BaseMongo::class)->getCollection($collection);
    }

    private function getMongoOptions(): array
    {
        return ['typeMap' => ['root' => 'array', 'document' => 'array']];
    }
}
