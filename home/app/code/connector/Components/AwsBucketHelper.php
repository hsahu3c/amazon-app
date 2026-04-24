<?php

namespace App\Connector\Components;

use Aws\S3\S3Client;

class AwsBucketHelper extends \App\Core\Components\Base
{
    public $s3Client;

    public $bucket;

    public function setBucket($bucket)
    {
        $this->bucket=$bucket;
        return $this->bucket;
    }

    public function getBucket()
    {
        return $this->bucket;
    }

    public function setDetails($config=[])
    {
        if (empty($config)) {
            $config = include BP . '/app/etc/aws.php';
            $s3Client = new S3Client($config);
            $this->s3Client=$s3Client;
            return $this->s3Client;
        }

        $s3Client = new S3Client($config);
        $this->s3Client=$s3Client;
        return $this->s3Client;
    }

    public function getDetails()
    {
        if (is_null($this->dynamoDbClient)) {
            $config = include BP . '/app/etc/aws.php';
            $s3Client = new S3Client($config);
            $this->s3Client=$s3Client;
            return $this->s3Client;
        }

        return $this->s3Client;
    }

    public function get($data)
    {
        $bucket = $this->getBucket();
        $client = $this->getDetails();

        if (!empty($bucket) && !empty($data['key'])) {
            $query = [];
            if(isset($data['save_as'])){
                $response=$client->getObject(array(
                    'Bucket' => $data['bucket']??"amazon-order-shipment-log",
                    'Key' => $data['key']??"57a542536c214ed5b82b7a877c6a0c3a",
                    'SaveAs' => $data['key']??"57a542536c214ed5b82b7a877c6a0c3a"
                ));
            }
            else{
                $response=$client->getObject(array(
                    'Bucket' => $data['bucket']??"amazon-order-shipment-log",
                    'Key' => $data['key']??"57a542536c214ed5b82b7a877c6a0c3a",
                ));
            }

            return ['success' => true, 'message' => "data retrieved Successfully", 'data' => $response];
        }

        return ['success' => false, 'message' => "Bucket or Key is not defined"];
    }
}
