<?php

namespace App\Connector\Components\Route;

use App\Connector\Models\ProductContainer;
use App\Connector\Models\Product as ConnectorProductModel;
use App\Connector\Models\SourceModel as ConnectorSourceModel;
use App\Connector\Components\Helper as ConnectorHelper;
use App\Connector\Components\ProductHelper as ProductHelper;
use App\Connector\Models\Product\Marketplace as ConnectorMarketplaceModel;
use Exception;

class ProductRequestcontrol extends \App\Core\Components\Base
{
    const EVENTDATA_NEW_VARIANT = 'new_variant';
    const EVENTDATA_NEW_PRODUCT = 'new_product';
    const EVENTDATA_SKU_UPDATE = 'sku_update';
    const EVENTDATA_DELETE = 'delete';
    const EVENTDATA_SIMPLE_TO_VARIANT = 'simple_to_variant';
    const EVENTDATA_VARIANT_TO_SIMPLE = 'variant_to_simple';
    const EVENTDATA_SIMPLE_TO_SIMPLE = 'simple_to_simple';
    const EVENTDATA_PRODUCT_UPDATE = 'updating_product';
    const WEBHOOK_PRODUCT_UPDATE = 'product_update';
    const WEBHOOK_PRODUCT_DELETE = 'product_delete';
    const SOURCE_MARKETPLACE = 'source_marketplace';

    public function handleImport($sqsData)
    {
        $operation = $sqsData['data']['operation'] ?? false;
        switch ($operation) {
            case 'import_products_tempdb':
                $response = $this->ImportProductsInTempdb($sqsData);
                if (isset($response['requeue']) && $response['requeue']) {
                    $this->di->getMessageManager()->pushMessage($response['sqs_data']);
                }
                break;

            case 'tempdb_to_maindb':
                $response = $this->ImportInProductContainer($sqsData);
                if (isset($response['requeue']) && $response['requeue']) {
                    $this->di->getMessageManager()->pushMessage($response['sqs_data']);
                }
                break;

            default:
                break;
        }
        return true;
    }

    public function ImportProductsInTempdb($sqsData)
    {
        $shop = $sqsData['data']['shop'];
        $marketplace = $shop['marketplace'];
        $addNupdate = (isset($sqsData['data']['cursor']) && $sqsData['data']['cursor'] == 1) ? false : true;
        $model = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($shop['marketplace'])->get('source_model'));
        $marketplaceProductsRes = $model->getMarketplaceProducts($sqsData);
        if (isset($marketplaceProductsRes['success']) && $marketplaceProductsRes['success']) {
            if (!empty($marketplaceProductsRes['products']['pushToTempdb'])) {
                $this->pushInTempContainer($marketplaceProductsRes['products']['pushToTempdb'], $marketplace, $sqsData);
            }
            if (!empty($marketplaceProductsRes['products']['pushToMaindb'])) {
                $additional_data['app_code'] = $sqsData['data']['app_code'];
                $additional_data['shop_id'] = $shop['_id'];
                $additional_data['marketplace'] = $marketplace;
                $additional_data['feed_id'] = $sqsData['data']['feed_id'];
                $additional_data['isImported'] = $sqsData['data']['isImported'] ?? ($sqsData['data']['isWebhook'] ?? false);
                $additional_data['webhook_present'] = $sqsData['data']['webhook_present'] ?? false;
                $additional_data['temp_importing'] = $sqsData['data']['temp_importing'] ?? false;
                if (isset($sqsData['data']['target_marketplace'], $sqsData['data']['target_shop_id'])) {
                    $additional_data['target_marketplace'] = $sqsData['data']['target_marketplace'];
                    $additional_data['target_shop_id'] = $sqsData['data']['target_shop_id'];
                }
                $this->pushToProductContainer($marketplaceProductsRes['products']['pushToMaindb'], $additional_data);

                $addProcessedContainerIdsToSqsData = $this->di->getConfig()->get('add_processed_container_ids_to_sqs_data') ?? false;
                if ($addProcessedContainerIdsToSqsData && !empty($sqsData['data']['process_code']) && $sqsData['data']['process_code'] == 'batch_product_import' && !empty($marketplaceProductsRes['products']['pushToMaindb'])) {
                    foreach ($marketplaceProductsRes['products']['pushToMaindb'] as $product) {
                        $sqsData['data']['processed_container_ids'][$product['container_id']] = true;
                    }
                }
            }
            $requeue = false;
            if (!empty($marketplaceProductsRes['nextcursor'])) {
                $sqsData['data']['cursor'] = $marketplaceProductsRes['nextcursor'];
                $requeue = true;
            }
            $tempCollection = $this->productContainer()->getCollection($marketplace . '_product_container');
            if (
                !$requeue &&
                $tempCollection->count([
                    'user_id' => $sqsData['user_id'] ?? $this->di->getUser()->id,
                    'shop_id' => (string) $shop['_id'],
                ]) > 0
            ) {
                $sqsData['data']['operation'] = 'tempdb_to_maindb';
                $sqsData['data']['limit'] = 250;
                $sqsData['data']['cursor'] = 0;
                if (isset($marketplaceProductsRes['message']))
                    $sqsData['data']['import_message'] = $marketplaceProductsRes['message'];
                $requeue = true;
            }

            if (
                isset($sqsData['data']['operation'], $sqsData['data']['total_count']) && $sqsData['data']['operation'] != 'tempdb_to_maindb' && (!empty($marketplaceProductsRes['nextcursor']) || (empty($marketplaceProductsRes['products']) && !$requeue))
            ) {
                $weight = $marketplaceProductsRes['chunk_individual_weight'] ?? $sqsData['data']['individual_weight'];
                $progressUpdate = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['data']['feed_id'], $weight, '', $addNupdate);
            }
            if ((isset($progressUpdate) && $progressUpdate == 100) || !$requeue) {
                if (isset($marketplaceProductsRes['message']))
                    $sqsData['data']['import_message'] = $marketplaceProductsRes['message'];
                $requeue = false;
                $this->updateProgressBarAndAddNotification($sqsData);
            }

            if (!empty($sqsData['data']['cursor']) && $requeue)
                return ['success' => true, 'requeue' => $requeue, 'sqs_data' => $sqsData];
            else
                return ['success' => false, 'requeue' => $requeue, 'sqs_data' => $sqsData];
        } elseif (isset($marketplaceProductsRes['success']) && !$marketplaceProductsRes['success']) {
            $this->di->getLog()->logContent(json_encode($marketplaceProductsRes), 'info', 'connector/' . date('Y-m-d') . '/' . $this->di->getUser()->id . '/marketplaceProductsRes.log');
            $message = $marketplaceProductsRes['message'] ?? 'Products not getting from source marketplace';
            $sqsData['data']['import_message'] = $message;
            $this->updateProgressBarAndAddNotification($sqsData, 'error');
            return ['success' => false, 'message' => $message];
        }
    }

    public function ImportInProductContainer($sqsData)
    {
        $marketplace = $sqsData['data']['shop']['marketplace'];
        $products = $this->getProductsFromTmpDb($sqsData, $marketplace);
        if (count($products) > 0) {
            $additional_data['app_code'] = $sqsData['data']['app_code'];
            $additional_data['shop_id'] = $sqsData['data']['shop']['_id'];
            $additional_data['marketplace'] = $marketplace;
            $additional_data['feed_id'] = $sqsData['data']['feed_id'];
            $additional_data['isImported'] = $sqsData['data']['isImported'] ?? ($sqsData['data']['isWebhook'] ?? false);
            $additional_data['webhook_present'] = $sqsData['data']['webhook_present'] ?? false;
            $additional_data['temp_importing'] = $sqsData['data']['temp_importing'] ?? false;
            if (isset($sqsData['data']['target_marketplace'], $sqsData['data']['target_shop_id'])) {
                $additional_data['target_marketplace'] = $sqsData['data']['target_marketplace'];
                $additional_data['target_shop_id'] = $sqsData['data']['target_shop_id'];
            }
            $this->pushToProductContainer($products, $additional_data);
            return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
        } else {
            $this->updateProgressBarAndAddNotification($sqsData);
        }

        return true;
    }

    public function pushInTempContainer($productData, $marketplace, $sqsData = [])
    {
        if (!isset($sqsData['data']['shop']))
            return ['success' => false, 'message' => 'shop data is required in ' . __FUNCTION__];
        $productContainer = $this->productContainer();
        $bulkArray = [];
        $shop = $sqsData['data']['shop'];
        foreach ($productData as $product) {
            $product['shop_id'] = $shop['_id'];
            $product['source_marketplace'] = $marketplace;
            if (!empty($sqsData['data']['isImported']))
                $product['is_exist'] = true;
            if (isset($product['source_product_id'])) {
                $filter = [
                    'user_id' => $this->di->getUser()->id,
                    'shop_id' => $product['shop_id'],
                    'source_product_id' => $product['source_product_id'],
                ];
                $bulkArray[]['updateOne'] = [
                    $filter,
                    [
                        '$set' => $product,
                    ],
                    ['upsert' => true]
                ];
            }
        }
        $productContainer->executeBulkQuery($marketplace . '_product_container', $bulkArray);
    }

    /**
     * get products from temp_product_container
     * @param array $sqsData
     * @param string $marketplace
     * @return array
     */
    public function getProductsFromTmpDb(array $sqsData, string $marketplace)
    {
        $productContainer = $this->productContainer();
        $simpleProduct = $simpleProducts = $source_product_ids = $containerIds = $conIds = $result = [];
        $limit = $sqsData['data']['limit'];
        $filter = [
            'user_id' => $sqsData['user_id'] ?? $this->di->getUser()->id,
            'shop_id' => $sqsData['data']['shop']['_id'],
        ];
        $options = ['limit' => $limit, 'typeMap' => ['root' => 'array', 'document' => 'array']];
        $simpleProduct = $productContainer->findQuery($marketplace . '_product_container', $filter, $options);

        $containerIds = array_values(array_unique(array_map('strval', array_column($simpleProduct, 'container_id'))));
        $filter = [
            [
                '$match' => [
                    'user_id' => $sqsData['user_id'] ?? $this->di->getUser()->id,
                    'shop_id' => $sqsData['data']['shop']['_id'],
                    'container_id' => ['$in' => $containerIds],
                    'type' => ConnectorProductModel::PRODUCT_TYPE_VARIATION,
                ]
            ],
            [
                '$project' => [
                    '_id' => -1,
                    'container_id' => 1
                ]
            ],
            [
                '$group' => [
                    '_id' => '$container_id',
                ]
            ],
            [
                '$limit' => $sqsData['data']['limit'] ?? 250
            ],
        ];
        $result = $productContainer->executeAggregateQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $filter);
        $conIds = (array_values(array_column($result, '_id')));
        foreach ($simpleProduct as $value) {
            /* Add a check to fetch simple product from tempdb */
            if (in_array($value['container_id'], $conIds) || $value['container_id'] == $value['source_product_id']) {
                $source_product_ids[] = $value['source_product_id'];
                $simpleProducts[] = $value;
            }
        }
        if (!empty($source_product_ids)) {
            $bulkArray = [
                [
                    'deleteMany' => [
                        [
                            'user_id' => $sqsData['user_id'] ?? $this->di->getUser()->id,
                            'shop_id' => $sqsData['data']['shop']['_id'],
                            'source_product_id' => [
                                '$in' => $source_product_ids
                            ]
                        ]
                    ]
                ]
            ];
            $productContainer->executeBulkQuery($marketplace . '_product_container', $bulkArray);
        }
        return $simpleProducts;
    }

    /**
     * This function update progress bar in queued task
     * and add notification
     * also process webhook at the end of importing process
     * @param array $sqsData
     * @param string $severity
     * @return void
     */
    public function updateProgressBarAndAddNotification(array $sqsData, string $severity = 'success')
    {
        // $queuedTask = \App\Connector\Models\QueuedTasks::findFirst([['_id' => $sqsData['data']['feed_id']]]);
        $message = isset($sqsData['data']['import_message']) ? $sqsData['data']['import_message'] : 'Product Import Successfully Completed!';
        $progress = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['data']['feed_id'], 100);
        if (empty($sqsData['data']['isImported'])) {
            $this->processWebhook($sqsData);
            $this->isProductsDeleteAllowWithNoChild($sqsData);
        }
        // $this->di->getLog()->logContent('afterProductImport event Data = ' . print_r($sqsData, true), 'info', 'updateProgressBarAndAddNotification.log');
        // for initiating Product Lookup and Product status Sync
        if (isset($sqsData['data']['target_marketplace'], $sqsData['data']['target_shop_id']) && empty($sqsData['data']['isImported'])) {
            $eventData['source_marketplace'] = $sqsData['data']['source_marketplace'] ?? $sqsData['data']['shop']['marketplace'];
            $eventData['source_shop_id'] = $sqsData['data']['source_shop_id'] ?? $sqsData['data']['shop']['_id'];
            $eventData['target_marketplace'] = $sqsData['data']['target_marketplace'];
            $eventData['target_shop_id'] = $sqsData['data']['target_shop_id'];
            $eventData['isImported'] = $sqsData['data']['isImported'];
            $eventData['user_id'] = $sqsData['user_id'];
            $eventData['additional_data'] = $sqsData['data']['extra_data'] ?? [];
            $eventsManager = $this->di->getEventsManager();
            $eventsManager->fire('application:afterProductImport', $this, $eventData);
            $params = [
                'user_id' => $sqsData['data']['user_id'] ?? $this->di->getUser()->id,
                'source' => [
                    'marketplace' => $sqsData['data']['source_marketplace'] ?? $sqsData['data']['shop']['marketplace'],
                    'shopId' => $sqsData['data']['source_shop_id'] ?? $sqsData['data']['shop']['_id'],
                ]
            ];
            $this->di->getObjectManager()->get(ConnectorMarketplaceModel::class)->syncRefineProducts($params);
        }

        if (!empty($sqsData['data']['isImported'])) {
            if ($severity == 'success') {
                $message = "Products fetched from source catalog, Now being processed to comply with our system requirements.";
                $this->createQueueOperation($sqsData);
            } else {
                $filter = [
                    '$match' => [
                        'user_id' => $sqsData['data']['user_id'],
                        'shop_id' => (string) $sqsData['data']['shop']['_id'],
                    ]
                ];
                $this->unsetIsExistKey($filter, $sqsData);
                $this->processWebhook($sqsData);
            }
        }
        $notificationData = [
            'marketplace' => $sqsData['data']['shop']['marketplace'],
            'user_id' => $sqsData['data']['user_id'],
            'message' => $message,
            'severity' => $severity,
            'process_code' => $sqsData['data']['process_code'] ?? 'product_import'

        ];

        /*Notification Check Added, If ['data']['add_notification'] is set to false, don't add notification*/
        if (
            !isset($sqsData['data']['add_notification'])
            || $sqsData['data']['add_notification']
        ) {
            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($sqsData['data']['shop']['_id'], $notificationData);
        }
    }

    /**
     * This function Create a Sqs Queue For the deletion of 
     * products(source products as well as uploaded products ),
     * that are deleted on source marketplace
     * After re-importing from marketplace
     * @param array $sqsData
     * @return array
     */
    private function createQueueOperation(array $sqsData)
    {
        try {
            $queueData = [
                'user_id' => $sqsData['user_id'] ?? $this->di->getUser()->id,
                'app_tag' => $sqsData['appTag'] ?? $this->di->getAppCode()->getAppTag(),
                'appTag' => $sqsData['appTag'] ?? $this->di->getAppCode()->getAppTag(),
                'message' => "Please wait while the import is in progress.",
                'process_code' => 'variants_delete',
                'marketplace' => $sqsData['data']['shop']['marketplace']
            ];
            $queuedTask = new \App\Connector\Models\QueuedTasks;
            $queuedTaskId = $queuedTask->setQueuedTask($sqsData['data']['shop']['_id'], $queueData);
            if (!$queuedTaskId) {
                return ['success' => false, 'message' => 'Variants delete process is already under progress.'];
            } elseif (isset($queuedTaskId['success']) && !$queuedTaskId['success']) {
                return $queuedTaskId;
            }
            if (isset($sqsData['handle_added']))
                unset($sqsData['handle_added']);
            $sqsData['method'] = 'deleteIsExistsKeyAndVariants';
            $sqsData['queue_name'] = $sqsData['data']['shop']['marketplace'] . '_variant_delete';
            $sqsData['data']['operation'] = 'variants_delete';
            $sqsData['data']['limit'] = 250;
            $sqsData['data']['feed_id'] = $queuedTaskId;
            return [
                'success' => true,
                'message' => 'Variant Deletion Queue created successfully',
                'queue_sr_no' => $this->di->getMessageManager()->pushMessage($sqsData)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Used for Insert and Update of products in product_container
     * @param array $products
     * @param array $additional_data
     * @return array
     */
    public function pushToProductContainer(array $products, array $additional_data)
    {
        $simpleProductCounter = 0;
        $bulkArrayWrite = $bulkOpArray = [
            'skus' => [],
            'existingProducts' => [],
            'getAllSku' => [],
            'source_sku_check' => [],
            'sku_check' => [],
            'parentInDb' => [],
            'parentInBulkArr' => [],
            'marketplaceAll' => []
        ];
        $existing_products = $tempdbOpArray = $marketplaceAll = $eventData = $exists = $bulkOpArrayIsExist = $parentNotExists = $deleteArray = [];
        $bulkOpArray['isImported'] = $additional_data['isImported'] = $additional_data['isImported'] ?? ($additional_data['isWebhook'] ?? false);
        $webhook_present = $additional_data['webhook_present'] ?? false;
        $marketplace = $additional_data['marketplace'];
        $shop_id = $additional_data['shop_id'];
        $productContainer = $this->productContainer();
        $productContainer->resetBulkTracking();
        $eventsManager = $this->di->getEventsManager();
        $containerIdsRes = $this->getAllContainerIds($products);
        $containerIds = $containerIdsRes['containerIds'];
        $getProjection = $this->getProjection();
        $existing_product = $this->getExistingProducts($containerIds, $shop_id, $getProjection['projection']);

        $getAllSku = $this->getAllSkus($products, $shop_id);
        $bulkArrayWrite['getAllSku'] = $bulkOpArray['getAllSku'] = $getAllSku['productSkuInfoArr'];
        $bulkArrayWrite['source_sku_check'] = $bulkOpArray['source_sku_check'] = array_count_values(array_column($getAllSku['result'], 'source_sku'));
        $bulkArrayWrite['sku_check'] = $bulkOpArray['sku_check'] = array_count_values(array_column($getAllSku['result'], 'sku'));
        if (!empty($existing_product))
            $bulkOpArray['isImported'] = true;
        if (!empty($bulkOpArray['isImported'])) {
            foreach ($existing_product as $prepareArray) {
                $parentNotExists[(string) $prepareArray['container_id']] = 1;
                if (isset($prepareArray['visibility']) && $prepareArray['visibility'] == ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH) {
                    $bulkOpArray['parentInDb'][(string) $prepareArray['container_id']]['_id'] = $prepareArray['_id'];
                    unset($parentNotExists[(string) $prepareArray['container_id']]);
                }
                $existing_products[(string) $prepareArray['container_id']][(string) $prepareArray['source_product_id']] = $prepareArray;
            }
            $bulkOpArray['existingProducts'] = $existing_products;
            $productsArray = $products;
        }
        $existingParentIds = array_keys($bulkOpArray['parentInDb'] ?? []);
        $uniqueContainerIds = array_unique(array_column($products, 'container_id'));
        $totalNewContainerIds = count(array_diff($uniqueContainerIds, $existingParentIds));
        $productContainer->setTotalNewProducts($totalNewContainerIds);
        $webhookPresentRes = $this->checkIfWebhookPresent($additional_data, $containerIds, $webhook_present);
        foreach ($products as $key => $productData) {
            if (!empty($parentNotExists[(string)$productData['container_id']])) {
                $parentNotExists[(string) $productData['container_id']] = $productData;
            }
            if (!empty($additional_data['isImported']) && empty($additional_data['isWebhook'])) {
                $this->setIsExistsToProducts($productData, $bulkOpArrayIsExist, $shop_id);
            }
            if ($webhookPresentRes['success'] && isset($webhookPresentRes['source_product_ids'][$productData['source_product_id']])) {
                continue;
            }
            if (!empty($additional_data['isImported'])) {
                $this->HandleReImportingCases($productsArray, $productData, $existing_products, $key, $additional_data, $bulkArrayWrite, $marketplaceAll, $eventData, $deleteArray);
                $exists = $this->existData($existing_products, $productData, 'source_product_id', true);
                $bulkOpArray['sku_check'] = $bulkArrayWrite['sku_check'];
                $bulkOpArray['skus'] += $bulkArrayWrite['skus'];
            } elseif ($bulkOpArray['isImported']) {
                $exists = $this->existData($existing_products, $productData, 'source_product_id', true);
            }

            if (!empty($exists)) {
                $this->updateExistingProduct($productData, $exists, $additional_data, $bulkOpArray, $eventData);
                if (!empty($getProjection['required_updating_products']))
                    $this->getUpdatingProducts($productData, $exists, $eventData, $getProjection);
                $marketplaceAll[] = $productContainer->prepareMarketplace($productData, $exists);
                $exists = [];
            } elseif (!empty($additional_data['update_only'])) {
                continue;
            } else {
                $data = $this->prepareData($productData, $additional_data, $bulkOpArray);
                $res = $productContainer->createProductsAndAttributes([$data], $marketplace, $shop_id, $this->di->getUser()->id, [], $bulkOpArray, $marketplaceAll);
                if (isset($res[0]['success']) && $res[0]['success']) {
                    $simpleProductCounter++;
                }
            }

            $data = [];
        }
        if (!empty($productsArray)) {
            foreach ($productsArray as $newVariant) {
                $eventData[self::EVENTDATA_NEW_VARIANT][$newVariant['container_id']][$newVariant['source_product_id']] = $newVariant;
            }
        }

        if (!empty($parentNotExists)) {
            foreach ($parentNotExists as $variant) {
                unset($bulkOpArray['parentInBulkArr'][$variant['container_id']]);
                $prepareData = $this->prepareData($variant, $additional_data, $bulkOpArray);
                $prepareData['variants'] = [];
                $productContainer->createProductsAndAttributes([$prepareData], $marketplace, $shop_id, $this->di->getUser()->id, [], $bulkOpArray, $marketplaceAll);
            }
        }
        try {

            if (!empty($bulkOpArray)) {
                $marketplaceAll = array_merge($marketplaceAll, $bulkOpArray['marketplaceAll']);
                $this->unsetKeys($bulkOpArray, $bulkArrayWrite);
                if (!empty($additional_data['isImported'])) {
                    if (!empty($deleteArray))
                        $this->di->getObjectManager()->get('App\Connector\Models\Product\Marketplace')->marketplaceDelete($deleteArray);
                    $productContainer->executeBulkQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $bulkArrayWrite);
                }
                $bulkObj = $productContainer->executeBulkQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $bulkOpArray);
                if (!empty($marketplaceAll))
                    $this->di->getObjectManager()->get(ConnectorMarketplaceModel::class)->marketplaceSaveAndUpdate($marketplaceAll, $containerIds);
                if (!empty($additional_data['isImported'])) {
                    $productContainer->executeBulkQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $bulkOpArrayIsExist);
                    if (!empty($eventData[self::EVENTDATA_DELETE])) {
                        $eventsManager->fire('application:afterVariantsDelete', $this, $eventData[self::EVENTDATA_DELETE]);
                        unset($eventData[self::EVENTDATA_DELETE]);
                    }
                    if (!empty($eventData)) {
                        $eventData[self::SOURCE_MARKETPLACE] = $marketplace;
                        $eventsManager->fire('application:afterProductUpdate', $this, $eventData);
                    }
                }
                unset($eventData, $bulkArrayWrite, $bulkOpArray, $bulkOpArrayIsExist, $existing_products, $existing_product, $productsArray, $products, $deleteArray, $marketplaceAll);
                if ($bulkObj['success']) {
                    $returnResult = [
                        'acknowledged' => $bulkObj['bulkObj']->isAcknowledged(),
                        'inserted' => $bulkObj['bulkObj']->getInsertedCount(),
                        'upserted' => $bulkObj['bulkObj']->getUpsertedCount(),
                        'modified' => $bulkObj['bulkObj']->getModifiedCount(),
                        'matched' => $bulkObj['bulkObj']->getMatchedCount(),
                        'mainProductCount' => $simpleProductCounter . ' main product imported successfully'
                    ];
                } else {
                    return [
                        'success' => false,
                        'writeError' => $bulkObj['error'] ?? $bulkObj['bulkObj'],
                    ];
                }
                return [
                    'success' => true,
                    'stats' => $returnResult
                ];
            }
            return [
                'success' => false,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function finalizeSku($sqsData)
    {
        $shopId = $sqsData['data']['shop']['_id'] ?? $sqsData['shop_id'];
        $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
        $productContainer = $this->productContainer();


        $match = [
            '$match' => [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'source_shop_id' => [
                    '$exists' => false
                ]
            ]
        ];
        $filter = [
            $match,
            [
                '$group' => [
                    '_id' => '$source_sku',
                    'swap_sku_count' => ['$sum' => 1],
                    'ids' => ['$first' => '$_id'],
                    'sku' => ['$first' => '$sku'],
                    'source_sku' => ['$first' => '$source_sku'],
                ]
            ],
            [
                '$match' => [
                    '$expr' => ['$ne' => ['$source_sku', '$sku']],
                    'swap_sku_count' => 1
                ]
            ]
        ];
        $result = $productContainer->executeAggregateQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $filter);

        $this->updateDocumentsWithNewSku($result, $productContainer, $sqsData, $match);
        $this->makeSkuAndSourceSkuSame($match, $sqsData, $productContainer);
        $this->findDuplicateSku($match, $sqsData, $productContainer);
        $this->removableDuplicateSkuKey($match, $sqsData, $productContainer);

        if (!empty($sqsData['data']['operation']) && $sqsData['data']['operation'] == 'update_sku_finalize') {
            $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['data']['feed_id'], 100);
            $notificationData = [
                'marketplace' => $sqsData['marketplace'],
                'user_id' => $userId,
                'message' => 'Skus have been successfully updated.',
                'severity' => 'success',
                'process_code' => 'sku_finalization'
            ];
            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($shopId, $notificationData);
        }
    }

    private function makeSkuAndSourceSkuSame($match, $sqsData, $productContainer)
    {
        $filter = [
            $match,
            [
                '$group' => [
                    '_id' => '$source_sku',
                    'source_sku_count' => ['$sum' => 1],
                    'different_count' => [
                        '$sum' => [
                            '$cond' => [
                                [
                                    '$and' => [
                                        ['$ne' => ['$source_sku', '$sku']],
                                    ]
                                ],
                                1,
                                0
                            ]
                        ]
                    ],
                    'ids' => ['$first' => '$_id'],
                ]
            ],
            [
                '$match' => [
                    '$expr' => ['$eq' => ['$source_sku_count', '$different_count']],
                ]
            ]
        ];
        $result = $productContainer->executeAggregateQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $filter);
        return $this->updateDocumentsWithNewSku($result, $productContainer, $sqsData, $match);
    }

    private function findDuplicateSku($match, $sqsData, $productContainer)
    {
        $filter = [
            $match,
            [
                '$group' => [
                    '_id' => '$sku',
                    'sku_count' => ['$sum' => 1],
                    'ids' => ['$first' => '$sku'],
                ]
            ],
            [
                '$match' => [
                    'sku_count' => ['$gt' => 1]
                ]
            ]/* ,
          [
              '$unwind' => '$ids'
          ] */
        ];

        $result = $productContainer->executeAggregateQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $filter);
        return $this->updateDocumentsWithNewSku($result, $productContainer, $sqsData, $match, true);
    }

    private function removableDuplicateSkuKey($match, $sqsData, $productContainer)
    {
        $match['$match']['duplicate_sku'] = true;
        $match['$match']['$expr'] = ['$eq' => ['$sku', '$source_sku']];
        $filter = [
            $match,
            [
                '$project' => [
                    'ids' => '$_id'
                ]
            ]
        ];

        $result = $productContainer->executeAggregateQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $filter);
        if (empty($result))
            return false;

        return $this->updateDocumentsWithNewSku($result, $productContainer, $sqsData, $match);
    }

    private function updateDocumentsWithNewSku($result, $productContainer, $sqsData, $match, $flag = false)
    {
        if ((isset($result['success']) && !$result['success']) || empty($result))
            return false;
        $limit = isset($sqsData['limit']) ? (int) $sqsData['limit'] : 500;
        $marketplace = $sqsData['data']['shop']['marketplace'] ?? $sqsData['marketplace'];
        $marketplaceAll = $containerIds = $eventData = [];
        $totalIdsCount = count($result);
        $totalIds = array_column($result, 'ids');
        for ($i = 0; $i < $totalIdsCount; $i += $limit) {
            $ids = array_slice($totalIds, $i, $limit);
            if ($flag) {
                $match['$match']['sku'] = ['$in' => $ids];
                $filter = $match;
            } else {
                $filter = ['$match' => ['_id' => ['$in' => $ids]]];
            }
            $products = $productContainer->executeAggregateQuery(ConnectorSourceModel::PRODUCT_CONTAINER, [$filter]);
            $bulk = [];
            foreach ($products as $product) {
                if ($flag) {
                    $count[$product['sku']] = isset($count[$product['sku']]) ? $count[$product['sku']] + 1 : 1;
                    if ($count[$product['sku']] === 1)
                        continue;
                    $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                    $counter = (string) $mongo->getCounter($product['source_sku'], $this->di->getUser()->id);
                    $sku = $product['sku'] = (string) $product['source_sku'] . '_' . $counter;
                    $bulk[]['updateOne'] = [
                        [
                            '_id' => $product['_id']
                        ],
                        [
                            '$set' => [
                                'sku' => $sku,
                                'low_sku' => strtolower($sku),
                                'duplicate_sku' => true
                            ]
                        ]
                    ];
                } else {
                    $product['sku'] = $product['source_sku'];
                    $bulk[]['updateOne'] = [
                        [
                            '_id' => $product['_id']
                        ],
                        [
                            '$set' => [
                                'sku' => $product['source_sku'],
                                'low_sku' => strtolower($product['source_sku'])
                            ],
                            '$unset' => [
                                'duplicate_sku' => ''
                            ]
                        ]
                    ];
                }
                $marketplace = $productContainer->prepareMarketplace($product);
                $marketplace['unset'] = ['duplicate_sku'];
                $marketplaceAll[] = $marketplace;
                $containerIds[] = $product['container_id'];
                $eventData[$product['container_id']][$product['source_product_id']] = $product;
            }
            $productContainer->executeBulkQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $bulk);
            if (!empty($marketplaceAll)) {
                $this->di->getObjectManager()->get(ConnectorMarketplaceModel::class)->marketplaceSaveAndUpdate($marketplaceAll, $containerIds);
                $dataForEvent[self::EVENTDATA_SKU_UPDATE] = $eventData;
                $dataForEvent[self::SOURCE_MARKETPLACE] = $marketplace;
                $eventsManager = $this->di->getEventsManager();
                $eventsManager->fire('application:afterProductUpdate', $this, $dataForEvent);
            }
        }
        return true;
    }

    /**
     * unsetKeys used in bulkArray for bulkWrite operations
     * @param mixed $bulkOpArray
     * @param mixed $bulkArrayWrite
     * @return void
     */
    private function unsetKeys(&$bulkOpArray, &$bulkArrayWrite)
    {
        unset(
            $bulkOpArray['isImported'],
            $bulkOpArray['skus'],
            $bulkArrayWrite['existingProducts'],
            $bulkOpArray['existingProducts'],
            $bulkOpArray['getAllSku'],
            $bulkOpArray['counter'],
            $bulkOpArray['parentInBulkArr'],
            $bulkArrayWrite['parentInBulkArr'],
            $bulkOpArray['parentInDb'],
            $bulkArrayWrite['parentInDb'],
            $bulkArrayWrite['skus'],
            $bulkArrayWrite['getAllSku'],
            $bulkArrayWrite['source_sku_check'],
            $bulkOpArray['source_sku_check'],
            $bulkArrayWrite['sku_check'],
            $bulkOpArray['sku_check'],
            $bulkOpArray['marketplaceAll'],
            $bulkArrayWrite['marketplaceAll']
        );
    }

    /**
     * Handling of Importing Cases
     * Variant to Simple
     * Simple to Variant
     * Variant Event
     *
     * @param array $productsArray
     * @param array $productData
     * @param array $existing_products
     * @param mixed $key
     * @param array $additional_data
     * @param array $bulkArrayWrite
     * @param array $marketplaceAll
     * @param array $eventData
     * @return void
     */
    private function HandleReImportingCases(&$productsArray, $productData, $existing_products, $key, $additional_data, &$bulkArrayWrite, &$marketplaceAll, &$eventData, &$deleteArray)
    {
        $productData['variant_attributes'] = isset($productData['variant_attributes']) ? $productData['variant_attributes'] : [];
        $existData = $this->existData($existing_products, $productData, 'container_id');
        $this->variantEvent($productsArray, $existData, $key, $productData, $eventData);
        if (!empty($existData)) {
            $SimpleOrVariantHandlingArr = [
                'existing_products' => $existData,
                'formatted_product' => &$productData,
                'additional_data' => $additional_data,
                'bulkArrayWrite' => &$bulkArrayWrite,
                'eventData' => &$eventData,
                'marketplaceAll' => &$marketplaceAll,
                'marketplaceDeleteArray' => &$deleteArray
            ];
            $this->simpleOrVariantHandling($SimpleOrVariantHandlingArr);
        }
        $existData = [];
    }

    /**
     * Get Sku of all products from product_container
     * 
     * @param array $products
     * @param string $shop_id
     * @return array
     */
    private function getAllSkus(array $products, string $shop_id)
    {
        $allSku = $productSkuInfoArr = $result = [];
        $allSku = array_values(array_unique(array_map('strval', array_column($products, 'sku'))));
        $filter = [
            [
                '$match' => [
                    'user_id' => $this->di->getUser()->id,
                    'shop_id' => $shop_id,
                    '$or' => [
                        ['source_sku' => ['$in' => $allSku]],
                        ['sku' => ['$in' => $allSku]],
                    ],
                ]
            ],
            [
                '$project' => [
                    'source_product_id' => 1,
                    'sku' => 1,
                    'source_sku' => 1,
                    'container_id' => 1,
                    'visibility' => 1
                ]
            ]
        ];
        $result = $this->productContainer()->executeAggregateQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $filter);
        foreach ($result as $productData) {
            $productSkuInfoArr[$productData['container_id']][$productData['source_product_id']][] = $productData;
        }

        return ['productSkuInfoArr' => $productSkuInfoArr, 'result' => $result];
    }

    /**
     * create object of productContainer
     * @return object
     */
    private function productContainer()
    {
        return $this->di->getObjectManager()->get(ProductContainer::class);
    }

    /**
     * Get container_id of all products from array
     * @param array $products
     * @param bool $flag
     * @return array
     */
    private function getAllContainerIds(array $products, bool $flag = false)
    {
        $containerIds = [];
        $containerIds = array_values(array_unique(array_map('strval', array_column($products, 'container_id'))));
        return ['containerIds' => $containerIds];
    }

    /**
     * get projection from config
     * @return array
     */
    private function getProjection()
    {
        $required_updating_products = $this->di->getConfig()->required_updating_products->toArray();
        $required = $required_updating_products['required'] ?? false;
        $connectorProjection = $required_updating_products['projection'];
        $moduleProjection = ($required && !empty($required_updating_products['fields'])) ? $required_updating_products['fields'] : [];
        $dbDataEnabled = ($required && !empty($required_updating_products['dbDataEnabled'])) ? $required_updating_products['dbDataEnabled'] : false;
        $projectionArray = $this->getProjectionArray($connectorProjection, $moduleProjection);
        return [
            'required_updating_products' => $required,
            'projection' => $projectionArray,
            'moduleProjection' => $moduleProjection,
            'dbDataEnabled' => $dbDataEnabled
        ];
    }

    private function getProjectionArray(array $connectorProjection, array $projection)
    {
        $projectionArray = array_merge($connectorProjection, $projection);
        $projectArray = array_fill_keys($projectionArray, 1);
        return $projectArray;
    }

    /**
     * Get container_id of all products from product_container
     * @param array $containerIds
     * @param string $shop_id
     * @return mixed
     */
    private function getExistingProducts(array $containerIds, string $shop_id, array $projection)
    {
        $filter = [
            [
                '$match' => [
                    'user_id' => $this->di->getUser()->id,
                    'shop_id' => $shop_id,
                    'container_id' => [
                        '$in' => $containerIds
                    ]
                ]
            ],
            [
                '$sort' => [
                    'source_product_id' => 1
                ]
            ],
            [
                '$project' => $projection
            ]
        ];

        return $this->productContainer()->executeAggregateQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $filter);
    }

    /**
     * This function checks whether webhook data present 
     * in temp_webhook_container or not.
     * @param array $additional_data
     * @param array $container_ids
     * @param bool $webhook_present
     * @return array
     */
    private function checkIfWebhookPresent(array $additional_data, array $container_ids = [], bool $webhook_present = true): array
    {
        if (!$webhook_present)
            return [
                'success' => false,
                'message' => 'Webhooks not registered.'
            ];
        if (empty($container_ids)) {
            $filter = [
                [
                    '$match' => [
                        'user_id' => $this->di->getUser()->id,
                        'shop_id' => $additional_data['shop_id'],
                    ]
                ],
                [
                    '$project' => [
                        'source_product_ids' => 1
                    ]
                ]
            ];
        } else {
            $filter = [
                [
                    '$match' => [
                        'user_id' => $this->di->getUser()->id,
                        'shop_id' => $additional_data['shop_id'],
                        'container_id' => [
                            '$in' => $container_ids
                        ]
                    ]
                ],
                [
                    '$project' => [
                        'source_product_ids' => 1
                    ]
                ]
            ];
        }

        $result = $this->productContainer()->executeAggregateQuery('temp_webhook_container', $filter);
        $source_product_ids = [];
        if (!empty($result)) {
            foreach ($result as $value) {
                if (isset($value['source_product_ids'])) {
                    $source_product_ids += $value['source_product_ids'];
                }
            }
            return [
                'success' => true,
                'source_product_ids' => $source_product_ids
            ];
        }
        return [
            'success' => false,
            'message' => 'No webhook data found in temp_webhook_container'
        ];
    }

    /**
     * This function process all webhook from temp_webhook_container
     *
     * @param array $sqsData
     * @return array
     */
    public function processWebhook(array $sqsData)
    {
        if (empty($sqsData['data']['webhook_present'])) {
            return [
                'success' => true,
                'message' => 'Webhooks not registered.'
            ];
        }
        $productContainer = $this->productContainer();
        $filter = [
            'user_id' => $sqsData['user_id'] ?? $this->di->getUser()->id,
            'shop_id' => $sqsData['data']['shop']['_id'],
        ];
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
        $result = $productContainer->findQuery(
            'temp_webhook_container',
            $filter,
            $options
        );
        if (!empty($result)) {
            $processMessage = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $ids = [];
            $ids = array_column($result, '_id');
            $bulkArray = [
                [
                    'deleteMany' => [
                        [
                            '_id' => [
                                '$in' => $ids
                            ]
                        ]
                    ]
                ]
            ];
            $productContainer->executeBulkQuery('temp_webhook_container', $bulkArray);
            foreach ($result as $value) {
                if (isset($value['webhook_data'], $value['webhook_data']['action'])) {
                    $value['webhook_data']['forceUpdateCreate'] = true;
                    $processMessage->processMsg($value['webhook_data']);
                }
            }
        }
        return [
            'success' => true,
            'message' => 'Webhook processed successfully'
        ];
    }

    /**
     * This function return common array from both array parameters when container_id and user_id are same
     *
     * @param array $existing_products
     * @param array $productData
     * @return array
     */
    private function existData(array $existing_products, array $productData, $key, $flag = false): array
    {
        if ($flag && isset($productData['container_id'], $productData[$key])) {
            $existData = isset($existing_products[$productData['container_id']][$productData[$key]]) ? ($existing_products[$productData['container_id']][$productData[$key]]) : [];
        } else {
            $existData = isset($existing_products[$productData['container_id']]) ? ($existing_products[$productData['container_id']]) : [];
        }
        return $existData;
    }

    /**
     * update existing products in product_container
     * @param array $data
     * @param array $exists
     * @param array $additional_data
     * @param array $bulkOpArray
     * @param array $tempdbOpArray
     * @return void
     */
    private function updateExistingProduct(array &$data, array $exists, array $additional_data, array &$bulkOpArray, array &$eventData)
    {

        $eventsManager = $this->di->getEventsManager();
        $marketplace = $additional_data['marketplace'];
        $shop_id = $additional_data['shop_id'];
        if (isset($data['marketplace'])) {
            unset($data['marketplace']);
        }
        if (isset($additional_data['app_code'])) {
            $app_code = $additional_data['app_code'];
            if (isset($exists['app_codes'])) {
                if (!in_array($app_code, $exists['app_codes'])) {
                    $exists['app_codes'][] = $app_code;
                    $data['app_codes'] = $exists['app_codes'];
                }
            } else {
                $data['app_codes'] = [$app_code];
            }
        }
        $data['shop_id'] = $shop_id;
        $data['source_marketplace'] = $marketplace;
        unset($data['created_at']);
        if (!empty($eventData[self::EVENTDATA_SIMPLE_TO_VARIANT][$exists['container_id']][$exists['source_product_id']])) {
            unset($eventData[self::EVENTDATA_DELETE][$exists['container_id']]);
            $productContainer =  $this->productContainer();
            $exists['_id'] = (string) $productContainer->getCounter('product_id');
            $data['created_at'] = date('c');
            $upsert = ['upsert' => true];
        }
        $query = [
            '_id' => $exists['_id']
        ];
        unset($data['_id']);

        $data['type'] = isset($data['type']) && $data['type'] == ConnectorProductModel::PRODUCT_TYPE_VARIATION || !empty($data['is_parent']) ? ConnectorProductModel::PRODUCT_TYPE_VARIATION : ConnectorProductModel::PRODUCT_TYPE_SIMPLE;

        $variantCount = isset($data['variant_attributes']) ? count($data['variant_attributes']) : 0;

        $data['visibility'] = ($variantCount === 0 || $data['type'] === ConnectorProductModel::PRODUCT_TYPE_VARIATION) ? ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH : ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY;

        $this->di->getObjectManager()->get(ProductHelper::class)->finalizeProduct(['product_data' => &$data, 'bulkOpArray' => &$bulkOpArray]);

        if ($data['visibility'] == ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY) {
            if (isset($data['variant_image'])) {
                $data['main_image'] = $data['variant_image'];
            }
        }
        $bulkOpArray[] = ['updateOne' => [$query, ['$set' => $data], $upsert ?? []]];
    }

    private function getUpdatingProducts($productData, $exists, &$eventData, $getProjection)
    {
        $dbData = [];
        $dbDataEnabled = $getProjection['dbDataEnabled'];
        $productToUpdate = false;
        foreach ($getProjection['moduleProjection'] as $value) {
            if (isset($productData[$value], $exists[$value]) && $productData[$value] != $exists[$value]) {
                $productToUpdate = true;
                if (!$dbDataEnabled) {
                    break;
                }
                $dbData[$value] = $exists[$value];
            }
        }
        if (!$productToUpdate) {
            return null;
        }
        if (!empty($dbData)) {
            $productData['db_data_enabled'] = $dbData;
        }
        $containerId = $productData['container_id'];
        $sourceProductId = $productData['source_product_id'];
        $eventData[self::EVENTDATA_PRODUCT_UPDATE][$containerId][$sourceProductId] = $productData;
    }

    /**
     * prepare products for insertion in product_container
     * @param array $productData
     * @param array $additional_data
     * @param array $bulkOpArray
     * @return array
     */
    private function prepareData(array $productData, array $additional_data, array $bulkOpArray)
    {
        $data = [];
        $variantData = $this->getChildProducts($productData, $additional_data['marketplace'], $additional_data['shop_id']);
        $data['variant_attribute'] = $variantData['variant_attributes'];
        if (!empty($productData['is_parent'])) {
            $data['details'] = $this->di->getObjectManager()->get(ConnectorHelper::class)->formatCoreDataForDetail($productData);
            if ($variantData['childPresent']) {
                foreach ($variantData['variants'] as $key => $variant) {
                    unset($variant['_id']);
                    $variants = $variant;
                    $this->prepareVariant($additional_data, $data, $variants);
                    $data['variants'][] = $variants;
                }
            } else {
                $this->prepareVariant($additional_data, $data);
                $data['variants'] = [];
            }
        } else {
            $data['details'] = [];
            if (isset($productData['title'], $productData['container_id']) && !empty($productData['title']) && !isset($bulkOpArray['parentInBulkArr'][$productData['container_id']]))
                $data['details'] = $this->di->getObjectManager()->get(ConnectorHelper::class)->formatCoreDataForDetail($productData);
            foreach ($variantData['variants'] as $key => $variant) {
                unset($variant['_id']);
                $variants = $variant;
                $this->prepareVariant($additional_data, $data, $variants);
                $data['variants'][] = $variants;
            }
        }
        return $data;
    }


    /**
     * This function check after re-import a product is 
     * changed from Simple to Variant or Vice-Versa
     *
     * @param array $SimpleOrVariantHandlingArr
     * 
     * @return void
     */
    private function simpleOrVariantHandling(array $SimpleOrVariantHandlingArr): void
    {
        $formatted_product = $SimpleOrVariantHandlingArr['formatted_product'];
        $existing_products = $SimpleOrVariantHandlingArr['existing_products'];
        if (isset($formatted_product['variant_attributes']) && count($formatted_product['variant_attributes']) > 0 && !empty($existing_products)) {
            foreach ($existing_products as $existing_product) {
                //Code for updating variant attributes for parent product and simple to variant
                if (
                    isset($existing_product['visibility'], $existing_product['type'])
                    && $existing_product['visibility'] == ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH
                    && $existing_product['type'] == ConnectorProductModel::PRODUCT_TYPE_SIMPLE
                ) {
                    if (!isset($SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_SIMPLE_TO_VARIANT][$formatted_product['container_id']])) {
                        $this->simpleToVariant($existing_product, $SimpleOrVariantHandlingArr);
                        break;
                    } elseif (!isset(
                        $SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_DELETE][$existing_product['container_id']][$existing_product['source_product_id']]
                    )) {
                        $SimpleOrVariantHandlingArr['marketplaceDeleteArray']['deleteArray'][] =
                            [
                                'source_product_id' => $existing_product['source_product_id'],
                                'type' => $existing_product['visibility'],
                                'source_shop_id' => $existing_product['shop_id']
                            ];
                        $SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_DELETE][$existing_product['container_id']][$existing_product['source_product_id']] = $existing_product;
                    }

                    if (isset($SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_SIMPLE_TO_VARIANT][$formatted_product['container_id']]) && $existing_product['source_product_id'] == $formatted_product['source_product_id']) {
                        $SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_SIMPLE_TO_VARIANT][$formatted_product['container_id']][$formatted_product['source_product_id']] = $formatted_product;
                     }
                }
            }
        }
        // Code for updating variation product into simple product
        elseif (isset($formatted_product['variant_attributes']) && count($formatted_product['variant_attributes']) == 0 && !empty($existing_products)) {
            if (count($existing_products) > 1) {
                foreach ($existing_products as $existing_product) {
                    if (
                        isset($existing_product['visibility']) &&
                        $existing_product['visibility'] == ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY
                        && $existing_product['type'] == ConnectorProductModel::PRODUCT_TYPE_SIMPLE &&
                        ($existing_product['source_product_id'] == $formatted_product['source_product_id'])
                    ) {
                        $SimpleOrVariantHandlingArr['bulkArrayWrite'][]['deleteOne'] = [['_id' => $existing_product['_id']]];
                        $SimpleOrVariantHandlingArr['marketplaceDeleteArray']['deleteArray'][] =
                            [
                                'source_product_id' => $existing_product['source_product_id'],
                                'type' => $existing_product['visibility'],
                                'source_shop_id' => $existing_product['shop_id']
                            ];
                        $SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_DELETE][$existing_product['container_id']][$existing_product['source_product_id']] = $existing_product;
                    } elseif (
                        isset($existing_product['visibility']) &&
                        $existing_product['visibility'] == ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH
                        && $existing_product['type'] == ConnectorProductModel::PRODUCT_TYPE_VARIATION
                    ) {
                        $this->VariantToSimple($existing_product, $SimpleOrVariantHandlingArr);
                    }
                }
            } else {
                $dbProduct = reset($existing_products) ?? [];
                if (
                    isset($dbProduct['variant_attributes'], $dbProduct['visibility'], $dbProduct['type'])
                    && count($dbProduct['variant_attributes']) == 0
                    && $dbProduct['visibility'] == ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH
                    && $dbProduct['type'] == ConnectorProductModel::PRODUCT_TYPE_SIMPLE
                    && $dbProduct['source_product_id'] != $formatted_product['source_product_id']
                ) {
                    if($dbProduct['type'] == ConnectorProductModel::PRODUCT_TYPE_SIMPLE && $formatted_product['type'] == ConnectorProductModel::PRODUCT_TYPE_SIMPLE) {
                        $SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_SIMPLE_TO_SIMPLE][$formatted_product['container_id']][$formatted_product['source_product_id']] = $formatted_product;
                        $SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_SIMPLE_TO_SIMPLE][$formatted_product['container_id']]['old_source_product_id'] = $dbProduct['source_product_id'];
                        $SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_SIMPLE_TO_SIMPLE][$formatted_product['container_id']]['new_source_product_id'] = $formatted_product['source_product_id'];
                    }
                    $SimpleOrVariantHandlingArr['marketplaceDeleteArray']['deleteArray'][] = [
                        'source_product_id' => $dbProduct['source_product_id'],
                        'type' => $dbProduct['visibility'],
                        'source_shop_id' => $dbProduct['shop_id']
                    ];
                    $SimpleOrVariantHandlingArr['bulkArrayWrite'][]['deleteOne'] = [['_id' => $dbProduct['_id']]];
                    $SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_DELETE][$dbProduct['container_id']][$dbProduct['source_product_id']] = $dbProduct;
                }
            }
        }
    }

    /**
     * This function change Simple Product to Parent Product
     *
     * @param object [mongo Object] $existing_product
     * @param array $SimpleOrVariantHandlingArr
     * 
     * @return void
     */
    private function simpleToVariant($existing_product, $SimpleOrVariantHandlingArr): void
    {
        $eventsManager = $this->di->getEventsManager();
        $productContainer = $this->productContainer();
        $formatted_product = &$SimpleOrVariantHandlingArr['formatted_product'];
        $additional_data = $SimpleOrVariantHandlingArr['additional_data'];
        $parentProduct = $this->di->getObjectManager()->get(ConnectorHelper::class)->formatCoreDataForDetail($formatted_product);
        $parentProduct['app_codes'] = [$additional_data['app_code']];
        $parentProduct['shop_id'] = $additional_data['shop_id'];
        $parentProduct['source_marketplace'] = $additional_data['marketplace'];
        $parentProduct = $productContainer->validateDetailsData($parentProduct);
        $parentProduct['type'] = ConnectorProductModel::PRODUCT_TYPE_VARIATION;
        $parentProduct['visibility'] = ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH;
        if (isset($parentProduct['price'], $parentProduct['quantity'])) {
            unset($parentProduct['price'], $parentProduct['quantity'], $parentProduct['marketplace']);
        }
        $SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_DELETE][$existing_product['container_id']][$existing_product['source_product_id']] = $existing_product;
        $sku = $formatted_product['sku'] ?? ($formatted_product['source_product_id'] ?? false);
        if (!empty($sku) && isset($existing_product['source_sku'], $SimpleOrVariantHandlingArr['bulkArrayWrite']['sku_check'][$sku]) && $sku == $existing_product['source_sku']) {
            $SimpleOrVariantHandlingArr['bulkArrayWrite']['sku_check'][$existing_product['sku']] =
                $SimpleOrVariantHandlingArr['bulkArrayWrite']['sku_check'][$existing_product['sku']] - 1;
            $formatted_product['sku'] = $existing_product['sku'];
        }
        $this->di->getObjectManager()->get(ProductHelper::class)->finalizeProduct(['product_data' => &$parentProduct, 'bulkOpArray' => &$SimpleOrVariantHandlingArr['bulkArrayWrite']]);
        $SimpleOrVariantHandlingArr['bulkArrayWrite'][]['updateOne'] = [
            [
                '_id' => $existing_product['_id']
            ],
            [
                '$unset' => [
                    'price' => '',
                    'quantity' => '',
                ],
                '$set' => $parentProduct
            ]
        ];

        $SimpleOrVariantHandlingArr['marketplaceAll'][] = $productContainer->prepareMarketplace($parentProduct);
        $SimpleOrVariantHandlingArr['marketplaceDeleteArray']['deleteArray'][] = [
            'source_product_id' => $existing_product['source_product_id'],
            'type' => ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH,
            'source_shop_id' => $existing_product['shop_id']
        ];
        $SimpleOrVariantHandlingArr['marketplaceDeleteArray']['deleteArray'][] = [
            'source_product_id' => $existing_product['source_product_id'],
            'type' => ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY
        ];
        $SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_SIMPLE_TO_VARIANT][$formatted_product['container_id']][$formatted_product['source_product_id']] = $formatted_product;
    }


    /**
     * This function changes the Parent Product to Simple Product
     *
     * @param object [mongo object] $existing_product
     * @param array $SimpleOrVariantHandlingArr
     * @return void
     */
    private function VariantToSimple($existing_product, $SimpleOrVariantHandlingArr): void
    {
        $eventsManager = $this->di->getEventsManager();
        $additional_data = $SimpleOrVariantHandlingArr['additional_data'];
        $formatted_product = $SimpleOrVariantHandlingArr['formatted_product'];
        $dbSameSourceProduct  = $SimpleOrVariantHandlingArr['existing_products'][$formatted_product['source_product_id']] ?? [];
        $formatted_product['app_codes'] = [$additional_data['app_code']];
        $formatted_product['shop_id'] = $additional_data['shop_id'];
        $formatted_product['source_marketplace'] = $additional_data['marketplace'];
        $formatted_product['type'] = ConnectorProductModel::PRODUCT_TYPE_SIMPLE;
        $formatted_product['visibility'] = ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH;
        $formatted_product = $this->productContainer()->validateVariantData($formatted_product);
        $this->di->getObjectManager()->get(ProductHelper::class)->finalizeProduct(['product_data' => &$formatted_product, 'bulkOpArray' => &$SimpleOrVariantHandlingArr['bulkArrayWrite']]);

        $this->setRequiredFields($formatted_product,$dbSameSourceProduct);
        $SimpleOrVariantHandlingArr['bulkArrayWrite'][]['updateOne'] = [
            [
                '_id' => $existing_product['_id']
            ],
            [
                '$set' => $formatted_product,
            ]
        ];
        $SimpleOrVariantHandlingArr['eventData'][self::EVENTDATA_VARIANT_TO_SIMPLE][$formatted_product['container_id']][$formatted_product['source_product_id']] = $formatted_product;
    }

    private function setRequiredFields(&$formatted_product , $dbSameSourceProduct){
        $keys = [
            'inventory_management',
            'requires_shipping',
            'inventory_tracked',
            'inventory_policy',
        ];
        foreach($keys as $key){
            if(!array_key_exists($key,$formatted_product) && isset($dbSameSourceProduct[$key])){
                $formatted_product[$key] = $dbSameSourceProduct[$key];
            }
        }
    }

    /**
     * This function calculates the total number of new variants
     * added after Re-importing
     *
     * @param array $productsArray
     * @param array $existing_products
     * @param mixed $key
     * @param array $productData
     * @param array $eventData
     * @return void
     */
    private function variantEvent(array &$productsArray, array $existing_products, $key, array $productData, array &$eventData)
    {
        if (isset($productData['variant_attributes']) && count($productData['variant_attributes']) > 0 && !empty($existing_products)) {
            if (isset($productData['source_product_id'], $existing_products[$productData['source_product_id']])) {
                unset($productsArray[$key]);
            }
        } elseif (!empty($existing_products) && isset($productData['variant_attributes']) && count($productData['variant_attributes']) == 0) {
            unset($productsArray[$key]);
        } elseif (empty($existing_products)) {
            $eventData[self::EVENTDATA_NEW_PRODUCT][$productData['container_id']][$productData['source_product_id']] = $productsArray[$key];
            unset($productsArray[$key]);
        }
    }



    /**
     * This function set is_exist key to products which are 
     * found after re-importing from the source marketplace
     *
     * @param array $productData
     * @return array
     */
    private function setIsExistsToProducts(array $productData, &$bulkOpArrayIsExist = [], $shop_id): array
    {
        try {
            $bulkOpArrayIsExist[]['updateMany'] = [
                [
                    '$or' => [
                        [
                            'user_id' => $productData['user_id'] ?? $this->di->getUser()->id,
                            'shop_id' => $shop_id,
                            'container_id' => $productData['container_id'],
                            'source_product_id' => $productData['source_product_id'],
                        ],
                        [
                            'user_id' => $productData['user_id'] ?? $this->di->getUser()->id,
                            'shop_id' => $shop_id,
                            'source_product_id' => $productData['container_id'],
                        ]
                    ]
                ],
                [
                    '$set' => [
                        'is_exist' => true
                    ]
                ]
            ];

            return [
                'success' => true,
                'message' => 'is_exist set to products successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * This function unset is_exist key and delete products which 
     * are deleted from source marketplace after re-import
     *
     * @param array $sqsData
     * @return array
     */
    public function deleteIsExistsKeyAndVariants(array $sqsData): array
    {
        try {
            if (isset($sqsData['method']) && $sqsData['method'] == 'triggerWebhooks') {
                return $this->handleWebhook($sqsData);
            }
            $checkIfFinalizeImport = $this->checkIfFinalizeImport($sqsData);
            if ($checkIfFinalizeImport['success']) {
                if ($checkIfFinalizeImport['code'] == 'UNSET_EXISTS') return $this->unsetIsExistKey([], $sqsData, false);
                else return ['success' => true, 'message' => 'process completed'];
            }
            $marketplace = $sqsData['data']['shop']['marketplace'] ?? $sqsData['marketplace'];
            $sourceModel = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($marketplace)->get('source_model'));
            $isProductDeleteAllowed = method_exists($sourceModel, 'isProductDeleteAllow') ? $sourceModel->isProductDeleteAllow($sqsData) : ['success' => false];
            if (!empty($isProductDeleteAllowed['success']))
                return $this->isProductDeleteAllowed($sqsData);
            $eventsManager = $this->di->getEventsManager();
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $shopId = $sqsData['data']['shop']['_id'] ?? $sqsData['shop_id'];
            $productContainer = $this->productContainer();
            $Ids = $sourceProductIds = $containerIds = $eventData = $conIds = [];

            if (!empty($sqsData['data']['process_code']) && $sqsData['data']['process_code'] == 'batch_product_import') {
                if (!empty($sqsData['data']['processed_container_ids'])) {
                    $filter = [
                        '$match' => [
                            'user_id' => $userId,
                            'shop_id' => (string) $shopId,
                        ]
                    ];
                    $conIds = array_map('strval', array_keys($sqsData['data']['processed_container_ids']));
                } else {
                    return ['success' => false, 'message' => 'No container ids found for batch product import'];
                }
            } else {
                 $filter = [
                    '$match' => [
                        'user_id' => $userId,
                        'shop_id' => (string) $shopId,
                        'is_exist' => [
                            '$exists' => false
                        ]
                    ]
                ];
                $result = $productContainer->executeAggregateQuery(ConnectorSourceModel::PRODUCT_CONTAINER, [
                    $filter,
                    [
                        '$project' => [
                            '_id' => -1,
                            'container_id' => 1
                        ]
                    ],
                    [
                        '$group' => [
                            '_id' => '$container_id',
                        ]
                    ],
                    [
                        '$limit' => 100
                    ],
                ]);
                $conIds = (array_values(array_column($result, '_id')));
                unset($filter['$match']['is_exist']);
            }

            $filter['$match']['container_id'] = [
                '$in' => $conIds
            ];
            $result = $productContainer->executeAggregateQuery(ConnectorSourceModel::PRODUCT_CONTAINER, [
                $filter,
                [
                    '$project' => [
                        'user_id' => 1,
                        'shop_id' => 1,
                        'container_id' => 1,
                        'source_product_id' => 1,
                        'type' => 1,
                        'visibility' => 1,
                        'sku' => 1,
                        'source_sku' => 1,
                        'source_marketplace' => 1,
                        'app_codes' => 1,
                        'is_exist' => 1
                    ]
                ]
            ]);
            $data = $this->getVariantDeleteIds($result, $sqsData);
            $Ids = $data['Ids'];
            $eventData = $data['eventData'];
            if (!empty($Ids)) {
                foreach ($Ids as $container_id => $source_product_ids) {
                    $containerIds[] = (string) $container_id;
                }
                $sourceProductIds = call_user_func_array('array_merge', $Ids);
            }
            if (empty($eventData)) {
                return $this->unsetIsExistKey($filter, $sqsData);
            }
            $bulkArray = [
                [
                    'deleteMany' => [
                        [
                            'user_id' => $userId,
                            'shop_id' => $shopId,
                            'source_product_id' => [
                                '$in' => $sourceProductIds
                            ]
                        ]
                    ]
                ]
            ];
            $productContainer->executeBulkQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $bulkArray);
            if (!empty($data['marketplaceDeleteData']))
                $this->di->getObjectManager()->get('App\Connector\Models\Product\Marketplace')->marketplaceDelete($data['marketplaceDeleteData']);
            if (!empty($eventData))
                $eventsManager->fire('application:afterVariantsDelete', $this, $eventData);
            return [
                'success' => true,
                'message' => 'Requeue',
                'queue_sr_no' => $this->di->getMessageManager()->pushMessage($sqsData)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkIfFinalizeImport($sqsData)
    {
        $queuedTaskColection = $this->productContainer()->getCollection('queued_tasks');
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
        $queuedTask = $queuedTaskColection->findOne([
            '_id' => new \MongoDB\BSON\ObjectId($sqsData['data']['feed_id'])
        ], $options);
        if (!empty($queuedTask['additional_data']['tag']) && $queuedTask['additional_data']['tag'] == 'UNSET_EXISTS') {
            return ['success' => true, 'code' => 'UNSET_EXISTS'];
        }
        if (empty($queuedTask)) {
            return ['success' => true, 'code' => "RETURN"];
        }
        return ['success' => false, 'code' => 'CONTINUE'];
    }

private function getVariantDeleteIds($result, $sqsData)
    {
        $Ids = $eventData = $marketplaceDeleteData = [];

        $containerData = [];
        $allKeys = [];

        foreach ($result as $key => $product) {
            $containerId = $product['container_id'];
            $allKeys[$containerId][] = $key;

            if (!isset($containerData[$containerId])) {
                $containerData[$containerId] = [
                    'parent_key' => null,
                    'parent_type' => null,
                    'total_children' => 0,
                    'children_without_exists' => 0
                ];
            }

            if (isset($product['visibility'])) {
                if ($product['visibility'] == ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH) {
                    $containerData[$containerId]['parent_key'] = $key;
                    if (isset($product['type'])) {
                        $containerData[$containerId]['parent_type'] = $product['type'];
                    }
                } elseif ($product['visibility'] == ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY) {
                    $containerData[$containerId]['total_children']++;
                    if (!isset($product['is_exist'])) {
                        $containerData[$containerId]['children_without_exists']++;
                    }
                }
            }
        }

        $keysToRemove = [];

        foreach ($containerData as $containerId => $data) {
            if ($data['parent_type'] == ConnectorProductModel::PRODUCT_TYPE_VARIATION) {
                if ($data['total_children'] > 0) {
                    if (
                        $data['children_without_exists'] == $data['total_children']
                        || $data['children_without_exists'] == 0
                    ) {
                        $keysToRemove = array_merge($keysToRemove, $allKeys[$containerId]);
                    }
                }
            } elseif ($data['parent_type'] == ConnectorProductModel::PRODUCT_TYPE_SIMPLE) {
                if ($data['parent_key'] !== null) {
                    $keysToRemove[] = $data['parent_key'];
                }
            }
        }

        foreach ($keysToRemove as $key) {
            unset($result[$key]);
        }

        foreach ($result as $parentProduct) {
            if (
                isset($parentProduct['visibility']) && !isset($parentProduct['is_exist'])
                && $parentProduct['visibility'] == ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY
            ) {
                $Ids[$parentProduct['container_id']][] = $parentProduct['source_product_id'];
                $eventData[$parentProduct['container_id']][$parentProduct['source_product_id']] = $parentProduct;
                $marketplaceDeleteData['deleteArray'][] = [
                    'source_product_id' => $parentProduct['source_product_id'],
                    'type' => $parentProduct['visibility'],
                    'source_shop_id' => $parentProduct['shop_id']
                ];
            }
        }

        return [
            'Ids' => $Ids,
            'eventData' => $eventData,
            'marketplaceDeleteData' => $marketplaceDeleteData
        ];
    }

    /**
     * delete products from product_container 
     * If they are not found in re-importing 
     * and marketplace has isProductDeleteAllow method
     * @param array $sqsData
     * @return array
     */
    private function isProductDeleteAllowed(array $sqsData)
    {
        try {
            $eventsManager = $this->di->getEventsManager();
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $shopId = $sqsData['data']['shop']['_id'] ?? $sqsData['shop_id'];
            $result = $source_product_ids = $bulkArray = $eventData = [];
            $filter = [
                '$match' => [
                    'user_id' => $userId,
                    'shop_id' => (string) $shopId,
                    'is_exist' => [
                        '$exists' => false
                    ]
                ]
            ];
            $productContainer = $this->productContainer();

            $result = $productContainer->executeAggregateQuery(
                ConnectorSourceModel::PRODUCT_CONTAINER,
                [
                    $filter,
                    [
                        '$project' => [
                            'user_id' => 1,
                            'shop_id' => 1,
                            'container_id' => 1,
                            'source_product_id' => 1,
                            'type' => 1,
                            'visibility' => 1,
                            'sku' => 1,
                            'source_sku' => 1,
                            'source_marketplace' => 1,
                            'app_codes' => 1
                        ]
                    ],
                    [
                        '$limit' => $sqsData['limit'] ?? 250
                    ],
                ]
            );
            if (empty($result)) {
                return $this->unsetIsExistKey($filter, $sqsData);
            }
            $source_product_ids = (array_values(array_column($result, 'source_product_id')));
            $container_ids = array_values(array_unique(array_column($result, 'container_id')));
            $this->di->getLog()->logContent(json_encode($source_product_ids) . PHP_EOL . json_encode($container_ids), 'info', 'connector/' . date('Y-m-d') . '/' . $userId . '/isProductDeleteAllowed.log');
            $bulkArray = [
                [
                    'deleteMany' => [
                        [
                            'user_id' => $userId,
                            'shop_id' => $shopId,
                            'source_product_id' => [
                                '$in' => $source_product_ids
                            ],
                            'is_exist' => [
                                '$exists' => false
                            ],
                        ]
                    ]
                ]
            ];
            foreach ($result as $parentProduct) {
                $eventData[$parentProduct['container_id']][$parentProduct['source_product_id']] = $parentProduct;
                $data['deleteArray'][] = [
                    'source_product_id' => $parentProduct['source_product_id'],
                    'type' => $parentProduct['visibility'],
                    'source_shop_id' => $parentProduct['shop_id']
                ];
            }
            if (!empty($data))
                $this->di->getObjectManager()->get('App\Connector\Models\Product\Marketplace')->marketplaceDelete($data);
            $eventsManager->fire('application:afterVariantsDelete', $this, $eventData);
            $bulkObj = $productContainer->executeBulkQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $bulkArray);
            if ($bulkObj['success']) {
                $response = [
                    'acknowledged' => $bulkObj['bulkObj']->isAcknowledged(),
                    'inserted' => $bulkObj['bulkObj']->getInsertedCount(),
                    'modified' => $bulkObj['bulkObj']->getModifiedCount(),
                    'matched' => $bulkObj['bulkObj']->getMatchedCount(),
                    'deleted' => $bulkObj['bulkObj']->getDeletedCount(),
                    'source_product_ids' => $source_product_ids
                ];
            } else {
                $response = [
                    'writeError' => $bulkObj['error'],
                ];
            }
            $this->di->getLog()->logContent(json_encode($response), 'info', 'connector/' . date('Y-m-d') . '/' . $userId . '/isProductDeleteAllowed.log');
            return [
                'success' => true,
                'message' => 'Requeue',
                'response' => $response,
                'queue_sr_no' => $this->di->getMessageManager()->pushMessage($sqsData)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * delete products from product_container 
     * which have no child
     * and marketplace has isProductsDeleteAllowWithNoChild method
     * @param array $sqsData
     * @return void
     */
    private function isProductsDeleteAllowWithNoChild(array $sqsData)
    {
        $sourceModel = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($sqsData['data']['shop']['marketplace'])->get('source_model'));
        $isProductsDeleteAllowWithNoChild = method_exists($sourceModel, 'allowParentsDeleteWithNoChild') ? $sourceModel->allowParentsDeleteWithNoChild() : ['success' => false];
        if (!empty($isProductsDeleteAllowWithNoChild['success'])) {
            $productContainer = $this->productContainer();
            $productContainer->deleteParentWithNoChild($sqsData);
        }
    }

    /**
     * Delete variants in case of webhook
     *
     * @param array $sqsData
     * @return array
     */
    private function handleWebhook(array $sqsData)
    {
        $sourceProductIds = $eventData = [];

        $eventsManager = $this->di->getEventsManager();
        $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
        $shopId = (string) ($sqsData['data']['shop']['_id'] ?? $sqsData['shop_id']);
        $productContainer = $this->productContainer();
        $conIds = array_values(array_unique(array_map('strval', array_column($sqsData['data'], 'container_id'))));
        $sourceProductIds = array_values(array_unique(array_map('strval', array_column($sqsData['data'], 'source_product_id'))));

        $filter = [
            '$match' => [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'container_id' => [
                    '$in' => $conIds
                ],
                'source_product_id' => [
                    '$nin' => $sourceProductIds
                ],
                'visibility' => ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY,
            ]
        ];
        $result = $productContainer->executeAggregateQuery(
            ConnectorSourceModel::PRODUCT_CONTAINER,
            [
                $filter
            ]
        );
        foreach ($result as $parentProduct) {
            $eventData[$parentProduct['container_id']][$parentProduct['source_product_id']] = $parentProduct;
            $data['deleteArray'][] = [
                'source_product_id' => $parentProduct['source_product_id'],
                'type' => $parentProduct['visibility'],
                'source_shop_id' => $parentProduct['shop_id']
            ];
        }

        if (!empty($eventData)) {
            $sourceProductIds = array_merge($sourceProductIds, $conIds);
            $bulkArray = [
                [
                    'deleteMany' => [
                        [
                            'user_id' => $userId,
                            'shop_id' => $shopId,
                            'container_id' => [
                                '$in' => $conIds
                            ],
                            'source_product_id' => [
                                '$nin' => $sourceProductIds
                            ],
                            'visibility' => ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY,
                        ]
                    ]
                ]
            ];
            $productContainer->executeBulkQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $bulkArray);
            if (!empty($data)) {
                $this->di->getObjectManager()->get('App\Connector\Models\Product\Marketplace')->marketplaceDelete($data);
            }
            $eventsManager->fire('application:afterVariantsDelete', $this, $eventData);
        }
        return [
            'success' => true,
            'message' => 'Data updated successfully'
        ];
    }

    /**
     * This function unset is_exist key from product_container collection.
     *
     * @param array $filter
     * @param array $sqsData
     * @return array
     */
    private function unsetIsExistKey($filter = [], $sqsData = [], $updateQueued = true): array
    {
        try {
            if ($updateQueued) {
                $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['data']['feed_id'], 50, "Finalizing Import....", false, ['tag' => 'UNSET_EXISTS']);
            }
            $productContainer = $this->productContainer();
            $sourceProductIds = $products = [];
            if (empty($filter)) {
                $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
                $shopId = (string) ($sqsData['data']['shop']['_id'] ?? $sqsData['shop_id']);
                $filter = [
                    '$match' => [
                        'user_id' => $userId,
                        'shop_id' => (string) $shopId,
                    ]
                ];
            }
            unset($filter['$match']['visibility']);
            if (isset($sqsData['data']['process_code']) && $sqsData['data']['process_code'] != 'batch_product_import') {
                unset($filter['$match']['container_id']);
            }
            $filter['$match']['is_exist'] = true;
            $products = $productContainer->executeAggregateQuery(
                ConnectorSourceModel::PRODUCT_CONTAINER,
                [
                    [
                        '$match' => $filter['$match']
                    ],
                    [
                        '$project' => [
                            'source_product_id' => 1
                        ]
                    ],
                    [
                        '$limit' => 5000
                    ]
                ]
            );
            while (!empty($products)) {
                $sourceProductIds = array_values(array_unique(array_map('strval', array_column($products, 'source_product_id')))) ?? [];
                $bulkArray[0]['updateMany'] = [
                    [
                        'user_id' => $filter['$match']['user_id'] ?? $this->di->getUser()->id,
                        'shop_id' => $filter['$match']['shop_id'] ?? $sqsData['data']['shop']['_id'],
                        'source_product_id' => [
                            '$in' => $sourceProductIds
                        ]
                    ],
                    [
                        '$unset' =>
                        ['is_exist' => '']

                    ]
                ];
                $productContainer->executeBulkQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $bulkArray);
                $products = $productContainer->executeAggregateQuery(
                    ConnectorSourceModel::PRODUCT_CONTAINER,
                    [
                        [
                            '$match' => $filter['$match']
                        ],
                        [
                            '$project' => [
                                'source_product_id' => 1
                            ]
                        ],
                        [
                            '$limit' => 5000
                        ]
                    ]
                );
                $sourceProductIds = [];
            }

            if (!empty($sqsData['data']['feed_id']) && !empty($sqsData['data']['operation']) && $sqsData['data']['operation'] == 'variants_delete') {
                $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($sqsData['data']['feed_id'], 100);
                $this->processWebhook($sqsData);
                $this->isProductsDeleteAllowWithNoChild($sqsData);
                $this->finalizeSku($sqsData);
                $notificationData = [
                    'marketplace' => $sqsData['data']['shop']['marketplace'],
                    'user_id' => $sqsData['data']['user_id'],
                    'message' => "Product Import Successfully Completed!",
                    'severity' => 'success',
                    'process_code' => $sqsData['data']['process_code'] ?? 'product_import',
                    'tag' => 'variant_delete'

                ];
                $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($sqsData['data']['shop']['_id'], $notificationData);
            }
            if (isset($sqsData['data']['target_marketplace'], $sqsData['data']['target_shop_id'])) {
                $eventData['source_marketplace'] = $sqsData['data']['shop']['marketplace'];
                $eventData['source_shop_id'] = $sqsData['data']['shop']['_id'];
                $eventData['target_marketplace'] = $sqsData['data']['target_marketplace'];
                $eventData['target_shop_id'] = $sqsData['data']['target_shop_id'];
                $eventData['isImported'] = $sqsData['data']['isImported'];
                $eventData['additional_data'] = $sqsData['data']['extra_data'] ?? [];
                $eventData['user_id'] = $sqsData['user_id'];
                $eventsManager = $this->di->getEventsManager();
                $eventsManager->fire('application:afterProductImport', $this, $eventData);
                $params = [
                    'user_id' => $filter['$match']['user_id'] ?? $this->di->getUser()->id,
                    'source' => [
                        'marketplace' => $sqsData['data']['shop']['marketplace'],
                        'shopId' => $sqsData['data']['shop']['_id'],
                    ]
                ];
                $this->di->getObjectManager()->get(ConnectorMarketplaceModel::class)->syncRefineProducts($params);
            }
            return [
                'success' => true,
                'message' => 'Products updated Successfully'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * prepare variant data for product insertion
     *
     * @param array $additional_data
     * @param array $data
     * @param array $variants
     * @return void
     */
    private function prepareVariant(array $additional_data, array &$data, array &$variants = [])
    {
        if (isset($additional_data['app_code'])) {
            $app_code = $additional_data['app_code'];
            $data['details']['app_codes'] = [$app_code];
            $variants['app_codes'] = [$app_code];
        }
        if (isset($variants['variant_attributes']) && count($variants['variant_attributes']) > 0 || !empty($data['details']['is_parent'])) {
            $data['details']['type'] = ConnectorProductModel::PRODUCT_TYPE_VARIATION;
        }
    }

    /**
     * get child of parent data from temp_product_container
     * @param array $parentData
     * @param string $marketplace
     * @param string $shop_id
     * @return array
     */
    public function getChildProducts(array $parentData, string $marketplace, string $shopId)
    {
        $childPresent = false;
        $productContainer = $this->productContainer();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
        $variants = [$parentData];
        if (!empty($parentData['is_parent'])) {
            $filter = [
                'user_id' => $parentData['user_id'] ?? $this->di->getUser()->id,
                'shop_id' => $shopId,
                'container_id' => $parentData['container_id'],
                'type' => ConnectorProductModel::PRODUCT_TYPE_SIMPLE
            ];
            $variants = $productContainer->findQuery($marketplace . '_product_container', $filter, $options);
            if (!empty($variants)) {
                $childPresent = true;
                $bulkArray[]['deleteMany'] = [
                    $filter
                ];
                $productContainer->executeBulkQuery($marketplace . '_product_container', $bulkArray);
            }
        }
        // set is_child
        return ['variant_attributes' => isset($variants[0]['variant_attributes']) ? $variants[0]['variant_attributes'] : [], 'variants' => $variants, 'childPresent' => $childPresent];
    }
}