# Remote: shopifywebapi bulk operation (metafield sync)

When the home app calls **`POST /bulk/operation`** on the Shopify remote (`ApiClient::init('shopify', true)`), execution lands in **`shopifywebapi`** as follows.

**Files:**

1. [`app/remote/app/code/shopifywebapi/Api/BulkOperation.php`](app/remote/app/code/shopifywebapi/Api/BulkOperation.php)
2. [`app/remote/app/code/shopifywebapi/Components/BulkOperation/BulkOperationQL.php`](app/remote/app/code/shopifywebapi/Components/BulkOperation/BulkOperationQL.php)
3. [`app/remote/app/code/shopifywebapi/Components/BulkOperation/BulkOperationQuery.php`](app/remote/app/code/shopifywebapi/Components/BulkOperation/BulkOperationQuery.php)

---

## 1. API entry: `BulkOperation::createBulkOperation`

**File:** `Api/BulkOperation.php`

- Requires current shop in registry (`getCurrentShop()`).
- `getFilteredParams($request_data)` normalizes incoming body.
- Delegates to **`BulkOperationQL::createBulkOperation($params)`**.

`getBulkOperation` (GET status) uses `BulkOperationQL::getBulkOperation` / `getCurrentBulkOperation` — not the query builder below.

---

## 2. `BulkOperationQL::createBulkOperation`

**File:** `Components/BulkOperation/BulkOperationQL.php`

1. Sales channel: if `_salesChannel`, may remap `type === 'product'` to `saleschannelproduct` and attach `publication_id` from `getPublicationId()`.
2. **`BulkOperationQuery::getBulkOperationQuery($params)`** — builds the GraphQL bulk query string.
3. On success: **`QueryQL::getBulkOperationCreateMutation($bulkQuery)`** wraps it in the Admin `bulkOperationRunMutation`.
4. **`graph($query)`** runs the mutation; response is parsed and `data.bulkOperation` is returned to the client.

---

## 3. Query routing: `getBulkOperationQuery` → `getQueryByType`

**File:** `Components/BulkOperation/BulkOperationQuery.php`

**`getBulkOperationQuery($data)`** (~26–46):

- If **`$data['type'] === 'query'`** — uses raw `data['query']` string (custom GraphQL).
- Else calls **`getQueryByType($data['type'], $data)`**.

**`getQueryByType($type, $data)`** (~48–61) — this is the **type dispatcher** (sometimes described informally as “get query by type”):

```php
$method_name = 'getQueryFor' . ucfirst(strtolower((string) $type));
if (method_exists($this, $method_name)) {
    return ['success' => true, 'query' => $this->$method_name($data)];
}
```

So the **`type`** sent from home (e.g. `metafieldsync`, `metafield`) becomes:

| Request `type` | `strtolower` + `ucfirst` | Private method called |
|----------------|---------------------------|------------------------|
| `metafieldsync` | `Metafieldsync` | **`getQueryForMetafieldsync($data)`** |
| `metafield` | `Metafield` | **`getQueryForMetafield($data)`** |

The home **`Metafield`** pipeline uses **`filter_type` / `type`** value **`metafieldsync`** → always **`getQueryForMetafieldsync`**, not `getQueryForMetafield`.

---

## 4. `getQueryForMetafield($data)` (~398–455)

- Builds a **`products(first:1, query:"NOT (status:draft) {publication filter}")`** bulk query with product + variant **metafields** (`namespace`, `type`, `key`, `value`, `createdAt`, `updatedAt`).
- **No** `updated_at` / “products changed since” filter on the product query string.
- Optional **`publication_id`** → appends `AND publication_ids:{id}` when present (also set in `BulkOperationQL` for sales channel).

Use this when bulk `type` is **`metafield`** (different from cron **`metafieldsync`**).

---

## 5. `getQueryForMetafieldsync($data)` (~457–522)

Used when bulk **`type`** is **`metafieldsync`** (CRON / metafield sync path).

**`updated_at` handling:**

- If **`$data['updated_at']`** is set — uses that ISO string in the product search query.
- Else — defaults to **yesterday at 00:00:00** in the remote server timezone (`DateTime`, `modify('-1 day')`, `setTime(0,0,0)`), formatted as `Y-m-d\TH:i:sP`.

So when the **home app omits `updated_at`** (e.g. all-users `customMetafieldSyncCron` path), the **remote still applies a time window** — roughly “products updated since start of yesterday,” not “all products.”

**Product query fragment:**

```text
products(first:1, query:"updated_at:>='{formattedDate}' AND NOT (status:draft) {publication filter}")
```

Same metafield shape on products and variants as `getQueryForMetafield`.

---

## Summary chain

```
POST bulk/operation (home → shopifywebapi)
  → Api\BulkOperation::createBulkOperation
  → BulkOperationQL::createBulkOperation
  → BulkOperationQuery::getBulkOperationQuery
        → getQueryByType(type)  // builds getQueryFor{Type}
        → getQueryForMetafieldsync  // when type === metafieldsync
        → or getQueryForMetafield   // when type === metafield
  → QueryQL::getBulkOperationCreateMutation + graph()
```

For debugging “wrong products in bulk file,” verify the **`type`** (`metafield` vs `metafieldsync`) and whether **`updated_at`** is passed through to the remote payload.
