---
name: shopify-amazon-shipment-sync
description: End-to-end Shopify fulfillment webhook (fulfillments/create|update) to Amazon shipment confirmation—queues, fulfillments_create|fulfillments_update, order_container prepare, initiateShipment, ShipmentV2, Amazon Service Shipment. Use whenever someone asks about tracking sync Shopify→Amazon, fulfillment webhooks not updating Amazon, fulfillments_update vs create, amazon_shopify_orders_fulfillments_* queues, or debugging shipment after SQS—also for partial fulfillments and carrier mapping on Amazon. Always pair with amazon-sqs-consumer and shopify-webhook-handler-router for ingress; use connector-shipment-orchestration for ShipInterface and ShipmentV2 internals.
---

# Shopify–Amazon shipment sync

Documents the **primary** path: Shopify **`fulfillments/create`** and **`fulfillments/update`** webhooks → SQS → webhook handler → **connector** `SourceModel::initWebhook` → **`Shopifyhome\Components\Order\Shipment::prepare`** → **`Connector\Components\Order\Shipment::initiateShipment`** → **`App\Connector\Service\ShipmentV2::ship`** → **`App\Amazon\Service\Shipment::ship`**.

Use the references for file-level detail:

- [`references/shopify-fulfillment-shipment.md`](references/shopify-fulfillment-shipment.md) — topics, queues, `prepare`, Shopify `mold`.
- [`references/amazon-shipment-from-connector.md`](references/amazon-shipment-from-connector.md) — Amazon `ship`, serverless vs confirm, settings.

---

## Prerequisites (reuse these skills)

| Layer | Skill |
|--------|--------|
| SQS → PHP worker | [amazon-sqs-consumer](../amazon-sqs-consumer/SKILL.md) · `references/sqs_message_polling.md` |
| `moldWebhookData`, topic → `action`, `triggerWebhooks` / `initWebhook` | [shopify-webhook-handler-router](../shopify-webhook-handler-router/SKILL.md) · `references/webhook-action-map.md` |
| `initiateShipment`, `ShipmentV2`, `ShipInterface` | [connector-shipment-orchestration](../connector-shipment-orchestration/SKILL.md) |

Do **not** duplicate those pipelines here.

---

## Naming

| Shopify topic | `action` in code |
|---------------|------------------|
| `fulfillments/update` | `fulfillments_update` |
| `fulfillments/create` | `fulfillments_create` |

Not `fulfillment_create` / `fulfillment_update`.

---

## Queues (Amazon–Shopify app)

From `shopifyhome/etc/config.php` (`webhookQueue`):

- `fulfillments/update` → queue `amazon_shopify_orders_fulfillments_update`
- `fulfillments/create` → queue `amazon_shopify_orders_fulfillments_create`

---

## Thin end-to-end spine

```
SQS payload
  → shopifyhome/Components/WebhookHandler/Hook.php::moldWebhookData()
  → connector/Models/SourceModel.php::triggerWebhooks() → initWebhook()
  → case fulfillments_update | fulfillments_create
  → shopifyhome/Components/Order/Shipment.php::prepare()
        → order_container lookup + source/target resolution
        → connector/Components/Order/Shipment.php::initiateShipment()
              → shopify ShipInterface::mold()  (shopifyhome/Service/Shipment.php)
              → App\Connector\Service\ShipmentV2::ship()
              → amazon ShipInterface::ship()   (amazon/Service/Shipment.php)
```

---

## Symptom → where to look

| Symptom | Likely location |
|---------|-----------------|
| Webhook never hits connector | [shopify-webhook-handler-router](../shopify-webhook-handler-router/SKILL.md), queue binding, `action` resolution |
| `Order not found` / no sync | `prepare()` query on `order_container` — wrong `marketplace_shop_id` or `order_id` |
| `Data not found` / empty mold | `shopifyhome/Service/Shipment.php::mold()` — missing `data` payload |
| Connector DB / duplicate fulfillment | `ShipmentV2` — same tracking, stale `source_updated_at`, deadlock guard |
| Amazon rejects / feed path | `amazon/Service/Shipment.php` — settings, carrier codes, serverless vs confirm |
| Status not updating on source order | `ShipmentV2::setSourceStatus` / Amazon failure handlers |

---

## Out of scope for this skill family

- Deep documentation of **`App\Connector\Service\Shipment`** (legacy default branch), **`ShipmentTransaction`** (beta-user branch in `initiateShipment`), or other alternate paths—**connector middle layer documented here is `ShipmentV2` only** per project standard.
- Full SP-API / remote service contracts—follow code in `Amazon\Components\Common\Helper` and related clients.
