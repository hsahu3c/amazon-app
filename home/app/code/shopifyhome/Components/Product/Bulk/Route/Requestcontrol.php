<?php

namespace App\Shopifyhome\Components\Product\Bulk\Route;

use App\Shopifyhome\Components\Product\Bulk\Bulkimport;
use App\Shopifyhome\Components\Product\Helper;
use App\Core\Components\Base;
use App\Shopifyhome\Components\Utility;
use Exception;
use Phalcon\Logger;
use App\Connector\Models\QueuedTasks;
class Requestcontrol extends Base
{

    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public const SHOPIFY_QUEUE_INV_IMPORT_MSG = 'SHOPIFY_INVENTORY_SYNC';

    public function handleBulkImport($sqsData)
    {


        $this->di->getLog()->logContent(' Request Control Product BULK | Now processing request for User ID : '.$this->di->getUser()->id.' | open time : '.date("Y-m-d H:i:s").' | SQS data : '.print_r($sqsData['data'], true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'sqs_bulk.log');

        $system = $this->di->getObjectManager()->get(Utility::class);

        $this->di->getLog()->logContent(' Request Control Product | Now processing request for User ID : '.$this->di->getUser()->id,'info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'Requestcontrol.log');
        if (!isset($sqsData['data']['cursor'])) {
            $operation = $sqsData['data']['operation'];
            switch($operation){
                case 'make_request' :
                    $remoteResponse = $this->di->getObjectManager()->get(Bulkimport::class)->createRequest($sqsData['data']['remote_shop_id'], $sqsData);
                    if($remoteResponse['success']) $this->pushToQueue($remoteResponse['sqs_data'], (time() + 8));
                    else $this->completeProgressBar($sqsData);

                    break;

                case 'get_status' :
                    $id = $sqsData['data']['id'];
                    $remoteResponse = $this->di->getObjectManager()->get(Bulkimport::class)->requestStatus($sqsData['data']['remote_shop_id'], $id, $sqsData);
                    if($remoteResponse['success'] && isset($remoteResponse['sqs_data']['data']['retry'])){
                        $runAfterTime = ((25 * $remoteResponse['sqs_data']['data']['retry']) < 100) ? (25 * $remoteResponse['sqs_data']['data']['retry']) : 90;

                        $this->di->getLog()->logContent('PROCESS 00210 | Request Control | Class - App\Shopifyhome\Components\Product\Bulk\Route\Requestcontrol | Function Name - handleBulkImport | Run After (secs) = '.$runAfterTime,'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');

                        $this->pushToQueue($remoteResponse['sqs_data'], (time() + $runAfterTime));
                    }   elseif($remoteResponse['success']) $this->pushToQueue($remoteResponse['sqs_data']);
                        else $this->completeProgressBar($remoteResponse['sqs_data']);

                    break;

                case 'build_progress' :
                    $remoteResponse = $this->di->getObjectManager()->get(Bulkimport::class)->buildProgressBar($sqsData['data']['remote_shop_id'], $sqsData['data']['file_path'], $sqsData);
                    if($remoteResponse['success']){
                        /*if($this->di->getUser()->id == '157'){*/
                            $resetData = $this->di->getObjectManager()->get(Helper::class)->resetImportFlag();
                            $this->di->getLog()->logContent('PROCESS 00012 | Request Control | handleBulkImport | reset import flag | '.json_encode($resetData),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');
                        /*}*/
                        $this->pushToQueue($remoteResponse['sqs_data']);
                    }
                    elseif ($remoteResponse['notice']) $this->completeProgressBar($remoteResponse['sqs_data'], $remoteResponse['notice']);
                    else $this->completeProgressBar($remoteResponse['sqs_data']);

                    break;

                default :
                    $this->di->getLog()->logContent('Request Control | Class - App\Shopifyhome\Components\Product\Bulk\Route | Function Name - handleBulkImport | Error Occured, no operation found | SQS Data = \n'.print_r($sqsData, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');

                    $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($sqsData['data']['feed_id'], 100);
                    if ($progress && $progress == 100) {
                        $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($sqsData['data']['user_id'], 'No response found. Kindly contact support for more info.', 'critical');
                    }

                    break;
            }
            $this->di->getLog()->logContent(' Request Control Product  BULK | if close time : '.date("Y-m-d H:i:s"),'info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'sqs.log');
            $this->di->getLog()->logContent('','info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'sqs.log');
            return true;
        }

        if (isset($sqsData['data']['delete_nexisting'])) {
            $this->di->getObjectManager()->get(Helper::class)->deleteNotExistingProduct('', 'bulk_');
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($sqsData['data']['feed_id'], 5, 'Finalizing .. ');
            return true;
        }
        else {

            if($this->di->getUser()->id == '202'){
                return true;
            }

            /*$this->di->getLog()->logContent('PROCESS 004 | Request Control | Class - App\Shopifyhome\Components\Product\Bulk\Route | Function Name - handleBulkImport | All Good .. yeah ','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.'bulk_product_import.log');*/

            $openTime = microtime(true);
            $this->di->getLog()->logContent(' Request Control Product BULK | Before pushing product data to container | Cursor :'. $sqsData['data']['cursor'],'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'system.log');
            /*$system->init('shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'system.log', ' Before Importing product (Bulk) ')->RAMConsumption();*/

            $remoteResponse = $this->di->getObjectManager()->get(Bulkimport::class)->importProduct($sqsData);

            if($remoteResponse['requeue'] && !isset($sqsData['data']['test'])) {
                $this->pushToQueue($remoteResponse['sqs_data']);
            }
            elseif (isset($remoteResponse['is_complete']) && $remoteResponse['is_complete']){
                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($sqsData['data']['feed_id'],0, 'SHOPIFY_PRODUCT_IMPORT : Finalizing..' );
                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($sqsData['data']['user_id'], $remoteResponse['message'], 'success');
                try{
                    $this->di->getObjectManager()->get(Helper::class)->ifEligibleSendMail($sqsData['data']['user_id']);
                }catch (Exception $e){
                    $this->di->getLog()->logContent('PROCESS 00000 | App\Shopifyhome\Components\Product\Bulk\Route\RequestControl | Error while sending mail : ' . $e->getMessage(), Logger::CRITICAL, 'mail.log');
                }

                $sqsData['data']['delete_nexisting'] = 1;
                $this->pushToQueue($sqsData);
            }
            else {
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($sqsData['data']['feed_id'], 100);
                if ($progress && $progress == 100) {
                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($sqsData['data']['user_id'], 'Your request has been declined. Kindly contact support.', 'critical');
                }
            }
        }

        $system->init('shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'system.log', ' After importing Product (Bulk) ')->RAMConsumption();
        //$system->init('shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'system.log')->CPUConsumption(); // caution : use only if critically required as it causes delay of 1 sec
        $endTime = microtime(true);
        $elapsedtIME = $endTime - $openTime;
        $generalizedTime = $this->di->getObjectManager()->get(Utility::class)->secondsToTime($elapsedtIME);
        $this->di->getLog()->logContent('PROCESS 00000 | RequestControl | handleBulkImport | Total Time Taken to import Product (BULK) = '.$generalizedTime.' | BATCH PRODUCT COUNT :'.$sqsData['data']['limit'],'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'system.log');
        $this->di->getLog()->logContent('','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'system.log');

        $this->di->getLog()->logContent(' Request Control Product BULK | else close time : '.date("Y-m-d H:i:s"),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'sqs_bulk.log');
        $this->di->getLog()->logContent('','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'sqs_bulk.log');

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

    public function completeProgressBar($sqsData, $msg = "Error fetching data from Shopify. Kindly contact support for help."): void{
        $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($sqsData['data']['feed_id'], 100);
        if ($progress && $progress == 100) {
            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($sqsData['data']['user_id'], $msg, 'critical');
        }
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