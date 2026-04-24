# Webhook Handler & Router

Documents the two-step pipeline that receives Shopify webhooks from SQS and routes them to the correct marketplace-specific handler. Use this skill whenever someone asks about `moldWebhookData()`, how Shopify topics are resolved to actions, how `initWebhook()` routes actions, how the webhook config (`webhookQueue` / `webhook` sections) works, why a webhook isn't being processed, `SourceModel::triggerWebhooks()`, or how to add a new webhook action. Also use when debugging missing webhook processing, action resolution failures, or understanding how `{moduleHome}` / `{marketplace}` dynamic class resolution works.

---

## Architecture Overview

```
SQS Message (from Skill 1: SQS Consumer)
  │
  ▼
┌──────────────────────────────────────────────────────────┐
│  Step 1: Hook::moldWebhookData($sqsData)                 │
│    File: shopifyhome/Components/WebhookHandler/Hook.php   │
│                                                           │
│    → Validate: payload, app_tag, topic present?           │
│    → Resolve action from config (app_tag → topic → action)│
│    → Find shop by marketplace match                       │
│    → Handle special cases (payload_too_large, inventory)  │
│    → Build $handlerData                                   │
│    → Call SourceModel::triggerWebhooks($handlerData)      │
└──────────────────────┬────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────┐
│  Step 2: SourceModel::triggerWebhooks($data)             │
│    File: connector/Models/SourceModel.php                 │
│                                                           │
│    → Set arrival_time, user context                       │
│    → Call initWebhook($data)                              │
└──────────────────────┬────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────┐
│  Step 3: SourceModel::initWebhook($data)                 │
│                                                           │
│    → Resolve connected channels and app codes             │
│    → Switch on $data['action'] (~50 cases)                │
│    → Instantiate marketplace-specific handler class       │
│    → Call handler method with $data                       │
│                                                           │
│    See references/webhook-action-map.md for full mapping  │
└──────────────────────────────────────────────────────────┘
```

---

## Step 1: moldWebhookData()

**File:** `app/home/app/code/shopifyhome/Components/WebhookHandler/Hook.php`
**Class:** `App\Shopifyhome\Components\WebhookHandler\Hook`

### Input

Receives `$sqsData` from the SQS consumer (Skill 1). The consumer already set user context in DI.

```php
$sqsData = [
    'data'        => ['detail' => ['payload' => [/* Shopify webhook body */]]],
    'topic'       => 'fulfillments/create',
    'app_tag'     => 'amazon_sales_channel',
    'app_code'    => 'amazon_sales_channel',
    'marketplace' => 'shopify',
    'username'    => 'mystore.myshopify.com',
    'queue_name'  => 'amazon_shopify_orders_fulfillments_create',
    'action'      => null  // optional, resolved from config if missing
];
```

### Action Resolution

If `$sqsData['action']` is already set, uses it directly. Otherwise:

1. Load webhook config: `$this->di->getConfig()->webhook->get($appTag)->toArray()`
2. Iterate config entries to find matching `topic`
3. Set `$action = $webhookData['action']`

**Config location:** `app/home/app/code/shopifyhome/etc/config.php` → `webhook` section

Example: topic `fulfillments/create` under `amazon_sales_channel` → action `fulfillments_create`

### Shop Resolution

Iterates `$this->di->getUser()->shops` and finds the first shop where `$shop['marketplace'] == $sqsData['marketplace']`.

### Special Cases

| Condition | Handling |
|-----------|---------|
| `payload_too_large` error code | Uses `X-Shopify-Product-Id` from metadata → `intitateBatchImport()` (checks plan limits, variant count > 100 handling) |
| `inventory_levels_update` action | Bypasses SourceModel — calls `Inventory\Hook::processInventoryLevelsUpdateWebhook()` directly |

### handlerData Construction

For all other actions, builds `$handlerData` and dispatches to `SourceModel::triggerWebhooks()`:

```php
$handlerData = [
    'shop'        => $sqsData['username'],
    'data'        => $sqsData['data']['detail']['payload'],
    'type'        => 'full_class',
    'class_name'  => 'App\\Connector\\Models\\SourceModel',
    'method'      => 'triggerWebhooks',
    'user_id'     => $userId,
    'appCode'     => $appCode,
    'app_code'    => $appCode,
    'appTag'      => $appTag,
    'shop_id'     => $shopId,
    'action'      => $action,
    'queue_name'  => $sqsData['queue_name'] ?? $queuePrefix . '_' . $action,
    'marketplace' => $sqsData['marketplace']
];
```

Note: `data` is the raw Shopify payload (unwrapped from the EventBridge envelope).

---

## Step 2: triggerWebhooks()

**File:** `app/home/app/code/connector/Models/SourceModel.php`
**Method:** `triggerWebhooks($data)` (line ~1881)

1. Sets `$data['arrival_time'] = date('c')`
2. Returns `true` early if `user_id` is missing
3. Sets user token via `Helper::setUserToken($data['user_id'])`
4. Calls `$this->initWebhook($data)`
5. If `initWebhook` returns `2`, returns `2` (triggers SQS retry in Skill 1)
6. Returns `true` on success

---

## Step 3: initWebhook() — Action Routing

**File:** `app/home/app/code/connector/Models/SourceModel.php`
**Method:** `initWebhook($data)` (line ~1906)

### Pre-routing Setup

Before the switch statement:

```php
$actionToPerform = $data['action'];
$getConnectedChannels = $this->getSellerConnectedChannels($data);
$data['app_codes'] = $this->getAppCodesForSimilarWebhook(...);
$connectedMarketplaces = $this->getConnectedMarketplaces(...);
```

### Dynamic Class Resolution

Many cases use dynamic class names based on the marketplace:

| Variable | Pattern | Example |
|----------|---------|---------|
| `{moduleHome}` | `ucfirst($data['marketplace']) . "home"` | `Shopifyhome` |
| `{marketplace}` | `ucfirst($data['marketplace'])` | `Shopify` or `Amazon` |
| `{sourceModule}` | From connected channels source marketplace | `Shopifyhome` |

### Key Action Groups

| Group | Actions | Target |
|-------|---------|--------|
| **Orders** | `orders_create`, `orders_cancelled`, `refunds_create`, `returns_approve`, `returns_decline` | Order components |
| **Products** | `product_create`, `product_update`, `product_delete`, `product_listings_add/update/remove` | Product hooks |
| **Inventory** | `inventory_levels_update`, `inventory_levels_connect`, `inventory_levels_disconnect` | Inventory hooks |
| **Fulfillments** | `fulfillments_create`, `fulfillments_update` | Shipment prepare |
| **Locations** | `locations_create`, `locations_update`, `locations_delete` | Location hooks |
| **Shop Lifecycle** | `app_delete`, `shop_update`, `shop_eraser`, `shop_redact` | Hook / ShopDelete |
| **Amazon Notifications** | `ORDER_CHANGE`, `FEED_PROCESSING_FINISHED`, `LISTINGS_ITEM_*`, `REPORT_PROCESSING_FINISHED` | Amazon helpers |
| **Internal Serverless** | `amazon_product_upload/update`, `amazon_inventory/price/image_sync`, `amazon_shipment_sync` | serverlessResponse |

For the complete mapping of all ~50 actions, see `references/webhook-action-map.md`.

---

## Webhook Config Structure

**File:** `app/home/app/code/shopifyhome/etc/config.php`

### `webhookQueue` Section

Maps Shopify topics to SQS queue names. Used by the webhook registration system to determine which queue each topic should route to.

| Topic | Queue Name |
|-------|-----------|
| `fulfillments/create` | `amazon_shopify_orders_fulfillments_create` |
| `fulfillments/update` | `amazon_shopify_orders_fulfillments_update` |
| `app/uninstalled` | `amazon_webhook_app_delete` |
| `shop/update` | `amazon_shopify_shop_update` |
| `orders/create` | `amazon_shopify_orders_create` |
| `orders/cancelled` | `amazon_shopify_orders_cancel` |
| ... | (~25 total mappings) |

### `webhook` Section

Maps `app_tag` → array of `{ topic, action, marketplace }`. Used by `moldWebhookData()` to resolve actions.

**Key app_tags:**
- `amazon_sales_channel` — Primary Shopify-Amazon integration (~16 webhook types)
- `default` (facebook) — Facebook integration (~12 webhook types)
- `shopify_hubspot` — HubSpot integration (~11 webhook types)

Example entry:
```php
'amazon_sales_channel' => [
    ['topic' => 'fulfillments/create', 'action' => 'fulfillments_create', 'marketplace' => 'amazon'],
    ['topic' => 'app/uninstalled',     'action' => 'app_delete',          'marketplace' => 'shopify'],
    // ...
]
```

Note: Some config actions (e.g., `order_fulfilled`, `checkouts_create`, `customers_create`) have no matching case in `initWebhook()` — they fall through to the default branch and return `true` without specific handling.

---

## Key Files Reference

All file paths are relative to `app/home/app/code/`.

| File | Class | Key Methods | Role |
|------|-------|-------------|------|
| `shopifyhome/Components/WebhookHandler/Hook.php` | `Hook` | `moldWebhookData()`, `intitateBatchImport()` | Topic resolution, shop lookup, dispatch |
| `connector/Models/SourceModel.php` | `SourceModel` | `triggerWebhooks()`, `initWebhook()` | User context, action routing (~50 cases) |
| `shopifyhome/etc/config.php` | — | — | `webhookQueue` and `webhook` config sections |

## Error Logging

| Log Path | Condition |
|----------|-----------|
| `shopify/webhookHandler/error/{dd-mm-YYYY}.log` | Missing payload/app_tag/topic, action not found, shop not found, batch import errors |

## Additional References

- **`references/webhook-action-map.md`** — Complete table of all ~50 `initWebhook()` switch cases with class, method, and description
