<?php

namespace App\Connector\Controllers;

class ShopController extends \App\Core\Controllers\BaseController
{
    public function changeShopStatusAction()
    {
        $formData = $this->getRequestData();
        if (isset($formData['shopStatus'], $formData['shopId'])) {
            $userDetailObject = $this->di->getObjectManager()->get("\App\Core\Models\User\Details");
            $result = $userDetailObject->setStatusInUser($formData['shopStatus'], false, $formData['shopId']);
            return $this->prepareResponse($result);
        }
        return $this->prepareResponse(['success' => false, 'message' => 'Required shopStatus and ShopId']);
    }
}
