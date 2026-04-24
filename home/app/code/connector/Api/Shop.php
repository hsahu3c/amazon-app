<?php

namespace App\Connector\Api;

use Phalcon\Events\Event;


class Shop extends \App\Apiconnect\Api\Base
{

    public function addShop($data)
    {
        $requestData = $data;
        $marketplace = $data['marketplace'];

        if (!isset($this->di->getAppCode()->get()[$marketplace]))
            return ['success' => false, 'message' => "Required parameter app_code is missing"];

        $appCode = $this->di->getAppCode()->get()[$marketplace];
        $apiConnector = $this->di->getConfig()->get('apiconnector');
        if (isset($apiConnector->$marketplace->$appCode->sub_app_id)) {
            $sAppId = $apiConnector->$marketplace->$appCode->sub_app_id;
        } else {
            return ['success' => false, 'message' => "sub_app_id is not getting from config.kindly check marketplace and appCode"];
        }

        if (isset($data['shop_data']['password'])) unset($data['shop_data']['password']);

        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($marketplace, false, $appCode)
            ->call('/add-app-shop', ['sAppId' => $sAppId], ['data' => $data], 'POST');

        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
            if(isset($data['state'])){
                $remoteResponse['state'] = json_encode($data['state']);
            }

            $dataForUserSetup = [
                'success' => true,
                'data' => ['data' => $remoteResponse['data']],
                'rawResponse' => $remoteResponse,
                'shop_data' => $requestData['shop_data'],
                "direct_call"=>1
            ];
            if (isset($requestData['user_role'])) $dataForUserSetup['rawResponse']['user_role'] = $requestData['user_role'];

            $userSetupResponse = $this->di
                ->getObjectManager()
                ->get('App\Connector\Models\SourceModel')->commenceHomeAuth($dataForUserSetup);
            return $userSetupResponse;
        }
        return $remoteResponse;
    }

    public function changeShopStatus($data)
    {
        try {
            $userId = false;
            if (isset($data['userId'])) $userId = $data['userId'];

            if (isset($data['shopStatus'], $data['shopId'])) {
                $userDetailObject = $this->di->getObjectManager()->get("\App\Core\Models\User\Details");
                $result = $userDetailObject->setStatusInUser($data['shopStatus'], $userId, $data['shopId']);
                return $result;
            }

            return ['success' => false, 'message' => 'Required shopStatus and ShopId'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
