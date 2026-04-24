<?php

namespace App\Connector\Api;

use App\Core\Models\BaseMongo;

use GuzzleHttp\Client;

class GoogleAdsApi extends BaseMongo
{
    public $_base_url;

    public $_api_version = 'v14';

    public $_test_customer_id = '5464584042';

    public $_re_initaite_count = 0;

    /**
     *
     * @param [array] $url , $headers , $body , $type
     * $url type string
     * $headers type array
     * $body type array
     * $type type string
     * @return array
     */
    public function callAPI($url, $headers, $body, $type)
    {
        // echo $url;
        $client = new Client(["verify" => false]);
        if ($type == 'POST') {
            $response =   $client->post($url, ['headers' => $headers, 'json' => $body, 'http_errors' => false]);
        } elseif ($type == 'GET') {
            $response = $client->get($url, ['headers' => $headers, 'query' => [], 'http_errors' => false]);
        } elseif ($type == 'PUT') {
            $response =  $client->put($url, ['headers' => $headers, 'json' => $body, 'http_errors' => false]);
        } elseif ($type == "DELETE") {
            $response = $client->delete($url, ['headers' => $headers, 'http_errors' => false]);
        } else {
            $response = $client->get($url, ['headers' => $headers, 'query' => [], 'http_errors' => false]);
        }

        $bodyContent = $response->getBody()->getContents();
        $headersContent = $response->getHeaders();

        $res = json_decode($bodyContent, true);

        $res['headers'] = $headersContent;
        return $res;
    }

    /**
     * login to google ads api : get access token and save it in db and cache for future use and return access token to use in header for api call
     * @return string
     */
    public function getAccessToken()
    {
        $access_token = $this->di->getCache()->get("gcp_access_token");
        if ($access_token) {
            return $access_token;
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $ai_service_table =  $mongo->getCollectionForTable('ai_services');
        $access_token = $ai_service_table->findOne(['type' => "access_token"]);

        $this->di->getCache()->set("gcp_access_token", $access_token['access_token']);

        if (!isset($access_token['access_token'])) {
            return $this->updateAuthToken();
        }

        return $access_token['access_token'];
    }

    public function createBaseUrl(): void
    {
        $this->_base_url = "https://googleads.googleapis.com/" . $this->_api_version . "/customers/" . $this->_test_customer_id . ":";
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return [
            'accept' => 'application/json',
            'content-type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'developer-token' => $this->di->getConfig()->googleAdsDeveloperToken
        ];
    }
    
    /**
     * get keyword historical metrics
     * @param [array] $body
     * $body type array
     * @return array
     */
    public function getKeywordHistoricalMetrics($body)
    {

        $this->createBaseUrl();
        $headers = $this->getHeaders();
        $url = $this->_base_url . 'generateKeywordHistoricalMetrics';
        $response = $this->callAPI($url, $headers, $body, 'POST');
        if (isset($response['error']) && $response['error']['status'] === 'UNAUTHENTICATED') {
            return $this->updateAuthToken($body, true);
        }

        return $response;
    }

    /**
     * @param [array] $body , $reIntiate = false 
     * $body type array
     * $reIntiate type boolean
     * @return array
     */
    public function updateAuthToken($body = [], $reIntiate = false)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $ai_service_table =  $mongo->getCollectionForTable('ai_services');
        $generateToken = new \App\Connector\Api\Helper\GetGCPAccessToken;
        $response = $generateToken->generateNewAccessToken();
        $access_token = "";
        if (isset($response['success']) && $response['success']) {
            $access_token = $response['access_token'];
            $ai_service_table->updateOne(['type' => "access_token"], ['$set' => ['access_token' => $access_token]], ['upsert' => true]);
            $this->di->getCache()->set("gcp_access_token", $access_token);
        }

        if ($reIntiate) {
            return $this->getKeywordHistoricalMetrics($body);
        }

        return $access_token;
    }
}
