<?php

namespace App\Amazon\Components\Product;

use App\Connector\Models\QueuedTasks;
use Exception;
use App\Amazon\Components\Common\Barcode;
use App\Connector\Models\Product\Marketplace;
use App\Core\Components\Base;

class Lookup extends Base
{
    protected $userId;

    protected $sourceShopId;

    protected $targetShopId;

    protected $sourceMarketplace;

    protected $targetMarketplace;

    public const statusArray = [
        Helper::PRODUCT_STATUS_ACTIVE,
        Helper::PRODUCT_STATUS_INACTIVE,
        Helper::PRODUCT_STATUS_INCOMPLETE,
        Helper::PRODUCT_STATUS_UPLOADED
    ];

    public const offerStatus = Helper::PRODUCT_STATUS_NOT_LISTED_OFFER;

    public const DEFAULT_CHUNK_SIZE_TO_SEND_ON_AMAZON = 20;

    public const DEFAULT_COUNT_PROCESS_DIRECTLY = 40;

    public const DEFAULT_QUERY_LIMIT = 250;

    public const THROTTLE_DELAY_TIME = 30;

    public const LOOKUP_COMPLETED_SUCCESS_MESSAGE = 'Amazon Lookup completed successfully.';

    public const LOOKUP_COMPLETED_PARTIALLY_MESSAGE = 'Amazon Lookup partially completed.';

    public const LOOKUP_COMPLETED_ERROR_MESSAGE = "Amazon look-up couldn't be completed. Kindly call support or try after sometime!";

    public $logFile = 'amazon/lookup.log';

    public function init($data)
    {
        $this->userId = isset($data['user_id']) ? (string)$data['user_id'] : (string)$this->di->getUser()->id;
        $this->sourceShopId = (string)$data['source_marketplace']['source_shop_id'] ?? null;
        $this->targetShopId = (string)$data['target_marketplace']['target_shop_id'] ?? null;
        $this->sourceMarketplace = (string)$data['source_marketplace']['marketplace'] ?? null;
        $this->targetMarketplace = (string)$data['target_marketplace']['marketplace'] ?? null;
        if (isset($this->sourceShopId, $this->targetShopId, $this->sourceMarketplace, $this->targetMarketplace)) {
            $obj = [
                'Ced-Source-Id' => $this->sourceShopId,
                'Ced-Source-Name' => $this->sourceMarketplace,
                'Ced-Target-Id' => $this->targetShopId,
                'Ced-Target-Name' => $this->targetMarketplace
            ];
            $this->di->getObjectManager()->get('\App\Core\Components\Requester')->setHeaders($obj);
        }

        return $this;
    }

    /**
     * initiate amazon look-up
     *Step : 1
     * @param array $data
     * @return bool|array
     */
    public function initiateLookup($data)
    {
        $this->logFile = 'amazon/lookup/'.date('Y-m-d').'.log';
        try {
            if (!($this->sourceMarketplace  && $this->targetMarketplace && $this->sourceShopId && $this->targetShopId)) {
                return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
            }

            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $connectedAccounts = $userDetails->getShop($this->targetShopId, $this->userId);
            if(!empty($connectedAccounts['sources'])) {
                foreach($connectedAccounts['sources'] as $source) {
                    if(isset($source['status']) && $source['status'] == 'closed') {
                        return ['success' => false, "Action cannot be performed because the store is closed."];
                    }
                }
            }
            if ($connectedAccounts['warehouses'][0]['status'] != 'active') {
                return ['success' => false, 'message' => 'Please enable your Amazon account from Settings section to initiate the processes.'];
            }

            //check if queued task with look-up process_code is in already progress
            $queuedTaskHelper = new QueuedTasks;
            // queued name == process tag in queued task == amazon_product_search
            $processTag = $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getSearchOnAmazonQueueName();
            $queuedTaskCount = $queuedTaskHelper->checkQueuedTaskwithProcessTag($processTag, $this->targetShopId);
            if ($queuedTaskCount) {
                return ['success' => false, 'message' => "Amazon look up is already running"];
            }
            $sourceProductIds = [];
            $bulkLookUp = false;
            $isThrottled = false;

            //check for query
            if (isset($data['query']) && !empty($data['query'])) {
                //thsi will be handled in other way
            }
            //check for source_product_ids
            if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {

                //first we will get count for the products count
                //if count <= 40 we will directly process by fetching the products along with edited
                //if count > 40 we will check how much source_product_ids if the count > 5000 we will process them via another method
                //if count is between the range we will process through the sqs, each time we will process 250 products from the ids and once they processsed successfully we will remove them from the sqs ids array

                //one question arises is that if we are taking 40 products to process directly how we can decide if the 250 products will be processed via sqs in one go
                //to handle failed product ids we will manage them the same way
                //to handle the failed products ids we will check if the failed products ids are not more than 50 than we will allow them to process with the chunk else we will first process them and not procedd with the chunk in that go
                //once the count comes below this we will fetch the products in the same limit and failed products along with that

                $sourceProductIds = $data['source_product_ids'];
                $variantsCount = $this->getProducts(false, false, $sourceProductIds, true);
                if (!$variantsCount) {
                    return ["success" => false, "message" => "Product(s) are not eligible for Amazon lookup."];
                }
                // if ($variantsCount > 5000) {
                //     //this is done to handle the size of products size a single product can now have 2000 variants as well, we will handle this via another technique
                //     return ["success" => false, "message" => "Product(s) count is more than 5000 this is a bulk data please choose within the limit"];
                // }
                $variants = $this->getProducts(false, false, $sourceProductIds);

                if ($variantsCount <= self::DEFAULT_COUNT_PROCESS_DIRECTLY) {
                    //initiate instant look-up instantly for <= 40 variants
                    $response = $this->processLookup($variants);
                    /** handle failed edge case */
                    if (isset($response['remote_server_error']) && $response['remote_server_error']) {
                        return ['success' => false, 'message' => self::LOOKUP_COMPLETED_ERROR_MESSAGE];
                    }

                    if (isset($response['failed_source_product']) && $response['failed_source_product']) {
                        $isThrottled = true;
                        // $bulkLookUp = true;
                    } else {
                        return ['success' => true, 'message' => self::LOOKUP_COMPLETED_SUCCESS_MESSAGE];
                    }
                } else {
                    // $sourceProductIds = array_column($variants, 'source_product_id');
                    $sourceProductIds = array_keys($variants);
                    $sourceProductIds = array_map(function ($value) {
                        return (string) $value;
                    }, $sourceProductIds);
                    $variantsCount = count($sourceProductIds);
                }
            } else if( isset($data['source_product_ids']) && empty($data['source_product_ids']) && !isset($data['query']) ) {
                $this->di->getLog()->logContent('Process terminated: ' . json_encode($data, true), 'info', $this->logFile);
                return [
                    'success' => false,
                    'message' => 'Source Product Id and Query not found. Kindly Contact Support!!',
                    'data' => $data
                ];
            }

            //check for all
            if (!isset($data['source_product_ids']) && !isset($data['query'])) {
                //first we will get count for the products count using the direct query
                //if count <= 40 we will directly process by fetching the products along with edited
                //if count > 40 we will process through the sqs, each time we will process 250 products from the ids and once they processsed successfully we will increase the page count


                //to handle failed product ids we will manage them the same way
                //to handle the failed products ids we will check if the failed products ids are not more than 50 than we will allow them to process with the chunk else we will first process them and not procedd with the chunk in that go
                //once the count comes below this we will fetch the products in the same limit and failed products along with that

                $variantsCount = $this->getProducts(false, false, [], true);
                if ($variantsCount) {
                    if ($variantsCount <= self::DEFAULT_COUNT_PROCESS_DIRECTLY) {
                        $variants = $variantsCount = $this->getProducts();
                        //initiate instant look-up instantly for <= 40 variants
                        $response = $this->processLookup($variants);
                        /** handle failed edge case */
                        if (isset($response['remote_server_error'])) {
                            return ['success' => false, 'message' => self::LOOKUP_COMPLETED_ERROR_MESSAGE];
                        }

                        if (isset($response['failed_source_product']) && !empty($response['failed_source_product'])) {
                            $isThrottled = true;
                            // $bulkLookUp = true;
                        } else {
                            return ['success' => true, 'message' => self::LOOKUP_COMPLETED_SUCCESS_MESSAGE];
                        }
                    } else {
                        $bulkLookUp = true;
                    }
                } else {
                    return ["success" => false, "message" => "Product(s) are not eligible for Amazon lookup."];
                }
            }



            // push data in queue for process start
            $queuedTaskData = [
                'user_id' => $this->userId,
                'message' => 'Amazon Lookup is in progress',
                'process_code' => $processTag,
                'marketplace' =>  $this->targetMarketplace,
                'app_tag' => $this->di->getAppCode()->getAppTag()
            ];
            // create new queued task for look-up
            $queuedTaskId = $queuedTaskHelper->setQueuedTask($this->targetShopId, $queuedTaskData);
            if ($queuedTaskId) {
                $sqsHandlerData = $this->getSqsDataForLookUp();
                $sqsHandlerData['queued_task_id'] = $queuedTaskId;
                // individual_weight is used to update progress in queued task
                $sqsHandlerData['individual_weight'] = $variantsCount; //need to handled thisk
                if (!$bulkLookUp) {
                    $sqsHandlerData['data']['source_product_ids'] = $sourceProductIds;
                } elseif ($isThrottled && isset($response['failed_source_product']) && !empty($response['failed_source_product'])) {
                    $sqsHandlerData['failed_source_product'] = $response['failed_source_product'];
                    $sqsHandlerData['isLastPage'] = true;
                }
                $this->di->getLog()->logContent('initiateLookup: sqsHandlerData: ' . json_encode($sqsHandlerData, true), 'info', $this->logFile);
                // push data in queue : name : amazon_product_search
                $this->di->getMessageManager()->pushMessage($sqsHandlerData);
                return ["success" => true, "message" => "Look up initiated. Please wait; it may take a while. The products' status in App will get updated to Not Listed: Offer If they get matched with Amazon's Product catalog"];
            }
            return ['success' => false, 'message' => "Amazon look up is already running"];

            return true;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('initiateLookup : ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true), 'info', 'exception.log');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function processLookup($variants, $failedProducts = [])
    {
        try {
            $returnArray = [];
            $barcodeValidatedProducts = [];
            $existedOfferProducts = [];
            $removeOfferProducts = [];
            if (!empty($variants)) {
                $result = $this->getBarcodeValidateProducts($variants);
                /** $barcodeValidatedProducts = ['barcode' => [ productData2: array, productData2: array]] */
                $barcodeValidatedProducts = $result['barcodeValidatedProducts'] ?? [];
                /** $existedOfferProducts = ['source_product_id' = ['container_id': string, 'asin': string]] */
                $existedOfferProducts = $result['offerProducts'] ?? [];
                /** these offer products now have invalid barcode, need to remove offer status from them */
                $removeOfferProducts = $result['removeOfferProducts'] ?? [];
            }

            if (!empty($failedProducts) && is_array($failedProducts)) {
                foreach ($failedProducts as $barcodeType => $failedData) {
                    foreach ($failedData as $barcode => $products) {
                        if (isset($barcodeValidatedProducts[$barcodeType][$barcode])) {
                            array_push($barcodeValidatedProducts[$barcodeType][$barcode], $products);
                        } else {
                            $barcodeValidatedProducts[$barcodeType][$barcode] = $products;
                        }
                    }
                }
            }


            if (!empty($barcodeValidatedProducts)) {
                $response = $this->searchProductOnAmazon($barcodeValidatedProducts, $existedOfferProducts);
                if (isset($response['failedSourceProducts']) && !empty($response['failedSourceProducts'])) {
                    /** push failed source_product_ids in queue for re-try */
                    $returnArray['failed_source_product'] = $response['failedSourceProducts'];
                }

                if (isset($response['remote_server_error']) && $response['remote_server_error']) {
                    // log remote error
                    $returnArray['remote_server_error'] = true;
                }
            } else {
                $returnArray['next_chunk'] = true;
            }

            if (!empty($removeOfferProducts)) {
                // remove offer status from product
                $bulkUpdateEditedData = [];
                foreach ($removeOfferProducts as $sourceProductId => $product) {
                    $asinData = [
                        'target_marketplace' => $this->targetMarketplace,
                        'shop_id' => $this->targetShopId,
                        'container_id' => (string)$product['container_id'],
                        'source_product_id' => (string)$sourceProductId,
                        'source_shop_id' => $this->sourceShopId,
                        'unset' => [
                            'asin' => 1,
                            'multioffer' => 1,
                            'status' => 1,
                            'category_settings' => 1
                        ]
                    ];
                    array_push($bulkUpdateEditedData, $asinData);
                }

                if (!empty($bulkUpdateEditedData)) {
                    $additionalData['source'] = [
                        'marketplace' => $this->sourceMarketplace,
                        'shopId' => (string)$this->sourceShopId
                    ];
                    $additionalData['target'] = [
                        'marketplace' => $this->targetMarketplace,
                        'shopId' => (string)$this->targetShopId
                    ];
                    $productHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                    $productHelper->saveProduct($bulkUpdateEditedData, $this->userId, $additionalData);
                }
            }
            return $returnArray;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('processLookup : ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true), 'info', 'exception.log');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getBarcodeValidateProducts($products)
    {
        try {
            $offerProducts = [];
            $barcodeValidatedProducts = [];
            $removeOfferProducts = [];
            if (!empty($products)) {
                $barcodeValidator = $this->di->getObjectManager()->create(Barcode::class);
                foreach ($products as $sourceProductId => $productDoc) {
                    $isOffer = false;
                    if (!isset($productDoc['edited']['status']) || !in_array($productDoc['edited']['status'], $this::statusArray)) {

                        if (isset($productDoc['edited']['status']) && ($productDoc['edited']['status'] == $this::offerStatus)) {
                            // need a array of products with offer status for offer status updation
                            $offerProducts[$sourceProductId] = ['container_id' => $productDoc['parent']['container_id'], 'asin' => $productDoc['edited']['asin'] ?? ''];
                            $isOffer = true;
                        }

                        $validBarcode = false;
                        // get barcode of target_marketplace if available
                        if (isset($productDoc['edited']['barcode']) && !empty($productDoc['edited']['barcode'])) {
                            $validBarcode = trim((string) $productDoc['edited']['barcode']);
                        } elseif (isset($productDoc['parent']['barcode']) && !empty($productDoc['parent']['barcode'])) {
                            // get barcode of shopify marketplace
                            $validBarcode = trim((string) $productDoc['parent']['barcode']);
                        }

                        if ($validBarcode) {
                            $barcodeType = $barcodeValidator->setBarcode($validBarcode);
                            if ($barcodeType) {
                                // validate barcode agains preg-match to remove special cases
                                if (preg_match('/(\x{200e}|\x{200f})/u', $validBarcode)) {
                                    $validBarcode = preg_replace('/(\x{200e}|\x{200f})/u', '', $validBarcode);
                                }

                                if ($barcodeType == 'EAN-8') $barcodeType = 'EAN';

                                $barcodeValidatedProducts[$barcodeType][$validBarcode][] = ['source_product_id' => $sourceProductId, 'container_id' =>  $productDoc['parent']['container_id']];
                            } elseif (isset($offerProducts[$sourceProductId])) {                                    // this array will keep those products who's offer status needs to be removed
                                $removeOfferProducts[$sourceProductId] = $offerProducts[$sourceProductId];
                                unset($offerProducts[$sourceProductId]);
                            }
                        } elseif ($isOffer) {
                            // this array will keep those products who's offer status needs to be removed
                            $removeOfferProducts[$sourceProductId] = $offerProducts[$sourceProductId];
                            unset($offerProducts[$sourceProductId]);
                        }
                    }
                }
            }

            return ['barcodeValidatedProducts' => $barcodeValidatedProducts, 'offerProducts' => $offerProducts, 'removeOfferProducts' => $removeOfferProducts];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('getBarcodeValidateProducts : ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true), 'info', 'exception.log');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }


    public function processSqsData($sqsData)
    {
        try {
            $this->logFile = 'amazon/lookup/'.date('Y-m-d').'.log';
            $queuedTaskHelper = new QueuedTasks;
            $this->di->getLog()->logContent('processSqsData: sqsData: ' . json_encode($sqsData, true), 'info', $this->logFile);
            if (!isset($sqsData['queued_task_id']) || !$queuedTaskHelper->checkQueuedTaskExists($sqsData['queued_task_id'])) {
                /** check if queued task is present of sqs data */
                return true;
            }

            $this->init($sqsData['data']);
            $remoteError = false;
            $isLastPage = false;
            $products = [];
            $limitDueToFailProd = false;
            $sourceProductIds = [];
            $failedProducts = [];
            if (isset($sqsData['isLastPage']) && $sqsData['isLastPage']) {
                $isLastPage = true;
            }

            $failedProductCount = 0;
            if (isset($sqsData['failed_source_product']) && !empty($sqsData['failed_source_product'])) {
                /** source_product_ids that is failed in previous batch (throttling error) */
                $failedProducts = $sqsData['failed_source_product'];
                if (!empty($failedProducts)) {
                    foreach ($failedProducts as $barcodeArr) {
                        foreach ($barcodeArr as $product) {
                            $failedProductCount += count($product);
                        }
                    }
                }
            }
            $processOnlyFailed = false;
            if ($failedProductCount > self::DEFAULT_COUNT_PROCESS_DIRECTLY) {
                $processOnlyFailed = true;
            }


            $isLastChunk = true;
            if (!$processOnlyFailed) { //to skip other processes
                if (isset($sqsData['data']['source_product_ids']) && !empty($sqsData['data']['source_product_ids'])) {
                    $limit = isset($sqsData['data']['limit']) ? (int)$sqsData['data']['limit'] : self::DEFAULT_QUERY_LIMIT;
                    if (count($sqsData['data']['source_product_ids']) > $limit) {
                        $sourceProductIds = array_slice($sqsData['data']['source_product_ids'], 0, $limit); //to get the first limit size source_product_ids

                        array_splice($sqsData['data']['source_product_ids'], 0, $limit); //to remove the limit ids from the sqs data

                        $products = $this->getProducts(false, false, $sourceProductIds);
                        $isLastPage = false;
                        $sqsData['data']['page'] = $sqsData['data']['page'] + 1;
                    } else {
                        $products = $this->getProducts(false, false, $sqsData['data']['source_product_ids']);
                        $isLastPage = true;
                    }
                } elseif (!$isLastPage) {
                    $skip = isset($sqsData['data']['page']) ? (int)$sqsData['data']['page'] : 0;
                    // $skip = isset($sqsData['data']['skip']) ? (int)$sqsData['data']['skip'] : 0;
                    $limit = isset($sqsData['data']['limit']) ? (int)$sqsData['data']['limit'] : self::DEFAULT_QUERY_LIMIT;
                    $skip = $skip * $limit;
                    // $limit = $limitDueToFailProd = ($failedProductCount < $limit) ? ($limit - $failedProductCount) : $failedProductCount;
                    $products = $this->getProducts($limit, $skip);
                    $productCount = count($products);
                    if ($productCount >= $limit) {
                        /** if product count is >= limit, then prosseing required for next page : bulk look-up */
                        $isLastPage = false;
                        $sqsData['data']['page'] = $sqsData['data']['page'] + 1;
                        // $sqsData['data']['skip'] = (int)$sqsData['data']['skip'] + $limit;
                    } else {
                        $sqsData['isLastPage'] = true;
                        $isLastPage = true;
                    }
                }
            }

            if (!empty($products) || !empty($failedProducts)) {
                $response = $this->processLookup($products, $failedProducts);
                if (isset($response['remote_server_error']) && $response['remote_server_error']) {
                    $remoteError = true;
                    $response = [];
                    $isLastPage = true;
                }
            }

            $counter = (int)($sqsData['data']['counter'] ?? 0);
            if (!isset($sqsData['queued_task_id'])) $isLastPage = true;

            $severity = 'success';
            $message = self::LOOKUP_COMPLETED_SUCCESS_MESSAGE;
            if ($isLastPage) {
                if (isset($response['failed_source_product']) && !empty($response['failed_source_product'])) {
                    if ($counter < 3) {
                        /** try failed_source_product (throttle) atmost 3 times */
                        $sqsData['failed_source_product'] = $response['failed_source_product'] ?? [];
                        $sqsData['data']['counter'] = $counter + 1;
                        $sqsData['delay'] = self::THROTTLE_DELAY_TIME;
                        $this->di->getMessageManager()->pushMessage($sqsData);
                        return true;
                    }
                    $severity = 'notice';
                    $message = self::LOOKUP_COMPLETED_PARTIALLY_MESSAGE;
                }

                /** update look-up complete */
                if (isset($sqsData['queued_task_id'])) {
                    if ($remoteError) {
                        $message = self::LOOKUP_COMPLETED_ERROR_MESSAGE;
                        $severity = 'critical';
                    }
                    /** remove queued task with 100 success */
                    $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['queued_task_id'], 100, $message);
                }

                /** add notification data in overview */
                $notificationData = [
                    'message' => $message,
                    'severity' => $severity,
                    'user_id' => $this->userId,
                    'marketplace' => $this->targetMarketplace,
                    'appTag' => $this->di->getAppCode()->getAppTag(),
                    'process_code' => 'amazon_product_search'
                ];
                $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($this->targetShopId, $notificationData);
            } else {
                /** update progress in queued task */
                if (!$processOnlyFailed) {
                    $processed = round(($limit * $sqsData['data']['page']) / $sqsData['individual_weight'] * 100, 2);
                    $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['queued_task_id'], $processed, '', false);
                    if ($processed >= 100) {
                        $notificationData = [
                            'message' => $message,
                            'severity' => $severity,
                            'user_id' => $this->userId,
                            'marketplace' => $this->targetMarketplace,
                            'appTag' => $this->di->getAppCode()->getAppTag(),
                            'process_code' => 'amazon_product_search'
                        ];
                        $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($this->targetShopId, $notificationData);
                        return true;
                    }
                }
                /** add throttled souce_product_ids in queue */
                if (isset($response['failed_source_product']) && !empty($response['failed_source_product'])) {
                    $sqsData['failed_source_product'] = $response['failed_source_product'];
                    $sqsData['delay'] = self::THROTTLE_DELAY_TIME;
                } elseif (isset($response['next_chunk']) && $response['next_chunk']) {
                    $sqsData['delay'] = 10;
                } else {
                    unset($sqsData['delay']);
                    unset($sqsData['failed_source_product']);
                }

                /** push data in queue for next skip : bulk look-up */
                $this->di->getMessageManager()->pushMessage($sqsData);
            }

            return true;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('processSqsData : ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true), 'info', 'exception.log');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function searchProductOnAmazon($barcodeValidatedProducts, $existedOfferProducts)
    {
        try {
            $lastSuccessRequestTime = null;
            $lastThrottleTime = null;
            $returnArray = [];
            $failedProductsDueToThrottle = [];
            if (!empty($barcodeValidatedProducts)) {
                $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                $amazonShop = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')->getShop($this->targetShopId, $this->userId);
                // $bulkMarketplaceUpdateData = [];
                $bulkUpdateEditedData = [];
                $isApiExhaust = false;
                foreach ($barcodeValidatedProducts as $barcodeType => $barcodesArray) {
                    // 60 barcode chunks to process in one api call
                    $productChunks = array_chunk($barcodesArray, self::DEFAULT_CHUNK_SIZE_TO_SEND_ON_AMAZON, true);
                    foreach ($productChunks as $barcodeArr) {
                        if ($isApiExhaust) {
                            $currentTime = microtime(true);
                            //to handle the process if we can try for one more time for the lookup call for the next chunk
                            if (($currentTime - $lastThrottleTime) > 1.0) {
                                $isApiExhaust = false; //turning it true will make the next chunks available for the requests in the same processing
                            }
                        }
                        if (!$isApiExhaust) {
                            $specifics = [
                                'id' => array_keys($barcodeArr),
                                'shop_id' => $amazonShop['remote_shop_id'],
                                'home_shop_id' => $this->targetShopId,
                                'id_type' => $barcodeType,
                                'included_data' => 'identifiers,summaries'
                            ];
                            $response = $commonHelper->sendRequestToAmazon('product', $specifics, 'GET');

                            if (isset($response['throttled']) && $response['throttled']) {
                                $lastThrottleTime = microtime(true);
                                $timeToSleep = 1; //in seconds
                                if (!is_null($lastSuccessRequestTime)) {
                                    $diff = ($lastThrottleTime - $lastSuccessRequestTime);
                                    if ($diff < 1) {
                                        $timeToSleep = ceil(1 - $diff);
                                    }
                                }
                                //throttled received from remote, re-attempt one more time
                                usleep($timeToSleep * 1000000); //to sleep for microsecons where 1 sec = 1000000ms

                                $response = $commonHelper->sendRequestToAmazon('product', $specifics, 'GET');
                                if (isset($response['throttled']) && $response['throttled']) {
                                    $lastThrottleTime = microtime(true);
                                    $timeToSleep = 1;
                                    if (!is_null($lastSuccessRequestTime)) {
                                        $diff = ($lastThrottleTime - $lastSuccessRequestTime);
                                        if ($diff < 1) {
                                            $timeToSleep = ceil(1 - $diff);
                                        }
                                    }
                                    usleep($timeToSleep * 1000000);
                                    $response = $commonHelper->sendRequestToAmazon('product', $specifics, 'GET');
                                }
                            }
                            if (isset($response['throttled']) && $response['throttled']) {
                                //if all the above cases fail we will add the remaining products in the failed array so that no products should miss and continue in next handling
                                if (!isset($failedProductsDueToThrottle[$barcodeType])) {
                                    $failedProductsDueToThrottle[$barcodeType] = $barcodeArr;
                                } else {
                                    $failedProductsDueToThrottle[$barcodeType] = $failedProductsDueToThrottle[$barcodeType] + $barcodeArr;
                                }
                                //throttled received from remote, terminate whole batch and mark left as failed
                                $isApiExhaust = true; //to terminate from the request call for sometime
                                $lastThrottleTime = microtime(true); //to track the last throttle time so that we can try it in teh same call
                            }
                            if (!isset($response['throttled'])) {
                                $lastSuccessRequestTime = microtime(true); //to track the success time so that we can add handling of throotle via sleep for the chunk
                                !is_null($lastThrottleTime) && $lastThrottleTime = null; //so that we can handle teh next throttle
                            }
                            if (isset($response['success'], $response['response']) && $response['success']) {
                                foreach ($response['response'] as $barcode => $data) {
                                    //unset barcode found on amazon
                                    unset($barcodeValidatedProducts[$barcodeType][$barcode]);
                                    $productsData = $barcodeArr[$barcode] ?? false;
                                    if ($productsData && isset($data['asin'])) {
                                        $asin = $data['asin'];
                                        // Explicitly check if multioffer key exists and is true
                                        $multiOffer = (isset($data['multioffer']) && $data['multioffer'] == 'true') ? 'true' : 'false';

                                        // loop for product in case multiple product have same barcode
                                        foreach ($productsData as $productData) {
                                            if (isset($productData['source_product_id'], $productData['container_id'])) {
                                                //check if offer status already applied, and asin is same then skip
                                                if (
                                                    isset($existedOfferProducts[$productData['source_product_id']]) &&
                                                    $existedOfferProducts[$productData['source_product_id']]['asin'] == $asin
                                                ) {
                                                    // skip updating product that already have asin assigned
                                                    continue;
                                                }

                                                $configData =  $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
                                                $appTag = 'amazon_sales_channel';
                                                $configData->setAppTag($appTag);
                                                $configData->setGroupCode('product');
                                                $configData->setUserId($this->di->getUser()->id);
                                                $configData->sourceSet('shopify');
                                                $configData->setTarget('amazon');
                                                $configData->setSourceShopId($this->sourceShopId);
                                                $configData->setTargetShopId($this->targetShopId);
                                                $config = $configData->getConfig('auto_offer_listing_creation');
                                                if ( !empty($config)) {
                                                    $config = $config['0']['value'] ?? [];
                                                    $config = json_decode(json_encode($config), true);
                                                    if ( !empty($config) && isset($config['category_settings']) && $config['enabled'] == true) {
                                                        $category_mapping = $config['category_settings'];
                                                    } 
                                                }

                                                $asinData = [
                                                    'target_marketplace' => 'amazon',
                                                    'shop_id' => (string) $this->targetShopId,
                                                    'container_id' => (string)$productData['container_id'],
                                                    'source_product_id' => (string)$productData['source_product_id'],
                                                    'asin' => $asin,
                                                    'multioffer' => $multiOffer,
                                                    'status' => $this::offerStatus,
                                                    'source_shop_id' => $this->sourceShopId,
                                                    'unset' => [
                                                        'category_settings' => 1,
                                                        'dont_validate_barcode' => 1,
                                                        'parent_listing_type' => 1,
                                                        'edited_variant_jsonFeed' => 1,
                                                        'variation_theme_fields' => 1
                                                    ]
                                                ];

                                                if (isset($category_mapping) && !empty($category_mapping)) {
                                                    $asinData['default_category_settings'] = $category_mapping;
                                                }
                                                
                                                array_push($bulkUpdateEditedData, $asinData);
                                            }
                                        }
                                    }
                                }
                            } else {
                                $returnArray['remote_server_error'] = true;
                                return $returnArray;
                            }
                        } else {
                            if (!isset($failedProductsDueToThrottle[$barcodeType])) {
                                $failedProductsDueToThrottle[$barcodeType] = $barcodeArr;
                            } else {
                                $failedProductsDueToThrottle[$barcodeType] = $failedProductsDueToThrottle[$barcodeType] + $barcodeArr;
                            }
                        }
                    }
                }
                if (!empty($failedProductsDueToThrottle)) {
                    $returnArray['failedSourceProducts'] = $failedProductsDueToThrottle;
                }
                if (!empty($barcodeValidatedProducts)) {
                    // remove offer status from products that not found on amazon
                    foreach ($barcodeValidatedProducts as $barcodeType => $barcodeArr) {
                        foreach ($barcodeArr as $barcode => $products) {
                            foreach ($products as $product) {
                                if (isset($product['source_product_id'], $existedOfferProducts[$product['source_product_id']]) && !isset($returnArray['failedSourceProducts'][$barcodeType][$barcode])) {
                                    $asinData = [
                                        'target_marketplace' => $this->targetMarketplace,
                                        'shop_id' => $this->targetShopId,
                                        'container_id' => (string)$product['container_id'],
                                        'source_product_id' => (string)$product['source_product_id'],
                                        'source_shop_id' => $this->sourceShopId,
                                        'unset' => [
                                            'multioffer' => 1,
                                            'asin' => 1,
                                            'status' => 1,
                                            'category_settings' => 1
                                        ]
                                    ];
                                    array_push($bulkUpdateEditedData, $asinData);
                                }
                            }
                        }
                    }
                }
                if (!empty($bulkUpdateEditedData)) {
                    $additionalData['source'] = [
                        'marketplace' => $this->sourceMarketplace,
                        'shopId' => (string)$this->sourceShopId
                    ];
                    $additionalData['target'] = [
                        'marketplace' => $this->targetMarketplace,
                        'shopId' => (string)$this->targetShopId
                    ];
                    /** update data in edited doc */
                    $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                    $helper->saveProduct($bulkUpdateEditedData, $this->userId, $additionalData);
                }
            }

            return $returnArray;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('searchProductOnAmazon : ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true), 'info', 'exception.log');
            throw $e;
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** prepare sqs handler data for process - look-up */
    public function getSqsDataForLookUp()
    {
        $appCode = $this->di->getAppCode()->get();
        $appTag = $this->di->getAppCode()->getAppTag();
        $processTag = $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getSearchOnAmazonQueueName();
        return [
            'type' => 'full_class',
            'class_name' => \App\Amazon\Components\Product\Lookup::class,
            'method' => 'processSqsData',
            'appCode' => $appCode,
            'appTag' => $appTag,
            'queue_name' => $processTag,
            'user_id' => $this->userId,
            'data' => [
                'source_marketplace' => [
                    'source_shop_id' => $this->sourceShopId,
                    'marketplace' => $this->sourceMarketplace
                ],
                'target_marketplace' => [
                    'target_shop_id' => $this->targetShopId,
                    'marketplace' => $this->targetMarketplace
                ],
                'user_id' => $this->userId,
                'page' => 0,
                // 'skip' => 0,
                'limit' => self::DEFAULT_QUERY_LIMIT
            ]
        ];
    }

    public function getProducts($limit = false, $skip = false, $ids = [], $getCount = false)
    {
        try {
            if (!$limit) {
                $limit = self::DEFAULT_QUERY_LIMIT;
            }
            if (!$skip) {
                $skip = 0; //need to remove this skip and limit in case we have $ids
            }
            $matchQueryPipe1 = [];
            if (!empty($ids)) {
                $matchQueryPipe1 = [
                    'user_id' => $this->userId,
                    'type' => 'simple',
                    'shop_id' => $this->sourceShopId,
                    '$or' => [
                        ['container_id' => ['$in' => $ids]],
                        ['source_product_id' => ['$in' => $ids]]
                    ]
                ];
                $pipeline1 = [
                    [
                        '$match' => [
                            'user_id' => $this->userId,
                            'type' => 'simple',
                            'shop_id' => $this->sourceShopId,
                            '$or' => [
                                ['container_id' => ['$in' => $ids]],
                                ['source_product_id' => ['$in' => $ids]]
                            ]
                        ]
                    ],
                    ['$group' => [
                        '_id' => '$source_product_id',
                        'parent' => ['$first' => [
                            '$cond' => [
                                'if' => ['$eq' => ['$shop_id', $this->sourceShopId]],
                                'then' => [
                                    'user_id' => '$user_id',
                                    'shop_id' => '$shop_id',
                                    'container_id' => '$container_id', // Adjusted key based on your schema
                                    'source_marketplace' => '$source_marketplace',
                                    'barcode' => '$barcode'
                                ],
                                'else' => []
                            ]
                        ]]
                    ]]
                ];
            } else {
                $matchQueryPipe1 = [
                    'user_id' => $this->userId,
                    'type' => 'simple',
                    'shop_id' => $this->sourceShopId
                ];
                $pipeline1 = [
                    [
                        '$match' => [
                            'user_id' => $this->userId,
                            'type' => 'simple',
                            'shop_id' => $this->sourceShopId
                        ]
                    ],
                    [
                        '$skip' => $skip
                    ],
                    [
                        '$limit' => $limit
                    ],
                    ['$group' => [
                        '_id' => '$source_product_id',
                        'parent' => ['$first' => [
                            '$cond' => [
                                'if' => ['$eq' => ['$shop_id', $this->sourceShopId]],
                                'then' => [
                                    'user_id' => '$user_id',
                                    'shop_id' => '$shop_id',
                                    'container_id' => '$container_id', // Adjusted key based on your schema
                                    'source_marketplace' => '$source_marketplace',
                                    'barcode' => '$barcode'
                                ],
                                'else' => []
                            ]
                        ]]
                    ]]
                ];
            }

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('product_container');
            if ($getCount) {
                return $collection->countDocuments($matchQueryPipe1);
            }
            $sourceProducts = $collection->aggregate($pipeline1, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            $sourceProducts = $sourceProducts->toArray();
            $sourceProductIds = array_column($sourceProducts, '_id');
            if (!empty($sourceProducts)) {
                $pipeline2 = [
                    [
                        '$match' => [
                            'user_id' => $this->userId,
                            'source_product_id' => ['$in' => $sourceProductIds],
                            'shop_id' => $this->targetShopId
                        ]
                    ],
                    ['$group' => [
                        '_id' => '$source_product_id',
                        'edited' => ['$first' => [
                            '$cond' => [
                                'if' => ['$eq' => ['$shop_id', $this->targetShopId]],
                                'then' => [
                                    'user_id' => '$user_id',
                                    'shop_id' => '$shop_id',
                                    'container_id' => '$container_id', // Adjusted key based on your schema
                                    'target_marketplace' => '$target_marketplace',
                                    'barcode' => '$barcode',
                                    'asin' => '$asin',
                                    'status' => '$status',
                                    'multioffer' => '$multioffer'
                                ],
                                'else' => []
                            ]
                        ]]
                    ]]
                ];

                $targetProducts = $collection->aggregate($pipeline2, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
                $targetProducts = $targetProducts->toArray();
                $combinedArray = [];
                if (!empty($targetProducts)) {
                    foreach ($targetProducts as $targetProduct) {
                        $combinedArray[(string)$targetProduct['_id']]['edited'] = $targetProduct['edited'] ?? [];
                    }
                }
                foreach ($sourceProducts as $sourceProduct) {
                    $combinedArray[(string)$sourceProduct['_id']]['parent'] = $sourceProduct['parent'] ?? [];
                }
                return  $combinedArray;
            }
            return [];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('getProducts : ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true), 'info', 'exception.log');
            return [];
        }
    }
    public function initiateMultiOfferLookup($data)
    {
        try {
            $this->logFile = 'amazon/multiOfferLookup/'.date('Y-m-d').'.log';
            
            if (!isset($data['source_product_id']) || empty($data['source_product_id'])) {
                return ['success' => false, 'message' => 'Source product id is required'];
            }
            
            $sourceProductId = $data['source_product_id'];
            
            // Get the product variant
            $variants = $this->getProducts(false, false, [$sourceProductId]);
            
            if (empty($variants) || !isset($variants[$sourceProductId])) {
                return ['success' => false, 'message' => 'Product not found'];
            }
            
            $variant = $variants[$sourceProductId];
            // Check if multioffer flag exists in edited
            if (!isset($variant['edited']['multioffer']) || $variant['edited']['multioffer'] !== 'true') {
                return ['success' => false, 'message' => 'This product does not support multi-offer listings.'];
            }
            
            // Get barcode validated products
            $result = $this->getBarcodeValidateProducts($variants);
            $barcodeValidatedProducts = $result['barcodeValidatedProducts'] ?? [];
            
            if (empty($barcodeValidatedProducts)) {
                return ['success' => false, 'message' => 'Invald Barcode'];
            }
            
            // Call searchProductOnAmazon with multioffer flag
            $response = $this->searchMultiOfferProductOnAmazon($barcodeValidatedProducts, $variant);
            
            return $response;
            
        } catch (Exception $e) {
            $this->di->getLog()->logContent('initiateMultiOfferLookup : ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true), 'info', 'exception.log');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Search for multi-offer products on Amazon
     * Returns all products matching the identifier instead of just one
     */
    public function searchMultiOfferProductOnAmazon($barcodeValidatedProducts, $variant)
    {
        try {
            $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
            $amazonShop = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')->getShop($this->targetShopId, $this->userId);
            $allResults = [];
            
            // For multi-offer, we have only ONE product with ONE barcode, so no chunking needed
            foreach ($barcodeValidatedProducts as $barcodeType => $barcodesArray) {
                // Get all barcode IDs directly (typically just one barcode)
                $barcodeIds = array_keys($barcodesArray);
                
                $specifics = [
                    'id' => $barcodeIds,
                    'shop_id' => $amazonShop['remote_shop_id'],
                    'home_shop_id' => $this->targetShopId,
                    'id_type' => $barcodeType,
                    'included_data' => 'identifiers,summaries',
                    'multioffer' => true  // Pass multioffer flag
                ];
                
                $response = $commonHelper->sendRequestToAmazon('product', $specifics, 'GET');
                
                if (isset($response['success']) && $response['success'] && isset($response['response'])) {
                    // Collect all results (should be multiple products with same barcode)
                    $allResults = array_merge($allResults, $response['response']);
                }
            }
            
            // Check if only one product was found - no multi-offer exists or there can be a case lookup is not initiated and barcode is changed. not 
            if (count($allResults) <= 1) {
                return [
                    'success' => false,
                    'message' => 'No multi-offer products are available for this barcode.'
                ];
            }
            
            // Mold the data to include only asin and itemName
            $moldedData = [];
            foreach ($allResults as $item) {
                $moldedItem = [
                    'asin' => $item['asin'] ?? '',
                    'itemName' => $item['summaries'][0]['itemName'] ?? ''
                ];
                $moldedData[] = $moldedItem;
            }
            
            return [
                'success' => true,
                'message' => 'Multi-offer products fetched successfully',
                'data' => $moldedData
                // 'count' => count($moldedData)
            ];
            
        } catch (Exception $e) {
            $this->di->getLog()->logContent('searchMultiOfferProductOnAmazon : ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true), 'info', 'exception.log');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getLookupData($data)
    {
        try {
            if (!empty($data['user_id']) && !empty($data['source_product_id']) && !empty($data['asin'])) {
                // Prepare asinData structure
                $objectManager = $this->di->getObjectManager();
                $userId = $data['user_id'];
                $mongo = $objectManager->create('\App\Core\Models\BaseMongo');
                $productContainer = $mongo->getCollectionForTable('product_container');
                $productContainerData = $productContainer->findOne([
                    'user_id' => $userId,
                    'source_product_id' => (string) $data['source_product_id'],
                    'shop_id' => $data['target_shop_id'] ?? (string) $this->targetShopId
                ]);
                if (empty($productContainerData)) {
                    return ['success' => false, 'message' => 'Product not found'];
                }

                $asinData = [
                    [
                        'target_marketplace' => 'amazon',
                        'shop_id' => (string) $this->targetShopId,
                        'source_product_id' => (string) $data['source_product_id'] ?? (string) $productContainerData['source_product_id'],
                        'container_id' => (string) $productContainerData['container_id'],
                        'asin' => (string) $data['asin'],
                        'status' => $this::offerStatus,
                        'source_shop_id' => (string) $this->sourceShopId,
                        'unset' => [
                            'category_settings' => 1,
                            'dont_validate_barcode' => 1,
                            'parent_listing_type' => 1,
                            'edited_variant_jsonFeed' => 1,
                            'variation_theme_fields' => 1
                        ]
                    ]
                ];

                // Prepare additional data
                $additionalData = [];
                $additionalData['source'] = [
                    'marketplace' => $this->sourceMarketplace,
                    'shopId' => (string) $this->sourceShopId
                ];
                $additionalData['target'] = [
                    'marketplace' => $this->targetMarketplace,
                    'shopId' => (string) $this->targetShopId
                ];
                // Save product data
                $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                $helper->saveProduct($asinData, $userId, $additionalData);


                return [
                    'success' => true,
                    'message' => 'Lookup data saved successfully',
                ];
            } else {
                return ['success' => false, 'message' => 'Params are missing'];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('getLookupData : ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true), 'info', 'exception.log');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
