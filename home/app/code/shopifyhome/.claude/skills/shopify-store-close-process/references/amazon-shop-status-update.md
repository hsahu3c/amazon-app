# Amazon ShopEvent::shopStatusUpdate

**File:** `app/home/app/code/amazon/Components/ShopEvent.php`  
**Method:** `shopStatusUpdate($event, $myComponent, $data)` — approximately lines 551–610.

Use this reference when debugging why Amazon **unregister webhooks**, **stopped order sync**, or **re-subscribed notifications** after a Shopify store closed or reopened.

---

## Event binding

**File:** `app/home/app/code/amazon/etc/config.php`

```php
'application:shopStatusUpdate' => [
    'after_shopStatusUpdate' => ShopEvent::class
],
```

The application event manager maps this to `ShopEvent::shopStatusUpdate` (convention: listener class + configured key).

---

## Guards

- Requires `$data['shops']` and `$data['status']`, non-empty `shops`.
- `$userId = $data['user_id'] ?? $this->di->getUser()->id`.
- Per shop: only processes when `marketplace == 'amazon'` and `shop['apps'][0]['app_status'] == 'active'`.
- Wrapped in try/catch; failures log to `amazon/shopStatusUpdate/{d-m-Y}.log`.

---

## status == 'closed'

For each matching Amazon shop:

1. Build `$dataTosent`: `user_id`, `shop_id`, `remote_shop_id`.
2. **`sendRemovedShopToAws($dataTosent)`** — DynamoDB / AWS cleanup for removed shop context.
3. **`SourceModel::routeUnregisterWebhooks($shop, 'amazon', $appCode, false)`** — tear down notification subscriptions (see common webhook registration / unregister flows).
4. **`updateRegisterForOrderSync($userId, $shop['_id'], false)`** — disable order sync registration.
5. **`setSourceStatus($userId, $shop, 'closed')`** — persist closed state on source side.

---

## status == 'reopened'

For each matching Amazon shop:

1. **`Shop\Hook::updateWarehouseStatus($userId, $shop['_id'], 'active')`**.
2. Set `$shop['warehouses'][0]['status'] = 'active'` in memory.
3. **`ShopDelete::removeRegisterForOrderSync($preparedData)`** and `unset($shop['register_for_order_sync'])`.
4. **`syncUserToDynamo($shop)`** — re-register order sync path when plan allows (same family as account connection).
5. **`Notification\Helper::createSubscription($dataToSent)`** with `user_id` and `target_marketplace` — re-enters notification subscription / common registration pipeline.
6. **`setSourceStatus($userId, $shop, 'active')`**.

---

## Relationship to Hook::notifyTargets

`Hook::notifyTargets` in `shopifyhome/Components/Shop/Hook.php` only fires `application:shopStatusUpdate` when **`count($shops) > 1`**. Single-shop users will not trigger this Amazon listener through that path; other scripts (e.g. dormant user cleanup) may fire the same event explicitly.
