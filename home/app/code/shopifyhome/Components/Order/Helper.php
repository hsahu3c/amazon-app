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

namespace App\Shopifyhome\Components\Order;

use DateTime;
use DateTimeZone;
use App\Connector\Models\QueuedTasks;
use App\Shopifyhome\Components\Order\Route\Requestcontrol;
use App\Shopifyhome\Components\Mail\Seller;
use App\Shopifyhome\Components\Core\Common;

class Helper extends Common
{

    public const SHOPIFY_QUEUE_ORDER_IMPORT_MSG = 'SHOPIFY_ORDER_IMPORT';

    public function formatCoreDataForDetail($oData)
    {
        $oData['source_order_id'] = (string)$oData['id'];
        $oData['is_imported'] = 1;
        unset($oData['id']);
        return $oData;
    }

    public function formatCoreDataForLineItemData($lIData)
    {
            $lIData['source_id'] = (string)$lIData['order_id'];
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
            'method' => 'fetchShopifyOrder',
            'queue_name' => 'shopify_order_import',
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
        $collection = $mongo->setSource("order_container_".$userId)->getPhpCollection();
        $response = $collection->updateMany([],['$set' => ['details.is_imported' => 0]]);
        return [
            'success' => true,
            'match_count' => $response->getMatchedCount(),
            'modified_count' => $response->getModifiedCount()
        ];
    }

    public function deleteNotExistingOrder($userId = '', $append = '')
    {
        $userId = !empty($userId) ? $userId : $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("order_container_".$userId)->getPhpCollection();
        $query = ['details.is_imported' => 0]; $options = ['projection' => ['details.source_order_id' => 1 ]  ];
        $cursor = $collection->find($query, $options);
        $sourceOrderId = $response = [];
        foreach ($cursor as $document) {
            if(isset($document['details']['source_order_id'])) {
                $sourceOrderId[] = $document['details']['source_order_id'];
            }
        }
        foreach ($sourceOrderId as $id){
            $orderContainer = $this->di->getObjectManager()->get('\App\Connector\Models\OrderContainer');
            $response = $orderContainer->deleteOrder(['details.source_order_id' => $id]);
        }

        return true;
    }

    public function formatCoreDataForOrderData($vData)
    {
        $vData['source_order_id'] ??= (string)$vData['id'];
        $vData['is_imported'] = 1;
        $date = new DateTime("now", new DateTimeZone('Asia/Kolkata') );
        $vData['updated_at'] = $date->format('Y-m-d\TH:i:sO');
        if(isset($vData['id']))unset($vData['id']);

        return $vData;
    }

}