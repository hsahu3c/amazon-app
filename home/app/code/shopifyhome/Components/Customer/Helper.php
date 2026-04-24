<?php
/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Customer;

use DateTime;
use DateTimeZone;
use App\Connector\Models\QueuedTasks;
use App\Shopifyhome\Components\Customer\Route\Requestcontrol;
use App\Shopifyhome\Components\Mail\Seller;
use App\Shopifyhome\Components\Core\Common;

class Helper extends Common
{

    public const SHOPIFY_QUEUE_CUSTOMER_IMPORT_MSG = 'SHOPIFY_CUSTOMER_IMPORT';

    public function formatCoreDataForDetail($oData)
    {
        $oData['source_customer_id'] = (string)$oData['id'];
        $oData['is_imported'] = 1;
        unset($oData['id']);
        return $oData;
    }

    public function formatCoreDataForLineItemData($lIData)
    {
            $lIData['source_customer_id'] = (string)$lIData['customer_id'];
            $lIData['is_imported'] = 1;
            $date = new DateTime("now", new DateTimeZone('Asia/Kolkata') );
            $lIData['updated_at'] = $date->format('Y-m-d\TH:i:sO');
            return $lIData;
    }

    public function shapeSqsData($userId, $remoteShopId, QueuedTasks $queuedTask)
    {

        $handlerData = [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'method' => 'fetchShopifyCustomer',
            'queue_name' => 'shopify_customer_import',
            'own_weight' => 100,
            'user_id' => $userId,
            'data' => [
                'user_id' => $userId,
                'individual_weight' => 0,
                'feed_id' => ($status ? $queuedTask->id : false),
                'pageSize' => 250,
                'remote_shop_id' => $remoteShopId // todo : replace with output function made by anshuman
            ]
        ];

        return $handlerData;
    }

    public function appendMissingWebhookDataFromContainer($data){
       return true;
    }

    public function ifEligibleSendMail($userId): void
    {
        $mongoCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection->setSource('user_details');

        $phpMongoCollection = $mongoCollection->getPhpCollection();

        $mongoResponse = $phpMongoCollection->findOne(['user_id' => (string)$userId], ['projection' =>
            ['import_mail' => 1, 'user_id' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array']]);

        if(!isset($mongoResponse['import_mail'])){
            $phpMongoCollection->updateOne(['user_id' => (string)$userId], ['$set' =>
                ['import_mail' => 1]]);

            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $user_details_shopify = $user_details->getDataByUserID((string)$userId, 'shopify');

            $this->di->getObjectManager()->get(Seller::class)->imported($user_details_shopify);
        }
    }

    public function resetImportFlag($userId = '')
    {
        $userId = !empty($userId) ? $userId : $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("customer_container_".$userId)->getPhpCollection();
        $response = $collection->updateMany([],['$set' => ['details.is_imported' => 0]]);
        return [
            'success' => true,
            'match_count' => $response->getMatchedCount(),
            'modified_count' => $response->getModifiedCount()
        ];
    }

    public function deleteNotExistingCustomer($userId = '', $append = '')
    {
        $userId = !empty($userId) ? $userId : $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("customer_container_".$userId)->getPhpCollection();
        $query = ['details.is_imported' => 0]; $options = ['projection' => ['details.source_customer_id' => 1 ]  ];
        $cursor = $collection->find($query, $options);
        $sourceCustomerId = [];
        $response = [];
        foreach ($cursor as $document) {
            if(isset($document['details']['source_customer_id'])) {
                $sourceCustomerId[] = $document['details']['source_customer_id'];
            }
        }
        foreach ($sourceCustomerId as $id){
            $customerContainer = $this->di->getObjectManager()->get('\App\Connector\Models\CustomerContainer');
            $response = $customerContainer->deleteCustomer(['details.source_customer_id' => $id]);
        }

        return true;
    }

    public function formatCoreDataForCustomerData($vData)
    {
        $vData['source_customer_id'] ??= (string)$vData['id'];
        $vData['is_imported'] = 1;
        $date = new DateTime("now", new DateTimeZone('Asia/Kolkata') );
        $vData['updated_at'] = $date->format('Y-m-d\TH:i:sO');
        if(isset($vData['id']))unset($vData['id']);

        return $vData;
    }

    public function getAndQuery($query,$user_id=false){
        $user_id ??= $this->di->getUser()->id;
        $andQuery=[
            '$and'=>[
                ['user_id'=>$user_id],
                $query
            ]
        ];
        return $andQuery;
    }

     /**
     * customerDataRequest function
     * To send customer related data of order
     * through mail requested by store owner
     * @param [array] $sqsData
     * @return array
     */
    public function customerDataRequest($sqsData)
    {
        $logFile = "shopify/customer_requested_data/" . date('d-m-Y') . '.log';
        $userId = $this->di->getUser()->id ?? $sqsData['user_id'];
        $this->di->getLog()->logContent('Customer Data Request Process Started for User: ' . json_encode($userId), 'info', $logFile);
        if (isset($sqsData['shop_id']) && !empty($sqsData['data']['orders_requested'])) {
            $shopId = $sqsData['shop_id'];
            $marketplace = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Shop\Shop::class)->getUserMarkeplace();
            $requestedOrderIds = $sqsData['data']['orders_requested'];
            $orderIds = array_map('strval', $requestedOrderIds);
            $chunkOrders = array_chunk($orderIds, 500);
            $filter = [
                'user_id' => $userId,
                'object_type' => ['$in' => ['source_order', 'target_order']],
                'marketplace' => $marketplace,
                'marketplace_shop_id' => $shopId

            ];
            $mongo = $this->di->getObjectManager()->create('App\Core\Models\BaseMongo');
            $orderContainer = $mongo->getCollectionForTable('order_container');
            foreach ($chunkOrders as $order) {
                $filter['marketplace_reference_id'] = ['$in' => $order];
                $options = [
                    "projection" => ['_id' => 0, 'shipping_address' => 1, 'marketplace_reference_id' => 1],
                    "typeMap" => ['root' => 'array', 'document' => 'array'],
                ];
                $orderData = $orderContainer->find($filter, $options)->toArray();
                if (!empty($orderData)) {
                    foreach ($orderData as $data) {
                        if (!empty($data['shipping_address'][0])) {
                            $ordersWithData[$data['marketplace_reference_id']] = $data['shipping_address'][0];
                        } else {
                            $ordersWithoutData[$data['marketplace_reference_id']] = 'No Data Found';
                        }
                    }
                    $fileName = $userId . '_requested-order-data';
                    $fileLocation = BP . DS . 'var' . DS . 'log' .  DS . $marketplace . DS . 'customer_request_data' . DS . $fileName;
                    $csvFilePath = "{$fileLocation}.csv";
                    $directory = dirname($csvFilePath);
                    if (!is_dir($directory)) {
                        mkdir($directory, 0777, true);
                    }
                    $csvFile = fopen($csvFilePath, 'w');
                    fputcsv($csvFile, ['Sno.', 'Order ID', 'Status', 'First Name', 'Last Name', 'Address', 'City', 'State', 'ZIP', 'Country']);
                    $counter = 1;

                    foreach ($ordersWithData as $orderId => $orderInfo) {
                        fputcsv($csvFile, [
                            $counter,
                            $orderId,
                            'Data Found',
                            $orderInfo['first_name'] ?? '',
                            $orderInfo['last_name'] ?? '',
                            $orderInfo['address'] ?? '',
                            $orderInfo['city'] ?? '',
                            $orderInfo['state'] ?? '',
                            $orderInfo['zip'],
                            $orderInfo['country'] ?? '',
                        ]);
                        $counter++;
                    }

                    foreach ($ordersWithoutData as $orderId => $orderInfo) {
                        fputcsv($csvFile, [
                            $counter,
                            $orderId,
                            $orderInfo,
                            '',
                            '',
                            '',
                            '',
                            '',
                            '',
                            '',
                        ]);
                    }
                    $appName = $this->di->getConfig()->app_name;
                    $msgData = [
                        'username' => $sqsData['data']['shop_domain'] ?? $this->di->getUser()->username,
                        'app_name' => $appName,
                        'name' => $this->di->getUser()->name ?? 'there',
                        'email' => $this->di->getUser()->source_email ?? $this->di->getUser()->email,
                        // 'subject' => 'Customer Requested Data',
                        'subject' => 'Requested Customer Data Attached -'.$appName,
                        'path' => 'shopifyhome' . DS . 'view' . DS . 'email' . DS . 'customer_requested_data.volt',
                        'content' => 'Download Attach File',
                        'files' =>  [
                            [
                                'name' =>  'requested-order-data',
                                'path' => $csvFilePath
                            ]

                        ]

                    ];
                    $mailResponse = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($msgData);
                    if ($mailResponse) {
                        $this->di->getLog()->logContent('Mail send successfully!!', 'info', $logFile);
                    } else {
                        $this->di->getLog()->logContent('Error while sending mail response: ' . json_encode([
                            'mailResponse' => $mailResponse,
                            'sqsData' => $sqsData
                        ]), 'info', $logFile);
                    }
                    fclose($csvFile);
                    unlink($csvFilePath);

                    return ['success' => true, 'message' => 'Process Completed!!'];
                } else {
                    $this->di->getLog()->logContent('No order data found of these orderIds:' . json_encode($order), 'info', $logFile);
                }
            }
        } else {
            $message = 'Required params shop_id missing or empty requested orders';
        }
        $this->di->getLog()->logContent('Error Occured:' . json_encode([
            'message' => $message ?? 'Something went wrong',
            'sqsData' => $sqsData
        ]), 'info', $logFile);

        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

}