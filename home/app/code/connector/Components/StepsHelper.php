<?php

namespace App\Connector\Components;

use Aws\S3\S3Client;

/**
 * helper class for steps completed at frontend controller
 */
class StepsHelper
{
    const STEP_COMPLETED = 'stepsCompleted';

    public function getStepsDetails(array $data): array
    {
        $sourceShopId = $this->di->getRequester()->getSourceId();

        $targetShopId = $this->di->getRequester()->getTargetId();

        $app_tag = $this->di->getAppCode()->getAppTag();
        $source = $this->di->getRequester()->getSourceName();

        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get('\App\Core\Models\Config\Config');
        $user_id = $this->di->getUser()->id;
        $configObj->setUserId($user_id);

        if (!($sourceShopId) || !($app_tag) || !($source)) {
            return ['success' => false, 'message' => 'Source shop or Source or app tag not found'];
        }

        $configObj->sourceSet($source);
        $configObj->setSourceShopId($sourceShopId);
        $configObj->setAppTag($app_tag);
        $configObj->setTargetShopId($targetShopId);
        $data['app_tag'] = $app_tag;

        $res = $configObj->getConfig($data['key'] ?? self::STEP_COMPLETED);
        $res = json_decode(json_encode($res, true), true);
        if (!empty($res)) {
            return ['success' => true, 'stepsCompleted' => $res[0]['value']];
        }

        return ['success' => true, 'stepsCompleted' => 0];
    }

    public function saveStepsDetails(array $data): array
    {
        $sourceShopId = $this->di->getRequester()->getSourceId();
        $targetShopId = $this->di->getRequester()->getTargetId();

        $app_tag = $this->di->getAppCode()->getAppTag();
        $source = $this->di->getRequester()->getSourceName();
        $target = $this->di->getRequester()->getTargetName();

        if (!($sourceShopId) || !($app_tag) || !($source)) {
            return ['success' => false, 'message' => 'Source shop or Source or app tag not found'];
        }

        if (!isset($data['stepsCompleted'])) {
            if ((isset($data['key']) && empty($data[$data['key']]))) {
                return ['success' => false, 'message' => 'Steps completed value missing'];
            }
            $data['stepsCompleted'] = $data[$data['key']] ?? 0;
        }

        $data['source'] = $source;
        $data['app_tag'] = $app_tag;
        $data['source_shop_id'] = $sourceShopId;
        $data['target_shop_id'] = $targetShopId;
        $data['target'] = $target;

        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get(\App\Core\Models\Config\Config::class);
        $configObj->setUserId();
        $data['key'] = $data['key'] ?? self::STEP_COMPLETED;
        $data['value'] = $data[$data['key']] ?? $data['stepsCompleted'];
        $data['group_code'] = $target;
        $res = $configObj->setConfig([$data]);
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:afterSaveStepsDetails', $this, $data);
        return ['success' => true, 'data' => $res];
    }

    public function getUploadFileUrl(string $fileName)
    {
        $filePath = BP . DS . '/var/file/images/' . $fileName;
        $s3Client = new S3Client(include BP . '/app/etc/aws.php');
        $result = $s3Client->putObject([
            'Bucket' => $this->di->getConfig()->get('s3_bucket'),
            'Key' => basename($filePath),
            'Body' => fopen($filePath, 'r'),
            'ACL' => 'public-read', // make file 'public'
        ]);
        $imageUrl = $result->get('ObjectURL');
        return $imageUrl;
    }

    public function uploadImageInS3Bucket(string $fileName)
    {

        $response = $this->getUploadFileUrl($fileName);

        if ($response) {
            $data = $fileName;
            $dir = BP . DS . '/var/file/images';
            $dirHandle = opendir($dir);
            while ($file = readdir($dirHandle)) {
                if ($file == $data) {
                    unlink($dir . '/' . $file);
                }
            }

            closedir($dirHandle);
        }

        return $response;
    }
}
