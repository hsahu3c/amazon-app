<?php

namespace App\Amazon\Components\ChangeStream;

use App\Core\Components\Base as Base;
use Exception;
use Aws\S3\S3Client;


class Helper extends Base
{
    const SUPPORTED_EVENTS = ['price'];

    public function init($data)
    {
        $key = isset($data['key']) ? $data['key'] : (isset($data['data']['key']) ? $data['data']['key'] : null);
        $streamData = isset($data['data']['data']) ? $data['data']['data'] : [];
        $messageArray = [];
        try {
            if ($data['type'] === 's3Message') {
                $s3Client = new S3Client(include BP . '/app/etc/aws.php');
                $result = $s3Client->getObject(array(
                    'Bucket' => $data['bucket'],
                    'Key' => $data['id'],
                ));
                $messageArray = json_decode($result['Body'], true);
                $streamData = $messageArray['Detail']['data'] ?? [];
                $key = 's3message';
            }
        } catch(Exception $e) {
            return true;
        }
        if (is_null($key) || empty($streamData)) {
            return [
                'success' => false,
                'message' => 'key/data not found'
            ];
        }
        if (in_array($key, self::SUPPORTED_EVENTS)) {
            $formatedData = $this->getFormatedData($streamData, $key);
            if (!empty($formatedData)) {
                $this->processStreamData($formatedData, $key);
            }
        } else {
            return [
                'success' => false,
                'message' => 'The provided event is not supported!'
            ];
        }
    }

    private function getFormatedData(array $streamData, string $key = "s3"): array
    {
        $nextPrevState = [];
        $containerIds = [];
        $shopIds = [];
    
        foreach ($streamData as $data) {
            $currentValue = $data['currentValue'] ?? [];
            $beforeValue = $data['beforeValue'] ?? [];
            $data['update_type'] = $key;
    
            $shopId = $currentValue['shop_id'] ?? null;
            $sourceProductId = $currentValue['source_product_id'] ?? null;
            $containerId = $currentValue['container_id'] ?? null;
    
            if ($containerId !== null) {
                $containerIds[$containerId] = true;
            }
    
            if ($shopId !== null) {
                $shopIds[$shopId] = true;
            }
    
            //this is done because we want to track in which marketplace the change happend and if we add this means in case the change is done at the target edited doc then we are searching the shopify and the amaozn both docs which will not be correct for further logic
            // if (!empty($currentValue['source_shop_id'])) {
            //     $shopIds[$currentValue['source_shop_id']] = true;
            // }
    
            if (!isset($nextPrevState[$shopId][$sourceProductId])) {
                $nextPrevState[$shopId][$sourceProductId] = $data;
            } else {
                $operationType = $data['operationType'] ?? null;
    
                if ($operationType === 'insert') {
                    $beforeValue = [];
                }
    
                $existingBeforeValue = $nextPrevState[$shopId][$sourceProductId]['beforeValue'] ?? [];
    
                foreach ($beforeValue as $key => $value) {
                    if (isset($existingBeforeValue[$key]) && !is_array($existingBeforeValue[$key])) {
                        $existingBeforeValue[$key] = [$existingBeforeValue[$key]];
                    }
    
                    $existingBeforeValue[$key][] = $value;
                }
    
                // Merge currentValue and beforeValue into the existing state
                $nextPrevState[$shopId][$sourceProductId]['currentValue'] = array_merge(
                    $nextPrevState[$shopId][$sourceProductId]['currentValue'] ?? [],
                    $currentValue
                );
    
                $nextPrevState[$shopId][$sourceProductId]['beforeValue'] = $existingBeforeValue;
            }
        }
    
        return [
            'containerIds' => array_keys($containerIds),
            'shopIds' => array_keys($shopIds),
            'nextPrevData' => $nextPrevState
        ];
    }
    

    public function processStreamData($streamData, $key)
    {
        $processSync = $this->di->getObjectManager()->get(ProcessSync::class);
        if ($key == 'price' || $key == 'compare_at_price') {
            $processSync->priceSync($streamData);
        }
        if ($key == 'image') {
            $processSync->imageSync($streamData);
        }
    }
}