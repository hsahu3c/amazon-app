<?php
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\Console\Command\Command;

class DownloadAmazonListingsCommand extends Command
{
    protected static $defaultName = "download-amazon-listings";
    
    protected $di;

    protected static $defaultDescription = "Download Amazon Listings Details using GetListings API";

    protected $output;
    
    protected $INPUT_DATA = [
        'input_file_path' => BP . DS . 'loventouch-handicraft-13-june-amazon_listings-edited.csv', // /var/www/html/home/fle.csv
        'shopify_shop_url' => 'loventouch-handicraft.myshopify.com',
        'amazon_remote_shop_id' => '217652',
        'output_collection_name' => 'loventouch-handicraft_amazonListings-13-june'
    ];

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
        // $this->setHelp('Get platform name from seller wise API error report shared by Fatima');
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute($input, $output)
    {
        die('nothing to execute');
        $this->output = $output;

        $input_file_path = $this->INPUT_DATA['input_file_path'];

        if(!file_exists($input_file_path)) {
            die('file not found');
        }

        $fp = fopen($input_file_path, 'r');

        $SELLER_SKU_KEY = false;

        $counter = 0;

        $insertData = [];
        $insertedCount = 0;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collectionObj = $mongo->getCollectionForTable($this->INPUT_DATA['output_collection_name']);

        // while ( !feof($fp) )
        // {
        //     $line = fgets($fp, 2048);

        //     $data = str_getcsv($line);
        //     // print_r($data);die;
        //     if(!$SELLER_SKU_KEY) {
        //         foreach ($data as $key => $value) {
        //             if($value === 'seller-sku') {
        //                 $SELLER_SKU_KEY = $key;
        //                 // break;
        //             }
        //         }
        //         continue;
        //     }

        //     $counter++;
            
        //     if(!$SELLER_SKU_KEY) {
        //         die('invalid csv');
        //     }

        //     $requestData = [
        //         'remote_shop_id' => $this->INPUT_DATA['amazon_remote_shop_id']
        //     ];

        //     $resp = $this->getListingsFromAmazon($requestData, $data[$SELLER_SKU_KEY]);
        //     if($resp['status'] === true) {
        //         $out_str = "{$counter}) {$data[$SELLER_SKU_KEY]}: Get Listings Successful.";
        //         $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>{$out_str}</>");                    

        //         $insertData[] = [
        //             'shop_url' => $this->INPUT_DATA['shopify_shop_url'],
        //             'remote_shop_id' => $this->INPUT_DATA['amazon_remote_shop_id'],
        //             'sku' => $data[$SELLER_SKU_KEY],
        //             'summaray_product_type' => $resp['data']['summaries'][0]['productType'],
        //             'product_type' => $resp['data']['productTypes'][0]['productType'],
        //             'listings_data' => $resp['data'],
        //             'created_at' => new UTCDateTime(strtotime(date('c')) * 1000)
        //         ];
        //     }
        //     else {
        //         $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red>{$counter}) {$data[$SELLER_SKU_KEY]}: Get Listings Failed.</>");
        //     }
            
        //     usleep(500000); // sleep for .5 sec

        //     if(count($insertData) == 500) {
        //         $bulkInsertObj = $collectionObj->insertMany($insertData);
        //         $insertData = [];
        //         $insertedCount += 500;
        //         $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> 500 Items inserted in database. </>");
        //     }
        // }

        // fclose($fp);

        // if(!empty($insertData)) {
        //     $bulkInsertObj = $collectionObj->insertMany($insertData);
        //     $count = count($insertData);
        //     $insertedCount += $count;
        //     $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> {$count} Items inserted in database. </>");
        // }

        // $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> Total {$insertedCount} Items inserted in database. </>");
        // return 0;

        //----------------------------------------------------------

        // Re-attempt failed SKUs
        // $erroredSku = [
        //     'LNT-PE-L-06355-S-5',
        //     'LNT-L-15989-S'
        // ];
        // foreach ($erroredSku as $_sku) {
        //     $counter++;

        //     $requestData = [
        //         'remote_shop_id' => $this->INPUT_DATA['amazon_remote_shop_id']
        //     ];

        //     $resp = $this->getListingsFromAmazon($requestData, $_sku);
        //     if($resp['status'] === true) {
        //         $out_str = "{$counter}) {$_sku}: Get Listings Successful.";
        //         $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>{$out_str}</>");                    

        //         $insertData[] = [
        //             'shop_url' => $this->INPUT_DATA['shopify_shop_url'],
        //             'remote_shop_id' => $this->INPUT_DATA['amazon_remote_shop_id'],
        //             'sku' => $_sku,
        //             'summaray_product_type' => $resp['data']['summaries'][0]['productType'],
        //             'product_type' => $resp['data']['productTypes'][0]['productType'],
        //             'listings_data' => $resp['data'],
        //             'created_at' => new UTCDateTime(strtotime(date('c')) * 1000)
        //         ];
        //     }
        //     else {
        //         $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red>{$counter}) {$_sku}: Get Listings Failed.</>");
        //     }
            
        //     usleep(500000); // sleep for .5 sec
        // }

        // $bulkInsertObj = $collectionObj->insertMany($insertData);
        // $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green> ".count($insertData)." Items inserted in database. </>");
        // return 0;
    }

    public function getListingsFromAmazon($requestData, $sellerSku)
    {
        $remoteShopId = $requestData['remote_shop_id'];
        $rawBody['sku'] = rawurlencode($sellerSku);
        $rawBody['shop_id'] = $remoteShopId;
        $rawBody['includedData'] = "issues,attributes,summaries,offers,fulfillmentAvailability,procurement,relationships,productTypes";
        $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
        $response = $commonHelper->sendRequestToAmazon('listing', $rawBody, 'GET');
        // print_r($response);die('getListingsFromAmazon');
        if($response['success']){
            $this->di->getLog()->logContent('body: '.print_r($response,true), 'info', "download-listings/{$this->INPUT_DATA['shopify_shop_url']}/get/success/{$sellerSku}.log");
            return ['status'=>true, 'data'=>$response['response']];
        }
        else {
            $this->di->getLog()->logContent('body: '.print_r($response,true), 'info', "download-listings/{$this->INPUT_DATA['shopify_shop_url']}/get/failure/{$sellerSku}.log");
            return ['status'=>false, 'error'=>$response];
        }
    }
}