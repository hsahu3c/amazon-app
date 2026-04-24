<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;

class CustomerModel extends BaseMongo
{
    protected $table = "user_details";

    public function validateUserDetails($data = false)
    {
        if (isset($data['username']) || isset($data['email'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->setSource($this->table)->getCollection();
            if (isset($data['username'])) {
                $matchedUsername = $collection->find(['username' => $data['username']])->toArray();

                if (count($matchedUsername) > 0) {
                    return ['success' => false, "message" => "Username already exists !"];
                }

                return ['success' => true, "message" => "Username is Unique"];
            }
            if (isset($data['email'])) {
                $matchedEmail = $collection->find(['email' => $data['email']])->toArray();

                if (count($matchedEmail) > 0) {
                    return ['success' => false, "message" => "Email already exists !"];
                }

                return ['success' => true, "message" => "Email is Unique"];
            }
        }

        return ['success' => false, "message" => "Missing or Invalid Params !!!"];
    }
}
