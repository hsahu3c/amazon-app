<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AccountCommand extends Command
{

    protected static $defaultName = "account";
    protected static $defaultDescription = "do accounts related stuff :-)";

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
        $this->setHelp('Configure user accounts through cli.');
        $this->addOption("status", "s", InputOption::VALUE_OPTIONAL, "[Status type]", "activate");
        $this->addOption("username", "u", InputOption::VALUE_OPTIONAL, "[Username or list of usernames]", "all");
        $this->addArgument("sub", InputArgument::OPTIONAL, "a user is of sub user type", false);
    }
    /**
     * The main logic to execute when the command is run
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $account  = $this->di->getObjectManager()
            ->get('\App\Core\Components\Account')
            ->changeStatus(
                $input->getOption("username"),
                $input->getArgument("sub"),
                $input->getOption("status")
            );

        foreach ($account['result'] as $arr) {
            $username = array_keys($arr)[0];
            if (array_values($arr)[0] == 'activated') {
                $output->writeln(
                    "<options=bold;bg=bright-green> ➤ </> user $username <options=bold;bg=green> Activated </>"
                );
            } else {
                $output->writeln(
                    "<options=bold;bg=black> ➤ </> user $username <options=bold;bg=black> Deactivated </>"
                );
            }
        }
        foreach ($account['errors'] as $username) {
            $output->writeln(
                "<options=bold;bg=bright-white> ➤ </><bg=red> user $username not found </>"
            );
        }
        return true;
    }
}
