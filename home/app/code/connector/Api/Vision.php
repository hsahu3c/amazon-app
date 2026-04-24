<?php

namespace App\Connector\Api;

use App\Core\Models\BaseMongo;

use GuzzleHttp\Client;

class Vision extends BaseMongo
{
    public $_base_url;

    public $_api_version = 'v1p4beta1';

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

    public function createBaseUrl(): void
    {
        $this->_base_url = "https://vision.googleapis.com/$this->_api_version/images:annotate?alt=json&key=" . $this->di->getConfig()->googleVisionApiKey;
    }

    public function getHeaders()
    {
        return [
            'accept' => 'application/json',
            'content-type' => 'application/json'
        ];
    }

    public function getImageAttributes($body)
    {
        $this->createBaseUrl();
        $headers = $this->getHeaders();
        $url = $this->_base_url;
        $response = $this->callAPI($url, $headers, $body, 'POST');
        return $response;
    }
}
