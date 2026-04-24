<?php

namespace App\Core\Components\Message\Handler;

class MongoAndRmq extends \App\Core\Components\Base
{
    /**
     * @param $data
     * @return string
     */
    public function pushMessage($data)
    {
        $model = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $model->setSource('queue_messages');
        $queueData = [];
        if (isset($data['message_unique_key'])) {
            $message = $model->getCollection()->findOne(
                [
                    'message_unique_key' => $data['message_unique_key'],
                    'status' => 'pending'
                ],
                ["typeMap" =>
                [
                    'root' => 'array',
                    'document' => 'array'
                ]]
            );

            if ($message && isset($message['_id'])) {
                $model->getCollection()->findOneAndUpdate(
                    ['_id' => $message['_id']],
                    ['$set' => ['message_data' => $data]]
                );
                return $message['_id'];
            }
            $queueData['message_unique_key'] = $data['message_unique_key'];
        }

        $queueData['_id'] = (string)$model->getCounter('queue_message_id');
        $queueData['message_data'] = $data;
        if (!isset($data['run_after']) || (isset($data['run_after']) && $data['run_after'] <= time())) {
            $queueData['status'] = 'pending';
        } else {
            $queueData['run_after'] = new \MongoDB\BSON\UTCDateTime($data['run_after']);
            $queueData['status'] = 'process_later';
        }


        $queueData['created_at'] = new \MongoDB\BSON\UTCDateTime(time());
        if (isset($data['message_unique_key'])) {
            $queueData['message_unique_key'] = $data['message_unique_key'];
        }

        $model->setData($queueData)->save();
        if ($queueData['status'] != 'process_later') {
            $handlerData = $data;
            $handlerData['type'] = 'full_class';
            $handlerData['class_name'] = 'App\Core\Components\Message\Handler\MongoAndRmq';
            $handlerData['method'] = 'getMessageDataByReferenceId';
            $handlerData['queue_name'] = $data['queue_name'];
            $handlerData['message_reference_id'] = $queueData['_id'];
            unset($handlerData['data']);
            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
            try {
                if (!$helper->pushMessage($handlerData)) {
                    $model->getCollection()->findOneAndUpdate(
                        ['_id' => $message['_id']],
                        [
                            '$set' => [
                                'status' => 'failed',
                                'message' => 'Unable to push the message check log for more details.'
                            ]
                        ]
                    );
                    return false;
                }
            } catch (\Exception $e) {
                $model->getCollection()->findOneAndUpdate(
                    ['_id' => $message['_id']],
                    ['$set' => ['status' => 'failed', 'message' => $e->getMessage()]]
                );
                return false;
            }
        }
        return (int)$queueData['_id'];
    }

    public function pushFutureMessageHandlerToQueue()
    {
        $handlerData = [];
        $handlerData['type'] = 'full_class';
        $handlerData['class_name'] = 'App\Core\Components\Message\Handler\MongoAndRmq';
        $handlerData['method'] = 'queueFutureMessagesHandler';
        $handlerData['queue_name'] = 'future_messages';
        $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        $helper->pushMessage($handlerData);
    }
    public function queueFutureMessagesHandler()
    {
        $result = $this->queueFutureMessages();
        $this->pushFutureMessageHandlerToQueue();
        return $result;
    }
    public function queueFutureMessages()
    {
        $model = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $model->setSource('queue_messages');
        $aggregation = [];
        $aggregation[] = ['$match' => ['run_after' => ['$lte' => new \MongoDB\BSON\UTCDateTime(time())]]];
        $aggregation[] = [
            '$replaceRoot' => [
                'newRoot' => [
                    '$mergeObjects' => [
                        [
                            '$arrayElemAt' => [
                                '$fromItems', 0
                            ]
                        ], '$$ROOT'
                    ]
                ]
            ]
        ];
        $messages = $model->getCollection()->aggregate(
            $aggregation,
            [
                "typeMap" =>
                [
                    'root' => 'array',
                    'document' => 'array'
                ]
            ]
        );

        foreach ($messages->toArray() as $message) {
            $handlerData = [];
            $handlerData['type'] = 'full_class';
            $handlerData['class_name'] = 'App\Core\Components\Message\Handler\MongoAndRmq';
            $handlerData['method'] = 'getMessageDataByReferenceId';
            $handlerData['queue_name'] = $message['message_data']['queue_name'];
            $handlerData['message_reference_id'] = $message['_id'];

            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
            try {

                if (!$helper->pushMessage($handlerData)) {
                    $model->getCollection()->deleteOne(['_id' => $message['_id']]);
                    return false;
                } else {
                    $model->getCollection()->updateOne(['_id' => $message['_id']], ['$set' => ['status' => 'pending']]);
                }
            } catch (\Exception $e) {
                $model->getCollection()->deleteOne(['_id' => $message['_id']]);
                return false;
            }
        }

        return true;
    }

    /**
     * @param $referenceId
     * @return mixed
     */
    public function getMessageDataByReferenceId($referenceId)
    {
        $model = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $model->setSource('queue_messages');
        $model->getCollection()->findOneAndUpdate(
            ['_id' => $referenceId],
            ['$set' => ['status' => 'processing']]
        );
        $model = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $model->setSource('queue_messages');
        $message = $model->getCollection()->findOne(
            ['_id' => $referenceId],
            ["typeMap" => [
                'root' => 'array',
                'document' => 'array'
            ]]
        );
        if ($message && isset($message['message_data'])) {
            return $message['message_data'];
        } else {
            return false;
        }
    }
}
