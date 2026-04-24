---
name: csv-helper
description: Global CSV export/import processing engine — CsvHelper for S3/local file export, SQS-based chunked processing, BulkEdit import, createLine row formatting, nested path resolution, download URL generation. Used by all marketplaces (Amazon, etc.).
---

# CSV Helper (Global)

This skill documents the global CSV processing engine in the connector module (`Components/CsvHelper.php` and `controllers/CsvController.php`). This is the shared foundation used by all marketplace-specific CSV flows (Amazon, etc.).

> **Related Skills:**
> - `connector/.cursor/skills/product-filter/` — Product filtering and retrieval APIs used by CSV export
> - `Amazon/.cursor/skills/csv-import-export/` — Amazon-specific CSV validation, data processing, and column formatting

## Architecture Overview

```
Export Flow:
  Marketplace Controller (e.g., Amazon/RequestController->exportCSVAction)
    |
    v
  Marketplace Helper validates → Connector CsvHelper orchestrates
    |
    |-- getProductDetails()        → Local file export
    |-- getProductDetails_S3()     → S3 file export
    v
  SQS Queue (async for large datasets)
    |
    |-- ProductWriteCSVSQS()       → Local file writer (SQS handler)
    |-- ProductWriteCSVSQS_S3()    → S3 file writer (SQS handler)
    v
  Product Data via GetProductProfileMerge (see product-filter skill)
    |
    v
  createLine() → CSV row formatting with nested path support
    |
    v
  Notification + afterCSVExport event

Import Flow:
  CsvController->importCSVAction()
    |
    v
  Validate file + headers → importCSVAndUpdate() → SQS Queue
    |
    v
  BulkEdit() (SQS handler)
    |-- Read CSV chunks
    |-- checkUpdatedKeys() → matchContainerData()
    |-- sendUpdatedDataInModule() → Marketplace Helper->processCsvData()
    v
  Product updates via marketplace-specific logic
```

## Key Paths

| File | Purpose |
|------|---------|
| `controllers/CsvController.php` | Controller: exportCSVAction(), importCSVAction(), downloadCSVAction(), S3fileDownloadAction(), validateExportProcess() |
| `Components/CsvHelper.php` | Core engine: getProductDetails(), getProductDetails_S3(), ProductWriteCSVSQS(), ProductWriteCSVSQS_S3(), BulkEdit(), createLine(), importCSVAndUpdate() |
| `Components/Profile/GetProductProfileMerge.php` | Product data retrieval (see product-filter skill) |
| `Models/QueuedTasks.php` | Progress tracking model |
| `Models/Notifications.php` | Notification model for completion alerts |

## API Endpoints

| Method | Endpoint | Action |
|--------|----------|--------|
| POST | `connector/csv/exportCSV` | Start CSV export (connector-level) |
| POST | `connector/csv/importCSV` | Upload and import CSV file |
| GET | `connector/csv/downloadCSV` | Download local CSV via JWT token |
| GET | `connector/csv/S3fileDownload` | Validate and return S3 download URL |
| GET | `connector/csv/exportedFileName` | Check if previous export file exists |

## Export Flow Detail

### 1. Validation (`validateExportProcess`)

```php
// Check if export already in progress
$result = $csvHelper->validateExportProcess(['new_process' => true]);
// Returns: ['success' => false, 'message' => '...'] if duplicate

// Remove previous export and start fresh
$result = $csvHelper->validateExportProcess(['remove_last' => true]);
// Cleans up: queued_tasks, csv_export_product_container, config, physical files
```

### 2. Config Storage (`insertionInConfigForExport`)

Stores three config entries under group_code `csv_product`:

| Config Key | Purpose |
|------------|---------|
| `csv_export_options_filter` | Columns selected for export (title, price, quantity, etc.) |
| `csv_page_filter` | Export scope type (all/selected/current) |
| `key_path` | Nested object path mappings for complex fields |

### 3. Product Retrieval & CSV Writing

**Local File Export (`getProductDetails`):**
- Creates CSV file at `var/log/UserProductCSV/`
- Writes header row
- Generates JWT-encoded download URL
- Pushes `ProductWriteCSVSQS` to SQS queue

**S3 Export (`getProductDetails_S3`):**
- Determines chunk size: `ceil(totalCount / export_limit)`
- Direct call if product count <= 1000 (SQS_THRESHOLD)
- SQS queue if product count > 1000
- Pushes `ProductWriteCSVSQS_S3` handler

### 4. SQS Handlers

**ProductWriteCSVSQS_S3 (S3 writer):**
```php
$sqsData = [
    'user_id' => '...',
    'shop_id' => '...',
    'target_marketplace' => 'amazon',
    'target_shop_id' => '...',
    'totalCount' => 5000,
    'export_limit' => 300,        // CSV_EXPORT_CHUNK_SIZE
    'activePage' => 1,
    'file_path' => 'exports/user_123/products.csv',
    'columns' => ['title', 'price', 'quantity', 'barcode', 'sku'],
    'column_names' => ['Title', 'Price', 'Quantity', 'Barcode', 'SKU'],
    'type' => 'all',              // all | selected | current
    'source_product_id' => [],    // For selected/current
    'filter' => [],               // For filtered export
    'key_path' => [],             // Nested path mappings
    'useRefinProduct' => true
];
```

**Chunk processing loop:**
1. Fetch products via `getProductDetailsRecursive()` or `getAllProductsRecursively()`
2. Write rows via `createLine()` for each product + variants
3. Upload chunk to S3 via `putObject()`
4. Store rows in `csv_export_product_container` collection
5. If more chunks: increment `activePage`, re-queue to SQS
6. If complete:
   - Generate presigned S3 URL (72-hour expiry)
   - Create notification (`csv_product` code)
   - Fire `application:afterCSVExport` event
   - Update queued task to 100%

### 5. createLine (Row Formatting)

```php
$result = $csvHelper->createLine(
    $columns,       // ['title', 'price', 'quantity']
    $productValue,  // Product document from MongoDB
    $toEditUpdate,  // Unused
    $type,          // 'main' or 'child'
    $parent,        // Unused
    $keyPath        // Nested path mappings
);
// Returns:
// [
//     'line' => ["\tShirt", "\t29.99", "\t100"],  // Tab-prefixed values
//     'line_key_items' => ['title' => 'Shirt', 'price' => 29.99, 'quantity' => 100]
// ]
```

**Nested path support:** For complex fields like `attributes|color` or `dimensions|weight[unit]`, uses `getValueFromNestedArray()` with `|` as path delimiter.

**Column mapping:**
- `inventory` is converted to `quantity` internally
- `type` values are uppercased (MAIN, CHILD)
- Edited data (marketplace-specific overrides) takes priority over source data

## Import Flow Detail

### 1. File Upload (`importCSVAction`)

```
POST connector/csv/importCSV (multipart/form-data)
```

**Validation:**
- File type: CSV only (`text/csv`)
- Column headers: Must include `parent id` and `child id`
- At least one exported column must be present in import file
- Filename must match previously exported file

### 2. Async Processing (`BulkEdit` SQS handler)

```php
$sqsData = [
    'path' => '/var/file/import_123.csv',
    'feed_id' => 'queued_task_id',
    'limit' => 5,                    // Rows per chunk
    'count' => 1000,                 // Total rows
    'config_data' => [...],          // Export config for diff comparison
    'skip_character' => 0,           // File pointer position (ftell)
    'row_read' => 0,                 // Rows processed so far
    'start' => 0,                    // Start row index
];
```

**Chunk processing loop:**
1. Read CSV rows from file pointer position (`skip_character`)
2. Parse rows: map `parent id` -> `container_id`, `child id` -> `source_product_id`
3. Group rows by `container_id`
4. `checkUpdatedKeys()` — compare imported vs exported data in `csv_export_product_container`
5. `matchContainerData()` — identify only changed fields
6. `sendUpdatedDataInModule()` — dynamically load marketplace Helper and call `processCsvData()`
7. If more rows: update progress, re-queue with new `skip_character` position
8. If complete: delete `csv_export_product_container` + `config` docs, send notification

### 3. Marketplace Dispatch (`sendUpdatedDataInModule`)

```php
// Dynamically loads marketplace-specific helper
$className = "\App\{$targetMarketplace}\Components\Csv\Helper";
// Falls back to: "\App\{$targetMarketplace}home\Components\Csv\Helper"

// Calls marketplace processCsvData() for actual product updates
$result = $marketplaceHelper->processCsvData($updatedRows, $sqsData);
```

## Key Constants

| Constant | Value | Location | Purpose |
|----------|-------|----------|---------|
| `CSV_EXPORT_CHUNK_SIZE` | 300 | Amazon Helper | Products per export chunk |
| `SQS_THRESHOLD` | 1000 | Amazon Helper | Direct vs SQS processing threshold |
| `PATH_DELIMITER` | `\|` | CsvHelper | Nested array path separator |

## MongoDB Collections

| Collection | Purpose | Key Operations |
|------------|---------|----------------|
| `config` | Export/import metadata (group_code: `csv_product`) | insertOne, find, deleteMany |
| `csv_export_product_container` | Snapshot of exported products for diff on import | insertMany, find, deleteMany |
| `queued_tasks` | Job progress tracking | create, updateFeedProgress |
| `notifications` | Completion alerts (code: `csv_product`) | addNotification |
| `product_container` | Source product data (via GetProductProfileMerge) | aggregate |

## S3 Integration

- **Bucket config:** Stored in connector config as `bulkUpdate_bucket`
- **AWS config:** Loaded from `BP . '/app/etc/aws.php'`
- **Operations:** `doesObjectExist()`, `getObject()`, `putObject()`, `createPresignedRequest()` (72h expiry)
- **File key format:** `exports/{user_id}/{filename}.csv`

## Download Mechanisms

**Local file download (`downloadCSVAction`):**
- JWT token decodes to file path
- Returns file with `Content-Type: csv` and `Content-Disposition` headers

**S3 file download (`S3fileDownloadAction`):**
- Validates presigned S3 URL via cURL
- Returns URL for browser download

## Activity Codes

| Code | Display Name | Trigger |
|------|-------------|---------|
| `csv_product` | Bulk Export | Export completion |
| `template_bulk_edit_export` | Bulk Product Attributes Export | Template export |
| `template_bulk_edit_import` | Bulk Product Attributes Import | Template import |
| `bulk_linking_via_csv` | Product Linking By CSV | CSV linking |
| `unlinked_product_csv_export` | Unlinked Product Export | Unlinked export |

## Checklist - Adding CSV Export for a New Marketplace

- [ ] Create marketplace Helper at `{Marketplace}/Components/Csv/Helper.php` extending Base
- [ ] Implement `validateExportProcess($getParams)` for marketplace-specific validation
- [ ] Implement `formatColumnsName($columns)` if column names differ from defaults
- [ ] Implement `formatExportCsv($row, $columns)` if row formatting differs
- [ ] Implement `processCsvData($updatedData, $sqsData)` for import processing
- [ ] Implement `validateImportedData($row_data, $sqsData)` for import validation
- [ ] Create controller action that calls connector CsvHelper for orchestration
- [ ] Ensure `insertionInConfigForExport()` stores correct config entries
- [ ] Test with both small (<1000) and large (>1000) datasets for SQS path
- [ ] Verify notification and afterCSVExport event fire on completion
