<?php

namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Components\Order\Grid as OrderGrid;

class OrderController extends BaseController
{
    public function getCountAction()
    {
        $params = $this->getRequestData();
        $isBulk = $params['bulk'] ?? false;

        try {
            $orderGrid = $this->di->getObjectManager()->get(OrderGrid::class);
            $result = $isBulk
                ? $orderGrid->getCountsByKeys($params)
                : $orderGrid->getCount($params);

            return $this->prepareResponse($result);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => "Failed to fetch order count: " . $e->getMessage(),
            ]);
        }
    }

    public function getAction()
    {
        $params = $this->getRequestData();
        try {
            $orderGrid = $this->di->getObjectManager()->get(OrderGrid::class);
            $result = $orderGrid->getData($params);

            return $this->prepareResponse($result);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => "Failed to fetch orders: " . $e->getMessage(),
            ]);
        }
    }

    public function updateOrderFetchDateAction()
    {
        try {
            $params = $this->getRequestData();
            $response = $this->di->getObjectManager()->get(\App\Amazon\Components\Manual\Helper::class)
                ->updateOrderFetchDate($params);
            return $this->prepareResponse($response);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => "Failed to update order fetch date: " . $e->getMessage(),
            ]);
        }
    }
}
