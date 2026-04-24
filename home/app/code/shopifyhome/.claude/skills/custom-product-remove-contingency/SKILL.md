---
name: custom-product-remove-contingency
description: Full Product Remove Contingency architecture — ProductWebhookBackupCommand CLI, handleProductWebhook remote bulk/operation call, requestStatus polling, readJSONLFile batch marking, getContainerIds + removeContainerIds cleanup, unsetSalesChannel finalization. Helper.php 6-step async SQS pipeline removing products without Shopify Sales Channel from product_container, refine_product, amazon_listing. Deep dives in references/01–06; orchestration glue in this SKILL only.
---

# Product Remove Contingency — Full Process Architecture

This skill is the **single source for the whole pipeline**: (1) **phases with a reference file** -> open the linked `references/*.md`; (2) **orchestration / glue** (no separate reference) -> explained **only in this parent SKILL** below.

---

## Purpose

Removes products from the app's `product_container` collection that **no longer have a Shopify Sales Channel assigned** — a backup/contingency cleanup process. When Shopify removes the sales channel from products, those products should no longer exist in the app. This process identifies and removes them.

---

## Full Pipeline Map (what to read where)

| Step | Role | Detail |
|------|------|--------|
| **A** | CLI entry — select users, create QueuedTask, push SQS | **-> `references/01-cli-entry.md`** |
| **B** | Remote bulk/operation call to Shopify | **-> `references/02-handle-product-webhook.md`** |
| **C** | Poll bulk operation status, download JSONL | **-> `references/03-request-status.md`** |
| **D** | Read JSONL in batches, mark `sales_channel=true` | **-> `references/04-read-jsonl-file.md`** |
| **E** | Find unmarked products, delete from collections | **-> `references/05-get-and-remove-container-ids.md`** |
| **F** | Unset temp markers, log final stats | **-> `references/06-unset-sales-channel.md`** |

**Queue:** `shopify_product_webhook_backup_sync`
**SQS message type:** `full_class` -> `Helper::class` -> method per step

```
A(CLI: ProductWebhookBackupCommand)
    -> B(handleProductWebhook -> Remote POST /bulk/operation)
    -> C(requestStatus -> poll until COMPLETED -> download JSONL)
    -> D(readJSONLFile -> batch 1000 lines -> assignUniqueKey sales_channel=true)
    -> E(getContainerIds -> products WITHOUT sales_channel -> removeContainerIds + removeProfileData + initatetoInactive)
    -> F(unsetSalesChannel -> cleanup temp marker -> adoption_metrics log)
```

---

## Key Files

| File | Purpose |
|------|---------|
| `app/home/app/code/shopifyhome/console/ProductWebhookBackupCommand.php` | CLI command entry point (`product-webhook-backup`) |
| `app/home/app/code/shopifyhome/Components/Webhook/Helper.php` | Core processing logic (all 6 pipeline methods) |
| `app/home/app/etc/config.php` | `webhook_backup_sync_priority_users` user ID list |

---

## CLI Command

| Flag | Option Name | Purpose |
|------|-------------|---------|
| `-a` | `app_code` | **Required.** App code (e.g. `shopify`) |
| `-t` | `app_tag` | **Required.** App tag (e.g. `amazon_sales_channel`) |
| `-p` | `webhook_backup_sync_priority_users` | **Optional.** If `true`, reads user IDs from config key `webhook_backup_sync_priority_users` |

---

## When to Use Which Approach

| Scenario | Approach | Code Changes Required |
|----------|----------|-----------------------|
| **One-time run** for a single user | `getPaidClientDetails()` with hardcoded `user_id` | Temporary (revert after run) |
| **Permanent / recurring cron** for specific users | Add user IDs to `config.php` | Persistent config change |

---

## Approach 1: One-Time Run for a Single User (via `getPaidClientDetails`)

Use this when you need to run the contingency process **once** for a specific user and don't want to disturb the permanent config.

### Step 1 — Add `user_id` to `getPaidClientDetails()` query

In `ProductWebhookBackupCommand.php` (line ~86):

```php
$query = [
    'type' => 'active_plan',
    'status' => 'active',
    'plan_details.custom_price' => ['$gt' => 0],
    'user_id' => 'TARGET_USER_ID_HERE'               // <-- add this temporarily
];
```

### Step 2 — Uncomment the `elseif` block

In `initiateWebhookBackup()` (line ~121):

```php
// Change from:
// elseif (!empty($this->getPaidClientDetails())) {
//     $users = $this->getPaidClientDetails();
//     $users = array_column($users, 'user_id');
// }

// Change to:
elseif (!empty($this->getPaidClientDetails())) {
    $users = $this->getPaidClientDetails();
    $users = array_column($users, 'user_id');
}
```

### Step 3 — Run WITHOUT the `-p` flag

```bash
php app/cli product-webhook-backup -a shopify -t amazon_sales_channel
```

Without `-p`, the priority-users branch is skipped, so execution falls through to the `elseif` which calls `getPaidClientDetails()` — now filtered to your single user.

### Step 4 — Revert changes after the run

- Remove `'user_id' => '...'` from the `$query` in `getPaidClientDetails()`
- Re-comment the `elseif` block back

> **Important:** Always revert after running. Leaving `user_id` hardcoded will break the paid-users flow for future runs. This approach is strictly for one-time executions.

---

## Approach 2: Permanent Cron for Specific Users (via Config)

Use this when the contingency process should run **regularly** (e.g., via a cron job) for a fixed set of users.

### Step 1 — Add user IDs to config

In `app/home/app/etc/config.php`:

```php
'webhook_backup_sync_priority_users' => [
    '6776568e6d7e859c340e54cc',
    '6890abcd1234ef5678901234',    // add more user IDs as needed
],
```

### Step 2 — Flush cache

```bash
php /app/home/app/cli cache flush
```

### Step 3 — Run with `-p true`

```bash
php app/cli product-webhook-backup -a shopify -t amazon_sales_channel -p y
```

This iterates over **all** user IDs in the `webhook_backup_sync_priority_users` array. No code changes needed — just config + cache flush.

> **Note:** To remove a user from future cron runs, remove their ID from the config array and flush cache again.

---

## Collections Affected

| Collection | Operation |
|------------|-----------|
| `product_container` | `deleteMany` (products without sales channel), `updateMany` (set/unset `sales_channel` marker) |
| `refine_product` | `deleteMany` (matching container_ids) |
| `amazon_listing` | `updateMany` ($unset source linking fields, $set `unmap_record`) |
| `profile` | `bulkWrite` ($pullAll `manual_product_ids`) |
| `queued_tasks` | `setQueuedTask` on start, `deleteOne` on completion |
| `adoption_metrics` | `insertOne` (final stats: removed count, timestamp) |
| `payment_details` | Read-only (for `getPaidClientDetails` — currently commented out) |

---

## Orchestration (parent skill only — no reference file)

### User Selection (`initiateWebhookBackup`)

- If `-p true` flag is set: reads user IDs from `config.php` key `webhook_backup_sync_priority_users`
- For each user: `setDiForUser($userId)` -> find Shopify shop -> `prepareSqsData()` -> create QueuedTask -> push SQS message
- The commented-out `getPaidClientDetails()` queries `payment_details` for users with `custom_price > 0` (not currently active)

### SQS Message Structure (from `prepareSqsData`)

```php
[
    'type'         => 'full_class',
    'class_name'   => Helper::class,
    'method'       => 'handleProductWebhook',   // first step
    'user_id'      => $userId,
    'shop_id'      => $shopData['_id'],          // Shopify shop _id
    'remote_shop_id' => $shopData['remote_shop_id'],
    'queue_name'   => 'shopify_product_webhook_backup_sync',
    'appCode'      => 'shopify',
    'app_code'     => ['shopify' => 'shopify'],
    'app_codes'    => ['shopify'],
    'appTag'       => 'amazon_sales_channel',
    'message'      => 'Contingency Process Initiated',
    'process_code' => 'shopify_product_remove',
    'marketplace'  => 'shopify',
    'feed_id'      => $queuedTaskId              // added after QueuedTask creation
]
```

### How Steps Chain

Each step pushes a new SQS message with a different `method` value to continue the pipeline:
- `handleProductWebhook` -> pushes `method: requestStatus`
- `requestStatus` -> calls `readJSONLFile()` directly (no SQS hop)
- `readJSONLFile` -> re-queues self with `seek` offset until EOF, then calls `getContainerIds()` directly
- `getContainerIds` -> pushes self with `last_traversed_object_id` for pagination, or calls `unsetSalesChannel()` when done
- `unsetSalesChannel` -> pushes self with `unset_sales_channel_last_id` for chunked cleanup, then finalizes

### Inventory Deactivation (`initatetoInactive`)

When linked Amazon listings are found for removed products and the user has `inactivate_product` config enabled for that target shop:
- Builds inventory feed with `Quantity: 0` for each SKU
- Sends to Amazon in batches of 500 via `sendRequestToAmazon('inventory-upload', ...)`
- This zeroes out Amazon inventory for products no longer in the app

### Progress Tracking

- `QueuedTasks::updateFeedProgress()` is called at key stages:
  - Step B: 0% — "retrieving products from source catalog"
  - Step D: 0-50% — "Reading Shopify Products" (proportional to JSONL rows processed)
  - Step E: 50-95% — "Total X product(s) are getting removed" (proportional to container IDs processed)

### Logging

All steps log to: `var/log/shopify/productRemoveContingency/{user_id}/{dd-mm-YYYY}.log`

### Error Handling / Retries

- **Bulk operation already running** (Step B): re-queues with `delay: 180` (3 min)
- **Bulk status RUNNING/CREATED** (Step C): re-queues with `delay: 180`, increments `retry` counter
- **Partial data URL** (Step C): terminates process, logs as failure
- **Remote token error** (Step C): re-queues for retry
- All failures: remove QueuedTask entry + create critical notification via `updateActivityLog`
