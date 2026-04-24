<?php

namespace App\Connector\Components;

use App\Amazon\Components\Common\Helper;
use App\Connector\Controllers\CsvController;
use App\Connector\Models\ProductContainer;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use PhpOffice\PhpSpreadsheet\Calculation\Financial\Securities\Price;

class CsvHelper extends \App\Core\Components\Base
{
    const PATH_DELIMITER = '|';

    public function getProductDetails($data)
    {
        $userId = $this->di->getUser()->id;
        $target_shop_id = $this->di->getRequester()->getTargetId();
        $app_tag = $this->di->getAppCode()->getAppTag();
        $source = $this->di->getRequester()->getSourceName();
        $target_marketplace = $this->di->getRequester()->getTargetName();
        $source_shop_id = $this->di->getRequester()->getSourceId();
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');

        $selected_product_ids = [];
        $grid_query = $data['filter'] ?? '';
        $data['query'] = $grid_query;
        $exportType = $data['type']; //all,selected,current_page
        if ($exportType == 'selected' || $exportType == 'current') {
            $selected_product_ids = $data['source_product_id'] ?? $data['container_ids'];
        }

        $filterSelected = $data['dataToExport']; //title,sku,barcode,brand,vendor,price,inventory
        $export_limit = $data['count'];

        //$path = $basePath . "bulk_edit_" . $userId . ".csv";
        $basePath = BP . DS . 'var/log/UserProductCSV/';
        $time_stamp = strtotime(date('d-m-Y H:i:s'));
        //"value" => 'ced_{sorce}_{target}_{source_shop_id}_{target_shop_id}_products.csv',
        $file_name = 'ced_' . $time_stamp . '_products.csv';
        $file_path = $basePath . DS . $file_name;

        if (!file_exists($basePath)) {
            mkdir($basePath);
            chmod($basePath, 0777);
        }

        if (!file_exists($file_path)) {
            $file = fopen($file_path, 'w');
            chmod($file_path, 0777);
        } else {
            $file = fopen($file_path, 'w');
        }

        if (!empty($selected_product_ids)) {
            $countOfProducts = count($selected_product_ids);
        } else {
            $containerModel = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');
            //$dataCount = $containerModel->getProductCatalogCount($data);
            $dataCount = $containerModel->getProductsCount([
                'productOnly' => false
            ]);
            $countOfProducts = $dataCount['data']['count'] ?? '';
        }

        $params = $data;

        $mandotaryField = ['container_id', 'source_product_id', 'type', 'variant_title'];
        $params['toExport'] = $filterSelected;

        $csv_columns = array_unique(array_merge($mandotaryField, $params['toExport'] ?? []));
        $csv_columns_names = [];

        $existMethod = false;
        $class = '';
        $possibleClassNames = [
            '\App\\' . ucfirst($target_marketplace) . '\Components\Csv\Helper',
            '\App\\' . ucfirst($target_marketplace) . 'home\Components\Csv\Helper',
        ];

        foreach ($possibleClassNames as $className) {
            if (class_exists($className) && method_exists($className, 'formatColumnsName')) {
                $class = $className;
                $existMethod = true;
                break;
            }
        }

        if ($existMethod) {
            [$csv_columns, $csv_columns_names] = $this->di->getObjectManager()->get($class)->formatColumnsName($csv_columns, $data);
        } else {
            foreach ($csv_columns as $value) {
                if ($value == "source_product_id") {
                    $value = "child id";
                }

                if ($value == "container_id") {
                    $value = "parent id";
                }

                $csv_columns_names[] = strtoupper($value);
            }
        }

        fputcsv($file, $csv_columns_names);

        $created_at = date('c');

        $data_to_insert = [
            "group_code"        => "csv_product",
            "source_shop_id"    => $source_shop_id,
            "target_shop_id"    => $target_shop_id,
            "app_tag"           => $app_tag,
            "user_id"           => $userId,
            "source"            => $source,
            "target"            => $target_marketplace,
            "created_at"        => $created_at,
        ];

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $config_collection = $mongo->getCollection("config");
        $data_to_insert['key'] = 'exported_file_name';
        $data_to_insert['value'] = $file_name;
        $config_collection->insertOne($data_to_insert);


        $queuedTaskData = [
            'marketplace'   => $target_marketplace,
            'message'       => 'CSV Export in progress',
            'process_code'  => 'csv_product',
            'shop_id'       => $target_shop_id,
        ];

        $feed_id = $this->di->getObjectManager()->get('App\Connector\Models\QueuedTasks')
            ->setQueuedTask($source_shop_id, $queuedTaskData);

        $CSVFile = [
            'file_path' => $file_path
        ];

        $token = $this->di->getObjectManager()
            ->get('App\Core\Components\Helper')
            ->getJwtToken($CSVFile, 'RS256', false);

        $base_url = $this->di->getConfig()->base_url_frontend ?? 'http://home.local.cedcommerce.com:8080/';

        $url = $base_url . 'connector/csv/downloadCSV?file_token=' . $token;

        $appTag = $this->di->getAppCode()->getAppTag();

        $handlerData = [
            'type'                  => 'full_class',
            'class_name'            => '\App\Connector\Components\CsvHelper',
            'method'                => 'ProductWriteCSVSQS',
            'queue_name'            => 'bulk_edit_export',
            'app_tag'               => $appTag,
            'user_id'               => $userId,
            'app_code'              => $appTag,
            'data' => [
                'url'               => $url,
                'totalCount'        => $countOfProducts,
                'feed_id'           => $feed_id,
                'cursor'            => 1,
                'skip'              => 0,
                'path'              => $file_path,
                'limit'             => $data['limit'] ?? CsvController::CSV_EXPORT_CHUNK_SIZE,
                'params'            => $params,
                'columns'           => $csv_columns,
                'csv_columns_names' => $csv_columns_names,
                'toExport'          => $params['toExport'],
                'file_path'         => $file_path,
                'file_name'         => $file_name,
                'export_type'       => $exportType,
                'export_limit'      => $export_limit,
                'shop_id'           => $source_shop_id,
                'target_marketplace' => $target_marketplace,
                'target_shop_id'    => $target_shop_id,
                'source_marketplace' => $source,
                'app_tag'           => $appTag,
                'user_id'           => $userId,
                'app_code'          => $appTag,
                'key_path'          => $data['key_path'] ?? [],
                'tag'               => empty($data['tag']) ? 'csv_product' : $data['tag'],
                'language'          => $data['language'] ?? 'en'
            ]
        ];

        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $sqsHelper->pushMessage($handlerData);

        return $url;
    }

    public function doesObjectExistInS3($s3, $bucketName, $key)
    {
        try {
            return $s3->doesObjectExist($bucketName, $key);
        } catch (AwsException $e) {
            // Handle the exception
            // echo $e->getMessage() . "\n";
            return false;
        }
    }

    public function getProductDetails_S3($data)
    {
        $userId = $this->di->getUser()->id;
        $target_shop_id = $this->di->getRequester()->getTargetId();
        $app_tag = $this->di->getAppCode()->getAppTag();
        $source = $this->di->getRequester()->getSourceName();
        $target_marketplace = $this->di->getRequester()->getTargetName();
        $source_shop_id = $this->di->getRequester()->getSourceId();
        $selected_product_ids = [];
        $grid_query = $data['filter'] ?? '';
        $data['query'] = $grid_query;
        $exportType = $data['type']; //all,selected,current_page
        if ($exportType == 'selected' || $exportType == 'current') {
            $selected_product_ids = $data['source_product_id'] ?? $data['container_ids'];
        }

        $filterSelected = $data['dataToExport']; //title,sku,barcode,brand,vendor,price,inventory
        $export_limit = $data['count'];
        $basePath = BP . DS . 'var/log/UserProductCSV/';
        $time_stamp = strtotime(date('d-m-Y H:i:s'));
        $file_name = 'ced_' . $time_stamp . '_products.csv';
        $file_path = $basePath . DS . $file_name;
        if (!empty($selected_product_ids)) {
            $countOfProducts = count($selected_product_ids);
        } else {
            $containerModel = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');
            $dataCount = $containerModel->getProductsCount([
                'productOnly' => false
            ]);
            $countOfProducts = $dataCount['data']['count'] ?? '';
        }

        $params = $data;
        $mandotaryField = ['container_id', 'source_product_id', 'type', 'variant_title'];
        $params['toExport'] = $filterSelected;
        $csv_columns = array_unique(array_merge($mandotaryField, $params['toExport'] ?? []));
        $csv_columns_names = [];

        $existMethod = false;
        $class = '';
        $possibleClassNames = [
            '\App\\' . ucfirst($target_marketplace) . '\Components\Csv\Helper',
            '\App\\' . ucfirst($target_marketplace) . 'home\Components\Csv\Helper',
        ];

        foreach ($possibleClassNames as $className) {
            if (class_exists($className) && method_exists($className, 'formatColumnsName')) {
                $class = $className;
                $existMethod = true;
                break;
            }
        }

        if ($existMethod) {
            [$csv_columns, $csv_columns_names] = $this->di->getObjectManager()->get($class)->formatColumnsName($csv_columns, $data);
        } else {
            foreach ($csv_columns as $value) {
                if ($value == "source_product_id") {
                    $value = "child id";
                }

                if ($value == "container_id") {
                    $value = "parent id";
                }

                $csv_columns_names[] = strtoupper($value);
            }
        }

        $created_at = date('c');
        $data_to_insert = [
            "group_code"        => "csv_product",
            "source_shop_id"    => $source_shop_id,
            "target_shop_id"    => $target_shop_id,
            "app_tag"           => $app_tag,
            "user_id"           => $userId,
            "source"            => $source,
            "target"            => $target_marketplace,
            "created_at"        => $created_at,
        ];

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $config_collection = $mongo->getCollection("config");
        $data_to_insert['key'] = 'exported_file_name';
        $data_to_insert['value'] = $file_name;
        $config_collection->insertOne($data_to_insert);
        $queuedTaskData = [
            'marketplace'   => $target_marketplace,
            'message'       => 'CSV Export in progress',
            'process_code'  => 'csv_product',
            'shop_id'       => $target_shop_id,
        ];
        $feed_id = $this->di->getObjectManager()->get('App\Connector\Models\QueuedTasks')
            ->setQueuedTask($source_shop_id, $queuedTaskData);
        $file_name = 'ced_' . $time_stamp . '_products.csv';
        $appTag = $this->di->getAppCode()->getAppTag();

        $handlerData = [
            'type'                  => 'full_class',
            'class_name'            => '\App\Connector\Components\CsvHelper',
            'method'                => 'ProductWriteCSVSQS_S3',
            'queue_name'            => 'bulk_edit_export',
            'app_tag'               => $appTag,
            'user_id'               => $userId,
            'app_code'              => $appTag,
            'data' => [
                'totalCount'        => $countOfProducts,
                'feed_id'           => $feed_id,
                'cursor'            => 1,
                'skip'              => 0,
                'path'              => $file_path,
                'limit'             => $data['limit'] ?? CsvController::CSV_EXPORT_CHUNK_SIZE,
                'params'            => $params,
                'csv_columns_names' => $csv_columns_names,
                'columns'           => $csv_columns,
                'toExport'          => $params['toExport'],
                'file_path'         => $file_path,
                'file_name'         => $file_name,
                'export_type'       => $exportType,
                'export_limit'      => $export_limit,
                'shop_id'           => $source_shop_id,
                'target_marketplace' => $target_marketplace,
                'target_shop_id'    => $target_shop_id,
                'source_marketplace' => $source,
                'app_tag'           => $appTag,
                'user_id'           => $userId,
                'app_code'          => $appTag,
                'key_path'          => $data['key_path'] ?? [],
                'tag'               => empty($data['tag']) ? 'csv_product' : $data['tag'],
                'language'          => $data['language'] ?? 'en'
            ]
        ];

        if (!empty($selected_product_ids)) {
            $this->ProductWriteCSVSQS_S3($handlerData);
        } else {
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $sqsHelper->pushMessage($handlerData);
        }

        return $file_name;
    }


    public function ProductWriteCSVSQS_S3($sqsData)
    {
        $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
        $shopId = $sqsData['data']['shop_id'] ?? '0';
        $target_marketplace = $sqsData['data']['target_marketplace'] ?? '';
        $source_marketplace = $sqsData['data']['source_marketplace'] ?? 'shopify';
        $target_shop_id = $sqsData['data']['target_shop_id'] ?? '';
        $app_tag = $sqsData['app_tag'] ?? $this->di->getAppCode()->getAppTag();
        $chunks_processed = $sqsData['data']['chunks_processed'] ?? 0;
        $totalCount = $sqsData['data']['totalCount'];
        $csv_export_limit = $sqsData['data']['export_limit'];
        $activePage = $sqsData['data']['activePage'] ?? 1;
        $chunk_size = $totalCount / $csv_export_limit;
        $chunk_size = ceil($chunk_size);

        $totalDataRead = $sqsData['data']['totalDataRead'] ?? 0;
        $row_found_count = 0;
        $path = $sqsData['data']['file_path'];
        $config = $this->di->getConfig();
        $bucketForS3Upload = $config->get("bulkUpdate_bucket");
        $toEditUpdate = "";
        $csv_columns = $sqsData['data']['columns'];
        $csv_column_names = $sqsData['data']['csv_columns_names'];

        $exportType = $sqsData['data']['export_type'];
        $unique_key_for_sqs = $sqsData['data']['unique_key_for_sqs'] ?? '';

        $getDataParms['source']['marketplace'] = $source_marketplace;
        $getDataParms['source']['shopId'] = $shopId;
        $getDataParms['target']['marketplace'] = $target_marketplace;
        $getDataParms['target']['shopId'] = $target_shop_id;
        $getDataParms['limit'] = $csv_export_limit;
        $getDataParms['useRefinProduct'] = $sqsData['data']['params']['useRefinProduct'] ?? false;
        $getDataParms['activePage'] = $activePage;
        $getDataParms['unique_key_for_sqs'] = $unique_key_for_sqs;
        $keyPath = $sqsData['data']['key_path'] ?? [];
        $tag = $sqsData['data']['tag'] ?? 'csv_product';
        $rowsToWrite = [];
        $hasNext = false;
        if ($exportType == 'selected' || $exportType == 'current') {
            if(!isset($sqsData['data']['params']['container_ids']) && !isset($sqsData['data']['params']['source_product_id'])) {
                return [
                    'success' => false,
                    'message' => 'Source product ids or container ids are missing'
                ];
            }

            $key = 'source_product_ids';
            if (isset($sqsData['data']['params']['container_ids'])) {
                $key = 'container_ids';
            }

            $selected_source_product_ids = [$key === 'container_ids' ? $sqsData['data']['params']['container_ids'] : $sqsData['data']['params']['source_product_id']];

            if (!empty($selected_source_product_ids)) {
                $spid_row_data = [];
                foreach ($selected_source_product_ids as $spid) {
                    $getDataParms[$key] = $spid;
                    $getDataParms['operationType'] = 'csv_export';

                    $chunkProductData = $this->getProductDetailsRecursive($getDataParms);

                    if (isset($chunkProductData['success']) && $chunkProductData['success']) {
                        $spid_data = $chunkProductData['data'];
                        $spid_row_data = array_merge($spid_data, $spid_row_data);
                        $hasNext = $chunkProductData['next'];
                    }
                }

                $rowsToWrite = $spid_row_data;
            } else {
                return ['status' => true, 'message' => 'source_product_ids not found'];
            }
        } else {
            $getDataParms['profile_id'] = 'all_products';
            $getDataParms['operationType'] = 'csv_export';
            if (isset($sqsData['data']['params']['filter']) && !empty($sqsData['data']['params']['filter'])) {
                $getDataParms['filter'] = $sqsData['data']['params']['filter'];
            }

            $row = $this->getAllProductsRecursively($getDataParms);

            $rowsToWrite = $row['data'];
            $row_found_count = count($row['data']);
            $hasNext = $row['next'];
        }

        $file_name = $sqsData['data']['file_name'];

        $config = include BP . '/app/etc/aws.php';
        $s3Client = new S3Client($config);
        $exists = $this->doesObjectExistInS3($s3Client, $bucketForS3Upload, $file_name);
        $file = fopen('php://temp', 'w');
        if (!empty($rowsToWrite)) {
            $i = 0;
            if (!$exists) {
                echo "not exist" . PHP_EOL;
                fputcsv($file, $csv_column_names);
            } else {
                echo "exists" . PHP_EOL;
                $existingFileObject = $s3Client->getObject([
                    'Bucket' => $bucketForS3Upload,
                    'Key' => $file_name
                ]);
                fwrite($file, $existingFileObject['Body']->getContents());
            }

            $existMethod = false;
            $class = '';
            $possibleClassNames = [
                '\App\\' . ucfirst($target_marketplace) . '\Components\Csv\Helper',
                '\App\\' . ucfirst($target_marketplace) . 'home\Components\Csv\Helper',
            ];

            foreach ($possibleClassNames as $className) {
                if (class_exists($className) && method_exists($className, 'formatExportCsv')) {
                    $class = $className;
                    $existMethod = true;
                    break;
                }
            }

            if ($existMethod) {
                [$line, $insertRows] = $this->di->getObjectManager()->get($class)->formatExportCsv($sqsData, $rowsToWrite, $file);
            } else {
                $line = $csv_column_names;
                $i = 0;
                $created_at = date('c');
                foreach ($rowsToWrite  as $value) {
                    $type = $value['visibility'] == "Catalog and Search" ? "main" : "child";
                    $line = $this->createLine($csv_columns, $value, $toEditUpdate, $type, [], $keyPath);
                    fputcsv($file, $line['line']);

                    $insertRows[$i] = $line['line_key_items'];
                    $insertRows[$i]['user_id'] = $userId;
                    $insertRows[$i]['source_shop_id'] = $shopId;
                    $insertRows[$i]['target_shop_id'] = $target_shop_id;
                    $insertRows[$i]['target_marketplace'] = $target_marketplace;
                    $insertRows[$i]['source_marketplace'] = $source_marketplace;
                    $insertRows[$i]['created_at'] = $created_at;
                    $i++;
                };
            }

            rewind($file); // Write data
            $s3Client->putObject([
                'Bucket' => $bucketForS3Upload,
                'Key'    => $file_name,
                'Body'   => $file,
                'ContentType' => 'text/csv',
            ]);
            fclose($file);
        }

        if (!empty($insertRows)) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

            $collectionObj = $mongo->getCollection('csv_export_product_container');
            $collectionObj->insertMany($insertRows);
        }

        $totalDataRead = $totalDataRead + $row_found_count;

        $chunks_processed = $chunks_processed + 1;
        if ($hasNext) {
            $handlerData = $sqsData;
            $handlerData['data']['chunks_processed'] = $chunks_processed;
            $handlerData['data']['activePage'] = $activePage + 1;
            $handlerData['data']['next'] = true;

            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $sqsHelper->pushMessage($handlerData);

            $progress = round(($chunks_processed / $chunk_size) * 100, '2');
            echo 'in queue for loop ' . $progress . PHP_EOL;

            $message = "CSV Export in progress";

            $this->di->getObjectManager()->get('App\Connector\Models\QueuedTasks')
                ->updateFeedProgress($sqsData['data']['feed_id'], $progress, $message, false);
        } else {
            $message = "CSV Export Completed";

            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucketForS3Upload,
                'Key' => $file_name
            ]);
            $request = $s3Client->createPresignedRequest($cmd, '+72 hour');

            // Get the presigned URL
            $url = (string)$request->getUri();
            $notificationData = [
                'message'       => "CSV exported successfully",
                'url'           => $url,
                'marketplace'   => $target_marketplace,
                'process_code'  => 'csv_product',
                'appTag'        => $app_tag,
                'severity'      => 'success',
                'tag'           => $tag
            ];

            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                ->addNotification($target_shop_id, $notificationData);

            $this->di->getObjectManager()->get('App\Connector\Models\QueuedTasks')
                ->updateFeedProgress($sqsData['data']['feed_id'], 100, $message, false);
            $this->triggerAfterCSVExportEvent($sqsData['data']);
            return ['success' => true];
        }
    }

    private function triggerAfterCSVExportEvent($data): void
    {
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:afterCSVExport', $this, $data);
    }

    public function createLine($columns, $value, $toEditUpdate, $type, $parent = [], $keyPath = [])
    {
        $line = [];
        $line_key_items = [];
        foreach ($columns as $column) {
            $var = $keyPath[$column]['var'] ?? [];
            $column = isset($keyPath[$column]) ? $this->getActualPath($keyPath[$column]) : $column;
            if ($column == 'type') {
                $line[] = strtoupper($type);
                $line_key_items[$column] = strtoupper($type);
            } else {
                if ($column == 'inventory') {
                    $column = 'quantity';
                }

                $column_data = '';
                if (isset($value['edited'][$column]) && !empty(trim($value['edited'][$column]))) {
                    $column_data = $value['edited'][$column] ?? '';
                } else if (strpos($column, '|') !== FALSE) {
                    $column_data = $this->getValueFromNestedArray($value, $column, $var) ?? "";
                } else {
                    $column_data = $value[$column] ?? '';
                }

                $line[] = "\t" . $column_data;
                $line_key_items[$column] = $column_data;
            }
        }

        /*$line[] = $value['variant_title'];
        $line_key_items['variant title'] = $value['variant_title'];*/

        return ['line' => $line, 'line_key_items' => $line_key_items];
    }

    public function ProductWriteCSVSQS($sqsData)
    {
        $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
        $shopId = $sqsData['data']['shop_id'] ?? '0';
        $target_marketplace = $sqsData['data']['target_marketplace'] ?? '';
        $source_marketplace = $sqsData['data']['source_marketplace'] ?? 'shopify';
        $target_shop_id = $sqsData['data']['target_shop_id'] ?? '';
        $app_tag = $sqsData['app_tag'] ?? $this->di->getAppCode()->getAppTag();

        $file_download_url = $sqsData['data']['url'] ?? '';

        $chunks_processed = $sqsData['data']['chunks_processed'] ?? 0;

        $totalCount = $sqsData['data']['totalCount'];
        $csv_export_limit = $sqsData['data']['export_limit'];
        $chunk_size = $totalCount / $csv_export_limit;
        $chunk_size = ceil($chunk_size);
        $row_found_count = 0;
        $path = $sqsData['data']['file_path'];
        $file = fopen($path, 'a');

        $toEditUpdate = "";
        $csv_column_names = $sqsData['data']['columns'];

        $exportType = $sqsData['data']['export_type'];
        $activePage = $sqsData['data']['activePage'] ?? 1;
        $unique_key_for_sqs = $sqsData['data']['unique_key_for_sqs'] ?? '';
        $keyPath = $sqsData['data']['key_path'] ?? [];
        $tag = $sqsData['data']['tag'] ?? 'csv_product';

        $getDataParms['source']['marketplace'] = $source_marketplace;
        $getDataParms['source']['shopId'] = $shopId;
        $getDataParms['target']['marketplace'] = $target_marketplace;
        $getDataParms['target']['shopId'] = $target_shop_id;
        $getDataParms['limit'] = $csv_export_limit;
        $getDataParms['useRefinProduct'] = $sqsData['data']['params']['useRefinProduct'] ?? false;
        $getDataParms['activePage'] = $activePage;
        $getDataParms['unique_key_for_sqs'] = $unique_key_for_sqs;

        $rowsToWrite = [];
        $hasNext = false;

        if ($exportType == 'selected' || $exportType == 'current') {
            if(!isset($sqsData['data']['params']['container_ids']) && !isset($sqsData['data']['params']['source_product_id'])) {
                return [
                    'success' => false,
                    'message' => 'Source product ids or container ids are missing'
                ];
            }

            $key = 'source_product_ids';
            if (isset($sqsData['data']['params']['container_ids'])) {
                $key = 'container_ids';
            }

            $selected_source_product_ids = [$key === 'container_ids' ? $sqsData['data']['params']['container_ids'] : $sqsData['data']['params']['source_product_id']];
            if (!empty($selected_source_product_ids)) {
                $spid_row_data = [];
                foreach ($selected_source_product_ids as $spid) {
                    $getDataParms[$key] = $spid;
                    $getDataParms['operationType'] = 'csv_export';

                    $chunkProductData = $this->getProductDetailsRecursive($getDataParms);

                    if (isset($chunkProductData['success']) && $chunkProductData['success']) {
                        $spid_data = $chunkProductData['data'];
                        $hasNext = $chunkProductData['next'];
                        $spid_row_data = array_merge($spid_data, $spid_row_data);
                    }
                }

                $rowsToWrite = $spid_row_data;
            } else {
                return ['status' => true, 'message' => 'source_product_ids not found'];
            }
        } else {

            $getDataParms['profile_id'] = 'all_products';
            $getDataParms['operationType'] = 'csv_export';

            if (isset($sqsData['data']['params']['filter']) && !empty($sqsData['data']['params']['filter'])) {
                $getDataParms['filter'] = $sqsData['data']['params']['filter'];
            }

            $row = $this->getAllProductsRecursively($getDataParms);

            $rowsToWrite = $row['data'];
            $row_found_count = count($row['data']);
            $hasNext = $row['next'];
        }


        if (!empty($rowsToWrite)) {
            $existMethod = false;
            $class = '';
            $possibleClassNames = [
                '\App\\' . ucfirst($target_marketplace) . '\Components\Csv\Helper',
                '\App\\' . ucfirst($target_marketplace) . 'home\Components\Csv\Helper',
            ];

            foreach ($possibleClassNames as $className) {
                if (class_exists($className) && method_exists($className, 'formatExportCsv')) {
                    $class = $className;
                    $existMethod = true;
                    break;
                }
            }

            if ($existMethod) {
                [$line, $insertRows] = $this->di->getObjectManager()->get($class)->formatExportCsv($sqsData, $rowsToWrite, $file);
            } else {
                $line = $csv_column_names;
                $i = 0;
                foreach ($rowsToWrite  as $value) {
                    $type = $value['visibility'] == "Catalog and Search" ? "main" : "child";
                    $line = $this->createLine($csv_column_names, $value, $toEditUpdate, $type, [], $keyPath);
                    fputcsv($file, $line['line']);

                    $insertRows[$i] = $line['line_key_items'];
                    $insertRows[$i]['user_id'] = $userId;
                    $insertRows[$i]['source_shop_id'] = $shopId;
                    $insertRows[$i]['target_shop_id'] = $target_shop_id;
                    $insertRows[$i]['target_marketplace'] = $target_marketplace;
                    $insertRows[$i]['source_marketplace'] = $source_marketplace;

                    $i++;
                };
            }
        }

        if (!empty($insertRows)) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

            $collectionObj = $mongo->getCollection('csv_export_product_container');
            $bulkInsertObj = $collectionObj->insertMany($insertRows);
        }


        $chunks_processed = $chunks_processed + 1;
        if ($hasNext) {
            //$last_id_inserted = $last_id_processed;\
            $handlerData = $sqsData;
            $handlerData['data']['chunks_processed'] = $chunks_processed;
            $handlerData['data']['activePage'] = $activePage + 1;
            $handlerData['data']['next'] = true;
            $progress = round(($chunks_processed / $chunk_size) * 100, '2');
            echo 'in queue for loop ' . $progress . PHP_EOL;

            $message = "CSV Export in progress";

            $updation_in_queued_task = $this->di->getObjectManager()->get('App\Connector\Models\QueuedTasks')
                ->updateFeedProgress($sqsData['data']['feed_id'], $progress, $message, false);
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $sqsHelper->pushMessage($handlerData);
        } else {
            fclose($file);
            $message = "CSV Export Completed";

            $notificationData = [
                'message'       => "CSV exported successfully",
                'url'           => $file_download_url,
                'marketplace'   => $target_marketplace,
                'appTag'        => $app_tag,
                'severity'      => 'success',
                'process_code'  => 'csv_product',
                'tag'           => $tag
            ];

            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                ->addNotification($target_shop_id, $notificationData);

            $this->di->getObjectManager()->get('App\Connector\Models\QueuedTasks')
                ->updateFeedProgress($sqsData['data']['feed_id'], 100, $message, false);

            /*$path = 'core' . DS . 'view' . DS . 'email' . DS . 'csv_exported.volt';
            $data['email'] = $this->di->getObjectManager()->getUser()->getEmail();
            $data['email'] = 'shivamagarwal@cedcommerce.com';
            $data['username'] = $this->di->getObjectManager()->getUser()->getUsername();
            $data['text'] = 'exporting is completed';
            $data['subject'] = 'exporting completed';
            $data['path'] = $path;
            $token = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);*/
            $this->triggerAfterCSVExportEvent($sqsData['data']);
            return ['success' => true];
        }
    }

    public function getProductDetailsRecursive($getDataParms)
    {
        $dataCount = $this->di->getObjectManager()->get('\App\Connector\Components\Profile\GetProductProfileMerge')->getproductsByProductIds($getDataParms, true);
        $dataCount = json_decode(json_encode($dataCount), true);


        if (isset($dataCount['success'], $dataCount['data']['rows']) && !empty($dataCount['data']['rows'])) {
            $hasNext = $dataCount['data']['next'];
            $rowsFound = $dataCount['data']['rows'];
            return ['success' => true, 'data' => $rowsFound, 'next' => boolval($hasNext)];
        } else {
            return ['success' => true, 'data' => [], 'next' => false];
        }

        // $filter = [];

        // if (isset($getDataParms['source_product_ids'])) {
        //     $filter['source_product_id'] = [
        //         '10' => $getDataParms['source_product_ids']
        //     ];
        // } else if (isset($getDataParms['container_ids'])) {
        //     $filter['container_id'] = [
        //         '10' => $getDataParms['container_ids']
        //     ];
        // } else {
        //     $filter = [];
        // }

        // $sourceShopId = $getDataParms['source']['shopId'];
        // $targetShopId = $getDataParms['target']['shopId'];

        // $products = $this->di->getObjectManager()->get(ProductContainer::class)->fetchProducts(
        //     $sourceShopId,
        //     $filter,
        //     [
        //         'useRefinProduct' => $getDataParms['useRefinProduct'],
        //         'page' => $getDataParms['activePage'],
        //         'limit' => 500
        //     ],
        //     $targetShopId
        // );
        // if (!empty($products)) {
        //     return [
        //         'success' => true,
        //         'data' => $products,
        //         'next' => true,
        //     ];
        // }

        // return [
        //     'success' => true,
        //     'data' => $products,
        //     'next' => false
        // ];
    }

    public function getAllProductsRecursively($getDataParms)
    {
        $dataCount = $this->di->getObjectManager()->get('\App\Connector\Components\Profile\GetProductProfileMerge')->getproductsByProfile($getDataParms, true);

        $dataCount = json_decode(json_encode($dataCount), true);

        if (isset($dataCount['success'], $dataCount['data']['rows']) && !empty($dataCount['data']['rows'])) {
            $hasNext = $dataCount['data']['next'];
            $rowsFound = $dataCount['data']['rows'];
            return ['success' => true, 'data' => $rowsFound, 'next' => $hasNext];
        } else {
            return ['success' => true, 'data' => [], 'next' => false];
        }
        // $sourceShopId = $getDataParms['source']['shopId'];
        // $products = $this->di->getObjectManager()->get(ProductContainer::class)->fetchProducts(
        //     $sourceShopId,
        //     $getDataParms['filter'] ?? [],
        //     [
        //         'useRefinProduct' => $getDataParms['useRefinProduct'],
        //         'page' => $getDataParms['activePage'],
        //         'limit' => $getDataParms['limit']
        //     ],
        //     $getDataParms['target']['shopId']
        // );
        // if (!empty($products)) {
        //     return [
        //         'success' => true,
        //         'data' => $products,
        //         'next' => true,
        //     ];
        // }

        // return [
        //     'success' => true,
        //     'data' => $products,
        //     'next' => false
        // ];
    }

    public function pushToQueue($sqsData): void
    {
        $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $rmqHelper->pushMessage($sqsData);
    }

    public function BulkEdit($sqsData)
    {
        $config_data = $sqsData['data']['config_data'];
        $target = $sqsData['data']['target_marketplace'];
        $source_id = $sqsData['data']['shop_id'];
        $target_id = $sqsData['data']['target_shop_id'];
        $total_rows = $sqsData['data']['count'];
        $app_tag = $sqsData['app_tag'];
        $feed_id = $sqsData['data']['feed_id'];
        $userId = $sqsData['user_id'];
        $skip_character = $sqsData['skip_character'] ?? 0;
        $row_chunk = $sqsData['data']['limit'] ?? 5;
        $myFile = $sqsData['data']['path'];
        $row_read = $sqsData['row_read'] ?? 0;
        $start = $sqsData['data']['start'] ?? false;
        $tag = $sqsData['data']['tag'] ?? "csv_product";
        //        $start = true;
        $rows_updated = $sqsData['rows_updated'] ?? 0;
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongo->getCollection("csv_export_product_container");

        $i =  0;
        $keyPath = $this->getKeyPathConfig($config_data);
        if (($handle = fopen($myFile, 'r')) !== false) {

            $header = fgetcsv($handle, 1000, ',');
            fseek($handle, $skip_character);

            $contianer_id_wise_Array = [];

            while (($product = fgetcsv($handle)) !== false && $i < $row_chunk) {
                if (!$start && $i != 0) {
                    $prepareProductArr = [];

                    $container_id = '';
                    foreach ($product as $key => $eachEle) {
                        $header_key = '';
                        $eachEle = str_replace("\t", "", $eachEle);
                        if (strtolower($header[$key]) == 'parent id') {
                            $header_key = 'container_id';
                            $prepareProductArr[$header_key] = $eachEle;
                            $container_id = $eachEle;
                        } elseif (strtolower($header[$key]) == 'child id') {
                            $header_key = 'source_product_id';
                            $prepareProductArr[$header_key] = $eachEle;
                        } else if ($header[$key] == "quantity") {
                            $eachEle = (int)$eachEle;
                            $prepareProductArr[$header_key] = $eachEle;
                        } else if ($header[$key] == "price") {
                            $eachEle = number_format((float)$eachEle, 2, '.', '');
                            $prepareProductArr[$header_key] = $eachEle;
                        }

                        else {
                            $hKey = strtolower($header[$key]);
                            $header_key = isset($keyPath[$hKey]) ? $this->getActualPath($keyPath[$hKey]) : $hKey;
                            $prepareProductArr[$header_key] = $eachEle;
                        }
                    }

                    if ($container_id != '') {
                        $contianer_id_wise_Array[$container_id][] = $prepareProductArr;
                    }
                }


                $i++;
                $row_read++;
                $skip_character = ftell($handle);
                $start = false;
                unset($product);
            }

            fclose($handle);
        }

        if ($contianer_id_wise_Array !== []) {
            //this function will check the data in csv_product_container with the data that was exported
            $check_updated_data = $this->checkUpdatedKeys($contianer_id_wise_Array, $sqsData);
            if ($check_updated_data['success']) {

                if (count($check_updated_data['data']) > 0) {
                    $updateInModule = $this->sendUpdatedDataInModule($check_updated_data['data'], $sqsData);
                    $rows_updated = $rows_updated + $updateInModule['updated_count'];
                    $rows_updated = $rows_updated + $updateInModule['inserted_count'];
                }
            }
        }

        $progress = round($row_read / $total_rows * 100, '2');

        if ($progress >= 100) {

            $message = "CSV Import Completed, " . $rows_updated . ' rows updated successfully among ' . $total_rows . ' found in CSV';

            $notificationData = [
                'message'       => $message,
                //'url'           => $file_download_url,
                'marketplace'   => $target,
                'appTag'        => $app_tag,
                'severity'      => 'success',
                'process_code'  => 'csv_product',
                'tag'           => $tag
            ];

            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                ->addNotification($target_id, $notificationData);

            $this->di->getObjectManager()->get('App\Connector\Models\QueuedTasks')
                ->updateFeedProgress($feed_id, 100, $message, false);

            $csv_product_collection = $mongo->getCollection("csv_export_product_container");

            $csv_product_collection_data  = $csv_product_collection->deleteMany(
                [
                    'user_id'                   => $userId,
                    'source_shop_id'            => $source_id,
                ]
            );

            $config_collection = $mongo->getCollection("config");

            $exported_file_name = $config_collection->deleteMany(
                [
                    'user_id'           => $userId,
                    'source_shop_id'    => $source_id,
                    'group_code'        => 'csv_product',
                ]
            );

            return true;
        }

        $sqsData['skip_character'] = $skip_character;
        $sqsData['rows_updated'] = $rows_updated;
        $sqsData['row_read'] = $row_read;
        $sqsData['limit'] = $row_chunk;
        $sqsData['totalProgress'] = $progress;
        $sqsData['data']['cursor']++;
        $message = "CSV Import in Progress";
        /*$this->di->getObjectManager()->get('App\Connector\Components\Helper')
          ->updateFeedProgress($sqsData['data']['feed_id'], $progress, $message);*/
        $this->di->getObjectManager()->get('App\Connector\Models\QueuedTasks')
            ->updateFeedProgress($feed_id, $progress, $message, false);
        $this->pushToQueue($sqsData);
    }

    private function getKeyPathConfig($config)
    {
        $keyPath = [];
        foreach ($config as $value) {
            if ($value['key'] == 'key_path') {
                $keyPath = $value['value'];
            }
        }

        return $keyPath;
    }

    public function checkUpdatedKeys($importedProducts, $sqsData)
    {
        $config_data = $sqsData['data']['config_data'];
        $source_id = $sqsData['data']['shop_id'];
        $target_id = $sqsData['data']['target_shop_id'];
        $userId = $sqsData['user_id'];
        $updated_rows_in_csv = [];

        $exported_columns_in_csv = [];
        $keyPath = $this->getKeyPathConfig($config_data);
        foreach ($config_data as $v) {
            if ($v['key'] == 'csv_export_options_filter') {
                $exported_columns_in_csv = $v['value'];
            }
        }

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $csv_export_product_container = $mongo->getCollection("csv_export_product_container");

        foreach ($importedProducts as $container_id => $importedData) {
            $container_exported_data = $csv_export_product_container->find(
                [
                    "user_id" => $userId,
                    "source_shop_id" => $source_id,
                    "container_id" => (string)$container_id,
                    "target_shop_id" => $target_id,

                ]
            )->toArray();

            if (count($container_exported_data)) {
                $match_data = $this->matchContainerData($importedData, $container_exported_data, $exported_columns_in_csv, $keyPath);
                if ($match_data['success'] && !empty($match_data['data'])) {
                    if (isset($updated_rows_in_csv[$container_id])) {
                        $updated_rows_in_csv[$container_id] = array_merge($updated_rows_in_csv[$container_id], $match_data['data']);
                    } else {
                        $updated_rows_in_csv[$container_id] = $match_data['data'];
                    }
                }
            }
        }

        return ['success' => true, 'data' => $updated_rows_in_csv];
    }

    public function matchContainerData($importedData, $exported_data, $exported_columns_in_csv, $keyPath)
    {
        $updated_data = [];
        foreach ($importedData as $row_data) {
            $csv_conatainer_id = $row_data['container_id'];
            $csv_source_product_id = $row_data['source_product_id'];

            foreach ($exported_data as $exported_value) {
                $exported_container_id = $exported_value['container_id'];
                $exported_source_pid = $exported_value['source_product_id'];

                if ($exported_container_id == $csv_conatainer_id && $exported_source_pid == $csv_source_product_id) {
                    foreach ($exported_value as $e_key => $e_val) {
                        if (isset($row_data[$e_key]) && $row_data[$e_key] != '' && $row_data[$e_key] != $e_val) {
                            //                            $updated_data[] = $row_data;
                            $updated_key[] = $e_key;
                            //return ['success' => true, 'data' => $row_data];
                            //break;
                        }
                    }
                }

                /*if(!empty($updated_data))
                {
                    break;
                }*/
            }

            if (!empty($updated_key)) {
                $prepare_data = [];
                $temp_row_data = $row_data;
                $prepare_data['source_product_id'] = $csv_source_product_id;
                $prepare_data['container_id'] = $csv_conatainer_id;
                foreach ($updated_key as $c_name) {
                    $var = $this->findVarByPath($keyPath, $c_name);
                    if (strpos($c_name, '|') !== FALSE) {
                        $this->setValueInNestedArray($prepare_data, $c_name, $temp_row_data[$c_name], $var);
                    } else {
                        $prepare_data[$c_name] = $temp_row_data[$c_name];
                    }
                }

                $prepare_data['type'] = $temp_row_data['type'];
                $updated_data[] = $prepare_data;
            }
        }

        return ['success' => true, 'data' => $updated_data];
    }

    private function findVarByPath($data, $path)
    {
        foreach ($data as $value) {
            if (isset($value['path']) && $value['path'] === $path) {
                return $value['var'] ?? null;
            }
        }

        return null;
    }

    public function sendUpdatedDataInModule($updatedRows, $sqsData)
    {
        //this function will only send the updated columns in csv rows to the module
        $marketplace = $sqsData['data']['target_marketplace'];
        $class = '\App\\' . ucfirst($marketplace) . '\Components\Csv\Helper';

        if (class_exists($class)) {
            if (method_exists($class, 'processCsvData')) {
                $requestHelper = $this->di->getObjectManager()->get($class)->processCsvData($updatedRows, $sqsData);
                return $requestHelper;
            }

            $this->di->getLog()->logContent('Class found,processCsvData method not found in ' . $marketplace, 'info', 'MethodNotFound.log');
        } else {
            $this->di->getLog()->logContent($class . ' Class not found in . ' . $marketplace, 'info', 'ClassNotFound.log');
        }
    }

    public function importCSVAndUpdate($params)
    {
        $userId = $params['user_id'] ?? $this->di->getUser()->id;
        $source_shop_id = $params['source_shop_id'] ?? '0';
        $target_marketplace = $params['target'] ?? '';
        $source_marketplace = $params['source'] ?? 'shopify';
        $target_shop_id = $params['target_shop_id'] ?? '';
        $appTag = $params['app_tag'] ?? '';
        $appCode = $params['app_code'] ?? '';
        $tag = $params['tag'] ?? '';

        $queuedTasksData = [
            'message'           => 'CSV Import in Progress',
            'process_code'      => 'csv_product',
            'additional_data'   => [
                'path'          => $params['path'] . $params['name'],
            ],
            'user_id'           => $userId,
            'shop_id'           => $target_shop_id,
            'marketplace'       => $target_marketplace
        ];
        $feed_id = $this->di->getObjectManager()->get('App\Connector\Models\QueuedTasks')
            ->setQueuedTask($target_shop_id, $queuedTasksData);

        $handlerData = [
            'type'              => 'full_class',
            'class_name'        => '\App\Connector\Components\CsvHelper',
            'method'            => 'BulkEdit',
            'queue_name'        => 'bulk_edit_import',
            'app_tag'           => $appTag,
            'user_id'           => $userId,
            'data' => [
                'path'          => $params['path'] . $params['name'],
                'feed_id'       => $feed_id,
                'cursor'        => 0,
                'skip'          => 0,
                'limit'         => $params['import_limit'],
                'count'         => $params['count'],
                'editUpdate'    => $params['editUpdate'] ?? "",
                'config_data'   => $params['config_data'],
                'file_headers'  => $params['file_headers'],
                'skip_character' => 1,
                'shop_id'           => $source_shop_id,
                'target_marketplace' => $target_marketplace,
                'target_shop_id'    => $target_shop_id,
                'source_marketplace' => $source_marketplace,
                'app_tag'           => $appTag,
                'user_id'           => $userId,
                'app_code'          => $appCode,
                'tag'               => $tag,

            ]
        ];
        //$this->BulkEdit($handlerData);
        $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $rmqHelper->pushMessage($handlerData);
        return true;
    }

    public function getProducts($params)
    {
        $aggregation2 = [];
        //        $params['app_code'] = 'amazon_sales_channel';
        //        $params['marketplace'] = 'amazon';

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getCollection();

        $totalPageRead = 1;
        $limit = $params['count'] ?? ProductContainer::$defaultPagination;

        if (isset($params['next'])) {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        $userId = $params['user_id'] ?? $this->di->getUser()->id;

        $aggregation = [];

        if ($type != 'admin') {
            $aggregation[] = ['$match' => ['user_id' => $userId]];
            $aggregation2[] = ['$match' => ['user_id' => $userId]];
        }

        if (isset($params['app_code'])) {
            $aggregation[] = [
                // '$match' => ['app_codes' => ['$in' => [$params['app_code']]]],
                '$match' => ['app_codes' => $params['app_code']],
            ];
        }

        $aggregation[] = [
            '$sort' => ['visibility' => 1]
        ];

        $aggregation[] = [
            '$sort' => ['container_id' => 1]
        ];

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params));

        $select_fields = ['user_id', 'sku', 'container_id', 'source_product_id', 'group_id', 'title', 'profile', 'main_image', 'description', 'type', 'brand', 'product_type', 'visibility', 'app_codes', 'additional_images', 'variant_attributes', 'barcode', 'quantity', 'price'];

        $_group = ['_id' => '$container_id'];
        foreach ($select_fields as $field) {
            $_group[$field] = ['$first' => '$' . $field];
        }

        $aggregation[] = [
            '$group' => $_group
        ];

        $aggregation[] = [
            '$sort' => ['_id' => 1]
        ];

        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int) $offset];
        }

        $aggregation[] = ['$limit' => (int) $limit + 1];

        try {
            // use this below code get to slow down
            //TODO: TEST Needed here
            // $rows = $collection->aggregate($aggregation)->toArray();
            $options = [
                'projection' => ['container_id' => 1, 'source_product_id' => 1, 'title' => 1, 'sku' => 1, 'brand' => 1, 'description' => 1, 'user_id' => 1, 'barcode' => 1, 'quantity' => 1, 'price' => 1],
                'typeMap'   => ['root' => 'array', 'document' => 'array']
            ];

            $cursor = $collection->aggregate($aggregation, $options);
            $amazonCollection = $mongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);
            $amazonProducts = $amazonCollection->aggregate($aggregation2, $options);
            $amazonProductsData = [];

            foreach ($amazonProducts as $amazonProduct) {
                $amazonProductsData = $amazonProductsData + [$amazonProduct['source_product_id'] => $amazonProduct];
            }

            $it = new \IteratorIterator($cursor);
            $it->rewind();
            // Very important
            $rows = [];
            $distinctContainerIds = [];

            while ($limit > 0 && $doc = $it->current()) {
                if (!in_array((string)$doc['container_id'], $distinctContainerIds)) {
                    $editedData = $amazonProductsData[$doc['source_product_id']] ?? [];
                    $distinctContainerIds[] = (string)$doc['container_id'];
                    if ($userId == "616d6f9a9be3302a6537fae3") {
                        $rows[$doc['container_id']] = [
                            'user_id'           => $doc['user_id'],
                            'container_id'      => $doc['container_id'],
                            'main_image'        => $doc['main_image'],
                            'title'             => $editedData['title'] ?? $doc['title'],
                            'additional_images' => $doc['additional_images'],
                            'sku'               =>  $doc['sku'] ?? '',
                            'edited_sku'        => $editedData['sku'] ?? '',
                            // 'type'              => $doc['type'],
                            'brand'             => $editedData['brand'] ?? $doc['brand'],
                            'description'       => $editedData['description'] ?? $doc['description'] ?? '',
                            'product_type'      => $doc['product_type'],
                            // 'visibility'        => $doc['visibility'],
                            'barcode'           =>  $editedData['barcode'] ?? $doc['barcode'] ?? "",
                            'app_codes'         => $doc['app_codes'],
                            'variant_attributes' => $doc['variant_attributes'],
                            'price'              => $editedData['price'] ?? $doc['price'],
                            'quantity'              => $editedData['quantity'] ?? $doc['quantity']
                        ];
                    } else {

                        $rows[$doc['container_id']] = [
                            'user_id'           => $doc['user_id'],
                            'container_id'      => $doc['container_id'],
                            'main_image'        => $doc['main_image'],
                            'title'             => $editedData['title'] ?? $doc['title'],
                            'additional_images' => $doc['additional_images'],
                            'sku'               => $editedData['sku'] ?? $doc['sku'],
                            // 'type'              => $doc['type'],
                            'brand'             => $editedData['brand'] ?? $doc['brand'],
                            'description'       => $editedData['description'] ?? $doc['description'] ?? '',
                            'product_type'      => $doc['product_type'],
                            // 'visibility'        => $doc['visibility'],
                            'barcode'           =>  $editedData['barcode'] ?? $doc['barcode'] ?? "",
                            'app_codes'         => $doc['app_codes'],
                            'variant_attributes' => $doc['variant_attributes'],
                            'price'              => $editedData['price'] ?? $doc['price'],
                            'quantity'           => $editedData['quantity'] ?? $doc['quantity']
                        ];
                    }

                    // $rows[$doc['container_id']]=$doc;

                    if (!empty($doc['profile'])) {
                        $rows[$doc['container_id']]['profile'] = $doc['profile'];
                    }

                    $limit--;
                }

                $it->next();
            }

            $productStatuses = $this->prepareProductsStatus($distinctContainerIds, $amazonProductsData);
            $this->di->getLog()->logContent('data' . json_encode($productStatuses), 'info', 'csv_test1.log');
            foreach ($rows as $c_id => $row) {
                $rows[$c_id]['variants'] = $productStatuses[$c_id]['variations'] ?? [];
                // if(!isset($productStatuses[$c_id]['container_source_product_id'])){
                //     $this->di->getLog()->logContent('data'. json_encode($productStatuses), 'info', 'csv_test1.log');

                //     die;

                // }
                $rows[$c_id]['source_product_id'] = $productStatuses[$c_id]['container_source_product_id'] ?? '';
                $rows[$c_id]['type'] = $productStatuses[$c_id]['container_type'] ?? '';
                $rows[$c_id]['visibility'] = $productStatuses[$c_id]['container_visibility'] ?? '';
            }

            $rows = array_values($rows);

            if (!$it->isDead()) {
                $next = base64_encode(json_encode([
                    'cursor' => ($doc)['_id'],
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                // 'data' => $aggregation
            ];
        }

        $responseData = [
            'success' => true,
            // 'query' =>  $aggregation,
            'data' => [
                'totalPageRead' => $page ?? $totalPageRead,
                'current_count' => count($rows),
                'rows' => $rows,
                'next' => $next ?? null
            ]
        ];

        return $responseData;
    }

    private function prepareProductsStatus(array $container_ids, array $amazonProductsData = []): array
    {
        if (in_array($this->di->getUser()->id, ['6116eb5b91b6367b2e5c41f2', '613edca8807b015973566456', '611cbdc45a71ee43e7008871', '6130e5adf6725006e115e6aa'])) {
            $status_arr = [];

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getCollection();

            $aggregation = [];

            $aggregation[] = ['$sort' => ['visibility' => 1]];

            $aggregation[] = ['$match' => ['container_id' => ['$in' => $container_ids]]];

            $_group = [
                '_id'       => '$container_id',
                'variants'   => [
                    '$push' => [
                        'container_id'      => '$container_id',
                        'source_product_id' => '$source_product_id',
                        'sku'               => '$sku',
                        'variant_title'     => '$variant_title',
                        'type'              => '$type',
                        'visibility'        => '$visibility',
                        'locations'         => '$locations',
                        'quantity'          => '$quantity',
                        'barcode'           => '$barcode',
                        'variant_image'     => '$variant_image',
                        'title'             => '$title',
                        'description'       => '$description',
                        'brand'             => '$brand',
                        'price'             =>  '$price'
                    ]
                ]
            ];

            $select_fields = ['user_id', 'container_id', 'source_product_id', 'group_id', 'title', 'profile', 'main_image', 'description', 'type', 'brand', 'product_type', 'visibility', 'app_codes', 'additional_images', 'variant_attributes', 'barcode', 'price', 'quantity'];

            foreach ($select_fields as $field) {
                $_group[$field] = ['$first' => '$' . $field];
            }

            $aggregation[] = [
                '$group' => $_group
            ];

            $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];

            $products = $collection->aggregate($aggregation, $options)->toArray();
            foreach ($products as $product) {
                $status_arr[$product['_id']] = [
                    'user_id'           => $product['user_id'],
                    'container_id'      => $product['container_id'],
                    'main_image'        => $product['main_image'],
                    'title'             => $amazonProductsData[$product['source_product_id']]['title'] ?? $product['title'],
                    'additional_images' => $product['additional_images'],
                    // 'type'              => $product['type'],
                    'brand'             => $amazonProductsData[$product['source_product_id']]['brand'] ?? $product['brand'],
                    'description'       => $amazonProductsData[$product['source_product_id']]['brand'] ?? $product['description'] ?? '',
                    'product_type'      => $product['product_type'],
                    // 'visibility'        => $product['visibility'],
                    'app_codes'         => $product['app_codes'],
                    'variant_attributes' => $product['variant_attributes'],
                    'sku' => $amazonProductsData[$product['source_product_id']]['sku'] ?? $product['sku'],
                    'price' => $amazonProductsData[$product['source_product_id']]['price'] ?? $product['price'],
                    'quantity' => $amazonProductsData[$product['source_product_id']]['quantity'] ?? $product['quantity'],
                    'barcode' => $amazonProductsData[$product['source_product_id']]['barcode'] ?? $product['barcode']
                ];

                if (!empty($product['profile'])) {
                    $status_arr[$product['_id']]['profile'] = $product['profile'];
                }


                if (count($product['variants']) == 1) {
                    $status_arr[$product['_id']]['source_product_id'] = current($product['variants'])['source_product_id'];
                    $status_arr[$product['_id']]['type'] = current($product['variants'])['type'];
                    $status_arr[$product['_id']]['visibility'] = current($product['variants'])['visibility'];

                    $status_arr[$product['_id']]['variants'] = [];
                } else {
                    foreach ($product['variants'] as $variant) {
                        if (!array_key_exists('variant_title', $variant)) {
                            $status_arr[$product['_id']]['source_product_id'] = $variant['source_product_id'];
                            $status_arr[$product['_id']]['type'] = $variant['type'];
                            $status_arr[$product['_id']]['visibility'] = $variant['visibility'];
                        } else {
                            $status_arr[$product['_id']]['variants'][] = $variant;
                        }
                    }
                }
            }

            return $status_arr;
        }

        $status_arr = [];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource(Helper::PRODUCT_CONTAINER)->getCollection();
        $aggregation = [
            ['$sort' => ['visibility' => 1]],
            ['$match' => ['container_id' => ['$in' => $container_ids]]],
            [
                '$group' => [
                    '_id'       => '$container_id',
                    'variants'   => [
                        '$push' => [
                            'container_id'      => '$container_id',
                            'source_product_id' => '$source_product_id',
                            'sku'               => '$sku',
                            'variant_title'     => '$variant_title',
                            'type'              => '$type',
                            'visibility'        => '$visibility',
                            'locations'         => '$locations',
                            'quantity'          => '$quantity',
                            'barcode'           => '$barcode',
                            'variant_image'     => '$variant_image',
                            'title'             => '$title',
                            'description'       => '$description',
                            'brand'             => '$brand',
                            'price'             =>  '$price'
                        ]
                    ]
                ]
            ]
        ];
        $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];
        $products = $collection->aggregate($aggregation, $options)->toArray();
        foreach ($products as $product) {
            if (count($product['variants']) == 1) {
                current($product['variants'])['title'] = $amazonProductsData[current($product['variants'])['source_product_id']]['title'] ?? current($product['variants'])['title'] ?? '';
                current($product['variants'])['description'] = $amazonProductsData[current($product['variants'])['source_product_id']]['description'] ?? current($product['variants'])['description'] ?? '';
                if ($this->di->getUser()->id == "616d6f9a9be3302a6537fae3") {
                    current($product['variants'])['sku'] ??= '';
                    current($product['variants'])['edited_sku'] = $amazonProductsData[current($product['variants'])['source_product_id']]['sku'] ?? '';
                } else {
                    current($product['variants'])['sku'] = $amazonProductsData[current($product['variants'])['source_product_id']]['sku'] ?? current($product['variants'])['sku'] ?? '';
                }

                current($product['variants'])['brand'] = $amazonProductsData[current($product['variants'])['source_product_id']]['brand'] ?? current($product['variants'])['brand'] ?? '';
                current($product['variants'])['quantity'] = $amazonProductsData[current($product['variants'])['source_product_id']]['quantity'] ?? current($product['variants'])['quantity'] ?? '';
                current($product['variants'])['price'] = $amazonProductsData[current($product['variants'])['source_product_id']]['price'] ?? current($product['variants'])['price'] ?? '';
                current($product['variants'])['barcode'] = $amazonProductsData[current($product['variants'])['source_product_id']]['barcode'] ?? current($product['variants'])['barcode'] ?? '';
                $status_arr[$product['_id']]['container_source_product_id'] = current($product['variants'])['source_product_id'];
                $status_arr[$product['_id']]['container_type'] = current($product['variants'])['type'];
                $status_arr[$product['_id']]['container_visibility'] = current($product['variants'])['visibility'];
            } else {
                foreach ($product['variants'] as $variant) {
                    $variant['title'] = $amazonProductsData[$variant['source_product_id']]['title'] ?? $variant['title'] ?? '';
                    $variant['description'] = $amazonProductsData[$variant['source_product_id']]['description'] ?? $variant['description'] ?? '';
                    if ($this->di->getUser()->id == "616d6f9a9be3302a6537fae3") {
                        $variant['sku'] ??= '';
                        $variant['edited_sku'] = $amazonProductsData[$variant['source_product_id']]['sku'] ?? '';
                    } else {
                        $variant['sku'] = $amazonProductsData[$variant['source_product_id']]['sku'] ?? $variant['sku'] ?? '';
                    }

                    $variant['brand'] = $amazonProductsData[$variant['source_product_id']]['brand'] ?? $variant['brand'] ?? '';
                    $variant['quantity'] = $amazonProductsData[$variant['source_product_id']]['quantity'] ?? $variant['quantity'] ?? '';
                    $variant['price'] = $amazonProductsData[$variant['source_product_id']]['price'] ?? $variant['price'] ?? '';
                    $variant['barcode'] = $amazonProductsData[$variant['source_product_id']]['barcode'] ?? $variant['barcode'] ?? '';
                    if (!array_key_exists('variant_title', $variant)) {
                        $status_arr[$product['_id']]['container_source_product_id'] = $variant['source_product_id'];
                        $status_arr[$product['_id']]['container_type'] = $variant['type'];
                        $status_arr[$product['_id']]['container_visibility'] = $variant['visibility'];
                    } else {
                        $status_arr[$product['_id']]['variations'][] = $variant;
                    }
                }
            }
        }

        return $status_arr;
    }

    public function getProductsCount($params)
    {
        //to verify the user of this fucntion and then remove this function

        $params['app_code'] = 'amazon_sales_channel';
        $params['marketplace'] = 'amazon';

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getCollection();

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        $userId = $this->di->getUser()->id;

        $aggregation = [];

        if ($type != 'admin') {
            $aggregation[] = ['$match' => ['user_id' => $userId]];
        }

        if (isset($params['app_code'])) {
            $aggregation[] = [
                // '$match' => ['app_codes' => ['$in' => [$params['app_code']]]],
                '$match' => ['app_codes' => $params['app_code']],
            ];
        }

        // $aggregation[] = [
        //     '$graphLookup' => [
        //         "from"              => "product_container",
        //         "startWith"         => '$container_id',
        //         "connectFromField"  => "container_id",
        //         "connectToField"    => "group_id",
        //         "as"                => "variants",
        //         "maxDepth"          => 1
        //     ]
        // ];

        // $aggregation[] = [
        //     '$match' => [
        //         '$or' => [
        //             ['$and' => [
        //                 ['type' => 'variation'],
        //                 ["visibility" => 'Catalog and Search'],
        //             ]],
        //             ['$and' => [
        //                 ['type' => 'simple'],
        //                 ["visibility" => 'Catalog and Search'],
        //             ]],
        //         ],
        //     ],
        // ];

        $aggregation = array_merge($aggregation, $this->buildAggregateQuery($params, 'productCount'));

        $aggregation[] = [
            '$group' => [
                '_id' => '$container_id'
            ]
        ];

        $aggregation[] = [
            '$count' => 'count',
        ];

        try {
            $totalVariantsRows = $collection->aggregate($aggregation)->toArray();
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()/*,'data' => $aggregation*/];
        }

        $responseData = [
            'success' => true,
            'query' => $aggregation,
            'data' => [],
        ];

        $totalVariantsRows = $totalVariantsRows[0]['count'] ?? 0;
        $responseData['data']['count'] = $totalVariantsRows;
        return $responseData;
    }

    private function buildAggregateQuery(array $params, string $callType = 'getProduct'): array
    {
        $productOnly = false;
        $aggregation = [];
        $andQuery = [];
        $orQuery = [];
        $filterType = '$and';

        if (isset($params['productOnly'])) {
            $productOnly = $params['productOnly'] === "true";
        }

        if (isset($params['filterType'])) {
            $filterType = '$' . $params['filterType'];
        }

        if (isset($params['filter']) || isset($params['search'])) {
            // $andQuery = self::search($params);
            $andQuery = ProductContainer::search($params);
        }

        if (isset($params['or_filter'])) {
            // $orQuery = self::search(['filter' => $params['or_filter']]);
            $orQuery = ProductContainer::search(['filter' => $params['or_filter']]);
            $temp = [];
            foreach ($orQuery as $optionKey => $orQueryValue) {
                $temp[] = [
                    $optionKey => $orQueryValue,
                ];
            }

            $orQuery = $temp;
        }

        if (isset($params['next']) && $callType === 'getProduct') {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $aggregation[] = [
                '$match' => ['_id' => [
                    '$gt' => $nextDecoded['cursor'],
                ]],
            ];
        } elseif (isset($params['prev']) && $callType === 'getProduct') {
            $aggregation[] = [
                '$match' => ['_id' => [
                    '$lt' => $params['prev'],
                ]],
            ];
        }

        if (isset($params['container_ids'])) {
            $aggregation[] = [
                '$match' => ['container_id' => [
                    '$in' => $params['container_ids']
                ]],
            ];
        }

        if (count($andQuery)) {
            $aggregation[] = ['$match' => [
                $filterType => [
                    $andQuery,
                ],
            ]];
        }

        if ($orQuery !== []) {
            $aggregation[] = ['$match' => [
                '$or' => $orQuery,
            ]];
        }

        return $aggregation;
    }

    public function getValueFromNestedArray($array, $path, $var = [], $delimiter = '|')
    {
        $keys = explode($delimiter, $path);
        foreach ($keys as $key) {
            $varValue = $this->getVarFromPath($key);
            if (!is_null($varValue)) {
                $key = preg_replace('/\[(.*)\]/', '', $key);
            }

            if (isset($array[$key])) {
                $array = $array[$key];
                if (!is_null($varValue)) {
                    $key = array_search($var[$varValue], array_column($array, $varValue));
                    if ($key !== false) {
                        $array = $array[$key];
                    }
                }
            } else {
                return null;
            }
        }

        return $array;
    }

    function setValueInNestedArray(&$array, $path, $value, $var = [], $delimiter = '|'): void
    {
        $keys = explode($delimiter, $path);
        $lastKeyIndex = count($keys) - 1;

        foreach ($keys as $i => $key) {
            // Check if key has an index
            if (preg_match('/(.*)\[(.*)\]/', $key, $matches)) {
                $key = $matches[1];
                $varKey = $matches[2];
                // Initialize key as an array if it's not set or not an array
                if (!isset($array[$key]) || !is_array($array[$key])) {
                    $array[$key] = [];
                    $array[$key][] = [
                        $varKey => $var[$varKey]
                    ];
                    $index = 0;
                } else {
                    $index = array_search($var[$varKey], array_column($array[$key], $varKey));
                    if ($index === false) {
                        $index = 0;
                    }
                }

                // If it's the last key, set the value
                if ($i === $lastKeyIndex) {
                    $array[$key][$index][$keys[$i]] = $value;
                } else {
                    $array = &$array[$key][$index];
                }
            } else {
                // Handle normal key
                if (!isset($array[$key])) {
                    $array[$key] = [];
                }

                // If it's the last key, set the value
                if ($i === $lastKeyIndex) {
                    $array[$key] = $value;
                } else {
                    $array = &$array[$key];
                }
            }
        }
    }

    public function getVarFromPath($path)
    {
        if (preg_match('/(.*)\[(.*)\]/', $path, $matches)) {
            return $matches[2];
        }

        return null;
    }

    private function getActualPath($keyPath)
    {
        if (is_string($keyPath)) {
            return $keyPath;
        }

        return $keyPath['path'];
    }
}
