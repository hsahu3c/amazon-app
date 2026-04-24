<?php


namespace App\Amazon\Components\Webhook;

use App\Amazon\Components\Common\Helper;
use DateTime;
use DateTimeZone;
use App\Core\Components\Base;

class OrderNotifications extends Base
{
    public $hasCancelled = false;

    public $cancelled_orders = [];

    public function getNotificationsData($data): void
    {
        $this->saveInLog($data, 'order-status-change/order.log', ' ********* Start **********');
        foreach ($data as $notificationData) {
            if (isset($notificationData['data'])) {
                $notificationData = $notificationData['data'];
            }

            if (isset($notificationData['NotificationType']) && $notificationData['NotificationType'] === 'MFN_ORDER_STATUS_CHANGE') {
                if (isset($notificationData['Payload']) && isset($notificationData['Payload']['MFNOrderStatusChangeNotification'])) {
                    $status = $notificationData['Payload']['MFNOrderStatusChangeNotification']['OrderStatus'];
                    switch ($status) {
                        case 'CANCELED':
                            $this->hasCancelled = true;
                            array_push($this->cancelled_orders, $notificationData['Payload']['MFNOrderStatusChangeNotification']);
                            break;
                        case 'Canceled':
                            $this->hasCancelled = true;
                            array_push($this->cancelled_orders, $notificationData['Payload']['MFNOrderStatusChangeNotification']);
                            break;
                        case 'canceled':
                            $this->hasCancelled = true;
                            array_push($this->cancelled_orders, $notificationData['Payload']['MFNOrderStatusChangeNotification']);
                            break;
                    }
                }
            }
        }

        if ($this->hasCancelled) {
            $this->saveInLog(['cancel data' => $this->cancelled_orders], 'order-status-change/order.log', '***** Order Cancellation Begin ***********');
            $orderCancel = $this->di->getObjectManager()->get("\App\Connector\Components\Order\OrderCancel");
            $orders = $this->getOrdersForCancellation($this->cancelled_orders);
            if (empty($orders)) {
                $this->saveInLog(['Order cancel notifications data => ' => $this->cancelled_orders], 'order-status-change/order.log', 'Orders not found for cancellation');
            } else {
                $this->saveInLog($orders, 'order-status-change/order.log', 'Orders available for cancellation');
                foreach ($orders as $order) {
                    $orderCancel->cancel($order);
                }
            }

            $this->saveInLog(['success' => true], 'order-status-change/order.log', ' ********** End ********** ');
        }
    }

    public function getOrdersForCancellation($cancelled_orders)
    {
        $orders = [];
        $orderCancel = $this->di->getObjectManager()->get("\App\Connector\Components\Order\OrderCancel");
        foreach ($cancelled_orders as $order) {
            if (isset($order['AmazonOrderId'])) {
                $params = ['$and' => [['marketplace_reference_id' => $order['AmazonOrderId']], ['$or' => [['object_type' => 'source_order'], ['object_type' => 'target_order']]]]];
                $sourceOrder = $this->findOneFromDb($params);
                if (!empty($sourceOrder)) {
                    $home_shop_id = $sourceOrder['marketplace_shop_id'];
                    $user_id = $sourceOrder['user_id'];
                    $shop = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getShop($home_shop_id, $user_id);
                    if (empty($shop)) {
                        $this->saveInLog(['Shop not found for amazon order ' => $order], 'order-status-change/order.log', 'Shop Not Found');
                    }

                    // for testing purposes only
                    // $response = $this->getOrder($order['AmazonOrderId'], $shop['remote_shop_id'], $home_shop_id);
                    $params = ['shop_id' => $shop['remote_shop_id'], 'home_shop_id' => $home_shop_id,  'amazon_order_id' => $order['AmazonOrderId']];
                    $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                    $response = $commonHelper->sendRequestToAmazon('order', $params, 'GET');
                    if ($response['success']) {
                        array_push($orders, $response);
                    } else {
                        $this->saveInLog($order, 'order-status-change/order.log', 'Remote response not found ');
                    }
                } else {
                    $this->saveInLog($order, 'order-status-change/order.log', 'Source order not available for amazon order');
                }
            }
        }

        return $orders;
    }

    /**
     *
     * to find data according to query
     */
    public function findOneFromDb($params): array
    {
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('order_container');
        $data = $collection->findOne($params, $options);
        if (empty($data)) {
            return [];
        }

        return $data;
    }

    public function saveInLog($data, $file, $msg = 'Message '): void
    {
        $time = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
        $path = __FILE__;
        $this->di->getLog()->logContent($time.PHP_EOL.$path.PHP_EOL.$msg .PHP_EOL. print_r($data, true), 'info', $file);
    }

    //for testing purposes
    public function getOrder($id, $shop_id, $home_shop_id)
    {
        $data1 = '{
            "success": true,
            "data": {
                "_url": "/webapi/rest/v1/order",
                "shop_id": "8",
                "home_shop_id": "28",
                "amazon_order_id": "984-8925819-1146001"
            },
            "orders": [
                {
                    "data": {
                        "BuyerInfo": [],
                        "AmazonOrderId": "984-8925819-1146001",
                        "EarliestShipDate": "2022-09-17T06:59:59Z",
                        "SalesChannel": "Amazon.com",
                        "OrderStatus": "Canceled",
                        "NumberOfItemsShipped": 0,
                        "OrderType": "StandardOrder",
                        "IsPremiumOrder": false,
                        "IsPrime": false,
                        "FulfillmentChannel": "AFN",
                        "NumberOfItemsUnshipped": 0,
                        "HasRegulatedItems": false,
                        "IsReplacementOrder": false,
                        "IsSoldByAB": false,
                        "LatestShipDate": "2022-09-17T06:59:59Z",
                        "ShipServiceLevel": "Expedited",
                        "IsISPU": false,
                        "MarketplaceId": "ATVPDKIKX0DER",
                        "PurchaseDate": "2022-09-16T22:17:44Z",
                        "IsAccessPointOrder": false,
                        "SellerOrderId": "111-9074480-5777822",
                        "PaymentMethod": "Other",
                        "IsBusinessOrder": false,
                        "PaymentMethodDetails": [
                            "Standard"
                        ],
                        "IsGlobalExpressEnabled": false,
                        "LastUpdateDate": "2022-09-18T09:52:03Z",
                        "ShipmentServiceLevelCategory": "Expedited"
                    },
                    "items": [
                        {
                            "ProductInfo": {
                                "NumberOfItems": "1"
                            },
                            "IsGift": "false",
                            "BuyerInfo": [],
                            "QuantityShipped": 0,
                            "IsTransparency": false,
                            "QuantityOrdered": 0,
                            "ASIN": "B00VDEK0I4",
                            "SellerSKU": "100",
                            "Title": "Halloween Themed Decorative Felt Shapes - 24pc",
                            "OrderItemId": "40607937547002"
                        }
                    ],
                    "region": "NA",
                    "country": "US",
                    "url": "https://sellercentral.amazon.com/orders-v3/order/111-9074480-5777822",
                    "shop_id": "28"
                }
            ],
            "spapi": true,
            "start_time": "09-22-2022 06:41:44",
            "end_time": "09-22-2022 06:41:45",
            "execution_time": 0.5602819919586182,
            "ip": "103.97.184.106"
        }';

        $data2 = '{
            "success": true,
            "data": {
                "_url": "/webapi/rest/v1/order",
                "shop_id": "8",
                "home_shop_id": "28",
                "amazon_order_id": "832-7405014-3646780"
            },
            "orders": [
                {
                    "data": {
                        "BuyerInfo": [],
                        "AmazonOrderId": "832-7405014-3646780",
                        "EarliestShipDate": "2022-09-17T06:59:59Z",
                        "SalesChannel": "Amazon.com",
                        "OrderStatus": "Canceled",
                        "NumberOfItemsShipped": 0,
                        "OrderType": "StandardOrder",
                        "IsPremiumOrder": false,
                        "IsPrime": false,
                        "FulfillmentChannel": "AFN",
                        "NumberOfItemsUnshipped": 0,
                        "HasRegulatedItems": false,
                        "IsReplacementOrder": false,
                        "IsSoldByAB": false,
                        "LatestShipDate": "2022-09-17T06:59:59Z",
                        "ShipServiceLevel": "Expedited",
                        "IsISPU": false,
                        "MarketplaceId": "ATVPDKIKX0DER",
                        "PurchaseDate": "2022-09-16T22:17:44Z",
                        "IsAccessPointOrder": false,
                        "SellerOrderId": "111-9074480-5777822",
                        "PaymentMethod": "Other",
                        "IsBusinessOrder": false,
                        "PaymentMethodDetails": [
                            "Standard"
                        ],
                        "IsGlobalExpressEnabled": false,
                        "LastUpdateDate": "2022-09-18T09:52:03Z",
                        "ShipmentServiceLevelCategory": "Expedited"
                    },
                    "items": [
                        {
                            "ProductInfo": {
                                "NumberOfItems": "1"
                            },
                            "IsGift": "false",
                            "BuyerInfo": [],
                            "QuantityShipped": 0,
                            "IsTransparency": false,
                            "QuantityOrdered": 0,
                            "ASIN": "B00VDEK0I4",
                            "SellerSKU": "101",
                            "Title": "Halloween Themed Decorative Felt Shapes - 24pc",
                            "OrderItemId": "40607937547002"
                        }
                    ],
                    "region": "NA",
                    "country": "US",
                    "url": "https://sellercentral.amazon.com/orders-v3/order/111-9074480-5777822",
                    "shop_id": "28"
                }
            ],
            "spapi": true,
            "start_time": "09-22-2022 06:41:44",
            "end_time": "09-22-2022 06:41:45",
            "execution_time": 0.5602819919586182,
            "ip": "103.97.184.106"
        }';

        $data3 = '{
            "success": true,
            "data": {
                "_url": "/webapi/rest/v1/order",
                "shop_id": "8",
                "home_shop_id": "28",
                "amazon_order_id": "410-3703604-7896077"
            },
            "orders": [
                {
                    "data": {
                        "BuyerInfo": [],
                        "AmazonOrderId": "410-3703604-7896077",
                        "EarliestShipDate": "2022-09-17T06:59:59Z",
                        "SalesChannel": "Amazon.com",
                        "OrderStatus": "Canceled",
                        "NumberOfItemsShipped": 0,
                        "OrderType": "StandardOrder",
                        "IsPremiumOrder": false,
                        "IsPrime": false,
                        "FulfillmentChannel": "AFN",
                        "NumberOfItemsUnshipped": 0,
                        "HasRegulatedItems": false,
                        "IsReplacementOrder": false,
                        "IsSoldByAB": false,
                        "LatestShipDate": "2022-09-17T06:59:59Z",
                        "ShipServiceLevel": "Expedited",
                        "IsISPU": false,
                        "MarketplaceId": "ATVPDKIKX0DER",
                        "PurchaseDate": "2022-09-16T22:17:44Z",
                        "IsAccessPointOrder": false,
                        "SellerOrderId": "111-9074480-5777822",
                        "PaymentMethod": "Other",
                        "IsBusinessOrder": false,
                        "PaymentMethodDetails": [
                            "Standard"
                        ],
                        "IsGlobalExpressEnabled": false,
                        "LastUpdateDate": "2022-09-18T09:52:03Z",
                        "ShipmentServiceLevelCategory": "Expedited"
                    },
                    "items": [
                        {
                            "ProductInfo": {
                                "NumberOfItems": "1"
                            },
                            "IsGift": "false",
                            "BuyerInfo": [],
                            "QuantityShipped": 0,
                            "IsTransparency": false,
                            "QuantityOrdered": 0,
                            "ASIN": "B00VDEK0I4",
                            "SellerSKU": "103",
                            "Title": "Halloween Themed Decorative Felt Shapes - 24pc",
                            "OrderItemId": "40607937547002"
                        },
                        {
                            "ProductInfo": {
                                "NumberOfItems": "1"
                            },
                            "IsGift": "false",
                            "BuyerInfo": [],
                            "QuantityShipped": 0,
                            "IsTransparency": false,
                            "QuantityOrdered": 0,
                            "ASIN": "B00VDEK0I4",
                            "SellerSKU": "104",
                            "Title": "Halloween Themed Decorative Felt Shapes - 24pc",
                            "OrderItemId": "40607937547002"
                        }
                    ],
                    "region": "NA",
                    "country": "US",
                    "url": "https://sellercentral.amazon.com/orders-v3/order/111-9074480-5777822",
                    "shop_id": "28"
                }
            ],
            "spapi": true,
            "start_time": "09-22-2022 06:41:44",
            "end_time": "09-22-2022 06:41:45",
            "execution_time": 0.5602819919586182,
            "ip": "103.97.184.106"
        }';

        $data = [json_decode($data1, 1), json_decode($data2, 1), json_decode($data3, 1)];
        foreach ($data as $value) {
            if (($value['data']['amazon_order_id'] == $id) && ($value['data']['shop_id'] == $shop_id) && ($value['data']['home_shop_id'] == $home_shop_id)) {
                return $value;
            }
        }
    }
}
