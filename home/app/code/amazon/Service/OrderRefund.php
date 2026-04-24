<?php

/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Amazon\Service;

use App\Core\Models\User;
use Exception;
use DateTime;
use DateTimeZone;
use App\Connector\Contracts\Sales\Order\RefundInterface;

/**
 * Interface RefundInterface
 * @services
 */
#[\AllowDynamicProperties]
class OrderRefund implements RefundInterface
{
    public const SCHEMA_VERSION = '2.0';

    public $settings_enabled = true;

    public $processManual = false;

    public $settings_data = null;

    public const FEED_CONTAINER = 'feed_container';

    public const ORDER_CONTAINER = 'order_container';

    public const AMZ_ALLOWED_REFUND_REASONS = [
        "NoInventory",
        "CustomerReturn",
        "GeneralAdjustment",
        "CouldNotShip",
        "DifferentItem",
        "Abandoned",
        "CustomerCancel",
        "PriceError",
        "ProductOutofStock",
        "CustomerAddressIncorrect",
        "Exchange",
        "Other",
        "CarrierCreditDecision",
        "RiskAssessmentInformationNotValid",
        "CarrierCoverageFailure",
        "TransactionRecord",
        "Undeliverable",
        "RefusedDelivery"
    ];

    public function refund($data): array
    {
        if (empty($data)) {
            return ['success' => false, 'message' => 'Data not found in amazon order refund service!'];
        }

        if (!isset($data['marketplace_shop_id'])) {
            return ['success' => false, 'data' => $data, 'message' => 'marketplace_shop_id not found!', 'isFeedBased' => true];
        }

        $adjustValidationResponse = $this->isAdjustmentValid($data);

        //in future deduct adjust amount from refund amount if adjustment is valid

        if (!$adjustValidationResponse['success']) {
            $this->tempLog($data, $type = 'adjustment-bifurcation-error');
            return ['success' => false, 'data' => $data, 'message' => $adjustValidationResponse['message'], 'isFeedBased' => true];
        }

        //validating the settings
        $settingsRes = $this->checkSettings($data);
        if (isset($settingsRes['success']) && $settingsRes['success'] == false) {
            return ['success' => false, 'data' => $data, 'message' => $settingsRes['message'], 'isFeedBased' => true];
        }

        $feedContent = $this->createFeedContent($data);
        if (!$feedContent['success']) {
            return ['success' => false, 'data' => $data, 'message' => $feedContent['message'], 'isFeedBased' => true];
        }

        $shopId = $data['marketplace_shop_id'];
        $shop = $this->getShopDetails($shopId);
        if (empty($shop)) {
            return ['success' => false, 'data' => $data, 'message' => 'Shop not found!', 'isFeedBased' => true];
        }

        $this->tempLog(json_encode($feedContent['data']), 'feed_data_json');

        $params = ['shop_id' => $shopId, 'feedContent' => $feedContent['data'], 'admin' => true];

        $this->tempLog(json_encode(['shop_id' => $shop['remote_shop_id'], 'data' => $params]), 'webapi-request-data');

        $useBatchRefundProcess = $this->di->getConfig()->batch_process_amazon_order_refund ?? false;
        if ($useBatchRefundProcess) {
            $params['remote_shop_id'] = $shop['remote_shop_id'];
            $params['user_id'] = $data['user_id'] ?? $this->di->getUser()->id;
            return $this->handleBatchRefund($params, $data);
        }

        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('amazon', true, 'amazon')
            ->call('feed/refund', [], ['shop_id' => $shop['remote_shop_id'], 'data' => $params], 'POST');

        //Todo: add a check for handling remoteResponse = null;
        $this->tempLog($remoteResponse, $type = 'remoteResponse');

        //updating the data that will be returned
        $data = $this->updateReturnData($data);

        if (!$remoteResponse['success']) {
            $message = $remoteResponse['message'] ?? $remoteResponse['msg'];
            if (str_contains((string) $message, "'AdjustmentReason' is invalid")) {
                $remoteResponse['msg'] = 'Refund reason cannot be empty';
            }

            return [
                'success' => false,
                'data' => $data,
                'message' => $this->getRemoteErrorMessage($remoteResponse),
                'isFeedBased' => true
            ];
        }

        if (isset($remoteResponse['data']) && !is_array($remoteResponse['data']) && str_contains((string) $remoteResponse['data'], 'Unauthorized: AccessDenied')) {
            return [
                'success' => false,
                'data' => $data,
                'message' => 'Unable to process request on Amazon'
            ];
        }

        if (isset($remoteResponse['data']['FeedSubmissionId'])) {
            $data['response_feed_id'] = $remoteResponse['data']['FeedSubmissionId'];
            $feedData = $this->prepareFeedData($remoteResponse['data'], $shopId);
        }

        if (isset($remoteResponse['xml'])) {
            $this->tempLog($remoteResponse['xml'], 'refund_xml');
        }

        return [
            'success' => true,
            'data' => $data,
            'feed_data' => $feedData,
            'isFeedBased' => true
        ];
    }

    public function isAdjustmentValid($data): array
    {
        if (!isset($data['adjust_amount'])) {
            return ['success' => true, 'message' => 'No adjustment done.'];
        }

        $adjustMarketplacePrice = 0;
        foreach ($data['items'] as $item) {
            if (isset($item['adjust_amount'])) {
                $adjustMarketplacePrice += $item['adjust_amount']['marketplace_price'];
            }
        }

        if ($adjustMarketplacePrice != $data['adjust_amount']['marketplace_price']) {
            return ['success' => false, 'message' => 'Adjustment bifurcation is not available.'];
        }

        return ['success' => true, 'message' => 'Adjustment is valid.'];
    }

    public function checkSettings($data)
    {
        $this->processManual = false;
        if (isset($data['process']) && $data['process'] == "manual") {
            $this->processManual = true;
            return;
        }

        $settingsResponse = $this->getSettings($data);
        if (!$settingsResponse['success']) {
            return $settingsResponse;
        }

        $this->settings_data = $settingsResponse['data'];
        if (empty($this->settings_data)) {
            return ['success' => false, 'message' => 'Settings not found!'];
        }

        // if (!$this->isSettingEnabled()) {
        //     return ['success' => false, 'message' => 'Settings not enabled'];
        // }
        if ($this->isRefundEnabled() === false) {
            return [
                'success' => false,
                'message' => 'Refund settings not enabled!'
            ];
        }

        $this->getMappedRefundReasons();
    }

    public function getShopDetails($shopId)
    {
        $shops = $this->di->getUser()->shops;
        foreach ($shops as $shop) {
            if ($shop['_id'] == $shopId) {
                return $shop;
            }
        }

        return [];
    }

    public function prepareFeedData($data, $shopId): array
    {
        $feedData = [];
        isset($data['FeedSubmissionId']) && $feedData['feed_id'] = $data['FeedSubmissionId'];
        isset($data['FeedType']) && $feedData['type'] = $data['FeedType'];
        if (isset($data['SubmittedDate'])) {
            $feedData['feed_created_date'] = $data['SubmittedDate'];
        } else {
            $feedData['feed_created_date'] = date('c');
        }

        isset($data['FeedProcessingStatus']) && $feedData['status'] = $data['FeedProcessingStatus'];
        $shop = $this->getShopDetails($shopId);
        if (isset($shop['warehouses'])) {
            foreach ($shop['warehouses'] as $warehouse) {
                //need to update
                $feedData['marketplace_id'] = $warehouse['marketplace_id'];
            }
        }

        $user_id = $this->di->getUser()->id;
        $feedData['user_id'] = $user_id;
        $feedData['remote_shop_id'] = $shop['remote_shop_id'];
        $feedData['marketplace_shop_id'] = $shopId;
        return $feedData;
    }

    public function getRemoteErrorMessage($remoteResponse)
    {
        $errorMessage = $remoteResponse['message'] ?? $remoteResponse['msg'];
        if (isset($errorMessage['errors'])) {
            $amazonErrors = json_decode((string) $errorMessage, true);
            $errors = [];
            foreach ($amazonErrors as $error) {
                $errors[] = $error['message'] . ' (Response from Amazon)';
            }

            if (count($errors) == 1) {
                $errors = $errors[0];
            }

            $errorMessage = $errors;
        }

        return $errorMessage;
    }

    /**
     * to create feed content for feed submit
     *
     * @param array $data
     */
    public function createFeedContent($data): array
    {
        if (!isset($data['marketplace_reference_id'])) {
            return [
                'success' => false,
                'message' => 'marketplace_reference_id not found'
            ];
        }

        if (!isset($data['items'])) {
            return [
                'success' => false,
                'message' => 'Items not found'
            ];
        }

        $feedContent = [];
        $feedContent['Id'] = random_int(1000000, 10000000000);
        $feedContent['AmazonOrderId'] = $data['marketplace_reference_id'];
        $feedContent['ActionType'] = 'Refund';
        foreach ($data['items'] as $item) {
            $feedItem = [];
            if (!isset($item['marketplace_item_id']) || !$item['qty']) {
                return [
                    'success' => false,
                    'message' => 'marketplace_item_id/qty not found in amazon order items'
                ];
            }

            $feedItem['AmazonOrderItemCode'] = trim($item['marketplace_item_id']);
            $feedItem['QuantityCancelled'] = $item['qty'];
            if ($this->processManual) {
                $feedItem['AdjustmentReason'] = $item['customer_note'] ?? "";
            } else {
                if (isset($item['customer_note'])) {
                    $feedItem['AdjustmentReason'] = $this->getReason($item['customer_note']);
                } elseif (isset($data['customer_note'])) {
                    $feedItem['AdjustmentReason'] = $this->getReason($data['customer_note']);
                } else {
                    $feedItem['AdjustmentReason'] = $this->getReason();
                }
            }

            $feedItem['ItemPriceAdjustments'] = $this->createComponents($item, $data['taxes_included'] ?? false);
            $feedContent['Item'][] = $feedItem;
        }

        $feed[$feedContent['Id']] = $feedContent;
        return [
            'success' => true,
            'data' => $feed
        ];
    }

    /**
     * to create feed components
     *
     * @return void
     */
    public function createComponents($item, $taxIncluded = false)
    {
        $principalAmount = $item['refund_amount']['marketplace_price'];
        //todo need to handle shipping_charge for cases taxes included
        if (isset($item['tax']['marketplace_price'])) {
            $principalAmount -= $item['tax']['marketplace_price'];
            $hasShipTax = $this->hasShippingTaxInTaxes($item);
            if ($hasShipTax['success']) {
                $item['tax']['marketplace_price'] -= $hasShipTax['data'];
                if ($taxIncluded) {
                    $item['shipping_charge']['marketplace_price'] -= $hasShipTax['data'];
                }
            }
        }

        if (isset($item['shipping_charge']['marketplace_price'])) {
            $principalAmount -= $item['shipping_charge']['marketplace_price'];
        }

        $component[]['Component'] = ["Type" => "Principal", "Amount" => $principalAmount];

        if (isset($item['tax']['marketplace_price'])) {
            $component[]['Component'] = ["Type" => "Tax", "Amount" => $item['tax']['marketplace_price']];
        } else {
            $component[]['Component'] = ["Type" => "Tax", "Amount" => 0];
        }

        if (isset($item['shipping_charge']['marketplace_price'])) {
            $component[]['Component'] = ["Type" => "Shipping", "Amount" => $item['shipping_charge']['marketplace_price']];
        }

        if ($hasShipTax['success']) {
            $component[]['Component'] = ["Type" => "ShippingTax", "Amount" => $hasShipTax['data']];
        }

        return $component;
    }

    public function hasShippingTaxInTaxes($item): array
    {
        if (!isset($item['taxes'])) {
            return ['success' => false, 'message' => 'No taxes available'];
        }

        foreach ($item['taxes'] as $tax) {
            if ($tax['code'] == 'ShippingTax') {
                return ['success' => true, 'data' => $tax['marketplace_price']];
            }
        }

        return ['success' => false, 'message' => 'No ShippingTax available'];
    }

    public function prepareForDb($order): array
    {
        return [];
    }

    public function create($order): array
    {
        return [];
    }

    public function syncFeed($feedId): void
    {
        $marketplace = ['A21TJRUUN4KGV'];
        $shopId = 8;
        $params = ['feed_id' => $feedId, 'marketplace_id' => $marketplace, 'shop_id' => $shopId, 'type' => 'POST_PAYMENT_ADJUSTMENT_DATA'];
        $response = $this->di->getObjectManager()
            ->get("\App\Connector\Components\ApiClient")
            ->init('amazon', true, 'amazon')
            ->call('feed-sync', [], $params, 'GET');

        if (isset($response['response'])) {
            $encodedResponse = $response['response'];
            $encodedResponse = base64_decode((string) $encodedResponse);
            $encodedResponse = str_replace("newlinecustomization", "\n", $encodedResponse);
            $encodedResponse = str_replace("newtabcustomization", "\t", $encodedResponse);
            print_r($encodedResponse);
            echo '<br>******<br><pre>';
            $xml = simplexml_load_string($encodedResponse);
            $json = json_encode($xml);
            print_r($json);
        }

        die();
    }


    /**
     * validate settings from config
     */
    public function getSettings($data): array
    {
        $user_details = $this->di->getUser()->getConfig();
        $userId = $user_details['user_id'];
        if (!isset($user_details['shops'])) {
            return [
                'success' => false,
                'message' => 'Shops not found'
            ];
        }

        foreach ($user_details['shops'] as $shop) {
            if ($shop['_id'] == $data['marketplace_shop_id']) {
                $source_shop_id = $shop['_id'];
            }

            if ($shop['_id'] == $data['target_shop_id']) {
                $target_shop_id = $shop['_id'];
            }
        }

        if (!isset($source_shop_id) || !isset($target_shop_id)) {
            return [
                'success' => false,
                'message' => 'source_shop_id/target_shop_id not matched with user shops'
            ];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection =  $mongo->getCollectionForTable('config');
        $pipeline = [];
        $filter = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        array_push($pipeline, ['$match' => ['user_id' => $userId]]);
        array_push($pipeline, ['$match' => ['group_code' => 'order']]);
        array_push($pipeline, ['$match' => ['source_shop_id' => (string)$target_shop_id]]); //since it will be from target to source & the settings saved in config are from source to target
        array_push($pipeline, ['$match' => ['target_shop_id' => (string)$source_shop_id]]);
        $settings = $collection->aggregate($pipeline, $filter)->toArray();
        return [
            'success' => true,
            'data' => $settings
        ];
    }

    /**
     * to check if settings are enabled or not
     *
     * @return boolean
     */
    public function isSettingEnabled()
    {
        return $this->checkSpecificSetting('order_sync');
    }

    public function isRefundEnabled()
    {
        return $this->checkSpecificSetting('order_refund');
    }

    public function updateReturnData($data)
    {
        $data = $this->mapRefundReason($data);
        return $data;
    }

    public function getMappedRefundReasons(): void
    {
        $this->reasons = [];
        $mappedReasons = [];
        foreach ($this->settings_data as $setting) {
            if ($setting['key'] == 'refund_reasons') {
                $mappedReasons = $setting['value'];
            }
        }

        $this->reasons = $mappedReasons;
    }

    public function mapRefundReason($data): array
    {
        if (isset($data['customer_note'])) {
            $data['customer_note'] = $this->getReason($data['customer_note']);
        }

        foreach ($data['items'] as $index => $item) {
            if (isset($item['customer_note'])) {
                $data['items'][$index]['customer_note'] = $this->getReason($item['customer_note']);
            }
        }

        return $data;
    }

    public function getReason($reason = null)
    {
        $defaultRefundReason = null;

        $defaultRefundReasonConfig = $this->checkSpecificSetting('default_refund_reason');
        if (!empty($defaultRefundReasonConfig['enabled']) && !empty($defaultRefundReasonConfig['reason'])) {
            $defaultRefundReason = $defaultRefundReasonConfig['reason'];
        }

        if (empty($reason) && !is_null($defaultRefundReason)) {
            return $defaultRefundReason;
        }

        foreach ($this->reasons as $value) {

            $sourceReason = array_filter($value, fn($marketplaceWiseReason): bool => $marketplaceWiseReason !== 'amazon_reason', ARRAY_FILTER_USE_KEY);

            if (in_array($reason, $sourceReason)) {
                return $value['amazon_reason'];
            }
        }

        if (!in_array($reason, self::AMZ_ALLOWED_REFUND_REASONS) && !is_null($defaultRefundReason)) {
            return $defaultRefundReason;
        }

        return $reason;
    }

    /**
     * to check if specific setting exists
     *
     * @param [type] $setting
     * @return void
     */
    public function checkSpecificSetting($setting = null)
    {
        if (empty($setting)) {
            return false;
        }

        foreach ($this->settings_data as $value) {
            if ($value['key'] == $setting) {
                return $value['value'];
            }
        }
    }

    public function prepareResponse($response, $data): array
    {
        $response['data'] = $data;
        $response['isFeedBased'] = $this->isFeedBased();
        return $response;
    }

    public function refundResponse($data)
    {
        $errorCount = 0;
        $successCount = 0;
        $warningCount = 0;

        if (!isset($data['marketplace_id']) || !isset($data['marketplace_shop_id']) || !isset($data['feed_id'])) {
            return  ['success' => false, 'message' => 'marketplace_id/marketplace_shop_id/feed_id not found'];
        }

        $marketplace_id = $data['marketplace_id'];
        $shopId = $data['marketplace_shop_id'];
        $shop = $this->getShopData($shopId);
        if (empty($shop)) {
            return [
                'success' => false,
                'message' => 'Shop not found on home'
            ];
        }

        $params = [
            'feed_id' => $data['feed_id'],
            'marketplace_id' => $marketplace_id,
            'shop_id' => $shop['remote_shop_id'],
            'type' => 'POST_PAYMENT_ADJUSTMENT_DATA'
        ];
        $response = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('amazon', true, 'amazon')
            ->call('feed-sync', [], $params, 'GET');

        if (!$response['success']) {
            return [
                'success' => false,
                'message' => 'might be in_process'
            ];
        }

        if (!isset($response['response'])) {
            return [
                'success' => false,
                'message' => 'response not found'
            ];
        }
        $encodedResponse = $response['response'];
        $decodedResponse = base64_decode($encodedResponse);
        $reportArray = json_decode($decodedResponse, true);
        // $encodedResponse = base64_decode((string) $encodedResponse);
        // $encodedResponse = str_replace("newlinecustomization", "\n", $encodedResponse);
        // $encodedResponse = str_replace("newtabcustomization", "\t", $encodedResponse);
        // $xml = simplexml_load_string($encodedResponse);
        // $json = json_encode($xml);
        // $reportArray = json_decode($json, true);
        if (!isset($reportArray['Message']['ProcessingReport'])) {
            return [
                'success' => false,
                'message' => 'ProcessingReport not found'
            ];
        }

        $processingReport = $reportArray['Message']['ProcessingReport'];
        if (!isset($processingReport['StatusCode'])) {
            return [
                'success' => false,
                'message' => 'StatusCode not found'
            ];
        }

        switch ($processingReport['StatusCode']) {
            case 'Complete':
                if (!isset($processingReport['ProcessingSummary'])) {
                    return [
                        'success' => false,
                        'message' => 'ProcessingSummary not found'
                    ];
                }

                $summary = $processingReport['ProcessingSummary'];
                if (isset($summary['MessagesSuccessful'])) {
                    $successCount = (int)$summary['MessagesSuccessful'];
                }

                if (isset($summary['MessagesWithError'])) {
                    $errorCount = (int)$summary['MessagesWithError'];
                }

                if (isset($summary['MessagesWithWarning'])) {
                    $warningCount = (int)$summary['MessagesWithWarning'];
                }

                break;
        }

        if ($errorCount > 0) {
            if (isset($processingReport['Result'])) {
                $result = $processingReport['Result'];
                if (isset($result['ResultCode'])) {
                    switch ($result['ResultCode']) {
                        case 'Error':
                            $errorMessage = $result['ResultDescription'];
                            break;
                    }
                }
            }
        }

        if (isset($errorMessage)) {
            return [
                'success' => false,
                'message' => $errorMessage,
                'feed_id' => $data['feed_id']
            ];
        }

        if ($successCount > 0) {
            return [
                'success' => true,
                'feed_id' => $data['feed_id'],
                'message' => 'Feed processed'
            ];
        }
    }

    public function getShopData($shopId)
    {
        $shops = $this->di->getUser()->shops;
        foreach ($shops as $shop) {
            if ($shop['_id'] == $shopId) {
                return $shop;
            }
        }

        return [];
    }

    public function initiateFeedSync(): array
    {
        $cronTask = 'sync_refund_feeds';
        $logfile = "order/order-refund/crons/amazon/{$cronTask}/" . date('Y-m-d') . '.log';
        $limit = 10;
        $successfulSyncCount = 0;
        $totalFeedsProcessed = 0;
        $query = ['type' => 'POST_PAYMENT_ADJUSTMENT_DATA'];
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array'], "limit" => $limit, "skip" => 0];
        $mongo = $this->getBaseMongoAndCollection(self::FEED_CONTAINER);
        $feeds = $mongo->find($query, $options)->toArray();
        if (!empty($feeds)) {
            $notSuccessResponses = [];
            foreach ($feeds as $feed) {
                $totalFeedsProcessed += 1;
                $this->di->getLog()->logContent('feed data : ' . print_r($feed, true), 'info', $logfile);
                $response = $this->commenceRefundResponse($feed);
                $this->di->getLog()->logContent('response : ' . print_r($response, true), 'info', $logfile);
                if ($response['success']) {
                    $deleteresponse = $mongo->deleteOne(['_id' => $feed['_id']]);
                    $successfulSyncCount += 1;
                } else {
                    $notSuccessResponses[] = $feed['feed_id'] . ' -> ' . $response['message'];
                }
            }
        }

        return [
            'success' => true,
            'message' => "{$successfulSyncCount} refund feed(s) synced successfully.",
            'totalFeedsProcessed' => "{$totalFeedsProcessed} refund feed(s) processed.",
            'responses' => $notSuccessResponses ?? ''
        ];
    }

    public function commenceRefundResponse($data)
    {
        $setDiForUserResponse = $this->setDiForUser($data['user_id']);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin. user_id: ' . $data['user_id']
            ];
        }

        if (!$setDiForUserResponse['success']) {
            $this->tempLog($data, 'feed_db_data', 'user-not-found-to-set-di.log');
            return $setDiForUserResponse;
        }

        $refundResponse = $this->refundResponse($data);

        $this->tempLog($data, 'feed_db_data', 'amazon_module_refund_response.log');
        $this->tempLog($refundResponse, 'feed_response', 'amazon_module_refund_response.log');
        if (empty($refundResponse)) {
            return ['success' => false, 'message' => 'refundResponse empty'];
        }

        // $refundResponse = ['success' => true, 'feed_id' => '140861019296', 'message' => 'Updated'];
        $failed = false;
        if (!$refundResponse['success']) {
            $failed = true;
            if ($this->isFeedProcessingError($refundResponse)) {
                return ['success' => false, 'message' => $refundResponse['message']];
            }
        }

        if (str_contains((string) $refundResponse['message'], 'This is a malformed or invalid XML document.')) {
            $refundResponse['message'] = 'Refund reason might be invalid';
        }

        if (str_contains((string) $refundResponse['message'], 'For troubleshooting help')) {
            $refundResponse['message'] = substr((string) $refundResponse['message'], 0, strpos((string) $refundResponse['message'], " For troubleshooting help"));
        }

        $refundResponse['user_id'] = $data['user_id'];
        $refundResponse['marketplace'] = 'amazon';
        $refundResponse['marketplace_shop_id'] = $data['marketplace_shop_id'];
        $connectorRefundService = $this->di->getObjectManager()->get(RefundInterface::class);
        $connectorResponse = $connectorRefundService->refundResponseUpdate($refundResponse, $failed);
        return $connectorResponse;
    }

    public function setDiForUser($user_id): array
    {
        $getUser = User::findFirst([['_id' => $user_id]]);
        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found, user_id: ' . $user_id
            ];
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }

    public function isFeedProcessingError($refundResponse): bool
    {
        $processingErrors = [
            'might be in_process',
            'response not found',
            'ProcessingReport not found',
            'StatusCode not found',
            'ProcessingSummary not found',
        ];
        if (!in_array($refundResponse['message'], $processingErrors)) {
            return false;
        }

        return true;
    }

    public function getRefundReasons(): array
    {
        $reasons = [
            "NoInventory",
            "CustomerReturn",
            "GeneralAdjustment",
            "CouldNotShip",
            "DifferentItem",
            "Abandoned",
            "CustomerCancel",
            "PriceError",
            "ProductOutofStock",
            "CustomerAddressIncorrect",
            "Exchange",
            "Other",
            "CarrierCreditDecision",
            "RiskAssessmentInformationNotValid",
            "CarrierCoverageFailure",
            "TransactionRecord",
            "Undeliverable",
            "RefusedDelivery"
        ];
        return $reasons;
    }

    public function handleBatchRefund($params, $refundData)
    {
        $validateRefundResponse = $this->validateRefundReason($params['feedContent']);
        if (!$validateRefundResponse['success']) {
            $validateRefundResponse['batch_process'] = true;
            $validateRefundResponse['data'] = $refundData;
            if ($validateRefundResponse['message'] == 'Refund reason is invalid' && !empty($validateRefundResponse['invalid_reasons'])) {
                $this->shouldMapInvalidRefundReason($refundData, $validateRefundResponse['invalid_reasons']);
            }

            return $validateRefundResponse;
        }

        $messageId = array_key_first($params['feedContent']);
        $params['message_id'] = $messageId;

        $params['marketplace_reference_id'] = $refundData['marketplace_reference_id'];
        $saveFeedResponse = $this->saveFeedContent($params);
        if (!$saveFeedResponse['success']) {
            $saveFeedResponse['batch_process'] = true;
            $saveFeedResponse['data'] = $refundData;
            return $saveFeedResponse;
        }

        return $this->updateBatchRefundResponseContent($params, $refundData);
    }

    public function validateRefundReason($feedContent = []): array
    {
        $emptyReason = false;
        $invalidReason = false;
        $invalidReasons = [];

        foreach ($feedContent as $feed) {
            foreach ($feed['Item'] as $item) {
                if (empty($item['AdjustmentReason'])) {
                    $emptyReason = true;
                    break;
                } elseif (!in_array($item['AdjustmentReason'], self::AMZ_ALLOWED_REFUND_REASONS)) {
                    $invalidReason = true;
                    $invalidReasons[] = $item['AdjustmentReason'];
                    break;
                }
            }
        }

        if (!$emptyReason && !$invalidReason) {
            return ['success' => true];
        }

        $errorMessage = $emptyReason ? 'Refund reason cannot be empty' : 'Refund reason is invalid';
        return ['success' => false, 'message' => $errorMessage, 'invalid_reasons' => $invalidReasons];
    }

    private function shouldMapInvalidRefundReason($data, $invalidReasons)
    {
        if (empty($this->settings_data)) {
            return;
        }

        $configCollection = $this->getBaseMongoAndCollection('config');

        $filter = [
            'user_id' => $data['user_id'],
            'group_code' => 'order',
            'key' => 'refund_reasons',
            'source' => $this->settings_data[0]['source'],
            'source_shop_id' => $this->settings_data[0]['source_shop_id'],
            'target' => $this->settings_data[0]['target'],
            'target_shop_id' => $this->settings_data[0]['target_shop_id'],
        ];

        $sourceMarketplace = $this->settings_data[0]['source'];

        $values = array_map(function($reason) use ($sourceMarketplace) {
            return [
                "{$sourceMarketplace}_reason" => $reason,
                "amazon_reason" => "",
            ];
        }, $invalidReasons);

        $refundReasonMapData = [
            '$addToSet' => [
                'value' => [
                    '$each' => $values
                ]
            ]
        ];

        $configCollection->updateOne($filter, $refundReasonMapData);
    }

    public function saveFeedContent($params): array
    {
        $params = [
            'feed_content' => $params['feedContent'],
            'user_id' => $params['user_id'],
            'home_shop_id' => (string)$params['shop_id'],
            'type' => 'POST_PAYMENT_ADJUSTMENT_DATA',
            'admin' => true,
            'remote_shop_id' => (string)$params['remote_shop_id'],
            'feed_message_id' => (string)$params['message_id'],
            'amazon_order_id' => $params['marketplace_reference_id']
        ];
        try {
            $amzFeedCollection = $this->getBaseMongoAndCollection('amazon_feed_data');
            $amzFeedCollection->insertOne($params);
            return ['success' => true];
        } catch (Exception $e) {
            $this->tempLog($e->getMessage(), 'handle_batch_refund');
            return ['success' => false, 'message' => 'Something went wrong. Please try again.'];
        }
    }

    public function updateBatchRefundResponseContent($params, $refundData): array
    {
        $refundData['feed_message_id'] = (string)$params['message_id'];
        $refundData = $this->updateReturnData($refundData);
        return ['success' => true, 'data' => $refundData, 'batch_process' => true];
    }

    public function getAppTag(): string
    {
        return 'amazon_sales_channel';
    }

    public function tempLog($data, $type = 'feed_data', $process = 'amazon_module.log'): void
    {
        $time = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
        $user_id = $this->di->getUser()->id;
        $div = '--------------------------------------------------------------------------------';
        $this->di->getLog()->logContent($div . PHP_EOL . $time . PHP_EOL . $type . ' = ' . print_r($data, true), 'info', 'order/order-refund/temp-all-refunds/'  . $user_id . '/' . date("Y-m-d") . '/' . $process);
    }

    public function getBaseMongoAndCollection($collection): object
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        return $mongo->getCollection($collection);
    }

    public function isFeedBased(): bool
    {
        return true;
    }

    public function hasResponseContent(): bool
    {
        return false;
    }
}
