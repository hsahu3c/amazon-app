# Store Close Process

Documents how Shopify store close and reopen is detected, how connected Amazon targets react, and how long-closed stores are queued for uninstall-style cleanup. Use this skill whenever someone asks about `store_closed_at`, `store_reopened_at`, `shop/update`, `Hook::shopUpdate`, `remove:store-closed_users`, `StoreClose::initiateProcess`, `application:shopStatusUpdate`, frozen/cancelled/fraudulent Shopify plans, `sendStoreCloseMail`, `checkStoreStatus`, or why Amazon webhooks were unregistered when a store closed. Also use when debugging `shopify_store_close_users` or `StoreClose::TemporarlyUninstall` (which bypasses `application:appUninstall`).

**Primary files:** `app/home/app/code/shopifyhome/Components/Shop/Hook.php`, `app/home/app/code/shopifyhome/Components/Shop/StoreClose.php`

---

## Architecture Overview

Two phases: **real-time** detection from Shopify (webhook / API) and **delayed** data deletion after months of closure.

### Phase 1: Real-time close / reopen

```
Shopify shop/update webhook
  → SQS (prefixed queue name, e.g. {app_code}_shop_update)
  → Webhook handler routes action shop_update
  → SourceModel::initWebhook() case 'shop_update'
  → Hook::shopUpdate($data)
```

**Store closed** (plan moves into `cancelled`, `frozen`, or `fraudulent`):

- Set `store_closed_at` on the shop document (via `User\Details::addShop` with webhook payload).
- Unset `store_reopened_at` if present.
- `sendStoreCloseMail()` — “App Services Suspended”.
- `notifyTargets(..., 'closed')` → fires `application:shopStatusUpdate` for multi-shop users.

**Store reopened** (plan leaves those states while stored shop was in a close plan):

- Set `store_reopened_at`, unset `store_closed_at` if needed.
- `validateShopGraphQLWebhooks()`, `fetchAndSavePublicationId()`, `checkAccessScopeAndSetReauthIfMissing()`.
- `notifyTargets(..., 'reopened')` → `application:shopStatusUpdate`.

Amazon listens on `application:shopStatusUpdate` and runs `ShopEvent::shopStatusUpdate()` — see `references/amazon-shop-status-update.md`.

### Phase 2: Long-closed store cleanup (CRON / CLI)

```
php cli remove:store-closed_users
  → StoreClosedUsersCommand
  → MongoDB: plan_name in [cancelled, frozen, fraudulent], app active, store_closed_at ≤ 6 months ago
  → SQS: StoreClose::initiateProcess, queue shopify_store_close_users
        → If shop still active: revert via Hook::shopUpdate + ensure shop_update webhook
        → Else: backup uninstall_user_details, token from remote apps_shop
        → SQS: StoreClose::TemporarlyUninstall on app_delete queue
              → Same uninstall mechanics as connector Hook but WITHOUT firing application:appUninstall
```

Details: `references/store-close-cron-cleanup.md`.

---

## Relationship to Other Skills

| Skill | Relationship | How It's Used |
|-------|-------------|---------------|
| [SQS Consumer](../amazon-sqs-consumer/SKILL.md) | `shop_update`, `shopify_store_close_users`, and `app_delete` messages are consumed by workers | Async entry for webhook replay and store-close uninstall |
| [Webhook Handler & Router](../shopify-webhook-handler-router/SKILL.md) | `shop_update` routes to `Hook::shopUpdate()` | Webhook routing |
| [Common Uninstall Process](../connector-common-uninstall-process/SKILL.md) | `StoreClose::TemporarlyUninstall()` mirrors connector uninstall flow for long-closed stores | Deferred uninstall after threshold |
| [Shopify Webhook Registration](../shopify-webhook-registration/SKILL.md) | Reopen path calls `validateShopGraphQLWebhooks()`; `StoreClose::checkAndRegisterShopUpdateWebhook()` may register `shop_update` | Recovery after reopen or revert |
| [Amazon Notification Registration](../amazon-notification-registration/SKILL.md) | Reopen path uses `Notification\Helper::createSubscription()` from Amazon `shopStatusUpdate` | Re-subscribe after reopen |
| [Common Webhook Registration](../connector-common-webhook-registration/SKILL.md) | `routeUnregisterWebhooks` on close; `routeRegisterWebhooks` when (re)registering `shop_update` | Webhook lifecycle |

---

## Key Files Reference

All paths relative to `app/home/app/code/`.

| File | Role |
|------|------|
| `shopifyhome/Components/Shop/Hook.php` | `shopUpdate()`, `notifyTargets()`, `checkStoreStatus()`, `sendStoreCloseMail()` |
| `shopifyhome/Models/SourceModel.php` | `initWebhook()` case `shop_update` (~1317) |
| `shopifyhome/Components/Shop/StoreClose.php` | `initiateProcess()`, `startUninstallationProcess()`, `TemporarlyUninstall()` override |
| `shopifyhome/console/StoreClosedUsersCommand.php` | CLI `remove:store-closed_users` |
| `shopifyhome/console/ShopifyScriptsCommand.php` | Related scripts: unavailable shop, dormant, token expiry (see reference) |
| `amazon/Components/ShopEvent.php` | `shopStatusUpdate()` |
| `amazon/etc/config.php` | Binds `application:shopStatusUpdate` → `ShopEvent` |

---

## SQS Queues

Prefix = `app_code` from `etc/config.php` (e.g. `dev_amazonbyced_` + logical name).

| Logical name | Typical role |
|--------------|----------------|
| `shop_update` | Incoming `shop/update` webhook; also used when `checkStoreStatus` re-queues `Hook::shopUpdate` |
| `shopify_store_close_users` | `StoreClose::initiateProcess` from `StoreClosedUsersCommand` |
| `app_delete` | `StoreClose::TemporarlyUninstall` after initiateProcess |
| `shopify_check_unavailable_shop_users` | `Shop::checkAndSetStoreClosedAtForUnavailableShop` |
| `shopify_check_and_uninstall_token_expired_users` | Token check → may call `StoreClose::startUninstallationProcess` |

---

## MongoDB Collections

| Collection | Role |
|------------|------|
| `user_details` | `shops.$.store_closed_at`, `store_reopened_at`, `plan_name`, `reauth_required`, etc. |
| `uninstall_user_details` | Upsert before uninstall pipeline in `StoreClose::initiateProcess` |

---

## Events

| Event | When |
|-------|------|
| `application:shopStatusUpdate` | After close or reopen when `notifyTargets` runs (multi-shop), or from dormant-user script |

---

## Additional References

- **`references/hook-shop-update.md`** — `Hook::shopUpdate`, email, `notifyTargets`, `checkStoreStatus`
- **`references/store-close-cron-cleanup.md`** — `StoreClosedUsersCommand`, `StoreClose` class, related `ShopifyScriptsCommand` methods
- **`references/amazon-shop-status-update.md`** — `ShopEvent::shopStatusUpdate` for `closed` / `reopened`
