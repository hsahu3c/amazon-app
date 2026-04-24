<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProductSyncCommand extends Command
{

    protected static $defaultName = "user:product-sync";
    protected $di;
    protected $mongoConnection;
    protected static $defaultDescription = "sync product data from source to app";

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
        $this->setHelp('Configure user details into dynamo.');
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute($input, $output)
    {

        $data = $this->di->getObjectManager()
            ->get('\App\Shopifyhome\Components\Product\Cron\CatalogSync')
            ->StartSyncingForProducts();
        print_r($data);
        return true;
    }
}
