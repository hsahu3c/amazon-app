<?php

use MongoDB\BSON\UTCDateTime;
use App\Core\Models\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[\AllowDynamicProperties]
class SalesPerDayCommand extends Command
{

    protected static $defaultName = "order-sales";
    protected $di;
    protected static $defaultDescription = "To insert the sales inside Sales Container at day end";

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
        $this->setHelp('Calculate Per Day Sales Userwise');
        // $this->addArgument("days", InputArgument::OPTIONAL, "days", false);
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute($input, $output)
    {
        $this->output = $output;
        $this->input = $input;
        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Calculating per day sales userwise...</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");


        // for ($i=90; $i > 0; $i--) { 
        //     $preDate = date('Y-m-d', strtotime("-{$i} days"));
        //     $currentDate = date('Y-m-d', strtotime('-'.($i-1).' days'));
        //     $this->salesPerDay($preDate, $currentDate);
        // }
        $cronTaskData = $this->getDataFromAmazonCronTask();

        $lastId = null;
        if(isset($cronTaskData['last_traversed_object_id'])) {
            $lastId = $cronTaskData['last_traversed_object_id'];
        }

        $format = 'Y-m-d';
        if(isset($cronTaskData['last_processed_date'])) {
            $lastDate = $cronTaskData['last_processed_date'];

            $preDateObj = new DateTime($lastDate);
            $preDateObj->format($format);
            $preDate = $preDateObj->modify('+1 day')->format($format);

            $currentDateObj = new DateTime($lastDate);
            $currentDateObj->format($format);
            $currentDate = $currentDateObj->modify('+2 day')->format($format);

            $nowDate = date($format);
            $nowObj = new DateTime($nowDate);
            $nowObj->format($format);
            if($preDateObj < $nowObj) {
                #continue with processing.
            } else {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Can not start processing for: '.$preDate.', because day is not completed.</>');
            }
        }
        else {
            $preDate = date('Y-m-d', strtotime('-1 days'));
            $currentDate = date('Y-m-d');
        }

        $this->salesPerDay($preDate, $currentDate, $lastId);
        return 0;
    }

    /**
     * salesPerDay Function
     * To get All Sales userwise and store in sales_container
     */
    public function salesPerDay($preDate, $currentDate, $lastId=null): int
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $salesContainer = $mongo->getCollectionForTable('sales_container_1Apr25');
        $orderContainerObj = $mongo->getCollectionForTable('order_container');
        // $preDate = date('Y-m-d', strtotime('-1 days'));
        // $currentDate = date('Y-m-d');

        $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Initating Orders Importing of date : '.$preDate.'</>');

        $canExecuteOperation = $this->canExecuteOperation($preDate);

        if($canExecuteOperation)
        {
            $flag = true;
            $prepareData = [];
            do{
                $query = [];
                if(is_null($lastId)) {
                    $query = [
                        'marketplace' => 'amazon',
                        'object_type' => 'source_order', 
                        // 'source_created_at' => [
                        'created_at' => [
                            '$gte' => $preDate, 
                            '$lt' => $currentDate
                        ], 
                        // 'marketplace_currency' => [
                        //     '$ne' => null
                        // ]
                    ];
                } else {
                    $query = [
                        '_id' => ['$gt' => $lastId],
                        'marketplace' => 'amazon',
                        'object_type' => 'source_order', 
                        // 'source_created_at' => [
                        'created_at' => [
                            '$gte' => $preDate, 
                            '$lt' => $currentDate
                        ], 
                        // 'marketplace_currency' => [
                        //     '$ne' => null
                        // ]
                    ];
                }

                $limit = 1000;
                $options = [
                    'typeMap'   => ['root' => 'array', 'document' => 'array'],
                    'projection'=> ['user_id' => 1, 'shop_id' => 1, 'marketplace_currency' => 1, 'total' => 1, 'created_at' => 1, 'source_created_at' => 1, 'attributes' => 1, 'fulfilled_by' => 1],
                    'limit'     => $limit,
                    'sort'      => ["_id" => 1]
                ];

                $result = $orderContainerObj->find($query, $options)->toArray();

                if (!empty($result)) {
                    foreach ($result as $orderData) {
                        $marketplaceId = $this->getAmazonMarketplaceIdFromAttribute($orderData);
                        $orderAmount = (float)$orderData['total']['marketplace_price'];
                        // $index = $orderData['user_id'].'_'.$orderData['shop_id'];
                        $index = $orderData['user_id'].'_'.$orderData['shop_id'].'_'.$orderData['fulfilled_by'];
                        if (!isset($prepareData[$index])) {
                            $prepareData[$index] = [
                                'user_id' => $orderData['user_id'],
                                'shop_id' => $orderData['shop_id'],
                                'marketplace_id' => $marketplaceId,
                                'marketplace_currency' => $orderData['marketplace_currency'],
                                'fulfilled_by' => $orderData['fulfilled_by'],
                                'order_count' => 1,
                                'order_amount' => $orderAmount,
                                'order_amount_usd' => $this->convertCurrency($marketplaceId, $orderAmount),
                                'order_date' => $date = new UTCDateTime(strtotime($preDate) * 1000),
                                'created_at' => new UTCDateTime(strtotime(date('c')) * 1000)
                            ];   
                        }
                        else {
                            $prepareData[$index]['order_count'] += 1;
                            $prepareData[$index]['order_amount'] += $orderAmount;
                            $prepareData[$index]['order_amount_usd'] += $this->convertCurrency($marketplaceId, $orderAmount);
                        }

                        $lastId = $orderData['_id'];
                    }
                    
                    if(count($result) != $limit) {
                        $flag = false;
                        break;
                    }
                }
                else {
                    $flag = false;
                    break;
                }
            }
            while ($flag);

            // $this->di->getLog()->logContent('Final Data= ' . print_r($prepareData, true), 'info', 'debug.log');

            // save data in database.
            if (!empty($prepareData)) {
                $dataToInsert = array_chunk($prepareData, 500);
                foreach ($dataToInsert as $value) {
                    $salesContainer->insertMany(array_values($value));
                }
                ;
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> '.count($prepareData).' Records Imported Successfully!!</>');
            }
            else {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> No Orders to be Imported.</>');
            }

            $this->endOperation($preDate);
            $this->updateDataInAmazonCronTask($lastId, $preDate);
        }
        else
        {
            // die('canExecuteOperation : NO');
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Already Imported.</>');
        }

        return 0;
    }

    public function getAmazonMarketplaceId($user_id, $shop_id)
    {
        $cache_key = "amazon_marketplace_id_{$user_id}_{$shop_id}";
        $cached_app_info = $this->di->getCache()->get($cache_key);
        if($cached_app_info) {
            return $cached_app_info;
        }
        $userModel = User::findFirst([['user_id' => $user_id, 'shops._id' => $shop_id]]);
        if($userModel) {
            $user = $userModel->toArray();

            foreach($user['shops'] as $shop) {
                if($shop['_id'] == $shop_id) {
                    $marketplaceId = $shop['warehouses'][0]['marketplace_id'];

                    $this->di->getCache()->set($cache_key, $marketplaceId);
                    return $marketplaceId;
                }
            }
        }
        else {
            return false;
        }
    }

    public function canExecuteOperation($date): bool
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $dailyCron = $mongo->getCollectionForTable('amazon_daily_cron');

        $query = [
            'user_id' => 'all',
            'shop_id' => 'all',
            'process_name' => 'daily_orders_report',
            'data' => $date
        ];
        $result = $dailyCron->find($query, ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();

        if(empty($result)) {
            return true;
        }
        return false;
    }

    public function endOperation($date)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $dailyCron = $mongo->getCollectionForTable('amazon_daily_cron');

        $resp = $dailyCron->insertOne([
            'user_id'       => 'all',
            'shop_id'       => 'all',
            'process_name'  => 'daily_orders_report',
            'data'          => $date,
            'created_at'    => new UTCDateTime(strtotime(date('c')) * 1000)
        ]);

        return true;
    }

    public function getDataFromAmazonCronTask()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $amzCron = $mongo->getCollectionForTable('amazon_cron_tasks');

        $result = $amzCron->findOne(['task' => 'order_sales_per_day'], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        // if(!empty($result)) {
        //     print_r($result);die;
        // }
        
        return $result;
    }

    public function updateDataInAmazonCronTask($lastObjectId, $date)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $amzCron = $mongo->getCollectionForTable('amazon_cron_tasks');

        $amzCron->updateOne(['task' => 'order_sales_per_day'], ['$set' => ['last_traversed_object_id' => $lastObjectId, 'last_processed_date' => $date]], ['upsert' => true]);
    }

    private function convertCurrency($marketplace_id, $amount)
    {
        //Rates as per 8 April 2025
        $conversionRate = [
            //North America
            'A2EUQ1WTGCTBG2' => 0.71, // Canada
            'ATVPDKIKX0DER' => 1, //US
            'A1AM78C64UM0Y8' => 0.049, //Mexico
            'A2Q3Y263D00KWC' => 0.16, //Brazil
            //Europe
            'A28R8C7NBKEWEA' => 1.10, //Ireland
            'A1RKKUPIHCS9HS' => 1.10, //Spain
            'A13V1IB3VIYZZH' => 1.10, //France
            'AMEN7PMS3EDWL' => 1.10, //Belgium
            'A1805IZSGTT6HS' => 1.10, //Netherlands
            'A1PA6795UKMFR9' => 1.10, //Germany
            'APJ6JRA9NG5V4' => 1.10, //Italy
            'A1F83G8C2ARO7P' => 1.28, //UK
            'A2NODRKZP88ZB9' => 0.10, //Sweden
            'AE08WJ6YKNBMC' => 0.051, //South Africa
            'A1C3SOZRARQ6R3' => 0.26, //Poland
            'ARBP9OOSHTCHU' => 0.019, //Egypt
            'A33AVAJ2PDY3EV' => 0.026, //Turkey
            'A17E79C6D8DWNP' => 0.27, //Soudi Arabia
            'A2VIGQ35RCS4UG' => 0.27, //UAE
            'A21TJRUUN4KGV' => 0.012, //India
            //Far East
            'A19VAU5U5O7RUS' => 0.74, //Singapore
            'A39IBJ37TRP1C6' => 0.61, //Australia
            'A1VC38T7YXB528' => 0.0068, //Japan
        ];

        if(isset($conversionRate[$marketplace_id])) {
            return $amount*$conversionRate[$marketplace_id];
        }
        else {
            die('currency not found');
        }
    }

    private function getAmazonMarketplaceIdFromAttribute($orderData)
    {
        if(isset($orderData['attributes'])) {
            foreach ($orderData['attributes'] as $attr) {
                if(isset($attr['key']) && $attr['key']=='MarketplaceId') {
                    return $attr['value'];
                }
            }
        }
        return '';
    }
}