<?php

namespace App\Connector\Api\Helper;

use Aws\Credentials\Credentials;
use Aws\Signature\SignatureV4;
use Psr\Http\Message\RequestInterface;


class AWSLambda
{
    public $awsConfig;

    public function init(array $data): void
    {
        $this->awsConfig = include BP . DS . 'app' . DS . 'etc' . DS . 'aws.php';
        
        if (isset($data['region'])) {
            $this->awsConfig['region'] = $data['region'];
        }

    }


    public function sign(
        RequestInterface $request,
        string $accessKeyId,
        string $secretAccessKey
    ): RequestInterface {

        $signature = new SignatureV4('lambda', $this->awsConfig['region']);
        $credentials = new Credentials($accessKeyId, $secretAccessKey);

        return $signature->signRequest($request, $credentials);
    }

    /**
     * Invoke AWS Lambda function with IAM credentials
     * @param  $url, $methodType, $headers, $body, $addtionalData
     * $url: AWS Lambda function url , $methodType: POST / GET , $headers: array of headers, $body: array of body, $addtionalData: array of additional data
     * @return array
     */
    public function invokeFunctionWithIAMCred($url, $methodType, $headers, $body, $addtionalData)
    {
        $this->init($addtionalData);
        $request = new \GuzzleHttp\Psr7\Request(
            $methodType,
            $url,
            $headers,
            json_encode($body, true)
        );
        $signed_request = $this->sign($request, $this->awsConfig['credentials']['key'], $this->awsConfig['credentials']['secret']);

        $client = new \GuzzleHttp\Client();
        $response = $client->send($signed_request);

        $bodyContent = $response->getBody()->getContents();

        $headersContent = $response->getHeaders();

        $res = json_decode($bodyContent, true);

        $res['headers'] = $headersContent;
        return $res;
    }
}
