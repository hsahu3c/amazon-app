<?php

namespace App\Connector\Components\Webhook;

use App\Core\Components\Base;
use \Phalcon\Events\Event;

class Events extends Base
{

    public function afterSingleWebhookSubscribe(Event $event, $component, array $data): array
    {
        $appWebhook = $this->di->getObjectManager()->get(AppWebhook::class);
        $result = $appWebhook->add($data['marketplace'], $data['app_code'], $data['shop_id'], $data['webhook']);
        if ($result) {
            $prefix = !empty($data['prefix']) ? $data['prefix'] . '_' : "";
            $queueName = "{$prefix}{$data['webhook']['code']}";
            $this->di->getObjectManager()->get(Queue::class)->create($queueName, $data['marketplace'], $data['app_code']);
        }
        return $data;
    }

    public function afterSingleWebhookUnsubscribe(Event $event, $component, array $data): array
    {
        $appWebhook = $this->di->getObjectManager()->get(AppWebhook::class);
        $result = $appWebhook->delete($data['marketplace'], $data['app_code'], $data['shop_id'], $data['webhook']);
        return $data;
    }
}
