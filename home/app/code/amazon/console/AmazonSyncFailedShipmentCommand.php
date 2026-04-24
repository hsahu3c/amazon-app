<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class AmazonSyncFailedShipmentCommand extends Command
{

    protected static $defaultName = "amazon-sync-failed-shipment";

    protected static $defaultDescription = "To reattempt failed shipments";
    protected $di;
    protected $input;
    protected $output;
    protected $appTag;

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
        $this->setHelp('Failed shipment attempt cron');
        $this->addOption("app_tag", "t", InputOption::VALUE_REQUIRED, "App tag");

    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Sync Failed Shipment Sync Process...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");
        $this->appTag = $input->getOption("app_tag") ?? false;
        if (!$this->appTag) {
            $this->output->writeln("➤ </><options=bold;fg=red>app_tag(-t) is mandatory</>");
            return 0;
        }
        $this->di->getAppCode()->setAppTag($this->appTag);
        $this->di->getObjectManager()->get(\App\Amazon\Components\Order\Shipment::class)
            ->initiateFailedShipmentSyncProcess();
        $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Process Completed</>');
        return 0;
    }
}
