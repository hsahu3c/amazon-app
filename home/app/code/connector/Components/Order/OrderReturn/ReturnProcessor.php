<?php

namespace App\Connector\Components\Order\OrderReturn;

use App\Connector\Repositories\OrderReturnItem;
use App\Connector\Repositories\OrderReturn;

class ReturnProcessor
{
    const SOURCE_ORDER = 'source_order';

    const TARGET_ORDER = 'target_order';

    private array $data;

    private array $items;

    private string $type;

    private array $returnedProductIdentifiers;

    private $errors;

    public function __construct(array $data, array $items, string $type)
    {
        $this->data = $data;
        $this->items = $items;
        $this->type = $type;
        $this->returnedProductIdentifiers = [];
        $this->errors = null;
    }

    /**
     * @return mixed[]
     */
    private function prepareItems(array $items, string $type): array
    {
        $orderReturnItem = new OrderReturnItem();
        $insertedItemIds = [];
        foreach ($items as $item) {
            $item['cif_order_id'] = $this->data['cif_order_id'];
            $item['marketplace_item_return_id'] ??= $this->data['marketplace_return_id'];
            $item['sku'] ??= $item['id'] ?? null;
            $item['return_error'] ??= null;
            $item['return_quantity'] = $item['quantity'] ?? 1;
            $item['customer_note'] ??= null;
            $item['user_id'] = $this->data['user_id'];
            $item['marketplace'] = $this->data['marketplace'];
            $item['return_reason_code'] ??= $this->data['return_reason_code'] ?? "OTHER";
            $item['return_note'] ??= $this->data['return_note'] ?? "";
            $itemData = $orderReturnItem->prepareItem($item, $type);
            if ($itemObjectId = $orderReturnItem->create($itemData)) {
                $insertedItemIds[] = $itemObjectId;
            }

            $this->errors = $orderReturnItem->getErrors();
            $this->returnedProductIdentifiers[] = [
                'item_link_id' => $item['item_link_id'],
                'quantity' => $item['quantity'] ?? 1,
            ];
        }

        return $insertedItemIds;
    }

    private function prepareData(array $data, array $itemsIds, string $type)
    {
        $orderReturn = new OrderReturn();
        $preparedDoc = $orderReturn->prepareData($data, $itemsIds, $type);
        $result = $orderReturn->create($preparedDoc);
        $this->errors = $orderReturn->getErrors();
        return $result;
    }

    public function create(): false|array
    {
        if (!$this->items) {
            $this->errors[] = 'No items found';
            return false;
        }

        $itemsIds = $this->prepareItems($this->items, $this->type);
        if ($itemsIds === []) {
            $this->errors[] = 'No items found';
            return false;
        }

        if (!$this->data) {
            $this->errors[] = 'No data found';
            return false;
        }

        $response = $this->prepareData($this->data, $itemsIds, $this->type);
        if (!$response) {
            $this->errors[] = 'Return request could not be created';
            return false;
        }

        return $this->returnedProductIdentifiers;
    }

    public function accept(array $returnedSkus, $error = null)
    {
        if (!$this->data['cif_order_id']) {
            return [
                'success' => false,
                'message' => 'No CIF order ID found'
            ];
        }

        $this->data['cif_order_id'];
        foreach ($returnedSkus as $sku) {
            $orderReturnItem = new OrderReturnItem();
            $orderReturnItem->update($sku, $this->type, ['item_status' => 'Returned']);
        }
    }

    public function bulkUpdate(array $items)
    {
        $orderReturnItem = new OrderReturnItem();
        return $orderReturnItem->bulkUpdate($items, $this->type);
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function addReturnToSourceOrTarget(array $updates)
    {
        $orderReturn = new OrderReturn();
        return $orderReturn->addReturnToSourceOrTarget($updates);
    }
}
