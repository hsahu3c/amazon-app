# CLI entry points and stuck-task behavior

---

## CustomMetafieldSyncCommand

**File:** `app/home/app/code/shopifyhome/console/CustomMetafieldSyncCommand.php`  
**Command:** `shopify:product-custom-metafield-sync`

### Required options

| Option | Short | Meaning |
|--------|-------|---------|
| `app_tag` | `-a` | App tag set on DI before sync |
| `hours` | `-t` | Used to build `timeDelay` for **user-wise** paths only |
| One of `all_users`, `priority_users`, `beta_users` | `-u`, `-p`, `-b` | Which population to run |

Optional: `minutes` (`-m`, default 0) — included in `timeDelay` for user-wise paths.

`timeDelay` string: `-{hours} hours -{minutes} minutes` → formatted as `updated_at` ISO: `(new DateTime($this->timeDelay))->format('Y-m-d\TH:i:s\Z')`.

### Branches

1. **`beta_users` (`-b`):** Reads `beta_metafield_sync_users` from config; for each user id calls `Metafield::initiateMetafieldSync` with:
   - `filter_type` = `metafieldsync` (constant `METAFIELD_FILTER_TYPE`)
   - `updated_at` = formatted `timeDelay`
   - `metafield_stuck_check` = **2** (hours multiplier for stuck logic on `created_at` when no `updated_at` on queued row)

2. **`priority_users` (`-p`):** Same as beta but list from `priority_metafield_sync_users`.

3. **`all_users` (`-u`):** Calls `Helper::customMetafieldSyncCron(['app_tag' => $this->appTag])` only — **does not pass `hours`, `minutes`, or `updated_at`**. The CLI `timeDelay` is unused on this branch.

---

## PriorityMetafieldSyncCommand

**File:** `app/home/app/code/shopifyhome/console/PriorityMetafieldSyncCommand.php`  
**Command:** `priority:sync-product-metafield`

### Options

- `-a` / `--app_tag` (required)
- `-t` / `--hours` (required)
- `-m` / `--minutes` (optional, default 0)

### Flow

1. Loads `priority_metafield_sync_users` from config.
2. For each user: `setDiForUser`, pick first Shopify shop with active app and **no** `store_closed_at`.
3. `QueuedTasks::setQueuedTask` with `process_code` = `metafield_import`.
4. If task already exists: **`checkMetafieldProgressStuck`** — compares `updated_at` or `created_at` on `queued_tasks` to **now**; if older than **2 hours**, deletes that queued task document and allows a new task.
5. Builds SQS payload: `class_name` = `Requestcontrol::class`, `method` = `handleImport`, `queue_name` = `shopify_metafield_import`, `data.operation` = `make_request`, `process_code` = `metafield`, `extra_data.filterType` = `metafieldsync`, `extra_data.updated_at` = time window string.
6. `Vistar\Data::pushToQueue($prepareData)` (not raw `Sqs::pushMessage`).

**Queued task lookup for stuck check** includes `appTag` on the query (along with `user_id`, `shop_id`, `process_code`, `marketplace`).

**Note:** Loop variable for shop in payload uses `'shop' => $shop` while the selected shop document is in `$shopData`; the last `$shop` from the foreach may not match `$shopData` if multiple shops exist — behavior depends on PHP variable state at loop end.

---

## Requestcontrol::handleImport (Vistar entry)

**File:** `app/home/app/code/shopifyhome/Components/Product/Vistar/Route/Requestcontrol.php`

`handleImport($sqsData)` switches on `$sqsData['data']['operation']`:

- **`make_request`:** `Vistar\Import::createRequest($remote_shop_id, $sqsData)`; on success `Data::pushToQueue($response['sqs_data'], delay)` — continues the Vistar import state machine (not `Metafield::startMetafieldImportProcess`).
- **`get_status`:** Poll bulk/request status; re-queue with backoff.
- **`build_progress`:** Progress bar steps; may `completeProgressBar` on failure/notice.
- Further cases (`import_product`, etc.) continue catalog import — metafield-specific payloads still flow through this router when `process_code` / `extra_data` indicate metafield mode (see `Import` implementation for filter wiring).

If `checkQueuedTaskExists(feed_id)` is false and there is no `file_path` but there is `id`, **`SourceModel::cancelBulkRequestOnMarketplace`** runs and returns `true`.

So **priority CLI** and **`Helper::metafieldSyncCRON`** converge on **Vistar** + `shopify_metafield_import`; **customMetafieldSyncCron** and **CustomMetafieldSyncCommand user-wise** use **`Metafield`** methods on the **same queue name** but a **different** `class_name` / `method` on the first message.

---

## Stuck-task thresholds compared

| Location | Stuck threshold | Notes |
|----------|-----------------|-------|
| `Metafield::checkMetafieldProgressStuck` | `updated_at` older than **24h**; or `created_at` older than **`metafield_stuck_check` × 3600** s | Default `metafield_stuck_check` from `initiateMetafieldSync` = **23** if omitted |
| Custom command user-wise | passes `metafield_stuck_check` = **2** | So **2h** on `created_at` branch |
| `PriorityMetafieldSyncCommand::checkMetafieldProgressStuck` | **2h** | Uses `updated_at` or `created_at`; filters by `appTag` |
| `Helper::metafieldSyncCRON` | Uses private `checkMetafieldProgressStuck` in Helper | Same pattern as other 24h-style checks in that file for metafield_import rows |
