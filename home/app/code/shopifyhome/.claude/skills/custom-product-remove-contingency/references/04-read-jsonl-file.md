# Step D — `readJSONLFile` (Batch JSONL Processing)

**File:** `app/home/app/code/shopifyhome/Components/Webhook/Helper.php` (line ~1377)

## Purpose

Reads the downloaded JSONL file in **1000-line batches**, extracts Shopify product IDs, and marks matching products in `product_container` with `sales_channel=true`. This temporary marker is later used to identify which products do NOT have the sales channel.

## Method Signature

```php
public function readJSONLFile($sqsData)
```

## Flow

1. **Validate file exists** at `$sqsData['data']['file_path']`
2. **Count total rows** (first call only): `countJsonlLines()` -> stored in `$sqsData['data']['total_rows']`
3. **Seek to position:** Uses `$sqsData['data']['seek']` (byte offset) to resume from last batch
4. **Read batch:** Up to 1000 lines:
   - Parse each JSON line
   - Extract product ID from `$data['id']` (format: `gid://shopify/Product/12345` -> takes last segment)
   - Collect into `$batch[]`
5. **If batch is non-empty:**
   - Call `assignUniqueKey($assignKeyData)` to mark products
   - Record new `seek` position (byte offset via `ftell`)
   - Update progress: `(rows_processed / total_rows) * 50` (0-50% range)
   - Update QueuedTask progress: "Reading Shopify Products"
   - **Re-queue self** via SQS with `method: readJSONLFile` and updated `seek`/`rows_processed`
6. **If batch is empty (EOF):**
   - Delete the JSONL file (`unlink`)
   - **Call `getContainerIds($data)` directly** (no SQS hop)

## `assignUniqueKey($sqsData)` (line ~1478)

Marks products that **have** the sales channel:

```php
$productContainer->updateMany(
    [
        'user_id'      => $userId,
        'shop_id'      => $shopId,
        'container_id' => ['$in' => $batch],          // product IDs from JSONL
        'visibility'   => 'Catalog and Search'
    ],
    ['$set' => ['sales_channel' => true]]
);
```

**Logic:** The JSONL file contains products that Shopify says still have the sales channel. By marking them, step E can find products that are in `product_container` but are NOT in the JSONL (i.e., sales channel was removed).

## Pagination Mechanism

Uses **byte-level seeking** (`fseek`/`ftell`), not line numbers. Each SQS message carries the byte offset to resume reading. This avoids re-reading the file from the beginning on each batch.

## `countJsonlLines()` (line ~1453)

Simple line counter — reads entire file once to get total non-empty lines. Used for progress calculation.
