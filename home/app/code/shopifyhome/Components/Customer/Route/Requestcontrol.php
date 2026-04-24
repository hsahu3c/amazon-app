<?php

namespace App\Shopifyhome\Components\Customer\Route;

use App\Shopifyhome\Components\Customer\Helper;
use App\Shopifyhome\Components\Customer\Import;
use App\Core\Components\Base;
use App\Connector\Models\QueuedTasks;
class Requestcontrol extends Base
{

    public const SHOPIFY_QUEUE_CUSTOMER_IMPORT_MSG = 'SHOPIFY_CUSTOMER_IMPORT';

    public function fetchShopifyCustomer($data){
        if (!isset($data['data']['cursor'])) {
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
        ->init('shopify', 'true')
        ->call('/customer/count',[],['shop_id'=> $data['data']['remote_shop_id']], 'GET');
            $remoteResponse['data'] = $remoteResponse['data']['count'];
            if ($remoteResponse['success'] && $remoteResponse['data'] == 0) {
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                if ($progress && $progress == 100) {
                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], 'No Customer Found on your Shopify Store', 'critical');
                }

                return true;
            }
            if ($remoteResponse['success']) {
                $individualWeight = (98 / ceil($remoteResponse['data'] / 250));
                $data['data']['individual_weight'] = round($individualWeight,4,PHP_ROUND_HALF_UP);
                $data['data']['cursor'] = 0;
                $data['data']['total'] = $remoteResponse['data'];
                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 0, Requestcontrol::SHOPIFY_QUEUE_CUSTOMER_IMPORT_MSG.' : '.$remoteResponse['data'].  ' customer(s) found. Please wait while customers are being imported.');
                //resetData
                $this->di->getObjectManager()->get(Helper::class)->resetImportFlag();
                $this->pushToQueue($data);
                return true;
            }
            else {
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                if ($progress && $progress == 100) {
                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], 'Failed to fetch Customer(s) from Shopify. Please try again later.', 'critical');
                }

                return true;
            }
        }
        if (isset($data['data']['delete_nexisting'])) {
            $this->di->getObjectManager()->get(Helper::class)->deleteNotExistingCustomer();
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 5, 'Finalizing .. ');
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['user_id'], $data['data']['total']. ' Shopify customer(s) has been successfully imported.', 'success');
            return true;
        }
        else {
            $filter['shop_id'] = $data['data']['remote_shop_id'];
            $userId = $data['data']['user_id'];

            if(isset($data['data']['next']))$filter['next'] = $data['data']['next'];

            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")->init('shopify', 'true')->call('/customer',[],$filter, 'GET');

            $handleCustomerData = $this->di->getObjectManager()->get(Import::class);
            if($remoteResponse['success'] && isset($remoteResponse['data'])) {
                $response = $handleCustomerData->getShopifyCustomers($remoteResponse, $userId);
            } elseif (count($remoteResponse) > 9) {
                $response = $handleCustomerData->getShopifyCustomers(['data' => $remoteResponse], $userId);
            } else {
                $response['success'] = false;
            }
            if ($response['success']) {
                $data['data']['cursor'] = $data['data']['cursor'] +1 ;
                $msg = Requestcontrol::SHOPIFY_QUEUE_CUSTOMER_IMPORT_MSG.' : '.($data['data']['cursor'] * 250).' of '.$data['data']['total'].' Shopify Customer(s) has been successfully imported.';
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], $data['data']['individual_weight'], $msg);
                if(($data['data']['cursor'] * 250) >= $data['data']['total']){
                    $data['data']['delete_nexisting'] = 1;
                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 0, Requestcontrol::SHOPIFY_QUEUE_CUSTOMER_IMPORT_MSG.' :  Finalizing .. ');
                    $this->pushToQueue($data);

                    return true;
                }

                if(isset($remoteResponse['cursors']) && isset($remoteResponse['cursors']['next'])){
                    $data['data']['next'] = $remoteResponse['cursors']['next'];
                }

                $this->pushToQueue($data, 5);
                return true;
            }

            if (isset($response['msg']) || isset($response['message'])) {
                $message = $response['msg'] ?? $response['message'];
                $realMsg = '';
                if(is_string($message)) $realMsg = $message;
                elseif(is_array($message)) {
                    if(isset($message['errors'])) $realMsg = $message['errors'];
                }

                if(empty($realMsg)) $error = true;
                else {
                    if(strpos((string) $realMsg, "Reduce request rates") || strpos((string) $realMsg, "Try after sometime")){
                        $this->pushToQueue($data, time() + 8);
                        return true;
                    }

                    $error = false;
                }
            }
            else{
                $error = true;
            }

            if($error){
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                if ($progress && $progress == 100) {
                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], 'Failed to fetch Customer(s) from Shopify. Please try again later.', 'critical');
                }

                return true;
            }
        }

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

    public function updateQueueMessage($userId, $stringToMatch, $msg): void{        
        $queuedTask = new QueuedTasks;
        $queueModel = $queuedTask::findFirst(
            [
                'conditions' => 'user_id ='.$userId.' AND message LIKE "%'.$stringToMatch.'%"'
            ]
        );
        if($queueModel){
            $queueModel->message = $msg;
            $queueModel->save();
        }
    }
}