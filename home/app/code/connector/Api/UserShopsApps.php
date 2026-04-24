<?php

namespace App\Connector\Api;

class UserShopsApps extends \App\Apiconnect\Api\Base
{
    public function changeAppStatus($params)
    {
        try {
            $userId = $params['userId'] ?? $this->di->getUser()->id;
            if (isset($params['appStatus'], $params['appCode'], $params['shopId'])) {
                $userDetailObject = $this->di->getObjectManager()->get("\App\Core\Models\User\Details");
                $result = $userDetailObject->setStatusInUser($params['appStatus'], $userId, $params['shopId'],$params['appCode']);
                return $result;
            }

            return ['success' => false, 'message' => 'Trouble in changing app status. Required params: appStatus, appCode, shopId.'];
            
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
