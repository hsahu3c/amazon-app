<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UserCreateCommand extends Command
{

    protected static $defaultName = "user:create";
    protected static $defaultDescription = "Create new user";
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
        $this->setHelp('Create new user');
        $this->addOption("username", "u", InputOption::VALUE_REQUIRED, "Username", "");
        $this->addOption("email", "e", InputOption::VALUE_REQUIRED, "Email", "");
        $this->addOption("password", "p", InputOption::VALUE_REQUIRED, "password", "");
        $this->addOption("type", "t", InputOption::VALUE_REQUIRED, "User role", "");
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
        $email = $input->getOption("email");
        $type = $input->getOption("type");
        $userModel = $this->di->getObjectManager()->create('\App\Core\Models\User');
        $response = $userModel->createUser([
            'username' => $user,
            'email' => $email,
            'password' => $password,
        ], $type);
        if ($response['success']) {
            $output->writeln('<info>User Created Successfully..</>');
        } else {
            $output->writeln('<error>' . implode("\n", $response['data']['errors']) . '</>');
        }
        return 0;
    }
}
