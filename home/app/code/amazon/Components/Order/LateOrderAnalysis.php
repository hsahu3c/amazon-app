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
use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\ObjectId;
use Exception;

class LateOrderAnalysis extends Base
{
    private $mongo;
    private $orderContainer;
    private $lateSyncOrders;
    const OBJECT_TYPE = 'source_order';
    const INSERTION_CHUNK  = 500;
    const TIME_DIFFERENCE_IN_MINUTES = 15;
    const DELAY_UNIT = 'seconds';
    const DEFAULT_PAGE_LIMIT = 50;
    const ORDER_FETCH_CHUNK = 2000;
    const QUEUE_NAME = 'amazon_late_fetched_orders';
    const SEARCH_INDEX = 'order_stat';
    const PLAN_ACTIVATION_HOURS_THRESHOLD = 1;
    const ORDER_DATE_DIFFERENCE_MINUTES_THRESHOLD = 10;

    /**
     * Initilizing mongo container objects
     */
    public function init()
    {
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->orderContainer = $this->mongo->getCollectionForTable('order_container');
        $this->lateSyncOrders = $this->mongo->getCollection("late_sync_orders_container");
    }

    /**
     * Fetch delay orders from order_container and saving in late_sync_orders_container
     *
     * @param array $params
     * @return array
     */
    public function findAndSaveLateSyncOrders($params)
    {
        $this->init();
        try {
            if (isset($params['start_date'], $params['end_date'])) {
                // This pipeline will only save late FBM orders in late_sync_orders_container
                $searchPipeline = [
                    [
                        '$search' => [
                            'index' => self::SEARCH_INDEX,
                            'compound' => [
                                'must' => [
                                    [
                                        'equals' => [
                                            'value' => self::OBJECT_TYPE,
                                            'path' => 'object_type'
                                        ]
                                    ],
                                    [
                                        'equals' => [
                                            'value' => Helper::TARGET,
                                            'path' => 'marketplace'
                                        ]
                                    ],
                                    [
                                        'equals' => [
                                            'value' => 'merchant',
                                            'path' => 'fulfilled_by'
                                        ]
                                    ],
                                    [
                                        'range' => [
                                            'path' => 'created_at',
                                            'gte' => $params['start_date'],
                                            'lt' => $params['end_date']
                                        ]
                                    ]
                                ]
                            ],
                            'sort' => [
                                '_id' => 1
                            ]
                        ]
                    ],
                    $params['last_traversed_object_id']['$oid'] ?? false
                        ? [
                            '$match' => [
                                '_id' => [
                                    '$gt' => new ObjectId($params['last_traversed_object_id']['$oid'])
                                ]
                            ]
                        ]
                        : [],
                    [
                        '$match' => [
                            '$expr' => [
                                '$gt' => [
                                    [
                                        '$dateFromString' => [
                                            'dateString' => '$created_at'
                                        ]
                                    ],
                                    [
                                        '$dateAdd' => [
                                            'startDate' => [
                                                '$dateFromString' => [
                                                    'dateString' => '$status_updated_at'
                                                ]
                                            ],
                                            'unit' => 'minute',
                                            'amount' => self::TIME_DIFFERENCE_IN_MINUTES
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    [
                        '$limit' => self::ORDER_FETCH_CHUNK
                    ],
                    [
                        '$project' => [
                            'user_id' => 1,
                            'marketplace_reference_id' => 1,
                            'marketplace_shop_id' => 1,
                            'created_at' => 1,
                            'manually_imported' => 1,
                            'status_updated_at' => 1
                        ]
                    ]
                ];

                $searchPipeline = array_values(array_filter($searchPipeline));

                $orders = $this->orderContainer->aggregate($searchPipeline, [
                    'typeMap' => ['root' => 'array', 'document' => 'array']
                ])->toArray();

                if (!empty($orders)) {
                    $prepareData = [];
                    foreach ($orders as $orderData) {
                        $prepareData[] = [
                            'user_id' => $orderData['user_id'] ?? '',
                            'marketplace_shop_id' => $orderData['marketplace_shop_id'],
                            'marketplace' => Helper::TARGET,
                            'marketplace_reference_id' => $orderData['marketplace_reference_id'] ?? '',
                            'order_created_at' => $orderData['created_at'] ?? '',
                            'status_updated_at' => $orderData['status_updated_at'] ?? '',
                            'delay' => strtotime($orderData['created_at']) - strtotime($orderData['status_updated_at']) ?? '',
                            'manually_imported' => $orderData['manually_imported'] ?? false,
                            'delat_unit' => self::DELAY_UNIT,
                            'created_at' => date('c'),
                            'created_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))
                        ];

                        if (count($prepareData) === self::INSERTION_CHUNK) {
                            $this->lateSyncOrders->insertMany($prepareData);
                            $prepareData = [];
                        }
                    }

                    if (!empty($prepareData)) {
                        $this->lateSyncOrders->insertMany($prepareData);
                    }
                    if (count($orders) < self::ORDER_FETCH_CHUNK) {
                        return ['success' => true, 'message' => 'All late orders processed successfully'];
                    }
                    $lastOrderData = end($orders);
                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => \App\Amazon\Components\Order\LateOrderAnalysis::class,
                        'method' => 'findAndSaveLateSyncOrders',
                        'queue_name' => self::QUEUE_NAME,
                        'user_id' => $lastOrderData['user_id'],
                        'start_date' => $params['start_date'],
                        'end_date' => $params['end_date'],
                        'last_traversed_object_id' => $lastOrderData['_id']
                    ];

                    $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($handlerData);
                    return ['success' => true, 'message' => 'Late synced order inserted successfully'];
                }
            } else {
                $message = 'Required params(start_date, end_date)';
            }

            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from findAndSaveLateSyncOrders: ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * Fetch late synced orders data from late_sync_order_container
     *
     * @param array $params
     * @return array
     */
    public function fetchLateSyncOrderByFilter($params = [])
    {
        $this->init();
        $filter = [];
        if (isset($params['filter_by_date'])) {
            $startDate = $params['filter_by_date']['start_date'] ?? date('Y-m-d', strtotime('-1 days'));
            $endDate = $params['filter_by_date']['end_date'] ?? date('Y-m-d');
            $filter['created_at'] = [
                '$gte' => $startDate,
                '$lt' => $endDate
            ];
        }
        if (isset($params['user_id'])) {
            $filter['user_id'] = $params['user_id'];
        }
        if (isset($params['order_id'])) {
            $filter['marketplace_reference_id'] = $params['order_id'];
        }
        if (isset($params['delay'])) {
            $filter['delay'] = ['$gte' => (int)$params['delay']];
        }
        if (!empty($filter)) {
            $limit = (int) ($params['limit'] ?? self::DEFAULT_PAGE_LIMIT);
            $page = $params['activePage'] ?? 1;
            $offset = ($page - 1) * $limit;
            $options = [
                "projection" => ['_id' => 0, 'user_id' => 1, 'marketplace_reference_id' => 1, 'marketplace_shop_id' => 1, 'created_at' => 1, 'order_created_at' => 1, 'status_created_at' => 1, 'delay' => 1, 'manually_imported' => 1,'status_updated_at' => 1],
                'sort' => ['delay' => -1],
                "typeMap" => ['root' => 'array', 'document' => 'array'],
                'limit' => $limit,
                'skip' => $offset,
            ];
            $lateOrderSyncData = $this->lateSyncOrders->find($filter, $options)->toArray();
            if (!empty($lateOrderSyncData)) {
                if (count($lateOrderSyncData) > 1) {
                    $count = $this->lateSyncOrders->countDocuments($filter);
                }
                return ['success' => true, 'data' => $lateOrderSyncData, 'count' => $count ?? 1];
            } else {
                $message = 'No data not found with this filter';
            }
        } else {
            $message = 'Filter cannot be blank';
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    /**
     * Analyze order delay and determine the reason tag
     *
     * @param array $params
     * @return array
     */
    public function analyzeOrderDelay($params = [])
    {
        $this->init();
        try {
            // Validate mandatory parameters
            $mandatoryParams = ['user_id', 'marketplace_shop_id', 'order_id', 'status_updated_at', 'order_created_at'];
            foreach ($mandatoryParams as $param) {
                if (!isset($params[$param]) || empty($params[$param])) {
                    return ['success' => false, 'message' => 'Requested params missing'];
                }
            }

            $userId = $params['user_id'];
            $marketplaceShopId = $params['marketplace_shop_id'];
            $statusUpdatedAt = $params['status_updated_at'];
            $orderCreatedAt = $params['order_created_at'];

            // Convert orderCreatedAt from 2026-02-19T23:51:31+00:00 to 2026-02-19T23:26:54Z format
            if (!empty($orderCreatedAt) && is_string($orderCreatedAt)) {
                $timestamp = strtotime($orderCreatedAt);
                if ($timestamp !== false) {
                    $orderCreatedAt = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
                }
            }

            // Fetch user data from user_details collection
            $userDetailsCollection = $this->mongo->getCollectionForTable('user_details');
            $userOptions = [
                "projection" => ['_id' => 0, 'user_id' => 1, 'username' => 1, 'created_at' => 1, 'shops' => 1],
                "typeMap" => ['root' => 'array', 'document' => 'array']
            ];
            $userData = $userDetailsCollection->find(
                ['user_id' => $userId],
                $userOptions
            )->toArray();

            if (empty($userData)) {
                return ['success' => false, 'message' => 'User not found'];
            }

            $userData = $userData[0];
            $username = $userData['username'] ?? '';
            $installedAtRaw = $userData['created_at'] ?? '';
            $shops = $userData['shops'] ?? [];

            // Convert installed_at to ISO format if it's a UTCDateTime object
            $installedAt = '';
            if (!empty($installedAtRaw)) {
                if ($installedAtRaw instanceof UTCDateTime) {
                    $timestamp = (int)($installedAtRaw->toDateTime()->getTimestamp());
                    $installedAt = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
                } elseif (is_string($installedAtRaw)) {
                    $installedAt = $installedAtRaw;
                }
            }

            // Loop through shops to find matching marketplace_shop_id
            $shopData = null;
            $marketplaceId = '';
            foreach ($shops as $shop) {
                if (isset($shop['_id']) && $shop['_id'] == $marketplaceShopId) {
                    $shopData = $shop;
                    // Get marketplace_id from warehouses
                    if (isset($shop['warehouses'][0]['marketplace_id'])) {
                        $marketplaceId = $shop['warehouses'][0]['marketplace_id'];
                    }
                    break;
                }
            }

            if (!$shopData) {
                return ['success' => false, 'message' => 'Shop not found for the given marketplace_shop_id'];
            }

            // Query payment_details collection
            $paymentDetailsCollection = $this->mongo->getCollectionForTable('payment_details');
            $paymentOptions = [
                "projection" => ['_id' => 0, 'updated_at' => 1],
                "typeMap" => ['root' => 'array', 'document' => 'array']
            ];
            $paymentDetails = $paymentDetailsCollection->findOne(
                [
                    'user_id' => $userId,
                    'service_type' => 'order_sync'
                ],
                $paymentOptions
            );

            $orderFetchingActivatedAtRaw = '';
            if (!empty($paymentDetails)) {
                $orderFetchingActivatedAtRaw = $paymentDetails['updated_at'] ?? '';
            }

            // Convert order_fetching_activated_at to ISO format if it's a UTCDateTime object
            $orderFetchingActivatedAt = '';
            if (!empty($orderFetchingActivatedAtRaw)) {
                if ($orderFetchingActivatedAtRaw instanceof UTCDateTime) {
                    $timestamp = (int)($orderFetchingActivatedAtRaw->toDateTime()->getTimestamp());
                    $orderFetchingActivatedAt = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
                } elseif (is_string($orderFetchingActivatedAtRaw)) {
                    $orderFetchingActivatedAt = $orderFetchingActivatedAtRaw;
                }
            }

            // Determine tag based on conditions
            $tag = 'VALID_DELAY_NEED_TO_CHECK';

            // Condition 1: Check if installed_at date is greater than status_updated_at date
            if (!empty($installedAt) && !empty($statusUpdatedAt)) {
                $installedAtTimestamp = strtotime($installedAt);
                $statusUpdatedAtTimestamp = strtotime($statusUpdatedAt);
                if ($installedAtTimestamp > $statusUpdatedAtTimestamp) {
                    $tag = 'RECENTLY_INSTALLED';
                }
            }

            // Condition 2: Check if plan is recently activated within 1hr OR order was created before plan was activated
            if ($tag === 'VALID_DELAY_NEED_TO_CHECK' && !empty($orderFetchingActivatedAt)) {
                $orderFetchingActivatedAtTimestamp = strtotime($orderFetchingActivatedAt);
                $isPlanActivationCase = false;
                // Case A: status_updated_at is within 1 hour after plan activation
                if (!empty($statusUpdatedAt)) {
                    $statusUpdatedAtTimestamp = strtotime($statusUpdatedAt);
                    $hoursDifference = ($statusUpdatedAtTimestamp - $orderFetchingActivatedAtTimestamp) / 3600;
                    if ($hoursDifference <= self::PLAN_ACTIVATION_HOURS_THRESHOLD && $hoursDifference >= 0) {
                        $isPlanActivationCase = true;
                    }
                }
                // Case B: order was created before order fetching was activated
                if (!$isPlanActivationCase && !empty($orderCreatedAt)) {
                    $orderCreatedAtTimestamp = strtotime($orderCreatedAt);
                    if ($orderCreatedAtTimestamp < $orderFetchingActivatedAtTimestamp) {
                        $isPlanActivationCase = true;
                    }
                }
                if ($isPlanActivationCase) {
                    $tag = 'ORDER_FETCHING_RECENTLY_ACTIVATED_PLAN_CASE';
                }
            }

            // Condition 3: Check if status_updated_at date is same as order_created_at date (within 5 minutes)
            if ($tag === 'VALID_DELAY_NEED_TO_CHECK' && !empty($statusUpdatedAt) && !empty($orderCreatedAt)) {
                $statusUpdatedAtTimestamp = strtotime($statusUpdatedAt);
                $orderCreatedAtTimestamp = strtotime($orderCreatedAt);
                $minutesDifference = abs(($statusUpdatedAtTimestamp - $orderCreatedAtTimestamp) / 60);
                if ($minutesDifference <= self::ORDER_DATE_DIFFERENCE_MINUTES_THRESHOLD) {
                    $tag = 'ORDER_LAST_UPDATED_DATE_UPDATED_IN_AMAZON';
                }
            }

            // Condition 4: Check if manually_imported is set and true
            if ($tag === 'VALID_DELAY_NEED_TO_CHECK' && isset($params['manually_imported']) && $params['manually_imported'] === true) {
                $tag = 'ORDER_MANUALLY_IMPORTED';
            }

            // Get marketplace name
            $marketplaceName = '';
            if (!empty($marketplaceId) && isset(Helper::MARKETPLACE_NAME[$marketplaceId])) {
                $marketplaceName = Helper::MARKETPLACE_NAME[$marketplaceId];
            }

            // Prepare final data
            $finalData = [
                'username' => $username,
                'installed_at' => $installedAt,
                'marketplace' => $marketplaceName,
                'order_fetching_activated_at' => $orderFetchingActivatedAt,
                'tag' => $tag
            ];

            return ['success' => true, 'data' => $finalData];

        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from analyzeOrderDelay: ' . json_encode($e->getMessage()), 'info', 'exception.log');
            return ['success' => false, 'message' => 'Exception occurred: ' . $e->getMessage()];
        }
    }
}
