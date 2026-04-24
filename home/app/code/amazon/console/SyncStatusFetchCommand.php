<?php

use App\Amazon\Components\Report\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncStatusFetchCommand extends Command
{

    protected static $defaultName = "sync-status-fetch";
    protected $di;
    protected static $defaultDescription = "initiate Sync Status fetch-report operation";

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
        $this->setHelp('initiate Sync Status fetch-report');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute($input, $output): int
    {
        print_r("Executing command to initiate Sync Status fetch report \n");
        $this->di->getObjectManager()-> get(Helper::class)->initiateFetchReport();
        return 0;
    }
}
