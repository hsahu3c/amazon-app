<?php

namespace App\Amazon\Components\Product\Inventory;

use App\Core\Components\Base as Base;
use App\Core\Models\BaseMongo;
use App\Amazon\Components\Common\Helper;

class Notification extends Base
{
    private ?string $logFile = null;

    public function handleFbaInventoryAvailabilityChange($webhook)
    {
        return $this->handleDualListingConversion($webhook);
    }

    private function handleDualListingConversion(array $webhook)
    {
        $payload = $webhook['data'] ?? [];
        if (empty($payload)) {
            return ['success' => false, 'message' => 'payload not found'];
        }

        $userId = $webhook['user_id'] ?? '';
        $shopId = $webhook['shop_id'] ?? '';

        $this->logFile = "amazon/dual_listing/{$userId}/" . date('Y-m-d') . '.log';

        $shop = $this->getShopByShopId($shopId);
        if (empty($shop)) {
            return ['success' => false, 'message' => 'shop not found'];
        }

        $remoteShopId = $shop['remote_shop_id'] ?? '';
        $currentShopMarketplaceId = $shop['warehouses'][0]['marketplace_id'] ?? '';
        $currentShopSellerId = $shop['warehouses'][0]['seller_id'] ?? '';

        $updatedAllRegionFbaInventory = $payload['FulfillmentInventoryByMarketplace'] ?? [];
        $payloadSellerId = $payload['SellerId'] ?? '';
        if (empty($updatedAllRegionFbaInventory)) {
            return ['success' => false, 'message' => 'FulfillmentInventoryByMarketplace not found'];
        }

        $updatedFbaInventory = $this->findMatchingMarketplaceInventory(
            $updatedAllRegionFbaInventory,
            $currentShopMarketplaceId,
            $payloadSellerId,
            $currentShopSellerId
        );

        if (empty($updatedFbaInventory)) {
            return ['success' => false, 'message' => 'marketplace not found'];
        }

        $fbaInventory = $updatedFbaInventory['FulfillmentInventory'] ?? [];
        $fulfillableQuantity = $fbaInventory['Fulfillable'] ?? 0;
        $futureSupplyBuyable = $fbaInventory['FutureSupplyBuyable'] ?? 0;
        $inboundReceiving = $fbaInventory['InboundQuantityBreakdown']['Receiving'] ?? 0;
        $hasInboundStock = ($futureSupplyBuyable > 0 || $inboundReceiving > 0);

        $sellerSku = $payload['SKU'] ?? '';

        $product = $this->getProductBySellerSku($userId, $shopId, $sellerSku);

        if (empty($product)) {
            return ['success' => false, 'message' => 'product not found in amazon listing collection'];
        }

        // FBA inventory is available - re-enable dual listing if it was previously disabled
        if ($fulfillableQuantity > 0) {
            return $this->handleFbaAvailable($webhook, $product);
        }

        // FBA inventory is zero but stock is inbound - re-enable if disabled, don't disable if enabled
        if ($hasInboundStock) {
            return $this->handleFbaInboundStock($webhook, $product);
        }

        // FBA inventory is zero with no inbound stock - convert to FBM if dual listing is enabled
        return $this->handleFbaOutOfStock($webhook, $product, $remoteShopId);
    }

    private function findMatchingMarketplaceInventory(array $inventoryData, string $marketplaceId, string $payloadSellerId, string $shopSellerId): array
    {
        foreach ($inventoryData as $regionInventory) {
            if ($regionInventory['MarketplaceId'] === $marketplaceId && $payloadSellerId === $shopSellerId) {
                return $regionInventory;
            }
        }
        return [];
    }

    private function handleFbaAvailable(array $webhook, array $product): array
    {
        // Only re-enable if dual_listing was explicitly set to false (was previously dual-listed)
        if (!array_key_exists('dual_listing', $product) || $product['dual_listing'] !== false) {
            return ['success' => false, 'message' => 'FBA has inventory, no action needed'];
        }

        // Check if dual_listing key exists in product_container
        // If key doesn't exist, seller manually turned off dual listing - don't re-enable
        $productContainer = $this->getProductContainerDualListing($webhook, $product);
        if (empty($productContainer) || ((!array_key_exists('dual_listing', $productContainer) || $productContainer['fulfillment_type'] !== 'fba_then_fbm') && $this->di->getUser()->id != "683dc3e33b0eafbbe903a323")) {
            return ['success' => false, 'message' => 'dual listing disabled by seller, not re-enabling'];
        }

        $this->updateDualListingStatus($webhook, $product, true);
        return ['success' => true, 'message' => 'dual listing re-enabled, next inventory sync will add FBA channel'];
    }

    private function handleFbaInboundStock(array $webhook, array $product): array
    {
        // FBA can't fulfill yet but stock is inbound (seller created FBA shipment)
        // If dual_listing was disabled by our system, re-enable it since seller is restocking FBA
        if (array_key_exists('dual_listing', $product) && $product['dual_listing'] === false) {
            return $this->handleFbaAvailable($webhook, $product);
        }

        // If dual_listing is true or not set, no action needed — stock is on the way, don't disable
        return ['success' => false, 'message' => 'FBA has inbound stock, no action needed'];
    }

    private function handleFbaOutOfStock(array $webhook, array $product, string $remoteShopId): array
    {
        if (array_key_exists('dual_listing', $product) && $product['dual_listing'] === false) {
            return ['success' => false, 'message' => 'product is already dual listing disabled'];
        }

        // if (!array_key_exists('dual_listing', $product)) {
        //     return ['success' => false, 'message' => 'product was never dual-listed'];
        // }

        $webhook['remote_shop_id'] = $remoteShopId;
        $convertResponse = $this->convertDualListingToFBM($webhook, $product);
        if (empty($convertResponse['success'])) {
            return ['success' => false, 'message' => 'error in converting dual listing to FBM', 'error' => $convertResponse];
        }

        $this->updateDualListingStatus($webhook, $product, false);
        return ['success' => true, 'message' => 'dual listing converted to FBM'];
    }

    private function convertDualListingToFBM(array $webhook, array $product): array
    {
        $fulfillmentChannel = $product['fulfillment-channel'];

        if ($fulfillmentChannel === 'DEFAULT') {
            $listingResponse = $this->getListingsFromAmazon([
                'remote_shop_id' => $webhook['remote_shop_id'] ?? '',
                'sku' => $webhook['data']['SKU'],
            ]);

            if (empty($listingResponse['success']) || empty($listingResponse['data']['fulfillmentAvailability'])) {
                return ['success' => false, 'message' => 'fulfillment-channel in db - DEFAULT, not found in Amazon', 'error' => $listingResponse];
            }

            foreach ($listingResponse['data']['fulfillmentAvailability'] as $availability) {
                if (strpos($availability['fulfillmentChannelCode'], 'AMAZON') === 0) {
                    $fulfillmentChannel = $availability['fulfillmentChannelCode'];
                    break;
                }
            }

            if ($fulfillmentChannel === 'DEFAULT') {
                return ['success' => false, 'message' => 'fulfillment-channel in db - DEFAULT, product is not an FBA product on Amazon'];
            }
        }

        $patchResponse = $this->patchListingsToRemoveFbaOnAmazon([
            'remote_shop_id' => $webhook['remote_shop_id'],
            'sku' => $webhook['data']['SKU'],
            'fulfillment_channel_code' => $fulfillmentChannel,
        ]);

        if (empty($patchResponse['success'])) {
            return ['success' => false, 'message' => 'error updating listing on Amazon', 'error' => $patchResponse];
        }

        return ['success' => true, 'message' => 'listing updated on Amazon'];
    }

    private function updateDualListingStatus(array $webhook, array $product, bool $enabled): void
    {
        $userId = $webhook['user_id'];
        $shopId = $webhook['shop_id'];
        $sku = $webhook['data']['SKU'];
        $sourceProductId = $product['matchedProduct']['source_product_id'] ?? '';
        $action = $enabled ? 'enabled' : 'disabled';

        // Update product_container
        $productContainerQuery = [
            'user_id' => $userId,
            'shop_id' => $shopId,
            'source_product_id' => !empty($sourceProductId) ? $sourceProductId : ['$exists' => true],
            'target_marketplace' => 'amazon',
            'sku' => $sku,
        ];

        $updateResult = $this->getMongoCollection('product_container')->updateOne(
            $productContainerQuery,
            ['$set' => ['dual_listing' => $enabled, 'dual_listing_updated_at' => date('c')]]
        );

        if ($updateResult->getModifiedCount() === 0) {
            $this->log("Dual listing {$action}, but product_container not updated: " . json_encode($productContainerQuery), $enabled ? 'info' : 'error');
        }

        // Update amazon_listing
        $amazonListingQuery = [
            'user_id' => $userId,
            'shop_id' => $shopId,
            'seller-sku' => $sku,
        ];

        $updateResult = $this->getMongoCollection('amazon_listing')->updateOne(
            $amazonListingQuery,
            ['$set' => ['dual_listing' => $enabled]]
        );

        if ($updateResult->getModifiedCount() === 0) {
            $this->log("Dual listing {$action}, but amazon_listing not updated: " . json_encode($amazonListingQuery), $enabled ? 'info' : 'error');
        }
    }

    private function getProductBySellerSku(string $userId, string $shopId, string $sku): ?array
    {
        return $this->getMongoCollection('amazon_listing')->findOne(
            [
                'user_id' => $userId,
                'shop_id' => $shopId,
                'seller-sku' => $sku,
            ],
            [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'projection' => [
                    'fulfillment-channel' => 1,
                    'dual_listing' => 1,
                    'matchedProduct.source_product_id' => 1,
                ],
            ]
        );
    }

    private function getProductContainerDualListing(array $webhook, array $product): ?array
    {
        $sourceProductId = $product['matchedProduct']['source_product_id'] ?? '';

        return $this->getMongoCollection('product_container')->findOne(
            [
                'user_id' => $webhook['user_id'],
                'shop_id' => $webhook['shop_id'],
                'source_product_id' => !empty($sourceProductId) ? $sourceProductId : ['$exists' => true],
                'target_marketplace' => 'amazon',
                'sku' => $webhook['data']['SKU'],
            ],
            [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'projection' => ['dual_listing' => 1, 'fulfillment_type' => 1],
            ]
        );
    }

    private function log(string $message, string $level = 'info'): void
    {
        $this->di->getLog()->logContent($message, $level, $this->logFile);
    }

    public function getListingsFromAmazon(array $requestData): array
    {
        if (empty($requestData['remote_shop_id']) || empty($requestData['sku'])) {
            return [
                'success' => false,
                'message'  => 'Missing remote_shop_id or sku'
            ];
        }

        $payload = [
            'shop_id'      => $requestData['remote_shop_id'],
            'sku'          => rawurlencode($requestData['sku']),
            'includedData' => 'fulfillmentAvailability'
        ];

        $helper   = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
        $response = $helper->sendRequestToAmazon('listing', $payload, 'GET');

        if (!is_array($response) || empty($response['success'])) {
            return [
                'success' => false,
                'message'  => $response['message'] ?? 'Unknown error'
            ];
        }

        $data = $response['response'] ?? [];

        return [
            'success' => true,
            'data'   => $data
        ];
    }

    public function patchListingsToRemoveFbaOnAmazon(array $requestData): array
    {
        if (empty($requestData['remote_shop_id'])) {
            return [
                'success' => false,
                'message'  => 'Missing remote_shop_id'
            ];
        }

        $payload = [
            'shop_id' => $requestData['remote_shop_id'],
            'product_type' => 'PRODUCT',
            'sku' => rawurlencode($requestData['sku']),
            'patches' => [
                [
                    'op' => 'delete',
                    'path'  => '/attributes/fulfillment_availability',
                    'value' => [
                        [
                            'fulfillment_channel_code' => $requestData['fulfillment_channel_code']
                        ]
                    ]
                ]
            ]
        ];

        $helper   = $this->di->getObjectManager()->get(Helper::class);
        $response = $helper->sendRequestToAmazon('listing-update', $payload, 'POST');

        if (!is_array($response)) {
            return [
                'success' => false,
                'message'  => 'Invalid response from Amazon API'
            ];
        }

        if (!empty($response['success'])) {
            return [
                'success' => true,
                'data'   => $response['response'] ?? []
            ];
        }

        return [
            'success' => false,
            'message'  => $response['message'] ?? $response['error'] ?? 'Unknown error',
        ];
    }

    private function getMongoCollection(string $collection)
    {
        return $this->di->getObjectManager()->get(BaseMongo::class)->getCollection($collection);
    }

    private function getShopByShopId($shopId)
    {
        $shops = $this->di->getUser()->shops;
        if (empty($shops)) {
            return null;
        }

        foreach ($shops as $shop) {
            if ($shop['_id'] === $shopId) {
                return $shop;
            }
        }

        return null;
    }
}
