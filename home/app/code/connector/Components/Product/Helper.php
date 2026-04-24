<?php

namespace App\Connector\Components\Product;

use App\Connector\Models\Product as ConnectorProductModel;
use App\Connector\Models\ProductContainer;

class Helper extends \App\Core\Components\Base
{
    const PRODUCT_CONTAINER = 'product_container';

    /**
     * syncing of source products when container_ids is provided
     *
     * @param array $data
     */
    public function startSourceProductSync($postData): array
    {

        if (!empty($postData['source']['marketplace']) && !empty($postData['source']['shopId'])) {
            if (isset($postData['data']['container_ids']) && $productIds = $postData['data']['container_ids']) {
                $sourceData = $postData['source'];
                $shopId = $sourceData['shopId'];
                $marketplace = $sourceData['marketplace'];
                $userId = isset($postData['data']['userId']) ? $postData['data']['userId'] : $this->di->getUser()->user_id;

                /**check if user's shop existed*/
                $shop = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")
                    ->getShop($shopId, $userId);

                if (empty($shop)) {
                    return [
                        'success' => false,
                        'message' => 'Shop not found'
                    ];
                }

                $additionalData = [];
                $returnRes = [];
                $products = [];
                $data = [];
                $eventData = [];
                $bulkOpArray = [];
                $deleted_products = [];

                /**get products from database */
                $productContainerModel = $this->di->getObjectManager()->get(ProductContainer::class);
                $productContainerCollection = $productContainerModel->getCollection();

                $additionalData['shop_id'] = $shopId;
                $additionalData['marketplace'] = $marketplace;
                $additionalData['project']['marketplace'] = 1;
                $getProducts = $productContainerModel->getProductsById($productIds, $additionalData);

                if (empty($getProducts['success']))
                    return $getProducts;

                $productFromProductContainer = $getProducts['productFromProductContainer'];
                if (isset($postData['data']['sync_via']) && $syncVia = $postData['data']['sync_via'] == 'source_product_ids') {
                    $productIds = array_column($productFromProductContainer, 'source_product_id');
                }

                /** fetch product from marketplace*/
                $SourceModel = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($marketplace)->get('source_model'));
                if (!method_exists($SourceModel, $method = 'getMarketplaceProductsForSync')) {
                    return [
                        'success' => false,
                        'message' => $method . ' Method not found Marketplace'
                    ];
                }

                $postData += [
                    'productIds' => $productIds,
                    'shop' => $shop
                ];
                $marketplaceProductsRes = $SourceModel->getMarketplaceProductsForSync($postData);

                if (empty($marketplaceProductsRes['success'])) {
                    return $marketplaceProductsRes;
                }

                $source_shop_id = $shop['_id'];
                try {

                    /* delete products not found in marketplace */
                    if (!empty($marketplaceProductsRes['success']) && !empty($marketplaceProductsRes['products_not_found'])) {
                        $productIds = $marketplaceProductsRes['products_not_found'];
                        $deletedSourceProductIds = [];
                        $deleteQuery = [];
                        $deleteContainerIds = [];
                        $data = [];
                        $data['products_from_product_container'] = $productFromProductContainer;
                        $data['delete_source_product_ids'] = $productIds;
                        $additionalData['shop'] = $shop;
                        $returnRes = $productContainerModel->deleteProductById($data, $additionalData);
                    }
                } catch (\Exception $e) {
                    return ['success' => false, 'message' => $e->getMessage()];
                }


                /**sync product found in marketplace */
                if (!empty($marketplaceProductsRes['success']) && !empty($marketplaceProductsRes['products_found'])) {

                    $products['existing_products'] = $productFromProductContainer;
                    $products['products'] = $marketplaceProductsRes['products_found'];
                    $product_ids = array_column($products['products'], 'source_product_id');
                    $appCode = $this->di->getAppCode()->get();
                    //  prepare additional data 
                    $additionalData['app_code'] = $appCode[$marketplace];
                    $additionalData['shop_id'] = $shopId;
                    $additionalData['marketplace'] = $marketplace;
                    $additionalData['isImported'] = true;
                    $productResControl = $this->di->getObjectManager()->get('\App\Connector\Components\Route\ProductRequestcontrol');
                    $syncResponse = $productResControl->pushToProductContainer($marketplaceProductsRes['products_found'], $additionalData);

                    if (!empty($syncResponse['success'])) {
                        $unsetIsExistCond = [
                            'user_id' => $userId,
                            'shop_id' => $source_shop_id,
                            'source_marketplace' => $marketplace,
                            'is_exist' => true
                        ];
                        if ($syncVia == 'source_product_ids') {
                            $unsetIsExistCond['source_product_id']['$in'] = $product_ids;
                        } else {
                            $unsetIsExistCond['container_id']['$in'] = $product_ids;
                        }

                        $res = $productContainerCollection->updateMany($unsetIsExistCond, ['$unset' => ['is_exist' => ""]]);
                        $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
                        $path = __FILE__;
                        // $this->di->getLog()->logContent($time . PHP_EOL . $path . PHP_EOL  . print_r($res, true), 'info', 'connector/' . date('Y-m-d') . '/' . $userId . '/singleSync.log');
                        if ($syncResponse['stats']['modified'] > 0) {
                            $modifiedProductCount = $syncResponse['stats']['modified'];
                            $syncResponse['message'] = (($modifiedProductCount == 1) ? "Product" : $modifiedProductCount . " Products") . " updated successfully";
                        } else {
                            $syncResponse['message'] = "Product already synced!";
                        }

                        $returnRes['updated'] = $syncResponse['message'];

                        $eventData = [
                            'shop' => $shop,
                            'modified_productIds' => $syncResponse['stats']['modifiedProductIds'] ?? [],
                            'productIds' => $product_ids,
                            'triggerAction' => __FUNCTION__
                        ];
                        $eventsManager = $this->di->getEventsManager();
                        $eventsManager->fire('application:aftersourceProductSync', $this, $eventData);
                    } else {
                        return $syncResponse;
                    }
                }

                return [
                    'success' => !empty($returnRes) ? true : false,
                    'stats' => !empty($returnRes) ? $returnRes : "No Product found to be synced."
                ];
            }

            return [
                'success' => false,
                'message' => 'Product Container Id missing!'
            ];
        }

        return [
            'success' => false,
            'message' => 'Required Souce data missing'
        ];
    }
}
