<?php

namespace App\Frontend\Components;

use App\Core\Models\BaseMongo;


class SQSWorker extends BaseMongo
{
    public function CreateWorker($data, $addQueuedTask = true)
    {

        // // die(json_encode($data));
        $userId = $data['user_id'];
        $params = $data['params'];

        // if ($addQueuedTask) {
        //     $feed_id = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->setQueuedTask(
        //         $params['target']['shopId'],
        //         [
        //             "user_id" => $params['user_id'],
        //             "marketplace" => "amazon",
        //             "message" => $data['message'] ?? 'Task in Progress',
        //             "process_code" => $data['process_code'] ?? "sqs_process_initiated",
        //             "shop_id" => ""
        //         ]
        //     );
        // }

        $handlerData = [
            'type' => 'full_class',
            // 'class_name' => '\App\Frontend\Components\ClickUP\Integration',
            'class_name' => $data['class_name'],
            'method' => $data['method_name'],
            'queue_name' => $data['worker_name'],
            'user_id' => $userId,
            'data' => [
                // 'feed_id' => $feed_id ?? '',
                'params' => $params,
            ]
        ];
        // die(json_encode($handlerData));
        $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $rmqHelper->pushMessage($handlerData);

        return 'start';
    }

    public function pushToQueue($sqsData)
    {
        $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        return $rmqHelper->pushMessage($sqsData);
    }
}
