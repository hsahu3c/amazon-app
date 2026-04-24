<?php

use App\Connector\Contracts\Sales\Order\RefundInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncRefundsCommand extends Command
{
    protected static $defaultName = "sync:amazon-refunds";
    protected $di;
    protected $mongoConnection;
    protected static $defaultDescription = "Sync Refund feeds from Amazon";

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
        $this->setHelp('Sync Refund feeds from Amazon.');
        $this->addOption("details", "d", InputOption::VALUE_OPTIONAL, "[display errors/notices]", "n");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute($input, $output)
    {
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Amazon refund feeds syncing...</>');
        $output->writeln("<options=bold;bg=blue> ➤ </> .....................");

        $amazonRefundService = $this->di->getObjectManager()->get(RefundInterface::class, [], 'amazon');
        $syncResponse = $amazonRefundService->initiateFeedSync();
        if (isset($syncResponse['message'])) {
            $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=green> ' . $syncResponse['message'] . '...</>');
        }

        if ($input->getOption("details") == 'y' || $input->getOption("details") == 'yes') {
            $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=yellow> ' . $syncResponse['totalFeedsProcessed'] . '...</>');
            if (isset($syncResponse['responses'])) {
                $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=white> unsuccessful responses: </>');
                foreach ($syncResponse['responses'] as $response) {
                    $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=red> ⎩━━━╾ ' . $response . '</>');
                }
            }
        }

        return 0;
    }
}
