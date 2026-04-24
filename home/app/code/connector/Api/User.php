<?php

namespace App\Connector\Api;

class User extends \App\Apiconnect\Api\Base
{

    public function changeUserStatus($data)
    {
        try {
            if (isset($data['userId'])) {
                $userId = $data['userId'];
            } else {
                $userId = $this->di->getUser()->id;
            }

            if (isset($data['status'])) {
                $userDetailObject = $this->di->getObjectManager()->get("\App\Core\Models\User\Details");
                $result = $userDetailObject->setStatusInUser($data['status'], $userId);
                return $result;
            }

            return ['success' => false, 'message' => 'Trouble in changing user status.'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
