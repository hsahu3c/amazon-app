<?php

namespace App\Amazon\Components\Listings;

use Exception;
use App\Core\Components\Base as Base;

class ProcessResponse extends Base
{
    public const NO_ERROR_SEVERITY = 'NONE';

    public function processListingIssueEvent($data)
    {
        try {
            if (isset($data['user_id'], $data['shop_id'], $data['data']['Sku'], $data['data']['MarketplaceId'])) {
                if (isset($data['data']['Severities'][0]) && $data['data']['Severities'][0] != self::NO_ERROR_SEVERITY) {
                    $listingHelper = $this->di->getObjectManager()->get(Helper::class);
                    $userId = (string)$data['user_id'];
                    $targetShopId = (string)$data['shop_id'];
                    $sku = $data['data']['Sku'];
                    $targetShop = $listingHelper->getTargetShop($userId, $targetShopId);
                    
                    $sourceShopId = $listingHelper->getSourceShopId($userId, $targetShopId, $targetShop);
                    
                    // Fetch source shop data to get the marketplace dynamically
                    $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                    $sourceShop = $userDetails->getShop($sourceShopId, $userId);
                    $sourceMarketplace = $sourceShop['marketplace']; 
                    
                    $additionalData = [
                        'source' => [
                            'shopId' => (string)$sourceShopId,
                            'marketplace' => $sourceMarketplace
                        ],
                        'target' => [
                            'shopId' => (string)$targetShopId,
                            'marketplace' => 'amazon'
                        ]
                    ];
                    $remoteShopId = $targetShop['remote_shop_id'] ?? '';
                    $marketplaceId = $targetShop['warehouses'][0]['marketplace_id'] ?? '';
                    if ($remoteShopId && $marketplaceId == $data['data']['MarketplaceId']) {
                        /** get product */
                        // $projection = ['source_product_id' => 1, 'container_id' => 1];
                        // $product = $listingHelper->queryProduct($userId, false, false, $targetShopId, $projection, $sku);
                        // if (empty($product)) {
                        //     $product = $listingHelper->queryProduct($userId, false, $sourceShopId, false, $projection, $sku);
                        // }
                        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                        $refineCollection = $mongo->getCollection("refine_product");
                        $options = [
                        'projection' => ['_id' => 1, 'items.$' => 1],
                        'typeMap' => ['root' => 'array', 'document' => 'array']
                                    ];
                        $refineProductData = $refineCollection->findOne([
                            'user_id' => $userId,
                            'source_shop_id' => $sourceShopId,
                            'target_shop_id' => $targetShopId,
                            'items' => ['$elemMatch' => ['sku' => $sku]]
                        ], $options);
                        if (!empty($refineProductData['items'])) {
                            $sourceProductId = $refineProductData['items'][0]['source_product_id'];
                            $containerId = $refineProductData['items'][0]['container_id'];
                        }

                        if (!empty($refineProductData) && isset($sourceProductId, $containerId)) {
                            $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                            $params = [
                                'shop_id' => $remoteShopId,
                                'sku' => $sku,
                                'issueLocale' => 'en_US',
                                'includedData' => 'issues'
                            ];
                            $response = $commonHelper->sendRequestToAmazon('listing', $params, 'GET');
                            if (isset($response['success']) && $response['success']) {
                                if (isset($response['response']['issues']) && !empty($response['response']['issues'])) {
                                    $errorHelper = $this->di->getObjectManager()->get(Error::class);
                                    $formattedErrors = $errorHelper->formateErrorMessages($response['response']['issues']);
                                    if (!empty($formattedErrors)) {
                                        $updateData = [
                                            "source_product_id" => (string)$sourceProductId,
                                            'user_id' => $userId,
                                            'source_shop_id' =>  (string)$sourceShopId,
                                            'container_id' => (string)$containerId,
                                            'shop_id' => (string)$targetShopId,
                                            'target_marketplace' => 'amazon'
                                        ];
                                        if (isset($formattedErrors['error']) && !empty($formattedErrors['error'])) {
                                            $updateData['error'] = $formattedErrors['error'];
                                            $updateData['unset']['process_tags'] = 1;
                                        }

                                        if(isset($formattedErrors['warning']) && !empty($formattedErrors['warning'])) {
                                            $updateData['warning'] = $formattedErrors['warning'];
                                        }

                                        $marketplaceHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                                        $marketplaceHelper->saveProduct([$updateData],$userId,$additionalData);
                                        return ['success' => true, 'message processed'];
                                    }
                                    $error = 'formatted error are empty';
                                } else {
                                    $error = 'No issues found for listing';
                                }
                            } else {
                                $error = $response['error'] ?? 'Error retrieving issues for listing';
                            }
                        } else {
                            $error = 'product not found in DB';
                        }
                    } else {
                        $error = 'remote shop id not found for shop';
                    }
                } else {
                    return ['success' => true, 'data' => 'No error received on listing'];
                }
            } else {
                $error = 'Required params(sku, seller_id, marketplace_id) not found';
            }

            return [
                'success' => false,
                'error' => $error ?? 'Error processing response'
            ];
        } catch (Exception $e) {
            print_r($e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
