<?php

/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Amazon
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Amazon\Components\Bulletin;

use App\Amazon\Components\Common\Common;
use App\Connector\Contracts\Sales\OrderInterface;
use App\Core\Components\Base as Base;
use App\Amazon\Components\Bulletin\Bulletin;

class Triggers extends Base
{
    public const BULLETIN_ORDER_SYNC = 'bulletin_order_sync';

    public const BULLETIN_ORDER_CANCEL = 'bulletin_order_cancel';

    public const BULLETIN_ORDER_SHIPMENT = 'bulletin_order_shipment';

    public const BULLETIN_ORDER_REFUND = 'bulletin_order_refund';

    public const BULLETIN_INVENTORY_OUT_OF_STOCK = 'bulletin_inventory_out_of_stock';

    public const BULLETIN_APP_MAINTAINANCE = 'bulletin_app_maintain';

    public function sendAppMaintainanceBulletin($rawBody)
    {
        if (!isset($rawBody['startDate'], $rawBody['startHour'], $rawBody['startMinute'], $rawBody['endDate'], $rawBody['endHour'], $rawBody['endMinute'])) {
            return ['success' => false, 'message' => 'Duration of the deployment is required!'];
        }
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $bulletinCronCollection = $mongo->getCollection('bulletin_cron');
        $entryExist = $bulletinCronCollection->count(['type' => 'bulletin_app_maintain']);
        if ($entryExist) {
            return ['success' => false, 'message' => 'The process is already initiated!'];
        }
        $entryToAdd = [
            'user_id' => 'all',
            'type' => 'bulletin_app_maintain',
            'processType' => 'app_maintainance',
            'created_at' => date('c'),
            'startDate' => $rawBody['startDate'],
            'startHour' => (int)$rawBody['startHour'],
            'startMinute' => (int)$rawBody['startMinute'],
            'endDate' => $rawBody['endDate'],
            'endHour' => (int)$rawBody['endHour'],
            'endMinute' => (int)$rawBody['endMinute'],
            'limit' => $rawBody['limit'] ?? 1000,
            'skip' => $rawBody['skip'] ?? 0,
            'app_tag' => $this->di->getAppCode()->getAppTag(),
        ];

        if (!empty($rawBody['test_user_ids'])) {
            $entryToAdd['test_user_ids'] = $rawBody['test_user_ids'];
        }

        $bulletinCronCollection->insertOne($entryToAdd);
        $handlerData = [
            'type' => 'full_class',
            'method' => 'triggerBulletin',
            'class_name' => \App\Amazon\Components\Bulletin\Triggers::class,
            'queue_name' => 'amazon_bulletin_triggers',
            'user_id' => $this->di->getUser()->id,
            'data' => $entryToAdd,
            'marketplace' => 'amazon',
            'delay' => 15,
            "appTag" => $this->di->getAppCode()->getAppTag(),
            "action" => "amazon_bulletin_triggers"
        ];
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $sqsHelper->pushMessage($handlerData);
        return ['success' => true, 'message' => 'The process is initiated successfully!'];
    }

    public function triggerBulletin($data): void
    {
        $logFile = 'bulletin/queue/'.date('Y-m-d').'.log';
        $this->di->getLog()->logContent(print_r('data: '.json_encode($data, true), true), 'info', $logFile);
        if (!empty($data['data']) && !empty($data['data']['type'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $bulletinCronCollection = $mongo->getCollection('bulletin_cron');
            $query = [];
            $bulkHandle = false;
            if (isset($data['data']['user_id']) && ($data['data']['user_id'] == 'all')) {
                $bulkHandle = true;
                $query = [
                    'type' => $data['data']['type'],
                    'user_id' => 'all'
                ];
            } else {
                $query = [
                    'type' => $data['data']['type'],
                    'user_id' => $data['data']['user_id']
                ];
            }

            $processEntry = $bulletinCronCollection->findOne($query, ["typeMap" => ['root' => 'array', 'document' => 'array']]);

            if (!empty($processEntry)) {
                if ($bulkHandle) {
                    $this->initiateBulkSend($processEntry);
                } else {
                    $this->initiateUserWiseBulletinSend($processEntry);
                }
            }
        }
    }

    public function initiateBulkSend($processEntry): void
    {
        if ($processEntry['type'] == self::BULLETIN_APP_MAINTAINANCE) {
            $this->initiateAppMaintainanceBulletin($processEntry);
        } elseif ($processEntry['type'] == self::BULLETIN_INVENTORY_OUT_OF_STOCK) {
                // $this->initiateInventoryOutOfStock($processEntry);
        }
    }

    public function initiateUserWiseBulletinSend($processEntry)
    {
        if ($this->di->getUser()->id != $processEntry['user_id']) {
            $res = $this->di->getObjectManager()->create(Common::class)->setDiForUser($processEntry['user_id']);
            if (!$res['success']) {
                return false;
            }
        }

        if (isset($processEntry['appTag']) && ($processEntry['appTag'] !== 'default') && ($this->di->getAppCode()->getAppTag() != $processEntry['appTag'])) {
            $this->di->getAppCode()->setAppTag($processEntry['appTag']);
        }

        $itemType = '';
        $queryParams = '';
        switch ($processEntry['type']) {
            case self::BULLETIN_ORDER_SYNC:
                $itemType = 'ORDER_SYNC_FAILED';
                $countRes = $this->getOrderCount('order', $processEntry['target_shop_id']);
                $queryParams = 'failed_order=true';
                break;
            case self::BULLETIN_ORDER_CANCEL:
                $itemType = 'ORDER_CANCELLATION_SYNC_FAILED';
                $countRes = $this->getOrderCount('cancel', $processEntry['target_shop_id']);
                $queryParams = 'failed_cancellation=true';
                break;
            case self::BULLETIN_ORDER_REFUND:
                $itemType = 'REFUND_SYNC_FAILED';
                $countRes = $this->getOrderCount('refund', $processEntry['target_shop_id']);
                $queryParams = 'failed_refund=true';
                break;
            case self::BULLETIN_ORDER_SHIPMENT:
                $itemType = 'SHIPMENT_SYNC_FAILED';
                $countRes = $this->getOrderCount('shipment', $processEntry['target_shop_id']);
                $queryParams = 'failed_shipment=true';
                break;
            case self::BULLETIN_INVENTORY_OUT_OF_STOCK:
                // $countRes = $this->getOrderCount('invenotroy', $data['data']['source_shop_id']);
                break;
            default:
            break;
        }

        $bulletinData = [];
        if (!empty($countRes) && isset($countRes['data']['count']) && ($countRes['data']['count'] > 0)) {
            $bulletinData = [
                'home_shop_id' =>  $processEntry['target_shop_id'] ?? null,
                'source_shop_id' => $processEntry['source_shop_id'] ?? null,
                'user_id' => $this->di->getUser()->id,
                'process' => $processEntry['processType'],
                'query_params' => $queryParams
            ];
            $bulletinData['bulletinItemType'] = $itemType;
            if ($itemType == 'SHIPMENT_SYNC_FAILED') {
                $bulletinData['bulletinItemParameters'] = ['numberOfShipments' => $countRes['data']['count']];
            } else {
                $bulletinData['bulletinItemParameters'] = ['numberOfOrders' => $countRes['data']['count']];
            }
        }

        if (!empty($bulletinData)) {
            $this->di->getObjectManager()->create(Bulletin::class)->createBulletin($bulletinData);
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $bulletinCronCollection = $mongo->getCollection('bulletin_cron');
        $bulletinCronCollection->deleteOne([
            'user_id' => $processEntry['user_id'],
            'type' => $processEntry['type']
        ]);
    }

    public function getOrderCount($process, $shopId)
    {
        $filter = [];
        $filter['filter']['user_id']['1'] = $this->di->getUser()->id;
        $filter['filter']['object_type']['1'] = 'source_order';
        $filter['filter']['marketplace']['1'] = 'amazon';
        $filter['filter']['marketplace_shop_id']['1'] = $shopId;
        if ($process == 'order') {
            $filter['filter']['targets.status']['1'] = 'failed';
            $filter['filter']['marketplace_status']['2'] = 'Canceled';
            $filter['filter']['targets.order_id']['10'] = false;
        } elseif ($process == 'shipment') {
            $filter['filter']['shipping_error']['10'] = true;
        } elseif ($process == 'cancel') {
            $filter['filter']['targets.cancellation_status']['1'] = 'Failed';
        } elseif ($process == 'refund') {
            $filter['filter']['refund_status']['3'] = 'Failed';
        }

        return $this->di->getObjectManager()->get(OrderInterface::class)->getCount($filter);
    }

    public function initiateAppMaintainanceBulletin($processEntry)
    {
        $logFile = 'bulletin/queue/bulletin_app_maintain/'.date('Y-m-d').'.log';
        $this->di->getLog()->logContent(print_r('processEntry: '.json_encode($processEntry, true), true), 'info', $logFile);
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $bulletinCronCollection = $mongo->getCollection('bulletin_cron');
        if (isset($processEntry['app_tag']) && ($processEntry['app_tag'] !== 'default')) {
            $this->di->getAppCode()->setAppTag($processEntry['app_tag']);
        } else {
            $this->di->getLog()->logContent(print_r('Invalid app_tag!', true), 'info', $logFile);
            return [
                'success' => false,
                'message' => 'app_tag is not valid!'
            ];
        }

        $addEntry = true;
        $usersData = $this->getUsers($processEntry);
        $this->di->getLog()->logContent(print_r('usersData count: '.count($usersData), true), 'info', $logFile);
        if (!empty($usersData)) {
            $bulletinComp = $this->di->getObjectManager()->create(Bulletin::class);
            $bulletinData = [
                'bulletinItemType' =>  'APP_UNDER_MAINTENENCE',
                'bulletinItemParameters' => ['startDate' => $processEntry['startDate'], 'endDate' => $processEntry['endDate'], 'startHour' => $processEntry['startHour'], 'startMinute' => $processEntry['startMinute'], 'endHour' => $processEntry['endHour'], 'endMinute' => $processEntry['endMinute']]
            ];
            foreach($usersData as $user) {
                $res = $this->di->getObjectManager()->create(Common::class)->setDiForUser($user['user_id']);
                if (!$res['success']) {
                    return false;
                }

                $bulletinData['user_id'] = $this->di->getUser()->id;
                foreach($user['shops'] as $shop) {
                    $bulletinData['home_shop_id'] = $shop['_id'];
                    $bulletinData['source_shop_id'] = $shop['sources'][0]['shop_id'] ?? null;
                }

                $bulletinComp->createBulletin($bulletinData);
            }
        } else {
            $addEntry = false;
            $this->di->getLog()->logContent(print_r('no more users to process so deleting the entry!', true), 'info', $logFile);
            $bulletinCronCollection->deleteOne(['type' => $processEntry['type']]);
        }

        if ($addEntry) {
            $processEntry['skip'] = $processEntry['skip'] + 1;
            $processEntry['updated_at'] = date('c');
            $bulletinCronCollection->updateOne(
                ['type' => $processEntry['type']],
                ['$set' => $processEntry],
                ['upsert' => true]
            );
            //add the code to delete entry from sqs and we also need to modify amazon's comsume function based on that, for now we can handle the visibility timeout for the queue
            $handlerData = [
                'type' => 'full_class',
                'method' => 'triggerBulletin',
                'class_name' => \App\Amazon\Components\Bulletin\Triggers::class,
                'queue_name' => 'amazon_bulletin_triggers',
                'user_id' => $this->di->getUser()->id,
                'data' => $processEntry,
                'marketplace' => 'amazon',
                'delay' => 15,//delay of 15 seconds added
                "appTag" => $this->di->getAppCode()->getAppTag(),
                "action" => "amazon_bulletin_triggers"
            ];
            $this->di->getLog()->logContent(print_r('handlerData to resend: '.json_encode($handlerData, true), true), 'info', $logFile);
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $sqsHelper->pushMessage($handlerData);
        }

        return true;
    }

    public function getUsers($processEntry)
    {
        if (!empty($processEntry) && ($processEntry['user_id'] == 'all')) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $limit = $processEntry['limit'] ?? 1000;
            $skip = 0;
            (isset($processEntry['skip']) && (int)$processEntry['skip']) && $skip = (int)$processEntry['skip'];
            $skip = $limit * $skip;
            $matchQuery = [
                'shops.marketplace' => 'amazon',
                'shops.apps' => [
                    '$elemMatch' => [
                        'app_status' => 'active'
                    ]
                ]
            ];
            !empty($processEntry['test_user_ids']) && $matchQuery['user_id'] = ['$in' => $processEntry['test_user_ids']];
            /**
             * this level of pipeline is added so that we will process every user chunk wise without skipping for any user since user will have mixed users with active account
             */
            $pipeline = [
                [
                    '$match' => $matchQuery
                ],
                ['$sort' => ['_id' => 1]],
                ['$skip' => $skip],
                ['$limit' => $limit],
                [
                    '$unwind' => '$shops'
                ],
                [
                    '$match' => $matchQuery
                ],
                [
                    '$project' => [
                        'user_id' => 1,
                        'shops' => 1
                    ]
                ],
                [
                    '$group' => [
                        '_id' => '$_id',
                        'user_id' => ['$first' => '$user_id'],
                        'shops' => [
                            '$push' => '$shops'
                        ]
                    ]
                ]
            ];
            $userDetails = $mongo->getCollection('user_details');
            return $userDetails->aggregate($pipeline, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
        }

        return [];
    }
}