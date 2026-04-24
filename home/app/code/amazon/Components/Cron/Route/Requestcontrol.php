<?php

namespace App\Amazon\Components\Cron\Route;

use App\Amazon\Components\Report\Report;
use App\Amazon\Components\Product\Product;
use App\Amazon\Components\Product\Upload;
use App\Amazon\Components\Inventory\Inventory;
use App\Amazon\Components\Price\Price;
use App\Amazon\Components\Image\Image;
use App\Amazon\Components\Product\Delete;
use App\Core\Components\Base;
use App\Amazon\Models\SourceModel;
use MongoDB\BSON\UTCDateTime;
use Exception;
use App\Amazon\Components\Common\Helper;

class Requestcontrol extends Base
{
    public function syncOrder($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            // return $this->di->getObjectManager()->get('\App\Amazon\Models\SourceModel')->initiateOrderSync($sqs_data['user_id']);

            $userId = $sqs_data['user_id'];
            $processName = 'sync_order';

            $processId = $this->getCurrentMicroTime();
            $logFile = $this->getLogFile($processName);

            if ($this->startProcess($userId, $processName)) {

                $this->di->getLog()->logContent("{$processName} having pid {$processId} for userId {$userId} is started.", 'info', $logFile);

                $response = $this->di->getObjectManager()->get(SourceModel::class)->initiateOrderSync($userId);
                $this->stopProcess($userId, $processName);

                $this->di->getLog()->logContent("{$processName} having pid {$processId} for userId {$userId} is stopped." . PHP_EOL, 'info', $logFile);

                return $response;
            }
            $this->di->getLog()->logContent("{$processName} for userId {$userId} is already running." . PHP_EOL, 'info', $logFile);
            return ['success' => false, 'message' => 'already running.'];
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function syncShipment($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            // return $this->di->getObjectManager()->get('\App\Amazon\Models\SourceModel')->initiateShipmentSync($sqs_data['user_id']);

            $userId = $sqs_data['user_id'];
            $processName = 'sync_shipment';

            $processId = $this->getCurrentMicroTime();
            $logFile = $this->getLogFile($processName);

            if ($this->startProcess($userId, $processName)) {

                $this->di->getLog()->logContent("{$processName} having pid {$processId} for userId {$userId} is started.", 'info', $logFile);

                $response = $this->di->getObjectManager()->get(SourceModel::class)->initiateShipmentSync($userId);
                $this->stopProcess($userId, $processName);

                $this->di->getLog()->logContent("{$processName} for userId {$userId} is stopped." . PHP_EOL, 'info', $logFile);

                return $response;
            }
            $this->di->getLog()->logContent("{$processName} having pid {$processId} for userId {$userId} is already running." . PHP_EOL, 'info', $logFile);
            return ['success' => false, 'message' => 'already running.'];
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function syncFeed($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            // return $this->di->getObjectManager()->get('\App\Amazon\Models\SourceModel')->initiateFeedSync($sqs_data['user_id']);

            $userId = $sqs_data['user_id'];
            $processName = 'sync_feed';

            $processId = $this->getCurrentMicroTime();
            $logFile = $this->getLogFile($processName);

            if ($this->startProcess($userId, $processName)) {

                $this->di->getLog()->logContent("{$processName} having pid {$processId} for userId {$userId} is started.", 'info', $logFile);

                $response = $this->di->getObjectManager()->get(SourceModel::class)->initiateFeedSync($userId);
                $this->stopProcess($userId, $processName);

                $this->di->getLog()->logContent("{$processName} having pid {$processId} for userId {$userId} is stopped." . PHP_EOL, 'info', $logFile);

                return $response;
            }
            $this->di->getLog()->logContent("{$processName} for userId {$userId} is already running." . PHP_EOL, 'info', $logFile);
            return ['success' => false, 'message' => 'already running.'];
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function syncInventory($sqs_data)
    {
        //       $this->di->getLog()->logContent('Initiate qty sync userId :'.json_encode($sqs_data) , 'info', 'cron.log');
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(SourceModel::class)->initiateInventorySync($sqs_data);

            // $processName = 'sync_inventory';
        } else {
            return ['success' => false, 'message' => 'user_id is missing.'];
        }
    }

    public function syncPrice($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(SourceModel::class)->initiatePriceSync($sqs_data);

            // $processName = 'sync_price';
        } else {
            return ['success' => false, 'message' => 'user_id is missing.'];
        }
    }

    public function syncImage($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(SourceModel::class)->initiateImageSync($sqs_data);

            // $processName = 'sync_image';
        } else {
            return ['success' => false, 'message' => 'user_id is missing.'];
        }
    }

    public function requestReport($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(SourceModel::class)->initiateReportRequest($sqs_data);
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function getReport($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(SourceModel::class)->initiateReportFetch($sqs_data['user_id']);
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function matchProductFromAmazon($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(SourceModel::class)->initiateMatchProductFromAmazon($sqs_data);
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function updateProductContainerStatus($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(SourceModel::class)->initiateProductContainerStatusUpdate($sqs_data);
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function startProcess($user_id, $process_name, $flag = true)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $processCollection = $mongo->getCollectionForTable(Helper::MERCHANT_PROCESSES);

            $id = $this->getProcessId($user_id, $process_name);

            $process = $processCollection->findOne(
                [
                    '_id' => $id,
                    'user_id' => $user_id
                ],
                [
                    'projection' => [],
                    'typeMap' => ['root' => 'array', 'document' => 'array']
                ]
            );

            if (!$process) {
                $bsonDate = new UTCDateTime();
                $insertData = [
                    '_id' => $id,
                    'user_id' => $user_id,
                    'process_name' => $process_name,
                    // 'created_at'    => date('c')
                    'created_at' => $bsonDate
                ];

                $status = $processCollection->insertOne($insertData);
                if ($status->getInsertedCount()) {
                    // (string)$status->getInsertedId();
                    return true;
                }
                return false;
            }
            // calculate time diff of 1 hour then
            $createdAtTimeStamp = $process['created_at']->toDateTime()->getTimestamp();
            $waitTime = 1 * 60 * 60;
            //In Seconds (1 hours)
            if (($createdAtTimeStamp + $waitTime) < time()) {
                // $status = $processCollection->updateOne(['_id' => $id], ['created_at' => new \MongoDB\BSON\UTCDateTime()]);
                // if($status->getModifiedCount()) {
                //     return true;
                // }
                // else {
                //     return false;
                // }

                if ($flag && $this->stopProcess($user_id, $process_name)) {
                    return $this->startProcess($user_id, $process_name, false);
                }
                return false;
            }
            return false;
        } catch (Exception) {
            return false;
        }
    }

    public function stopProcess($user_id, $process_name)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $processCollection = $mongo->getCollectionForTable(Helper::MERCHANT_PROCESSES);

            $response = $processCollection->deleteOne(
                [
                    '_id' => $this->getProcessId($user_id, $process_name)
                ],
                ['w' => true]
            );

            if ($response->getDeletedCount()) {
                return true;
            }
            return false;
        } catch (Exception) {
            return false;
        }
    }

    private function getProcessId($user_id, $process_name): string
    {
        return "{$user_id}_{$process_name}";
    }

    private function getLogFile($process_name): string
    {
        $date = date('d-m-Y');
        return "amazon/{$process_name}/{$date}.log";
    }

    public function getCurrentMicroTime()
    {
        return round(microtime(true) * 1000);
    }

    public function getReportMethod($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(Report::class)->init($sqs_data)
                ->getReport();
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function moldReportMethod($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(Report::class)->init($sqs_data)
                ->moldReport($sqs_data);
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    /**
     * by shivam
     */

    /**
     * @param $sqs_data
     * @return array
     */
    public function processProductReport($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(\App\Amazon\Components\Report\SyncStatus::class)->process($sqs_data);
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }
    
    public function processSellerPerformanceReport($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(\App\Amazon\Components\Report\SyncStatus::class)->requestPerformanceReport($sqs_data);
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function searchOnAmazon($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(SourceModel::class)->initiateSearchOnAmazon($sqs_data);
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function deleteAmazonListing($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(Product::class)
                ->deleteAmazonListing($sqs_data);
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function processProductUpload($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            return $this->di->getObjectManager()->get(Upload::class)->init($sqs_data)
                ->upload($sqs_data);
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function processUpdateInventory($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            $response = $this->di->getObjectManager()->get(Inventory::class)->init($sqs_data)->upload($sqs_data);
            return ['success' => true];
        }
        return ['success' => false, 'message' => 'user_id is missing.'];

    }

    public function processUpdatePrice($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            $response = $this->di->getObjectManager()->get(Price::class)->init($sqs_data)->upload($sqs_data);
            return ['success' => true];
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function processUpdateImage($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            $response = $this->di->getObjectManager()->get(Image::class)->init($sqs_data)->upload($sqs_data);
            return ['success' => true];
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function processProductDelete($sqs_data)
    {

        if (isset($sqs_data['user_id'])) {
            $response = $this->di->getObjectManager()->get(Delete::class)->init($sqs_data)->delete($sqs_data);
            return ['success' => true];
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }

    public function syncFailedOrder($sqs_data)
    {
        if (isset($sqs_data['user_id'])) {
            // return $this->di->getObjectManager()->get('\App\Amazon\Models\SourceModel')->initiateOrderSync($sqs_data['user_id']);

            $userId = $sqs_data['user_id'];
            $processName = 'sync_failed_order';

            $processId = $this->getCurrentMicroTime();
            $logFile = $this->getLogFile($processName);
            $this->stopProcess($userId, $processName);

            if ($this->startProcess($userId, $processName)) {

                $this->di->getLog()->logContent("{$processName} having pid {$processId} for userId {$userId} is started.", 'info', $logFile);

                $response = $this->di->getObjectManager()->get(SourceModel::class)->initiateFailedOrderSync($userId);

                $this->stopProcess($userId, $processName);

                $this->di->getLog()->logContent("{$processName} having pid {$processId} for userId {$userId} is stopped." . PHP_EOL, 'info', $logFile);

                return $response;
            }
            $this->di->getLog()->logContent("{$processName} for userId {$userId} is already running." . PHP_EOL, 'info', $logFile);
            return ['success' => false, 'message' => 'already running.'];
        }
        return ['success' => false, 'message' => 'user_id is missing.'];
    }
    public function pushProcessScript($requestData){
        $date = date('d-m-Y');
        $logFile = "amazon/ProcessInitiatetoInactiveScript/{$date}.log";
        $handlerData = [
            'type' => 'full_class',
            'method' => 'ShiftToInactive',
            'class_name' => Requestcontrol::class,
            'queue_name' => 'amazon_active_to_inactive_script',
            'user_id' => $requestData['user_id'],
            'requestData' => $requestData,
            'marketplace' => 'amazon',
            'shop_id' => $requestData['target_marketplace']['target_shop_id'],
            "appTag" => 'amazon_sales_channel'
        ];
        $this->di->getLog()->logContent('Handler Data Prepared = ' . json_encode($handlerData, true), 'info', $logFile);
        $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');

        $rmqHelper->pushMessage($handlerData);
    }
    public function ShiftToInactive($sqsMessage)
    {
        $date = date('d-m-Y');
        $logFile = "amazon/ProcessInitiatetoInactiveScript/{$date}.log";
        $this->di->getLog()->logContent('SqsMessage= ' . json_encode($sqsMessage, true), 'info', $logFile);

        try {
            $requestData = $sqsMessage['requestData'];
            $userId = $requestData['user_id'];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $SELLER_SKU_KEY = 'seller-sku';
            $amazonListingCollection = $mongo->getCollectionForTable(Helper::AMAZON_LISTING);
            if (isset($sqsMessage['lastUpdatedKey'])) {
                $aggregate[] = [
                    '$match' => [
                        '_id' => ['$gt' => new \MongoDB\BSON\ObjectId($sqsMessage['lastUpdatedKey']['$oid'])]
                    ]
                ];
            }
            $aggregate[] = 
                [
                    '$match' => [
                        'user_id' => $userId,
                        'shop_id' => $requestData['target_marketplace']['target_shop_id'],
                        'status' => 'Active',
                        'fulfillment-channel' => 'DEFAULT',
                        'matched' => null
                    ]
                    ];
                    $aggregate[] = [
                        '$sort' => ['_id' => 1],
                    ];
                    $aggregate[] = [
                        '$limit' => 1500,
                    ];
                    //add projection
                    $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                    $this->di->getLog()->logContent('Aggregate= ' . json_encode($aggregate, true), 'info', $logFile);

                    $amazonListings = $amazonListingCollection->aggregate($aggregate);  
                    $products = json_decode(json_encode($amazonListings->toArray()),true);
                    $this->di->getLog()->logContent('Products= ' . json_encode($products, true), 'info', $logFile);

                    if(count($products) > 0)
                    {
                        $lasUpdateKey = '';
                       foreach($products as $product){
                        if(isset($product['status']) && $product['status'] == 'Active'){
                            $resp = $this->getListingsFromAmazon($requestData,$product[$SELLER_SKU_KEY]);
                            $this->di->getLog()->logContent('Get Listing Response= ' . json_encode($resp, true), 'info', $logFile);

                            if($resp['status'] === true) {            
                                $lead_time = false;
                                if(isset($resp['data']['attributes']['fulfillment_availability'][0]['lead_time_to_ship_max_days'])) {
                                    $lead_time = $resp['data']['attributes']['fulfillment_availability'][0]['lead_time_to_ship_max_days'];
                                }
                                if((isset($resp['data']['attributes']['fulfillment_availability'][0]['quantity']) && $resp['data']['attributes']['fulfillment_availability'][0]['quantity'] > 0) || (isset($resp['data']['summaries'][0]['status'])  && in_array('BUYABLE',$resp['data']['summaries'][0]['status'])) ) {  
                                $patchRes = $this->patchListingsOnAmazon($requestData,$product[$SELLER_SKU_KEY], $lead_time);
                                $this->di->getLog()->logContent('patch Listing Response= ' . json_encode($patchRes, true), 'info', $logFile);

                                if($patchRes['status'] === true) {
                                    if($patchRes['data']['status'] == 'ACCEPTED') {

                                        // echo " | Patch Success.".PHP_EOL;
                                    }
                                    else {

                                        // echo " | Patch Error Can be Handled.".PHP_EOL;
                                    }
                                }
                                else {
                                    // echo " | Patch Error.".PHP_EOL;
                                }
                            }
                            }
                            
                        }
                        if (isset($product['_id'])) {
                            $lasUpdateKey = $product['_id'];
                        }
                       
                       }
                       if(!empty($lasUpdateKey)){
                        $sqsMessage['lastUpdatedKey'] = $lasUpdateKey;
                       }
                       $rmqHelper->pushMessage($sqsMessage);
                       return true;
                    }
                    return true;     
            }
            catch (Exception) {
                return false;
            }

    }
    public function patchListingsOnAmazon($requestData,$seller_sku,$lead_time){
        $remoteShopId = $requestData['remote_shop_id'];
        $payload['shop_id'] = $remoteShopId;
        $payload['product_type'] = 'PRODUCT';
        $payload['patches'] = [
            [
              "op"=> "replace",
              "operation_type"=>"PARTIAL_UPDATE",
              "path"=>"/attributes/fulfillment_availability",
              "value"=>[
                  [
                      "fulfillment_channel_code"=> "DEFAULT",
                      "quantity"=> (int)0,
                      "lead_time_to_ship_max_days" => $lead_time
                      ]
              ]
            ]
          ];
          $payload['sku'] = rawurlencode($seller_sku);
        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        $res = $commonHelper->sendRequestToAmazon('listing-update', $payload, 'POST');
        if(isset($res['success']) && $res['success']){
            
                return ['status'=>true, 'data'=>$res['response']];
            
            }
            else {
                return ['status'=>false, 'error'=>$res['error']];
            }

    }
    public function getListingsFromAmazon($requestData,$sellerSku)
    {
        $remoteShopId = $requestData['remote_shop_id'];
        // $sku = $productData['sku'];
        $rawBody['sku'] = rawurlencode($sellerSku);
        $rawBody['shop_id'] = $remoteShopId;
        // summaries%2Cattributes%2Cissues%2Coffers%2CfulfillmentAvailability%2Cprocurement%2Crelationships%2CproductTypes
        $rawBody['includedData'] = "issues,attributes,summaries,offers,fulfillmentAvailability,procurement,relationships,productTypes";
        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        $response = $commonHelper->sendRequestToAmazon('listing', $rawBody, 'GET');
        // print_r($response);die;
        if($response['success']){
            // $this->di->getLog()->logContent('http_code: '.$httpcode.PHP_EOL.'body: '.$response, 'info', "horseLoverz/get/success/{$seller_sku}.log");
            return ['status'=>true, 'data'=>$response['response']];
        }
        else {
            // $this->di->getLog()->logContent('http_code: '.$httpcode.PHP_EOL.'body: '.print_r($response,true), 'info', "horseLoverz/get/failure/{$seller_sku}.log");
            return ['status'=>false, 'error'=>$response];
        }
    }

}
