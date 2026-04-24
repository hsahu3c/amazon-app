<?php

namespace App\Connector\Components\Order\OrderReturn;

use App\Connector\Contracts\Sales\Order\ReturnInterface;
use App\Connector\Models\OrderContainer;
use App\Connector\Components\Order\OrderReturn\ReturnProcessor;
use App\Core\Components\Base;

class AcceptCommand extends Base implements Command
{
    private $data;

    private $marketplace;

    protected $di;

    const SOURCE_ORDER = 'source_order';

    const SOURCE_RETURN = 'source_return';

    const ACCEPTED_STATUS = "Accepted";

    public function __construct($data, $marketplace)
    {
        $this->di = \Phalcon\Di::getDefault();
        $this->data = $data;
        $this->marketplace = $marketplace;
    }

    public function acceptReturn(array $data): array
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
                'marketplace' => $data['marketplace'],
                'object_type' => $data['type'] . "_order"
            ],
            [
                '$set' => [
                    'return_status' => self::ACCEPTED_STATUS,
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
                    'marketplace_status' => $data['data']['marketplace_status'] ?? 'Accepted',
                    'return_status' => self::ACCEPTED_STATUS,
                    'source_updated_at' => $data['data']['source_updated_at'] ?? date('c'),
                ],
                '$unset' => [
                    'approve_error' => ''
                ]
            ]
        );
    }

    private function process(array $data, $marketplace, \Closure $callback, bool $processTargets = true)
    {
        $marketplaceOrderReturn = $this->di->getObjectManager()->get(ReturnInterface::class, [], $marketplace);

        $response = $marketplaceOrderReturn->accept($data);
        if (!$response['success']) {
            if (isset($response['data']['approve_error'], $response['data']['marketplace_return_id'])) {
                $this->updateError($response['data']['marketplace_return_id'], $response['data']['approve_error']);
            }

            return $response;
        }

        $marketplaceReturnId = $response['data']['marketplace_return_id'] ?? null;

        if (!isset($marketplaceReturnId)) {
            return [
                'success' => false,
                'message' => 'marketplace_return_id not found'
            ];
        }

        $cifOrder = OrderContainer::findFirst(
            [
                [
                    'marketplace_return_id' => $marketplaceReturnId,
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
        $itemsWithCifOrderId = array_map(function ($item) use ($response): array {
            return [
                'item_status' => $response['data']['status'] ?? 'Accepted',
                'cif_order_id' => $item['cif_order_id'],
                'marketplace_item_id' => $item['marketplace_item_id'],
                'marketplace_status' => $item['marketplace_status'] ?? $response['data']['marketplace_status'],
            ];
        }, $items);

        $result = $returnProcessor->bulkUpdate($itemsWithCifOrderId);
        if (!$result) {
            return [
                'success' => false,
                'message' => 'Items are already approved for return'
            ];
        }

        if ($processTargets) {
            $order = OrderContainer::findFirst(
                [
                    [
                        'marketplace_return_id' => $marketplaceReturnId,
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
            foreach ($targets as $target) {
                $payload = [
                    'marketplace_reference_id' => $target['order_id'],
                    'shop_id' => $target['shop_id'],
                    'marketplace' => $target['marketplace'],
                    'marketplace_return_id' => $target['marketplace_return_id']
                ];
                $targetResponse = $this->processTargets($payload, $target['marketplace']);
                if (isset($targetResponse['success']) && !$targetResponse['success']) {
                    return $targetResponse;
                }
            }
        }

        $response['type'] = $type;
        $response['cif_order_id'] = $cifOrder->cif_order_id;
        $response['shop_id'] = $cifOrder->shop_id;
        $response['marketplace'] = $cifOrder->marketplace;
        $callback($response);
        return [
            'success' => true,
            'message' => 'Order return approved'
        ];
    }

    private function processTargets(array $payload, $marketplace)
    {
        return $this->process($payload, $marketplace, function ($data): void {
            $this->finalize($data);
        }, false);
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
                    'approve_error' => $errorMessage,
                    // 'marketplace_status' => $data['data']['marketplace_status'] ?? 'Error',
                ]
            ]
        );
    }

    public function execute(): array
    {
        return $this->acceptReturn($this->data);
    }
}
