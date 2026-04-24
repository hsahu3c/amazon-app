<?php

namespace App\Core\Components\Message\Handler;

use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Phalcon\Logger\Logger;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;
use \Phalcon\Db\Adapter\Pdo\Mysql as DbAdapter;

class Sqs extends \App\Core\Components\Base
{
    public $client = false;

    public function updateProgress($id, $points)
    {
        $message = \App\Rmq\Models\Message::findFirst([['_id' => $id]]);

        if ($message) {
            $points = ($message->progress + $points);
            $message->save(['progress' => $points < 100 ? $points : 100]);
        }
    }

    /**
     * @param $data
     * @return string
     */
    public function pushMessage($data)
    {
        if (isset($data['update_parent_progress'])) {
            $this->updateProgress($data['parent_id'], $data['update_parent_progress']);
            return false;
        }

        if (!isset($data['handle_added']) && $this->getDi()->getConfig()->get('app_code')) {
            $data['queue_name'] = $this->getDi()->getConfig()->get('app_code') . '_' . $data['queue_name'];
            $data['handle_added'] = 1;
        }

        if (!isset($data['appTag'])) {
            $data['appTag'] = $this->getDi()->getAppCode()->getAppTag();
        }

        if (!isset($data['appCode'])) {
            $data['appCode'] = $this->getDi()->getAppCode()->get();
        }
        if (!isset($data['app_code'])) {
            $data['app_code'] = $this->getDi()->getAppCode()->get();
        }
        // Create a service builder using a configuration file
        $delay = 1;
        if (isset($data['delay'])) {
            $delay = $data['delay'];
        } elseif (isset($data['run_after']) && $data['run_after'] > time()) {
            $delay = $data['run_after'] - time();
        }

        $client = $this->getClient();
        $queueUrl = $this->getQueueUrl($data['queue_name']);
        if (!$queueUrl) {
            // Get custom SQS policies from config
            $sqsPolicies = $this->di->getConfig()->get('custom_sqs_policies_for_new_queue') ? $this->di->getConfig()->get('custom_sqs_policies_for_new_queue')->toArray() : [];
            $visibilityTimeout = 2 * 60; // 2 min max time to process the message
            if (isset($data['visibility_timeout'])) {
                $visibilityTimeout = $data['visibility_timeout'];
            } elseif (isset($sqsPolicies['visibility_timeout'])) {
                $visibilityTimeout = (int)$sqsPolicies['visibility_timeout'];
            }

            $attributes = array(
                'MaximumMessageSize' => (string)(64 * 4096), // 256 KB
                'VisibilityTimeout' => (string)$visibilityTimeout,
            );

            // Check for dead letter queue name in config
            if (!empty($sqsPolicies['dead_letter_queue_name'])) {
                $deadLetterQueueUrl = $this->getQueueUrl($sqsPolicies['dead_letter_queue_name']);
                if ($deadLetterQueueUrl) {
                    // Get queue ARN from attributes
                    $queueAttributes = $client->getQueueAttributes([
                        'QueueUrl' => $deadLetterQueueUrl,
                        'AttributeNames' => ['QueueArn']
                    ]);
                    $deadLetterQueueArn = $queueAttributes->get('Attributes')['QueueArn'];

                    $maxReceiveCount = isset($sqsPolicies['dead_letter_queue_max_receive_count'])
                        ? (int)$sqsPolicies['dead_letter_queue_max_receive_count']
                        : 10;
                    $attributes['RedrivePolicy'] = json_encode([
                        'deadLetterTargetArn' => $deadLetterQueueArn,
                        'maxReceiveCount' => $maxReceiveCount
                    ]);
                }
            }
            $result = $client->createQueue(
                array(
                    'QueueName' => $data['queue_name'],
                    'Attributes' => $attributes,
                )
            );
            $queueUrl = $result->get('QueueUrl');
        }
        try {
            $client->sendMessage(
                array(
                    'QueueUrl' => $queueUrl,
                    'MessageBody' => json_encode($data),
                    'DelaySeconds' => $delay,

                )
            );
        } catch (AwsException $e) {
            throw new \Exception($e->getAwsErrorMessage(), $e->getCode());
        }
    }
    /**
     * Send Batch messages to sqs.
     * Queue name and visibility timeout for queue creation will be 
     * taken from first the message
     * 
     * For sending batch messages, each message should have an 'Id' property.
     * This Id should be unique only in its batch. If not provided a default
     * Id will be generated
     *
     * @param array $messages
     * @return void
     */
    public function pushMessagesBatch($messages)
    {
        // Max 10 messages can be sent at a time
        if (count($messages) > 10) {
            return ["success" => false, "message" => "Max 10 messages can be pushed. " . count($messages) . " given "];
        }
        foreach ($messages as $key => $data) {
            if (isset($data['update_parent_progress'])) {
                $this->updateProgress($data['parent_id'], $data['update_parent_progress']);
                return false;
            }

            if (!isset($data['handle_added']) && $this->getDi()->getConfig()->get('app_code')) {
                $data['queue_name'] = $this->getDi()->getConfig()->get('app_code') . '_' . $data['queue_name'];
                $data['handle_added'] = 1;
            }

            if (!isset($data['appTag'])) {
                $data['appTag'] = $this->getDi()->getAppCode()->getAppTag();
            }

            if (!isset($data['appCode'])) {
                $data['appCode'] = $this->getDi()->getAppCode()->get();
            }
            if (!isset($data['app_code'])) {
                $data['app_code'] = $this->getDi()->getAppCode()->get();
            }
            // Create a service builder using a configuration file
            $delay = 1;
            if (isset($data['delay'])) {
                $delay = $data['delay'];
            } elseif (isset($data['run_after']) && $data['run_after'] > time()) {
                $delay = $data['run_after'] - time();
            }
            if (empty($data["Id"])) {
                $data["Id"] = "$key-default";
            }
            $data["MessageBody"] = json_encode($data);
            $data["DelaySeconds"] = $delay;
            $messages[$key] = $data;
        }
        $client = $this->getClient();
        $queueUrl = $this->getQueueUrl($messages[0]['queue_name']);
        if (!$queueUrl) {
            $sqsPolicies = $this->di->getConfig()->get('custom_sqs_policies_for_new_queue') ? $this->di->getConfig()->get('custom_sqs_policies_for_new_queue')->toArray() : [];
            $visibilityTimeout = 2 * 60; // 2 min max time to process the message
            if (isset($data['visibility_timeout'])) {
                $visibilityTimeout = $data['visibility_timeout'];
            } elseif (isset($sqsPolicies['visibility_timeout'])) {
                $visibilityTimeout = (int)$sqsPolicies['visibility_timeout'];
            }

            $attributes = array(
                'MaximumMessageSize' => (string) (256 * 1024), // 256 KB
                'VisibilityTimeout' => (string) $visibilityTimeout,
            );

            // Check for dead letter queue name in config
            if (!empty($sqsPolicies['dead_letter_queue_name'])) {
                $deadLetterQueueUrl = $this->getQueueUrl($sqsPolicies['dead_letter_queue_name']);
                if ($deadLetterQueueUrl) {
                    // Get queue ARN from attributes
                    $queueAttributes = $client->getQueueAttributes([
                        'QueueUrl' => $deadLetterQueueUrl,
                        'AttributeNames' => ['QueueArn']
                    ]);
                    $deadLetterQueueArn = $queueAttributes->get('Attributes')['QueueArn'];

                    $maxReceiveCount = isset($sqsPolicies['dead_letter_queue_max_receive_count'])
                        ? (int)$sqsPolicies['dead_letter_queue_max_receive_count']
                        : 10;
                    $attributes['RedrivePolicy'] = json_encode([
                        'deadLetterTargetArn' => $deadLetterQueueArn,
                        'maxReceiveCount' => $maxReceiveCount
                    ]);
                }
            }
            $result = $client->createQueue(
                array(
                    'QueueName' => $messages[0]['queue_name'],
                    'Attributes' => $attributes,
                )
            );
            $queueUrl = $result->get('QueueUrl');
        }
        $client->sendMessageBatch(
            array(
                'QueueUrl' => $queueUrl,
                'Entries' => $messages
            )
        );
        return ["success" => true, "message" => count($messages) . " messages pushed"];
    }

    public function getQueueUrl($queueNameOrPrefix)
    {
        $queueUrl = false;
        try {
            $result = $this->getClient()->getQueueUrl([
                'QueueName' => $queueNameOrPrefix,
            ]);
            $queueUrl = $result->get('QueueUrl');
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === "AWS.SimpleQueueService.NonExistentQueue") {
                return null;
            } else {
                throw $e;
            }
        }
        return $queueUrl;
    }

    public function getClient()
    {
        if (!$this->client) {
            $this->client = new SqsClient(include BP . '/app/etc/aws.php');
        }
        return $this->client;
    }
    public function getS3Client()
    {
        return new S3Client(include BP . '/app/etc/aws.php');
    }

    public function getDataFrom($filePath) //Check usage of this function
    {
        return require $filePath;
    }

    // handle the case of mysql server gone away to reset the mysql connection
    public function loadDatabase()
    {
        foreach ($this->di->getConfig()->databases as $key => $database) {
            if ($database['adapter'] == 'Mongo') {
            } else {
                if ($connection = $this->di->get($key)) {
                    $result = $connection->close();
                    $this->di->getLog()->logContent(
                        'db connection is active:' . ($result ? "1" : "0"),
                        Logger::CRITICAL
                    );
                }
                $this->di->set(
                    $key,
                    function () use ($database) {
                        return new DbAdapter((array) $database);
                    }
                );
            }
        }
    }

    public function testQueue()
    {
        while (true) {
            $this->di->getLog()->logContent('working  ', 'info', 'testing_queue.log');
            print_r("null");
            sleep(10);
        }
    }
    public function consume($queueName, $durable = false)
    {
        // Iteration count for revive
        $iterationCount = $this->di->getConfig()->get("worker_revive_iterations") ?? 25;
        // Make pid file for handling soft kill
        $pidFileName = SIG_DIR . DS . getmypid();
        if (!is_dir(SIG_DIR)) {
            mkdir(SIG_DIR);
        }
        file_put_contents($pidFileName, true);

        $this->di->get('\App\Core\Components\AppCode');
        $client = $this->getClient();
        // Short polling
        if ($queueUrl = $this->getQueueUrl($queueName)) {
            $result = $client->receiveMessage(
                array(
                    'QueueUrl' => $queueUrl,
                    'MaxNumberOfMessages' => 10,
                )
            );
            do {
                if (!file_exists($pidFileName)) {
                    echo "Received SIGTERM - soft kill" . PHP_EOL;
                    break;
                }
                try {
                    while ($messages = $result->get('Messages')) {
                        foreach ($messages as $message) {
                            $_SERVER['HTTP_REQUEST_ID'] = $queueName . "_" . $message['MessageId'];

                            $messageArray = json_decode($message['Body'], true);
                            $isS3Flag = false;
                            if (isset($messageArray['S3Payload'])) {
                                $isS3Flag = true;
                                $s3Payload['S3Payload'] = $messageArray;
                                if (
                                    isset($messageArray['S3Payload']['global_webhook_s3'])
                                    && $messageArray['S3Payload']['global_webhook_s3']
                                ) {
                                    $messageArray = $this->getS3Data($messageArray);
                                } else {
                                    $s3 = $this->getS3Client();
                                    $bucketName = $messageArray['S3Payload']['bucketName'] ??
                                        $this->di->getConfig()->get('sqs_s3_bucket');
                                    $objectKey = $messageArray['S3Payload']['Key'];
                                    $result = $s3->getObject([
                                        'Bucket' => $bucketName,
                                        'Key' => $objectKey,
                                    ]);
                                    $messageArray = json_decode($result['Body'], true);
                                }
                            }

                            if (isset($messageArray['appCode'])) {
                                $this->di->getAppCode()->set($messageArray['appCode']);
                            } elseif (isset($messageArray['data']['appCode'])) {
                                $this->di->getAppCode()->set($messageArray['data']['appCode']);
                            } else {
                                $this->di->getAppCode()->set([]);
                            }
                            if (isset($messageArray['appTag'])) {
                                $this->di->getAppCode()->setAppTag($messageArray['appTag']);
                            } elseif (isset($messageArray['data']['appTag'])) {
                                $this->di->getAppCode()->setAppTag($messageArray['data']['appTag']);
                            } else {
                                $this->di->getAppCode()->setAppTag('default');
                            }

                            if (isset($messageArray['user_id']) || isset($messageArray['username'])) {
                                $currentApp = isset($messageArray['current-App']) && $messageArray['current-App'] ?
                                    $messageArray['current-App'] : null;
                                $this->di->getAppCode()->setCurrentApp($currentApp);

                                if (isset($messageArray['user_id'])) {
                                    $user = \App\Core\Models\User::findFirst([['_id' => $messageArray['user_id']]]);
                                } elseif (isset($messageArray['username'])) {
                                    $user = \App\Core\Models\User::findFirst([['username' => (string) $messageArray['username']]]);
                                }
                                if ($user) {
                                    $user->id = (string) $user->_id;
                                    $this->di->setUser($user);

                                    if ($this->di->getConfig()->has('plan_di')) {
                                        $planDi = $this->di->getConfig()->get('plan_di')->toArray();
                                        if (!empty($planDi) && isset($planDi['enabled'], $planDi['class'], $planDi['method']) && $planDi['enabled'] && !empty($planDi['class']) && !empty($planDi['method'])) {
                                            if (class_exists($planDi['class'])) {
                                                $planObj = $this->di->getObjectManager()->create($planDi['class']);
                                                if (method_exists($planObj, $planDi['method'])) {
                                                    $method = $planDi['method'];
                                                    $this->di->setPlan($planObj->$method($user->id));
                                                }
                                            }
                                        }
                                    }

                                    $decodedToken = [
                                        'role' => 'admin',
                                        'user_id' => $messageArray['user_id'] ?? null,
                                        'username' => $messageArray['username'] ?? null,
                                    ];

                                    $this->di->getRegistry()->setDecodedToken($decodedToken);
                                    if ((isset($messageArray['user_id']) && is_array($messageArray['user_id'])) || (isset($messageArray['username']) && is_array($messageArray['username']))) {
                                        $this->client->deleteMessage(
                                            array(
                                                'QueueUrl' => $queueUrl,
                                                'ReceiptHandle' => $message['ReceiptHandle'],
                                            )
                                        );
                                        if ($isS3Flag) {
                                            try {
                                                $result = $s3->deleteObject([
                                                    'Bucket' => $bucketName,
                                                    'Key' => $objectKey,
                                                ]);
                                            } catch (S3Exception $e) {
                                                $this->di->getLog()->logContent(
                                                    'Error in S3 object delete : ' . $e->getMessage() . 'BucketName : ' . $bucketName . 'ObjectKey :' . $objectKey,
                                                    'error',
                                                    'aws' . DS . 's3_delete.log'
                                                );
                                            }
                                        }
                                        continue;
                                    }
                                    if (isset($messageArray['user_id']) && strcmp($this->di->getUser()->id, $messageArray['user_id']) !== 0) {
                                        $this->di->getLog()->logContent('Userid from Di container = ' . json_encode($this->di->getUser()->id) . " Userid in sqs queue = " . $messageArray['user_id'], 'info', 'shopify' . DS . 'error_sqs.log');
                                    }
                                } else {
                                    $this->di->getLog()->logContent('4. inside foreach | data : ' . print_r($message['Body'], true), 'info', 'sqs_user_not_found.log');
                                    $this->client->deleteMessage(
                                        array(
                                            'QueueUrl' => $queueUrl,
                                            'ReceiptHandle' => $message['ReceiptHandle'],
                                        )
                                    );
                                    if ($isS3Flag) {
                                        try {
                                            $result = $s3->deleteObject([
                                                'Bucket' => $bucketName,
                                                'Key' => $objectKey,
                                            ]);
                                        } catch (S3Exception $e) {
                                            $this->di->getLog()->logContent('Error in S3 object delete : ' . $e->getMessage() . 'BucketName : ' . $bucketName . 'ObjectKey :' . $objectKey, 'error', 'aws' . DS . 's3_delete.log');
                                        }
                                    }
                                    continue;
                                }
                            }
                            #TODO: check user || shop  || app status before processing worker
                            $msgResponse = $this->processMsg($messageArray);
                            if ($msgResponse) {
                                echo "\e[38;5;10m☲\e[0m";
                            }
                            if (!$msgResponse) {
                                $this->getQueueUrl($this->getDi()->getConfig()->get('app_code') . '_failed');
                            } else {

                                if (
                                    $msgResponse === 2 &&
                                    (!isset($messageArray['retry']) ||
                                        $messageArray['retry'] <= 5)
                                ) {
                                    $messageArray['delay'] = 5;
                                    $isS3Flag = false;
                                    $messageArray['retry'] = isset($messageArray['retry']) ? $messageArray['retry'] + 1 : 1;

                                    if (!empty($s3Payload) && !empty($s3Payload['S3Payload'])) {
                                        $this->pushMessage($s3Payload);
                                    } else {
                                        $this->pushMessage($messageArray);
                                    }
                                }
                            }
                            $this->client->deleteMessage(
                                array(
                                    'QueueUrl' => $queueUrl,
                                    'ReceiptHandle' => $message['ReceiptHandle'],
                                )
                            );
                            if ($isS3Flag) {
                                try {
                                    $result = $s3->deleteObject([
                                        'Bucket' => $bucketName,
                                        'Key' => $objectKey,
                                    ]);
                                } catch (S3Exception $e) {
                                    $this->di->getLog()->logContent(
                                        'Error in S3 object delete : ' . $e->getMessage() . 'BucketName : ' . $bucketName . 'ObjectKey :' . $objectKey,
                                        'error',
                                        'aws' . DS . 's3_delete.log'
                                    );
                                }
                            }
                        }
                        // Short polling
                        $result = $client->receiveMessage(
                            array(
                                'QueueUrl' => $queueUrl,
                                'MaxNumberOfMessages' => 10,
                                'WaitTimeSeconds' => 0
                            )
                        );
                    }
                    // REVIVE !!
                    if (!($iterationCount--)) {
                        throw new \Exception("Flush Memory");
                    }
                    //Long polling
                    $result = $client->receiveMessage(
                        array(
                            'QueueUrl' => $queueUrl,
                            'MaxNumberOfMessages' => 10,
                            'WaitTimeSeconds' => 20
                        )
                    );
                } catch (\Exception $e) {
                    if ($e->getMessage() == "Flush Memory") {
                        throw $e;
                    }
                    if (strpos($e->getMessage(), '2006 MySQL server has gone away') !== false) {
                        $this->di->getLog()->logContent('revive working ', Logger::CRITICAL);
                        throw new \Exception('revive');
                    }

                    $messageCode = preg_replace('/[^A-Za-z0-9\-]/', '', $e->getMessage());
                    $this->di->getLog()->logContent(
                        $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL,
                        Logger::EMERGENCY,
                        "cli.log",
                        false,
                        $messageCode
                    );
                    throw new \Exception('revive');
                }
            } while ($durable);
            return ['success' => true];
        } else {
            return ['success' => false];
        }
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
            '$replaceRoot' => ['newRoot' => ['$mergeObjects' => [['$arrayElemAt' => ['$fromItems', 0]], '$$ROOT']]],
        ];
        $messages = $model->getCollection()->aggregate(
            $aggregation,
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
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
            ['$set' => ['status' => 'processing']],
            ['writeConcern' => new \MongoDB\Driver\WriteConcern('majority')]
        );
        $model = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $model->setSource('queue_messages');
        $message = $model->getCollection()->findOne(['_id' => $referenceId], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if ($message && isset($message['message_data'])) {
            return $message['message_data'];
        } else {
            return false;
        }
    }

    public function processMsg($msgArray)
    {
        $logger = $this->di->getLog();
        $queueProcessingLogsDisabled = $this->di->getConfig()->get('disable_queue_processing_logs') === true;
        $msgArray['type'] = $msgArray['type'] ?? 'default';
        $queueName = $msgArray['queue_name'];
        // For some specific webhook data which do not contain class, method and type, fetch data from config if exist. 
        if (!isset($msgArray['type'], $msgArray['class_name'], $msgArray['method'])) {
            $appConfig = $this->di->getConfig();
            if (isset($appConfig['default_queue_config'], $appConfig['default_queue_config'][$queueName])) {
                $queueConfig = $appConfig['default_queue_config'][$queueName];
                $msgArray['type'] = $queueConfig['type'];
                $msgArray['class_name'] = $queueConfig['class_name'];
                $msgArray['method'] = $queueConfig['method'];
            } else {
                return false;
            }
        }
        if ($msgArray['type'] == 'url') {
            return false;
        } elseif ($msgArray['type'] == 'full_class') {
            if (isset($msgArray['class_name'])) {
                $obj = $this->getDi()->getObjectManager()->get($msgArray['class_name']);
                $method = $msgArray['method'];
                $startTime = microtime(true);
                if (!$queueProcessingLogsDisabled) {
                    $logger->logContent(
                        "\tcalled handler method {$msgArray['class_name']} -> {$method}:" . $startTime . PHP_EOL,
                        Logger::DEBUG,
                        "queue-processing/{$queueName}.log"
                    );
                }
                $response = $obj->$method($msgArray);
                $endTime = microtime(true);
                $executionTime = ($endTime - $startTime);
                if (!$queueProcessingLogsDisabled) {
                    $logger->logContent(
                        "\tHandler method {$msgArray['class_name']} -> {$method} executed in  :" . $executionTime . PHP_EOL,
                        Logger::DEBUG,
                        "queue-processing/{$queueName}.log"
                    );
                }
                return $response;
            } else {
                $logger->logContent(print_r($msgArray, true), Logger::DEBUG, 'queue_failed.log');
                /* @todo if class not found */
                return true;
            }
        }
    }


    /**
     * Check user || shop || app status before processing worker
     *
     * @param $queueMessage['user_id'=> ''] or 
     * $queueMessage['user_id'=> '', 'shop_id'=''] or 
     * $queueMessage['user_id'=> '', 'shop_id'='', 'app_code'='']
     * @return void
     */
    public function checkStatus($queueMessage)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $userCollection = $mongo->getCollectionForTable("user_details");
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        if (isset($queueMessage['user_id'], $queueMessage['shop_id'], $queueMessage['app_code'])) {
            $match = [
                '$match' => [
                    'user_id' => $queueMessage['user_id'],
                    'shops._id' => (string) $queueMessage['shop_id'],
                    'shops.apps.code' => $queueMessage['app_code']
                ]
            ];
            $key = $queueMessage['user_id'] . "_" . $queueMessage['shop_id'] . "_" . $queueMessage['app_code'];
            $query = [
                $match,
                ['$unwind' => '$shops'],
                ['$unwind' => '$shops.apps'],
                $match,
                ['$project' => ['_id' => 0, 'shops.apps.app_status' => 1]]
            ];
        } elseif (isset($queueMessage['user_id'], $queueMessage['shop_id'])) {
            $match = ['$match' => ['user_id' => $queueMessage['user_id'], 'shops._id' => $queueMessage['shop_id']]];
            $key = $queueMessage['user_id'] . "_" . $queueMessage['shop_id'];
            $query = [
                $match,
                ['$unwind' => '$shops'],
                $match,
                ['$project' => ['_id' => 0, 'shops.shop_status' => 1]],
            ];
        } elseif (isset($queueMessage['user_id'])) {
            $match = ['$match' => ['user_id' => $queueMessage['user_id']]];
            $key = $queueMessage['user_id'];
            $query = [
                $match,
                ['$project' => ['_id' => 0, 'user_status' => 1]],
            ];
        }

        if ($this->di->getCache()->get($key)) {
            $out = $this->di->getCache()->get($key);
        } else {
            $out = $userCollection->aggregate($query, $options)->toArray();
            if (isset($out) && !empty($out)) {
                $out = $out[0] ?? '';
                if (isset($out['user_status']))
                    $out['user_status'] = $out['user_status'];
                if (isset($out['shops'], $out['shops']['shop_status']))
                    $out['shop_status'] = $out['shops']['shop_status'];
                if (isset($out['shops']['apps'], $out['shops']['apps']['app_status']))
                    $out['app_status'] = $out['shops']['apps']['app_status'];
                unset($out['shops']);
                $this->setCache($key, $out);
            } else {
                return ['success' => false, 'message' => 'Something went wrong!'];
            }
        }

        if (isset($out['user_status']) && $out['user_status'] !== null)
            if ($out['user_status'] === 'active')
                return ['success' => true, 'message' => $this->di->getLocale()->_('user_status', [
                    'status' => $out['user_status']
                ])];
            else
                return ['success' => false, 'message' => $this->di->getLocale()->_('user_status', [
                    'status' => $out['user_status']
                ])];

        if (isset($out['shop_status']) && $out['shop_status'] != null)
            if ($out['shop_status'] === 'active')
                return ['success' => true, 'message' => $this->di->getLocale()->_('shop_status', [
                    'status' => $out['shop_status']
                ])];
            else
                return ['success' => false, 'message' => $this->di->getLocale()->_('shop_status', [
                    'status' => $out['shop_status']
                ])];

        if (isset($out['app_status']) && $out['app_status'] != null)
            if ($out['app_status'] === 'active')
                return ['success' => true, 'message' => $this->di->getLocale()->_('app_status', [
                    'status' => $out['app_status']
                ])];
            else
                return ['success' => false, 'message' => $this->di->getLocale()->_('app_status', [
                    'status' => $out['app_status']
                ])];
    }

    public function getS3Data($data)
    {
        $s3Credentials = $this->di->getConfig()->get('aws')->s3_bucket ?? $this->di->getConfig()->get('aws')->default;
        $s3 = new S3Client(json_decode(json_encode($s3Credentials), true));
        $bucketName = $data['S3Payload']['Bucket'] ?? $this->di->getConfig()->get('sqs_s3_bucket');
        $objectKey = $data['S3Payload']['Key'];
        try {
            $result = $s3->getObject([
                'Bucket' => $bucketName,
                'Key' => $objectKey,
            ]);
            return json_decode($result['Body'], true);
        } catch (S3Exception $e) {
            $this->di->getLog()->logContent(
                'Error in get S3 object  : ' . $e->getMessage() . 'BucketName : ' . $bucketName . 'ObjectKey :' . $objectKey,
                'error',
                'aws' . DS . 's3_delete.log'
            );
        }
    }
}
