<?php

use Symfony\Component\Console\Command\Command;

class RemovePIDataCommand extends Command
{

    protected static $defaultName = "remove-pi-info";
    protected $di;
    protected static $defaultDescription = "Remove personal Information";

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
        $this->setHelp('Remove personal Information');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute($input, $output): int
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $orderCollection = $mongo->getCollection("order_container");
            $deleteDate = date('Y-m-d', strtotime('-30 days'));
            do {
                $data = $orderCollection->aggregate(
                    [
                        ['$match' => [
                            "user_id" => ['$ne' => null],
                            "object_type" => "source_order",
                            "marketplace" => "amazon",
                            "marketplace_shop_id" => ['$ne' => null],
                            "marketplace_reference_id" => ['$ne' => null],
                            'pi_removed' => null,
                            'attributes' => [
                                '$elemMatch' => [
                                    "key" => "Amazon LatestShipDate",
                                    "value" => ['$lte' => $deleteDate]
                                ]
                            ]
                        ]],
                        ['$limit' => 5000],
                        ['$project' => ["marketplace_reference_id" => 1]]
                    ],
                    ["typeMap" => ['root' => 'array', 'document' => 'array'], 'allowDiskUse' => true]
                )->toArray();
                if (!empty($data)) {
                    $orderIds = array_column($data, 'marketplace_reference_id');
                    $orderCollection->updateMany(['object_type' => 'source_order', 'marketplace_reference_id' => ['$in' => $orderIds]], ['$unset' => ['billing_address' => 1, 'customer' => 1, 'shipping_address' => 1, 'shipping_details' => 1], '$set' => ['pi_removed' => true]]);
                    $orderCollection->updateMany(['object_type' => 'target_order', 'targets.order_id' => ['$in' => $orderIds]], ['$unset' => ['billing_address' => 1, 'customer' => 1, 'shipping_address' => 1], '$set' => ['pi_removed' => true]]);
                    $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> ' . count($orderIds) . ' Orders Updated!!</>');
                }
            } while (!empty($data));
            // Deleting 3-months old order data from amazon_order_ids collection
            $amazonOrderIdsCollection = $mongo->getCollection("amazon_order_ids");
            $deleteDate = date('Y-m-d', strtotime('-3 months'));
            $amazonOrderData = $amazonOrderIdsCollection->aggregate(
                [
                    [
                        '$match' => [
                            'user_id' => ['$ne' => null],
                            'home_shop_id' => ['$ne' => null],
                            'source_order_id' => ['$ne' => null],
                            'created_at' => ['$lte' => $deleteDate]
                        ]
                    ]
                ],
                ["typeMap" => ['root' => 'array', 'document' => 'array'], 'allowDiskUse' => true]
            )->toArray();
            if (!empty($amazonOrderData)) {
                $uniqueIds = array_column($amazonOrderData, '_id');
                $idChunks = array_chunk($uniqueIds, 100000);
                foreach ($idChunks as $id) {
                    $amazonOrderIdsCollection->deleteMany(['_id' => ['$in' => $id]]);
                }
                $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> ' . count($uniqueIds) . ' Orders Data deleted from amazon_order_ids collection!!</>');
            }
            return 1;
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception: RemovePIDataCommand, Error: ' . print_r($e->getMessage()), 'info', 'exception.log');
            return 0;
        }
    }
}
