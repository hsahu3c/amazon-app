# Bulk CSV Label Assignment — SQS Pipeline Reference

Documents the asynchronous bulk label assignment flow from CSV upload through SQS processing to completion.

---

## Entry Point

**Controller:** `RequestController::startBulkLabelAssignmentAction()` (line 5761)
**Component:** `Label::bulkLabelAssignmentCSV($data)`

### Request Payload

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `unique_identifier` | string | Yes | CSV column name to match products (e.g., `sku`, `title`). Lowercased during processing. |
| `assigned_labels` | JSON string | Yes | Labels to assign, e.g., `[{"id":"abc","name":"Sale"}]`. Decoded before SQS push. |
| CSV file | file upload | Yes | Must be `text/csv` MIME type |

---

## Pipeline Flow

### Step 1: Upload & Queue (synchronous — `bulkLabelAssignmentCSV`)

```
1. Validate: unique_identifier and assigned_labels present
2. Validate: file type == text/csv
3. QueuedTasks::setQueuedTask(shop_id, {
       user_id, message: "Label Assignment Process",
       process_code: "csv_product_label", marketplace
   })
   → Returns false if csv_product_label already in progress → abort
4. Move file → var/file/{userId}/bulk_label_assignment.csv
5. Build SQS payload:
   {
     type: "full_class",
     class_name: "\App\Shopifyhome\Components\Product\Label",
     method: "initateBulkLabelAssignmentProcess",
     user_id, shop_id, marketplace,
     queue_name: "bulk_label_assignment",
     data: {
       file_path: "var/file/{userId}/bulk_label_assignment.csv",
       unique_identifier: "sku",
       assigned_labels: [{id, name}, ...],
       process_code: "csv_product_label"
     }
   }
6. Push to SQS via App\Core\Components\Message\Handler\Sqs
```

### Step 2: SQS Processing (async — `initateBulkLabelAssignmentProcess`)

The SQS consumer invokes this method. It processes the CSV in pages of 500 rows, re-queuing itself for each subsequent page.

```
Page 1:
  → getTotalRowsFromCsv() — count rows (excl. header)
  → if total_rows > 10,000 → fail, cleanup, notify
  → fetchCsvData(path, identifier, offset=0, limit=500)
  → assignLabels(identifiers, sqsData)
  → updateQueuedTaskProgress(progress %)
  → more pages? → push SQS with page=2, total_rows, labelAssignedCount

Page 2..N:
  → fetchCsvData(path, identifier, offset=page*500, limit=500)
  → assignLabels(identifiers, sqsData)
  → updateQueuedTaskProgress(progress %)
  → more pages? → push SQS with page+1

Final page:
  → removeEntryFromQueuedTask("csv_product_label")
  → updateActivityLog(success, "labels assigned to X product(s)")
  → unlink(csv file)
```

### Step 3: Label Assignment (per page — `assignLabels`)

```
1. array_unique(identifiers) — deduplicate
2. product_container.distinct("container_id", {
       user_id, shop_id, source_marketplace,
       {unique_identifier}: {$in: identifiers}
   })
3. product_container.updateMany(
       {user_id, shop_id, source_marketplace, visibility:"Catalog and Search",
        container_id: {$in: containerIds}},
       {$addToSet: {assigned_labels: {$each: labels}}}
   )
4. refine_product.updateMany(
       {user_id, source_shop_id, container_id: {$in: containerIds}},
       {$addToSet: {assigned_labels: {$each: labels}}}
   )
```

`$addToSet` ensures no duplicate label entries if the same label is assigned again.

---

## CSV Format

- **Header row required** — column names are lowercased during parsing
- **Identifier column** — must match `unique_identifier` param (case-insensitive after lowering)
- **Other columns** — ignored; only the identifier column is read
- **Max rows:** 10,000 data rows (header excluded from count)

Example CSV:
```
SKU,Title,Price
ABC-001,Widget,9.99
ABC-002,Gadget,19.99
```
With `unique_identifier = "sku"`, the values `ABC-001` and `ABC-002` are extracted.

---

## Progress Tracking

| Collection | Field | Values |
|-----------|-------|--------|
| `queued_tasks` | `process_code` | `csv_product_label` |
| `queued_tasks` | `progress` | 0–100 (float, rounded to 2 decimals) |

**Formula:** `progress = round((page * 500 / total_rows) * 100, 2)`

Progress is visible in the UI's Overview section.

---

## Completion & Cleanup

On success (final page):
1. `queued_tasks` entry deleted
2. Notification created: severity `success`, message includes count of products updated
3. CSV file deleted from `var/file/{userId}/`

On failure (max rows exceeded or column not found):
1. `queued_tasks` entry deleted
2. Notification created: severity `critical` with error message
3. CSV file deleted

---

## Duplicate Process Guard

`QueuedTasks::setQueuedTask()` checks if a `csv_product_label` task already exists for the shop. If so, returns `false` and the upload is rejected with: *"Bulk Label Assignment is already under process. Check for updates in Overview section"*.

This prevents concurrent bulk assignments for the same shop.

---

## File Storage

| Path | Content |
|------|---------|
| `var/file/{userId}/bulk_label_assignment.csv` | Uploaded CSV; overwritten on each new bulk request; deleted after processing |

---

## SQS Message Structure

```json
{
  "type": "full_class",
  "class_name": "\\App\\Shopifyhome\\Components\\Product\\Label",
  "method": "initateBulkLabelAssignmentProcess",
  "user_id": "...",
  "shop_id": "...",
  "marketplace": "...",
  "queue_name": "bulk_label_assignment",
  "data": {
    "file_path": "var/file/{userId}/bulk_label_assignment.csv",
    "unique_identifier": "sku",
    "assigned_labels": [{"id": "...", "name": "..."}],
    "process_code": "csv_product_label",
    "page": 1,
    "total_rows": 5000,
    "labelAssignedCount": 0
  }
}
```

Fields `page`, `total_rows`, and `labelAssignedCount` are added/updated by `initateBulkLabelAssignmentProcess` during re-queue.
