<?php

namespace App\Amazon\Components\Listings;

use App\Amazon\Components\Feed\Feed;
use Exception;
use App\Core\Components\Base as Base;
use App\Connector\Components\Helper as ConnectorHelper;


class Error extends Base
{
    public const ERROR_TYPE = 'error';

    public const ERRORED_ATTRIBUTE_NAME = 'error_attribute_name';

    /** set error in product */
    public function saveErrorInProduct($productErrorList, $errorType, $targetShopId, $sourceShopId, $userId, $feedData = [], $productsData = []): void
    {

        $date = date(DATE_RFC2822);
        $time = str_replace("+0000", "GMT", $date);
        if (!$targetShopId) {
            $targetShopId = (string)$feedData['specifics']['shop_id'];
        }

        if (!$errorType && isset($feedData['type'])) {
            $errorType = Feed::FEED_TYPE[$feedData['type']];
        }

        if (!$userId && isset($feedData['user_id'])) {
            $userId = (string)$feedData['user_id'];
        }

        if (!$sourceShopId && isset($feedData['source_shop_id'])) {
            $sourceShopId = (string)$feedData['source_shop_id'];
        }

        $additionalData['source'] = [
            'marketplace' => 'shopify',
            'shopId' => (string) $sourceShopId
        ];
        $additionalData['target'] = [
            'marketplace' => 'amazon',
            'shopId' => (string) $targetShopId
        ];
        if (!empty($productErrorList)) {
            $bulkUpdateData = [];
            $listingHelper = $this->di->getObjectManager()->get(Helper::class);
            $projection = ['container_id' => 1];
            foreach ($productErrorList as $sourceProductId => $productDetails) {

                $product = [];
                $error = [];
                $updateData = [];
                if (isset($productDetails['container_id']) && !empty($productDetails['container_id'])) {
                    $containerId = $productDetails['container_id'];
                } else {
                    // $product = $listingHelper->queryProduct($userId, $sourceProductId, $sourceShopId, $targetShopId, $projection);
                    // $containerId = $product['container_id'];
                }

                if (empty($containerId)) {
                    var_dump('product not found');
                    continue;
                }

                if (isset($productDetails['error']) && !empty($productDetails['error'])) {

                    foreach ($productDetails['error'] as $errorCode) {

                        $errMsg = "";
                        $errCode = "";
                        if ($feedData) {
                            if (is_array($errorCode)) {
                                foreach ($errorCode as $code => $val) {
                                    if ($code == "AmazonValidationError") {
                                        $error[] = [
                                            'type' => $errorType,
                                            'code' => $code,
                                            'source'=> 'validator',
                                            'message' => $val['message'],
                                            'path' =>  $val['path'],
                                            'label' =>  $val['label'],
                                            'time' => $time
                                        ];
                                    }
                                }
                                if(empty($error) && isset($errorCode['code'], $errorCode['message'])) {
                                   
                                    $error [] = [
                                        'type' => $errorType,
                                        'source'=> 'marketplace',
                                        'code' => $errorCode['code'],
                                        'message' => $errorCode['message'],
                                        'attributeNames' => $errorCode['attributeNames'] ?? [],
                                        'time' => $time
                                    ];

                                }

                                continue;
                            }
                            $msg = $this->getErrorByCode($errorCode);
                            if ($msg) {
                                $errCode = $errorCode;
                                $errMsg = $msg;
                            } else {
                                $errCode =  Locale::DEFAULT_ERROR_CODE;
                            }
                        } else {
                            $errMsg = $this->getErrorByCode($errorCode);
                            if ($errMsg) {
                                $errCode = $errorCode;
                            } else {
                                $errCode = Locale::DEFAULT_ERROR_CODE;
                                $errMsg = $errorCode;
                            }
                        }

                        $error[] = [
                            'type' => $errorType,
                            'source'=> 'validator',
                            'code' => $errCode,
                            'message' => $errMsg,
                            'time' => $time
                        ];
                    }
                }

                if (!empty($error) || isset($productDetails['process_tags'])) {

                    $updateData = [
                        "source_product_id" => (string) $sourceProductId,
                        // required
                        'user_id' => $userId,
                        'source_shop_id' => (string) $sourceShopId,
                        'target_marketplace' => 'amazon',
                        'shop_id' => (string)$targetShopId,
                        'container_id' => (string) $containerId
                    ];
                    if (!empty($error)) {
                        $updateData['error'] = $error;
                    }

                    if (isset($productDetails['process_tags'])) {
                        if (empty($productDetails['process_tags'])) {
                            $updateData['unset'][] = 'process_tags';
                        } else {
                            $updateData['process_tags'] = $productDetails['process_tags'];
                        }
                    }

                    $bulkUpdateData[] = $updateData;
                }
            }

            if (!empty($bulkUpdateData)) {
                $appTag = $this->di->getAppCode()->getAppTag();

                if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
                    /* Trigger websocket (if configured) */
                    $websocketConfig = $this->di->getConfig()->get("app_tags")
                        ->get($appTag)
                        ->get('websocket');
                    if (
                        isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['notification']) &&
                        $websocketConfig['client_id'] &&
                        $websocketConfig['allowed_types']['notification']
                    ) {
                        echo '<pre>';

                        $helper = $this->di->getObjectManager()->get(ConnectorHelper::class);
                        $helper->handleMessage([
                            'user_id' => $userId,
                            'notification' => $bulkUpdateData
                        ]);
                    }
                }
                $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                $res = $editHelper->saveProduct($bulkUpdateData, $userId, $additionalData);

                // $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                // $helper->marketplaceSaveAndUpdate($bulkUpdateData);
            }
        }
    }

    /** get error message from code */
    public function getErrorByCode($code)
    {
        $err = match ($code) {
            'AmazonError101' => Locale::CATEGORY_EMPTY_ERRORED_MESSAGE,
            'AmazonError102' => Locale::SKU_EMPTY_ERRORED_MESSAGE,
            'AmazonError103' => Locale::DESCRIPTION_EXCEEDING_ERRORED_MESSAGE,
            'AmazonError104' => Locale::SKU_EXCEEDING_ERRORED_MESSAGE,
            'AmazonError105' => Locale::PRICE_EMPTY_EMPTY_ERRORED_MESSAGE,
            'AmazonError106' => Locale::PRICE_ZERO_ERRORED_MESSAGE,
            'AmazonError107' => Locale::PRODUCT_SETTING_DISABLED_ERRORED_MESSAGE,
            'AmazonError108' => Locale::QUANTITY_EMPTY_ERRORED_MESSAGE,
            'AmazonError109' => Locale::BARCODE_EMPTY_ERRORED_MESSAGE,
            'AmazonError110' => Locale::BARCODE_INVALID_ERRORED_MESSAGE,
            'AmazonError111' => Locale::CONDITION_TYPE_EMPTY_ERRORED_MESSAGE,
            'AmazonError301' => Locale::ASIN_EMPTY_ERRORED_MESSAGE,
            'AmazonError302' => Locale::BARCODE_TYPE_ERRORED_MESSAGE,
            'AmazonError303' => Locale::MINIMUM_PRICE_MORE_THAN_PRICE_ERRORED_MESSAGE,
            'AmazonError304' => Locale::SALE_PRICE_MORE_THAN_PRICE_ERRORED_MESSAGE,
            'AmazonError305' => Locale::NAME_EXCEEDING_ERRORED_MESSAGE,
            'AmazonError309' => Locale::FBA_NOT_AUTHORISED,
            'AmazonError123' => Locale::INVENTORY_SETTING_DISABLED_ERRORED_MESSAGE,
            'AmazonError124' => Locale::FBA_PRODUCT_ERRORED_MESSAGE,
            'AmazonError125' => Locale::WAREHOUSE_SETTING_DISABLED_ERRORED_MESSAGE,
            'AmazonError141' => Locale::PRICE_SETTING_DISABLED_ERRORED_MESSAGE,
            'AmazonError161' => Locale::IMAGE_EMPTY_ERRORED_MESSAGE,
            'AmazonError162' => Locale::IMAGE_SETTING_DISABLED_ERRORED_MESSAGE,
            'AmazonError501' => Locale::NOT_LISTED_PRODUCT_ERRORED_MESSAGE,
            'AmazonError900' => Locale::FEED_NOT_GENERATED_ERRORED_MESSAGE,
            'AmazonError901' => Locale::FEED_LANGUAGE_ERRORED_MESSAGE,
            'AmazonError902' => Locale::FEED_CONTENT_ERRORED_MESSAGE,
            'AmazonError903' => Locale::SHOP_NOT_FOUND_ERRORED_MESSAGE,
            'AmazonError1099' => Locale::ONLYNOTLISTEDPRODUCT,
            'AmazonError1098' => Locale::ONLYLISTEDPRODUCT,
            default => $code,
        };
        return $err;
    }

    /** format issues to save in marketplace-node */
    public function formateErrorMessages($errors)
    {
        try {
            $processedErrors = [];
            if (is_array($errors) && !empty($errors)) {
                foreach ($errors as $error) {
                    $errorDetails = [];
                    if (isset($error['code'], $error['message'])) {
                        $errorDetails = [
                            'type' => 'Listing',
                            'source'=> 'marketplace',
                            'code' => $error['code'],
                            'message' => $error['message']
                        ];
                        if (isset($error['attributeNames'])) {
                            $errorDetails[self::ERRORED_ATTRIBUTE_NAME] = $error['attributeNames'];
                        }
                    }

                    $severity = strtolower($error['severity'] ?? self::ERROR_TYPE);
                    if (!empty($errorDetails)) {
                        $processedErrors[$severity][] = $errorDetails;
                    }
                }
            }

            return $processedErrors;
        } catch (Exception $e) {
            print_r($e->getMessage());
            return [];
        }
    }
}
