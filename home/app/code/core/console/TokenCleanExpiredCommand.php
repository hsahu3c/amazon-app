<?php

use App\Core\Components\Setup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TokenCleanExpiredCommand extends Command
{

    protected static $defaultName = "token:clean-expired";
    protected static $defaultDescription = "Clean expired tokens";
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
    }
    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Clean expired tokens');
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
        $manager = $this->di->getObjectManager()->get('\App\Core\Components\TokenManager');
        $manager->cleanExpiredTokens();
        return 0;
    }
}
