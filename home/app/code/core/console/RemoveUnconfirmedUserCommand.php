<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check status for user || shop || app
 * argument : user_id, shop_id, app_id
 */
class RemoveUnconfirmedUserCommand extends Command
{

    protected static $defaultName = "rm-unconfirmeduser";
    protected static $defaultDescription = "Check and remove unconfirmed user which exceeds their confirmation  period.";
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
        $this->setHelp('Remove Unconfirmed user <comment>e.g: rm-user [user_id]');
        $this->addArgument("user_id", InputArgument::OPTIONAL, "user_id");
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
        $output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Removing unconfirmed user... </>");
        $params = [];
        if ($input->getArgument("user_id")) {
            $params['user_id'] = $input->getArgument("user_id");
        }
        $response = $this->di->getObjectManager()
            ->get('App\Core\Models\User')->removeUnconfirmedUser($params);
        if ($response['success']) {
            $output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=green> "
                . $response['message'] . "</>");
        } else {
            $output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red> " .
                $response['message'] . "</>");
        }
        return 0;
    }
}
