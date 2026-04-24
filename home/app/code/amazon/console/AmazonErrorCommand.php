<?php
use App\Core\Models\User;
use App\Amazon\Components\Template\Category;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AmazonErrorCommand extends Command
{
    protected static $defaultName = "amazon-error";
    
    protected $di;

    protected static $defaultDescription = "Get platform name from seller wise API error report shared by Fatima";

    protected $output;

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
        // $this->bulkLinkingInitiate();
        // die('yahan');

//         $sqsMsg = <<<JSONSTR
// {"NotificationVersion":"1.0","NotificationType":"ANY_OFFER_CHANGED","PayloadVersion":"1.0","EventTime":"2026-01-21T06:29:43.228Z","NotificationMetadata":{"ApplicationId":"amzn1.sellerapps.app.b5994c79-9567-4ba5-b045-0645faf723b6","SubscriptionId":"a75955a5-a530-4bfb-8a7f-29f872b3c7f6","PublishTime":"2026-01-21T06:29:44.967Z","NotificationId":"b8f9b45e-8c51-4082-8a24-1d5c481dcffa"},"Payload":{"AnyOfferChangedNotification":{"SellerId":"A3QAGNMJBO5UG3","OfferChangeTrigger":{"MarketplaceId":"A1PA6795UKMFR9","ASIN":"B095M2KVQD","ItemCondition":"new","TimeOfOfferChange":"2026-01-21T06:29:43.191Z","OfferChangeType":""},"Summary":{"NumberOfOffers":[{"Condition":"new","FulfillmentChannel":"Amazon","OfferCount":2},{"Condition":"new","FulfillmentChannel":"Merchant","OfferCount":23}],"LowestPrices":[{"Condition":"new","FulfillmentChannel":"Amazon","LandedPrice":{"Amount":5.29,"CurrencyCode":"EUR"},"ListingPrice":{"Amount":5.29,"CurrencyCode":"EUR"},"Shipping":{"Amount":0,"CurrencyCode":"EUR"}},{"Condition":"new","FulfillmentChannel":"Merchant","LandedPrice":{"Amount":4.6,"CurrencyCode":"EUR"},"ListingPrice":{"Amount":4.6,"CurrencyCode":"EUR"},"Shipping":{"Amount":0,"CurrencyCode":"EUR"}}],"BuyBoxPrices":[{"Condition":"New","LandedPrice":{"Amount":4.69,"CurrencyCode":"EUR"},"ListingPrice":{"Amount":3.9,"CurrencyCode":"EUR"},"Shipping":{"Amount":0.79,"CurrencyCode":"EUR"}}],"SalesRankings":[{"ProductCategoryId":"office_product_display_on_website","Rank":64},{"ProductCategoryId":"202976031","Rank":5}],"NumberOfBuyBoxEligibleOffers":[{"Condition":"new","FulfillmentChannel":"Amazon","OfferCount":2},{"Condition":"new","FulfillmentChannel":"Merchant","OfferCount":23}],"CompetitivePriceThreshold":{"Amount":4.99,"CurrencyCode":"EUR"}},"Offers":[{"SellerId":"A2RDE756DOM3H0","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":15857,"SellerPositiveFeedbackRating":82},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":4.6,"CurrencyCode":"EUR"},"Shipping":{"Amount":0,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A1HQ9YKW948X95","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":367,"SellerPositiveFeedbackRating":91},"ShippingTime":{"MinimumHours":24,"MaximumHours":24,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":4.63,"CurrencyCode":"EUR"},"Shipping":{"Amount":0,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A16ZYXKEW02ZX1","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":93252,"SellerPositiveFeedbackRating":85},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":3.9,"CurrencyCode":"EUR"},"Shipping":{"Amount":0.79,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":true,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"AL5JXES37EDG7","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":51384,"SellerPositiveFeedbackRating":100},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":5.29,"CurrencyCode":"EUR"},"Shipping":{"Amount":0,"CurrencyCode":"EUR"},"IsFulfilledByAmazon":true,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":true,"IsOfferNationalPrime":true},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A129CH61Q8B4GJ","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":1587,"SellerPositiveFeedbackRating":87},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":3.95,"CurrencyCode":"EUR"},"Shipping":{"Amount":1.6,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A3QAGNMJBO5UG3","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":9592,"SellerPositiveFeedbackRating":88},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"FUTURE_WITH_DATE","AvailableDate":"2026-01-21T21:00:00.000Z"},"ListingPrice":{"Amount":5.89,"CurrencyCode":"EUR"},"Shipping":{"Amount":0,"CurrencyCode":"EUR"},"IsFulfilledByAmazon":true,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":true,"IsOfferNationalPrime":true},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A357PNY7S2ERGI","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":0,"SellerPositiveFeedbackRating":0},"ShippingTime":{"MinimumHours":24,"MaximumHours":24,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":3.5,"CurrencyCode":"EUR"},"Shipping":{"Amount":3,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A2J3MHS8H5EIUE","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":191455,"SellerPositiveFeedbackRating":90},"ShippingTime":{"MinimumHours":72,"MaximumHours":96,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":3.85,"CurrencyCode":"EUR"},"Shipping":{"Amount":3,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"GB","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A61EHKVFD3ZJL","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":192,"SellerPositiveFeedbackRating":61},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":4.66,"CurrencyCode":"EUR"},"Shipping":{"Amount":2.2,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"AJN8PL9CTSGDS","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":421,"SellerPositiveFeedbackRating":93},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":6.9,"CurrencyCode":"EUR"},"Shipping":{"Amount":0,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A1NKTO3ZKGYRSY","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":3114,"SellerPositiveFeedbackRating":96},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":4.99,"CurrencyCode":"EUR"},"Shipping":{"Amount":2.79,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A1S8S54W4VIKDY","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":1829,"SellerPositiveFeedbackRating":98},"ShippingTime":{"MinimumHours":24,"MaximumHours":24,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":8.69,"CurrencyCode":"EUR"},"Shipping":{"Amount":0,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"GB","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A3C4O1L5ZDJ600","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":3524,"SellerPositiveFeedbackRating":90},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":3.75,"CurrencyCode":"EUR"},"Shipping":{"Amount":4.95,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A1E3MCVQOE6G82","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":2398,"SellerPositiveFeedbackRating":78},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":4.04,"CurrencyCode":"EUR"},"Shipping":{"Amount":4.99,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A34OL3Q55ZGH4I","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":59,"SellerPositiveFeedbackRating":67},"ShippingTime":{"MinimumHours":24,"MaximumHours":24,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":4.49,"CurrencyCode":"EUR"},"Shipping":{"Amount":5,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"AT","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"AU0983JQI3ZFM","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":44,"SellerPositiveFeedbackRating":100},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":6.79,"CurrencyCode":"EUR"},"Shipping":{"Amount":2.95,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A2X0QCT0NCHBYM","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":91,"SellerPositiveFeedbackRating":91},"ShippingTime":{"MinimumHours":24,"MaximumHours":24,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":3.95,"CurrencyCode":"EUR"},"Shipping":{"Amount":5.9,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A3L9NRMS5P8MO1","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":33992,"SellerPositiveFeedbackRating":96},"ShippingTime":{"MinimumHours":0,"MaximumHours":0,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":9.9,"CurrencyCode":"EUR"},"Shipping":{"Amount":0,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":true,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A3FZXPF87JIOTD","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":2254,"SellerPositiveFeedbackRating":50},"ShippingTime":{"MinimumHours":24,"MaximumHours":24,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":4.95,"CurrencyCode":"EUR"},"Shipping":{"Amount":4.99,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true},{"SellerId":"A24FRWRCVYRZGK","SubCondition":"new","SellerFeedbackRating":{"FeedbackCount":697,"SellerPositiveFeedbackRating":95},"ShippingTime":{"MinimumHours":24,"MaximumHours":48,"AvailabilityType":"NOW","AvailableDate":""},"ListingPrice":{"Amount":3.99,"CurrencyCode":"EUR"},"Shipping":{"Amount":5.99,"CurrencyCode":"EUR"},"ShipsFrom":{"Country":"DE","State":""},"IsFulfilledByAmazon":false,"IsBuyBoxWinner":false,"PrimeInformation":{"IsOfferPrime":false,"IsOfferNationalPrime":false},"IsFeaturedMerchant":true,"ShipsDomestically":true}]}}}
// JSONSTR;
//         $data = json_decode($sqsMsg, true);
//         $data['queue_name'] = 'himanshu_any_offer_changed';
//         $return = $this->di->getObjectManager()->create('\App\Amazon\Components\Message\Handler\Sqs')
//         ->processMsg($data);
//         var_dump($return);die;

//         $sellerId = 'A16ZYXKEW02ZX1';
//         $payload = $sqsMessage['Payload']['AnyOfferChangedNotification'];
//         $skuResponse = [
//             'body' => [
//                 'payload' => [
//                     'Offers' => $payload['Offers'] ?? null,
//                     'Summary'=> $payload['Summary'] ?? null
//                 ]
//             ]
//         ];
//         $isBuyboxWinner = $this->di->getObjectManager()->create(ListingOffersBatch::class)->calculateBuyBoxStatus($sellerId, $skuResponse);
//         // var_dump($isBuyboxWinner);die;
//         $updateData = [
//             'buybox'  => $isBuyboxWinner ? 'win' : 'loss',
//             'summary' => $skuResponse['body']['payload']['Summary'] ?? null,
//             'offers'  => $skuResponse['body']['payload']['Offers'] ?? null,
//             'updated_at' => date('c')
//         ];
//         print_r($updateData);die;

//         $json = <<<JSONSTR
// {"appCode":"amazon","appTag":"amazon_sales_channel","type":"full_class","queue_name":"amazonbycedhomelocalcif2_mongo_webhooks_v2","data":[{"source_product_id":"40818178850865","container_id":"14777759433070","shop_id":"1","user_id":"68ff96277f41bd6a3d020a66","currentValue":{"compare_at_price":"801.00","price":1002,"quantity":501,"source_updated_at":"2025-08-18T06:26:49-04:00","title":"title11ab2","variant_attributes":[{"key":"COLOR","value":"Slate"},{"key":"SIZE","value":"One Size"}],"variant_title":"Slate / One Size"},"beforeValue":{"compare_at_price":"74100.00","price":74100,"quantity":0,"source_updated_at":"2025-08-18T14:14:09-04:00","title":"title11ab20","variant_attributes":[{"key":"COLOR","value":"Red"},{"key":"SIZE","value":"Small"}],"variant_title":"Red / Small"},"removedFields":[],"operationType":"update","key":"price","rs_token":{"_data":"8268A36DE40000001B2B042C0100296E5A10049D2FBE026B84490FAE46716455A72690463C6F7065726174696F6E54797065003C7570646174650046646F63756D656E744B657900463C5F6964003C3236343239363500000004"},"clusterTime":"2025-08-18T18:16:04.929Z","source_marketplace":"shopify"}],"userIds":["67c35f260c26613f3d0c6beb"]}
// JSONSTR;
//         $data = json_decode($json, true);
//         $return = $this->di->getObjectManager()->create('\App\Amazon\Components\MongostreamV2\Helper')
//         ->canSkipMessage($data);
//         var_dump($return);die;

        
//         $json = <<<JSONSTR
// {"appCode":"amazon","appTag":"amazon_sales_channel","type":"full_class","queue_name":"amazonbycedhomelocalcif2_mongo_webhooks_v2","data":[{"source_product_id":"52328967668078","container_id":"14777759433070","shop_id":"1","user_id":"67c35f260c26613f3d0c6beb","currentValue":{"compare_at_price":"801.00","price":1002,"quantity":501,"source_updated_at":"2025-08-18T06:26:49-04:00","title":"title11ab2","variant_attributes":[{"key":"COLOR","value":"Slate"},{"key":"SIZE","value":"One Size"}],"variant_title":"Slate / One Size"},"beforeValue":{"compare_at_price":"74100.00","price":74100,"quantity":0,"source_updated_at":"2025-08-18T14:14:09-04:00","title":"title11ab20","variant_attributes":[{"key":"COLOR","value":"Red"},{"key":"SIZE","value":"Small"}],"variant_title":"Red / Small"},"removedFields":[],"operationType":"update","key":"price","rs_token":{"_data":"8268A36DE40000001B2B042C0100296E5A10049D2FBE026B84490FAE46716455A72690463C6F7065726174696F6E54797065003C7570646174650046646F63756D656E744B657900463C5F6964003C3236343239363500000004"},"clusterTime":"2025-08-18T18:16:04.929Z","source_marketplace":"shopify"}],"userIds":["67c35f260c26613f3d0c6beb"]}
// JSONSTR;
//         $data = json_decode($json, true);
//         $this->di->getObjectManager()->create('\App\Amazon\Components\MongostreamV2\Helper')->start($data);
//         die('yahananan');

//-----start
        // $userId = '67c3626b0c3af5871403cc0e';

        // $toRemoveData = [
        //     [
        //         'user_id' => $userId,
        //         'target_marketplace' => 'amazon',
        //         'shop_id' => '3',
        //         'container_id' => '7685313593398',
        //         'source_product_id' => '43148984778806',
        //         'source_shop_id' => '2',
        //         'unset' => [
        //             'asin' => 1,
        //             'sku' => 1,
        //             'matchedwith' => 1,
        //             'status' => 1,
        //             'target_listing_id' => 1,
        //             'fulfillment_type' => 1
        //         ]
        //     ]
        // ];

        // $additionalData = [];
        // $additionalData['source'] = [
        //     'marketplace' => 'shopify',
        //     'shopId' => '2'
        // ];
        // $additionalData['target'] = [
        //     'marketplace' => 'amazon',
        //     'shopId' => '3'
        // ];

        // $saveProductResponse = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit')->saveProduct($toRemoveData , $userId, $additionalData);
        // print_r($saveProductResponse);
        // die('end-saveProduct');
//-----end





        $this->output = $output;

        $this->testFileReadFromUrl();
        die('finish testFileReadFromUrl');

        // $this->testGetAttributeFileCache();
        // die('finish test attribute cache');

        // $this->findDuplicateProducts();
        // die('finish findDuplicateProducts');

        // $this->prepareOrderData();
        // die('finish prepareOrderData');

        // $this->horseLoverz();
        // die('finish horseLoverz');

        // $this->horseLoverzGetInventory();
        // die('finish horseLoverzGetInventory');

        die('nothing to execute');

        $input_file_path = BP . DS . 'Error-rates.csv';
        $output_file_path = BP . DS . 'Output-Error-rates.csv';

        $fp = fopen($input_file_path, 'r');

        $SELLER_ID_KEY = false;

        while ( !feof($fp) )
        {
            $line = fgets($fp, 2048);

            $data = str_getcsv($line);
            // print_r($data);die;
            if(!$SELLER_ID_KEY) {
                foreach ($data as $key => $value) {
                    if($value === 'seller ID') {
                        $SELLER_ID_KEY = $key;

                        $columns = $data;
                        $columns[] = 'Platform';
                        $this->writeNewCsv($output_file_path, $columns, false);

                        break;
                    }
                }

                continue;
            }
      if(!$SELLER_ID_KEY) {
                    die('invalid csv');
                }
      // foreach(['A3VSMOL1YWESR0', 'A1JRBYXA57B4UD', 'A3PCDHD12R0QU3', 'B4PCDHD12R0QU3'] as $sid) {
      //    $response = $this->getSellerPlatform($sid);
      //    if($response['success']==true) {
      //        $response['platforms'];
      //    }
      // }die;
      // var_dump($data[$SELLER_ID_KEY]);die;
      $response = $this->getSellerPlatform($data[$SELLER_ID_KEY]);
      if($response['success']==true) {
                    $data[] = $response['platforms'];

                    $this->writeNewCsv($output_file_path, $data);
                }
                else {
                    $data[] = "Not Found - {$response['message']}";

                    $this->writeNewCsv($output_file_path, $data);
                }
        }                              

        fclose($fp);
    }

    public function testFileReadFromUrl()
    {
        // $filePath = BP.DS.'var'.DS.'file'.DS.'http'.DS.'product-5.jsonl'; // local system
        $filePath = 'https://staging-amazon-sales-channel-app-backend.cifapps.com/product-5.jsonl';


        $intermediateResponse = $this->di->getObjectManager()->create('\App\Shopifyhome\Components\Parser\Product', [$filePath]);

        $filter = [
            'limit' => 1,
            'next'  => 'MX4wfjIwMg%3D%3D'
        ];
        $response =  $intermediateResponse->getVariants($filter);
        print_r($response);die;

        // $response =  $intermediateResponse->getItemCount();
        // print_r($response);die;
    }

    /**
     * getSellerPlatform Function
     */
    public function getSellerPlatform($sellerId): array
    {
        if (!empty($sellerId)) {
            $dbName = 'remote_db';
            $db = $this->di->get($dbName);
            if ($db) {
                $appsShopCollections = $db->selectCollection('apps_shop');
                $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
                $sellerInfo = $appsShopCollections->findOne(['group_code' => 'amazon', 'seller_id' => $sellerId], $options);

                if (!empty($sellerInfo)) {
                    // echo '<pre>';print_r($appInfo);die;

                    $subUserCollections = $db->selectCollection('sub_user');
                    $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                    if(count($sellerInfo['sub_apps'])==1) {
                        $subUser = $subUserCollections->findOne(['apps._id' => $sellerInfo['sub_apps'][0]], $options);

                        // print_r($subUser);die('subUser');
                        $subUsers = [$subUser];
                    }
                    else {
                        $subUsers = $subUserCollections->find(['apps._id' => ['$in' => $sellerInfo['sub_apps']]], $options)->toArray();

                        // print_r($subUsers);die('subUserS');
                    }

                    if(isset($subUsers) && !empty($subUsers)) {
                        $platforms = [];
                        foreach ($subUsers as $subUser) {
                            // print_r($subUser);die;
                            $platform = $this->getPlatformFromSubUser($sellerInfo, $subUser);
                            if($platform) {
                                $platforms[] = $platform;
                            }
                        }

                        $platformsArr = array_unique($platforms);
                        $platformsStr = implode(',', $platformsArr);
                        // die('unique platforms');

                        return [
                            'success' => true,
                            'platforms' => $platformsStr
                        ];
                    }
              return [
                            'success' => false,
                            'message' => 'sub-user not found.'
                        ];

                    // print_r($sellerInfo);die;
                } else {
                    return [
                        'success' => false,
                        'message' => 'seller not found.'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => 'db credentials are invalid.'
                ];
            }

        } else {
            return [
                'success' => false,
                'message' => 'sellerId is invalid.'
            ];
        }
    }

    public function bulkLinkingInitiate()
    {
        $messageArray = [
            'appCode'       => 'dafault',
            'appTag'        => 'amazon_sales_channel',
            'user_id'       => '631b2edad37bd859a9292e73',
            'username'      => 'get-casely.myshopify.com',
            'sourceShopId'  => '22852',
            'targetShopId'  => '22855',
            'sourceMarketplace'=> 'shopify',
            'targetMarketplace'=> 'amazon' 
        ];

        if (isset($messageArray['appCode'])) {
            $this->di->getAppCode()->set($messageArray['appCode']);
        }

        if (isset($messageArray['appTag'])) {
            $this->di->getAppCode()->setAppTag($messageArray['appTag']);
        }
        
        if(isset($messageArray['user_id'])) {
            $user = \App\Core\Models\User::findFirst([['user_id' => (string) $messageArray['user_id']]]);
        }

        if ($user) {
            $user->id = (string) $user->_id;
            $this->di->setUser($user);

            if ($this->di->getConfig()->has('plan_di')) {
                $planDi = $this->di->getConfig()->get('plan_di')->toArray();
                if (!empty($planDi) && isset($planDi['enabled'], $planDi['class'], $planDi['method']) && $planDi['enabled'] && !empty($planDi['class']) && !empty($planDi['method'])) {
                    if (class_exists($planDi['class'])) {
                        $planObj = $this->di->getObjectManager()->create($planDi['class']);
                        if (method_exists($planObj, $planDi['method'])) {
                            $method = $planDi['method'];
                            $this->di->setPlan($planObj->$method($user->id));
                        }
                    }
                }
            }

            $decodedToken = [
                'role' => 'admin',
                'user_id' => $messageArray['user_id'] ?? null,
                'username' => $messageArray['username'] ?? null,
            ];

            $this->di->getRegistry()->setDecodedToken($decodedToken);

            $filePath = '/var/www/html/home/var/log/get-casely-linking.csv';
            $resp = $this->di->getObjectManager()->get('App\Amazon\Components\BulkLinking')
            ->bulkManualLinkingCSV($filePath, $messageArray);
            var_dump($resp);
            print($resp);die;
        }
        else {
            die('invalid user');
        }
    }

    public function getPlatformFromSubUser($seller_info, $sub_user): string|false
    {
        $SHOPIFY_AMAZON_APP_SUB_APP_ID = 2;
     // print_r($sub_user);die('getPlatformFromSubUser');
     if (in_array($SHOPIFY_AMAZON_APP_SUB_APP_ID, $seller_info['sub_apps'])) {
         return 'Shopify';
     }
     if (str_contains((string) $sub_user['domain'], 'module/cedamazon')) {
         return 'Prestashop';
     }
     if (str_contains((string) $sub_user['domain'], 'cedamazon')) {
         return 'Opencart';
     }
     if (str_starts_with((string) $sub_user['username'], 'wooced_')) {
         return 'Woocommerce';
     }
     if (str_contains((string) $sub_user['domain'], 'demo2.cedcommerce.com/test/test2.php')) {
         return 'Magento';
     }

        if ($sub_user['name'] == 'amazon_importer_spapi') {
         return 'Importer';
     }

        // elseif(strpos($sub_user['domain'], 'importer.sellernext.com') !== false) {
        //     return 'Importer';
        // }

        return false;
    }

    public function writeNewCsv($file_path, $data, $append=true): void
    {
        if($append) {
            $fp = fopen($file_path, 'a');
        } else {
            $fp = fopen($file_path, 'w');
        }

        fputcsv($fp, $data);

        fclose($fp);
    }

    public function testGetAttributeFileCache(): void
    {
        $messageArray = [
            'user_id' => '63b80bbb2a82bfd6250729e5',
            'username' => 'amazon-multi-account.myshopify.com'
        ];
        $user = User::findFirst([['_id' => $messageArray['user_id']]]);
        $user->id = (string) $user->_id;
        $this->di->setUser($user);
        $decodedToken = [
            'role' => 'admin',
            'user_id' => $messageArray['user_id'] ?? null,
            'username' => $messageArray['username'] ?? null,
        ];
        $this->di->getRegistry()->setDecodedToken($decodedToken);

        /*
            luggage / luggage / 9385555031
            beauty / sunscreen / 1374338031
            clothing / saree / 1953210031
            baby / pacifier / 0
            baby / teether / 0
            baby / babyproducts / 16085626031
            biss_food_services / FoodServiceSupply / 1380216031
            beauty / shampoo / 1374282031
            luggage / wallet / 2917491031
            gift_cards / physicalgiftcard / 4048868031
            clothing / dress / 1953152031
            clothing / scarf / 5250446031
        */

        $data = [
            'target' => [
                'shopId' => '2',
            ],
            'data' => [
                'category' => 'biss_food_services',//'book',
                'sub_category' => 'FoodServiceSupply',//'bookloader',
                'browser_node_id' => '1380216031',//'1318065031',
                'barcode_exemption' => false
            ]
        ];
        $cat = $this->di->getObjectManager()->create(Category::class)->init($data);
        $resp = $cat->getAmazonAttributes($data);
        var_dump($resp);

        die('end here...');
    }

    public function findDuplicateProducts()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $mongo->initializeDb('db_home'); // for demo
        // $mongo->initializeDb('db_homecopy'); // for production

        $collection_name = 'duplicate_product_users_collection';

        $users = $this->fetchUserForProcessing($collection_name);
        // print_r($users);die;
        
        $count = 0;
        while(!empty($users)) {
            $processedUserId = '';
            foreach ($users as $key => $user) {
                echo ++$count.',';
                $insert_products = [];

                $collection = $mongo->getCollectionForTable('product_container');
                // db.getCollection('product_container').aggregate(
                //   [
                //     {
                //       $match: {
                //         user_id: '64bed5c9908eedebd3023072',
                //         shop_id: '95620'
                //       }
                //     },
                //     {
                //       $group: {
                //         _id: {
                //           user_id: '$user_id',
                //           shop_id: '$shop_id',
                //           source_product_id: '$source_product_id'
                //         },
                //         user_id: { $first: '$user_id' },
                //         shop_id: { $first: '$shop_id' },
                //         source_product_id: {
                //           $first: '$source_product_id'
                //         },
                //         pro_count: { $sum: 1 }
                //       }
                //     },
                //     { $match: { pro_count: { $gt: 1 } } }
                //   ],
                //   { maxTimeMS: 60000, allowDiskUse: true }
                // );
                if(isset($user['shops']) && !empty($user['shops'])) {
                    foreach ($user['shops'] as $key => $shop) {
                        $aggregation = [];

                        // $aggregation[] = ['$sort' => ['user_id' => 1, 'source_product_id' => 1]];
                        $aggregation[] = ['$match' => ['user_id' => $user['user_id'], 'shop_id' => $shop['_id']]];

                        $aggregation[] = ['$group' => [
                            '_id' => [
                                'user_id' => '$user_id',
                                'shop_id' => '$shop_id',
                                'source_product_id' => '$source_product_id'
                            ],
                            'user_id' => ['$first' => '$user_id'],
                            'shop_id' => ['$first' => '$shop_id'],
                            'source_product_id' => ['$first' => '$source_product_id'],
                            'source_marketplace' => ['$first' => '$source_marketplace'],
                            'pro_count' => ['$sum' => 1]
                        ]];

                        // if($user['username']=='cedcommerce-plusteststore.myshopify.com') {
                        //     print_r($aggregation);die;
                        // }

                        $aggregation[] = ['$match' => ['pro_count' => ['$gt' => 1 ]]];

                        // //$aggregation[] = ['$out' => 'duplicate_products_collection'];
                        // print_r($aggregation);die;
                        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                        $duplicate_products = $collection->aggregate($aggregation, $options)->toArray();
                        // print_r($duplicate_products);die;
                        if(!empty($duplicate_products))
                        {
                            // $insert_products = [];
                            // print_r($duplicate_products);die('found a user');
                            foreach ($duplicate_products as $duplicate_product)
                            {
                                // unset($duplicate_product['_id']);
                                $insert_products[] = $duplicate_product;
                            }
                            
                            // $collectionObj = $mongo->getCollectionForTable('duplicate_products_collection');
                            // $bulkInsertObj = $collectionObj->insertMany($insert_products);
                        }
                        else
                        {
                            // echo 'No duplicate source_product_ids found.';
                        }
                    }
                }

                if(!empty($insert_products)) {
                    $collectionObj = $mongo->getCollectionForTable('duplicate_products_collection');
                    $bulkInsertObj = $collectionObj->insertMany($insert_products);
                }

                $mongo->getCollectionForTable($collection_name)->updateOne(['_id' => 'last_processed_user'], ['$set' => ['value' => $user['user_id']]], ['upsert'=>true]);

                $processedUserId = $user['user_id'];
            } 

            $users = [];
            $users = $this->fetchUserForProcessing($collection_name, $processedUserId);
        } 
        die('all users processed.'); 
    }

    private function fetchUserForProcessing($collectionName, $lastProcessedUserId=null)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $mongo->initializeDb('db_home'); // for demo
        // $mongo->initializeDb('db_homecopy'); // for production

        if(is_null($lastProcessedUserId)) {
            $lastProcessedUser = $mongo->getCollectionForTable($collectionName)->findOne(['_id' => 'last_processed_user'], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            if(!empty($lastProcessedUser)) {
                $lastProcessedUserId = $lastProcessedUser['value'];
            }
        }
        // print_r($lastProcessedUserId);die;

        $userDetails = $mongo->getCollectionForTable('user_details');
        if(!empty($lastProcessedUserId)) {
            $users = $userDetails->find(
                ['_id' => ['$gt' => new \MongoDB\BSON\ObjectId($lastProcessedUserId)]], 
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => 100,
                    'projection' => ['username' => 1, 'user_id' => 1, 'shops._id' => 1, 'shops.marketplace' => 1, 'shops.plan_name' => 1, 'shops.plan_display_name' => 1, 'shops.email' => 1, 'shops.country' => 1, 'shops.phone' => 1, 'shops.store_closed_at' => 1, 'shops.apps' => 1, 'shops.warehouses' => 1],
                    'sort' => ['_id' => 1]
                ]
            )->toArray();
        }
        else {
            $users = $userDetails->find(
                // ['user_id' => '65cbe879496630c39a00edc2'], 
                [], 
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => 100,
                    'projection' => ['username' => 1, 'user_id' => 1, 'shops._id' => 1, 'shops.marketplace' => 1, 'shops.plan_name' => 1, 'shops.plan_display_name' => 1, 'shops.email' => 1, 'shops.country' => 1, 'shops.phone' => 1, 'shops.store_closed_at' => 1, 'shops.apps' => 1],
                    'sort' => ['_id' => 1]
                ]
            )->toArray();
        }

        return $users;
    }

    public function prepareOrderData()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $mongo->initializeDb('db_home');

        $collection_name = 'users_wise_script_execution_collection';

        $users = $this->fetchUserForProcessing($collection_name);
        
        $count = 0;
        while(!empty($users)) {
            $processedUserId = '';
            $insert_rows = [];
            foreach ($users as $key => $user) {
                echo ++$count.',';
                $userId = (string)$user['_id'];
                $row_data = [
                    'user_id' => $userId,
                    'username' => $user['username'] ?? ''
                ];

                $salesContainerCollection = $mongo->getCollectionForTable('sales_container');
                $orderContainerCollection = $mongo->getCollectionForTable('order_container');
                
                // if(isset($user['shops']) && !empty($user['shops'])) {
                //     foreach ($user['shops'] as $key => $shop) {
                        $totalOrders = $orderContainerCollection->count([
                            'user_id' => $userId,
                            'object_type' => "source_order",
                            'marketplace' => "amazon"
                        ]);

                        if($totalOrders > 0) {
                            $totalFBAOrders = $orderContainerCollection->count([
                                'user_id' => $userId,
                                'object_type' => "source_order",
                                'marketplace' => "amazon",
                                'fulfilled_by' => "other"
                            ]);

                            $row_data['FBA_Orders'] = $totalFBAOrders;
                            $row_data['FBM_Orders'] = $totalOrders-$totalFBAOrders;

                            // $aggregation = [
                            //     [
                            //         '$match' => [
                            //             'user_id' => $userId
                            //         ]
                            //     ], 
                            //     [
                            //         '$addFields' => [
                            //             'year' => [
                            //                 '$year' => '$order_date'
                            //             ]
                            //         ]
                            //     ], 
                            //     [
                            //         '$group' => [
                            //             '_id' => ['year' => '$year'], 
                            //             'orderCount' => ['$sum' => '$order_count'], 
                            //             'orderAmount' => ['$sum' => '$order_amount'], 
                            //             'year' => ['$first' => '$year']
                            //         ]
                            //     ]
                            // ];
                            $aggregation = [
                                [
                                    '$match' => [
                                        'user_id' => $userId
                                    ]
                                ], 
                                [
                                    '$group' => [
                                        '_id' => ['user_id' => '$user_id'], 
                                        'orderCount' => ['$sum' => '$order_count'], 
                                        'orderAmount' => ['$sum' => '$order_amount']
                                    ]
                                ]
                            ];

                            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                            $GMV_Data = $salesContainerCollection->aggregate($aggregation, $options)->toArray();
                            // print_r($GMV_Data);die;
                            if(!empty($GMV_Data))
                            {
                                foreach ($GMV_Data as $GMV) {
                                    $row_data['GMV'] = $GMV['orderAmount'];
                                    $row_data['total_order_count'] = $GMV['orderCount'];
                                }
                            }
                            else
                            {
                                $row_data['GMV'] = 0.00;
                                $row_data['total_order_count'] = 0;
                            }

                            $insert_rows[] = $row_data;
                        }
                        else {
                            $row_data['FBA_Orders'] = 0;
                            $row_data['FBM_Orders'] = 0;
                        }
                                                
                //     }
                // }

                $processedUserId = $userId;
            }

            $mongo->getCollectionForTable($collection_name)->updateOne(['_id' => 'last_processed_user'], ['$set' => ['value' => $userId, 'count' => $count]], ['upsert'=>true]);

            if(!empty($insert_rows)) {
                $collectionObj = $mongo->getCollectionForTable('users_sales_data_new');
                $bulkInsertObj = $collectionObj->insertMany($insert_rows);
            }

            $users = [];
            $users = $this->fetchUserForProcessing($collection_name, $processedUserId);
        } 
        die('all users processed.'); 
    }

    public function checkIfProductExistInRefine()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        // $mongo->initializeDb('db_home');

        $collection_name = 'script_product_exist_in_refine';

        $users = $this->fetchUserForProcessing($collection_name);
        // print_r($users);die;
        
        $count = 0;
        while(!empty($users)) {
            $processedUserId = '';
            foreach ($users as $key => $user) {
                echo ++$count;
                $insert_products = [];

                $collection = $mongo->getCollectionForTable('product_container');
                
                if(isset($user['shops']) && !empty($user['shops'])) {
                    $shopifyShop = [];
                    // $amazonShops = [];
                    $amazonShopIds = [];
                    foreach ($user['shops'] as $key => $shop) {

                        if($shop['marketplace'] === 'shopify') {
                            $shopifyShop = $shop;
                        } else {
                            // $amazonShops[] = $shop;
                            $amazonShopIds[] = $shop['_id'];
                        }
                    }

                    // if($shopifyShop['apps'][0]['app_status'] === 'uninstall' || isset($shopifyShop['apps'][0]['uninstall_date'])) {}

                    if(!empty($shopifyShop) && !empty($amazonShopIds))
                    {
                        $filter = [
                            'user_id' => $user['user_id'],
                            'shop_id' => $shopifyShop['_id']
                        ];

                        $options = [
                            'typeMap' => ['root' => 'array', 'document' => 'array'],
                            'limit' => 1000,
                            'projection' => ['source_product_id' => 1, 'user_id' => 1, 'shop_id' => 1, 'container_id' => 1, 'visibility' => 1, 'type' => 1],
                            'sort' => ['_id' => 1]
                        ];

                        $product_container_data = $collection->find($filter, $options)->toArray();
                        // print_r($product_container);die;

                        $productCount = 0;
                        while(!empty($product_container_data)) {
                            $lastProcessedProductId = '';
                            $missing_products = [];

                            foreach ($product_container_data as $key => $product) {
                                $productCount++;

                                $existanceResponse = $this->checkExistanceInRefine($user, $shopifyShop, $amazonShopIds, $product);
                                if(!$existanceResponse) {
                                    $missing_products[] = [
                                        'user_id' => $user['user_id'],
                                        'source_shop_id' => $shopifyShop['_id'],
                                        'target_shop_id' => ['$in' => $amazonShopIds],
                                        'container_id' => $product['container_id'],
                                        'source_product_id' => $product['source_product_id']
                                    ];
                                }

                                $lastProcessedProductId = $product['_id'];
                            }

                            if(!empty($missing_products)) {
                                $collectionObj = $mongo->getCollectionForTable('products_missing_in_refine');
                                $collectionObj->insertMany($missing_products);
                            }

                            if(!empty($lastProcessedProductId)) {
                                if(is_numeric($lastProcessedProductId)) {
                                    $filter['_id'] = ['$gt' => $lastProcessedProductId];
                                } else {
                                    // $filter['_id'] = ['$gt' => new \MongoDB\BSON\ObjectId($lastProcessedProductId->__toString())];
                                    $filter['_id'] = ['$gt' => $lastProcessedProductId];
                                }
                                
                                $product_container_data = $collection->find($filter, $options)->toArray();
                            }
                        }
                        // echo '-'.$productCount;
                    }
                    else {
                        // Either Invalid user or Amazon Shop not connected
                    }

                    // if(!empty($insert_products)) {
                    //     $collectionObj = $mongo->getCollectionForTable('duplicate_products_collection');
                    //     $bulkInsertObj = $collectionObj->insertMany($insert_products);
                    // }
                }

                $mongo->getCollectionForTable($collection_name)->updateOne(['_id' => 'last_processed_user'], ['$set' => ['value' => $user['_id']->__toString(), 'count' => $count]], ['upsert'=>true]);

                $processedUserId = $user['_id']->__toString();

                echo ', ';
            } 

            $users = [];
            $users = $this->fetchUserForProcessing($collection_name, $processedUserId);
        } 
        die('all users processed.'); 
    }

    public function checkExistanceInRefine($user, $shopifyShop, $amazonShopIds, $product)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $refine_collection = $mongo->getCollectionForTable('refine_product');

        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ];

        $refine_products = [];

        if($product['visibility'] == 'Catalog and Search') {
            if($product['container_id'] === $product['source_product_id']) {
                // container of variant
                $filter = [
                    'user_id' => $user['user_id'],
                    'source_shop_id' => $shopifyShop['_id'],
                    'target_shop_id' => ['$in' => $amazonShopIds],
                    'container_id' => $product['container_id']
                ];

                $options['projection'] =  ['user_id' => 1, 'source_shop_id' => 1, 'target_shop_id' => 1, 'container_id' => 1, 'source_product_id' => 1, 'items.source_product_id' => 1];

                $refine_products = $refine_collection->find($filter, $options)->toArray();
                // print_r($refine_products);die('checkIfProductExistsInRefine die1');
            }
            else {
                if($product['type'] === 'simple') {
                    // simple product
                    $filter = [
                        'user_id' => $user['user_id'],
                        'source_shop_id' => $shopifyShop['_id'],
                        'target_shop_id' => ['$in' => $amazonShopIds],
                        'container_id' => $product['container_id']
                    ];

                    $options['projection'] =  ['user_id' => 1, 'source_shop_id' => 1, 'target_shop_id' => 1, 'container_id' => 1, 'source_product_id' => 1, 'items.source_product_id' => 1];

                    $refine_products = $refine_collection->find($filter, $options)->toArray();
                    // print_r($refine_products);die('checkIfProductExistsInRefine die2');
                }
                else {
                    // unrecognised type of product
                    die('unrecognised type of product1');
                }
            }
        }
        else {
            if($product['container_id'] !== $product['source_product_id']) {
                // child of variant
                 $aggregation = [
                    [
                        '$match' => [
                            'user_id' => $user['user_id'], 'source_shop_id' => $shopifyShop['_id'], 'container_id' => $product['container_id']
                        ]
                    ],
                    [
                        '$project' => [
                            'user_id' => 1, 'source_shop_id' => 1, 'target_shop_id' => 1, 'container_id' => 1, 'source_product_id' => 1, 'items.source_product_id' => 1
                        ]
                    ],
                    [
                        '$unwind' => [
                            'path' => '$items'
                        ]
                    ],
                    [
                        '$match' => [
                            'target_shop_id' => ['$in' => $amazonShopIds],
                            'items.source_product_id' => $product['source_product_id']
                        ]
                    ]
                ];

                $refine_products = $refine_collection->aggregate($aggregation, $options)->toArray();
                // print_r($refine_products);die('checkIfProductExistsInRefine die3');
                
            }
            else {
                // unrecognised type of product
                die('unrecognised type of product2');
            }
        }

        // print_r($refine_products);die;

        if(!empty($refine_products))
        {
            if(count($refine_products) === count($amazonShopIds)) {
                return true;
            }
            else {
                // print_r($refine_products);die('ye product ni hai refine me');
                return false;
            }
        }
    }

    public function checkIfRefineProductExistInProductContainer()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        // $mongo->initializeDb('db_home');

        $collection_name = 'script_refine_product_exist_in_product_container';

        $users = $this->fetchUserForProcessing($collection_name);
        // print_r($users);die;
        
        $count = 0;
        while(!empty($users)) {
            $processedUserId = '';
            foreach ($users as $key => $user) {
                echo ++$count;
                $insert_products = [];

                $refine_collection = $mongo->getCollectionForTable('refine_product');
                
                if(isset($user['shops']) && !empty($user['shops'])) {
                    $shopifyShop = [];
                    // $amazonShops = [];
                    $amazonShopIds = [];
                    foreach ($user['shops'] as $key => $shop) {

                        if($shop['marketplace'] === 'shopify') {
                            $shopifyShop = $shop;
                        } else {
                            // $amazonShops[] = $shop;
                            $amazonShopIds[] = $shop['_id'];
                        }
                    }

                    // if($shopifyShop['apps'][0]['app_status'] === 'uninstall' || isset($shopifyShop['apps'][0]['uninstall_date'])) {}

                    if(!empty($shopifyShop) && !empty($amazonShopIds))
                    {
                        /*db.getCollection('refine_product').aggregate(
                          [
                            {
                              $match: {
                                user_id: '672caa080d103c08e10b4758'
                              }
                            },
                            {
                              $match: {
                                _id: {
                                  $gt: ObjectId(
                                    '672defb8685b52a9a78e97e6'
                                  )
                                }
                              }
                            },
                            { $sort: { _id: 1 } },
                            {
                              $group: {
                                _id: {
                                  user_id: '$user_id',
                                  source_shop_id: '$source_shop_id',
                                  container_id: '$container_id'
                                },
                                user_id: { $first: '$user_id' },
                                source_shop_id: {
                                  $first: '$source_shop_id'
                                },
                                target_shop_id: {
                                  $first: '$target_shop_id'
                                },
                                container_id: { $first: '$container_id' },
                                source_product_id: {
                                  $first: '$source_product_id'
                                },
                                items: { $first: '$items' }
                              }
                            },
                            { $limit: 1000 },
                            {
                              $project: {
                                user_id: 1,
                                source_shop_id: 1,
                                target_shop_id: 1,
                                container_id: 1,
                                source_product_id: 1,
                                'items.source_product_id': 1
                              }
                            },
                            { $unwind: { path: '$items' } }
                          ],
                          { maxTimeMS: 60000, allowDiskUse: true }
                        );*/
                        $aggregation = [
                            [
                                '$match' => [
                                    'user_id' => $user['user_id']
                                ]
                            ],
                            [
                                '$sort' => [ '_id' => 1 ]
                            ],
                            [
                                '$group' => [
                                    '_id' => ['user_id' => '$user_id', 'source_shop_id' => '$source_shop_id', 'container_id' => '$container_id'], 
                                    'user_id' => ['$first' => '$user_id'],
                                    'source_shop_id' => ['$first' => '$source_shop_id'],
                                    'target_shop_id' => ['$first' => '$target_shop_id'],
                                    'container_id' => ['$first' => '$container_id'],
                                    'source_product_id' => ['$first' => '$source_product_id'],
                                    'items' => ['$first' => '$items']
                                ]
                            ],
                            [
                                '$limit' => 1000
                            ],
                            [
                                '$project' => [
                                    'user_id' => 1, 'source_shop_id' => 1, 'target_shop_id' => 1, 'container_id' => 1, 'source_product_id' => 1, 'items.source_product_id' => 1
                                ]
                            ],
                            [
                                '$unwind' => [
                                    'path' => '$items'
                                ]
                            ]
                        ];

                        $options = [
                            'typeMap' => ['root' => 'array', 'document' => 'array'],
                        ];

                        $refine_products = $refine_collection->aggregate($aggregation, $options)->toArray();
                        // print_r($refine_products);die;

                        $productCount = 0;
                        while(!empty($refine_products)) {
                            $lastProcessedProductId = '';
                            $missing_products = [];

                            foreach ($refine_products as $key => $product) {
                                $productCount++;

                                // TODO :

                                $lastProcessedProductId = $product['_id'];
                            }

                            // if(!empty($missing_products)) {
                            //     $collectionObj = $mongo->getCollectionForTable('products_missing_in_refine');
                            //     $collectionObj->insertMany($missing_products);
                            // }

                            if(!empty($lastProcessedProductId)) {
                                $aggregation = [
                                    [
                                        '$match' => [
                                            'user_id' => $user['user_id']
                                        ]
                                    ]
                                ];

                                if(is_numeric($lastProcessedProductId)) {
                                    $aggregation[] = [
                                        'match' => ['$gt' => $lastProcessedProductId]
                                    ];
                                } else {
                                    // $aggregation[] = [
                                    //     'match' => ['$gt' => new \MongoDB\BSON\ObjectId($lastProcessedProductId->__toString())]
                                    // ];
                                    $aggregation[] = [
                                        'match' => ['$gt' => $lastProcessedProductId]
                                    ];
                                }

                                $aggregation[] = [
                                    '$sort' => [ '_id' => 1 ]
                                ];

                                 $aggregation[] = [
                                    '$group' => [
                                        '_id' => ['user_id' => '$user_id', 'source_shop_id' => '$source_shop_id', 'container_id' => '$container_id'], 
                                        'user_id' => ['$first' => '$user_id'],
                                        'source_shop_id' => ['$first' => '$source_shop_id'],
                                        'target_shop_id' => ['$first' => '$target_shop_id'],
                                        'container_id' => ['$first' => '$container_id'],
                                        'source_product_id' => ['$first' => '$source_product_id'],
                                        'items' => ['$first' => '$items']
                                    ]
                                ];

                                $aggregation[] = [
                                    '$limit' => 1000
                                ];

                                $aggregation[] = [
                                    '$project' => [
                                        'user_id' => 1, 'source_shop_id' => 1, 'target_shop_id' => 1, 'container_id' => 1, 'source_product_id' => 1, 'items.source_product_id' => 1
                                    ]
                                ];

                                $aggregation[] = [
                                    '$unwind' => [
                                        'path' => '$items'
                                    ]
                                ];
                                
                                $refine_products = $refine_collection->aggregate($aggregation, $options)->toArray();
                            }
                        }
                        // echo '-'.$productCount;
                    }
                    else {
                        // Either Invalid user or Amazon Shop not connected
                    }

                    // if(!empty($insert_products)) {
                    //     $collectionObj = $mongo->getCollectionForTable('duplicate_products_collection');
                    //     $bulkInsertObj = $collectionObj->insertMany($insert_products);
                    // }
                }

                $mongo->getCollectionForTable($collection_name)->updateOne(['_id' => 'last_processed_user'], ['$set' => ['value' => $user['_id']->__toString(), 'count' => $count]], ['upsert'=>true]);

                $processedUserId = $user['_id']->__toString();

                echo ', ';
            } 

            $users = [];
            $users = $this->fetchUserForProcessing($collection_name, $processedUserId);
        } 
        die('all users processed.'); 
    }

    public function findDuplicateProductsInRefine()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        // $mongo->initializeDb('db_home');

        $collection_name = 'script_duplicate_product_in_refine';

        $users = $this->fetchUserForProcessing($collection_name);
        // print_r($users);die;
        
        $count = 0;
        while(!empty($users)) {
            $processedUserId = '';
            foreach ($users as $key => $user) {
                echo ++$count.',';
                $insert_products = [];

                $refine_collection = $mongo->getCollectionForTable('refine_product');

                if(isset($user['shops']) && !empty($user['shops'])) {
                    foreach ($user['shops'] as $key => $shop) {
                        $aggregation = [];

                        // $aggregation[] = ['$sort' => ['user_id' => 1, 'source_product_id' => 1]];
                        $aggregation[] = ['$match' => ['user_id' => $user['user_id'], 'shop_id' => $shop['_id']]];

                        $aggregation[] = [
                            '$group' => [
                                '_id' => ['user_id' => '$user_id', 'source_shop_id' => '$source_shop_id', 'target_shop_id' => '$target_shop_id', 'container_id' => '$container_id'], 
                                'user_id' => ['$first' => '$user_id'],
                                'source_shop_id' => ['$first' => '$source_shop_id'],
                                'target_shop_id' => ['$first' => '$target_shop_id'],
                                'container_id' => ['$first' => '$container_id'],
                                'source_product_id' => ['$first' => '$source_product_id'],
                                'items' => ['$first' => '$items'],
                                'pro_count' => ['$sum' => 1]
                            ]
                        ];

                        $aggregation[] = ['$match' => ['pro_count' => ['$gt' => 1 ]]];

                        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                        $duplicate_products = $refine_collection->aggregate($aggregation, $options)->toArray();
                        // print_r($duplicate_products);die;
                        if(!empty($duplicate_products))
                        {
                            // $insert_products = [];
                            // print_r($duplicate_products);die('found a user');
                            foreach ($duplicate_products as $duplicate_product)
                            {
                                // unset($duplicate_product['_id']);
                                $insert_products[] = $duplicate_product;
                            }                            
                        }
                        else
                        {
                            // echo 'No duplicate source_product_ids found.';
                        }
                    }
                }

                if(!empty($insert_products)) {
                    $collectionObj = $mongo->getCollectionForTable('duplicate_refine_products_collection');
                    $bulkInsertObj = $collectionObj->insertMany($insert_products);
                }

                $mongo->getCollectionForTable($collection_name)->updateOne(['_id' => 'last_processed_user'], ['$set' => ['value' => $user['user_id'], 'count' => $count]], ['upsert'=>true]);

                $processedUserId = $user['user_id'];
            } 

            $users = [];
            $users = $this->fetchUserForProcessing($collection_name, $processedUserId);
        } 
        die('all users processed.'); 
    }

    /**
     * Find Duplicate edited docs with wrong container_id
     */
    public function findDuplicateEditedProducts()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $mongo->initializeDb('db_home'); // for demo
        // $mongo->initializeDb('db_homecopy'); // for production

        $collection_name = 'processed_users_of_duplicate_edited_docs';

        $users = $this->fetchUserForProcessing($collection_name);
        // print_r($users);die;
        
        $count = 0;
        while(!empty($users)) {
            $processedUserId = '';
            foreach ($users as $key => $user) {
                echo ++$count.',';
                $insert_products = $invalid_profile_insert_data = [];

                $collection = $mongo->getCollectionForTable('product_container');
                
                // [
                //     ['$match' => ['user_id' => '6726600aa77bc7b5cd0ca574', 'shop_id' => '582']], 
                //     ['$group' => ['_id' => ['source_product_id' => '$source_product_id'], 'user_id' => ['$first' => '$user_id'], 'container_id' => ['$first' => '$container_id'], 'con_ids' => ['$push' => '$container_id'], 'source_product_id' => ['$first' => '$source_product_id'], 'count' => ['$sum' => 1]]], 
                //     ['$match' => ['count' => ['$gt' => 1]]], 
                //     ['$count' => 'string']
                // ]

                $duplicateEditedCollectionObj = $mongo->getCollectionForTable('duplicate_edited_products_wale_users');
                $profileInChildCollectionObj = $mongo->getCollectionForTable('profile_in_child_wale_users');

                if(isset($user['shops']) && !empty($user['shops'])) {
                    foreach ($user['shops'] as $key => $shop) {
                        if(isset($shop['marketplace']) && $shop['marketplace']=='amazon') {
                            $aggregation = [];

                            $aggregation[] = ['$match' => ['user_id' => $user['user_id'], 'shop_id' => $shop['_id']]];

                            $aggregation[] = ['$group' => [
                                '_id' => [
                                    // 'user_id' => '$user_id',
                                    // 'shop_id' => '$shop_id',
                                    'source_product_id' => '$source_product_id'
                                ],
                                'user_id' => ['$first' => '$user_id'],
                                'shop_id' => ['$first' => '$shop_id'],
                                'container_ids' => ['$push' => '$container_id'], 
                                'container_id' => ['$first' => '$container_id'],
                                'source_product_id' => ['$first' => '$source_product_id'],
                                'source_marketplace' => ['$first' => '$source_marketplace'],
                                'product_count' => ['$sum' => 1]
                            ]];

                            // if($user['username']=='cedcommerce-plusteststore.myshopify.com') {
                            //     print_r($aggregation);die;
                            // }

                            $aggregation[] = ['$match' => ['product_count' => ['$gt' => 1 ]]];

                            $aggregation[] = ['$count' => 'duplicate_data'];

                            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                            $duplicate_products = $collection->aggregate($aggregation, $options)->toArray();
                            
                            if(!empty($duplicate_products) && isset($duplicate_products[0]['duplicate_data']))
                            {
                                // print_r('user_id:'.$user['user_id']. '/ shop_id:'.$shop['_id']);
                                // print_r($duplicate_products);die('yahan tk aa rha hai');

                                $insert_data[] = [
                                    'user_id' => $user['user_id'],
                                    'shop_id' => $shop['_id'],
                                    'count'   => $duplicate_products[0]['duplicate_data'] ?? 0
                                ];

                                // $bulkInsertObj = $insertCollectionObj->insertMany($insert_data);
                            }
                            else
                            {
                                // echo 'No duplicate source_product_ids found.';
                            }
                        }
                        elseif(isset($shop['marketplace']) && $shop['marketplace']=='shopify') {
                            
                            // [
                            //     ['$match' => ['user_id' => '61fc94e3366fc1178e4a43ba', 'type' => 'simple', 'visibility' => 'Not Visible Individually', 'profile.profile_id' => ['$ne' => null]]]
                            // ];
                            $aggregation = [];

                            $aggregation[] = ['$match' => [
                                'user_id' => $user['user_id'], 
                                'shop_id' => $shop['_id'],
                                'type' => 'simple',
                                'visibility' => 'Not Visible Individually',
                                'profile.profile_id' => ['$ne' => null]
                            ]];

                            $aggregation[] = ['$count' => 'count_data'];

                            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                            $count_data = $collection->aggregate($aggregation, $options)->toArray();
                            
                            if(!empty($count_data) && isset($count_data[0]['count_data']))
                            {
                                $invalid_profile_insert_data[] = [
                                    'user_id' => $user['user_id'],
                                    'shop_id' => $shop['_id'],
                                    'count'   => $count_data[0]['count_data'] ?? 0
                                ];
                                // $bulkInsertObj = $profileInChildCollectionObj->insertMany($invalid_profile_insert_data);
                            }
                            else
                            {
                                // echo 'No duplicate source_product_ids found.';
                            }
                            
                        }
                        else {
                            // echo 'Invalid shop';
                        }
                    }
                }

                if(!empty($insert_data)) {
                    $bulkInsertObj = $duplicateEditedCollectionObj->insertMany($insert_data);
                    $insert_data = [];
                }

                if(!empty($invalid_profile_insert_data)) {
                    $bulkInsertObj = $profileInChildCollectionObj->insertMany($invalid_profile_insert_data);
                    $invalid_profile_insert_data = [];
                }

                $mongo->getCollectionForTable($collection_name)->updateOne(['_id' => 'last_processed_user'], ['$set' => ['value' => $user['user_id']]], ['upsert'=>true]);

                $processedUserId = $user['user_id'];
            } 

            $users = [];
            $users = $this->fetchUserForProcessing($collection_name, $processedUserId);
        } 
        die('all users processed.'); 
    }

    public function prepareOrderData24Feb()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $mongo->initializeDb('db_home');

        $collection_name = 'processed_users_of_prepareOrderData25Feb';

        $users = $this->fetchUserForProcessing($collection_name);
        
        $count = 0;
        while(!empty($users)) {
            $processedUserId = '';
            $insert_rows = [];
            foreach ($users as $key => $user) {
                echo ++$count.',';
                $userId = (string)$user['_id'];

                if(isset($user['shops']) && !empty($user['shops'])) {
                    $shopifyShop = [];
                    $amazonShopCount = 0;
                    $shopStatus = 'install';
                    foreach ($user['shops'] as $key => $shop) {
                        if($shop['marketplace'] === 'shopify') {
                            $shopifyShop = $shop;
                            if(isset($shop['store_closed_at'])) {
                                $shopStatus = 'close';
                            }
                            elseif(isset($shop['apps'])) {
                                $currentShop = current($shop['apps']);
                                if($currentShop['app_status'] == 'uninstall') {
                                    $shopStatus = 'uninstall';
                                }
                            }
                        }
                        elseif($shop['marketplace'] === 'amazon') {
                            $amazonShopCount++;
                        }
                    }

                    if(!empty($shopifyShop) && $amazonShopCount>0) {
                        // print_r($shopifyShop);die;
                        $row_data = [
                            'user_id' => $userId,
                            'username' => $user['username'] ?? '',
                            'amazon_shop_count' => $amazonShopCount,
                            'plan_name' => $shopifyShop['plan_name'],
                            'plan_display_name' => $shopifyShop['plan_display_name'],
                            'email' => $shopifyShop['email'],
                            'country' => $shopifyShop['country'],
                            'phone' => $shopifyShop['phone'] ?? '',
                            'app_plan_name' => '',
                            'app_plan_type' => '',
                            'app_plan_amount' => 0,
                            'app_plan_discount_type' => '',
                            'app_plan_discount_value' => 0,
                            'GMV' => 0.00,
                            'total_order_count' => 0,
                            'shop_status' => $shopStatus
                        ];
                        $activePlan = $this->di->getObjectManager()->create('\App\Plan\Models\Plan')->getActivePlanForCurrentUser($userId);
                        if (!empty($activePlan)) {
                            $row_data['app_plan_type'] = $activePlan['plan_details']['billed_type'] ?? 'defauly_monthly';
                            $row_data['app_plan_name'] = $activePlan['plan_details']['title'] ?? 'regular';
                            $row_data['app_plan_amount'] = $activePlan['plan_details']['custom_price'] ?? 0;
                            
                            if(!empty($activePlan['plan_details']['discounts'])) {
                                $discount = current($activePlan['plan_details']['discounts']);

                                $row_data['app_plan_discount_type'] = $discount['type'] ?? '';
                                $row_data['app_plan_discount_value'] = $discount['value'] ?? 0;
                            }
                        }

                        $salesContainerCollection = $mongo->getCollectionForTable('sales_container');

                        $aggregation = [
                            [
                                '$match' => [
                                    'user_id' => $userId
                                ]
                            ], 
                            [
                                '$match' => [
                                    'order_date' => [
                                        '$gte' => new \MongoDB\BSON\UTCDateTime(new DateTime('2024-06-01')),
                                        '$lt' => new \MongoDB\BSON\UTCDateTime(new DateTime('2025-01-01'))
                                    ]
                                ]
                            ],
                            // [
                            //     '$addFields' => [
                            //         'year' => [
                            //             '$year' =>  '$order_date'
                            //         ]
                            //     ]
                            // ],
                            [
                                '$group' => [
                                    // '_id' => ['year' => '$year'],
                                    '_id' => [
                                        'user_id' => '$user_id',
                                        'marketplace_id' => '$marketplace_id'
                                    ],
                                    'orderCount' => ['$sum' => '$order_count'], 
                                    'orderAmount' => ['$sum' => '$order_amount'],
                                    'marketplace_id' => ['$first' => '$marketplace_id'],
                                    'marketplace_currency' => ['$first' => '$marketplace_currency']
                                ]
                            ]
                        ];

                        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                        $GMV_Data = $salesContainerCollection->aggregate($aggregation, $options)->toArray();
                        // if($userId=='6726600aa77bc7b5cd0ca574'){
                        //     var_dump($GMV_Data);die;
                        // }
                        if(!empty($GMV_Data))
                        {
                            // print_r($GMV_Data);die;
                            foreach ($GMV_Data as $GMV) {
                                $order_amount = ''.$GMV['orderAmount'];
                                $row_data['GMV'] += $this->convertCurrency($GMV['marketplace_id'], $order_amount);
                                $row_data['total_order_count'] += $GMV['orderCount'];
                            }
                        }

                        $insert_rows[] = $row_data;
                    }
                }     

                $processedUserId = $userId;
            }

            $mongo->getCollectionForTable($collection_name)->updateOne(['_id' => 'last_processed_user'], ['$set' => ['value' => $userId, 'count' => $count]], ['upsert'=>true]);

            if(!empty($insert_rows)) {
                $collectionObj = $mongo->getCollectionForTable('users_sales_data_25_feb');
                $bulkInsertObj = $collectionObj->insertMany($insert_rows);
            }

            $users = [];
            $users = $this->fetchUserForProcessing($collection_name, $processedUserId);
        } 
        die('all users processed.'); 
    }

    private function convertCurrency($marketplace_id, $amount)
    {
        // $conversionRate = [
        //     //North America
        //     'A2EUQ1WTGCTBG2' => 1.42, // Canada
        //     'ATVPDKIKX0DER' => 1, //US
        //     'A1AM78C64UM0Y8' => 20.48, //Mexico
        //     'A2Q3Y263D00KWC' => 5.73, //Brazil
        //     //Europe
        //     'A28R8C7NBKEWEA' => 0.95, //Ireland
        //     'A1RKKUPIHCS9HS' => 0.95, //Spain
        //     'A1F83G8C2ARO7P' => 0.79, //UK
        //     'A13V1IB3VIYZZH' => 0.95, //France
        //     'AMEN7PMS3EDWL' => 0.95, //Belgium
        //     'A1805IZSGTT6HS' => 0.95, //Netherlands
        //     'A1PA6795UKMFR9' => 0.95, //Germany
        //     'APJ6JRA9NG5V4' => 0.95, //Italy
        //     'A2NODRKZP88ZB9' => 9.50, //Sweden
        //     'AE08WJ6YKNBMC' => 15.00, //South Africa
        //     'A1C3SOZRARQ6R3' => 3.80, //Poland
        //     'ARBP9OOSHTCHU' => 15.70, //Egypt
        //     'A33AVAJ2PDY3EV' => 7.00, //Turkey
        //     'A17E79C6D8DWNP' => 3.75, //Soudi Arabia
        //     'A2VIGQ35RCS4UG' => 3.67, //UAE
        //     'A21TJRUUN4KGV' => 86.67, //India
        //     //Far East
        //     'A19VAU5U5O7RUS' => 1.34, //Singapore
        //     'A39IBJ37TRP1C6' => 1.57, //Australia
        //     'A1VC38T7YXB528' => 149.58, //Japan
        // ];

        $conversionRate = [
            //North America
            'A2EUQ1WTGCTBG2' => 0.70, // Canada
            'ATVPDKIKX0DER' => 1, //US
            'A1AM78C64UM0Y8' => 0.049, //Mexico
            'A2Q3Y263D00KWC' => 0.17, //Brazil
            //Europe
            'A28R8C7NBKEWEA' => 1.05, //Ireland
            'A1RKKUPIHCS9HS' => 1.05, //Spain
            'A1F83G8C2ARO7P' => 1.26, //UK
            'A13V1IB3VIYZZH' => 1.05, //France
            'AMEN7PMS3EDWL' => 1.05, //Belgium
            'A1805IZSGTT6HS' => 1.05, //Netherlands
            'A1PA6795UKMFR9' => 1.05, //Germany
            'APJ6JRA9NG5V4' => 1.05, //Italy
            'A2NODRKZP88ZB9' => 0.093, //Sweden
            'AE08WJ6YKNBMC' => 0.054, //South Africa
            'A1C3SOZRARQ6R3' => 0.25, //Poland
            'ARBP9OOSHTCHU' => 0.020, //Egypt
            'A33AVAJ2PDY3EV' => 0.027, //Turkey
            'A17E79C6D8DWNP' => 0.27, //Soudi Arabia
            'A2VIGQ35RCS4UG' => 0.27, //UAE
            'A21TJRUUN4KGV' => 0.012, //India
            //Far East
            'A19VAU5U5O7RUS' => 0.74, //Singapore
            'A39IBJ37TRP1C6' => 0.63, //Australia
            'A1VC38T7YXB528' => 0.0067, //Japan
        ];

        if(isset($conversionRate[$marketplace_id])) {
            return $amount*$conversionRate[$marketplace_id];
        }
        else {
            die('currency not found');
        }
    }

    public function prepareDataForDogra()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $mongo->initializeDb('db_home');

        $process_name = 'AbhinavData_23_June';

        $collection_name = $process_name.'-processed_users';

        $users = $this->fetchUserForProcessing($collection_name);
        
        $count = 0;
        while(!empty($users)) {
            $processedUserId = '';
            $insert_rows = [];
            foreach ($users as $key => $user) {
                echo ++$count.',';
                $userId = (string)$user['_id'];

                if(isset($user['shops']) && !empty($user['shops'])) {
                    $shopifyShop = [];
                    $amazonShopCount = 0;
                    $amazonSellerIds = [];
                    $shopStatus = 'install';
                    foreach ($user['shops'] as $key => $shop) {
                        if($shop['marketplace'] === 'shopify') {
                            $shopifyShop = $shop;
                            if(isset($shop['store_closed_at'])) {
                                $shopStatus = 'close';
                            }
                            elseif(isset($shop['apps'])) {
                                $currentShop = current($shop['apps']);
                                if($currentShop['app_status'] == 'uninstall') {
                                    $shopStatus = 'uninstall';
                                }
                            }
                        }
                        elseif($shop['marketplace'] === 'amazon') {
                            $amazonShopCount++;
                            $amazonSellerIds[] = $shop['warehouses'][0]['seller_id'];
                        }
                    }

                    if(!empty($shopifyShop) && $amazonShopCount>0) {
                        // print_r($shopifyShop);die;
                        $row_data = [
                            'user_id' => $userId,
                            'username' => $user['username'] ?? '',
                            'amazon_shop_count' => $amazonShopCount,
                            'amazon_seller_ids' => implode(',', $amazonSellerIds),
                            'plan_name' => $shopifyShop['plan_name'],
                            'plan_display_name' => $shopifyShop['plan_display_name'],
                            'name' => $shopifyShop['shop_owner'],
                            'store_name' => $shopifyShop['name'],
                            'email' => $shopifyShop['email'],
                            'country' => $shopifyShop['country'],
                            'phone' => $shopifyShop['phone'] ?? '',
                            'website' => $shopifyShop['domain'],
                            'install_date' => $user['created_at'],
                            'app_plan_name' => '',
                            'app_plan_type' => '',
                            'app_plan_amount' => 0,
                            'app_plan_discount_type' => '',
                            'app_plan_discount_value' => 0,
                            'GMV_before1Apr2025' => 0.00,
                            'order_count_before1Apr2025' => 0,
                            'GMV_after1Apr2025' => 0.00,
                            'order_count_after1Apr2025' => 0,
                            'shop_status' => $shopStatus
                        ];
                        $activePlan = $this->di->getObjectManager()->create('\App\Plan\Models\Plan')->getActivePlanForCurrentUser($userId);
                        if (!empty($activePlan)) {
                            $row_data['app_plan_type'] = $activePlan['plan_details']['billed_type'] ?? 'defauly_monthly';
                            $row_data['app_plan_name'] = $activePlan['plan_details']['title'] ?? 'regular';
                            $row_data['app_plan_amount'] = $activePlan['plan_details']['custom_price'] ?? 0;
                            
                            if(!empty($activePlan['plan_details']['discounts'])) {
                                $discount = current($activePlan['plan_details']['discounts']);

                                $row_data['app_plan_discount_type'] = $discount['type'] ?? '';
                                $row_data['app_plan_discount_value'] = $discount['value'] ?? 0;
                            }
                        }

                        // Before 1Apr25
                        $salesContainerCollection = $mongo->getCollectionForTable('sales_container');

                        $aggregation = [
                            [
                                '$match' => [
                                    'user_id' => $userId
                                ]
                            ], 
                            // [
                            //     '$match' => [
                            //         'order_date' => [
                            //             '$gte' => new \MongoDB\BSON\UTCDateTime(new DateTime('2024-06-01')),
                            //             '$lt' => new \MongoDB\BSON\UTCDateTime(new DateTime('2025-01-01'))
                            //         ]
                            //     ]
                            // ],
                            // [
                            //     '$addFields' => [
                            //         'year' => [
                            //             '$year' =>  '$order_date'
                            //         ]
                            //     ]
                            // ],
                            [
                                '$group' => [
                                    // '_id' => ['year' => '$year'],
                                    '_id' => [
                                        'user_id' => '$user_id',
                                        'marketplace_id' => '$marketplace_id'
                                    ],
                                    'orderCount' => ['$sum' => '$order_count'], 
                                    'orderAmount' => ['$sum' => '$order_amount'],
                                    'marketplace_id' => ['$first' => '$marketplace_id'],
                                    'marketplace_currency' => ['$first' => '$marketplace_currency']
                                ]
                            ]
                        ];

                        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                        $GMV_Data = $salesContainerCollection->aggregate($aggregation, $options)->toArray();
                        // if($userId=='6726600aa77bc7b5cd0ca574'){
                        //     var_dump($GMV_Data);die;
                        // }
                        if(!empty($GMV_Data))
                        {
                            // print_r($GMV_Data);die;
                            foreach ($GMV_Data as $GMV) {
                                $order_amount = ''.$GMV['orderAmount'];
                                $row_data['GMV_before1Apr2025'] += $this->convertCurrency($GMV['marketplace_id'], $order_amount);
                                $row_data['order_count_before1Apr2025'] += $GMV['orderCount'];
                            }
                        }

                        // 1Apr25 and after
                        $salesContainerCollection = $mongo->getCollectionForTable('sales_container_1Apr25');
                        $aggregation = [
                            [
                                '$match' => [
                                    'user_id' => $userId
                                ]
                            ],
                            [
                                '$group' => [
                                    '_id' => [
                                        'user_id' => '$user_id'
                                    ],
                                    'orderCount' => ['$sum' => '$order_count'], 
                                    'orderAmount' => ['$sum' => '$order_amount_usd'],
                                    'marketplace_id' => ['$first' => '$marketplace_id'],
                                    'marketplace_currency' => ['$first' => '$marketplace_currency']
                                ]
                            ]
                        ];
                        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                        $GMV_Data = $salesContainerCollection->aggregate($aggregation, $options)->toArray();
                        if(!empty($GMV_Data))
                        {
                            foreach ($GMV_Data as $GMV) {
                                $row_data['GMV_after1Apr2025'] += $GMV['orderAmount'];
                                $row_data['order_count_after1Apr2025'] += $GMV['orderCount'];
                            }
                        }

                        $row_data['GMV_Total'] = $row_data['GMV_before1Apr2025']+$row_data['GMV_after1Apr2025'];
                        $row_data['total_order_count'] = $row_data['order_count_before1Apr2025']+$row_data['order_count_after1Apr2025'];

                        $insert_rows[] = $row_data;
                    }
                }     

                $processedUserId = $userId;
            }

            $mongo->getCollectionForTable($collection_name)->updateOne(['_id' => 'last_processed_user'], ['$set' => ['value' => $userId, 'count' => $count]], ['upsert'=>true]);

            print_r($insert_rows);die;
            if(!empty($insert_rows)) {
                $collectionObj = $mongo->getCollectionForTable($process_name);
                $bulkInsertObj = $collectionObj->insertMany($insert_rows);
            }

            $users = [];
            $users = $this->fetchUserForProcessing($collection_name, $processedUserId);
        } 
        die('all users processed.'); 
    }

    public function debugFunc()
    {
//         $json = <<<JSONSTR
//         {"key":"price","type":"fullData","data":{"data":[{"currentValue":{"created_at":"2025-02-24T10:58:07+00:00","price":143,"source_updated_at":"2025-02-24T05:57:51-05:00","updated_at":"2025-02-24T10:58:07+0000","source_product_id":"49475364159785","container_id":"9537374159145","shop_id":"67865","user_id":"641adf51fde0c9a1e0054481","source_shop_id":"67865"},"beforeValue":{"created_at":"2025-02-24T10:48:15+00:00","price":123,"source_updated_at":"2025-02-24T05:47:27-05:00","updated_at":"2025-02-24T10:48:15+0000"},"operationType":"update","clusterTime":"2025-02-24T10:58:07.250Z"},{"currentValue":{"created_at":"2025-02-24T10:58:09+00:00","price":127,"source_updated_at":"2025-02-24T05:58:07-05:00","updated_at":"2025-02-24T10:58:09+0000","source_product_id":"44836298555689","container_id":"8222611505449","shop_id":"67865","user_id":"641adf51fde0c9a1e0054481","source_shop_id":"67865"},"beforeValue":{"created_at":"2025-02-24T10:25:19+00:00","price":110,"source_updated_at":"2025-02-24T05:25:17-05:00","updated_at":"2025-02-24T10:25:19+0000"},"operationType":"update","clusterTime":"2025-02-24T10:58:09.978Z"},{"currentValue":{"created_at":"2025-02-24T10:58:20+00:00","price":530,"source_updated_at":"2025-02-24T05:58:18-05:00","updated_at":"2025-02-24T10:58:20+0000","source_product_id":"44836299047209","container_id":"8222611669289","shop_id":"67865","user_id":"641adf51fde0c9a1e0054481","source_shop_id":"67865"},"beforeValue":{"created_at":"2025-02-24T10:50:02+00:00","price":654,"source_updated_at":"2025-02-24T05:49:35-05:00","updated_at":"2025-02-24T10:50:02+0000"},"operationType":"update","clusterTime":"2025-02-24T10:58:20.182Z"}],"key":"price","type":"fullData","user_id":"641adf51fde0c9a1e0054481"}}
// JSONSTR;
        // // print_r($json);die;
        // $data = json_decode($json, true);
        // // var_dump( json_last_error());
        // // print_r($data);die('yahan');
        // $user = null;
        // if (isset($data['data']['user_id'])) {
        //     $user = \App\Core\Models\User::findFirst([['_id' => $data['data']['user_id']]]);
        // }
        // if ($user) {
        //     $user->id = (string) $user->_id;
        //     $this->di->setUser($user);
        //     // $nextPrevStateHelper = $this->di->getObjectManager()->get("\App\Connector\Models\Mongostream\NextPrevStateHelper")->processNextPrevEvent($data);
        // }
        // else {
        //     die('invalid user');
        // }

        /*$json = <<<JSONSTR
        {"data":{"version":"0","id":"67af4aee-7d4c-bafa-61dc-9592d96d3b0c","detail-type":"shopifyWebhook","source":"aws.partner/shopify.com/5045527/Shopify-Amazon-App","account":"282133225308","time":"2025-05-07T10:55:55Z","region":"us-east-2","resources":[],"detail":{"payload":{"id":14014759,"name":"Dam Nail Polish","email":"angie@damnailpolish.com","domain":"www.damnailpolish.com","province":"Virginia","country":"US","address1":"5746 Union Mill Road","zip":"20124","city":"Clifton","source":null,"phone":"(571) 354-6245","latitude":38.8344303,"longitude":-77.413066,"primary_locale":"en","address2":"PMB #203","created_at":"2016-07-24T10:00:13-04:00","updated_at":"2025-05-07T06:55:53-04:00","country_code":"US","country_name":"United States","currency":"USD","customer_email":"cs@damnailpolish.com","timezone":"(GMT-05:00) America/New_York","iana_timezone":"America/New_York","shop_owner":"Angela Dam","money_format":"<span class=money> USD</span>","money_with_currency_format":"<span class=money> USD</span>","weight_unit":"oz","province_code":"VA","taxes_included":false,"auto_configure_tax_inclusivity":null,"tax_shipping":false,"county_taxes":true,"plan_display_name":"Grow","plan_name":"professional","has_discounts":true,"has_gift_cards":true,"myshopify_domain":"damnailpolish.myshopify.com","google_apps_domain":"damnailpolish.com","google_apps_login_enabled":true,"money_in_emails_format":"","money_with_currency_in_emails_format":" USD","eligible_for_payments":true,"requires_extra_payments_agreement":false,"password_enabled":false,"has_storefront":true,"finances":true,"primary_location_id":36588554,"checkout_api_supported":true,"multi_location_enabled":true,"setup_required":false,"pre_launch_enabled":false,"enabled_presentment_currencies":["AED","AFN","ALL","AMD","ANG","AUD","AWG","AZN","BAM","BBD","BDT","BGN","BIF","BND","BOB","BSD","BWP","BZD","CAD","CDF","CHF","CNY","CRC","CVE","CZK","DJF","DKK","DOP","DZD","EGP","ETB","EUR","FJD","FKP","GBP","GMD","GNF","GTQ","GYD","HKD","HNL","HUF","IDR","ILS","INR","ISK","JMD","JPY","KES","KGS","KHR","KMF","KRW","KYD","KZT","LAK","LBP","LKR","MAD","MDL","MKD","MMK","MNT","MOP","MUR","MVR","MWK","MYR","NGN","NIO","NPR","NZD","PEN","PGK","PHP","PKR","PLN","PYG","QAR","RON","RSD","RWF","SAR","SBD","SEK","SGD","SHP","SLL","STD","THB","TJS","TOP","TTD","TWD","TZS","UAH","UGX","USD","UYU","UZS","VND","VUV","WST","XAF","XCD","XOF","XPF","YER"],"marketing_sms_consent_enabled_at_checkout":true,"transactional_sms_disabled":false},"metadata":{"Content-Type":"application/json","X-Shopify-Topic":"shop/update","X-Shopify-Shop-Domain":"damnailpolish.myshopify.com","X-Shopify-Hmac-SHA256":"68bPaMWH9puLSsAfus+cl5nGwIdD7z4bNEBBZNJ4Z4s=","X-Shopify-Webhook-Id":"3a86d2b7-7b3c-4f8b-a127-ccbefdfc6768","X-Shopify-API-Version":"2024-07","X-Shopify-Event-Id":"0449fba4-4020-45d0-836e-ccbbb7024620","X-Shopify-Triggered-At":"2025-05-07T10:55:53.472869903Z"}}},"username" : "damnailpolish.myshopify.com","topic" : "shop/update","class_name": "","method": "moldWebhookData","app_code": "amazon_sales_channel","app_tag": "amazon_sales_channel","marketplace": "shopify","type":"full_class","action": "shop_update","queue_name":"amazonbyced_shop_update"}
JSONSTR;
        $messageArray = json_decode($json, true);
        // var_dump( json_last_error());
        // print_r($messageArray);die('yahan');
        $messageArray['data']['detail']['payload']['money_format'] = '<span class=money>${{amount}} USD</span>';
        $messageArray['data']['detail']['payload']['money_with_currency_format'] = '<span class=money>${{amount}} USD</span>';
        $messageArray['data']['detail']['payload']['money_in_emails_format'] = '${{amount}}';
        $messageArray['data']['detail']['payload']['money_with_currency_in_emails_format'] = '${{amount}} USD';
        $messageArray['class_name'] = 'App\\Shopifyhome\\Components\\WebhookHandler\\Hook';
        // print_r($messageArray);die('yahan2');*/

//         $json = <<<JSONSTR
//         {"appCode":"amazon","appTag":"amazon_sales_channel","type":"full_class","class_name":"","method":"receiveSqsMessage","queue_name":"staging_amazonbyced_handle_next_prev_state_price","user_id":"6726600aa77bc7b5cd0ca574","data":{"className":"","method":"init","key":"price","type":"fullData","data":{"data":[{"currentValue":{"variantMetafield.custom->az_Price.namespace":"custom1","variantMetafield.custom->az_Price.value":"223.0","variantMetafield.custom->az_lead_time.namespace":"custom1","variantMetafield.custom->az_lead_time.value":"10","source_product_id":"43148984483894","container_id":"7685313298486","shop_id":"580","user_id":"6726600aa77bc7b5cd0ca574","source_shop_id":"580"},"beforeValue":{"variantMetafield.custom->az_Price.namespace":"custom","variantMetafield.custom->az_Price.value":"222.0","variantMetafield.custom->az_lead_time.namespace":"custom","variantMetafield.custom->az_lead_time.value":"9"},"operationType":"update","clusterTime":"2025-05-27T16:06:25.465Z"}],"key":"price","type":"fullData","user_id":"6726600aa77bc7b5cd0ca574"}}}
// JSONSTR;
//         $messageArray = json_decode($json, true);
//         $messageArray['class_name'] = '\\App\\Connector\\Models\\Mongostream\\Core';
//         $messageArray['data']['className'] = '\\App\\Amazon\\Components\\ChangeStream\\Helper';
//         // print_r($messageArray);die('yahan2');


        $json = <<<JSONSTR
{"type":"full_class","appCode":"amazon_sales_channel","appTag":"amazon_sales_channel","class_name":"","method":"handleImport","queue_name":"amazonbyced_shopify_metafield_import","own_weight":100,"user_id":"641adf51fde0c9a1e0054481","data":{"operation":"import_products_tempdb","user_id":"641adf51fde0c9a1e0054481","remote_shop_id":"22225","app_code":"amazon_sales_channel","individual_weight":1,"feed_id":"683449bee768f296150f16c9","webhook_present":true,"temp_importing":false,"shop":{"id":{"$numberLong":"73460515113"},"name":"3_product12","email":"shtiwari@threecolts.com","domain":"3-product12.myshopify.com","province":"Uttar Pradesh","country":"IN","address1":"Kalyanpur","zip":"223492","city":"lucknow","source":null,"phone":"1234567889","latitude":null,"longitude":null,"primary_locale":"en","address2":"abc","created_at":"2023-03-22T06:48:13-04:00","updated_at":{"$date":"2025-05-22T12:24:12.473Z"},"country_code":"IN","country_name":"India","currency":"INR","customer_email":"shtiwari@threecolts.com","timezone":"(GMT-05:00) America/New_York","iana_timezone":"America/New_York","shop_owner":"Shubham Rai","money_format":"Rs. {{amount}}","money_with_currency_format":"Rs. {{amount}}","weight_unit":"kg","province_code":"UP","taxes_included":false,"auto_configure_tax_inclusivity":null,"tax_shipping":null,"county_taxes":true,"plan_display_name":"Developer Preview","plan_name":"partner_test","has_discounts":false,"has_gift_cards":false,"myshopify_domain":"3-product12.myshopify.com","google_apps_domain":null,"google_apps_login_enabled":null,"money_in_emails_format":"Rs. {{amount}}","money_with_currency_in_emails_format":"Rs. {{amount}}","eligible_for_payments":false,"requires_extra_payments_agreement":false,"password_enabled":true,"has_storefront":true,"finances":true,"primary_location_id":{"$numberLong":"80078733609"},"cookie_consent_level":"implicit","visitor_tracking_consent_preference":"allow_all","checkout_api_supported":true,"multi_location_enabled":true,"setup_required":false,"pre_launch_enabled":false,"enabled_presentment_currencies":["INR"],"username":"3-product12.myshopify.com","apps":[{"code":"amazon_sales_channel","app_status":"active","reinstalled":true,"webhooks":[{"code":"app_delete","dynamo_webhook_id":"1345788","marketplace_subscription_id":"1682263703849"},{"code":"fulfillments_create","dynamo_webhook_id":"1345794","marketplace_subscription_id":"1682264326441"},{"code":"fulfillments_update","dynamo_webhook_id":"1345795","marketplace_subscription_id":"1682264359209"},{"code":"inventory_levels_update","dynamo_webhook_id":"1345796","marketplace_subscription_id":"1682264391977"},{"code":"locations_create","dynamo_webhook_id":"1345797","marketplace_subscription_id":"1682264424745"},{"code":"locations_delete","dynamo_webhook_id":"1345800","marketplace_subscription_id":"1682264457513"},{"code":"locations_update","dynamo_webhook_id":"1345801","marketplace_subscription_id":"1682264490281"},{"code":"orders_create","dynamo_webhook_id":"1345802","marketplace_subscription_id":"1682264523049"},{"code":"product_listings_add","dynamo_webhook_id":"1345803","marketplace_subscription_id":"1682264555817"},{"code":"product_listings_remove","dynamo_webhook_id":"1345804","marketplace_subscription_id":"1682264588585"},{"code":"product_listings_update","dynamo_webhook_id":"1345805","marketplace_subscription_id":"1682264621353"},{"code":"refunds_create","dynamo_webhook_id":"1345806","marketplace_subscription_id":"1682264654121"},{"code":"shop_update","dynamo_webhook_id":"1345807","marketplace_subscription_id":"1682264686889"},{"code":"app_subscriptions_update","dynamo_webhook_id":"1345808","marketplace_subscription_id":"1682264719657"},{"code":"inventory_levels_connect","dynamo_webhook_id":"1345809","marketplace_subscription_id":"1682264752425"},{"code":"inventory_levels_disconnect","dynamo_webhook_id":"1345810","marketplace_subscription_id":"1682264785193"}]}],"remote_shop_id":"22225","marketplace":"shopify","last_login_at":"2025-05-05 12:41:03","warehouses":[{"id":"102021005609","name":"Location 2","address1":"Gomti Nagar Railway Station Vinay Khand 5 Gomti Nagar","address2":"","city":"Lucknow","zip":"226010","province":"Uttar Pradesh","country":"IN","phone":"","country_code":"IN","country_name":"India","province_code":"UP","legacy":false,"active":true,"admin_graphql_api_id":"gid://shopify/Location/102021005609","localized_country_name":"India","localized_province_name":"Uttar Pradesh","_id":"629178"},{"id":"102021038377","name":"Location 3","address1":"Kanpur Central Railway Station Mirpur","address2":"","city":"Kanpur","zip":"208001","province":"Uttar Pradesh","country":"IN","phone":"","country_code":"IN","country_name":"India","province_code":"UP","legacy":false,"active":true,"admin_graphql_api_id":"gid://shopify/Location/102021038377","localized_country_name":"India","localized_province_name":"Uttar Pradesh","_id":"629179"},{"id":"102021071145","name":"Location 4","address1":"Allah","address2":"","city":"Prayagraj","zip":"","province":"Uttar Pradesh","country":"IN","phone":"","country_code":"IN","country_name":"India","province_code":"UP","legacy":false,"active":true,"admin_graphql_api_id":"gid://shopify/Location/102021071145","localized_country_name":"India","localized_province_name":"Uttar Pradesh","_id":"629180"},{"id":"80078733609","name":"Store Location","address1":"Charbagh","address2":"","city":"lucknow","zip":"226020","province":"Uttar Pradesh","country":"IN","phone":"+9143423423343","country_code":"IN","country_name":"India","province_code":"UP","legacy":false,"active":true,"admin_graphql_api_id":"gid://shopify/Location/80078733609","localized_country_name":"India","localized_province_name":"Uttar Pradesh","_id":"629181"},{"id":"104595095849","name":"Update warehouse 1","address1":"Allahabad Airport Bamrauli Airport Area Bamrauli","address2":"","city":"Prayagraj","zip":"211012","province":"Uttar Pradesh","country":"IN","phone":"","country_code":"IN","country_name":"India","province_code":"UP","legacy":false,"active":true,"admin_graphql_api_id":"gid://shopify/Location/104595095849","localized_country_name":"India","localized_province_name":"Uttar Pradesh","_id":"629182"}],"_id":"67865","shop_status":"active","targets":[{"shop_id":"73342","code":"amazon","app_code":"amazon"},{"shop_id":"260177","code":"amazon","app_code":"amazon"}],"transactional_sms_disabled":true,"marketing_sms_consent_enabled_at_checkout":false},"isImported":false,"process_code":"metafield","extra_data":{"filterType":"metafieldsync","metafield_status":true},"id":"gid://shopify/BulkOperation/5968709812520","file_path":"/var/www/html/home/var/file/641adf51fde0c9a1e0054481/metafields-import-test.jsonl"},"run_after":0,"handle_added":1,"app_code":{"shopify":"default"}}
JSONSTR;
        $messageArray = json_decode($json, true);
        $messageArray['class_name'] = '\\App\\Connector\\Components\\Route\\ProductRequestcontrol';
        // print_r($messageArray);die('yahan par aa');


//         $json = <<<JSONSTR
//         {"appCode":"amazon_sales_channel","appTag":"amazon_sales_channel","type":"full_class","class_name":"","method":"receiveSqsMessage","queue_name":"amazonbyced_handle_next_prev_state_price","user_id":"641adf51fde0c9a1e0054481","data":{"className":"","method":"init","key":"price","type":"fullData","data":{"data":[{"currentValue":{"variantMetafield.custom->az_Price.namespace":"custom","variantMetafield.custom->az_Price.value":"76.0","variantMetafield.custom->az_lead_time.namespace":"custom","variantMetafield.custom->az_lead_time.value":"9","source_product_id":"44836301242665","container_id":"8222613340457","shop_id":"67865","user_id":"6726600aa77bc7b5cd0ca574","source_shop_id":"67865"},"beforeValue":{"variantMetafield.custom->az_Price.namespace":"custom","variantMetafield.custom->az_Price.value":"75.0","variantMetafield.custom->az_lead_time.namespace":"custom","variantMetafield.custom->az_lead_time.value":"9"},"operationType":"update","clusterTime":"2025-05-27T16:06:25.465Z"}],"key":"price","type":"fullData","user_id":"641adf51fde0c9a1e0054481"}}}
// JSONSTR;
//         $messageArray = json_decode($json, true);
//         $messageArray['class_name'] = '\\App\\Connector\\Models\\Mongostream\\Core';
//         $messageArray['data']['className'] = '\\App\\Amazon\\Components\\ChangeStream\\Helper';
//         // print_r($messageArray);die('yahan2');

        if (isset($messageArray['appCode'])) {
            $this->di->getAppCode()->set($messageArray['appCode']);
        } elseif (isset($messageArray['data']['appCode'])) {
            $this->di->getAppCode()->set($messageArray['data']['appCode']);
        }

        if (isset($messageArray['appTag'])) {
            $this->di->getAppCode()->setAppTag($messageArray['appTag']);
        } elseif (isset($messageArray['data']['appTag'])) {
            $this->di->getAppCode()->setAppTag($messageArray['data']['appTag']);
        }
        
        if(isset($messageArray['username'])) {
            $user = \App\Core\Models\User::findFirst([['username' => (string) $messageArray['username']]]);
        } elseif(isset($messageArray['user_id'])) {
            $user = \App\Core\Models\User::findFirst([['user_id' => (string) $messageArray['user_id']]]);
        }

        if ($user) {
            $user->id = (string) $user->_id;
            $this->di->setUser($user);

            if ($this->di->getConfig()->has('plan_di')) {
                $planDi = $this->di->getConfig()->get('plan_di')->toArray();
                if (!empty($planDi) && isset($planDi['enabled'], $planDi['class'], $planDi['method']) && $planDi['enabled'] && !empty($planDi['class']) && !empty($planDi['method'])) {
                    if (class_exists($planDi['class'])) {
                        $planObj = $this->di->getObjectManager()->create($planDi['class']);
                        if (method_exists($planObj, $planDi['method'])) {
                            $method = $planDi['method'];
                            $this->di->setPlan($planObj->$method($user->id));
                        }
                    }
                }
            }

            $decodedToken = [
                'role' => 'admin',
                'user_id' => $messageArray['user_id'] ?? null,
                'username' => $messageArray['username'] ?? null,
            ];

            $this->di->getRegistry()->setDecodedToken($decodedToken);

            if ($messageArray['type'] == 'full_class') {
                if (isset($messageArray['class_name'])) {
                    $obj = $this->di->getObjectManager()->get($messageArray['class_name']);
                    $method = $messageArray['method'];
                    
                    $response = $obj->$method($messageArray);
                    var_dump($response);

                    die('processing response');
                } else {
                    die('invalid class_name');
                }
            }
            else {
                die('invalid type');
            }
        }
        else {
            die('invalid user');
        }
    }

    public function clientTemplateMigrationWork()
    {
        $matched = [];
        $not_matched = [];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $mongo->initializeDb('db_home');

        $profileCollection = $mongo->getCollectionForTable('profile');
        $productCollection = $mongo->getCollectionForTable('product_container');

        // profile
        // {user_id:"67af139393454e1a3c0c64e2",type:"profile",filter_type:"manual"}
        // project 
        // manual_product_ids
        $profiles = $profileCollection->find(['user_id'=>'67af139393454e1a3c0c64e2','type'=>'profile','filter_type'=>'manual'], ['typeMap' => ['root' => 'array', 'document' => 'array'], 'projection' => ['manual_product_ids' => 1]])->toArray();

        foreach ($profiles as $profile)
        {
            if (isset($profile['manual_product_ids']) && count($profile['manual_product_ids'])>0)
            {
                $matched_ids = [];
                foreach ($profile['manual_product_ids'] as $key => $container_id) 
                {
                    // var_dump(json_encode(['user_id'=>'65967866c95d4b69a7062764','container_id'=>$container_id,'visibility'=>'Catalog and Search']));die;
                    //find container_id in old store
                    //{user_id:"65967866c95d4b69a7062764",container_id:"9362597511464",visibility:"Catalog and Search"}
                    // project
                    // title
                    $oldProduct = $productCollection->findOne(['user_id'=>'65967866c95d4b69a7062764','container_id'=>$container_id,'visibility'=>'Catalog and Search'], ['typeMap' => ['root' => 'array', 'document' => 'array'], 'projection' => ['title' => 1]]);
                    if(!empty($oldProduct))
                    {
                        //find container_id in new store by seaching via old product title
                        
                        //{user_id:"67af139393454e1a3c0c64e2",title:"Lässiges Baumwollhemd mit Karomuster",visibility:"Catalog and Search"}
                        //project 
                        // container_id
                        $newProduct = $productCollection->findOne(['user_id'=>'67af139393454e1a3c0c64e2','title'=>$oldProduct['title'],'visibility'=>'Catalog and Search'], ['typeMap' => ['root' => 'array', 'document' => 'array'], 'projection' => ['container_id' => 1]]);
                        if(!empty($newProduct))
                        {
                            $matched[] = [
                                'old_con_id' => $container_id,
                                'old_title' =>  $oldProduct['title'],
                                'profile' => $profile['_id']->__toString(),
                                'new_con_id' => $newProduct['container_id']
                            ];
                            $matched_ids[] = $newProduct['container_id'];
                            // var_dump($newProduct['container_id']);die('success');
                        }
                        else {
                            // var_dump(json_encode(['user_id'=>'67af139393454e1a3c0c64e2','title'=>$oldProduct['title'],'visibility'=>'Catalog and Search']));
                            // die('product not found in new store');
                            $not_matched[] = [
                                'old_con_id' => $container_id,
                                'old_title' =>  $oldProduct['title'],
                                'profile' => $profile['_id']->__toString()
                            ];
                        }
                    }
                    else {
                        // var_dump(json_encode(['user_id'=>'65967866c95d4b69a7062764','container_id'=>$container_id,'visibility'=>'Catalog and Search']));
                        // die('product not found in old store');
                        $not_matched[] = [
                            'old_con_id' => $container_id,
                            'profile' => $profile['_id']->__toString()
                        ];
                    }
                }
                if(!empty($matched_ids)) {
                    $manual_product_ids = array_values(array_unique(array_merge($profile['manual_product_ids'], $matched_ids)));
                    // print_r($manual_product_ids);
                    // print_r($matched_ids);
                    // print_r($profileCollection->findOne(['_id'=>$profile['_id']], ['typeMap' => ['root' => 'array', 'document' => 'array'], 'projection' => ['manual_product_ids' => 1]]));
                    // die('success');
                    $profileCollection->updateOne(['_id'=>$profile['_id']], ['$set'=>['manual_product_ids' => $manual_product_ids]]);
                    // print_r($profile['_id']);die;

                }
            }
        }
        // $this->di->getLog()->logContent(json_encode($matched), 'info', 'hima-matched.log');
        // $this->di->getLog()->logContent(json_encode($not_matched), 'info', 'hima-not_matched.log');
    }

    public function updateInvalidTemplates()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $mongo->initializeDb('db_home');

        $profileCollection = $mongo->getCollectionForTable('profile');
        $userCollection = $mongo->getCollectionForTable('user_details');

        // profile
        // {type:"attribute","data.required_attribute":{$size:0},"data.optional_attribute":{$size:0},"data.jsonFeed":{$exists:false}}
        // project 
        // data, profile_id
        $profiles = $profileCollection->find(['type'=>'attribute','data.required_attribute'=>['$size'=>0],'data.optional_attribute'=>['$size'=>0],'data.jsonFeed'=>['$exists'=>false]], ['typeMap' => ['root' => 'array', 'document' => 'array'], 'projection' => ['data' => 1, 'profile_id' => 1, 'user_id' => 1]])->toArray();

        $invalid_users = $invalid_templates = [];
        foreach ($profiles as $profile)
        {
            if (isset($profile['profile_id'],$profile['user_id']))
            {
                $user = $userCollection->findOne(['user_id'=>$profile['user_id']], ['typeMap' => ['root' => 'array', 'document' => 'array'], 'projection' => ['user_id' => 1]]);
                if(!empty($user)) {
                    //find profile with given id
                    //{_id:ObjectId('636e3a2fbfb507f411037d8c')}
                    // unset
                    // category_id, targets.0.category_id
                    // $profileCollection->updateOne(['_id'=>$profile['profile_id']], ['$unset' => ['category_id'=>1,'targets.0.category_id'=>1]]);
                }
                else {
                    // if(!in_array($profile['user_id'],$invalid_users)){
                        $invalid_users[] = $profile['user_id'];
                    // }
                }
            }
            else {
                $invalid_templates[] = $profile;
            }
        }
        print_r($invalid_users);
        print_r($invalid_templates);
        die('end');
    }

    /**
     * Make Unlinked Listings InActive for client HorseLoverZ (ec7d29-32.myshopify.com)
     *
     */
    protected function horseLoverz()
    {
        /*
        1. Uplaod csv of amazon_listings collection, may use following query. {user_id:"68058af274e396dc230f22d3",shop_id:"257261",status:{$ne:"Deleted"},"fulfillment-channel":"DEFAULT",matched:null}
        OR
        {user_id:"68058af274e396dc230f22d3",shop_id:"257261",status:{$ne:"Deleted"},"fulfillment-channel":"DEFAULT",matched:null}
        {status:"Active"}
        2. Enter the file path in $input_file_path
        3. Generate new Amazon Token and paste it in getListingsFromAmazon() and patchListingsOnAmazon() function
        */ 

        // $input_file_path = BP . DS . 'horseLoverz.csv';
        $input_file_path = BP . DS . 'horseLoverz-active-8-may.csv';
        // /var/www/html/home/horseLoverz.csv

        if(!file_exists($input_file_path)) {
            die('file not found');
        }

        $fp = fopen($input_file_path, 'r');

        $SELLER_SKU_KEY = $STATUS_KEY = $FULFILLMENT_CHANNEL_KEY = false;

        $counter = 0;
        while ( !feof($fp) )
        {
            $line = fgets($fp, 2048);

            $data = str_getcsv($line);
            // print_r($data);die;
            if(!$SELLER_SKU_KEY) {
                foreach ($data as $key => $value) {
                    if($value === 'seller-sku') {
                        $SELLER_SKU_KEY = $key;
                        // break;
                    }

                    if($value === 'status') {
                        $STATUS_KEY = $key;
                    }

                    if($value === 'fulfillment-channel') {
                        $FULFILLMENT_CHANNEL_KEY = $key;
                    }
                }
                continue;
            }

            $counter++;
            
            if(!$SELLER_SKU_KEY || !$STATUS_KEY || !$FULFILLMENT_CHANNEL_KEY) {
                die('invalid csv');
            }

            // if($counter <= 505) {
            // if($counter <= 1030) {
            //     continue;
            // }

            if($data[$STATUS_KEY] == 'Active' && $data[$FULFILLMENT_CHANNEL_KEY] == 'DEFAULT') {
                $resp = $this->getListingsFromAmazon($data[$SELLER_SKU_KEY]);
                if($resp['status'] === true) {
                    echo "{$counter}) {$data[$SELLER_SKU_KEY]}: Get Listings Success";

                    $lead_time = false;
                    if(isset($resp['data']['attributes']['fulfillment_availability'][0]['lead_time_to_ship_max_days'])) {
                        $lead_time = $resp['data']['attributes']['fulfillment_availability'][0]['lead_time_to_ship_max_days'];
                    }
                    
                    $patchRes = $this->patchListingsOnAmazon($data[$SELLER_SKU_KEY], $lead_time);
                    if($patchRes['status'] === true) {
                        if($patchRes['data']['status'] == 'ACCEPTED') {
                            echo " | Patch Success.".PHP_EOL;
                        }
                        else {
                            echo " | Patch Error Can be Handled.".PHP_EOL;
                        }
                    }
                    else {
                        echo " | Patch Error.".PHP_EOL;
                    }
                }
                else {
                    echo "{$counter}) {$data[$SELLER_SKU_KEY]} Get Listings Failed".PHP_EOL;
                }
            }
            
            // sleep(.5); // In order to delay program execution for a fraction of a second, use usleep() as the sleep() function expects an int. For example, sleep(0.25) will pause program execution for 0 seconds. 

            usleep(500000); // sleep for .5 sec
        }

        fclose($fp);
    }

    public function getListingsFromAmazon($seller_sku,$log_flag=true)
    {
        // return ['status'=>true, 'data'=>[]];
        $curl = curl_init();

        curl_setopt_array($curl, array(
          CURLOPT_URL => 'https://sellingpartnerapi-na.amazon.com/listings/2021-08-01/items/A1RT26U55DQQ8J/'.$seller_sku.'?marketplaceIds=ATVPDKIKX0DER&includedData=summaries%2Cattributes%2Cissues%2Coffers%2CfulfillmentAvailability%2Cprocurement%2Crelationships%2CproductTypes',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'GET',
          CURLOPT_HTTPHEADER => array(
            'x-amz-access-token: Atza|IwEBIGKizrmjaPioUU7RqhS1gWzOflN0g8l79NNZDsdA1NcePfiRSpJ8hn7alksS3njOBvJJVZGmO-PGqpqcwxTnopo41AEVl2l5YyVEOVuZ063kfOnfmmb4rC4tdk0Xe554fcyn9PQWJH2sB7yfBA-8FZh-OjDif-aP6d0wE08N4ZjEqHZW4oVxAwr_vpgbuxH9FBrUVbPhcciCe3g3aW0EDjbuSkOeDcA2ZO1QmDfXsRhk76dMz4dP354zxXqAWmhyZVqm9AmXWv8dw-KY4er-zSakmyCHSc1QaGO-QSXGlhro7G470furnDd24XITKZGJhQXuCyPFrIxXxgQ0SMrG3VvP'
          ),
        ));

        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if($httpcode === 200){
            if($log_flag) {
                $this->di->getLog()->logContent('http_code: '.$httpcode.PHP_EOL.'body: '.$response, 'info', "horseLoverz/get/success/{$seller_sku}.log");
            }
            return ['status'=>true, 'data'=>json_decode($response,true)];
        }
        else {
            if($log_flag) {
                $this->di->getLog()->logContent('http_code: '.$httpcode.PHP_EOL.'body: '.print_r($response,true), 'info', "horseLoverz/get/failure/{$seller_sku}.log");
            }
            return ['status'=>false, 'error'=>$response, 'code'=>$httpcode];
        }
    }

    public function patchListingsOnAmazon($seller_sku, $lead_time)
    {
        $post = '{
          "productType":"PRODUCT",
          "patches":[
            {
              "op":"replace",
              "path":"/attributes/fulfillment_availability",
              "value":[
                {
                    "fulfillment_channel_code": "DEFAULT",
                    "quantity":0';
        if($lead_time!==false){
            $post .= ',
            "lead_time_to_ship_max_days": '.$lead_time;
        }
        $post .= '
                }
              ]
            }
          ]
        }';
        
        $curl = curl_init();

        curl_setopt_array($curl, array(
          CURLOPT_URL => 'https://sellingpartnerapi-na.amazon.com/listings/2021-08-01/items/A1RT26U55DQQ8J/'.$seller_sku.'?marketplaceIds=ATVPDKIKX0DER&includedData=issues',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'PATCH',
          CURLOPT_POSTFIELDS => $post,
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'x-amz-access-token: Atza|IwEBIGKizrmjaPioUU7RqhS1gWzOflN0g8l79NNZDsdA1NcePfiRSpJ8hn7alksS3njOBvJJVZGmO-PGqpqcwxTnopo41AEVl2l5YyVEOVuZ063kfOnfmmb4rC4tdk0Xe554fcyn9PQWJH2sB7yfBA-8FZh-OjDif-aP6d0wE08N4ZjEqHZW4oVxAwr_vpgbuxH9FBrUVbPhcciCe3g3aW0EDjbuSkOeDcA2ZO1QmDfXsRhk76dMz4dP354zxXqAWmhyZVqm9AmXWv8dw-KY4er-zSakmyCHSc1QaGO-QSXGlhro7G470furnDd24XITKZGJhQXuCyPFrIxXxgQ0SMrG3VvP',
            'Accept: application/json'
          ),
        ));

        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if($httpcode === 200){
            $this->di->getLog()->logContent('http_code: '.$httpcode.PHP_EOL.'body: '.$response, 'info', "horseLoverz/patch/success/{$seller_sku}.log");
            return ['status'=>true, 'data'=>json_decode($response,true)];
        }
        else {
            $this->di->getLog()->logContent('http_code: '.$httpcode.PHP_EOL.'body: '.print_r($response,true), 'info', "horseLoverz/patch/failure/{$seller_sku}.log");
            return ['status'=>false, 'error'=>$response, 'code'=>$httpcode];
        }
    }

    protected function horseLoverzGetInventory()
    {
        $input_file_path = BP . DS . 'horseLoverz-active-8-may.csv';

        if(!file_exists($input_file_path)) {
            die('file not found');
        }
        
        $fp = fopen($input_file_path, 'r');

        $SELLER_SKU_KEY = $STATUS_KEY = $FULFILLMENT_CHANNEL_KEY = false;

        $counter = 0;
        while ( !feof($fp) )
        {
            $line = fgets($fp, 2048);

            $data = str_getcsv($line);
            // print_r($data);die;
            if(!$SELLER_SKU_KEY) {
                foreach ($data as $key => $value) {
                    if($value === 'seller-sku') {
                        $SELLER_SKU_KEY = $key;
                        // break;
                    }

                    if($value === 'status') {
                        $STATUS_KEY = $key;
                    }

                    if($value === 'fulfillment-channel') {
                        $FULFILLMENT_CHANNEL_KEY = $key;
                    }
                }
                continue;
            }

            $counter++;
            
            if(!$SELLER_SKU_KEY || !$STATUS_KEY || !$FULFILLMENT_CHANNEL_KEY) {
                die('invalid csv');
            }

            // if($counter <= 505) {
            // if($counter <= 1030) {
            //     continue;
            // }

            if($data[$STATUS_KEY] == 'Active' && $data[$FULFILLMENT_CHANNEL_KEY] == 'DEFAULT') {
                $resp = $this->getListingsFromAmazon($data[$SELLER_SKU_KEY]);
                if($resp['status'] === true) {
                    $out_str = "{$counter}) {$data[$SELLER_SKU_KEY]}: quantity: ";

                    if(isset($resp['data']['attributes']['fulfillment_availability'][0]['quantity'])) {
                        $out_str .= json_encode($resp['data']['attributes']['fulfillment_availability'][0]);
                        $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>{$out_str}</>");

                        if($resp['data']['attributes']['fulfillment_availability'][0]['quantity'] > 0) {
                            $lead_time = false;
                            if(isset($resp['data']['attributes']['fulfillment_availability'][0]['lead_time_to_ship_max_days'])) {
                                $lead_time = $resp['data']['attributes']['fulfillment_availability'][0]['lead_time_to_ship_max_days'];
                            }
                            
                            $patchRes = $this->patchListingsOnAmazon($data[$SELLER_SKU_KEY], $lead_time);
                            if($patchRes['status'] === true) {
                                if($patchRes['data']['status'] == 'ACCEPTED') {
                                    $this->output->writeln("<options=bold;fg=green> Patch Success </>");
                                }
                                else {
                                    $this->output->writeln("<options=bold;fg=red> Patch Error Can be Handled </>");
                                }
                            }
                            else {
                                $this->output->writeln("<options=bold;fg=red> Patch Error </>");
                            }
                        }
                    }
                    else {
                        $out_str .= 'Not-set';
                        $this->output->writeln("<options=bold;bg=green> ➤ </><options=bold;fg=green>{$out_str}</>");
                    }
                    
                }
                else {
                    $this->output->writeln("<options=bold;bg=red> ➤ </><options=bold;fg=red>{$counter}) {$data[$SELLER_SKU_KEY]}: Get Listings Failed</>");
                }
            }
            
            usleep(300000); // sleep for .3 sec
        }

        fclose($fp);
    }
}