<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppDeleteCommand extends Command
{

    protected static $defaultName = "app-delete";

    protected static $defaultDescription = "Delete uninstalled apps";
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
        $this->setHelp('Delete uninstalled apps');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        print_r("Executing command to delete Uninstalled apps \n");
        $this->di->getObjectManager()-> get("\App\Connector\Components\Hook")->appDelete();
        return 0;
    }
}
