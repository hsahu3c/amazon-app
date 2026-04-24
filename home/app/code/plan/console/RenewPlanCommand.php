<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use App\Plan\Models\Plan as PlanModel;

class RenewPlanCommand extends Command
{

    protected static $defaultName = "plan:renew-plan";

    protected static $defaultDescription = "Renew Plan";

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
        $this->setHelp('Renew Plan');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        print_r("Executing command to Renew PLan \n");
        $planModel = $this->di->getObjectManager()->get(PlanModel::class);
        $planResponse = $planModel->renewPlanByCron();
        echo 'renew plans' . PHP_EOL;
        print_r($planResponse);
        return true;
    }
}
