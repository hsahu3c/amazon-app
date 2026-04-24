<?php

namespace App\Amazon\Components\WebhookHandler;

use Exception;
use App\Core\Models\BaseMongo;
use App\Amazon\Components\CredRotation\Rotation;
use App\Core\Components\Base as Base;

class Hook extends Base
{
    const ORDER_CHANGE = 'ORDER_CHANGE';
    const ORDER_STATUS_CHANGE = 'ORDER_STATUS_CHANGE';
    const FEED_PROCESSING_FINISHED = 'FEED_PROCESSING_FINISHED';
    const REPORT_PROCESSING_FINISHED = 'REPORT_PROCESSING_FINISHED';
    const LISTINGS_ITEM_STATUS_CHANGE = 'LISTINGS_ITEM_STATUS_CHANGE';
    const LISTINGS_ITEM_ISSUES_CHANGE = 'LISTINGS_ITEM_ISSUES_CHANGE';
    const PRODUCT_TYPE_DEFINITIONS_CHANGE = 'PRODUCT_TYPE_DEFINITIONS_CHANGE';
    const FBA_INVENTORY_AVAILABILITY_CHANGES = 'FBA_INVENTORY_AVAILABILITY_CHANGES';

    const NOTIFICATIONS_WITHOUT_SUBSCRIPTION_ID = [];
    const NOTIFICATIONS_TO_PROCESS_DIRECTLY = [
        'LISTINGS_ITEM_STATUS_CHANGE',
        'LISTINGS_ITEM_ISSUES_CHANGE',
        'PRODUCT_TYPE_DEFINITIONS_CHANGE',
        'FBA_INVENTORY_AVAILABILITY_CHANGES'
    ];

    const NOTIFICATIONS_REQUIRING_DI = [
        'FBA_INVENTORY_AVAILABILITY_CHANGES'
    ];

    public $baseLogPath = 'webhook-handler/amazon/';

    public $logFile = null;

    public $notificationType = null;

    public function processMessage($message)
    {
        $queueName = $message['queue_name'] ?? '';
        $this->logFile = $this->baseLogPath . date('Y-m-d') . DS . $queueName . DS . 'global.log';

        $this->writeLog('Message received - ', $message);

        if (empty($message)) {
            $this->writeLog('Empty Message data', $message, 'Error');
            return false;
        }

        if (isset($message['detail'])) {
            $webhookData = $message['detail'];
            unset($message['detail']);
            $message += $webhookData;
        }

        $extractIdentifiersResponse = $this->extractIdentifiersFromMessage($message);
        if (!$extractIdentifiersResponse['success']) {
            $this->writeLog(
                'error in getting seller id, marketplace id or subscription id',
                $extractIdentifiersResponse,
                'Error'
            );
            return false;
        }

        $sellerId = $extractIdentifiersResponse['seller_id'];
        $marketplaceId = $extractIdentifiersResponse['marketplace_id'];
        $subscriptionId = $extractIdentifiersResponse['subscription_id'];

        $this->logFile = !is_null($subscriptionId)
            ? $this->baseLogPath . date('Y-m-d') . DS . $queueName . DS . 'sub-id-' . $subscriptionId . DS . 'pid-id-' . getmypid() . '.log'
            : $this->baseLogPath . date('Y-m-d') . DS . $queueName . DS . 'pid-id-' . getmypid() . '.log';

        $userData = $this->getUser($sellerId, $subscriptionId);
        if (empty($userData)) {
            $this->writeLog('error in getting user data, message', $message, 'Error');
            return false;
        }

        $payload = $message['Payload'] ?? $message['payload'];
        $userId = $userData['user_id'];
        $amazonShopIds = $this->getAmazonShopIds($userData, $sellerId, $marketplaceId, $subscriptionId);
        if (!$amazonShopIds) {
            $this->writeLog('error in getting amazon shop ids, user data', $userData, 'Error');
            return false;
        }
        $appTag = $this->di->getConfig()->default_queue_config[$message['queue_name']]['appTag'] ?? null;
        $appCodes = $this->di->getConfig()->app_tags[$appTag]['app_code'] ?? null;
        $appCode = $appCodes['amazon'] ?? null;
        $response = true;

        //set DI for notifications requiring DI
        if (in_array($this->notificationType, self::NOTIFICATIONS_REQUIRING_DI)) {
            $setDiResponse = $this->setDiForUser($userId);
            if (!$setDiResponse['success']) {
                $this->writeLog('error in setting di for user', [$setDiResponse, $message], 'Error');
                return false;
            }
        }

        foreach ($amazonShopIds as $shopId) {
            $updatedMessage = $this->prepareQueueMessage($userId, $payload, $shopId, $appTag, $appCode);
            $response = $this->dispatchMessage($updatedMessage);
        }

        return $response;
    }

    private function extractIdentifiersFromMessage($message)
    {
        $notificationType = $message['NotificationType'] ?? $message['notificationType'] ?? null;
        $notificationMetaData = $message['NotificationMetadata'] ?? $message['notificationMetadata'] ?? [];
        $subscriptionId = $notificationMetaData['SubscriptionId'] ?? $notificationMetaData['subscriptionId'] ?? null;
        if (is_null($notificationType)) {
            return ['success' => false, 'message' => 'notification type not found'];
        }

        $this->notificationType = $notificationType;

        $sellerId = null;
        $marketplaceId = null;
        $payload = $message['Payload'] ?? $message['payload'] ?? [];

        switch ($notificationType) {
            case self::ORDER_CHANGE:
                $sellerId = $payload['OrderChangeNotification']['SellerId'] ?? null;
                $marketplaceId = $payload['OrderChangeNotification']['Summary']['MarketplaceId'] ?? null;
                break;
            case self::REPORT_PROCESSING_FINISHED:
                $sellerId = $payload['reportProcessingFinishedNotification']['sellerId'] ?? null;
                $marketplaceId = null;
                break;

            #EventBridge Notification Type
            case self::LISTINGS_ITEM_STATUS_CHANGE:
                $sellerId = $payload['SellerId'] ?? null;
                $marketplaceId = $payload['MarketplaceId'] ?? null;
                break;
            case self::LISTINGS_ITEM_ISSUES_CHANGE:
                $sellerId = $payload['SellerId'] ?? null;
                $marketplaceId = $payload['MarketplaceId'] ?? null;
                break;
            case self::PRODUCT_TYPE_DEFINITIONS_CHANGE:
                $sellerId = $payload['SellerId'] ?? null;
                $marketplaceId = $payload['MarketplaceId'] ?? null;
                break;
            case self::FBA_INVENTORY_AVAILABILITY_CHANGES:
                $sellerId = $payload['SellerId'] ?? null;
                $marketplaceId = null;
                break;
            default:
                # only allow processing for notifications which are defined
                return [
                    'success' => false,
                    'message' => 'Unsupported notification type: ' . $notificationType,
                    'data' => $message
                ];
        }

        if (is_null($subscriptionId) && !in_array($notificationType, self::NOTIFICATIONS_WITHOUT_SUBSCRIPTION_ID)) {
            return [
                'success' => false,
                'message' => 'Subscription ID not found',
                'data' => $message
            ];
        }

        return [
            'success' => true,
            'seller_id' => $sellerId,
            'marketplace_id' => $marketplaceId,
            'subscription_id' => $subscriptionId
        ];
    }

    private function prepareQueueMessage(string $userId, array $payload, string $shopId, ?string $appTag, ?string $appCode): array
    {
        $updatedMessage = [
            'type' => $this->getType(),
            'method' => $this->getMethod(),
            'class_name' => $this->getClassName(),
            'user_id' => $userId,
            'shop_id' => $shopId,
            'action' => $this->getAction(),
            'queue_name' => $this->getQueueName(),
            'marketplace' => 'amazon',
            'data' => $payload
        ];
        if (!is_null($appTag)) {
            $updatedMessage['appTag'] = $appTag;
        }

        if (!is_null($appCode)) {
            $updatedMessage['appCode'] = $appCode;
            $updatedMessage['app_code'] = $appCode;
        }
        return $updatedMessage;
    }

    private function getQueueName()
    {
        $queueName = null;
        $queueName = match ($this->notificationType) {
            self::ORDER_CHANGE => 'order_change',
            self::FEED_PROCESSING_FINISHED => 'feed_processing_finished',
            self::REPORT_PROCESSING_FINISHED => 'report_processing_finished',
            self::LISTINGS_ITEM_STATUS_CHANGE => 'listings_item_status_change',
            self::LISTINGS_ITEM_ISSUES_CHANGE => 'listings_item_issues_change',
            self::PRODUCT_TYPE_DEFINITIONS_CHANGE => 'product_type_definitions_change',
            self::FBA_INVENTORY_AVAILABILITY_CHANGES => 'fba_inventory_availability_change',
            default => null,
        };
        return $queueName;
    }

    private function getAction()
    {
        $action = null;
        $action = match ($this->notificationType) {
            self::ORDER_CHANGE, self::FEED_PROCESSING_FINISHED, self::REPORT_PROCESSING_FINISHED, self::LISTINGS_ITEM_STATUS_CHANGE, self::LISTINGS_ITEM_ISSUES_CHANGE, self::PRODUCT_TYPE_DEFINITIONS_CHANGE, self::FBA_INVENTORY_AVAILABILITY_CHANGES => $this->notificationType,
            default => null,
        };
        return $action;
    }

    private function dispatchMessage($message)
    {
        if (empty($message)) {
            return false;
        }

        if (in_array($this->notificationType, self::NOTIFICATIONS_TO_PROCESS_DIRECTLY)) {
            // process the message directly
            try {
                $class = $message['class_name'];
                $method = $message['method'];
                $this->getDi()->getObjectManager()->get($class)->$method($message);
            } catch (Exception $e) {
                $this->writeLog('error in processing message directly', $e, 'Error');
                return false;
            }
            return true;
        }

        $response = true;
        $pushSuccessful = $this->pushMessageToQueue($message);
        if (!$pushSuccessful) {
            $this->writeLog('error in pushing message to queue, queue data', $message, 'Error');
            $response = false;
        }
        return $response;
    }

    public function getAmazonShopIds($userData, $sellerId, $marketplaceId = null, $subscriptionId = null)
    {
        if (empty($userData)) {
            return null;
        }

        $allAmazonShopIds = [];
        $shops = $userData['shops'] ?? [];

        if (empty($sellerId) && empty($marketplaceId) && !empty($subscriptionId)) {
            foreach ($shops as $shop) {
                if (isset($shop['marketplace']) && $shop['marketplace'] == 'amazon' && !empty($shop['apps'][0]['webhooks'])) {
                    foreach ($shop['apps'][0]['webhooks'] as $webhook) {
                        if (isset($webhook['marketplace_subscription_id']) && $webhook['marketplace_subscription_id'] == $subscriptionId) {
                            return [$shop['_id']];
                        }
                    }
                }
            }
            return false;
        }

        foreach ($shops as $shop) {
            if (isset($shop['marketplace']) && $shop['marketplace'] == 'amazon') {
                $warehouses = $shop['warehouses'] ?? [];
                foreach ($warehouses as $warehouse) {
                    if (isset($warehouse['seller_id']) && $warehouse['seller_id'] == $sellerId) {
                        $allAmazonShopIds[] = $shop['_id'];
                        if ($marketplaceId != null && $marketplaceId == $warehouse['marketplace_id']) {
                            return [$shop['_id']];
                        }
                    }
                }
            }
        }

        return !empty($allAmazonShopIds) ? $allAmazonShopIds : false;
    }

    public function getUser($sellerId, $subscriptionId)
    {
        $query = [];

        if (!empty($subscriptionId)) {
            $query[] = ['shops.apps.webhooks.marketplace_subscription_id' => $subscriptionId];
        }

        if (!empty($sellerId)) {
            $query[] = ['shops.warehouses.seller_id' => $sellerId];
        }

        if(empty($query)) {
            return [];
        }

        $userDetails = $this->getMongoCollection('user_details');
        return $userDetails->findOne(
            [
                '$or' => $query,
            ],
            ['typeMap'   => ['root' => 'array', 'document' => 'array']]
        );
    }

    public function getType(): string
    {
        return 'full_class';
    }

    public function getMethod(): string|null
    {
        $method = null;
        $method = match ($this->notificationType) {
            default => 'triggerWebhooks',
            self::FBA_INVENTORY_AVAILABILITY_CHANGES => 'handleFbaInventoryAvailabilityChange',
        };
        return $method;
    }

    public function getClassName(): string|null
    {
        $class = null;
        $class = match ($this->notificationType) {
            default => '\\App\\Connector\\Models\\SourceModel',
            self::FBA_INVENTORY_AVAILABILITY_CHANGES => \App\Amazon\Components\Product\Inventory\Notification::class,
        };
        return $class;
    }

    public function pushMessageToQueue($message): bool
    {
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        try {
            $sqsHelper->pushMessage($message);
        } catch (Exception $e) {
            $this->writeLog('exception in pushMessage', $e, 'Error');
            return false;
        }

        return true;
    }

    private function getMongoCollection(string $collection): object
    {
        return $this->di->getObjectManager()->create(BaseMongo::class)->getCollection($collection);
    }

    public function writeLog($key, $message, $type = 'info'): void
    {
        $this->di->getLog()
            ->logContent(
                str_repeat('-', 50) . PHP_EOL . $key . ' = ' . print_r($message, true),
                $type,
                $this->logFile
            );
    }

    public function handleApplicationClientSecretRotation($message)
    {
        $this->logFile = $this->baseLogPath  . DS . 'secretRotation' . DS . date('Y-m-d') . '.log';
        $this->writeLog('Secret Rotation data received from queue: ', json_encode($message));
        $response = [];
        if (!empty($message)) {
            isset($message['data']['notificationType']) && $message = $message['data'];
            if (isset($message['notificationType']) && isset($message['notificationMetadata']['applicationId'])) {
                $applicationId = $message['notificationMetadata']['applicationId'];
                $message['payload']['applicationId'] = $applicationId;
                $notificationType = $message['notificationType'];
                $this->writeLog('notificationType: ', $notificationType);
                $rotationClass = $this->di->getObjectManager()->get(Rotation::class);
                if ($notificationType == 'APPLICATION_OAUTH_CLIENT_SECRET_EXPIRY') {
                    $response = $rotationClass->initiateSecretRotation($message['payload']);
                } elseif ($notificationType == 'APPLICATION_OAUTH_CLIENT_NEW_SECRET') {
                    $response = $rotationClass->rotateSecret($message['payload']);
                } else {
                    $response = [
                        'success' => false,
                        'message' => 'Invalid notification type!'
                    ];
                }

                $this->writeLog('response: ', json_encode($response));
                return $response;
            }
        } else {
            $this->writeLog('error: ', 'Invalid Data');
            return [
                'success' => false,
                'message' => 'Invalid data!'
            ];
        }
    }

    public function setDiForUser($userId): array
    {
        try {
            $getUser = \App\Core\Models\User::findFirst([['_id' => $userId]]);
        } catch (\Exception $e) {
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
}
