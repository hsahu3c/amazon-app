<?php

use App\Shopifyhome\Components\Product\BatchImport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[\AllowDynamicProperties]
class BatchProductImportCommand extends Command
{

    protected static $defaultName = "product:batch-import";

    protected static $defaultDescription = "Initiate batch import for products from Shopify";

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
        $this->setHelp('Initiate batch import for products from Shopify');
        $this->addOption("app_tag", "t", InputOption::VALUE_REQUIRED, "appTag");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Initiating Shopify Batch Product Import...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $appTag = $input->getOption("app_tag") ?? false;
        if (!$appTag) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>Please provide appTag</>');
            return 0;
        }

        $this->di->getAppCode()->setAppTag($appTag);
        $appCodes = $this->di->getConfig()->app_tags[$appTag]['app_code'];
        $appCodesArray = $appCodes->toArray();
        $this->di->getAppCode()->set($appCodesArray);

        $shopifyBatchImport = $this->di->getObjectManager()->get(BatchImport::class);

        $usersAnsShopsToProcessForListingsAdd = $shopifyBatchImport->getUsersForListingsAddBatchImport();
        if (empty($usersAnsShopsToProcessForListingsAdd)) {
            $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>No users to process</>');
            //add log
            return 0;
        }

        $skipUsers = $this->getInProcessingUsers();

        // Extract user_ids from the all pending users
        $allUserIds = array_column($usersAnsShopsToProcessForListingsAdd, 'user_id');

        // Determine eligible users
        $eligibleUserIds = array_diff($allUserIds, $skipUsers);

        if (empty($eligibleUserIds)) {
            $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>No users to process</>');
            return 0;
        }

        $eligibleUserIds = array_values($eligibleUserIds);

        $eligibleUserRecords = array_filter($usersAnsShopsToProcessForListingsAdd, function ($userAndShop) use ($eligibleUserIds) {
            return in_array($userAndShop['user_id'], $eligibleUserIds);
        });

        $total = count($eligibleUserRecords);

        $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Total docs: ' . $total . '</>');

        foreach ($eligibleUserRecords as $userAndShop) {
            $response = $shopifyBatchImport->pushToBatchImportQueue($userAndShop);
            if ($response['success']) {
                $output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> Pushed data in queue for {$userAndShop['user_id']} </>");
            } else {
                $output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> Failed to push data in queue for {$userAndShop['user_id']}. Error - {$response['message']} </>");
            }
        }

        return 0;
    }

    private function getInProcessingUsers()
    {
        $batchImportProductCollection = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollectionForTable('batch_import_product_queue');
        $queuedTaskCollection = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollectionForTable('queued_tasks');
        $processingUserFromBatchImportProductCollection = $batchImportProductCollection->find([], ['projection' => ['user_id' => 1,'_id' => 0]])->toArray();
        $processingUserFromQueuedTaskCollection = $queuedTaskCollection->find(['process_code' => 'saleschannel_product_import'], ['projection' => ['user_id' => 1,'_id' => 0]])->toArray();
        $processingUsers = array_merge($processingUserFromBatchImportProductCollection, $processingUserFromQueuedTaskCollection);
        $processingUsers = array_column($processingUsers, 'user_id');
        return $processingUsers;
    }
}