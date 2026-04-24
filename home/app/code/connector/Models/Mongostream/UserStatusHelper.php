<?php

namespace App\Connector\Models\Mongostream;

use App\Core\Models\BaseMongo;
class UserStatusHelper extends BaseMongo
{
    public function processUserStatus($data)
    {
        return $this->formatUserStatus($data);
    }

    private function formatUserStatus(array $data): bool
    {
        $this->getCollectionForTable("user_details");
        $product = $this->getCollectionForTable("product_container");
        $user = $this->di->getUser()->toArray();;
        if (!empty($user['status']) && $user['user_status'] == "inactive") {
            $product->updateMany(['user_id' => $data['user_id']], ['$set' => ['uninstall_status' => true]]);
        } else if (!empty($user['status']) && $user['user_status'] == "active") {
            $product->updateMany(['user_id' => $data['user_id']], ['$set' => ['uninstall_status' => false]]);
        }

        return true;
    }
}