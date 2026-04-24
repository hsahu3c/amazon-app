<?php

namespace App\Shopifyhome\Components\WebhookHandler;

use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Product\BatchImport;
use App\Shopifyhome\Components\Core\Common;
use function GuzzleHttp\json_encode;
use MongoDB\BSON\UTCDateTime;

class Hook extends Common
{
    public $errorLog;
    public const DEFAULT_VARIANT_COUNT = 100;

    /**
     * moldWebhookData function
     * Molds the webhook data received from Shopify
     * @param [array] $sqsData
     * @return array
     */
    public function moldWebhookData($sqsData)
    {
        $this->errorLog = "shopify/webhookHandler/error/" . date('d-m-Y') . '.log';
        if (
            isset($sqsData['data']['detail']['payload'], $sqsData['app_tag'], $sqsData['topic'])
            && !empty($sqsData['data']['detail']['payload'])
        ) {
            $webhookConfig = $shops = $shopData = [];
            $action = '';
            $userId = $this->di->getUser()->id;
            $appCode = $sqsData['app_code'] ?? 'default';
            $appTag = $sqsData['app_tag'] ?? 'default';
            if (!isset($sqsData['action'])) {
                $webhookConfig = $this->di->getConfig()->webhook->get($appTag)->toArray();
                if (!empty($webhookConfig)) {
                    foreach ($webhookConfig as $webhookData) {
                        if ($webhookData['topic'] == $sqsData['topic']) {
                            $action = $webhookData['action'];
                            break;
                        }
                    }
                } else {
                    $this->di->getLog()->logContent("Unable to get config data for this appTag: "
                        . json_encode($appTag), "info", $this->errorLog);
                }
            } else {
                $action = $sqsData['action'];
            }

            if (!empty($action)) {
                $shops = $this->di->getUser()->shops;
                if (!empty($shops)) {
                    foreach ($shops as $shop) {
                        if ($shop['marketplace'] == $sqsData['marketplace']) {
                            $shopData = $shop;
                            break;
                        }
                    }

                    if (!empty($shopData)) {
                        $shopId = $shopData['_id'];
                        if (
                            isset($sqsData['data']['detail']['errors'][0]['code'])
                            && $sqsData['data']['detail']['errors'][0]['code'] == 'payload_too_large'
                        ) {
                            if (isset($sqsData['data']['detail']['metadata']['X-Shopify-Product-Id'])) {
                                $this->intitateBatchImport(
                                    [
                                        'user_id' => $userId,
                                        'container_id' => $sqsData['data']['detail']['metadata']['X-Shopify-Product-Id'],
                                        'action' => $action,
                                        'sqs_data' => $sqsData
                                    ]
                                );
                            } else {
                                $this->di->getLog()->logContent(
                                    'X-Shopify-Product-Id not found :' .
                                        json_encode(
                                            [
                                                'user_id' => $userId,
                                                'body' => $sqsData
                                            ]
                                        ),
                                    'info',
                                    $this->errorLog
                                );
                            }
                        } else {
                            if ($action == "inventory_levels_update") {
                                $queuePrefix = $this->di->getConfig()->app_code;
                                $handlerData = [
                                    'shop' => $sqsData['username'],
                                    'data' => $sqsData['data']['detail']['payload'],
                                    'type' => 'full_class',
                                    'class_name' => 'App\\Shopifyhome\\Components\\Product\\Inventory\\Hook',
                                    'method' => 'processInventoryLevelsUpdateWebhook',
                                    'user_id' => $userId,
                                    'appCode' => $appCode,
                                    'app_code' => $appCode,
                                    'appTag' =>  $appTag,
                                    'shop_id' => $shopId,
                                    'action' => $action,
                                    'queue_name' => $sqsData['queue_name'] ?? $queuePrefix . '_' . $action,
                                    'marketplace' => $sqsData['marketplace']
                                ];
                                $obj = $this->getDi()->getObjectManager()->get($handlerData['class_name']);
                                $method = $handlerData['method'];
                                return $obj->$method($handlerData);
                            } else {

                                $queuePrefix = $this->di->getConfig()->app_code;
                                $handlerData = [
                                    'shop' => $sqsData['username'],
                                    'data' => $sqsData['data']['detail']['payload'],
                                    'type' => 'full_class',
                                    'class_name' => 'App\\Connector\\Models\\SourceModel',
                                    'method' => 'triggerWebhooks',
                                    'user_id' => $userId,
                                    'appCode' => $appCode,
                                    'app_code' => $appCode,
                                    'appTag' =>  $appTag,
                                    'shop_id' => $shopId,
                                    'action' => $action,
                                    'queue_name' => $sqsData['queue_name'] ?? $queuePrefix . '_' . $action,
                                    'marketplace' => $sqsData['marketplace']
                                ];
                                $obj = $this->getDi()->getObjectManager()->get($handlerData['class_name']);
                                $method = $handlerData['method'];
                                return $obj->$method($handlerData);
                            }
                        }
                    } else {
                        $this->di->getLog()->logContent("shop not found"
                            . json_encode($sqsData), "info", $this->errorLog);
                    }
                }
            } else {
                $this->di->getLog()->logContent("action not found"
                    . json_encode($sqsData), "info", $this->errorLog);
            }
        } else {
            $this->di->getLog()->logContent("Required params (payload/app_tag/topic)missing body: "
                . json_encode($sqsData), "info", $this->errorLog);
        }

        return ['success' => true, 'message' => 'Message Processed'];
    }

    public function intitateBatchImport($requestParams)
    {
        $userId = $requestParams['user_id'] ?? $this->di->getUser()->id;
        $containerId = (string)$requestParams['container_id'];
        $action = $requestParams['action'] ?? 'product_listings_add';
        $marketplace = $this->di->getObjectManager()->get(Shop::class)
            ->getUserMarkeplace() ?? 'shopify';
        $shopData = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')
            ->getDataByUserID($userId, $marketplace);
        $shopId = (string)$shopData['_id'];
        $batchImport = $this->di->getObjectManager()->get(BatchImport::class);
        if ($batchImport->isProductImportRestricted($userId)) {
            $batchImport->checkReauthAndUnassignProduct([
                'user_id' => $userId,
                'shop_id' => $shopId,
                'container_id' => $containerId,
            ]);
            return [
                'success' => true,
                'message' => 'Product Importing cannot be proceeded due to plan restriction'
            ];
        }

        if ($containerId) {
            $requestParams['shop_id'] = $shopId;
            $requestParams['remote_shop_id'] = $shopData['remote_shop_id'];
            $fetchProductVariantCountResponse = $this->fetchProductVariantCount($requestParams);
            if (isset($fetchProductVariantCountResponse['success']) && $fetchProductVariantCountResponse['success']) {

                // Set False for now, to skip products with large variant count$
                // $userHas2048VariantSupport = false;

                $enabledUserIds = $this->di->getCache()->get(BatchImport::CACHE_KEY_2048_VARIANT_SUPPORT_USERS);
                if ($enabledUserIds === null) {
                    $enabledUserIds = $batchImport->fetchAndSet2048VariantSupportUsers();
                }
                $userHas2048VariantSupport = !empty($enabledUserIds) && is_array($enabledUserIds)
                    && in_array((string)$userId, array_map('strval', $enabledUserIds));
                if ($fetchProductVariantCountResponse['data']['variantsCount']['count'] > self::DEFAULT_VARIANT_COUNT && !$userHas2048VariantSupport) {
                    $this->insertProductsWithLargeVariantCount($requestParams, $fetchProductVariantCountResponse['data']);
                    if (isset($shopData['reauth_required']) && $shopData['reauth_required']) {
                        $this->di->getObjectManager()->get('App\Shopifyhome\Components\Shop\Shop')->sendMailForReauthRequired($shopData);
                    } else {
                        $batchImport->unassignProductFromSalesChannel($containerId, $shopData);
                    }
                    return [
                        'success' => true,
                        'message' => 'Product variant count is greater than ' . self::DEFAULT_VARIANT_COUNT
                    ];
                }
            } else {
                $this->di->getLog()->logContent(
                    "Error from fetchProductVariantCount : "
                        . json_encode([
                            'user_id' => $userId,
                            'requestParams' => $requestParams,
                            'fetchProductVariantCountResponse' => $fetchProductVariantCountResponse
                        ]),
                    "info",
                    $this->errorLog
                );
            }
            $preparedData = [
                'product_listing' => [
                    'product_id' => $containerId,
                    'title' => 'Large Product Data'
                ]
            ];
            $data = [
                'data' => $preparedData,
                'user_id' => $userId,
                'shop_id' => $shopId,
                'action' => $action,
            ];
            $handleBatchResponse = $batchImport->handleBatchImport($data, $action);
            if ($handleBatchResponse['success']) {
                return $handleBatchResponse;
            }
            $this->di->getLog()->logContent(
                "Error from intitateBatchImport : "
                    . json_encode([
                        'user_id' => $userId,
                        'requestParams' => $requestParams,
                        'handleBatchResponse' => $handleBatchResponse
                    ]),
                "info",
                $this->errorLog
            );
        }
    }

    public function fetchProductVariantCount($data)
    {
        if (isset($data['user_id'], $data['shop_id']) && !empty($data['container_id'])) {
            $shopData = [];
            if(!empty($data['remote_shop_id'])) {
                $shopData = [
                    'marketplace' => 'shopify',
                    'remote_shop_id' => $data['remote_shop_id']
                ];
            } else {
                $shopData = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class)
                ->getShop($data['shop_id'], $data['user_id'], true);
            }
            if (!empty($shopData)) {
                $remoteShopId = $shopData['remote_shop_id'];
                $query = 'query GetProductVariantsCount {
                    product(id: "gid://shopify/Product/' . $data['container_id'] . '") {
                        title
                        featuredImage {
                            originalSrc
                        }
                        variantsCount {
                        count
                        }
                    }
                }';
                $response = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init($shopData['marketplace'], true)
                    ->call('/shopifygql/query', [], ['shop_id' => $remoteShopId, 'query' => $query], 'POST');
                if (isset($response['success']) && $response['success'] && isset($response['data']['product'])) {
                    return [
                        'success' => true,
                        'data' => $response['data']['product']
                    ];
                } else {
                    $message = json_encode($response);
                }
            } else {
                $message = 'Shop Data not found';
            }
        } else {
            $message = 'Required params (user_id, shop_id, container_id) missing';
        }
        return [
            'success' => false,
            'message' => $message
        ];
    }

    private function insertProductsWithLargeVariantCount($requestParams, $productData)
    {
        $userId = $requestParams['user_id'] ?? $this->di->getUser()->id;
        $shopId = $requestParams['shop_id'];
        $variantCount = $productData['variantsCount']['count'];
        $containerId = (string)$requestParams['container_id'];
        $now = new UTCDateTime((int)(microtime(true) * 1000));
        $filter = [
            'user_id' => $userId,
            'shop_id' => $shopId,
            'container_id' => $containerId
        ];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $restrictedProductsCollection = $mongo->getCollectionForTable('restricted_products');
        $restrictedProductDataUpdate = [
            '$setOnInsert' => [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'marketplace' => 'shopify',
                'container_id' => (string) $containerId,
                'title' => $productData['title'],
                'main_image' => $productData['featuredImage']['originalSrc'] ?? null,
                'error' => 'Product Variant Limit Exceeded',
                'created_at' => $now
            ],
            '$set' => [
                'updated_at' => $now
            ]
        ];
        try {
            $restrictedProductsCollection->updateOne(
                $filter,
                $restrictedProductDataUpdate,
                ['upsert' => true]
            );
        } catch (\Exception $e) {
            $this->errorLog = $this->errorLog ?? "shopify/webhookHandler/error/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent(
                "Error from insertProductsWithLargeVariantCount : "
                    . json_encode([
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                        'container_id' => $containerId,
                        'variant_count' => $variantCount,
                        'error_message' => $e->getMessage()
                    ]),
                "info",
                $this->errorLog
            );
            throw $e;
        }
    }
}