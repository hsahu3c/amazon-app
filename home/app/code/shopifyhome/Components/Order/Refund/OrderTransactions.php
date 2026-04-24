<?php

namespace App\Shopifyhome\Components\Order\Refund;

use App\Core\Components\Base;

class OrderTransactions extends Base
{
    const SALE_KIND = 'sale';
    private $orderId;
    public function __construct($orderId)
    {
        $this->orderId = $orderId;
    }

    public function getTransactions()
    {
        $shops = $this->di->getUser()->getShops() ?? [];
        $helper = $this->di->getObjectManager()->get(Helper::class,[$shops]);
        $params = [
            'order_id' => $this->orderId
        ];
        $response = $helper->request('get/ordertransactions', $params, "GET");
        if (isset($response['success']) && !$response['success']) {
            return [
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to get transactions from Shopify'
            ];
        }

        if(isset($response['data']['transactions']) && count($response['data']['transactions']) == 0) {
            return [
                'success' => false,
                'message' => 'No transaction found'
            ];
        }

        return [
            'success' => true,
            'data' => $response['data']['transactions']
        ];
    }

    public function getSaleTransaction()
    {
        $response = $this->getTransactions();
        if (!$response['success']) {
            return $response;
        }

        foreach ($response['data'] as $transaction) {
            if ($transaction['kind'] === OrderTransactions::SALE_KIND) {
                return $transaction;
            }
        }

        return null;
    }
}
