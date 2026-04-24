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
 * Interface CancelInterface
 * @services
 */
#[\AllowDynamicProperties]
class OrderCancel extends AbstractCancel implements CancelInterface
{
    private $orderId;

    public const  SCHEMA_VERSION = '2.0';

    public const ORDER_CONTAINER = 'order_container';

    public $settings_enabled = false;

    public $settings_data = null;

    public $parentOrderType = null;

    public $schemaObjectType = null;

    public $sourceOrderId;

    /**
     * To set the schema object type
     */
    public function setSchemaObject(string $schemaObject): string
    {
        $this->schemaObjectType = $schemaObject;
        return  $this->schemaObjectType;
    }

    /**
     * To set the cancellation object type
     *
     * @param string $type
     */
    public function setCancellationObjectType($type): string
    {
        $this->schemaObjectType = (stripos($type, "source") === false) ? "target_cancellation": "source_cancellation";
        return $this->schemaObjectType;
    }

    /**
     * to toggle the object_type
     *
     * @param string $type
     */
    public function toggleCancellationObjectType($type): string
    {
        $this->schemaObjectType = !(stripos($type, "source") === false) ? "target_cancellation": "source_cancellation";
        return $this->schemaObjectType;
    }

    public function prepareForDb(array $data): array
    {
        return [];
    }

    public function cancel(array $order): array
    {
        return [];
    }


    /**
     * to get all failed cancel orders
     */
    public function getAll(array $params): array
    {
        $this->di->getUser()->id;
        $source_shop_id = $this->di->getRequester()->getSourceId();
        $target_shop_id = $this->di->getRequester()->getTargetId();
        $this->di->getRequester()->getSourceName();
        $this->di->getRequester()->getTargetName();
        $this->di->getAppCode()->getAppTag();
        $params['filter']['marketplace_shop_id'][1] = $source_shop_id;
        $params['filter']['targets.shop_id'][1] = $target_shop_id;
        $params['filter']['cancellation_status'][1] = 'failed';
        $orderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
        $response = $orderService->getAll($params);
        if(empty($response)) {
            return [];
        }

        return $response;
    }

    public function search(array $data): array
    {
        return [];
    }

    /**
     * for updating cancellation status and quantity in items and source orders
     *
     * @param string $status
     */
    public function updateCancellationStatusAndQty(array $target, array $cancelledData, $status, $additional = []): array
    {
        $collection = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $query = [];
        foreach ($target['items'] as $index => $targetItem) {
            foreach ($cancelledData['items'] as $cancelledDataItems) {
                //sku matching has been removed with marketplace_item_id
                if ((string)$cancelledDataItems['marketplace_item_id'] == (string)$targetItem['marketplace_item_id']) {
                    //$query["items." . $index . ".cancelled_qty"] = (int)$cancelledDataItems['qty'];
                    //for future updates
                    $qty = 0;
                    if(isset($targetItem['cancelled_qty'])) {
                        $qty = (int)$cancelledDataItems['qty']+(int)$targetItem['cancelled_qty'];
                    } else {
                       $qty = (int)$cancelledDataItems['qty'];
                    }

                    $query["items." . $index . ".cancelled_qty"] = $qty;
                    $response = $collection->updateOne(['_id' => new \MongoDB\BSON\ObjectId($targetItem['id'])], ['$set' => ['cancelled_qty' => $qty, 'updated_at' => $this->getMongoDate()]]);
                    if (empty($response)) {
                        $this->di->getLog()->logContent( 'Updation failed at order_items= ' . print_r($response, true), 'info', 'order/order-cancel/' .$this->di->getUser()->id.'/'.date('d-m-y') .'/automatic-cancel.log');
                    }
                } else
                    continue;
            }
        }

        $query['cancellation_status'] =  $status;
        $query['updated_at'] = $this->getMongoDate();
        if(!empty($additional)) {
            foreach($additional as $key => $value) {
                $query[$key] = $value;
            }
        }

        $response = $collection->updateOne(['_id' => $target['_id']], ['$set' => $query]);
        $data = $collection->findOne(['_id' => $target['_id']], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if (empty($response)) {
            return [
                'success' => false,
                'message' => 'Unable to update ' . (string)$target['_id']
            ];
        }

        return [
            'success' => true,
            'data' => $data
        ];
    }


    /**
     * to create the order
     */
    public function create(array $cancelledOrder): array
    {
        if (empty($cancelledOrder['marketplace_reference_id'])) {
            return ['success' => false, "message" => "marketplace_reference_id not found in prepared data"];
        }

        $generatedOrderId = $this->generateOrderId($cancelledOrder);
        if (!$generatedOrderId['success']) {
            return ['success' => false, "message" => $generatedOrderId['message']];
        }

        $this->cancellationData = $cancelledOrder;
        $this->orderId = $generatedOrderId['data'];
        $itemResponse = $this->createOrderItems();
        if (!$itemResponse['success']) {
            return ['success' => false, 'message' => $itemResponse['message']];
        }

        $id = new \MongoDB\BSON\ObjectId($this->orderId);
        $orderUpdate = $this->update(['_id' => $id, 'object_type' => $this->schemaObjectType], $this->cancellationData);
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
        if (!isset($data['marketplace_reference_id']) || !isset($data['marketplace_shop_id']) || !isset($data['marketplace'])) {
            return [
                'success' => false,
                'message' => 'marketplace_reference_id, marketplace_shop_id or marketplace is missing!!'
            ];
        }

        $insertData = [
            "user_id" => $this->di->getUser()->id,
            "object_type" => $this->schemaObjectType,
            "marketplace" => $data['marketplace'],
            "marketplace_shop_id" => $data['marketplace_shop_id'],
            "marketplace_reference_id" => $data['marketplace_reference_id'],
            "process_inprogess" => 1,
            "currency" => "USD",
            'status' => isset($data['status'])? $data['status']: 'Cancelled'
        ];

        $collection = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $orderData = $collection->findOne($insertData, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if (isset($orderData['process_inprogess'])) {
            return ['success' => false, 'message' => $this->di->getLocale()->_('order_cancellation_in_progress', [
                'marketplaceReferenceId' => $data['marketplace_reference_id']
            ])];
        }

        $insertData['created_at'] = $this->getMongoDate();
        $insertData['updated_at'] = $this->getMongoDate();
        $insert = $collection->insertOne($insertData);
        return ["success" => true, "data" => (string)$insert->getInsertedId()];
    }


    /**
     * to perform update
     */
    public function update(array $filter, array $data): array
    {
        $collection = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $data['updated_at'] = $this->getMongoDate();
        $collection->findOneAndUpdate($filter, ['$set' => $data], ["returnOriginal" => true]);
        $getData = $collection->findOne($filter, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if (empty($getData)) {
            return ['success' => false, 'message' => 'updated filter data not found'];
        }

        return ['success' => true, 'data' => $getData];
    }

    /**
     * to unset any keys from db collection
     */
    public function unsetData(array $filter, array $data): array
    {
        $collection = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $data['updated_at'] = $this->getMongoDate();
        $collection->findOneAndUpdate($filter, ['$unset' => $data], ["returnOriginal" => true]);
        $getData = $collection->findOne($filter, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if (empty($getData)) {
            return ['success' => false, 'message' => 'updated filter data not found'];
        }

        return ['success' => true, 'data' => $getData];
    }

    /**
     * to create order items and updating price information
     */
    public function createOrderItems(): array
    {
        $savedItem = [];
        $data = $this->cancellationData;
        $marketPlaceCurrency = $data['marketplace_currency'];
        $totalMarketplacePrice = 0;
        $total_marketplace_tax = 0;
        $total_marketplace_discount = 0;
        $total_marketplace_shipping = 0;

        $orderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
        if (!isset($data['items']) || empty($data['items'])) {
            return ['success' => false, 'message' => 'items not found during data save at connector!!'];
        }

        foreach ($data['items'] as $itemKey => $item) {
            //price and marketplace price - itemwise
            if (isset($item['price']['price']) && !is_null($item['price']['price']) && !is_array($item['price']['price'])) {
                $this->cancellationData['items'][$itemKey]['marketplace_price'] = (float)$item['price']['price'];
                $this->cancellationData['items'][$itemKey]['price'] = (float)$orderService->getConnectorPrice($marketPlaceCurrency, $item['price']['price']);
                $totalMarketplacePrice += ((float)$item['price']['price'] * $item['qty']);
            } else if (isset($item['price']) && !is_null($item['price']) && !is_array($item['price'])) {
                $this->cancellationData['items'][$itemKey]['marketplace_price'] = (float)$item['price'];
                $this->cancellationData['items'][$itemKey]['price'] = (float)$orderService->getConnectorPrice($marketPlaceCurrency, $item['price']);
                $totalMarketplacePrice += ((float)$item['price'] * $item['qty']);
            }

            //tax- itemwise
            if (isset($item['tax']['price']) && !is_null($item['tax']['price']) && !is_array($item['tax']['price'])) {
                $this->cancellationData['items'][$itemKey]['tax']['marketplace_price'] = (float)$item['tax']['price'];
                $this->cancellationData['items'][$itemKey]['tax']['price'] = (float)$orderService->getConnectorPrice($marketPlaceCurrency, $item['tax']['price']);
                $total_marketplace_tax += (float)$item['tax']['price'];
            }

            //discount - itemwise
            if (isset($item['discount']['price'])  && !is_null($item['discount']['price']) && !is_array($item['discount']['price'])) {
                $this->cancellationData['items'][$itemKey]['discount']['marketplace_price'] = (float)$item['discount']['price'];
                $this->cancellationData['items'][$itemKey]['discount']['price'] = (float)$orderService->getConnectorPrice($marketPlaceCurrency, $item['discount']['price']);
                $total_marketplace_discount += (float)$item['discount']['price'];
            }

            //shipping_charge - itemwise
            if (isset($item['shipping_charge']['price']) && !is_null($item['shipping_charge']['price']) && !is_array($item['shipping_charge']['price'])) {
                $this->cancellationData['items'][$itemKey]['shipping_charge']['marketplace_price'] = (float)$item['shipping_charge']['price'];
                $this->cancellationData['items'][$itemKey]['shipping_charge']['price'] = (float)$orderService->getConnectorPrice($marketPlaceCurrency, $item['shipping_charge']['price']);
                $total_marketplace_shipping += (float)$item['shipping_charge']['price'];
            }

            //discounts - itemwise
            if (isset($item['discounts'])) {
                foreach ($item['discounts'] as $key => $value) {
                    if (isset($value['price']) && !is_null($value['price']) && !is_array($value['price'])) {
                        $this->cancellationData['items'][$itemKey]['discounts'][$key]['marketplace_price'] = (float)$value['price'];
                        $this->cancellationData['items'][$itemKey]['discounts'][$key]['price'] = (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $value['price']);
                    }
                }
            }

            //taxes - itemwise
            if (isset($item['taxes'])) {
                foreach ($item['taxes'] as $key => $value) {
                    if (isset($value['price']) && !is_null($value['price']) && !is_array($value['price'])) {
                        $this->cancellationData['items'][$itemKey]['taxes'][$key]['marketplace_price'] = (float)$value['price'];
                        $this->cancellationData['items'][$itemKey]['taxes'][$key]['price'] = (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $value['price']);
                    }
                }
            }

            //shipping_charges - itemwise
            if (isset($item['shipping_charges'])) {
                foreach ($item['shipping_charges'] as $key => $value) {
                    if (isset($value['price']) && !is_null($value['price']) && !is_array($value['price'])) {
                        $this->cancellationData['items'][$itemKey]['shipping_charges'][$key]['marketplace_price'] = (float)$value['price'];
                        $this->cancellationData['items'][$itemKey]['shipping_charges'][$key]['price'] = (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $value['price']);
                    }
                }
            }

            $collection = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
            $itemsData = $this->cancellationData['items'][$itemKey];
            $itemsData['object_type'] = $this->schemaObjectType . '_order_items';
            $itemsData['schema_version'] = self::SCHEMA_VERSION;
            $itemsData['user_id'] = $this->di->getUser()->id;
            $itemsData['cif_order_id'] = $this->cancellationData['cif_order_id'];
            $itemsData['order_id'] = $this->orderId;
            $itemsData['currency'] = "USD";
            $itemsData['marketplace_currency'] = $marketPlaceCurrency;
            $itemsData['updated_at'] = $this->getMongoDate();
            $itemsData['created_at'] = $this->getMongoDate();
            $insert = $collection->insertOne($itemsData);
            $savedItem[$item['marketplace_item_id']] = (string)$insert->getInsertedId();
            $this->cancellationData['items'][$itemKey]['id'] = (string)$insert->getInsertedId();
        }

        //tax - global
        if(isset($this->cancellationData['tax']['price']) && !is_null($this->cancellationData['tax']['price']) && !is_array($this->cancellationData['tax']['price'])) {
            $total_marketplace_tax = $this->cancellationData['tax']['price'];
        }

        $this->cancellationData['tax'] = [
            'price' => (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $total_marketplace_tax),
            'marketplace_price' => (float)$total_marketplace_tax
        ];

        //discount - global
        if(isset($this->cancellationData['discount']['price']) && !is_null($this->cancellationData['discount']['price']) && !is_array($this->cancellationData['discount']['price'])) {
            $total_marketplace_discount = $this->cancellationData['discount']['price'];
        }

        $this->cancellationData['discount'] = [
            'price' => (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $total_marketplace_discount),
            'marketplace_price' => (float)$total_marketplace_discount
        ];


        //shipping_charge - global
        if(isset($this->cancellationData['shipping_charge']['price']) && !is_null($this->cancellationData['shipping_charge']['price']) && !is_array($this->cancellationData['shipping_charge']['price'])) {
            $total_marketplace_shipping = $this->cancellationData['shipping_charge']['price'];
        }

        $this->cancellationData['shipping_charge'] = [
            'price' => (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $total_marketplace_shipping),
            'marketplace_price' => (float)$total_marketplace_shipping
        ];


        if(isset($this->cancellationData['taxes']) && !empty($this->cancellationData['taxes'])) {
           foreach($this->cancellationData['taxes'] as $key => $tax) {
                if(isset($tax['price']) && !is_null($tax['price']) && !is_array($tax['price'])) {
                    $this->cancellationData['taxes'][$key]['price'] = (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $tax['price']);
                    $this->cancellationData['taxes'][$key]['marketplace_price'] = (float)$tax['price'];
                }
           }
        }

        if(isset($this->cancellationData['discounts']) && !empty($this->cancellationData['discounts'])) {
            foreach($this->cancellationData['discounts'] as $key => $discount) {
                 if(isset($discount['price'])  && !is_null($discount['price']) && !is_array($discount['price'])) {
                     $this->cancellationData['discounts'][$key]['price'] = (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $discount['price']);
                     $this->cancellationData['discounts'][$key]['marketplace_price'] = (float)$discount['price'];
                 }
            }
         }

         if(isset($this->cancellationData['shipping_charges']) && !empty($this->cancellationData['shipping_charges'])) {
            foreach($this->cancellationData['shipping_charges'] as $key => $shipping) {
                 if(isset($shipping['price'])  && !is_null($shipping['price']) && !is_array($shipping['price'])) {
                     $this->cancellationData['shipping_charges'][$key]['price'] = (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $shipping['price']);
                     $this->cancellationData['shipping_charges'][$key]['marketplace_price'] = (float)$shipping['price'];
                 }
            }
         }

        $cancel_amount = (float)($totalMarketplacePrice + $total_marketplace_tax + $total_marketplace_shipping - $total_marketplace_discount);
        $this->cancellationData['cancellation_amount'] = [
            'price' => (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $cancel_amount),
            'marketplace_price' => $cancel_amount,
        ];


        if(isset($this->cancellationData['sub_total']) && !is_null($this->cancellationData['sub_total']) && !is_array($this->cancellationData['sub_total'])) {
            $this->cancellationData['sub_total'] = [
                'price' => (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $this->cancellationData['sub_total']),
                'marketplace_price' => (float)$this->cancellationData['sub_total'],
            ];
        }

        if(isset($this->cancellationData['total']) && !is_null($this->cancellationData['total'])  && !is_array($this->cancellationData['total'])) {
            $this->cancellationData['total'] = [
                'price' => (float)$orderService->getConnectorPrice($marketPlaceCurrency,  $this->cancellationData['total']),
                'marketplace_price' => (float)$this->cancellationData['total'],
            ];
        }

        return ['success' => true, 'data' => $savedItem];
    }

       /**
     *
     * to find data according to query
     */
    public function findFromDb(array $params): array
    {
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection(self::ORDER_CONTAINER);
        $data = $collection->find($params, $options)->toArray();
        if(empty($data)) {
            return [];
        }

        return $data;
    }

      /**
     * running findOne mongo
     */
    public function findOneFromDb(array $params): array
    {
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection(self::ORDER_CONTAINER);
        $data = $collection->findOne($params, $options);
        if(empty($data)) {
            return [];
        }

        return $data;
    }

    /**
     * to get the object of mongo
     *
     * @param string $collection
     * @return object
     */
    public function getBaseMongoAndCollection($collection)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        return $mongo->getCollection($collection);
    }

    /**
     * To get the mongo date
     *
     */
    public function getMongoDate(): string
    {
        return date('c');
        //return new \MongoDB\BSON\UTCDateTime();
    }   

    /**
     * using abstract class to check if cancellation allowed in connector or not
     */
    public function isCancellable(): bool
    {
        return true;
    }

    public function getFormattedData(array $data): array
    {
        return [];
    }

    public function prepareData(array $order): array
    {
        return [];
    }

    public function validateForCancellation(array $data): array
    {
        return [];
    }

    public function isPartialCancelAllowed():bool
    {
        return true;
    }

    public function getCancelReason($reason, $marketplace):array
    {
        return [];
    }

    public function delete($params)
    {
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection(self::ORDER_CONTAINER);
        $data = $collection->deleteOne($params, $options);
        if(empty($data)) {
            return [];
        }

        return $data;
    }

    public function deleteAll($params)
    {
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection(self::ORDER_CONTAINER);
        $data = $collection->deleteMany($params, $options);
        if(empty($data)) {
            return [];
        }

        return $data;
    }

    public function getMongoObjectId($id): \MongoDB\BSON\ObjectId
    {
        return new \MongoDB\BSON\ObjectId($id);
    }
}
