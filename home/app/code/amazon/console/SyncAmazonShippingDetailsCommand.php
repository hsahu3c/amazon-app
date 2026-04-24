<?php

use App\Core\Models\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Amazon\Components\Common\Helper;
class SyncAmazonShippingDetailsCommand extends Command
{

    protected static $defaultName = "amazon-shipment-sync";
    protected $di;
    protected $mongoConnection;
    protected static $defaultDescription = "sync shipping details from Amazon to target";

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
        $this->mongoConnection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getConnection();
    }

    /**
     * Configuration for the command-
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('Configure user details into dynamo.');
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute($input, $output)
    {
        $users = $this->di->getConfig()->get('fba_order_shipment_sync_users');
        if (!empty($users)) {
            $users = $users->toArray();
            $reportType = 'GET_AMAZON_FULFILLED_SHIPMENTS_DATA_GENERAL';
            $startDate = date("Y-m-d", strtotime('-2 day'));
            $endDate = date("Y-m-d", strtotime('-1 day'));
            $marketplace = 'amazon';
            foreach ($users as $userId) {
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    $shops = $this->di->getUser()->getShops() ?? '';
                    if(!empty($shops)){
                        foreach ($shops as $shop) {
                            if ($shop['marketplace'] == $marketplace 
                                && $shop['apps'][0]['app_status'] =='active') {
                                $sources = $shop['sources'][0] ?? [];
                                if(!empty($sources) && isset($sourceData['status']) && $sourceData['status'] == 'closed') {
                                    continue;
                                }
                                $params = ['shop_id' => $shop['remote_shop_id'], 'type' => $reportType, 'home_shop_id' => $shop['_id'], 'dataStartTime' => $startDate, 'dataEndTime' => $endDate];
                                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                                $commonHelper->sendRequestToAmazon('report-request', $params, 'GET');
                            }
                        }
                    }
                }
            }
        }

        return 1;
    }

    public function setDiForUser($userId): array
    {
        try {
            $getUser = User::findFirst([['_id' => $userId]]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin.'
            ];
        }

        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }
}
