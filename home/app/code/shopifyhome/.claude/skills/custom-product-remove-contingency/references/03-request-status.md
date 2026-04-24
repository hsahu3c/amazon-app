# Step C — `requestStatus` (Poll Bulk Operation)

**File:** `app/home/app/code/shopifyhome/Components/Webhook/Helper.php` (line ~1308)

## Purpose

Polls the Remote app for the Shopify Bulk GraphQL operation status until it reaches `COMPLETED`, then downloads and saves the JSONL file.

## Method Signature

```php
public function requestStatus($sqsData)
```

## Flow

1. **Remote call:** `GET /bulk/operation` with params:
   ```php
   [
       'shop_id' => $remoteShopID,
       'id'      => $sqsData['data']['id'],  // bulk operation ID from step B
   ]
   ```

2. **Status: RUNNING or CREATED**
   - Increments `retry` counter
   - Re-queues with `delay: 180` (3 min wait)
   - Returns `true`

3. **Status: COMPLETED**
   - **objectCount == 0:** No products have sales channel assigned -> removes QueuedTask, logs, returns success
   - **partialDataUrl present:** Terminates process (partial data is unreliable)
   - **Empty URL:** Terminates process
   - **Valid URL:** Downloads JSONL file via `Vistar\Helper::saveJSONLFile($url, 'product_remove_contingency')`
     - File saved to: `var/file/{user_id}/{shop_id}/product_remove_contingency/{filename}.jsonl`
     - Sets `$sqsData['data']['file_path']` to saved path
     - Unsets any `delay` from SQS data
     - **Calls `readJSONLFile($sqsData)` directly** (no SQS hop)

4. **Other / Error states:**
   - **Remote token error:** Re-queues for retry
   - **Other:** Terminates, removes QueuedTask, logs failure

## Key Decisions

- **No SQS hop to step D:** `readJSONLFile()` is called directly from within `requestStatus()` once the file is saved. This is different from steps E/F which use SQS for pagination.
- **Retry strategy:** 3-minute delay between polls, no max retry limit (unbounded retries for RUNNING/CREATED).
- **Partial data rejection:** If Shopify returns partial results, the entire process is terminated rather than working with incomplete data.
