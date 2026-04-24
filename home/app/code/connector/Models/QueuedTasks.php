<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use Phalcon\Mvc\Model\Message;
use Phalcon\Validation;
use App\Connector\Components\Helper as ConnectorHelper;

class QueuedTasks extends BaseMongo
{
    protected $table = 'queued_tasks';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }


    public function getAllQueuedTasks($params, $match = false)
    {
        $userId = $this->di->getUser()->id;
        $collection = $this->getCollection();
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

        if (isset($params['app_tag'])) {
            $appTag = $params['app_tag'];
        } else {
            $appTag = $this->di->getAppCode()->getAppTag();
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
        if (!$match) {
            $aggregate = [
                [
                    // matching with userid
                    '$match' => [
                        'user_id' => $userId,
                        "appTag" => $appTag,
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

            ];

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

            $aggregate[] = ['$skip' => (int) $activePage];
            $aggregate[] = ['$limit' => (int) $count];
        } else {
            $aggregate = [
                [
                    // matching with userid
                    '$match' => $params['match']
                ]

            ];
        }



        $countQuery = $aggregate;
        $queuedTasks = $collection->aggregate($aggregate);
        $countQuery[] = [
            '$count' => 'count',
        ];

        if (isset($params['get_count']) && !$params['get_count']) $count = 0;
        else {
            $count = $collection->aggregate($countQuery)->toArray();
            !empty($count) ? $count = $count[0]['count'] : $count = 0;
        }


        $queuedTasks = $queuedTasks->toArray();
        $queuedTasks = $this->getModifiedTranslatedData($queuedTasks, 'queued_task');

        return [
            'success' => true,
            'data' => [
                'rows' => $queuedTasks,
                'count' => $count
            ]

        ];
    }

    /**
     * function to check Whether user's action in progress or not
     *
     * @param string $userId
     * @param array $queuedData
     * @return array/bool
     */
    // new function


    public function setQueuedTask($shopId, $queuedData)
    {
        try {
            $userId = $queuedData['user_id']??$this->di->getUser()->id;


            if (empty($queuedData)) {
                return ['success' => false, 'message' => 'QueueData missing.'];
            }

            if (!$shopId) {
                return [
                    "success" => false, "message" => "shop_id does not exist"
                ];
            }
            ;
            if (!isset($queuedData['marketplace'])) {
                return [
                    "success" => false, "message" => "markeplace does not exist"
                ];
            }

            $query = ['user_id' => $userId];
            $query['shop_id'] = $shopId = (string)$shopId;

            if (isset($queuedData['process_code'])) {
                $query['process_code'] = $queuedData['process_code'];
            } else {
                return [
                    "success" => false, "message" => "kindly provide process_code"
                ];
            }

            $collection = $this->getCollection();

            if ($collection->count($query) == 0  || (isset($queuedData['bypass_process_code']) && $queuedData['bypass_process_code'])) {
                $data = [
                    'user_id' => $userId,
                    'shop_id' => $shopId,
                    'marketplace' => $queuedData['marketplace'],
                    'appTag' => $queuedData['app_tag'] ?? $this->di->getAppCode()->getAppTag(),
                    'process_code' => $queuedData['process_code'],
                    'progress' => 0.00,
                    'created_at' => date('c'),

                ];
                if (isset($queuedData['additional_data'])) {
                    $data['additional_data'] = $queuedData['additional_data'];
                }

                if (isset($queuedData['process_initiation'])) {
                    //process is initiated mannually(by user) or automatic(eg. cron)
                    $data['process_initiation'] = $queuedData['process_initiation'];
                }

                if (isset($queuedData['message'])) {
                    $data['message'] = $queuedData['message'];
                }

                if (isset($queuedData['tag'])) {
                    $data['tag'] = $queuedData['tag'];
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
                }

                $queuedTask = $collection->insertOne($data);
                if ($queuedTask) {
                    $counter = (string)$queuedTask->getInsertedId();
                }

                $this->triggerWebSocketIfConfigured($counter,[]);
                return $counter;
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }


    function checkQueuedTaskwithProcessTag($processTag, $shopId = false)
    {

        $userId = $this->di->getUser()->id;
        $sourceShopId = $shopId ? $shopId : $this->di->getRequester()->getSourceId();
        $collection = $this->getCollection();
        $query = ['user_id' => $userId, 'shop_id' => $sourceShopId, "process_code" => $processTag];
        return $collection->count($query);
    }

    // new function
    public function updateFeedProgress($feedId, $progress, $message = '', $addNupdate = true, $additionalData = [])
    {
        $queueTable = $this->getCollection();
        $queuedData = $queueTable->findOne(['_id' => new \MongoDB\BSON\ObjectId($feedId)]);
        if ($queuedData) {
            //Unsupported operand types: int + string
            if(empty($progress)) {
                $progress = 0;
            }
            $updatedProgress = $addNupdate ? ($queuedData['progress'] + $progress) : $progress;
            if ($updatedProgress < 99.9) {
                if (!empty($additionalData)) {
                    $data['additional_data'] = $additionalData;
                }

                $data['progress'] = $updatedProgress;
                $data['updated_at'] = date('Y-m-d H:i:s');
                if (!empty($message)) {
                    $data['message'] = $message;
                }

                $queueTable->updateOne(["_id" => new \MongoDB\BSON\ObjectId($feedId)], ['$set' => $data]);
                $this->triggerWebSocketIfConfigured($feedId,[]);
                return $updatedProgress;
            }
            $queueTable->deleteOne(["_id" => new \MongoDB\BSON\ObjectId($feedId)]);
            $queuedData['progress']=100;
            $this->triggerWebSocketIfConfigured($feedId,$queuedData);
            $this->di->getEventsManager()->fire("application:afterQueuedTaskComplete", $this, ['feed_id' => $feedId]);
            return 100;
        }

        return false;
    }

    /**
     * Check whether the queued task process is aborted or not
     * @param string $feedId
     * @return boolean
     */
    public function checkQueuedTaskExists($feedId)
    {
        $queueTable = $this->getCollection();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
        $query = ['_id' => new \MongoDB\BSON\ObjectId($feedId)];
        $searchResponse = $queueTable->find($query, $options)->toArray();
        if (count($searchResponse) == 0) {
            return false;
        }

        return true;
    }

    /**
     * Aborts the queued task process
     * Required parameters are feed_id, marketplace, shop_id
     * @param array $rawBody
     * @return array
     */
    public function abortQueuedTaskProcess($rawBody)
    {
        $queueTable = $this->getCollection();
        $deleteResponse = $queueTable->deleteOne(['_id' => new \MongoDB\BSON\ObjectId($rawBody['feed_id'])]);
        if ($deleteResponse->getDeletedCount() == 0) {
            return [
                'success' => false,
                'message' => 'Feed Not found'
            ];
        }

        $sendNotification = true;
        if (isset($rawBody['send_notification']) && $rawBody['send_notification'] == 'false') {
            $sendNotification = false;
        }

        if ($sendNotification) {
            $notificationData = [
                'marketplace' => $rawBody['marketplace'],
                'message' => $rawBody['message'] ?? 'Your process has been successfully aborted',
                'severity' => $rawBody['severity'] ?? 'critical'
            ];
            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($rawBody['shop_id'], $notificationData);
        }

        return [
            'success' => true,
            'message' => 'Your process has been aborted'
        ];
    }

    /**
     * Trigger websocket (if configured)
     */
    public function triggerWebSocketIfConfigured($feedId,$data=[]): void
    {
        $queueTable = $this->getCollection();
        $appTag = $data['appTag'] ?? $this->di->getAppCode()->getAppTag();
        if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
        $websocketConfig = $this->di->getConfig()->get("app_tags")
            ->get($appTag)
            ->get('websocket');
        if (
            isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['feed']) &&
            $websocketConfig['client_id'] &&
            $websocketConfig['allowed_types']['feed']
        ) {
            if( isset($data['user_id'])&& isset($data['marketplace']) && isset($data['appTag'])&& isset($data['process_code'])){
                $queuedData=$data;
            }else{
                $queuedData = $queueTable->findOne(
                    [
                        '_id' => new \MongoDB\BSON\ObjectId($feedId)
                    ],
                    [
                        "typeMap" =>
                        [
                            'root' => 'array',
                            'document' => 'array'
                        ]
                    ]
                );
            }

            if ($queuedData) {
                $helper = $this->di->getObjectManager()->get(ConnectorHelper::class);
                $helper->handleMessage([
                    'user_id' => $this->di->getUser()->id,
                    'feed' => $queuedData
                ]);
            }
        }
    }
}
}
