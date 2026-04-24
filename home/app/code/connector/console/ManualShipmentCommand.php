<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ManualShipmentCommand extends Command
{

    protected static $defaultName = "manual-shipment";

    protected static $defaultDescription = "Manual Shipment of Orders";
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
        $this->setHelp('Sync target order from Shopify.');
        $this->addOption("type", "t", InputOption::VALUE_REQUIRED, "type");
        $this->addOption("user_id", "u", InputOption::VALUE_OPTIONAL, "user_id of user");
        $this->addOption("marketplace", "m", InputOption::VALUE_REQUIRED, "where to sync shipment details");
        $this->addOption("shop_id", "s", InputOption::VALUE_OPTIONAL, "shop_id of user");
        $this->addOption("source_created_at", "c", InputOption::VALUE_OPTIONAL, 'Date');
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
            $this->manualShipmentUserWise($userId);
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
     * @return void
     */
    public function manualShipmentOrderWise()
    {
        $orderIds = [
            // Provide OrderIds
        ];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('order_container');
        $marketplace = $this->input->getOption("marketplace") ?? false;
        if (!$marketplace) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:marketplace field is mandatory </>');
            return 0;
        }

        if (!empty($orderIds)) {
            $params = [
                'user_id' => ['$exists' => true],
                'object_type' => ['$in' => ['source_shipment', 'target_shipment']],
                'marketplace' => $marketplace,
                'marketplace_shop_id' => ['$exists' => true],
                'marketplace_reference_id' => ['$in' => $orderIds],
            ];
            $shipmentData = $query->find(
                $params,
                ["typeMap" => ['root' => 'array', 'document' => 'array']]
            )->toArray();
            if (!empty($shipmentData)) {
                $className = \App\Connector\Contracts\Sales\Order\ShipInterface::class;
                $shipmentService = $this->di->getObjectManager()
                    ->get($className, [], $marketplace);
                foreach ($shipmentData as $data) {
                    $shipmentService->ship($data);
                }
            } else {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>No data found</>');

            }
        } else {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>OrderIds array cannot be left blank</>');
        }
    }

    /**
     * manualShipmentUserWise function
     * To Manual Ship Orders Userwise
     * @param [type] $output
     * @return void
     */
    public function manualShipmentUserWise($userId)
    {
        $orderIds = [
            // Provide OrderIds of particular user
        ];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable('order_container');
        $marketplace = $this->input->getOption("marketplace") ?? false;
        $shopId = $this->input->getOption("shop_id") ?? false;
        if (!$marketplace || !$shopId) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:marketplace and shopId field is mandatory</>');
            return 0;
        }

        $params = [
            'user_id' => $userId,
            'object_type' => ['$in' => ['source_shipment','target_shipment']],
            'marketplace' => $marketplace,
            'marketplace_shop_id' => $shopId,
        ];
        if (!empty($orderIds)) {
            $params['marketplace_reference_id'] = ['$in' => $orderIds];
        } else {
            $createdAt = $this->input->getOption("source_created_at") ?? false;
            if (!$createdAt) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:source_created_at key mandatory</>');
                return 0;
            }

            $isDateValid = $this->isDateValid($createdAt);
            if (!$isDateValid['success']) {
                $this->output->writeln('➤ </><options=bold;fg=red> ERROR:' . $isDateValid['message'] . '</>');
                return 0;
            }

            $params['source_created_at'] = ['$gte' => $createdAt];
        }

        $shipmentData = $query->find(
            $params,
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        )->toArray();

        if (!empty($shipmentData)) {
            $className = \App\Connector\Contracts\Sales\Order\ShipInterface::class;
            $shipmentService = $this->di->getObjectManager()
                ->get($className, [], $marketplace);
            foreach ($shipmentData as $data) {
                $shipmentService->ship($data);
            }
        } else {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red>No Data found</>');
        }
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

        $explodedDate = explode($explodeNeedle, $sourceCreatedAt);
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
     */
    public function getDateNeedle($date): array
    {
        $explodeNeedle = '';
        if (strpos($date, '-') !== false) {
            $explodeNeedle = '-';
        }

        if ($explodeNeedle === '') {
            return ['success' => false, 'message' => 'Date format is not valid for this process'];
        }

        return ['success' => true, 'data' => $explodeNeedle];
    }
}