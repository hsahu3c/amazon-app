<?php

namespace App\Amazon\Components;

use App\Core\Components\Base;

class UserLinking extends Base
{

    public function getAmzUserIdByAuthCode($params)
    {
        try {
            $appDataRes = $this->getAppDataFromRemoteDb();
            if (!$appDataRes['success']) {
                return $appDataRes;
            }
            $appData = $appDataRes['appData'];

            $requestParams = [
                'grant_type' => 'authorization_code',
                'code' => $params['auth_code'],
                'client_id' =>  $appData['EU']['emb_client_id'] ?? $appData['EU']['clientId'],
                'client_secret' =>  $appData['EU']['emb_client_secret'] ?? $appData['EU']['clientSecret'],
                'redirect_uri' => $appData['EU']['emb_redirect_uri'] ?? $appData['EU']['redirect_uri'],
            ];
            $this->di->getLog()->logContent('getAmzUserIdByAuthCode | requestParams that sends to amz to get token = ' . json_encode($requestParams), 'info', 'amazon' . DS . 'widget_access.log');

            // access token get using authorization code
            $client = new \GuzzleHttp\Client();
            $res = $client->post('https://api.amazon.com/auth/o2/token', [
                \GuzzleHttp\RequestOptions::JSON => $requestParams
            ]);
            $body = json_decode($res->getBody(), true);
            $this->di->getLog()->logContent('getAmzUserIdByAuthCode | auth/o2/token api response = ' . json_encode($body), 'info', 'amazon' . DS . 'widget_access.log');

            if (!isset($body['access_token'])) {
                return ['success' => false, 'message' => "Not getting amazon access token using auth_code."];
            }

            // user profile data get with user access token
            $accessToken = $body['access_token'];
            $response = $client->get('https://api.amazon.com/user/profile', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken
                ]
            ]);
            $userData = json_decode($response->getBody(), true);
            $this->di->getLog()->logContent('getAmzUserIdByAuthCode | user/profile api response = ' . json_encode($userData), 'info', 'amazon' . DS . 'widget_access.log');

            if (empty($userData) || !isset($userData['user_id'])) {
                return ['success' => false, 'message' => "Not getting amazon user profile using access token."];
            } else {
                return ['success' => true, 'amazon_user_id' => $userData['user_id']];
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->di->getLog()->logContent('RequestException getResponse = ' . json_encode($e->getResponse()->getBody()->getContents()), 'info', 'amazon' . DS . 'widget_access.log');
            $this->di->getLog()->logContent('RequestException message  = ' . json_encode($e->getMessage()), 'info', 'amazon' . DS . 'widget_access.log');
            return ['success' => false, 'message' => "Something went wrong in getting amazon access token or user profile."];
        } catch (\Exception $e) {
            $this->di->getLog()->logContent('exception message  = ' . json_encode($e->getMessage()), 'info', 'amazon' . DS . 'widget_access.log');
            return ['success' => false, 'message' => "Something went wrong in getting amazon access token or user profile."];
        }
    }

    public function getAppDataFromRemoteDb(): array
    {
        // Getting Amazon app ID from the config
        $remoteAmazonAppId = $this->di->getConfig()->get('remote_amazon_app_id');
        if (empty($remoteAmazonAppId)) {
            return [
                'success' => false,
                'message' => "Amazon app ID not found in config."
            ];
        }

        // Remote database connection
        $remotedb = $this->di->get('remote_db');
        if (!$remotedb) {
            return [
                'success' => false,
                'message' => "Remote database connection is not available."
            ];
        }

        try {
            // Fetching 'apps' data from db
            $appsCollection = $remotedb->selectCollection('apps');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
            $appData = $appsCollection->findOne(['_id' => (string)$remoteAmazonAppId], $options);

            if (!$appData) {
                return [
                    'success' => false,
                    'message' => "No app data found for the given Amazon app ID."
                ];
            }
            return [
                'success' => true,
                'appData' => $appData
            ];
        } catch (\Exception $e) {
            // Handle any exceptions that occur
            return [
                'success' => false,
                'message' => "An error occurred while fetching app data: " . $e->getMessage()
            ];
        }
    }
}
