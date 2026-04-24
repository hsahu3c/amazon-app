<?php

/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Product;

use App\Shopifyhome\Components\Core\Common;

#[\AllowDynamicProperties]
class Data extends Common
{
    public $_collection = '';

    public $_error = false;

    public $_userId = 1;

    public function prepare($userId = null): void
    {
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $this->_collection = $mongo->getCollection("product_container");
        $this->_userId = $userId;
    }


    public function prepareQuery($query, $failed_product = false)
    {
        if ($query != '') {
            $filterQuery = [];
            $orConditions = explode('||', (string) $query);

            $orConditionQueries = [];
            foreach ($orConditions as $value) {
                $andConditionQuery = trim($value);
                $andConditionQuery = trim($andConditionQuery, '()');
                $andConditions = explode('&&', $andConditionQuery);
                $andConditionSet = [];

                foreach ($andConditions as $andValue) {
                    $andConditionSet[] = $this->getAndConditions($andValue);
                }

                //                print_r($andConditionSet);die;

                $orConditionQueries[] = [
                    '$and' => $andConditionSet
                ];
            }

            if ($failed_product) {
                $orConditionQueries = [
                    '$and' => [
                        [
                            'details.source_product_id' => [
                                '$in' => $failed_product
                            ]
                        ],
                    ],
                    '$or' => $orConditionQueries
                ];
            } else {

                $orConditionQueries = [
                    '$or' => $orConditionQueries
                ];
            }

            //$orConditionQueries['$and'] = $orConditionQueries;

            return $orConditionQueries;
        }

        return false;
    }


    public function getProductsByQuery($userId = false, $source = false, $cursor = null, $failedProduct = null, $profileId = null)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $shopId = false;
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shopDetails =  $user_details->getDataByUserID($userId, $source);
        if ($shopDetails) {
            $shopId = $shopDetails->id;
        }

        $filterQuery = ['source_marketplace' => $source];
        $customizedFilterQuery = [
            '$and' => [
                ['user_id' => (string)$userId],
                ['visibility' => "Catalog and Search"],
                $filterQuery
            ]
        ];
        if ($filterQuery) {
            $finalQuery = [
                [
                    '$match' => $customizedFilterQuery
                ],
                [
                    '$limit' => ($cursor + 1) * 10
                ],
                [
                    '$skip' => $cursor * 10
                ]
            ];
            $countFinalQuery = [
                [
                    '$match' => $customizedFilterQuery
                ],
                [
                    '$count' => 'count'
                ]
            ];

            $this->di->getLog()->logContent(' GetProductsByQuery finalQuery  == ' . print_r($finalQuery, true), 'info', 'shopify' . DS . $userId . DS . 'product_upload' . DS . date("Y-m-d") . DS . 'product_upload.log');

            $this->di->getLog()->logContent(' GetProductsByQuery countFinalQuery  == ' . print_r($finalQuery, true), 'info', 'shopify' . DS . $userId . DS . 'product_upload' . DS . date("Y-m-d") . DS . 'product_upload.log');

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $mongo->setSource("product_container");
            $collection = $mongo->getCollection();
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $response = $collection->aggregate($finalQuery, $options);
            $countResponse = $collection->aggregate($countFinalQuery, $options);
            $countResponse = $countResponse->toArray();
            $count = $countResponse[0]['count'] ?: 0;
            $selectedProducts = $response->toArray();
            return ['success' => true, 'data' => $selectedProducts, 'total_count' => $count];
        }

        return ['success' => false, 'code' => 'no_products_found', 'message' => 'No products found'];
    }

    public function getAndConditions($andCondition)
    {
        $preparedCondition = [];
        $conditions = ['==', '!=', '!%LIKE%', '%LIKE%', '>=', '<=', '>', '<'];
        $andCondition = trim((string) $andCondition);
        foreach ($conditions as $value) {
            if (str_contains($andCondition, $value)) {
                $keyValue = explode($value, $andCondition);
                $prefix = 'variants';
                if ((trim($keyValue[0]) == 'title') ||
                    (trim($keyValue[0]) == 'site') ||
                    (trim($keyValue[0]) == 'vendor') ||
                    trim($keyValue[0]) == 'primary_category_name' ||
                    (trim($keyValue[0]) == 'primary_category_id') ||
                    (trim($keyValue[0]) == 'source_product_id') ||
                    trim($keyValue[0]) == 'state' || trim($keyValue[0]) == 'site' ||
                    trim($keyValue[0]) == 'listing_type'
                ) {
                    $prefix = 'details';
                }

                $valueOfProduct = trim(addslashes($keyValue[1]));

                if (trim($keyValue[0]) == 'price' || trim($keyValue[0]) == 'quantity') {
                    $valueOfProduct = (int)$valueOfProduct;
                }

                switch ($value) {
                    case '==':
                        $preparedCondition[trim($keyValue[0])] = $valueOfProduct;
                        break;
                    case '!=':
                        $preparedCondition[trim($keyValue[0])] = ['$ne' => $valueOfProduct, '$exists' => true];
                        break;
                    case '%LIKE%':
                        $preparedCondition[trim($keyValue[0])] = ['$regex' => ".*" . $valueOfProduct . ".*", '$options' => 'i'];
                        break;
                    case '!%LIKE%':
                        $preparedCondition[trim($keyValue[0])] = ['$regex' => "^((?!" . $valueOfProduct . ").)*$"];
                        break;
                    case '>':
                        $preparedCondition[trim($keyValue[0])] = ['$gt' => (float)$valueOfProduct];
                        break;
                    case '<':
                        $preparedCondition[trim($keyValue[0])] = ['$lt' => (float)$valueOfProduct];
                        break;
                    case '>=':
                        $preparedCondition[trim($keyValue[0])] = ['$gte' => (float)$valueOfProduct];
                        break;
                    case '<=':
                        $preparedCondition[trim($keyValue[0])] = ['$lte' => (float)$valueOfProduct];
                        break;
                }

                break;
            }
        }

        return $preparedCondition;
    }

    public function initProductUploadQueue($data, $failed_product)
    {
        $response = $this->myfetchProductInChunks($data, $failed_product);
        $data['data']['cursor'] = $response['cursor'];
        $this->di->getLog()->logContent("Next Cursor Data = " . print_r($data, true), 'info', 'shopify' . DS . $data['data']['user_id'] . DS . 'product_upload' . DS . date("Y-m-d") . DS . 'SQS.log');
        $maxLimit = $data['data']['max_limit'];
        $syncStatus = $data['data']['sync_status'];
        $productUploadedCount = $data['data']['uploaded_product_count'];
        $total_count = $data['data']['total_count'];
        $maxCursorLimit = $maxLimit / 10;

        if ($data['data']['cursor'] > $total_count / 10) {
            return true;
        }

        if (isset($response['cursor'])) {
            if ($response['cursor'] >= $maxCursorLimit) {
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                if ($progress && $progress == 100) {
                    $url = false;
                    if (
                        $data['data']['file_path'] &&
                        $data['data']['file_name'] &&
                        file_exists($data['data']['file_path'])
                    ) {
                        $failedProducts = require $data['data']['file_path'];
                        unlink($data['data']['file_path']);
                        $reportFilePath = BP . DS . 'var' . DS . 'failed-products' . DS . $data['data']['file_name'] . '.csv';
                        $url = $this->prepareReportFile($reportFilePath, $failedProducts);
                    }

                    $successCount = true;
                    if ($successCount) {
                        if (
                            $productUploadedCount &&
                            $syncStatus == 'enable'
                        ) {
                            $data['data']['success_message'] = $successCount . ' ' . $this->getProductMessage($successCount) . ' of ' . $data['data']['source'] . ' uploaded successfully on Shopify. ' . $productUploadedCount . ' ' . $this->getProductMessage($productUploadedCount) . ' successfully updated on Shopify.';
                        } else {
                            $data['data']['success_message'] = $successCount . ' ' . $this->getProductMessage($successCount) . ' of ' . $data['data']['source'] . ' uploaded successfully on Shopify.';
                        }
                    } else {
                        $limitExhaustMessage = 'No new products of ' . $data['data']['source'] . ' to upload on Shopify';
                        if (
                            $productUploadedCount &&
                            $syncStatus == 'enable'
                        ) {
                            $data['data']['success_message'] = $productUploadedCount . ' ' . $this->getProductMessage($productUploadedCount) . ' successfully updated on Shopify. ' . $limitExhaustMessage;
                        } else {
                            $data['data']['success_message'] = $limitExhaustMessage;
                        }
                    }

                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], $data['data']['success_message'], 'success', $url);
                }

                $this->di->getLog()->logContent(' Before Return True | Last Cursor = ' . json_encode($response['cursor']), 'info', 'shopify' . DS . $data['data']['user_id'] . DS . 'product_upload' . DS . date("Y-m-d") . DS . 'SQS.log');
                return true;
            }

            $handlerData = $this->di->getObjectManager()->get(Helper::class)->shapeSqsDataForUpload($data);
            $this->pushToQueue($handlerData, 5, 'product_upload');
        } elseif (isset($response['error'])) {
            return $returnArr[] = ['success' => false, 'message' => $response['message']];
        } else {
            return true;
        }

        return true;
    }

    public function getProductMessage($count)
    {
        if ($count > 1) {
            return 'products';
        }
        return 'product';
    }

    public function getProductsCountByQuery($userId = false, $profileId = false, $source = false, $productQuery = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $this->_user_id = $userId;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('profiles');
        $profile = $collection->findOne([
            "profile_id" => (int)$profileId
        ]);
        $uploadedProductIds = $this->getUploadedProductIds($userId);

        $customizedFilterQuery = [
            '$and' => [
                ['user_id' => (string)$userId],
                [
                    'source_product_id' => [
                        '$nin' => $uploadedProductIds
                    ]
                ],
                ["source_marketplace" => $source],
                ['visibility' => 'Catalog and Search'],

            ]
        ];

        $customizedFilterQueryForUploadedProducts = [
            '$and' => [
                ['user_id' => (string)$userId],
                [
                    'source_product_id' => [
                        '$in' => $uploadedProductIds
                    ]
                ],
                ["source_marketplace" => $source],

            ]
        ];
        if ($customizedFilterQuery && $customizedFilterQueryForUploadedProducts) {
            $finalQuery = [
                [
                    '$match' => $customizedFilterQuery
                ],
                [
                    '$count' => 'count'
                ]
            ];

            $uploadedCountFinalQuery = [
                [
                    '$match' => $customizedFilterQueryForUploadedProducts
                ],
                [
                    '$count' => 'count'
                ]
            ];

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $mongo->setSource("product_container");
            $collection = $mongo->getCollection();
            $response = $collection->aggregate($finalQuery);
            $response = $response->toArray();
            $count = isset($response[0]) ? $response[0]['count'] : 0;
            $uploadedCountResponse = $collection->aggregate($uploadedCountFinalQuery);
            $uploadedCountResponse = $uploadedCountResponse->toArray();
            $uploadedCount = isset($uploadedCountResponse[0]) ? $uploadedCountResponse[0]['count'] : 0;

            return [
                'product_count' => $count,
                'product_uploaded_count' => $uploadedCount
            ];
        }

        return false;
    }

    public function getUploadedProductIds($userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('product_upload');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $filters = ['user_id' => $userId];
        $response = $collection->aggregate([
            ['$match' => $filters],
            [
                '$project' => [
                    'source_product_id' => 1
                ]
            ]
        ], $options);
        $response = $response->toArray();

        $productIds = [];
        foreach ($response as $value) {
            $productIds[] = $value['source_product_id'];
        }

        return $productIds;
    }

    public function myfetchProductInChunks($data, $failedProduct)
    {
        $userId = $data['data']['user_id'];
        $cursor = $data['data']['cursor'];
        $profileId = $data['data']['profileId'];
        $sourceShopId = $data['data']['source_shop_id'];
        $feed_id = $data['data']['feed_id'];
        $targetShopId = $data['data']['target_shop_id'];
        $source = $data['data']['source'];
        $total_count = $data['data']['total_count'];
        $productUploadedCount = $data['data']['uploaded_product_count'];
        $syncStatus = $data['data']['sync_status'];
        if (isset($data['data']['is_sync'])) {
            $is_sync = $data['data']['is_sync'];
        }

        $mongo1 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo1->setSource("product_container");

        $collection1 = $mongo1->getCollection();
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("product_upload");

        $collection = $mongo->getCollection();
        $parentproducts_response = $this->getProductsByQuery($userId, $source, $cursor, $failedProduct = false, $profileId = false);
        $productData = $parentproducts_response['data'];
        $count = $createProductCount = 0;
        $allproducts = [];
        if (is_array($productData) && count($productData)) {
            foreach ($productData as $value) {
                $singleproduct = [];
                $product_details = json_decode(json_encode($value), true);
                $count++;
                $productAsin = $product_details['source_product_id'];
                $exist = $collection->findOne(['$and' => [
                    ['user_id' => $userId],
                    ['source_product_id' => $productAsin]
                ]]);
                if (isset($exist['upload_status']) && $exist['upload_status'] == true) {
                    $product_details['shopify_product_id'] = $exist['shopify_product_id'];
                    $mode = 'productUpdate';
                } else {
                    $mode = 'productCreate';
                    $createProductCount++;
                }

                $singleproduct['mode'] = $mode;
                $singleproduct['details'] = $product_details;
                $allvariants = [];
                if ($value['type'] == "variation" && $value['visibility'] == "Catalog and Search") {
                    $allvariants = $collection1->find(['container_id' => (string)$value['container_id'], 'user_id' => $userId, 'visibility' => "Not Visible Individually"])->toArray();
                    $singleproduct['variants'] = $allvariants;
                }

                if ($value['type'] == "simple" && $value['visibility'] == "Catalog and Search") {
                    $allvariants[] = $value;
                }

                $singleproduct['variantcount'] = count($allvariants);
                array_push($allproducts, $singleproduct);
            }
        }

        $pcount = (string)count($productData);

        $this->di->getLog()->logContent(' PRODUCT COUNT  == ' . \GuzzleHttp\json_encode($pcount), 'info', 'shopify' . DS . $userId . DS . 'product_upload' . DS . date("Y-m-d") . DS . 'product_upload.log');

        $response = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', 'true')
            ->call('createproduct', [], ['shop_id' => (string)$targetShopId, 'product_data' => $allproducts], 'POST');

        echo "<pre>";
        var_dump($response);
        echo "</pre>";
        die();

        $this->di->getLog()->logContent(' extension  == ' . print_r($response, true), 'info', 'shopify' . DS . $userId . DS . 'product_upload' . DS . date("Y-m-d") . DS . 'extension.log');

        $this->di->getLog()->logContent(' Query  == ' . print_r($response['query'], true), 'info', 'shopify' . DS . $userId . DS . 'product_upload' . DS . date("Y-m-d") . DS . 'Query.log');

        $response = $response['body'];

        $this->di->getLog()->logContent(' Response Body  == ' . print_r($response, true), 'info', 'shopify' . DS . $userId . DS . 'product_upload' . DS . date("Y-m-d") . DS . 'Res.log');

        foreach ($response['body'] as $key => $bodyresult) {
            $mode_productid = $this->getproductId($key);
            $mode = $mode_productid[0];
            $productAsin = $mode_productid[1];
            $exist_in_upload = $collection->findOne(['$and' => [
                ['user_id' => $userId],
                ['source_product_id' => $productAsin]
            ]]);
            $this->updatecollectionsDataAfterUpload($bodyresult, $mode, $exist_in_upload, $productAsin, $data);
        }

        $cursor++;
        print_r("Cursor = ");
        print_r($cursor);
        return ['success' => true, 'cursor' => $cursor];
    }

    public function getproductId($product_id)
    {
        return explode("_", (string) $product_id);
    }

    public function updatecollectionsDataAfterUpload($result, $mode, $exist_in_upload, $productAsin, $data): void
    {
        $userId = $data['data']['user_id'];
        $profileId = $data['data']['profileId'];
        $sourceShopId = $data['data']['source_shop_id'];
        $filePath = false;
        $fileName = false;
        if (
            isset($data['data']['file_path']) &&
            isset($data['data']['file_name'])
        ) {
            $filePath = $data['data']['file_path'];
            $fileName = $data['data']['file_name'];
        }

        $mongo1 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo1->setSource("product_container");

        $collection1 = $mongo1->getCollection();
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("product_upload");

        $collection = $mongo->getCollection();

        $this->di->getLog()->logContent("Product = " . \GuzzleHttp\json_encode($productAsin) . ' || PRODUCT Exists or Not in product_upload collection  == ' . \GuzzleHttp\json_encode($exist_in_upload), 'info', 'shopify' . DS . $userId . DS . 'product_upload' . DS . date("Y-m-d") . DS . 'product_upload.log');

        if (is_null($exist_in_upload)) {
            if ($mode == "productCreate") {
                if (
                    isset($result['product']) &&
                    !is_null($result['product'])
                ) {
                    $variant_nodes = $result['product']['variants']['edges'];
                    $meta_nodes = $result['product']['metafields']['edges'];
                    $meta_items = [];
                    $id = $result['product']['id'];
                    $items = [];
                    foreach ($variant_nodes as $nodes) {
                        $items[] = [
                            'item_id' => $nodes['node']['inventoryItem']['id'],
                            'variant_id' => $nodes['node']['id'],
                            'sku' => $nodes['node']['sku'],
                            'quantity' => $nodes['node']['inventoryQuantity']
                        ];
                    }

                    foreach ($meta_nodes as $nodes) {
                        $meta_items[$nodes['node']['key']] = $nodes['node']['id'];
                    }

                    $collection->insertOne(
                        [
                            'user_id' => $userId,
                            'source_product_id' => $productAsin,
                            'upload_status' => true,
                            'profile' => (int)$profileId,
                            'shopify_product_id' => $id,
                            'variants' => $items,
                            'metafields' => $meta_items,
                            'source' => $sourceShopId
                        ]
                    );
                    $res = $collection1->updateOne(
                        ['$and' => [
                            ['user_id' => (string)$userId],
                            ['source_product_id' => $productAsin]
                        ]],
                        ['$set' => [
                            "upload_status" => true
                        ]]
                    );
                }
            }
        } else {
            if (isset($result['product']) && !is_null($result['product'])) {
                $variant_nodes = $result['product']['variants']['edges'];
                $meta_nodes = $result['product']['metafields']['edges'];
                $meta_items = [];
                $items = [];
                foreach ($variant_nodes as $nodes) {
                    $items[] = [
                        'item_id' => $nodes['node']['inventoryItem']['id'],
                        'variant_id' => $nodes['node']['id'],
                        'sku' => $nodes['node']['sku'],
                        'quantity' => $nodes['node']['inventoryQuantity']
                    ];
                }

                foreach ($meta_nodes as $nodes) {
                    $meta_items[$nodes['node']['key']] = $nodes['node']['id'];
                }

                $collection->updateOne(
                    ['$and' => [
                        ['user_id' => $userId],
                        ['source_product_id' => $productAsin],
                    ]],
                    ['$set' => [
                        'source' => $sourceShopId,
                        'variants' => $items,
                        'metafields' => $meta_items,
                    ]]
                );
                $res = $collection1->updateOne(
                    ['$and' => [
                        ['user_id' => (string)$userId],
                        ['source_product_id' => $productAsin]
                    ]],
                    ['$set' => [
                        "upload_status" => true
                    ]]
                );
            }
        }
    }

    public function handleProductData($data, $userId = null)
    {
        $this->prepare($userId);
        if ($this->_error) return $this->_error;

        switch ($data['db_action']) {
            case 'variant_update':
                unset($data['db_action']);
                $this->updateProductVariant($data);
                break;

            case 'delete':
                break;

            default:
                return [
                    'success' => false,
                    'message' => "No action defined"
                ];
        }
    }

    public function deleteProducts($userId = null, $locationId = []): void
    {
        $this->prepare($userId);
        $this->_collection->drop();
    }

    public function deleteIndividualVariants($containerId, $products = [], $deleteParent = false, $userId = null): void
    {
        $this->prepare($userId);
        if ($deleteParent) {
            $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(['$and' => [
                ['source_product_id' => $containerId],
                ['type' => 'variation']
            ]], $userId);
            $this->_collection->deleteOne($query);
        } else {
            foreach ($products as $variantId) {
                $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(["source_product_id" => $variantId], $userId);
                $res = $this->_collection->deleteOne($query);
                print_r($res);
            }
        }
    }

    public function getallVariantIds($id, $userId = null)
    {
        $this->prepare($userId);
        $ids = $this->_collection->distinct(
            "source_product_id",
            ["container_id" => $id]
        );
        if (($key = array_search($id, $ids)) !== false) {
            unset($ids[$key]);
        }

        return $ids;
    }

    public function updateProductVariant($data): void
    {

        $prefix = $data['mode'] ?? '';

        $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(['source_product_id' => $data['source_product_id']], $this->di->getUser()->id);
        $out = $this->_collection->findOne($query, ['typeMap' => ['root' => 'array', 'document' => 'array']]);

        $helper = $this->di->getObjectManager()->get(Helper::class);

        $res = $this->_collection->updateOne(
            $query,
            ['$set' => $data]
        );
    }

    public function updateWebhookProductVariants($data, $userId = null): void
    {
        $this->prepare($userId);
        $updateVariant = $this->_collection->updateOne(
            ['details.source_product_id' => $data['source_product_id']],
            [
                '$set' => [
                    'variant_attribute' => $data['attributes']
                ]
            ]
        );
        $this->di->getLog()->logContent('PROCESS 00323 | Data | updateProductVariant ', 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'webhook_product_import.log');
    }

    public function checkProductExists($data, $userId = null)
    {
        $this->prepare($userId);
        $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(['source_product_id' => $data['source_product_id']], $userId);
        $productExists = $this->_collection->findOne($query);
        $this->di->getLog()->logContent("Product Exists == " . json_encode($productExists), 'info', 'shopify' . DS . 'global' . DS . date("Y-m-d") . DS . 'webhook_product_import.log');

        if ($productExists) {
            return [
                'success' => true
            ];
        }
        return [
            'success' => false,
            'msg' => 'product does not exist'
        ];
    }

    public function pushToQueue($data, $time = 0, $queueName = 'temp')
    {
        $data['run_after'] = $time;

        if ($this->di->getConfig()->get('enable_rabbitmq_internal')) {
            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
            return $helper->createQueue($queueName, $data);
        }

        return true;
    }

    public function manageSimpleToVariant($deleteIds, $currentVariantIds, $incomingWebhookProductIds, $containerId)
    {
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollection("product_container");

        foreach($deleteIds as $deleteId)
        {
            $collectionQuery = ['user_id' => (string) $userId, 'source_product_id' => (string) $deleteId];

            $oldSkuEntry = $productCollection->findOne($collectionQuery, ["typeMap" => ['root' => 'array', 'document' => 'array']]);

            if(isset($oldSkuEntry['sku']) && !empty($oldSkuEntry['sku']))
            {
                $oldSku = $oldSkuEntry['sku'];

                $collectionQuery_2 = ['user_id' => (string) $userId, 'sku' => $oldSku, 'source_product_id' => ['$in' => $incomingWebhookProductIds]];

                $findSkuEntry = $productCollection->findOne($collectionQuery_2, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            }
        }

        return ['success' => true, 'message' => 'data_managed'];
    }

    public function deleteNotRequiredJson($containerId, $products = [], $deleteParent = false, $userId = null)
    {
        $this->prepare($userId);
        $userId = $this->_userId;
        $deleted_res = [];
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollection("product_container");

        if ($deleteParent)
        {
            $query = $this->di->getObjectManager()
                ->get(Helper::class)
                ->getAndQuery([
                    'source_product_id' => (string)$containerId,
                    'type' => 'variation'
                ], $userId);


            $deleteParent = $productCollection->deleteOne($query);
            $deleted_res['parent'] = $deleteParent;
        }


        foreach ($products as $variantId)
        {
            $query = $this->di->getObjectManager()
                ->get(Helper::class)
                ->getAndQuery(
                    [
                        "source_product_id" => (string)$variantId
                    ], $userId
                );
            $res = $productCollection->deleteOne($query);
            $deleted_res[$variantId] = $res;
        }

        $this->di->getLog()->logContent('$deleted_res if'.print_r($deleted_res, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'mywebhook_product_import.log');
        return $deleted_res;
    }

}
