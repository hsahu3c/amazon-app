<?php

use App\Amazon\Components\Common\Helper;
use App\Core\Models\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use MongoDB\BSON\UTCDateTime;
use Exception;

class ProcessSubmittedCommand extends Command
{
    protected static $defaultName = "amazon:process-submitted-products";
    
    protected static $defaultDescription = "Process products with Submitted status older than 12 hours and update status via searchListings";
    
    protected $di;
    protected $output;
    protected $mongo;
    protected $logFile;
    
    const BATCH_SIZE = 20;
    const HOURS_GAP = 12;
    const QUEUE_CHUNK_THRESHOLD = 200;
    const QUEUE_CHUNK_SIZE = 200;
    const QUEUE_NAME_PROCESS_SUBMITTED = 'amazon_process_submitted_products';

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
        $this->logFile = "amazon/process-submitted-products/" . date('Y-m-d') . '.log';
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Process products with Submitted status that were submitted more than 12 hours ago and update their status based on Amazon searchListings API response');
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Process Submitted Products...</>');
        
        try {
            $this->processAllUsers();
            $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Process Completed</>');
            return 0;
        } catch (Exception $e) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error: ' . $e->getMessage() . '</>');
            $this->di->getLog()->logContent('Error in processSubmittedProducts: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'error', $this->logFile);
            return 1;
        }
    }

    /**
     * Process all users
     *
     * @return void
     */
    private function processAllUsers()
    {
        $userDetailsCollection = $this->mongo->getCollectionForTable('user_details');
        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ];
        
        // Fetch all users with Amazon shops
       
        $users = $userDetailsCollection->aggregate([
            [
                '$match' => [
                    // 'user_id' => '6794739e47a79007df0242a2',
                    'shops' => [
                        '$elemMatch' => [
                            'marketplace' => 'shopify',
                            'apps.app_status'=>"active",
                            'store_closed_at' => ['$exists' => false]
                        ]
                    ],
                    'shops.1' => ['$exists' => true], // Ensure there are at least 2 shops to have a source and target
                ]
            ],
        ], $options)->toArray();

        $this->output->writeln('<options=bold> ➤ </>Found ' . count($users) . ' users with active Amazon shops');

        foreach ($users as $userData) {
            $userId = $userData['user_id'] ?? null;
            $shop = $userData['shops'] ?? null;
            foreach ($shop as $s) {
                if (isset($s['marketplace']) && $s['marketplace'] === Helper::TARGET) {
                    $shop = $s;
                    break;
                }
            }
            
            if (!$userId || !$shop) {
                continue;
            }

            $this->output->writeln('<options=bold> ➤ </>Processing user: ' . $userId);
            $this->processUserProducts($userId, $shop);
        }
    }

    /**
     * Process products for a specific user
     *
     * @param string $userId
     * @param array $shop
     * @return void
     */
    private function processUserProducts($userId, $shop)
    {
        $targetShopId = $shop['_id'] ?? null;
        $remoteShopId = $shop['remote_shop_id'] ?? null;
        
        
        if (!$targetShopId || !$remoteShopId) {
            $this->output->writeln('<options=bold;fg=yellow> ➤ </>Skipping user ' . $userId . ' - missing shop ID or remote shop ID');
            return;
        }

        // Get source shop ID
        $sourceShopId = null;
        if (isset($shop['sources']) && is_array($shop['sources']) && !empty($shop['sources'])) {
            $sourceShopId = $shop['sources'][0]['shop_id'] ?? null;
        }

        if (!$sourceShopId) {
            $this->output->writeln('<options=bold;fg=yellow> ➤ </>Skipping user ' . $userId . ' - missing source shop ID');
            return;
        }

        // Calculate 12 hours ago timestamp (ISO 8601 format)
        $twelveHoursAgo = time() - (self::HOURS_GAP * 3600);
        $twelveHoursAgoIso = date('c', $twelveHoursAgo);

        $productHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\ProductHelper::class);
        $productCollection = $this->mongo->getCollectionForTable('refine_product');
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
        // First get only the count
        $countPipeline = $productHelper->buildSubmittedProductsPipeline($userId, $sourceShopId, $targetShopId, $twelveHoursAgoIso);
        $countPipeline[] = [ '$count' => 'total' ];
        $countResult = $productCollection->aggregate($countPipeline, $options)->toArray();
        $productCount = isset($countResult[0]['total']) ? (int) $countResult[0]['total'] : 0;

        if ($productCount === 0) {
            $this->output->writeln('<options=bold;fg=cyan> ➤ </>No products found for user ' . $userId);
            return;
        }

        $this->output->writeln('<options=bold> ➤ </>Found ' . $productCount . ' products to process for user ' . $userId);

        // If more than threshold, push to queue (one message per chunk); worker will fetch and process each chunk
        if ($productCount > self::QUEUE_CHUNK_THRESHOLD) {
            $numChunks = (int) ceil($productCount / self::QUEUE_CHUNK_SIZE);
            $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            for ($chunkIndex = 0; $chunkIndex < $numChunks; $chunkIndex++) {
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => \App\Amazon\Components\ProductHelper::class,
                    'method' => 'processChunkFromQueue',
                    'queue_name' => self::QUEUE_NAME_PROCESS_SUBMITTED,
                    'user_id' => $userId,
                    'shop' => $shop,
                    'chunk_index' => $chunkIndex,
                    // 'command_class' => '\\ProcessSubmittedCommand',
                ];
                $sqsHelper->pushMessage($handlerData);
            }
            $this->output->writeln('<options=bold;fg=green> ➤ </>Pushed ' . $numChunks . ' chunk(s) to queue for user ' . $userId . ' (' . $productCount . ' products)');
            return;
        }

        // Count <= 100: fetch products and process inline (no SQS)
        $pipeline = $productHelper->buildSubmittedProductsPipeline($userId, $sourceShopId, $targetShopId, $twelveHoursAgoIso);
        $products = $productCollection->aggregate($pipeline, $options)->toArray();

        $productBatches = array_chunk($products, self::BATCH_SIZE);
        foreach ($productBatches as $batchIndex => $batch) {
            $this->output->writeln('<options=bold> ➤ </>Processing batch ' . ($batchIndex + 1) . ' of ' . count($productBatches) . ' (user: ' . $userId . ')');
            $productHelper->processBatch($batch, $userId, $targetShopId, $remoteShopId, $shop, $this->output);
        }
    }

    /**
     * Process one chunk from queue: delegates to ProductHelper::processChunkFromQueue.
     * Kept for backwards compatibility when queue message has class_name => ProcessSubmittedCommand.
     *
     * @param array $messageArray Full queue message (user_id, shop, chunk_index)
     * @return bool
     */
    // public function processChunkFromQueue(array $messageArray)
    // {
    //     $productHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\ProductHelper::class);
    //     return $productHelper->processChunkFromQueue($messageArray);
    // }

   
  
    // private function determineProductStatus($amazonStatus, $attributes = [])
    // {
    //     // Check if is_off_amazon_item attribute exists and is true
    //     if (isset($attributes['is_off_amazon_item']) && is_array($attributes['is_off_amazon_item'])) {
    //         foreach ($attributes['is_off_amazon_item'] as $attrItem) {
    //             if (isset($attrItem['value'])) {
    //                 $value = $attrItem['value'];
    //                 // Check if value is true (can be 1, '1', true, 'true')
    //                 if ($value === 1 || $value === '1' || $value === true || strtolower((string)$value) === 'true') {
    //                     return ProductHelper::PRODUCT_STATUS_LISTED;
    //                 }
    //             }
    //         }
    //     }
        
    //     // If is_off_amazon_item is not true, return Not Listed
    //     return ProductHelper::PRODUCT_STATUS_NOT_LISTED;
    // }
}

