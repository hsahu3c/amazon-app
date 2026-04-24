<?php

namespace App\Connector\Components\Webhook;

use App\Connector\Components\Webhook\SubscriptionInterface;
use App\Core\Components\Base;

class SubscriptionService extends Base implements SubscriptionInterface
{
    const REGISTER_WEBHOOK_QUEUE_NAME = 'webhook_subscribe';
    const UNREGISTER_WEBHOOK_QUEUE_NAME = 'webhook_unsubscribe';
    const SUBSCRIBE_ACTION = 'subscribe';
    const UNSUBSCRIBE_ACTION = 'unsubscribe';

    /**
     * Subscribe webhooks from given shop
     *
     * @param array $request
     * @return array
     * @throws \Exception
     */
    public function subscribe(array $request): array
    {
        $marketplaceName = $request['marketplace'] ?? $this->di->getRequester()->getSourceName() ?? "";
        $shopId = $request['shop_id'] ?? $this->di->getRequester()->getSourceId() ?? "";
        $appCode = $request['app_code'] ?? $this->di->getAppCode()->get()[$marketplaceName] ?? "";
        if (empty($appCode) || empty($shopId) || empty($marketplaceName)) {
            throw new \Exception('Invalid request. Missing app_code, shop_id, or marketplace');
        }
        $message = 'It may take some time to register webhooks. Please wait...';
        $failedWebhooks = [];
        $objectManager = $this->di->getObjectManager();
        $helper = $objectManager->get(Helper::class);
        $hookClass = $helper->getHookClass($marketplaceName);
        $hook = $objectManager->get($hookClass);
        $shop = $helper->getShop($shopId);
        // Priority for requested webhooks
        if (isset($request['webhooks'])) {
            $webhooks = $request['webhooks'];
        } else {
            // Get webhooks that you ticked in the remote admin panel
            $webhooks = $objectManager->get(AppWebhook::class)->getSelectedWebhooks($marketplaceName, $appCode, $shopId);
        }

        $registeredWebhookCodes = $this->getRegisteredWebhookCodes(
            $marketplaceName,
            $appCode,
            $shopId
        );
        foreach ($webhooks as $webhook) {
            if (in_array($webhook['code'], $registeredWebhookCodes)) {
                continue;
            }
            $registeredWebhookCodes[] = $webhook['code'];
            $payload = [];
            $payload['remote_shop_id'] = $shop['remote_shop_id'];
            $payload['shop_id'] = $shop['_id'];
            $payload['marketplace'] = $marketplaceName;
            $payload['app_code'] = $appCode;
            $payload['queue_name'] = self::REGISTER_WEBHOOK_QUEUE_NAME;
            $payload['action'] = self::SUBSCRIBE_ACTION;
            $payload['prefix'] = $this->di->getConfig()->get('app_code') ?? $payload['app_code'];
            $payload['webhook'] = $hook->formatSingleWebhook($webhook, $payload);
            if (isset($webhook['queueable']) && ($webhook['queueable'] === false)) {
                $result = $objectManager->get(Handler::class)->processTask($payload);
                if (!$result) {
                    $failedWebhooks[] = $webhook['code'];
                }
            } else {
                $helper->enqueueTask($helper->createTask($payload));
            }
        }

        $this->di->getEventsManager()->fire('application:afterWebhookSubscribe', $this, [
            'shop' => $shop,
            'webhooks' => $webhooks,
        ]);
        $response = [
            'success' => true,
            'message' => count($failedWebhooks) ? 'Few webhooks failed to subscribe' : 'Webhook(s) subscribed successfully',
        ];
        if (count($failedWebhooks) > 0) {
            $response['failed_webhooks'] = $failedWebhooks;
        }
        return $response;
    }

    /**
     * Get list of webhook codes that are already registered in dynamo db for given shop and app
     *
     * @param string $marketplaceName
     * @param string $appCode
     * @param string $shopId
     * @return array
     */
    public function getRegisteredWebhookCodes(string $marketplaceName, string $appCode, string $shopId): array
    {
        $registeredWebhooks = $this->di->getObjectManager()->get(AppWebhook::class)->get($marketplaceName, $appCode, $shopId);
        $registeredWebhookCodes = [];
        if (!empty($registeredWebhooks)) {
            // Get code of registered webhooks
            foreach ($registeredWebhooks as $webhook) {
                $registeredWebhookCodes[] = $webhook['code'];
            }
        }
        return $registeredWebhookCodes;
    }

    /**
     * Unsubscribe webhooks from given shop
     *
     * @param array $request
     * @return array
     * @throws \Exception
     */
    public function unsubscribe(array $request): array
    {
        $marketplaceName = $request['marketplace'] ?? $this->di->getRequester()->getSourceName() ?? "";
        $shopId = $request['shop_id'] ?? $this->di->getRequester()->getSourceId() ?? "";
        $appCode = $request['app_code'] ?? $this->di->getAppCode()->get()[$marketplaceName] ?? "";
        if (empty($appCode) || empty($shopId) || empty($marketplaceName)) {
            throw new \Exception('Invalid request. Missing app_code, shop_id, or marketplace');
        }
        $objectManager = $this->di->getObjectManager();
        $helper = $objectManager->get(Helper::class);
        $hookClass = $helper->getHookClass($marketplaceName);
        $hook = $objectManager->get($hookClass);
        $shop = $helper->getShop($shopId);
        $data = [
            'shop_id' => $shop['remote_shop_id'],
        ];

        // Priority for requested webhooks
        if (isset($request['webhooks'])) {
            $webhooks = $request['webhooks'];
        } else {
            // Get webhooks which are saved in your shops
            $webhooks = $objectManager->get(AppWebhook::class)->get($marketplaceName, $appCode, $shopId);
        }

        $excludedWebhooks = [];
        if (isset($request['exclude'])) {
            $excludedWebhooks = $request['exclude'];
        }

        $registeredWebhookCodes = $this->getRegisteredWebhookCodes(
            $marketplaceName,
            $appCode,
            $shopId
        );
        foreach ($webhooks as $webhook) {
            if (!in_array($webhook['code'], $registeredWebhookCodes) || in_array($webhook['code'], $excludedWebhooks)) {
                continue;
            }
            $payload = [];
            $payload['remote_shop_id'] = $shop['remote_shop_id'];
            $payload['shop_id'] = $shop['_id'];
            $payload['marketplace'] = $marketplaceName;
            $payload['app_code'] = $appCode;
            $payload['queue_name'] = self::UNREGISTER_WEBHOOK_QUEUE_NAME;
            $payload['action'] = self::UNSUBSCRIBE_ACTION;
            $payload['webhook'] = $webhook;
            if (isset($webhook['queueable']) && ($webhook['queueable'] === false)) {
                $objectManager->get(Handler::class)->processTask($payload);
            } else {
                $helper->enqueueTask($helper->createTask($payload));
            }
        }

        $this->di->getEventsManager()->fire('application:afterWebhookUnsubscribe', $this, [
            'shop' => $shop,
            'webhooks' => $webhooks,
        ]);
        return [
            'success' => true,
            'message' => 'Unsubscribing webhooks is in progress. It may take sometime.',
        ];
    }

    public function update(array $request): array
    {
        // TODO: Implement update() method.
        return [];
    }

    public function get(array $request): array
    {
        // TODO: Implement get() method.
        return [];
    }
}
