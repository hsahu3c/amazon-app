<?php

namespace App\Amazon\Components\Product;

use App\Amazon\Components\Common\Helper;
class saveProductValidation
{
    public function setErrorArr($sku, $source_product_id): array
    {
        return [
            'sku' => $sku,
            'source_product_id' => $source_product_id,
            'message' => 'SKU already exists in the system',
        ];
    }

    public function validateSKUHelperFrontedData(&$dataToSourceIdMap, &$skuToSourcePrd, &$error, $v, $skuToSourcePrdVariation = null)
    {
        $uniqueSKU = true;


        if (isset($skuToSourcePrd[$v['sku']]) || (isset($skuToSourcePrdVariation[$v['sku']]) && $skuToSourcePrdVariation[$v['sku']]['container_id'] != $v['container_id'])) {
            $uniqueSKU = false;
            $keyToUse = $skuToSourcePrd[$v['sku']]['source_product_id'] ?? $skuToSourcePrdVariation[$v['sku']]['source_product_id'];
            $error[$v['source_product_id']] = $this->setErrorArr($v['sku'], $keyToUse);
            $error[$keyToUse] = $this->setErrorArr($v['sku'], $v['source_product_id']);
            // unset($v['sku']);

            unset($dataToSourceIdMap[$v['source_product_id']]['sku']);
            unset($dataToSourceIdMap[$keyToUse]['sku']);
        } else {
            $skuToSourcePrd[$v['sku']] = [
                'source_product_id' => $v['source_product_id'],
                'container_id' => $v['container_id']
            ];
        }

        return $uniqueSKU;
    }

    public function validateSKUHelperDbData(&$dataToSourceIdMap, $skuToSourcePrd, &$error, $v, $skuToSourcePrdVariation = null)
    {
        $uniqueSKU = true;
        if (
            (
                isset($skuToSourcePrd[$v['sku']]) &&
                $v['source_product_id'] != $skuToSourcePrd[$v['sku']]['source_product_id']
            )
            ||
            (
                isset($skuToSourcePrdVariation[$v['sku']]) &&
                    $v['source_product_id'] != $skuToSourcePrdVariation[$v['sku']]['source_product_id'] &&
                    $skuToSourcePrdVariation[$v['sku']]['container_id'] != $v['container_id']
            )
        ) {
            $keyToUse = $skuToSourcePrd[$v['sku']]['source_product_id'] ?? $skuToSourcePrdVariation[$v['sku']]['source_product_id'];

            $uniqueSKU = false;
            $error[$keyToUse] = $this->setErrorArr($v['sku'], $v['source_product_id']);
            unset($dataToSourceIdMap[$keyToUse]['sku']);
        }

        return $uniqueSKU;
    }

    public function validateSaveProduct($data, $user_id = false, $addtionalData = false): array
    {
        $userId = $user_id ?: $this->di->getUser()->id;
        $skuToSourcePrd = [];
        $uniqueSKU = true;
        $error = [];
        $arr = [];

        $skuToSourcePrdVariation = [];

        $dataToSourceIdMap = [];
        foreach ($data as $v) {
            if (is_array($v)) {
                $temp = $v['source_product_id'];
                $dataToSourceIdMap[$temp] = $v;

            if (isset($v['sku'])) {

                if ($v['source_product_id'] != $v['container_id']) {

                    $uniqueSKU = $this->validateSKUHelperFrontedData($dataToSourceIdMap, $skuToSourcePrd, $error, $v, $skuToSourcePrdVariation);
                } else {
                    $uniqueSKU = $this->validateSKUHelperFrontedData($dataToSourceIdMap, $skuToSourcePrdVariation, $error, $v,  $skuToSourcePrd);
                }

                $arr[] = (string)$v['sku'];
            }
        }
    }
        if ($uniqueSKU == false) {
            return ['success' => false, 'message' => 'sku not unique', 'data' => ['error_info' => $error], 'code' => 'proceed'];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        $products =  $productCollection->find(['user_id' => $userId, 'sku' => ['$in' => $arr], 'shop_id' => $addtionalData['target']['shopId']])->toArray();

        foreach ($products as $v) {
            if ($v['source_product_id'] == $v['container_id']) {
                $uniqueSKU = $this->validateSKUHelperDbData($dataToSourceIdMap, $skuToSourcePrdVariation, $error, $v, $skuToSourcePrd);
            } else {
                $uniqueSKU = $this->validateSKUHelperDbData($dataToSourceIdMap, $skuToSourcePrd, $error, $v, $skuToSourcePrdVariation);
            }
        }

        //code proceed , terminate;
        if ($uniqueSKU == false) {
            return ['success' => false, 'message' => 'sku not unique', 'data' => ['error_info' => $error], 'code' => 'terminate', 'updated_data' => $dataToSourceIdMap];
        }
        $jsonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Listings\Helper::class);
        if (!$jsonHelper->isBetaSeller($userId)) ['success' => true];

        if ($addtionalData && isset($addtionalData['dont_validate']) && $addtionalData['dont_validate']) {
            return ['success' => true];
        }

        $productObject = $this->di->getObjectManager()->get(Product::class)->validateFeed($data, $user_id, $addtionalData);
        if(isset($productObject['success'] ,$productObject['response'] ) && !$productObject['success']){

            return ['success' => false, 'message' => 'Validation Failed', 'data' => $productObject['response'],'other_attributes_error' => $productObject['other_attributes_error'], 'code' => 'terminate'];
        }

        return ['success' => true];
    }
}