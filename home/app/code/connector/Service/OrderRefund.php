<?php

/* Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Connector\Service;

use App\Connector\Contracts\Sales\Order\RefundInterface;
use App\Connector\Service\AbstractRefund;

/**
 * OrderRefund
 * @services
 */
class OrderRefund extends AbstractRefund implements RefundInterface
{
    public const ORDER_CONTAINER = 'order_container';

    public const SCHEMA_VERSION = "2.0";

    public $orderId;

    public $refundData;

    public $objectType;

    public $currencyService = null;

    public function refund(array $order): array
    {
        return [];
    }

    public function create(array $refundOrder): array
    {
        $isPrepared = $refundOrder['isPrepared'] ?? false;
        unset($refundOrder['isPrepared']);

        $this->refundData = $refundOrder;

        $generatedOrderId = $this->createBaseDataAndGetInsertedId($refundOrder);
        if (!$generatedOrderId['success']) {
            return $generatedOrderId;
        }

        $this->orderId = $generatedOrderId['data'];

        $preparedData = $refundOrder;
        if (!$isPrepared) {
            $preparedDataResponse = $this->preparePriceForDb($refundOrder);
            if (!$preparedDataResponse['success']) {
                return $preparedDataResponse;
            }

            $preparedData = $preparedDataResponse['data'];
        }

        $createItemsResponse = $this->createItemsAndSetItemIds($preparedData, $isPrepared);
        if (!$createItemsResponse['success']) {
            return ['success' => false, 'message' => "Error in saving items."];
        }

        //need to work on taxes & discounts
        $updateResponse = $this->updateBaseData();
        if (!$updateResponse['success']) {
            return $updateResponse;
        }

        if (isset($this->refundData['marketplace_transaction_id'])) {
            $this->refundData['reference_id'] = (string)$updateResponse['data']['_id'];
            unset($this->refundData['marketplace_transaction_id']);
        }

        return ['success' => true, 'data' => $this->refundData];
    }

    public function createBaseDataAndGetInsertedId(array $data): array
    {
        //todo: consolidate this
        if (!isset($data['marketplace_shop_id'], $data['marketplace'])) {
            return [
                'success' => false,
                'message' => 'marketplace_shop_id or marketplace is missing!!'
            ];
        }

        if (!isset($data['marketplace_transaction_id']) && !isset($data['reference_id'])) {
            return [
                'success' => false,
                'message' => 'marketplace_transaction_id or reference_id is missing!!'
            ];
        }

        //creating data to check if refund is already in process
        //marketplace_transaction_id will prevent obtaining previously executed refund
        $insertionData = [
            "user_id" => $data['user_id'],
            "object_type" => $data['object_type'],
            "marketplace" => $data['marketplace'],
            "marketplace_shop_id" => $data['marketplace_shop_id'],
            "marketplace_reference_id" => $data['marketplace_reference_id'],
            "process_inprogess" => 1,
        ];
        if (isset($data['marketplace_transaction_id'])) {
            $insertionData['marketplace_transaction_id'] = $data['marketplace_transaction_id'];
        }

        if (isset($data['reference_id'])) {
            $insertionData['reference_id'] = $data['reference_id'];
        }

        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $orderData = $mongo->findOne($insertionData, $options);

        if (isset($orderData['process_inprogess'])) {
            return ['success' => false, 'message' => 'Refund is already in progress for this order.'];
        }

        //todo: move process_inprogess to top level

        //if the refund failed on source, unsetting 'process_inprogess' key
        if ($data['status'] == 'Failed') {
            unset($insertionData['process_inprogess']);
        }

        //todo: assign both in same line
        $insertionData['created_at'] = $this->getMongoDate();
        $insertionData['updated_at'] = $this->getMongoDate();
        $insert = $mongo->insertOne($insertionData);
        //todo: check if insert was successful
        return ["success" => true, "data" => (string)$insert->getInsertedId()];
    }

    public function preparePriceForDb(array $refundOrder): array
    {
        if (!isset($refundOrder['currency'])) {
            return [
                'success' => false,
                'message' => 'Required param currency (marketplace_currency) missing.',
            ];
        }

        $this->refundData['marketplace_currency'] = $refundOrder['currency'];
        $this->refundData['currency'] = 'USD';
        $refundOrder['marketplace_currency'] = $refundOrder['currency'];

        //preparing prices and amounts inside the items
        foreach ($refundOrder['items'] as $itemKey => $item) {
            if (isset($item['currency'])) {
                $this->refundData['items'][$itemKey]['marketplace_currency'] = $item['currency'];
                $this->refundData['items'][$itemKey]['currency'] = 'USD';
            }

            foreach ($item as $itemAttributeName => $itemAttributeValue) {
                if (is_array($itemAttributeValue)) {
                    if (isset($itemAttributeValue[0])) {
                        foreach ($itemAttributeValue as $itemValueKey => $itemValueData) {
                            if (isset($itemValueData['price'])) {
                                $this->refundData['items'][$itemKey][$itemAttributeName][$itemValueKey]['marketplace_price'] =  $itemValueData['price'];
                                $this->refundData['items'][$itemKey][$itemAttributeName][$itemValueKey]['price'] = $this->getConnectorPrice($refundOrder['marketplace_currency'], $itemValueData['price']);
                            }
                        }
                    } elseif (isset($itemAttributeValue['price'])) {
                        $this->refundData['items'][$itemKey][$itemAttributeName]['marketplace_price'] = $itemAttributeValue['price'];
                        $this->refundData['items'][$itemKey][$itemAttributeName]['price'] = $this->getConnectorPrice($refundOrder['marketplace_currency'], $itemAttributeValue['price']);
                    }
                } elseif ($itemAttributeName == 'price') {
                    $this->refundData['items'][$itemKey][$itemAttributeName] = $this->getConnectorPrice($refundOrder['marketplace_currency'], $itemAttributeValue);
                    $this->refundData['items'][$itemKey]['marketplace_price'] = (string)$itemAttributeValue;
                } elseif (strpos($itemAttributeName, 'amount') !== false) {
                    $this->refundData['items'][$itemKey][$itemAttributeName] =  [
                        'marketplace_price' => (string)$itemAttributeValue,
                        'price' => $this->getConnectorPrice($refundOrder['marketplace_currency'], $itemAttributeValue)
                    ];
                }
            }
        }

        //preparing price and amount data outside of the items
        foreach ($refundOrder as $refundOrderAttributeKey => $refundOrderAttributeValue) {
            if (is_array($refundOrderAttributeValue)) {
                if (isset($refundOrderAttributeValue[0])) {
                    foreach ($refundOrderAttributeValue as $attributeKey => $attributeValue) {
                        if (isset($attributeValue['price'])) {
                            $this->refundData[$refundOrderAttributeKey][$attributeKey]['marketplace_price'] = $attributeValue['price'];
                            $this->refundData[$refundOrderAttributeKey][$attributeKey]['price'] = $this->getConnectorPrice($refundOrder['marketplace_currency'], $attributeValue['price']);
                        }
                    }
                } elseif (isset($refundOrderAttributeValue['price'])) {
                    $this->refundData[$refundOrderAttributeKey]['marketplace_price'] = $refundOrderAttributeValue['price'];
                    $this->refundData[$refundOrderAttributeKey]['price'] = $this->getConnectorPrice($refundOrder['marketplace_currency'], $refundOrderAttributeValue['price']);
                }
            } elseif ($refundOrderAttributeValue === 'price') {
                $this->refundData[$refundOrderAttributeValue] = [
                    'marketplace_price' => $refundOrderAttributeValue['price'],
                    'price' => $this->getConnectorPrice($refundOrder['marketplace_currency'], $refundOrderAttributeValue['price'])
                ];
            }
        }

        return [
            'success' => true,
            'data' => $this->refundData
        ];
    }

    public function createItemsAndSetItemIds(array $data, $isPrepared = false): array
    {
        foreach ($data['items'] as $itemKey => $item) {
            $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
            $itemData = $item;
            $itemData['object_type'] = $data['object_type'] . '_order_items';
            $itemData['schema_version'] = self::SCHEMA_VERSION;
            $itemData['user_id'] = $data['user_id'];
            $itemData['cif_order_id'] = $this->refundData['cif_order_id'];
            $itemData['order_id'] = $this->orderId; //assigning _id of parent_order to child
            $itemData['created_at'] = $itemData['updated_at'] = $this->getMongoDate();
            $insertResponse = $mongo->insertOne($itemData);
            //todo: check here if data inserted or not

            //setting _id of inserted order_items as id inside parent_order's items
            $this->refundData['items'][$itemKey]['id'] = (string)$insertResponse->getInsertedId();
        }


        return [
            'success' => true,
            'data' => "Items created successfully.",
        ];
    }

    public function updateBaseData()
    {
        $this->refundData['updated_at'] = $this->getMongoDate();
        $id = new \MongoDB\BSON\ObjectId($this->orderId);
        $filter = [
            '_id' => $id,
            'object_type' => $this->refundData['object_type']
        ];
        $data = $this->refundData;
        $updateResponse = $this->updateDataInDb($filter, $data);
        return $updateResponse;
    }

    public function updateTargetsAndItems(array $target, array $refundData): array
    {
        $isPartial = false;
        $refundedItemCount = 0;
        $status = 'Partially Refund';
        $marketplace_status = $refundData['marketplace_status'];

        foreach ($target['items'] as $item_index => $target_item) {
            if (isset($target_item['refund_qty'])) {
                if ($target_item['refund_qty'] == $target_item['qty']) {
                    $refundedItemCount += 1;
                    continue;
                }

                $target_item['qty'] = $target_item['qty'] - $target_item['refund_qty'];
            }

            foreach ($refundData['items'] as $item) {
                if (
                    (isset($target_item['marketplace_item_id'], $item['marketplace_item_id']) && $target_item['marketplace_item_id'] == $item['marketplace_item_id']) ||
                    (!isset($target_item['marketplace_item_id'], $item['marketplace_item_id']) && $target_item['sku'] == $item['sku'])
                ) {
                    $refundedItemCount += 1;

                    // //here compare target_item refund qty with refund_item qty and if they are equal
                    // //then it means it shouldn't be updated
                    // if (isset($refundData['object_type']) && strpos($refundData['object_type'], 'cif') !== false) {
                    //     if (isset($target_item['refund_qty'])) {
                    //         //
                    //     }
                    // }

                    //updating refund_qty in target_order_items
                    $filter = [
                        '_id' => new \MongoDB\BSON\ObjectId($target_item['id'])
                    ];
                    $inc = ['refund_qty' => $item['qty']];
                    $update = ['updated_at' => $this->getMongoDate()];
                    $updateAndIncResponse = $this->incrementAndUpdateDataInDb($filter, $inc, $update);
                    if (!$updateAndIncResponse['success']) {
                        $this->errors = $updateAndIncResponse['message'];

                        //preparing query for updating target_order
                        //if some error occurred in $inc, setting refund_qty from refundData
                        $query["items." . $item_index . ".refund_qty"] = $item['qty'];
                    } else {
                        //if the $inc was successfully updated, fetch the refund_qty from the database
                        $query["items." . $item_index . ".refund_qty"] = $updateAndIncResponse['data']['refund_qty'];
                    }

                    //checking if the quantity is not equal in the refund data
                    if ($target_item['qty'] != $item['qty'] && $isPartial != true) {
                        $isPartial = true;
                    }
                }
            }
        }

        if ($refundedItemCount != count($target['items'])) {
            $isPartial = true;
        }

        if ($isPartial != true) {
            $status = 'Refund';
        }

        //As this is target_order, it means this request is already processed
        //hence refund_status is same as status(cif status of target_refund)
        $refund_status = $status;
        if (empty($refundData['items']) && stripos($marketplace_status, 'Partial') === false) {
            $refund_status = 'Refund';
            $status = 'Refund';
        }

        $query['status'] = $status;
        $query['refund_status'] =  $refund_status;
        $query['marketplace_status'] = $marketplace_status;
        $query['updated_at'] = $this->getMongoDate();
        if (isset($refundData['source_updated_at'])) {
            $query['source_updated_at'] = $refundData['source_updated_at'];
        }

        $response = $this->updateDataInDb(['_id' => $target['_id']], $query);
        if (empty($response)) {
            return ['success' => false, 'message' => 'Unable to update target_order.']; //need to update object_type
        }

        return ['success' => true, 'data' => $response['data']];
    }

    public function updateSourceAndItems(array $source, array $refundData): array
    {
        $isPartial = false;
        $refundedItemCount = 0;
        $refund_status = 'Inprogress';
        $status = 'Partially Refund';
        $marketplace_status = $refundData['marketplace_status'];

        foreach ($source['items'] as $item_index => $source_item) {
            if (isset($source_item['refund_qty'])) {
                if ($source_item['refund_qty'] == $source_item['qty']) {
                    $refundedItemCount += 1;
                    continue;
                }

                $source_item['qty'] = $source_item['qty'] - $source_item['refund_qty'];
            }

            foreach ($refundData['items'] as $item) {
                if (
                    (isset($source_item['marketplace_item_id'], $item['marketplace_item_id']) && $source_item['marketplace_item_id'] == $item['marketplace_item_id']) ||
                    (!isset($source_item['marketplace_item_id'], $item['marketplace_item_id']) && $source_item['sku'] == $item['sku'])
                ) {
                    $refundedItemCount += 1;

                    //code written below for condition when process is not feed based
                    if (isset($refundData['isFeedBased']) && $refundData['isFeedBased'] != true) {
                        $filter = ['_id' => new \MongoDB\BSON\ObjectId($source_item['id'])];
                        $inc = ['refund_qty' => $item['qty']];
                        $update = ['updated_at' => $this->getMongoDate()];
                        $updateAndIncResponse = $this->incrementAndUpdateDataInDb($filter, $inc, $update);
                        if (!$updateAndIncResponse['success']) {
                            $this->errors = $updateAndIncResponse['message'];

                            //preparing query for updating source_order
                            //if some error occurred in $inc, setting refund_qty from refundData
                            $query["items." . $item_index . ".refund_qty"] = $item['qty'];
                        } else {
                            //if the $inc was successfully updated, fetch the refund_qty from the database
                            $query["items." . $item_index . ".refund_qty"] = $updateAndIncResponse['data']['refund_qty'];
                        }
                    }

                    if ($source_item['qty'] != $item['qty'] && $isPartial != true) {
                        $isPartial = true;
                    }
                }
            }
        }

        if ($refundedItemCount != count($source['items'])) {
            $isPartial = true;
        }

        if ($isPartial != true) {
            $status = 'Refund';
        }

        $query['refund_status'] =  $refund_status;

        //code written below for condition when process is not feed based
        if (isset($refundData['isFeedBased']) && $refundData['isFeedBased'] != true) {
            $query['status'] = $status;
            $query['marketplace_status'] = $marketplace_status;
            //mapped to marketplace_status in case of not feed based process
            $query['refund_status'] = $marketplace_status;
        }

        if (strpos($marketplace_status, 'Failed') !== false) {
            $query['refund_failed_reason'] = $refundData['error'];
            $query['refund_status'] =  $marketplace_status;
            unset($query['status']);
        }

        $query['updated_at'] = $this->getMongoDate();
        if (isset($refundData['source_updated_at'])) {
            $query['source_updated_at'] = $refundData['source_updated_at'];
        }

        if ($query['refund_status'] == 'Inprogress' || strpos($query['refund_status'], 'Partial') !== false) {

            //not required to fetch source order from database it is available in the parameter

            $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $sourceOrder = $mongo->findOne(['_id' => $source['_id']], $options);

            if (isset($sourceOrder['refund_status']) && strpos($sourceOrder['refund_status'], 'Failed') !== false) {
                if (strpos($sourceOrder['refund_status'], 'Inprogress') !== false) {
                    $updatedSourceOrder = $this->validateInProcessSourceRefund($sourceOrder, $refundData);
                }

                $statusResponse = $this->getUpdatedStatusForFailedStatusInSourceOrder($refundData, $sourceOrder, $query['refund_status']);
                if (!$statusResponse['success']) {
                    $query['refund_status'] .= ' with Failed';
                } else {
                    $query['refund_status'] = $statusResponse['data'];
                }
            }
        }

        $response = $this->updateDataInDb(['_id' => $source['_id']], $query);
        if (empty($response)) {
            return ['success' => false, 'message' => $this->di->getLocale()->_("unable_to_update_object_type", [
                'objectType' => $refundData['object_type']
            ])];
        }

        if (isset($refundData['isFeedBased']) && $refundData['isFeedBased'] != true) {
            $response['data']['isFeedBased'] = false;
        }

        return ['success' => true, 'data' => $response['data']];
    }

    public function getUpdatedStatusForFailedStatusInSourceOrder(array $refundData, array $sourceOrder, string $refundStatus): array
    {
        if (strpos($refundData['object_type'], 'cif') === false) {
            return [
                'success' => true,
                'data' => $refundStatus . ' with Failed'
            ];
        }

        //todo: update query
        $targetOrderFilter = [
            'user_id' => $sourceOrder['user_id'],
            'object_type' => 'target_order',
            'cif_order_id' => $sourceOrder['cif_order_id'],
        ];
        if (strpos($sourceOrder['object_type'], 'target') !== false) {
            $targetOrderFilter['object_type'] = 'source_order';
        }

        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $allTargetOrders = $mongo->find($targetOrderFilter, $options)->toArray();
        if (empty($allTargetOrders)) {
            return ['success' => false, 'message' => 'target_order not found'];
        }

        $unsynchedRefundsOnSource = $sourceOrder;
        foreach ($unsynchedRefundsOnSource['items'] as $sourceIndex => $sourceItem) {
            if (isset($sourceItem['refund_qty']) && $sourceItem['refund_qty'] == $sourceItem['qty']) {
                unset($unsynchedRefundsOnSource['items'][$sourceIndex]);
                continue;
            }

            foreach ($allTargetOrders as $targetOrder) {
                foreach ($targetOrder['items'] as $targetItem) {
                    if ($sourceItem['sku'] == $targetItem['sku']) {
                        if (!isset($targetItem['refund_qty'])) {
                            unset($unsynchedRefundsOnSource['items'][$sourceIndex]);
                            continue;
                        }

                        if (!isset($sourceItem['refund_qty'])) {
                            $unsynchedRefundsOnSource['items'][$sourceIndex]['refund_qty'] = $targetItem['refund_qty'];
                            continue;
                        }

                        if ($sourceItem['refund_qty'] == $targetItem['refund_qty']) {
                            unset($unsynchedRefundsOnSource['items'][$sourceIndex]);
                            continue;
                        }

                        $unsynchedRefundsOnSource['items'][$sourceIndex]['refund_qty'] = $targetItem['refund_qty'] - $sourceItem['refund_qty'];
                    }
                }
            }
        }

        $isPartial = false;
        foreach ($unsynchedRefundsOnSource['items'] as $unsynchedItem) {
            $itemExists = false;
            $qtyEqualOrMore = false;
            foreach ($refundData['items'] as $refundedItem) {
                if ($unsynchedItem['sku'] == $refundedItem['sku']) {
                    $itemExists = true;
                    /*
                    The condition checks if the unsynced refunded quantity is less than or equal to the manually refunded quantity.
                    If the manually refunded quantity is more than the unsynced refunded quantity, it means all unsynced quantities
                    are accounted for and refunded.
                    */
                    if (isset($unsynchedItem['refund_qty']) && $unsynchedItem['refund_qty'] <= $refundedItem['qty']) {
                        $qtyEqualOrMore = true;
                    }
                }
            }

            if (!$itemExists || !$qtyEqualOrMore) {
                $isPartial = true;
            }
        }

        if ($isPartial) {
            $refundStatus .= ' with Failed';
        }

        return ['success' => true, 'data' => $refundStatus];
    }

    public function validateInProcessSourceRefund(array $sourceOrder, array $refundData)
    {
        $sourceRefundFilter = ['cif_order_id' => $sourceOrder['cif_order_id']];
        $object_type = 'source_refund';
        if (strpos($sourceOrder['object_type'], 'target') !== false) {
            $object_type = 'target_refund';
        }

        //todo:update query
        $sourceRefundFilter = [
            '$and' => [
                [
                    'cif_order_id' => $sourceOrder['cif_order_id']
                ],
                [
                    '$or' => [
                        ['object_type' => 'cif_target_refund'],
                        ['object_type' => $object_type]
                    ],
                ],
                [
                    'user_id' => $sourceOrder['user_id']
                ]
            ]
        ];
        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $allSourceRefunds = $mongo->find($sourceRefundFilter, $options)->toArray();
        foreach ($allSourceRefunds as $index => $sourceRefund) {
            if (strpos($sourceRefund['status'], 'Inprogress') === false) {
                unset($allSourceRefunds[$index]);
            }

            if ($sourceRefund['reference_id'] == $refundData['reference_id']) {
                unset($allSourceRefunds[$index]);
            }
        }

        if (count($allSourceRefunds) < 1) {
            return $sourceOrder;
        }

        foreach ($sourceOrder['items'] as $sourceItemIndex => $sourceItem) {
            foreach ($allSourceRefunds as $sourceRefund) {
                foreach ($sourceRefund['items'] as $sourceRefundItem) {
                    if ($sourceItem['sku'] == $sourceRefundItem['sku']) {
                        if (!isset($sourceItem['refund_qty'])) {
                            $sourceOrder['items'][$sourceItemIndex]['refund_qty'] = 0;
                        }

                        $sourceOrder['items'][$sourceItemIndex]['refund_qty'] += $sourceRefundItem['qty'];
                    }
                }
            }
        }

        //updating code here
        // echo '<pre>';
        // print_r($sourceOrder);
        // echo '<br><br><b>';
        // die(__FILE__ . '/line ' . __LINE__);
    }

    public function updateParentOrdersTarget(array $updateData)
    {
        $object_type = $updateData['filters']['object_type'];
        $filter['user_id'] = $this->di->getUser()->id;
        $filter['object_type'] = 'source_order';
        if (strpos($object_type, 'source') !== false) {
            $filter['object_type'] = 'target_order';
        }

        $filter['cif_order_id'] = $updateData['filters']['cif_order_id'];
        // $filter['targets.order_id'] = $updateData['filters']['order_id'];
        // $filter['targets.marketplace'] = $updateData['filters']['marketplace'];
        $data['targets.$[target].status'] = $updateData['data']['status'];
        $data['targets.$[target].marketplace_status'] = $updateData['data']['marketplace_status'];
        $data['targets.$[target].refund_status'] = $updateData['data']['refund_status'];
        $arrayFilter = [
            'arrayFilters' =>
            [
                [
                    'target.order_id' => $updateData['filters']['order_id'],
                    'target.marketplace' => $updateData['filters']['marketplace']
                ]
            ]
        ];
        $response = $this->updateDataInDb($filter, $data, $arrayFilter);
        return $response;
    }

    public function updateTargetsInInitiator(array $refundData, $initiatorsTargets)
    {
        // $object_type = 'target_refund';
        // if (strpos($refundData['object_type'], "target") !== false) {
        //     $object_type = 'source_refund';
        // }
        // $filter = [
        //     'cif_order_id' => $refundData['cif_order_id'],
        //     'object_type' => $object_type
        // ];
        $filter['_id'] = new \MongoDB\BSON\ObjectId($refundData['reference_id']);

        if (strpos($refundData['object_type'], "cif") !== false) {
            $filter['object_type'] = 'cif_source_refund';
        }

        $target['targets'] = $initiatorsTargets;
        $updateResponse = $this->updateDataInDb($filter, $target);
        return $updateResponse;
    }

    public function refundResponseUpdate($data, $failed = false): array
    {
        // $filter = ['marketplace_status' => 'Pending', 'response_feed_id' => $data['feed_id']];

        //updating source_refund-----OR------cif_target_refund-------
        if (!isset($data['feed_id']) && !isset($data['message_id'])) {
            $this->saveInLog($data, 'errors/feedId-missing-in-refundResponse.log', 'data in refundResponse');
            return [
                'success' => true,
                'message' => 'feed_id or message_id required'
            ];
        }

        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $sourceRefundFilter = [
            'user_id' => $userId,
            'object_type' => ['$in' => ['source_refund', 'target_refund', 'cif_source_refund', 'cif_target_refund']],
            'marketplace' => ['$in' => [$data['marketplace'] ?? '', 'cif']],
            'marketplace_shop_id' => ['$in' => [$data['marketplace_shop_id'] ?? '', 'cif']],
        ];
        if (isset($data['feed_id'])) {
            $sourceRefundFilter['response_feed_id'] = $data['feed_id'];
        } else {
            $sourceRefundFilter['feed_message_id'] = $data['message_id'];
        }

        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $sourceRefund = $mongo->findOne($sourceRefundFilter, $options);
        if (empty($sourceRefund)) {
            return ['success' => true, 'message' => 'No order with this feed_id or message_id found'];
        }

        $sourceRefundStatus = $sourceRefund['status'];
        $sourceRefundStatus = str_replace(" Inprogress", "", $sourceRefundStatus);
        if ($failed) {
            $sourceRefundStatus = 'Failed';
        }

        $sourceRefundMarketplaceStatus = $sourceRefundStatus;
        // $sourceRefundMarketplaceStatus['updated_at'] = $this->getMongoDate();
        $sourceRefundUpdateData = ['status' => $sourceRefundStatus, 'marketplace_status' => $sourceRefundMarketplaceStatus];
        //adding error if it was failure
        if ($failed) {
            $sourceRefundUpdateData['error'] = $data['message'];
        }

        $sourceRefundResponse = $this->updateDataInDb($sourceRefundFilter, $sourceRefundUpdateData);
        if ($sourceRefundResponse['success']) {
            $unsetSourceRefundInprogress = ['process_inprogess' => 1];
            $this->unsetData($sourceRefundFilter, $unsetSourceRefundInprogress);
        }

        $sourceRefund = $sourceRefundResponse['data'];

        //updating target_refund------OR------cif_source_refund-----
        $targetRefundFilter = ['_id' => new \MongoDB\BSON\ObjectId($sourceRefund['reference_id'])];

        $targetRefundData['targets.$[target].status'] = $sourceRefund['status'];
        $targetRefundData['targets.$[target].refund_status'] = $sourceRefund['status'];
        if ($failed) {
            $targetRefundData['targets.$[target].error'] = $data['message'];
        }

        $targetRefundArrayFilter = [
            'arrayFilters' =>
            [
                ['target.order_id' => $sourceRefund['marketplace_reference_id'], 'target.marketplace' => $sourceRefund['marketplace']]
            ]
        ];
        $targetRefund = $this->updateDataInDb($targetRefundFilter, $targetRefundData, $targetRefundArrayFilter);

        if ($targetRefund['success']) {
            $unsetTargetRefundInprogress = ['process_inprogess' => 1];
            $this->unsetData($targetRefundFilter, $unsetTargetRefundInprogress);
        }

        //updating source_order----------
        $objectType = 'source_order';
        if (strpos($sourceRefund['object_type'], 'target') !== false) {
            $objectType = 'target_order';
        }

        /*
        As refund is generated from target_marketplace and that means
        syncing will fail on source_marketplace. Generally the target_marketplace will be the one having the
        the app, meaning that target_marketplace will be generated as cif_source_refund and
        hence cif_target_refund will be mapped to source_marketplace(source_order)
        */
        if (strpos($sourceRefund['object_type'], "cif_target") !== false) {
            $objectType = 'source_order';
        }

        $sourceOrderFilter = [
            'user_id' => $userId,
            'object_type' => $objectType,
            'cif_order_id' => $sourceRefund['cif_order_id'],
        ];
        $sourceOrder = $mongo->findOne($sourceOrderFilter, $options);
        $sourceOrderData = ['refund_status' => $sourceRefundStatus];
        if ($failed) {
            $sourceOrderData['refund_failed_reason'] = $data['message'];
        } else {
            $sourceOrderStatus = $sourceRefundStatus;
            $sourceOrderMarketplaceStatus = $sourceRefundMarketplaceStatus;
            $sourceOrderData['status'] = $sourceOrderStatus;
            if (strpos($sourceOrderData['refund_status'], 'Failed') === false) {
                if (strpos($sourceOrder['refund_status'], 'Failed') !== false) {
                    $sourceOrderData['refund_status'] .= ' with Failed';
                }
            }

            $sourceOrderData['marketplace_status'] = $sourceOrderMarketplaceStatus;
            foreach ($sourceRefund['items'] as $sourceRefundItem) {
                foreach ($sourceOrder['items'] as $itemIndex => $sourceOrderItem) {
                    if ($sourceOrderItem['sku'] == $sourceRefundItem['sku']) {
                        //updating source_order_items
                        $sourceOrderItemFilter = ['_id' => new \MongoDB\BSON\ObjectId($sourceOrderItem['id'])];
                        $inc = ['refund_qty' => $sourceRefundItem['qty']];
                        $update = ['updated_at' => $this->getMongoDate()];

                        $updateAndIncResponse =
                            $this->incrementAndUpdateDataInDb(
                                $sourceOrderItemFilter,
                                $inc,
                                $update
                            );

                        if (!$updateAndIncResponse['success']) {
                            $this->errors = $updateAndIncResponse['message'];
                            $sourceOrderData["items." . $itemIndex . ".refund_qty"] = $sourceRefundItem['qty'];
                        } else {
                            //if the $inc was successfully updated, fetched the refund_qty from the database
                            $sourceOrderData["items." . $itemIndex . ".refund_qty"] = $updateAndIncResponse['data']['refund_qty'];
                        }
                    }
                }
            }
        }

        $sourceOrderResponse = $this->updateDataInDb($sourceOrderFilter, $sourceOrderData);
        $sourceOrder = $sourceOrderResponse['data'];
        //updating source_order status and refund status if full refund
        if (strpos($sourceOrder['refund_status'], 'Failed') === false && strpos($sourceOrder['refund_status'], 'Partial') !== false) {
            $isCompletelyRefunded = true;
            foreach ($sourceOrder['items'] as $sourceOrderItem) {
                //TODO: update here to add a check for if refund_qty isset
                if (isset($sourceOrderItem['refund_qty']) && $sourceOrderItem['refund_qty'] < $sourceOrderItem['qty']) {
                    $isCompletelyRefunded = false;
                }
            }

            if ($isCompletelyRefunded) {
                $sourceOrderStatusData = [
                    'refund_status' => 'Refund',
                    'marketplace_status' => 'Refund',
                    'status' => 'Refund'
                ];
                $sourceOrderResponse = $this->updateDataInDb($sourceOrderFilter, $sourceOrderStatusData);
                $sourceOrder = $sourceOrderResponse['data'];
            }
        }

        //updating target_order----------

        $targetOrderFilter['object_type'] = 'target_order';
        if ($sourceOrder['object_type'] == 'target_order') {
            $targetOrderFilter['object_type'] = 'source_order';
        }

        $targetOrderFilter['user_id'] = $userId;
        $targetOrderFilter['cif_order_id'] = $sourceOrder['cif_order_id'];
        $targetOrderData['targets.$[target].status'] = $sourceOrder['status'];
        $targetOrderData['targets.$[target].refund_status'] = $sourceOrder['refund_status'];
        $targetOrderData['targets.$[target].marketplace_status'] = $sourceOrder['marketplace_status'];
        if ($failed) {
            $targetOrderData['targets.$[target].error'] = $data['message'];
        }

        $targetOrderArrayFilter = [
            'arrayFilters' =>
            [
                [
                    'target.order_id' => $sourceOrder['marketplace_reference_id'],
                    'target.marketplace' => $sourceOrder['marketplace']
                ]
            ]
        ];
        $target_order = $this->updateDataInDb($targetOrderFilter, $targetOrderData, $targetOrderArrayFilter);

        if ($failed) {
            $this->mailForFailedRefundResponse($sourceOrder, $target_order['data'], $data['message']);
        }

        return ['success' => true, 'data' => 'Updated successfully'];
    }

    public function updateDataInDb($filter, $data, $arrayFilter = []): array
    {
        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);

        //written temporarily as the target_order has order_id inside targets as as int
        //instead of string
        // if (isset($filter['targets.order_id'])) {
        //     $filter['targets.order_id'] = (string)$filter['targets.order_id'];
        // }

        //Todo : add options here
        if (empty($arrayFilter)) {
            $updateResponse = $mongo->findOneAndUpdate($filter, ['$set' => $data], ["returnDocument" => 2]);
        } else {
            $updateResponse = $mongo->findOneAndUpdate($filter, ['$set' => $data], $arrayFilter, ["returnDocument" => 2]);
        }

        if (empty($updateResponse)) {
            return ['success' => false, 'message' => 'Updation filter failed to search any data.'];
        }

        return ['success' => true, 'data' => $updateResponse];
    }

    public function unsetData($filter, $data): array
    {
        $collection = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $updateResponse = $collection->findOneAndUpdate($filter, ['$unset' => $data], ["returnDocument" => 1]);
        if (empty($updateResponse)) {
            return ['success' => false, 'message' => 'updated filter data not found'];
        }

        return ['success' => true, 'data' => $updateResponse];
    }

    //This function's implementation is not correct
    //the query won't work on the third argument. Second and third params are one array
    // public function updateAndUnset($filter, $update_data = [], $unset_data = [])
    // {
    //     $collection = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
    //     $updateAndUnsetResponse = $collection->findOneAndUpdate($filter, ['$set' => $update_data], ['$unset' => $unset_data], ["returnDocument" => 2]);
    //     if (empty($updateAndUnsetResponse)) {
    //         return ['success' => false, 'message' => 'updated filter data not found'];
    //     }
    //     return ['success' => true, 'data' => $updateAndUnsetResponse];
    // }

    public function incrementAndUpdateDataInDb($filter, $inc, $update): array
    {
        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        //TODO: add options here
        $incResponse = $mongo->findOneAndUpdate($filter, ['$inc' => $inc, '$set' => $update], ["returnDocument" => 2]);
        if (empty($incResponse)) {
            return ['success' => false, 'message' => '$inc filter failed to find any data.'];
        }

        return ['success' => true, 'data' => $incResponse];
    }

    // public function updateAllAmounts($total_refund, $total_marketplace_refund, $total_adjust, $total_marketplace_adjust, $total_tax, $total_marketplace_tax, $total_discount, $total_marketplace_discount)
    // {
    //     if (isset($total_refund)) {
    //         $this->refundData['refund_amount']['price'] = $total_refund;
    //         $this->refundData['refund_amount']['marketplace_price'] = $total_marketplace_refund;
    //     }
    //     if (isset($total_adjust)) {
    //         $this->refundData['adjust_amount']['price'] = $total_adjust;
    //         $this->refundData['adjust_amount']['marketplace_price'] = $total_marketplace_adjust;
    //     }
    //     if (isset($total_tax)) {
    //         $this->refundData['tax']['price'] = $total_tax;
    //         $this->refundData['tax']['marketplace_price'] = $total_marketplace_tax;
    //     }
    //     if (isset($total_discount)) {
    //         $this->refundData['discount']['price'] = $total_discount;
    //         $this->refundData['discount']['marketplace_price'] = $total_marketplace_discount;
    //     }
    // }

    public function updateTotalRefundAmount($total_refund, $total_marketplace_refund): void
    {
        $this->refundData['refund_amount']['price'] = $total_refund;
        $this->refundData['refund_amount']['marketplace_price'] = $total_marketplace_refund;
    }

    public function updateTotalAdjustAmount($total_adjust, $total_marketplace_adjust): void
    {
        $this->refundData['adjust_amount']['price'] = $total_adjust;
        $this->refundData['adjust_amount']['marketplace_price'] = $total_marketplace_adjust;
    }

    public function updateTotalTaxAmount($total_tax, $total_marketplace_tax): void
    {
        $this->refundData['tax']['price'] = $total_tax;
        $this->refundData['tax']['marketplace_price'] = $total_marketplace_tax;
    }

    public function updateTotalDiscountAmount($total_discount, $total_marketplace_discount): void
    {
        $this->refundData['discount']['price'] = $total_discount;
        $this->refundData['discount']['marketplace_price'] = $total_marketplace_discount;
    }

    public function getAllFailed(array $params)
    {
        $params['filter']['refund_status'][3] = 'Failed';

        //As source can be initiator as well so removing this filter
        // $params['filter']['object_type'][1] = 'source_order';
        //

        //Todo: can handle below code to take value first from params
        //if they are not specified, then from Requester and finally false
        $params['filter']['targets.marketplace'][1] = $this->di->getRequester()->getSourceName() ?? false;
        $params['filter']['marketplace_shop_id'][1] = $this->di->getRequester()->getTargetId() ?? false;

        if (!$params['filter']['targets.marketplace'][1] || !$params['filter']['marketplace_shop_id'][1]) {
            return ['success' => false, 'message' => 'Required Ced-Params missing in headers!'];
        }

        //<this check is not required. It'll only put more load on db
        // $params['filter']['targets.shop_id'][1] = $this->di->getRequester()->getSourceId();

        //<As targets status refund_status is still as before>
        // $params['filter']['targets.refund_status'][1] = 'Failed';

        $orderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
        $response = $orderService->getAll($params);
        return $response;
    }

    // public function getFailed($params)
    // {
    //     // if (!isset($params['cif_order_id'])) {
    //     //     //for test purposes
    //     //     // $params = [
    //     //     //     'cif_order_id' => '63318402cd41d51b6c759546',
    //     //     //     'marketplace' => 'shopify',
    //     //     //     'marketplace_shop_id' => 11
    //     //     // ];
    //     //     return ['success' => false, 'message' => 'Required parameters cif_order_id, object_type missing.'];
    //     // }
    //     $source_shop_id = $this->di->getRequester()->getSourceId();
    //     $shops = $this->di->getUser()->shops;
    //     foreach ($shops as $shop) {
    //         if ($shop['_id'] == $source_shop_id) {
    //             $marketplace = $shop['marketplace'];
    //         } elseif ($shop['remote'] == $source_shop_id) {

    //         }
    //     }
    //     $object_type = 'target_order';
    //     if (strpos($params['object_type'], 'target') !== false) {
    //         $object_type = 'source_order';
    //     }
    //     $params['object_type'] = $object_type;
    //     $params['marketplace_shop_id'] = $source_shop_id;
    //     $params['marketplace'] = $marketplace;
    //     $orderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
    //     $response = $orderService->get($params);

    //     //toggling between source and target order
    //     if (!isset($response['data'])) {
    //         $params['object_type'] = 'source_order';
    //         $response = $orderService->get($params);
    //     }
    //     if (isset($response['data'])) {
    //         foreach ($response['data']['items'] as $key => $item) {
    //             $additional_prices = [];
    //             if (isset($item['taxes'])) {
    //                 $additional_prices = array_merge($additional_prices, $item['taxes']);
    //             }
    //             if (isset($item['discounts'])) {
    //                 $additional_prices = array_merge($additional_prices, $item['discounts']);
    //             }
    //             $response['data']['items'][$key]['additional_prices'] = $additional_prices;
    //             if (!isset($item['price']['marketplace_price'])) {
    //                 $response['data']['items'][$key]['price'] = [
    //                     'price' => $item['price'],
    //                     'marketplace_price' => $item['marketplace_price'],
    //                 ];
    //             }
    //         }
    //     }
    //     return $response;
    // }

    public function getFailed($params)
    {
        if (!isset($params['cif_order_id'], $params['object_type'])) {
            //for test purposes
            // $params = [
            //     'cif_order_id' => '63318402cd41d51b6c759546',
            //     'marketplace' => 'shopify',
            //     'marketplace_shop_id' => 11
            // ];
            return ['success' => false, 'message' => 'Required parameters cif_order_id, object_type missing.'];
        }

        $userId = $params['user_id'] ?? $this->di->getUser()->id;

        $source_shop_id = $this->di->getRequester()->getSourceId() ?? false;
        if (!$source_shop_id) {
            return ['success' => false, 'message' => 'Required param Ced-Source-Id missing in headers!'];
        }

        $source_marketplace = $this->di->getRequester()->getSourceName() ?? false;

        //if Ced-Source-Name is not Received from headers
        if (!$source_marketplace) {
            $shops = $this->di->getUser()->shops;
            foreach ($shops as $shop) {
                if ($shop['_id'] == $source_shop_id) {
                    $source_marketplace = $shop['marketplace'];
                }
            }
        }

        $object_type = 'target_order';
        if (strpos($params['object_type'], 'target') !== false) {
            $object_type = 'source_order';
        }

        $params['user_id'] = $userId;
        $params['object_type'] = $object_type;
        $params['marketplace_shop_id'] = $source_shop_id;
        $params['marketplace'] = $source_marketplace;
        $orderService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class);
        $response = $orderService->get($params);

        if (!$response['success'] || !isset($response['data'])) {
            return $response;
        }

        foreach ($response['data']['items'] as $key => $item) {
            $additional_prices = [];
            if (isset($item['taxes'])) {
                $additional_prices = array_merge($additional_prices, $item['taxes']);
            }

            if (isset($item['discounts'])) {
                $additional_prices = array_merge($additional_prices, $item['discounts']);
            }

            if (isset($item['shipping_charge'])) {
                $additional_prices = array_merge($additional_prices, $item['shipping_charge']);
            }

            $response['data']['items'][$key]['additional_prices'] = $additional_prices;
            if (!isset($item['price']['marketplace_price'])) {
                $response['data']['items'][$key]['price'] = [
                    'price' => $item['price'],
                    'marketplace_price' => $item['marketplace_price'],
                ];
            }
        }

        $requiredData = $this->removeAlreadyProcessedRefundsData($response['data'], $params);
        $requiredData = $this->removeProcessingRefundsData($requiredData, $params);
        foreach ($requiredData['items'] as $key => $item) {
            if (isset($item['shipping_charge'])) {
                $requiredData['items'][$key]['additional_prices'][] = [
                    'code' => 'Shipping charge',
                    'price' => $item['shipping_charge']['price'],
                    'marketplace_price' => $item['shipping_charge']['marketplace_price']
                ];
            }
        }

        $requiredData = $this->getFailedRefundCustomerNotes($requiredData);
        $requiredData = $this->removeTaxIfIsIncluded($requiredData);
        $requiredData['items'] = array_values($requiredData['items']);
        return ['success' => true, 'data' => $requiredData];
    }

    public function removeAlreadyProcessedRefundsData(array $target_order, array $searchData)
    {

        //todo : update query
        $query = [
            'user_id' => $searchData['user_id'],
            'cif_order_id' => $searchData['cif_order_id']
        ];

        if ($searchData['object_type'] == 'target_order') {
            $query['object_type'] = 'source_order';
        } else {
            $query['object_type'] = 'target_order';
        }

        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $sourceOrder = $mongo->findOne($query, $options);
        if (empty($sourceOrder)) {
            return [
                'success' => false,
                'message' => 'source_order not found for this target_order'
            ];
        }

        foreach ($target_order['items'] as $itemIndex => $targetItem) {
            foreach ($sourceOrder['items'] as $sourceItem) {
                //update this to use item_link_id instead
                if ($sourceItem['sku'] != $targetItem['sku']) {
                    continue;
                }

                //temporarily adding shipping charges as they are missing in target_order
                //add check if missing in target_order
                if (isset($sourceItem['shipping_charge'])) {
                    $target_order['items'][$itemIndex]['shipping_charge'] = $sourceItem['shipping_charge'];
                }

                //----------

                if (!isset($targetItem['refund_qty']) && !isset($sourceItem['refund_qty'])) {
                    continue;
                }

                if (!isset($sourceItem['refund_qty']) && isset($targetItem['refund_qty'])) {
                    continue;
                }

                if (isset($sourceItem['refund_qty']) && !isset($targetItem['refund_qty'])) {
                    $target_order['items'][$itemIndex]['qty'] -= $sourceItem['refund_qty'];
                    if ($target_order['items'][$itemIndex]['qty'] < 1) {
                        unset($target_order['items'][$itemIndex]);
                    }

                    continue;
                }

                if ($sourceItem['refund_qty'] != $targetItem['refund_qty']) {
                    $target_order['items'][$itemIndex]['refund_qty'] -= $sourceItem['refund_qty'];
                    if ($target_order['items'][$itemIndex]['refund_qty'] < 0) {
                        $target_order['items'][$itemIndex]['refund_qty'] = 0;
                    }

                    $target_order['items'][$itemIndex]['qty'] -= $sourceItem['refund_qty'];
                    continue;
                }

                unset($target_order['items'][$itemIndex]);
            }
        }

        $requiredData = $this->trimIncorrectDataInFailedRefund($target_order);
        return $requiredData;
    }

    /**
     * @return mixed[]
     */
    public function removeProcessingRefundsData(array $target_order, array $searchData): array
    {

        //todo : update query
        $sourceRefundFilter = [
            'cif_order_id' => $target_order['cif_order_id']
        ];
        $object_type = 'source_refund';
        if (strpos($target_order['object_type'], 'source') !== false) {
            $object_type = 'target_refund';
        }

        // $sourceRefundFilter = [
        //     '$and' => [
        //         [
        //             'cif_order_id' => $target_order['cif_order_id']
        //         ],
        //         [
        //             '$or' => [
        //                 ['object_type' => 'cif_target_refund'],
        //                 ['object_type' => $object_type]
        //             ],
        //         ],
        //         [
        //             'status' => [
        //                 '$regex' => 'Inprogress'
        //             ]
        //         ]
        //     ]
        // ];
        $sourceRefundFilter = [
            'user_id' => $searchData['user_id'],
            'object_type' => ['$in' => ['cif_target_refund', $object_type]],
            'marketplace' => ['$exists' => true],
            'marketplace_shop_id' => ['$exists' => true],
            'cif_order_id' => $target_order['cif_order_id'],
            'status' => ['$regex' => 'Inprogress']
        ];
        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $allInprogressSourceRefunds = $mongo->find($sourceRefundFilter, $options)->toArray();
        if (empty($allInprogressSourceRefunds)) {
            return $target_order;
        }

        foreach ($target_order['items'] as $itemIndex => $item) {
            foreach ($allInprogressSourceRefunds as $refund) {
                foreach ($refund['items'] as $refundItem) {
                    if ($item['sku'] == $refundItem['sku']) {
                        $target_order['items'][$itemIndex]['qty'] -= $refundItem['qty'];
                        if ($target_order['items'][$itemIndex]['qty'] < 1) {
                            unset($target_order['items'][$itemIndex]);
                            continue;
                        }

                        if (!isset($target_order['items'][$itemIndex]['refund_qty'])) {
                            continue;
                        }

                        $target_order['items'][$itemIndex]['refund_qty'] -= $refundItem['qty'];
                        if ($target_order['items'][$itemIndex]['refund_qty'] < 1) {
                            unset($target_order['items'][$itemIndex]['refund_qty']);
                        }
                    }
                }
            }
        }

        return $target_order;
    }

    private function getFailedRefundCustomerNotes(array $data): array
    {
        $objectType = stripos($data['object_type'], 'target') !== false
            ? 'target_refund'
            : 'source_refund';

        $failedRefundFilter = [
            'user_id' => $data['user_id'],
            'object_type' => $objectType,
            'marketplace' => $data['marketplace'],
            'marketplace_shop_id' => $data['marketplace_shop_id'],
            'cif_order_id' => $data['cif_order_id'],
            'items.marketplace_item_id' => ['$in' => array_column($data['items'], 'marketplace_item_id')],
            'targets.status' => 'Failed',
            'customer_note' => ['$ne' => ""]
        ];

        $mongo = $this->getBaseMongoAndCollection(self::ORDER_CONTAINER);
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array'],
            'projection' => ['_id' => 0, 'customer_note' => 1],
        ];

        $failedRefund =  $mongo->findOne($failedRefundFilter, $options);
        $data['refund_reason'] = $failedRefund['customer_note'] ?? null;
        return $data;
    }

    /**
     * @return mixed[]
     */
    public function removeTaxIfIsIncluded(array $target_order): array
    {
        $taxIncluded = false;
        if (isset($target_order['taxes_included']) && $target_order['taxes_included']) {
            $taxIncluded = true;
        }

        if (!$taxIncluded) {
            return $target_order;
        }

        $taxesToBeRemoved = ["ItemTax", "Item Tax", "ShippingTax", "Shipping Tax"];
        foreach ($target_order['items'] as $item_index => $item) {
            foreach ($item['taxes'] as $tax) {
                if (in_array($tax['code'], $taxesToBeRemoved)) {
                    $target_order['items'][$item_index]['tax']['marketplace_price'] -= $tax['marketplace_price'];
                    $target_order['items'][$item_index]['tax']['price'] -= $tax['price'];
                    // unset($target_order['items'][$item_index]['taxes'][$tax_index]);
                }
            }
        }

        return $target_order;
    }

    public function trimIncorrectDataInFailedRefund(array $data): array
    {
        $unrequiredData = [
            'total' => 1,
            'sub_total' => 1,
            'tax' => 1,
            'taxes' => 1,
            'discount' => 1,
            'discounts' => 1,
            'shipping_charges' => 1,
            'shipping_address' => 1,
            'billing_address' => 1,
        ];

        foreach ($data as $key => $value) {
            if (isset($unrequiredData[$key])) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    public function getRefundReasons(array $data): array
    {
        if (empty($data['target'])) {
            return ['status' => false, 'message' => 'required parameter target missing'];
        }

        $targetMarketplace = $data['target'];
        $targetRefundService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\Order\RefundInterface::class, [], $targetMarketplace);
        if (!method_exists($targetRefundService, 'getRefundReasons')) {
            return ['status' => false, 'message' => 'target_marketplace missing required method'];
        }

        $refundReasons = $targetRefundService->getRefundReasons();

        // mapped to status and message instead of success and data for the sake of
        // uniformity with shipping_code reasons
        $refundReasons = [
            'status' => true,
            'message' => $refundReasons
        ];
        return $refundReasons;
    }

    public function mailForFailedRefundResponse($sourceOrder, $targetOrder, $reason): string
    {
        $helperObj  = $this->di->getObjectManager()->get('App\Connector\Components\Order\Helper');
        $canSendMail = $helperObj->settingEnabledForMailSend($targetOrder['marketplace_shop_id'] ?? "", $sourceOrder['marketplace_shop_id'] ?? "", 'email_on_failed_refund');
        $this->saveInLog('canSendMail: '.$canSendMail, 'email/email-process.log');
        if($canSendMail) {
            $userDetails = $this->di->getUser()->getConfig();
            foreach ($userDetails['shops'] as $shop) {
                if ($shop['_id'] == $sourceOrder['marketplace_shop_id']) {
                    $email = $shop['email'] ?? false;
                    $name = $shop['name'] ?? false;
                }
            }

            //update code below to get order id and marketplaces in actual format and not cif
            if (!$email || !$name) {
                foreach ($userDetails['shops'] as $shop) {
                    if ($shop['_id'] == $targetOrder['marketplace_shop_id']) {
                        $email = $shop['email'] ?? false;
                        $name = $shop['name'] ?? false;
                    }
                }
            }

            if (!$email) {
                $email = $userDetails['source_email'] ?? ($userDetails['email'] ?? "");
            }

            if (!$name) {
                $name = $userDetails['username'] ?? false;
            }

            $data['source_marketplace'] = ucfirst($sourceOrder['marketplace']);
            $data['target_marketplace'] = ucfirst($targetOrder['marketplace']);
            $data['source_order_id'] = $sourceOrder['marketplace_reference_id'];
            $data['target_order_id'] = $targetOrder['marketplace_reference_id'];
            $data['source_shop_id'] = $targetOrder['marketplace_shop_id'] ?? "";
            $data['target_shop_id'] = $sourceOrder['marketplace_shop_id'] ?? "";

            $path = 'connector' . DS . 'views' . DS . 'email' .  DS . 'Refund-order.volt';
            $data['email'] = $email;
            $bccs[] = !empty($userDetails['alternate_email']) ? $userDetails['alternate_email'] : "";
            $data['bccs'] = $bccs;
            $data['user_id'] = $this->di->getUser()->id;
            $data['name'] = isset($name) ? $name : "there";
            $data['path'] = $path;
            $data['app_name'] = $this->di->getConfig()->app_name;
            $data['subject'] = 'Refund Order Sync Failure - ' . $data['app_name'];
            $data['reason'] = $reason;
            $data['has_unsubscribe_link'] = false;
            $linkdata = [
                'user_id' => $this->di->getUser()->id,
                'app_tag' => $this->di->getAppCode()->getAppTag() ?? 'default',
                'source_shop_id' => $targetOrder['marketplace_shop_id'] ?? "",
                'setting_name' => 'email_on_failed_refund',
                'target_shop_id' => $sourceOrder['marketplace_shop_id'] ?? "",
                'source' => strtolower($data['target_marketplace']),
                'target' => strtolower($data['source_marketplace'])
            ];
            $linkResponse = $helperObj->getUnsubscribeLink($linkdata);
            $this->saveInLog('Link response'. json_encode($linkResponse), 'email/email-process.log');
            //$this->addLog("linkResponse:" . json_encode($linkResponse), 'order/order-cancel/' . $this->di->getUser()->id . '/' . date('d-m-y') . '/automatic-cancel.log', '');
            if($linkResponse['success']) {
                $data['has_unsubscribe_link'] = true;
                $data['unsubscribe_link'] = $linkResponse['data'];
            }

            $gridlinkRes = $helperObj->getGridLink($data, $this->di->getAppCode()->getAppTag() ?? 'default', 'refund');
            $data['has_order_grid_link'] = false;
            if($gridlinkRes) {
                $data['order_grid_link'] = $gridlinkRes;
                $data['has_order_grid_link'] = true;
            }

            $returnResponse = 'email sent';
            try {
                $this->saveInLog('Data send for mail: '.json_encode($data), 'email/email-process.log');
                $response = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
                $eventsManager = $this->di->getEventsManager();
                $eventsManager->fire('application:orderRefundFail', $this, $data);
                if (!$response) {
                    $returnResponse = 'email not sent. Received false from sendMail component';
                }
            } catch (\Exception $e) {
                $this->saveInLog($e->getMessage(), 'errors/email-process.log');
                $this->saveInLog($path, 'errors/email-process.log', 'template path');
                $returnResponse = 'Error in sending email (' . $e->getMessage() . ')';
            }

            return $returnResponse;
        }
        return 'settings disabled for mail send!';
    }

    public function notifyForFailedRefund(array $refundData): string
    {
        $helperObj  = $this->di->getObjectManager()->get('App\Connector\Components\Order\Helper');
        $canSendMail = $helperObj->settingEnabledForMailSend($refundData['data']['target_shop_id'], $refundData['data']['marketplace_shop_id'], 'email_on_failed_refund');
        $this->saveInLog('canSendMail: '.$canSendMail, 'email/email-process.log');
        if($canSendMail) {
            $userDetails = $this->di->getUser()->getConfig();
            foreach ($userDetails['shops'] as $shop) {
                if ($shop['_id'] == $refundData['data']['marketplace_shop_id']) {
                    $email = $shop['email'] ?? false;
                    $name = $shop['name'] ?? false;
                }
            }

            if (!$email || !$name) {
                foreach ($userDetails['shops'] as $shop) {
                    if ($shop['_id'] == $refundData['data']['target_marketplace_shop_id']) {
                        $email = $shop['email'] ?? false;
                        $name = $shop['name'] ?? false;
                    }
                }
            }

            if (!$email) {
                $email = $userDetails['email'];
            }

            if (!$name) {
                $name = $userDetails['username'] ?? false;
            }

            $sourceOrderId = $refundData['data']['marketplace_reference_id'];
            $targetOrderId = $refundData['data']['target_marketplace_reference_id'];
            $sourceMarketplace = $refundData['data']['marketplace'];
            $targetMarketplace = $refundData['data']['target_marketplace'];
            $refundFailReason = $refundData['message'];

            $path = 'connector' . DS . 'views' . DS . 'email' .  DS . 'Refund-order.volt';
            $data['email'] = $email;
            $bccs[] = !empty($userDetails['alternate_email']) ? $userDetails['alternate_email'] : "";
            $data['bccs'] = $bccs;
            $data['name'] = isset($name) ? $name : "there";
            $data['path'] = $path;
            $data['user_id'] = $this->di->getUser()->id;
            $data['app_name'] = $this->di->getConfig()->app_name;
            $data['subject'] = 'Refund Order Sync Failure - ' . $data['app_name'];
            $data['source_order_id'] = $sourceOrderId;
            $data['target_order_id'] = $targetOrderId;
            $data['reason'] = $refundFailReason;
            $data['target_marketplace'] = ucfirst($targetMarketplace);
            $data['source_marketplace'] = ucfirst($sourceMarketplace);
            $data['has_unsubscribe_link'] = false;
            $data['source_shop_id'] =  $refundData['data']['target_shop_id'];
            $data['target_shop_id'] = $refundData['data']['marketplace_shop_id'];
            $linkdata = [
                'user_id' => $this->di->getUser()->id,
                'app_tag' => $this->di->getAppCode()->getAppTag() ?? 'default',
                'source_shop_id' => $refundData['data']['target_shop_id'],
                'setting_name' => 'email_on_failed_refund',
                'target_shop_id' => $refundData['data']['marketplace_shop_id'],
                'source' => strtolower($targetMarketplace),
                'target' => strtolower($sourceMarketplace)
            ];
            $linkResponse = $helperObj->getUnsubscribeLink($linkdata);
            $this->saveInLog('Link response'. json_encode($linkResponse), 'email/email-process.log');
            //$this->addLog("linkResponse:" . json_encode($linkResponse), 'order/order-cancel/' . $this->di->getUser()->id . '/' . date('d-m-y') . '/automatic-cancel.log', '');
            if($linkResponse['success']) {
                $data['has_unsubscribe_link'] = true;
                $data['unsubscribe_link'] = $linkResponse['data'];
            }

            $gridlinkRes = $helperObj->getGridLink($data, $this->di->getAppCode()->getAppTag() ?? 'default', 'refund');
            $data['has_order_grid_link'] = false;
            if($gridlinkRes) {
                $data['order_grid_link'] = $gridlinkRes;
                $data['has_order_grid_link'] = true;
            }

            $returnResponse = 'email sent';
            try {
                $this->saveInLog('Data send for mail: '.json_encode($data), 'email/email-process.log');
                $response = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
                $eventsManager = $this->di->getEventsManager();
                $eventsManager->fire('application:orderRefundFail', $this, $data);
                if (!$response) {
                    $returnResponse = 'email not sent. Received false from sendMail component';
                }
            } catch (\Exception $e) {
                $this->saveInLog($e->getMessage(), 'errors/email-process.log');
                $this->saveInLog($path, 'errors/email-process.log', 'template path');
                $returnResponse = 'Error in sending email (' . $e->getMessage() . ')';
            }

            return $returnResponse;
        }
        return 'settings disabled for mail send!';
    }

    public function getBaseMongoAndCollection($collection)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        return $mongo->getCollection($collection);
    }

    public function getConnectorPrice($marketplaceCurrency, $price): string
    {
        // $this->currencyService ??= $this->di->getObjectManager()
        //     ->get(\App\Connector\Contracts\Currency\CurrencyInterface::class);

        $this->currencyService ??= $this->di->getObjectManager()
        ->get(\App\Connector\Contracts\Currency\CurrencyInterface::class);

        return $this->currencyService->convert($marketplaceCurrency, $price);
    }


    public function saveInLog($data, string $file, string $type = 'message'): void
    {
        $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
        $user_id = $this->di->getUser()->id;
        $div = '--------------------------------------------------------------------------------';
        $this->di->getLog()->logContent($div . PHP_EOL . $time . PHP_EOL . $type . ' = ' . print_r($data, true), 'info', 'order/order-refund/'  . $user_id . '/' . date("Y-m-d") . '/' . $file);
    }

    public function prepareForDb(array $order): array
    {
        return ['success' => false, 'message' => 'work in progress'];
    }

    public function getMongoDate($mongoObject = false): \MongoDB\BSON\UTCDateTime|string
    {
        return $mongoObject ? new \MongoDB\BSON\UTCDateTime() : date('c');
    }

    public function isRefundable(): bool
    {
        return true;
    }

    //Not required here but needed for interface compatibility
    //but it is necessary in all other modules
    public function isFeedBased(): bool
    {
        return false;
    }

    public function hasResponseContent(): bool
    {
        return false;
    }

    //Not required here but needed for interface compatibility
    //but it is necessary in all other modules
    public function getSettings(array $order): array
    {
        return [];
    }
}
