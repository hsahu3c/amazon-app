<?php

use App\Core\Models\User;
use App\Connector\Contracts\Sales\OrderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[\AllowDynamicProperties]
class SyncTargetOrderCommand extends Command
{
    protected static $defaultName = "sync:shopify-target-order";

    protected static $defaultDescription = "Sync target orders from Shopify";

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
        $this->mongoConnection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getConnection();
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Sync target order from Shopify.');
        $this->addOption("user_id", "u", InputOption::VALUE_REQUIRED, "user_id of the user");
        $this->addOption("shop_id", "s", InputOption::VALUE_REQUIRED, "shop_id of the user");
        $this->addOption("order_id", "o", InputOption::VALUE_OPTIONAL, "shopify order_id");
        $this->addOption("any_status", "a", InputOption::VALUE_OPTIONAL, "get orders with any status", "n");
        $this->addOption("created_at_min", "c", InputOption::VALUE_OPTIONAL, "get orders created after a certain date");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Target Order syncing from Shopify...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $user_id = $input->getOption("user_id") ?? false;
        $shop_id = $input->getOption("shop_id") ?? false;
        $order_id = $input->getOption("order_id") ?? false;
        $anyStatus = $input->getOption("any_status") ?? false;
        $created_at_min = $input->getOption("created_at_min") ?? false;

        if (!$shop_id || !$user_id) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> shop_id/user_id is required </>');
            return 0;
        }

        $response = $this->setDiForUser($user_id);
        if (!$response['success']) {
            $output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> {$response['message']} </>");
            return 0;
        }

        $params['shop_id'] = $shop_id;
        if ($order_id) {
            $params['id'] = $order_id;
        }

        $anyStatusTrueConditions = ['y', 'Y', 'yes', 'Yes'];
        if ($anyStatus && in_array($anyStatus, $anyStatusTrueConditions)) {
            $params['status'] = 'any';
        }

        if ($created_at_min) {
            $params['created_at_min'] = $created_at_min;
        }

        $getOrdersResponse = $this->getShopifyOrders($params);
        if (!$getOrdersResponse['success']) {
            $error = $getOrdersResponse['msg'] ?? $getOrdersResponse['message'];
            if (is_array($error)) {
                $error = json_encode($error);
            }

            $output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> {$error} </>");
            return 0;
        }

        $ordersData = $getOrdersResponse['data'];

        if ($order_id) {
            $ordersData = [$ordersData];
        }

        $connectorOrderComponent = $this->di->getObjectManager()->get('\App\Connector\Components\Order\Order');
        $processedOrderCount = 0;
        $createdTargetOrder = 0;
        foreach ($ordersData as $order) {
            $processedOrderCount += 1;
            $orderWrapper = $this->prepareOrderForConnector($order, $shop_id);
            $id = $order['id'] ?? "N/A";
            $connectorResponse = $connectorOrderComponent->createWebhook($orderWrapper);
            $connectorResponse['message'] .= ". Shopify order_id: " . $id;
            $colour = "red";
            if ($connectorResponse['success']) {
                $colour = "green";
                $createdTargetOrder += 1;
            } elseif (stripos($connectorResponse['message'], 'target_order found in database') !== false) {
                $colour = "cyan";
            } elseif (stripos($connectorResponse['message'], 'Order not created from our app') !== false) {
                $colour = 'bright-white';
            }

            $output->writeln("<options=bold;fg={$colour};bg=bright-white> ➤ </><options=bold;fg={$colour}> {$connectorResponse['message']} </>");
        }

        $orderCount = count($ordersData);
        $output->writeln("<options=bold;bg=black;fg=bright-white> ➤ </><options=bold;fg=yellow> Total orders received: {$orderCount}</>");
        $output->writeln("<options=bold;bg=black;fg=bright-white> ➤ </><options=bold;fg=yellow> Total orders processed: {$processedOrderCount} </>");
        $output->writeln("<options=bold;bg=black;fg=bright-white> ➤ </><options=bold;fg=green> Total target orders created: {$createdTargetOrder} </>");
        return 1;
    }

    public function setDiForUser($user_id)
    {
        try {
            $getUser = User::findFirst([['_id' => $user_id]]);
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


    public function getShopifyOrders($params)
    {
        $params['app_code'] = 'default';
        $shopifyOrderService = $this->di->getObjectManager()->get(OrderInterface::class, [], 'shopify');
        $response = $shopifyOrderService->get($params);
        return $response['data'];
    }

    public function prepareOrderForConnector($orderData, $shop_id)
    {
        $orderWrapper = [];
        $orderWrapper['data'] = $orderData;
        $orderWrapper['shop_id'] = $shop_id;
        $orderWrapper['marketplace'] = 'shopify';
        return $orderWrapper;
    }
}
