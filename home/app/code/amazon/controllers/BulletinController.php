<?php

namespace App\Amazon\Controllers;

use App\Amazon\Components\Bulletin\Bulletin;
use App\Amazon\Components\Bulletin\Triggers;
use App\Core\Controllers\BaseController;

class BulletinController extends BaseController
{
    public function handleAction()
    {
        $rawBody = $this->getRequestData();
        $bulletin = $this->di->getObjectManager()->get(Bulletin::class);
        $link = $bulletin->handleRequest($rawBody);
        if ($link) {
            return $this->response->redirect($link);
        }
        return $this->prepareResponse([
            'success' => false,
            'message' => 'Error in getting response!'
        ]);
    }

    public function testCallAction(): void
    {
        $rawBody = $this->getRequestData();
        $bulletin = $this->di->getObjectManager()->get(Bulletin::class);
        $response = $bulletin->createBulletin($rawBody);
        echo '<pre>'; print_r($response); die();
    }

    public function sendAppMaintainanceBulletinAction()
    {
        $rawBody = $this->getRequestData();
        $triggers = $this->di->getObjectManager()->get(Triggers::class);
        $response = $triggers->sendAppMaintainanceBulletin($rawBody);
        return $this->prepareResponse($response);
    }
}