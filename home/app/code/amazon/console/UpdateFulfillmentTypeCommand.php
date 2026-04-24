<?php

use App\Core\Models\User;
use App\Core\Models\BaseMongo;
use App\Amazon\Components\Common\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;

class UpdateFulfillmentTypeCommand extends Command
{
    protected static $defaultName = "update-fulfillment-type";
    protected static $defaultDescription = "Update FBA dual-listed products to FBM when FBA inventory is zero";

    protected $di;
    protected $output;
    protected $input;
    protected $logFile;

    /**
     * Constructor
     * Calls parent constructor and sets the di
     *
     * @param $di
     */
    public function __construct($di)
    {
        parent::__construct();
        $this->di = $di;
        $this->logFile = 'amazon/update-fulfillment-type/' . date('Y-m-d') . '.log'; // default, updated with user_id in execute()
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Update FBA dual-listed products to FBM when FBA inventory goes to zero');
        $this->addOption("user_id", "u", InputOption::VALUE_REQUIRED, "User ID of the seller");
        $this->addOption("shop_id", "s", InputOption::VALUE_REQUIRED, "Amazon Shop ID of the seller");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $this->input = $input;

        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Initiating Update Fulfillment Type...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $userId = $input->getOption("user_id") ?? false;
        $shopId = $input->getOption("shop_id") ?? false;

        if (!$userId || !$shopId) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: user_id (-u) and shop_id (-s) are required</>');
            return Command::FAILURE;
        }

        $this->logFile = "amazon/dual_listing/{$userId}/" . date('Y-m-d') . '.log';
        $this->log("Starting process for user_id: {$userId}, shop_id: {$shopId}");

        // Set user in DI container
        $setUserResponse = $this->setDiForUser($userId);
        if (empty($setUserResponse['success'])) {
            $message = $setUserResponse['message'] ?? 'Unknown error setting user';
            $output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: {$message}</>");
            $this->log("Error setting user: {$message}");
            return Command::FAILURE;
        }

        // Get shop details
        $shop = $this->getShopByShopId($shopId);
        if (empty($shop)) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: Shop not found</>');
            $this->log("Shop not found for shop_id: {$shopId}");
            return Command::FAILURE;
        }

        $remoteShopId = $shop['remote_shop_id'] ?? '';
        if (empty($remoteShopId)) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: remote_shop_id not found for shop</>');
            $this->log("remote_shop_id not found for shop_id: {$shopId}");
            return Command::FAILURE;
        }

        $marketplaceId = $shop['warehouses'][0]['marketplace_id'] ?? '';
        if (empty($marketplaceId)) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: marketplace_id not found for shop</>');
            $this->log("marketplace_id not found for shop_id: {$shopId}");
            return Command::FAILURE;
        }

        $output->writeln("<options=bold;fg=green> ➤ User and shop validated. Remote Shop ID: {$remoteShopId}, Marketplace ID: {$marketplaceId}</>");

        // Process FBA dual-listed products
        $result = $this->processFbaDualListedProducts($userId, $shopId, $remoteShopId, $marketplaceId);

        $output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> Process Completed</>");
        $output->writeln("<options=bold;fg=cyan> ➤ Total processed: {$result['total']}</>");
        $output->writeln("<options=bold;fg=green> ➤ Updated to FBM: {$result['updated']}</>");
        $output->writeln("<options=bold;fg=yellow> ➤ Skipped: {$result['skipped']}</>");
        $output->writeln("<options=bold;fg=red> ➤ Errors: {$result['errors']}</>");

        return Command::SUCCESS;
    }

    /**
     * Process all FBA dual-listed products for the given user and shop
     *
     * @param string $userId
     * @param string $shopId
     * @param string $remoteShopId
     * @param string $marketplaceId
     * @return array
     */
    private function processFbaDualListedProducts(string $userId, string $shopId, string $remoteShopId, string $marketplaceId): array
    {
        $result = [
            'total' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // Query FBA dual-listed products
        $products = $this->getFbaDualListedProducts($userId, $shopId);
        $result['total'] = count($products);

        $this->output->writeln("<options=bold;fg=cyan> ➤ Found {$result['total']} FBA dual-listed products</>");
        $this->log("Found {$result['total']} FBA dual-listed products");

        foreach ($products as $product) {
            $sku = $product['seller-sku'] ?? '';
            if (empty($sku)) {
                $this->log("Skipping product without SKU");
                $result['skipped']++;
                continue;
            }

            $this->output->writeln("<options=bold;fg=white> ➤ Processing SKU: {$sku}</>");

            // Fetch listing from Amazon to verify current state (FBA channel & dual-listing)
            $listingResponse = $this->getListingsFromAmazon($remoteShopId, $sku);
            if (empty($listingResponse['success'])) {
                $message = $listingResponse['message'] ?? 'Unknown error';
                $this->output->writeln("<options=bold;fg=red>   ✗ Error fetching listing from Amazon: {$message}</>");
                $this->log("Error fetching listing for SKU {$sku}: {$message}");
                $result['errors']++;
                continue;
            }

            $fulfillmentAvailability = $listingResponse['data']['fulfillmentAvailability'] ?? [];
            $attributes = $listingResponse['data']['attributes'] ?? [];
            if (empty($fulfillmentAvailability)) {
                $this->output->writeln("<options=bold;fg=yellow>   ⊘ No fulfillment availability data, skipping</>");
                $this->log("No fulfillment availability for SKU {$sku}");
                $result['skipped']++;
                continue;
            }

            // Step 1: Verify product is FBA and dual-listed from listing data
            $fbaCheck = $this->checkFbaAndDualListed($fulfillmentAvailability, $attributes);
            if (!$fbaCheck['is_fba_dual_listed']) {
                $reason = $fbaCheck['reason'] ?? 'Unknown';
                $this->output->writeln("<options=bold;fg=yellow>   ⊘ Skipping: {$reason}</>");
                $this->log("Skipping SKU {$sku}: {$reason}");
                $result['skipped']++;
                continue;
            }

            $fulfillmentChannelCode = $fbaCheck['fulfillment_channel_code'];

            // Step 2: Use FBA Inventory Summaries API to check actual FBA inventory
            $inventoryResponse = $this->getFbaInventorySummary($remoteShopId, $sku, $marketplaceId);
            if (empty($inventoryResponse['success'])) {
                $message = $inventoryResponse['message'] ?? 'Unknown error';
                $this->output->writeln("<options=bold;fg=red>   ✗ Error fetching FBA inventory: {$message}</>");
                $this->log("Error fetching FBA inventory for SKU {$sku}: {$message}");
                $result['errors']++;
                continue;
            }

            if (!$inventoryResponse['is_zero']) {
                $totalQty = $inventoryResponse['total_quantity'] ?? 'N/A';
                $this->output->writeln("<options=bold;fg=yellow>   ⊘ Skipping: FBA inventory is not zero (totalQuantity: {$totalQty})</>");
                $this->log("Skipping SKU {$sku}: FBA inventory is not zero (totalQuantity: {$totalQty})");
                $result['skipped']++;
                continue;
            }

            // Step 3: Update listing on Amazon to remove FBA fulfillment
            $patchResponse = $this->patchListingsToRemoveFbaOnAmazon($remoteShopId, $sku, $fulfillmentChannelCode);
            if (empty($patchResponse['success'])) {
                $message = $patchResponse['message'] ?? 'Unknown error';
                $this->output->writeln("<options=bold;fg=red>   ✗ Error updating on Amazon: {$message}</>");
                $this->log("Error patching listing for SKU {$sku}: {$message}");
                $result['errors']++;
                continue;
            }

            // Step 4: Disable dual listing in database
            $this->disableDualListingInventorySync($userId, $shopId, $sku, $product);

            $this->output->writeln("<options=bold;fg=green>   ✓ Successfully converted to FBM</>");
            $this->log("Successfully converted SKU {$sku} to FBM");
            $result['updated']++;
        }

        return $result;
    }

    /**
     * Get FBA dual-listed products from amazon_listing collection
     *
     * @param string $userId
     * @param string $shopId
     * @return array
     */
    private function getFbaDualListedProducts(string $userId, string $shopId): array
    {
        $query = [
            'user_id' => $userId,
            'shop_id' => $shopId,
            'fulfillment-channel' => ['$ne' => 'DEFAULT'], // FBA products (not DEFAULT/FBM)
            'dual_listing' => ['$ne' => false],            // dual-listed products
        ];

        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'projection' => [
                'seller-sku' => 1,
                'fulfillment-channel' => 1,
                'dual_listing' => 1,
                'matchedProduct.source_product_id' => 1,
            ],
        ];

        $collection = $this->getMongoCollection('amazon_listing');
        $cursor = $collection->find($query, $options);

        return $cursor->toArray();
    }

    /**
     * Fetch listing data from Amazon API
     *
     * @param string $remoteShopId
     * @param string $sku
     * @return array
     */
    private function getListingsFromAmazon(string $remoteShopId, string $sku): array
    {
        if (empty($remoteShopId) || empty($sku)) {
            return [
                'success' => false,
                'message' => 'Missing remote_shop_id or sku'
            ];
        }

        $payload = [
            'shop_id' => $remoteShopId,
            'sku' => rawurlencode($sku),
            'includedData' => 'fulfillmentAvailability,attributes'
        ];

        $helper = $this->di->getObjectManager()->get(Helper::class);
        $response = $helper->sendRequestToAmazon('listing', $payload, 'GET');

        if (!is_array($response) || empty($response['success'])) {
            return [
                'success' => false,
                'message' => $response['message'] ?? 'Unknown error'
            ];
        }

        return [
            'success' => true,
            'data' => $response['response'] ?? []
        ];
    }

    /**
     * Check if product is FBA and dual-listed using listing data
     * This only verifies the FBA channel and dual-listing status, NOT inventory quantity
     *
     * @param array $fulfillmentAvailability From listing API (fulfillmentAvailability)
     * @param array $attributes From listing API (attributes)
     * @return array
     */
    private function checkFbaAndDualListed(array $fulfillmentAvailability, array $attributes): array
    {
        foreach ($fulfillmentAvailability as $availability) {
            $channelCode = $availability['fulfillmentChannelCode'] ?? '';

            // Check if it's FBA (starts with AMAZON)
            if (strpos($channelCode, 'AMAZON') === 0) {
                // Verify product is dual-listed by checking for DEFAULT channel in attributes
                if (empty($attributes['fulfillment_availability'])) {
                    return [
                        'is_fba_dual_listed' => false,
                        'reason' => 'No fulfillment_availability in attributes',
                    ];
                }

                $hasFbmChannel = false;
                foreach ($attributes['fulfillment_availability'] as $attrAvailability) {
                    if (($attrAvailability['fulfillment_channel_code'] ?? '') === 'DEFAULT') {
                        $hasFbmChannel = true;
                        break;
                    }
                }

                if (!$hasFbmChannel) {
                    return [
                        'is_fba_dual_listed' => false,
                        'reason' => 'Not a dual-listed product (no DEFAULT channel in attributes)',
                    ];
                }

                return [
                    'is_fba_dual_listed' => true,
                    'fulfillment_channel_code' => $channelCode,
                ];
            }
        }

        return [
            'is_fba_dual_listed' => false,
            'reason' => 'Not an FBA product'
        ];
    }

    /**
     * Get FBA inventory summary from Amazon using the FBA Inventory Summaries API
     * This is the authoritative source for real-time FBA stock levels
     *
     * @param string $remoteShopId
     * @param string $sku
     * @param string $marketplaceId
     * @return array
     */
    private function getFbaInventorySummary(string $remoteShopId, string $sku, string $marketplaceId): array
    {
        if (empty($remoteShopId) || empty($sku) || empty($marketplaceId)) {
            return [
                'success' => false,
                'message' => 'Missing remote_shop_id, sku or marketplace_id'
            ];
        }

        $payload = [
            'shop_id' => $remoteShopId,
            'seller_sku' => $sku,
        ];

        $helper = $this->di->getObjectManager()->get(Helper::class);
        $response = $helper->sendRequestToAmazon('fba-inventory-summary', $payload, 'GET');

        if (!is_array($response) || empty($response['success'])) {
            return [
                'success' => false,
                'message' => $response['message'] ?? $response['error'] ?? $response['response']['error'] ?? 'Unknown error fetching FBA inventory'
            ];
        }

        $inventorySummaries = $response['response']['payload']['inventorySummaries'] ?? [];

        // If no inventory summary found for this SKU, it means no FBA inventory exists
        if (empty($inventorySummaries)) {
            $this->log("No FBA inventory summary found for SKU {$sku} - treating as zero inventory");
            return [
                'success' => true,
                'is_zero' => true,
                'total_quantity' => 0,
            ];
        }

        // Use the first summary (should be only one for a single SKU)
        $summary = $inventorySummaries[0];
        $totalQuantity = $summary['totalQuantity'] ?? 0;

        return [
            'success' => true,
            'is_zero' => $totalQuantity == 0,
            'total_quantity' => $totalQuantity,
        ];
    }

    /**
     * Patch listing on Amazon to remove FBA fulfillment
     *
     * @param string $remoteShopId
     * @param string $sku
     * @param string $fulfillmentChannelCode
     * @return array
     */
    private function patchListingsToRemoveFbaOnAmazon(string $remoteShopId, string $sku, string $fulfillmentChannelCode): array
    {
        if (empty($remoteShopId)) {
            return [
                'success' => false,
                'message' => 'Missing remote_shop_id'
            ];
        }

        $payload = [
            'shop_id' => $remoteShopId,
            'product_type' => 'PRODUCT',
            'sku' => rawurlencode($sku),
            'patches' => [
                [
                    'op' => 'delete',
                    'path' => '/attributes/fulfillment_availability',
                    'value' => [
                        [
                            'fulfillment_channel_code' => $fulfillmentChannelCode
                        ]
                    ]
                ]
            ]
        ];

        $helper = $this->di->getObjectManager()->get(Helper::class);
        $response = $helper->sendRequestToAmazon('listing-update', $payload, 'POST');

        if (!is_array($response)) {
            return [
                'success' => false,
                'message' => 'Invalid response from Amazon API'
            ];
        }

        if (!empty($response['success'])) {
            return [
                'success' => true,
                'data' => $response['response'] ?? []
            ];
        }

        return [
            'success' => false,
            'message' => $response['message'] ?? $response['error'] ?? 'Unknown error'
        ];
    }

    /**
     * Disable dual listing inventory sync in database
     *
     * @param string $userId
     * @param string $shopId
     * @param string $sku
     * @param array $product
     * @return void
     */
    private function disableDualListingInventorySync(string $userId, string $shopId, string $sku, array $product): void
    {
        $sourceProductId = $product['matchedProduct']['source_product_id'] ?? '';

        // Update product_container
        $productContainerQuery = [
            'user_id' => $userId,
            'shop_id' => $shopId,
            'source_product_id' => !empty($sourceProductId) ? $sourceProductId : ['$exists' => true],
            'target_marketplace' => 'amazon',
            'sku' => $sku,
        ];

        $updateProductContainerResponse = $this->getMongoCollection('product_container')->updateOne(
            $productContainerQuery,
            [
                '$set' => [
                    'dual_listing' => false,
                    'dual_listing_updated_at' => date('c'),
                ],
            ]
        );

        if ($updateProductContainerResponse->getModifiedCount() === 0) {
            $this->log('Dual listing disabled, but product container not updated: ' . json_encode($productContainerQuery));
        }

        // Update amazon_listing
        $amazonListingQuery = [
            'user_id' => $userId,
            'shop_id' => $shopId,
            'seller-sku' => $sku,
        ];

        $updateAmazonListingResponse = $this->getMongoCollection('amazon_listing')->updateOne(
            $amazonListingQuery,
            [
                '$set' => [
                    'dual_listing' => false,
                ],
            ]
        );

        if ($updateAmazonListingResponse->getModifiedCount() === 0) {
            $this->log('Dual listing disabled, but amazon listing not updated: ' . json_encode($amazonListingQuery));
        }
    }

    /**
     * Set user in DI container
     *
     * @param string $userId
     * @return array
     */
    private function setDiForUser(string $userId): array
    {
        if ($this->di->getUser()->id == $userId) {
            return [
                'success' => true,
                'message' => 'User already set in di'
            ];
        }

        try {
            $getUser = User::findFirst([['_id' => $userId]]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);

        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'User not found in DB. Fetched di of admin.'
            ];
        }

        return [
            'success' => true,
            'message' => 'User set in di successfully'
        ];
    }

    /**
     * Get shop by shop ID from user shops
     *
     * @param string $shopId
     * @return array|null
     */
    private function getShopByShopId(string $shopId): ?array
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

    /**
     * Get MongoDB collection
     *
     * @param string $collection
     * @return \MongoDB\Collection
     */
    private function getMongoCollection(string $collection)
    {
        return $this->di->getObjectManager()->get(BaseMongo::class)->getCollection($collection);
    }

    /**
     * Log message to file
     *
     * @param string $message
     * @return void
     */
    private function log(string $message): void
    {
        $this->di->getLog()->logContent($message, 'info', $this->logFile);
    }
}
