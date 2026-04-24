<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use App\Plan\Models\Settlement;

class RenewPostpaidCommand extends Command
{

    protected static $defaultName = "plan:renew-postpaid";

    protected static $defaultDescription = "Renew Postpaid";

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
        $this->setHelp('Renew Postpaid');
    }

    /**
     * The main logic to execute when the command is run
     * steps to perform charge deduction:
     * get the pending capped settlement_invoices
     * deduct the usage charges by setting the user in di getting the balance and as per that perforimng the deduction
     * once deduction at remote is done successfuly update the status of the settlement_invoice to on_hold if it is within the current month's last payment date
     * if the date is exceeded then close the settlement by approving it and manage the usage in monthly_usage
     * 
     * steps to renew the postpaid credits:
     * get the service with postpaid.capped_amount, has total_used_credits or previous_used_credits > 0, and the current date is greater than or euqal to the subscription_billing_date
     * for all such services get any pending or on_hold settlement_invoice
     * for pending invoice deduct the charges
     * for on_hold check if there is any to deduct
     * after checking, validating and clearing the charges
     * approve the settlement_invoice finally
     * and the reset the postpaid credits and update the subscription_billing_date either by increasing a month or getting the dae from the API
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        print_r("Executing command to Renew Postpaid credits and deduct usage charges \n");
        $settlementModel = $this->di->getObjectManager()->get(Settlement::class);
        $settlementResponse = $settlementModel->resetAndApprovePostpaidCredits();
        echo 'renew plans' . PHP_EOL;
        print_r($settlementResponse);
        $activatePostPaidCappingRes = $settlementModel->activatePostPaidCapping();
        echo 'renew plans' . PHP_EOL;
        print_r($activatePostPaidCappingRes);
        return true;
        return true;
    }
}
