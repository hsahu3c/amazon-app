<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CacheListCommand extends Command
{
    protected static $defaultName = "cache:list";
    protected static $defaultDescription = "List all keys in cache";
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
        $this->setHelp('List all keys in cache');
        $this->addArgument("type", InputArgument::OPTIONAL, "[cache type]", false);
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
        $type = $input->getArgument("type");
        if (!$type) {
            print_r($this->di->getCache()->getAll());
        } else {
            print_r($this->di->getCache()->getAll($type));
        }
        return 0;
    }
}
