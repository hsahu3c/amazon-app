<?php

namespace App\Connector\Components\Order\OrderReturn;

use App\Connector\Contracts\Sales\Order\ReturnInterface;
use App\Connector\Models\OrderContainer;
use App\Connector\Components\Order\OrderReturn\ReturnProcessor;
use App\Core\Components\Base;

class DeclineCommand extends Base implements Command
{
    private $data;

    private $marketplace;

    protected $di;

    const SOURCE_ORDER = 'source_order';

    const SOURCE_RETURN = 'source_return';

    const DECLINED_STATUS = 'Rejected';

    public function __construct($data, $marketplace)
    {
        $this->di = \Phalcon\Di::getDefault();
        $this->data = $data;
        $this->marketplace = $marketplace;
    }

    public function rejectReturn(array $data): array
    {
        return $this->process($data, $this->marketplace, function ($data): void {
            $this->finalize($data);
        });
    }

    private function finalize(array $data): void
    {
        $this->updateOrder($data);
        $this->updateOrderReturn($data);
    }

    private function updateOrder(array $data): void
    {
        $this->di->getObjectManager()->get(OrderContainer::class)->getCollection()->updateOne(
            [
                'cif_order_id' => $data['cif_order_id'],
                'marketplace' => $this->marketplace,
                'shop_id' => $this->data['shop_id'],
                'object_type' => $data['type'] . "_order"
            ],
            [
                '$set' => [
                    'return_status' => self::DECLINED_STATUS,
                ]
            ]
        );
    }

    private function updateOrderReturn(array $data): void
    {
        $this->di->getObjectManager()->get(OrderContainer::class)->getCollection()->updateOne(
            [
                'cif_order_id' => $data['cif_order_id'],
                'object_type' => $data['type'] . "_return",
                'marketplace_return_id' => $data['data']['marketplace_return_id']
            ],
            [
                '$set' => [
                    'marketplace_status' => $data['data']['marketplace_status'] ?? self::DECLINED_STATUS,
                    'return_status' => self::DECLINED_STATUS,
                    'source_updated_at' => $data['data']['source_updated_at'] ?? date('c'),
                    'reject_reason_code' => $data['data']['reject_reason_code'],
                    'reject_note' => $data['data']['reject_note'] ?? ""
                ],
                '$unset' => [
                    'reject_error' => ''
                ]
            ]
        );
    }

    private function process(array $data, $marketplace, \Closure $callback, bool $processTargets = true)
    {
        $marketplaceOrderReturn = $this->di->getObjectManager()->get(ReturnInterface::class, [], $marketplace);
        $response = $marketplaceOrderReturn->reject($data);
        if (!$response['success']) {
            if (isset($response['data']['reject_error'], $response['data']['marketplace_return_id'])) {
                $this->updateError($response['data']['marketplace_return_id'], $response['data']['reject_error']);
            }

            return $response;
        }

        if (!isset($response['data']['marketplace_return_id'])) {
            return [
                'success' => false,
                'message' => 'marketplace_reference_id or shop_id is required'
            ];
        }

        if (!isset($response['data']['reject_reason_code'])) {
            return [
                'success' => false,
                'message' => 'reject_reason_code is required'
            ];
        }

        $cifOrder = OrderContainer::findFirst(
            [
                [
                    'marketplace_return_id' => $response['data']['marketplace_return_id'],
                    'marketplace' => $marketplace,
                    'shop_id' => $data['shop_id'],
                    'user_id' => $this->di->getUser()->id,
                    'object_type' => [
                        '$regex' => '_return$'
                    ]
                ],
                'typeMap' => [
                    'root' => 'array',
                    'document' => 'array'
                ]
            ]
        );

        if (!$cifOrder) {
            return [
                'success' => false,
                'message' => 'No return order found'
            ];
        }

        $type = $cifOrder->object_type === 'source_return' ? 'source' : 'target';

        $items = OrderContainer::find(
            [
                [
                    '_id' => [
                        '$in' => $cifOrder->itemIds
                    ]
                ],
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ]
        );

        if (!isset($items)) {
            return [
                'success' => false,
                'message' => 'No items found'
            ];
        }

        $items = $items->toArray();
        $returnProcessor = new ReturnProcessor([
            'cif_order_id' => $cifOrder->cif_order_id,
        ], $items, $type);
        $itemsWithCifOrderId = array_map(function ($item) use ($response, $data): array {
            return [
                'item_status' => self::DECLINED_STATUS,
                'cif_order_id' => $item['cif_order_id'],
                'marketplace_item_id' => $item['marketplace_item_id'],
                'marketplace_status' => $item['marketplace_status'] ?? $response['data']['marketplace_status'],
                'reject_reason_code' => $response['data']['reject_reason_code'] ?? 'OTHER',
                'reject_note' => $response['data']['reject_note'] ?? ""
            ];
        }, $items);
        $result = $returnProcessor->bulkUpdate($itemsWithCifOrderId);
        if (!$result) {
            return [
                'success' => false,
                'message' => 'Items are already rejected'
            ];
        }

        if ($processTargets) {
            $order = OrderContainer::findFirst(
                [
                    [
                        'marketplace_return_id' => $response['data']['marketplace_return_id'],
                        'shop_id' => $data['shop_id'],
                        'marketplace' => $marketplace,
                        'object_type' => $type . "_return"
                    ],
                    'typeMap' => ['root' => 'array', 'document' => 'array']
                ]
            );
            $order = $order->toArray();
            if ($order === null) {
                return [
                    'success' => false,
                    'message' => 'No order found'
                ];
            }

            $targets = $order['targets'] ?? [];
            $data['reject_reason_code'] = $response['data']['reject_reason_code'];
            $data['reject_note'] = $response['data']['reject_note'] ?? "";
            foreach ($targets as $target) {
                $returnReason = $marketplaceOrderReturn->getRejectReason($data, $target);
                if (!isset($returnReason['reject_reason_code'], $returnReason['reject_note'])) {
                    continue;
                }

                $payload = [
                    'marketplace_reference_id' => $target['order_id'],
                    'shop_id' => $target['shop_id'],
                    'marketplace' => $target['marketplace'],
                    'reject_reason_code' => $returnReason['reject_reason_code'] ?? 'OTHER',
                    'reject_note' => $returnReason['reject_note'] ?? "",
                    'marketplace_return_id' => $target['marketplace_return_id']
                ];
                $targetResponse = $this->processTargets($payload, $target['marketplace']);
                if (isset($targetResponse['success']) && !$targetResponse['success']) {
                    $this->di->getLog()->logContent(json_encode($targetResponse), 'info', date('Y-m-d') . "order_return_error.log");
                    return $targetResponse;
                }
            }
        }

        $response['type'] = $type;
        $response['cif_order_id'] = $cifOrder->cif_order_id;
        $response['shop_id'] = $cifOrder->shop_id;
        $callback($response);
        return [
            'success' => true,
            'message' => 'Order return declined'
        ];
    }

    private function updateError($marketplaceReturnId, $errorMessage)
    {
        return $this->di->getObjectManager()->get(OrderContainer::class)->getCollection()->updateOne(
            [
                'object_type' => [
                    '$regex' => '_return$'
                ],
                'user_id' => $this->di->getUser()->id,
                'marketplace_return_id' => $marketplaceReturnId
            ],
            [
                '$set' => [
                    'reject_error' => $errorMessage,
                ]
            ]
        );
    }

    private function processTargets(array $payload, $marketplace)
    {
        return $this->process($payload, $marketplace, function ($data): void {
            $this->finalize($data);
        }, false);
    }

    public function execute(): array
    {
        return $this->rejectReturn($this->data);
    }
}
