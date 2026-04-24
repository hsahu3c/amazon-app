<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProductActivityDeleteCommand extends Command
{

    protected static $defaultName = "product-activity-delete";

    protected static $defaultDescription = "Delete last 7 days of data in product_activity table";
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
        // $this->setHelp('Sync target order from Shopify.');
        // $this->addOption("user_id", "u", InputOption::VALUE_REQUIRED, "user_id of the user");
        // $this->addOption("shop_id", "s", InputOption::VALUE_REQUIRED, "Amazon Home Shop Id of the user");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $product_activity = $mongo->getCollectionForTable('product_activity');

        $date = date('d-m-Y', strtotime('-7 days'));

        $this->objectIdFromDate($date);

        $res = $product_activity->deleteMany(['_id' => ['$lte' => new \MongoDB\BSON\ObjectId($lteId)]]);


        return 0;
    }

    function objectIdFromDate($dateStr): string
    {

        $timestamp = strtotime($dateStr);
        $hexId = dechex($timestamp);
        $hexId = str_pad($hexId, 8, '0', STR_PAD_LEFT);
        return $hexId . '0000000000000000';
    }
}
