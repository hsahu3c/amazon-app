<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class synchronizeSkuCommand extends Command
{

    protected static $defaultName = "sync:product-sku";

    protected static $defaultDescription = "Command for synchronizing product SKUs from a source shop.";
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
     *
     * @return void
     */
    protected function configure()
    {
        $this->addOption("source_shop_id", "s", InputOption::VALUE_REQUIRED, "source_shop_id of user");
        $this->addOption("source_marketplace", "m", InputOption::VALUE_REQUIRED, "source_marketplace of user");
        $this->addOption("limit", "l", InputOption::VALUE_OPTIONAL, "limit of products to process at a time");
        $this->addArgument("type", InputArgument::OPTIONAL, "userWise or all [type all for all user]", false);
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Starting SKU synchronization...</>');
        $sourceShopId = $input->getOption("source_shop_id") ?? false;
        $sourceMarketplace = $input->getOption("source_marketplace") ?? false;
        $userWise = false;
        $limit = $input->getOption("limit") ?? 250;
        $type = $input->getArgument("type");
        if (!$type) {
            $userWise =  true;
        }

        if (!$sourceMarketplace) {
            $this->printError($output, "source_marketplace field is mandatory");
            return 0;
        }

        if ($userWise && !$sourceShopId) {
            $this->printError($output, "source_shop_id field is mandatory");
            return 0;
        }

        if ($userWise && !$this->findUser($sourceShopId)) {
            $this->printError($output, "User not found");
            return 0;
        }

        if ($type === 'all') {
            $this->processAllUsers($output, $sourceMarketplace, $limit);
        } else {
            $this->processSingleUser($sourceShopId, $sourceMarketplace, $limit, $output);
        }

        $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Completed</>');

        return 0;
    }

    private function processAllUsers(\Symfony\Component\Console\Output\OutputInterface $output, $sourceMarketplace, $limit): void
    {
        $allUsers = $this->getAllUsers($sourceMarketplace);
        foreach ($allUsers as $user) {
            $this->setUser($user);
            $sourceShopIds = array_column($user->shops, '_id', 'marketplace');
            $sourceShopId = $sourceShopIds[$sourceMarketplace];
            if ($sourceShopId) {
                $this->processSingleUser($sourceShopId, $sourceMarketplace, $limit, $output);
            }
        }
    }

    private function processSingleUser($sourceShopId, $sourceMarketplace,  $limit, \Symfony\Component\Console\Output\OutputInterface $output): void
    {
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> user : ' . $this->di->getUser()->id . '</>');
        $this->di->getObjectManager()->get('App\Connector\Components\Route\ProductRequestcontrol')->finalizeSku(['shop_id' => $sourceShopId, 'marketplace' => $sourceMarketplace, 'limit' => $limit]);
    }

    private function findUser($source_shop_id): bool
    {
        $user = \App\Core\Models\User::findFirst([['shops._id' =>  $source_shop_id]]);
        if ($user) {
            $this->setUser($user);
            return true;
        }

        return false;
    }

    private function getAllUsers($source_marketplace)
    {
        return  \App\Core\Models\User::find([['shops.marketplace' =>  $source_marketplace]]);
    }

    private function setUser($user): void
    {
        $user->id = (string) $user->_id;
        $this->di->setUser($user);
    }

    private function printError(OutputInterface $output, string $errorMessage): void
    {
        $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR:' . $errorMessage . '</>');
    }
}
