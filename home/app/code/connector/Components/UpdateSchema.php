<?php

namespace App\Connector\Components;

class UpdateSchema extends \App\Core\Components\Base
{

    public function init($param = 1) {
        if ($param == 1) return $this->setStatus();

        if ($param == 2) return $this->updateSources();
    }

    public function updateSources()
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $usersCollection = $mongo->getCollectionForTable('user_details');


        $response = $usersCollection->find(
            [
                'shops.targets' => ['$exists' => true],
                'shops.apps.0' => ['$exists' => true],
                'shops.apps.1' => ['$exists' => false]
            ],
            [
                'projection' => ['shops' => 1, '_id' => 0]
            ]
        )->toArray();


        foreach ($response as $res) {

            foreach ($res['shops'] as $r) {

                if (isset($r['domain'], $r['apps'])) {
                    $code = $r['apps'][0]['code'];

                    if (isset($r['targets'])) {

                        foreach ($r['targets'] as $target) {
                            $targetShopId = $target['shop_id'];
                            $id = $r['id'];
                            $marketplace = $r['marketplace'];

                            $response = $usersCollection->updateMany(

                                ['shops._id' => $targetShopId],
                                [
                                    '$set' => [
                                        'shops.$[shop].sources.$[index].app_code' => $code,
                                    ],
                                ],
                                [
                                    'arrayFilters' => [
                                        ['shop._id' => $targetShopId],
                                        ['index.code' => $marketplace]
                                    ]
                                ]

                            );

                            if ($response->getModifiedCount()) {
                                return ['success' => true, 'message' => 'Shop_sources schema updated successfully!'];
                            }

                            return ['success' => false, 'message' => 'Trouble updating schema!'];

                        }
                    }
                }
            }
        }
    }


    public function setStatus() {

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $usersCollection = $mongo->getCollectionForTable('user_details');

        $response=$usersCollection->updateMany( 
                [
                    'shops' => ['$exists' => true],
                    'shops.warehouses' => ['$exists' => true],
                    'shops.shop_status' => ['$exists' => false],
                    'shops.apps' => ['$exists' =>true],
                    'shops.app_status' => ['$exists' => false],
                    'user_status' => ['$exists' => false],
                ],
                ['$set' => [
                    'user_status' => 'active',
                    'shops.$[].shop_status' => 'active',
                    'shops.$[].apps.$[].app_status' => 'active',
                    ]
                ]
            );

        if ($response->getModifiedCount()) {
            return ['success' => true, 'message' => 'User, Shop and app status are set.'];
        }

        return ['success' => false, 'message' => 'Trouble setting status field!'];

    }
}
