<?php

namespace App\Amazon\Components\Template\BulkAttributesEdit\Export;

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
    const FILE_BASE_PATH = BP . DS . 'var' . DS . 'file' . DS . 'template_bulk_edit';
    const FILE_NAME_PREFIX = "ced_amz";
    const EXPORT_PROCESS_CODE = 'template_bulk_edit_export';
    const EXPORT_QUEUE_NAME = 'template_bulk_edit_export';

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
            return ['success' => false, 'message' => 'Export already in progress. Please check activity section for updates.'];
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
            'message' => "Please wait while we export your products attributes. It might take a little while.",
            'process_code' => self::EXPORT_PROCESS_CODE,
            'marketplace' => 'amazon',
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
            return ['success' => false, 'message' => 'Template Bulk Edit export is already under progress. Please check activity section for updates.'];
        }

        return ['success' => true, 'queued_task_id' => $queuedTaskId];
    }

    private function pushToExportQueue(array $params)
    {
        $preparedQueueData =  [
            'type' => 'full_class',
            'class_name' => \App\Amazon\Components\Template\BulkAttributesEdit\Export\Export::class,
            'method' => 'templateProductsExport',
            'user_id' => $params['user_id'],
            'source_shop_id' => $params['source_shop_id'],
            'target_shop_id' => $params['target_shop_id'],
            'queue_name' => self::EXPORT_QUEUE_NAME,
            'data' => [
                'user_id' => $params['user_id'],
                'queued_task_id' => $params['queued_task_id'],
                'total_count' => $params['total_count'],
                'template_id' => $params['template_id'],
                'template_name' => $params['template_name'],
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
                'marketplace' => "amazon",
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

    public function templateProductsExport(array $queueData)
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
                'message' => 'Product export has been terminated. Please try again later or contact support if the issue persists.',
                'marketplace' => "amazon",
                'severity' => 'error',
                'process_code' => 'template_bulk_export',
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
            $this->updateQueuedTask($queuedTaskId, 1, 'Please wait while we export your products. It might take a little while.');
            return true;
        }

        $processNextChunkResponse = $this->processNextProductExportChunk($params);
        if (!$processNextChunkResponse['success']) {
            $this->abortQueuedTask($params, $processNextChunkResponse['message']);
            return true; //TODO - can disucss some error which can be handled and which cannot
        }

        if ($processNextChunkResponse['export_completed'] === false) {
            $queueData['data']['last_traversed_product_id'] = $processNextChunkResponse['last_traversed_product_id'] ?? null;
            $queueData['params']['cursor'] = $params['cursor'] ? $params['cursor']++ : 1;
            $this->pushMessageToQueue($queueData);
            $processedCount = $params['limit'] * $params['cursor'];
            $processedCountPercentage = ($processedCount / $params['total_count']) * 100;
            if ($processedCountPercentage >= 95) {
                $processedCountPercentage = 95;
            }
            $this->updateQueuedTask($queuedTaskId, $processedCountPercentage, addAndUpdate: false);
            return true;
        } else {
            $this->updateQueuedTask($queuedTaskId, 96, addAndUpdate: false);
            $queueData['method'] = 'writeAttributesToExcelFile';
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

        $fileName = self::FILE_NAME_PREFIX . "_{$templateName}";

        // $filePath = BP . DS . 'var' . DS . 'file' . DS . 'template_bulk_edit' . DS . $userId . DS . $templateId . DS .'exports' . DS . $targetShopId . '.xlsx';

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
            'type' => 'Type',
            //TODO - need to add SKU
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
                    'items.variant_title' => 1,
                ],
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'limit' => $params['limit'],
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

                $items = $product['items'] ?? []; // Array of items, each with a source_product_id anf title

                // Loop through each item to create a new row in the Excel file
                foreach ($items as $item) {
                    $sourceProductId = $item['source_product_id'] ?? '';
                    $title = $mainTitle;

                    $productRelationType = $type == "variation" ? "Parent" : "Simple";

                    if ($type == "variation" && $containerId != $sourceProductId) {
                        $title .= ' ' . ($item['variant_title'] ?? '');
                        $productRelationType = "Child";
                    }

                    // Set container_id in the first column (Column A) and lock
                    $cellA = $activeWorksheet->getCellByColumnAndRow(2, $rowIndex);
                    $cellA->setValue($containerId);
                    $styleA = $activeWorksheet->getStyle($cellA->getCoordinate());
                    $styleA->getProtection()->setLocked(true);
                    $styleA->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                    // Set source_product_id in the second column (Column B) and lock
                    $cellB = $activeWorksheet->getCellByColumnAndRow(3, $rowIndex);
                    $cellB->setValue($sourceProductId);
                    $styleB = $activeWorksheet->getStyle($cellB->getCoordinate());
                    $styleB->getProtection()->setLocked(true);
                    $styleB->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                    // Set title in the third column (Column C) and lock
                    $cellC = $activeWorksheet->getCellByColumnAndRow(1, $rowIndex);
                    $cellC->setValue($title);
                    $styleC = $activeWorksheet->getStyle($cellC->getCoordinate());
                    $styleC->getProtection()->setLocked(true);
                    $styleC->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                    $cellD = $activeWorksheet->getCellByColumnAndRow(4, $rowIndex);
                    $cellD->setValue($productRelationType);
                    $styleD = $activeWorksheet->getStyle($cellD->getCoordinate());
                    $styleD->getProtection()->setLocked(true);
                    $styleD->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                    $rowIndex++; // Move to the next row for the next item
                }
            }

            // 4. Save the spreadsheet
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($filePath);

            return true;
        } catch (\Exception $e) {
            error_log("Error appending data to Excel file: " . $e->getMessage());
            return false; // Failure
        }
    }

    public function writeAttributesToExcelFile($params)
    {
        $attributesSelected = $params['data']['attributes_selected'] ?? [];
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
        $schema = json_decode($schema, true);
        $this->addAttributesAndDataValidationToExcel($params['data']['file_path'], $attributesSelected, $schema);

        $downloadUrl = $this->getDownloadUrlS3($params['data']['file_path']);

        $completionMsg = 'Product Attributes Export has been completed successfully.';

        if (!empty($params['data']['template_name'])) {
            $completionMsg = "Product Attributes Export for the template '{$params['data']['template_name']}' has been completed successfully.";
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

            return (string)$request->getUri();
        } catch (\Exception $e) {
            // echo "There was an error uploading the file: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function getAllSelectableAttributes(array $amazonSchema, string $currentPath = '', array $selectableAttributes = []): array
    {
        foreach ($amazonSchema['properties'] as $attributeKey => $attributeDef) {
            $attributeFullPath = empty($currentPath) ? $attributeKey : $currentPath . '.' . $attributeKey;

            if (isset($attributeDef['properties'])) { // Dive deeper into nested properties (objects)
                $selectableAttributes = $this->getAllSelectableAttributes($attributeDef, $attributeFullPath, $selectableAttributes);
            } elseif (isset($attributeDef['items']) && isset($attributeDef['items']['properties'])) { // Dive deeper into array items with properties
                $selectableAttributes = $this->getAllSelectableAttributes($attributeDef['items'], $attributeFullPath . '.0', $selectableAttributes); // Assuming array index 0 for simplicity
            } else {
                // Check selectability criteria for leaf nodes (attributes with type, title, etc.)
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

    public function addAttributesAndDataValidationToExcel(string $filePath, array $selectedAttributes, array $amazonSchema): bool
    {
        try {
            // 1. Load the existing spreadsheet
            $spreadsheet = IOFactory::load($filePath);
            $activeWorksheet = $spreadsheet->getActiveSheet();
            $headerRow = 1;

            // 2. Create the "AttributePaths" hidden worksheet
            $attributePathsSheet = new Worksheet($spreadsheet, 'AttributePaths');
            $spreadsheet->addSheet($attributePathsSheet);
            $attributePathsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

            // 3. Create the "ValidationLists" hidden worksheet
            $validationSheet = new Worksheet($spreadsheet, 'ValidationLists');
            $spreadsheet->addSheet($validationSheet);
            $validationSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

            // 4. If all attributes are selected, retrieve them from the schema
            if ($selectedAttributes[0] === 'all') {
                $selectedAttributes = $this->getAllSelectableAttributes($amazonSchema);
            }

            // 5. Determine starting column for new attributes (after default headers)
            $highestColumnString = $activeWorksheet->getHighestColumn();
            $attributeStartColumnIndex = Coordinate::columnIndexFromString($highestColumnString) + 1;
            $currentAttributeColumnIndex = $attributeStartColumnIndex;

            // 6. Iterate through each selected attribute
            foreach ($selectedAttributes as $attributePath) {

                $attributePath = preg_replace('/\.0$/', '', $attributePath);// Remove '.0' from the end of the string if present

                // 6.1. Get attribute definition from schema
                $attributePathArray = explode('.', $attributePath);
                $attributeKey = $attributePathArray[0] ?? null;
                $attributeDef = $amazonSchema['properties'][$attributeKey] ?? null;
                if (!$attributeDef) {
                    error_log("Selected Attribute '$attributeKey' not found in schema.");
                    continue;
                }

                // 6.2. Construct a user-friendly header label
                $headerLabel = $this->buildHeaderLabel($attributePathArray);

                // 6.3. Write header and attribute path in all worksheets using the same column
                $attributeColumnLetter = Coordinate::stringFromColumnIndex($currentAttributeColumnIndex);
                // Main worksheet header
                $activeWorksheet->setCellValue($attributeColumnLetter . $headerRow, $headerLabel);
                // Hidden sheet for attribute paths
                $attributePathsSheet->setCellValue($attributeColumnLetter . $headerRow, $attributePath);
                // Hidden sheet for dropdown lists (headers)
                $validationSheet->setCellValue($attributeColumnLetter . $headerRow, $attributePath);

                // Apply styling to the header (locking, bold, centered)
                $headerStyle = $activeWorksheet->getStyle($attributeColumnLetter . $headerRow);
                $headerStyle->getProtection()->setLocked(true);
                $headerStyle->getFont()->setBold(true);
                $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // 7. Data Validation Logic
                $rootAttri = $this->extractDropdownOptionsFromSchema($attributeDef, $attributePathArray);
                $options = $rootAttri['enumNames'] ?? null;
                $optionValues = $rootAttri['enum'] ?? null;

                if (is_null($options) && !empty($rootAttri['anyOf'][1]['enum'])) {
                    //TODO - need to add enum from here as well
                    $options = $rootAttri['anyOf'][1]['enumNames'];
                    $optionValues = $rootAttri['anyOf'][1]['enum'];
                }

                if ($options !== null) {
                    // same column letter for writing the dropdown options
                    $currentValidationColumnLetter = $attributeColumnLetter;
                    // Options start on row 2 (row 1 already has the header)
                    $rowIndex = 2;
                    foreach ($options as $index => $option) {
                        $validationSheet->setCellValue($currentValidationColumnLetter . $rowIndex, trim($option));
                        if (isset($optionValues[$index])) {
                            $attributePathsSheet->setCellValue($currentValidationColumnLetter . $rowIndex, trim($optionValues[$index]));
                        }
                        $rowIndex++;
                    }
                    $optionListRange = 'ValidationLists!$' . $currentValidationColumnLetter . '$2:$' . $currentValidationColumnLetter . '$' . ($rowIndex - 1);

                    // Create and configure the data validation object
                    $validation = new DataValidation();
                    $validation->setType(DataValidation::TYPE_LIST);
                    $validation->setAllowBlank(true);
                    $validation->setShowInputMessage(true);
                    $validation->setPromptTitle('Pick from list only');
                    $validation->setShowDropDown(true);
                    $validation->setFormula1($optionListRange);
                    $validation->setPrompt("Please select a value from Amazon Recommendations");
                    $validation->setError("Invalid value selected. Please choose from the dropdown list.");
                    $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);

                    // Apply the validation to all cells in this new attribute column (from row 2 downward)
                    $highestRowMainSheet = $activeWorksheet->getHighestRow();
                    for ($row = 2; $row <= $highestRowMainSheet; $row++) {
                        $cell = $activeWorksheet->getCell($attributeColumnLetter . $row);
                        // Clone the validation object to ensure each cell gets its own copy
                        $cell->setDataValidation(clone $validation);

                        // Unlock the cell for user input and center align
                        $cellStyle = $activeWorksheet->getStyle($cell->getCoordinate());
                        $cellStyle->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
                        $cellStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }
                } else {
                    // Non-dropdown fields – enforce data types if specified (e.g. string or number)
                    $enforceDataType = false;
                    $dataType = null;
                    if (isset($rootAttri['type']) && in_array($rootAttri['type'], ['string', 'number'])) {
                        $enforceDataType = true;
                        $dataType = $rootAttri['type'] === 'number' ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING2;
                    }
                    $highestRowMainSheet = $activeWorksheet->getHighestRow();
                    for ($row = 2; $row <= $highestRowMainSheet; $row++) {
                        $cell = $activeWorksheet->getCell($attributeColumnLetter . $row);
                        if ($enforceDataType && $dataType !== null) {
                            //this might be causing the value in sheet (Purchasable Offer → Discounted Price → Schedule → Value With Tax having value zero in all cells)
                            $cell->setValueExplicit($cell->getValue(), $dataType);
                        }
                        $cellStyle = $activeWorksheet->getStyle($cell->getCoordinate());
                        $cellStyle->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
                        $cellStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }
                }

                // Move to the next column for the following attribute
                $currentAttributeColumnIndex++;
            }

            // 8. Protect the main worksheet (after adding all validations and unlocking specific cells)
            $activeWorksheet->getProtection()->setSheet(true, null, false);

            $activeWorksheet->freezePane('B2');

            // 9. Auto-size all columns
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

            // 10. Save the updated spreadsheet (overwriting the existing file)
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($filePath);

            return true;

        } catch (\Exception $e) {
            error_log("Error adding attributes and data validation to Excel file: " . $e->getMessage());
            return false;
        }
    }

    private function buildHeaderLabel(array $attributePathArray): ?string
    {
        // Remove any parts that are '0'.
        $filteredParts = array_filter($attributePathArray, function ($part) {
            return $part !== '0';
        });

        // If the last element is 'value', remove it.
        if (!empty($filteredParts)) {
            $lastPart = end($filteredParts);
            if ($lastPart === 'value') {
                array_pop($filteredParts);
            }
        }

        // Convert each part to title case.
        $headerLabelParts = array_map(function ($part) {
            $part = str_replace('_', ' ', $part);
            return ucwords($part);
        }, $filteredParts);

        $separator = ' → ';

        // Construct the header label.
        return implode($separator, $headerLabelParts);
    }


    public function extractDropdownOptionsFromSchema(array $schemaPart, array $attributePathArray): ?array
    {
        // If an attribute path is provided, use it exclusively to locate the node.
        if (!empty($attributePathArray)) {
            $resolvedNode = $this->resolveSchemaPath($schemaPart, $attributePathArray);
            return $this->extractOptionsFromNode($resolvedNode);
        }

        // Fallback: if no attribute path is provided, perform a recursive search.
        return $this->recursiveSearchForOptions($schemaPart);
    }

    /**
     * Given a schema node, return its dropdown options if explicitly defined.
     * Only "enumNames" or "enum" are considered valid dropdown options.
     */
    private function extractOptionsFromNode($node): ?array
    {
        if (!is_array($node)) {
            return null;
        }
        if (isset($node['enumNames'], $node['enum'])) {
            return $node;
        }

        return null;
    }

    /**
     * Traverse the schema following the provided attribute path.
     *
     * This function is aware of common JSON Schema patterns where object properties
     * are defined under "properties" and arrays are defined via "items".
     *
     * Additionally, if the schema already represents a single attribute (for example,
     * its "title" is "Country of Origin") and the first segment of the attribute path
     * (e.g. "country_of_origin") matches the normalized title, then that segment is skipped.
     */
    private function resolveSchemaPath(array $schema, array $path): ?array
    {
        array_shift($path);

        $current = $schema;
        foreach ($path as $segment) {
            // If the segment is numeric (as a string) and we have an "items" key, dive into it.
            if (is_numeric($segment) && isset($current['items'])) {
                $current = $current['items'];
                continue;
            }

            // Check if the current node has a "properties" block with this segment.
            if (isset($current['properties']) && array_key_exists($segment, $current['properties'])) {
                $current = $current['properties'][$segment];
                continue;
            }

            // Otherwise, check if the segment exists directly.
            if (isset($current[$segment])) {
                $current = $current[$segment];
                continue;
            }

            // As a fallback, if there is an "items" key, try to see if its "properties" contain the segment.
            if (isset($current['items']) && is_array($current['items'])) {
                $temp = $current['items'];
                if (isset($temp['properties']) && array_key_exists($segment, $temp['properties'])) {
                    $current = $temp['properties'][$segment];
                    continue;
                } elseif ($temp['anyOf'][1]['enum']) {
                    $current = $temp['anyOf'][1];
                    continue;
                }
            }

            // If none of the above worked, we cannot resolve this segment.
            return null;
        }
        return $current;
    }

    /**
     * Normalize a title string to a comparable attribute key.
     * For example, "Country of Origin" becomes "country_of_origin".
     */
    private function normalizeTitle(string $title): string
    {
        $normalized = strtolower(trim($title));
        $normalized = str_replace(' ', '_', $normalized);
        return $normalized;
    }

    /**
     * Recursively search the given schema for dropdown options.
     * (Only used if no attribute path is provided.)
     */
    private function recursiveSearchForOptions(array $schema): ?array
    {
        if ($options = $this->extractOptionsFromNode($schema)) {
            return $options;
        }

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                if ($options = $this->recursiveSearchForOptions($value)) {
                    return $options;
                }
            }
        }
        return null;
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
            'marketplace' => 'amazon'
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
            'marketplace' => 'amazon',
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
        $country_of_origin = $amazonSchema['properties']['country_of_origin'];
        $amazonSchema['properties'] = [];
        $amazonSchema['properties']['country_of_origin'] = $country_of_origin;

        $resp = $this->getAllSelectableAttributes($amazonSchema, 'externally_assigned_product_identifier.0.type');
        echo '<pre>';
        print_r($resp);
        echo '<br><br><b>';
        die(__FILE__ . '/line ' . __LINE__);
    }
}
