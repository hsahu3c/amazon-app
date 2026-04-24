# Hook::shopUpdate and related methods

**File:** `app/home/app/code/shopifyhome/Components/Shop/Hook.php`

Use this reference when you need line-level behavior for the Shopify-side store close and reopen detection and for the manual store-active replay path.

---

## shopUpdate($data) — lines ~144–214

**Routing:** `shopifyhome/Models/SourceModel.php` `initWebhook()` case `shop_update` (around line 1317) calls `Hook::shopUpdate($data)`. If the marketplace product Hook class defines `shopUpdateWebhook`, that method may also run.

**Inputs:** `$data` includes `user_id`, `shop_id`, `marketplace`, and `data` (Shopify shop payload from webhook, including `plan_name`).

**Close plan set:**

- `cancelled`, `frozen`, `fraudulent` (inline array in `shopUpdate`).

**Logic:**

1. Load source shop via `User\Details::getShop($homeShopId, $userId)`.
2. **Reopened:** Webhook `plan_name` is not in the close set, but stored `plan_name` is:
   - Set `store_reopened_at` on the payload.
   - If `store_closed_at` existed: `user_details` `updateOne` with `$unset` on `shops.$.store_closed_at`.
   - `Webhook\Helper::validateShopGraphQLWebhooks($sourceShop)`.
   - `Shop::fetchAndSavePublicationId(...)`.
   - `Shop::checkAccessScopeAndSetReauthIfMissing($sourceShop, true)`.
3. **Closed:** Stored plan is not in the close set, webhook `plan_name` is:
   - Set `store_closed_at` on the payload.
   - If `store_reopened_at` existed: `$unset` `shops.$.store_reopened_at`.
   - `sendStoreCloseMail($userId)`.
4. Set `webhookData['_id']` from source shop; persist with `User\Details::addShop($webhookData, $userId, ['_id'])`.
5. On success: call `notifyTargets($data['marketplace'], 'reopened'|'closed')` as appropriate.

**Logging:** `shopify/shopUpdate/{d-m-Y}.log`; exceptions logged to `exception.log`.

---

## notifyTargets($marketplace, $status) — lines ~216–235

- Reads current user `shops` from DI.
- Only fires `application:shopStatusUpdate` when `count($shops) > 1` (multi-shop users).
- Payload: `user_id`, `shops`, `status` (`closed` or `reopened`), `marketplace`.

Single-shop merchants do not get this event from `notifyTargets`.

---

## checkStoreStatus($params) — lines ~237–272

Resolves `user_id`, source shop id, and marketplace; loads shop; calls `ApiClient` GET `/shop`.

On success with shop data:

- `$unset` `shops.$.store_closed_at` on `user_details`.
- Push SQS: `class_name` = `Hook::class`, `method` = `shopUpdate`, `queue_name` = `shop_update`, `data` = remote shop payload.

---

## sendStoreCloseMail($userId) — lines ~274–293

1. `setDiForUser($userId)`.
2. Email subject: `Attention! App Services Suspended`; template path uses `Shop::getUserMarkeplace()` and `store_close.volt` under the marketplace home view email folder.
3. `SendMail::send($mailData)`.

---

## User\Details::addShop

Defined in `app/home/app/code/core/Models/User/Details.php`. `shopUpdate` uses it to merge webhook fields (`plan_name`, `store_closed_at`, `store_reopened_at`, etc.) into `user_details`.
