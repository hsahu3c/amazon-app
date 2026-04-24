<?php


namespace App\Amazon\Components\Sales;

use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base;

class Sales extends Base
{
    public function getOrderMatric($shopId, $userId, $data)
    {
        $result = [];
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shopDetails = $userDetails->getShop($shopId, $userId);
        if (!empty($shopDetails) && isset($shopDetails['remote_shop_id'])) {
            $params = $data;
            $params['shop_id'] = $shopDetails['remote_shop_id'];
            $response = $this->di->getObjectManager()->get(Helper::class)->sendRequestToAmazon('order-matrix', $params, 'GET');
            if ($response['success']) {
                return [
                    'success' => true,
                    'data' => $response['response'] ?? []
                ];
            }
            $result = [
                'success' => false,
                'message' => 'Error in fetching order metric'
            ];
        } else {
            $result = [
                'success' => false,
                'message' => 'Shop not found!'
            ];
        }

        return $result;
    }
}
