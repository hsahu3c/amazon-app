<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncFailedOrdersCommand extends Command
{
    protected static $defaultName = "order:sync-failed";

    protected static $defaultDescription = "Sync failed orders from the database and attempts to recreate them";

    protected static $cronTaskCollection = "cron_tasks";

    protected static $cronTaskName = "failed_order_sync";
    protected $di;
    protected $mongoConnection;

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
        $this->setHelp('Sync failed orders on target marketplaces');

        $this->addOption(
            "limit",
            "l",
            InputOption::VALUE_OPTIONAL,
            "number of orders to sync",
            "100"
        );
        $this->addOption(
            "date_from",
            "d",
            InputOption::VALUE_OPTIONAL,
            "date from which orders are to be synced",
            date('Y-m-d', strtotime('-5 days'))
        );
        $this->addOption(
            "use_last_delivery_date",
            "e",
            InputOption::VALUE_OPTIONAL,
            "use last_delivery_date (end date of delivery) as the deciding parameter which order to be synced",
            "n"
        );
        $this->addOption(
            "info",
            "i",
            InputOption::VALUE_OPTIONAL,
            "display the connector response",
            "n"
        );
        $this->addOption(
            "user_id",
            "u",
            InputOption::VALUE_OPTIONAL,
            "user_id for which orders need to be synced"
        );
        $this->addOption(
            "order_id",
            "o",
            InputOption::VALUE_OPTIONAL,
            "order_id which needs to be synced"
        );
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $initiatingMessage = 'Initiating failed orders syncing to target marketplace ...';
        $output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=blue> {$initiatingMessage}</>");
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $limit = $input->getOption("limit") ?? false;
        $dateFrom = $input->getOption("date_from") ?? false;
        $showInfo = $input->getOption("info") ?? false;
        $userId = $input->getOption("user_id") ?? false;
        $orderId = $input->getOption("order_id") ?? false;
        $lastDeliveryDate = $input->getOption("use_last_delivery_date") ?? false;

        $showInfoAllowedOptions = ['y', 'Y', 'yes', 'Yes'];

        $isDateValid = $this->isDateValid($dateFrom);
        if (!$isDateValid['success']) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: ' . $isDateValid['message'] . '</>');
            return 0;
        }

        $lastTraversedObjectId = $this->getLastTraversedObjectId();

        $getFailedResponse = $this->getFailedSourceOrders($dateFrom, $limit, $lastTraversedObjectId, $userId, $orderId, $lastDeliveryDate);
        if (!$getFailedResponse['success']) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: ' . $getFailedResponse['message'] . '</>');
            return 0;
        }

        $failedSourceOrders = $getFailedResponse['data'];
        if (count($failedSourceOrders) < 1) {
            $output->writeln('<options=bold;bg=bright-white;fg=blue> ➤ </><options=bold;fg=cyan> No Failed Source Order found </>');
            if ($lastTraversedObjectId) {
                $output->writeln("<options=bold;bg=bright-white;fg=blue> ➤ </><options=bold;fg=bright-white> Last traversed ObjectId:- {$lastTraversedObjectId} </>");
            }

            self::updateLastTraversedObjectId($failedSourceOrders);
            return 0;
        }

        $connectorOrderComponent = $this->di->getObjectManager()->get('\App\Connector\Components\Order\Order');
        foreach ($failedSourceOrders as $source_order) {
            //target order created, targets created in source but status not updated in source order
            $diResponse = $this->setDiForUser($source_order['user_id']);
            if (!$diResponse['success']) {
                $diResponse['message'] .= " user_id: {$source_order['user_id']}";
                $output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> {$diResponse['message']} </>");
                continue;
            }

            $sourceOrderId = " Source Order Id: " . $source_order['marketplace_reference_id'];
            $formattedData['savedData'] = $source_order;
            $createSavedOrderResponse = $connectorOrderComponent->createSavedOrder($formattedData);

            $outputMessage = null;
            $defaultOutputMessage = "ERROR: Some error occurred in creating target order.";
            $colour = 'red';
            if ($createSavedOrderResponse['success']) {
                $outputMessage = "Target Order created.";
                $colour = 'green';
                $targetOrderIds = $this->getTargetOrderId($createSavedOrderResponse['data'] ?? []);
                if (!empty($targetOrderIds)) {
                    $outputMessage .= " Target Order Id(s) = " . $targetOrderIds . '.';
                }
            }

            if (!isset($outputMessage)) {
                $outputMessage = $defaultOutputMessage;
            }

            $outputMessage .= $sourceOrderId;
            $output->writeln("<options=bold;bg=bright-white;fg={$colour}> ➤ </><options=bold;fg={$colour}>  {$outputMessage}</>");
            if (in_array($showInfo, $showInfoAllowedOptions)) {
                $output->writeln("<options=bold;bg=black> ➤ </><options=bold;>  connector response:-</><options=bold;fg=black> " . json_encode($createSavedOrderResponse) . "</>");
            }

            $this->log($source_order['marketplace_reference_id'], json_encode($createSavedOrderResponse));
        }

        if (!$userId && !$orderId) {//date check cannot be added here as there is a default value for it
            self::updateLastTraversedObjectId($failedSourceOrders);
        }

        return 1;
    }

    public function getFailedSourceOrders(
        $dateFrom,
        $limit,
        $lastTraversedObjectId = null,
        $userId = false,
        $orderId = false,
        $lastDeliveryDate = false
    ): array {
        $connectorOrderService =  $this->di->getObjectManager()
            ->get(\App\Connector\Contracts\Sales\OrderInterface::class);

        $collection = $connectorOrderService::ORDER_CONTAINER;
        $orderContainer = $this->getMongoCollection($collection);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array'], "limit" => (int)$limit];

        //todo: utilise last_delivery_date
        $shopNotEquals = ["", null];
        $query = [
            'targets' => [
                '$elemMatch' => [
                    'status' => 'failed',
                    'order_id' => ['$exists' => false],
                    'shop_id' => ['$nin' => $shopNotEquals]
                ]
            ],
            'source_created_at' => ['$gte' => $dateFrom],
            "status" => ['$ne' => 'archived']
        ];

        if ($orderId) {
            $query = ['marketplace_reference_id' => $orderId, ...$query];
            unset($query['source_created_at']);
        }

        if (in_array($lastDeliveryDate, ['y', 'Y', 'yes', 'Yes'])) {
            $query = array_merge(['last_delivery_date' => ['$gte' => date('c')]], $query);
            unset($query['source_created_at']);
        }

        $query = ['object_type' => 'source_order', ...$query];

        if ($userId) {
            $query = array_merge(['user_id' => $userId], $query);
        }

        if (!is_null($lastTraversedObjectId)) {
            $query = array_merge(['_id' => ['$gt' => $lastTraversedObjectId]], $query);
        }

        //todo: add param to know final query

        try {
            $sourceOrders = $orderContainer->find($query, $options)->toArray();
            return ['success' => true, 'data' => $sourceOrders];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getTargetOrderId($targetOrdersResponse): string
    {
        $targetOrderIds = array_column($targetOrdersResponse, 'order_id');
        $targetOrderIds = array_filter($targetOrderIds);
        return implode(', ', $targetOrderIds);
    }


    public function isDateValid($dateFrom)
    {
        $getDateNeedleResponse = $this->getDateNeedle($dateFrom);
        if (!$getDateNeedleResponse['success']) {
            return $getDateNeedleResponse;
        }

        $explodeNeedle = $getDateNeedleResponse['data'];

        $explodedDate = explode($explodeNeedle, $dateFrom);
        if (count($explodedDate) != 3) {
            return ['success' => false, 'message' => 'Date format is not valid for this process'];
        }

        if (!checkdate($explodedDate[1], $explodedDate[2], $explodedDate[0])) {
            return ['success' => false, 'message' => 'Input date is invalid! Expected format yyyy-mm-dd'];
        }

        return ['success' => true, 'data' => $dateFrom];
    }


    public function getDateNeedle($date): array
    {
        $needles = ['/', '-'];
        foreach ($needles as $needle) {
            if (strpos($date, $needle) !== false) {
                return ['success' => true, 'data' => $needle];
            }
        }

        return ['success' => false, 'message' => 'Date format is not valid for this process'];
    }

    public function getLastTraversedObjectId()
    {
        $cronTaskCollection = $this->getMongoCollection(self::$cronTaskCollection);
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $query = [
            'task' => self::$cronTaskName,
        ];
        $cronTask = $cronTaskCollection->findOne($query, $options);
        return $cronTask['last_traversed_object_id'] ?? null;
    }

    public function updateLastTraversedObjectId($failedSourceOrders): void
    {
        $lastTraversedObjectId = null;
        if (!empty($failedSourceOrders)) {
            $lastTraversedFailedOrder = end($failedSourceOrders);
            $lastTraversedObjectId = $lastTraversedFailedOrder['_id'];
        }

        $cronTaskCollection = $this->getMongoCollection(self::$cronTaskCollection);
        $query = ['task' => self::$cronTaskName];
        $update = ['$set' => ['last_traversed_object_id' => $lastTraversedObjectId]];
        $cronTaskCollection->updateOne($query, $update, ['upsert' => true]);
    }

    public function getMongoCollection($collection)
    {
        return $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')
            ->getCollection($collection);
    }

    public function setDiForUser($userId): array
    {
        try {
            $getUser = \App\Core\Models\User::findFirst([['_id' => $userId]]);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found.'
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

    public function log($sourceOrderId, $data): void
    {
        $div = '----------------------------------------------------------------';
        $file = 'order/failed-order-sync/' . $this->di->getUser()->id . '/' . date('Y-m-d') . '.log';
        $time = date('Y-m-d H:i:s');
        $this->di->getLog()->logContent(
            $time  . PHP_EOL
                . 'source-order-id:- ' . print_r($sourceOrderId, true) . PHP_EOL
                . 'connector-response:- ' . print_r($data, true)
                . PHP_EOL . $div,
            'info',
            $file
        );
    }
}
