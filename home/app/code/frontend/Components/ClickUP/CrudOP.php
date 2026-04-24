<?php
namespace App\Frontend\Components\ClickUP;

use App\Core\Components\Base;
use GuzzleHttp\Client;

class CrudOP extends Base
{
    private function isJson($string): bool
    {
        json_decode((string) $string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    public function callAPI($url, $headers, $body, $type)
    {
        // echo $url;
        $client = new Client(["verify" => false]);
        if ($type=='POST') {
            $response =   $client->post($url, ['headers' => $headers, 'json' => $body, 'http_errors' => false]);
        } elseif ($type =='GET') {
            $response = $client->get($url, ['headers' => $headers, 'query' => [], 'http_errors' => false]);
        } elseif ($type=='PUT') {
            $response =  $client->put($url, ['headers' => $headers, 'json' => $body, 'http_errors' => false]);
        } elseif ($type =="DELETE") {
            $response = $client->delete($url, ['headers' => $headers,'http_errors' => false]);
        } else {
            $response = $client->get($url, ['headers' => $headers, 'query' => [], 'http_errors' => false]);
        }

        $bodyContent = $response->getBody()->getContents();
        $headersContent=$response->getHeaders();

        if (!$this->isJson($bodyContent)) {
            $this->di->getLog()->logContent('Request url : '.$type.' '.$url.PHP_EOL.print_r($body, true).PHP_EOL.'Response data : '.print_r($bodyContent, true), 'info', 'remote_errors.log');
        }

        $res = json_decode((string) $bodyContent, true);

        $res['headers']=$headersContent;
        return $res;
    }

    public function createTask($listID, $body)
    {
        $headers =[
            'Content-Type'=>'application/json',
            'Authorization'=>$this->di->getConfig()->getClikupAUTH
        ];
        $url='https://api.clickup.com/api/v2/list/'.$listID.'/task';
        $response=$this->callAPI($url, $headers, $body, 'POST');
        $response['new_task_created']=true;
        return $response;
    }

    public function getAllListTask($listID)
    {
        $headers =[
            'Content-Type'=>'application/json',
            'Authorization'=>$this->di->getConfig()->getClikupAUTH
        ];
        $url='https://api.clickup.com/api/v2/list/'.$listID.'/task';
        $response=$this->callAPI($url, $headers, [], 'GET');
        return $response;
    }

    public function getTask($taskID)
    {
        $headers =[
            'Content-Type'=>'application/json',
            'Authorization'=>$this->di->getConfig()->getClikupAUTH
        ];
        $url='https://api.clickup.com/api/v2/task/'.$taskID.'/';
        $response=$this->callAPI($url, $headers, [], 'GET');
        return $response;
    }

    public function getFields($listID)
    {
        $headers =[
            'Content-Type'=>'application/json',
            'Authorization'=>$this->di->getConfig()->getClikupAUTH
        ];
        $url='https://api.clickup.com/api/v2/list/'.$listID.'/field';
        $response=$this->callAPI($url, $headers, [], 'GET');
        return $response;
    }

    public function updateTask($taskID, $body=[], $listID="")
    {
        $headers =[
            'Content-Type'=>'application/json',
            'Authorization'=>$this->di->getConfig()->getClikupAUTH
        ];
        $url='https://api.clickup.com/api/v2/task/'.$taskID.'/';
        $response=$this->callAPI($url, $headers, $body, 'PUT');
        if ($listID!="") {
            if (isset($response['err'])) {
                $response=$this->createTask($listID, $body);
            }
        }

        return $response;
    }

    public function updateField($taskID, $fieldID, $body, $listID)
    {
        $headers =[
            'Content-Type'=>'application/json',
            'Authorization'=>$this->di->getConfig()->getClikupAUTH
        ];
        $url='https://api.clickup.com/api/v2/task/'.$taskID.'/field/'.$fieldID.'/';
        $response=$this->callAPI($url, $headers, $body, 'POST');
        return $response;
    }

    public function DeleteFieldValue($taskID, $fieldID, $body, $listID)
    {
        $headers =[
            'Content-Type'=>'application/json',
            'Authorization'=>$this->di->getConfig()->getClikupAUTH
        ];
        $url='https://api.clickup.com/api/v2/task/'.$taskID.'/field/'.$fieldID.'/';
        $response=$this->callAPI($url, $headers, $body, 'DELETE');
        $response['deleted']='true21';
        return $response;
    }
}
