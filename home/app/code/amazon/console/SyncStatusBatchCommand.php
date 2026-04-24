<?php

use App\Amazon\Components\Report\Report;
use App\Amazon\Components\Report\SyncStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncStatusBatchCommand extends Command
{
    protected static $defaultName = "sync-status-batch";
    protected $di;
    protected $mongoConnection;
    protected static $defaultDescription = "initiate sync status in batch";

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
        $this->mongoConnection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getConnection();
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Sync status from Amazon in batches.');
        $this->addOption("details", "d", InputOption::VALUE_OPTIONAL, "[display errors/notices]", "n");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute($input, $output)
    {
        try {
            $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating sync status...</>');
            $output->writeln("<options=bold;bg=blue> ➤ </> .....................");
            $res = $this->di->getObjectManager()->get(SyncStatus::class)->syncStatusBatchCrone();
            $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=green>Completed...</>');
        } catch (Exception $e) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ' . $e->getMessage() . '...</>');
        }

        return 0;
    }
}
