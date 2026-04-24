# Store close CRON / CLI cleanup

Use this reference for **delayed** uninstall-style cleanup and related maintenance scripts—not the immediate `shop/update` webhook path.

---

## StoreClosedUsersCommand

**File:** `app/home/app/code/shopifyhome/console/StoreClosedUsersCommand.php`

**CLI:** `php cli remove:store-closed_users`  
**Option:** `-m` / `--method` — if omitted, runs `globalDataDeletionHandling()`; if set to an existing method name on the command, that method runs (e.g. `customDataDeletionHandling`).

### globalDataDeletionHandling()

- **Window:** `store_closed_at <= 6 months ago` (ISO datetime string).
- **Match:** `shops` elemMatch: `marketplace` = `shopify`, `plan_name` in `['cancelled','frozen','fraudulent']`, `apps.app_status` = `active`, and `store_closed_at` within window.
- **Action:** `processUserData()` — for each user, one Shopify shop per user: `Sqs::pushMessage` with:
  - `class_name`: `\App\Shopifyhome\Components\Shop\StoreClose`
  - `method`: `initiateProcess`
  - `queue_name`: `shopify_store_close_users`
  - `shop`, `data` (shop doc), `user_id`, `app_code`, etc.

### customDataDeletionHandling()

Stricter schedules for subsets of users:

- **Single shop** (`shops.1` does not exist): `store_closed_at <= 7 days ago`, same plan/app filters.
- **Multi shop** with `plan_tier` = `default` or missing: `store_closed_at <= 1 month ago`.

Same SQS payload pattern as global handling.

---

## StoreClose class

**File:** `app/home/app/code/shopifyhome/Components/Shop/StoreClose.php`  
**Extends:** `App\Connector\Components\Hook`

### initiateProcess($data)

1. Log: `shopify/storeClose/{d-m-Y}.log`.
2. **`checkIsShopActive`:** GET `/shop` via ApiClient. If active and data returned → call `Hook::shopUpdate($preparedData)` and `checkAndRegisterShopUpdateWebhook` → **return** (no uninstall).
3. **`getToken($shop)`:** `remote_db` collection `apps_shop` by `shop_url`.
4. If token found: **upsert** `uninstall_user_details` with `user_id`, `username`, `email`, `store_closed_at`, `remote_data` (token + `remote_shop_id`).
5. **`startUninstallationProcess($data)`**.

### checkIsShopActive($userId, $shop)

Private. Same `/shop` GET; on success builds payload for `Hook::shopUpdate()`.

### checkAndRegisterShopUpdateWebhook($shop)

If `shop_update` is missing from `apps[0].webhooks`: `UserEvent::createDestination`, then `SourceModel::routeRegisterWebhooks(..., $destinationWise)` with event `shop_update`.

### startUninstallationProcess($data)

Pushes to **`app_delete`** queue:

- `class_name`: `StoreClose`
- `method`: `TemporarlyUninstall`
- `action`: `app_delete`

### TemporarlyUninstall($data, ...)

Override of connector `Hook::TemporarlyUninstall` behavior:

- Resolves shop from `user_details` (supports disconnect-shaped payloads or plain `shop_id` / `app_code`).
- Fetches `/app` config unless `force_delete`.
- **`isAppActive`:** if marketplace `ShopDelete::isAppActive` returns success → **return early** (no uninstall).
- Full uninstall branch: `setUninstallDateInApp`, `setStatusInSourcesAndTargets`, `uninstallUserDetails`, **`application:appUninstall` is commented out** (no plan deactivation event on this path).
- `sendDataToMarketplace` for targets and source marketplace.
- `force_delete` → immediate `appDelete()`.

---

## ShopifyScriptsCommand (related)

**File:** `app/home/app/code/shopifyhome/console/ShopifyScriptsCommand.php`  
**CLI:** `php cli shopify-scripts -m <methodName>`

| Method | Queue | Handler | Effect |
|--------|--------|---------|--------|
| `setStoreClosedAtForUnavailableShops` | `shopify_check_unavailable_shop_users` | `Shop::checkAndSetStoreClosedAtForUnavailableShop` | Marks unavailable shops closed (`store_closed_at`, `plan_name` cancelled) when API indicates unavailable |
| `removeStoreClosedAtKeyForDormantUsers` | (inline) | — | Unsets `store_closed_at` for `plan_name` = `dormant`; if multi-shop, fires `application:shopStatusUpdate` with `reopened` |
| `checkAndUninstallTokenExpiredUsers` | `shopify_check_and_uninstall_token_expired_users` | `Shop::checkAndUninstallTokenExpiredUsers` | Invalid token can call `StoreClose::startUninstallationProcess` |

**Note:** Cron is typically **external**; this repo defines CLIs and SQS producers, not the crontab.

---

## RemoveStaleUsersCommand (related)

**File:** `app/home/app/code/shopifyhome/console/RemoveStaleUsersCommand.php`  
**CLI:** e.g. `remove-users:stale` with action `closed-store` (per product usage).

Populates Mongo **`users_to_remove`** with `type` = `store_closed` and batched `user_ids` for long-closed Shopify cancelled stores—feeds downstream cleanup flows.
