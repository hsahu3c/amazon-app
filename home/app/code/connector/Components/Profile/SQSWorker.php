<?php

namespace App\Connector\Components\Profile;

use App\Core\Models\BaseMongo;


class SQSWorker extends BaseMongo
{
    public function CreateWorker($data, $addQueuedTask = true)
    {
        $userId = $data['user_id'];
        $params = $data['params'];

        if ($addQueuedTask) {
            $feed_id = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->setQueuedTask(
                $params['target']['shopId'],
                [
                    "user_id" => $params['user_id'],
                    "marketplace" => $params['target']['marketplace'],
                    "message" => $data['message'] ?? 'Task in Progress',
                    "process_code" => $data['process_code'] ?? "sqs_process_initiated",
                    "shop_id" => $params['target']['shopId'],
                    "additional_data" => $data['additional_data'] ?? [],
                    "bypass_process_code" => true
                ]
            );
        }

        $handlerData = [
            'type' => 'full_class',
            'class_name' => $data['class_name'],
            'method' => $data['method_name'],
            'queue_name' => $data['worker_name'],
            'user_id' => $userId,
            'data' => [
                'feed_id' => $feed_id ?? '',
                'params' => $params,
            ]
        ];
        // $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\SqsS3Publisher');
        $rmqHelper->pushMessage($handlerData);

        return 'start';
    }

    public function pushToQueue($sqsData)
    {

        if (isset($sqsData['apply_limit'])) {
            $sqsData['DelaySeconds'] = $sqsData['apply_limit'];
            $sqsData['run_after'] = $sqsData['apply_limit'];
            $sqsData['delay'] = $sqsData['apply_limit'];
            unset($sqsData['apply_limit']);
        } else {
            unset($sqsData['DelaySeconds']);
            unset($sqsData['run_after']);
            unset($sqsData['delay']);
        }

        $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        return $rmqHelper->pushMessage($sqsData);
    }
}
