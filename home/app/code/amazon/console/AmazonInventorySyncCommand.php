<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AmazonInventorySyncCommand extends Command
{

    protected static $defaultName = "Invnetory-sync";
    protected $di;
    protected static $defaultDescription = "initiate Invnetory sync";

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
        $this->setHelp('initiate Invnetory sync');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute($input, $output): int
    {
        print_r("Executing command to initiate Invnetory sync \n");
        $this->requestInventory();
        return 0;
    }


    public function requestInventory(): void
    {
        $cronTask = 'Inventory sync';
        $limit = 100;
        $skip = 0;
        $continue = true;
        $inv = $this->di->getObjectManager()->get('\App\Amazon\Components\Inventory\Inventory');
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $this->di->getAppCode()->setAppTag('amazon_sales_channel');
        $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
        $appCodeArray = $appCode->toArray();
        $this->di->getAppCode()->set($appCodeArray);
        $planChecker = $this->di->getObjectManager()->get('\App\Plan\Models\Plan');
        $inventorysyncUsers = $this->di->getConfig()->get('premiumInventoryUser') ?? false;
        $inventorysyncUsers = json_decode(json_encode($inventorysyncUsers),true);
        do {
            $options = [
                'limit' => $limit,
                'skip' => $skip,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];
            // need to add check for uninstalled sellers
            $user_details = $collection->find([
                'user_id' => ['$in' => $inventorysyncUsers],
                'shops' => [
                    '$elemMatch' => [
                        'marketplace' => 'amazon',
                        'apps.app_status' => 'active',
                        'warehouses.status' => 'active'
                    ]
                ],
            ], $options)->toArray();
            foreach ($user_details as $user) {
                if (isset($user['user_id'])) {
                    $userId = (string)$user['user_id'];
                    $shops = $user['shops'] ?? [];
                    if (is_iterable($shops)) {
                        foreach ($shops as $shop) {
                            if (isset($shop['marketplace']) && $shop['marketplace'] == 'amazon') {
                                $sources = $shop['sources'] ?? [];
                                if (is_iterable($sources)) {
                                    foreach ($sources as $sourceData) {
                                        if (!isset($sourceData['code'], $sourceData['shop_id']) || (isset($sourceData['status']) && $sourceData['status'] == 'closed') ) {
                                            continue;
                                        }
                                        $data = [
                                            'user_id' => $userId,
                                            'source_marketplace' => [
                                                'marketplace' => $sourceData['code'],
                                                'source_shop_id' => (string)$sourceData['shop_id']
                                            ],
                                            'target_marketplace' => [
                                                'marketplace' => $shop['marketplace'],
                                                'target_shop_id' => (string)$shop['_id']
                                            ],
                                        ];
                                        
                                       $inv->init($data)->execute($data);
                                        //skip other sources because currently we have only one source
                                        continue;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if (count($user_details) == $limit) {
                $skip += $limit;
            } else {
                $continue = false;
            }
        } while ($continue);
    }
}
