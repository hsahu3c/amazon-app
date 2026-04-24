<?php

use App\Amazon\Service\Order;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[\AllowDynamicProperties]
class OrderAcknowledgementCommand extends Command
{

    protected static $defaultName = "order-acknowledgement-feed";
    protected $di;
    protected $mongo;

    protected static $defaultDescription = "To submit and process Order Acknowledgement Feed";

    public const AMAZON_FEED_DATA = 'amazon_feed_data';

    public const FEED_CONTAINER = 'feed_container';

    public const FEED_TYPE = 'POST_ORDER_ACKNOWLEDGEMENT_DATA';

    public const ACCEPTED_METHODS = [
        'submitFeed',
        'processFeed'
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
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Order Acknowledgement Feed Process');
        $this->addOption("method", "m", InputOption::VALUE_REQUIRED, "type");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute($input, $output)
    {
        $this->output = $output;
        $this->input = $input;
        $output->writeln('<options=bold;bg=cyan> ➤ </><fg=cyan>Initiating Order Acknowledgement Process...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $method = $input->getOption("method") ?? false;
        if (!$method) {
            $this->output->writeln("➤ </><options=bold;fg=red>Method name (-m) is mandatory</>");
            return 0;
        }

        if (!method_exists($this, $method) || !in_array($method, self::ACCEPTED_METHODS)) {
            $this->output->writeln("➤ </><options=bold;fg=red>Method not found or not a valid method</>");
            return 0;
        }

        $this->logFile = "amazon/orderAcknowledgement/{$method}/" . date('Y-m-d') . ".log";
        $this->$method();
        return 1;
    }

    public function submitFeed(): void
    {
        $amazonFeedData = $this->mongo->getCollectionForTable(self::AMAZON_FEED_DATA);
        $users = [];
        $options = [
            "typeMap" => [
                'root' => 'array',
                'document' => 'array'
            ],
            'limit' => 1000
        ];
        $query = [
            'user_id' => ['$exists' => true],
            'type' => self::FEED_TYPE
        ];
        $acknowledgementData = $amazonFeedData->find($query, $options)->toArray();
        if (!empty($acknowledgementData)) {
            foreach ($acknowledgementData as $data) {
                if (!isset($users[$data['home_shop_id']])) {
                    $users[$data['home_shop_id']] = $data;
                } else {
                    $feedContent = array_values($data['feedContent'])[0];
                    $users[$data['home_shop_id']]['feedContent'][$feedContent['Id']]
                        = $data['feedContent'][$feedContent['Id']];
                }
            }

            foreach ($users as $userData) {
                $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init('amazon', true, 'amazon')
                    ->call('order-acknowledge', [], ['shop_id' => $userData['shop_id'], 'data' => $userData], 'POST');
                if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                    $feedContainer = $this->mongo->getCollectionForTable(self::FEED_CONTAINER);
                    try {
                        $orderIds = array_column($userData['feedContent'], 'AmazonOrderID');
                        $feedContainer->insertOne(
                            [
                                "feed_id" => $remoteResponse['response']['FeedSubmissionId'] ?? '',
                                "type" => $userData['type'],
                                "status" => $remoteResponse['response']['FeedProcessingStatus'] ?? "IN_PROGRESS",
                                "feed_created_date" => date('c'),
                                "marketplace_id" => $remoteResponse['marketplace_id'] ?? '',
                                "shop_id" => $userData['home_shop_id'],
                                "user_id" => $userData['user_id'],
                                "source_shop_id" => $userData['target_shop_id'],
                                "specifics" => [
                                    "type" => $userData['type'],
                                    "ids" => $orderIds,
                                    "marketplace" => [
                                        $remoteResponse['marketplace_id'] ?? ''
                                    ],
                                    "shop_id" => $userData['home_shop_id'],
                                ],
                                'remote_shop_id' => $userData['shop_id'],
                                'submit_data' => json_encode($userData['feedContent']),
                                'phalcon' => true,
                            ]
                        );
                        $amazonFeedData->deleteMany(
                            [
                                'user_id' => $userData['user_id'],
                                'source_order_id' => ['$in' => $orderIds]
                            ]
                        );
                    } catch (Exception $e) {
                        $this->di->getLog()->logContent('Exception from OrderAckowledgementCommand
                        submitFeed() =>' . json_encode($e, true), 'info', 'exception.log');
                    }
                } else {
                    $this->di->getLog()->logContent('ERROR UserId => ' . $userData['user_id'] .
                        ' ,Remote Response=> ' . json_encode($remoteResponse, true), 'info', $this->logFile);
                }
            }
        }
    }

    public function processFeed(): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $feedContainer = $mongo->getCollectionForTable('feed_container');
        $options = [
            "typeMap" => [
                'root' => 'array',
                'document' => 'array'
            ],
            'limit' => 50
        ];
        $query = [
            'user_id' => ['$exists' => true],
            'type' => self::FEED_TYPE,
            'status' => ['$in' => ['IN_PROGRESS', 'IN_QUEUE']],
            'phalcon' => true
        ];
        $feedData = $feedContainer->find($query, $options)->toArray();
        if (!empty($feedData)) {
            $processedIds = [];
            foreach ($feedData as $data) {
                $response = $this->acknowledgementFeedResponse($data);
                if (isset($response['success']) && $response['success']) {
                    $processedIds[] = $data['feed_id'];
                }
            }

            if (!empty($processedIds)) {
                $feedContainer->updateMany(
                    [
                        'user_id' => $data['user_id'],
                        'feed_id' => ['$in' => $processedIds]
                    ],
                    [
                        '$set' => ['status' => '_DONE_']
                    ]
                );
            }
        }
    }

    public function acknowledgementFeedResponse($data)
    {
        try {
            if (isset($data['remote_shop_id'], $data['marketplace_id'], $data['feed_id'])) {
                $params = [
                    'feed_id' => $data['feed_id'],
                    'marketplace_id' => $data['marketplace_id'],
                    'shop_id' => $data['remote_shop_id'],
                    'type' => $data['type'] ?? self::FEED_TYPE
                ];
                $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init('amazon', true, 'amazon')
                    ->call('feed-sync', [], $params, 'GET');
                if (
                    isset($remoteResponse['success'])
                    && $remoteResponse['success'] && isset($remoteResponse['response'])
                ) {
                    $submitData = json_decode((string) $data['submit_data'], true);
                    $decodedResponse = base64_decode($remoteResponse['response']);
                    $responseData = json_decode($decodedResponse, true);
                    $orderService = $this->di->getObjectManager()->get(Order::class);
                    $orderService->updateAcknowledgedOrder($submitData, $responseData, $data['user_id'], false);
                    return  ['success' => true, 'message' => 'Feed response fetched successfully'];
                }
                $message = 'Remote Error';
                $this->di->getLog()->logContent('ERROR UserId => ' . $data['user_id'] .
                    'Remote Response=> ' . json_encode($remoteResponse, true), 'info', $this->logFile);
            } else {
                $message = 'Required Params Missing';
            }

            $this->di->getLog()->logContent($message, 'info', $this->logFile);
            return  ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from OrderAckowledgementCommand
                        processFeed() =>' . json_encode($e, true), 'info', 'exception.log');
        }
    }
}
