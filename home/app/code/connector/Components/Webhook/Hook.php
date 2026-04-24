<?php

namespace App\Connector\Components\Webhook;

use App\Core\Components\Base;

class Hook extends Base
{
    public function formatSingleWebhook(array $data, array $payload)
    {
        return [
            'topic' => $data['topic'],
            'address' => $this->getEndpoint($data, $payload),
            'format' => 'json',
            'code' => $data['code'],
        ];
    }

    private function getEndpoint(array $data, array $payload): string
    {
        $queryParams = http_build_query([
            'shop_id' => $payload['shop_id'],
            'user_id' => $this->di->getUser()->id,
            'topic' => $data['code'] ?? $data['topic'],
            'prefix' => $this->di->getConfig()->get('app_code') ?? $payload['app_code'],
        ]);
        $url = $this->di->getConfig()->path("webhooks.endpoint");
        if (!$url) {
            throw new \Exception('Webhook endpoint is missing, dweeb. Add at webhooks.endpoint in config.php');
        }
        return "$url?$queryParams";
    }
}
