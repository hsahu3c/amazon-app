<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check status for user || shop || app
 * argument : user_id, shop_id, app_id
 */
class CheckStatusCommand extends Command
{

    protected static $defaultName = "check-status";
    protected static $defaultDescription = "Check user, shop or app status";
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
        $this->setHelp('Check user, shop, and app status <comment>e.g: check-status <user_id> [shop_id] [app_code]');
        $this->addArgument("user_id", InputArgument::REQUIRED, "<required> your user_id");
        $this->addArgument("shop_id", InputArgument::OPTIONAL, "<optional> _id of your shop");
        $this->addArgument("app_code", InputArgument::OPTIONAL, "<optional> app_code of your app");
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
        $output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Check status for user, shop or app. </>");
        $output->writeln("<comment>e.g: check-status <user_id> [shop_id] [app_code]");

        if ($input->getArgument("user_id")) $params['user_id'] = $input->getArgument("user_id");
        if ($input->getArgument("shop_id")) $params['shop_id'] = $input->getArgument("shop_id");
        if ($input->getArgument("app_code")) $params['app_code'] = $input->getArgument("app_code");

        $response = $this->di->getObjectManager()->get('\App\Core\Components\Message\Handler\Sqs')->checkStatus($params);

        if ($response['success']) {
            $output->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=green>" . $response['message'] . "</>");
        } else {
            $output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red>" . $response['message'] . "</>");
        }
        return 0;
    }
}
