<?php

use App\Amazon\Components\Report\Report;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SellerPerformanceCommand extends Command
{

    protected static $defaultName = "seller-performance";
    protected $di;
    protected static $defaultDescription = "initiate Seller Performance";

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
        $this->setHelp('initiate Seller Performance');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute($input, $output): int
    {
        print_r("Executing command to Fetch Seller Perfromance Data \n");
        $this->di->getObjectManager()-> get(Report::class)->fetchReport();
        return 0;
    }
}
