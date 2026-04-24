<?php

namespace App\Core\Models;

use Phalcon\Logger\Logger;

class QueuedTask extends BaseMongo
{
    protected $table = 'queued_tasks';
    public function getQueuedTaskOfUser()
    {
        $userId = $this->di->getUser()->_id;
        $allUserFeeds = $this->findByField(['user_id' => $userId]);
        if (count($allUserFeeds)) {
            $allQueuedTask = [];
            foreach ($allUserFeeds as $feedValue) {
                $feedId = $feedValue['feed_id'];
                if ($feedId) {
                    $message = \App\Rmq\Models\Message::findFirst([["_id" => $feedId]]);
                    $progress = $message->progress == '' ? 0 : $message->progress;
                    if ($progress < 100) {
                        $allQueuedTask[] = [
                            'status' => $progress . '%',
                            'text' => $feedValue->feed_message,
                            'id' => $feedValue['_id'],
                        ];
                    }
                }
            }
            return ['success' => true, 'message' => 'All queued task', 'data' => $allQueuedTask];
        } else {
            return [
                'success' => true,
                'code' => 'no_queued_task',
                'message' => 'No queued task',
                'data' => []
            ];
        }
    }

    public function createNotification($completedFeed, $message, $userId)
    {
        $completedFeed->delete();
        $notifications = new \App\Core\Models\Notifications;
        $notifications->user_id = $userId;
        $notifications->message = $message . ' completed';
        $notifications->seen = false;
        $notifications->severity = 'notice';
        $saveStatus = $notifications->save();
        if (!$saveStatus) {
            $errors = implode(',', $notifications->getMessages());
            $this->di->getLog()->logContent(
                $message . ' ==> ' . $userId,
                Logger::CRITICAL,
                'notify-insert.log'
            );
            $this->di->getLog()->logContent(
                $errors,
                Logger::CRITICAL,
                'notify-insert.log'
            );
        }
        return true;
    }

    public function getQueuedTaskResponse($data, $success)
    {
        $messageId = $data['message_id'];
        $userFeed = QueuedTask::findFirst(["feed_id='{$messageId}'"]);
        if ($userFeed) {
            $userId = $userFeed->user_id;
            $userFeed->delete();
            $notifications = $this->di->getObjectManager()->create('\App\Core\Models\Notifications');
            $notifications->seen = false;
            $notifications->user_id = $userId;
            if ($success) {
                $notifications->message = $data['success_message'];
                $notifications->severity = 'notice';
            } else {
                $notifications->message = $data['failure_message'];
                $notifications->severity = 'critical';
            }
            $saveStatus = $notifications->save();
            $notify = new Notifications;
            $notify->sendMessageToClient($userId);
            if (!$saveStatus) {
                $errors = implode(',', $notifications->getMessages());
                $this->di->getLog()->logContent(
                    $errors . ' ==> ' . $userId . ' ==> ' . $messageId,
                    Logger::CRITICAL,
                    'notify-insert.log'
                );
            }
        }
    }

    public function insertNewQueuedTask($feedId, $userId = false, $message = 'Queued Task')
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }
        $queuedTasks = new QueuedTask;
        $feedData = [
            'feed_id' => $feedId,
            'user_id' => $userId,
            'feed_message' => $message,
        ];
        if (!$queuedTasks->save($feedData)) {
            $errors = implode(',', $queuedTasks->getMessages());
            $this->di->getLog()->logContent(
                $errors,
                Logger::CRITICAL,
                'queued_task_insert.log'
            );
            return false;
        }
        $notifications = new Notifications;
        $notifications->sendMessageToClient($userId);
        return true;
    }

    public function deleteFailedQueuetask($feedId)
    {
        if (!is_object($feedId)) {
            $feedId = new \MongoDB\BSON\ObjectId($feedId);
        }
        $queueTask = QueuedTask::find([["_id" => $feedId]]);
        $queueTask->delete();
    }
}
