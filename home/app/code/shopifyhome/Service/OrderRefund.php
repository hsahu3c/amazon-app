<?php

/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Shopifyhome\Service;

use App\Connector\Contracts\Sales\Order\RefundInterface;
use App\Shopifyhome\Components\Order\Refund\OrderRefund as Refund;

class OrderRefund implements RefundInterface
{
    public $schemaObjectType;

    public $currency = null;

    public const SCHEMA_VERSION = '2.0';

    public const SHIPPING_TAX_CODE = 'ShippingTax';

    protected $di;

    public function refund($order): array
    {
        if(!isset($order['marketplace_reference_id'])) {
            return [
                'success' => false,
                'message' => 'Order not found'
            ];
        }

        return $this->di->getObjectManager()->create(Refund::class, [$order['marketplace_reference_id']])->create($order);
    }

    public function prepareForDb(array $order): array
    {
        if (!isset($order['shop_id'])) {
            return [
                'success' => false,
                'message' => 'Required parameter shop_id missing.',
            ];
        }

        $this->currency = null;
        $refundData['schema_version'] = self::SCHEMA_VERSION;
        $refundData['marketplace_shop_id'] = (string)$order['shop_id'];
        $refundData['marketplace'] = $order['marketplace'] ?? 'shopify';

        $refundData['user_id'] = $this->di->getUser()->id;

        if (empty($order['order_id'])) {
            return [
                'success' => false,
                'message' => 'Required parameter order_id missing in shopify data.',
            ];
        }

        $refundData['marketplace_reference_id'] = (string)$order['order_id'];

        if (empty($order['id'])) {
            return [
                'success' => false,
                'message' => 'Required parameter id missing.'
            ];
        }

        $refundData['marketplace_transaction_id'] = (string)$order['id'];

        if (isset($order['financial_status'])) {
            $refundData['marketplace_status'] = $order['financial_status'];
        }

        if (empty($order['refund_line_items'])) {
            $refundWithoutItemsData = $refundData;
            $refundWithoutItemsData['items'] = [];
            $withoutLineItemsData = $this->refundWithoutLineItems($order);
            $refundWithoutItemsData = array_merge($refundWithoutItemsData, $withoutLineItemsData);

            //if allow_refund_without_items is true, then prepare the refund items data from target order
            $allowRefundWithoutItems = $this->di->getConfig()->get('allow_refund_without_items');
            if ($allowRefundWithoutItems) {
                $preparedRefundItemsDataWithoutItems = $this->prepareRefundItemsDataForWithoutItems($refundWithoutItemsData);
                if (!empty($preparedRefundItemsDataWithoutItems)) {
                    $refundWithoutItemsData['items'] = $preparedRefundItemsDataWithoutItems;
                    $refundWithoutItemsData['is_refund_without_items'] = true;
                    unset($refundWithoutItemsData['adjust_amount'], $refundWithoutItemsData['adjust_reason'], $refundWithoutItemsData['adjust_amounts']);
                }
            }

            return [
                'success' => true,
                'data' => $refundWithoutItemsData,
            ];
        }

        $refundItems = [];
        foreach ($order['refund_line_items'] as $index => $item) {
            $refundItemResponse = $this->getItems($item);
            if ($refundItemResponse['success']) {
                array_push($refundItems, $refundItemResponse['data']);
            }
        }

        if (empty($refundItems)) {
            return [
                'success' => false,
                'message' => 'No items provided for refund.'
            ];
        }

        $refundData['items'] = $refundItems;

        if (isset($order['note'])) {
            $refundData['customer_note'] = $order['note'];
        }

        if (!empty($order['order_adjustments'])) {
            $refundData['adjust_amount']['price'] = $this->getAdjustAmount($order);
            $refundData['adjust_reason'] = $order['order_adjustments'][0]['reason'];
            if (isset($order['order_adjustments'][1])) {
                $refundData['adjust_reason'] = 'multiple';
            }

            $refundData['adjust_amounts'] = $this->getAllAdjustments($order['order_adjustments']);
            $isShipAdjustResponse = $this->isShippingAdjustmentAndItsAmount($order['order_adjustments']);
        }

        if (isset($isShipAdjustResponse) && $isShipAdjustResponse['success']) {
            $perItemShippingAdjustment = $isShipAdjustResponse['amount'] / count($order['refund_line_items']);
            $perItemAdjustmentTax = 0;
            if (!empty($isShipAdjustResponse['tax'])) {
                $perItemAdjustmentTax = $isShipAdjustResponse['tax'] / count($order['refund_line_items']);
            }

            foreach ($refundData['items'] as $itemIndex => $refundItem) {
                $refundData['items'][$itemIndex]['adjust_amount']['price'] = $perItemShippingAdjustment + $perItemAdjustmentTax;
                $refundData['items'][$itemIndex]['adjust_reason'] = 'Shipping refund';
                $refundData['items'][$itemIndex]['adjust_amounts'][] = [
                    'code' => 'shipping_refund',
                    'price' => $perItemShippingAdjustment + $perItemAdjustmentTax,
                    'reason' => 'Shipping refund'
                ];
                $refundData['items'][$itemIndex]['shipping_charge']['price'] = $perItemShippingAdjustment;
                $refundData['items'][$itemIndex]['refund_amount']['price'] += $perItemShippingAdjustment;

                $itemAlreadyHasShippingTax = array_filter($refundItem['taxes'] ?? [], function($tax) {
                    return $tax['code'] === self::SHIPPING_TAX_CODE;
                });

                if (!$itemAlreadyHasShippingTax) {
                    $refundData['items'][$itemIndex]['taxes'][] = [
                        'code' => self::SHIPPING_TAX_CODE,
                        'price' => $perItemAdjustmentTax,
                    ];
                    $refundData['items'][$itemIndex]['tax']['price'] += $perItemAdjustmentTax;
                    $refundData['items'][$itemIndex]['refund_amount']['price'] += $perItemAdjustmentTax;
                }
            }

            $refundData['shipping_charge']['price'] = $isShipAdjustResponse['amount'];
            //temp sol.
            $shippingTax = $isShipAdjustResponse['tax'] ?? null;
        }

        //currently handling only the case where it was free order, for partial payed orders
        //case need to be handled in the future
        if (empty($order['transactions'])) {
            foreach ($refundData['items'] as $index => $refundItem) {
                $refundData['items'][$index]['refund_amount'] = 0;
            }
        }

        $totalTax = 0;
        $totalRefund = 0;
        $totalTaxes = [];
        foreach ($refundData['items'] as $refundItem) {
            if (isset($refundItem['tax']['price'])) {
                $totalTax += $refundItem['tax']['price'];
            }

            if (isset($refundItem['refund_amount']['price'])) {
                $totalRefund += $refundItem['refund_amount']['price'];
            }

            if (isset($refundItem['taxes'])) {
                foreach ($refundItem['taxes'] as $itemTaxes) {
                    $taxCode = $itemTaxes['code'];
                    if (!isset($totalTaxes[$taxCode])) {
                        $totalTaxes[$taxCode] = 0;

                    }

                    $totalTaxes[$taxCode] += $itemTaxes['price'];
                }
            }
        }

        //temp sol
        // if (isset($shippingTax) && $shippingTax !== null) {
        //     $totalTax += $shippingTax;
        //     $totalTaxes[self::SHIPPING_TAX_CODE] = $shippingTax;
        //     if (count($refundData['items']) == 1) {
        //         $refundData['items'][0]['taxes'][] = ['price' => $shippingTax, 'code' => self::SHIPPING_TAX_CODE];
        //         $refundData['items'][0]['tax']['price'] += $shippingTax;
        //         $refundData['items'][0]['refund_amount']['price'] += $shippingTax;
        //     }
        // }

        if ($totalTax > 0) {
            $refundData['tax']['price'] = $totalTax;
        }

        foreach ($totalTaxes as $taxCode => $taxPrice) {
            $refundData['taxes'][] = [
                'code' => $taxCode,
                'price' => $taxPrice
            ];
        }

        $refundData['refund_amount']['price'] = $totalRefund;

        //temp capping refund amt to transaction amt at marketplace
        $refundData['refund_amount']['price'] = $this->getTransactionAmount($order['transactions']);

        // $validateRefundAmountResponse = $this->validateRefundAmountResponse($order['transactions'], $totalRefund);

        $refundData['currency'] = $this->currency;
        $refundData['source_created_at'] = $order['created_at'];
        $refundData['source_updated_at'] = $order['processed_at'];
        // $refundData['cif_order_id'] = $order['cif_order_id'];
        // $refundData['status'] = $order['status'];
        if(isset($order['cif_order_id']) && !empty($order['cif_order_id'])) {
            $refundData['cif_order_id'] = $order['cif_order_id'];
        }

        if(isset($order['status']) && !empty($order['status'])) {
            $refundData['status'] = $order['status'];
        }

        $refundData['marketplace_status'] = $order['status'] ?? 'Refund';

        if(isset($order['is_webhook']) && $order['is_webhook'] === false) {
            $refundData['object_type'] = 'target_refund';
            return $refundData;
        }

        $refundData['object_type'] = 'source_refund';
        return [
            'success' => true,
            'data' => $refundData,
        ];
    }

    public function create(array $order): array
    {
        return [];
    }

    public function getItems($item)
    {
        if (empty($item)) {
            return [
                'success' => false,
                'message' => 'item empty'
            ];
        }

        if (isset($item['line_item_id'])) {
            $itemData['marketplace_item_id'] = (string)$item['line_item_id'];
        }

        if (isset($item['line_item']['title'])) {
            $itemData['title'] = $item['line_item']['title'];
        }

        $itemData['sku'] = null;
        if (isset($item['line_item']['sku'])) {
            $itemData['sku'] = $item['line_item']['sku'];
        }

        if (isset($item['quantity'])) {
            $itemData['qty'] = (int)$item['quantity'];
        }

        if (isset($item['line_item']['price_set'])) {
            $itemData['price'] = $item['line_item']['price_set']['shop_money']['amount'];
            $itemData['currency'] = $item['line_item']['price_set']['shop_money']['currency_code'];
        }

        if (!isset($this->currency) && isset($itemData['currency'])) {
            $this->currency = $itemData['currency'];
        }

        if (isset($item['line_item']['note'])) {
            $itemData['customer_note'] = $item['line_item']['note'];
        }

        if (isset($item['subtotal']) && isset($item['total_tax'])) {
            $itemData['refund_amount']['price'] = $item['subtotal'] + $item['total_tax'];
            $itemData['tax']['price'] = $item['total_tax'];
        }

        if (!empty($item['line_item']['tax_lines'])) {
            foreach ($item['line_item']['tax_lines'] as $taxLine) {
                $taxLine['price'] = $taxLine['price'] / $item['line_item']['quantity'] * $item['quantity'];
                $itemData['taxes'][] = ['code' => $taxLine['title'], 'price' => $taxLine['price']];
            }
        }

        // if (!empty($item['line_item']['discount_allocations'])) {
        //     foreach ($item['line_item']['discount_allocations'] as $discount) {
        //         $itemData['discounts'][] = ['code' => $discount['title'], 'price' => $discount['price']]; //need to handle marketplace_price;
        //     }
        // }
        if (isset($item['total_discount_set'])) {
            $itemData['discount']['price'] = $item['total_discount_set']['shop_money']['amount'];
        }

        return [
            'success' => true,
            'data' => $itemData
        ];
    }

    public function refundWithoutLineItems($order)
    {
        if (isset($order['note'])) {
            $refundData['customer_note'] = $order['note'];
        }

        if (!empty($order['order_adjustments'])) {
            $refundData['adjust_amount']['price'] = $this->getAdjustAmount($order);
            $refundData['adjust_reason'] = $order['order_adjustments'][0]['reason'] ?? '';
            if (isset($order['order_adjustments'][1])) {
                $refundData['adjust_reason'] = 'multiple';
            }

            $refundData['adjust_amounts'] = $this->getAllAdjustments($order['order_adjustments']);
            $isShipAdjustResponse = $this->isShippingAdjustmentAndItsAmount($order['order_adjustments']);
        }

        if (isset($isShipAdjustResponse) && $isShipAdjustResponse['success']) {
            $refundData['shipping_charge']['price'] = $isShipAdjustResponse['amount'];
        }

        $refundData['currency'] = $order['total_duties_set']['shop_money']['currency_code'] ?? '';
        $refundData['refund_amount']['price'] = 0;
        foreach ($order['transactions'] as $transaction) {
            if ($transaction['currency'] != $refundData['currency']) {
                continue;
            }

            if ($transaction['kind'] != 'refund') {
                continue;
            }

            $refundData['refund_amount']['price'] += $transaction['amount'] ?? 0;
        }

        $refundData['source_created_at'] = $order['created_at'];
        $refundData['source_updated_at'] = $order['processed_at'];
        return $refundData;

    }

    //work in progress
    // public function validateRefundAmountResponse($transactions, $totalRefund)
    // {
    //     $totalTransactionAmount = 0;
    //     foreach ($transactions as $transaction) {
    //         $totalTransactionAmount += $transaction['amount'] ?? 0;
    //     }
    //     if ($totalTransactionAmount == $totalRefund) {
    //         return [
    //             'success' => true,
    //             'data' => $totalRefund
    //         ];
    //     }

    // }

    public function getTransactionAmount($transactions)
    {
        $totalTransactionAmount = 0;
        foreach ($transactions as $transaction) {
            if ($transaction['kind'] != 'refund') {
                continue;
            }

            $totalTransactionAmount += $transaction['amount'] ?? 0;
        }

        return $totalTransactionAmount;
    }

    public function getAdjustAmount($data)
    {
        $adjustAmount = 0;
        $adjustKind = [];
        if (!empty($data['order_adjustments'])) {
            foreach ($data['order_adjustments'] as $adjustment) {
                if (!isset($adjustKind[$adjustment['kind']])) {
                    $adjustAmount += abs($adjustment['amount']) + abs($adjustment['tax_amount']);
                    $adjustKind[$adjustment['kind']] = $adjustment['kind'];
                }
            }
        }

        return $adjustAmount;
    }

    public function getAllAdjustments($adjustments)
    {
        $allAdjustments = [];
        foreach ($adjustments as $adjustment) {
            $allAdjustments[] = [
                'code' => $adjustment['kind'],
                'price' => abs($adjustment['amount']) + abs($adjustment['tax_amount']),
                'reason' => $adjustment['reason']
            ];
        }

        return $allAdjustments;
    }

    public function isShippingAdjustmentAndItsAmount($adjustments)
    {
        foreach ($adjustments as $adjustment) {
            if ($adjustment['kind'] == 'shipping_refund') {
                return [
                    'success' => true,
                    'amount' => abs($adjustment['amount']),
                    'tax' => abs($adjustment['tax_amount']),
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'No shipping_refund'
        ];
    }
    private function prepareRefundItemsDataForWithoutItems($refundData)
    {
        $orderContainer = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollection('order_container');
        $order = $orderContainer->findOne(
            [
                'user_id' => $this->di->getUser()->id,
                'marketplace' => $refundData['marketplace'],
                'marketplace_shop_id' => $refundData['marketplace_shop_id'],
                'marketplace_reference_id' => $refundData['marketplace_reference_id']
            ],
            [
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ]
        );

        if (empty($order)) {
            return [];
        }

        $priceAdjustRatio = $refundData['refund_amount']['price'] / $order['total']['price'];

        if ($refundData['refund_amount']['price'] >= $order['sub_total']['price']) {
            $priceAdjustRatio = 1;
        }

        $itemsToRefund = [];

        foreach ($order['items'] as $item) {
            if (empty($item['refund_qty'])) {
                unset(
                    $item['type'],
                    $item['cancelled_qty'],
                    $item['product_identifier'],
                    $item['parent_product_identifier'],
                    $item['discounts'],
                    $item['discount'],
                    $item['attributes'],
                    $item['marketplace_price'],
                    $item['schema_version'],
                    $item['cif_order_id'],
                    $item['user_id'],
                    $item['object_type'],
                    $item['order_id'],
                    $item['id'],
                    $item['tax']['marketplace_price'],
                    $item['taxes'][0]['marketplace_price']
                );
                $item['refund_amount']['price'] = (($item['price'] * $item['qty']) + ($item['tax']['price'] ?? 0)) * $priceAdjustRatio;
                $item['currency'] = $refundData['currency'];
                $itemsToRefund[] = $item;
            }
        }

        return $itemsToRefund;
    }

    public function getRefundReasons()
    {
        $reasons = [
            "Customer Return",
            "Seller Refund"
        ];
        return $reasons;
    }

    public function getSettings(array $order): array
    {
        //need to update
        return [];
    }

    public function isFeedBased(): bool
    {
        return false;
    }

    public function hasResponseContent(): bool
    {
        return true;
    }
}
