<?php

use App\Connector\Contracts\Sales\Order\RefundInterface;
use App\Core\Models\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncBatchOrderRefundsCommand extends Command
{
    protected static $defaultName = "order-refund:amazon-batch";

    protected static $defaultDescription = "Submit and sync Batch Order Refund feeds on Amazon";

    protected $connectorRefundService = null;

    protected $output = null;

    protected $input = null;

    protected $logFile = null;
    protected $di;
    protected $mongoConnection;

    public const AMAZON_FEED_DATA_COLLECTION = 'amazon_feed_data';

    public const FEED_CONTAINER = 'feed_container';

    public const FEED_TYPE = 'POST_PAYMENT_ADJUSTMENT_DATA';

    public const ACCEPTED_METHODS = [
        'submit-feed' => 'submitFeed',
        'process-feed' => 'processFeed'
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
        $this->mongoConnection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getConnection();
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Submit and sync Batch Order Refund feeds on Amazon');
        $this->addOption("method", "m", InputOption::VALUE_REQUIRED, "which method to call [submitFeed, processFeed]");
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

        $method = $input->getOption("method") ?? false;
        if (!$method) {
            $this->output->writeln("➤ </><options=bold;fg=red>Method name (-m) is required</>");
            return 1;
        }

        if (!isset(self::ACCEPTED_METHODS[$method]) || !method_exists($this, self::ACCEPTED_METHODS[$method])) {
            $this->output->writeln("➤ </><options=bold;fg=red>Method not found or invalid</>");
            return 1;
        }

        $output->writeln("<options=bold;bg=cyan> ➤ </><options=bold;fg=yellow> Initiating Amazon batch Order Refund <fg=white>{$method}</> process...</>");
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $this->logFile = "order/order-refund/amazon-batch/{$method}/" . date('Y-m-d') . ".log";

        $methodToCall = self::ACCEPTED_METHODS[$method];
        $this->$methodToCall();
        return 0;
    }

    public function submitFeed(): void
    {
        $amazonFeedDataCollection = $this->getMongoCollection(self::AMAZON_FEED_DATA_COLLECTION);
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
        $orderRefundData = $amazonFeedDataCollection->find($query, $options)->toArray();
        if (empty($orderRefundData)) {
            $this->output->writeln("➤ </><options=bold;fg=green>No data found to submit feed</>");
            return;
        }

        $this->prepareFeedAndSubmit($orderRefundData);
    }

    public function prepareFeedAndSubmit($orderRefundData): void
    {
        $this->output->writeln("➤ </><fg=#e18313>Initiating Feed preparation and submission</>");
        $users = [];
        if (!empty($orderRefundData)) {
            $amazonFeedData = $this->getMongoCollection(self::AMAZON_FEED_DATA_COLLECTION);
            foreach ($orderRefundData as $data) {
                $users[$data['home_shop_id']]['feedContent'][$data['feed_message_id']] = $data['feed_content'][$data['feed_message_id']];
                if (!isset($users[$data['home_shop_id']]['remote_shop_id'])) {
                    $users[$data['home_shop_id']]['remote_shop_id'] = $data['remote_shop_id'];
                    $users[$data['home_shop_id']]['user_id'] = $data['user_id'];
                }
            }

            foreach ($users as $homeShopId => $userData) {
                $this->output->writeln("➤ </><fg=blue>Submitting feed for user_id - {$userData['user_id']}</>");
                $homeShopId = (string)$homeShopId;
                $params = [
                    'shop_id' => $homeShopId,
                    'feedContent' => $userData['feedContent'],
                    'admin' => true
                ];
                $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init('amazon', true, 'amazon')
                    ->call(
                        'feed/refund',
                        [],
                        [
                            'shop_id' => $userData['remote_shop_id'],
                            'data' => $params
                        ],
                        'POST'
                    );

                $this->di->getLog()->logContent('remoteResponse -' . print_r($remoteResponse, true), 'info', $this->logFile);

                if (!isset($remoteResponse['success']) || !$remoteResponse['success']) {
                    $message = $remoteResponse['message'] ?? $remoteResponse['msg'] ?? 'Something went wrong';
                    if (str_contains((string) $message, "'AdjustmentReason' is invalid")) {
                        $remoteResponse['message'] = 'Refund reason cannot be empty';
                    }

                    $errorMessage = $this->getRemoteErrorMessage($remoteResponse);

                    $stringMessage = json_encode($message);
                    $this->output->writeln("➤ </><options=bold;fg=red>ERROR: {$stringMessage}</>");

                    if (stripos((string) $errorMessage, 'Api limit exceed') !== false) {
                        continue;
                    }

                    $orderIds = array_column($userData['feedContent'], 'AmazonOrderId');
                    $feedMessageIds = array_column($userData['feedContent'], 'Id');

                    $connectorRefundService = $this->di
                        ->getObjectManager()
                        ->get(RefundInterface::class);

                    foreach ($feedMessageIds as $feedMessageId) {
                        $responseUpdateData = [
                            'user_id' => $userData['user_id'],
                            'marketplace' => 'amazon',
                            'marketplace_shop_id' => $homeShopId,
                            'message_id' => $feedMessageId,
                            'message' => $errorMessage,
                        ];
                        $connectorRefundService->refundResponseUpdate($responseUpdateData);
                    }

                    $amazonFeedData->deleteMany(
                        [
                            'user_id' => $userData['user_id'],
                            'home_shop_id' => $homeShopId,
                            'amazon_order_id' => ['$in' => $orderIds]
                        ]
                    );
                    continue;
                }

                $this->output->writeln("➤ </><fg=green>Feed submitted successfully</>");

                $feedContainer = $this->getMongoCollection(self::FEED_CONTAINER);
                try {
                    $orderIds = array_column($userData['feedContent'], 'AmazonOrderId');
                    $feedMessageIds = array_column($userData['feedContent'], 'Id');

                    $feedContainer->insertOne(
                        [
                            "user_id" => $userData['user_id'],
                            "feed_id" => $remoteResponse['data']['FeedSubmissionId'] ?? '',
                            "type" => self::FEED_TYPE,
                            "status" => $remoteResponse['data']['FeedProcessingStatus'] ?? "IN_QUEUE",
                            "feed_created_date" => $remoteResponse['data']['SubmittedDate'] ?? date('c'),
                            "marketplace_id" => $remoteResponse['marketplace_id'] ?? '',
                            "shop_id" => $homeShopId,
                            "specifics" => [
                                "type" => self::FEED_TYPE,
                                "ids" => $orderIds,
                                'message_ids' => $feedMessageIds,
                                "marketplace" => [
                                    $remoteResponse['marketplace_id'] ?? ''
                                ],
                                "shop_id" => $homeShopId,
                            ],
                            'remote_shop_id' => $userData['remote_shop_id'],
                            'submit_data' => json_encode($userData['feedContent']),
                            'phalcon' => true,
                        ]
                    );

                    $this->output->writeln("➤ </><fg=green>Feed data saved in 'feed_container' successfully</>");

                    $amazonFeedData->deleteMany(
                        [
                            'user_id' => $userData['user_id'],
                            'home_shop_id' => $homeShopId,
                            'amazon_order_id' => ['$in' => $orderIds]
                        ]
                    );
                    $this->output->writeln("➤ </><fg=magenta>Data deleted from 'amazon_feed_data' successfully</>");
                } catch (Exception $e) {
                    $this->di->getLog()->logContent('Exception in submitFeed() =>' . json_encode($e, true), 'error', $this->logFile);
                }
            }
        }
    }

    public function processFeed(): void
    {
        $this->output->writeln("➤ </><fg=#e18313>Initiated feed processing</>");

        $feedContainer = $this->getMongoCollection(self::FEED_CONTAINER);
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

        $feedsData = $feedContainer->find($query, $options)->toArray();
        if (empty($feedsData)) {
            $this->output->writeln("➤ </><fg=magenta>No saved feed data found in 'feed_container' to process</>");
            return;
        }

        $processedIds = [];
        $this->connectorRefundService = $this->di
            ->getObjectManager()
            ->get(RefundInterface::class);

        foreach ($feedsData as $feedData) {
            $response = $this->commenceRefundResponse($feedData);
            if (isset($response['success']) && $response['success']) {
                $processedIds[] = $feedData['feed_id'];
            }
        }

        if (!empty($processedIds)) {
            $feedContainer->updateMany(
                [
                    'user_id' => $feedData['user_id'],
                    'type' => self::FEED_TYPE,
                    'feed_id' => ['$in' => $processedIds]
                ],
                [
                    '$set' => ['status' => 'DONE']
                ]
            );
        }
    }

    public function commenceRefundResponse($feedData)
    {
        $this->output->writeln("➤ </><fg=blue>Processing for user_id - {$feedData['user_id']}</>");
        $setDiForUserResponse = $this->setDiForUser($feedData['user_id']);
        if (!$setDiForUserResponse['success']) {
            $this->output->writeln("➤ </><options=bold;fg=red>ERROR: {$setDiForUserResponse['message']}</>");
            return $setDiForUserResponse;
        }

        return $this->refundResponse($feedData);
    }

    public function refundResponse($feedData)
    {
        $fetchFeedResponse = $this->fetchFeedResponse($feedData);
        if (!$fetchFeedResponse['success']) {
            $this->output->writeln("➤ </><options=bold;fg=red>ERROR: {$fetchFeedResponse['message']}</>");
            return $fetchFeedResponse;
        }

        $this->output->writeln("➤ </><fg=green>Feed response fetched successfully</>");

        $responseArray = $fetchFeedResponse['data'];

        $refundResponse['user_id'] = $feedData['user_id'];
        $refundResponse['marketplace'] = 'amazon';
        $refundResponse['marketplace_shop_id'] = $feedData['shop_id'];

        if (isset($responseArray['Message']['ProcessingReport']['Result'])) {
            $processingResult = $responseArray['Message']['ProcessingReport']['Result'];
            if (!isset($processingResult[0])) {
                $processingResult = [$processingResult];
            }

            foreach ($processingResult as $value) {
                $refundResponse['message_id'] = $value['MessageID'];
                $hasFailure = false;
                if (isset($value['ResultCode']) && $value['ResultCode'] == 'Error') {
                    $hasFailure = true;
                    $refundResponse['message'] = $this->parseFeedResponseErrorMessage($value['ResultDescription']);
                }

                $this->connectorRefundService->refundResponseUpdate($refundResponse, $hasFailure);
            }

            $this->output->writeln("➤ </><options=bold;fg=green>Refund response updated successfully in DB</>");
        }

        return ['success' => true, 'message' => 'Refund response fetched successfully'];
    }

    public function fetchFeedResponse($data): array
    {
        if (!isset($data['marketplace_id']) || !isset($data['remote_shop_id']) || !isset($data['feed_id'])) {
            return  ['success' => false, 'message' => 'marketplace_id/remote_shop_id/feed_id not found'];
        }

        $params = [
            'feed_id' => $data['feed_id'],
            'marketplace_id' => $data['marketplace_id'],
            'shop_id' => $data['remote_shop_id'],
            'type' => self::FEED_TYPE
        ];
        $response = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('amazon', true, 'amazon')
            ->call('feed-sync', [], $params, 'GET');

        if (!$response['success']) {
            return [
                'success' => false,
                'message' => 'might be in_process'
            ];
        }

        if (!isset($response['response'])) {
            return [
                'success' => false,
                'message' => 'response not found'
            ];
        }

        $encodedResponse = $response['response'];
        if (!is_string($encodedResponse)) {
            $errorMessage = isset($encodedResponse['FeedProcessingStatus'])
                && $encodedResponse['FeedProcessingStatus'] == 'IN_QUEUE'
                ? 'Feed is IN_QUEUE'
                : 'array returned from remote, expected string';
            return ['success' => false, 'message' => $errorMessage];
        }

        $decodedResponse = base64_decode($encodedResponse);
        $responseArray = json_decode($decodedResponse, true);
        if (!isset($responseArray['Message']['ProcessingReport'])) {
            return [
                'success' => false,
                'message' => 'ProcessingReport not found'
            ];
        }

        $processingReport = $responseArray['Message']['ProcessingReport'];
        if (!isset($processingReport['StatusCode'])) {
            return [
                'success' => false,
                'message' => 'StatusCode not found'
            ];
        }

        return [
            'success' => true,
            'data' => $responseArray
        ];
    }

    public function parseFeedResponseErrorMessage($message)
    {
        if (str_contains((string) $message, 'This is a malformed or invalid XML document.')) {
            /*
            Amazon returns this error message for various reasons, including an invalid Amazon Order ID format (e.g., not in 3-7-7 format).
            Real Amazon orders will always have valid IDs. This issue typically occurs with test orders which might have incorrect ID formats.
            */
            $message = 'Refund reason might be invalid';
        }

        if (str_contains((string) $message, 'For troubleshooting help')) {
            $message = substr((string) $message, 0, strpos((string) $message, " For troubleshooting help"));
        }

        return $message;
    }

    public function getRemoteErrorMessage($remoteResponse)
    {
        $errorMessage = $remoteResponse['message'] ?? $remoteResponse['msg'];
        if (isset($errorMessage['errors'])) {
            $amazonErrors = json_decode((string) $errorMessage, true);
            $errors = [];
            foreach ($amazonErrors as $error) {
                $errors[] = $error['message'] . ' (Response from Amazon)';
            }

            if (count($errors) == 1) {
                $errors = $errors[0];
            }

            $errorMessage = $errors;
        }

        return $errorMessage;
    }

    public function setDiForUser($userId): array
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

    public function getMongoCollection($collection)
    {
        return $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')
            ->getCollection($collection);
    }
}
