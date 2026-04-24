<?php

/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Connector\Service;

use App\Connector\Contracts\Sales\OrderInterface;
use App\Connector\Components\Order\OrderStatus;
use Exception;

/**
 * Interface OrderInterface
 * @services
 */
class Order implements OrderInterface
{
    private $orderId;

    private $sentData;

    private ?string $schemaObjectType = null;

    private ?string $orderItemType = null;

    private $currencyObj;

    public static $defaultPagination = 20;

    const IS_EQUAL_TO = 1;

    const IS_NOT_EQUAL_TO = 2;

    const IS_CONTAINS = 3;

    const IS_NOT_CONTAINS = 4;

    const START_FROM = 5;

    const END_FROM = 6;

    const RANGE = 7;

    const IS_GREATER_THAN = 8;

    const IS_LESS_THAN = 9;

    const IS_EXISTS = 10;

    const CONTAIN_IN_ARRAY = 11;

    const NOT_CONTAINS_IN_ARRAY = 12;

    const ORDER_CONTAINER = 'order_container';

    const AFTER_ORDER_FETCH_EVENT = 'afterOrderFetch';

    const AFTER_TARGET_ORDER_CREATED_EVENT = 'afterTargetOrderCreated';



    public function setSchemaObject(string $schemaObject): string
    {
        $this->schemaObjectType = $schemaObject;
        return  $this->schemaObjectType;
    }

    public function delete(array $data)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $order_container = $mongo->getCollectionForTable('order_container');
            $queryFilter = [
                'user_id' => $data['user_id'],
                'object_type' => ['$ne' => null],
                'marketplace_shop_id' => $data['shop_id']
            ];
            if (isset($data['targets.shop_id'])) {
                $queryFilter['targets.shop_id'] = $data['targets.shop_id'];
            }

            $orderData = $order_container->find(
                $queryFilter,
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            )->toArray();
            if (!empty($orderData)) {
                $unsetData = [];
                $notUnsetKeys = [
                    '_id',
                    'user_id',
                    'shop_id',
                    'object_type',
                    'marketplace',
                    'marketplace_reference_id',
                    'cif_order_id',
                    'marketplace_shop_id'
                ];
                foreach ($orderData as $order) {
                    foreach ($order as $key => $value) {
                        if (in_array($key, $notUnsetKeys)) {
                            continue;
                        }

                        $unsetData[$key] = 1;
                    }
                }

                $order_container->updateMany(
                    $queryFilter,
                    ['$unset' => $unsetData]
                );
            }

            return ['success' => true, 'message' => 'Keys unset successfully!!'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from Connector/Order/Service delete() method: ' . json_encode($e), 'info',  'exception.log');
        }
    }

    public function create(array $data): array
    {
        if (!empty($data['marketplace_reference_id'])) {
            $generatedOrderIdData = $this->generateOrderId($data);
            if ($generatedOrderIdData['success']) {
                if (isset($generatedOrderIdData['savedData'])) {
                    return ['success' => true, 'data' => $generatedOrderIdData['savedData']];
                }

                $this->sentData = $data;
                $marketPlaceCurrency = $data['marketplace_currency'];
                $this->orderId = $generatedOrderIdData['data'];
                $this->sentData['cif_order_id'] = $data['cif_order_id'] ?? $this->orderId;
                $this->setOrderItemType();
                $itemResponse = $this->createOrderItem();
                if ($itemResponse['success']) {
                    $orderWrapper = $this->sentData;
                    unset($orderWrapper['items']);
                    foreach ($orderWrapper as $attributeKey => $attributeValue) {
                        if (is_array($attributeValue)) {
                            if (isset($attributeValue[0])) {
                                foreach ($attributeValue as $attributeValueName => $value) {
                                    if (isset($value['price'])) {
                                        $this->sentData[$attributeKey][$attributeValueName]['marketplace_price'] = $value['price'];
                                        $this->sentData[$attributeKey][$attributeValueName]['price'] = $this->getConnectorPrice($marketPlaceCurrency, $value['price']);
                                    }
                                }
                            } else {
                                if (isset($attributeValue['price'])) {
                                    $this->sentData[$attributeKey]['marketplace_price'] = $attributeValue['price'];
                                    $this->sentData[$attributeKey]['price'] = $this->getConnectorPrice($marketPlaceCurrency, $attributeValue['price']);
                                }
                            }
                        }
                    }

                    $id = new \MongoDB\BSON\ObjectId($this->orderId);
                    $orderUpdate = $this->update(['_id' => $id, 'object_type' => $this->schemaObjectType], $this->sentData);
                    if ($orderUpdate['success']) {
                        return ['success' => true, 'data' => $orderUpdate['data']];
                    }
                    return ['success' => false, 'message' => $orderUpdate['message']];
                }
                return ['success' => false, 'message' => $itemResponse['message']];
            }
            return ['success' => false, "message" => $generatedOrderIdData['message'], "incoming_data" => $data];
        }
        return ['success' => false, 'message' => "Order can't create without source marketplace id"];
    }

    public function update(array $filter, array $data): array
    {
        $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
        $collection = $mongo->getCollectionForTable('order_container');
        $collection->findOneAndUpdate($filter, ['$set' => $data], ["returnOriginal" => true]);
        $getData = $collection->findOne($filter, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        /**
         * if enabled from config then encrypt the mentioned keys in config
         */
        if ($this->di->getConfig()->get('pii_data_encryption_manager_status')) {
            $piiManager = $this->di->getObjectManager()->get(\App\Connector\Components\PIIDataManager::class);
            $piiManager->encryptDecrypt($getData, [], false);
        }
        if (empty($getData)) {
            return ['success' => false, 'message' => 'updated filter data not found'];
        }
        return ['success' => true, 'data' => $getData];
    }

    public function getAll(array $params): array
    {

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $collection = $mongo->getCollection('order_container');
        $userId = $this->di->getUser()->id;
        $conditionalQuery = [];
        if (isset($params['filter']) || isset($params['search'])) {
            $conditionalQuery = self::search($params);
        }

        if (isset($params['or_filter'])) {
            $conditionalQuery['$or'] = array_map('self::search', $params['or_filter']);
        }

        $limit = $params['count'] ?? self::$defaultPagination;
        $page = $params['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;

        $aggregation = [];

        $conditionalQuery['user_id'] = $userId;
        if (!isset($conditionalQuery['status'])) {
            $conditionalQuery['status'] = ['$ne' => "archived"];
        }

        if (isset($params['filter']['total.price'])) {
            $aggregation[] = ['$addFields' => ['converted_price' => ['$toDouble' => '$total.price']]];
            $conditionalQuery['converted_price'] = $conditionalQuery['total.price'];
            unset($conditionalQuery['total.price']);
        }

        if (!empty($conditionalQuery)) {
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        if (!empty($params['unwind'])) {
            $aggregation[] = ['$unwind' => '$' . $params['unwind']];
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        $countAggregation = $aggregation;
        $countAggregation[] = [
            '$count' => 'count'
        ];
        try {
            $totalRows = $collection->aggregate($countAggregation, $options);

            $sortBy = $params['sort']['by'] ?? '_id';
            $sortOrder = isset($params['sort']['order']) && is_numeric($params['sort']['order'])
                ? intval($params['sort']['order']) : -1;

            $aggregation[] = [
                '$sort' => [
                    $sortBy => $sortOrder
                ]
            ];
            $aggregation[] = ['$skip' => (int)$offset];
            $aggregation[] = ['$limit' => (int)$limit];

            if (!empty($params['projection'])) {
                $aggregation[] = ['$project' => array_map(function($value) {
                    return is_numeric($value) ? intval($value) : $value;
                }, $params['projection'])];
            }

            $rows = $collection->aggregate($aggregation, $options)->toArray();
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $responseData = [];
        $responseData['success'] = true;
        $responseData['data']['rows'] = $rows;
        $totalRows = $totalRows->toArray();

        $totalRows = $totalRows[0]['count'] ?? 0;
        $responseData['data']['count'] = $totalRows;
        if (isset($count)) {
            $responseData['data']['mainCount'] = $count;
        }

        return $responseData;
    }

    public function get(array $params): array
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $collection = $mongo->getCollection('order_container');

        if (!isset($params['cif_order_id'], $params['marketplace_shop_id'], $params['marketplace'])) {
            return ['success' => false, 'message' => 'Required params order_id, marketplace_shop_id, marketplace missing '];
        }

        $filter = [
            'marketplace'  => $params['marketplace'],
            'marketplace_shop_id'  => $params['marketplace_shop_id'],
            'cif_order_id'  => $params['cif_order_id']
        ];
        if (isset($params['user_id'])) {
            $filter['user_id'] = $params['user_id'];
        }
        else {
            $filter['user_id'] = $this->di->getUser()->id;
        }

        if (isset($params['object_type'])) {
            $filter['object_type'] = $params['object_type'];
        }

        $orderData = $collection->findOne($filter, $options);
        if (!empty($orderData) && count($orderData) > 0) {
            return ['success' => true, 'data' => $orderData];
        }
        return ['success' => false, 'message' => 'No order with this id found'];
    }

    public function getCount(array $params): array
    {
        $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollection(self::ORDER_CONTAINER);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $userId = $this->di->getUser()->id;
        $conditionalQuery = [];
        $conditionalQuery['user_id'] = $userId;
        if (isset($params['filter']) || isset($params['search'])) {
            $conditionalQuery = array_merge($conditionalQuery, self::search($params));
        }

        if (isset($params['or_filter'])) {
            $conditionalQuery['$or'] = array_map('self::search', $params['or_filter']);
        }

        if (!isset($conditionalQuery['status'])) {
            $conditionalQuery['status'] = ['$ne' => "archived"];
        }

        $aggregation = [];
        if (!empty($conditionalQuery)) {
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        if (!empty($params['unwind'])) {
            $aggregation[] = ['$unwind' => '$' . $params['unwind']];
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        $aggregation[] = [
            '$count' => 'count'
        ];
        $documentCount = $collection->aggregate($aggregation, $options)->toArray();
        $documentCount = $documentCount[0]['count'] ?? 0;
        $responseData['success'] = true;
        $responseData['data']['count'] = $documentCount;
        return $responseData;
    }

    /**
     * @return mixed[]
     */
    public static function search(array $filterParams = []): array
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);
                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    $conditions[$key] = self::checkInteger($key, $value[self::IS_EQUAL_TO]);
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[$key] =  ['$ne' => self::checkInteger($key, trim(addslashes($value[self::IS_NOT_EQUAL_TO])))];
                } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' =>  self::checkInteger($key, trim(addslashes($value[self::IS_CONTAINS]))),
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^((?!" . self::checkInteger($key, trim(addslashes($value[self::IS_NOT_CONTAINS]))) . ").)*$",
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::START_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^" . self::checkInteger($key, trim(addslashes($value[self::START_FROM]))),
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::END_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => self::checkInteger($key, trim(addslashes($value[self::END_FROM]))) . "$",
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::RANGE, $value)) {
                    if (trim($value[self::RANGE]['from']) && !trim($value[self::RANGE]['to'])) {
                        $conditions[$key] =  ['$gte' => self::checkInteger($key, trim($value[self::RANGE]['from']))];
                    } elseif (
                        trim($value[self::RANGE]['to']) &&
                        !trim($value[self::RANGE]['from'])
                    ) {
                        $conditions[$key] =  ['$lte' => self::checkInteger($key, trim($value[self::RANGE]['to']))];
                    } else {
                        $conditions[$key] =  [
                            '$gte' => self::checkInteger($key, trim($value[self::RANGE]['from'])),
                            '$lte' => self::checkInteger($key, trim($value[self::RANGE]['to']))
                        ];
                    }
                } elseif (array_key_exists(self::IS_GREATER_THAN, $value)) {
                    if (is_numeric(trim($value[self::IS_GREATER_THAN]))) {
                        $conditions[$key] = ['$gte' => self::checkInteger($key, trim($value[self::IS_GREATER_THAN]))];
                    }
                } elseif (array_key_exists(self::IS_LESS_THAN, $value)) {
                    if (is_numeric(trim($value[self::IS_LESS_THAN]))) {
                        $conditions[$key] = ['$lte' => self::checkInteger($key, trim($value[self::IS_LESS_THAN]))];
                    }
                } elseif (array_key_exists(self::IS_EXISTS, $value)) {
                    if (is_bool($value[self::IS_EXISTS] = filter_var(trim($value[self::IS_EXISTS]), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE))) {
                        $conditions[$key] = ['$exists' => $value[self::IS_EXISTS]];
                    }
                } elseif (array_key_exists(self::CONTAIN_IN_ARRAY, $value)) {
                    $value[self::CONTAIN_IN_ARRAY] = explode(",", $value[self::CONTAIN_IN_ARRAY]);
                    $conditions[$key] = [
                        '$in' => $value[self::CONTAIN_IN_ARRAY]
                    ];
                } elseif (array_key_exists(self::NOT_CONTAINS_IN_ARRAY, $value)) {
                    $value[self::NOT_CONTAINS_IN_ARRAY] = explode(",", $value[self::NOT_CONTAINS_IN_ARRAY]);
                    $conditions[$key] = [
                        '$nin' => $value[self::NOT_CONTAINS_IN_ARRAY]
                    ];
                }
            }
        }

        if (isset($filterParams['search'])) {
            $conditions['$text'] = ['$search' => self::checkInteger($key, trim(addslashes($filterParams['search'])))];
        }

        return $conditions;
    }

    public static function checkInteger($key, $value): float|bool|string
    {
        if ($key == 'total.price') {
            $value = trim($value);
            return (float)$value;
        }

        if (is_bool($value)) {
            return $value;
        }

        return trim($value);
    }

    /**
     * @return mixed[]
     */
    public function bulkCount(array $filters = []): array
    {
        $conditionalQuery = [];
        if (!isset($filters['filters'])) {
            return $conditionalQuery;
        }

        foreach ($filters['filters'] as $key => $filter) {
            $condition = self::search($filter);
            if (!empty($condition)) {
                $conditions = [];
                $conditions[] = ['$match' => $condition];
                $conditions[] = ['$count' => 'count'];
                $conditionalQuery[$key] = $conditions;
            }
        }

        return $conditionalQuery;
    }

    public function getCountsByKeys(array $data): array
    {
        if (empty($data['filters'])) {
            return ['success' => false, 'message' => 'No filter keys found'];
        }

        $response = [];
        foreach ($data['filters'] as $key => $filter) {
            $getCountResponse = $this->getCount($filter);
            if ($getCountResponse['success']) {
                $response[$key] = $getCountResponse['data']['count'] ?? 0;
            }
        }

        if(empty($response)){
            return ['success' => false, 'message' => 'No data found'];
        }

        return ['success' => true, 'data' => $response];
    }

    public function archiveOrder(array $params): array
    {
        if (!isset($params['cif_order_id'], $params['marketplace_shop_id'])) {
            return ['success' => false, 'message' => 'Required params order_id, marketplace_shop_id missing '];
        }

        $hasCompletedLifeCycleResponse = $this->hasCompletedLifeCycle(
            $params['cif_order_id'],
            $params['marketplace_shop_id']
        );
        if (!$hasCompletedLifeCycleResponse['success']) {
            $updatedErrorMessage = 'Unable to archive the order(s). ' . $hasCompletedLifeCycleResponse['message'] ?? '';
            $hasCompletedLifeCycleResponse['message'] = $updatedErrorMessage;
            return $hasCompletedLifeCycleResponse;
        }

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('order_container');
        $userId = $this->di->getUser()->id;
        $filter = [
            'user_id' => $userId,
            'marketplace_shop_id' => $params['marketplace_shop_id'],
            'cif_order_id' => ['$in' => $params['cif_order_id']]
        ];
        $response = $collection->updateMany($filter, ['$set' => ["status" => "archived"]]);
        if ($response->getModifiedCount()) {
            return ['success' => true, 'message' => "Order archived successfully"];
        }
        return ['success' => false, 'message' => "Something went wrong , order not archived"];
    }

    public function hasCompletedLifeCycle($cifOrderId, $marketplaceShopId): array
    {
        $filter = [
            'user_id' => $this->di->getUser()->id,
            'object_type' => 'source_order',
            'marketplace_shop_id' => $marketplaceShopId,
            'cif_order_id' => ['$in' => $cifOrderId]
        ];

        $sourceOrders = $this->getByField($filter);

        $hasActiveOrders = false;
        $activeOrdersId = [];
        foreach ($sourceOrders as $sourceOrder) {
            if (
                (isset($sourceOrder['last_delivery_date']) && $sourceOrder['last_delivery_date'] >= date('c'))
                && ($sourceOrder['status'] != 'Cancelled')
            ) {
                $hasActiveOrders = true;
                $activeOrdersId[] = $sourceOrder['marketplace_reference_id'] ?? null;
            }
        }

        if ($hasActiveOrders) {
            $errorMsg = (count($sourceOrders) > 1)
                ? 'One or more of the orders have not completed their lifecycle'
                : 'This order has not completed its lifecycle';
            return ['success' => false, 'message' => $errorMsg, 'data' => $activeOrdersId];
        }

        return ['success' => true, 'message' => ''];
    }

    public function getByField(array $params): array
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $orderCollection = $mongo->getCollectionForTable('order_container');
        $orders = $orderCollection->find($params, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
        return $orders;
    }

    public function prepareForDb(array $data): array
    {
    }

    private function setOrderItemType(): string
    {
        $this->orderItemType = $this->schemaObjectType . '_items';
        return $this->orderItemType;
        // source_order_items
        // $functionName = $this->schemaObjectType;
        // $functionName = preg_replace_callback('/(?:^|_)([a-z])/', function($ite){
        //     return strtoupper($ite[1]);
        // }, $functionName);
        // $functionName = 'create'.$functionName.'Item';
        // if(method_exists($this,$functionName)){
        //     return ['success'=>true,'function'=>$functionName];
        // } else {
        //     return ['success'=>false,'message'=>"schema type method not exist"];
        // }

    }

    public function generateOrderId(array $data): array
    {
        $insertData = [
            "user_id" => $this->di->getUser()->id,
            "object_type" => $this->schemaObjectType,
            "marketplace" => $data['marketplace'],
            "marketplace_shop_id" => $data['marketplace_shop_id'],
            "marketplace_reference_id" => $data['marketplace_reference_id'],
            "currency" => "USD"
        ];

        $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
        $collection = $mongo->getCollectionForTable('order_container');
        $orderData = $collection->findOne($insertData, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if ($orderData) {
            if (isset($orderData['status']) && ($orderData['status'] == OrderStatus::failed || $orderData['status'] == OrderStatus::pending || $orderData['status'] == OrderStatus::partially_created)) {
                return ['success' => true, 'savedData' => $orderData];
            }
            return ['success' => false, 'message' => 'dublicate order attempt'];
        }
        // if (!isset($data['fulfilled_by']) || $data['fulfilled_by'] == 'merchant') {
        //     if ($this->schemaObjectType == 'source_order') {
        //         $eventsManager = $this->di->getEventsManager();
        //         $eventsManager->fire('application:afterOrderFetch', $this, $insertData);
        //     } elseif ($this->schemaObjectType == 'target_order') {
        //         if ($this->isSourceOrderFbo($data)) {
        //             $eventsManager = $this->di->getEventsManager();
        //             $eventsManager->fire('application:afterOrderFetch', $this, $insertData);
        //         }
        //     }
        // } else {
        //     if ($this->schemaObjectType == 'target_order') {
        //         $eventsManager = $this->di->getEventsManager();
        //         $eventsManager->fire('application:afterOrderFetch', $this, $insertData);
        //     }
        // }

        $this->manageAfterOrderFetch($data, $insertData);

        $insertData['created_at'] = (string)$this->getMongoDate();
        $insert = $collection->insertOne($insertData);
        return ["success" => true, "data" => (string)$insert->getInsertedId()];
    }

    public function manageAfterOrderFetch(array $data, array &$insertData): void
    {
        $config = $this->di->getConfig();
        $afterOrderFetchForFboWhenSourceCreated = $config->path('order_credit_events.fbo.fire_order_fetch_on_source_creation', false);
        $fulfilledByOther = ($data['fulfilled_by'] ?? '') === 'other';

        switch ($this->schemaObjectType) {
            case 'source_order':
                $this->handleSourceOrder($data, $insertData, $fulfilledByOther, $afterOrderFetchForFboWhenSourceCreated);
                break;

            case 'target_order':
                $this->handleTargetOrder($data, $fulfilledByOther, $afterOrderFetchForFboWhenSourceCreated);
                break;

            default:
                // No action needed
                break;
        }
    }

    private function handleSourceOrder(array $data, array &$insertData, bool $fulfilledByOther, bool $afterOrderFetchForFboWhenSourceCreated): void
    {
        // Return early if the order is fulfilled by other and afterOrderFetchForFboWhenSourceCreated is false
        if ($fulfilledByOther && !$afterOrderFetchForFboWhenSourceCreated) {
            return;
        }

        $shouldFireEvent = !$fulfilledByOther;  // Fire event if fulfilled by merchant

        // Check for linked items if order is fulfilled by other
        if ($fulfilledByOther) {
            $countCreditsWhenAnyItemLinked = $this->di->getConfig()->path('order_credit_events.fbo.count_credits_when_any_item_linked', true);
            $hasLinkedItems = $countCreditsWhenAnyItemLinked && $this->hasLinkedItemsOrValidateForCreate($data, $insertData);

            // Fire event if there are linked items or if counting credits without linked items is allowed
            $shouldFireEvent = $hasLinkedItems || !$countCreditsWhenAnyItemLinked;
        }

        if ($shouldFireEvent) {
            $this->fireAfterOrderFetchEvent($data);
        }
    }

    private function handleTargetOrder(array $data, bool $fulfilledByOther, bool $afterOrderFetchForFboWhenSourceCreated): void
    {
        /*
         * Fire the event if the order is fulfilled by other
         * and the config for fire_order_fetch_on_source_creation is false.
         * This implies that credit counting is set for creation of source order.
         */
        if ($fulfilledByOther && !$afterOrderFetchForFboWhenSourceCreated) {
            $this->fireAfterOrderFetchEvent($data);
            return;
        }

        /*
         * Fire the event if 'fulfilled_by' is not set, the config
         * for fire_order_fetch_on_source_creation is false,
         * and the source order is fulfilled by other (FBO).
         * This scenario handles cases where the source order was FBO,
         * credit counting is set for target order creation, and
         * 'fulfilled_by' is not defined in the target order.
         */
        if (!isset($data['fulfilled_by']) && !$afterOrderFetchForFboWhenSourceCreated && $this->isSourceOrderFbo($data)) {
            $this->fireAfterOrderFetchEvent($data);
            return;
        }

        /*
        * Fire the event if 'fulfilled_by' is not set.
        * This condition checks for the presence of the 'linked_item_verification_failed' key
        * in the source order. It addresses scenarios where credits were not deducted from
        * the source order due to specific conditions, such as when items were not linked
        * and the configuration for 'count_credits_when_any_item_linked' is set to true.
        */
        if (!isset($data['fulfilled_by'])) {
            $this->checkSourceOrderAndFireEvent($data);
        }
    }

    private function checkSourceOrderAndFireEvent(array $data): void
    {
        $query = [
            'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
            'object_type' => 'source_order',
            'cif_order_id' => $data['cif_order_id'] ?? null,
        ];
        $sourceOrder = $this->getByField($query);
        $sourceOrder = $sourceOrder[0] ?? [];

        if ($sourceOrder['linked_item_verification_failed'] ?? false) {
            $this->fireAfterOrderFetchEvent($data);
        }
    }

    private function fireAfterOrderFetchEvent(array $data): void
    {
        $this->di->getEventsManager()->fire('application:afterOrderFetch', $this, $data);
    }

    public function hasLinkedItemsOrValidateForCreate(array $orderData, array &$insertData): bool
    {
        $type = $orderData['type'] ?? 'sources';
        if (!$this->isValidateForCreateOnTargets($orderData, $type)) {
            $insertData['linked_item_verification_failed'] = true;
            return false;
        }
        return true;
    }

    public function isValidateForCreateOnTargets(array $orderData, string $type = 'sources'): bool
    {
        $shops = $this->di->getUser()->shops;
        $sourceShop = current(array_filter($shops, fn($shop) => $shop['_id'] === $orderData['marketplace_shop_id']));

        $linkedShops = $sourceShop[$type] ?? [];
        if (empty($linkedShops)) {
            return false;
        }

        return $this->validateLinkedShops($linkedShops, $orderData);
    }

    private function validateLinkedShops(array $linkedShops, array $orderData): bool
    {
        $orderConnectorComponent = $this->di->getObjectManager()->get(\App\Connector\Components\Order\Order::class);
        foreach ($linkedShops as $linkedShop) {
            $orderSettings = $orderConnectorComponent->checkOrderSyncEnable($linkedShop['shop_id'], $orderData['marketplace_shop_id'])['data'] ?? [];
            $requestData = [
                'shop_id' => $linkedShop['shop_id'],
                'target_shop_id' => $orderData['marketplace_shop_id'],
                'order_settings' => $orderSettings,
                'order' => $orderData
            ];

            if ($this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class, [], $linkedShop['code'])->validateForCreate($requestData)['success']) {
                return true;
            }
        }
        return false;
    }

    public function isSourceOrderFbo($data): bool
    {
        $query = [
            'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
            'object_type' => 'source_order',
            'cif_order_id' => $data['cif_order_id'] ?? null,
        ];
        $sourceOrder = $this->getByField($query);
        $sourceOrder = $sourceOrder[0] ?? [];
        return isset($sourceOrder['fulfilled_by']) && $sourceOrder['fulfilled_by'] == 'other' ? true : false;
    }

    public function getMongoDate(): string
    {
        return date('c');
        //return new \MongoDB\BSON\UTCDateTime();
    }


    public function completeOrder(array $data): array
    {
    }

    public function createOrderItem(): array
    {
        $savedItem = [];
        $data = $this->sentData;
        $marketPlaceCurrency = $data['marketplace_currency'];
        $subTotalMarketplacePrice = 0;
        $subTotalConnectorPrice = 0;
        $totalMarketplacePrice = 0;
        $totalConnectorePrice = 0;

        if (isset($data['items'])) {
            foreach ($data['items'] as $itemKey => $item) {
                foreach ($item as $itemAttributeName => $itemAttributeValue) {
                    if (is_array($itemAttributeValue)) {
                        if (isset($itemAttributeValue[0])) {
                            foreach ($itemAttributeValue as $itemIte => $itemData) {
                                if (isset($itemData['price'])) {
                                    $this->sentData['items'][$itemKey][$itemAttributeName][$itemIte]['marketplace_price'] =  $itemData['price'];
                                    $this->sentData['items'][$itemKey][$itemAttributeName][$itemIte]['price'] = $this->getConnectorPrice($marketPlaceCurrency, $itemData['price']);
                                }
                            }
                        } else {
                            if (isset($itemAttributeValue['price'])) {
                                $this->sentData['items'][$itemKey][$itemAttributeName]['marketplace_price'] = $itemAttributeValue['price'];
                                $this->sentData['items'][$itemKey][$itemAttributeName]['price'] = $this->getConnectorPrice($marketPlaceCurrency, $itemAttributeValue['price']);
                            }
                        }
                    } elseif ($itemAttributeName == 'price') {
                        $this->sentData['items'][$itemKey]['marketplace_price'] =  $itemAttributeValue;
                        $this->sentData['items'][$itemKey]['price'] = $this->getConnectorPrice($marketPlaceCurrency, $itemAttributeValue);
                    }
                }

                $checkData = [
                    "user_id" => $this->di->getUser()->id,
                    "object_type" => $this->orderItemType,
                    "order_id" => $this->orderId,
                    "marketplace_item_id" => $item['marketplace_item_id'],
                ];

                $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
                $collection = $mongo->getCollectionForTable('order_container');
                $orderData = $collection->findOne($checkData, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
                if (empty($orderData)) {
                    $this->sentData['items'][$itemKey]['schema_version'] = self::SCHEMA_VERSION;
                    $this->sentData['items'][$itemKey]['cif_order_id'] = $this->sentData['cif_order_id'];
                    $this->sentData['items'][$itemKey] = array_merge($this->sentData['items'][$itemKey], $checkData);
                    $this->sentData['items'][$itemKey]['created_at'] = (string)$this->getMongoDate();
                    $this->sentData['items'][$itemKey]['updated_at'] = (string)$this->getMongoDate();
                    $insert = $collection->insertOne($this->sentData['items'][$itemKey]);
                    $savedItem[$item['marketplace_item_id']] = (string)$insert->getInsertedId();
                    $this->sentData['items'][$itemKey]['id'] = (string)$insert->getInsertedId();
                    if ($this->schemaObjectType == 'source_order') {
                        $this->sentData['items'][$itemKey]['item_link_id'] = (string)$insert->getInsertedId();
                    }
                } else {
                    $this->sentData['items'][$itemKey]['id'] = (string)$orderData["_id"];
                    $savedItem[$item['marketplace_item_id']] = (string)$orderData["_id"];
                }

                $quantity = $this->sentData['items'][$itemKey]['qty'];
                if (isset($item['tax'])) {
                    $subTotalMarketplacePrice = ($subTotalMarketplacePrice + ($this->sentData['items'][$itemKey]['marketplace_price'] * $quantity));
                    $subTotalConnectorPrice =  ($subTotalConnectorPrice + ($this->sentData['items'][$itemKey]['price'] * $quantity));
                    if (isset($data['taxes_included']) && $data['taxes_included']) {
                        $totalMarketplacePrice = $subTotalMarketplacePrice;
                        $totalConnectorePrice = $subTotalConnectorPrice;
                    } else {
                        $totalMarketplacePrice = $totalMarketplacePrice + ($this->sentData['items'][$itemKey]['marketplace_price'] * $quantity) + $this->sentData['items'][$itemKey]['tax']['marketplace_price'];
                        $totalConnectorePrice = $totalConnectorePrice + ($this->sentData['items'][$itemKey]['price'] * $quantity) + $this->sentData['items'][$itemKey]['tax']['price'];
                    }
                } else {
                    $subTotalMarketplacePrice = ($subTotalMarketplacePrice + ($this->sentData['items'][$itemKey]['marketplace_price'] * $quantity));
                    $subTotalConnectorPrice =  ($subTotalConnectorPrice + ($this->sentData['items'][$itemKey]['price'] * $quantity));

                    $totalMarketplacePrice = $totalMarketplacePrice + ($this->sentData['items'][$itemKey]['marketplace_price'] * $quantity);
                    $totalConnectorePrice = $totalConnectorePrice + ($this->sentData['items'][$itemKey]['price'] * $quantity);
                }
            }

            if (isset($this->sentData['sub_total'])) {
                $sourceSubTotal = $this->sentData['sub_total'];
                $this->sentData['sub_total'] = [
                    'price' => $this->getConnectorPrice($marketPlaceCurrency, $sourceSubTotal),
                    'marketplace_price' => $sourceSubTotal
                ];
            } else {
                $this->sentData['sub_total'] = [
                    'price' => $subTotalConnectorPrice,
                    'marketplace_price' => $subTotalMarketplacePrice
                ];
            }

            if (isset($this->sentData['total'])) {
                $sourceTotal = $this->sentData['total'];
                $this->sentData['total'] = [
                    'price' => $this->getConnectorPrice($marketPlaceCurrency, $sourceTotal),
                    'marketplace_price' => $sourceTotal
                ];
            } else {
                if (isset($data['shipping_charge'])) {
                    $totalMarketplacePrice = $totalMarketplacePrice + $data['shipping_charge']['marketplace_price'];
                    $totalConnectorePrice = $totalConnectorePrice + $data['shipping_charge']['price'];

                    $this->sentData['total'] = [
                        'price' => $totalConnectorePrice,
                        'marketplace_price' => $totalMarketplacePrice
                    ];
                } else {
                    $this->sentData['total'] = [
                        'price' => $totalConnectorePrice,
                        'marketplace_price' => $totalMarketplacePrice
                    ];
                }
            }

            return ['success' => true, 'data' => $savedItem];
        }
        return ['success' => false, 'message' => 'items not found'];
    }

    public function getConnectorPrice($marketplaceCurrency, $price): string
    {
        if (is_null($this->currencyObj)) {
            $this->currencyObj = $this->di->getObjectManager()->get(\App\Connector\Contracts\Currency\CurrencyInterface::class);
        }

        //    var_dump($marketplaceCurrency,$price);die("cjvhjvh");
        return  $this->currencyObj->convert($marketplaceCurrency, $price);
    }

    public function validateForCreate(array $data): array
    {
    }

    /**
     * Fires a change event with the specified event name and data.
     *
     * @param mixed $event The event name to fire.
     * @param array $dataToPass The data to pass to the event handler.
     */
    public function fireChangeEvent(
        $event = self::AFTER_TARGET_ORDER_CREATED_EVENT,
        $dataToPass = []
    ): void {
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire(
            "application:{$event}",
            $this,
            $dataToPass
        );
    }
}
