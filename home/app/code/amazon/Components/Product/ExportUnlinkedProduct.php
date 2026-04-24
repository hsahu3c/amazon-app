<?php

namespace App\Amazon\Components\Product;

use App\Core\Components\Base;
use App\Connector\Models\QueuedTasks;
use App\Amazon\Components\ProductHelper;
use App\Amazon\Components\Common\Helper;
use MongoDB\BSON\ObjectID;
use App\Amazon\Models\SourceModel;
use App\Connector\Models\QueuedTasks as ConnectorQueuedTasks;
use Aws\S3\S3Client;
use Exception;

class ExportUnlinkedProduct extends base
{

    const PROCESS_CODE = 'unlinked_product_csv_export';
    const QUEUE_NAME = 'export_unlinked_products';
    const S3_URL_VALIDITY = "+72 hour";
    const ALLOWED_FILE_TYPE = 'text/csv';


    public function exportUnlinkedProductsCSV($data)
    {
        try {
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $sourceShopId = $this->di->getRequester()->getSourceId() ?? $data['source']['shopId'];
            $targetShopId = $this->di->getRequester()->getTargetId() ?? $data['target']['shopId'];
            $logFile = "amazon/ExportProduct/$userId/" . date('d-m-Y') . '.log';

            if (!empty($userId) && !empty($targetShopId)) {

                $sqsData = $this->prepareSqsData($data);
                if (isset($data['seller-sku']) && !empty($data['seller-sku'])) {
                    // Handle specific SKUs export - No chunking needed
                    $exportCsvFile = $this->createResultCsvFile($data);

                    if (!empty($exportCsvFile) && !file_exists($exportCsvFile['file_path'])) {
                        $message = $resultCsv['message'] ?? 'Error while creating result CSV File';
                        return ['success' => false, 'message' => $message];
                    }
    
                    $sqsData['result_file_path'] = $exportCsvFile['file_path'] ?? '';
                    $skus = $data['seller-sku'] ?? [];
                    $this->exportSpecificSkus($skus, $sqsData);
                    return ['success' => true, 'message' => 'Product(s) exported. Download CSV from overview section'];
                }
                $queuedTask = new QueuedTasks;
                $queuedTaskId = $queuedTask->setQueuedTask($targetShopId, $sqsData);
                if (!$queuedTaskId) {
                    return ['success' => false, 'message' => 'Exporting is already under process.Check for updates in Overview section'];
                }

                $exportCsvFile = $this->createResultCsvFile($data);

                if (!empty($exportCsvFile) && !file_exists($exportCsvFile['file_path'])) {
                    $message = $resultCsv['message'] ?? 'Error while creating result CSV File';
                    return ['success' => false, 'message' => $message];
                }

                $sqsData['result_file_path'] = $exportCsvFile['file_path'] ?? '';

                $sqsData['feed_id'] = $queuedTaskId;
                $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($sqsData);
                $this->di->getLog()->logContent('Message Pushed to SQS ..  ', 'info', $logFile);

                // $this->exportAmazonListing($sqsData);

            } else {
                $this->di->getLog()->logContent('Exporting Failed..  ', 'info', $logFile);
                return ['success' => false, 'message' => 'Required Params are missing'];
            }
            return ['success' => true, 'message' => 'Exporting process inititated'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception exportUnlinkedProductsCSV(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }


    public function createResultCsvFile($data)
    {
        try {
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $microtime=microtime(true);
            $resultFilePath = BP . DS . "var/file/$userId/amazon_listing_export_$microtime.csv";

            // Ensure directory exists
            $dirName = dirname($resultFilePath);
            if (!is_dir($dirName)) {
                mkdir($dirName, 0777, true);
            }

            // If file exists, remove it
            if (file_exists($resultFilePath)) {
                unlink($resultFilePath);
            }

            // Define CSV headers
            $headers = ['amazon_sku', 'type', 'product-id', 'item-name', 'variant_id'];

            // Write headers
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
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function writeResultCsvFile($resultFilePath, $amazonData)
    {
        try {
            if (empty($amazonData) || !file_exists($resultFilePath)) {
                return;
            }

            $handle = fopen($resultFilePath, 'a');

            foreach ($amazonData as $row) {
                fputcsv($handle, [
                    $row['seller-sku'] ?? '',
                    $row['type'] ?? '',
                    $row['product-id'] ?? '',
                    $row['item-name'] ?? '',
                    $row['variant_id'] ?? '',
                ]);
            }

            fclose($handle);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception writeResultCsvFile(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    public function exportAmazonListing($data)
    {
        try {

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $amazonListing = $mongo->getCollectionForTable('amazon_listing');
            $sqs = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');

            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $shopId = $data['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $logFile = "amazon/ExportProduct/$userId/" . date('d-m-Y') . '.log';

            $limit = 250;

            // fetch unlinked listings (matched not exists)
            $query = [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'matched' => ['$exists' => false],
            ];

            // Following Shopify pattern for cleaner chunking
            if (!empty($data['last_traversed_object_id'])) {
                // Extract ObjectId string - handle both SQS format and MongoDB ObjectId object
                $objectIdStr = $data['last_traversed_object_id'];


                $amazonData = $amazonListing->find(
                    [
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                        'matched' => ['$exists' => false],
                        '_id' => ['$gt' =>  new ObjectId((string)$objectIdStr)]
                    ],
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'limit' => $limit,
                        'projection' => ['seller-sku' => 1, 'type' => 1, 'product-id' => 1, 'item-name' => 1, 'variant_id' => 1],
                        'sort' => ['_id' => 1]
                    ]
                )->toArray();
            } else {
                $totalAmazonProducts = $amazonListing->countDocuments($query);
                $data['total_count'] = $totalAmazonProducts;
                $data['exported_count'] = 0;

                if ($totalAmazonProducts == 0) {
                    $message = "No Unlinked Product Exists.";
                    $data['message'] = $message;
                    $data['severity'] = 'error';
                    $this->di->getObjectManager()->get(ConnectorQueuedTasks::class)->updateFeedProgress($data['feed_id'], 100, $message, false);
                    $this->addNotification($data);
                    return true;
                }

                $amazonData = $amazonListing->find(
                    $query,
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'limit' => $limit,
                        'projection' => ['seller-sku' => 1, 'type' => 1, 'product-id' => 1, 'item-name' => 1, 'variant_id' => 1],
                        'sort' => ['_id' => 1]
                    ]
                )->toArray();
            }

            $this->di->getLog()->logContent('-- Reached exportAmazonListings --', 'info', $logFile);

            // Process data if found
            if (!empty($amazonData)) {
                // Write this batch to CSV
                $this->writeResultCsvFile($data['result_file_path'], $amazonData);

                // Get last document for pagination with validation
                $lastTraversedObjectId =  (string)end($amazonData)['_id'];


                // Calculate exported count
                $exportedCount = ($data['exported_count'] ?? 0) + count($amazonData);
                $totalCount = $data['total_count'];

                // CONDITION: Only continue if more records need processing
                if ($exportedCount < $totalCount) {
                    // === MORE CHUNKS TO PROCESS ===

                    // 1. Calculate and update progress
                    $progress = round(($exportedCount / $totalCount) * 100, 2);
                    $this->di->getObjectManager()->get(ConnectorQueuedTasks::class)->updateFeedProgress($data['feed_id'], $progress, "Exported {$exportedCount} of {$totalCount} unlinked listings", false);

                    // 2. Prepare next batch data

                    $nextBatch = [
                        'type' => 'full_class',
                        'class_name' => self::class,
                        'method' => 'exportAmazonListing',
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                        'result_file_path' => $data['result_file_path'],
                        'total_count' => $totalCount,
                        'exported_count' => $exportedCount,
                        'last_traversed_object_id' => $lastTraversedObjectId,
                        'feed_id' => $data['feed_id'] ?? '',
                        'queue_name' => self::QUEUE_NAME,
                    ];


                    // 3. Push next chunk for processing
                    $sqs->pushMessage($nextBatch);
                    $this->di->getLog()->logContent('--Message Pushed for Chunking--', 'info', $logFile);

                    // $this->exportAmazonListing($nextBatch);
                } else {
                    // === FINAL CHUNK - COMPLETE EXPORT ===

                    // 1. FIRST: Upload CSV to S3
                    $s3Url = $this->getDownloadUrlS3($data['result_file_path']);

                    // 2. THEN: Update final progress based on S3 upload result
                    if ($s3Url) {
                        $data['url'] = $s3Url;
                        $message = "Exporting of Unlinked Products is completed successfully.";
                        $data['message'] = $message;
                    } else {
                        $message = "Csv Export completed, but error occurred while generating download link.";
                        $data['message'] = $message;
                        $data['severity'] = 'error';
                    }
                    $this->di->getObjectManager()->get(ConnectorQueuedTasks::class)->updateFeedProgress($data['feed_id'],  100, $message, false);
                    $this->addNotification($data);
                    $this->di->getLog()->logContent('--Process Completed--', 'info', $logFile);
                    unlink($data['result_file_path']);
                }
            } else {
                // No more data found - complete the export
                $this->di->getLog()->logContent('-- No more data in amazon_listing --', 'info', $logFile);
                $this->di->getObjectManager()->get(ConnectorQueuedTasks::class)->updateFeedProgress($data['feed_id'],  100, "Amazon Listing Export Completed. All data processed.", false);
            }

            return true;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception exportAmazonListing(): ' . print_r($e->getMessage()), 'info',  'exception.log');
            return false;
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


    public function exportSpecificSkus($skus, $data)
    {
        try {
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $shopId = $data['shop_id'] ?? $this->di->getRequester()->getTargetId();
            $csvFilePath = $data['result_file_path'] ?? '';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $amazonListing = $mongo->getCollectionForTable('amazon_listing');
            $logFile = "amazon/ExportProduct/$userId/" . date('d-m-Y') . '.log';


            // Query for specific SKUs
            $query = [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'seller-sku' => ['$in' => $skus],
                'matched' => ['$exists' => false], // Only unlinked products
            ];

            $options = [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'projection' => ['seller-sku' => 1, 'type' => 1, 'product-id' => 1, 'item-name' => 1, 'variant_id' => 1],
                'sort' => ['_id' => 1]
            ];

            $amazonData = $amazonListing->find($query, $options)->toArray();
            $this->di->getLog()->logContent('--Reached exportSpecificSkus--', 'info', $logFile);

            if (!empty($amazonData)) {
                // Write data to CSV
                $this->writeResultCsvFile($csvFilePath, $amazonData);

                // Upload to S3
                $s3Url = $this->getDownloadUrlS3($csvFilePath);

                if ($s3Url) {

                    $data['url'] = $s3Url;
                    $message = "Exporting of Unlinked Products is completed successfully.";
                    $data['message'] = $message;
                } else {

                    $message = "Csv Export completed, but error occurred while generating download link.";
                    $data['message'] = $message;
                    $data['severity'] = 'error';
                }
                $this->addNotification($data);
                unlink($csvFilePath);
                $this->di->getLog()->logContent('--Export for Specific SKU completed--'.json_encode($amazonData), 'info', $logFile);

            } else {
                $data['message'] = "No unlinked products found";
                $data['severity'] = 'error';
                $this->addNotification($data);
                $this->di->getLog()->logContent('-- Data Not found --'.json_encode($amazonData), 'info', $logFile);

            }
            return true;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception exportSpecificSkus(): ' . print_r($e->getMessage()), 'info',  'exception.log');
            return [
                'success' => false,
                'message' => 'Error occurred while exporting specific SKUs: ' . $e->getMessage()
            ];
        }
    }

    private function prepareSqsData($data)
    {
        return [
            'type' => 'full_class',
            'class_name' => self::class,
            'method' => 'exportAmazonListing',
            'user_id' => $this->di->getUser()->id ?? $data['user_id'],
            'shop_id' => $this->di->getRequester()->getTargetId() ?? $data['target_shop_id'],
            'queue_name' => self::QUEUE_NAME,
            'appTag' => $this->di->getAppCode()->getAppTag(),
            'message' => "Exporting of Unlinked Products started",
            'process_code' => self::PROCESS_CODE ?? 'unlinked_product_csv_export',
            'marketplace' => 'amazon',
        ];
    }

    public function addNotification($messageData)
    {
        try {
            $userId = $this->di->getUser()->id ?? $messageData['user_id'];
            $notificationData = [
                'user_id' => $userId,
                'message' => $messageData['message'] ?? 'Exporting of  Unlinked Products is in process',
                'severity' => $messageData['severity'] ?? 'success',
                'created_at' => date('c'),
                'shop_id' => $messageData['shop_id'],
                'marketplace' => 'amazon',
                'appTag' => $messageData['appTag'],
                'process_code' => self::PROCESS_CODE  ?? 'unlinked_product_csv_export',
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
}
