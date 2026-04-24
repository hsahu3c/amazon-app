<?php

namespace App\Amazon\Controllers;

use App\Amazon\Components\UserLinking;
use App\Amazon\Models\LinkedUser;
use App\Core\Controllers\BaseController;

class LinkingController extends BaseController
{
    public function userLinkingAction()
    {
        $postData = $this->getRequestData();
        $this->di->getLog()->logContent('userLink | PostData = ' . json_encode($postData), 'info', 'amazon' . DS . 'user_linking.log');

        // Required parameters
        $requiredParams = ['data', 'marketplace', 'app_code', 'widget'];
        foreach ($requiredParams as $param) {
            if (!isset($postData[$param])) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Missing required parameters.'
                ]);
            }
        }

        $helper = $this->di->getObjectManager()->get('\App\Core\Components\Helper');
        $configData = $this->di->getConfig()->toArray();

        // Decode JWT tokens
        $publicKey = $configData['apiconnector'][$postData['marketplace']][$postData['app_code']]['public_key'];
        $decodedData = $helper->decodeToken($postData['data'], false, base64_decode($publicKey));
        $decodedState = isset($postData['state']) ? $helper->decodeToken($postData['state'], false) : null;

        $this->di->getLog()->logContent('userLink | user profile data = ' . json_encode($decodedData) . " state = " . json_encode($decodedState), 'info', 'amazon' . DS . 'user_linking.log');

        $amazonUserId = $decodedData['data']['data']->user_id ?? null;
        $amazonUserData = [
            'email' => $decodedData['data']['data']->email ?? null,
            'name' => $decodedData['data']['data']->name ?? null,
            'postal_code' => $decodedData['data']['data']->postal_code ?? null
        ];

        $shopUrl = $decodedState['data']['shop'] ?? null;
        $loggedInUserId = $decodedState['data']['login_user_id'] ?? null;
        $finalAmazonRedirectURI = $decodedState['data']['finalAmazonRedirectURI'] ?? null;

        // Performing user_id 
        $userLink = $this->di->getObjectManager()->get(LinkedUser::class);
        $linkUserResponse = $userLink->linkUserToSeller($amazonUserId, $amazonUserData, $shopUrl, $loggedInUserId, $postData);
        $this->di->getLog()->logContent('linkUserResponse = ' . json_encode($linkUserResponse), 'info', 'amazon' . DS . 'user_linking.log');
        return $this->response->redirect($finalAmazonRedirectURI);
    }

    public function displayError($message, $widget)
    {
        $frontendErrorPage = $this->di->getConfig()->get('widget_configuration')->get($widget)->get('error_page_url');
        return $this->response->redirect($frontendErrorPage . "?message=" . urlencode($message), true);
    }
    private function redirectToShopSwitcher($widget, $amazonUserId)
    {
        $shopSwitcherPageUrl = $this->di->getConfig()->get('widget_configuration')->get($widget)->get('shop_switcher_page_url');
        return $this->response->redirect("{$shopSwitcherPageUrl}?widget={$widget}&amazon_user_id={$amazonUserId}");
    }
    public function getLinkedShopByAmazonIdAction()
    {
        $postData = $this->getRequestData();

        // Required parameters
        if (empty($postData['amazon_user_id']) || empty($postData['widget'])) {
            return $this->prepareResponse([
                'success' => false,
                'message' => 'Missing required parameters: amazon_user_id, widget'
            ]);
        }

        $userLink = $this->di->getObjectManager()->get(LinkedUser::class);
        $linkedUser = $userLink->getLinkedAccounts($postData['amazon_user_id'], $postData['widget'], $postData['user_id'] ?? null, $postData['seller_id'] ?? null);

        if (!empty($linkedUser)) {
            return $this->prepareResponse([
                'success' => true,
                'linked_shops' => $linkedUser
            ]);
        } else {
            return $this->prepareResponse([
                'success' => false,
                'message' => 'Amazon user not linked with any shop.'
            ]);
        }
    }

    public function getUserTokenByAmazonIdAction()
    {
        $postData = $this->getRequestData();

        // Required parameters
        if (empty($postData['amazon_user_id']) || empty($postData['widget'])) {
            return $this->prepareResponse([
                'success' => false,
                'message' => 'Missing required parameters: amazon_user_id, widget'
            ]);
        }

        $userLink = $this->di->getObjectManager()->get(LinkedUser::class);
        $linkedUser = $userLink->getLinkedAccounts($postData['amazon_user_id'], $postData['widget']);

        if (!empty($linkedUser)) {
            if (count($linkedUser) > 1) {
                return $this->redirectToShopSwitcher($postData['widget'], $postData['amazon_user_id']);
            }

            if (count($linkedUser) === 1) {
                $linkedUser = $linkedUser[0];
                $shop = $linkedUser['seller_username'];
                $userId = (string) ($linkedUser['user_id'] ?? '');

                $user = \App\Core\Models\User::findFirst([['_id' => $userId]]);
                $user->id = (string) $user->_id;
                $this->di->setUser($user);

                return $this->prepareResponse([
                    'success' => true,
                    'data' => [
                        'user_token' => $user->getToken(),
                        'shop' => $shop
                    ]
                ]);
            }
        } else {
            return $this->prepareResponse([
                'success' => false,
                'message' => 'Invalid Amazon user id.'
            ]);
        }
    }

    public function widgetAccessAction()
    {
        $params = $this->getRequestData();
        // Validate required parameters
        if (empty($params['widget']) || empty($params['auth_code'])) {
            return $this->prepareResponse([
                'success' => false,
                'message' => 'Missing required parameters: auth_code or widget.'
            ]);
        }

        // Retrieve Amazon user ID using auth_code
        $userLinking = $this->di->getObjectManager()->get(UserLinking::class);
        $amzUserIdResponse = $userLinking->getAmzUserIdByAuthCode($params);

        if (!$amzUserIdResponse['success'] || empty($amzUserIdResponse['amazon_user_id'])) {
            return $this->prepareResponse($amzUserIdResponse);
        }

        $amazonUserId = $amzUserIdResponse['amazon_user_id'];

        // Get linked accounts
        $linkedUser = $this->di->getObjectManager()->get(LinkedUser::class);
        $linkedAccounts = $linkedUser->getLinkedAccounts($amazonUserId, $params['widget']);

        // Process linked accounts
        if (count($linkedAccounts) === 1) {
            $linkedAccount = $linkedAccounts[0];
            $shop = $linkedAccount['seller_username'] ?? null;
            $userId = (string) ($linkedAccount['user_id'] ?? '');

            // Retrieve user token
            $user = \App\Core\Models\User::findFirst([['_id' => $userId]]);
            if (!$user) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Linked user not found.'
                ]);
            }

            $user->id = (string) $user->_id;
            $this->di->setUser($user);

            // Prepare success response
            return $this->prepareResponse([
                'success' => true,
                'data' => [
                    'shop' => $shop,
                    'amazon_user_id' => $amazonUserId,
                    'user_token' => $user->getToken()
                ]
            ]);
        }

        return $this->prepareResponse([
            'success' => false,
            'message' => 'User linking is not completed successfully.'
        ]);
    }
}
