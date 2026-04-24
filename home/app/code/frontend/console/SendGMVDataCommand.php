<?php

use App\Frontend\Components\AmazonMulti\FreshSalesRequiredData;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SendGMVDataCommand extends Command
{

    protected static $defaultName = "send:gmv-data";

    protected static $defaultDescription = "send gmv data to fresh sales";

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
    }

      /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp( "send gmv data to fresh sales");
    }

    public function execute($input, $output){
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initatiation in progress!</>');
        $helper =  $this->di->getObjectManager()->get(FreshSalesRequiredData::class);
        $success =  $helper->sendGMVData();
        $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=white> '."data sent successfully".'</>');
        return 0;
    }
}
