# Amazon target: `Amazon\Service\Shipment::ship`

Paths below are under `app/home/app/code/` unless noted.

## Role

After **`App\Connector\Service\ShipmentV2::ship`** succeeds, **`Connector\Components\Order\Shipment::initiateShipment`** resolves `ShipInterface` for the **target** marketplace (`amazon`) and calls **`Amazon\Service\Shipment::ship($payload)`**.

File: `amazon/Service/Shipment.php`.

## Early flow

1. Optional unwrap: if `requeue_data` present, replace `$data`.
2. Load **source** `order_container` row (filter: `object_type` = `source_order`, `marketplace` default `amazon` in filter—verify against call site; method uses `$data['marketplace']` when set) to read `fulfilled_by`, `marketplace_status`, `filter_tags`.
3. **Guards**
   - `fulfilled_by == 'other'` → FBA-style skip; `Connector\Service\Shipment::setSourceStatus` (failure path in code).
   - `marketplace_status == 'Canceled'` → shipment error on order.
   - `Easy Ship` in `filter_tags` → error (must use Amazon’s method).
4. **`checkShipmentSettings`** via `Profile\DefaultSettings` (`settings.order`) → `shipment`, `tracking_number`, `tracking_company`, `shipment_sync_max_age`, carrier maps, etc. If shipment sync off → failure.
5. **Throttle / serverless:** retries &gt; 3 can set `shipment_throttle` on order and force serverless; empty tracking can force serverless path.
6. **`isAllowedToShip`:** optional max age between `source_created_at` and `source_updated_at`.
7. Resolve **`remote_shop_id`** and **`marketplaceId`** from shop / warehouse metadata; missing → failure.
8. **`prepareShipmentData`** builds Amazon-facing structures (carrier codes from cache / `Connector\Models\Shipment\Helper::fetchCarriers`, carrier mapping from profile, `Other` fallback).

## Outbound paths

| Condition | Method | Behavior (summary) |
|-----------|--------|---------------------|
| `$serverless` true (no tracking, throttle, etc.) | `shipmentFeedCall` | Builds feed-style payload, `base64_encode(json_encode($fulfillmentData))`, `Helper::sendRequestToAmazon('shipment-sync', …, 'POST')` with `operation_type` `Update`. On failure may call `Connector\Service\Shipment::setSourceStatus` with support message + optional mail. |
| Else | `confirmShipmentCall` | `Helper::sendRequestToAmazon('shipment-confirm', …, 'POST')` with structured `packageDetail` / `orderItems`. On success calls `Connector\Service\Shipment::setSourceStatus` with success. Certain API errors trigger `serverless` re-entry via recursive `ship()`. Throttle strings may call `handleThrottle`. |

Exact request/response shapes live in `App\Amazon\Components\Common\Helper::sendRequestToAmazon` and remote services—not duplicated here.

## Logging

Instance log path pattern: `shipment/{userId}/{d-m-Y}.log` (see class property `$shipmentLog`).

## Related

- Connector persistence before Amazon: `connector/Service/ShipmentV2.php` (see [connector-shipment-orchestration](../../connector-shipment-orchestration/SKILL.md)).
- Orchestrator: `connector/Components/Order/Shipment.php` `initiateShipment`.
