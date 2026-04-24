---
name: shopify-label-assignment
description: Shopify product label CRUD and assignment—getLabels, createLabel, deleteLabel, saveProductLabels, bulkLabelAssignmentCSV, initateBulkLabelAssignmentProcess, label_container, assigned_labels, bulk_label_assignment queue, csv_product_label queued task, CSV bulk upload. Use whenever someone asks about product labels, label assignment, bulk label CSV upload, label_container collection, assigned_labels field on product_container/refine_product, or debugging why labels aren't showing or bulk assignment is stuck.
---

# Shopify Product Label Assignment

Documents **CRUD operations** on product labels and **assignment** of labels to products—both single-product and bulk CSV via SQS.

**Primary file:** `app/home/app/code/shopifyhome/Components/Product/Label.php`

Use the references for file-level detail:

- [`references/label-component-methods.md`](references/label-component-methods.md) — all Label class methods, parameters, MongoDB operators
- [`references/bulk-csv-pipeline.md`](references/bulk-csv-pipeline.md) — SQS pipeline, CSV processing, progress tracking, cleanup

---

## Architecture Overview

### CRUD + Single-Product Assignment (synchronous)

```
Frontend / API request
  → RequestController::{action}Action()
      → parses JSON or form body
      → Label component via DI ObjectManager
          → Label::initiate() (opens MongoDB collections)
          → Label::getLabels()       — read all labels + optional product count
          → Label::createLabel()     — insert into label_container
          → Label::deleteLabel()     — delete from label_container + $pull from product_container & refine_product
          → Label::saveProductLabels() — $set or $unset assigned_labels on product_container & refine_product
      → prepareResponse()
```

### Bulk CSV Assignment (asynchronous via SQS)

```
Frontend uploads CSV + params
  → RequestController::startBulkLabelAssignmentAction()
      → attaches uploaded files to $rawBody
      → Label::bulkLabelAssignmentCSV()
            → validate file type (text/csv only)
            → create queued_tasks entry (process_code: csv_product_label)
            → move CSV to var/file/{userId}/bulk_label_assignment.csv
            → push SQS message to queue: bulk_label_assignment
                handler: Label::initateBulkLabelAssignmentProcess

SQS Consumer picks up message
  → Label::initateBulkLabelAssignmentProcess()
      → page 1: count total CSV rows (max 10,000)
      → fetchCsvData() — read 500 rows at current offset
      → assignLabels() — $addToSet labels on matching products
      → updateQueuedTaskProgress() — write progress %
      → if more pages: re-queue to SQS with page+1
      → final page: removeEntryFromQueuedTask + updateActivityLog + delete CSV
```

---

## API Actions → Label Methods

| Controller Action | Label Method | HTTP Body |
|-------------------|-------------|-----------|
| `getLabelsAction` | `getLabels($data)` | `source_shop_id`, `source_marketplace`, optional `count` flag |
| `createLabelAction` | `createLabel($data)` | `name` (required) |
| `deleteLabelAction` | `deleteLabel($data)` | `id` (MongoDB ObjectId string) |
| `saveProductLabelsAction` | `saveProductLabels($data)` | `container_id`, `assigned_labels` array |
| `startBulkLabelAssignmentAction` | `bulkLabelAssignmentCSV($data)` | `unique_identifier`, `assigned_labels` (JSON string), CSV file upload |

All actions live in `shopifyhome/controllers/RequestController.php` (lines 5708–5774).

---

## Constants

| Constant / Value | Location | Purpose |
|-----------------|----------|---------|
| `ALLOWED_FILE_TYPE = 'text/csv'` | `Label.php:34` | Only CSV uploads accepted |
| Page size = `500` | `initateBulkLabelAssignmentProcess` | Rows processed per SQS message |
| Max rows = `10,000` | `initateBulkLabelAssignmentProcess` | CSV row limit; exceeding fails the job |
| `process_code = 'csv_product_label'` | `bulkLabelAssignmentCSV` | Queued task identifier |
| `queue_name = 'bulk_label_assignment'` | `bulkLabelAssignmentCSV` | SQS logical queue name |

---

## MongoDB Collections

| Collection | Field(s) | Role |
|-----------|----------|------|
| `label_container` | `user_id`, `source_shop_id`, `source`, `name`, `id`, `created_at` | Stores label definitions |
| `product_container` | `assigned_labels` (array of `{id, name}`) | Products with labels assigned |
| `refine_product` | `assigned_labels` (array of `{id, name}`) | Mirror of labels for refined/filtered view |
| `queued_tasks` | `process_code = 'csv_product_label'`, `progress` | Tracks bulk assignment in-progress state |

**Note:** Both `product_container` and `refine_product` are always updated together to keep label state consistent.

---

## Symptom → Where to Look

| Symptom | Likely Location |
|---------|-----------------|
| Labels not showing for user | `getLabels()` — check `user_id`, `source_shop_id`, `source` filter in `label_container` |
| Label created but no ID | `createLabel()` — the `id` field is set via a second `updateOne` after `insertOne` |
| Deleting label doesn't remove from products | `deleteLabel()` — `$pull` on `product_container` and `refine_product`; check `source_marketplace` vs `source_shop_id` filter |
| Single assign not persisting | `saveProductLabels()` — verify `container_id` and `visibility = 'Catalog and Search'` filter |
| Bulk assignment stuck / no progress | `initateBulkLabelAssignmentProcess()` — check `queued_tasks` for stale entry; SQS message may have failed |
| "Already under process" error | `bulkLabelAssignmentCSV()` — `QueuedTasks::setQueuedTask` returns false if existing entry for `csv_product_label` |
| CSV column not found | `fetchCsvData()` — headers are lowercased; `unique_identifier` param must match lowercase column name |
| "Max rows allowed" error | `initateBulkLabelAssignmentProcess()` — CSV exceeds 10,000 data rows |
| Bulk done but count is 0 | `assignLabels()` — `$in` match on identifier field; check if `unique_identifier` column values match `product_container` data |

---

## Key Files

All paths relative to `app/home/app/code/`.

| File | Class | Key Methods |
|------|-------|-------------|
| `shopifyhome/Components/Product/Label.php` | `Label` | `getLabels`, `createLabel`, `deleteLabel`, `saveProductLabels`, `bulkLabelAssignmentCSV`, `initateBulkLabelAssignmentProcess`, `assignLabels` |
| `shopifyhome/controllers/RequestController.php` | `RequestController` | `getLabelsAction`, `createLabelAction`, `deleteLabelAction`, `saveProductLabelsAction`, `startBulkLabelAssignmentAction` |
| `connector/Models/QueuedTasks.php` | `QueuedTasks` | `setQueuedTask` — duplicate process guard |
| `connector/Models/Notifications.php` | `Notifications` | `addNotification` — activity log for bulk completion |

---

## SQS Queue

Prefix = `{app_code}_` from `etc/config.php`.

| Logical Name | Role |
|-------------|------|
| `bulk_label_assignment` | Carries bulk CSV assignment messages; handler class = `Label`, method = `initateBulkLabelAssignmentProcess` |

---

## Logs

| Path Pattern | Source |
|-------------|--------|
| `exception.log` | All catch blocks in Label.php — `getLabels`, `createLabel`, `deleteLabel`, `saveProductLabels`, `assignLabels`, `updateQueuedTaskProgress`, `removeEntryFromQueuedTask` |

---

## Additional References

- [`references/label-component-methods.md`](references/label-component-methods.md) — method-by-method detail with parameters, return values, MongoDB operators
- [`references/bulk-csv-pipeline.md`](references/bulk-csv-pipeline.md) — full SQS pipeline, CSV format, pagination, progress tracking, cleanup
