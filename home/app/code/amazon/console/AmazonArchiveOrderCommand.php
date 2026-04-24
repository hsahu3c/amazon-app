<?php

use Symfony\Component\Console\Command\Command;
use App\Amazon\Components\Common\Helper;

class AmazonArchiveOrderCommand extends Command
{

    protected static $defaultName = "archive-order";
    protected $di;
    private $orderCollection;
    protected $input;
    protected $output;
    protected static $defaultDescription = "Move older order data in archive order collection";

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
        $this->setHelp('Move older order data in archive order collection');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute($input, $output): int
    {
        $this->output = $output;
        $this->input = $input;
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue>Initiating Archive Order Process...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");
        $this->initiateOrderArchiveProcess();
        return 1;
    }

    /**
     * initiateOrderArchiveProcess function
     * Function to initiate archive order process
     * @return array
     */
    private function initiateOrderArchiveProcess()
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $this->orderCollection = $mongo->getCollection("order_container");
            $deleteDate = date('Y-m-d', strtotime('-3 months'));
            do {
                $orderData = $this->orderCollection->aggregate(
                    [
                        ['$match' => [
                            "user_id" => ['$ne' => null],
                            "object_type" => "source_order",
                            "marketplace" => Helper::TARGET,
                            "marketplace_shop_id" => ['$ne' => null],
                            "marketplace_reference_id" => ['$ne' => null],
                            "cif_order_id" => ['$ne' => null],
                            "last_delivery_date" => ['$lte' => $deleteDate],
                        ]],
                        ['$limit' => 1000],
                        ['$project' => ["user_id" => 1, 'marketplace' => 1, 'marketplace_shop_id' => 1, 'status' => 1,'marketplace_status' => 1, 'fulfilled_by' => 1, "marketplace_reference_id" => 1, 'total' => 1, 'targets' => 1, 'items.type' => 1,
                        'source_created_at' => 1, 'created_at' => 1, 'items.sku' => 1,  'items.qty' => 1, 'items.title' => 1, 'items.marketplace_price' => 1, 'shipping_address' => 1, 'cif_order_id' => 1]]
                    ],
                    ["typeMap" => ['root' => 'array', 'document' => 'array'], 'allowDiskUse' => true]
                )->toArray();
                if (!empty($orderData)) {
                    $preparedData = [];
                    $archiveOrderData = [];
                    foreach ($orderData  as $order) {
                        $preparedData[$order['marketplace_reference_id']] = [
                            'user_id' => $order['user_id'] ?? '',
                            'marketplace_shop_id' => $order['marketplace_shop_id'] ?? '',
                            'marketplace' => $order['marketplace'] ?? '',
                            'marketplace_reference_id' => $order['marketplace_reference_id'] ?? '',
                            'marketplace_status' => $order['marketplace_status'] ?? 'Created',
                            'status' => 'archived',
                            'items' => $order['items'] ?? [],
                            'marketplace_price' => $order['total']['marketplace_price'] ?? 0,
                            'targets' => $order['targets'] ?? '',
                            'archive_type' => 'automatic',
                            'fulfilled_by' => $order['fulfilled_by'] ?? '',
                            'source_created_at' => $order['source_created_at'] ?? '',
                            'order_created_at' => $order['created_at'] ?? '',
                            'created_at' => date('c')
                        ];
                        if (!empty($order['shipping_address'])) {
                            $preparedData[$order['marketplace_reference_id']]['shipping_address'] = [
                                'address_line_1' => $order['shipping_address'][0]['address_line_1'] ?? '',
                                'city' => $order['shipping_address'][0]['city'] ?? '',
                                'state' => $order['shipping_address'][0]['state'] ?? '',
                                'zip_code' => $order['shipping_address'][0]['zip_code'] ?? '',
                                'country' => $order['shipping_address'][0]['country'] ?? ''
                            ];
                        }
                    }
                    $orderIds = array_column($orderData, 'marketplace_reference_id');
                    $cifOrderIds = array_column($orderData, 'cif_order_id');
                    $orderRelatedDocs = $this->getOrderRelatedDocs($orderIds);
                    if (!empty($orderRelatedDocs)) {
                        $archiveOrderData = $this->mergeOrderData($preparedData, $orderRelatedDocs);
                    } else {
                        $archiveOrderData = $preparedData;
                    }
                    $archiveOrderCollection = $mongo->getCollection("archive_order_container");
                    $archiveOrderCollection->insertMany(array_values($archiveOrderData));
                    $this->deleteOrderDocs($cifOrderIds);
                } else {
                    $this->output->writeln("➤ </><options=bold;fg=green>Process Completed</>");
                }
            } while (!empty($orderData));

            return ['success' => true, 'message' => 'Process Compeleted...'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception: AmazonArchiveOrderCommand, Error: ' . print_r($e->getMessage()), 'info', 'exception.log');
            return 0;
        }
    }

    /**
     * deleteOrderDocs function
     * Deleting docs based on cif_order_id
     * @return null
     */
    private function deleteOrderDocs($cifOrderIds)
    {
        $this->orderCollection->deleteMany(
            [
                'cif_order_id' => ['$in' => $cifOrderIds]
            ]
        );
    }

    /**
     * getOrderRelatedDocs function
     * Function to get shipment,cancellation and refund doc
     * @return array
     */
    private function getOrderRelatedDocs($orderIds)
    {
        $pipeline = [
            ['$match' => [
                'object_type' => ['$in' => ['source_shipment', 'source_cancellation', 'source_refund']],
                'marketplace_reference_id' => ['$in' => $orderIds]
            ]],
            ['$project' => [
                'created_at' => 1,
                'tracking' => 1,
                'cancel_reason' => 1,
                'error' => 1,
                'refund_amount' => 1,
                'marketplace_reference_id' => 1,
                'object_type' => 1,
                'items.sku' => 1,
                'items.qty' => 1,
                'items.marketplace_price' => 1,
                'items.cancel_reason' => 1,
                'items.cancelled_qty' => 1,
                'items.customer_note' => 1,
                'items.shipped_qty' => 1,
                'items.title' => 1
            ]],
            [
                '$group' => [
                    '_id' => ['id' => '$marketplace_reference_id', 'object_type' => '$object_type'],
                    'data' => [
                        '$push' => '$$ROOT'
                    ]
                ]
            ],
        ];
        return $this->orderCollection->aggregate(
            $pipeline,
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        )->toArray();
    }

    /**
     * mergeOrderData function
     * Preparing final archive order data by merging it in single doc
     * @return array
     */
    private function mergeOrderData($sourceOrderData, $orderRelatedDocs)
    {
        $mappedKeys = [
            'source_shipment' => 'shipment',
            'source_cancellation' => 'cancellation',
            'source_refund' => 'refund'
        ];
        foreach ($orderRelatedDocs as $doc) {
            $unquieKey = $doc['_id'];
            $sourceOrderData[$unquieKey['id']][$mappedKeys[$unquieKey['object_type']]] =  $doc['data'];
        }
        return $sourceOrderData;
    }
}
