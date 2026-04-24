<?php

namespace App\Amazon\Components\Template\BulkAttributesEdit\Delete;

use MongoDB\BSON\ObjectId;
use App\Connector\Models\QueuedTasks as ConnectorQueuedTasks;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Aws\S3\S3Client;

class Export
{
    const XLSX_EXPORT_CHUNK_SIZE = 250;
    const S3_BUCKET_NAME = "template-bulk-attributes-edit";
    const S3_URL_VALIDITY = "+72 hour";
    const FILE_BASE_PATH = BP . DS . 'var' . DS . 'file' . DS . 'template_bulk_delete_attribute';
    const FILE_NAME_PREFIX = "ced_amz_delete_attr";
    const EXPORT_PROCESS_CODE = 'template_bulk_attribute_delete_export';
    const EXPORT_QUEUE_NAME = 'template_bulk_attribute_delete_export';
    const MARKETPLACE = "amazon";

    public function export(array $params): array
    {
        if (!isset(
            $params['user_id'],
            $params['source_shop_id'],
            $params['target_shop_id'],
            $params['template_id'],
            $params['product_type'],
            $params['attributes_selected']
        )) {
            return [
                'success' => false,
                'message' => 'user_id, source_shop_id, target_shop_id and template_id required'
            ];
        }

        $hasExportInProgress = $this->hasExportInProgress($params);
        if ($hasExportInProgress) {
            return ['success' => false, 'message' => 'Export already in progress. Please check activity section for updates.']; // Updated message
        }

        $productCountResponse = $this->getTemplateProductsCount($params);
        if (!$productCountResponse['success']) {
            return $productCountResponse;
        }

        $productCount = $productCountResponse['product_count'];

        if ($productCount == 0) {
            return ['success' => false, 'message' => 'No products to export for this template.'];
        }

        $params['total_count'] = $productCount;
        $response = $this->commenceTemplateProductsExport($params);
        if ($response['success']) {
            return ['success' => true, 'message' => 'Export process initiated successfully. Please check activity section for updates.'];
        }
        return $response;
    }

    private function commenceTemplateProductsExport(array $params)
    {
        $queuedTaskData = [
            'user_id' => $params['user_id'],
            'message' => "Please wait while we export your products for attribute deletion. It might take a little while.",
            'process_code' => self::EXPORT_PROCESS_CODE,
            'marketplace' => self::MARKETPLACE,
        ];

        $addToQueuedTasksResponse = $this->addToQueuedTasks($queuedTaskData, $params['target_shop_id']);
        if (!$addToQueuedTasksResponse['success']) {
            return $addToQueuedTasksResponse;
        }

        $params['queued_task_id'] = $addToQueuedTasksResponse['queued_task_id'];

        return $this->pushToExportQueue($params);
    }

    private function addToQueuedTasks(array $queueData, string $shopId)
    {
        $queuedTask = $this->di->getObjectManager()->get(ConnectorQueuedTasks::class);
        $queuedTaskId = $queuedTask->setQueuedTask($shopId, $queueData);
        if (!$queuedTaskId) {
            return ['success' => false, 'message' => 'Template Bulk Attribute Delete export is already under progress. Please check activity section for updates.'];
        }

        return ['success' => true, 'queued_task_id' => $queuedTaskId];
    }

    private function pushToExportQueue(array $params)
    {
        $preparedQueueData =  [
            'type' => 'full_class',
            'class_name' => \App\Amazon\Components\Template\BulkAttributesEdit\Delete\Export::class,
            'method' => 'templateProductsExportDelete',
            'user_id' => $params['user_id'],
            'source_shop_id' => $params['source_shop_id'],
            'target_shop_id' => $params['target_shop_id'],
            'queue_name' => self::EXPORT_QUEUE_NAME,
            'data' => [
                'user_id' => $params['user_id'],
                'queued_task_id' => $params['queued_task_id'],
                'total_count' => $params['total_count'],
                'template_id' => $params['template_id'],
                'template_name' => $params['template_name'] ?? '',
                'attributes_selected' => $params['attributes_selected'],
                'product_type' => $params['product_type'],
                'source_shop_id' => $params['source_shop_id'],
                'target_shop_id' => $params['target_shop_id'],
                'cursor' => 1,
                'limit' => self::XLSX_EXPORT_CHUNK_SIZE
            ],
        ];
        return $this->pushMessageToQueue($preparedQueueData);
    }

    public function pushMessageToQueue($message)
    {
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        try {
            $response = $sqsHelper->pushMessage($message);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'data' => $response];
    }

    private function hasExportInProgress(array $params): bool
    {
        $queuedTaskCollection = $this->getBaseMongoCollection('queued_tasks');

        $hasInProgress = $queuedTaskCollection->countDocuments(
            [
                'user_id' => $params['user_id'],
                'shop_id' => $params['target_shop_id'],
                'process_code' => self::EXPORT_PROCESS_CODE,
                'marketplace' => self::MARKETPLACE,
            ]
        );

        return $hasInProgress > 0;
    }

    private function getTemplateProductsCount(array $params)
    {
        if (empty($params['template_id'])) {
            return ['success' => false, 'message' => 'template_id required'];
        }

        $templateId = ($params['template_id'] instanceof ObjectID)
            ? $params['template_id']
            : new ObjectId($params['template_id']);

        $productCount = 0;
        try {
            $refineProductContainer = $this->getBaseMongoCollection('refine_product');
            $productCount = $refineProductContainer->countDocuments([
                'user_id' => $params['user_id'],
                'source_shop_id' => $params['source_shop_id'],
                'target_shop_id' => $params['target_shop_id'],
                'profile.profile_id' => $templateId,
            ]);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'product_count' => $productCount];
    }

    private function getBaseMongoCollection(string $collection): object
    {
        return $this->di->getObjectManager()
            ->create('\App\Core\Models\BaseMongo')
            ->getCollection($collection);
    }

    public function templateProductsExportDelete(array $queueData)
    {
        $params = $queueData['data'] ?? [];
        $queuedTaskId = $params['queued_task_id'] ?? null;

        if (empty($queuedTaskId)) {
            return true;
        }

        $queuedTask = $this->di->getObjectManager()->get(ConnectorQueuedTasks::class);
        $hasExportInProgress = $queuedTask->checkQueuedTaskExists($queuedTaskId, $params['target_shop_id'] ?? null);
        if (!$hasExportInProgress) {
            $filePath = $params['file_path'] ?? null;
            if (!is_null($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }

            $notificationData = [
                'user_id' => $params['user_id'],
                'message' => 'Product export for attribute deletion has been terminated. Please try again later or contact support if the issue persists.',
                'marketplace' => self::MARKETPLACE,
                'severity' => 'error',
                'process_code' => self::EXPORT_PROCESS_CODE,
            ];
            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($params['target_shop_id'] ?? null, $notificationData);
            return true; //deleting message from the queue
        }

        $filePath = $params['file_path'] ?? null;
        if (empty($filePath)) {
            $response = $this->createFileAndStartExport($params);
            if (!$response['success']) {
                $this->abortQueuedTask($params, $response['message']);
                return true; //deleting message from the queue
            }
            $queueData['data']['file_path'] = $response['file_path'] ?? null;
            $queueData['data']['last_traversed_product_id'] = null;
            $this->pushMessageToQueue($queueData);
            $this->updateQueuedTask($queuedTaskId, 1, 'Please wait while we export your products for attribute deletion. It might take a little while.');
            return true;
        }
        $processNextChunkResponse = $this->processNextProductExportChunk($params);
        if (!$processNextChunkResponse['success']) {
            $this->abortQueuedTask($params, $processNextChunkResponse['message']);
            return true;
        }

        if ($processNextChunkResponse['export_completed'] === false) {
            $queueData['data']['last_traversed_product_id'] = $processNextChunkResponse['last_traversed_product_id'] ?? null;
            $queueData['data']['cursor'] = ($params['data']['cursor'] ?? 1) + 1; // Increment cursor safely
            $this->pushMessageToQueue($queueData);
            $processedCount = self::XLSX_EXPORT_CHUNK_SIZE * $queueData['data']['cursor'];
            $processedCountPercentage = ($processedCount / $params['total_count']) * 100;
            if ($processedCountPercentage >= 95) {
                $processedCountPercentage = 95;
            }
            $this->updateQueuedTask($queuedTaskId, $processedCountPercentage, addAndUpdate: false);
            return true;
        } else {
            $this->updateQueuedTask($queuedTaskId, 96, addAndUpdate: false);
            $queueData['method'] = 'writeAttributesToDeleteExcelFile';
            $this->pushMessageToQueue($queueData);
            return true;
        }
    }

    public function createFileAndStartExport(array $params)
    {
        $userId = $params['user_id'];
        $targetShopId = $params['target_shop_id'];
        $templateId = $params['template_id'];
        $templateName = $params['template_name'] ?? "";

        $fileName = self::FILE_NAME_PREFIX . "_{$templateName}"; // Include template name in filename

        $filePath = self::FILE_BASE_PATH  . DS . $userId . DS . $templateId . DS .'exports' . DS . $fileName . '.xlsx';

        if (!file_exists($filePath)) {
            $dirname = dirname($filePath);
            if (!is_dir($dirname)) {
                mkdir($dirname, 0777, true);
            }
        } else {
            unlink($filePath);
        }

        $defaultHeaders = [
            'title' => 'Title',
            'container_id' => 'Container ID',
            'source_product_id' => 'Source Product ID',
        ];
        $initialWriteResult = $this->createInitialExcelFile($defaultHeaders, $filePath);

        if ($initialWriteResult) {
            return [
                'success' => true,
                'message' => 'Excel file created and default headers set.',
                'file_path' => $filePath
            ];
        }
        return ['success' => false, 'message' => 'We encountered an error while creating the Excel file.'];
    }

    public function createInitialExcelFile(array $defaultHeaders, string $filename): bool
    {
        try {
            $spreadsheet = new Spreadsheet();
            $activeWorksheet = $spreadsheet->getActiveSheet();

            // 1. Set up Headers and make them uneditable and bold
            $columnIndex = 1;
            foreach ($defaultHeaders as $header) {
                $cell = $activeWorksheet->getCellByColumnAndRow($columnIndex, 1);
                $cell->setValue($header);

                $style = $activeWorksheet->getStyle($cell->getCoordinate());
                $style->getProtection()->setLocked(true);
                $style->getFont()->setBold(true);
                $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $columnIndex++;
            }

            // 3. Apply worksheet protection, without locking all cells by default
            $activeWorksheet->getProtection()->setSheet(true, null, false);

            // 4. Auto-size columns
            foreach (range('A', $activeWorksheet->getHighestColumn()) as $column) {
                $activeWorksheet->getColumnDimension($column)->setAutoSize(true);
            }
            $activeWorksheet->freezePane('B2'); // Freeze pane for better UX

            $writer = new Xlsx($spreadsheet);
            $writer->save($filename);

            return true;
        } catch (\Exception $e) {
            error_log("Error creating Excel file: " . $e->getMessage());
            return false;
        }
    }

    private function processNextProductExportChunk($params)
    {
        $templateId = ($params['template_id'] instanceof ObjectID)
            ? $params['template_id']
            : new ObjectId($params['template_id']);

        $findFilter = [
            'user_id' => $params['user_id'],
            'source_shop_id' => $params['source_shop_id'],
            'target_shop_id' => $params['target_shop_id'],
            'profile.profile_id' => $templateId,
        ];
        if (!empty($params['last_traversed_product_id'])) {
            $findFilter['_id'] = [
                '$gt' => new ObjectId($params['last_traversed_product_id'])
            ];
        }

        $refineProductContainer = $this->getBaseMongoCollection('refine_product');
        $products = $refineProductContainer->find(
            $findFilter,
            [
                'projection' => [
                    'container_id' => 1,
                    'title' => 1,
                    'type' => 1,
                    'items.source_product_id' => 1,
                    'items.variant_title' => 1, // Include variant_title for variation products
                ],
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'limit' => self::XLSX_EXPORT_CHUNK_SIZE,
                'sort' => ['_id' => 1],
            ]
        )->toArray();

        if (!empty($products)) {
            $appendResult = $this->appendMandatoryProductRows($params['file_path'], $products);
            if ($appendResult) {
                $lastProductId = (string)end($products)['_id'];
                return ['success' => true, 'export_completed' => false, 'last_traversed_product_id' => $lastProductId];
            } else {
                return ['success' => false, 'message' => 'Failed to append data to Excel file.'];
            }
        } else {
            return ['success' => true, 'export_completed' => true];
        }
    }

    public function appendMandatoryProductRows(string $filePath, array $productsChunk): bool
    {
        try {
            // 1. Load the existing spreadsheet
            $spreadsheet = IOFactory::load($filePath);
            $activeWorksheet = $spreadsheet->getActiveSheet();

            // 2. Determine the next empty row
            $highestRow = $activeWorksheet->getHighestRow();
            $startRow = $highestRow + 1; // Start appending from the next row

            // 3. Populate data rows (container_id, source_product_id and title)
            $rowIndex = $startRow;
            foreach ($productsChunk as $product) {
                $containerId = $product['container_id'] ?? '';
                $mainTitle = $product['title'] ?? '';
                $type = $product['type'] ?? '';
                $items = $product['items'] ?? [];

                // Loop through each item to create a new row in the Excel file
                foreach ($items as $item) {
                    $sourceProductId = $item['source_product_id'] ?? '';
                    $title = $mainTitle;

                    if ($type == "variation" && $containerId != $sourceProductId) {
                        $title .= ' ' . ($item['variant_title'] ?? '');
                    }

                    // Set title in the first column (Column A) and lock
                    $cellA = $activeWorksheet->getCellByColumnAndRow(1, $rowIndex);
                    $cellA->setValue($title);
                    $styleA = $activeWorksheet->getStyle($cellA->getCoordinate());
                    $styleA->getProtection()->setLocked(true);
                    $styleA->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                    // Set container_id in the second column (Column B) and lock
                    $cellB = $activeWorksheet->getCellByColumnAndRow(2, $rowIndex);
                    $cellB->setValue($containerId);
                    $styleB = $activeWorksheet->getStyle($cellB->getCoordinate());
                    $styleB->getProtection()->setLocked(true);
                    $styleB->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);


                    // Set source_product_id in the third column (Column C) and lock
                    $cellC = $activeWorksheet->getCellByColumnAndRow(3, $rowIndex);
                    $cellC->setValue($sourceProductId);
                    $styleC = $activeWorksheet->getStyle($cellC->getCoordinate());
                    $styleC->getProtection()->setLocked(true);
                    $styleC->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);


                    $rowIndex++; // Move to the next row for the next item
                }
            }

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($filePath);

            return true;
        } catch (\Exception $e) {
            error_log("Error appending data to Excel file: " . $e->getMessage());
            return false;
        }
    }

    public function writeAttributesToDeleteExcelFile($params)
    {
        $attributesSelected = $params['data']['attributes_selected'] ?? [];
        $filePath = $params['data']['file_path'];

        try {
            // 1. Load the existing spreadsheet
            $spreadsheet = IOFactory::load($filePath);
            $activeWorksheet = $spreadsheet->getActiveSheet();
            $headerRow = 1;

            // 2. Create the "AttributePaths" hidden worksheet (for consistency, though not strictly needed for delete)
            $attributePathsSheet = new Worksheet($spreadsheet, 'AttributePaths');
            $spreadsheet->addSheet($attributePathsSheet);
            $attributePathsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

            if ($attributesSelected[0] == 'all') {
                // Handle 'all' attributes - Fetch all selectable attributes from schema here
                $jsonCollection = $this->di->getObjectManager()
                    ->get(\App\Core\Models\BaseMongo::class)
                    ->getCollectionForTable('product_type_definitions_schema');
                $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];
                $jsonAttributes = $jsonCollection->findOne(
                    [
                        'user_id' => $params['data']['user_id'],
                        'shop_id' => $params['data']['target_shop_id'],
                        'product_type' => $params['data']['product_type'],
                    ],
                    $options
                );
                $schema = $jsonAttributes['schema'] ?? "";
                $amazonSchema = json_decode($schema, true);
                $allAttributes = $this->getAllSelectableAttributes($amazonSchema);
                $attributesSelected = $allAttributes; // Override selected attributes with all
            }

            // 3. Determine starting column for attributes (after default headers)
            $highestColumnString = $activeWorksheet->getHighestColumn();
            $attributeStartColumnIndex = Coordinate::columnIndexFromString($highestColumnString) + 1;
            $currentAttributeColumnIndex = $attributeStartColumnIndex;

            // 4. Add headers and data validation for selected attributes
            foreach ($attributesSelected as $attributePath) {
                $attributePathArray = explode('.', $attributePath);
                $headerLabel = $this->buildHeaderLabel($attributePathArray);

                $attributeColumnLetter = Coordinate::stringFromColumnIndex($currentAttributeColumnIndex);
                $headerCell = $activeWorksheet->getCell($attributeColumnLetter . $headerRow);
                $headerCell->setValue($headerLabel);
                // Hidden sheet for attribute paths (for consistency)
                $attributePathsSheet->setCellValue($attributeColumnLetter . $headerRow, $attributePath);

                $style = $activeWorksheet->getStyle($headerCell->getCoordinate());
                $style->getProtection()->setLocked(true); // Lock header cells
                $style->getFont()->setBold(true); // Set bold font
                $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Data Validation for "Delete Attribute"
                $validation = new DataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setAllowBlank(true); // Allow blank for keeping existing value
                $validation->setShowInputMessage(true);
                $validation->setPromptTitle('Select Action');
                $validation->setShowDropDown(true);
                $validation->setFormula1('"Delete"'); // Only "Delete" option in dropdown. Empty string means keep/no action.
                $validation->setPrompt("Choose 'Delete' to delete this attribute value, or leave blank to keep.");
                $validation->setError("Invalid value selected. Please choose from the dropdown list (Delete or leave blank).");
                $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);

                $highestRowMainSheet = $activeWorksheet->getHighestRow();
                $attributeColumnLetterExcel = Coordinate::stringFromColumnIndex($currentAttributeColumnIndex);
                for ($row = 2; $row <= $highestRowMainSheet; $row++) {
                    $cell = $activeWorksheet->getCell($attributeColumnLetterExcel . $row);
                    $cell->setDataValidation($validation);
                    $styleAttributeColumn = $activeWorksheet->getStyle($cell->getCoordinate());
                    $styleAttributeColumn->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED); // Unlock for user input
                    $styleAttributeColumn->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
                $currentAttributeColumnIndex++;
            }

            // 5. Auto-size columns (including new attribute columns)
            $highestColumn = $activeWorksheet->getHighestColumn();
            if (strlen($highestColumn) === 1) {
                foreach (range('A', $highestColumn) as $column) {
                    $activeWorksheet->getColumnDimension($column)->setAutoSize(true);
                }
            } else {
                $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $columnLetter = Coordinate::stringFromColumnIndex($col);
                    $activeWorksheet->getColumnDimension($columnLetter)->setAutoSize(true);
                }
            }

            // 6. Apply worksheet protection (important! - after adding all validations and unlocking cells)
            $activeWorksheet->getProtection()->setSheet(true, null, false);
            $activeWorksheet->freezePane('B2'); // Freeze pane for better UX

            // 7. Save the spreadsheet
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($filePath);
        } catch (\Exception $e) {
            error_log("Error adding attribute headers and data validation to Excel file: " . $e->getMessage());
            return false; // Failure
        }

        $downloadUrl = $this->getDownloadUrlS3($params['data']['file_path']);

        $completionMsg = 'Product Export for Attribute Deletion completed successfully.';
        if (!empty($params['data']['template_name'])) {
            $completionMsg = "Product Export for Attribute Deletion for the template '{$params['data']['template_name']}' has been completed successfully.";
        }

        $notificationParams = [
            'user_id' => $params['data']['user_id'],
            'message' => $completionMsg,
            'severity' => 'success',
            'url' => $downloadUrl,
            'shop_id' => $params['data']['target_shop_id']
        ];
        $this->addNotification($notificationParams);

        $this->updateQueuedTask($params['data']['queued_task_id'], 100, addAndUpdate: false);
        return true;
    }

    private function getDownloadUrlS3($filePath)
    {
        $bucketName = self::S3_BUCKET_NAME;

        $bucketNamePrefix = $this->di->getConfig()->path('template_bulk_edit_bucket_prefix', false);
        if ($bucketNamePrefix) {
            $bucketName = $bucketNamePrefix . $bucketName;
        }

        $fileName    = 'exports/' . basename($filePath);

        $config = include BP . '/app/etc/aws.php';

        $s3Client = new S3Client($config);

        try {
            $s3Client->putObject([
                'Bucket'      => $bucketName,
                'Key'         => $fileName,
                'SourceFile'  => $filePath,
                'ContentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // XLSX MIME type
            ]);

            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucketName,
                'Key' => $fileName
            ]);
            $urlValidity = self::S3_URL_VALIDITY;
            $request = $s3Client->createPresignedRequest($cmd, $urlValidity);

            // Get the presigned URL
            return (string)$request->getUri();

        } catch (\Exception $e) {
            echo "There was an error uploading the file: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function getAllSelectableAttributes(array $amazonSchema, string $currentPath = '', array $selectableAttributes = []): array
    {
        foreach ($amazonSchema['properties'] as $attributeKey => $attributeDef) {
            $attributeFullPath = empty($currentPath) ? $attributeKey : $currentPath . '.' . $attributeKey;

            if (isset($attributeDef['properties'])) {
                $selectableAttributes = $this->getAllSelectableAttributes($attributeDef, $attributeFullPath, $selectableAttributes);
            } elseif (isset($attributeDef['items']) && isset($attributeDef['items']['properties'])) {
                $selectableAttributes = $this->getAllSelectableAttributes($attributeDef['items'], $attributeFullPath . '.0', $selectableAttributes);
            } else {
                if (
                    !(isset($attributeDef['hidden']) && $attributeDef['hidden'] === true) &&
                    !in_array($attributeKey, ['marketplace_id', 'language_tag'])
                ) {
                    $valuePath = $attributeFullPath;
                    $selectableAttributes[] = $valuePath;
                }
            }
        }
        return $selectableAttributes;
    }

    private function buildHeaderLabel(array $attributePathArray): ?string
    {
        $filteredParts = array_filter($attributePathArray, function ($part) {
            return $part !== '0';
        });

        if (!empty($filteredParts)) {
            $lastPart = end($filteredParts);
            if ($lastPart === 'value') {
                array_pop($filteredParts);
            }
        }

        $headerLabelParts = array_map(function ($part) {
            $part = str_replace('_', ' ', $part);
            return ucwords($part);
        }, $filteredParts);

        $separator = ' → ';
        return implode($separator, $headerLabelParts);
    }

    public function updateQueuedTask($feedId, $progress, $msg = "", $addAndUpdate = true)
    {
        return $this->di->getObjectManager()->get(ConnectorQueuedTasks::class)->updateFeedProgress($feedId, $progress, $msg, $addAndUpdate);
    }

    public function abortQueuedTask($queueData, $message)
    {
        $params = [
            'feed_id' => $queueData['queued_task_id'],
            'message' => $message,
            'shop_id' => $queueData['target_shop_id'],
            'marketplace' => self::MARKETPLACE
        ];
        $this->di->getObjectManager()->get(ConnectorQueuedTasks::class)->abortQueuedTaskProcess($params);
    }

    public function addNotification(array $params)
    {
        $notificationData = [
            'user_id' => $params['user_id'],
            'message' => $params['message'],
            'severity' => $params['severity'],
            'created_at' => date('c'),
            'marketplace' => self::MARKETPLACE,
            'process_code' => self::EXPORT_PROCESS_CODE,
        ];

        if (!empty($params['url'])) {
            $notificationData['url'] = $params['url'];
        }

        $notification = $this->di->getObjectManager()->get('App\Connector\Models\Notifications');
        $notification->addNotification($params['shop_id'], $notificationData);
    }

    public function test($params)
    {
        $jsonCollection = $this->di->getObjectManager()
            ->get(\App\Core\Models\BaseMongo::class)
            ->getCollectionForTable('product_type_definitions_schema');
        $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];

        $jsonAttributes = $jsonCollection->findOne(
            [
                'user_id' => $params['user_id'],
                'shop_id' => $params['target_shop_id'],
                'product_type' => $params['product_type'],
            ],
            $options
        );
        $schema = $jsonAttributes['schema'] ?? "";
        $amazonSchema = json_decode($schema, true);

        $resp = $this->getAllSelectableAttributes($amazonSchema, 'externally_assigned_product_identifier.0.type');
        echo '<pre>';
        print_r($resp);
        echo '<br><br><b>';
        die(__FILE__ . '/line ' . __LINE__);
    }
}