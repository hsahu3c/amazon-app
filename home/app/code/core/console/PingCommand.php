<?php

use Phalcon\Di\FactoryDefault;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PingCommand extends Command
{

    protected static $defaultName = "ping";
    protected static $defaultDescription = "ping redis and mongo server";
    protected FactoryDefault $di;

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
        $this->setHelp('Pings redis and mongo server for latency');
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
        $latency = $this->di->getObjectManager()->get('\App\Core\Components\Ping')->pingBoth();
        foreach ($latency as $key => $val) {
            $output->writeln("<comment>{$key} took {$val}</>");
        }
        return 0;
    }
}
