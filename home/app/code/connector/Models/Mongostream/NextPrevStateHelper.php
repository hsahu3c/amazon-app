<?php

namespace App\Connector\Models\Mongostream;

use App\Core\Models\BaseMongo;
use Aws\S3\S3Client;

class NextPrevStateHelper extends BaseMongo
{
    public function processNextPrevEvent($data)
    {
        return $this->formatNextPrevData($data);
    }

    private function formatNextPrevData(array $data)
    {   $nextPrevData = $this->getNextPrevState($data['data']['data'] ?? [],$data['key']);
        $messageArray = [];
        try{
            if ($data['type'] === 's3Message') {
                $s3Client = new S3Client(include BP . '/app/etc/aws.php');
                $result = $s3Client->getObject(array(
                    'Bucket' => $data['bucket'],
                    'Key' => $data['id'],
                ));
                $messageArray = json_decode($result['Body'], true);
                $nextPrevData = $this->getNextPrevState($messageArray['Detail']['data'] ?? [],"s3message");
            }
        }catch(\Exception $e){
            $this->di->getLog()->logContent('Exception: '.$e->getMessage(), 'info', 'change-stream-exception.log');
            return true;
        }

        if (count($nextPrevData) == 0) {
            return true;
        }

        $users = $this->di->getUser()->toArray();
        $marketplaces = $this->getMarketPlacesWithShopIds($users['shops'] ?? []);
        return $this->formatNextPrevProducts($nextPrevData['nextPrevData'],$nextPrevData['shopIds'], $nextPrevData['containerIds'], $marketplaces);
    }

    private function getNextPrevState($data,$key="s3"): array
    {
        $nextPrevState = [];
        $containerIds = [];
        $shopIds = [];
        foreach ($data as $val) {
            $val['update_type'] = $key;
            if (empty($nextPrevState[$val['currentValue']['shop_id']][$val['currentValue']['source_product_id']])) {
                $nextPrevState[$val['currentValue']['shop_id']][$val['currentValue']['source_product_id']] = $val;
                !in_array($val['currentValue']['container_id'], $containerIds) && $containerIds[] = $val['currentValue']['container_id'];
                !in_array($val['currentValue']['shop_id'],$shopIds) && $shopIds[]=$val['currentValue']['shop_id']; 
                if(isset($val['currentValue']['source_shop_id']) && !in_array($val['currentValue']['source_shop_id'],$shopIds)){
                    $shopIds[]=$val['currentValue']['source_shop_id'];
                }
            } else {
                if($val['operationType']==='insert'){
                    $val['beforeValue']=[];
                }else{
                    foreach ($val['beforeValue'] as $k => $v) {
                       $shop_id = $val['currentValue']['shop_id'];
                        $source_product_id = $val['currentValue']['source_product_id'];
                        if(isset($nextPrevState[$shop_id][$source_product_id]['beforeValue'][$k]) && !empty($nextPrevState[$shop_id][$source_product_id]['beforeValue'][$k]))
                        {
                            if (gettype($nextPrevState[$shop_id][$source_product_id]['beforeValue'][$k]) === 'string') {
                                $nextPrevState[$val['currentValue']['shop_id']][$val['currentValue']['source_product_id']]['beforeValue'][$k] = [$nextPrevState[$val['currentValue']['shop_id']][$val['currentValue']['source_product_id']]['beforeValue'][$k]];
                            }

                            if(is_array($nextPrevState[$shop_id][$source_product_id]['beforeValue'][$k]))
                            {
                                $nextPrevState[$shop_id][$source_product_id]['beforeValue'][$k][] = $v;
                            }
                        }
                    }
                }

                foreach ($val['currentValue'] as $k => $v) {
                    $nextPrevState[$val['currentValue']['shop_id']][$val['currentValue']['source_product_id']]['currentValue'][$k] = $v;
                }
            }
        }

        return ['containerIds' => $containerIds,'shopIds'=>$shopIds, 'nextPrevData' => $nextPrevState];
    }

    /**
     * @return array<mixed, array<'ids'|'marketplace'|'type', mixed>>
     */
    private function getMarketPlacesWithShopIds($shops): array
    {
        $shopsArray = [];
        foreach ($shops as $v) {
            $shopsArray[$v['_id']] = ["marketplace" => $v['marketplace'], "type" => "NA", "ids" => []];
            if (isset($v['targets']) || isset($v['sources'])) {
                if (isset($v['targets'])) {
                    $shopsArray[$v['_id']]['type'] = "source";
                } else {
                    $shopsArray[$v['_id']]['type'] = "target";
                }

                foreach ($v[isset($v['targets']) ? "targets" : "sources"] as $val) {
                    if(isset($val['shop_id']))
                        $shopsArray[$v['_id']]['ids'][] = $val['shop_id'];
                }
            }
        }

        return $shopsArray;
    }

    // private function formatNextPrevProducts($nextPrev,$shopIds, $containerIds, array $marketplaces): bool
    // {
    //     $allProducts = $this->getContainerIdsFormattedProducts($containerIds,$shopIds);
    //     $profiles = [];
    //     foreach ($nextPrev as $key => $value) {
    //         if (isset($marketplaces[$key])) {
    //             $marketplaceType = $marketplaces[$key]['type'] ?? "NA";
    //             $marketplaceName = $marketplaces[$key]['marketplace'];
    //             $dataToSend = [];
    //             foreach ($value as $v) {
    //                 $profile = [];
    //                 $sourceProductId = $v['currentValue']['source_product_id'];
    //                 $containerId = $v['currentValue']['container_id'];
    //                 if(isset($allProducts[$containerId][$sourceProductId]))
    //                 {
    //                     $product = $allProducts[$containerId][$sourceProductId];
    //                     $product['next_prev']=$v;
    //                     $data = [
    //                         'source' => [
    //                             'shop_id' =>$v['operationType']==='update'?$v['currentValue']['source_shop_id'] ?? "NA":$v['currentValue']['shop_id']??"NA",
    //                             'marketplace' =>$v['operationType']==='update'? $marketplaces[$v['currentValue']['source_shop_id']]['marketplace']:$marketplaces[$v['currentValue']['shop_id']]['marketplace']
    //                         ],
    //                         'target' => ['shop_id' => $key, 'marketplace' => $marketplaceName]
    //                     ];
    //                     $product['next_prev']['data']=$data;

    //                     if (isset($product['isParent']) && !$product['isParent']) {
    //                         // if not parent
    //                         if (!empty($allProducts[$containerId][$containerId]) && $allProducts[$containerId][$containerId]['visibility'] === 'Catalog and Search') {
    //                             $parent = $allProducts[$containerId][$containerId];
    //                             if (isset($parent['profile']) && count($parent['profile']) > 0) {
    //                                 foreach ($parent['profile'] as  $prf) {
    //                                     if (empty($profiles[$prf['target_shop_id']][$prf['profile_name']])) {
    //                                         $profiles[$prf['target_shop_id']][$prf['profile_name']] =  $this->getProfileInfo($prf,$parent['shop_id'], $marketplaceName);
    //                                         $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
    //                                     } else {
    //                                         $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
    //                                     }
    //                                 }
    //                             }

    //                             $parent['profile_info'] = $profile;
    //                             $product['profile_info'] = $profile;
    //                             $product['parent_details'] = $parent;
    //                         }
    //                     }else{
    //                         if (isset($product['profile']) && count($product['profile']) > 0) {
    //                             foreach ($product['profile'] as  $prf) {
    //                                 if (empty($profiles[$prf['target_shop_id']][$prf['profile_name']])) {
    //                                     $profiles[$prf['target_shop_id']][$prf['profile_name']] =  $this->getProfileInfo($prf,$product['shop_id'], $marketplaceName);
    //                                     $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
    //                                 } else {
    //                                     $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
    //                                 }
    //                             }
    //                         }

    //                         $product['profile_info'] = $profile;
    //                     }

    //                     $dataToSend[]=$product;
    //                 }
    //                 }

    //             if($marketplaceType==='target'){
    //                 $this->sendDatatoMarketplace($marketplaceName,$dataToSend);
    //             }else{
    //                 foreach ($marketplaces[$key]['ids'] as $id) {
    //                     if (isset($marketplaces[$id]) && isset($marketplaces[$id]['marketplace'])) {
    //                         foreach($dataToSend as $key => $val){
    //                             $dataToSend[$key]['next_prev']['data']['target'] = ['shop_id'=>$id,'marketplace'=>$marketplaces[$id]['marketplace']];
    //                         }

    //                         $this->sendDatatoMarketplace($marketplaces[$id]['marketplace'], $dataToSend);
    //                     }

    //                 }
    //             }
    //         }
    //     }

    //     return true;
    // }

    private function formatNextPrevProducts($nextPrev,$shopIds, $containerIds, array $marketplaces): bool
    {
        // $allProducts = $this->getContainerIdsFormattedProducts($containerIds,$shopIds);
        $profiles = [];
        foreach ($nextPrev as $key => $value) {
            if (isset($marketplaces[$key])) {
                $marketplaceType = $marketplaces[$key]['type'] ?? "NA";
                $marketplaceName = $marketplaces[$key]['marketplace'];
                foreach ($value as $v) {
                    $sourceProductId = $v['currentValue']['source_product_id'];
                    $containerId = $v['currentValue']['container_id'];
                    
                    if($marketplaceType==='target')
                    {
                        // In this case shopIds variable will contain target_shop_id. It will also contain source_shop_id as well in case data contains "source_shop_id".
                        // marketplaces variable will contain the array of source_shops
                        $targetShopId = $key;
                        foreach ($marketplaces[$targetShopId]['ids'] as $sourceShopId) {
                            $profile = [];
                            $dataToSend = [];
                            if (isset($marketplaces[$sourceShopId]) && isset($marketplaces[$sourceShopId]['marketplace'])) {

                                $allProducts = $this->getContainerIdsFormattedProducts($containerIds, [(string)$sourceShopId, (string)$targetShopId]);
                                
                                if(isset($allProducts[$containerId][$sourceProductId]))
                                {
                                    $product = $allProducts[$containerId][$sourceProductId];
                                    $product['next_prev'] = $v;
                                    $data = [
                                        'source' => [
                                            'shop_id' => $sourceShopId,
                                            'marketplace' => $marketplaces[$sourceShopId]['marketplace']
                                        ],
                                        'target' => [
                                            'shop_id' => $targetShopId, 
                                            'marketplace' => $marketplaces[$targetShopId]['marketplace']
                                        ]
                                    ];
                                    $product['next_prev']['data'] = $data;

                                    if (isset($product['isParent']) && !$product['isParent']) {
                                        // if not parent
                                        if (!empty($allProducts[$containerId][$containerId]) && $allProducts[$containerId][$containerId]['visibility'] === 'Catalog and Search') {
                                            $parent = $allProducts[$containerId][$containerId];
                                            if (isset($parent['profile']) && count($parent['profile']) > 0) {
                                                foreach ($parent['profile'] as  $prf) {
                                                    if($targetShopId == $prf['target_shop_id']) {
                                                        if (empty($profiles[$prf['target_shop_id']][$prf['profile_name']])) {
                                                            $profiles[$prf['target_shop_id']][$prf['profile_name']] =  $this->getProfileInfo($prf,$parent['shop_id'], $marketplaceName);
                                                            $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
                                                        } else {
                                                            $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
                                                        }
                                                    }
                                                }
                                            }

                                            $parent['profile_info'] = $profile;
                                            $product['profile_info'] = $profile;
                                            $product['parent_details'] = $parent;
                                        }
                                    }
                                    else
                                    {
                                        if (isset($product['profile']) && count($product['profile']) > 0) {
                                            foreach ($product['profile'] as  $prf) {
                                                if($targetShopId == $prf['target_shop_id']) {
                                                    if (empty($profiles[$prf['target_shop_id']][$prf['profile_name']])) {
                                                        $profiles[$prf['target_shop_id']][$prf['profile_name']] =  $this->getProfileInfo($prf,$product['shop_id'], $marketplaceName);
                                                        $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
                                                    } else {
                                                        $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
                                                    }
                                                }
                                            }
                                        }

                                        $product['profile_info'] = $profile;
                                    }

                                    $dataToSend[] = $product;
                                     $this->sendDatatoMarketplace($marketplaces[$targetShopId]['marketplace'], $dataToSend);
                                }
                            }
                        }
                    }
                    elseif($marketplaceType==='source')
                    {
                        $sourceShopId = $key;
                       
                        foreach ($marketplaces[$sourceShopId]['ids'] as $targetShopId) {
                            $profile = [];
                            $dataToSend = [];
                            if (isset($marketplaces[$targetShopId]) && isset($marketplaces[$targetShopId]['marketplace'])) {
                               
                                $allProducts = $this->getContainerIdsFormattedProducts($containerIds, [(string)$sourceShopId, (string)$targetShopId]);
                                if(isset($allProducts[$containerId][$sourceProductId]))
                                {
                                    $product = $allProducts[$containerId][$sourceProductId];
                                    $product['next_prev'] = $v;
                                    $data = [
                                        'source' => [
                                            'shop_id' => $sourceShopId,
                                            'marketplace' => $marketplaces[$sourceShopId]['marketplace']
                                        ],
                                        'target' => [
                                            'shop_id' => $targetShopId, 
                                            'marketplace' => $marketplaces[$targetShopId]['marketplace']
                                        ]
                                    ];
                                    $product['next_prev']['data'] = $data;

                                    if (isset($product['isParent']) && !$product['isParent']) {
                                        // if not parent
                                        if (!empty($allProducts[$containerId][$containerId]) && $allProducts[$containerId][$containerId]['visibility'] === 'Catalog and Search') {
                                            $parent = $allProducts[$containerId][$containerId];
                                            if (isset($parent['profile']) && count($parent['profile']) > 0) {
                                                foreach ($parent['profile'] as  $prf) {
                                                    if($targetShopId == $prf['target_shop_id']) {
                                                        if (empty($profiles[$prf['target_shop_id']][$prf['profile_name']])) {
                                                            $profiles[$prf['target_shop_id']][$prf['profile_name']] =  $this->getProfileInfo($prf,$parent['shop_id'], $marketplaceName);
                                                            $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
                                                        } else {
                                                            $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
                                                        }
                                                    }
                                                }
                                            }

                                            $parent['profile_info'] = $profile;
                                            $product['profile_info'] = $profile;
                                            $product['parent_details'] = $parent;
                                        }
                                    }
                                    else
                                    {
                                        if (isset($product['profile']) && count($product['profile']) > 0) {
                                            foreach ($product['profile'] as  $prf) {
                                                if($targetShopId == $prf['target_shop_id']) {
                                                    if (empty($profiles[$prf['target_shop_id']][$prf['profile_name']])) {
                                                        $profiles[$prf['target_shop_id']][$prf['profile_name']] =  $this->getProfileInfo($prf,$product['shop_id'], $marketplaceName);
                                                        $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
                                                    } else {
                                                        $profile[] = $profiles[$prf['target_shop_id']][$prf['profile_name']];
                                                    }
                                                }
                                            }
                                        }

                                        $product['profile_info'] = $profile;
                                    }

                                    $dataToSend[] = $product;
                                     $this->sendDatatoMarketplace($marketplaces[$targetShopId]['marketplace'], $dataToSend);
                                }
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    /*public function getContainerIdsFormattedProducts($containerIds,$shopIds)
    {
        $productCollection = $this->getCollectionForTable('product_container');
        $products = $productCollection->find(['user_id' => $this->di->getUser()->id, 'container_id' => ['$in' => $containerIds], 'shop_id' => ['$in' => $shopIds]])->toArray();
        $formattedProducts = [];
        foreach ($products as $val) {
            if(isset($val['description'])){
                unset($val['description']);
            }

            if (isset($val['target_marketplace'])) {
                $formattedProducts[$val['container_id']][$val['source_product_id']]['edited'] = $val;
            } else {
                $formattedProducts[$val['container_id']][$val['source_product_id']] = $val;
                $formattedProducts[$val['container_id']][$val['source_product_id']]['isParent'] = $val['visibility'] === 'Catalog and Search' ? true : false;
            }
        }

        return $formattedProducts;
    }*/
    public function getContainerIdsFormattedProducts($containerIds,$shopIds)
    {
        $options = [
            'projection' => [
                'locations' => 0,
                'seo' => 0,
                'tags' => 0,
                'marketplace' => 0,
                'additional_images' => 0,
                'variant_attributes' => 0,
                'variant_attributes_values' => 0,
                'collection' => 0,
                'last_activities' => 0
            ]
        ];
        $productCollection = $this->getCollectionForTable('product_container');
        $products = $productCollection->find(['user_id' => $this->di->getUser()->id, 'container_id' => ['$in' => $containerIds], 'shop_id' => ['$in' => $shopIds]], $options)->toArray();
        $formattedProducts = [];
        foreach ($products as $val) {
            if(isset($val['description'])){
                unset($val['description']);
            }

            if (isset($val['target_marketplace'])) {
                $formattedProducts[$val['container_id']][$val['source_product_id']]['edited'] = $val;
            } else {
                if(!isset($formattedProducts[$val['container_id']][$val['source_product_id']]['edited'])) {
                    $formattedProducts[$val['container_id']][$val['source_product_id']] = $val;
                    $formattedProducts[$val['container_id']][$val['source_product_id']]['isParent'] = $val['visibility'] === 'Catalog and Search' ? true : false;
                }
                else {
                    $edited = $formattedProducts[$val['container_id']][$val['source_product_id']]['edited'];
                    $formattedProducts[$val['container_id']][$val['source_product_id']] = $val;
                    $formattedProducts[$val['container_id']][$val['source_product_id']]['isParent'] = $val['visibility'] === 'Catalog and Search' ? true : false;
                    $formattedProducts[$val['container_id']][$val['source_product_id']]['edited'] = $edited;
                }
            }
        }

        return $formattedProducts;
    }

    private function getProfileInfo($prf,$shopId, $marketplaceName)
    {   $tempProfile = $this->di->getObjectManager()->get("\App\Connector\Models\Product\Edit")->getProfileInfo($prf['profile_id'], [
            'targetShopID' => $prf['target_shop_id'],
            'target_marketplace' => $marketplaceName,
            'sourceShopID' => $shopId
        ]);
        return $tempProfile;
    }

    private function sendDatatoMarketplace($marketplaceName, array $data): void
    {
        $this->di->getConfig()->connectors->get($marketplaceName)->get('source_model');
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:handleNextPrevProductState', $this, $data);
        // if ((method_exists($this->di->getObjectManager()->get($model), "handleNextPrevProductState"))) {
        //     $this->di->getObjectManager()->get($model)->handleNextPrevProductState($data);
        // }
    }
}
