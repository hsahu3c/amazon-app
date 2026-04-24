<?php

namespace App\Core\Controllers;

class TokenController extends BaseController
{
    public function getAction()
    {
        $userId = $this->di->getUser()->id;
        $pageSettings = $this->getRequestData();
        if (!isset($pageSettings['count']))
            $pageSettings['count'] = 500;
        if (!isset($pageSettings['activePage']))
            $pageSettings['activePage'] = 1;
        $userGeneratedTokens = new \App\Core\Models\UserGeneratedTokens;
        try {
            $subUserID = $this->di->getUser()->getSubUserId();
        } catch (\Exception $e) {
            print_r($e);
            die;
        }
        return $this->prepareResponse(
            $userGeneratedTokens
                ->getUserGeneratedTokens(
                    $userId,
                    $pageSettings['count'],
                    ($pageSettings['count'] * $pageSettings['activePage']) - $pageSettings['count'],
                    $subUserID
                )
        );
    }
    public function createAction()
    {
        $rawBody = $this->getRequestData();
        try {
            $subUserID = $this->di->getUser()->getSubUserId();
        } catch (\Exception $e) {
            print_r($e);
            die;
        }
        if (isset($subUserID) && $subUserID !== 0) {
            $rawBody['user'] = $subUserID;
        }
        $userGeneratedTokens = new \App\Core\Models\UserGeneratedTokens;
        return $this->prepareResponse($userGeneratedTokens->createToken($rawBody, false));
    }
    public function removeAction()
    {
        $requestData = $this->getRequestData();

        $tokenId = $requestData['token_id'];
        $token = ['token_id' => $tokenId, 'user_id' => $this->di->getUser()->id];
        if (
            $this->di
                ->getObjectManager()
                ->get('App\Core\Components\TokenManager')
                ->removeToken($token)
        ) {
            $response = ['success' => true];
        } else {
            $response = ['success' => false, 'code' => 'unable_to_remove_the_token'];
        }
        try {
            $subUserID = $this->di->getUser()->getSubUserId();
        } catch (\Exception $e) {
            print_r($e);
            die;
        }
        if (isset($subUserID) && $subUserID !== 0) {
            $response['user'] = $subUserID;
        }
        return $this->prepareResponse($response);
    }
    /**
     * Generate a new token from the Refresh Token for user.
     * @input $refreshToken
     * @return array
     */
    public function getTokenByRefreshAction()
    {
        $rawBody = $this->getRequestData();
        $userGeneratedTokens = new \App\Core\Models\UserGeneratedTokens;
        return $this->prepareResponse($userGeneratedTokens->createTokenByRefresh($rawBody));
    }
    public function getThemeRefreshTokenAction()
    {
        $user = \App\Core\Models\User::findFirst([['username' => 'theme_refresh']]);
        if (empty($user)) {
            $baseMongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $baseMongo->getCollection('acl_role');
            $collection->insertOne(
                [
                    "code" => "theme_refresh",
                    "title" => "Theme Refresh",
                    "description" => "Theme Refresh Token",
                    "resources" => ""
                ]
            );
            $collection->createIndex(['code' => 1], ["unique" => true]);
            $user_Details = $this->di->getObjectManager()->get('\App\Core\Models\User');
            $user_Details->createUser([
                'username' => 'theme_refresh',
                'email' => 'puspanjalishukla1@cedcommerce.com',
                'password' => PASS2
            ], 'theme_refresh');
            $user = \App\Core\Models\User::findFirst([['username' => 'theme_refresh']]);
        }

        $tokenObject = [
            "role" => 'theme_refresh',
            "user_id" => $user->user_id
        ];
        $token = $this->di
            ->getObjectManager()
            ->get('\App\Core\Components\Helper')
            ->getJwtToken($tokenObject, 'RS256', true);
        return $this->prepareResponse(['success' => true, 'theme_refresh_token' => $token]);
    }
    public function getThemeTokenAction()
    {
        $rawBody = $this->getRequestData();
        $user = \App\Core\Models\User::findFirst([['username' => 'theme_token']]);
        if (empty($user)) {
            $baseMongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $baseMongo->getCollection('acl_role');
            $collection->insertOne(
                [
                    "code" => "theme_token",
                    "title" => "Theme Token",
                    "description" => "Theme Token",
                    "resources" => ""
                ]
            );
            $collection->createIndex(['code' => 1], ["unique" => true]);
            $user_Details = $this->di->getObjectManager()->get('\App\Core\Models\User');
            $user_Details->createUser([
                'username' => 'theme_token',
                'email' => 'puspanjalishukla2@cedcommerce.com',
                'password' => PASS2
            ], 'theme_token');
            $user = \App\Core\Models\User::findFirst([['username' => 'theme_token']]);
        }
        $tokenObject = [
            "role" => 'theme_token',
            "user_id" => $user->user_id,
            "shop_url" => $rawBody['shop_url'] ?? "test.myshopify.com"
        ];
        $token = $this->di
            ->getObjectManager()
            ->get('\App\Core\Components\Helper')
            ->getJwtToken($tokenObject, 'RS256', true);
        return $this->prepareResponse(['success' => true, 'theme_token' => $token]);
    }
    public function getUserAccessTokenAction()
    {
        $userId = $this->di->getUser()->id;
        $query = $this->request->get();
        $isLongLived = false;
        if (isset($query['long_lived']) && $query['long_lived'] == 1) {
            $isLongLived = true;
        }
        if (!isset($userId)) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'message' => "Trouble in getting access token. User not set."
                ]
            );
        }
        $role = $this->di->getUser()->getRole();
        if (empty($role)) {
            return $this->prepareResponse([
                'success' => false,
                'message' => "Trouble in getting access token. Role not found."
            ]);
        }
        $defaultExpirationTime = new \DateTime(\App\Core\Models\User::DEFAULT_TOKEN_EXPIRATION_TIME);
        $tokenObject = [
            "role" => $role->code,
            "user_id" => $userId,
            "is_requested" => "true",
            "exp" => $defaultExpirationTime->getTimestamp()
        ];

        if ($isLongLived) {
            unset($tokenObject['exp']);
        }
        $token = $this->di
            ->getObjectManager()
            ->get('\App\Core\Components\Helper')
            ->getJwtToken($tokenObject, 'RS256', true);
        return $this->prepareResponse([
            'success' => true,
            'access_token' => $token
        ]);
    }
}
