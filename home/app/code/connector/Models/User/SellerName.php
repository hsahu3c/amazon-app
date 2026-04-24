<?php

namespace App\Connector\Models\User;

use App\Core\Models\User\Details;

class SellerName extends Details
{
    public function updateShopName($data)
    {
        $userId = $this->di->getUser()->getId();
        $targetId = $this->di->getRequester()->getTargetId();
        $userDetails = $this->di->getObjectManager()->get('App\Core\Models\User\Details');
        $allShops = $userDetails->getAllConnectedShops($userId, $this->di->getRequester()->getTargetName());
        $shopData =  $userDetails->getShop($targetId);
        if (isset($data['sellerName'])) {
            $unique = true;
            foreach ($allShops as $value) {
                if ($value['sellerName'] == $data['sellerName']) {
                    $unique = false;
                    break;
                }
            }

            if (!$unique) {
                return (['success' => false, 'message' => 'Seller Name already exists please choose unique seller name']);
            }

            $shopData['sellerName'] = $data['sellerName'];
        } else {
            return (['success' => false, 'message' => 'No seller name found']);
        }

        $shopRes = $userDetails->addShop($shopData, false, ["_id"]);

        return $shopRes;
    }
}
