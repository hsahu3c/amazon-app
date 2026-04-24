<?php

/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Shopifyhome\Service;

use App\Shopifyhome\Components\Helper;
use App\Connector\Contracts\Sales\Order\ShipInterface;
use App\Shopifyhome\Service\AbstractShipment;

/**
 * Interface CurrencyInterface
 * @services
 */
class Shipment extends AbstractShipment implements ShipInterface
{
    private $logFilePath;
    /**
     * mold function
     * Molding Webhook Data
     * @param [array] $data
     */
    public function mold(array $data): array
    {
        if (isset($data['data'])) {

            $prepareData = [
                'object_type' => $data['object_type'],
                'source_shop_id' => $data['source_shop_id'],
                'target_shop_id' => $data['target_shop_id'],
                'target_marketplace' => $data['target_marketplace'],
                'source_marketplace' => $data['source_marketplace'],
                'marketplace_reference_id' => (string)$data['data']['order_id'],
                'marketplace_shipment_id' =>  $data['data']['id'],
                'user_id' => $data['user_id'],
                'items'  => [],
                'shipping_status' =>   $data['data']['shipment_status'] ?? '',
                'shipping_method' => [
                    'code' => $data['data']['tracking_company'],
                    'title' => $data['data']['tracking_company'],
                    'carrier' => $data['data']['tracking_company']
                ],
                'tracking' =>
                [
                    'company' =>  $data['data']['tracking_company'],
                    'number' => trim((string) $data['data']['tracking_number']),
                    'name' => $data['data']['tracking_url'],
                    'url' => $data['data']['tracking_url']
                ],
                'shipment_created_at' => $data['data']['created_at'],
                'shipment_updated_at' => $data['data']['updated_at']

            ];
            $items = [];
            foreach ($data['data']['line_items'] as $value) {
                $prepareItems = [
                    'type' => $value['product_exists'] ? 'real' : 'virtual',
                    'title' => $value['title'] ?? '',
                    'sku' => $value['sku'] ?? '',
                    'weight' => $value['grams'] ?? '',
                    'shipped_qty' => $value['quantity'],
                    'product_identifier' => $value['variant_id'],
                    'marketplace_item_id' => $value['id']
                ];
                $items[] = $prepareItems;
            }

            $prepareData['items'] = $items;
        }

        return $prepareData ?? [];
    }

    /**
     * ship function
     * To sync shipment details on Shopify
     * @param [array] $data
     */
    public function ship(array $data): array
    {
        $orderShipment = $this->di->getObjectManager()->get('App\Connector\Service\Shipment');
        $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $shops = $this->di->getUser()->shops;
        $shopData = [];
        $logFile = $data['targets'][0]['order_id'] ?? 'shipOnPlatform';
        $logPath = 'shopify'. DS. $userId. DS. date('Y-m-d'). DS. 'shipment'. DS. $logFile. '.log';
        $this->di->getLog()->logContent('* Shipment initiated for Order ID * '. $data['marketplace_reference_id'] ." ". json_encode($data), 'info', $logPath);
        $message = 'Something Went Wrong';
        if (!empty($shops)) {
            foreach ($shops as $shop) {
                if ($data['shop_id'] == $shop['_id']) {
                    $shopData = $shop;
                    break;
                }
            }

            if (!empty($shopData)) {
                $appCode = $shopData['apps'][0]['code'] ?? 'default';
                $remoteShopId = $shopData['remote_shop_id'];
                $fetchParams = [
                    'shop_id' => $remoteShopId,
                    'order_id' => $data['marketplace_reference_id'],
                    'app_code' => $appCode
                ];
                $response = $shopifyHelper->sendRequestToShopify('get/fulfillmentOrder', [], $fetchParams, 'GET');
                if (isset($response['success']) && $response['success']) {
                    if (isset($response['data']['fulfillment_orders']) && !empty($response['data']['fulfillment_orders'])) {
                        if (isset($data['items']) && !empty($data['items'])) {
                            $setQuantity = [];
                            foreach ($data['items'] as $item) {
                                $setQuantity[$item['marketplace_item_id']] = $item['shipped_qty'];
                            }

                            foreach ($response['data']['fulfillment_orders'] as $fufillmentData) {
                                $fulfillmentOrderId = $fufillmentData['id'];
                                $items = [];


                                if (isset($fufillmentData['line_items']) && !empty($fufillmentData['line_items'])) {
                                    foreach ($fufillmentData['line_items'] as $fufillItems) {
                                        $itemId = $fufillItems['line_item_id'];
                                        if (isset($setQuantity[$itemId])) {
                                            $temp = [
                                                "id" => $fufillItems['id'],
                                                "quantity" => $setQuantity[$itemId],
                                                "line_item_id" => $itemId
                                            ];
                                            array_push($items, $temp);
                                        }
                                    }

                                    if (!empty($items)) {
                                        $prepareData = [
                                            'message' => 'Ship this Order',
                                            'tracking_info' => [
                                                "number" => $data['tracking']['number'],
                                                "company" => $data['tracking']['company'],
                                                "url" => $data['tracking']['url'],
                                            ],
                                            'line_items_by_fulfillment_order' => [
                                                [
                                                    'fulfillment_order_id' => $fulfillmentOrderId,
                                                    'fulfillment_order_line_items' => $items
                                                ],
                                            ]

                                        ];
                                        $sendParams = [
                                            'shop_id' => $remoteShopId,
                                            'order_id' => $data['marketplace_reference_id'],
                                            'app_code' => $appCode,
                                            'fulfillment' => $prepareData
                                        ];
                                        $fulfillmentResponse = $shopifyHelper->sendRequestToShopify('order/fulfillmentCreate', [], $sendParams, 'POST');
                                        $shopifyOrderId = $data['marketplace_reference_id'];
                                        $marketplace = $data['targets'][0]['marketplace'];
                                        if (isset($fulfillmentResponse['success']) && $fulfillmentResponse['success']) {
                                            if (
                                                isset($fulfillmentResponse['data']['fulfillment'])
                                                && !empty($fulfillmentResponse['data']['fulfillment'])
                                            ) {
                                                $this->di->getLog()->logContent('* Shipment Created successfully for Order ID * '. $data['marketplace_reference_id'], 'info', $logPath);
                                                $orderShipment = $this->di->getObjectManager()->get('App\Connector\Service\Shipment');
                                                $orderShipment->setSourceStatus($shopifyOrderId, $marketplace, true, "Unknown", $userId);
                                            }

                                            return ['success' => true, 'message' => 'Shipment Details Successfully!!'];
                                        }
                                        if(isset($fulfillmentResponse['message']['errors'])){
                                            $errorMSG=$fulfillmentResponse['message']['errors'];
                                           if($errorMSG == 'Exceeded 2 calls per second for api client. Reduce request rates to resume uninterrupted service.' || stripos((string) $errorMSG,'Exceeded order API rate limit') !== false)
                                            {
                                                sleep(3);
                                                $this->ship($data);
                                            }
                                        }
                                        $this->di->getLog()->logContent('* Shipment Creation failed * '. $data['marketplace_reference_id'] ." ". json_encode([
                                            'sendParams' => $sendParams, 'fulfillmentOrders' => $response['data']['fulfillment_orders'], 'fulfillmentResponse' => $fulfillmentResponse
                                        ]), 'info', $logPath);
                                        $orderShipment->setSourceStatus($shopifyOrderId, $marketplace, false, $fulfillmentResponse['message'], $userId);
                                        return ['success' => false, 'message' => 'Shipment Details Syncing Failed!!'];
                                    }
                                    $this->di->getLog()->logContent('* Item(s) Mismatch * '. $data['marketplace_reference_id'] ." ". json_encode([
                                        'setQuantity' => $setQuantity, 'fulfillmentDataLineItems' => $fufillmentData['line_items']
                                    ]), 'info', $logPath);
                                }
                            }
                        }
                    }
                    else {
                        $this->di->getLog()->logContent('* No open fulfillment order(s) found for Order ID* '. $data['marketplace_reference_id'] ." ", 'info', $logPath);
                        return ['success' => false, 'message' => 'No open fulfillment order(s) found', 'data' => $response];
                    }
                }
                else {
                    $this->di->getLog()->logContent('* Fulfillment Order Response * '. $data['marketplace_reference_id'] ." ". json_encode(['fetchParams' => $fetchParams, 'response' => $response]), 'info', $logPath);
                    return ['success' => false, 'message' => 'Something went wrong on fetching fulfillment order(s)', 'data' => $response];
                }
            } else {
                $message = 'Shop not found';
            }
        } else {
            $this->di->getLog()->logContent('* Error Unable to fetch shop from Di *  '. $data['marketplace_reference_id'] ." ", 'info', $logPath);
            $message = 'Unable to fetch shop from Di.';
        }

        return ['success' => false, 'message' => $message];
    }

    public function isShippable()
    {
        return true;
    }
}
