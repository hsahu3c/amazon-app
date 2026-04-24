<?php

namespace App\Connector\Api;

use App\Core\Models\BaseMongo;

use GuzzleHttp\Client;

class PineCone extends BaseMongo
{
    public $_base_url;

    public $_api_key;

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
        $pinecone = $this->di->getConfig()->pinecone->toArray();

        $this->_base_url = 'https://' . $pinecone['PINECONE_INDEX_NAME'] . '-' . $pinecone['PINECONE_PROJECT_ID'] . '.svc.' . $pinecone['PINECONE_ENVIRONMENT'] . '.pinecone.io/';

        $this->_api_key = $pinecone['PINECONE_API_KEY'];
    }

    public function getHeaders()
    {
        return [
            'accept' => 'application/json',
            'content-type' => 'application/json',
            'Api-Key' => $this->_api_key
        ];
    }

    public function querySearch($body)
    {
        $this->createBaseUrl();
        $headers = $this->getHeaders();
        $url = $this->_base_url . 'query';
        $response = $this->callAPI($url, $headers, $body, 'POST');
        return $response;
    }
}
