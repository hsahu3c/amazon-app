<?php

namespace App\Connector\Controllers;

use Phalcon\Mvc\Controller;

use Phalcon\Di;

class CsvController extends \App\Core\Controllers\BaseController
{
    const CSV_EXPORT_CHUNK_SIZE = 1500;

    const CSV_REQUIRED_COLUMNS = [
        'parent id', 'child id'
    ];

    public function exportCSVAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $getParams = $this->request->getJsonRawBody(true);
        } else {
            $getParams = $this->request->get();
        }

        $locale =  $this->request->getHeader('locale') ?? 'en';

        $getRequester = $this->di->getRequester();
        $userId = $this->di->getUser()->id;

        $shop_id =  $getRequester->getSourceId();
        $containerModel = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');
        $dataCount = $containerModel->getProductsCount(['user_id' => $userId, 'shop_id' => $shop_id]);
        $countOfProducts = $dataCount['data']['count'] ?? '';
        if ($countOfProducts == 0) {
            // these conditions are just for handling and removing the entries from db if user meets this condition and previoulsy he has entries in these collections
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $csv_product_collection = $mongo->getCollection("csv_export_product_container");

            $csv_product_collection_data  = $csv_product_collection->deleteMany(
                [
                    'user_id'                   => $userId,
                    'source_shop_id'            => $shop_id,
                ]
            );

            $config_collection = $mongo->getCollection("config");

            $exported_file_name = $config_collection->deleteMany(
                [
                    'user_id'           => $userId,
                    'source_shop_id'    => $shop_id,
                    'group_code'        => 'csv_product',
                ]
            );

            $queued_tasks_coll = $mongo->getCollection('queued_tasks');
            $delete_entry_for_queued_tasks = $queued_tasks_coll->deleteMany(
                [
                    'user_id'           => $userId,
                    'shop_id'           => $shop_id,
                    'process_code'      => 'csv_product',
                ]
            );
            return $this->prepareResponse(
                [
                    'success' => false,
                    'message' => '0 products found for exporting.'
                ]
            );
        }

        $validate = $this->validateExportProcess($getParams);

        if ($validate['success']) {
            $postData = $this->request->getJsonRawBody();
            $exportType = $getParams['type'] ?? 'all'; ///'all';//all,selected,current_page
            $filterSelected = $getParams['dataToExport'] ?? ['title', 'barcode', 'brand', 'vendor', 'price', 'quantity']; //title,sku,barcode,brand,vendor,price,inventory
            $getParams['language'] = $locale;
            $getParams['filter_columns'] = $filterSelected;
            $getParams['export_type'] = $exportType;
            $getParams['count'] = $getParams['limit'] ?? self::CSV_EXPORT_CHUNK_SIZE;
            $this->insertionInConfigForExport($getParams);
            $csvHelper = $this->di->getObjectManager()->get('\App\Connector\Components\CsvHelper');

            $config = $this->di->getConfig();
            $bucketForS3Upload = $config->get("bulk_update_through_s3");
            //check if the file will be saved on S3 or on server.
            if ($bucketForS3Upload) {
                $returnArr = $csvHelper->getProductDetails_S3($getParams);
            } else {

                $returnArr = $csvHelper->getProductDetails($getParams);
            }

            return $this->prepareResponse(
                [
                    'data' => $returnArr,
                    'success' => true,
                    'message' => $this->di->getLocale()->_('csv_export_in_progress')
                ]
            );
        }
        return $this->prepareResponse($validate);
    }

    public function validateExportProcess($getParams)
    {
        $userId = $this->di->getUser()->id;
        $getRequester = $this->di->getRequester();
        $shopId =  $getRequester->getSourceId();
        $this->di->getRequester()->getTargetId();
        $targetShopId = $getRequester->getTargetId();
        if ($userId == '67266078ce2563409e0c8ec2') {
            $shopId = $targetShopId;
        }
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        if (isset($getParams['new_process']) && $getParams['new_process'] = true) {
            $configCollection = $mongo->getCollection("config");
            $configCollectionEntry = $configCollection->find(
                [
                    'user_id'           => $userId,
                    'source_shop_id'    => $shopId,
                    'group_code'        => 'csv_product',
                ]
            )->toArray();
            if (count($configCollectionEntry)) {
                $configData = $configCollectionEntry;
                $exportedFileName = '';
                $csvExportOptionsFilter = '';
                $csvPageFilter = '';

                foreach ($configData as $value) {
                    if ($value['key'] == 'csv_page_filter') {
                        $csvPageFilter = $value['value'];
                    } elseif ($value['key'] == 'csv_export_options_filter') {
                        $csvExportOptionsFilters = [];
                        $csvExportOptionsFilterValue = $value['value'];
                        foreach ($csvExportOptionsFilterValue as $v) {
                            $csvExportOptionsFilters[] = $v;
                        }

                        $csv_export_options_filter_string = implode(', ', $csvExportOptionsFilters);
                    } elseif ($value['key'] == 'exported_file_name') {
                        $exportedFileName = $value['value'];
                    }
                }


                return ([
                    'success' => false,
                    'message' => $this->di->getLocale()->_('export_process_with_same_filters', [
                        'csvPageFilter' => $csvPageFilter,
                        'csv_export_options_filter_string' => $csv_export_options_filter_string,
                        'exportedFileName' => $exportedFileName
                    ])
                ]
                );
            }
            return ['success' => true];
        }


        if (isset($getParams['remove_last']) && $getParams['remove_last'] == true) {
            $queuedTasksCollection = $mongo->getCollection("queued_tasks");
            $queuedTasksCollection = $queuedTasksCollection->deleteMany(
                [
                    'user_id'           => $userId,
                    'shop_id'           => $shopId,
                    'message'           => 'CSV Export in progress',
                ]
            );
            $csvProductCollection = $mongo->getCollection("csv_export_product_container");
            $csvProductCollectionData  = $csvProductCollection->deleteMany(
                [
                    'user_id'                   => $userId,
                    'source_shop_id'            => $shopId,
                ]
            );
            $configCollection = $mongo->getCollection("config");
            $exportedFileName = $configCollection->findOne(
                [
                    'user_id'           => $userId,
                    'source_shop_id'    => $shopId,
                    'group_code'        => 'csv_product',
                    'key'               => 'exported_file_name'
                ]
            );
            if ($exportedFileName && count($exportedFileName)) {
                $directory = BP . DS . 'var/log/UserProductCSV/';
                $exportedFileName = $exportedFileName['value'];
                $fileToRemovePath = $directory . $exportedFileName;
                if (file_exists($fileToRemovePath))
                    unlink($directory . $exportedFileName);
            }
            $configEntryRemove = $configCollection->deleteMany(
                [
                    'user_id'           => $userId,
                    'source_shop_id'    => $shopId,
                    'group_code'        => 'csv_product',
                ]
            );
            if ($configEntryRemove) {
                return ['success' => true];
            }
        }

        return ['success' => false];
    }

    public function insertionInConfigForExport($getParams): void
    {
        $sourceShopId = $this->di->getRequester()->getSourceId();
        $targetShopId = $this->di->getRequester()->getTargetId();
        $appTag = $this->di->getAppCode()->getAppTag();
        $source = $this->di->getRequester()->getSourceName();
        $target = $this->di->getRequester()->getTargetName();
        $created_at = date('c');

        $data = [
            "group_code"        => "csv_product",
            "source_shop_id"    => $sourceShopId,
            "target_shop_id"    => $targetShopId,
            "app_tag"           => $appTag,
            "user_id"           => $this->di->getUser()->id,
            "source"            => $source,
            "target"            => $target,
            "created_at"        => $created_at,
        ];

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $configCollection = $mongo->getCollection("config");

        if (isset($getParams['filter_columns'])) {
            $data['key'] = 'csv_export_options_filter';
            $data['value'] = $getParams['filter_columns'];
            $configCollection->insertOne($data);
        }

        if (isset($getParams['csv_page_filter'])) {
            $data['key'] = 'csv_page_filter';
            $data['value'] = $getParams['export_type'];
            $configCollection->insertOne($data);
        }

        if (isset($getParams['key_path'])) {
            $data['key'] = 'key_path';
            $data['value'] = $getParams['key_path'];
            $configCollection->insertOne($data);
        }
    }

    public function getExportOptionsAction()
    {
        $options = ['title', 'description', 'sku', 'quantity', 'price', 'brand', 'barcode'];
        return $this->prepareResponse(['success' => true, 'options' => $options]);
    }

    public function downloadCSVAction(): void
    {
        $shipmentFileToken = $this->di->getRequest()->get('file_token');
        try {
            $token = $this->di->getObjectManager()->get('App\Core\Components\Helper')->decodeToken($shipmentFileToken, false);
            $filePath = $token['data']['file_path'];
            $file_name = basename($filePath); // $file is set to "index"

            if (file_exists($filePath)) {
                header('Content-Type: ' . 'csv' . '; charset=utf-8');
                header('Content-Disposition: attachment; filename=' . $file_name);
                @readfile($filePath);
                die;
            }
            die('Invalid Url. No report found.');
        } catch (\Exception) {
            die('Invalid Url. No report found.');
        }
    }

    public function importCSVAction()
    {

        $sourceShopId = $this->di->getRequester()->getSourceId();
        $targetShopId = $this->di->getRequester()->getTargetId();
        $source = $this->di->getRequester()->getSourceName();
        $target = $this->di->getRequester()->getTargetName();

        $rawBody = $this->request->getJsonRawBody(true);
        $this->di->getRequest()->get();
        $userId = $this->di->getUser()->id;
        $appTag = $this->di->getAppCode()->getAppTag();
        $path = BP . DS . 'var/file/';
        $files = $this->request->getUploadedFiles();
        $name = "";

        $rawBody['source_shop_id'] = $sourceShopId;
        $rawBody['target_shop_id'] = $targetShopId;
        $rawBody['source'] = $source;
        $rawBody['target'] = $target;
        $rawBody['app_tag'] = $appTag;
        $rawBody['user_id'] = $userId;
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');

        //check if exporting is already in process
        $queued_task_collection = $mongo->getCollection("queued_tasks");
        $configCollection = $mongo->getCollection("config");

        $queued_task_check = $queued_task_collection->find(
            [
                'user_id'           => $userId,
                'message'           => 'CSV Import in Progress',
                'shop_id'           => $targetShopId,
                'process_code'      => "csv_product",
                'marketplace'       => $target,
            ]
        )->toArray();

        if (count($queued_task_check) > 0) {
            return json_encode(['success' => false, 'message' => $this->di->getLocale()->_('csv_import_in_progress')]);
        }

        $checkConfigExportData = $configCollection->find([
            'group_code'        => 'csv_product',
            'user_id'           => $userId,
            'source_shop_id'    => $sourceShopId,
            'target_shop_id'    => $targetShopId,
            'app_tag'           => $appTag
        ])->toArray();

        $checkConfigExportData = \GuzzleHttp\json_decode(\GuzzleHttp\json_encode($checkConfigExportData), true);

        $filterExported = [];
        $exportedFileName = '';

        if (count($checkConfigExportData) > 0) {
            foreach ($checkConfigExportData as $vData) {
                if ($vData['key'] == 'exported_file_name') {
                    $exportedFileName = $vData['value'];
                    $name = $userId . '_' . $exportedFileName;
                } elseif ($vData['key'] == 'csv_export_options_filter') {
                    $filterExported = $vData['value'];
                }
            }

            if ($name == "") {
                return json_encode(['success' => false, 'message' => 'Imported file name cannot be blank.']);
            }
        } else {
            return json_encode(['success' => false, 'message' => $this->di->getLocale()->_('no_export_history_found')]);
        }

        $allowed = ["text/csv", "application/vnd.ms-excel"];

        foreach ($files as $file) {
            if (!in_array($file->getType(), $allowed)) {
                return $this->prepareResponse(['error' => "file type not csv", 'success' => false, 'message' => 'Please use CSV file type only']);
            }

            $importedFileName = $file->getName();

            //check the name of imported file, it should be same as what name was exported
            if ($importedFileName != $exportedFileName) {
                return $this->prepareResponse(['success' => false, 'message' => $this->di->getLocale()->_('import_csv_must_be_export_csv', [
                    'exportedFileName' => $exportedFileName
                ])]);
            }

            $file->moveTo(
                $path . $name
            );
        }

        $count = 0;

        $mongo->getCollectionForTable('user_details');

        $file = fopen($path . $name, 'r');
        $fileHeaders = [];
        while (($handle = fgetcsv($file)) !== false) {
            if ($count == 0) {
                $columns = $handle;
                $columnsMatched = 0;
                $requiredColumns = 0;

                foreach ($columns as $c_name) {
                    $fileHeaders[] = $c_name;
                    if (in_array(strtolower($c_name), $filterExported)) {
                        $columnsMatched++;
                    }

                    if (in_array(strtolower($c_name), self::CSV_REQUIRED_COLUMNS)) {
                        $requiredColumns++;
                    }
                }

                if ($columnsMatched == 0) {
                    return json_encode(['success' => false, 'message' => 'No column found in CSV to update']);
                }

                if ($requiredColumns == 0) {
                    return json_encode(['success' => false, 'message' => $this->di->getLocale()->_('required_columns', [
                        'requiredColumns' => implode(', ', self::CSV_REQUIRED_COLUMNS)
                    ])]);
                }
            }

            $count++;
        }

        fclose($file);

        //if file is blank or only headers are in the file
        if ($count == 1 || $count == 0) {
            return json_encode(['success' => false, 'message' => 'No data found in imported file to update.']);
        }

        $rawBody['count'] = $count;
        $rawBody['path'] = $path;
        $rawBody['name'] = $name;
        $rawBody['config_data'] = $checkConfigExportData;
        $rawBody['import_limit'] = self::CSV_EXPORT_CHUNK_SIZE;
        $rawBody['file_headers'] = $fileHeaders;

        $helper = $this->di->getObjectManager()->get('\App\Connector\Components\CsvHelper');
        // TODO : change the name
        $returnArr = $helper->importCSVAndUpdate($rawBody);

        return $this->prepareResponse(['data' => $returnArr, 'success' => true, "message" => $this->di->getLocale()->_('csv_import_in_progress')]);
    }

    public function exportedFileNameAction()
    {
        $userId = $this->di->getUser()->id;
        $targetShopId = $this->di->getRequester()->getTargetId();
        $this->di->getAppCode()->getAppTag();
        $this->di->getRequester()->getSourceName();
        $this->di->getRequester()->getTargetName();
        $sourceShopId = $this->di->getRequester()->getSourceId();

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');

        $configCollection = $mongo->getCollection("config");

        $exportedFileName = $configCollection->findOne(
            [
                'user_id'           => $userId,
                'source_shop_id'    => $sourceShopId,
                'target_shop_id'    => $targetShopId,
                'group_code'        => 'csv_product',
                'key'               => 'exported_file_name'
            ]
        );

        if ($exportedFileName) {
            return $this->prepareResponse(['success' => true, 'data' => $exportedFileName]);
        }
        return $this->prepareResponse(['success' => false]);
    }

    public function S3fileDownloadAction()
    {
        try {
            // Determine the content type
            $contentType = $this->request->getHeader('Content-Type');
            // Parse parameters based on content type
            $getParams = ($contentType && strpos($contentType, 'application/json') !== false) ? $this->request->getJsonRawBody(true) : $this->request->get();
            // Initialize cURL session
            $ch = curl_init($getParams['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // MongoDB setup
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $notification_collection = $mongo->getCollection("notifications");
            $notificationData = $notification_collection->findOne(['_id' => new \MongoDB\BSON\ObjectId($getParams['_id'])]);
                // Check if URL is valid
                if ($responseCode == 200) {
                    return $this->prepareResponse([
                        "success" => true,
                        "data" => $notificationData,
                        'message' => "URL is valid"
                    ]);
                }
                // URL is expired
                return $this->prepareResponse([
                    "success" => false,
                    'message' => "URL is expired"
                ]);

        } catch (\Exception $e) {
            // Exception occurred
            return $this->prepareResponse([
                "success" => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
