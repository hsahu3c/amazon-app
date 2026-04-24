<?php

use App\Core\Models\User;
use MongoDB\BSON\ObjectId;
use App\Shopifyhome\Components\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[\AllowDynamicProperties]
class RemoveStaleUsersCommand extends Command
{
    protected static $defaultName = "remove-users:stale";

    protected static $defaultDescription = "Remove Users whose Shopify Store is inactive or token expired";

    public const REMOVE_BEFORE = '-6 months';

    public const LIMIT = 300;

    public const ACTIONS = [
        'closed-store',
        'invalid-token',
        'uninstall-stale-users',
        'check-all',
        'remove-no-shopify-users',
        'move-duplicate-simple-products',
        'verify-duplicate-simple-products',
        'remove-users-with-no-amazon-and-invalid-token',
        'remove-all-error-users-with-no-amazon-shops',
        'commence-migrate-inventory-schema'
    ];

    public const ACTION_METHOD_MAPPING = [
        'closed-store' => 'getAndUpdateClosedStoreUsers',
        'invalid-token' => 'getAndUpdateInvalidTokenUsers',
        'uninstall-stale-users' => 'pushToUninstallQueue',
        'check-all' => 'checkAllUsers',
        'remove-no-shopify-users' => 'updateUsersWithNoShopifyShop',
        'move-duplicate-simple-products' => 'moveDuplicateSimpleProducts',
        'verify-duplicate-simple-products' => 'verifyDuplicateSimpleProducts',
        'remove-users-with-no-amazon-and-invalid-token' => 'removeUsersWithNoAmazonAndInvalidToken',
        'remove-all-error-users-with-no-amazon-shops' => 'removeAllErrorUsersWithNoAmazonShops',
        'commence-migrate-inventory-schema' => 'commenceMigrateInventoryConfig'
    ];

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
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Remove Users whose Shopify Store is inactive or token expired');

        $this->addOption(
            "action",
            "a",
            InputOption::VALUE_OPTIONAL,
            "action to process"
        );
        $this->addOption(
            "page",
            "p",
            InputOption::VALUE_OPTIONAL,
            "page to process"
        );
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Initiating Process...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $action = $input->getOption("action") ?? false;
        if (!$action) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No action provided</>');
            return 0;
        }

        if (!in_array($action, self::ACTIONS)) {
            $output->writeln("➤ </><options=bold;fg=red>Provided action not found</>");
            return 0;
        }

        if (
            !array_key_exists($action, self::ACTION_METHOD_MAPPING)
            || !method_exists($this, self::ACTION_METHOD_MAPPING[$action])
        ) {
            $output->writeln("➤ </><options=bold;fg=red>Required function/method not found in mappings</>");
            return 0;
        }

        $method = self::ACTION_METHOD_MAPPING[$action];
        $output->writeln("➤ </><options=bold;fg=green>Executing {$method}</>");
        $this->$method($input, $output);
        //prepare the list of users found here
        //then find user_ids not in found array
        //api call to get shop
        //if Invalid API key or access token (unrecognized login or wrong password)
        //add those users to the list as well
        $output->writeln("➤ </><options=bold;fg=green>Process Completed</>");
        return 0;
    }

    public function getAndUpdateClosedStoreUsers(): void
    {
        $usersData = $this->getClosedStoreUsers();
        $this->updateClosedStoreUsers($usersData);
    }

    public function getClosedStoreUsers()
    {
        $dataBefore = date('Y-m-d\TH:i:sP', strtotime(self::REMOVE_BEFORE));
        $userDetailCollection = $this->getCollection('user_details');
        $options = $this->getOptions();
        return $userDetailCollection->find(
            [
                'shops' => [
                    '$elemMatch' => [
                        'marketplace' => 'shopify',
                        'plan_name' => 'cancelled',
                        'apps.app_status' => 'active',
                        'store_closed_at' => [
                            '$lte' => $dataBefore
                        ]
                    ]
                ]
            ],
            $options,
            ['projection' => ["_id" => false, "shops.$" => true, 'user_id' => true, 'username' => true], $options]
        )->toArray();
    }

    public function updateClosedStoreUsers($userData): void
    {
        $userIds = array_column($userData, 'user_id');
        $usersToRemoveCollection = $this->getCollection('users_to_remove');
        // $data = [
        //     'type' => 'store_closed',
        //     'users_to_remove' => $userIds
        // ];
        $query = ['type' => 'store_closed'];
        $dataToUpdate = [
            '$push' => ['user_ids' => $userIds]
        ];
        $usersToRemoveCollection->updateOne($query, $dataToUpdate, ['upsert' => true]);
    }

    public function getAndUpdateInvalidTokenUsers(): void
    {
        $usersToRemoveCollection = $this->getCollection('users_to_remove');
        $storeClosedUsers = $usersToRemoveCollection->findOne(['type' => 'store_closed'], $this->getOptions());
        $lastTraversedId = $this->getLastTraversedObjectId();
        $userDetailCollection = $this->getCollection('user_details');
        $closedStoreUserIds = $storeClosedUsers['user_ids'] ?? [];
        $query = [
            'user_id' => ['$nin' => $closedStoreUserIds],
            'username' => ['$nin' => ['app', 'admin']],
            'shops' => ['$exists' => true],
            'shops.marketplace' => 'shopify'
        ];
        if (!is_null($lastTraversedId)) {
            $query['_id'] = ['$gt' => $lastTraversedId];
        }

        $options = $this->getOptions();
        $options['limit'] = self::LIMIT;
        $users = $userDetailCollection->find($query, $options)->toArray();
        $invalidUsers = [];
        foreach ($users as $user) {
            $hasInvalidToken = $this->hasInvalidToken($user);
            if ($hasInvalidToken) {
                $invalidUsers[] = $user['user_id'];
            }
        }

        $query = ['type' => 'invalid_token'];
        $dataToUpdate = [
            '$push' => ['user_ids' => $invalidUsers]
        ];
        $usersToRemoveCollection->updateOne($query, $dataToUpdate, ['upsert' => true]);
        $lastTraversedUser = end($users);
        $lastTraversedId = $lastTraversedUser['_id'];
        $this->updateLastTraversedObjectId($lastTraversedId);
    }

    public function hasInvalidToken($user)
    {
        $shopifyShop = $this->getShopifyShop($user['shops'] ?? []);
        if (empty($shopifyShop)) {
            return false;
        }

        $remoteShopId = $shopifyShop['remote_shop_id'];
        // $appCode = $shopifyShop['apps'][0]['code'] ?? 'default';
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', 'true')
            ->call('/shop', [], ['shop_id' => $remoteShopId], 'GET');
        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
            return false;
        }

        $errorMessage = $remoteResponse['message'] ?? $remoteResponse['msg'];
        $errorMessage = $errorMessage['errors'] ?? $errorMessage['error'];
        if (str_contains((string) $errorMessage, 'Invalid API key or access token')) {
            return true;
        }

        //log response
        return false;
    }

    public function getShopifyShop($shops)
    {
        $shopifyShop = [];
        foreach ($shops as $shop) {
            if ($shop['marketplace'] == 'shopify') {
                $shopifyShop = $shop;
                break;
            }
        }

        return $shopifyShop;
    }

    public function getLastTraversedObjectId()
    {
        $usersToRemoveCollection = $this->getCollection('users_to_remove');
        $lastTraversedId = $usersToRemoveCollection->findOne(['type' => 'last_traversed_id'], $this->getOptions());
        return $lastTraversedId['last_id'] ?? null;
    }

    public function updateLastTraversedObjectId($lastTraversedId): void
    {
        $usersToRemoveCollection = $this->getCollection('users_to_remove');
        $query = ['type' => 'last_traversed_id'];
        $update = ['$set' => ['last_id' => $lastTraversedId]];
        $usersToRemoveCollection->updateOne($query, $update, ['upsert' => true]);
    }

    public function getCollection($collection)
    {
        return $this->di->getObjectManager()
            ->create('\App\Core\Models\BaseMongo')
            ->getCollectionForTable($collection);
    }

    public function getOptions()
    {
        return ["typeMap" => ['root' => 'array', 'document' => 'array']];
    }

    public function uninstallStaleUsers(): void
    {
        $usersToRemoveCollection = $this->getCollection('users_to_remove');

        $query = [
            'type' => ['$in' => ['store_closed', 'invalid_token']]
        ];
        $usersToRemove = $usersToRemoveCollection->find($query, $this->getOptions())->toArray();
        $userIds = [];
        foreach ($usersToRemove as $data) {
            $userIds = $userIds + $data['user_ids'] ?? [];
        }
    }

    public function pushToUninstallQueue($userIds)
    {
        if (empty($userIds)) {
            return false;
        }

        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $count = 0;
        $errorUsersArray = [];
        foreach ($userIds as $userId) {
            $response = $this->setDiForUser($userId);
            if (!$response['success']) {
                echo ' - error in setting di for user_id - ' . $userId . PHP_EOL;
                $errorUsersArray[$userId] = $response['message'];
                continue;
            }

            $allShops = $this->di->getUser()->shops;
            $shopData = $this->getShopifyShop($allShops);
            if (empty($shopData)) {
                echo ' - shopify shop data not found for user_id - ' . $userId . PHP_EOL;
                $errorUsersArray[$userId] = 'shopify shop data not found';
                continue;
            }

            $handlerData = [
                'shop' => $shopData['username'] ?? $shopData['myshopify_domain'] ?? '',
                'data' => $shopData,
                'type' => 'full_class',
                'class_name' => '\App\Connector\Models\SourceModel',
                'method' => 'triggerWebhooks',
                'user_id' => $userId,
                'action' => 'app_delete',
                'queue_name' => 'app_delete_stale_users',
                'shop_id' => $shopData['_id'],
                'marketplace' => $shopData['marketplace'],
                'app_code' => 'amazon_sales_channel'
            ];
            $sqsHelper->pushMessage($handlerData);
            $shopUrl = $shopData['username'] ?? $shopData['myshopify_domain'] ?? '';
            $this->updateAccessTokenOnHome($shopUrl, $userId);
            $count++;
        }

        echo 'users pushed in queue - ' . $count . PHP_EOL;
        return ['success' => true, 'error_users' => $errorUsersArray];
    }

    public function checkAllUsers(): void
    {
        $usersTokenCheckColl = $this->getCollection('users_token_check');
        $lastTraversedId = $usersTokenCheckColl->findOne(['type' => 'last_traversed_id'], $this->getOptions());
        $id = $lastTraversedId['last_id'] ?? null;

        $userDetailCollection = $this->getCollection('user_details');
        $query = [
            'username' => ['$nin' => ['app', 'admin']],
            'shops' => ['$exists' => true],
            'shops.marketplace' => 'shopify'
        ];
        if (!is_null($id)) {
            $query['_id'] = ['$gt' => $id];
        }

        $options = $this->getOptions();
        $options['limit'] = self::LIMIT;
        $users = $userDetailCollection->find($query, $options)->toArray();
        if (empty($users)) {
            echo "no users found to process. last_traversed_id - " . $id . PHP_EOL;
            return;
        }

        echo "staring process for " . count($users) . " users" . PHP_EOL;
        foreach ($users as $user) {
            $response = $this->fetchProdCount($user);
            echo "\e[38;5;10m☲\e[0m";
            unset(
                $response['time_taken'],
                $response['ip'],
                $response['execution_time'],
                $response['end_time'],
                $response['start_time'],
                $response['sales_channel']
            );
            $insertData = [];
            $insertData['user_id'] = $user['user_id'];
            $insertData['type'] = 'api_response';
            $insertData['remote_response'] = $response;
            $usersTokenCheckColl->insertOne($insertData);
        }

        $lastTraversedUser = end($users);
        $lastTraversedId = $lastTraversedUser['_id'];
        $query = ['type' => 'last_traversed_id'];
        $update = ['$set' => ['last_id' => $lastTraversedId]];
        $usersTokenCheckColl->updateOne($query, $update, ['upsert' => true]);
        echo PHP_EOL;
    }

    public function removeUsersWithNoAmazonAndInvalidToken(): void
    {
        $usersTokenCheckColl = $this->getCollection('users_token_check');
        $allInvalidUsers = [
            'has_amazon_shop' => ['$exists' => false],
            'remote_response.success' => false,
            'remote_response.msg.errors' => '[API] Invalid API key or access token (unrecognized login or wrong password)'
        ];
        $userIds = $usersTokenCheckColl->distinct('user_id', $allInvalidUsers);
        echo 'total users with no amazon and invalid token - ' . count($userIds) . PHP_EOL;
        $this->pushToUninstallQueue($userIds);
        echo 'all users pushed to queue';
    }

    public function fetchProdCount($user)
    {
        $shopifyShop = $this->getShopifyShop($user['shops'] ?? []);
        if (empty($shopifyShop)) {
            return false;
        }

        $remoteShopId = $shopifyShop['remote_shop_id'];
        // $appCode = $shopifyShop['apps'][0]['code'] ?? 'default';
        return $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', 'true')
            ->call('product/count', [], ['shop_id' => $remoteShopId], 'GET');
    }

    public function updateUsersWithNoShopifyShop(): void
    {
        $usersWithMissingShopifyShop = $this->getUsersWithNoShopifyShop();
        $connectorHook = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');

        echo "staring process for " . count($usersWithMissingShopifyShop) . " users" . PHP_EOL;

        foreach ($usersWithMissingShopifyShop as $user) {
            echo "Executing process for - " . $user['user_id'] . PHP_EOL;

            $preparedData = [
                'disconnected' => [
                    'target' => 1
                ],
                'target' => [
                    'marketplace' => $user['shops'][0]['marketplace'],
                    'shopId' => $user['shops'][0]['shop_id'],
                ],
                'deletion_in_progress' => false
            ];
            $connectorHook->TemporarlyUninstall($preparedData);
        }
    }

    public function getUsersWithNoShopifyShop()
    {
        $query = [
            'user_id' => ['$exists' => true],
            'shops' => ['$exists' => true],
            'shops.marketplace' => ['$ne' => 'shopify']
        ];
        $userDetailCollection = $this->getCollection('user_details');
        $usersWithMissingShopifyShop = $userDetailCollection->find($query)->toArray();
        return $usersWithMissingShopifyShop;
    }

    public function moveDuplicateSimpleProducts(): void
    {
        $query = [
            [
                '$match' =>
                [
                    'user_id' => ['$exists' => true],
                    'shop_id' => ['$exists' => true],
                    'container_id' => ['$exists' => true],
                    'visibility' => 'Catalog and Search',
                    'type' => 'simple'
                ]
            ],
            [
                '$group' =>
                [
                    '_id' => '$container_id',
                    'user_id' => ['$addToSet' => '$user_id'],
                    'shop_id' => ['$addToSet' => '$shop_id'],
                    'source_product_id' => ['$addToSet' => '$source_product_id'],
                    'count' => ['$sum' => 1]
                ]
            ],
            [
                '$match' =>
                [
                    'count' => ['$gt' => 1]
                ]
            ],
            [
                '$out' => 'duplicate_simple_products_errors'
            ]
        ];
        $productContainer = $this->getCollection('product_container');
        $result = $productContainer->aggregate($query, ['allowDiskUse' => true])->toArray();
    }

    public function verifyDuplicateSimpleProducts(): void
    {
        $duplicateSimpleProd = $this->getCollection('duplicate_simple_products_errors');
        $options = $this->getOptions();
        $limit = 10;
        // $page = $data['page'] ?? 0;
        $lastIdDoc = $duplicateSimpleProd->findOne(['type' => 'pagination']);
        $id = $lastIdDoc['last_id'] ?? null;
        $options['limit'] = $limit;
        // $options['skip'] = $limit  * $page;
        $query = [
            'type' => ['$ne' => 'pagination']
        ];
        if (!is_null($id)) {
            $query['_id'] = ['$gt' => $id];
        }

        $products = $duplicateSimpleProd->find($query, $options)->toArray();

        foreach ($products as $product) {
            $res = $this->setDiForUser($product['user_id']);
            if (!$res['success']) {
                $bulkOpQuery[] = [
                    'updateOne' => [
                        ['_id' => $product['_id']],
                        [
                            '$set' => ['error' => 'User not found'],
                        ],
                    ]
                ];
                continue;
            }

            $shopifyProduct = $this->getShopifyProduct($product['_id']);
            if (empty($shopifyProduct)) {
                $bulkOpQuery[] = [
                    'updateOne' => [
                        ['_id' => $product['_id']],
                        [
                            '$set' => ['error' => 'Product not found on Shopify'],
                        ],
                    ]
                ];
                continue;
            }

            $this->validateVariants($shopifyProduct, $product);
            foreach ($product['source_product_id'] as $id) {
                $bulkOpQuery[] = [
                    'updateOne' => [
                        ['_id' => $product['_id']],
                        [
                            '$set' => ['source_product_id.$[id].variant_exists' => false],
                        ],
                        [
                            'arrayFilters' =>
                            [
                                [
                                    'id' => $id,
                                ]
                            ]
                        ]
                    ]
                ];
            }
        }

        $duplicateSimpleProd->BulkWrite($bulkOpQuery, ['w' => 1]);
        $lastProduct = end($products);
        $lastId = $lastProduct['_id'] ?? null;
        if (!empty($lastId)) {
            $duplicateSimpleProd->updateOne(
                ['type' => 'pagination'],
                ['$set' => ['last_id' => $lastId]],
                ['upsert' => true]
            );
        }
    }

    public function getShopifyProduct($container_id)
    {
        $user = $this->di->getUser();
        $shopifyShop = $this->getShopifyShop($user['shops'] ?? []);
        if (empty($shopifyShop)) {
            return false;
        }

        $remoteShopId = $shopifyShop['remote_shop_id'];
        return $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', 'true')
            ->call('product/get', [], [
                'shop_id' => $remoteShopId,
                'is_saleschannel' => true,
                'id' => $container_id
            ], 'GET');
    }

    public function getProductLocation($variantsInventoryItemId, $locationIds)
    {
        $user = $this->di->getUser();
        $shopifyShop = $this->getShopifyShop($user['shops'] ?? []);
        if (empty($shopifyShop)) {
            return false;
        }

        $remoteShopId = $shopifyShop['remote_shop_id'];
        return $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', 'true')
            ->call('inventory', [], [
                'shop_id' => $remoteShopId,
                'sales_channel' => 0,
                'inventory_item_ids' => $variantsInventoryItemId,
                'location_ids' => $locationIds
            ], 'GET');
    }

    public function validateVariants(&$shopifyProduct, $dbData): void
    {
        $variants = $shopifyProduct['variants'];
        foreach ($variants as $variant) {
            foreach ($dbData['source_product_id'] as $index => $source_product_id) {
                if ($variant['id'] == $source_product_id) {
                    unset($dbData['source_product_id'][$index]);
                }
            }
        }
    }

    public function removeTargetsForUsersWithNoAmazonShop(): void
    {
        $query = [
            'user_id' => ['$exists' => true],
            'shops' => ['$exists' => true],
            'username' => ['$nin' => ['admin', 'app']],
            'shops.marketplace' => ['$ne' => 'amazon'],
            'shops.targets' => ['$exists' => true, '$ne' => []],
            'email' => ['$not' => ['$regex' => "cedcommerce"]],
        ];
        $update = [
            '$set' => [
                'shops.$.targets' => []
            ]
        ];
        $userDetailCollection = $this->getCollection('user_details');
        $response = $userDetailCollection->updateMany($query, $update)->toArray();
    }

    public function addProductsInRefineContainer($input, $output): void
    {
        $userId = $input->getOption('user_id') ?? false;
        $shopifyShopId = $input->getOption('shopify_shop_id') ?? false;
        if (!$userId || !$shopifyShopId) {
            echo 'user_id and shopify_shop_id are required';
            return;
        }

        $res = $this->setDiForUser($userId);
        if (!$res['success']) {
            // echo $res['message'];
            return;
        }

        $params = [
            'user_id' => $userId,
            'source' => [
                'marketplace' => 'shopify',
                'shopId' => $shopifyShopId
            ]
        ];
        $productMarketplace = $this->di->getObjectManager('\App\Connector\Models\Product\Marketplace');
        $productMarketplace->syncRefineProducts($params);
    }

    public function removeProductsFromRefine(): void
    {
        $userId = $input->getOption('user_id') ?? false;
        $shopifyShopId = $input->getOption('shopify_shop_id') ?? false;
        if (!$userId || !$shopifyShopId) {
            echo 'user_id and shopify_shop_id are required';
            return;
        }

        $res = $this->setDiForUser($userId);
        if (!$res['success']) {
            // echo $res['message'];
            return;
        }

        $params = [
            'user_id' => $userId,
            'shop_id' => $shopifyShopId,
            'container_id' => ['$exists' => true],
            'visibility' => 'Not Visible Individually'
        ];
        $productContainer = $this->getCollection('product_container');
        $refineContainer = $this->getCollection('refine_product');

        $sourceProductIds = $productContainer->distinct('source_product_id', $params);

        $refineRemoveItemsQuery = [
            'user_id' => $userId,
            'source_shop_id' => $shopifyShopId,
            'target_shop_id' => ['$exists' => true],
            'source_product' => ['$exists' => true],
            'items' =>
            [
                '$elemMatch' =>
                [
                    'source_product_id' =>
                    [
                        '$nin' => $sourceProductIds
                    ]
                ]
            ]
        ];
        $refineRemoveItemsUpdateQuery = [
            '$pull' =>
            [
                'items' =>
                [
                    'source_product_id' =>
                    [
                        '$nin' => $sourceProductIds
                    ]
                ]
            ]
        ];
        $refineContainer->updateMany($refineRemoveItemsQuery, $refineRemoveItemsUpdateQuery);

        $wrapperDocQuery = [
            'user_id' => $userId,
            'source_shop_id' => $shopifyShopId,
            'items' => [
                '$size' => 0
            ]
        ];
        $refineContainer->deleteMany($wrapperDocQuery);
    }

    public function setDiForUser($userId)
    {
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
                'message' => 'User not found.'
            ];
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin.'
            ];
        }

        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }

    public function updateLocationsAndInv(): void
    {
        $query = [
            [
                '$match' => [
                    'user_id' => ['$exists' => true],
                    'shop_id' => ['$exists' => true],
                    'container_id' => ['$exists' => true],
                    'visibility' => ['$exists' => true],
                    'locations' => null
                ]
            ],
            [
                '$limit' => 100,
            ],
            [
                '$group' => [
                    '_id' => '$user_id',
                    'shop_id' => ['$addToSet' => '$shop_id'],
                    'container_id' => ['$addToSet' => '$container_id'],
                ]
            ]
        ];
        $productContainer = $this->getCollection('product_container');
        $userWiseProductsData = $productContainer->aggregate($query)->toArray();
        foreach ($userWiseProductsData as $userProductData) {
            $userId = $userProductData['_id'];
            $response = $this->setDiForUser($userId);
            if (!$response['success']) {
                continue;
            }

            $response = $this->getShopifyProduct($userProductData['container_id']);
            if (empty($response)) {
                continue;
            }

            $shopifyShop = $this->getShopifyShop($this->di->getUser()->shops);
            $locationIds =  array_column($shopifyShop['warehouses'], 'id');
            $allProducts = $response['data'] ?? $response;
            $bulkOpArray = [];
            foreach ($allProducts as $product) {
                $variants = $product['variants'] ?? [];
                $inventoryItemIds = array_column($variants, 'id');
                $locationResponse = $this->getProductLocation($inventoryItemIds, $locationIds);
                if (
                    isset($locationResponse['success'], $locationResponse['data']['inventory_levels'])
                    && $locationResponse['success']
                ) {
                    $inventoryLevels = $this->typeCastElement($locationResponse['data']['inventory_levels']);
                    foreach ($inventoryLevels as $inventoryLevel) {
                        foreach ($variants as $variant) {
                            if ($variant['inventory_item_id'] == $inventoryLevel['inventory_item_id']) {
                                $bulkOpArray[] = [
                                    'updateOne' => [
                                        [
                                            'user_id' => $userId,
                                            'shop_id' => ['$in' => $userProductData['shop_id']]
                                        ],
                                        [
                                            '$set' => [
                                                'locations' => $inventoryLevel
                                            ]
                                        ]
                                    ]
                                    ];
                            }
                        }
                    }
                }
            }

            $productContainer->bulkWrite($bulkOpArray, ['w' => 1]);
        }
    }

    public function mapInventoryIdWithVariantIndex($variants)
    {
        $inventoryMapping = [];
        foreach ($variants as $key => $value) {
            $inventoryMapping[$value['inventory_item_id']] = $key;
        }

        return $inventoryMapping;
    }

    public function typeCastElement($shopRawData)
    {
        if(!isset($shopRawData[0])) {
            $shopRawData['inventory_item_id'] = (string)$shopRawData['inventory_item_id'];
            $shopRawData['location_id'] = (string)$shopRawData['location_id'];
        } else {
            $count = 0;
            foreach($shopRawData as $raw){
                $shopRawData[$count]['inventory_item_id'] = (string)$raw['inventory_item_id'];
                $shopRawData[$count]['location_id'] = (string)$raw['location_id'];
                $count++;
            }
        }

        return $shopRawData;
    }

    public function updateAllShopsToAddMarketplaceSubscription(): void
    {
        $query = [
            'user_id' => ['$exists' => true],
            'shops' => ['$exists' => true],
            'shops' => [
                '$elemMatch' =>
                [
                    'marketplace' => 'amazon',
                    // 'apps.webhooks.marketplace_subscription_id' => ['$exists' => true, '$ne' => null]
                    'apps.webhooks.marketplace_subscription_id' => null
                ]
            ]
        ];
        $userDetailCollection = $this->getCollection('user_details');
        $options = $this->getOptions();
        $options['limit'] = 50;
        $users = $userDetailCollection->find($query, $options)->toArray();
        foreach ($users as $user) {
            $subscriptionData = $this->getSubscriptions($user);
            $this->addMarketplaceSubscriptionId($user, $subscriptionData);
        }
    }

    public function getSubscriptions($userData)
    {
        $shops = $userData['shops'] ?? [];
        $subscriptionData = [];
        foreach ($shops as $shop) {
            if ($shop['marketplace'] != 'amazon' || empty($shop['remote_shop_id'])) {
                continue;
            }

            $getSubscriptionResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init('cedcommerce', false, 'cedcommerce')
                ->call(
                    'event/subscription',
                    [],
                    [
                        'shop_id' => $shop['remote_shop_id'],
                        'app_code' => "cedcommerce"
                    ],
                    'GET'
                );
            if ($getSubscriptionResponse['success'] && !empty($getSubscriptionResponse['data'])) {
                $subscriptionData[$shop['remote_shop_id']] = $getSubscriptionResponse['data'];
            }
        }

        return $subscriptionData;
    }

    public function addMarketplaceSubscriptionId($userData, $subscriptionData): void
    {
        if (empty($subscriptionData)) {
            return;
        }

        $bulkWrites = [];

        foreach ($subscriptionData as $remoteShopId => $webhooks) {
            $filter = [
                '_id' => new ObjectId($userData['user_id']),
                'shops' => [
                    '$elemMatch' => [
                        '_id' => $remoteShopId,
                        'marketplace' => 'amazon',
                        'apps.webhooks.code' => ['$in' => array_column($webhooks, 'event_code')],
                    ],
                ],
            ];

            foreach ($webhooks as $webhook) {
                $update = [
                    '$set' => [
                        'shops.$.apps.0.webhooks.$[hook].marketplace_subscription_id' => $webhook['subscription_id'],
                    ],
                ];

                $options = [
                    'arrayFilters' => [
                        ['hook.code' => $webhook['event_code']],
                    ],
                ];

                $bulkWrites[] = [
                    'updateOne' => [
                        [
                            'filter' => $filter,
                            'update' => $update,
                            'upsert' => false,
                            'arrayFilters' => $options['arrayFilters']
                        ],
                    ],
                ];
            }
        }

        if (!empty($bulkWrites)) {
            $userDetailCollection = $this->getCollection('user_details');
            $userDetailCollection->bulkWrite($bulkWrites);
        }
    }

    public function commenceMigrateInventoryConfig($input, $output)
    {
        $query = [
            'user_id' => ['$exists' => true],
            'shops' => ['$exists' => true],
            'shops.marketplace' => 'amazon'
        ];
        $page = $input->getOption('page');
        if (empty($page)) {
            $page = 1;
        }

        echo 'Processing page: ' . $page . PHP_EOL;
        $userDetailCollection = $this->getCollection('user_details');
        $options = $this->getOptions();
        $options['limit'] = 1000;
        $options['sort'] = ['_id' => 1];
        $options['skip'] = ($page - 1) * $options['limit'];
        $users = $userDetailCollection->find($query, $options)->toArray();

        if (empty($users)) {
            echo 'No users found';
            return;
        }

        //push in SQS
        $this->pushToInventorySchemaMigrateQueue($users);

        // $userIds = array_column($users, 'user_id');
        // $profileCollection = $this->getCollection('profile');
        // $profileQuery = [
        //     'user_id' => ['$nin' => $userIds],
        //     'data' => [
        //         '$elemMatch' => [
        //             'data_type' => 'inventory_settings',
        //             'data.settings_enabled' => ['$exists' => true]
        //         ]
        //     ]
        // ];
        // $notFoundUserIds = $profileCollection->distinct('user_id', $profileQuery);
        // foreach ($notFoundUserIds as $userId) {
        //     $this->migrateTemplateInventorySchema($userId, []);
        // }
        return 0;
    }

    public function migrateInventoryConfig($data): void
    {
        $configCollection = $this->getCollection('config');

        $configQuery = [
            'user_id' => $data['user_id'],
            'app_tag' => 'amazon_sales_channel',
            'group_code' => 'inventory'
        ];
        $options = $this->getOptions();
        $oldSettings = $configCollection->find($configQuery, $options)->toArray();
        if (empty($oldSettings)) {
            return;
        }

        $mappedSettings = $this->mappedSettings($oldSettings);

        $newSettings = [];

        $effectiveSettings = [];

        foreach ($oldSettings as $oldSetting) {
            // Common fields for both old and new settings
            $newSetting = [
                "_id" => $oldSetting["_id"],
                "group_code" => $oldSetting["group_code"],
                "app_tag" => $oldSetting["app_tag"],
                "target" => $oldSetting["target"],
                "target_shop_id" => $oldSetting["target_shop_id"],
                "source" => $oldSetting["source"],
                "source_shop_id" => $oldSetting["source_shop_id"],
                "user_id" => $oldSetting["user_id"],
                "created_at" => $oldSetting["created_at"],
            ];
            if (isset($oldSetting["updated_at"])) {
                $newSetting["updated_at"] = $oldSetting["updated_at"];
            }

            switch ($oldSetting["key"]) {
                case "settings_enabled":
                    $newSetting["key"] = "inventory_settings";
                    $newSetting["value"] = $oldSetting["value"];
                    break;
                case "enable_max_inventory_level":
                    $newSetting["key"] = "continue_selling";
                    $newSetting["value"] = [
                        'enabled' => $oldSetting["value"],
                        'value' => $mappedSettings['max_inventory_level'][$oldSetting["target_shop_id"]]['value']
                    ];
                    break;
                case "warehouses_settings":
                    $newSetting["key"] = "warehouses";
                    $newSetting["value"] = [
                        'enabled' => $mappedSettings['settings_selected'][$oldSetting["target_shop_id"]]['value']['warehouses_settings'],
                        'value' => $oldSetting["value"]
                    ];
                    break;
                case "customize_inventory":
                    $effectiveSettings[$oldSetting["target_shop_id"]]['reserved'] = $newSetting + [
                        "key" => "effective_inventory",
                        "value" => [
                            'type' => "reserved",
                            'enabled' => $mappedSettings['settings_selected'][$oldSetting["target_shop_id"]]['value']['customize_inventory'],
                            'value' => $oldSetting["value"]['value']
                        ],
                    ];
                    continue 2;
                case "fixed_inventory":
                    $effectiveSettings[$oldSetting["target_shop_id"]]['fixed'] = $newSetting + [
                        "key" => "effective_inventory",
                        "value" => [
                            'type' => "fixed",
                            'enabled' => $mappedSettings['settings_selected'][$oldSetting["target_shop_id"]]['value']['fixed_inventory'],
                            'value' => $oldSetting["value"]
                        ]
                    ];
                    continue 2;
                case "threshold_inventory":
                    $effectiveSettings[$oldSetting["target_shop_id"]]['threshold'] = $newSetting + [
                        "key" => "effective_inventory",
                        "value" => [
                            'type' => "threshold",
                            'enabled' => $mappedSettings['settings_selected'][$oldSetting["target_shop_id"]]['value']['threshold_inventory'],
                            'value' => $oldSetting["value"]
                        ]
                    ];
                    continue 2;
                case "settings_selected":
                    if (isset($oldSetting['value']['delete_out_of_stock']) || isset($oldSetting['value']['inactive_product_setting'])) {
                        $newSetting["key"] = "inactivate_product";
                        $newSetting["value"] = $oldSetting['value']['inactive_product_setting'] ?? $oldSetting['value']['delete_out_of_stock'];
                        break;
                    }

                    continue 2;
                case "customize_inventory":
                case "enable_max_inventory_level":
                case "fixed_inventory":
                case "threshold_inventory":
                case "max_inventory_level":
                case "reserved_inventory":
                    continue 2;
                default:
                    $newSetting["key"] = $oldSetting["key"];
                    $newSetting["value"] = $oldSetting["value"];
                    break;
            }

            $newSettings[] = $newSetting;
        }

        foreach ($effectiveSettings as $effectiveSetting) {
            $enabledEffectiveSetting = null;
            if ($effectiveSetting['fixed']['value']['enabled']) {
                $enabledEffectiveSetting = 'fixed';
            } elseif ($effectiveSetting['reserved']['value']['enabled']) {
                $enabledEffectiveSetting = 'reserved';
            } elseif ($effectiveSetting['threshold']['value']['enabled']) {
                $enabledEffectiveSetting = 'threshold';
            }

            if (!is_null($enabledEffectiveSetting)) {
                $effectiveSetting = $effectiveSetting[$enabledEffectiveSetting];
            } else {
                $effectiveSetting = $effectiveSetting['fixed']
                ?? $effectiveSetting['reserved']
                ?? $effectiveSetting['threshold']
                ?? null;
            }

            if (!is_null($effectiveSetting)) {
                $newSettings[] = $effectiveSetting;
            }
        }

        $configCollection->deleteMany($configQuery);
        $configCollection->insertMany($newSettings);

        $this->migrateTemplateInventorySchema($data['user_id'], $newSettings);
    }

    public function mappedSettings($settings)
    {
        $mappedSettings = [];
        foreach ($settings as $oldSetting) {
            $mappedSettings[$oldSetting["key"]][$oldSetting["target_shop_id"]] = $oldSetting;
        }

        return $mappedSettings;
    }

    public function pushToInventorySchemaMigrateQueue($users)
    {
        if (empty($users)) {
            return false;
        }

        echo 'Initiating inventory schema message queue for users - ' . count($users) . PHP_EOL;
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        foreach ($users as $user) {
            $response = $this->setDiForUser($user['user_id']);
            if (!$response['success']) {
                echo 'Failed to set di for user - ' . $user['user_id'] . PHP_EOL;
                continue;
            }

            $handlerData = [
                'type' => 'full_class',
                'class_name' => Helper::class,
                'method' => 'migrateInventoryConfig',
                'user_id' => $user['user_id'],
                'queue_name' => 'migrate_inventory_schema',
            ];
            try {
                $sqsHelper->pushMessage($handlerData);
            } catch (Exception) {
                echo 'Failed to push message for user - ' . $user['user_id'] . PHP_EOL;
                continue;
            }

            echo 'Pushed message for user - ' . $user['user_id'] . PHP_EOL;
        }
    }

    public function migrateTemplateInventorySchema($userId, $updatedSettings): void
    {
        $profileCollection = $this->getCollection('profile');
        $query = [
            'user_id' => $userId,
        ];
        $options = $this->getOptions();
        $allProfiles = $profileCollection->find($query, $options)->toArray();

        if (empty($allProfiles)) {
            return;
        }

        foreach ($allProfiles as $profile) {
            if (empty($profile['data'])) {
                continue;
            }

            foreach ($profile['data'] as $key => $data) {
                if (!is_array($data) || !isset($data['data']['data_type'])) {
                    continue;
                }

                if ($data['data']['data_type'] != 'inventory_settings') {
                    continue;
                }

                $updatedSchema = [
                    'data_type' => 'inventory_settings'
                ];
                foreach ($data['data'] as $key => $value) {
                    if ($key == 'data_type') {
                        continue;
                    }

                    switch ($key) {
                        case "settings_enabled":
                            $updatedSchema['inventory_settings'] = $value;
                            break;
                        case "enable_max_inventory_level":
                            $updatedSchema['continue_selling'] = [
                                'enabled' => $value,
                                'value' => $data['data']['max_inventory_level']
                            ];
                            break;
                        case "warehouses_settings":
                            $updatedSchema['warehouses'] = $value;
                            break;
                        case "customize_inventory":
                        case "threshold_inventory":
                        case "fixed_inventory":
                            if (isset($data['data']['settings_selected']['customize_inventory']) && $data['data']['settings_selected']['customize_inventory']) {
                                $updatedSchema['effective_inventory'] = [
                                    'type' => 'reserved',
                                    'enabled' => true,
                                    'value' => $data['data']['customize_inventory']['value']
                                ];
                            } elseif (isset($data['data']['settings_selected']['threshold_inventory']) && $data['data']['settings_selected']['threshold_inventory']) {
                                $updatedSchema['effective_inventory'] = [
                                    'type' => 'threshold',
                                    'enabled' => true,
                                    'value' => $data['data']['threshold_inventory']
                                ];
                            } elseif (isset($data['data']['settings_selected']['fixed_inventory']) && $data['data']['settings_selected']['fixed_inventory']) {
                                $updatedSchema['effective_inventory'] = [
                                    'type' => 'fixed',
                                    'enabled' => true,
                                    'value' => $data['data']['fixed_inventory']
                                ];
                            } else {
                                $updatedSchema['effective_inventory'] = [
                                    'type' => 'none',
                                    'enabled' => false,
                                    'value' => null
                                ];
                            }

                            break;

                        case "settings_selected":
                            if (isset($data['data']['settings_selected']['delete_out_of_stock']) || isset($data['data']['settings_selected']['inactive_product_setting'])) {
                                $updatedSchema["inactivate_product"] = $data['data']['settings_selected']['delete_out_of_stock'] ?? $data['data']['settings_selected']['inactive_product_setting'];
                                break;
                            }

                            break;
                        case "max_inventory_level":
                        case "reserved_inventory":
                            break;
                        default:
                            $updatedSchema[$key] = $value;
                            break;
                    }
                }

                $qRes = $profileCollection->updateOne(
                    [
                        '_id' => $profile['_id'],
                        'data.data_type' => 'inventory_settings',
                    ],
                    [
                        '$set' => [
                            'data.$[index].data' => $updatedSchema,
                        ]
                    ],
                    [
                        'arrayFilters' => [
                            ['index.data_type' => 'inventory_settings'],
                        ]
                    ]
                );
            }
        }

        //fetch templates using user id and type - warehouse
        //loop all the templates
        //where data.data_type = inventory_settings
        //then loop over data.data and update keys as per the new mapping
        //$set using arrayFilter for this index to replace with new value
    }

    public function removeAllErrorUsersWithNoAmazonShops(): void
    {
        $usersTokenCheckColl = $this->getCollection('users_token_check');
        $lastTraversedId = $usersTokenCheckColl->findOne(['type' => 'last_picked_uninstall_id'], $this->getOptions());
        $allErrorUsers = [
            'remote_response.success' => false,
            'has_amazon_shop' => null,
            'uninstall' => null,
        ];
        if (!empty($lastTraversedId['last_id'])) {
            $allErrorUsers['_id'] = ['$gt' => $lastTraversedId['last_id']];
        }

        $options = $this->getOptions();
        $options['sort']['_id'] = 1;
        $options['limit'] = self::LIMIT;
        $errorUsers = $usersTokenCheckColl->find($allErrorUsers, $options)->toArray();
        if (empty($errorUsers)) {
            echo 'no user found' . PHP_EOL;
            return;
        }

        $errorUsersIds = array_column($errorUsers, 'user_id');
        echo 'Initiating process for ' . count($errorUsers) . ' users' . PHP_EOL;
        $pushToQueueResponse = $this->pushToUninstallQueue($errorUsersIds);
        $errorEncounteredUsers = [];
        if (!empty($pushToQueueResponse['error_users'])) {
            $errorEncounteredUsers = $pushToQueueResponse['error_users'];
            echo 'error encountered in users - ' . count($errorEncounteredUsers) . PHP_EOL;
        }

        $this->updateErrorUsersInUserTokenCheck($errorEncounteredUsers, $errorUsersIds);
        $lastTraversedUser = end($errorUsers);
        $lastTraversedId = $lastTraversedUser['_id'];
        $usersTokenCheckColl->updateOne(
            ['type' => 'last_picked_uninstall_id'],
            [
                '$set' => [
                    'last_id' => $lastTraversedId
                ]
            ],
            ['upsert' => true]
        );
        echo 'process finished' . PHP_EOL;
    }

    public function updateErrorUsersInUserTokenCheck($errorUsers, $errorUsersIds): void
    {
        $successIds = array_diff($errorUsersIds, array_keys($errorUsers));
        $successIds = array_values($successIds);

        $bulkOpQuery = [];
        foreach ($errorUsers as $userId =>  $errorMessage) {
            $bulkOpQuery[] = [
                'updateOne' => [
                    ['user_id' => $userId],
                    [
                        '$set' => [
                            'uninstall' => false,
                            'uninstall_error' => $errorMessage
                        ],
                    ]
                ]
            ];
        }

        $bulkOpQuery[] = [
            'updateMany' => [
                ['user_id' => ['$in' => $successIds]],
                ['$set' => ['uninstall' => true]]
            ]
        ];
        if (!empty($bulkOpQuery)) {
            $this->getCollection('users_token_check')->bulkWrite($bulkOpQuery);
        }
    }

    public function updateAccessTokenOnHome($shopUrl, $userId): void
    {
        $appShop = $this->getAppShopFromRemote($shopUrl);
        $updateQuery = [];
        if (!$appShop) {
            echo 'error in retrieving app shop' . PHP_EOL;
            $updateQuery = [
                '$set' => [
                    'access_token_v2' => 'apps_shop not found',
                ]
            ];
        } else {
            $updateQuery = [
                '$set' => [
                    'access_token_v2' => $appShop['apps'][0]['token'] ?? 'token not found in apps array',
                    'shop_url_v2' => $appShop['shop_url'] ?? 'shop_url not found',
                ]
            ];
        }

        $this->getCollection('users_token_check')->updateOne(
            [
                'user_id' => $userId
            ],
            $updateQuery
        );
    }

    public function getAppShopFromRemote($shopUrl)
    {
        $db = $this->di->get('remote_db');
        if ($db) {
            $appsCollections = $db->selectCollection('apps_shop');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
            return $appsCollections->findOne(['shop_url' => $shopUrl], $options);
        }
        return false;
    }
}
