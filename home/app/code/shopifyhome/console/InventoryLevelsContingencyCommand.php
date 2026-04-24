<?php

use App\Shopifyhome\Components\Product\Inventory\Contingency;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Connector\Models\QueuedTasks;
use App\Core\Models\User;


#[\AllowDynamicProperties]
class InventoryLevelsContingencyCommand extends Command
{

    protected static $defaultName = "shopify-inventory-webhook-contingency";

    protected static $defaultDescription = "Contingency process inventory levels update";

    public const QUEUE_NAME = 'shopify_inventory_webhook_contingency';
    public const PROCESS_INVENTORY_WEBHOOK_USERS = 'shopify_process_inventory_webhook_contingency_users';
    public const MAX_MESSAGE_COUNT = 10;
    public const PROCESS_COMPLETED_OPERATION_DELAY = 305; // 5 minutes 5 second
    public const PAYMENT_DETAILS = 'payment_details';
    public const PAID_CLIENT_DEFAULT_PLAN = 100;
    public const PROCESS_CODE = 'inventory_levels_contingency';
    public const FILTER_TYPE = 'bulkinventory';
    public const MARKETPLACE = 'shopify';

    protected $mongo;
    protected $sqsHelper;
    protected $hours;
    protected $mintues;
    protected $timeDelay;
    protected $queuedTaskContainer;
    protected $isSalesChannel;



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
        $this->sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $this->queuedTaskContainer = $this->mongo->getCollection("queued_tasks");
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Syncing Actions Backup Command');
        $this->addOption("method", "m", InputOption::VALUE_REQUIRED, "Method name");
        $this->addOption("user_id", "u", InputOption::VALUE_OPTIONAL, "Userid of User");
        $this->addOption("app_code", "c", InputOption::VALUE_REQUIRED, "App code");
        $this->addOption("app_tag", "p", InputOption::VALUE_REQUIRED, "App tag");
        $this->addOption("priority_users", "w", InputOption::VALUE_OPTIONAL, "priority_users");
        $this->addOption("paid_users", "s", InputOption::VALUE_OPTIONAL, "paid_users");
        $this->addOption("sync_on_target", "t", InputOption::VALUE_OPTIONAL, "Sync on target");
        $this->addOption("hours", "d", InputOption::VALUE_REQUIRED, "Past hours from which we want to sync inventory");
        $this->addOption("minutes", "k", InputOption::VALUE_OPTIONAL, "minutes", 0);
        $this->addOption("is_sales_channel", "l", InputOption::VALUE_OPTIONAL, "Script to be run for public or private app", false);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Contingency Process...</>');
        $this->output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");
        $method = $input->getOption("method") ?? false;
        if ((!$method)) {
            $this->output->writeln("➤ </><options=bold;fg=red>method (-m) is mandatory which method you want to invoke</>");
            return 0;
        }

        $this->appCode = $input->getOption("app_code") ?? false;
        $this->appTag = $input->getOption("app_tag") ?? false;
        if ((!$this->appCode || !$this->appTag)) {
            $this->output->writeln("➤ </><options=bold;fg=red>app_code (-c) and app_tag (-p) is mandatory</>");
            return 0;
        }

        $this->userId = $input->getOption("user_id") ?? false;
        $this->paidUsers = $this->input->getOption("paid_users") ?? false;
        $this->priorityUsers = $this->input->getOption("priority_users") ?? false;
        $this->isSalesChannel = $this->input->getOption("is_sales_channel") ?? false;

        if ($method == 'initiateInventorySyncContingencyProcess' && !$this->paidUsers && !$this->priorityUsers && !$this->userId) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:For which users process has to be initiated paidUsers(-s) or priority_users(-w) or single user(-u)  any one is required</>');
            return 0;
        }

        if ($method) {
            $this->$method();
        }
        $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>Process Completed!!</>");
        return 0;
    }

    /**
     * initiateContingencyProcess function
     * To initiate contingency process for selected users (paid, priority, or specific user)
     * based on input flags and date. Pushes eligible users to SQS queue.
     * @return void
     */
    public function initiateInventorySyncContingencyProcess()
    {
        $this->hours = $this->input->getOption("hours") ?? false;
        if (!$this->hours) {
            $this->output->writeln("➤ </><options=bold;fg=red>hours(-d) is mandatory</>");
            return 0;
        }
        $this->minutes = $this->input->getOption("minutes") ?? 0;
        $this->timeDelay = "-{$this->hours} hours -{$this->minutes} minutes";
        if ($this->userId) {
            $userIds[] = $this->userId;
        } else if ($this->priorityUsers) {
            $userIds = $this->di->getConfig()->get('priority_users_inventory_webhook_contingency') ? $this->di->getConfig()->get('priority_users_inventory_webhook_contingency')->toArray() : [];
        } else if ($this->paidUsers) {
            $userData = $this->getPaidClientDetails();
            $userIds = array_column($userData, 'user_id');
        }
        if (!empty($userIds)) {
            $userCount = count($userIds);
            $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>Total Users Found:$userCount</>");
            $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing Users....</>");
            $prepareHandlerData = [];
            $processedUsers = 0;
            $this->logFile = "shopify/inventoryWebhookContingency/initiateProcess/" . date('d-m-Y') . '.log';
            foreach ($userIds as $id) {
                    $response = $this->setDiForUser($id);
                    if (isset($response['success']) && $response['success']) {
                        $shopData = $this->getShopData();
                        if (!empty($shopData)) {
                            $feedId = $this->setQueuedTask($id, $shopData);
                            if (is_string($feedId)) {
                                $prepareHandlerData[] = $this->prepareSqsData($id, $shopData['_id'], $feedId);
                            } else {
                                $message = $feedId;
                                $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red>Unable to start process for this user: $id</>");
                                $this->di->getLog()->logContent('Unable to start inventory contingency process for this user: ' . json_encode([
                                    'user_id' => $id,
                                    'message' => $message,
                                ]), 'info', $this->logFile);
                            }
                        } else {
                            $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red>Shop Data not found for user: $id</>");
                            $this->di->getLog()->logContent('Shop Data not found for user: ' . json_encode($id), 'info', $this->logFile);
                        }
                    } else {
                        $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red>Unable to set di for userId: $id</>");
                        $this->di->getLog()->logContent('Unable to set di for userId: ' . json_encode($id), 'info', $this->logFile);
                    }
                if (count($prepareHandlerData) == self::MAX_MESSAGE_COUNT) {
                    $this->sqsHelper->pushMessagesBatch($prepareHandlerData);
                    $processedUsers = $processedUsers + count($prepareHandlerData);
                    $prepareHandlerData = [];
                }
            }
            if (!empty($prepareHandlerData)) {
                $this->sqsHelper->pushMessagesBatch($prepareHandlerData);
                $processedUsers = $processedUsers + count($prepareHandlerData);
            }
            if ($processedUsers > 0) {
                $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>For $processedUsers users process intitiated</>");
                $this->di->getLog()->logContent("For $processedUsers users process intitiated", 'info', $this->logFile);
                $this->startDataReadingProcess(end($userIds));
            }
        } else {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No users found</>');
        }
    }

    /**
     * Function set the di by userId
     *
     * @param string $userId
     * @return array
     */
    private function setDiForUser($userId)
    {
        try {
            if ($this->di->getUser()->id == $userId) {
                return [
                    'success' => true,
                    'message' => 'User di is already set'
                ];
            }
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

    /**
     * getShopData function
     * To fetch the active shop data for the user associated with the Shopify marketplace.
     * @return array
     */
    private function getShopData()
    {
        $shops = $this->di->getUser()->shops ?? [];
        if (!empty($shops)) {
            foreach ($shops as $shop) {
                if (
                    $shop['marketplace'] == self::MARKETPLACE
                    && $shop['apps'][0]['app_status'] == 'active' &&
                    !isset($shop['store_closed_at'])
                ) {
                    return $shop;
                }
            }
        }
        return [];
    }

    /**
     * startDataReadingProcess function
     * Initiates the process of pushing user data to SQS for inventory webhook contingency processing.
     * Prepares the payload with relevant context like user ID, class, method, app codes, and process codes.
     * Optionally includes sync_on_target if specified.
     *
     * @param string $userId The ID of the user whose data reading process is to be initiated.
     * @return void
     */
    private function startDataReadingProcess($userId)
    {
        $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Initiating Data Reading Process...</>');
        $syncOnTarget = $this->input->getOption("sync_on_target") ?? false;
        $preparedData = [
            'type' => 'full_class',
            'class_name' => Contingency::class,
            'method' => 'processBulkOperationCompletedUsers',
            'user_id' => (string)$userId,
            'queue_name' => self::PROCESS_INVENTORY_WEBHOOK_USERS,
            'appCode' => $this->appCode,
            'app_code' => [$this->appCode => $this->appCode],
            'app_codes' => [$this->appCode],
            'appTag' => $this->appTag,
            'process_code' => self::PROCESS_CODE,
            'delay' => self::PROCESS_COMPLETED_OPERATION_DELAY
        ];
        if ($syncOnTarget) {
            $preparedData['sync_on_target'] = $syncOnTarget;
        }
        $this->di->getLog()->logContent('Inventory Data reading process initiated: ' . json_encode($preparedData), 'info', $this->logFile);
        $this->sqsHelper->pushMessage($preparedData);
    }

    /**
     * prepareSqsData function
     * To prepare SQS message payload with necessary details for webhook contingency process
     * @param [string] $userId
     * @param [string] $feedId
     * @return array
     */
    private function prepareSqsData($userId, $shopId, $feedId)
    {
        $preparedData =  [
            'type' => 'full_class',
            'class_name' => Contingency::class,
            'method' => 'initiateInventoryContingencyProcess',
            'user_id' => $userId,
            'shop_id' => $shopId,
            'queue_name' => self::QUEUE_NAME,
            'updated_at' => (new DateTime($this->timeDelay))->format('Y-m-d\TH:i:s\Z'),
            'appCode' => $this->appCode,
            'feed_id' => $feedId,
            'filter_type' => self::FILTER_TYPE,
            'marketplace' => self::MARKETPLACE,
            'app_code' => [$this->appCode => $this->appCode],
            'app_codes' => [$this->appCode],
            'appTag' => $this->appTag,
            'process_code' => self::PROCESS_CODE
        ];
        if($this->isSalesChannel) {
            $preparedData['sales_channel'] = true;
        }
        return $preparedData;
    }

    /**
     * getPaidClientDetails function
     * To fetch users who have active paid plans with custom pricing enabled.
     * @return array|null
     */
    private function getPaidClientDetails()
    {
        try {
            $query = [
                'type' => 'active_plan',
                'status' => 'active',
                'plan_details.custom_price' => ['$gt' => self::PAID_CLIENT_DEFAULT_PLAN]
            ];
            $options = [
                "projection" => ['user_id' => 1, '_id' => 0],
                "typeMap" => ['root' => 'array', 'document' => 'array']
            ];
            $paymentDetails = $this->mongo->getCollectionForTable(self::PAYMENT_DETAILS);
            return $paymentDetails->find(
                $query,
                $options
            )->toArray();
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from getPaidClientDetails(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * checkInventoryContingencyProcessStuck function
     * To check if user's contingency process is stuck for more than given hours-1.
     * Marks the process completed with an error if stuck.
     * @param [string] $userId
     * @return bool
     */
    private function checkInventoryContingencyProcessStuck($userId, $shop)
    {
        try {
            $this->logFile = "shopify/inventoryContingency/$userId/" . date('d-m-Y') . '.log';
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $queuedTaskData = $this->queuedTaskContainer->findOne([
                'user_id' => $userId,
                'shop_id' => $shop['_id'],
                'process_code' => self::PROCESS_CODE,
                'marketplace' => self::MARKETPLACE
            ], $options);
            
            if (!empty($queuedTaskData)) {
                $latestKey = $queuedTaskData['updated_at'] ?? $queuedTaskData['created_at'];
                $date = new \DateTime($latestKey);
                $now = new \DateTime();
                if ($date instanceof \DateTime) {
                    $intervalInSeconds = $now->getTimestamp() - $date->getTimestamp();
                    if ($intervalInSeconds > ($this->hours - 1) * 3600) {
                        $this->di->getLog()->logContent('Inventory Contingency Progress Stuck for user reinitiating process: ' . json_encode($queuedTaskData), 'info', $this->logFile);
                        $this->queuedTaskContainer->deleteOne([
                            '_id' => $queuedTaskData['_id']
                        ]);
                        return true;
                    }
                }
            }
            return false;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from checkInventoryContingencyProcessStuck(): ' . json_encode($e->getMessage()), 'info',  $this->logFile);
        }
    }

    private function setQueuedTask($userId, $shop)
    {
        try {
            $logFile = "shopify/inventoryContingency/$userId/" . date('d-m-Y') . '.log';
            $this->di->getAppCode()->setAppTag($this->appTag);

            $queueData = [
                'user_id' => $userId,
                'message' => "Initiating Inventory Sync Contingency Process",
                'process_code' => self::PROCESS_CODE,
                'marketplace' => $shop['marketplace']
            ];
            $queuedTask = new QueuedTasks;
            $queuedTaskId = $queuedTask->setQueuedTask($shop['_id'], $queueData);
            if (!$queuedTaskId) {
                if ($this->checkInventoryContingencyProcessStuck($userId, $shop)) {
                    $queuedTask = new QueuedTasks;
                    $queuedTaskId = $queuedTask->setQueuedTask($shop['_id'], $queueData);
                    $this->di->getLog()->logContent('New queued task created queuedTaskId: ' . json_encode($queuedTaskId), 'info', $logFile);
                } else {
                    $this->di->getLog()->logContent('Inventory Contingency process is already under progress. Please check notification for updates.', 'info', $logFile);
                    return ['success' => false, 'message' => 'Inventory Contingency process is already under progress. Please check notification for updates.'];
                }
            }
            return $queuedTaskId;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from Inventory Contingency setQueuedTask(): ' . json_encode($e->getMessage()), 'info',  $logFile);
        }
    }
}
