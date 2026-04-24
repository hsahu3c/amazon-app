<?php
namespace App\Connector\Components\Webhook;

use App\Core\Components\Base;
use App\Core\Models\User\Details;

class AppWebhook extends Base
{
    public function getSelectedWebhooks(string $marketplace, string $appCode, string $shopId): array
    {
        $helper = $this->di->getObjectManager()->get(Helper::class);
        $shop = $helper->getShop($shopId);
        $client = $helper->getClient($marketplace, $appCode);
        $response = $client->call('appsWebhooks', [], [
            'shop_id' => $shop['remote_shop_id'],
        ], 'GET');
        if (!$response['success']) {
            throw new \Exception($response['message']);
        }
        return $response['data'];
    }

    public function add(string $marketplace, string $appCode, string $shopId, array $webhook): bool
    {
        $userDetailsModel = $this->di->getObjectManager()->get(Details::class);
        $result = $userDetailsModel->getCollection()->updateOne(
            [
                'user_id' => $this->di->getUser()->id,
            ],
            [
                '$push' => [
                    'shops.$[shop].apps.$[app].webhooks' => [
                        'code' => $webhook['code'],
                        'id' => $webhook['id'],
                    ],
                ],
            ],
            [
                'arrayFilters' => [
                    [
                        'shop._id' => $shopId,
                    ],
                    [
                        'app.code' => $appCode ?? $marketplace,
                    ],
                ],
            ]
        );
        return $result->getModifiedCount() || $result->getMatchedCount();
    }

    public function delete(string $marketplace, string $appCode, string $shopId, array $webhook): bool
    {
        $userDetailsModel = $this->di->getObjectManager()->get(Details::class);
        $result = $userDetailsModel->getCollection()->updateOne(
            [
                'user_id' => $this->di->getUser()->id,
            ],
            [
                '$pull' => [
                    'shops.$[shop].apps.$[app].webhooks' => $webhook,
                ],
            ],
            [
                'arrayFilters' => [
                    [
                        'shop._id' => $shopId,
                    ],
                    [
                        'app.code' => $appCode ?? $marketplace,
                    ],
                ],
            ]
        );
        return $result->getModifiedCount() || $result->getMatchedCount();
    }

    public function get(string $marketplace, string $appCode, string $shopId): array
    {
        $userDetailsModel = $this->di->getObjectManager()->get(Details::class);
        $result = $userDetailsModel->getCollection()->findOne(
            [
                'user_id' => $this->di->getUser()->id,
                'shops._id' => $shopId,
                'shops.apps.code' => $appCode ?? $marketplace,
            ],
            [
                'projection' => [
                    'shops.apps.$' => true,
                ],
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ]
        );
        return $result['shops'][0]['apps'][0]['webhooks'] ?? [];
    }
}