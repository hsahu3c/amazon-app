<?php

namespace App\Core\Components;

use Phalcon\Events\Event;

class UserEvent extends \App\Core\Components\Base
{

    /**

     * Change user shop_status based on their app_status to:  active | inactive | block 
     * @param Event $event
     * @param $myComponent
     */

    public function afterStatusChange(Event $event, $myComponent, $data)
    {
        if (isset($data['level']) && $data['level'] == 'app') {
            $this->changeShopStatusAppWise($data);
            $this->changeUserStatusShopWise($data);
        } elseif (isset($data['level']) && $data['level'] == 'shop') {
            $this->changeUserStatusShopWise($data);
        }
    }


    public function changeShopStatusAppWise($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('user_details');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $aggregation = [
            ['$sort' => ['visibility' => 1]],
            [
                '$match' => [
                    '$or' => [
                        ['_id' => $data['userId']],
                        ['user_id' => $data['userId']],
                    ],
                    'shops.apps.app_status' => ['$exists' => true]
                ]
            ],
            ['$unwind' => '$shops'],
            ['$unwind' => '$shops.apps'],
            [
                '$group' => [
                    '_id'       => '$shops.apps.app_status',
                    'count' => ['$sum' => 1],
                ]
            ],

        ];

        $out = $collection->aggregate($aggregation, $options)->toArray();

        if ($out) {
            $max = 0;
            foreach ($out as $key => $value) {
                if ($value['_id'] === 'active') {
                    $status = 'active';
                    break;
                } else {
                    if ($max < $value['count']) {
                        $max = $value['count'];
                        $status = $value['_id'];
                    }
                }
            }
        }

        if ($status == 'uninstall') $status = 'inactive';
        try {
            $collection->updateOne(
                [

                    "user_id" => $data['userId'],

                ],
                [
                    '$set' => [
                        'shops.$[shop].shop_status' => $status
                    ]
                ],
                [
                    'arrayFilters' => [
                        [
                            'shop._id' => $data['shopId'],
                        ],
                    ]
                ]

            );
        } catch (\Exception $e) {
            // echo $e->getMessage();
        }
    }


    /**
     * Function to change user status based on their shop status to: active | inactive | block
     *
     * @param [type] $data
     * @return void
     */
    public function changeUserStatusShopWise($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('user_details');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $aggregation = [
            ['$sort' => ['visibility' => 1]],

            [
                '$match' => [
                    '$or' => [
                        ['_id' => $data['userId']],
                        ['user_id' => $data['userId']],
                    ],
                    'shops.shop_status' => ['$exists' => true]
                ]
            ],
            ['$unwind' => '$shops'],
            [
                '$group' => [
                    '_id'       => '$shops.shop_status',
                    'count' => ['$sum' => 1],
                ]
            ],

        ];

        $out = $collection->aggregate($aggregation, $options)->toArray();

        if ($out) {
            $max = 0;
            foreach ($out as $value) {
                if ($value['_id'] === 'active') {
                    $userStatus = 'active';
                    break;
                } else {
                    if ($max < $value['count']) {
                        $max = $value['count'];
                        $userStatus = $value['_id'];
                    }
                }
            }
        }

        try {
            $collection->updateOne(
                [
                    "user_id" => $data['userId'],
                ],
                [
                    '$set' => [
                        'user_status' => $userStatus,
                    ]
                ]
            );
        } catch (\Exception $e) {
            // echo $e->getMessage();
        }
    }
}
