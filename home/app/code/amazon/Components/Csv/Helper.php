<?php
/**
 * Created by PhpStorm.
 * User: cedcoss
 * Date: 2/9/22
 * Time: 2:58 PM
 */

namespace App\Amazon\Components\Csv;

use App\Core\Components\Base;

class Helper extends Base
{
    const CSV_EXPORT_CHUNK_SIZE = 300;
    const SQS_THRESHOLD = 1000; // Use SQS if product count exceeds this threshold

    public function processCsvData($updatedData, $sqsData)
    {
        $column_exported = [];
        $bulk_insert_data = [];
        $bulk_update_data = [];
        $errors_in_data = [];
        $updated_count = 0;
        $inserted_count = 0;

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $csv_export_product_container = $mongo->getCollection("csv_export_product_container");
        $product_container = $mongo->getCollection("product_container");
        $userId = $sqsData['user_id'];
        $appTag = $sqsData['app_tag'];
        $source_shop_id = $sqsData['data']['shop_id'];
        $target_shop_id = $sqsData['data']['target_shop_id'];
        $source = $sqsData['data']['source_marketplace'];
        $target = $sqsData['data']['target_marketplace'];

        $additionalData['source'] = [
            'marketplace' => $source,
            'shopId' => (string) $source_shop_id
        ];
        $additionalData['target'] = [
            'marketplace' => $target,
            'shopId' => (string) $target_shop_id
        ];

        $config_data = $sqsData['data']['config_data'];
        foreach ($config_data as $value)
        {
            if($value['key'] == "csv_export_options_filter")
            {
                $column_exported = $value['value'];
            }
        }

        foreach ($updatedData as $container_id => $u_data)
        {
            $container_in_db = $product_container->find([
                'user_id'       => $userId,
                'container_id'  => (string)$container_id,
                'shop_id'       => $source_shop_id
            ])->toArray();

            foreach ($u_data as $row_data)
            {
                $validate = $this->validateImportedData($row_data, $sqsData);

                if(isset($validate['data']) && !empty($validate['data']))
                {
                    $checkAndUpdate = $this->checkAndUpdate($validate['data'], $container_in_db);

                    if(isset($checkAndUpdate['success']) && $checkAndUpdate['success'])
                    {
                        if(isset($checkAndUpdate['update']) && !empty($checkAndUpdate['update']))
                        {
                            $bulk_update_data[] = $checkAndUpdate['update'];
                        }
                        elseif(isset($checkAndUpdate['insert']) && !empty($checkAndUpdate['insert']))
                        {
                            $bulk_insert_data[] = $checkAndUpdate['insert'];
                        }
                    }
                    elseif (isset($validate['error']))
                    {
                        $errors_in_data[] = $validate['error'];
                    }
                }
            }
        }

        if(!empty($bulk_insert_data) || !empty($bulk_update_data))
        {
            $update_in_db = $this->updateCsvDataInDb($bulk_insert_data, $bulk_update_data, $additionalData);

            if($update_in_db['success'])
            {
                $updated_count = $update_in_db['updated'];
                $inserted_count = $update_in_db['inserted'];
            }
        }

        return [
            'success' => true,
            'updated_count' => $updated_count,
            'inserted_count' => $inserted_count,
            'errors' => $errors_in_data
        ];

    }

    public function validateImportedData($row_data, $sqsData)
    {
        $appTag = $sqsData['app_tag'];
        $source_shop_id = $sqsData['data']['shop_id'];
        $target_shop_id = $sqsData['data']['target_shop_id'];
        $source = $sqsData['data']['source_marketplace'];
        $target = $sqsData['data']['target_marketplace'];
        $user_id = $sqsData['user_id'];

        $source_product_id = $row_data['source_product_id'];
        $container_id = $row_data['container_id'];
        $type = strtolower((string) $row_data['type']);
        $dataFormatted = [];
        $restrictedKeysToUpdate = ['sku', 'barcode']; // not to update them if product is already on amazon

        if($type == 'main' && $source_product_id == $container_id)
        {
            //variant_product_parent
            $update_only_keys = ['title', 'sku'];
        }
        elseif($type == 'main' && $source_product_id != $container_id)
        {
            //simple product
            $update_only_keys = ['title','price','quantity','barcode','sku','vendor'];
        }
        else
        {
            //variant
            $update_only_keys = ['title','price','quantity','barcode','sku','vendor'];
        }

        foreach ($update_only_keys as $value)
        {
            if(isset($row_data[$value]) && $row_data[$value] != '')
            {
                if($value == 'quantity')
                {
                    $dataFormatted[$value] =  (int)$row_data[$value];
                }
                elseif ($value == 'price')
                {
                    $dataFormatted[$value] =  (float)number_format($row_data[$value],2,".","");
                }
                else
                {
                    $dataFormatted[$value] =  $row_data[$value];
                }
            }
        }

        if(!empty($dataFormatted))
        {
            //$dataFormatted['type'] = $type;
            $dataFormatted['source_product_id'] = (string)$source_product_id;
            $dataFormatted['container_id'] = (string)$container_id;
            $dataFormatted['shop_id'] = $target_shop_id;
            $dataFormatted['source_shop_id'] = $source_shop_id;
            $dataFormatted['target_marketplace'] = $target;
            $dataFormatted['user_id'] = $user_id;
        }

        return ['success' => true, 'data' => $dataFormatted];
    }

    public function checkAndUpdate($data_to_update, $container_in_db)
    {
        // in this function we will check that in product container editted doc is there or not, if doc exits we will put data in update array by merging the existing data with new data else we will use insert array
        $data_to_be_updated = [];
        $data_to_insert = [];
        $data_to_be_updated_in_db = [];
        $marketplace = $data_to_update['target_marketplace'];
        $shop_id = $data_to_update['source_shop_id'];
        $source_product_id = $data_to_update['source_product_id'];
        $edit_data_found_in_db = false;
        $source_product_id_found = false;
        $errors = [];
        $data_to_update['dont_validate_barcode'] = true;

        foreach ($container_in_db as $container_data)
        {
            $container_data = json_decode(json_encode($container_data), true);

            if($container_data['source_product_id'] == $source_product_id)
            {
                $source_product_id_found = true;

                if(isset($container_data['target_marketplace'],$container_data['shop_id']) && $container_data['target_marketplace'] == $marketplace && $container_data['shop_id'] == $shop_id)
                {
                    if(isset($container_data['status']) && (strtolower((string) $container_data['status']) == 'active' || strtolower((string) $container_data['status']) == 'inactive'))
                    {
                        unset($data_to_update['sku']);
                        unset($data_to_update['barcode']);
                    }


                    $edit_data_found_in_db = true;

                    $data_to_be_updated = array_merge($container_data, $data_to_update);
                }
            }
        }

        if($edit_data_found_in_db)
        {
            $data_to_be_updated_in_db = $data_to_be_updated;
        }
        elseif ($source_product_id_found)
        {
            $data_to_insert = $data_to_update;
        }
        else
        {
            $data_to_update['error'] = 'source product id does not exist in db';
            $errors = $data_to_update;

            return ['success' => false, 'error' => $errors];
        }

        return ['success' => true, 'update' => $data_to_be_updated_in_db, 'insert' => $data_to_insert];



    }

    public function updateCsvDataInDb($data_to_insert, $data_to_update, $additionalData = [])
    {
        //in this function we will be saving the updated data in db by using the existing function use to save data in edit section by which the data will be updated in product and refine containers and desired info will be updated on marketplace

        //$products = new \App\Connector\Models\Product\Edit;
        $products = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');

        $data_to_change = array_merge($data_to_insert, $data_to_update);
        $inserted_count = 0;
        $updated_count = 0;
        $data_to_change = json_decode(json_encode($data_to_change , JSON_INVALID_UTF8_IGNORE) , true);
        $data_to_change_request = $products->saveProduct($data_to_change, false, $additionalData);

        if(isset($data_to_change_request['success']) && $data_to_change_request['success'])
        {
            $updated_count = $data_to_change_request['data']['bulk_info']['modified_count'] ?? 0 ;
            $inserted_count = $data_to_change_request['data']['bulk_info']['inserted_count'] ?? 0;
        }

        return ['success' => true, 'updated' => $updated_count, 'inserted' => $inserted_count, 'data' => $data_to_change_request];


        //this is the manual code for updation above is the code used in module that will update in refine products also
        /*$mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collectionObj = $mongo->getCollection('product_container');
        $updated_data_onj = [];

        if(!empty($data_to_insert))
        {
            $bulkInsertObj = $collectionObj->insertMany($data_to_insert);
            $inserted_count = $bulkInsertObj->getInsertedCount();
        }

        if(!empty($data_to_update))
        {
            foreach ($data_to_update as $key => $update_data)
            {
                $db_id = $update_data['_id']['$oid'];
                unset($update_data['_id']);
                $source_product_id = $update_data['source_product_id'];
                $container_id = $update_data['container_id'];
                $shop_id = $update_data['shop_id'];

                $update_data['title'] = 'manual testing done';

                $updateObj = $collectionObj->updateOne(
                    ['_id' => new \MongoDB\BSON\ObjectId($db_id)],
                    ['$set' => $update_data]
                );

                $updated_count = $updated_count + $updateObj->getModifiedCount();
            }
        }


        return ['success' => true, 'updated' => $updated_count, 'inserted' => $inserted_count];*/

    }

    /**
     * Validate Export Process.
     */
    public function validateExportProcess($getParams)
    {
        $userId = $this->di->getUser()->id;
        $getRequester = $this->di->getRequester();
        $shopId =  $getRequester->getSourceId();
        $targetId = $this->di->getRequester()->getTargetId();

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        if (isset($getParams['new_process']) && $getParams['new_process'] = true) {
            $configCollection = $mongo->getCollection("config");
            $configCollectionEntry = $configCollection->find(
                [
                    'user_id'           => $userId,
                    'source_shop_id'    => $shopId,
                    'target_shop_id'    =>  $targetId,
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
                    'target_shop_id'    =>  $targetId,
                    'message'           => 'CSV Export in progress',
                ]
            );
            $csvProductCollection = $mongo->getCollection("csv_export_product_container");
            $csvProductCollectionData  = $csvProductCollection->deleteMany(
                [
                    'user_id'                   => $userId,
                    'source_shop_id'            => $shopId,
                    'target_shop_id'            =>  $targetId,
                ]
            );
            $configCollection = $mongo->getCollection("config");
            $exportedFileName = $configCollection->findOne(
                [
                    'user_id'           => $userId,
                    'source_shop_id'    => $shopId,
                    'target_shop_id'    =>  $targetId,
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
                    'target_shop_id'    =>  $targetId,
                    'group_code'        => 'csv_product',
                ]
            );
            if ($configEntryRemove) {
                return ['success' => true];
            }
        }

        return ['success' => false];
    }

    /**
     * getProductDetails from Product Container and update the file in S3.
     */
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
            // Get actual count of all products (including children) for selected containers
            $containerModel = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');
            $dataCount = $containerModel->getProductsCount([
                'user_id' => $userId,
                'shop_id' => $source_shop_id,
                'container_ids' => $selected_product_ids, // Pass the selected container IDs
                'productOnly' => false // Include all variants/children
            ]);
            $countOfProducts = $dataCount['data']['count'] ?? 0;
        } else {
            $containerModel = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');
            $dataCount = $containerModel->getProductsCount([
                'user_id' => $userId,
                'shop_id' => $source_shop_id,
                'productOnly' => false
            ]);
            $countOfProducts = $dataCount['data']['count'] ?? 0;
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
            ->setQueuedTask($target_shop_id, $queuedTaskData);
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
                'limit'             => $data['limit'] ?? self::CSV_EXPORT_CHUNK_SIZE,
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

        // Decide between SQS and direct processing based on actual product count
        if ($countOfProducts > self::SQS_THRESHOLD) {
            // Use SQS for large datasets to avoid memory issues
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $sqsHelper->pushMessage($handlerData);
        } else {
            // Process directly for smaller datasets
            $feed_id = $this->di->getObjectManager()->get('App\Connector\Components\CsvHelper')
                ->ProductWriteCSVSQS_S3($handlerData);
        }

        return $file_name;
    }


    /**
     * Insert doc in config collection function
     */
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
}