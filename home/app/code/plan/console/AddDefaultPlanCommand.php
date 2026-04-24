<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use App\Plan\Models\Plan as PlanModel;

class AddDefaultPlanCommand extends Command
{

    protected static $defaultName = "add-default-plan";

    protected static $defaultDescription = "Add Amazon Default Plan";
    
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
        $this->setHelp('Add Amazon Default Plan');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        print_r("Executing command to Add Amazon Default Plan \n");
        $response = $this->di->getObjectManager()->get(PlanModel::class)->addAmazonDefaultPlan();
        $this->di->getLog()->logContent('MESSAGE: ' . json_encode($response, 128), 'info', 'plan/recurringCheck1.log');
        $output->writeln($response['message']);
        return 0;
    }
}
