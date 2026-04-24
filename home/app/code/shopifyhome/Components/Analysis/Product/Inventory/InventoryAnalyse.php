<?php

namespace App\Shopifyhome\Components\Analysis\Product\Inventory;

use App\Shopifyhome\Components\Core\Common;

class InventoryAnalyse extends Common
{
    public function validateInventory(): void{

        $params = $this->di->getRequest()->get();
        $skip = (int) $params['skip'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("user_details")->getCollection();


        $result = $collection->find([], ['limit' =>20, 'skip' => $skip *20])->toArray();
        $users=json_decode(json_encode($result), true);

        $userids=[];


        foreach ($result as $user) {
            array_push($userids, $user['user_id']);
        }


        foreach ($userids as $userid){
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $this->_collection = $mongo->setSource("product_container_".$userid)->getPhpCollection();
            $options = [];
            $pipeline = [
                [
                    '$unwind' => [
                        'path' => '$variants'
                    ]
                ],
                [
                    '$unwind' => [
                        'path' => '$variants.locations',
                        'preserveNullAndEmptyArrays' => true
                    ]
                ],
                [
                    '$match' => [
                        'variants.locations' => [
                            '$exists' => false
                        ]
                    ]
                ]
            ];

            $cursor = $this->_collection->aggregate($pipeline, $options)->toArray();

            $cursor=json_decode(json_encode($cursor), true);
            if(count($cursor) != 0){
                print_r($userid.",");
            }
        }
    }
}