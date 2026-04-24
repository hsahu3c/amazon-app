<?php

namespace App\Core\Controllers;

class AppController extends BaseController
{
    public function createAction()
    {
        $user = new \App\Core\Models\User;
        $requestData = $this->getRequestData();
        return $this->prepareResponse($user->createUser($requestData, 'app'));
    }


    public function loginAction()
    {
        $user = new \App\Core\Models\User;
        $requestData = $this->getRequestData();
        return $this->prepareResponse($user->login($requestData, 'app'));
    }

    public function reportAction()
    {
        $requestData = $this->getRequestData();
        $user = new \App\Core\Models\User;
        return $this->prepareResponse($user->reportIssue($requestData));
    }

    public function sendOtpAction()
    {
        $requestData = $this->getRequestData();
        $user = $this->di->getObjectManager()->get('\App\Core\Components\Helper');
        return $this->prepareResponse($user->sendOtp($requestData));
    }

    public function matchOtpAction()
    {
        $requestData = $this->getRequestData();
        $user = $this->di->getObjectManager()->get('\App\Core\Components\Helper');
        return $this->prepareResponse($user->matchOtp($requestData));
    }
}
