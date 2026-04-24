<?php
namespace App\Connector\Test\Models;
use App\Core\Components\UnitTestApp;
use App\Connector\Models\Notifications;

class NotificationsTest extends UnitTestApp {
    public function testClearAllNotifications(): void {
        $notificationModel = $this->di->getObjectManager()->get(Notifications::class);
        $queryParams = [
            'filter' => [
                'price' => [
                    8 => '100', // 8 => 100
                    9 => '500' // 9 => 500
                ],
                'category' => [
                    1 => 'electronics' // 1 => electronics
                ],
                'tags' => [
                    10 => 'new,featured,sale' // 10 => new,featured,sale
                ],
                'available' => [
                    12 => 'true' // 12 => true
                ]
            ]
        ];
        $response = $notificationModel->clearAllNotifications("1233", '123', "test", $queryParams['filter']);
        print_r($response);
        $this->assertArrayHasKey('available',$response);
    }
}