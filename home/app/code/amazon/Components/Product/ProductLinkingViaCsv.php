<?php

namespace App\Amazon\Components\Product;

use App\Core\Components\Base;
use App\Connector\Models\QueuedTasks;
use App\Amazon\Components\ProductHelper;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Models\SourceModel;
use Aws\S3\S3Client;
use App\Connector\Models\QueuedTasks as ConnectorQueuedTasks;
use Exception;

class ProductLinkingViaCsv extends Base
{

    private $mongo;
    private $productContainer;
    private $refineContainer;
    private $queueTasksContainer;
    const S3_URL_VALIDITY = "+72 hour";
    const ALLOWED_FILE_TYPE = 'text/csv';

    /**
     * Initializes MongoDB collections used in this class.
     */
    public function initiate()
    {
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->productContainer = $this->mongo->getCollectionForTable('product_container');
        $this->refineContainer = $this->mongo->getCollectionForTable('refine_product');
        $this->queueTasksContainer = $this->mongo->getCollection("queued_tasks");
    }

    /**
     * bulkManualLinkingCSV function
     * Preparing the data for initiating the bulk linking process via CSV
     * @return string
     */
    public function bulkManualLinkingCSV($data)
    {
        try {
            $this->initiate();
            $file = $data['file'] ?? '';
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $sourceShopId = $this->di->getRequester()->getSourceId();
            $appTag = $this->di->getAppCode()->getAppTag();
            $targetShopId = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
            $sourceMarketplace =  $this->di->getRequester()->getSourceName() ?? 'shopify';
            $targetMarketplace =  $this->di->getRequester()->getTargetName() ?? 'amazon';
            $logFile = "amazon/ProductLinkingviaCSV/$userId/" . date('d-m-Y') . '.log';
            $microtime=microtime(true);
            $inputFilePath = BP . DS . "var/file/$userId/product_linking_$microtime.csv";
            if (!$sourceShopId || !$targetShopId) {
                return ['success' => false, 'message' => 'Required all params.'];
            }

            $response = $this->checkExistingQueuedTask($userId, $targetShopId, 'bulk_linking_via_csv');
            if (isset($response['success']) && !$response['success']) {
                return $response;
            }

            if (empty($data['sku']) && empty($data['VariantId'])) {
                return ['success' => false, 'message' => 'Either sku or VariantId must be true'];
            }

            if (!empty($file) && $file->getType() == self::ALLOWED_FILE_TYPE) {
                $file->moveTo($inputFilePath);
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => '\App\Amazon\Components\Product\ProductLinkingViaCsv',
                    'method' => 'initateBulkManualMappingProcess',
                    'user_id' => $userId,
                    'shop_id' => $targetShopId,
                    'source_shop_id' => $sourceShopId,
                    'marketplace' => $targetMarketplace,
                    'queue_name' => 'linking_via_csv',
                    'message' => "Product Linking Via CSV inititated",
                    'process_code' => 'bulk_linking_via_csv',
                    'appTag' => $appTag,

                    'data' => [
                        'file_path' => $inputFilePath,
                        'sku' => $data['sku'] ?? false,
                        'VariantId' => $data['VariantId'] ?? false
                    ]
                ];


                $flagVariantId = $handlerData['data']['VariantId'] ?? false;
                $flagSku = $handlerData['data']['sku'] ?? false;

                if ($flagVariantId) {
                    $response = $this->VerifyCsvDataForVariantId($handlerData);
                    if (isset($response['success']) && !$response['success']) {
                        $message = $response['message'] ?? 'Validation Failed';
                        unlink($inputFilePath);
                        return ['success' => false, 'message' => $message];
                    }
                } elseif ($flagSku) {
                    $response = $this->VerifyCsvDataForSku($handlerData);
                    if (isset($response['success']) && !$response['success']) {
                        $message = $response['message'] ?? 'Validation Failed';
                        unlink($inputFilePath);
                        return ['success' => false, 'message' => $message];
                    }
                } else {
                    unlink($inputFilePath);
                    $message = 'Either VariantId or sku flag must be true';
                    return ['success' => false, 'message' => $message];
                }

                $resultCsv = $this->createResultCsvFile($handlerData);

                if (!empty($resultCsv) && !file_exists($resultCsv['file_path'])) {
                    $message = $resultCsv['message'] ?? 'Error while creating result CSV File';
                    unlink($inputFilePath);
                    return ['success' => false, 'message' => $message];
                }

                $handlerData['data']['result_file_path'] = $resultCsv['file_path'] ?? '';

                $queuedTask = new QueuedTasks;
                $queuedTaskId = $queuedTask->setQueuedTask($targetShopId, $handlerData);
                if (!$queuedTaskId) {
                    return ['success' => false, 'message' => 'Linking Products Via CSV is already under process.Check for updates in Overview section'];
                }
                $handlerData['data']['feed_id'] = $queuedTaskId;
                $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($handlerData);
                $this->di->getLog()->logContent('message pushed to initateBulkManualMappingProcess: '.json_encode($handlerData), 'info', $logFile);
                return ['success' => true, 'message' => 'Linking process inititated'];
            } else {
                return ['success' => false, 'message' => 'Please use .csv file type only.'];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception bulkManualLinkingCSV(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * VerifyCsvDataForVariantId function
     * Sanity check for CSV data based on Variant IDs.
     */
    public function VerifyCsvDataForVariantId($messageData)
    {
        try {
            $csvPath = $messageData['data']['file_path'];
            if (!file_exists($csvPath)) {
                $message = 'File does not exists';
                return ['success' => false, 'message' => $message];
            }

            if (($handle = fopen($csvPath, "r")) !== false) {
                $header = fgetcsv($handle);
                if (!$header) {
                    fclose($handle);
                    $message = 'Required header not found in CSV';
                    return ['success' => false, 'message' => $message];
                }

                $header = array_map('strtolower', $header);
                $amazonIndex = array_search('amazon_sku', $header);
                $variantIndex = array_search('variant_id', $header);

                if ($amazonIndex === false || $variantIndex === false) {
                    fclose($handle);
                    $message = 'Required csv format (amazon_sku / variant_id) not found';
                    return ['success' => false, 'message' => $message];
                }

                // Check if at least one valid data row exists
                while (($row = fgetcsv($handle)) !== false) {
                    if (!empty($row[$amazonIndex]) && !empty($row[$variantIndex])) {
                        fclose($handle);
                        return ['success' => true];
                    }
                }

                fclose($handle);
            }

            $message = 'No valid data found in CSV for processing';
            return ['success' => false, 'message' => $message];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception VerifyCsvDataForVariantId(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * VerifyCsvDataForSku function
     * Sanity check for CSV data based on SKU.
     */
    public function VerifyCsvDataForSku($messageData)
    {
        try {
            $csvPath = $messageData['data']['file_path'];
            if (!file_exists($csvPath)) {
                $message = 'File does not exists';
                return ['success' => false, 'message' => $message];
            }

            if (($handle = fopen($csvPath, "r")) !== false) {
                $header = fgetcsv($handle);
                if (!$header) {
                    fclose($handle);
                    $message = 'Required header not found in CSV';
                    return ['success' => false, 'message' => $message];
                }

                $header = array_map('strtolower', $header);
                $amazonIndex = array_search('amazon_sku', $header);
                $shopifyIndex = array_search('shopify_sku', $header);

                if ($amazonIndex === false || $shopifyIndex === false) {
                    fclose($handle);
                    $message = 'Required csv format (amazon_sku / shopify_sku) not found';
                    return ['success' => false, 'message' => $message];
                }

                // Check if at least one valid data row exists
                while (($row = fgetcsv($handle)) !== false) {
                    if (!empty($row[$amazonIndex]) && !empty($row[$shopifyIndex])) {
                        fclose($handle);
                        return ['success' => true];
                    }
                }

                fclose($handle);
            }

            $message = 'No valid data found in CSV for processing';
            return ['success' => false, 'message' => $message];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception VerifyCsvDataForSku(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * initateBulkManualMappingProcess function
     * Initiates the bulk manual mapping process based on CSV data.
     * Processes the CSV in chunks, updates progress, and handles completion.
     * @return array
     */
    public function initateBulkManualMappingProcess($messageData)
    {
        try {
            $this->initiate();
            if (!empty($messageData['data'])) {
                $userId = $messageData['user_id'] ?? $this->di->getUser()->id;
                $logFile = "amazon/ProductLinkingviaCSV/$userId/" . date('d-m-Y') . '.log';
                $page = $messageData['data']['page'] ?? 1;
                $limit = 250;
                $offset = ($page - 1) * $limit;

                if (!isset($messageData['data']['total_rows'])) {
                    $messageData['data']['total_rows'] = $this->getTotalRowsFromCsv($messageData['data']['file_path']);
                }

                $flagVariantId = $messageData['data']['VariantId'] ?? false;
                $flagSku = $messageData['data']['sku'] ?? false;

                if ($flagVariantId) {
                    $this->di->getLog()->logContent('fetchCsvDataForVariantId: ', 'info', $logFile);
                    $csvData = $this->fetchCsvDataForVariantId($messageData, $offset, $limit);
                }
                if ($flagSku) {
                    $this->di->getLog()->logContent('fetchCsvDataForSku: ', 'info', $logFile);
                    $csvData = $this->fetchCsvDataForSku($messageData, $offset, $limit);
                }

                // Call the unified linking function
                $this->csvlinking($csvData['amazon_sku'], $csvData['amazon_shopify'], $messageData);

                $progress = round((($page * $limit) / $messageData['data']['total_rows']) * 100, 2);

                if (($page * $limit) < $messageData['data']['total_rows']) {

                    $messageData['data']['page'] = $page + 1;
                    $message = "Product Linking is in process";
                    $messageData['message'] = $message;
                    $this->di->getObjectManager()->get(ConnectorQueuedTasks :: class)->updateFeedProgress($messageData['data']['feed_id'], $progress, $message, false);
                    $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($messageData);
                    $this->di->getLog()->logContent('message pushed for chunking: ', 'info', $logFile);
                } else {
                    $res= $this->getTotalRowsFromCsv($messageData['data']['result_file_path']);
                    if($res>=1){
                        $downloadUrl = $this->getDownloadUrlS3($messageData['data']['result_file_path']);
                        $messageData['url']=$downloadUrl;
                        $message = "Product Linking by csv completed successfully.Download the CSV to review any failed linkings";
                        $messageData['message'] = $message;
                    }else {
                        $message = "Product Linking by csv completed successfully.";
                        $messageData['message'] = $message;
                    }
                    $this->addNotification($messageData);
                    $message = "Product Linking by csv completed successfully.";
                    $messageData['message'] = $message;
                    $this->di->getObjectManager()->get(ConnectorQueuedTasks :: class)->updateFeedProgress($messageData['data']['feed_id'], $progress, $message, false);
                    unlink($messageData['data']['file_path']);
                    unlink($messageData['data']['result_file_path']);
                    $this->di->getLog()->logContent('file unlinked -- : ', 'info', $logFile);
                }
            }
            return ['success' => true, 'message' => $message ?? 'Process Completed'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception initateBulkManualMappingProcess(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * createResultCsvFile function
     * Create Result CSV File for a record of failed linking reason
     */
    public function createResultCsvFile($sqsData)
    {
        try {
            $userId = $sqsData['user_id'];
            $flagVariantId = $sqsData['data']['VariantId'] ?? false;
            $flagSku = $sqsData['data']['sku'] ?? false;
            $microtime=microtime(true);
            $resultFilePath = BP . DS . "var/file/$userId/result_product_linking_$microtime.csv";

            // Create directory if not exists
            if (!file_exists($resultFilePath)) {
                $dirname = dirname($resultFilePath);
                if (!is_dir($dirname)) {
                    mkdir($dirname, 0777, true);
                }
            } else {
                unlink($resultFilePath);
            }

            // Determine headers dynamically
            $headers = ['amazon_sku'];
            if ($flagVariantId) {
                $headers[] = 'variant_id';
            } elseif ($flagSku) {
                $headers[] = 'shopify_sku';
            }
            $headers[] = 'reason';
            // Write headers to the CSV
            $handle = fopen($resultFilePath, 'w');
            fputcsv($handle, $headers);
            fclose($handle);

            return [
                'success' => true,
                'message' => 'Result CSV file created successfully.',
                'file_path' => $resultFilePath,
            ];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception createResultCsvFile(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * csvlinking function
     * Unified function to handle product linking via CSV data.
     * Processes Amazon SKUs and Shopify source_product_id / sku to link products.
     * @return array
     */
    private function csvlinking($amazon_sku, $amazonShopify, $sqsData)
    {
        try {
            if (empty($amazon_sku) || empty($amazonShopify) || empty(array_values($amazonShopify))) {
                return;
            }
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $logFile = "amazon/ProductLinkingviaCSV/$userId/" . date('d-m-Y') . '.log';
            $sourceShopId = $sqsData['source_shop_id'] ?? $this->di->getRequester()->getSourceId();
            $target_shop_id = $sqsData['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $sourceMarketplace =  $this->di->getRequester()->getSourceName() ?? 'shopify';
            $targetMarketplace = $this->di->getRequester()->getTargetName() ?? 'amazon';
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $flagVariantId = $sqsData['data']['VariantId'] ?? false;
            $flagSku = $sqsData['data']['sku'] ?? false;

            $resultFilePath = $sqsData['data']['result_file_path'] ?? '';

            $params = [
                'target' => [
                    'marketplace' => $targetMarketplace,
                    'shopId' => $target_shop_id,
                ],
                'source' => [
                    'marketplace' => $sourceMarketplace,
                    'shopId' => $sourceShopId,
                ],
                'activePage' => 1,
                'useRefinProduct' => 1,
            ];

            $amazonCollection = $this->mongo->getCollectionForTable(Helper::AMAZON_LISTING);
            $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
            $productHelper = $this->di->getObjectManager()->get(ProductHelper::class);
            $validator = $this->di->getObjectManager()->get(SourceModel::class);
            $shop = $userDetails->getShop($target_shop_id, $userId);
            $seller_id = $shop['warehouses'][0]['seller_id'];
            $query = [
                'user_id' => $userId,
                'shop_id' => $target_shop_id,
                'seller-sku' => ['$in' => $amazon_sku],
            ];

            // $option = [
            //     'projection' => ['seller-sku' => 1, 'asin1' => 1],
            //     'typeMap'   => ['root' => 'array', 'document' => 'array']
            // ];

            $amzonListingData = $amazonCollection->find($query)->toArray();
            if(empty($amzonListingData)){
                $this->writeResultCsvFile($amazon_sku, $amazonShopify , $sqsData);
                return;
            }

            $amazonSKU = array_column($amzonListingData, 'seller-sku');


            $amazonData = [];
            foreach ($amzonListingData as $value) {
                $itemArray = (array) $value;
                if (isset($itemArray['seller-sku'])) {
                    $amazonData[$itemArray['seller-sku']] = $itemArray;
                }
            }

            $shopifySourceProductIDs = array_values($amazonShopify);

            $query = ['user_id' => $userId, 'shop_id' => $sourceShopId, 'source_product_id' => ['$in' => $shopifySourceProductIDs]];
            $productData = $helper->getproductbyQuery($query);
            $shopifySourceProductIDs = array_column($productData, 'source_product_id');

            if (empty($productData)){
                $this->writeResultCsvFile($amazon_sku, $amazonShopify , $sqsData);
                return;
            }

            $shopifyData = [];
            foreach ($productData as $value) {
                $itemArray = (array) $value;
                if (isset($itemArray['source_product_id'])) {
                    $shopifyData[$itemArray['source_product_id']] = $itemArray;
                }
            }

            $marketplaceQuery = ['user_id' => $userId, 'shop_id' => $target_shop_id, 'source_product_id' => ['$in' => $shopifySourceProductIDs], 'status' => ['$in' => ['Active', 'Inactive', 'Incomplete', 'Submitted']]];
            $option = [
                'projection' => ['source_product_id' => 1, '_id' => 0],
                'typeMap'   => ['root' => 'array', 'document' => 'array']
            ];

            $shopifyTargetData = $helper->getproductbyQuery($marketplaceQuery, $option);

            if (!empty($shopifyTargetData)) {
                $shopifyLinkedSourceProductID = array_column($shopifyTargetData, 'source_product_id');
                $target = true;
            } else {
                $target = false;
            }

            foreach ($amazonShopify as $amazonSku => $shopifyID) {

                if ((in_array($amazonSku, $amazonSKU)) && in_array($shopifyID, $shopifySourceProductIDs)) {

                    if (isset($amazonData[$amazonSku]['type'])) {
                        if ($shopifyData[$shopifyID]['type'] != $amazonData[$amazonSku]['type']) {
                            if ($amazonData[$amazonSku]['type'] == 'simple') {
                                $reason = 'Shopify parent product can not be mapped to Amazon simple/child product.';
                            } else {
                                $reason = 'Shopify simple/child product can not be mapped with parent Amazon product.';
                            }

                            if ($flagVariantId) {
                                $failedRows[] = [
                                    'amazon_sku' => $amazonSku ?? '',
                                    'variant_id' => $shopifyID ?? '',
                                    'reason' => $reason
                                ];
                            } elseif ($flagSku) {
                                $failedRows[] = [
                                    'amazon_sku' => $amazonSku ?? '',
                                    'shopify_sku' => $shopifyData[$shopifyID]['sku'] ?? '',
                                    'reason' => $reason
                                ];
                            }
                            continue;
                        }
                    }

                    if (isset($amazonData[$amazonSku]['matched']) && $amazonData[$amazonSku]['matched'] == true) {
                        $sourceId = $amazonData[$amazonSku]['source_product_id'];
                        if ($sourceId != $shopifyID) {
                            $params['data']['source_product_id'] = $sourceId;
                            $params['data']['unlink_for_csv'] = true;
                            $productHelper->manualUnmap($params);
                        } else {
                            continue;
                        }
                    }

                    if ($target && in_array($shopifyID, $shopifyLinkedSourceProductID)) {
                        $params['data']['source_product_id'] = $shopifyID;
                        $params['data']['unlink_for_csv'] = true;
                        $productHelper->manualUnmap($params);
                    }


                    if (isset($amazonData[$amazonSku]['fulfillment-channel']) && ($amazonData[$amazonSku]['fulfillment-channel'] != 'DEFAULT' && $amazonData[$amazonSku]['fulfillment-channel'] != 'default')) {
                        $channel = 'FBA';
                    } else {
                        $channel = 'FBM';
                    }

                    $asin = '';
                    if (isset($amazonData[$amazonSku]['asin1']) && $amazonData[$amazonSku]['asin1'] != '') {
                        $asin = $amazonData[$amazonSku]['asin1'];
                    } elseif (isset($amazonData[$amazonSku]['asin2']) && $amazonData[$amazonSku]['asin2'] != '') {
                        $asin = $amazonData[$amazonSku]['asin2'];
                    } elseif (isset($amazonData[$amazonSku]['asin3']) && $amazonData[$amazonSku]['asin3'] != '') {
                        $asin = $amazonData[$amazonSku]['asin3'];
                    }

                    $status = $amazonData[$amazonSku]['status'];
                    if (isset($amazonData[$amazonSku]['type']) && $amazonData[$amazonSku]['type'] == 'variation') {
                        $status = 'parent_' . $amazonData[$amazonSku]['status'];
                    }


                    // update asin on product container
                    $asinData = [
                        [
                            'user_id' => $userId,
                            'target_marketplace' => 'amazon',
                            'shop_id' => (string)$target_shop_id,
                            'container_id' => $shopifyData[$shopifyID]['container_id'],
                            'source_product_id' => $shopifyData[$shopifyID]['source_product_id'],
                            'asin' => $asin,
                            'sku' => (string)$amazonSku,
                            "shopify_sku" =>(string) $shopifyData[$shopifyID]['sku'],
                            'matchedwith' => 'manual',
                            'status' => $status,
                            'target_listing_id' => (string)$amazonData[$amazonSku]['_id'],
                            'source_shop_id' => $sourceShopId,
                            'fulfillment_type' => $channel
                        ]
                    ];

                    $valid = $validator->validateSaveProduct($asinData, $userId, $params);
                    if (isset($valid['success']) && !$valid['success']) {
                        $reason = 'Product seller-sku is not unique in marketplace.';
                        if ($flagVariantId) {
                            $failedRows[] = [
                                'amazon_sku' => $amazonSku ?? '',
                                'variant_id' => $shopifyID ?? '',
                                'reason' => $reason
                            ];
                        } elseif ($flagSku) {
                            $failedRows[] = [
                                'amazon_sku' => $amazonSku ?? '',
                                'shopify_sku' =>  $shopifyData[$shopifyID]['sku'] ?? '',
                                'reason' => $reason
                            ];
                        }
                        continue;
                    }

                    $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                    $editHelper->saveProduct($asinData, $userId, $params);

                    $matchedData = [
                        'source_product_id' => $shopifyID,
                        'source_container_id' => $shopifyData[$shopifyID]['container_id'],
                        'matched' => true,
                        'manual_mapped' => true, //Identifies SKU/Barcode/Title gets mapped manually.
                        'matchedwith' => 'manual', //Identifies SKU/Barcode/Title gets mapped manually.
                        'matchedProduct' => [
                            'linking_via_csv' => true,
                            'source_product_id' => $shopifyID,
                            'container_id' => $shopifyData[$shopifyID]['container_id'],
                            'main_image' => $shopifyData[$shopifyID]['main_image'],
                            'title' => $shopifyData[$shopifyID]['title'],
                            'barcode' => $shopifyData[$shopifyID]['barcode'],
                            'sku' => (string)$shopifyData[$shopifyID]['sku'],
                            'shop_id' => $shopifyData[$shopifyID]['shop_id']
                        ]
                    ];
                    $amazonCollection->updateOne(['_id' => $amazonData[$amazonSku]['_id']], ['$unset' => ['closeMatchedProduct' => 1, 'unmap_record' => 1, 'manual_unlinked' => 1, 'manual_unlink_time' => 1], '$set' => $matchedData]);
                    $sqsData['data']['total_linked'] = ($sqsData['data']['total_linked'] ?? 0) + 1;
                    // initiate inventory_sync after linking

                } else {

                    if (!empty($amazonSku) && !empty($shopifyID) && $flagVariantId) {
                        $reason = 'Either amazon_sku or variant_id does not exist in our app';
                        $failedRows[] = [
                            'amazon_sku' => $amazonSku ?? '',
                            'variant_id' => $shopifyID ?? '',
                            'reason' => $reason
                        ];
                    } elseif (!empty($amazonSku) && !empty($shopifyID) && $flagSku) {
                        $reason = 'Either amazon_sku or shopify_sku does not exist in our app';
                        $failedRows[] = [
                            'amazon_sku' => $amazonSku ?? '',
                            'shopify_sku' => $shopifyData[$shopifyID]['sku'] ?? '',
                            'reason' => $reason
                        ];
                    }
                }
            }
            if (!empty($failedRows) && $resultFilePath) {
                $handle = fopen($resultFilePath, 'a');
                foreach ($failedRows as $row) {
                    fputcsv($handle, $row);
                }
                fclose($handle);
            }
            $reportHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\Helper::class);
            $reportHelper->inventorySyncAfterLinking($shopifySourceProductIDs, $params);
            $this->di->getLog()->logContent('Linking by CSV Completed: ', 'info', $logFile);
            return true;
            // return ['success' => true, 'message' => 'Amazon product has been successfully linked with selected Shopify product.'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception csvlinking(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    public function writeResultCsvFile($amazonSku,$amazonShopify , $sqsData)
    {
        try {
            $flagVariantId = $sqsData['data']['VariantId'] ?? false;
            $flagSku = $sqsData['data']['sku'] ?? false;
            $resultFilePath = $sqsData['data']['result_file_path'] ?? '';
            if ($flagVariantId && $resultFilePath ) {
                $handle = fopen($resultFilePath, 'a');

                foreach ($amazonSku as $sku) {
                    if(!empty($sku) && !empty($amazonShopify[$sku])){
                        fputcsv($handle, [$sku, $amazonShopify[$sku], 'Either amazon_sku or shopify_sku/variant_id does not exist in app']);
                    }
                }
                fclose($handle);
            } elseif ($flagSku && $resultFilePath) {
                $handle = fopen($resultFilePath, 'a');

                foreach ($amazonSku as $sku) {
                    if(!empty($sku) && !empty($amazonShopify[$sku])){
                        fputcsv($handle, [$sku, $amazonShopify[$sku], 'Either amazon_sku or shopify_sku/variant_id does not exist in app']);
                    }
                }
                fclose($handle);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception writeResultCsvFile(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * fetchCsvDataForVariantId function
     * Prepares and fetches CSV data based on Variant IDs.
     * Make Key: amazon_sku, Value: variant_id in amazon_shopify array
     * return array ['amazon_sku'=>[], 'amazon_shopify'=>[]]
     */
    private function fetchCsvDataForVariantId($messageData, $offset, $limit)
    {
        try {
            $amazon_sku = [];
            $variant_id = [];
            $csvPath = $messageData['data']['file_path'];

            if (($handle = fopen($csvPath, "r")) !== false) {
                $header = fgetcsv($handle);
                $header = array_map('strtolower', $header);
                $amazonIndex = array_search('amazon_sku', $header);
                $variantIndex = array_search('variant_id', $header);

                $currentRow = 0;
                while (($row = fgetcsv($handle)) !== false) {
                    if ($currentRow >= $offset && count($amazon_sku) < $limit) {
                        $amazon_sku[] = $row[$amazonIndex];
                        $variant_id[$row[$amazonIndex]] = $row[$variantIndex];
                    }

                    $currentRow++;
                    if (count($amazon_sku) >= $limit) {
                        break;
                    }
                }

                fclose($handle);
            }

            if (empty(array_filter($amazon_sku)) || empty(array_filter($variant_id))) {
                // $message = 'Invalid Data in CSV for processing';
                return;
            }

            return ['amazon_sku' => $amazon_sku, 'amazon_shopify' => $variant_id];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception fetchCsvDataForVariantId(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * fetchCsvDataForSku function
     * Prepares and fetches CSV data based on Shopify SKUs.
     * Make Key: amazon_sku, Value: shopify_sku in amazon_shopify array
     * return array ['amazon_sku'=>[], 'amazon_shopify'=>[]]
     */
    private function fetchCsvDataForSku($messageData, $offset, $limit)
    {
        try {
            $amazon_sku = [];
            $shopify_sku = [];
            $csvPath = $messageData['data']['file_path'];
            $userId = $messageData['user_id'] ?? $this->di->getUser()->id;
            $logFile = "amazon/ProductLinkingviaCSV/$userId/" . date('d-m-Y') . '.log';
            $resultFilePath = $messageData['data']['result_file_path'] ?? '';

            if (($handle = fopen($csvPath, "r")) !== false) {
                $header = fgetcsv($handle);
                $header = array_map('strtolower', $header);
                $amazonIndex = array_search('amazon_sku', $header);
                $shopifyIndex = array_search('shopify_sku', $header);


                $rowsToProcess = [];
                $currentRow = 0;
                while (($row = fgetcsv($handle)) !== false) {
                    if ($currentRow >= $offset && count($rowsToProcess) < $limit) {
                        $rowsToProcess[] = [
                            'amazon_sku' => $row[$amazonIndex],
                            'shopify_sku' => $row[$shopifyIndex]
                        ];
                    }
                    $currentRow++;
                    if (count($rowsToProcess) >= $limit) {
                        break;
                    }
                }

                fclose($handle);
            }
            if (empty($rowsToProcess)) {
                // $message = 'Invalid Data in CSV for processing';
                return;
            }

            // Extract all shopify_skus for bulk query
            $shopifySkus = array_column($rowsToProcess, 'shopify_sku');
            $amazonSkus = array_column($rowsToProcess, 'amazon_sku');

            if (empty(array_filter($shopifySkus)) || empty(array_filter($amazonSkus))) {
                // $message = 'Invalid Data in CSV for processing';
                return;
            }

            // Fetch all source_product_ids in bulk
            $sourceProducts = $this->getSourceProductIdsBySkus($shopifySkus, $messageData);

            // if (empty($sourceProducts)) {
            //     return;
            // }

            foreach ($rowsToProcess as $row) {
                $sku = $row['shopify_sku'];

                if (!empty($row['amazon_sku']) && !empty($row['shopify_sku'])) {

                    if (!empty($sourceProducts) && isset($sourceProducts[$sku])) {
                        $amazon_sku[] = $row['amazon_sku'];
                        $shopify_sku[$row['amazon_sku']] = $sourceProducts[$sku];
                    } else {
                        $reason = 'Shopify SKU does not exists';
                        $failedRows[] = [
                            'amazon_sku' => $row['amazon_sku'] ?? '',
                            'shopify_sku' => $row['shopify_sku'] ?? '',
                            'reason' => $reason
                        ];
                    }
                }
            }

            if (!empty($failedRows) && $resultFilePath) {
                $handle = fopen($resultFilePath, 'a');
                foreach ($failedRows as $row) {
                    fputcsv($handle, $row);
                }
                fclose($handle);
            }
            return ['amazon_sku' => $amazon_sku, 'amazon_shopify' => $shopify_sku];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception fetchCsvDataForSku(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * getSourceProductIdsBySkus function
     * Fetches source_product_ids for given Shopify SKUs in bulk.
     * return array ['sku' => 'source_product_id'] in sourceProducts array
     */
    private function getSourceProductIdsBySkus($shopifySkus, $messageData)
    {
        try {
            $sourceProducts = [];
            $userId = $messageData['user_id'] ?? $this->di->getUser()->id;
            $logFile = "amazon/ProductLinkingviaCSV/$userId/" . date('d-m-Y') . '.log';
            if (empty($shopifySkus)) return $sourceProducts;

            $sourceShopId = $messageData['source_shop_id'] ?? $this->di->getRequester()->getSourceId();
            $userId = $messageData['user_id'] ??  $this->di->getUser()->id;

            $query = [
                'user_id' => $userId,
                'shop_id' => $sourceShopId,
                'sku' => ['$in' => $shopifySkus]
            ];

            $option = [
                'projection' => ['source_product_id' => 1, 'sku' => 1, '_id' => 0],
                'typeMap'   => ['root' => 'array', 'document' => 'array']
            ];

            $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
            $products = $helper->getproductbyQuery($query, $option);

            foreach ($products as $product) {
                $sourceProducts[$product['sku']] = $product['source_product_id'];
            }

            return $sourceProducts;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception getSourceProductIdsBySkus(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    private function getDownloadUrlS3($filePath)
    {
        // $bucketName = 'amazon-product-upload-dev-s3';

        $bucketName = $this->di->getConfig()->get('bulk_linking');
        

        $fileName = basename($filePath);

        $config = include BP . '/app/etc/aws.php';

        $s3Client = new S3Client($config);

        try {
            $s3Client->putObject([
                'Bucket'      => $bucketName,
                'Key'         => $fileName,
                'SourceFile'  => $filePath,
                'ContentType' => 'text/csv',
            ]);

            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucketName,
                'Key' => $fileName
            ]);

            $urlValidity = self::S3_URL_VALIDITY;
            $request = $s3Client->createPresignedRequest($cmd, $urlValidity);

            return (string)$request->getUri();
        } catch (\Exception $e) {
            echo "There was an error uploading the file: " . $e->getMessage() . "\n";
            return null;
        }
    }

    /**
     * getTotalRowsFromCsv function
     * Fetches total number of rows in the CSV file.
     * return int (total rows - 1 for header)
     */
    private function getTotalRowsFromCsv($csvPath)
    {
        try {
            $totalRows = 0;
            if (($handle = fopen($csvPath, "r")) !== false) {
                while (fgetcsv($handle) !== false) {
                    $totalRows++;
                }
                fclose($handle);
            }
            return $totalRows - 1;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception getTotalRowsFromCsv(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * addNotification function
     * Updates the activity log with the status of the linking process.
     * return void
     */

    public function addNotification($messageData)
    {
        try {
            $userId = $this->di->getUser()->id ?? $messageData['user_id'];
            $notificationData = [
                'user_id' => $userId,
                'message' => $messageData['message'] ?? 'Product Linking Via CSV process completed successfully',
                'severity' => 'success',
                'created_at' => date('c'),
                'shop_id' => $messageData['shop_id'],
                'marketplace' => 'amazon',
                'appTag' => $messageData['appTag'],
                'process_code' => $messageData['process_code'] ?? 'bulk_linking_via_csv',
            ];


            if (!empty($messageData['url'])) {
                $notificationData['url'] = $messageData['url'] ?? '';
            }

            $notification = $this->di->getObjectManager()->get('App\Connector\Models\Notifications');
            $notification->addNotification($messageData['shop_id'], $notificationData);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception addNotification(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * checkExistingQueuedTask function
     * Checks for Existing Queued Task
     */
    private function checkExistingQueuedTask($userId, $targetShopId, $processCode)
    {
        try {
            if (!empty($userId) && !empty($targetShopId) && !empty($processCode)) {
                $existingTask = $this->queueTasksContainer->findOne([
                    'user_id' => $userId,
                    'shop_id' => $targetShopId,
                    'process_code' => $processCode,
                ]);
                if (!empty($existingTask)) {
                    return ['success' => false, 'message' => 'Linking Products Via CSV is already under process.Check for updates in Overview section'];
                }
                return ['success' => true, 'message' => 'No existing task found'];
            }else{
                return['success' => false, 'message' => 'Required params are missing'];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception checkExistingQueuedTask(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }
}
