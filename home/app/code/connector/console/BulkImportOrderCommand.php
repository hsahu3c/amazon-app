<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BulkImportOrderCommand extends Command
{

    protected static $defaultName = "bulk-import";

    protected static $defaultDescription = "To Import Bulk Orders from Amazon";
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
        $this->setHelp('Sync target order from Shopify.');
        $this->addOption("user_id", "u", InputOption::VALUE_REQUIRED, "user_id of the user");
        $this->addOption("shop_id", "s", InputOption::VALUE_REQUIRED, "Amazon Home Shop Id of the user");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;
        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Initiating Order Import...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");

        $userId = $input->getOption("user_id") ?? false;
        $shopId = $input->getOption('shop_id') ?? false;
        if (!$userId || !$shopId) {
            $output->writeln('➤ </><options=bold;fg=red> ERROR: Userid and Shopid Field is mandatory</>');
            return 0;
        }

        $prepareData = [
            'user_id' => $userId,
            'shop_id' => $shopId,
            'marketplace' => 'amazon'
        ];
        $this->bulkImport($prepareData);
        return 0;
    }

    public function bulkImport(array $data): int
    {

        $orderIds = ['397-1011838-2111456546565', '111-333-444-5555'];
        if (!empty($orderIds)) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $query = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
            foreach ($data as $userId) {
                $params = [];
                $params[] = ['$match' => ['user_id' => $userId]];
                $params[] = ['$project' => ['_id' => 0, 'user_id' => 1, 'shops._id' => 1, 'shops.marketplace' => 1]];
                $result = $query->aggregate(
                    $params,
                    ["typeMap" => ['root' => 'array', 'document' => 'array']]
                )->toArray();
                if (!empty($result)) {
                    $source = [];
                    $target = [];
                    foreach ($result['0']['shops'] as $shop) {
                        if ($shop['marketplace'] == "shopify") {
                            $source = [
                                'marketplace' => $shop['marketplace'],
                                'shopId' => $shop['_id']
                            ];
                            $target = [
                                'marketplace' => $data['marketplace'],
                                'shopId' => $data['shop_id']
                            ];
                            break;
                        }
                    }

                    $response = $this->setDiForUser($userId);
                    if (isset($response['success']) && $response['success']) {
                        foreach ($orderIds as $id) {
                            $rawBody = [];
                            $rawBody = [
                                'source' => $source,
                                'target' => $target,
                                'source_order_ids' => $id
                            ];
                            if (isset($rawBody['target']['marketplace'])) {
                                $code = $rawBody['target']['marketplace'];
                                $rawBody['code'] = $code;
                                $containerModel =  new \App\Connector\Models\Order;
                                $responseData = $containerModel->importOrderFromMarketpalce($rawBody);
                                if (!$responseData['success']) {
                                    if (isset($responseData['message']) && !isset($responseData['data']['message'])) {
                                        $reason = ', Reason =>' . $responseData['message'];
                                        $this->output->writeln("➤ </><options=bold;fg=red>OrderId: {$id}{$reason}</>");
                                    } elseif (isset($responseData['data']['message'])) {
                                        $reason = ', Reason =>' . $responseData['data']['message'];
                                        $this->output->writeln("➤ </><options=bold;fg=red>OrderId: {$id}{$reason}</>");
                                    } else {
                                        $encodeData = json_encode($responseData, 128);
                                        $reason = ",Reason =>" . json_encode($encodeData);
                                        $this->output->writeln("➤ </><options=bold;fg=red>OrderId: {$id}{$reason}</>");
                                    }
                                } else {
                                    $message = 'Synced to Shopify Successfully';
                                    $this->output->writeln("➤ </><options=bold;fg=green>OrderId: {$id} {$message}</>");
                                }
                            } else {
                                $reason = 'User_id : ' . $userId . 'Target does not exists';
                                $this->output->writeln("➤ </><options=bold;fg=green> {$reason}</>");
                            }
                        }
                    }
                }
            }
        } else {
            $this->output->writeln('➤ </><options=bold;fg=red> ERROR: Atleast one Order_id Required</>');
        }

        return 0;
    }

    public function setDiForUser($userId): array
    {
        $flag = true;
        $message = '';
        try {
            $getUser = \App\Core\Models\User::findFirst([['_id' => $userId]]);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            $flag = false;
            $message = 'User not found.';
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            $flag = false;
            $message = 'User not found in DB. Fetched di of admin.';
        }

        if (!$flag) {
            return [
                'success' => false,
                'message' => $message
            ];
        }

        return [
            'success' => true,
            'message' => 'User set in di successfully'
        ];
    }
}