<?php

use App\Core\Models\User;
use App\Core\Models\Config\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Amazon\Components\Common\Helper;
use App\Connector\Components\Order\Order as ConnectorOrder;

class BatchSyncOfferListingCommand extends Command
{

    protected static $defaultName = "batch-offer-sync";
    protected $di;
    protected $output;
    protected $input;
    protected static $defaultDescription = "To Bulk sync offer to Amazon";

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
        $this->setHelp('Sync offer product to target.');
        $this->addOption("method", "m", InputOption::VALUE_REQUIRED, "Method to use for syncing");
        // $this->addOption("lookup", "l", InputOption::VALUE_REQUIRED, "Look up product on Amazon");
        // $this->addOption("upload", "u", InputOption::VALUE_REQUIRED, "Upload product to Amazon");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute($input, $output)
    {
        $this->di->getAppCode()->setAppTag('amazon_sales_channel');
        $this->output = $output;
        $this->input = $input;
        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Initiating Bulk Offer Sync...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $method = $input->getOption("method") ?? '';

        if ($method == 'sync') {
            $this->di->getLog()->logContent('Running Sync Process','info', 'batchOfferSync.log');
            $output->writeln('<options=bold;fg=green>Running Sync Process</>');
            $this->initiateProcessSync([], $output);
        } elseif ($method == 'upload') {
            $this->di->getLog()->logContent('Running the Upload','info', 'batchOfferSync.log');
            $output->writeln('<options=bold;fg=green>Running Upload Process</>');
            $this->initiateuploadProcess([], $output);
        } else {
            $output->writeln('<options=bold;fg=red>Invalid method specified. Use "sync" or "upload".</>');
            return 1;
        }
        return 0;
    }

    public function initiateProcessSync($data, $output)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $config_col = $mongo->getCollectionForTable('config');
        $batch_offer_listing = $mongo->getCollectionForTable('batch_offer_listing');

        $data = $config_col->find([
            'group_code' => 'product',
            'target' => 'amazon',
            'key' => 'auto_offer_listing_creation',
            // 'user_id' => "68058af274e396dc230f22d3"
        ])->toArray();

        if (empty($data)) {
            $this->di->getLog()->logContent('ERROR: No configuration found for auto listing creation','info', 'batchOfferSync.log');
            $this->output->writeln('<options=bold;fg=red>ERROR: No configuration found for auto listing creation</>');
            return 0;
        }

        $logFile = '';

        foreach ($data as $config) {
            $logFile = 'batchOfferSync/' . $config['user_id'] . '/batchOfferSync.log';
            $configData = $config['value'];
            if (isset($configData['enabled']) && $configData['enabled'] == true) {
                $this->di->getLog()->logContent('Auto Listing Creation is enabled','info', $logFile);
                $output->writeln('<options=bold;fg=green> ' . $config['user_id'] . ' Auto Listing Creation is enabled</>');
                $this->setDiForUser($config['user_id']);

                $all_product_ids = $batch_offer_listing->aggregate([
                    [
                        '$match' => [
                            'user_id' => $config['user_id'],
                            'shop_id' => $config['source_shop_id'],
                            'status' => null
                        ]
                    ],
                    [
                        '$group' => [
                            '_id' => '$source_product_id',
                            'source_product_id' => ['$first' => '$source_product_id']
                        ]
                    ],
                    [
                        '$limit' => 500
                    ]
                ])->toArray();

                if (empty($all_product_ids)) {
                    $this->di->getLog()->logContent('No product Found for the users','info', $logFile);
                    $output->writeln('<options=bold;fg=red>No products found for the user</>');
                    continue;
                }

                $containerModel = new \App\Connector\Models\ProductContainer();
                $queuedTaskModel = new \App\Connector\Models\QueuedTasks;
                if ($queuedTaskModel->checkQueuedTaskwithProcessTag("product_import")) {
                    $this->di->getLog()->logContent('Product import is in progress, please wait for it to finish','info', $logFile);
                    $output->writeln('<options=bold;fg=red>Product import is in progress, please wait for it to finish</>');
                    continue;
                }

                $productIds = [];
                $all_product_ids = json_decode(json_encode($all_product_ids), true);
                // echo json_encode($all_product_ids);die;
                if (empty($all_product_ids)) {
                    $this->di->getLog()->logContent('No product Found for the users','info', $logFile);
                    $output->writeln('<options=bold;fg=red>No products found for the user</>');
                    continue;
                }

                foreach ($all_product_ids as $product) {
                    if (isset($product['source_product_id']) && !empty($product['source_product_id'])) {
                        // $product['source_product_id'] = ['id1', 'id2' ...];
                        if (is_array($product['source_product_id'])) {
                            foreach ($product['source_product_id'] as $id) {
                                if (!empty($id)) {
                                    $productIds[] = $id;
                                }
                            }
                        } else {
                            $productIds[] = $product['source_product_id'];
                        }
                    }
                }

                if (empty($productIds)) {
                    $this->di->getLog()->logContent('No product IDs found for upload','info', $logFile);
                    $output->writeln('<options=bold;fg=red>No product IDs found for upload</>');
                    continue;
                }

                $productIds = array_values(array_unique($productIds));

                $importData = [
                    "target" => [
                        "marketplace" => "amazon",
                        "shopId" => $config['target_shop_id']
                    ],
                    "source" => [
                        "marketplace" => "shopify",
                        "shopId" => $config['source_shop_id']
                    ],
                    "activePage" => 1,
                    "source_product_ids" => $productIds,
                    "useRefinProduct" => true
                ];

                $this->di->getLog()->logContent('data: ' . json_encode($importData),'info', $logFile);

                $code = $importData['target']['marketplace'];
                $importData['code'] = $code;
                $responseData = $containerModel->getLookup($importData);

                if ( !isset($responseData['success']) || !$responseData['success']) {
                    $this->di->getLog()->logContent('ERROR: ' . $responseData['message'], 'error', $logFile);
                    $output->writeln('<options=bold;fg=red>ERROR: ' . $responseData['message'] . '</>');
                    continue;
                }
                $this->di->getLog()->logContent('Lookup Process completed successfully','info', $logFile);
                $batch_offer_listing->updateMany(
                    ['user_id' => $config['user_id'], 'shop_id' => $config['source_shop_id'], 'source_product_id' => ['$in' => $productIds]],
                    ['$set' => ['status' => 'ready', 'updated_at' => date('c')]]
                );
                $output->writeln('<options=bold;fg=green>Success: ' . $responseData['message'] . '</>');

            } else {
                $this->di->getLog()->logContent('Auto Listing Creation is disabled','info', $logFile);
                $output->writeln('<options=bold;fg=red>Auto Listing Creation is disabled</>');
            }
        }

        return 0;
    }

    public function initiateuploadProcess($data, $output)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $config_col = $mongo->getCollectionForTable('config');
        $batch_offer_listing = $mongo->getCollectionForTable('batch_offer_listing');
        $product_container = $mongo->getCollectionForTable('product_container');

        $data = $config_col->find([
            'group_code' => 'product',
            'target' => 'amazon',
            'key' => 'auto_offer_listing_creation',
            // 'user_id' => "681e0703ff475e0b2301c5f2"
        ])->toArray();

        if (empty($data)) {
            $this->di->getLog()->logContent('ERROR: No configuration found for auto listing creation','info', 'batchOfferupload.log');
            $this->output->writeln('<options=bold;fg=red>ERROR: No configuration found for auto listing creation</>');
            return 0;
        }

        foreach ($data as $config) {
            $configData = $config['value'];
            $logFile = 'batchOfferSync/' . $config['user_id'] . '/batchOfferupload.log';
            $this->di->getLog()->logContent('-------------------------------------------','info', $logFile);
            if (isset($configData['enabled']) && $configData['enabled'] == true) {
                $this->di->getLog()->logContent('Auto Listing Creation is enabled','info', $logFile);
                $output->writeln('<options=bold;fg=green>Auto Listing Creation is enabled</>');
                $this->setDiForUser($config['user_id']);

                $all_product_ids = $batch_offer_listing->find([
                    'user_id' => $config['user_id'],
                    'shop_id' => $config['source_shop_id'],
                    'status' => 'ready',
                    // Only updated BEFORE the last 2 hours (using ISODate string)
                    // 'updated_at' => ['$lt' => gmdate('Y-m-d\TH:i:s\Z', time() - 2 * 60 * 60)]
                ])->toArray();
                if (empty($all_product_ids)) {
                    $this->di->getLog()->logContent('No product Found for the users','info', $logFile);
                    $output->writeln('<options=bold;fg=red>No products found for the user</>');
                    continue;
                }

                $all_product_ids = json_decode(json_encode($all_product_ids), true);
                $productIds = [];
                foreach ($all_product_ids as $product) {
                    if (isset($product['source_product_id']) && !empty($product['source_product_id'])) {
                        // $product['source_product_id'] = ['id1', 'id2' ...];
                        if (is_array($product['source_product_id'])) {
                            foreach ($product['source_product_id'] as $id) {
                                if (!empty($id)) {
                                    $productIds[] = $id;
                                }
                            }
                            // $productIds = array_merge($productIds, $product['source_product_id']);
                        } else {
                            $productIds[] = $product['source_product_id'];
                        }
                    }
                }


                if (empty($productIds)) {
                    $this->di->getLog()->logContent('No product IDs found for upload','info', $logFile);
                    $output->writeln('<options=bold;fg=red>No product IDs found for upload</>');
                    continue;
                }

                $productIds = array_values(array_unique($productIds));

                $product_container_data = $product_container->aggregate([
                    [
                        '$match' => [
                            'user_id' => $config['user_id'],
                            'shop_id' => $config['target_shop_id'],
                            '$or' => [
                                ['source_product_id' => ['$in' => $productIds]],
                                ['container_id' => ['$in' => $productIds]]
                            ],
                            'status' => 'Not Listed: Offer',
                            'target_marketplace' => 'amazon'
                        ]
                    ]
                ])->toArray();
                $not_intersected = $productIds;
                // intersect productIds with the batch_offer_listing data
                $productIds = array_intersect($productIds, array_column($product_container_data, 'source_product_id'));

                // also get product which do not get intersect
                $not_intersected = array_diff($not_intersected, array_column($product_container_data, 'source_product_id'));

                if ( !empty($not_intersected) ) {
                    $not_intersected = array_values($not_intersected);
                    $batch_offer_listing->updateMany(
                        ['user_id' => $config['user_id'], 'shop_id' => $config['source_shop_id'], 'source_product_id' => ['$in' => $not_intersected]],
                        ['$set' => ['status' => 'skipped', 'updated_at' => date('c')]]
                    );
                }

                $this->di->getLog()->logContent('Product IDs found for upload: ' . json_encode($productIds),'info', $logFile);
                $this->di->getLog()->logContent('Product IDs not found in product container: ' . json_encode($not_intersected),'info', $logFile);
                
                if (empty($product_container_data)) {
                    $this->di->getLog()->logContent('No product data found for upload','info', $logFile);
                    $output->writeln('<options=bold;fg=red>No product data found for upload</>');
                    continue;
                }

                // TODO check if productIds is assigned correctly
                if (empty($productIds)) {
                    $this->di->getLog()->logContent('No product IDs found for upload','info', $logFile);
                    $output->writeln('<options=bold;fg=red>No product IDs found for upload</>');
                    continue;
                }

                $productIds = array_values($productIds);

                $importData = [
                    "target" => [
                        "marketplace" => "amazon",
                        "shopId" => $config['target_shop_id']
                    ],
                    "source" => [
                        "marketplace" => "shopify",
                        "shopId" => $config['source_shop_id']
                    ],
                    "activePage" => 1,
                    "usePrdOpt" => true,
                    "projectData" => [
                        "marketplace" => 0
                    ],
                    "source_product_ids" => $productIds,
                    "useRefinProduct" => true
                ];
                $this->di->getLog()->logContent('data: ' . json_encode($importData),'info', $logFile);

                $code = $importData['target']['marketplace'];
                $importData['code'] = $code;
                $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
                if (!isset($importData['operationType'])) {
                    $importData['operationType'] = 'product_upload';
                }


                $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog("Upload Product", "Upload log added", $importData);
                $res = $product->startSync($importData);
                if ( !$res['success'] ) {
                    $this->di->getLog()->logContent('ERROR: ' . $res['message'], 'info', $logFile);
                    continue;
                }

                $this->di->getLog()->logContent('Upload Process completed successfully started','info', $logFile);

                $batch_offer_listing->updateMany(
                    ['user_id' => $config['user_id'], 'shop_id' => $config['source_shop_id'], 'source_product_id' => ['$in' => $productIds]],
                    ['$set' => ['status' => 'completed']]
                );

            } else {
                $this->di->getLog()->logContent('Auto Listing Creation is disabled','info', $logFile);
                $output->writeln('<options=bold;fg=red>Auto Listing Creation is disabled</>');
            }
        }

        return 0;
    }
    public function setDiForUser($userId)
    {
        if ($this->di->getUser()->id == $userId) {
            return [
                'success' => true,
                'message' => 'user already set in di'
            ];
        }

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

}
