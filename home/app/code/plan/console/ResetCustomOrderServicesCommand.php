<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use App\Plan\Models\Plan as PlanModel;

class ResetCustomOrderServicesCommand extends Command
{

    protected static $defaultName = "plan:renew-custom-order-services";

    protected static $defaultDescription = "Reset Custom Order Services";

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
        $this->setHelp('Reset Custom Order Services');
    }

    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        print_r("Executing command to Reset Custom Order Services \n");
        $planModel = $this->di->getObjectManager()->get(PlanModel::class);
        $planResponse = $planModel->resetCustomOrderServices();
        echo 'reset custom order services' . PHP_EOL;
        print_r($planResponse);
        return true;
    }
}
