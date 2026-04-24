# Step E — `getContainerIds` + `removeContainerIds` (Product Cleanup)

**File:** `app/home/app/code/shopifyhome/Components/Webhook/Helper.php` (lines ~1516, ~1641)

## Purpose

Finds products in `product_container` that do **NOT** have the `sales_channel` marker (i.e., Shopify no longer has them in the sales channel), then deletes them from `product_container`, `refine_product`, unlinks from `amazon_listing`, removes from `profile`, and optionally zeroes Amazon inventory.

---

## `getContainerIds($data)` (line ~1516)

### Flow

1. **Query** `product_container` for products WITHOUT `sales_channel` marker:
   ```php
   [
       'user_id'       => $userId,
       'shop_id'       => $shopId,
       'visibility'    => 'Catalog and Search',
       'sales_channel' => ['$exists' => false],    // not marked by step D
   ]
   ```
   - **Limit:** 1000 per batch
   - **Projection:** `container_id`, `profile`
   - **Sort:** `_id: 1` (ascending for cursor pagination)

2. **First call:** Also counts total matching documents -> stored in `$data['count']`

3. **Pagination:** Uses `last_traversed_object_id` with `_id: ['$gt' => ...]` for subsequent batches

4. **If products found:**
   - Extract `container_id` array
   - Build profile-to-containerIds map (for manual profile assignments)
   - Call `removeProfileData($result, $userId)` — removes product IDs from manually assigned templates
   - Call `removeContainerIds($handlerData)` — deletes products from collections
   - **Re-queue self** via SQS with `last_traversed_object_id` for next batch
   - Update progress: 50-95% range based on `container_id_removed_count / total_count`

5. **If no more products:** Call `unsetSalesChannel($data)` directly (step F)

---

## `removeContainerIds($sqsData)` (line ~1641)

### Deletions Performed

1. **`product_container`** — Delete all matching products:
   ```php
   $productContainer->deleteMany([
       'user_id'      => $userId,
       'container_id' => ['$in' => $productsWithoutSalesChannel],
   ]);
   ```

2. **`refine_product`** — Delete refined product data:
   ```php
   $refineProduct->deleteMany([
       'user_id'        => $userId,
       'source_shop_id' => $shopId,
       'container_id'   => ['$in' => $productsWithoutSalesChannel],
   ]);
   ```

3. **`amazon_listing`** — Unlink (not delete) Amazon listings:
   - Find listings where `source_container_id` OR `closeMatchedProduct.source_container_id` matches
   - Call `initatetoInactive()` to zero inventory on Amazon (if config enabled)
   - UpdateMany to:
     - `$set`: `unmap_record` with `removeProductWebhookBackup: true` and timestamp
     - `$unset`: `source_product_id`, `source_variant_id`, `source_container_id`, `matched`, `manual_mapped`, `matchedProduct`, `matchedwith`, `closeMatchedProduct`

---

## `removeProfileData($result, $userId)` (line ~1713)

Removes deleted product IDs from manually assigned templates:

```php
$profileCollection->bulkWrite([
    'updateOne' => [
        ['user_id' => $userId, '_id' => ObjectId($profileId)],
        ['$pullAll' => ['manual_product_ids' => $containerIdsToRemove]]
    ]
]);
```

---

## `initatetoInactive($userId, $shopId, $listings)` (line ~1747)

Zeroes Amazon inventory for unlinked products **if** `inactivate_product` config is enabled for the target shop:

1. Reads `inactivate_product` config per target shop
2. Groups listings by target shop
3. For each target shop (if config enabled):
   - Builds feed with `Quantity: 0`, `Latency: 2` per SKU
   - Sends in batches of 500 via `sendRequestToAmazon('inventory-upload', $specifics, 'POST')`
   - Feed is base64-encoded JSON, sent as `unified_json_feed`

---

## Key Data Fields in SQS (re-queue)

```php
[
    'type'                       => 'full_class',
    'class_name'                 => Helper::class,
    'method'                     => 'getContainerIds',
    'last_traversed_object_id'   => $lastProductData['_id'],   // cursor for pagination
    'total_count'                => ...,                        // from first query count
    'container_id_removed_count' => ...,                        // running total
    'progress_start'             => ...,                        // current progress %
    'feed_id'                    => ...,
]
```
