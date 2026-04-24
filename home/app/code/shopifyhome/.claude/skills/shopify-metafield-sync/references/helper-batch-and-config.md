# Helper batch crons: `customMetafieldSyncCron` vs `metafieldSyncCRON`

**File:** `app/home/app/code/shopifyhome/Components/Product/Helper.php`

Both methods paginate the same **`config`** slice but enqueue **different** first-hop handlers.

---

## Shared aggregation

MongoDB **`config`** collection, aggregation pipeline (conceptually):

1. `$match`: `group_code` = `product`, `key` = `metafield`, `value` = **true**
2. `$project`: `user_id`
3. `$sort`: `_id` ascending
4. `$limit`: **500**

If `$params['_id']` is passed (from a prior SQS continuation), an extra **`$match`** is prepended: `_id` > that ObjectId so the next batch continues after the last processed config document.

For each batch:

- Skip users listed in **`priority_metafield_sync_users`** (when that config array is non-empty).
- After processing, if `count($userData) == 500`, push continuation to **`shopify_process_metafield_users`** with `class_name` = `Helper::class`, `user_id` / `_id` from the **last** row of the batch (used as cursor for the next `customMetafieldSyncCron` or `metafieldSyncCRON` invocation).

**Log file:** `shopify/metafieldSync/allUsers/{d-m-Y}.log`

---

## customMetafieldSyncCron($params)

**Used by:** `CustomMetafieldSyncCommand` **all_users** (`-u`) — passes only **`app_tag`** in `$params` today.

**Per user:**

- Resolves `Metafield` component and calls:

```php
$metafieldObj->initiateMetafieldSync([
    'user_id' => $user['user_id'],
    'filter_type' => 'metafieldsync',
    'app_tag' => $params['app_tag'],
]);
```

- **No `updated_at`** is passed from this helper on the home request. **`getQueryForMetafieldsync`** on **shopifywebapi** then uses its **built-in default** (products updated since start of yesterday) — not “all products.” See **`remote-shopifywebapi-bulk-operation.md`**.

**Continuation:** `method` = **`customMetafieldSyncCron`**, queue **`shopify_process_metafield_users`**.

---

## metafieldSyncCRON($params)

**Not** invoked by `CustomMetafieldSyncCommand`. Used when SQS (or other callers) dispatch **`Helper::metafieldSyncCRON`** on **`shopify_process_metafield_users`** — same aggregation and skip rules as above.

**Per user:**

- `setDiForUser`, resolve Shopify shop (active app, no `store_closed_at`).
- `QueuedTasks::setQueuedTask` for **`metafield_import`** (same user-facing process as other paths).
- Builds payload for **`Vistar\Data::pushToQueue`**:
  - `class_name` = **`Requestcontrol::class`**
  - `method` = **`handleImport`**
  - `queue_name` = **`shopify_metafield_import`**
  - `data.operation` = **`make_request`**
  - `process_code` = **`metafield`**
  - `extra_data.filterType` = **`metafieldsync`**
  - **No `updated_at`** in `extra_data` in this helper (contrast **PriorityMetafieldSyncCommand**, which adds `updated_at`).

**Continuation:** `method` = **`metafieldSyncCRON`**, queue **`shopify_process_metafield_users`**.

**Implementation note:** Payload uses `'shop' => $shop` while the resolved document is in `$shopData`; verify in code if multiple shops exist.

---

## Summary table

| Method | First hop | Passes `updated_at` to remote | Typical trigger |
|--------|-----------|-------------------------------|-----------------|
| `customMetafieldSyncCron` | `Metafield::initiateMetafieldSync` | Only if caller adds it (CLI all-users does not) | `shopify:product-custom-metafield-sync -u` |
| `metafieldSyncCRON` | `Requestcontrol::handleImport` | No in `extra_data` from this helper | SQS `shopify_process_metafield_users` |
| `PriorityMetafieldSyncCommand` | `Requestcontrol::handleImport` | Yes in `extra_data` | `priority:sync-product-metafield` |
