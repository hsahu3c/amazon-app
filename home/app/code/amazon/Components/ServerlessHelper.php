<?php

namespace App\Amazon\Components;

use Exception;
use App\Core\Components\Base as Base;
use App\Connector\Components\Dynamo;
use Aws\DynamoDb\Marshaler;
use App\Core\Models\BaseMongo;

class ServerlessHelper extends Base
{
    public $deleteEntry = true;

    public $maxLimit = 5000;

    public $dynamoClientObj;

    public $marshaler;

    public $spapiError = null;

    public function handleDelete($event): void
    {
        $tables = [
            "amazon_unified_json_listing_sync_spapi",
            "amazon_shipment_sync_spapi",
            "amazon_inventory_sync_spapi"
        ];
        $shopTables = [
            "amazon_unified_json_listing_shop_spapi",
            "amazon_shipment_shop_spapi",
            "amazon_inventory_shop_spapi"
        ];
        $shopId = $event['remote_shop_id'];
        $this->deleteEntry = true;
        if ($shopId) {
            $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
            $this->dynamoClientObj = $dynamoObj->getDetails();
            $this->marshaler = new Marshaler();

            if (!isset($event['spapi_error'])) {
                foreach ($shopTables as $shopTable) {
                    $this->deleteShop($event, $shopTable);
                }

                $this->deleteOtherData($shopId);
            } else {
                $this->spapiError = $event['spapi_error'];
            }

            foreach ($tables as $table) {
                $this->deleteData($shopId, $table);
            }

            if ($this->deleteEntry) {
                try {
                    $this->dynamoClientObj->deleteItem([
                        'TableName' => 'amazon_account_delete',
                        'Key' => $this->marshaler->marshalItem([
                            'id' => $shopId,
                        ])
                    ]);
                    if (!empty($event['update_after_delete'])) {
                        $this->sendMessage($event);
                    }
                } catch (Exception $e) {
                    $this->di->getLog()->logContent('Exception from ServerlessHelper handleDelete(): '
                        . print_r($e), 'info', 'exception.log');
                }
            }
        }
    }

    private function deleteData($shopId, $table): void
    {
        try {
            $counter = 0;
            $moreData = false;
            $params = [
                'TableName' => $table,
                'ProjectionExpression' => 'id,user_id,source_shop_id,home_shop_id',
                'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                    ':remote_shop_id' => $shopId
                ]),
                'KeyConditionExpression' => 'remote_shop_id = :remote_shop_id'
            ];
            do {
                $connectionData = $this->dynamoClientObj->query($params);
                $connectionData = $this->convertToArray($connectionData);
                $actionWiseData = [];
                $sourceProductIds = [];
                if (!empty($connectionData['Items'])) {
                    $counter = $counter + count($connectionData['Items']);
                    if (isset($connectionData['LastEvaluatedKey'])) {
                        $params['ExclusiveStartKey'] = $connectionData['LastEvaluatedKey'];
                        $moreData = true;
                    } else {
                        $moreData = false;
                    }
                    if ($table == 'amazon_unified_json_listing_sync_spapi') {
                        if (isset($this->spapiError)) {
                            $userId = $connectionData['Items'][0]['user_id'] ?? null;
                            $sourceShopId = $connectionData['Items'][0]['source_shop_id'] ?? null;
                            $targetShopId = $connectionData['Items'][0]['home_shop_id'] ?? null;
                            if (isset($userId, $sourceShopId, $targetShopId)) {
                                $ids = array_column($connectionData['Items'], 'id');
                                foreach ($ids as $id) {
                                    $explodeData = explode("_", $id);
                                    $actionWiseData[$explodeData[2]][] = $explodeData[1];
                                    $sourceProductIds[] = $explodeData[1];
                                }

                                if (!empty($actionWiseData)) {
                                    $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')
                                        ->insertLastSyncActionData($userId, $targetShopId, $sourceShopId, $actionWiseData, $this->spapiError);
                                }

                                if (!empty($sourceProductIds)) {
                                    $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')
                                        ->removeProgressTag($userId, $sourceShopId, $targetShopId, $sourceProductIds);
                                }
                            }
                        }
                    }

                    $itemsToDelete = array_chunk($connectionData['Items'], 25);
                    foreach ($itemsToDelete as $chunk) {
                        $batchRequest = [];
                        foreach ($chunk as $value) {
                            $batchRequest[] = [
                                'DeleteRequest' => [
                                    'Key' => [
                                        'remote_shop_id' => ['S' => $shopId],
                                        'id' => ['S' => $value['id']],
                                    ],
                                ],
                            ];
                        }

                        if (!empty($batchRequest)) {
                            $this->dynamoClientObj->batchWriteItem([
                                'RequestItems' => [
                                    $table => $batchRequest,
                                ],
                            ]);
                        }
                    }
                }
            } while ($moreData && $counter < $this->maxLimit);

            if ($moreData) {
                $this->deleteEntry = false;
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from ServerlessHelper deleteData(): '
                . print_r($e), 'info', 'exception.log');
        }
    }

    private function deleteShop($event, $table): void
    {
        try {
            $this->dynamoClientObj->deleteItem([
                'TableName' => $table,
                'Key' => $this->marshaler->marshalItem([
                    'activate' => "1",
                    'id' => $event['remote_shop_id']
                ])
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from ServerlessHelper deleteShop(): '
                . print_r($e), 'info', 'exception.log');
        }
    }

    private function deleteOtherData($shopId): void
    {
        try {
            $this->dynamoClientObj->deleteItem([
                'TableName' => 'amazon_spapi_info',
                'Key' => $this->marshaler->marshalItem([
                    'id' => $shopId
                ])
            ]);

            $this->dynamoClientObj->deleteItem([
                'TableName' => 'amazon_order_sync_priority_users',
                'Key' => $this->marshaler->marshalItem([
                    'id' => $shopId
                ])
            ]);

            $this->dynamoClientObj->deleteItem([
                'TableName' => 'amazon_api_throttle',
                'Key' => $this->marshaler->marshalItem([
                    'id' => $shopId
                ])
            ]);

            $shop = $this->fetchShopByRemoteShopId($shopId);
            if (!empty($shop['warehouses'][0]['seller_id']) && !empty($shop['warehouses'][0]['region'])) {
                $sellerId = $shop['warehouses'][0]['seller_id'];
                $region = $shop['warehouses'][0]['region'];
                $rateLimiterPartitionKey = $sellerId . '__' . $region;
                $this->dynamoClientObj->deleteItem([
                    'TableName' => 'amazon_spapi_rate_limitter',
                    'Key' => $this->marshaler->marshalItem([
                        'id' => $rateLimiterPartitionKey
                    ])
                ]);
            }

            $params = [
                'TableName' => 'amazon_feed_spapi',
                'ProjectionExpression' => 'id',
                'FilterExpression' => 'remote_shop_id =:remote_shop_id',
                'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                    ':remote_shop_id' => $shopId,
                    ':progress' => '1'
                ]),
                'KeyConditionExpression' => 'progress = :progress'
            ];

            $connectionData = $this->dynamoClientObj->query($params);
            $connectionData = $this->convertToArray($connectionData);

            if (!empty($connectionData['Items'])) {
                foreach ($connectionData['Items'] as $value) {
                    $this->dynamoClientObj->deleteItem([
                        'TableName' => 'amazon_feed_spapi',
                        'Key' => $this->marshaler->marshalItem([
                            'id' => $value['id'],
                            'progress' => '1'
                        ])
                    ]);
                }
            }

            $params = [
                'TableName' => 'SPAPI_Error',
                'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                    ':remote_shop_id' => $shopId
                ]),
                'KeyConditionExpression' => 'remote_shop_id = :remote_shop_id'
            ];

            $getData = $this->dynamoClientObj->query($params);
            $getData = $this->convertToArray($getData);
            if (!empty($getData['Items'])) {
                foreach ($getData['Items'] as $value) {
                    $this->dynamoClientObj->deleteItem([
                        'TableName' => 'SPAPI_Error',
                        'Key' => $this->marshaler->marshalItem([
                            'remote_shop_id' => $shopId,
                            'error_code' => $value['error_code']
                        ])
                    ]);
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from ServerlessHelper deleteOtherData(): '
                . print_r($e), 'info', 'exception.log');
        }
    }

    private function fetchShopByRemoteShopId($remoteShopId)
    {
        try {
            $mongo = $this->di->getObjectManager()->get(BaseMongo::class);
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $options = [
                'projection' => ['shops.$' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ];
            $result = $userDetailsCollection->findOne(
                [
                    'shops' => [
                        '$elemMatch' => [
                            'remote_shop_id' => $remoteShopId,
                            'marketplace' => 'amazon',
                        ],
                    ],
                ],
                $options
            );
            return $result['shops'][0] ?? null;
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                'Exception from ServerlessHelper fetchShopByRemoteShopId(): ' . print_r($e, true),
                'info',
                'exception.log'
            );
            return null;
        }
    }

    private function convertToArray($connectionData)
    {
        $items = [];
        foreach ($connectionData['Items'] as $item) {
            $items[] = $this->marshaler->unmarshalItem($item);
        }

        $connectionData['Items'] = $items;
        return $connectionData;
    }

    private function sendMessage($event): void
    {
        if (!empty($event['after_deletion_data'])) {
            $handlerData = json_decode((string) $event['after_deletion_data'], true);
            $this->di->getObjectManager()
                ->get('App\Core\Components\Message\Handler\Sqs')
                ->pushMessage($handlerData);
        }
    }
}