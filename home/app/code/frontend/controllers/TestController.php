<?php
namespace App\Frontend\Controllers;
use App\Core\Controllers\BaseController;
use App\Frontend\Components\MigrationHelper;
use App\Frontend\Components\Helper_Test;
use DateTime;
use App\Shopify\Models\Shop\Details;
use MongoDB\BSON\UTCDateTime;

class TestController extends BaseController
{

//        $token = \App\Shopify\Models\Shop\Details::findFirst(["user_id = 16 AND shop_url = qwedsazxcfr.myshopify.com", 'column' => 'token'])->toArray();
//        $shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
//            ['type' => 'parameter', 'value' => 'a1b1c1d1f1.myshopify.com'],
//            ['type' => 'parameter', 'value' => 'fc5f648397bd41707d23b0ef1c8a7d19'],
//            ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->auth_key],
//            ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->secret_key]
//        ]);
//        $products = $shopifyClient->call('GET', '/admin/products.json', ['limit' => '1']);
//        if (isset($products['errors'])) {
//            print_r($products['errors']);
//            die('Error Occur');
//        }
//        print_r($products);
//        die();
//        $rawBody = [];
//        $rawBody = $this->di->getRequest()->get();
//        $userID = $this->di->getUser()->id;
//        // print_r($rawBody);die();
        //$response = $this->di->getObjectManager()->get('\App\Frontend\Components\AdminHelper')->getConfirmationUrlForOne(200, $userID);
    public function loginAction()
    {
        // var_dump(debug_print_backtrace());die;
        // $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        // $this->di->getLog()->logContent('marketplaceSaveAndUpdate' . json_encode($trace), 'info', 'Came_to_save.log');
        $data = $this->request->getJsonRawBody(true);
        $response =  $this->di->getObjectManager()->get('App\Connector\Models\Product\Marketplace')
            ->marketplaceSaveAndUpdate($data['data']);
        die;

        $dbData = [
            'source_product_id' => '1234567890',
            'source_variant_id' => '0987654321',
            'title' => 'Test Product',
            'description' => 'This is a test product description.',
            'price' => 19.99,
        ];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $batch_offer_listing_col = $mongo->getCollectionForTable('batch_offer_listing');
        // $batch_offer_listing_col->insertOne($dbData);
        $batch_offer_listing_col->updateOne(
            ['source_product_id' => $dbData['source_product_id']],
            [
                '$set' => $dbData, 
                '$setOnInsert' => ['created_at' => date('c'), 'inc' => 0 ],
                '$inc' => ['inc' => 1]
            ],
            ['upsert' => true]
        );
        return $this->prepareResponse($return);
    }

    public function shopifyMetricsAction()
    {
        $data = [];
        $data = $this->request->getJsonRawBody(true);
        // --------------------
        // Step 1: Extract shop from URL
        // --------------------
        $parsedUrl = parse_url($data['pageUrl']);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        $shop = $queryParams['shop'] ?? null;

        // --------------------
        // Step 2: Extract path from URL
        // --------------------
        $path = $parsedUrl['path'] ?? null;

        // --------------------
        // Step 3: Filter metrics (keep only FID, LCP, FCP if present)
        // --------------------
        $metricsUpdated = [];
        foreach ($data['metrics'] as $metric) {
            if (isset($metric['name'])) {
                $metricsUpdated[$metric['name']] = $metric['value'];
            }
        }
        // --------------------
        // Step 4: Add created_at and iso_date
        // --------------------
        $createdAt = gmdate("c");
        $isoDate = new UTCDateTime((int)(microtime(true) * 1000));

        $rawBody = [
            'shop' => $shop,
            'app_id' => $data['appId'] ?? null,
            'userId' => $data['userId'] ?? null,
            'shopId' => $data['shopId'] ?? null,
            'path' => $path,
            'metrics' => $metricsUpdated,
            'appLoadId' => $data['appLoadId'] ?? null,
            'created_at' => $createdAt,
            'iso_date' => $isoDate
        ];


        
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $shopify_metrics = $mongo->getCollectionForTable('shopify_metrics');

        $shopify_metrics->insertOne($rawBody);
        return $this->prepareResponse($response);
    }

    public function registerAction(){
       /* $test = $this->di->getObjectManager()->get('\App\Frontend\Models\Ptest');
        $res= $test->insert_data($this->request->get());*/
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = $this->request->getJsonRawBody(true);
        // if (str_contains((string) $contentType, 'application/json')) {
        //     $rawBody = $this->request->getJsonRawBody(true);
        // } else {
        //     $rawBody = $this->request->get();
        // }

        if ( isset($rawBody['appId'])) {
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $productCollection = $mongo->getCollectionForTable('shopify_metrics');
            $rawBody['extracted'] = $this->extractShopAndPath($rawBody['pageUrl'] ?? null);
            $rawBody['shop'] = $rawBody['extracted']['shopUrl'] ?? null;
            $rawBody['path'] = $rawBody['extracted']['path'] ?? null;
            unset($rawBody['extracted']);
            $output = [];
            foreach ($rawBody['metrics'] as $metric) {
                $output[$metric['name']] = $metric['value'];
            }
            $rawBody['metrics_updated'] = $output;
            $rawBody['created_at'] = date(DateTime::ATOM);
            $rawBody['iso_date'] = new \MongoDB\BSON\UTCDateTime();
            $productCollection->insertOne($rawBody);
            $this->di->getLog()->logContent(' successfully : ' . json_encode( $rawBody),'info', 'shopifymetrix.log');
        } else {
            $this->di->getLog()->logContent(' failed to get data : ' . json_encode( $rawBody),'info', 'shopifymetrix.log');
        }

       

	    return $this->prepareResponse(['success' => true, 'code' => 'Code Is Running', 'message' => 'Code Is Sunning :P']);
    }

    private function extractShopAndPath($inputUrl = null) {
        if ($inputUrl === null && isset($_SERVER['REQUEST_URI']) && isset($_SERVER['HTTP_HOST'])) {
            // Construct full URL from current request
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $inputUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }

        $parsedUrl = parse_url($inputUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);

        return [
            'shopUrl' => $queryParams['shop'] ?? null,
            'path'    => $parsedUrl['path'] ?? null
        ];
    }

    public function getTestAction()
    {
        # code...
        $rawBody = [];
        $rawBody = $this->di->getRequest()->get();
        // print_r($_SERVER['REMOTE_ADDR']);die();
        // print_r($rawBody);die();
        if ( $rawBody['user'] ) {
            $response = $this->di->getObjectManager()->get(Helper_Test::class)->TestRunGet($rawBody['user']);
        } else {
            return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);
        }

        // die('here');
        // $response = ['success' => true, 'code' => 'Code Is Running', 'message' => 'Code Is Sunning :P'];
        return $this->prepareResponse($response);

    }

    public function pradCreateAction(): void
    {
        $getcount = $this->di->getObjectManager()->get('App\Shopify\Components\Helper')->initiatebulkUpdatedata(['unique_feild'=>'Variant SKU']);


         $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource('import_csv_557');

        $collection = $mongo->getCollection();
        $mongo2 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo2->setSource('product_container_557');

        $collection2 = $mongo2->getCollection();
        $total_elements = $collection->count();
        $time_stamp = time();
        $time_stamp_variant = time()+1;
        for($i=0;$i<$total_elements;$i++){
            $data=$collection->find([],['limit'=>1,'skip'=>$i,"typeMap" => ['root' => 'array', 'document' => 'array']]);
            $element_data=($data->toArray())[0];
//            print_r($collection2->findOne(['details.handle' => $element_data['handle']],["typeMap" => ['root' => 'array', 'document' => 'array']]));

            // $collection2->findOneAndUpdate([
            //     'details.handle' => $element_data['handle']
            // ], [
            //     '$set' => [
            //         'details.short_description' => $element_data['description'] ,
            //         'details.long_description' => $element_data['description']
            //     ]
            // ]);
            $details=[[
                'details'=>[
                    'source_product_id' => (string)($time_stamp--),
                    'title'=>$element_data['title'],
                    'short_description' => $element_data['description'],
                    'long_description' => $element_data['description'],
                    'type'=>'simple',
                    'vendor'=>$element_data['vendor'],
                    'handle' => $element_data['handle'],
                    'product_type' => $element_data['category'],
                    'image_main' => $element_data['image'],
                ],
                'variants'=>[
                    [
                        'source_variant_id'=>$time_stamp_variant++,
                        'price'=>$element_data['price'],
                        'sku'=>$element_data['sku'],
                        'quantity'=>$element_data['quantity'],
                        'color'=>$element_data['Option1Value'],
                        'size'=>$element_data['Option2Value'],
                        'barcode'=>$element_data['barcode'],
                        'category'=>$element_data['category'],
                        'materials'=>$element_data['materials'],
                        'pattern'=>$element_data['pattern'],
                        'variant_images'=>[$element_data['image']]
                    ]
                ],
                'variant_attributes'=>['Color','Size'],
                'created_at' => date(DateTime::ATOM),
                'updated_at' => date(DateTime::ATOM),
                'source_marketplace' =>'shopify'
            ]];
            $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer')->createProductsAndAttributes($details,'shopify',"733");
            $details=[];
        }

//        $collection2->insertMany($details);
        die('test');


//        $rawBody=[];
//      $rawBody=$this->di->getRequest()->get();
//      if($rawBody['username']!='' && $rawBody['password']!='')
//      {
//
//        $response=$this->di->getObjectManager()->get('\App\Frontend\Components\Helper_DbConn')->Createuser($rawBody['username'],$rawBody['password']);
//         return $response;
//      }
//      else
//      {
//        return $this->prepareResponse(['success' => 'false', 'code' => 'Parameters Missing', 'message' => 'Parameters Missing']);
//      }

    }

    public function pradEditAction(): void
    {

        $response=$this->di->getObjectManager()->get('\App\Shopify\Components\EngineHelper')->getShopDetails(555,'bladevip.myshopify.com');
        print_r($response);
        die('test');

//        $rawBody=[];
//      $rawBody=$this->di->getRequest()->get();
//      if($rawBody['id']!='' && $rawBody['username']!='' && $rawBody['password']!='')
//      {
//        $response=$this->di->getObjectManager()->get('\App\Frontend\Components\Helper_DbConn')->Edituser($rawBody['id'],$rawBody['username'],$rawBody['password']);
//        return $this->prepareResponse($response);
//      }
//      else
//      {
//        return $this->prepareResponse(['success' => 'false', 'code' => 'Parameters Missing', 'message' => 'Parameters Missing']);
//      }
    }

    public function pradDeleteAction(): void
    {
        $files = glob(BP . DS . 'var' . DS . 'testcommand' .DS. 'text_*'); // get all file names
        foreach ($files as $file) {// iterate files
            print_r($files);
            if (is_file($file)) {
                unlink($file); // delete file
            }
        }

    }

    public function uninstallUserAction() {
        $helperAmazon = $this->di->getObjectManager()->get('\App\Amazonimporter\Components\Helper');
        $uninstallUserIds = [49,54,56,57,59,60,61,63,73,87,114,135,157,174,178,179,186,196,209,210,215,240,280,331,341,360,367,375,
            388,397,401,421,428,434,444,451,488,499,526,534,566,570,581,568,585,597,624,631,633,634,635,636,637,656,
            657,688,673,677,680,719,721,750,751,757,761,772,775,802,804,850,862,880,887,895,913,917,976,1052,1074,
            1093,1140,1184,1194,1198,1222,1259,1261,1306,1353,1355,1387,1395,1409,1428,1439,1456,1504,1510,1617,
            1526,1528,1568,1570,1572,1584,1600,1660,1683,1721,1778,1783,1792,1799,1820,1873,1885,1891,1975];

        foreach ( $uninstallUserIds as $user_id ) {
            $shop = new Details;
            $shop = $shop::findFirst('user_id='.$user_id);
            if ( $shop ) {
                $shop = $shop->toArray();
            } else continue;

            $shop_id = $shop['id'];
            $helperAmazon->uninstall('', $user_id, $shop_id);
        }

        return ['success' => true, 'message' => 'Deleted all the given user id(s)'];
    }
}
?>