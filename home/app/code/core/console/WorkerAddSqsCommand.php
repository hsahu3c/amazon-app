<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class WorkerAddSqsCommand extends Command
{

    protected static $defaultName = "worker:add-sqs";
    protected static $defaultDescription = "add a new worker sqs";
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
        $this->setHelp('Add a new worker');
        $this->addArgument("queue", InputArgument::REQUIRED, "queue name");
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
        $queueName = $input->getArgument("queue");
        return $this->di->getObjectManager()
            ->get('\App\Core\Components\Message\Handler\Sqs')
            ->consume($queueName, true)["success"];
    }
}
