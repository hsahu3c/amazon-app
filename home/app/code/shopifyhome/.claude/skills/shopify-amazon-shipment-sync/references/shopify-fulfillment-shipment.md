# Shopify side: fulfillment webhooks → `prepare` → mold

Paths below are under `app/home/app/code/` unless noted.

## Topic and action names

| Shopify topic | Internal `action` | Typical queue (`webhookQueue`) |
|---------------|-------------------|--------------------------------|
| `fulfillments/update` | `fulfillments_update` | `amazon_shopify_orders_fulfillments_update` |
| `fulfillments/create` | `fulfillments_create` | `amazon_shopify_orders_fulfillments_create` |

Configured in `shopifyhome/etc/config.php` (`webhookQueue` + `webhook` sections). Do not confuse with `fulfillment_create` (wrong name).

## Ingress (defer detail)

1. SQS consumer delivers payload → see `.cursor/skills/amazon-sqs-consumer/SKILL.md`.
2. `shopifyhome/Components/WebhookHandler/Hook.php` `moldWebhookData()` resolves topic → `action`, builds handler payload, calls **`connector/Models/SourceModel.php` `triggerWebhooks()`** → `initWebhook()`. See `.cursor/skills/shopify-webhook-handler-router/SKILL.md` and `shopify-webhook-handler-router/references/webhook-action-map.md`.

## Connector router branch

In `connector/Models/SourceModel.php`, `initWebhook()`:

- `case 'fulfillments_update':`
- `case 'fulfillments_create':`

Resolves `App\Shopifyhome\Components\Order\Shipment` (via `$data['marketplace']` → `Shopify` / `Shopifyhome` class name logic) and calls **`prepare($data)`**.

## `Shopifyhome\Components\Order\Shipment::prepare`

File: `shopifyhome/Components/Order/Shipment.php`.

1. Query **`order_container`** for `user_id`, `object_type` in `source_order` / `target_order`, `marketplace_shop_id` = webhook shop, `marketplace_reference_id` = `(string) $data['data']['order_id']`.
2. If no row → `['success' => false, 'message' => 'Order not found']`.
3. If no `targets` → `Targets data not found`.
4. Derives **`source_shop_id`**, **`target_shop_id`**, **`source_marketplace`**, **`target_marketplace`** depending on whether the row is `source_order` or `target_order` (reads linked order via `targets`).
5. Sets `user_id` and `object_type` from the order row.
6. Calls **`App\Connector\Components\Order\Shipment::initiateShipment($data)`** (does not return the inner result to the webhook caller; returns `Shipment Initiated` on success).

**Debug hints**

- Wrong shop or order id in webhook → order not found.
- Broken link graph in `order_container` → missing targets.

## Source marketplace mold: `Shopifyhome\Service\Shipment::mold`

File: `shopifyhome/Service/Shipment.php`.

Called from **`initiateShipment`** via `ShipInterface` for marketplace `shopify`. Expects webhook-shaped `$data` including nested `$data['data']` (Shopify fulfillment fields).

**Typical mapped fields**

- `marketplace_reference_id` ← `order_id`
- `marketplace_shipment_id` ← fulfillment `id`
- `shipping_status` ← `shipment_status`
- `shipping_method` / `tracking` from `tracking_company`, `tracking_number`, `tracking_url`
- `shipment_created_at` / `shipment_updated_at`
- `items[]` from `line_items` (sku, qty as `shipped_qty`, `variant_id` as `product_identifier`, etc.)

If `$data['data']` is missing, mold returns an empty array → orchestrator reports `"Data not found"`.

## Alternate entry (awareness only)

`shopifyhome/Models/SourceModel.php` can invoke `createQueueToFulfillOrder` on connected channels’ `Order\Hook` for **other** actions (e.g. legacy order-fulfilled style flows). The **primary** path for `fulfillments/create|update` through connector is **`connector` `SourceModel::initWebhook` → `prepare` → `initiateShipment`**.
