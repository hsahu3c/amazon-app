<?php

use App\Core\Components\Setup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SetupClearNotificationsCommand extends Command
{
    protected static $defaultName = "setup:clear-notifications";
    protected static $defaultDescription = "Clear the notifications";
    protected Setup $setup;
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
        $this->setup = $this->di->getObjectManager()->get(Setup::class);
    }
    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Clear notifications');
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
        $this->setup->clearNotificationsAction();
        return 0;
    }
}
