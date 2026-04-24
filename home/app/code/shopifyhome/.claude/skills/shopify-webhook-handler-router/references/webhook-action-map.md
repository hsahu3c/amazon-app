# Webhook Action Map — initWebhook() Complete Routing

Complete reference for all switch cases in `SourceModel::initWebhook()`.

**File:** `app/home/app/code/connector/Models/SourceModel.php` (line ~1906)

---

## Dynamic Class Variables

Many actions use dynamically resolved class names:

| Variable | Resolution | Example |
|----------|-----------|---------|
| `{moduleHome}` | `ucfirst($marketplace) . "home"` | `Shopifyhome`, `Amazonhome` |
| `{marketplace}` | `ucfirst($data['marketplace'])` | `Shopify`, `Amazon` |
| `{sourceModule}` | From connected channels, source marketplace module | `Shopifyhome` |

---

## Order Actions

| Action | Target Class | Method | Description |
|--------|-------------|--------|-------------|
| `orders_create` | `Connector\Components\Order\Order` | `createWebhook($data)` | New order from webhook |
| `orders_cancelled` | `Connector\Components\Order\OrderCancel` | `cancel($data)` | Order cancellation |
| `refunds_create` | `Connector\Components\Order\OrderRefund` | `refund($data)` | Order refund |
| `returns_approve` | `Connector\Components\Order\OrderReturn` | `return("accept", $data)` | Return approved |
| `returns_decline` | `Connector\Components\Order\OrderReturn` | `return("reject", $data)` | Return declined |
| `order_create` | `{moduleHome}\Components\Order\Hook` | `createOrderOnSource($data)` + `createOrder()` | Order create with source routing |
| `salesorder_add_edit` | `{sourceModule}\Components\Order\Shipment` or `CancelOrder` | `prepare($data)` or `prepareCancel($data)` | Sales order add/edit (multi-action) |

## Product Actions

| Action | Target Class | Method | Description |
|--------|-------------|--------|-------------|
| `product_create` | `Connector\Components\Product\Hook` then `{moduleHome}\Components\Product\Hook` | `productCreateOrUpdate($data)` then `createQueueToCreateProduct($data)` | Product created |
| `product_update` | Same as above | `productCreateOrUpdate($data)` then `createQueueToUpdateProduct($data)` | Product updated |
| `product_delete` | `Connector\Components\Product\Hook` then `{moduleHome}\Components\Product\Hook` | `productDelete($data)` then `createQueueToDeleteProduct($data)` | Product deleted |
| `product_listings_add` | `{moduleHome}\Components\Product\Hook` | `createQueueToCreateProduct($data)` | Sales channel listing added |
| `product_listings_update` | `{moduleHome}\Components\Product\Hook` | `createQueueToCreateProduct($data)` + `updatedDataOfWebhook()` | Sales channel listing updated |
| `product_listings_remove` | `{moduleHome}\Components\Product\Hook` | `removeProductWebhook($data)` | Sales channel listing removed |

## Inventory Actions

| Action | Target Class | Method | Description |
|--------|-------------|--------|-------------|
| `inventory_levels_update` | `{moduleHome}\Components\Product\Inventory\Hook` | `createQueueToupdateProductInventory($data)` | Inventory level changed |
| `inventory_levels_connect` | `{moduleHome}\Components\Product\Inventory\Hook` | `createQueueToupdateProductInventory($data)` | Inventory location connected |
| `inventory_levels_disconnect` | `{moduleHome}\Components\Product\Inventory\Hook` | `createQueueToDisconnectProductInventory($data)` | Inventory location disconnected |
| `inventory_adjustment_add_edit` | `{sourceModule}\Components\Product\Inventory\Hook` | `createQueueToupdateProductInventory($data)` | Inventory adjustment (non-Shopify) |

## Fulfillment / Shipment Actions

| Action | Target Class | Method | Description |
|--------|-------------|--------|-------------|
| `fulfillments_create` | `{marketplace}\Components\Order\Shipment` or `{marketplace}home\Components\Order\Shipment` | `prepare($data)` | Fulfillment created — triggers shipment sync |
| `fulfillments_update` | Same as above | `prepare($data)` | Fulfillment updated — triggers shipment sync |

Class resolution tries `\App\{Marketplace}\Components\Order\Shipment` first, falls back to `\App\{Marketplace}home\Components\Order\Shipment`.

## Location Actions

| Action | Target Class | Method | Description |
|--------|-------------|--------|-------------|
| `locations_create` | `{moduleHome}\Components\Location\Hook` | `createQueueLocationWebhook($data)` | Location created |
| `locations_update` | `{moduleHome}\Components\Location\Hook` | `updateQueueLocationWebhook($data)` | Location updated |
| `locations_delete` | `{moduleHome}\Components\Location\Hook` | `deleteQueueLocationWebhook($data)` | Location deleted |

## Shop Lifecycle Actions

| Action | Target Class | Method | Description |
|--------|-------------|--------|-------------|
| `app_delete` | `Connector\Components\Hook` | `TemporarlyUninstall($data)` | App uninstalled (see Skill 9) |
| `shop_update` | `{moduleHome}\Components\Shop\Hook` | `shopUpdate($data)` | Shop data updated (see Skill 8: Store Close) |
| `shop_eraser` | `{moduleHome}\Components\Core\Hook` | `ShopEraserMarking($data)` | GDPR shop/redact — marks for erasure |
| `shop_redact` | `{moduleHome}\Components\Shop\Shop` | `eraseShopData($data)` | GDPR data erasure request |
| `customers_data_request` | `{moduleHome}\Components\Customer\Helper` | `customerDataRequest($data)` | GDPR customer data request |
| `app_subscriptions_update` | `{moduleHome}\Components\Subscription\Hook` | `appSubscriptionUpdate($data)` | App subscription plan changed |
| `re_register_webhooks` | `SourceModel` (self) | `reRegisterWebhooks($data)` | Re-register all webhooks |

Note: `shop_eraser` iterates over all connected channels, calling `ShopEraserMarking` on each.

## Amazon Notification Actions

These are Amazon SP-API notification types, not Shopify webhooks. They arrive via SQS from Amazon's notification service.

| Action | Target Class | Method | Description |
|--------|-------------|--------|-------------|
| `ORDER_CHANGE` | `{moduleHome}\Components\Common\Helper` | `getOrderNotificationsData($data)` | Amazon order status changed |
| `ORDER_STATUS_CHANGE` | `{moduleHome}\Components\Common\Helper` | `getOrderNotificationsData($data)` | Amazon order status changed (v2) |
| `FEED_PROCESSING_FINISHED` | `{moduleHome}\Components\Common\Helper` | `webhookFeedSync($data)` | Amazon feed processing complete |
| `REPORT_PROCESSING_FINISHED` | `{moduleHome}\Components\Common\Helper` | `webhookGetReport($data)` | Amazon report ready |
| `LISTINGS_ITEM_STATUS_CHANGE` | `{moduleHome}\Components\Common\Helper` | `webhookProductDelete($data)` | Amazon listing status changed |
| `LISTINGS_ITEM_ISSUES_CHANGE` | `{moduleHome}\Components\Common\Helper` | `webhookProductIssueChange($data)` | Amazon listing issues changed |
| `PRODUCT_TYPE_DEFINITIONS_CHANGE` | `{moduleHome}\Components\Listings\Helper` | `handleProductTypeDefinitionsChangeNotifications($data)` | Amazon product type definitions changed |

## Internal Serverless Response Actions

These are internal callbacks from serverless functions processing async operations. Routed via `handleInternalWebhooks()` → `{moduleHome}\Components\Common\Helper::serverlessResponse()`.

| Action | Description |
|--------|-------------|
| `amazon_product_upload` | Serverless product upload complete |
| `amazon_product_update` | Serverless product update complete |
| `amazon_inventory_sync` | Serverless inventory sync complete |
| `amazon_price_sync` | Serverless price sync complete |
| `amazon_image_sync` | Serverless image sync complete |
| `amazon_product_delete` | Serverless product delete complete |
| `amazon_shipment_sync` | Serverless shipment sync complete |
| `amazon_acknowledgement_sync` | Serverless acknowledgement sync complete |

## WooCommerce / Magento Actions

| Action | Target Class | Method | Description |
|--------|-------------|--------|-------------|
| `woocommerce_product_created` | `{sourceModule}\Components\Product\Product` | `webhookManageCreate($data)` | WooCommerce product created |
| `woocommerce_product_deleted` | `{sourceModule}\Components\Product\Product` | `webhookManageDelete($data)` | WooCommerce product deleted |
| `woocommerce_product_update` | `{sourceModule}\Components\Product\Product` | `webhookManageUpdate($data)` | WooCommerce product updated |
| `woocommerce_orders_created` | `{sourceModule}\Components\Order\OrderCreate` | `createOrder($data)` | WooCommerce order created |
| `woocommerce_orders_updated` | `{sourceModule}\Components\Order\Order` | `webhookManageUpdate($data)` | WooCommerce order updated |
| `magento_order_create` | `{sourceModule}\Components\Order\OrderCreate` | `createOrder($data)` | Magento order created |

## Other Actions

| Action | Target Class | Method | Description |
|--------|-------------|--------|-------------|
| `purchase_order` | `{sourceModule}\Components\Order\PurchaseOrder` | `preparePurchaseOrderInventory($data)` | Purchase order received |
| `item_add_edit` | `{sourceModule}\Components\Product\Route\Requestcontrol` | `processItemWebhook($data)` | Item add/edit webhook |

## Default Branch

Any action not matching the above cases returns `true` without specific handling. This includes several config-defined actions like `order_fulfilled`, `order_cancel`, `order_delete`, `checkouts_create`, `customers_create`, etc.

---

## Config Actions Without initWebhook() Cases

These actions exist in the `webhook` config section of `shopifyhome/etc/config.php` but have no matching `case` in `initWebhook()`:

| Config Action | Topic | Notes |
|--------------|-------|-------|
| `order_fulfilled` | `orders/fulfilled` | Falls through to default |
| `order_cancel` | `orders/cancelled` | Different from `orders_cancelled` |
| `order_delete` | `orders/delete` | Falls through to default |
| `order_partial_fulfilled` | `orders/partially_fulfilled` | Falls through to default |
| `refund_create` | `refunds/create` | Different from `refunds_create` |
| `checkouts_create` | `checkouts/create` | Falls through to default |
| `checkouts_update` | `checkouts/update` | Falls through to default |
| `customers_create` | `customers/create` | Falls through to default |
| `customers_update` | `customers/update` | Falls through to default |
| `orders_updated` | `orders/updated` | Falls through to default |

Note: Some of these represent naming mismatches between config and `initWebhook()` (e.g., `refund_create` vs `refunds_create`). Others are registered webhooks that are received but intentionally not processed.
