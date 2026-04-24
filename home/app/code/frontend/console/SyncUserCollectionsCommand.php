<?php

use App\Frontend\Components\AdminpanelamazonmultiHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncUserCollectionsCommand extends Command
{

    protected static $defaultName = "sync:user-collections";

    protected static $defaultDescription = "Sync User Collections";

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
        $this->setHelp('Run collection sync for user_details and refine_user_details');
    }

    public function execute($input, $output){
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initatiation in progress!</>');
        $helper =  $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        $success =  $helper->syncUserCollections([]);
        $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=white> '.$success['message'].'</>');
        return 0;
    }
}
