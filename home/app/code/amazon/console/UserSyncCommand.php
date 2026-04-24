<?php

use App\Core\Models\Config\Config;
use App\Amazon\Components\ShopEvent;
use App\Amazon\Components\Order\Order;
use App\Amazon\Components\Common\Helper;
use Symfony\Component\Console\Command\Command;

class UserSyncCommand extends Command
{

    protected static $defaultName = "user:sync-to-dynamo";
    protected $di;
    protected $mongo;
    protected $shopEvent;
    protected static $defaultDescription = "sync credential to dynamo";
    const LIMIT = 100;

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
        $this->shopEvent = $this->di->getObjectManager()->get(ShopEvent::class);
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Configure user data into dynamo.');
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute($input, $output)
    {
        $output->writeln('<options=bold;bg=cyan> ➤ Initiating User Sync to Dynamo Process...</>');
        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'projection' => ['user_id' =>  1, 'username' => 1, 'shops' => 1],
        ];
        $userDetailsCollection = $this->mongo->getCollectionForTable('user_details');
        $userData = $userDetailsCollection->aggregate([
            [
                '$match' => [
                    'shops.marketplace' => Helper::TARGET,
                ]
            ],
            [
                '$unwind' => '$shops'
            ],
            [
                '$match' => [
                    'shops.apps.app_status' => ['$ne' => 'uninstall'],
                    'shops.marketplace' => Helper::TARGET,
                    'shops.sources' => ['$exists' => true, '$ne' => []],
                    'shops.register_for_order_sync' => ['$exists' => false]

                ]
            ],
            ['$limit' => self::LIMIT]
        ], $options)->toArray();
        if (!empty($userData)) {
            $chunks = array_chunk($userData, 20);
            $failedUser = [];
            foreach ($chunks as $users) {
                $bulkOpArray = [];
                $remoteData = [];
                $configObj = $this->di->getObjectManager()
                    ->get(Config::class);
                foreach ($users as $user) {
                    if (isset($user['shops'], $user['user_id']) && !empty($user['shops'])) {
                        $shop = $user['shops'];
                        if (isset($shop['warehouses'][0]['status'])
                            && $shop['warehouses'][0]['status'] == 'active') {
                            $planExists = false;
                            $class = '\App\Plan\Models\Plan';
                            if (class_exists($class) && method_exists($class, 'getActivePlanForCurrentUser')) {
                                $planChecker = $this->di->getObjectManager()->get($class);
                                $planData = $planChecker->getActivePlanForCurrentUser($user['user_id']);
                                if (empty($planData)) {
                                    $this->shopEvent->updateRegisterForOrderSync($user['user_id'], $shop['_id'], true);
                                    continue;
                                }

                                $planExists = true;
                            }

                            $priority = 2;
                            $checkFbaSetting = true;
                            if ($planExists) {
                                $customPrice = $planData['plan_details']['custom_price'];
                                if ($customPrice > 100) {
                                    $priority = 1;
                                }
                                if ($customPrice == 0) {
                                    $checkFbaSetting = false;
                                    $this->shopEvent->createFbaSettingDocs($user['user_id'], $shop);
                                }
                            }

                            $preparedData = [
                                'order_status'      => ['Unshipped'],
                                'order_channel'     => 'MFN',
                                'order_filter'      => '{}',
                                'order_fetch'       =>  1,
                                'disable_order_sync' => 0,
                                'home_shop_id'      => $shop['_id'],
                                'remote_shop_id'    => $shop['remote_shop_id'],
                                'queue_name'        => 'amazon_orders',
                                'priority'          => $priority,
                                'message_data'      => [
                                    'user_id'           => $user['user_id'],
                                    'handle_added'      => '1',
                                    'queue_name'        => 'amazon_orders',
                                    'type'              => 'full_class',
                                    'class_name'        => Order::class,
                                    'method'            => 'syncOrder'
                                ]
                            ];
                            if (!$checkFbaSetting) {
                                $preparedData['fba_fetch'] = "1";
                                $preparedData['fba_filter'] = '{}';
                            } else {
                                $configObj->setGroupCode('order');
                                $configObj->setUserId($user['user_id']);
                                $configObj->setTarget(Helper::TARGET);
                                $configObj->setTargetShopId($shop['_id']);
                                $configObj->setAppTag(null);
                                $fbaSetting = $configObj->getConfig('fbo_order_fetch');
                                if (!empty($fbaSetting) && $fbaSetting[0]['value']) {
                                    $preparedData['fba_fetch'] = "1";
                                    $preparedData['fba_filter'] = '{}';
                                }
                            }

                            $remoteData[] = $preparedData;
                            $bulkOpArray[$shop['_id']] = [
                                'updateOne' => [
                                    ['_id' => $user['_id'], 'shops' => ['$elemMatch' => [
                                        'marketplace' => Helper::TARGET,
                                        '_id' => $shop['_id']
                                    ]]],
                                    ['$set' => ['shops.$.register_for_order_sync' => true]]
                                ]
                            ];
                        }
                    }
                }
                if (!empty($remoteData)) {
                    $logfile = "amazon/register_for_order_sync/" . date('d-m-Y') . '.log';
                    $this->di->getLog()->logContent('Requested remote data: ' . json_encode($remoteData), 'info', $logfile);
                    $helperObj = $this->di->getObjectManager()->get(Helper::class);
                    $response = $helperObj->sendRequestToAmazon('order-sync/register', $remoteData, 'POST', 'json');
                    $this->di->getLog()->logContent('Remote Response:  ' . json_encode($response), 'info', $logfile);
                    if (isset($response['success']) && $response['success'] && !empty($response['registered'])) {
                        $successShopIds = [];
                        foreach ($response['registered'] as $registered) {
                            if (isset($bulkOpArray[$registered['home_shop_id']])) {
                                $successShopIds[] = $bulkOpArray[$registered['home_shop_id']];
                            }
                        }
                        if (!empty($successShopIds)) {
                            $userDetailsCollection->BulkWrite($successShopIds, ['w' => 1]);
                        }
                    } else {
                        $this->di->getLog()->logContent('Remote request failed, Response: '
                            . json_encode($response), 'info', $logfile);
                    }

                    if (!empty($response['failed'])) {
                        $failedUser['failed'][] = $response['failed'];
                    }
                }
            }
            if (!empty($failedUser)) {
                $this->di->getLog()->logContent('Failed users: ' . json_encode($failedUser), 'info', $logfile);
            }
        }

        $output->writeln("<info>Executed Successfully!!</info>");
        return 0;
    }
}
