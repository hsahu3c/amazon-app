# Step A — CLI Entry (`ProductWebhookBackupCommand`)

**File:** `app/home/app/code/shopifyhome/console/ProductWebhookBackupCommand.php`

## Command Registration

```
defaultName: "product-webhook-backup"
defaultDescription: "Product Syncing Backup Command"
```

**Constants:**
- `QUEUE_NAME = 'shopify_product_webhook_backup_sync'`
- `MAX_MESSAGE_COUNT = 10`
- `USER_COLLECTION = 'user_details'`

## Options

| Flag | Name | Required | Description |
|------|------|----------|-------------|
| `-a` | `app_code` | Yes | App code (`shopify`) |
| `-t` | `app_tag` | Yes | App tag (`amazon_sales_channel`) |
| `-p` | `webhook_backup_sync_priority_users` | No | If `true`, use priority user list from config |

## Execution Flow

### `execute()` (line 60)
1. Validates `-a` and `-t` flags are present
2. Calls `initiateWebhookBackup()`

### `initiateWebhookBackup()` (line 109)
1. If `-p` flag set: reads user IDs from `config.php` -> `webhook_backup_sync_priority_users` array
2. For each user ID:
   - `setDiForUser($userId)` — loads user into DI container
   - Finds the user's Shopify shop (first shop with `marketplace == 'shopify'`)
   - `prepareSqsData($userId, $shopData)` — builds SQS payload
   - Creates `QueuedTask` via `$queuedTask->setQueuedTask($sourceShopId, $sqsData)`
   - Pushes SQS message with `feed_id` = queuedTaskId

### `setDiForUser($userId)` (line 198)
- Finds user by `_id` in MongoDB
- Sets user in DI: `$this->di->setUser($getUser)`
- **Guard:** rejects if fetched user is `admin` (fallback DI user)

### `prepareSqsData($userId, $shopData)` (line 172)
Returns the initial SQS message that triggers the first pipeline step (`handleProductWebhook`).

### `getPaidClientDetails()` (line 82)
Queries `payment_details` for active plans with `custom_price > 0`. This code path is **commented out** in `initiateWebhookBackup()` but can be used to target a single user (see below).

```php
private function getPaidClientDetails()
{
    $query = [
        'type' => 'active_plan',
        'status' => 'active',
        'plan_details.custom_price' => ['$gt' => 0]
    ];
    // ...
    return $paymentDetails->find($query, $options)->toArray();
}
```

## Config Reference

In `app/home/app/etc/config.php`:
```php
'webhook_backup_sync_priority_users' => [
    '6776568e6d7e859c340e54cc'   // add user IDs here
],
```

---

## When to Use Which Approach

| Scenario | Approach | Code Changes | Command |
|----------|----------|--------------|---------|
| **One-time run** for a single user | `getPaidClientDetails()` with temp `user_id` | Temporary (revert after) | `... -a shopify -t amazon_sales_channel` (no `-p`) |
| **Permanent / recurring cron** for specific users | Config array | Persistent config + cache flush | `... -a shopify -t amazon_sales_channel -p y` |

---

## Approach 1: One-Time Run for a Single User (via `getPaidClientDetails`)

Use this when you need to run the contingency **once** for a specific user without touching the permanent config.

### Step 1 — Add `user_id` to `getPaidClientDetails()` query (line ~86)

```php
$query = [
    'type' => 'active_plan',
    'status' => 'active',
    'plan_details.custom_price' => ['$gt' => 0],
    'user_id' => 'TARGET_USER_ID_HERE'               // <-- add this temporarily
];
```

### Step 2 — Uncomment the `elseif` block in `initiateWebhookBackup()` (line ~121)

```php
// FROM (commented):
// elseif (!empty($this->getPaidClientDetails())) {
//     $users = $this->getPaidClientDetails();
//     $users = array_column($users, 'user_id');
// }

// TO (uncommented):
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

> **Important:** Always revert after running. This approach is strictly for one-time executions. Leaving `user_id` hardcoded will break future paid-users runs.

---

## Approach 2: Permanent Cron for Specific Users (via Config)

Use this when the contingency should run **regularly** (e.g., via a cron job) for a fixed set of users.

### Step 1 — Add user IDs to config

In `app/home/app/etc/config.php`:
```php
'webhook_backup_sync_priority_users' => [
    '6776568e6d7e859c340e54cc',
    '6890abcd1234ef5678901234',    // add more as needed
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

This iterates over **all** user IDs in the config array. No code changes needed.

> **Note:** To remove a user from future cron runs, remove their ID from the config array and flush cache again.
