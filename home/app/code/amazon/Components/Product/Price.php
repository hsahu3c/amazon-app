<?php


namespace App\Amazon\Components\Product;

use App\Amazon\Components\LocalDeliveryHelper;
use App\Core\Components\Base;
use App\Amazon\Components\Common\Helper;
use Exception;

class Price extends Base
{
    private ?string $_user_id = null;


    private $_baseMongo;

    private $configObj;

    private $customPriceSyncRules = [];

    const CUSTOM_PRICE_SYNC_FIELDS_ENABLED = 'custom_price_sync_fields_enabled';
    const CUSTOM_PRICE_SYNC_FIELDS = 'custom_price_sync_fields';
    const CUSTOM_SALE_PRICE_DATES = 'custom_sale_price_dates';
    const CUSTOM_PRICE_APPLY_TEMPLATE = 'custom_price_apply_template';
    const CUSTOM_SALE_DURATION = 'custom_sale_duration';
    const CUSTOM_GROUP_CODE = 'custom_mappings';

    const CUSTOM_PRICE_SYNC_SETTINGS = [
        self::CUSTOM_PRICE_SYNC_FIELDS_ENABLED,
        self::CUSTOM_PRICE_SYNC_FIELDS,
        self::CUSTOM_SALE_PRICE_DATES,
        self::CUSTOM_PRICE_APPLY_TEMPLATE,
        self::CUSTOM_SALE_DURATION
    ];

    public function init($request = [])
    {
        if (isset($request['user_id'])) $this->_user_id = (string)$request['user_id'];
        else $this->_user_id = (string)$this->di->getUser()->id;
        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $this->configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');

        return $this;
    }

    public function upload($sqsData , $localDelivery = false)
    {
        $feedContent = [];
        $response = [];
        $addTagProductList = [];
        $productErrorList = [];
        $activeAccounts = [];
        $container_ids = [];
        $alreadySavedActivities = [];
        $multiplier = false;
        $date = date('d-m-Y');
        $currencyValue = "";
        $jsonFeedPrice = [];
        $processType = $sqsData['data']['params']['process_type'] ?? 'manual';


        $data = json_decode(json_encode($sqsData, true), true);
        $productComponent = $this->di->getObjectManager()->get(Product::class);

        if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
            $userId = $data['data']['params']['user_id'];
            $targetShopId = $data['data']['params']['target']['shopId'];
            $sourceShopId = $data['data']['params']['source']['shopId'];
            $targetMarketplace = $data['data']['params']['target']['marketplace'];
            $sourceMarketplace = $data['data']['params']['source']['marketplace'];
            $specificData = $data['data']['params'];
            $specificData['type'] = 'price';
            $logFile = "amazon/{$userId}/{$sourceShopId}/SyncPrice/{$date}.log";
            $inventoryComponent = $this->di->getObjectManager()->get(Inventory::class);
            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $targetShop = $user_details->getShop($targetShopId, $userId);
            $currencyValue = $targetShop['currency'] ?? "";
            $merchantName = false;
            $merchantData = false;
            $merchantDataSourceProduct = false;
            $commonHelper = $this->di->getObjectManager()->get(Helper::class);

            $allProfileInfo = $data['data']['all_profile_info'] ?? [];
            if($localDelivery)
            {
                $merchantName = $data['data']['params']['ld_source_product_ids'] ?? false;
            }

            foreach($targetShop['warehouses'] as $warehouse){
                if($warehouse['status'] == "active"){
                    $activeAccounts = $warehouse;
                    $specificData['marketplaceId'] = $warehouse['marketplace_id'];
                }
            }


            if (!empty($activeAccounts)) {
                $uniqueKey =(string)$userId . 'target_id' . $targetShopId . 'source_id' . $sourceShopId;
                $data['uniqueKeyForCurrency'] = $uniqueKey;
                $connector = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
                $currencyCheck = $connector->checkCurrencyWithCache($userId, $targetMarketplace, $sourceMarketplace, $targetShopId, $sourceShopId, $uniqueKey);
                $this->configObj->setUserId($userId);
                $this->configObj->setTarget($targetMarketplace);
                $this->configObj->setTargetShopId($targetShopId);
                $this->configObj->setSourceShopId($sourceShopId);
                $this->configObj->setGroupCode('currency');

                $amazonCurrency = $this->configObj->getConfig('target_currency');
                $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);
                if (!$currencyCheck) {
                    $sourceCurrency = $this->configObj->getConfig('source_currency');
                    $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);

                    if (isset($sourceCurrency[0]['value'] , $amazonCurrency[0]['value']) && $sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
                        $currencyCheck = true;
                    } else {
                        $amazonCurrencyValue = $this->configObj->getConfig('target_value');
                        $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
                        if (isset($amazonCurrencyValue[0]['value'])) {
                            $currencyCheck = $amazonCurrencyValue[0]['value'];
                        }
                    }
                }

                if ($currencyCheck) {
                    $this->customPriceSyncRules = $this->getPriceSyncRulesConfig($userId, $sourceShopId, $targetShopId);
                    foreach ($data['data']['rows'] as $key => $value) {
                        $productComponent->removeTag([$value['source_product_id']], [Helper::PROCESS_TAG_PRICE_SYNC], $targetShopId, $sourceShopId, $userId, [], []);
                        $products = $data['data']['rows'];
                        if(isset($value['profile_info']['$oid']) && is_string($value['profile_info']['$oid']) && !empty($allProfileInfo[$value['profile_info']['$oid']])) {
                            $value['profile_info'] = $allProfileInfo[$value['profile_info']['$oid']];
                        }
                        $priceTemplate = $this->prepare($value);
                        if (isset($value['parent_details']) && (is_string($value['parent_details']) || is_int($value['parent_details'])) && isset($data['data']['all_parent_details'][$value['parent_details']])) {
                            $value['parent_details'] = $data['data']['all_parent_details'][$value['parent_details']];
                        }
                        $container_ids[$value['source_product_id']] = $value['container_id'];
                        $alreadySavedActivities[$value['source_product_id']] = $value['edited']['last_activities'] ?? [];
                        if ($value['type'] == 'variation') {
                            continue;
                        }
                        $target = [];
                        // $marketplaces = $value['marketplace'];
                        // foreach ($marketplaces as $marketplace) {
                        //     if(isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == $targetMarketplace && $marketplace['shop_id'] == $targetShopId)
                        //     {
                        //         $target = $marketplace;
                        //     }
                        // }
                        if (isset($value['edited'])) {
                            $target = $value['edited'];
                        } else {
                            $target = $this->di->getObjectManager()->get(Marketplace::class)->getMarketplaceProduct($value['source_product_id'], $targetShopId, $value['container_id']);
                        }
                        if(!isset($target['status']) || $target['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER)
                        {
                            $productErrorList[$value['source_product_id']] = ['AmazonError501'];
                        }
                        else {
                            if($localDelivery && $merchantName)
                            {
                                if(isset($merchantName[$value['source_product_id']]))
                                {
                                    $merchantData = $merchantName[$value['source_product_id']];
                                }
                                elseif(isset($merchantName[$value['container_id']]))
                                {
                                    $merchantData = $merchantName[$value['container_id']];
                                }

                                $merchantDataSourceProduct[$value['source_product_id']] = $merchantData['merchant_shipping_group'] ?? [];
                            }

                            $calculateResponse = $this->calculate($value, $priceTemplate, $currencyCheck, false , $merchantDataSourceProduct);

                            if (!empty($calculateResponse) && isset($calculateResponse['SKU']) && isset($calculateResponse['StandardPrice'])) {
                                $time = date(DATE_ISO8601);
                                $content = [
                                    'Id' => $value['_id'] . $key,
                                    'SKU' => $calculateResponse['SKU'],
                                    'SalePrice' => $calculateResponse['SalePrice'],
                                    'StandardPrice' => $calculateResponse['StandardPrice'],
                                    'StartDate' => $calculateResponse['StartDate'],
                                    'processed' => $processType,
                                    'EndDate' => $calculateResponse['EndDate'],
                                    'MinimumPrice' => $calculateResponse['MinimumPrice'],
                                    'time' => $time
                                ];
                                $customMerchantShippingGroupUser = [
                                    '681e0703ff475e0b2301c5f2',// StateLineTack (a22259-53.myshopify.com)
                                    '68156eca89f6f996f402b532',//Horse.com (41b1c3-28.myshopify.com)
                                    '68058af274e396dc230f22d3'// HorseLoverZ (ec7d29-32.myshopify.com)
                                 ];
                                if(in_array($this->di->getUser()->id,$customMerchantShippingGroupUser)  && isset($value['variantMetafield']['custom->az_shipping_template_id']) || isset($value['parentMetafield']['custom->az_shipping_template_id'])){
                                    $content['merchantShippingGroup'] = $value['variantMetafield']['custom->az_shipping_template_id']['value'] ?? $value['parentMetafield']['custom->az_shipping_template_id']['value'];
                                }
                                if($this->di->getUser()->id == "6838a6d33688830cc1049893" && !empty($value['compare_at_price']))
                              {
                                    $content['maximumSellerAllowedPrice']  = (float)$value['compare_at_price'];
                                }
                                if($calculateResponse['BusinessPrice'] > 0 && !$localDelivery)
                                {
                                    $content['BusinessPrice'] = $calculateResponse['BusinessPrice'];
                                    $content['QuantityDiscount'] = $calculateResponse['QuantityDiscount']??[];
                                    $content['QuantityPriceType'] = $calculateResponse['QuantityPriceType']??[];
                                    $jsonFeedPrice[$value['source_product_id']] = $content;
                                }
                                else
                                {
                                    $jsonFeedPrice[$value['source_product_id']] = $content;
                                }
                            }
                            elseif(!empty($calculateResponse['error']))
                            {
                                $productErrorList[$value['source_product_id']] = [$calculateResponse['error']];

                            }
                            else {
                                $productErrorList[$value['source_product_id']] = ['AmazonError141'];
                            }
                        }

                        if(isset($value['next_prev'])) {
                            unset($productErrorList[$value['source_product_id']]);
                        }
                    }
                    $customUser = $this->di->getConfig()->get('CustomPricePatch')??[];
                    if(!is_array($customUser)){
                        $customUser = $customUser->toArray();
                    }
                    // $this->di->getLog()->logContent('Feed Content = ' . print_r($feedContent, true), 'info', $logFile);
                    if(!empty($jsonFeedPrice) && count(array_keys($jsonFeedPrice))<10 && in_array($userId, $customUser) && !empty($jsonFeedPrice)){
                        foreach($jsonFeedPrice as $key => $value)
                        {
                             $time = date(DATE_ISO8601);
                                $payload['shop_id'] = $targetShop['remote_shop_id'];
                                $purchasable_offer = [];
                                $purchasable_offer['audience'] = 'ALL';

                                $purchasable_offer['currency'] = $currencyValue;
                                $purchasable_offer['marketplace_id'] = $activeAccounts['marketplace_id'];
                                // map price-details
                                if($value['StandardPrice'])
                                {
                                    $schedule[] = [
                                        'value_with_tax' => (float)$value['StandardPrice']
                                    ];
                                    $our_price[0]['schedule'] = $schedule;
                                    $purchasable_offer['our_price'] = $our_price;
                                    $schedule = [];
                                }

                                if($value['SalePrice'])
                                {
                                    $schedule[] = [
                                        'value_with_tax' => (float)$value['SalePrice'],
                                        'start_at' =>$value['StartDate'],
                                        'end_at' => $value['EndDate']
                                    ];
                                    $discounted_price[0]['schedule'] = $schedule;
                                    $purchasable_offer['discounted_price'] = $discounted_price;
                                    $schedule = [];
                                }
                                if(!empty($value['BusinessPrice'])){
                                    $businessPriceMapping['audience'] = 'B2B';
                                    $businessPriceMapping['currency'] = $currencyValue;
                                    $businessPriceMapping['marketplace_id'] = $activeAccounts['marketplace_id'];
                                    $schedule[] = [
                                        'value_with_tax' => (float)$value['BusinessPrice']
                                    ];
                                    $our_price[0]['schedule'] = $schedule;
                                    $businessPriceMapping['our_price'] = $our_price;
                                    if(!empty($quantityDiscount)&& !empty($quantityPriceType)){
                                        $level = [];
                                        foreach($quantityDiscount as $quantity){
                                            $level[]['lower_bound']= $quantity['quantity'];
                                            $level[]['value'] = $quantity['price'];

                                        }
                                        $schedule[] = [
                                            'discount_type' => (string)$quantityPriceType,
                                            'levels'=>$level
                                        ];
                                        $quantityDiscountPlan[0]['schedule'] =$schedule; 
                                        $businessPriceMapping['quantity_discount_plan']= $quantityDiscountPlan;

                                    }
                                }
                                if($value['MinimumPrice'])
                                {
                                    $schedule[] = [
                                        'value_with_tax' => (float)$value['MinimumPrice']
                                    ];
                                    $minimum_seller_allowed_price[0]['schedule'] = $schedule;
                                    $purchasable_offer['minimum_seller_allowed_price'] = $minimum_seller_allowed_price;
                                    $schedule = [];
                                }
                                $attributes[0] = $purchasable_offer;
                                
                                if(!empty($businessPriceMapping)){
                                    $attributes[1] = $businessPriceMapping;

                                }                    
                                $payload['product_type'] = 'PRODUCT';
                              
                                $payload['patches'] = [
                                    [
                                        "op" => "replace",
                                        "operation_type" => "PARTIAL_UPDATE",
                                        "path" => "/attributes/purchasable_offer",
                                        "value" => $attributes
                                    ]
                                    ];
                                     
                                $payload['sku'] = rawurlencode($calculateResponse['SKU']);
                                $res = $commonHelper->sendRequestToAmazon('listing-update', $payload, 'POST');
                                // print_r($res);die;
                                if(isset($res['success']) && $res['success'] && !empty($res['response']['issues'])){
                                    $productErrorList[$key] = $res['response']['issues']; 
                                    unset($jsonFeedPrice[$key]);
                                    $activityFeed[$key] = $value;  
                                }
                                elseif(isset($res['success']) && $res['success']){
                                       unset($jsonFeedPrice[$key]);
                                    $activityFeed[$key] = $value;  
                                }
                            }
                            $inventoryComponent->saveFeedInDb($activityFeed , 'price' , $container_ids , $data['data']['params'] , $alreadySavedActivities);
                            
                    }

                    if (!empty($feedContent) || !empty($jsonFeedPrice)) {
                        $specifics = [
                            'ids' => array_keys($feedContent),
                            'home_shop_id' => $targetShop['_id'],
                            'marketplace_id' => $activeAccounts['marketplace_id'],
                            'sourceShopId' => $sourceShopId,
                            'shop_id' => $targetShop['remote_shop_id'],
                            'feedContent' => base64_encode(serialize($feedContent)),
                            'jsonFeedContent' => base64_encode(serialize($jsonFeedPrice)),
                            'user_id' => $userId,
                            'operation_type' => 'Update',
                            'currency' => $currencyValue,
                            'localDelivery' => $merchantDataSourceProduct,
                            'unified_json_feed' => true
                        ];

                        // $response = $commonHelper->sendRequestToAmazon('price-upload', $specifics, 'POST');
                        $response = $this->uploadPriceDirect($specifics);
                        if(isset($response['success']) && $response['success'] && !$localDelivery)
                        {
                            $feedActivtiy = [];
                            if(!empty($feedContent))
                            {
                                $feedActivtiy = $feedActivtiy + $feedContent;
                            }

                            if(!empty($jsonFeedPrice))
                            {
                                $feedActivtiy = $feedActivtiy + $jsonFeedPrice;
                            }

                            $inventoryComponent->saveFeedInDb($feedActivtiy , 'price' , $container_ids , $data['data']['params'] , $alreadySavedActivities);
                        } elseif((empty($response) || (isset($response['success']) && !$response['success'])) && !empty($products)) {
                            $this->di->getLog()->logContent('When processing price sync error occured:' . json_encode($response, true), 'info', 'amazon/exception/'.date('Y-m-d').'.log');
                            foreach($products as $product) {
                                isset($product['source_product_id']) && $productErrorList[$product['source_product_id']] = ['AmazonError996'];
                            }
                        }

                        $specificData['tag'] = Helper::PROCESS_TAG_PRICE_FEED;
                        $specificData['unsetTag'] = Helper::PROCESS_TAG_PRICE_SYNC;
                        $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);
                        // $productSpecifics = $productComponent->init()
                        // ->processResponse($response,$feedContent, \App\Amazon\Components\Common\Helper::PROCESS_TAG_PRICE_FEED, 'price',$targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList, \App\Amazon\Components\Common\Helper::PROCESS_TAG_PRICE_SYNC , $localDelivery , $merchantDataSourceProduct);
                        $addTagProductList = $productSpecifics['addTagProductList'];
                        $productErrorList = $productSpecifics['productErrorList'];
                    return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);

                    }
                    if(!empty($productErrorList)) {
                        $specificData['tag'] = Helper::PROCESS_TAG_PRICE_SYNC;
                        $specificData['unsetTag'] = false;
                        $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);
                        // $productSpecifics = $productComponent->init()
                        // ->processResponse($response,$feedContent, \App\Amazon\Components\Common\Helper::PROCESS_TAG_PRICE_SYNC, 'price',$targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList , false , $localDelivery , $merchantDataSourceProduct);

                    }
                    return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);


                }
                return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'Currency of '.$sourceMarketplace.' and Amazon account are not same. Please fill currency settings from Global Price Adjustment in settings page.', 0, 0, 0);
            }
            return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0);
        }
        return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0);
    }

    public function calculate($product, $priceTemplateData, $changeCurrency, $forcedEnable = false , $localDelivery = false)
    {
        $this->_user_id = $this->di->getUser()->id;
        $enabled = $priceTemplateData['settings_enabled'] ?? false;
        if ($enabled || $forcedEnable) {

            $salePrice = false;
            $businessPrice = false;
            $minimumPrice = false;
            $start = false;
            $end = false;
            $quantityPriceType = '';
            $quantity = [];
            $quantityvalidation = [];
            $error = '';
            if($localDelivery)
            {
                $prodLocal = false;
                $merchantId = $localDelivery[$product['source_product_id']] ?? "";
                $localDeliveryComp = $this->di->getObjectManager()->get(LocalDeliveryHelper::class);
                $paramsOfLD = [
                    'source_shop_id' => $product['shop_id'],
                    'user_id' => (string)$this->_user_id,
                    'container_id' => (string)$product['container_id'],
                    'source_product_id' => (string)$product['source_product_id'],
                    'items.merchant_shipping_group' => $merchantId
                ];
                $prodLocal = $localDeliveryComp->getSingleProductDB($paramsOfLD);
                if($prodLocal)
                {
                    $product['edited']['sku'] = $prodLocal['sku'] ?? "";
                    $product['edited']['price'] = (float)$prodLocal['price'] ?? "";
                }
            }

            $standardPriceMapping = $priceTemplateData['standard_price'] ?? [];
            $amazonProduct = [];
            if (isset($product['edited'])) {
                $amazonProduct = $product['edited'];
            }

            $parentAmazonProduct = [];
            
            if (isset($product['parent_details']['edited']) && !empty($product['parent_details']['edited'])) {
                $parentAmazonProduct = $product['parent_details']['edited'];
            }


            //setting sku
            if ($amazonProduct && isset($amazonProduct['sku'])) {
                $sku = $amazonProduct['sku'];
            } elseif (isset($product['sku'])) {
                $sku = $product['sku'];
            } else {
                $sku = $product['source_product_id'];
            }

            $productPriceRules = [];
            if (isset($amazonProduct['price_rules']) && !empty($amazonProduct['price_rules'])) {
                $productPriceRules = $amazonProduct['price_rules'] ?? [];
            } elseif (!empty($parentAmazonProduct) && isset($parentAmazonProduct['price_rules']) && !empty($parentAmazonProduct['price_rules'])) {
                $productPriceRules = $parentAmazonProduct['price_rules'] ?? [];
            } elseif (isset($amazonProduct['parent_price_rules']) && !empty($amazonProduct['parent_price_rules'])) {
                $productPriceRules = $amazonProduct['parent_price_rules'] ?? [];
            }
            //setting standard price
            if ($amazonProduct && isset($amazonProduct['price'])) {
                $productPrice = $amazonProduct['price'];
                $standardPrice = $productPrice;
            } else {
                $key = null;
                if (isset($product['offer_price']) && $product['offer_price']) {
                    $productPrice = $product['offer_price'];
                    $key = 'offer_price';
                } elseif (isset($product['price'])) {
                    $productPrice = $product['price'];
                    $key = 'price';
                } else {
                    $productPrice = 0;
                }
                //to update the price based on the metafield key
                if (!is_null($key) && !empty($this->di->getConfig()->get('metafield_sync_user_feature')) && (!empty($product['parentMetafield']) || !empty($product['variantMetafield']))) {
                    $metafieldSyncConfig = $this->di->getConfig()->get('metafield_sync_user_feature')->toArray();
                    $metafieldType = isset($product['variantMetafield']) ? 'variantMetafield' : 'parentMetafield';
                    if (isset($metafieldSyncConfig[$this->_user_id][$metafieldType][$key]) && $this->isMetafieldSyncEnable($this->_user_id, $product['shop_id'])) {
                        $syncKey = $metafieldSyncConfig[$this->_user_id][$metafieldType][$key] ?? null;
                        isset($product[$metafieldType][$syncKey]['value']) && $productPrice = $product[$metafieldType][$syncKey]['value'];
                    }
                }

                $standardPrice = $productPrice;
                $standardPriceAttribute = $standardPriceMapping['attribute'] ?? false;
                if (!empty($standardPriceMapping) && $standardPriceAttribute != 'default') {
                    $standardPriceChangeValue = $standardPriceMapping['value'];
                    $productPrice = $standardPrice = $this->changePrice($standardPrice, $standardPriceAttribute, $standardPriceChangeValue);
                }
            }

            if ($productPriceRules) {
                is_object($productPriceRules) && $productPriceRules  = json_decode(json_encode($productPriceRules, true));
                $standardPrice = $product['price'] ?? 0;
                $standardPriceAttribute = $productPriceRules['attribute'] ?? false;
                if ($standardPriceAttribute != 'default') {
                    $standardPriceChangeValue = $productPriceRules['value'];
                    $standardPrice = $this->changePrice($standardPrice, $standardPriceAttribute, $standardPriceChangeValue);
                    if (isset($productPriceRules['price_roundoff_value'])) {
                        $standardPrice = $this->priceRoundOff($standardPrice, $productPriceRules);
                    }
                }
                $productPrice = $standardPrice;
            }

            if ($standardPrice < 0) {
                $standardPrice = 0;
            }

            $salePriceEnabled = $priceTemplateData['settings_selected']['sale_price'] ?? false;
            if ($salePriceEnabled) {
                $salePriceMapping = $priceTemplateData['sale_price'];
                $salePrice = $product['price'];
                $endDate = $priceTemplateData['sale_price']['end_date'];
                $startDate = $priceTemplateData['sale_price']['start_date'];
                $start = date('Y-m-d', strtotime((string) $startDate));
                $end = date('Y-m-d', strtotime((string) $endDate));
                $salePriceAttribute = $salePriceMapping['attribute'];
                if ($salePriceAttribute != 'default') {
                    $salePriceChangeValue = $salePriceMapping['value'];
                    $salePrice = $this->changePrice($salePrice, $salePriceAttribute, $salePriceChangeValue);
                }

                if ($salePrice < 0) {
                    $salePrice = 0;
                }
            }

            $applySettings = true;
            $customPriceRes = $this->getCustomPriceValues($product, $priceTemplateData, $this->customPriceSyncRules);
            if (isset($customPriceRes['success']) && $customPriceRes['success']) {
                if (isset($customPriceRes['data']['custom_standard_price']) && !empty($customPriceRes['data']['custom_standard_price'])) {
                    $standardPrice = $productPrice = $customPriceRes['data']['custom_standard_price'];
                    if (!empty($productPriceRules)) {
                        is_object($productPriceRules) && $productPriceRules  = json_decode(json_encode($productPriceRules, true));
                        // $standardPrice = $product['price'] ?? 0;
                        $standardPriceAttribute = $productPriceRules['attribute'] ?? false;
                        if ($standardPriceAttribute != 'default') {
                            $standardPriceChangeValue = $productPriceRules['value'];
                            $standardPrice = $this->changePrice($standardPrice, $standardPriceAttribute, $standardPriceChangeValue);
                            if (isset($productPriceRules['price_roundoff_value'])) {
                                $productPrice = $standardPrice = $this->priceRoundOff($standardPrice, $productPriceRules);
                            }
                        }
                    }
                }
                (isset($customPriceRes['data']['custom_sale_price']) && !empty($customPriceRes['data']['custom_sale_price'])) && $salePrice = $customPriceRes['data']['custom_sale_price'];
                (isset($customPriceRes['data']['apply_settings'])) && $applySettings = $customPriceRes['data']['apply_settings'];
                (isset($customPriceRes['data']['custom_start']) && !empty($customPriceRes['data']['custom_start'])) && $start = $customPriceRes['data']['custom_start'];
                (isset($customPriceRes['data']['custom_end']) && !empty($customPriceRes['data']['custom_end'])) && $end = $customPriceRes['data']['custom_end'];
            }
            if ((!isset($amazonProduct['business_price_enabled']) || (isset($amazonProduct['business_price_enabled']) && !$amazonProduct['business_price_enabled'])) && isset($parentAmazonProduct['business_price_enabled']) && $parentAmazonProduct['business_price_enabled'] && !empty($parentAmazonProduct['business_price'])) {
                $amazonProduct['business_price_enabled'] = $parentAmazonProduct['business_price_enabled'];
                $amazonProduct['business_price'] = $parentAmazonProduct['business_price'];
            }
            if($applySettings && isset($amazonProduct['business_price_enabled'])&& $amazonProduct['business_price_enabled'] && !empty($amazonProduct['business_price']))
            {
                $businessPriceEnabled = $amazonProduct['business_price_enabled'];

                    $businessPriceMapping = $amazonProduct['business_price'];
                    $businessPrice = $productPrice; 
                    $businessPriceAttribute = $businessPriceMapping['attribute'];

                    if(isset($businessPriceMapping['selection_type'])&& $businessPriceMapping['selection_type'] != "default")
                    {
                        $businessPriceChangeValue = $businessPriceMapping['value'];
                        $businessPrice = $this->changePrice($businessPrice, $businessPriceAttribute, $businessPriceChangeValue);
                }

                if ($businessPrice < 0) {
                    $businessPrice = 0;
                }

                if(isset($businessPriceMapping['quantity_discount']) && isset($businessPriceMapping['quantity_type']) ){
                    $quantityPriceType = $businessPriceMapping['quantity_type'];
                    $quantity = [];
                    $quantityDiscount = $businessPriceMapping['quantity_discount'];
                    $result = $this->findInvalidPriceEntries($quantityDiscount);
                    if(!empty($result)  && is_array($result)){
                        $error = 'Quantity Discount Hierarchy is not in correct order';
                    }
                    if($quantityPriceType == "fixed"){
                        foreach($quantityDiscount as $key => $value){
                            if(!empty($value['price'])){
                                $quantity[$key]['quantity'] =  $value['quantity'];
                                $quantityvalidation[$key]['quantity'] =  $value['quantity'];
                                $bpCurrency = $businessPrice ? (float)$changeCurrency * $businessPrice : $businessPrice;
                                $bp =  number_format((float)$bpCurrency, 2, '.', '');
                                $bp=isset($businessPriceMapping['price_roundoff_value']) ? $this->priceRoundOff($bp,$businessPriceMapping):$bp;
                                $quantity[$key]['price'] = (string)($bp - $value['price']); 
                                $quantityvalidation[$key]['price'] = (string)($bp - $value['price']);
                            }
                        }
                    }
                    else{
                        $quantity = $quantityDiscount;
                        foreach($quantityDiscount as $key => $value){
                            if(!empty($value['price'])){
                                $quantityvalidation[$key]['quantity'] =  $value['quantity'];
                                $bpCurrency = $businessPrice ? (float)$changeCurrency * $businessPrice : $businessPrice;
                                 $bp =  number_format((float)$bpCurrency, 2, '.', '');
                                $bp=isset($businessPriceMapping['price_roundoff_value']) ? $this->priceRoundOff($bp,$businessPriceMapping):$bp;
                                $quantityvalidation[$key]['price'] = (string)($bp - ($value['price']/100 *$bp));
                            }
                        }
                    }
                }
            }


            else{

                $businessPriceEnabled = $priceTemplateData['settings_selected']['business_price'] ?? false;
                if ($applySettings && $businessPriceEnabled) {
                $businessPriceMapping = $priceTemplateData['business_price'];
                $businessPrice = $productPrice;
                $businessPriceAttribute = $businessPriceMapping['attribute'];
                $quantityPriceType = $businessPriceMapping['quantity_type']??[];
                // $quantity = $businessPriceMapping['quantity_discount']??[];
                $quantity = [];
                $quantityDiscount = $businessPriceMapping['quantity_discount']??[];
                $result = $this->findInvalidPriceEntries($quantityDiscount);
                
                if(!empty($result)  && is_array($result)){
                    $error = 'Quantity Discount Hierarchy is not in correct order';
                }
                if ($businessPriceAttribute != 'default') {
                    $businessPriceChangeValue = $businessPriceMapping['value'];
                    $businessPrice = $this->changePrice($businessPrice, $businessPriceAttribute, $businessPriceChangeValue);
                }
                if ($businessPrice < 0) {
                    $businessPrice = 0;
                }
                if($quantityPriceType == "fixed"){
                    foreach($quantityDiscount as $key => $value){
                        if(!empty($value['price'])){
                            $quantity[$key]['quantity'] =  $value['quantity'];
                            $quantityvalidation[$key]['quantity'] =  $value['quantity'];
                            $bpCurrency = $businessPrice ? (float)$changeCurrency * $businessPrice : $businessPrice;
                             $bp =  number_format((float)$bpCurrency, 2, '.', '');
                            $bp=isset($businessPriceMapping['price_roundoff_value']) ? $this->priceRoundOff($bp,$businessPriceMapping):$bp;
                            $quantity[$key]['price'] = (string)($bp - $value['price']); 
                            $quantityvalidation[$key]['price'] = (string)($bp - $value['price']);
                        }
                    }
                }
                else{
                    $quantity = $quantityDiscount;
                    foreach($quantityDiscount as $key => $value){
                        if(!empty($value['price'])){
                            $quantityvalidation[$key]['quantity'] =  $value['quantity'];
                            $bpCurrency = $businessPrice ? (float)$changeCurrency * $businessPrice : $businessPrice;
                             $bp =  number_format((float)$bpCurrency, 2, '.', '');
                            $bp=isset($businessPriceMapping['price_roundoff_value']) ? $this->priceRoundOff($bp,$businessPriceMapping):$bp;
                            $quantityvalidation[$key]['price'] = (string)($bp - ($value['price']/100 *$bp));
                        }
                    }
                }
            }
        }
            $minimumPriceEnabled = $priceTemplateData['settings_selected']['minimum_price'] ?? false;
            if ($applySettings && $minimumPriceEnabled) {
                $minimumPriceMapping = $priceTemplateData['minimum_price'];
                $minimumPrice = $product['price'];
                $minimumPriceAttribute = $minimumPriceMapping['attribute'];
                if ($minimumPriceAttribute != 'default') {
                    $minimumPriceChangeValue = $minimumPriceMapping['value'];
                    $minimumPrice = $this->changePrice($minimumPrice, $minimumPriceAttribute, $minimumPriceChangeValue);
                }
                if($this->di->getUser()->id == "660d12d2c57df226430244c3" && $minimumPriceAttribute == "default" && isset($product['price'])){
                    $minimumPrice = $product['price'];
                }
                if($this->di->getUser()->id == "6863b9d05aaf939f370b11e3" && $minimumPriceAttribute == "default" && isset($product['variantMetafield']['variant->amazon_price'])){
                    $minimumPrice = $product['variantMetafield']['variant->amazon_price']['value'];
                }

                if ($minimumPrice < 0) {
                    $minimumPrice = 0;
                }
            }

            $standardPriceInCurrency = $standardPrice ? (float)$changeCurrency * $standardPrice : $standardPrice;
            $salePriceInCurrency = $salePrice ? (float)$changeCurrency * $salePrice : $salePrice;
            $minimumPriceInCurrency = $minimumPrice ? (float)$changeCurrency * $minimumPrice : $minimumPrice;
            $businessPriceInCurrency = $businessPrice ? (float)$changeCurrency * $businessPrice : $businessPrice;

            $finalStandardPrice = number_format((float)$standardPriceInCurrency, 2, '.', '');
            $finalSalePrice = number_format((float)$salePriceInCurrency, 2, '.', '');
            $finalMinimumPrice = number_format((float)$minimumPriceInCurrency, 2, '.', '');
            $finalBusinessPrice = number_format((float)$businessPriceInCurrency, 2, '.', '');

            if($applySettings && isset($standardPriceMapping['price_roundoff_value']) && !isset($amazonProduct['price']) && empty($productPriceRules))
            {
                $finalStandardPrice = $this->priceRoundOff($finalStandardPrice,$standardPriceMapping);
            }
            if ($applySettings) {
                $finalSalePrice = isset($salePriceMapping['price_roundoff_value']) ? $this->priceRoundOff($finalSalePrice,$salePriceMapping):$finalSalePrice ;
                $finalBusinessPrice = isset($businessPriceMapping['price_roundoff_value']) ? $this->priceRoundOff($finalBusinessPrice,$businessPriceMapping):$finalBusinessPrice ;
                $finalMinimumPrice = isset($minimumPriceMapping['price_roundoff_value']) ? $this->priceRoundOff($finalMinimumPrice,$minimumPriceMapping):$finalMinimumPrice ;
                $validateheirarache=$this->validatePriceHierarchy((float)$finalStandardPrice,(float)$finalBusinessPrice,$quantityvalidation,(float)$finalMinimumPrice);
                if(isset($validateheirarache['Error']) && empty($error)){
                    $error = $validateheirarache['Error'];
                }
            }

            if(!empty($error)){
                return ['error'=> $error];
            }
            else{
                return [
                    'SKU' => $sku,
                    'StandardPrice' => (float)$finalStandardPrice,
                    'SalePrice' => (float)$finalSalePrice,
                    'StartDate' => $start,
                    'EndDate' => $end,
                    'MinimumPrice' => (float)$finalMinimumPrice,
                    'BusinessPrice' => (float)$finalBusinessPrice,
                    'QuantityDiscount'=>$quantity,
                    'QuantityPriceType' => $quantityPriceType
                ];
            }
        }
        return [];
    }
    public function validatePriceHierarchy($retailPrice, $businessPrice, $quantityTierPrice, $minAllowedPrice) {
        // Check hierarchy
        if ($retailPrice < $businessPrice) {
            return ["Error"=>"AmazonError997"];
        }
        if(is_array($quantityTierPrice)){
        foreach($quantityTierPrice as $quantity){
            if ($businessPrice <= $quantity['price']) {
                return ["Error"=>"AmazonError998"];
            }
            if ($quantity['price'] <= $minAllowedPrice) {
                return ["Error"=>"AmazonError999"];
            }
        }    
        }
        // If all checks pass, return success message
        return ["sucess"=>"Price hierarchy is valid."];
    }
    public function findInvalidPriceEntries(array $items): array {
        // Add original index to each item
        $itemsWithIndex = array_map(function ($item, $index) {
            $item['originalIndex'] = $index;
            return $item;
        }, $items, array_keys($items));
        // Sort the array by quantity
        usort($itemsWithIndex, function ($a, $b) {
            return intval($a['quantity']) <=> intval($b['quantity']);
        });
        // Array to store the result
        $result = [];
        // Loop through the sorted array and find rows with price lower than the previous row
        for ($i = 1; $i < count($itemsWithIndex); $i++) {
            if (intval($itemsWithIndex[$i]['price']) < intval($itemsWithIndex[$i - 1]['price'])) {
                $result[] = [
                    'originalIndex' => $itemsWithIndex[$i]['originalIndex'],
                    'row' => $itemsWithIndex[$i]
                ];
            }
        }
        return $result;
    }

    public function priceRoundOff($oldPrice,$attribute)
    {
        $value = $attribute['price_roundoff_value'];
        if($value == "")
        {
            return $oldPrice;
        }
        $initialPrice = (float)$oldPrice;
        $finalPrice = $initialPrice;
        switch ($value) {
            case 'higherEndWith9':
                $initialPrice = ceil($initialPrice);
                $price = (int)($initialPrice - $initialPrice % 10);
                $finalPrice = $price + 9;
                break;
            case 'lowerEndWith9':
                if($initialPrice<9){
                    $finalPrice = $initialPrice;
                }
                else{
                    $initialPrice = ceil($initialPrice);
                    $price = (int)($initialPrice - $initialPrice % 10);
                    $finalPrice = $price - 1;
                    
                }
                break;
            case 'higherWholeNumber':
                $price = ceil($initialPrice);
                // $price = (int)$initialPrice;
                $finalPrice = (int)$price;
                break; 
            case 'higherEndWith0.49':
                    $initialPrice = (int)$initialPrice;
                    $finalPrice = (float)$initialPrice + 0.49;
                break;
            case 'lowerEndWith0.49':
                if($initialPrice < 0.49){
                    $finalPrice = $initialPrice;
                }else{
                    $initialPrice = (int)$initialPrice;
                    $finalPrice = (float)$initialPrice - 0.51;

                }
                break;              
            case 'lowerWholeNumber':
                $price = floor($initialPrice);
                $finalPrice = (int)$price;
                break;
            case 'higherEndWith0.99':
                $price = (int)$initialPrice;
                $finalPrice = $price + 0.99;
                break;
            case 'lowerEndWith0.99':
                if($initialPrice < 0.99) 
                {
                    $finalPrice = $initialPrice;
                }
                else
                {
                    $price = (int)$initialPrice;
                    $finalPrice = $price - 0.01;

                }
                break; 
            case 'higherEndWith10':
                $finalPrice=ceil($initialPrice / 10) * 10;
                break;
            case 'lowerEndWith10':
                $finalPrice =floor($initialPrice / 10) * 10;
                break; 
            default:
                $finalPrice = $initialPrice;
                break;
        }

        if($finalPrice <= 0)
        {
            $finalPrice = $oldPrice;
        }

        return $finalPrice;
    }

    public function changePrice($price, $attribute, $changeValue)
    {
        if ($attribute == 'value_increase') {
            $price = $price + $changeValue;
        } elseif ($attribute == 'value_decrease') {
            $price = $price - $changeValue;
        } elseif ($attribute == 'percentage_increase') {
            $price = $price + (($changeValue / 100) * $price);
        } elseif ($attribute == 'percentage_decrease') {
            $price = $price - (($changeValue / 100) * $price);
        }  elseif ($attribute == 'custom') {
            $price = $changeValue;
        }

        return $price;
    }

    // public function update($data)
    // {
    //     if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
    //         $ids = $data['source_product_ids'];
    //         $profileIds = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\Helper')
    //             ->getProfileIdsByProductIds($data['source_product_ids']);

    //         if (!empty($profileIds)) {
    //             //get profiles from profile Ids
    //             $profileCollection = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILES);
    //             $profiles = $profileCollection->find(['profile_id' => ['$in' => $profileIds]], ["typeMap" => ['root' => 'array', 'document' => 'array']]);

    //             foreach ($profiles as $profileKey => $profile) {
    //                 $profileId = $profile['profile_id'];
    //                 $accounts = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\Helper')
    //                     ->getAccountsByProfile($profile);

    //                 foreach ($accounts as $homeShopId => $account) {
    //                     $productIds = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\Helper')
    //                         ->getAssociatedProductIds($profileId, $ids);

    //                     if (!empty($productIds)) {
    //                         $priceTemplateId = $account['templates']['price'];
    //                         $categoryTemplateId = $account['templates']['category'];
    //                         foreach ($account['warehouses'] as $amazonWarehouse) {
    //                             $marketplaceIds = $amazonWarehouse['marketplace_id'];
    //                             if (!empty($marketplaceIds)) {
    //                                 $specifics = [
    //                                     'ids' => $productIds,
    //                                     'home_shop_id' => $homeShopId,
    //                                     'marketplace_id' => $marketplaceIds,
    //                                     'profile_id' => $profile['profile_id'],
    //                                     'price_template' => $priceTemplateId,
    //                                     'category_template' => $categoryTemplateId,
    //                                     'shop_id' => $account['remote_shop_id']
    //                                 ];
    //                                 $feedContent = $this->prepare($specifics);
    //                                 if (!empty($feedContent)) {
    //                                     $specifics['feedContent'] = $feedContent;
    //                                     $commonHelper = $this->di->getObjectManager()->get(Helper::class);
    //                                     $response = $commonHelper->sendRequestToAmazon('price-upload', $specifics, 'POST');
    //                                     $feed = $this->di->getObjectManager()
    //                                         ->get(Feed::class)
    //                                         ->init(['user_id' => $this->_user_id, 'shop_id' => $homeShopId])
    //                                         ->process($response);
    //                                 }
    //                             }
    //                         }
    //                     }
    //                 }
    //             }
    //         }
    //     }
    // }

    public function prepare($data)
    {
        $profilePresent = false;
        $priceTemplateData = [];
        if (isset($data['profile_info']) && isset($data['profile_info']['data'])) {
            foreach ($data['profile_info']['data'] as $value) {
                if ($value['data_type'] == 'pricing_settings') {
                    $priceTemplateData = $value['data'];
                    $profilePresent = true;
                }
            }
        }

        if (isset($data['profile_info']) && isset($data['profile_info'][0]['data'])) {

            foreach ($data['profile_info'][0]['data'] as $value) {
                if ($value['data_type'] == 'pricing_settings') {
                    $priceTemplateData = $value['data'];

                    $profilePresent = true;
                }
            }
        }

        if (!$profilePresent) {
            $this->configObj->setGroupCode('price');
            $priceConfig = $this->configObj->getConfig();
            $priceConfig = json_decode(json_encode($priceConfig, true), true);
            foreach ($priceConfig as $value) {
                $priceTemplateData[$value['key']] = $value['value'];
                $profilePresent = true;
            }
        }

        if (!$profilePresent) {
            $this->configObj->setUserId('all');
            $this->configObj->setGroupCode('price');
            $priceConfig = $this->configObj->getConfig();
            $priceConfig = json_decode(json_encode($priceConfig, true), true);
            foreach ($priceConfig as $value) {
                $priceTemplateData[$value['key']] = $value['value'];
                $profilePresent =true;
            }
        }

        return $priceTemplateData;
    }


    public function getConversionRate($sourceMarketplace = false)
    {

        $sourceMarketplaceCurrency = false;
        $changeCurrency = false;
        /*check if currency is different on sourcemarketplace and amazon*/

        $amazonHelper = $this->di->getObjectManager()->get('App\Frontend\Components\AmazonebaymultiHelper');
        $connectedAccounts = $amazonHelper->getAllConnectedAcccounts($this->_user_id); //all rhe connected acc come togather


        foreach ($connectedAccounts['data'] as $shop) {
            if ($shop['marketplace'] === 'onyx') {
                if (isset($shop['shop_details']['currency'])) {
                    $sourceMarketplaceCurrency = $shop['shop_details']['currency'];
                    break;
                }
            }
        }

        $configuration = $this->_baseMongo->getCollectionForTable(Helper::CONFIGURATION)->findOne(['user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        // check both are same country

        if (isset($configuration['data']['currency_settings']['settings_enabled']) && $configuration['data']['currency_settings']['settings_enabled']) {
            if (isset($configuration['data']['currency_settings']['amazon_currency'])) {
                $changeCurrency = $configuration['data']['currency_settings']['amazon_currency'];
            }
        }

        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        $activeAccounts = [];
        $connectedAccounts = $commonHelper->getAllAmazonShops($this->_user_id);

        foreach ($connectedAccounts as $account) {
            if ($account['warehouses'][0]['status'] == 'active') {
                $marketplaceId = $account['warehouses'][0]['marketplace_id'];

                if (!$changeCurrency) {
                    if (Helper::MARKETPLACE_CURRENCY[$marketplaceId] != $sourceMarketplaceCurrency) {
                        //if marketplace id's currency != $shop currency
                        return false;
                    }
                    $changeCurrency = 1;
                }
            }
        }

        return $changeCurrency;
    }

    public function isMetafieldSyncEnable($userId, $sourceShopId)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $config = $mongo->getCollectionForTable('config');
        $query = [
            'group_code' => 'product',
            'key' => 'metafield',
            'value' => true,
            'user_id' => $userId,
            'source_shop_id' => $sourceShopId
        ];
        return $config->countDocuments($query);
    }

    public function getPriceSyncRulesConfig($userId, $sourceShopId, $targetShopId)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $config = $mongo->getCollectionForTable('config');
        $query = [
            'group_code' => self::CUSTOM_GROUP_CODE,
            'key' => ['$in' => SELF::CUSTOM_PRICE_SYNC_SETTINGS],
            'user_id' => (string)$userId,
            'source_shop_id' => (string)$sourceShopId,
            'target_shop_id' => (string)$targetShopId
        ];
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $settings = $config->find($query, $options);
        if (!empty($settings)) {
            $settings = $settings->toArray();
            $priceSettings = [];
            foreach($settings as $setting) {
                $priceSettings[$setting['key']] = $setting;
            }
            return $priceSettings;
        }
        return [];
    }

    public function getCustomPriceValues($product, $priceTemplateData, $customPriceSyncRules)
    {
        $customStandardPrice = null;
        $customSalePrice = null;
        $customSaleStartDate = null;
        $customSaleEndDate = null;
        $applyTemplateSettings = true;//by default we will apply the template settings in the customized changed price
        if (!empty($customPriceSyncRules) && isset($customPriceSyncRules[self::CUSTOM_PRICE_SYNC_FIELDS_ENABLED]) && $customPriceSyncRules[self::CUSTOM_PRICE_SYNC_FIELDS_ENABLED]['value']) {
            $addCustomization = false;
            if (isset($product['compare_at_price'], $product['price'])) {
                if (is_null($product['compare_at_price']) || empty($product['compare_at_price']) || ((float)$product['compare_at_price'] <= (float)$product['price'])) {
                    return [
                        'success' => false,
                        'message' => 'customizations cannot be added as conditions not meet'
                    ];
                } else {
                    $addCustomization = true;
                }
            }

            if ($addCustomization) {
                $customPriceFields = isset($customPriceSyncRules[self::CUSTOM_PRICE_SYNC_FIELDS]['value']) ? $customPriceSyncRules[self::CUSTOM_PRICE_SYNC_FIELDS]['value'] : [];
                $applyTemplateSettings = isset($customPriceSyncRules[self::CUSTOM_PRICE_APPLY_TEMPLATE]['value']) ? $customPriceSyncRules[self::CUSTOM_PRICE_APPLY_TEMPLATE]['value'] : true;
                if (!empty($customPriceFields)) {
                    //this should be like the mapping will be saved in the format => value : {"standard_price" => "price"}, means value => [amazon_key => shopify_key](will be an associative array)
                    if (isset($customPriceFields['standard_price']) && isset($product[$customPriceFields['standard_price']]) && !empty($product[$customPriceFields['standard_price']])) {
                        $customStandardPrice = (float)$product[$customPriceFields['standard_price']];
                        $standardPrice  = $customStandardPrice;
                        if ($applyTemplateSettings && !empty($standardPriceMapping)) {
                            $standardPriceAttribute = $standardPriceMapping['attribute'] ?? false;
                            if ($standardPriceAttribute != 'default') {
                                $standardPriceChangeValue = $standardPriceMapping['value'];
                                $customStandardPrice  = $this->changePrice($standardPrice, $standardPriceAttribute, $standardPriceChangeValue);
                            }
                        }
                    }
                    if (isset($customPriceFields['sale_price']) && isset($product[$customPriceFields['sale_price']]) && !empty($product[$customPriceFields['sale_price']])) {
                        $customSalePrice = (float)$product[$customPriceFields['sale_price']];
                    }
                }

                $customSaleDuration = isset($customPriceSyncRules[self::CUSTOM_SALE_PRICE_DATES]['value']) ? $customPriceSyncRules[self::CUSTOM_SALE_PRICE_DATES]['value'] : [];
                if (!is_null($customSalePrice) && !empty($customSaleDuration)) {
                    if (isset($customSaleDuration['start_date'])) {
                        if($customSaleDuration['start_date'] == 'current') {
                            $customSaleStartDate = date('Y-m-d');
                        } else {
                            $customSaleStartDate = $customSaleDuration['start_date'];
                        }
                    }
                    if (isset($customSaleDuration['end_date'])) {
                        $customSaleEndDate = $customSaleDuration['end_date'];
                    }
                }

                if (!is_null($customSalePrice)) {
                    // $salePrice = $customSalePrice;
                    $customSaleEndDate = (!is_null($customSaleEndDate)) ? $customSaleEndDate : $priceTemplateData['sale_price']['end_date'] ?? null;
                    $customSaleStartDate = (!is_null($customSaleStartDate)) ? $customSaleStartDate : $priceTemplateData['sale_price']['start_date'] ?? null;
                    $customSaleStartDate = date('Y-m-d', strtotime((string) $customSaleStartDate));
                    $customSaleEndDate = date('Y-m-d', strtotime((string) $customSaleEndDate));
                    if ($applyTemplateSettings && isset($priceTemplateData['sale_price'])) {
                        $salePriceMapping = $priceTemplateData['sale_price'];
                        $salePriceAttribute = $salePriceMapping['attribute'];
                        if ($salePriceAttribute != 'default') {
                            $salePriceChangeValue = $salePriceMapping['value'];
                            $customSalePrice = $this->changePrice($customSalePrice, $salePriceAttribute, $salePriceChangeValue);
                        }
                    }
                    if ($customSalePrice < 0) {
                        $customSalePrice = 0;
                    }
                }
                return [
                    'success' => true,
                    'data' => [
                        'custom_standard_price' => $customStandardPrice,
                        'custom_sale_price' => $customSalePrice,
                        'custom_start' => $customSaleStartDate,
                        'custom_end' => $customSaleEndDate,
                        'apply_settings' => $applyTemplateSettings
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'No customization can be added as not data is found'
                ];
            }
        } else {
            return [
                'success' => false,
                'message' => 'No customization can be added as not data is found'
            ];
        }
    }

    public function addCustomPriceSettings($rawBody)
    {
        if (isset($rawBody['user_id'], $rawBody['source_marketplace'], $rawBody['source_shop_id'], $rawBody['target_marketplace'], $rawBody['target_shop_id'], $rawBody['app_tag'])) {
            $settingsData = [];
            foreach(self::CUSTOM_PRICE_SYNC_SETTINGS as $setting) {
                if ($setting == self::CUSTOM_PRICE_SYNC_FIELDS_ENABLED) {
                    $settingsData[$setting] = true;
                }
                if ($setting == self::CUSTOM_PRICE_APPLY_TEMPLATE) {
                    $settingsData[$setting] = true;
                }
                if ($setting == self::CUSTOM_PRICE_SYNC_FIELDS) {
                    isset ($rawBody[self::CUSTOM_PRICE_SYNC_FIELDS]) &&  $settingsData[$setting] = $rawBody[self::CUSTOM_PRICE_SYNC_FIELDS];
                }
                if ($setting == self::CUSTOM_SALE_DURATION) {
                    if (isset ($rawBody[self::CUSTOM_SALE_DURATION])) {
                        $settingsData[$setting] = $rawBody[self::CUSTOM_SALE_DURATION];
                        $startDate = date('Y-m-d', strtotime('-1day'));
                        if ($rawBody[self::CUSTOM_SALE_DURATION] == '1year') {
                            $endDate = date('Y-m-d', strtotime('+1year'));
                        } elseif ($rawBody[self::CUSTOM_SALE_DURATION] == '1month') {
                            $endDate = date('Y-m-d', strtotime('+1month'));
                        }
                        $settingsData[self::CUSTOM_SALE_PRICE_DATES] = ['start_date' => $startDate, 'end_date' => $endDate];
                    }
                }
            }
            $commonHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Common');
            $diRes = $commonHelper->setDiForUser($rawBody['user_id']);
            if (isset($diRes['success']) && $diRes['success'] && !empty($settingsData)) {
                $this->getDi()->getAppCode()->setAppTag($rawBody['app_tag']);
                if ($rawBody['target_shop_id'] == 'all') {
                    $shops = $this->di->getUser()->shops ?? [];
                    if (!empty($shops)) {
                        $configData = [
                            'data' => [
                                [
                                    'data' => $settingsData,
                                    'group_code' => self::CUSTOM_GROUP_CODE
                                ]
                                ],
                                'source' => [
                                    'marketplace' => $rawBody['source_marketplace'],
                                    'shopId' => $rawBody['source_shop_id']
                                ],
                                'user_id' => $rawBody['user_id'],
                                // 'appTag' => $rawBody['app_tag']
                        ];
                        foreach($shops as $shop) {
                            if (isset($shop['marketplace']) && ($shop['marketplace'] == $rawBody['target_marketplace'])) {
                                $configData['target'] = [
                                    'marketplace' => $shop['marketplace'],
                                    'shopId' => $shop['_id']
                                ];
                                $configHelper = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');
                                $configHelper->saveConfigData($configData);
                            }
                        }
                    }
                } else {
                    $configData = [
                        'data' => [
                            [
                                'data' => $settingsData,
                                'group_code' => self::CUSTOM_GROUP_CODE
                            ]
                            ],
                            'source' => [
                                'marketplace' => $rawBody['source_marketplace'],
                                'shopId' => $rawBody['source_shop_id']
                            ],
                            'target' =>  [
                                'marketplace' => $rawBody['target_marketplace'],
                                'shopId' => $rawBody['target_shop_id'],
                            ],
                            'user_id' => $rawBody['user_id'],
                            // 'appTag' => $rawBody['app_tag']
                    ];
                    $configHelper = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');
                    $configHelper->saveConfigData($configData);
                }
                return [
                    'success' => true,
                    'message' => 'settings added successfully'
                ];
            } else {
                return !$diRes['success'] ? $diRes : 'Settings not found!';
            }
        } else {
            return [
                'success' => false,
                'message' => 'user_id, source_marketplace, source_shop_id, target_marketplace, target_shop_id, app_tag required'
            ];
        }
    }

    public function updateCustomPriceDuration()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $config = $mongo->getCollectionForTable('config');
        $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
        $commonHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Common');
        $exceedDate = date('Y-m-d', strtotime('+1month'));
        $dateQuery = [
            'group_code' => self::CUSTOM_GROUP_CODE,
            'key' =>  SELF::CUSTOM_SALE_PRICE_DATES,
            'value.end_date' => ['$exists' => true],
            'value.end_date' => ['$lte' => $exceedDate]
        ];
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $customDateSettings = $config->find($dateQuery, $options)->toArray();
        if (!empty($customDateSettings)) {
            $syncData['activePage'] = 1;
            $syncData['profile_id'] = 'profile_id';
            $syncData['filter'] = [
                'items.status' => [
                    10 => [
                        'Inactive',
                        'Incomplete',
                        'Active',
                        'Submitted'
                    ]
                ]
            ];
            $syncData['useRefinProduct'] = 1;
            $syncData['usePrdOpt'] = 1;
            $syncData['projectData'] = [
                'marketplace' => 0
            ];
            $syncData['operationType'] = 'price_sync';
            foreach($customDateSettings as $customDateSetting) {
                $durationSetting = $config->findOne(['user_id' => $customDateSetting['user_id'],'target_shop_id' => $customDateSetting['target_shop_id'], 'group_code' => self::CUSTOM_GROUP_CODE, 'key' => self::CUSTOM_SALE_DURATION], $options);
                if (!empty($durationSetting) && isset($durationSetting['value'])) {
                    $startDate = date('Y-m-d', strtotime('-1day'));
                    $endDate = date('Y-m-d', strtotime('+'.$durationSetting['value']));
                    $updateData = [
                        'value' => [
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                        ],
                        'updated_at' => date('c')
                    ];
                    $dateQuery['user_id'] = $customDateSetting['user_id'];
                    $dateQuery['target_shop_id'] = $customDateSetting['target_shop_id'];
                    $config->updateMany($dateQuery, ['$set' => $updateData]);
                    $diRes = $commonHelper->setDiForUser($customDateSetting['user_id']);
                    if (isset($diRes['success']) && $diRes['success']) {
                        $syncData['target'] = [
                            'marketplace' => $customDateSetting['target'],
                            'shopId' => $customDateSetting['target_shop_id']
                        ];
                        $syncData['source'] = [
                            'marketplace' => $customDateSetting['source'],
                            'shopId' => $customDateSetting['source_shop_id']
                        ];
                        $this->di->getRequester()->setSource(
                            [
                                'source_id' => $customDateSetting['source_shop_id'],
                                'source_name' => $customDateSetting['source']
                            ]
                        );
                        $this->di->getRequester()->setTarget(
                            [
                                'target_id' => $customDateSetting['target_shop_id'],
                                'target_name' => $customDateSetting['target']
                            ]
                        );
                        $this->di->getAppCode()->setAppTag($customDateSetting['app_tag']);
                        // $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog("Price Sync", "Price Sync log added", $syncData);
                        $res = $product->startSync($syncData);
                    }
                }
            }
            return [
                'success' => true,
                'message' => 'Settings updated successfully and price sync intiated for the users'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'No duration setting found!'
            ];
        }
    }

    public function uploadPriceDirect($data)
    {
        try {
            $jsonFeedContent = $data['jsonFeedContent'] ?? "";
            $jsonFeedContent = unserialize(base64_decode((string) $jsonFeedContent));
            $errors = [];
            $marketplaceId = $data['marketplace_id'];
            $result = [];
            $currency = $data['currency'] ?? "";

            if (!empty($jsonFeedContent)) {
                $envelopeArrayJson = $this->prepareFeedData($jsonFeedContent, $marketplaceId, $currency);
                $jsonEnvelopeData = $envelopeArrayJson['envelopeData'] ?? [];
                $operationType = "";
                if (isset($data['operation_type'])) {
                    $operationType = $data['operation_type'];
                }
                $dynamoTable = 'amazon_unified_json_listing_sync_spapi';
                $subscriptionKey = isset($this->di->getConfig()->get('amazon_subscription_key_by_action')['price']) ? $this->di->getConfig()->get('amazon_subscription_key_by_action')['price'] : null;
                if (empty($subscriptionKey)) {
                    return [
                        'success' => false,
                        'data' => $data,
                        'message' => 'Subscription key not found'
                    ];
                }
                $dynamoObj = $this->di->getObjectManager()->get('\App\Connector\Components\Dynamo');
                $dynamoData = [];
                $sentToDynamo = [];
                $preparedResponseData = [];
                $dynamoObj->setTable($dynamoTable);
                $dynamoObj->setUniqueKeys(['remote_shop_id', 'source_identifier', 'action']);
                $dynamoObj->setTableUniqueColumn('id');
                foreach ($jsonEnvelopeData as $sourceProductId => $singleEnvelopeDataJson) {
                    $sentToDynamo[$sourceProductId] = [
                        'home_shop_id' => $data['home_shop_id'],
                        'remote_shop_id' => $data['shop_id'],
                        'source_identifier' => $sourceProductId,
                        'source_shop_id' => $data['sourceShopId'],
                        'action' => 'price',
                        'messageId' => $singleEnvelopeDataJson['messageId'],
                        'feedContent' => json_encode($singleEnvelopeDataJson),
                        'user_id' => $data['user_id'],
                        'operation_type' => $operationType,
                        'SK' => $subscriptionKey,
                        'contentType' => 'JSON',
                        'timestamp' => $jsonFeedContent[$sourceProductId]['time'] ?? ""
                    ];
                    $dynamoData[$sourceProductId] = $sentToDynamo[$sourceProductId];
                    $preparedResponseData[$sourceProductId] = [
                        'success' => true,
                        "message" => "Data inserted successfully!!"
                    ];
                }

                if (empty($sentToDynamo)) {
                    return [
                        'success' => false,
                        'data' => $data,
                        'message' => 'Unable to prepare sendToDynamo data'
                    ];
                }

                $dynamoResponse = $dynamoObj->save($sentToDynamo);
                if (isset($dynamoResponse['success']) && !$dynamoResponse['success']) {
                    return [
                        'success' => false,
                        'data' => $data,
                        'message' => 'Unable to save data to Dynamo',
                        'dynamoData' => $dynamoData,
                        'dynamoResponse' => $dynamoResponse
                    ];
                }

                $result[$marketplaceId] = [
                    'success' => true,
                    'error' => $errors,
                    'response' => $preparedResponseData,
                    'dynamoData' => $dynamoData
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Invalid JSON feed content'
                ];
            }

            return [
                'success' => true,
                'data' => $data,
                'result' => $result
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'data' => $data,
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    public function prepareFeedData($feedContent, $marketplaceId, $currency = false)
    {
        $envelopData = [];
        foreach ($feedContent as $sourceProductId => $priceAttribute) {
            $dataPrepared = $this->prepareJsonFeedPrice($priceAttribute, $currency, $marketplaceId);
            $envelopData[$sourceProductId] = $dataPrepared;
        }
        return [
            'envelopeData' => $envelopData
        ];
    }

    public function prepareJsonFeedPrice($message, $currency = 'INR', $marketplaceId = '')
    {
        $messageId = $message['Id'];
        $sku = $message['SKU'];
        $price = $message['StandardPrice'];
        $salePrice = $message['SalePrice'];
        $StartDate = $message['StartDate'];
        $EndDate = $message['EndDate'];
        $businessPrice = $message['BusinessPrice'] ?? "";
        $maximumSellerAllowedPrice = $message['maximumSellerAllowedPrice'] ?? "";
        $merchantShippingGroup = $message['merchantShippingGroup'] ?? "";

        $quantityDiscount = $message['QuantityDiscount'] ?? "";
        $quantityPriceType = $message['QuantityPriceType'] ?? "";
        $businessPriceMapping = [];
        $minimumPrice = $message['MinimumPrice'];
        $message = [];
        $attributes = [];
        $purchasable_offer = [];
        $purchasable_offer['audience'] = 'ALL';

        $purchasable_offer['currency'] = $currency;
        $purchasable_offer['marketplace_id'] = $marketplaceId;

        if ($price) {
            $schedule[] = [
                'value_with_tax' => (float)$price
            ];
            $our_price[0]['schedule'] = $schedule;
            $purchasable_offer['our_price'] = $our_price;
            $schedule = [];
        }

        if ($salePrice) {
            $schedule[] = [
                'value_with_tax' => (float)$salePrice,
                'start_at' => $StartDate,
                'end_at' => $EndDate
            ];
            $discounted_price[0]['schedule'] = $schedule;
            $purchasable_offer['discounted_price'] = $discounted_price;
            $schedule = [];
        }
        if (!empty($maximumSellerAllowedPrice)) {
            $schedule[] = [
                'value_with_tax' => (float)$maximumSellerAllowedPrice
            ];
            $maximumMapping[0]['schedule'] = $schedule;
            $purchasable_offer['maximum_seller_allowed_price'] = $maximumMapping;
            $schedule = [];
        }
        if (!empty($businessPrice)) {
            $businessPriceMapping['audience'] = 'B2B';
            $businessPriceMapping['currency'] = $currency;
            $businessPriceMapping['marketplace_id'] = $marketplaceId;
            $schedule[] = [
                'value_with_tax' => (float)$businessPrice
            ];
            $our_price[0]['schedule'] = $schedule;
            $businessPriceMapping['our_price'] = $our_price;
            $schedule = [];
            if (!empty($quantityDiscount) && !empty($quantityPriceType)) {
                $level = [];
                $quantityPriceSchedule = [];
                foreach ($quantityDiscount as $quantity) {
                    $level[] = [
                        'lower_bound' => $quantity['quantity'],
                        'value' => $quantity['price']
                    ];
                }
                $quantityPriceSchedule[] = [
                    'discount_type' => (string)$quantityPriceType,
                    'levels' => $level
                ];
                $quantityDiscountPlan[0]['schedule'] = $quantityPriceSchedule;
                $businessPriceMapping['quantity_discount_plan'] = $quantityDiscountPlan;
            }
        }

        if ($minimumPrice) {
            $schedule[] = [
                'value_with_tax' => (float)$minimumPrice
            ];
            $minimum_seller_allowed_price[0]['schedule'] = $schedule;
            $purchasable_offer['minimum_seller_allowed_price'] = $minimum_seller_allowed_price;
            $schedule = [];
        }

        $attributes['purchasable_offer'][0] = $purchasable_offer;
        if (!empty($businessPriceMapping)) {
            $attributes['purchasable_offer'][1] = $businessPriceMapping;
        }
        if (!empty($merchantShippingGroup)) {
            $attributes['merchant_shipping_group'][] = [
                'value' => $merchantShippingGroup,
                'marketplace_id' => $marketplaceId
            ];
        }
        $message = [
            'messageId' => $messageId,
            'sku' => $sku,
            'operationType' => "PARTIAL_UPDATE",
            'productType' => 'PRODUCT',
            'attributes' => $attributes
        ];

        return $message;
    }
}