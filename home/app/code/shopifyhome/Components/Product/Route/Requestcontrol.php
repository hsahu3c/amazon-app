<?php

namespace App\Shopifyhome\Components\Product\Route;

use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Product\Helper;
use App\Shopifyhome\Models\SourceModel;
use App\Shopifyhome\Components\Product\Import;
use App\Core\Components\Base;
use App\Shopifyhome\Components\Product\Inventory\Data;
use Exception;
use Phalcon\Logger;
use App\Connector\Models\QueuedTasks;
class Requestcontrol extends Base
{

    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public const SHOPIFY_QUEUE_INV_IMPORT_MSG = 'SHOPIFY_INVENTORY_SYNC';

    public function fetchShopifyProduct($data){

        $this->di->getLog()->logContent(' Request Control Product | Now processing request for User ID : '.$this->di->getUser()->id.' | open time : '.date("Y-m-d H:i:s").' | SQS data : '.print_r($data['data'], true),'info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'sqs.log');

        $this->di->getLog()->logContent(' Request Control Product | Now processing request for User ID : '.$this->di->getUser()->id,'info','shopify'.DS.'Requestcontrol.log');
        if (!isset($data['data']['cursor'])) {
            $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                            ->init($target, 'true')
                            ->call('/product/count',[],['shop_id'=> $data['data']['remote_shop_id'], 'call_type' => 'QL'], 'GET');
            $this->di->getLog()->logContent(' Request Control Product remote | time : '.date("Y-m-d H:i:s"),'info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'sqs.log');
            $this->di->getLog()->logContent(' PROCESS 00001 | Request Control | fetchShopifyProduct | Response from Remote : '.print_r($remoteResponse, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'product_import.log');
            if ($remoteResponse['success'] && $remoteResponse['data'] == 0) {
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                if ($progress && $progress == 100) {
                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], 'No Product Found on your Shopify Store', 'critical');
                }

                return true;
            }
            if ($remoteResponse['success']) {
                //$this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Data')->deleteProducts();
                $this->di->getLog()->logContent('json_encode = '.json_encode($data),'info','Requestcontrol.log');
                /*if($this->di->getUser()->id == '157'){*/
                $individualWeight = (98 / ceil($remoteResponse['data'] / 250));
                /*} else {
                      $individualWeight = (100 / ceil($remoteResponse['data'] / 250));
                  }*/
                $data['data']['individual_weight'] = round($individualWeight,4,PHP_ROUND_HALF_UP);
                $data['data']['cursor'] = 0;
                $data['data']['total'] = $remoteResponse['data'];
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 0, Requestcontrol::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : '.$remoteResponse['data'].  ' product(s) found. Please wait while products are being imported.');
                /*if($this->di->getUser()->id == '157'){*/
                $resetData = $this->di->getObjectManager()->get(Helper::class)->resetImportFlag();
                $this->di->getLog()->logContent('PROCESS 00012 | Request Control | fetchShopifyProduct | reset import flag | '.json_encode($resetData),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'product_import.log');
                $this->di->getLog()->logContent('PROCESS 00012 | Request Control | fetchShopifyProduct | sqs data | '.json_encode($data),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'product_import.log');
                /*}*/
                //                todo unnecessary
                $this->di->getLog()->logContent('PROCESS 00002 | Request Control | fetchShopifyProduct | set cursor and start import | new SQS data : '.print_r($data, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'product_import.log');
                $this->di->getLog()->logContent(' Request Control Product count | else close time : '.date("Y-m-d H:i:s"),'info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'sqs.log');
                $this->di->getLog()->logContent('','info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'sqs.log');
                $this->pushToQueue($data);
                return true;
            }
            else {
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                if ($progress && $progress == 100) {
                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], 'Failed to fetch Product(s) from Shopify. Please try again later.', 'critical');
                }

                return true;
            }
        }

        if (isset($data['data']['delete_nexisting'])) {
            /*if($this->di->getUser()->id != '157'){
                  $this->di->getObjectManager()->get('\App\Shopifyhome\Models\SourceModel')->initiateInvImport([], $data['data']['user_id']);
              }*/
            $this->di->getObjectManager()->get(Helper::class)->deleteNotExistingProduct();
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 5, 'Finalizing .. ');
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['user_id'], $data['data']['total']. ' Shopify product(s) has been successfully imported.', 'success');
            $this->di->getObjectManager()->get(SourceModel::class)->initiateInvImport([], $data['data']['user_id']);
            $this->di->getLog()->logContent('PROCESS 00002 | Request Control | fetchShopifyProduct | Product Import completed | Inventory Location sync ON','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'product_import.log');
            return true;
        }
        else {
        $filter['shop_id'] = $data['data']['remote_shop_id'];  
        if(isset($data['data']['next']))$filter['next'] = $data['data']['next'];

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
        ->init($target, 'true')
        ->call('/product',[],$filter, 'GET');

            $this->di->getLog()->logContent(' Request Control Product remote | time : '.date("Y-m-d H:i:s"),'info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'sqs.log');

        /*$this->di->getLog()->logContent(' PROCESS 00003 | Request Control | fetchShopifyProduct | Importing Products | Response from Remote : '.print_r($remoteResponse, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'product_import.log');*/

        $handleProductData = $this->di->getObjectManager()->get(Import::class);

        if($remoteResponse['success'] && isset($remoteResponse['data'])) {
            $response = $handleProductData->getShopifyProducts($remoteResponse);
        } elseif (count($remoteResponse) > 9) {
            $response = $handleProductData->getShopifyProducts(['data' => $remoteResponse]);
        } else {
            $response['success'] = false;
        }
        if ($response['success']) {
            $data['data']['cursor'] = $data['data']['cursor'] +1 ;
            $msg = Requestcontrol::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : '.($data['data']['cursor'] * 250).' of '.$data['data']['total'].' Shopify Product(s) has been successfully imported.';
            $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], $data['data']['individual_weight'], $msg);
            if(($data['data']['cursor'] * 250) >= $data['data']['total']){
                $data['data']['delete_nexisting'] = 1;
                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 0, Requestcontrol::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' :  Finalizing .. ');
                $this->pushToQueue($data);
                $this->di->getLog()->logContent(' Request Control Product completed | else close time : '.date("Y-m-d H:i:s"),'info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'sqs.log');
                $this->di->getLog()->logContent('','info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'sqs.log');

                return true;
            }

            if(isset($remoteResponse['cursors']) && isset($remoteResponse['cursors']['next'])){
                $data['data']['next'] = $remoteResponse['cursors']['next'];
            }

            $this->pushToQueue($data, 5);
            $this->di->getLog()->logContent(' Request Control Product ongoing | else close time : '.date("Y-m-d H:i:s"),'info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'sqs.log');
            $this->di->getLog()->logContent('','info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'sqs.log');
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
        else $error = true;

            if($error){

                $this->di->getLog()->logContent('product data = '.json_encode($remoteResponse),'info','shopify'.DS.$data['data']['user_id'].DS.'product'.DS.'import_error.log');

                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                if ($progress && $progress == 100) {
                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], 'Failed to fetch Product(s) from Shopify. Please try again later.', 'critical');
                }

                return true;
            }
        }

        return true;
    }

    public function fetchProductInventoryLocationWise($data, $userId = null){
        $userId = $data['user_id'];
        $this->di->getLog()->logContent('PROCESS 00000 | Request Control | Class - App\Shopifyhome\Models | Function Name - SourceModel | SQS data : '.print_r($data, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'inventory'.DS.date("Y-m-d").DS.'inventory.log');

            $initLocationRequest = $this->di->getObjectManager()->get(Data::class);
            $chunkResponse = $initLocationRequest->fetchLocationsInChunk($data, $userId);
            $error = false;

        $this->di->getLog()->logContent('PROCESS 00001 | Request Control | Class - App\Shopifyhome\Components\Product\Route | Function Name - Requestcontrol | chunk wise inventory id(s) = '.json_encode($chunkResponse),'info','shopify'.DS.$this->di->getUser()->id.DS.'inventory'.DS.date("Y-m-d").DS.'inventory.log');
        if ($chunkResponse['success'] && ($chunkResponse['locations'] == 'partial_error')) {
            $this->di->getLog()->logContent('PROCESS XX0001 | Request Control | Class - App\Shopifyhome\Components\Product\Route\Requestcontrol | Function Name - fetchProductInventoryLocationWise | chunk wise inventory id(s) = '.json_encode($chunkResponse).'| invalid cursor | SQS data = '.json_encode($data),'info','shopify'.DS.$this->di->getUser()->id.DS.'inventory'.DS.'inventory_error.log');
            $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
            if ($progress && $progress == 100) {
                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], 'Invalid Inventory Data. Kindly reimport products or Kindly contact our support ', 'critical');
            }
            
            return true;
        }


            if ($chunkResponse['success']) {
                $initLocationRequest = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Inventory\Import::class);
                $response = $initLocationRequest->getProductInventory($chunkResponse['locations'], $data['data']['remote_shop_id']);
                $this->di->getLog()->logContent('PROCESS 00010 | Request Control | Class - App\Shopifyhome\Components\Product\Route | Function Name - Requestcontrol | Response from Shopify = '.print_r($response, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'inventory'.DS.date("Y-m-d").DS.'inventory.log');
                if($response['success']) {
                    if($data['data']['cursor'] == 0){
                        // setup individual weight and push to queue
                        $this->di->getLog()->logContent('json_encode = '.json_encode($chunkResponse),'info','Requestcontrol.log');
                        $individualWeight = (100 / $chunkResponse['total_chunk']);
                        $data['data']['individual_weight'] = round($individualWeight,4,PHP_ROUND_HALF_UP);
                        $data['data']['cursor'] = $data['data']['cursor'] + 1;
                        $data['data']['total'] = $chunkResponse['total'];
                        $data['data']['total_chunk'] = $chunkResponse['total_chunk'];
                        $this->di->getLog()->logContent('individual wt ='. $individualWeight,'info','Requestcontrol.log');

                    } else {
                        $data['data']['cursor'] = $data['data']['cursor'] + 1;
                        $this->di->getLog()->logContent('json_encode = '.json_encode($chunkResponse),'info','Requestcontrol.log');
                    }

                    $msg = Requestcontrol::SHOPIFY_QUEUE_INV_IMPORT_MSG.' : '.$data['data']['cursor'] * 40 . " of ".$data['data']['total']. " Shopify locations has been successfully synced";
                    $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], $data['data']['individual_weight'], $msg);

                    $this->di->getLog()->logContent('progress ='.$progress,'info','Requestcontrol.log');
                    if($data['data']['cursor'] == $data['data']['total_chunk'] ){
                        $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['user_id'], 'All inventory locations has been succesfully updated on App', 'success');
                        try{
                            $this->di->getObjectManager()->get(Helper::class)->ifEligibleSendMail($data['user_id']);
                        }catch (Exception $e){
                            $this->di->getLog()->logContent('PROCESS 00000 | App\Shopifyhome\Components\Product\Bulk\Route\RequestControl |Error while sending mail : ' . $e->getMessage(), Logger::CRITICAL, 'shopify'.DS.'mail.log');
                        }

                        return true;
                    }

                    $this->pushToQueue($data);

                    // initiate sync with facebook
                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $collection = $mongo->getCollectionForTable('user_details');
                    $user_details = $collection->findOne(['user_id' =>(string)$data['data']['user_id'],'product_imported'=>['$exists'=>true]]);

                    if ( empty($user_details) ){
                        $collection->updateOne(['user_id' => (string)$data['data']['user_id']], ['$set' => ['product_imported' =>1]]);
                        $fbData=['marketplace' => "facebook"];
                        $this->di->getObjectManager()->get("\App\Facebookhome\Models\SourceModel")->initiateSync($fbData);
                    }

                    return true;
                }
                $error = true;
            }
            else $error = true;
            
            if($error) {
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                if ($progress && $progress == 100) {
                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], 'Failed to fetch inventory Id. '.$response['message'], 'critical');
                }

                return true;
            }
    }

    public function uploadProduct($data)
    {
        $request=$this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Data::class);
        $request->initProductUploadQueue($data,$failed_product=false);
        return true;
    }

    public function pushToQueue($data, $time = 0, $queueName = 'temp')
    {
        /* $handlerData = [
            'type' => 'full_class',
            'class_name' => '\App\Ebayimporter\Components\Route\Requestcontrol',
            'queue_name' => $queueName,
            'own_weight' => 100,
            'user_id' => $data['user_id'],
            'data' => $data['data'],
            'run_after' => $time,
            'method' => $method
        ]; */
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