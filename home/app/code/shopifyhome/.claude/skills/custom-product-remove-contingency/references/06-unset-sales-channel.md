# Step F — `unsetSalesChannel` (Cleanup Temp Markers)

**File:** `app/home/app/code/shopifyhome/Components/Webhook/Helper.php` (line ~1838)

## Purpose

Removes the temporary `sales_channel` field from all `product_container` documents that were marked during step D. This is a **cleanup step** — the marker was only used to identify products without the channel and is no longer needed.

## Method Signature

```php
public function unsetSalesChannel($data)
```

## Constants

```php
private const UNSET_SALES_CHANNEL_BATCH = 500;
```

## Flow

1. **Query** `product_container` for documents WITH `sales_channel` marker:
   ```php
   [
       'user_id'       => $userId,
       'shop_id'       => $shopId,
       'visibility'    => 'Catalog and Search',
       'sales_channel' => ['$exists' => true],
   ]
   ```
   - **Limit:** 500 per batch (`UNSET_SALES_CHANNEL_BATCH`)
   - **Projection:** `_id` only
   - **Sort:** `_id: 1` (ascending for cursor pagination)
   - **Cursor:** `$data['unset_sales_channel_last_id']` with `_id: ['$gt' => ...]`

2. **If batch found:**
   - `updateMany` to `$unset: ['sales_channel' => '']` for matched `_id`s
   - Log chunk count and cumulative total
   - **Re-queue self** via SQS with:
     - `unset_sales_channel_last_id` = last document `_id`
     - `unset_sales_channel_chunk_count` = running total
   - Returns (process continues via SQS)

3. **If no more documents (all cleaned up):**
   - Log "Sales Channel Unassigned (chunked pass complete)"
   - Remove QueuedTask entry via `removeEntryFromQueuedTask('shopify_product_remove')`
   - Create success notification: "Contingency process completed. Total X products(s) removed from app."
   - Insert record into `adoption_metrics`:
     ```php
     [
         'user_id'                => $userId,
         'process_code'           => 'shopify_product_remove',
         'shop_id'                => $shopId,
         'removed_products_count' => $removeProducts,
         'created_at'             => date('Y-m-d H:i:s'),
     ]
     ```

## SQS Re-queue Payload

```php
[
    'type'                              => 'full_class',
    'class_name'                        => Helper::class,
    'method'                            => 'unsetSalesChannel',
    'queue_name'                        => 'shopify_product_webhook_backup_sync',
    'unset_sales_channel_last_id'       => $lastId,
    'unset_sales_channel_chunk_count'   => $unsetSoFar,
    // inherits user_id, shop_id, remote_shop_id, etc. from $data
]
```

## Why Chunked?

For users with large catalogs (hundreds of thousands of products), a single `updateMany` touching all documents would be too slow and could timeout. The chunked approach processes 500 documents per SQS message, keeping each operation fast and allowing SQS visibility timeout to reset between batches.

## Finalization Summary

After this step completes:
- All `product_container` documents have had the temp `sales_channel` field removed
- Products without the sales channel have been deleted from `product_container`, `refine_product`
- Amazon listings for deleted products have been unlinked (and optionally inventory zeroed)
- Profile manual assignments have been cleaned up
- QueuedTask has been removed
- Success notification has been created
- Adoption metrics have been recorded
