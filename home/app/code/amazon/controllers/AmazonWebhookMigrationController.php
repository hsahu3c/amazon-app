<?php

namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Components\Common\Helper;
use App\Core\Models\User;
use Exception;
class AmazonWebhookMigrationController extends BaseController
{
    public $sqsWebhook, $processedRegions,
        $logFile, $preparedDestinationData,
        $accessToken, $destinationData;


    public function reregisterAmazonWebhookAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('user_details');
        $commonHelper = $this->di->getObjectManager()
            ->get(Helper::class);
        $destinationCollection = $mongo->getCollectionForTable('destination_data');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $marketplace = 'amazon';
        $this->logFile = "amazonRegisterWebhook/script/" . date('d-m-Y') . '.log';
        $webhookMigration = $mongo->getCollectionForTable('amazon_webhook_migration');
        $migratedUsersData = $webhookMigration->find(
            ['user_id' => ['$ne' => null]],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        )->toArray();
        $this->processedRegions = [];
        $this->preparedDestinationData = [];
        $errors = [];
        $offset = 0;
        if (!empty($migratedUsersData)) {
            $offset = count($migratedUsersData);
        }

        $limit = $rawBody['limit'] ?? 500;
        if (!empty($rawBody['betaSellers'])) {
            $pipline = [
                [
                    '$match' => [
                        'user_id' => [
                            '$in' => $rawBody['betaSellers']
                        ],
                        'shops' => [
                            '$elemMatch' => [
                                'apps.app_status' => 'active',
                                'marketplace' => $marketplace
                            ],
                        ],
                    ]
                ],
                ['$project' => ['user_id' => 1, 'username' => 1]]
            ];
        } else {
            $pipline = [
                [
                    '$match' => [
                        'shops' => [
                            '$elemMatch' => [
                                'apps.app_status' => 'active',
                                'marketplace' => $marketplace
                            ],
                        ],
                    ]
                ],
                ['$project' => ['user_id' => 1, 'username' => 1]],
                ['$skip' => $offset],
                ['$limit' => $limit]
            ];
        }

        $userData = $query->aggregate($pipline, $arrayParams)->toArray();
        if (!empty($userData)) {
            $this->sqsWebhook = $this->di->getConfig()->get('amazon_sqs_webhook');
            if (!isset($this->sqsWebhook)) {
                $this->di->getLog()->logContent("Amazon Webhooks not defined ", 'info', $this->logFile);
                return ['success' => false, 'message' => 'Amazon Webhooks not defined'];
            }

            $this->sqsWebhook = $this->sqsWebhook->toArray();
            $destinationData = $destinationCollection->find(['event_code' => ['$ne' => null]]);
            if (!empty($destinationData)) {
                foreach ($destinationData as $data) {
                    $this->preparedDestinationData[$data['region']][$data['event_code']] = $data['destination_id'];
                }
            }

            foreach ($userData as $user) {
                $this->processedRegions = [];
                $response = $this->setDiForUser($user['user_id']);
                if (isset($response['success']) && $response['success']) {
                    $shops = $this->di->getUser()->shops;
                    if (!empty($shops) && count($shops) > 1) {
                        foreach ($shops as $shopData) {
                            $this->accessToken = '';
                            if (
                                isset(
                                    $shopData['marketplace'],
                                    $shopData['apps'],
                                    $shopData['remote_shop_id']
                                ) && $shopData['marketplace'] == $marketplace &&
                                $shopData['apps'][0]['app_status'] == 'active' && !empty($shopData['warehouses'])
                            ) {
                                $remoteResponse = $commonHelper->sendRequestToAmazon(
                                    'access-token',
                                    ['shop_id' => $shopData['_id']],
                                    'GET'
                                );
                                if (isset($remoteResponse['success']) && $remoteResponse['success'] && !empty($remoteResponse['response'])) {
                                    $appData = $remoteResponse['response']['apps'][0] ?? '';
                                    if (!empty($appData)) {
                                        $this->accessToken = $appData['access_token'];
                                    } else {
                                        $this->di->getLog()->logContent("Empty appData of remoteShopId: " . $shopData['remote_shop_id'], 'info', $this->logFile);
                                        $errors[] = "Empty appData of remoteShopId: " . $shopData['remote_shop_id'];
                                    }
                                }

                                if (!empty($this->accessToken)) {
                                    $warehouseData = $shopData['warehouses'][0];
                                    if (!empty($warehouseData['region'])) {
                                        $response = $this->initiateProcess($shopData, $warehouseData['region']);
                                        if (isset($response['success']) && $response['success'] && !empty($response['data'])) {
                                            $this->insertAndUpdateDocs($shopData, $warehouseData['region'], $response['data'][0]);
                                        } else {
                                            $this->di->getLog()->logContent("Error from initiateProcess response: " . json_encode($response), 'info', $this->logFile);
                                            $errors[] = $response['data'];
                                        }
                                    }
                                } else {
                                    $this->di->getLog()->logContent("Unable to fetch access token of remoteShopId: " . $shopData['remote_shop_id'], 'info', $this->logFile);
                                    $errors[] = "Unable to fetch access token of remoteShopId: " . $shopData['remote_shop_id'];
                                }
                            } else {
                                //log
                            }
                        }
                    }
                }

                $this->insertCompletedUsers($user, $errors);
            }
        } else {
            $this->di->getLog()->logContent("All Users Processed", 'info', $this->logFile);
        }
    }

    public function initiateProcess($shopData, $region)
    {
        $destinationId = '';
        $prepareSubscriptionData = [];
        $subscriptionResponse = [];
        $deleteSubscriptionResponse = $this->deleteSubscription($shopData);
        if (isset($deleteSubscriptionResponse['success']) && $deleteSubscriptionResponse['success']) {
            if (!array_key_exists($region, $this->processedRegions)) {
                foreach ($this->sqsWebhook as $webhook) {
                    if (!empty($this->preparedDestinationData)) {
                        $regionDestination = $this->preparedDestinationData[$region];
                        if (!empty($regionDestination) && isset($regionDestination[$webhook])) {
                            $destinationId = $regionDestination[$webhook];
                        }
                    }

                    if (!empty($destinationId)) {
                        $subscriptionResponse = $this->createAmazonSubscription($webhook, $region, $destinationId);
                        if (isset($subscriptionResponse['success']) && $subscriptionResponse['success']) {
                            $this->processedRegions[$region][] = [
                                'code' => $webhook,
                                'marketplace_subscription_id' => $subscriptionResponse['data']['subscriptionId']
                            ];
                        } else {
                            $this->di->getLog()->logContent("Error from Creating {$webhook} Subscription API Response: " . json_encode($subscriptionResponse), 'info', $this->logFile);
                        }
                    }
                }
            }

            if (!empty($this->processedRegions[$region])) {
                $prepareSubscriptionData[] = $this->processedRegions[$region];
            }
        }

        if (!empty($prepareSubscriptionData)) {
            return ['success' => true, 'data' => $prepareSubscriptionData];
        }

        return ['success' => false, 'data' => $subscriptionResponse];
    }

    public function createAmazonSubscription($eventCode, $region, $destinationId)
    {
        $region = strtolower((string) $region);
        $curl = curl_init();
        $parameters = [
            CURLOPT_URL => "https://sellingpartnerapi-{$region}.amazon.com/notifications/v1/subscriptions/{$eventCode}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
                "x-amz-access-token: $this->accessToken",
                'Content-Type: application/json'
            ],
        ];
        if ($eventCode == 'ORDER_CHANGE') {
            $parameters[CURLOPT_POSTFIELDS] = "{
                'payloadVersion': '1.0',
                'destinationId': {$destinationId},
                'processingDirective': {
                        'eventFilter': {
                            'eventFilterType': {$eventCode}
                        }
                }
            }";
        } else {
            $parameters[CURLOPT_POSTFIELDS] = "{
                'payloadVersion': '1.0',
                'destinationId': {$destinationId},
            }";
        }

        curl_setopt_array($curl, $parameters);
        $response = json_decode(curl_exec($curl), true);
        curl_close($curl);
        if (!empty($response['payload'])) {
            return ['success' => true, 'data' => $response['payload']];
        }
        return ['success' => false, 'data' => $response];
    }

    public function deleteSubscription($shopData)
    {
        if (!empty($shopData['apps'][0]['webhooks'])) {
            $appWebhooks = $shopData['apps'][0]['webhooks'];
            foreach ($appWebhooks as $appWebData) {
                if (in_array($appWebData['code'], $this->sqsWebhook)) {
                    $dynamoWebhookIds[] = $appWebData['dynamo_webhook_id'];
                }
            }

            if (!empty($dynamoWebhookIds)) {
                $remoteData = [
                    'shop_id' => $shopData['remote_shop_id'],
                    'subscription_ids' => $dynamoWebhookIds,
                    'app_code' => "cedcommerce"
                ];
                $remoteResponse = $this->di->getObjectManager()
                    ->get("\App\Connector\Components\ApiClient")
                    ->init('cedcommerce', true)
                    ->call('bulkDelete/subscription', [], $remoteData, 'DELETE');
                if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                    return ['success' => true, 'message' => 'Data Deleted from all tables'];
                }
                $this->di->getLog()->logContent('ERROR While Deleting Data from Tables remoteResponse: ' . json_encode($remoteResponse, true), 'info', $this->logFile);

                return ['success' => false, 'message' => 'Something Went Wrong'];
            }
        }
    }

    public function insertAndUpdateDocs($shopData, $region, $subscriptionData)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $userDetails = $mongo->getCollection("user_details");
        $this->destinationData = $this->di->getConfig()->get('destination_data');
        if (empty($this->destinationData)) {
            return ['success' => false, 'message' => 'Destination Data not found'];
        }

        $this->destinationData = ($this->destinationData)->toArray();
        $queueAppCode = $this->destinationData['app_code'] ?? 'default';
        $marketplace = 'amazon';
        $appCode = $shopData['apps'][0]['code'];
        foreach ($subscriptionData as $data) {
            $prepareCifSubscriptionData[] = [
                "marketplace" => $marketplace,
                "remote_shop_id" => $shopData['remote_shop_id'],
                "subscription_id" => (string)$data['marketplace_subscription_id'],
                "event_code" => $data['code'],
                "destination_id" => $this->destinationData['user'] ?? '',
                "type" => 'user',
                "marketplace_data" => [],
                "queue_data" => [
                    "type" => "full_class",
                    "class_name" => "\\App\\Connector\\Models\\SourceModel",
                    "method" => "triggerWebhooks",
                    "user_id" => $this->di->getUser()->id,
                    "shop_id" => $shopData['_id'],
                    "action" => $data['code'],
                    "queue_name" => $queueAppCode . '_' . $data['code'],
                    "marketplace" => $marketplace,
                    "app_code" => $appCode
                ],
            ];
            $prepareMarketplaceSubscriptionData[] = [
                "marketplace" => "amazon",
                "event_code" => $data['code'],
                "remote_shop_id" => $shopData['remote_shop_id'],
                "destination_id" => $this->preparedDestinationData[$region][$data['code']] ?? '',
                "marketplace_subscription_id" => (string)$data['marketplace_subscription_id'],
                "created_at" => date('Y-m-d H:i:s')
            ];
        }

        $requestParams = [
            'shop_id' => $shopData['remote_shop_id'],
            'app_code' => $appCode,
            'cif_data' => $prepareCifSubscriptionData,
            'marketplace_data' => $prepareMarketplaceSubscriptionData
        ];
        $helper = $this->di->getObjectManager()->get(Helper::class);
        $cedData = $helper->sendRequestToAmazon('cedwebapi/data', $requestParams, 'POST');
        if (!empty($cedData['data'])) {
            $userWebhookData = $cedData['data'];
            $options = [
                'arrayFilters' => [
                    ['shop.marketplace' => $shopData['marketplace']]
                ]
            ];
            $appWebhooks = $shopData['apps'][0]['webhooks'];
            if (!empty($appWebhooks)) {
                foreach ($appWebhooks as $appWebData) {
                    if (in_array($appWebData['code'], $this->sqsWebhook)) {
                        $dynamoWebhookIds[] = $appWebData['dynamo_webhook_id'];
                    }
                }

                if (!empty($dynamoWebhookIds)) {

                    $userDetails->updateOne(
                        [
                            "user_id" => $this->di->getUser()->id,
                            "shops" => [
                                '$elemMatch' => [
                                    '_id' => $shopData['_id'],
                                    'marketplace' => $shopData['marketplace']
                                ]
                            ]
                        ],
                        [
                            '$pull' => [
                                'shops.$[shop].apps.0.webhooks' => [
                                    'dynamo_webhook_id' => [
                                        '$in' => $dynamoWebhookIds
                                    ]
                                ]
                            ]
                        ],
                        $options
                    );
                }
            }

            $userDetails->updateOne(
                [
                    "user_id" => $this->di->getUser()->id,
                    "shops" => [
                        '$elemMatch' => [
                            '_id' => $shopData['_id'],
                            'marketplace' => $shopData['marketplace']
                        ]
                    ]
                ],
                [
                    '$addToSet' => [
                        'shops.$[shop].apps.0.webhooks' => [
                            '$each' => $userWebhookData
                        ]
                    ]
                ],
                $options
            );
        }
    }

    public function insertCompletedUsers($user, $errors): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $webhookMigration = $mongo->getCollectionForTable('amazon_webhook_migration');
        $prepareData = [
            'user_id' => $user['user_id'],
            'username' => $user['username']
        ];
        if (!empty($this->processedRegions)) {
            $prepareData['subscription_data'] = $this->processedRegions;
        }

        if (!empty($errors)) {
            $prepareData['errors'] = $errors;
        }

        $webhookMigration->insertOne($prepareData);
    }

    public function setDiForUser($user_id)
    {
        try {
            $getUser = User::findFirst([['_id' => $user_id]]);
        } catch (Exception $e) {
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
}
