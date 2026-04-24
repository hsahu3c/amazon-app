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

use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Helper;
use App\Shopifyhome\Components\Core\Common;

class Shipment extends Common
{
    private $orderCollection;

    /**
     * prepare function
     * For preparing webhook data
     * @param [array] $data
     * @return array
     */
    public function prepare($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $orderCollection = $mongo->getCollectionForTable('order_container');
        $params = [
            'user_id' => $data['user_id'],
            'object_type' => ['$in' => ['source_order', 'target_order']],
            'marketplace_shop_id' => $data['shop_id'],
            'marketplace_reference_id' => (string) $data['data']['order_id']
        ];
        $options = [
            'projection' => ['user_id' => 1, 'object_type' => 1, 'shop_id' => 1,
            'marketplace' => 1, 'targets' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ];
        $orderData = $orderCollection->findOne($params, $options);
        if (empty($orderData)) {
            return ['success' => false, 'message' => 'Order not found'];
        }

        if (!isset($orderData['targets'])) {
            return ['success' => false, 'message' => 'Targets data not found'];
        }

        if ($orderData['object_type'] != 'source_order') {
            foreach ($orderData['targets'] as $value) {
                $sourceShopId = $value['shop_id'];
                $sourceMarketplace = $value['marketplace'];
            }

            $targetMarketplace = $orderData['marketplace'];
            $targetShopId = $orderData['shop_id'];
        } else {
            foreach ($orderData['targets'] as $value) {
                $targetShopId = $value['shop_id'];
                $targetMarketplace = $value['marketplace'];
            }

            $sourceMarketplace = $orderData['marketplace'];
            $sourceShopId = $orderData['shop_id'];
        }

        $data['source_shop_id'] = (string)$sourceShopId;
        $data['target_shop_id'] =  (string)$targetShopId;
        $data['target_marketplace'] = $targetMarketplace;
        $data['source_marketplace'] = $sourceMarketplace;
        $data['user_id'] = $orderData['user_id'];
        $data['object_type'] = $orderData['object_type'];
        $connectorOrder = $this->di->getObjectManager()->get(\App\Connector\Components\Order\Shipment::class, []);
        $connectorOrder->initiateShipment($data);
        return ['success' => true, 'message' => 'Shipment Initiated'];
    }

    /**
     * syncShipmentDetails function
     * Syncing Shipment Details on Shopify
     * @param string $sourceOrderId (mandatory)
     * @param array $trackingDetails (optional)
     * - number (string) : Shipment tracking number
     * - company (string) : Shipping company name
     * - url (string) : Tracking URL
     * @return array
     */
    public function syncShipmentDetails($orderId, $trackingDetails = null, $userId = null)
    {
        $userId ??= $this->di->getUser()->id;
        $marketplace = $this->di->getObjectManager()
            ->get(Shop::class)->getUserMarkeplace();
        $orderData = $this->getOrderData($userId, $orderId, $marketplace);
        if (!empty($orderData) && isset($orderData['marketplace_reference_id'])) {
            $shops = $this->di->getUser()->shops ?? [];
            $shopData = $items = $fufillmentData = [];
            if (!empty($shops)) {
                foreach ($shops as $shop) {
                    if ($shop['marketplace'] == $marketplace) {
                        $shopData = $shop;
                        break;
                    }
                }

                if (empty($shopData)) {
                    return ['success' => false, 'message' => $marketplace . " Shop not matched"];
                }

                $remoteShopId = $shopData['remote_shop_id'] ?? "";
                $appCode = $shopData['apps'][0]['code'] ?? 'default';
                $requestParams = [
                    'shop_id' => $remoteShopId,
                    'order_id' => $orderId,
                    'app_code' => $appCode
                ];
                $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
                $response = $shopifyHelper->sendRequestToShopify('get/fulfillmentOrder', [], $requestParams, 'GET');
                if (isset($response['success']) && $response['success']) {
                    if (isset($response['data']['fulfillment_orders']) && !empty($response['data']['fulfillment_orders'])) {
                        if (!empty($orderData['items'])) {
                            $matchedItem = [];
                            foreach ($orderData['items'] as $item) {
                                $matchedItem[$item['marketplace_item_id']] = $item['qty'];
                            }

                            foreach ($response['data']['fulfillment_orders'] as $fufillmentData) {
                                $fulfillmentOrderId = $fufillmentData['id'];
                                $items = [];
                                if (isset($fufillmentData['line_items']) && !empty($fufillmentData['line_items'])) {
                                    foreach ($fufillmentData['line_items'] as $fufillItems) {
                                        $itemId = $fufillItems['line_item_id'];
                                        if (isset($matchedItem[$itemId])) {
                                            $temp = [
                                                "id" => $fufillItems['id'],
                                                "quantity" => $matchedItem[$itemId],
                                                "line_item_id" => $itemId
                                            ];
                                            array_push($items, $temp);
                                        }
                                    }

                                    if (!empty($items)) {
                                        $prepareData = [
                                            'message' => 'Ship this Order',
                                            'line_items_by_fulfillment_order' => [
                                                [
                                                    'fulfillment_order_id' => $fulfillmentOrderId,
                                                    'fulfillment_order_line_items' => $items
                                                ],
                                            ]

                                        ];
                                        if (!empty($trackingDetails)) {
                                            $prepareData['tracking_info'] = $trackingDetails;
                                        }

                                        $requestParams = [
                                            'shop_id' => $remoteShopId,
                                            'order_id' => $orderData['marketplace_reference_id'],
                                            'app_code' => $appCode,
                                            'fulfillment' => $prepareData
                                        ];
                                        $fulfillmentResponse = $shopifyHelper->sendRequestToShopify('order/fulfillmentCreate', [], $requestParams, 'POST');
                                        if (isset($fulfillmentResponse['success']) && !$fulfillmentResponse['success']) {
                                            $errorMsg = $fulfillmentResponse['message']['errors'] ?? $fulfillmentResponse['msg']['errors'] ?? '';
                                            $errorMessages[$fulfillmentOrderId] = $errorMsg;
                                            if ($errorMsg == 'Exceeded 2 calls per second for api client. Reduce request rates to resume uninterrupted service.' || stripos((string) $errorMsg, 'Exceeded order API rate limit') !== false) {
                                                sleep(3);
                                                $this->syncShipmentDetails($orderId, $trackingDetails, $userId);
                                            }
                                        }
                                    }
                                }
                            }

                            if (!isset($errorMessages)) {
                                $this->setShippingStatus($userId, $orderId, $marketplace);
                                return ['success' => true, 'message' => "Shipment details synced successfully!!"];
                            }

                            $message = $errorMessages;
                        } else {
                            $message = "Empty order items";
                        }
                    } else {
                        $message = "No open fulfillment order(s) found for Order ID";
                    }
                } else {
                    $message = "Something went wrong on fetching fulfillment order(s)";
                }
            } else {
                $message = 'Shop not found';
            }
        } else {
            $message = 'Order data not found';
        }

        return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
    }

    /**
     * getOrderData function
     * To get source or target order data
     * @param string $userId (mandatory)
     * @param string $orderId (mandatory)
     * @return array
     */
    private function getOrderData($userId, $orderId, $marketplace)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->orderCollection = $mongo->getCollectionForTable('order_container');
        return $this->orderCollection->findOne(
            [
                'user_id' => $userId,
                'object_type' => ['$in' => ['source_order', 'target_order']],
                'marketplace' => $marketplace,
                'marketplace_reference_id' => $orderId
            ],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        );
    }

    /**
     * setShippingStatus function
     * To set shipping status key in source or target order
     * @param string $userId (mandatory)
     * @param string $orderId (mandatory)
     */
    private function setShippingStatus($userId, $orderId, $marketplace): void
    {
        $this->orderCollection->updateOne(
            [
                'user_id' => $userId,
                'object_type' => ['$in' => ['source_order', 'target_order']],
                'marketplace' => $marketplace,
                'marketplace_reference_id' => $orderId
            ],
            [
                '$set' => ['status' => 'Shipped', 'shipping_status' => 'Shipped']
            ]
        );
    }

    /**
     * updateShipmentDetails function
     * Syncing Shipment Details on Shopify
     * @param string $sourceOrderId (mandatory)
     * @param array $trackingDetails (mandatory)
     * - number (string) : Shipment tracking number
     * - company (string) : Shipping company name
     * - url (string) : Tracking URL
     * @return array
     */
    public function updateShipmentDetails($orderId, $trackingDetails = null, $userId = null, $fulfillmentId = null)
    {
        $userId ??= $this->di->getUser()->id;
        $marketplace = $this->di->getObjectManager()
            ->get(Shop::class)->getUserMarkeplace();
        $orderData = $this->getOrderData($userId, $orderId, $marketplace);
        if (!empty($orderData) && isset($orderData['marketplace_reference_id'])) {
            $shopifyOrderId = $orderData['marketplace_reference_id'];
            $shops = $this->di->getUser()->shops ?? [];
            $shopData = [];
            $fulfillmentIds = [];
            if (!empty($shops)) {
                foreach ($shops as $shop) {
                    if ($shop['marketplace'] == $marketplace) {
                        $shopData = $shop;
                        break;
                    }
                }

                if (empty($shopData)) {
                    return ['success' => false, 'message' => $marketplace . " Shop not matched"];
                }

                $remoteShopId = $shopData['remote_shop_id'] ?? "";
                $appCode = $shopData['apps'][0]['code'] ?? 'default';
                $requestParams = [
                    'shop_id' => $remoteShopId,
                    'order_id' => $shopifyOrderId,
                    'app_code' => $appCode
                ];
                $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
                $response = $shopifyHelper->sendRequestToShopify('get/ordershipment', [], $requestParams, 'GET');
                if (isset($response['success']) && $response['success']) {
                    if (!empty($response['data']['fulfillments'])) {
                        foreach ($response['data']['fulfillments'] as $fulfillmentData) {
                            if (isset($fulfillmentData['status']) && $fulfillmentData['status'] == 'success') {
                                if (isset($fulfillmentId)) {
                                    if ($fulfillmentId == $fulfillmentData['id']) {
                                        $fulfillmentIds[] = $fulfillmentId;
                                        break;
                                    }
                                } else {
                                    $fulfillmentIds[] = $fulfillmentData['id'];
                                }
                            }
                        }

                        foreach ($fulfillmentIds as $id) {
                            $requestParams = [
                                'shop_id' => $remoteShopId,
                                'fulfillment_id' => $id,
                                'app_code' => $appCode,
                                "fulfillment" => [
                                    "tracking_info" => $trackingDetails
                                ],
                            ];
                            $fulfillmentResponse = $shopifyHelper->sendRequestToShopify(
                                'update/ordershipment',
                                [],
                                $requestParams,
                                'POST'
                            );
                            if (isset($fulfillmentResponse['success']) && !$fulfillmentResponse['success']) {
                                $errorMsg = $fulfillmentResponse['message']['errors'] ?? $fulfillmentResponse['response']['errors'] ?? json_encode($fulfillmentResponse);
                                $errorMessages[$id] = $errorMsg;
                            }
                        }

                        if (!isset($errorMessages)) {
                            return ['success' => true, 'message' => "Tracking details updated successfully"];
                        }

                        $message = $errorMessages;
                    } else {
                        $message = "Empty fulfillment data";
                    }
                } else {
                    $message = "Unable to get order shipment data";
                }
            }
        } else {
            $message = 'Order data not found';
        }

        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    /**
     * retryShipmentByOrderId function
     * Manual action to initiate shipment sync from Shopify to target
     * @return array
    */
    public function retryShipmentByOrderId($params = [])
    {
        if (!empty($params['order_id'])) {
            $userId = $this->di->getUser()->id ?? $params['user_id'];
            $sourceShopId = $this->di->getRequester()->getSourceId()  ?? $params['source_shop_id'];
            $sourceMarketplace = $this->di->getRequester()->getSourceName()  ?? $params['source_marketplace'];
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shopData = $userDetails->getShop($sourceShopId, $userId);
            if (!empty($shopData)) {
                $appCode = $shopData['apps'][0]['code'] ?? 'default';
                $orderFetchParams = [
                    'shop_id' => $shopData['remote_shop_id'],
                    'id' => $params['order_id'],
                    'app_code' => $appCode
                ];
                $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
                $connectorSourceModel = $this->di->getObjectManager()->get('App\\Connector\\Models\\SourceModel');
                $response = $shopifyHelper->sendRequestToShopify('/order', [], $orderFetchParams, 'GET');
                if (isset($response['success']) && $response['success']) {
                    if (!empty($response['data']['fulfillments'])) {
                        foreach ($response['data']['fulfillments'] as $fulfillments) {
                            $prepareData = [
                                'type' => 'full_class',
                                'class_name' => '\\App\\Connector\\Models\\SourceModel',
                                'action' => 'fulfillments_update',
                                'shop' => $shopData['myshopify_domain'],
                                'data' => $fulfillments,
                                'user_id' => $userId,
                                'shop_id' => $sourceShopId,
                                'marketplace' => $sourceMarketplace
                            ];
                            $connectorSourceModel->triggerWebhooks($prepareData);
                        }
                        return ['success' => true, 'message' => 'Shipment sync initiated'];
                    } else {
                        $message = "Fulfillment not done from $sourceMarketplace";
                    }
                } else {
                    $message = "Unable to fetch fulfillment data from $sourceMarketplace";
                }
            }
        } else {
            $message = "Required params missing(order_id)";
        }

        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }
}
