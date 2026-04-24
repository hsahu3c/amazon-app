<?php
/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */


namespace App\Shopifyhome\Components\Order;

use App\Shopifyhome\Components\Core\Common;
use GuzzleHttp\Client;

#[\AllowDynamicProperties]
class Order extends Common
{

    private $_user_id;
    private $_queue_data = [];
    private $_shopify_shop_id;
    private $_shopify_remote_shop_id;

    private $_user_details;

    public function init(bool $private = false, $options = [])
    {
        return $this;
    }

    public function initMain($queueData)
    {
        if (isset($queueData['user_id'])) {
            $this->_user_id = $queueData['user_id'];
        } else {
            $this->_user_id = $this->di->getUser()->id;
        }
        $this->_queue_data = $queueData;
        $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $user_details = $this->_user_details->getDataByUserID($this->_user_id, 'shopify');
        $fb_user_details = $this->_user_details->getDataByUserID($this->_user_id, 'facebook');

        $this->_shopify_shop_id = $user_details['_id'] ?? '';
        $this->_shopify_remote_shop_id = $user_details['remote_shop_id'] ?? '';
        $this->_fb_remote_shop_id = $fb_user_details['remote_shop_id'] ?? '';

        return $this;
    }

    public function deleteFromShopify ( ): void {

        $id = json_decode('[{"id":2351991586893,"name":"639582656901533"}]', true);

        for ( $i = 0; $i < 63; $i++ ) {
            if ( !isset($id[$i]['id']) ) {
                die("dddd");
            }

            $url = 'https://maxsupshop.myshopify.com/admin/api/2020-04/orders/' . $id[$i]['id'] . '.json';
            $res = $this->sendRequest($url,'ffe67605ace73f14a58bc7d7788eecbb','DELETE');
            if ( $res['status_code'] !== 200 ) {
                print_r($res);die("ERROR");
            }

            echo $res['header']['X-Shopify-Shop-Api-Call-Limit'][0]  .PHP_EOL;
        }

        print_r($res);die("COMPLETED");
    }

    private function OrderManage($order, $user_id) {
        $res = [];

        $this->di->getLog()->logContent('-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-* ','info',$user_id . '/ManuallyOrderSync.log');
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', 'true')
            ->call('/order', [], ['shop_id' => $this->_shopify_remote_shop_id, 'id' => $order['target_order_id']], 'GET');

        if ( !$remoteResponse['success'] ) {
            $this->di->getLog()->logContent('ERROR ','info',$user_id . '/CHECKORDER.log');
            $this->di->getLog()->logContent('REMOTE ERROR : ' . json_encode($remoteResponse, 128),'info',$user_id . '/ManuallyOrderSync.log');
            return 'error';
        }

        $this->di->getLog()->logContent('DATA GET ','info',$user_id . '/CHECKORDER.log');
        $res['fulfil'] = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Hook')->updateOrder($remoteResponse);
        $res['cancel'] = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Hook')->cancelOrder($remoteResponse);
        $res['refund'] = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Refund\Hook')->createRefund($remoteResponse);
        $this->di->getLog()->logContent('DB : ' . json_encode($order),'info',$user_id . '/ManuallyOrderSync.log');
        $this->di->getLog()->logContent('Facebook : ' . json_encode($res, JSON_PRETTY_PRINT),'info',$user_id . '/ManuallyOrderSync.log');
        $this->di->getLog()->logContent('Shopify Fullfilments : ' . json_encode($remoteResponse['data']['fulfillments'], 128),'info',$user_id . '/ManuallyOrderSync.log');
        $this->di->getLog()->logContent('Shopify refunds : ' . json_encode($remoteResponse['data']['refunds'], 128),'info',$user_id . '/ManuallyOrderSync.log');
        return $remoteResponse;
    }

    public function syncOrdersManually($data) {
        $user_id = $this->di->getUser()->id;

        if ( isset($data['target_order_id']) && $data['target_order_id'] != '' ) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('order_container_' . $user_id);

            $aggregate = [];
            $aggregate[] = ['$match' => ['target_order_id' => $data['target_order_id']]];
            $aggregate[] = ['$project' => [
                'source_order_id' => 1,
                'target_order_id' => 1,
            ]];

            $orders = $collection->aggregate($aggregate)->toArray();

            $res = [];

            foreach ( $orders as $order ) {
                $res = $this->OrderManage($order, $user_id);

            }

            return ['success' => true , 'message' => 'Order is Successfully Synced with Shopify.', 'shopify' => $res];

        }
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container_' . $user_id);
        $cursor = 0;
        if ( isset($data['data']['cursor']) ) {
            $this->initMain($data);
            $cursor = $data['data']['cursor'];
        }
        $aggregate = [];
        $aggregate[] = ['$match' => ['status' => 'pending', 'target_order_id' => ['$ne' => '']]];
        $aggregate[] = ['$project' => [
            'source_order_id' => 1,
            'target_order_id' => 1,
        ]];
        $aggregate[] = ['$sort' => ['_id' => -1]];
        $aggregate[] = ['$skip' => $cursor*5];
        $aggregate[] = ['$limit' => 5];
        $this->di->getLog()->logContent('CURSOR  : ' . $cursor,'info',$user_id . '/CHECKORDER.log');
        $orders = $collection->aggregate($aggregate)->toArray();
        if ( count($orders) == 0 ) {
            $this->di->getLog()->logContent('COMPLETED!!','info',$user_id . '/CHECKORDER.log');
            return ['success' => true , 'message' => 'Order Sync is Successfully Started with Shopify.'];
        }
        foreach ( $orders as $order ) {
            $this->OrderManage($order, $user_id);
        }
        $pushData = [
            'user_id' => $user_id,
            'cursor' => $cursor + 1,
        ];
        $this->pushToQueue($pushData);
        $this->di->getLog()->logContent('PSUHED TO QUEUE ','info',$user_id . '/CHECKORDER.log');

        return ['success' => true , 'message' => 'Order Sync is Successfully Started with Shopify.'];
    }

    public function syncOrders($order = [])
    {
        return $this->moldOrder($order['orders']);
    }

    private function moldOrder($OrderIds)
    {
        $userId =  $this->_user_id; // get user id
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container_' . $userId);

        $orders = $collection->find([
            'source_order_id' => [
                '$in' => $OrderIds
            ]
        ]);
        $oldShopifyOrderIds = [];
        $responseArray = [];
        foreach ($orders as $value) {
            $orderId = $value->source_order_id;
            echo $orderId . PHP_EOL;
            $orderValue = $collection->findOne([
                'source_order_id' => $orderId
            ], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            if (isset($orderValue['target_order_id']) && $orderValue['target_order_id'] != '') {
                $oldShopifyOrderId = $orderValue['target_order_id'];
                $oldShopifyOrderIds[] = $orderValue['target_order_id'];
                $responseArray[] = ['id' => $orderId];
                $this->di->getLog()->logContent('*****************Error ALREADY CREATED *************************','info',$this->_user_id . '/order-upload-error.log');
                $this->di->getLog()->logContent('ORDER ID : ' . $orderId ,'info',$this->_user_id . '/order-upload-error.log');
                $this->di->getLog()->logContent('*****************Error *************************','info',$this->_user_id . '/order-upload-error.log');
                continue;
            }

            $shippingLines = $this->getShippingLines($orderValue);

            if ( !isset($orderValue['channel']) ) $orderValue['channel'] = 'facebook';

            $orderData = [
                'tax_lines' => $orderValue['tax_lines'] ?? [],
                'email' => $orderValue['client_details']['contact_email'],
                'browser_ip' => $orderValue['client_details']['browser_ip'] ?? null,
                'currency' => $orderValue['currency'],
                'total_tax' => $orderValue['total_tax'],
                'client_details' => [
                    'browser_ip' => $orderValue['client_details']['browser_ip'] ?? null
                ],
                'created_at' => $orderValue['placed_at'],
                'fulfillment_status' => $orderValue['fulfillment_status'] ?? 'unfulfilled',
                'processed_at' => $orderValue['placed_at'],
                'subtotal_price' => $orderValue['subtotal_price'],
                'taxes_included' => false,
                'total_discounts' => $orderValue['total_discounts'] ?? 0,
                'total_price' => $orderValue['total_price'],
                'total_weight' => $orderValue['total_weight'] ?? 0,
                'financial_status' => 'paid',
//                'inventory_behaviour' => 'bypass',
                'inventory_behaviour' => 'decrement_obeying_policy',
                'note' => $orderValue['channel'] . ' order id => ' . $orderValue['source_order_id'],
                'name' => $orderValue['source_order_id'],
                'source_name' => $orderValue['channel'],
                'note_attributes' => [
                    [
                        'name' => 'channel',
                        'value' => $orderValue['channel']
                    ],
                    [
                        'name' => 'Source Order ID',
                        'value' => $orderValue['source_order_id']
                    ]
                ],
                'transactions' => [
                    [
                        'kind' => 'sale',
                        'status' => 'success',
                        'amount' => $orderValue['total_price'],
                        "gateway" => $orderValue['channel'] == 'facebook' ? "Facebook_Marketplace" : $orderValue['channel']
                    ]
                ]
            ];

            if (isset($orderValue['discount_codes'])) {
                $orderData['discount_codes'] = $this->getDiscountCodes($orderValue['discount_codes']);
            }

            if (isset($orderValue['client_details']['contact_email']) &&
                isset($orderValue['client_details']['name'])) {
                $orderData['cart'] = [
                    'email' => $orderValue['client_details']['contact_email'],
                    'first_name' => $orderValue['client_details']['name']
                ];
            }

            $lineItems = [];
            $skippThisOrder = false;
            foreach ($orderValue['line_items'] as $itemValue) {
                $quantityOrdered = ($itemValue['quantity_ordered'] ?? 0) - ($itemValue['quantity_canceled'] ?? 0);
                if ($quantityOrdered > 0) {
                    $itemData = [
//                        'fulfillable_quantity' => $quantityOrdered,
                        'quantity' => $quantityOrdered,
                        'title' => $itemValue['title'],
                        //'sku' => '',
                        'price' => (string)$itemValue['price'],
//                        'total_discount' => $itemValue['total_discount'] ?? 0,
//                        'fulfillment_status' => $itemValue['fulfillment_status'] ?? null,
//                        'grams' => $itemValue['weight'] ?? ''
                    ];
                    if ( isset($itemValue['source_variant_id']) && $itemValue['source_variant_id'] != '' ) {
                        $itemData['variant_id'] = $itemValue['source_variant_id'];
                    } else {
                        $skippThisOrder = true;
                        $collection->updateOne(['source_order_id' => $orderId],['$set' => ['status' => 'error', 'errorMessage' => 'Product not found on Shopify.']]);
                    }

                    $lineItems[] = $itemData;
                }
            }

            if ( $skippThisOrder ) continue;

            if ($lineItems !== []) {
                $orderData['line_items'] = $lineItems;
                $billingAddress = $this->getBillingAddress($orderValue);
                $deliveryAddress = $this->getDeliveryAddress($orderValue);
                if ($billingAddress &&
                    $deliveryAddress) {
                    $orderData['billing_address'] = $billingAddress;
                    $orderData['shipping_address'] = $deliveryAddress;
                } else if ( $deliveryAddress ) {
                    $orderData['billing_address'] = $deliveryAddress;
                    $orderData['shipping_address'] = $deliveryAddress;
                }

                if ($shippingLines) {
                    $orderData['shipping_lines'] = $shippingLines;
                }

                $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init('shopify', 'true')
                    ->call('/order', [], ['shop_id' => $this->_shopify_remote_shop_id, 'data' => $orderData], 'POST');
                if ( $remoteResponse['success'] ) {
                    $this->di->getLog()->logContent('*****************Creatted *************************','info',$this->_user_id . '/order-upload.log');
                    $this->di->getLog()->logContent('Created : ID : ' . $orderId . ' Created Request'  . json_encode($orderData),'info',$this->_user_id . '/order-upload.log');
                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $collection = $mongo->getCollectionForTable("order_container_" . $userId);
                    $responseArray[] = ['id' => $orderId];
                    $collection->updateOne(['source_order_id' => $orderId],['$set' => ['status'=>'pending', 'target_order_id' => (string)$remoteResponse['data']['id']]]);
                    $this->di->getLog()->logContent('Created : ID : ' . $orderId . ' Remote Response'  . json_encode($remoteResponse),'info',$this->_user_id . '/order-upload.log');
                    $this->di->getLog()->logContent('*****************END *************************','info',$this->_user_id . '/order-upload.log');
                } else {
                    $this->di->getLog()->logContent('*****************Error *************************','info',$this->_user_id . '/order-upload-error.log');
                    $this->di->getLog()->logContent('Created : ID : ' . $orderId . ' Created Request'  . json_encode($orderData),'info',$this->_user_id . '/order-upload-error.log');
                    $errorMSG = 'Error';
                    $error = [];
                    if ( gettype($remoteResponse['msg']['errors']) == 'string' ) {
                        $errorMSG = $remoteResponse['msg'];
                        $error = $remoteResponse['msg'];
                    } else if ( $remoteResponse['msg']['errors']['line_items'][0] == "Unable to reserve inventory" ) {
                        $errorMSG = "Unable to reserve inventory";
                        $error = $remoteResponse['msg'];
                    } else if ( gettype($remoteResponse['msg']['errors']) == 'array' ) {
                        $error = $remoteResponse['msg'];
                    }

                    $collection->updateOne(['source_order_id' => $orderId],['$set' => ['status' => 'error', 'errorMessage' => $errorMSG, 'errors' => $error]]);
                    $this->di->getLog()->logContent('Error : ID : ' . $orderId . ' Remote Response'  . json_encode($remoteResponse),'info',$this->_user_id . '/order-upload-error.log');
                    $this->di->getLog()->logContent('*****************END Error *************************','info',$this->_user_id . '/order-upload-error.log');
                }
            }
        }

        return $responseArray;

    }

    public function getShippingLines($orderData)
    {
        $shippingLines = [];
        if (isset($orderData['shipping_cost_details'])) {
            if ($orderData['shipping_cost_details']['title'] != '' &&
                $orderData['shipping_cost_details']['code'] != '') {
                $shippingLines[] = [
                    'price' => $orderData['shipping_cost_details']['cost'],
                    'title' => $orderData['shipping_cost_details']['title'],
                    'code' => $orderData['shipping_cost_details']['code']
                ];
            }

            if ($shippingLines !== []) {
                return $shippingLines;
            }

            return false;
        }
        if ( isset($orderData['shipping_details']['price']) && (float)$orderData['shipping_details']['price'] > 0 ) {
            $shippingLines[] = [
                'price' => $orderData['shipping_details']['price'],
                'title' => $orderData['shipping_details']['method']
            ];
            return $shippingLines;
        }

        return false;
    }

    public function getDiscountCodes($discounts)
    {
        $discountCodes = [];
        foreach ($discounts as $value) {
            $type = 'fixed_amount';
            if ($value['type'] == 'percentage') {
                $type = 'percentage';
            }

            $discountCodes[] = [
                'code' => ($value['code'] !== "" || $value['code'] !== null) ? $value['code'] : "#DISCOUNT",
                'amount' => $value['amount'],
                'type' => $type
            ];

        }

        return $discountCodes;
    }

    public function getBillingAddress($orderData)
    {
        if (isset($orderData['payment_method']['billing_address'])) {
            $name = $this->getNameSections($orderData['payment_method']['full_name'] ?? $orderData['payment_method']['billing_address'][0]);
            $billingAddress = [
                'first_name' => $name['first_name'],
                'last_name' => $name['last_name'] ?: '.',
                'phone' => $orderData['payment_method']['phone_number'],
                'city' => $orderData['payment_method']['locality'],
                'province' => $orderData['payment_method']['region'],
                'zip' => $orderData['payment_method']['zip'],
                'country' => $orderData['payment_method']['country']
            ];
            if (isset($orderData['payment_method']['full_name']) &&
                ($orderData['payment_method']['billing_address'][0] != $orderData['payment_method']['full_name']) &&
                isset($orderData['payment_method']['billing_address'][0])
            ) {
                $billingAddress['address1'] = $orderData['payment_method']['billing_address'][0];
            } else {
                $billingAddress['address1'] = $orderData['payment_method']['billing_address'][1];
            }

            if (isset($orderData['payment_method']['full_name']) &&
                ($orderData['payment_method']['billing_address'][0] != $orderData['payment_method']['full_name']) &&
                count($orderData['payment_method']['billing_address']) > 1) {
                $billingAddress['address2'] = $orderData['payment_method']['billing_address'][1];
            }

            return $billingAddress;
        }

        return false;
    }

    public function getNameSections($fullName) {
        $nameArray = explode(' ', (string) $fullName);
        $finalData = [
            'first_name' => $nameArray[0],
            'last_name' => implode(' ', array_slice($nameArray, 1))
        ];
        return $finalData;
    }

    public function getDeliveryAddress($orderData)
    {
        if (isset($orderData['delivery_details'])) {
            $name = $this->getNameSections($orderData['delivery_details']['full_name'] ?? $orderData['delivery_details']['full_address'][0]);
            $billingAddress = [
                'first_name' => $name['first_name'],
                'last_name' => $name['last_name'] ?: '.',
                'address1' => $orderData['delivery_details']['full_address'][0] ?: '',
//                'address2' => $orderData['delivery_details']['full_address'][1] ? $orderData['delivery_details']['full_address'][1] : '',
//                'phone' => $orderData['delivery_details']['phone_number'] ?? '',
                'city' => $orderData['delivery_details']['locality'],
                'province' => $orderData['delivery_details']['region'],
                'zip' => $orderData['delivery_details']['zip'],
                'country' => $orderData['delivery_details']['country']
            ];
            if (isset($orderData['delivery_details']['full_name']) &&
                ($orderData['delivery_details']['full_address'][0] != $orderData['delivery_details']['full_name']) &&
                count($orderData['delivery_details']['full_address']) > 1) {
                $billingAddress['address2'] = $orderData['delivery_details']['full_address'][1];
            }

            return $billingAddress;
        }

        return false;
    }

    public function sendRequest($endUrl, $token = '', $type = 'GET')
    {
        $headers = $this->shapeShopifyHeaders($token);

        $client = new Client();

        $response = $client->request($type, $endUrl, ['headers' => $headers, 'http_errors' => false]);

        return [
            'status_code' => $response->getStatusCode(),
            'header' => $response->getHeaders(),
            'data' => json_decode((string) $response->getBody()->getContents(), true)
        ];
    }

    private function shapeShopifyHeaders($token)
    {
        $headers = [
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json'
        ];
        return $headers;
    }

    public function pushToQueue($data, $method = 'syncOrdersManually', $time = 10, $queueName = 'fb_order_sync')
    {
        $handlerData = [
            'type' => 'full_class',
            'class_name' => \App\Shopifyhome\Components\Order\Order::class,
            'queue_name' => $queueName,
            'own_weight' => 100,
            'user_id' => $this->_user_id,
            'data' => $data,
            'run_after' => $time,
            'method' => $method
        ];
        if ($this->di->getConfig()->get('enable_rabbitmq_internal')) {
            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
            return $helper->createQueue($handlerData['queue_name'], $handlerData);
        }

        return false;
    }

}