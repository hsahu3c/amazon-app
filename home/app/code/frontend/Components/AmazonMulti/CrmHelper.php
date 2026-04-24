<?php

namespace App\Frontend\Components\AmazonMulti;

use App\Core\Components\Base;
use MongoDB\Collection as MongoCollection;

class CrmHelper extends Base
{
    public const ORDER_TIME = "-1 day";

    public const SALES_CONTAINER_COLLECTION = 'sales_container';

    public const REFINE_PRODUCT_COLLECTION = 'refine_product';

    public const PAYMENT_DETAILS_COLLECTION = 'payment_details';

    public const AMAZON_LISTING_COLLECTION = 'amazon_listing';

    public const UNINSTALL_USER_DETAILS = 'uninstall_user_details';

    public const ORDER_CONTAINER_COLLECTION = 'order_container';

    public ?string $userId = null;

    public array $userShops = [];

    public ?string $shopifyShopId = null;

    public ?string $firstAmazonShopId = null;

    public bool $hasMultipleAmazonShops = false;

    public function updateCrmData(array $data)
    {
        if (!class_exists(\App\Zoho\Components\QueueProcess::class)) {
            return;
        }

        if (!method_exists(\App\Zoho\Components\QueueProcess::class, 'processProductOrderInfo')) {
            return;
        }

        $this->userId = $data['user_id'] ?? $this->di->getUser()->id;
        $this->userShops = $this->di->getUser()?->shops ?? [];
        if (empty($this->userShops)) {
            return;
        }

        $this->shopifyShopId = $this->userShops[0]['_id'] ?? null;

        $this->firstAmazonShopId = $this->userShops[1]['_id'] ?? null;

        $this->hasMultipleAmazonShops = count($this->userShops) > 2;

        $lastDayOrderCount = $this->getLastDayOrderCount();

        $totalImportedProducts = $this->getImportedProductsCount();

        $userOrderService = $this->getOrderCreditStatus();
        $marketplaceOrderStatus = [
            'total_order_credits' => $userOrderService['prepaid']['service_credits'] ?? 0,
            'order_limiting_remaining' => $userOrderService['prepaid']['available_credits'] ?? 0,
            'total_used_credits' => $userOrderService['prepaid']['total_used_credits'] ?? 0,
            'monthly_orders' => $userOrderService['prepaid']['total_used_credits'] ?? 0,
        ];

        $notLinkedProductsCount = $this->getNotLinkedProductsCount();

        $linkedOrUploadedProductsCount = $this->getLinkedProductsCount();

        $listingErrorsProductsCount = $this->getListingErrorsProductsCount();

        $finalData = [
            'shopify_shop_json' => json_encode($this->userShops[0]),
            'data' => [
                'store_url' => $this->di->getUser()->username,
                'last_day_order_count' => $lastDayOrderCount,
                'total_imported_products' => $totalImportedProducts,
                'marketplace_order_status' => $marketplaceOrderStatus,
                'not_linked_products' => $notLinkedProductsCount,
                'uploaded_products' => $linkedOrUploadedProductsCount,
                'listing_errors' => $listingErrorsProductsCount,
                'has_multiple_amazon_shops' => $this->hasMultipleAmazonShops,
            ]
        ];
        $zohoHelper = $this->di->getObjectManager()->get(\App\Zoho\Components\QueueProcess::class);
        $zohoHelper->processProductOrderInfo($finalData);
    }

    public function getLastDayOrderCount(): int|string
    {
        $orderAfter = date('Y-m-d', strtotime(self::ORDER_TIME));
        $params = [];
        $params[] = [
            '$match' => [
                'user_id' => $this->userId,
                'order_date' => [
                    '$gte' => $orderAfter
                ]
            ]
        ];
        $params[] = [
            '$group' => [
                '_id' => '$user_id',
                'total_orders' => ['$sum' => ['$toInt' => '$order_count']],
            ]
        ];
        $options = $this->getMongoOptions();

        $salesContainerCollection = $this->getMongoCollection(self::SALES_CONTAINER_COLLECTION);
        $dbResponse = $salesContainerCollection->aggregate($params, $options)->toArray();
        return $dbResponse[0]['total_orders'] ?? 0;
    }

    public function getImportedProductsCount(): int|string
    {
        $productContainerCollection = $this->getMongoCollection(self::REFINE_PRODUCT_COLLECTION);
        $params = [];
        $params[] = [
            '$match' => [
                'user_id' => $this->userId,
                'source_shop_id' => $this->shopifyShopId,
                'target_shop_id' => $this->firstAmazonShopId
            ]
        ];
        $params[] = [
            '$group' => [
                '_id' => null,
                'total_products' => ['$sum' => ['$size' => '$items']],
            ]
        ];

        $options = $this->getMongoOptions();
        $dbResponse = $productContainerCollection->aggregate($params, $options)->toArray();
        return $dbResponse[0]['total_products'] ?? 0;
    }

    public function getOrderCreditStatus(): array|null
    {
        $paymentDetailsCollection = $this->getMongoCollection(self::PAYMENT_DETAILS_COLLECTION);
        $params = [
            'user_id' => $this->userId,
            'source_id' => $this->shopifyShopId,
            'type' => 'user_service',
            'service_type' => 'order_sync',
        ];

        $options = $this->getMongoOptions();
        return $paymentDetailsCollection->findOne($params, $options) ?? [];
    }

    public function getNotLinkedProductsCount(): int|string
    {
        $query = [
            'user_id' => $this->userId,
            'shop_id' => $this->firstAmazonShopId,
            'status' => ['$ne' => 'Deleted'],
            'matched' => ['$exists' => false]
        ];
        $amazonListingsCollection = $this->getMongoCollection(self::AMAZON_LISTING_COLLECTION);

        return $amazonListingsCollection->countDocuments($query);
    }

    /**
     * used for sharing count of uploaded products as they are same for this use-case
     *
     * @return integer|string
     */
    public function getLinkedProductsCount(): int|string
    {
        $query = [
            'user_id' => $this->userId,
            'shop_id' => $this->firstAmazonShopId,
            'matched' => true,
            'status' => ['$ne' => 'Deleted']
        ];

        $amazonListingsCollection = $this->getMongoCollection(self::AMAZON_LISTING_COLLECTION);
        return $amazonListingsCollection->countDocuments($query);
    }

    public function getListingErrorsProductsCount(): int|string
    {
        $query = [];
        $query[] = [
            '$match' => [
                'user_id' => $this->userId,
                'source_shop_id' => $this->shopifyShopId,
                'target_shop_id' => $this->firstAmazonShopId,
                'items.error.type' => ['$in' => ['upload', 'Listing']]
            ]
        ];
        $query[] = [
            '$project' => [
                'items.error.type' => 1
            ]
        ];
        $query[] = [
            '$unwind' => '$items'
        ];
        $query[] = [
            '$match' => [
                'items.error.type' => ['$in' => ['upload', 'Listing']]
            ]
        ];
        $query[] = [
            '$count' => 'total_products'
        ];

        $options = $this->getMongoOptions();
        $refineProductCollection = $this->getMongoCollection(self::REFINE_PRODUCT_COLLECTION);
        $dbResponse = $refineProductCollection->aggregate($query, $options)->toArray();
        return $dbResponse[0]['total_products'] ?? 0;
    }

    public function getMongoCollection(string $collection): MongoCollection
    {
        return $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollection($collection);
    }

    public function getMongoOptions(): array
    {
        return ["typeMap" => ['root' => 'array', 'document' => 'array']];
    }

    public function afterAccountConnection($event, $component, array $shops): void
    {
        if (!empty($shops['source_shop']) && empty($shops['target_shop'])) {
            $username = $shops['source_shop']['username'] ?? null;
            if ($username == null) {
                return;
            }
            $uninstallUserDetailsCollection = $this->getMongoCollection(self::UNINSTALL_USER_DETAILS);
            $query = [
                '$or' => [
                    ['username' => $username],
                    ['user_id' => $this->di->getUser()->id]
                ]
            ];
            $hasUninstallDoc = $uninstallUserDetailsCollection->countDocuments($query);
            if ($hasUninstallDoc > 0) {
                $finalData = [
                    'user_id' => $this->di->getUser()->id,
                    'username' => $username,
                    'shopJson' => $shops['source_shop']
                ];
                $zohoHelper = $this->di->getObjectManager()->get(\App\Zoho\Components\QueueProcess::class);
                $zohoHelper->handleReinstalledUser($finalData);
            }
        }
    }

    public function afterCreateSubscriptionForOrderSync($event, $component, array $data): void
    {
        //onboarding completed event for CRM
        $data['shopJson'] = json_encode($this->di->getUser()?->shops[0] ?? []);
        $configData =  $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
        $appTag = 'amazon_sales_channel';
        $configData->setGroupCode('product');
        $configData->setUserId($this->di->getUser()->id);
        $config = $configData->getConfig();
        $config_data = json_decode(json_encode($config), true);
        foreach ($config_data as $item) {
            // Check if the 'value' key exists and is not empty
            if (isset($item['key'], $item['value']) && !empty($item['value'])) {

                $acceptedKeys = ['contact_email', 'brand_registered', 'seller', 'sync_product_with', 'sync_status_with'];

                $key = $item['key'];

                if (!in_array($key, $acceptedKeys)) {
                    continue; // Skip if the key is not in the accepted list
                }

                $value = $item['value'];
                
                if (is_array($value) && $key == 'seller') {
                    // Extract ONLY IF the array has a key named 'value' AND that inner value is "truthy"
                    $extractedvalueData = [];
                    foreach ($value as $keyval => $valueitem) {
                        if (isset($valueitem['value']) && is_bool($valueitem['value'])) {
                            if ( !$valueitem['value'] ) continue; // Skip if the value is false
                            $extractedvalueData[$keyval] = $valueitem['value'];
                        } else {
                            $extractedvalueData[$keyval] = $valueitem;
                        }
                    }
                    $value = $extractedvalueData;
                }

                // If this key already exists, group the values into an array
                if (isset($extractedData[$key])) {
                    // If it's not already an array, put the existing value into an array
                    if (!is_array($extractedData[$key]) || !isset($extractedData[$key][0])) {
                        $extractedData[$key] = [$extractedData[$key]];
                    }
                    // Add the new value to the array
                    $extractedData[$key][] = $value;
                } else {
                    // Otherwise, just add the new key-value pair
                    $extractedData[$key] = $value;
                }
            }
        }
        if ( isset($data['data']) ) {
            $data['data']['config_data'] = $extractedData;
        } else {
            $data['config_data'] = $extractedData;
        }
        $this->di->getEventsManager()->fire('application:onboardingCompleted', $this, $data);
    }

    public function migrateCrmData(array $data)
    {
        if (!class_exists(\App\Zohocrm\Components\MigrationProcess8::class)) {
            return;
        }

        if (!method_exists(\App\Zohocrm\Components\MigrationProcess8::class, 'startMigration')) {
            return;
        }

        $this->userId = $data['user_id'] ?? $this->di->getUser()->id;
        $this->userShops = $this->di->getUser()?->shops ?? [];
        if (empty($this->userShops)) {
            return;
        }

        $this->shopifyShopId = $this->userShops[0]['_id'] ?? null;

        $this->firstAmazonShopId = $this->userShops[1]['_id'] ?? null;

        $this->hasMultipleAmazonShops = count($this->userShops) > 2;

        $totalOrders = $this->getTotalOrdersCount();

        $totalImportedProducts = $this->getImportedProductsCount();

        $userOrderService = $this->getOrderCreditStatus();
        $marketplaceOrderStatus = [
            'total_order_credits' => $userOrderService['prepaid']['service_credits'] ?? 0,
            'order_limiting_remaining' => $userOrderService['prepaid']['available_credits'] ?? 0,
            'total_used_credits' => $userOrderService['prepaid']['total_used_credits'] ?? 0,
            'monthly_orders' => $userOrderService['prepaid']['total_used_credits'] ?? 0,
        ];

        $notLinkedProductsCount = $this->getNotLinkedProductsCount();

        $linkedOrUploadedProductsCount = $this->getLinkedProductsCount();

        $listingErrorsProductsCount = $this->getListingErrorsProductsCount();

        $activePlan = $this->getActivePlan();

        $getLastPayment = $this->getLastPayment();

        $finalData = [
            'shopJson' => json_encode($this->userShops[0]),
            'paymentData' => [
                'amount' => $getLastPayment['marketplace_data']['price'] ?? 0,
                'plan_name' => $getLastPayment['marketplace_data']['name'] ?? $activePlan['plan_details']['title'] ?? null,
                'shopify_created_at' => $getLastPayment['marketplace_data']['created_at'] ?? null,
                'payment_type' => (isset($activePlan['plan_details']['custom_price']) && $activePlan['plan_details']['custom_price'] == 0)
                    ? null
                    : (isset($getLastPayment['marketplace_data']['billing_on'])
                        ? 'recurring'
                        : 'one time'),
                'charge_id' => $getLastPayment['marketplace_data']['id'] ?? null,
                'next_billing_on' => $this->getNextBillingOn($getLastPayment['marketplace_data']['billing_on'] ?? null),
            ],
            'purchaseStatus' => (isset($activePlan['plan_details']['custom_price']) && $activePlan['plan_details']['custom_price'] > 0) ? 'Purchased' : 'Trial',
            'installedAt' => $this->di->getUser()?->created_at?->toDateTime()?->format('c') ?? null,
            'currentOnBoardingStep' => $this->getOnboardingStep(),
            'sellerType' => $this->getSellerType(),
            'configStatus' => (isset($this->userShops[1]['apps'][0]['app_status']) && $this->userShops[1]['apps'][0]['app_status'] == 'active') ? 'Yes' : 'No',
            'isOnboardingCompleted' => isset($this->userShops[1]['register_for_order_sync']) ? 'Yes' : 'No',
            'interval' => $activePlan['plan_details']['billed_type'] ?? null,
            'appName' => 'amazon',
            'paymentDone' => (isset($activePlan['plan_details']['custom_price']) && $activePlan['plan_details']['custom_price'] > 0) ? 'Yes' : 'No',
            'marketplace_shop_id' => $this->userShops[1]['warehouses'][0]['seller_id'] ?? null,
            'marketplace_marketplace_id' => $this->userShops[1]['warehouses'][0]['marketplace_id'] ?? null,
            'marketplace_shop_name' => $this->userShops[1]['sellerName'] ?? null,
            'marketplace_status' => $this->getMarketplaceStatus(),
            'accountType' => ($this->hasMultipleAmazonShops) ? 'Multi' : 'Single',
            'installationStatus' => $this->userShops[0]['apps'][0]['app_status'] && $this->userShops[0]['apps'][0]['app_status'] == 'active' ? 'Installed' : 'Uninstalled',
            'marketplace_connection_date' => $this->userShops[1]['created_at']?->toDateTime()?->format('c') ?? null,
            'productImportedCount' => $totalImportedProducts,
            'productUploadFromApp' => $linkedOrUploadedProductsCount,
            'unlinkProductCount' =>  $notLinkedProductsCount,
            'getProductErrorCount' => $listingErrorsProductsCount,
            'orderExhausted' => ($marketplaceOrderStatus['order_limiting_remaining'] == 0) ? 'Yes' : 'No',
            'orderManagement' => $this->isOrderFetchingEnabled() ? 'Yes' : 'No',
            'fbaOrderManagement' => $this->isFbaOrderFetchingEnabled() ? 'Yes' : 'No',
            'monthlyOrders' => $marketplaceOrderStatus['monthly_orders'],
            'orderConsumed' => $marketplaceOrderStatus['total_used_credits'],
            'orderLimit' => $marketplaceOrderStatus['total_order_credits'],
            'totalOrders' => $totalOrders,
            'gmv' => $this->getUserTotalGmv(),
            'last_login_date' => isset($this->userShops[0]['last_login_at']) && strtotime($this->userShops[0]['last_login_at']) ? (new \DateTime($this->userShops[0]['last_login_at']))->format('c') : null,
            'orderLimitRemaining' => $marketplaceOrderStatus['order_limiting_remaining'],
            'method' => 'migrateSellerInstallationDetailsOnZoho',
        ];
        $zohoHelper = $this->di->getObjectManager()->get(\App\Zohocrm\Components\MigrationProcess8::class);
        $zohoHelper->startMigration($finalData);
    }

    public function getTotalOrdersCount(): int|string
    {
        $params = [
            'user_id' => $this->userId,
            'object_type' => 'source_order',
            'marketplace' => 'amazon',
            'marketplace_shop_id' => $this->firstAmazonShopId,
        ];

        $salesContainerCollection = $this->getMongoCollection(self::ORDER_CONTAINER_COLLECTION);
        $dbResponse = $salesContainerCollection->countDocuments($params);
        return $dbResponse ?? 0;
    }

    public function getOnboardingStep(): string|null
    {
        $params = [
            'user_id' => $this->userId,
            'source_shop_id' => $this->shopifyShopId,
            'target_shop_id' => $this->firstAmazonShopId,
            'group_code' => 'onboarding',
            'key' => 'stepsCompleted',
        ];
        $configCollection = $this->getMongoCollection('config');
        $dbResponse = $configCollection->findOne($params);
        return $dbResponse['value'] ?? null;
    }

    public function getSellerType(): string
    {
        $params = [
            'user_id' => $this->userId,
            'source_shop_id' => $this->shopifyShopId,
            'target_shop_id' => $this->firstAmazonShopId,
            'group_code' => 'onboarding',
            'key' => 'sellerStatus',
        ];
        $configCollection = $this->getMongoCollection('config');
        $dbResponse = $configCollection->findOne($params);
        return isset($dbResponse['value']) && $dbResponse['value'] == 'new_seller' ? 'New' : 'Existing';
    }

    public function getMarketplaceStatus()
    {
        $params = [
            'user_id' => $this->userId,
            'source_shop_id' => $this->shopifyShopId,
            'target_shop_id' => $this->firstAmazonShopId,
            'group_code' => 'feedStatus',
            'key' => 'accountStatus',
        ];
        $configCollection = $this->getMongoCollection('config');
        $dbResponse = $configCollection->findOne($params);
        return isset($dbResponse['value']) ? ucfirst($dbResponse['value']) : 'Active';
    }

    public function isOrderFetchingEnabled()
    {
        $params = [
            'user_id' => $this->userId,
            'source_shop_id' => $this->shopifyShopId,
            'target_shop_id' => $this->firstAmazonShopId,
            'group_code' => 'order',
            'key' => 'order_sync',
        ];

        $configCollection = $this->getMongoCollection('config');
        $dbResponse = $configCollection->findOne($params);
        return $dbResponse['value'] ?? true;
    }

    public function isFbaOrderFetchingEnabled()
    {
        $params = [
            'user_id' => $this->userId,
            'source_shop_id' => $this->shopifyShopId,
            'target_shop_id' => $this->firstAmazonShopId,
            'group_code' => 'order',
            'key' => 'fbo_order_sync',
        ];
        $configCollection = $this->getMongoCollection('config');
        $dbResponse = $configCollection->findOne($params);
        return $dbResponse['value'] ?? null;
    }

    public function getActivePlan()
    {
        $params = [
            'user_id' => $this->userId,
            'source_id' => $this->shopifyShopId,
            'type' => 'active_plan',
            'status' => 'active',
        ];
        $configCollection = $this->getMongoCollection(self::PAYMENT_DETAILS_COLLECTION);
        return $configCollection->findOne($params);
    }

    public function getLastPayment()
    {
        $params = [
            'user_id' => $this->userId,
            'source_id' => $this->shopifyShopId,
            'type' => 'payment',
            'status' => 'approved',
            'plan_status' => 'active',
        ];
        $configCollection = $this->getMongoCollection(self::PAYMENT_DETAILS_COLLECTION);
        return $configCollection->findOne($params);
    }

    public function getNextBillingOn($billingOn = null)
    {
        if (!$billingOn) {
            return null;
        }
        $currentDate = new \DateTime();
        $billingOn = new \DateTime($billingOn ?? null);

        // Get the billing date for the current month
        $billingThisMonth = (clone $billingOn)->setDate($currentDate->format('Y'), $currentDate->format('m'), $billingOn->format('d'));

        if ($billingThisMonth >= $currentDate) {
            // If the billing date this month is today or in the future
            $nextBillingOn = $billingThisMonth->format('Y-m-d');
        } else {
            // Otherwise, set to the same day next month
            $nextBillingOn = (clone $billingOn)->modify('first day of next month')->setDate(
                $currentDate->format('Y'), // Use the current year
                $currentDate->format('m') + 1, // Next month
                $billingOn->format('d') // Keep the same day
            )->format('Y-m-d');
        }

        return $nextBillingOn;
    }

    public function getUserTotalGmv()
    {
        $params = [];
        $params[] = [
            '$match' => [
                'user_id' => $this->userId,
            ]
        ];
        $params[] = [
            '$group' => [
                '_id' => '$user_id',
                'total_order_gmv' => ['$sum' => ['$toDouble' => '$order_amount']],
            ]
        ];
        $options = $this->getMongoOptions();

        $salesContainerCollection = $this->getMongoCollection(self::SALES_CONTAINER_COLLECTION);
        $dbResponse = $salesContainerCollection->aggregate($params, $options)->toArray();
        return $dbResponse[0]['total_order_gmv'] ?? 0;
    }
}
