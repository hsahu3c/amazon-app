<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Connector\Models\QueuedTasks;
use App\Core\Models\User;
use Exception;

class FailedOrderReportCommand extends Command
{
    const PROCESS_CODE = 'failed_order_report';
    const QUEUE_NAME   = 'failed_order_report';

    protected static $defaultName = "amazon-failed-order-report";
    protected $di;
    protected static $defaultDescription = "Generate per-user CSV report of failed Amazon orders from the previous week";
    protected OutputInterface $output;

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
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Generate Failed Order Report CSV per user for the previous week');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute($input, $output): int
    {
        $this->output = $output;
        $this->output->writeln("Executing Failed Order Report for all users");
        $this->fetchUsers();
        return 0;
    }

    /**
     * Fetch all eligible users from user_details.
     * For each eligible amazon shop: count orders, create CSV, register a QueuedTask,
     * then push the first SQS chunk message to Helper::fetchOrderData().
     *
     * @return void
     */
    public function fetchUsers()
    {
        try {
            $mongo      = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('user_details');

            $options = [
                'typeMap'    => ['root' => 'array', 'document' => 'array'],
                'projection' => [
                    'user_id'  => 1,
                    'username' => 1,
                    'email'    => 1,
                    'shops'    => 1,
                ],
            ];

            $filter = [
                'user_id' => ['$in' => ['68a55b825add0444980d6ba2']],
                // 'plan_tier' => ['$exists' => true],
                'shops.1' => ['$exists' => true],
            ];

            $userDetails = $collection->find($filter, $options)->toArray();

            // Compute date range once — cron fires every Monday
            // $gt : 7 days ago (previous Monday);  $lt : today (current Monday)
            $prevMondayStr  = date('Y-m-d', strtotime('-7 days'));
            $currentDateStr = date('Y-m-d');

            foreach ($userDetails as $user) {
                $userId   = $user['user_id'] ?? '';
                $username = $user['username'] ?? '';

                if (empty($userId)) {
                    continue;
                }

                // Set DI context for this user so appTag / config resolves correctly
                $diResponse = $this->setDiForUser($userId);
                if (!isset($diResponse['success']) || !$diResponse['success']) {
                    continue;
                }

                // setDiForUser() only sets the user; it never calls setAppTag(), so
                // getAppTag() returns "default" without this explicit initialization.
                $amazonAppTag = \App\Amazon\Components\Common\Helper::AMAZON_APP_TAG;
                $this->di->getAppCode()->setAppTag($amazonAppTag);
                $amazonAppCode = $this->di->getConfig()->app_tags[$amazonAppTag]['app_code'];
                $this->di->getAppCode()->set($amazonAppCode->toArray());

                $shops  = $user['shops'] ?? [];
                $sourceShopId = '';

                foreach ($shops as $shop) {
                    if (isset($shop['marketplace']) && $shop['marketplace'] === 'shopify') {
                        // Capture the shopify source shop id
                        $sourceShopId = (string)($shop['_id'] ?? '');
                    } else {
                        // Skip if warehouse is inactive or sourceShopId is not yet set
                        if (!empty($shop['warehouses']) && $shop['warehouses'][0]['status'] == 'inactive' || empty($sourceShopId)) {
                            continue;
                        }

                        $marketplaceShopId = (string)($shop['_id'] ?? '');
                        if (empty($marketplaceShopId)) {
                            continue;
                        }

                        $orderCollection = $mongo->getCollectionForTable('order_container');

                        $baseMatch = [
                            'user_id'             => (string)$userId,
                            'object_type'         => 'source_order',
                            'marketplace_shop_id' => $marketplaceShopId,
                            'created_at'          => [
                                '$gte' => $prevMondayStr,
                                '$lt' => $currentDateStr,
                            ],
                            'targets.errors' => ['$exists' => true],
                        ];

                        // 1. Count total matching orders — skip if none
                        $totalCount = $orderCollection->countDocuments($baseMatch);
                        if ($totalCount === 0) {
                            continue;
                        }
                        // 2. Check for an existing queued task (mirrors ProductLinkingViaCsv::checkExistingQueuedTask)
                        $queueTasksCollection = $mongo->getCollection('queued_tasks');
                        $existingTask = $queueTasksCollection->findOne([
                            'user_id'      => $userId,
                            'shop_id'      => $marketplaceShopId,
                            'process_code' => self::PROCESS_CODE,
                        ]);
                        if (!empty($existingTask)) {
                            continue;
                        }

                        // 3. Create per-user/per-shop CSV file with headers
                        //    Path includes both userId and marketplaceShopId so multiple marketplaces
                        //    for the same user don't overwrite each other.
                        $csvFilePath = $this->createCsvFile($userId, $marketplaceShopId);
                        if (empty($csvFilePath)) {
                            continue;
                        }

                        // 4. Prepare initial SQS payload — SQS worker is Helper::fetchOrderData()
                        $sqsData = [
                            'type'                => 'full_class',
                            'class_name'          => '\App\Amazon\Components\Order\Helper',
                            'method'              => 'fetchOrderData',
                            'queue_name'          => self::QUEUE_NAME,
                            'user_id'             => $userId,
                            'marketplace_shop_id' => $marketplaceShopId,
                            'csv_file_path'       => $csvFilePath,
                            'total_count'         => $totalCount,
                            'exported_count'      => 0,
                            'prev_monday_str'     => $prevMondayStr,
                            'current_date_str'    => $currentDateStr,
                            'appTag'              => $this->di->getAppCode()->getAppTag(),
                            'message'             => 'Failed Order Report generation started',
                            // Required by QueuedTasks::setQueuedTask()
                            'marketplace'         => 'amazon',
                            'process_code'        => self::PROCESS_CODE,
                            // no last_traversed_object_id → first batch
                        ];

                        // 5. Register QueuedTask — provides feed_id for progress tracking
                        $queuedTask = new QueuedTasks();
                        $feedId     = $queuedTask->setQueuedTask($marketplaceShopId, $sqsData);
                        if (!$feedId) {
                            continue;
                        }
                        $sqsData['feed_id'] = $feedId;
                        $this->di->getLog()->logContent('sqsData: ' . json_encode($sqsData), 'info', 'FailedOrderReport.log');

                        // 5. Push first SQS message — Helper::fetchOrderData() takes it from here
                        $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($sqsData);
                    }
                }
            }

            $this->output->writeln("Failed Order Report jobs queued.");
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                'Exception FailedOrderReportCommand::fetchUsers(): ' . print_r($e->getMessage(), true),
                'info',
                'exception.log'
            );
        }
    }

    /**
     * Sets the DI user context for the given userId — mirrors AmazonGMVDataCommand::setDiForUser().
     * Required so that getAppCode()->getAppTag() and other user-scoped services resolve correctly.
     *
     * @param string $userId
     *
     * @return array  ['success' => bool, 'message' => string]
     */
    public function setDiForUser($userId)
    {
        try {
            $getUser = User::findFirst([['_id' => $userId]]);
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (empty($getUser)) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        $getUser->id = (string)$getUser->_id;
        $this->di->setUser($getUser);

        if ($this->di->getUser()->getConfig()['username'] === 'admin') {
            return ['success' => false, 'message' => 'user not found in DB. Fetched di of admin.'];
        }

        return ['success' => true, 'message' => 'user set in di successfully'];
    }

    /**
     * Create the per-user/per-shop CSV file with header row.
     * Path includes both userId and marketplaceShopId so that multiple amazon
     * marketplaces for the same user each get their own file.
     *
     * @param string $userId
     * @param string $marketplaceShopId
     *
     * @return string|null  Full file path on success, null on failure
     */
    private function createCsvFile($userId, $marketplaceShopId)
    {
        try {
            $date     = date('Y-m-d');
            $filePath = BP . DS . "var/file/$userId/$marketplaceShopId/failed_orders_$date.csv";

            // Ensure directory exists
            $dirName = dirname($filePath);
            if (!is_dir($dirName)) {
                mkdir($dirName, 0777, true);
            }

            // Write header row
            $handle = fopen($filePath, 'w');
            if ($handle === false) {
                return null;
            }
            fputcsv($handle, [
                'Order ID',             // marketplace_reference_id
                'Created on Amazon',    // source_created_at
                'Status',               // marketplace_status
                'Sync Status',          // status
                'Failed order reason',  // targets.errors
            ]);
            fclose($handle);

            return $filePath;
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                'Exception FailedOrderReportCommand::createCsvFile(): ' . print_r($e->getMessage(), true),
                'info',
                'exception.log'
            );
            return null;
        }
    }
}