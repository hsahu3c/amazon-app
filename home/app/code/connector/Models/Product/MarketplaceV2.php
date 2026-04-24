<?php

namespace App\Connector\Models\Product;

use App\Core\Models\Base;

class MarketplaceV2 extends Base
{
    // MongoDB collections
    public $_mongo;
    public $_refineProductsTable;

    // User and product-related information
    public $_userId;
    public $_sourceShopId;
    public $_sourceMarketplace;
    public $_allTargetIds;
    public $_childKey = 'items';

    // Arrays to store processed data
    public $_productsUpdates = [];
    public $_allProductData = [];
    public $_updateRefineProduct = [];
    public $_refineData = [];
    public $_errors = [];
    public $_filters;
    public $_refineAdditionals;

    // Abort refine sync configuration
    public $abortRefineSyncingForMarketplacesArr = [];

    // Initialize the necessary variables and collections
    public function init($data)
    {
        $this->_productsUpdates = [];
        $this->_allProductData = [];
        $this->_updateRefineProduct = [];
        $this->_refineData = [];
        $this->_errors = [];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_mongo = $mongo->getCollectionForTable('product_container');
        $this->_refineProductsTable = $mongo->getCollectionForTable('refine_product');

        if (isset($data['childInfo']['source_marketplace']) || isset($data['source_marketplace'])) {
            $this->_sourceMarketplace = isset($data['childInfo']['source_marketplace']) ? $data['childInfo']['source_marketplace'] : $data['source_marketplace'];
        }

        $this->_userId = isset($data['user_id']) ? $data['user_id'] : $this->di->getUser()->id;
        $this->_sourceShopId = $data['source_shop_id'];

        if ($this->di->getConfig()->get('abort_refine_syncing')) {
            $this->abortRefineSyncingForMarketplacesArr = $this->di->getConfig()->abort_refine_syncing->toArray() ?? [];
        }
    }

    // Entry point function for saving and updating marketplace data
    public function marketplaceSaveAndUpdate($data, $allContainerIds = false)
    {
        if (!$this->isSequentialArray($data)) {
            $data = [$data];
        }
        $this->init($data[0]);

        //fetch all target and save in global variable
        $this->getAllTargetIds($data[0]);

        // Fetch and format data from product_container and refine_product
        $this->fetchAndFormatData($data, $allContainerIds);

        // Process each item in the data array
        foreach ($data as $item) {
            $this->processMarketplaceSaveAndUpdate($item);
        }

        // Execute bulk operations
        $result = $this->executeBulkOperations();

        return $result;
    }

    // Fetch and format data from product_container and refine_product
    protected function fetchAndFormatData($data, $allContainerIds)
    {
        if ($allContainerIds) {
            $allProducts = $this->getProducts($allContainerIds, true);

            $this->formatAndStructureContainerData($allProducts);
            $refineProducts = $this->getRefineProducts($allProducts);
            $this->formatAndStructureRefineData($refineProducts);
        } else {
            $allSourceProductIdsData = array_map(function ($item) {
                return $item['source_product_id'];
            }, $data);

            $allProducts = $this->getProducts($allSourceProductIdsData);

            $resIds =   $this->formatAndStructureContainerData($allProducts,  true);

            $allProducts = $this->getProducts($resIds['parentIds']);
            $this->formatAndStructureContainerData($allProducts,  true);

            $refineProducts = $this->getRefineProducts($resIds['allCatalogAndSearch']);
            $this->formatAndStructureRefineData($refineProducts);
        }
    }

    // Process and update marketplace data
    protected function processMarketplaceSaveAndUpdate($data)
    {
        // Determine update type
        $isTargetUpdate = isset($data['childInfo']['target_marketplace']);
        $isSourceUpdate = isset($data['childInfo']['source_marketplace']);

        if ($isSourceUpdate) {
            // Handle source document update
            $this->handleSourceDocumentUpdate($data);
        } else if ($isTargetUpdate) {
            // Handle target document update
            $this->handleTargetDocumentUpdate($data, $data['childInfo']['shop_id']);
        }
    }

    // Handle source document update
    protected function handleSourceDocumentUpdate($data)
    {
        //here we have to divide the further code target wise as source update has to be reflected everywhere. you getting my point??
        foreach ($this->_allTargetIds as $targetInfo) {
            $this->updateRefineProduct($data,  $targetInfo);
        }
    }

    // Handle target document update
    protected function handleTargetDocumentUpdate($data, $targetId)
    {
        $this->updateRefineProduct($data, ['shopId' => $targetId], false);
    }

    // Update existing refine product data
    protected function updateRefineProduct($data, $targetInfo, $isSourceUpdate = true)
    {
        $childInfo = $data['childInfo'];

        $sourceKey = $this->getFormattedKey($data);

        $prdcData = $this->_allProductData[$sourceKey];

        $isChild = $this->isChildOfProduct($prdcData);

        $refineKey = $this->getFormattedRefineKey($data, $targetInfo['shopId'], $isChild);
        $refineData =  $this->_refineData[$refineKey];

        $syncRefineAdditional = !$refineData  ?  true : false;


        if (isset($prdcData['edited']) && $prdcData['edited'][$targetInfo['shopId']])
            $edited  = $prdcData['edited'][$targetInfo['shopId']];
        else
            $edited = [];

        if ($isSourceUpdate)
            $childInfo = $this->getFilterData($edited) + $childInfo;
        else
            $childInfo = $childInfo + $this->getFilterData($prdcData);


        $additionalPrd = [];
        if (!$isChild) {
            $additionalPrd = [
                'title' => $childInfo['title'],
                'target_product_id' => $product['asin'] ?? null
            ];
        }

        //getRefineAdditionals
        if ($syncRefineAdditional) {

            if ($isChild) {
                $parentKey = $data['container_id'] . '_' . $data['container_id'];
                $parentPrd = $this->_allProductData[$parentKey] ?? false;
                $additionalPrd = [
                    'title' => $parentPrd['edited']['title'] ?? $parentPrd['title'],
                    'target_product_id' => $parentPrd['asin'] ?? null
                ];
            } else {
                $parentPrd = $prdcData;
            }
            $additionalRefine = $this->refineAdditionalData($parentPrd, $targetInfo['shopId']);
            $additionalPrd = $additionalRefine + $additionalPrd;
        }

        if ($refineData) {
            $items = $refineData[$this->_childKey];

            // Search for the item index to update
            $index = $this->findItemIndex($items, $childInfo);

            if ($index !== false) {
                // Update the existing item
                $items[$index] =  $childInfo + $items[$index];
            } else {
                // Add new item
                $items[] = $childInfo;
            }
            $refineData[$this->_childKey] = $items;
        } else {
            $refineData = [
                'source_product_id' => $data['source_product_id'],
                'container_id' => $data['container_id'],
                'source_shop_id' => $data['source_shop_id'],
                'target_shop_id' => $targetInfo['shopId'],
                'user_id' => $this->_userId,
                $this->_childKey => [$childInfo]
            ] + $additionalPrd;
        }


        // Update global details
        $this->_refineData[$this->getFormattedRefineKey($data, $targetInfo['shopId'], $isChild)] = $refineData;


        // Prepare bulk operation
        $this->_updateRefineProduct[$this->getFormattedRefineKey($data, $targetInfo['shopId'], $isChild)] = [
            'updateOne' => [
                ['source_product_id' => $isChild ? $data['container_id'] : $data['source_product_id'], 'container_id' => $data['container_id'], 'user_id' => $this->_userId, 'source_shop_id' => $this->_sourceShopId, 'target_shop_id' => $targetInfo['shopId']],
                [
                    '$set' => [
                        $this->_childKey => $items,
                        'updated_at' => date('c'),
                    ] + $additionalPrd,
                    '$setOnInsert' => [
                        'created_at' => date('c')
                    ]
                ],
                ['upsert' => true]
            ]
        ];
    }

    // Execute bulk operations for product_container and refine_product
    protected function executeBulkOperations()
    {
        // $bulkOpArray = array_values($this->_updateRefineProduct);
        // $r = $this->executeBulk($this->_mongo, $bulkOpArray);

        $refineBulkOP = array_values($this->_updateRefineProduct);
        $res = $this->executeBulk($this->_refineProductsTable, $refineBulkOP);
        return [
            'success' => true,
            'errors' => $this->_errors,
            'bulk_info' => [
                'refine_product' => $res
            ]
        ];
    }

    // Execute bulk write operation
    protected function executeBulk($mongo, $bulk)
    {
        if (count($bulk) !== 0) {
            return $mongo->BulkWrite($bulk);
        }
        return false;
    }

    // Check if the given data corresponds to a child product
    protected function isChildOfProduct($data)
    {
        if (!isset($data['type'], $data['visibility'])) return false;
        return ($data['type'] === "simple" && $data['visibility'] === "Not Visible Individually" && isset($data['container_id']));
    }

    // Fetch products from product_container
    protected function getProducts($allSourceProductIds, $useContainerId = false)
    {
        $allowedFilter =  $this->di->getConfig()->get('allowed_filters')->toArray();

        $extraKeys = $allowedFilter +  $this->di->getConfig()->get('refine_additional_keys')->toArray();


        $extraProjection = [];
        foreach ($extraKeys as $k => $v) {
            $extraProjection[$v] = 1;
        }

        $targetIds = [];

        foreach ($this->_allTargetIds as $targeInfo) {
            $targetIds[] = $targeInfo['shopId'];
        }

        $ShopidsOr = [
            ['shop_id' => $this->_sourceShopId],
            ['shop_id' => ['$in' => $targetIds]]
        ];

        if ($useContainerId) {
            $findQuery = [
                'user_id' => $this->_userId,
                'container_id' => ['$in' => $allSourceProductIds]
            ];
        } else {
            $findQuery = [
                'user_id' => $this->_userId,
                'source_product_id' => ['$in' => $allSourceProductIds]
            ];
        }

        $findQuery['$or'] = $ShopidsOr;

        $project =  [
            'marketplace' => 1,
            'source_product_id' => 1,
            'container_id' => 1,
            'type' => 1,
            'visibility' => 1,
            'tags' => 1,
            'asin' => 1,
            'variant_attributes' => 1,
            'source_marketplace' => 1,
            'shop_id' => 1,
            'target_marketplace' => 1,
            'source_shop_id' => 1
        ] + $extraProjection;

        $options = [
            'projection' => $project,
            'typeMap'   => ['root' => 'array', 'document' => 'array']
        ];

        return $this->_mongo->find(
            $findQuery,
            $options
        )->toArray();
    }

    // Fetch products from refine_product
    protected function getRefineProducts($allSourceProductIds, $useContainerId = false)
    {
        $findQuery = [
            'user_id' => $this->_userId,
            'source_shop_id' => $this->_sourceShopId
        ];

        if ($useContainerId) {
            $findQuery['container_id'] = ['$in' => $allSourceProductIds];
        } else {
            $findQuery['source_product_id'] = ['$in' => $allSourceProductIds];
        }

        $project = [
            'items' => 1,
            'source_product_id' => 1,
            'container_id' => 1,
            'source_shop_id' => 1,
            'target_shop_id' => 1
        ];
        $options = [
            'projection' => $project,
            'typeMap'   => ['root' => 'array', 'document' => 'array']
        ];
        return $this->_refineProductsTable->find($findQuery, $options)->toArray();
    }


    public function getAllTargetIds($data, $useCache = false)
    {

        if ($this->_sourceShopId) {
            $sourceId = $this->_sourceShopId;
        } else {
            $sourceId = $data['source']['shopId'];
        }

        if ($useCache) {
            $userShops = $this->di->getUser()->shops;
        } else {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('user_details');
            $user = $collection->find(['user_id' => $this->_userId])->toArray()[0];
            $userShops = $user['shops'];
        }

        $allTargetIds = [];

        foreach ($userShops as $k => $v) {
            if ($v['_id'] == $sourceId) {
                if (isset($v['targets'])) {
                    foreach ($v['targets'] as $kT => $vT) {
                        if (!$this->validateRefineAbortProcess($vT['code'] ?? $vT['marketplace'])) {
                            $allTargetIds[] = [
                                'marketplace' => $vT['code'] ?? $vT['marketplace'],
                                'shopId' => $vT['shop_id']
                            ];
                        }
                    }
                }
                break;
            }
        }
        $this->_allTargetIds = $allTargetIds;
    }

    protected function formatAndStructureContainerData($allProducts, $returnParentSourceIds = false)
    {
        $parentIds = [];
        $allCatalogAndSearch = [];
        foreach ($allProducts as $product) {
            if (isset($product['source_product_id']) && isset($product['container_id'])) {
                if ($this->isChildOfProduct($product) && $returnParentSourceIds) {
                    $parentIds[] = $product['container_id'];
                    $allCatalogAndSearch[] = $product['container_id'];
                }
                if ($product['visibility'] == 'Catalog and Search')
                    $allCatalogAndSearch[] = $product['source_product_id'];
                $key = $this->getFormattedKey($product);
                if (!isset($this->_allProductData[$key])) {
                    $this->_allProductData[$key] = [];
                }
                if (isset($product['target_marketplace'])) {
                    $this->_allProductData[$key]['edited'][$product['shop_id']] = $product;
                } else {
                    $this->_allProductData[$key] =  $this->_allProductData[$key]  + $product;
                }
            }
        }

        return ['parentIds' => $parentIds, 'allCatalogAndSearch' => $allCatalogAndSearch];
    }

    protected function formatAndStructureRefineData($refineProducts)
    {
        foreach ($refineProducts as $product) {
            $key = $this->getFormattedRefineKey($product, $product['target_shop_id']);
            $this->_refineData[$key] = $product;
        }
    }

    // Get formatted key for product data
    protected function getFormattedKey($data)
    {
        return $data['source_product_id'] . '_' . $data['container_id'];
    }

    // Get formatted key for refine data
    protected function getFormattedRefineKey($data, $targetId = "", $isChild = false)
    {
        if ($isChild)
            return $data['container_id'] . '_' . $data['container_id'] . '_' . $data['source_shop_id'] . '_' . $targetId;
        else
            return $data['source_product_id'] . '_' . $data['container_id'] . '_' . $data['source_shop_id'] . '_' . $targetId;
    }

    // Check if an array is sequential
    protected function isSequentialArray($arr)
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    // Find item index by child info
    protected function findItemIndex($items, $childInfo)
    {
        foreach ($items as $index => $item) {
            if ($item['source_product_id'] === $childInfo['source_product_id'] && $item['shop_id'] === $childInfo['shop_id']) {
                return $index;
            }
        }
        return false;
    }

    public function getFilterData($dbData)
    {
        if (!$this->_filters) {
            $this->_filters =  $this->di->getConfig()->allowed_filters ? $this->di->getConfig()->allowed_filters->toArray() : [];
        }
        $filters[] = 'target_marketplace';
        $arr = [];
        foreach ($this->_filters as $key => $value) {
            if (isset($dbData[$value])) {
                $arr[$value] = $dbData[$value];
            }
        }
        return $arr;
    }

    protected function refineAdditionalData($product, $targetId)
    {

        if (!$this->_refineAdditionals) {
            $this->_refineAdditionals =  $this->di->getConfig()->refine_additional_keys ? $this->di->getConfig()->refine_additional_keys->toArray() : [];
        }

        $additionalInfo = [];
        foreach ($this->_refineAdditionals as $k => $v) {
            if (isset($product[$v])) {
                $additionalInfo[$v] = $product[$v];
            }
        }

        unset($additionalInfo['profile']);

        if (isset($product["profile"]) && count($product["profile"]) > 0) {
            foreach ($product["profile"] as $key) {
                if ($key['target_shop_id'] == $targetId) {
                    $temp = [
                        'profile_name' => $key['profile_name'],
                        'profile_id' => new \MongoDB\BSON\ObjectID($key['profile_id']['$oid']),
                        'type' => $key['type']
                    ];
                    $additionalInfo['profile'] = $temp;
                }
            }
        }

        return $additionalInfo;
    }
}
