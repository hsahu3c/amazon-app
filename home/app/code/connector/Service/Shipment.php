<?php

/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Connector\Service;

use App\Connector\Contracts\Sales\Order\ShipInterface;
use App\Connector\Service\AbstractShipment;

/**
 * Interface CurrencyInterface
 * @services
 */
#[\AllowDynamicProperties]
class Shipment extends AbstractShipment implements ShipInterface
{
    /**
     * ship function
     * For preparing Target Shipment
     */
    public function ship(array $data): array
    {
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $orderCollection = $this->mongo->getCollectionForTable('order_container');
        $this->logFile = "shipment/{$data['user_id']}/" . date('d-m-Y') . '.log';
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $params = [
            'user_id' => $data['user_id'],
            'object_type' => ['$in' => ['source_order','target_order']],
            'marketplace' => $data['marketplace'],
            'marketplace_shop_id' => $data['shop_id'],
            'marketplace_reference_id' => (string) $data['marketplace_reference_id']
        ];
        $orderData = $orderCollection->findOne($params, $arrayParams);
        if (!empty($orderData)) {
            $this->addLog(
                $this->logFile,
                'Starting Process for OrderId: ',
                $data['marketplace_reference_id'],
                'optional'
            );
            $this->objectType = $orderData['object_type'];
            $checkParams = [
                'user_id' => $data['user_id'],
                'object_type' => ['$in' => ['source_shipment', 'target_shipment']],
                'marketplace' => $data['marketplace'],
                'marketplace_shop_id' => $data['shop_id'],
                'marketplace_reference_id' => (string)$data['marketplace_reference_id'],
                'reference_id' => ['$exists' => true]
            ];
            $shipmentCheck = $orderCollection->findOne($checkParams, $arrayParams);
            if (!empty($shipmentCheck)) {
                $errorParams = [
                    'user_id' => $data['user_id'],
                    'object_type' => $this->objectType,
                    'marketplace' => $data['marketplace'],
                    'marketplace_shop_id' => $data['shop_id'],
                    'marketplace_reference_id' => (string)$data['marketplace_reference_id'],
                ];
                $orderCollection->updateOne(
                    $errorParams,
                    [
                        '$addToSet' => ['shipment_error' => 'Shipment tried from wrong source']
                    ]
                );
                return ['success' => false, 'message' => 'Shipment tried from wrong source'];
            }

            if ($this->objectType == "source_order") {
                $sourceShipment = $this->prepareSourceShipment($data);
                if (isset($sourceShipment['success']) && $sourceShipment['success']) {
                    $this->addLog($this->logFile, 'Source Shipment Prepared Successfully', "", 'optional');
                    if (isset($sourceShipment['data']) && !empty($sourceShipment['data'])) {
                        return $this->prepareTargetShipment($data, $sourceShipment['data']);
                    }
                }

                return $sourceShipment;

            }
            $targetShipment = $this->prepareTargetShipment($data);
            if (isset($targetShipment['success']) && $targetShipment['success']) {
                $this->di->getLog()->logContent('Target Shipment Prepared Successfully', 'info', $this->logFile);
                if (isset($targetShipment['data']) && !empty($targetShipment['data'])) {
                    return $this->prepareSourceShipment($data, $targetShipment['data']);
                }
            }
            return $targetShipment;
        }

        return ['success' => false, 'message' => 'OrderData Not Found'];
    }

    /**
     * prepareTargetShipment function
     * For preparing Target Shipment
     * @param [array] $data
     * @param [array] $sourceShipment
     * @return array
     */
    public function prepareTargetShipment(array $data, array $sourceShipment = [])
    {
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $orderCollection = $this->mongo->getCollectionForTable('order_container');
        if ($this->objectType == "source_order") {
            foreach ($sourceShipment['targets'] as $target) {
                if (($target['marketplace'] == $data['target_marketplace'])
                    && $target['shop_id'] == $data['target_shop_id']
                ) {
                    $targetOrderId = $target['order_id'];
                }
            }
        } else {
            $targetOrderId = $data['marketplace_reference_id'];
        }

        if (!empty($targetOrderId)) {
            $targetParams = [
                'user_id' => $data['user_id'],
                'object_type' => 'target_order',
                'marketplace' => $data['target_marketplace'],
                'marketplace_shop_id' => $data['target_shop_id'],
                'marketplace_reference_id' => (string) $targetOrderId
            ];
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $targetData = $orderCollection->findOne($targetParams, $arrayParams);
            $items = [];
            $errorSku = [];
            if (!empty($targetData)) {
                $toBeUpdated = false;
                $shipmentParams = [
                    'user_id' => $data['user_id'],
                    'object_type' => 'target_shipment',
                    'marketplace' => $targetData['marketplace'],
                    'marketplace_shop_id' => $targetData['marketplace_shop_id'],
                    'marketplace_shipment_id' => (string) $data['marketplace_shipment_id']
                ];
                if (!isset($targetData['shipping_status'])) {
                    $shipmentData = [];
                } else {
                    $shipmentData = $orderCollection->findOne($shipmentParams, $arrayParams);
                }

                if ($targetData['object_type'] == $this->objectType) {
                    if (!empty($shipmentData)) {
                        $toBeUpdated = true;
                    }

                    $getItems = $this->prepareItems($data, $targetData, $toBeUpdated);
                    $prepareData = [
                        'object_type' => 'target_shipment',
                        'marketplace' => $data['target_marketplace'],
                        'shop_id' => $data['target_shop_id'],
                        'targets_marketplace' => $data['source_marketplace'],
                        'targets_status' => "In Progress",
                        'targets_shop_id' => $data['source_shop_id'],
                        'targets_order_id' => $getItems['orderId'],
                        'marketplace_reference_id' => $targetData['marketplace_reference_id'],
                        'cif_order_id' => $targetData['cif_order_id'],
                        'items' => $getItems['items'],
                        'totalQty' => $getItems['totalQuantity'],
                        'shippedQty' => $getItems['quantityShipped'],
                        'data' => $data,
                    ];

                    $targetShipment = $this->prepareShipmentData($prepareData);
                    if ($toBeUpdated) {
                        if (strtotime($targetShipment['source_updated_at'])
                            > strtotime($shipmentData['source_updated_at'])) {
                            $targetShipment['shipment_id'] = $shipmentData['shipment_id'];
                            $targetShipmentCopy = $targetShipment;
                            unset($targetShipmentCopy['items']);
                            unset($targetShipmentCopy['created_at']);
                            $orderCollection->updateOne($shipmentParams, ['$set' => $targetShipmentCopy]);
                        } else {
                            return ['success' => false, 'message' => 'Attempting to update stale information'];
                        }
                    } else {
                        $saveShipment = $orderCollection->insertOne($targetShipment);
                        $id = $saveShipment->getInsertedId();
                        $itemIds = [];
                        $items = $getItems['items'];
                        $parentStatus = $getItems['totalQuantity']
                        == $getItems['savedShippedQuantity'] ? "Shipped" : "Partially Shipped";
                        foreach ($items as $key => $value) {
                            $shippedQty = $getItems['orderItems'][$value['marketplace_item_id']]['shipped_qty'];
                            $itemStatus = $shippedQty == $value['qty'] ? 'Shipped' : 'Partially Shipped';
                            $items[$key]['shipment_id'] = (string) $id;
                            $saveShipment = $orderCollection->insertOne($items[$key]);
                            array_push($itemIds, (string) $saveShipment->getInsertedId());
                            $orderCollection->updateOne(
                                [
                                    'user_id' => $data['user_id'],
                                    'object_type' => 'target_order_items',
                                    'cif_order_id' => $targetData['cif_order_id'],
                                    'marketplace_item_id' => (string) $value['marketplace_item_id']
                                ],
                                ['$set' => ['item_status' => $itemStatus, 'shipped_qty' => $shippedQty]]
                            );
                        }

                        $orderCollection->updateOne(
                            ['_id' => $id],
                            ['$set' => [
                                'items' => $items,
                                'shipment_id' => (string) $id,
                                'target_shipment_id' => $itemIds
                            ]]
                        );
                        $sourceParams = [
                            'user_id' => $data['user_id'],
                            'object_type' => 'source_order',
                            'marketplace' => $data['source_marketplace'],
                            'marketplace_shop_id' => (string)$data['source_shop_id'],
                            'marketplace_reference_id' => (string)$getItems['orderId'],
                            'targets.marketplace' => (string)$data['target_marketplace']
                        ];
                        $targetParams = [
                            'user_id' => $data['user_id'],
                            'object_type' => 'target_order',
                            'marketplace' => $data['target_marketplace'],
                            'marketplace_shop_id' => (string)$data['target_shop_id'],
                            'marketplace_reference_id' => (string) $data['marketplace_reference_id']
                        ];
                        $prepareDocData = [
                            'sourceParams' => $sourceParams,
                            'targetParams' => $targetParams,
                            'orderItems' => $getItems['orderItems'],
                            'status' => $targetData['status'],
                            'parentStatus' => $parentStatus
                        ];
                        $this->updateDocs($prepareDocData);
                        $targetShipment['shipment_id'] = (string) $id;
                    }
                    return ['success' => true, 'data' => $targetShipment];
                } else {
                    if (!empty($shipmentData)) {
                        $tracking = $shipmentData['tracking'];
                        if (
                            $tracking['company'] == $data['tracking']['company']
                            && $tracking['number'] == $data['tracking']['number']
                            && ($targetData['status'] == "Shipped" || $targetData['status'] == "Partially Shipped")
                        ) {
                            return ['success' => false, 'message' => 'Same Fulfillment tried to re-attempt'];
                        } else {
                            $toBeUpdated = true;
                        }
                    }
                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $orderCollection = $mongo->getCollectionForTable('order_container');
                    $message = 'Product Not Found SKU: ';
                    foreach ($sourceShipment['items'] as $sourceItem) {
                        $matchedItem = [];
                        foreach ($targetData['items'] as $targetItem) {
                            if ($sourceItem['item_link_id'] == $targetItem['item_link_id']) {
                                $matchedItem = $targetItem;
                                break;
                            }
                        }
                            if (!empty($matchedItem)) {
                                $temp = [
                                    "type" => $matchedItem['type'],
                                    "title" => $matchedItem['title'],
                                    "sku" => $matchedItem['sku'],
                                    "qty" => $matchedItem['qty'],
                                    "weight" => $sourceItem['weight'],
                                    "shipped_qty" => $sourceItem['shipped_qty'],
                                    "object_type" => "target_shipment_items",
                                    "user_id" => $data['user_id'],
                                    "cif_order_id" => $targetData['cif_order_id'],
                                    "marketplace_item_id" => $matchedItem['marketplace_item_id'] ?? "",
                                    "item_link_id" => $sourceItem['item_link_id'],
                                    'item_status' => $sourceItem['shipped_qty'] == $matchedItem['qty']
                                        ? "Shipped" : "Partially Shipped",
                                ];
                                //Handled Shipment Syncing for Bundled Products
                                if (!empty($sourceItem['is_bundled']) && $targetItem['qty'] < $temp['shipped_qty']) {
                                    $temp['shipped_qty'] = $targetItem['qty'];
                                }
                                array_push($items, $temp);
                            } else {
                                $errorSku[] = $message . $sourceItem['sku'];
                            }
                    }
                    if (!empty($errorSku)) {
                        return $this->checkInvalidSku($errorSku, $targetParams);
                    }
                    if (isset($targetData['shipment_error'])) {
                        $orderCollection->updateOne($targetParams, ['$set' => ["shipping_status" => "In Progress"]]);
                    }
                    $prepareData = [
                        'object_type' => 'target_shipment',
                        'marketplace' => $data['target_marketplace'],
                        'shop_id' => $data['target_shop_id'],
                        'targets_marketplace' => $data['source_marketplace'],
                        'targets_shop_id' => $data['source_shop_id'],
                        'targets_status' => $sourceShipment['status'],
                        'targets_order_id' => $data['marketplace_reference_id'],
                        'marketplace_reference_id' => $targetData['marketplace_reference_id'],
                        'cif_order_id' => $targetData['cif_order_id'],
                        'data' => $data,
                        'items' => $items,
                    ];
                    $targetShipment = $this->prepareShipmentData($prepareData);
                    if ($toBeUpdated) {
                        $targetShipmentCopy = $targetShipment;
                        unset($targetShipmentCopy['items']);
                        unset($targetShipmentCopy['created_at']);
                        $orderCollection->updateOne($shipmentParams, ['$set' => $targetShipmentCopy]);
                    } else {
                        $itemIds = [];
                        $saveTargetShipment = $orderCollection->insertOne($targetShipment);
                        $id = $saveTargetShipment->getInsertedId();

                        foreach ($items as $key => $targetShipmentItem) {
                            $items[$key]['shipment_id'] = (string) $id;
                            $saveShipment = $orderCollection->insertOne($items[$key]);
                            array_push($itemIds, (string) $saveShipment->getInsertedId());
                        }
                        $orderCollection->updateOne(
                            ['_id' => $id],
                            ['$set' => [
                                'items' => $items,
                                'shipment_id' => (string) $id,
                                "target_shipment_id" => $itemIds
                            ]]
                        );
                        $targetShipment['items'] = $items;
                        $referenceParams = [
                            'object_type' => "target_shipment",
                            'user_id' => $data['user_id'],
                            'marketplace' => $targetData['marketplace'],
                            'marketplace_shop_id' => $targetData['marketplace_shop_id'],
                            'marketplace_reference_id' => $targetData['marketplace_reference_id'],
                            'marketplace_shipment_id' => $sourceShipment['marketplace_shipment_id']
                        ];
                        $this->addKey($referenceParams,$sourceShipment['shipment_id']);
                    }
                    $this->addLog($this->logFile, 'Target Shipment Prepared Successfully', "", 'optional');
                    return ['success' => true, 'data' => $targetShipment];
                }
                if (!empty($shipmentData)) {
                    $tracking = $shipmentData['tracking'];
                    if (
                        $tracking['company'] == $data['tracking']['company']
                        && $tracking['number'] == $data['tracking']['number']
                        && ($targetData['status'] == "Shipped" || $targetData['status'] == "Partially Shipped")
                    ) {
                        return ['success' => false, 'message' => 'Same Fulfillment tried to re-attempt'];
                    }
                    $toBeUpdated = true;
                }
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $orderCollection = $mongo->getCollectionForTable('order_container');
                $message = 'Product Not Found SKU: ';
                foreach ($sourceShipment['items'] as $sourceItem) {
                    $matchedItem = [];
                    foreach ($targetData['items'] as $targetItem) {
                        if ($sourceItem['item_link_id'] == $targetItem['item_link_id']) {
                            $matchedItem = $targetItem;
                            break;
                        }
                    }

                        if (!empty($matchedItem)) {
                            $temp = [
                                "type" => $matchedItem['type'],
                                "title" => $matchedItem['title'],
                                "sku" => $matchedItem['sku'],
                                "qty" => $matchedItem['qty'],
                                "weight" => $sourceItem['weight'],
                                "shipped_qty" => $sourceItem['shipped_qty'],
                                "object_type" => "target_shipment_items",
                                "user_id" => $data['user_id'],
                                "cif_order_id" => $targetData['cif_order_id'],
                                "marketplace_item_id" => $matchedItem['marketplace_item_id'] ?? "",
                                "item_link_id" => $sourceItem['item_link_id'],
                                'item_status' => $sourceItem['shipped_qty'] == $matchedItem['qty']
                                    ? "Shipped" : "Partially Shipped",
                            ];
                            array_push($items, $temp);
                        } else {
                            $errorSku[] = $message . $sourceItem['sku'];
                        }
                }
                if (!empty($errorSku)) {
                    return $this->checkInvalidSku($errorSku, $targetParams);
                }
                if (isset($targetData['shipment_error'])) {
                    $orderCollection->updateOne($targetParams, ['$set' => ["shipping_status" => "In Progress"]]);
                }
                $prepareData = [
                    'object_type' => 'target_shipment',
                    'marketplace' => $data['target_marketplace'],
                    'shop_id' => $data['target_shop_id'],
                    'targets_marketplace' => $data['source_marketplace'],
                    'targets_shop_id' => $data['source_shop_id'],
                    'targets_status' => $sourceShipment['status'],
                    'targets_order_id' => $data['marketplace_reference_id'],
                    'marketplace_reference_id' => $targetData['marketplace_reference_id'],
                    'cif_order_id' => $targetData['cif_order_id'],
                    'data' => $data,
                    'items' => $items,
                ];
                $targetShipment = $this->prepareShipmentData($prepareData);
                if ($toBeUpdated) {
                    $targetShipmentCopy = $targetShipment;
                    unset($targetShipmentCopy['items']);
                    unset($targetShipmentCopy['created_at']);
                    $orderCollection->updateOne($shipmentParams, ['$set' => $targetShipmentCopy]);
                } else {
                    $itemIds = [];
                    $saveTargetShipment = $orderCollection->insertOne($targetShipment);
                    $id = $saveTargetShipment->getInsertedId();

                    foreach ($items as $key => $targetShipmentItem) {
                        $items[$key]['shipment_id'] = (string) $id;
                        $saveShipment = $orderCollection->insertOne($items[$key]);
                        array_push($itemIds, (string) $saveShipment->getInsertedId());
                    }

                    $orderCollection->updateOne(
                        ['_id' => $id],
                        ['$set' => [
                            'items' => $items,
                            'shipment_id' => (string) $id,
                            "target_shipment_id" => $itemIds
                        ]]
                    );
                    $targetShipment['items'] = $items;
                    $referenceParams = [
                        'object_type' => "target_shipment",
                        'user_id' => $data['user_id'],
                        'marketplace' => $targetData['marketplace'],
                        'marketplace_shop_id' => $targetData['marketplace_shop_id'],
                        'marketplace_reference_id' => $targetData['marketplace_reference_id'],
                        'marketplace_shipment_id' => $sourceShipment['marketplace_shipment_id']
                    ];
                    $this->addKey($referenceParams,$sourceShipment['shipment_id']);
                }
                $this->di->getLog()->logContent('Target Shipment Prepared Successfully', 'info', $this->logFile);
                return ['success' => true, 'data' => $targetShipment];
            }
        }

        return ['success' => false, 'message' => 'Unable to Prepare Shipment'];
    }

    /**
     * prepareSourceShipment function
     * For preparing Source Shipment
     * @param [array] $data
     * @param [array] $targetShipment
     * @return array
     */
    public function prepareSourceShipment(array $data, array $targetShipment = [])
    {
        $sourceOrderId = '';
        if ($this->objectType != "source_order") {
            foreach ($targetShipment['targets'] as $source) {
                if (($source['marketplace'] == $data['source_marketplace'])
                    && $source['shop_id'] == $data['source_shop_id']
                ) {
                    $sourceOrderId = $source['order_id'];
                }
            }
        } else {
            $sourceOrderId = $data['marketplace_reference_id'];
        }

        $items = [];
        $errorSku = [];
        if (!empty($sourceOrderId)) {
            $orderCollection = $this->mongo->getCollectionForTable('order_container');
            $sourceParams = [
                'user_id' => $data['user_id'],
                'object_type' => 'source_order',
                'marketplace' => $data['source_marketplace'],
                'marketplace_shop_id' => $data['source_shop_id'],
                'marketplace_reference_id' => $sourceOrderId
            ];
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $sourceData = $orderCollection->findOne($sourceParams, $arrayParams);
            if (!empty($sourceData)) {
                $toBeUpdated = false;
                $shipmentParams = [
                    'user_id' => $data['user_id'],
                    'object_type' => 'source_shipment',
                    'marketplace' => $sourceData['marketplace'],
                    'marketplace_shop_id' => $sourceData['marketplace_shop_id'],
                    'marketplace_shipment_id' => (string) $data['marketplace_shipment_id']
                ];
                if (!isset($sourceData['shipping_status'])) {
                    $shipmentData = [];
                } else {
                    $shipmentData = $orderCollection->findOne($shipmentParams, $arrayParams);
                }

                if ($sourceData['object_type'] == $this->objectType) {
                    if (!empty($shipmentData)) {
                        $toBeUpdated = true;
                    }

                    $targetOrderId = $sourceData['targets'][0]['order_id'];
                    $getItems = $this->prepareItems($data, $sourceData, $toBeUpdated);
                    $prepareData = [
                        'object_type' => 'source_shipment',
                        'marketplace' => $data['source_marketplace'],
                        'shop_id' => $data['source_shop_id'],
                        'targets_marketplace' => $data['target_marketplace'],
                        'targets_status' => 'In Progress',
                        'targets_shop_id' => $data['target_shop_id'],
                        'targets_order_id' => $getItems['orderId'],
                        'marketplace_reference_id' => $sourceData['marketplace_reference_id'],
                        'cif_order_id' => $sourceData['cif_order_id'],
                        'items' => $getItems['items'],
                        'totalQty' => $getItems['totalQuantity'],
                        'shippedQty' => $getItems['quantityShipped'],
                        'data' => $data,

                    ];
                    $sourceShipment = $this->prepareShipmentData($prepareData);
                    if ($toBeUpdated) {
                        if (strtotime($sourceShipment['source_updated_at'])
                            > strtotime($shipmentData['source_updated_at'])) {
                            $sourceShipment['shipment_id'] = $shipmentData['shipment_id'];
                            $sourceShipmentCopy = $sourceShipment;
                            unset($sourceShipmentCopy['items']);
                            unset($sourceShipmentCopy['created_at']);
                            $orderCollection->updateOne($shipmentParams, ['$set' => $sourceShipmentCopy]);
                        } else {
                            return ['success' => false, 'message' => 'Attempting to update stale information'];
                        }
                    } else {
                        $saveShipment = $orderCollection->insertOne($sourceShipment);
                        $id = $saveShipment->getInsertedId();
                        $itemIds = [];
                        $items = $getItems['items'];
                        $parentStatus = $getItems['totalQuantity']
                        == $getItems['savedShippedQuantity'] ? "Shipped" : "Partially Shipped";
                        foreach ($items as $key => $value) {
                            $shippedQty = $getItems['orderItems'][$value['marketplace_item_id']]['shipped_qty'];
                            $itemStatus = $shippedQty == $value['qty'] ? 'Shipped' : 'Partially Shipped';
                            $items[$key]['shipment_id'] = (string) $id;
                            $saveShipment = $orderCollection->insertOne($items[$key]);
                            array_push($itemIds, (string) $saveShipment->getInsertedId());
                            $orderCollection->updateOne(
                                [
                                    'user_id' => $data['user_id'],
                                    'object_type' => 'source_order_items',
                                    'cif_order_id' => $sourceData['cif_order_id'],
                                    'marketplace_item_id' => (string) $value['marketplace_item_id']
                                ],
                                ['$set' => ['item_status' => $itemStatus, 'shipped_qty' => $shippedQty]]
                            );
                        }

                        $orderCollection->updateOne(
                            ['_id' => $id],
                            ['$set' => [
                                'items' => $items,
                                'shipment_id' => (string) $id,
                                "source_shipment_id" => $itemIds
                            ]]
                        );

                        $sourceParams = [
                            'user_id' => $data['user_id'],
                            'object_type' => 'source_order',
                            'marketplace' => $data['source_marketplace'],
                            "marketplace_shop_id" => (string)$data['source_shop_id'],
                            'marketplace_reference_id' => (string) $data['marketplace_reference_id']
                        ];
                        $targetParams = [
                            'user_id' => $data['user_id'],
                            'object_type' => 'target_order',
                            'marketplace' => $data['target_marketplace'],
                            "marketplace_shop_id" => (string)$data['target_shop_id'],
                            'marketplace_reference_id' => (string)$targetOrderId,
                            "targets.marketplace" => (string)$data['source_marketplace']
                        ];
                        $prepareDocData = [
                            'sourceParams' => $targetParams,
                            'targetParams' => $sourceParams,
                            'orderItems' => $getItems['orderItems'],
                            'status' => $sourceData['status'],
                            'parentStatus' => $parentStatus
                        ];
                        $this->updateDocs($prepareDocData);
                        $sourceShipment['shipment_id'] = (string) $id;
                    }

                    return ['success' => true, 'data' => $sourceShipment];
                }

                if (!empty($shipmentData)) {
                    $tracking = $shipmentData['tracking'];
                    if (
                        $tracking['company'] == $data['tracking']['company']
                        && $tracking['number'] == $data['tracking']['number']
                        && ($sourceData['status'] == "Shipped" || $sourceData['status'] == "Partially Shipped")
                    ) {
                        return ['success' => false, 'message' => 'Same Fulfillment tried to re-attempt'];
                    }
                    $toBeUpdated = true;
                }
                $message = "Product Not Found SKU: ";
                foreach ($targetShipment['items'] as $targetItem) {
                    $matchedItem = [];
                    foreach ($sourceData['items'] as $sourceItem) {
                        if ($targetItem['item_link_id'] == $sourceItem['item_link_id']) {
                            $matchedItem = $sourceItem;
                            break;
                        }
                    }
                    if (!empty($matchedItem)) {
                        $temp = [
                            "type" => $matchedItem['type'],
                            "title" => $matchedItem['title'],
                            "sku" => $matchedItem['sku'],
                            "qty" => $matchedItem['qty'],
                            "weight" => $targetItem['weight'],
                            "shipped_qty" => $targetItem['shipped_qty'],
                            "object_type" => "source_shipment_items",
                            "user_id" => $data['user_id'],
                            "cif_order_id" => $sourceData['cif_order_id'],
                            "marketplace_item_id" => $matchedItem['marketplace_item_id'] ?? "",
                            "item_link_id" => $targetItem['item_link_id'],
                            'item_status' => $targetItem['shipped_qty'] == $matchedItem['qty']
                                ? "Shipped" : "Partially Shipped",
                        ];
                        //Handled Shipment Syncing for Bundled Products
                        if (!empty($targetItem['is_bundled']) && $sourceItem['qty'] < $temp['shipped_qty']) {
                            $temp['shipped_qty'] = $sourceItem['qty'];
                        }
                        array_push($items, $temp);
                    } else {
                        $errorSku[] = $message . $targetItem['sku'];
                    }
                }

                if (!empty($errorSku)) {

                    return $this->checkInvalidSku($errorSku, $sourceParams);
                }

                if (isset($sourceData['shipment_error'])) {
                    $orderCollection->updateOne($sourceParams, ['$set' => ["shipping_status" => "In Progress"]]);
                }

                $prepareData = [
                    'object_type' => 'source_shipment',
                    'marketplace' => $data['source_marketplace'],
                    'shop_id' => $data['source_shop_id'],
                    'targets_marketplace' => $data['target_marketplace'],
                    'targets_status' => $targetShipment['status'],
                    'targets_shop_id' => $data['target_shop_id'],
                    'targets_order_id' => $targetShipment['marketplace_reference_id'],
                    'marketplace_reference_id' => $sourceData['marketplace_reference_id'],
                    'cif_order_id' => $sourceData['cif_order_id'],
                    'data' => $data,
                    'items' => $items
                ];
                $sourceShipment = $this->prepareShipmentData($prepareData);
                if ($toBeUpdated) {
                    $sourceShipmentCopy = $sourceShipment;
                    unset($sourceShipmentCopy['items']);
                    unset($sourceShipmentCopy['created_at']);
                    $orderCollection->updateOne($shipmentParams, ['$set' => $sourceShipmentCopy]);
                } else {
                    $itemIds = [];
                    $saveSourceShipment = $orderCollection->insertOne($sourceShipment);
                    $id = $saveSourceShipment->getInsertedId();

                    foreach ($items as $key => $sourceShipmentItem) {
                        $items[$key]['shipment_id'] = (string) $id;
                        $saveShipment = $orderCollection->insertOne($items[$key]);
                        array_push($itemIds, (string) $saveShipment->getInsertedId());
                    }

                    $orderCollection->updateOne(
                        ['_id' => $id],
                        ['$set' => ['items' => $items, 'shipment_id' => (string) $id, "source_shipment_id" => $itemIds]]
                    );
                    $sourceShipment['items'] = $items;
                    $referenceParams = [
                        'object_type' => "source_shipment",
                        'user_id' => $data['user_id'],
                        'marketplace' => $sourceData['marketplace'],
                        'marketplace_shop_id' => $sourceData['marketplace_shop_id'],
                        'marketplace_reference_id' => $sourceData['marketplace_reference_id'],
                        'marketplace_shipment_id' => $targetShipment['marketplace_shipment_id']
                    ];
                    $this->addKey($referenceParams, $targetShipment['shipment_id']);
                }
                $this->addLog($this->logFile, 'Source Shipment Prepared Successfully', "", 'optional');
                return ['success' => true, 'data' => $sourceShipment];
            }
        }

        return ['success' => false, 'message' => 'Unable to Prepare Shipment'];
    }

    /**
     * setSourceStatus function
     * For Syncing Source or Target Status
     * @param [string] $orderId
     * @param [string] $marketplace
     * @param boolean $success
     * @param string $reason
     * @param string $userId
     * @param boolean $sendMail
     * @return array
     */
    public function setSourceStatus($orderId, $marketplace, $success = false, $reason = 'Unknown', $userId = '', $sendMail = false)
    {
        if ((!empty($orderId)) && (!empty($marketplace))) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $orderCollection = $mongo->getCollectionForTable('order_container');
            $shipmentMailCollection = $mongo->getCollectionForTable('failed_shipment');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $object = $this->di->getObjectManager()->get("App\\Connector\\Components\\Order\\OrderStatus");
            if (empty($userId)) {
                $userId = $this->di->getUser()->id;
            }

            $params = [
                'user_id' => $userId,
                'object_type' => ['$in' => ['source_order','target_order']],
                'marketplace_reference_id' => $orderId
            ];
            $orderData = $orderCollection->findOne($params, $arrayParams);
            $objectType = $orderData['object_type'];
            $shippingStatus = '';
            if (!empty($orderData)) {
                $userId = empty($userId) ? $orderData['user_id'] : $userId;
                $updateParams = [
                    'user_id' => $userId,
                    'object_type' => $objectType,
                    'marketplace' => $orderData['marketplace'],
                    'marketplace_shop_id' => $orderData['marketplace_shop_id'],
                    'marketplace_reference_id' => $orderId,
                ];
                if ($success) {
                    if ($objectType == "source_order") {
                        $fetchParams = [
                            'user_id' => $userId,
                            'object_type' => 'target_order',
                            'marketplace' => $marketplace,
                            'targets.order_id' => $orderId
                        ];
                    } else {
                        $fetchParams = [
                            'user_id' => $userId,
                            'object_type' => 'source_order',
                            'marketplace' => $marketplace,
                            'targets.order_id' => $orderId
                        ];
                    }

                    $getData = $orderCollection->findOne($fetchParams, $arrayParams);
                    if (!empty($getData)) {
                        $status = $getData['shipping_status'];
                    }

                    if (isset($orderData['shipment_error'])) {
                        if ($orderData['shipping_status'] != 'Unshipped' && $orderData['shipping_status'] != 'Failed') {
                            $filter[] =
                                [
                                    'updateOne' => [
                                        $updateParams,
                                        ['$unset' => ["shipment_error" => true]],
                                    ],
                                ];
                        } else {
                            return [
                                "success" => false,
                                "message" => "Shipment Sync Failed",
                            ];
                        }
                    }

                    $response = $object->validateStatus($orderData['status'], $status);
                    if ($response) {
                        $filter[] =
                            [
                                'updateOne' => [
                                    $updateParams,
                                    ['$set' => ["status" => $status, 'shipping_status' => $status]],
                                ],
                            ];
                    } else {
                        $filter[] = [
                            'updateOne' => [
                                $updateParams,
                                ['$set' => ['shipping_status' => $status]],
                            ],
                        ];
                    }
                    foreach ($getData['items'] as $getItem) {
                        $matchedItem = false;
                        if (isset($getItem['shipped_qty']) && !empty($getItem['shipped_qty'])) {
                            $shippedQty = $getItem['shipped_qty'];
                            $itemStatus = $getItem['shipped_qty'] == $getItem['qty'] ? 'Shipped' : 'Partially Shipped';
                            foreach ($orderData['items'] as $orderItem) {
                                if ($orderItem['item_link_id'] == $getItem['item_link_id']) {
                                        $matchedItem = true;
                                        break;
                                }
                            }

                            if ($matchedItem) {
                                $filter[] =
                                    [
                                        'updateOne' => [
                                            [
                                                'user_id' => $userId,
                                                'object_type' => $objectType,
                                                'marketplace' => $orderData['marketplace'],
                                                'marketplace_shop_id' => $orderData['marketplace_shop_id'],
                                                'cif_order_id' => $orderItem['cif_order_id'],
                                                'items.marketplace_item_id' => (string)$orderItem['marketplace_item_id'],
                                            ],
                                            ['$set' => ["items.$.shipped_qty" => $shippedQty]],
                                        ],
                                    ];
                                $filter[] =
                                    [
                                        'updateOne' => [
                                            [
                                                'user_id' => $userId,
                                                'object_type' => $objectType . "_items",
                                                'cif_order_id' => $orderItem['cif_order_id'],
                                                'marketplace_item_id' => (string)$orderItem['marketplace_item_id'],
                                            ],
                                            ['$set' => ["shipped_qty" => $shippedQty, "item_status" => $itemStatus]],
                                        ],
                                    ];
                            }
                        }
                    }

                    foreach ($orderData['targets'] as $sourceTarget) {
                        if ($sourceTarget['marketplace'] == $marketplace) {
                            $filter[] =
                                [
                                    'updateOne' => [
                                        [
                                            'user_id' => $userId,
                                            'object_type' => $getData['object_type'],
                                            'marketplace' => $getData['marketplace'],
                                            "marketplace_shop_id" => $getData['marketplace_shop_id'],
                                            'marketplace_reference_id' => $getData['marketplace_reference_id'],
                                            "targets.marketplace" => $orderData['marketplace'],
                                        ],
                                        ['$set' => ["targets.$.status" => $status]],
                                    ],
                                ];

                            if (!empty($filter)) {
                                $orderCollection->bulkWrite($filter);
                            }

                            return [
                                "success" => true,
                                "message" => "Status Changed Successfully!!",
                                'shipping_status' => $status,
                            ];
                        }
                    }
                } else {
                    if ($sendMail) {
                        $prepareData = [
                            'source_order_id' => $orderData['marketplace_reference_id'],
                            'source_marketplace' => $orderData['marketplace'],
                            'target_order_id' => $orderData['targets'][0]['order_id'],
                            'target_marketplace' => $orderData['targets'][0]['marketplace'],
                            'user_id' => $userId,
                            'reason' => $reason,
                            'created_at' => date('c')
                        ];
                        $shipmentMailCollection->insertOne($prepareData);
                        $setValue['shipment_mail'] = true;
                        $eventData = [
                            'user_id' => $userId,
                            'target_shop_id' => $orderData['shop_id'],
                            'source_shop_id' => $orderData['targets'][0]['shop_id']
                        ];
                        $eventsManager = $this->di->getEventsManager();
                        $eventsManager->fire('application:orderShipmentFail', $this, $eventData);
                    }

                    $shippingStatus = "Unshipped";
                    $setValue['shipping_status'] = $shippingStatus;
                    $orderCollection->updateOne(
                        $updateParams,
                        [
                            '$set' => $setValue,
                            '$addToSet' => ['shipment_error' => $reason]
                        ]
                    );
                    return [
                        "success" => false,
                        "message" => "Shipment Unsuccessful",
                        'shipping_status' => $shippingStatus
                    ];
                }
            }
        }
    }

    /**
     * prepareShipmentData function
     * Preparing Source or Target Shipment Data
     * @param [array] $prepareData
     */
    public function prepareShipmentData(array $prepareData): array
    {
        $status = "In Progress";
        if (isset($prepareData['totalQty']) && isset($prepareData['shippedQty'])) {
            $status = $prepareData['totalQty'] == $prepareData['shippedQty']
                ? "Shipped" : "Partially Shipped";
        }

        return [
            "schema_version" => "2.0",
            "object_type" => $prepareData['object_type'],
            "cif_order_id" => $prepareData['cif_order_id'],
            "shop_id" => $prepareData['shop_id'],
            "marketplace" => $prepareData['marketplace'],
            "marketplace_shop_id" => $prepareData['shop_id'],
            "marketplace_reference_id" => $prepareData['marketplace_reference_id'],
            "marketplace_shipment_id" => (string) $prepareData['data']['marketplace_shipment_id'],
            "user_id" => $prepareData['data']['user_id'],
            "status" => $status,
            "targets" => [
                [
                    "marketplace" => $prepareData['targets_marketplace'],
                    "order_id" => $prepareData['targets_order_id'],
                    "status" => $prepareData['targets_status'],
                    "shop_id" => $prepareData['targets_shop_id'],

                ],
            ],
            "items" => $prepareData['items'],
            "shipping_status" => $prepareData['data']['shipment_status']
                ?? $prepareData['data']['shipping_status'] ?? $status,
            "shipping_method" => [
                "code" => $prepareData['data']['shipping_method']['code']
                ?? $prepareData['data']['tracking']['company'],
                "title" => $prepareData['data']['shipping_method']['title']
                ?? $prepareData['data']['tracking']['company'],
                "carrier" => $prepareData['data']['shipping_method']['carrier']
                ?? $prepareData['data']['tracking']['company'],
            ],
            "tracking" => [
                "company" => $prepareData['data']['shipping_method']['title']
                ?? $prepareData['data']['tracking']['company'],
                "number" => $prepareData['data']['tracking']['number'] ?? '',
                "name" => $prepareData['data']['tracking']['name'] ?? '',
                "url" => $prepareData['data']['tracking']['url'] ?? '',
            ],
            "created_at" => date('c'),
            "updated_at" => date('c'),
            "source_created_at" => $prepareData['data']['shipment_created_at'],
            "source_updated_at" => $prepareData['data']['shipment_updated_at'],
            "attributes" => $prepareData['data']['attributes'] ?? '',

        ];
    }

    /**
     * prepareItems function
     * For preparing Source or Target Shipment Items
     * @param [array] $data
     * @param [array] $sourceOrTargetData
     * @param [string] $toBeUpdated
     */
    public function prepareItems(array $data, array $sourceOrTargetData, $toBeUpdated): array
    {
        $objectType = 'source_shipment_items';
        $marketplace = 'target_marketplace';
        if ($this->objectType != "source_order") {
            $objectType = 'target_shipment_items';
            $marketplace = 'source_marketplace';
        }

        $items = [];
        $totalQuantity = 0;
        $quantityShipped = 0;
        $orderItems = [];
        $savedShippedQuantity = 0;
        foreach ($sourceOrTargetData['items'] as $key => $value) {
            $orderItems[(string) $value['marketplace_item_id']] = $value;
            $totalQuantity = $totalQuantity + $value['qty'] ?? 0;
        }

        foreach ($data['items'] as $value) {
            $temp = [
                "type" => $value['type'],
                "title" => $value['title'],
                "sku" => $value['sku'],
                "qty" => $orderItems[$value['marketplace_item_id']]['qty'] ?? "",
                "weight" => $value['weight'],
                "shipped_qty" => $value['shipped_qty'],
                "object_type" => $objectType,
                "user_id" => $data['user_id'],
                "cif_order_id" => $sourceOrTargetData['cif_order_id'],
                "product_identifier" => $value['product_identifier'],
                "marketplace_item_id" => $value['marketplace_item_id'],
                "item_link_id" => $orderItems[$value['marketplace_item_id']]['item_link_id'],
                'item_status' => $orderItems[$value['marketplace_item_id']]['qty'] == $value['shipped_qty']
                    ? "Shipped" : "Partially Shipped",
            ];
            //Handled Shipment Syncing for Bundled Products
            if (isset($orderItems[$value['marketplace_item_id']]['is_bundled'])) {
                $temp['is_bundled'] = $orderItems[$value['marketplace_item_id']]['is_bundled'];
            }
            array_push($items, $temp);
            $quantityShipped = $quantityShipped + $value['shipped_qty'] ?? 0;
            if (isset($orderItems[$value['marketplace_item_id']]) && !$toBeUpdated) {
                $tempQty = isset($orderItems[$value['marketplace_item_id']]['shipped_qty'])
                    ? (int) $orderItems[$value['marketplace_item_id']]['shipped_qty'] : 0;
                if ($tempQty != $temp['qty']) {
                    $orderItems[$value['marketplace_item_id']]['shipped_qty'] = $tempQty + (int) $temp['shipped_qty'];
                }
            }
        }

        foreach ($orderItems as $value) {
            if (isset($value['shipped_qty'])) {
                $savedShippedQuantity = $savedShippedQuantity + $value['shipped_qty'];
            }
        }

        $orderId = '';
        foreach ($sourceOrTargetData['targets'] as $value) {
            if ($value['marketplace'] == $data[$marketplace]) {
                $orderId = $value['order_id'];
            }
        }

        return [
            'data' => $data,
            'orderData' => $sourceOrTargetData,
            'totalQuantity' => $totalQuantity,
            'quantityShipped' => $quantityShipped,
            'savedShippedQuantity' => $savedShippedQuantity,
            'orderItems' => $orderItems,
            'items' => $items,
            'orderId' => $orderId
        ];
    }

    /**
     * updateDocs function
     * For updating Source and Target Docs
     * @param [array] $data
     */
    public function updateDocs(array $data): void
    {
        $orderCollection = $this->mongo->getCollectionForTable('order_container');
        $object = $this->di->getObjectManager()->get("App\\Connector\\Components\\Order\\OrderStatus");
        if (!isset($data['status'])) {
            $filter[] =
                [
                    'updateOne' => [
                        $data['targetParams'],
                        ['$set' => [
                            'items' => array_values($data['orderItems']),
                            'status' => $data['parentStatus'],
                            'shipping_status' => $data['parentStatus']
                        ]],
                    ]
                ];
            $filter[] =
                [
                    'updateOne' => [
                        $data['sourceParams'],
                        ['$set' => [
                            'targets.$.status' => $data['parentStatus'],
                            'shipping_status' => 'In Progress'
                        ]],
                    ]
                ];
        } else {
            $response = $object->validateStatus($data['status'], $data['parentStatus']);
            if ($response) {
                $filter[] =
                    [
                        'updateOne' => [
                            $data['targetParams'],
                            ['$set' => [
                                'items' => array_values($data['orderItems']),
                                'status' => $data['parentStatus'], 'shipping_status' => $data['parentStatus']
                            ]],
                        ]
                    ];
                $filter[] =
                    [
                        'updateOne' => [
                            $data['sourceParams'],
                            ['$set' => [
                                "targets.$.status" => $data['parentStatus'],
                                'shipping_status' => 'In Progress'
                            ]],
                        ]
                    ];
            } else {
                $filter[] =
                    [
                        'updateOne' => [
                            $data['targetParams'],
                            ['$set' => [
                                'items' => array_values($data['orderItems']),
                                'shipping_status' => $data['parentStatus']
                            ]],
                        ]
                    ];
                $filter[] =
                    [
                        'updateOne' => [
                            $data['sourceParams'],
                            ['$set' => ['shipping_status' => 'In Progress']],
                        ]
                    ];
            }
        }

        if (!empty($filter)) {
            $orderCollection->bulkWrite($filter);
        }
    }

    /**
     * checkInvalidSku function
     * For checking Invalid Mapped Sku Error
     * @param [array] $errorSku
     * @param [array] $params
     * @param [string] $message
     */
    public function checkInvalidSku($errorSku, $params): array
    {
        $orderCollection = $this->mongo->getCollectionForTable('order_container');
        foreach ($errorSku as $sku) {
            $filter[] =
                [
                    'updateOne' => [
                        $params,
                        ['$set' => ['shipping_status' => "Failed"], '$addToSet' => [
                            'shipment_error' => $sku,
                        ]],
                    ],
                ];
            $this->addLog($this->logFile, 'Shipment Sync Failed Error: ', $sku, 'critical');
        }

        if (!empty($filter)) {
            $orderCollection->bulkWrite($filter);
        }

        return ['success' => false, "message" => 'Invalid Mapped SKU or Product Not Found'];
    }

    /**
     * addKey function
     * For Creating Relation between Source and Target Shipment Doc
     * Handling DeadLock Case
     * @param [string] $objectType
     * @param [string] $orderId
     * @param [array] $data
     */
    public function addKey($updateParams,$referenceId): array
    {
        $orderCollection = $this->mongo->getCollectionForTable('order_container');
        $orderCollection->updateOne(
            $updateParams,
            ['$set' => [
                "reference_id" => $referenceId,
            ]]
        );

        return ['success' => true, 'message' => 'Doc Linked'];
    }

    /**
     * checkSku function
     * To check Same SKU in all line items
     * @param [array] $items
     */
    public function checkSku($items): bool
    {
        $getSku = array_column($items, "sku");
        $count = count($getSku);
        $finalSku = array_unique($getSku);
        $uniqueCount = count($finalSku);
        if ($count != $uniqueCount) {
            return true;
        }

        return false;
    }

    /**
     * isShippable function
     * Abstract Function
     */
    public function isShippable(): bool
    {
        return true;
    }

    /**
     * mold function
     * Abstract Function
     */
    public function mold(array $data): array
    {
        return [];
    }

    public function addLog($file, $key, $data = "", $priorityLevel = null)
    {
        $orderLogConfig = $this->di->getConfig()->path('logger_config.shipment_sync');
        $priorityEnabled = $orderLogConfig['priority'][$priorityLevel] ?? true;
        $isLogEnabled = $orderLogConfig['enabled'] ?? true;
        $shipmentLogEnabled = ($priorityEnabled && $isLogEnabled);
        if ($shipmentLogEnabled) {
            if (!empty($data)) {
                $this->di->getLog()->logContent($key . ' ' . json_encode($data, true), 'info', $file);
            } else {
                $this->di->getLog()->logContent($key, 'info', $file);
            }
        }
    }
}