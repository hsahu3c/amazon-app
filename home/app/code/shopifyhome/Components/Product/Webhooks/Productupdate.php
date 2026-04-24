<?php

namespace App\Shopifyhome\Components\Product\Webhooks;

use App\Shopifyhome\Components\Core\Common;
use MongoDB\BSON\ObjectId;
use Exception;
use App\Connector\Models\Product as ConnectorProductModel;

class Productupdate extends Common
{
    private $collection;
    private $userId;
    private $shopId;
    private $marketplace;
    private $logFile;
    
    // Performance optimization constants
    private const CHUNK_SIZE = 20;
    private const BULK_WRITE_BATCH = 50;
    
    // Logging configuration
    private $logEnabled = false;
    private $logLevel = 'info'; // info, warning, error, debug
    private $logFileSuffix = 'product_update.log';

    // [container_id] => [variant_id] => [variant_data]
    private $variant_deleted_data = [];
    private $after_product_update_data = [];
    private $update_type = 'updating_product'; // updating_product, variant_to_simple, simple_to_variant

    // debug mode
    private $debugMode = true;
    
    public function initMethod($data)
    {
        // reset the global variables
        $this->variant_deleted_data = [];
        $this->after_product_update_data = [];
        $this->update_type = 'updating_product';
        
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->collection = $mongo->getCollection('product_container');

        $this->logFile = 'shopify/' . $this->userId . '/product' . '/' . date("Y-m-d") . '/product_update.log';

        return $this;
    }

    public function index($data)
    {
        try {
            // Extract basic info
            $this->userId = $data['user_id'] ?? $this->di->getUser()->id;
            $this->shopId = $data['shop_id'] ?? null;
            $this->marketplace = 'shopify';
            
            if (!$this->userId || !$this->shopId) {
                throw new Exception('Missing required user_id or shop_id');
            }
            
            // Process the product listing
            if (!isset($data['data']['product_listing'])) {
                throw new Exception('Missing product_listing data');
            }
            
            $productListing = $data['data']['product_listing'];
            $productId = $productListing['product_id'] ?? 'unknown';
            $variantCount = count($productListing['variants'] ?? []);
            
            $this->writeLog(
                "Starting product update - Product ID: {$productId}, Variants: {$variantCount}, User: {$this->userId}",
                'info',
                ['product_id' => $productId, 'variant_count' => $variantCount]
            );
            
            $result = $this->processProductUpdate($productListing, $data);
            
            $this->writeLog(
                "Product update completed - Product ID: {$productId}, Success: " . ($result['success'] ? 'true' : 'false'),
                'info',
                ['product_id' => $productId, 'success' => $result['success']]
            );
            
            return [
                'success' => $result['success'],
                'message' => $result['message'] ?? 'Product updated successfully',
                'stats' => $result['stats'] ?? []
            ];
            
        } catch (Exception $e) {
            $this->writeLog(
                'ProductUpdate Error: ' . $e->getMessage() . ' User: ' . ($this->userId ?? 'unknown'),
                'error',
                ['error' => $e->getMessage(), 'user_id' => $this->userId ?? 'unknown']
            );
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    private function checkProductType($productListing)
    {
        $variants = $productListing['variants'] ?? [];
        $variantCount = count($variants);
        if ( $variantCount > 1 ) {
            return 'variation';
        }
        // check option values
        $optionValues = $productListing['options'] ?? $productListing['option_values'] ?? [];
        $optionValueCount = count($optionValues);

        // check if option values is 1 and title is default title
        // TODO : Add Default value Check
        if ( $optionValueCount == 1 && strtolower($optionValues[0]['name']) == 'title' ) {
            return 'simple';
        }
        if ( $optionValueCount > 0 ) {
            return 'variation';
        }
        // if ( $variantCount == 1 && isset($productListing[0]) && strtolower($productListing[0]['title']) == 'default title' ) {
        //     return 'simple';
        // }
        return 'simple';
    }
    
    /**
     * Main product update processor with optimizations
     */
    private function processProductUpdate($productListing, $fullData)
    {
        $startTime = microtime(true);
        $stats = ['variants_processed' => 0, 'db_queries' => 0, 'memory_peak' => 0];
        
        // Extract product data
        $productId = (string)$productListing['product_id'];
        $variantCount = count($productListing['variants'] ?? []);
        $sourceProductId = null;

        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
        // Step 1: Determine product type
        $newProductType = $this->checkProductType($productListing);
        
        $this->writeLog(
            "Processing product update - Type: {$newProductType}, Variants: {$variantCount}",
            'info',
            ['product_type' => $newProductType, 'variant_count' => $variantCount]
        );
        
        // Step 2: Get existing product data efficiently (single query with projection)
        $existingData = $this->getExistingProductData($productId);
        $stats['db_queries']++;
        
        if (empty($existingData)) {
            $this->writeLog(
                "Product not found for update - Product ID: {$productId}",
                'warning',
                ['product_id' => $productId]
            );
            return [
                'success' => false,
                'message' => 'Product not found for update'
            ];
        }
        
        // Step 3: Detect type conversion scenario
        $conversionType = $this->detectTypeConversion($existingData, $newProductType);

        $this->writeLog(
            "Conversion type detected: {$conversionType}",
            'info',
            ['conversion_type' => $conversionType]
        );

        // Step 3.5: Middleware - resolve duplicate SKU by adding counter (same as ProductHelper), do not skip update
        $this->resolveDuplicateSkus($productListing, $existingData);

        // Step 4: Process based on conversion type
        switch ($conversionType) {
            case 'simple_to_variant':
                $deleteSourceDoc = [ 
                    'deleteArray' => [
                        [
                            'source_shop_id' => $this->shopId, 
                            'type' => 'Catalog and Search', 
                            'source_product_id' => $existingData['parent']['source_product_id']
                        ]
                    ]
                ];
                $helper->marketplaceDelete($deleteSourceDoc);
                $this->update_type = 'simple_to_variant';
                $result = $this->handleSimpleToVariant($productListing, $existingData, $fullData);
                
                break;
                
            case 'variant_to_simple':
                $deleteSourceDoc = [ 
                    'deleteArray' => [
                        [
                            'source_shop_id' => $this->shopId, 
                            'type' => 'Catalog and Search', 
                            'source_product_id' => $existingData['parent']['source_product_id']
                        ]
                    ]
                ];
                $helper->marketplaceDelete($deleteSourceDoc);
                $this->update_type = 'variant_to_simple';
                $result = $this->handleVariantToSimple($productListing, $existingData, $fullData);
                $sourceProductId = (string)($productListing['variants'][0]['id'] ?? $productListing['id']);
                break;
                
            case 'variant_update':
                $result = $this->handleVariantUpdate($productListing, $existingData, $fullData);
                break;
                
            case 'simple_update':
                $result = $this->handleSimpleUpdate($productListing, $existingData, $fullData);
                $sourceProductId = (string)($productListing['variants'][0]['id'] ?? $productListing['id']);
                break;
                
            default:
                $this->writeLog(
                    "Unknown conversion type: {$conversionType}",
                    'error',
                    ['conversion_type' => $conversionType]
                );
                return ['success' => false, 'message' => 'Unknown conversion type'];
        }

        if ( $this->debugMode ) {
            $stats['afterConversionType'] = [
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024,
                'execution_time' => round(microtime(true) - $startTime, 3)
            ];
        }

        // step 4: event after variant remove
        $this->eventAfterVariantRemove();
        if ( $this->debugMode ) {
            $stats['afterVariantRemove'] = [
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024,
                'execution_time' => round(microtime(true) - $startTime, 3)
            ];
        }

        // step 5: event after product update
        $this->eventAfterProductUpdate();
        if ( $this->debugMode ) {
            $stats['afterProductUpdate'] = [
            'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024,
                'execution_time' => round(microtime(true) - $startTime, 3)
            ];
        }

        // step 6: update refine_product table
        $updateData = [
            "source_product_id" => $sourceProductId ? (string)$sourceProductId : (string)$productId, // required
            'user_id' => $this->userId,
            'childInfo' => [
                'source_product_id' => $sourceProductId ? (string)$sourceProductId : (string)$productId, // required
                'container_id' => $productListing['product_id'], // required
                'shop_id' => $this->shopId, // required
                'source_marketplace' => $this->marketplace // required
            ]
        ];

        $response = $helper->marketplaceSaveAndUpdate($updateData, [$productId]);
        if ( $this->debugMode ) {
            $stats['marketplaceSaveAndUpdate'] = [
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024,
                'execution_time' => round(microtime(true) - $startTime, 3)
            ];
        }

        // Calculate stats
        $stats['variants_processed'] = $existingData['variant_count'];
        $stats['db_queries'] += $result['queries'] ?? 0;
        $stats['refine_product_update'] = $response;
        
        $this->writeLog(
            "Product update stats - Queries: {$stats['db_queries']}, Memory: {$stats['memory_peak']}MB, Time: {$stats['execution_time']}s",
            'info',
            ['queries' => $stats['db_queries'], 'memory_mb' => $stats['memory_peak'], 'execution_time' => $stats['execution_time']]
        );
        if ( $this->debugMode ) {
            $stats['total'] = [
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024,
                'execution_time' => round(microtime(true) - $startTime, 3)
            ];
        } else {
            $stats['execution_time'] = round(microtime(true) - $startTime, 3);
        }
        return array_merge($result, ['stats' => $stats]);
    }
    
    /**
     * Get existing product data with minimal memory usage
     */
    private function getExistingProductData($productId)
    {
        // Use projection to get only necessary fields
        $projection = [
            'projection' => [
                '_id' => 1,
                'container_id' => 1,
                'type' => 1,
                'visibility' => 1,
                'source_product_id' => 1,
                'variant_attributes' => 1,
                'shop_id' => 1,
                'marketplace' => 1,
                'app_codes' => 1,
                'sku' => 1,
                'low_sku' => 1,
                'duplicate_sku' => 1,
                'source_marketplace' => 1
            ]
        ];
        
        // Get all related products (parent and variants)
        $products = $this->collection->find(
            [
                'user_id' => $this->userId,
                'shop_id' => $this->shopId,
                'container_id' => $productId,
                'source_marketplace' => $this->marketplace
            ],
            $projection
        )->toArray();

        if (empty($products)) {
            return [];
        }
        
        // Build efficient lookup structure
        $data = [
            'products' => [],
            'parent' => null,
            'variants' => [],
            'variant_count' => 0
        ];

        foreach ($products as $product) {
            $sourceId = (string)$product['source_product_id'];
            $data['products'][$sourceId] = $product;
            
            if ($product['visibility'] == ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH) {
                $data['parent'] = $product;
            } else {
                $data['variants'][] = $sourceId;
            }
        }
        
        $data['variant_count'] = count($data['variants']);
        return $data;
    }
    
    /**
     * Detect type conversion scenario
     */
    private function detectTypeConversion($existingData, $newProductType)
    {
        $existingType = $existingData['parent']['type'] ?? 'simple';
        $existingVariantCount = $existingData['variant_count'];
        
        if ($existingType === 'simple' && $newProductType === 'variation') {
            return 'simple_to_variant';
        }
        
        if ($existingType === 'variation' && $newProductType === 'simple') {
            return 'variant_to_simple';
        }
        
        if ($existingType === 'variation' && $newProductType === 'variation') {
            return 'variant_update';
        }
        
        return 'simple_update';
    }

    private function checkIdUnderscoreIdisFromMongoDb($id)
    {
        // check if id is from mongo db
        // it can be object id or string
        if (preg_match('/^[a-f\d]{24}$/i', (string)$id)) {
            return new \MongoDB\BSON\ObjectId($id);
        }
        return $id;
    }
    
    /**
     * Handle simple to variant conversion (optimized)
     */
    private function handleSimpleToVariant($productListing, $existingData, $fullData)
    {
        $bulkOps = [];
        $queries = 0;
        
        $this->writeLog(
            "Starting simple to variant conversion - Product ID: {$productListing['product_id']}",
            'info',
            ['product_id' => $productListing['product_id']]
        );
        
        try {
            // 1. Convert existing simple product to parent
            $parentUpdate = $this->prepareParentFromSimple($existingData['parent'], $productListing);
            $this->after_product_update_data[$productListing['product_id']] = [];
            $bulkOps[] = [
                'updateOne' => [
                    [
                        '_id' => $this->checkIdUnderscoreIdisFromMongoDb($existingData['parent']['_id'])
                        // 'user_id' => $this->userId,
                        // 'shop_id' => $this->shopId,
                        // 'source_product_id' => (string)$existingData['parent']['source_product_id'],
                        // 'container_id' => (string)$existingData['parent']['container_id']
                    ],
                    $parentUpdate
                ]
            ];
            
            // 2. Process variants in chunks to manage memory
            $variants = $productListing['variants'];
            $variantChunks = array_chunk($variants, self::CHUNK_SIZE);
            
            foreach ($variantChunks as $chunk) {
                $chunkOps = $this->processVariantChunk($chunk, $productListing, $fullData, $existingData['parent']);
                $bulkOps = array_merge($bulkOps, $chunkOps);
                
                // Execute bulk write when batch size reached
                if (count($bulkOps) >= self::BULK_WRITE_BATCH) {
                    $this->executeBulkWrite($bulkOps);
                    $queries++;
                    $bulkOps = [];
                }
                
                // Free memory
                unset($chunk, $chunkOps);
            }
            
            // Execute remaining operations
            if (!empty($bulkOps)) {
                $this->executeBulkWrite($bulkOps);
                $queries++;
            }
            
            $this->writeLog(
                "Simple to variant conversion completed - Queries: {$queries}",
                'info',
                ['queries' => $queries]
            );
            
            return [
                'success' => true,
                'message' => 'Successfully converted simple to variant product',
                'queries' => $queries
            ];
            
        } catch (Exception $e) {
            $this->writeLog(
                "Simple to variant conversion failed: " . $e->getMessage(),
                'error',
                ['error' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'Simple to variant conversion failed: ' . $e->getMessage(),
                'queries' => $queries
            ];
        }
    }
    
    /**
     * Handle variant to simple conversion (optimized)
     */
    private function handleVariantToSimple($productListing, $existingData, $fullData)
    {
        $bulkOps = [];
        $queries = 0;
        
        $this->writeLog(
            "Starting variant to simple conversion - Product ID: {$productListing['product_id']}",
            'info',
            ['product_id' => $productListing['product_id']]
        );
        
        try {
            // 1. Convert parent to simple product
            $simpleUpdate = $this->prepareSimpleFromVariant($existingData['parent'], $productListing);
            $this->after_product_update_data[$productListing['product_id']] = [];
            $this->after_product_update_data[$productListing['product_id']][$simpleUpdate['$set']['source_product_id']] = $simpleUpdate;
            $bulkOps[] = [
                'updateOne' => [
                    [
                        '_id' => $this->checkIdUnderscoreIdisFromMongoDb($existingData['parent']['_id'])
                        // 'user_id' => $this->userId,
                        // 'shop_id' => $this->shopId,
                        // 'source_product_id' => (string)$existingData['parent']['source_product_id'],
                        // 'container_id' => (string)$existingData['parent']['container_id']
                    ],
                    $simpleUpdate
                ]
            ];

            // step 2 : whilte Conventring check sku with edited docs and update it with new Id
            $this->updateSkuWithNewId($simpleUpdate['$set']);

            // STEP 3: event after variant remove
            $this->variant_deleted_data[$productListing['product_id']] = [];
            foreach ($existingData['products'] as $variant) {
                if ( $variant['source_product_id'] == $existingData['parent']['source_product_id'] ) 
                continue;
                $this->variant_deleted_data[$productListing['product_id']][$variant['source_product_id']] = $variant;
            }
            
            // 3. Delete all child variants
            if (!empty($existingData['variants'])) {
                $bulkOps[] = [
                    'deleteMany' => [
                        [
                            'user_id' => $this->userId,
                            'shop_id' => $this->shopId,
                            'visibility' => ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY,
                            'container_id' => (string)$productListing['product_id'],
                            'source_product_id' => ['$in' => $existingData['variants']]
                        ]
                    ]
                ];
            }
            
            // 5. Execute bulk operations
            $this->executeBulkWrite($bulkOps);
            $queries++;
            
            $this->writeLog(
                "Variant to simple conversion completed - Queries: {$queries}",
                'info',
                ['queries' => $queries]
            );
            
            return [
                'success' => true,
                'message' => 'Successfully converted variant to simple product',
                'queries' => $queries
            ];
            
        } catch (Exception $e) {
            $this->writeLog(
                "Variant to simple conversion failed: " . $e->getMessage(),
                'error',
                ['error' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'Variant to simple conversion failed: ' . $e->getMessage(),
                'queries' => $queries
            ];
        }
    }
    
    /**
     * Handle regular variant update (optimized)
     */
    private function handleVariantUpdate($productListing, $existingData, $fullData)
    {
        $bulkOps = [];
        $queries = 0;
        
        $variantCount = count($productListing['variants'] ?? []);
        $this->writeLog(
            "Starting variant update - Product ID: {$productListing['product_id']}, Variants: {$variantCount}",
            'info',
            ['product_id' => $productListing['product_id'], 'variant_count' => $variantCount]
        );
        
        try {
            // 1. Update parent product
            $parentUpdate = $this->prepareParentUpdate($productListing);
            $this->after_product_update_data[$productListing['product_id']] = [];
            // if also want to store the parent update in the after_product_update_data
            // $this->after_product_update_data[$productListing['product_id']][$parentUpdate['source_product_id']] = $parentUpdate;
            $bulkOps[] = [
                'updateOne' => [
                    [
                        'user_id' => $this->userId,
                        'shop_id' => $this->shopId,
                        'source_product_id' => (string)$existingData['parent']['source_product_id'],
                        'container_id' => (string)$existingData['parent']['container_id']
                    ],
                    ['$set' => $parentUpdate]
                ]
            ];
            
            // 2. Build efficient variant lookup
            $existingVariantIds = array_flip($existingData['variants']);
            $newVariantIds = [];
            
            // 3. Process variants in chunks
            $variants = $productListing['variants'];
            $variantChunks = array_chunk($variants, self::CHUNK_SIZE);
            foreach ($variantChunks as $chunk) {
                foreach ($chunk as $variant) {
                    $variantId = (string)$variant['id'];
                    $newVariantIds[$variantId] = true;
                    $variant['variant_image'] = $this->extractVariantImage($variant, $productListing);
                    
                    if (isset($existingVariantIds[$variantId])) {
                        // Update existing variant
                        $update = $this->prepareVariantUpdate($variant, $productListing, $fullData, $parentUpdate);
                        $this->after_product_update_data[$productListing['product_id']][$variantId] = $update;
                        $bulkOps[] = [
                            'updateOne' => [
                                [
                                    'user_id' => $this->userId, 
                                    'shop_id' => $this->shopId, 
                                    'source_product_id' => (string)$variantId, 
                                    'container_id' => (string)$productListing['product_id']
                                ],
                                ['$set' => $update]
                            ]
                        ];
                    } else {
                        // Insert new variant
                        $newVariant = $this->prepareNewVariant($variant, $productListing, $fullData, $parentUpdate);
                        $this->after_product_update_data[$productListing['product_id']][$variantId] = $newVariant;
                        $bulkOps[] = [
                            'insertOne' => [$newVariant]
                        ];
                    }
                }
                
                // Execute bulk write when batch size reached
                if (count($bulkOps) >= self::BULK_WRITE_BATCH) {
                    $this->executeBulkWrite($bulkOps);
                    $queries++;
                    $bulkOps = [];
                }
            }
            
            // 4. Delete removed variants
            $deletedVariants = array_diff(array_keys($existingVariantIds), array_keys($newVariantIds));
            if (!empty($deletedVariants)) {
                // Convert all variant IDs to strings and filter out the ones that are not in the existingVariantIds
                $deletedVariants = array_values(array_map('strval', $deletedVariants));
                $this->variant_deleted_data[$productListing['product_id']] = [];

                foreach ($deletedVariants as $variant) {
                    $this->variant_deleted_data[$productListing['product_id']][$variant] = $existingData['products'][$variant];
                }
                $bulkOps[] = [
                    'deleteMany' => [
                        [
                            'user_id' => $this->userId, 
                            'shop_id' => $this->shopId,
                            'visibility' => ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY,
                            'source_product_id' => ['$in' => $deletedVariants],
                            'container_id' => (string)$productListing['product_id']
                        ]
                    ]
                ];
            }
            // Execute remaining operations
            if (!empty($bulkOps)) {
                $this->executeBulkWrite($bulkOps);
                $queries++;
            }
            
            $this->writeLog(
                "Variant update completed - Queries: {$queries}",
                'info',
                ['queries' => $queries]
            );
            
            return [
                'success' => true,
                'message' => 'Successfully updated variant product',
                'queries' => $queries
            ];
            
        } catch (Exception $e) {
            $this->writeLog(
                "Variant update failed: " . $e->getMessage(),
                'error',
                ['error' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'Variant update failed: ' . $e->getMessage(),
                'queries' => $queries
            ];
        }
    }

    /**
     * Extract variant image from product listing
     */
    private function extractVariantImage($variant, $productListing) {
        $image_id = $variant['image_id'] ?? '';
        if($image_id != '') {
            $images = $productListing['images'] ?? [];
            foreach ($images as $image) {
                if ($image['id'] == $image_id) {
                    return $image['src'] ?? null;
                }
            }
        }
        return null;
    }
    
    /**
     * Handle simple product update (optimized)
     */
    private function handleSimpleUpdate($productListing, $existingData, $fullData)
    {
        $this->writeLog(
            "Starting simple product update - Product ID: {$productListing['product_id']}",
            'info',
            ['product_id' => $productListing['product_id']]
        );
        
        try {
            $variant = $productListing['variants'][0] ?? [];
            $update = $this->prepareSimpleProductUpdate($productListing, $variant);
            $this->after_product_update_data[$productListing['product_id']] = [];
            $this->after_product_update_data[$productListing['product_id']][$update['source_product_id']] = $update;

            $this->collection->updateOne(
                [
                    'user_id' => $this->userId,
                    'shop_id' => $this->shopId,
                    'source_product_id' => (string)$existingData['parent']['source_product_id'],
                    'container_id' => (string)$existingData['parent']['container_id']
                ],
                ['$set' => $update]
            );
            
            $this->writeLog(
                "Simple product update completed",
                'info'
            );
            
            return [
                'success' => true,
                'message' => 'Successfully updated simple product',
                'queries' => 1
            ];
            
        } catch (Exception $e) {
            $this->writeLog(
                "Simple product update failed: " . $e->getMessage(),
                'error',
                ['error' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'Simple update failed: ' . $e->getMessage(),
                'queries' => 0
            ];
        }
    }
    
    /**
     * Process variant chunk efficiently
     */
    private function processVariantChunk($variants, $productListing, $fullData, $parentData)
    {
        $operations = [];
        
        foreach ($variants as $variant) {
            $variantData = $this->prepareNewVariant($variant, $productListing, $fullData, $parentData);
            $this->after_product_update_data[$productListing['product_id']][$variant['id']] = $variantData;
            $operations[] = [
                'insertOne' => [$variantData]
            ];
        }
        
        return $operations;
    }
    
    /**
     * Prepare parent update from simple product
     */
    private function prepareParentFromSimple($existing, $productListing)
    {
        $sku = !empty($productListing['sku']) ? (string)$productListing['sku'] : (string)$productListing['product_id'];
        $set = [
            'type' => 'variation',
            'container_id' => (string)$productListing['product_id'],
            'source_product_id' => (string)$productListing['product_id'],
            'variant_attributes' => $this->extractVariantAttributes($productListing),
            'source_marketplace' => $this->marketplace,
            'source_sku' => $sku,
            'sku' => $sku,
            'tags' => $this->extractTags($productListing),
            'title' => $productListing['title'] ?? '',
            'description' => $productListing['body_html'] ?? '',
            'brand' => $productListing['vendor'] ?? '',
            'product_type' => $productListing['product_type'] ?? '',
            'handle' => $productListing['handle'] ?? '',
            'updated_at' => date('c'),
            'source_created_at' => $productListing['created_at'] ?? null,
            'source_updated_at' => $productListing['updated_at'] ?? null,
            'low_sku' => strtolower($sku),
            'update_version' => 2.0,
        ];
        if (!empty($productListing['_duplicate_sku'])) {
            $set['duplicate_sku'] = true;
        } else {
            $set['duplicate_sku'] = null;
        }
        return [
            '$set' => $set,
            '$unset' => [
                'price' => '',
                'quantity' => '',
                'barcode' => '',
                'variant_image' => '',
                // 'low_sku' => '',
            ]
        ];
    }
    
    /**
     * Prepare simple update from variant product
     */
    private function prepareSimpleFromVariant($existing, $productListing)
    {
        $variant = $productListing['variants'][0] ?? [];
        $sku = !empty($variant['sku']) ? (string)$variant['sku'] : (string)$variant['id'];

        $mapWeightUnit = static function ($unit) {
            $u = strtoupper((string)$unit);
            return match ($u) {
                'KG', 'KILOGRAM', 'KILOGRAMS' => 'KILOGRAMS',
                'G', 'GRAM', 'GRAMS' => 'GRAMS',
                'LB', 'LBS', 'POUND', 'POUNDS' => 'POUNDS',
                'OZ', 'OUNCE', 'OUNCES' => 'OUNCES',
                default => $u,
            };
        };
        $set = [
            'type' => 'simple',
            'price' => (float)$variant['price'] ?? 0,
            'quantity' => (int)$variant['inventory_quantity'] ?? 0,
            'sku' => $sku,
            'updated_at' => date('c'),
            'compare_at_price' => (float)$variant['compare_at_price'] ?? 0,
            'inventory_management' => $variant['inventory_management'] ?? '',
            'inventory_policy' => $variant['inventory_policy'] ?? 'deny',
            'inventory_tracked' => $this->getInventoryTracked($variant),
            'weight' => $variant['weight'] ?? 0,
            'weight_unit' => $mapWeightUnit($variant['weight_unit'] ?? null),
            'grams' => $variant['grams'] ?? 0,
            'barcode' => $variant['barcode'] ?? null,
            'requires_shipping' => $variant['requires_shipping'] ?? true,
            'taxable' => $variant['taxable'] ?? true,
            'template_suffix' => $variant['template_suffix'] ?? null,
            'fulfillment_service' => $variant['fulfillment_service'] ?? '',
            'published_at' => $variant['published_at'] ?? null,
            'seo' => $variant['seo'] ?? null,
            'tags' => $this->extractTags($productListing),
            'product_type' => $productListing['product_type'] ?? '',
            'handle' => $productListing['handle'] ?? '',
            'brand' => $productListing['vendor'] ?? '',
            'description' => $productListing['body_html'] ?? '',
            'title' => $productListing['title'] ?? '',
            'container_id' => (string)$productListing['product_id'],
            'source_product_id' => (string)$variant['id'],
            'source_sku' => $sku,
            'low_sku' => strtolower($sku),
            'update_version' => 2.0
        ];
        if (!empty($variant['_duplicate_sku'])) {
            $set['duplicate_sku'] = true;
        } else {
            $set['duplicate_sku'] = null;
        }
        return [
            '$set' => $set,
            '$unset' => [
                'variant_attributes' => '',
                'variant_attributes_values' => '',
                'variant_title' => '',
                'variant_image' => '',
                // 'low_sku' => '',
            ]
        ];
    }
    
    /**
     * Prepare parent product update
     */
    private function prepareParentUpdate($productListing)
    {
        $mapWeightUnit = static function ($unit) {
            $u = strtoupper((string)$unit);
            return match ($u) {
                'KG', 'KILOGRAM', 'KILOGRAMS' => 'KILOGRAMS',
                'G', 'GRAM', 'GRAMS' => 'GRAMS',
                'LB', 'LBS', 'POUND', 'POUNDS' => 'POUNDS',
                'OZ', 'OUNCE', 'OUNCES' => 'OUNCES',
                default => $u,
            };
        };
        $sku = !empty($productListing['sku']) ? (string)$productListing['sku'] : (string)$productListing['product_id'];
        $data = [
            'title' => $productListing['title'] ?? '',
            'description' => $productListing['body_html'] ?? '',
            'brand' => $productListing['vendor'] ?? '',
            'product_type' => $productListing['product_type'] ?? '',
            'tags' => $this->extractTags($productListing),
            'handle' => $productListing['handle'] ?? '',
            'variant_attributes' => $this->extractVariantAttributes($productListing),
            'variant_attributes_values' => $this->extractVariantAttributesValues($productListing),
            'main_image' => $this->extarctMainImage($productListing),
            'additional_images' => $this->extarctAdditionalImages($productListing),
            'updated_at' => date('c'),
            'source_created_at' => $productListing['created_at'] ?? null,
            'source_updated_at' => $productListing['updated_at'] ?? null,
            'source_marketplace' => $this->marketplace,
            'source_product_id' => (string)$productListing['product_id'],
            'source_sku' => $sku,
            'low_sku' => (string)strtolower($sku),
            'sku' => $sku,
            'weight' => $productListing['weight'] ?? 0,
            'weight_unit' => $mapWeightUnit($productListing['weight_unit'] ?? null),
            'grams' => $productListing['grams'] ?? 0,
            'update_version' => 2.0
        ];
        if (!empty($productListing['_duplicate_sku'])) {
            $data['duplicate_sku'] = true;
        } else {
            $data['duplicate_sku'] = null;
        }
        return $data;
    }
    
    /**
     * Prepare variant update
     * {
        *"_id": "1331912",
        *"type": "simple",
        *"source_product_id": "45462965649663",
        *"container_id": "8633095454975",
        *"user_id": "6642f37d18d76dcd0e0b0555",
        *"shop_id": "387",
        *"additional_images": [],
        *"app_codes": [
        **"amazon_sales_channel"
        *],
        *"barcode": null,
        *"brand": "Snowboard Vendor",
        *"collection": [
        **{
        ***"collection_id": "428054249727",
        ***"title": "Automated Collection",
        ***"__parentId": "gid://shopify/Product/8633095454975",
        ***"gid": "gid://shopify/Collection/428054249727"
        **}
        *],
        *"compare_at_price": null,
        *"created_at": "2024-12-17 10:13:50",
        *"description": "This <b>PREMIUM</b> <i>snowboard</i> is so <b>SUPER</b><i>DUPER</i> awesome!",
        *"fulfillment_service": "manual",
        *"grams": 4536,
        *"handle": "the-complete-snowboard",
        *"inventory_item_id": "47532409848063",
        *"inventory_management": "shopify",
        *"inventory_policy": "DENY",
        *"inventory_tracked": true,
        *"low_sku": "45462965649663",
        *"main_image": "https://cdn.shopify.com/s/files/1/0699/7347/5583/files/Main_589fc064-24a2-4236-9eaf-13b2bd35d21d.jpg?v=1715663519",
        *"position": 1,
        *"price": 699.95,
        *"product_type": "snowboard",
        *"published_at": "2024-09-13T11:27:45Z",
        *"quantity": 10,
        *"requires_shipping": true,
        *"seo": {
        **"description": "snowboard winter sport snowboarding",
        **"title": "Complete Snowboard"
        *},
        *"sku": "45462965649663",
        *"source_created_at": "2024-05-14T05:11:59Z",
        *"source_marketplace": "shopify",
        *"source_sku": "45462965649663",
        *"source_updated_at": "2024-05-14T05:11:59Z",
        *"tags": [
        **"24May",
        **"Premium",
        **"Snow",
        **"Snowboard",
        **"Sport",
        **"Winter"
        *],
        *"taxable": true,
        *"template_suffix": null,
        *"title": "The Complete Snowboard\t The Complete Snowboard\t The Complete Snowboard\t The Complete Snowboard\t The Complete Snowboard\t The Complete Snowboard\t The Complete Snowboard\t The Complete Snowboard\t The Complete Snowboard\t The Complete Snowboardplete Snowb",
        *"updated_at": "2024-12-17T10:13:50+0000",
        *"variant_attributes": [
        **{
        ***"key": "Color",
        ***"value": "Ice"
        **}
        *],
        *"variant_image": null,
        *"variant_title": "Ice",
        *"visibility": "Not Visible Individually",
        *"weight": 10,
        *"weight_unit": "POUNDS"
        *}
*   */
    private function prepareVariantUpdate($variant, $productListing, $fullData, $parentData)
    {

        // weight maping
        $mapWeightUnit = static function ($unit) {
            $u = strtoupper((string)$unit);
            return match ($u) {
                'KG', 'KILOGRAM', 'KILOGRAMS' => 'KILOGRAMS',
                'G', 'GRAM', 'GRAMS' => 'GRAMS',
                'LB', 'LBS', 'POUND', 'POUNDS' => 'POUNDS',
                'OZ', 'OUNCE', 'OUNCES' => 'OUNCES',
                default => $u,
            };
        };
        $extarctVariantAttributes = $this->extractVariantAttributesValues($variant);
        $sku = !empty($variant['sku']) ? (string)$variant['sku'] : (string)$variant['id'];
        $data = [
            'price' => (float)$variant['price'] ?? 0,
            'compare_at_price' => (float)$variant['compare_at_price'] ?? 0,
            'sku' => $sku,
            'barcode' => $variant['barcode'] ?? null,
            'quantity' => (int)$variant['inventory_quantity'] ?? 0,
            'weight' => $variant['weight'] ?? 0,
            'weight_unit' => $mapWeightUnit($variant['weight_unit'] ?? null),
            'inventory_management' => $variant['inventory_management'] ?? '',
            'inventory_policy' => $variant['inventory_policy'] ?? 'deny',
            'inventory_tracked' => $this->getInventoryTracked($variant),
            'grams' => $variant['grams'] ?? 0,
            'requires_shipping' => $variant['requires_shipping'] ?? true,
            'template_suffix' => $variant['template_suffix'] ?? null,
            'fulfillment_service' => $variant['fulfillment_service'] ?? '',
            'published_at' => $variant['published_at'] ?? null,
            'seo' => $variant['seo'] ?? null,
            'variant_image' => $variant['variant_image'] ?? null,
            'variant_title' => $variant['title'] ?? '',
            'handle' => $parentData['handle'] ?? $productListing['handle'] ?? '',
            'product_type' => $parentData['product_type'] ?? $productListing['product_type'] ?? '',
            'brand' => $parentData['brand'] ?? $productListing['vendor'] ?? '',
            'description' => $parentData['description'] ?? $productListing['body_html'] ?? '',
            'title' => $parentData['title'] ?? '',
            'type' => 'simple',
            'visibility' => ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY,
            'updated_at' => date('c'),
            'variant_attributes' => $extarctVariantAttributes ?? [],
            'low_sku' => strtolower($sku),
            'main_image' => $variant['variant_image'] ?? $parentData['main_image'] ?? $this->extarctMainImage($productListing),
            'additional_images' => $parentData['additional_images'] ?? $this->extarctAdditionalImages($productListing),
            'source_created_at' => $variant['created_at'] ?? null,
            'source_updated_at' => $variant['updated_at'] ?? null,
            'source_marketplace' => $this->marketplace,
            'source_product_id' => (string)$variant['id'],
            'source_sku' => $sku,
            'update_version' => 2.0,
            'taxable' => $variant['taxable'] ?? true,
            'container_id' => $parentData['container_id'] ?? (string)$productListing['product_id'],
            'tags' => $parentData['tags'] ?? $this->extractTags($productListing),
        ];
        if (!empty($variant['_duplicate_sku'])) {
            $data['duplicate_sku'] = true;
        } else {
            $data['duplicate_sku'] = null;
        }
        return $data;
    }
    
    /**
     * Prepare new variant data
     */
    private function prepareNewVariant($variant, $productListing, $fullData, $parentData)
    {
        // TODO :  Add the location and inventory item id from Shopify get Product API, GraphQL API
        $mapWeightUnit = static function ($unit) {
            $u = strtoupper((string)$unit);
            return match ($u) {
                'KG', 'KILOGRAM', 'KILOGRAMS' => 'KILOGRAMS',
                'G', 'GRAM', 'GRAMS' => 'GRAMS',
                'LB', 'LBS', 'POUND', 'POUNDS' => 'POUNDS',
                'OZ', 'OUNCE', 'OUNCES' => 'OUNCES',
                default => $u,
            };
        };
        $sku = !empty($variant['sku']) ? (string)$variant['sku'] : (string)$variant['id'];
        $productContainer = $this->di->getObjectManager()->get(\App\Connector\Models\ProductContainer::class);
        $variantData = [
            '_id' => (string) $productContainer->getCounter('product_id'),
            'user_id' => $this->userId,
            'shop_id' => $this->shopId,
            'container_id' => $parentData['container_id'] ?? (string)$productListing['product_id'],
            'source_product_id' => (string)$variant['id'],
            'source_marketplace' => $this->marketplace,
            'type' => 'simple',
            'visibility' => ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY,
            'title' => $productListing['title'] ?? '',
            'variant_title' => $variant['title'] ?? '',
            'price' => (float)$variant['price'] ?? 0,
            'sku' => $sku,
            'quantity' => (int)$variant['inventory_quantity'] ?? 0,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'source_created_at' => $variant['created_at'] ?? null,
            'source_updated_at' => $variant['updated_at'] ?? null,
            'source_sku' => $sku,
            'update_version'=> 2.0,
            'tags' => $parentData['tags'] ?? $this->extractTags($productListing),
            'variant_attributes' => $this->extractVariantAttributesValues($variant),
            'low_sku' => strtolower($sku),
            'main_image' => $variant['variant_image'] ?? $parentData['main_image'] ?? $this->extarctMainImage($productListing),
            'additional_images' => $parentData['additional_images'] ?? $this->extarctAdditionalImages($productListing),
            'inventory_management' => $variant['inventory_management'] ?? '',
            'inventory_policy' => $variant['inventory_policy'] ?? 'deny',
            'inventory_tracked' => $this->getInventoryTracked($variant),
            'weight' => $variant['weight'] ?? 0,
            'weight_unit' => $mapWeightUnit($variant['weight_unit'] ?? null),
            'grams' => $variant['grams'] ?? 0,
            'compare_at_price' => (float)$variant['compare_at_price'] ?? 0,
            'barcode' => $variant['barcode'] ?? null,
            'position' => $variant['position'] ?? 0,
            'requires_shipping' => $variant['requires_shipping'] ?? true,
            'taxable' => $variant['taxable'] ?? true,
            'template_suffix' => $variant['template_suffix'] ?? null,
            'fulfillment_service' => $variant['fulfillment_service'] ?? '',
            'published_at' => $variant['published_at'] ?? null,
            'seo' => $variant['seo'] ?? null,
            'variant_image' => $variant['variant_image'] ?? null,
            'brand' => $parentData['brand'] ?? $productListing['vendor'] ?? '',
            'description' => $parentData['description'] ?? $productListing['body_html'] ?? '',
            'handle' => $parentData['handle'] ?? $productListing['handle'] ?? '',
            'product_type' => $parentData['product_type'] ?? $productListing['product_type'] ?? '',
            
        ];
        if (!empty($variant['_duplicate_sku'])) {
            $variantData['duplicate_sku'] = true;
        } else {
            $variantData['duplicate_sku'] = null;
        }
        // Add variant attributes
        foreach ($variant['option_values'] ?? [] as $option) {
            if (strtolower($option['name']) !== 'title') {
                $variantData[$option['name']] = $option['value'];
            }
        }
        
        return $variantData;
    }
    
    /**
     * Prepare simple product update
     */
    private function prepareSimpleProductUpdate($productListing, $variant)
    {
        $mapWeightUnit = static function ($unit) {
            $u = strtoupper((string)$unit);
            return match ($u) {
                'KG', 'KILOGRAM', 'KILOGRAMS' => 'KILOGRAMS',
                'G', 'GRAM', 'GRAMS' => 'GRAMS',
                'LB', 'LBS', 'POUND', 'POUNDS' => 'POUNDS',
                'OZ', 'OUNCE', 'OUNCES' => 'OUNCES',
                default => $u,
            };
        };
        $sku = !empty($variant['sku']) ? (string)$variant['sku'] : (string)$variant['id'];
        $data = [
            'title' => $productListing['title'] ?? '',
            'description' => $productListing['body_html'] ?? '',
            'brand' => $productListing['vendor'] ?? '',
            'product_type' => $productListing['product_type'] ?? '',
            'tags' => $this->extractTags($productListing),
            'handle' => $productListing['handle'] ?? '',
            'price' => (float)$variant['price'] ?? 0,
            'sku' => $sku,
            'low_sku' => strtolower($sku),
            'quantity' => (int)$variant['inventory_quantity'] ?? 0,
            'compare_at_price' => (float)$variant['compare_at_price'] ?? 0,
            'barcode' => $variant['barcode'] ?? null,
            'weight' => $variant['weight'] ?? 0,
            'weight_unit' => $mapWeightUnit($variant['weight_unit'] ?? null),
            'grams' => $variant['grams'] ?? 0,
            'inventory_management' => $variant['inventory_management'] ?? '',
            'inventory_policy' => $variant['inventory_policy'] ?? 'deny',
            'inventory_tracked' => $this->getInventoryTracked($variant),
            'published_at' => $productListing['published_at'] ?? null,
            'source_created_at' => $productListing['created_at'] ?? null,
            'source_updated_at' => $productListing['updated_at'] ?? null,
            'source_marketplace' => $this->marketplace,
            'source_product_id' => (string)$variant['id'],
            'source_sku' => $sku,
            'update_version'=> 2.0,
            'container_id' => (string)$productListing['product_id'],
            'updated_at' => date('c'),
            'taxable' => $variant['taxable'] ?? true,
            'additional_images' => $this->extarctAdditionalImages($productListing),
            'main_image' => $this->extarctMainImage($productListing),
        ];
        if (!empty($variant['_duplicate_sku'])) {
            $data['duplicate_sku'] = true;
        } else {
            $data['duplicate_sku'] = null;
        }
        return $data;
    }
    
    /**
     * Extract variant attributes from product listing
     */
    private function extractVariantAttributes($productListing)
    {
        $attributes = [];
        
        foreach ($productListing['options'] ?? [] as $option) {
            $variants = $productListing['variants'] ?? [];
            $variantCount = count($variants);
            if (strtolower($option['name']) !== 'title' || $variantCount > 1) {
                $attributes[] = $option['name'];
            }
        }
        
        return $attributes;
    }

    private function extarctMainImage($productListing)
    {
        $images = $productListing['images'] ?? [];
        $featured = null;
        foreach ($images as $img) {
            if ($img['position'] == 1) {
                $featured = $img;
            }
        }
        return $featured['src'] ?? $featured['originalSrc'] ?? '';
    }

    private function extarctAdditionalImages($productListing)
    {

        $images = $productListing['images'] ?? [];
        $additional = [];
        foreach ($images as $img) {
            $src = $img['src'] ?? ($img['originalSrc'] ?? null);
            if ($src) {
                $additional[] = $src;
            }
        }
        return $additional;
    }

    private function extractVariantAttributesValues($productListing)
    {
        $attributes = [];
        foreach ($productListing['options'] ?? $productListing['option_values'] ?? [] as $option) {
            $attributes[] = ['key' => $option['name'], 'value' => $option['value'] ?? $option['values'] ?? []];
        }
        return $attributes;
    }

    private function extractTags($productListing)
    {
        $tags = $productListing['tags'] ?? '';
        $tags = explode(',', (string) $tags);
        $tags = array_map('trim', $tags);
        $tags = array_filter($tags);
        return $tags;
    }
    
    /**
     * Middleware: when duplicate SKU is found (in another product or same SKU in multiple variants),
     * resolve by adding counter (source_sku_counter) and set duplicate_sku flag. Same logic as ProductHelper.
     * If the document already has a resolved SKU (duplicate_sku in existing data), reuse it so _1 does not become _2 on later updates.
     * Mutates $productListing so prepare* methods see the resolved SKU.
     *
     * @param array $productListing product listing payload (product_id, variants, etc.) - passed by reference
     * @param array $existingData existing product data from getExistingProductData (parent + products keyed by source_product_id)
     */
    private function resolveDuplicateSkus(array &$productListing, array $existingData = [])
    {
        $productId = (string)($productListing['product_id'] ?? '');
        if ($productId === '') {
            return;
        }

        $items = $this->collectSkuItemsFromProductListing($productListing);
        if (empty($items)) {
            return;
        }

        $excludeSourceProductIds = [(string)$productId];
        foreach ($productListing['variants'] ?? [] as $variant) {
            if (isset($variant['id'])) {
                $excludeSourceProductIds[] = (string)$variant['id'];
            }
        }
        $excludeSourceProductIds = array_values(array_unique($excludeSourceProductIds));

        $lowSkus = array_column($items, 'low_sku');
        $duplicateInOtherProducts = $this->getDuplicateSkusInOtherProducts($lowSkus, $excludeSourceProductIds);
        $countByLowSku = array_count_values($lowSkus);
        $duplicateWithinPayload = array_keys(array_filter($countByLowSku, static function ($c) {
            return $c > 1;
        }));
        $duplicateLowSkus = array_unique(array_merge($duplicateInOtherProducts, $duplicateWithinPayload));
        $duplicateLowSkus = array_map('strval', $duplicateLowSkus);
        if (empty($duplicateLowSkus)) {
            return;
        }

        $existingProducts = $existingData['products'] ?? [];
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $resolvedCount = 0;
        foreach ($items as $item) {
            if (!in_array($item['low_sku'], $duplicateLowSkus, true)) {
                continue;
            }
            $sourceProductId = $item['type'] === 'product'
                ? $productId
                : (string)($productListing['variants'][$item['variant_key']]['id'] ?? '');
            $existingDoc = $existingProducts[$sourceProductId] ?? null;
            if (!empty($existingDoc['duplicate_sku']) && !empty($existingDoc['sku']) && ((string)$existingDoc['sku'] != $item['low_sku'])) {
                $newSku = (string)$existingDoc['sku'];
            } else {
                $counter = (string) $mongo->getCounter($item['source_sku'], $this->userId);
                $newSku = $item['source_sku'] . '_' . $counter;
            }
            if ($item['type'] === 'product') {
                $productListing['sku'] = $newSku;
                $productListing['_duplicate_sku'] = true;
            } else {
                $productListing['variants'][$item['variant_key']]['sku'] = $newSku;
                $productListing['variants'][$item['variant_key']]['_duplicate_sku'] = true;
            }
            $resolvedCount++;
        }

        $this->writeLog(
            'Duplicate SKU resolved with counter',
            'info',
            ['container_id' => $productId, 'resolved_count' => $resolvedCount]
        );
    }

    /**
     * Collect all SKU items (product-level + each variant) for duplicate check and resolution.
     *
     * @param array $productListing product listing payload
     * @return array list of ['source_sku' => string, 'low_sku' => string, 'type' => 'product'|'variant', 'variant_key' => int|null]
     */
    private function collectSkuItemsFromProductListing($productListing)
    {
        $items = [];
        $productId = (string)($productListing['product_id'] ?? '');
        $productSku = !empty($productListing['sku']) ? (string)$productListing['sku'] : $productId;
        $items[] = [
            'source_sku'  => $productSku,
            'low_sku'     => strtolower($productSku),
            'type'        => 'product',
            'variant_key' => null
        ];

        foreach ($productListing['variants'] ?? [] as $key => $variant) {
            $sku = !empty($variant['sku']) ? (string)$variant['sku'] : (string)($variant['id'] ?? '');
            if ($sku !== '') {
                $items[] = [
                    'source_sku'  => $sku,
                    'low_sku'     => strtolower($sku),
                    'type'        => 'variant',
                    'variant_key' => $key
                ];
            }
        }

        return $items;
    }

    /**
     * Get list of lowercase SKUs that exist on any other document (different source_product_id). Single query.
     * Enforces uniqueness per document (parent and each child), not just per container.
     *
     * @param array $lowSkus list of lowercase SKU strings
     * @param array $excludeSourceProductIds source_product_ids being written in this update (to exclude)
     * @return array list of low_sku strings that are duplicate on other documents
     */
    private function getDuplicateSkusInOtherProducts(array $lowSkus, array $excludeSourceProductIds)
    {
        if (empty($lowSkus)) {
            return [];
        }
        $type_low = [];
        foreach($lowSkus as $l_sku) {
            $type_low[] = (string)$l_sku;
        }

        $filter = [
            'user_id' => $this->userId,
            'shop_id' => $this->shopId,
            'low_sku' => ['$in' => $type_low],
        ];
        if (!empty($excludeSourceProductIds)) {
            $filter['source_product_id'] = ['$nin' => $excludeSourceProductIds];
        }

        $cursor = $this->collection->find(
            $filter,
            [
                'projection' => ['low_sku' => 1],
                'typeMap'    => ['root' => 'array', 'document' => 'array', 'array' => 'array']
            ]
        );

        $duplicate = [];
        foreach ($cursor as $doc) {
            if (isset($doc['low_sku'])) {
                $duplicate[$doc['low_sku']] = true;
            }
        }

        return array_keys($duplicate);
    }

    /**
     * Execute bulk write operations
     */
    private function executeBulkWrite($operations)
    {
        if (empty($operations)) {
            return;
        }
        
        $operationCount = count($operations);
        $this->writeLog(
            "Executing bulk write - Operations: {$operationCount}",
            'info',
            $operations
        );
        
        try {
            $this->collection->bulkWrite($operations, ['ordered' => false]);
            $this->writeLog(
                "Bulk write completed successfully - Operations: {$operationCount}",
                'info',
                ['operation_count' => $operationCount]
            );
        } catch (Exception $e) {
            $this->writeLog(
                "Bulk write failed: " . $e->getMessage(),
                'error',
                ['error' => $e->getMessage()]
            );
            throw new Exception('Bulk write failed: ' . $e->getMessage());
        }
    }

    private function eventAfterVariantRemove()
    {
        if (empty($this->variant_deleted_data)) {
            return;
        }
        $eventsManager = $this->di->getEventsManager();
        $eventsManager->fire('application:afterVariantsDelete', $this, $this->variant_deleted_data);
    }

    private function eventAfterProductUpdate()
    {
        if (empty($this->after_product_update_data)) {
            return;
        }
        $eventsManager = $this->di->getEventsManager();
        $eventData = [];
        $eventData[$this->update_type] = $this->after_product_update_data;
        $eventData['source_marketplace'] = $this->marketplace ?? 'shopify';
        $eventsManager->fire('application:afterProductUpdate', $this, $eventData);
    }

    public function getInventoryTracked($data)
    {
        if(isset($data['inventory_management']) && $data['inventory_management'] == 'shopify')
        {
            return true;
        }

        return false;
    }

    private function updateSkuWithNewId($update)
    {
        $this->collection->updateOne(
            [
                'user_id' => $this->userId,
                'container_id' => $update['container_id'],
                'target_marketplace' => 'amazon',
                'sku' => $update['sku']
            ],
            ['$set' => ['source_product_id' => $update['source_product_id']]]
        );
    }

        /**
     * Centralized logging function with configurable options
     * 
     * @param string $message The log message
     * @param string $level Log level (info, warning, error, debug)
     * @param array $context Additional context data (optional)
     * @param string $customFile Custom log file (optional)
     */
    private function writeLog($message, $level = 'info', $context = [], $customFile = null)
    {
        // Check if logging is enabled
        if (!$this->logEnabled) {
            return;
        }
        
        // Check log level priority
        $levelPriority = ['debug' => 1, 'info' => 2, 'warning' => 3, 'error' => 4];
        $currentLevelPriority = $levelPriority[$this->logLevel] ?? 2;
        $messageLevelPriority = $levelPriority[$level] ?? 2;
        
        if ($messageLevelPriority < $currentLevelPriority) {
            return;
        }
        
        // Prepare log message with context
        $logMessage = $message;
        if (!empty($context)) {
            $logMessage .= ' | Context: ' . json_encode($context);
        }
        
        // Add timestamp and level
        $formattedMessage = "{$logMessage}";
        
        // Determine log file
        $logFile = $customFile ?? $this->logFile;
        
        // Write to log
        $this->di->getLog()->logContent(
            $formattedMessage,
            $level,
            $logFile
        );
    }
    
    /**
     * Configure logging settings
     * 
     * @param bool $enabled Enable/disable logging
     * @param string $level Minimum log level (debug, info, warning, error)
     * @param string $fileSuffix Custom log file suffix
     */
    public function configureLogging($enabled = true, $level = 'info', $fileSuffix = 'product_update.log')
    {
        $this->logEnabled = $enabled;
        $this->logLevel = $level;
        $this->logFileSuffix = $fileSuffix;
        
        // Update log file path if userId is set
        if ($this->userId) {
            $this->logFile = 'shopify/' . $this->userId . '/product' . '/' . date("Y-m-d") . '/' . $this->logFileSuffix;
        }
    }
}
