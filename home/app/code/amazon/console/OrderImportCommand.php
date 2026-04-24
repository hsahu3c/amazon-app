<?php

use App\Core\Models\User;
use App\Core\Models\Config\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Amazon\Components\Common\Helper;
use App\Connector\Components\Order\Order as ConnectorOrder;

class OrderImportCommand extends Command
{

    protected static $defaultName = "order-import";
    protected $di;
    protected $output;
    protected $input;
    protected static $defaultDescription = "To Import Bulk Orders from Amazon";

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
        $this->setHelp('Sync order to Source.');
        $this->addOption("user_id", "u", InputOption::VALUE_REQUIRED, "user_id of the user");
        $this->addOption("shop_id", "s", InputOption::VALUE_REQUIRED, "Amazon Home Shop Id of the user");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute($input, $output)
    {
        $this->output = $output;
        $this->input = $input;
        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Initiating Order Import...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $userId = $input->getOption("user_id") ?? false;
        $shopId = $input->getOption('shop_id') ?? false;
        if (!$userId || !$shopId) {
            $output->writeln('➤ </><options=bold;fg=red> ERROR: Userid and Shopid Field is mandatory</>');
            return 0;
        }

        $prepareData = [
            'user_id' => $userId,
            'home_shop_id' => $shopId,
            'marketplace' => 'amazon'
        ];
        $this->initiateProcess($prepareData);
        return 0;
    }

    public function initiateProcess($data)
    {

        $orderIds = ['111-0629240-3222667','111-2669504-3418613'];
        if (!empty($orderIds)) {
            $response = $this->setDiForUser($data['user_id']);
            if (isset($response['success']) && $response['success']) {
                $prepareData = [
                    'target' => [
                        'marketplace' => 'amazon',
                        'shopId' => $data['home_shop_id']
                    ],
                    'source_order_ids' => $orderIds
                ];
                $getResult = $this->importOrder($prepareData);
                return $getResult;
            }
        } else {
            $this->output->writeln('➤ </><options=bold;fg=red> ERROR: Atleast one Order_id Required</>');
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

    public function importOrder($data): void
    {
        if (isset($data['target']['marketplace']) && isset($data['source_order_ids']) && !empty($data['source_order_ids'])) {
            $targetShopId = $data['target']['shopId'];
            $targetMarketplace = $data['target']['marketplace'];
            $amazonOrderIds = $data['source_order_ids'];
            $userId = $this->di->getUser()->id;
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shop = $userDetails->getShop($targetShopId, $userId);
            $homeShopId = $shop['_id'];
            $active = [];
            foreach ($shop['warehouses'] as $warehouse) {
                if ($warehouse['status'] == "active") {
                    $active = $warehouse;
                }
            }

            if (!empty($active)) {
                $objectManager = $this->di->getObjectManager();
                $configObj = $objectManager->get(Config::class);
                $configObj->setGroupCode('order');
                $configObj->setUserId($userId);
                $configObj->setTarget($targetMarketplace);
                $configObj->setTargetShopId($targetShopId);
                $configObj->setAppTag(null);
                $settingConfiguration = $configObj->getConfig('order_sync');
                $orderEnabled = false;
                if($settingConfiguration[0]['value']){
                    $orderEnabled = $settingConfiguration[0]['value'];
                }

                if ($orderEnabled) {
                    $remoteShopId = $shop['remote_shop_id'];
                    $params = [
                        'shop_id' => $remoteShopId,
                        'amazon_order_id' => $amazonOrderIds,
                        'home_shop_id' => $homeShopId
                    ];
                    $connectorOrderComponent = $this->di->getObjectManager()->get(ConnectorOrder::class);
                    $commonHelper = $this->di->getObjectManager()
                        ->get(Helper::class);
                    $response = $commonHelper->sendRequestToAmazon('orders', $params, 'GET');
                    if (isset($response['success']) && !empty($response['success'])) {
                        if (isset($response['orders']) && !empty($response['orders'])) {

                            $save['order']['region'] = $active['region'] ?? "";
                            $save['shop_id'] = $homeShopId;
                            $save['home_shop_id'] = $homeShopId;
                            $save['marketplace'] = $targetMarketplace;
                            $save['region'] = $active['region'] ?? "";
                            foreach ($response['orders'] as $order) {
                                $save['order'] = $order;
                                $saveOrderResponse = $connectorOrderComponent->create($save);
                                $orderId = $order['AmazonOrderId'];
                                if (!$saveOrderResponse['success']) {
                                    if (isset($saveOrderResponse['message']) && !isset($saveOrderResponse['data']['message'])) {
                                        $reason = ', Reason =>' . $saveOrderResponse['message'];
                                        $this->output->writeln("➤ </><options=bold;fg=red>OrderId: {$orderId}{$reason}</>");
                                    } elseif (isset($saveOrderResponse['data']['message'])) {
                                        $reason = ', Reason =>' . $saveOrderResponse['data']['message'];
                                        $this->output->writeln("➤ </><options=bold;fg=red>OrderId: {$orderId}{$reason}</>");
                                    } else {
                                        $encodeData = json_encode($saveOrderResponse, 128);
                                        $reason = ",Reason =>" . json_encode($encodeData);
                                        $this->output->writeln("➤ </><options=bold;fg=red>OrderId: {$orderId}{$reason}</>");
                                    }
                                } else {
                                    $message = 'Synced to Source Successfully';
                                    $this->output->writeln("➤ </><options=bold;fg=green>OrderId: {$orderId} {$message}</>");
                                }
                            }
                        }
                    } else {
                        $reason = ', Reason =>' . $response['message'];
                        $this->output->writeln("➤ </><options=bold;fg=red>Order Importing Failed{$reason}</>");
                    }
                } else {
                    $reason = ', Reason => Order fetch is disabled';
                    $this->output->writeln("➤ </><options=bold;fg=red>Order Importing Failed{$reason}</>");
                }
            } else {
                $reason = ', Reason => This account is disabled';
                $this->output->writeln("➤ </><options=bold;fg=red>Order Importing Failed{$reason}</>");
            }
        } else {
            $reason = ', Reason => One of shop Id or Amazon Order Id not found';
            $this->output->writeln("➤ </><options=bold;fg=red>Order Importing Failed{$reason}</>");
        }
    }
}
