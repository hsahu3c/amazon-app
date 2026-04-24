<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CacheBlockCommand extends Command
{

    protected static $defaultName = "cache:block";
    protected static $defaultDescription = "Block the cacheType to prevent read/write operations at that cacheType.
    blocking a cacheType will make it unusable and flush all of it's contents.";
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
        $this->setHelp('Block the cacheType');
        $this->addArgument("cacheType", InputArgument::REQUIRED, "[cacheType to Block]");
        $this->addOption("duration", "d", InputOption::VALUE_OPTIONAL, "[duration of CacheType to be blocked]", "1800");
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
        $cacheType = $input->getArgument("cacheType");
        $ttl = $input->getOption("duration");
        $ttl = ctype_digit($ttl) ? (int) $ttl : 1800;
        if ($this->di->getCache()->blockCache($cacheType, $ttl)) {
            $output->writeln("<options=bold;bg=bright-green> ➤ </> <options=bold;fg=green>cache $cacheType Blocked for " . $ttl . " seconds </>");
            return 0;
        }
        $output->writeln("<options=bold;bg=bright-white> ➤ </><bg=red> some error occurred</>");
        return 0;
    }
}
