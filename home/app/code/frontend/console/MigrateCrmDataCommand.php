<?php

use App\Frontend\Components\AmazonMulti\CrmHelper;
use Symfony\Component\Console\Command\Command;
use App\Core\Components\Message\Handler\Sqs as SQSHandler;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection as MongoCollection;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCrmDataCommand extends Command
{

    protected static $defaultName = "crm:migrate-data";

    protected static $defaultDescription = "Migrate updated user data to crm";

    protected $di;

    protected SQSHandler $sqsHandler;

    protected InputInterface $inputHelper;

    protected OutputInterface $outputHelper;

    protected ?int $currentPage = null;

    private const MAX_USERS = 2500;

    private const MAX_MESSAGES_PER_BATCH = 9;


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
        $this->sqsHandler = $this->di->getObjectManager()->get(SQSHandler::class);
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Share updated user data with crm');
        $this->addOption(
            "user_id",
            "u",
            InputOption::VALUE_OPTIONAL,
            "user_id for which orders need to be synced",
            "all"
        );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->inputHelper = $input;
        $this->outputHelper = $output;

        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating CRM data sync!</>');
        $this->processConnectedShopUsers();

        return self::SUCCESS;
    }

    public function processConnectedShopUsers($lastTraversedUserObjectId = null)
    {
        $userDetailsDataCollection = $this->getMongoCollection('user_details');

        $this->outputHelper->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Fetching users!</>');

        // $userIds = [
        //     "63905cdd6c4bf40bda24f7a4",
        //     "614bb96d6d38fc15cb5b338a",
        //     "614ddd72f3a6c369c63a6325",
        //     "652c9d0e14a6c08c37084c87",
        //     "64ef10bc037ce59e0c04d012",
        //     "63fc49697de95f4288076ee5",
        //     "64402896ec6b4f14a001a8e9",
        //     "66f50ee02f4c93360e019c02",
        //     "61ae8309461fcc7fdf663f62",
        //     "628e2019b671730033784445",
        //     "635800df47c22928e72b8735",
        //     "63c1a9ea784c4db10908fd95",
        //     "64a716d33f3e9b93510bffa3",
        //     "63f7080d8e2dd7061b036e82",
        //     "63e480312e7ffcf24d0d39a2",
        //     "63fd12cc5c2093655804f566",
        //     "63fcf8ede512cc779f0eed34",
        //     "63fd5b743e45208af2012812",
        //     "63e0062ce71ae348c00ce5f2",
        //     "611d560abe46fa53002e50e8",
        //     "611d560abe46fa53002e50e8",
        //     "661da29a979fcad1ab02e035"
        // ];

        $userIds = $this->getMongoCollection('payment_details')->distinct('user_id', ['user_id' => ['$ne' => null], 'type' => 'active_plan', 'status' => 'active', 'plan_details.custom_price' => ['$gt' => 0]]);

        $userDetailsData = $userDetailsDataCollection->find(['user_id' => ['$in' => $userIds]], ['projection' => ['_id' => 1, 'user_id' => 1]])->toArray();
        $this->processUserDetails($userDetailsData);
        return 0;


        $this->currentPage = 1;
        while (false) {
            $this->outputHelper->writeln("<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing page {$this->currentPage}!</>");
            $this->currentPage++;

            $options = [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
                'limit' => self::MAX_USERS,
                'projection' => ['_id' => 1, 'user_id' => 1],
                'sort' => ['_id' => 1]
            ];

            $query = $this->buildQuery($lastTraversedUserObjectId);

            try {
                $userDetailsData = $userDetailsDataCollection->find($query, $options)->toArray();
            } catch (\Exception $e) {
                $this->outputHelper->writeln("<error>Error fetching users: {$e->getMessage()}</error>");
                break;
            }

            if (empty($userDetailsData)) {
                $this->outputHelper->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> No more users found!</>');
                break; // Exit the loop if no users are found
            }

            $this->processUserDetails($userDetailsData);

            $lastUser = end($userDetailsData);
            $lastTraversedUserObjectId = $lastUser['_id'] ?? null;
        }

        $this->outputHelper->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> All users processed!</>');
        return ['success' => true];
    }

    private function buildQuery(?ObjectId $lastTraversedUserObjectId): array
    {
        $query = [
            'user_id' => ['$exists' => true],
            'shops' => ['$exists' => true],
            'shops.marketplace' => 'amazon'
        ];

        if ($lastTraversedUserObjectId !== null) {
            $query['_id'] = ['$gt' => $lastTraversedUserObjectId];
        }

        return $query;
    }

    private function processUserDetails(array $userDetailsData): void
    {
        $this->outputHelper->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Pushing users to SQS!</>');
        $handlerData = [];

        foreach ($userDetailsData as $user) {
            $handlerData[] = [
                'type' => 'full_class',
                'method' => 'migrateCrmData',
                'class_name' => CrmHelper::class,
                'user_id' => $user['user_id'],
                'queue_name' => 'migrate_crm_data',
                'appTag' => 'amazon_sales_channel'
            ];

            if (count($handlerData) === self::MAX_MESSAGES_PER_BATCH) {
                $this->pushMessagesBatch($handlerData);
                $handlerData = [];
            }
        }

        if (!empty($handlerData)) {
            $this->pushMessagesBatch($handlerData);
        }
    }

    public function pushMessagesBatch(array $handlerData)
    {
        try {
            $this->sqsHandler->pushMessagesBatch($handlerData);
        } catch (\Exception $e) {
            $this->outputHelper->writeln("<error>Error pushing messages: {$e->getMessage()}</error>");
        }
    }

    public function getMongoCollection(string $collection): MongoCollection
    {
        return $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollection($collection);
    }
}
