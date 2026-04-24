<?php

namespace App\Shopifyhome\Components;

use App\Core\Components\Base;

class App extends Base
{
    public function get()
    {
        try {
            $helper = $this->di->getObjectManager()->get(Helper::class);
            $remoteShopId = $helper->getRemoteShopId();
            $response = $helper->sendRequestToShopify('/shopify_app', [], [
                'shop_id' => $remoteShopId,
                'app_code' => 'shopify'
            ], 'GET');
            return $response;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
