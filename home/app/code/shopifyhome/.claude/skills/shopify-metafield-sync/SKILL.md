# Shopify Product Metafield Sync (CRON)

Documents CRON/CLI-driven sync of Shopify product metafields into MongoDB via Admin bulk operations, SQS, and JSONL processing. Use this skill whenever someone asks about `shopify:product-custom-metafield-sync`, `priority:sync-product-metafield`, `metafieldsync`, `shopify_metafield_import`, `shopify_process_metafield_users`, `Metafield::initiateMetafieldSync`, stuck `metafield_import` queued tasks, bulk `/bulk/operation`, or why metafields are not updating for closed stores. Also use when debugging `product_container` metafield fields, `acceptedMetafieldTypes`, or the difference between the **direct `Metafield` SQS pipeline** and the **Vistar `Requestcontrol::handleImport`** path.

**Primary file:** `app/home/app/code/shopifyhome/Components/Product/Metafield.php`

---

## Architecture Overview

```
CRON / CLI
  │
  ├─ shopify:product-custom-metafield-sync (CustomMetafieldSyncCommand)
  │     ├─ -u all_users → Helper::customMetafieldSyncCron (config batch 500)
  │     │       → Metafield::initiateMetafieldSync per user (NO updated_at in code)
  │     │       → SQS shopify_process_metafield_users (next batch)
  │     │
  │     └─ -p / -b → user list from config → Metafield::initiateMetafieldSync
  │           (+ updated_at window, metafield_stuck_check = 2h)
  │
  └─ priority:sync-product-metafield (PriorityMetafieldSyncCommand)
        → priority_metafield_sync_users only
        → QueuedTasks + Vistar Data::pushToQueue
        → Requestcontrol::handleImport on shopify_metafield_import
              (extra_data: metafieldsync + updated_at)

Metafield::initiateMetafieldSync path (same queue name, different producer):
  SQS shopify_metafield_import
    → Metafield::startMetafieldImportProcess
    → POST /bulk/operation (shopify) + type metafieldsync + optional updated_at
        → shopifywebapi: BulkOperation → BulkOperationQL → BulkOperationQuery
          → getQueryByType → getQueryForMetafieldsync (see references/remote-shopifywebapi-bulk-operation.md)
    → Metafield::checkMetafieldBulkOperationStatus (delay 180s while RUNNING)
    → JSONL download → Metafield::readMetafieldJSONL (chunks)
    → moldAndSaveMetafieldData → product_container (+ optional definitions)
```

**Vistar path** (`Requestcontrol::handleImport`, operation `make_request`): uses `Vistar\Import::createRequest` and chained `pushToQueue` steps (`get_status`, `build_progress`, etc.) — same **queue** `shopify_metafield_import` but **not** the `Metafield::startMetafieldImportProcess` chain. See `references/cli-entry-points.md`.

---

## Key constants (`Metafield.php`)

| Constant | Value | Role |
|----------|-------|------|
| `PROCESS_CODE` | `metafield_import` | `queued_tasks` / UI progress |
| `QUEUE_NAME` | `shopify_metafield_import` | SQS logical name |
| `REQUEST_STATUS_DELAY` | 180 | Seconds delay on retry / poll |
| `READ_JSON_FILE_BATCH_SIZE` | 2000 | JSONL lines read per message |
| `BULK_WRITE_CHUNK` | 500 | Mongo `bulkWrite` chunk size |

---

## Guards

- **Shop:** First active Shopify shop with `apps[0].app_status == active` and **no** `store_closed_at` (`Metafield::getShopData`, `PriorityMetafieldSyncCommand`, `Helper::metafieldSyncCRON` / similar).

---

## Relationship to Other Skills

| Skill | Relationship |
|-------|----------------|
| [SQS Consumer](../amazon-sqs-consumer/SKILL.md) | Workers consume `shopify_metafield_import` and `shopify_process_metafield_users` |
| [Store Close Process](../shopify-store-close-process/SKILL.md) | `store_closed_at` excludes shops from metafield sync |

---

## SQS queues

Prefix = `{app_code}_` from `etc/config.php` (e.g. `dev_amazonbyced_shopify_metafield_import`).

| Logical name | Role |
|--------------|------|
| `shopify_metafield_import` | Metafield pipeline **or** Vistar `Requestcontrol::handleImport` |
| `shopify_process_metafield_users` | Continuation batches for `customMetafieldSyncCron` and `metafieldSyncCRON` |

---

## MongoDB collections

| Collection | Role |
|------------|------|
| `queued_tasks` | In-progress `metafield_import`; stuck detection deletes stale rows |
| `product_container` | `parentMetafield` / `variantMetafield` updates |
| `product_metafield_definitions_container` | Optional definition catalog per user/shop |
| `config` | Rows with `group_code=product`, `key=metafield`, `value=true` drive **all-users** cron user list |

---

## Config keys (examples)

| Key | Role |
|-----|------|
| `priority_metafield_sync_users` | User IDs for priority CLI and skipped in some all-user batches |
| `beta_metafield_sync_users` | User IDs for `-b` custom command branch |
| `acceptedMetafieldTypes` | If set, only those GraphQL metafield types are saved |
| `saveMetafieldDefinitions` | When true, upsert into `product_metafield_definitions_container` |

---

## Logs

| Path pattern | Source |
|--------------|--------|
| `shopify/metafieldSync/{userId}/{date}.log` | `Metafield` per-user |
| `shopify/metafieldSync/allUsers/{date}.log` | `Helper::customMetafieldSyncCron` / related |
| `shopify/priorityMetafieldSync/{date}.log` | `PriorityMetafieldSyncCommand` |

---

## Behavioral note: `updated_at` window vs all-users

`CustomMetafieldSyncCommand` builds `timeDelay` from `-t` / `-m`, but **`initiateMetafieldSyncForAllUsers()`** only passes `app_tag` into **`Helper::customMetafieldSyncCron`**. The **home app** therefore does not send `updated_at` on that path. On **shopifywebapi**, **`getQueryForMetafieldsync`** still applies a default filter: **products updated since start of yesterday** when `updated_at` is absent (see `references/remote-shopifywebapi-bulk-operation.md`). The **beta/priority** branch passes **`updated_at`** from the CLI window into `Metafield::initiateMetafieldSync`. See `references/cli-entry-points.md`.

---

## Key files (home app)

| File | Role |
|------|------|
| `shopifyhome/Components/Product/Metafield.php` | Direct SQS pipeline |
| `shopifyhome/console/CustomMetafieldSyncCommand.php` | `shopify:product-custom-metafield-sync` |
| `shopifyhome/console/PriorityMetafieldSyncCommand.php` | `priority:sync-product-metafield` |
| `shopifyhome/Components/Product/Helper.php` | `customMetafieldSyncCron`, `metafieldSyncCRON` |
| `shopifyhome/Components/Product/Vistar/Route/Requestcontrol.php` | `handleImport` for Vistar-produced messages |
| `shopifyhome/Components/Product/Vistar/Data.php` | `pushToQueue` scheduling |

---

## Additional References

- **`references/cli-entry-points.md`** — CLI options, stuck thresholds, Vistar vs Metafield entry
- **`references/metafield-sqs-pipeline.md`** — `Metafield` class method chain and remote API
- **`references/remote-shopifywebapi-bulk-operation.md`** — `BulkOperation` API → `BulkOperationQL` → `getBulkOperationQuery` / `getQueryByType` → `getQueryForMetafield` vs `getQueryForMetafieldsync`
- **`references/helper-batch-and-config.md`** — `customMetafieldSyncCron` vs `metafieldSyncCRON`
