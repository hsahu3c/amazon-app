<?php

namespace App\Connector\Components\Webhook;

use App\Core\Components\Message\Handler\Sqs as SqsHandler;
use App\Core\Components\Sqs;

class Queue
{
    protected $di;
    /**
     * Create an SQS Queue
     *
     * @param string $queueName Name of the queue
     * @return \Aws\Result
     */
    public function create(string $queueName, string $marketplace, string $appCode = "default")
    {
        $awsCredentials = $awsCredentials = require BP . '/app/etc/aws.php';
        if (isset($awsCredentials[$marketplace][$appCode])) {
            $awsClient = $awsCredentials[$marketplace][$appCode];
        } else {
            $awsClient = $awsCredentials;
        }

        $sqs = $this->di->getObjectManager()->get(Sqs::class);
        if (isset($awsClient['region'], $awsClient['credentials']['key'], $awsClient['credentials']['secret'])) {
            $sqsClient = $sqs->getClient($awsClient['region'], $awsClient['credentials']['key'], $awsClient['credentials']['secret']);
        } else {
            $sqsClient = $this->di->getObjectManager()->get(SqsHandler::class)->getClient();
        }

        $createQueueResponse = $sqs->createQueue($queueName, $sqsClient);
        return $createQueueResponse;
    }
}
