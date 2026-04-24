<?php

namespace App\Connector\Repositories;

use App\Connector\Components\SchemaValidator;
use App\Connector\Models\OrderContainer;

class OrderReturnItem
{
    protected $schema = [
        "object_type" => ["validator" => "source_return_items|target_return_items", "message" => "Invalid object type"],
        "marketplace_return_id" => ["validator" => "string", "message" => "Invalid marketplace_return_id"],
        "item_status" => ["validator" => "Returned|Requested|Approved|Declined", "message" => "Invalid item_status"],
        "sku" => ["validator" => "string", "message" => "Invalid sku"],
        "marketplace_item_return_id" => ["validator" => "string", "message" => "Invalid marketplace_item_id"],
        "return_quantity" => ["validator" => "number", 'message' => "Invalid return_quantity"],
        "return_reason_code" => ["validator" => "string", "message" => "Invalid return_reason_code"],
        "return_note" => ["validator" => "string", "message" => "Invalid return_note"],
        "return_error" => ["validator" => "string", "message" => "Invalid return_error"],
        "user_id" => ["validator" => "string", "message" => "Invalid user_id"],
        "marketplace" => ["validator" => "string", "message" => "Invalid marketplace"],
        "item_link_id" => ["validator" => "string", "message" => "Invalid item_link_id"]
    ];

    protected $errors = [];

    public function prepareItem(array $item, string $type): array
    {
        return [
            'object_type' => $type . '_return_items',
            'cif_order_id' => $item['cif_order_id'],
            'marketplace_item_id' => $item['marketplace_item_id'],
            'item_status' => $item['item_status'] ?? null,
            'return_quantity' => $item['return_quantity'],
            'return_reason_code' => $item['return_reason_code'],
            'return_note' => $item['return_note'],
            'sku' => $item['sku'],
            'marketplace_item_return_id' => $item['marketplace_item_return_id'],
            'return_error' => $item['return_error'],
            'product_identifier' => $item['product_identifier'] ?? null,
            'user_id' => $item['user_id'],
            'marketplace' => $item['marketplace'],
            'item_link_id' => $item['item_link_id'],
            'created_at' => date('c')
        ];
    }

    private function validate(array $data): bool
    {
        $validator = new SchemaValidator($this->schema);
        if (!$validator->validate($data)) {
            $this->errors = $validator->getErrors();
            return false;
        }

        return true;
    }

    public function create(array $data)
    {
        $model = new OrderContainer();
        try {
            // $response = $model->getCollection()->findOneAndUpdate([
            //     'cif_order_id' => $data['cif_order_id'],
            //     'object_type' => $data['object_type'],
            //     'marketplace_item_id' => $data['marketplace_item_id'],
            // ], [
            //     '$set' => $data,
            //     'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            // ], [
            //     'upsert' => true
            // ]);
            // Update or insert the document
            $model->getCollection()->updateOne(
                [
                    'cif_order_id' => $data['cif_order_id'],
                    'object_type' => $data['object_type'],
                    'marketplace_item_id' => $data['marketplace_item_id'],
                ],
                [
                    '$set' => $data
                ],
                [
                    'upsert' => true
                ]
            );

            // Fetch and return the updated document
            $response = $model->getCollection()->findOne(
                [
                    'cif_order_id' => $data['cif_order_id'],
                    'object_type' => $data['object_type'],
                    'marketplace_item_id' => $data['marketplace_item_id'],
                ]
            );

            return $response->_id ?? false;
        } catch (\Exception $e) {
            print_r($e->getMessage());
            return false;
        }

        if ($this->validate($data)) {
            return false;
        }
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function update(string $marketplaceItemId, string $type, array $data)
    {
        $model = new OrderContainer();
        try {
            $response = $model->getCollection()->updateOne([
                'cif_order_id' => $data['cif_order_id'],
                'object_type' => $type . '_return_items',
                'marketplace_item_id' => $marketplaceItemId
            ], [
                '$set' => $data
            ]);
            return $response->getModifiedCount();
        } catch (\Exception) {
            return false;
        }
    }

    public function bulkUpdate(array $items, string $type)
    {
        if (empty($items) || $items === []) {
            return false;
        }

        $bulk = [];

        foreach ($items as $item) {
            $bulk[] = [
                'updateOne' =>
                [
                    [
                        'cif_order_id' => $item['cif_order_id'],
                        'object_type' => $type . '_return_items',
                        'marketplace_item_id' => $item['marketplace_item_id']
                    ],
                    [
                        '$set' => [
                            'item_status' => $item['item_status'],
                            'return_error' => $item['return_error'] ?? null
                        ]
                    ]
                ]
            ];
        }

        $model = new OrderContainer();
        try {
            return $model->getCollection()->bulkWrite($bulk)->getModifiedCount();
        } catch (\Exception) {
            return false;
        }
    }
}
