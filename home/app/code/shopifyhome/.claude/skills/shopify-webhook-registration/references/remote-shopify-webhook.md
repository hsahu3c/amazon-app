# Remote Shopify Webhook Registration (shopifywebapi)

Details the remote-side Shopify webhook creation flow in `shopifywebapi`. This is called when `cedcommercewebapi` dispatches via `marketplacesCall('create/subscription')` to the Shopify marketplace module.

---

## Call Chain Overview

```
cedcommercewebapi/Components/EventSubscription::createSubscriptionOnMarketplace()
  → Base::marketplacesCall('create/subscription', $data)
      → shopifywebapi/Api/Notification::createSubscription()
          │
          ├── Writes app credentials to DynamoDB `apps` table
          │
          ├── Protocol routing:
          │     │
          │     ├── GraphQL (HTTPS):
          │     │     → createSubscriptionQL()
          │     │         → webhookSubscriptionCreate mutation
          │     │         → Callback URI from config
          │     │
          │     ├── EventBridge:
          │     │     → createSubscriptionEventBridge()
          │     │         → webhookSubscriptionCreate mutation
          │     │         → ARN from webhook_graphql_base_url_by_plan_tier
          │     │           or graphql_base_url
          │     │
          │     ├── REST:
          │     │     → Webhook::createWebhook()
          │     │         → REST POST webhooks.json
          │     │         → Address from updated_base_webhook_url
          │     │
          │     └── Fulfillment:
          │           → Fulfillment::createFulfillment()
          │           → Special fulfillment service webhook
          │
          └── Returns subscription data (marketplace webhook id)
```

---

## Key Classes

### shopifywebapi/Api/Notification.php

**File:** `app/remote/app/code/shopifywebapi/Api/Notification.php`

**`createSubscription($data)`** — Main router for Shopify webhook creation:

1. **DynamoDB credentials:** Writes app credentials (`access_token`, `api_key`, `api_secret_key`) to the `apps` DynamoDB table for the shop
2. **Protocol routing:** Based on webhook config and app settings, routes to one of:
   - `createSubscriptionQL()` — GraphQL HTTPS webhooks
   - `createSubscriptionEventBridge()` — EventBridge webhooks
   - `Webhook::createWebhook()` — REST webhooks
   - `Fulfillment::createFulfillment()` — Fulfillment service webhooks

**`createSubscriptionQL($data)`:**
- Builds `webhookSubscriptionCreate` GraphQL mutation
- Sets callback URL from webhook config
- Returns Shopify webhook subscription ID

**`createSubscriptionEventBridge($data)`:**
- Builds `webhookSubscriptionCreate` GraphQL mutation
- URI comes from `webhook_graphql_base_url_by_plan_tier` (if plan tier set) or `graphql_base_url`
- Supports EventBridge ARN as the delivery target

**`updateSubscriptionEventBridge($data)`:**
- Used for plan-tier address changes
- Builds `webhookSubscriptionUpdate` GraphQL mutation
- Updates the webhook's callback endpoint to the new plan-tier URL

### shopifywebapi/Api/Webhook.php

**File:** `app/remote/app/code/shopifywebapi/Api/Webhook.php`

**`register($data)`:**
- Creates SQS queue for the webhook
- Stores webhook config in DynamoDB `webhook_config` table
- Builds webhook address from `base_webhook_url`
- Calls `create()` for the actual REST API registration

**`create($data)`:**
- REST API call: `POST admin/api/{version}/webhooks.json`
- Payload includes: `topic`, `address`, `format`
- Returns webhook ID from Shopify

---

## Protocol Selection Logic

The protocol is determined by a combination of webhook config and app-level settings:

1. **EventBridge:** If `register_shopify_webhook_with_eventbridge` is enabled in app config and the webhook supports it
2. **Fulfillment:** If the webhook's `call_type` is `fulfillment`
3. **GraphQL:** If the webhook's `call_type` is `graphql` (default for most webhooks)
4. **REST:** Fallback for legacy webhooks or when explicitly configured

---

## DynamoDB Tables

| Table | Operation | Purpose |
|-------|-----------|---------|
| `apps` | PutItem | Stores Shopify app credentials (access_token, api_key, api_secret_key) per shop |
| `webhook_config` | PutItem | Stores webhook subscription config for fast lookup by consumers |

## Key Configs

| Config Key | Purpose |
|-----------|---------|
| `graphql_base_url` | Default GraphQL webhook callback base URL |
| `updated_base_webhook_url` | REST webhook callback base URL |
| `register_shopify_webhook_with_eventbridge` | Flag to enable EventBridge protocol |
| `webhook_graphql_base_url_by_plan_tier` | Maps plan tiers to different callback URLs for load distribution |
| `base_webhook_url` | Legacy REST webhook base URL |
