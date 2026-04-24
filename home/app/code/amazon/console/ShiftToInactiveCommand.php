<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShiftToInactiveCommand extends Command
{

    protected static $defaultName = "shift-inactive";
    protected $di;
    protected static $defaultDescription = "initiate to convert active to inactive product which are in linking required in batch";

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
        $this->setHelp('initiate unlinked product to convert active to inactive');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute($input, $output): int
    {
        print_r("Executing command to initiate unlinked product to convert active to inactive sync \n");
        $this->requestToInactive();
        return 0;
    }


    public function requestToInactive(): void
    {
       
        $cronTask = 'ShiftToInactive';
        $limit = 100;
        $skip = 0;
        $continue = true;
        $CronObject = $this->di->getObjectManager()->get('\App\Amazon\Components\Cron\Route\Requestcontrol');
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $this->di->getAppCode()->setAppTag('amazon_sales_channel');
        $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
        $appCodeArray = $appCode->toArray();
        $this->di->getAppCode()->set($appCodeArray);
        $shiftToInactiveUsers = $this->di->getConfig()->get('ShiftToInActive') ?? [];
       
        $shiftToInactiveUsers = json_decode(json_encode($shiftToInactiveUsers),true);
        do {
            $options = [
                'limit' => $limit,
                'skip' => $skip,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ];
            // need to add check for uninstalled sellers
            $user_details = $collection->find(['user_id'=>['$in'=>$shiftToInactiveUsers],'shops.marketplace' => 'amazon', 'uninstall_status' => null], $options)->toArray();
            foreach ($user_details as $user) {
                if (isset($user['user_id'])) {
                    $userId = (string)$user['user_id'];
                    $shops = $user['shops'] ?? [];
                    if (is_iterable($shops)) {
                        foreach ($shops as $shop) {
                            if (isset($shop['marketplace']) && $shop['marketplace'] == 'amazon') {
                                // print_r($shop);die;
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
                                            'remote_shop_id' => $shop['remote_shop_id']
                                        ];
                                       
                                       $CronObject->pushProcessScript($data);
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
