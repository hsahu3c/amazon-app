<?php

namespace App\Connector\Controllers;

use App\Connector\Components\BellNotificationHelper;

class BellNotificationController extends \App\Core\Controllers\BaseController
{

    public function bellCountAction()
    {
        try {
            $bellHelper = $this->di->getObjectManager()->get(BellNotificationHelper::class);
            $result = $bellHelper->getBellCount();
            return $this->prepareResponse([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Mark notification(s) as read
     * POST /connector/bell-notification/markAsRead
     * Body: { "notification_id": "string" } for single notification
     * Body: { "mark_all": true } for all notifications
     */
    public function markAsReadAction()
    {
        try {
            $notificationModel = $this->di->getObjectManager()->get(\App\Connector\Models\Notifications::class);
            $requestData = $this->getRequestData();

            if (isset($requestData['mark_all']) && $requestData['mark_all'] === true) {
                // Mark all notifications as read
                $modifiedCount = $notificationModel->markAllAsRead();
                
                return $this->prepareResponse([
                    'success' => true,
                    'message' => "Marked {$modifiedCount} notifications as read",
                    'data' => ['modified_count' => $modifiedCount]
                ]);
            } elseif (isset($requestData['notification_id'])) {
                // Mark single notification as read
                $notificationId = $requestData['notification_id'];
                $success = $notificationModel->markAsRead($notificationId);
                
                if ($success) {
                    return $this->prepareResponse([
                        'success' => true,
                        'message' => 'Notification marked as read'
                    ]);
                } else {
                    return $this->prepareResponse([
                        'success' => false,
                        'message' => 'Notification not found or could not be updated'
                    ]);
                }
            } else {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Either notification_id or mark_all parameter is required'
                ]);
            }

        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Archive notification(s)
     * POST /connector/bell-notification/archive
     * Body: { "notification_id": "string" } for single notification
     * Body: { "archive_all": true } for all notifications
     */
    public function archiveAction()
    {
        try {
            $notificationModel = $this->di->getObjectManager()->get(\App\Connector\Models\Notifications::class);
            $requestData = $this->getRequestData();

            if (isset($requestData['archive_all']) && $requestData['archive_all'] === true) {
                // Archive all notifications
                $modifiedCount = $notificationModel->archiveAllNotifications();
                
                return $this->prepareResponse([
                    'success' => true,
                    'message' => "Archived {$modifiedCount} notifications",
                    'data' => ['modified_count' => $modifiedCount]
                ]);
            } elseif (isset($requestData['notification_id'])) {
                // Archive single notification
                $notificationId = $requestData['notification_id'];
                $success = $notificationModel->archiveNotification($notificationId);
                
                if ($success) {
                    return $this->prepareResponse([
                        'success' => true,
                        'message' => 'Notification archived'
                    ]);
                } else {
                    return $this->prepareResponse([
                        'success' => false,
                        'message' => 'Notification not found or could not be archived'
                    ]);
                }
            } else {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Either notification_id or archive_all parameter is required'
                ]);
            }

        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
