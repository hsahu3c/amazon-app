<?php

use App\Shopifyhome\Components\Webhook\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[\AllowDynamicProperties]
class SyncBackupDataCommand extends Command
{
    protected static $defaultName = "sync-backup-data";

    protected static $defaultDescription = "Syncing Actions Backup Command'";

    public const QUEUE_NAME = 'shopify_webhook_backup_sync';
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
        $this->addOption("user_id", "u", InputOption::VALUE_OPTIONAL, "Userid of User");
        $this->addOption("action", "a", InputOption::VALUE_REQUIRED, "Action which to be performed");
        $this->addOption("target", "t", InputOption::VALUE_REQUIRED, "Connected target name");
        $this->addOption("app_code", "c", InputOption::VALUE_REQUIRED, "App code");
        $this->addOption("app_tag", "p", InputOption::VALUE_REQUIRED, "App tag");
        $this->addOption("selective_users", "s", InputOption::VALUE_OPTIONAL, "Define Selective users in getSelectiveUsers()");
        $this->addOption("sync_to_target", "m", InputOption::VALUE_OPTIONAL, "Sync inventory to target");
        $this->addOption("date", "d", InputOption::VALUE_OPTIONAL, "Data to be synced from which date(Y-m-d) by default it will set -1 day");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Backup Sync from Shopify...</>');
        $this->output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");
        $this->action = $input->getOption("action") ?? false;
        $this->target = $input->getOption("target") ?? false;
        $this->appCode = $input->getOption("app_code") ?? false;
        $this->appTag = $input->getOption("app_tag") ?? false;
        $this->syncToTarget = $input->getOption("sync_to_target") ?? false;

        if ((!$this->action || !$this->target) || (!$this->appCode || !$this->appTag)) {
            $this->output->writeln("➤ </><options=bold;fg=red>action (-a) and target (-t) is mandatory</>");
            $this->output->writeln("➤ </><options=bold;fg=red>app_code (-c) and app_tag (-p) is mandatory</>");
            return 0;
        }

        $this->userId = $input->getOption("user_id") ?? false;
        $this->selectiveUsers = $input->getOption("selective_users") ?? false;
        $this->date = $input->getOption("date") ?? date('Y-m-d', strtotime('-1 days'));
        switch ($this->action) {
            case 'inventory':
                $this->syncInventory();
                break;
            case 'shipment':
                $this->syncShipment();
                break;
            default:
                $this->output->writeln("➤ </><options=bold;fg=red>Invalid specified action</>");
                break;
        }
        $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>Process Completed</>");
        return 1;
    }

    private function syncInventory()
    {
        if ($this->userId) {
            $this->sqsHelper->pushMessage($this->prepareSqsData($this->userId));
        } else {
            $this->initiateSyncProcess();
        }
    }

    private function syncShipment()
    {
        if ($this->userId) {
            $this->sqsHelper->pushMessage($this->prepareSqsData($this->userId));
        } else {
            $this->initiateSyncProcess();
        }
    }

    private function initiateSyncProcess()
    {
        $userData = $this->getTargetConnectedUsers();
        if (!empty($userData)) {
            $userCount = count($userData);
            $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>Total Users Found:$userCount</>");
            $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>Processing Users....</>");
            $prepareHandlerData = [];
            foreach ($userData as $user) {
                $prepareHandlerData[] = $this->prepareSqsData($user['user_id']);
                if (count($prepareHandlerData) == self::MAX_MESSAGE_COUNT) {
                    $this->sqsHelper->pushMessagesBatch($prepareHandlerData);
                    $prepareHandlerData = [];
                }
            }
            if (!empty($prepareHandlerData)) {
                $this->sqsHelper->pushMessagesBatch($prepareHandlerData);
            }
        } else {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No users found</>');
        }
    }

    private function getTargetConnectedUsers()
    {
        $pipeline = [
            [
                '$match' => [
                    'shops' => [
                        '$elemMatch' => [
                            'marketplace' => $this->target,
                            'apps.app_status' => 'active'
                        ]
                    ]
                ]
            ],
            ['$project' => [
                '_id' => 0,
                'user_id' => 1
            ]]
        ];

        if ($this->selectiveUsers) {
            $users = $this->getSelectiveUsers();
            if (empty($users)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Kindly provide selective users user_id in getSelectiveUsers()</>');
                return false;
            }
            $preparePipline = ['$match' => ['user_id' => ['$in' => $users]]];
            array_splice($pipeline, 0, 0, [$preparePipline]);
        }
        $userCollection = $this->mongo->getCollectionForTable(self::USER_COLLECTION);
        return $userCollection->aggregate($pipeline, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
    }

    private function prepareSqsData($userId)
    {
        return [
            'type' => 'full_class',
            'class_name' => Helper::class,
            'method' => 'handleBackupSync',
            'user_id' => $userId,
            'queue_name' => self::QUEUE_NAME,
            'action' => $this->action,
            'date' => $this->date, 
            'appCode' => $this->appCode,
            'app_code' => [$this->appCode => $this->appCode],
            'app_codes' => [$this->appCode],
            'appTag' => $this->appTag,
            'sync_to_target' => $this->syncToTarget ? true : false

        ];
    }

    private function getSelectiveUsers()
    {
        return [];
    }
}
