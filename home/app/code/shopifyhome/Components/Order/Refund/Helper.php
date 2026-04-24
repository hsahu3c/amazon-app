<?php

namespace App\Shopifyhome\Components\Order\Refund;

use Exception;
use App\Core\Components\Base;
use App\Connector\Components\ApiClient;

class Helper extends Base
{
    public const MARKETPLACE = 'shopify';

    private $remoteShopId;

    public function __construct($shops)
    {
        $shop = $this->getShopifyShop($shops);
        if (isset($shop['remote_shop_id'])) {
            $this->remoteShopId = $shop['remote_shop_id'];
        } else {
            throw new Exception('Remote shop id is missing');
        }
    }

    public function request(string $endPoint, array $params, string $method)
    {
        $appCode = $this->di->getAppCode()->get()[Helper::MARKETPLACE];
        $params['shop_id'] = $this->remoteShopId;
        return $this->di->getObjectManager()->get(ApiClient::class)
            ->init('shopify', true, $appCode)
            ->call($endPoint, [], $params, $method, $method === "GET" ? null : "json");
    }

    public function getShopifyShop($shops)
    {
        $shop = [];
        foreach ($shops as $shop) {
            if ($shop['marketplace'] === Helper::MARKETPLACE) {
                return $shop;
            }
        }

        return $shop;
    }
}
