<?php

namespace App\Amazon\Controllers;

use App\Amazon\Components\Report\Report;
use App\Connector\Components\ApiClient;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\Feed\Feed;
use App\Connector\Components\Dynamo;
use Aws\S3\S3Client;
use Phalcon\Events\Event;

class RequestController extends \App\Core\Controllers\BaseController
{
    const CSV_EXPORT_CHUNK_SIZE = 1500;

    public function authAction()
    {
        $appId = $this->request->get('sAppId');
        $region = $this->request->get('region');
        $state = $this->request->get('state');

        $url = $this->di->getConfig()->apiconnector->get('base_url') . 'apiconnect/request/auth?sAppId=' . $appId . '&region=' . $region . '&state=' . $state;

        return $this->response->redirect($url);

    }

    public function checkRefreshTokenAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper');

        return $this->prepareResponse($helper->checkRefreshToken($rawBody));
    }

    public function barcodeTypeAction(){

        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Common\Barcode')
                    ->getBarcodeType($rawBody);
        return $this->prepareResponse($response);

    }
    
    public function getFeedTypesAction()
    {
        $feedHelper = $this->di->getObjectManager()
                ->get('\App\Amazon\Components\Feed\Helper');
            $response = $feedHelper->getFeedTypes();
        return $this->prepareResponse($response);
    }

    public function previewAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if(isset($rawBody['source_product_ids']))
        {
            $commonHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
            $result = $commonHelper->getPreviewData($rawBody);
        }
        else
        {
            $result = ['success' => false , 'msg' => 'Invalid product'];
        }
        return $this->prepareResponse($result);
    }

    public function variantTitleAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if(isset($rawBody['source_product_ids']))
        {
            $commonHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
            $result = $commonHelper->getPreviewData($rawBody , true);
        }
        else
        {
            $result = ['success' => false , 'msg' => 'Invalid product Id'];
        }
        return $this->prepareResponse($result);
    }
    
    public function getAllProductTypesAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Template\Category')
                    ->getAllProductTypes($rawBody);
        return $this->prepareResponse($response);
    }

    public function requestProductReportAction()
    {

        if(isset($_GET['listingissue'])){

            $array = [
                'TH-BND-005'
            ];
            foreach( $array as $sku){
                
                $rawBody = json_decode('{
                "type": "full_class",
                "class_name": "",
                "method": "triggerWebhooks",
                "user_id": "66fb1939f7bdf50d220af894",
                "shop_id": 225746,
                "action": "LISTINGS_ITEM_ISSUES_CHANGE",
                "queue_name": "amazonbyced_listings_item_issues_change",
                "marketplace": "amazon",
                "data": {
                    "SellerId": "AGB0OB8AEEY9O",
                    "MarketplaceId": "ATVPDKIKX0DER",
                    "Severities": [
                        "ERROR"
                    ],
                    "EnforcementActions": [
                        "SEARCH_SUPPRESSED"
                    ]
                },
                "arrival_time": "2024-09-18T07:20:09+00:00",
                "app_codes": [
                    "amazon"
                ]
            }',true);
            $rawBody['data']['Sku'] = $sku;
                        $newb = $this->di->getObjectManager()
                            ->get('\App\Amazon\Components\Common\Helper');
                        $response = $newb->webhookProductIssueChange($rawBody);
                        
            }
            print_r($response);die;
        }
        if (isset($_GET['connectorAppDelete'])) {
            $queueData = '';
            print '<pre>';
            $connectorAppDelete = $this->di->getObjectManager()->get('\App\Connector\Components\Hook')
                ->shopEraser(json_decode($queueData, true));
        }

        if (isset($_GET['connectorTriggerWebhook'])) {
            $queueData = '';

            print '<pre>';
            $connectorSubscriptionCreate = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel')
                ->registerWebhooks(json_decode($queueData, true));
        }
        if (isset($_GET['accountDisconnect'])) {
            $eventsManager = $this->di->getEventsManager();
            $eventsManager->fire('application:beforeAccountDisconnect', $this, [ "user_id" => "62fb4bda4bab1f5e511b9ac3", "shop_id" => "402","source_shop_id" => "398" ]);

        }

        if (isset($_GET['afterAccountConnection'])) {
            $preparedData = [ 'source_marketplace' => [
                'shop_id' => '418', // source shop id
                'marketplace' => '' // add source name in single quotes for testing
            ],
                'target_marketplace' => [
                    'shop_id' => '416', // amazon shop id
                    'marketplace' => 'amazon' // amazon
                ],
                'user_id' => '63287c3063bca57d3d64d2f3'];

            $getUser = \App\Core\Models\User::findFirst([['_id' => '63287c3063bca57d3d64d2f3']]);
            $getUser->id = (string) $getUser->_id;
            $this->di->setUser($getUser);

            $subscription_create = $this->di->getObjectManager()->get('\App\Amazon\Components\Notification\Helper')
                ->createSubscription($preparedData);
        }
        if(isset($_GET['feedwebhook'])){
            $rawBody['data'] = json_decode('{
                "notificationVersion" : "2020-09-04",
                "notificationType" : "FEED_PROCESSING_FINISHED",
                "payloadVersion" : "1.0",
                "eventTime" : "2022-09-13T06:43:39.575Z",
                "payload" : {
                  "feedProcessingFinishedNotification" : {
                    "sellerId" : "A3VSMOL1YWESR0",
                    "feedId" : "127814019248",
                    "feedType" : "POST_INVENTORY_AVAILABILITY_DATA",
                    "processingStatus" : "DONE",
                    "resultFeedDocumentId" : "amzn1.tortuga.4.eu.46565520-278e-4a99-a648-ca4e7c1df35b.T1PK8YZ0MV58HW"
                  }
                },
                "notificationMetadata" : {
                  "applicationId" : "amzn1.sellerapps.app.b5994c79-9567-4ba5-b045-0645faf723b6",
                  "subscriptionId" : "50a0b95f-f458-4e05-ad42-ab8c3f2c4484",
                  "publishTime" : "2022-09-13T06:43:39.628Z",
                  "notificationId" : "9e621e0a-505a-473b-9609-9afe666d38ce"
                }
              }',true);

            $newb = $this->di->getObjectManager()
                ->get('\App\Amazon\Components\Common\Helper');
            $response = $newb->webhookFeedSync($rawBody);
            print_r($response);die;
        }

        if(isset($_GET['hello'])){

            $Product = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Product\Product::class);
            // $sourceProductIds = ['42801271079120','42801271144656','42801271177424'];
            $userId = '6266588aaba6b1f4080841f3';
            $products =json_decode(' []',true);
            foreach($products as $product){
                // print_r($product);die;
                $data = $Product->getUploadCategorySettings($product);

            }

        }
        if (isset($_GET['amazonlisting'])) {
            $preparedData = [
                'source' => [
                    'shopId' => '245', // source shop id
                    'marketplace' => '' // add source name in single quotes for testing
                ],
                'target' => [
                    'shopId' => '246', // amazon shop id
                    'marketplace' => 'amazon' // amazon
                ],
                'data'=> [
                    'matched'=> 'all',
                    'user_id' => '62fb4bda4bab1f5e511b9ac3',
                    'seller-sku' => 'AWndn123',
                    'activePage'=>1
                ]
            ];

            $getAmazonListing = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper')
                ->getAmazonListing($preparedData);

            return $this->prepareResponse($getAmazonListing);
        }
        if (isset($_GET['afterProductImportEvent'])) {
            $eventData['source_marketplace'] = '';   // add source name in single quotes for testing
            $eventData['source_shop_id'] =  '77';
            $eventData['target_marketplace'] = 'amazon';
            $eventData['target_shop_id'] = '121';
            $eventsManager = $this->di->getEventsManager();
            $eventsManager->fire('application:afterProductImport', $this, $eventData);
        }

        if (isset($_GET['updateFeed'])) {
            $jsonParams = '{"type":"full_class","class_name":"","method":"triggerWebhooks","queue_name":"amazonmulti_product_upload","app_tag":"default","user_id":"633296d60d5e31762758b2f9","app_code":"amazon_sales_channel","handle_added":1,"shop_id":"","data":{"type":"POST_FLAT_FILE_LISTINGS_DATA","feed_id":"135159019269","status":"_DONE_","feed_created_date":"2022-10-04T09:48:18.055Z","marketplace_id":["A21TJRUUN4KGV"],"remote_shop_id":"266","user_id":"633296d60d5e31762758b2f9","specifics":{"type":"POST_FLAT_FILE_LISTINGS_DATA","marketplace":["A21TJRUUN4KGV"],"ids":["43343544910041","7881087615193"],"shop_id":"266"},"s3_feed_id":"266_135159019269_submit.json","s3_response_id":"266_135159019269_response.json","serverless":true,"operation_type":"Update","source_shop_id":"476","action":"upload"},"action":"amazon_product_upload","arrival_time":"2022-10-04T09:48:35+00:00"}';
            $commonHelper = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Common\Helper::class);
            $res =  $commonHelper->serverlessResponse(json_decode($jsonParams, true));
            print_r($res);
            die();

        }



        if (isset($_GET['deleteProductFeedSync'])) {
            $jsonParams = '{"type":"full_class","class_name":"\/App\/Amazon\/Components\/Common\/Helper","method":"serverlessResponse","queue_name":"feed_sync","app_tag":"default","user_id":"62667f4aaebfeff3400bb186","app_code":"amazon_sales_channel","handle_added":1,"data":{"type":"POST_PRODUCT_DATA","feed_id":"120142019206","status":"_DONE_","feed_created_date":"2022-08-02T11:48:36.274Z","marketplace_id":["A21TJRUUN4KGV"],"remote_shop_id":"9","user_id":"62667f4aaebfeff3400bb186","specifics":{"type":"POST_PRODUCT_DATA","marketplace":["A21TJRUUN4KGV"],"ids":["42976519028966","42976520634598","7812853498086","7812855791846"],"shop_id":"9"},"s3_feed_id":"9_120142019206_submit.json","s3_response_id":"9_120142019206_response.json","serverless":true,"operation_type":"Delete","source_shop_id":"1","action":"delete"}}';

            $commonHelper = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Common\Helper::class);
            $res =  $commonHelper->serverlessResponse(json_decode($jsonParams, true));
            print_r($res);
            die();

        }

        if (isset($_GET['priceFeedSync'])) {
//            $jsonParams = '{"type":"full_class","class_name":"\\App\\Amazon\\Components\\Common\\Helper","method":"serverlessResponse","queue_name":"feed_sync","app_tag":"default","user_id":"62667f4aaebfeff3400bb186","app_code":"amazon_sales_channel","handle_added":1,"data":{"type":"POST_PRODUCT_PRICING_DATA","feed_id":"117331019186","status":"_DONE_","feed_created_date":"2022-07-13T12:53:17.500Z","marketplace_id":["A21TJRUUN4KGV"],"remote_shop_id":"9","user_id":"62667f4aaebfeff3400bb186","specifics":{"type":"POST_PRODUCT_PRICING_DATA","marketplace":["A21TJRUUN4KGV"],"ids":["42976519028966","42976520634598"],"shop_id":"9"},"s3_feed_id":"9_117331019186_submit.json","s3_response_id":"9_117331019186_response.json","serverless":true,"operation_type":"Update","source_shop_id":"1","action":"price"}}';

//            $jsonParams = '{"type":"full_class","class_name":"\/App\/Amazon\/Components\/Common\/Helper","method":"serverlessResponse","queue_name":"feed_sync","app_tag":"default","user_id":"62667f4aaebfeff3400bb186","app_code":"amazon_sales_channel","handle_added":1,"data":{"type":"POST_PRODUCT_PRICING_DATA","feed_id":"117329019186","status":"_DONE_","feed_created_date":"2022-07-13T12:42:52.405Z","marketplace_id":["A21TJRUUN4KGV"],"remote_shop_id":"9","user_id":"62667f4aaebfeff3400bb186","specifics":{"type":"POST_PRODUCT_PRICING_DATA","marketplace":["A21TJRUUN4KGV"],"ids":["42976519028966","42976520634598"],"shop_id":"9"},"s3_feed_id":"9_117329019186_submit.json","s3_response_id":"9_117329019186_response.json","serverless":true,"operation_type":"Update","source_shop_id":"1","action":"price"}}';

            $jsonParams = '{"type":"full_class","class_name":"\/App\/Amazon\/Components\/Common\/Helper","method":"serverlessResponse","queue_name":"feed_sync","app_tag":"default","user_id":"62667f4aaebfeff3400bb186","app_code":"amazon_sales_channel","handle_added":1,"data":{"type":"POST_PRODUCT_DATA","feed_id":"117468019187","status":"_DONE_","feed_created_date":"2022-07-14T10:19:36.214Z","marketplace_id":["A21TJRUUN4KGV"],"remote_shop_id":"9","user_id":"62667f4aaebfeff3400bb186","specifics":{"type":"POST_PRODUCT_DATA","marketplace":["A21TJRUUN4KGV"],"ids":["42976519028966","7812853498086"],"shop_id":"9"},"s3_feed_id":"9_117468019187_submit.json","s3_response_id":"9_117468019187_response.json","serverless":true,"operation_type":"Delete","source_shop_id":"1","action":"delete"}}';

            $commonHelper = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Common\Helper::class);
            $res =  $commonHelper->serverlessResponse(json_decode($jsonParams, true));
            print_r($res);
            die();

        }

        if (isset($_GET['deleteProduct'])) {
            $jsonParams = '';
            $priceComponent = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Product\Product::class);
            $res =  $priceComponent->init()->delete(json_decode($jsonParams, true));
            print_r($res);
            die();
        }
        if (isset($_GET['Vaishnavi'])) {
            $marketplaceOrder = '{"success":true,"data":{"_url":"\/webapi\/rest\/v1\/order","shop_id":"55","amazon_order_id":"0","buyer_email":"0","order_status":["Unshipped"],"order_channel":"MFN","lower":"2021-08-30 11:11:50 +0000","type":"Created","home_shop_id":"382"},"orders":[{"data":{"AmazonOrderId":"' . mt_rand(100, 999) . "-" . mt_rand(1000000, 9999999) . "-" . mt_rand(1000000, 9999999) . '","PurchaseDate":"2021-08-30 11:11:50 +0000","LastUpdateDate":"2021-08-30 11:11:50 +0000","OrderStatus":"Unshipped","FulfillmentChannel":"MFN","SalesChannel":"Amazon.com","ShipServiceLevel":"Std US D2D Dom","ShippingAddress":{"Name":"Anita Gerards","AddressLine1":"520 UNION LN","AddressLine2":"","AddressLine3":"","City":"BRIELLE","County":"","District":"","StateOrRegion":"NJ","PostalCode":"08730-1421","CountryCode":"US","Phone":""},"OrderTotal":{"Amount":"47.29","CurrencyCode":"USD"},"NumberOfItemsShipped":"0","NumberOfItemsUnshipped":"10","PaymentMethod":"Other","MarketplaceId":"A21TJRUUN4KGV","BuyerName":"Anita gerards","buyeremail":"6g37tc7chmnjwf2@marketplace.amazon.com","ShipServiceLevelCategory":"Standard","EarliestShipDate":"2021-08-07 11:11:50 +0000","LatestShipDate":"2021-08-09 11:11:50 +0000","EarliestDeliveryDate":"2021-08-09 11:11:50 +0000","LatestDeliveryDate":"2021-08-11 11:11:50 +0000","IsPrime":"false","IsBusinessOrder":"false","IsPremiumOrder":"false","loadedByReport":false,"loadedByData":false},"items":[{"ASIN":"B0057OXMUO","SellerSKU":"' . $_GET['sku'] . '","OrderItemId":"20091962421466","Title":"Rolling Sands Premium Solid Color Mexican Yoga Blanket, Purple","QuantityOrdered":"10","QuantityShipped":"0","ItemPrice":{"Amount":"29.99","CurrencyCode":"USD"},"ShippingPrice":{"Amount":"17.30","CurrencyCode":"USD"},"ItemTax":{"Amount":"0.00","CurrencyCode":"USD"},"ShippingTax":{"Amount":"0.00","CurrencyCode":"USD"},"ShippingDiscount":{"Amount":"0.00","CurrencyCode":"USD"},"PromotionDiscount":{"Amount":"0.00","CurrencyCode":"USD"}}],"region":"IN","country":"IN","url":"https:\/\/sellercentral.amazon.in\/orders-v3\/order\/878-3618152-3371112","shop_id":"382"}],"start_time":"08-06-2021 11:11:50","end_time":"08-06-2021 11:11:50","execution_time":0.00391697883605957,"ip":"127.0.0.1"}';
            $order = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Order\Order::class);
            $order->init(['user_id' => '61d5827398530c1808770a44	'])->saveOrder($marketplaceOrder);
            print '<pre>';
            print_r(json_decode($marketplaceOrder, true));
            die('order import done');
        }
        if (isset($_GET['webhookgetreport'])) {
            $rawBody=[
                "notificationVersion" => "2020-09-04",
                "notificationType" => "REPORT_PROCESSING_FINISHED",
                "payloadVersion" => "1.0",
                "eventTime" => "2022-07-01T09:56:34.071Z",
                "payload" => [
                    "reportProcessingFinishedNotification" => [
                        "sellerId" => "A3VSMOL1YWESR0",
                        "reportId" => "127795019248",
                        "reportType" => "GET_MERCHANT_LISTINGS_ALL_DATA",
                        "processingStatus" => "DONE",
                        "reportDocumentId" => "amzn1.spdoc.1.4.eu.d8c879f2-d4e3-4189-a198-cee9db0c2af0.TACV7Y196TEUJ.47700"
                    ]
                ],
                "notificationMetadata" => [
                    "applicationId" => "amzn1.sellerapps.app.b5994c79-9567-4ba5-b045-0645faf723b6",
                    "subscriptionId" => "b8ea43fd-6f50-4b52-89c2-4efb3426f567",
                    "publishTime" => "2022-07-01T09:56:34.124Z",
                    "notificationId" => "60456b08-478d-4e11-be64-417675180124"
                ]

            ];
            $newb = $this->di->getObjectManager()
                ->get('\App\Amazon\Components\Common\Helper');
            $response = $newb->webhookGetReport($rawBody);
        }
        if (isset($_GET['faizan'])) {
            $userId = $this->di->getUser()->id;

            print_r($userId);
            die;
            // $order = $this->di->getObjectManager()
            //     ->get(\App\Amazon\Components\Order\Hook::class);
            //     $order->manualOrderShipment();
        }


        if (isset($_GET['mfa'])) {
            print '<pre>';
            $qtss = [];
            $message=[
                'shop_id'=> '7',
                'report_content'=>'hello'
            ];
            $res=$this->di->getObjectManager()->get('\App\Amazon\Components\Report\Report')->savefile($message);
            print_r($res);

            // $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            // $queuedTasks = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::QUEUED_TASKS);
            // $reportContainer = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::REPORT_CONTAINER);
            // $queuedt = $queuedTasks->find(['type' => 'default_amazon_product_match', 'progress' => 0], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            // $queuedTaskss = $queuedt->toArray();


            // foreach ($queuedTaskss as $qts) {
            //     $reportCount = $reportContainer->find(['queued_task_id' => (string)$qts['_id']]);
            //     //   if (!$reportCount) {
            //     //                      $queuedTasks->deleteOne(['user_id' => $qts['user_id'], 'type' => 'default_amazon_product_match']);
            //     //                      $reportContainer->deleteOne(['user_id' => $qts['user_id'], 'queued_task_id' => (string)$qts['_id']]);
            //     $qtss[] = $reportCount->toArray();
            //     //   }
            // }

            die();
        }
        if(isset($_GET['response'])){
            $sqsData= json_decode('{
                "type": "full_class",
                "class_name": "\/App\/Amazon\/Components\/Common\/Helper",
                "method": "serverlessResponse",
                "queue_name": "product_upload",
                "app_tag": "default",
                "user_id": "6266588aaba6b1f4080841f3",
                "app_code": "amazon_sales_channel",
                "handle_added": "1",
                "data": {

                    "type": "POST_FLAT_FILE_LISTINGS_DATA",
                    "feed_id": "114409019163",
                    "status": "_DONE_",
                    "feed_created_date": "2022-06-20T10:38:59.725Z",
                    "marketplace_id": [
                        "A21TJRUUN4KGV"
                    ],
                    "remote_shop_id": "2",
                    "user_id": "6266588aaba6b1f4080841f3",
                    "specifics": {
                        "type": "POST_FLAT_FILE_LISTINGS_DATA",
                        "marketplace": [
                            "A21TJRUUN4KGV"
                        ],
                        "ids": [
                            "32419575103625",
                            "32419577135241"
                        ],
                        "shop_id": "2"
                    },
                    "s3_feed_id": "2_114409019163_submit.json",
                    "s3_response_id": "2_114409019163_response.json",
                    "serverless": "1",
                    "operation_type": "Update",
                    "source_shop_id": "1"
                }
            }',true);
            $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper')->serverlessResponse($sqsData);

            /*    "notificationVersion": "2020-09-04",
                "notificationType": "FEED_PROCESSING_FINISHED",
                "payloadVersion": "1.0",
                "eventTime": "2022-07-01T11:18:17.799Z",
                "payload": {
                  "feedProcessingFinishedNotification": {
                    "sellerId": "A3VSMOL1YWESR0",
                    "feedId": "114409019163",
                    "feedType": "POST_FLAT_FILE_LISTINGS_DATA",
                    "processingStatus": "DONE",
                    "resultFeedDocumentId": "amzn1.tortuga.3.727557c3-7c11-4e9b-adf6-ffb1dfb2773b.T3PGEZMIA97FJJ"
                  }
                },
                "notificationMetadata": {
                  "applicationId": "amzn1.sellerapps.app.b5994c79-9567-4ba5-b045-0645faf723b6",
                  "subscriptionId": "b784b310-e00e-4f3c-9bcd-198086d44590",
                  "publishTime": "2022-07-01T11:18:17.866Z",
                  "notificationId": "df7a9702-1ea8-486b-b30b-9190777760db"
                }
              }
            }',true);
          $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper')->webhookFeedSync($sqsData); */

        }

        //   $response = $this->manualMapMigration();
        //   die(" details");
        // $response = $this->ShippedOrderFixes();
        // die(" shippredorderFixes");
        //        set_time_limit(0);
        //        $requestControl = $this->di->getObjectManager()
        //                        ->get(\App\Amazon\Components\Cron\Route\Requestcontrol::class);
        //               $feed =  $requestControl->syncFeed(['user_id' => '6130368f1da0415b354f1637']);
        //               die('yyy');

        //        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //        $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::ORDER_CONTAINER);
        //       $orders = $collection->find(['source_status' => 'Unshipped', 'target_order_id' => ['$exists' => true], 'target_status' => ['$in' => [ 'shipped']], 'user_id' => '613102a58ef4b91b47268357'], ["typeMap" => ['root' => 'array', 'document' => 'array'], 'limit' => 4]);
        //
        //       $orderArray = $orders->toArray();
        //       print '<pre>';
        //       print_r(array_column($orderArray, 'source_order_id'));
        //       print_r(count($orderArray));
        ////       die();
        //
        //
        //
        //        $commonHelper = $this->di->getObjectManager()
        //            ->get(\App\Amazon\Components\Common\Helper::class);
        //        $orderShipped = [];
        //
        //
        //        foreach ($orderArray as $order) {
        //            $params = [
        //                'shop_id' => 2174,
        //                'amazon_order_id' => $order['source_order_id'],
        //                'home_shop_id' => 2265
        //            ];
        //            print_r($orderArray);
        //            print_r($params);
        //
        //            $response = $commonHelper->sendRequestToAmazon('order', $params, 'GET');
        //            print_r($response);
        //            if (isset($response['orders']) && count($response['orders'])) {
        //                foreach ($response['orders'] as $amzOrder) {
        //                    $order = $amzOrder['data'];
        //                    if ($order['OrderStatus'] == 'Shipped') {
        //                        $orderShipped[] = $order['source_order_id'];
        //                    }
        //                }
        //            }
        //
        //        }
        //
        //        print_r(json_encode($orderShipped));
        //        die();

        if (isset($_GET['getreport'])) {
            print '<pre>';
            $report = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Report\Report::class);
            $report->init(['user_id' => '617837d0c1bd0a69fd3b908f'])->getReport();
            die('Report get done');
        }
        //        $preparedData = [
        //
        //                "type" =>"full_class",
        //  "class_name"=> "\App\Amazon\Components\Report\Report",
        //  "method"=> "moldReport",
        //  "queue_name"=> "amazon_get_report",
        //  "user_id"=> "612cf0f567318768af3b5d46",
        //  "shop_id"=> "2028",
        //  "queued_task_id"=> "612e3f88c1abf525db651456",
        //  "skip_character"=> 122258,
        //  "chunk"=> 100,
        //  "file_path"=> "/var/www/html/home/var/file/612cf0f567318768af3b5d46/report.tsv",
        //  "scanned"=> 100
        //
        //        ];

        //        for ($page = 0; $page<20; $page++) {
        if (isset($_GET['matchproduct'])) {
            print '<pre>';
            $matchData = [
                'type' => 'full_class',
                'class_name' => '\App\Amazon\Components\Cron\Route\Requestcontrol',
                'method' => 'matchProductFromAmazon',
                'queue_name' => 'amazon_product_match',
                'user_id' => '61d5ab1f79cbb17796473307',
                'shop_id' => '6453',
                'limit' => 10000,
                'queued_task_id' => '61dbfb629ef7ad77b66c6db2',
                'page' => 1,
                'individual_weight' => 1.1,
            ];
            //
            $requestControl = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Cron\Route\Requestcontrol::class);
            $requestControl->matchProductFromAmazon($matchData);
            //    }
            die('Product match done');
        }

        if (isset($_GET['moldreport'])) {
            $report = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Report\Report::class);
            print '<pre>';
            $handlerData = [
                "type" => "full_class",
                "class_name" => "\App\Amazon\Components\Cron\Route\Requestcontrol",
                "method" => "moldReportMethod",
                "queue_name" => "amazon_mold_report",
                "user_id" => "61d844569e901256a45236c4",
                "shop_id" => "6661",
                "queued_task_id" => "61de80d8dddc4b27ab309e4c",
                "skip_character" => 0,
                "chunk" => 500,
                "file_path" => "/var/www/html/home/var/file/mfa/61d844569e901256a45236c4/6661/report.tsv",
                "scanned" => 0
            ];
            $report->init(['user_id' => '61d844569e901256a45236c4'])->moldReport($handlerData);
            die('mold report done');
        }


        // //


        // if(isset($_GET['shipment'])){

        //     $this->shipOrderOnAmazonAction();
        //     die();
        // }


        //    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //    $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);
        //    $amazonListing = $collection->find(["user_id" => '6130e5adf6725006e115e6aa'],["typeMap" => ['root' => 'array', 'document' => 'array']]);
        //    print '<pre>';
        //    print_r(count($amazonListing->toArray()));
        //    die();


        //        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //        $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
        //        $sku = 'MSH-SW-GC-xL';
        //        $productData = $collection->findOne(['sku' => new \MongoDB\BSON\Regex('^'.preg_quote((string)$sku).'$',"i")], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        //        $sku2 = '8272491';
        //        $productData2 = $collection->findOne(['sku' => new \MongoDB\BSON\Regex('^'.preg_quote((string)$sku2).'$',"i")], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        //        $sku3 = '7081452L';
        //        $productData3 = $collection->findOne(['sku' => new \MongoDB\BSON\Regex('^'.preg_quote((string)$sku3).'$',"i")], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        //        print '<pre>';
        //        print_r($sku);
        //        print_r(' = ');
        //        print_r($productData['sku']);
        //        print_r(' ------ ');
        //        print_r($sku2);
        //        print_r(' = ');
        //        print_r($productData2['sku']);
        //        print_r(' ------ ');
        //        print_r($sku3);
        //        print_r(' = ');
        //        print_r($productData3['sku']);
        //        die();

        //                $order = $this->di->getObjectManager()
        //                        ->get(\App\Amazon\Components\Order\Order::class);
        //                $order->init(['user_id' => '613102a58ef4b91b47268357'])->fetchOrders(['user_id' => '613102a58ef4b91b47268357']);
        //                die('order sync done');


        //        print '<pre>';
        //        for ($page = 0; $page <=70; $page++) {
        //            $params = [
        //                'type' => 'full_class',
        //                'method' =>'updateProductContainerStatus',
        //                'queue_name' =>  'amazon_amazon_product_match',
        //                'user_id' => '60d5d70cfd78c76e34776e12',
        //                'page' => $page,
        //                'limit' => 100,
        //                "handle_added" => 1,
        //                "shop_id" => "285",
        //            ];
        //       //     $params = ['user_id' => '6065cd371c4d2633c2009b94', 'shop_id' => '88', 'page' => $page, 'limit' => 100, 'queue_name' => 'amazon_product_match'];
        //            $requestControl = $this->di->getObjectManager()
        //                ->get(\App\Amazon\Components\Cron\Route\Requestcontrol::class);
        //            $requestControl->updateProductContainerStatus($params);
        //        }


        //        for ($page = 0; $page <=70; $page++) {
        //            $params = ['user_id' => '6065cd371c4d2633c2009b94', 'page' => $page, 'limit' => 100, 'queue_name' => 'amazon_product_search', 'queued_task_id' => '60e5ae55d039742c0a6edff5'];
        //            $amazon_shops = '[
        //                {
        //                    "remote_shop_id" : 43,
        //                        "marketplace" : "amazon",
        //                        "warehouses" : [
        //                                {
        //                                    "region" : "IN",
        //                                        "marketplace_id" : "A21TJRUUN4KGV",
        //                                        "seller_id" : "A3VSMOL1YWESR0",
        //                                        "status" : "active",
        //                                        "_id" : 88
        //                                }
        //                        ],
        //                        "_id" : 88,
        //                        "updated_at" : "2021-06-01T10:47:53+00:00",
        //                        "remote_shop" : 40
        //                }
        //        ]';
        //            $params['amazon_shops'] = json_decode($amazon_shops, true);
        //
        //            $requestControl = $this->di->getObjectManager()
        //                ->get(\App\Amazon\Components\Cron\Route\Requestcontrol::class);
        //            $requestControl->searchOnAmazon($params);
        //        }

        // $marketplaceOrder = '{"success":true,"data":{"_url":"\/webapi\/rest\/v1\/order","shop_id":"51","amazon_order_id":"0","buyer_email":"0","order_status":["Unshipped"],"order_channel":"MFN","lower":"2021-08-30 11:11:50 +0000","type":"Created","home_shop_id":"5209"},"orders":[{"data":{"AmazonOrderId":"'.mt_rand(100,999)."-".mt_rand(1000000, 9999999)."-".mt_rand(1000000, 9999999).'","PurchaseDate":"2021-08-30 11:11:50 +0000","LastUpdateDate":"2021-08-30 11:11:50 +0000","OrderStatus":"Unshipped","FulfillmentChannel":"MFN","SalesChannel":"Amazon.com","ShipServiceLevel":"Std US D2D Dom","ShippingAddress":{"Name":"Anita Gerards","AddressLine1":"520 UNION LN","AddressLine2":"","AddressLine3":"","City":"BRIELLE","County":"","District":"","StateOrRegion":"NJ","PostalCode":"08730-1421","CountryCode":"US","Phone":""},"OrderTotal":{"Amount":"47.29","CurrencyCode":"USD"},"NumberOfItemsShipped":"0","NumberOfItemsUnshipped":"1","PaymentMethod":"Other","MarketplaceId":"A21TJRUUN4KGV","BuyerName":"Anita Gerards","BuyerEmail":"6g37tc7chmnjwf2@marketplace.amazon.com","ShipServiceLevelCategory":"Standard","EarliestShipDate":"2021-08-07 11:11:50 +0000","LatestShipDate":"2021-08-09 11:11:50 +0000","EarliestDeliveryDate":"2021-08-09 11:11:50 +0000","LatestDeliveryDate":"2021-08-11 11:11:50 +0000","IsPrime":"false","IsBusinessOrder":"false","IsPremiumOrder":"false","loadedByReport":false,"loadedByData":false},"items":[{"ASIN":"B0057OXMUO","SellerSKU":"120758889000","OrderItemId":"20091962421466","Title":"Rolling Sands Premium Solid Color Mexican Yoga Blanket, Purple","QuantityOrdered":"1","QuantityShipped":"1","ItemPrice":{"Amount":"29.99","CurrencyCode":"USD"},"ShippingPrice":{"Amount":"17.30","CurrencyCode":"USD"},"ItemTax":{"Amount":"0.00","CurrencyCode":"USD"},"ShippingTax":{"Amount":"0.00","CurrencyCode":"USD"},"ShippingDiscount":{"Amount":"0.00","CurrencyCode":"USD"},"PromotionDiscount":{"Amount":"0.00","CurrencyCode":"USD"}}],"region":"IN","country":"IN","url":"https:\/\/sellercentral.amazon.in\/orders-v3\/order\/878-3618152-3371112","shop_id":"5209"}],"start_time":"08-06-2021 11:11:50","end_time":"08-06-2021 11:11:50","execution_time":0.00391697883605957,"ip":"127.0.0.1"}';
        // $order = $this->di->getObjectManager()
        //     ->get(\App\Amazon\Components\Order\Order::class);
        // $order->init(['user_id' => '616e6e64553378099d13995d'])->saveOrder($marketplaceOrder);
        // print '<pre>';
        // print_r(json_decode($marketplaceOrder, true));
        // die('order import done');
        if (isset($_GET['create'])) {
            $marketplaceOrder = '{"success":true,"data":{"_url":"\/webapi\/rest\/v1\/order","shop_id":"51","amazon_order_id":"0","buyer_email":"0","order_status":["Unshipped"],"order_channel":"MFN","lower":"2021-08-30 11:11:50 +0000","type":"Created","home_shop_id":"5436"},"orders":[{"data":{"AmazonOrderId":"' . mt_rand(100, 999) . "-" . mt_rand(1000000, 9999999) . "-" . mt_rand(1000000, 9999999) . '","PurchaseDate":"2021-08-30 11:11:50 +0000","LastUpdateDate":"2021-08-30 11:11:50 +0000","OrderStatus":"Unshipped","FulfillmentChannel":"MFN","SalesChannel":"Amazon.com","ShipServiceLevel":"Std US D2D Dom","ShippingAddress":{"Name":"Anita Gerards","AddressLine1":"520 UNION LN","AddressLine2":"","AddressLine3":"","City":"BRIELLE","County":"","District":"","StateOrRegion":"NJ","PostalCode":"08730-1421","CountryCode":"US","Phone":""},"OrderTotal":{"Amount":"47.29","CurrencyCode":"USD"},"NumberOfItemsShipped":"0","NumberOfItemsUnshipped":"10","PaymentMethod":"Other","MarketplaceId":"A21TJRUUN4KGV","BuyerName":"Anita gerards","buyeremail":"6g37tc7chmnjwf2@marketplace.amazon.com","ShipServiceLevelCategory":"Standard","EarliestShipDate":"2021-08-07 11:11:50 +0000","LatestShipDate":"2021-08-09 11:11:50 +0000","EarliestDeliveryDate":"2021-08-09 11:11:50 +0000","LatestDeliveryDate":"2021-08-11 11:11:50 +0000","IsPrime":"false","IsBusinessOrder":"false","IsPremiumOrder":"false","loadedByReport":false,"loadedByData":false},"items":[{"ASIN":"B0057OXMUO","SellerSKU":"sku7","OrderItemId":"20091962421466","Title":"Rolling Sands Premium Solid Color Mexican Yoga Blanket, Purple","QuantityOrdered":"10","QuantityShipped":"0","ItemPrice":{"Amount":"29.99","CurrencyCode":"USD"},"ShippingPrice":{"Amount":"17.30","CurrencyCode":"USD"},"ItemTax":{"Amount":"0.00","CurrencyCode":"USD"},"ShippingTax":{"Amount":"0.00","CurrencyCode":"USD"},"ShippingDiscount":{"Amount":"0.00","CurrencyCode":"USD"},"PromotionDiscount":{"Amount":"0.00","CurrencyCode":"USD"}}],"region":"IN","country":"IN","url":"https:\/\/sellercentral.amazon.in\/orders-v3\/order\/878-3618152-3371112","shop_id":"5436"}],"start_time":"08-06-2021 11:11:50","end_time":"08-06-2021 11:11:50","execution_time":0.00391697883605957,"ip":"127.0.0.1"}';
            $order = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Order\Order::class);
            $order->init(['user_id' => '61a884672ff67078b248c15c'])->saveOrder($marketplaceOrder);
            print '<pre>';
            print_r(json_decode($marketplaceOrder, true));
            die('order import done');
        }
        if (isset($_GET['dev'])) {
            $marketplaceOrder = '{"success":true,"data":{"_url":"\/webapi\/rest\/v1\/order","shop_id":"55","amazon_order_id":"0","buyer_email":"0","order_status":["Unshipped"],"order_channel":"MFN","lower":"2021-08-30 11:11:50 +0000","type":"Created","home_shop_id":"382"},"orders":[{"data":{"AmazonOrderId":"' . mt_rand(100, 999) . "-" . mt_rand(1000000, 9999999) . "-" . mt_rand(1000000, 9999999) . '","PurchaseDate":"2021-08-30 11:11:50 +0000","LastUpdateDate":"2021-08-30 11:11:50 +0000","OrderStatus":"Unshipped","FulfillmentChannel":"MFN","SalesChannel":"Amazon.com","ShipServiceLevel":"Std US D2D Dom","ShippingAddress":{"Name":"Anita Gerards","AddressLine1":"520 UNION LN","AddressLine2":"","AddressLine3":"","City":"BRIELLE","County":"","District":"","StateOrRegion":"NJ","PostalCode":"08730-1421","CountryCode":"US","Phone":""},"OrderTotal":{"Amount":"47.29","CurrencyCode":"USD"},"NumberOfItemsShipped":"0","NumberOfItemsUnshipped":"10","PaymentMethod":"Other","MarketplaceId":"A21TJRUUN4KGV","BuyerName":"Anita gerards","buyeremail":"6g37tc7chmnjwf2@marketplace.amazon.com","ShipServiceLevelCategory":"Standard","EarliestShipDate":"2021-08-07 11:11:50 +0000","LatestShipDate":"2021-08-09 11:11:50 +0000","EarliestDeliveryDate":"2021-08-09 11:11:50 +0000","LatestDeliveryDate":"2021-08-11 11:11:50 +0000","IsPrime":"false","IsBusinessOrder":"false","IsPremiumOrder":"false","loadedByReport":false,"loadedByData":false},"items":[{"ASIN":"B0057OXMUO","SellerSKU":"120758889000","OrderItemId":"20091962421466","Title":"Rolling Sands Premium Solid Color Mexican Yoga Blanket, Purple","QuantityOrdered":"10","QuantityShipped":"0","ItemPrice":{"Amount":"29.99","CurrencyCode":"USD"},"ShippingPrice":{"Amount":"17.30","CurrencyCode":"USD"},"ItemTax":{"Amount":"0.00","CurrencyCode":"USD"},"ShippingTax":{"Amount":"0.00","CurrencyCode":"USD"},"ShippingDiscount":{"Amount":"0.00","CurrencyCode":"USD"},"PromotionDiscount":{"Amount":"0.00","CurrencyCode":"USD"}}],"region":"IN","country":"IN","url":"https:\/\/sellercentral.amazon.in\/orders-v3\/order\/878-3618152-3371112","shop_id":"382"}],"start_time":"08-06-2021 11:11:50","end_time":"08-06-2021 11:11:50","execution_time":0.00391697883605957,"ip":"127.0.0.1"}';
            $order = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Order\Order::class);
            $order->init(['user_id' => '61d5827398530c1808770a44'])->saveOrder($marketplaceOrder);
            print '<pre>';
            print_r(json_decode($marketplaceOrder, true));
            die('order import done');
        }


        //        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //        $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
        //        $sku = 'MSH-SW-GC-xL';
        //        $productData = $collection->findOne(['sku' => new \MongoDB\BSON\Regex('^'.preg_quote((string)$sku).'$',"i")], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        //        $sku2 = '8272491';
        //        $productData2 = $collection->findOne(['sku' => new \MongoDB\BSON\Regex('^'.preg_quote((string)$sku2).'$',"i")], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        //        $sku3 = '7081452L';
        //        $productData3 = $collection->findOne(['sku' => new \MongoDB\BSON\Regex('^'.preg_quote((string)$sku3).'$',"i")], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        //        print '<pre>';
        //        print_r($sku);
        //        print_r(' = ');
        //        print_r($productData['sku']);
        //        print_r(' ------ ');
        //        print_r($sku2);
        //        print_r(' = ');
        //        print_r($productData2['sku']);
        //        print_r(' ------ ');
        //        print_r($sku3);
        //        print_r(' = ');
        //        print_r($productData3['sku']);
        //        die();

        //        $order = $this->di->getObjectManager()
        //                ->get(\App\Amazon\Components\Order\Order::class);
        //        $order->init(['user_id' => '60e72fd0aa04d96d5d7de2e0'])->shipOrders([]);
        //        die('shipment sync done');


        //        for ($page = 0; $page <=70; $page++) {
        //            $params = ['user_id' => '6065cd371c4d2633c2009b94', 'shop_id' => '88', 'page' => $page, 'limit' => 100, 'queue_name' => 'amazon_product_match'];
        //            $requestControl = $this->di->getObjectManager()
        //                ->get(\App\Amazon\Components\Cron\Route\Requestcontrol::class);
        //            $requestControl->matchProductFromAmazon($params);
        //        }
        //        die();

        //        for ($page = 0; $page <=70; $page++) {
        //            $params = ['user_id' => '6065cd371c4d2633c2009b94', 'page' => $page, 'limit' => 100, 'queue_name' => 'amazon_product_search', 'queued_task_id' => '60e5ae55d039742c0a6edff5'];
        //            $amazon_shops = '[
        //                {
        //                    "remote_shop_id" : 43,
        //                        "marketplace" : "amazon",
        //                        "warehouses" : [
        //                                {
        //                                    "region" : "IN",
        //                                        "marketplace_id" : "A21TJRUUN4KGV",
        //                                        "seller_id" : "A3VSMOL1YWESR0",
        //                                        "status" : "active",
        //                                        "_id" : 88
        //                                }
        //                        ],
        //                        "_id" : 88,
        //                        "updated_at" : "2021-06-01T10:47:53+00:00",
        //                        "remote_shop" : 40
        //                }
        //        ]';
        //            $params['amazon_shops'] = json_decode($amazon_shops, true);
        //
        //            $requestControl = $this->di->getObjectManager()
        //                ->get(\App\Amazon\Components\Cron\Route\Requestcontrol::class);
        //            $requestControl->searchOnAmazon($params);
        //        }

        die();


        $params = ['shop_id' => 5, 'type' => '_GET_MERCHANT_LISTINGS_ALL_DATA_'];
        $commonHelper = $this->di->getObjectManager()
            ->get(Helper::class);
        $response = $commonHelper->sendRequestToAmazon('report-request', $params, 'GET');
        //        $response = $this->di->getObjectManager()
        //            ->get(ApiClient::class)
        //            ->init('amazon', 'true')
        //            ->call('report-request', [], ['shop_id' => 8, 'type' => '_GET_FLAT_FILE_OPEN_LISTINGS_DATA_'], 'GET');
        if (isset($response['result'])) {
            $createReport = $this->di->getObjectManager()
                ->get(Report::class)
                ->init(['user_id' => 3, 'shop_id' => 5])
                ->saveReport(json_encode($response));
        }
        print '<pre>';
        print_r($response);
        print '<br>';
        print_r($createReport);
        die();
    }

    public function getReportAction()
    {
        try {
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Report\Helper')
                ->getReport();
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        return $this->prepareResponse($response);
    }

    public function getAmazonCategoryAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            $templateHelper = $this->di->getObjectManager()
                ->get('\App\Amazon\Components\Template\Helper');
            $response = $templateHelper->getAmazonCategory($rawBody);
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }
        return $this->prepareResponse($response);
    }

    public function deleteCacheAttributesAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            if(isset($rawBody['marketplace_id'] , $rawBody['category'] , $rawBody['sub_category']))
            {
                $cacheAttribute = $this->di->getObjectManager()
                    ->get('App\Amazon\Components\Template\CategoryAttributeCache');
                $result = $cacheAttribute->deleteAttributesFromCache($rawBody['marketplace_id'] , $rawBody['category'] , $rawBody['sub_category']);
                if($result) {
                    $response = ['success' => true, "message" => 'File deleted succesfully'];
                } else {
                    $response = ['success' => false, "message" => 'File not found in cache data for the given data or empty values passed'];
                }
            } else {
                $response = ['success' => false, "message" => 'marketplace_id , category or sub_category is absent'];
            }

        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }
        return $this->prepareResponse($response);
    }

    public function getBrowseNodeIdsAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $templateHelper = $this->di->getObjectManager()
                ->get('\App\Amazon\Components\Template\Helper');
            $response = $templateHelper->getBrowseNodeIds($rawBody);
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }
        return $this->prepareResponse($response);
    }

    public function getVariantAttributesAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            $VariantAttribute = $this->di->getObjectManager()->get('App\Amazon\Components\Product\Product');
            $response = $VariantAttribute->getVariantAttributes($rawBody);
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];

        }
        return $this->prepareResponse($response);
    }

    public function getVendorAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            $VariantAttribute = $this->di->getObjectManager()->get('App\Amazon\Components\Product\Product');
            $response = $VariantAttribute->getVendor($rawBody);
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }
        return $this->prepareResponse($response);
    }

    public function getAmazonSubCategoryAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            $templateHelper = $this->di->getObjectManager()
                ->get('\App\Amazon\Components\Template\Helper');
            $response = $templateHelper->getAmazonSubCategory($rawBody);
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }
        return $this->prepareResponse($response);
    }

    public function getAmazonAttributesAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            $templateHelper = $this->di->getObjectManager()
                ->get('\App\Amazon\Components\Template\Helper');
            $response = $templateHelper->getAmazonAttributes($rawBody);
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }
        return $this->prepareResponse($response);
    }

    public function orderAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\Helper')
            ->fetchOrders($rawBody);
        return $this->prepareResponse($response);
    }

    public function checkWarehouseAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $templateHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper');

        return $this->prepareResponse($templateHelper->warehouseCheck($rawBody));
    }

    public function getOrderByIdAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\Helper')->getOrderById($rawBody);
        return $this->prepareResponse($response);
    }

    public function productsByQueryAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')->getProductsByQuery($rawBody);
        return $this->prepareResponse($response);
    }

    public function getProfileProductsCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $userId = $this->di->getUser()->id;
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')->getProfileProductsCount($userId, $rawBody);
        return $this->prepareResponse($response);
    }

    public function shipOrderOnAmazonAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = "No order found to ship";
        // $response =  $this->di->getObjectManager()->get('\App\Amazon\Components\Order\Helper')->init()->shipOrders();
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\Helper')
            ->shipOrders(['user_id' => "618ec5c91ed9ca7fe03d3310"]);
        return $this->prepareResponse($response);
    }

    public function deleteOrderOnAppAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $userId = $this->di->getUser()->id;
        //        $rawBody['source_order_ids'] = ['495-6895600-4023887'];
        //        $userId = '6065cd371c4d2633c2009b94';
        if (isset($rawBody['source_order_ids']) && !empty($rawBody['source_order_ids'])) {
            foreach ($rawBody['source_order_ids'] as $amazonOrderId) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollection(\App\Amazon\Components\Common\Helper::ORDER_CONTAINER);
                $orderData = $collection->deleteOne(['source_order_id' => (string)$amazonOrderId]);
                $this->di->getLog()->logContent('Order Id deleted : ' . $amazonOrderId, 'info', $userId . '/order-delete.log');
            }
        }
        $response = ['success' => true, 'message' => 'Amazon Order(s) deleted Successfully'];
        return $this->prepareResponse($response);
    }

    public function importOrderFromAmazonAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\Helper')
            ->fetchOrdersByShopId($rawBody);

        return $this->prepareResponse($response);
    }

    public function syncOrderOnAmazonAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if (isset($rawBody['source_order_ids']) && !empty($rawBody['source_order_ids'])) {
            foreach ($rawBody['source_order_ids'] as $amazonOrderId) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollection(\App\Amazon\Components\Common\Helper::ORDER_CONTAINER);
                $orderData = $collection->findOne(['source_order_id' => $amazonOrderId]);
                if ($orderData && count($orderData)) {
                    $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\Helper')
                        ->fetchOrders(['shop_id' => $orderData['shop_id'], 'order_id' => $amazonOrderId]);
                }
            }
        }
        $response = ['success' => true, 'message' => 'Amazon Order(s) synced Successfully'];
        return $this->prepareResponse($response);
    }

    public function uploadProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody['product_ids']) && !empty($rawBody['product_ids'])) {

            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
                ->uploadProduct(['source_product_ids' => $rawBody['product_ids']], \App\Amazon\Components\Product\Product::OPERATION_TYPE_UPDATE);
        } else {
            $response = ['success' => false, 'message' => 'Product Ids not found.'];
        }
        $response['grid_refresh'] = true;
        return $this->prepareResponse($response);
    }

    public function syncProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody['product_ids']) && !empty($rawBody['product_ids'])) {
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
                ->uploadProduct(['source_product_ids' => $rawBody['product_ids']], \App\Amazon\Components\Product\Product::OPERATION_TYPE_PARTIAL_UPDATE);
        } else {
            $response = ['success' => false, 'message' => 'Product Ids not found.'];
        }

        $response['grid_refresh'] = true;
        return $this->prepareResponse($response);
    }

    /*public function inventoryUploadAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            //  $sourceProductId = ['36916246577311', '36916246610079', '36916246642847', '5850128973983'];
            // $rawBody['product_ids'] = ['5849960906911'];
            if (isset($rawBody['product_ids']) && !empty($rawBody['product_ids'])) {
                $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
                    ->uploadInventory(['source_product_ids' => $rawBody['product_ids']]);
                // $response = ['success' => true, 'message' => 'Inventory Submitted Successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Product Ids not found.'];
            }
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
        }

        $response['grid_refresh'] = true;
        return $this->prepareResponse($response);
    }*/

    /*public function priceUploadAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            //  $sourceProductId = ['36916246577311', '36916246610079', '36916246642847', '5850128973983'];
            if (isset($rawBody['product_ids']) && !empty($rawBody['product_ids'])) {
                $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
                    ->uploadPrice(['source_product_ids' => $rawBody['product_ids']]);
                //  $response = ['success' => true, 'message' => 'Price Submitted Successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Product Ids not found.'];
            }
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
        }

        $response['grid_refresh'] = true;
        return $this->prepareResponse($response);
    }*/

    /*public function imageUploadAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody['product_ids']) && !empty($rawBody['product_ids'])) {
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
                ->uploadImage(['source_product_ids' => $rawBody['product_ids']]);
        } else {
            $response = ['success' => false, 'message' => 'Product Ids not found.'];
        }
        $response['grid_refresh'] = true;
        return $this->prepareResponse($response);
    }*/

    /*public function deleteProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody['product_ids']) && !empty($rawBody['product_ids'])) {
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
                ->deleteProduct(['source_product_ids' => $rawBody['product_ids']], \App\Amazon\Components\Product\Product::OPERATION_TYPE_DELETE);
        } else {
            $response = ['success' => false, 'message' => 'Product Ids not found.'];
        }
        $response['grid_refresh'] = true;
        return $this->prepareResponse($response);
    }*/

    public function getAllFeedsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Feed\Helper')
            ->getAllFeeds($rawBody);
        return $this->prepareResponse($response);
    }

    public function feedResponseViewAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Feed\Helper')
            ->getFeedResponseView($rawBody);
        return $this->prepareResponse($response);
    }

    public function syncFeedAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Feed\Helper')
            ->syncFeed($rawBody);
        return $this->prepareResponse($response);
    }

    public function deleteFeedAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Feed\Helper')
            ->deleteFeed($rawBody);
        return $this->prepareResponse($response);
    }

    public function saveproductadditionalAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')
            ->updateProfiledataMarketplacetable(["source_product_id" => $rawBody['source_product_id']], ['$set' => $rawBody], ['upsert' => true]);
        return $this->prepareResponse(["success" => true, "message" => "Additional details saved"]);
    }

    public function authenticateAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            $backendUrl = $this->di->getConfig()->backend_base_url;
            $sAppId = $this->di->getConfig()->apiconnector->amazon->default->get('sub_app_id');
            $region = $rawBody['region'];
            $accountType = $rawBody['account_type'];
            $sandbox = false;
            if ($accountType == 'sandbox') {
                $sandbox = true;
            }
            $marketplaceId = $rawBody['marketplace_id'];

            $userId = $this->di->getUser()->id;
            $url = $backendUrl . 'apiconnect/request/auth/?sAppId=' .
                $sAppId . '&region=' . $region . '&version=new&sandbox=' . $sandbox . '&marketplace_id=' . $marketplaceId . '&state=' . $userId;
            $response = ['success' => true, 'data' => $url];
        } catch (\Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }
        return $this->prepareResponse($response);
    }

    public function getAmazonSellerNameByIdAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper')->getAmazonSellerNameById($rawBody);
        } catch (\Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function saveMwsAuthTokenAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            //            $rawBody['mws_auth_token'] = 'amzn.mws.4cbc2554-8e71-e56c-68a0-e5fbc3d14de8';
            //            $rawBody['shop_id'] = 88;
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper')->saveMwsAuthToken($rawBody);
        } catch (\Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    /*To Migrate the Data - One Time Utilisation of this function*/
    public function manualMapMigration()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper')
            ->manualMapMigration($rawBody);
        return $this->prepareResponse($response);
    }

    /*Check for Unshipped orders from Amazon to App - One Time Utilisation of this function*/
    public function ShippedOrderFixes()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper')
            ->ShippedOrderFixes($rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * This function is used to sync product from Amazon
     * @return mixed
     */
    public function matchProductAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            $data = [];
            if (isset($rawBody['source_marketplace'])) {
                $data['source_marketplace'] = $rawBody['source_marketplace'];
            }
            if (isset($rawBody['target_marketplace'])) {
                $data['target_marketplace'] = $rawBody['target_marketplace'];
            }
            if (isset($rawBody['matchWith'])) {
                $data['matchWith'] = $rawBody['matchWith'];
            }

            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Report\Helper')
                ->requestReport($data);
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage() . $e->getTraceAsString()];
        }
        return $this->prepareResponse($response);
    }

    /**
     * This function is used to check product exists on Amazon
     * Product Look Up process
     * @return mixed
     */
    public function searchOnAmazonAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $data = [];

            if (isset($rawBody['source_marketplace'])) {
                $data['source_marketplace'] = $rawBody['source_marketplace'];
            }
            if (isset($rawBody['target_marketplace'])) {
                $data['target_marketplace'] = $rawBody['target_marketplace'];
            }
            if (isset($rawBody['source_product_id'])) {
                $data['source_product_ids'] = $rawBody['source_product_id'];
            }
            if (isset($rawBody['container_id'])) {
                $data['container_ids'] = $rawBody['container_id'];
            }
            if (isset($rawBody['source_shop_id'])) {
                $data['source_shop_id'] = $rawBody['source_shop_id'];
            }
            if (isset($rawBody['target_shop_id'])) {
                $data['target_shop_id'] = $rawBody['target_shop_id'];
            }
            if (isset($rawBody['user_id'])) {
                $data['user_id'] = $rawBody['user_id'];
            }
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\LookUp')
                ->init($data)->initiateLookup($data);
            // $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
            //     ->searchFromAmazon($data);

        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage() . $e->getTraceAsString()];
        }
        return $this->prepareResponse($response);
    }

    public function multiOfferLookupAction(){
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            if (isset($rawBody['source_marketplace'])) {
                $data['source_marketplace'] = $rawBody['source_marketplace'];
            }
            if (isset($rawBody['target_marketplace'])) {
                $data['target_marketplace'] = $rawBody['target_marketplace'];
            }
            if (isset($rawBody['source_product_id'])) {
                $data['source_product_id'] = $rawBody['source_product_id'];
            }else{
                return ['success' => false, 'message' => 'Source product id is required'];
            }
            if (isset($rawBody['source_marketplace']['source_shop_id'])) {
                $data['source_shop_id'] = $rawBody['source_marketplace']['source_shop_id'];
            }
            if (isset($rawBody['target_marketplace']['target_shop_id'])) {
                $data['target_shop_id'] = $rawBody['target_marketplace']['target_shop_id'];
            }
            if (isset($rawBody['user_id']) && !empty($rawBody['user_id'])) {
                $data['user_id'] = $rawBody['user_id'] ?? $this->di->getUser()->id;
            }

            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Lookup')->init($data)->initiateMultiOfferLookup($data);
            return $this->prepareResponse($response);
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage() . $e->getTraceAsString()];
        }
    }

    public function getLookupDataAction(){
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            
            // Prepare data from rawBody
            if (isset($rawBody['source_marketplace'])) {
                $data['source_marketplace'] = $rawBody['source_marketplace'];
            }
            if (isset($rawBody['target_marketplace'])) {
                $data['target_marketplace'] = $rawBody['target_marketplace'];
            }
            if (isset($rawBody['source_product_id']) && !empty($rawBody['source_product_id'])) {
                $data['source_product_id'] = $rawBody['source_product_id'];
            } else {
                return ['success' => false, 'message' => 'Source product id is required'];
            }
            if (isset($rawBody['asin']) && !empty($rawBody['asin'])) {
                $data['asin'] = $rawBody['asin'];
            } else {
                return ['success' => false, 'message' => 'Asin is required'];
            }
            if (isset($rawBody['source_marketplace']['source_shop_id']) && !empty($rawBody['source_marketplace']['source_shop_id'])) {
                $data['source_shop_id'] = $rawBody['source_marketplace']['source_shop_id'];
            } else {
                return ['success' => false, 'message' => 'Source shop id is required'];
            }
            if (isset($rawBody['target_marketplace']['target_shop_id']) && !empty($rawBody['target_marketplace']['target_shop_id'])) {
                $data['target_shop_id'] = $rawBody['target_marketplace']['target_shop_id'];
            } else {
                return ['success' => false, 'message' => 'Target shop id is required'];
            }
            if (isset($rawBody['user_id']) && !empty($rawBody['user_id'])) {
                $data['user_id'] = $rawBody['user_id'];
            }
            // Call getLookupData
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Lookup')
                ->init($data)
                ->getLookupData($data);
            return $this->prepareResponse($response);
        } catch (\Exception $e) {
            $this->di->getLog()->logContent('getLookupDataAction : ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true), 'info', 'exception.log');
        }
    }

    /**
     * This function is used to get Amazon Category
     *
     * @return mixed
     */
    public function getCategoryAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            $templateHelper = $this->di->getObjectManager()
                ->get('\App\Amazon\Components\Template\Helper');
            $response = $templateHelper->getCategory($rawBody);
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }
        return $this->prepareResponse($response);
    }

    /**
     * This function is used to check product already exist on Amazon Seller Center
     *
     * @return mixed
     */
    public function amazonLookUpAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            if (isset($rawBody['container_id'])) {
                $data = [];
                if (isset($rawBody['source_marketplace'])) {
                    $data['source_marketplace'] = $rawBody['source_marketplace'];
                }
                if (isset($rawBody['target_marketplace'])) {
                    $data['target_marketplace'] = $rawBody['target_marketplace'];
                }

                foreach ($rawBody['container_id'] as $containerId => $sourceProductIds) {
                    $data['container_ids'][] = $containerId;
                    if (isset($data['source_product_ids'])) {
                        $data['source_product_ids'] = array_merge($data['source_product_ids'], $sourceProductIds);
                    } else {
                        $data['source_product_ids'] = $sourceProductIds;
                    }
                }
                $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')
                    ->searchOnAmazon($data);
            } else {
                $response = ['success' => false, 'message' => 'Container Id not found'];
            }
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage() . $e->getTraceAsString()];
        }
        return $this->prepareResponse($response);
    }

    /**
     * This function is used to sync price on Amazon.
     *
     * @return mixed
     */
    public function updatePriceAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $data = [];

            if (isset($rawBody['source_marketplace'])) {
                $data['source_marketplace'] = $rawBody['source_marketplace'];
            }
            if (isset($rawBody['target_marketplace'])) {
                $data['target_marketplace'] = $rawBody['target_marketplace'];
            }
            if (isset($rawBody['source_product_id'])) {
                $data['source_product_ids'] = $rawBody['source_product_id'];
            }
            if (isset($rawBody['container_id'])) {
                $data['container_ids'] = $rawBody['container_id'];
            }

            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
                ->updatePrice($data);

        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage() . $e->getTraceAsString()];
        }
        return $this->prepareResponse($response);
    }

    /**
     * This function is used to sync inventory on Amazon
     *
     * @return mixed
     */
    public function updateInventoryAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $data = [];

            if (isset($rawBody['source'])) {
                $data['source_marketplace']['marketplace'] = $rawBody['source'];
                $data['source_marketplace']['shop_id']= $rawBody['source_shop_id'];
            }
            if (isset($rawBody['target'])) {
                $data['target_marketplace']['marketplace'] = $rawBody['target'];
                $data['target_marketplace']['shop_id']= $rawBody['target_shop_id'];
            }
            if (isset($rawBody['source_product_id'])) {
                $data['source_product_ids'] = $rawBody['source_product_id'];
            }
            if (isset($rawBody['container_id'])) {
                $data['container_ids'] = $rawBody['container_id'];
            }
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
                ->updateInventory($data);

        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage() . $e->getTraceAsString()];
        }
        return $this->prepareResponse($response);
    }

    /**
     * This function is used to sync image on Amazon
     *
     * @return mixed
     */
    public function updateImageAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $data = [];

            if (isset($rawBody['source_marketplace'])) {
                $data['source_marketplace'] = $rawBody['source_marketplace'];
            }
            if (isset($rawBody['target_marketplace'])) {
                $data['target_marketplace'] = $rawBody['target_marketplace'];
            }
            if (isset($rawBody['source_product_id'])) {
                $data['source_product_ids'] = $rawBody['source_product_id'];
            }
            if (isset($rawBody['container_id'])) {
                $data['container_ids'] = $rawBody['container_id'];
            }

            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
                ->updateImage($data);

        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage() . $e->getTraceAsString()];
        }
        return $this->prepareResponse($response);
    }

    /**
     * This function is used to delete the product from Amazon
     *
     * @return mixed
     */
    public function deleteProductAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $data = [];

            if (isset($rawBody['source_marketplace'])) {
                $data['source_marketplace'] = $rawBody['source_marketplace'];
            }
            if (isset($rawBody['target_marketplace'])) {
                $data['target_marketplace'] = $rawBody['target_marketplace'];
            }
            if (isset($rawBody['source_product_id'])) {
                $data['source_product_ids'] = $rawBody['source_product_id'];
            }
            if (isset($rawBody['container_id'])) {
                $data['container_ids'] = $rawBody['container_id'];
            }

            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')
                ->deleteProduct($data);

        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage() . $e->getTraceAsString()];
        }
        return $this->prepareResponse($response);
    }

    public function getBucketDataAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            if(isset($rawBody['key'])){
                $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Feed\Helper')
                    ->getBucketData($rawBody);
            }
            else{
                $response = ['success' => false, 'message' => 'key is missing'];

            }
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        return $this->prepareResponse($response);

    }

    public function removeErrorTagAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if ($rawBody['data']['Password'] == "donotremoveError") {
            $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog( "Remove Error Tag" , "Error Tag(s) removed." ,$rawBody ?? "N/A");
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Action')
                ->removeErrorTag($rawBody);

            return $this->prepareResponse($response);
        } else {
            $msg = ['success' => false, 'message' => 'Password Incorrect'];
            return $this->prepareResponse($msg);
        }
    }

    public function removeStatusAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if ($rawBody['data']['Password'] == "donotremoveStatus") {
        $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog( "Remove Status" , "Status(s) removed." ,$rawBody  ?? 'N/A');
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Action')
                ->removeStatus($rawBody);
            return $this->prepareResponse($response);
        } else {
            $msg = ['success' => false, 'message' => 'Password Incorrect'];
            return $this->prepareResponse($msg);
        }
    }
    public function removeProcessTagAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if ($rawBody['data']['Password'] == "donotremoveProcess") {
        $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog( "Remove Process Tag" , "Process Tag(s) removed." ,$rawBody ?? "N/A" );
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Action')
                ->removeProcessTag($rawBody);
            return $this->prepareResponse($response);
        } else {
            $msg = ['success' => false, 'message' => 'Password Incorrect'];
            return $this->prepareResponse($msg);
        }
    }
    public function removeAsinAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if ($rawBody['data']['Password'] == "donotremoveAsin") {
        $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog( "Remove ASIN(s)" , "ASIN remove process started." ,$rawBody ?? 'N/A');
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Action')
                ->removeAsin($rawBody);
            return $this->prepareResponse($response);
        } else {
            $msg = ['success' => false, 'message' => 'Password Incorrect'];
            return $this->prepareResponse($msg);
        }
    }
    public function removeProductFromListingAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if ($rawBody['data']['Password'] == "donotremoveProductFromAmazonListing") {
        $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog( "Remove Product from Listings" , "Remove from listing started!" ,$rawBody ?? 'N/A');

            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Action')
                ->removeProductFromAmazonListing($rawBody);
            return $this->prepareResponse($response);
        } else {
            $msg = ['success' => false, 'message' => 'Password Incorrect'];
            return $this->prepareResponse($msg);
        }
    }

    public function removeProductFromMatchingAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if ($rawBody['data']['Password'] == "donotremoveProductFromMatching") {
        $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog( "Remove Product from Amazon Matching" , "Remove from amazon match started!" ,$rawBody ?? 'N/A' );
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Action')
                ->removeProductFromMatching($rawBody);
            return $this->prepareResponse($response);
        } else {
            $msg = ['success' => false, 'message' => 'Password Incorrect'];
            return $this->prepareResponse($msg);
        }
    }

    public function saveChecklistAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if (isset($rawBody['data'])) {
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper')
                ->saveChecklist($rawBody);
            return $this->prepareResponse($response);
        } else {
            $msg = ['success' => false, 'message' => 'data not found'];
            return $this->prepareResponse($msg);
        }
    }

    public function notifyAmazonSellersAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response =  $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper')
            ->notifyAmazonSellers($rawBody);
        return $this->prepareResponse($response);
    }
    public function removeQueuedTaskAction(){

        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if ($rawBody['data']['Password'] == "amazon_product_report" || $rawBody['data']['Password'] == "amazon_product_search" || $rawBody['data']['Password'] == "amazon_product_image") {
            $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Action')
                ->removeQueuedTask($rawBody);
            return $this->prepareResponse($response);
        } else {
            $msg = ['success' => false, 'message' => 'Password Incorrect'];
            return $this->prepareResponse($msg);
        }
        $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog( "Remove Queued task" , "Remove Queued task" ,$rawBody ?? 'N/A' );
    }

    public function getConnectedSourceAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')->getConnectedSource($rawBody);
        return $this->prepareResponse($response);
    }

    public function getDataFromDynamoAction()
    {
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper')
            ->getDataFromDynamo();
        return $this->prepareResponse($response);
    }

    public function deleteDataFromDynamoAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper')
            ->deleteDataFromDynamo($rawBody);
        return $this->prepareResponse($response);
    }

    public function DeleteProductFromAmazonAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper')
            ->DeleteProductFromAmazon($rawBody);
        return $this->prepareResponse($response);
    }

    public function CheckDataOnAmazonAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper')
            ->CheckDataOnAmazon($rawBody);
        return $this->prepareResponse($response);
    }

    public function updateStatusAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Report\Helper')
            ->removeDeleteStatus($rawBody);
        return $this->prepareResponse($response);
    }

    public function createAmazonWebhooksAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Manual\Helper')
            ->createAmazonWebhooks($rawBody);
        return $this->prepareResponse($response);
    }

    public function registerAllUsersWebhookAction()
    {
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Manual\Helper')
            ->registerAllUsersWebhook();
        return $this->prepareResponse($response);
    }
    public function checkForAlredyInstalledShopAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\ReverseAuth')
            ->CheckForInstallation($rawBody);
        return $this->prepareResponse($response);

    }

    public function addNewAttributeAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper')
        ->addNewAttribute($rawBody);
        return $this->prepareResponse($response);
    }

    public function isSellerAlreadySellingAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = (strpos($contentType, 'application/json') !== false)
            ? $this->request->getJsonRawBody(true)
            : $this->request->get();

        $isAlreadySellingResponse = $this->di->getObjectManager()
            ->get('\App\Amazon\Components\Common\Helper')
            ->isSellerAlreadySelling($rawBody);
        return $this->prepareResponse($isAlreadySellingResponse);
    }

    public function getEncodedTokenAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $helper = $this->di->getObjectManager()->get('App\Apiconnect\Components\Authenticate\Common');

        $token=$helper->getStateToken($rawBody['data']);
        return $this->prepareResponse(['success'=>true,'token'=>$token]);

    }

    public function syncFbaUserstoDynamoAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Common\Helper')
        ->syncFbaUserstoDynamo($rawBody);
        return $this->prepareResponse($response);
    }

    public function registerAmazonWebhooksByCodeAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Manual\Helper')
            ->registerAmazonWebhooksByCode($rawBody);
        return $this->prepareResponse($response);
    }

    public function updateSellerAttributesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = strpos($contentType, 'application/json') !== false
            ?  $rawBody = $this->request->getJsonRawBody(true)
            :  $rawBody = $this->request->get();
        $response = $this->di->getObjectManager()
            ->get('App\Amazon\Components\ConfigurationEvent')
            ->addSellerWiseProductTypeAttributes($rawBody);
        return $this->prepareResponse($response);
    }

    public function getWebhookLogsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Common\Helper')
            ->extractLogLine($rawBody['file_path'],$rawBody['search_text'], $rawBody['line_number']);
        return $this->prepareResponse(['success' => true, 'data' => $response]);
    }

    public function validateAmazonAccountStatusAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Shop\Hook')
        ->validateAmazonAccountStatus($rawBody);
        return $this->prepareResponse($response);
    }

    public function fetchSingleOrderDataAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Order\Order')
        ->fetchSingleOrderData($rawBody);
        return $this->prepareResponse($response);
    }

    public function syncCancelOrderAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Order\OrderNotifications')
        ->syncCancelOrder($rawBody);
        return $this->prepareResponse($response);
    }

    public function syncUserToDynamoAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $shop = $rawBody['shop'];
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\ShopEvent')->syncUserToDynamo($shop);
        return $this->prepareResponse($response);
    }

    public function getArchiveOrdersAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\ArchiveOrder')->getArchiveOrders($rawBody);
        return $this->prepareResponse($response);
    }

    public function getArchiveOrderDataAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\ArchiveOrder')->getArchiveOrderData($rawBody);
        return $this->prepareResponse($response);
    }

    public function moveOrderInArchiveCollectionAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\ArchiveOrder')->moveOrderInArchiveCollection($rawBody);
        return $this->prepareResponse($response);
    }

    public function moveOrderDataInArchiveCollectionBulkAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\ArchiveOrder')->moveOrderDataInArchiveCollectionBulk($rawBody);
        return $this->prepareResponse($response);
    }

    public function exportCSVAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $getParams = $this->request->getJsonRawBody(true);
        } else {
            $getParams = $this->request->get();
        }

        $locale =  $this->request->getHeader('locale') ?? 'en';
        $getRequester = $this->di->getRequester();
        $userId = $this->di->getUser()->id;

        $shop_id =  $getRequester->getSourceId();
        $targetId=$getRequester->getTargetId();
        $containerModel = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');
        $dataCount = $containerModel->getProductsCount(['user_id' => $userId, 'shop_id' => $shop_id]);
        $countOfProducts = $dataCount['data']['count'] ?? '';
        if ($countOfProducts == 0) {
            // these conditions are just for handling and removing the entries from db if user meets this condition and previoulsy he has entries in these collections
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $csvProductCollection = $mongo->getCollection("csv_export_product_container");

            $csvProductCollection->deleteMany(
                [
                    'user_id'                   => $userId,
                    'source_shop_id'            => $shop_id,
                    'target_shop_id'            =>  $targetId
                ]
            );

            $configollection = $mongo->getCollection("config");

            $configollection->deleteMany(
                [
                    'user_id'           => $userId,
                    'source_shop_id'    => $shop_id,
                    'target_shop_id'    =>  $targetId,
                    'group_code'        => 'csv_product',
                ]
            );

            $queuedTasksColl = $mongo->getCollection('queued_tasks');
            $queuedTasksColl->deleteMany(
                [
                    'user_id'           => $userId,
                    'shop_id'           => $shop_id,
                    'target_shop_id'    =>  $targetId,
                    'process_code'      => 'csv_product',
                ]
            );
            return $this->prepareResponse(
                [
                    'success' => false,
                    'message' => '0 products found for exporting.'
                ]
            );
        }

        $csvHelp=$this->di->getObjectManager()->get('\App\Amazon\Components\Csv\Helper');
        $validate = $csvHelp->validateExportProcess($getParams);

        if ($validate['success']) {
            $exportType = $getParams['type'] ?? 'all';
            $filterSelected = $getParams['dataToExport'] ?? ['title', 'barcode', 'brand', 'vendor', 'price', 'quantity'];
            $getParams['language'] = $locale;
            $getParams['filter_columns'] = $filterSelected;
            $getParams['export_type'] = $exportType;
            $getParams['count'] = $getParams['limit'] ?? self::CSV_EXPORT_CHUNK_SIZE;
            $csvHelp->insertionInConfigForExport($getParams);
            $csvHelper = $this->di->getObjectManager()->get('\App\Connector\Components\CsvHelper');

            $config = $this->di->getConfig();
            $bucketForS3Upload = $config->get("bulk_update_through_s3");
            if ($bucketForS3Upload) {
                $returnArr = $csvHelp->getProductDetails_S3($getParams);
            }

            return $this->prepareResponse(
                [
                    'data' => $returnArr,
                    'success' => true,
                    'message' => $this->di->getLocale()->_('csv_export_in_progress')
                ]
            );
        }
        return $this->prepareResponse($validate);
    }

    public function getPoisonMessageDataAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper')->getPoisonMessageData($rawBody['aggregate']);
        return $this->prepareResponse($response);
    }

    public function deleteAmazonWebhooksAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Manual\Helper')
            ->deleteAmazonWebhooks($rawBody);
        return $this->prepareResponse($response);
    }

    public function moveShopToPriorityInOrderSyncTableAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Shop\Hook')
            ->moveShopToPriorityInOrderSyncTable($rawBody);
        return $this->prepareResponse($response);
    }

    public function fetchPriorityUsersFromSyncTableAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Shop\Hook')
            ->fetchPriorityUsersFromSyncTable($rawBody);
        return $this->prepareResponse($response);
    }

    public function unmarkShopAsPriorityAndEnableNormalSyncAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Shop\Hook')
            ->unmarkShopAsPriorityAndEnableNormalSync($rawBody);
        return $this->prepareResponse($response);
    }

    public function enableServerlessReportFetchForAmazonShopAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Report\Helper')
            ->enableServerlessReportFetchForAmazonShop($rawBody);
        return $this->prepareResponse($response);
    }

    public function createCustomDestinationAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Manual\Helper')
            ->createCustomDestination($rawBody);
        return $this->prepareResponse($response);
    }

    public function createCustomSubscriptionAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Manual\Helper')
            ->createCustomSubscription($rawBody);
        return $this->prepareResponse($response);
    }

    public function preventAccountDisconnectionAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Manual\Helper')
            ->preventAccountDisconnection($rawBody);
        return $this->prepareResponse($response);
    }

    public function getInactiveAccountsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Manual\Helper')
            ->getInactiveAccounts($rawBody);
        return $this->prepareResponse($response);
    }

    public function fetchSingleOrderDataV2Action()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get('App\Amazon\Components\Order\Order')
        ->fetchSingleOrderDataV2($rawBody);
        return $this->prepareResponse($response);
    }
}
