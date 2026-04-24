<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use App\Connector\Components\Helper as ConnectorHelper;

class Notifications extends BaseMongo
{
    protected $table = 'notifications';

    const RANGE = 7;

    const END_FROM = 6;

    const START_FROM = 5;

    const IS_EQUAL_TO = 1;

    const IS_CONTAINS = 3;

    const IS_NOT_EQUAL_TO = 2;

    const IS_NOT_CONTAINS = 4;

    const IS_GREATER_THAN = 8;

    const IS_LESS_THAN = 9;

    const CONTAIN_IN_ARRAY = 10;

    const NOT_CONTAIN_IN_ARRAY = 11;

    const KEY_EXISTS = 12;

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    public function getAllNotifications($params)
    {
        $userId = $this->di->getUser()->id;
        $count = 5;
        $activePage = 0;
        $aggregate = [];
        if (isset($params['count']) && isset($params['activePage'])) {
            $count = $params['count'];
            $activePage = $params['activePage'] - 1;
            $activePage = $activePage * $count;
        } else if (isset($params['count'])) {
            $count = $params['count'];
        }

        if (isset($params['filter'])) {
            $obj = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');

            $aggregateSearch = $obj->search($params);

        }

        $or_filter = [];
        if (isset($params['or_filter'])) {
            foreach ($params['or_filter'] as $key => $values) {
            if (is_array($values) && !empty($values)) {
                $values = array_values($values);
                $or_filter[$key] =  ['$in' => $values];
            }
            }
        }

        $sourceShopId = isset($params['source']) && isset($params['source']['shopId']) ? $params['source']['shopId'] : $this->di->getRequester()->getSourceId();
        $targetShopId = isset($params['target']) && isset($params['target']['shopId']) ? $params['target']['shopId'] : $this->di->getRequester()->getTargetId();


        if (isset($params['app_tag'])) {
            $appTag = $params['app_tag'];
        } else {
            $appTag = $this->di->getAppCode()->getAppTag();
        }


        $aggregate = [
            [
                // matching with userid; exclude archived so they are not shown in get/allNotifications
                '$match' => [
                    'user_id' => $userId,
                    'appTag' => $appTag,
                    'is_archived' => ['$ne' => true],
                ]
            ],
            [
                //sorting array
                '$sort' => [
                    'created_at' => -1
                ]
            ],
            [
                //matching with either source shop id or target shop id
                '$match' => [
                    '$or' => [
                        [
                            'shop_id' => $targetShopId
                        ],
                        [
                            'shop_id' => $sourceShopId
                        ]
                    ]
                ]
            ],
            [
                '$addFields' => [
                    'created_at' => [
                        '$cond' => [
                            'if' => [
                                '$eq' => [
                                    ['$type' => '$created_at'],
                                    'date'
                                ]
                            ],
                            'then' => [
                                '$dateToString' => [
                                    'format' => '%Y-%m-%dT%H:%M:%S%z',
                                    'date' => '$created_at'
                                ]
                            ],
                            'else' => '$created_at'
                        ]
                    ]
                ]
            ]

        ];
        if (isset($params['severity'])) {
            $aggregate[] = [
                '$match' => [
                    "severity" => $params['severity']
                ],
            ];
        }

        if (isset($aggregateSearch) && count($aggregateSearch) > 0){
            $aggregate[] = [
                '$match' => $aggregateSearch
            ];
        }


        if ($or_filter !== []) {
            $aggregate[] = [
                '$match' =>  $or_filter,

            ];
        }

        $countQuery = $aggregate;
        $aggregate[] = [
            '$skip' => (int) $activePage,
        ];
        $aggregate[] = [
            '$limit' => (int) $count,
        ];
        $collection = $this->getCollection();

        $notifications = $collection->aggregate($aggregate);
        $countQuery[] = [
            '$count' => 'count',
        ];
        $count = $collection->aggregate($countQuery)->toArray();
        !empty($count) ? $count = $count[0]['count'] : $count = 0;
        $notifications = $notifications->toArray();
        $notifications = $this->getModifiedTranslatedData($notifications, 'notifications');
        return ['success' => true, 'data' => ['rows' => $notifications, 'count' => $count]];
    }

    //New Function
    public function addNotification($shopId, $notificationData)
    {
        try {
            $userId = $this->di->getUser()->id;
            if (empty($notificationData)) {
                return ['success' => false, 'message' => 'Notification Data missing.'];
            }

            if (!$shopId) {
                return [
                    "success" => false,
                    "message" => "shop_id does not exist"
                ];
            }
            ;
            if (!isset($notificationData['marketplace'])) {
                return [
                    "success" => false,
                    "message" => "Marketplace does not exist"
                ];
            }

            if (!isset($notificationData['appTag'])) {
                $appTag = $this->di->getAppCode()->getAppTag();
            } else {
                $appTag = $notificationData['appTag'];
            }

            $notification = $this->di->getObjectManager()->create('\App\Connector\Models\Notifications');

            $data = [
                'user_id' => $notificationData['user_id'] ?? $userId,
                "shop_id" => $shopId,
                'marketplace' => $notificationData['marketplace'],
                'appTag' => $appTag,
                'is_read' => $notificationData['is_read'] ?? false,
                'is_archived' => $notificationData['is_archived'] ?? false,
                'created_at' => new \MongoDB\BSON\UTCDateTime()
            ];
            if (isset($notificationData['additional_data'])) {
                $data['additional_data'] = $notificationData['additional_data'];
            }

            if (isset($notificationData['process_code'])) {
                $data['process_code'] = $notificationData['process_code'];
            }

            if (isset($notificationData['process_initiation'])) {
                //process is initiated mannually(by user) or automatic(eg. cron)
                $data['process_initiation'] = $notificationData['process_initiation'];
            }

            if (isset($notificationData['severity'])) {
                $data['severity'] = $notificationData['severity'];
            }

            if (isset($notificationData['message'])) {
                $data['message'] = $notificationData['message'];
            }
            ;
            if (isset($notificationData['tag'])) {
                $data['tag'] = $notificationData['tag'];
            } else {
                $user_details = $this->getCollectionForTable("user_details");
                $userdata = $user_details->findOne(
                    ["user_id" => $userId]
                );
                $name = [];
                if ($userdata) {
                    foreach ($userdata['shops'] as $shop) {
                        if (isset($shop['name']) && $shopId === $shop['_id']) {
                            $name[] = $shop['name'];
                        }
                    }
                }

                $data['tag'] = implode(', ', $name);
            };

            if (isset($notificationData['url'])) {
                $data['url'] = $notificationData['url'];
            }

            $notification->setData($data);
            $notification->save();

            if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
                /* Trigger websocket (if configured) */
                $websocketConfig = $this->di->getConfig()->get("app_tags")
                    ->get($appTag)
                    ->get('websocket');
                if (
                    isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['notification']) &&
                    $websocketConfig['client_id'] &&
                    $websocketConfig['allowed_types']['notification']
                ) {
                    $helper = $this->di->getObjectManager()->get(ConnectorHelper::class);
                    $helper->handleMessage([
                        'user_id' => $userId,
                        'notification' => $notification->getData()
                    ]);
                }

                /* Trigger websocket (if configured) */
            }

            return $this;
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function clearAllNotifications($userId, $shopId, $appTag, $filter = [])
    {
        $collection = $this->getCollection();
        $appliedFilters = ['user_id' => $userId, 'shop_id' => $shopId, 'appTag' => $appTag];
        if (count($filter) > 0) {
            $appliedFilters = array_merge($appliedFilters, $this->convertQueryToMongoFilter($filter));
        }

        if ($collection->count($appliedFilters)) {
            $collection->deleteMany($appliedFilters);
            return ['success' => true, 'message' => 'All your activities are cleared.'];
        }

        return ['success' => false, 'message' => 'There are no activities for this shop'];
    }

    /**
     * Converts query parameters to MongoDB filters based on custom constants.
     *
     * @param array $queryParams The query parameters from the URL.
     * @return array The MongoDB filter array.
     */
    public function convertQueryToMongoFilter($queryParams)
    {
        $filter = [];

        $operatorMap = [
            Notifications::IS_EQUAL_TO => '$eq',
            Notifications::IS_NOT_EQUAL_TO => '$ne',
            Notifications::IS_GREATER_THAN => '$gt',
            Notifications::IS_LESS_THAN => '$lt',
            Notifications::IS_CONTAINS => '$regex',
            Notifications::IS_NOT_CONTAINS => '$not', // Will be combined with $regex
            Notifications::CONTAIN_IN_ARRAY => '$in',
            Notifications::NOT_CONTAIN_IN_ARRAY => '$nin',
            Notifications::KEY_EXISTS => '$exists'
        ];

        foreach ($queryParams as $key => $conditions) {
            foreach ($conditions as $condition => $value) {
                $condition = (int)$condition;

                if (isset($operatorMap[$condition])) {
                    $mongoOperator = $operatorMap[$condition];

                    if ($condition === Notifications::IS_CONTAINS) {
                        $value = new \MongoDB\BSON\Regex($value, 'i');
                    } elseif ($condition === Notifications::IS_NOT_CONTAINS) {
                        $value = ['$regex' => new \MongoDB\BSON\Regex($value, 'i')];
                        $mongoOperator = '$not'; // Use $not with regex
                    } elseif (in_array($condition, [Notifications::CONTAIN_IN_ARRAY, Notifications::NOT_CONTAIN_IN_ARRAY])) {
                        $value = explode(',', $value);
                    } elseif ($condition === Notifications::KEY_EXISTS) {
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    }

                    if (!isset($filter[$key])) {
                        $filter[$key] = [];
                    }

                    $filter[$key][$mongoOperator] = $value;
                }
            }
        }

        return $filter;
    }

    /**
     * Count unread notifications for a user
     *
     * @param array $filters Array containing user_id, shop_id (source), target_shop_id, appTag
     * @return int Count of unread notifications
     */
    public function countUnread(array $filters): int
    {
        $userId = $filters['user_id'] ?? $this->di->getUser()->id;
        $sourceShopId = $filters['shop_id'] ?? $this->di->getRequester()->getSourceId();
        $targetShopId = $filters['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
        $appTag = $filters['appTag'] ?? $this->di->getAppCode()->getAppTag();

      

        $query = [
            'user_id' => $userId,
            'appTag' => $appTag,
            'is_read' => ['$exists' => true, '$eq' => false],
            'is_archived' => ['$ne' => true]
        ];

        // Match either source or target shop
        if ($sourceShopId || $targetShopId) {
            $orConditions = [];
            if ($sourceShopId) {
                $orConditions[] = ['shop_id' => $sourceShopId];
            }
            if ($targetShopId) {
                $orConditions[] = ['shop_id' => $targetShopId];
            }
            if (count($orConditions) > 0) {
                $query['$or'] = $orConditions;
            }
        }

        return $this->getCollection()->countDocuments($query);
    }

    /**
     * Build base filter for current user's notifications (same scope as countUnread).
     *
     * @param array $filters Optional overrides for user_id, shop_id, target_shop_id, appTag
     * @return array MongoDB filter
     */
    protected function getUnreadScopeFilter(array $filters = []): array
    {
        $userId = $filters['user_id'] ?? $this->di->getUser()->id;
        $sourceShopId = $filters['shop_id'] ?? $this->di->getRequester()->getSourceId();
        $targetShopId = $filters['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
        $appTag = $filters['appTag'] ?? $this->di->getAppCode()->getAppTag();

        $query = [
            'user_id' => $userId,
            'appTag' => $appTag,
        ];

        if ($sourceShopId || $targetShopId) {
            $orConditions = [];
            if ($sourceShopId) {
                $orConditions[] = ['shop_id' => $sourceShopId];
            }
            if ($targetShopId) {
                $orConditions[] = ['shop_id' => $targetShopId];
            }
            if (count($orConditions) > 0) {
                $query['$or'] = $orConditions;
            }
        }

        return $query;
    }

    /**
     * Mark all unread notifications as read for the current user (and app/shop scope).
     *
     * @param array $filters Optional array containing user_id, shop_id, target_shop_id, appTag
     * @return int Number of documents modified
     */
    public function markAllAsRead(array $filters = []): int
    {
        $query = $this->getUnreadScopeFilter($filters);
        $query['is_read'] = ['$exists' => true, '$eq' => false];
        $query['is_archived'] = ['$ne' => true];

        $result = $this->getCollection()->updateMany(
            $query,
            ['$set' => ['is_read' => true]]
        );

        return $result->getModifiedCount();
    }

    /**
     * Mark a single notification as read by id (scoped to current user).
     *
     * @param string $notificationId Notification _id (MongoDB ObjectId string or id)
     * @return bool True if one document was updated
     */
    public function markAsRead(string $notificationId): bool
    {
        $filter = $this->getUnreadScopeFilter();
        try {
            $filter['_id'] = new \MongoDB\BSON\ObjectId($notificationId);
        } catch (\Exception $e) {
            $filter['_id'] = $notificationId;
        }

        $result = $this->getCollection()->updateOne(
            $filter,
            ['$set' => ['is_read' => true]]
        );

        return $result->getModifiedCount() === 1;
    }

    /**
     * Archive all non-archived notifications for the current user (and app/shop scope).
     *
     * @param array $filters Optional array containing user_id, shop_id, target_shop_id, appTag
     * @return int Number of documents modified
     */
    public function archiveAllNotifications(array $filters = []): int
    {
        $query = $this->getUnreadScopeFilter($filters);
        $query['is_archived'] = ['$ne' => true];

        $result = $this->getCollection()->updateMany(
            $query,
            ['$set' => ['is_archived' => true]]
        );

        return $result->getModifiedCount();
    }

    /**
     * Archive a single notification by id (scoped to current user).
     *
     * @param string $notificationId Notification _id (MongoDB ObjectId string or id)
     * @return bool True if one document was updated
     */
    public function archiveNotification(string $notificationId): bool
    {
        $filter = $this->getUnreadScopeFilter();
        try {
            $filter['_id'] = new \MongoDB\BSON\ObjectId($notificationId);
        } catch (\Exception $e) {
            $filter['_id'] = $notificationId;
        }

        $result = $this->getCollection()->updateOne(
            $filter,
            ['$set' => ['is_archived' => true]]
        );

        return $result->getModifiedCount() === 1;
    }
}
