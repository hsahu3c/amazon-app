<?php

namespace App\Amazon\Components\Notification;

use Exception;
use App\Core\Components\Base;
use App\Amazon\Components\Common\Helper;

class Notification extends Base
{
    private ?string $userId = null;


    private $targetMarketplace;

    private $targetShop;

    public function init($request = [])
    {

        if (isset($request['target_marketplace'])) {
            $this->targetMarketplace = $request['target_marketplace']['marketplace'];
            $this->targetShop = $request['target_marketplace']['shop_id'];
        }

        if (isset($request['user_id'])) {
            $this->userId = (string)$request['user_id'];
        } else {
            $this->userId = (string)$this->di->getUser()->id;
        }

        return $this;
    }

    public function createSubscription()
    {
        try {
            $date = date('d-m-Y');
            $logFile = "amazon/Notification/{$date}.log";
            $shop = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')->getShop($this->targetShop, $this->userId, true);
            $destinationId = '';
            $message = 'Registering Webhooks...';
            if (!empty($shop)) {
                $this->di->getLog()->logContent('Starting Process for UserId => ' . json_encode($this->userId), 'info', $logFile);
                $awsClient = require BP . '/app/etc/aws.php';
                $appCode = $shop['apps'][0]['code'] ?? 'default';
                $marketplace = $this->targetMarketplace;
                if ($shop['warehouses'][0]['status'] && $shop['warehouses'][0]['status'] == "active") {
                    $data = ['title' => 'User type Event Destination', 'type' => 'user', 'event_handler' => 'sqs', 'destination_data' => [
                        'type' => 'sqs',
                        "sqs" => [
                            "region" => $awsClient['region'],
                            "key" => $awsClient['credentials']['key'] ?? null,
                            "secret" => $awsClient['credentials']['secret'] ?? null,
                            'queue_base_url' => "https://sqs." . $awsClient['region'] . ".amazonaws.com/" . $awsClient['account_id'] . "/"
                        ]
                    ]];
                    $destinationResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init('cedcommerce', false)
                        ->call('event/destination', [], ['shop_id' => $shop['remote_shop_id'], 'data' => $data, 'app_code' => "cedcommerce"], 'POST');
                    $destinationId = $destinationResponse['success'] ? $destinationResponse['destination_id'] : '';
                    if (!empty($destinationId)) {
                        // Check to bypass getSubscription call
                        if(!$this->checkSameRegionAccountConnected($shop)) {
                            $shop['is_new_account_connection'] = true;
                        }
                        $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks($shop, $marketplace, $appCode, $destinationId);
                        $this->di->getLog()->logContent('createWebhookRequest Response=> ' . json_encode($createWebhookRequest), 'info', $logFile);
                        return ['success' => true, 'message' => $message];
                    }
                    $message = 'Unable to create/get Destination Id';
                    $this->di->getLog()->logContent($message, 'info', $logFile);
                }
            } else {
                $message = 'Shop details not found';
                $this->di->getLog()->logContent($message . 'UserId =>' . json_encode($this->userId), 'info', $logFile);
            }

            return ['success' => false, 'message' => $message];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from enableOrderFetch => '
            . json_encode($e->getMessage()), 'emergency', 'exception.log');
        }
    }

    private function checkSameRegionAccountConnected($shop)
    {
        if (!empty($shop['warehouses'])) {
            $region = $shop['warehouses'][0]['region'];
            $sellerId = $shop['warehouses'][0]['seller_id'];
            $uniqueKey = $region . '_' . $sellerId;
        }
        $shops = $this->di->getUser()->shops ?? [];
        if (!empty($shops)) {
            foreach ($shops as $shopData) {
                if (
                    $shopData['marketplace'] == Helper::TARGET
                    && $shopData['_id'] != $shop['_id']
                    && $shopData['apps'][0]['app_status'] == 'active'
                ) {
                    $existingKey = $shopData['warehouses'][0]['region'] . '_' . $shopData['warehouses'][0]['seller_id'];
                    if ($uniqueKey == $existingKey) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function registerSpecificWebhookByCode($userId, $shop, $webhookCodes): array
    {

        $logFile = "amazon/Notification/" . date('d-m-Y') . '.log';
        if (!empty($webhookCodes)) {
            $this->di->getLog()->logContent('Starting registerSpecificWebhookByCode Process for userId => ' . json_encode([
                'user_id' => $userId,
                'webhookCodes' => $webhookCodes
            ]), 'info', $logFile);
            $destinationWise = [];
            $awsClient = require BP . '/app/etc/aws.php';
            $eventDestinationData = [
                'title' => 'User type Event Destination',
                'type' => 'user',
                'event_handler' => 'sqs',
                'destination_data' => [
                    'type' => 'sqs',
                    "sqs" => [
                        "region" => $awsClient['region'],
                        "key" => $awsClient['credentials']['key'] ?? null,
                        "secret" => $awsClient['credentials']['secret'] ?? null,
                        'queue_base_url' => "https://sqs." . $awsClient['region']
                            . ".amazonaws.com/" . $awsClient['account_id'] . "/"
                    ]
                ]
            ];

            if ($shop['apps'][0]['app_status'] == 'active' && $shop['warehouses'][0]['status'] == 'active') {
                $appCode = $shop['apps'][0]['code'] ?? 'default';
                $remoteResponse = $this->di->getObjectManager()
                    ->get("\App\Connector\Components\ApiClient")
                    ->init('cedcommerce', false)
                    ->call('event/destination', [], [
                        'shop_id' => $shop['remote_shop_id'],
                        'data' => $eventDestinationData,
                        'app_code' => "cedcommerce"
                    ], 'POST');
                $destinationId = $remoteResponse['success']
                    ? $remoteResponse['destination_id'] : false;

                if ($destinationId) {
                    $destinationWise[] = [
                        'destination_id' => $destinationId,
                        'event_codes' =>  array_values($webhookCodes)
                    ];

                    if (!$this->checkSameRegionAccountConnected($shop)) {
                        $shop['is_new_account_connection'] = true;
                    }

                    $createWebhookRequest = $this->di->getObjectManager()
                        ->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks(
                            $shop,
                            $shop['marketplace'],
                            $appCode,
                            false,
                            $destinationWise
                        );
                    $this->di->getLog()->logContent('Create webhook request response: '
                        . json_encode($createWebhookRequest, true), 'info', $logFile);
                    return ['success' => true, 'message' => 'Registering Webhooks...'];
                } else {
                    $message = $remoteResponse;
                }
            } else {
                $message = 'Shop or warehouse inactive';
            }
        } else {
            $message = 'Webhook codes cannot be left blank';
        }

        $this->di->getLog()->logContent('Unable to register webhook by code => ' . json_encode($message ?? 'Something went wrong'), 'info', $logFile);

        return ['success' => false, 'message' => $message ?? 'Something went Wrong'];
    }
}
