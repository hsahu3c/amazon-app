<?php

namespace App\Connector\Models\User;

use App\Core\Models\BaseMongo;

class Connector extends BaseMongo
{
    protected $table = 'user_connector';

    public function install($connector, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->_id;
        }

        $data = Connector::findFirst([['user_id' => $userId, "code" => $connector]]);
        if (!$data) {
            $data = [];
            $data['user_id'] = $userId;
            $data['code'] = $connector;
            $this->setData($data);
            if ($this->save()) {
                return true;
            }
            return false;
        }

        return true;
    }
}
