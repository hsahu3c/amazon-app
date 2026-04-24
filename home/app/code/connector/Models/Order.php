<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use Phalcon\Mvc\Model\Message;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\InclusionIn;
use Phalcon\Validation\Validator\InvalidValue;

class Order extends BaseMongo
{
    protected $table = 'order_container';

    const IS_EQUAL_TO = 1;

    const IS_NOT_EQUAL_TO = 2;

    const IS_CONTAINS = 3;

    const IS_NOT_CONTAINS = 4;

    const START_FROM = 5;

    const END_FROM = 6;

    const RANGE = 7;

    const STATUS_CODES = [
        0 => 'Pending',
        1 => 'Processing',
        2 => 'Completed',
        3 => 'Cancelled'
    ];

    public static $defaultPagination = 100;

    const PRODUCT_TYPE_SIMPLE = 'simple', PRODUCT_TYPE_VARIANT = 'variant';

    protected $currentTransaction=false;

    public function getCurrentTransaction()
    {
        if (!$this->currentTransaction) {
            return $this->di->getTransactionManager()->get($this->getWriteConnectionService());
        }

        return $this->currentTransaction;
    }

    /*
    public function initialize()
    {
        $this->sqlConfig = $this->di->getObjectManager()->get('\App\Connector\Components\Data');
        $token = $this->di->getRegistry()->getDecodedToken();
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }
*/
    /*$orderData = [
            'merchant_id'=>'1',
            'source_order_id' => '1',
            'target_order_id' => '',
            'order_source' => 'shopify',
            'qty' =>'1',
            'total_price' => '1',
            'subtotal_price' => '1',
            'total_weight' =>'1',
            'total_tax' => '0',
            'taxes_included' => '1',
            'currency' => 'USD',
            'total_discounts' => '0',
            'total_line_items_price' => '1',//skipped
            'total_price_usd' => '1',
            'client_details'=>[
                'contact_email' => '',
                'browser_ip' => '',
            ],
            'discount_codes' => [
                [   'code'=>'',
                    'amount' => '0',
                    'type' => 'percentage/fixed'
                ]
            ],

            'tax_lines' => [
                [   'title'=>'',
                    'price' => '',
                    'rate' => ''
                ]
            ],
            'line_items' => [
                [
                    'order_id' => '',
                    'source_item_id' => '',
                    'sku' => 'sku',

                    'qty' => '2',
                    'price' => '',
                    'title' => 'name',
                    'fulfillment_service'=>'shopify',
                    'warehouse' => '1',
                    'weight' => '',
                    'total_discount' => '',
                    'product_id' => '1',
                    'fulfillment_status' =>0,
                    'tax_lines' => [
                        [   'title'=>'',
                            'price' => '',
                            'rate' => ''
                        ]
                    ],
                ],
            ]
        ];*/


    public function createOrder($orderData)
    {
        try {
            //$orderData['client_details'] = isset($orderData['client_details'])?(is_array($orderData['client_details'])?json_encode($orderData['client_details']):'{}'):'{}';
            //$orderData['extra_data'] = isset($orderData['extra_data'])?(is_array($orderData['extra_data'])?json_encode($orderData['extra_data']):'{}'):'{}';
            $orderData['status'] ??= 0;
            /* Checking order duplicacy */
            if (isset($orderData['source_order_id'])) {
                $exists = $this->loadByField([
                    "source_order_id" => $orderData['source_order_id'],
//                    "merchant_id" => $orderData['merchant_id']
                ]);
                if ($exists) {
                    return ['success' => false, 'code' => 'order_already_exist', 'message' => 'Order already exist'];
                }
                $orderData['_id'] = (string)$this->getCounter('order_id', $orderData['merchant_id']);
                $orderData['imported_at'] = date("Y-m-d H:i:s");
            }

            $this->setData($orderData);
            if ($this->save()) {
                return ['success' => true, 'message' => 'Order created successfully'];
            }
            $messages = $this->getMessages();
            $errors = '';
            foreach ($messages as $message) {
                $errors .=  ' ' . $message->getMessage();
            }

            return ['success' => false, 'message' => $errors];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function importOrderFromMarketpalce($rawBody)
    {
        if(isset($rawBody['code'])){ 
            $code=$rawBody['code'];
            if ($model = $this->di->getConfig()->connectors->get($code)->get('source_model')) {
                $data = $this->di->getObjectManager()->get($model)->fetchOrder($rawBody);
                return $data;
            }
        }

    }

    public function uploadOrder($orderDetails)
    {
        $orderId = $orderDetails['id'];
        $orderData = Order::findFirst(["id='{$orderId}'"]);
        $orderItemsData = \App\Connector\Models\Order\Item::find(["order_id='{$orderId}'"]);
        if ($orderData && $orderItemsData &&
            count($orderItemsData) > 0) {
            $orderData = $orderData->toArray();
            $orderItemsData = $orderItemsData->toArray();
            if ($orderData['order_target'] != 'connector' &&
                $orderData['target_order_id'] == null) {
                $orderData['line_items'] = $orderItemsData;
                $orderData['client_details'] = json_decode($orderData['client_details'], true);
                $response = $this->di->getObjectManager()->get('App\\' . ucfirst($orderData['order_target']) . '\\Components\OrderHelper')->uploadOrder($orderData);
                if ($response['success'] == true) {
                    $orderModel = Order::findFirst(["id='{$orderId}'"]);
                    $orderModel->target_order_id = $response['data']['id'];
                    $orderUpdateStatus = $orderModel->save();
                    if ($orderUpdateStatus) {
                        return ['success' => true, 'message' => 'Order Uploaded Successfully'];
                    }
                    $errors = '';
                    foreach ($orderModel->getMessages() as $value) {
                        $errors .= ' ' . $value;
                    }

                    return ['success' => false, 'message' => $errors];
                }
                return $response;
            }
            return ['success' => false, 'message' => 'Order already synced with order target'];
        }
        return ['success' => false, 'message' => 'No order with this id found'];
    }

    public function getOrderById($orderDetail)
    {
        $userId = $this->di->getUser()->id;
        $orderData = $this->loadByField(['_id'=>(int)$orderDetail['_id'], 'user_id' => $userId]);
        if ($orderData &&
            count($orderData) > 0) {
            return ['success' => true, 'message' => '','data'=>$orderData];
        }
        return ['success' => false, 'message' => 'No order with this id found'];
    }

    public function saveItems($items): void
    {
        foreach ($items as $item) {
            $item['merchant_id'] = $this->merchant_id;
            $item['order_id'] = $this->id;
            $item['fulfillment_status'] ??= 0;

            $newItem = new Order\Item();
            $newItem->setTransaction($this->getCurrentTransaction());
            if (!$newItem->save($item)) {
                $messages = $newItem->getMessages();
                foreach ($messages as $message) {
                    $transaction->rollback(
                        $message->getMessage()
                    );
                }
            }
        }
    }

    /*
        public static function search($filterParams = [], $fullTextSearchColumns = null)
        {
            $conditions = [];
            if (isset($filterParams['filter'])) {
                foreach ($filterParams['filter'] as $key => $value) {
                    $key = trim($key);
                    if (array_key_exists(Order::IS_EQUAL_TO, $value)) {
                        $conditions[] = "`" . $key . "` = '" . trim(addslashes($value[Order::IS_EQUAL_TO])) . "'";
                    } elseif (array_key_exists(Order::IS_NOT_EQUAL_TO, $value)) {
                        $conditions[] = "`" . $key . "` != '" . trim(addslashes($value[Order::IS_NOT_EQUAL_TO])) . "'";
                    } elseif (array_key_exists(Order::IS_CONTAINS, $value)) {
                        $conditions[] = "`" . $key . "` LIKE '%" . trim(addslashes($value[Order::IS_CONTAINS])) . "%'";
                    } elseif (array_key_exists(Order::IS_NOT_CONTAINS, $value)) {
                        $conditions[] = "`" . $key . "` NOT LIKE '%" . trim(addslashes($value[Order::IS_NOT_CONTAINS])) . "%'";
                    } elseif (array_key_exists(Order::START_FROM, $value)) {
                        $conditions[] = "`" . $key . "` LIKE '" . trim(addslashes($value[Order::START_FROM])) . "%'";
                    } elseif (array_key_exists(Order::END_FROM, $value)) {
                        $conditions[] = "`" . $key . "` LIKE '%" . trim(addslashes($value[Order::END_FROM])) . "'";
                    } elseif (array_key_exists(Order::RANGE, $value)) {
                        if (trim($value[Order::RANGE]['from']) && !trim($value[Order::RANGE]['to'])) {
                            $conditions[] = "`" . $key . "` >= '" . $value[Order::RANGE]['from'] . "'";
                        } elseif (trim($value[Order::RANGE]['to']) && !trim($value[Order::RANGE]['from'])) {
                            $conditions[] = "`" . $key . "` >= '" . $value[Order::RANGE]['to'] . "'";
                        } else {
                            $conditions[] = "`" . $key . "` between '" . $value[Order::RANGE]['from'] . "' AND '" . $value[Order::RANGE]['to'] . "'";
                        }
                    }
                }
            }
            if (isset($filterParams['search']) && $fullTextSearchColumns) {
                $conditions[] = "MATCH(" . $fullTextSearchColumns . ") AGAINST ('" . trim(addslashes($filterParams['search'])) . "' IN NATURAL LANGUAGE MODE)";
            }
            $conditionalQuery = "";
            if (is_array($conditions) && count($conditions)) {
                $conditionalQuery = implode(' AND ', $conditions);
            }
            return $conditionalQuery;
        }
    */

    /**
     * Get Orders list based on filter and search
     * @param $params
     * @return array
     */
    public function getOrders($params = [], $dataOnly = false)
    {
        $conditionalQuery = [];
        $getfulfullied = false;
        $getUnfulfullied = false;
        if (isset($params['filter']) || isset($params['search'])) {
            if (isset($params['filter']['qty'])) {
                foreach ($params['filter']['qty'] as $key => $value) {
                    $params['filter']['qty'][$key] = (int)$value;
                }
            }

            if (isset($params['filter']['status'])) {
                foreach ($params['filter']['status'] as $key => $value) {
                    if ($params['filter']['status'][$key] == 'inProgress') {
                        $getUnfulfullied = true;
                    }
                }
            }

            if (isset($params['filter']['sandbox'])) {
                foreach ($params['filter']['sandbox'] as $key => $value) {
                    $params['filter']['sandbox'][$key] = ($value == '1') ? true : false;
                }
            }

            if (isset($params['filter']['failed'])) {
                foreach ($params['filter']['failed'] as $key => $value) {
                    $params['filter']['target_order_id'][$key] = '';
                }

                unset($params['filter']['failed']);
            }

            if (isset($params['filter']['fulfillments'])) {
                if ($params['filter']['fulfillments'] == 1) {
                    $getfulfullied = true;
                } elseif ($params['filter']['fulfillments']== 0) {
                    $getUnfulfullied = true;
                }

                unset($params['filter']['fulfillments']);
            }

            $conditionalQuery = self::search($params);
        }

        if (!isset($params['activePage'])) {
            $params['activePage'] = 1;
        }

        $limit = $params['activePage'] * $params['count'];
        $offset = $limit - $params['count'];
        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $this->_baseMongo->getCollectionForTable("order_container");
//        $collection = $this->getCollection();
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $conditionalQuery['user_id'] = (string)$this->di->getUser()->id;
        if ($conditionalQuery &&
            count($conditionalQuery)) {
            if ($getfulfullied) {
                $conditionalQuery = [
                    '$and' => [
                        [
                            "fulfillments"=>[
                                '$exists'=>true
                            ]
                        ],
                        $conditionalQuery
                    ]
                ];
            } elseif ($getUnfulfullied) {
                $conditionalQuery = [
                    '$and' => [
                        [
                            "fulfillments" => [
                                '$exists' => false
                            ],
                            "target_order_id" => [
                                '$ne' => ''
                            ]
                        ],
                        $conditionalQuery
                    ]
                ];
            }
        }

        $aggregation[] = [
            '$match' => $conditionalQuery
        ];
        $aggregation[] = [
            '$sort' => [
                'placed_at' => -1
            ]
        ];
        $aggregation[] = [
            '$limit' => (int)$limit
        ];
        $aggregation[] = [
            '$skip' => (int)$offset
        ];

        $rows = $collection->aggregate($aggregation, $options);
        if ($dataOnly) {
            return $rows->toArray();
        }

        $countAggregation = [];
        if ($conditionalQuery &&
            count($conditionalQuery)) {
            $countAggregation[] = [
                '$match' => $conditionalQuery
            ];
        }

        $countAggregation[] = [
            '$count' => 'count'
        ];
        $totalRows = $collection->aggregate($countAggregation);
        $totalRows = $totalRows->toArray();

        $count = count($totalRows) ? iterator_to_array($totalRows[0])['count'] : 0;
        $responseData = [];
        $responseData['success'] = true;
        $responseData['data']['rows'] = $rows->toArray();
        $responseData['data']['count'] = $count;
        return $responseData;
    }

    public function updateOrderStatus($orderDetails)
    {
        $orderId = $orderDetails['id'];
        $orderStatus = $orderDetails['status'];
        $orderModel = Order::findFirst("id='{$orderId}'");
        $orderModel->status = array_search($orderStatus, Order::STATUS_CODES);
        $updateStatus = $orderModel->save();
        if ($updateStatus) {
            return ['success' => true, 'message' => 'Order status updated succesfully'];
        }
        $errors = '';
        foreach ($orderModel->getMessages() as $value) {
            $errors .= ' ' . $value;
        }

        return ['success' => false, 'message' => $errors];
    }

    public function fetchStatusCodeInData($data)
    {
        foreach ($data as $key => $value) {
            $data[$key]['status'] = Order::STATUS_CODES[$value['status']];
        }

        return $data;
    }

    public function getfulfillmentdetails($rawBody)
    {
        $shopDetails = \App\Shopify\Models\Shop\Details::findFirst(['id=5']);
        $shopDetails = $shopDetails->toArray();

        $client = $this->di->getObjectManager()->get('App\Shopify\Components\Helper')
            ->getShopifyClient($shopDetails['user_id'], $shopDetails['shop_url']);
        $service = new \Shopify\Service\OrderService($client);
//        $service->setResponseType('array');
        if ($rawBody['order_data']['target_order_id']!=='') {
            $fullfillment_data = $service->get($rawBody['target_order_id']);
            return $fullfillment_data;
        }
        return false;
    }
}
