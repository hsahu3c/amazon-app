<?php
namespace App\Frontend\Components;

use App\Core\Components\Base;
use DateTime;
use DateTimeZone;
use App\Amazonimporter\Models\Shop\Details;
use App\Connector\Models\User\Connector;
use Google_Service_ShoppingContent;
use Google_Service_ShoppingContent_AccountsLinkRequest;
use Exception;
use App\Core\Models\BaseMongo;
use Phalcon\Events\Event;
use function MongoDB\BSON\toJSON;
use function MongoDB\BSON\fromPHP;
class Helper extends Base
{
    /**
     * function to get basic user details (modified).
     *
     * @param array $data contains params.
     * @return void
     */
    public function getUserDeatils() {

        $created_at = $this->di->getUser()->created_at;
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $userDetails  = $user_details->getConfigByKey('date_timezone_wrapper', $this->di->getUser()->id);
        $timeZone     = $userDetails !== false && ! empty( $userDetails['timezone'] ) ? $userDetails['timezone'] : 'Asia/Kolkata';

        $date_format  = $user_details->getConfigByKey('date_timezone_wrapper', $this->di->getUser()->id);
        $dateFormat  = $date_format !== false && ! empty( $date_format['date_format'] ) ? $date_format['date_format'] : 'Y-m-d H:i:s';


        $dateTime = gmdate( 'Y-m-d H:i:s', strtotime( $created_at ) );
        $date     = new DateTime( $dateTime );
        $date->setTimezone(new DateTimeZone($timeZone));

        $created_at = $date->format( $dateFormat );

        return [
            'email' => $this->di->getUser()->email ?? '',
            'store_url' => $this->getStoreUrl() ?? '',
            'created_at' => $created_at ?? '',
            'user_name' => $this->di->getUser()->username ?? ''
        ];
    }

      /**
     * Wil get the store url ( modified )
     *
     * @return string
     */
    public function getStoreUrl() {

        $shops = $this->di->getUser()->shops ?? [];
        foreach ( $shops as $value ) {
            if ( $value['marketplace'] == 'magento' ) {
                return $value['storeurl'];
            }
        }

        return false;

    }

    public function setActiveStep($stepData, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        //added code.
        if(!isset( $stepData['step'] ) && empty( $stepData['step'] ) ) {
            return ['success' => false, 'code' => 'active_step_update_failed', 'message' => 'Active step update failed'];
        }

        $stepCompleted = $stepData['step'];
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        //added code.
        $oldStep = $user_details->getConfigByKey('step_completed', $userId);
        if(!empty( $oldStep ) && $stepCompleted <= $oldStep ) {
            return ['success' => false, 'code' => 'active_step_update_failed', 'message' => 'Active step update failed'];
        }

        $store_step_completed = $user_details->setConfigByKey('step_completed', $stepCompleted, $userId);
        if ($store_step_completed['success']) {
           // print_r($store_step_completed);die('if');
            $response = ['success' => true, 'code' => 'active_step_updated', 'message' => 'Active step updated'];
        } else {
           // print_r($store_step_completed);die('else');
            $response = ['success' => false, 'code' => 'active_step_update_failed', 'message' => 'Active step update failed'];
        }

        return $response;
    }

    public function setConfigurationsApp($data){
        $userId = $this->di->getUser()->id;
        $configurationsData = $data;
        $shop=$this->di->getUser()->getShop();
        if(!empty($shop)){
            if($shop){
                $shop = $shop->getData();
                $shopId = $shop['id'];
            } else {
                $shopId = 0;
            }

            $this->di->getObjectManager()->get('\App\Core\Models\User\Config')->setForShop($userId,$shopId,'/App/Configurations', $configurationsData, 'shopify');
        }else{
            $this->di->getObjectManager()->get('\App\Core\Models\User\Config')->set($userId,'/App/Configurations', $configurationsData, 'shopify');
        }

        return ['success' => true, 'code' => 'app_configuration_updated', 'message' => 'App Configurations Set'];
    }

    public function getConfigurationsApp()
    {
        $userId = $this->di->getUser()->id;
        if ($shop = $this->di->getUser()->getShop()) {
            $shop = $this->di->getUser()->getShop()->getData();
            $shopId = isset($shop) ? $shop['id'] : 0;
            $data = $this->di->getObjectManager()->get('\App\Core\Models\User\Config')->getConfigByPathForShop($userId, $shopId, '/App/Configurations', 'shopify');
        } else {
            $data = $this->di->getObjectManager()->get('\App\Core\Models\User\Config')->getConfigByPath($userId, '/App/Configurations', 'shopify');
        }

        if (!$data) {
            $step = 0;
        } else {
            $step = $data['value'];
        }

        return ['success' => true, 'data' => $step];
    }


    public function getActiveStep($path, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        // $step = 0;
        // $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        // $step_completed = $user_details->getConfigByKey('step_completed', $userId);
        // if(!$step_completed){
        //     $store_step_completed = $user_details->setConfigByKey('step_completed', 0, $userId);
        //     if($store_step_completed['success']) $step = 0 ;
        // } else $step = $step_completed;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $user = $collection->find(['user_id' => $userId])->toArray()[0];
        $connected = false;
        foreach ($user['shops'] as $value) {
            if ($value['marketplace'] == 'amazon') {
                $connected = true;
            }
        }

        return ['success' => true, 'data' => $connected == true ? 3 : 0, 'collections' => $user];
    }


    /**
     * Will return step completed for current user ( modified ).
     *
     * @param array $params array containing the params.
     * @since 1.0.0
     * @return array
     */
    public function getActiveStep1( $params = [] ) {

        $userId          = $this->di->getUser()->id ?? false;
        $step            = 0;
        $user_details    = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $step_completed  = $user_details->getConfigByKey('step_completed', $userId);
        $mongo           = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection = $mongo->getCollectionFortable('user_details');
        $query           = $mongoCollection->findOne(
            [
                'user_id' => $userId
            ],
            [
                'projection' => [
                    '_id'      => 0,
                    'username' => 1,
                    'shops'    => 1,
                    'marketplace_handler' => 1
                ]
            ]
        );
        if ( ! empty( $query ) ) {
            $query = json_decode( toJSON( fromPHP( $query ) ), true );
        }

        $targetConnected = false;
        $shops = [
            'Ced-Source-Id'    => $query['shops'][0]['_id'] ?? false,
            'Ced-Source-Name'  => $query['shops'][0]['marketplace'] ?? false,
            'Remote-Source-Id' => $query['shops'][0]['remote_shop_id'] ?? false,
            // 'Ced-Target-Id'    => $query['shops'][1]['_id'] ?? false,
            // 'Ced-Target-Name'  => $query['shops'][1]['marketplace'] ?? false,
            // 'Remote-Target-Id' => $query['shops'][1]['remote_shop_id'] ?? false,
            'Token-Warning'    => false
        ];
        foreach ($query['shops'] as $value) {
           if( isset( $value['marketplace'] ) && $value['marketplace'] ==  $targetMarketplace ) {
            $shops['Ced-Target-Id'] = $value['_id'] ?? false;
            $shops['Ced-Target-Name']  = $value['marketplace'] ?? false;
            $shops['Remote-Target-Id'] = $value['remote_shop_id'] ?? false;
            $targetConnected = true;
           }
        }

        if( !$targetConnected){
            $shops['Ced-Target-Id']    = $query['shops'][1]['_id'] ?? false;
            $shops['Ced-Target-Name']  = $query['shops'][1]['marketplace'] ?? false;
            $shops['Remote-Target-Id'] = $query['shops'][1]['remote_shop_id'] ?? false;
        }

        if( !empty( $query['marketplace_handler'] ) &&  $query['marketplace_handler'] == 'shopify' ) {
            $shops['shopify_custom_app'] = true;
        }

        $shops['Ced-Target-Display-Name'] = ('arise' === strtolower( (string) $shops['Ced-Target-Name'] )) ? 'miravia' : $shops['Ced-Target-Name'];
        ( 'arise' === $shops['Ced-Target-Name'] && $this->checkIfTokenExpireDateRange( $query, $shops ) );
        if ( ! $step_completed ) {
            $store_step_completed = $user_details->setConfigByKey('step_completed', 0, $userId);
            if ($store_step_completed['success']) {
                $step = 0;
            }
        } else {
            $step = $step_completed;
        }

        $sourceName  = ucfirst($shops['Ced-Source-Name'] ?? '');
        $targetName  = ucfirst($shops['Ced-Target-Name'] ?? '');
        $sourceClass = "\App\\{$sourceName}home\Models\SourceModel";
        $targetClass = "\App\\{$targetName}home\Models\SourceModel";
        $method      = 'getStepCompleted';
        $notice      = [];
        if (method_exists($sourceClass, $method)) {
            $notice = array_merge(
                $this->di->getObjectManager()->get($sourceClass)->$method($shops),
                $notice
            );
        }

        if (method_exists($targetClass, $method)) {
            $notice = array_merge(
                $this->di->getObjectManager()->get($targetClass)->$method($shops),
                $notice
            );
        }

        return [
            'success' => true,
            'data'    => $step,
            'user'    => $query['shops'][0]['domain'] ?? (
                $query['shops'][0]['storeurl'] ?? ($query['username'] ?? 'User')
            ),
            'shops'   => $shops,
            'notice'  => $notice
        ];
    }

    /**
     * Will check if token expire date range is matched.
     *
     * @param array $query array containing the current user data.
     * @since 1.0.0
     * @return boolean
     */
    public function checkIfTokenExpireDateRange( $query = [], &$params = null ) {
        $targetShopData = $query['shops'][1] ?? [];
        $tokenExpires   = $targetShopData['token_expires'] ?? false;
        $refreshExpires = $targetShopData['refresh_expires'] ?? false;
        if ( $tokenExpires && $refreshExpires ) {
            $current5Days = strtotime( gmdate( 'Y-m-d H:i:s', strtotime( '+5 days' ) ) );
            if ( $refreshExpires <= $current5Days ) {
                $params['Token-Warning']    = true;
                $params['Token-Expiration'] = gmdate( 'Y-m-d H:i:s', $refreshExpires );
            }
        }

        return false;
    }



    /*public function getActiveStep($path, $userId = false) {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }
        $step = 0;
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $step_completed = $user_details->getConfigByKey('step_completed', $userId);
        if(!$step_completed){
            $store_step_completed = $user_details->setConfigByKey('step_completed', 0, $userId);
            if($store_step_completed['success']) $step = 0 ;
        } else $step = $step_completed;

        return ['success' => true, 'data' => $step];
    }*/

    public function getProductsImportedData($importerData, $userId) {
        $importers = $importerData['importers'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('product_container_' . $userId);
        $productCount = [];
        foreach ($importers as $value) {
            $countFinalQuery = [
                [
                    '$unwind'=>'$variants'
                ],
                [
                    '$count' => 'count'
                ]
            ];
            $products = $collection->aggregate($countFinalQuery);
            $products = $products->toArray();
            $productCount[$value] = 0;
            $productCount[$value] = isset($products[0]) && isset($products[0]['count']) ? $products[0]['count'] : 0;
        }

        $productCount = [];
        $countFinalQuery = [
            [
                '$unwind'=>'$variants'
            ],
            [
                '$match' => [
                    '_id' => [
                        '$in' => $googleProductId
                    ]
                ]
            ],
            [
                '$count' => 'count'
            ]
        ];
        $products = $collection->aggregate($countFinalQuery);
        $products = $products->toArray();
        $productCount[$marketplace] = $products[0]['count'] ?? 0;
        return ['success' => true, 'data' => $productCount];
    }

    public function getProductsUploadedData($uploaderData, $userId = false) {
        $marketplace = $uploaderData['marketplace'];
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('product_container_' . $userId);
        $googleCollection = $mongo->getCollectionForTable('google_product_' . $userId);
        $googleProductId = $googleCollection->aggregate([
            [
                '$project' => [
                    '_id' => 1
                ]
            ]
        ]);
        $googleProductId = $googleProductId->toArray();
        foreach ($googleProductId as $key => $value) {
            $googleProductId[$key] = $value['_id'];
        }

        $productCount = [];
        $countFinalQuery = [
            [
                '$unwind'=>'$variants'
            ],
            [
                '$match' => [
                    '_id' => [
                        '$in' => $googleProductId
                    ]
                ]
            ],
            [
                '$count' => 'count'
            ]
        ];
        $products = $collection->aggregate($countFinalQuery);
        $products = $products->toArray();
        $productCount[$marketplace] = $products[0]['count'] ?? 0;
        return ['success' => true, 'data' => $productCount];
    }

    public function getInstalledConnectorDetails($connectorDetails) {
        $userId = $this->di->getUser()->id;
        foreach ($connectorDetails as $connectorKey => $connectorValue) {
            $connectorDetails[$connectorKey]['shops'] = $this->di->getObjectManager()->get($connectorValue['source_model'])->getShops($userId, true);
        }

        return ['success' => true, 'data' => $connectorDetails];
    }

    public function getTransactionLog() {
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('transaction_log');
        $transactionLog = $collection->find([
            "user_id" => (string)$userId
        ],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        $transactionLog = $transactionLog->toArray();

        $logList = [];
        for ($i = count($transactionLog) - 1; $i > -1 ; $i--) {
            $logList[] = $transactionLog[$i];
        }

        return ['success' => true, 'data' => $logList];
    }

    public function checkAccount($accountDetails) {
        if (is_array($accountDetails['code'])) {
            $marketplaces = $accountDetails['code'];
            $userId = $this->di->getUser()->id;
            $flag = true;
            foreach ($marketplaces as $marketplace) {
                if($marketplace == 'amazonimporter'){
                    $userConnector = Details::findFirst(["user_id='{$userId}' AND shop_id='{$this->di->getUser()->getShop()->getId()}'"]);
                } else if($marketplace == 'walmartimporter'){
                    $userConnector = \App\Walmartimporter\Models\Shop\Details::findFirst(["user_id='{$userId}'"]);
                } else {
                    $userConnector = Connector::findFirst(["code='{$marketplace}' AND user_id='{$userId}'"]);
                }

                if ($userConnector) {
                    $flag = false;
                }
            }

            if ( $flag ) {
                return ['success' => true, 'data' => [ 'account_connected' => false ]];
            }

            return ['success' => true, 'data' => [ 'account_connected' => true ]];
        }
        $marketplace = $accountDetails['code'];
        $userId = $this->di->getUser()->id;
        if($marketplace == 'morecommerce'){
            $userConnector = \App\Opensky\Models\Shop\Details::findFirst(["user_id='{$userId}'"]);
        } else {
            $userConnector = Connector::findFirst(["code='{$marketplace}' AND user_id='{$userId}'"]);
        }

        if ($userConnector) {
            return ['success' => true, 'data' => [ 'account_connected' => true ]];
        }

        return ['success' => true, 'data' => [ 'account_connected' => false ]];
    }

    public function checkDefaultConfiguration($accountDetails) {
        $marketplace = $accountDetails['code'];
        $userId = $this->di->getUser()->id;
        $marketplaceData = $this->di->getObjectManager()->get('\App\\' . ucfirst((string) $marketplace) . '\Models\SourceModel')->getMarketplaceAttributes($userId);
        $isFilled = true;
        foreach ($marketplaceData as $key => $value) {
            if ($value == '' ||
                $value == []) {
                $isFilled = false;
                if($key=="ageGroup" ||$key=="gender"  ||$key=="category"  ){
                    $isFilled = true;
                }
            }
        }

        $shopDetails = \App\Shopify\Models\Shop\Details::findFirst(["user_id='{$userId}'"]);
        $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable('user_service');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $data = $collection->findOne(['merchant_id' => (string)$userId,'code' => 'google_uploader'], $options);
        if ($shopDetails &&
            $data &
            count($data)) {
            $shop = $shopDetails->shop_url;
            $this->di->getObjectManager()->get('\App\Shopify\Components\Helper')->createNecessaryWebhooks($shop, $userId);
            $this->di->getObjectManager()->get('\App\Shopify\Components\Helper')->importCollections(['shop' => $shop]);
            $this->sendAccountLinkRequest($userId);
        }

        return ['success' => true, 'data' => ['configFilled' => $isFilled]];
    }

    public function sendAccountLinkRequest($userId)
    {
        $cedUserId = 3;
        $cedMerchantId = 123898449;
        $shopDetails = \App\Google\Models\Shop\Details::findFirst(["user_id='{$userId}'"]);
        if ($shopDetails) {
            $shopDetails = $shopDetails->toArray();
            $merchantId = $shopDetails['merchant_id'];
            try {
                /* First request */
                $helper = $this->di->getObjectManager()->get('\App\Google\Components\Helper');
                $client = $helper->getGoogleClient($cedUserId, $cedMerchantId);
                $service = new Google_Service_ShoppingContent($client);
                $accountLinkRequest = new Google_Service_ShoppingContent_AccountsLinkRequest();
                $accountLinkRequest->setAction('request');
                $accountLinkRequest->setLinkType('channelPartner');
                $accountLinkRequest->setLinkedAccountId($merchantId);
                $response = $service->accounts->link($cedMerchantId, $cedMerchantId, $accountLinkRequest);
                /* End of first request */

                /* Second request */

                $client = $helper->getGoogleClient($userId, $merchantId);
                $service = new Google_Service_ShoppingContent($client);
                $accountLinkRequest = new Google_Service_ShoppingContent_AccountsLinkRequest();
                $accountLinkRequest->setAction('approve');
                $accountLinkRequest->setLinkType('channelPartner');
                $accountLinkRequest->setLinkedAccountId($cedMerchantId);
                $response = $service->accounts->link($merchantId, $merchantId, $accountLinkRequest);
                /* End of second request */
                return true;
            } catch(Exception) {
                return false;
            }   
        }

        return false;
    }

    public function getGoogleOrderConfig() {
        $userId = $this->di->getUser()->id;
        $response = $this->di->getObjectManager()->get('\App\Google\Components\Helper')->getOrderConfigData($userId);
        return ['success' => true, 'data' => $response];
    }

    public function getShopDetails($userId = false) {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $shop = \App\Shopify\Models\Shop\Details::findFirst(["user_id='{$userId}'"]);
        if ($shop) {
            $shop = $shop->toArray();
            $shop = $shop['shop_url'];
            $userId = $this->di->getUser()->id;
            $shopData = $this->di->getObjectManager()->get('\App\Shopify\Components\EngineHelper')->getShopDetails($userId, $shop);
            if ($shopData) {
                $email = '';
                $name = '';
                if (isset($shopData['customer_email']) &&
                    $shopData['customer_email'] != '') {
                    $email = $shopData['customer_email'];
                } elseif (isset($shopData['email']) &&
                    $shopData['email'] != '') {
                    $email = $shopData['email'];
                }

                if (isset($shopData['shop_owner']) &&
                    $shopData['shop_owner'] != '') {
                    $name = $shopData['shop_owner'];
                } elseif (isset($shopData['name']) &&
                    $shopData['name'] != '') {
                    $name = $shopData['name'];
                }

                if (isset($shopData['country']) &&
                    $shopData['country'] != '') {
                    $country = $shopData['country'];
                } else {
                    $country = 'US';
                }

                if (isset($shopData['country_name']) &&
                    $shopData['country_name'] != '') {
                    $countryName = $shopData['country_name'];
                } else {
                    $countryName = 'United States';
                }

                if (isset($shopData['currency']) &&
                    $shopData['currency'] != '') {
                    $currency = $shopData['currency'];
                } else {
                    $currency = 'USD';
                }

                if (isset($shopData['domain']) &&
                    $shopData['domain'] != '') {
                    $domain = $shopData['domain'];
                } else {
                    $domain = false;
                }

                $data = [
                    'email' => $email,
                    'full_name' => $name,
                    'mobile' => $shopData['phone'] ?? null,
                    'country' => $country,
                    'shop_url'=>$shop,
                    'country_name' => $countryName,
                    'currency' => $currency,
                    'domain' => $domain
                ];
                return ['success' => true, 'data' => $data];
            }
        }

        return ['success' => false, 'message' => 'No shop details found'];
    }

    public function userType($data) {
        $userId = $this->di->getUser()->id;
        $usertype = $data['usertype'];

        $shop=$this->di->getUser()->getShop();
        if($shop){
            $shop = $shop->getData();
            $shopId = $shop['id'];
        } else {
            $shopId = 0;
        }

        $this->di->getObjectManager()->get('\App\Core\Models\User\Config')->setForShop($userId,$shopId,'usertype', $usertype, 'global');
        return ['success' => true, 'code' => 'account_type_changed', 'message' => 'Account Type Changed'];
    }

    public function getImportedProductCount($importerData, $userId) {
        $importers = $importerData['importers'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("product_container_".$userId);

        $collection = $mongo->getCollection();
        $productCount = [];
        if ( is_array($importers) ) {
            foreach ($importers as $value) {
                $productCount[$value] = $collection->count([
                    'source_marketplace' => $value
                ]);
            }
        } else {
            if ( $importers == 'oberlosupply' ) {
                $mongo->setSource("product_container_".$userId);
                $collection = $mongo->getCollection();
                $productCount['oberlosupply'] = $collection->count() ;
            }

            if ( $importers == 'morecommerce' ) {
                $mongo->setSource("product_container_".$userId);
                $collection = $mongo->getCollection();
                $productCount['morecommerce'] = $collection->count() ;
            }
        }

        return ['success' => true, 'data' => $productCount];
    } // for Omni Imported

    public function getUploadedProductsCount($uploaderData, $shopId) {
        $user_id = $this->di->getUser()->id;
        $marketplace = $uploaderData['marketplace'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo2 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        if ( $marketplace == 'oberlosupply' ) {
            $mongo->setSource("product_container_".$user_id);
            $collection = $mongo->getCollection();
            if ( $collection->count() <= 0 ) {
                return ['success' => false, 'message' => 'No Product Uploaded'];
            }

            $count = $collection->count(['details.oberlo_id' => ['$exists' => true]]);
            return ['success' => true, 'data' => ['oberlosupply' => $count]];
        }
        if ( $marketplace == 'morecommerce' ) {
            $mongo->setSource("product_container_".$user_id);
            $collection = $mongo->getCollection();
            if ( $collection->count() <= 0 ) {
                return ['success' => false, 'message' => 'No Product Uploaded'];
            }

            $count = $collection->count(['details.opensky_id' => ['$exists' => true]]);
            return ['success' => true, 'data' => ['morecommerce' => $count]];
        }
        $mongo->setSource("shopify_product_upload_".$shopId);
        $mongo2->setSource("shopify_product_delete_".$user_id);
        $collection = $mongo->getCollection();
        $collection2 = $mongo2->getCollection();
        $data = $collection->aggregate([
            [
                '$group' => [
                    '_id' => '$source',
                    'count' => ['$sum' => 1]
                ]
            ]
        ])->toArray();
        if ( $collection2->count() )
            $data[] = [
                '_id' => 'shopify_delete',
                'count' => $collection2->count()
            ];

        return ['success' => true, 'data' => $data];
    } // for Omni Importer

    public function getOrdersHelper($orderParam, $userId) {
        $marketplace = $orderParam['marketplace'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_'.$userId);
        $orderList = $collection->find();
        $orderList = $orderList->toArray();

        $Orders = [];
        foreach ($orderList as $listValue) {
            foreach ($listValue['variants'] as $key => $value) {
                $Orders[$key] = $value;
            }
        }

        return ['success' => true, 'data' => array_values($Orders)];
    }

    public function getAttributesForItemId() {
        $attributes = [
            [
                'attribute' => 'targetCountry',
                'parent_attribute' => 'marketplace_attribute',
                'static_text' => ''
            ],
            [
                'attribute' => 'contentLanguage',
                'parent_attribute' => 'marketplace_attribute',
                'static_text' => ''
            ],
            [
                'attribute' => 'source_product_id',
                'parent_attribute' => 'details',
                'static_text' => ''
            ],
            [
                'attribute' => 'title',
                'parent_attribute' => 'details',
                'static_text' => ''
            ],
            [
                'attribute' => 'source_variant_id',
                'parent_attribute' => 'variants',
                'static_text' => ''
            ],
            [
                'attribute' => 'sku',
                'parent_attribute' => 'variants',
                'static_text' => ''
            ]
        ];
        return ['success' => true, 'data' => $attributes];
    }

    public function getCurrentItemIdFormat() {
        $userId = $this->di->getUser()->id;
        $itemIdFormat = $this->getItemIdFormat($userId);
        if ($itemIdFormat) {
            return ['success' => true, 'data' => $itemIdFormat];
        }

        return ['success' => false, 'data' => 'No item id format saved'];
    }

    public function setCurrentItemIdFormat($data) {
        $userId = $this->di->getUser()->id;
        $itemIdFormat = $data['item_id_format'];
        $status = $this->setItemIdFormat($itemIdFormat, $userId);
        if ($status) {
            return ['success' => true, 'message' => 'Item ID format successfully updated. Now your products will be uploaded with this item ID format.'];
        }

        return ['success' => false, 'message' => 'Failed to save. Internal server error.'];
    }

    public function getItemIdFormat($userId) {
        $itemIdFormat = $this->di->getObjectManager()->get('\App\Core\Models\User\Config')->getConfigByPath($userId, '/App/Google/CustomItemId', 'google');
        if ($itemIdFormat) {
            return json_decode((string) $itemIdFormat['value'], true);
        }

        return false;
    }

    public function setItemIdFormat($idFormat, $userId) {
        $status = $this->di->getObjectManager()->get('\App\Core\Models\User\Config')->set($userId, '/App/Google/CustomItemId', json_encode($idFormat), 'google');
        return $status;
    }

    public function getShopID($data) {
        if ( !isset($data['marketplace']) || !isset($data['source']) )
            return ['success' => false, 'message' => 'required parameter missing'];

        $userId = $data['user_id'];
        $shopId = $data['shop_id'];
        $source_shop_path = false;
        if ( $data['source'] == 'amazonimporter' || $data['source'] == 'amazon_importer') {
            $source_shop_path = 'Amazonimporter';
        } else if ( $data['source'] == 'ebayimporter'  || $data['source'] == 'ebay_importer') {
            $source_shop_path = 'Ebayimporter';
        } else if ( $data['source'] == 'walmartimporter'  || $data['source'] == 'walmart_importer') {
            $source_shop_path = 'Walmartimporter';
        } else if ( $data['source'] == 'etsyimporter'  || $data['source'] == 'etsy_importer') {
            $source_shop_path = 'Etsyimporter';
        } else if ( $data['source'] == 'amazonaffiliate') {
            $source_shop_path = 'Amazonaffiliate';
        } else {
            $source_shop_path = ucfirst((string) $data['source']);
        }

        if ( $data['source'] == 'fileimporter' || $data['source'] == 'ebayaffiliate' ) {
            return ['success' => true, 'data' => ['source_shop_id' => $userId, 'target_shop_id' => $shopId]];
        }

        if ( $source_shop_path ) {
            $source_shop = $this->di->getObjectManager()->get('\App\\'. $source_shop_path .'\\Models\Shop\Details');
            $source_shop_id = $source_shop::findFirst([
                "conditions" => "user_id='{$userId}' AND shop_id='{$shopId}'"
            ]);
            if ( $source_shop_id ) {
                $source_shop_id = $source_shop_id->toArray();
                return ['success' => true, 'data' => ['source_shop_id' => $source_shop_id['id'], 'target_shop_id' => $shopId]];
            }
            return ['success' => false, 'message' => 'No Data Found'];
        }
        return ['success' => false, 'message' => 'required path missing'];
    }

    public function getOrderAnalytics() {
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_' . $userId);
        $cancelledCount = $collection->aggregate([
            [
                '$match' => [
                    '$and' => [
                        ["status" => "canceled"]
                    ]
                ]
            ],
            [
                '$count' => 'count'
            ]
        ]);
        $cancelledCount = $cancelledCount->toArray();

        $cancelledOrders = count($cancelledCount) ? $cancelledCount[0]['count'] : 0;
        $unfulfilledCount = $collection->aggregate([
            [
                '$match' => [
                    '$and' => [
                        [
                            'status' => [
                                '$ne' => "canceled"
                            ]
                        ],
                        [
                            "target_shop_id" => [
                                '$ne' => ''
                            ]
                        ],
                        [
                            "fulfillments" => [
                                '$exists' => false
                            ]
                        ]
                    ]
                ]
            ],
            [
                '$count' => 'count'
            ]
        ]);
        $unfulfilledCount = $unfulfilledCount->toArray();

        $unfulfilledOrders = count($unfulfilledCount) ? $unfulfilledCount[0]['count'] : 0;
        $failedCount = $collection->aggregate([
            [
                '$match' => [
                    '$and' => [
                        [
                            'status' => [
                                '$ne' => "canceled"
                            ]
                        ],
                        [
                            "target_shop_id" => ''
                        ]
                    ]
                ]
            ],
            [
                '$count' => 'count'
            ]
        ]);
        $failedCount = $failedCount->toArray();

        $failedOrders = count($failedCount) ? $failedCount[0]['count'] : 0;
        $fulfilledCount = $collection->aggregate([
            [
                '$match' => [
                    '$and' => [
                        [
                            'status' => [
                                '$ne' => "canceled"
                            ]
                        ],
                        [
                            "fulfillments" => [
                                '$exists' => true
                            ]
                        ]
                    ]
                ]
            ],
            [
                '$count' => 'count'
            ]
        ]);
        $fulfilledCount = $fulfilledCount->toArray();

        $fulfilledOrders = count($fulfilledCount) ? $fulfilledCount[0]['count'] : 0;
        $data = [
            'fulfilled_orders' => $fulfilledOrders,
            'unfulfilled_orders' => $unfulfilledOrders,
            'cancelled_orders' => $cancelledOrders,
            'failed_orders' => $failedOrders
        ];
        return ['success' => true, 'data' => $data];
    }

    public function getSKUCount($data) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource('product_container_'.$data['user_id']);

        $collection = $mongo->getCollection([]);
        $count_main = $collection->count([
            'details.source_product_id' => [
                '$in' => $data['list_ids']
            ]
        ]);
        $response = $collection->aggregate(
            [
                [
                    '$match' => [
                        'details.source_product_id' => [
                            '$in' => $data['list_ids']
                        ]
                    ]
                ],
                [
                    '$unwind' => '$variants'
                ],
                [
                    '$count' => 'count'
                ]
            ],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        );
        $response = $response->toArray();

        $count = $response[0]['count'] ?: 0;
        return ['success' => true, 'data' => ['parent' => $count_main, 'child' => $count]];
    }

    public function getNecessaryDetails($data)
    {
        $mongo = new BaseMongo();
        $mongo->initialize();
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongo->setSource('user_service');

        $collection = $mongo->getCollection();
        $user_service = $collection->find(['merchant_id' => (string)$data['user_id']])->toArray();

        $user_connector =  Connector::find(["user_id='{$data['user_id']}'"]);

        $mongo->setSource('product_container_'.$data['user_id']);
        $collection = $mongo->getCollection();
        $import_product_count = $collection->count();

        $shopId = $this->di->getUser()->getShop()->id;
        $mongo->setSource('shopify_product_upload_'.$shopId);
        $collection = $mongo->getCollection();
        $upload_product_count = $collection->count();

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shopify_details = $user_details->getDataByUserID($data['user_id'], 'shopify');

        return [
            'success' => true,
            'services' => $user_service,
            'account_connected' => $user_connector,
            'import_count'=> $import_product_count,
            'upload_count'=>$upload_product_count,
            'shop_url' => $shopify_details['myshopify_domain'],
            'shop_owner' => $shopify_details['shop_owner'],
            'form_details' => $shopify_details['form_details'],
        ];
    }

    public function getActiveRecurrying()
    {
        $userId = $this->di->getUser()->id;
        $activeRecurrying = $this->getActiveRecurryingDetails($userId);
        if ($activeRecurrying) {
            return ['success' => true, 'data' => $activeRecurrying];
        }

        return ['success' => false, 'message' => 'No active recurrying found.'];
    }

    public function getActiveRecurryingDetails($userId)
    {
        $shopData = \App\Shopify\Models\Shop\Details::findFirst(["user_id='{$userId}'"]);
        if ($shopData) {
            $shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
                ['type' => 'parameter', 'value' => $shopData->shop_url], ['type' => 'parameter', 'value' => $shopData->token], ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->auth_key], ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->secret_key]
            ]);
            $response = $shopifyClient->call('GET', '/admin/recurring_application_charges.json');
            $activeRecurrying = false;
            foreach ($response as $value) {
                if (isset($value['status']) && $value['status'] == 'active') {
                    $activeRecurrying = $value;
                    break;
                }
            }

            return $activeRecurrying;
        }

        return false;
    }

    public function getServiceCredits($data = [])
    {
        $userId = $this->di->getUser()->id;
        $serviceCode = 'google_uploader';
        if (isset($data['service_code'])) {
            $serviceCode = $data['service_code'];
        }

        $serviceHelper = $this->di->getObjectManager()->get('\App\Connector\Components\Services');
        $credits = [];
        $service = $serviceHelper->getServiceModel($serviceHelper->getByCode($serviceCode), $userId);
        $credits = [
            'available_credits' => $service->getAvailableCredits(),
            'total_used_credits' => $service->getUsedCredits()
        ];
        return ['success' => true, 'data' => $credits];
    }

    public function updateYearlyPlan($data)
    {
        $userId = $data['merchant_id'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('yearly_plans');
        $response = $collection->findOne([
            'merchant_id' => $userId
        ]);
        if ($data['activation_date'] != '') {
            $data['expiration_date'] = $this->getPlanExpirationDate($data['activation_date'], $data['period']);
        }

        if ($response &&
            count($response)) {
            $status = $collection->findOneAndUpdate([
                'merchant_id' => $userId
            ], [
                '$set' => $data
            ]);
        } else {
            $status = $collection->insertMany([$data]);
        }

        if ($status) {
            return ['success' => true, 'message' => 'Payment plan updated for the merchant.'];
        }

        return ['success' => false, 'message' => 'Failed to update payment plan.'];
    }

    public function getPlanExpirationDate($activationDate, $period)
    {
        $activationTimestamp = strtotime((string) $activationDate);
        $expirationTimestamp = strtotime('+' . (int)$period . ' day', $activationTimestamp);
        $expirationDate = date('d/m/Y H:i:s', $expirationTimestamp);
        return $expirationDate;
    }

    public function getYearlyPlan()
    {
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('yearly_plans');
        $response = $collection->findOne([
            'merchant_id' => (string)$userId
        ], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if ($response &&
            count($response)) {
            unset($response['_id']);
            return ['success' => true, 'data' => $response];
        }

        return ['success' => false, 'message' => 'No yearly plan found for this merchant.'];
    }

    public function getAllYearlyPlans()
    {
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('yearly_plans');
        $response = $collection->find([]);
        $response = $response->toArray();
        if ($response &&
            count($response)) {
            return ['success' => true, 'data' => $response];
        }

        return ['success' => false, 'message' => 'No yearly plans found.'];
    }

    public function giftServices($filter)
    {
        $userId=$this->di->getUser()->getId();
        $services = $this->di
            ->getObjectManager()
            ->get('App\Connector\Components\Services')
            ->getWithFilter($filter,$userId);
        if(count($services)>0) {
            $modified_services = $this->getrequiredUploaderServices($services);
            if(count($modified_services)>0) {
                $insert_services = $this->di
                    ->getObjectManager()
                    ->get('App\Shopify\Components\HookActions')->insertServices($modified_services, $userId);
                return ['success' => true, 'message' => 'All Services Added'];
            }
            return ['success' => false, 'message' => 'Services not Found'];
        }
        return ['success' => false, 'message' => 'Services Does not exists'];
    }

    public function getrequiredUploaderServices($services)
    {
        $temparr=[];
        foreach($services as $service => $value){

            if($service=='bing_uploader' || $service=='google_uploader' || $service=='facebook_uploader'){
                $value['prepaid']['service_credits']="10000";
                $temparr[$service]=$value;
            }

        }

        return $temparr;
    }

    public function getDateRange($days_in_month=false,$date = null,$time_line = null)
    {

        switch($time_line) {
            case 'weekly':
//                $days_in_between=42;
//                $date_six_weeks_back=date('Y-m-d',strtotime('last monday',strtotime("-6 week")));
//                $date_six_weeks_back_sunday=date('Y-m-d',strtotime('next sunday',strtotime("-6 week")));

//
//                print_r($date_six_weeks_back."\n");
//                print_r($date_six_weeks_back_sunday."\n");
//
//                die('test');

                $temparr=[];
                $i=5;
                while($i>=0){
                    if($i>0) {
                        $start_date = date('Y-m-d', strtotime('last monday', strtotime("-" . $i . " week")));
                        $end_date = date('Y-m-d',strtotime('next sunday',strtotime("-" . $i . " week")));
                    }
                    else{
                        $start_date = date('Y-m-d', strtotime('last monday', strtotime((string) $date)));
                        $end_date = date('Y-m-d',strtotime('next sunday',strtotime((string) $date)));
                    }

                    array_push($temparr, ['start' => $start_date, 'end' => $end_date]);
                    $i--;
                }

//                $temparr = [];
//                $i = 1;
//
//                while ($i <= $days_in_month) {
//                    $start_date = date('Y-m-' . $i, strtotime($date));
//                    if (($i + 6) < $days_in_month) {
//                        $end_date = date('Y-m-' . ($i + 6), strtotime($date));
//                    } else {
//                        $end_date = date('Y-m-' . ($days_in_month), strtotime($date));
//                    }
//                    array_push($temparr, ['start' => $start_date, 'end' => $end_date]);
//                    if (($i + 7) < $days_in_month) {
//                        $i += 7;
//                    } else {
//                        $i = $days_in_month + 1;
//                    }
//                }
                return $temparr;
                break;
            case 'monthly':
                $year=date('Y',strtotime((string) $date));
                $temparr = [];
                $i = 1;
                while ($i <= 12) {
                    $days_in_month=date('t',strtotime($year.'-'.$i.'-1'));
                    $start_date = date('Y-'.$i.'-1', strtotime((string) $date));
                    $end_date = date('Y-'.$i.'-'.$days_in_month, strtotime((string) $date));

                    array_push($temparr, ['start' => $start_date, 'end' => $end_date]);
                    $i++;
                }

                return $temparr;

                break;
            case 'yearly':
                $year=date('Y',strtotime((string) $date));

                $temparr = [];
                $i = 2018;
                while ($i <= $year) {
                    $start_date = date($i.'-1-1', strtotime((string) $date));
                    $end_date = date($i.'-12-31', strtotime((string) $date));

                    array_push($temparr, ['start' => $start_date, 'end' => $end_date]);
                    $i++;
                }

                return $temparr;

                break;

        }

    }

    public function getOrderTimeStats($date,$type)
    {
        $temp_array=[];
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("order_".$userId);

        $collection = $mongo->getCollection();
        $month=date('n',strtotime((string) $date));
        $days_in_month=date('t',strtotime((string) $date));
        $range_recieved=$this->getDateRange($days_in_month,$date,$type);
        foreach($range_recieved as $range){
            $start_format=(new DateTime($range['start']))->format('Y-m-d\TH:i:s\Z');
            $end_format=(new DateTime($range['end']))->format('Y-m-d\T23:59:59\Z');
            $count_orders=$collection->aggregate(
                [
                    [
                        '$match' => [
                            'placed_at' => [
                                '$gte' => $start_format
                                ,
                                '$lte' => $end_format
                            ]

                        ],

                    ],
                    [
                        '$count' => 'count'
                    ]
                ],
                ["typeMap" => ['root' => 'array', 'document' => 'array']]
            )->toArray();

            if(!isset($count_orders[0]['count']))
            {
                $count_orders=0;
            }
            else{
                $count_orders=$count_orders[0]['count'];
            }

            $temp_array[]=['start'=>$range['start'],'end'=>$range['end'],'count'=>$count_orders];

        }

        return $temp_array;

    }

    public function getOrderDatewise($timeline)
    {

        if(isset($timeline['timeline'])) {
            if($timeline['timeline']=='yearly' || $timeline['timeline']=='monthly' || $timeline['timeline']=='weekly' ) {
                $time_line = $timeline['timeline'];
            }
            else{
                return ['success'=>false,'message'=>'Incorrect Timeline specified','code'=>'TIMELINE_NOT_VALID'];
            }
        }
        else{
            return ['success'=>false,'message'=>'Timeline not specified','code'=>'TIMELINE_NOT_FOUND'];
        }

        $date=new DateTime();
        $date=$date->format('Y-m-d\TH:i:s.u\Z');

        $base_year = new DateTime('01-01-2018');

        switch($time_line){
            case 'weekly':
                $response=$this->getOrderTimeStats($date,$time_line);
                break;
            case 'monthly':
                $response=$this->getOrderTimeStats($date,$time_line);
                break;
            case 'yearly':
                $response=$this->getOrderTimeStats($date,$time_line);
                break;
            default : return ['success'=>false,'message'=>'Incorrect Timeline specified','code'=>'TIMELINE_NOT_VALID'];
                break;
        }

        return ['success'=>'success','message'=>'Order Stats recieved','code'=>'ORDER_STATS','data'=>$response];

    }

    public function getDateRangeRevenue($date,$months)
    {
        $temparr=[];
        $current_date_year=date('Y-m-d',strtotime((string) $date));
        $year_giventime_back=date('Y-m-d',strtotime("-" . $months . " month"));
        array_push($temparr, ['start' => $year_giventime_back, 'end' => $current_date_year]);
        return $temparr;

    }

    public function getOrderRevenueRangewise($timeline)
    {

        if(isset($timeline['timeline'])) {
            if($timeline['timeline']>=1 && $timeline['timeline']<=12) {
                $time_line = $timeline['timeline'];
            }
            else{
                return ['success'=>false,'message'=>'Incorrect Timeline specified','code'=>'TIMELINE_NOT_VALID'];
            }
        }
        else{
            return ['success'=>false,'message'=>'Timeline not specified','code'=>'TIMELINE_NOT_FOUND'];
        }

        $date=new DateTime();
        $date=$date->format('Y-m-d\TH:i:s.u\Z');

        $response=$this->getOrderRevenueStats($date,$time_line);
        return ['success'=>'success','message'=>'Order Stats recieved','code'=>'ORDER_STATS','data'=>$response];

    }

    public function getOrderRevenueStats($date,$months)
    {

        $temp_array=[];
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("order_".$userId);

        $collection = $mongo->getCollection();

        $range_recieved=$this->getDateRangeRevenue($date,$months);

        foreach($range_recieved as $range){
            $start_format=(new DateTime($range['start']))->format('Y-m-d\TH:i:s\Z');
            $end_format=(new DateTime($range['end']))->format('Y-m-d\T23:59:59\Z');

            $total_revenue_range=$collection->aggregate(
                [
                    [
                        '$match' => [
                            'placed_at' => [
                                '$gte' => $start_format
                                ,
                                '$lte' => $end_format
                            ],
                            'fulfillments' => [
                                '$exists' => true,
                                '$not'=>[
                                    '$size'=> 0
                                ]
                            ],
                        ],


                    ],
                    [
                        '$group' => [
                            '_id'=>null,
                            'revenue' => [
                                '$sum'=> ['$toDouble'=> '$total_price']
                            ]
                        ]
                    ],
                ],
//                line_items,fulfillments
                ["typeMap" => ['root' => 'array', 'document' => 'array']]
            )->toArray();

            $total_revenue=$collection->aggregate(
                [
                    [
                        '$match' => [
                            'placed_at' => [
                                '$lte' => $end_format
                            ],
                            'fulfillments' => [
                                '$exists' => true,
                                '$not'=>[
                                    '$size'=> 0
                                ]
                            ],
                        ],


                    ],
                    [
                        '$group' => [
                            '_id'=>null,
                            'revenue' => [
                                '$sum'=> ['$toDouble'=> '$total_price']
                            ]
                        ]
                    ],
                ],
//                line_items,fulfillments
                ["typeMap" => ['root' => 'array', 'document' => 'array']]
            )->toArray();

            if(!isset($total_revenue[0]['revenue']) ||  $total_revenue[0]['revenue']==0)
            {
                $totalrevenue=0;
            }
            else{
                $totalrevenue=$total_revenue[0]['revenue'];
            }



            if(!isset($total_revenue_range[0]['revenue']) ||  $total_revenue_range[0]['revenue']==0)
            {
                $revenue=0;
            }
            else{
                $revenue=$total_revenue_range[0]['revenue'];
            }

            $temp_array[]=['start'=>$range['start'],'end'=>$range['end'],'revenue'=>$revenue ,'total_revenue'=>$totalrevenue];

        }

        return $temp_array;

    }

    public function newsFromData($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("express_news_data");

        $collection = $mongo->getCollection();
        $new_data = [
            "_id"=>uniqid(),
            "title" => $data['data'][0]["title"] ?? null,
            "description" => $data['data'][1]["description"] ?? null,
            "image_url" => $data['data'][2]['imageurl'] ?? null,
            "content_link" => $data['data'][3]['content_link'] ?? null
        ];
        if ($new_data['title'] != null||$new_data['description'] != null||$new_data['image_url'] != null||$new_data['content_link'] != null) {
            $collection->insertOne($new_data);
        }

        $fetch_data = $collection->find()->toArray();
        return $fetch_data;
    }

    public function deleteData($object_id)
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("express_news_data");

        $collection = $mongo->getCollection();
        $collection->deleteOne(['_id' =>$object_id['data']]);
        return true;
    }

    public function addBlogs($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("express_blogs_data");

        $collection = $mongo->getCollection();
        $new_data = [
            "_id"=>uniqid(),
            "title" => $data['data'][0]["title"] ?? null,
            "description" => $data['data'][1]["description"] ?? null,
            "image_url" => $data['data'][2]['imageurl'] ?? null,
            "content_link" => $data['data'][3]['content_link'] ?? null
        ];
        if ($new_data['title'] != null||$new_data['description'] != null||$new_data['image_url'] != null||$new_data['content_link'] != null) {
            $collection->insertOne($new_data);
        }

        $fetch_data = $collection->find()->toArray();
        return $fetch_data;
    }

    public function deleteBlogData($object_id)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("express_blogs_data");

        $collection = $mongo->getCollection();
        $collection->deleteOne(['_id' =>$object_id['data']]);
        return true;
    }

    public function saveLastLoginDetails($userId = false, $time = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('login_log');
        $response = $collection->findOneAndUpdate([
                "user_id" => (string)$userId
            ], [
                '$set' => [
                    "user_id" => (string)$userId,
                    "last_login" => $time ?: date("d-m-y h:i:s")
                ]
            ], [
                "upsert" => true
            ]);
        return true;
    }

    public function getLastLoginDetails($userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('login_log');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $response = $collection->findOne([
                "user_id" => (string)$userId
            ], $options);
        if ($response && count($response)) {
            return ['success' => true, 'data' => $response];
        }

        return ['success' => false, 'message' => 'No last login found'];
    }

    public function changeStep($data): void
    {
        $val=$data['step'];
        $userId = $data['user_Id'];
        $baseModel = $this->di->getObjectManager()->get('App\Core\Models\Base');
        $connection = $baseModel->getDbConnection();
        $connection->query("UPDATE `user_config_data` SET `value` ='".$val."' WHERE `user_config_data`.`user_id` = '".$userId. "'AND `user_config_data`.`path` ='/App/User/Step/ActiveStep'");
    }

    public function addFaq($rawBody){

        $type = $rawBody['type'];
        $question = $rawBody['question'];
        $answer = $rawBody['answer'];
        $id  = $rawBody['id'];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('express_faqs');

         $response =  $collection->insertOne([
             '_id'=>$id,
            'type'=>$type,
            'question'=>$question,
            'answer'=>$answer
        ],["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if($response){
            return ['success'=>true,'message'=>'Inserted'];
        }
        return ['success'=>false,'message'=>'Something nasty happen.'];

    }

    public function editFaq($rawBody){

        $type = $rawBody['type'];
        $question = $rawBody['question'];
        $answer = $rawBody['answer'];
        $id  = $rawBody['id'];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('express_faqs');

        $response =  $collection->findOneAndUpdate(
            ['_id'=>$id,],
            ['$set'=>['type'=>$type, 'question'=>$question, 'answer'=>$answer]],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]);

        if($response){
            return ['success'=>true,'message'=>'Updated'];
        }
        return ['success'=>false,'message'=>'Something nasty happen.'];

    }

    public function deleteFaq($rawBody){

        $id  = $rawBody['id'];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('express_faqs');

        $response =  $collection->findOneAndDelete(
            ['_id'=>$id,],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]);

        if($response){
            return ['success'=>true,'message'=>'Deleted'];
        }
        return ['success'=>false,'message'=>'Something nasty happen.'];

    }

    public function beforeHandleRequest(Event $event, \Phalcon\Mvc\Application $application)
    {
        try {
            $this->di = $application->di;
            $URI = $application->request->getURI();
            $parts = explode('/', trim($URI, '/'));
            $action = end($parts);
            $config = $this->di->getConfig()->get('log_user_activity')->toArray();
            if (isset($config[$URI])) {
                if ($config[$URI] === true) {
                    $rawBody = $application->request->getJsonRawBody(true);
                    $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog($action, $action, $rawBody);
                }
            }
            return true;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception beforeHandleRequest(): ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }
}
?>