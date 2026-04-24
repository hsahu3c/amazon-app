# Label Component — Method Reference

**File:** `app/home/app/code/shopifyhome/Components/Product/Label.php`
**Class:** `App\Shopifyhome\Components\Product\Label`
**Extends:** `App\Shopifyhome\Components\Core\Common`

---

## Properties

| Property | Type | Initialized By |
|----------|------|---------------|
| `$mongo` | `BaseMongo` | `initiate()` |
| `$labelContainer` | MongoDB Collection | `initiate()` → `getCollectionForTable('label_container')` |
| `$productContainer` | MongoDB Collection | `initiate()` → `getCollectionForTable('product_container')` |
| `$refineContainer` | MongoDB Collection | `initiate()` → `getCollectionForTable('refine_product')` |
| `$queueTasksContainer` | MongoDB Collection | `initiate()` → `getCollection('queued_tasks')` |

**Constant:** `ALLOWED_FILE_TYPE = 'text/csv'`

---

## Public Methods

### `initiate()`

Initializes all MongoDB collection handles. Called at the start of every public method.

- Creates `BaseMongo` via `$this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')`
- Maps 4 collections: `label_container`, `product_container`, `refine_product`, `queued_tasks`

---

### `getLabels($data = null): array`

Retrieves all labels for current user/shop/marketplace.

**Parameters:**
- `$data['source_shop_id']` — fallback if `getRequester()->getSourceId()` is null
- `$data['source_marketplace']` — fallback if `getRequester()->getSourceName()` is null
- `$data['count']` — optional flag; when set, queries product count per label

**MongoDB query:**
```
label_container.find({
  user_id, source_shop_id, source
}, projection: {_id:0, name:1, id:1, created_at:1})
```

**With count flag:** For each label, runs:
```
product_container.count({
  user_id, shop_id, source_marketplace,
  visibility: 'Catalog and Search',
  assigned_labels: {$elemMatch: {id: label.id}}
})
```

**Returns:** `{success: true, data: [...]}` or `{success: false, message: 'No data found'}`

---

### `createLabel($data): array`

Creates a new label document.

**Required:** `$data['name']`

**Flow:**
1. `insertOne` into `label_container` with `user_id`, `source_shop_id`, `source`, `name`, `created_at` (ISO 8601)
2. `updateOne` to set `id` field = `(string) insertedId` (MongoDB ObjectId as string)

**Returns:** `{success: true, message: 'Label created successfully'}` or `{success: false, message: 'Required params missing'}`

---

### `deleteLabel($data): array`

Deletes a label and cascades removal from all products.

**Required:** `$data['id']` — MongoDB ObjectId string

**Flow (3 operations):**
1. `label_container.deleteOne({_id: ObjectId(id)})`
2. `product_container.updateMany` — `$pull` label from `assigned_labels` where `assigned_labels.id == data.id`
   - Filter: `user_id`, `shop_id`, `source_marketplace`, `assigned_labels: {$exists: true}`
3. `refine_product.updateMany` — same `$pull` operation
   - Filter: `user_id`, `source_shop_id`, `assigned_labels: {$exists: true}`

**Returns:** `{success: true, message: 'Label deleted and unassigned from all products successfully'}`

**Note:** `product_container` uses `shop_id` while `refine_product` uses `source_shop_id` — different field names for the same concept.

---

### `saveProductLabels($data): array`

Assigns or unassigns labels for a single product.

**Required:** `$data['assigned_labels']` (must be `isset`), `$data['container_id']`

**Two branches:**

1. **Empty `assigned_labels`:** Uses `$unset` to remove the field entirely from both collections
   - `product_container.updateOne` filter includes `visibility: 'Catalog and Search'`
   - `refine_product.updateMany` — no visibility filter

2. **Non-empty `assigned_labels`:** Uses `$set` to replace the entire array
   - Same collection split as above

**Returns:** `{success: true, message: 'All labels unassigned successfully'}` or `{success: true, message: 'Label assigned successfully'}`

---

### `bulkLabelAssignmentCSV($data): array`

Entry point for bulk CSV label assignment. Validates, queues task, uploads file, pushes to SQS.

**Required:** `$data['unique_identifier']`, `$data['assigned_labels']` (JSON string)

**Flow:**
1. Validate file presence and type (`text/csv`)
2. Create `queued_tasks` entry via `QueuedTasks::setQueuedTask` — returns false if duplicate
3. Move uploaded file to `var/file/{userId}/bulk_label_assignment.csv`
4. Build SQS handler payload:
   - `type: 'full_class'`
   - `class_name: '\App\Shopifyhome\Components\Product\Label'`
   - `method: 'initateBulkLabelAssignmentProcess'`
   - `queue_name: 'bulk_label_assignment'`
5. Push to SQS via `App\Core\Components\Message\Handler\Sqs`

**Note:** `assigned_labels` is `json_decode`'d before being placed in SQS data.

---

### `initateBulkLabelAssignmentProcess($sqsData): array`

SQS consumer handler. Processes CSV in paginated 500-row batches.

**SQS payload fields:** `$sqsData['data']` contains `file_path`, `unique_identifier`, `assigned_labels`, `process_code`, `page` (starts at 1), `total_rows`, `labelAssignedCount`

**Flow per page:**
1. First page: count total rows via `getTotalRowsFromCsv()`
2. Validate max 10,000 rows
3. `fetchCsvData()` — read 500 identifiers at current offset
4. `assignLabels()` — update matching products
5. `updateQueuedTaskProgress()` — write progress percentage
6. If more pages remain: increment page, re-push to SQS
7. Final page: `removeEntryFromQueuedTask()` + `updateActivityLog()` + `unlink()` CSV

See [`bulk-csv-pipeline.md`](bulk-csv-pipeline.md) for detailed pipeline documentation.

---

## Private Methods

### `fetchCsvData($csvPath, $uniqueIdentifier, $offset, $limit): array`

Reads CSV and extracts values from specified column.

- Normalizes headers to lowercase
- Searches for `$uniqueIdentifier` in header row
- Returns paginated identifiers based on `$offset` and `$limit`
- Returns empty array if column not found

---

### `getTotalRowsFromCsv($csvPath): int`

Counts data rows (excludes header) in CSV file.

---

### `assignLabels($identifiers, $data): array`

Assigns labels to products matching identifiers.

**Flow:**
1. `array_unique` on identifiers
2. `product_container.distinct('container_id', {unique_identifier: {$in: identifiers}})` — finds matching container IDs
3. `product_container.updateMany` — `$addToSet` with `$each` for `assigned_labels` (prevents duplicates)
   - Filter includes `visibility: 'Catalog and Search'`
4. `refine_product.updateMany` — same `$addToSet`

**Returns:** `{success: true, data: count}` — count of products updated

---

### `updateActivityLog($data, $status, $message): void`

Creates a notification for bulk assignment completion/failure.

- Severity: `success` or `critical` based on `$status`
- Uses `App\Connector\Models\Notifications::addNotification()`

---

### `updateQueuedTaskProgress($processCode, $progress): void`

Updates `progress` field in `queued_tasks` collection for the current user and process code.

---

### `removeEntryFromQueuedTask($processCode): void`

Deletes the `queued_tasks` entry for the current user and process code. Called on completion or failure.
