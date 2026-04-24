<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Core\Models\User;
use Exception;

class AmazonGMVDataCommand extends Command
{

    protected static $defaultName = "amazon-gmv-data";
    protected $di;
    protected static $defaultDescription = "Fetch Users GMV data";
    protected OutputInterface $output;

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
        $this->setHelp('Fetch GMV Data');
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute($input, $output): int
    {
        $this->output = $output;
        $this->output->writeln("Executing GMV data for all users");
        $this->fetchUsersNew();
        return 1;
    }
    public function fetchUsers()
    {
        $limit = 10;
        $skip = 0;
        $continue = true;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $paymentCollection = $mongo->getCollectionForTable('payment_details');

        do {
            $options = [
                'limit' => $limit,
                'skip' => $skip,
                'sort' => ['_id' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'projection' => [
                    'user_id' => 1,
                    'username' => 1,
                    'email' => 1,
                    // return only the matching shopify shop element(s)
                    'shops' => 1,
                ],
            ];

            $filter = [
                'shops' => [
                    '$elemMatch' => [
                        'marketplace' => 'shopify',
                        'apps.app_status' => 'active'
                    ]
                ]
            ];

            $userDetails = $collection->find($filter, $options)->toArray();

            foreach ($userDetails as $user) {
                $userId = $user['user_id'] ?? '';
                $username = $user['username'] ?? '';
                $email = $user['email'] ?? '';
                $response = $this->setDiForUser($userId);
                $shopifyShop = null;
                $handlerdata = [];
                $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                if (isset($response['success']) && $response['success']) {

                    $appCode = $this->di->getAppCode()->get();
                    $appTag = $this->di->getAppCode()->getAppTag();
                    $shops = $user['shops'] ?? [];
                    $sourceShopId = '';
                    $marketplaceId = '';
                    $currency = '';
                    $phone = '';
                    foreach ($shops as $shop) {
                        if (isset($shop['marketplace']) && ($shop['marketplace'] == 'shopify')) {
                            $sourceShopId = (string)$shop['_id'];
                            $phone = (string)$shop['phone'] ?? '';
                        } elseif (isset($shop['marketplace']) && ($shop['marketplace'] == 'amazon')) {
                            if (!empty($shop['warehouses']) || empty($sourceShopId)) {
                                $marketplaceId = $shop['warehouses'][0]['marketplace_id'] ?? '';
                                $currency = $shop['currency'] ?? '';
                            }
                            $handlerdata[] = [
                                'type' => 'full_class',
                                'class_name' => '\App\Amazon\Components\Order\Helper',
                                'method' => 'fetchGMVData',
                                'appCode' => $appCode,
                                'appTag' => $appTag,
                                'queue_name' => 'prepare_gmv_data',
                                'user_id' => $userId ?? $this->di->getUser()->getId(),
                                'username' => $username ?? $this->di->getUser()->getUsername(),
                                'email' => $email ?? $this->di->getUser()->getEmail(),
                                'phone' => $phone,
                                'source_marketplace' => [
                                    'marketplace' => 'shopify',
                                    'source_shop_id' => $sourceShopId,
                                ],
                                'target_marketplace' => [
                                    'marketplace' => 'amazon',
                                    'target_shop_id' => (string)$shop['_id'],
                                    'marketplace_id' => $marketplaceId,
                                    'currency' => $currency,
                                ],
                            ];                                // $this->fetchGMVData($data);
                        }
                    }
                }
            }

            if (count($userDetails) === $limit) {
                $skip += $limit;
            } else {
                $continue = false;
            }
        } while ($continue);
    }

    public function setDiForUser($user_id)
    {
        try {
            $getUser = User::findFirst([['_id' => $user_id]]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found.'
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

    public function fetchUsersNew()
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
            'projection' => [
                'user_id' => 1,
                'username' => 1,
                'email' => 1,
                // return only the matching shopify shop element(s)
                'shops' => 1,
            ],
        ];

        $filter = [
            'plan_tier' => ['$exists' => true],
            'shops.1' => ['$exists' => true],
        ];

        $userDetails = $collection->find($filter, $options)->toArray();

        foreach ($userDetails as $user) {
            $userId = $user['user_id'] ?? '';
            $response = $this->setDiForUser($userId);
            if (isset($response['success']) && $response['success']) {
                $data = [
                    'type' => 'full_class',
                    'class_name' => '\App\Amazon\Components\Order\Helper',
                    'method' => 'fetchGMVData',
                    'queue_name' => 'prepare_gmv_data',
                    'user_id' => $userId,
                    'username' => $user['username'] ?? '',
                ];
                $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($data);
            }
        }
    }
}
