<?php

namespace App\Connector\Components\Profile;

use App\Core\Models\BaseMongo;

class DefaultSettings extends BaseMongo
{

    public $settings = ["pricing_settings", "inventory_settings", "product_settings"];

    public function saveDefaultSettings($data)
    {
        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get('\App\Core\Models\Config\Config');
        $configData = [];
        $true = true;
        $appTag = $this->di->getAppCode()->getAppTag() ?? 'default';
        $sourceShopId = isset($data['source']['shopId']) ? $data['source']['shopId'] : $this->di->getRequester()->getSourceId();
        $targetShopId = isset($data['target']['shopId']) ? $data['target']['shopId'] : $this->di->getRequester()->getTargetId();

        foreach ($data['data'] as $value) {
            if (!(isset($value['group_code']))) {
                $true = false;
            }

            foreach ($value['data'] as $dK => $dV) {
                $configData[] = [
                    'key' => $dK,
                    'value' => $dV,
                    'group_code' => $value['group_code'],
                    'source_shop_id' => $sourceShopId,
                    'target_shop_id' => $targetShopId,
                    'app_tag' => $appTag
                ];
            }
        }

        if ($true) {
            $configObj->setUserId(isset($data['user_id']) ?  $data['user_id'] : $this->di->getUser()->id);
            $dataR = $configObj->setConfig($configData);
            return ['success' => true, 'data' => $dataR, 'message' => 'Saved Successfully'];
        }

        return ['success' => false, 'message' => 'failed to save', 'code' => 'false'];
    }

    public function getDefaultSettings($data)
    {
        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get('\App\Core\Models\Config\Config');
        $configObj->setUserId(isset($data['user_id']) ?  $data['user_id'] : $this->di->getUser()->id);
        $configObj->setSourceShopId(isset($data['source']['shopId']) ? $data['source']['shopId'] : $this->di->getRequester()->getSourceId());
        $configObj->setTargetShopId(isset($data['target']['shopId']) ? $data['target']['shopId'] : $this->di->getRequester()->getTargetId());
        if (!(isset($data['settings']))) {
            return ['success' => false, 'message' => 'Setting array Missing', 'code' => 'data_missing'];
        }

        $settings = $data['settings'];

        $dataTosend = [];
        foreach ($settings as $v) {
            $d1 = [];
            $configObj->setGroupCode($v);

            $data = $configObj->getConfig();

            foreach ($data as $value) {
                $d1[$value['key']] = $value['value'];
            }

            $dataTosend[] = [
                'key' => $v,
                'value' => $d1
            ];
        }

        return ['success' => true, 'message' => 'Data fetched successfully', 'data' => $dataTosend];
    }
}
