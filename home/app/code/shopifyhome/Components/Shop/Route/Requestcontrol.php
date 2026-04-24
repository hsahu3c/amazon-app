<?php

namespace App\Shopifyhome\Components\Shop\Route;

use App\Core\Components\Base;
class Requestcontrol extends Base
{
    public function UnregisterProductCreateWebhooks($data)
    {
        if(isset($data['data']['cursor'])){
            $this->di->getLog()->logContent("SQS DATA ".print_r($data,true),'info','shopify'.DS.'UNREGISTER_PRODUCT_CREATE_WEBHOOK_SQS.log');
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->setSource("user_details")->getCollection();
            $totalusers=$collection->count();

            $cursor=$data['data']['cursor'];
            $chunksize=$data['data']['chunkSize'];

            $skip=$cursor*$chunksize;
            $total=ceil($totalusers/$chunksize);
            $userarray=[];

            if ($cursor == 0) {
                $result = $collection->find([], ['limit' => $chunksize])->toArray();
            } else {
                $result = $collection->find([], ['limit' => $chunksize, 'skip' => $skip])->toArray();
            }

            foreach ($result as $user) {
                $userdata = [];
                array_push($userdata, $user['user_id']);
                array_push($userdata, $user['shops'][0]['remote_shop_id']);
                array_push($userarray, $userdata);
            }

            for ($i = 0; $i < count($userarray); $i++) {

                $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init('shopify', 'true')
                    ->call('/webhook', [], ['shop_id' => $userarray[$i][1]], 'GET');


                if ($remoteResponse['success'] && isset($remoteResponse['data'])) {

                    $webhooks = $remoteResponse['data'];
                    foreach ($webhooks as $value) {
                        if ($value['topic'] == $data['data']['topic']) {
                            $webhookid = $value['id'];
                            $topic = $value['topic'];
                            $responseWbhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                                ->init('shopify', 'true')
                                ->call("/webhook/unregister", [], ['shop_id' => $userarray[$i][1], 'id' => $webhookid, 'topic' => $topic], 'DELETE');

                            if($responseWbhook['success']){
                                $this->di->getLog()->logContent("Webhook Deleted successfully for userid ".$userarray[$i][0],'info','shopify'.DS.'UNREGISTER_PRODUCT_CREATE_WEBHOOK.log');
                                $this->di->getLog()->logContent("products Create Webhook Deleted Response From Remote".print_r($responseWbhook,true),'info','shopify'.DS.'UNREGISTER_PRODUCT_CREATE_WEBHOOK.log');
                            }

                        }
                    }
                }
            }

            if($data['data']['cursor']>$total){
                return true;
            }

            $data['data']['cursor'] = $data['data']['cursor'] + 1;
            $this->pushToQueue($data);
            return true;
        }

        return true;
    }

    public function pushToQueue($data, $time = 0)
    {
        $data['run_after'] = $time;
        if($this->di->getConfig()->get('enable_rabbitmq_internal')){
            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
            return $helper->createQueue($data['queue_name'],$data);
        }

        return true;
    }

}