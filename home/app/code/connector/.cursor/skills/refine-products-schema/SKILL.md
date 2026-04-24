---
name: refine-products-schema
description: Complete payload schema for POST connector/product/getRefineProducts and getRefineProductCount — filter constants to MongoDB operator mapping, special filter keys (template, SKU, barcode, activity, Optimised, cif_amazon_multi_inactive), Atlas Search vs standard aggregation, allowed_filters config, cursor pagination encoding, edge cases, and input sanitization.
---

# Refine Products Schema (Global)

This skill documents the backend API schema, filter-to-MongoDB mapping, and edge cases for `connector/product/getRefineProducts` and `connector/product/getRefineProductCount`. It focuses on what the backend expects, how it processes filters, and the exact MongoDB queries generated.

> **Related Skills:**
> - `react-amazon-multi/.cursor/skills/listing-grid-filters-tabs-session/` — Frontend filter UI, session storage keys, tab-to-filter mapping, debounced fetch triggers
> - `connector/.cursor/skills/product-filter/` — High-level product retrieval overview, GetProductProfileMerge, profile-based retrieval

## Key Paths

| File | Purpose |
|------|---------|
| `controllers/ProductController.php` | getRefineProductsAction() (~line 1039), getRefineProductCountAction() (~line 1061) |
| `Models/ProductContainer.php` | Core model: getRefineProducts(), getRefineProductCount(), buildRefineProductAggregateQuery(), buildSearchAggrigation(), search(), createFilter(), createFilterAtlas() |
| `Components/PaginationHelper.php` | Cursor-based pagination: getNextPrevData(), cursor encoding/decoding |
| `etc/config.php` | allowed_filters list (~line 97) |

## API Endpoints

| Method | Endpoint | Action |
|--------|----------|--------|
| POST | `connector/product/getRefineProducts` | Paginated filtered product listing |
| POST | `connector/product/getRefineProductCount` | Count for current filters |

## Request Schema

### getRefineProducts

```php
POST connector/product/getRefineProducts
{
    // === FILTERS ===
    "filter": {                              // AND conditions
        "<field>": { "<operator>": "<value>" }
    },
    "or_filter": { ... },                    // OR conditions (same format)
    "parallel_filter": { ... },              // Independent AND conditions
    "filterType": "and",                     // "and" | "or" (default: "and")

    // === PAGINATION ===
    "count": 50,                             // Items per page (default: 20)
    "next": "base64_cursor",                 // Next page cursor
    "prev": "base64_cursor",                 // Previous page cursor
    "activePage": 1,                         // Alternative: offset-based page

    // === SORTING ===
    "sortBy": "_id",                         // Field to sort (default: "_id")
    "sortOrder": "asc",                      // "asc" | "desc"

    // === PROJECTION ===
    "project": ["title", "price"],           // Fields to include
    "cif_amazon_multi_project": { ... },     // MongoDB $project spec with $map, $filter, $sum

    // === OPTIONS ===
    "productOnly": true,                     // Filter to products only
    "useAtlasSearch": false,                 // Force Atlas Search
    "is_only_parent_allow": false,           // Include all vs parents only
    "container_ids": ["id1", "id2"]          // Filter specific containers
}
```

### getRefineProductCount

```php
POST connector/product/getRefineProductCount
{
    "filter": { ... },                       // Same format as above
    "or_filter": { ... },
    "filterType": "and"
}
```

## Response Schema

### getRefineProducts Response

```php
{
    "success": true,
    "data": {
        "totalPageRead": 3,
        "current_count": 50,
        "next": "base64_encoded_cursor",     // null if last page
        "prev": "base64_encoded_cursor",     // null if first page
        "rows": [
            {
                "_id": { "$oid": "..." },
                "container_id": "string",
                "source_product_id": "string",
                "source_shop_id": "string",
                "target_shop_id": "string",
                "user_id": "string",
                "type": "simple | variation",
                "brand": "string",
                "title": "string",
                "items": [ ... ],            // Variant/item array
                "is_bundle": true | false
            }
        ],
        "query": [ ... ]                     // Debug: aggregation pipeline used
    }
}
```

### getRefineProductCount Response

```php
{
    "success": true,
    "data": { "count": 1500 }
}
```

## Filter Constants & MongoDB Mapping

Defined in `ProductContainer.php` (lines 21-37):

| Constant | Code | MongoDB Operator | Example Input | MongoDB Output |
|----------|------|-----------------|---------------|----------------|
| `IS_EQUAL_TO` | `1` | Direct match | `{"title": {"1": "Shirt"}}` | `{"title": "Shirt"}` |
| `IS_NOT_EQUAL_TO` | `2` | `$ne` | `{"status": {"2": "Active"}}` | `{"status": {"$ne": "Active"}}` |
| `IS_CONTAINS` | `3` | `$regex` (case-insensitive) | `{"title": {"3": "blue"}}` | `{"title": {"$regex": "blue", "$options": "i"}}` |
| `IS_NOT_CONTAINS` | `4` | Negative regex | `{"title": {"4": "draft"}}` | `{"title": {"$regex": "^((?!draft).)*$", "$options": "i"}}` |
| `START_FROM` | `5` | `$regex` anchored start | `{"sku": {"5": "AMZ-"}}` | `{"sku": {"$regex": "^AMZ-", "$options": "i"}}` |
| `END_FROM` | `6` | `$regex` anchored end | `{"sku": {"6": "-US"}}` | `{"sku": {"$regex": "-US$", "$options": "i"}}` |
| `RANGE` | `7` | `$gte` + `$lte` | `{"price": {"7": {"from":10,"to":100}}}` | `{"price": {"$gte": 10.0, "$lte": 100.0}}` |
| `IS_GREATER_THAN` | `8` | `$gte` | `{"quantity": {"8": "50"}}` | `{"items.quantity": {"$gte": 50.0}}` |
| `IS_LESS_THAN` | `9` | `$lte` | `{"quantity": {"9": "10"}}` | `{"items.quantity": {"$lte": 10.0}}` |
| `CONTAIN_IN_ARRAY` | `10` | `$in` | `{"brand": {"10": ["Nike","Adidas"]}}` | `{"brand": {"$in": ["Nike","Adidas"]}}` |
| `NOT_CONTAIN_IN_ARRAY` | `11` | `$nin` | `{"status": {"11": ["draft"]}}` | `{"status": {"$nin": ["draft"]}}` |
| `KEY_EXISTS` | `12` | `$exists` | `{"barcode": {"12": true}}` | `{"barcode": {"$exists": true}}` |

### Type Conversion (`checkInteger`)

Fields automatically converted to `float`:
- `price`, `quantity`, `items.quantity`, `items.price`, `marketplace.quantity`, `marketplace.price`

All others are trimmed strings.

### Array Field Handling

For fields prefixed with `items.` or `marketplace.` with RANGE operator, uses `$elemMatch`:
```php
{
    "$and": [{
        "items": {
            "$elemMatch": {
                "quantity": { "$gte": 10.0, "$lte": 100.0 }
            }
        }
    }]
}
```

## Special Filter Keys

These filter keys have **custom processing logic** in `createFilter()` and do NOT follow the generic mapping above:

### `cif_amazon_multi_inactive` (Not Listed tab)

Used when the "Not Listed" tab is selected. Builds an `$or` condition checking for status = value OR status field is null:

```php
// Input: {"cif_amazon_multi_inactive": {"1": "Not Listed"}}
// Output:
{
    "$or": [
        {"items.status": "Not Listed"},
        {"items.status": null}
    ]
}
```

### `cif_amazon_multi_sku` (SKU filter)

Searches across BOTH `items.sku` AND `items.shopify_sku` using `$or`:

```php
// Input: {"cif_amazon_multi_sku": {"3": "test-sku"}}
// Output:
{
    "$or": [
        {"items.sku": {"$regex": "test-sku", "$options": "i"}},
        {"items.shopify_sku": {"$regex": "test-sku", "$options": "i"}}
    ]
}
```

Supports all 6 text operators (1-6): Equals, Not Equals, Contains, Not Contains, Starts With, Ends With.

### `cif_amazon_multi_template_id` (Template filter)

Maps to `profile.profile_id` with ObjectId conversion:

```php
// Input: {"cif_amazon_multi_template_id": {"1": "507f1f77bcf86cd799439011"}}
// Output:
{"profile.profile_id": ObjectId("507f1f77bcf86cd799439011")}
```

### `cif_amazon_multi_template_id_multi` (Multi-template filter)

Uses `$in` operator on `profile.profile_id` with ObjectId array:

```php
// Input: {"cif_amazon_multi_template_id_multi": {"10": ["id1", "id2"]}}
// Output:
{"profile.profile_id": {"$in": [ObjectId("id1"), ObjectId("id2")]}}
```

### `template_name` (Template name filter)

Maps to `profile.profile_name`:

```php
// Input: {"template_name": {"3": "Default"}}
// Output:
{"profile.profile_name": {"$regex": "Default", "$options": "i"}}
```

### `cif_amazon_multi_barcode_exists` (Barcode existence)

Uses KEY_EXISTS (12) with custom true/false logic:

```php
// Input: {"cif_amazon_multi_barcode_exists": {"12": "1"}}  (barcode exists)
// Output:
{
    "$and": [
        {"items.barcode": {"$exists": true}},
        {"items.barcode": {"$ne": null}},
        {"items.barcode": {"$ne": ""}}
    ]
}

// Input: {"cif_amazon_multi_barcode_exists": {"12": "0"}}  (no barcode)
// Output:
{
    "$or": [
        {"items.barcode": {"$exists": false}},
        {"items.barcode": null},
        {"items.barcode": ""}
    ]
}
```

### `cif_amazon_multi_activity` (Activity filter)

Checks if a field exists on items (In Progress / Error / Warning):

```php
// Input: {"cif_amazon_multi_activity": {"1": "error"}}
// Output:
{"items.error": {"$exists": true}}

// Input: {"cif_amazon_multi_activity": {"1": "process_tags"}}
// Output:
{"items.process_tags": {"$exists": true}}
```

### `cif_amazon_multi_is_bundle` (Bundle filter)

```php
// Input: {"cif_amazon_multi_is_bundle": {"12": "1"}}   (bundled only)
// Output:
{"is_bundle": {"$exists": true}}

// Input: {"cif_amazon_multi_is_bundle": {"12": "0"}}   (non-bundled)
// Output:
{"is_bundle": {"$exists": false}}
```

### `cif_amazon_multi_fulfillment_type` (Fulfillment type)

Uses `$or` with null fallback:

```php
// Input: {"cif_amazon_multi_fulfillment_type": {"1": "FBA"}}
// Output:
{
    "$or": [
        {"items.fulfillment_type": "FBA"},
        {"items.fulfillment_type": null}
    ]
}
```

### `cif_amazon_multi_variant_attributes` (Variant attributes)

Splits comma-separated values and builds `$and` with `$size` check:

```php
// Input: {"cif_amazon_multi_variant_attributes": {"1": "Color,Size"}}
// Output:
{
    "$and": [
        {"variant_attributes": "Color"},
        {"variant_attributes": "Size"},
        {"variant_attributes": {"$size": 2}}
    ]
}
```

### `cif_amazon_multi_product_inactive` (Exclude statuses)

Uses `$nin` to exclude specific statuses:

```php
// Input: {"cif_amazon_multi_product_inactive": {"1": "Active,Inactive"}}
// Output:
{"items.status": {"$nin": ["Active", "Inactive"]}}
```

### Optimised Flag (Frontend Special Case)

The **frontend** `filter()` function in `ListingGrid.tsx` handles this before sending:

```javascript
// When Optimised filter has textValue === 'false':
if (key === 'Optimised' && custom_filter[key].textValue === 'false') {
    tempData[2] = true;  // Forces operator code 2 (IS_NOT_EQUAL_TO)
}
// Backend receives: {"Optimised_field": {"2": true}}
// Which maps to: {field: {$ne: true}} → products NOT optimised
```

## Atlas Search vs Standard MongoDB

The system has two filter processing paths:

| Aspect | Standard MongoDB | Atlas Search |
|--------|-----------------|--------------|
| **Decision** | Default path | Enabled via config `atlas_search_enabled` or user whitelist |
| **Method** | `buildRefineProductAggregateQuery()` | `buildSearchAggrigation()` |
| **Filter builder** | `search()` → `createFilter()` | `searchAtlas()` → `createFilterAtlas()` |
| **Pipeline stage** | `$match` with MongoDB operators | `$search` with Atlas compound query |
| **Text matching** | `$regex` with `$options: 'i'` | `wildcard` with `allowAnalyzedField: true` |
| **Range** | `$gte`/`$lte` operators | Atlas `range` operator |
| **Existence** | `$exists` | Atlas `exists` in filter array |
| **NOT conditions** | `$ne`, negative regex | `mustNot` array in compound |

### Atlas Search Compound Structure

```php
{
    "$search": {
        "index": "default",
        "compound": {
            "must": [
                // user_id, target_shop_id, source_shop_id (always present)
                // IS_EQUAL_TO conditions
            ],
            "mustNot": [
                // IS_NOT_EQUAL_TO, IS_NOT_CONTAINS conditions
            ],
            "filter": [
                // KEY_EXISTS conditions
            ]
        }
    }
}
```

### Atlas Fallback Fields

These fields fall back to traditional `$match` even when Atlas Search is enabled:
- `tags` — uses `$match` instead of Atlas
- `cif_amazon_multi_variant_attributes` — uses `$match` with `$and`

## Aggregation Pipeline Stages (Standard Path)

Generated by `buildRefineProductAggregateQuery()` in order:

| Order | Stage | Purpose |
|-------|-------|---------|
| 1 | `$match` | user_id + source_shop_id + target_shop_id + type filter |
| 2 | `$sort` | Sort by sortBy field (default: `_id`) |
| 3 | `$match` | Cursor pagination (next: `$gt`, prev: `$gte`) |
| 4 | `$match` | container_ids filter (if provided) |
| 5 | `$addFields` | Parent data exclusion filter (conditional) |
| 6 | `$match` | Main filter conditions (AND/OR based on filterType) |
| 7 | `$match` | OR filter conditions |
| 8 | `$match` | Parallel filter conditions |
| 9 | `$skip` | Offset for activePage pagination |
| 10 | `$project` | Field projection (if project/cif_amazon_multi_project provided) |
| 11 | `$limit` | count + 1 (extra for pagination detection) |

## Cursor Pagination Encoding

Cursors are base64-encoded JSON:

### Next Cursor (decoded)
```json
{
    "cursor": "<MongoDB ObjectId>",
    "pointer": ["<previous cursor values>"],
    "totalPageRead": 3
}
```

### Previous Cursor (decoded)
```json
{
    "cursor": ["<array of ObjectIds for each visited page>"],
    "totalPageRead": 3
}
```

The system fetches `count + 1` results. If the extra document exists, a `next` cursor is generated pointing to it.

## Allowed Filters Config

From `etc/config.php` (~line 97). These fields are recognized for `items.*` nested array mapping:

```php
'allowed_filters' => [
    "shop_id", "source_product_id", "status", "title",
    "variant_title", "sku", "shopify_sku", "quantity",
    "price", "barcode", "main_image", "errors", "is_visible",
    "inventory_tracked", "inventory_policy", "inventory_management",
    "locale", "ai_updated_product", "is_bundle"
]
```

When a field name matches this list, the system may prefix it with `items.` or `marketplace.` for nested array queries.

## Input Sanitization

| Function | Applied To | Purpose |
|----------|-----------|---------|
| `addslashes()` | Regex patterns (IS_CONTAINS, START_FROM, END_FROM) | Escape special characters |
| `checkForSpecialCharacters()` | IS_CONTAINS values | Escape regex metacharacters |
| `checkInteger()` | All filter values | Convert price/quantity to float, others to trimmed string |
| `trim()` | All string values | Remove whitespace |

## Edge Cases & Gotchas

1. **Optimised flag with `false`**: Frontend converts to operator code `2` (IS_NOT_EQUAL_TO) with value `true` — effectively `{$ne: true}`
2. **Not Listed tab**: Uses `cif_amazon_multi_inactive` field (NOT `items.status`) which adds null-checking `$or`
3. **SKU filter**: Always searches both `items.sku` AND `items.shopify_sku` via `$or`
4. **Template filter**: Requires ObjectId conversion — string IDs are cast to `new ObjectId()`
5. **Activity filter**: Value `"error"` maps to `{"items.error": {"$exists": true}}` — it checks field existence, not value
6. **Barcode exists with "0"**: Returns products where barcode is missing, null, OR empty string
7. **Array fields with RANGE**: Use `$elemMatch` wrapper (not flat `$gte`/`$lte`)
8. **Atlas Search fallback**: `tags` and `variant_attributes` always use traditional `$match` even when Atlas is enabled
9. **Pagination**: System always fetches `count + 1` docs — the extra is used for `next` cursor detection, not returned
10. **Type filter** (Stage 1): Excludes `variation` products with only 1 item — `{type: 'variation', items: {$not: {$size: 1}}}` — unless `is_only_parent_allow` is true

## Checklist — Working with Refine Product Filters

- [ ] Filter keys must use numeric string codes (`"1"`, `"3"`, `"10"`) matching the filter constants
- [ ] Special `cif_amazon_multi_*` keys have custom logic — do NOT treat them as generic filters
- [ ] Numeric fields (price, quantity) are auto-converted to float — pass strings, they'll be cast
- [ ] Use `next`/`prev` cursors for pagination, NOT `skip`/`offset` (except `activePage` fallback)
- [ ] Atlas Search requires the `default` index on the `refine_product` collection
- [ ] When adding a new filter: add field to `allowed_filters` in `etc/config.php` if it's an `items.*` field
- [ ] When adding a new special filter key: add handling in `createFilter()` AND `createFilterAtlas()` if Atlas is used
- [ ] Test with both Atlas Search enabled and disabled — logic paths differ significantly
