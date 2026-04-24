<?php

namespace App\Shopifyhome\Components\Order\Returns;

use Phalcon\Logger;
use App\Core\Components\Base;
use App\Shopifyhome\Components\Order\Returns\Utility;

class Request extends Base
{
    public const MARKETPLACE = 'shopify';

    public function process(array $data)
    {
        if (!isset($data['marketplace_reference_id'], $data['shop_id'])) {
            return [
                'success' => false,
                'message' => 'marketplace_reference_id or shop_id is required'
            ];
        }

        $cifOrder = $data['cif_order'];
        $shopifyOrderId = $data['marketplace_reference_id'];
        $rejectReasonCode = $data['return_request_code'] ?? "OTHER";
        $rejectNote = $data['return_note'] ?? 'Other';

        $returnableItems = $this->getReturnableItems($shopifyOrderId, $data['shop_id'], $cifOrder['items'], $rejectReasonCode, $rejectNote);

        if (!empty($data['items'])) {
            $orderItems = $cifOrder['items'];
            $matchedDbItems = array_values(array_filter($orderItems, fn($item) => in_array($item['item_link_id'], array_column($data['items'], 'item_link_id'))));
            $returnableItems = Utility::matchItems($matchedDbItems, $returnableItems);
        }

        if (count($returnableItems) == 0) {
            return [
                'success' => false,
                'message' => 'No returnable items found'
            ];
        }

        $autoApprove = false;
        if (isset($data['auto_approve']) && $data['auto_approve']) {
            $autoApprove = true;
        }

        $response = $this->requestReturn($shopifyOrderId, $data['shop_id'], $returnableItems, $autoApprove);

        if (!$response['success']) {
            return [
                'success' => false,
                'message' => $response['message'],
                'data' => [
                    'return_error' => $response['message'],
                ]
            ];
        }

        $data = [
            'marketplace_return_id' => $response['marketplace_return_id'],
            'return_reason_code' => $rejectReasonCode,
            'return_note' => $rejectNote,
            'marketplace_status' => $response['marketplace_status'],
            'returnable_items' => $returnableItems,
            'return_status' => $autoApprove ? 'Approved' : "Requested"
        ];

        $data['marketplace_status'] = strtolower((string) $data['marketplace_status']);

        return [
            'success' => true,
            'data' => $data
        ];
    }

    private function requestReturn($orderId, $shopId, $items, $autoApprove = false)
    {
        $utility = $this->di->getObjectManager()->get(Utility::class);
        $returnLineItems = array_map(function ($item) use ($autoApprove) {
            $data = [
                'quantity' => $item['quantity'],
                'fulfillmentLineItemId' => $item['fulfillmentLineItemId'],
                'returnReason' => $item['return_reason_code']
            ];
            if ($autoApprove) {
                $data['returnReasonNote'] = $item['return_note'] ?? $item['customer_note'];
            } else {
                $data['customerNote'] = $item['customer_note'];
            }

            return $data;
        }, $items);

        $params = [
            'shop_id' => $utility->getRemoteShopId($shopId),
            'data' => [
                'orderId' => "gid://shopify/Order/{$orderId}",
                'returnLineItems' => $returnLineItems
            ]
        ];

        $url = $autoApprove ? 'return/create' : 'return/request';

        $response = $utility->getClient()->call($url, [], $params, "POST", "json");

        if (!$response['success']) {
            $this->logItBro($response);
            return [
                'success' => false,
                'message' => $response['msg'] ?? 'Failed to request return'
            ];
        }

        $key = $autoApprove ? 'returnCreate' : 'returnRequest';

        return [
            'success' => true,
            'marketplace_return_id' => $response['data'][$key]['return']['id'],
            'marketplace_status' => $response['data'][$key]['return']['status']
        ];
    }

    private function getReturnableItems($orderId, $shopId, $dbItems, $rejectReasonCode, $rejectNote)
    {
        $utility = $this->di->getObjectManager()->get(Utility::class);
        $params = [
            'shop_id' => $utility->getRemoteShopId($shopId),
            'orderId' => "gid://shopify/Order/{$orderId}",
        ];
        $response = $utility->getClient()->call('return/fulfillments', [], $params);

        if (!isset($response['data']) || count($response['data']) == 0) {
            $this->logItBro($response);
            return [];
        }

        // filter items on the basis marketplace item id
        $filteredItems = array_filter($response['data'], fn($item) => in_array($utility->getLineItemId($item['lineItemId']), array_column($dbItems, 'marketplace_item_id')));

        $items = array_map(fn($item) => [
            'quantity' => $item['quantity'],
            'marketplace_item_id' => $utility->getLineItemId($item['lineItemId']),
            'fulfillmentLineItemId' => $item['fulfillmentLineItemId'],
            'customer_note' => $rejectNote,
            'return_note' => $rejectNote,
            'return_reason_code' => $rejectReasonCode
        ], $filteredItems);

        return $items;
    }


    public function logItBro($content)
    {
        $this->di->getLog()->logContent(json_encode($content), \Phalcon\Logger::INFO, date('Y-m-d') . "/" . $this->di->getUser()->id . "/shopify_return_request.log");
    }
}
