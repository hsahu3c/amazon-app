<?php

namespace App\Connector\Components\Order\Retry;

use App\Core\Components\Base as BaseComponent;
use Aws\Scheduler\SchedulerClient;

class Scheduler extends BaseComponent
{
    private SchedulerClient $eventBridge;
    private string $logFile = 'order_retry_scheduler.log';

    private string $queueArn;
    private string $roleArn;

    private function init()
    {
        $this->eventBridge = $this->getSchedulerClient();

        $config = $this->di->getConfig()->failed_order;
        $this->queueArn = $config['sqs_retry_queue_arn'] ?? false;
        $this->roleArn = $config['eventbridge_role_arn'] ?? false;

        if (!$this->queueArn || !$this->roleArn) {
            throw new \Exception('Queue ARN or Role ARN is not set in config for Scheduler');
        }
    }

    private function getSchedulerClient()
    {
        if (isset($this->eventBridge)) {
            return $this->eventBridge;
        }

        return new SchedulerClient(include BP . '/app/etc/aws.php');
    }

    /**
     * Schedule retry using EventBridge Scheduler.
     */
    public function schedule(array $message, int $delayInSeconds, ?string $messageGroupId = null): void
    {
        $this->init();

        try {
            $scheduleName = $message['user_id'] . '-' . $message['data']['marketplace_reference_id'];
            $time = $this->formatAtExpressionTime($delayInSeconds);

            $params = [
                'Name'       => $scheduleName,
                'GroupName'  => 'order-retry',
                'ScheduleExpression' => "at($time)",
                'FlexibleTimeWindow' => ['Mode' => 'OFF'],
                'ActionAfterCompletion' => 'DELETE',
                'Target' => [
                    'Arn'     => $this->queueArn,
                    'RoleArn' => $this->roleArn,
                    'Input'   => json_encode($message),
                ],
            ];

            // if ($messageGroupId) {
            //     $params['Target']['SqsParameters'] = [
            //         'MessageGroupId' => $messageGroupId,
            //     ];
            // }

            $this->eventBridge->createSchedule($params);

            $this->log('EVENTBRIDGE_SCHEDULED', [
                'schedule_name' => $scheduleName,
                'time'          => $time,
                'group_id'      => $messageGroupId,
                'message'       => $message,
            ]);

        } catch (\Throwable $e) {
            $this->log('SCHEDULE_ERROR', [
                'error'   => $e->getMessage(),
                'message' => $message,
            ], 'error');
        }
    }

    private function formatAtExpressionTime(int $delayInSeconds): string
    {
        // add a small floor so we don't accidentally schedule in the past
        $delay = max(1, $delayInSeconds);

        return gmdate('Y-m-d\TH:i:s', time() + $delay);
    }

    private function log(string $msg, array $data, string $level = 'info'): void
    {
        $this->di->getLog()->logContent($msg . ': ' . json_encode($data), $level, $this->logFile);
    }
}
