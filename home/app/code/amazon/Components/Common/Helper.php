<?php

namespace App\Amazon\Components\Common;

use App\Amazon\Components\Listings\ProcessResponse;
use App\Amazon\Components\Report\Report;
use App\Amazon\Components\Feed\Feed;
use App\Core\Models\User;
use App\Amazon\Components\Product\Product;
use App\Amazon\Components\ProductHelper;
use App\Amazon\Components\Order\OrderNotifications;
use App\Amazon\Components\Listings\ListingHelper;
use App\Core\Models\BaseMongo;
use App\Amazon\Components\ValueMapping;
use App\Amazon\Models\SourceModel;
use App\Amazon\Components\Sales\Sales;
use App\Amazon\Components\Order\Order;
use MongoDB\BSON\ObjectId;
use App\Connector\Components\ApiClient;
use App\Core\Components\Base as Base;
use App\Connector\Components\Dynamo;
use Exception;
use Phalcon\Events\Event;
use Aws\DynamoDb\Marshaler;
use App\Connector\Components\Helper as ConnectorHelper;

class Helper extends Base {
    //    const AMAZON_PRODUCT_CONTAINER = 'amazon_product_container';
    public const PRODUCT_CONTAINER = 'product_container';

    public const Refine_Product = 'refine_product';

    public const PROFILES = 'profiles';

    public const PROFILE_SETTINGS = 'profile_settings';

    public const CONFIGURATION = 'configuration';

    public const FEED_CONTAINER = 'feed_container';

    public const ORDER_CONTAINER = 'order_container';

    public const REPORT_CONTAINER = 'report_container';

    public const AMAZON_LISTING = 'amazon_listing';

    public const USER_DETAILS = 'user_details';

    public const AMAZON_CRON_TASKS = 'amazon_cron_tasks';

    public const QUEUED_TASKS = 'queued_tasks';

    public const MERCHANT_PROCESSES = 'amazon_merchant_processes';

    public const MATCH_WITH_TITLE = 'title';

    public const MATCH_WITH_BARCODE = 'barcode';

    public const MATCH_WITH_SKU = 'sku';

    public const MATCH_WITH_SKU_AND_BARCODE = 'sku,barcode';

    public const Feed_Status = 'feedStatus';

    public const Account_Status = 'accountStatus';

    public const PROFILE = 'profile';

    public const ACTIVITY_TYPE_ACCOUNT_CONNECTION = 'account_connection';

    public const ACTIVITY_TYPE_ACCOUNT_DISCONNECTION = 'account_disconnection';

    public const ACTIVITY_TYPE_SHOP_DATA_ERASE = 'shop_data_erase';

    public const ACTIVITY_TYPE_ACCOUNT_RECONNECTION = 'account_reconnection';

    public const Value_Mapping = 'amazon_value_mapping';

    public const Local_Selling = 'amazon_local_stores';

    public const INITIAL_STATUS = 'Inactive';

    public const Amazon_Local_Selling_All_Stores = 'amazon_local_selling_stores';

    public const ACTIVE_STATUS = 'Active';

    public const AMAZON_APP_TAG = 'amazon_sales_channel';

    public const TARGET = 'amazon';

    public const CONFIG = 'config';

    public const SELLERPERFORMANCELISTING = 'seller_performance_list';

    public const STEP_COMPLETED = 'stepsCompleted';
    
    public const Global_Value_Mapping = 'Global_value_mapping';


    public const ONBOARDING_CHECKLIST = 'onboarding_checklist';

    public const Local_Delivery = 'amazon_local_delivery';

    public const Local_Delivery_Product = 'amazon_local_delivery_product_listing';

    public const Refine_Product_Local_Delivery = 'amazon_local_delivery_refine_product';


    public const RESPONSE_SUCCESS = 'SUCCESS';

    public const RESPONSE_REQUEUE = 'REQUEUE';

    public const RESPONSE_TERMINATE = 'TERMINATE';

    public const MAX_REMOTE_RETRY_ATTEMPTS = 3;

    public $retryAttempt = 0;

    public const AMAZON_REPORT_CONTAINER_USERS = 'amazon_report_container_users';

    //    const AMAZON_PRODUCT_STATUS_TEMP = 'amazon_product_status_temp';

    public const MARKETPLACE_CURRENCY = [
        'ATVPDKIKX0DER' => 'USD',
        'A2EUQ1WTGCTBG2' => 'CAD',
        'A1AM78C64UM0Y8' => 'MXN',
        'A1RKKUPIHCS9HS' => 'EUR',
        'A1F83G8C2ARO7P' => 'GBP',
        'A13V1IB3VIYZZH' => 'EUR',
        'A1PA6795UKMFR9' => 'EUR',
        'APJ6JRA9NG5V4' => 'EUR',
        'A2Q3Y263D00KWC' => 'BRL',
        'A2VIGQ35RCS4UG' => 'AED',
        'A21TJRUUN4KGV' => 'INR',
        'A1VC38T7YXB528' => 'JPY',
        'A39IBJ37TRP1C6' => 'AUD',
        'A19VAU5U5O7RUS' => 'SGD',
        'A1805IZSGTT6HS' => 'EUR',
        'A17E79C6D8DWNP' => 'SAR',
        'A2NODRKZP88ZB9' => 'SEK',
        'A1C3SOZRARQ6R3' => 'PLN',
        'AE08WJ6YKNBMC' => 'ZAR',
        'A33AVAJ2PDY3EV'=>  'TRY',
        'AMEN7PMS3EDWL' =>  'EUR',
        'ARBP9OOSHTCHU' => 'EGP',
        'A28R8C7NBKEWEA' => 'EUR'
    ];


    public const MARKETPLACE_NAME = [
        'ATVPDKIKX0DER' => 'United States of America',
        'A2EUQ1WTGCTBG2' => 'Canada',
        'A1AM78C64UM0Y8' => 'Mexico',
        'A1RKKUPIHCS9HS' => 'Spain',
        'A1F83G8C2ARO7P' => 'United Kingdom',
        'A13V1IB3VIYZZH' => 'France',
        'A1PA6795UKMFR9' => 'Germany',
        'APJ6JRA9NG5V4' => 'Italy',
        'A2Q3Y263D00KWC' => 'Brazil',
        'A2VIGQ35RCS4UG' => 'United Arab Emirates',
        'A21TJRUUN4KGV' => 'India',
        'A1VC38T7YXB528' => 'Japan',
        'A39IBJ37TRP1C6' => 'Australia',
        'A19VAU5U5O7RUS' => 'Singapore',
        'A1805IZSGTT6HS' => 'Netherlands',
        'A17E79C6D8DWNP' => 'Saudi Arabia',
        'A2NODRKZP88ZB9' => 'Sweden',
        'A1C3SOZRARQ6R3' => 'Poland',
        'AMEN7PMS3EDWL' => 'Belgium',
        'ARBP9OOSHTCHU' => 'Egypt',
        'A33AVAJ2PDY3EV' => 'Turkey',
        'AE08WJ6YKNBMC' => 'South Africa',
        'A28R8C7NBKEWEA'  => 'Ireland'

    ];

    public const MARKETPLACE_URL = [
        'ATVPDKIKX0DER' => 'https://www.amazon.com/',
        'A2EUQ1WTGCTBG2' => 'https://www.amazon.ca/',
        'A1AM78C64UM0Y8' => 'https://www.amazon.com.mx/',
        'A1RKKUPIHCS9HS' => 'https://www.amazon.es/',
        'A1F83G8C2ARO7P' => 'https://www.amazon.co.uk/',
        'A13V1IB3VIYZZH' => 'https://www.amazon.fr/',
        'A1PA6795UKMFR9' => 'https://www.amazon.de/',
        'APJ6JRA9NG5V4' => 'https://www.amazon.it/',
        'A2Q3Y263D00KWC' => 'https://www.amazon.com.br/',
        'A2VIGQ35RCS4UG' => 'https://www.amazon.ae/',
        'A21TJRUUN4KGV' => 'https://www.amazon.in/',
        'A1VC38T7YXB528' => 'https://www.amazon.co.jp/',
        'A39IBJ37TRP1C6' => 'https://www.amazon.com.au/',
        'A19VAU5U5O7RUS' => 'https://www.amazon.sg/',
        'A1805IZSGTT6HS' => 'https://www.amazon.nl/',
        'A17E79C6D8DWNP' => 'https://www.amazon.sa/',
        'A2NODRKZP88ZB9' => 'https://www.amazon.se/',
        'A1C3SOZRARQ6R3' => 'https://www.amazon.pl/',
        'AMEN7PMS3EDWL' => 'https://www.amazon.com.be/',
        'ARBP9OOSHTCHU' => 'https://www.amazon.eg/',
        'A33AVAJ2PDY3EV' => 'https://www.amazon.com.tr/',
        'AE08WJ6YKNBMC' => 'https://www.amazon.com.za/',
        'A28R8C7NBKEWEA' => 'https://www.amazon.ie/'
    ];

    public const JSON_MAPPING = [
        'sku' => 'Seller SKU (item_sku)',
        'brand' => 'Brand Name (brand_name)',
        'product_description' => 'Product Description (product_description)',
        'item_name' => 'Item Name (aka Title) (item_name)',
        'purchasable_offer/our_price/schedule' => 'Your price (standard_price)',
        'fulfillment_availability' => 'Quantity (quantity)',
        'main_product_image_locator' => 'Main Image URL (main_image_url)',
        'other_product_image_locator_0' => 'Other Image URL0 (other_image_url0)',
        'other_product_image_locator_1' => 'Other Image URL1 (other_image_url1)',
        'other_product_image_locator_2' => 'Other Image URL2 (other_image_url2)',
        'other_product_image_locator_3' => 'Other Image URL3 (other_image_url3)',
        'other_product_image_locator_4' => 'Other Image URL4 (other_image_url4)',
        'other_product_image_locator_5' => 'Other Image URL5 (other_image_url5)',
        'other_product_image_locator_6' => 'Other Image URL6 (other_image_url6)',
        'other_product_image_locator_7' => 'Other Image URL7 (other_image_url7)',
        'other_product_image_locator_8' => 'Other Image URL8 (other_image_url8)',
        'supplier_declared_has_product_identifier_exemption' => 'barcode_exemption',
        'external_product_id' => 'Product ID (external_product_id)',
        'category' => 'category',
        'sub_category' => 'sub_category'
    ];

    public const JSON_KEYS = [
        'sku' => 'item_sku',
        'brand' => 'brand_name',
        'product_description' => 'product_description',
        'item_name' => 'item_name',
        'purchasable_offer/our_price/schedule' => 'standard_price',
        'fulfillment_availability' => 'quantity',
        'main_product_image_locator' => 'main_image_url',
        'other_product_image_locator_0' => 'other_image_url1',
        'other_product_image_locator_1' => 'other_image_url2',
        'other_product_image_locator_2' => 'other_image_url3',
        'other_product_image_locator_3' => 'other_image_url4',
        'other_product_image_locator_4' => 'other_image_url5',
        'other_product_image_locator_5' => 'other_image_url6',
        'other_product_image_locator_6' => 'other_image_url7',
        'other_product_image_locator_7' => 'other_image_url8',
        'supplier_declared_has_product_identifier_exemption' => 'barcode_exemption',
        'external_product_id' => 'external_product_id',
        'product_type' => 'ProductType'
    ];

    public const INVENTORY_MESSAGE = 'Inventory Sync Initiated';

    public const UPLOAD_MESSAGE = 'Product Upload Initiated';

    public const SYNC_PRODUCT = 'Sync Product Initiated';

    public const IMAGE_MESSAGE = 'Image Sync Initiated';

    public const GROUP_MESSAGE = 'Group Action Initiated';

    public const PRICE_MESSAGE = 'Price Sync Initiated';

    public const DELETE_MESSAGE = 'Product Delete Initiated';

    public const INVENTORY_MESSAGE_FEED = 'Inventory Feed Submitted';

    public const GROUP_MESSAGE_FEED = 'Group Action Feed Submitted';


    public const UPLOAD_MESSAGE_FEED = 'Product Feed Submitted';

    public const SYNC_PRODUCT_FEED = 'Sync Product Feed Submitted';

    public const IMAGE_MESSAGE_FEED = 'Image Feed Submitted';

    public const PRICE_MESSAGE_FEED = 'Price Feed Submitted';

    public const DELETE_MESSAGE_FEED = 'Product Delete Feed Submitted';

    public const PROCESS_TAG_SEARCH_PRODUCT = 'Search product in progress';

    public const PROCESS_TAG_UPLOAD_PRODUCT = 'Upload product in progress';

    public const PROCESS_TAG_IMAGE_SYNC = 'Image sync in progress';

    public const PROCESS_TAG_PRICE_SYNC = 'Price sync in progress';

    public const PROCESS_TAG_INVENTORY_SYNC = 'Inventory sync in progress';

    public const PROCESS_TAG_GROUP_SYNC = 'Group Action in progress';

    public const PROCESS_TAG_DELETE_PRODUCT = 'Delete product in progress';

    public const PROCESS_TAG_SYNC_PRODUCT = 'Product update in progress';

    public const PROCESS_TAG_UPLOAD_FEED = 'Product Upload Feed Submitted Successfully';

    public const PROCESS_TAG_UPDATE_FEED = 'Product Update Feed Submitted Successfully';

    public const PROCESS_TAG_PRICE_FEED = 'Price Feed Submitted Successfully';

    public const PROCESS_TAG_GROUP_FEED = 'Group Action Feed Submitted Successfully';

    public const PROCESS_TAG_INVENTORY_FEED = 'Inventory Feed Submitted Successfully';

    public const PROCESS_TAG_IMAGE_FEED = 'Image Feed Submitted Successfully';

    public const PROCESS_TAG_DELETE_FEED = 'Product Delete Feed Submitted Successfully';

    public const PRODUCT_STATUS_UPLOADED = 'Submitted';

    public const PRODUCT_STATUS_AVAILABLE_FOR_OFFER = 'Available for Offer';

    public const PRODUCT_MATCH_STATE_STARTED = 'Started';

    public const PRODUCT_MATCH_STATE_REPORT_ID_GENERATED = 'ReportId Generated';

    public const PRODUCT_MATCH_STATE_REPORT_ID_REPORT_READ_DONE = 'Report Read Done';

    public const PRODUCT_MATCH_STATE_PARTIALLY_COMPLETED = 'Partially completed';

    public const PRODUCT_MATCH_STATE_COMPLETED = 'Completed';

    public const DEFAULT_PRICE_SETTING = [
        'settings_enabled' => true,
        'settings_selected' => [
                'sale_price' => false,
                'business_price' => false,
                'minimum_price' => false,
            ],
        'standard_price' => [
            'attribute' => 'default',
            'value' => 'default',
        ],
    ];

    public const DEFAULT_INVENTORY_SETTING = [
        "settings_enabled" => true,
        "settings_selected" => [
                "fixed_inventory" => false,
                "threshold_inventory" => false,
                "delete_out_of_stock" => false,
                "customize_inventory" => false,
                "inventory_fulfillment_latency" => false,
                "warehouses_settings" => false,
            ],
        "fixed_inventory" => "",
        "threshold_inventory" => "",
        "customize_inventory" => [
                "attribute" => "default",
                "value" => "",
            ],
    ];

    public const DEFAULT_ORDER_SETTING = [
        "shipment" => true,
        "tracking_number" => false,
    ];

    public const MANUAL_AUTHENTICATION_URLS = [
        'NA' => 'https://sellercentral.amazon.com/gp/mws/registration/register.html?signInPageDisplayed=1&devAuth=1&developerName=Cedcommerce+Inc&devMWSAccountId=337320726556&',
        'FE' => 'https://sellercentral.amazon.com.au/gp/mws/registration/register.html?signInPageDisplayed=1&devAuth=1&developerName=Cedcommerce+Inc&devMWSAccountId=048563819005&',
        'IN' => 'https://sellercentral.amazon.in/gp/mws/registration/register.html?signInPageDisplayed=1&devAuth=1&developerName=Cedcommerce+Inc&devMWSAccountId=163411718947&',
        'EU' => 'https://sellercentral-europe.amazon.com/gp/mws/registration/register.html?signInPageDisplayed=1&devAuth=1&developerName=Cedcommerce+Inc&devMWSAccountId=233623308975&',
    ];

    public function sendRequestToAmazon($path, $data, $type = 'GET', $dataType = null) {
        $response = $this->di->getObjectManager()->get(ApiClient::class)
            ->init('amazon', true, 'amazon')
            ->call($path, [], $data, $type, $dataType);
         if(isset($response['success'])  && !$response['success'] && isset($response['code']) && in_array($response['code'],['400,403']))
            {
                $this->BannerAddition($data,$response);
            }
        $this->validateRemoteErrors($path, $data, $type = 'GET', $dataType, $response);
        return $response;
    }

    private function validateRemoteErrors($path, $data, $type, $dataType, $response) {
        $logFile = 'amazon/validateRemoteErrors/'.date('Y-m-d').'.log';
        $message = $response['message'] ?? $response['msg'] ?? $response['error'] ?? '';
        if(!empty($message)) {
            if($this->retryAttempt < self::MAX_REMOTE_RETRY_ATTEMPTS) {
                if(is_string($message) && str_contains((string) $message, "cURL error")) {
                    $this->di->getLog()->logContent('Curl Error Retrying remote call again for user: ' . json_encode([
                    'user_id' => $this->di->getUser()->id,
                    'remote_response' => $response]), 'info', $logFile);
                    ++$this->retryAttempt;
                    sleep(1);
                    $this->sendRequestToAmazon($path, $data, $type, $dataType);
                }
            } else {
                $this->di->getLog()->logContent('Maximum retry attempt passed, lastData: ' . json_encode([
                    'user_id' => $this->di->getUser()->id,
                    'remote_response' => $response
                ]), 'info', $logFile);
            }
        }
    }

    public function BannerAddition($data,$response)
    {
       
       $userId = $data['user_id']??$this->di->getUser()->id;
       $remoteShopId = $data['shop_id']??"";
       $targetMarketplace = 'amazon';
       $shops = $this->di->getUser()->shops;
       
       $errorCode = $response['code']??"";
       $objectManager = $this->di->getObjectManager();
      
       $productComponent = $objectManager->get('\App\Amazon\Components\Product\Product');
      
       foreach($shops as $shop){
        if (
            $shop['remote_shop_id'] == $remoteShopId
            && $shop['marketplace'] == Helper::TARGET
            && $shop['apps'][0]['app_status'] == 'active'
        ){
            $targetShopId = $shop['_id'];
            foreach($shop['sources'] as $source){
                    
                    $sourceMarketplace = $source['code'];
                    $sourceShopId = $source['shop_id'];
                    $sourceAppCode = $source['app_code'];
                    $targetMarketplace = $shop['marketplace'];
                    $this->di->getAppCode()->setAppTag($sourceAppCode);
            }
        }
       }
       $configData = [
        'user_id' => $userId,
        'source' => $sourceMarketplace,
        'target' => $targetMarketplace,
        'source_shop_id' => $sourceShopId,
        'target_shop_id' => $targetShopId,
        'group_code' => 'feedStatus',
        'key' => 'accountStatus',
        'app_tag' => $this->di->getAppCode()->getAppTag() ?? 'default',
        'value' => 'expired'
    ];
    
    $productComponent->updateWarehouseStatus($userId, $targetShopId, 'inactive');
    $configObj = $objectManager->get(\App\Core\Models\Config\Config::class);
    $configObj->setUserId($userId);
    $configObj->setAppTag($configData['app_tag']);
    $configObj->setConfig([$configData]);

    }

    public function remotePermissionToSendRequest($path, $data, $type = "GET") {
        return $this->di->getObjectManager()->get(ApiClient::class)
            ->init('amazon', true, 'amazon')
            ->call($path, [], $data, $type);

    }

    public function getAmazonSellerNameById($amazonSellerId) {

        if(isset($amazonSellerId['amazon_seller_id'])) {
            if(isset($amazonSellerId['marketplace_id'], self::MARKETPLACE_URL[$amazonSellerId['marketplace_id']])) {
                $url = self::MARKETPLACE_URL[$amazonSellerId['marketplace_id']]."sp?seller={$amazonSellerId['amazon_seller_id']}";
            } else {
                $url = "https://www.amazon.com/sp?seller={$amazonSellerId['amazon_seller_id']}";
            }

            // $userAgent = 'Mozilla/5.0 (Windows NT 10.0)'
            //            . ' AppleWebKit/537.36 (KHTML, like Gecko)'
            //            . ' Chrome/48.0.2564.97'
            //            . ' Safari/537.36';
            // $headers = array('User-Agent' => $userAgent);

            $opts = ['http' => ['method' => "GET", 'header' => "Accept-language: en\r\n".
                "Cookie: foo=bar\r\n"]];
            $context = stream_context_create($opts);
            $data = file_get_contents($url, false, $context);

            // $data = file_get_contents($url);

            $name = $this->scrapSellerName($data);
            if($name) {
                return ['success' => true, 'data' => $name];
            }
            return ['success' => false, 'message' => 'Amazon Seller Id not found.'];
        }
        return ['success' => false, 'message' => 'amazon_seller_id is required.'];
    }

    private function scrapSellerName($page_html): string|false {
        $find = 'id="sellerName">';

        $start = strpos($page_html, $find);
        if($start !== false) {
            $end = strpos($page_html, '</h1>', $start);

            $name = substr($page_html, ($start + strlen($find)), ($end - ($start + strlen($find))));
            return $name;
        }
        return false;
    }

    public function saveMwsAuthToken($rawBody) {
        if(isset($rawBody['mws_auth_token'], $rawBody['shop_id'])) {
            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $remoteShopId = $user_details->getShop($rawBody['shop_id'], (string)$this->di->getUser()->id)['remote_shop_id'];
            //            $remoteShopId = $user_details->getShop($rawBody['shop_id'], '6065cd371c4d2633c2009b94')['remote_shop_id'];
            $params = [
                'user_id' => (string)$this->di->getUser()->id,
                'home_shop_id' => $rawBody['shop_id'],
                'shop_id' => $remoteShopId,
                'mws_auth_token' => $rawBody['mws_auth_token'],
            ];
            $response = $this->sendRequestToAmazon('amazon-token-save', $params, 'POST');

            if(isset($response['success']) && $response['success']) {
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $getShop = $user_details->getShop($rawBody['shop_id'], $this->di->getUser()->id);
                $getShop['warehouses'][0]['status'] = "active";
                $getShop['warehouses'][0]['token_generated'] = true;
                $user_details->addShop($getShop, $this->di->getUser()->id, ['_id']);
            }

            return $response;
        }
    }

    /**
     * Get all the amazon accounts connected by USER
     *
     * @param $userId
     * @return array
     */
    public function getAllAmazonShops($userId) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection(\App\Amazon\Components\Common\Helper::USER_DETAILS);
        $user_details = $collection->findOne(['user_id' => (string)$userId]);
        $shops = [];

        foreach($user_details['shops'] as $value) {
            if($value['marketplace'] === 'amazon') {
                $shops[] = $value;
            }
        }

        return $shops;
    }

    /**
     * Used only for logged in merchant.
     *
     * @return array
     */
    public function getAllCurrentAmazonShops() {
        $shops = [];
        $user_shops = $this->di->getUser()->shops;
        foreach($user_shops as $value) {
            if($value['marketplace'] === 'amazon') {
                $shops[] = $value;
            }
        }

        return $shops;
    }

    /**
     * process report by webhook
     *
     * @param [type] $data
     * @return void|array
     */
    public function webhookGetReport($data) {
        try {
            $notificationData = $data['data']['reportProcessingFinishedNotification'] ?? [];
            if(
                isset($data['user_id'],$data['shop_id'], $notificationData['reportId'], $notificationData['reportType'])
            ) {
                $userId = $data['user_id'];
                $reportId = (string)$notificationData['reportId'];
                $targetShopId = (string)$data['shop_id'];
                $logFile = 'webhookGetReport' . DS . $userId . DS . $targetShopId . DS . date('Y-m-d') . DS . 'success.log';
                $errorLogFile = 'webhookGetReport' . DS . $userId . DS . $targetShopId . DS . date('Y-m-d') . DS . 'error.log';
                $this->di->getLog()->logContent('received = ' . json_encode($data), 'info', $logFile);
                // $processWebhook indicates whether webhook's report id matched with any user's queued task or not
                $processedWebhook = false;
                switch ($notificationData['reportType']) {
                    case 'GET_MERCHANT_LISTINGS_ALL_DATA':
                        // $reportHelper = $this->di->getObjectManager()->get(Report::class)->init();
                        $syncStatus = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\SyncStatus::class);
                        //  get all queued tasks of sync status
                        $preparedData = [
                            'user_id' => $userId,
                            'data' => [
                                'home_shop_id' => $targetShopId,
                                'target_marketplace' => self::TARGET
                            ]
                        ];
                        $searchResponse = $syncStatus->init($preparedData)->getSyncStatusQueuedTask($userId);
                        if (!empty($searchResponse['additional_data']['report_id']) && ($searchResponse['additional_data']['report_id'] == $reportId)) {
                            // also check if webhook is received past 30 min, already-processed by fetch report
                            if (isset($searchResponse['progress'], $searchResponse['message']) && (($searchResponse['progress'] > 5)
                            || $searchResponse['message'] != 'Success! Sync Status Report request submitted on Amazon.')) {
                                $this->di->getLog()->logContent('skipped process = ' . json_encode($data), 'info', $logFile);
                                return ['success' => true, 'message' => 'Already processed by cron.'];
                            }
                            $processedWebhook = true;
                            $message = $syncStatus::ERROR_SYNC_STATUS;
                            $progress = 100;
                            // $targetShopId = $searchResponse['shop_id'];
                            $queuedTaskId = (string)$searchResponse['_id'];
                            if (isset($notificationData['processingStatus'], $notificationData['reportDocumentId']) && $notificationData['processingStatus'] == 'DONE') {
                                $reportDocumentId = $notificationData['reportDocumentId'];
                                $queuedTaskId = (string)$searchResponse['_id'];
                                $activeShop = $syncStatus->getActiveShop($targetShopId, $userId);
                                if (isset($activeShop['success'], $activeShop['data']) && !empty($activeShop['data'])) {
                                    $targetShop = $activeShop['data'];
                                    // $targetShop = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')->getShop($targetShopId, $userId);
                                    $remoteShopId = $targetShop['remote_shop_id'];
                                    //  report path where report will be saved for chunk-wise read
                                    $path = BP . DS . 'var' . DS . 'file' . DS . 'mfa' . DS . $userId . DS . $targetShopId . DS . 'report.tsv';
                                    $filePath = $this->di->getObjectManager()->get('\App\Amazon\Components\Report\Report')->fetchReportFromAmazon($remoteShopId, $notificationData['reportType'], $reportDocumentId, $path);
                                    if (is_string($filePath)) {
                                        $syncStatusData = [
                                            'user_id' => $userId,
                                            'remote_shop_id' => $remoteShopId,
                                            'target_shop_id' => $targetShopId,
                                            'target_marketplace' => $targetShop['marketplace'],
                                            'queued_task_id' => $queuedTaskId
                                        ];
                                        if (!empty($targetShop['sources'])) {
                                            foreach ($targetShop['sources'] as $sources) {
                                                if (isset($sources['shop_id'], $sources['code'])) {
                                                    $syncStatusData['source_shop_id'] = $sources['shop_id'];
                                                    $syncStatusData['source_marketplace'] = $sources['code'];
                                                    $sqsData = $syncStatus->prepareSqsData($syncStatus::IMPORT_REPORT_DATA_OPERATION, $syncStatusData);
                                                    if (isset($searchResponse['additional_data']['initiateImage']) && $searchResponse['additional_data']['initiateImage']) {
                                                        $sqsData['initiateImage'] = true;
                                                    }
                                                    if (isset($searchResponse['additional_data']['lookup']) && $searchResponse['additional_data']['lookup']) {
                                                        $sqsData['lookup'] = true;
                                                    }
                                                    if (isset($searchResponse['process_initiation'])) {
                                                        $sqsData['initiated'] = $searchResponse['process_initiation'];
                                                    }

                                                    $sqsData['data']['file_path'] = $filePath;
                                                    $sqsData['data']['skip_character'] = 0;
                                                    $sqsData['data']['chunk'] = 500;
                                                    $sqsData['data']['scanned'] = 0;
                                                    $this->di->getMessageManager()->pushMessage($sqsData);
                                                }

                                                $message = 'Success! Sync Status Report fetched from Amazon. Product Import currently in Progress.';
                                                $progress = $syncStatus::SYNC_STATUS_STEP_2_REPORT_FETCHNDOWNLOAD;
                                            }
                                        }
                                    } elseif(isset($filePath['success'], $filePath['throttle']) && !$filePath['success'] && $filePath['throttle']) {
                                        $sqsData = $data;
                                        $sqsData['delay'] = 60;
                                        $sqsData['handle_added'] =true;
                                        $this->di->getMessageManager()->pushMessage($sqsData);
                                        return;
                                    }
                                } else {
                                    $message = $syncStatus::SUCCESS_SYNC_STATUS;
                                }
                            } else {
                                //  report_status is either CANCELLED or FATAL
                                $message = $syncStatus::SUCCESS_SYNC_STATUS;
                            }
                            $syncStatus->updateProcess($queuedTaskId, $progress, $message);
                            break;
                        }
                        if (!$processedWebhook) {
                            //  webhook report_id does not matched with any user's pending process
                            $this->di->getLog()->logContent('queued task not found = ' . json_encode($data), 'info', $errorLogFile);
                        }
                        break;
                    case 'GET_V2_SELLER_PERFORMANCE_REPORT':
                        if(isset($notificationData['processingStatus']) && !empty($notificationData['reportDocumentId']) && $notificationData['processingStatus'] == 'DONE') {
                            $reportDocumentId = $notificationData['reportDocumentId'];
                            $targetShop = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')->getShop($targetShopId, $userId);
                            $remoteShopId = $targetShop['remote_shop_id'];
                            $reportHelper = $this->di->getObjectManager()->get(Report::class);
                            $response = $reportHelper->fetchReportFromAmazon($remoteShopId, $notificationData['reportType'], $reportDocumentId);
                            if (isset($response['response']) && !empty($response['response']) && is_string($response['response'])) {
                                $contents = json_decode($response['response'], true);
                                if (!empty($contents['performanceMetrics'][0])) {
                                    $updateQuery = ['user_id' => $userId,'shop_id' => $targetShopId];
                                    $updatedValue = $contents['performanceMetrics'][0];
                                    $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                                    $sellerPerformanceCollection = $baseMongo->getCollection(\App\Amazon\Components\Common\Helper::SELLERPERFORMANCELISTING);
                                    $sellerPerformanceCollection->updateOne($updateQuery, ['$set' => $updatedValue], ['upsert' => true]);
                                }
                            }
                        }

                        break;
                    case 'GET_AMAZON_FULFILLED_SHIPMENTS_DATA_GENERAL':
                        $fbaUsers = $this->di->getConfig()->get('fba_order_shipment_sync_users') ? $this->di->getConfig()->get('fba_order_shipment_sync_users')->toArray() : [];
                        if (!empty($fbaUsers) && in_array($userId, $fbaUsers)) {
                            if (isset($notificationData['processingStatus']) && !empty($notificationData['reportDocumentId']) && $notificationData['processingStatus'] == 'DONE') {
                                $logFile = "amazon/fba_order_shipment_sync/" . $userId . "/" . date('d-m-Y') . '.log';
                                $errorLogFile = "amazon/fba_order_shipment_sync/errors/" . date('d-m-Y') . '.log';
                                $reportDocumentId = $notificationData['reportDocumentId'];
                                $targetShop = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')->getShop($targetShopId, $userId);
                                $remoteShopId = $targetShop['remote_shop_id'];
                                if (!isset($targetShop['sources'][0]['code'])) {
                                    $this->di->getLog()->logContent('Unable to find source: ' . json_encode([
                                        'user_id' => $userId,
                                        'target_shop' => $targetShop
                                    ]), 'info', $errorLogFile);
                                    return ['success' => false, 'message' => 'Unable to find sources data'];
                                }
                                $path = '\Components\Order\Shipment';
                                $method = 'updateShipmentDetails';
                                $class = $this->checkClassAndMethodExists($targetShop['sources'][0]['code'], $path, $method);
                                if (empty($class)) {
                                    $this->di->getLog()->logContent('class or method not found' . json_encode([
                                        'user_id' => $userId
                                    ]), 'info', $errorLogFile);
                                    return ['success' => false, 'message' => 'class or method not found'];
                                }
                                $reportHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Report\Report');
                                $response = $reportHelper->fetchReportFromAmazon($remoteShopId, $notificationData['reportType'], $reportDocumentId);
                                if (isset($response['response']) && !empty($response['response'])) {
                                    $rows = explode("\n", $response['response']);
                                    $objects = $successIds = $errorIds = [];
                                    $headers = explode("\t", array_shift($rows));
                                    foreach ($rows as $row) {
                                        $values = explode("\t", $row);
                                        $rowObject = [];
                                        foreach ($headers as $index => $header) {
                                            $rowObject[$header] = $values[$index];
                                        }
                                        if (!isset($rowObject['amazon-order-id'])) {
                                            $this->di->getLog()->logContent("Invalid Row Data({$userId}): " . json_encode($rowObject), 'info', $errorLogFile);
                                            continue;
                                        }
                                        $objects[$rowObject['amazon-order-id']] = $rowObject;
                                    }
                                    $orderIds = array_keys($objects);
                                    $options = [
                                        'projection' => [
                                            'user_id' => 1, 'targets' => 1, 'marketplace_reference_id' => 1
                                        ],
                                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                                    ];
                                    $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                                    $orderCollection = $baseMongo->getCollection('order_container');
                                    $orderData = $orderCollection->find(['user_id' => $userId, 'object_type' => 'source_order', 'marketplace_reference_id' => ['$in' => $orderIds], 'targets.order_id' => ['$ne' => null], 'fba_shipment_synced' => null], $options)->toArray();
                                    foreach ($orderData as $data) {
                                        $orderId = $data['marketplace_reference_id'];
                                        if (isset($objects[$orderId]['carrier'], $objects[$orderId]['tracking-number'])) {
                                            $trackingDetails = [
                                                "company" => $objects[$orderId]['carrier'],
                                                "number" => $objects[$orderId]['tracking-number'],
                                                "url" => ""
                                            ];
                                            $response = $this->di->getObjectManager()->get($class)->$method($data['targets'][0]['order_id'], $trackingDetails);
                                            if (isset($response['success']) && $response['success']) {
                                                $successIds[] = $orderId;
                                            } else {
                                                $errorIds[$orderId] = $response;
                                            }
                                        } else {
                                            $errorIds[$orderId] = 'Carrier and Tracking Number Key not found';
                                        }
                                    }
                                    if (!empty($successIds)) {
                                        $orderCollection->updateMany(
                                            [
                                                'object_type' => ['$in' => ['source_order', 'target_order']],
                                                'user_id' => $userId,
                                                'marketplace' => 'amazon',
                                                'marketplace_reference_id' => ['$in' => $successIds]
                                            ],
                                            ['$set' => ['fba_shipment_synced' => true]]
                                        );
                                    }
                                    if (!empty($errorIds)) {
                                        $this->di->getLog()->logContent('Error OrderIds = ' . json_encode($errorIds), 'info', $logFile);
                                    }
                                } else {
                                    $this->di->getLog()->logContent("Invalid Response ({$userId}): " . json_encode($response), 'info', $errorLogFile);
                                }
                            }
                        }
                        break;
                        case 'GET_FLAT_FILE_VAT_INVOICE_DATA_REPORT':
                            $processedWebhook = true;
                            $this->di->getObjectManager()
                                ->get(\App\Amazon\Components\Report\VatInvoiceDataReportHandler::class)
                                ->handleWebhook(
                                    $data,
                                    $notificationData,
                                    (string)$userId,
                                    (string)$targetShopId,
                                    $reportId,
                                    $logFile,
                                    $errorLogFile
                                );
                            break;
                }
            }

            return true;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('exception webhookGetReport = '.print_r($e->getMessage(), true).print_r($e->getTraceAsString(), true), 'info', 'exception.log');
            return false;
        }
    }

    /**
     * checkClassAndMethodExists function
     * Returning class path if class and method exists
     * @param string $marketplace
     * @param string $path
     * @param string $method
     * @return string
     */
    public function checkClassAndMethodExists($marketplace, $path, $method)
    {
        $moduleHome = ucfirst($marketplace);
        $baseClass = '\App\\' . $moduleHome . $path;
        $altClass = '\App\\' . $moduleHome . 'home' . $path;
        if (class_exists($baseClass) && method_exists($baseClass, $method)) {
            return $baseClass;
        }
        if (class_exists($altClass) && method_exists($altClass, $method)) {
            return $altClass;
        }

        return null;
    }

    /**
     * save amazonOrderId in feed.specifics
     */
    public function getAmazonOrderIds($s3_feed_id, $bucket) {
        $allIds = [];
        $data = [
            'key' => $s3_feed_id,
            'bucket' => $bucket
        ];
        $response = $this->di->getObjectManager()->get(Feed::class)->getBucketData($data);
        if(isset($response['success']) && $response['success']) {
            try {
                $response = json_decode(json_encode($response['data'], true), true);
                $response = json_decode((string) $response['request'], true);
                $xml_view = simplexml_load_string((string) $response['content']);
                $xml_view = json_decode(json_encode($xml_view), true);

                if(isset($xml_view['Message'])) {
                    if(!isset($xml_view['Message'][0])) {
                        $temp[] = $xml_view['Message'];
                        unset($xml_view['Message']);
                        $xml_view['Message'] = $temp;
                    }

                    foreach($xml_view['Message'] as $feed) {
                        $allIds[] = $feed['OrderFulfillment']['AmazonOrderID'];
                    }
                }
            } catch (Exception $e) {
                print_r($e->getMessage());
            }
        }

        return $allIds;
    }


    public function moldQueueMessages($sqsData)
    {
        $data = $sqsData['data'] ?? [];
        $sqsMessages = [];
        $price = [];
        $image = [];
        $delete = [];
        $feedComponent = $this->di->getObjectManager()->get(Feed::class);
        if(isset($data['operation_type']) && $data['operation_type'] == 'JSON')
        {
            $sqsMessage = $sqsData;
            $ids = $data['specifics']['ids'];
            if(empty($ids))
            {
                $requestData['bucket'] = $data['bucket'];
                $requestData['user_id'] = $data['user_id'];
                $requestData['key'] = $data['s3_feed_id'];
                $feedView = $feedComponent->getBucketData($requestData);
                if(isset($feedView['success'] , $feedView['data']) && $feedView['success'])
                {
                    $feedView = json_decode(json_encode($feedView['data']) , true);
                    if(isset($feedView['ids'])) {
                        $ids = $feedView['ids'];
                    } else {
                        $ids = $feedView['identifiers'] ?? [];
                    }
                }

                foreach ($ids as $id) {
                    $arr = explode("_" , (string) $id);
                    $action = $arr[2];
                    switch ($action) {
                        case 'price':
                            $price[] = $arr[0]."_".$arr[1];
                            break;
                        case 'image':
                            $image[] = $arr[0]."_".$arr[1];
                            break;
                        case 'delete':
                            $delete[] = $arr[0]."_".$arr[1];
                            break;
                    }
                }

                if(!empty($delete))
                {
                    $sqsMessage['data']['action'] = 'delete';
                    $sqsMessage['data']['specifics']['ids'] = $delete;
                    $sqsMessages[] = $sqsMessage;
                }

                if(!empty($image))
                {
                    $sqsMessage['data']['action'] = 'image';
                    $sqsMessage['data']['specifics']['ids'] = $image;
                    $sqsMessages[] = $sqsMessage;
                }

                if(!empty($price))
                {
                    $sqsMessage['data']['action'] = 'price';
                    $sqsMessage['data']['specifics']['ids'] = $price;
                    $sqsMessages[] = $sqsMessage;
                }
            }
        }
        else
        {
            $sqsMessages[] = $sqsData;
        }

        return $sqsMessages;
    }

    public function serverlessResponse($sqsMessage): void
    {
        $date = date('d-m-Y');
        $logFile = "amazon/serverlessResponse/{$date}.log";
        $this->di->getLog()->logContent('Receiving SQS DATA = '.print_r($sqsMessage, true), 'info', $logFile);
        $sqsMessages = $this->moldQueueMessages($sqsMessage);
        foreach ($sqsMessages as $key => $sqsData) {
            if(isset($sqsData['action'])) {
                $action = $sqsData['action'];
            }

            $flag = false;
            if (isset($sqsData['data'])) {
                $data = $sqsData['data'];
                if(isset($data['s3_response_id'], $data['s3_feed_id'])) {
                    $feedComponent = $this->di->getObjectManager()->get(Feed::class);
                    $feed = [];
                    $s3ResponseId = $data['s3_response_id'];
                    $s3FeedId = $data['s3_feed_id'];
                    $feedComponent = $this->di->getObjectManager()->get(Feed::class);
                    $this->di->getAppCode()->setAppTag($sqsData['app_code']);
                    $userId = $data['user_id'];
                    $requestData['bucket'] = $data['bucket'];
                    $requestData['user_id'] = $userId;
                    $contentType = false;
                    $messageIds = [];
                    $timestamp = [];
                    $ids = [];
                    $localDelivery = [];
                    $skuLD = [];
                    //setting user_id in di
                    $getUser = User::findFirst([['_id' => $userId]]);
                    $getUser->id = (string)$getUser->_id;
                    $this->di->setUser($getUser);

                    $feedId = $data['feed_id'];
                    if(isset($data['contentType'] , $data['action']) && ($data['contentType'] == "JSON" || $data['action'] == "inventory"))
                    {
                        $feedType = $data['type']. '_'. strtoupper((string) $data['action']);
                        $contentType = 'JSON';
                        $ids = $data['specifics']['ids'];
                        if(empty($ids))
                        {
                            $requestData['key'] = $s3FeedId;
                            $feedView = $feedComponent->getBucketData($requestData);
                            if(isset($feedView['success'] , $feedView['data']) && $feedView['success'])
                            {
                                $feedView = json_decode(json_encode($feedView['data']) , true);
                                if(isset($feedView['ids'])) {
                                    $ids = $feedView['ids'];
                                } else {
                                    $ids = $feedView['identifiers'] ?? [];
                                }
                            }
                        }

                        foreach ($ids as $key => $messageId) {
                            $msgs = explode('_', (string) $messageId);

                            //to get messageId in order to set error if error is there in feed response
                            $messageIds[$msgs[1]] = $msgs[0];
                            //localDelivery product
                            if(isset($msgs[3]))
                            {
                                $localDelivery[$msgs[0]] = $msgs[3];
                                unset($ids[$key]);
                                continue;
                            }

                            if(count($msgs) > 2)
                            {
                                $ids[$key] = $msgs[0] . "_" . $msgs[2];
                            }

                            $ids = array_keys($messageIds);
                        }

                        $data['specifics']['type'] = $feedType;
                        $data['specifics']['ids'] = array_values($messageIds);
                    }
                    else
                    {
                        $ids = $data['specifics']['ids'];
                        $feedType = $data['type'];
                    }

                    //to check timestamp
                    foreach ($ids as $time) {
                        $timeArr = explode('_',(string) $time);
                        if(count($timeArr) > 1)
                        {
                            $timestamp[$timeArr[0]] = $timeArr[1];
                            $flag = true;
                            $ids[] = $timeArr[0];
                        }
                    }

                    if($flag)
                    {
                        $data['specifics']['ids'] = array_keys($timestamp);
                    }

                    if(isset($data['specifics']['identifiers']))
                    {
                        foreach ($data['specifics']['identifiers'] as $v) {
                            $localDeliveryIds = explode("_" , (string) $v);
                            $localDelivery[$localDeliveryIds[0]] = $localDeliveryIds[2];

                            if(count($localDeliveryIds) > 2)
                            {
                                $localDelivery[$localDeliveryIds[0]] = $localDeliveryIds[2];
                                $skuLD[$localDeliveryIds[1]] = $localDeliveryIds[0];
                                if(in_array($localDeliveryIds[0] , $data['specifics']['ids']))
                                {
                                    $key = array_search($localDeliveryIds[0] , $data['specifics']['ids']);
                                    unset($data['specifics']['ids'][$key]);
                                }
                            }
                        }

                        unset($data['specifics']['identifiers']);
                    }

                    if(!empty($localDelivery))
                    {
                        $data['specifics']['localDelivery'] = $localDelivery;
                    }

                    $status = $data['status'];
                    $requestData['key'] = $s3ResponseId;
                    $feedCreatedDate = $data['feed_created_date'];
                    $marketplaceId = $data['marketplace_id'][0];
                    $remoteShopId = (string)$data['remote_shop_id'];
                    $sourceShopId = $data['source_shop_id'];
                    $homeShopId = null;
                    $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                    $userShops = $user_details->findShop(['remote_shop_id' => $remoteShopId], $userId);
                    foreach ($userShops as $shop) {

                        if ($shop['remote_shop_id'] == $remoteShopId) {
                            $homeShopId = $shop['_id'];
                            $targetMarketplace= $shop['marketplace'];
                            foreach($shop['sources'] as $source){
                                if($source['shop_id'] == $sourceShopId){
                                    $sourceMarketplace = $source['code'];
                                }
                            }
                        }
                    }

                    $specifics = $data['specifics'];
                    $specifics['shop_id'] = $homeShopId;
                    if(isset($data['operation_type'])) {
                        $specifics['operation_type'] = $data['operation_type'];
                    }

                    if($feedType == '_POST_ORDER_FULFILLMENT_DATA_' || $feedType == 'POST_ORDER_FULFILLMENT_DATA') {
                        $amazonOrderID = $this->getAmazonOrderIds($data['s3_feed_id'], $data['bucket']);
                        $specifics['ids'] = $amazonOrderID;
                    }

                    $configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
                    $productComponent = $this->getDi()->getObjectManager()->get(Product::class);


                    $configObj->setUserId($userId);
                    $configObj->setTarget('amazon');
                    $configObj->setTargetShopId($homeShopId);
                    $configObj->setSourceShopId($sourceShopId);
                    $configObj->setGroupCode('feedStatus');
                    $configObj->setAppTag($sqsData['app_code']);
                    $configData = $configObj->getConfig('accountStatus');
                    $datatoConfig['user_id'] = $userId;
                    $datatoConfig['source'] = $sourceMarketplace;
                    $datatoConfig['target'] = $targetMarketplace;
                    $datatoConfig['source_shop_id'] = $sourceShopId;
                    $datatoConfig['target_shop_id'] = $homeShopId;
                    $datatoConfig['group_code'] = 'feedStatus';
                    $datatoConfig['key'] = 'accountStatus';
                    $datatoConfig['app_tag'] = $sqsData['app_code'];
                    if(isset($configData[0]['value']) && $configData[0]['value'] == "inactive") {
                        $datatoConfig['value'] = 'active';
                        $sample = $configObj->setConfig([$datatoConfig]);
                    }

                    $feedResponse = $feedComponent->getBucketData($requestData);
                    $feedResponse = json_decode(json_encode($feedResponse), true);
                    if(isset($feedResponse['data'])) {
                        $responseFile = $feedResponse['data']['response'];
                        $feed = [
                            'feed_id' => $feedId,
                            'type' => $feedType,
                            'status' => $status,
                            'feed_created_date' => $feedCreatedDate,
                            'feed_executed_date' => '',
                            'feed_file' => "",
                            'response_file' => "",
                            'marketplace_id' => $marketplaceId,
                            'remote_shop_id' => $remoteShopId,
                            'profile_id' => '',
                            'shop_id' => $homeShopId,
                            'user_id' => $userId,
                            'bucket' => $data['bucket'],
                            'source_shop_id' => $sourceShopId,
                            'specifics' => $specifics,
                            's3_response_id' => $data['s3_response_id'],
                            's3_feed_id' => $data['s3_feed_id']
                        ];
                        if(isset($data['processType'])) {
                            $feed['processType'] = $data['processType'];
                        }

                        $res = $feedComponent->save($feed);
                        $feed['timestamp'] = $timestamp;
                        if(!empty($skuLD))
                        {
                        $feed['skuLD'] = $skuLD;
                        }

                        if($feedType == 'POST_ORDER_FULFILLMENT_DATA' || $feedType == 'POST_ORDER_ACKNOWLEDGEMENT_DATA') {
                            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                            $userShops = $user_details->getShop($sourceShopId, $userId);
                            $sourceMarketplace = $userShops['marketplace'];
                            $requestData['key'] = $s3FeedId;
                            $viewResponse = $feedComponent->getBucketData($requestData);
                            $viewResponse = json_decode(json_encode($viewResponse), true);
                            $viewFile = $viewResponse['data']['request'];
                            $viewFile = json_decode((string) $viewFile, true);
                            $feedComponent->setErrorInProduct($responseFile, $feed, $viewFile['content'], $sourceMarketplace);
                        } else {
                            $feedComponent->setErrorInProduct($responseFile, $feed, false, false, $contentType, $messageIds);
                        }

                        if($feedType !== 'POST_ORDER_FULFILLMENT_DATA' && $feedType !== 'POST_ORDER_ACKNOWLEDGEMENT_DATA') {
                            $productComponent->removeTag([], [], false, false, false, [], $feed);
                        }
                    }
                } elseif (isset($data['s3_feed_id'], $data['successFeed']) && !$data['successFeed']) {
                    $logFile = "amazon/fetalErrorAmazon/{$date}.log";
                    $this->di->getLog()->logContent('fetal error feed dat = '.print_r($sqsMessage, true), 'info', $logFile);
                    $feedComponent = $this->di->getObjectManager()->get(Feed::class);
                    $responseFile = null;
                    $feed = [
                        'feed_id' => $data['feed_id'],
                        'type' => $data['type'],
                        'status' => $data['status'],
                        'shop_id' => $data['specifics']['shop_id'],
                        'source_shop_id' => $data['source_shop_id'],
                        'target_shop_id' => $sqsData['shop_id'],
                        'specifics' => $data['specifics'],
                        'user_id' => $data['user_id']
                    ];
                    $res = $feedComponent->save($feed);
                    $feedComponent = $this->di->getObjectManager()->get(Feed::class);
                    $feedComponent->setErrorInProduct($responseFile, $feed, false, false, false, [],true);
                    $productComponent = $this->getDi()->getObjectManager()->get(Product::class);
                    $productComponent->removeTag([], [], $sqsData['shop_id'], false, false, [], $feed);

                }
            }
        }
    }

    public function webhookFeedSync($sqsData): void {
        $date = date('d-m-Y');
        $logFile = "amazon/webhookFeedSync/{$date}.log";
        // $this->di->getLog()->logContent('Receiving SQS DATA = ' . print_r($sqsData, true), 'info', $logFile);
        if(isset($sqsData['data'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $feedContainer = $mongo->getCollection(\App\Amazon\Components\Common\Helper::FEED_CONTAINER);
            $productComponent = $this->getDi()->getObjectManager()->get(Product::class);
            $payLoad = $sqsData['data'];
            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $feedComponent = $this->di->getObjectManager()->get(Feed::class);
            if($payLoad['feedProcessingFinishedNotification']['processingStatus'] == 'DONE') {
                $feedId = $payLoad['feedProcessingFinishedNotification']['feedId'];
                $feedType = $payLoad['feedProcessingFinishedNotification']['feedType'];
                $sellerId = $payLoad['feedProcessingFinishedNotification']['sellerId'];
                $resultFeedDocumentId = $payLoad['feedProcessingFinishedNotification']['resultFeedDocumentId'];
                $feeds = $feedContainer->find(['feed_id' => $feedId])->toArray();
                $this->di->getLog()->logContent('Feeds = '.print_r($feeds, true), 'info', $logFile);
                foreach($feeds as $feed) {
                    $feed = json_decode(json_encode($feed), true);
                    $userId = $feed['user_id'];
                    $homeShopId = $feed['shop_id'];
                    $userShops = $user_details->getShop($homeShopId, $userId);

                    // $userShops = $user_details->getUserByRemoteShopId($feed['remote_shop_id'],['shops'=>1]);
                    foreach($userShops['shops'] as $userShop) {
                        if($userShop['warehouses'][0]['seller_id'] == $sellerId) {
                            $remoteShopId = $userShop['remote_shop_id'];
                            $params = ['shop_id' => $remoteShopId, 'resultFeedDocumentId' => $resultFeedDocumentId];
                            $feedResponse = $this->sendRequestToAmazon('feedDocument', $params, 'GET');
                            if(isset($feedResponse['feed'])) {

                                $feedData = [
                                    'feed_id' => $feedId,
                                    'type' => $feedType,
                                    'status' => $payLoad['feedProcessingFinishedNotification']['processingStatus'],
                                    'feed_created_date' => $feed['feed_created_date'],
                                    'feed_executed_date' => '',
                                    'feed_file' => "",
                                    'response_file' => $feedResponse['feed'],
                                    'marketplace_id' => $feed['marketplace_id'],
                                    'profile_id' => '',
                                    'remote_shop_id' => $feed['remote_shop_id'],
                                    'user_id' => $feed['user_id'],
                                    'source_shop_id' => $feed['source_shop_id'],
                                    'specifics' => $feed['specifics'],
                                ];
                                // $this->di->getLog()->logContent('FeedDAta = ' . print_r($feedData, true), 'info', $logFile);

                                $res = $feedComponent->save($feedData);
                                $feedComponent->setErrorInProduct($feedResponse['feed'], $feedData);
                                $productComponent->removeTag([], [], false, false, false, [], $feedData);
                            }
                        }
                    }
                }
            }
        }
    }


    public function webhookProductDelete($sqsData): void
    {
        $date = date('d-m-Y');

        $user = $this->di->getUser()->toArray();
        if ($user['username'] == "anonymous" || $user['username'] == "admin") {
            $userId = $sqsData['user_id'];
            $user = $this->di->getObjectManager()->create('\App\Core\Models\User')->findFirst([
                [
                    'user_id' => $userId,
                ],

            ]);
            $user->id = (string)$user->_id;
            $this->di->setUser($user);
            $user = $user->toArray();
        } else {
            $userId = $user['user_id'];
        }
        $logFile = "amazon/webhookProductDelete/{$userId}/{$date}.log";
        $this->di->getLog()->logContent('Receiving SQS DATA = ' . print_r($sqsData, true), 'info', $logFile);

        if (isset($sqsData['data'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $amazonCollection = $mongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);
            $payLoad = $sqsData['data'];
            $homeShopId = $sqsData['shop_id'];
            $sellerId = $payLoad['SellerId'];
            $marketplaceId = $payLoad['MarketplaceId'];
            $sku = $payLoad['Sku'];
            $amazonListings = false;
            $product = false;
            foreach ($user['shops'] as $userShops) {
                if (isset($shop['_id']) && $shop['_id'] == $homeShopId) {
                    break;
                }
            }

            $sources = $userShops['sources'];
            $remoteShopId = $userShops['remote_shop_id'];
            //Todo :: Need to be done Dynamic
            if (!empty($sources)) {
                foreach ($sources as $source) {
                    $sourceMarketplace = $source['code'];
                    $sourceShopId = $source['shop_id'];
                    $amazonListings = $amazonCollection->findOne(['seller-sku' => $sku, 'shop_id' => (string)$homeShopId, 'user_id' => $userId]);
                    $this->di->getLog()->logContent('AmazonListing = ' . print_r($amazonListings, true), 'info', $logFile);
                    if ($amazonListings) {
                        if ($payLoad['Status'] == ['DELETED']) {
                            if (isset($amazonListings['matched']) && $amazonListings['matched']) {
                                $sourceProductId = $amazonListings['source_product_id'];
                                if (!empty($sourceProductId)) {
                                    $params = ['data' => ['user_id' => $userId, 'source_product_id' => $sourceProductId, 'removeProductWebhook' => true], 'source' => ['shopId' => $sourceShopId, 'marketplace' => $sourceMarketplace], 'target' => ['shopId' => $homeShopId, 'marketplace' => "amazon"]];
                                    $productHelper = $this->di->getObjectManager()->get(ProductHelper::class);
                                    $productHelper->manualUnmap($params);
                                    $this->di->getLog()->logContent('Amazon Product Deleted and Unmapped', 'info', $logFile);
                                } else {
                                    $this->di->getLog()->logContent('Source Product ID not found ', 'info', $logFile);
                                }
                            }
                            $amazonCollection->deleteOne(['seller-sku' => $sku, 'shop_id' => (string)$homeShopId]);
                        } else {
                            $response = $amazonCollection->updateMany(['seller-sku' => $sku, 'shop_id' => (string)$homeShopId], ['$set' => ['amazonStatus' => $payLoad['Status']]]);
                        }
                    }
                }
            }
        }
    }

    public function getBrowseNode($rawBody) {
        $userId = $rawBody['user_id'];
        $targetShopId = $rawBody['target']['shopId'];
        $browseNodeId = $rawBody['browser_node_id'];
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $targetShop = $user_details->getShop($targetShopId, $userId);
        $params = [
            'shop_id' => $targetShop['remote_shop_id'],
            'browseNodeId' => $browseNodeId,
            'primary_category' => $rawBody['primary_category'],
            'sub_category' => $rawBody['sub_category']
        ];
        $response = $this->sendRequestToAmazon('browseNode-Data', $params, 'GET');
        $response['response']['displayPath'] = $response['response']['full_path'] ?? [];
        $response['response']['parentNodes'] = $response['response']['parent_id'] ?? [];
        $response['response']['browser_node_id'] = $response['response']['browseNodeId'] ?? 0;
        unset($response['response']['full_path']);
        unset($response['response']['parent_id']);
        return $response;
    }

    public function getOrderNotificationsData($sqsData): void {
        $date = date('d-m-Y');
        $logFile = "amazon/getOrderNotificationsData/{$date}.log";
        $this->di->getLog()->logContent('Receiving SQS DATA = '.print_r(json_encode($sqsData), true), 'info', $logFile);
        if(isset($sqsData['data'])) {
            $notifications = $this->di->getObjectManager()->get(OrderNotifications::class);
            $notifications->getNotificationsData($sqsData);
        }
    }

    public function addAccountNotification($data): void {
        $user_details = $this->di->getObjectManager()->get('App\Core\Models\User\Details');
        $targetShopId = $data['target_shop_id'];
        $notificationShopId = $data['source_shop_id'];
        $sourceShopId = $data['source_shop_id'];
        if (isset($data['source_marketplace'])) {
            $notificationMarketplace = $data['source_marketplace'];
            $sourceMarketplace = $data['source_marketplace'];
        } else {
            $sourceShop = $user_details->getShop($sourceShopId, $this->di->getUser()->id);
            $notificationMarketplace = $sourceShop['marketplace'];
            $sourceMarketplace = $sourceShop['marketplace'];
        }

        if(isset($data['target_marketplace'])) {
            $targetMarketplace = $data['target_marketplace'];
        } else {
            $targetMarketplace = 'amazon';
        }

        $activityType = $data['activity_type'];

        $amazonShop = $user_details->getShop($targetShopId, $this->di->getUser()->id);
        if(!empty($amazonShop) && isset($amazonShop['warehouses'][0]['marketplace_id'], $amazonShop['warehouses'][0]['seller_id'])) {
            $amazonMarketplaceName = \App\Amazon\Components\Common\Helper::MARKETPLACE_NAME[$amazonShop['warehouses'][0]['marketplace_id']];
            $amazonSellerId = $amazonShop['warehouses'][0]['seller_id'];

            $notificationDataMessage = '';
            $notificationDataSeverity = '';

            if(isset($amazonShop['sellerName']) && !empty($amazonShop['sellerName'])) {
                $sellerName = $amazonShop['sellerName'];
            } else {
                $sellerName = '';
            }

            if($activityType == self::ACTIVITY_TYPE_ACCOUNT_CONNECTION) {
                $notificationDataMessage = 'Amazon account '.$sellerName.'('.$amazonMarketplaceName.'-'.$amazonSellerId.') connected successfully.';
                $notificationDataSeverity = 'success';
                $notificationMarketplace = $sourceMarketplace;
                $notificationShopId = $sourceShopId;
            } elseif($activityType == self::ACTIVITY_TYPE_ACCOUNT_DISCONNECTION) {
                $notificationDataMessage = 'Amazon account '.$sellerName.'('.$amazonMarketplaceName.'-'.$amazonSellerId.') disconnected.';
                $notificationDataSeverity = 'notice';
                $notificationMarketplace = $sourceMarketplace;
                $notificationShopId = $sourceShopId;
            } elseif($activityType == self::ACTIVITY_TYPE_SHOP_DATA_ERASE) {
                $notificationDataMessage = 'Amazon account '.$sellerName.'('.$amazonMarketplaceName.'-'.$amazonSellerId.') data erased.';
                $notificationDataSeverity = 'notice';
                $notificationMarketplace = $sourceMarketplace;
                $notificationShopId = $sourceShopId;
            } elseif($activityType == self::ACTIVITY_TYPE_ACCOUNT_RECONNECTION) {
                $notificationDataMessage = 'Amazon account '.$sellerName.'('.$amazonMarketplaceName.'-'.$amazonSellerId.') re-connected.';
                $notificationDataSeverity = 'notice';
                $notificationMarketplace = $sourceMarketplace;
                $notificationShopId = $sourceShopId;
            }

            $notificationData = [
                "user_id" => $this->di->getUser()->id,
                "marketplace" => $notificationMarketplace,
                "appTag" => $this->di->getAppCode()->getAppTag(),
                "message" => $notificationDataMessage,
                "severity" => $notificationDataSeverity,
                "process_code" => "amazon_account"
            ];



            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($notificationShopId, $notificationData);

        }
    }

    public function getAppTagFromAppCodes($sourceMarketplace, $sourceAppCode, $targetMarketplace, $targetAppCode) {
        $appTags = $this->di->getConfig()->app_tags;
        foreach($appTags as $appTag => $appCodes) {
            if(isset($appCodes['app_code'], $appCodes['app_code'][$sourceMarketplace], $appCodes['app_code'][$targetMarketplace]) && $appCodes['app_code'][$sourceMarketplace] == $sourceAppCode && $appCodes['app_code'][$targetMarketplace] == $targetAppCode) {
                return $appTag;
            }
        }

        return false;
    }

    public function getPreviewData($data , $title = false)
    {
        $json = false;
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $source = $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName();
        $sourceShopId = $data['source']['shopId'] ?? $this->di->getRequester()->getSourceId();
        $target = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
        $targetShopId = $data['target']['shopId'] ?? $this->di->getRequester()->getTargetId();

        $productProfile = $this->di->getObjectManager()->get('App\Connector\Components\Profile\GetProductProfileMerge');
        $data['user_id'] = (string) $userId;
        if (!isset($data['activePage'])) {
           $data['activePage'] = 1;
        }

        $data['limit'] =100;
        $data['operationType'] = 'product_upload';
        $data['usePrdOpt'] = true;
        $data['useRefinProduct'] = true;
        $data['projectData'] = ['marketplace'=>0];
        $products = $productProfile->getproductsByProductIds($data, true);
        $uniqueKey = $products['unique_key_for_sqs'] ?? [];
        if (!empty($uniqueKey)) {
            $dataInCache = [
               'params' => [
                   'unique_key_for_sqs' => $uniqueKey
                ]
            ];
            $productProfile->clearInfoFromCache(['data' => $dataInCache]);
        }

        $products = json_decode(json_encode($products),true);
        if(isset($products['success'] , $products['data']['rows'][0]) && $products['success'])
        {
            $variantTitle = [];
            $allParentDetails = $products['data']['all_parent_details'] ?? [];
            $allProfileInfo = $products['data']['all_profile_info'] ?? [];
            $products = $products['data']['rows'] ?? [];
            $product = [];
            $productComponent = $this->di->getObjectManager()->get(Product::class);
            if($title)
            {
                $variantTitle = $this->getProductData($products , 'title' , $data['source_product_ids'],$allParentDetails);
                $result = [
                    'success' => true ,
                    'variantTitle' => $variantTitle
                ];
            }
            else
            {
                $product = $this->getProductData($products , false , $data['source_product_ids'],$allParentDetails);
                if(isset($product['profile_info']['$oid'])){
                    $product['profile_info'] = $allProfileInfo[$product['profile_info']['$oid']];
                }
                $categorySettings = $productComponent->getUploadCategorySettings($product);
                if(!empty($categorySettings) && is_array($categorySettings))
                {
                    if($categorySettings['primary_category'] == 'default' && $categorySettings['sub_category'] == 'default' && $product['type'] == 'variation')
                    {
                        $product = $this->getProductData($products , 'default' , $data['source_product_ids'],$allParentDetails);
                    }

                    if(isset($categorySettings['product_type'][0]) && !empty($categorySettings['product_type'][0])) {
                        if($product['type'] == 'variation') {
                            $type = Product::PRODUCT_TYPE_PARENT;
                        } elseif($product['visibility'] == 'Catalog and Search') {
                            $type = null;
                        } else {
                            $type = Product::PRODUCT_TYPE_CHILD;
                        }

                        $productTypeHelper = $this->di->getObjectManager()->get(ListingHelper::class);
                        $additionalData['categorySettings'] = $categorySettings;
                        $additionalData['product']  = $product;
                        $additionalData['sourceMarketplace'] = $source;
                        $additionalData['targetShopId'] = $targetShopId;
                        $productTypeHelper->init($additionalData);
                        $preparedContent = $productTypeHelper->setContentJsonListing($categorySettings, $product, $type, Product::OPERATION_TYPE_UPDATE, $source , true);
                        $json = true;
                    } elseif (isset($product['parent_details'])) {
                        $parentAmazonProduct = $product['parent_details'];
                        if ($parentAmazonProduct && isset($parentAmazonProduct['edited']['parent_sku']) && $parentAmazonProduct['edited']['parent_sku'] != '') {
                            $productTypeParentSku = $parentAmazonProduct['edited']['parent_sku'];
                        } else if ($parentAmazonProduct && isset($parentAmazonProduct['sku']) && $parentAmazonProduct['sku'] != '') {
                            $productTypeParentSku = $parentAmazonProduct['sku'];
                        } else {
                            $productTypeParentSku = $parentAmazonProduct['source_product_id'];
                        }

                        $preparedContent = $productComponent->setContent($categorySettings, $product, $targetShopId, 'child', $productTypeParentSku, 'Update' , $source , true);
                    } else if ($product['type'] == 'variation') {
                        $preparedContent = $productComponent->setContent($categorySettings, $product, $targetShopId, 'parent', false, 'Update' , $source , true);
                    } else {
                        $preparedContent = $productComponent->setContent($categorySettings, $product, $targetShopId, false, false, 'Update' , $source , true);
                    }

                    if(!empty($preparedContent))
                    {
                        $error = [];
                        if(isset($preparedContent['error']))
                        {
                            foreach ($preparedContent['error'] as $errorCode) {
                                $error[] = $productComponent->getErrorByCode($errorCode);
                            }

                            unset($preparedContent['error']);
                        }

                        if($json)
                        {
                            $preparedContent = $this->getJsonPreviewData($preparedContent);
                        } elseif($preparedContent['previewLabel']) {
                            $label = $preparedContent['previewLabel'];
                            unset($preparedContent['previewLabel']);
                        }

                        $mandatory = [
                           "item_name",
                           "brand_name",
                           "standard_price",
                           "quantity",
                           "product_description",
                           "barcode",
                           "category",
                           "sub_category",
                           "barcode_exemption",
                           "main_image_url",
                           "item_sku",
                           "other_image_url1",
                           "other_image_url2",
                           "other_image_url3",
                           "other_image_url4",
                           "other_image_url5",
                           "other_image_url6",
                           "other_image_url7",
                           "other_image_url8"
                        ];

                        $offerChanges = [
                            'item_sku' => 'sku',
                            'item_name' => 'title',
                            'standard_price' => 'price',
                            'barcode' => 'product-id'
                        ];

                        $req = [];
                        $op = [];

                        if($json) {
                            $label = self::JSON_MAPPING;
                            $mandatory = array_keys(self::JSON_MAPPING);
                        }

                        foreach ($preparedContent as $key => $value) {
                            $contentPrepared = [];
                            $labelName = $label[$key] ?? $key;
                            $contentPrepared = [
                                'label' => $labelName,
                                'value' => $value
                            ];

                            //mapping done for offer_listing change
                            if(in_array($key , $offerChanges))
                            {
                                $opkey = array_search($key , $offerChanges);
                                $key = $opkey;
                            }

                            if(in_array($key , $mandatory))
                            {
                                if($json)
                                {
                                    $key = self::JSON_KEYS[$key] ?? $key;
                                }

                                $req[$key] = $contentPrepared;
                            }
                            else
                            {
                                $op[$key] = $contentPrepared;
                            }
                        }

                        $finalContent = [
                            'Mandatory' => $req ,
                            'Optional' => $op
                            ];
                        $result = [
                            'success' => true ,
                            'data' => $finalContent,
                            'variantTitle' => $variantTitle,
                            'error' => $error
                        ];
                    }
                    else
                    {
                        $result = ['success' => false , 'msg' => 'Unable to prepare feed , kindly contact support'];
                    }
                }
                else
                {
                    $result = ['success' => false , 'msg' => 'Amazon Category is not selected. Click on Product to edit & select an Amazon Category to upload this Product.'];
                }
            }
        }
        else
        {
            $result = ['success' => false , 'msg' => 'Product not found'];
        }

        return $result;
    }

    public function getJsonPreviewData($feedContent)
    {
        $updatedPreviewData = [];
        $feedContent = json_decode(json_encode($feedContent) , true);
        foreach ($feedContent as $key => $attribute) {
            $previewData = $this->extractInnermostValue($attribute , $key);
            $label = $previewData['key'] ?? $key;
            $updatedPreviewData[$label] = $previewData['value'];
        }

        $updatedPreviewData = array_merge($updatedPreviewData , $feedContent['feedToSave']??[]);
        return $updatedPreviewData;
    }

    public function extractInnermostValue($array, $path = "")
    {
        if (is_array($array)) {
            foreach ($array as $key => $value) {
                if (is_array($value)) {
                    $path = ($path != "" && $key) ? $path.'/'.$key : $path;

                    if (isset($value['value']) && !is_array($value['value'])) {
                        return ['key' => $path, 'value' => $value['value']];
                    }

                    $result = $this->extractInnermostValue($value, $path);
                    if ($result !== null) {
                        return $result;
                    }
                } else {
                    return ['key' => $path, 'value' => $value];
                }
            }
        } else {
            return ['key' => $path, 'value' => $array];
        }

        return ['key' => $path, 'value' => ""];
    }


    public function getProductData($products , $type = false , $sourceProductIds = [],$allParentDetails =[])
    {
        $mainProduct = [];
        foreach ($products as $product) {
            if (isset($product['parent_details']) && is_string($product['parent_details']) && isset($allParentDetails[$product['parent_details']])) {
                $product['parent_details'] = $allParentDetails[$product['parent_details']];
            }
            if(!$type && in_array($product['source_product_id'] , $sourceProductIds))
            {
                $mainProduct = $product;
            }
            elseif($type == 'default' && $product['type'] != "variation")
            {
                return $product;
            }
            else
            {
               $mainProduct[$product['source_product_id']] = $product['variant_title'];
            }
        }

        return $mainProduct;
    }

    public function getDataFromDynamo() {
        $tableName = 'SPAPI_Error';
        $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
        $dynamoData = $dynamoObj->scanData($tableName);
        $getData = array_column($dynamoData['Items'], 'remote_shop_id');
        $remoteShopIds = array_column($getData, 'S');
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $query = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
        $params[] = ['$match' => ['shops.remote_shop_id' => ['$in' => $remoteShopIds]]];
        $params[] = ['$project' => ['_id' => 0, 'user_id' => 1, 'username' => 1, 'shops.remote_shop_id' => 1, 'shops.marketplace' => 1]];
        $result = $query->aggregate($params, ["typeMap" => ['root' => 'array', 'document' => 'array']])->toArray();
        foreach($dynamoData['Items'] as $item) {
            $prepareData = [];
            $remote_shop_id = $item['remote_shop_id']['S'];
            $error_code = $item['error_code']['S'];
            $created_at = $item['created_at']['S'] ?? '';
            foreach($result as $user_detail) {
                $flag = 0;
                foreach($user_detail['shops'] as $shop) {
                    if($shop['marketplace'] == 'amazon' && $shop['remote_shop_id'] == $remote_shop_id) {
                        $prepareData = [
                            'user_id' => $user_detail['user_id'],
                            'username' => $user_detail['username'],
                            'remote_shop_id' => $remote_shop_id,
                            'error_code' => $error_code,
                            'created_at' => $created_at
                        ];
                        $flag = 1;
                        break;
                    }
                }

                if($flag == 1) {
                    break;
                }
            }

            $prepareData !== [] && $completeData[] = $prepareData;
        }

        return ['success' => true, 'count' => count($completeData), 'data' => $completeData];
    }

    //This function is used by faizan to remove webhooks created on single account
    public function handleSingleUser($sqsData): void {
        if(isset($sqsData['shop'])) { {
                $mongo = $this->di->getObjectManager()->create(BaseMongo::class);
                $deletedSingleWebhooks = $mongo->getCollectionForTable('single_webhooks_deleted');
                $remoteDb = $this->di->get('singleRemote');
                $userCollection = $remoteDb->selectCollection("apps_shop");
                $value = $sqsData['shop'];
                $remoteData = $userCollection->findOne(['shop_url' => $value]);
                if(isset($remoteData['_id'])) {
                    $sourceShopId = $remoteData['_id'];
                    $responseWbhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init('shopify', 'true')
                        ->call("/webhook", [], ['shop_id' => $sourceShopId], 'GET');
                    // var_dump($responseWbhook);die;

                    if(isset($responseWbhook['success']) && $responseWbhook['success']) {
                        $deletedSingleWebhooks->insertOne(['shop_url' => $sqsData['shop'], 'created_at' => date('c')]);
                        $webhooks = $responseWbhook['data'];
                        foreach($webhooks as $webhook) {
                            $address = $webhook['address'];
                            if (!str_contains((string) $address, 'multiaccount')) {
                                $this->di->getLog()->logContent('Webhook deleted for remote_shop_id'.$sourceShopId." and topic ".$webhook['topic'], 'info', 'single_webhook_delete.log');
                                $responseWbhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                                    ->init('shopify', 'true')
                                    ->call("/webhook/unregister", [], ['shop_id' => $sourceShopId, 'id' => $webhook['id'], 'topic' => $webhook['topic']], 'DELETE');
                            }
                        }
                    }
                }
            }
        }
    }

    public function deleteDataFromDynamo($data) {
        $tableName = 'SPAPI_Error';
        $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
        $dynamoClientObj = $dynamoObj->getDetails();
        $dynamoClientObj->deleteItem(
            ['TableName' => $tableName, 'Key' => ['remote_shop_id' => ['S' => $data['remote_shop_id']], 'error_code' => ['S' => $data['error_code']]]]
        );

        return ['success' => true, 'message' => 'Deleted Successfully'];
    }

    public function handleNextPrevProductState($event, $myComponent, $params)
    {
        try
        {
            $priceSync = false;
            $syncHandlingTime = false;
            $params = json_decode(json_encode($params), true);
            $handlingTimeUsers = $this->di->getConfig()->get('sync_handling_time_users');
            if (!empty($handlingTimeUsers)) {
                $handlingTimeUsers = array_keys($handlingTimeUsers->toArray());
                if (isset($params[0]['user_id'])
                    && in_array($params[0]['user_id'], $handlingTimeUsers)) {
                    $syncHandlingTime = true;
                }
            }

            foreach($params as $data) {
                if ($syncHandlingTime) {
                    $this->checkMetafieldData($data);
                }

                $operationType = "";
                $dataToSent = [];
                if(isset($data['next_prev']['update_type'], $data['next_prev']['operationType']) && $data['next_prev']['operationType'] == 'update') {
                    if(isset($data['next_prev']['currentValue']) && $data['next_prev']['currentValue'] != NULL) {
                        $currentValue = $data['next_prev']['currentValue'];
                        $operationTypeOnDB = $data['next_prev']['operationType'] ?? "";
                        $currentKey = array_keys($currentValue);
                        $toSent = false;
                        $restrict_values = ['handle','type', 'sku', 'barcode', 'tags', 'quantity','source_product_id','container_id','shop_id','clusterTime','user_id','source_shop_id',"published_at","source_created_at","source_updated_at","updated_at"];
                        $uniq = array_diff($currentKey,$restrict_values);

                        if(in_array('price' , $uniq) || in_array('compare_at_price' , $uniq))
                        {
                            $priceSync = true;
                        }
                        elseif(!empty($uniq))
                        {
                            $toSent = true;
                        }

                        if($toSent || $priceSync){
                            $userId = $data['user_id'];
                            if(isset($data['parent_details']) && !empty($data['parent_details'])) {
                                if(!empty($data['parent_details']['profile'])) {
                                    $profile = $data['parent_details']['profile'];
                                }
                            } else {
                                if(isset($data['profile']) && !empty($data['profile'])) {
                                    $profile = $data['profile'];
                                }
                            }

                            $prepData = [];
                            // Code commented to prevent the value mapping syncing in case of price sync on change stream process. Because this process is consuming high memory.
                            // if(!empty($profile)) {
                            //     if(isset($data['next_prev']['data']['target']['shop_id']) && !empty($data['next_prev']['data']['target']['shop_id'])) {
                            //         foreach($profile as $profileData) {
                            //             if($data['next_prev']['data']['target']['shop_id'] == $profileData['target_shop_id']) {
                            //                 $prepData = ['profile_id' => $profileData['profile_id']['$oid'], 'profile_name' => $profileData['profile_name'], 'user_id' => $userId];
                            //                 $prepData['target'] = ['shopId' => $profileData['target_shop_id'], 'marketplace' => $data['next_prev']['data']['target']['marketplace']];
                            //                 $prepData['source'] = ['shopId' => $data['shop_id'], 'marketplace' => $data['source_marketplace']];
                            //                 $this->di->getObjectManager()->get(ValueMapping::class)->valueMapping($prepData);
                            //             }
                            //         }
                            //     }
                            // }

                            if($operationTypeOnDB == "update" && isset($data['next_prev']['update_type']))
                            {
                                $paramsPrepared = [];
                                $sourceModel = $this->di->getObjectManager()->get(SourceModel::class);
                                $paramsPrepared = $data['next_prev']['data'];

                                $paramsPrepared['source_product_ids'][] = $data['source_product_id'];
                                $paramsPrepared['target']['shopId'] = (string) $paramsPrepared['target']['shop_id'];
                                $paramsPrepared['source']['shopId'] = (string) $paramsPrepared['source']['shop_id'];
                                $paramsPrepared['user_id'] = (string) $userId;

                                if($priceSync && (($data['next_prev']['update_type'] == 'price') || ($data['next_prev']['update_type'] == 'compare_at_price'))) {
                                    $operationType = 'price_sync';
                                } elseif(!empty($uniq) && $data['next_prev']['update_type'] == 'image') {
                                    $operationType = 'image_sync';
                                }

                                if($operationType != "") {
                                    $paramsPrepared['operationType'] = $operationType;
                                    $dataToSent['data']['params'] = $paramsPrepared;
                                    $dataToSent['data']['rows'][] = $data;
                                    $sourceModel->startSync($dataToSent);
                                }
                            }
                        }
                    }
                }
            }

            return true;
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    public function checkMetafieldData($data): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $syncLeadTimeCollection = $mongo->getCollectionForTable('sync_handling_time');
        $sourceProductId = '';
        $leadTime = '';
        if (isset($data['next_prev']['currentValue']['parentMetafield'])) {
            $afterMetafieldData = $data['next_prev']['currentValue']['parentMetafield'];
            if (isset($data['next_prev']['beforeValue']['parentMetafield'])) {
                $beforeMetafieldData = $data['next_prev']['beforeValue']['parentMetafield'];
                if (isset($beforeMetafieldData['custom->lead_time']['value'])) {
                    if (
                        isset($afterMetafieldData['custom->lead_time']['value'])
                        && $beforeMetafieldData['custom->lead_time']['value'] !=
                        $afterMetafieldData['custom->lead_time']['value']
                    ) {
                        $sourceProductId = $data['source_product_id'];
                        $leadTime = $afterMetafieldData['custom->lead_time']['value'];
                    }
                } else {
                    $sourceProductId = $data['source_product_id'];
                    $leadTime = $afterMetafieldData['custom->lead_time']['value'];
                }
            } else {
                $sourceProductId = $data['source_product_id'];
                $leadTime = $afterMetafieldData['custom->lead_time']['value'];
            }
        } else if (isset($data['next_prev']['currentValue']['variantMetafield'])) {
            $afterMetafieldData = $data['next_prev']['currentValue']['variantMetafield'];
            if (isset($data['next_prev']['beforeValue']['variantMetafield'])) {
                $beforeMetafieldData = $data['next_prev']['beforeValue']['variantMetafield'];
                if (isset($beforeMetafieldData['custom->lead_time']['value'])) {
                    if (
                        isset($afterMetafieldData['custom->lead_time']['value'])
                        && $beforeMetafieldData['custom->lead_time']['value'] !=
                        $afterMetafieldData['custom->lead_time']['value']
                    ) {
                        $sourceProductId = $data['source_product_id'];
                        $leadTime = $afterMetafieldData['custom->lead_time']['value'];
                    }
                } else {
                    $sourceProductId = $data['source_product_id'];
                    $leadTime = $afterMetafieldData['custom->lead_time']['value'];
                }
            } else {
                $sourceProductId = $data['source_product_id'];
                $leadTime = $afterMetafieldData['custom->lead_time']['value'];
            }
        }

        if (!empty($sourceProductId) && !empty($leadTime)) {
            $prepareData = [
                'user_id' => $data['user_id'],
                'source_product_id' => $sourceProductId,
                'source_shop_id' => $data['shop_id'],
                'source_marketplace' => $data['source_marketplace'],
                'target_marketplace' => 'amazon',
                'lead_time' => $leadTime,
                'created_at' => date('c')
            ];
            $syncLeadTimeCollection->updateOne(
                [
                    'user_id' => $data['user_id'],
                    'source_product_id' => $sourceProductId
                ],
                [
                    '$set' => $prepareData
                ],
                ['upsert' => true]
            );
        }
    }

    public function registerAmazonWebhook($sqsData = []) {
        if(!empty($sqsData) && !empty($sqsData['remote_shop_id'])) {
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $remoteShopId = $sqsData['remote_shop_id'];
            $logFile = "amazonRegisterWebhook/allUsers/".date('d-m-Y').'.log';
            $shopData = [];
            $this->di->getLog()->logContent('Starting Process for UserId: '.json_encode($userId, true), 'info', $logFile);
            $shops = $this->di->getUser()->shops;
            foreach($shops as $shop) {
                if($shop['remote_shop_id'] == $remoteShopId) {
                    $shopData = $shop;
                    break;
                }
            }

            if(!empty($shopData)) {
                $sources = $shopData['sources'][0] ?? '';
                if(!empty($sources)) {
                    $dataToSent['user_id'] = $userId;
                    $dataToSent['source_marketplace'] = [
                        'marketplace' => $sources['code'],
                        'shop_id' => $sources['shop_id']
                    ];
                }

                $dataToSent['target_marketplace'] = [
                    'marketplace' => $shopData['marketplace'],
                    'shop_id' => $shopData['_id']
                ];
                $this->di->getLog()->logContent('Called Create Subscription', 'info', $logFile);
                $this->di->getObjectManager()->get(\App\Amazon\Components\Notification\Helper::class)
                    ->createSubscription($dataToSent);
                return [
                    'success' => true,
                    'message' => 'Registering Webhooks...'
                ];
            }
            $this->di->getLog()->logContent('Unable to find Shop', 'info', $logFile);
        }

        return [
            'success' => false,
            'message' => 'Unable to Register Webhook'
        ];
    }

    public function isSellerAlreadySelling($params)
    {
        $shopId = $params['shop_id'] ?? false;
        $userId = $params['user_id'] ?? $this->di->getUser()->id ?? false;
        if (!$shopId || !$userId) {
            return ['success' => false, 'message' => 'user_id and shop_id are required'];
        }

        //check here if seller is already selling in db

        $requestData = [
            'start_time' => date('Y-m-d\TH:i:s-00:00', strtotime('-1 month')),
            'end_time' => date('Y-m-d\TH:i:s-00:00'),
            'group_by' => 'Month',
            'user_id' => $userId
        ];
        $orderMatrixResponse = $this->di->getObjectManager()
            ->get(Sales::class)
            ->getOrderMatric($shopId, $userId, $requestData);

        if (
            !isset($orderMatrixResponse['success'])
            || !$orderMatrixResponse['success']
            || !isset($orderMatrixResponse['data'][0]['orderCount'])
            || $orderMatrixResponse['data'][0]['orderCount'] < 1
        ) {
            return ['success' => false, 'message' => 'Unable to verify if seller is already selling'];
        }

        //update in db here
        return ['success' => true, 'message' => 'Seller is already selling'];
    }

    public function syncFbaUserstoDynamo($data)
    {
        try {
            if (isset($data['key']) && $data['key'] == 'fbo_order_fetch') {
                $shops = $this->di->getUser()->shops;
                $targetShopId = $this->di->getRequester()->getTargetId();
                $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
                $dynamoClientObj = $dynamoObj->getDetails();
                $message = 'Something Went Wrong';
                $shopData = [];
                if (!empty($shops) && isset($data['value'])) {
                    foreach ($shops as $shop) {
                        if ($shop['_id'] == $targetShopId) {
                            $shopData = $shop;
                            break;
                        }
                    }

                    if (!empty($shopData)) {
                        $remoteShopId = $shopData['remote_shop_id'];
                        if ($data['value']) {
                            $message = 'FBA order fetching enabled';
                            $dynamoData = $dynamoClientObj->getItem([
                                'TableName' => 'user_details',
                                'Key' => ['id' => ['S' => (string)$remoteShopId]]
                            ]);
                            if (!empty($dynamoData['Item'])) {
                                $dynamoClientObj->updateItem(['TableName' => 'user_details', 'Key' => ['id' => ['S' => (string)$remoteShopId]], 'ExpressionAttributeValues' =>  [':fetch' => ['S' => '1'], ':filter' => ['S' => '{}']], 'UpdateExpression' => 'SET fba_fetch = :fetch , fba_filter=:filter']);
                                $settingEnabledShops = $this->di->getCache()->get('fba_users');
                                if (!empty($settingEnabledShops)
                                    && !array_key_exists($targetShopId, $settingEnabledShops)) {
                                    $settingEnabledShops[$targetShopId] = true;
                                    $this->di->getCache()->set("fba_users", $settingEnabledShops);
                                }
                            }
                        } else {
                            $message = 'FBA order fetching disabled';
                            $dynamoClientObj->updateItem(['TableName' => 'user_details', 'Key' => ['id' => ['S' => (string)$remoteShopId]], 'ExpressionAttributeNames' => [
                                '#fetch' => 'fba_fetch',
                                '#filter' => 'fba_filter'
                            ], 'UpdateExpression' => 'REMOVE #fetch, #filter']);
                            $settingEnabledShops = $this->di->getCache()->get('fba_users');
                            if (!empty($settingEnabledShops) && array_key_exists($targetShopId, $settingEnabledShops)) {
                                unset($settingEnabledShops[$targetShopId]);
                                $this->di->getCache()->set("fba_users", $settingEnabledShops);
                            }
                        }
                    }
                } else {
                    return [
                        'success' => false,
                        'message' => 'Shop not found or value key not found'
                    ];
                }
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception Occured: ' . $e
            ];
        }

        return ['success' => true, 'message' => $message];
    }

    public function handleOrderReport($sqsData)
    {
        try {
            $logFile = 'amazon/order_fetch_report/' . date('Y-m-d') . '.log';
            if (!empty($sqsData['data']['order_ids'])) {
                $orderIds = $sqsData['data']['order_ids'];
            } else {
                $this->di->getLog()->logContent('Empty OrderIds', 'info', $logFile);
                return false;
            }
            $remoteShopId = $sqsData['remote_shop_id'];
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id();
            $orderNotificationObj = $this->di->getObjectManager()->get(OrderNotifications::class);
            $reportType = $sqsData['data']['report_type'] ?? '';
            if(!$orderNotificationObj->checkPlanExists()) {
                $this->di->getLog()->logContent('Order credits exceed closing report process for this user: '.json_encode($userId), 'info', $logFile);
                $this->removeReportProcessFromDynamo($remoteShopId, $reportType);
                return false;
            }
            $homeShopId = $this->getHomeShopId($userId, $remoteShopId);
            if (!empty($homeShopId)) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollection("order_container");
                $missingOrderCollection = $mongo->getCollection("missed_amazon_orders");
                $existingOrders = $collection->distinct(
                    'marketplace_reference_id',
                    [
                        'user_id' => $userId,
                        'object_type' => 'source_order',
                        'marketplace' => 'amazon',
                        'marketplace_shop_id' => $homeShopId,
                        'marketplace_reference_id' => ['$in' => $orderIds],
                        'targets' => ['$exists' => true]
                    ]
                );
                $missingOrders = array_values(array_diff($orderIds, $existingOrders));
                if (!empty($missingOrders)) {
                    $this->di->getLog()->logContent('Missed Orders found for user: '.json_encode($userId), 'info', $logFile);
                    $params = ['target' => ['shopId' => $homeShopId, 'marketplace' => 'amazon']];
                    $helper = $this->di->getObjectManager()->get(Order::class);
                    $helper->init(['user_id' => $userId]);
                    foreach ($missingOrders as $value) {
                        $params['source_order_ids'] = $value;
                        $this->di->getLog()->logContent('Starting process for order_id: '.json_encode($value), 'info', $logFile);
                        $response = $helper->fetchOrdersByShopId($params);
                        $this->di->getLog()->logContent('Order create response: '.json_encode($response), 'info', $logFile);
                    }
                    $missingOrderCollection->insertOne(
                        [
                            'user_id' => $userId,
                            'home_shop_id' => $homeShopId,
                            'remote_shop_id' => $remoteShopId,
                            'missed_order_ids' => $missingOrders,
                            'created_at' => date('c')
                        ]
                    );
                } else {
                    $this->di->getLog()->logContent('No orders missed for this user: '.json_encode($userId), 'info', $logFile);
                }
            }
            return true;
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception from handleOrderReport(), Error: '.json_encode($e), 'info', 'exception.log');
            throw $e;
        }
    }

    private function removeReportProcessFromDynamo($remoteShopId, $reportType) {
        try {
            $marshaler = new Marshaler();
            $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
            $dynamoClientObj = $dynamoObj->getDetails();
            $tableName = self::AMAZON_REPORT_CONTAINER_USERS;
            $dynamoClientObj->deleteItem([
                'TableName' => $tableName,
                'Key' => $marshaler->marshalItem([
                    'activate'  => '1',
                    'id' => $remoteShopId.'_'.$reportType
                ])
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception, removeReportProcessFromDynamo(), Error: ' . json_encode($e->getMessage()), 'info', 'exception.log');
            throw $e;
        }
    }

    function getHomeShopId($userId, $remote_shop_id) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection("user_details");
        $data=$collection->findOne(['user_id'=>$userId],['typeMap' => ['root' => 'array', 'document' => 'array']]);
        $homeShopId=null;
        if (!empty($data)) {
            foreach ($data['shops'] as $value) {
                if($value['remote_shop_id']==$remote_shop_id){
                    $homeShopId=$value['_id'];
                }
            }
        }

        return $homeShopId;
    }

    public function extractLogSection($logFilePath, $searchKeyword, $beforLines = 2,$afterLines = 2,$matches=5, $startFromLine=0) {
        $command = "grep -B {$beforLines} -A {$afterLines}  --line-number '{$searchKeyword}' {$logFilePath} ";
        if ($matches > 0) {
            $command = $command . "-m {$matches}";
        }

        if ($startFromLine > 0) {
            $command = $command . " | sed -n '{$startFromLine},/^--/p' ";
        }

        // print_r($command);die;
        $logSection = shell_exec($command);


        return $logSection;
    }

    public function extractLogLine($logFilePath, $searchKeyword, $startFromLine=0) {
        $logFilePath= BP . DS. 'var/log/'.$logFilePath;
        $command = "grep   --line-number  '{$searchKeyword}' {$logFilePath}   | awk -F: '\$1 >= {$startFromLine} {print}' | head -n 1";
        $logSection = shell_exec($command);
        return $logSection;
    }

    public function getAmazonPopups($params) {
        $user = $this->di->getUser();
        $data = [];
        if (isset($user->popups)) {
            $data = $user->popups;
        }

        return ['success' => true, 'data' => $data];

    }

    public function saveAmazonPopups($params) {
        $mandatoryParams = ["topic", "shop_id"];
        $paramsKeys = array_keys($params);
        if(count(array_intersect($mandatoryParams, $paramsKeys)) != count($mandatoryParams)){
            return ['success' => false, 'message' => json_encode(array_diff($mandatoryParams,$paramsKeys)) . "Mandatory Params Missing"];
        }

        $user = $this->di->getUser();
        $data = [];
        $found = false;
        if (isset($user->popups)) {
            $data = $user->popups;
            foreach ($data as $key => $value) {
                if ($value['topic'] == $params['topic'] && $value['shop_id'] == $params['shop_id']) {
                    $data[$key] = $params;
                    $found = true;
                }
            }
        }

        if($found == false) {
            array_push($data, $params);
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userCollection = $mongo->getCollectionForTable('user_details');
        $userCollection->updateOne(['user_id' => $user->id], ['$set' => ['popups' => $data]]);
        return ['success' => true, 'data' => "Data Updated Successfully"];

    }

    public function deleteAmazonPopup($params) {
        if(empty($params['topic']) || empty($params['shop_id'])){
            return ['success' => false, 'message' => "Topic or Shop Id Missing"];
        }

        $user = $this->di->getUser();
        $found = false;
        if (isset($user->popups)) {
            $data = $user->popups;
            foreach ($data as $key => $value) {
                if ($value['topic'] == $params['topic'] && $value['shop_id'] == $params['shop_id']) {
                    unset($data[$key]);
                    $found = true;
                }
            }

            if ($found) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $userCollection = $mongo->getCollectionForTable('user_details');
                $userCollection->updateOne(['user_id' => $user->id], ['$set' => ['popups' => $data]]);
                return ['success' => true, 'data' => "Topic Deleted Successfully"];
            }
        }

        if ($found == false) {
            return ['success' => false, 'data' => "Topic Not Found"];
        }
    }

    /** process ISSUE_ITEM_CHANGE notification */
    public function webhookProductIssueChange($sqsData)
    {
        try {
            $date = date('d-m-Y');
            $logFile = "amazon/webhookProductIssueChange/{$this->di->getUser()->id}/.{$date}.log";
            $this->di->getLog()->logContent('Receiving SQS DATA = ' . print_r($sqsData, true), 'info', $logFile);
            if (isset($sqsData['data'])) {
                $listingHelper = $this->di->getObjectManager()->get(ProcessResponse::class);
                return $listingHelper->processListingIssueEvent($sqsData);
            }
            return ['success' => false, 'message' => 'Required params(data) is missing'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function validateRefreshToken($params = [])
    {
        $logFile = "amazon/validateToken/" . date('d-m-Y') . '.log';
        $page = $params['page'] ?? 1;
        $this->di->getLog()->logContent('Starting Page: ' . $page, 'info', $logFile);
        try {
            $remoteDB = $this->di->get('remote_db');
            if ($remoteDB) {
                $appShops = $remoteDB->selectCollection('apps_shop');
                $pipline = [
                    [
                        '$match' => [
                            'group_code' => 'amazon',
                            'apps.refresh_token' => ['$exists' => true],
                            'apps.access_token' => ['$exists' => true]
                        ],
                    ],
                    ['$project' => ['_id' => 1]],
                    ['$sort' => ['_id' => 1]],
                    ['$limit' => 500]
                ];
                if (!empty($params['_id'])) {
                    if (isset($params['_id']['$oid'])) {
                        $id = new ObjectId($params['_id']['$oid']);
                    } else {
                        $id = $params['_id'];
                    }

                    $preparePipline = ['$match' => ['_id' => ['$gt' => $id]]];
                    array_splice($pipline, 0, 0, [$preparePipline]);
                }

                $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
                $shopData = $appShops->aggregate($pipline, $arrayParams)->toArray();
                $commonHelper =  $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                if (!empty($shopData)) {
                    $tokenExpiredIds = [];
                    $userId = $params['user_id'] ?? $this->di->getUser()->id;
                    foreach ($shopData as $shop) {
                        $response = $commonHelper->sendRequestToAmazon('access-token', ['shop_id' => $shop['_id']], 'GET');
                        if (isset($response['success']) && !$response['success']) {
                            $tokenExpiredIds[] = $shop['_id'];
                            $this->di->getLog()->logContent('Token Invalid Data: ' .
                                json_encode([
                                    'remote_shop_id' => $shop['_id'],
                                    'response' => $response['response'] ?? $response
                                ]), 'info', $logFile);
                        }
                    }

                    $this->di->getLog()->logContent('Total expiredIds found in this page: ' . json_encode(count($tokenExpiredIds)), 'info', $logFile);
                    if (!empty($tokenExpiredIds)) {
                        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                        $userDetails = $mongo->getCollectionForTable('user_details');
                        $pipline = [
                            ['$unwind' => '$shops'],
                            [
                                '$match' => [
                                    'shops.marketplace' => 'amazon',
                                    'shops.remote_shop_id' =>  ['$in' => $tokenExpiredIds]
                                ],
                            ],
                            ['$project' => ['user_id' => 1, 'shops' => 1, 'username' => 1, 'email' => 1]]
                        ];
                        $userData = $userDetails->aggregate($pipline, $arrayParams)->toArray();
                        if (!empty($userData)) {
                            $prepareData = [];
                            foreach ($userData as $data) {
                                if (!isset($prepareData[$data['user_id']])) {
                                    $prepareData[$data['user_id']] = [
                                        'user_id' => $data['user_id'],
                                        'username' => $data['username'],
                                        'email' => $data['email'],
                                        'created_at' => date('c')
                                    ];
                                }

                                $prepareData[$data['user_id']]['shops'][] = $data['shops'];
                            }

                            $invalidTokenUsers = $mongo->getCollectionForTable('invalid_token_users');
                            $invalidTokenUsers->insertMany(array_values($prepareData));
                        } else {
                            $this->di->getLog()->logContent('No data found for these remote_shop_ids: '.json_encode($tokenExpiredIds), 'info', $logFile);
                        }
                    }

                    if (count($shopData) < 500) {
                        $this->di->getLog()->logContent('All Pages completed', 'info', $logFile);
                        return ['success' => true, 'message' => "All Pages completed"];
                    }

                    $endUser = end($shopData);
                    $this->di->getLog()->logContent('Last _id of this page' . json_encode($endUser), 'info', $logFile);
                    $page = $page + 1;
                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => \App\Amazon\Components\Common\Helper::class,
                        'method' => 'validateRefreshToken',
                        'queue_name' => 'validate_refresh_token',
                        'user_id' => $userId,
                        '_id' => $endUser['_id'],
                        'page' => $page,
                        'delay' => 15
                    ];
                    $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')
                    ->pushMessage($handlerData);
                    $this->di->getLog()->logContent('Process Completed for this page', 'info', $logFile);
                    $message = 'Processing from Queue...';
                }
            } else {
                $message = 'Unable to create connection of remoteDB';
                $this->di->getLog()->logContent($message, 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception => ' . print_r($e->getMessage(), true), 'info', $logFile);
        }

        return ['success' => true, 'message' => $message ?? 'Process Completed'];
    }
    public function processUnifiedFeed($sqsMessage):bool
    {
        $date = date('d-m-Y');
        $logFile = "amazon/UnifiedFeed/{$date}.log";
        $this->di->getLog()->logContent('Receiving SQS DATA = '.print_r($sqsMessage, true), 'info', $logFile);

        $sqsData = $sqsMessage['data']??[];
        if(isset($sqsData['s3_feed_id'],$sqsData['s3_response_id'])){
            $feedComponent = $this->di->getObjectManager()->get(Feed::class);
            $this->di->getAppCode()->setAppTag($sqsMessage['app_code']);
            $s3ResponseId = $sqsData['s3_response_id'];
            $s3FeedId = $sqsData['s3_feed_id'];
            $userId = $sqsData['user_id'];
            $requestData['bucket'] = $sqsData['bucket'];
            $requestData['user_id'] = $userId;
            $getUser = User::findFirst([['_id' => $userId]]);
            $getUser->id = (string)$getUser->_id;
            $this->di->setUser($getUser);
            $feedId = $sqsData['feed_id'];
            if(isset($sqsData['action'])){
                $requestData['key'] = $s3FeedId;
                $feedViewData = $feedComponent->getBucketData($requestData);

                if(isset($feedViewData['success'])&& $feedViewData['success']){
                    $feedView = json_decode(json_encode($feedViewData['data']) , true);
                    $ids = $feedView['identifiers']??[];
                    $actionData = $feedView['actionData'];
                    $processTypes = $feedView['processTypes'];



                }
                $status = $sqsData['status'];
                $requestData['key'] = $s3ResponseId;
                $feedCreatedDate = $sqsData['feed_created_date'];

                $remoteShopId = (string)$sqsData['remote_shop_id'];
                $sourceShopId = $sqsData['source_shop_id'];
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $userShops = $user_details->findShop(['remote_shop_id' => $remoteShopId], $userId);
                foreach ($userShops as $shop) {

                    if ($shop['remote_shop_id'] == $remoteShopId) {
                        $homeShopId = $shop['_id'];

                        $marketplaceId = $shop['warehouses'][0]['marketplace_id'];
                        $targetMarketplace= $shop['marketplace'];
                        foreach($shop['sources'] as $source){
                            if($source['shop_id'] == $sourceShopId){
                                $sourceMarketplace = $source['code'];
                            }
                        }
                    }
                }

                $specifics = $sqsData['specifics'];
                    $specifics['shop_id'] = $homeShopId;
                    if(isset($data['operation_type'])) {
                        $specifics['operation_type'] = $data['operation_type'];
                    }


            }
            $configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
                    $productComponent = $this->getDi()->getObjectManager()->get(Product::class);
                    $configObj->setUserId($userId);
                    $configObj->setTarget('amazon');
                    $configObj->setTargetShopId($homeShopId);
                    $configObj->setSourceShopId($sourceShopId);
                    $configObj->setGroupCode('feedStatus');
                    $configObj->setAppTag($sqsMessage['app_code']);
                    $configData = $configObj->getConfig('accountStatus');
                    $datatoConfig['user_id'] = $userId;
                    $datatoConfig['source'] = $sourceMarketplace;
                    $datatoConfig['target'] = $targetMarketplace;
                    $datatoConfig['source_shop_id'] = $sourceShopId;
                    $datatoConfig['target_shop_id'] = $homeShopId;
                    $datatoConfig['group_code'] = 'feedStatus';
                    $datatoConfig['key'] = 'accountStatus';
                    $datatoConfig['app_tag'] = $sqsMessage['app_code'];
                    if(isset($configData[0]['value']) && $configData[0]['value'] == "inactive") {
                        $datatoConfig['value'] = 'active';

                        $sample = $configObj->setConfig([$datatoConfig]);
                    }
                    $feedResponse = $feedComponent->getBucketData($requestData);


                    $feedResponse = json_decode(json_encode($feedResponse), true);

                    if(isset($feedResponse['data'])){
                        $responseFile = $feedResponse['data']['response'];
                        $feed = [
                            'feed_id' => $feedId,
                            'type' => 'JSON_LISTING_FEED',
                            'status' => $status,
                            'feed_created_date' => $feedCreatedDate,
                            'feed_executed_date' => '',
                            'feed_file' => "",
                            'response_file' => "",
                            'marketplace_id' => $marketplaceId,
                            'remote_shop_id' => $remoteShopId,
                            'profile_id' => '',
                            'shop_id' => $homeShopId,
                            'user_id' => $userId,
                            'bucket' => $sqsData['bucket'],
                            'source_shop_id' => $sourceShopId,
                            'specifics' => $specifics,
                            's3_response_id' => $sqsData['s3_response_id'],
                            's3_feed_id' => $sqsData['s3_feed_id'],
                            'actionData' => $actionData,
                            'unified_true' => true
                        ];
                        $contentType = 'JSON';
                        $res = $feedComponent->save($feed);

                        $this->processFeedProduct($responseFile,$feed,$ids,$actionData,$processTypes);


                    }

        }

        return true;
    }

    public function processFeedProduct($response, $feed,$ids,$actionData,$processTypes)
    {
        // $sourceShopId = $feed['source_shop_id'];
        // $remoteShopId = $feed['remote_shop_id'];
        $targetShopId = $feed['shop_id'];

        $productComponent = $this->di->getObjectManager()->get(Product::class)->init();
        $variantError = [];
        foreach($ids as $id){
            $breakids = explode('_',$id);
            $messageIds[$breakids[1]] = $breakids[0];
        }
        if(isset($feed['type']) && $feed['type'] == "JSON_LISTING_FEED"){
            if (isset($response['summary']['messagesProcessed'])) {
                $recordsProcessed = $response['summary']['messagesProcessed'];
            }
            if (isset($response['summary']['messagesAccepted'])) {
                $recordsSuccessful = $response['summary']['messagesAccepted'];
            }
            if(isset($response['summary']['errors'])){
                $errorsCount = $response['summary']['errors'];
            }
            if(isset($response['summary']['messagesInvalid'])){
                $messageInvalidCount = $response['summary']['messagesInvalid'];
            }

            if($recordsSuccessful<$recordsProcessed){
                if(!empty($response['issues'])){
                    $errors = $response['issues'];

                    if(!empty($ids)){
                        $key = 0;
                        $variantId=null;

                        foreach($errors as  $error){
                            $sourceProductId = $messageIds[$error['messageId']];
                            if($sourceProductId != $variantId){
                                $key =0;
                            }

                            $variantError[$sourceProductId][$key]['code'] = $error['code'];
                            $variantError[$sourceProductId][$key]['message'] = $error['message'];
                            $variantError[$sourceProductId][$key]['actions'] = $actionData[$sourceProductId];

                            $variantId = $sourceProductId;
                            $key ++;
                        }


                    }
                }

            }

            $listToupdateUploadedStatus =[];
            $listToremoveStatus = [];
            $idsTosentMail = [];
            $errorIdtoMail = [];
            foreach($messageIds as $messageId => $sourceProductId){
                if(isset($actionData[$sourceProductId]) && isset($processTypes['inventory'][$sourceProductId]) && in_array('inventory',$actionData[$sourceProductId]) && $processTypes['inventory'][$sourceProductId] == "automatic")
                {
                    $errorVariantList = array_keys($variantError);
                    $feedProductList =  $messageIds;
                    $variantList = array_diff($feedProductList, $errorVariantList);
                    if(in_array($sourceProductId,$variantList)){
                        $idsTosentMail[] = $sourceProductId;

                    }else{
                        $errorIdtoMail[] = $sourceProductId;
                    }
                  
                   
                    
                }
                if(isset($actionData[$sourceProductId])&& in_array('upload',$actionData[$sourceProductId]) && $recordsSuccessful > 0){
                    $errorVariantList = array_keys($variantError);
                    $feedProductList =  $messageIds;
                    $variantList = array_diff($feedProductList, $errorVariantList);
                    if(in_array($sourceProductId,$variantList)){
                        $listToupdateUploadedStatus[] = $sourceProductId;

                    }


                }
                if(isset($actionData[$sourceProductId]) && in_array('delete',$actionData[$sourceProductId]) && $recordsSuccessful>0){
                    $errorVariantList = array_keys($variantError);
                    $feedProductList =  $messageIds;
                    $variantList = array_diff($feedProductList, $errorVariantList);
                    if(in_array($sourceProductId,$variantList)){
                        $listToremoveStatus[] = $sourceProductId;

                    }
                }

            }
            if(!empty($idsTosentMail)){

                $sendToMail['targetShopId'] = $targetShopId;
                $sendToMail['actionType'] = 'inventory_sync';
                $sendToMail['recordsSuccessful'] = count($idsTosentMail);
                $sendToMail['errorIds'] = array_keys($errorIdtoMail);
               
               $sendReceived = $this->sendMailToFeedProcessed($sendToMail);
            }

            $this->removeprocessTag($messageIds,$feed);
            $this->putErrorInProduct($variantError,$feed);
            if(!empty($listToupdateUploadedStatus)){

                $productComponent->setUploadedStatus($listToupdateUploadedStatus, $feed);
            }
            if(!empty($listToremoveStatus)){

                $productComponent->removeStatusFromProduct($listToremoveStatus, $targetShopId, false, false, $feed);
            }



        }
    }
    public function sendMailToFeedProcessed($data)
    {
     $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
     $configCollection = $mongo->getCollection('config');
     $targetShopId = $data['targetShopId'];
     $responseSucessfull = $data['recordsSuccessful'];
     $errorIds = $data['errorIds'];
     $query = [
         'user_id' => $this->di->getUser()->id,
         'group_code' => 'product',
         'key' => 'sync_feed_process',
         'target_shop_id' => $targetShopId
     ];
    
     $configData = $configCollection->findOne($query);
     $configData = json_decode(json_encode($configData),true);
    
     $toSync = $configData['value']??false;
    
        if($toSync){

            $actionType = $data['actionType'];
            // $appName = $source . '_base_path';
            // $appPath = $configData[$source][$data['app_code']][$appName];
            $userName = $this->di->getUser()->username;
            $appName = $this->di->getConfig()->app_name;
            $storeName = str_replace('.myshopify.com', '', $userName);
            $mailData = [
            'name' => $storeName,
            'app_name' => $appName,
            'actionType'=> $actionType,
            'dateAndTime' => date("c"),
            'responseSucessfull'=>$responseSucessfull,
            'errors'=>  count($errorIds),
            'email' => $this->di->getUser()->source_email ?? $this->di->getUser()->email,
            // 'subject' => 'Your Action Has Been Completed Successfully',
            'subject' => 'Inventory Sync Completed -'.$appName,
            'path' => 'amazon' . DS . 'view' . DS . 'email' . DS . 'feedProcessing.volt'
        ];
       
        try{
            
            $mailResponse=$this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($mailData);
           
        }
        catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from sendMailToFeedProcessed(), Error:' . print_r($e->getMessage(), true), 'info', 'exception.log');
        }
    }
    }
    public function removeprocessTag($ids,$feed)
    {
        $sourceShopId = $feed['source_shop_id'];
        $remoteShopId = $feed['remote_shop_id'];
        $targetShopId = $feed['shop_id'];
        $userId = $feed['user_id']??$this->di->getUser()->id;
        foreach ($this->di->getUser()->shops as $shop) {
            if ($shop['_id'] == $sourceShopId) {
                $sourceMarketplace = $shop['marketplace'] ?? "shopify";
            }
        }
        $additionalData['source'] = [
            'marketplace' =>$sourceMarketplace,
            'shopId' => (string)$sourceShopId
        ];
        $additionalData['target'] = [
            'marketplace' => 'amazon',
            'shopId' => (string)$targetShopId
        ];
        if(!empty($ids)){
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('product_container');

            $filter = ['user_id'=>$userId,'source_product_id' =>['$in'=>array_values($ids)],'source_marketplace'=>'shopify'];

            $products = $collection->find($filter)->toArray();
            $products = json_decode(json_encode($products),true);
            $productInsert = [];
            foreach($products as $product){
                $updateProduct = [
                    "source_product_id" => (string) $product['source_product_id'],
                    // required
                    'user_id' => $userId,
                    'source_shop_id' => $sourceShopId,
                    'container_id' => (string) $product['container_id'],
                    'target_marketplace'=> 'amazon',
                    'shop_id'=> $targetShopId,
                    'unset'=>['process_tags'=>true]
                ];
                array_push($productInsert,$updateProduct);
            }

            if (!empty($productInsert)) {
                $appTag = $this->di->getAppCode()->getAppTag();

                if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
                    /* Trigger websocket (if configured) */
                    $websocketConfig = $this->di->getConfig()->get("app_tags")
                        ->get($appTag)
                        ->get('websocket');
                    if (
                        isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['notification']) &&
                        $websocketConfig['client_id'] &&
                        $websocketConfig['allowed_types']['notification']
                    ) {
                        echo '<pre>';
                        print_r($productInsert);

                        $helper = $this->di->getObjectManager()->get(ConnectorHelper::class);
                        $helper->handleMessage([
                            'user_id' => $userId,
                            'notification' => $productInsert
                        ]);
                    }}
                $objectManager = $this->di->getObjectManager();
               $res= $objectManager->get('\App\Connector\Models\Product\Edit')->saveProduct($productInsert,$userId,$additionalData);
            }

        }


    }

    public function putErrorInProduct($variantError,$feed)
    {
        $sourceShopId = $feed['source_shop_id'];
        $remoteShopId = $feed['remote_shop_id'];
        $targetShopId = $feed['shop_id'];
        $userId = $feed['user_id']??$this->di->getUser()->id;
        foreach ($this->di->getUser()->shops as $shop) {
            if ($shop['_id'] == $sourceShopId) {
                $sourceMarketplace = $shop['marketplace'] ?? "shopify";
            }
        }
        $additionalData['source'] = [
            'marketplace' =>$sourceMarketplace,
            'shopId' => (string)$sourceShopId
        ];
        $additionalData['target'] = [
            'marketplace' => 'amazon',
            'shopId' => (string)$targetShopId
        ];

        if(!empty($variantError)){
            $productInsert = [];

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('product_container');
            $productIds = array_keys($variantError);
            foreach ($productIds as $key => $value) {
                $productIds[$key] = (string) $value;
            }
            $filter = ['user_id'=>$userId,'source_product_id' =>['$in'=>$productIds],"source_marketplace"=>$sourceMarketplace];
            $products = $collection->find($filter)->toArray();
            $products = json_decode(json_encode($products),true);


            foreach($products as $product){

                if(isset($variantError[$product['source_product_id']]) ){
                    $err=[];
                    $errors =$variantError[$product['source_product_id']];

                    foreach($errors as $key=> $error){
                        $err[$key]['code'] = $error['code']??'';
                        $err[$key]['message'] = $error['message']??'';
                        $err[$key]['type'] = $error ['actions'][0];
                        $err[$key]['source'] = 'marketplace';

                    }
                    if(!empty($err)){
                        $updateProduct = [
                            "source_product_id" => (string) $product['source_product_id'],
                            // required
                            'user_id' => $userId,
                            'source_shop_id' =>(string) $sourceShopId,
                            'target_marketplace'=> 'amazon',
                            'container_id' => (string) $product['container_id'],
                            'error'=>  $err,
                            'shop_id'=> (string)$targetShopId
                        ];


                        array_push($productInsert, $updateProduct);
                    }
                }

            }

            if (!empty($productInsert)) {
                $appTag = $this->di->getAppCode()->getAppTag();

                if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
                    /* Trigger websocket (if configured) */
                    $websocketConfig = $this->di->getConfig()->get("app_tags")
                        ->get($appTag)
                        ->get('websocket');
                    if (
                        isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['notification']) &&
                        $websocketConfig['client_id'] &&
                        $websocketConfig['allowed_types']['notification']
                    ) {
                        echo '<pre>';
                        print_r($productInsert);

                        $helper = $this->di->getObjectManager()->get(ConnectorHelper::class);
                        $helper->handleMessage([
                            'user_id' => $userId,
                            'notification' => $productInsert
                        ]);
                    }}
                $objectManager = $this->di->getObjectManager();
               $res= $objectManager->get('\App\Connector\Models\Product\Edit')->saveProduct($productInsert,$userId,$additionalData);
            }

        }

    }

    public function getPoisonMessageData($params =[]) {
        try {
            $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $poisonMessageContainer = $baseMongo->getCollection('sqs_poison_message_container');
            $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $data = $poisonMessageContainer->aggregate($params, $arrayParams)->toArray();
            if(!empty($data)) {
                return ['success' => true, 'data' => $data];
            }
            return ['success' => false, 'message' => 'No data found'];
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception from getPoisonMessageData(): ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }
}
