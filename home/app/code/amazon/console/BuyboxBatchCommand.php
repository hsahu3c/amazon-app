<?php
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
// use Symfony\Component\Console\Input\InputInterface;
// use Symfony\Component\Console\Output\OutputInterface;
use App\Amazon\Components\Buybox\Buybox;
// use App\Amazon\Components\Buybox\FeaturedOfferExpectedPriceBatch;
use App\Amazon\Components\Buybox\ListingOffersBatch;

class BuyboxBatchCommand extends Command
{
    protected static $defaultName = 'amazon:buybox:batch';
    protected static $defaultDescription = 'Initiate Amazon Buybox Status Batch Process';
    
    // protected $commandOptions = [
    //     'process_initiation' => 'Process initiation type (manual|automatic)',
    //     'help' => 'Show help information'
    // ];

    private \Phalcon\Di\FactoryDefault $di;

    /**
     * Constructor
     * Calls parent constructor and sets the di
     *
     * @param $di
     */
    public function __construct(\Phalcon\Di\FactoryDefault $di)
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
        $this->setHelp('Initiate Amazon Buybox Status Batch Process');
        $this->addOption("user_id", "u", InputOption::VALUE_REQUIRED, "user_id");
        $this->addOption("shop_id", "s", InputOption::VALUE_REQUIRED, "shop_id");
        $this->addOption("app_tag", "a", InputOption::VALUE_REQUIRED, "app_tag");
        $this->addOption("process_initiation", "p", InputOption::VALUE_REQUIRED, "process_initiation");
    }

    public function execute($input, $output)
    {
        $userId = $input->getOption("user_id") ?? false;
        $shopId = $input->getOption("shop_id") ?? false;
        $appTag = $input->getOption("app_tag") ?? 'amazon_sales_channel';
        $processInitiation = $input->getOption('process_initiation', 'manual');

        if (!$userId || !$shopId) {
            $output->writeln('<error>Error: user_id and shop_id are required</error>');
            $this->showHelp($output);
            return 1;
        }

        $output->writeln("Initiating Amazon Buybox Status Batch Process...");
        $output->writeln("User ID: {$userId}");
        $output->writeln("Shop ID: {$shopId}");
        $output->writeln("AppTag: {$appTag}");
        $output->writeln("Process Initiation: {$processInitiation}");
        $output->writeln("");

        try {
            $this->di->getObjectManager()->create(Buybox::class)->isBuyBoxFeatureAllowed($userId);

            // $batchService = $this->di->getObjectManager()->get(FeaturedOfferExpectedPriceBatch::class);
            $batchService = $this->di->getObjectManager()->get(ListingOffersBatch::class);
            $result = $batchService->initiateBatchProcess($userId, $shopId, [
                'process_initiation' => $processInitiation,
                'app_tag'            => $appTag
            ]);

            // $result = $batchService->initiateBatchProcess($userId, $shopId, [
            //     'process_initiation' => $processInitiation,
            //     'app_tag'            => $appTag,
            //     'skus' => ['wa1', 'sk3', 'sk1']
            // ]);

            if ($result['success']) {
                $output->writeln('<info>✓ Batch process initiated successfully!</info>');
                $output->writeln("Queued Task ID: {$result['queued_task_id']}");
                $output->writeln("Total Items: {$result['total_items']}");
                $output->writeln("Total Batches: {$result['total_batches']}");
                return 0;
            } else {
                $output->writeln('<error>✗ Failed to initiate batch process: ' . $result['message'] . '</error>');
                return 1;
            }

        } catch (\Exception $e) {
            $output->writeln('<error>✗ Exception occurred: ' . $e->getMessage() . '</error>');
            return 1;
        }
    }

    protected function showHelp($output)
    {
        $output->writeln('');
        $output->writeln('Usage:');
        $output->writeln('  php app/cli amazon:buybox:batch <user_id> <shop_id> [options]');
        $output->writeln('');
        $output->writeln('Arguments:');
        $output->writeln('  user_id                User ID (required)');
        $output->writeln('  shop_id                Shop ID (required)');
        $output->writeln('');
        $output->writeln('Options:');
        $output->writeln('  --process_initiation   Process initiation type (manual|automatic) [default: manual]');
        $output->writeln('  --help                 Show this help message');
        $output->writeln('');
        $output->writeln('Examples:');
        $output->writeln('  php app/cli amazon:buybox:batch 66fbeac2a7bf4b1c5e0fc7b3 270950');
        $output->writeln('  php app/cli amazon:buybox:batch 66fbeac2a7bf4b1c5e0fc7b3 270950 --process_initiation=automatic');
    }
}

