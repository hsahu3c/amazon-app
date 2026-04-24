<?php

namespace App\Connector\Components\Order\OrderReturn;

use App\Connector\Models\OrderContainer;
use App\Connector\Contracts\Sales\Order\ReturnInterface;
use App\Connector\Components\Order\OrderReturn\ReturnProcessor;
use App\Core\Components\Base;

class RequestCommand extends Base implements Command
{
    protected $di;

    private $data;

    private $marketplace;

    const SOURCE_ORDER = 'source_order';

    const REQUESTED_STATUS = 'Requested';

    public function __construct($data, $marketplace)
    {
        $this->di = \Phalcon\Di::getDefault();
        $this->data = $data;
        $this->marketplace = $marketplace;
    }

    private function createReturnRequest(): array
    {
        return $this->process($this->data, $this->marketplace, function ($data): void {
            $this->finalize($data);
        });
    }

    private static function appendItemData(array $items, array $dbItems, string $itemStatus): array
    {
        $itemsWithProductIdentifiers = [];
        foreach ($items as $item) {
            $marketplaceItemId = $item['marketplace_item_id'];
            foreach ($dbItems as $dbItem) {
                if ($dbItem['marketplace_item_id'] == $marketplaceItemId) {
                    if (!isset($dbItem['item_link_id'])) {
                        continue;
                    }

                    $item['item_link_id'] = $dbItem['item_link_id'];
                    $item['item_status'] = $itemStatus;
                    break;
                }
            }

            if (empty($item['item_link_id'])) {
                continue;
            }

            $itemsWithProductIdentifiers[] = $item;
        }

        return $itemsWithProductIdentifiers;
    }

    private function processTargetOrder($target, $returnedProductIdentifiers)
    {
        $target['marketplace_reference_id'] = $target['order_id'] ?? "";
        $target['shop_id'] ??= "";
        $target['items'] = $returnedProductIdentifiers;

        return $this->process($target, $target['marketplace'], function ($data): void {
            $this->finalize($data);
        }, false);
    }

    private function getOrderByMarketplaceReferenceId($marketplaceReferenceId, $marketplace, $shopId)
    {
        return OrderContainer::findFirst(
            [
                [
                    'marketplace_reference_id' => $marketplaceReferenceId,
                    'shop_id' => $shopId,
                    'user_id' => $this->di->getUser()->id,
                    'marketplace' => $marketplace,
                    'object_type' => [
                        '$regex' => '_order$'
                    ],
                ],
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ]
        );
    }

    private function finalize(array $data)
    {
        $result = $this->di->getObjectManager()->get(OrderContainer::class)
            ->getCollection()
            ->updateOne(
                [
                    'marketplace_return_id' => $data['marketplace_return_id'],
                    'marketplace' => $data['marketplace'],
                    'cif_order_id' => $data['cif_order_id'],
                    'object_type' => $data['object_type']
                ],
                [
                    '$set' => [
                        'return_status' => $data['return_status'],
                    ],
                    '$unset' => [
                        'return_error' => ''
                    ]
                ]
            );
        return $result;
    }

    private function process($payload, $marketplace, \Closure $callback, bool $processTargets = true)
    {
        $marketplaceOrderReturn = $this->di->getObjectManager()->get(ReturnInterface::class, [], $marketplace);
        $defaultValues = [
            'success' => false,
            'data' => [],
            'message' => 'Something went wrong. Please try again later.'
        ];

        $cifOrder = $this->getOrderByMarketplaceReferenceId($payload['marketplace_reference_id'], $marketplace, $payload['shop_id']);
        if (!$cifOrder) {
            return [
                'success' => false,
                'message' => 'Order not found'
            ];
        }

        $payload['cif_order'] = $cifOrder->toArray();
        $response = $marketplaceOrderReturn->request($payload);
        $response = array_merge($defaultValues, $response);

        list(
            'success' => $success,
            'data' => $data,
            'message' => $message
        ) = $response;

        if (!$success) {
            return [
                'success' => false,
                'message' => $message
            ];
        }

        if (!$cifOrder) {
            return [
                'success' => false,
                'message' => 'Order not found'
            ];
        }

        $cifOrder = $cifOrder->toArray();

        $items = $data['returnable_items'] ?? [];
        $type = $cifOrder['object_type'] === self::SOURCE_ORDER ? "source" : "target";
        $returnData = [
            'marketplace_return_id' => $data['marketplace_return_id'],
            'cif_order_id' => $cifOrder['cif_order_id'],
            'return_status' => $data['return_status'] ?? 'Requested',
            'user_id' => $payload['user_id'] ?? $this->di->getUser()->id,
            'shop_id' => $payload['shop_id'],
            'source_created_at' => $data['source_created_at'] ?? date('c'),
            'source_updated_at' => $data['source_updated_at'] ?? date('c'),
            'marketplace' => $marketplace,
            'object_type' => $cifOrder['object_type'],
            'marketplace_reference_id' => $payload['marketplace_reference_id'],
            'marketplace_status' => $data['marketplace_status'] ?? 'error',
            'return_reason_code' => $data['return_reason_code'] ?? 'OTHER',
            'return_note' => $data['return_note'] ?? "",
        ];

        if (isset($data['return_error'])) {
            $returnData['return_error'] = $data['return_error'];
            $returnData['return_status'] = "Error";
            unset($data['return_reason_code'], $data['return_note']);
        }

        $itemsWithProductIdentifiers = self::appendItemData($items, $cifOrder['items'], $data['return_status'] ?? 'Requested');

        $returnProcessor = new ReturnProcessor($returnData, $itemsWithProductIdentifiers, $type);
        $returnedProductIdentifiers = $returnProcessor->create();

        if (!empty($returnProcessor->getErrors())) {
            return [
                'success' => false,
                'message' => $returnProcessor->getErrors()
            ];
        }

        if (isset($data['return_error']) && !empty($data['return_error'])) {
            return [
                'success' => false,
                'message' => $data['return_error']
            ];
        }

        if (empty($returnedProductIdentifiers)) {
            return [
                'success' => false,
                'message' => 'Return request could not be created'
            ];
        }

        if ($processTargets) {
            $targets = $cifOrder['targets'];
            if (empty($targets) === 0) {
                return [
                    'success' => false,
                    'message' => 'No targets found'
                ];
            }

            foreach ($targets as $target) {
                $requestReason = $marketplaceOrderReturn->getRequestReason($returnData, $target);

                if (!isset($requestReason['return_reason_code'], $requestReason['return_note'])) {
                    continue;
                }

                $target['return_reason_code'] = $requestReason['return_reason_code'];
                $target['return_note'] = $requestReason['return_note'];
                $target['auto_approve'] = isset($data['auto_approve']) ? $data['auto_approve'] : false;
                $targetResponse = $this->processTargetOrder($target, $returnedProductIdentifiers, $type);
                if ($targetResponse['success'] === false) {
                    $targetResponse['marketplace'] = $target['marketplace'];
                    $returnData['return_error'] = $targetResponse['data']['return_error'] ?? "Failed to create return on {$marketplace}";
                    return $targetResponse;
                }

                if (!isset($targetResponse['marketplace_return_id'])) {
                    continue;
                }

                $target['marketplace_return_id'] = $targetResponse['marketplace_return_id'];

                $returnProcessor->addReturnToSourceOrTarget([
                    [
                        'filter' => [
                            'marketplace_return_id' => $target['marketplace_return_id'],
                            'shop_id' => $target['shop_id'],
                            'marketplace' => $target['marketplace'],
                            'user_id' => $this->di->getUser()->id,
                            'object_type' => [
                                '$regex' => '_return$'
                            ]
                        ],
                        'data' => [
                            'marketplace_return_id' => $returnData['marketplace_return_id'],
                            'shop_id' => $returnData['shop_id'],
                            'marketplace' => $marketplace,
                            'order_id' => $returnData['marketplace_reference_id']
                        ]
                    ],
                    [
                        'filter' => [
                            'marketplace_return_id' => $returnData['marketplace_return_id'],
                            'shop_id' => $returnData['shop_id'],
                            'user_id' => $this->di->getUser()->id,
                            'marketplace' => $marketplace,
                            'object_type' => [
                                '$regex' => '_return$'
                            ]
                        ],
                        'data' => [
                            'marketplace_return_id' => $target['marketplace_return_id'],
                            'shop_id' => $target['shop_id'],
                            'marketplace' => $target['marketplace'],
                            'order_id' => $target['order_id']
                        ]
                    ]
                ]);
                // $returnProcessor->addReturnToSourceOrTarget($target);
                // $returnProcessor->addReturnToSourceOrTarget([
                //     'marketplace_return_id' => $returnData['marketplace_return_id'],
                //     'shop_id' => $returnData['shop_id'],
                //     'marketplace' => $marketplace,
                //     'order_id' => $returnData['marketplace_reference_id']
                // ], [
                //     'marketplace_return_id' => $target['marketplace_return_id'],
                //     'shop_id' => $target['shop_id'],
                //     'marketplace' => $target['marketplace']
                // ]);
            }
        }

        $callback($returnData);
        return [
            'success' => true,
            'message' => 'Return request has been created successfully.',
            'marketplace_return_id' => $returnData['marketplace_return_id']
        ];
    }

    public function execute(): array
    {
        return $this->createReturnRequest();
    }
}
