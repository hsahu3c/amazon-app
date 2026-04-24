<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncFailedShipmentCommand extends Command
{

    protected static $defaultName = "sync-failedshipment";

    protected static $defaultDescription = "Sync failed Shipment";
    protected $di;

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
        $this->addOption("limit", "l", InputOption::VALUE_OPTIONAL, "Number of Orders to Shipped", 500);
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Shipment Sync...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");
        $this->syncFailedShipment();
        return 0;
    }

    /**
     * shipmentSync function
     * To sync failed Shipment
     */
    public function syncFailedShipment(): int
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::ORDER_CONTAINER);
        $getDate = date('Y-m-d', strtotime('-1 days'));
        $processCount = 0;
        $unfulfilledCount = 0;
        $limit = $this->input->getOption("limit") ?? false;
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $orderParams[] = [
            '$match' => [
            "object_type" => "source_order", 
            "shipment_error" => ['$exists' => false],
             '$or' => [['targets.status' => 'Shipped'], ['targets.status' => 'Partially Shipped']], "status" => "Pending", "targets.marketplace" => "shopify", "created_at" => ['$gte' => $getDate]
        ]];
        $orderParams[] = ['$project' => ['_id' => 0, 'marketplace_reference_id' => 1, 'user_id' => 1]];
        $orderParams[] = ['$limit' => (int)$limit];
        $sourceData = $query->aggregate($orderParams, $arrayParams)->toArray();
        $orderId = array_column($sourceData, 'marketplace_reference_id');
        $totalCount = count($orderId);
        $sourceShipmentData = $query->find(['object_type' => 'source_shipment', 'marketplace_reference_id' => ['$in' => $orderId]], ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
        $amazonOrderService = $this->di->getObjectManager()->get(\App\Amazon\Service\Shipment::class, []);
        foreach ($sourceShipmentData as $data) {
            $response = $amazonOrderService->ship($data);
            if (isset($response['success']) && $response['success']) {
                $outputMessage = "Shipment Sync Done For";
                $outputMessage .= " Amazon OrderId: " . $data['marketplace_reference_id'];
                $this->output->writeln("➤ </><options=bold;fg=green>{$outputMessage}</>");
                $processCount++;
            } else {
                $fufillmentMessage = "";
                $fufillmentMessage .= "Shipment Sync Failed for OrderId: " . $data['marketplace_reference_id'];
                $fufillmentMessage .= " , Reason: " . $response['message'];
                $this->output->writeln("➤ </><options=bold;fg=red>{$fufillmentMessage}</>");
                $unfulfilledCount++;
            }
        }

        $this->output->writeln("<options=bold;bg=bright-white> ➤ </></> ......................................");
        $this->output->writeln("➤ </><options=bold;fg=yellow>Orders Shipment Sync done: {$processCount}</>");
        $this->output->writeln("➤ </><options=bold;fg=blue>Orders given for Shipment Sync: {$totalCount} </>");
        $this->output->writeln("➤ </><options=bold;fg=red>Orders whose Shipment Sync Failed : {$unfulfilledCount} </>");
        return 0;
    }
}