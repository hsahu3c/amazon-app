<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SetupDisableModuleCommand extends Command
{

    protected static $defaultName = "setup:disable-module";
    protected static $defaultDescription = "Disable a module";
    protected $setup;
    private \Phalcon\Di\FactoryDefault $di;
    /**
     * Constructor
     * Calls parent constructor and sets the di
     *
     * @param $di
     */
    public function __construct(\Phalcon\Di\FactoryDefault $di)
    {
        parent::__construct();
        $this->di = $di;
        $this->setup = $this->di->getObjectManager()->get("App\Core\Components\Setup");
    }
    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Disable the module');
        $this->addArgument('module', InputArgument::REQUIRED, 'Name of the module');
    }
    /**
     * The main logic to execute when the command is run
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $module = $input->getArgument("module");
        $this->setup->disableAction([$module]);
        return 0;
    }
}
