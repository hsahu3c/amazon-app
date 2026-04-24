<?php

use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Helper;
use App\Core\Models\User;
use App\Shopifyhome\Components\UserEvent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[\AllowDynamicProperties]
class WebhookSubscriptionCommand extends Command
{

    protected static $defaultName = "webhook-subscription";

    protected static $defaultDescription = "To Migrate REST Webhook to GraphQL Webhook Subscription";

    private $destinationData;

    private $logFile;

    private $input;

    private $output;

    private $appTag;

    private $target;

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
        $this->setHelp('To Migrate REST Webhook to GraphQL Webhook Subscription');
        $this->addOption("active_page", "p", InputOption::VALUE_REQUIRED, "type");
        $this->addOption("app_tag", "a", InputOption::VALUE_REQUIRED, "type");
        $this->addOption("target", "t", InputOption::VALUE_REQUIRED, "type");
        $this->addOption("test_user", "u", InputOption::VALUE_OPTIONAL, "type");
        $this->addOption("function", "f", InputOption::VALUE_OPTIONAL, "type");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logFile = "shopifyWebhookSubscription/script/" . date('d-m-Y') . '.log';
        $this->output = $output;
        $this->input = $input;
        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Initiating Migration Process...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $activePage = $input->getOption("active_page") ?? false;
        $this->appTag = $input->getOption("app_tag") ?? false;
        $this->target = $input->getOption("target") ?? false;
        if (!$this->appTag) {
            $this->output->writeln("➤ </><options=bold;fg=red>AppTag is mandatory</>");
            return 0;
        }

        $f = $input->getOption("function") ?? false;
        if ($f) {
            $this->$f($activePage);
        } else {
            $this->registerQlWebhook($activePage);
        }

        return 1;
    }

    public function registerQlWebhook($activePage)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('user_details');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $testUsers = $this->input->getOption("test_user") ?? 'n';
        $marketplace = 'shopify';
        if ($testUsers == 'y') {
            $users = $this->di->getConfig()->get('test_webhook_subscription_users');
            if (!empty($users)) {
                $users = $users->toArray();
                $pipline = [
                    [
                        '$match' => [
                            'user_id' => ['$in' => $users],
                            'shops' => [
                                '$elemMatch' => [
                                    'apps.app_status' => 'active',
                                    'marketplace' => $this->target
                                ],
                            ],
                        ]
                    ],
                    ['$project' => ['user_id' => 1, 'username' => 1]]
                ];
            } else {
                return false;
            }
        } elseif(!$this->target) {
            $limit = 1000;
            if (!$activePage) {
                $activePage = 1;
            }

            $page = $activePage;
            $offset = ($page - 1) * $limit;
            $marketplace = $this->di->getObjectManager()
            ->get(Shop::class)->getUserMarkeplace();
            $pipline = [
                [
                    '$match' => [
                        'shops.1' => ['$exists' => false],
                        'shops' => [
                            '$elemMatch' => [
                                'apps.app_status' => 'active',
                                'marketplace' => $marketplace
                            ],
                        ],
                    ]
                ],
                ['$project' => ['user_id' => 1, 'username' => 1]],
                ['$skip' => (int) $offset],
                ['$limit' => $limit]
            ];
        } else {
            $limit = 500;
            if (!$activePage) {
                $activePage = 1;
            }

            $page = $activePage;
            $offset = ($page - 1) * $limit;
            $pipline = [
                [
                    '$match' => [
                        'shops' => [
                            '$elemMatch' => [
                                'apps.app_status' => 'active',
                                'marketplace' => $this->target
                            ],
                        ],
                    ]
                ],
                ['$project' => ['user_id' => 1, 'username' => 1]],
                ['$skip' => (int) $offset],
                ['$limit' => $limit]
            ];
        }

        $userData = $query->aggregate($pipline, $arrayParams)->toArray();
        if (!empty($userData)) {
            $this->destinationData = $this->di->getConfig()->get('destination_data');
            if (empty($this->destinationData)) {
                $this->output->writeln("➤ </><options=bold;fg=red>Destination Data not found</>");
                return ['success' => false, 'message' => 'Destination Data not found'];
            }

            $this->destinationData = ($this->destinationData)->toArray();
            foreach ($userData as $user) {
                $createResponse = [];
                $this->output->writeln("➤ </><options=bold;fg=green>Username : {$user['username']} </>");
                $this->di->getLog()->logContent('Username : '
                    . json_encode($user['username'], true), 'info', $this->logFile);
                $remoteData = $this->getToken($user['username']);
                if (!$remoteData) {
                    $this->output->writeln("➤ </><options=bold;fg=red>Token not found</>");
                    $this->di->getLog()->logContent('Token not found', 'info', $this->logFile);
                } else {
                    $token = $remoteData['apps'][0]['token'];
                    $remoteShopId = $remoteData['_id'];
                    $graphqlBaseUrl = $this->di->getConfig()->get('graphql_base_url');
                    if (!empty($graphqlBaseUrl)) {
                        $baseWebhookUrl = $this->di->getConfig()->get('graphql_base_url')->get($marketplace);
                    } else {
                        $message = 'graphql_base_url key is missing in config';
                        $this->di->getLog()->logContent('Config Error: ' . $message, 'critical', $this->logFile);
                        return ['success' => false, 'message' => $message];
                    }

                    $createResponse = $this->create($user, $token, $baseWebhookUrl);
                    if (isset($createResponse['success']) && $createResponse['success']) {
                        if (!empty($createResponse['data'])) {
                            $this->unregisterWebhook(
                                $user,
                                $token,
                                $baseWebhookUrl,
                                $remoteShopId,
                                $createResponse['data']
                            );
                        } else {
                            $this->output->writeln("➤ </><options=bold;fg=red>Error From API call </>");
                            $this->di->getLog()->logContent('Error From API call: '
                                . json_encode($user['user_id'], true), 'info', $this->logFile);
                        }
                    } else {
                        $this->output->writeln("➤ </><options=bold;fg=red>{$createResponse['message']}</>");
                        break;
                    }
                }
            }

            $this->output->writeln("➤ </><options=bold;fg=green>Active Page: {$activePage} Completed</>");
            $this->di->getLog()->logContent('Chunk Completed for Active Page: ' . $activePage, 'info', $this->logFile);
        }
    }

    public function create($userData, $token, $graphqlBaseUrl)
    {
        $storeUrl = $userData['username'];
        $userId = $userData['user_id'];
        $marketplace = 'shopify';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $webhookQuery = $mongo->getCollectionForTable('webhook_subscription');
        $webhookData = [];
        $webhooks = [];
        $errors = [];
        if(!$this->target) {
            $webhooks[] = 'app_delete';
        } else {
            $getWebhookConfig = $this->di->getConfig()->webhook->get($this->appTag)->toArray();
            $webhooks = array_column($getWebhookConfig, "action");
        }

        if (!empty($webhooks)) {
            $this->output->writeln("➤ </><options=bold;fg=green>Registering Webhooks...</>");
            foreach ($webhooks as $webhook) {
                if ($webhook == 'app_delete') {
                    $webhook = 'APP_UNINSTALLED';
                } else {
                    $webhook = strtoupper((string) $webhook);
                }
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => "https://{$storeUrl}/admin/api/2024-01/graphql.json",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => '
                    mutation {
                        eventBridgeWebhookSubscriptionCreate(
                          topic: ' . $webhook . '
                          webhookSubscription: {
                            arn: "' . $graphqlBaseUrl . '"
                            format: JSON
                          }
                        ) {
                          webhookSubscription {
                            id
                          }
                          userErrors {
                            message
                          }
                        }
                      }',
                    CURLOPT_HTTPHEADER => array(
                        "X-Shopify-Access-Token: $token",
                        'Content-Type: application/graphql'
                    ),
                ));
                $response = json_decode(curl_exec($curl), true);
                curl_close($curl);
                if ($webhook == 'APP_UNINSTALLED') {
                    $webhook = 'app_delete';
                } else {
                    $webhook = strtolower($webhook);
                }

                if (isset($response['data']['eventBridgeWebhookSubscriptionCreate'])) {
                    $eventSubscription = $response['data']['eventBridgeWebhookSubscriptionCreate'];
                    if (isset($eventSubscription['webhookSubscription']['id'])) {
                        $parts = explode('/', (string) $eventSubscription['webhookSubscription']['id']);
                        $filteredParts = array_filter($parts);
                        $webhookData[] = [
                            'code' => $webhook,
                            'marketplace_subscription_id' => end($filteredParts)
                        ];
                    } elseif (
                        !empty($eventSubscription['userErrors'])
                        && str_contains((string) $eventSubscription['userErrors'][0]['message'], "topic has already been taken")
                    ) {
                        $webhookData[] = [
                            'code' => $webhook,
                            'message' => 'Topic Already registered'
                        ];
                    } else {
                        $errors[] = [
                            'code' => $webhook,
                            'error' => $response
                        ];
                    }
                } elseif (isset($response['errors'])) {
                    $errors[] = [
                        'code' => $webhook,
                        'error' => $response['errors']
                    ];
                    break;
                } else {
                    $errors[] = [
                        'code' => $webhook,
                        'error' => $response
                    ];
                }
            }

            $prepareData = [
                'user_id' => $userId,
                'username' => $storeUrl,
                'marketplace' => $marketplace,
                'webhooks' => $webhookData
            ];
            if (!empty($errors)) {
                $prepareData['errors'] = $errors;
            }

            $webhookQuery->insertOne($prepareData);
            return ['success' => true, 'data' => $webhookData];
        }
        $message = 'Unable to fetch webhooks from config';

        return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
    }

    public function unregisterWebhook($userData, $token, $graphqlBaseUrl, $remoteShopId, $graphQlWebhookData): void
    {
        $getWebhookData = $this->getWebhook($userData['username'], $token);
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $userDetails = $mongo->getCollection("user_details");
        $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
        $marketplace = 'shopify';
        $shops = $shopData = $deletedId = $dynamoWebhookIds = $registeredWebhooks = [];
        if (isset($getWebhookData['success']) && $getWebhookData['success']) {
            $response = $this->setDiForUser($userData['user_id']);
            if (isset($response['success']) && $response['success']) {
                $shops = $this->di->getUser()->shops;
                if (!empty($shops)) {
                    foreach ($shops as $shop) {
                        if ($shop['remote_shop_id'] == $remoteShopId) {
                            $shopData = $shop;
                            break;
                        }
                    }

                    if (!empty($shopData)) {
                        $this->output->writeln("➤ </><options=bold;fg=green>Webhook Fetched</>");
                        $this->di->getLog()->logContent('Webhook Fetched', 'info', $this->logFile);
                        $appCode = $shopData['apps'][0]['code'];
                        $queueAppCode = $this->destinationData['app_code'] ?? 'default';
                        foreach ($getWebhookData['data'] as $qlWebhookData) {
                            if ($qlWebhookData['address'] == $graphqlBaseUrl) {
                                $registeredWebhooks[] = $qlWebhookData['topic'];
                                if ($qlWebhookData['topic'] == 'app/uninstalled') {
                                    $destinationId = $this->destinationData['user_app'] ?? '';
                                    $type = 'user_app';
                                    $action = 'app_delete';
                                } else {
                                    $destinationId = $this->destinationData['user'] ?? '';
                                    $type = 'user';
                                    $action = str_replace('/', '_', $qlWebhookData['topic']);
                                }

                                $prepareCifSubscriptionData[] = [
                                    "marketplace" => $marketplace,
                                    "remote_shop_id" => $remoteShopId,
                                    "subscription_id" => (string)$qlWebhookData['id'],
                                    "event_code" => $qlWebhookData['topic'],
                                    "destination_id" => $destinationId ?? '',
                                    "type" => $type ?? '',
                                    "marketplace_data" => [
                                        "call_type" => "QL"
                                    ],
                                    "queue_data" => [
                                        "type" => "full_class",
                                        "class_name" => "\\App\\Connector\\Models\\SourceModel",
                                        "method" => "triggerWebhooks",
                                        "user_id" => $userData['user_id'],
                                        "shop_id" => $shopData['_id'],
                                        "action" => $action,
                                        "queue_name" => $queueAppCode . '_' . $action,
                                        "marketplace" => $marketplace,
                                        "app_code" => $appCode
                                    ],
                                    "app_code" => $appCode

                                ];
                                $prepareMarketplaceSubscriptionData[] = [
                                    "marketplace" => "shopify",
                                    "event_code" => $qlWebhookData['topic'],
                                    "remote_shop_id" => $remoteShopId,
                                    "destination_id" => "test_destination_id",
                                    "marketplace_subscription_id" => (string)$qlWebhookData['id'],
                                    "created_at" => date('Y-m-d H:i:s')
                                ];
                            }
                        }

                        if (!empty($registeredWebhooks)) {
                            $options = [
                                'arrayFilters' => [
                                    ['shop.marketplace' => $shopData['marketplace']]
                                ]
                            ];
                            $this->output->writeln("➤ </><options=bold;fg=green>Unregistering old webhooks...</>");
                            foreach ($getWebhookData['data'] as $webhook) {
                                if (
                                    $webhook['address'] != $graphqlBaseUrl
                                    && in_array($webhook['topic'], $registeredWebhooks)
                                ) {
                                    if ($this->removeWebhook($userData['username'], $webhook['id'], $token)) {
                                        $deletedId[] = $webhook['id'];
                                    } else {
                                        $this->di->getLog()->logContent('Unable to Delete Webhook for User: '
                                            . json_encode($userData['username']) . 'Webhook Id: '
                                            . json_encode($webhook['id']), 'info', $this->logFile);
                                    }
                                }
                            }

                            if (!empty($deletedId)) {
                                $this->output->writeln("➤ </><options=bold;fg=green>Webhook Unregistered Successfully!!</>");
                                $this->output->writeln("➤ </><options=bold;fg=green>Inserting Docs...</>");
                                $requestParams = [
                                    'shop_id' => $remoteShopId,
                                    'app_code' => $appCode,
                                    'cif_data' => $prepareCifSubscriptionData,
                                    'marketplace_data' => $prepareMarketplaceSubscriptionData
                                ];
                                $cedData = $shopifyHelper->sendRequestToShopify(
                                    'cedwebapi/data',
                                    [],
                                    $requestParams,
                                    'POST'
                                );
                                if (!empty($cedData['data'])) {
                                    $userWebhookData = $cedData['data'];
                                } else {
                                    $userWebhookData = $graphQlWebhookData;
                                    $this->output->writeln("➤ </><options=bold;fg=red>Error While Inserting Data into Tables!!</>");
                                    $this->di->getLog()->logContent('Error While Inserting Data into Tables
                                    UserId: ' . json_encode($userData['user_id']) . 'Remote Response: '
                                        . json_encode($cedData), 'info', $this->logFile);
                                }

                                if (!empty($shopData['apps'][0]['webhooks'])) {
                                    $appWebhooks = $shopData['apps'][0]['webhooks'];
                                    $webhookCodes = str_replace('/', '_', $registeredWebhooks);
                                    foreach ($appWebhooks as $appWebData) {
                                        if (
                                            $appWebData['code'] == 'app_delete' &&
                                            in_array('app_uninstalled', $webhookCodes)
                                        ) {
                                            array_push($webhookCodes, "app_delete");
                                            $dynamoWebhookIds[] = $appWebData['dynamo_webhook_id'];
                                        } else {
                                            if (in_array($appWebData['code'], $webhookCodes)) {
                                                $dynamoWebhookIds[] = $appWebData['dynamo_webhook_id'];
                                            }
                                        }
                                    }

                                    $remoteData = [
                                        'shop_id' => $remoteShopId,
                                        'subscription_ids' => $dynamoWebhookIds,
                                        'app_code' => "cedcommerce",
                                        'force_dynamo_delete' => true
                                    ];
                                    $remoteResponse = $this->di->getObjectManager()
                                        ->get("\App\Connector\Components\ApiClient")
                                        ->init('cedcommerce', true)
                                        ->call('bulkDelete/subscription', [], $remoteData, 'DELETE');
                                    if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                                        $this->output->writeln("➤ </><options=bold;fg=green>Data Deleted From All Tables!!</>");
                                        $userDetails->updateOne(
                                            [
                                                "user_id" => $userData['user_id'],
                                                "shops" => [
                                                    '$elemMatch' => [
                                                        '_id' => $shopData['_id'],
                                                        'marketplace' => $shopData['marketplace']
                                                    ]
                                                ]
                                            ],
                                            [
                                                '$pull' => [
                                                    'shops.$[shop].apps.0.webhooks' => [
                                                        'dynamo_webhook_id' => [
                                                            '$in' => $dynamoWebhookIds
                                                        ]
                                                    ]
                                                ]
                                            ],
                                            $options
                                        );
                                        $this->output->writeln("➤ </><options=bold;fg=green>Docs Inserted and  Updated!!</>");
                                    } else {
                                        $this->output->writeln("➤ </><options=bold;fg=red>Error While Deleting Data from Tables!!</>");
                                        $this->di->getLog()->logContent('Error While Deleting Data from Tables: ' . json_encode($remoteResponse), 'info', $this->logFile);
                                    }
                                } else {
                                    $this->output->writeln("➤ </><options=bold;fg=red>Shop or Webhooks error not found for User: {$userData['user_id']}</>");
                                }

                                $userDetails->updateOne(
                                    [
                                        "user_id" => $userData['user_id'],
                                        "shops" => [
                                            '$elemMatch' => [
                                                '_id' => $shopData['_id'],
                                                'marketplace' => $shopData['marketplace']
                                            ]
                                        ]
                                    ],
                                    [
                                        '$addToSet' => [
                                            'shops.$[shop].apps.0.webhooks' => [
                                                '$each' => $userWebhookData
                                            ]
                                        ]
                                    ],
                                    $options
                                );
                            } else {
                                $this->output->writeln("➤ </><options=bold;fg=red>No Rest Webhook Found</>");
                                $this->di->getLog()->logContent("No Rest Webhook Found UserId: {$userData['user_id']}", 'info', $this->logFile);
                            }
                        } else {
                            $this->output->writeln("➤ </><options=bold;fg=red>No Ql webhooks registered</>");
                        }
                    } else {
                        $this->output->writeln("➤ </><options=bold;fg=red>Unable find shop user_id: {$userData['user_id']}</>");
                    }
                }
            } else {
                $this->output->writeln("➤ </><options=bold;fg=red>Unable to set Di user_id: {$userData['user_id']}</>");
            }
        } else {
            $this->output->writeln("➤ </><options=bold;fg=red>{$getWebhookData['message']}</>");
        }
    }

    public function getWebhook($storeUrl, $token)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://{$storeUrl}/admin/api/2023-07/webhooks.json",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                "X-Shopify-Access-Token: $token"
            ),
        ));

        $response = json_decode(curl_exec($curl), true);
        curl_close($curl);
        if (isset($response['webhooks']) && !empty($response['webhooks'])) {
            return ['success' => true, 'data' => $response['webhooks']];
        }
        $this->di->getLog()->logContent(' Error Response: ' .
            json_encode($response), 'info', $this->logFile);
        return ['success' => false, 'message' => 'Unable to fetch Webhooks'];
    }

    public function removeWebhook($storeUrl, $id, $token)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://{$storeUrl}/admin/api/2024-01/graphql.json",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '
            mutation {
                webhookSubscriptionDelete(id: "gid://shopify/WebhookSubscription/' . $id . '") {
                    deletedWebhookSubscriptionId
                    userErrors {
                    field
                    message
                    }
                }
                }',
            CURLOPT_HTTPHEADER => array(
                "X-Shopify-Access-Token: $token",
                'Content-Type: application/graphql'
            ),
        ));
        $response = json_decode((curl_exec($curl)), true);
        curl_close($curl);
        if (isset($response['data']['webhookSubscriptionDelete'])) {
            $deleteSubscription = $response['data']['webhookSubscriptionDelete'];
            if (!empty($deleteSubscription['deletedWebhookSubscriptionId'])) {
                return true;
            }
        }

        return false;
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

    public function getToken($shop)
    {
        $db = $this->di->get('remote_db');
        if ($db) {
            $appsCollections = $db->selectCollection('apps_shop');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
            $appInfo = $appsCollections->findOne(['shop_url' => $shop], $options);
            return $appInfo;
        }
        return false;
    }

    public function registerWebhookForTopic($activePage)
    {
        $logFile = 'shopify/registerSubscription.log';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userCollection = $mongo->getCollectionForTable('user_details');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $testUsers = $this->input->getOption("test_user") ?? 'n';
        $marketplace = 'shopify';
        $topicToRegister = 'app_subscriptions/update';
        $codeToRegister = 'app_subscriptions_update';
        if ($testUsers == 'y') {
            $users = $this->di->getConfig()->get('test_webhook_subscription_users');
            if (!empty($users)) {
                $users->toArray();
                $pipline = [
                    [
                        '$match' => [
                            'user_id' => ['$in' => $users],
                            'shops' => [
                                '$elemMatch' => [
                                    'apps.app_status' => 'active',
                                    'marketplace' => $this->target
                                ],
                            ],
                        ]
                    ],
                    ['$project' => ['user_id' => 1, 'username' => 1]]
                ];
            } else {
                return false;
            }
        } else {
            $limit = 5;
            if (!$activePage) {
                $activePage = 1;
            }

            $page = $activePage;
            $offset = ($page - 1) * $limit;
            $pipline = [
                [
                    '$match' => [
                        'shops' => [
                            '$elemMatch' => [
                                'apps.app_status' => 'active',
                                'marketplace' => $this->target
                            ],
                        ],
                    ]
                ],
                ['$project' => ['user_id' => 1, 'username' => 1]],
                ['$skip' => (int) $offset],
                ['$limit' => $limit]
            ];
        }

        $userData = $userCollection->aggregate($pipline, $arrayParams)->toArray();
        if (!empty($userData)) {
            foreach ($userData as $user) {
                $this->di->getLog()->logContent('Processing user: '.$user['user_id'], 'info', $logFile);
                $res = $this->setDiForUser($user['user_id']);
                if(isset($res['success']) && $res['success']) {
                    $this->output->writeln("➤ </><options=bold;fg=green>Username : {$user['username']} </>");
                    $remoteData = $this->getToken($user['username']);
                    if (!$remoteData) {
                        $this->output->writeln("➤ </><options=bold;fg=red>Token not found</>");
                        $this->di->getLog()->logContent('Token not found', 'info', $logFile);
                    } else {
                        $token = $remoteData['apps'][0]['token'];
                        $getWebhookData = $this->getWebhook($user['username'], $token);
                        $this->di->getLog()->logContent('getWebhookData : ' . json_encode($getWebhookData, true), 'info', $logFile);
                        if(!empty($getWebhookData['data']) && isset($getWebhookData['success']) && $getWebhookData['success']) {
                            $topics = [];
                            foreach($getWebhookData['data'] as $webhook) {
                                $topics[] = $webhook['topic'];
                            }

                            $this->di->getLog()->logContent('topics: '.json_encode($topics, true), 'info', $logFile);
                            if(!in_array($topicToRegister, $topics)) {
                                $this->di->getLog()->logContent('topic not exist! ', 'info', $logFile);
                                $createWebhookInRestRes = $this->createWebhookInRest($user['username'], $token, $topicToRegister);
                                $this->di->getLog()->logContent('createWebhookInRest response: '.json_encode($createWebhookInRestRes, true), 'info', $logFile);
                                if($createWebhookInRestRes['success'] && isset($createWebhookInRestRes['data']['webhook']['id'])) {
                                    $delRes = $this->deleteWebhookInRest($user['username'], $token, $createWebhookInRestRes['data']['webhook']['id']);
                                    $this->di->getLog()->logContent('deleteWebhookInRest response: '.json_encode($delRes, true), 'info', $logFile);
                                    if($delRes['success']) {
                                        $shops = $this->di->getUser()->shops;
                                        $shopData = [];
                                        foreach ($shops as $shop) {
                                            if ($shop['marketplace'] == $marketplace) {
                                                $shopData = $shop;
                                                break;
                                            }
                                        }

                                        if (!empty($shopData)) {
                                            $appCode = $shopData['apps'][0]['code'] ?? 'default';
                                            $object = $this->di->getObjectManager()->get(UserEvent::class);
                                            $createDestinationResponse = $object->createDestination($shopData);
                                            $destinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
                                            if (!$destinationId) {
                                                $this->di->getLog()->logContent('Unable to Create/Get destination user_id!', 'info', $logFile);
                                            } else {
                                                $destinationWise[] = [
                                                    'destination_id' => $destinationId,
                                                    'event_codes' => [
                                                        $codeToRegister
                                                    ]
                                                ];
                                                $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks($shopData, $marketplace, $appCode, false, $destinationWise);
                                                $this->di->getLog()->logContent('Create Webhook Request Response=> ' . json_encode($createWebhookRequest, true), 'info', $logFile);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $this->di->getLog()->logContent('Unable to set di!', 'info', $logFile);
                }
            }

            $this->output->writeln("➤ </><options=bold;fg=green>Active Page: {$activePage} Completed</>");
            $this->di->getLog()->logContent('Chunk Completed for Active Page: ' . $activePage, 'info', $logFile);
        } else {
            $this->di->getLog()->logContent('No users found!', 'info', $logFile);
        }
    }

    public function createWebhookInRest($storeUrl, $token, $webhookTopic)
    {
        $curl = curl_init();
        $webhook_callback_url = 'https://testapisshopify.free.beeceptor.com';

        curl_setopt_array($curl, array(
        CURLOPT_URL => "https://{$storeUrl}/admin/api/2023-07/webhooks.json",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode(array(
            'webhook' => array(
            'topic' => $webhookTopic,
            'address' => $webhook_callback_url,
            'format' => 'json'
            )
        )),
        CURLOPT_HTTPHEADER => array(
            "X-Shopify-Access-Token: $token",
            "Content-Type: application/json",
        ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
         return [
            'success' => false,
            'error' => $err
         ];
        }
        return [
            'success' => true,
            'data' => json_decode($response, true)
         ];
    }

    public function deleteWebhookInRest($storeUrl, $token, $webhookId)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://{$storeUrl}/admin/api/2023-07/webhooks/$webhookId.json",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "DELETE",
            CURLOPT_HTTPHEADER => array(
                "X-Shopify-Access-Token: $token",
                "Content-Type: application/json",
            ),
          ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
         return [
            'success' => false,
            'error' => $err
         ];
        }
        return [
            'success' => true,
            'data' => json_decode($response, true)
         ];
    }

    public function removeAppLevelIfOnlySourceConnected()
    {
        $logFile = "shopify/script/" . date('d-m-Y') . '.log';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetails = $mongo->getCollectionForTable('user_details');
        $filter = [
            'shops' => ['$size' => 1],
            'shops.0.marketplace' => 'shopify',
            'shops.0.plan_name' => ['$nin' => ['cancelled', 'frozen', 'fraudulent']],
            'shops.0.apps.webhooks' => [
                '$exists' => true,
                '$not' => ['$size' => 1]
            ]
        ];
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $userData = $userDetails->find($filter, $arrayParams)->toArray();

        if (!empty($userData)) {
            foreach ($userData as $user) {
                $userId = $user['user_id'];
                $this->di->getLog()->logContent('Starting process for user_id' . json_encode($userId), 'info', $logFile);
                foreach ($user['shops'] as $shop) {
                    $webhookIds = [];
                    $dynamoWebhookIds = [];
                    if ($shop['marketplace'] == 'shopify') {
                        $response = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                            ->init('shopify', true)
                            ->call('webhook', [], ['shop_id' => $shop['remote_shop_id']], 'GET');
                        if (isset($response['success']) && $response['success'] && !empty($response['data'])) {
                            foreach ($response['data'] as $webhook) {
                                if ($webhook['topic'] != 'app/uninstalled') {
                                    $webhookIds[] = $webhook['id'];
                                }
                            }
                        } else {
                            $this->di->getLog()->logContent('Unable to fetch webhook data', 'info', $logFile);
                        }
                        if (!empty($webhookIds)) {
                            $this->di->getLog()->logContent('App level webhooks found', 'info', $logFile);
                            $appCode = $shop['apps'][0]['code'];
                            $specifics = [
                                'shop_id' => $shop['remote_shop_id'],
                                'app_code' => $appCode,
                                'ids' => implode(",", $webhookIds)
                            ];
                            $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                                ->init('shopify', true)
                                ->call('webhook', [], $specifics, 'DELETE');
                            $this->di->getLog()->logContent('Webhook Unregistered', 'info', $logFile);
                            if (!empty($shop['apps'][0]['webhooks'])) {
                                foreach ($shop['apps'][0]['webhooks'] as $webhookData) {
                                    if (isset($webhookData['code']) && $webhookData['code'] != 'app_delete' && $webhookData['dynamo_webhook_id']) {
                                        $dynamoWebhookIds[] = $webhookData['dynamo_webhook_id'];
                                    }
                                }
                                if (!empty($dynamoWebhookIds)) {
                                    $remoteData = [
                                        'shop_id' => $shop['remote_shop_id'],
                                        'subscription_ids' => $dynamoWebhookIds,
                                        'force_dynamo_delete' => true,
                                        'app_code' => "cedcommerce"
                                    ];
                                    $this->di->getLog()->logContent('Deleting webhook data from app', 'info', $logFile);
                                    $bulkDeleteSubscriptionResponse = $this->di->getObjectManager()
                                        ->get("\App\Connector\Components\ApiClient")
                                        ->init('cedcommerce', true)
                                        ->call('bulkDelete/subscription', [], $remoteData, 'DELETE');
                                    if (isset($bulkDeleteSubscriptionResponse['success']) && $bulkDeleteSubscriptionResponse['success']) {
                                        $this->di->getLog()->logContent('Bulk Delete Subscription Response Error: '
                                            . json_encode([
                                                'shop' => $shop,
                                                'remote_response' => $bulkDeleteSubscriptionResponse
                                            ]), 'info', $logFile);
                                    }
                                    $userDetails->updateOne(
                                        [
                                            "user_id" => $userId,
                                            "shops" => [
                                                '$elemMatch' => [
                                                    '_id' => $shop['_id'],
                                                    'marketplace' => $shop['marketplace']
                                                ]
                                            ]
                                        ],
                                        [
                                            '$pull' => [
                                                'shops.$[shop].apps.0.webhooks' => [
                                                    'dynamo_webhook_id' => [
                                                        '$in' => $dynamoWebhookIds
                                                    ]
                                                ]
                                            ]
                                        ],
                                        [
                                            'arrayFilters' => [
                                                ['shop.marketplace' => $shop['marketplace']]
                                            ]
                                        ]
                                    );
                                } else {
                                    $this->di->getLog()->logContent('Dynamo WebhookIds not found: '
                                        . json_encode([
                                            'user_id' => $userId,
                                            'shop' => $shop,
                                        ]), 'info', $logFile);
                                }
                            } else {
                                $this->di->getLog()->logContent('Empty Webhooks array: '
                                    . json_encode([
                                        'user_id' => $userId,
                                        'shop' => $shop,
                                    ]), 'info', $logFile);
                            }
                        }
                    }
                }
            }
        }
    }
}
