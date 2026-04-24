<?php

/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Connector\Service;

use App\Connector\Contracts\Sales\Order\RefundInterface;

/**
 * ManualRefund
 * @services
 */
class ManualRefund implements RefundInterface
{
    public const ORDER_CONTAINER = 'order_container';

    public $errors = array();

    public function refund(array $order): array
    {
        //todo : add another param marketplace_item_id along with sku in data from front-end
        if (!isset($order['data'])) {
            return [
                'success' => false,
                'message' => 'No data provided for refund.'
            ];
        }

        $this->tempLog($this->di->getUser()->getConfig(), $type = 'User Config');
        $this->tempLog($order);

        $order = $order['data'];
        $preparedDataResponse = $this->prepareDataForRefund($order);
        if (!$preparedDataResponse['success']) {
            $this->saveInLog($order, 'errors/data-empty.log');
            return $preparedDataResponse;
        }

        $preparedData = $this->trimAdditionalData($preparedDataResponse['data']);

        $isInprocess = $this->isRefundInProgress($preparedData['data']);
        if (!$isInprocess['success']) {
            $this->saveInLog($preparedData['data'], 'errors/refund-in-progress.log');
            return $isInprocess;
        }

        $preparedData['process'] = 'manual';
        $connectorRefundService = $this->di->getObjectManager()->get('App\Connector\Components\Order\OrderRefund');
        $refundResponse = $connectorRefundService->refund($preparedData);

        return $refundResponse;
    }

    public function prepareDataForRefund(array $order): array
    {
        $targetOrder = $this->getTargetOrder($order);
        if (empty($targetOrder)) {
            return ['success' => false, 'message' => 'ObjectId provided is invalid.'];
        }

        $this->target = $targetOrder;
        // $targetRefund = $this->getTargetRefund($targetOrder);

        $targetOrder = $this->checkRefundItems($order, $targetOrder);

        if (!empty($this->errors)) {
            $this->saveInLog($this->errors, 'errors/all-errors.log');
            return ['success' => false, 'message' => $this->errors[0]['error']]; //as frontent handles only one message
        }

        $response = $this->updatePrices($targetOrder);
        if (!$response['success']) {
            $this->saveInLog($response, 'errors/no-items-matched.log');
            return $response;
        }

        $targetOrder = $response['data'];
        $targetOrder['items'] = array_values($targetOrder['items']);

        return ['success' => true, 'data' => $targetOrder];
    }

    public function updatePrices(array $targetOrder): array
    {
        $totalRefundPrice = 0;
        $totalRefundMarketplacePrice = 0;
        $totalAdjustPrice = 0;
        $totalAdjustMarketplacePrice = 0;
        $totalTaxprice = 0;
        $totalTaxMarketplacePrice = 0;
        $totalDiscountPrice = 0;
        $totalDiscountMarketplacePrice = 0;
        foreach ($targetOrder['items'] as $key => $item) {
            if ($item['isIncluded'] != true) {
                unset($targetOrder['items'][$key]);
                continue;
            }

            unset($targetOrder['items'][$key]['isIncluded']);
            if (isset($item['tax']['marketplace_price'])) {
                $totalTaxprice += $item['tax']['price'];
                $totalTaxMarketplacePrice += $item['tax']['marketplace_price'];
                $isTaxSet = true;
            }

            if (isset($item['discount']['marketplace_price'])) {
                $totalDiscountPrice += $item['discount']['price'];
                $totalDiscountMarketplacePrice += $item['discount']['marketplace_price'];
                $isDiscountSet = true;
            }

            if (isset($item['adjust_amount']['marketplace_price'])) {
                $totalAdjustPrice += $item['adjust_amount']['price'];
                $totalAdjustMarketplacePrice += $item['adjust_amount']['marketplace_price'];
                $isAdjustSet = true;
            }

            if (isset($item['refund_amount']['marketplace_price'])) {
                $totalRefundPrice += $item['refund_amount']['price'];
                $totalRefundMarketplacePrice += $item['refund_amount']['marketplace_price'];
                $isRefundSet = true;
            }
        }

        if (isset($isTaxSet)) {
            $targetOrder['tax'] = [
                'price' => $totalTaxprice,
                'marketplace_price' => $totalTaxMarketplacePrice
            ];
        }

        if (isset($isAdjustSet)) {
            $targetOrder['adjust_amount'] = [
                'price' => $totalAdjustPrice,
                'marketplace_price' => $totalAdjustMarketplacePrice
            ];
        }

        if (isset($isDiscountSet)) {
            $targetOrder['discount'] = [
                'price' => $totalDiscountPrice,
                'marketplace_price' => $totalDiscountMarketplacePrice
            ];
        }

        if (isset($isRefundSet)) {
            $targetOrder['refund_amount'] = [
                'price' => $totalRefundPrice,
                'marketplace_price' => $totalRefundMarketplacePrice
            ];
        }

        if (empty($targetOrder['items'])) {
            $this->errors[] = 'No items provided for refund';
            return ['success' => false, 'message' => 'No items provided for refund.'];
        }

        return ['success' => true, 'data' => $targetOrder];
    }

    public function getTargetOrder(array $order)
    {
        $id = new \MongoDB\BSON\ObjectId($order['id']);
        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $targetOrder = $mongo->findOne(['_id' => $id], $options);

        return $targetOrder;
    }

    public function getTargetRefund(array $targetOrder): void
    {
        $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $object_type = 'target_refund';
        if (strpos($targetOrder['object_type'], 'source') !== false) {
            $object_type = 'source_refund';
        }
    }

    public function checkRefundItems(array $refund_order, array $targetOrder): array
    {
        foreach ($targetOrder['items'] as $index => $target_item) {
            $item_total_price = 0;
            $item_total_marketplace_price = 0;
            $targetOrder['items'][$index]['isIncluded'] = false;
            foreach ($refund_order['items'] as $refund_item) {
                $hasMarketplaceIds = isset($refund_item['marketplace_item_id'], $target_item['marketplace_item_id']);

                if (
                    ($hasMarketplaceIds && $refund_item['marketplace_item_id'] === $target_item['marketplace_item_id']) ||
                    (!$hasMarketplaceIds && $refund_item['sku'] === $target_item['sku'])
                ) {
                    if (!isset($refund_item['refund_qty'])) {
                        $this->errors[] = [
                            'sku' => $refund_item['sku'],
                            'error' => 'Refund quantity required for this item.'
                        ];
                        continue;
                    }

                    if (!isset($refund_item['refund_amount'])) {
                        $this->errors[] = [
                            'sku' => $refund_item['sku'],
                            'error' => 'Refund amount required for this item.'
                        ];
                        continue;
                    }

                    if (isset($target_item['price']['marketplace_price'])) {
                        $item_total_price += $target_item['price']['price'] * $refund_item['refund_qty'];
                        $item_total_marketplace_price += $target_item['price']['marketplace_price'] * $refund_item['refund_qty'];
                    } else {
                        $item_total_price += $target_item['price'] * $refund_item['refund_qty'];
                        $item_total_marketplace_price += $target_item['marketplace_price'] * $refund_item['refund_qty'];
                    }

                    if (isset($target_item['tax'])) {
                        $item_total_price += ($target_item['tax']['price'] / $target_item['qty']) * $refund_item['refund_qty'];
                        $item_total_marketplace_price += ($target_item['tax']['marketplace_price'] / $target_item['qty']) * $refund_item['refund_qty'];
                        $targetOrder['items'][$index]['tax']['price'] = ($target_item['tax']['price'] / $target_item['qty']) * $refund_item['refund_qty'];
                        $targetOrder['items'][$index]['tax']['marketplace_price'] = ($target_item['tax']['marketplace_price'] / $target_item['qty']) * $refund_item['refund_qty'];
                    }

                    if (isset($target_item['discount'])) {
                        $item_total_price -= $target_item['discount']['price'];
                        $item_total_marketplace_price -= $target_item['discount']['marketplace_price'];
                    }

                    if (isset($target_item['shipping_charge'])) {
                        $item_total_price += $target_item['shipping_charge']['price'];
                        $item_total_marketplace_price += $target_item['shipping_charge']['marketplace_price'];
                    } elseif ((isset($targetOrder['shipping_charge']) && $targetOrder['shipping_charge'] > 0) || $refund_item['refund_amount'] > $item_total_marketplace_price) {
                        $itemShippingCharge = $this->getItemShipFromSource($targetOrder, $target_item['sku']);
                        $item_total_price +=  $targetOrder['items'][$index]['shipping_charge']['marketplace_price'] = $itemShippingCharge;
                        $item_total_marketplace_price += $targetOrder['items'][$index]['shipping_charge']['price'] = $this->getConnectorPrice($targetOrder['marketplace_currency'], $itemShippingCharge);
                    }

                    if ($refund_item['refund_amount'] > $item_total_marketplace_price) {
                        $this->errors[] = [
                            'sku' => $refund_item['sku'],
                            'error' => 'Refund amount cannot be more than order amount.',
                            'refund_amount' => $refund_item['refund_amount'],
                            'amount' => $item_total_marketplace_price
                        ];
                    }

                    $adjust_amount = $item_total_marketplace_price - $refund_item['refund_amount'];
                    $targetOrder['items'][$index]['isIncluded'] = true;
                    $targetOrder['items'][$index]['refund_amount'] = [
                        'marketplace_price' => $refund_item['refund_amount'],
                        'price' => $this->getConnectorPrice($targetOrder['marketplace_currency'], $refund_item['refund_amount'])
                    ];
                    $targetOrder['items'][$index]['adjust_amount'] = [
                        'marketplace_price' => $adjust_amount,
                        'price' => $this->getConnectorPrice($targetOrder['marketplace_currency'], $adjust_amount)
                    ];
                    if ($adjust_amount > 0) {
                        $targetOrder['items'][$index]['adjust_reason'] = 'manual refund';
                    }

                    $targetOrder['items'][$index]['qty'] = (int)$refund_item['refund_qty'];
                    if ($refund_item['refund_qty'] > $target_item['qty']) {
                        $this->errors[] = [
                            'sku' => $refund_item['sku'],
                            'error' => 'Refund quantity cannot be more than ordered quantity!!'
                        ];
                    }

                    if (isset($refund_item['refund_reason'])) {
                        $targetOrder['items'][$index]['customer_note'] = $refund_item['refund_reason'];
                    }

                    //unsetting id of items to prevent clash with _id
                    unset($targetOrder['items'][$index]['id']);

                    //adding item metadata
                    $targetOrder['items'][$index]['item_link_id'] = $target_item['item_link_id'] ?? '';
                    $targetOrder['items'][$index]['total_qty'] = $target_item['qty'] ?? 0;
                    $targetOrder['items'][$index]['total_refunded_qty'] = 0;
                    if (!empty($target_item['is_component'])) {
                        $targetOrder['items'][$index]['is_component'] = $target_item['is_component'];
                    }
                }
            }
        }

        return $targetOrder;
    }

    public function trimAdditionalData($data): array
    {
        //todo - validate all the trimmed keys
        $unrequiredData = [
            '_id' => 1,
            'shipping_address' => 1,
            'billing_address' => 1,
            'customer' => 1,
            'subtotal' => 1,
            'discount' => 1,
            'total' => 1,
            'tax' => 1,
            'taxes' => 1,
            'discounts' => 1,
            'shipping_charges' => 1,
            'targets' => 1,
            'updated_at' => 1,
            'created_at' => 1,
            'source_updated_at' => 1,
            'source_updated_at' => 1,
            'attributes' => 1,
            'sub_total' => 1,
            'shop_id' => 1,
            'shipping_status' => 1
        ];

        foreach ($data as $key => $value) {
            if (isset($unrequiredData[$key])) {
                unset($data[$key]);
            }
        }

        $data = $this->trimAdditionalDataInItems($data);

        return ['success' => true, 'data' => $data];
    }

    public function trimAdditionalDataInItems(array $data): array
    {
        $unrequiredItemsData = [
            'order_id' => 1,
            'cancelled_qty' => 1,
            'type' => 1,
            'order_id' => 1,
            'id' => 1,
            'attributes' => 1,
            'object_type' => 1,
            'refund_qty' => 1,
        ];
        foreach ($data['items'] as $key => $item) {
            foreach ($item as $itemIndex => $value) {
                if (isset($unrequiredItemsData[$itemIndex])) {
                    unset($data['items'][$key][$itemIndex]);
                }
            }
        }

        return $data;
    }

    public function getItemShipFromSource($targetOrder, $itemSku)
    {
        // $filter = [
        //     'cif_order_id' => $targetOrder['cif_order_id'],
        //     'object_type' => 'source_order',
        //     'items.sku' => $itemSku
        // ];
        $filter = [
            'user_id' => $targetOrder['user_id'] ?? $this->di->getUser()->id,
            'object_type' => 'source_order',
            'cif_order_id' => $targetOrder['cif_order_id'],
            'items.sku' => $itemSku
        ];
        if ($targetOrder['object_type'] == 'source_order') {
            $filter['object_type'] = 'target_order';
        }

        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $dbData = $mongo->find($filter, $options)->toArray();
        foreach ($dbData as $data) {
            foreach ($data['items'] as $item) {
                if ($item['sku'] == $itemSku) {
                    return $item['shipping_charge']['marketplace_price'];
                }
            }
        }

        return 0;
    }

    public function prepareCifSource(array $order): array
    {
        $reference_marketplace_id = (string)$this->target['_id'];
        $reference_shop_id = $order['marketplace_shop_id'];
        $order['object_type'] = 'cif_source_refund';
        $order['reference_marketplace'] = $order['marketplace'];
        $order['reference_marketplace_id'] = $reference_marketplace_id;
        $order['reference_shop_id'] = $reference_shop_id;
        $order['marketplace'] = 'cif';
        $order['marketplace_shop_id'] = 'cif';
        $order['marketplace_reference_id'] = 'cif_' . $order['marketplace_reference_id'];
        $order['marketplace_status'] = $order['status'];
        //mapping marketplace_status to actual items refunded
        $response = $this->getMarketplaceStatus($this->target, $order);
        if ($response['success']) {
            $order['marketplace_status'] = $response['data'] . ' Inprogress';
        }

        $order['status'] = $this->getCifStatus($order) . ' Inprogress';
        unset($order['refund_status']);
        $order['marketplace_transaction_id'] = 'cif_' . time();
        foreach ($order['items'] as $index => $item) {
            unset($order['items'][$index]['isIncluded']);
            // $order['items'][$index]['marketplace_item_id'] = $index;
        }

        return [$order];
    }

    public function getMarketplaceStatus(array $targetOrder, array $preparedData): array
    {
        //todo:update comparisons
        $marketplace_status = 'partially_refunded';
        foreach ($targetOrder['items'] as $target_item) {
            foreach ($preparedData['items'] as $prepared_item) {
                if (empty($prepared_item['sku']) ||  empty($prepared_item['qty'])) {
                    return ['success' => false, 'message' => 'Required param sku or qty missing.'];
                }

                if ($target_item['sku'] == $prepared_item['sku'] && $target_item['qty'] != $prepared_item['qty']) {
                    return ['success' => true, 'data' => $marketplace_status];
                }
            }
        }

        $marketplace_status = 'refunded';
        return ['success' => true, 'data' => $marketplace_status];
    }

    public function getCifStatus(array $preparedData): string
    {
        $status = 'Refund';
        if (strpos($preparedData['marketplace_status'], 'partial') !== false) {
            $status = 'Partially Refund';
        }

        return $status;
    }

    public function isRefundInProgress(array $data): array
    {
        $insertionData = [
            "process_inprogess" => 1,
            "object_type" => 'cif_source_refund',
            "marketplace_shop_id" => 'cif',
            "marketplace_reference_id" => 'cif_' . $data['marketplace_reference_id'],
            "marketplace" => 'cif',
            "cif_order_id" => $data['cif_order_id'],
            "user_id" => $data['user_id'],
        ];

        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $orderData = $mongo->findOne($insertionData, $options);
        if (isset($orderData['process_inprogess'])) {
            //handle case for manual order on same order
            // foreach ($orderData['items'] as $item) {
            //     foreach ($data['items'] as $refundItem) {
            //     }
            // }
            return ['success' => false, 'message' => $this->di->getLocale()->_('refund_in_progress')];
        }

        return ['success' => true, 'message' => 'Ok to process'];
    }

    public function prepareCifTarget(array $order, array $cifSourceRefund): array
    {
        // $reference_id = (string)$order['_id'];
        $reference_shop_id = $order['marketplace_shop_id'];
        $order['object_type'] = 'cif_target_refund';
        $order['marketplace'] = 'cif';
        $order['marketplace_shop_id'] = 'cif';
        $order['marketplace_reference_id'] = 'cif_' . $order['marketplace_reference_id'];
        // $order['reference_marketplace_id'] = $reference_id;
        $order['reference_shop_id'] = $reference_shop_id;
        $order['reference_marketplace'] = $order['marketplace'];
        // $order['marketplace_status'] = $order['status'];
        $order['marketplace_status'] = $cifSourceRefund['marketplace_status'];
        $order['status'] = $cifSourceRefund['status'];
        unset($order['refund_status']);
        $order['reference_id'] = $cifSourceRefund['reference_id'];
        foreach ($order['items'] as $index => $item) {
            unset($order['items'][$index]['isIncluded']);
            // $order['items'][$index]['marketplace_item_id'] = $index;
        }

        $order['targets'][0] = [
            'marketplace' => $cifSourceRefund['marketplace'],
            'order_id' => $cifSourceRefund['marketplace_reference_id'],
            'shop_id' => $cifSourceRefund['marketplace_shop_id'],
            'status' => $cifSourceRefund['status'],
            'refund_status' => $cifSourceRefund['status']
        ];
        unset($order['order_sync']);
        unset($order['order_refund']);
        unset($order['process']);
        return $order;
    }

    public function getConnectorPrice($marketplaceCurrency, $price): string
    {
        if (!isset($this->currencyObj)) {
            $this->currencyObj = $this->di->getObjectManager()->get(\App\Connector\Contracts\Currency\CurrencyInterface::class);
        }

        return  $this->currencyObj->convert($marketplaceCurrency, $price);
    }

    public function getBaseMongoAndCollection($collection)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        return $mongo->getCollection($collection);
    }

    public function getConnectorRefundService()
    {
        $connectorRefundService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class);
        return $connectorRefundService;
    }

    public function saveInLog($data, string $file): void
    {
        $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
        $user_id = $this->di->getUser()->id;
        $this->di->getLog()->logContent($time . PHP_EOL . 'message = ' . print_r($data, true), 'info', 'order/order-refund/' . $user_id . '/' . date("Y-m-d") . '/' . $file);
    }

    public function create(array $order): array
    {
        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $response = $mongo->insertOne($order);
        if (empty($response)) {
            return ['success' => false, 'message' => 'Something went wrong while attempting to save data.'];
        }

        return ['success' => true, 'data' => $response];
    }

    public function prepareForDb(array $order): array
    {
        return [];
    }

    //Not required here but needed for interface compatibility
    //but it is necessary in all other modules
    public function isFeedBased(): bool
    {
        return false;
    }

    public function hasResponseContent(): bool
    {
        return false;
    }

    //Not required here but needed for interface compatibility
    //but it is necessary in all other modules
    public function getSettings(array $order): array
    {
        return [];
    }

    public function tempLog($data, string $type = 'front-end-app-data'): void
    {
        $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
        $user_id = $this->di->getUser()->id;
        $div = '--------------------------------------------------------------------------------';
        $this->di->getLog()
            ->logContent(
                $div . PHP_EOL . $time . PHP_EOL . $type . ' = ' . print_r($data, true),
                'info',
                'order/order-refund/temp-all-refunds/'  . $user_id . '/' . date("Y-m-d") . '/in-manual-service.log'
            );
    }
}
