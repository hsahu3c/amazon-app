<?php

use App\Shopifyhome\Components\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[\AllowDynamicProperties]
class ManualShipOrderCommand extends Command
{

    protected static $defaultName = "manual-ship-order";

    protected static $defaultDescription = "Manual Shipment of Orders";

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
        $this->setHelp('Sync target order from Shopify.');
        $this->addOption("type", "t", InputOption::VALUE_REQUIRED, "type");
        $this->addOption("user_id", "u", InputOption::VALUE_OPTIONAL, "User_id of the user");
        $optionalDate = date('Y-m-d', strtotime('-5 days'));
        $this->addOption("source_created_at", "d", InputOption::VALUE_OPTIONAL, 'Date', $optionalDate);
        $this->addOption("limit", "l", InputOption::VALUE_OPTIONAL, "Number of Orders to Shipped", "100");
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
        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Initiating Shipment Sync...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $type = $input->getOption("type") ?? false;
        if (!$type) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:Type field is mandatory</>');
            return 0;
        }

        if ($type == "userwise") {
            $userId = $input->getOption("user_id") ?? false;
            if (!$userId) {
                $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> User_id is Required(with -u) </>');
                return 0;
            }
            $sourceCreatedAt = $input->getOption("source_created_at") ?? false;
            $isDateValid = $this->isDateValid($sourceCreatedAt);
            if (!$isDateValid['success']) {
                $output->writeln('➤ </><options=bold;fg=red> ERROR:' . $isDateValid['message'] . '</>');
            } else {
                $limit = $input->getOption("limit") ?? false;
                $prepareData = [
                    'user_id' => $userId,
                    'source_created_at' => $sourceCreatedAt,
                    'limit' => $limit

                ];
                $this->manualShipmentUserWise($prepareData);
            }
        } elseif ($type == "orderwise") {
            $this->manualShipmentOrderWise();
        } else {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: This type does not exists</>');
        }

        return 0;
    }

    /**
     * manualShipmentOrderWise function
     * To Manual Ship Orders by Amazon OrderId wise
     * @param [type] $output
     */
    public function manualShipmentOrderWise(): void
    {
        $orderIds = ['397-1011838-334567009', '397-1011838-311122'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('order_container');
        $params[] = ['$match' => [
            'marketplace_reference_id' => ['$in' => $orderIds],
            'object_type' => 'source_order', 'targets.marketplace' => 'shopify'
        ]];
        $params[] = ['$project' => ['_id' => 0, 'targets' => 1, 'user_id' => 1, 'marketplace_reference_id' => 1]];
        $sourceData = $query->aggregate($params, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
        $this->fulfillOrder($sourceData);
    }

    /**
     * manualShipmentUserWise function
     * To Manual Ship Orders Userwise
     * @param [type] $output
     */
    public function manualShipmentUserWise($params): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('order_container');
        $orderParams[] = ['$match' => [
            "user_id" => $params['user_id'], "object_type" => "source_order",
            "status" => 'Pending', "targets.order_id" => ['$exists' => true], "targets.status" => "Shipped",
            "source_created_at" => ['$gte' => $params['source_created_at']]
        ]];
        $orderParams[] = ['$project' => ['_id' => 0, 'targets' => 1, 'user_id' => 1, 'marketplace_reference_id' => 1]];
        $orderParams[] = ['$limit' => (int)($params['limit'])];
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $sourceData = $query->aggregate($orderParams, $arrayParams)->toArray();
        $this->fulfillOrder($sourceData);
    }

    /**
     * fulfillOrder function
     * Preparing Shipment for Orders
     * @param [type] $output
     */
    public function fulfillOrder($sourceData): void
    {
        $processCount = 0;
        $unfulfilledCount = 0;
        $totalCount = count($sourceData);
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
        $object = $this->di->getObjectManager()->get('App\\Connector\\Models\\SourceModel');
        foreach ($sourceData as $result) {
            $target = $result['targets'][0];
            $getDetails = $userDetails->getShop($target['shop_id'], $result['user_id']);
            $orderFetchParams = [
                'shop_id' => $getDetails['remote_shop_id'],
                'id' => $target['order_id'],
            ];

            $response = $shopifyHelper->sendRequestToShopify('/order', [], $orderFetchParams, 'GET');
            if (
                $response['success'] && isset($response['data']['fulfillments'])
                && !empty($response['data']['fulfillments'])
            ) {
                $prepareData = [];
                foreach ($response['data']['fulfillments'] as $fulfillments) {
                    $prepareData['shop'] = $getDetails['myshopify_domain'];
                    $prepareData['data'] = $fulfillments;
                    $prepareData['user_id'] = $result['user_id'];
                    $prepareData['shop_id'] = $target['shop_id'];
                    $prepareData['type'] = "full_class";
                    $prepareData['class_name'] = "\\App\\Connector\\Models\\SourceModel";
                    $prepareData['method'] = "triggerWebhooks";
                    $prepareData['action'] = "fulfillments_update";
                    $prepareData['marketplace'] = "shopify";
                    $getResponse = $object->triggerWebhooks($prepareData);
                    if ($getResponse) {
                        $outputMessage = "Shipment Sync Done For";
                        $outputMessage .= " Amazon OrderId: " . $result['marketplace_reference_id'];
                        $this->output->writeln("➤ </><options=bold;fg=green>{$outputMessage}</>");
                        $processCount++;
                    }
                }
            } else {
                $fufillmentMessage = "";
                $fufillmentMessage .= "Fulfillment not came for OrderId: " . $result['marketplace_reference_id'];
                $this->output->writeln("➤ </><options=bold;fg=red>{$fufillmentMessage}</>");
                $unfulfilledCount++;
            }
        }

        $this->output->writeln("<options=bold;bg=bright-white> ➤ </></> ......................................");
        $this->output->writeln("➤ </><options=bold;fg=yellow>Orders Shipment Sync done: {$processCount}</>");
        $this->output->writeln("➤ </><options=bold;fg=blue>Orders given for Shipment Sync: {$totalCount} </>");
        $this->output->writeln("➤ </><options=bold;fg=red>Orders whose Fulfillment not came : {$unfulfilledCount} </>");
    }

    /**
     * isDateValid function
     * Validation of given Date
     * @param [type] $output
     * @return void
     */
    public function isDateValid($sourceCreatedAt)
    {
        $getDateNeedleResponse = $this->getDateNeedle($sourceCreatedAt);
        if (!$getDateNeedleResponse['success']) {
            return $getDateNeedleResponse;
        }

        $explodeNeedle = $getDateNeedleResponse['data'];

        $explodedDate = explode($explodeNeedle, (string) $sourceCreatedAt);
        $flag = 0;
        if (count($explodedDate) != 3) {
            $flag = 1;
            $message = "Date format is not valid for this process";
        }

        if (!checkdate($explodedDate[1], $explodedDate[2], $explodedDate[0])) {
            $flag = 1;
            $message = "Input date is invalid! Expected format yyyy-mm-dd";
        }

        if ($flag == 1) {
            return ['success' => false, 'message' => $message];
        }

        return ['success' => true, 'data' => $sourceCreatedAt];
    }

    /**
     * getDateNeedle function
     * Validation of given Date format
     * @param [string] $date
     * @return void
     */
    public function getDateNeedle($date)
    {
        $explodeNeedle = '';
        if (str_contains((string) $date, '-')) {
            $explodeNeedle = '-';
        }

        if ($explodeNeedle === '') {
            return ['success' => false, 'message' => 'Date format is not valid for this process'];
        }

        return ['success' => true, 'data' => $explodeNeedle];
    }
}
