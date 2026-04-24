<?php

namespace App\Connector\Components;

use App\Connector\Models\ProductContainer;
use App\Connector\Models\Product as ConnectorProductModel;

class ProductHelper extends \App\Core\Components\Base
{

    const FIELDS_TO_BE_UPDATED_IN_PARENT = [
        'brand',
        'description',
        'handle',
        'tags',
        'title',
        'additional_images',
        'main_image',
        'status',
        'source_status',
        'product_type',
        'sku',
        'source_sku',
        'variant_attributes',
        'variant_attributes_values',
        'product_category',
        'parentMetafield',
        'requires_shipping',
        'source_updated_at',
        'updated_at'
    ];

    public function uploadProducts($data)
    {
        $success = true;
        $message = 'Product Upload process initiated.';
        if (isset($data['product_ids']) && !empty($data['product_ids'])) {
        } else {
            return ['success' => false, 'message' => 'something went wrong'];
        }
    }

    public function finalizeProduct($actualData)
    {
        $actualData['product_data']['sku'] = $actualData['product_data']['source_sku'] = !empty($actualData['product_data']['sku']) ? $actualData['product_data']['sku'] : $actualData['product_data']['source_product_id'];
        if (isset($actualData['product_data']['status'])) {
            $actualData['product_data']['source_status'] = $actualData['product_data']['status'];
            unset($actualData['product_data']['status']);
        }
        $response = $this->setParent($actualData['product_data'], $actualData['bulkOpArray']);
        if (isset($actualData['product_data']['visibility']) && $actualData['product_data']['visibility'] == ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY) {
            if (isset($actualData['bulkOpArray']) && $response['success'] && !empty($actualData['bulkOpArray']['isImported'])) {
                $this->updateParentProduct($actualData['product_data'], $actualData['bulkOpArray']);
            }
            if (isset($actualData['product_data']['variant_attributes_values'])) {
                unset($actualData['product_data']['variant_attributes_values']);
            }
            unset($actualData['product_data']['parentMetafield']);
        }
        $this->formatVariantAttribute($actualData['product_data']);
        if (
            isset($actualData['product_data']['visibility'], $actualData['product_data']['type'])
            && $actualData['product_data']['type'] == ConnectorProductModel::PRODUCT_TYPE_VARIATION &&
            $actualData['product_data']['visibility'] == ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH
        ) {
            $actualData['product_data']['sku'] = $actualData['product_data']['source_sku'] = !empty($actualData['product_data']['parent_sku']) ? $actualData['product_data']['parent_sku'] : $actualData['product_data']['source_product_id'];
            unset($actualData['product_data']['price'], $actualData['product_data']['quantity'], $actualData['product_data']['marketplace']);
        }
        $this->checkSkuAndUpdate($actualData['product_data'], $actualData['bulkOpArray']['skus'], $actualData['bulkOpArray']['getAllSku'], $actualData['bulkOpArray']);
        $actualData['product_data']['low_sku'] = strtolower($actualData['product_data']['sku']);
    }

    /**
     * check sku in db and bulkArray and update SKU
     * @param array $productData
     * @param array $skus
     * @param array $getAllSku
     * @param array $bulkOpArray
     * @return void
     */
    public function checkSkuAndUpdate(array &$productData, array &$skus, array $getAllSku, array $bulkOpArray)
    {
        $index = $this->existingSku($getAllSku, $productData);
        $checkSkuUpdated = $this->checkSku($productData, $skus, $index, $bulkOpArray);
        if ($checkSkuUpdated) {
            $this->updateSku($productData);
        } else {
            if ($index['success'] && $productData['source_sku'] === $index['index']['source_sku']) {
                $productData['sku'] = $index['index']['sku'];
            }
        }
    }

    private function existingSku($getAllSku, $productData)
    {
        $containerId = $productData['container_id'];
        $sourceProductId = $productData['source_product_id'];
        if (isset($getAllSku[$containerId], $getAllSku[$containerId][$sourceProductId])) {
            $existingSkuInDb = $getAllSku[$containerId][$sourceProductId];
            if (count($existingSkuInDb) > 1) {
                foreach ($existingSkuInDb as $arr) {
                    if ($arr['visibility'] === $productData['visibility']) {
                        $res = $arr;
                    }
                }
            } else {
                $res = $existingSkuInDb[0];
            }
            return ['success' => true, 'index' => $res];
        }

        return ['success' => false];
    }

    /**
     * checking Sku in bulkArray and db.
     * @param array $productData
     * @param array $skus
     * @param array $flag
     * @param array $bulkOpArray
     * @return bool
     */
    private function checkSku(array &$productData, array &$skus, array $flag, array $bulkOpArray)
    {
        $update_sku = $this->checkSkuInBulkArray($productData, $skus);
        $skus[$productData['sku']] = $productData['sku'];
        $checkInDb = isset($bulkOpArray['source_sku_check'][$productData['sku']]);
        if ($update_sku && !$checkInDb) {
            return true;
        }
        if ($update_sku) {
            if (!$flag['success'] && isset($bulkOpArray['sku_check'][$productData['sku']])) {
                return true;
            }
            return false;
        } elseif (!$flag['success'] && isset($bulkOpArray['sku_check'][$productData['sku']]) && $bulkOpArray['sku_check'][$productData['sku']] > 0) {
            return true;
        } elseif ($flag['success'] && $flag['index']['source_sku'] != $productData['source_sku'] && isset($bulkOpArray['sku_check'][$productData['sku']]) && $bulkOpArray['sku_check'][$productData['sku']] > 0) {
            return true;
        }
        return false;
    }

    /**
     * check sku in bulk array
     * @param array $productData
     * @param array $skus
     * @return bool
     */
    private function checkSkuInBulkArray(array $productData, array $skus)
    {
        if (!empty($skus) && isset($skus[$productData['sku']])) {
            return true;
        }
        return false;
    }

    /**
     * update sku in products
     * @param array $productData
     * @return void
     */
    private function updateSKU(array &$productData)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $counter = (string) $mongo->getCounter($productData['source_sku'], $this->di->getUser()->id);
        $productData['sku'] = $productData['source_sku'] . '_' . $counter;
        $productData['duplicate_sku'] = true;
    }

    /**
     * formating Variant Attributes
     * @param array $productData
     * @return void
     */
    private function formatVariantAttribute(array &$productData)
    {
        if (isset($productData['variant_attributes']) && !empty($productData['variant_attributes'])) {
            $updatedVariantAttri = [];
            foreach ($productData['variant_attributes'] as $key => $variantAttribute) {
                if (isset($productData['visibility']) && $productData['visibility'] == ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY) {

                    $attribute = [
                        'key' => $variantAttribute,
                        'value' => isset($productData[$variantAttribute]) ? $productData[$variantAttribute] : ''
                    ];
                } else {
                    $attribute = $variantAttribute;
                }
                array_push($updatedVariantAttri, $attribute);
                if (isset($productData[$variantAttribute]))
                    unset($productData[$variantAttribute]);
            }
            $productData['variant_attributes'] = $updatedVariantAttri;
        }
    }

    /**
     * set parent container_id in bulkArray
     * @param array $productData
     * @param array $bulkOpArray
     * @return array
     */
    private function setParent(array &$productData, array &$bulkOpArray)
    {
        if (isset($bulkOpArray['parentInBulkArr'][$productData['container_id']]) && empty($productData['is_parent']))
            return ['success' => false, 'message' => 'Parent is already set in bulkArray'];
        $bulkOpArray['parentInBulkArr'][$productData['container_id']] = isset($bulkOpArray['parentInBulkArr'][$productData['container_id']]) ? $bulkOpArray['parentInBulkArr'][$productData['container_id']] + 1 : 1;
        if ($bulkOpArray['parentInBulkArr'][$productData['container_id']] > 1 && !empty($productData['is_parent'])) {
            unset($productData['_id']);
        }
        return [
            'success' => true,
            'message' => 'Parent set in bulkArray'
        ];
    }

    /**
     * update parent in product_container
     * @param array $productData
     * @param array $bulkOpArray
     * @return array
     */
    public function updateParentProduct(array $productData, array &$bulkOpArray)
    {
        $productContainer = $this->di->getObjectManager()->get(ProductContainer::class);
        $parent_filter = [
            'user_id' => $productData['user_id'] ?? $this->di->getUser()->id,
            'shop_id' => $productData['shop_id'],
            'container_id' => $productData['container_id'],
            'visibility' => ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH,
            /* 'marketplace' => [
                '$exists' => true,
            ],
            'no_update_required' => [
                '$exists' => false
            ] */
        ];
        $productData['sku'] = $productData['source_sku'] = (!empty($productData['parent_sku'])) ? $productData['parent_sku'] : $productData['container_id'];

        if (isset($productData['visibility']) && $productData['visibility'] == ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY) {
            $updatedFieldValue = [];
            foreach (self::FIELDS_TO_BE_UPDATED_IN_PARENT as $field) {
                if (isset($productData[$field])) {
                    $updatedFieldValue[$field] = $productData[$field];
                }
            }
            $productData['type'] = ConnectorProductModel::PRODUCT_TYPE_VARIATION;
            $productData['visibility'] = ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH;
            $marketplace = $productContainer->prepareMarketplace($productData);
            unset($marketplace['childInfo']['price'], $marketplace['childInfo']['quantity']);
            $marketplaceAll = $marketplace;
            foreach ($marketplace['childInfo'] as $key => $val) {
                if (isset($productData[$key])) {
                    $marketplaceAll['childInfo'][$key] = $productData[$key];
                    // $updatedFieldValue['marketplace.$[el].' . $key] = $productData[$key];
                }
            }
            $marketplaceAll['childInfo']['sku'] = /* $updatedFieldValue['marketplace.$[el].sku'] = */ $productData['parent_sku'] ?? $productData['container_id'];
            $marketplaceAll['source_product_id'] = $marketplaceAll['childInfo']['source_product_id'] =/*  $updatedFieldValue['marketplace.$[el].source_product_id'] = */ $productData['container_id'];
            $update_array = [
                $parent_filter,
                ['$set' => $updatedFieldValue],
                /* [
                    'arrayFilters' => [
                        [
                            'el.price' => ['$exists' => false],
                            'el.direct' => true
                        ]
                    ]
                ] */
            ];
            $bulkOpArray['marketplaceAll'][] = $marketplaceAll;
            $bulkOpArray[]['updateOne'] = $update_array;
            return ['success' => true, 'message' => 'Parent updated'];
        }
        return ['success' => false, 'message' => 'Parent product found'];
    }

    public function manageSkuUpdate($data)
    {
        $marketplace = $data['marketplace'];
        $userId = $data['user_id'] ??  $this->di->getUser()->id;
        $shopId = $data['shop_id'] ?? $data['shopId'];
        $processCode =  'update_sku_finalize';
        $queueName = $marketplace . '_update_sku_finalize';
        $queueData = [
            'user_id' => $userId,
            'message' => "Just a moment! We're updating your Skus.",
            'process_code' => $processCode,
            'marketplace' => $marketplace
        ];
        $queuedTask = new \App\Connector\Models\QueuedTasks;
        $queuedTaskId = $queuedTask->setQueuedTask($shopId, $queueData);

        if (!$queuedTaskId) {
            return ['success' => false, 'message' => $this->di->getLocale()->_('sku_update_in_progress')];
        } elseif (isset($queuedTaskId['success']) && !$queuedTaskId['success']) {
            return $queuedTaskId;
        }
        $appCode = $this->di->getAppCode()->get();
        $appTag = $this->di->getAppCode()->getAppTag();
        $appCode = $appCode[$marketplace] ?? 'default';
        $handlerData = [
            'type' => 'full_class',
            'appCode' => $appCode,
            'appTag' => $appTag,
            'class_name' => '\App\Connector\Components\Route\ProductRequestcontrol',
            'method' => 'finalizeSku',
            'queue_name' => $queueName,
            'user_id' => $userId,
            'shop_id' => $shopId,
            'marketplace' => $marketplace,
            'limit' => 500,
            'data' => [
                'operation' => 'update_sku_finalize',
                'user_id' => $userId,
                'app_code' => $appCode,
                'feed_id' => $queuedTaskId,
                'shop_id' => $shopId
            ],
        ];
        return [
            'success' => true,
            'message' => ucfirst($marketplace) . ' Sku Update Initiated',
            'queue_sr_no' => $this->di->getMessageManager()->pushMessage($handlerData),
        ];
    }
}