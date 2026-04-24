<?php

use MongoDB\BSON\UTCDateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Shopifyhome\Components\ShopifyOrdersUpdater;

#[\AllowDynamicProperties]
class ShopifyOrdersUpdateCommand extends Command
{
    // php app/cli shopify:update-orders -s <shopify-shop-url> -t <shopify-access-token> -r <{since_id} shopify-order-id>
    protected static $defaultName = "shopify:update-orders";
    // protected $di;
    protected static $defaultDescription = "Update Shopify orders by clearing phone numbers using GraphQL API";
    
    private const BASE_MONGO_CLASS = '\App\Core\Models\BaseMongo';

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
        $this->setHelp('Update Shopify orders by clearing phone numbers in shipping and billing addresses using GraphQL API');
        
        $this->addOption(
            'shop-domain',
            's',
            InputOption::VALUE_REQUIRED,
            'Shopify shop domain (e.g., your-shop.myshopify.com)'
        );
        
        $this->addOption(
            'access-token',
            't',
            InputOption::VALUE_REQUIRED,
            'Shopify access token'
        );
        
        $this->addOption(
            'api-version',
            'a',
            InputOption::VALUE_REQUIRED,
            'Shopify API version',
            '2025-01'
        );

        $this->addOption(
            'table-name',
            'c',
            InputOption::VALUE_REQUIRED,
            'MongoDB Collection Name',
            'processed_orders'
        );
        
        $this->addOption(
            'start-id',
            'r',
            InputOption::VALUE_REQUIRED,
            'Start order id, will be used when no data is saved in database'
        );
        
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Run without making actual updates (test mode)'
        );
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
        
        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Starting Shopify Orders Update Process...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        try {
            // Get configuration from command options
            $config = $this->getConfiguration($input);
            
            // Display configuration
            $this->displayConfiguration($config, $output);
            
            // Initialize the Shopify Orders Updater
            $updater = $this->initializeUpdater($config);
            
            if ($input->getOption('dry-run')) {
                $output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> DRY RUN MODE - No actual updates will be made</>');
                $this->performDryRun($updater, $output);
            } else {
                // Process all orders
                $this->processOrders($updater, $output);
            }
            
            $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Process completed successfully!</>');
            return 0;
            
        } catch (Exception $e) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error: ' . $e->getMessage() . '</>');
            $this->di->getLog()->logContent('Shopify Orders Update Error: ' . $e->getMessage(), 'error', 'shopify_orders_update.log');
            return 1;
        }
    }

    /**
     * Get configuration from command input
     *
     * @param InputInterface $input
     * @return array
     */
    private function getConfiguration(InputInterface $input): array
    {
        return [
            'shopify' => [
                'shop_domain' => $input->getOption('shop-domain'),
                'access_token' => $input->getOption('access-token'),
                'api_version' => $input->getOption('api-version')
            ],
            'mongodb' => [
                // 'connection_string' => $input->getOption('mongo-connection'),
                // 'database_name' => $input->getOption('database-name')
                'table_name' => $input->getOption('table-name')
            ],
            'start_id' => $input->getOption('start-id'),
            'dry_run' => $input->getOption('dry-run')
        ];
    }

    /**
     * Display configuration to user
     *
     * @param array $config
     * @param OutputInterface $output
     */
    private function displayConfiguration(array $config, OutputInterface $output): void
    {
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Configuration:</>');
        $output->writeln("   Shop Domain: {$config['shopify']['shop_domain']}");
        $output->writeln("   API Version: {$config['shopify']['api_version']}");
        $output->writeln("   Table Database: {$config['mongodb']['table_name']}");
        // $output->writeln("   Resume Mode: " . ($config['resume'] ? 'Yes' : 'No'));
        $output->writeln("   Start Order Id: " . ($config['start_id'] ? 'Yes' : 'No'));
        $output->writeln("   Dry Run: " . ($config['dry_run'] ? 'Yes' : 'No'));
        $output->writeln('');
    }

    /**
     * Initialize the Shopify Orders Updater
     *
     * @param array $config
     * @return ShopifyOrdersUpdater
     * @throws Exception
     */
    private function initializeUpdater(array $config): ShopifyOrdersUpdater
    {
        // Include the ShopifyOrdersUpdater class
        // if (!class_exists('ShopifyOrdersUpdater')) {
        //     require_once __DIR__ . '/../../../shopify_orders_updater.php';
        // }
        
        // return (new ShopifyOrdersUpdater())->init(
        //     $config['shopify']['shop_domain'],
        //     $config['shopify']['access_token'],
        //     // $config['mongodb']['connection_string'],
        //     // $config['mongodb']['database_name'],
        //     $config['mongodb']['table_name'],
        //     $config['shopify']['api_version']
        // );

        if(empty($config['shopify']['shop_domain']) || empty($config['shopify']['access_token'])) {
            die('shop_domain and access_token is required.');
        }

        return $this->di->getObjectManager()->create('\App\Shopifyhome\Components\ShopifyOrdersUpdater')->init(
            $config['shopify']['shop_domain'],
            $config['shopify']['access_token'],
            // $config['mongodb']['connection_string'],
            // $config['mongodb']['database_name'],
            $config['mongodb']['table_name'],
            $config['shopify']['api_version']
        );
    }

    /**
     * Perform dry run to test the process
     *
     * @param ShopifyOrdersUpdater $updater
     * @param OutputInterface $output
     */
    private function performDryRun(ShopifyOrdersUpdater $updater, OutputInterface $output): void
    {
        $output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Fetching orders for dry run...</>');
        
        try {
            // Get processing statistics
            $stats = $updater->getProcessingStats();
            $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Current Processing Stats:</>');
            $output->writeln("   Total Processed: {$stats['total_processed']}");
            $output->writeln("   Successful: {$stats['success_count']}");
            $output->writeln("   Failed: {$stats['failed_count']}");
            $output->writeln("   Errors: {$stats['error_count']}");
            
            // Fetch a sample of orders (first page only)
            $orders = $updater->fetchAllOrders($this->input->getOption('start-id'));
            $sampleSize = min(10, count($orders));
            
            $output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Sample of {$sampleSize} orders to be processed:</>");
            
            for ($i = 0; $i < $sampleSize; $i++) {
                $order = $orders[$i];
                $output->writeln("   Order ID: {$order['id']}, Name: {$order['name']}, Created: {$order['created_at']}");
            }
            
            if (count($orders) > $sampleSize) {
                $output->writeln("   ... and " . (count($orders) - $sampleSize) . " more orders");
            }
            
        } catch (Exception $e) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Dry run error: ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Process orders using the updater
     *
     * @param ShopifyOrdersUpdater $updater
     * @param OutputInterface $output
     */
    private function processOrders(ShopifyOrdersUpdater $updater, OutputInterface $output): void
    {
        $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Starting order processing...</>');
        
        // Log the start of processing
        $this->di->getLog()->logContent('Shopify Orders Update started', 'info', 'shopify_orders_update.log');
        
        // Process all orders
        $updater->processAllOrders($this->input->getOption('start-id'));

        // $updater->processManually();
        
        // Log the completion
        $this->di->getLog()->logContent('Shopify Orders Update completed', 'info', 'shopify_orders_update.log');
    }

    /**
     * Get processing statistics from MongoDB
     *
     * @return array
     */
    public function getProcessingStats(): array
    {
        try {
            $mongo = $this->di->getObjectManager()->create(self::BASE_MONGO_CLASS);
            $collection = $mongo->getCollectionForTable('processed_orders');
            
            $totalProcessed = $collection->countDocuments([]);
            $successCount = $collection->countDocuments(['status' => 'success']);
            $failedCount = $collection->countDocuments(['status' => 'failed']);
            $errorCount = $collection->countDocuments(['status' => 'error']);
            
            return [
                'total_processed' => $totalProcessed,
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'error_count' => $errorCount
            ];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error getting processing stats: ' . $e->getMessage(), 'error', 'shopify_orders_update.log');
            return [
                'total_processed' => 0,
                'success_count' => 0,
                'failed_count' => 0,
                'error_count' => 0
            ];
        }
    }

    /**
     * Check if operation can be executed (prevent duplicate runs)
     *
     * @param string $processName
     * @return bool
     */
    // public function canExecuteOperation(string $processName = 'shopify_orders_update'): bool
    // {
    //     try {
    //         $mongo = $this->di->getObjectManager()->create(self::BASE_MONGO_CLASS);
    //         $cronCollection = $mongo->getCollectionForTable('shopify_cron_tasks');
            
    //         $query = [
    //             'process_name' => $processName,
    //             'status' => 'running'
    //         ];
            
    //         $result = $cronCollection->find($query, ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();
            
    //         return empty($result);
    //     } catch (Exception $e) {
    //         $this->di->getLog()->logContent('Error checking operation status: ' . $e->getMessage(), 'error', 'shopify_orders_update.log');
    //         return true; // Allow execution if check fails
    //     }
    // }

    /**
     * Mark operation as started
     *
     * @param string $processName
     * @return bool
     */
    // public function startOperation(string $processName = 'shopify_orders_update'): bool
    // {
    //     try {
    //         $mongo = $this->di->getObjectManager()->create(self::BASE_MONGO_CLASS);
    //         $cronCollection = $mongo->getCollectionForTable('shopify_cron_tasks');
            
    //         $cronCollection->insertOne([
    //             'process_name' => $processName,
    //             'status' => 'running',
    //             'started_at' => new UTCDateTime(strtotime(date('c')) * 1000),
    //             'created_at' => new UTCDateTime(strtotime(date('c')) * 1000)
    //         ]);
            
    //         return true;
    //     } catch (Exception $e) {
    //         $this->di->getLog()->logContent('Error starting operation: ' . $e->getMessage(), 'error', 'shopify_orders_update.log');
    //         return false;
    //     }
    // }

    /**
     * Mark operation as completed
     *
     * @param string $processName
     * @param array $stats
     * @return bool
     */
    // public function endOperation(string $processName = 'shopify_orders_update', array $stats = []): bool
    // {
    //     try {
    //         $mongo = $this->di->getObjectManager()->create(self::BASE_MONGO_CLASS);
    //         $cronCollection = $mongo->getCollectionForTable('shopify_cron_tasks');
            
    //         $updateData = [
    //             'status' => 'completed',
    //             'completed_at' => new UTCDateTime(strtotime(date('c')) * 1000),
    //             'stats' => $stats
    //         ];
            
    //         $cronCollection->updateOne(
    //             ['process_name' => $processName, 'status' => 'running'],
    //             ['$set' => $updateData]
    //         );
            
    //         return true;
    //     } catch (Exception $e) {
    //         $this->di->getLog()->logContent('Error ending operation: ' . $e->getMessage(), 'error', 'shopify_orders_update.log');
    //         return false;
    //     }
    // }
}
