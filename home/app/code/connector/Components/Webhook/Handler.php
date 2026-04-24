<?php

namespace App\Connector\Components\Webhook;

use App\Core\Components\Base;

class Handler extends Base
{
    const SUBSCRIBE_ACTION = 'subscribe';
    const UNSUBSCRIBE_ACTION = 'unsubscribe';
    public function processTask(array $data): bool
    {
        $marketplaceName = $data['marketplace'] ?? $this->di->getRequester()->getSourceName() ?? "";
        $appCode = $data['app_code'] ?? $this->di->getAppCode()->get()[$marketplaceName] ?? "";
        $client = $this->di->getObjectManager()->get(Helper::class)->getClient($marketplaceName, $appCode);
        switch ($data['action']) {
            case self::SUBSCRIBE_ACTION:
                return $this->createWebhook($data, $client);
            case self::UNSUBSCRIBE_ACTION:
                return $this->deleteWebhook($data, $client);
            default:
                throw new \Exception('Invalid action');
        }
        return true;
    }

    private function createWebhook(array $data, $client): bool
    {
        if (!isset($data['webhook']) || !isset($data['remote_shop_id'])) {
            $this->di->getObjectManager()->get(Helper::class)->dearDiary("create", "Webhook or remote data is missing");
            return false;
        }

        $response = $client->call('webhook', [], [
            'shop_id' => $data['remote_shop_id'],
            'data' => $data['webhook'],
        ], 'POST');

        if (!$response['success']) {
            $this->di->getObjectManager()->get(Helper::class)->dearDiary("create", json_encode($response));
            return true;
        }
        $data['webhook']['id'] = $response['data']['id'];
        $this->di->getEventsManager()->fire("application:afterSingleWebhookSubscribe", $this, $data);
        return true;
    }

    private function deleteWebhook(array $data, $client): bool
    {
        if (!isset($data['webhook']['id']) || !isset($data['remote_shop_id'])) {
            $this->di->getObjectManager()->get(Helper::class)->dearDiary("delete", "Webhook or remote data is missing");
            return true;
        }
        $response = $client->call('webhook', [], [
            'shop_id' => $data['remote_shop_id'],
            'id' => $data['webhook']['id'],
        ], 'DELETE');
        if (!$response['success']) {
            $this->di->getObjectManager()->get(Helper::class)->dearDiary("delete", json_encode($response));
            return true;
        }
        $this->di->getEventsManager()->fire("application:afterSingleWebhookUnsubscribe", $this, $data);
        return true;
    }
}
