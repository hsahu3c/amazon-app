<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Core\Models\User;
use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\ObjectId;
use Exception;

#[\AllowDynamicProperties]
class ShopifyScriptsCommand extends Command
{

    protected static $defaultName = "shopify-scripts";

    protected static $defaultDescription = "To execute random shopify scripts";

    public const MARKETPLACE = 'shopify';
    public const SALES_CHANNEL_APP_CODE = 'amazon_sales_channel';
    public const VARIANT_CHECK_PROCESS_CODE = 'find_users_with_more_than_100_variants';
    public const USER_BATCH_SIZE = 500;

    private $output;
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
        $this->setHelp('To execute random or cron scripts');
        $this->addOption("method", "m", InputOption::VALUE_REQUIRED, "which method you want to invoke");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Shopify Scripts...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");
        $method = $input->getOption("method") ?? false;
        if (!$method) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Method name (-m) is mandatory</>');
            return 0;
        }
        if (!method_exists($this, $method)) {
            $this->output->writeln("➤ </><options=bold;fg=red>Method not found or not a valid method</>");
            return 0;
        }
        $this->$method();
        $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Process Completed</>');
        return 0;
    }

    public function setStoreClosedAtForUnavailableShops()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollection("user_details");
        $options = [
            'projection' => ['user_id' => 1, 'username' => 1],
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        $users = $userDetailsCollection->find(
            [
                'shops' => ['$elemMatch' => ['marketplace' => self::MARKETPLACE, 'store_closed_at' => ['$exists' => false]]],
                'picked' => ['$exists' => false]
            ],
            $options
        )->toArray();
        $maxMessageCount = 10;
        if (!empty($users)) {
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userCollection = $mongo->getCollectionForTable('user_details');
            $messagePushedCount = 0;
            $handlerData = [];
            $processedUserIds = [];
            foreach ($users as $user) {
                $handlerData[] = [
                    'type' => 'full_class',
                    'class_name' => '\\App\\Shopifyhome\\Components\\Shop\\Shop',
                    'method' => 'checkAndSetStoreClosedAtForUnavailableShop',
                    'queue_name' => 'shopify_check_unavailable_shop_users',
                    'user_id' => $user['user_id'],
                    'username' => $user['username']
                ];
                $processedUserIds[] = $user['user_id'];
                if (count($handlerData) == $maxMessageCount) {
                    $messagePushedCount = $messagePushedCount + $maxMessageCount;
                    $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> $messagePushedCount messages pushed</>");
                    $sqsHelper->pushMessagesBatch($handlerData);
                    $userCollection->updateMany(
                        [
                            'user_id' => ['$in' => $processedUserIds]
                        ],
                        [
                            '$set' => ['picked' => true]
                        ]
                    );
                    $handlerData = [];
                    $processedUserIds = [];
                }
            }
            if (!empty($handlerData)) {
                $messagePushedCount = $messagePushedCount + count($handlerData);
                $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> $messagePushedCount messages pushed</>");
                $sqsHelper->pushMessagesBatch($handlerData);
                $userCollection->updateMany(
                    [
                        'user_id' => ['$in' => $processedUserIds]
                    ],
                    [
                        '$set' => ['picked' => true]
                    ]
                );
            }
            return ['success' => true, 'message' => 'All users processed'];
        } else {
            $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> No users found for processing</>");
        }
    }

    public function fetchAndSavePublicationIdForAllUsers()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollection("user_details");
        $options = [
            'projection' => ['user_id' => 1, 'shops.$' => 1],
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        $users = $userDetailsCollection->find(
            [
                'shops' => ['$elemMatch' => ['marketplace' => self::MARKETPLACE, 'store_closed_at' => ['$exists' => false]]],
                'picked' => ['$exists' => false]
            ],
            $options
        )->toArray();
        $maxMessageCount = 10;
        if (!empty($users)) {
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userCollection = $mongo->getCollectionForTable('user_details');
            $messagePushedCount = 0;
            $handlerData = [];
            $processedUserIds = [];
            foreach ($users as $user) {
                $handlerData[] = [
                    'type' => 'full_class',
                    'class_name' => '\\App\\Shopifyhome\\Components\\Shop\\Shop',
                    'method' => 'fetchAndSavePublicationId',
                    'queue_name' => 'shopify_save_publication_id_all_users',
                    'user_id' => $user['user_id'],
                    'shop' => $user['shops'][0] ?? []
                ];
                $processedUserIds[] = $user['user_id'];
                if (count($handlerData) == $maxMessageCount) {
                    $messagePushedCount = $messagePushedCount + $maxMessageCount;
                    $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> $messagePushedCount messages pushed</>");
                    $sqsHelper->pushMessagesBatch($handlerData);
                    $userCollection->updateMany(
                        [
                            'user_id' => ['$in' => $processedUserIds]
                        ],
                        [
                            '$set' => ['picked' => true]
                        ]
                    );
                    $handlerData = [];
                    $processedUserIds = [];
                }
            }
            if (!empty($handlerData)) {
                $messagePushedCount = $messagePushedCount + count($handlerData);
                $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> $messagePushedCount messages pushed</>");
                $sqsHelper->pushMessagesBatch($handlerData);
                $userCollection->updateMany(
                    [
                        'user_id' => ['$in' => $processedUserIds]
                    ],
                    [
                        '$set' => ['picked' => true]
                    ]
                );
            }
            return ['success' => true, 'message' => 'All users processed'];
        } else {
            $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> No users found for processing</>");
        }
    }

    public function registerWebhookForInstalledUsersWithoutTarget()
    {
        $logFile = "shopify/script/" . date('d-m-Y') . '.log';
        $destinationData = $this->di->getConfig()->get('destination_data') ? $this->di->getConfig()->get('destination_data')->toArray() : [];
        $maxItemLimitToProcess = 100;
        if (empty($destinationData)) {
            $this->output->writeln("➤ </><options=bold;fg=red>Destination Data not found</>");
            return ['success' => false, 'message' => 'Destination Data not found'];
        }
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollection("user_details");
        $sourceModel = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel");
        $options = [
            'projection' => ['user_id' => 1, 'shops.$' => 1],
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        $users = $userDetailsCollection->find(
            [
                'shops' => ['$elemMatch' => ['marketplace' => self::MARKETPLACE, 'store_closed_at' => ['$exists' => false]]],
                'shops.1' => ['$exists' => false],
                'picked' => ['$exists' => false]
            ],
            $options
        )->toArray();
        if (!empty($users)) {
            $processedUserIds = [];
            foreach ($users as $user) {
                $userId = $user['user_id'];
                $this->di->getLog()->logContent('Starting process for user_id: ' . json_encode($userId), 'info',  $logFile);
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    $destinationWise = [];
                    $shopData = $user['shops'][0];
                    $appCode = $shopData['apps'][0]['code'] ?? 'default';
                    if (!empty($shopData['apps'][0]['webhooks'])) {
                        $webhookCodes = array_column($shopData['apps'][0]['webhooks'], 'code');
                        if (!in_array('app_delete', $webhookCodes)) {
                            $destinationWise[] = [
                                'destination_id' => $destinationData['user_app'],
                                'event_codes' =>  ["app_delete"]
                            ];
                            $this->di->getLog()->logContent('Registering app_delete as well for userId: ' . json_encode($userId), 'info',  $logFile);
                        }
                    } else {
                        $destinationWise[] = [
                            'destination_id' => $destinationData['user_app'],
                            'event_codes' =>  ["app_delete"]
                        ];
                        $this->di->getLog()->logContent('No webhooks found registering both onboarding webhooks for user: ' . json_encode($userId), 'info',  $logFile);
                    }
                    $destinationWise[] = [
                        'destination_id' => $destinationData['user'],
                        'event_codes' =>  ["shop_update"]
                    ];
                    $registerWebhookResponse = $sourceModel->routeRegisterWebhooks($shopData, self::MARKETPLACE, $appCode, false, $destinationWise);
                    if (isset($registerWebhookResponse['success']) && $registerWebhookResponse['success']) {
                        $this->di->getLog()->logContent('Registering webhook response: ' . json_encode($registerWebhookResponse), 'info',  $logFile);
                    } else {
                        $this->di->getLog()->logContent('Unable to register webhook response: ' . json_encode($registerWebhookResponse), 'info',  $logFile);
                    }
                    $processedUserIds[] = $userId;
                } else {
                    $this->di->getLog()->logContent('Unable to set di for user: ' . json_encode($userId), 'info',  $logFile);
                    $processedUserIds[] = $userId;
                }
                if (count($processedUserIds) == $maxItemLimitToProcess) {
                    $userDetailsCollection->updateMany(
                        [
                            'user_id' => ['$in' => $processedUserIds]
                        ],
                        [
                            '$set' => ['picked' => true]
                        ]
                    );
                    $processedUserIds = [];
                }
            }

            if (!empty($processedUserIds)) {
                $userDetailsCollection->updateMany(
                    [
                        'user_id' => ['$in' => $processedUserIds]
                    ],
                    [
                        '$set' => ['picked' => true]
                    ]
                );
            }
        }
        return ['success' => true, 'message' => 'All app installed users processed'];
    }

    public function unRegisterAppLevelWebhookForInstalledUsersWithoutTarget()
    {
        $logFile = "shopify/script/" . date('d-m-Y') . '.log';
        $maxItemLimitToProcess = 100;
        $onboardingWebhookCount = 2;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollection("user_details");
        $options = [
            'projection' => ['user_id' => 1, 'shops.$' => 1],
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        $users = $userDetailsCollection->find(
            [
                'shops' => ['$elemMatch' => ['marketplace' => self::MARKETPLACE, 'store_closed_at' => ['$exists' => false]]],
                'shops.1' => ['$exists' => false],
                'picked' => ['$exists' => false]
            ],
            $options
        )->toArray();
        if (!empty($users)) {
            $sourceModel = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel");
            $processedUserIds = [];
            foreach ($users as $user) {
                $userId = $user['user_id'];
                $this->di->getLog()->logContent('Starting process for userId: ' . json_encode($userId), 'info',  $logFile);
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    $shopData = $user['shops'][0];
                    if (!empty($shopData['apps'][0]['webhooks'])) {
                        $webhooks = $shopData['apps'][0]['webhooks'];
                        if (count($webhooks) > $onboardingWebhookCount) {
                            $appCode = $shopData['apps'][0]['code'] ?? 'default';
                            $unRegisterWebhookResponse = $sourceModel->routeUnregisterWebhooks($shopData, self::MARKETPLACE, $appCode, false);
                            if (isset($unRegisterWebhookResponse['success']) && $unRegisterWebhookResponse['success']) {
                                $this->di->getLog()->logContent('Unregistering app level webhook response: ' . json_encode($unRegisterWebhookResponse), 'info',  $logFile);
                            } else {
                                $this->di->getLog()->logContent('Unable to unregister webhook response: ' . json_encode($unRegisterWebhookResponse), 'info',  $logFile);
                            }
                        }
                    }
                    $processedUserIds[] = $userId;
                } else {
                    $this->di->getLog()->logContent('Unable to set di for user: ' . json_encode($userId), 'info',  $logFile);
                    $processedUserIds[] = $userId;
                }
                if (count($processedUserIds) == $maxItemLimitToProcess) {
                    $userDetailsCollection->updateMany(
                        [
                            'user_id' => ['$in' => $processedUserIds]
                        ],
                        [
                            '$set' => ['picked' => true]
                        ]
                    );
                    $processedUserIds = [];
                }
            }

            if (!empty($processedUserIds)) {
                $userDetailsCollection->updateMany(
                    [
                        'user_id' => ['$in' => $processedUserIds]
                    ],
                    [
                        '$set' => ['picked' => true]
                    ]
                );
            }
        }
        return ['success' => true, 'message' => 'All app installed users processed'];
    }

    public function removeStoreClosedAtKeyForDormantUsers()
    {
        $logFile = "shopify/script/" . date('d-m-Y') . '.log';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollection("user_details");
        $options = [
            'projection' => ['user_id' => 1, 'shops' => 1],
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        $users = $userDetailsCollection->find(
            [
                'shops' => ['$elemMatch' => ['marketplace' => self::MARKETPLACE, 'plan_name' => 'dormant', 'store_closed_at' => ['$exists' => true]]]
            ],
            $options
        )->toArray();
        if (!empty($users)) {
            foreach ($users as $user) {
                $userId = $user['user_id'];
                $this->di->getLog()->logContent('Starting process for user_id: ' . json_encode($userId), 'info',  $logFile);
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Starting process for user_id:' . json_encode($userId) . '</>');
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    if (!empty($user['shops'])) {
                        foreach ($user['shops'] as $shopData) {
                            if ($shopData['marketplace'] == self::MARKETPLACE) {
                                $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> Store_closed_at key unset successfully</>");
                                $this->di->getLog()->logContent('Unsetting store_closed_at: ' . json_encode($userId), 'info',  $logFile);
                                $userDetailsCollection->updateOne(
                                    ['user_id' => $userId, 'shops' => ['$elemMatch' => ['_id' => $shopData['_id']]]],
                                    ['$unset' => ['shops.$.store_closed_at' => true]]
                                );
                                break;
                            }
                        }
                    }
                    if (count($user['shops']) > 1) {
                        $dataToSent = [
                            'user_id' => $userId,
                            'shops' => $user['shops'],
                            'status' => 'reopened',
                            'marketplace' => self::MARKETPLACE
                        ];
                        $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> Shop status update event fired</>");
                        $this->di->getLog()->logContent('shopStatusUpdate event called for dataToSent: ' . json_encode($dataToSent), 'info',  $logFile);
                        $eventsManager = $this->di->getEventsManager();
                        $eventsManager->fire(
                            "application:shopStatusUpdate",
                            $this,
                            $dataToSent
                        );
                    }
                } else {
                    $this->di->getLog()->logContent('Unable to set di for user: ' . json_encode($userId), 'info',  $logFile);
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to set di for user:' . json_encode($userId) . '</>');
                }
            }
        } else {
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> All users processed!</>');
        }
    }

    public function updateShopifyWebhookAddressForPaidClients()
    {
        // Will only run for paid clients with active plan and custom price > 0 and for users who has connected targets
        $logFile = "shopify/script/" . date('d-m-Y') . '.log';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $paymentDetailsCollection = $mongo->getCollection("payment_details");
        $planTier = 'paid';
        $options = [
            'projection' => ['user_id' => 1],
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        $paymentDetails = $paymentDetailsCollection->find([
            'status' => 'active',
            'type' => 'active_plan',
            'plan_details.custom_price' => ['$gt' => 0]
        ])->toArray();
        if (!empty($paymentDetails)) {
            $userIds = array_column($paymentDetails, 'user_id');
            $userDetailsCollection = $mongo->getCollection("user_details");
            $userDetails = $userDetailsCollection->find([
                'user_id' => ['$in' => $userIds],
                'shops' => ['$elemMatch' => [
                    'marketplace' => self::MARKETPLACE,
                    'apps.app_status' => 'active',
                    'store_closed_at' => ['$exists' => false],
                    'targets' => ['$exists' => true]
                ]],
                'shops.1' => ['$exists' => true],
                'picked' => ['$exists' => false]
            ], $options)->toArray();
            if (!empty($userDetails)) {
                foreach ($userDetails as $userDetail) {
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Starting process for user_id:' . json_encode($userDetail['user_id']) . '</>');
                    $this->di->getLog()->logContent('Starting process for user_id: ' . json_encode($userDetail['user_id']), 'info',  $logFile);
                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => '\App\Shopifyhome\Components\Webhook\Helper',
                        'method' => 'updateShopifyWebhookAddressByPlanTier',
                        'queue_name' => 'shopify_update_webhook_address',
                        'user_id' => $userDetail['user_id'],
                        'data' => [
                            'plan_tier' => $planTier
                        ]
                    ];
                    $response = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')
                        ->pushMessage($handlerData);
                    $this->di->getLog()->logContent('Message pushed handlerData: ' . json_encode($response), 'info',  $logFile);
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userDetail['user_id']],
                        ['$set' => ['plan_tier' => $planTier, 'picked' => true]]
                    );
                }
            }
        }
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
                'message' => 'User not found'
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

    public function checkAndUninstallTokenExpiredUsers()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollection("user_details");
        $options = [
            'projection' => ['user_id' => 1, 'username' => 1],
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        $users = $userDetailsCollection->find(
            [
                'shops' => ['$elemMatch' => ['marketplace' => self::MARKETPLACE, 'store_closed_at' => ['$exists' => false], 'apps.app_status' => 'active']],
                'picked' => ['$exists' => false]
            ],
            $options
        )->toArray();
        $maxMessageCount = 10;
        if (!empty($users)) {
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userCollection = $mongo->getCollectionForTable('user_details');
            $messagePushedCount = 0;
            $handlerData = [];
            $processedUserIds = [];
            foreach ($users as $user) {
                $handlerData[] = [
                    'type' => 'full_class',
                    'class_name' => '\\App\\Shopifyhome\\Components\\Shop\\Shop',
                    'method' => 'checkAndUninstallTokenExpiredUsers',
                    'queue_name' => 'shopify_check_and_uninstall_token_expired_users',
                    'user_id' => $user['user_id'],
                    'username' => $user['username']
                ];
                $processedUserIds[] = $user['user_id'];
                if (count($handlerData) == $maxMessageCount) {
                    $messagePushedCount = $messagePushedCount + $maxMessageCount;
                    $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> $messagePushedCount messages pushed</>");
                    $sqsHelper->pushMessagesBatch($handlerData);
                    $userCollection->updateMany(
                        [
                            'user_id' => ['$in' => $processedUserIds]
                        ],
                        [
                            '$set' => ['picked' => true]
                        ]
                    );
                    $handlerData = [];
                    $processedUserIds = [];
                }
            }
            if (!empty($handlerData)) {
                $messagePushedCount = $messagePushedCount + count($handlerData);
                $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> $messagePushedCount messages pushed</>");
                $sqsHelper->pushMessagesBatch($handlerData);
                $userCollection->updateMany(
                    [
                        'user_id' => ['$in' => $processedUserIds]
                    ],
                    [
                        '$set' => ['picked' => true]
                    ]
                );
            }
            return ['success' => true, 'message' => 'All users processed'];
        } else {
            $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> No users found for processing</>");
        }
    }

    public function updateAppCodesInProducts()
    {
        $logFile = "shopify/script/updateAppCodesInProducts/" . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Starting updateAppCodesInProducts process...', 'info', $logFile);
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Starting updateAppCodesInProducts process...</>');

        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollectionForTable('product_container');
            $trackingCollection = $mongo->getCollectionForTable('orphaned_shopify_products_with_default_app_code');

            // Query to find products
            $query = [
                'user_id' => ['$ne' => null],
                'shop_id' => ['$ne' => null],
                'source_marketplace' => 'shopify',
                'app_codes' => ['$nin' => [self::SALES_CHANNEL_APP_CODE]]
            ];

            $chunkSize = 2000;
            $totalProcessed = 0;
            $totalInserted = 0;
            $totalUpdated = 0;

            do {
                $options = [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => $chunkSize,
                    'projection' => [
                        'user_id' => 1,
                        'shop_id' => 1,
                        'container_id' => 1,
                        'source_product_id' => 1,
                        'source_marketplace' => 1,
                        'app_codes' => 1
                    ]
                ];

                $products = $productContainer->find($query, $options)->toArray();
                if (empty($products)) {
                    break;
                }

                $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing chunk of ' . count($products) . ' products</>');
                $this->di->getLog()->logContent('Processing chunk of ' . count($products) . ' products', 'info', $logFile);

                // Prepare documents for insertion
                $documentsToInsert = [];
                $productIdsToUpdate = [];

                foreach ($products as $product) {
                    $userId = $product['user_id'] ?? null;
                    $shopId = $product['shop_id'] ?? null;
                    $containerId = $product['container_id'] ?? null;

                    if (!$userId || !$shopId || !$containerId) {
                        continue;
                    }

                    // Prepare document for insertion
                    $documentsToInsert[] = [
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                        'container_id' => $containerId,
                        'source_product_id' => $product['source_product_id'] ?? null,
                        'marketplace' => 'shopify',
                        'created_at' => new UTCDateTime()
                    ];

                    $productIdsToUpdate[] = $product['_id'];
                }
                if (!empty($documentsToInsert)) {
                    $insertedCount = $this->insertUniqueDocuments($trackingCollection, $documentsToInsert, $logFile);
                    $totalInserted += $insertedCount;
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Inserted ' . $insertedCount . ' unique documents (skipped ' . (count($documentsToInsert) - $insertedCount) . ' duplicates)</>');
                    $this->di->getLog()->logContent('Inserted ' . $insertedCount . ' unique documents (skipped ' . (count($documentsToInsert) - $insertedCount) . ' duplicates)', 'info', $logFile);
                }

                if (!empty($productIdsToUpdate)) {
                    $updateResult = $productContainer->updateMany(
                        ['_id' => ['$in' => $productIdsToUpdate]],
                        ['$set' => ['app_codes' => [self::SALES_CHANNEL_APP_CODE]]]
                    );
                    $updatedCount = $updateResult->getModifiedCount();
                    $totalUpdated += $updatedCount;
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Updated app_codes for ' . $updatedCount . ' products</>');
                    $this->di->getLog()->logContent('Updated app_codes for ' . $updatedCount . ' products', 'info', $logFile);
                }
                $totalProcessed += count($products);
            } while (count($products) == $chunkSize);

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Process completed! Total processed: ' . $totalProcessed . ', Total inserted: ' . $totalInserted . ', Total updated: ' . $totalUpdated . '</>');
            $this->di->getLog()->logContent('Process completed! Total processed: ' . $totalProcessed . ', Total inserted: ' . $totalInserted . ', Total updated: ' . $totalUpdated, 'info', $logFile);
        } catch (Exception $e) {
            $logFile = $logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in updateAppCodesInProducts: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error in updateAppCodesInProducts: ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Insert unique documents avoiding duplicates based on user_id, shop_id, container_id, marketplace
     * Uses bulkWrite with upsert to handle duplicates
     */
    private function insertUniqueDocuments($collection, $documents, $logFile)
    {
        try {
            $operations = [];
            $now = new UTCDateTime();
            foreach ($documents as $doc) {
                $filter = [
                    'user_id' => $doc['user_id'],
                    'shop_id' => $doc['shop_id'],
                    'container_id' => $doc['container_id'],
                    'source_product_id' => $doc['source_product_id'],
                    'marketplace' => $doc['marketplace']
                ];

                $updateData = [
                    '$setOnInsert' => [
                        'user_id' => $doc['user_id'],
                        'shop_id' => $doc['shop_id'],
                        'container_id' => $doc['container_id'],
                        'source_product_id' => $doc['source_product_id'],
                        'marketplace' => $doc['marketplace'],
                        'created_at' => $doc['created_at']
                    ],
                    '$set' => [
                        'updated_at' => $now
                    ]
                ];

                $operations[] = [
                    'updateOne' => [
                        $filter,
                        $updateData,
                        ['upsert' => true]
                    ]
                ];
            }

            if (empty($operations)) {
                return 0;
            }
            $result = $collection->bulkWrite($operations);
            $insertedCount = $result->getUpsertedCount();
            return $insertedCount;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in insertUniqueDocuments: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error in insertUniqueDocuments: ' . $e->getMessage() . '</>');
            return 0;
        }
    }

    public function setPlanTierDefaultForFreeClients()
    {
        try {
            $logFile = "shopify/script/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $paymentDetailsCollection = $mongo->getCollection("payment_details");
            $planTier = 'default';
            $options = [
                'projection' => ['user_id' => 1],
                "typeMap" => ['root' => 'array', 'document' => 'array']
            ];
            $paymentDetails = $paymentDetailsCollection->find([
                'status' => 'active',
                'type' => 'active_plan',
                'plan_details.custom_price' => 0
            ], $options)->toArray();
            if (!empty($paymentDetails)) {
                $userIds = array_column($paymentDetails, 'user_id');
                $userDetailsCollection = $mongo->getCollection("user_details");
                $userDetailsCollection->updateMany(
                    [
                        'user_id' => ['$in' => $userIds],
                    ],
                    ['$set' => ['plan_tier' => $planTier]]
                );
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Plan tier set to default for free clients</>');
            } else {
                $this->di->getLog()->logContent('No free clients found', 'info',  $logFile ?? 'exception.log');
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No free clients found</>');
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in setPlanTierDefaultForFreeClients: ' . json_encode($e->getMessage()), 'info',  $logFile ?? 'exception.log');
        }
    }

    public function checkAccessScopesForActiveShops()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollection("user_details");
        $options = [
            'projection' => ['user_id' => 1, 'username' => 1],
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        $users = $userDetailsCollection->find(
            [
                'shops' => ['$elemMatch' => ['marketplace' => self::MARKETPLACE, 'apps.app_status' => 'active']],
                'picked' => ['$exists' => false]
            ],
            $options
        )->toArray();
        $maxMessageCount = 10;
        if (!empty($users)) {
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userCollection = $mongo->getCollectionForTable('user_details');
            $messagePushedCount = 0;
            $handlerData = [];
            $processedUserIds = [];
            foreach ($users as $user) {
                $handlerData[] = [
                    'type' => 'full_class',
                    'class_name' => '\\App\\Shopifyhome\\Components\\Shop\\Shop',
                    'method' => 'checkAccessScopesForShop',
                    'queue_name' => 'shopify_check_access_scopes',
                    'user_id' => $user['user_id'],
                    'username' => $user['username'],
                    'marketplace' => self::MARKETPLACE
                ];
                $processedUserIds[] = $user['user_id'];
                if (count($handlerData) == $maxMessageCount) {
                    $messagePushedCount = $messagePushedCount + $maxMessageCount;
                    $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> $messagePushedCount messages pushed</>");
                    $sqsHelper->pushMessagesBatch($handlerData);
                    $userCollection->updateMany(
                        [
                            'user_id' => ['$in' => $processedUserIds]
                        ],
                        [
                            '$set' => ['picked' => true]
                        ]
                    );
                    $handlerData = [];
                    $processedUserIds = [];
                }
            }
            if (!empty($handlerData)) {
                $messagePushedCount = $messagePushedCount + count($handlerData);
                $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> $messagePushedCount messages pushed</>");
                $sqsHelper->pushMessagesBatch($handlerData);
                $userCollection->updateMany(
                    [
                        'user_id' => ['$in' => $processedUserIds]
                    ],
                    [
                        '$set' => ['picked' => true]
                    ]
                );
            }
            return ['success' => true, 'message' => 'All users processed'];
        } else {
            $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> No users found for processing</>");
        }
    }

    public function findUsersWithMoreThan100Variants()
    {
        try {
            $logFile = "shopify/script/findUsersWithMoreThan100Variants/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $productContainer = $mongo->getCollectionForTable('product_container');
            $variantCollection = $mongo->getCollectionForTable('users_data_with_more_than_100_variant');

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting findUsersWithMoreThan100Variants process...</>');
            $this->di->getLog()->logContent('Starting findUsersWithMoreThan100Variants process', 'info', $logFile);

            $lastProcessedUserId = $this->getResumeDataForVariantCheck($mongo);
            if ($lastProcessedUserId) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Resuming from user _id: ' . $lastProcessedUserId . '</>');
                $this->di->getLog()->logContent('Resuming from user _id: ' . $lastProcessedUserId, 'info', $logFile);
            }

            $totalProcessedUsers = 0;

            do {
                $users = $this->getAllUsersForVariantCheck($userDetailsCollection, $lastProcessedUserId);
                if (empty($users)) {
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>No more users to process. All users completed!</>');
                    $this->di->getLog()->logContent('No more users to process. All users completed!', 'info', $logFile);
                    break;
                }

                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing batch of ' . count($users) . ' users</>');
                $this->di->getLog()->logContent('Processing batch of ' . count($users) . ' users', 'info', $logFile);

                foreach ($users as $user) {
                    $userId = $user['user_id'];
                    $lastProcessedUserId = (string)$user['_id'];

                    // Find shopify shop
                    $shopId = null;
                    if (!empty($user['shops'])) {
                        foreach ($user['shops'] as $shop) {
                            if (isset($shop['marketplace']) && $shop['marketplace'] == self::MARKETPLACE) {
                                $shopId = (string)$shop['_id'];
                                break;
                            }
                        }
                    }
                    if (empty($shopId)) {
                        $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - shopify shop not found', 'info', $logFile);
                        continue;
                    }

                    // Aggregation to find products with >100 variants
                    $pipeline = [
                        ['$match' => [
                            'user_id' => $userId,
                            'shop_id' => $shopId,
                            'source_marketplace' => self::MARKETPLACE
                        ]],
                        ['$group' => [
                            '_id' => '$container_id',
                            'variant_count' => ['$sum' => 1]
                        ]],
                        ['$match' => [
                            'variant_count' => ['$gt' => 100]
                        ]],
                        ['$count' => 'total_products_with_more_than_100_variants']
                    ];

                    $result = $productContainer->aggregate($pipeline, ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();
                    $count = $result[0]['total_products_with_more_than_100_variants'] ?? 0;
                    if ($count > 0) {
                        $variantCollection->updateOne(
                            ['user_id' => $userId, 'shop_id' => $shopId],
                            [
                                '$set' => [
                                    'marketplace' => self::MARKETPLACE,
                                    'count' => $count,
                                    'plan_tier' => $user['plan_tier'],
                                    'updated_at' => date('c')
                                ],
                                '$setOnInsert' => [
                                    'picked' => false,
                                    'mail_sent' => false,
                                    '2048_support_allowed' => false,
                                    'created_at' => date('c')
                                ]
                            ],
                            ['upsert' => true]
                        );
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>User: ' . $userId . ' - Found ' . $count . ' products with >100 variants</>');
                        $this->di->getLog()->logContent('User: ' . $userId . ' - Found ' . $count . ' products with >100 variants', 'info', $logFile);
                    } else {
                        $this->di->getLog()->logContent('User: ' . $userId . ' - No products with >100 variants', 'info', $logFile);
                    }

                    $totalProcessedUsers++;
                }

                // Update cron_tasks after batch completes
                $this->updateLastProcessedUserIdForVariantCheck($mongo, $lastProcessedUserId);
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Completed batch. Total processed: ' . $totalProcessedUsers . '</>');
                $this->di->getLog()->logContent('Completed batch. Total processed: ' . $totalProcessedUsers, 'info', $logFile);
            } while (count($users) == self::USER_BATCH_SIZE);

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>All users processing completed! Total users processed: ' . $totalProcessedUsers . '</>');
            $this->di->getLog()->logContent('All users processing completed! Total users processed: ' . $totalProcessedUsers, 'info', $logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in findUsersWithMoreThan100Variants(): ' . $e->getMessage(), 'error', $logFile ?? 'exception.log');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception: ' . $e->getMessage() . '</>');
        }
    }

    public function sendMailToUsersWithMoreThan100Variants()
    {
        try {
            $logFile = "shopify/script/sendMailToUsersWithMoreThan100Variants/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $variantCollection = $mongo->getCollectionForTable('users_data_with_more_than_100_variant');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $paymentCollection = $mongo->getCollectionForTable('payment_details');

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting sendMailToUsersWithMoreThan100Variants process...</>');
            $this->di->getLog()->logContent('Starting sendMailToUsersWithMoreThan100Variants process', 'info', $logFile);

            // Fetch all users with 2048_variant_support enabled
            $supportedUsers = $paymentCollection->find(
                [
                    'service_type' => 'additional_services',
                    'supported_features.2048_variant_support' => true,
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => ['user_id' => 1]
                ]
            )->toArray();
            $supportedUserIds = array_column($supportedUsers, 'user_id');
            $this->di->getLog()->logContent('Found ' . count($supportedUserIds) . ' users with 2048_variant_support', 'info', $logFile);
            // Fetch unpicked users from variant collection
            $unpickedUsers = $variantCollection->find(
                ['picked' => false],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            )->toArray();

            if (empty($unpickedUsers)) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>No unpicked users found to process</>');
                $this->di->getLog()->logContent('No unpicked users found to process', 'info', $logFile);
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Found ' . count($unpickedUsers) . ' unpicked users to process</>');
            $this->di->getLog()->logContent('Found ' . count($unpickedUsers) . ' unpicked users to process', 'info', $logFile);

            foreach ($unpickedUsers as $userData) {
                $userId = $userData['user_id'];
                $shopId = $userData['shop_id'];
                $count = $userData['count'];

                // If user has 2048_variant_support, skip mail
                if (in_array($userId, $supportedUserIds)) {
                    $variantCollection->updateOne(
                        ['user_id' => $userId, 'shop_id' => $shopId],
                        ['$set' => ['2048_support_allowed' => true, 'mail_sent' => false, 'picked' => true]]
                    );
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping user: ' . $userId . ' - has 2048_variant_support</>');
                    $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - has 2048_variant_support', 'info', $logFile);
                    continue;
                }

                // Fetch user email and username from user_details
                $userDetail = $userDetailsCollection->findOne(
                    ['user_id' => $userId],
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'projection' => ['username' => 1, 'email' => 1]
                    ]
                );

                if (empty($userDetail) || empty($userDetail['email'])) {
                    $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - email not found', 'info', $logFile);
                    $variantCollection->updateOne(
                        ['user_id' => $userId, 'shop_id' => $shopId],
                        ['$set' => ['picked' => true, 'mail_sent' => false]]
                    );
                    continue;
                }

                $username = $userDetail['username'] ?? $userDetail['email'];
                $userEmail = $userDetail['email'];
                $lastDate = date('c', strtotime('+15 days'));
                $lastDateFormatted = date('d M Y', strtotime('+15 days'));

                // Send mail
                $msgData = [
                    'app_name' => $this->di->getConfig()->app_name,
                    'name' => $username,
                    'email' => $userEmail,
                    'subject' => 'Action Required: Products with 100+ Variants',
                    'path' => 'shopifyhome' . DS . 'view' . DS . 'email' . DS . 'variant_limit_notification.volt',
                    'count' => $count,
                    'last_date' => $lastDateFormatted
                ];
                $mailResponse = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($msgData);

                if ($mailResponse) {
                    // Set auto_remove_extra_variant_products_after in user_details
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        ['$set' => ['auto_remove_extra_variant_products_after' => $lastDate]]
                    );

                    // Mark as picked in variant collection
                    $variantCollection->updateOne(
                        ['user_id' => $userId, 'shop_id' => $shopId],
                        ['$set' => ['picked' => true, 'mail_sent' => true]]
                    );

                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Mail sent to user: ' . $userId . ' (' . $userEmail . ')</>');
                    $this->di->getLog()->logContent('Mail sent to user: ' . $userId . ' (' . $userEmail . ') - count: ' . $count . ' - last_date: ' . $lastDateFormatted, 'info', $logFile);
                } else {
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Failed to send mail to user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('Failed to send mail to user: ' . $userId . ' (' . $userEmail . ')', 'error', $logFile);
                }
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Process completed</>');
            $this->di->getLog()->logContent('Process completed', 'info', $logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in sendMailToUsersWithMoreThan100Variants(): ' . $e->getMessage(), 'error', $logFile ?? 'exception.log');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception: ' . $e->getMessage() . '</>');
        }
    }

    public function removeExtraVariantProductsAfterAutoRemoveDate()
    {
        try {
            $logFile = "shopify/script/removeExtraVariantProducts/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $productContainer = $mongo->getCollectionForTable('product_container');

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting removeExtraVariantProductsAfterAutoRemoveDate process...</>');
            $this->di->getLog()->logContent('Starting removeExtraVariantProductsAfterAutoRemoveDate process', 'info', $logFile);

            $userData = $userDetailsCollection->find(
                [
                    'auto_remove_extra_variant_products_after' => ['$lte' => date('c')],
                    'auto_removed_extra_variant_products_at' => ['$exists' => false]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => ['user_id' => 1, 'shops' => 1]
                ]
            )->toArray();

            if (empty($userData)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>No users found with auto_remove_extra_variant_products_after date reached</>');
                $this->di->getLog()->logContent('No users found with auto_remove_extra_variant_products_after date reached', 'info', $logFile);
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Found ' . count($userData) . ' users to process</>');
            $this->di->getLog()->logContent('Found ' . count($userData) . ' users to process', 'info', $logFile);
            foreach ($userData as $user) {
                $userId = $user['user_id'];
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Processing user: ' . $userId, 'info', $logFile);

                // Find shopify shop
                $shopId = null;
                if (!empty($user['shops'])) {
                    foreach ($user['shops'] as $shop) {
                        if (isset($shop['marketplace']) && $shop['marketplace'] == self::MARKETPLACE) {
                            $shopId = (string)$shop['_id'];
                            break;
                        }
                    }
                }

                if (empty($shopId)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping user: ' . $userId . ' - shopify shop not found</>');
                    $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - shopify shop not found', 'info', $logFile);
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$unset' => ['auto_remove_extra_variant_products_after' => 1]
                        ]
                    );
                    continue;
                }

                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting product deletion process for user: ' . $userId . '</>');

                $chunkSize = 500;
                $totalDeletedContainerIds = 0;
                $hasProducts = false;

                do {
                    $pipeline = [
                        ['$match' => [
                            'user_id' => $userId,
                            'shop_id' => $shopId,
                            'source_marketplace' => self::MARKETPLACE
                        ]],
                        ['$group' => [
                            '_id' => '$container_id',
                            'variant_count' => ['$sum' => 1]
                        ]],
                        ['$match' => [
                            'variant_count' => ['$gt' => 100]
                        ]],
                        ['$limit' => $chunkSize]
                    ];
                    $result = $productContainer->aggregate($pipeline, ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();
                    if (empty($result)) {
                        if (!$hasProducts) {
                            $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>No products with >100 variants found for user: ' . $userId . '</>');
                            $this->di->getLog()->logContent('No products with >100 variants found for user: ' . $userId, 'info', $logFile);
                            $userDetailsCollection->updateOne(
                                ['user_id' => $userId],
                                ['$unset' => ['auto_remove_extra_variant_products_after' => 1]]
                            );
                        }
                        break;
                    }

                    $hasProducts = true;
                    $containerIds = array_column($result, '_id');
                    $containerIdsCount = count($containerIds);
                    $totalDeletedContainerIds += $containerIdsCount;

                    $this->di->getLog()->logContent('User: ' . $userId . ' - Found' . $containerIdsCount . ' container_ids with >100 variants in this chunk', 'info', $logFile);

                    $productContainer->deleteMany([
                        'user_id' => $userId,
                        'container_id' => ['$in' => $containerIds]
                    ]);

                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Deleted ' . $containerIdsCount . ' container_ids for user: ' . $userId . ', Total deleted container_ids: ' . $totalDeletedContainerIds . '</>');
                    $this->di->getLog()->logContent('User: ' . $userId . ' - Deleted ' . $containerIdsCount . ' container_ids in this chunk, Total deleted container_ids: ' . $totalDeletedContainerIds, 'info', $logFile);

                    if ($containerIdsCount < $chunkSize) {
                        break;
                    }

                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing next ' . $chunkSize . ' container_ids deletion for user: ' . $userId . ', Total ' . $totalDeletedContainerIds . ' deleted container_ids for user_id: ' . $userId . '</>');
                } while (true);

                if ($hasProducts) {
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>User: ' . $userId . ' - Total ' . $totalDeletedContainerIds . ' container_ids deleted from product_container</>');
                    $this->di->getLog()->logContent('User: ' . $userId . ' - Total ' . $totalDeletedContainerIds . ' container_ids deleted from product_container', 'info', $logFile);

                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$set' => ['auto_removed_extra_variant_products_at' => date('c')],
                            '$unset' => ['auto_remove_extra_variant_products_after' => 1]
                        ]
                    );
                    $this->di->getLog()->logContent('User: ' . $userId . ' - Marked as processed', 'info', $logFile);
                }
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Process completed</>');
            $this->di->getLog()->logContent('Process completed', 'info', $logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in removeExtraVariantProductsAfterAutoRemoveDate(): ' . $e->getMessage(), 'error', $logFile ?? 'exception.log');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception: ' . $e->getMessage() . '</>');
        }
    }

    private function getResumeDataForVariantCheck($mongo)
    {
        try {
            $cronTasks = $mongo->getCollectionForTable('cron_tasks');
            $taskData = $cronTasks->findOne(
                ['task' => self::VARIANT_CHECK_PROCESS_CODE],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            );
            return $taskData['last_processed_user_id'] ?? null;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in getResumeDataForVariantCheck: ' . $e->getMessage(), 'info', 'exception.log');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Error getting resume data: ' . $e->getMessage() . '</>');
            return null;
        }
    }

    private function updateLastProcessedUserIdForVariantCheck($mongo, $userId)
    {
        try {
            $cronTasks = $mongo->getCollectionForTable('cron_tasks');
            $cronTasks->updateOne(
                ['task' => self::VARIANT_CHECK_PROCESS_CODE],
                [
                    '$set' => [
                        'last_processed_user_id' => $userId,
                        'updated_at' => new UTCDateTime()
                    ]
                ],
                ['upsert' => true]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in updateLastProcessedUserIdForVariantCheck: ' . $e->getMessage(), 'info', 'exception.log');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Error updating last processed user: ' . $e->getMessage() . '</>');
        }
    }

    private function getAllUsersForVariantCheck($userDetailsCollection, $lastProcessedUserId = null)
    {
        try {
            $query = [
                'shops' => [
                    '$elemMatch' => [
                        'marketplace' => self::MARKETPLACE,
                        'apps.app_status' => 'active',
                        'targets' => ['$exists' => true]
                    ]
                ],
                'shops.1' => ['$exists' => true]
            ];

            if ($lastProcessedUserId) {
                $query['_id'] = ['$gt' => new ObjectId($lastProcessedUserId)];
            }

            return $userDetailsCollection->find($query, [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'limit' => self::USER_BATCH_SIZE,
                'projection' => ['user_id' => 1, 'shops' => 1,'plan_tier' => 1],
                'sort' => ['_id' => 1]
            ])->toArray();
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in getAllUsersForVariantCheck: ' . $e->getMessage(), 'info', 'exception.log');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Error getting users: ' . $e->getMessage() . '</>');
            return [];
        }
    }
}
