<?php

namespace App\Core\Controllers;

class AdminConfigController extends BaseController
{
    public function getAllConfigAction()
    {
        $config = new \App\Core\Models\Config;
        $requestData = $this->getRequestData();
        return $this->prepareResponse($config->getAllConfig($requestData['framework']));
    }

    public function saveConfigAction()
    {
        $config = new \App\Core\Models\Config;
        $requestData = $this->getRequestData();
        return $this->prepareResponse($config->saveConfig($requestData));
    }
}
