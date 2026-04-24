<?php

namespace App\Amazon\Components\Product;

use App\Amazon\Components\Listings\Error;
use App\Amazon\Components\Listings\CategoryAttributes;
use App\Amazon\Components\Template\Category;
use App\Amazon\Components\Common\Barcode;
use App\Amazon\Components\Listings\ListingHelper;
use App\Amazon\Components\LocalDeliveryHelper;
use App\Amazon\Components\ProductHelper;
use App\Amazon\Components\Product\Inventory;
use App\Amazon\Components\Product\Marketplace;

use MongoDB\BSON\ObjectId;
use App\Core\Models\User;
use Exception;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\Feed\Feed;
use App\Core\Components\Base as Base;
use App\Connector\Components\Helper as ConnectorHelper;

class UnifiedFeed extends Base
{
    private $_user_id;
    private $configObj;
    private $_baseMongo;
    private $sourceShopId = '';
    private $targetShopId = '';
    private $sourceMarketplace = '';
    private $targetMarketplace = '';
    private $marketplaceId;
    private $activeAccount;
    private $remoteShopId;
    private $customPriceSyncRules;
    private $currencyValue;

    public function init($request = [])
    {
        $payload = json_decode(json_encode($request, true), true);
        if (isset($payload['data']['params'])) {
            $params = $payload['data']['params'];

            if (isset($params['user_id'])) {
                $this->_user_id = (string) $params['user_id'];
            }

            $this->configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
            $appTag = $this->di->getAppCode()->getAppTag();
            if (isset($payload['raw_data']['appTag'])) {
                $appTag = $payload['raw_data']['appTag'];
            }

            if ($appTag == 'default') {
                $this->configObj->setAppTag(null);
            } else {
                $this->configObj->setAppTag($appTag);
            }
            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $targetShop = $user_details->getShop($params['target']['shopId'], $this->_user_id);
            $this->currencyValue = $targetShop['currency'] ?? "";
            foreach ($targetShop['warehouses'] as $warehouse) {
                if ($warehouse['status'] == "active") {
                    $this->activeAccount = $warehouse;
                    $this->marketplaceId = $warehouse['marketplace_id'];
                }
            }
            $this->remoteShopId = $targetShop['remote_shop_id'];
            $this->sourceShopId = $params['source']['shopId'];
            $this->targetShopId = $params['target']['shopId'];
            $this->sourceMarketplace = $params['source']['marketplace'];
            $this->targetMarketplace = $params['target']['marketplace'];

            $this->configObj->setUserId($params['user_id']);
            $this->configObj->setTargetShopId($params['target']['shopId']);
            $this->configObj->setSourceShopId($params['source']['shopId']);
            $this->configObj->setTarget($params['target']['marketplace']);

            $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');

            return $this;
        }
        return false;
    }
    public function groupSync($data)
    {
        $productObject = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product');
        // print_r($data);die;
        if (!empty($this->sourceShopId) && !empty($this->targetShopId) && !empty($data['data']['rows'])) {
            $products = $data['data']['rows'];
            $userId = $this->_user_id;
            $time = date(DATE_ISO8601);
            $container_ids = [];
            $sourceShopId = $this->sourceShopId;
            $targetShopId = $this->targetShopId;
            $operationType = $data['data']['params']['groupWiseAction'];
             $allParentDetails = $data['data']['all_parent_details']??[];
            $allProfileInfo = $data['data']['all_profile_info'] ?? [];
            $tempOperationType = $operationType;
            $actionType = [];
            $addTagProductList =[];
            $tags = [];
            $productErrorList = [];
            $groupResponse = [];
            $sourceProductIds = [];
            $feedContent = [];
            $specificData['marketplaceId'] = $this->marketplaceId;
            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
            $marketplaceComponent = $this->di->getObjectManager()->get(Marketplace::class);
           
            if ($this->activeAccount) {
                $currencyCheck = $this->currencyCheck();
               
              
                $priceObject = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Price');
                $priceObject->init($data);
                $InvObject = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\BulkInventory');
                $InvObject->init($data);
                $ImgObject = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Image');
                $ImgObject->init($data);

                $this->customPriceSyncRules = $priceObject->getPriceSyncRulesConfig($userId, $sourceShopId, $targetShopId);
                 
                if (in_array('inventory_sync', $operationType)) {
                    $sourceProductIdsList = array_unique(array_column($products, 'source_product_id'));
                    $sourceProductIdsList = array_values($sourceProductIdsList);
                    $fbaListings = $InvObject->fetchFBAListings($userId, $targetShopId, $sourceProductIdsList);
                }

                foreach ($products as  $key => $product) {
                    $product = json_decode(json_encode($product),true);
                     if(isset($product['profile_info']['$oid'])){
                            $product['profile_info'] = $allProfileInfo[$product['profile_info']['$oid']];
                        }
                         if(isset($product['parent_details']) && is_string($product['parent_details'])){
                            $product['parent_details'] = $allParentDetails[$product['parent_details']];
                        }
            
                    $operationType = $tempOperationType;
                    $product = json_decode(json_encode($product),true);
                    $container_ids[$product['source_product_id']] = $product['container_id'];
                    if (isset($product['edited'])) {
                            $target = $product['edited'];
                    }
                    // print_r($target);die;
                    if(!isset($target['status']) || $target['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER)
                    {
                            $productErrorList[$product['source_product_id']] = ['AmazonError501'];
                    }else
                    {

                        
                        if ($product['type'] == 'variation') {
                        //need to discuss what to return for variant parent product as variant parent's inv will not sync to amazon
                        if (!in_array('image_sync', $operationType)) {
                           continue;
                        } else {
                            
                            $operationType = ['image_sync'];
                            $imgContent = $ImgObject->prepare($product, Product::PRODUCT_TYPE_PARENT);
                        }
                    } else {
                      
                        if (in_array('image_sync', $operationType)) {

                            $imgContent = $ImgObject->prepare($product, false);
                           
                        }
                    }
                    
                    $priceTemplate = $priceObject->prepare($product);
                    $invTemplate = $InvObject->prepare($product);
                    $alreadySavedActivities[$product['source_product_id']] =
                        $product['edited']['last_activities'] ?? [];
                        
                    if (in_array('inventory_sync', $operationType)) {
                        
                        $type = $invTemplate['type'] ?? false;
                        
                        
                        if (isset($fbaListings[$product['source_product_id']])) {
                            if ($fbaListings[$product['source_product_id']] !== 'DEFAULT') {
                                $productErrorList[$product['source_product_id']] = ['AmazonError124'];
                                continue;
                            }
                        }
                        // print_r($invTemplate);die;
                        $calculateResponse = $InvObject->calculate($product, $invTemplate, $this->sourceMarketplace);
                      
                        if (!empty($calculateResponse)) {
                            if (isset($calculateResponse['error'], $calculateResponse['saveInConfig'])) {
                                $update = false;
                                if (isset($invTemplateData['warehouse_disabled'])) {
                                    $value = $invTemplateData['warehouse_disabled'];
                                    if (!in_array($type, $value)) {
                                        $update = true;
                                        array_push($value, $type);
                                    }
                                } else {
                                    $update = true;
                                    $value[] = $type;
                                }

                                if ($update) {
                                    $savedData = [
                                        'group_code' => 'inventory',
                                        'data' => [
                                            'warehouse_disabled' => $value,
                                        ],
                                    ];
                                    $configData = $data['data']['params'];
                                    $configData['data'][0] = $savedData;
                                    $res = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper')->saveConfigData($configData);
                                }
                                
                                $productErrorList[$product['source_product_id']] = [$calculateResponse['error']];
                            } else {
                                if (isset($calculateResponse['Quantity']) && $calculateResponse['Quantity'] < 0) {
                                    $calculateResponse['Quantity'] = 0;
                                }
                                
                                if (!$calculateResponse['Latency']) {
                                    $calculateResponse['Latency'] = "2"; //if latency is less then 0 , then giving the default value
                                    }
                                    // if(empty($feedContent)){
                                    //      $feedContent[$product['source_product_id']] = [
                                    //     'Id' => $product['_id'],
                                    //     'SKU' => $calculateResponse['SKU'],
                                    //     'Quantity' => $calculateResponse['Quantity'],
                                    //     'Latency' => $calculateResponse['Latency'],
                                    //     'time' => $time,
                                    // ];

                                    // }
                                    $feedContent[$product['source_product_id']]['Id'] = $product['_id'];
                                    
                                    $feedContent[$product['source_product_id']]['SKU'] = (string) $calculateResponse['SKU'];

                                    $feedContent[$product['source_product_id']]['Quantity'] = $calculateResponse['Quantity'];
                                     $feedContent[$product['source_product_id']]['Latency'] = $calculateResponse['Latency'];
                                   
                                    $inventoryAttribute = $InvObject->getInventoryAttributeValues($product);
                                    if (isset($inventoryAttribute['restock_date'])) {
                                        $feedContent[$product['source_product_id']]['RestockDate'] = $inventoryAttribute['restock_date'];
                                    }
                            }
                        } else {
                            $inventoryDisabledErrorProductList[] = $product['source_product_id'];
                            $productErrorList[$product['source_product_id']] = ['AmazonError123'];
                        }
                    }
                    if (in_array('image_sync', $operationType) && !empty($imgContent)) {
                        //  if(empty($feedContent)){
                            $feedContent[$product['source_product_id']]['SKU'] = (string) $imgContent['SKU'];
                            $feedContent[$product['source_product_id']]['Id'] = $imgContent['Id'];
                            // }

                        $feedContent[$product['source_product_id']]['feed'] = $imgContent['feed'];
                        $feedContent[$product['source_product_id']]['time'] = $time;

                        
                        $sourceProductIds[] = $product['source_product_id'];
                    }
                    if (in_array('price_sync', $operationType)) {
                        $calculateResponse = $priceObject->calculate($product, $priceTemplate, $currencyCheck, false);
                       

                        if (!empty($calculateResponse) && isset($calculateResponse['SKU']) && isset($calculateResponse['StandardPrice'])) {
                            // if(empty($feedContent)){
                                $feedContent[$product['source_product_id']]['SKU'] = (string)$calculateResponse['SKU'];
                                $feedContent[$product['source_product_id']]['Id'] = $product['_id'] . $key;
                                $feedContent[$product['source_product_id']]['time'] = $time;

                                // }
                                $feedContent[$product['source_product_id']]['SalePrice'] =$calculateResponse['SalePrice'];
                            $feedContent[$product['source_product_id']]['StandardPrice'] =$calculateResponse['StandardPrice'];
                            $feedContent[$product['source_product_id']]['StartDate'] =$calculateResponse['StartDate']; 
                            $feedContent[$product['source_product_id']]['EndDate'] =$calculateResponse['EndDate'];
                            $feedContent[$product['source_product_id']]['MinimumPrice'] =$calculateResponse['MinimumPrice'];
                            
                            
                            if ($calculateResponse['BusinessPrice'] > 0) {
                                  $feedContent[$product['source_product_id']]['BusinessPrice'] = $calculateResponse['BusinessPrice'];
                                 $feedContent[$product['source_product_id']]['QuantityDiscount'] = $calculateResponse['QuantityDiscount'] ?? [];
                                 $feedContent[$product['source_product_id']]['QuantityPriceType'] = $calculateResponse['QuantityPriceType'] ?? [];
                                }
                            }
                    }
                }
            }
                $baseParams = [
                    'user_id' => $userId,
                    'tag' => Helper::PROCESS_TAG_GROUP_FEED,
                    'type' => 'group',
                    'target' => [
                        'shopId' => $targetShopId,
                        'marketplace' =>  $this->targetMarketplace 
                        ],
                        'source' => [
                            'shopId' => $sourceShopId,
                            'marketplace' => $this->sourceMarketplace
                            ],
                            'marketplaceId' => $this->marketplaceId,
                            'unsetTag' => Helper::PROCESS_TAG_GROUP_SYNC
                        ];
                if (!empty($feedContent) && !empty($userId)) {
                    $specifics = [
                        'ids' => $sourceProductIds,
                        'home_shop_id' => $targetShopId,
                        'marketplace_id' => $this->marketplaceId,
                        'sourceShopId' => $sourceShopId,
                        'shop_id' => $this->remoteShopId,
                        'feedContent' => base64_encode(serialize($feedContent)),
                        'user_id' => $userId,
                        'operation_type' => 'Update',
                         'currency' => $this->currencyValue,
                         'unified_json_feed' => true
                        ];
                    $groupResponse = $commonHelper->sendRequestToAmazon('group-submit', $specifics, 'POST');
                    // print_r($groupResponse);die;
                    if (isset($groupResponse['success']) && $groupResponse['success']) {
                            $feedActivtiy = [];
                            $InvObject->saveFeedInDb($feedContent, 'group', $container_ids, $data['data']['params'], $alreadySavedActivities);
                        }
                        $baseParams['tag'] = Helper::PROCESS_TAG_GROUP_FEED;
                        $baseParams['unsetTag'] = Helper::PROCESS_TAG_GROUP_SYNC;
                        // print_r($baseParams);die;
                    $productSpecifics = $marketplaceComponent->init($baseParams)->prepareAndProcessSpecifices($groupResponse, $productErrorList, $feedContent, $products);

                    $addTagProductList = $productSpecifics['addTagProductList'];
                    $productErrorList = $productSpecifics['productErrorList'];
                    $responseLocal = $productObject->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);
                    if (!empty($responseLocal) && !empty($responseOnline)) {
                        $responseData = array_merge($responseLocal, $responseOnline);
                    } elseif (!empty($responseLocal)) {
                        $responseData = $responseLocal;
                    } elseif (!empty($responseOnline)) {
                        $responseData = $responseOnline;
                    }

                    return $responseData;
                    
                }
                $baseParams['tag'] = Helper::PROCESS_TAG_GROUP_SYNC;
                $baseParams['unsetTag'] = false;
                $marketplaceComponent->init($baseParams)->prepareAndProcessSpecifices($groupResponse, $productErrorList, $feedContent, $products);
                return $productObject->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);
        }
            return $productObject->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0);
        } else {
            return $productObject->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0);
            
        }
    }
    public function currencyCheck()
    {
        $currencyCheck = 1;
        $this->configObj->setUserId($this->_user_id);
        $this->configObj->setTarget($this->targetMarketplace);
        $this->configObj->setTargetShopId($this->targetShopId);
        $this->configObj->setSourceShopId($this->sourceShopId);
        $this->configObj->setGroupCode('currency');
        $amazonCurrency = $this->configObj->getConfig('target_currency');
        $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);
        $sourceCurrency = $this->configObj->getConfig('source_currency');
        $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);
        if (isset($sourceCurrency[0]['value'], $amazonCurrency[0]['value']) && $sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
            $currencyCheck = true;
        } else {
            $amazonCurrencyValue = $this->configObj->getConfig('target_value');
            $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
            if (isset($amazonCurrencyValue[0]['value'])) {
                $currencyCheck = $amazonCurrencyValue[0]['value'];
            }
        }
        return $currencyCheck;
    }
}
