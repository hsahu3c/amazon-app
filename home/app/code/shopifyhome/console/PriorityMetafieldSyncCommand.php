<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Connector\Models\QueuedTasks;
use App\Core\Models\User;

#[\AllowDynamicProperties]
class PriorityMetafieldSyncCommand extends Command
{
    protected static $defaultName = "priority:sync-product-metafield";

    protected static $defaultDescription = "Syncing Products Metafields from Shopify for priority user";

    const MARKETPLACE = 'shopify';
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
        $this->setHelp('Sync products metafield from shopify for priority clients');
        $this->addOption("app_tag", "a", InputOption::VALUE_REQUIRED, "app_tag");
        $this->addOption("hours", "t", InputOption::VALUE_REQUIRED, "hours");
        $this->addOption("minutes", "m", InputOption::VALUE_REQUIRED, "minutes", 0);
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->appTag = $input->getOption("app_tag") ?? false;
        $this->hours = $input->getOption("hours") ?? false;
        $this->minutes = $input->getOption("minutes") ?? 0;

        if (!$this->appTag || !$this->hours) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:app_tag(-a) and hours(-h) is required</>');
            return 0;
        }
        $this->timeDelay = "-{$this->hours} hours -{$this->minutes} minutes";
        $this->initiateProcess();
        return 1;
    }

    private function initiateProcess()
    {
        $logFile = "shopify/priorityMetafieldSync/" . date('d-m-Y') . '.log';
        $users = $this->di->getConfig()->get('priority_metafield_sync_users') ? $this->di->getConfig()->get('priority_metafield_sync_users')->toArray() : [];
        if (!empty($users)) {
            foreach ($users as $userId) {
                $this->di->getLog()->logContent('Starting Process for UserId: ' .
                    json_encode($userId, true), 'info', $logFile);
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    $shops = $this->di->getUser()->shops ?? [];
                    $shopData = [];
                    if (!empty($shops)) {
                        foreach ($shops as $shop) {
                            if (
                                $shop['marketplace'] == self::MARKETPLACE
                                && $shop['apps'][0]['app_status'] == 'active' &&
                                !isset($shop['store_closed_at'])
                            ) {
                                $shopData = $shop;
                                break;
                            }
                        }
                    }
                    if (!empty($shopData)) {
                        $appCode = $shopData['apps'][0]['code'] ?? 'default';
                        $this->di->getAppCode()->setAppTag($this->appTag);
                        $processCode = 'metafield_import';
                        $queueData = [
                            'user_id' => $userId,
                            'message' => "Just a moment! We're retrieving your products metafield from the source catalog. We'll let you know once it's done.",
                            'process_code' => $processCode,
                            'marketplace' => $shop['marketplace']
                        ];
                        $queuedTask = new QueuedTasks;
                        $queuedTaskId = $queuedTask->setQueuedTask($shopData['_id'], $queueData);
                        if (!$queuedTaskId) {
                            if ($this->checkMetafieldProgressStuck($userId, $shopData)) {
                                $queuedTask = new QueuedTasks;
                                $queuedTaskId = $queuedTask->setQueuedTask($shopData['_id'], $queueData);
                                $this->di->getLog()->logContent('New queued task created queuedTaskId: '.json_encode($queuedTaskId), 'info', $logFile);
                            } else {
                                $this->di->getLog()->logContent('Metafield Import process is already under progress. Please check notification for updates', 'info', $logFile);
                                continue;
                            }
                        }
                        if (isset($queuedTaskId['success']) && !$queuedTaskId['success']) {
                            $this->di->getLog()->logContent('Success false setQueueTask response: ' . json_encode($queuedTaskId), 'info', $logFile);
                            continue;
                        }
                        $prepareData = [
                            'type' => 'full_class',
                            'appCode' => $appCode,
                            'appTag' => $this->appTag,
                            'class_name' => \App\Shopifyhome\Components\Product\Vistar\Route\Requestcontrol::class,
                            'method' => 'handleImport',
                            'queue_name' => 'shopify_metafield_import',
                            'own_weight' => 100,
                            'user_id' => $userId,
                            'data' => [
                                'operation' => 'make_request',
                                'user_id' => $userId,
                                'remote_shop_id' => $shopData['remote_shop_id'],
                                'app_code' => $appCode,
                                'individual_weight' => 1,
                                'feed_id' => $queuedTaskId,
                                'webhook_present' => true,
                                'temp_importing' => false,
                                'shop' => $shop,
                                'isImported' => false,
                                'process_code' => 'metafield',
                                'extra_data' => [
                                    'filterType' => 'metafieldsync',
                                    'updated_at' => (new DateTime($this->timeDelay))->format('Y-m-d\TH:i:s\Z')
                                ]
                            ],
                        ];
                        $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Vistar\Data::class)
                            ->pushToQueue($prepareData);
                        $this->di->getLog()->logContent('Metafield Process Started, Pushed Message =>' . json_encode($prepareData), 'info', $logFile);
                    } else {
                        $this->di->getLog()->logContent('Shop not found or inactive or store closed', 'info', $logFile);
                    }
                } else {
                    $this->di->getLog()->logContent('Unable to set user in Di UserId: '
                        . json_encode($userId, true), 'info', $logFile);
                }
            }
        }
    }

    private function setDiForUser($userId)
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
     * checkMetafieldProgressStuck function
     * Function to reinitiate metafield process if stuck from last 2 hour
     * @param [string] $userId
     * @param [array] $shop
     * @return bool
    */
    private function checkMetafieldProgressStuck($userId, $shop)
    {
        try {
            $logFile = "shopify/priorityMetafieldSync/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $queuedTaskContainer = $mongo->getCollection("queued_tasks");
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $queuedTaskData = $queuedTaskContainer->findOne([
                'user_id' => $userId,
                'shop_id' => $shop['_id'],
                'process_code' => 'metafield_import',
                'marketplace' => self::MARKETPLACE,
                'appTag' => $this->appTag,
            ], $options);
            if (isset($queuedTaskData['updated_at'])) {
                $updatedAtDate = \DateTime::createFromFormat('Y-m-d H:i:s', $queuedTaskData['updated_at']);
                $now = new \DateTime();
                if ($updatedAtDate instanceof \DateTime) {
                    $intervalInSeconds = $now->getTimestamp() - $updatedAtDate->getTimestamp();
                    if ($intervalInSeconds > 2 * 3600) {
                        $this->di->getLog()->logContent('Metafield Sync Stuck for user: ' . json_encode($queuedTaskData), 'info', $logFile);
                        $queuedTaskContainer->deleteOne([
                            '_id' => $queuedTaskData['_id']
                        ]);
                        return true;
                    }
                }
            } elseif(isset($queuedTaskData['created_at'])) {
                $createdAtDate = new \DateTime($queuedTaskData['created_at']);
                $now = new \DateTime();
                if ($createdAtDate instanceof \DateTime) {
                    $intervalInSeconds = $now->getTimestamp() - $createdAtDate->getTimestamp();
                    if ($intervalInSeconds > 2 * 3600) {
                        $this->di->getLog()->logContent('Metafield Sync Stuck for user: ' . json_encode($queuedTaskData), 'info', $logFile);
                        $queuedTaskContainer->deleteOne([
                            '_id' => $queuedTaskData['_id']
                        ]);
                        return true;
                    }
                }
            }
            return false;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from priority checkMetafieldProgressStuck(): ' . json_encode($e->getMessage()), 'info',  'exception.log');
        }
    }
}
