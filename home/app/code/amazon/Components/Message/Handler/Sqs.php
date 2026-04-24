<?php

namespace App\Amazon\Components\Message\Handler;

use App\Amazon\Components\Common\Helper;
use App\Core\Models\User;
use Exception;
use Phalcon\Logger\Logger;
use App\Core\Components\Log;
use App\Core\Components\Message\Handler\Sqs as CoreSqs;
use Aws\S3\Exception\S3Exception;

class Sqs extends CoreSqs
{
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

        $client = $this->getClient();
        // Short polling
        if ($queueUrl = $this->getQueueUrl($queueName)) {
            $result = $client->receiveMessage(['QueueUrl' => $queueUrl, 'MaxNumberOfMessages' => 10, 'AttributeNames' => ['ApproximateReceiveCount', 'SentTimestamp']
            ]);
            do {
                if (!file_exists($pidFileName)) {
                    echo "Received SIGTERM - soft kill" . PHP_EOL;
                    break;
                }

                try {
                    while ($messages = $result->get('Messages')) 
                    {
                        foreach ($messages as $message) 
                        {
                            if (!file_exists($pidFileName)) {
                                echo "Received SIGTERM - breaking foreach loop." . PHP_EOL;
                                break;
                            }

                            $_SERVER['HTTP_REQUEST_ID'] = $queueName . "_" . $message['MessageId'];
                            $messageArray = json_decode((string) $message['Body'], true);
                            $isS3Flag = false;
                            if (isset($messageArray['S3Payload'])) {
                                $isS3Flag = true;
                                if (
                                    isset($messageArray['S3Payload']['global_webhook_s3'])
                                    && $messageArray['S3Payload']['global_webhook_s3']
                                ) {
                                    $messageArray = $this->getS3Data($messageArray);
                                } else {
                                    $s3 = $this->getS3Client();
                                    $bucketName = $messageArray['S3Payload']['bucketName'] ?? $messageArray['S3Payload']['Bucket'] ?? $this->di->getConfig()->get('sqs_s3_bucS3Exceptionket');
                                    $objectKey = $messageArray['S3Payload']['Key'];
                                    $result = $s3->getObject([
                                        'Bucket' => $bucketName,
                                        'Key' => $objectKey,
                                    ]);
                                    $messageArray = json_decode((string) $result['Body'], true);
                                }
                            }

                            if (isset($messageArray['user_id']) || isset($messageArray['username'])) {
                                $currentApp = isset($messageArray['current-App']) && $messageArray['current-App'] ?
                                    $messageArray['current-App'] : null;
                                $this->di->getAppCode()->setCurrentApp($currentApp);

                                if (isset($messageArray['user_id'])) {
                                    $user = User::findFirst([['_id' => $messageArray['user_id']]]);
                                } elseif (isset($messageArray['username'])) {
                                    $user = User::findFirst([['username' => (string) $messageArray['username']]]);
                                }

                                if (isset($messageArray['app_code'])) {
                                    $this->di->getAppCode()->set($messageArray['app_code']);
                                } elseif (isset($messageArray['data']['app_code'])) {
                                    $this->di->getAppCode()->set($messageArray['data']['app_code']);
                                }

                                if (isset($messageArray['appCode'])) {
                                    $this->di->getAppCode()->set($messageArray['appCode']);
                                } elseif (isset($messageArray['data']['appCode'])) {
                                    $this->di->getAppCode()->set($messageArray['data']['appCode']);
                                }

                                if (isset($messageArray['appTag'])) {
                                    $this->di->getAppCode()->setAppTag($messageArray['appTag']);
                                } elseif (isset($messageArray['data']['appTag'])) {
                                    $this->di->getAppCode()->setAppTag($messageArray['data']['appTag']);
                                }

                                if ($user) {
                                    $user->id = (string) $user->_id;
                                    $this->di->setUser($user);
                                    $decodedToken = [
                                        'role' => 'admin',
                                        'user_id' => $messageArray['user_id'] ?? null,
                                        'username' => $messageArray['username'] ?? null,
                                    ];
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

                                    $this->di->getRegistry()->setDecodedToken($decodedToken);
                                    if ((isset($messageArray['user_id']) && is_array($messageArray['user_id'])) || (isset($messageArray['username']) && is_array($messageArray['username']))) {
                                        $this->client->deleteMessage(['QueueUrl' => $queueUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
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

                                    if (isset($messageArray['user_id']) && strcmp($this->di->getUser()->id, (string) $messageArray['user_id']) !== 0) {
                                        $this->di->getLog()->logContent('Userid from Di container = ' . json_encode($this->di->getUser()->id) . " Userid in sqs queue = " . $messageArray['user_id'], 'info', 'shopify' . DS . 'error_sqs.log');
                                    }
                                } else {
                                    $this->di->getLog()->logContent('User not found, queue message: ' . base64_encode(json_encode($message['Body'])), 'info', 'sqs_user_not_found.log');
                                    $this->client->deleteMessage(['QueueUrl' => $queueUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
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

                            $action = null;
                            if (empty($messageArray['queue_name'])) {
                                $messageArray['queue_name'] = $queueName;
                            }
                            if (isset($messageArray['marketplace'])) {
                                $path = '\Components\Webhook\Helper';
                                $method = 'getActionByQueueName';
                                $class = $this->di->getObjectManager()->get(Helper::class)
                                    ->checkClassAndMethodExists($messageArray['marketplace'], $path, $method);
                                if (!empty($class)) {
                                    $action = $this->di->getObjectManager()->get($class)->$method($queueName);
                                }
                            }

                            $startTime = microtime(true);
                            $msgResponse = $this->processMsg($messageArray);
                            $endTime = microtime(true);
                            $processingTime = $endTime - $startTime;
                            $this->receiveCountMonitorProcess($message, $queueName, $processingTime);
                            if ($msgResponse) {
                                if (isset($action)) {
                                    $this->logData($action, true, ['request' => $messageArray, 'response' => $msgResponse]);
                                }
                                echo "\e[38;5;10m☲\e[0m";
                            }

                            if (!$msgResponse) {
                                $this->getQueueUrl($this->getDi()->getConfig()->get('app_code') . '_failed');
                                if (isset($action)) {
                                    $this->logData($action, false, ['request' => $messageArray, 'response' => "Error Occured"]);
                                }
                            } else {

                                if (
                                    $msgResponse === 2 &&
                                    (!isset($messageArray['retry']) ||
                                        $messageArray['retry'] <= 5)
                                ) {
                                    if (isset($action)) {
                                        $this->logData($action, false, ['request' => $messageArray, 'response' => "Message Retry"]);
                                    }

                                    $messageArray['delay'] = 5;
                                    $messageArray['retry'] = isset($messageArray['retry']) ? $messageArray['retry'] + 1 : 1;
                                    $this->pushMessage($messageArray);
                                }
                            }

                            if(empty($msgResponse['skip_delete_message'])) {
                                $this->client->deleteMessage(['QueueUrl' => $queueUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
                            }

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

                        if (!file_exists($pidFileName)) {
                            echo "Received SIGTERM - breaking while loop." . PHP_EOL;
                            break;
                        }

                        // Short polling
                        $result = $client->receiveMessage(['QueueUrl' => $queueUrl, 'MaxNumberOfMessages' => 10, 'WaitTimeSeconds' => 0, 'AttributeNames' => ['ApproximateReceiveCount', 'SentTimestamp']]);
                    }

                    // REVIVE !!
                    if (!($iterationCount--)) {
                        throw new Exception("Flush Memory");
                    }

                    //Long polling
                    $result = $client->receiveMessage(['QueueUrl' => $queueUrl, 'MaxNumberOfMessages' => 10, 'WaitTimeSeconds' => 20, 'AttributeNames' => ['ApproximateReceiveCount', 'SentTimestamp']]);
                } catch (Exception $e) {
                    if ($e->getMessage() == "Flush Memory") {
                        throw $e;
                    }

                    if (str_contains($e->getMessage(), '2006 MySQL server has gone away')) {
                        $this->di->getLog()->logContent('revive working ', Logger::CRITICAL);
                        throw new Exception('revive');
                    }

                    $messageCode = preg_replace('/[^A-Za-z0-9\-]/', '', $e->getMessage());
                    $this->di->getLog()->logContent(
                        // $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL,
                        $e->getMessage() . PHP_EOL . Log::getExceptionTraceAsString($e) . PHP_EOL,
                        Logger::EMERGENCY,
                        "cli.log",
                        false,
                        $messageCode
                    );
                    throw new Exception('revive');
                }
            } while ($durable);

            return ['success' => true];
        }
        return ['success' => false];
    }

    public function receiveCountMonitorProcess($message, $queueName, $processingTime) {

        if((isset($message['Attributes']['ApproximateReceiveCount']) && $message['Attributes']['ApproximateReceiveCount'] > 2) || $processingTime > 1.5) {
            $this->di->getObjectManager()->get("\App\Amazon\Models\SourceModel")
                ->insertPoisonMessageData($message, $queueName, $processingTime);
        }
    }

    public function logData($process, $status, $msgResponse): void
    {
        $msgResponse = json_encode($msgResponse);
        $status = $status ? "true" : "false";
        $path = "consume/" . date("Y-m-d") . "/" . $process . "/" . $status . "/" . $this->di->getUser()->id . ".log";
        $this->di->getLog()->logContent(
            $msgResponse,
            "info",
            $path
        );
    }
}
