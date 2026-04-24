<?php

namespace App\Connector\Repositories;

use App\Connector\Components\SchemaValidator;
use App\Connector\Models\OrderContainer;

class OrderReturn
{
    protected $schema = [
        "object_type" => ["validator" => "source_return|target_return", "message" => "Invalid object type"],
        "marketplace_return_id" => ["validator" => "string", "message" => "Invalid marketplace_return_id"],
        "return_status" => ["validator" => "Returned|Requested|Approved|Declined", "message" => "Invalid return_status"],
        "marketplace_status" => ["validator" => "string", "message" => "Invalid marketplace_status"],
        "return_error" => ["validator" => "string", "message" => "Invalid return_error"],
        "user_id" => ["validator" => "string", "message" => "Invalid user_id"],
        "shop_id" => ["validator" => "string", "message" => "Invalid shop_id"],
        "attributes" => ["validator" => "array", "message" => "Invalid attributes"],
        "marketplace" => ["validator" => "string", "message" => "Invalid marketplace"],
        "itemIds" => ["validator" => "array", "message" => "Invalid itemIds"],
        'return_reason_code' => ["validator" => 'string', 'message' => 'Invalid return reason code'],
        'return_note' => ["validator" => 'string', 'message' => 'Invalid return note'],
        'marketplace_reference_id' => ["validator" => 'string', 'message' => 'Invalid marketplace_reference_id'],
    ];

    protected $errors = [];

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
        if (!$this->validate($data)) {
            return false;
        }

        $model = new OrderContainer();
        try {
            $model->getCollection()->updateOne([
                'marketplace_return_id' => $data['marketplace_return_id'],
                'cif_order_id' => $data['cif_order_id'],
                'object_type' => $data['object_type']
            ], ['$set' => $data], ['upsert' => true]);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function prepareData(array $data, array $itemsIds, string $type): array
    {
        $preparedData = [];
        if (isset($data['reject_reason_code'], $data['reject_note'])) {
            $preparedData = [
                'reject_reason_code' => $data['reject_reason_code'],
                'reject_note' => $data['reject_note'],
            ];
        }

        if (isset($data['return_reason_code'], $data['return_note'])) {
            $preparedData = [
                'return_reason_code' => $data['return_reason_code'],
                'return_note' => $data['return_note'],
            ];
        }

        if (isset($data['return_error'])) {
            $preparedData['return_error'] = $data['return_error'];
        }

        if (isset($data['marketplace_status'])) {
            $preparedData['marketplace_status'] = $data['marketplace_status'];
        }

        return array_merge($preparedData, [
            'object_type' => $type . "_return",
            'marketplace_return_id' => $data['marketplace_return_id'],
            'marketplace_reference_id' => $data['marketplace_reference_id'],
            'cif_order_id' => $data['cif_order_id'],
            'return_status' => $data['return_status'],
            'user_id' => $data['user_id'],
            'shop_id' => $data['shop_id'],
            'attributes' => $data['attributes'] ?? [],
            'marketplace' => $data['marketplace'],
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'source_updated_at' => $data['source_updated_at'],
            'source_created_at' => $data['source_created_at'],
            'itemIds' => $itemsIds
        ]);
    }

    // public function addReturnToSourceOrTarget($marketplaceReturnId, $shopId, $marketplace, array $data)
    // {
    //     $model = new OrderContainer();
    //     return $model->getCollection()->updateOne(
    //         [
    //             'marketplace_return_id' => $marketplaceReturnId,
    //             'shop_id' => $shopId,
    //             'marketplace' => $marketplace
    //         ],
    //         [
    //             '$push' => [
    //                 'targets' => [
    //                     'marketplace_return_id' => $data['marketplace_return_id'],
    //                     'shop_id' => $data['shop_id'],
    //                     'marketplace' => $data['marketplace'],
    //                     'order_id' => $data['order_id']
    //                 ]
    //             ]
    //         ]
    //     );
    // }

    public function addReturnToSourceOrTarget(array $updates)
    {
        $model = new OrderContainer();
        $collection = $model->getCollection();

        $bulk = [];

        foreach ($updates as $update) {
            if (!isset($update['filter'], $update['data'])) {
                throw new \InvalidArgumentException("Each update must contain 'filter' and 'data' keys.");
            }

            $filter = $update['filter'];
            $data = $update['data'];

            $bulk[] = [
                'updateOne' => [
                    $filter,
                    [
                        '$push' => ['targets' => $data]
                    ]
                ]
            ];
        }

        try {
            $result = $collection->bulkWrite($bulk);
        } catch (\Exception $e) {
            // Handle errors
            throw new \RuntimeException("BulkWriteException occurred.", 0, $e);
        }

        return $result;
    }
}
