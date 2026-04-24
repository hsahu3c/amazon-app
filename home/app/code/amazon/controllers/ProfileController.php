<?php

namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Components\Profile\Helper as ProfileHelper;
use App\Connector\Components\EventsHelper;

class ProfileController extends BaseController
{
    public function saveProfileAction()
    {
        $rawBody = $this->getRequestData();
        if (empty($rawBody)) {
            $this->prepareResponse(['success' => false, 'code' => 'body_missing', 'message' => 'Request data missing']);
        }

        $profileHelper = $this->di->getObjectManager()->get(ProfileHelper::class);
        $response = $profileHelper->saveProfile($rawBody);

        $this->di->getObjectManager()->get(EventsHelper::class)->createActivityLog("update", "updated profile (" . $rawBody['data']['name'] . ")", $rawBody);
        return $this->prepareResponse($response);
    }
}
