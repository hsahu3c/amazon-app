# Shopify Webhook Registration

Documents how Shopify webhooks are registered, including destination creation, GraphQL/EventBridge/REST webhook protocols, webhook migration from REST to GraphQL, and plan-tier address updates. Use this skill whenever someone asks about `afterProductImport`, `createDestination`, Shopify webhook registration, GraphQL webhook subscription, EventBridge webhook setup, `webhookSubscriptionCreate`, webhook migration (`validateShopGraphQLWebhooks`, `migrateRestToGraphQLWebhookSqsProcess`), plan-tier webhook address changes (`updateShopifyWebhookAddressByPlanTier`), `appReInstall` webhook re-registration, or why a Shopify webhook wasn't created. Also use when debugging `webhook_graphql_base_url_by_plan_tier` config or `register_shopify_webhook_with_eventbridge` settings.

**Primary File:** `app/home/app/code/shopifyhome/Components/UserEvent.php`

---

## Architecture Overview

Shopify webhook registration is triggered after the first product import completes. It creates a destination on `cedcommercewebapi`, then hands off to the [Common Webhook Registration](../connector-common-webhook-registration/SKILL.md) pipeline for async processing.

```
application:afterProductImport fires
  → UserEvent::afterProductImport($event, $shop)
      │
      ├── createDestination($shop)
      │     → Create SQS queue locally: {app_code}_app_delete
      │     → Build destination payload from app/etc/aws.php:
      │         - region, credentials, queue_base_url
      │         - type = 'user', event_handler = 'sqs'
      │     → ApiClient POST cedcommerce event/destination
      │     → Returns destination_id
      │
      └── SourceModel::routeRegisterWebhooks($shop, $marketplace, $appCode, $destinationId)
            → [Common Webhook Registration pipeline]
            → See ../connector-common-webhook-registration/SKILL.md
```

---

## Entry Points

### 1. afterProductImport() — Primary Entry

**File:** `app/home/app/code/shopifyhome/Components/UserEvent.php`

Triggered by the `application:afterProductImport` event after the initial Shopify product import completes. This is the standard first-time webhook registration path.

**Steps:**
1. Calls `createDestination($shop)` to get a `destination_id`
2. Calls `SourceModel::routeRegisterWebhooks()` to queue webhook registration

### 2. appReInstall() — Re-registration Entry

**File:** `app/home/app/code/shopifyhome/Components/UserEvent.php`

Called when a merchant reinstalls the app. Follows the same pattern as `afterProductImport()`: creates a destination and calls `routeRegisterWebhooks()`.

### 3. validateShopGraphQLWebhooks() — Migration Entry

**File:** `app/home/app/code/shopifyhome/Components/Webhook/Helper.php`

Detects REST webhooks that need migration to GraphQL. Deletes the old REST webhooks and re-registers them through the destination-wise flow.

---

## createDestination() Detail

**File:** `app/home/app/code/shopifyhome/Components/UserEvent.php`

Builds a destination payload for `cedcommercewebapi`:

```
createDestination($shop)
  → Read AWS config from app/etc/aws.php: region, credentials, queue_base_url
  → Create local SQS queue: {app_code}_app_delete (if not exists)
  → Build payload:
      destination_name: {app_code}_app_delete
      destination_type: sqs
      event_handler: sqs
      type: user
      arn: queue ARN
      queue_url: full SQS URL
  → POST event/destination to cedcommercewebapi
  → Returns destination_id
```

The returned `destination_id` ties all subsequent webhook subscriptions to this SQS destination.

---

## Webhook Protocols

Shopify supports multiple webhook delivery protocols. The protocol selection happens in the remote `shopifywebapi` during subscription creation:

| Protocol | Config Flag | How It Works |
|----------|------------|--------------|
| **GraphQL (HTTPS)** | Default | `webhookSubscriptionCreate` mutation with callback URL |
| **EventBridge** | `register_shopify_webhook_with_eventbridge` | `webhookSubscriptionCreate` mutation with EventBridge ARN |
| **REST** | Legacy | REST API `POST webhooks.json` with callback address |
| **Fulfillment** | Special case | `Fulfillment::createFulfillment()` for fulfillment service webhooks |

For remote-side details, see `references/remote-shopify-webhook.md`.

---

## Webhook Migration (REST → GraphQL)

**File:** `app/home/app/code/shopifyhome/Components/Webhook/Helper.php`

Two migration mechanisms exist:

### validateShopGraphQLWebhooks()

Detects shops with REST webhooks that should be GraphQL:
1. Fetches current webhooks from Shopify
2. Identifies REST webhooks registered by the app
3. Deletes them via Shopify API
4. Re-registers through the destination-wise flow (creating a new destination)

### migrateRestToGraphQLWebhookSqsProcess()

Batch migration for EventBridge GraphQL registration:
1. Processes a batch of webhooks from an SQS message
2. Calls `eventBridgeWebhookSubscriptionCreate` GraphQL mutation for each
3. Updates subscription records on success

---

## Plan-Tier Webhook Address Updates

**File:** `app/home/app/code/shopifyhome/Components/Webhook/Helper.php`

**`updateShopifyWebhookAddressByPlanTier($data)`:**

When a shop's plan tier changes, the webhook callback addresses may need updating:

1. Resolves new address from `webhook_graphql_base_url_by_plan_tier` config
2. Validates that webhook count doesn't exceed the plan's limit
3. Calls remote `update/webhookSubscriptionEventBridge` for each webhook that needs the new address
4. Updates local subscription records

**Config:** `webhook_graphql_base_url_by_plan_tier` maps plan tiers to different callback URLs, allowing traffic routing based on merchant tier.

---

## Relationship to Other Skills

| Skill | Relationship | How It's Used |
|-------|-------------|---------------|
| [Common Webhook Registration](../connector-common-webhook-registration/SKILL.md) | After `createDestination()` returns `destination_id`, calls `SourceModel::routeRegisterWebhooks()` which enters the common async pipeline | Queuing, webhook definition fetch, subscription creation, persistence |
| [SQS Consumer](../amazon-sqs-consumer/SKILL.md) | `register_webhook` queue message is consumed by the SQS consumer | Async processing of queued webhook registrations |

---

## Key Files Reference

All file paths are relative to `app/home/app/code/`.

| File | Class | Key Methods | Role |
|------|-------|-------------|------|
| `shopifyhome/Components/UserEvent.php` | `UserEvent` | `afterProductImport()`, `createDestination()`, `appReInstall()` | Entry points and destination creation |
| `shopifyhome/Components/Webhook/Helper.php` | `Helper` | `validateShopGraphQLWebhooks()`, `migrateRestToGraphQLWebhookSqsProcess()`, `updateShopifyWebhookAddressByPlanTier()` | Migration and plan-tier updates |
| `connector/Models/SourceModel.php` | `SourceModel` | `routeRegisterWebhooks()` | Hands off to common pipeline |

## Key Configs

| Config Key | Source | Purpose |
|-----------|--------|---------|
| `register_shopify_webhook_with_eventbridge` | App config | Enables EventBridge protocol instead of HTTPS callback |
| `webhook_graphql_base_url_by_plan_tier` | App config | Maps plan tiers to callback URLs |
| `graphql_base_url` | App config | Default GraphQL webhook callback base URL |
| `updated_base_webhook_url` | App config | Updated REST webhook callback base URL |

## Additional References

- [Common Webhook Registration](../connector-common-webhook-registration/SKILL.md) — common registration pipeline (`routeRegisterWebhooks` → `registerWebhooks` → `updatedRegisterWebhook`)
- **`references/remote-shopify-webhook.md`** — Shopify remote webhook creation: GraphQL mutations, EventBridge setup, REST fallback, DynamoDB credential storage
