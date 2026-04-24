# Step B — `handleProductWebhook` (Remote Bulk Operation)

**File:** `app/home/app/code/shopifyhome/Components/Webhook/Helper.php` (line ~1257)

## Purpose

Calls the Remote app to trigger a **Shopify Bulk GraphQL operation** that fetches all products with the sales channel assigned. The resulting JSONL file will later be used to identify which products still have the channel.

## Method Signature

```php
public function handleProductWebhook($sqsData)
```

## Flow

1. **Update progress** to 0% — "retrieving products from source catalog"
2. **Remote call:** `POST /bulk/operation` with params:
   ```php
   [
       'shop_id' => $remoteShopID,
       'type'    => 'productwebhook',
   ]
   ```
   Via: `ApiClient->init('shopify', true)->call('/bulk/operation', [], $params, 'POST')`

3. **On success:**
   - Pushes SQS message with `method: requestStatus` and the remote response data (contains bulk operation `id`)
   - Returns success

4. **On "already in progress" error:**
   - Re-queues the same SQS message with `delay: 180` (retry after 3 minutes)
   - Message detection: `str_contains($remoteResponse['msg'], 'A bulk query operation for this app and shop is already in progress')`

5. **On other failure:**
   - Calls `removeEntryFromQueuedTask('shopify_product_remove')`
   - Calls `updateActivityLog()` with critical severity
   - Returns failure

## SQS Output (on success)

```php
[
    'type'           => 'full_class',
    'class_name'     => Helper::class,
    'method'         => 'requestStatus',        // next step
    'user_id'        => ...,
    'shop_id'        => ...,
    'remote_shop_id' => ...,
    'queue_name'     => 'shopify_product_webhook_backup_sync',
    'data'           => $remoteResponse['data'], // contains 'id' for polling
    'process_code'   => 'shopify_product_remove',
    'marketplace'    => 'shopify',
    'feed_id'        => ...,
]
```

## Remote Endpoint

The Remote app endpoint `POST /bulk/operation` with `type=productwebhook` triggers a Shopify Bulk GraphQL query that returns all products with the app's publication/sales channel. The response includes a bulk operation `id` used for polling in the next step.
