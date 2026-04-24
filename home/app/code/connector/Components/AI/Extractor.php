<?php

namespace App\Connector\Components\AI;

use App\Core\Models\BaseMongo;

use GuzzleHttp\Client;


class Extractor extends BaseMongo
{



    public function callAPI($url, $headers, $body, $type)
    {
        // echo $url;
        $client = new Client(["verify" => false]);
        if ($type == 'POST') {
            $response =   $client->post($url, ['headers' => $headers, 'json' => $body, 'http_errors' => false]);
        } elseif ($type == 'GET') {
            $response = $client->get($url, ['headers' => $headers, 'query' => $body, 'http_errors' => false]);
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


    public function getKeywordsFromASIN($data = [])
    {
        // $this->aboveLayer([]);
        $item = $data['attributes'] ?? [];
        $item_name = $item['item_name'][0]['value'] ?? '';
        $bullet_point = $item['bullet_point'] ?? [];

        $formatted_bullet_points = "";

        foreach ($bullet_point as $bullet) {
            $formatted_bullet_points .= $bullet['value'] . " ";
        }

        $specific_uses_for_product = $item['specific_uses_for_product'][0]['value'] ?? '';

        $special_feature = $item['special_feature'][0]['value'] ?? '';

        $prompt = [
            [
                'role' => 'system',
                'content' => 'You will be provided with a json object with key as "item_name" , "bullet_point" , "specific_uses_for_product" , "special_feature" . You need to provide keywords used in this product. Do not add any extra kwywords from your side . Always reply in JSON format with key as "keywords" and value as array of keywords.'
            ],
            [
                'role' => 'user',
                'content' => " [
                    'item_name' => {$item_name},
                    'bullet_point' => {$formatted_bullet_points},
                    'specific_uses_for_product' => {$specific_uses_for_product},
                    'special_feature' => {$special_feature}
                ] "
            ]
        ];

        $suggestionsObj = new \App\Connector\Components\AI\getSuggestions;
        $res = $suggestionsObj->getChatRes([], $prompt);

        $resF =  json_decode($res['choices'][0]['message']['content'], true) ?? [];

        return $resF;
    }


    public function getRemoteShopId()
    {
        $user_details = $this->di->getUser()->toArray();
        $this->_target_id =  $this->di->getRequester()->getTargetId();
        $remote_shop_id = '';
        if (isset($user_details['shops'])) {
            foreach ($user_details['shops'] as $value) {
                if ($value['_id'] == $this->_target_id) {
                    $remote_shop_id = $value['remote_shop_id'];
                }
            }
        }

        return $remote_shop_id; 
        
    }

    public function aboveLayer($data)
    {
        $url = 'https://dev-amazon-sales-channel-api-backend.cifapps.com/webapi/rest/v1/product';
        $headers = [
            'Authorization' => 'Bearer ' . $this->di->getConfig()->asin_token
        ];

        $asin = $data['asin'];

        $sAppId = $this->di->getConfig()->apiconnector->get('amazon')->default->get('sub_app_id');
        $res = $this->callAPI($url, $headers, ['sAppId' => $sAppId, 'shop_id' => $this->getRemoteShopId(), 'id' => [$asin], 'id_type' => 'ASIN','included_data' => 'attributes'], 'GET');
        $getKeywords = [];
        if ($res['success'] == true && isset($res['response'][$asin])) {
            $getKeywords =  $this->getKeywordsFromASIN($res['response'][$asin]);
        }

       if(count($getKeywords)){
        return ['success' => true , 'data' => $getKeywords , 'message' => 'Fetched Successfully'];
       }

       return ['success' => false , 'data' => $getKeywords , 'message' => 'No data Found'];
    }
}
