<?php

use App\Core\Models\User;
use App\Connector\Models\QueuedTasks;
use App\Shopifyhome\Components\Webhook\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


#[\AllowDynamicProperties]
class ProductWebhookBackupCommand extends Command
{
    protected static $defaultName = "product-webhook-backup";

    protected static $defaultDescription = "Product Syncing Backup Command'";

    public const QUEUE_NAME = 'shopify_product_webhook_backup_sync';
    public const MAX_MESSAGE_COUNT = 10;
    public const USER_COLLECTION = 'user_details';

    protected $action;
    protected $mongo;
    protected $sqsHelper;

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
        $this->addOption("app_code", "a", InputOption::VALUE_REQUIRED, "App code");
        $this->addOption("app_tag", "t", InputOption::VALUE_REQUIRED, "App tag");
        $this->addOption("webhook_backup_sync_priority_users", "p", InputOption::VALUE_OPTIONAL, "Selective Priority Users");
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
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Webhook Action Process...</>');
        $this->output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");
        $this->appCode = $input->getOption("app_code") ?? false;
        $this->appTag = $input->getOption("app_tag") ?? false;
        if (!$this->appCode || !$this->appTag) {
            $this->output->writeln("➤ </><options=bold;fg=red>app_code (-a) and app_tag (-t) is mandatory</>");
            return 0;
        }
        $this->initiateWebhookBackup();
        $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>Process Completed</>");
        return 1;
    }

    /**
     * Used to get Paid users 
     *
     * @return array
     */
    private function getPaidClientDetails()
    {
        try {
            $query = [
                'type' => 'active_plan',
                'status' => 'active',
                'plan_details.custom_price' => ['$gt' => 0]
            ];
            $options = [
                "projection" => ['user_id' => 1, '_id' => 0],
                "typeMap" => ['root' => 'array', 'document' => 'array']
            ];
            $paymentDetails = $this->mongo->getCollectionForTable('payment_details');
            return $paymentDetails->find(
                $query,
                $options
            )->toArray();
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from getPaidClientDetails(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * initiateWebhookBackup function
     * To initiate listing_remove Webhook backup for particular users
     * @return string
     */
    private function initiateWebhookBackup()
    {
        try {
            $this->priorityUsers = $this->input->getOption("webhook_backup_sync_priority_users") ?? false;
            $users = [];
            if ($this->priorityUsers) {
                $priorityUsers = $this->di->getConfig()->get('webhook_backup_sync_priority_users') ? $this->di->getConfig()->get('webhook_backup_sync_priority_users')->toArray() : [];
                if (!empty($priorityUsers)) {
                    $users = $priorityUsers;
                }
            }
            //Uncomment the code to initiate for paid client
            // elseif (!empty($this->getPaidClientDetails())) {
            //     $users = $this->getPaidClientDetails();
            //     $users = array_column($users, 'user_id');
            // }
            foreach ($users as $user) {
                $logFile = "shopify/productRemoveContingency/$user/" . date('d-m-Y') . '.log';
                $response = $this->setDiForUser($user);
                if (isset($response['success']) && $response['success']) {
                    $shops = $this->di->getUser()->getShops() ?? [];
                    if (!empty($shops)) {
                        foreach ($shops as $shop) {
                            if ($shop['marketplace'] == 'shopify') {
                                $shopData = $shop;
                                break;
                            }
                        }
                        $sqsData = $this->prepareSqsData($user, $shopData);
                        $sourceShopId = $sqsData['shop_id'];
                        $queuedTask = new QueuedTasks;
                        $this->di->getAppCode()->setAppTag($this->appTag);
                        $queuedTaskId = $queuedTask->setQueuedTask($sourceShopId, $sqsData);
                        if (!$queuedTaskId) {
                            $this->di->getLog()->logContent('Error: ' . json_encode($queuedTaskId), 'info', $logFile);
                        }
                        if (!empty($sqsData)) {
                            $sqsData['feed_id']=$queuedTaskId;
                            $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($sqsData);
                        } else {
                            $message = 'SQS data not received';
                            $this->di->getLog()->logContent('Error: ' . json_encode($message), 'info', $logFile);
                        }
                    }
                } else {
                    $message = 'Unable to set Di';
                    $this->di->getLog()->logContent('Error: ' . json_encode($message), 'info', $logFile);
                }
            }
            return [
                'success' => true,
                'message' => 'Process Completed'
            ];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from initiateWebhookBackup(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
            }
        }
     
    /**
     * {Prepare SQS Data
     * @param string $userId
     * @return array
     */
    private function prepareSqsData($userId, $shopData)
    {
        return [
            'type' => 'full_class',
            'class_name' => Helper::class,
            'method' => 'handleProductWebhook',
            'user_id' => $userId,
            'shop_id' => $shopData['_id'],
            'remote_shop_id' => $shopData['remote_shop_id'],
            'queue_name' => self::QUEUE_NAME,
            'appCode' => $this->appCode,
            'app_code' => [$this->appCode => $this->appCode],
            'app_codes' => [$this->appCode],
            'appTag' => $this->appTag,
            'message' => "Contingency Process Initiated",
            'process_code' => 'shopify_product_remove',
            'marketplace' => 'shopify'
        ];
    }

    /**
     * setDiForUser function
     * To set current user in Di
     * @param [string] $userId
     * @return array
     */
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

    /**
     * Logs the activity of product remove backup process.
     * Creates notifications based on success or failure.
     *
     * @param array $data Contains marketplace and process metadata.
     * @param bool $status Success or failure status.
     * @param string $message Message to log.
     */
    private function updateActivityLog($data, $status, $message)
    {
        $appTag = $this->di->getAppCode()->getAppTag();
        $notificationData = [
            'severity' => $status ? 'success' : 'critical',
            'message' => $message ?? 'Contigency Process',
            "user_id" => $this->di->getUser()->id,
            "marketplace" => $data['marketplace'],
            "appTag" => $appTag,
            'process_code' => $data['data']['process_code'] ?? 'shopify_product_remove'
        ];
        $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
            ->addNotification($data['shop_id'], $notificationData);
    }

    /**
     * Removes an entry from the queued task after the process is completed.
     *
     * @param string $processCode Unique process identifier.
     */
    private function removeEntryFromQueuedTask($processCode)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $queueTasksContainer = $mongo->getCollection("queued_tasks");
            $queueTasksContainer->deleteOne([
                'user_id' => $this->di->getUser()->id,
                'process_code' => $processCode
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception removeEntryFromQueuedTask(), Error: ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }
}
