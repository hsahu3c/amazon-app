<?php

namespace App\Connector\Controllers;

class OrderController extends \App\Core\Controllers\BaseController
{

    public function getAction()
    {
        $rawBody = $this->getRequestData();
        $connectorOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
        $responseData = $connectorOrderService->get($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function getAllAction()
    {
        $rawBody = $this->getRequestData();
        $connectorOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
        $responseData = $connectorOrderService->getAll($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function archiveOrderAction()
    {
        $rawBody = $this->getRequestData();
        $connectorOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
        $responseData = $connectorOrderService->archiveOrder($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function getOrdersAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $ordersModel = new \App\Connector\Models\Order;
        $responseData = $ordersModel->getOrders($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function createOrderAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $orderModel = $this->di->getObjectManager()->get(\App\Connector\Components\Order\Order::class);
        return $this->prepareResponse($orderModel->createSavedOrder($rawBody));
    }

    public function getOrderAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $ordersModel = new \App\Connector\Models\OrderContainer;
        $responseData = $ordersModel->getOrderByID($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function getAllOrdersAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $ordersModel = new \App\Connector\Models\OrderContainer;
        $responseData = $ordersModel->getOrders($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function fullfillOrderAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $ordersModel = new \App\Connector\Models\Order;
        $fullfilmentdata = $ordersModel->getfulfillmentdetails($rawBody);
        if ($fullfilmentdata == false) {
            return $this->prepareResponse(['status' => false, 'code' => 'Order not created at Shopify', 'message' => 'Order not created at Shopify']);
        }
        $rawBody['order_data']['fulfillment_data'] = $fullfilmentdata;
        $responseData = $this->di->getObjectManager()->get('\App\Connector\Components\OrderHelper')->fullfillOrderToTarget($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function getOrderByIdAction()
    {
        $ordersModel = new \App\Connector\Models\Order();
        $responseData = $ordersModel->getOrderById($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function uploadOrderAction()
    {
        $ordersModel = new \App\Connector\Models\Order();
        $responseData = $ordersModel->uploadOrder($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function updateOrderStatusAction()
    {
        $ordersModel = new \App\Connector\Models\Order();
        $responseData = $ordersModel->updateOrderStatus($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function syncAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        return $this->prepareResponse($this->di->getObjectManager()->get('App\Connector\Components\OrderHelper')->initiateOrderSync($rawBody));
    }

    public function cancelAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $responseData = $this->di->getObjectManager()->get('\App\Connector\Components\OrderHelper')->cancelOrderToTarget($this->di->getUser()->id, $rawBody);
        return $this->prepareResponse($responseData);
    }

    public function cancelItemAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $responseData = $this->di->getObjectManager()->get('\App\Connector\Components\OrderHelper')->cancelItemToTarget($this->di->getUser()->id, $rawBody);
        return $this->prepareResponse($responseData);
    }

    /**
     * Create Order Action
     * @return string
     */
    public function importAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $order = $this->di->getObjectManager()->create('\App\Connector\Models\OrderContainer');
        return $this->prepareResponse($order->importOrders($rawBody));
    }

    /**
     * Import Order from Marketplace Action
     * @return string
     */
    public function importOrderAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody['target']['marketplace'])) {
            $code = $rawBody['target']['marketplace'];
            $rawBody['code'] = $code;
            $containerModel =  new \App\Connector\Models\Order;
            $responseData = $containerModel->importOrderFromMarketpalce($rawBody);
            $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog("order", "manual import order", $rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        }
        return $this->prepareResponse(['success' => false, 'code' => 'missing_required_params', 'message' => 'Missing Code.']);
    }

    /**
     * Upload Order Action
     * @return string
     */
    public function uploadAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $order = $this->di->getObjectManager()->create('\App\Connector\Models\OrderContainer');
        return $this->prepareResponse($order->uploadOrders($rawBody));
    }

    public function selectAndSyncAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $orderContainer = new \App\Connector\Models\OrderContainer;
        return $this->prepareResponse($orderContainer->syncData($rawBody));
    }

    public function getShippingCarriersAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $orderContainer = $this->di->getObjectManager()->get('\App\Connector\Models\Shipment\Helper');
        return $this->prepareResponse($orderContainer->fetchCarriers($rawBody));
    }

    public function getFailedCancelOrdersAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $orderCancel = $this->di->getObjectManager()->get('\App\Connector\Components\Order\OrderCancel');
        return $this->prepareResponse($orderCancel->getFailedOrders($rawBody));
    }

    public function manualCancelAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $orderCancel = $this->di->getObjectManager()->get('\App\Connector\Components\Order\OrderCancel');
        $rawBody['process'] = 'manual';
        return $this->prepareResponse($orderCancel->cancel($rawBody));
    }

    public function getFailedCancelOrderAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $orderCancel = $this->di->getObjectManager()->get('\App\Connector\Components\Order\OrderCancel');
        return $this->prepareResponse($orderCancel->getOrder($rawBody));
    }

    public function getAllFailedRefundsAction()
    {
        $rawBody = $this->getRequestData();
        $connectorRefundService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class);
        $failedRefunds = $connectorRefundService->getAllFailed($rawBody);
        return $this->prepareResponse($failedRefunds);
    }

    public function getFailedRefundAction()
    {
        $rawBody = $this->getRequestData();
        $connectorRefundService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class);
        $failedRefund = $connectorRefundService->getFailed($rawBody);
        return $this->prepareResponse($failedRefund);
    }

    public function manualRefundAction()
    {
        $this->di->getUser()->getConfig();
        $rawBody = $this->getRequestData();
        $connectorManualRefund = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class, [], 'manual');
        $refundResponse = $connectorManualRefund->refund($rawBody);
        return $this->prepareResponse($refundResponse);
    }

    public function getRefundReasonsAction()
    {
        $rawBody = $this->getRequestData();
        $connectorRefundService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class);
        $refundReasons = $connectorRefundService->getRefundReasons($rawBody);
        return $this->prepareResponse($refundReasons);
    }

    public function testOrderCreateAction()
    {
        $rawBody = $this->getRequestData();
        $data['shop_id'] = $this->request->get('home_shop_id') ?? false;
        $data['marketplace'] =  $this->request->get('marketplace') ?? false;
        if (!$data['shop_id'] || !$data['marketplace']) {
            return $this->prepareResponse(['success' => false, 'message' => 'required params home_shop_id or marketplace missing.']);
        }

        $data['order'] = $rawBody;
        $orderComponent = $this->di->getObjectManager()->get('\App\Connector\Components\Order\Order');
        $response = $orderComponent->create($data);
        return $this->prepareResponse($response);
    }

    public function getCountAction()
    {
        $rawBody = $this->getRequestData();
        if (is_null($rawBody)) {
            return $this->prepareResponse(['success' => false, 'message' => 'Incorrect request format']);
        }

        $userId = $this->di->getUser()->id;
        $targetShopId = $this->di->getRequester()->getTargetId();
        $isBulk = isset($rawBody['bulk']) && $rawBody['bulk'];

        // Generate hash key for bulk based on payload
        $keysHash = '';

        $cacheKey = "ordercount_uid_{$userId}_shop_{$targetShopId}_" . ($isBulk ? 'bulk' : 'normal') ;

        $cache = $this->di->getCache();
        $refresh = filter_var($rawBody['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);


        if ($refresh) {
            $cache->delete($cacheKey);
        }


        $cachedData = $refresh ? false : $cache->get($cacheKey);


        if ($cachedData) {
            return $this->prepareResponse([
                'success' => true,
                'data' => $cachedData['data'],
                'from_cache' => true,
                'cached_at' => $cachedData['cached_at'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        }

        // Fetch fresh data
        $connectorOrderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
        $freshData = $isBulk
            ? $connectorOrderService->getCountsByKeys($rawBody)
            : $connectorOrderService->getCount($rawBody);

        $cachedData = [
            "data" => $freshData['data'],
            "cached_at" => date("c"),
            "is_bulk" => $isBulk
        ];

        $test=$cache->set($cacheKey, $cachedData); // 1 hour

        return $this->prepareResponse([
            'success' => true,
            'data' => $freshData['data'],
            'from_cache' => false,
            'cached_at' => $cachedData['cached_at'], // use actual cached timestamp
        ]);
    }

    public function syncPlatformTaxesAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $orderContainer = $this->di->getObjectManager()->get('\App\Connector\Models\Order\Helper');
        return $this->prepareResponse($orderContainer->fetchTaxes($rawBody));
    }


    public function syncOrderStatusAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $orderContainer = $this->di->getObjectManager()->get('\App\Connector\Models\Order\Helper');
        return $this->prepareResponse($orderContainer->syncOrderStatus($rawBody));
    }

    public function returnAction() {
        $rawBody = $this->getRequestData();
        if(!isset($rawBody['operation']) || !isset($rawBody['data'])) {
            return $this->prepareResponse(['success' => false, 'message' => 'Incorrect request format']);
        }

        $orderReturn = $this->di->getObjectManager()->get(\App\Connector\Components\Order\OrderReturn::class);
        $responseData = $orderReturn->return($rawBody['operation'], $rawBody['data']);
        return $this->prepareResponse($responseData);
    }
}
