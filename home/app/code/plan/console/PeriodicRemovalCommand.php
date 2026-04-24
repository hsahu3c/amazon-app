<?php

use App\Plan\Models\BaseMongo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PeriodicRemovalCommand extends Command
{

    protected static $defaultName = "plan:remove-data";

    protected static $defaultDescription = "Periodic Data removal for plans";

    protected $di;
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
        $this->setHelp('To remove data for payment details');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        print_r("Executing command for removing unneccasary payment data\n");
        $uninstallPaymentDetailsCollection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollectionForTable('payment_details_uninstalled_users');
        $transactionDetailsCollection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollectionForTable('transaction_details');
        $marginDate = date('Y-m-d', strtotime('-12months'));
        $filter = [
         'created_at' => ['$lt' => $marginDate]
        ];
        $uninstalRes = $uninstallPaymentDetailsCollection->deleteMany($filter);
        $transRes = $transactionDetailsCollection->deleteMany($filter);
        $this->di->getLog()->logContent(
         'Count of Data removed from payment_details_uninstalled_users: '.$uninstalRes->getDeletedCount(),
         'info',
         'plan/data-remove.log'
         );
         $this->di->getLog()->logContent(
             'Count of Data removed from transaction_details: '.$transRes->getDeletedCount(),
             'info',
             'plan/data-remove.log'
         );
        print_r("Executed successfully!");
    }
}
