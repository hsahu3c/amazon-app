<?php

namespace App\Amazon\Components;

use App\Amazon\Components\Bulletin\Triggers;
use App\Core\Components\Base;
use App\Amazon\Components\Bulletin\Bulletin;
use App\Amazon\Components\Common\Common;
use Phalcon\Events\Event;


class BulletinEvent extends Base
{
    /**
     * @param $myComponent
     * @param $data
     */
    public function orderFail($event, $myComponent, $data): void
    {
        $notificationData['user_id'] = $data['user_id'];
        $notificationData['type'] = 'bulletin_order_sync';
        $notificationData['processType'] = 'order';
        $notificationData['source_shop_id'] = $data['source_shop_id'];
        $notificationData['target_shop_id'] = $data['target_shop_id'];
        $notificationData['app_tag'] = $data['app_tag'] ?? 'default';
        $this->notifyMerchant($notificationData);
    }

    public function orderCancelFail($event, $myComponent, $data): void
    {
        $notificationData['user_id'] = $data['user_id'];
        $notificationData['type'] = 'bulletin_order_cancel';
        $notificationData['processType'] = 'order';
        $notificationData['source_shop_id'] = $data['source_shop_id'];
        $notificationData['target_shop_id'] = $data['target_shop_id'];
        $notificationData['app_tag'] = $data['app_tag'] ?? 'default';
        $this->notifyMerchant($notificationData);
    }

    public function orderRefundFail($event, $myComponent, $data): void
    {
        $notificationData['user_id'] = $data['user_id'];
        $notificationData['type'] = 'bulletin_order_refund';
        $notificationData['processType'] = 'order';
        $notificationData['source_shop_id'] = $data['source_shop_id'];
        $notificationData['target_shop_id'] = $data['target_shop_id'];
        $notificationData['app_tag'] = $data['app_tag'] ?? 'default';
        $this->notifyMerchant($notificationData);
    }

    public function orderShipmentFail($event, $myComponent, $data): void
    {
        $notificationData['user_id'] = $data['user_id'];
        $notificationData['type'] = 'bulletin_order_shipment';
        $notificationData['processType'] = 'order';
        $notificationData['source_shop_id'] = $data['source_shop_id'];
        $notificationData['target_shop_id'] = $data['target_shop_id'];
        $notificationData['app_tag'] = $data['app_tag'] ?? 'default';
        $this->notifyMerchant($notificationData);
    }

    public function warehouseDisabled($event, $myComponent, $data): void
    {
        // $notificationData['user_id'] = $data['user_id'];
        // $notificationData['type'] = 'bulletin_enable_warehouse';
        // $notificationData['processType'] = 'warehouse';
        // $notificationData['source_shop_id'] = $data['source_shop_id'];
        // $notificationData['target_shop_id'] = $data['target_shop_id'];
        // $notificationData['app_tag'] = $data['app_tag'] ?? 'default';
        $bulletinData = [
            'user_id' => $data['user_id'],
            'home_shop_id' => $data['target_shop_id'],
            'bulletinItemType' => 'ENABLE_WAREHOUSE',
            'bulletinItemParameters' => [],
            'source_shop_id' => $data['source_shop_id'] ?? '',
            'process' => 'warehouse'
        ];
        $this->di->getObjectManager()->get(Bulletin::class)->createBulletin($bulletinData);
        // $this->notifyMerchant($notificationData);
    }

    public function inventoryOutOfStock($event, $myComponent, $data): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $listingCollection = $mongo->getCollection('amazon_listings');
        $query = [
            'user_id' => $data['user_id'],
            'quantity' => (string)0,
            'shop_id' => $data['target_shop_id']
        ];
        $count = $listingCollection->countDocuments($query);
        if ($count > 0) {
            $bulletinData = [
                'user_id' => $data['user_id'],
                'home_shop_id' => $data['target_shop_id'],
                'bulletinItemType' => 'INVENTORY_OUT_OF_STOCK',
                'bulletinItemParameters' => ['numberOfProducts' => $count],
                'source_shop_id' => $data['source_shop_id'] ?? ''
            ];
            $this->di->getObjectManager()->get(Bulletin::class)->createBulletin($bulletinData);
        }
    }

    public function notifyMerchant($notificationData)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $bulletinCronCollection = $mongo->getCollection('bulletin_cron');
        $entry = $bulletinCronCollection->findOne([
            'user_id' => $notificationData['user_id'],
            'type' => $notificationData['type'],
            'processType' => $notificationData['processType']
        ]);
        if (!empty($entry)) {
            return ['success' => false, 'message' => 'Already added to send'];
        }
        $appTag = $notificationData['app_tag'] ?? 'default';
        if ($appTag == 'default') {
            $appTag = $this->getAppTag($notificationData['user_id'],  $notificationData['source_shop_id']);
        }

        $entryToAdd = [
            'user_id' => $notificationData['user_id'],
            'type' => $notificationData['type'],
            'processType' => $notificationData['processType'],
            'created_at' => date('c'),
            'source_shop_id' => $notificationData['source_shop_id'],
            'target_shop_id' => $notificationData['target_shop_id'],
            'app_tag' => $appTag
        ];
        $bulletinCronCollection->insertOne($entryToAdd);
        $handlerData = [
            'type' => 'full_class',
            'method' => 'triggerBulletin',
            'class_name' => Triggers::class,
            'queue_name' => 'amazon_bulletin_triggers',
            'user_id' => $notificationData['user_id'],
            'data' => $entryToAdd,
            'marketplace' => 'amazon',
            'shop_id' => $notificationData['source_shop_id'],
            'delay' => 900,//for 15 minutes
            "appTag" => $appTag,
            "action" => "amazon_bulletin_triggers"
        ];
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $sqsHelper->pushMessage($handlerData);
        return ['success' => true, 'message' => 'Added to send'];
    }

    public function getAppTag($userId, $shopId)
    {
        if ($userId != $this->di->getUser()->id) {
            $res = $this->di->getObjectManager()->create(Common::class)->setDiForUser($userId);
            if (!$res['success']) {
                return 'default';
            }
        }

        foreach ($this->di->getUser()->shops as $shop) {
            if ($shop['_id'] == $shopId) {
                return $shop['apps'][0]['code'] ?? 'default';
            }
        }
    }
}