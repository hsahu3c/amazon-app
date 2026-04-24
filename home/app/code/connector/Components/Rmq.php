<?php

namespace App\Connector\Components;

class Rmq extends \App\Core\Components\Base {
	
	public function createWebhooks($shop, $userId) {
        $handlerData = [
            'type' => 'full_class',
            'class_name' => '\App\Connector\Components\Webhook',
            'method' => 'consumerCreateWebhook',
            'queue_name' => 'general',
            'own_weight' => 100,
            'data' => [
                'user_id' => $userId,
                'shop' => $shop
            ]
        ];
        if($this->di->getConfig()->enable_rabbitmq_internal){
            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
            return $helper->createQueue($handlerData['queue_name'],$handlerData);
        }

        $request = $this->di->get('\App\Core\Components\Helper')
                ->curlRequest($this->di->getConfig()->rabbitmq_url . '/rmq/queue/create', $handlerData, false);
        json_decode($request['message'], true);
        return $request['feed_id'];
    }
}