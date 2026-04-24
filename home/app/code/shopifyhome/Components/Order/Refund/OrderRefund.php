<?php

namespace App\Shopifyhome\Components\Order\Refund;
use App\Core\Components\Base;
class OrderRefund extends Base
{
    private $orderId;
    protected $di;
    public function __construct($orderId)
    {
        $this->di = \Phalcon\Di::getDefault();
        $this->orderId = $orderId;
    }

    public function create($refundData)
    {
        $shops = $this->di->getUser()->getShops() ?? [];
        $helper = $this->di->getObjectManager()->get(Helper::class, [$shops]);
        if (!isset($refundData['items'])) {
            return [
                'success' => false,
                'message' => 'No items found',
                'data' => $refundData
            ];
        }

        $items = $refundData['items'];
        $refundedLineItems = $this->getRefundLineItems($helper, $items);
        if(!$refundedLineItems['success']) {
            return [
                'success' => false,
                'message' => $refundedLineItems['message'] ?? 'No refund line items found',
                'data' => $refundData
            ];
        }

        $saleTransaction = $this->di->getObjectManager()->get(OrderTransactions::class, [$this->orderId])
            ->getSaleTransaction();

        if(!isset($saleTransaction)) {
            return [
                'success' => false,
                'message' => 'No sale transaction found',
                'data' => $refundData
            ];
        }

        $transaction = [
            'parent_id' => $saleTransaction['id'],
            'amount' => $refundData['refund_amount']['marketplace_price'] ?? $saleTransaction['amount'],
            'kind' => 'refund',
            'gateway' => $saleTransaction['gateway']
        ];

        $note = $refundData['customer_note'] ?? "";
        $currency = $refundData['marketplace_currency'] ?? "USD";
        $request = [
            'order_id' => $this->orderId,
            'refund' => $this->format($refundedLineItems['data'], $transaction, $currency, $note)
        ];
        $response = $helper->request('order/refundCreate', $request, 'POST');
        if(isset($response['success']) && !$response['success']) {
            return [
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to create order on Shopify',
                'data' => $refundData
            ];
        }

        if(!isset($response['data']['refund'])) {
            return [
                'success' => false,
                'message' => 'No refund data found',
                'data' => $refundData
            ];
        }

        $createdRefund = $response['data']['refund'];
        $createdRefund['shop_id'] = $refundData['marketplace_shop_id'];
        $createdRefund['is_webhook'] = false;
        $createdRefund = array_merge($refundData, $createdRefund);

        return [
            'success' => true,
            'message' => 'Refund created successfully',
            'data' => $createdRefund
        ];
    }

    public function getRefundLineItems($helper, $refundedItems)
    {
        $request = [
            'order_id' => $this->orderId
        ];
        $response = $helper->request('get/ordershipment', $request, 'GET');
        if (isset($response['success']) && !$response['success']) {
            return $response;
        }

        if (!isset($response['data']['fulfillments'])) {
            return [
                'success' => false,
                'message' => 'No fulfillments found'
            ];
        }

        $fulfillments = $response['data']['fulfillments'] ?? [];

        $lineItemIds = array_column($refundedItems, 'marketplace_item_id');
        $groupedItems = [];
        foreach ($fulfillments as $fulfillment) {
            foreach ($fulfillment['line_items'] as $lineItem) {
                $lineItemId = $lineItem['id'];
                if (!in_array($lineItemId, $lineItemIds)) {
                    continue;
                }

                $locationId = $fulfillment['location_id'];
                $quantity = $lineItem['quantity'];

                $groupedItems[$lineItemId] = [
                    'location_id' => $locationId,
                    'quantity' => $quantity
                ];
            }
        }

        $refundedLineItems = [];
        foreach ($groupedItems as $lineItemId => $item) {
            $refundedLineItems[] = [
                "line_item_id" => $lineItemId,
                "quantity" => $item['quantity'],
                "restock_type" => "return",
                "location_id" => $item['location_id']
            ];
        }

        if($refundedLineItems === []) {
            return [
                'success' => false,
                'message' => 'No refund line items found'
            ];
        }

        return [
            'success' => true,
            'data' => $refundedLineItems
        ];
    }

    public function format($refundedLineItems, $transaction, $currency, $note)
    {
        return [
            "currency" => $currency,
            "notify" => true,
            "note" => $note,
            "shipping" => [
                "full_refund" => true
            ],
            "refund_line_items" => $refundedLineItems,
            "transactions" => [
                $transaction
            ]
        ];
    }
}
