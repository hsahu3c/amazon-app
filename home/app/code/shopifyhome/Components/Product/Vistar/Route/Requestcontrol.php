<?php

namespace App\Shopifyhome\Components\Product\Vistar\Route;

use App\Core\Components\Base;
use App\Shopifyhome\Components\Product\Vistar\Data;
use App\Shopifyhome\Components\Product\Vistar\Import;
use App\Shopifyhome\Models\SourceModel;
use App\Shopifyhome\Components\Product\Helper;
use Exception;
use Phalcon\Logger;
use App\Shopifyhome\Components\Product\Vistar\SyncCatalog;
#[\AllowDynamicProperties]
class Requestcontrol extends Base
{
    private $_vistarData;

    private $_vistarImport;

    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public function init(): void
    {
        $this->_vistarData = $this->di->getObjectManager()->get("\App\Shopifyhome\Components\Product\Vistar\Data");
        $this->_vistarImport = $this->di->getObjectManager()->get("\App\Shopifyhome\Components\Product\Vistar\Import");
        $this->_shopifySourceModel = $this->di->getObjectManager()->get("\App\Shopifyhome\Models\SourceModel");
        $this->_queuedTask = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
        $this->_notification = $this->di->getObjectManager()->get('\App\Connector\Models\Notifications');
        $this->_connectorHelper = $this->di->getObjectManager()->get('\App\Connector\Components\Helper'); //creating problem
    }

    public function handleImport($sqsData)
    {
        $this->init();

        //Stop Process if queuedTask doesn't exists
        $queuedTaskStatus = $this->_queuedTask->checkQueuedTaskExists($sqsData['data']['feed_id']);
        if (!$queuedTaskStatus && !isset($sqsData['data']['file_path']) && isset($sqsData['data']['id']))
        {
            $this->_shopifySourceModel->cancelBulkRequestOnMarketplace($sqsData);
            return true;
        }

        $operation = $sqsData['data']['operation'];

        switch($operation)
        {

            case 'make_request' :

                // used at the time of importing , request is sent to this fucntion from connector
                $this->di->getLog()->logContent(date('d-m-Y H:i:s').'   =====> make_request ======>   '.json_encode($sqsData),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');

                //echo "start of make request";

                $response = $this->_vistarImport->init()->createRequest($sqsData['data']['remote_shop_id'], $sqsData);

                if($response['success'])
                {
                    $this->_vistarData->pushToQueue($response['sqs_data'], (time() + 8));
                }
                else
                {
                    $this->_vistarData->completeProgressBar($response['sqs_data']);
                }

                break;

            case 'get_status' :

                $this->di->getLog()->logContent(date('d-m-Y H:i:s').'   =====> get_status ======>   '.json_encode($sqsData),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');
                //echo "start of get_status";

                $remoteResponse = $this->_vistarImport->init()->requestStatus($sqsData['data']['remote_shop_id'], $sqsData);
                if($remoteResponse['success'] && isset($remoteResponse['sqs_data']['data']['retry']))
                {
                    $runAfterTime = ((25 * $remoteResponse['sqs_data']['data']['retry']) < 100) ? (25 * $remoteResponse['sqs_data']['data']['retry']) : 90;

                    $this->_vistarData->pushToQueue($remoteResponse['sqs_data'], (time() + $runAfterTime));
                }
                elseif($remoteResponse['success'])
                {
                    $this->_vistarData->pushToQueue($remoteResponse['sqs_data']);
                }
                else
                {
                    $this->_vistarData->completeProgressBar($remoteResponse['sqs_data']);

                }

                break;

            case 'build_progress' :

                $this->di->getLog()->logContent(date('d-m-Y H:i:s').'   =====> build_progress ======>   '.json_encode($sqsData),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');

                //echo "start of build_progress";

                $remoteResponse = $this->_vistarImport->init()->buildProgressBar($sqsData);

                if(isset($remoteResponse['success']) && $remoteResponse['success'])
                {
                    $this->_vistarData->pushToQueue($remoteResponse['sqs_data']);
                }
                elseif (isset($remoteResponse['notice']) && $remoteResponse['notice'])
                {
                    $this->di->getLog()->logContent('Build Progress notice else: '.json_encode($remoteResponse),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'SQS.log');

                    $this->_vistarData->completeProgressBar($remoteResponse['sqs_data'], $remoteResponse['notice']);
                }
                else
                {
                    $this->di->getLog()->logContent('Build Progress last else: '.json_encode($remoteResponse),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'SQS.log');

                    $this->_vistarData->completeProgressBar($remoteResponse['sqs_data']);
                }

                break;

            case 'import_product' :

                $this->di->getLog()->logContent(date('d-m-Y H:i:s').'   =====> import_product ======>   '.json_encode($sqsData),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');

                //echo "start of import_product";

                if($sqsData['data']['total_variant'] == 0)
                {
                    $this->di->getLog()->logContent('Import Product : '.print_r($sqsData, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'SQS.log');
                }

                $remoteResponse = $this->_vistarImport->init()->importControl($sqsData);

                $this->di->getLog()->logContent(' PROCESS 003000 | Route | RequestCOntrol | Response : '.print_r($remoteResponse, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');

                if($remoteResponse['requeue'] && !isset($sqsData['data']['test']))
                {
                    $this->di->getLog()->logContent('PROCESS 004001 | Requestcontrol | handleImport | '.$remoteResponse['sqs_data']['data']['total_variant'].' variant(s) has been successfullly imported. | Stats : '.json_encode($remoteResponse['data']),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');

                    $this->_vistarData->pushToQueue($remoteResponse['sqs_data']);

                }
                elseif (isset($remoteResponse['is_complete']))
                {
                    $this->di->getLog()->logContent('PROCESS 004002 | Requestcontrol | handleImport | '.$remoteResponse['sqs_data']['data']['total'].' variant(s) has been successfullly imported. Stats : '.json_encode($remoteResponse['data']),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');

                    $this->_queuedTask->updateFeedProgress($sqsData['data']['feed_id'], 0, 'SHOPIFY_PRODUCT_IMPORT : Finalizing..');
                    try
                    {
                        $this->di->getObjectManager()->get(Helper::class)->ifEligibleSendMail($sqsData['data']['user_id']);
                    }
                    catch (Exception $e)
                    {
                        $this->di->getLog()->logContent('PROCESS 00000 | App\Shopifyhome\Components\Product\Bulk\Route\RequestControl | Error while sending mail : ' . $e->getMessage(), Logger::CRITICAL, 'mail.log');
                    }

                    $this->_vistarData->pushToQueue($remoteResponse['sqs_data']);
                }
                else
                {
                    $progress = $this->_queuedTask->updateFeedProgress($sqsData['data']['feed_id'], 100);

                    if ($progress && $progress == 100)
                    {
                        $notificationData = [
                            'marketplace' => $sqsData['data']['shop']['marketplace'],
                            'user_id' => $sqsData['data']['user_id'],
                            'message' => 'Your request has been declined. Kindly contact support.',
                            'severity' => 'critical'
                        ];
                        $this->_notification->addNotification($sqsData['data']['shop']['_id'], $notificationData);
                    }
                }

                break;

            case 'delete_nexisting' :

                $this->di->getLog()->logContent(date('d-m-Y H:i:s').'   =====> delete_nexisting ======>   '.json_encode($sqsData),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');

                //echo "start of delete_nexisting";

                $this->di->getLog()->logContent('delete_nexisting Process : '.print_r($sqsData, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'SQS.log');

                $this->di->getObjectManager()->get(Helper::class)->deleteNotExistingProduct('', 'vistar_');

                $this->_connectorHelper->updateFeedProgress($sqsData['data']['feed_id'], 100, 'Finalizing .. ');

                $this->_connectorHelper->addNotification($sqsData['data']['user_id'], $sqsData['data']['total'].' product variant(s) has been successfully imported.', 'success');

                $this->di->getLog()->logContent('PROCESS 0005000 | Requestcontrol | Import | Non Existing Product Deleted ','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');

                // initiate sync with facebook
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

                $collection = $mongo->getCollectionForTable('user_details');

                $user_details = $collection->findOne(['user_id' =>(string)$sqsData['data']['user_id'],'product_imported'=>['$exists'=>true]]);

                if ( empty($user_details) )
                {
                    $collection->updateOne(['user_id' => (string)$sqsData['data']['user_id']], ['$set' => ['product_imported' =>1]]);

                }

                break;

            default :

                $this->di->getLog()->logContent(date('d-m-Y H:i:s').'   =====> default ======>   '.json_encode($sqsData),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');

                $this->di->getLog()->logContent('Request Control | Class - App\Shopifyhome\Components\Product\Bulk\Route | Function Name - handleBulkImport | Error Occured, no operation found | SQS Data = \n' . print_r($sqsData, true), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'vistar_product_import.log');

                $progress = $this->_queuedTask->updateFeedProgress($sqsData['data']['feed_id'], 100);

                if ($progress && $progress == 100)
                {
                    $notificationData = [
                        'marketplace' => $sqsData['data']['shop']['marketplace'],
                        'user_id' => $sqsData['data']['user_id'],
                        'message' => 'No response found. Kindly contact support for more info.',
                        'severity' => 'critical'
                    ];

                    $this->_notification->addNotification($sqsData['data']['shop']['_id'], $notificationData);
                }

                break;
        }

        return true;
    }

    public function handleSyncCatalog($sqsData)
    {
        $logFile = 'shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_sync_catalog.log';

        $this->di->getLog()->logContent('Process 00000 | Now Performing Operation : '.$sqsData['data']['operation'],'info',$logFile);
        $this->init();
        $operation = $sqsData['data']['operation'];
        switch($operation){

            case 'make_request' :
                $this->di->getLog()->logContent('Make Request : '.print_r($sqsData, true),'info',$logFile);

                $response = $this->_vistarImport->init()->createRequest($sqsData['data']['remote_shop_id'], $sqsData);
                if($response['success']) $this->_vistarData->pushToQueue($response['sqs_data'], (time() + 8));
                else $this->_vistarData->completeProgressBar($response['sqs_data']);

                break;

            case 'get_status' :
                if(!isset($sqsData['data']['retry'])) $this->di->getLog()->logContent('Get Status : '.print_r($sqsData, true),'info',$logFile);

                $remoteResponse = $this->_vistarImport->init()->requestStatus($sqsData['data']['remote_shop_id'], $sqsData);
                if($remoteResponse['success'] && isset($remoteResponse['sqs_data']['data']['retry'])){
                    $runAfterTime = ((25 * $remoteResponse['sqs_data']['data']['retry']) < 100) ? (25 * $remoteResponse['sqs_data']['data']['retry']) : 90;
                    $this->di->getLog()->logContent('PROCESS 00210 | Requestcontrol | handleImport | Run After (secs) = '.$runAfterTime,'info',$logFile);
                    $this->_vistarData->pushToQueue($remoteResponse['sqs_data'], (time() + $runAfterTime));
                }   elseif($remoteResponse['success']) $this->_vistarData->pushToQueue($remoteResponse['sqs_data']);
                else $this->_vistarData->completeProgressBar($remoteResponse['sqs_data']);

                break;

            case 'build_progress' :
                echo "build_progress";
                $this->di->getLog()->logContent('Build Progress : '.print_r($sqsData, true),'info',$logFile);

                $syncCatalog = $this->di->getObjectManager()->get(SyncCatalog::class);
                $remoteResponse = $syncCatalog->init()->buildProgressBar($sqsData);

                if($remoteResponse['success']) {
                    $resetData = $this->di->getObjectManager()->get(Helper::class)->resetImportFlag();
                    $this->_vistarData->pushToQueue($remoteResponse['sqs_data']);
                }
                elseif ($remoteResponse['notice']) $this->_vistarData->completeProgressBar($remoteResponse['sqs_data'], $remoteResponse['notice']);
                else $this->_vistarData->completeProgressBar($remoteResponse['sqs_data']);

                break;

            case 'sync_catalog' :
                echo "sync_catalog";
                if($sqsData['data']['total_variant'] == 0) $this->di->getLog()->logContent('Import Product : '.print_r($sqsData, true),'info',$logFile);

                $syncCatalog = $this->di->getObjectManager()->get(SyncCatalog::class);
                $remoteResponse = $syncCatalog->init()->syncData($sqsData);

                $this->di->getLog()->logContent(' PROCESS 003000 | Route | RequestCOntrol | Response : '.print_r($remoteResponse, true),'info',$logFile);
                if($remoteResponse['requeue'] && !isset($sqsData['data']['test'])) {
                    $this->di->getLog()->logContent('PROCESS 004001 | Requestcontrol | handleImport | '.$remoteResponse['sqs_data']['data']['total_variant'].' variant(s) has been successfullly imported. | Stats : '.json_encode($remoteResponse['data']),'info',$logFile);

                    $this->_vistarData->pushToQueue($remoteResponse['sqs_data']);

                }   elseif (isset($remoteResponse['is_complete']))  {
                    $this->di->getLog()->logContent('PROCESS 004002 | Requestcontrol | handleImport | '.$remoteResponse['sqs_data']['data']['total'].' variant(s) has been successfullly imported. Stats : '.json_encode($remoteResponse['data']),'info',$logFile);

                    $this->_connectorHelper->updateFeedProgress($sqsData['data']['feed_id'],0, 'SHOPIFY_PRODUCT_IMPORT : Finalizing..' );
                    try{ $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Helper')->ifEligibleSendMail($sqsData['data']['user_id']);}
                    catch (\Exception $e){ $this->di->getLog()->logContent('PROCESS 00000 | App\Shopifyhome\Components\Product\Bulk\Route\RequestControl | Error while sending mail : ' . $e->getMessage(), \Phalcon\Logger::CRITICAL, 'mail.log'); }
                    $this->_vistarData->pushToQueue($remoteResponse['sqs_data']);
                }   else {
                    $progress = $this->_connectorHelper->updateFeedProgress($sqsData['data']['feed_id'], 100);
                    if ($progress && $progress == 100) { $this->_connectorHelper->addNotification($sqsData['data']['user_id'], 'Your request has been declined. Kindly contact support.', 'critical'); }
                }

                break;

            case 'delete_nexisting' :
                $this->di->getLog()->logContent('delete_nexisting Process : '.print_r($sqsData, true),'info',$logFile);
                $this->di->getObjectManager()->get(Helper::class)->deleteNotExistingProduct('', 'vistar_');
                $this->_connectorHelper->updateFeedProgress($sqsData['data']['feed_id'], 100, 'Finalizing .. ');
                $this->_connectorHelper->addNotification($sqsData['data']['user_id'], $sqsData['data']['total'].' product variant(s) has been successfully imported.', 'success');

                $this->di->getLog()->logContent('PROCESS 0005000 | Requestcontrol | Import | Non Existing Product Deleted ','info',$logFile);

                // initiate sync with facebook
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollectionForTable('user_details');
                $user_details = $collection->findOne(['user_id' =>(string)$sqsData['data']['user_id'],'product_imported'=>['$exists'=>true]]);

                if ( empty($user_details) ){
                    $collection->updateOne(['user_id' => (string)$sqsData['data']['user_id']], ['$set' => ['product_imported' =>1]]);
                    // $fbData=array('marketplace' => "facebook");
                    // $this->di->getObjectManager()->get("\App\Facebookhome\Models\SourceModel")->initiateSync($fbData);
                }

                break;

            default :
                $this->di->getLog()->logContent('Request Control | Class - App\Shopifyhome\Components\Product\Bulk\Route | Function Name - handleBulkImport | Error Occured, no operation found | SQS Data = \n'.print_r($sqsData, true),'info',$logFile);
                $progress = $this->_connectorHelper->updateFeedProgress($sqsData['data']['feed_id'], 100);
                if ($progress && $progress == 100) $this->_connectorHelper->addNotification($sqsData['data']['user_id'], 'No response found. Kindly contact support for more info.', 'critical');

                break;
        }

        /*$this->di->getLog()->logContent(' Request Control Product | Now processing request for User ID : '.$this->di->getUser()->id,'info','shopify'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'RequestcontrolVistar.log');*/
        return true;
    }
}