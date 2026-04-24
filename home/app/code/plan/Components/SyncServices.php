<?php

namespace App\Plan\Components;

use App\Core\Components\Base;
use App\Connector\Components\Dynamo;
use App\Plan\Models\Plan;
use Aws\DynamoDb\Marshaler;
use Exception;

/**
 * class SyncServices for handling syncing services in the plan
 */
class SyncServices extends Base
{
    /**
     * function to update and get entry of the dynamo
     */
    public function getAndUpdateEntryInDynamo($remoteShopId, $data, $method = 'POST')
    {
        try {
            if ($method == 'POST') {
                if (is_numeric($data['disable_order_sync']) && is_string($remoteShopId)) {
                    return $this->di->getObjectManager()->get(Plan::class)->updateEntryInDynamo($remoteShopId, $data['disable_order_sync']);
                } else {
                    return ['success' => false, 'message' => 'disable_order_sync must be numeric and remote_shop_id must be a string.'];
                }
            } elseif ($method == 'GET') {
                $tableName = 'user_details';
                $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
                $dynamoClientObj = $dynamoObj->getDetails();
                $dynamoData = $dynamoClientObj->getItem(['ConsistentRead' => true, 'TableName' => $tableName, 'Key' => ['id'   => ['S' => (string)$remoteShopId]]]);
                if (empty($dynamoData['Item'])) {
                    return ['success' => false, 'message' => 'Shop entry not found not dynamo'];
                }
                $marshalerObj = new Marshaler();
                $dynamoData = $marshalerObj->unmarshalItem($dynamoData['Item']);
                return ['success' => true, 'data' => $dynamoData];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('App\Plan\Components\SyncServices:getAndUpdateEntryInDynamo(), Error: ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
        return ['success' => false, 'message' => 'Something went wrong'];
    }
}
