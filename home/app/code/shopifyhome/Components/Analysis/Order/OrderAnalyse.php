<?php

namespace App\Shopifyhome\Components\Analysis\Order;

use App\Shopifyhome\Components\Order\Order;
use App\Shopifyhome\Components\Core\Common;

class OrderAnalyse extends Common
{
    public function syncOrdersAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if ( !isset($rawBody['user_id']) ) $rawBody['user_id'] = $this->di->getUser()->id;

        $res = $this->di->getObjectManager()->get(Order::class)
            ->initMain($rawBody)
            ->syncOrdersManually($rawBody);
        return $this->prepareResponse($res);
    }

    public function shipOrderAction(): void
    {
        $customerData = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\OrderREST');
        $customerData->createShipment();
    }

    public function cancelOrderAction(): void
    {
        $customerData = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\OrderREST');
        $customerData->cancelShipment();
    }

    public function orderUploadAction(): void
    {
        $shopData = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\OrderREST')->init(false);
        //$shopData->completeOrder();
        $shopData->createOrder();
    }
}