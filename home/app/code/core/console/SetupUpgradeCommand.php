<?php

use App\Core\Components\Setup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SetupUpgradeCommand extends Command
{

    protected static $defaultName = "setup:upgrade";
    protected static $defaultDescription = "Perform setup upgrade";
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
        $this->setHelp('Reload modules, config, etc and rebuild acl');
        $this->addArgument("install", InputArgument::OPTIONAL, "install", false);
        $this->addOption("currentApp",null, InputOption::VALUE_OPTIONAL, "[Current App Name]");
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
        $currentApp = $input->getOption("currentApp");
        if($currentApp){
            $this->di->getAppCode()->setCurrentApp($currentApp);
        }
        // if arg install is given remove the setup.hash file
        if ($input->getArgument("install") && is_file(BP . DS . "var" . DS . "setup.hash")) {
            unlink(BP . DS . "var" . DS . "setup.hash");
        }
        $this->setup->upgradeAction(true);
        return 0;
    }
}
