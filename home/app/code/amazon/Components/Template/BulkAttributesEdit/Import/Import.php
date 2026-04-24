<?php

namespace App\Amazon\Components\Template\BulkAttributesEdit\Import;

use MongoDB\BSON\ObjectId;
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
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use App\Amazon\Components\Template\BulkAttributesEdit\ChunkReadFilter;
use App\Connector\Models\QueuedTasks as ConnectorQueuedTasks;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpOfficeDate;


class Import
{
    const IMPORT_CHUNK_SIZE = 50;
    const IMPORT_PROCESS_CODE = 'template_bulk_edit_import';
    const IMPORT_QUEUE_NAME = 'template_bulk_edit_import';
    private array $amazonProductTypeSchema = [];

    /**
     * Entry point for initiating the Excel import process.
     *
     * @param array $params
     * @return array
     */
    public function import(array $params): array
    {
        if (!isset(
            $params['user_id'],
            $params['source_shop_id'],
            $params['target_shop_id'],
            $params['file_path'],
            $params['exported_file_path'],
            $params['template_id'],
            $params['product_type']
        )) {
            return [
                'success' => false,
                'message' => 'Required parameters missing for import.'
            ];
        }

        $exportedFilePath = $params['exported_file_path'];

        $importFilePath = $params['file_path'];

        // Excel Structure and Header Validation
        $excelValidationResult = $this->validateExcelStructure($importFilePath, $exportedFilePath);

        if (!$excelValidationResult['success']) {
            // Strip internal server file path from the error message to avoid exposing sensitive system details.          $excelValidationResult['message'] = isset($excelValidationResult['message']) ? explode('/var/www/html/', $excelValidationResult['message'])[0] : 'Something went wrong';
            return $excelValidationResult;
        }

        $totalProductRows = $this->getRowCountBySheetName($importFilePath, 'Worksheet') - 1;

        $params['import_file_path'] = $importFilePath;
        $params['total_products_rows'] = $totalProductRows;

        $response = $this->commenceTemplateProductsImport($params);
        if ($response['success']) {
            return ['success' => true, 'message' => 'Import process initiated. Check Overview activity section for updates.'];
        }

        return $response;
    }

    /**
     * Retrieves the total row count from a specific sheet within an Excel file.
     *
     * @param string $filePath  The path to the Excel file.
     * @param string $sheetName The name of the sheet to search for.
     * @return int              The number of rows in the specified sheet, or 0 if not found.
     */

    private function getRowCountBySheetName(string $filePath, string $sheetName): int
    {
        $reader = new XlsxReader();
        // Retrieve metadata for all sheets without loading the entire file.
        $sheetsInfo = $reader->listWorksheetInfo($filePath);

        foreach ($sheetsInfo as $sheetInfo) {
            // Compare sheet names (case-insensitive)
            if (strcasecmp($sheetInfo['worksheetName'], $sheetName) === 0) {
                return (int)$sheetInfo['totalRows'];
            }
        }

        // Return 0 if the sheet with the given name is not found.
        return 0;
    }


    public function commenceTemplateProductsImport(array $params): array
    {
        $params['start_row'] = 2;
        $params['processed_rows_count'] = 0;
        $params['total_updated_count'] = 0;
        $params['total_invalid_id_count'] = 0;
        $params['total_failed_updates'] = [];

        $queuedTaskData = [
            'user_id' => $params['user_id'],
            'message' => "Please wait while we import your products attributes. It might take a little while.",
            'process_code' => self::IMPORT_PROCESS_CODE,
            'marketplace' => 'amazon',
        ];

        $addToQueuedTasksResponse = $this->addToQueuedTasks($queuedTaskData, $params['target_shop_id']);
        if (!$addToQueuedTasksResponse['success']) {
            return $addToQueuedTasksResponse;
        }

        $params['queued_task_id'] = $addToQueuedTasksResponse['queued_task_id'];
        unset($params['_url']);

        return $this->pushToImportQueue($params);

        // return $this->templateProductsImport($params);
    }

    private function addToQueuedTasks(array $queueData, string $shopId)
    {
        $queuedTask = $this->di->getObjectManager()->get(ConnectorQueuedTasks::class);
        $queuedTaskId = $queuedTask->setQueuedTask($shopId, $queueData);
        if (!$queuedTaskId) {
            return ['success' => false, 'message' => 'Bulk attribute import is in progress. Check Activity for updates.'];
        }

        return ['success' => true, 'queued_task_id' => $queuedTaskId];
    }

    private function pushToImportQueue(array $params)
    {
        $preparedQueueData =  [
            'type' => 'full_class',
            'class_name' => \App\Amazon\Components\Template\BulkAttributesEdit\Import\Import::class,
            'method' => 'templateProductsImport',
            'user_id' => $params['user_id'],
            'source_shop_id' => $params['source_shop_id'],
            'target_shop_id' => $params['target_shop_id'],
            'queue_name' => self::IMPORT_QUEUE_NAME,
            'data' => [
                ...$params,
                'cursor' => 1,
                'limit' => self::IMPORT_CHUNK_SIZE
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


    public function templateProductsImport(array $queueData): array|bool
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
                'message' => 'Product attribute import process has been terminated. Please try again later or contact support team if the issue persists.',
                'marketplace' => "amazon",
                'severity' => 'error',
                'process_code' => self::IMPORT_PROCESS_CODE,
            ];
            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($params['target_shop_id'] ?? null, $notificationData);
            return true; //deleting message from the queue
        }

        $importFilePath = $params['import_file_path'] ?? null;
        $exportedFilePath = $params['exported_file_path'] ?? null;
        $sourceShopId = $params['source_shop_id'] ?? null;
        $targetShopId = $params['target_shop_id'] ?? null;
        $userId = $params['user_id'] ?? null;
        $startRow = $params['start_row'] ?? 2;
        $processedRowsCount = $params['processed_rows_count'] ?? 0;
        $totalUpdatedCount = $params['total_updated_count'] ?? 0;
        $totalInvalidIdCount = $params['total_invalid_id_count'] ?? 0;
        $totalFailedUpdates = $params['total_failed_updates'] ?? 0;
        $totalProductRows = $params['total_products_rows'] ?? 0;

        if ($processedRowsCount >= $totalProductRows) {
            $this->updateQueuedTask($queuedTaskId, 100, addAndUpdate: false);

            $completionMsg = 'Product Attributes Importing completed';
            if (!empty($params['template_name'])) {
                $completionMsg = "Product Attributes Importing for the template '{$params['template_name']}' completed";
            }

            $messageParts = [
                $completionMsg,
                "Total products processed: {$totalProductRows}"
            ];

            $messageParts[] = "Updated: {$totalUpdatedCount}";

            if ($totalInvalidIdCount) {
                $messageParts[] = "Invalid IDs: {$totalInvalidIdCount}";
            }
            if ($totalFailedUpdates) {
                $messageParts[] = "Failed updates: {$totalFailedUpdates}";
            }

            $message = implode('. ', $messageParts) . '.';

            $this->addNotification([
                'user_id' => $userId,
                'shop_id' => $targetShopId,
                'message' => $message,
                'severity' => 'success'
            ]);

            $this->setBulkEditImportedFlag($userId, $sourceShopId, $targetShopId);

            return true;
        }

        $this->amazonProductTypeSchema = $this->getAmazonSchema($params);


        if (empty($importFilePath) || empty($exportedFilePath) || empty($sourceShopId) || empty($userId)) {
            $errorMessage = 'Missing required parameters for import processing (file paths, shop ID, user ID).';
            $this->logError("Import Failed: " . $errorMessage);
            $this->abortQueuedTask($params, 'Product Attributes import failed due to missing information. Contact support.');
            return true; //deleting msg
        }

        // 1. Data Extraction and Parsing
        $importDataResult = $this->extractImportDataChunk($importFilePath, $startRow, self::IMPORT_CHUNK_SIZE);

        if (!$importDataResult['success']) {
            $this->logError("Import Data Extraction Failed: " . $importDataResult['message']);
            $this->addNotification([
                'user_id' => $userId,
                'shop_id' => $targetShopId,
                'message' => 'Product Attributes import encountered error during reading some data from Excel file',
                'severity' => 'warning'
            ]);
            $queueData['data']['start_row'] = $startRow + self::IMPORT_CHUNK_SIZE;
            $queueData['data']['processed_rows_count'] = $processedRowsCount + self::IMPORT_CHUNK_SIZE;
            $this->pushMessageToQueue($queueData);
            return true;;
        }

        $importDataChunk = $importDataResult['data'];

        if (empty($importDataChunk)) {
            //no attributes mapped in this chunk, moving to next
            $queueData['data']['start_row'] = $startRow + self::IMPORT_CHUNK_SIZE;
            $queueData['data']['processed_rows_count'] = $processedRowsCount + self::IMPORT_CHUNK_SIZE;

            $processedRows = $queueData['data']['processed_rows_count'];
            $progressPercent = ($processedRows / $totalProductRows) * 100;
            $roundedProgress = round($progressPercent, 3);

            if ($roundedProgress >= 99.5) {
                $roundedProgress = 99;
            }

            $this->updateQueuedTask($queuedTaskId, $roundedProgress, addAndUpdate: false);

            $this->pushMessageToQueue($queueData);
            return true;
        }

        // 2. Data Validation (Import-Side)
        $validationResult = $this->validateImportData($importDataChunk, $exportedFilePath);

        if (!$validationResult['success']) {
            $this->logError("Import Data Validation Failed: " . $validationResult['message'] . '. Errors: ' . json_encode($validationResult['errors'] ?? []));
            $this->addNotification([
                'user_id' => $userId,
                'shop_id' => $targetShopId,
                'message' => 'Product Attributes import encountered error during validation of attributes.',
                'severity' => 'warning'
            ]);
            $queueData['data']['total_invalid_id_count'] = $totalInvalidIdCount + $validationResult['invalid_id_count'] ?? 0;
            $queueData['data']['start_row'] = $startRow + self::IMPORT_CHUNK_SIZE;
            $queueData['data']['processed_rows_count'] = $processedRowsCount + self::IMPORT_CHUNK_SIZE;
            $this->pushMessageToQueue($queueData);
            return true;
        }

        $invalidIdCount = $validationResult['invalid_id_count'] ?? 0;

        // 3. Data Update in Database
        $databaseUpdateResult = $this->updateDatabaseFromImportData($validationResult['valid_products'], $params);

        if (!$databaseUpdateResult['success']) {
            $this->logError("Database Update Failed: " . $databaseUpdateResult['message'] . '. Failed updates: ' . json_encode($databaseUpdateResult['failed_updates'] ?? []));

            $queueData['data']['retry'] = ($queueData['data']['retry'] ?? 0) + 1;
            if ($queueData['data']['retry'] > 5) {
                $queueData['data']['start_row'] = $startRow + self::IMPORT_CHUNK_SIZE;
                $queueData['data']['processed_rows_count'] = $processedRowsCount + self::IMPORT_CHUNK_SIZE;
                $this->addNotification([
                    'user_id' => $userId,
                    'shop_id' => $targetShopId,
                    'message' => 'Product Attributes import partially failed during database update. Please contact support',
                    'severity' => 'warning'
                ]);
            }
            $this->pushMessageToQueue($queueData);
            return true;
        }

        if (!empty($queueData['data']['retry'])) {
            unset($queueData['data']['retry']);
        }

        $queueData['data']['total_invalid_id_count'] = $totalInvalidIdCount + $invalidIdCount;
        $updatedCount = $databaseUpdateResult['updated_count'];
        $failedUpdates = $databaseUpdateResult['failed_updates'];

        $queueData['data']['total_updated_count'] = $totalUpdatedCount + $updatedCount;
        $queueData['data']['total_failed_updates'] = $totalFailedUpdates + $failedUpdates;
        $queueData['data']['processed_rows_count'] = $processedRowsCount + self::IMPORT_CHUNK_SIZE;
        $queueData['data']['start_row'] = $startRow + self::IMPORT_CHUNK_SIZE;

        $processedRows = $queueData['data']['processed_rows_count'];
        $progressPercent = ($processedRows / $totalProductRows) * 100;
        $roundedProgress = round($progressPercent, 3);

        if ($roundedProgress >= 99.5) {
            $roundedProgress = 99;
        }

        $this->updateQueuedTask($queuedTaskId, $roundedProgress, addAndUpdate: false);

        $this->pushMessageToQueue($queueData);
        return true;


        // 4. Completion Notification
        $message = "Product import process completed. {$updatedCount} products updated successfully.";
        if ($invalidIdCount > 0) {
            $message .= " {$invalidIdCount} product IDs were not found in the exported file and were skipped.";
        }
    }


    private function validateExcelStructure(string $importFilePath, string $exportedFilePath): array
    {
        try {
            $headers = $this->getSheetHeaders($importFilePath, 'Worksheet');
            $attributeHeaders = $this->getSheetHeaders($exportedFilePath, 'Worksheet');

            if (count($headers) !== count($attributeHeaders)) {
                return [
                    'success' => false,
                    'message' => 'Invalid file: Found ' . count($headers) . ' headers, but expected ' . count($attributeHeaders) . '.'
                ];
            }

            foreach ($attributeHeaders as $index => $expectedHeader) {
                if (!isset($headers[$index]) || $headers[$index] !== $expectedHeader) {
                    return [
                        'success' => false,
                        'message' => 'Invalid structure. Expected header "' . $expectedHeader .
                            '" in column ' . ($index + 1) . ' but found "' . ($headers[$index] ?? 'none') . '".'
                    ];
                }
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error validating Excel file structure: ' . $e->getMessage()
            ];
        }

        return [
            'success' => true,
            'message' => 'Excel file structure validated successfully.'
        ];
    }

    /**
     * Loads the specified sheet from the given file and returns an array of headers.
     *
     * @param string $filePath  The path to the Excel file.
     * @param string $sheetName The name of the sheet to load.
     * @return array            The header values from the first row.
     * @throws \Exception       If the sheet or header row is not found.
     */
    private function getSheetHeaders(string $filePath, string $sheetName): array
    {
        // Create a new reader and chunk filter for this file.
        $reader = new XlsxReader();
        $chunkFilter = new ChunkReadFilter();
        $reader->setReadFilter($chunkFilter);

        // Load only the specified sheet.
        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadDataOnly(true);

        // Configure the filter to load only the first row (headers).
        $chunkFilter->setRows(1, 1);

        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) {
            throw new \Exception('Sheet "' . $sheetName . '" not found in file ' . $filePath);
        }

        // Retrieve the header row.
        $rowIterator = $sheet->getRowIterator(1, 1);
        $row = $rowIterator->current();
        if (!$row) {
            throw new \Exception('No header row found in sheet "' . $sheetName . '" in file ' . $filePath);
        }

        // Iterate through the header row's cells.
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(true);
        $headers = [];
        foreach ($cellIterator as $cell) {
            $headers[] = trim((string)$cell->getValue());
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();

        return $headers;
    }

    private function extractImportDataChunk(string $filePath, int $startRow, int $chunkSize): array
    {
        try {
            $reader = new XlsxReader();
            $chunkFilter = new ChunkReadFilter();
            $reader->setReadFilter($chunkFilter);
            $reader->setLoadSheetsOnly(['Worksheet', 'AttributePaths']);
            $reader->setReadDataOnly(true);

            $endRow = $startRow + $chunkSize - 1;
            $chunkFilter->setRows($startRow, $endRow);

            $spreadsheet = $reader->load($filePath);
            $mainWorksheet = $spreadsheet->getActiveSheet();
            $attributePathsSheet = $spreadsheet->getSheetByName('AttributePaths');

            $headerRow = $mainWorksheet->getRowIterator()->current();
            $cellIterator = $headerRow->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $headers = [];
            $headerColumnMap = [];
            $columnIndex = 0;
            foreach ($cellIterator as $cell) {
                $headerValue = $cell->getValue() ? trim($cell->getValue()) : $cell->getValue();
                $headers[] = $headerValue;
                $headerColumnMap[$columnIndex] = ['header' => $headerValue, 'attribute_path' => null];
                $columnIndex++;
            }

            $attributePathRow = $attributePathsSheet->getRowIterator()->current();
            $attributePathCellIterator = $attributePathRow->getCellIterator();
            $attributePathCellIterator->setIterateOnlyExistingCells(false);
            $attributePathColumnIndex = 0;
            foreach ($attributePathCellIterator as $cell) {
                $attributePath = $cell->getValue() ? trim($cell->getValue()) : $cell->getValue();
                if (isset($headerColumnMap[$attributePathColumnIndex])) {
                    $headerColumnMap[$attributePathColumnIndex]['attribute_path'] = $attributePath;
                }
                $attributePathColumnIndex++;
            }

            $importDataChunk = [];
            $rowDataIterator = $mainWorksheet->getRowIterator($startRow, $endRow);
            foreach ($rowDataIterator as $row) {
                $cellIteratorForRow = $row->getCellIterator();
                $cellIteratorForRow->setIterateOnlyExistingCells(false);

                $rowData = [];
                $cellIndex = 0;
                foreach ($cellIteratorForRow as $cell) {
                    $cellValue = $cell->getValue() ? trim($cell->getValue()) : $cell->getValue();
                    if (isset($headerColumnMap[$cellIndex])) {
                        $headerName = $headerColumnMap[$cellIndex]['header'];
                        $attributePath = $headerColumnMap[$cellIndex]['attribute_path'];
                        $rowData[$headerName] = [
                            'value' => $cellValue,
                            'attribute_path' => $attributePath
                        ];
                        if (!$cellValue && ($cellValue != '0' || $cellValue != 0)) { // Do not unset if value is explicitly 0
                            unset($rowData[$headerName]);
                        }
                    }
                    $cellIndex++;
                }
                if (!empty($rowData) && isset($rowData['Container ID']['value']) && isset($rowData['Source Product ID']['value']) && count($rowData) > 3) {
                    $importDataChunk[] = $rowData;
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            gc_collect_cycles();

            return ['success' => true, 'data' => $importDataChunk];
        } catch (\Exception $e) {
            if (isset($spreadsheet)) {
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
                gc_collect_cycles();
            }
            return ['success' => false, 'message' => 'Error extracting data chunk: ' . $e->getMessage()];
        }
    }

    private function validateImportData(array $importData, string $exportedFilePath): array
    {
        try {

            $exportedReader = new XlsxReader();
            $exportedReader->setLoadSheetsOnly(['Worksheet', 'ValidationLists', 'AttributePaths']);

            $exportedReader->setReadDataOnly(true);

            $exportedSpreadsheet = $exportedReader->load($exportedFilePath);

            $exportedMainWorksheet = $exportedSpreadsheet->getActiveSheet();

            $exportedValidationListsSheet = $exportedSpreadsheet->getSheetByName('ValidationLists');

            $exportedAttributePathsListsSheet = $exportedSpreadsheet->getSheetByName('AttributePaths');

            $validationErrors = []; // To collect all validation errors for invalid products
            $validProducts = []; // To collect products that pass validation 
            $invalidIdCount = 0;
            $rowIndex = 2;

            foreach ($importData as $rowData) {
                $isValidProduct = true; // Flag to track if the current product is valid

                $containerId = $rowData['Container ID']['value'] ?? '';
                $sourceProductId = $rowData['Source Product ID']['value'] ?? '';

                $idCheckResult = $this->checkIDsInExportedSheet($containerId, $sourceProductId, $exportedMainWorksheet);

                if (!$idCheckResult) {
                    $validationErrors[] = [
                        'row' => $rowIndex,
                        'column' => 'Container ID & Source Product ID',
                        'value' => "Container ID: {$containerId}, Source Product ID: {$sourceProductId}",
                        'message' => 'This Container ID and Source Product ID combination was not found in the originally exported file. Only exported products can be updated.'
                    ];
                    $invalidIdCount++;
                    $rowIndex++;
                    continue;
                }

                $columnIndex = 0;
                foreach ($rowData as $headerName => $cellData) {

                    $attributePath = $cellData['attribute_path'];
                    $cellValue = $cellData['value'];

                    if (!empty($attributePath) && $headerName !== 'Container ID' && $headerName !== 'Source Product ID' && $headerName !== 'Title') {

                        $attributeDefinition = $this->getAttributeValidationRules($attributePath, $exportedValidationListsSheet, $exportedAttributePathsListsSheet);

                        if ($attributeDefinition !== null) {
                            $isAttrValidResponse = $this->applyValidationRules($cellValue, $attributeDefinition, $cellData);

                            if (!$isAttrValidResponse['is_valid']) {
                                $validationErrors[] = [
                                    'row' => $rowIndex,
                                    'column' => $headerName,
                                    'value' => $cellValue,
                                    'attribute_path' => $attributePath,
                                    'message' => $isAttrValidResponse['message'] ?? 'Invalid value'
                                ];
                                $isValidProduct = false; // Mark product as invalid due to attribute error 
                            } else {
                                $query = $this->getQueryForValidAttribute($cellData, $attributeDefinition);
                                if ($query) {
                                    $rowData[$headerName]['db_data'] = $query;
                                }
                            }
                        } else {
                            $isValidProduct = false;
                        }
                    }
                    $columnIndex++;
                }
                if ($isValidProduct) {
                    $validProducts[] = $rowData; // Add to valid products array if no errors 
                } else {
                    $invalidIdCount++;
                }
                $rowIndex++;
            }

            $response = [
                'success' => true,
                'message' => 'All products are correct.',
                'valid_products' => $validProducts,
                'validation_errors' => $validationErrors,
                'invalid_id_count' => $invalidIdCount
            ];
            if (!empty($validationErrors)) {
                $response['message'] = "Data validation completed. Some products were not validated. See errors for details.";
            }

            $exportedSpreadsheet->disconnectWorksheets();
            unset($exportedSpreadsheet);
            gc_collect_cycles();

            return $response;
        } catch (\Throwable $e) {

            if ($exportedSpreadsheet) {
                $exportedSpreadsheet->disconnectWorksheets();
                unset($exportedSpreadsheet);
                gc_collect_cycles();
            }

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getQueryForValidAttribute($cellData, $attributeDefinition)
    {
        $attributePath = $cellData['attribute_path'] ?? [];

        $attributePathArray = explode('.', $attributePath);

        $attributeRoot = $attributePathArray[0];

        $additionalKeys = $this->amazonProductTypeSchema['properties'][$attributeRoot]['selectors'] ?? [];

        $requiredAdditionalKeys = $this->getRequiredAdditionalKeys($this->amazonProductTypeSchema['properties'][$attributeRoot], $attributePathArray);
        if ($requiredAdditionalKeys != null) {
            $additionalKeys = $requiredAdditionalKeys;
        }

        $completeAttributeValue = [];

        $attributePreparedValue = [];
        if (isset($attributeDefinition['enum'])) {
            $recommendationValue = $attributeDefinition['enumNamesAndEnum'][$cellData['value']] ?? $cellData['value'];

            if (isset($attributeDefinition['type'])) {
                if ($attributeDefinition['type'] == 'boolean') {
                    $recommendationValue = strtolower($cellData['value']) == 'yes' ? true : false;
                } elseif ($attributeDefinition['type'] == 'array' && !is_array($recommendationValue)) {
                    $recommendationValue = [$recommendationValue];
                }
            }

            $attributePreparedValue = [
                'attribute_value' => 'recommendation',
                'type' => $attributeDefinition['type'] ?? 'not found',
                'recommendation' => $recommendationValue
            ];
        } else {
            $customValue = $cellData['value'];

            if (isset($attributeDefinition['type'])) {
                if ($attributeDefinition['type'] == 'boolean') {
                    $customValue = strtolower($cellData['value']) == 'yes' ? true : false;
                } elseif ($attributeDefinition['type'] == 'array' && !is_array($customValue)) {
                    $customValue = [$customValue];
                }
            }

            $attributePreparedValue = [
                'attribute_value' => 'custom',
                'type' => $attributeDefinition['type'] ?? 'not found',
                'custom' => $customValue
            ];
        }

        $additionalKeysPreparedValue = [];

        if (!empty($additionalKeys)) {
            foreach ($additionalKeys as $key) {
                $keyValue = $this->amazonProductTypeSchema['$defs'][$key] ?? null;
                if ($keyValue !== null && isset($keyValue['default'], $keyValue['type'])) {
                    $additionalKeysPreparedValue[$key] = [
                        'attribute_value' => 'recommendation',
                        'type' => $keyValue['type'],
                        'recommendation' => $keyValue['default']
                    ];
                }
            }
        }

        $completeAttributeValue = $this->buildCompleteAttribute($attributePathArray, $attributePreparedValue, $additionalKeysPreparedValue);
        if ($completeAttributeValue) {
            return $completeAttributeValue;
        }
    }

    private function getRequiredAdditionalKeys($schemaPart, $attributePathArray)
    {
        array_shift($attributePathArray);
        $previousSchemaPart = $schemaPart; // Keep track of the previous level

        foreach ($attributePathArray as $key) {
            $previousSchemaPart = $schemaPart; // Update previous level before moving to the next level
            if (is_array($schemaPart) && isset($schemaPart['items']) && $key === '0') {
                $schemaPart = $schemaPart['items'];
            } elseif (is_array($schemaPart) && isset($schemaPart['properties']) && isset($schemaPart['properties'][$key])) {
                $schemaPart = $schemaPart['properties'][$key];
            } elseif (is_array($schemaPart) && isset($schemaPart['properties']) && !is_numeric($key) && !isset($schemaPart['properties'][$key]) && isset($schemaPart[$key])) {
                // Handle cases where 'type' and 'value' are direct keys and not under 'properties'
                $schemaPart = $schemaPart[$key];
            } elseif (is_array($schemaPart) && isset($schemaPart[$key])) {
                $schemaPart = $schemaPart[$key];
            } else {
                return null; // Invalid path
            }
        }

        if (isset($previousSchemaPart['required'])) {
            return $previousSchemaPart['required'];
        }

        return null;
    }

    private function buildCompleteAttribute($keys, $attrVal, $additionalData = [])
    {
        $result = [];
        $temp = &$result;

        // Traverse and create nested structure dynamically
        $lastKey = array_pop($keys); // Extract last key
        foreach ($keys as $key) {
            $temp = &$temp[$key];
        }

        // Assign the final value and merge additional data at the same level
        $temp[$lastKey] = $attrVal;
        $temp = array_merge($temp, $additionalData);

        return $result;
    }

    private function checkIDsInExportedSheet(string $containerId, string $sourceProductId, Worksheet $exportedMainWorksheet): bool
    {
        foreach ($exportedMainWorksheet->getRowIterator(2) as $row) {
            $exportedContainerId = trim($exportedMainWorksheet->getCell('B' . $row->getRowIndex())->getValue());
            $exportedSourceProductId = trim($exportedMainWorksheet->getCell('C' . $row->getRowIndex())->getValue());

            if ($exportedContainerId === $containerId && $exportedSourceProductId === $sourceProductId) {
                return true;
            }
        }
        return false;
    }

    private function getAttributeValidationRules(string $attributePath, Worksheet $exportedValidationListsSheet, Worksheet $exportedAttributePathsListsSheet): ?array
    {
        $attributePathArray = explode('.', $attributePath);
        $attributeKey = $attributePathArray[0] ?? null;
        $attributeDef = $this->amazonProductTypeSchema['properties'][$attributeKey] ?? [];

        $attributeDef = $this->getAttributeDefinition($attributeDef, $attributePathArray);

        if (!isset($attributeDef['enum'])) {
            return $attributeDef;
        }

        try {
            $validationColumn = null;
            foreach ($exportedValidationListsSheet->getRowIterator(1, 1) as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $cellValue = $cell->getValue() ? trim($cell->getValue()) : $cell->getValue();
                    if ($cellValue === trim($attributePath)) {
                        $validationColumn = $cell->getColumn();
                        break 2;
                    }
                }
            }


            if ($validationColumn) {
                $enumNamesAndEnum = [];
                $startRow = 2;
                while (true) {
                    $optionCell = $exportedValidationListsSheet->getCell($validationColumn . $startRow);
                    $optionValue = $optionCell->getValue() ? trim($optionCell->getValue()) : $optionCell->getValue();
                    if (empty($optionValue)) {
                        break;
                    }

                    $enumCell = $exportedAttributePathsListsSheet->getCell($validationColumn . $startRow);
                    $enumNamesAndEnum[$optionValue] = $enumCell->getValue() ? trim($enumCell->getValue()) : $enumCell->getValue();

                    $startRow++;
                }

                if (!empty($enumNamesAndEnum)) {
                    $attributeDef['enumNamesAndEnum'] = $enumNamesAndEnum;
                    return $attributeDef;
                }
            }
        } catch (\Exception $e) {
            return null;
        }
        return null;
    }

    public function getAttributeDefinition(array $schemaPart, array $attributePathArray): ?array
    {
        if (!empty($attributePathArray)) {
            try {
                return $this->resolveSchemaPath($schemaPart, $attributePathArray);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    private function resolveSchemaPath(array $schema, array $path): ?array
    {
        array_shift($path);

        $current = $schema;
        foreach ($path as $segment) {
            if (is_numeric($segment) && isset($current['items'])) {
                $current = $current['items'];
                continue;
            }

            if (isset($current['properties']) && array_key_exists($segment, $current['properties'])) {
                $current = $current['properties'][$segment];
                continue;
            }

            if (isset($current[$segment])) {
                $current = $current[$segment];
                continue;
            }

            if (isset($current['items']) && is_array($current['items'])) {
                $temp = $current['items'];
                if (isset($temp['properties']) && array_key_exists($segment, $temp['properties'])) {
                    $current = $temp['properties'][$segment];
                    continue;
                }
            }

            return null;
        }
        return $current;
    }

    private function applyValidationRules($value, array $rules, &$cellData): array
    {
        $isValid = true;
        $errorMessage = '';

        $type = $rules['type'] ?? null;

        switch ($type) {
            case 'string':
                if (!is_string($value) && !is_null($value)) {
                    $isValid = false;
                    $errorMessage = 'Value must be a string.';
                    break;
                }
                if (isset($rules['minLength']) && strlen($value) < $rules['minLength']) {
                    $isValid = false;
                    $errorMessage = 'String length is less than minimum allowed length: ' . $rules['minLength'] . '.';
                    break;
                }
                if (isset($rules['maxLength']) && strlen($value) > $rules['maxLength']) {
                    $isValid = false;
                    $errorMessage = 'String length exceeds maximum allowed length: ' . $rules['maxLength'] . '.';
                    break;
                }
                if (isset($rules['enumNamesAndEnum']) && !empty($rules['enumNamesAndEnum'])) {
                    if (!isset($rules['enumNamesAndEnum'][$value]) && !is_null($value)) {
                        $isValid = false;
                        $errorMessage = 'Value is not in the allowed list of values. Please select from Amazon Recommendations only.';
                        break;
                    }
                }
                // Check if the attribute title suggests a date and if the value is numeric (Excel serial)
                if (isset($rules['title']) && stripos($rules['title'], 'date') !== false && is_numeric($value)) {
                    $convertedDate = $this->convertToDate($value);
                    if ($convertedDate !== false) {
                        $cellData['value'] = $convertedDate;
                    }
                }
                break;

            case 'integer':
                if (!is_int($value) && !is_numeric($value) && !is_null($value) && $value !== '') {
                    $isValid = false;
                    $errorMessage = 'Value must be an integer.';
                    break;
                }
                if (!is_null($value) && $value !== '') {
                    $value = intval($value);
                    if (isset($rules['minimum']) && $value < $rules['minimum']) {
                        $isValid = false;
                        $errorMessage = 'Value is less than minimum allowed value: ' . $rules['minimum'] . '.';
                        break;
                    }
                    if (isset($rules['maximum']) && $value > $rules['maximum']) {
                        $isValid = false;
                        $errorMessage = 'Value exceeds maximum allowed value: ' . $rules['maximum'] . '.';
                        break;
                    }
                }
                break;

            case 'number':
                if (!is_numeric($value) && !is_null($value) && $value !== '') {
                    $isValid = false;
                    $errorMessage = 'Value must be a number.';
                    break;
                }
                if (!is_null($value) && $value !== '') {
                    $value = floatval($value);
                    if (isset($rules['minimum']) && $value < $rules['minimum']) {
                        $isValid = false;
                        $errorMessage = 'Value is less than minimum allowed value: ' . $rules['minimum'] . '.';
                        break;
                    }
                    if (isset($rules['maximum']) && $value > $rules['maximum']) {
                        $isValid = false;
                        $errorMessage = 'Value exceeds maximum allowed value: ' . $rules['maximum'] . '.';
                        break;
                    }
                    if (isset($rules['multipleOf']) && is_numeric($rules['multipleOf'])) {
                        $epsilon = 1e-15; // A small threshold for floating point comparisons
                        if (abs(fmod(floatval($value), floatval($rules['multipleOf']))) > $epsilon) {
                            $isValid = false;
                            $errorMessage = 'Value must be a multiple of: ' . $rules['multipleOf'] . '.';
                            break;
                        }
                    }
                }
                break;

            case 'boolean':
                if (!is_bool($value) && !is_null($value) && strtolower($value) !== 'yes' && strtolower($value) !== 'no' && $value !== '0' && $value !== '1' && $value !== 0 && $value !== 1 && $value !== '') {
                    $isValid = false;
                    $errorMessage = 'Value must be a boolean (Yes/No, True/False, 0/1).';
                    break;
                }
                if (isset($rules['enumNames']) && !empty($rules['enumNames'])) {
                    $validEnums = array_map('strtolower', $rules['enumNames']);
                    $checkValue = is_bool($value) ? ($value ? 'yes' : 'no') : strtolower($value);
                    if (!in_array($checkValue, $validEnums, true) && !is_null($value) && $value !== '') {
                        $isValid = false;
                        $errorMessage = 'Value is not in the allowed boolean values. Allowed values are: ' . implode(', ', $rules['enumNames']) . '.';
                        break;
                    }
                }
                break;


            default:
                break; // Unknown type, consider valid
        }

        return ['is_valid' => $isValid, 'message' => $errorMessage];
    }

    /**
     * Convert a value to a date string in the format 'Y-m-d H:i:s' if it has a time component, or 'Y-m-d' if it only has a date.
     *
     * @param mixed $value Value to convert to a date string. If numeric, it will be treated as an Excel serial date. If string, it will be passed to date_create.
     * @return string|false Date string in the format 'Y-m-d H:i:s' or 'Y-m-d' if successful, false if the input was invalid.
     */
    public function convertToDate($value)
    {
        // Handle numeric values (Excel serial date)
        if (is_numeric($value)) {
            try {
                $dateTime = PhpOfficeDate::excelToDateTimeObject($value);
            } catch (\Exception $e) {
                return false;
            }
            // Determine if there's a significant fractional component (has time)
            $fraction = $value - floor($value);
            if (abs($fraction) > 0.000001) {
                return $dateTime->format('Y-m-d H:i:s');
            } else {
                return $dateTime->format('Y-m-d');
            }
        } else {
            // Attempt to create a date from the string input
            $dateTime = date_create($value);
            if ($dateTime === false) {
                // Not a valid date string
                return false;
            }
            // Heuristic: if the string contains a colon, assume it includes a time component
            if (strpos($value, ':') !== false) {
                return $dateTime->format('Y-m-d H:i:s');
            } else {
                return $dateTime->format('Y-m-d');
            }
        }
    }

    private function updateDatabaseFromImportData(array $importData, array $params): array
    {
        $updatedProductCount = 0;
        $failedUpdates = [];

        foreach ($importData as $rowData) {
            $containerId = $rowData['Container ID']['value'] ?? '';
            $sourceProductId = $rowData['Source Product ID']['value'] ?? '';
            $attributesToUpdate = [];

            foreach ($rowData as $headerName => $cellData) {
                $attributePath = $cellData['attribute_path'];

                if (!empty($attributePath) && $headerName !== 'Container ID' && $headerName !== 'Source Product ID' && $headerName !== 'Title') {
                    $attributesToUpdate[] = $cellData['db_data'];
                }
            }
            $preparedAttr = $this->recursiveArrayMerge($attributesToUpdate);

            if (!empty($preparedAttr)) {
                $updateResult = $this->updateProductAttributesInDatabase($containerId, $sourceProductId, $preparedAttr, $params);
                if ($updateResult['success']) {
                    $updatedProductCount++;
                } else {
                    $failedUpdates[] = [
                        'container_id' => $containerId,
                        'source_product_id' => $sourceProductId,
                        'errors' => $updateResult['message'] ?? 'Database update failed'
                    ];
                }
            }
        }

        return [
            'success' => true,
            'message' => "Updated products.",
            'failed_updates' => $failedUpdates,
            'updated_count' => $updatedProductCount
        ];
    }


    public function recursiveArrayMerge(array $arrays): array
    {
        $merged = [];
        foreach ($arrays as $array) {
            $merged = $this->mergeRecursive($merged, $array);
        }
        return $merged;
    }

    /**
     * Recursively merges two arrays.
     *
     * When both arrays have the same key and the corresponding values are arrays,
     * they will be merged recursively.
     *
     * @param array $array1 The first array.
     * @param array $array2 The second array.
     * @return array The merged result.
     */
    public function mergeRecursive(array $array1, array $array2): array
    {
        foreach ($array2 as $key => $value) {
            // If the key does not exist in the first array, add it.
            if (!array_key_exists($key, $array1)) {
                $array1[$key] = $value;
            } else {
                // If the key exists in both arrays...
                if (is_array($array1[$key]) && is_array($value)) {
                    // If both values are numeric arrays, merge element by element.
                    if ($this->isNumericArray($array1[$key]) && $this->isNumericArray($value)) {
                        foreach ($value as $index => $item) {
                            if (isset($array1[$key][$index])) {
                                $array1[$key][$index] = $this->mergeRecursive($array1[$key][$index], $item);
                            } else {
                                $array1[$key][$index] = $item;
                            }
                        }
                    } else {
                        // Otherwise, merge associative arrays by key.
                        $array1[$key] = $this->mergeRecursive($array1[$key], $value);
                    }
                } else {
                    // If one of them is not an array, override with the second value.
                    $array1[$key] = $value;
                }
            }
        }
        return $array1;
    }

    /**
     * Helper function to check if an array is numerically indexed.
     *
     * @param array $arr
     * @return bool
     */
    public function isNumericArray(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private function updateProductAttributesInDatabase(string $containerId, string $sourceProductId, array $attributes, array $params): array
    {
        //TODO - use connector saveProduct
        $filterQuery = [
            'user_id' => $params['user_id'],
            'shop_id' => $params['target_shop_id'],
            'target_marketplace' => 'amazon',
            'container_id' => $containerId,
            'source_product_id' => $sourceProductId,
        ];

        $setOnInsert = [
            'created_at' => date('c')
        ];

        $filterUpdate = [
            'bulk_edit_attributes_mapping' => $attributes,
            'updated_at' => date('c'),
        ];

        try {
            $productContainer = $this->di->getObjectManager()
                ->get(\App\Core\Models\BaseMongo::class)
                ->getCollectionForTable('product_container');

            $productContainer->updateOne($filterQuery, ['$set' => $filterUpdate, '$setOnInsert' => $setOnInsert], ['upsert' => true]);

            return ['success' => true, 'message' => 'Product updated successfully'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Product update failed ' . $e->getMessage()];
        }
    }

    /**
     * Set config flag so cleanup can skip when user has never run bulk-edit import.
     * Uses group_code 'template_bulk_edit' and key 'has_bulk_edit_imported' = true.
     *
     * @param string $userId
     * @param string $sourceShopId
     * @param string $targetShopId
     * @return void
     */
    private function setBulkEditImportedFlag(string $userId, string $sourceShopId, string $targetShopId): void
    {
        try {
            $config = $this->di->getObjectManager()->get(\App\Core\Models\Config\Config::class);
            $config->setUserId($userId);

            $sourceMarketplace = $this->di->getObjectManager()
                ->get('\App\Core\Models\User\Details')
                ->getShop($sourceShopId, $userId);

            $configData = [
                'user_id' => $userId,
                'source' => $sourceMarketplace,
                'source_shop_id' => $sourceShopId,
                'target' => 'amazon',
                'target_shop_id' => $targetShopId,
                'group_code' => 'template_bulk_attributes_edit',
                'key' => 'has_imported',
                'value' => true,
            ];
            $config->setConfig([$configData]);
        } catch (\Throwable $e) {
            $this->logError('Failed to set bulk-edit imported flag: ' . $e->getMessage());
        }
    }

    private function logError(string $message): void
    {
        $logPath = 'amazon' . DS . 'bulk_attr_edit' . DS . $this->di->getUser()->id . DS . 'import.log';
        $this->di->getLog()->logContent($message, 'error', $logPath);
    }

    private function logInfo(string $message): void
    {
        $logPath = 'amazon' . DS . 'bulk_attr_edit' . DS . $this->di->getUser()->id . DS . 'import.log';
        $this->di->getLog()->logContent($message, 'info', $logPath);
    }

    public function addNotification(array $params)
    {
        $notificationData = [
            'user_id' => $params['user_id'],
            'message' => $params['message'],
            'severity' => $params['severity'],
            'created_at' => date('c'),
            'marketplace' => 'amazon',
            'process_code' => self::IMPORT_PROCESS_CODE,
        ];


        if (!empty($params['url'])) {
            $notificationData['url'] = $params['url'];
        }

        $notification = $this->di->getObjectManager()->get('App\Connector\Models\Notifications');
        $notification->addNotification($params['shop_id'], $notificationData);
    }

    public function getAmazonSchema($params)
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
        return json_decode($schema, true);
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

    public function updateQueuedTask($feedId, $progress, $msg = "", $addAndUpdate = true)
    {
        return $this->di->getObjectManager()->get(ConnectorQueuedTasks::class)->updateFeedProgress($feedId, $progress, $msg, $addAndUpdate);
    }
}
