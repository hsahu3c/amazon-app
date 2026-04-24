<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UserGetTokenCommand extends Command
{

    protected static $defaultName = "user:get-token";
    protected static $defaultDescription = "Get token for a user";
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
        $this->setHelp('Generate token for user by role');
        $this->addOption("userrole", "u", InputOption::VALUE_REQUIRED, "Role to generate token for", "admin");
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
        $user = $input->getOption('userrole');
        $user = \App\Core\Models\User::findFirst([["username" => $user]]);
        if ($user) {
            $output->writeln("<comment>" . $user->getToken('+365 days', false, false) . '</>');
        } else {
            $output->writeln('<error>Invalid user id</>');
        }
        return 0;
    }
}
