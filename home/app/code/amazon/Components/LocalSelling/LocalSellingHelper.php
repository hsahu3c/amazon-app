<?php

namespace App\Amazon\Components\LocalSelling;

use Exception;
use App\Amazon\Components\LocalSelling;
use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base;

class LocalSellingHelper extends Base
{

    public function addMessageInQueue($queueData)
    {
        try {
            $response = $this->di->getMessageManager()->pushMessage($queueData);
            return $response;
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function requestCancelOrders(): void
    {
        $cronTask = 'Sync Status';
        $limit = 100;
        $skip = 0;
        $totalShopsCount = 0;
        $continue = true;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('config');

        $this->di->getAppCode()->setAppTag('amazon_sales_channel');
        $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
        $appCodeArray = $appCode->toArray();
        $this->di->getAppCode()->set($appCodeArray);
        $response = $collection->aggregate([['$match' => ['group_code' => 'amazon_local_selling', '$or' => [['key' => 'local_selling_enabled', 'value' => true],
        ['key' => 'order_cancellation_BOPIS', 'value' => true]]]], ['$group' => ['_id' => '$user_id', 'keys' => ['$push' => '$key']]], ['$match' => ['$expr' => ['$eq' => [['$size' => '$keys'], 2]]]]])->toArray();
        $result = json_decode(json_encode($response),true);
        $result = array_column($result,"_id" );

        $count = count($result);


        do {
            $options = [
                'limit' => $limit,
                'skip' => $skip,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];
            // need to add check for uninstalled sellers
            $user = $collection->aggregate([['$match' => ['group_code' => 'amazon_local_selling', '$or' => [['key' => 'local_selling_enabled', 'value' => true],
            ['key' => 'order_cancellation_BOPIS', 'value' => true]]]], ['$group' => ['_id' => '$user_id', 'keys' => ['$push' => '$key']]], ['$match' => ['$expr' => ['$eq' => [['$size' => '$keys'], 2]]]]], $options)->toArray();
            foreach($user as $userId){

                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => \App\Amazon\Components\LocalSelling\LocalSellingHelper::class,
                    'method' => 'automaticCancelOrdersBOPIS',
                    'queue_name' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->automaticCancelOrdersBOPIS(),
                    // 'user_id' => $userId['user_id'],
                    'appCode' => $this->di->getAppCode()->get(),
                    'appTag' => $this->di->getAppCode()->getAppTag(),
                    'response' => $userId['_id']
                ];

                $this->addMessageInQueue($handlerData);
            }

            if ($count == $limit) {
                $skip += $limit;
            } else {
                $continue = false;
            }
        } while ($continue);
    }
    
    public function requestRefundOrders(): void{

        $cronTask = 'Sync Status';
        $limit = 100;
        $skip = 0;
        $totalShopsCount = 0;
        $continue = true;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('config');

        $this->di->getAppCode()->setAppTag('amazon_sales_channel');
        $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
        $appCodeArray = $appCode->toArray();
        $this->di->getAppCode()->set($appCodeArray);
        $response = $collection->aggregate([['$match' => ['group_code' => 'amazon_local_selling', '$or' => [['key' => 'local_selling_enabled', 'value' => true],
        ['key' => 'order_refund_BOPIS', 'value' => true]]]], ['$group' => ['_id' => '$user_id', 'keys' => ['$push' => '$key']]], ['$match' => ['$expr' => ['$eq' => [['$size' => '$keys'], 2]]]]])->toArray();
        $result = json_decode(json_encode($response),true);
        $result = array_column($result,"_id" );

        $count = count($result);

        do {
            $options = [
                'limit' => $limit,
                'skip' => $skip,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];
            // need to add check for uninstalled sellers
            $user = $collection->aggregate([['$match' => ['group_code' => 'amazon_local_selling', '$or' => [['key' => 'local_selling_enabled', 'value' => true],
            ['key' => 'order_refund_BOPIS', 'value' => true]]]], ['$group' => ['_id' => '$user_id', 'keys' => ['$push' => '$key']]], ['$match' => ['$expr' => ['$eq' => [['$size' => '$keys'], 2]]]]])->toArray();
            foreach($user as $userId){

                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => \App\Amazon\Components\LocalSelling\LocalSellingHelper::class,
                    'method' => 'automaticRefundOrdersBOPIS',
                    'queue_name' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->automaticRefundOrdersBOPIS(),
                    // 'user_id' => $userId['user_id'],
                    'appCode' => $this->di->getAppCode()->get(),
                    'appTag' => $this->di->getAppCode()->getAppTag(),
                    'response' => $userId['_id']
                 ];

                $this->addMessageInQueue($handlerData);
                }

            if ($count == $limit) {
                $skip += $limit;
            } else {
                $continue = false;
            }
        } while ($continue);
    }

    public function automaticCancelOrdersBOPIS($sqs_data){
        $userId = $sqs_data['response'];
        $cancelOrderStatus = ['Pending', 'ReadyForPickup'];
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::ORDER_CONTAINER);              
        $res= $collection->find(['user_id'=>$userId, 'is_ispu'=>true, 'status'=>['$in'=>$cancelOrderStatus]])->toArray();
        $res = json_decode(json_encode($res),true);

        $d2  = $this->di->getObjectManager()->get(LocalSelling::class)->storeDate();
        $todayDate = date_create($d2);
        foreach($res as $data){
                $lastUpdatedDate = date_create($data['updated_at']);
                $diff = date_diff($lastUpdatedDate,$todayDate);
                $diff = (int)$diff->format("%a");
                if($diff > 5){
                    $updateRes= $collection->updateMany(['user_id'=>$userId,'is_ispu'=>true, 'status'=>['$in'=>$cancelOrderStatus]],['$set'=>['status'=>'Canceled', 'updated_at'=>$d2]])->toArray();
                }
            }

        return $res;
    }

    public function automaticRefundOrdersBOPIS($sqs_data){
        $userId = $sqs_data['response'];
        $refundOrderStatus = ['RefusedPickup', 'StoreReturn'];
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::ORDER_CONTAINER);              
        $res= $collection->find(['user_id'=>$userId, 'is_ispu'=>true, 'status'=>['$in'=>$refundOrderStatus]])->toArray();
        $res = json_decode(json_encode($res),true);

        $d2  = $this->di->getObjectManager()->get(LocalSelling::class)->storeDate();
        $todayDate = date_create($d2);
        foreach($res as $data){
                $lastUpdatedDate = date_create($data['updated_at']);
                $diff = date_diff($lastUpdatedDate,$todayDate);
                $diff = (int)$diff->format("%a");
                if($diff > 5){
                    $updateRes= $collection->updateMany(['user_id'=>$userId,'is_ispu'=>true, 'status'=>['$in'=>$refundOrderStatus]],['$set'=>['status'=>'Refund', 'updated_at'=>$d2]])->toArray();
                }
            }

        return $res;
    }
}

