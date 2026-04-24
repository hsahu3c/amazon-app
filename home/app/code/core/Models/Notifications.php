<?php

namespace App\Core\Models;


class Notifications extends BaseMongo
{
    public $port = '10000';
    public $socket = '';
    protected $table = 'notifications';
    public function getNotificationsOfUser()
    {
        $userId = $this->di->getUser()->id;
        $allUserNotifications = Notifications::find(
            [
                [
                    "user_id" => $userId,

                ],
                'sort' => [
                    'created_at' => 1,
                ],
                'limit' => 100,
            ]

        );
        if (count($allUserNotifications)) {
            return ['success' => true, 'message' => 'All notifications', 'data' => $allUserNotifications];
        } else {
            return ['success' => true, 'code' => 'no_notifications', 'message' => 'No notifications', 'data' => []];
        }
    }

    public function updateNotificationStatus($notificationDetails)
    {
        $userId = $this->di->getUser()->id;
        $notificationId = $notificationDetails['id'];
        $notification = Notifications::findFirst([["_id" => $notificationId]]);
        $notification->seen = true;
        if ($notification->save()) {
            $notify = new Notifications;
            $notify->sendMessageToClient($userId);
            return ['success' => true, 'message' => 'Notification seen', 'code' => 'notification_seen'];
        } else {
            $errors = implode(',', $notification->getMessages());
            return ['success' => false, 'message' => 'Something went wrong', 'code' => $errors];
        }
    }

    public function updateMassNotificationStatus($notificationDetails)
    {
        $userId = $this->di->getUser()->_id;
        $count = 1;
        $ids = [];
        foreach ($notificationDetails as $value) {
            $ids = $value['_id'];
            $count++;
        }
        $result = $this->getCollection()->findAndModify([
            "query" => [
                "_id" => ['$in' => $ids]
            ],
            "update" => [
                '$set' => [
                    "seen" => true
                ]
            ],
            "upsert" => true
        ]);
        $status = $result->getModifiedCount();
        if ($status) {
            $notify = new Notifications;
            $notify->sendMessageToClient($userId);
            return [
                'success' => true,
                'message' => 'Notifications seen',
                'code' => 'notifications_seen'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Something went wrong',
                'code' => 'something_went_wrong'
            ];
        }
    }

    public function clearAllNotifications()
    {
        $userId = $this->di->getUser()->_id;
        $response = $this->getConnection()->deleteMany(["user_id" => $userId]);
        $status = $response->getDeletedCount();
        $this->sendMessageToClient($userId);
        if ($status) {
            return ['success' => true, 'message' => 'All notifications cleared successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to clear all notifications'];
        }
    }

    public function clearSeenNotifications()
    {
        $response = $this->getConnection()->deleteMany(["seen" => true]);
        $status = $response->isAcknowledged();

        return $status;
    }

    public function sendMessageToClient($userId)
    {
        echo "<h2>TCP/IP Connection</h2>\n";
        /* Get the port for the WWW service. */
        $servicePort = $this->port;
        $address = $this->di->getConfig()->server_ip;
        echo "Server address => " . $address;
        $context = stream_context_create();
        // local_cert must be in PEM format
        stream_context_set_option(
            $context,
            'ssl',
            'local_cert',
            '/var/www/engine-cert-keys/fullchain2.pem'
        );
        stream_context_set_option(
            $context,
            'ssl',
            'local_pk',
            '/var/www/engine-cert-keys/privkey2.pem'
        );
        stream_context_set_option(
            $context,
            'ssl',
            'allow_self_signed',
            false
        );
        stream_context_set_option(
            $context,
            'ssl',
            'verify_peer',
            false
        );
        // Create the server socket
        $socket = stream_socket_client(
            'ssl://' . $address . ':' . $servicePort,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );
        /* Create a TCP/IP socket. */
        if ($socket === false) {
            echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
        } else {
            echo "OK.\n";
        }
        echo "Attempting to connect to '$address' on port '$servicePort'...";
        $in = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n";
        $in .= "Host: 192.168.0.222\r\n";
        $in .= "userId: $userId\r\n\r\n";
        echo "Sending HTTP HEAD request...";
        fwrite($socket, $in);
        echo "OK.\n";
        echo "Closing socket...";
        fclose($socket);
        echo "Closed.\n\n";
    }
}
