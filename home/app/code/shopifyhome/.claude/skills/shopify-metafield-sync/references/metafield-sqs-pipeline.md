# Metafield class SQS pipeline (`Metafield.php`)

This is the path used when messages specify `class_name` = `App\Shopifyhome\Components\Product\Metafield` (e.g. `initiateMetafieldSync` → `prepareSqsData`).

**File:** `app/home/app/code/shopifyhome/Components/Product/Metafield.php`

---

## initiateMetafieldSync($data)

1. `initilize()` — SQS helper, ApiClient, Vistar Helper, Mongo collections (`queued_tasks`, `product_container`, `product_metafield_definitions_container`).
2. Requires `user_id`, `filter_type`.
3. `setDiForUser($userId)`.
4. `getShopData()` — first eligible Shopify shop.
5. `setAppTag($data, $shopData)`.
6. `setQueuedTask` — creates `queued_tasks` row for `metafield_import`; if insert blocked, `checkMetafieldProgressStuck` may delete stale row and retry.
7. On success string `feed_id`: `pushMessage(prepareSqsData(...))` with `method` = **`startMetafieldImportProcess`**, `queue_name` = `shopify_metafield_import`.

`metafield_stuck_check` default **23** if not provided (used in stuck logic).

---

## prepareSqsData

Message includes: `user_id`, `shop_id`, `shop`, `feed_id`, `filter_type`, optional `updated_at`, `appCode`, `appTag`, `app_code` / `app_codes` arrays.

---

## startMetafieldImportProcess($sqsData)

1. POST remote **`/bulk/operation`** via `ApiClient::init('shopify', true)` with body:
   - `shop_id` = `remote_shop_id`
   - `type` = `filter_type` (e.g. **`metafieldsync`**)
   - optional `updated_at` from SQS payload

   **Remote execution order (shopifywebapi):** [`Api/BulkOperation::createBulkOperation`](app/remote/app/code/shopifywebapi/Api/BulkOperation.php) → [`BulkOperationQL::createBulkOperation`](app/remote/app/code/shopifywebapi/Components/BulkOperation/BulkOperationQL.php) → [`BulkOperationQuery::getBulkOperationQuery`](app/remote/app/code/shopifywebapi/Components/BulkOperation/BulkOperationQuery.php) → **`getQueryByType`** (dispatches to **`getQueryForMetafieldsync`** when `type` is `metafieldsync`, or **`getQueryForMetafield`** when `type` is `metafield`). Full detail: **`references/remote-shopifywebapi-bulk-operation.md`**.

2. **Success:** Push next message same queue, `method` = **`checkMetafieldBulkOperationStatus`**, `data` = remote bulk operation payload, `delay` = `REQUEST_STATUS_DELAY` (180 seconds).

3. **Failures:**
   - Empty response or token error message → **re-push same** `$sqsData` (retry).
   - Message contains “bulk query operation … already in progress” → re-push with `delay` = 180.
   - Otherwise → `removeEntryFromQueuedTask`, `updateActivityLog` (notification).

---

## checkMetafieldBulkOperationStatus($sqsData)

1. GET **`/bulk/operation`** with `shop_id` and bulk `id`.
2. Status **RUNNING** or **CREATED** → re-queue same payload (polling).
3. Status **COMPLETED:**
   - `objectCount == 0` → remove queued task, activity log “No Product(s) found”, exit.
   - Else if `url` present → `Vistar\Helper::productsMetafieldSaveFile(url)` saves JSONL locally; then push with `method` = **`readMetafieldJSONL`**, `data.file_path` set, `delay` cleared.
   - Missing URL or save failure → cleanup + failure notification.

4. Other terminal states → remove queued task + failure notification.

---

## readMetafieldJSONL($sqsData)

1. Validates `file_path` exists.
2. First pass: `countJsonlLines` stored in `data.total_rows`; `updated_products_count` initialized.
3. Opens file, seeks to `data.seek` (default 0).
4. Reads up to **READ_JSON_FILE_BATCH_SIZE** (2000) logical line iterations, grouping **Metafield** lines by parent **Product** or **ProductVariant** via `__parentId`.
5. Calls **`moldAndSaveMetafieldData`** for non-empty parent/variant maps; increments `updated_products_count`.
6. Updates `seek`, `rows_processed`, computes `progress` %, **`QueuedTasks::updateFeedProgress`**.
7. Re-pushes same message for next chunk until no lines read → then `removeEntryFromQueuedTask`, success notification, **`unlink` file**.

---

## moldAndSaveMetafieldData

- Reads config **`acceptedMetafieldTypes`** (if non-empty, filters by `type`).
- **`saveMetafieldDefinitions`** config: when true, collects `namespace->key` codes and calls **`saveMetafieldDefinitions`** into `product_metafield_definitions_container` (`$addToSet` per parent/variant list).
- Builds bulk **`updateOne`** ops via **`prepareDbData`**:
  - **parent:** filter `user_id`, `shop_id`, `container_id` = Shopify product id string, `visibility` = `Catalog and Search`; `$set` `parentMetafield` map + `updated_at`.
  - **variant:** filter `user_id`, `shop_id`, `source_product_id` = variant’s product id string; `$set` `variantMetafield`.
- Executes **`product_container->bulkWrite`** in chunks of **BULK_WRITE_CHUNK** (500).

Metafield map keys: `namespace->key` → value struct with `namespace`, `key`, `value`, `type`, `created_at`, `updated_at` from GraphQL bulk JSON.

---

## Notifications and cleanup

- **`updateActivityLog`:** `Notifications::addNotification` with `process_code` `metafield_import`, severity success/critical.
- **`removeEntryFromQueuedTask`:** `deleteOne` on `queued_tasks` for current user + `process_code`.

---

## Logging

Per-user log path: `shopify/metafieldSync/{userId}/{d-m-Y}.log`.
