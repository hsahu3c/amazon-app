<?php

namespace App\Amazon\Components\Common;

use App\Core\Models\User;
use App\Core\Components\Base as Base;
use Exception;

class Common extends Base
{
    public function setDiForUser($userId)
    {
        $result = [];
        try {
            $getUser = User::findFirst([['_id' => $userId]]);
            if (empty($getUser)) {
                $result = [
                    'success' => false,
                    'message' => 'User not found'
                ];
            } else {
                $getUser->id = (string) $getUser->_id;
                $this->di->setUser($getUser);
                if ($this->di->getUser()->getConfig()['username'] == 'admin') {
                    $result = [
                        'success' => false,
                        'message' => 'user not found in DB. Fetched di of admin.'
                    ];
                } else {
                    $result = [
                        'success' => true,
                        'message' => 'user set in di successfully'
                    ];
                }
            }
        } catch (Exception $e) {
            $result = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        return $result;
    }
}