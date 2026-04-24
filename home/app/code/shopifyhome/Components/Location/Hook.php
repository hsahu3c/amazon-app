<?php
/**
 * Created by PhpStorm.
 * User: cedcoss
 * Date: 7/7/22
 * Time: 10:59 PM
 */

namespace App\Shopifyhome\Components\Location;

use App\Shopifyhome\Components\Core\Common;

class Hook extends Common
{

    public function deleteQueueLocationWebhook($data)
    {
        $deleteLocation = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Shop\Hook::class)->deleteLocation($data);

        return $deleteLocation;

    }

    public function updateQueueLocationWebhook($data)
    {
        $logFile = 'shopify' . DS . $this->di->getUser()->id . DS .
            'locations' . DS . date("Y-m-d") . DS . 'updatelocation.log';
        $this->di->getLog()->logContent('Starting updateQueueLocationWebhook Data=> '
            . json_encode($data), 'info', $logFile);
        if (empty($data['shop_id']) || empty($data['marketplace']) || empty($data['data']['id'])) {
            $this->di->getLog()->logContent('Missing Required Parameters', 'info', $logFile);
            return ['success' => false, 'message' => 'Missing Required Parameters'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('user_details');
        $locationId = $data['data']['id'];
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $locationData = $collection->findOne(
            [
                'user_id' => $userId,
                'shops' => [
                    '$elemMatch' => [
                        '_id' => $data['shop_id'],
                        'marketplace' => $data['marketplace'],
                        'warehouses' => [
                            '$elemMatch' => [
                                'id' => (string)$locationId
                            ]
                        ]
                    ]
                ]
            ],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        );
        if (!empty($locationData)) {
            $this->di->getLog()->logContent('Location data found', 'info', $logFile);
            $locationUpdate = $this->di->getObjectManager()
                ->get(\App\Shopifyhome\Components\Shop\Hook::class)->addAndUpdateLocation($data);
        } else {
            $this->di->getLog()->logContent('Location data not found', 'info', $logFile);
            return ['success' => true, 'message' => 'Location data not found'];
        }

        $this->di->getLog()->logContent('addAndUpdateLocation Response=> '
        . json_encode($locationUpdate), 'info', $logFile);
        return $locationUpdate;
    }

    public function createQueueLocationWebhook($data)
    {
        $createLocation = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Shop\Hook::class)->addAndUpdateLocation($data);

        return $createLocation;
    }
}