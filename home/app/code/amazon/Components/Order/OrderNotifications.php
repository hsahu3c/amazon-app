<?php


namespace App\Amazon\Components\Order;
use Exception;
use App\Amazon\Components\Common\Helper;
use DateTime;
use DateTimeZone;
use App\Connector\Contracts\Sales\Order\CancelInterface;
use App\Connector\Components\Dynamo;

use App\Core\Components\Base;

class OrderNotifications extends Base
{
    private string $logFile = 'order/OrderNotifications/process.log';

    public function getNotificationsData($data)
    {
        $order_notification_cancel_data = [];
        $notificationSummary = [];
        $this->logFile = 'order/OrderNotifications/' . $this->di->getUser()->id . '/' . date('d-m-y') . '/process.log';
        if (isset($data['data'])) {
            $notificationData = $data['data'];
        } else {
            $notificationData = $data;
        }

        if (isset($notificationData['Payload']['MFNOrderStatusChangeNotification'])) {
            $notificationData = $notificationData['Payload']['MFNOrderStatusChangeNotification'];
        } elseif (isset($notificationData['MFNOrderStatusChangeNotification'])) {
            $notificationData = $notificationData['MFNOrderStatusChangeNotification'];
        } elseif (isset($notificationData['OrderChangeNotification'])
            && isset($notificationData['OrderChangeNotification']['Summary'])) {
            $notificationData = $notificationData['OrderChangeNotification'];
            $notificationSummary = $notificationData['Summary'];
        }

        if (!empty($notificationSummary)) {
            $this->updateOrderStatus($notificationData, $notificationSummary);
            $fulfillmentType = $notificationSummary['FulfillmentType'];
            $status = strtolower((string) $notificationSummary['OrderStatus']);
        } else {
            $this->updateOrderStatus($notificationData);
            $fulfillmentType = $notificationData['FulfillmentChannel'];
            $status = strtolower((string) $notificationData['OrderStatus']);
        }

        $this->di->getLog()->logContent("Notifications Process Start: ". json_encode(
            [
                'order_id' => $notificationData['AmazonOrderId'] ?? '',
                'order_status' => $status
            ]
        ), 'info', $this->logFile);
        if ($fulfillmentType == 'AFN') {
            if($status == 'shipped') {
                $message = 'FBA fetching process initiated...';
                $this->di->getLog()->logContent($message, 'info', $this->logFile);
                $this->processOrderData($data);
            }

            return ['success' => true, 'message' => $message ?? 'FBA order found'];
        }

        //for now we receive 1 notification at a time but this can be more than 1 also in future
        switch ($status) {
            case 'canceled':
                array_push($order_notification_cancel_data, $notificationData);
                break;
            case 'cancelled':
                array_push($order_notification_cancel_data, $notificationData);
                break;
            case 'unshipped':
                $this->processOrderData($data);
                break;
            case 'shipped':
                $this->handleShippedOrder($data);
                break;
            default:
                //silent case
        }

        if (!empty($order_notification_cancel_data)) {
            $this->di->getLog()->logContent('Found cancel notifications data: '
                . json_encode($order_notification_cancel_data), 'info', $this->logFile);
            $this->proceedCancelProcess($order_notification_cancel_data);
        }
    }

    /**
     * enableOrderFetch function
     * Enable Order Fetching
     * @param array $sqsData
     * @return array
     */
    public function enableOrderFetch($sqsData)
    {
        $logFile = 'order/OrderNotifications/' . $this->di->getUser()->id .
            '/' . date('d-m-y') . '/process.log';
        $this->di->getLog()->logContent(
            '--------------Initiating Order Import Process--------------',
            'info',
            $logFile
        );
        $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
        if(!$this->checkUserSyncedToDynamo($sqsData['shop_id'])) {
            $this->di->getLog()->logContent(
                'User not active or not synced to dynamoDB, Data: ' . json_encode([
                    'user_id' => $userId,
                    'shop_id' => $sqsData['shop_id']
                ]),
                'info',
                $this->logFile
            );
            return ['success' => false, 'message' => 'User not active or not synced to dynamoDB'];
        }

        $orderId = (string)($sqsData['data']['OrderChangeNotification']['AmazonOrderId'] ?? '');
        $this->di->getLog()->logContent('OrderId: ' . $orderId, 'info', $logFile);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $orderCollection = $mongo->getCollectionForTable('order_container');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $checkDuplicateOrder = $orderCollection->findOne(
            [
                'user_id' => $userId,
                'object_type' => ['$in' => ['source_order', 'target_order']],
                'marketplace' => $sqsData['marketplace'],
                'marketplace_shop_id' => $sqsData['shop_id'],
                'marketplace_reference_id' => $orderId
            ],
            $arrayParams
        );
        if (empty($checkDuplicateOrder)) {
            $notificationData = $sqsData['data']['OrderChangeNotification'];
            try {
                $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
                $dynamoClientObj = $dynamoObj->getDetails();
                $remoteShopId = $sqsData['remote_shop_id'];
                if ($notificationData['Summary']['FulfillmentType'] == 'MFN') {
                    $this->di->getLog()->logContent('Order Type: MFN', 'info', $logFile);
                    $dynamoClientObj->updateItem([
                        'TableName' => 'user_details',
                        'Key' => [
                            'id' => ['S' => (string)$remoteShopId]
                        ],
                        'ExpressionAttributeValues' =>  [
                            ':value' => ['N' => '1']
                        ],
                        'UpdateExpression' => 'SET order_fetch=:value'

                    ]);
                    $message = 'Order Fetch Enabled';
                    $this->di->getLog()->logContent($message, 'info', $logFile);
                    return ['success' => true, 'message' => $message];
                }
                if ($notificationData['Summary']['FulfillmentType'] == 'AFN') {
                    $this->di->getLog()->logContent('Order Type: AFN', 'info', $logFile);
                    $dynamoClientObj->updateItem([
                        'TableName' => 'user_details',
                        'Key' => [
                            'id' => ['S' => (string)$remoteShopId]
                        ],
                        'ExpressionAttributeValues' =>  [
                            ':value' => ['S' => '1']
                        ],
                        'UpdateExpression' => 'SET fba_fetch=:value'

                    ]);
                    $message = 'FBA Fetch Enabled';
                    $this->di->getLog()->logContent($message, 'info', $logFile);
                    return ['success' => true, 'message' => $message];
                }
            } catch (Exception $e) {
                $this->di->getLog()->logContent('Exception from enableOrderFetch => '
                    . json_encode($e->getMessage()), 'emergency', 'exception.log');
            }
        } else {
            $message = 'Duplicate Order Found, OrderId: ';
            $this->di->getLog()->logContent($message . "OrderId : {$orderId}", 'info', $logFile);
        }

        return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
    }

    /**
     * processOrderData function
     * Processing Order from queue
     * with a delay time of 3min
     * @param array $orderData
     * @return array
     */
    public function processOrderData($orderData)
    {

        if ($this->checkPlanExists()) {
            $userId = $orderData['user_id'] ?? $this->di->getUser()->id;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $marketplace = $orderData['marketplace'] ?? 'amazon';
            $shops = $this->di->getUser()->shops;
            if (empty($shops)) {
                $this->di->getLog()->logContent(
                    'Shop not found for userId: ' . json_encode($userId),
                    'info',
                    $this->logFile
                );
                return ['success' => false, 'message' => 'Shop not found'];
            }

            $shopData = [];
            $settingEnabledShops = [];
            $notificationData = $orderData['data']['OrderChangeNotification'];
            foreach ($shops as $shop) {
                if ($shop['marketplace'] == $marketplace &&
                    !empty($shop['warehouses']) && $shop['warehouses'][0]['marketplace_id']
                    == $notificationData['Summary']['MarketplaceId']
                    && $shop['warehouses'][0]['seller_id']
                    == $notificationData['SellerId'] && isset($shop['register_for_order_sync'])
                    && $shop['register_for_order_sync']) {
                    $shopData = $shop;
                    break;
                }
            }

            if (!empty($shopData)) {
                $shopId = $shopData['_id'];
                if ($notificationData['Summary']['FulfillmentType'] == 'AFN') {
                    if (!$this->di->getCache()->get('fba_users')) {
                        $config = $mongo->getCollectionForTable('config');
                        $fbaSetting = $config->find([
                            'group_code' => 'order',
                            'key' => 'fbo_order_fetch',
                            'value' => true,
                            '$or' => [
                                ['target' => $marketplace],
                                ['source' => $marketplace]
                            ]

                        ], $arrayParams)->toArray();
                        if (!empty($fbaSetting)) {
                            $key = 'target_shop_id';
                            if ($fbaSetting[0]['source'] == $marketplace) {
                                $key = 'source_shop_id';
                            }

                            $shopIds = array_column($fbaSetting, $key);
                            foreach ($shopIds as $id) {
                                $settingEnabledShops[$id] = true;
                            }

                            $this->di->getCache()->set("fba_users", $settingEnabledShops);
                        }
                    } else {
                        $settingEnabledShops = $this->di->getCache()->get('fba_users') ?? [];
                    }

                    if (is_array($settingEnabledShops) && !array_key_exists($shopId, $settingEnabledShops)) {
                        $message = 'Settings disabled';
                        $this->di->getLog()->logContent($message, 'info', $this->logFile);
                        return ['success' => false, 'message' => $message];
                    }
                } elseif ($notificationData['Summary']['FulfillmentType'] == 'MFN' && $this->checkPriorityUserShop($shopId)) {
                    $message = 'Priority user shop found terminating process for shopId: ' . $shopId;
                    $this->di->getLog()->logContent($message, 'info', $this->logFile);
                    return ['success' => false, 'message' => $message];
                }

                $orderId = (string)$orderData['data']['OrderChangeNotification']['AmazonOrderId'] ?? '';
                $orderCollection = $mongo->getCollectionForTable('order_container');
                $checkDuplicateOrder = $orderCollection->findOne(
                    [
                        'user_id' => $userId,
                        'object_type' => ['$in' => ['source_order', 'target_order']],
                        'marketplace' => $marketplace,
                        'marketplace_shop_id' => $shopId,
                        'marketplace_reference_id' => $orderId
                    ],
                    $arrayParams
                );
                if (empty($checkDuplicateOrder)) {
                    $handlerData = [
                        'type' => 'full_class',
                        'method' => 'enableOrderFetch',
                        'class_name' => \App\Amazon\Components\Order\OrderNotifications::class,
                        'queue_name' => 'process_order_notification',
                        'user_id' => $userId,
                        'data' => $orderData['data'],
                        'marketplace' => $marketplace,
                        'shop_id' => $shopId,
                        'remote_shop_id' => $shopData['remote_shop_id'],
                        'delay' => 180
                    ];
                    $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                    $sqsHelper->pushMessage($handlerData);
                    $message = 'Processing Data From Queue...';
                    $this->di->getLog()->logContent($message, 'info', $this->logFile);
                    return ['success' => true, 'message' => $message];
                }
                $message = 'Duplicate Order Found, OrderId: ';
                $this->di->getLog()->logContent($message . $orderId, 'info', $this->logFile);
            } else {
                $message = 'MarketplaceId and SellerId not matched or register_for_order_sync is not set or false';
                $this->di->getLog()->logContent($message, 'info', $this->logFile);
            }
        } else {
            $message = 'Inactive Plan';
            $this->di->getLog()->logContent($message, 'info', $this->logFile);
        }

        $this->di->getLog()->logContent('-------------- Process End --------------', 'info', $this->logFile);
        return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
    }

    /**
     * checkPlanExists function
     * Checking plan is active or not
     * @return bool
     */
    public function checkPlanExists()
    {
        $class = '\App\Plan\Models\Plan';
        if (class_exists($class) && method_exists($class, 'isSyncAllowed')) {
            $planChecker = $this->di->getObjectManager()->get($class);
            if (!$planChecker->isSyncAllowed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * checkUserSyncToDynamo function
     * Checking if user is active and entry exists on Dynamo or not
     * @return bool
     */
    public function checkUserSyncedToDynamo($shopId) {
        $shops = $this->di->getUser()->shops ?? [];
        if(!empty($shops)) {
            foreach($shops as $shop) {
                if($shop['_id'] == $shopId
                && $shop['apps'][0]['app_status'] == 'active'
                && isset($shop['register_for_order_sync'])
                && $shop['register_for_order_sync']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * checkPriorityUserShop function
     * Checks if the user shop belongs to a priority shop
     * @return bool
     */
    private function checkPriorityUserShop($shopId)
    {
        $shops = $this->di->getConfig()->get('priority_order_sync_user_shops')
            ? $this->di->getConfig()->get('priority_order_sync_user_shops')->toArray() : [];
        if (!empty($shops)) {
            if (in_array($shopId, $shops)) {
                return true;
            }
        }

        return false;
    }

    /**
     * handleShippedOrder function
     * Performing action when order status is Shipped on Amazon
     * @param array $notificationData
     * @return array
     */
    public function handleShippedOrder($notificationData)
    {
        if (!empty($notificationData['data']['OrderChangeNotification'])) {
            $orderId = (string)$notificationData['data']['OrderChangeNotification']['AmazonOrderId'];
            $orderData = $this->getOrderData($notificationData['user_id'], $orderId);
            if (!empty($orderData)) {
                if ($this->validateReverseShipmentUser($notificationData['user_id'])) {
                    $response = $this->syncShipmentDetails($orderData);
                    if (!isset($response['success']) || (isset($response['success']) && !$response['success'])) {
                        $this->di->getLog()->logContent('Unable to sync shipment details, Response: ' . json_encode($response), 'info', $this->logFile);
                    }
                }
                if (!empty($orderData['shipment_error'])) {
                    $this->updateShippingStatusAndUnsetShipmentError($notificationData['user_id'], $orderId, $orderData['shipment_error']);
                }

                // VAT invoice sync for business orders when enabled
                $this->initiateVatInvoiceReportIfApplicable($notificationData, $orderData);
                return ['success' => true, 'message' => 'Process Completed'];
            } else {
                $message = 'Unable to find order data';
            }
        } else {
            $message = 'Empty notification data';
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    /**
     * validateReverseShipmentUser function
     * Validating reverse shipment enabled for user or not
     * @param string $userId
     * @return bool
     */
    private function validateReverseShipmentUser($userId)
    {
        $users = $this->di->getConfig()->get('reverse_shipment_users');
        if (!empty($users)) {
            $users = $users->toArray();
            if (in_array($userId, $users)) {
                return true;
            }
        }

        return false;
    }

    /**
     * syncShipmentDetails function
     * Syncing shipment details from Amazon to source or target
     * @param array $orderData
     * @return array
     */
    private function syncShipmentDetails($orderData)
    {
        if (isset($orderData['targets'][0]['order_id'])) {
            $targets = $orderData['targets'][0];
            $path = '\Components\Order\Shipment';
            $method = 'syncShipmentDetails';
            $class = $this->di->getObjectManager()->get(Helper::class)
                ->checkClassAndMethodExists($targets['marketplace'], $path, $method);
            if (!empty($class)) {
                $targetOrderId = $targets['order_id'];
                return $this->di->getObjectManager()->get($class)->$method($targetOrderId);
            }
            $message = "class or method not found";
        } else {
            $message = 'Unable to find order_id in targets';
        }

        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    /**
     * getOrderData function
     * To get source or target order data
     * @param string $userId (mandatory)
     * @param string $orderId (mandatory)
     * @return array
     */
    private function getOrderData($userId, $orderId)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $orderCollection = $mongo->getCollectionForTable('order_container');
        return $orderCollection->findOne(
            [
                'user_id' => $userId,
                'object_type' => ['$in' => ['source_order', 'target_order']],
                'marketplace' => 'amazon',
                'marketplace_reference_id' => $orderId
            ],
            [
                "projection" => [
                    'marketplace_reference_id' => 1,
                    'marketplace_shop_id' => 1,
                    'source_created_at' => 1,
                    'is_business_order' => 1,
                    'targets' => 1,
                    'shipment_error' => 1
                ],
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ]
        );
    }

    /**
     * Initiate a VAT invoice data report request for a shipped Amazon order (business orders only),
     * and store correlation so the reportProcessingFinishedNotification webhook can continue the flow.
     */
    private function initiateVatInvoiceReportIfApplicable(array $notificationData, array $orderData): void
    {
        try {
            $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Report\VatInvoiceDataReportRequester::class)
                ->requestForAmazonOrder([
                    'user_id' => (string)($notificationData['user_id'] ?? ''),
                    'marketplace_reference_id' => (string)($orderData['marketplace_reference_id'] ?? ''),
                    'marketplace_shop_id' => (string)($orderData['marketplace_shop_id'] ?? ''),
                    'source_created_at' => $orderData['source_created_at'] ?? null,
                    'is_business_order' => $orderData['is_business_order'] ?? null,
                    'targets' => $orderData['targets'] ?? [],
                ]);
        } catch (\Exception $e) {
            $this->di->getLog()->logContent(
                'VAT sync exception: ' . $e->getMessage(),
                'info',
                $this->logFile
            );
        }
    }

    /**
     * updateShippingStatusAndUnsetShipmentError function
     * Updating shipping_status to Shipped
     * @param string $userId (mandatory)
     * @param string $orderId (mandatory)
     * @return null
     */
    private function updateShippingStatusAndUnsetShipmentError($userId, $orderId, $shipmentError = [])
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $orderCollection = $mongo->getCollectionForTable('order_container');
        $orderCollection->updateOne(
            [
                'user_id' => $userId,
                'object_type' => ['$in' => ['source_order', 'target_order']],
                'marketplace' => 'amazon',
                'marketplace_reference_id' => $orderId
            ],
            [
                '$set' => ['shipping_status' => 'Shipped', 'shipment_error_history' => $shipmentError, 'order_shipped_at' => date('c')],
                '$unset' => ['shipment_error' => '']
            ]
        );
    }

    /**
     * updateOrderStatus function
     * To update the marketplace status 
     * @param array $notificationData
     * @param array $notificationSummary
    */
    public function updateOrderStatus($notificationData, $notificationSummary = []): void
    {
        if (!empty($notificationSummary)) {
            $status = $notificationSummary['OrderStatus'];
        } else {
            $status = $notificationData['OrderStatus'];
        }

        $validStatuses = ['partiallyshipped', 'shipped', 'invoiceunconfirmed', 'unfulfillable', 'canceled'];
        if (!empty($notificationData['AmazonOrderId']) && in_array(strtolower((string) $status), $validStatuses)) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('order_container');
            $collection->updateOne(['user_id' => $this->di->getUser()->id, 'object_type' => 'source_order', 'marketplace_reference_id' => $notificationData['AmazonOrderId']], ['$set' => ['marketplace_status' => $status]]);
        }
    }

    /**
     * function to process cancel notifications
     *
     * @param array $cancelled_orders
     * @return void
     */
    public function proceedCancelProcess($cancelled_orders)
    {
        try {
            if($this->logFile == 'order/OrderNotifications/process.log') {
                $this->logFile = 'order/OrderNotifications/other-process/'.date('d-m-Y').'/process.log';
            }

            $this->di->getLog()->logContent('Initiating Cancel Process: ', 'info', $this->logFile);
            $orders = $this->getOrdersForCancellation($cancelled_orders);
            $time = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
            if (empty($orders)) {
                $this->di->getLog()->logContent('No orders Found for cancellation'.PHP_EOL.' ---------------- Process end ------------'.PHP_EOL.$time.PHP_EOL, 'info', $this->logFile);
                return [
                    'success' => false,
                    'message' => 'No orders Found'
                ];
            }
            $orderCancel = $this->di->getObjectManager()->get("\App\Connector\Components\Order\OrderCancel");
            $this->di->getLog()->logContent('Order received for cancellation', 'info', $this->logFile);
            $this->di->getLog()->logContent($time.PHP_EOL.'Received order cancel data = ' . json_encode($orders), 'info', $this->logFile);
            foreach($orders as $order) {
                $response = $orderCancel->cancel($order);
                $this->di->getLog()->logContent($time.PHP_EOL.'cancellation response = ' . json_encode($response), 'info', $this->logFile);
            }
            $this->di->getLog()->logContent($time.PHP_EOL.'-------------- Process End --------------', 'info', $this->logFile);
            return [
                'success' => true,
                'message' => 'Orders Proccessed'
            ];
        } catch(Exception $e) {
            $this->di->getLog()->logContent("Exception from proceedCancelProcess(), error: ".json_encode($e->getMessage()), 'info', $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    /**
     * to get modified cancellation data from amazon
     *
     * @param array $cancelled_orders
     * @return array
     */
    public function getOrdersForCancellation($cancelled_orders)
    {
        try {
            $this->di->getLog()->logContent('In getOrdersForCancellation', 'info', $this->logFile);
            $orders = [];
            $orderCancelService = $this->di->getObjectManager()->get(CancelInterface::class);
            foreach ($cancelled_orders as $order) {
                if (isset($order['AmazonOrderId'])) {
                    //to get source_order
                    $params = [
                        "user_id" => $this->di->getUser()->id,
                        '$or' => [
                            ['object_type' => 'source_order'],
                            ['object_type' => 'target_order']
                        ],
                        'marketplace_reference_id' => $order['AmazonOrderId']
                    ];
                    $sourceOrder = $orderCancelService->findOneFromDb($params);

                    if (!empty($sourceOrder)) {
                        $this->di->getLog()->logContent('Source Order Found for order_id '.$sourceOrder['marketplace_reference_id'], 'info', $this->logFile);
                        $home_shop_id = $sourceOrder['marketplace_shop_id'];
                        $user_id = $this->di->getUser()->id;
                        $shop = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getShop($home_shop_id, $user_id);
                        if (empty($shop)) {
                            $this->di->getLog()->logContent('Shop not found for amazon order_id '.$sourceOrder['marketplace_shop_id'].' for home_shop_id '.$home_shop_id.' for user '.$user_id, 'info', $this->logFile);
                        } else {
                           //to get order's data from Amazon
                            $remote_params = ['shop_id' => $shop['remote_shop_id'], 'home_shop_id' => $home_shop_id,  'amazon_order_id' => $order['AmazonOrderId']];
                            $this->di->getLog()->logContent('Params for remote order search: '.print_r($remote_params, true), 'info', $this->logFile);
                            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                            $response = $commonHelper->sendRequestToAmazon('order', $remote_params, 'GET');

                            if (isset($response['success']) && $response['success']) {
                                $this->di->getLog()->logContent('*************************'.PHP_EOL.'Remote success: '.json_encode($response).PHP_EOL.'**************************', 'info', $this->logFile);
                                $cancelData = $this->getCancelOrders($response, $sourceOrder);
                                if(!empty($cancelData)) {
                                    array_push($orders, $cancelData);
                                } else {
                                    $this->di->getLog()->logContent('No updated order for cancellation', 'info', $this->logFile);
                                }
                            } else {
                                $this->di->getLog()->logContent('*************************'.PHP_EOL.'Remote failure: '.json_encode($response).PHP_EOL.'**************************', 'info', $this->logFile);
                            }
                        }
                    } else {
                        $this->di->getLog()->logContent('Source order not found in db', 'info', $this->logFile);
                    }
                }
            }

            return $orders;
        } catch(Exception $e) {
            $this->di->getLog()->logContent("Exception from getOrdersForCancellation(), error: ".json_encode($e->getMessage()), 'info', $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    /**
     * to modify order for cancel data
     *
     * @param array $orders
     * @param array $sourceOrder
     * @return array
     */
    public function getCancelOrders($orders, $sourceOrder)
    {
        try {
            $data = [];
            if(isset($orders['orders']) && !empty($sourceOrder['items'])) {
                foreach($orders['orders'] as $k => $order) {
                    if(isset($order['data']['OrderStatus']) && (strtolower((string) $order['data']['OrderStatus']) == 'canceled' || strtolower((string) $order['data']['OrderStatus']) == 'cancelled')) {
                        foreach($order['items'] as $key => $item) {
                            $response = $this->getUpdatedItem($item, $sourceOrder['items']);
                            if(empty($response)) {
                                unset($orders['orders'][$k]['items'][$key]);
                            } else {
                                $orders['orders'][$k]['items'][$key] = $response;
                            }
                        }

                        $orders['orders'][$k]['items'] = array_values($orders['orders'][$k]['items']);
                    } else {
                        unset($orders['orders'][$k]);
                        $this->di->getLog()->logContent('Invalid order status: '.$order['data']['OrderStatus'], 'info', $this->logFile);
                    }
               }

               $orders['orders'] = array_values($orders['orders']);
               $data = $orders;
           }

           return $data;
        } catch(Exception $e) {
            $this->di->getLog()->logContent("Exception from getCancelOrders(), error: ".json_encode($e->getMessage()), 'info', $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    public function getUpdatedItem($item, $sourceItems) 
    {
        try {
            foreach ($sourceItems as $sourceItem) {
                if($sourceItem['sku'] == $item['SellerSKU']) {
                    if(!isset($item['QuantityCancelled'])) {
                        if(isset($item['QuantityOrdered']) && $item['QuantityOrdered'] <= $sourceItem['qty']) {
                            //$item['QuantityCancelled'] =  ((int)$sourceItem['qty'] - (int)$item['QuantityOrdered']);

                            //approved
                            $item['QuantityCancelled'] =  isset($sourceItem['cancelled_qty']) ? (((int)$sourceItem['qty'] - (int)$sourceItem['cancelled_qty']) - (int)$item['QuantityOrdered']) : ((int)$sourceItem['qty'] - (int)$item['QuantityOrdered']);
                            return $item;
                        }
                    } else {
                        return $item;
                    }
                }
            }

            return [];
        } catch(Exception $e) {
            $this->di->getLog()->logContent("Exception from getUpdatedItem(), error: ".json_encode($e->getMessage()), 'info', $this->logFile ?? 'exception.log');
            throw $e;
        }
    }

    public function syncCancelOrder($data)
    {
        if (!isset($data['order_id'], $data['user_id'])) {
            return [
                'success' => false,
                'message' => 'order_id/user_id not provided!'
            ];
        }
        if ($data['user_id'] !== $this->di->getUser()->id) {
            $response = $this->di->getObjectManager()->get('App\Amazon\Components\Common\Common')->setDiForUser($data['user_id']);
            if (!isset($response['success']) && !$response['success']) {
                return $response;
            }
        }
        
        if ($this->di->getAppCode()->getAppTag() == 'default') {
            $appTag = $data['app_tag'] ?? 'amazon_sales_channel';
            $this->di->getAppCode()->setAppTag($appTag); 
        }
        $cancelData[]['AmazonOrderId'] = $data['order_id'];
        return $this->proceedCancelProcess($cancelData);
    }
}
