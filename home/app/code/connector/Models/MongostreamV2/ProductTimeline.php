<?php

namespace App\Connector\Models\MongostreamV2;

use App\Core\Models\BaseMongo;

use Aws\S3\S3Client;

class ProductTimeline extends BaseMongo
{

    public function initiate($data): void
    {
        $productActivity =  $this->getCollectionForTable('product_activity');

        $productActivity->insertMany($data);
    }

    public function tryParser($jsonString)
    {
        try {
            $data = json_decode($jsonString, true);
            return $data;
        } catch (\Exception) {
            return false;
        }
    }

    public function getDataFromS3($s3Info)
    {
        $s3Client = new S3Client(include BP . '/app/etc/aws.php');
        $result = $s3Client->getObject(array(
            'Bucket' => $s3Info['s3Bucket'],
            'Key' => $s3Info['s3key'],
        ));
        $messageArray = json_decode($result['Body'], true);

        return $messageArray;
    }
}
