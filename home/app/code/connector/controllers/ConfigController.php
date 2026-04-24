<?php

namespace App\Connector\Controllers;

use Phalcon\Mvc\Controller;
use App\Connector\Models\ProductContainer;
use App\Connector\Models\Product;
use Phalcon\Di;
use App\Core\Controllers\BaseController;

class ConfigController extends BaseController
{
    public function saveAction()
    {
        if ($this->request->get()) {
            foreach ($this->request->get('config') as $framework => $data) {
                foreach ($data as $key => $value) {
                    $this->di->getObjectManager()->get('App\Core\Models\User')
                        ->load($this->di->getUser()->getId())->setConfig($key, $value, $framework);
                }
            }

            return $this->prepareResponse(['success' => true, 'code' => '', 'message' => 'Config saved successfully', 'data' => '']);
        }
    }

    public function saveSellerStatusAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody["sellerStatus"])) {
            $helper = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
            $res = $helper->saveSellerStatus($rawBody);
            return $this->prepareResponse($res);
        }
        $msg = ['success' => false, 'message' => 'data not found'];
        return $this->prepareResponse($msg);
    }

    public function saveSettingPrefrencesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
        return $this->prepareResponse($helper->saveSettingPrefrences($rawBody));
    }

    public function updateShopNameAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $obj =  $this->di->getObjectManager()->get('\App\Connector\Models\User\SellerName');
        return $this->prepareResponse($obj->updateShopName($rawBody));
    }

    /**
     * API to get config collection
     */
    public function getConfigAction(): object
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $configHelper = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');
        return $this->prepareResponse($configHelper->getConfigData($rawBody));
    }

    /**
     * save/update the configuration data in config collection
     */
    public function saveConfigAction(): object
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $configHelper = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');
        return $this->prepareResponse($configHelper->saveConfigData($rawBody));
    }

    /**
     * for updating entries in multi targets or connected shops
     */
    public function saveConfigForAllAction(): object
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $result = [];
        $configHelper = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');
        if(!empty($rawBody['data'])) {
            foreach($rawBody['data'] as $setData) {
                if(isset($setData['apply']) && $setData['apply'] == 'all') {
                    isset($setData['user_id']) && $setData['user_id'] =  $this->di->getUser()->id;
                    $result = $configHelper->saveConfigForAll($setData);
                }
            }

            return $this->prepareResponse($result);
        }
        isset($rawBody['user_id']) && $rawBody['user_id'] =  $this->di->getUser()->id;
        return $this->prepareResponse($configHelper->saveConfigData($rawBody));
    }

    /**
     * for deleting the keys from config collection
     * @param - [data => [[key=> [''], group_code => '']]]
     * 
     * @return object
     */
    public function deleteConfigAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if (isset($rawBody['user_id'])) {
            $rawBody['user_id'] = $this->di->getUser()->id;
        }
        $configHelper = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');
        return $this->prepareResponse($configHelper->deleteConfigData($rawBody));
    }
}
