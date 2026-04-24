<?php

use App\Amazon\Components\ServerlessHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Connector\Components\Dynamo;
use Aws\DynamoDb\Marshaler;

class RemoveServerlessDataCommand extends Command
{

    protected static $defaultName = "remove-serverless-info";
    protected $di;
    protected static $defaultDescription = "Remove Serverless Data";

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
        $this->setHelp('Remove data from Dynamo tables of uninstalled or disconnected users');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute($input, $output): int
    {
        $output->writeln('<options=bold;bg=cyan> ➤ Initiating Data Deletion Process...</>');
        $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
        $dynamoClientObj = $dynamoObj->getDetails();
        $marshaler = new Marshaler();
        $params = [
            'TableName' => "amazon_account_delete"
        ];
        $connectionData = $dynamoClientObj->scan($params);
        $items = [];
        foreach ($connectionData['Items'] as $item) {
            $items[] = $marshaler->unmarshalItem($item);
        }

        $count = 0;
        if (!empty($items)) {
            $helper = $this->di->getObjectManager()->create(ServerlessHelper::class);
            foreach ($items as $value) {
                if ($count > 50) {
                    break;
                }

                $count++;
                $helper->handleDelete($value);
            }
        }

        $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Process Completed!!</>');
        return 1;
    }
}
