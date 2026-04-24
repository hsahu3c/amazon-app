<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Shopifyhome\Components\Product\Helper;

#[\AllowDynamicProperties]
class CustomMetafieldSyncCommand extends Command
{

    protected static $defaultName = "shopify:product-custom-metafield-sync";

    protected static $defaultDescription = "Syncing Products Metafields from Shopify which are updated";
    
    const METAFIELD_FILTER_TYPE = "metafieldsync";

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
        $this->setHelp('Sync products metafield from shopify based on time period');
        $this->addOption("app_tag", "a", InputOption::VALUE_REQUIRED, "app_tag");
        $this->addOption("hours", "t", InputOption::VALUE_REQUIRED, "hours");
        $this->addOption("minutes", "m", InputOption::VALUE_OPTIONAL, "minutes", 0);
        $this->addOption("priority_users", "p", InputOption::VALUE_OPTIONAL, "priority_users");
        $this->addOption("beta_users", "b", InputOption::VALUE_OPTIONAL, "beta_users");
        $this->addOption("all_users", "u", InputOption::VALUE_OPTIONAL, "all_users");
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->appTag = $input->getOption("app_tag") ?? false;
        $this->hours = $input->getOption("hours") ?? false;
        $this->minutes = $input->getOption("minutes") ?? 0;
        $this->allUsers = $this->input->getOption("all_users") ?? false;
        $this->priorityUsers = $this->input->getOption("priority_users") ?? false;
        $this->betaUsers = $this->input->getOption("beta_users") ?? false;
        if (!$this->appTag || !$this->hours) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:app_tag(-a) and hours(-h) is required</>');
            return 0;
        }
        if (!$this->allUsers && !$this->priorityUsers && !$this->betaUsers) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:For which users process has to be initiated all_users(-u) or priority_users(-p) or beta_users(-b) any one is required</>');
            return 0;
        }
        $this->timeDelay = "-{$this->hours} hours -{$this->minutes} minutes";
        $this->initiateProcess();
        return 0;
    }

    /**
     * initiateProcess function
     * Initiating metafield syncing process
     * @return null
    */
    private function initiateProcess()
    {
        if ($this->betaUsers) {
            $users = $this->di->getConfig()->get('beta_metafield_sync_users') ? $this->di->getConfig()->get('beta_metafield_sync_users')->toArray() : [];
            if(!empty($users)) {
                $this->initiateMetafieldSyncUserWise($users);
            } else {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:No beta users found</>');
            }
        } elseif ($this->priorityUsers) {
            $users = $this->di->getConfig()->get('priority_metafield_sync_users') ? $this->di->getConfig()->get('priority_metafield_sync_users')->toArray() : [];
            if(!empty($users)) {
                $this->initiateMetafieldSyncUserWise($users);
            } else {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:No priority users found</>');
            }
        } elseif($this->allUsers) {
            $this->initiateMetafieldSyncForAllUsers();
        }
    }

    /**
     * initiateMetafieldSyncForAllUsers function
     * Initiating metafield syncing process for all users
     * @return null
    */
    private function initiateMetafieldSyncForAllUsers() {
        $object = $this->di->getObjectManager()->get(Helper::class);
        $params['app_tag'] = $this->appTag;
        $object->customMetafieldSyncCron($params);
    }

    /**
     * initiateMetafieldSyncUserWise function
     * Initiating metafield syncing process for priority users
     * @return null
    */
    private function initiateMetafieldSyncUserWise($users) {
        $metafieldObj = $this->di->getObjectManager()->get('App\Shopifyhome\Components\Product\Metafield');
        foreach($users as $user) {
            $params = [
                'user_id' => $user,
                'app_tag' => $this->appTag,
                'filter_type' => self::METAFIELD_FILTER_TYPE,
                'updated_at' => (new DateTime($this->timeDelay))->format('Y-m-d\TH:i:s\Z'),
                'metafield_stuck_check' => 2,
            ];
            $metafieldObj->initiateMetafieldSync($params);
        }
    }
}