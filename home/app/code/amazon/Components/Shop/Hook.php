<?php

namespace App\Amazon\Components\Shop;

use App\Amazon\Components\Common\Helper;
use App\Connector\Components\Dynamo;
use App\Core\Components\Base as Base;
use Aws\DynamoDb\Marshaler;
use Exception;

class Hook extends Base
{

    /**
     * shopUpdate function
     * Handled the response ACCOUNT_STATUS_CHANGED Webhook
     * @param [array] $shopUpdate
     * @return array
     */
    public function shopUpdate($sqsData)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetails = $mongo->getCollectionForTable(Helper::USER_DETAILS);
        if (isset($sqsData['user_id']) && !empty($sqsData['user_id'])) {
            if (isset($sqsData['marketplace']) && !empty($sqsData['marketplace'])) {
                if (isset($sqsData['data']) && !empty($sqsData['data'])) {
                    if (isset($sqsData['data']['accountStatusChangeNotification'])) {
                        $accountNotification = $sqsData['data']['accountStatusChangeNotification'];
                        if (isset($accountNotification['currentAccountStatus'])) {
                            $status = $accountNotification['currentAccountStatus'];
                            $userDetails->updateOne(['user_id' => $sqsData['user_id'], 'shops.marketplace' => $sqsData['marketplace']], ['$set' => ['marketplace_status' => $status]]);
                            return ['success' => true, 'message' => 'Status Updated Successfully'];
                        }
                    }
                }
            }
        }
    }

    /**
     * checkAmazonAccountStatus function
     * CRON which validates the Amazon account status based on the error_code.
     * If the error is resolved, it set status to active in config.
     * @param [array] $params
     * @return array
     */
    public function checkAmazonAccountStatus($params = [])
    {
        try {
            $logFile = "amazon/check_amazon_account_status/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $config = $mongo->getCollectionForTable('config');
            $options = [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'limit' => 500,
                'projection' => ['user_id' => 1, 'target_shop_id' => 1, 'source_shop_id' => 1, 'source' => 1, 'error_code' => 1],
                'sort' => ['_id' => 1]
            ];
            if (isset($params['_id']['$oid'])) {
                $configData = $config->find(
                    [
                        '_id' => ['$gt' => new \MongoDB\BSON\ObjectId($params['_id']['$oid'])],
                        'group_code' => 'feedStatus',
                        'key' => 'accountStatus',
                        'target' => Helper::TARGET,
                        'target_shop_id' => ['$ne' => null],
                        'error_code' => 'InvalidInput',
                        'value' => 'inactive'
                        // 'error_code' => ['$in' => ['InvalidInput', 'Unauthorized']],
                        // 'value' => ['$in' => ['inactive', 'access_denied']]
                    ],
                    $options
                )->toArray();
            } else {
                $configData = $config->find(
                    [
                        'group_code' => 'feedStatus',
                        'key' => 'accountStatus',
                        'target' => Helper::TARGET,
                        'target_shop_id' => ['$ne' => null],
                        'error_code' => 'InvalidInput',
                        'value' => 'inactive'
                        // 'error_code' => ['$in' => ['InvalidInput', 'Unauthorized']],
                        // 'value' => ['$in' => ['inactive', 'access_denied']]
                    ],
                    $options
                )->toArray();
            }
            if (!empty($configData)) {
                $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $commonHelper =  $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                $validShopIds = [];
                $validRemoteShopIds = [];
                $enableOrderSyncShops = [];
                foreach ($configData as $user) {
                    $this->di->getLog()->logContent('Starting process for user: ' . json_encode($user['user_id']), 'info', $logFile);
                    $response = $this->setDiForUser($user['user_id']);
                    if (isset($response['success']) && $response['success']) {
                        $shopData = $userDetails->getShop($user['target_shop_id'], $user['user_id']);
                        if (!empty($shopData)) {
                            if (isset($user['error_code'])) {
                                if ($user['error_code'] == 'InvalidInput') {
                                    $specifics = $this->prepareDummyFeedContent($user['user_id'], $shopData);
                                    $remoteResponse = $commonHelper->sendRequestToAmazon('inventory-upload', $specifics, 'POST');
                                    $marketplaceId = $shopData['warehouses'][0]['marketplace_id'];
                                    if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                                        if (!empty($remoteResponse['result'][$marketplaceId]) && !empty($remoteResponse['result'][$marketplaceId]['response']['success'])) {
                                            $validShopIds[] = $shopData['_id'];
                                            $validRemoteShopIds[$shopData['remote_shop_id']] = $user['error_code'];
                                            $lastSyncActionData[] = [
                                                'user_id' => $user['user_id'],
                                                'source_shop_id' => $user['source_shop_id'],
                                                'source_marketplace' => $user['source'],
                                                'target_shop_id' => $user['target_shop_id']
                                            ];
                                            $dataToSent['user_id'] = $user['user_id'];
                                            $dataToSent['target_marketplace'] = [
                                                'marketplace' => Helper::TARGET,
                                                'shop_id' => $user['target_shop_id']
                                            ];
                                            $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                                                ->createSubscription($dataToSent);
                                        } elseif (!empty($remoteResponse['result'][$marketplaceId]['response']['error'])
                                            && (str_contains($remoteResponse['result'][$marketplaceId]['response']['error'], 'Too Many Requests')) || str_contains($remoteResponse['result'][$marketplaceId]['response']['error'], 'Api limit exceed')) {
                                            $dataToSent['user_id'] = $user['user_id'];
                                            $dataToSent['target_marketplace'] = [
                                                'marketplace' => Helper::TARGET,
                                                'shop_id' => $user['target_shop_id']
                                            ];
                                            $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                                                ->createSubscription($dataToSent);
                                            $reAttemptData = [
                                                'user_id' => $user['user_id'],
                                                'shop_id' => $shopData['_id'],
                                                'error_code' => $user['error_code']
                                            ];
                                            // $this->retryAccountStatusHandler($reAttemptData);
                                            // $this->di->getLog()->logContent('Retry feed call process initiated for: ' . json_encode([
                                            //     'user_id' => $user['user_id'],
                                            //     'shop_id' => $user['target_shop_id']
                                            // ]), 'info', $logFile);
                                        }
                                    } else {
                                        $this->di->getLog()->logContent('Error from remote response: ' . json_encode($remoteResponse), 'info', $logFile);
                                    }
                                } elseif ($user['error_code'] == 'Unauthorized') {
                                    $remoteResponse = $commonHelper->sendRequestToAmazon('marketplace-participations', ['shop_id' => $shopData['remote_shop_id']], 'GET');
                                    if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                                        $validShopIds[] = $shopData['_id'];
                                        $validRemoteShopIds[$shopData['remote_shop_id']] = $user['error_code'];
                                        $enableOrderSyncShops[] = $shopData['remote_shop_id'];
                                    }
                                }
                            }
                        } else {
                            $this->di->getLog()->logContent('shop not found' . json_encode([
                                'user_id' => $user['user_id'],
                                'shop_id' => $user['target_shop_id']
                            ]), 'info', $logFile);
                        }
                    } else {
                        $this->di->getLog()->logContent('Unable to set di for user: ' . json_encode($user['user_id']), 'info', $logFile);
                    }
                }
                if (!empty($validShopIds)) {
                    $this->di->getLog()->logContent('Valid shopIds found:' . json_encode($validShopIds), 'info', $logFile);
                    $validShopIds = array_values(array_unique($validShopIds));
                    $marshaler = new Marshaler();
                    $config->updateMany(
                        [
                            'user_id' => ['$ne' => null],
                            'key' => 'accountStatus',
                            'group_code' => 'feedStatus',
                            'target' => Helper::TARGET,
                            'target_shop_id' => ['$in' => $validShopIds]
                        ],
                        [
                           '$set' => [
                                'value' => 'active',
                                'updated_at' => date('c')
                            ]
                        ]
                    );
                    $this->updateAllShopsWarehouseStatus($validShopIds, 'active');
                    $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
                    $dynamoClientObj = $dynamoObj->getDetails();
                    foreach ($validRemoteShopIds as $remoteShopId => $errorCode) {
                        $tableName = 'SPAPI_Error';
                        $dynamoClientObj->deleteItem([
                            'TableName' => $tableName,
                            'Key' => $marshaler->marshalItem([
                                'remote_shop_id'  => (string)$remoteShopId,
                                'error_code' => (string)$errorCode
                            ])
                        ]);
                    }
                    if (!empty($enableOrderSyncShops)) {
                        $this->di->getLog()->logContent('Enabling Order Syncing for these remoteShops:' . json_encode($enableOrderSyncShops), 'info', $logFile);
                        foreach ($enableOrderSyncShops as $remoteShopId) {
                            $tableName = 'user_details';
                            $dynamoData = $dynamoClientObj->getItem([
                                'TableName' => $tableName,
                                'Key' => $marshaler->marshalItem([
                                    'id' => (string)$remoteShopId,
                                ]),
                                'ConsistentRead' => true,
                            ]);
                            if (!empty($dynamoData) && isset($dynamoData['Item'])) {
                                $userData = $dynamoData['Item'];
                                $updateExpression = [];
                                $expressionAttributeValues = [];
                                if (isset($userData['order_fetch'])) {
                                    $updateExpression[] = 'order_fetch = :ofetch';
                                    $expressionAttributeValues[':ofetch'] = 1;
                                    $updateExpression[] = 'order_filter = :ofil';
                                    $expressionAttributeValues[':ofil'] = '{}';
                                }

                                if (isset($userData['fba_fetch'])) {
                                    $updateExpression[] = 'fba_fetch = :ffetch';
                                    $expressionAttributeValues[':ffetch'] = '1';
                                    $updateExpression[] = 'fba_filter = :ffil';
                                    $expressionAttributeValues[':ffil'] = '{}';
                                }
                                $expressionAttributeValues = $marshaler->marshalItem($expressionAttributeValues);
                                if (!empty($updateExpression)) {
                                    $updateExpressionString = 'SET ' . implode(', ', $updateExpression);
                                    $updateParams = [
                                        'TableName' => $tableName,
                                        'Key' => $marshaler->marshalItem([
                                            'id' => (string)$remoteShopId,
                                        ]),
                                        'UpdateExpression' => $updateExpressionString,
                                        'ExpressionAttributeValues' => $expressionAttributeValues
                                    ];
                                    $dynamoClientObj->updateItem($updateParams);
                                }
                            }
                        }
                    }
                }

                if(!empty($lastSyncActionData)) {
                    foreach($lastSyncActionData as $data) {
                        $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')
                        ->initiateLastFailedSyncAction($data['user_id'], $data['target_shop_id'], $data['source_marketplace']);
                    }
                }

                $endUser = end($configData);
                $userId = $endUser['user_id'];
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => \App\Amazon\Components\Shop\Hook::class,
                    'method' => 'checkAmazonAccountStatus',
                    'queue_name' => 'check_amazon_account_status',
                    'user_id' => $userId,
                    '_id' => $endUser['_id']
                ];
                $sqsHelper->pushMessage($handlerData);
                $message = 'Processing from Queue...';
            } else {
                $message = 'Process Completed!!';
            }
            return ['success' => true, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception:' . print_r($e, true), 'info', $logFile);
        }
    }

    /**
     * retryAccountStatusHandler function
     * Handler to retry feed status call
     * @param [array] $data
     * @return array
     */
    public function retryAccountStatusHandler($data)
    {
        $handlerData = [
            'type' => 'full_class',
            'method' => 'retryFeedStatusCall',
            'class_name' => '\App\Amazon\Components\Shop\Hook',
            'queue_name' => 'check_amazon_account_status',
            'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
            'shop_id' => $data['shop_id'],
            'data' => $data,
            'delay' => rand(600, 1200)
        ];

        if (!isset($data['retry'])) {
            $handlerData['retry'] = 1;
        } else {
            $handlerData['retry'] = $data['retry'] + 1;
        }
        $logFile = "amazon/retryAccountStatusHandler/" . date('d-m-Y') . '.log';
        if ($handlerData['retry'] > 3) {
            $this->di->getLog()->logContent('Max retry attempts passed:' . json_encode($data), 'info', $logFile);
            return ['success' => true, 'message' => 'Max retry attempts passed'];
        }
        $this->di->getLog()->logContent('Starting retry process for:' . json_encode($handlerData), 'info', $logFile);
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $sqsHelper->pushMessage($handlerData);
        return ['success' => true, 'message' => 'Retry account status handler executed.'];
    }

    /**
     * retryFeedStatusCall function
     * Retrying feed status call with a delay to remove banner and active
     * warehouse on success response
     * @param [array] $params
     * @return array
     */
    public function retryFeedStatusCall($params = [])
    {
        try {
            if (!empty($params['data'])) {
                $userId =  $this->di->getUser()->id ?? $params['user_id'];
                $targetShopId = $params['data']['shop_id'];
                $errorCode = $params['data']['error_code'] ?? '';
                if (!empty($targetShopId) && !empty($errorCode)) {
                    $isActive = false;
                    $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                    $shopData = $userDetails->getShop($targetShopId, $userId);
                    if (!empty($shopData)) {
                        $remoteShopId = $shopData['remote_shop_id'];
                        $commonHelper =  $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                        if ($errorCode == 'InvalidInput') {
                            $specifics = $this->prepareDummyFeedContent($userId, $shopData);
                            $remoteResponse = $commonHelper->sendRequestToAmazon('inventory-upload', $specifics, 'POST');
                            $marketplaceId = $shopData['warehouses'][0]['marketplace_id'];
                            if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                                if (!empty($remoteResponse['result'][$marketplaceId]) && !empty($remoteResponse['result'][$marketplaceId]['response']['success'])) {
                                    $isActive = true;
                                } elseif (
                                    !empty($remoteResponse['result'][$marketplaceId]['response']['error'])
                                    && (str_contains($remoteResponse['result'][$marketplaceId]['response']['error'], 'Many Requests')) || str_contains($remoteResponse['result'][$marketplaceId]['response']['error'], 'Api limit exceed')
                                ) {
                                    $reAttemptData = [
                                        'user_id' => $userId,
                                        'shop_id' => $shopData['_id'],
                                        'error_code' => $errorCode,
                                        'retry' => $params['retry']
                                    ];
                                    // $this->retryAccountStatusHandler($reAttemptData);
                                    return ['success' => true, 'message' => 'Retry account status handler executed.'];
                                }
                            }
                        }
                        if ($isActive) {
                            $configData = [
                                'user_id' => $userId,
                                'group_code' => 'feedStatus',
                                'key' => 'accountStatus',
                                'error_code' => $errorCode,
                                'target_shop_id' => $targetShopId,
                                'value' => 'active',
                                'app_tag' => $this->di->getAppCode()->getAppTag() ?? 'default'
                            ];
                            $configObj = $this->di->getObjectManager()
                                ->get(\App\Core\Models\Config\Config::class);
                            $configObj->setUserId($userId);
                            $configObj->setAppTag($configData['app_tag']);
                            $configObj->setConfig([$configData]);
                            $this->updateWarehouseStatus($userId, $targetShopId, 'active');
                            $marshaler = new Marshaler();
                            $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
                            $dynamoClientObj = $dynamoObj->getDetails();
                            $tableName = 'SPAPI_Error';
                            $dynamoClientObj->deleteItem([
                                'TableName' => $tableName,
                                'Key' => $marshaler->marshalItem([
                                    'remote_shop_id'  => (string)$remoteShopId,
                                    'error_code' => (string)$errorCode
                                ])
                            ]);
                            return ['success' => true, 'message' => 'Your account is now active.'];
                        } else {
                            $message = 'There’s an still some issue with your account. Please follow the steps provided in banner or try again later.';
                        }
                    } else {
                        $message = 'Shop not found.Please contact our support team for assistance.';
                    }
                } else {
                    $message = 'Required Params Missing,Something went wrong from our end.Please contact our support team for assistance.';
                }
                return ['success' => false, 'message' => $message ?? 'Something went wrong from our end.Please contact our support team for assistance.'];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from retryFeedStatusCall(), Error' . print_r($e), 'info', 'exception.log');
        }
    }

    /**
     * updateAllShopsWarehouseStatus function
     * Updating warehouses status of all shops
     * @param [array] $shopIds
     * @return array
     */
    public function updateAllShopsWarehouseStatus($shopIds, $status = 'active')
    {
        $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
        $userDetails = $mongo->getCollectionForTable('user_details');
        $userDetails->updateMany(
            [
                'shops' => [
                    '$elemMatch' => [
                        '_id' => ['$in' => $shopIds],
                        'marketplace' => Helper::TARGET
                    ]
                ]
            ],
            [
                '$set' => [
                    'shops.$[shop].warehouses.0.status' => $status
                ]
            ],
            [
                'arrayFilters' => [
                    [
                        'shop._id' => ['$in' => $shopIds],
                        'shop.marketplace' => Helper::TARGET
                    ]
                ]
            ]
        );
    }

    /**
     * validateAmazonAccountStatus function
     * Function for sellers to manually check
     * their account is still inactive or not.
     * @param [array] $params
     * @return array
     */
    public function validateAmazonAccountStatus($params = [])
    {
        try {
            $userId = $this->di->getUser()->id ?? $params['user_id'];
            $sourceShopId = $this->di->getRequester()->getSourceId()  ?? $params['source_shop_id'];
            $targetShopId = $this->di->getRequester()->getTargetId()  ?? $params['target_shop_id'];
            $sourceMarketplace = $this->di->getRequester()->getSourceName()  ?? $params['source_marketplace'];
            $errorCode = $params['error_code'] ?? '';
            if (!empty($sourceShopId) && !empty($targetShopId) && !empty($params['error_code'])) {
                $isActive = false;
                $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $shopData = $userDetails->getShop($targetShopId, $userId);
                if (!empty($shopData)) {
                    $remoteShopId = $shopData['remote_shop_id'];
                    $commonHelper =  $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                    if ($errorCode == 'InvalidInput') {
                        $specifics = $this->prepareDummyFeedContent($userId, $shopData);
                        $remoteResponse = $commonHelper->sendRequestToAmazon('inventory-upload', $specifics, 'POST');
                        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                            $marketplaceId = $shopData['warehouses'][0]['marketplace_id'];
                            if (!empty($remoteResponse['result'][$marketplaceId]) && !empty($remoteResponse['result'][$marketplaceId]['response']['success'])) {
                                $isActive = true;
                            }
                        }
                    } elseif ($errorCode == 'Unauthorized') {
                        $remoteResponse = $commonHelper->sendRequestToAmazon('marketplace-participations', ['shop_id' => $remoteShopId], 'GET');
                        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                            $isActive = true;
                            $enableOrderSync = true;
                        }
                    }
                    if ($isActive) {
                        $configData = [
                            'user_id' => $userId,
                            'group_code' => 'feedStatus',
                            'key' => 'accountStatus',
                            'error_code' => $errorCode,
                            'source_shop_id' => $sourceShopId,
                            'target_shop_id' => $targetShopId,
                            'value' => 'active',
                            'app_tag' => $this->di->getAppCode()->getAppTag() ?? 'default'
                        ];
                        $configObj = $this->di->getObjectManager()
                            ->get(\App\Core\Models\Config\Config::class);
                        $configObj->setUserId($userId);
                        $configObj->setAppTag($configData['app_tag']);
                        $configObj->setConfig([$configData]);
                        $this->updateWarehouseStatus($userId, $targetShopId, 'active');
                        $marshaler = new Marshaler();
                        $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
                        $dynamoClientObj = $dynamoObj->getDetails();
                        $tableName = 'SPAPI_Error';
                        $dynamoClientObj->deleteItem([
                            'TableName' => $tableName,
                            'Key' => $marshaler->marshalItem([
                                'remote_shop_id'  => (string)$remoteShopId,
                                'error_code' => (string)$errorCode
                            ])
                        ]);
                        if ($enableOrderSync) {
                            $tableName = 'user_details';
                            $dynamoData = $dynamoClientObj->getItem([
                                'TableName' => $tableName,
                                'Key' => $marshaler->marshalItem([
                                    'id' => (string)$remoteShopId,
                                ]),
                                'ConsistentRead' => true,
                            ]);
                            if (!empty($dynamoData) && isset($dynamoData['Item'])) {
                                $userData = $dynamoData['Item'];
                                $updateExpression = [];
                                $expressionAttributeValues = [];
                                if (isset($userData['order_fetch'])) {
                                    $updateExpression[] = 'order_fetch = :ofetch';
                                    $expressionAttributeValues[':ofetch'] = 1;
                                    $updateExpression[] = 'order_filter = :ofil';
                                    $expressionAttributeValues[':ofil'] = '{}';
                                }

                                if (isset($userData['fba_fetch'])) {
                                    $updateExpression[] = 'fba_fetch = :ffetch';
                                    $expressionAttributeValues[':ffetch'] = '1';
                                    $updateExpression[] = 'fba_filter = :ffil';
                                    $expressionAttributeValues[':ffil'] = '{}';
                                }
                                $expressionAttributeValues = $marshaler->marshalItem($expressionAttributeValues);
                                if (!empty($updateExpression)) {
                                    $updateExpressionString = 'SET ' . implode(', ', $updateExpression);
                                    $updateParams = [
                                        'TableName' => $tableName,
                                        'Key' => $marshaler->marshalItem([
                                            'id' => (string)$remoteShopId,
                                        ]),
                                        'UpdateExpression' => $updateExpressionString,
                                        'ExpressionAttributeValues' => $expressionAttributeValues
                                    ];
                                    $dynamoClientObj->updateItem($updateParams);
                                }
                            }
                        }
                        $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')
                        ->initiateLastFailedSyncAction($userId, $targetShopId, $sourceMarketplace);
                        $dataToSent['user_id'] = $userId;
                        $dataToSent['target_marketplace'] = [
                            'marketplace' => Helper::TARGET,
                            'shop_id' => $targetShopId
                        ];
                        $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                            ->createSubscription($dataToSent);
                        return ['success' => true, 'message' => 'Your account is now active.'];
                    } else {
                        $message = 'There’s an still some issue with your account. Please follow the steps provided in banner and try again.';
                    }
                } else {
                    $message = 'Shop not found.Please contact our support team for assistance.';
                }
            } else {
                $message = 'Required Params Missing,Something went wrong from our end.Please contact our support team for assistance.';
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong from our end.Please contact our support team for assistance.'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from validateAmazonAccountStatus(), Error' . print_r($e), 'info', 'exception.log');
        }
    }

    /**
     * prepareDummyFeedContent function
     * Preparing dummy feed content to check feed API
     * @param [string] $userId
     * @param [array] $shop
     * @return array
     */
    private function prepareDummyFeedContent($userId, $shop)
    {
        $dummyInventoryFeedContent = [
            "DummyCedId123" => [
                "Id" => "1",
                "SKU" => "DummyTestCed@$123",
                "Quantity" => 0,
                "Latency" => "2"
            ]
        ];
        return [
            "ids" => [01230321],
            "homeShopId" => $shop['_id'],
            "shop_id" => $shop['remote_shop_id'],
            "user_id" => $userId,
            "feedContent" => base64_encode(json_encode($dummyInventoryFeedContent)),
            "marketplace_id" => $shop['warehouses'][0]['marketplace_id'],
            "admin" => true
        ];
    }

    /**
     * updateWarehouseStatus function
     * Updating warehouse status
     * @param [string] $userId
     * @param [string] $shopId
     * @param [string] $status (optional)
     * @return array
     */
    public function updateWarehouseStatus($userId, $shopId, $status = 'active')
    {
        if (!empty($userId) && !empty($shopId)) {
            $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
            $userDetails = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
            $userDetails->updateOne(
                [
                    'user_id' => $userId,
                    'shops' => [
                        '$elemMatch' => [
                            '_id' => (string)$shopId,
                            'marketplace' => Helper::TARGET,
                            'apps.app_status' => 'active'
                        ]
                    ]
                ],
                [
                    '$set' => [
                        'shops.$[shop].warehouses.0.status' => $status
                    ]
                ],
                [
                    'arrayFilters' => [
                        [
                            'shop._id' => $shopId,
                            'shop.marketplace' => Helper::TARGET,
                            'shop.apps.app_status' => 'active'
                        ]
                    ]
                ]
            );
        }
    }

    /**
     * setDiForUser function
     * Setting user in Di
     * @param [array] $userId
     * @return array
     */
    private function setDiForUser($userId)
    {
        try {
            $getUser = \App\Core\Models\User::findFirst([['_id' => $userId]]);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found.'
            ];
        }
        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin.'
            ];
        }
        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }

    /**
     * moveShopToPriorityInOrderSyncTable function  
     * Moves a shop to the priority order sync DynamoDB table and disables syncing from the global user_details table.
     *  
     * @param [array] $params Associative array containing:
     *                        - 'shop_id' (string|int): Required shop ID
     *                        - 'order_fetch_interval' (int|string): Fetch interval to set
     *                        - 'user_id' (string|int, optional): Optional user ID to store
     * @return void
     */
    public function moveShopToPriorityInOrderSyncTable($params)
    {
        try {
            if (isset($params['shop_id'], $params['order_fetch_interval'])) {
                $userId = $params['user_id'];
                $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $shop = $userDetails->getShop($params['shop_id'], $userId);
                $priorityUserTable = 'amazon_order_sync_priority_users';
                $globalTable = 'user_details';
                if (!empty($shop)) {
                    $remoteShopId = $shop['remote_shop_id'];
                    $marshaler = new Marshaler();
                    $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
                    $dynamoClientObj = $dynamoObj->getDetails();
                    $dynamoData = $dynamoClientObj->getItem([
                        'TableName' => $priorityUserTable,
                        'Key' => $marshaler->marshalItem([
                            'id' => (string)$remoteShopId,
                        ]),
                        'ConsistentRead' => true,
                    ]);
                    if(empty($dynamoData['Item'])) {
                        $dynamoData = $dynamoClientObj->getItem([
                            'TableName' => $globalTable,
                            'Key' => $marshaler->marshalItem([
                                'id' => (string)$remoteShopId,
                            ]),
                            'ConsistentRead' => true,
                        ]);
                        if (!empty($dynamoData['Item'])) {
                            //Moving shop in priority order sync table
                            $dynamoData['Item']['order_fetch_interval'] = ['S' => (string) $params['order_fetch_interval']];
                            $dynamoData['Item']['order_fetch']['N'] = '1';
                            $insert = [
                                'TableName' => $priorityUserTable,
                                'Item' => $dynamoData['Item']
                            ];
                            $dynamoClientObj->putItem($insert);
                            //Adding is_priority_user key to disable order_sync from global table
                            $updateExpression[] = 'is_priority_user = :is_priority_user';
                            $expressionAttributeValues[':is_priority_user'] = 1;
                            $expressionAttributeValues = $marshaler->marshalItem($expressionAttributeValues);
                            if (!empty($updateExpression)) {
                                $updateExpressionString = 'SET ' . implode(', ', $updateExpression);
                                $updateParams = [
                                    'TableName' => $globalTable,
                                    'Key' => $marshaler->marshalItem([
                                        'id' => (string)$remoteShopId,
                                    ]),
                                    'UpdateExpression' => $updateExpressionString,
                                    'ExpressionAttributeValues' => $expressionAttributeValues
                                ];
                                $dynamoClientObj->updateItem($updateParams);
                            }
                            return ['success' => true, 'message' => 'Shop moved successfully in priority order sync table'];
                        } else {
                            $message = 'User not available in user_details';
                        }
                    } else {
                        $message = 'User already present in priority user table';
                    }
                } else {
                    $message = 'Shop not found';
                }
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from moveShopToPriorityInOrderSyncTable(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
            throw $e;
        }
    }

    /**
     * fetchPriorityUsersFromOrderSyncPriorityTable function  
     * Fetches all users from the priority order sync table with selected fields.
     *
     * @return [array] List of users with 'user_id', 'username', and 'shops' fields
     */
    public function fetchPriorityUsersFromSyncTable($params = [])
    {
        try {
            $priorityUserTable = 'amazon_order_sync_priority_users';
            $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
            $dynamoClientObj = $dynamoObj->getDetails();

            $limit = !empty($params['limit']) ? (int)$params['limit'] : 10;
            $lastEvaluatedKey = $params['lastEvaluatedKey'] ?? null;

            $scanParams = [
                'TableName' => $priorityUserTable,
                'Limit' => $limit
            ];
            if (!is_null($lastEvaluatedKey)) {
                $scanParams['ExclusiveStartKey'] = $lastEvaluatedKey;
            }
            $scanData = $dynamoClientObj->scan($scanParams);
            $scanData = $this->convertToArray($scanData);
            if (empty($scanData['Items'])) {
                return ['success' => false, 'message' => "No priority users present in $priorityUserTable table"];
            }

            $dynamoMoldData = [];
            foreach ($scanData['Items'] as $data) {
                $dynamoMoldData[$data['id']] = $data;
            }

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetails = $mongo->getCollectionForTable('user_details');
            $remoteShopIds = array_column($scanData['Items'], 'id');

            $pipeline = [
                [
                    '$match' => [
                        'shops.remote_shop_id' => ['$in' => $remoteShopIds]
                    ]
                ],
                [
                    '$project' => [
                        '_id' => 0,
                        'user_id' => 1,
                        'username' => 1,
                        'shops' => [
                            '$filter' => [
                                'input' => '$shops',
                                'as' => 'shop',
                                'cond' => ['$in' => ['$$shop.remote_shop_id', $remoteShopIds]]
                            ]
                        ]
                    ]
                ]
            ];

            $userData = $userDetails->aggregate($pipeline, [
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ])->toArray();

            if (empty($userData)) {
                return ['success' => false, 'message' => 'No user data found'];
            }

            $preparedData = [];
            foreach ($userData as $data) {
                foreach ($data['shops'] as $shopData) {
                    $preparedData[] = [
                        'user_id' => $data['user_id'],
                        'username' => $data['username'],
                        'shop_id' => $shopData['_id'],
                        'seller_id' => $shopData['warehouses'][0]['seller_id'] ?? null,
                        'marketplace_name' => Helper::MARKETPLACE_NAME[$shopData['warehouses'][0]['marketplace_id']] ?? null,
                        'order_fetch_interval' => $dynamoMoldData[$shopData['remote_shop_id']]['order_fetch_interval'] ?? null
                    ];
                }
            }

            $preparedData = [
                'success' => true,
                'message' => 'Data fetched successfully',
                'data' => $preparedData,
                'pagination' => [
                    'limit' => $limit,
                    'lastEvaluatedKey' => $scanData['LastEvaluatedKey'] ?? null
                ]
            ];
            if (isset($params['totalCount'])) {
                $scanParams = [
                    'TableName' => $priorityUserTable
                ];
                $scanData = $dynamoClientObj->scan($scanParams);
                $scanData = $this->convertToArray($scanData);
                $totalDocuments = count($scanData['Items']);
                $preparedData['totalCount'] = $totalDocuments;
            }

            return $preparedData;
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                'Exception from fetchPriorityUsersFromSyncTable(): ' . json_encode($e->getMessage()),
                'info',
                'exception.log'
            );
            throw $e;
        }
    }

    /**
     * unmarkShopAsPriorityAndEnableNormalSync function
     * Removes a shop from the priority order sync table and restores its data in the global user_details table.
     *
     * @param [array] $params Associative array containing:
     * @return [array] Returns an array with:
     */
    public function unmarkShopAsPriorityAndEnableNormalSync($params)
    {
        try {
            if (isset($params['user_id'], $params['shop_id'])) {
                $userId = $params['user_id'];
                $marshaler = new Marshaler();
                $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
                $dynamoClientObj = $dynamoObj->getDetails();
                $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $shopData = $userDetails->getShop($params['shop_id'], $userId);
                if (isset($shopData['remote_shop_id'])) {
                    $priorityUserTable = 'amazon_order_sync_priority_users';
                    $globalTable = 'user_details';
                    $priorityTableData = $dynamoClientObj->getItem([
                        'TableName' => $priorityUserTable,
                        'Key' => $marshaler->marshalItem([
                            'id' => (string)$shopData['remote_shop_id'],
                        ]),
                        'ConsistentRead' => true,
                    ]);
                    if(!empty($priorityTableData['Item'])) {
                        $itemData = $priorityTableData['Item'];
                        unset($itemData['order_fetch_interval']);
                        $dynamoClientObj->deleteItem([
                            'TableName' => $priorityUserTable,
                            'Key' => $marshaler->marshalItem([
                                'id'  => (string)$shopData['remote_shop_id']
                            ])
                        ]);
                        $globalTableData = $dynamoClientObj->getItem([
                            'TableName' => $globalTable,
                            'Key' => $marshaler->marshalItem([
                                'id' => (string)$shopData['remote_shop_id'],
                            ]),
                            'ConsistentRead' => true,
                        ]);
                        if (!empty($globalTableData['Item'])) {
                            $insert = [
                                'TableName' => $globalTable,
                                'Item' => $itemData
                            ];
                            $dynamoClientObj->putItem($insert);
                            return ['success' => true, 'message' => 'Shop removed from priority sync. Normal order syncing has been re-enabled'];
                        } else {
                            $message = 'Shop not present in global (user_details) table so, but removed from priority user table';
                        }
                    } else {
                        $message = 'Particular shop not present in priority order sync table';
                    }
                } else {
                    $message = 'Remote_shop not set unable to remove shop from priority list';
                }
            } else {
                $message = 'Required params missing (user_id, shop_id)';
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from unmarkShopAsPriorityAndEnableNormalSync(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
            throw $e;
        }
    }

    /**
     * convertToArray function  
     * Converts marshaled DynamoDB items into PHP arrays using the AWS Marshaler.
     *  
     * @param [array] $connectionData DynamoDB response containing 'Items' in marshaled format
     * @return [array] $connectionData Modified array with 'Items' converted to PHP arrays
     */
    private function convertToArray($connectionData)
    {
        $marshalerObj = new Marshaler();
        $items = [];
        foreach ($connectionData['Items'] as $item) {
            $items[] = $marshalerObj->unmarshalItem($item);
        }
        $connectionData['Items'] = $items;
        return $connectionData;
    }
}
