<?php

use App\Plan\Components\Email;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class NotifySellersCommand extends Command
{

    protected static $defaultName = "plan:notifications";

    protected static $defaultDescription = "Notifications For Plans";

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
        $this->setHelp('To notify sellers for their plan related updates');
        $this->addOption(
            "function",
            "f",
            InputOption::VALUE_REQUIRED,
            "run function",
            ""
        );
        $this->addOption(
            "user",
            "u",
            InputOption::VALUE_OPTIONAL,
            "user id",
            ""
        );
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        print_r("Executing command to notify sellers \n");
        $f = $input->getOption("function") ?? "";
        if(empty($f)) {
            echo 'Provide a valid function!';
            return false;
        }

        $this->$f($input);
        return true;
    }

    public function notifySellersForPlanDeactivation($input): void
    {
       print_r("Executing command for notifying sellers for plan deactivation\n");
       $user = $input->getOption("user") ?? false;
       $email = $this->di->getObjectManager()->get(Email::class);
       $email->notifySellersForPlanDeactivation($user);
       print_r("Executed successfully!");
    }

    public function notifySellersForPlanPause($input): void
    {
       print_r("Executing command for notifying sellers for their plan pause\n");
       $user = $input->getOption("user") ?? false;
       $email = $this->di->getObjectManager()->get(Email::class);
       $email->notifySellersForPlanPause($user);
       print_r("Executed successfully!");
    }
}
