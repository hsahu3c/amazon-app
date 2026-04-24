<?php

namespace App\Amazon\Components;

use App\Amazon\Components\Listings\ProductType;

use App\Core\Components\Base;
use App\Amazon\Components\Product\Helper;
use Exception;
use Phalcon\Events\Event;
use App\Connector\Models\Product as ConnectorProductModel;


class ProductEvent extends Base
{

    public const MAX_TARGET_DOCS_LIMIT = 30;

    /**
     * @param $myComponent
     * @param $data
     */
    public function productSaveAfter($event, $myComponent, $data): void
    {

        if (isset($data['seller-sku']) && $data['seller-sku'] != '') {

            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $target_marketplace = 'amazon';
            $user_data = $user_details->getDataByUserID($data['user_id'], $target_marketplace);
            $seller_id = $user_data['warehouses'][0]['seller_id'];

            $updateData = [
                "source_product_id" => $data['source_product_id'], // required
                'user_id' => $data['user_id'],
                'childInfo' => [
                    'source_product_id' => $data['source_product_id'], // required
                    'shop_id' => $user_data['_id'], // required
                    'status' => Helper::PRODUCT_STATUS_UNKNOWN,
                    'target_marketplace' => $target_marketplace, // required
                    'seller_id' => $seller_id
                ]
            ];

            $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
            $response = $helper->marketplaceSaveAndUpdate($updateData);
        }
    }

    public function afterProductImport($event, $myComponent, $data): void
    {
        if ($data['target_marketplace'] == 'amazon') {
            $date = date('d-m-Y');
            $logFile = "amazon/AfterProductImport/{$date}.log";
            $this->di->getLog()->logContent('afterProductImport start event Data = ' . print_r($data, true), 'info', $logFile);

            if (isset($data['target_marketplace']) && $data['target_marketplace'] == 'amazon') {
                $preparedData = [
                    'source_marketplace' => [
                        'source_shop_id' => $data['source_shop_id'], // source shop id
                        'marketplace' => $data['source_marketplace'] // source
                    ],
                    'target_marketplace' => [
                        'target_shop_id' => $data['target_shop_id'], // amazon shop id
                        'marketplace' => $data['target_marketplace'] // amazon
                    ],
                    'user_id' => $data['user_id']
                ];
                $report_response = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\SyncStatus::class)->initiateSyncAfterImport($preparedData);
                $launch = $this->di->getObjectManager()->get(ProductType::class)->initiateProductType($preparedData);
            }

            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shop = $userDetails->getShop($data['source_shop_id'], $this->di->getUser()->id);
            $this->di->getLog()->logContent('afterProductImport event shop Data = ' . print_r($shop, true), 'info', $logFile);

            if (isset($shop['targets']) && is_array($shop['targets'])) {

                foreach ($shop['targets'] as $target) {
                    $marketplace = $target['code'] ?? $target['marketplace'] ?? null;
                    if (isset($marketplace, $target['shop_id']) && $marketplace == 'amazon') {

                        try {

                            //creating products in refine products
                            $prepareRefinedata = [
                                'source' => [ //required
                                    'marketplace' => $data['source_marketplace'],
                                    'shopId' =>  $data['source_shop_id']
                                ],
                                'target' => [ //required
                                    'marketplace' => $data['target_marketplace'],
                                    'shopId' => $target['shop_id']
                                ],
                                'user_id' => $this->di->getUser()->id //required
                            ];

                            $this->di->getLog()->logContent('afterProductImport event refine product params = ' . print_r($prepareRefinedata, true), 'info', $logFile);

                            $marketplaceObj = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                            $res = $marketplaceObj->createRefinProducts($prepareRefinedata);
                            $this->di->getLog()->logContent('afterProductImport event refine product response = ' . print_r($res, true), 'info', $logFile);
                        } catch (Exception $e) {
                            $this->di->getLog()->logContent('creating products in refine product, exception msg = ' . $e->getMessage() . ', trace = ' . json_encode($e->getTrace()), 'info', $logFile);
                        }
                    }
                }
            }

            // $look_up_response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
            //     ->searchFromAmazon($preparedData);
            // $this->di->getLog()->logContent('afterProductImport event Data = ' . print_r($look_up_response, true), 'info', 'AmazonEvent.log');
        }
    }

    public function beforeDisconnect($event, $myComponent, $data): void
    {
        $user_id = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $amazonListing = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);
        $amazonListingResponse = $amazonListing->deleteMany([
            'user_id' => $user_id,
            'shop_id' => $data['custom_data']['_id'],
        ], ['w' => true]);
    }

    public function afterProductCreate(Event $event, $myComponent, $eventData)
    {
        $userId = $eventData['user_id'] ?? $this->di->getUser()->id;

        $configData =  $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
        $appTag = 'amazon_sales_channel';
        $configData->setGroupCode('product');
        $configData->setAppTag($appTag);
        $configData->setUserId($userId);
        $configData->sourceSet('shopify');
        $configData->setTarget('amazon');
        $configData->setSourceShopId($eventData['shop_id'] ?? null);
        $config = $configData->getConfig('auto_offer_listing_creation');

        if ( !isset($config[0]) || empty($config[0]['value']) || $config[0]['value']['enabled'] != true) {
            return;
        }

        $source_shop_id = [];
        $container_id = [];
        $product_info = [];

        if ( isset($eventData['productData']['variants']) && is_array($eventData['productData']['variants']) && count($eventData['productData']['variants']) > 0) {
            foreach ($eventData['productData']['variants'] as $variant) {
                if (isset($variant['source_product_id']) && !empty($variant['source_product_id'])) {
                    $source_shop_id[] = $variant['source_product_id'];
                    $container_id[] = $variant['container_id'] ?? '';
                    $product_info[] = [
                        'source_product_id' => $variant['source_product_id'],
                        'container_id' => $variant['container_id'] ?? '',
                        'title' => $variant['title'] ?? '',
                        'sku' => $variant['sku'] ?? '',
                        'price' => $variant['price'] ?? '',
                        'main_image' => $variant['main_image'] ?? '',
                    ];
                }
            }
        } else if ( isset($eventData['productData']['details']) && isset($eventData['productData']['details']['source_product_id'])) {
            $source_shop_id[] = $eventData['productData']['details']['source_product_id'];
            $container_id[] = $eventData['productData']['details']['container_id'] ?? '';
            $product_info[] = [
                'source_product_id' => $eventData['productData']['details']['source_product_id'],
                'container_id' => $eventData['productData']['details']['container_id'] ?? '',
                'title' => $eventData['productData']['details']['title'] ?? '',
                'sku' => $eventData['productData']['details']['sku'] ?? '',
                'price' => $eventData['productData']['details']['price'] ?? '',
                'main_image' => $eventData['productData']['details']['main_image'] ?? '',
            ];
        }
        $dbData = [
            'user_id' => $userId,
            'source_product_id' => $source_shop_id,
            'container_id' => $container_id,
            'marketplace' => 'amazon',
            'shop_id' => $eventData['shop_id'] ?? null,
            'product_info' => $product_info,
            'last_updated_at' => date('c'),
        ];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $batch_offer_listing_col = $mongo->getCollectionForTable('batch_offer_listing');
        // $batch_offer_listing_col->insertOne($dbData);
        $batch_offer_listing_col->updateOne(
            ['source_product_id' => $dbData['source_product_id']],
            [
                '$set' => $dbData, 
                '$setOnInsert' => ['created_at' => date('c')],
                '$inc' => ['inc' => 1]
            ],
            ['upsert' => true]
        );
        return true;
    }

    public function shopUpdate($event, $myComponent, $data): void
    {
        $logfile = "amazon/shop_update/" . date('d-m-Y') . '.log';
        if (
            isset($data['shop']['marketplace']) && $data['shop']['marketplace'] == 'amazon' && isset($data['remoteData']['rawResponse']['marketplace']) &&
            $data['remoteData']['rawResponse']['marketplace'] == 'amazon'
        ) {
            $this->di->getLog()->logContent('In shopEvent Data : ' . print_r($data, true), 'info', $logfile);
            if (!empty($data['remoteData']['data']['data']['renewal_time'])) {
                $shop = $data['shop'];
                $renewalTime = $data['remoteData']['data']['data']['renewal_time'];
                $shop['renewal_time'] = $renewalTime;
                unset($shop['reauth_required']);
                $this->di->getObjectManager()
                    ->get('App\Core\Models\User\Details')->updateShop($shop, false, ['_id']);
            } elseif (!empty($data['shop']['reauth_required']) && $data['shop']['reauth_required']) {
                $shop = $data['shop'];
                unset($shop['reauth_required']);
                $this->di->getObjectManager()
                    ->get('App\Core\Models\User\Details')->updateShop($shop, false, ['_id']);
            }
            $result = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper')
                ->TokenUpdated($data);
            $this->di->getLog()->logContent('TokenUpdated(), Response: ' . json_encode($result), 'info', $logfile);

            $dataToSent = [
                'user_id' => $this->di->getUser()->id,
                'target_marketplace' => [
                    'marketplace' => $shop['marketplace'],
                    'shop_id' => $shop['_id']
                ]
            ];
            $createSubscriptionResponse = $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                ->createSubscription($dataToSent);
            $this->di->getLog()->logContent('createSubscription(), Response: ' . json_encode($createSubscriptionResponse), 'info', $logfile);
        }
        if (isset($data['shop']['marketplace'])) {
            $this->checkForCurrencyChange($data['shop']);
        }
    }

    public function afterVariantsDelete($event, $myComponent, $eventData): void
    {
        try {
            $logFile = "amazon/afterVariantsDelete/" . $this->di->getUser()->id . "/" . date('d-m-Y') . ".log";
            $userId = $this->di->getUser()->id;
            if (!empty($eventData)) {
                $filter = [];
                $preparedData = [];
                $targetMarketplace = 'amazon';
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $mongoCollection = $mongo->getCollection("product_container");
                foreach ($eventData as $containerId => $data) {
                    $containerId = (string)$containerId;
                    $this->di->getLog()->logContent('Processing container_id: ' . json_encode($containerId), 'info', $logFile);
                    foreach ($data as $variantData) {
                        if ((isset($variantData['container_id'], $variantData['source_product_id'], $variantData['source_marketplace'], $variantData['shop_id']))) {
                            $this->di->getLog()->logContent('Processing variantData for source_product_id: ' . json_encode($variantData['source_product_id']), 'info', $logFile);
                            $sourceShopId = $variantData['shop_id'];
                            $sourceMarketplace = $variantData['source_marketplace'];
                            $preparedData = [
                                'user_id' => $userId,
                                'container_id' => $containerId,
                                'source_shop_id' => $sourceShopId,
                                'source_product_id' => $variantData['source_product_id'],
                                'target_marketplace' => $targetMarketplace
                            ];
                            $options = [
                                'typeMap' => ['root' => 'array', 'document' => 'array'],
                                'limit' => self::MAX_TARGET_DOCS_LIMIT, // To avoid duplicate target documents and query timeout case
                                'projection' => [
                                    'shop_id' => 1,
                                    'sku' => 1,
                                    'target_listing_id' => 1
                                ]
                            ];
                            $targetDocs = $mongoCollection->find(
                                $preparedData,
                                $options
                            )->toArray();
                            if (!empty($targetDocs)) {
                                if (count($targetDocs) == self::MAX_TARGET_DOCS_LIMIT) {
                                    $this->di->getLog()->logContent('Target documents limit reached for source_product_id: ' . json_encode($variantData['source_product_id']), 'info', $logFile);
                                }
                                if ($variantData['type'] == ConnectorProductModel::PRODUCT_TYPE_SIMPLE && $variantData['visibility'] == ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH) {
                                    $filter[] =
                                        [
                                            'updateMany' => [
                                                $preparedData,
                                                [
                                                    '$set' => [
                                                        'toBeDeleted' => true
                                                    ]
                                                ]
                                            ]
                                        ];
                                    $this->di->getLog()->logContent('Variant Delete Case for source_product_id : ' . json_encode($variantData['source_product_id']), 'info', $logFile);
                                } else {
                                    //Multiple amazon shops connected case handling
                                    foreach ($targetDocs as $targetDoc) {
                                        if(isset($targetDoc['sku'], $targetDoc['target_listing_id'])) {
                                            $manualUnmapParams = [
                                                'data' => [
                                                    'source_product_id' => $variantData['source_product_id'],
                                                    'user_id' => $userId,
                                                    'variant_delete' => true
                                                ],
                                                'source' => [
                                                    'shopId' => $sourceShopId,
                                                    'marketplace' => $sourceMarketplace
                                                ],
                                                'target' => [
                                                    'shopId' => $targetDoc['shop_id'],
                                                    'marketplace' => $targetMarketplace
                                                ]
                                            ];
                                            $this->di->getObjectManager()->get(ProductHelper::class)->manualUnmap($manualUnmapParams);
                                            $this->di->getLog()->logContent('Manual unmapping done for targetShopId: ' . json_encode($targetDoc['shop_id']), 'info', $logFile);
                                        }
                                    }
                                    // Deleting doc after unmapping
                                    $filter[] =
                                        [
                                            'deleteMany' => [
                                                $preparedData
                                            ]
                                        ];
                                    $this->di->getLog()->logContent('Manual unmapping done and target docs deleted for source_product_id: ' . json_encode($variantData['source_product_id']), 'info', $logFile);
                                }
                            } else {
                                $this->di->getLog()->logContent('Target documents not found for source_product_id: ' . json_encode($variantData['source_product_id']), 'info', $logFile);
                            }
                        } else {
                            $this->di->getLog()->logContent('Required fields not found in the variant data = ' . json_encode($eventData), 'info', $logFile);
                        }
                    }
                }

                if (!empty($filter)) {
                    $mongoCollection->bulkWrite($filter);
                }
            } else {
                $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                $this->di->getLog()->logContent('afterVariantDelete empty data received for this user backtrace log: ' . json_encode($trace), 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from afterVariantsDelete(): ' . json_encode($e->getMessage()), 'info', $logFile ?? 'exception.log');
            throw $e;
        }
    }

    public function afterProductUpdate($event, $myComponent, $params): void
    {
        try {
            $userId = $this->di->getUser()->id;
            $logFile = "amazon/afterProductUpdate/" . $userId . "/" . date('d-m-Y') . ".log";
            $variantToSimple = $params['variant_to_simple'] ?? [];
            $simpleToVariant = $params['simple_to_variant'] ?? [];
            $simpleToSimple = $params['simple_to_simple'] ?? [];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainerObj = $mongo->getCollection("product_container");
            $amazonListingObj = $mongo->getCollection("amazon_listing");
            $refineProductCollection = $mongo->getCollection("refine_product");
            // $this->di->getLog()->logContent('afterProductUpdate started with params: ' . base64_encode(json_encode($params)), 'info', $logFile);
            if (!empty($variantToSimple)) {
                foreach ($variantToSimple as $containerId => $product) {
                    $this->di->getLog()->logContent('Variant to simple case started for container_id: ' . json_encode($containerId), 'info', $logFile);
                    $productData = $productContainerObj->findOne([
                        'user_id' => $userId,
                        'container_id' => (string)$containerId,
                        'source_product_id' => (string)$containerId,
                        'target_marketplace' => 'amazon'
                    ], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
                    if (!empty($productData)) {
                        // Always only one source_product_id will be present in the array
                        $sourceProductId = (array_keys($variantToSimple[$containerId]))[0];
                        $productContainerObj->updateMany(
                            [
                                'user_id' => $userId,
                                'container_id' => (string)$containerId,
                                'source_product_id' => (string)$containerId,
                                'target_marketplace' => 'amazon'
                            ],
                            [
                                '$set' => [
                                    'source_product_id' => (string)$sourceProductId,
                                    'updated_at' => date('c')
                                ],
                                '$unset' => [
                                    'toBeDeleted' => true
                                ]
                            ]
                        );

                        // To handle Deafult Title case
                        $refineProductCollection->updateMany(
                            [
                                'user_id' => $userId,
                                'container_id' => (string)$containerId,
                                'source_product_id' => (string)$sourceProductId
                            ],
                            ['$pull' => ['items' => ['source_product_id' => (string)$containerId]]]
                        );
                    } else {
                        $this->di->getLog()->logContent('Parent level attribute mapping not found', 'info', $logFile);
                    }
                }
            }

            if (!empty($simpleToVariant)) {
                $newVariant = $params['new_variant'] ?? [];
                foreach ($simpleToVariant as $containerId => $product) {
                    $this->di->getLog()->logContent('Simple to variant case started for container_id: ' . json_encode($containerId), 'info', $logFile);
                    $filter = [];
                    $amazonListingFilter = [];
                    $refineUpdateFilter = [];
                    $targetMarketplace = 'amazon';
                    $findFilter = [
                        'user_id' => $userId,
                        'container_id' => (string)$containerId,
                        'target_marketplace' => $targetMarketplace,
                        'toBeDeleted' => true
                    ];

                    $options = [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'limit' => self::MAX_TARGET_DOCS_LIMIT // To avoid duplicate target documents and query timeout case
                    ];

                    // Finding all target documents because of multiple connected target case
                    $targetOldDocs = $productContainerObj->find(
                        $findFilter,
                        $options
                    )->toArray();
                    if (!empty($targetOldDocs)) {
                        foreach ($targetOldDocs as $oldData) {
                            $skuMatchSourceProductId = '';
                            $productLinked = true;
                            $updatedAt = date('c');
                            $prepareVariantData = [];
                            $refineKeysToSync = [];
                            $queryFilter = [
                                'user_id' => $userId,
                                'shop_id' => $oldData['shop_id'],
                                'container_id' => (string)$oldData['container_id'],
                                'source_product_id' => (string)$oldData['source_product_id'],
                                'target_marketplace' => $targetMarketplace
                            ];
                            $removeKeys = ['_id', 'inventory_fulfillment_latency', 'isAiEnable', 'toBeDeleted'];
                            foreach ($removeKeys as $key) {
                                unset($oldData[$key]);
                            }

                            if (isset($oldData['sku'], $oldData['target_listing_id'], $oldData['matchedwith'])) {
                                foreach ($product as $newData) {
                                    if ($oldData['matchedwith'] == 'manual') {
                                        $matchWithSKU = $newData['sku'];
                                    } else {
                                        $matchWithSKU = $newData['source_sku'];
                                    }
                                    if ($matchWithSKU == $oldData['sku']) {
                                        $skuMatchSourceProductId = $newData['source_product_id'];
                                        break;
                                    }
                                }
                            } else {
                                $productLinked = false;
                            }

                            if (!empty($skuMatchSourceProductId)) {
                                $this->di->getLog()->logContent('skuMatchSourceProductId found for source_product_id: ' . json_encode($oldData['source_product_id']), 'info', $logFile);
                                if (!empty($oldData['category_settings'])) {
                                    // Preparing target doc having same container_id as source_product_id if category settings is present
                                    $parentProductData = $oldData;
                                    $parentProductData['source_product_id'] = $oldData['container_id'];
                                    $parentProductData['updated_at'] = $updatedAt;

                                    // Unsetting the keys that are not needed for the parent product
                                    unset($parentProductData['sku'], $parentProductData['target_listing_id'], $parentProductData['asin'], $parentProductData['matchedwith'], $parentProductData['matched_at'], $parentProductData['fulfillment_type'], $parentProductData['status']);
                                    $productContainerObj->insertOne($parentProductData);

                                    // Preparing target doc for new variants
                                    if (isset($newVariant[$containerId])) {
                                        foreach ($newVariant[$containerId] as $variantData) {
                                            if ($variantData['source_product_id'] != $skuMatchSourceProductId) {
                                                $prepareVariantData[] = [
                                                    'user_id' => $userId,
                                                    'shop_id' => $oldData['shop_id'],
                                                    'container_id' => $oldData['container_id'],
                                                    'source_product_id' => (string)$variantData['source_product_id'],
                                                    'target_marketplace' => $targetMarketplace,
                                                    'source_shop_id' => $oldData['source_shop_id'],
                                                    'created_at' => $variantData['created_at'],
                                                    'updated_at' => $updatedAt
                                                ];
                                            }
                                        }
                                    }

                                    if (!empty($prepareVariantData)) {
                                        $productContainerObj->insertMany($prepareVariantData);
                                    }
                                }

                                // Updating skuMatchedSourceProductId in the target doc
                                $filter[] =
                                    [
                                        'updateOne' => [
                                            $queryFilter,
                                            [
                                                '$set' => [
                                                    'source_product_id' => $skuMatchSourceProductId,
                                                    'updated_at' => $updatedAt
                                                ],
                                                '$unset' => [
                                                    'toBeDeleted' => "",
                                                    'category_settings.attributes_mapping' => ""
                                                ]
                                            ]
                                        ]
                                    ];

                                // Refine keys to be synced
                                $fieldsToCopy = ['status', 'matchedwith', 'fulfillment_type', 'asin', 'target_listing_id', 'shopify_sku'];

                                foreach ($fieldsToCopy as $field) {
                                    if (!empty($oldData[$field])) {
                                        $refineKeysToSync["items.\$[item].{$field}"] = $oldData[$field];
                                    }
                                }

                                if (!empty($refineKeysToSync)) {
                                    $refineFindFilter = [
                                        'user_id' => $userId,
                                        'target_shop_id' => $oldData['shop_id'],
                                        'container_id' => (string)$oldData['container_id'],
                                        'items' => ['$elemMatch' => ['source_product_id' => $skuMatchSourceProductId]]
                                    ];
                                    $refineUpdateFilter[] = [
                                        'updateOne' => [
                                            $refineFindFilter,
                                            [
                                                '$set' => $refineKeysToSync
                                            ],
                                            [
                                                'arrayFilters' => [
                                                    [
                                                        'item.source_product_id' => (string)$skuMatchSourceProductId
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ];
                                }

                                // Updating amazonListing doc with the new source product id
                                $amazonListingFilter[] =
                                    [
                                        'updateOne' => [
                                            [
                                                'user_id' => $userId,
                                                'shop_id' => $oldData['shop_id'],
                                                'source_product_id' => $oldData['source_product_id'],

                                            ],
                                            ['$set' => [
                                                'source_product_id' => $skuMatchSourceProductId,
                                                'updated_at' => $updatedAt,
                                                'matched_updated_at' => $updatedAt
                                            ]]
                                        ]
                                    ];

                            } else {
                                if ($productLinked) {
                                    $manualUnmapParams = [
                                        'data' => [
                                            'source_product_id' => $oldData['source_product_id'],
                                            'user_id' => $userId,
                                            'variant_delete' => true
                                        ],
                                        'source' => [
                                            'shopId' => $oldData['source_shop_id'],
                                            'marketplace' => $params['source_marketplace']
                                        ],
                                        'target' => [
                                            'shopId' => $oldData['shop_id'],
                                            'marketplace' => $targetMarketplace
                                        ]
                                    ];
                                    $this->di->getObjectManager()->get(ProductHelper::class)
                                        ->manualUnmap($manualUnmapParams);
                                    $this->di->getLog()->logContent('Manual unmapping done for source_product_id: ' . json_encode($oldData['source_product_id']), 'info', $logFile);
                                }

                                if (!empty($oldData['category_settings'])) {
                                    // Preparing target doc for new variants
                                    if (isset($newVariant[$containerId])) {
                                        foreach ($newVariant[$containerId] as $variantData) {
                                            $prepareVariantData[] = [
                                                'user_id' => $userId,
                                                'shop_id' => $oldData['shop_id'],
                                                'container_id' => $oldData['container_id'],
                                                'source_product_id' => (string)$variantData['source_product_id'],
                                                'target_marketplace' => $targetMarketplace,
                                                'source_shop_id' => $oldData['source_shop_id'],
                                                'created_at' => $variantData['created_at'],
                                                'updated_at' => $updatedAt
                                            ];
                                        }
                                    }

                                    if (!empty($prepareVariantData)) {
                                        $productContainerObj->insertMany($prepareVariantData);
                                        // Updating old doc as parent doc by assigning container_id as source_product_id
                                        $filter[] =
                                            [
                                                'updateOne' => [
                                                    $queryFilter,
                                                    [
                                                        '$set' => [
                                                            'source_product_id' => (string) $containerId,
                                                            'updated_at' => $updatedAt
                                                        ],
                                                        '$unset' => [
                                                            'toBeDeleted' => true
                                                        ]
                                                    ],
                                                ]
                                            ];
                                    }
                                } else {
                                    $queryFilter['toBeDeleted'] = true;
                                    $filter[] =
                                        [
                                            'deleteOne' => [
                                                $queryFilter
                                            ]
                                        ];
                                }
                            }
                        }

                        if (!empty($filter)) {
                            // $this->di->getLog()->logContent('Product container bulk write filter: ' . json_encode($filter), 'info', $logFile);
                            $productContainerObj->bulkWrite($filter);
                        }

                        if (!empty($amazonListingFilter)) {
                            // $this->di->getLog()->logContent('Amazon listing bulk write filter: ' . json_encode($amazonListingFilter), 'info', $logFile);
                            $amazonListingObj->bulkWrite($amazonListingFilter);
                        }

                        if(!empty($refineUpdateFilter)) {
                            // $this->di->getLog()->logContent('Refine bulk write filter: ' . json_encode($refineUpdateFilter), 'info', $logFile);
                            $refineProductCollection->bulkWrite($refineUpdateFilter);
                        }

                        $this->di->getLog()->logContent('Bulk operations completed for container_id: ' . json_encode($containerId), 'info', $logFile);
                    } else {
                        $this->di->getLog()->logContent('Target documents not found for this container_id: ' . json_encode($containerId), 'info', $logFile);
                    }
                }
            }

            if (!empty($simpleToSimple)) {
                foreach ($simpleToSimple as $containerId => $product) {
                    $this->di->getLog()->logContent('Simple to simple case started for container_id: ' . json_encode($containerId), 'info', $logFile);
                    $filter = [];
                    $amazonListingFilter = [];
                    $refineUpdateFilter = [];
                    $targetMarketplace = 'amazon';
                    $findFilter = [
                        'user_id' => $userId,
                        'container_id' => (string)$containerId,
                        // The key can be added if we are sure that their will be only one document for the container_id each shop_id
                        // 'source_product_id' => $simpleToSimple[$containerId]['old_source_product_id'],
                        'target_marketplace' => $targetMarketplace,
                        'toBeDeleted' => true
                    ];

                    $options = [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'limit' => self::MAX_TARGET_DOCS_LIMIT, // To avoid duplicate target documents and query timeout case
                    ];

                    // Finding all target documents because of multiple connected target case
                    $targetOldDocs = $productContainerObj->find(
                        $findFilter,
                        $options
                    )->toArray();

                    if (!empty($targetOldDocs)) {
                        foreach ($targetOldDocs as $oldData) {
                            $skuMatchSourceProductId = '';
                            $productLinked = true;
                            $updatedAt = date('c');
                            $refineKeysToSync = [];
                            $queryFilter = [
                                'user_id' => $userId,
                                'shop_id' => $oldData['shop_id'],
                                'container_id' => $oldData['container_id'],
                                'source_product_id' => $oldData['source_product_id'],
                                'target_marketplace' => $targetMarketplace
                            ];
                            if (isset($oldData['sku'], $oldData['target_listing_id'], $oldData['matchedwith'])) {
                                unset($product['new_source_product_id'], $product['old_source_product_id']);
                                foreach ($product as $newData) {
                                    if ($oldData['matchedwith'] == 'manual') {
                                        $matchWithSKU = $newData['sku'];
                                    } else {
                                        $matchWithSKU = $newData['source_sku'];
                                    }
                                    if ($matchWithSKU == $oldData['sku']) {
                                        $skuMatchSourceProductId = (string) $newData['source_product_id'];
                                        break;
                                    }
                                }
                            } else {
                                $productLinked = false;
                            }

                            if (!empty($skuMatchSourceProductId)) {
                                $this->di->getLog()->logContent('skuMatchSourceProductId found for source_product_id: ' . json_encode($oldData['source_product_id']), 'info', $logFile);
                                $filter[] =
                                    [
                                        'updateOne' => [
                                            $queryFilter,
                                            [
                                                '$set' => [
                                                    'source_product_id' => $skuMatchSourceProductId,
                                                    'updated_at' => $updatedAt
                                                ],
                                                '$unset' => [
                                                    'toBeDeleted' => true
                                                ]

                                            ],
                                        ]
                                    ];
                                $amazonListingFilter[] =
                                    [
                                        'updateOne' => [
                                            [
                                                'user_id' => $userId,
                                                'shop_id' => $oldData['shop_id'],
                                                'source_product_id' => $oldData['source_product_id'],

                                            ],
                                            [
                                                '$set' => [
                                                    'source_product_id' => $skuMatchSourceProductId,
                                                    'matchedProduct.source_product_id' => $skuMatchSourceProductId
                                                ]

                                            ]
                                        ]
                                    ];

                                    $fieldsToCopy = ['status', 'matchedwith', 'fulfillment_type', 'asin', 'target_listing_id', 'shopify_sku'];

                                foreach ($fieldsToCopy as $field) {
                                    if (!empty($oldData[$field])) {
                                        $refineKeysToSync["items.\$[item].{$field}"] = $oldData[$field];
                                    }
                                }

                                if (!empty($refineKeysToSync)) {
                                    $refineFindFilter = [
                                        'user_id' => $userId,
                                        'target_shop_id' => $oldData['shop_id'],
                                        'container_id' => (string)$oldData['container_id'],
                                        'items' => ['$elemMatch' => ['source_product_id' => $skuMatchSourceProductId]]
                                    ];
                                    $refineUpdateFilter[] = [
                                        'updateOne' => [
                                            $refineFindFilter,
                                            [
                                                '$set' => $refineKeysToSync
                                            ],
                                            [
                                                'arrayFilters' => [
                                                    [
                                                        'item.source_product_id' => (string)$skuMatchSourceProductId
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ];
                                }
                            } else {
                                if ($productLinked) {
                                    $manualUnmapParams = [
                                        'data' => [
                                            'source_product_id' => (string)$oldData['source_product_id'],
                                            'user_id' => $this->di->getUser()->id,
                                            'variant_delete' => true
                                        ],
                                        'source' => [
                                            'shopId' => (string)$oldData['source_shop_id'],
                                            'marketplace' => 'shopify'
                                        ],
                                        'target' => [
                                            'shopId' => (string)$oldData['shop_id'],
                                            'marketplace' => $targetMarketplace
                                        ]
                                    ];
                                    $this->di->getObjectManager()->get(ProductHelper::class)
                                        ->manualUnmap($manualUnmapParams);
                                    $this->di->getLog()->logContent('Manual unmapping done for source_product_id: ' . json_encode($oldData['source_product_id']), 'info', $logFile);
                                }
                                if (!empty($oldData['category_settings'])) {
                                    if (!empty($simpleToSimple[$containerId]['new_source_product_id'])) {
                                        $newSourceProductId = $simpleToSimple[$containerId]['new_source_product_id'];
                                        $filter[] =
                                            [
                                                'updateOne' => [
                                                    $queryFilter,
                                                    [
                                                        '$set' => [
                                                            'source_product_id' => $newSourceProductId,
                                                            'updated_at' => $updatedAt
                                                        ],
                                                        '$unset' => [
                                                            'toBeDeleted' => true
                                                        ]
                                                    ],
                                                ]
                                            ];
                                    }
                                } else {
                                    $queryFilter['toBeDeleted'] = true;
                                    $filter[] =
                                        [
                                            'deleteOne' => [
                                                $queryFilter
                                            ]
                                        ];
                                }
                            }
                        }
                        if (!empty($filter)) {
                            // $this->di->getLog()->logContent('Product container bulk write filter: ' . json_encode($filter), 'info', $logFile);
                            $productContainerObj->bulkWrite($filter);
                        }

                        if (!empty($amazonListingFilter)) {
                            // $this->di->getLog()->logContent('Amazon listing bulk write filter: ' . json_encode($amazonListingFilter), 'info', $logFile);
                            $amazonListingObj->bulkWrite($amazonListingFilter);
                        }

                        if(!empty($refineUpdateFilter)) {
                            // $this->di->getLog()->logContent('Refine bulk write filter: ' . json_encode($refineUpdateFilter), 'info', $logFile);
                            $refineProductCollection->bulkWrite($refineUpdateFilter);
                        }

                        $this->di->getLog()->logContent('Bulk operations completed for container_id: ' . json_encode($containerId), 'info', $logFile);
                    } else {
                        $this->di->getLog()->logContent('Target documents not found for this container_id: ' . json_encode($containerId), 'info', $logFile);
                    }
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from afterProductUpdate(): ' . json_encode($e->getMessage()), 'info', $logFile ??'exception.log');
            throw $e;
        }
    }

    public function afterMetafieldImport($event, $myComponent, $params): void
    {
        $users = $this->di->getConfig()->get('sync_handling_time_users');
        if (!empty($users)) {
            $users = $users->toArray();
            $checkUsers = array_keys($users);
            if (in_array($params['user_id'], $checkUsers)) {
                $object = $this->di->getObjectManager()->get(\App\Amazon\Components\Manual\Helper::class);
                $data = [
                    'user_id' => $params['user_id'],
                    'target_shop_id' => $users[$params['user_id']]
                ];
                $object->syncHandlingTime($data);
            }
        }
    }


    public function afterProductDelete($event, $myComponent, $eventData): void
    {
        // Remove the product from the manually assigned template.
        if (!empty($eventData['user_id']) && !empty($eventData['data'])) {
            $userId = $eventData['user_id'];
            $result = [];
            if (!empty($eventData['data']['profile']) && is_array($eventData['data']['profile'])) {
                $containerId = $eventData['data']['container_id'];
                foreach ($eventData['data']['profile'] as $profileEntry) {
                    if (isset($profileEntry['type']) && $profileEntry['type'] === 'manual') {
                        $profileId = (string) $profileEntry['profile_id'];
                        if (!isset($result[$profileId])) {
                            $result[$profileId] = [];
                        }

                        if (!in_array($containerId, $result[$profileId])) {
                            $result[$profileId][] = $containerId;
                        }
                    }
                }
            }
            if (!empty($result) && !empty($userId)) {
                try {
                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $profileCollection = $mongo->getCollection("profile");
                    $updateQueries = [];
                    foreach ($result as $profileIdStr => $containerIdsToRemove) {
                        $updateQueries[] = [
                            'updateOne' => [
                                [
                                    'user_id' => $userId,
                                    '_id' => new \MongoDB\BSON\ObjectId($profileIdStr)
                                ],
                                [
                                    '$pullAll' => [
                                        'manual_product_ids' => $containerIdsToRemove
                                    ]
                                ]
                            ]
                        ];
                    }
                    if (!empty($updateQueries)) {
                        $profileCollection->bulkWrite($updateQueries);
                    }
                } catch (Exception $e) {
                    $this->di->getLog()->logContent('Exception from getContainerIds(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
                }
            }
        }

        // Code commented to prevent the value mapping syncing in case of listing_remove webhook processing. Because this process is consuming high memory.
        // if (!empty($eventData['data']['profile'])) {
        //     $productDataDeleted = $eventData['data'];
        //     foreach ($productDataDeleted['profile'] as $profile) {
        //         $profile = json_decode(json_encode($profile), true);
        //         $params = [
        //             'target' => [
        //                 'marketplace' => 'amazon',
        //                 'shopId' => $profile['target_shop_id'] ?? ""
        //             ],
        //             'source' => [
        //                 'marketplace' => $productDataDeleted['source_marketplace'] ?? "",
        //                 'shopId' => $productDataDeleted['shop_id'] ?? "",
        //             ],
        //             'profile_id' => (string)$profile['profile_id']['$oid'] ?? ((string)$profile['profile_id'] ?? "")
        //         ];
        //         $this->di->getObjectManager()->get(ValueMapping::class)->valueMapping($params);
        //     }
        // }
    }

    public function afterProductImportChunkProcessed($event, $myComponent, $data)
    {
        if (!empty($data['source'] && !empty($data['sourceProductIds']))) {
            $preparedData = [
                'source_marketplace' => $data['source']['marketplace'],
                'source_shop_id' => $data['source']['shopId'],
                'source_product_ids' => $data['sourceProductIds']
            ];
            $inventoryComponent = $this->di->getObjectManager()->get('\App\Amazon\Components\Inventory\Inventory');
            $inventoryComponent->inventorySyncBackupProcess($preparedData);
        }
        return true;
    }

    public function checkForCurrencyChange($shopData)
    {
        if (isset($shopData['marketplace'], $shopData['_id'])) {
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $configCollection = $mongo->getCollectionForTable('config');
            $options = [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ];
            $configFilter = [
                'group_code' => 'currency',
                'source_shop_id' => (string)$shopData['_id'],
                'key' => 'source_currency',
            ];
            $configSettings = $configCollection->findOne($configFilter, $options);
            if (!empty($configSettings)) {
                $sourceShop = $configSettings['key'] == 'source_currency' ? true : false;
                $shopId =  $sourceShop ? $configSettings['source_shop_id'] : $configSettings['target_shop_id'];
                $currencyChange =  false;
                if ($shopData['_id'] == $shopId && isset($shopData['currency']) && ($shopData['currency'] !== $configSettings['value'])) {
                    $currencyChange = true;
                }

                if ($currencyChange) {
                    $deleteFilter = [];
                    if($this->di->getUser()->id) {
                        $deleteFilter['user_id'] =  $this->di->getUser()->id;
                    }
                    $sourceShop ? $deleteFilter['source_shop_id'] = $shopId : $deleteFilter['target_shop_id'] = $shopId;
                    $deleteFilter['group_code'] = 'currency';
                    $configCollection->deleteMany($deleteFilter);
                }
            }
        }
    }
}
