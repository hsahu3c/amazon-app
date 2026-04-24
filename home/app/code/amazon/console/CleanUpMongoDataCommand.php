<?php

use Symfony\Component\Console\Command\Command;
use Exception;

class CleanUpMongoDataCommand extends Command
{

    protected static $defaultName = "cleanup-mongo-data";
    protected $di;
    protected static $defaultDescription = "Clean up old data from MongoDB collections based on specified thresholds";

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
        $this->setHelp('Clean up old data from MongoDB collections based on specified thresholds');
    }

    /**
     * Executes the cleanup operation for MongoDB collections.
     *
     * This function identifies and removes documents from specified MongoDB collections
     * based on their respective date thresholds. The cleanup process is performed in batches
     * to handle large datasets efficiently and avoid memory issues.
     *
     * @param InputInterface $input  The input interface for the console command.
     * @param OutputInterface $output The output interface for the console command.
     *
     * @return bool
     */
    protected function execute($input, $output): int
    {

        $output->writeln("<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan>Initiating Mongo Data Clean Up Process...</>");
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collectionQueryMap = [
            'feed_container' => [
                'feed_created_date' => ['$lt' => date('Y-m-d', strtotime('-30 days'))]
            ],
            'action_container' => [
                'created_at' => ['$lt' => date('Y-m-d', strtotime('-6 months'))]
            ],
            'amazon_order_ids' => [
                'created_at' => ['$lt' => date('Y-m-d', strtotime('-3 months'))]
            ],
            'csv_export_product_container' => [
                'created_at' => ['$lt' => date('Y-m-d', strtotime('-3 days'))]
            ],
            'product_type_definitions_schema' => [
                'product_type_version.latest' => false,
            ],
        ];
        try {
            foreach ($collectionQueryMap as $collectionName => $filter) {
                $output->writeln("<options=bold;bg=black> ➤ </><options=bold;fg=green>Deleting data from collection: $collectionName</>");
                $collection = $mongo->getCollection($collectionName);
                $result = $collection->deleteMany($filter);
                $deletedCount = $result->getDeletedCount();
                $output->writeln("<options=bold;fg=green>Collection $collectionName completed. Total deleted: $deletedCount</>");
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception: CleanUpMongoDataCommand, Error: ' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }

        $output->writeln("<options=bold;bg=black> ➤ </><options=bold;fg=green>Data deletion completed!!</>");

        return 1;
    }
}
