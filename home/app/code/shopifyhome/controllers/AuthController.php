<?php

/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright © 2018 CedCommerce. All rights reserved.
 * @license     EULA http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Controllers;

use App\Core\Controllers\BaseController;
use App\Shopifyhome\Components\Shop\Shop;
/**
 * Class AuthController:
 * used for private App authorization
 * @package App\Shopifyhome\Controllers
 */
class AuthController extends BaseController
{

    /**
     * intallation of private APP
     *
     * @return mixed: redirect to login page on successful installation
     */
    public function installAction()
    {
        $requestData = $this->getRequestData();
        $apiConnector = $this->di->getConfig()->get('apiconnector');
        $state = $requestData['state'] ?? "";
        $requiredData = ['id', 'secret', 'token', 'store', 'marketplace', 'appCode'];
        if (!empty($state)) {
            $requestData = json_decode(base64_decode((string) $state), true);
            foreach ($requiredData as $value) {
                if (!in_array($value, array_keys($requestData))) {
                    $missingData[] = $value;
                }
            }

            if (!empty($missingData)) {
                return $this->prepareResponse([
                    'succes' => false,
                    'message' => 'Missing required data in state : ' . json_encode($missingData, true)
                ]);
            }
        } else {
            unset($requestData['_url']);
        }

        $marketplace = $requestData['marketplace'] ?? $this->di->getRequester()->getSourceName();
        $appCode = $requestData['appCode'] ?? $this->di->getAppCode()->get()[$marketplace] ?? false;
        $sAppId = !empty($apiConnector->$marketplace[$appCode]) ?
            $apiConnector->$marketplace[$appCode]['sub_app_id'] : false;
        if (
            isset(
                $requestData['store'],
                $requestData['id'],
                $requestData['secret'],
                $requestData['token']
            )  &&
            !empty($sAppId) && !empty($appCode)
        ) {
            $userId = $requestData['user_id'] ?? $this->di->getUser()->user_id;
            $state = [];
            $state['user_id'] = $userId;
            $state['shop'] = $requestData['store'];
            $state['clientId'] = $requestData['id'];
            $state['clientSecret'] = $requestData['secret'];
            $state['accessToken'] = $requestData['token'];

            if (!empty($requestData['frontend_redirect_uri'])) {
                $state['frontend_redirect_uri'] = $requestData['frontend_redirect_uri'];
            }


            $url = $apiConnector->base_url . 'apiconnect/request/commenceAuth' .
                '?sAppId=' . $sAppId . '&appCode=' . $appCode . '&appType=private&state=' . base64_encode(json_encode($state));

            return $this->response->redirect($url, true);
        }
        $response = [
            'success' => true,
            'message' => 'Required data is missing'
        ];

        return $this->prepareResponse($response);
    }

    /**
     * function used tp return shopify app scopes registered in remote database
     *
     * @return object
     */
    public function getScopesFromAppAction()
    {
        $requestData = $this->getRequestData();
        $response = $this->di->getobjectManager()
            ->get(Shop::class)->getScopesFromApp($requestData);
        return $this->prepareResponse($response);
    }
}
