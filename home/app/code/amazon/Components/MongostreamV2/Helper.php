<?php
namespace App\Amazon\Components\MongostreamV2;

use Phalcon\Events\Event;

class Helper extends \App\Core\Components\Base
{
    public $_userShopDetails = [];

    /**
     * @param $myComponent
     */
    public function MongoWebhooksV2(Event $event, $myComponent, $data)
    {
        if($this->canSkipMessage($data)) {
            return true;
        }
        // $this->di->getLog()->logContent(print_r($data, true), 'info', 'himanshu-12-Aug.log');

        if (isset($data['appCode'])) {
            $this->di->getAppCode()->set($data['appCode']);
        }

        if (isset($data['appTag'])) {
            $this->di->getAppCode()->setAppTag($data['appTag']);
        }

        $formatedData = $this->getFormatedData($data['data']);
        if(isset($formatedData['formatedData'])) {
            foreach ($formatedData['formatedData'] as $key => $value) {
                if($key === 'price') {
                    $this->priceSync($value);
                }
            }
        }
    }

    public function start($data): void
    {
        $formatedData = $this->getFormatedData($data['data']);
        if(isset($formatedData['formatedData'])) {
            foreach ($formatedData['formatedData'] as $key => $value) {
                if($key === 'price') {
                    $this->priceSync($value);
                }
            }
        }
    }

    private function getFormatedData(array $streamData)
    {
        $formatedData = [];
    
        foreach ($streamData as $data) {
            if(!isset($formatedData[$data['key']][$data['user_id']][$data['shop_id']][$data['source_product_id']])) {
                $formatedData[$data['key']][$data['user_id']][$data['shop_id']][$data['source_product_id']] = $data;
            }
            else {
                $oldData = $formatedData[$data['key']][$data['user_id']][$data['shop_id']][$data['source_product_id']];

                $oldTime = strtotime($oldData['clusterTime']);
                $newTime = strtotime($data['clusterTime']);

                // in case if we want all the fields that were updated/deleted then in that case we should merge the old data and new data instead of replacing it.
                if($newTime > $oldTime) {
                    $formatedData[$data['key']][$data['user_id']][$data['shop_id']][$data['source_product_id']] = $data;
                }
            }
        }
    
        return [
            'formatedData' => $formatedData
        ];
    }

    public function priceSync($streamData)
    {
        if (!empty($streamData)) {
            // $nextPrevData = $streamData['formatedData'];
            foreach($streamData as $userId => $userWiseData) {
                $userModel = \App\Core\Models\User::findFirst([['_id' => $userId]]);
                if($userModel) {
                    $this->di->getObjectManager()->get('\App\Connector\Models\MongostreamV2\Helper')
                    ->setUserInDi($userModel);
                } else {
                    continue;
                }
                foreach($userWiseData as $shopId => $productData) {
                    $params = [];
                    $shopInfo = $this->getShopTypeAndLinkedIds($userId, $shopId);
                    if (!empty($shopInfo)) {
                        if ($shopInfo['type'] == 'source') {
                            foreach($shopInfo['targets'] as $target) {
                                if (isset($target['disconnected']) && $target['disconnected']) {
                                    continue;
                                }

                                if(!empty($productData['shop_ids'])) {
                                    $allowedShopIds = array_map('strval', $productData['shop_ids']);
                                    if(!in_array(strval($target['shop_id']), $allowedShopIds)) {
                                        continue;
                                    }
                                }
                                
                                $sourceProductIds = array_map('strval', array_keys($productData));
                                $params = [
                                    'target' => [
                                        'marketplace' => $target['code'],
                                        'shopId' => $target['shop_id']
                                    ],
                                    'source' => [
                                        'marketplace' => $shopInfo['marketplace'],
                                        'shopId' => $shopInfo['id']
                                    ],
                                    'operationType' => 'price_sync',
                                    'user_id' => $userId,
                                    'source_product_ids' => $sourceProductIds,
                                     'process_type'=> 'automatic',
                                     'usePrdOpt' => true,
                                    'activePage' => 1,
                                    'filter' => [
                                        'items.status' => [
                                            "10" => [
                                                "Inactive",
                                                "Incomplete",
                                                "Active",
                                                "Submitted"
                                            ]
                                            ],
                                            // 'items.source_product_id' => [
                                            //     "10" => $sourceProductIds
                                            // ]
                                    ],
                                    'useRefinProduct' => true
                                ];

                                // print_r($params);die('triggered successfully');
                                $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing')->startSync($params);
                            }
                        } else {
                            foreach($shopInfo['sources'] as $source) {
                                if (isset($source['disconnected']) && $source['disconnected']) {
                                    continue;
                                }
                                $sourceProductIds = array_map('strval', array_keys($productData));
                                $params = [
                                    'source' => [
                                        'marketplace' => $source['code'],
                                        'shopId' => $source['shop_id']
                                    ],
                                    'target' => [
                                        'marketplace' => $shopInfo['marketplace'],
                                        'shopId' => $shopInfo['id']
                                    ],
                                    'operationType' => 'price_sync',
                                    'user_id' => $userId,
                                    'source_product_ids' => $sourceProductIds,
                                    'process_type'=> 'automatic',
                                    'activePage' => 1,
                                    'filter' => [
                                        'items.status' => [
                                            "10" => [
                                                "Inactive",
                                                "Incomplete",
                                                "Active",
                                                "Submitted"
                                            ]
                                            ],
                                            // 'items.source_product_id' => [
                                            //     "10" => $sourceProductIds
                                            // ]
                                    ],
                                    'useRefinProduct' => true
                                ];
                                // print_r($params);die('triggered successfully2');
                                $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing')->startSync($params);
                            }
                        }
                    }
                }
            }
        }
    }

    public function getShopTypeAndLinkedIds($userId, $shopId)
    {
        if(isset($this->_userShopDetails[$userId][$shopId])){
            return $this->_userShopDetails[$userId][$shopId];
        }
        else {
            if($userId === $this->di->getUser()->id) {
                $shops = $this->di->getUser()->shops;
            }
            else {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $user_details = $mongo->getCollectionForTable('user_details');
                $userData = $user_details->findOne(
                    ['user_id' => $userId],
                    [
                        'projection' => ['shops.marketplace' => true, 'shops.targets' => true, 'shops._id' => true, 'user_id' => true], 
                        'typeMap' => ['root' => 'array', 'document' => 'array']
                    ]
                );
                if(!$userData) {
                    return false;
                } else {
                    $shops = $userData['shops'];
                }
            }

            $shopData = [];
            if (!empty($shops)) {
                foreach($shops as $shop) {
                    if (isset($shop['_id']) && ($shop['_id'] == $shopId)) {
                        if (isset($shop['targets'])) {
                            $shopData = [
                                'id' => $shop['_id'],
                                'marketplace' => $shop['marketplace'],
                                'targets' => $shop['targets'],
                                'type' => 'source'
                            ];
                        } else {
                            $shopData = [
                                'id' => $shop['_id'],
                                'marketplace' => $shop['marketplace'],
                                'sources' => $shop['sources'],
                                'type' => 'target'
                            ];
                        }
                    }
                }
            }

            $this->_userShopDetails[$userId][$shopId] = $shopData;
            return $this->_userShopDetails[$userId][$shopId];
        }

        return false;
    }

    public function canSkipMessage($sqsMessage)
    {
        $userId = $sqsMessage['data'][0]['user_id'];
        $sourceProductId = $sqsMessage['data'][0]['source_product_id'];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('product_container');
        // $options = [
        //     'typeMap' => ['root' => 'array', 'document' => 'array']
        // ];
        $uploadedProduct = $productCollection->count(
            [
                'user_id' => $userId,
                'source_product_id' => $sourceProductId,
                'target_marketplace' => 'amazon',
                'status' => ['$in' => ['Inactive', 'Incomplete', 'Active', 'Submitted']]
            ]
        );

        if($uploadedProduct) {
            return false;
        } else {
            return true;
        }
    }
}