<?php

namespace App\Connector\Models\Mongostream;

use App\Core\Models\BaseMongo;
class ProductDeleteHelper extends BaseMongo
{
    private array $profileArray = [];

    public function processProductDeleteEvent($data): void
    {
        $this->formatProductData($data);
    }

    private function formatProductData(array $data)
    {
        $productActivity = $this->getCollectionForTable("product_activity");
        $userDetails = $this->getCollectionForTable("user_details");
        $process_id = $data['process_id'];

        $aggregate = [
            ['$match' => ['$or' => [['process_id' => $process_id], ['delete' => false]]]],
            ['$graphLookup' =>
            [
                'from' => 'product_container',
                'startWith' => '$container_id',
                'connectFromField' => 'container_id',
                'connectToField' => 'container_id',
                'as' => 'products',
                'restrictSearchWithMatch' => [
                    'user_id' => ['$in' => $data['user_id']]
                ]
            ]]
        ];
        $productsDeleted = $productActivity->aggregate($aggregate)->toArray();
        $users = $userDetails->find(['user_id' => ['$in' => $data['user_id']]])->toArray();
        $marketplaces = $this->getMarketPlaces($users);
        if ($this->getFormattedProductsData($productsDeleted, $marketplaces)) {
            return true;
        }
    }

    /**
     * @return never[][]
     */
    private function getMarketPlaces($data): array
    {
        $marketplaces = [];
        foreach ($data as $value) {
            if (isset($value['shops'])) {
                $marketplaces[$value['user_id']] = [];
                foreach ($value['shops'] as $v) {
                    if (isset($v['sources']) && isset($v['marketplace'])) {
                        array_push($marketplaces[$value['user_id']], ['marketplace' => $v['marketplace'], 'id' => $v['_id']]);
                    }
                }
            }
        }

        return $marketplaces;
    }

    private function getFormattedProductsData($productsDeleted, array $marketplaces): bool
    {
        $productActivityCollection = $this->getCollectionForTable("product_activity");
        foreach ($productsDeleted as $value) {
            $mainProduct = $value['before_change'];
            $arrayOfData = $value['products'];
            $parent = [];
            if (!isset($mainProduct['target_marketplace'])) {
                if ($mainProduct['visibility'] !== 'Catalog and Search') {
                    $parent = $this->getAndFormatParentsData($arrayOfData, $mainProduct['container_id'], $mainProduct['user_id'], $mainProduct['shop_id']);
                    $mainProduct['parent'] = $parent['parent'];
                    $mainProduct['profile'] = $parent['profile'];
                    if (count($mainProduct['parent']) == 0) {
                        $productActivityCollection->updateOne(['_id' => $value['_id']], ['$set' => ['delete' => false]]);
                        continue;
                    }
                    $productActivityCollection->updateOne(['_id' => $value['_id']], ['$set' => ['delete' => true]]);
                    $productActivityCollection->updateOne(['source_product_id' => $mainProduct['parent']['source_product_id']], ['$pull' => ['variants' => [
                        '$eq' => $mainProduct['source_product_id']
                    ]]]);
                } else {
                    $mainProduct['profile'] = isset($mainProduct['profile']) && count($mainProduct['profile']) > 0 ? $this->getProfile($mainProduct['profile'][0]['profile_id'],  $mainProduct['profile'][0]['target_shop_id'], $mainProduct['shop_id']) : $value['profile'];
                }

                $mainProduct['edited'] = $this->getAndFormatEditedData($arrayOfData, $mainProduct['container_id'], $mainProduct['user_id'], $mainProduct['source_product_id']);
                foreach ($marketplaces[$mainProduct['user_id']] as $v) {
                    $data = [
                        'source' => [
                            'shop_id' => $mainProduct['shop_id'] ?? "NA",
                            'marketplace' => $mainProduct['source_marketplace'] ?? "NA"
                        ],
                        'target' => ['shop_id' => $v['id'], 'marketplace' => $v['marketplace']]
                    ];
                    $mainProduct['target_shop_id'] = $v['id'];
                    $model = $this->di->getConfig()->connectors->get($v['marketplace'])->get('source_model');
                    if ((method_exists($this->di->getObjectManager()->get($model), "handleProductDeleteEvent"))) {
                        $this->di->getObjectManager()->get($model)->handleProductDeleteEvent($mainProduct);
                    }
                }
            }
        }

        return true;
    }

    private function getProfile($profileId, $target, $shopId)
    {
        $profile_id = $profileId;
        if (in_array($profileId, array_keys($this->profileArray))) {
            return $this->profileArray[$profile_id];
        }

        $profile =  $this->di->getObjectManager()->get("\App\Connector\Models\Product\Edit")->getProfileInfo(new \MongoDB\BSON\ObjectId($profileId), [
            'targetShopID' => $target,
            'target_marketplace' => "amazon",
            'sourceShopID' => $shopId
        ]);
        $profile['target_shop_id'] = $target;
        $profile['profile_id'] = $profile_id;
        $this->profileArray[$profile_id] = $profile;
        return $profile;
    }

    private function getAndFormatParentsData($arrayOfData, $containerId, $userId, $shopId): array
    {
        $parent = [];
        $profile = [];
        foreach ($arrayOfData as $value) {
            if (isset($value['visibility']) && $value['visibility'] === "Catalog and Search" && $containerId === $value['container_id'] && $userId === $value['user_id'] && $shopId === $value['shop_id']) {
                $parent = $value;
                if (isset($value['profile'])) {
                    $profile =  count($value['profile']) > 0 ? $this->getProfile($value['profile'][0]['profile_id'], $value['profile'][0]['target_shop_id'], $value['shop_id']) : $value['profile'];
                }

                $parent['edited'] = $this->getAndFormatEditedData($arrayOfData, $value['container_id'], $value['user_id'], $value['source_product_id']);
                break;
            }
        }

        if (count($parent) == 0) {
            $productActivity = $this->getCollectionForTable('product_activity');

            $parentData = $productActivity->find(
                ['container_id' => $containerId, 'user_id' => $userId, 'before_change.shop_id' => $shopId, 'before_change.visibility' => 'Catalog and Search']
            )->toArray();
            if (count($parentData) > 0) {
                $parent = $parentData[0]['before_change'];
                if (isset($parent['profile'])) {
                    $profile =  count($parent['profile']) > 0 ? $this->getProfile($parent['profile'][0]['profile_id'], $parent['profile'][0]['target_shop_id'], $parent['shop_id']) : $parent['profile'];
                }

                $parent['edited'] = $this->getAndFormatEditedData($arrayOfData, $parent['container_id'], $parent['user_id'], $parent['source_product_id']);
            }
        }

        return ['parent' => $parent, 'profile' => $profile];
    }

    private function getAndFormatEditedData($arrayOfData, $containerId, $userId, $sourceProductId)
    {
        $edited = [];
        foreach ($arrayOfData as $value) {
            if (isset($value['target_marketplace']) && $value['target_marketplace'] === 'amazon') {
                if ($sourceProductId === $value['source_product_id'] && $containerId === $value['container_id'] && $userId === $value['user_id']) {
                    $edited = $value;
                    break;
                }
            }
        }

        if (count($edited) == 0) {
            $productActivity = $this->getCollectionForTable('product_activity');
            $editedData = $productActivity->find([
                'container_id' => $containerId,
                'user_id' => $userId,
                'before_change.target_marketplace' => "amazon",
                'before_change.source_product_id' => $sourceProductId,
            ])->toArray();
            if (count($editedData) > 0) {
                $edited = $editedData[0]['before_change'];
            }
        }

        return $edited;
    }
}