<?php

use App\Shopifyhome\Components\Product\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

#[\AllowDynamicProperties]
class MetafieldSyncCommand extends Command
{

    protected static $defaultName = "sync:product-metafield";

    protected static $defaultDescription = "Syncing Products Metafields from Shopify";

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
        $this->setHelp('Sync products metafield from shopify');
        $this->addOption("app_tag", "t", InputOption::VALUE_REQUIRED, "app_tag");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Initiating Metafield Import...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");
        $appTag = $input->getOption("app_tag") ?? false;
        if (!$appTag) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:app_tag is required</>');
            return 0;
        }
        $params['app_tag'] = $appTag;
        $object = $this->di->getObjectManager()->get(Helper::class);
        $object->metafieldSyncCRON($params);
        $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green>Executed</>');
        return 0;
    }
}
