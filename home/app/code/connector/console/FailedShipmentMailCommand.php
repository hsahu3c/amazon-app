<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FailedShipmentMailCommand extends Command
{

    protected static $defaultName = "failedshipment-mail";

    protected static $defaultDescription = "Send Mail for Failed Shipment";
    private $logFile;
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
        $this->setHelp('Send mail for failed Shipment');
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Shipment Mail Process...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");
        $this->startProcess();
        $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Process Completed</>');
        return 0;
    }

    /**
     * startProcess function
     * Get and Prepare Data to send mail
     * for failed shipment
     */
    public function startProcess(): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $shipmentMailCollection = $mongo->getCollectionForTable('failed_shipment');
        $userDetails = $mongo->getCollectionForTable('user_details');
        $orderCollection = $mongo->getCollectionForTable('order_container');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $this->logFile = "shipment/failedShipmentMail/" . date('d-m-Y') . '.log';
        $failedShipmentData = $shipmentMailCollection->find(['user_id' => ['$exists' => true]], $arrayParams)->toArray();
        $prepareData = [];
        if (!empty($failedShipmentData)) {
            foreach ($failedShipmentData as $data) {
                if (!isset($prepareData[$data['user_id']])) {
                    $prepareData[$data['user_id']]['source_marketplace'] = $data['source_marketplace'];
                    $prepareData[$data['user_id']]['target_marketplace'] = $data['target_marketplace'];
                }

                $prepareData[$data['user_id']]['orders'][$data['source_order_id']] = [
                    'source' => $data['source_order_id'],
                    'target' => $data['target_order_id'],
                    'reason' => $data['reason']
                ];
            }

            foreach ($prepareData as $user => $userData) {
                $this->di->getLog()->logContent('Starting Process for UserId: ' . json_encode($user, true), 'info', $this->logFile);
                $userInfo = $userDetails->findOne(
                    ['user_id' => $user],
                    $arrayParams
                );
                if (!empty($userInfo)) {
                    $diRes = $this->setDiForUser($user);
                    if(isset($diRes['success']) && !$diRes['success']) {
                        continue;
                    }

                    $orderIds = [];
                    $bccs = [];
                    $orderIds = array_keys($userData['orders']);
                    $orderData = $orderCollection->find([
                        'user_id' => $user,
                        'object_type' => ['$in' => ['source_order', 'target_order']],
                        'marketplace_reference_id' => ['$in' => $orderIds],
                        '$or' => [['marketplace_status' => ['$in' => ['Unshipped','Canceled','Cancelled']]],
                        ['cancellation_status' => ['$in' => ['full', 'partial']]]],
                        'shipping_status' => 'Unshipped',
                        'shipment_error' => ['$exists' => true]
                    ], $arrayParams)->toArray();
                    $unshippedOrders = array_column($orderData, "marketplace_reference_id");
                    $shippedOrders = array_values(array_diff($orderIds, $unshippedOrders));
                    if (!empty($shippedOrders)) {
                        foreach ($shippedOrders as $orderId) {
                            unset($userData['orders'][$orderId]);
                        }

                        $orderCollection->updateMany(
                            [
                                'user_id' => $user,
                                '$or' => [['object_type' => 'source_order'], ['object_type' => 'target_order']],
                                'marketplace_reference_id' => ['$in' => $shippedOrders],
                            ],
                            ['$unset' => ["shipment_mail" => true]]
                        );
                    }

                    if (!empty($userData['orders'])) {
                        $appTag = 'default';
                        foreach ($userInfo['shops'] as $shop) {
                            if (isset($shop['targets']) && !empty($shop['targets']) && !empty($shop['apps'])) {
                                $appTag = $shop['apps'][0]['code'] ?? 'default';
                                break;
                            }
                        }

                        $this->di->getLog()->logContent('Failed Shipment Orders Found', 'info', $this->logFile);
                        $this->di->getLog()->logContent('OrderIds: ' . json_encode($unshippedOrders), 'info', $this->logFile);
                        $bccs[] = !empty($userInfo['alternate_email']) ? $userInfo['alternate_email'] : "";
                        $mailData = [
                            'username' => $userInfo['username'],
                            'name' => $userInfo['name'],
                            'email' => $userInfo['source_email'] ?? ($userInfo['email'] ?? ""),
                            'bccs' => $bccs,
                            'source_marketplace' => $userData['source_marketplace'],
                            'target_marketplace' => $userData['target_marketplace'],
                            'subject' => 'Shipment Sync Failure',
                            'userData' => $userData['orders']
                        ];
                        $mailData['app_name'] = $this->di->getConfig()->app_name;
                        if (!empty($mailData['app_name'])) {
                            $mailData['subject'] = 'Shipment Sync Failure - '.$mailData['app_name'];
                        }
                        $orderCount = count($userData['orders']);
                        $mailData['user_id'] =  $user;
                        if ($orderCount > 10) {
                            $this->di->getLog()->logContent('Preparing CSV Format', 'info', $this->logFile);
                            $mailData['csvFormat'] = true;
                        }

                        $gridlinkRes = $this->di->getObjectManager()->get('App\Connector\Components\Order\Helper')->getGridLink($mailData, $appTag, 'shipment');
                        $mailData['has_order_grid_link'] = false;
                        if($gridlinkRes) {
                            $mailData['order_grid_link'] = $gridlinkRes;
                            $mailData['has_order_grid_link'] = true;
                        }

                        $this->sendMail($mailData);
                    }

                    $shipmentMailCollection->deleteMany(['user_id' => (string)$user, 'source_order_id' => ['$in' => $orderIds]]);
                }
            }
        }
    }

    /**
     * sendMail function
     * Sending mail to particular user
     */
    public function sendMail(array $msgData): void
    {
        $this->di->getLog()->logContent('sendMail msgData: '.json_encode($msgData), 'info', $this->logFile);
        if (isset($msgData['csvFormat']) && $msgData['csvFormat']) {
            $sourceMarketplace = ucfirst($msgData['source_marketplace']) . ' Order ID';
            $targetMarketplace = ucfirst($msgData['target_marketplace']) . ' Order ID';
            $csvData = [];
            $csvData[] = ['Sno.', $targetMarketplace, $sourceMarketplace, 'Reason of Failure'];
            $counter = 0;
            foreach ($msgData['userData'] as $item) {
                ++$counter;
                $csvData[] = [$counter, $item['target'], $item['source'], $item['reason']];
            }

            $csvContent = '';
            foreach ($csvData as $row) {
                $csvContent .= implode(',', $row) . PHP_EOL;
            }

            $fileName = $msgData['user_id'] . '_failedShipment';
            $fileLocation = BP . DS . 'var' . DS . 'log' .  DS . 'shipment' . DS . $fileName;
            $csvFilePath = "{$fileLocation}.csv";

            if (!file_exists($csvFilePath)) {
                $dirname = dirname($csvFilePath);
                if (!is_dir($dirname)) {
                    mkdir($dirname, 0777, true);
                }

                file_put_contents($csvFilePath, $csvContent);
            }

            unset($msgData['userData']);
            $msgData['path'] = 'connector' . DS . 'views' . DS . 'email' . DS . 'bulkShipmentFailed.volt';
            $msgData['content'] = 'Download Attach File';
            $msgData['files'] = [
                [
                    'name' =>  'failedShipments',
                    'path' => $csvFilePath
                ]
            ];
        } else {
            $msgData['path'] = 'connector' . DS . 'views' . DS . 'email' . DS . 'shipmentFailed.volt';
        }

        $mailResponse = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($msgData);
        if ($mailResponse) {
            $this->di->getLog()->logContent('Mail Send Successfully!!', 'info', $this->logFile);
        } else {
            $this->di->getLog()->logContent('Unable to Send Mail', 'info', $this->logFile);
        }

        if (isset($msgData['csvFormat']) && $msgData['csvFormat']) {
            unlink($csvFilePath);
        }

        $this->di->getLog()->logContent('-------------------------------', 'info', $this->logFile);
    }

    public function setDiForUser($userId): array
    {
        try {
            $getUser = \App\Core\Models\User::findFirst([['_id' => $userId]]);
        } catch (\Exception $e) {
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
