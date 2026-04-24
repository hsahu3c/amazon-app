<?php
namespace App\Connector\Components;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3 extends \App\Core\Components\Base
{
    public $S3Client;

    public function setUniqueKeys($uniqueKeys)
    {
        $this->uniqueKeys = $uniqueKeys;
        return $this->uniqueKeys;
    }

    public function getUniqueKeys()
    {
        return $this->uniqueKeys;
    }


    public function setDetails($config)
    {
        if (empty($config)) {
            $client = S3Client::factory(include BP . '/app/etc/dynamo.php');
            $this->S3Client = $client;
            return $this->S3Client;
        }

        $client = S3Client::factory($config);
        $this->S3Client = $client;
        return $this->S3Client;
    }

    public function getFile($data)
    {
        $s3Client = new S3Client(include BP . '/app/etc/dynamo.php');
        $result = $s3Client->getObject([
            'Bucket' => $data['bucket'],
            'Key' => $data['key']
        ]);
        $body = $result['Body'];
        $data = json_decode($body, true);

        return $data;
    }


    public function save($data)
    {
        $s3Client = new S3Client(include BP . '/app/etc/dynamo.php');
        $jsonData = json_encode($data);
        // Specify the bucket name and key (filename) of the object to retrieve

        $result = $s3Client->putObject([
            'Bucket' => $data['bucket'],
            'Key' => $data['key'],
            'Body' => json_encode($data['dataToUpload']),
            'ContentType' => 'application/json'
        ]);
        if ($result['@metadata']['statusCode'] == 200) {
            return ['success' => true, 'message' => "data saved Successfully"];
        }

        return ['success' => false, 'message' => "unique key or unique table column is not defined"];

    }

}