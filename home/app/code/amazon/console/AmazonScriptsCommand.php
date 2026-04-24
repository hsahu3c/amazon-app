<?php

use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\ShopEvent;
use App\Connector\Components\Dynamo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Core\Models\User;
use Aws\DynamoDb\Marshaler;
use MongoDB\BSON\UTCDateTime;
use Exception;

class AmazonScriptsCommand extends Command
{

    protected static $defaultName = "amazon-scripts";

    protected static $defaultDescription = "To execute random scripts";
    protected $di;
    protected $input;
    protected $output;
    public $logFile;
    private $lastTraversedObjectId;
    private $lastTraversedShopId;
    private $jsonAttributeSourceProductWiseData;

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
        $this->addOption("source", "s", InputOption::VALUE_OPTIONAL, "source marketplace required for some methods");
        $this->addOption("container", "c", InputOption::VALUE_OPTIONAL, "container_name");
        $this->addOption("app_tag", "t", InputOption::VALUE_OPTIONAL, "app_tag");
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
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Amazon Scripts...</>');
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
        $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Process Completed</>');
        return 0;
    }

    public function sendMailToInvalidTokenUsers()
    {
        $logFile = "amazon/script/" . date('d-m-Y') . '.log';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $invalidTokenUsers = $mongo->getCollectionForTable('invalid_token_users');
        $pipeline = [
            [
                '$match' => [
                    'picked' => ['$exists' => false]
                ]
            ],
            [
                '$unwind' => '$shops'
            ],
            [
                '$group' => [
                    '_id' => '$user_id',
                    'shops' => [
                        '$push' => '$shops.warehouses'
                    ],
                    'username' => [
                        '$first' => '$username'
                    ],
                    'email' => [
                        '$first' => '$email'
                    ]
                ]
            ],
            [
                '$project' => [
                    '_id' => 0,
                    'user_id' => '$_id',
                    'username' => '$username',
                    'email' => '$email',
                    'shops' => [
                        '$reduce' => [
                            'input' => '$shops',
                            'initialValue' => [],
                            'in' => [
                                '$concatArrays' => ['$$value', '$$this']
                            ]
                        ]
                    ]
                ]
            ],
            ['$limit' => 200],
            ['$sort' => ['user_id' => 1]]
        ];

        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $userData = $invalidTokenUsers->aggregate($pipeline, $arrayParams)->toArray();
        if (!empty($userData)) {
            $csvData = [];
            $counter = 0;
            foreach ($userData as $data) {
                $this->di->getLog()->logContent('Starting process for user: ' . $data['username'], 'info', $logFile);
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> ' . $data['username'] . '</>');
                $csvData = [];
                $csvData[] = ['Sno.', 'Seller Id', 'Country', 'Account Status'];
                $counter = 0;
                $msgData = [
                    'app_name' => $this->di->getConfig()->app_name,
                    'name' => $data['username'],
                    'email' => $data['email'],
                    'subject' => 'Re-authorize Your Amazon Account'
                ];
                foreach ($data['shops'] as $shop) {
                    ++$counter;
                    $marketplaceName = \App\Amazon\Components\Common\Helper::MARKETPLACE_NAME[$shop['marketplace_id']];
                    $csvData[] = [$counter, $shop['seller_id'], $marketplaceName, 'Inactive'];
                }
                $csvContent = '';
                foreach ($csvData as $row) {
                    $csvContent .= implode(',', $row) . PHP_EOL;
                }
                $fileName = $data['user_id'] . '_invalid_token_users';
                $fileLocation = BP . DS . 'var' . DS . 'log' .  DS . 'script' . DS . $fileName;
                $csvFilePath = "{$fileLocation}.csv";

                if (!file_exists($csvFilePath)) {
                    $dirname = dirname($csvFilePath);
                    if (!is_dir($dirname)) {
                        mkdir($dirname, 0777, true);
                    }

                    file_put_contents($csvFilePath, $csvContent);
                }
                $msgData['path'] = 'amazon' . DS . 'view' . DS . 'email' . DS . 'Reauthorize.volt';
                $msgData['content'] = 'Download Attach File';
                $msgData['files'] = [
                    [
                        'name' =>  'amazon-accounts',
                        'path' => $csvFilePath
                    ]
                ];
                $mailResponse = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($msgData);
                if ($mailResponse) {
                    $this->di->getLog()->logContent('Mail send successfully!!', 'info', $logFile);
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Mail send successfully!!</>');
                } else {
                    $this->di->getLog()->logContent('Error while sending mail response: ' . json_encode($mailResponse), 'info', $logFile);
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to send mail</>');
                }
                unlink($csvFilePath);
            }
            $processedUserIds = array_column($userData, 'user_id');
            $invalidTokenUsers->updateMany(
                ['user_id' => ['$in' => $processedUserIds]],
                ['$set' => ['picked' => true]]
            );
        } else {
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=red> All Users Processed</>');
        }
    }

    public function validateAmazonOrderWebhook()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $orderCollection = $mongo->getCollectionForTable('amazon_order_ids');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $pipline = [
            [
                '$match' => [
                    'processType' => 'CRON'
                ]
            ],
            ['$project' => ['user_id' => 1, 'home_shop_id' => 1]],
            ['$group' => ['_id' => '$user_id', 'shops' => ['$addToSet' => '$home_shop_id']]]
        ];
        $orderData = $orderCollection->aggregate($pipline, $arrayParams)->toArray();
        if (!empty($orderData)) {
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $maxMessageCount = 9;
            $handlerData = [];
            foreach ($orderData as $data) {
                $handlerData[] = [
                    'type' => 'full_class',
                    'class_name' => \App\Amazon\Components\Manual\Helper::class,
                    'method' => 'validateAmazonOrderChangeWebhook',
                    'queue_name' => 'validate_order_change_webhook',
                    'shops' => $data['shops'],
                    'user_id' => $data['_id']
                ];
                if (count($handlerData) == $maxMessageCount) {
                    $sqsHelper->pushMessagesBatch($handlerData);
                    $handlerData = [];
                }
            }
            if (!empty($handlerData)) {
                $sqsHelper->pushMessagesBatch($handlerData);
            }
        }
    }

    public function checkAmazonAccountStatus()
    {
        $this->di->getObjectManager()->get(\App\Amazon\Components\Shop\Hook::class)
            ->checkAmazonAccountStatus();
        return 1;
    }

    public function removeUsersIfSourceNotExists()
    {
        $source = $this->input->getOption("source") ?? false;
        if (!$source) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><fg=red>To execute this method source field is required(-s)</>');
            return 0;
        }
        $logFile = "amazon/script/" . date('d-m-Y') . '.log';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsContainer = $mongo->getCollectionForTable('user_details');
        $options = [
            "projection" => ['_id' => 0, 'user_id' => 1, 'shops' => 1, 'username' => 1],
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        $userData = $userDetailsContainer->find(
            [
                'shops' => [
                    '$exists' => true,
                    '$ne' => []
                ],
                'shops.marketplace' => [
                    '$ne' => $source
                ]
            ],
            $options
        )->toArray();
        if (!empty($userData)) {
            $shopEvent = $this->di->getObjectManager()->get(ShopEvent::class);
            foreach ($userData as $user) {
                $userId = $user['user_id'];
                $this->di->getLog()->logContent('Starting Process for User: ' . json_encode($userId), 'info',  $logFile);
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    foreach ($user['shops'] as $shopData) {
                        $preparedData = [
                            'user_id' => $userId,
                            'shop_id' => $shopData['_id']
                        ];
                        $shopEvent->sendRemovedShopToAws($preparedData);
                        $appCode = $shopData['apps'][0]['code'];
                        if (!empty($shopData['apps'][0]['webhooks'])) {
                            $dynamoWebhookIds = array_column($shopData['apps'][0]['webhooks'], 'dynamo_webhook_id');
                            $remoteData = [
                                'shop_id' => $shopData['remote_shop_id'],
                                'subscription_ids' => $dynamoWebhookIds,
                                'app_code' => "cedcommerce"
                            ];
                            $bulkDeleteSubscriptionResponse = $this->di->getObjectManager()
                                ->get("\App\Connector\Components\ApiClient")
                                ->init('cedcommerce', true)
                                ->call('bulkDelete/subscription', [], $remoteData, 'DELETE');
                            $this->di->getLog()->logContent('bulkDeleteSubscriptionResponse: ' . json_encode($bulkDeleteSubscriptionResponse), 'info',  $logFile);
                        } else {
                            $this->di->getLog()->logContent('Webhooks not found for shop: ' . json_encode($shopData['_id']), 'info',  $logFile);
                        }
                        $appShopDeleteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                            ->init($shopData['marketplace'], false,  $appCode)
                            ->call('app-shop', [], ['shop_id' => $shopData['remote_shop_id']], 'DELETE');
                        $this->di->getLog()->logContent('appShopDeleteResponse: ' . json_encode($appShopDeleteResponse), 'info',  $logFile);
                    }
                } else {
                    $this->di->getLog()->logContent('Unable to setDi for user: ' . json_encode($userId), 'info',  $logFile);
                }
                $userDetailsContainer->deleteOne(['user_id' => $userId]);
            }
        } else {
            $this->di->getLog()->logContent('All users processed', 'info',  $logFile);
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

    public function checkIfRefineProductExistInProductContainer()
    {
        $logFile = 'amazon/script/' . date('d-m-Y') . '.log';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $activePage = 1;
        $this->lastTraversedObjectId = '';
        $productContainer = $mongo->getCollectionForTable('product_container');
        $refineContainer = $mongo->getCollectionForTable('refine_product');
        $cronTask = $mongo->getCollectionForTable('cron_tasks');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $productExistsInRefineContainerOnly = $mongo->getCollectionForTable('product_exists_in_refine_container_only');
        $userData = $this->fetchUserForProcessing($activePage, 'product_exists_in_refine_container_only');
        $sourceMarketplace = 'shopify';
        while (!empty($userData)) {
            $logFile = "amazon/script/" . date('d-m-Y') . '/page-' . $activePage . '.log';
            $lastProcessedUserId = '';
            foreach ($userData as $user) {
                $userId = $user['user_id'];
                if (!empty($user['shops'])) {
                    $sourceShop = $pipeline = [];
                    foreach ($user['shops'] as $shop) {
                        if ($shop['marketplace'] === $sourceMarketplace) {
                            $sourceShop = $shop;
                            break;
                        }
                    }
                    if (!empty($this->lastTraversedObjectId)) {
                        $matchPipeline = [
                            '$match' => [
                                'user_id' => $userId,
                                'source_shop_id' => $sourceShop['_id'],
                                '_id' => ['$gte' => new \MongoDB\BSON\ObjectId($this->lastTraversedObjectId)]
                            ]
                        ];
                    } else {
                        $matchPipeline = [
                            '$match' => [
                                'user_id' => $userId,
                                'source_shop_id' => $sourceShop['_id'],
                            ]
                        ];
                    }
                    if (!empty($sourceShop)) {
                        $this->di->getLog()->logContent('Starting process for user: ' . json_encode($user['user_id']), 'info', $logFile);
                        $pipeline = [
                            $matchPipeline,
                            [
                                '$limit' => 2000
                            ],
                            [
                                '$unwind' => '$items'
                            ],
                            [
                                '$group' => [
                                    '_id' => '$container_id',
                                    'uniqueSourceProductIds' => [
                                        '$addToSet' => '$items.source_product_id'
                                    ],
                                    'object_id' => ['$first' => '$_id']
                                ]
                            ],
                            [
                                '$project' => [
                                    'object_id' => 1,
                                    'uniqueSourceProductIds' => 1
                                ]
                            ],
                            [
                                '$sort' => ['_id' => 1]
                            ]

                        ];
                        $refineProducts = $refineContainer->aggregate($pipeline, $arrayParams)->toArray();
                        while (!empty($refineProducts)) {
                            $lastProcessedObjectId = '';
                            $containerIdCount = 0;
                            foreach ($refineProducts as $refineProduct) {
                                $missingSourceProductIds = [];
                                ++$containerIdCount;
                                $productContainerIds = $productContainer->distinct(
                                    'source_product_id',
                                    [
                                        'user_id' => $userId,
                                        'shop_id' => $sourceShop['_id'],
                                        'source_marketplace' => $sourceMarketplace,
                                        'container_id' => $refineProduct['_id'],
                                        'source_product_id' => ['$in' => $refineProduct['uniqueSourceProductIds']]
                                    ]
                                );
                                if (empty($productContainerIds)) {
                                    $missingSourceProductIds = $refineProduct['uniqueSourceProductIds'];
                                } else {
                                    $missingSourceProductIds = array_values(array_diff($refineProduct['uniqueSourceProductIds'], $productContainerIds));
                                }
                                if (!empty($missingSourceProductIds)) {
                                    $preparedData = [
                                        'user_id' => $userId,
                                        'source_shop_id' => $sourceShop['_id'],
                                        'container_id' => $refineProduct['_id'],
                                        'source_marketplace' => $sourceMarketplace,
                                        'source_product_ids' => $missingSourceProductIds,
                                        'created_at' => date('c')
                                    ];
                                    $filter[] =
                                        [
                                            'updateOne' => [
                                                [
                                                    'user_id' => $userId,
                                                    'source_shop_id' => $sourceShop['_id'],
                                                    'container_id' => $refineProduct['_id'],
                                                    'source_marketplace' => $sourceMarketplace
                                                ],
                                                [
                                                    '$set' => $preparedData
                                                ],
                                                ['upsert' => true]
                                            ],
                                        ];
                                }
                                if ($containerIdCount == 100) {
                                    $cronTask->updateOne(
                                        ['task' => 'product_exists_in_refine_container_only'],
                                        [
                                            '$set' => ['last_traversed_user_id' => $userId, 'last_traversed_object_id' => (string)$refineProduct['object_id']],
                                        ],
                                        ['upsert' => true]
                                    );
                                    $containerIdCount = 0;
                                    if (!empty($filter)) {
                                        $productExistsInRefineContainerOnly->bulkWrite($filter);
                                        $filter = [];
                                    }
                                }
                                $lastProcessedObjectId = (string)$refineProduct['object_id'];
                            }
                            if (!empty($filter)) {
                                $productExistsInRefineContainerOnly->bulkWrite($filter);
                            }
                            $pipeline = [
                                [
                                    '$match' => [
                                        'user_id' => $userId,
                                        'source_shop_id' => $sourceShop['_id'],
                                        '_id' => ['$gt' => new \MongoDB\BSON\ObjectId($lastProcessedObjectId)]
                                    ]
                                ],
                                [
                                    '$limit' => 2000
                                ],
                                [
                                    '$unwind' => '$items'
                                ],
                                [
                                    '$group' => [
                                        '_id' => '$container_id',
                                        'uniqueSourceProductIds' => [
                                            '$addToSet' => '$items.source_product_id'
                                        ],
                                        'object_id' => ['$first' => '$_id']
                                    ]
                                ],
                                [
                                    '$project' => [
                                        'object_id' => 1,
                                        'uniqueSourceProductIds' => 1
                                    ]
                                ],
                                [
                                    '$sort' => ['_id' => 1]
                                ]

                            ];
                            $refineProducts = $refineContainer->aggregate($pipeline, $arrayParams)->toArray();
                        }
                    } else {
                        $this->di->getLog()->logContent('Source shop not found', 'info', $logFile);
                    }
                }
                $lastProcessedUserId =  $userId;
                $cronTask->updateOne(
                    ['task' => 'product_exists_in_refine_container_only'],
                    [
                        '$set' => [
                            'last_traversed_user_id' => $lastProcessedUserId,
                            'completed_page' => $activePage
                        ],
                        '$unset' => ['last_traversed_object_id' => 1]
                    ],
                    ['upsert' => true]
                );
            }
            ++$activePage;
            $userData = $this->fetchUserForProcessing($activePage, 'product_exists_in_refine_container_only', $lastProcessedUserId, true);
        }
    }

    public function checkIfProductExistInRefineContainer()
    {
        $logFile = 'amazon/script/' . date('d-m-Y') . '.log';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $activePage = 1;
        $productContainer = $mongo->getCollectionForTable('product_container');
        $refineContainer = $mongo->getCollectionForTable('refine_product');
        $cronTask = $mongo->getCollectionForTable('cron_tasks');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $productExistsInProductContainerOnly = $mongo->getCollectionForTable('product_exists_in_product_container_only');
        $this->lastTraversedObjectId = '';
        $userData = $this->fetchUserForProcessing($activePage, 'product_exists_in_product_container_only');
        $sourceMarketplace = 'shopify';
        $targetMarketplace = 'amazon';
        while (!empty($userData)) {
            $logFile = "amazon/script/" . date('d-m-Y') . '/page-' . $activePage . '.log';
            $lastProcessedUserId = '';
            foreach ($userData as $user) {
                $userId = $user['user_id'];
                $this->di->getLog()->logContent('Starting Process for user: ' . json_encode($user['user_id']), 'info', $logFile);
                if (!empty($user['shops'])) {
                    $sourceShop = $targetShopIds = $pipeline = [];
                    foreach ($user['shops'] as $shop) {
                        if ($shop['marketplace'] === $sourceMarketplace) {
                            $sourceShop = $shop;
                        } else {
                            $targetShopIds[] = $shop['_id'];
                        }
                    }
                    if (!empty($sourceShop) && !empty($targetShopIds)) {
                        if (!empty($this->lastTraversedObjectId)) {
                            $matchPipeline = [
                                '$match' => [
                                    'user_id' => $userId,
                                    'shop_id' => $sourceShop['_id'],
                                    'source_marketplace' => $sourceMarketplace,
                                    '_id' => ['$gte' => $this->lastTraversedObjectId]
                                ]
                            ];
                        } else {
                            $matchPipeline =  [
                                '$match' => [
                                    'user_id' => $userId,
                                    'shop_id' => $sourceShop['_id'],
                                    'source_marketplace' => $sourceMarketplace
                                ]
                            ];
                        }
                        $pipeline = [
                            $matchPipeline,
                            [
                                '$sort' => ['_id' => 1]
                            ],
                            [
                                '$limit' => 1000
                            ],
                            [
                                '$group' => [
                                    '_id' => '$container_id',
                                    'uniqueSourceProductIds' => [
                                        '$addToSet' => '$source_product_id'
                                    ],
                                    'object_id' => ['$first' => '$_id'],
                                ]
                            ],
                            [
                                '$sort' => ['object_id' => 1]
                            ],
                            [
                                '$project' => [
                                    'object_id' => 1,
                                    'uniqueSourceProductIds' => 1
                                ]
                            ]

                        ];
                        $productContainerProducts = $productContainer->aggregate($pipeline, $arrayParams)->toArray();
                        while (!empty($productContainerProducts)) {
                            $containerIdCount = 0;
                            foreach ($productContainerProducts as $product) {
                                $refinePipeline = [
                                    [
                                        '$match' => [
                                            'user_id' => $userId,
                                            'source_shop_id' => $sourceShop['_id'],
                                            'target_shop_id' => ['$in' => $targetShopIds],
                                            'container_id' => $product['_id']
                                        ]
                                    ],
                                    [
                                        '$unwind' => '$items'
                                    ],
                                    [
                                        '$group' => [
                                            '_id' => '$target_shop_id',
                                            'uniqueSourceProductIds' => [
                                                '$addToSet' => '$items.source_product_id'
                                            ]
                                        ]
                                    ],
                                    [
                                        '$project' => [
                                            'uniqueSourceProductIds' => 1
                                        ]
                                    ]
                                ];
                                $refineContainerData = $refineContainer->aggregate($refinePipeline, $arrayParams)->toArray();
                                foreach ($refineContainerData as $refineProduct) {
                                    $sourceProductIds = [];
                                    if (in_array($refineProduct['_id'], $targetShopIds)) {
                                        if (empty($refineProduct['uniqueSourceProductIds'])) {
                                            $sourceProductIds = $product['uniqueSourceProductIds'];
                                        } else {
                                            $missingSourceProductIds = array_values(array_diff($product['uniqueSourceProductIds'], $refineProduct['uniqueSourceProductIds']));
                                            if (!empty($missingSourceProductIds)) {
                                                $sourceProductIds = $missingSourceProductIds;
                                            }
                                        }
                                    } else {
                                        $sourceProductIds = $product['uniqueSourceProductIds'];
                                    }
                                    if (!empty($sourceProductIds)) {
                                        $preparedData = [
                                            'user_id' => $userId,
                                            'source_shop_id' => $sourceShop['_id'],
                                            'target_shop_id' => $refineProduct['_id'],
                                            'source_product_ids' => $sourceProductIds,
                                            'source_marketplace' => $sourceMarketplace,
                                            'target_marketplace' => $targetMarketplace,
                                            'container_id' => $product['_id'],
                                            'created_at' => date('c')
                                        ];
                                        $filter[] =
                                            [
                                                'updateOne' => [
                                                    [
                                                        'user_id' => $userId,
                                                        'source_shop_id' => $preparedData['source_shop_id'],
                                                        'target_shop_id' => $refineProduct['_id'],
                                                        'container_id' => $product['_id']
                                                    ],
                                                    ['$set' => $preparedData],
                                                    ['upsert' => true]
                                                ],
                                            ];
                                    }
                                }
                                ++$containerIdCount;
                                if ($containerIdCount == 100) {
                                    $cronTask->updateOne(
                                        ['task' => 'product_exists_in_product_container_only'],
                                        [
                                            '$set' => ['last_traversed_user_id' => $userId, 'last_traversed_object_id' => $product['object_id']],
                                        ],
                                        ['upsert' => true]
                                    );
                                    $containerIdCount = 0;
                                    if (!empty($filter)) {
                                        $this->di->getLog()->logContent('Inserting missing source_product_id', 'info', $logFile);
                                        $productExistsInProductContainerOnly->bulkWrite($filter);
                                        $filter = [];
                                    }
                                }
                            }
                            if (!empty($filter)) {
                                $this->di->getLog()->logContent('Inserting missing source_product_id', 'info', $logFile);
                                $productExistsInProductContainerOnly->bulkWrite($filter);
                                $filter = [];
                            }
                            $lastProductData = end($productContainerProducts);
                            $lastProcessedObjectId = $lastProductData['object_id'];
                            $pipeline = [
                                [
                                    '$match' => [
                                        'user_id' => $userId,
                                        'shop_id' => $sourceShop['_id'],
                                        'source_marketplace' => $sourceMarketplace,
                                        '_id' => ['$gt' => $lastProcessedObjectId]
                                    ]
                                ],
                                [
                                    '$sort' => ['_id' => 1]
                                ],
                                [
                                    '$limit' => 1000
                                ],
                                [
                                    '$group' => [
                                        '_id' => '$container_id',
                                        'uniqueSourceProductIds' => [
                                            '$addToSet' => '$source_product_id'
                                        ],
                                        'object_id' => ['$first' => '$_id']
                                    ]
                                ],
                                [
                                    '$sort' => ['object_id' => 1]
                                ],
                                [
                                    '$project' => [
                                        'object_id' => 1,
                                        'uniqueSourceProductIds' => 1
                                    ]
                                ]

                            ];
                            $productContainerProducts = $productContainer->aggregate($pipeline, $arrayParams)->toArray();
                        }
                    } else {
                        $this->di->getLog()->logContent('source or target shop not found for user: ' . json_encode($user['user_id']), 'info', $logFile);
                    }
                }
                $lastProcessedUserId =  $userId;
                $cronTask->updateOne(
                    ['task' => 'product_exists_in_product_container_only'],
                    [
                        '$set' => [
                            'last_traversed_user_id' => $lastProcessedUserId,
                            'completed_page' => $activePage
                        ],
                        '$unset' => ['last_traversed_object_id' => 1]
                    ],
                    ['upsert' => true]
                );
            }
            ++$activePage;
            $userData = $this->fetchUserForProcessing($activePage, 'product_exists_in_product_container_only', $lastProcessedUserId, true);
        }
    }

    public function fetchUserForProcessing(&$activePage, $task, $lastProcessedUserId = null, $lastUserFinished = false)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetails = $mongo->getCollectionForTable('user_details');
        if (is_null($lastProcessedUserId)) {
            $cronTask = $mongo->getCollectionForTable('cron_tasks');
            $taskData = $cronTask->findOne(['task' => $task], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            if (!empty($taskData)) {
                $activePage = ++$taskData['completed_page'];
                $lastProcessedUserId = $taskData['last_traversed_user_id'];
                if (!empty($taskData['last_traversed_object_id'])) {
                    $this->lastTraversedObjectId = $taskData['last_traversed_object_id'];
                } else {
                    $lastUserFinished = true;
                }
            }
        }
        if ($task == 'amazon_webhook_version_update') {
            $lastUserFinished = false;
        }
        if (!empty($lastProcessedUserId)) {
            if ($lastUserFinished) {
                $queryParts = ['$gt' => new \MongoDB\BSON\ObjectId($lastProcessedUserId)];
            } else {
                $queryParts = ['$gte' => new \MongoDB\BSON\ObjectId($lastProcessedUserId)];
            }
            $userData = $userDetails->find(
                [
                    '_id' => $queryParts,
                    'shops' => [
                        '$elemMatch' => [
                            'apps.app_status' => 'active',
                            'marketplace' => 'amazon'
                        ],
                    ]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => 500,
                    'projection' => ['user_id' => 1, 'username' => 1, 'shops' => 1],
                    'sort' => ['_id' => 1]
                ]
            )->toArray();
        } else {
            $userData = $userDetails->find(
                [
                    'shops' => [
                        '$elemMatch' => [
                            'apps.app_status' => 'active',
                            'marketplace' => 'amazon'
                        ],
                    ]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => 500,
                    'projection' => ['user_id' => 1, 'username' => 1, 'shops' => 1],
                    'sort' => ['_id' => 1]
                ]
            )->toArray();
        }
        return $userData;
    }

    public function updateAmazonWebhookPayloadVersion()
    {
        try {
            $this->logFile = "amazon/script/" . date('d-m-Y') . '.log';
            $destinationData = $this->di->getConfig()->get('region_wise_destination_ids') ? $this->di->getConfig()->get('region_wise_destination_ids')->toArray() : [];
            if (empty($destinationData)) {
                $this->di->getLog()->logContent('Unable to fetch destination data from config', 'info', $this->logFile);
                return ['success' => false, 'message' => 'Unable to fetch destination data from config'];
            }
            $dbName = 'remote_db';
            $db = $this->di->get($dbName);
            if ($db) {
                $cifSubscriptionCollection = $db->selectCollection('cif_subscription');
                $marketplaceSubscriptionCollection = $db->selectCollection('marketplace_subscription');
            } else {
                $this->di->getLog()->logContent('Unable to create remote db connection', 'info', $this->logFile);
                return ['success' => false, 'message' => 'Unable to create remote db connection'];
            }
            $marketplace = "amazon";
            $webhookType = 'LISTINGS_ITEM_ISSUES_CHANGE';
            $region = '';
            $activePage = 1;
            $userData = $this->fetchUserForProcessing($activePage, 'amazon_webhook_version_update');
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailCollection = $mongo->getCollectionForTable('user_details');
            $cronTask = $mongo->getCollectionForTable('cron_tasks');
            while (!empty($userData)) {
                $this->logFile = "amazon/script/" . date('d-m-Y') . '/page-' . $activePage . '.log';
                $lastProcessedUserId = '';
                foreach ($userData as $user) {
                    $oldSubscriptionData = [];
                    $newSubscriptionData = [];
                    $userId = $user['user_id'];
                    $this->di->getLog()->logContent('Starting process for UserId: ' . json_encode($userId), 'info', $this->logFile);
                    if (!empty($user['shops'])) {
                        foreach ($user['shops'] as $shop) {
                            $toBeUpdated = false;
                            $toBeDeleted = true;
                            if ($shop['marketplace'] == $marketplace) {
                                if ($shop['apps'][0]['app_status'] == 'active' && $shop['warehouses'][0]['status'] == 'active') {
                                    $region = $shop['warehouses'][0]['region'];
                                    $sellerId = $shop['warehouses'][0]['seller_id'];
                                    $uniqueKey = $region . "_" . $sellerId;
                                    if (!empty($shop['apps'][0]['webhooks'])) {
                                        $webhooks = $shop['apps'][0]['webhooks'];
                                        $getWebhookCodes = array_column($webhooks, 'code');
                                        if (!in_array($webhookType, $getWebhookCodes)) {
                                            if (isset($shop['register_for_order_sync']) && $shop['register_for_order_sync']) {
                                                $this->di->getLog()->logContent('Webhook Code not found initiating routeRegisterWebhook for:  ' . json_encode([
                                                    'shop_id' => $shop['_id'],
                                                    'remote_shop_id' => $shop['remote_shop_id'],
                                                ]), 'info', $this->logFile);
                                                $dataToSent = [
                                                    'user_id' => $userId,
                                                    'target_marketplace' => [
                                                        'marketplace' => $shop['marketplace'],
                                                        'shop_id' => $shop['_id']
                                                    ]
                                                ];
                                                $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                                                    ->createSubscription($dataToSent);
                                            } else {
                                                $this->di->getLog()->logContent('Store Close user found:  ' . json_encode([
                                                    'shop_id' => $shop['_id'],
                                                    'remote_shop_id' => $shop['remote_shop_id'],
                                                ]), 'info', $this->logFile);
                                            }
                                            continue;
                                        }
                                        if (!isset($oldSubscriptionData[$uniqueKey]) && !isset($newSubscriptionData[$uniqueKey])) {
                                            $getSubscriptionResponse = $this->getSubscription($shop, $webhookType);
                                            if (isset($getSubscriptionResponse['success']) && $getSubscriptionResponse['success'] && !empty($getSubscriptionResponse['response']['payload'])) {
                                                $payloadVersion = $getSubscriptionResponse['response']['payload']['payloadVersion'];
                                                $marketplaceSubscriptionId = $getSubscriptionResponse['response']['payload']['subscriptionId'];
                                                if ($payloadVersion == '1.0') {
                                                    $oldSubscriptionData[$uniqueKey] = [
                                                        'marketplace_subscription_id' => $marketplaceSubscriptionId,
                                                        'remote_shop_id' => $shop['remote_shop_id']
                                                    ];
                                                    $toBeUpdated = true;
                                                } else {
                                                    $this->di->getLog()->logContent('Payload version is already updated for: ' . json_encode([
                                                        'shop_id' => $shop['_id'],
                                                        'remote_shop_id' => $shop['remote_shop_id']
                                                    ]), 'info', $this->logFile);
                                                    $newSubscriptionData[$uniqueKey] = $marketplaceSubscriptionId;
                                                    $toBeUpdated = true;
                                                    $toBeDeleted = false;
                                                }
                                            } elseif (isset($getSubscriptionResponse['code']) && $getSubscriptionResponse['code'] == '404') {
                                                $this->di->getLog()->logContent('Requested resource not found for: ' . json_encode([
                                                    'user_id' => $userId,
                                                    'shop_id' => $shop['_id']
                                                ]), 'info', $this->logFile);
                                                $toBeUpdated = true;
                                                $toBeDeleted = false;
                                            } else {
                                                $this->di->getLog()->logContent('getSubscripton(), Error: ' . json_encode($getSubscriptionResponse), 'info', $this->logFile);
                                                $this->insertErrorShop($userId, $shop, $getSubscriptionResponse);
                                            }
                                        } else {
                                            $this->di->getLog()->logContent('Same region shop found: ' . json_encode(['shop_id' => $shop['_id'], 'remote_shop_id' => $shop['remote_shop_id']]), 'info', $this->logFile);
                                            $toBeUpdated = true;
                                            $toBeDeleted = false;
                                        }
                                    } else {
                                        if (isset($shop['register_for_order_sync']) && $shop['register_for_order_sync']) {
                                            $this->di->getLog()->logContent('Webhook Code not found initiating routeRegisterWebhook for:  ' . json_encode([
                                                'shop_id' => $shop['_id'],
                                                'remote_shop_id' => $shop['remote_shop_id'],
                                            ]), 'info', $this->logFile);
                                            $dataToSent = [
                                                'user_id' => $userId,
                                                'target_marketplace' => [
                                                    'marketplace' => $shop['marketplace'],
                                                    'shop_id' => $shop['_id']
                                                ]
                                            ];
                                            $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                                                ->createSubscription($dataToSent);
                                        } else {
                                            $this->di->getLog()->logContent('Store Close user found:  ' . json_encode([
                                                'shop_id' => $shop['_id'],
                                                'remote_shop_id' => $shop['remote_shop_id'],
                                            ]), 'info', $this->logFile);
                                        }
                                        continue;
                                    }
                                    if ($toBeUpdated) {
                                        if (!isset($newSubscriptionData[$uniqueKey])) {
                                            $createSubscriptionResponse = $this->createSubscription($shop, $webhookType, $region, $destinationData);
                                            $this->di->getLog()->logContent('Creating new subscription for: ' . json_encode([
                                                'shop_id' => $shop['_id'],
                                                'remote_shop_id' => $shop['remote_shop_id'],
                                                'createSubscriptionResponse' => $createSubscriptionResponse
                                            ]), 'info', $this->logFile);
                                            if (isset($createSubscriptionResponse['success']) && $createSubscriptionResponse['success']) {
                                                $newSubscriptionData[$uniqueKey] = $createSubscriptionResponse['subscription_id'];
                                            } else {
                                                $this->di->getLog()->logContent('createSubscriptionResponse(), Error: ' . json_encode($createSubscriptionResponse), 'info', $this->logFile);
                                                $this->insertErrorShop($userId, $shop, $createSubscriptionResponse);
                                            }
                                        }
                                        $this->di->getLog()->logContent('Updating subscriptionId for: ' . json_encode([
                                            'shop_id' => $shop['_id'],
                                            'remote_shop_id' => $shop['remote_shop_id'],
                                            'subscription_id' => $newSubscriptionData[$uniqueKey]
                                        ]), 'info', $this->logFile);
                                        $userDetailCollection->updateOne(
                                            [
                                                'user_id' => $userId,
                                                'shops._id' => $shop['_id'],
                                                'shops.apps.webhooks.code' => $webhookType
                                            ],
                                            [
                                                '$set' => [
                                                    'shops.$[shop].apps.$[app].webhooks.$[webhook].marketplace_subscription_id' => $newSubscriptionData[$uniqueKey]
                                                ]
                                            ],
                                            [
                                                'arrayFilters' => [
                                                    ['shop._id' => $shop['_id']],
                                                    ['app.code' => $marketplace],
                                                    ['webhook.code' => $webhookType]
                                                ]
                                            ]
                                        );
                                        $cifSubscriptionCollection->updateOne(
                                            ['remote_shop_id' => $shop['remote_shop_id'], 'event_code' => $webhookType],
                                            [
                                                '$set' => ['subscription_id' => $newSubscriptionData[$uniqueKey]]
                                            ]
                                        );
                                        $marketplaceSubscriptionCollection->updateOne(
                                            ['remote_shop_id' => $shop['remote_shop_id'], 'event_code' => $webhookType],
                                            [
                                                '$set' => ['marketplace_subscription_id' => $newSubscriptionData[$uniqueKey]]
                                            ]
                                        );
                                        if ($toBeDeleted) {
                                            $this->deleteSubscription($webhookType, $oldSubscriptionData);
                                        }
                                    } else {
                                        $this->di->getLog()->logContent('Not updating subscription_id either error in getSubscription response or already latest version is registered: ' . json_encode([
                                            'user_id' => $userId,
                                            'shop_id' => $shop['_id']
                                        ]), 'info', $this->logFile);
                                    }
                                } else {
                                    $this->di->getLog()->logContent('Shop or Warehouse inactive: ' . json_encode([
                                        'user_id' => $userId,
                                        'shop_id' => $shop['_id']
                                    ]), 'info', $this->logFile);
                                    $this->insertErrorShop($userId, $shop, 'Shop or Warehouse inactive');
                                }
                            }
                        }
                    }
                    $lastProcessedUserId =  $userId;
                    $cronTask->updateOne(
                        ['task' => 'amazon_webhook_version_update'],
                        [
                            '$set' => ['last_traversed_user_id' => $lastProcessedUserId, 'completed_page' => $activePage],
                        ],
                        ['upsert' => true]
                    );
                }
                ++$activePage;
                $userData = $this->fetchUserForProcessing($activePage, 'amazon_webhook_version_update', $lastProcessedUserId);
                $this->di->getLog()->logContent('Process Completed for this page', 'info', $this->logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception updateAmazonWebhookPayloadVersion(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }

    private function getSubscription($shop, $webhookType)
    {
        try {
            $specifics = [
                'shop_id' => $shop['remote_shop_id'],
                'notificationtype' => $webhookType
            ];
            $helper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
            return $helper->sendRequestToAmazon('get-subscription', $specifics, 'GET');
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception getSubscription(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }

    private function createSubscription($shop, $webhookType, $region, $destinationData)
    {
        try {
            if (isset($destinationData[$webhookType]) && isset($destinationData[$webhookType][$region])) {
                $destinationId = $destinationData[$webhookType][$region];
                $specifics = [
                    'shop_id' => $shop['remote_shop_id'],
                    'event_code' => $webhookType,
                    'destination_id' => $destinationId,
                    'force_create_new_subscription' => true
                ];
                $helper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
                return $helper->sendRequestToAmazon('create-subscription', $specifics, 'POST');
            } else {
                $this->di->getLog()->logContent('Destination Id not found: ' . json_encode([
                    'webhookType' => $webhookType,
                    'region' => $region
                ]), 'info', $this->logFile);
            }
            return [];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception createSubscription(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }

    private function deleteSubscription($webhookType, $oldSubscriptionData)
    {
        try {
            foreach ($oldSubscriptionData as $data) {
                $specifics = [
                    'shop_id' => $data['remote_shop_id'],
                    'notificationtype' => $webhookType,
                    'subscription_id' => $data['marketplace_subscription_id']
                ];
                $this->di->getLog()->logContent('deleteSubscription specifics : ' . json_encode($specifics), 'info', $this->logFile);
                $helper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
                $deleteSubscriptionResponse = $helper->sendRequestToAmazon('delete-subscription', $specifics, 'DELETE');
                $this->di->getLog()->logContent('deleteSubscriptionResponse Response: ' . json_encode($deleteSubscriptionResponse), 'info', $this->logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception deleteSubscription(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }

    public function removeEventBridgeSubscriptionDataFromDynamo()
    {
        $activePage = 1;
        $userData = $this->fetchUserForProcessing($activePage, 'delete_event_bridge_data_from_dynamo');
        $eventBridgeWebhookTypes = ['LISTINGS_ITEM_STATUS_CHANGE', 'LISTINGS_ITEM_ISSUES_CHANGE', "PRODUCT_TYPE_DEFINITIONS_CHANGE"];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $cronTask = $mongo->getCollectionForTable('cron_tasks');
        $marketplace = 'amazon';
        while (!empty($userData)) {
            $this->logFile = "amazon/script/" . date('d-m-Y') . '/page-' . $activePage . '.log';
            $lastProcessedUserId = '';
            foreach ($userData as $user) {
                $userId = $user['user_id'];
                $this->di->getLog()->logContent('Starting process for UserId: ' . json_encode($userId), 'info', $this->logFile);
                if (!empty($user['shops'])) {
                    foreach ($user['shops'] as $shop) {
                        if ($shop['marketplace'] == $marketplace) {
                            if ($shop['apps'][0]['app_status'] == 'active' && $shop['warehouses'][0]['status'] == 'active') {
                                if (!empty($shop['apps'][0]['webhooks'])) {
                                    $webhooks = $shop['apps'][0]['webhooks'];
                                    foreach ($webhooks as $webhook) {
                                        if (in_array($webhook['code'], $eventBridgeWebhookTypes)) {
                                            $dynamoWebhookId = $webhook['dynamo_webhook_id'] ?? '';
                                            $this->removeWebhookDataFromDynamo($shop, $webhook['code'], $dynamoWebhookId);
                                        }
                                    }
                                } else {
                                    $this->di->getLog()->logContent('Empty webhooks array found for: ' . json_encode([
                                        'user_id' => $userId,
                                        'shop_id' => $shop['_id']
                                    ]), 'info', $this->logFile);
                                }
                            } else {
                                $this->di->getLog()->logContent('Shop or Warehouse inactive: ' . json_encode([
                                    'user_id' => $userId,
                                    'shop_id' => $shop['_id']
                                ]), 'info', $this->logFile);
                            }
                        }
                    }
                }
                $lastProcessedUserId =  $userId;
                $cronTask->updateOne(
                    ['task' => 'delete_event_bridge_data_from_dynamo'],
                    [
                        '$set' => ['last_traversed_user_id' => $lastProcessedUserId, 'completed_page' => $activePage],
                    ],
                    ['upsert' => true]
                );
            }
            ++$activePage;
            $userData = $this->fetchUserForProcessing($activePage, 'delete_event_bridge_data_from_dynamo', $lastProcessedUserId);
            $this->di->getLog()->logContent('Process Completed for this page', 'info', $this->logFile);
        }
    }

    private function removeWebhookDataFromDynamo($shop, $webhookType, $dynamoWebhookId)
    {
        try {
            $tableName = 'webhook';
            $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
            $dynamoClientObj = $dynamoObj->getDetails();
            $remoteShopId = $shop['remote_shop_id'];
            $uniqueKey = $remoteShopId . "_$webhookType";
            $partitionSortKeyData = [
                'PK' => [
                    'S' => "subscription_marketplace",
                ],
                'SK' => [
                    'S' => $uniqueKey . "_marketplace",
                ],
            ];
            $dynamoClientObj->deleteItem([
                'TableName' => $tableName,
                'Key' => $partitionSortKeyData
            ]);
            $uniqueKey = $remoteShopId . "_$webhookType";
            $partitionSortKeyData = [
                'PK' => [
                    'S' => "subscription_cif",
                ],
                'SK' => [
                    'S' => $uniqueKey . "_$dynamoWebhookId",
                ],
            ];
            $dynamoClientObj->deleteItem([
                'TableName' => $tableName,
                'Key' => $partitionSortKeyData
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception removeWebhookDataFromDynamo(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }


    private function insertErrorShop($userId, $shop, $error)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $webhookVersionUpdateCollection = $mongo->getCollectionForTable('webhook_version_update_failed_users');
            $preparedData = [
                'user_id' => $userId,
                'shop_id' => $shop['_id'],
                'marketplace' => $shop['marketplace'],
                'error' => $error,
                'created_at' => date('c')
            ];
            $webhookVersionUpdateCollection->updateOne(
                [
                    'user_id' => $userId,
                    'shop_id' => $shop['_id'],
                    'marketplace' => $shop['marketplace'],
                ],
                [
                    '$set' => $preparedData
                ],
                ['upsert' => true]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception insertErrorShop(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }

    public function saveProductContainerStaleUserData()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productContainer = $mongo->getCollectionForTable('product_container');
        $uninstallUserDetails = $mongo->getCollectionForTable('uninstall_user_details');
        $staleUserContainer = $mongo->getCollectionForTable('stale_user_data');
        $userDetailsContainer = $mongo->getCollectionForTable('user_details');
        $cronTask = $mongo->getCollectionForTable('cron_tasks');
        $limit = 500;
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $task = $cronTask->findOne(['task' => 'stale_user_product_data'], $arrayParams);
        if (!empty($task)) {
            $page = $task['last_completed_page'];
        } else {
            $page = 1;
        }
        $logFile = "save_stale_user_script/" . date('d-m-Y') . "/page- $page.log";

        while (true) {
            $filter = [];
            $offset = ($page - 1) * $limit;
            $pipeline = [
                ['$group' => ['_id' => '$user_id', 'count' => ['$sum' => 1]]],
                ['$sort' => ['_id' => 1]],
                ['$skip' => $offset],
                ['$limit' => $limit]
            ];

            $uninstallUserData = $uninstallUserDetails->aggregate($pipeline, $arrayParams)->toArray();
            if (empty($uninstallUserData)) {
                $this->di->getLog()->logContent('All users processed!!', 'info', $logFile);
                break;
            }
            $fetchedUsers = [];
            foreach ($uninstallUserData as $doc) {
                $fetchedUsers[] = $doc['_id'];
            }

            $foundUserIds = $userDetailsContainer->distinct("user_id", ['user_id' => ['$in' => $fetchedUsers]]);
            $finalIds = array_values(array_diff($fetchedUsers, $foundUserIds));
            if (!empty($finalIds)) {
                $IdsExistsInProductContainer = $productContainer->distinct("user_id", ['user_id' => ['$in' => $finalIds]]);
                $this->di->getLog()->logContent('Stale userIds Found in this chunk: ' . json_encode($IdsExistsInProductContainer), 'info', $logFile);
                foreach ($IdsExistsInProductContainer as $userId) {
                    $preparedData = [
                        'user_id' => $userId,
                        'created_at' => date('c')
                    ];
                    $filter[] =
                        [
                            'updateOne' => [
                                [
                                    'user_id' => $userId,
                                    'toBeDeletedFrom' => 'product_container'
                                ],
                                ['$set' => $preparedData],
                                ['upsert' => true]
                            ],
                        ];
                }
                if (!empty($filter)) {
                    $staleUserContainer->bulkWrite($filter);
                }
            }
            $cronTask->updateOne(
                ['task' => 'stale_user_product_data'],
                [
                    '$set' => ['last_completed_page' => $page],
                ],
                ['upsert' => true]
            );
            ++$page;
        }
    }

    public function saveRefineProductStaleUserData()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $refineContainer = $mongo->getCollectionForTable('refine_product');
        $uninstallUserDetails = $mongo->getCollectionForTable('uninstall_user_details');
        $staleUserContainer = $mongo->getCollectionForTable('stale_user_data');
        $userDetailsContainer = $mongo->getCollectionForTable('user_details');
        $cronTask = $mongo->getCollectionForTable('cron_tasks');
        $limit = 500;
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $task = $cronTask->findOne(['task' => 'stale_user_refine_data'], $arrayParams);
        if (!empty($task)) {
            $page = $task['last_completed_page'];
        } else {
            $page = 1;
        }
        $logFile = "save_stale_user_script/" . date('d-m-Y') . "/page- $page.log";

        while (true) {
            $filter = [];
            $offset = ($page - 1) * $limit;
            $pipeline = [
                ['$group' => ['_id' => '$user_id', 'count' => ['$sum' => 1]]],
                ['$sort' => ['_id' => 1]],
                ['$skip' => $offset],
                ['$limit' => $limit]
            ];

            $uninstallUserData = $uninstallUserDetails->aggregate($pipeline, $arrayParams)->toArray();
            if (empty($uninstallUserData)) {
                $this->di->getLog()->logContent('All users processed!!', 'info', $logFile);
                break;
            }

            $fetchedUsers = [];
            foreach ($uninstallUserData as $doc) {
                $fetchedUsers[] = $doc['_id'];
            }

            $foundUserIds = $userDetailsContainer->distinct("user_id", ['user_id' => ['$in' => $fetchedUsers]]);
            $finalIds = array_values(array_diff($fetchedUsers, $foundUserIds));
            if (!empty($finalIds)) {
                $IdsExistsInRefineContainer = $refineContainer->distinct("user_id", ['user_id' => ['$in' => $finalIds]]);
                $this->di->getLog()->logContent('Stale userIds Found in this chunk: ' . json_encode($IdsExistsInRefineContainer), 'info', $logFile);
                foreach ($IdsExistsInRefineContainer as $userId) {
                    $preparedData = [
                        'user_id' => $userId,
                        'created_at' => date('c')
                    ];
                    $filter[] =
                        [
                            'updateOne' => [
                                [
                                    'user_id' => $userId,
                                    'toBeDeletedFrom' => 'refine_product'
                                ],
                                ['$set' => $preparedData],
                                ['upsert' => true]
                            ],
                        ];
                }
                if (!empty($filter)) {
                    $staleUserContainer->bulkWrite($filter);
                }
            }
            $cronTask->updateOne(
                ['task' => 'stale_user_refine_data'],
                [
                    '$set' => ['last_completed_page' => $page],
                ],
                ['upsert' => true]
            );
            ++$page;
        }
    }

    public function deleteStaleUserDataAction()
    {
        try {
            $container = $this->input->getOption("container") ?? false;
            if (!$container) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Container name (-c) is mandatory</>');
                return;
            }
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $staleUserDataContainer = $mongo->getCollectionForTable('stale_user_data');
            $productContainer = $mongo->getCollectionForTable($container);
            $logFile = "delete_stale_user_data/" . date('d-m-Y') . ".log";
            $staleUserData = $staleUserDataContainer->find(
                [
                    'user_id' => ['$ne' => null],
                    'picked' => ['$exists' => false],
                    'toBeDeletedFrom' => $container
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => 500
                ]
            )->toArray();
            if (!empty($staleUserData)) {
                $processedIds = [];
                foreach ($staleUserData as $data) {
                    $this->di->getLog()->logContent("Deleting Stale user data from $container for user:  => " . json_encode($data['user_id']), 'info', $logFile);
                    $productContainer->deleteMany(['user_id' => $data['user_id']]);
                    $this->di->getLog()->logContent('Data Deleted Successfully!!', 'info', $logFile);
                    $processedIds[] = $data['user_id'];
                }
                $staleUserDataContainer->updateMany(['user_id' => ['$in' => $processedIds]], ['$set' => ['picked' => true]]);
            } else {
                $this->di->getLog()->logContent("All users processed!!", 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception, deleteStaleUserData => ' . print_r($e->getMessage()), 'info', $logFile);
        }
    }

    /**
     * reattemptFailedUserPayloadVersion function
     * Retring to update payload version of failed users
     * successfully
     * @return null
     */
    public function reattemptFailedUserPayloadVersion()
    {
        try {
            $this->logFile = "amazon/script/" . date('d-m-Y') . '.log';
            $destinationData = $this->di->getConfig()->get('region_wise_destination_ids') ? $this->di->getConfig()->get('region_wise_destination_ids')->toArray() : [];
            if (empty($destinationData)) {
                $this->di->getLog()->logContent('Unable to fetch destination data from config', 'info', $this->logFile);
                return ['success' => false, 'message' => 'Unable to fetch destination data from config'];
            }
            $dbName = 'remote_db';
            $db = $this->di->get($dbName);
            if ($db) {
                $cifSubscriptionCollection = $db->selectCollection('cif_subscription');
                $marketplaceSubscriptionCollection = $db->selectCollection('marketplace_subscription');
            } else {
                $this->di->getLog()->logContent('Unable to create remote db connection', 'info', $this->logFile);
                return ['success' => false, 'message' => 'Unable to create remote db connection'];
            }
            $marketplace = "amazon";
            $webhookType = 'LISTINGS_ITEM_ISSUES_CHANGE';
            $region = '';
            $activePage = 1;
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $failedUsers = $mongo->getCollectionForTable('webhook_version_update_failed_users');
            $userDetailCollection = $mongo->getCollectionForTable('user_details');
            $page = 1;
            $limit = 500;
            $this->logFile = "amazon/script/" . date('d-m-Y') . '/page-' . $activePage . '.log';
            while (true) {
                $filter = [];
                $offset = ($page - 1) * $limit;
                $pipeline = [
                    [
                        '$match' => [
                            'picked' => ['$exists' => false],
                        ]
                    ],
                    [
                        '$group' => [
                            '_id' => '$user_id',
                            'shops' => [
                                '$addToSet' => '$shop_id'
                            ],
                        ]
                    ],
                    [
                        '$sort' => ['_id' => 1]
                    ],
                    [
                        '$skip' => $offset
                    ],
                    [
                        '$limit' => $limit
                    ]
                ];
                $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
                $userData = $failedUsers->aggregate($pipeline, $arrayParams)->toArray();
                if (empty($userData)) {
                    $this->di->getLog()->logContent('All users completed', 'info', $this->logFile);
                    break;
                }
                foreach ($userData as $user) {
                    $oldSubscriptionData = [];
                    $newSubscriptionData = [];
                    $userId = $user['_id'];
                    $response = $this->setDiForUser($userId);
                    if (isset($response['success']) && $response['success']) {
                        $this->di->getLog()->logContent('Starting process for UserId: ' . json_encode($userId), 'info', $this->logFile);
                        $shops = $this->di->getUser()->shops;
                        if (!empty($shops)) {
                            foreach ($shops as $shop) {
                                $toBeUpdated = false;
                                $toBeDeleted = true;
                                if ($shop['marketplace'] == $marketplace) {
                                    if (in_array($shop['_id'], $user['shops'])) {
                                        if ($shop['apps'][0]['app_status'] == 'active' && $shop['warehouses'][0]['status'] == 'active') {
                                            $region = $shop['warehouses'][0]['region'];
                                            $sellerId = $shop['warehouses'][0]['seller_id'];
                                            $uniqueKey = $region . "_" . $sellerId;
                                            if (!empty($shop['apps'][0]['webhooks'])) {
                                                $webhooks = $shop['apps'][0]['webhooks'];
                                                $getWebhookCodes = array_column($webhooks, 'code');
                                                if (!in_array($webhookType, $getWebhookCodes)) {
                                                    if (isset($shop['register_for_order_sync']) && $shop['register_for_order_sync']) {
                                                        $this->di->getLog()->logContent('Webhook Code not found initiating routeRegisterWebhook for:  ' . json_encode([
                                                            'shop_id' => $shop['_id'],
                                                            'remote_shop_id' => $shop['remote_shop_id'],
                                                        ]), 'info', $this->logFile);
                                                        $dataToSent = [
                                                            'user_id' => $userId,
                                                            'target_marketplace' => [
                                                                'marketplace' => $shop['marketplace'],
                                                                'shop_id' => $shop['_id']
                                                            ]
                                                        ];
                                                        $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                                                            ->createSubscription($dataToSent);
                                                    } else {
                                                        $this->di->getLog()->logContent('Store Close user found:  ' . json_encode([
                                                            'shop_id' => $shop['_id'],
                                                            'remote_shop_id' => $shop['remote_shop_id'],
                                                        ]), 'info', $this->logFile);
                                                        $this->insertErrorShop($userId, $shop, 'Store close user');
                                                        $filter[] =
                                                            [
                                                                'updateOne' => [
                                                                    [
                                                                        'user_id' => $userId,
                                                                        'shop_id' => $shop['_id'],
                                                                    ],
                                                                    ['$set' => ["picked" => true]],
                                                                ],
                                                            ];
                                                    }
                                                    continue;
                                                }
                                                if (!isset($oldSubscriptionData[$uniqueKey]) && !isset($newSubscriptionData[$uniqueKey])) {
                                                    $getSubscriptionResponse = $this->getSubscription($shop, $webhookType);
                                                    if (isset($getSubscriptionResponse['success']) && $getSubscriptionResponse['success'] && !empty($getSubscriptionResponse['response']['payload'])) {
                                                        $payloadVersion = $getSubscriptionResponse['response']['payload']['payloadVersion'];
                                                        $marketplaceSubscriptionId = $getSubscriptionResponse['response']['payload']['subscriptionId'];
                                                        if ($payloadVersion == '1.0') {
                                                            $oldSubscriptionData[$uniqueKey] = [
                                                                'marketplace_subscription_id' => $marketplaceSubscriptionId,
                                                                'remote_shop_id' => $shop['remote_shop_id']
                                                            ];
                                                            $toBeUpdated = true;
                                                        } else {
                                                            $this->di->getLog()->logContent('Payload version is already updated for: ' . json_encode([
                                                                'shop_id' => $shop['_id'],
                                                                'remote_shop_id' => $shop['remote_shop_id']
                                                            ]), 'info', $this->logFile);
                                                            $newSubscriptionData[$uniqueKey] = $marketplaceSubscriptionId;
                                                            $toBeUpdated = true;
                                                            $toBeDeleted = false;
                                                        }
                                                    } elseif (isset($getSubscriptionResponse['code']) && $getSubscriptionResponse['code'] == '404') {
                                                        $this->di->getLog()->logContent('Requested resource not found for: ' . json_encode([
                                                            'user_id' => $userId,
                                                            'shop_id' => $shop['_id']
                                                        ]), 'info', $this->logFile);
                                                        $toBeUpdated = true;
                                                        $toBeDeleted = false;
                                                    } else {
                                                        $this->di->getLog()->logContent('getSubscripton(), Error: ' . json_encode($getSubscriptionResponse), 'info', $this->logFile);
                                                        $this->insertErrorShop($userId, $shop, $getSubscriptionResponse);
                                                    }
                                                } else {
                                                    $this->di->getLog()->logContent('Same region shop found: ' . json_encode(['shop_id' => $shop['_id'], 'remote_shop_id' => $shop['remote_shop_id']]), 'info', $this->logFile);
                                                    $toBeUpdated = true;
                                                    $toBeDeleted = false;
                                                }
                                            } else {
                                                if (isset($shop['register_for_order_sync']) && $shop['register_for_order_sync']) {
                                                    $this->di->getLog()->logContent('Webhook Code not found initiating routeRegisterWebhook for:  ' . json_encode([
                                                        'shop_id' => $shop['_id'],
                                                        'remote_shop_id' => $shop['remote_shop_id'],
                                                    ]), 'info', $this->logFile);
                                                    $dataToSent= [
                                                        'user_id' => $userId,
                                                        'target_marketplace' => [
                                                            'marketplace' => $shop['marketplace'],
                                                            'shop_id' => $shop['_id']
                                                        ]
                                                    ];
                                                    $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                                                        ->createSubscription($dataToSent);
                                                } else {
                                                    $this->di->getLog()->logContent('Store Close user found:  ' . json_encode([
                                                        'shop_id' => $shop['_id'],
                                                        'remote_shop_id' => $shop['remote_shop_id'],
                                                    ]), 'info', $this->logFile);
                                                    $this->insertErrorShop($userId, $shop, 'Store close user');
                                                    $filter[] =
                                                        [
                                                            'updateOne' => [
                                                                [
                                                                    'user_id' => $userId,
                                                                    'shop_id' => $shop['_id'],
                                                                ],
                                                                ['$set' => ["picked" => true]],
                                                            ],
                                                        ];
                                                }
                                                continue;
                                            }
                                            if ($toBeUpdated) {
                                                if (!isset($newSubscriptionData[$uniqueKey])) {
                                                    $createSubscriptionResponse = $this->createSubscription($shop, $webhookType, $region, $destinationData);
                                                    $this->di->getLog()->logContent('Creating new subscription for: ' . json_encode([
                                                        'shop_id' => $shop['_id'],
                                                        'remote_shop_id' => $shop['remote_shop_id'],
                                                        'createSubscriptionResponse' => $createSubscriptionResponse
                                                    ]), 'info', $this->logFile);
                                                    if (isset($createSubscriptionResponse['success']) && $createSubscriptionResponse['success']) {
                                                        $newSubscriptionData[$uniqueKey] = $createSubscriptionResponse['subscription_id'];
                                                    } else {
                                                        $this->di->getLog()->logContent('createSubscriptionResponse(), Error: ' . json_encode($createSubscriptionResponse), 'info', $this->logFile);
                                                        $this->insertErrorShop($userId, $shop, $createSubscriptionResponse);
                                                    }
                                                }
                                                $this->di->getLog()->logContent('Updating subscriptionId for: ' . json_encode([
                                                    'shop_id' => $shop['_id'],
                                                    'remote_shop_id' => $shop['remote_shop_id'],
                                                    'subscription_id' => $newSubscriptionData[$uniqueKey]
                                                ]), 'info', $this->logFile);
                                                $filter[] =
                                                    [
                                                        'updateOne' => [
                                                            [
                                                                'user_id' => $userId,
                                                                'shop_id' => $shop['_id'],
                                                            ],
                                                            ['$set' => ["picked" => true]],
                                                        ],
                                                    ];
                                                $userDetailCollection->updateOne(
                                                    [
                                                        'user_id' => $userId,
                                                        'shops._id' => $shop['_id'],
                                                        'shops.apps.webhooks.code' => $webhookType
                                                    ],
                                                    [
                                                        '$set' => [
                                                            'shops.$[shop].apps.$[app].webhooks.$[webhook].marketplace_subscription_id' => $newSubscriptionData[$uniqueKey]
                                                        ]
                                                    ],
                                                    [
                                                        'arrayFilters' => [
                                                            ['shop._id' => $shop['_id']],
                                                            ['app.code' => $marketplace],
                                                            ['webhook.code' => $webhookType]
                                                        ]
                                                    ]
                                                );
                                                $cifSubscriptionCollection->updateOne(
                                                    ['remote_shop_id' => $shop['remote_shop_id'], 'event_code' => $webhookType],
                                                    [
                                                        '$set' => ['subscription_id' => $newSubscriptionData[$uniqueKey]]
                                                    ]
                                                );
                                                $marketplaceSubscriptionCollection->updateOne(
                                                    ['remote_shop_id' => $shop['remote_shop_id'], 'event_code' => $webhookType],
                                                    [
                                                        '$set' => ['marketplace_subscription_id' => $newSubscriptionData[$uniqueKey]]
                                                    ]
                                                );
                                                if ($toBeDeleted) {
                                                    $this->deleteSubscription($webhookType, $oldSubscriptionData);
                                                }
                                            } else {
                                                $this->di->getLog()->logContent('Not updating subscription_id either error in getSubscription response or already latest version is registered: ' . json_encode([
                                                    'user_id' => $userId,
                                                    'shop_id' => $shop['_id']
                                                ]), 'info', $this->logFile);
                                                $filter[] =
                                                    [
                                                        'updateOne' => [
                                                            [
                                                                'user_id' => $userId
                                                            ],
                                                            ['$set' => ["picked" => true,"error" => 'getSubscription response error']],
                                                        ],
                                                    ];
                                            }
                                        } else {
                                            $this->di->getLog()->logContent('Shop or Warehouse inactive: ' . json_encode([
                                                'user_id' => $userId,
                                                'shop_id' => $shop['_id']
                                            ]), 'info', $this->logFile);
                                            $this->insertErrorShop($userId, $shop, 'Shop or Warehouse inactive');
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        $this->di->getLog()->logContent('Unable to set di', 'info', $this->logFile);
                        $filter[] =
                        [
                            'updateOne' => [
                                [
                                    'user_id' => $userId
                                ],
                                ['$set' => ["picked" => true,"error" => 'Unable to set di']],
                            ],
                        ];
                    }
                }
                ++$page;
                if (!empty($filter)) {
                    $failedUsers->bulkWrite($filter);
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception reattemptFailedUserPayloadVersion(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }

    public function migrateJsonAttributeData()
    {
        $this->logFile = 'amazon/script/' . date('d-m-Y') . '.log';
        $activePage = 1;
        $this->lastTraversedObjectId = '';
        $this->lastTraversedShopId = '';
        $targetMarketplace = 'amazon';
        $sourceMarketplace = 'shopify';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userData = $this->fetchNewUserForProcessing($activePage, 'migrate_json_attribute_data');
        $cronTask = $mongo->getCollectionForTable('cron_tasks');
        while (!empty($userData)) {
            $this->logFile = "amazon/script/" . date('d-m-Y') . '/page-' . $activePage . '.log';
            $lastProcessedUserId = '';
            foreach ($userData as $user) {
                $userId = $user['user_id'];
                $this->di->getLog()->logContent('Starting process for user_id: ' . json_encode($userId), 'info', $this->logFile);
                if (!empty($user['shops'])) {
                    $sourceShop = [];
                    foreach ($user['shops'] as $shop) {
                        if ($shop['marketplace'] === $sourceMarketplace) {
                            $sourceShop = $shop;
                            break;
                        }
                    }
                    if (!empty($sourceShop)) {
                        $targetFlag = false;
                        foreach ($user['shops'] as  $shop) {
                            if (
                                $shop['marketplace'] == $targetMarketplace
                                && $shop['apps'][0]['app_status'] == 'active'
                            ) {
                                if (!empty($this->lastTraversedShopId)) {
                                    if ($targetFlag || $this->lastTraversedShopId == $shop['_id']) {
                                        $targetShopId = $shop['_id'];
                                        $sourceShopId = $sourceShop['_id'];
                                        $targetFlag = true;
                                    } else {
                                        continue;
                                    }
                                } else {
                                    $targetShopId = $shop['_id'];
                                    $sourceShopId = $sourceShop['_id'];
                                }
                                $this->di->getLog()->logContent('Starting process for target_shop: ' . json_encode($targetShopId), 'info', $this->logFile);
                                $groupWiseContainerIds = $this->fetchTargetGroupWiseIds($userId, $targetShopId, $this->lastTraversedObjectId);
                                while (!empty($groupWiseContainerIds)) {
                                    $lastProcessedObjectId = (string)$groupWiseContainerIds['last_traversed_object_id'];
                                    unset($groupWiseContainerIds['last_traversed_object_id']);
                                    $targetParentDoc = [];
                                    foreach ($groupWiseContainerIds as $containerId => $sourceProductIds) {
                                        $containerId = (string) $containerId;
                                        $sourceProductIds = array_map('strval', $sourceProductIds);
                                        $targetParentDoc = $this->fetchTargetParentDoc($userId, $targetShopId, $containerId);
                                        if (!empty($targetParentDoc) && !empty($targetParentDoc['category_settings']['attributes_mapping']['jsonFeed'])) {
                                            $targetChildData = $this->fetchTargetDataBySourceProductIds($userId, $targetShopId, $containerId, $sourceProductIds);
                                            $this->jsonAttributeSourceProductWiseData = [];
                                            if (!empty($targetChildData)) {
                                                foreach ($targetChildData as $data) {
                                                    $sourceDoc = $this->fetchSourceData($userId, $sourceShopId, $sourceMarketplace, $containerId, $data['source_product_id']);
                                                    if (empty($sourceDoc)) {
                                                        $this->di->getLog()->logContent('Unable to find sourceDoc for container_id: ' . json_encode($containerId), 'info', $this->logFile);
                                                        continue;
                                                    }
                                                    if (!empty($data['category_settings']['variants_json_attributes_values'])) {
                                                        foreach ($data['category_settings']['variants_json_attributes_values'] as $attribute => $attributeValues) {
                                                            $this->processNestedAttributes($attribute, $attributeValues, $targetParentDoc, $sourceDoc, $data, $sourceMarketplace);
                                                        }
                                                    }
                                                }
                                                if (!empty($this->jsonAttributeSourceProductWiseData)) {
                                                    $this->insertVariantValueMappingData($userId, $targetShopId, $containerId, $targetParentDoc, $this->jsonAttributeSourceProductWiseData);
                                                }
                                            }
                                        } else {
                                            $this->di->getLog()->logContent('Target Parent Doc or attributes_mapping key not found for container_id: ' . json_encode($containerId), 'info', $this->logFile);
                                        }
                                    }
                                    $cronTask->updateOne(
                                        ['task' => 'migrate_json_attribute_data'],
                                        [
                                            '$set' => [
                                                'last_traversed_user_id' => $userId,
                                                'last_traversed_object_id' => $lastProcessedObjectId,
                                                'last_traversed_shop_id' => (string) $targetShopId
                                            ],
                                        ],
                                        ['upsert' => true]
                                    );
                                    $groupWiseContainerIds = $this->fetchTargetGroupWiseIds($userId, $targetShopId, $lastProcessedObjectId);
                                }
                            }
                            $this->di->getLog()->logContent('Process completed for this target_shop', 'info', $this->logFile);
                        }
                    }
                }
                $lastProcessedUserId =  $userId;
                $cronTask->updateOne(
                    ['task' => 'migrate_json_attribute_data'],
                    [
                        '$set' => [
                            'last_traversed_user_id' => $lastProcessedUserId,
                            'completed_page' => $activePage
                        ],
                        '$unset' => ['last_traversed_object_id' => 1, 'last_traversed_shop_id' => 1]
                    ],
                    ['upsert' => true]
                );
            }
            ++$activePage;
            $userData = $this->fetchNewUserForProcessing($activePage, 'migrate_json_attribute_data', $lastProcessedUserId, true);
        }
    }

    private function processNestedAttributes($attribute, $nestedData, $targetParentDoc, $sourceDoc, $data, $sourceMarketplace, $preparePath = '')
    {
        $skipSourceAttributes = ['title', 'description', 'handle', 'type', 'sku', 'barcode', 'tags', 'price', 'quantity', 'brand'];
        foreach ($nestedData as $key => $value) {
            $currentPath = empty($preparePath) ? "$attribute/$key" : "$preparePath/$key";
            $sourceAttribute = '';
            $sourceValue = '';

            $mappingPath = explode('/', $currentPath);
            $mappingReference = $targetParentDoc['category_settings']['attributes_mapping']['jsonFeed'];

            foreach ($mappingPath as $pathPart) {
                if (isset($mappingReference[$pathPart])) {
                    $mappingReference = $mappingReference[$pathPart];
                } else {
                    $mappingReference = null;
                    break;
                }
            }

            if (
                $mappingReference && isset($mappingReference['attribute_value']) &&
                $mappingReference['attribute_value'] == $sourceMarketplace . "_attribute"
            ) {

                $sourceAttribute = $mappingReference[$sourceMarketplace . "_attribute"];
            }

            if (!empty($sourceAttribute) && !in_array($sourceAttribute, $skipSourceAttributes)) {
                if (isset($sourceDoc[$sourceAttribute])) {
                    $sourceValue = $sourceDoc[$sourceAttribute];
                    $sourceValue = (string) $sourceValue;
                } elseif (!empty($sourceDoc['variant_attributes'])) {
                    foreach ($sourceDoc['variant_attributes'] as $variantAttribute) {
                        if ($variantAttribute['key'] == $sourceAttribute) {
                            $sourceValue = $variantAttribute['value'];
                            break;
                        }
                    }
                }

                if (!empty($sourceValue) || in_array($sourceValue,[0,'0'])) {
                    $this->jsonAttributeSourceProductWiseData[$data['source_product_id']][$currentPath] = (object)[
                        $sourceValue => $value
                    ];
                }
            }

            if (is_array($value)) {
                $this->processNestedAttributes($attribute, $value, $targetParentDoc, $sourceDoc, $data, $sourceMarketplace, $currentPath);
            }
        }
    }

    private function insertVariantValueMappingData($userId, $targetShopId, $containerId, $targetParentDoc, $variantValueMappingData)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollectionForTable('product_container');
            $finalValues = [];

            foreach ($variantValueMappingData as $item) {
                foreach ($item as $key => $values) {
                    if (!isset($finalValues[$key])) {
                        $finalValues[$key] = [];
                    }
                    $valuesArray = is_object($values) ? (array) $values : $values;
                    $finalValues[$key] = is_object($finalValues[$key]) ? (array) $finalValues[$key] : $finalValues[$key];
                    $finalValues[$key] += $valuesArray;
                    $finalValues[$key] = (object)$finalValues[$key];
                }
            }

            if (!empty($targetParentDoc['category_settings']['variants_value_mapping'])) {
                $finalValues = $this->mergeJsonAttributeData($targetParentDoc['category_settings']['variants_value_mapping'], $finalValues);
            }

            $productContainer->updateOne(
                [
                    'user_id' => $userId,
                    'shop_id' => $targetShopId,
                    'target_marketplace' => Helper::TARGET,
                    'container_id' => $containerId,
                    'source_product_id' => $containerId
                ],
                [
                    '$set' => [
                        'category_settings.variants_value_mapping' => $finalValues
                    ]
                ]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception insertVariantValueMappingData(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }

    private function mergeJsonAttributeData($existingJsonAttributeData, $jsonAttributeSourceProductWiseData)
    {
        foreach ($jsonAttributeSourceProductWiseData as $key => $values) {
            if (isset($existingJsonAttributeData[$key]) && is_array($existingJsonAttributeData[$key]) && is_array($values)) {
                if (array_keys($values) === range(0, count($values) - 1)) {
                    $existingJsonAttributeData[$key] = array_values(array_unique(array_merge($existingJsonAttributeData[$key], $values)));
                } else {
                    $existingJsonAttributeData[$key] = $existingJsonAttributeData[$key] + $values;
                }
            } else {
                $existingJsonAttributeData[$key] = $values;
            }
        }
        return $existingJsonAttributeData;
    }

    private function fetchSourceData($userId, $sourceShopId, $sourceMarketplace, $containerId, $sourceProductId)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollectionForTable('product_container');
            $options = [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
                "projection" => [
                    '_id' => 0,
                    'description' => 0,
                    'additional_images' => 0,
                    'locations' => 0,
                    'marketplace' => 0,
                    'profile' => 0,
                    'product_category' => 0
                ],
            ];
            return $productContainer->findOne(
                [
                    'user_id' => $userId,
                    'shop_id' => $sourceShopId,
                    'source_marketplace' => $sourceMarketplace,
                    'container_id' => $containerId,
                    'source_product_id' => $sourceProductId,
                    'visibility' => 'Not Visible Individually'
                ],
                $options
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception fetchSourceData(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }

    private function fetchTargetParentDoc($userId, $targetShopId, $containerId)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollectionForTable('product_container');
            $options = [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
                "projection" => [
                    '_id' => 0,
                    'category_settings.attributes_mapping.jsonFeed' => 1,
                    'category_settings.variants_value_mapping' => 1
                ],
            ];
            return $productContainer->findOne(
                [
                    'user_id' => $userId,
                    'shop_id' => $targetShopId,
                    'target_marketplace' => Helper::TARGET,
                    'container_id' => $containerId,
                    'source_product_id' => $containerId
                ],
                $options
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception fetchTargetParentDoc(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }

    private function fetchTargetDataBySourceProductIds($userId, $targetShopId, $containerId, $sourceProductIds)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollectionForTable('product_container');
            $options = [
                "projection" => ['_id' => 0, 'source_product_id' => 1, 'category_settings.variants_json_attributes_values' => 1],
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ];
            return $productContainer->find(
                [
                    'user_id' => $userId,
                    'shop_id' => $targetShopId,
                    'target_marketplace' => Helper::TARGET,
                    'container_id' => $containerId,
                    'source_product_id' => ['$in' => $sourceProductIds],
                    'category_settings.variants_json_attributes_values' => ['$exists' => true]
                ],
                $options
            )->toArray();
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception fetchTargetDataBySourceProductIds(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }

    private function fetchTargetGroupWiseIds($userId, $targetShopId, $lastProcessedObjectId)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollectionForTable('product_container');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            if (!empty($lastProcessedObjectId)) {
                $matchPipeline = [
                    '$match' => [
                        'user_id' => $userId,
                        'shop_id' => $targetShopId,
                        'target_marketplace' => Helper::TARGET,
                        'category_settings.variants_json_attributes_values' => ['$exists' => true],
                        '_id' => ['$gt' => new \MongoDB\BSON\ObjectId($lastProcessedObjectId)]
                    ]
                ];
            } else {
                $matchPipeline = [
                    '$match' => [
                        'user_id' => $userId,
                        'shop_id' => $targetShopId,
                        'target_marketplace' => Helper::TARGET,
                        'category_settings.variants_json_attributes_values' => ['$exists' => true]
                    ]
                ];
            }

            $pipeline = [
                $matchPipeline,
                [
                    '$project' => [
                        'container_id' => 1,
                        'source_product_id' => 1
                    ]
                ],
                [
                    '$limit' => 500
                ],
                [
                    '$sort' => ['_id' => 1]
                ]
            ];
            $preparedData = [];
            $productData = $productContainer->aggregate($pipeline, $arrayParams)->toArray();
            if (!empty($productData)) {
                foreach ($productData as $product) {
                    $preparedData[$product['container_id']][] = $product['source_product_id'];
                }
                $lastObjectData = end($productData);
                $preparedData['last_traversed_object_id'] = (string)$lastObjectData['_id'];
            }
            return $preparedData;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception fetchTargetGroupWiseIds(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }

    public function fetchNewUserForProcessing(&$activePage, $task, $lastProcessedUserId = null, $lastUserFinished = false)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetails = $mongo->getCollectionForTable('user_details');
            if (is_null($lastProcessedUserId)) {
                $cronTask = $mongo->getCollectionForTable('cron_tasks');
                $taskData = $cronTask->findOne(['task' => $task], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
                if (!empty($taskData)) {
                    $activePage = isset($taskData['completed_page']) ? ++$taskData['completed_page'] : 1;
                    $lastProcessedUserId = $taskData['last_traversed_user_id'];
                    if (!empty($taskData['last_traversed_object_id'])) {
                        $this->lastTraversedObjectId = $taskData['last_traversed_object_id'];
                        if ($task == 'migrate_json_attribute_data') {
                            $this->lastTraversedShopId = $taskData['last_traversed_shop_id'];
                        }
                    } else {
                        $lastUserFinished = true;
                    }
                }
            }
            if (!empty($lastProcessedUserId)) {
                if ($lastUserFinished) {
                    $queryParts = ['$gt' => new \MongoDB\BSON\ObjectId($lastProcessedUserId)];
                } else {
                    $queryParts = ['$gte' => new \MongoDB\BSON\ObjectId($lastProcessedUserId)];
                }
                $userData = $userDetails->find(
                    [
                        '_id' => $queryParts,
                        'shops' => [
                            '$elemMatch' => [
                                'apps.app_status' => 'active',
                                'marketplace' => Helper::TARGET
                            ],
                        ]
                    ],
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'limit' => 500,
                        'projection' => ['user_id' => 1, 'username' => 1, 'shops' => 1],
                        'sort' => ['_id' => 1]
                    ]
                )->toArray();
            } else {
                $userData = $userDetails->find(
                    [
                        'shops' => [
                            '$elemMatch' => [
                                'apps.app_status' => 'active',
                                'marketplace' => 'amazon'
                            ],
                        ]
                    ],
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'limit' => 500,
                        'projection' => ['user_id' => 1, 'username' => 1, 'shops' => 1],
                        'sort' => ['_id' => 1]
                    ]
                )->toArray();
            }
            return $userData;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception fetchNewUserForProcessing(), Error: ' . print_r($e->getMessage()), 'info', $this->logFile);
        }
    }


    public function findStaleUsersInCollection()
    {
        try {
            $collectionName = $this->input->getOption("container") ?? false;
            if (!$collectionName) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Collection name (-c) is mandatory</>');
                return 0;
            }
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $staleUserDataContainer = $mongo->getCollectionForTable('stale_user_data_collection_wise');
            $activeContainer = $mongo->getCollectionForTable($collectionName);
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            if (!$staleUserDataContainer || !$activeContainer) {
                $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> Either `stale_user_data_collection_wise` or $collectionName not exists</>");
                return 0;
            }
            $userPipeline = [
                [
                    '$match' => [
                        'shops' => [
                            '$ne' => null
                        ]
                    ]
                ],
                [

                    '$group' => [
                        '_id' => null,
                        'user_ids' => [
                            '$addToSet' => '$user_id'
                        ]
                    ]
                ]
            ];
            $userDetailsContainer = $mongo->getCollectionForTable('user_details');
            $userData = $userDetailsContainer->aggregate($userPipeline, $options)->toArray();
            if (!empty($userData[0]['user_ids'])) {
                $userIds = $userData[0]['user_ids'];
                $collectionPipeline = [
                    [
                        '$match' => [
                            'user_id' => [
                                '$nin' => $userIds
                            ]
                        ]
                    ],
                    [
                        '$group' => [
                            '_id' => null,
                            'user_ids' => [
                                '$addToSet' => '$user_id'
                            ]
                        ]
                    ]
                ];
                $staleUserData = $activeContainer->aggregate($collectionPipeline, $options)->toArray();
                if (!empty($staleUserData[0]['user_ids'])) {
                    $prepareData = [];
                    foreach ($staleUserData[0]['user_ids'] as $userId) {
                        if (!empty($userId) && $userId != 'all') {
                            $prepareData[$userId] = false;
                        }
                    }
                    if (!empty($prepareData)) {
                        $staleUserDataContainer->updateOne(
                            [
                                'collection_name' => $collectionName,
                            ],
                            [
                                '$set' => ['user_ids' => $prepareData, 'created_at' => date('c')]
                            ],
                            ['upsert' => true]
                        );
                    }
                } else {
                    $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> No stale user data found for collection: $collectionName</>");
                }
            } else {
                $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to fetch data from user_details</>");
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from scriptToFindStaleUsersFromSpecificCollection(), Error: ' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    public function deleteStaleUsersFromCollection()
    {
        try {
            $collectionName = $this->input->getOption("container") ?? false;
            if (!$collectionName) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Collection name (-c) is mandatory</>');
                return 0;
            }
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $staleUserDataContainer = $mongo->getCollectionForTable('stale_user_data_collection_wise');
            $activeContainer = $mongo->getCollectionForTable($collectionName);
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            if (!$staleUserDataContainer || !$activeContainer) {
                $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> Either `stale_user_data_collection_wise` or $collectionName not exists</>");
                return 0;
            }
            $staleUserData = $staleUserDataContainer->findOne(['collection_name' => $collectionName], $options);
            if (!empty($staleUserData['user_ids'])) {
                $staleUserIds = [];
                foreach ($staleUserData['user_ids'] as $userId => $value) {
                    if (!$value) {
                        $staleUserIds[] = $userId;
                    }
                }
                if (!empty($staleUserIds)) {
                    $userPipeline = [
                        [
                            '$match' => [
                                'user_id' => [
                                    '$in' => $staleUserIds
                                ]
                            ]
                        ],
                        [
                            '$group' => [
                                '_id' => null,
                                'user_ids' => [
                                    '$addToSet' => '$user_id'
                                ]
                            ]
                        ]
                    ];
                    $userDetailsContainer = $mongo->getCollectionForTable('user_details');
                    $userDetailsData = $userDetailsContainer->aggregate($userPipeline, $options)->toArray();
                    if (!empty($userDetailsData[0]['user_ids'])) {
                        $staleUserIds = array_diff($staleUserIds, $userDetailsData[0]['user_ids']);
                        foreach ($userDetailsData[0]['user_ids'] as $userId) {
                            $unsetFields["user_ids.$userId"] = "";
                        }
                        $staleUserDataContainer->updateOne(['collection_name' => $collectionName], ['$unset' => $unsetFields]);
                    }
                    $processedUserIdCount = 0;
                    $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> Data deletion started from collection: $collectionName</>");
                    foreach ($staleUserIds as $userId) {
                        $response = $activeContainer->deleteMany(['user_id' => (string) $userId]);
                        if (!$response->getDeletedCount()) {
                            $activeContainer->deleteMany(['user_id' => $userId]);
                        }
                        $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> Processed UserId: $userId</>");
                        $filter[] = [
                            'updateOne' => [
                                [
                                    'collection_name' => $collectionName
                                ],
                                [
                                    '$set' => ["user_ids.$userId" => true]
                                ]
                            ]
                        ];
                        ++$processedUserIdCount;
                        if ($processedUserIdCount == 50) {
                            $staleUserDataContainer->bulkWrite($filter);
                            $processedUserIdCount = 0;
                            $filter = [];
                        }
                    }

                    if (!empty($filter)) {
                        $staleUserDataContainer->bulkWrite($filter);
                    }
                } else {
                    $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> All userIds data deletion completed for this collection: $collectionName</>");
                }
            } else {
                $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> Stale userIds not found for this collection: $collectionName</>");
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from scriptToDeleteStaleUsersFromSpecificCollection(), Error: ' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    public function reattemptAmazonWebhookRegisteration()
    {
        $getAmazonWebhooks = $this->di->getConfig()->webhook->get('amazon')->toArray();
        if (!empty($getAmazonWebhooks)) {
            $standardWebhooks = array_column($getAmazonWebhooks, 'code');
        }
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsContainer = $mongo->getCollectionForTable('user_details');
        $pipeline = [
            [
                '$match' => [
                    'shops.marketplace' => 'amazon',
                    'shops.apps.app_status' => 'active',
                    'shops.warehouses.status' => 'active',
                    'shops.register_for_order_sync' => true
                ]
            ],
            ['$unwind' => '$shops'],
            ['$unwind' => '$shops.apps'],
            [
                '$match' => [
                    'shops.marketplace' => 'amazon',
                    'shops.apps.app_status' => 'active',
                    'shops.warehouses.status' => 'active',
                    'shops.sources' => ['$ne' => null],
                    '$or' => [
                        ['shops.apps.webhooks' => ['$exists' => false]],
                        ['shops.apps.webhooks' => []],
                        [
                            'shops.apps.webhooks.code' => [
                                '$not' => [
                                    '$all' => $standardWebhooks
                                ]
                            ]
                        ]
                    ],
                ]
            ],
            [
                '$project' => ['shops.apps.webhooks.code' => 1, 'shops._id' => 1, 'user_id' => 1, 'username' => 1]
            ],
            ['$group' => ['_id' => '$user_id', 'shops' => ['$addToSet' => '$shops'], 'username' => ['$first' => '$username']]],
        ];
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $userData = $userDetailsContainer->aggregate($pipeline, $arrayParams)->toArray();
        if (!empty($userData)) {
            foreach ($userData as $user) {
                $response = $this->setDiForUser($user['_id']);
                if (isset($response['success']) && $response['success']) {
                    foreach ($user['shops'] as $shopData) {
                        $dataToSent['user_id'] = $user['_id'];
                        $dataToSent['target_marketplace'] = [
                            'marketplace' => 'amazon',
                            'shop_id' => $shopData['_id']
                        ];
                        $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                            ->createSubscription($dataToSent);
                    }
                }
            }
        }
    }

    public function disconnectOnlyTargetExistsUsers()
    {
        try {
            $logFile = "amazon/script/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsContainer = $mongo->getCollectionForTable('user_details');
            $options = [
                "projection" => ['_id' => 0, 'user_id' => 1, 'shops' => 1],
                "typeMap" => ['root' => 'array', 'document' => 'array']
            ];
            $userData = $userDetailsContainer->find(
                [
                    'shops.0.marketplace' => Helper::TARGET,
                    'shops.apps.app_status' => 'active'
                ],
                $options
            )->toArray();
            foreach ($userData as $user) {
                foreach ($user['shops'] as $shopData) {
                    $userId = $user['user_id'];
                    $dataToSend = [
                        'user_id' => $user['user_id'],
                        'shop_id' => $shopData['_id']
                    ];
                    $response = $this->setDiForUser($userId);
                    if (isset($response['success']) && $response['success']) {
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting Process for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('Starting Process for user: ' . json_encode($userId), 'info', $logFile);
                        if (isset($shopData['register_for_order_sync']) && $shopData['register_for_order_sync']) {
                            $this->di->getObjectManager()->get("\App\Amazon\Components\ShopDelete")
                                ->removeRegisterForOrderSync($dataToSend);
                            $this->di->getObjectManager()->get(ShopEvent::class)->sendRemovedShopToAws($dataToSend);
                        }
                        $appCode = $shopData['apps'][0]['code'];
                        if (!empty($shopData['apps'][0]['webhooks'])) {
                            $this->di->getObjectManager()->get("\App\Amazon\Components\ShopDelete")
                                ->unRegisterWebhook($shopData, $appCode);
                        }
                        $dataToBeDeleted = [
                            'shop_id' => $shopData['_id'],
                            'app_code' => $appCode
                        ];
                        $this->di->getObjectManager()->get("\App\Connector\Components\Hook")->TemporarlyUninstall($dataToBeDeleted, $userId);
                    } else {
                        $this->di->getLog()->logContent('Unable to set Di for user: ' . json_encode($userId), 'info', $logFile);
                    }
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from disconnectOnlyTargetExistsUsers(), Error: ' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    public function removeProductsIfNotExistsInProductContainer()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productExistsInRefineContainerOnly = $mongo->getCollectionForTable('product_exists_in_refine_container_only');
        $refineProduct = $mongo->getCollectionForTable('refine_product');
        do {
            $staleData = $productExistsInRefineContainerOnly->find(
                [
                    'user_id' => ['$ne' => null],
                    'picked' => ['$exists' => false]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => 500
                ]
            )->toArray();
            if (!empty($staleData)) {
                $counter = 0;
                $filter = [];
                foreach ($staleData as $data) {
                    $filter[] =
                        [
                            'updateMany' => [
                                [
                                    'user_id' => $data['user_id'],
                                    'source_shop_id' => $data['source_shop_id'],
                                    'container_id' => $data['container_id']
                                ],
                                [
                                    '$pull' => [
                                        'items' => [
                                            'source_product_id' => ['$in' => $data['source_product_ids']]
                                        ]
                                    ]
                                ]
                            ]
                        ];
                    $containerIds[] = $data['container_id'];
                    $counter++;
                    if ($counter == 100) {
                        $refineProduct->bulkWrite($filter);
                        $refineProduct->deleteMany(
                            [
                                'user_id' => $data['user_id'],
                                'source_shop_id' => $data['source_shop_id'],
                                'container_id' => ['$in' => $containerIds],
                                'items' => []
                            ]
                        );
                        $productExistsInRefineContainerOnly->updateMany([
                            'user_id' => $data['user_id'],
                            'source_shop_id' => $data['source_shop_id'],
                            'container_id' => ['$in' => $containerIds],
                        ], [
                            '$set' => ['picked' => true]
                        ]);
                        $containerIds = [];
                        $counter = 0;
                        $filter = [];
                    }
                }
                if ($counter > 0) {
                    $refineProduct->bulkWrite($filter);
                    $refineProduct->deleteMany(
                        [
                            'user_id' => $data['user_id'],
                            'source_shop_id' => $data['source_shop_id'],
                            'container_id' => ['$in' => $containerIds],
                            'items' => []
                        ]
                    );
                    $productExistsInRefineContainerOnly->updateMany([
                        'user_id' => $data['user_id'],
                        'source_shop_id' => $data['source_shop_id'],
                        'container_id' => ['$in' => $containerIds],
                    ], [
                        '$set' => ['picked' => true]
                    ]);
                }
                $staleData = $productExistsInRefineContainerOnly->find(
                    [
                        'user_id' => ['$ne' => null],
                        'picked' => ['$exists' => false]
                    ],
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'limit' => 500
                    ]
                )->toArray();
            }
        } while (!empty($staleData));
    }

    public function validateBulleteinAccess()
    {
        try {
            $yesterdayDate = date('Y-m-d', strtotime('-1 day'));
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetails = $mongo->getCollectionForTable('user_details');
            $userDetails->updateMany(
                [
                    "shops" => [
                        '$elemMatch' => [
                            "marketplace" => Helper::TARGET,
                            "apps.app_status" =>'active',
                            "renewal_time" => ['$gte' => $yesterdayDate],
                            "reauth_required" => ['$exists' => true]
                        ]
                    ]
                ],
                [
                    '$unset' => [
                        "shops.$[elem].reauth_required" => ""
                    ]
                ],
                [
                    'arrayFilters' => [
                        [
                            "elem.marketplace" => Helper::TARGET,
                            "elem.apps.app_status" =>'active',
                            "elem.renewal_time" => ['$gte' => $yesterdayDate],
                            "elem.reauth_required" => ['$exists' => true]
                        ]
                    ]
                ]
            );
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception from validateBulleteinAccess(), Error: ' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    public function customScriptToSyncProductInRefine()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productExistsInProductContainerOnly = $mongo->getCollectionForTable('product_exists_in_product_container_only');
        do {
            $staleData = $productExistsInProductContainerOnly->find(
                [
                    'user_id' => ['$ne' => null],
                    'picked' => ['$exists' => false]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => 500
                ]
            )->toArray();
            if (!empty($staleData)) {
                $processedContainerIds = [];
                foreach ($staleData as $data) {
                    $preparedData = [];
                    $response = $this->setDiForUser($data['user_id']);
                    if (isset($response['success']) && $response['success']) {
                        if(!empty($data['source_product_ids'])) {
                            foreach ($data['source_product_ids'] as $sourceProductId) {
                                $preparedData[] = [
                                    'user_id' => $data['user_id'],
                                    'target_marketplace' => 'amazon',
                                    'shop_id' => $data['target_shop_id'],
                                    'container_id' => $data['container_id'],
                                    'source_product_id' => $sourceProductId,
                                    'source_shop_id' => $data['source_shop_id'],
                                ];
                            }
                           $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit')
                            ->saveProduct($preparedData, $data['user_id']);
                            $processedContainerIds[] = $data['container_id'];
                        }
                    }
                }
                if(!empty($processedContainerIds)) {
                    $productExistsInProductContainerOnly->updateMany(
                        [
                            'container_id' => ['$in' => $processedContainerIds]
                        ],
                        [
                            '$set' => ['picked' => true]
                        ]
                    );
                }
            }
            $staleData = $productExistsInProductContainerOnly->find(
                [
                    'user_id' => ['$ne' => null],
                    'picked' => ['$exists' => false]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => 500
                ]
            )->toArray();
        } while (!empty($staleData));
    }

    public function scriptToRemoveStaleDataFromDynamo()
    {

        $table = $this->input->getOption("container") ?? false;
        if (!$table) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Collection name (-c) is mandatory</>');
            return 0;
        }

        $logFile = "amazon/script/" . date('d-m-Y') . '.log';
        $lastEvaluatedKey = null;
        $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
        $dynamoClientObj = $dynamoObj->getDetails();
        $consolidatedData = [];
        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Dynamo Data scanning in progress...</>');
        do {
            $params = [
                'TableName' => $table
            ];

            if (!is_null($lastEvaluatedKey)) {
                $params['ExclusiveStartKey'] = $lastEvaluatedKey;
            }
            $scanData = $dynamoClientObj->scan($params);
            $scanData = $this->convertToArray($scanData);
            $consolidatedData = array_merge($consolidatedData, $scanData['Items']);
            $lastEvaluatedKey = isset($scanData['LastEvaluatedKey']) ? $scanData['LastEvaluatedKey'] : null;
        } while (!is_null($lastEvaluatedKey));
        if (!empty($consolidatedData)) {
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Dynamo Data scanning completed</>');
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetails = $mongo->getCollectionForTable('user_details');
            $dynamoRemoteShopIds = array_column($consolidatedData, 'id');
            $remoteShopChunks = array_chunk($dynamoRemoteShopIds, 2000);
            $chunkCounter = 1;
            $mongoRemoteShopIds = [];
            $staleRemoteShopIds = [];
            foreach ($remoteShopChunks as $chunk) {
                $filter = [
                    'shops' => [
                        '$elemMatch' => [
                            'apps.app_status' => 'active',
                            'register_for_order_sync' => true,
                            'remote_shop_id' => ['$in' => $chunk]
                        ]
                    ]
                ];
                $options = [
                    "projection" => ['_id' => 0, 'shops' => 1],
                    "typeMap" => ['root' => 'array', 'document' => 'array'],
                ];
                $userData = $userDetails->find($filter, $options)->toArray();
                if (!empty($userData)) {
                    foreach ($userData as $user) {
                        foreach ($user['shops'] as  $shop) {
                            if (
                                $shop['marketplace'] == Helper::TARGET && $shop['apps'][0]['app_status'] == 'active'
                                && isset($shop['register_for_order_sync']) && $shop['register_for_order_sync']
                            ) {
                                $mongoRemoteShopIds[] = $shop['remote_shop_id'];
                            }
                        }
                    }
                } else {
                    $this->di->getLog()->logContent('No Data found in mongo for these remote_shop_ids: ' . json_encode($chunk), 'info', $logFile);
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>No Data found in mongo for these remote_shop_ids</>');
                }
                $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>Chunk- $chunkCounter completed</>");
                $chunkCounter++;
            }
            if (!empty($mongoRemoteShopIds)) {
                $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting stale remote_shop_ids deletion process</>");
                $mongoRemoteShopIds = array_unique($mongoRemoteShopIds);
                $staleRemoteShopIds = array_diff($dynamoRemoteShopIds, $mongoRemoteShopIds);
                $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>Stale remote_shop_ids found check log</>");
                $this->di->getLog()->logContent("Stale remoteshopIds found: " . json_encode(array_values($staleRemoteShopIds)), 'info', $logFile);
                $this->removeStaleRemoteShopIds($table, $staleRemoteShopIds);
                $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>Stale remote_shop_ids removed from dynamoDb</>");
            } else {
                $this->di->getLog()->logContent('No mongo remote_shop_ids found', 'info', $logFile);
            }
        }
    }

    private function removeStaleRemoteShopIds($table, $remoteShopIds)
    {
        $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
        $dynamoClientObj = $dynamoObj->getDetails();
        $processCounter = 0;
        $maxBatchSize = 25;
        $batchRequest = [];
        foreach ($remoteShopIds as $id) {
            ++$processCounter;
            $batchRequest[] = [
                'DeleteRequest' => [
                    'Key' => [
                        'id' => ['S' => $id]
                    ],
                ],
            ];

            if ($processCounter == $maxBatchSize) {
                $dynamoClientObj->batchWriteItem([
                    'RequestItems' => [
                        $table => $batchRequest,
                    ],
                ]);
                $processCounter = 0;
                $batchRequest = [];
            }
        }
        if (!empty($batchRequest)) {
            $dynamoClientObj->batchWriteItem([
                'RequestItems' => [
                    $table => $batchRequest,
                ],
            ]);
        }
    }

    private function convertToArray($connectionData)
    {
        $marshalerObj = new Marshaler();
        $items = [];
        foreach ($connectionData['Items'] as $item) {
            $items[] = $marshalerObj->unmarshalItem($item);
        }
        $connectionData['Items'] = $items;
        return $connectionData;
    }

    public function saveLateFetchOrdersData() {
        $startDate = date('Y-m-d', strtotime('-1 days'));
        $endDate = date('Y-m-d');
        $params['start_date'] = $startDate;
        $params['end_date'] = $endDate;
        $this->di->getObjectManager()->get('\App\Amazon\Components\Order\LateOrderAnalysis')
        ->findAndSaveLateSyncOrders($params);
    }

    public function removeDataFromNotificationsCollection() {
        $notificationProcessCodes = $this->di->getConfig()->get('notifications_process_codes') ? $this->di->getConfig()->get('notifications_process_codes')->toArray() : [];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $notificationContainer = $mongo->getCollectionForTable('notifications');
        if(!empty($notificationProcessCodes)) {
            foreach($notificationProcessCodes as $deleteFrom => $processCodes) {
                $notificationContainer->deleteMany([
                    'process_code' => ['$in' => $processCodes],
                    'created_at' => ['$lt' => new  UTCDateTime((int)(strtotime($deleteFrom)* 1000))]
                ]);
            }
        }
    }

    public function dynamoSpapiErrorTableHandling()
    {
        $logFile = "amazon/script/" . date('d-m-Y') . '.log';
        $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
        $dynamoClientObj = $dynamoObj->getDetails();
        $lastEvaluatedKey = null;
        $consolidatedData = [];
        $tableName = 'SPAPI_Error';
        $this->output->writeln("➤ </><options=bold;fg=green>Fetching data from SPAPI_Error table</>");
        do {
            $params = [
                'TableName' => $tableName
            ];

            if (!is_null($lastEvaluatedKey)) {
                $params['ExclusiveStartKey'] = $lastEvaluatedKey;
            }
            $scanData = $dynamoClientObj->scan($params);
            $scanData = $this->convertToArray($scanData);
            $consolidatedData = array_merge($consolidatedData, $scanData['Items']);
            $lastEvaluatedKey = isset($scanData['LastEvaluatedKey']) ? $scanData['LastEvaluatedKey'] : null;
        } while (!is_null($lastEvaluatedKey));
        if (!empty($consolidatedData)) {
            $this->output->writeln("➤ </><options=bold;fg=green>Data fetched successfully!!</>");
            $this->di->getLog()->logContent('SPAPI_Error table consolidatedData: ' . json_encode($consolidatedData), 'info', $logFile);
            //Key value pair mapping
            $errorMapped = [];
            foreach ($consolidatedData as $data) {
                $errorMapped[$data['remote_shop_id']] = $data['error_code'];
            }
            $dynamoRemoteShopIds = array_map('strval', array_unique(array_keys($errorMapped)));
            $this->di->getLog()->logContent('SPAPI_Error table remote_shop_ids: ' . json_encode($dynamoRemoteShopIds), 'info', $logFile);
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetails = $mongo->getCollectionForTable('user_details');

            $filter = [
                'shops' => [
                    '$elemMatch' => [
                        'marketplace' => 'amazon',
                        'apps.app_status' => 'active',
                        'remote_shop_id' => ['$in' => $dynamoRemoteShopIds]
                    ]
                ]
            ];
            $options = [
                "projection" => ['_id' => 0, 'shops' => 1],
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ];
            $userData = $userDetails->find($filter, $options)->toArray();
            if (!empty($userData)) {
                $warehouseActiveShops = [];
                $homeShopAndRemoteShopPair = [];
                $remoteShopIdExistsInMongo = [];
                foreach ($userData as $data) {
                    foreach ($data['shops'] as $shop) {
                        if (isset($errorMapped[$shop['remote_shop_id']]) && $shop['marketplace'] == 'amazon') {
                            if (isset($shop['warehouses'][0]['status']) && $shop['warehouses'][0]['status'] == 'active') {
                                $warehouseActiveShops[] = $shop['_id'];
                                $homeShopAndRemoteShopPair[$shop['_id']] = $shop['remote_shop_id'];
                            }
                            $remoteShopIdExistsInMongo[] = $shop['remote_shop_id'];
                        }
                    }
                }
                $this->di->getLog()->logContent('remoteShopIdExistsInMongo data: ' . json_encode($remoteShopIdExistsInMongo), 'info', $logFile);
                if (!empty($warehouseActiveShops)) {
                    $this->output->writeln("➤ </><options=bold;fg=green>Shops Found with active warehouse</>");
                    $this->di->getLog()->logContent('Active warehouse shops: ' . json_encode($warehouseActiveShops), 'info', $logFile);
                    $options = [
                        "typeMap" => ['root' => 'array', 'document' => 'array'],
                        "projection" => ['_id' => 0, 'target_shop_id' => 1],
                    ];
                    $configCollection = $mongo->getCollectionForTable('config');
                    $inactiveFeedStatusShop = $configCollection->find(
                        [
                            'group_code' => 'feedStatus',
                            'key' => 'accountStatus',
                            'target_shop_id' => ['$in' => $warehouseActiveShops],
                            'target' => 'amazon',
                            'value' => ['$ne' => 'active']
                        ],
                        $options
                    )->toArray();
                    if (!empty($inactiveFeedStatusShop)) {
                        foreach ($inactiveFeedStatusShop as $shopData) {
                            if (isset($homeShopAndRemoteShopPair[$shopData['target_shop_id']])) {
                                $setWarehouseToInactiveHomeShop[] = $shopData['target_shop_id'];
                            }
                        }
                        if (!empty($setWarehouseToInactiveHomeShop)) {
                            $this->di->getLog()->logContent('Setting warehouse status to inactive for these home_shop_ids: ' . json_encode($setWarehouseToInactiveHomeShop), 'info', $logFile);
                            $this->di->getObjectManager()->get('\App\Amazon\Components\Shop\Hook')
                                ->updateAllShopsWarehouseStatus($setWarehouseToInactiveHomeShop, 'inactive');
                        }
                    } else {
                        $this->output->writeln("➤ </><options=bold;fg=red>No data found from config feed collection</>");
                    }
                } else {
                    $this->output->writeln("➤ </><options=bold;fg=red>No active warehouse shops found</>");
                }
                //Remove remote_shop from SPAPI_Error table if not present in mongo
                $toBeRemoveDynamoIds = array_diff($dynamoRemoteShopIds, $remoteShopIdExistsInMongo);
                if (!empty($toBeRemoveDynamoIds)) {
                    $this->output->writeln("➤ </><options=bold;fg=green>Removing stale remote_shop_ids from SPAPI_Error Table</>");
                    $this->di->getLog()->logContent('Stale Remote_shop_ids removed: ' . json_encode($toBeRemoveDynamoIds), 'info', $logFile);
                    foreach ($toBeRemoveDynamoIds as $remoteShopId) {
                        if (isset($errorMapped[$remoteShopId])) {
                            $prepareData = [
                                'remote_shop_id' => $remoteShopId,
                                'error_code' => $errorMapped[$remoteShopId]
                            ];
                            $commonHelper = $this->di->getObjectManager()->get("\App\Amazon\Components\Common\Helper");
                            $commonHelper->deleteDataFromDynamo($prepareData);
                        }
                    }
                } else {
                    $this->output->writeln("➤ </><options=bold;fg=red>No stale remote_shop_ids found</>");
                }
            } else {
                $this->output->writeln("➤ </><options=bold;fg=red>No data found in mongo for mentioned remote_shop_ids</>");
            }
        } else {
            $this->output->writeln("➤ </><options=bold;fg=red>No data found from SPAPI Error table</>");
        }
    }

    // Updating Missing keys of MatchedProduct Object in Amazon Listing
    public function amazonListingSchemaFix()
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

            $amazonListingColl = $mongo->getCollection("amazon_listing");
            $refineProductColl = $mongo->getCollection("refine_product");

            // Step 1: Fetch affected amazon_listing documents
            $filter = [
                'matchedProduct' => ['$exists' => true],
                'matchedProduct.shop_id' => ['$exists' => false],
            ];
            $amazonDocs = $amazonListingColl->find($filter)->toArray();

            if (empty($amazonDocs)) {
                $this->di->getLog()->logContent("No affected listings found", 'info', 'matched_product_migration.log');
                return;
            }

            // Step 2: Group by shop_id and collect source_product_ids
            $grouped = [];
            foreach ($amazonDocs as $doc) {
                $shopId = $doc['shop_id'];
                $sourceProductId = $doc['source_product_id'] ?? null;

                if ($shopId && $sourceProductId) {
                    $grouped[$shopId][] = $sourceProductId;
                }
            }
            $this->di->getLog()->logContent("Data on the basis of target_shop_id" . json_encode($grouped), 'info', 'matched_product_migration.log');

            // Step 3: Fetch matching refine_product data
            $refineDataMap = [];
            foreach ($grouped as $shopId => $sourceProductIds) {
                $refineDocs = $refineProductColl->find([
                    'items' => [
                        '$elemMatch' => [
                            'shop_id' => (string)$shopId,
                            'source_product_id' => ['$in' => $sourceProductIds]
                        ]
                    ]
                ])->toArray();
                foreach ($refineDocs as $refineDoc) {
                    $containerId = $refineDoc['container_id'];
                    $sourceShopId = $refineDoc['source_shop_id'];
                    foreach ($refineDoc['items'] as $item) {
                        if (
                            isset($item['source_product_id'], $item['shop_id']) &&
                            $item['shop_id'] == $shopId &&
                            in_array($item['source_product_id'], $sourceProductIds)
                        ) {
                            $refineDataMap[$shopId][$item['source_product_id']] = [
                                'container_id' => $containerId,
                                'main_image' => $item['main_image'],
                                'title' => $item['title'],
                                'shop_id' => $sourceShopId,
                                'sku' => $item['sku'],

                            ];
                        }
                    }
                }
            }

            // Step 4: Build bulk update array
            $bulkUpdates = [];
            foreach ($amazonDocs as $doc) {
                $listingId = $doc['_id'];
                $sourceProductId = $doc['source_product_id'];
                $shopId = $doc['shop_id'];
                if (isset($refineDataMap[$shopId][$sourceProductId])) {
                    $refineInfo = $refineDataMap[$shopId][$sourceProductId];

                    $updateData = [
                        'matchedProduct.shop_id' => $refineInfo['shop_id'],
                        'matchedProduct.source_product_id' => $sourceProductId,
                        'matchedProduct.container_id' => $refineInfo['container_id'],
                        'matchedProduct.main_image' => $refineInfo['main_image'],
                        'matchedProduct.title' => $refineInfo['title'],
                        'matchedProduct.sku' => $refineInfo['sku'],
                    ];

                    $bulkUpdates[] = [
                        'updateOne' => [
                            ['_id' => $listingId],
                            ['$set' => $updateData]
                        ]
                    ];
                }
            }
            if (!empty($bulkUpdates)) {
                $amazonListingColl->bulkWrite($bulkUpdates);
                $this->di->getLog()->logContent("Bulk updated: " . count($bulkUpdates), 'info', 'matched_product_migration.log');
            } else {
                $this->di->getLog()->logContent("No updates found after mapping", 'info', 'matched_product_migration.log');
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception: ' . $e->getMessage(), 'error', 'exception.log');
        }
    }

    public function syncOrderFetchingStatusFromDynamo()
    {
        try {
            $logFile = "amazon/script/" . date('d-m-Y') . '.log';
            $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
            $dynamoClientObj = $dynamoObj->getDetails();
            $table = 'user_details';
            $lastEvaluatedKey = null;
            $consolidatedData = [];
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Dynamo Data scanning in progress...</>');
            do {
                $params = [
                    'TableName' => $table
                ];

                if (!is_null($lastEvaluatedKey)) {
                    $params['ExclusiveStartKey'] = $lastEvaluatedKey;
                }
                $scanData = $dynamoClientObj->scan($params);
                $scanData = $this->convertToArray($scanData);
                $consolidatedData = array_merge($consolidatedData, $scanData['Items']);
                $lastEvaluatedKey = isset($scanData['LastEvaluatedKey']) ? $scanData['LastEvaluatedKey'] : null;
            } while (!is_null($lastEvaluatedKey));
            if (!empty($consolidatedData)) {
                $orderSyncEnabledShops = [];
                $orderSyncDisabledShops = [];
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $userDetails = $mongo->getCollectionForTable('user_details');
                foreach ($consolidatedData as $data) {
                    if (isset($data['disable_order_sync'])) {
                        if (!$data['disable_order_sync']) {
                            $orderSyncEnabledShops[] = (string)$data['id'];
                        } else {
                            $orderSyncDisabledShops[] = (string)$data['id'];
                        }
                    } else {
                        $this->di->getLog()->logContent('disable_order_sync not found for remote_shop_id: ' . $data['id'], 'info', $logFile);
                    }
                }
                if (!empty($orderSyncEnabledShops)) {
                    $this->di->getLog()->logContent('orderSyncEnabledShops: ' . json_encode($orderSyncEnabledShops), 'info', $logFile);
                    $userDetails->updateMany(
                        [
                            'shops.remote_shop_id' => ['$in' => $orderSyncEnabledShops],
                            'shops.marketplace' => 'amazon'
                        ],
                        ['$set' => ['order_fetch_enabled' => true]]
                    );
                }
                if (!empty($orderSyncDisabledShops)) {
                    $this->di->getLog()->logContent('orderSyncDisabledShops: ' . json_encode($orderSyncDisabledShops), 'info', $logFile);
                    $userDetails->updateMany(
                        [
                            'shops.remote_shop_id' => ['$in' => $orderSyncDisabledShops],
                            'shops.marketplace' => 'amazon'
                        ],
                        ['$set' => ['order_fetch_enabled' => false]]
                    );
                }
            } else {
                $this->di->getLog()->logContent('No data found in dynamo table', 'info', $logFile);
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>No data found in dynamo table</>');
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception: ' . $e->getMessage(), 'error', 'exception.log');
        }
    }

    public function disconnectInactivePlanUsers()
    {
        try {
            $appTag = $this->input->getOption("app_tag") ?? false;
            $source = $this->input->getOption("source") ?? false;
            if (!$appTag || !$source) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><fg=red>To execute this method app_tag and source field is required(-t and -s)</>');
                return 0;
            }
            $logFile = "amazon/inactive_plan_disconnection/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $paymentDetailsCollection = $mongo->getCollectionForTable('payment_details');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $queueName = 'disconnect_amazon_account';

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting inactive plan disconnection process...</>');
            $this->di->getLog()->logContent('Starting inactive plan disconnection process', 'info', $logFile);
            $pipeline = $this->buildInactivePlanPaymentDetailsPipeline();
            $paymentData = $paymentDetailsCollection->aggregate($pipeline, $arrayParams)->toArray();
            if (empty($paymentData)) {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>No users found with inactive plans</>');
                $this->di->getLog()->logContent('No users found with inactive plans', 'info', $logFile);
                return;
            }

            $userIds = array_column($paymentData, '_id');
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Found ' . count($userIds) . ' users with inactive plans</>');
            $this->di->getLog()->logContent('Found ' . count($userIds) . ' users with inactive plans: ' . json_encode($userIds), 'info', $logFile);

            $userData = $userDetailsCollection->find(
                [
                    'user_id' => ['$in' => $userIds],
                    'shops' => [
                        '$elemMatch' => [
                            'marketplace' => 'amazon',
                            'apps.app_status' => 'active'
                        ]
                    ]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => ['user_id' => 1, 'shops' => 1]
                ]
            )->toArray();
            if (empty($userData)) {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>No users found matching criteria</>');
                $this->di->getLog()->logContent('No users found matching criteria', 'info', $logFile);
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing ' . count($userData) . ' users</>');
            $this->di->getLog()->logContent('Processing ' . count($userData) . ' users', 'info', $logFile);

            foreach ($userData as $user) {
                $userId = $user['user_id'];
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Processing user: ' . $userId, 'info', $logFile);

                if (empty($user['shops'])) {
                    $this->di->getLog()->logContent('No shops found for user: ' . $userId, 'info', $logFile);
                    continue;
                }

                $hasActiveAmazonShops = false;
                $pushedMessagesCount = 0;

                $sourceShop = null;
                foreach ($user['shops'] as $shop) {
                    if (isset($shop['marketplace']) && $shop['marketplace'] == $source) {
                        $sourceShop = $shop;
                        break;
                    }
                }

                foreach ($user['shops'] as $shop) {
                    if (
                        isset($shop['marketplace']) &&
                        $shop['marketplace'] == 'amazon' &&
                        isset($shop['apps'][0]['app_status']) &&
                        $shop['apps'][0]['app_status'] == 'active'
                    ) {
                        $shopId = $shop['_id'];
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Pushing queue message for shop: ' . $shopId . ' for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('Pushing queue message for shop: ' . $shopId . ' for user: ' . $userId, 'info', $logFile);

                        try {
                            $data = [
                                'target' => [
                                    'marketplace' => 'amazon',
                                    'shopId' => (string)$shopId
                                ],
                                'source' => [
                                    'marketplace' => $sourceShop ? $sourceShop['marketplace'] : 'shopify',
                                    'shopId' => $sourceShop ? (string)$sourceShop['_id'] : ''
                                ],
                                'disconnected' => [
                                    'target' => 1
                                ]
                            ];
                            $handlerData = [
                                'type' => 'full_class',
                                'class_name' => '\App\Amazon\Components\ShopDelete',
                                'method' => 'initiateAccountDisconnectionInactivePlan',
                                'queue_name' => $queueName,
                                'user_id' => $userId,
                                'data' => $data,
                                'app_code' => $shop['apps'][0]['code'],
                                "appTag" => $appTag,
                                "appCode" => $this->di->getConfig()->get('app_tags')->get($appTag)->get('app_code')->toArray()
                            ];
                            $sqsHelper->pushMessage($handlerData);
                            $pushedMessagesCount++;
                            $hasActiveAmazonShops = true;
                            $this->di->getLog()->logContent('Successfully pushed message to queue for shop: ' . $shopId . ' for user: ' . $userId, 'info', $logFile);
                        } catch (Exception $e) {
                            $this->di->getLog()->logContent('Exception while pushing message for shop ' . $shopId . ': ' . $e->getMessage(), 'error', $logFile);
                            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception while pushing message for shop: ' . $shopId . '</>');
                        }
                    }
                }

                if ($hasActiveAmazonShops && $pushedMessagesCount > 0) {
                    try {
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Pushed ' . $pushedMessagesCount . ' messages to queue for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('Pushed ' . $pushedMessagesCount . ' messages to queue for user: ' . $userId, 'info', $logFile);

                        $paymentDetailsCollection->updateMany(
                            [
                                'user_id' => $userId,
                                'type' => 'active_plan',
                                'inactivated_on' => ['$exists' => true]
                            ],
                            [
                                '$set' => ['inactive_plan_disconnection_at' => date('c')]
                            ]
                        );
                        $userDetailsCollection->updateOne(
                            [
                                'user_id' => $userId,
                            ],
                            [
                                '$set' => ['inactive_plan_accounts_disconnection_completed_at' => date('c')]
                            ]
                        );
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Updated payment_details for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('Updated payment_details for user: ' . $userId, 'info', $logFile);
                    } catch (Exception $e) {
                        $this->di->getLog()->logContent('Exception while updating payment_details for user ' . $userId . ': ' . $e->getMessage(), 'error', $logFile);
                        $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception while updating payment_details for user: ' . $userId . '</>');
                    }
                }
            }

            $this->di->getLog()->logContent('Process completed', 'info', $logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in disconnectInactivePlanUsers(): ' . $e->getMessage(), 'info', $logFile ?? 'exception.log');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception: ' . $e->getMessage() . '</>');
        }
    }

    public function notifyBdaInactivePlanAutoDisconnect()
    {
        try {
            $logFile = "amazon/inactive_plan_disconnection/bda_notify_" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $paymentDetailsCollection = $mongo->getCollectionForTable('payment_details');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];

            $bdaRecipients = $this->di->getConfig()->get('bda_email_for_inactive_plan_users_notification') ? $this->di->getConfig()->get('bda_email_for_inactive_plan_users_notification')->toArray() : [];
            if (empty($bdaRecipients)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><fg=red>Set bda_email_for_inactive_plan_users_notification (non-empty array) in config</>');
                $this->di->getLog()->logContent('notifyBdaInactivePlanAutoDisconnect: bda_email_for_inactive_plan_users_notification empty or missing', 'error', $logFile);
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting BDA inactive-plan auto-disconnect notification...</>');
            $this->di->getLog()->logContent('Starting BDA inactive-plan auto-disconnect notification', 'info', $logFile);

            $pipeline = $this->buildInactivePlanPaymentDetailsPipeline();
            $paymentData = $paymentDetailsCollection->aggregate($pipeline, $arrayParams)->toArray();
            if (empty($paymentData)) {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>No users from inactive-plan pipeline</>');
                $this->di->getLog()->logContent('No users from inactive-plan pipeline', 'info', $logFile);
                return;
            }

            $userIds = array_column($paymentData, '_id');
            $userData = $userDetailsCollection->find(
                [
                    'user_id' => ['$in' => $userIds],
                    'inactive_plan_accounts_disconnection_after' => ['$exists' => false],
                    'inactive_plan_accounts_disconnection_completed_at' => ['$exists' => false],
                    'shops' => [
                        '$elemMatch' => [
                            'marketplace' => 'amazon',
                            'apps.app_status' => 'active',
                        ],
                    ],
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => [
                        'user_id' => 1,
                        'username' => 1,
                        'email' => 1,
                        'source_email' => 1,
                    ],
                ]
            )->toArray();
            if (empty($userData)) {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>No eligible user_details rows (or all already scheduled / disconnected)</>');
                $this->di->getLog()->logContent('No eligible user_details rows', 'info', $logFile);
                return;
            }

            $csvRows = [];
            $csvRows[] = ['shop_url', 'email', 'user_id'];
            $updateUserIds = [];
            foreach ($userData as $user) {
                $userId = $user['user_id'] ?? '';
                if ($userId === '') {
                    continue;
                }
                $shopUrl = $user['username'] ?? '';
                $email = $user['source_email'] ?? ($user['email'] ?? '');
                $csvRows[] = [$shopUrl, $email, (string) $userId];
                $updateUserIds[] = $userId;
            }

            if (count($updateUserIds) === 0) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><fg=yellow>No rows to export after filtering</>');
                return;
            }

            $autoDisconnectAfter = date('c', strtotime('+1 week'));
            $notifyAt = date('c');
            $dirname = BP . DS . 'var' . DS . 'log' . DS . 'script' . DS . 'inactive_plan_bda';
            if (!is_dir($dirname)) {
                mkdir($dirname, 0777, true);
            }
            $csvFilePath = $dirname . DS . 'inactive_plan_bda_notify_' . date('Y-m-d_His') . '.csv';
            $fh = fopen($csvFilePath, 'wb');
            if ($fh === false) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><fg=red>Could not create CSV file</>');
                $this->di->getLog()->logContent('Could not create CSV at ' . $csvFilePath, 'error', $logFile);
                return;
            }
            foreach ($csvRows as $row) {
                fputcsv($fh, $row);
            }
            fclose($fh);

            $sendMail = $this->di->getObjectManager()->get('App\Core\Components\SendMail');
            $allMailsOk = true;
            foreach ($bdaRecipients as $bdaTo) {
                $msgData = [
                    'app_name' => $this->di->getConfig()->app_name,
                    'name' => 'Team',
                    'email' => $bdaTo,
                    'subject' => 'Inactive plan sellers (3+ months) — contact sellers before 1-week auto-disconnect',
                    'seller_count' => count($updateUserIds),
                    'auto_disconnect_after' => $autoDisconnectAfter,
                    'auto_disconnect_after_display' => date('F j, Y g:i A T', strtotime($autoDisconnectAfter)),
                    'content' => 'Seller list (CSV attachment)',
                    'path' => 'plan' . DS . 'view' . DS . 'email' . DS . 'inactivePlanUserBDANotify.volt',
                    'files' => [
                        [
                            'name' => 'inactive-plan-sellers.csv',
                            'path' => $csvFilePath,
                        ],
                    ],
                ];
                $mailResponse = $sendMail->send($msgData);
                if (!$mailResponse) {
                    $allMailsOk = false;
                    $this->di->getLog()->logContent('BDA mail failed for: ' . $bdaTo, 'error', $logFile);
                    $this->output->writeln('<options=bold;bg=red> ➤ </><fg=red>Mail send failed for: ' . $bdaTo . '</>');
                } else {
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Mail sent to: ' . $bdaTo . '</>');
                }
            }

            if ($allMailsOk) {
                $userDetailsCollection->updateMany(
                    ['user_id' => ['$in' => $updateUserIds]],
                    [
                        '$set' => [
                            'inactive_plan_accounts_disconnection_after' => $autoDisconnectAfter,
                            'inactive_plan_bda_notified_at' => $notifyAt,
                        ],
                    ]
                );
                $this->di->getLog()->logContent(
                    'BDA mail sent to all recipients; updated ' . count($updateUserIds) . ' users: ' . json_encode($updateUserIds),
                    'info',
                    $logFile
                );
                $this->output->writeln(
                    '<options=bold;bg=green> ➤ </><options=bold;fg=green>user_details updated for '
                    . count($updateUserIds) . ' user(s)</>'
                );
            } else {
                $this->di->getLog()->logContent('One or more BDA mails failed; user_details not updated', 'error', $logFile);
                $this->output->writeln('<options=bold;bg=red> ➤ </><fg=red>user_details not updated (fix failed sends and re-run)</>');
            }

            if (is_file($csvFilePath)) {
                unlink($csvFilePath);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                'Exception in notifyBdaInactivePlanAutoDisconnect(): ' . $e->getMessage(),
                'error',
                $logFile ?? 'exception.log'
            );
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception: ' . $e->getMessage() . '</>');
        }
    }

    private function buildInactivePlanPaymentDetailsPipeline()
    {
        return [
            [
                '$match' => [
                    'type' => 'active_plan',
                    'inactive_plan_disconnection_at' => ['$exists' => false],
                ],
            ],
            [
                '$group' => [
                    '_id' => '$user_id',
                    'hasActivePlan' => [
                        '$max' => [
                            '$cond' => [
                                ['$eq' => ['$status', 'active']],
                                1,
                                0,
                            ],
                        ],
                    ],
                    'latestInactivatedOn' => [
                        '$max' => '$inactivated_on',
                    ],
                ],
            ],
            [
                '$match' => [
                    'hasActivePlan' => 0,
                    'latestInactivatedOn' => [
                        '$lt' => date('c', strtotime('-3 months')),
                    ],
                ],
            ],
            [
                '$sort' => ['latestInactivatedOn' => 1],
            ],
        ];
    }

    public function disconnectInactivePlanAccountsAfterScheduledDate()
    {
        try {
            $appTag = $this->input->getOption("app_tag") ?? false;
            $source = $this->input->getOption("source") ?? false;
            if (!$appTag || !$source) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><fg=red>To execute this method app_tag and source field is required(-t and -s)</>');
                return 0;
            }
            $logFile = "amazon/inactive_plan_disconnection/scheduled_" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $queueName = 'disconnect_amazon_account';
            $nowIso = date('Y-m-d');

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting inactive plan scheduled account disconnection...</>');
            $this->di->getLog()->logContent('Starting inactive plan scheduled account disconnection', 'info', $logFile);

            $userData = $userDetailsCollection->find(
                [
                    'inactive_plan_accounts_disconnection_after' => ['$lte' => $nowIso],
                    'inactive_plan_accounts_disconnection_completed_at' => ['$exists' => false],
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => ['user_id' => 1, 'shops' => 1],
                ]
            )->toArray();

            if (empty($userData)) {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>No users with due inactive_plan_accounts_disconnection_after</>');
                $this->di->getLog()->logContent('No users with due inactive_plan_accounts_disconnection_after', 'info', $logFile);
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing ' . count($userData) . ' user(s)</>');
            $this->di->getLog()->logContent('Processing ' . count($userData) . ' user(s)', 'info', $logFile);

            foreach ($userData as $user) {
                $userId = $user['user_id'];
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Processing user: ' . $userId, 'info', $logFile);

                $planChecker = $this->di->getObjectManager()->get('\App\Plan\Models\Plan');
                $planData = $planChecker->getActivePlanForCurrentUser($userId);
                if (!empty($planData)) {
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$unset' => [
                                'inactive_plan_accounts_disconnection_after' => 1,
                                'inactive_plan_bda_notified_at' => 1,
                            ],
                        ]
                    );
                    $this->output->writeln(
                        '<options=bold;bg=yellow> ➤ </><fg=yellow>Skipping user: ' . $userId
                        . ' — active plan present; cleared BDA schedule fields</>'
                    );
                    $this->di->getLog()->logContent(
                        'Skipping user: ' . $userId . ' — active plan present; unset inactive_plan_accounts_disconnection_after and inactive_plan_bda_notified_at',
                        'info',
                        $logFile
                    );
                    continue;
                }

                if (empty($user['shops'])) {
                    $this->di->getLog()->logContent('No shops found for user: ' . $userId . ' — marking schedule cleared', 'info', $logFile);
                    $this->markInactivePlanScheduledDisconnectionCompleted($userDetailsCollection, $userId, $logFile);
                    continue;
                }

                $sourceShop = null;
                foreach ($user['shops'] as $shop) {
                    if (isset($shop['marketplace']) && $shop['marketplace'] == $source) {
                        $sourceShop = $shop;
                        break;
                    }
                }

                $pushedMessagesCount = 0;
                $hadActiveAmazonShop = false;

                foreach ($user['shops'] as $shop) {
                    if (
                        isset($shop['marketplace']) &&
                        $shop['marketplace'] == 'amazon' &&
                        isset($shop['apps'][0]['app_status']) &&
                        $shop['apps'][0]['app_status'] == 'active'
                    ) {
                        $hadActiveAmazonShop = true;
                        $shopId = $shop['_id'];
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Pushing queue message for shop: ' . $shopId . ' for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('Pushing queue message for shop: ' . $shopId . ' for user: ' . $userId, 'info', $logFile);

                        try {
                            $data = [
                                'target' => [
                                    'marketplace' => 'amazon',
                                    'shopId' => (string) $shopId,
                                ],
                                'source' => [
                                    'marketplace' => $sourceShop ? $sourceShop['marketplace'] : 'shopify',
                                    'shopId' => $sourceShop ? (string) $sourceShop['_id'] : '',
                                ],
                                'disconnected' => [
                                    'target' => 1,
                                ],
                            ];
                            $handlerData = [
                                'type' => 'full_class',
                                'class_name' => '\App\Amazon\Components\ShopDelete',
                                'method' => 'initiateAccountDisconnectionInactivePlan',
                                'queue_name' => $queueName,
                                'user_id' => $userId,
                                'data' => $data,
                                'app_code' => $shop['apps'][0]['code'],
                                'appTag' => $appTag,
                                'appCode' => $this->di->getConfig()->get('app_tags')->get($appTag)->get('app_code')->toArray(),
                            ];
                            $sqsHelper->pushMessage($handlerData);
                            $pushedMessagesCount++;
                            $this->di->getLog()->logContent('Successfully pushed message to queue for shop: ' . $shopId . ' for user: ' . $userId, 'info', $logFile);
                        } catch (Exception $e) {
                            $this->di->getLog()->logContent('Exception while pushing message for shop ' . $shopId . ': ' . $e->getMessage(), 'error', $logFile);
                            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception while pushing message for shop: ' . $shopId . '</>');
                        }
                    }
                }

                if ($pushedMessagesCount > 0) {
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Pushed ' . $pushedMessagesCount . ' message(s) to queue for user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('Pushed ' . $pushedMessagesCount . ' message(s) to queue for user: ' . $userId, 'info', $logFile);
                    $this->markInactivePlanScheduledDisconnectionCompleted($userDetailsCollection, $userId, $logFile);
                } elseif (!$hadActiveAmazonShop) {
                    $this->di->getLog()->logContent('No active Amazon shops for user: ' . $userId . ' — marking schedule cleared', 'info', $logFile);
                    $this->markInactivePlanScheduledDisconnectionCompleted($userDetailsCollection, $userId, $logFile);
                } else {
                    $this->di->getLog()->logContent('No messages pushed for user: ' . $userId . ' (retain schedule for retry)', 'info', $logFile);
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><fg=yellow>No queue messages pushed for user: ' . $userId . ' — will retry on next run</>');
                }
            }

            $this->di->getLog()->logContent('disconnectInactivePlanAccountsAfterScheduledDate completed', 'info', $logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                'Exception in disconnectInactivePlanAccountsAfterScheduledDate(): ' . $e->getMessage(),
                'error',
                $logFile ?? 'exception.log'
            );
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception: ' . $e->getMessage() . '</>');
        }
    }

    private function markInactivePlanScheduledDisconnectionCompleted($userDetailsCollection, $userId, string $logFile): void
    {
        try {
            $userDetailsCollection->updateOne(
                ['user_id' => $userId],
                [
                    '$set' => ['inactive_plan_accounts_disconnection_completed_at' => date('c')],
                    '$unset' => ['inactive_plan_accounts_disconnection_after' => 1],
                ]
            );
            $this->di->getLog()->logContent('Set inactive_plan_accounts_disconnection_completed_at and unset inactive_plan_accounts_disconnection_after for user: ' . $userId, 'info', $logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('markInactivePlanScheduledDisconnectionCompleted failed for user ' . $userId . ': ' . $e->getMessage(), 'error', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Failed to update user_details for user: ' . $userId . '</>');
        }
    }

    public function disconnectAccountsAfterAutoDisconnectDate()
    {
        try {
            $appTag = $this->input->getOption("app_tag") ?? false;
            $source = $this->input->getOption("source") ?? false;
            if (!$appTag || !$source) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><fg=red>To execute this method app_tag and source field is required(-t and -s)</>');
                return 0;
            }
            $logFile = "amazon/script/auto_disconnect_accounts/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $queueName = 'disconnect_amazon_account';

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting auto disconnect accounts process...</>');
            $this->di->getLog()->logContent('Starting auto disconnect accounts process', 'info', $logFile);

            // Fetch users where auto_disconnect_account_after <= current date and accounts_disconnected doesn't exist
            $userData = $userDetailsCollection->find(
                [
                    'auto_disconnect_account_after' => ['$lte' => date('c')],
                    'account_auto_disconnected_at' =>  ['$exists' => false]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => ['user_id' => 1, 'shops' => 1, 'plan_tier' => 1]
                ]
            )->toArray();
            if (empty($userData)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>No users found with auto_disconnect_account_after date reached</>');
                $this->di->getLog()->logContent('No users found with auto_disconnect_account_after date reached', 'info', $logFile);
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Found ' . count($userData) . ' users to process</>');
            $this->di->getLog()->logContent('Found ' . count($userData) . ' users to process', 'info', $logFile);

            foreach ($userData as $user) {
                $userId = $user['user_id'];
                if (!isset($user['plan_tier']) || $user['plan_tier'] == 'paid') {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping user: ' . $userId . ' - plan_tier is paid or not set</>');
                    $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - plan_tier is paid or not set', 'info', $logFile);
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$unset' => [
                                'auto_disconnect_account_after' => 1,
                                'shops.$[shop].auto_disconnect_account_after' => 1,
                            ],
                        ],
                        [
                            'arrayFilters' => [
                                ['shop.marketplace' => 'amazon', 'shop.auto_disconnect_account_after' => ['$exists' => true]],
                            ],
                        ]
                    );
                    continue;
                }
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Processing user: ' . $userId, 'info', $logFile);

                if (empty($user['shops'])) {
                    $this->di->getLog()->logContent('No shops found for user: ' . $userId, 'info', $logFile);
                    // Mark user as processed even if no shops
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        ['$unset' => ['auto_disconnect_account_after' => 1]]
                    );
                    continue;
                }

                $pushedMessagesCount = 0;
                $hasProcessedShops = false;

                // Find source shop
                $sourceShop = null;
                foreach ($user['shops'] as $shop) {
                    if (isset($shop['marketplace']) && $shop['marketplace'] == $source) {
                        $sourceShop = $shop;
                        break;
                    }
                }

                // If source shop is empty, skip this user
                if (empty($sourceShop)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping user: ' . $userId . ' - source shop not found</>');
                    $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - source shop not found for marketplace: ' . $source, 'info', $logFile);
                    // Mark user as processed even if source shop not found
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        ['$unset' => ['auto_disconnect_account_after' => 1]]
                    );
                    continue;
                }

                // Loop through shops - keep first amazon shop, disconnect others
                $firstShopProcessed = false;
                foreach ($user['shops'] as $shop) {
                    if (
                        isset($shop['marketplace']) &&
                        $shop['marketplace'] == 'amazon' &&
                        isset($shop['apps'][0]['app_status']) &&
                        $shop['apps'][0]['app_status'] == 'active'
                    ) {
                        // Skip the first amazon shop
                        if (!$firstShopProcessed) {
                            $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping first amazon shop: ' . $shop['_id'] . ' for user: ' . $userId . '</>');
                            $this->di->getLog()->logContent('Skipping first amazon shop: ' . $shop['_id'] . ' for user: ' . $userId, 'info', $logFile);
                            $firstShopProcessed = true;
                            continue;
                        }

                        $shopId = $shop['_id'];
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Pushing queue message for shop: ' . $shopId . ' for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('Pushing queue message for shop: ' . $shopId . ' for user: ' . $userId, 'info', $logFile);

                        try {
                            $data = [
                                'target' => [
                                    'marketplace' => 'amazon',
                                    'shopId' => (string)$shopId
                                ],
                                'source' => [
                                    'marketplace' => $sourceShop ? $sourceShop['marketplace'] : $source,
                                    'shopId' => $sourceShop ? (string)$sourceShop['_id'] : ''
                                ],
                                'disconnected' => [
                                    'target' => 1
                                ]
                            ];
                            $handlerData = [
                                'type' => 'full_class',
                                'class_name' => '\App\Amazon\Components\ShopDelete',
                                'method' => 'initiateAccountDisconnectionInactivePlan',
                                'queue_name' => $queueName,
                                'user_id' => $userId,
                                'data' => $data,
                                'app_code' => $shop['apps'][0]['code'],
                                "appTag" => $appTag,
                                "appCode" => $this->di->getConfig()->get('app_tags')->get($appTag)->get('app_code')->toArray()
                            ];
                            $sqsHelper->pushMessage($handlerData);
                            $pushedMessagesCount++;
                            $hasProcessedShops = true;
                            $this->di->getLog()->logContent('Successfully pushed message to queue for shop: ' . $shopId . ' for user: ' . $userId, 'info', $logFile);
                        } catch (Exception $e) {
                            $this->di->getLog()->logContent('Exception while pushing message for shop ' . $shopId . ': ' . $e->getMessage(), 'error', $logFile);
                            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception while pushing message for shop: ' . $shopId . '</>');
                        }
                    }
                }

                // Mark user as processed
                try {
                    if ($hasProcessedShops && $pushedMessagesCount > 0) {
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Pushed ' . $pushedMessagesCount . ' messages to queue for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('Pushed ' . $pushedMessagesCount . ' messages to queue for user: ' . $userId, 'info', $logFile);

                        $userDetailsCollection->updateOne(
                            ['user_id' => $userId],
                            [
                                '$set' => ['account_auto_disconnected_at' => date('c')],
                                '$unset' => [
                                    'auto_disconnect_account_after' => 1,
                                    'shops.$[shop].auto_disconnect_account_after' => 1
                                ]
                            ],
                            [
                                'arrayFilters' => [
                                    ['shop.marketplace' => 'amazon', 'shop.auto_disconnect_account_after' => ['$exists' => true]]
                                ]
                            ]
                        );
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Marked user: ' . $userId . ' as accounts_disconnected</>');
                        $this->di->getLog()->logContent('Marked user: ' . $userId . ' as accounts_disconnected', 'info', $logFile);
                    } elseif (!$hasProcessedShops) {
                        $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>No active shop found for user or only 1 shop is present: ' . $userId . '</>');
                        $this->di->getLog()->logContent('No active shop found for user or only 1 shop is present: ' . json_encode(['user_id' => $userId, 'shops' => $user['shops']]), 'info', $logFile);
                        $userDetailsCollection->updateOne(
                            ['user_id' => $userId],
                            [
                                '$unset' => [
                                    'auto_disconnect_account_after' => 1,
                                    'shops.$[shop].auto_disconnect_account_after' => 1
                                ]
                            ],
                            [
                                'arrayFilters' => [
                                    ['shop.marketplace' => 'amazon', 'shop.auto_disconnect_account_after' => ['$exists' => true]]
                                ]
                            ]
                        );
                    }
                } catch (Exception $e) {
                    $this->di->getLog()->logContent('Exception while marking user ' . $userId . ' as accounts_disconnected: ' . $e->getMessage(), 'error', $logFile);
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception while marking user: ' . $userId . '</>');
                }
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Process completed</>');
            $this->di->getLog()->logContent('Process completed', 'info', $logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in disconnectAccountsAfterAutoDisconnectDate(): ' . $e->getMessage(), 'error', $logFile ?? 'exception.log');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception: ' . $e->getMessage() . '</>');
        }
    }

    public function removeExtraTemplatesAfterAutoRemoveDate()
    {
        try {
            $appTag = $this->input->getOption("app_tag") ?? false;
            $source = $this->input->getOption("source") ?? false;
            if (!$appTag || !$source) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><fg=red>To execute this method app_tag and source field is required(-t and -s)</>');
                return 0;
            }
            $logFile = "amazon/script/auto_remove_templates/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $profileCollection = $mongo->getCollectionForTable('profile');
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $queueName = 'delete_profile';

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting auto remove extra templates process...</>');
            $this->di->getLog()->logContent('Starting auto remove extra templates process', 'info', $logFile);

            $userData = $userDetailsCollection->find(
                [
                    'auto_remove_extra_templates_after' => ['$lte' => date('c')],
                    'auto_removed_extra_template_at' => ['$exists' => false]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => ['user_id' => 1, 'shops' => 1, 'plan_tier' => 1]
                ]
            )->toArray();
            if (empty($userData)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>No users found with auto_remove_extra_templates_after date reached</>');
                $this->di->getLog()->logContent('No users found with auto_remove_extra_templates_after date reached', 'info', $logFile);
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Found ' . count($userData) . ' users to process</>');
            $this->di->getLog()->logContent('Found ' . count($userData) . ' users to process', 'info', $logFile);

            foreach ($userData as $user) {
                $userId = $user['user_id'];
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Processing user: ' . $userId, 'info', $logFile);

                // Find source shop
                $sourceShop = null;
                if (!empty($user['shops'])) {
                    foreach ($user['shops'] as $shop) {
                        if (isset($shop['marketplace']) && $shop['marketplace'] == $source) {
                            $sourceShop = $shop;
                            break;
                        }
                    }
                }

                if (!isset($user['plan_tier']) || $user['plan_tier'] == 'paid') {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping user: ' . $userId . ' - plan_tier is paid or not set</>');
                    $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - plan_tier is paid or not set', 'info', $logFile);
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$unset' => [
                                'auto_remove_extra_templates_after' => 1,
                                'shops.$[shop].auto_remove_extra_templates_after' => 1
                            ]
                        ],
                        [
                            'arrayFilters' => [
                                ['shop.marketplace' => 'amazon', 'shop.auto_remove_extra_templates_after' => ['$exists' => true]]
                            ]
                        ]
                    );
                    continue;
                }

                if (empty($sourceShop)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping user: ' . $userId . ' - source shop not found</>');
                    $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - source shop not found for marketplace: ' . $source, 'info', $logFile);
                    // Mark user as processed even if source shop not found
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$unset' => [
                                'auto_remove_extra_templates_after' => 1,
                                'shops.$[shop].auto_remove_extra_templates_after' => 1
                            ]
                        ],
                        [
                            'arrayFilters' => [
                                ['shop.marketplace' => 'amazon', 'shop.auto_remove_extra_templates_after' => ['$exists' => true]]
                            ]
                        ]
                    );
                    continue;
                }

                // Fetch all profiles for this user
                $profileData = $profileCollection->find(
                    [
                        'user_id' => $userId,
                        'type' => 'profile'
                    ],
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'projection' => ['_id' => 1],
                        'sort' => ['_id' => 1]
                    ]
                )->toArray();

                if (empty($profileData)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>No profiles found for user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('No profiles found for user: ' . $userId, 'info', $logFile);
                    // Mark user as processed
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$unset' => [
                                'auto_remove_extra_templates_after' => 1,
                                'shops.$[shop].auto_remove_extra_templates_after' => 1
                            ]
                        ],
                        [
                            'arrayFilters' => [
                                ['shop.marketplace' => 'amazon', 'shop.auto_remove_extra_templates_after' => ['$exists' => true]]
                            ]
                        ]
                    );
                    continue;
                }

                // Get _id values using array_column and convert to string using array_map
                $profileIds = array_map('strval', array_column($profileData, '_id'));

                // Handle case if only 1 profile is present
                if (count($profileIds) == 1) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Only one template is present for user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('Only one template is present for user: ' . $userId, 'info', $logFile);
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$unset' => [
                                'auto_remove_extra_templates_after' => 1,
                                'shops.$[shop].auto_remove_extra_templates_after' => 1
                            ]
                        ],
                        [
                            'arrayFilters' => [
                                ['shop.marketplace' => 'amazon', 'shop.auto_remove_extra_templates_after' => ['$exists' => true]]
                            ]
                        ]
                    );
                    continue;
                }

                $pushedMessagesCount = 0;
                $hasProcessedProfiles = false;

                $firstTemplateProcessed = false;
                foreach ($profileIds as $profileId) {
                    // Skip the first template
                    if (!$firstTemplateProcessed) {
                        $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping first template: ' . $profileId . ' for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('Skipping first template: ' . $profileId . ' for user: ' . $userId, 'info', $logFile);
                        $firstTemplateProcessed = true;
                        continue;
                    }
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Pushing queue message for profile: ' . $profileId . ' for user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('Pushing queue message for profile: ' . $profileId . ' for user: ' . $userId, 'info', $logFile);

                    try {
                        $handlerData = [
                            'type' => 'full_class',
                            'class_name' => '\App\Connector\Components\Profile\ProfileHelper',
                            'method' => 'deleteProfile',
                            'queue_name' => $queueName,
                            'user_id' => $userId,
                            'id' => $profileId,
                            'app_code' => $sourceShop['apps'][0]['code'] ?? '',
                            "appTag" => $appTag,
                            "appCode" => $this->di->getConfig()->get('app_tags')->get($appTag)->get('app_code')->toArray()
                        ];
                        $sqsHelper->pushMessage($handlerData);
                        $pushedMessagesCount++;
                        $hasProcessedProfiles = true;
                        $this->di->getLog()->logContent('Successfully pushed message to queue for profile: ' . $profileId . ' for user: ' . $userId, 'info', $logFile);
                    } catch (Exception $e) {
                        $this->di->getLog()->logContent('Exception while pushing message for profile ' . $profileId . ': ' . $e->getMessage(), 'error', $logFile);
                        $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception while pushing message for profile: ' . $profileId . '</>');
                    }
                }

                // Mark user as processed
                try {
                    if ($hasProcessedProfiles && $pushedMessagesCount > 0) {
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Pushed ' . $pushedMessagesCount . ' messages to queue for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('Pushed ' . $pushedMessagesCount . ' messages to queue for user: ' . $userId, 'info', $logFile);

                        $userDetailsCollection->updateOne(
                            ['user_id' => $userId],
                            [
                                '$set' => ['auto_removed_extra_template_at' => date('c')],
                                '$unset' => [
                                    'auto_remove_extra_templates_after' => 1,
                                    'shops.$[shop].auto_remove_extra_templates_after' => 1
                                ]
                            ],
                            [
                                'arrayFilters' => [
                                    ['shop.marketplace' => 'amazon', 'shop.auto_remove_extra_templates_after' => ['$exists' => true]]
                                ]
                            ]
                        );
                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Marked user: ' . $userId . ' as templates_removed</>');
                        $this->di->getLog()->logContent('Marked user: ' . $userId . ' as templates_removed', 'info', $logFile);
                    }
                } catch (Exception $e) {
                    $this->di->getLog()->logContent('Exception while marking user ' . $userId . ' as templates_removed: ' . $e->getMessage(), 'error', $logFile);
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception while marking user: ' . $userId . '</>');
                }
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Process completed</>');
            $this->di->getLog()->logContent('Process completed', 'info', $logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in removeExtraTemplatesAfterAutoRemoveDate(): ' . $e->getMessage(), 'error', $logFile ?? 'exception.log');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception: ' . $e->getMessage() . '</>');
        }
    }

    public function syncAndUpdateAccountConnectionCreditsFreePlanUsers()
    {
        try {
            $logFile = "amazon/script/" . date('d-m-Y') . '.log';
            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Account Connection Credits Update...</>');
            $this->di->getLog()->logContent('Starting updateAccountConnectionCredits script', 'info', $logFile);

            $source = $this->input->getOption("source") ?? false;
            if (!$source) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Source marketplace (-s) is mandatory</>');
                $this->di->getLog()->logContent('Source marketplace not provided', 'error', $logFile);
                return 0;
            }

            $appTag = $this->input->getOption("app_tag") ?? false;
            if (!$appTag) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> App tag (-t) is mandatory</>');
                $this->di->getLog()->logContent('App tag not provided', 'error', $logFile);
                return 0;
            }

            $defaultAccountCredits = 1;
            $targetMarketplace = 'amazon';

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            // Step 1: Fetch all free plan users (excluding already processed ones)
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Fetching free plan users...</>');
            $this->di->getLog()->logContent('Fetching free plan users', 'info', $logFile);

            $paymentCollection = $mongo->getCollectionForTable('payment_details');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];

            $freePlanUserIds = $paymentCollection->distinct('user_id', [
                'status' => 'active',
                'type' => 'active_plan',
                'plan_details.custom_price' => 0,
                'picked' => ['$exists' => false]
            ], $arrayParams);
            if (empty($freePlanUserIds)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No free plan users found</>');
                $this->di->getLog()->logContent('No free plan users found', 'info', $logFile);
                return 0;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($freePlanUserIds) . ' free plan users</>');
            $this->di->getLog()->logContent('Found ' . count($freePlanUserIds) . ' free plan users', 'info', $logFile);

            // Step 2: Fetch users who already have account_connection service
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Fetching existing account connection service users...</>');
            $this->di->getLog()->logContent('Fetching existing account connection service users', 'info', $logFile);

            $accountServicePresentUserIds = $paymentCollection->distinct('user_id', [
                'service_type' => 'account_connection',
                'type' => 'user_service'
            ], $arrayParams);
            $accountServicePresentUserIds = array_map('strval', $accountServicePresentUserIds);
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($accountServicePresentUserIds) . ' users with existing account connection service</>');
            $this->di->getLog()->logContent('Found ' . count($accountServicePresentUserIds) . ' users with existing account connection service', 'info', $logFile);

            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $processedCount = 0;
            $errorCount = 0;

            // Step 3: Loop through free plan users
            foreach ($freePlanUserIds as $userId) {
                $userId = (string)$userId;
                $this->di->getLog()->logContent('Processing user_id: ' . $userId, 'info', $logFile);

                // Set DI for user
                $response = $this->setDiForUser($userId);
                if (!isset($response['success']) || !$response['success']) {
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to set DI for user: ' . $userId . ' - ' . ($response['message'] ?? 'Unknown error') . '</>');
                    $this->di->getLog()->logContent('Unable to set DI for user: ' . $userId . ' - ' . ($response['message'] ?? 'Unknown error'), 'error', $logFile);
                    $errorCount++;
                    continue;
                }

                // Get user shops
                $shops = $this->di->getUser()->shops ?? [];

                if (empty($shops)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No shops found for user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('No shops found for user: ' . $userId, 'info', $logFile);
                    $paymentCollection->updateOne(
                        ['user_id' => $userId, 'type' => 'active_plan', 'status' => 'active'],
                        ['$set' => ['picked' => true]]
                    );
                    continue;
                }

                // Get total active target shops
                $totalActiveTargetShops = $this->getTotalActiveTargetShops($shops);
                $this->di->getLog()->logContent('User: ' . $userId . ' - Total active target shops: ' . $totalActiveTargetShops, 'info', $logFile);

                // Calculate credits
                $serviceCredits = $defaultAccountCredits;
                if ($totalActiveTargetShops > $serviceCredits) {
                    $totalUsedCredits = $serviceCredits;
                    $totalAvailableCredits = $totalUsedCredits - $totalActiveTargetShops;
                } else {
                    $totalUsedCredits = $totalActiveTargetShops;
                    $totalAvailableCredits = $serviceCredits - $totalUsedCredits;
                }

                $finalAccountCredits = [
                    'service_credits' => $serviceCredits,
                    'available_credits' => $totalAvailableCredits,
                    'total_used_credits' => $totalUsedCredits
                ];
                $this->di->getLog()->logContent('User: ' . $userId . ' - Final credits: ' . json_encode($finalAccountCredits), 'info', $logFile);

                // Find source shop
                $sourceShop = null;
                foreach ($shops as $shop) {
                    if (isset($shop['marketplace']) && $shop['marketplace'] === $source) {
                        $sourceShop = $shop;
                        break;
                    }
                }

                if (empty($sourceShop)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Source shop not found for user: ' . $userId . ' with marketplace: ' . $source . '</>');
                    $this->di->getLog()->logContent('Source shop not found for user: ' . $userId . ' with marketplace: ' . $source, 'info', $logFile);
                    $paymentCollection->updateOne(
                        ['user_id' => $userId, 'type' => 'active_plan', 'status' => 'active'],
                        ['$set' => ['picked' => true]]
                    );
                    continue;
                }

                $sourceId = (string)$sourceShop['_id'];

                // Unique filter based on user_id and source_id
                $serviceQuery = [
                    'user_id' => $userId,
                    'source_id' => $sourceId,
                    'service_type' => 'account_connection',
                    'type' => 'user_service'
                ];

                // Check if user exists in accountServicePresentUserIds (means account_connection doc already exists)
                if (in_array($userId, $accountServicePresentUserIds)) {
                    // Update existing document
                    $paymentCollection->updateOne(
                        $serviceQuery,
                        ['$set' => ['prepaid' => $finalAccountCredits , 'postpaid' => (object)[], 'active' => true, 'updated_at' => date('Y-m-d H:i:s')]]
                    );

                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Updated account connection credits for user: ' . $userId . ' with source_id: ' . $sourceId . '</>');
                    $this->di->getLog()->logContent('Updated account connection credits for user: ' . $userId . ' with source_id: ' . $sourceId, 'info', $logFile);
                } else {
                    // Create new document using updateOne with $setOnInsert
                    $activatedOn = date('Y-m-d');
                    $expiredAt = date('Y-m-d', strtotime('+1 month'));
                    $subscriptionBillingDate = date('c', strtotime('+1 month'));
                    $documentId = (string)$mongo->getCounter('payment_id');

                    $paymentCollection->updateOne(
                        $serviceQuery,
                        [
                            '$set' => [
                                'prepaid' => $finalAccountCredits,
                                'active' => true,
                                'updated_at' => date('Y-m-d H:i:s')
                            ],
                            '$setOnInsert' => [
                                '_id' => $documentId,
                                'user_id' => $userId,
                                'type' => 'user_service',
                                'source_marketplace' => $source,
                                'target_marketplace' => $targetMarketplace,
                                'marketplace' => $targetMarketplace,
                                'service_type' => 'account_connection',
                                'created_at' => date('Y-m-d H:i:s'),
                                'activated_on' => $activatedOn,
                                'expired_at' => $expiredAt,
                                'subscription_billing_date' => $subscriptionBillingDate,
                                'source_id' => $sourceId,
                                'app_tag' => $appTag,
                                'postpaid' => (object)[]
                            ]
                        ],
                        ['upsert' => true]
                    );

                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Created account connection service document for user: ' . $userId . ' with source_id: ' . $sourceId . '</>');
                    $this->di->getLog()->logContent('Created account connection service document for user: ' . $userId . ' with source_id: ' . $sourceId, 'info', $logFile);
                }

                 // Check if totalActiveTargetShops > defaultAccountCredits
                 if ($totalActiveTargetShops > $defaultAccountCredits) {
                    $autoDisconnectDate = date('c', strtotime('+2 months'));
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$set' => [
                                'auto_disconnect_account_after' => $autoDisconnectDate,
                                'shops.$[shop].auto_disconnect_account_after' => $autoDisconnectDate
                            ]
                        ],
                        [
                            'arrayFilters' => [
                                [
                                    'shop.marketplace' => $targetMarketplace,
                                    'shop.apps.app_status' => 'active'
                                ]
                            ]
                        ]
                    );
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Set auto_disconnect_account_after for user: ' . $userId . ' - ' . $autoDisconnectDate . '</>');
                    $this->di->getLog()->logContent('Set auto_disconnect_account_after for user: ' . $userId . ' - ' . $autoDisconnectDate, 'info', $logFile);
                }

                // Mark user as picked in active_plan document
                $updateResult = $paymentCollection->updateOne(
                    [
                        'user_id' => $userId,
                        'status' => 'active',
                        'type' => 'active_plan'
                    ],
                    ['$set' => ['picked' => true]]
                );

                if ($updateResult->getModifiedCount() > 0 || $updateResult->getMatchedCount() > 0) {
                    $this->di->getLog()->logContent('Marked user: ' . $userId . ' as picked in active_plan document', 'info', $logFile);
                } else {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Warning: Could not mark user: ' . $userId . ' as picked in active_plan document</>');
                    $this->di->getLog()->logContent('Warning: Could not mark user: ' . $userId . ' as picked in active_plan document. Document may not exist.', 'warning', $logFile);
                }

                $processedCount++;
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Processed: ' . $processedCount . ', Errors: ' . $errorCount . '</>');
            }
            $this->di->getLog()->logContent('Process completed. Processed: ' . $processedCount . ', Errors: ' . $errorCount, 'info', $logFile);

        } catch (Exception $e) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Exception: ' . $e->getMessage() . '</>');
            $this->di->getLog()->logContent('Exception updateAccountConnectionCredits(): ' . $e->getMessage(), 'error', $logFile ?? 'exception.log');
        }
    }

    public function syncTemplateCreditsForFreeUsers()
    {
        try {
            $logFile = "amazon/script/" . date('d-m-Y') . '.log';
            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Template Credits Update...</>');
            $this->di->getLog()->logContent('Starting syncTemplateCreditsForFreeUsers script', 'info', $logFile);

            $source = $this->input->getOption("source") ?? false;
            if (!$source) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Source marketplace (-s) is mandatory</>');
                $this->di->getLog()->logContent('Source marketplace not provided', 'error', $logFile);
                return 0;
            }

            $appTag = $this->input->getOption("app_tag") ?? false;
            if (!$appTag) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> App tag (-t) is mandatory</>');
                $this->di->getLog()->logContent('App tag not provided', 'error', $logFile);
                return 0;
            }

            $defaultTemplateCredits = 1;
            $targetMarketplace = 'amazon';

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

            // Step 1: Fetch all free plan users (excluding already processed ones)
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Fetching free plan users...</>');
            $this->di->getLog()->logContent('Fetching free plan users', 'info', $logFile);

            $paymentCollection = $mongo->getCollectionForTable('payment_details');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];

            $freePlanUserIds = $paymentCollection->distinct('user_id', [
                'status' => 'active',
                'type' => 'active_plan',
                'plan_details.custom_price' => 0,
                'picked_template' => ['$exists' => false]
            ], $arrayParams);
            if (empty($freePlanUserIds)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No free plan users found</>');
                $this->di->getLog()->logContent('No free plan users found', 'info', $logFile);
                return 0;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($freePlanUserIds) . ' free plan users</>');
            $this->di->getLog()->logContent('Found ' . count($freePlanUserIds) . ' free plan users', 'info', $logFile);

            // Step 2: Fetch users who already have template_creation service
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Fetching existing template creation service users...</>');
            $this->di->getLog()->logContent('Fetching existing template creation service users', 'info', $logFile);

            $templateServicePresentUserIds = $paymentCollection->distinct('user_id', [
                'service_type' => 'template_creation',
                'type' => 'user_service'
            ], $arrayParams);
            $templateServicePresentUserIds = array_map('strval', $templateServicePresentUserIds);
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($templateServicePresentUserIds) . ' users with existing template creation service</>');
            $this->di->getLog()->logContent('Found ' . count($templateServicePresentUserIds) . ' users with existing template creation service', 'info', $logFile);

            $profileCollection = $mongo->getCollectionForTable('profile');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $processedCount = 0;
            $errorCount = 0;

            // Step 3: Loop through free plan users
            foreach ($freePlanUserIds as $userId) {
                $userId = (string)$userId;
                $this->di->getLog()->logContent('Processing user_id: ' . $userId, 'info', $logFile);

                // Set DI for user
                $response = $this->setDiForUser($userId);
                if (!isset($response['success']) || !$response['success']) {
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to set DI for user: ' . $userId . ' - ' . ($response['message'] ?? 'Unknown error') . '</>');
                    $this->di->getLog()->logContent('Unable to set DI for user: ' . $userId . ' - ' . ($response['message'] ?? 'Unknown error'), 'error', $logFile);
                    $errorCount++;
                    continue;
                }

                // Get user shops
                $shops = $this->di->getUser()->shops ?? [];

                if (empty($shops)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No shops found for user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('No shops found for user: ' . $userId, 'info', $logFile);
                    $paymentCollection->updateOne(
                        ['user_id' => $userId, 'type' => 'active_plan', 'status' => 'active'],
                        ['$set' => ['picked_template' => true]]
                    );
                    continue;
                }

                // Get template count from profile collection
                $profileQuery = [
                    'user_id' => $userId,
                    'type' => 'profile'
                ];
                $totalActiveTemplates = $profileCollection->countDocuments($profileQuery);
                $this->di->getLog()->logContent('User: ' . $userId . ' - Total active templates: ' . $totalActiveTemplates, 'info', $logFile);

                // Calculate credits
                $serviceCredits = $defaultTemplateCredits;
                if ($totalActiveTemplates > $serviceCredits) {
                    $totalUsedCredits = $serviceCredits;
                    $totalAvailableCredits = $totalUsedCredits - $totalActiveTemplates;
                } else {
                    $totalUsedCredits = $totalActiveTemplates;
                    $totalAvailableCredits = $serviceCredits - $totalUsedCredits;
                }

                $finalTemplateCredits = [
                    'service_credits' => $serviceCredits,
                    'available_credits' => $totalAvailableCredits,
                    'total_used_credits' => $totalUsedCredits
                ];
                $this->di->getLog()->logContent('User: ' . $userId . ' - Final template credits: ' . json_encode($finalTemplateCredits), 'info', $logFile);

                // Find source shop
                $sourceShop = null;
                foreach ($shops as $shop) {
                    if (isset($shop['marketplace']) && $shop['marketplace'] === $source) {
                        $sourceShop = $shop;
                        break;
                    }
                }

                if (empty($sourceShop)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Source shop not found for user: ' . $userId . ' with marketplace: ' . $source . '</>');
                    $this->di->getLog()->logContent('Source shop not found for user: ' . $userId . ' with marketplace: ' . $source, 'info', $logFile);
                    $paymentCollection->updateOne(
                        ['user_id' => $userId, 'type' => 'active_plan', 'status' => 'active'],
                        ['$set' => ['picked_template' => true]]
                    );
                    continue;
                }

                $sourceId = (string)$sourceShop['_id'];

                // Unique filter based on user_id and source_id
                $serviceQuery = [
                    'user_id' => $userId,
                    'source_id' => $sourceId,
                    'service_type' => 'template_creation',
                    'type' => 'user_service'
                ];

                // Check if user exists in templateServicePresentUserIds (means template_creation doc already exists)
                if (in_array($userId, $templateServicePresentUserIds)) {
                    // Update existing document
                    $paymentCollection->updateOne(
                        $serviceQuery,
                        ['$set' => ['prepaid' => $finalTemplateCredits, 'postpaid' => (object)[], 'active' => true, 'updated_at' => date('Y-m-d H:i:s')]]
                    );

                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Updated template creation credits for user: ' . $userId . ' with source_id: ' . $sourceId . '</>');
                    $this->di->getLog()->logContent('Updated template creation credits for user: ' . $userId . ' with source_id: ' . $sourceId, 'info', $logFile);
                } else {
                    // Create new document using updateOne with $setOnInsert
                    $activatedOn = date('Y-m-d');
                    $expiredAt = date('Y-m-d', strtotime('+1 month'));
                    $subscriptionBillingDate = date('c', strtotime('+1 month'));
                    $documentId = (string)$mongo->getCounter('payment_id');

                    $paymentCollection->updateOne(
                        $serviceQuery,
                        [
                            '$set' => [
                                'prepaid' => $finalTemplateCredits,
                                'active' => true,
                                'updated_at' => date('Y-m-d H:i:s')
                            ],
                            '$setOnInsert' => [
                                '_id' => $documentId,
                                'user_id' => $userId,
                                'type' => 'user_service',
                                'source_marketplace' => $source,
                                'target_marketplace' => $targetMarketplace,
                                'marketplace' => $targetMarketplace,
                                'service_type' => 'template_creation',
                                'created_at' => date('Y-m-d H:i:s'),
                                'activated_on' => $activatedOn,
                                'expired_at' => $expiredAt,
                                'subscription_billing_date' => $subscriptionBillingDate,
                                'source_id' => $sourceId,
                                'app_tag' => $appTag,
                                'postpaid' => (object)[]
                            ]
                        ],
                        ['upsert' => true]
                    );

                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Created template creation service document for user: ' . $userId . ' with source_id: ' . $sourceId . '</>');
                    $this->di->getLog()->logContent('Created template creation service document for user: ' . $userId . ' with source_id: ' . $sourceId, 'info', $logFile);
                }

                // Check if template count is greater than default credit
                if ($totalActiveTemplates > $defaultTemplateCredits) {
                    $autoRemoveDate = date('c', strtotime('+2 months'));
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$set' => [
                                'auto_remove_extra_templates_after' => $autoRemoveDate,
                                'shops.$[shop].auto_remove_extra_templates_after' => $autoRemoveDate
                            ]
                        ],
                        [
                            'arrayFilters' => [
                                [
                                    'shop.marketplace' => $targetMarketplace,
                                    'shop.apps.app_status' => 'active'
                                ]
                            ]
                        ]
                    );
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Set auto_remove_exta_templates for user: ' . $userId . ' - ' . $autoRemoveDate . '</>');
                    $this->di->getLog()->logContent('Set auto_remove_exta_templates for user: ' . $userId . ' - Template count: ' . $totalActiveTemplates . ' - Date: ' . $autoRemoveDate, 'info', $logFile);
                } else {
                    $this->di->getLog()->logContent('User: ' . $userId . ' - Template count (' . $totalActiveTemplates . ') is within limit, skipping', 'info', $logFile);
                }

                // Mark user as picked in active_plan document
                $updateResult = $paymentCollection->updateOne(
                    [
                        'user_id' => $userId,
                        'status' => 'active',
                        'type' => 'active_plan'
                    ],
                    ['$set' => ['picked_template' => true]]
                );

                if ($updateResult->getModifiedCount() > 0 || $updateResult->getMatchedCount() > 0) {
                    $this->di->getLog()->logContent('Marked user: ' . $userId . ' as picked_template in active_plan document', 'info', $logFile);
                } else {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Warning: Could not mark user: ' . $userId . ' as picked_template in active_plan document</>');
                    $this->di->getLog()->logContent('Warning: Could not mark user: ' . $userId . ' as picked_template in active_plan document. Document may not exist.', 'warning', $logFile);
                }

                $processedCount++;
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Processed: ' . $processedCount . ', Errors: ' . $errorCount . '</>');
            }
            $this->di->getLog()->logContent('Process completed. Processed: ' . $processedCount . ', Errors: ' . $errorCount, 'info', $logFile);

        } catch (Exception $e) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Exception: ' . $e->getMessage() . '</>');
            $this->di->getLog()->logContent('Exception syncTemplateCreditsForFreeUsers(): ' . $e->getMessage(), 'error', $logFile ?? 'exception.log');
        }
    }

    public function syncProductImportCreditsForFreeUsers()
    {
        try {
            $logFile = "amazon/script/syncProductImportCreditsForFreeUsers/" . date('d-m-Y') . '.log';
            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Product Import Credits Update...</>');
            $this->di->getLog()->logContent('Starting syncProductImportCreditsForFreeUsers script', 'info', $logFile);

            $source = $this->input->getOption("source") ?? false;
            if (!$source) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Source marketplace (-s) is mandatory</>');
                $this->di->getLog()->logContent('Source marketplace not provided', 'error', $logFile);
                return 0;
            }

            $appTag = $this->input->getOption("app_tag") ?? false;
            if (!$appTag) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> App tag (-t) is mandatory</>');
                $this->di->getLog()->logContent('App tag not provided', 'error', $logFile);
                return 0;
            }

            $defaultProductImportCredits = 100;
            $targetMarketplace = 'amazon';

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

            // Step 1: Fetch all free plan users (excluding already processed ones)
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Fetching free plan users...</>');
            $this->di->getLog()->logContent('Fetching free plan users', 'info', $logFile);

            $paymentCollection = $mongo->getCollectionForTable('payment_details');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];

            $freePlanUserIds = $paymentCollection->distinct('user_id', [
                'status' => 'active',
                'type' => 'active_plan',
                'plan_details.custom_price' => 0,
                'picked_product_import' => ['$exists' => false]
            ], $arrayParams);
            if (empty($freePlanUserIds)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No free plan users found</>');
                $this->di->getLog()->logContent('No free plan users found', 'info', $logFile);
                return 0;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($freePlanUserIds) . ' free plan users</>');
            $this->di->getLog()->logContent('Found ' . count($freePlanUserIds) . ' free plan users', 'info', $logFile);

            // Step 2: Fetch users who already have product_import service
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Fetching existing product import service users...</>');
            $this->di->getLog()->logContent('Fetching existing product import service users', 'info', $logFile);

            $productImportServicePresentUserIds = $paymentCollection->distinct('user_id', [
                'service_type' => 'product_import',
                'type' => 'user_service'
            ], $arrayParams);
            $productImportServicePresentUserIds = array_map('strval', $productImportServicePresentUserIds);
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($productImportServicePresentUserIds) . ' users with existing product import service</>');
            $this->di->getLog()->logContent('Found ' . count($productImportServicePresentUserIds) . ' users with existing product import service', 'info', $logFile);

            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $processedCount = 0;
            $errorCount = 0;

            // Step 3: Loop through free plan users
            foreach ($freePlanUserIds as $userId) {
                $userId = (string)$userId;
                $this->di->getLog()->logContent('Processing user_id: ' . $userId, 'info', $logFile);

                // Set DI for user
                $response = $this->setDiForUser($userId);
                if (!isset($response['success']) || !$response['success']) {
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to set DI for user: ' . $userId . ' - ' . ($response['message'] ?? 'Unknown error') . '</>');
                    $this->di->getLog()->logContent('Unable to set DI for user: ' . $userId . ' - ' . ($response['message'] ?? 'Unknown error'), 'error', $logFile);
                    $errorCount++;
                    continue;
                }

                // Get user shops
                $shops = $this->di->getUser()->shops ?? [];

                if (empty($shops)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No shops found for user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('No shops found for user: ' . $userId, 'info', $logFile);
                    $paymentCollection->updateOne(
                        ['user_id' => $userId, 'type' => 'active_plan', 'status' => 'active'],
                        ['$set' => ['picked_product_import' => true]]
                    );
                    continue;
                }

                // Find source shop
                $sourceShop = null;
                foreach ($shops as $shop) {
                    if (isset($shop['marketplace']) && $shop['marketplace'] === $source) {
                        $sourceShop = $shop;
                        break;
                    }
                }

                if (empty($sourceShop)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Source shop not found for user: ' . $userId . ' with marketplace: ' . $source . '</>');
                    $this->di->getLog()->logContent('Source shop not found for user: ' . $userId . ' with marketplace: ' . $source, 'info', $logFile);
                    $paymentCollection->updateOne(
                        ['user_id' => $userId, 'type' => 'active_plan', 'status' => 'active'],
                        ['$set' => ['picked_product_import' => true]]
                    );
                    continue;
                }

                $sourceId = (string)$sourceShop['_id'];

                // Get product count
                $totalImportedProducts = $this->getSourceProductCount($mongo, $userId, $sourceId, $source);

                $this->di->getLog()->logContent('User: ' . $userId . ' - Total imported products: ' . $totalImportedProducts, 'info', $logFile);

                // Calculate credits
                $serviceCredits = $defaultProductImportCredits;
                if ($totalImportedProducts > $serviceCredits) {
                    $totalUsedCredits = $serviceCredits;
                    $totalAvailableCredits = $totalUsedCredits - $totalImportedProducts;
                } else {
                    $totalUsedCredits = $totalImportedProducts;
                    $totalAvailableCredits = $serviceCredits - $totalUsedCredits;
                }

                $finalProductImportCredits = [
                    'service_credits' => $serviceCredits,
                    'available_credits' => $totalAvailableCredits,
                    'total_used_credits' => $totalUsedCredits
                ];
                $this->di->getLog()->logContent('User: ' . $userId . ' - Final product import credits: ' . json_encode($finalProductImportCredits), 'info', $logFile);

                // Unique filter based on user_id and source_id
                $serviceQuery = [
                    'user_id' => $userId,
                    'source_id' => $sourceId,
                    'service_type' => 'product_import',
                    'type' => 'user_service'
                ];

                // Check if user exists in productImportServicePresentUserIds (means product_import doc already exists)
                if (in_array($userId, $productImportServicePresentUserIds)) {
                    // Update existing document
                    $setData = [
                        'prepaid' => $finalProductImportCredits,
                        'postpaid' => (object)[],
                        'active' => true,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    if ($totalImportedProducts > $defaultProductImportCredits) {
                        $setData['sync_activated'] = false;
                    }
                    $paymentCollection->updateOne(
                        $serviceQuery,
                        ['$set' => $setData]
                    );

                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Updated product import credits for user: ' . $userId . ' with source_id: ' . $sourceId . '</>');
                    $this->di->getLog()->logContent('Updated product import credits for user: ' . $userId . ' with source_id: ' . $sourceId, 'info', $logFile);
                } else {
                    // Create new document using updateOne with $setOnInsert
                    $activatedOn = date('Y-m-d');
                    $expiredAt = date('Y-m-d', strtotime('+1 month'));
                    $subscriptionBillingDate = date('c', strtotime('+1 month'));
                    $documentId = (string)$mongo->getCounter('payment_id');

                    $setData = [
                        'prepaid' => $finalProductImportCredits,
                        'active' => true,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    if ($totalImportedProducts > $defaultProductImportCredits) {
                        $setData['sync_activated'] = false;
                    }

                    $paymentCollection->updateOne(
                        $serviceQuery,
                        [
                            '$set' => $setData,
                            '$setOnInsert' => [
                                '_id' => $documentId,
                                'user_id' => $userId,
                                'type' => 'user_service',
                                'source_marketplace' => $source,
                                'target_marketplace' => $targetMarketplace,
                                'marketplace' => $targetMarketplace,
                                'service_type' => 'product_import',
                                'created_at' => date('Y-m-d H:i:s'),
                                'activated_on' => $activatedOn,
                                'expired_at' => $expiredAt,
                                'subscription_billing_date' => $subscriptionBillingDate,
                                'source_id' => $sourceId,
                                'app_tag' => $appTag,
                                'postpaid' => (object)[]
                            ]
                        ],
                        ['upsert' => true]
                    );

                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Created product import service document for user: ' . $userId . ' with source_id: ' . $sourceId . '</>');
                    $this->di->getLog()->logContent('Created product import service document for user: ' . $userId . ' with source_id: ' . $sourceId, 'info', $logFile);
                }

                // Check if product count is greater than default credit
                if ($totalImportedProducts > $defaultProductImportCredits) {
                    $autoRemoveDate = date('c', strtotime('+2 months'));
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$set' => [
                                'auto_remove_extra_products_after' => $autoRemoveDate,
                                'shops.$[shop].auto_remove_extra_products_after' => $autoRemoveDate
                            ]
                        ],
                        [
                            'arrayFilters' => [
                                [
                                    'shop.marketplace' => $targetMarketplace,
                                    'shop.apps.app_status' => 'active'
                                ]
                            ]
                        ]
                    );
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Set auto_remove_extra_products_after for user: ' . $userId . ' - ' . $autoRemoveDate . '</>');
                    $this->di->getLog()->logContent('Set auto_remove_extra_products_after for user: ' . $userId . ' - Product count: ' . $totalImportedProducts . ' - Date: ' . $autoRemoveDate, 'info', $logFile);
                } else {
                    $this->di->getLog()->logContent('User: ' . $userId . ' - Product count (' . $totalImportedProducts . ') is within limit, skipping', 'info', $logFile);
                }

                // Mark user as picked in active_plan document
                $updateResult = $paymentCollection->updateOne(
                    [
                        'user_id' => $userId,
                        'status' => 'active',
                        'type' => 'active_plan'
                    ],
                    ['$set' => ['picked_product_import' => true]]
                );

                if ($updateResult->getModifiedCount() > 0 || $updateResult->getMatchedCount() > 0) {
                    $this->di->getLog()->logContent('Marked user: ' . $userId . ' as picked_product_import in active_plan document', 'info', $logFile);
                } else {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Warning: Could not mark user: ' . $userId . ' as picked_product_import in active_plan document</>');
                    $this->di->getLog()->logContent('Warning: Could not mark user: ' . $userId . ' as picked_product_import in active_plan document. Document may not exist.', 'warning', $logFile);
                }

                $processedCount++;
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Processed: ' . $processedCount . ', Errors: ' . $errorCount . '</>');
            }
            $this->di->getLog()->logContent('Process completed. Processed: ' . $processedCount . ', Errors: ' . $errorCount, 'info', $logFile);

        } catch (Exception $e) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Exception: ' . $e->getMessage() . '</>');
            $this->di->getLog()->logContent('Exception syncProductImportCreditsForFreeUsers(): ' . $e->getMessage(), 'error', $logFile ?? 'exception.log');
        }
    }

    private function getTotalActiveTargetShops($shops)
    {
        $totalActiveTargetShops = 0;
        if (!empty($shops)) {
            foreach ($shops as $shop) {
                $marketplace = $shop['marketplace'] ?? '';
                $apps = $shop['apps'] ?? [];
                $appStatus = isset($apps[0]['app_status']) ? $apps[0]['app_status'] : '';
                if ($marketplace === 'amazon' && $appStatus === 'active') {
                    $totalActiveTargetShops++;
                }
            }
        }
        return $totalActiveTargetShops;
    }

    private function getSourceProductCount($mongo, $userId, $shopId, $source)
    {
        $productContainer = $mongo->getCollectionForTable('product_container');
        $parentProductQuery = [
            'user_id' => $userId,
            'shop_id' => $shopId,
            "visibility" => "Catalog and Search",
            'source_marketplace' => $source
        ];
        $productCount = $productContainer->countDocuments($parentProductQuery);
        return $productCount ?? 0;
    }

    public function removeExtraProductsAfterAutoRemoveDate()
    {
        try {
            $appTag = $this->input->getOption("app_tag") ?? false;
            $source = $this->input->getOption("source") ?? false;
            if (!$appTag || !$source) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><fg=red>To execute this method app_tag and source field is required(-t and -s)</>');
                return 0;
            }
            $logFile = "amazon/script/auto_remove_products/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $userDetailsCollection = $mongo->getCollectionForTable('user_details');
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $queueName = 'delete_extra_products';

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting auto remove extra products process...</>');
            $this->di->getLog()->logContent('Starting auto remove extra products process', 'info', $logFile);

            $userData = $userDetailsCollection->find(
                [
                    'auto_remove_extra_products_after' => ['$lte' => date('c')],
                    'auto_removed_extra_products_at' => ['$exists' => false]
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => ['user_id' => 1, 'shops' => 1, 'plan_tier' => 1]
                ]
            )->toArray();
            if (empty($userData)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>No users found with auto_remove_extra_products_after date reached</>');
                $this->di->getLog()->logContent('No users found with auto_remove_extra_products_after date reached', 'info', $logFile);
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Found ' . count($userData) . ' users to process</>');
            $this->di->getLog()->logContent('Found ' . count($userData) . ' users to process', 'info', $logFile);

            foreach ($userData as $user) {
                $userId = $user['user_id'];
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Processing user: ' . $userId, 'info', $logFile);

                // Find source shop
                $sourceShop = null;
                if (!empty($user['shops'])) {
                    foreach ($user['shops'] as $shop) {
                        if (isset($shop['marketplace']) && $shop['marketplace'] == $source) {
                            $sourceShop = $shop;
                            break;
                        }
                    }
                }

                if (!isset($user['plan_tier']) || $user['plan_tier'] == 'paid') {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping user: ' . $userId . ' - plan_tier is paid or not set</>');
                    $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - plan_tier is paid or not set', 'info', $logFile);
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$unset' => [
                                'auto_remove_extra_products_after' => 1,
                                'shops.$[shop].auto_remove_extra_products_after' => 1
                            ]
                        ],
                        [
                            'arrayFilters' => [
                                ['shop.marketplace' => 'amazon', 'shop.auto_remove_extra_products_after' => ['$exists' => true]]
                            ]
                        ]
                    );
                    continue;
                }

                if (empty($sourceShop)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping user: ' . $userId . ' - source shop not found</>');
                    $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - source shop not found for marketplace: ' . $source, 'info', $logFile);
                    // Mark user as processed even if source shop not found
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$unset' => [
                                'auto_remove_extra_products_after' => 1,
                                'shops.$[shop].auto_remove_extra_products_after' => 1
                            ]
                        ],
                        [
                            'arrayFilters' => [
                                ['shop.marketplace' => 'amazon', 'shop.auto_remove_extra_products_after' => ['$exists' => true]]
                            ]
                        ]
                    );
                    continue;
                }

                // Find target shop (amazon)
                $targetShop = null;
                foreach ($user['shops'] as $shop) {
                    if (isset($shop['marketplace']) && $shop['marketplace'] == 'amazon' && isset($shop['apps'][0]['app_status']) && $shop['apps'][0]['app_status'] == 'active') {
                        $targetShop = $shop;
                        break;
                    }
                }

                if (empty($targetShop)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping user: ' . $userId . ' - target shop not found</>');
                    $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - target shop not found', 'info', $logFile);
                    // Mark user as processed
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        [
                            '$unset' => [
                                'auto_remove_extra_products_after' => 1,
                                'shops.$[shop].auto_remove_extra_products_after' => 1
                            ]
                        ],
                        [
                            'arrayFilters' => [
                                ['shop.marketplace' => 'amazon', 'shop.auto_remove_extra_products_after' => ['$exists' => true]]
                            ]
                        ]
                    );
                    continue;
                }

                // Push message to queue for this user
                try {
                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => '\App\Amazon\Components\Product\Delete',
                        'method' => 'removeExtraProductsFromDB',
                        'queue_name' => $queueName,
                        'user_id' => $userId,
                        'source' => $source,
                        'target' => 'amazon',
                        'app_code' => $targetShop['apps'][0]['code'] ?? '',
                        "appTag" => $appTag,
                        "appCode" => $this->di->getConfig()->get('app_tags')->get($appTag)->get('app_code')->toArray()
                    ];
                    $sqsHelper->pushMessage($handlerData);
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Pushed queue message for user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('Successfully pushed message to queue for user: ' . $userId, 'info', $logFile);

                    // Mark user as processed to prevent reprocessing
                    $userDetailsCollection->updateOne(
                        ['user_id' => $userId],
                        ['$set' => ['auto_removed_extra_products_at' => date('c')]]
                    );
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Marked user: ' . $userId . ' as queued for processing</>');
                    $this->di->getLog()->logContent('Marked user: ' . $userId . ' as queued for processing', 'info', $logFile);
                } catch (Exception $e) {
                    $this->di->getLog()->logContent('Exception while pushing message for user ' . $userId . ': ' . $e->getMessage(), 'error', $logFile);
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception while pushing message for user: ' . $userId . '</>');
                }
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Process completed</>');
            $this->di->getLog()->logContent('Process completed', 'info', $logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in removeExtraProductsAfterAutoRemoveDate(): ' . $e->getMessage(), 'error', $logFile ?? 'exception.log');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception: ' . $e->getMessage() . '</>');
        }
    }

    public function autoDisconnectInactiveAmazonAccounts()
    {
        try {
            $appTag = $this->input->getOption("app_tag") ?? false;
            $source = $this->input->getOption("source") ?? false;
            if (!$appTag || !$source) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><fg=red>To execute this method app_tag and source field is required(-t and -s)</>');
                return 0;
            }

            $logFile = "amazon/auto_disconnect/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $configCollection = $mongo->getCollectionForTable('config');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $queueName = 'disconnect_amazon_account';

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Starting auto disconnect Amazon account process...</>');
            $this->di->getLog()->logContent('Starting auto disconnect Amazon account process', 'info', $logFile);

            // Calculate date 6 months ago
            $sixMonthsAgo = date('c', strtotime('-6 months'));

            // Prepare $or query
            $orConditions = [
                [
                    'group_code' => 'feedStatus',
                    'value' => ['$in' => ['expired', 'inactive', 'access_denied']],
                    'error_code' => ['$in' => ['invalid_grant', 'InvalidInput', 'Unauthorized']],
                    'updated_at' => ['$exists' => false],
                    'created_at' => ['$lt' => $sixMonthsAgo],
                    'notified_and_disconnected' => ['$exists' => false],
                    'paid_client_skipping' => ['$exists' => false]
                ],
                [
                    'group_code' => 'feedStatus',
                    'value' => ['$in' => ['expired', 'inactive', 'access_denied']],
                    'error_code' => ['$in' => ['invalid_grant', 'InvalidInput', 'Unauthorized']],
                    'updated_at' => ['$exists' => true],
                    'updated_at' => ['$lt' => $sixMonthsAgo],
                    'notified_and_disconnected' => ['$exists' => false],
                    'paid_client_skipping' => ['$exists' => false]
                ]
            ];

            // Fetch data
            $feedStatusData = $configCollection->find(
                ['$or' => $orConditions],
                $arrayParams
            )->toArray();
            if (empty($feedStatusData)) {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>No data found matching criteria</>');
                $this->di->getLog()->logContent('No data found matching criteria', 'info', $logFile);
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Found ' . count($feedStatusData) . ' feed status records</>');
            $this->di->getLog()->logContent('Found ' . count($feedStatusData) . ' feed status records', 'info', $logFile);

            // Group data by user_id => target_shop_id => data
            $groupedData = [];
            foreach ($feedStatusData as $record) {
                $userId = $record['user_id'];
                $targetShopId = $record['target_shop_id'];

                if (!isset($groupedData[$userId])) {
                    $groupedData[$userId] = [];
                }
                if (!isset($groupedData[$userId][$targetShopId])) {
                    $groupedData[$userId][$targetShopId] = [];
                }
                $groupedData[$userId][$targetShopId][] = $record;
            }
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing ' . count($groupedData) . ' users</>');
            $this->di->getLog()->logContent('Processing ' . count($groupedData) . ' users', 'info', $logFile);

            // Process each user
            foreach ($groupedData as $userId => $targetShops) {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Processing user: ' . $userId, 'info', $logFile);

                // Set DI for user
                $diResponse = $this->setDiForUser($userId);
                if (!isset($diResponse['success']) || !$diResponse['success']) {
                    $this->di->getLog()->logContent('Unable to setDi for user: ' . $userId . ' - ' . ($diResponse['message'] ?? 'Unknown error'), 'info', $logFile);
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Unable to setDi for user: ' . $userId . '</>');
                    continue;
                }

                // Check plan_tier - skip if it's 'paid'
                $user = $this->di->getUser();
                $planTier = isset($user->plan_tier) ? $user->plan_tier : null;
                
                if (!isset($planTier)) {
                    $this->di->getLog()->logContent('plan_tier is not set for user: ' . $userId . ' - continuing processing', 'info', $logFile);
                    continue;
                } elseif ($planTier === 'paid') {
                    $this->di->getLog()->logContent('Skipping user: ' . $userId . ' - plan_tier is paid', 'info', $logFile);
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow>Skipping user: ' . $userId . ' - plan_tier is paid</>');
                    
                    // Mark all records for this user with paid_client_skipping: true using updateMany
                    try {
                        $configCollection->updateMany(
                            [
                                'user_id' => $userId,
                                'group_code' => 'feedStatus',
                                'key' => 'accountStatus',
                                'value' => ['$in' => ['expired', 'inactive', 'access_denied']]
                            ],
                            [
                                '$set' => ['paid_client_skipping' => true]
                            ]
                        );
                        $this->di->getLog()->logContent('Marked all feedStatus records with paid_client_skipping for user: ' . $userId, 'info', $logFile);
                    } catch (Exception $e) {
                        $this->di->getLog()->logContent('Exception while marking paid_client_skipping for user ' . $userId . ': ' . $e->getMessage(), 'error', $logFile);
                    }
                    continue;
                }
                
                $userShops = $user->shops ?? [];
                $username = $user->username ?? '';
                $email = $user->source_email ?? $user->email ?? '';

                if (empty($userShops)) {
                    $this->di->getLog()->logContent('No shops found for user: ' . $userId, 'info', $logFile);
                    continue;
                }

                // Find source shop
                $sourceShop = null;
                foreach ($userShops as $shop) {
                    if (isset($shop['marketplace']) && $shop['marketplace'] == $source) {
                        $sourceShop = $shop;
                        break;
                    }
                }
                // Process each target_shop_id - send one email per error per shop
                foreach ($targetShops as $targetShopId => $records) {
                    // Find matching shop in user shops with apps.app_status: active
                    $matchingShop = null;
                    foreach ($userShops as $shop) {
                        if (
                            isset($shop['_id']) &&
                            (string)$shop['_id'] === (string)$targetShopId &&
                            isset($shop['marketplace']) &&
                            $shop['marketplace'] === 'amazon' &&
                            isset($shop['apps'][0]['app_status']) &&
                            $shop['apps'][0]['app_status'] === 'active'
                        ) {
                            $matchingShop = $shop;
                            break;
                        }
                    }

                    if (empty($matchingShop)) {
                        $this->di->getLog()->logContent('No active shop found for target_shop_id: ' . $targetShopId . ' for user: ' . $userId, 'info', $logFile);
                        continue;
                    }
                    // Process each record (error) separately - send one email per error
                    foreach ($records as $record) {
                        $errorCode = $record['error_code'] ?? '';
                        $marketplaceId = $matchingShop['warehouses'][0]['marketplace_id'] ?? '';
                        $marketplaceName = \App\Amazon\Components\Common\Helper::MARKETPLACE_NAME[$marketplaceId] ?? '';
                        $sellerId = $matchingShop['warehouses'][0]['seller_id'] ?? 'N/A';
                        $region = $matchingShop['warehouses'][0]['region'] ?? 'N/A';

                        // Determine error type and subject
                        $errorType = '';
                        $subject = '';
                        if ($errorCode === 'invalid_grant') {
                            $errorType = 'Token Expired - Reauthorization Required';
                            $subject = 'Amazon Account Disconnection - Amazon Token Expired';
                        } elseif ($errorCode === 'InvalidInput') {
                            $errorType = 'Vacation Mode - Account Inactive';
                            $subject = 'Amazon Account Disconnection - Vacation Mode';
                        } elseif ($errorCode === 'Unauthorized') {
                            $errorType = 'Access Denied - Permissions Required';
                            $subject = 'Amazon Account Disconnection - Access Denied';
                        }

                        if (empty($errorType)) {
                            $this->di->getLog()->logContent('Unknown error code: ' . $errorCode . ' for record: ' . $record['_id'], 'info', $logFile);
                            continue;
                        }

                        // Prepare mail data for single error
                        $msgData = [
                            'app_name' => $this->di->getConfig()->app_name,
                            'name' => $username,
                            'email' => $email,
                            'subject' => $subject,
                            'error_type' => $errorCode,
                            'marketplace_name' => $marketplaceName,
                            'seller_id' => $sellerId,
                            'region' => $region
                        ];
                        // Send email for this specific error
                        $msgData['path'] = 'amazon' . DS . 'view' . DS . 'email' . DS . 'AutoDisconnectionMail.volt';
                        $mailResponse = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($msgData);
                        if ($mailResponse) {
                            $this->di->getLog()->logContent('Mail sent successfully for user: ' . $userId . ' shop: ' . $targetShopId . ' error: ' . $errorType, 'info', $logFile);
                            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Mail sent successfully for user: ' . $userId . ' shop: ' . $targetShopId . ' error: ' . $errorType . '</>');
                        } else {
                            $this->di->getLog()->logContent('Error while sending mail for user: ' . $userId . ' shop: ' . $targetShopId . ' error: ' . $errorType . ' - Response: ' . json_encode($mailResponse), 'info', $logFile);
                            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to send mail for user: ' . $userId . ' shop: ' . $targetShopId . ' error: ' . $errorType . '</>');
                            continue; // Skip queue and config update if mail fails
                        }

                        // Push message to queue for this shop
                        try {
                            $data = [
                                'target' => [
                                    'marketplace' => 'amazon',
                                    'shopId' => (string)$targetShopId
                                ],
                                'source' => [
                                    'marketplace' => $sourceShop ? $sourceShop['marketplace'] : 'shopify',
                                    'shopId' => $sourceShop ? (string)$sourceShop['_id'] : ''
                                ],
                                'disconnected' => [
                                    'target' => 1
                                ]
                            ];

                            $handlerData = [
                                'type' => 'full_class',
                                'class_name' => '\App\Amazon\Components\ShopDelete',
                                'method' => 'initiateAccountDisconnectionInactivePlan',
                                'queue_name' => $queueName,
                                'user_id' => $userId,
                                'data' => $data,
                                'app_code' => $matchingShop['apps'][0]['code'] ?? '',
                                'appTag' => $appTag,
                                'appCode' => $this->di->getConfig()->get('app_tags')->get($appTag)->get('app_code')->toArray()
                            ];

                            $sqsHelper->pushMessage($handlerData);
                            $this->di->getLog()->logContent('Successfully pushed message to queue for shop: ' . $targetShopId . ' for user: ' . $userId, 'info', $logFile);
                            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Pushed message to queue for shop: ' . $targetShopId . '</>');
                        } catch (Exception $e) {
                            $this->di->getLog()->logContent('Exception while pushing message for shop ' . $targetShopId . ': ' . $e->getMessage(), 'error', $logFile);
                            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception while pushing message for shop: ' . $targetShopId . '</>');
                        }

                        // Update the config record with notified_and_disconnected flag
                        try {
                            $configCollection->updateOne(
                                ['_id' => $record['_id']],
                                ['$set' => ['notified_and_disconnected' => true]]
                            );
                        } catch (Exception $e) {
                            $this->di->getLog()->logContent('Exception while updating config for record ' . $record['_id'] . ': ' . $e->getMessage(), 'error', $logFile);
                        }
                    }
                }
            }
            $this->di->getLog()->logContent('Process completed', 'info', $logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception in autoDisconnectAmazonAccount(): ' . $e->getMessage(), 'error', $logFile ?? 'exception.log');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Exception: ' . $e->getMessage() . '</>');
        }
    }

    public function createAdditionalServicesForFreePlanUsers()
    {
        try {
            $logFile = "amazon/script/createAdditionalServices_" . date('d-m-Y') . '.log';
            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Create Additional Services for Free Plan Users...</>');
            $this->di->getLog()->logContent('Starting createAdditionalServicesForFreePlanUsers script', 'info', $logFile);

            $source = $this->input->getOption("source") ?? false;
            if (!$source) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Source marketplace (-s) is mandatory</>');
                $this->di->getLog()->logContent('Source marketplace not provided', 'error', $logFile);
                return 0;
            }

            $appTag = $this->input->getOption("app_tag") ?? false;
            if (!$appTag) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> App tag (-t) is mandatory</>');
                $this->di->getLog()->logContent('App tag not provided', 'error', $logFile);
                return 0;
            }

            $targetMarketplace = 'amazon';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $paymentCollection = $mongo->getCollectionForTable('payment_details');
            $planCollection = $mongo->getCollectionForTable('plan');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];

            // Fetch supported_features from plan (custom_price: 0, code: free) -> services_groups "Additional Services" -> service supported_features
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Fetching supported_features from plan...</>');
            $planQuery = [
                'custom_price' => 0,
                'code' => 'free'
            ];
            $planData = $planCollection->findOne($planQuery, $arrayParams);
            if (empty($planData)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Plan not found with custom_price: 0 and code: free</>');
                $this->di->getLog()->logContent('Plan not found with custom_price: 0 and code: free', 'error', $logFile);
                return 0;
            }

            $supportedFeatures = [];
            if (isset($planData['services_groups']) && is_array($planData['services_groups'])) {
                foreach ($planData['services_groups'] as $serviceGroup) {
                    if (isset($serviceGroup['title']) && $serviceGroup['title'] === 'Additional Services') {
                        if (isset($serviceGroup['services']) && is_array($serviceGroup['services'])) {
                            foreach ($serviceGroup['services'] as $service) {
                                if (isset($service['type']) && $service['type'] === 'additional_services'
                                    && isset($service['supported_features']) && is_array($service['supported_features'])) {
                                    $supportedFeatures = $service['supported_features'];
                                    break 2;
                                }
                            }
                        }
                        break;
                    }
                }
            }
            if (empty($supportedFeatures)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> supported_features not found in plan (Additional Services / additional_services)</>');
                $this->di->getLog()->logContent('supported_features not found in plan', 'error', $logFile);
                return 0;
            }
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Fetched supported_features from plan</>');

            // Step 1: Distinct user_ids where free plan and picked_additional_services_check does not exist
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Fetching free plan users (picked_additional_services_check not set)...</>');
            $freePlanUserIds = $paymentCollection->distinct('user_id', [
                'status' => 'active',
                'type' => 'active_plan',
                'plan_details.custom_price' => 0,
                'picked_additional_services_check' => ['$exists' => false]
            ], $arrayParams);
            if (empty($freePlanUserIds)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No free plan users found</>');
                $this->di->getLog()->logContent('No free plan users found', 'info', $logFile);
                return 0;
            }
            $freePlanUserIds = array_map('strval', $freePlanUserIds);
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($freePlanUserIds) . ' free plan users</>');

            // Step 2: Distinct user_ids who already have additional_services (user_service)
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Fetching users who already have additional_services...</>');
            $additionalServicesPresentUsers = $paymentCollection->distinct('user_id', [
                'user_id' => ['$in' => $freePlanUserIds],
                'type' => 'user_service',
                'service_type' => 'additional_services'
            ], $arrayParams);
            $additionalServicesPresentUsers = array_map('strval', $additionalServicesPresentUsers);
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($additionalServicesPresentUsers) . ' users with additional_services</>');

            // Step 3: User IDs that need the doc (additional services not present)
            $userIdsWithoutAdditionalServices = array_diff($freePlanUserIds, $additionalServicesPresentUsers);
            $userIdsWithoutAdditionalServices = array_values($userIdsWithoutAdditionalServices);
            if (empty($userIdsWithoutAdditionalServices)) {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> No users missing additional_services doc. Nothing to create.</>');
                $this->di->getLog()->logContent('No users missing additional_services', 'info', $logFile);
                return 0;
            }
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Creating additional_services doc for ' . count($userIdsWithoutAdditionalServices) . ' users</>');

            $processedCount = 0;
            $errorCount = 0;
            $now = date('Y-m-d H:i:s');
            $activatedOn = date('Y-m-d');
            $expiredAt = date('Y-m-d', strtotime('+1 month'));

            foreach ($userIdsWithoutAdditionalServices as $userId) {
                $this->di->getLog()->logContent('Processing user_id: ' . $userId, 'info', $logFile);

                $response = $this->setDiForUser($userId);
                if (!isset($response['success']) || !$response['success']) {
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to set DI for user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('Unable to set DI for user: ' . $userId, 'error', $logFile);
                    $paymentCollection->updateOne(
                        [
                            'user_id' => $userId,
                            'type' => 'active_plan',
                            'status' => 'active'
                        ],
                        ['$set' => ['picked_additional_services_check' => true]]
                    );
                    $errorCount++;
                    continue;
                }

                $shops = $this->di->getUser()->shops ?? [];
                $sourceShop = null;
                foreach ($shops as $shop) {
                    if (isset($shop['marketplace']) && $shop['marketplace'] === $source) {
                        $sourceShop = $shop;
                        break;
                    }
                }
                if (empty($sourceShop) || !isset($sourceShop['_id'])) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No source shop for user: ' . $userId . ' (marketplace: ' . $source . '), skipping</>');
                    $this->di->getLog()->logContent('No source shop for user: ' . $userId, 'info', $logFile);
                    $paymentCollection->updateOne(
                        [
                            'user_id' => $userId,
                            'type' => 'active_plan',
                            'status' => 'active'
                        ],
                        ['$set' => ['picked_additional_services_check' => true]]
                    );
                    continue;
                }
                $sourceId = (string)$sourceShop['_id'];

                // Use updateOne with upsert so duplicate docs are not inserted (unique on user_id + source_id + service_type)
                $serviceQuery = [
                    'user_id' => $userId,
                    'source_id' => $sourceId,
                    'type' => 'user_service',
                    'service_type' => 'additional_services'
                ];
                $documentId = (string)$mongo->getCounter('payment_id');
                $doc = [
                    '_id' => $documentId,
                    'user_id' => $userId,
                    'type' => 'user_service',
                    'source_marketplace' => $source,
                    'target_marketplace' => $targetMarketplace,
                    'marketplace' => $targetMarketplace,
                    'service_type' => 'additional_services',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'activated_on' => $activatedOn,
                    'expired_at' => $expiredAt,
                    'source_id' => $sourceId,
                    'app_tag' => $appTag,
                    'supported_features' => $supportedFeatures
                ];

                $paymentCollection->updateOne(
                    $serviceQuery,
                    ['$setOnInsert' => $doc],
                    ['upsert' => true]
                );
                $processedCount++;

                // Mark active_plan as picked_additional_services_check so user is not picked again in future runs
                $paymentCollection->updateOne(
                    [
                        'user_id' => $userId,
                        'type' => 'active_plan',
                        'status' => 'active'
                    ],
                    ['$set' => ['picked_additional_services_check' => true]]
                );

                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Created/verified additional_services for user: ' . $userId . ' (source_id: ' . $sourceId . ')</>');
                $this->di->getLog()->logContent('Created/verified additional_services for user: ' . $userId . ', source_id: ' . $sourceId, 'info', $logFile);
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Processed: ' . $processedCount . ', Errors: ' . $errorCount . '</>');
            $this->di->getLog()->logContent('Process completed. Processed: ' . $processedCount . ', Errors: ' . $errorCount, 'info', $logFile);

        } catch (Exception $e) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Exception: ' . $e->getMessage() . '</>');
            $this->di->getLog()->logContent('Exception createAdditionalServicesForFreePlanUsers(): ' . $e->getMessage(), 'error', $logFile ?? 'exception.log');
        }
    }
    public function updateUploadedProductsToSubmitted()
    {
        try {
            $taskName = 'update_uploaded_products_to_submitted';
            $activePage = 1;
            $this->logFile = "amazon/script/" . date('d-m-Y') . '/updateUploadedProductsToSubmitted.log';
            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Update Uploaded Products to Submitted...</>');
            $this->di->getLog()->logContent('Starting updateUploadedProductsToSubmitted script', 'info', $this->logFile);

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainer = $mongo->getCollectionForTable('product_container');
            $cronTask = $mongo->getCollectionForTable('cron_tasks');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];

            $userData = $this->fetchNewUserForProcessing($activePage, $taskName);


            $totalUpdated = 0;

            while (!empty($userData)) {
                $this->logFile = "amazon/script/" . date('d-m-Y') . '/updateUploadedProductsToSubmitted-page-' . $activePage . '.log';
                $lastProcessedUserId = '';

                foreach ($userData as $user) {
                    $userId = $user['user_id'];
                    $this->di->getLog()->logContent('Starting process for user_id: ' . $userId, 'info', $this->logFile);

                    if (empty($user['shops'])) {
                        $this->di->getLog()->logContent('No shops found for user: ' . $userId, 'info', $this->logFile);
                        $lastProcessedUserId = $userId;
                        continue;
                    }

                    $response = $this->setDiForUser($userId);
                    if (!isset($response['success']) || !$response['success']) {
                        $this->di->getLog()->logContent('Failed to set DI for user: ' . $userId . ' - ' . ($response['message'] ?? ''), 'error', $this->logFile);
                        $lastProcessedUserId = $userId;
                        continue;
                    }

                    foreach ($user['shops'] as $shop) {
                        if (($shop['marketplace'] ?? '') !== 'amazon') {
                            continue;
                        }

                        $targetShopId = $shop['_id'] ?? null;
                        if (!$targetShopId) {
                            continue;
                        }

                        $sourceShopId = null;
                        $sourceMarketplace = '';
                        if (isset($shop['sources']) && is_array($shop['sources']) && !empty($shop['sources'])) {
                            $sourceShopId = $shop['sources'][0]['shop_id'] ?? null;
                            $sourceMarketplace = $shop['sources'][0]['code'] ?? '';
                        }

                        if (!$sourceShopId) {
                            $this->output->writeln('<options=bold;fg=yellow> ➤ </>Skipping user ' . $userId . ' shop ' . $targetShopId . ' - missing source shop ID');
                            continue;
                        }

                        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing user: ' . $userId . ' shop: ' . $targetShopId . '</>');

                        $products = $productContainer->find(
                            [
                                'user_id' => $userId,
                                'shop_id' => (string) $targetShopId,
                                'target_marketplace' => 'amazon',
                                'status' => 'Uploaded'
                            ],
                            $arrayParams
                        )->toArray();

                        if (empty($products)) {
                            $this->output->writeln('<options=bold;fg=cyan> ➤ </>No Uploaded products for user: ' . $userId . ' shop: ' . $targetShopId);
                            continue;
                        }

                        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($products) . ' Uploaded products for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('Found ' . count($products) . ' Uploaded products for user: ' . $userId . ' shop: ' . $targetShopId, 'info', $this->logFile);

                        $updateDataInsert = [];

                        foreach ($products as $product) {
                            $updateDataInsert[] = [
                                'source_product_id' => (string) $product['source_product_id'],
                                'user_id' => $userId,
                                'source_shop_id' => (string) $sourceShopId,
                                'target_marketplace' => 'amazon',
                                'status' => Helper::PRODUCT_STATUS_UPLOADED,
                                'shop_id' => (string) $targetShopId,
                                'container_id' => (string) $product['container_id'],
                                'last_synced_at' => date('c'),
                            ];
                        }

                        if (!empty($updateDataInsert)) {
                            $additionalData = [
                                'source' => [
                                    'marketplace' => $sourceMarketplace,
                                    'shopId' => (string) $sourceShopId,
                                ],
                                'target' => [
                                    'marketplace' => 'amazon',
                                    'shopId' => (string) $targetShopId,
                                ],
                            ];
                            $this->di->getLog()->logContent('Updated products data for user: ' . $userId . ' shop: ' . $targetShopId . ': ' . json_encode($updateDataInsert), 'info', $this->logFile);

                            $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                            $res = $editHelper->saveProduct($updateDataInsert, $userId, $additionalData);

                            $this->di->getLog()->logContent('saveProduct response for user: ' . $userId . ' shop: ' . $targetShopId . ': ' . json_encode($res), 'info', $this->logFile);
                            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Updated ' . count($updateDataInsert) . ' products to Submitted for user: ' . $userId . '</>');
                            $totalUpdated += count($updateDataInsert);
                        }
                    }

                    $lastProcessedUserId = $userId;
                    $cronTask->updateOne(
                        ['task' => $taskName],
                        [
                            '$set' => [
                                'last_traversed_user_id' => $lastProcessedUserId,
                                'completed_page' => $activePage,
                                'updated_at' => date('c'),
                            ],
                        ],
                        ['upsert' => true]
                    );
                }

                ++$activePage;
                $userData = $this->fetchNewUserForProcessing($activePage, $taskName, $lastProcessedUserId, true);
                $this->di->getLog()->logContent('Process Completed for page: ' . ($activePage - 1), 'info', $this->logFile);
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Total products updated to Submitted: ' . $totalUpdated . '</>');
            $this->di->getLog()->logContent('Total products updated to Submitted: ' . $totalUpdated, 'info', $this->logFile);

        } catch (Exception $e) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error: ' . $e->getMessage() . '</>');
            $this->di->getLog()->logContent('Error in updateUploadedProductsToSubmitted: ' . $e->getMessage(), 'error', $this->logFile ?? 'amazon/script/error.log');
        }
    }

}
