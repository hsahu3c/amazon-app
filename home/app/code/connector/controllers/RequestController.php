<?php

namespace App\Connector\Controllers;

class RequestController extends \App\Core\Controllers\BaseController
{

    const GENERAL_ERROR_MSG = "We didn't anticipated you landing here ! Please reinstall the app again or send us your experience. We will help you guide through.";

    const GENERAL_MSG = "Sorry..<br /> It's not you.<br /> It's us.<br /> Mind sending a error report to us.";

    public $_shopId;

    public $_postData;

    public $_postDataFromRemote;

    public $_errorMsg = false;

    public $_reinstallFlag = false;

    public $_frontendAppUrl = 'http://localhost:4000/';

    public $_ignoreGlobalFrontendUrl = false;

    public function initialize(): void
    {
        $helper = $this->di->getObjectManager()->get('\App\Core\Components\Helper');
        $configData = $this->di->getConfig()->toArray();
        $this->_postDataFromRemote = $this->request->get();
        $postData = $this->_postDataFromRemote;
        if (!isset($postData['data'])) {
            $this->_reinstallFlag = true;
            $this->_errorMsg = isset($postData['message']) ? $postData['message'] : RequestController::GENERAL_ERROR_MSG;
            $this->_frontendAppUrl = isset($postData['marketplace'], $postData['app_code'], $configData['apiconnector'][$postData['marketplace']][$postData['app_code']]['frontend_app_url']) ? $configData['apiconnector'][$postData['marketplace']][$postData['app_code']]['frontend_app_url'] : ($configData['frontend_app_url'] ?? $this->_frontendAppUrl);
        } else {
            $postData['app_code'] = isset($postData['app_code']) ? $postData['app_code'] : 'default';
            $publicKey = $configData['apiconnector'][$postData['marketplace']][$postData['app_code']]['public_key'];
            $this->_frontendAppUrl = isset($postData['marketplace'], $postData['app_code'], $configData['apiconnector'][$postData['marketplace']][$postData['app_code']]['frontend_app_url']) ? $configData['apiconnector'][$postData['marketplace']][$postData['app_code']]['frontend_app_url'] : ($configData['frontend_app_url'] ?? $this->_frontendAppUrl);
            $this->_ignoreGlobalFrontendUrl = isset($postData['marketplace'], $postData['app_code'], $configData['apiconnector'][$postData['marketplace']][$postData['app_code']]['ignore_global_frontend_url']) ? $configData['apiconnector'][$postData['marketplace']][$postData['app_code']]['ignore_global_frontend_url'] : ($configData['ignore_global_frontend_url'] ?? '');

            $this->di->getAppCode()->set([
                $postData['marketplace'] => $postData['app_code']
            ]);

            if (!isset($publicKey)) {
                $this->_errorMsg = json_encode($postData);
            } else {
                $this->_postData = json_encode($helper->decodeToken($postData['data'], false, base64_decode($publicKey)));
                $this->_postData = json_decode($this->_postData, true);
                if (!isset($this->_postData['data']['data']['shop_id']) || ($this->_postData['data']['data']['shop_id'] == 0) || empty($this->_postData['data']['data']['shop_id'])) {
                    $msg = isset($this->_postData['message']) ? ($this->_postData['message']) : '';
                    $this->di->getLog()->logContent(date("d-m-y H:i:s") . ' POST DATA FROM REMOTE => ' . print_r($postData, true), 'critical', 'requestControllerPostData.log');
                    $this->_errorMsg = $msg . ". Invalid/No Shop Found. Kindly contact Remote Host to provide valid shop id.";
                } else {
                    $this->_shopId = $this->request->get('shop_id');
                }
            }
        }

        if (isset($this->_postDataFromRemote['state']) && !empty($this->_postDataFromRemote['state'])) {
            $state = json_decode($this->_postDataFromRemote['state'], true);
            if (isset($state['frontend_redirect_uri'])) {
                $redirectUrl = explode("?", $state['frontend_redirect_uri']);
                $redirectUrl = $redirectUrl[0] ?? $state['frontend_redirect_uri'];
                $params = parse_url($state['frontend_redirect_uri']);
                if (isset($params['query'])) {
                    parse_str($params['query'], $query);
                    $redirectUrl = $redirectUrl . '?' . http_build_query($query) . '&';
                } else {
                    $redirectUrl = $redirectUrl . '?';
                }

                $this->_postDataFromRemote['frontend_redirect_uri'] = $redirectUrl;
            }
        } elseif (isset($this->_postDataFromRemote['frontend_redirect_uri']) && strpos($this->_postDataFromRemote['frontend_redirect_uri'], '?') == false) {
            $this->_postDataFromRemote['frontend_redirect_uri'] = $this->_postDataFromRemote['frontend_redirect_uri'] . "?";
        }
    }

    public function commenceHomeAuthAction()
    {
        if ($this->_errorMsg) {
            $this->di->getLog()->logContent(date("H:i:s") . ' error message => ' . print_r($this->_errorMsg, true), 'critical', 'Authorization' . DS . date('d-m-Y') . DS . 'commence_home_auth_action.log');
            $this->_frontendAppUrl = isset($this->_postDataFromRemote['frontend_redirect_uri']) ? $this->_postDataFromRemote['frontend_redirect_uri'] : $this->_frontendAppUrl . 'show/message?';
            return $this->response->redirect($this->_frontendAppUrl . 'success=false&message=' . $this->_errorMsg);
        }

        if (!empty($this->_postData)) {
            $this->_postDataFromRemote['decoded_state'] = $this->_postData;
        }

        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:beforeCommenceHomeAuthAction', $this, $this->_postDataFromRemote);

        $redirect = true;
        if (isset($this->_postDataFromRemote['no_redirect']))
            $redirect = false;

        if (isset($this->_postData['success']) && $this->_postData['success']) {
            if (
                $model = $this->di->getConfig()->connectors
                ->get($this->_postDataFromRemote['marketplace'])->get('source_model')
            ) {

                $this->_postData['rawResponse'] = $this->_postDataFromRemote;
                $homeAppResponse = $this->di
                    ->getObjectManager()
                    ->get('App\Connector\Models\SourceModel')->commenceHomeAuth($this->_postData);

                $eventsManager->fire('application:afterCommenceHomeAuthAction', $this, $this->_postData);
                // if success , user created successfully else already exist or error
                if ($homeAppResponse['success']) {
                    if (isset($this->_postDataFromRemote['state'])) {
                        $state = json_decode($this->_postDataFromRemote['state'], true);
                        $tokenExpirationDuration = $state['token_expiry_duration'] ?? $this->di->getUser()::DEFAULT_TOKEN_EXPIRATION_TIME;
                    }

                    $token = $this->di->getUser()->getToken($tokenExpirationDuration ?? $this->di->getUser()::DEFAULT_TOKEN_EXPIRATION_TIME);
                    if (isset($homeAppResponse['redirect_to_dashboard'])) {
                        $user_id = $this->di->getUser()->id;
                        if (isset($this->_postDataFromRemote['frontend_redirect_uri'])) {
                            return $this->response->redirect($this->_postDataFromRemote['frontend_redirect_uri'] . 'user_token=' . $token . '&shop=' . $homeAppResponse['shop'] . '&connectionStatus=' . $homeAppResponse['connectionStatus']);
                        }
                        if ($this->_ignoreGlobalFrontendUrl) {
                            if ($redirect) {
                                return $this->response->redirect($this->_frontendAppUrl . '?user_token=' . $token . '&shop=' . $homeAppResponse['shop']);
                            }
                            return $this->prepareResponse(['success' => true, 'message' => 'requested_current_route']);
                        }
                        if ($statePath = $this->session->get("requested_current_route")) {
                            $queryParams = $this->getShopURLParams($homeAppResponse);
                            if ($redirect) {
                                return $this->response->redirect($this->_frontendAppUrl . "panel/" . $user_id . '/' . $statePath . '?' . http_build_query($queryParams));
                            }
                            return $this->prepareResponse(['success' => true, 'message' => 'requested_current_route']);
                        }
                        if ($redirect) {
                            // return $this->response->redirect($this->_frontendAppUrl . 'auth/login?user_token=' . $token . '&shop=' . $homeAppResponse['shop'] . '&connectionStatus=' . $homeAppResponse['connectionStatus'] . '&username=' . $this->di->getUser()->getUsername() . '&user_id=' . $this->di->getUser()->getUserId() . '&target_shop_id=' . $homeAppResponse['shop_data']['shop']['_id']);
                            $redirectQueryParams = [
                                'user_token' => $token,
                                'shop' => $homeAppResponse['shop'],
                                'connectionStatus' => $homeAppResponse['connectionStatus'],
                                'username' => $this->di->getUser()->getUsername(),
                                'user_id' => $this->di->getUser()->getUserId(),
                                'target_shop_id' => $homeAppResponse['shop_data']['shop']['_id']
                            ];

                            if (isset($this->_postDataFromRemote['host'])) {
                                $redirectQueryParams['host'] = $this->_postDataFromRemote['host'];
                            }

                            return $this->response->redirect($this->_frontendAppUrl . 'auth/login?' . http_build_query($redirectQueryParams));
                        }
                        return $this->prepareResponse(['success' => true, 'message' => 'requested_current_route']);
                    }

                    if (isset($homeAppResponse['redirect_custom_url'])) {
                        if (isset($this->_postDataFromRemote['frontend_redirect_uri'])) {
                            return $this->response->redirect($this->_postDataFromRemote['frontend_redirect_uri'] . '?user_token=' . $token . '&shop=' . $homeAppResponse['shop'] . '&connectionStatus=' . $homeAppResponse['connectionStatus']);
                        }

                        if ($redirect) {
                            return $this->response->redirect($homeAppResponse['custom_url']);
                        }
                        return $this->prepareResponse(['success' => true, 'message' => 'redirect_custom_url']);
                    }
                    else {
                        unset($homeAppResponse['success']);
                        if (isset($this->_postDataFromRemote['frontend_redirect_uri'])) {
                            return $this->response->redirect($this->_postDataFromRemote['frontend_redirect_uri'] . '?user_token=' . $token . '&shop=' . $homeAppResponse['shop'] . '&connectionStatus=' . $homeAppResponse['connectionStatus']);
                        }
                        if ($this->_ignoreGlobalFrontendUrl) {
                            if ($redirect) {
                                return $this->response->redirect($this->_frontendAppUrl . '?user_token=' . $token . '&code=shopify_installed&success=true&' . http_build_query($homeAppResponse));
                            }
                            return $this->prepareResponse(['success' => true, 'message' => 'homeAppResponse']);
                        }

                        if ($redirect) {
                            unset($homeAppResponse['shop_data']);
                            $this->_frontendAppUrl = isset($this->_postDataFromRemote['frontend_redirect_uri']) ? $this->_postDataFromRemote['frontend_redirect_uri'] : $this->_frontendAppUrl . 'show/message?';
                            return $this->response->redirect($this->_frontendAppUrl . 'success=true&' . http_build_query($homeAppResponse));
                        }
                        return $this->prepareResponse(['success' => true, 'message' => 'show message']);
                    }
                }
                if ($redirect) {
                    $this->_frontendAppUrl = isset($this->_postDataFromRemote['frontend_redirect_uri']) ? $this->_postDataFromRemote['frontend_redirect_uri'] : $this->_frontendAppUrl . 'show/message?';
                    return $this->response->redirect($this->_frontendAppUrl . 'success=false' . '&code=' . ($homeAppResponse['code'] ?? 'code_not_found') . '&remote_shop_id=' . ($homeAppResponse['remote_shop_id'] ?? 'remote_shop_id_not_found') . '&message=' . ($homeAppResponse['message'] ?? 'Something Went Wrong From Our Side'));
                }
                return $this->prepareResponse(['success' => false, 'message' => 'Something Went Wrong From Our Side']);
            }
            if ($redirect) {
                $this->_frontendAppUrl = isset($this->_postDataFromRemote['frontend_redirect_uri']) ? $this->_postDataFromRemote['frontend_redirect_uri'] : $this->_frontendAppUrl . 'show/message?';
                return $this->response->redirect($this->_frontendAppUrl . 'success=false&message=' . RequestController::GENERAL_MSG);
            }
            return $this->prepareResponse(['success' => false, 'message' => RequestController::GENERAL_MSG]);
        }
        if ($redirect) {
            $this->_frontendAppUrl = isset($this->_postDataFromRemote['frontend_redirect_uri']) ? $this->_postDataFromRemote['frontend_redirect_uri'] : $this->_frontendAppUrl . 'show/message?';
            return $this->response->redirect($this->_frontendAppUrl . 'success=false&message=' . RequestController::GENERAL_ERROR_MSG);
        }
        $this->di->getLog()->logContent(date("H:i:s") . ' response => ' . json_encode($this->_postData), 'critical', 'Authorization' . DS . date('d-m-Y') . DS . 'commence_home_auth_action.log');
        return $this->prepareResponse(['success' => false, 'message' => RequestController::GENERAL_MSG]);
    }

    public function getShopURLParams($homeAppResponse)
    {
        $token = $this->di->getUser()->getToken();
        $arr = [
            'user_token' => $token,
            'shop' => $homeAppResponse['shop'],
            'username' => $this->di->getUser()->getUsername(),
            'target_shop_id' => $homeAppResponse['shop_data']['shop']['_id'] ?? ''
        ];

        if ($shopify_host = $this->session->get("requested_shopify_host")) {
            $arr['shopify_host'] = $shopify_host;
        }

        if ($shopify_shop = $this->session->get("requested_shopify_shopURL")) {
            $arr['shop'] = $shopify_shop;
        }

        if ($shopifyHost = $this->request->get('host')) {
            $arr['host'] = $shopifyHost;
        }

        return $arr;
    }

    public function shopifyCurrentRouteAction()
    {
        $rawData = $this->di->getRequest()->get();

        if ($redirect = $this->request->get('redirect_return_type')) {
            $this->session->set("redirect_return_type", $redirect);
            if ($url = $this->request->get('custom_url')) {
                $this->session->set("custom_url", $url);
            }
        } else {
            $this->session->set("redirect_return_type", 'redirect_to_dashboard');
        }

        if (isset($rawData['current_route'])) {
            $this->session->set("requested_current_route", $rawData['current_route']);
        } else {
            $this->session->set("requested_current_route", false);
        }

        $this->session->set("requested_shopify_host", $rawData['host']);
        $this->session->set("requested_shopify_shopURL", $rawData['shop']);

        unset($rawData['_url']);
        unset($rawData['current_route']);
        return $this->response->redirect($this->di->getConfig()->get('backend_base_url') . "apiconnect/request/auth?" . http_build_query($rawData));
    }

    public function disconnectAccountAction($user_id = false)
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
        return $this->prepareResponse($helper->disconnectAccount($rawBody));
    }

    public function updatedDisconnectAccountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $helper->TemporarlyUninstall($rawBody);
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:userUninstalled', $this, [
            'username' => $this->di->getUser()->username,
            'name' => $this->di->getUser()->name,
            'email' => $this->di->getUser()->email,
            'shops' => json_decode(json_encode($this->di->getUser()->shops), true),
            'data' => $rawBody
        ]);
        return $this->prepareResponse($response);
    }

    public function getConnectedAccountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
        return $this->prepareResponse($helper->getAllConnectedAcccounts(false, $rawBody));
    }

    public function saveSellerNameAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
        return $this->prepareResponse($helper->saveSellerName($rawBody));
    }

    public function sourceOrTargetShopDeleteAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!isset($rawBody['shop_id'], $rawBody['app_code']))
            return $this->prepareResponse(['success' => false, 'message' => 'shop_id or app_code missing']);

        $helper = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $result = $helper->TemporarlyUninstall($rawBody);
        return $this->prepareResponse($result);
    }

    public function resetAccountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');

        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get('\App\Connector\Components\Hook')->resetAccount($rawBody);
        return $this->prepareResponse($response);
    }
}
