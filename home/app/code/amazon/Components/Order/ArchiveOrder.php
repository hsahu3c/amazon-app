<?php

/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Amazon
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Amazon\Components\Order;

use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base as Base;
use Exception;

class ArchiveOrder extends Base
{

    private $mongo;
    private $orderContainer;
    private $archiveOrderContainer;


    /**
     * Initializes MongoDB collections used in this class.
    */
    public function initiate()
    {
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->orderContainer = $this->mongo->getCollectionForTable('order_container');
        $this->archiveOrderContainer = $this->mongo->getCollection('archive_order_container');
    }

    /**
     * Retrieves archive order data from archive_order_container collection
     *
     * @param array $params
     * @param string (optional) limit
     * @param string (optional) activePage  
     * @return array
     */
    public function getArchiveOrders($params = [])
    {
        try {
            $this->initiate();
            $limit = (int) ($params['limit'] ?? 10);
            $page = $params['activePage'] ?? 1;
            $offset = ($page - 1) * $limit;
            $targetShopId = $this->di->getRequester()->getTargetId() ?? $params['target_shop_id'];
            $options = [
                'projection' => ['_id' => 0, 'marketplace_reference_id' => 1, 'targets' => 1, 'created_at' => 1, 'order_created_at' => 1, 'marketplace_status' => 1, 'archive_type' => 1, 'fulfilled_by' => 1, 'source_created_at' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'limit' => $limit,
                'skip' => $offset,
                'sort' => ['_id' => -1]
            ];
            $filter = [
                'user_id' => $this->di->getUser()->id,
                'marketplace_shop_id' => $targetShopId
            ];
            if (isset($params['order_id'])) {
                $filter['marketplace_reference_id'] = $params['order_id'];
            } else {
                $count = $this->getOrdersCount($filter);
            }
            $archiveOrders = $this->archiveOrderContainer->find(
                $filter,
                $options
            )->toArray();
            if (!empty($archiveOrders)) {
                return ['success' => true, 'data' => $archiveOrders, 'count' => $count ?? 1];
            }

            return ['success' => false, 'message' => 'Unable to find archive orders'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from getArchiveOrders(), Error:' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }
    }

    /**
     * Retrive total Archive Orders Count
     *
     * @param array $filter
     * @return number
    */
    private function getOrdersCount($filter)
    {
        return $this->archiveOrderContainer->count(
            $filter
        );
    }

    /**
     * Archive a particular order
     *
     * @param array $params
     * @param string (required) order_id
     * @param string (required) cif_order_id  
     * @return array
    */
    public function moveOrderInArchiveCollection($params = [])
    {
        $this->initiate();
        if (isset($params['order_id'], $params['cif_order_id'])) {
            $targetShopId = $this->di->getRequester()->getTargetId() ?? $params['target_shop_id'];
            $orderData = $this->getOrderData($params['order_id'], $targetShopId);
            if (!empty($orderData)) {
                $orderData['status'] = 'archived';
                $hasCompletedLifeCycleResponse = $this->hasCompletedLifeCycle($orderData);
                if ($hasCompletedLifeCycleResponse) {
                    return ['success' => false, 'message' => 'Unable to archive the order.This order has not completed its lifecycle.'];
                }
                $archiveOrderData = $this->prepareArchiveOrderData($orderData);
                $orderRelatedDocs = $this->getOrderRelatedDocs($params['order_id'], $targetShopId);
                if (!empty($orderRelatedDocs)) {
                    $archiveOrderData = $this->mergeOrderData($archiveOrderData, $orderRelatedDocs);
                }
                $this->archiveOrderContainer->insertOne($archiveOrderData[$params['order_id']]);
                $this->deleteOrderByCifOrderId($params['cif_order_id']);
                return ['success' => true, 'message' => 'Order Archived Successfully'];
            } else {
                $message = 'Order data not found';
            }
        } else {
            $message = 'Required params missing(order_id, cif_order_id)';
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    /**
     * Archive orders in bulk
     *
     * @param array $params
     * @param string (required) order_ids
     * @return array
     */
    public function moveOrderDataInArchiveCollectionBulk($params = [])
    {
        $this->initiate();
        if (isset($params['order_ids'])) {
            $targetShopId = $this->di->getRequester()->getTargetId() ?? $params['target_shop_id'];
            $orders = $this->getOrdersData($params['order_ids'], $targetShopId);
            if (!empty($orders)) {
                $failedArchiveOrders = [];
                $successArchiveOrders = [];
                $successCifOrderIds = [];
                foreach ($orders as $orderData) {
                    $orderData['status'] = 'archived';
                    $hasCompletedLifeCycleResponse = $this->hasCompletedLifeCycle($orderData);
                    if ($hasCompletedLifeCycleResponse) {
                        $failedArchiveOrders[] = $orderData['marketplace_reference_id'];
                        continue;
                    }
                    $archiveOrderData = $this->prepareArchiveOrderData($orderData);
                    $orderRelatedDocs = $this->getOrderRelatedDocs($orderData['marketplace_reference_id'], $targetShopId);
                    if (!empty($orderRelatedDocs)) {
                        $archiveOrderData = $this->mergeOrderData($archiveOrderData, $orderRelatedDocs);
                    }
                    $successArchiveOrders[] = $archiveOrderData[$orderData['marketplace_reference_id']];
                    $successCifOrderIds[] = $orderData['cif_order_id'];
                }
                if (!empty($successArchiveOrders)) {
                    $this->archiveOrderContainer->insertMany(array_values($successArchiveOrders));
                    $this->deleteOrderByCifOrderIds($successCifOrderIds);
                    return ['success' => true, 'message' => 'Orders Archived Successfully', 'order_ids_failed_to_move' => $failedArchiveOrders];
                } else {
                    $message = 'Unable to archive selected orders as lifecycle is not completed';
                }
            } else {
                $message = 'Unable to fetch orders data';
            }
        } else {
            $message = 'Required params missing(order_ids)';
        }
        return ['success' => false, 'message' => $message ?? "Something went wrong"];
    }

    /**
     * Fetching orders data by orderIds
     *
     * @param string $orderIds
     * @param string $shopId
     * @return array
    */
    private function getOrdersData($orderIds, $shopId)
    {
        try {
            $options = [
                'projection' => [
                    'user_id' => 1,
                    'marketplace' => 1,
                    'marketplace_shop_id' => 1,
                    'created_at' => 1,
                    'source_created_at' => 1,
                    'status' => 1,
                    'marketplace_status' => 1,
                    'last_delivery_date' => 1,
                    'fulfilled_by' => 1,
                    'marketplace_reference_id' => 1,
                    'total' => 1,
                    'targets' => 1,
                    'items.type' => 1,
                    'items.sku' => 1,
                    'items.qty' => 1,
                    'items.title' => 1,
                    'items.marketplace_price' => 1,
                    'shipping_address' => 1,
                    'cif_order_id' => 1
                ],
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];
            return $this->orderContainer->find(
                [
                    'user_id' => $this->di->getUser()->id,
                    'object_type' => ['$in' => ['source_order', 'target_order']],
                    'marketplace' => Helper::TARGET,
                    'marketplace_shop_id' => $shopId,
                    'marketplace_reference_id' => ['$in' => $orderIds]
                ],
                $options
            )->toArray();
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from getOrdersData(), Error:' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * Retrieve archive view order data
     * 
     * @param array $params
     * @param string (required) order_id
     * @return array
     */
    public function getArchiveOrderData($params = [])
    {
        $this->initiate();
        try {
            if (!empty($params['order_id'])) {
                $targetShopId = $this->di->getRequester()->getTargetId() ?? $params['target_shop_id'];
                $options = [
                    'typeMap' => ['root' => 'array', 'document' => 'array']
                ];
                $archiveViewData = $this->archiveOrderContainer->findOne(
                    [
                        'user_id' => $this->di->getUser()->id,
                        'marketplace_shop_id' => $targetShopId,
                        'marketplace_reference_id' => $params['order_id']
                    ],
                    $options
                );
                if (!empty($archiveViewData)) {
                    return ['success' => true, 'data' => $archiveViewData];
                } else {
                    $message = 'Unable to fetch archive order data';
                }
            } else {
                $message = 'Required Params missing(order_id)';
            }

            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from getArchiveOrderData(), Error:' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * Checks if order life cycle is completed or not
     * 
     * @param array $orderData
     * @return array
    */
    private function hasCompletedLifeCycle($orderData)
    {
        $orderLifeCycleExists = false;
        if ((isset($orderData['last_delivery_date']) && $orderData['last_delivery_date'] >= date('c'))
            && ($orderData['status'] != 'Cancelled')
        ) {
            $orderLifeCycleExists = true;
        }
        return $orderLifeCycleExists;
    }

    /**
     * Deleting order related docs by cif_order_id
     *
     * @param string $cifOrderId
     * @return null
    */
    private function deleteOrderByCifOrderId($cifOrderId)
    {
        try {
            $this->orderContainer->deleteMany(
                [
                    'user_id' => $this->di->getUser()->id,
                    'cif_order_id' => $cifOrderId
                ]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from deleteOrderByCifOrderId(), Error:' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * Deleting order related docs by cif_order_id
     * 
     * @param string $cifOrderId
     * @return null
    */
    private function deleteOrderByCifOrderIds($cifOrderIds)
    {
        try {
            $this->orderContainer->deleteMany(
                [
                    'cif_order_id' => ['$in' => $cifOrderIds]
                ]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from deleteOrderByCifOrderIds(), Error:' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * Fetching particular order data 
     * 
     * @param string $orderId
     * @param string $shopId
     * @return array
    */
    private function getOrderData($orderId, $shopId)
    {
        try {
            $options = [
                'projection' => [
                    'user_id' => 1,
                    'marketplace' => 1,
                    'marketplace_shop_id' => 1,
                    'created_at' => 1,
                    'source_created_at' => 1,
                    'status' => 1,
                    'marketplace_status' => 1,
                    'last_delivery_date' => 1,
                    'fulfilled_by' => 1,
                    'marketplace_reference_id' => 1,
                    'total' => 1,
                    'targets' => 1,
                    'items.type' => 1,
                    'items.sku' => 1,
                    'items.qty' => 1,
                    'items.title' => 1,
                    'items.marketplace_price' => 1,
                    'shipping_address' => 1,
                    'cif_order_id' => 1
                ],
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];
            return $this->orderContainer->findOne(
                [
                    'user_id' => $this->di->getUser()->id,
                    'object_type' => ['$in' => ['source_order', 'target_order']],
                    'marketplace' => Helper::TARGET,
                    'marketplace_shop_id' => $shopId,
                    'marketplace_reference_id' => $orderId
                ],
                $options
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from getOrderData(), Error:' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * Merging order related docs into single doc
     * 
     * @param array $sourceOrderData
     * @param array $orderRelatedDocs
     * @return array
     */
    private function mergeOrderData($sourceOrderData, $orderRelatedDocs)
    {
        $mappedKeys = [
            'source_shipment' => 'shipment',
            'source_cancellation' => 'cancellation',
            'source_refund' => 'refund'
        ];
        foreach ($orderRelatedDocs as $doc) {
            $unique = $doc['_id'];
            $sourceOrderData[$unique['id']][$mappedKeys[$unique['object_type']]] =  $doc['data'];
        }
        return $sourceOrderData;
    }

    /**
     * Fetching order related docs data
     * like shipment, cancellation and refund
     * 
     * @param string $orderId
     * @param string $shopId
     * @return array
     */
    private function getOrderRelatedDocs($orderId, $shopId)
    {
        try {
            $pipeline = [
                ['$match' => [
                    'user_id' => $this->di->getUser()->id,
                    'object_type' => ['$in' => ['source_shipment', 'source_cancellation', 'source_refund']],
                    'marketplace_shop_id' => $shopId,
                    'marketplace' => Helper::TARGET,
                    'marketplace_reference_id' => $orderId
                ]],
                ['$project' => [
                    'created_at' => 1,
                    'tracking' => 1,
                    'cancel_reason' => 1,
                    'error' => 1,
                    'refund_amount' => 1,
                    'marketplace_reference_id' => 1,
                    'object_type' => 1,
                    'items.sku' => 1,
                    'items.qty' => 1,
                    'items.marketplace_price' => 1,
                    'items.cancel_reason' => 1,
                    'items.customer_note' => 1,
                    'items.cancelled_qty' => 1,
                    'items.shipped_qty' => 1,
                    'items.title' => 1
                ]],
                [
                    '$group' => [
                        '_id' => ['id' => '$marketplace_reference_id', 'object_type' => '$object_type'],
                        'data' => [
                            '$push' => '$$ROOT'
                        ]
                    ]
                ],
            ];
            return $this->orderContainer->aggregate(
                $pipeline,
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            )->toArray();
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from getOrderRelatedDocs(), Error:' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * Preparing archive order data
     * 
     * @param array $orderData
     * @return array
     */
    private function prepareArchiveOrderData($orderData)
    {
        $preparedData[$orderData['marketplace_reference_id']] = [
            'user_id' => $orderData['user_id'] ?? '',
            'marketplace_shop_id' => $orderData['marketplace_shop_id'] ?? '',
            'marketplace' => $orderData['marketplace'] ?? '',
            'marketplace_reference_id' => $orderData['marketplace_reference_id'] ?? '',
            'marketplace_status' => $orderData['marketplace_status'] ?? 'Created',
            'status' => 'archived',
            'items' => $orderData['items'] ?? [],
            'marketplace_price' => $orderData['total']['marketplace_price'] ?? 0,
            'targets' => $orderData['targets'] ?? '',
            'archive_type' => 'manual',
            'fulfilled_by' => $orderData['fulfilled_by'] ?? '',
            'order_created_at' => $orderData['created_at'] ?? '',
            'source_created_at' => $orderData['source_created_at'] ?? '',
            'created_at' => date('c')
        ];
        if (!empty($orderData['shipping_address'])) {
            $preparedData[$orderData['marketplace_reference_id']]['shipping_address'] = [
                'address_line_1' => $orderData['shipping_address'][0]['address_line_1'] ?? '',
                'city' => $orderData['shipping_address'][0]['city'] ?? '',
                'state' => $orderData['shipping_address'][0]['state'] ?? '',
                'zip_code' => $orderData['shipping_address'][0]['zip_code'] ?? '',
                'country' => $orderData['shipping_address'][0]['country'] ?? ''
            ];
        }

        return $preparedData;
    }
}
