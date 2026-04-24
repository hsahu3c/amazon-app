---
name: product-filter
description: Product filtering, pagination, and retrieval via getRefineProducts, getRefineProductCount, GetProductProfileMerge, profile-based and ID-based product queries, cursor-based pagination, MongoDB Atlas Search.
---

# Product Filter & Retrieval (Global)

This skill documents the global product filtering, pagination, and retrieval system in the connector module. These APIs and components are used across all marketplaces (Amazon, etc.) for product listing, CSV export, bulk operations, and profile-based workflows.

## Architecture Overview

```
Frontend (React)
  |
  |  POST connector/product/getRefineProducts
  |  POST connector/product/getRefineProductCount
  v
ProductController (connector/controllers/ProductController.php)
  |
  v
ProductContainer Model (connector/Models/ProductContainer.php)
  |
  |-- buildRefineProductAggregateQuery()  --> Standard MongoDB
  |-- buildSearchAggrigation()            --> MongoDB Atlas Search
  v
MongoDB Collections: product_container, refine_product

Profile-Based Retrieval:
GetProductProfileMerge (connector/Components/Profile/GetProductProfileMerge.php)
  |-- getproductsByProfile()      --> Profile-based product fetch
  |-- getproductsByProductIds()   --> Product-ID-based fetch
  |-- getProducts()               --> Unified paginated fetch with variant formatting
```

## Key Paths

| File | Purpose |
|------|---------|
| `controllers/ProductController.php` | Controller: getRefineProductsAction(), getRefineProductCountAction() |
| `Models/ProductContainer.php` | Model: buildRefineProductAggregateQuery(), buildSearchAggrigation(), getRefineProducts(), getRefineProductCount(), search() |
| `Components/Profile/GetProductProfileMerge.php` | Profile-based retrieval: getproductsByProfile(), getproductsByProductIds(), getProducts() |
| `Components/Profile/QueryProducts.php` | Custom query-based retrieval |

## API Endpoints

| Method | Endpoint | Action |
|--------|----------|--------|
| POST | `connector/product/getRefineProducts` | Paginated product listing with filters |
| POST | `connector/product/getRefineProductCount` | Total count for current filters |

## Filter Parameter Structure

```php
$params = [
    // Pagination
    'count' => 20,                    // Items per page (default 20)
    'activePage' => 1,                // Current page number
    'next' => 'base64_string',        // Next cursor token
    'prev' => 'base64_string',        // Prev cursor token

    // Filtering (key => [filterType => value])
    'filter' => [
        'title' => [1 => 'Product Name'],                      // IS_EQUAL_TO
        'price' => [3 => '100'],                                // IS_CONTAINS
        'sku'   => [5 => 'SKU-'],                               // START_FROM
        'quantity' => [7 => ['from' => 10, 'to' => 100]],      // RANGE
    ],
    'or_filter' => [...],             // OR conditions (same format)
    'parallel_filter' => [...],       // Additional parallel filters
    'filterType' => 'and',            // 'and' or 'or'

    // Sorting
    'sortBy' => 'title',              // Field to sort by
    'sortOrder' => 'asc',             // 'asc' or 'desc'

    // Selection
    'source_product_ids' => ['id1', 'id2'],
    'profile_id' => 'profileId',
    'container_ids' => ['container1'],

    // Options
    'useAtlasSearch' => false,        // Use MongoDB Atlas Search index
    'mergeVariants' => false,         // Recursive $graphLookup for variants
    'useForcedRefineProductTable' => false,
    'project' => ['title', 'price'],  // Field projection
    'productOnly' => true,            // Filter to products only

    // Context (auto-injected from headers)
    'source' => ['marketplace' => 'shopify', 'shopId' => '...'],
    'target' => ['marketplace' => 'amazon', 'shopId' => '...'],
    'user_id' => '...'
];
```

## Filter Constants

Defined in `ProductContainer.php`:

| Constant | Value | Operator | Example |
|----------|-------|----------|---------|
| `IS_EQUAL_TO` | 1 | Exact match | `['title' => [1 => 'Shirt']]` |
| `IS_NOT_EQUAL_TO` | 2 | Not equal | `['status' => [2 => 'active']]` |
| `IS_CONTAINS` | 3 | Substring match | `['title' => [3 => 'blue']]` |
| `IS_NOT_CONTAINS` | 4 | Negative substring | `['title' => [4 => 'draft']]` |
| `START_FROM` | 5 | Starts with | `['sku' => [5 => 'AMZ-']]` |
| `END_FROM` | 6 | Ends with | `['sku' => [6 => '-US']]` |
| `RANGE` | 7 | Between values | `['price' => [7 => ['from'=>10,'to'=>100]]]` |
| `KEY_EXISTS` | 12 | Field exists | `['barcode' => [12 => true]]` |

## Response Structure

### getRefineProducts Response
```php
[
    'success' => true,
    'data' => [
        'totalPageRead' => 5,
        'current_count' => 20,
        'next' => 'base64_encoded_cursor',   // null if last page
        'prev' => 'base64_encoded_cursor',
        'rows' => [
            [
                '_id' => ObjectId,
                'source_product_id' => 'string',
                'container_id' => 'string',
                'title' => 'string',
                'type' => 'simple|variation',
                'visibility' => 'Catalog and Search',
                'items' => [...],              // Variants (refine_product)
                'variants' => [...],           // Variants (product_container)
                // ... other product fields
            ]
        ]
    ]
]
```

### getRefineProductCount Response
```php
[
    'success' => true,
    'data' => ['count' => 1500]
]
```

## Pagination

Uses **cursor-based pagination** with base64-encoded tokens:

```php
// Next token (decoded)
[
    'pointer' => [...],           // Array of cursor values
    'totalPageRead' => 3,         // Pages read so far
    'cursor' => ObjectId          // MongoDB ObjectId boundary
]

// Prev token (decoded)
[
    'cursor' => [ObjectId, ...],  // Array of cursor ObjectIds
    'totalPageRead' => 3,
    'pointers' => [...]
]
```

Pagination is handled by `PaginationHelper->getNextPrevData()`. The query always fetches `count + 1` results to determine if more data exists.

## Profile-Based Retrieval (GetProductProfileMerge)

Used by CSV export, bulk operations, and profile workflows.

### getproductsByProfile($data, $directReturnProducts)

Fetches products matching a profile assignment.

```php
$data = [
    'user_id' => '...',
    'profile_id' => 'all_products',   // Or specific profile ObjectId
    'source' => ['marketplace' => '...', 'shopId' => '...'],
    'target' => ['marketplace' => '...', 'shopId' => '...'],
    'limit' => 10,
    'activePage' => 1,
    'mergeVariants' => false,
    'operationType' => 'csvExport',
    'filter' => [...],
    'or_filter' => [...]
];

// Direct return
$result = $merge->getproductsByProfile($data, true);
// Returns: ['rows' => [...], 'next' => '...', 'current_count' => 10, ...]

// SQS async
$merge->getproductsByProfile($data, false);
// Queues via SQS for background processing
```

### getproductsByProductIds($data, $directReturnProducts)

Fetches specific products by their source_product_ids.

```php
$data = [
    'source_product_ids' => ['prod_1', 'prod_2', 'prod_3'],
    // ... same context params as above
];

$result = $merge->getproductsByProductIds($data, true);
```

### Variant Handling

When `mergeVariants = true`:
- Uses `$graphLookup` for recursive multi-level variant fetch
- Groups variants under parent product

When `mergeVariants = false`:
- Uses `$lookup` with subpipeline for single-level variants
- More performant for flat listings

## MongoDB Collections

| Collection | Purpose | Key Fields |
|------------|---------|------------|
| `product_container` | Main product data store | user_id, source_product_id, container_id, shop_id, source_marketplace, type, visibility |
| `refine_product` | Optimized product view for listings | source_shop_id, target_shop_id, items[], source_product_id |
| `profile` | Profile/template definitions | profile_id, name, rules |

## Checklist - Using Product Filters

- [ ] Use `connector/product/getRefineProducts` for paginated listings
- [ ] Use `connector/product/getRefineProductCount` for total count
- [ ] Pass filter constants (1-7, 12) as keys in filter objects
- [ ] Use `next`/`prev` tokens for cursor-based pagination (do NOT use skip/offset)
- [ ] Set `productOnly: true` to exclude non-product entries
- [ ] Use `GetProductProfileMerge` for profile-based or product-ID-based bulk retrieval
- [ ] Set `directReturnProducts = true` for synchronous calls, `false` for SQS async
- [ ] For CSV export: use `getAllProductsRecursively()` (all) or `getProductDetailsRecursive()` (selected)
