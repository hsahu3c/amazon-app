<?php
namespace App\Amazon\Components\Profile;

use App\Core\Components\Base;
class Helper extends Base
{
    public function getProfileIdsByProductIds($data) {
        $res = $this->di->getObjectManager()->get(Profile::class)
            ->init()->getProfileIdsByProductIds($data);
        return $res;
    }

    public function getAccountsByProfile($data) {
        $res = $this->di->getObjectManager()->get(Profile::class)
            ->init()->getAccountsByProfile($data);
        return $res;
    }

    public function getAssociatedProductIds($profileId, $ids) {
        $res = $this->di->getObjectManager()->get(Profile::class)
            ->init()->getAssociatedProductIds($profileId, $ids);
        return $res;
    }

    public function saveProfile(array $params): array
    {
        $validationResponse = $this->validateAttributes($params);
        if (!$validationResponse['success']) {
            return $validationResponse;
        }

        $profileModel = $this->di->getObjectManager()->get(\App\Connector\Components\Profile\ProfileHelper::class);
        return $profileModel->saveProfile($params);
    }

    public function validateAttributes(array $params): array
    {
        $data = $params['data'] ?? [];
        $attributesMapping = $data['attributes_mapping'] ?? [];
        $categorySettings = $data['category_id'] ?? [];

        if (empty($attributesMapping['jsonFeed'])) {
            return ['success' => false, 'message' => 'Missing jsonFeed in attributes_mapping'];
        }
        if (empty($attributesMapping['language_tag'])) {
            return ['success' => false, 'message' => 'Missing language_tag in attributes_mapping'];
        }

        $objectManager  = $this->di->getObjectManager();
        $targetShopId   = $params['shop_id'] ?? $this->di->getRequester()->getTargetId();
        $sourceShopId   = $params['source_shop_id'] ?? $this->di->getRequester()->getSourceId();

        $feedValidationData  = [
            'category_settings'  => array_merge($categorySettings, ['attributes_mapping' => $attributesMapping]),
            'fulfillment_type'   => $data['fulfillment_type'] ?? null,
            'target_marketplace' => 'amazon',
            'shop_id'            => $targetShopId,
            'source_shop_id'     => $sourceShopId,
            'dont_validate_barcode' => $categorySettings['barcode_exemption'] ?? "0",
            'amazonProductType'  => $categorySettings['product_type'] ?? "",
            'parent_listing_type' => (isset($categorySettings['product_type']) && $categorySettings['product_type'] === 'PRODUCT') ? 'offer' : 'new_listing'
        ];

        $mongo = $objectManager->get('\App\Core\Models\BaseMongo');
        $refineProduct = $mongo->getCollectionForTable('refine_product');
        $productData   = null;

        $filterType = $data['filter_type'] ?? '';
        $data['user_id'] ??= $this->di->getUser()->id;
        if ($filterType === 'manual') {
            $containerId = $data['manual_product_ids'][0] ?? null;
            if ($containerId) {
                $finalQuery = [
                    'user_id'    => $data['user_id'] ?? $this->di->getUser()->id,
                    'target_shop_id'    => $targetShopId,
                    'container_id' => $containerId
                ];
                $productData = $refineProduct->findOne($finalQuery);
            }
        } else {
            $rawQuery = $data['query'] ?? null;
            $queryConverter = $objectManager->get('\App\Connector\Components\Profile\GetQueryConverted');
            $mainQuery = $queryConverter->convertQueryFromMysqlToMongo($rawQuery, true);

            $finalQuery = [
                '$and' => [
                    ['user_id' => $data['user_id'] ?? null],
                    ['target_shop_id' => $targetShopId]
                ]
            ];
            if ($mainQuery) {
                $finalQuery['$and'][] = $mainQuery;
                $productData = $refineProduct->findOne($finalQuery);
            }
        }

        if (empty($productData)) {
            return ['success' => false, 'message' => 'Product required for validation'];
        }

        $feedValidationData['container_id'] = $productData['container_id'] ?? null;

        $items = $productData['items'] ?? [];
        if (($productData['type'] ?? '') === 'simple') {
            $itemData = $items[0] ?? [];
        } else {
            $itemData = $items[1] ?? ($items[0] ?? []);
        }

        if (empty($itemData['source_product_id'])) {
            return ['success' => false, 'message' => 'Missing source product id'];
        }

        $feedValidationData['source_product_id'] = $itemData['source_product_id'];

        $productValidator = $objectManager->get(\App\Amazon\Components\Product\Product::class);
        $validationResponse = $productValidator->validateFeedForProfile($feedValidationData, $data['user_id'] ?? null);

        if (empty($validationResponse['success'])) {
            $validationResponse['message'] = $validationResponse['return_msg'] ?? 'Validation Failed';
            $validationResponse['data'] = $validationResponse['response'] ?? [];
            unset($validationResponse['response']);
        }

        return $validationResponse;
    }
}
