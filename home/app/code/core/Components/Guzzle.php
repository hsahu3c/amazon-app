<?php

namespace App\Core\Components;

use GuzzleHttp\Client;

class Guzzle extends \App\Core\Components\Base
{

    /**
     * @param string $url
     * @param array $headers
     * @param array $data
     * @param string $type
     * @return array/false
     */
    public function call($url, $headers = [], $data = [], $type = 'GET', $dataType = null)
    {
        $client = new Client(["verify" => false]);
        switch ($type) {
            case 'DELETE':
                $response = $client->delete(
                    $url,
                    [
                        'headers' => $headers,
                        (is_null($dataType) ? 'query' : $dataType) => $data
                    ]
                );
                break;
            case 'DELETE/FORM':
                $response = $client->delete(
                    $url,
                    [
                        'headers' => $headers,
                        (is_null($dataType) ? 'form_params' : $dataType) => $data
                    ]
                );
                break;

            case 'POST':
                $response = $client->post(
                    $url,
                    [
                        'headers' => $headers,
                        (is_null($dataType) ? 'form_params' : $dataType) => $data
                    ]
                );
                break;

            case 'PUT':
                $response = $client->put(
                    $url,
                    [
                        'headers' => $headers,
                        (is_null($dataType) ? 'json' : $dataType) => $data
                    ]
                );
                break;

            case 'PATCH':
                $response = $client->patch($url, ['headers' => $headers, (is_null($dataType) ? 'json' : $dataType ) => $data]);
                break;

            default:
                $response = $client->get(
                    $url,
                    [
                        'headers' => $headers,
                        (is_null($dataType) ? 'query' : $dataType) => $data,
                        'http_errors' => false
                    ]
                );
                break;
        }
        $bodyContent = $response->getBody()->getContents();
        if (!$this->isJson($bodyContent)) {
            $this->di->getLog()
                ->logContent(
                    'Request url : ' . $type . ' ' . $url . PHP_EOL . print_r($data, true) . PHP_EOL . 'Response data : ' . print_r($bodyContent, true),
                    'info',
                    'remote_errors.log'
                );
        }
        return json_decode($bodyContent, true);
    }

    private function isJson($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }
}
