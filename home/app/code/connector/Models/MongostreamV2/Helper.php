<?php

namespace App\Connector\Models\MongostreamV2;

use App\Core\Models\BaseMongo;

use App\Connector\Models\MongostreamV2\DataWrapper;


class Helper extends BaseMongo
{

    public function getProduct($data)
    {

        if (!isset($data['source'])) {
            return ['success' => false, 'message' => 'source key data missing, excepted schema [source => [shopId => 1 , marektplace => shopify]]'];
        }

        if (!isset($data['source_product_id']) && !isset($data['container_id'])) {
            return ['success' => false, 'message' => 'Either source_product_id or container_id is missing'];
        }

        $obj = new \App\Connector\Models\MongostreamV2\GetProductInfo;

        if (isset($data['target_info']) && $data['target_info'] == false) {
            unset($data['targets']);
        }

        $getResponse = $obj->processProductData($data, isset($data['appendProfileData']) ? $data['appendProfileData'] : true);

        return $getResponse;
    }

    public function getTargetIds($data, $returnMarketplaceName = false)
    {
        //on the basis of source_shop_id and user ids [user_id , source_shop_id];
        // $options = [
        //     "typeMap" => ['root' => 'array', 'document' => 'array']
        // ];
        // $userInfo =  $this->getCollectionForTable('user_details')->findOne(['user_id' => $data['user_id']], $options);
        $shops = $this->di->getUser()->shops;

        if (isset($data['target_marketplace'])) {
            $shopId = $data['source_shop_id'];
        } else {
            $shopId = $data['shop_id'];
        }

        // $shops = $userInfo['shops'];
        foreach ($shops as $v) {
            if ($v['_id'] == $shopId) {
                return ($returnMarketplaceName ? ['targets' => $v['targets'], 'source_marketplace' => $v['marketplace'], 'source_shop_id' => $shopId] : $v['targets']);
            }
        }
    }

    // public function setUserInDi($userInfo): void
    // {
    //     $this->di->setUser($userInfo);
    // }

    // public function getAllUserInfo($userIds)
    // {
    //     $options = [
    //         "typeMap" => ['root' => 'array', 'document' => 'array']
    //     ];
    //     $alluserInfo =  $this->getCollectionForTable('user_details')->find(['user_id' => ['$in' => $userIds]], $options)->toArray();

    //     $formatDataOnUserKey = [];

    //     foreach ($alluserInfo as $v) {
    //         $formatDataOnUserKey[$v['user_id']] = $v;
    //     }

    //     return $formatDataOnUserKey;
    // }

    public function setUserInDi($userInfo): void
    {
        // $user = \App\Core\Models\User::findFirst([['_id' => $userInfo['user_id']]]);
        if ($userInfo) {
            $userInfo->id = (string) $userInfo->_id;
            $this->di->setUser($userInfo);
            $decodedToken = [
                'role' => 'admin',
                'user_id' => $userInfo->user_id,
                'username' => $userInfo->username,
            ];

            if ($this->di->getConfig()->has('plan_di')) {
                $planDi = $this->di->getConfig()->get('plan_di')->toArray();
                if (!empty($planDi) && isset($planDi['enabled'], $planDi['class'], $planDi['method']) && $planDi['enabled'] && !empty($planDi['class']) && !empty($planDi['method'])) {
                    if (class_exists($planDi['class'])) {
                        $planObj = $this->di->getObjectManager()->create($planDi['class']);
                        if (method_exists($planObj, $planDi['method'])) {
                        $method = $planDi['method'];
                            $this->di->setPlan($planObj->$method($userInfo->id));
                        }
                    }
                }
            }
            $this->di->getRegistry()->setDecodedToken($decodedToken);
        }
    }

    public function getAllUserInfo($userIds)
    {
        $formatDataOnUserKey = [];
        foreach ($userIds as $userId) {
            $userModel = \App\Core\Models\User::findFirst([['_id' => $userId]]);
            if($userModel) {
                $formatDataOnUserKey[$userId] = $userModel;
            }
        }
        return $formatDataOnUserKey;
    }

    public function prepareData($data)
    {
        try {
            $res = $this->getTargetIds($data, true);

            $source = [
                'shopId' => $res['source_shop_id'],
                'marketplace' => $res['source_marketplace']
            ];

            if (isset($data['target_marketplace'], $data['shop_id'])) {
                $data['targets'][] = [
                    'shopId' => $data['shop_id'],
                    'marketplace' => $data['target_marketplace']
                ];
            }

            $data['all_targets'] = $res['targets'];
            $data['source'] = $source;



            return new DataWrapper($data);
        } catch (\Exception) {
            return ['success' => false, 'message' => 'fatal error'];
        }
    }

    public function sendData($data)
    {
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:MongoWebhooksV2', $this, $data);
        return true;
    }
}
