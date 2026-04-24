<?php

namespace App\Amazon\Components\Template\BulkAttributesEdit\Delete;

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
use App\Connector\Models\QueuedTasks as ConnectorQueuedTasks;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use App\Amazon\Components\Template\BulkAttributesEdit\ChunkReadFilter;

class Import
{
    const IMPORT_CHUNK_SIZE = 50;
    const IMPORT_PROCESS_CODE = 'template_bulk_attribute_delete_import';
    const IMPORT_QUEUE_NAME = 'template_bulk_attribute_delete_import';

    /**
     * Entry point for initiating the Excel import process for attribute deletion.
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
            $excelValidationResult['message'] = isset($excelValidationResult['message']) ? explode('/var/www/html/', $excelValidationResult['message'])[0] : 'Something went wrong';
            return $excelValidationResult;
        }

        $totalProductRows = $this->getRowCountBySheetName($importFilePath, 'Worksheet') - 1;

        $params['import_file_path'] = $importFilePath;
        $params['total_products_rows'] = $totalProductRows;

        $response = $this->commenceTemplateProductsImport($params);
        if ($response['success']) {
            return ['success' => true, 'message' => 'Import process initiated successfully. Check overview activity section for updates.'];
        }
        return $response;
    }

    private function getRowCountBySheetName(string $filePath, string $sheetName): int
    {
        $reader = new XlsxReader();
        $sheetsInfo = $reader->listWorksheetInfo($filePath);

        foreach ($sheetsInfo as $sheetInfo) {
            if (strcasecmp($sheetInfo['worksheetName'], $sheetName) === 0) {
                return (int)$sheetInfo['totalRows'];
            }
        }
        return 0;
    }

    public function commenceTemplateProductsImport(array $params): array
    {
        $params['start_row'] = 2;
        $params['processed_rows_count'] = 0;
        $params['total_deleted_attributes_count'] = 0;
        $params['total_invalid_id_count'] = 0;
        $params['total_failed_deletions'] = [];

        $queuedTaskData = [
            'user_id' => $params['user_id'],
            'message' => "Please wait while we import your product attribute deletions. It might take a little while.", // Updated message
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
    }

    private function addToQueuedTasks(array $queueData, string $shopId)
    {
        $queuedTask = $this->di->getObjectManager()->get(ConnectorQueuedTasks::class);
        $queuedTaskId = $queuedTask->setQueuedTask($shopId, $queueData);
        if (!$queuedTaskId) {
            return ['success' => false, 'message' => 'Template Bulk Attribute Delete import is already under progress. Please check activity section for updates.']; // Updated message
        }
        return ['success' => true, 'queued_task_id' => $queuedTaskId];
    }

    private function pushToImportQueue(array $params)
    {
        $preparedQueueData =  [
            'type' => 'full_class',
            'class_name' => \App\Amazon\Components\Template\BulkAttributesEdit\Delete\Import::class,
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
                'message' => 'Product Attribute deletion import has been terminated. Please try again later or contact support if the issue persists.', // Updated message
                'marketplace' => "amazon",
                'severity' => 'error',
                'process_code' => self::IMPORT_PROCESS_CODE,
            ];
            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($params['target_shop_id'] ?? null, $notificationData);
            return true;
        }

        $importFilePath = $params['import_file_path'] ?? null;
        $exportedFilePath = $params['exported_file_path'] ?? null;
        $sourceShopId = $params['source_shop_id'] ?? null;
        $targetShopId = $params['target_shop_id'] ?? null;
        $userId = $params['user_id'] ?? null;
        $startRow = $params['start_row'] ?? 2;
        $processedRowsCount = $params['processed_rows_count'] ?? 0;
        $totalDeletedAttributesCount = $params['total_deleted_attributes_count'] ?? 0;
        $totalInvalidIdCount = $params['total_invalid_id_count'] ?? 0;
        $totalFailedDeletions = $params['total_failed_deletions'] ?? [];
        $totalProductRows = $params['total_products_rows'] ?? 0;

        if ($processedRowsCount >= $totalProductRows) {
            $this->updateQueuedTask($queuedTaskId, 100, addAndUpdate: false);

            $completionMsg = 'Product Attributes Deletion completed';
            if (!empty($params['template_name'])) {
                $completionMsg = "Product Attributes Deletion for the template '{$params['template_name']}' completed";
            }
            $messageParts = [
                $completionMsg,
                "Total products processed: {$totalProductRows}"
            ];

            if ($totalDeletedAttributesCount) {
                $messageParts[] = "Attributes Deleted: {$totalDeletedAttributesCount}";
            }
            if ($totalInvalidIdCount) {
                $messageParts[] = "Invalid IDs: {$totalInvalidIdCount}";
            }
            if (!empty($totalFailedDeletions)) {
                $messageParts[] = "Failed deletions: " . count($totalFailedDeletions);
            }

            $message = implode('. ', $messageParts) . '.';

            $this->addNotification([
                'user_id' => $userId,
                'shop_id' => $targetShopId,
                'message' => $message,
                'severity' => 'success'
            ]);
            return true;
        }


        if (empty($importFilePath) || empty($exportedFilePath) || empty($sourceShopId) || empty($userId)) {
            $errorMessage = 'Missing required parameters for import processing (file paths, shop ID, user ID).';
            $this->logError("Import Failed: " . $errorMessage);
            $this->abortQueuedTask($params, 'Product Attributes deletion import failed due to missing information. Contact support.');
            return true;
        }

        // 1. Data Extraction and Parsing
        $importDataResult = $this->extractImportDataChunk($importFilePath, $startRow, self::IMPORT_CHUNK_SIZE, $exportedFilePath);
        if (!$importDataResult['success']) {
            $this->logError("Import Data Extraction Failed: " . $importDataResult['message']);
            $this->addNotification([
                'user_id' => $userId,
                'shop_id' => $targetShopId,
                'message' => 'Product Attributes deletion import encountered error during reading some data from Excel file', // Updated message
                'severity' => 'warning'
            ]);
            $queueData['data']['start_row'] = $startRow + self::IMPORT_CHUNK_SIZE;
            $queueData['data']['processed_rows_count'] = $processedRowsCount + self::IMPORT_CHUNK_SIZE;
            $this->pushMessageToQueue($queueData);
            return true;;
        }

        $importDataChunk = $importDataResult['data'];

        if (empty($importDataChunk)) {
            $queueData['data']['start_row'] = $startRow + self::IMPORT_CHUNK_SIZE;
            $queueData['data']['processed_rows_count'] = $processedRowsCount + self::IMPORT_CHUNK_SIZE;
            $this->pushMessageToQueue($queueData);
            return true;
        }

        // 2. Data Validation (Simplified for Delete)
        $validationResult = $this->validateImportData($importDataChunk, $exportedFilePath, $exportedFilePath); // Pass exportedFilePath again

        if (!$validationResult['success']) {
            $this->logError("Import Data Validation Failed: " . $validationResult['message'] . '. Errors: ' . json_encode($validationResult['errors'] ?? []));
            $this->addNotification([
                'user_id' => $userId,
                'shop_id' => $targetShopId,
                'message' => 'Product Attributes deletion import encountered error during validation of products.', // Updated message
                'severity' => 'warning'
            ]);
            $queueData['data']['total_invalid_id_count'] = $totalInvalidIdCount + ($validationResult['invalid_id_count'] ?? 0); // Use null coalescing operator
            $queueData['data']['start_row'] = $startRow + self::IMPORT_CHUNK_SIZE;
            $queueData['data']['processed_rows_count'] = $processedRowsCount + self::IMPORT_CHUNK_SIZE;
            $this->pushMessageToQueue($queueData);
            return true;
        }

        $invalidIdCount = $validationResult['invalid_id_count'] ?? 0;

        // 3. Data Update in Database (Attribute Deletion)
        $databaseUpdateResult = $this->updateDatabaseFromImportData($validationResult['valid_products'], $params);

        if (!$databaseUpdateResult['success']) {
            $this->logError("Database Deletion Failed: " . $databaseUpdateResult['message'] . '. Failed deletions: ' . json_encode($databaseUpdateResult['failed_deletions'] ?? [])); // Updated message
            $this->addNotification([
                'user_id' => $userId,
                'shop_id' => $targetShopId,
                'message' => 'Product attribute deletion partially failed during database update.',
                'severity' => 'warning'
            ]);

            $queueData['data']['retry'] = ($queueData['data']['retry'] ?? 0) + 1;
            if ($queueData['data']['retry'] > 5) {
                $queueData['data']['start_row'] = $startRow + self::IMPORT_CHUNK_SIZE;
                $queueData['data']['processed_rows_count'] = $processedRowsCount + self::IMPORT_CHUNK_SIZE;
                $this->addNotification([
                    'user_id' => $userId,
                    'shop_id' => $targetShopId,
                    'message' => 'Product attribute deletion partially failed during database update. Please contact support',
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
        $deletedAttributesCount = $databaseUpdateResult['deleted_attributes_count'];
        $failedDeletions = $databaseUpdateResult['failed_deletions'];

        $queueData['data']['total_deleted_attributes_count'] = $totalDeletedAttributesCount + $deletedAttributesCount;
        $queueData['data']['total_failed_deletions'] = array_merge($totalFailedDeletions, $failedDeletions);
        $queueData['data']['processed_rows_count'] = $processedRowsCount + self::IMPORT_CHUNK_SIZE;
        $queueData['data']['start_row'] = $startRow + self::IMPORT_CHUNK_SIZE;

        $this->pushMessageToQueue($queueData);
        return true;

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


    private function getSheetHeaders(string $filePath, string $sheetName): array
    {
        $reader = new XlsxReader();
        $chunkFilter = new ChunkReadFilter();
        $reader->setReadFilter($chunkFilter);

        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadDataOnly(true);

        $chunkFilter->setRows(1, 1);

        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) {
            throw new \Exception('Sheet "' . $sheetName . '" not found in file ' . $filePath);
        }

        $rowIterator = $sheet->getRowIterator(1, 1);
        $row = $rowIterator->current();
        if (!$row) {
            throw new \Exception('No header row found in sheet "' . $sheetName . '" in file ' . $filePath);
        }

        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(true);
        $headers = [];
        foreach ($cellIterator as $cell) {
            $headers[] = trim((string)$cell->getValue());
        }

        return $headers;
    }


    private function extractImportDataChunk(string $filePath, int $startRow, int $chunkSize, string $exportedFilePath): array
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

            $headerRow = $mainWorksheet->getRowIterator()->current();
            $cellIterator = $headerRow->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $headers = [];
            $headerColumnMap = [];
            $columnIndex = 0;
            foreach ($cellIterator as $cell) {
                $headerValue = $cell->getValue() ? trim($cell->getValue()) : $cell->getValue();
                $headers[] = $headerValue;
                $headerColumnMap[$columnIndex] = ['header' => $headerValue, 'attribute_path' => null]; // attribute_path not needed here but keeping structure
                $columnIndex++;
            }

            $attributePathsSheet = $spreadsheet->getSheetByName('AttributePaths');
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
                    }
                    $cellIndex++;
                }
                if (!empty($rowData) && isset($rowData['Container ID']['value']) && isset($rowData['Source Product ID']['value']) && count($rowData) > 3) {
                    $importDataChunk[] = $rowData;
                }
            }

            return ['success' => true, 'data' => $importDataChunk];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error extracting data chunk: ' . $e->getMessage()];
        }
    }


    private function validateImportData(array $importData, string $exportedFilePath): array
    {
        try {
            $exportedReader = new XlsxReader();
            $exportedReader->setLoadSheetsOnly(['Worksheet']); // Only need Worksheet from exported file
            $exportedReader->setReadDataOnly(true);
            $exportedSpreadsheet = $exportedReader->load($exportedFilePath);
            $exportedMainWorksheet = $exportedSpreadsheet->getActiveSheet();

            $validationErrors = [];
            $validProducts = [];
            $invalidIdCount = 0;
            $rowIndex = 2;

            foreach ($importData as $rowData) {

                $containerId = $rowData['Container ID']['value'] ?? '';
                $sourceProductId = $rowData['Source Product ID']['value'] ?? '';

                $idCheckResult = $this->checkIDsInExportedSheet($containerId, $sourceProductId, $exportedMainWorksheet);

                if (!$idCheckResult) {
                    $validationErrors[] = [
                        'row' => $rowIndex,
                        'column' => 'Container ID & Source Product ID',
                        'value' => "Container ID: {$containerId}, Source Product ID: {$sourceProductId}",
                        'message' => 'This Container ID and Source Product ID combination was not found in the originally exported file. Only exported products can be processed.'
                    ];
                    $invalidIdCount++;
                    $rowIndex++;
                    continue;
                }

                $validProducts[] = $rowData;

                $rowIndex++;
            }

            $response = [
                'success' => true,
                'message' => 'Product ID validation completed.',
                'valid_products' => $validProducts,
                'validation_errors' => $validationErrors,
                'invalid_id_count' => $invalidIdCount
            ];
            if (!empty($validationErrors)) {
                $response['message'] = "Data validation completed. Some products were not validated. See errors for details.";
            }

            return $response;
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
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


    private function updateDatabaseFromImportData(array $importData, array $params): array
    {
        $deletedAttributesCount = 0;
        $failedDeletions = [];

        foreach ($importData as $rowData) {
            $containerId = $rowData['Container ID']['value'] ?? '';
            $sourceProductId = $rowData['Source Product ID']['value'] ?? '';
            $attributesToDelete = [];

            $attributeHeaderIndex = 0;
            foreach ($rowData as $headerName => $cellData) {
                 if ($headerName !== 'Container ID' && $headerName !== 'Source Product ID' && $headerName !== 'Title') {
                    if ($cellData['value'] === 'Delete') {

                        $attributePathToDelete = $cellData['attribute_path'] ?? null;
                        if ($attributePathToDelete) {
                            $attributesToDelete[] = $attributePathToDelete;
                        }
                    }
                    $attributeHeaderIndex++;
                }
            }

            if (!empty($attributesToDelete)) {
                $updateResult = $this->deleteProductAttributesInDatabase($containerId, $sourceProductId, $attributesToDelete, $params);
                if ($updateResult['success']) {
                    $modifiedCount = isset($updateResult['modified_count']) ? $updateResult['modified_count'] : count($attributesToDelete);
                    $deletedAttributesCount += $modifiedCount;
                } else {
                    $failedDeletions[] = [
                        'container_id' => $containerId,
                        'source_product_id' => $sourceProductId,
                        'attributes' => $attributesToDelete,
                        'errors' => $updateResult['message'] ?? 'Database deletion failed'
                    ];
                }
            }
        }

        return [
            'success' => true,
            'message' => "Deleted attributes for products.",
            'failed_deletions' => $failedDeletions,
            'deleted_attributes_count' => $deletedAttributesCount
        ];
    }


    private function deleteProductAttributesInDatabase(string $containerId, string $sourceProductId, array $attributePaths, array $params): array
    {
        $filterQuery = [
            'user_id' => $params['user_id'],
            'shop_id' => $params['target_shop_id'],
            'target_marketplace' => 'amazon',
            'container_id' => $containerId,
            'source_product_id' => $sourceProductId,
        ];

        $unsetQuery = [];
        foreach ($attributePaths as $path) {
            $pathToUnset = $this->getPathToUnset($path);
            $unsetQuery['bulk_edit_attributes_mapping.' . $pathToUnset] = 1;
        }

        if (empty($unsetQuery)) {
            return ['success' => true, 'message' => 'No attributes to delete.'];
        }


        $productContainer = $this->di->getObjectManager()
            ->get(\App\Core\Models\BaseMongo::class)
            ->getCollectionForTable('product_container');

        try {
            $dbResp = $productContainer->updateOne($filterQuery, ['$unset' => $unsetQuery]); // Use $unset operator for deletion
            return [
                'success' => true,
                'message' => 'Product attributes deletion initiated successfully.',
                'modified_count' => $dbResp->getModifiedCount()
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Product attributes deletion failed: ' . $e->getMessage()];
        }
    }

    private function getPathToUnset($path)
    {
        $pathsToSkip = ['value', '0', 'value_with_tax'];
        $pathArray = explode('.', $path);
        for ($i = count($pathArray) - 1; $i >= 0; $i--) {
            if (in_array($pathArray[$i], $pathsToSkip)) {
                unset($pathArray[$i]);
                continue;
            }
            break;
        }
        return implode('.', $pathArray);
    }

    private function logError(string $message): void
    {
        error_log("[BulkDeleteAttributeImport Error] " . $message);
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
            'process_initiation' => 'manual',
        ];

        if (!empty($params['url'])) {
            $notificationData['url'] = $params['url'];
        }

        $notification = $this->di->getObjectManager()->get('App\Connector\Models\Notifications');
        $notification->addNotification($params['shop_id'], $notificationData);
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