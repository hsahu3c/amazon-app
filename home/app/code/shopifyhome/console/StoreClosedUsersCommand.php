<?php

use App\Core\Models\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

#[\AllowDynamicProperties]
class StoreClosedUsersCommand extends Command
{

    protected static $defaultName = "remove:store-closed_users";

    protected static $defaultDescription = "Remove Users whose Shopify Store is closed from last 6 month or custom data deletion handling";

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
        $this->setHelp('Remove Users whose Shopify Store is closed from last 6 month or custom data deletion handling')
            ->addOption('method', 'm', InputOption::VALUE_OPTIONAL, 'Method name to execute', null);
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Initiating Process..</>');
        $this->output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $method = $input->getOption('method') ?? null ;

        if (!$method) {
            $this->globalDataDeletionHandling();
        } else if (method_exists($this, $method)) {
            $this->$method();
        } else {
            $this->output->writeln("<options=bold;fg=red>Error: Method '{$method}' does not exist</>");
            return 1;
        }
        
        $output->writeln("➤ </><options=bold;fg=green>Process Completed</>");
        return 0;
    }

    /**
     * Global data deletion handling - original logic
     *
     * @param OutputInterface $output
     * @return void
     */
    protected function globalDataDeletionHandling()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('user_details');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $sixMonthsAgo = date('Y-m-d\TH:i:sP', strtotime('-6 months'));

        $userData = $query->find(
            [
                'shops' => [
                    '$elemMatch' => [
                        'marketplace' => self::MARKETPLACE,
                        'plan_name' => ['$in' => ['cancelled', 'frozen', 'fraudulent']],
                        'apps.app_status' => 'active',
                        'store_closed_at' => [
                            '$lte' => $sixMonthsAgo
                        ]
                    ]
                ]
            ],
            $arrayParams,
            ['projection' => ["_id" => false, "shops.$" => true, 'user_id' => true, 'username' => true], $arrayParams]
        )->toArray();

        $this->processUserData($userData);
    }

    /**
     * Custom data deletion handling with specific conditions
     *
     * @return void
     */
    protected function customDataDeletionHandling()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('user_details');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        
        // For no target connected users
        $sevenDaysAgo = date('Y-m-d\TH:i:sP', strtotime('-7 days'));

        // For target connected free plan users or not selected plan users
        $oneMonthAgo = date('Y-m-d\TH:i:sP', strtotime('-1 month'));

        //Note: In these queries we are not deleting data of target connected paid plan users their data is being automatically archived by the target connected app after 1 month
        $userData = $query->find(
            [
                '$or' => [
                    // Condition 1: Only one shop exists (shops.1 doesn't exist) and store_closed_at <= 7 days ago
                    [
                        'shops.1' => ['$exists' => false],
                        'shops' => [
                            '$elemMatch' => [
                                'marketplace' => self::MARKETPLACE,
                                'plan_name' => ['$in' => ['cancelled', 'frozen', 'fraudulent']],
                                'apps.app_status' => 'active',
                                'store_closed_at' => ['$lte' => $sevenDaysAgo]
                            ]
                        ]
                    ],
                    // Condition 2: At least 2 shops exist, plan_tier is 'default', store_closed_at <= 1 month ago
                    [
                        'shops.1' => ['$exists' => true],
                        'plan_tier' => 'default',
                        'shops' => [
                            '$elemMatch' => [
                                'marketplace' => self::MARKETPLACE,
                                'plan_name' => ['$in' => ['cancelled', 'frozen', 'fraudulent']],
                                'apps.app_status' => 'active',
                                'store_closed_at' => ['$lte' => $oneMonthAgo]
                            ]
                        ]
                    ],
                    // Condition 3: At least 2 shops exist, plan_tier doesn't exist, store_closed_at <= 1 month ago
                    [
                        'shops.1' => ['$exists' => true],
                        'plan_tier' => ['$exists' => false],
                        'shops' => [
                            '$elemMatch' => [
                                'marketplace' => self::MARKETPLACE,
                                'plan_name' => ['$in' => ['cancelled', 'frozen', 'fraudulent']],
                                'apps.app_status' => 'active',
                                'store_closed_at' => ['$lte' => $oneMonthAgo]
                            ]
                        ]
                    ]
                ]
            ],
            $arrayParams,
            ['projection' => ["_id" => false, "shops.$" => true, 'user_id' => true, 'username' => true], $arrayParams]
        )->toArray();
        $this->processUserData($userData);
    }

    /**
     * Process user data and push messages to SQS
     *
     * @param array $userData
     * @return void
     */
    protected function processUserData(array $userData)
    {
        if (empty($userData)) {
            $this->output->writeln("<options=bold;fg=yellow>No user data found</>");
            return;
        }
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $count = 0;
        foreach ($userData as $user) {
            $userId = $user['user_id'];
            $this->output->writeln("<options=bold;fg=green>Processing user: " . $userId . "</>");
            $count++;
            foreach ($user['shops'] as $shopData) {
                if ($shopData['marketplace'] == self::MARKETPLACE) {
                    $appCode = $shopData['apps'][0]['code'] ?? 'default';
                    $preparedData = [
                        'type' => 'full_class',
                        'class_name' => '\\App\\Shopifyhome\\Components\\Shop\\StoreClose',
                        'method' => 'initiateProcess',
                        'shop' => $user['username'],
                        'data' => $shopData,
                        'user_id' => $userId,
                        'queue_name' => 'shopify_store_close_users',
                        'shop_id' => $shopData['_id'],
                        'marketplace' => $shopData['marketplace'],
                        'app_code' => $appCode
                    ];
                    $sqsHelper->pushMessage($preparedData);
                    break;
                }
            }
            $this->output->writeln("<options=bold;fg=green>Processed " . $count . " users</>");
        }
    }
}
