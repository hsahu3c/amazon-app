<?php
declare(strict_types=1);
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Connector\Service;

use App\Connector\Contracts\Sales\Order\CancelInterface;
use App\Connector\Service\AbstractCancel;

/**
 * Interface OrderInterface
 * @services
 */
class ManualCancel extends AbstractCancel implements CancelInterface
{
    public const  SCHEMA_VERSION = '2.0';

    public const ORDER_CONTAINER = 'order_container';

    public $schemaObjectType;

    public $cifData;

    public $orderId;

    public $status;

    public function cancel(array $order): array
    {
        return [];
    }

    public function prepareForDb(array $order): array
    {
        return [];
    }

    public function getFormattedData($data): array
    {
        return [];
    }

    public function validateForCancellation(array $data): array
    {
        return [];
    }

    public function setStatus($status)
    {
        $this->status = $status;
        return $this->status;
    }

    public function updateOrder(array $data): array
    {
        $data['marketplace'] = 'cif';
        $data['marketplace_reference_id'] = 'cif_'.$data['marketplace_reference_id'];
        $data['marketplace_shop_id'] = 'cif';
        return $data;
    }

    public function setReferenceKeys($reference_id, $reference_shop_id, array $data = []): array
    {
        $data['reference_id'] = $reference_id;
        $data['reference_shop_id'] = $reference_shop_id;
        return $data;
    }

    public function create(array $order): array
    {
        $orderCancelService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\CancelInterface::class);
        if (empty($order['marketplace_reference_id'])) {
            return ['success' => false, "message" => "marketplace_reference_id not found in prepared data"];
        }

        $this->cifData = $this->updateOrder($order);
        $generatedOrderId = $this->generateOrderId($this->cifData);
        if (!$generatedOrderId['success']) {
            return ['success' => false, "message" => $generatedOrderId['message']];
        }

        $this->orderId = $generatedOrderId['data'];
        $itemResponse = $this->createOrderItems();
        if (!$itemResponse['success']) {
            return ['success' => false, 'message' => $itemResponse['message']];
        }

        $id = new \MongoDB\BSON\ObjectId($this->orderId);
        $orderUpdate = $orderCancelService->update(['_id' => $id, 'object_type' => $this->schemaObjectType], $this->cifData);
        if (!$orderUpdate['success']) {
            return ['success' => false, 'message' => $orderUpdate['message']];
        }

        return ['success' => true, 'data' => $orderUpdate['data']];
    }

      /**
     * to create a basic order
     */
    public function generateOrderId(array $data): array
    {
        $insertData = [
            "user_id" => $this->di->getUser()->id,
            "object_type" => $this->schemaObjectType,
            "marketplace" =>  $data['marketplace'],
            "marketplace_shop_id" => $data['marketplace_shop_id'],
            "marketplace_reference_id" => $data['marketplace_reference_id'],
            "process_inprogess" => 1,
            "currency" => "USD",
            "status"=> $this->status
        ];
        $orderCancelService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\CancelInterface::class);
        $collection = $orderCancelService->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $orderData = $collection->findOne($insertData, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if (isset($orderData['process_inprogess'])) {
            return ['success' => false, 'message' => 'Order is in progress'];
        }

        $insertData['created_at'] = $orderCancelService->getMongoDate();
        $insertData['updated_at'] = $orderCancelService->getMongoDate();
        $insert = $collection->insertOne($insertData);
        return ["success" => true, "data" => (string)$insert->getInsertedId()];
    }

    /**
     * to create order items and updating price information
     */
    public function createOrderItems(): array
    {
        $savedItem = [];
        $data = $this->cifData;
        $marketPlaceCurrency = $data['marketplace_currency'];
        $totalMarketplacePrice = 0;
        $total_marketplace_tax = 0;
        $total_marketplace_discount = 0;
        $total_marketplace_shipping = 0;
        $orderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
        $orderCancelService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\CancelInterface::class);        
        if (!isset($data['items'])) {
            return ['success' => false, 'message' => 'items not found during data save at connector!!'];
        }

        foreach ($data['items'] as $itemKey => $item) {
            if(isset($item['price']) && isset($item['price']['price'])) {
                $this->cifData['items'][$itemKey]['marketplace_price'] = $item['price']['price'];
                $this->cifData['items'][$itemKey]['price'] = $orderService->getConnectorPrice($marketPlaceCurrency, $item['price']['price']);
                $totalMarketplacePrice += (float)$item['price']['price'];
            } elseif(isset($item['price'])) {
                $this->cifData['items'][$itemKey]['marketplace_price'] = $item['price'];
                $this->cifData['items'][$itemKey]['price'] = $orderService->getConnectorPrice($marketPlaceCurrency, $item['price']);
                $totalMarketplacePrice += (float)$item['price'];
            }

            if(isset($item['tax']) && isset($item['tax']['price'])) {
                $this->cifData['items'][$itemKey]['tax']['marketplace_price'] = $item['tax']['price'];
                $this->cifData['items'][$itemKey]['tax']['price'] = $orderService->getConnectorPrice($marketPlaceCurrency, $item['tax']['price']);
                $total_marketplace_tax += (float)$item['tax']['price'];
            }

            if(isset($item['discount']) && isset($item['discount']['price'])) {
                $this->cifData['items'][$itemKey]['discount']['marketplace_price'] = $item['discount']['price'];
                $this->cifData['items'][$itemKey]['discount']['price'] = $orderService->getConnectorPrice($marketPlaceCurrency, $item['discount']['price']);
                $total_marketplace_discount += (float)$item['discount']['price'];
            }

            if(isset($item['shipping_charge']) && isset($item['shipping_charge']['price'])) {
                $this->cifData['items'][$itemKey]['shipping_charge']['marketplace_price'] = $item['shipping_charge']['price'];
                $this->cifData['items'][$itemKey]['shipping_charge']['price'] = $orderService->getConnectorPrice($marketPlaceCurrency, $item['shipping_charge']['price']);
                $total_marketplace_shipping += (float)$item['shipping_charge']['price'];
            }

            if(isset($item['discounts'])) {
                foreach($item['discounts'] as $key => $value) {
                    if(isset($value['price'])) {
                        $this->cifData['items'][$itemKey]['discounts'][$key]['marketplace_price'] = $value['price'];
                        $this->cifData['items'][$itemKey]['discounts'][$key]['price'] = $orderService->getConnectorPrice($marketPlaceCurrency, $value['price']);
                    }
                }                
            }

            if(isset($item['taxes'])) {
                foreach($item['taxes'] as $key => $value) {
                    if(isset($value['price'])) {
                        $this->cifData['items'][$itemKey]['taxes'][$key]['marketplace_price'] = $value['price'];
                        $this->cifData['items'][$itemKey]['taxes'][$key]['price'] = $orderService->getConnectorPrice($marketPlaceCurrency, $value['price']);
                    }
                }                
            }            

            $collection = $orderCancelService->getBaseMongoAndCollection(self::ORDER_CONTAINER);
            $itemsData = $this->cifData['items'][$itemKey];
            $itemsData['object_type'] = $this->schemaObjectType . '_order_items';
            $itemsData['schema_version'] = self::SCHEMA_VERSION;
            $itemsData['cif_order_id'] = $this->cifData['cif_order_id'];
            $itemsData['updated_at'] = $orderCancelService->getMongoDate();
            $itemsData['created_at'] = $orderCancelService->getMongoDate();
            $insert = $collection->insertOne($itemsData);
            $savedItem[$item['marketplace_item_id']] = (string)$insert->getInsertedId();
            $this->cifData['items'][$itemKey]['id'] = (string)$insert->getInsertedId();
        }

        $this->cifData['tax'] = [
            'price' => $orderService->getConnectorPrice($marketPlaceCurrency, $total_marketplace_tax),
            'marketplace_price' => $total_marketplace_tax
        ];
        $this->cifData['discount'] = [
            'price' => $orderService->getConnectorPrice($marketPlaceCurrency, $total_marketplace_discount),
            'marketplace_price' => $total_marketplace_discount
        ];
        $this->cifData['cancellation_amount'] = [
            'price' => $orderService->getConnectorPrice($marketPlaceCurrency, $totalMarketplacePrice),
            'marketplace_price' => $totalMarketplacePrice,
        ];

        if(isset($this->cifData['sub_total']) && !is_null($this->cifData['sub_total']) && !is_array($this->cifData['sub_total'])) {
            $this->cifData['sub_total'] = [
                'price' => (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $this->cifData['sub_total']),
                'marketplace_price' => (float)$this->cifData['sub_total'],
            ];
        }

        if(isset($this->cifData['total']) && !is_null($this->cifData['total'])  && !is_array($this->cifData['total'])) {
            $this->cifData['total'] = [
                'price' => (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $this->cifData['total']),
                'marketplace_price' => (float)$this->cifData['total'],
            ];
        }

        return ['success' => true, 'data' => $savedItem];
    }


       /**
     * To set the schema object type
     */
    public function setSchemaObject(string $schemaObject): string
    {
        $this->schemaObjectType = $schemaObject;
        return  $this->schemaObjectType;
    }

        /**
     * using abstract class to check if cancellation allowed in connector or not
     */
    public function isCancellable(): bool
    {
        return true;
    }

    public function isPartialCancelAllowed():bool
    {
        return false;
    }

    public function getCancelReason($reason, $marketplace):array
    {
        return [];
    }
}