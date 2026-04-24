<?php

/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Connector\Service;

use App\Connector\Contracts\Sales\Order\ShipInterface;
use App\Connector\Service\AbstractShipment;
use MongoDB\BSON\UTCDateTime;

/**
 * Class ShipmentTransaction
 * This class is responsible for creating source
 * and target shipment documents
 * Interface ShipInterface
 * @services
 */
#[\AllowDynamicProperties]
class ShipmentTransaction extends AbstractShipment implements ShipInterface
{
    const SOURCE = 'source';
    const TARGET = 'target';
    const SOURCE_ORDER = 'source_order';
    const TARGET_ORDER = 'target_order';
    const SOURCE_SHIPMENT = 'source_shipment';
    const TARGET_SHIPMENT = 'target_shipment';
    const STATUS = 'In Progress';
    private $mongo;
    private $orderCollection;
    private $arrayParams;
    private $userId;
    private $objectType = '';
    private $logFile;

    /**
     * init function
     * To initialize mongo objects
     * @return null
     */
    private function init()
    {
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->orderCollection = $this->mongo->getCollectionForTable('order_container');
        $this->arrayParams = ['root' => 'array', 'document' => 'array'];
    }

    /**
     * ship function
     * To initate shipment syncing process
     * @param array $data
     * @return array
     */
    public function ship(array $data): array
    {
        $this->init();
        // Start MongoDB session and transaction
        $manager = $this->di->get('db')->getManager();
        $this->sessionTransaction = $manager->startSession();
        $this->sessionTransaction->startTransaction();

        $this->userId = $data['user_id'] ?? $this->di->getUser()->id;
        $this->logFile = "shipment/{$this->userId}/" . date('d-m-Y') . '.log';
        $this->addLog($this->logFile, 'Starting Process for OrderId: ', $data['marketplace_reference_id'], 'optional');
        if (isset($data['object_type'])) {
            $this->objectType = $data['object_type'];
        } else {
            $orderData = $this->getOrderData($data);
            if (empty($orderData)) {
                $this->sessionTransaction->commitTransaction();
                return ['success' => false, 'message' => 'Order data not found'];
            }
            $this->objectType = $orderData['object_type'];
        }
        if (!empty($this->objectType)) {
            if (!$this->checkDeadLockCase($data)) {
                if ($this->objectType == self::SOURCE_ORDER) {
                    $sourceShipment = $this->prepareSourceShipment($data);
                    if (isset($sourceShipment['success']) && $sourceShipment['success']) {
                        $this->addLog($this->logFile, 'Source Shipment Prepared Successfully', "", 'optional');
                        if (!empty($sourceShipment['data'])) {
                            $response = $this->prepareTargetShipment($data, $sourceShipment['data']);
                            $this->sessionTransaction->commitTransaction();
                            return $response;
                        }
                    }
                    $this->sessionTransaction->commitTransaction();
                    return $sourceShipment;
                } else {
                    $targetShipment = $this->prepareTargetShipment($data);
                    if (isset($targetShipment['success']) && $targetShipment['success']) {
                        $this->addLog($this->logFile, 'Target Shipment Prepared Successfully', "", 'optional');
                        if (!empty($targetShipment['data'])) {
                            $response = $this->prepareSourceShipment($data, $targetShipment['data']);
                            $this->sessionTransaction->commitTransaction();
                            return $response;
                        }
                    }
                    $this->sessionTransaction->commitTransaction();
                    return $targetShipment;
                }
            } else {
                $message = 'Shipment tried from wrong source';
            }
        } else {
            $message = 'Unable to define object_type';
        }
        $this->sessionTransaction->commitTransaction();
        return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
    }

    /**
     * getOrderData function
     * To get order data based on order_id
     * @param array $data
     * @return array
     */
    private function getOrderData($data)
    {
        $filter = [
            'user_id' => $this->userId,
            'object_type' => ['$in' => [self::SOURCE_ORDER, self::TARGET_ORDER]],
            'marketplace' => $data['marketplace'],
            'marketplace_shop_id' => $data['shop_id'],
            'marketplace_reference_id' => (string) $data['marketplace_reference_id']
        ];
        $options = [
            'projection' => [
                'user_id' => 1,
                'object_type' => 1,
                'shop_id' => 1,
                'marketplace' => 1,
                'shipping_status' => 1,
                'targets' => 1,
            ],
            'typeMap' => $this->arrayParams,
            'session' => $this->sessionTransaction
        ];
        return $this->orderCollection->findOne($filter, $options);
    }

    /**
     * getOrderData function
     * To check weather shipment tried from right source or not
     * @param array $data
     * @return bool
     */
    private function checkDeadLockCase($data)
    {
        $shipmentFilter = [
            'user_id' => $this->userId,
            'object_type' => ['$in' => [self::SOURCE_SHIPMENT, self::TARGET_SHIPMENT]],
            'marketplace' => $data['marketplace'],
            'marketplace_shop_id' => $data['shop_id'],
            'marketplace_reference_id' => (string)$data['marketplace_reference_id'],
            'reference_id' => ['$exists' => true]
        ];
        $options = [
            'projection' => [
                'user_id' => 1,
                'object_type' => 1,
                'shop_id' => 1,
                'marketplace' => 1
            ],
            'typeMap' => $this->arrayParams,
            'session' => $this->sessionTransaction
        ];
        $shipmentData = $this->orderCollection->findOne($shipmentFilter, $options);
        if (!empty($shipmentData)) {
            $orderFilter = [
                'user_id' => $this->userId,
                'object_type' => $this->objectType,
                'marketplace' => $data['marketplace'],
                'marketplace_shop_id' => $data['shop_id'],
                'marketplace_reference_id' => (string)$data['marketplace_reference_id'],
            ];
            $options = [
                'session' => $this->sessionTransaction
            ];
            $this->orderCollection->updateOne(
                $orderFilter,
                [
                    '$set' => ['shipping_status' => 'Failed', 'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))],
                    '$addToSet' => ['shipment_error' => 'Shipment tried from wrong source']
                ],
                $options
            );
            return true;
        }
        return false;
    }

    /**
     * prepareTargetShipment function
     * To prepare target shipment data
     * @param array $data
     * @param array $sourceShipment
     * @return array
     */
    private function prepareTargetShipment($data, $sourceShipment = [])
    {
        $targetOrderId = '';
        if (!empty($sourceShipment)) {
            $targetOrderId = $this->getOrderIdFromTargets($data, $sourceShipment, self::TARGET);
        } else {
            $targetOrderId = $data['marketplace_reference_id'];
        }
        if (!empty($targetOrderId)) {
            $targetData = $this->getTargetOrderData($data, $targetOrderId);
            if (!empty($targetData)) {
                $toBeUpdated = false;
                $shipmentFilter = $this->prepareShipmentFilter($data, $targetData, self::TARGET_SHIPMENT);
                $options = [
                    'projection' => [
                        'user_id' => 1,
                        'object_type' => 1,
                        'shop_id' => 1,
                        'marketplace' => 1,
                        'shipment_id' => 1,
                        'source_created_at' => 1,
                        'source_updated_at' => 1,
                        'created_at' => 1,
                        'updated_at' => 1,
                        'tracking' => 1
                    ],
                    'typeMap' => $this->arrayParams,
                    'session' => $this->sessionTransaction
                ];
                if (!isset($targetData['shipping_status'])) {
                    $existingShipmentData = [];
                } else {
                    $existingShipmentData = $this->orderCollection->findOne($shipmentFilter, $options);
                }
                if ($this->objectType == self::TARGET_ORDER) {
                    if (!empty($existingShipmentData)) {
                        if ($this->checkSameShipmentAttempt($data, $targetData, $existingShipmentData)) {
                            return ['success' => false, 'message' => 'Same Fulfillment tried to re-attempt'];
                        }
                        $toBeUpdated = true;
                    }
                    $sourceOrderId = $targetData['targets'][0]['order_id'];
                    $shipmentItemData = $this->prepareShipmentItems($data, $targetData, self::TARGET_SHIPMENT, $toBeUpdated);
                    $prepareData = [
                        'object_type' => self::TARGET_SHIPMENT,
                        'shop_id' => $data['target_shop_id'],
                        'marketplace' => $data['target_marketplace'],
                        'marketplace_reference_id' => $targetData['marketplace_reference_id'],
                        'cif_order_id' => $targetData['cif_order_id'],
                        'targets_marketplace' => $data['source_marketplace'],
                        'targets_status' => self::STATUS,
                        'targets_shop_id' => $data['source_shop_id'],
                        'targets_order_id' => $sourceOrderId,
                        'items' => $shipmentItemData['items'],
                        'totalQty' => $shipmentItemData['totalQuantity'],
                        'shippedQty' => $shipmentItemData['quantityShipped'],
                        'data' => $data,
                    ];
                    $targetShipment = $this->prepareShipmentData($prepareData);
                    if ($toBeUpdated) {
                        if ($this->checkStaleData($targetShipment, $existingShipmentData)) {
                            return ['success' => false, 'message' => 'Attempting to update stale information'];
                        }
                        $targetShipment['shipment_id'] = $existingShipmentData['shipment_id'];
                        $targetShipmentCopy = $targetShipment;
                        $this->updateShipmentDoc($shipmentFilter, $targetShipmentCopy);
                    } else {
                        $insertedId = $this->insertShipmentDoc($targetShipment);
                        $this->insertAndUpdateShipmentItem($targetData, $shipmentItemData, $insertedId, self::TARGET);
                        $parentStatus = $shipmentItemData['totalQuantity']
                            == $shipmentItemData['savedShippedQuantity'] ? "Shipped" : "Partially Shipped";
                        $sourceParams = [
                            'user_id' => $this->userId,
                            'object_type' => self::SOURCE_ORDER,
                            'marketplace' => $data['source_marketplace'],
                            'marketplace_shop_id' => (string)$data['source_shop_id'],
                            'marketplace_reference_id' => (string)$sourceOrderId,
                            'targets.marketplace' => (string)$data['target_marketplace']
                        ];
                        $targetParams = [
                            'user_id' => $this->userId,
                            'object_type' => self::TARGET_ORDER,
                            'marketplace' => $data['target_marketplace'],
                            'marketplace_shop_id' => (string)$data['target_shop_id'],
                            'marketplace_reference_id' => (string) $data['marketplace_reference_id']
                        ];
                        $prepareDocData = [
                            'sourceParams' => $sourceParams,
                            'targetParams' => $targetParams,
                            'orderItems' => $shipmentItemData['orderItems'],
                            'status' => $targetData['status'],
                            'parentStatus' => $parentStatus
                        ];
                        $this->setAndUpdateShipmentStatus($prepareDocData);
                        $targetShipment['shipment_id'] = (string)$insertedId;
                    }
                    $targetShipment = $this->isComponentProduct($targetData, $targetShipment);
                    if (!empty($targetShipment)) {
                        return ['success' => true, 'data' => $targetShipment];
                    }
                } else {
                    if (!empty($existingShipmentData)) {
                        if ($this->checkSameShipmentAttempt($data, $targetData, $existingShipmentData)) {
                            return ['success' => false, 'message' => 'Same Fulfillment tried to re-attempt'];
                        }
                        $toBeUpdated = true;
                    }
                    $matchedItems = $this->prepareMatchedItems($targetData, $sourceShipment, self::TARGET_SHIPMENT);
                    if (isset($matchedItems['success']) && !$matchedItems['success']) {
                        return $matchedItems;
                    }
                    if (isset($targetData['shipment_error'])) {
                        $options = [
                            'session' => $this->sessionTransaction
                        ];
                        $this->orderCollection->updateOne(['_id' => $targetData['_id']], ['$set' => ["shipping_status" => self::STATUS, "updated_at_iso" => new UTCDateTime((int)(microtime(true) * 1000))]], $options);
                    }
                    $prepareData = [
                        'object_type' => self::TARGET_SHIPMENT,
                        'marketplace' => $data['target_marketplace'],
                        'shop_id' => $data['target_shop_id'],
                        'targets_marketplace' => $data['source_marketplace'],
                        'targets_shop_id' => $data['source_shop_id'],
                        'targets_status' => $sourceShipment['status'],
                        'targets_order_id' => $data['marketplace_reference_id'],
                        'marketplace_reference_id' => $targetData['marketplace_reference_id'],
                        'cif_order_id' => $targetData['cif_order_id'],
                        'data' => $data,
                        'items' => $matchedItems
                    ];
                    $targetShipment = $this->prepareShipmentData($prepareData);
                    if ($toBeUpdated) {
                        $targetShipmentCopy = $targetShipment;
                        $this->updateShipmentDoc($shipmentFilter, $targetShipmentCopy);
                    } else {
                        $itemIds = [];
                        $insertedId = $this->insertShipmentDoc($targetShipment);
                        $items = $matchedItems;
                        foreach ($items as $key => $targetShipmentItem) {
                            $items[$key]['shipment_id'] = (string) $insertedId;
                            $insertedItemId = (string) $this->insertShipmentDoc($items[$key]);
                            $matchedItems[$key]['id'] = $insertedItemId;
                            $itemIds[] = $insertedItemId;
                        }
                        $options = [
                            'session' => $this->sessionTransaction
                        ];
                        $this->orderCollection->updateOne(
                            ['_id' => $insertedId],
                            ['$set' => [
                                'items' => $matchedItems,
                                'shipment_id' => (string) $insertedId,
                                'target_shipment_id' => $itemIds
                            ]],
                            $options
                        );
                        $targetShipment['items'] = $matchedItems;
                        $this->addReferenceKey(['_id' => $insertedId], $sourceShipment['shipment_id']);
                    }
                    $this->addLog($this->logFile, 'Target Shipment Prepared Successfully', "", 'optional');
                    return ['success' => true, 'data' => $targetShipment];
                }
            } else {
                $message = 'Target data not found';
            }
        } else {
            $message = 'Unable to get target orderId';
        }
        return ['success' => false, 'message' => $message ?? 'Unable to prepare target shipment'];
    }

    /**
     * Prepare and validate shipment data with bundled items.
     * @param array $orderData
     * @param array $sourceTargetShipmentData
     * @return array
     */
    private function isComponentProduct($orderData, $sourceTargetShipmentData)
    {
        $orderBundledItems = $this->groupOrderBundledItems($orderData);

        if (empty($orderBundledItems)) {
            return $sourceTargetShipmentData;
        }

        [$shipmentBundledItems, $nonBundledItems] = $this->groupShipmentBundleItems($sourceTargetShipmentData, $orderBundledItems);

        $uncompleteBundles = $this->identifyIncompleteBundles($shipmentBundledItems, $orderBundledItems);

        if (empty($uncompleteBundles)) {
            return $sourceTargetShipmentData;
        }

        $pastShipmentBundle = $this->getPastShipmentBundles($sourceTargetShipmentData, array_keys($uncompleteBundles));

        $this->mergePastAndCurrentShipments($uncompleteBundles, $shipmentBundledItems, $orderBundledItems, $pastShipmentBundle);
        $errors = $this->validateShipmentBundles($shipmentBundledItems, $uncompleteBundles);
        if (!empty($errors)) {
            $this->recordBundleShipmentErrors($orderData, $errors);
        }

        $finalItems = $this->combineFinalItems($shipmentBundledItems, $nonBundledItems);

        $sourceTargetShipmentData['items'] = $finalItems;
        return empty($finalItems) ? [] : $sourceTargetShipmentData;
    }

    /**
     * Group bundled items from order data.
     * @param array $orderData
     * @return array
     */
    private function groupOrderBundledItems($orderData)
    {
        $orderBundledItems = [];
        foreach ($orderData['items'] as $item) {
            if (!empty($item['is_component'])) {
                $linkId = $item['item_link_id'];
                $orderBundledItems[$linkId]['items'][$item['marketplace_item_id']] = $item;
                $orderBundledItems[$linkId]['total_bundle_qty'] = ($orderBundledItems[$linkId]['total_bundle_qty'] ?? 0) + $item['qty'];
            }
        }
        return $orderBundledItems;
    }

    /**
     * Group shipment items into bundled and non-bundled categories.
     * @param array $sourceTargetShipmentData
     * @param array $orderBundledItems
     * @return array
     */
    private function groupShipmentBundleItems($sourceTargetShipmentData, $orderBundledItems)
    {
        $shipmentBundledItems = [];
        $nonBundledItems = [];

        foreach ($sourceTargetShipmentData['items'] as $shipmentItem) {
            $linkId = $shipmentItem['item_link_id'];
            if (isset($orderBundledItems[$linkId])) {
                $shipmentBundledItems[$linkId]['items'][$shipmentItem['marketplace_item_id']] = $shipmentItem;
                $shipmentBundledItems[$linkId]['total_bundle_qty'] = ($shipmentBundledItems[$linkId]['total_bundle_qty'] ?? 0) + $shipmentItem['qty'];
                $shipmentBundledItems[$linkId]['total_shipped_qty'] = ($shipmentBundledItems[$linkId]['total_shipped_qty'] ?? 0) + $shipmentItem['shipped_qty'];
                $shipmentBundledItems[$linkId]['tracking'][] = $sourceTargetShipmentData['tracking'];
            } else {
                $nonBundledItems[] = $shipmentItem;
            }
        }

        return [$shipmentBundledItems, $nonBundledItems];
    }

    /**
     * Identify incomplete bundles in the shipment.
     * @param array $shipmentBundledItems
     * @param array $orderBundledItems
     * @return array
     */
    private function identifyIncompleteBundles($shipmentBundledItems, $orderBundledItems)
    {
        $uncompleteBundles = [];

        foreach ($shipmentBundledItems as $itemLinkId => $shipmentItem) {
            if ($shipmentItem['total_shipped_qty'] !== $orderBundledItems[$itemLinkId]['total_bundle_qty']) {
                $uncompleteBundles[$itemLinkId] = $shipmentItem;
            }
        }

        return $uncompleteBundles;
    }

    /**
     * Retrieve past shipment data for specific bundled items.
     * @param array $sourceTargetShipmentData
     * @param array $itemLinkIds
     * @return array
     */
    private function getPastShipmentBundles($sourceTargetShipmentData, $itemLinkIds)
    {
        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'session' => $this->sessionTransaction
        ];
        $allPreviousShipmentData = $this->orderCollection->find(
            [
                'user_id' => $sourceTargetShipmentData['user_id'],
                'object_type' => $sourceTargetShipmentData['object_type'],
                'marketplace' => $sourceTargetShipmentData['marketplace'],
                'marketplace_shop_id' => $sourceTargetShipmentData['marketplace_shop_id'],
                'items.item_link_id' => ['$in' => $itemLinkIds],
                'marketplace_shipment_id' => ['$ne' => $sourceTargetShipmentData['marketplace_shipment_id']]
            ],
            $options
        )->toArray();

        $pastShipmentBundle = [];
        foreach ($allPreviousShipmentData as $previousShipmentData) {
            foreach ($previousShipmentData['items'] as $previousShipItem) {
                $linkId = $previousShipItem['item_link_id'];
                $pastShipmentBundle[$linkId]['items'][$previousShipItem['marketplace_item_id']] = $previousShipItem;
                $pastShipmentBundle[$linkId]['total_bundle_qty'] = ($pastShipmentBundle[$linkId]['total_bundle_qty'] ?? 0) + $previousShipItem['qty'];
                $pastShipmentBundle[$linkId]['total_shipped_qty'] = ($pastShipmentBundle[$linkId]['total_shipped_qty'] ?? 0) + $previousShipItem['shipped_qty'];
                $pastShipmentBundle[$linkId]['tracking'][] = $previousShipmentData['tracking'];
            }
        }

        return $pastShipmentBundle;
    }

    /**
     * Merge past and current shipment data for incomplete bundles.
     * @param array &$uncompleteBundles
     * @param array &$shipmentBundledItems
     * @param array $orderBundledItems
     * @param array $pastShipmentBundle
     * @return void
     */
    private function mergePastAndCurrentShipments(&$uncompleteBundles, &$shipmentBundledItems, $orderBundledItems, $pastShipmentBundle)
    {
        foreach ($uncompleteBundles as $itemLinkId => $uncompleteBundleData) {
            if (
                isset($pastShipmentBundle[$itemLinkId]['total_bundle_qty'])
                && $pastShipmentBundle[$itemLinkId]['total_shipped_qty'] + $shipmentBundledItems[$itemLinkId]['total_shipped_qty'] === $orderBundledItems[$itemLinkId]['total_bundle_qty']
            ) {
                foreach ($uncompleteBundleData['items'] as $uncompleteBundleItem) {
                    $shipmentBundledItems[$itemLinkId]['items'][$uncompleteBundleItem['marketplace_item_id']] =
                        $shipmentBundledItems[$itemLinkId]['items'][$uncompleteBundleItem['marketplace_item_id']] ?? $uncompleteBundleItem;
                    $shipmentBundledItems[$itemLinkId]['items'][$uncompleteBundleItem['marketplace_item_id']]['shipped_qty'] =
                        $orderBundledItems[$itemLinkId]['items'][$uncompleteBundleItem['marketplace_item_id']]['qty'];
                }
                $shipmentBundledItems[$itemLinkId]['tracking'] = array_merge(
                    $shipmentBundledItems[$itemLinkId]['tracking'],
                    $pastShipmentBundle[$itemLinkId]['tracking']
                );

                unset($uncompleteBundles[$itemLinkId]);
            }
        }
    }

    /**
     * Validate shipment bundles and identify errors.
     * @param array &$shipmentBundledItems
     * @param array &$uncompleteBundles
     * @return array
     */
    private function validateShipmentBundles(&$shipmentBundledItems, &$uncompleteBundles)
    {
        $errors = [];
        $multipleTrackingInfo = [];

        foreach ($shipmentBundledItems as $itemLinkId => $groupedBundleData) {
            $trackingInfo = [];
            foreach ($groupedBundleData['tracking'] as $trackingData) {
                $key = $trackingData['company'] . '_' . $trackingData['number'];
                $trackingInfo[$key] = $trackingData;
            }

            if (count($trackingInfo) > 1) {
                $multipleTrackingInfo = array_merge($multipleTrackingInfo, $groupedBundleData['items']);
                unset($shipmentBundledItems[$itemLinkId]);
            }
        }

        foreach ($multipleTrackingInfo as $item) {
            $errors[] = "Different Tracking Details found for bundled Product SKU: {$item['sku']}";
        }

        foreach ($uncompleteBundles as $itemLinkId => $bundleData) {
            foreach ($bundleData['items'] as $item) {
                $errors[] = "Incomplete bundle item found SKU: {$item['sku']}";
            }
            unset($shipmentBundledItems[$itemLinkId]);
        }

        return $errors;
    }

    /**
     * Record shipment errors in the system.
     * @param array $orderData
     * @param array $errors
     * @return void
     */
    private function recordBundleShipmentErrors($orderData, $errors)
    {
        $filter = [
            'user_id' => $orderData['user_id'],
            'object_type' => $orderData['object_type'] === self::SOURCE_ORDER ? self::TARGET_ORDER : self::SOURCE_ORDER,
            'targets.order_id' => $orderData['marketplace_reference_id']
        ];

        $this->setShipmentError($filter, $errors);
    }

    /**
     * Combine final items from bundled and non-bundled lists.
     * @param array $shipmentBundledItems
     * @param array $nonBundledItems
     * @return array
     */
    private function combineFinalItems($shipmentBundledItems, $nonBundledItems)
    {
        $finalItems = $nonBundledItems;
        foreach ($shipmentBundledItems as $bundleBatch) {
            $finalItems = array_merge($finalItems, $bundleBatch['items']);
        }
        return $finalItems;
    }

    /**
     * prepareSourceShipment function
     * To prepare source shipment data
     * @param array $data
     * @param array $targetShipment
     * @return array
     */
    private function prepareSourceShipment($data, $targetShipment = [])
    {
        $sourceOrderId = '';
        if (!empty($targetShipment)) {
            $sourceOrderId = $this->getOrderIdFromTargets($data, $targetShipment, self::SOURCE);
        } else {
            $sourceOrderId = $data['marketplace_reference_id'];
        }
        if (!empty($sourceOrderId)) {
            $sourceData = $this->getSourceOrderData($data, $sourceOrderId);
            if (!empty($sourceData)) {
                $toBeUpdated = false;
                $shipmentFilter = $this->prepareShipmentFilter($data, $sourceData, self::SOURCE_SHIPMENT);
                $options = [
                    'projection' => [
                        'user_id' => 1,
                        'object_type' => 1,
                        'shop_id' => 1,
                        'marketplace' => 1,
                        'shipment_id' => 1,
                        'source_created_at' => 1,
                        'source_updated_at' => 1,
                        'created_at' => 1,
                        'updated_at' => 1,
                        'tracking' => 1
                    ],
                    'typeMap' => $this->arrayParams,
                    'session' => $this->sessionTransaction
                ];
                if (!isset($sourceData['shipping_status'])) {
                    $existingShipmentData = [];
                } else {
                    $existingShipmentData = $this->orderCollection->findOne($shipmentFilter, $options);
                }
                if ($this->objectType == self::SOURCE_ORDER) {
                    if (!empty($existingShipmentData)) {
                        if ($this->checkSameShipmentAttempt($data, $sourceData, $existingShipmentData)) {
                            return ['success' => false, 'message' => 'Same Fulfillment tried to re-attempt'];
                        }
                        $toBeUpdated = true;
                    }
                    $targetOrderId = $sourceData['targets'][0]['order_id'];
                    $shipmentItemData = $this->prepareShipmentItems($data, $sourceData, self::SOURCE_SHIPMENT, $toBeUpdated);
                    $prepareData = [
                        'object_type' => self::SOURCE_SHIPMENT,
                        'shop_id' => $data['source_shop_id'],
                        'marketplace' => $data['source_marketplace'],
                        'marketplace_reference_id' => $sourceData['marketplace_reference_id'],
                        'cif_order_id' => $sourceData['cif_order_id'],
                        'targets_marketplace' => $data['target_marketplace'],
                        'targets_status' => self::STATUS,
                        'targets_shop_id' => $data['target_shop_id'],
                        'targets_order_id' => $targetOrderId,
                        'items' => $shipmentItemData['items'],
                        'totalQty' => $shipmentItemData['totalQuantity'],
                        'shippedQty' => $shipmentItemData['quantityShipped'],
                        'data' => $data,

                    ];
                    $sourceShipment = $this->prepareShipmentData($prepareData);
                    if ($toBeUpdated) {
                        if ($this->checkStaleData($sourceShipment, $existingShipmentData)) {
                            return ['success' => false, 'message' => 'Attempting to update stale information'];
                        }
                        $sourceShipment['shipment_id'] = $existingShipmentData['shipment_id'];
                        $sourceShipmentCopy = $sourceShipment;
                        $this->updateShipmentDoc($shipmentFilter, $sourceShipmentCopy);
                    } else {
                        $insertedId = $this->insertShipmentDoc($sourceShipment);
                        $this->insertAndUpdateShipmentItem($sourceData, $shipmentItemData, $insertedId, self::SOURCE);
                        $parentStatus = $shipmentItemData['totalQuantity']
                            == $shipmentItemData['savedShippedQuantity'] ? "Shipped" : "Partially Shipped";
                        $sourceParams = [
                            'user_id' => $this->userId,
                            'object_type' => self::SOURCE_ORDER,
                            'marketplace' => $data['source_marketplace'],
                            "marketplace_shop_id" => (string)$data['source_shop_id'],
                            'marketplace_reference_id' => (string) $data['marketplace_reference_id']
                        ];
                        $targetParams = [
                            'user_id' => $this->userId,
                            'object_type' => self::TARGET_ORDER,
                            'marketplace' => $data['target_marketplace'],
                            "marketplace_shop_id" => (string)$data['target_shop_id'],
                            'marketplace_reference_id' => (string)$targetOrderId,
                            "targets.marketplace" => (string)$data['source_marketplace']
                        ];
                        $prepareDocData = [
                            'sourceParams' => $targetParams,
                            'targetParams' => $sourceParams,
                            'orderItems' => $shipmentItemData['orderItems'],
                            'status' => $sourceData['status'],
                            'parentStatus' => $parentStatus
                        ];
                        $this->setAndUpdateShipmentStatus($prepareDocData);
                        $sourceShipment['shipment_id'] = (string) $insertedId;
                    }
                    $sourceShipment = $this->isComponentProduct($sourceData, $targetShipment);
                    if (!empty($sourceShipment)) {
                        return ['success' => true, 'data' => $sourceShipment];
                    }
                } else {
                    if (!empty($existingShipmentData)) {
                        if ($this->checkSameShipmentAttempt($data, $sourceData, $existingShipmentData)) {
                            return ['success' => false, 'message' => 'Same Fulfillment tried to re-attempt'];
                        }
                        $toBeUpdated = true;
                    }
                    $matchedItems = $this->prepareMatchedItems($sourceData, $targetShipment, self::SOURCE_SHIPMENT);
                    if (isset($matchedItems['success']) && !$matchedItems['success']) {
                        return $matchedItems;
                    }
                    if (isset($sourceData['shipment_error'])) {
                        $options = [
                            'session' => $this->sessionTransaction
                        ];
                        $this->orderCollection->updateOne(
                            ['_id' => $sourceData['_id']],
                            ['$set' => [
                                'shipping_status' => self::STATUS,
                                'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))
                            ]],
                            $options
                        );
                    }
                    $prepareData = [
                        'object_type' => self::SOURCE_SHIPMENT,
                        'shop_id' => $data['source_shop_id'],
                        'marketplace' => $data['source_marketplace'],
                        'marketplace_reference_id' => $sourceData['marketplace_reference_id'],
                        'targets_status' => $targetShipment['status'],
                        'targets_marketplace' => $data['target_marketplace'],
                        'targets_shop_id' => $data['target_shop_id'],
                        'targets_order_id' => $targetShipment['marketplace_reference_id'],
                        'cif_order_id' => $sourceData['cif_order_id'],
                        'data' => $data,
                        'items' => $matchedItems
                    ];
                    $sourceShipment = $this->prepareShipmentData($prepareData);
                    if ($toBeUpdated) {
                        $sourceShipmentCopy = $sourceShipment;
                        $this->updateShipmentDoc($shipmentFilter, $sourceShipmentCopy);
                    } else {
                        $itemIds = [];
                        $insertedId = $this->insertShipmentDoc($sourceShipment);
                        $items = $matchedItems;
                        foreach ($items as $key => $sourceShipmentItem) {
                            $items[$key]['shipment_id'] = (string) $insertedId;
                            $insertedItemId = (string) $this->insertShipmentDoc($items[$key]);
                            $matchedItems[$key]['id'] = $insertedItemId;
                            $itemIds[] = $insertedItemId;
                        }
                        $options = [
                            'session' => $this->sessionTransaction
                        ];
                        $this->orderCollection->updateOne(
                            ['_id' => $insertedId],
                            ['$set' => [
                                'items' => $matchedItems,
                                'shipment_id' => (string) $insertedId,
                                "source_shipment_id" => $itemIds
                            ]],
                            $options
                        );
                        $sourceShipment['items'] = $matchedItems;
                        $this->addReferenceKey(['_id' => $insertedId], $targetShipment['shipment_id']);
                    }
                    $this->addLog($this->logFile, 'Source Shipment Prepared Successfully', "", 'optional');
                    return ['success' => true, 'data' => $sourceShipment];
                }
            } else {
                $message = 'Target data not found';
            }
        } else {
            $message = 'Unable to get target orderId';
        }
        return ['success' => false, 'message' => $message ?? 'Unable to prepare target shipment'];
    }

    /**
     * prepareShipmentData function
     * Molding source or target shipment data
     * @param array $prepareData
     * @return array
     */
    private function prepareShipmentData($prepareData)
    {
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
            "status" => $status ?? self::STATUS,
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
                ?? $prepareData['data']['shipping_status'] ?? self::STATUS,
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
     * getSourceOrderData function
     * To get source order data
     * @param array $data
     * @param string $orderId
     * @return array
     */
    private function getSourceOrderData($data, $orderId)
    {
        $filter = [
            'user_id' => $this->userId,
            'object_type' => self::SOURCE_ORDER,
            'marketplace' => $data['source_marketplace'],
            'marketplace_shop_id' => $data['source_shop_id'],
            'marketplace_reference_id' => (string) $orderId
        ];
        $options = [
            'projection' => [
                'user_id' => 1,
                'object_type' => 1,
                'shop_id' => 1,
                'cif_order_id' => 1,
                'marketplace' => 1,
                'marketplace_shop_id' => 1,
                'marketplace_reference_id' => 1,
                'status' => 1,
                'shipping_status' => 1,
                'targets' => 1,
                'shipment_error' => 1,
                'items' => 1
            ],
            'typeMap' => $this->arrayParams,
            'session' => $this->sessionTransaction
        ];
        return $this->orderCollection->findOne($filter, $options);
    }

    /**
     * getTargetOrderData function
     * To get target order data
     * @param array $data
     * @param string $orderId
     * @return array
     */
    private function getTargetOrderData($data, $orderId)
    {
        $filter = [
            'user_id' => $this->userId,
            'object_type' => self::TARGET_ORDER,
            'marketplace' => $data['target_marketplace'],
            'marketplace_shop_id' => $data['target_shop_id'],
            'marketplace_reference_id' => (string) $orderId
        ];
        $options = [
            'projection' => [
                'user_id' => 1,
                'object_type' => 1,
                'shop_id' => 1,
                'cif_order_id' => 1,
                'marketplace' => 1,
                'marketplace_shop_id' => 1,
                'marketplace_reference_id' => 1,
                'status' => 1,
                'shipping_status' => 1,
                'targets' => 1,
                'shipment_error' => 1,
                'items' => 1
            ],
            'typeMap' => $this->arrayParams,
            'session' => $this->sessionTransaction
        ];
        return $this->orderCollection->findOne($filter, $options);
    }

    /**
     * prepareShipmentItems function
     * To prepare source or target shipment items
     * @param array $data
     * @param array $sourceOrTargetData
     * @param string $objectType
     * @param bool $toBeUpdated
     * @return array
     */
    private function prepareShipmentItems($data, $sourceOrTargetData, $objectType, $toBeUpdated)
    {
        $shipmentItems = $orderItems = [];
        $totalQuantity = $quantityShipped = $savedShippedQuantity = 0;
        foreach ($sourceOrTargetData['items'] as $orderItem) {
            $orderItems[(string) $orderItem['marketplace_item_id']] = $orderItem;
            $totalQuantity = $totalQuantity + $orderItem['qty'] ?? 0;
        }
        foreach ($data['items'] as $item) {
            if (isset($orderItems[$item['marketplace_item_id']])) {
                $marketplaceItemId = $item['marketplace_item_id'];
                $preparedItems = [
                    "type" => $item['type'],
                    "title" => $item['title'],
                    "sku" => $item['sku'],
                    "qty" => $orderItems[$marketplaceItemId]['qty'] ?? "",
                    "weight" => $item['weight'],
                    "shipped_qty" => $item['shipped_qty'],
                    "object_type" => $objectType . '_items',
                    "user_id" => $this->userId,
                    "cif_order_id" => $sourceOrTargetData['cif_order_id'],
                    "product_identifier" => $item['product_identifier'],
                    "marketplace_item_id" => $marketplaceItemId,
                    "item_link_id" => $orderItems[$marketplaceItemId]['item_link_id'],
                    'item_status' => $orderItems[$marketplaceItemId]['qty'] == $item['shipped_qty']
                        ? "Shipped" : "Partially Shipped",
                    'created_at' => date('c')
                ];

                //Handled Shipment Syncing for Bundled Products
                if (isset($orderItems[$marketplaceItemId]['is_bundled'])) {
                    $preparedItems['is_bundled'] = $orderItems[$marketplaceItemId]['is_bundled'];
                }

                //Handled Shipment Syncing for Component Products
                if (isset($orderItems[$marketplaceItemId]['is_component'])) {
                    $preparedItems['is_component'] = $orderItems[$marketplaceItemId]['is_component'];
                }
                $shipmentItems[] = $preparedItems;
                $quantityShipped = $quantityShipped + $item['shipped_qty'] ?? 0;
                if (!$toBeUpdated) {
                    $oldQuantity = isset($orderItems[$marketplaceItemId]['shipped_qty'])
                        ? (int) $orderItems[$marketplaceItemId]['shipped_qty'] : 0;
                    if ($oldQuantity != $preparedItems['qty']) {
                        $orderItems[$marketplaceItemId]['shipped_qty'] = $oldQuantity + (int) $preparedItems['shipped_qty'];
                    }
                }
            }
        }
        //Calculating total quantity shipped in all line_items
        foreach ($orderItems as  $value) {
            if (isset($value['shipped_qty'])) {
                $savedShippedQuantity = $savedShippedQuantity + $value['shipped_qty'];
            }
        }

        return [
            'orderItems' => $orderItems,
            'items' => $shipmentItems,
            'totalQuantity' => $totalQuantity,
            'quantityShipped' => $quantityShipped,
            'savedShippedQuantity' => $savedShippedQuantity,
        ];
    }

    /**
     * setAndUpdateShipmentStatus function
     * To set and update shipping_status and status key
     * @param array $data
     * @return null
     */
    private function setAndUpdateShipmentStatus($data)
    {
        if (!isset($data['status'])) {
            $filter[] =
                [
                    'updateOne' => [
                        $data['targetParams'],
                        ['$set' => [
                            'items' => array_values($data['orderItems']),
                            'status' => $data['parentStatus'],
                            'shipping_status' => $data['parentStatus'],
                            'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))
                        ]],
                    ]
                ];
            $filter[] =
                [
                    'updateOne' => [
                        $data['sourceParams'],
                        ['$set' => [
                            'targets.$.status' => $data['parentStatus'],
                            'shipping_status' => self::STATUS,
                            'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))
                        ]],
                    ]
                ];
        } else {
            $response = $this->di->getObjectManager()->get("App\\Connector\\Components\\Order\\OrderStatus")
                ->validateStatus($data['status'], $data['parentStatus']);
            if ($response) {
                $filter[] =
                    [
                        'updateOne' => [
                            $data['targetParams'],
                            ['$set' => [
                                'items' => array_values($data['orderItems']),
                                'status' => $data['parentStatus'],
                                'shipping_status' => $data['parentStatus'],
                                'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))
                            ]],
                        ]
                    ];
                $filter[] =
                    [
                        'updateOne' => [
                            $data['sourceParams'],
                            ['$set' => [
                                'targets.$.status' => $data['parentStatus'],
                                'shipping_status' => self::STATUS,
                                'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))

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
                                'shipping_status' => $data['parentStatus'],
                                'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))
                            ]],
                        ]
                    ];
                $filter[] =
                    [
                        'updateOne' => [
                            $data['sourceParams'],
                            ['$set' => ['shipping_status' => self::STATUS, 'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))]],
                        ]
                    ];
            }
        }
        if (!empty($filter)) {
            $options = [
                'session' => $this->sessionTransaction
            ];
            $this->orderCollection->bulkWrite($filter, $options);
        }
    }

    /**
     * prepareMatchedItems function
     * To match product based on item_line_id and prepare items
     * @param array $orderData
     * @param array $shipmentData
     * @param string $objectType
     * @return array
     */
    private function prepareMatchedItems($orderData, $shipmentData, $objectType)
    {
        $linkIds = $items = $errors = [];
        foreach ($orderData['items'] as $orderItem) {
            $linkIds[$orderItem['item_link_id']] = $orderItem;
        }
        $processedItemLinkId = [];
        foreach ($shipmentData['items'] as $shipmentItem) {
            $matchedItem = [];
            if (isset($linkIds[$shipmentItem['item_link_id']])) {
                if (!in_array($shipmentItem['item_link_id'], $processedItemLinkId)) {
                    $matchedItem = $linkIds[$shipmentItem['item_link_id']];
                    $processedItemLinkId[] = $shipmentItem['item_link_id'];
                } else {
                    continue;
                }
            }
            if (!empty($matchedItem)) {
                if (
                    isset($matchedItem['cancelled_qty'])
                    && $matchedItem['cancelled_qty'] == $matchedItem['qty']
                ) {
                    $errors[] = 'Item cancelled SKU : ' . $shipmentItem['sku'];
                    continue;
                }
                $itemData = [
                    "type" => $matchedItem['type'],
                    "title" => $matchedItem['title'],
                    "sku" => $matchedItem['sku'],
                    "qty" => $matchedItem['qty'],
                    "weight" => $shipmentItem['weight'],
                    "shipped_qty" => $shipmentItem['shipped_qty'],
                    "object_type" => $objectType . "_items",
                    "user_id" => $this->userId,
                    "cif_order_id" => $orderData['cif_order_id'],
                    "marketplace_item_id" => $matchedItem['marketplace_item_id'] ?? "",
                    "item_link_id" => $shipmentItem['item_link_id'],
                    'item_status' => $shipmentItem['shipped_qty'] == $matchedItem['qty']
                        ? "Shipped" : "Partially Shipped",
                    'created_at' => date('c')
                ];

                //Handled Shipment Syncing for Bundled Products
                if (!empty($shipmentItem['is_bundled']) && $matchedItem['qty'] < $itemData['shipped_qty']) {
                    $itemData['shipped_qty'] = $matchedItem['qty'];
                }
                //Handled Shipment Syncing for Bundled Products
                if (!empty($shipmentItem['is_component'])) {
                    $itemData['shipped_qty'] = $matchedItem['qty'];
                }
                $items[] = $itemData;
            } else {
                $errors[] = 'Product Not Found SKU: ' . $shipmentItem['sku'];
            }
        }
        if (!empty($errors)) {
            return $this->setShipmentError(['_id' => $orderData['_id']], $errors);
        }
        return $items;
    }

    /**
     * insertShipmentDoc function
     * To insert source or target shipment docs
     * @param array $shipmentData
     * @return null
     */
    private function insertShipmentDoc($shipmentData)
    {
        $options = [
            'session' => $this->sessionTransaction
        ];
        $saveShipment = $this->orderCollection->insertOne($shipmentData, $options);
        return $saveShipment->getInsertedId();
    }

    /**
     * updateShipmentDoc function
     * To update source or target shipment docs
     * @param array $shipmentData
     * @return null
     */
    private function updateShipmentDoc($filter, $shipmentData)
    {
        unset($shipmentData['items']);
        unset($shipmentData['created_at']);
        $options = [
            'session' => $this->sessionTransaction
        ];
        $this->orderCollection->updateOne($filter, ['$set' => $shipmentData], $options);
    }

    /**
     * insertAndUpdateShipmentItem function
     * To update source or target shipment item docs
     * @param array $orderData
     * @param array $shipmentItemData
     * @param object $insertedId
     * @param string $type
     * @return null
     */
    private function insertAndUpdateShipmentItem($orderData, $shipmentItemData, $insertedId, $type)
    {
        $itemIds = $filter = [];
        $items = $shipmentItemData['items'];
        foreach ($items as $key => $value) {
            $shippedQty = $shipmentItemData['orderItems'][$value['marketplace_item_id']]['shipped_qty'];
            $itemStatus = $shippedQty == $value['qty'] ? 'Shipped' : 'Partially Shipped';
            $items[$key]['shipment_id'] = (string) $insertedId;
            $itemInsertedId = (string)$this->insertShipmentDoc($items[$key]);
            $itemIds[] = $itemInsertedId;
            $shipmentItemData['items'][$key]['id'] = $itemInsertedId;
            $filter[] =
                [
                    'updateOne' => [
                        [
                            'user_id' => $this->userId,
                            'object_type' => $type . '_order_items',
                            'cif_order_id' => $orderData['cif_order_id'],
                            'marketplace_item_id' => (string) $value['marketplace_item_id']
                        ],
                        ['$set' => ['item_status' => $itemStatus, 'shipped_qty' => $shippedQty]]
                    ],
                ];
        }
        $filter[] =
            [
                'updateOne' => [
                    ['_id' => $insertedId],
                    ['$set' => [
                        'items' => $shipmentItemData['items'],
                        'shipment_id' => (string) $insertedId,
                        $type . '_shipment_id' => $itemIds
                    ]]
                ],
            ];
        if (!empty($filter)) {
            $options = [
                'session' => $this->sessionTransaction
            ];
            $this->orderCollection->bulkWrite($filter, $options);
        }
    }

    /**
     * prepareShipmentFilter function
     * Preparing shipment data filter
     * @param array $orderData
     * @param array $shipmentItemData
     * @param string $objectType
     * @return array
     */
    private function prepareShipmentFilter($data, $orderData, $objectType)
    {
        return [
            'user_id' => $this->userId,
            'object_type' => $objectType,
            'marketplace' => $orderData['marketplace'],
            'marketplace_shop_id' => $orderData['marketplace_shop_id'],
            'marketplace_shipment_id' => (string) $data['marketplace_shipment_id']
        ];
    }

    /**
     * addReferenceKey function
     * Creating relation b/w source and target shipment
     * @param array $updateParams
     * @param string $referenceId
     * @return null
     */
    private function addReferenceKey($updateParams, $referenceId)
    {
        $options = [
            'session' => $this->sessionTransaction
        ];
        $this->orderCollection->updateOne(
            $updateParams,
            ['$set' => [
                "reference_id" => $referenceId,
            ]],
            $options
        );
    }

    /**
     * addLog function
     * Adding log based on priority level
     * @param string $file
     * @param string $key
     * @param string $data
     * @param string $priorityLevel
     * @return null
     */
    private function addLog($file, $key, $data = "", $priorityLevel = null)
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

    /**
     * getOrderIdFromTargets function
     * To fetch orderId from targets
     * @param array $data
     * @param array $shipmentData
     * @param string $key
     * @return array
     */
    private function getOrderIdFromTargets($data, $shipmentData, $key)
    {
        $marketplace = $key . "_marketplace";
        $shopId = $key . "_shop_id";
        foreach ($shipmentData['targets'] as $target) {
            if (($target['marketplace'] == $data[$marketplace])
                && $target['shop_id'] == $data[$shopId]
            ) {
                return $target['order_id'];
            }
        }
    }

    /**
     * checkSameShipmentAttempt function
     * Checking same shipment attempt
     * @param array $data
     * @param array $orderData
     * @param array $shipmentData
     * @return bool
     */
    private function checkSameShipmentAttempt($data, $orderData, $shipmentData)
    {
        $tracking = $shipmentData['tracking'];
        if (
            $tracking['company'] == $data['tracking']['company']
            && $tracking['number'] == $data['tracking']['number']
            && ($orderData['status'] == "Shipped" || $orderData['status'] == "Partially Shipped")
        ) {
            return true;
        }
        return false;
    }

    /**
     * checkStaleData function
     * Checking stale shipment data
     * @param array $newData
     * @param array $oldData
     * @return bool
     */
    private function checkStaleData($newData, $oldData)
    {
        if (
            strtotime($newData['source_updated_at'])
            > strtotime($oldData['source_updated_at'])
        ) {
            return false;
        }
        return true;
    }

    /**
     * setShipmentError function
     * Setting shipment_error in source or target order
     * @param array $params
     * @param array $errors
     * @return array
     */
    private function setShipmentError($params, $errors)
    {
        foreach ($errors as $error) {
            $filter[] =
                [
                    'updateOne' => [
                        $params,
                        ['$set' => ['shipping_status' => "Failed", 'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))], '$addToSet' => [
                            'shipment_error' => $error,
                        ]],
                    ],
                ];
        }
        $this->addLog($this->logFile, 'Shipment Sync Failed Errors: ', $errors, 'critical');
        if (!empty($filter)) {
            $options = [
                'session' => $this->sessionTransaction
            ];
            $this->orderCollection->bulkWrite($filter, $options);
        }
        return ['success' => false, 'message' => $errors];
    }

    /**
     * setSourceStatus function
     * For Syncing Source or Target Status
     * @param string $orderId
     * @param string $marketplace
     * @param boolean $success
     * @param string $reason
     * @param string $userId
     * @param boolean $sendMail
     * @return array
     */
    public function setSourceStatus($orderId, $marketplace, $success = false, $reason = 'Unknown', $userId = '', $sendMail = false)
    {
        $this->init();
        if ((!empty($orderId)) && (!empty($marketplace))) {
            if (empty($userId)) {
                $userId = $this->di->getUser()->id;
            }
            $orderParams = [
                'user_id' => $userId,
                'object_type' => ['$in' => [self::SOURCE_ORDER, self::TARGET_ORDER]],
                'marketplace_reference_id' => $orderId
            ];
            $options = [
                'projection' => [
                    'user_id' => 1,
                    'object_type' => 1,
                    'shop_id' => 1,
                    'cif_order_id' => 1,
                    'marketplace' => 1,
                    'marketplace_shop_id' => 1,
                    'marketplace_reference_id' => 1,
                    'status' => 1,
                    'shipping_status' => 1,
                    'targets' => 1,
                    'shipment_error' => 1,
                    'items' => 1,
                    'shipment_mail' => 1
                ],
                'typeMap' => $this->arrayParams
            ];
            $orderData = $this->orderCollection->findOne($orderParams, $options);
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
                    if ($objectType == self::SOURCE_ORDER) {
                        $fetchParams = [
                            'user_id' => $userId,
                            'object_type' => self::TARGET_ORDER,
                            'marketplace' => $marketplace,
                            'targets.order_id' => $orderId
                        ];
                    } else {
                        $fetchParams = [
                            'user_id' => $userId,
                            'object_type' => self::SOURCE_ORDER,
                            'marketplace' => $marketplace,
                            'targets.order_id' => $orderId
                        ];
                    }
                    $getData = $this->orderCollection->findOne($fetchParams, $options);
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
                    $response = $this->di->getObjectManager()->get("App\\Connector\\Components\\Order\\OrderStatus")->validateStatus($orderData['status'], $status);
                    if ($response) {
                        $filter[] =
                            [
                                'updateOne' => [
                                    $updateParams,
                                    ['$set' => ["status" => $status, 'shipping_status' => $status, 'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))]],
                                ],
                            ];
                    } else {
                        $filter[] = [
                            'updateOne' => [
                                $updateParams,
                                ['$set' => ['shipping_status' => $status, 'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))]],
                            ],
                        ];
                    }
                    foreach ($getData['items'] as $getItem) {
                        $linkIds[$getItem['item_link_id']] = $getItem;
                    }
                    foreach ($orderData['items'] as $orderItem) {
                        if (isset($linkIds[$orderItem['item_link_id']])) {
                            $getItem = $linkIds[$orderItem['item_link_id']];
                            if (isset($getItem['shipped_qty']) && !empty($getItem['shipped_qty'])) {
                                if(isset($getItem['is_component']) && $getItem['is_component']) {
                                    $shippedQty = $orderItem['qty'];
                                    $itemStatus = 'Shipped';
                                } else {
                                    $shippedQty = $getItem['shipped_qty'];
                                    $itemStatus = $getItem['shipped_qty'] == $getItem['qty'] ? 'Shipped' : 'Partially Shipped';
                                }
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
                                        ['$set' => ["targets.$.status" => $status, 'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))]],
                                    ],
                                ];

                            if (!empty($filter)) {
                                $this->orderCollection->bulkWrite($filter);
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
                        $this->insertDataInMailContainer($userId, $orderData, $reason);
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
                    $setValue['updated_at_iso'] = new UTCDateTime((int)(microtime(true) * 1000));
                    $this->orderCollection->updateOne(
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
     * insertDataInMailContainer function
     * To insert data in failed_shipment mail container
     * @param string $userId
     * @param array $orderData
     * @param string $reason
     * @return null
     */
    private function insertDataInMailContainer($userId, $orderData, $reason)
    {
        $prepareData = [
            'source_order_id' => $orderData['marketplace_reference_id'],
            'source_marketplace' => $orderData['marketplace'],
            'target_order_id' => $orderData['targets'][0]['order_id'],
            'target_marketplace' => $orderData['targets'][0]['marketplace'],
            'user_id' => $userId,
            'reason' => $reason,
            'created_at' => date('c')
        ];
        $shipmentMailCollection = $this->mongo->getCollectionForTable('failed_shipment');
        $shipmentMailCollection->insertOne($prepareData);
    }

    /**
     * isShippable function
     * Abstract Function
     *
     * @return bool
     */
    public function isShippable()
    {
        return true;
    }

    /**
     * mold function
     * Abstract Function
     *
     * @return array
     */
    public function mold(array $data): array
    {
        return [];
    }
}
