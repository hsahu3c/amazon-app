<?php

namespace App\Core\Controllers;

class QueuedTaskController extends BaseController
{
    public function getAllAction()
    {
        $queuedTask = new \App\Core\Models\QueuedTask;
        return $this->prepareResponse($queuedTask->getQueuedTaskOfUser());
    }

    public function getAllNotificationsAction()
    {
        $notifications = new \App\Core\Models\Notifications;
        return $this->prepareResponse($notifications->getNotificationsOfUser());
    }

    public function updateNotificationStatusAction()
    {
        $notifications = new \App\Core\Models\Notifications;
        $requestData = $this->getRequestData();
        return $this->prepareResponse($notifications->updateNotificationStatus($requestData));
    }

    public function updateMassNotificationStatusAction()
    {
        $requestData = $this->getRequestData();
        $notifications = new \App\Core\Models\Notifications;
        return $this->prepareResponse($notifications->updateMassNotificationStatus($requestData));
    }

    public function clearAllNotificationsAction()
    {
        $notifications = new \App\Core\Models\Notifications;
        return $this->prepareResponse($notifications->clearAllNotifications());
    }
}
