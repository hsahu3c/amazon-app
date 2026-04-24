<?php

namespace App\Amazon\Components\Product;

use Exception;
use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base;

class Action extends Base
{

    public function removeErrorTag($data)
    {
        try {
            $productIds =  $data['data']['source_product_id'] ?? [];
            $userId = $this->di->getUser()->id;
            if (!empty($productIds)) {
                if ($data['data']['isParent'] == false) {
                    foreach ($productIds as $ids) {

                        $variant = $this->di->getObjectManager();
                        $help = $variant->get('\App\Connector\Models\Product\Edit');

                        $product = [
                            "source_product_id" => $ids,
                            'user_id' => $userId,
                            'targetShopID' => $data['target']['shopId'],
                            'target_marketplace' => $data['target']['marketplace'],
                            'source_marketplace' => $data['source']['marketplace'],
                            'sourceShopID' => $data['source']['shopId']
                        ];
                        $variant_product = $help->getProduct($product);
                        $variant_product = $variant_product['data']['rows'];

                        foreach ($variant_product as $value) {

                            $updateData = [
                                "source_product_id" => (string)$value['source_product_id'],
                                'user_id' => $userId,
                                'source_shop_id' => $data['source']['shopId'],
                                'target_marketplace' => 'amazon',
                                'shop_id' => $data['target']['shopId'],
                                'container_id' => $value['container_id'],
                                'unset' => [
                                    'error'=>true
                                ]
                            ];
                            $additionalData = [
                                'source' => [
                                    'shop_id' => $data['source']['shopId'],
                                    'marketplace' => 'shopify'
                                ],
                                'target' => [
                                    'shop_id' => $data['target']['shopId'],
                                    'marketplace' => 'amazon'
                                ]
                            ];

                            $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                            $editHelper->saveProduct([$updateData], $userId, $additionalData);
                        }
                    }
                } else {
                    foreach ($productIds as $ids) {

                        $variant = $this->di->getObjectManager();
                        $help = $variant->get('\App\Connector\Models\Product\Edit');

                        $product = [
                            "container_id" => $ids,
                            'user_id' => $userId,
                            'targetShopID' => $data['target']['shopId'],
                            'target_marketplace' => $data['target']['marketplace'],
                            'source_marketplace' => $data['source']['marketplace'],
                            'sourceShopID' => $data['source']['shopId']
                        ];
                        $variant_product = $help->getProduct($product);
                        if(empty($variant_product['data']['rows'])){
                            $product = [
                                "source_product_id" => $ids,
                                'user_id' => $userId,
                                'targetShopID' => $data['target']['shopId'],
                                'target_marketplace' => $data['target']['marketplace'],
                                'source_marketplace' => $data['source']['marketplace'],
                                'sourceShopID' => $data['source']['shopId']
                            ];
                            $variant_product = $help->getProduct($product);
                        }

                        $variant_product = $variant_product['data']['rows'];

                        foreach ($variant_product as $value) {

                            $updateData = [
                                "source_product_id" => (string)$value['source_product_id'],
                                'user_id' => $userId,
                                'source_shop_id' => $data['source']['shopId'],
                                'target_marketplace' => 'amazon',
                                'shop_id' => $data['target']['shopId'],
                                'container_id' => $value['container_id'],
                                'unset' => [
                                    'error'=>true
                                ]
                            ];
                            $additionalData = [
                                'source' => [
                                    'shop_id' => $data['source']['shopId'],
                                    'marketplace' => 'shopify'
                                ],
                                'target' => [
                                    'shop_id' => $data['target']['shopId'],
                                    'marketplace' => 'amazon'
                                ]
                            ];

                            $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                            $editHelper->saveProduct([$updateData], $userId, $additionalData);
                        }
                    }
                }
            } else {
                $product = [
                    'user_id' => $userId,
                    'target_shop_id' => $data['target']['shopId'],
                    'source_shop_id' => $data['source']['shopId'],
                    'unset_key' => 'error'
                ];
                $variant = $this->di->getObjectManager();
                $help = $variant->get('\App\Connector\Models\Product\Marketplace');
                $child = $help->marketplaceUnsetBulk($product);
                return ['success' => true, 'message' => 'bulk action in progress'];
                // $products = $collection->updateMany(['user_id' => $userId,'marketplace.amazon.error' => ['$exists' => true]], ['$unset' => ['marketplace.amazon.$.error' => true]]);
                // return ['success' => true, 'message' => 'bulk action are not working.'];
            }

            return ['success' => true, 'message' => 'Error Tags removed successfully from Product(s).'];
        } catch (Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function removeStatus($data)
    {
        try {
            $productIds =  $data['data']['source_product_id'] ?? [];
            $userId = $this->di->getUser()->id;
            if (!empty($productIds)) {
                if ($data['data']['isParent'] == false) {
                    foreach ($productIds as $ids) {
                        $variant = $this->di->getObjectManager();
                        $help = $variant->get('\App\Connector\Models\Product\Edit');

                        $product = [
                            "source_product_id" => $ids,
                            'user_id' => $userId,
                            'targetShopID' => $data['target']['shopId'],
                            'target_marketplace' => $data['target']['marketplace'],
                            'source_marketplace' => $data['source']['marketplace'],
                            'sourceShopID' => $data['source']['shopId']
                        ];
                        $variant_product = $help->getProduct($product);
                        $variant_product = $variant_product['data']['rows'];

                        foreach ($variant_product as $value) {

                            $updateData = [
                                "source_product_id" => (string)$value['source_product_id'],
                                'user_id' => $userId,
                                'source_shop_id' => $data['source']['shopId'],
                                'target_marketplace' => 'amazon',
                                'shop_id' => $data['target']['shopId'],
                                'container_id' => $value['container_id'],
                                'unset' => [
                                    'status'=>true
                                ]
                            ];
                            $additionalData = [
                                'source' => [
                                    'shop_id' => $data['source']['shopId'],
                                    'marketplace' => 'shopify'
                                ],
                                'target' => [
                                    'shop_id' => $data['target']['shopId'],
                                    'marketplace' => 'amazon'
                                ]
                            ];

                            $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                            $editHelper->saveProduct([$updateData], $userId, $additionalData);
                        }
                    }
                } else {
                    foreach ($productIds as $ids) {
                        $variant = $this->di->getObjectManager();
                        $help = $variant->get('\App\Connector\Models\Product\Edit');

                        $product = [
                            "container_id" => $ids,
                            'user_id' => $userId,
                            'targetShopID' => $data['target']['shopId'],
                            'target_marketplace' => $data['target']['marketplace'],
                            'source_marketplace' => $data['source']['marketplace'],
                            'sourceShopID' => $data['source']['shopId']
                        ];
                        $variant_product = $help->getProduct($product);
                        if(empty($variant_product['data']['rows'])){
                            $product = [
                                "source_product_id" => $ids,
                                'user_id' => $userId,
                                'targetShopID' => $data['target']['shopId'],
                                'target_marketplace' => $data['target']['marketplace'],
                                'source_marketplace' => $data['source']['marketplace'],
                                'sourceShopID' => $data['source']['shopId']
                            ];
                            $variant_product = $help->getProduct($product);
                        }

                        $variant_product = $variant_product['data']['rows'];

                        foreach ($variant_product as $value) {

                            $updateData = [
                                "source_product_id" => (string)$value['source_product_id'],
                                'user_id' => $userId,
                                'source_shop_id' => $data['source']['shopId'],
                                'target_marketplace' => 'amazon',
                                'shop_id' => $data['target']['shopId'],
                                'container_id' => $value['container_id'],
                                'unset' => [
                                    'status'=>true
                                ]
                            ];
                            $additionalData = [
                                'source' => [
                                    'shop_id' => $data['source']['shopId'],
                                    'marketplace' => 'shopify'
                                ],
                                'target' => [
                                    'shop_id' => $data['target']['shopId'],
                                    'marketplace' => 'amazon'
                                ]
                            ];

                            $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                            $editHelper->saveProduct([$updateData], $userId, $additionalData);
                        }
                    }
                }
            } else {
                $product = [
                    'user_id' => $userId,
                    'target_shop_id' => $data['target']['shopId'],
                    'source_shop_id' => $data['source']['shopId'],
                    'unset_key' => 'status'
                ];
                $variant = $this->di->getObjectManager();
                $help = $variant->get('\App\Connector\Models\Product\Marketplace');
                $child = $help->marketplaceUnsetBulk($product);
                return ['success' => true, 'message' => 'bulk action in progress'];
                // $products = $collection->updateMany(['user_id' => $userId,'marketplace.amazon.status' => ['$exists' => true]], ['$unset' => ['marketplace.amazon.$.status' => true]]);
                // return ['success' => true, 'message' => 'bulk action are not working.'];
            }

            return ['success' => true, 'message' => 'Status removed successfully from Product(s).'];
        } catch (Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function removeProcessTag($data)
    {
        try {
            $productIds =  $data['data']['source_product_id'] ?? [];
            $userId = $this->di->getUser()->id;
            if (!empty($productIds)) {
                if ($data['data']['isParent'] == false) {
                    foreach ($productIds as $ids) {
                        $variant = $this->di->getObjectManager();
                        $help = $variant->get('\App\Connector\Models\Product\Edit');

                        $product = [
                            "source_product_id" => $ids,
                            'user_id' => $userId,
                            'targetShopID' => $data['target']['shopId'],
                            'target_marketplace' => $data['target']['marketplace'],
                            'source_marketplace' => $data['source']['marketplace'],
                            'sourceShopID' => $data['source']['shopId']
                        ];
                        $variant_product = $help->getProduct($product);
                        $variant_product = $variant_product['data']['rows'];

                        foreach ($variant_product as $value) {

                            $updateData = [
                                "source_product_id" => (string)$value['source_product_id'],
                                'user_id' => $userId,
                                'source_shop_id' => $data['source']['shopId'],
                                'target_marketplace' => 'amazon',
                                'shop_id' => $data['target']['shopId'],
                                'container_id' => $value['container_id'],
                                'unset' => [
                                    'process_tags'=>true
                                ]
                            ];
                            $additionalData = [
                                'source' => [
                                    'shop_id' => $data['source']['shopId'],
                                    'marketplace' => 'shopify'
                                ],
                                'target' => [
                                    'shop_id' => $data['target']['shopId'],
                                    'marketplace' => 'amazon'
                                ]
                            ];

                            $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                            $editHelper->saveProduct([$updateData], $userId, $additionalData);
                        }
                    }
                } else {
                    foreach ($productIds as $ids) {
                        $variant = $this->di->getObjectManager();
                        $help = $variant->get('\App\Connector\Models\Product\Edit');

                        $product = [
                            "container_id" => $ids,
                            'user_id' => $userId,
                            'targetShopID' => $data['target']['shopId'],
                            'target_marketplace' => $data['target']['marketplace'],
                            'source_marketplace' => $data['source']['marketplace'],
                            'sourceShopID' => $data['source']['shopId']
                        ];
                        $variant_product = $help->getProduct($product);
                        if(empty($variant_product['data']['rows'])){
                            $product = [
                                "source_product_id" => $ids,
                                'user_id' => $userId,
                                'targetShopID' => $data['target']['shopId'],
                                'target_marketplace' => $data['target']['marketplace'],
                                'source_marketplace' => $data['source']['marketplace'],
                                'sourceShopID' => $data['source']['shopId']
                            ];
                            $variant_product = $help->getProduct($product);
                        }

                        $variant_product = $variant_product['data']['rows'];

                        foreach ($variant_product as $value) {

                            $updateData = [
                                "source_product_id" => (string)$value['source_product_id'],
                                'user_id' => $userId,
                                'source_shop_id' => $data['source']['shopId'],
                                'target_marketplace' => 'amazon',
                                'shop_id' => $data['target']['shopId'],
                                'container_id' => $value['container_id'],
                                'unset' => [
                                    'process_tags'=>true
                                ]
                            ];
                            $additionalData = [
                                'source' => [
                                    'shop_id' => $data['source']['shopId'],
                                    'marketplace' => 'shopify'
                                ],
                                'target' => [
                                    'shop_id' => $data['target']['shopId'],
                                    'marketplace' => 'amazon'
                                ]
                            ];

                            $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                            $editHelper->saveProduct([$updateData], $userId, $additionalData);
                        }
                    }
                }
            } else {
                $product = [
                    'user_id' => $userId,
                    'target_shop_id' => $data['target']['shopId'],
                    'source_shop_id' => $data['source']['shopId'],
                    'unset_key' => 'process_tags'
                ];
                $variant = $this->di->getObjectManager();
                $help = $variant->get('\App\Connector\Models\Product\Marketplace');
                $child = $help->marketplaceUnsetBulk($product);
                return ['success' => true, 'message' => 'bulk action in progress'];
                // $products = $collection->updateMany(['user_id' => $userId,'marketplace.amazon.process_tags' => ['$exists' => true]], ['$unset' => ['marketplace.amazon.$.process_tags' => true]]);
                // return ['success' => true, 'message' => 'bulk action are not working.'];

            }

            return ['success' => true, 'message' => 'Process Tags removed successfully from Product(s).'];
        } catch (Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function removeAsin($data)
    {
        try {
            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')
            ->getCollectionForTable(Helper::AMAZON_LISTING);
            $productIds =  $data['data']['source_product_id'] ?? [];
            $userId = $this->di->getUser()->id;
            if (!empty($productIds)) {
                if ($data['data']['isParent'] == false) {
                    foreach ($productIds as $ids) {
                        $variant = $this->di->getObjectManager();
                        $help = $variant->get('\App\Connector\Models\Product\Edit');

                        $product = [
                            "source_product_id" => $ids,
                            'user_id' => $userId,
                            'targetShopID' => $data['target']['shopId'],
                            'target_marketplace' => $data['target']['marketplace'],
                            'source_marketplace' => $data['source']['marketplace'],
                            'sourceShopID' => $data['source']['shopId']
                        ];
                        $variant_product = $help->getProduct($product);
                        $variant_product = $variant_product['data']['rows'];

                        foreach ($variant_product as $value) {

                            $updateData = [
                                "source_product_id" => (string)$value['source_product_id'],
                                'user_id' => $userId,
                                'source_shop_id' => $data['source']['shopId'],
                                'target_marketplace' => 'amazon',
                                'shop_id' => $data['target']['shopId'],
                                'container_id' => $value['container_id'],
                                'unset' => [
                                    'asin'=>true
                                ]
                            ];
                            $additionalData = [
                                'source' => [
                                    'shop_id' => $data['source']['shopId'],
                                    'marketplace' => 'shopify'
                                ],
                                'target' => [
                                    'shop_id' => $data['target']['shopId'],
                                    'marketplace' => 'amazon'
                                ]
                            ];

                            $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                            $editHelper->saveProduct([$updateData], $userId, $additionalData);
                        }
                    }
                } else {
                    foreach ($productIds as $ids) {
                        $variant = $this->di->getObjectManager();
                        $help = $variant->get('\App\Connector\Models\Product\Edit');

                        $product = [
                            "container_id" => $ids,
                            'user_id' => $userId,
                            'targetShopID' => $data['target']['shopId'],
                            'target_marketplace' => $data['target']['marketplace'],
                            'source_marketplace' => $data['source']['marketplace'],
                            'sourceShopID' => $data['source']['shopId']
                        ];
                        $variant_product = $help->getProduct($product);
                        if(empty($variant_product['data']['rows'])){
                            $product = [
                                "source_product_id" => $ids,
                                'user_id' => $userId,
                                'targetShopID' => $data['target']['shopId'],
                                'target_marketplace' => $data['target']['marketplace'],
                                'source_marketplace' => $data['source']['marketplace'],
                                'sourceShopID' => $data['source']['shopId']
                            ];
                            $variant_product = $help->getProduct($product);
                        }

                        $variant_product = $variant_product['data']['rows'];

                        foreach ($variant_product as $value) {

                            $updateData = [
                                "source_product_id" => (string)$value['source_product_id'],
                                'user_id' => $userId,
                                'source_shop_id' => $data['source']['shopId'],
                                'target_marketplace' => 'amazon',
                                'shop_id' => $data['target']['shopId'],
                                'container_id' => $value['container_id'],
                                'unset' => [
                                    'asin'=>true
                                ]
                            ];
                            $additionalData = [
                                'source' => [
                                    'shop_id' => $data['source']['shopId'],
                                    'marketplace' => 'shopify'
                                ],
                                'target' => [
                                    'shop_id' => $data['target']['shopId'],
                                    'marketplace' => 'amazon'
                                ]
                            ];

                            $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                            $editHelper->saveProduct([$updateData], $userId, $additionalData);
                        }
                    }
                }
            } else {
                $product = [
                    'user_id' => $userId,
                    'target_shop_id' => $data['target']['shopId'],
                    'source_shop_id' => $data['source']['shopId'],
                    'unset_key' => 'asin'
                ];
                $variant = $this->di->getObjectManager();
                $help = $variant->get('\App\Connector\Models\Product\Marketplace');
                $child = $help->marketplaceUnsetBulk($product);
                return ['success' => true, 'message' => 'bulk action in progress'];
                // $products = $collection->updateMany(['user_id' => $userId], ['$unset' => ['asin' => true]]);
                // return ['success' => true, 'message' => 'bulk action are not working.'];
            }

            return ['success' => true, 'message' => 'ASIN removed successfully from Product(s).'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param $data
     * @return array
     * Removes product from Amazon Listing (amazon_listing)
     */
    public function removeProductFromAmazonListing($data)
    {
        try {
            $userId = $this->di->getUser()->id;
            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')
                ->getCollectionForTable(Helper::AMAZON_LISTING);
            $res = $collection->deleteMany(['user_id' => $userId, 'shop_id' => $data['target']['shopId'], 'manual_mapped' => null]);
            return ['success' => true, 'message' => 'Products removed successfully from Listing(s).'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * remove product-linking from product
     *
     * @param array $data
     * @return array
     */
    public function removeProductFromMatching($data)
    {
        try {
            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')
            ->getCollectionForTable(Helper::AMAZON_LISTING);
            $productIds =  $data['data']['source_product_id'] ?? [];
            $userId = $this->di->getUser()->id;
            $variant = $this->di->getObjectManager();
            $help = $variant->get('\App\Connector\Models\Product\Edit');
            $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
            if (!empty($productIds)) {
                if ($data['data']['isParent'] == false) {
                    foreach ($productIds as $ids) {
                        $product = [
                            "source_product_id" => $ids,
                            'user_id' => $userId,
                            'targetShopID' => $data['target']['shopId'],
                            'target_marketplace' => $data['target']['marketplace'],
                            'source_marketplace' => $data['source']['marketplace'],
                            'sourceShopID' => $data['source']['shopId']
                        ];
                        $variant_product = $help->getProduct($product);
                        $variant_product = $variant_product['data']['rows'];
                        foreach ($variant_product as $value) {
                            $updateData = [
                                "source_product_id" => $value['source_product_id'], // required
                                'user_id' => $userId,
                                'childInfo' => [
                                    'source_product_id' => $value['source_product_id'], // required
                                    'shop_id' => $data['target']['shopId'], // required
                                    'target_marketplace' => 'amazon', //required
                                ],
                                'unset' => [
                                    'status',
                                    'sku',
                                    'target_listing_id',
                                    'matchedwith',
                                    'asin'
                                ]
                            ];
                            $helper->marketplaceSaveAndUpdate($updateData);
                            // unset matching from edited document
                            $asinQuery = [
                                'user_id' => $userId, 'shop_id' => $data['target']['shopId'], 'source_product_id'  => $value['source_product_id'], 'target_marketplace' => 'amazon'
                            ];
                            $option = ['$unset' => [
                                'sku' => 1, 'target_listing_id' => 1, 'asin' => 1, 'matchedwith' => 1, 'status' => 1
                            ]];
                            $helper->updateproductbyQuery($asinQuery, $option);
                            // unset matching from amazon listing

                            $amazonQuery = ['user_id' => $userId, 'shop_id' => $data['target']['shopId'], 'source_product_id'  => $value['source_product_id'], 'source_container_id' => $value['container_id']];

                            $collection->updateOne($amazonQuery, ['$unset' => [
                                'source_product_id' => 1, 'source_variant_id' => 1, 'source_container_id' => 1,
                                'matched' => 1, 'manual_mapped' => 1, 'matchedProduct' => 1, 'matchedwith' => 1
                            ]]);
                        }
                    }
                } else {
                    $variant = $this->di->getObjectManager();
                    $help = $variant->get('\App\Connector\Models\Product\Edit');
                    $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                    foreach ($productIds as $ids) {
                        $product = [
                            "container_id" => $ids,
                            'user_id' => $userId,
                            'targetShopID' => $data['target']['shopId'],
                            'target_marketplace' => $data['target']['marketplace'],
                            'source_marketplace' => $data['source']['marketplace'],
                            'sourceShopID' => $data['source']['shopId']
                        ];
                        $variant_product = $help->getProduct($product);
                        if (empty($variant_product['data']['rows'])) {
                            $product = [
                                "source_product_id" => $ids,
                                'user_id' => $userId,
                                'targetShopID' => $data['target']['shopId'],
                                'target_marketplace' => $data['target']['marketplace'],
                                'source_marketplace' => $data['source']['marketplace'],
                                'sourceShopID' => $data['source']['shopId']
                            ];
                            $variant_product = $help->getProduct($product);
                        }

                        $variant_product = $variant_product['data']['rows'];
                        foreach ($variant_product as $value) {
                            $updateData = [
                                "source_product_id" => $value['source_product_id'], // required
                                'user_id' => $userId,
                                'childInfo' => [
                                    'source_product_id' => $value['source_product_id'], // required
                                    'shop_id' => $data['target']['shopId'], // required
                                    'target_marketplace' => 'amazon', //required
                                ],
                                'unset' => [
                                    'status',
                                    'sku',
                                    'target_listing_id',
                                    'matchedwith',
                                    'asin'
                                ]
                            ];
                            $helper->marketplaceSaveAndUpdate($updateData);
                            // unset matching from edited document
                            $asinQuery = [
                                'user_id' => $userId, 'shop_id' => $data['target']['shopId'], 'source_product_id'  => $value['source_product_id'], 'target_marketplace' => 'amazon'
                            ];
                            $option = ['$unset' => [
                                'sku' => 1, 'target_listing_id' => 1, 'asin' => 1, 'matchedwith' => 1, 'status' => 1
                            ]];
                            $helper->updateproductbyQuery($asinQuery, $option);
                            // unset matching from amazon listing
                            $amazonQuery = ['user_id' => $userId, 'shop_id' => $data['target']['shopId'], 'source_product_id'  => $value['source_product_id'], 'source_container_id' => $value['container_id']];
                            $collection->updateOne($amazonQuery, ['$unset' => [
                                'source_product_id' => 1, 'source_variant_id' => 1, 'source_container_id' => 1,
                                'matched' => 1, 'manual_mapped' => 1, 'matchedProduct' => 1, 'matchedwith' => 1
                            ]]);
                        }
                    }
                }
            } else {
                $product = [
                    'user_id' => $userId,
                    'target_shop_id' => $data['target']['shopId'],
                    'source_shop_id' => $data['source']['shopId'],
                    'source_marketplace' => $data['source']['marketplace']
                ];
                $this->updateBulkMarketplace($product);
                $collection->updateMany(['user_id' => $userId, 'shop_id' => $data['target']['shopId'], 'manual_mapped' => null], ['$unset' => [
                    'source_product_id' => 1, 'source_variant_id' => 1, 'source_container_id' => 1,
                    'matched' => 1, 'manual_mapped' => 1, 'matchedProduct' => 1, 'matchedwith' => 1
                ]]);
                return ['success' => true, 'message' => 'bulk action in progress'];
            }

            return ['success' => true, 'message' => 'Products removed from matching successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * bulk query to remove matching
     *
     * @param array $data
     * @return void
     */
    public function updateBulkMarketplace($data)
    {
        if (!isset($data['user_id'], $data['source_shop_id'], $data['target_shop_id'])) {
            return ['success' => false, 'message' => 'user_id, source_shop_id,or target_shop_id', 'code' => 'data_missing'];
        }

        $userId = $data['user_id'];
        $sourceShopId = $data['source_shop_id'];
        $targetShopId = $data['target_shop_id'];
        $sourceMarketplace = $data['source_marketplace'];
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        $refineCollection = $mongo->getCollectionForTable('refine_product');
        //unset data from edited product
        $unsetKeys = ['status' => 1, 'asin' => 1, 'target_listing_id' => 1, 'matchedwith' => 1, 'sku' => 1];
        $res = $productCollection->updateMany(
            ['user_id' => $userId, 'shop_id' => $targetShopId, 'source_shop_id' => $sourceShopId, 'matchedwith' => ['$ne' => 'manual']],
            ['$unset' => $unsetKeys]
        );
        //unset data from marketplace key
        $unsetKeys = ['marketplace.$.sku' => 1, 'marketplace.$.status' => 1, 'marketplace.$.asin' => 1, 'marketplace.$.target_listing_id' => 1, 'marketplace.$.matchedWith' => 1];
        $res = $productCollection->updateMany(
            ['user_id' => $userId, 'shop_id' => $sourceShopId, 'marketplace.shop_id' => $targetShopId, 'marketplace.matchedwith' => ['$ne' => 'manual']],
            ['$unset' => $unsetKeys]
        );
        //unset data from refine_product
        $unsetKeys = ['items.$[elem].sku' => 1, 'items.$[elem].status' => 1, 'items.$[elem].asin' => 1, 'items.$[elem].target_listing_id' => 1, 'items.$[elem].matchedWith' => 1, 'items.$[elem].matchedWith' => ['$ne' => 'manual']];
        $res = $refineCollection->updateMany(
            ['user_id' => $userId, 'source_shop_id' => $sourceShopId, 'target_shop_id' => $targetShopId, 'items.shop_id' => $targetShopId],
            ['$unset' => $unsetKeys],
            ['arrayFilters' => [['elem.matchedwith' => ['$ne' => 'manual']]]]
        );

        $marketplaceHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
        $marketplaceHelper->createRefinProducts(['source' => ['shopId' => $sourceShopId, 'marketplace' => $sourceMarketplace], 'target' => ['shopId' => $targetShopId, 'marketplace' => 'amazon'], 'user_id' => $userId]);
        return;
    }

    public function removeQueuedTask($data){

        $userId = $this->di->getUser()->id;

        $shopId = $data['target']['shopId'];
        $process_code = $data['data']['Password'];
        $params = [
            'match' => [
                'user_id' => $userId,
                'shop_id' => (string)$shopId,
                'process_code' => $process_code
            ]
        ];
        //@todo : change this function according to connector
        $tasks = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')
            ->getAllQueuedTasks($params, true);
        $tasks = json_decode(json_encode($tasks), true);

        $queuedTaskId = $tasks['data']['rows'][0]['_id']['$oid'];
        if(!empty($queuedTaskId)){
            $result = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($queuedTaskId, 100);
            return ["success"=>true, "data"=> "One task has been removed"];
        }
        return ["success"=>false, "message"=> "Process doesn't exist"];
    }
}
