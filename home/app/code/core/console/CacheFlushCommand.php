<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CacheFlushCommand extends Command
{
    protected static $defaultName = "cache:flush";
    protected static $defaultDescription = "Flush the cache";
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
        $this->setHelp('Flush the cache');
        $this->addArgument("install", InputArgument::OPTIONAL, "install or Cache type [ 'all' to flush all cache]", false);
        $this->addArgument("type", InputArgument::OPTIONAL, "(if install specified) cache type [ 'all' to flush all cache]", false);
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
            $type = $input->getArgument("install");
        }
        if (!$type || $type === "install") {
            $this->di->getCache()->flush();
            $output->writeln('<info>Default cache has been flushed</>');
        } elseif ($type == "all") {
            $this->di->getCache()->flushAll();
            $output->writeln('<info>All cache has been flushed</>');
        } else {
            $this->di->getCache()->flushByType($type);
            $output->writeln("<info>{$type} cache has been flushed</>");
        }
        return 0;
    }
}
