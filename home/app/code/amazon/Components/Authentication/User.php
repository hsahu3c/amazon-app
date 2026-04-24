<?php

namespace App\Amazon\Components\Authentication;

use App\Core\Components\Base;
use App\Core\Models\User\Details;
use App\Connector\Components\ApiClient;
use Phalcon\Config\Config;

class User extends Base
{
    /**
     * Setup User
     *
     * @param Config $data
     */
    public function setup($data): void
    {
        // $marketplace == 'amazon' | $marketplace == 'amazon_india'
        $marketplace = $data->path('data.data.marketplace');
        $userId = (string)$data->path('rawResponse.state');
        $shopId = (string)$data->path('data.data.shop_id');
        if (!empty($marketplace) && !empty($userId) && !empty($shopId)) {
            /** @var \App\Core\Models\User\Details $user */
            $user = $this->di->getObjectManager()->get(Details::class)
                ->getUserbyShopId($shopId, ['user_id' => $userId, 'shops' => 1]);
            $response = $this->di->getObjectManager()->get(ApiClient::class)
                ->init($marketplace, true)->call('/shop', [], [
                'shop_id' => $shopId
            ]);

            // TODO: create user on authentication, if not created.
        }
    }
}