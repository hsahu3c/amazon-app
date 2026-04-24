<?php

namespace App\Amazon\Components;

use App\Core\Components\Base;
use App\Core\Components\Staff\Staff;
use Phalcon\Events\Event;

class HandleEvent extends Base
{
    /**
     * @param $myComponent
     * @param $eventData
     */
    public function beforeCommenceHomeAuthAction($event, $myComponent, $eventData): void
    {
        $this->di->getLog()->logContent(
            'remote_data = ' . print_r($eventData, true) . PHP_EOL,
            'info',
            'beforeCommenceAuthAmazonToken.log'
        );

        if (!isset($eventData['success']) && !$eventData['success'] || empty($eventData['decoded_state'])) {
            return;
        }

        $decodedTokenData = $eventData['decoded_state'];

        if (isset($decodedTokenData['data']['marketplace']) && $decodedTokenData['data']['marketplace'] != 'amazon') {
            return;
        }

        if (!empty($decodedTokenData['data']['is_update']) && !empty($decodedTokenData['data']['renewal_time'])) {

            $mongoObj = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsColl = $mongoObj->getCollection('user_details');

            $userFilter = [
                'shops' => [
                    '$elemMatch' => [
                        'remote_shop_id' => $decodedTokenData['data']['shop_id'] ?? '',
                        'marketplace' => $decodedTokenData['data']['marketplace'] ?? '',
                    ]
                ]
            ];
            $options = [];
            $userDetails = $userDetailsColl->find($userFilter)->toArray();
            if (empty($userDetails)) {
                //log
                return;
            }

            $userId = $userDetails['user_id'];
            $updatedShops = [];
            $reauthKeyRemove = false;
            foreach ($userDetails as $userDetail) {
                foreach ($userDetail['shops'] as $shop) {
                    if (
                        isset($shop['remote_shop_id'])
                        && $shop['remote_shop_id'] == $decodedTokenData['data']['shop_id']
                    ) {
                        isset($shop['reauth_required']) && $reauthKeyRemove = true;
                        $updatedShops[] = $shop['_id'];
                    }
                }
            }

            $configCollection = $mongoObj->getCollection('config');
            $filter = [
                'user_id' => $userId,
                [
                    '$or' => [
                        [
                            'source' => $decodedTokenData['data']['marketplace'],
                            'source_shop_id' => ['$in' => $updatedShops],
                        ],
                        [
                            'target' => $decodedTokenData['data']['marketplace'],
                            'target_shop_id' => ['$in' => $updatedShops],
                        ],
                    ]
                ],
                'group_code' => 'feedStatus',
                'key' => 'accountStatus',
                'value' => 'expired'
            ];
            $update = [
                '$set' => [
                    'value' => 'active',
                ],
                // '$unset' => [
                //     'error' => true, //discuss regarding this
                // ]
            ];
            $response = $configCollection->updateMany($filter, $update);
            if ($reauthKeyRemove) {
                $userDetailsColl->updateOne(['user_id' => $userId, 'shops.remote_shop_id' => $decodedTokenData['data']['shop_id'], 'shops.marketplace' => 'amazon'], ['$unset' => ['shops.$.reauth_required' => 1]]);

            }
            $this->di->getLog()->logContent(
                'db_response = ' . print_r($eventData, true) . PHP_EOL,
                'info',
                'beforeCommenceAuthAmazonToken.log'
            );
        }
    }

    public function loginAfter($event, $myComponent, $data = []): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $seller = $collection->findOne(['user_id' => $data['user_id'], 'shops' => ['$exists' => true]], $options);
        if (!empty($seller)) {
            $params = [
                'user_id' => $seller['user_id'],
                'seller_username' => $seller['username'],
                'staff_role' => $seller['admin']
            ];
            $staffObj = $this->di->getObjectManager()->get(Staff::class);
            $response = $staffObj->createStaff($params);
            $this->di->getLog()->logContent(
                'staff create response = ' . print_r($response, true) . PHP_EOL,
                'info',
                'amazon' . DS . 'create_staff.log'
            );
        }

    }
}
