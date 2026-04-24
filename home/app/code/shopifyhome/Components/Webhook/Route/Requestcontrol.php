<?php

namespace App\Shopifyhome\Components\Webhook\Route;

use App\Core\Components\Base;
use Exception;
use App\Shopifyhome\Components\Shop\Shop;
class Requestcontrol extends Base
{
    public function registerWebhooks($data){

        if(!isset($data['data']['source'])){
            $this->di->getLog()->logContent('Error : source not found', 'info','shopify'.DS.$this->di->getUser()->id.DS.'webhook_register.log');
            return true;
        }

        $source = $data['data']['source'];
        $target = $data['data']['target'] ?? $data['data']['source'];
        $temp = $this->di->getConfig()->webhook->$source->toArray();
        $webhookQueues = $this->di->getConfig()->webhookQueue->toArray();

        $tempCount = count($temp);

        if(isset($data['data']['cursor']) && ($data['data']['cursor'] < $tempCount)){
            if(!isset($data['data']['cursor'])){
                $this->di->getLog()->logContent('Error : cursor mismatch', 'info','shopify'.DS.$this->di->getUser()->id.DS.'webhook_register.log');
                return true;
            }

            $webhookInfo = $temp[$data['data']['cursor']];

            if(isset($webhookInfo['queue_name'])){
                $webhookQueueName = $webhookInfo['queue_name'];
            }else if(isset($webhookQueues[$webhookInfo['topic']])){
                $webhookQueueName = $webhookQueues[$webhookInfo['topic']]['queue_name'];
            }else{
                $webhookQueueName = 'unnamed_shopify_webhook';
            }

            if(isset($webhookInfo['queue_unique_id'])){
                $webhookUniqueQueueId = $webhookInfo['queue_unique_id'];
            }else if(isset($webhookQueues[$webhookInfo['topic']])){
                $webhookUniqueQueueId = $webhookQueues[$webhookInfo['topic']]['queue_unique_id'];
            }else{
                $webhookUniqueQueueId = 'sqs-unnamed_shopify_webhook';
            }

            $webHook = [
                'type' => 'sqs',
                'queue_config' => $data['data']['sqs'],
                'topic' => $webhookInfo['topic'],
                'queue_unique_id' => $webhookUniqueQueueId,
                'format' => 'json',
                'queue_name' => $webhookQueueName,
                'queue_data' => [
                    'type' => 'full_class',
                    'handle_added' => 1,
                    'class_name' => $webhookInfo['class_name'] ?? '\App\Connector\Models\SourceModel',
                    'method' => $webhookInfo['method'] ?? 'triggerWebhooks',
                    'user_id' => $data['data']['user_id'],
                    'code' => 'Shopifyhome',
                    'action' => $webhookInfo['action'],
                    'queue_name' => $webhookQueueName
                ]
            ];
            if(!empty($webhookInfo['marketplace'])){
                $webHook['queue_data']['marketplace'] = $webhookInfo['marketplace'];
            }

            if(!empty($webhookInfo['app_code'])){
                $webHook['queue_data']['app_code'] = $webhookInfo['app_code'];
            }

            $this->di->getLog()->logContent('webhook data ='. json_encode($webHook),'info','webhook_check.log');

            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shop_details = $user_details->getDataByUserID($data['data']['user_id'], 'shopify') ?? [];

            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init($target, 'true', $source)
                    ->call('/webhook/register', [], ['shop_id' => $data['data']['remote_shop_id'], 'data' => $webHook], 'POST');



            $this->di->getLog()->logContent(json_encode($remoteResponse), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'webhook_register.log');
            $data['data']['cursor'] = $data['data']['cursor'] + 1;

            if (isset($temp[$data['data']['cursor']])) {
                $this->pushToQueue($data);

                return true;
            }
            $this->di->getLog()->logContent('All Shopify webhook Registered', 'info','shopify'.DS.$this->di->getUser()->id.DS.'webhook_register.log');
            return true;
        }

        return true;
    }

    public function registerCustomWebhooks($configData){

        if(!isset($configData['data']['cursor'])){
            $this->di->getLog()->logContent('Error : Cursor Missing','info','register_webhook_request_control.log');
            return true;
        }

        try{
            $userIds = json_decode((string) $configData['data']['user_ids'], true);
            $userId = $userIds[$configData['data']['cursor']];

            $this->di->getLog()->logContent('----------------------------------------------------------','info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');
            $this->di->getLog()->logContent('-----USER ID : '.$userId.' ------','info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');

            $mongoCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $phpMongoCollection = $mongoCollection->setSource('user_details')->getPhpCollection();
            $shop = $phpMongoCollection->findOne(
                ['user_id' => $userId],
                [
                    "typeMap" => ['root' => 'array', 'document' => 'array'],
                    "projection" => ["shops.remote_shop_id" => 1, "shops.marketplace" => 1]
                ]
            );
            foreach ( $shop['shops'] as $value ) {
                if ( $value['marketplace'] === 'shopify' ) {
                    $shopId = $value['remote_shop_id'];
                }
            }
        }catch (Exception $exception){
            $this->di->getLog()->logContent('Error | Message : '.$exception->getMessage(),'info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');
            $this->di->getLog()->logContent('-------------------------------------------------------','info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');
            return true;
        }



        $temp = $this->di->getConfig()->webhook->temp->toArray();
        foreach($temp as $webhookInfo){
            $webHook = [
                'type' => 'sqs',
                'queue_config' => $configData['data']['sqs'],
                'topic' => $webhookInfo['topic'],
                'queue_unique_id' => $webhookInfo['queue_unique_id'].$userId,
                'format' => 'json',
                'queue_name' => $webhookInfo['queue_name'],
                'queue_data' => [
                    'type' => 'full_class',
                    'handle_added' => 1,
                    'class_name' => '\App\Connector\Models\SourceModel',
                    'method' => 'triggerWebhooks',
                    'user_id' => $userId,
                    'code' => 'Shopifyhome',
                    'action' => $webhookInfo['action'],
                    'queue_name' => $webhookInfo['queue_name']
                ]
            ];
            $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($target, 'true')
                ->call('/webhook/register',[],['shop_id'=> $shopId, 'data' => $webHook], 'POST');



            $msg = isset($remoteResponse['config_save_result']) ? $remoteResponse['config_save_result']['message'] : json_encode($remoteResponse);
            $errormsg = isset($remoteResponse['msg']['errors']) ? json_encode($remoteResponse['msg']['errors']) : 'NA';
            $this->di->getLog()->logContent('Remote Shop Id : '.$shopId.' | Topic : '.$webhookInfo['topic'].'  | Status : '. $msg.' | Errors : '.$errormsg,'info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');

            /*$this->di->getLog()->logContent('wehook registration status :'.json_encode($remoteResponse),'info','register_webhook_request_control.log');*/
        }

        $this->di->getLog()->logContent(' ','info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');

        $configData['data']['cursor'] = $configData['data']['cursor'] + 1;

        if (isset($userIds[$configData['data']['cursor']])) {
            $this->pushToQueue($configData);
            return true;
        }
        $this->di->getLog()->logContent('---------------- All Missing Webhook have been created --------------------------','info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');
        return true;
    }

    public function updateWebhooks($configData)
    {

        if(!isset($configData['data']['cursor'])){
            $this->di->getLog()->logContent('Error : Cursor Missing','info','register_webhook_request_control.log');
            return true;
        }

        try{
            $userIds = json_decode((string) $configData['data']['user_ids'], true);
            $userId = $userIds[$configData['data']['cursor']];

            $this->di->getLog()->logContent('----------------------------------------------------------','info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');
            $this->di->getLog()->logContent('-----USER ID : '.$userId.' ------','info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');

            $mongoCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $phpMongoCollection = $mongoCollection->setSource('user_details')->getPhpCollection();
            $shop = $phpMongoCollection->findOne(
                ['user_id' => $userId],
                [
                    "typeMap" => ['root' => 'array', 'document' => 'array'],
                    "projection" => ["shops.remote_shop_id" => 1, "shops.marketplace" => 1]
                ]
            );
            foreach ( $shop['shops'] as $value ) {
                if ( $value['marketplace'] === 'shopify' ) {
                    $shopId = $value['remote_shop_id'];
                }
            }
        }catch (Exception $exception){
            $this->di->getLog()->logContent('Error | Message : '.$exception->getMessage(),'info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');
            $this->di->getLog()->logContent('-------------------------------------------------------','info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');
            return true;
        }



        $temp = $this->di->getConfig()->webhook->temp->toArray();
        foreach($temp as $webhookInfo){
            $webHook = [
                'type' => 'sqs',
                'queue_config' => $configData['data']['sqs'],
                'topic' => $webhookInfo['topic'],
                'queue_unique_id' => $webhookInfo['queue_unique_id'].$userId,
                'format' => 'json',
                'queue_name' => $webhookInfo['queue_name'],
                'queue_data' => [
                    'type' => 'full_class',
                    'handle_added' => 1,
                    'class_name' => '\App\Connector\Models\SourceModel',
                    'method' => 'triggerWebhooks',
                    'user_id' => $userId,
                    'code' => 'Shopifyhome',
                    'action' => $webhookInfo['action'],
                    'queue_name' => $webhookInfo['queue_name']
                ]
            ];
            $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($target, 'true')
                ->call('/webhook/register',[],['shop_id'=> $shopId, 'data' => $webHook], 'POST');



            $msg = isset($remoteResponse['config_save_result']) ? $remoteResponse['config_save_result']['message'] : json_encode($remoteResponse);
            $errormsg = isset($remoteResponse['msg']['errors']) ? json_encode($remoteResponse['msg']['errors']) : 'NA';
            $this->di->getLog()->logContent('Remote Shop Id : '.$shopId.' | Topic : '.$webhookInfo['topic'].'  | Status : '. $msg.' | Errors : '.$errormsg,'info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');

            /*$this->di->getLog()->logContent('wehook registration status :'.json_encode($remoteResponse),'info','register_webhook_request_control.log');*/
        }

        $this->di->getLog()->logContent(' ','info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');

        $configData['data']['cursor'] = $configData['data']['cursor'] + 1;

        if (isset($userIds[$configData['data']['cursor']])) {
            $this->pushToQueue($configData);
            return true;
        }
        $this->di->getLog()->logContent('---------------- All Missing Webhook have been created --------------------------','info','shopify'.DS.'custom'.DS.'registerMissingWebhook.log');
        return true;
    }

    public function pushToQueue($data, $time = 0, $queueName = 'temp')
    {
        $data['run_after'] = $time;
        if($this->di->getConfig()->get('enable_rabbitmq_internal')){
            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
            return $helper->createQueue($data['queue_name'],$data);
        }

        return true;
    }
}