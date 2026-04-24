<?php

use App\Amazon\Components\Price\Price;
use App\Amazon\Components\Report\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CustomPriceSettingCommand extends Command
{

    protected static $defaultName = "custom-price-duration-update";
    protected $di;
    protected static $defaultDescription = "Update custom price duration";

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
        $this->setHelp('Update custom price duration');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute($input, $output): int
    {
        print_r("Executing update custom price duration \n");
        $this->di->getObjectManager()-> get('App\Amazon\Components\Product\Price')->updateCustomPriceDuration();
        return 0;
    }
}
