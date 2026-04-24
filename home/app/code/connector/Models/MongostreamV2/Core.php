<?php

namespace App\Connector\Models\MongostreamV2;

use App\Connector\Models\MongostreamV2\Helper;
use App\Connector\Models\MongostreamV2\ProductTimeline;
use Aws\S3\S3Client;

class Core extends Helper
{
    public function receiveSqsMessage($data)
    {
        $data = $this->checkDataInfo($data);

        $this->sendData($data);

        return true;
        /*$allUserInfo = $this->getAllUserInfo($data['userIds']);
        //initaite Product TimeLime
        (new ProductTimeline)->initiate($data['data']);

        try {
            foreach ($data['data'] as $value) {
                if (isset($value['user_id'])) {
                    $this->setUserInDi($allUserInfo[$value['user_id']]);
                    $preParedData = $this->prepareData($value);
                    $this->sendData($preParedData);
                }
            }

            return true;
        } catch (\Exception) {
            // write the the $data and error message in a file and then return true
            return true;
        }*/
    }

    public function checkDataInfo($data)
    {
        if (isset($data['data']['s3Key'], $data['data']['s3Bucket'])) {
            $data = $this->getDataFromS3($data['data']);
        }

        return $data;
    }

    public function getDataFromS3($s3Info)
    {
        $s3Client = new S3Client(include BP . '/app/etc/aws.php');
        $result = $s3Client->getObject(array(
            'Bucket' => $s3Info['s3Bucket'],
            'Key' => $s3Info['s3Key'],
        ));
        $messageArray = json_decode($result['Body'], true);

        return $messageArray;
    }
}
