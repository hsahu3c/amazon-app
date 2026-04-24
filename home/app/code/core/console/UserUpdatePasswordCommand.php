<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserUpdatePasswordCommand extends Command
{

    protected static $defaultName = "user:update-password";
    protected static $defaultDescription = "Update user password";
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
        $this->setHelp('Update user password');
        $this->addOption("username", "u", InputOption::VALUE_REQUIRED, "Username", "");
        $this->addOption("password", "p", InputOption::VALUE_REQUIRED, "password", "");
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
        $user = $input->getOption("username");
        $password = $input->getOption("password");
        $user = \App\Core\Models\User::findFirst([["username" => $user]]);
        $hash = $user->getHash($password);
        $user->setPassword($hash);
        $user->save();
        $output->writeln('Updated Password to : <comment>' . $password . "</>");
        return 0;
    }
}
