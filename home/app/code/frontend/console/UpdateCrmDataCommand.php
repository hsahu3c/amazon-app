<?php

use App\Frontend\Components\AmazonMulti\CrmHelper;
use Symfony\Component\Console\Command\Command;
use App\Core\Components\Message\Handler\Sqs as SQSHandler;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection as MongoCollection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCrmDataCommand extends Command
{

    protected static $defaultName = "crm:update-data";

    protected static $defaultDescription = "Share updated user data with crm";

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

        $this->currentPage = 1;
        while (true) {
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
                'method' => 'updateCrmData',
                'class_name' => CrmHelper::class,
                'user_id' => $user['user_id'],
                'queue_name' => 'update_crm_data',
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