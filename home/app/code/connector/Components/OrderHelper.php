<?php

namespace App\Connector\Components;

class OrderHelper extends \App\Core\Components\Base
{
    public function initiateOrderSync($data,$userId = false, $shopId = false, $createNotifications = false){
        if(!$userId)
        {
            $userId = $this->di->getUser()->id;
        }

        if (!$shopId) {
            return ['success' => false, 'message' => 'shopId not found!', 'code' => 'failed_to_save_queued_task'];
        }

        if (!isset($data['shop_id'])) {
            $marketplace = $data['marketplace'];
            if ($marketplace == 'ebay') {
                $marketplace = 'EbayV1';
            }

            $shopDetails = $this->di->getObjectManager()->get('\App\\' . ucfirst($marketplace) . '\Models\Shop\Details')::findFirst(["user_id='{$userId}'"]);
            $data['shop_id'] = $shopDetails->id;
        }

        $queuedTask = new \App\Connector\Models\QueuedTasks;
        $queuedTask->set([
            'user_id' => $userId,
            'message' => 'Order syncing process in progress',
            'progress' => 0.00,
            'shop_id' => $shopId
        ]);
        $status = $queuedTask->save();
        $filename = 'order_sync_' . $userId . '_' . time();
        if ($status) {
            $data['feed_id'] = $queuedTask->id;
            $data['filename'] = $filename;
            if ($createNotifications) {
                $data['notification'] = false;
            }

            return $this->processOrderData(['data'=>$data],$userId);
        }

        return ['success' => false, 'message' => 'Internal Server Error', 'code' => 'failed_to_save_queued_task'];
    }

    public function queueAllShopsToSyncOrder(): void{
        $services = $this->di
            ->getObjectManager()
            ->get('App\Connector\Components\Services')
            ->getWithFilter(['type'=>'uploader']);

        foreach($services as $service){
            if($service['marketplace']!='google')
                continue;

            $sourceModel = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($service['marketplace']);
            foreach($sourceModel->getAllShops() as $shop){
                if (!$this->checkOrderSyncing($shop->user_id)) {
                    continue;
                }

                $data = [];
                $data['marketplace'] = $service['marketplace'];
                $data['shop_id'] = $shop->id;
                $data['target'] = 'shopify';
                $handlerData = [
                    'type' => 'full_class',
                    'class_name'=> 'App\Connector\Components\OrderHelper',
                    'method' => 'initiateOrderSyncFromQueue',
                    'queue_name' => 'intitiate_order_sync',
                    'own_weight' => 0,
                    'user_id' => $shop->user_id,
                    'data' => $data
                ];

                if($this->di->getConfig()->get('enable_rabbitmq')) {
                    if ($this->di->getConfig()->get('enable_rabbitmq_internal')) {

                        $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
                        $response = [
                            'success' => true,
                            'code' => 'order_sync_started',
                            'message' => 'Order Sync Process Started',
                            'feed_id' => $helper->createQueue($handlerData['queue_name'], $handlerData)
                        ];
                    } else {

                    }
                }
                else{
                    $this->initiateOrderSyncFromQueue($handlerData);
                }
            }
        }
    }

    public function queueShopsToSyncOrder(): void
    {
        $services = $this->di
            ->getObjectManager()
            ->get('App\Connector\Components\Services')
            ->getWithFilter(['type'=>'uploader']);

        foreach($services as $service) {
            $sourceModel = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($service['marketplace']);
            if ($sourceModel) {
                foreach($sourceModel->getAllShops() as $shop) {
                    $data = [];
                    $data['marketplace'] = $service['marketplace'];
                    $data['shop_id'] = $shop->id;
                    $data['target'] = 'shopify';
                    $handlerData = [
                        'type' => 'full_class',
                        'class_name'=> 'App\Connector\Components\OrderHelper',
                        'method' => 'initiateOrderSyncFromQueue',
                        'queue_name' => 'intitiate_order_sync',
                        'own_weight' => 0,
                        'user_id' => $shop->user_id,
                        'data' => $data
                    ];

                    if($this->di->getConfig()->get('enable_rabbitmq')) {
                        if ($this->di->getConfig()->get('enable_rabbitmq_internal')) {

                            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
                            $response = [
                                'success' => true,
                                'code' => 'order_sync_started',
                                'message' => 'Order Sync Process Started',
                                'feed_id' => $helper->createQueue($handlerData['queue_name'], $handlerData)
                            ];
                        } else {

                        }
                    }
                    else{
                        $this->initiateOrderSyncFromQueue($handlerData);
                    }
                }
            }
        }
    }

    public function checkOrderSyncing($userId = false) {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_service');
        $userService = $collection->findOne([
            'merchant_id' => (string)$userId,
            'code' => 'google_uploader'
        ], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if ($userService &&
            count($userService)) {
            $orderSyncStatus = $this->di->getObjectManager()->get('\App\Google\Components\Helper')->getOrderConfigData($userId);
            if ($orderSyncStatus['disable_order_sync'] == 'yes') {
                return false;
            }

            return true;
        }

        return false;
    }

    public function initiateOrderSyncFromQueue($queueData){
        $this->initiateOrderSync($queueData['data'], $queueData['user_id'], $queueData['data']['shop_id'], true);
        return true;
    }

    public function syncOrderToTarget($queueData){
        $target = $queueData['data']['target'];
        $feedId = $queueData['data']['feed_id'];
        $userId = $queueData['data']['user_id'];
        $filename = isset($queueData['data']['fileninitiateOrderSyncFromQueueame']) ? $queueData['data']['fileninitiateOrderSyncFromQueueame'] : false;
        $merchantId = $queueData['data']['merchant_id'];
        $sourceModel = $this->di->getObjectManager()
            ->get('App\Connector\Components\Connectors')
            ->getConnectorModelByCode($target);
        $orderModel = $this->di->getObjectManager()->create('\App\Connector\Models\Order');
        $orders = $orderModel->getOrders([
            'filter'=>[
                'target_order_id' => '',
                'target' => $target
            ],
            'count'=> 500
        ], true);
        $sandbox = false;
        if(isset($queueData['data']['sandbox']) && $queueData['data']['sandbox'])
            $sandbox = true;

        $data = [
            'feed_id' => $feedId,
            'user_id' => $userId,
            'filename' => $filename
        ];
        if (isset($queueData['data']['notifications']) &&
            !$queueData['data']['notifications']) {
            $data['notifications'] = false;
        }

        $app_configuration = $this->di->getObjectManager()->get('\App\Frontend\Components\Helper')->getConfigurationsApp();
        $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable('order_' . $userId);
        $data_value=json_decode($app_configuration['data'],true);
        if(isset($data_value['order_creation']) && $data_value['order_creation']['value'] === "true") {
            foreach($orders as $value){
                $start_date = new \DateTime($value['imported_at']);
                $current_date= date("Y-m-d H:i:s");
                $since_start = $start_date->diff(new \DateTime($current_date));
                $minutes = $since_start->days * 24 * 60;
                $minutes += $since_start->h * 60;
                $minutes += $since_start->i;
                if($minutes < 31){
                    $collection->findOneAndUpdate([
                        'source_order_id' => $value['source_order_id']
                    ], [
                        '$set' => [
                            'delay_creation' => true,
                        ]
                    ]);
                    $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($feedId, 100/count($orders));
                }else{
                    $this->di->getObjectManager()->get('\App\Google\Models\SourceModel')->setLineItemMetaData([$value], $merchantId, $userId);
                    $sourceModel->massUploadOrders([$value], $sandbox, $data);
                    $collection->findOneAndUpdate([
                        'source_order_id' => $value['source_order_id']
                    ], [
                        '$set' => [
                            'delay_creation' => false,
                        ]
                    ]);
                    $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($feedId, 100/count($orders));
                }
            }

            $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($feedId, 100);
            return true;
        }

        $this->di->getObjectManager()->get('\App\Google\Models\SourceModel')->setLineItemMetaData($orders, $merchantId, $userId);
        $sourceModel->massUploadOrders($orders, $sandbox, $data);
        return true;
    }

    public function cancelOrderToTarget($userId,$data){
        $data['userId']=$userId;
        $target = $data['source']; //google
        $soruceModel = $this->di->getObjectManager()
            ->get('App\Connector\Components\Connectors')
            ->getConnectorModelByCode($target);
        return $soruceModel->cancelOrder($data);
    }

    public function cancelItemToTarget($userId,$data){
        $data['userId']=$userId;
        $target = $data['target']; //google
        $soruceModel = $this->di->getObjectManager()
            ->get('App\Connector\Components\Connectors')
            ->getConnectorModelByCode($target);
        return $soruceModel->cancelItem($data);
    }

    public function fullfillOrderToTarget($data){
        $target = $data['source']; //google
        $soruceModel = $this->di->getObjectManager()
            ->get('App\Connector\Components\Connectors')
            ->getConnectorModelByCode($target);
        return $soruceModel->fullfillOrder($data);
    }


    public function processOrderData($rawData,$userId,$completed = false){
        $data = $rawData['data'];
        if($this->di->getConfig()->get('enable_rabbitmq')) {
            if($completed){
                $rawData['update_parent_progress'] = 100;
                $handlerData = $rawData;
            }else{
                $handlerData = [
                    'type' => 'full_class',
                    'method' => 'initiateOrderSync',
                    'queue_name' => 'order_sync',
                    'own_weight' => 0,
                    'user_id' => $userId,
                    'data' => $data
                ];
                if(!isset($rawData['class_name'])){
                    $connector = $this->di->getObjectManager()
                        ->get('App\Connector\Components\Connectors')
                        ->getConnectorByCode($data['marketplace']);
                    $handlerData['class_name'] = $connector['source_model'];
                }


                if(isset($rawData['parent_id'])){
                    $handlerData['parent_id'] = $rawData['parent_id'];
                } else {
                    $handlerData['parent_id'] = isset($rawData['message_id']) ? $rawData['message_id'] : '';
                }
            }

            if($this->di->getConfig()->get('enable_rabbitmq_internal')){

                $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
                return [
                    'success' => true,
                    'code' => 'order_sync_started',
                    'message' => 'Order Sync Process Started',
                    'feed_id' => $helper->createQueue($handlerData['queue_name'],$handlerData)
                ];
            }

            $request = $this->di->get('\App\Core\Components\Helper')
                ->curlRequest($this->di->getConfig()->rabbitmq_url . '/rmq/queue/create', $handlerData, false);
            $responseData = json_decode($request['message'], true);
        }
        else{
            if(!$completed){
                $sourceModel = $this->di->getObjectManager()
                    ->get('App\Connector\Components\Connectors')
                    ->getConnectorModelByCode($data['marketplace']);
                $sourceModel->initiateOrderSync($rawData,$userId);
            }

            return [
                'success' => true,
                'code' => 'order_sync_done',
                'message' => 'Order Sync Process Completed'
            ];

        }
    }


    public function getOrderInRightFormat($userId = false, $data = '')
    {
        $data['merchant_id'] = $userId;
        $orderData = [];
        if ($data['source_order_id'] === null || $data['source_order_id'] === '') {
            unset($data['source_order_id']);
        }

        $warehouseModel = $this->di->getObjectManager()->get('\App\Connector\Models\Warehouse');
        $orderTargetDetails = $warehouseModel::findFirst(
            [
                "id='{$data["products"][0]["selected_warehouse"]["id"]}'",
                'column' => 'order_target, order_target_shop'
            ]
        )->toArray();
        $data['order_target'] = $orderTargetDetails['order_target'];
        $data['order_target_shop'] = $orderTargetDetails['order_target_shop'];
        $shippingAmount = $this->getShippingAmount($data['fulfillmentService']);
        foreach ($data as $key => $value) {
            switch ($key) {
                case 'fulfillmentService':
                case 'shipping_method':
                    continue;
                case 'products':
                    $line_items = [];
                    $count = 0;
                    foreach ($value as $lineItemValue) {
                        if ($lineItemValue['quantity'] > 0) {
                            $line_items[$count]['shipping_amount'] = $shippingAmount;
                            $line_items[$count]['sku'] = $lineItemValue['sku'];
                            $line_items[$count]['qty'] = $lineItemValue['quantity'];
                            $line_items[$count]['total_price'] = $lineItemValue['price'] * $lineItemValue['quantity'];
                            $line_items[$count]['price'] = $lineItemValue['price'];
                            $line_items[$count]['subtotal_price'] = $lineItemValue['subtotal_price'];
                            $line_items[$count]['total_price'] = $lineItemValue['total_price'];
                            $line_items[$count]['total_discounts'] = $lineItemValue['total_discounts'];
                            $line_items[$count]['total_tax'] = $lineItemValue['total_tax'];
                            $line_items[$count]['discount_codes'] = is_array($lineItemValue['discount_codes']) ? json_encode($lineItemValue['discount_codes'], true) : '{}';
                            $line_items[$count]['total_price_usd'] = $lineItemValue['subtotal_price'];
                            $line_items[$count]['title'] = $lineItemValue['title'];
                            $line_items[$count]['total_tax'] = $this->individualItemTax($lineItemValue['tax_lines']);
                            $line_items[$count]['taxes_included'] = false;
                            $line_items[$count]['currency'] = $data['currency'];
                            $line_items[$count]['fulfillment_service'] = $data['fulfillmentService'];
                            $line_items[$count]['warehouse'] = $lineItemValue['selected_warehouse']['id'];
                            $line_items[$count]['weight'] = $lineItemValue['weight'];
                            $line_items[$count]['product_id'] = $lineItemValue['id'];
                            $line_items[$count]['tax_lines'] = is_array($lineItemValue['tax_lines']) ? json_encode($lineItemValue['tax_lines'], true) : '{}';
                            $line_items[$count]['fulfillment_status'] = 0;
                            $count++;
                        }
                    }

                    $orderData['line_items'] = $line_items;
                    break;
                default:
                    $orderData[$key] = $value;
                    break;
            }
        }

        switch ($orderData['shipment_type']) {
            case 'itemwise':
                $orderData['total_shipment_amount'] = $this->getTotalShippingAmount($orderData['line_items']);
                break;

            case 'global':
                $orderData['total_shipment_amount'] = $orderData['line_items'][0]['shipping_amount'];
                break;
        }

        $orderData['shipping_amount_paid'] = 0;
        unset($orderData['shipment_type']);

        return $orderData;
    }

    public function getShippingAmount($methodCode)
    {
        $methodDetails = $this->di->getObjectManager()->get('App\Shipment\Components\ShippingMethodHelper')->getShippingMethodDetails($methodCode);
        $shippingPrice = $methodDetails['price'];
        if (isset($methodDetails['tax_lines']) && count($methodDetails['tax_lines']) > 0) {
            foreach ($methodDetails['tax_lines'] as $value) {
                $shippingPrice += $value['price'];
            }
        }

        return $shippingPrice;
    }

    public function getTotalShippingAmount($line_items)
    {
        $totalShippingAmount = 0;
        foreach ($line_items as $value) {
            $totalShippingAmount += $value['shipping_amount'];
        }

        return $totalShippingAmount;
    }

    public function individualItemTax($taxes)
    {
        $totalTax = 0;
        foreach ($taxes as $value) {
            $totalTax += $value['price'];
        }

        return $totalTax;
    }
}
