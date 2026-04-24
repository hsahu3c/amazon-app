<?php

namespace App\Connector\Components\Order;

class Helper extends \App\Core\Components\Base
{
    public function settingEnabledForMailSend($sourceShopId, $targetShopId, $key)
    {
        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get(\App\Core\Models\Config\Config::class);
        $configObj->setGroupCode("email");
        $configObj->setAppTag(null);
        $configObj->setUserId($this->di->getUser()->id);

        $emailSettings = $configObj->getConfig('send_email_notifications');
        $val = true;
        if(!empty($emailSettings))
        {
            $emailSettingEnable = false;
            foreach($emailSettings as $setting) {
                if($setting['key'] == 'send_email_notifications' && $setting['value']) {
                    $emailSettingEnable = true;
                }
            }

            if($emailSettingEnable) {
                $configObj->setSourceShopId($sourceShopId);
                $configObj->setTargetShopId($targetShopId);
                $configObj->setAppTag(null);
                $configObj->setUserId($this->di->getUser()->id);
                $sendMailSettings = $configObj->getConfig($key);
                if(!empty($sendMailSettings)) {
                    if (isset($sendMailSettings[0]['value'])) {
                        $val = $sendMailSettings[0]['value'];
                    }
                } else {
                    $val = true;
                }
            } else {
                $val = false;
            }
        }

        return $val;
    }

    public function getUnsubscribeLink($data)
    {
        if(!empty($this->di->getConfig()->get('unsubscribe_url_route'))) {
            $frontendUrl = $this->di->getConfig()->get('unsubscribe_url_route');
            $tokenData = [
                'user_id' => $data['user_id'],
                'setting_name' => $data['setting_name'],
                'app_tag' => $data['app_tag'] ?? null,
                'only_token' => true,
                'action' => 'email_unsubscribe',
                'source_shop_id' => $data['source_shop_id'] ?? null,
                'target_shop_id' => $data['target_shop_id'] ?? null,
                'source' => $data['source'] ?? null,
                'target' => $data['target'] ?? null
            ];
            $mailType = "";
            if($data['setting_name'] == 'email_on_failed_order') {
                $mailType = 'Failed Order Mails';
            } elseif($data['setting_name'] == 'email_on_failed_cancellation') {
                $mailType = 'Failed Cancel Order Mails';
            } elseif($data['setting_name'] == 'email_on_failed_refund') {
                $mailType = 'Failed Refund Order Mails';
            }

            // $this->addLog("", $this->logOrderFile, 'Token url data: '.json_encode($tokenData));
            $token = $this->di->getObjectManager()->get('App\Connector\Components\Emails\Email')->generateTokenForEmailSubscription($tokenData);
            // $this->addLog(json_encode($token), 'token.log');
            if($token['success'] && isset($token['token'])) {
                $unsubscribeLink = $frontendUrl.'?token='.$token['token'].'&mail_type='.$mailType;
                return [
                    'success' => true,
                    'data' => $unsubscribeLink
                ];
            }

            return $token;
        }

        return [
            'success' => false,
            'message' => 'No url to unsubscription!'
        ];
    }

    public function getGridLink($emailData, $appTag, $process = 'create')
    {
        if(!empty($this->di->getConfig()->get('grid-link')[$appTag])) {
            $linkInfo = $this->di->getConfig()->get('grid-link')[$appTag]->toArray();
            if (isset($linkInfo['modification_required']) && isset($linkInfo['source']) && $linkInfo['modification_required']) {
                $sourceService = $this->di->getObjectManager()->get(\App\Connector\Contracts\Sales\OrderInterface::class, [], $linkInfo['source']);
                return $sourceService->createOrderGridLinkAsPerProcessAndAppTag($emailData, $linkInfo, $appTag, $process);
            }

            if (isset($linkInfo['basepath']) && isset($linkInfo['modification_required']) && !$linkInfo['modification_required']) {
                return $linkInfo['basepath'];
            }
        }

        return false;
    }
}
