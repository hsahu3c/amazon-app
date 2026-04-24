<?php

namespace App\Amazon\Components\Product;

use App\Amazon\Models\SourceModel;
use Exception;
use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base;


class Delete extends Base
{


    public function init($request = [])
    {
        if (!isset($request['user_id'])) {
        }
        return $this;
    }

    public function delete($sqs_data)
    {

        try {
            $data = $sqs_data['data'];
            $source_marketplace = $data['source_marketplace'];
            $target_marketplace = $data['target_marketplace'];
            $source_shop_id = $data['source_shop_id'];
            $shops = $data['amazon_shops'];
            $returnArray = [];
            $errorArray = [];
            $successArray = [];

            if ($target_marketplace == '' || $source_marketplace == '' || empty($shops)) {
                return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
            }

            $commonHelper = $this->di->getObjectManager()->get(Helper::class);

            $allValidProductStatus = [
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_LISTING_IN_PROGRESS,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_ACTIVE,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INACTIVE,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INCOMPLETE,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_SUPRESSED,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UNKNOWN,
            ];
            foreach ($shops as $shop) {
                $marketplace_id = $shop['warehouses'][0]['marketplace_id'];
                $params = [];
                $feedContent = [];
                $params['source_marketplace']['marketplace'] = $source_marketplace;
                $params['target_marketplace']['marketplace'] = $target_marketplace;
                $params['target_marketplace']['shop_id'] = $shop['_id'];
                $params['source_marketplace']['shop_id'] = $source_shop_id;
                $params['source_product_id'] = $data['source_product_ids'];

                $objectManager = $this->di->getObjectManager();
                $helper = $objectManager->get('\App\Connector\Models\Product\Index');
                $mergedData = $helper->getProducts($params);

                if (!empty($mergedData)) {
                    if (isset($mergedData['data']['rows'])) {
                        foreach ($mergedData['data']['rows'] as $product) {

                            $productStatus = $this->di->getObjectManager()->get(SourceModel::class)->getProductStatusByShopId($product, $shop['_id']);

                            if (in_array($productStatus, $allValidProductStatus)) {
                                $feedContent[$product['source_product_id']]['Id'] = $product['source_product_id'];
                                $feedContent[$product['source_product_id']]['SKU'] = $product['sku'];
                            }
                        }

                        if (!empty($feedContent)) {
                            $specifics = [
                                'ids' => array_keys($feedContent),
                                'home_shop_id' => $shop['_id'],
                                'marketplace_id' => $marketplace_id,
                                'shop_id' => $shop['remote_shop_id'],
                                'feedContent' => base64_encode(json_encode($feedContent))
                            ];
                            $response = $commonHelper->sendRequestToAmazon('product-delete', $specifics, 'POST');

                            if (isset($response['success']) && $response['success']) {
                                $successArray[$marketplace_id] = 'Feed successfully uploaded on s3';
                            } else {
                                $errorArray[$marketplace_id] = 'Error from remote';
                            }

                        } else {
                            $errorArray[$marketplace_id] = 'No products available for inventory update';
                        }
                    } else {
                        $errorArray[$marketplace_id] = 'No Products Found';
                    }
                } else {
                    $errorArray[$marketplace_id] = 'No Products Found';
                }

            }

            if (!empty($errorArray)) {
                $returnArray['error'] = ['status' => true, 'message' => $errorArray];
            }

            if (!empty($successArray)) {
                $returnArray['success'] = ['status' => true, 'message' => $successArray];
            }

            return $returnArray;
        } catch (Exception $e) {
            $success = false;
            $message = $e->getMessage();
            return ['success' => $success, 'message' => $message];
        }
    }

    public function removeExtraProductsFromDB($sqsData)
    {
        try {
            $userId = $sqsData['user_id'] ?? null;
            if (empty($userId)) {
                $this->di->getLog()->logContent('User ID not found in sqsData', 'error', 'remove_extra_products.log');
                return ['success' => false, 'message' => 'User ID not found'];
            }

            $logFile = "amazon/remove_extra_products/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('Starting removeExtraProductsFromDB for user: ' . $userId, 'info', $logFile);

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollectionForTable('product_container');
            $refineProduct = $mongo->getCollectionForTable('refine_product');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');

            // Fetch first 100 container_ids
            $parentProductQuery = [
                'user_id' => $userId,
                'source_marketplace' => $sqsData['source'],
                "visibility" => "Catalog and Search"
            ];

            $parentProducts = $productContainer->find(
                $parentProductQuery,
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => ['container_id' => 1],
                    'sort' => ['_id' => 1],
                    'limit' => 100
                ]
            )->toArray();
            $containerIds = array_map('strval', array_column($parentProducts, 'container_id'));
            $containerIdsCount = count($containerIds);
            $this->di->getLog()->logContent('User: ' . $userId . ' - Found ' . $containerIdsCount . ' container_ids', 'info', $logFile);

            if ($containerIdsCount < 100) {
                $this->di->getLog()->logContent('User: ' . $userId . ' - Parent products are less than 100, no need to delete', 'info', $logFile);

                // Unset auto_remove_extra_products_after from both outer and shop level
                $userDetailsCollection->updateOne(
                    ['user_id' => $userId],
                    [
                        '$set' => ['auto_removed_extra_products_at' => date('c')],
                        '$unset' => [
                            'auto_remove_extra_products_after' => 1,
                            'shops.$[shop].auto_remove_extra_products_after' => 1
                        ]
                    ],
                    [
                        'arrayFilters' => [
                            ['shop.marketplace' => 'amazon', 'shop.auto_remove_extra_products_after' => ['$exists' => true]]
                        ]
                    ]
                );
                return ['success' => true, 'message' => 'Parent products are less than 100, no deletion needed'];
            }

            // Delete products from product_container where container_id is NOT in the fetched container_ids
            $deleteResultProductContainer = $productContainer->deleteMany([
                'user_id' => $userId,
                'container_id' => ['$nin' => $containerIds]
            ]);

            $this->di->getLog()->logContent('User: ' . $userId . ' - Deleted ' . $deleteResultProductContainer->getDeletedCount() . ' products from product_container', 'info', $logFile);

            // Delete products from refine_product where container_id is NOT in the fetched container_ids
            $deleteResultRefineProduct = $refineProduct->deleteMany([
                'user_id' => $userId,
                'container_id' => ['$nin' => $containerIds]
            ]);

            $this->di->getLog()->logContent('User: ' . $userId . ' - Deleted ' . $deleteResultRefineProduct->getDeletedCount() . ' products from refine_product', 'info', $logFile);

            // Update product import credits after deletion
            $services = $this->di->getObjectManager()->get('\App\Plan\Models\Plan\Services');
            $userShops = $this->di->getUser()->shops ?? [];
            if (!empty($userShops) && isset($userShops[0]['_id'])) {
                $shopId = (string)$userShops[0]['_id'];
                $services->updateCacheAndManageSyncing($userId, $shopId, 3600);
            }

            // Unset auto_remove_extra_products_after from both outer and shop level after successful deletion
            $userDetailsCollection->updateOne(
                ['user_id' => $userId],
                [
                    '$set' => ['auto_removed_extra_products_at' => date('c')],
                    '$unset' => [
                        'auto_remove_extra_products_after' => 1,
                        'shops.$[shop].auto_remove_extra_products_after' => 1
                    ]
                ],
                [
                    'arrayFilters' => [
                        ['shop.marketplace' => 'amazon', 'shop.auto_remove_extra_products_after' => ['$exists' => true]]
                    ]
                ]
            );

            $this->di->getLog()->logContent('User: ' . $userId . ' - Successfully removed extra products', 'info', $logFile);
            return ['success' => true, 'message' => 'Extra products removed successfully'];

        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in removeExtraProductsFromDB: ' . $e->getMessage(), 'error', 'remove_extra_products.log');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

}
