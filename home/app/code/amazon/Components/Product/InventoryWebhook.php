<?php


namespace App\Amazon\Components\Product;

use App\Core\Components\Base;

class InventoryWebhook extends Base
{
//    private $_user_id;
//    private $_user_details;
//    private $_baseMongo;
//
//    public function init($request = [])
//    {
//        if (isset($request['user_id'])) {
//            $this->_user_id = (string)$request['user_id'];
//        } else {
//            $this->_user_id = (string)$this->di->getUser()->id;
//        }
//
//        $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
//        if (isset($request['shop_id'])) {
//            $this->_shop_id = (string)$request['shop_id'];
//            $shop = $this->_user_details->getShop($this->_shop_id, $this->_user_id);
//        } else {
//            $shop = $this->_user_details->getDataByUserID($this->_user_id, 'amazon');
//            $this->_shop_id = $shop['_id'];
//        }
//        $this->_remote_shop_id = $shop['remote_shop_id'];
//        /*$this->_site_id = $shop['warehouses'][0]['seller_id'];*/
//
//        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
//        return $this;
//    }
//
//    public function updateNew($data)
//    {
//        $success =  true;
//        $message = 'Inventory sync of selected product(s) initiated. It will take approx. 20 minutes.';
//        $feedContent = [];
//        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//        $activeAccounts = [];
//        $connectedAccounts = $commonHelper->getAllAmazonShops($this->_user_id);
//        $deleteOutOfStock = [];
//        $inventoryDisabledErrorProductList = [];
//        $accountDisabledErrorProductList = [];
//        $removeTagProductList = [];
//        $removeErrorProductList = [];
//        foreach ($connectedAccounts as $account) {
//            if ($account['warehouses'][0]['status'] == 'active') {
//                $activeAccounts[] = $account;
//            }
//        }
//        if (!empty($activeAccounts)) {
//            $productContainer = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
//            foreach ($data['source_product_ids'] as $key => $id) {
//                $product = $productContainer->findOne(['source_product_id' => $id, 'user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//                /*if ($product['type'] != 'variation') {
//                    $item = $this->prepareNew($product);
//                    if (!empty($item)) {
//                        if (isset($item['delete_out_of_stock']) && $item['delete_out_of_stock']) {
//                            $deleteOutOfStock[] = $product['source_product_id'];
//                        }
//                            $feedContent[$product['source_product_id']] = [
//                                'Id' => $product['_id'] . $key,
//                                'SKU' => $item['SKU'],
//                                'Quantity' => $item['Quantity'],
//                                'Latency' => $item['Latency']
//                            ];
//                    }
//                }*/
//                $invTemplateData = $this->prepareNew($product);
//                if ($product['type'] == 'variation' && !isset($product['group_id'])) {
//                    $removeTagProductList[] = $product['source_product_id'];
//                    $removeErrorProductList[] = $product['source_product_id'];
//
//                    // get child for parent products
//                    $childCollection = $productContainer->find(['group_id' => $product['source_product_id'], 'user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//                    $childArray = $childCollection->toArray();
//                    foreach ($childArray as $childKey => $child) {
//                        $item = $this->calculateNew($child, $invTemplateData);
//                        if (!empty($item)) {
//                            if($item['Quantity']<0){
//                                $item['Quantity']=0;
//                            }
//
//                            if (isset($item['delete_out_of_stock']) && $item['delete_out_of_stock']) {
//                                $deleteOutOfStock[] = $product['source_product_id'];
//                            }
//                            $feedContent[$child['source_product_id']] = [
//                                'Id' => $child['_id'] . $childKey,
//                                'SKU' => $item['SKU'],
//                                'Quantity' => $item['Quantity'],
//                                'Latency' => $item['Latency']
//                            ];
//
//                        } else {
//                            $removeTagProductList[] = $child['source_product_id'];
//                            $inventoryDisabledErrorProductList[] = $child['source_product_id'];
//                        }
//                    }
//                } else {
//                    $item = $this->calculateNew($product, $invTemplateData);
//                    if (!empty($item)) {
//                        if($item['Quantity']<0){
//                            $item['Quantity']=0;
//                        }
//                        if (isset($item['delete_out_of_stock']) && $item['delete_out_of_stock']) {
//                            $deleteOutOfStock[] = $product['source_product_id'];
//                        }
//                        $feedContent[$product['source_product_id']] = [
//                            'Id' => $product['_id'] . $key,
//                            'SKU' => $item['SKU'],
//                            'Quantity' => $item['Quantity'],
//                            'Latency' => $item['Latency']
//                        ];
//                    } else {
//                        $removeTagProductList[] = $product['source_product_id'];
//                        $inventoryDisabledErrorProductList[] = $product['source_product_id'];
//                    }
//                }
//            }
//
//            foreach ($activeAccounts as $ac) {
//                if (!empty($feedContent)) {
//                    $sentToDynamo = [];
//                    foreach ($feedContent as $source_product_id => $feedval) {
//                        $sentToDynamo[$source_product_id] = [
//                            'home_shop_id' =>$ac['_id'],
//                            'marketplace_id' => $ac['warehouses'][0]['marketplace_id'],
//                            'shop_id' => $ac['remote_shop_id'],
//                            'source_product_id'=>$source_product_id,
//                            'process'=>'1',
//                            'feedContent'=> json_encode($feedval),
//                            'user_id'=> $this->_user_id
//                        ];
//                    }
//
//                    $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
//                    $dynamoObj->setTable('amazon_inventory_mgmt');
//                    $dynamoObj->setUniqueKeys(['home_shop_id','source_product_id','marketplace_id']);
//                    $dynamoObj->setTableUniqueColumn('id');
//                    $res = $dynamoObj->save($sentToDynamo);
//                }
//            }
//
//            if (!empty($deleteOutOfStock)) {
//                // $data['source_product_ids'] = $deleteOutOfStock;
//                // if (isset($data['source_product_ids'])) {
//                //     $sourceProductIds = $data['source_product_ids'];
//                //     $res = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')
//                //         ->init()->addTags($sourceProductIds, \App\Amazon\Components\Common\Helper::PROCESS_TAG_DELETE_PRODUCT);
//                // }
//                // $productComponent = $this->di->getObjectManager()->get(\App\Amazon\Components\Product\Product::class);
//                // $productComponent->init()->delete($data, \App\Amazon\Components\Product\Product::OPERATION_TYPE_DELETE);
//            }
//        } else {
//            $removeTagProductList = $data['source_product_ids'];
//            $accountDisabledErrorProductList = $data['source_product_ids'];
//            $success = false;
//            $message = 'Please enable your Amazon account from Settings section to initiate the process.';
//        }
//
//        // setting category and account disabled error and removing tags
//        foreach ($connectedAccounts as $connectedAccount) {
//            if (isset($removeTagProductList) && !empty($removeTagProductList)) {
//                $tags = [
//                    \App\Amazon\Components\Common\Helper::PROCESS_TAG_INVENTORY_SYNC
//                ];
//                $feed = $this->di->getObjectManager()
//                    ->get(Feed::class)
//                    ->init(['user_id' => $this->_user_id])
//                    ->removeTag([], $tags, $connectedAccount['_id'], $removeTagProductList);
//            }
//
//            /*if (isset($accountDisabledErrorProductList) && !empty($accountDisabledErrorProductList)) {
//                $variantErrorList = [];
//                foreach ($accountDisabledErrorProductList as $sourceProductId) {
//                    $variantErrorList[$sourceProductId] = ['All connected accounts are disabled.'];
//                }
//                $feed = $this->di->getObjectManager()
//                    ->get(Feed::class)
//                    ->init(['user_id' => $this->_user_id])
//                    ->saveErrorInProduct($variantErrorList, [], $connectedAccount['_id'], 'inventory');
//            }*/
//
//            if (isset($inventoryDisabledErrorProductList) && !empty($inventoryDisabledErrorProductList)) {
//                $variantErrorList = [];
//                foreach ($inventoryDisabledErrorProductList as $sourceProductId) {
//                    $variantErrorList[$sourceProductId] = ['Inventory sync is disabled.'];
//                }
//                $feed = $this->di->getObjectManager()
//                    ->get(Feed::class)
//                    ->init(['user_id' => $this->_user_id])
//                    ->saveErrorInProduct($variantErrorList, [], $connectedAccount['_id'], 'inventory');
//            }
//
//            if (isset($removeErrorProductList) && !empty($removeErrorProductList)) {
//                $feed = $this->di->getObjectManager()
//                    ->get(Feed::class)
//                    ->init(['user_id' => $this->_user_id])
//                    ->removeErrorQuery($removeErrorProductList, $connectedAccount['_id'], 'inventory');
//            }
//        }
//
//        return ['success' => $success, 'message' => $message];
//    }
//
//    public function prepareNew($product, $invTemplateData = [], $defaultProfile = true)
//    {
//        $activeWarehouses = $this->getActiveShopifyWarehouses();
//        if (isset($product['profile'][0]['profile_id']) && !empty($product['profile'][0]['profile_id'])) {
//            $profileId = $product['profile'][0]['profile_id'];
//            $profile = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILES)->findOne(['profile_id' => (string)$profileId], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//            if ($profile) {
//                $invTemplateId = false;
//                foreach ($profile['settings']['amazon'] as $amazonTemplates) {
//                    if (isset($amazonTemplates['templates']['inventory'])) {
//                        $invTemplateId = $amazonTemplates['templates']['inventory'];
//                        break;
//                    }
//                }
//                if ($invTemplateId) {
//                    $invTemplate = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILE_SETTINGS)->findOne(['_id' => (int)$invTemplateId], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//                    if ($invTemplate && isset($invTemplate['data'])) {
//                        $invTemplateData = $invTemplate['data'];
//                        $invTemplateData['warehouses_settings'] = array_intersect($activeWarehouses, $invTemplateData['warehouses_settings']);
//                        $defaultProfile = false;
//                    }
//                }
//            }
//        }
//        if ($defaultProfile) {
//            $configurationContainer = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::CONFIGURATION);
//            $configuration = $configurationContainer->findOne(['user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//            if (isset($configuration['data']['inventory_settings'])) {
//                $invTemplateData = $configuration['data']['inventory_settings'];
//                $invTemplateData['warehouses_settings'] = array_intersect($activeWarehouses, $invTemplateData['warehouses_settings']);
//            } else {
//                $invTemplateData = \App\Amazon\Components\Common\Helper::DEFAULT_INVENTORY_SETTING;
//                $invTemplateData['warehouses_settings'] = $activeWarehouses;
//            }
//        }
//        return $invTemplateData;
//    }
//
//    public function updateNewOld($data)
//    {
//        $success =  true;
//        $message = 'Inventory sync of selected product(s) initiated. It will take approx. 20 minutes.';
//
//        $feedContent = [];
//        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//        $activeAccounts = [];
//        $connectedAccounts = $commonHelper->getAllAmazonShops($this->_user_id);
//        $deleteOutOfStock = [];
//        $inventoryDisabledErrorProductList = [];
//        $removeTagProductList = [];
//        $accountDisabledErrorProductList = [];
//        $removeErrorProductList = [];
//
//        foreach ($connectedAccounts as $account) {
//            if ($account['warehouses'][0]['status'] == 'active') {
//                $activeAccounts[] = $account;
//            }
//        }
//        if (!empty($activeAccounts)) {
//            $productContainer = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
//            foreach ($data['source_product_ids'] as $key => $id) {
//                $product = $productContainer->findOne(['source_product_id' => $id, 'user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//                /*if ($product['type'] != 'variation') {
//                    $item = $this->prepareNew($product);
//                    if (!empty($item)) {
//                        if (isset($item['delete_out_of_stock']) && $item['delete_out_of_stock']) {
//                            $deleteOutOfStock[] = $product['source_product_id'];
//                        }
//                            $feedContent[$product['source_product_id']] = [
//                                'Id' => $product['_id'] . $key,
//                                'SKU' => $item['SKU'],
//                                'Quantity' => $item['Quantity'],
//                                'Latency' => $item['Latency']
//                            ];
//                    }
//                }*/
//
//                if ($product['type'] == 'variation' && !isset($product['group_id'])) {
//                    $removeTagProductList[] = $product['source_product_id'];
//                    $removeErrorProductList[] = $product['source_product_id'];
//
//                    // get child for parent products
//                    $childCollection = $productContainer->find(['group_id' => $product['source_product_id'], 'user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//                    $childArray = $childCollection->toArray();
//                    foreach ($childArray as $childKey => $child) {
//                        $item = $this->prepareNew($child);
//                        print_r($item);
//                        die;
//                        if (!empty($item)) {
//                            if (isset($item['delete_out_of_stock']) && $item['delete_out_of_stock']) {
//                                $deleteOutOfStock[] = $product['source_product_id'];
//                            }
//                            $feedContent[$child['source_product_id']] = [
//                                'Id' => $child['_id'] . $childKey,
//                                'SKU' => $item['SKU'],
//                                'Quantity' => $item['Quantity'],
//                                'Latency' => $item['Latency']
//                            ];
//
//                            if (isset($child['source_product_id'])) {
//                                $res = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')
//                                    ->init()->addTags([$child['source_product_id']], \App\Amazon\Components\Common\Helper::PROCESS_TAG_INVENTORY_SYNC);
//                            }
//                        } else {
//                            $removeTagProductList[] = $child['source_product_id'];
//                            $inventoryDisabledErrorProductList[] = $child['source_product_id'];
//                        }
//                    }
//                } else {
//                    $item = $this->prepareNew($product);
//                    print_r($item);
//                    die;
//                    if (!empty($item)) {
//                        if (isset($item['delete_out_of_stock']) && $item['delete_out_of_stock']) {
//                            $deleteOutOfStock[] = $product['source_product_id'];
//                        }
//                        $feedContent[$product['source_product_id']] = [
//                            'Id' => $product['_id'] . $key,
//                            'SKU' => $item['SKU'],
//                            'Quantity' => $item['Quantity'],
//                            'Latency' => $item['Latency']
//                        ];
//                    } else {
//                        $removeTagProductList[] = $product['source_product_id'];
//                        $inventoryDisabledErrorProductList[] = $product['source_product_id'];
//                    }
//                }
//            }
//
//
//            foreach ($activeAccounts as $ac) {
//                if (!empty($feedContent)) {
//                    $specifics = [
//                        'ids' => array_keys($feedContent),
//                        'home_shop_id' => $ac['_id'],
//                        'marketplace_id' => $ac['warehouses'][0]['marketplace_id'],
//                        'shop_id' => $ac['remote_shop_id'],
//                        'feedContent' => base64_encode(json_encode($feedContent))
//                    ];
//                    $response = $commonHelper->sendRequestToAmazon('inventory-upload', $specifics, 'POST');
//
//                    if (isset($response['success'])) {
//                        if ($response['success']) {
//                            $feed = $this->di->getObjectManager()
//                               ->get(Feed::class)
//                               ->init(['user_id' => $this->_user_id, 'shop_id' => $ac['_id']])
//                               ->process($response);
//                        }
//                    }
//                }
//            }
//
//            if (!empty($deleteOutOfStock)) {
//                $data['source_product_ids'] = $deleteOutOfStock;
//                if (isset($data['source_product_ids'])) {
//                    $sourceProductIds = $data['source_product_ids'];
//                    $res = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')
//                        ->init()->addTags($sourceProductIds, \App\Amazon\Components\Common\Helper::PROCESS_TAG_DELETE_PRODUCT);
//                }
//                $productComponent = $this->di->getObjectManager()->get(\App\Amazon\Components\Product\Product::class);
//                $productComponent->init()->delete($data, \App\Amazon\Components\Product\Product::OPERATION_TYPE_DELETE);
//            }
//        } else {
//            $removeTagProductList = $data['source_product_ids'];
//            $accountDisabledErrorProductList = $data['source_product_ids'];
//            $success = false;
//            $message = 'Please enable your Amazon account from Settings section to initiate the process.';
//        }
//
//        // setting category and account disabled error and removing tags
//        foreach ($connectedAccounts as $connectedAccount) {
//            if (isset($removeTagProductList) && !empty($removeTagProductList)) {
//                $tags = [
//                    \App\Amazon\Components\Common\Helper::PROCESS_TAG_INVENTORY_SYNC
//                ];
//                $feed = $this->di->getObjectManager()
//                    ->get(Feed::class)
//                    ->init(['user_id' => $this->_user_id])
//                    ->removeTag([], $tags, $connectedAccount['_id'], $removeTagProductList);
//            }
//
//            /*if (isset($accountDisabledErrorProductList) && !empty($accountDisabledErrorProductList)) {
//                $variantErrorList = [];
//                foreach ($accountDisabledErrorProductList as $sourceProductId) {
//                    $variantErrorList[$sourceProductId] = ['All connected accounts are disabled.'];
//                }
//                $feed = $this->di->getObjectManager()
//                    ->get(Feed::class)
//                    ->init(['user_id' => $this->_user_id])
//                    ->saveErrorInProduct($variantErrorList, [], $connectedAccount['_id'], 'inventory');
//            }*/
//
//            if (isset($inventoryDisabledErrorProductList) && !empty($inventoryDisabledErrorProductList)) {
//                $variantErrorList = [];
//                foreach ($inventoryDisabledErrorProductList as $sourceProductId) {
//                    $variantErrorList[$sourceProductId] = ['Inventory sync is disabled.'];
//                }
//                $feed = $this->di->getObjectManager()
//                    ->get(Feed::class)
//                    ->init(['user_id' => $this->_user_id])
//                    ->saveErrorInProduct($variantErrorList, [], $connectedAccount['_id'], 'inventory');
//            }
//
//            if (isset($removeErrorProductList) && !empty($removeErrorProductList)) {
//                $feed = $this->di->getObjectManager()
//                    ->get(Feed::class)
//                    ->init(['user_id' => $this->_user_id])
//                    ->removeErrorQuery($removeErrorProductList, $connectedAccount['_id'], 'inventory');
//            }
//        }
//
//        return ['success' => $success, 'message' => $message];
//    }
//
//    public function prepareNewOld($product)
//    {
//        $value = [];
//        $profilePresent = false;
//        if (isset($product['profile']) && is_array($product['profile']) && !empty($product['profile'])) {
//            $profiles = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILES);
//            $profileSettings = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILE_SETTINGS);
//            $profileId = $this->di->getObjectManager()->get('App\Amazon\Components\Profile\Profile')->getProfileIdByProduct($product);
//            $profile = $profiles->findOne(['profile_id' => (string)$profileId], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//            $invTemplateId = false;
//            if ($profile) {
//                foreach ($profile['settings']['amazon'] as $homeShopId => $amazonTemplates) {
//                    if (isset($amazonTemplates['templates']['inventory'])) {
//                        $invTemplateId = $amazonTemplates['templates']['inventory'];
//                        break;
//                    }
//                }
//
//                $invTemplate = $profileSettings->findOne(['_id' => (int)$invTemplateId], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//                if ($invTemplate && isset($invTemplate['data'])) {
//                    $invTemplateData = $invTemplate['data'];
//                    $value = $this->calculateNew($product, $invTemplateData);
//                    $profilePresent = true;
//                }
//            }
//        }
//        if (!$profilePresent) {
//            $configurationContainer = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::CONFIGURATION);
//            $configuration = $configurationContainer->findOne(['user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//            $invTemplateData = false;
//            if (isset($configuration['data']['inventory_settings'])) {
//                $invTemplateData = $configuration['data']['inventory_settings'];
//            } else {
//                $invTemplateData = \App\Amazon\Components\Common\Helper::DEFAULT_INVENTORY_SETTING;
//                $warehouses = [];
//                $userDetailsTable =  $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
//                $userDetails = $userDetailsTable->findOne(['user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//                foreach ($userDetails['shops'] as $value) {
//                    if ($value['marketplace'] === 'shopify') {
//                        if (isset($value['warehouses'])) {
//                            foreach ($value['warehouses'] as $warehouse) {
//                                if ($warehouse['active']) {
//                                    $warehouses[] =  $warehouse['id'];
//                                }
//                            }
//                        }
//                        break;
//                    }
//                }
//
//                if (!empty($warehouse) && !isset($warehouse['success'])) {
//                    $invTemplateData['warehouses_settings'] = $warehouses;
//                }
//            }
//            if ($invTemplateData) {
//                $value = $this->calculateNew($product, $invTemplateData);
//            }
//        }
//        return $value;
//    }
//
//    public function calculateNew($product, $invTemplateData)
//    {
//        if ($invTemplateData['settings_enabled']) {
//            $amazonProductContainer = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::AMAZON_PRODUCT_CONTAINER);
//            $amazonProduct = $amazonProductContainer->findOne(['source_product_id' => $product['source_product_id'], 'user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//            // set sku
//            if ($amazonProduct && isset($amazonProduct['sku'])) {
//                $sku = $amazonProduct['sku'];
//            } elseif (isset($product['sku'])) {
//                $sku = $product['sku'];
//            } else {
//                $sku = $product['source_product_id'];
//            }
//
//            // set latency
//            if ($amazonProduct && isset($amazonProduct['inventory_fulfillment_latency'])) {
//                $latency = $amazonProduct['inventory_fulfillment_latency'];
//            } elseif (isset($invTemplateData['inventory_fulfillment_latency'])) {
//                $latency = $invTemplateData['inventory_fulfillment_latency'];
//            } else {
//                $latency = 1;
//            }
//
//            //set inventory policy & set quantity
//            if (isset($invTemplateData['max_inventory_level']) && $invTemplateData['max_inventory_level'] != "") {
//                $max_inventory_level = (int)$invTemplateData['max_inventory_level'];
//            } else {
//                $max_inventory_level = 999;
//            }
//            if (isset($invTemplateData['enable_max_inventory_level'])) {
//                $enable_max_inventory_level = $invTemplateData['enable_max_inventory_level'];
//            } else {
//                $enable_max_inventory_level = true;
//            }
//            $qty = 0;
//            if (array_key_exists('inventory_management', $product) && $enable_max_inventory_level == true) {
//                if (is_null($product['inventory_management'])) {
//                    $qty = $max_inventory_level;
//                } else {
//                    if ($product['inventory_management'] == "shopify") {
//                        if (isset($product['inventory_policy']) && $product['inventory_policy'] == "CONTINUE") {
//                            // then set qty either 999 or whatever filled in setting
//                            $qty = $max_inventory_level;
//                        } else {
//                            if ($amazonProduct && isset($amazonProduct['quantity'])) {
//                                $qty = $amazonProduct['quantity'];
//                            } else {
//
//                                /* code for selected warehouse */
//                                $qty = 0;
//                                if (isset($product['locations']) && is_array($product['locations'])) {
//                                    foreach ($product['locations'] as $location) {
//                                        if (in_array($location['location_id'], $invTemplateData['warehouses_settings'])) {
//                                            $qty = $qty + $location['available'];
//                                        }
//                                    }
//                                }
//                                //for handling location error exception while type conversion
//                                if ($qty == 0 && isset($product['quantity'])) {
//                                    $qty = (int)$product['quantity'];
//                                }
//                                $availableQty = $qty;
//                                /*Customise Inventory and Fixed Inventory have same (lowest)priority and then Threshold and then delete product (highest)*/
//                                if ($invTemplateData['settings_selected']['customize_inventory']) {
//                                    $qty = $this->changeInvNew((int)$availableQty, 'value_decrease', $invTemplateData['customize_inventory']['value']);
//                                } elseif ($invTemplateData['settings_selected']['fixed_inventory']) {
//                                    $qty = $this->changeInvNew((int)$availableQty, 'set_fixed_inventory', $invTemplateData['fixed_inventory']);
//                                } elseif ($invTemplateData['settings_selected']['delete_out_of_stock'] && (int)$availableQty == 0) {
//                                    return [
//                                        'SKU' => $sku,
//                                        'delete_out_of_stock' => true,
//                                        'Quantity' => $qty,
//                                        'Latency' => $latency
//                                    ];
//                                }
//                                if ($invTemplateData['settings_selected']['threshold_inventory']) {
//                                    if ((int)$invTemplateData['threshold_inventory'] >= (int)$availableQty) {
//                                        $qty = 0;
//                                    }
//                                }
//                            }
//                        }
//                    } else {
//                        $qty = $max_inventory_level;
//                    }
//                }
//            } else {
//                // when inv management does not exist
//                if ($amazonProduct && isset($amazonProduct['quantity'])) {
//                    $qty = $amazonProduct['quantity'];
//                } else {
//
//                    /* code for selected warehouse */
//                    $qty = 0;
//                    if (isset($product['locations']) && is_array($product['locations'])) {
//                        foreach ($product['locations'] as $location) {
//                            if (in_array($location['location_id'], $invTemplateData['warehouses_settings'])) {
//                                $qty = $qty + $location['available'];
//                            }
//                        }
//                    }
//                    //for handling location error exception while type conversion
//                    if ($qty == 0 && isset($product['quantity'])) {
//                        $qty = (int)$product['quantity'];
//                    }
//                    $availableQty = $qty;
//                    /*Customise Inventory and Fixed Inventory have same (lowest)priority and then Threshold and then delete product (highest)*/
//                    if ($invTemplateData['settings_selected']['customize_inventory']) {
//                        $qty = $this->changeInvNew((int)$availableQty, 'value_decrease', $invTemplateData['customize_inventory']['value']);
//                    } elseif ($invTemplateData['settings_selected']['fixed_inventory']) {
//                        $qty = $this->changeInvNew((int)$availableQty, 'set_fixed_inventory', $invTemplateData['fixed_inventory']);
//                    } elseif ($invTemplateData['settings_selected']['delete_out_of_stock'] && (int)$availableQty == 0) {
//                        return [
//                            'SKU' => $sku,
//                            'delete_out_of_stock' => true,
//                            'Quantity' => $qty,
//                            'Latency' => $latency
//                        ];
//                    }
//                    if ($invTemplateData['settings_selected']['threshold_inventory']) {
//                        if ((int)$invTemplateData['threshold_inventory'] >= (int)$availableQty) {
//                            $qty = 0;
//                        }
//                    }
//                }
//            }
//            return [
//                'SKU' => $sku,
//                'Quantity' => $qty,
//                'Latency' => $latency
//            ];
//        } else {
//            return [];
//        }
//    }
//
//    public function changeInvNew($qty, $attribute, $changeValue)
//    {
//        switch ($attribute) {
//            case 'value_increase':
//                $qty = $qty + $changeValue;
//                break;
//            case 'value_decrease':
//                $qty = $qty - $changeValue;
//                break;
//            case 'percentage_increase':
//                $qty = $qty + (($changeValue/100) * $qty);
//                break;
//            case 'percentage_decrease':
//                $qty = $qty - (($changeValue/100) * $qty);
//                break;
//            case 'set_fixed_inventory':
//                $qty = $changeValue;
//                break;
//            default:
//                break;
//        }
//        if ((int)$qty < 0) {
//            $qty = 0;
//        }
//        return ceil($qty);
//    }
//
//    public function getActiveShopifyWarehouses($warehouses = [])
//    {
//        $userDetailsTable =  $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
//        $userDetails = $userDetailsTable->findOne(['user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//        foreach ($userDetails['shops'] as $value) {
//            if ($value['marketplace'] === 'shopify') {
//                if (isset($value['warehouses'])) {
//                    foreach ($value['warehouses'] as $warehouse) {
//                        if ($warehouse['active']) {
//                            $warehouses[] =  $warehouse['id'];
//                        }
//                    }
//                }
//                break;
//            }
//        }
//        return $warehouses;
//    }
//
//    public function update($data)
//    {
//        if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
//            $ids = $data['source_product_ids'];
//            $profileIds = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\Helper')
//                ->getProfileIdsByProductIds($data['source_product_ids']);
//            if (!empty($profileIds)) {
//                //get profiles from profile Ids
//                $profileCollection = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILES);
//                $profiles = $profileCollection->find(['profile_id' => ['$in' => $profileIds]], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//
//                foreach ($profiles as $profileKey => $profile) {
//                    $profileId = $profile['profile_id'];
//                    $accounts = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\Helper')
//                        ->getAccountsByProfile($profile);
//
//                    foreach ($accounts as $homeShopId => $account) {
//                        $productIds = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\Helper')
//                            ->getAssociatedProductIds($profile, $ids);
//
//                        if (!empty($productIds)) {
//                            $inventoryTemplateId = $account['templates']['inventory'];
//                            $categoryTemplateId = $account['templates']['category'];
//                            foreach ($account['warehouses'] as $amazonWarehouse) {
//                                $marketplaceIds = $amazonWarehouse['marketplace_id'];
//                                if (!empty($marketplaceIds)) {
//                                    $specifics = [
//                                        'ids' => $productIds,
//                                        'home_shop_id' => $homeShopId,
//                                        'marketplace_id' => $marketplaceIds,
//                                        'profile_id' => $profile['profile_id'],
//                                        'inventory_template' => $inventoryTemplateId,
//                                        'category_template' => $categoryTemplateId,
//                                        'shop_id' => $account['remote_shop_id']
//                                    ];
//                                    $feedContent = $this->prepare($specifics);
//                                    if (!empty($feedContent)) {
//                                        $specifics['feedContent'] = $feedContent;
//                                        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
//                                        $response = $commonHelper->sendRequestToAmazon('inventory-upload', $specifics, 'POST');
//                                        $feed = $this->di->getObjectManager()
//                                            ->get(Feed::class)
//                                            ->init(['user_id' => $this->_user_id, 'shop_id' => $homeShopId])
//                                            ->process($response);
//                                    }
//                                }
//                            }
//                        }
//                    }
//                }
//            }
//        }
//    }
//
//    public function prepare($specifics)
//    {
//        $feedContent = [];
//        $inventoryTemplate = [];
//        if (isset($specifics) && !empty($specifics)) {
//            $templatesCollection = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILE_SETTINGS);
//            if (isset($specifics['inventory_template']) && $specifics['inventory_template']) {
//                $inventoryTemplate = $templatesCollection->findOne(['_id' => (int)$specifics['inventory_template']], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//            }
//            if (isset($specifics['category_template']) && $specifics['category_template']) {
//                $categoryTemplate = $templatesCollection->findOne(['_id' => (int)$specifics['category_template']], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//                $requiredAttributes = $categoryTemplate['data']['attributes_mapping']['required_attribute'];
//                $skuAttributeIndex = array_search('SKU', array_column($requiredAttributes, 'amazon_attribute'));
//                if (isset($requiredAttributes[$skuAttributeIndex]['shopify_attribute']) && !empty($requiredAttributes[$skuAttributeIndex]['shopify_attribute'])) {
//                    $attributeSku = $requiredAttributes[$skuAttributeIndex]['shopify_attribute'];
//                }
//            }
//            if (!isset($attributeSku)) {
//                $attributeSku = 'sku';
//            }
//
//            if (!empty($inventoryTemplate)) {
//                $ids = $specifics['ids'];
//
//                //get products from mongo db
//                $productCollection = $this->_baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
//                $products = $productCollection->find(['source_product_id' => ['$in' => $ids]], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//
//                foreach ($products as $key => $product) {
//                    // case 1 : for configurable products
//                    if ($product['type'] == 'variation' && !isset($product['group_id'])) {
//
//                        // get childs for parent products
//                        $childs = $productCollection->find(['group_id' => ['$in' => [$product['source_product_id']]]], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
//
//                        foreach ($childs as $child) {
//                            $value = $this->calculate($child, $specifics, $inventoryTemplate);
//                            if (isset($child[$attributeSku])) {
//                                $sku = $child[$attributeSku];
//
//                                $feedContent[$sku.$child['_id']] = [
//                                    'Id' => $child['_id'] . $key,
//                                    'SKU' => $sku,
//                                    'Quantity' => $value['Quantity'],
//                                    'Latency' => $value['Latency']
//                                ];
//                            }
//                        }
//                    } else {
//                        $value = $this->calculate($product, $specifics, $inventoryTemplate);
//                        if (isset($product[$attributeSku])) {
//                            $sku = $product[$attributeSku];
//
//                            $feedContent[$sku.$product['_id']] = [
//                                'Id' => $product['_id'] . $key,
//                                'SKU' => $sku,
//                                'Quantity' => $value['Quantity'],
//                                'Latency' => $value['Latency']
//                            ];
//                        }
//                    }
//                }
//            }
//        }
//        return $feedContent;
//    }
//
//    public function calculate($product, $specifics, $inventoryTemplate)
//    {
//        // setting fulfillment latency
//        $fulfillmentLatencyAttribute = $inventoryTemplate['data']['inventory_fulfillment_latency_mapping'];
//        if ($fulfillmentLatencyAttribute == 'custom') {
//            $fulfillmentLatency = (int)$inventoryTemplate['data']['inventory_fulfillment_latency_value'];
//        } else {
//            $fulfillmentLatency = (int)$product[$fulfillmentLatencyAttribute];
//        }
//
//        //setting qty
//        $qtyAttribute = 'quantity';
//        $qty = (int)$product[$qtyAttribute];
//        if ($inventoryTemplate['data']['change_inventory'] == 'change') {
//            $modifier = ['modifier'];
//            $modifyInventory = $inventoryTemplate['data']['modify_inventory'];
//            $qty = $this->changeInventory($modifier, $modifyInventory, $qty);
//        }
//        if ($qty < 0) {
//            $qty = 0;
//        }
//
//        return [
//            'Quantity' => $qty,
//            'Latency' => $fulfillmentLatency
//        ];
//    }
//
//    public function changeInventory($modifier, $modifyInventory, $qty)
//    {
//        if (in_array('modifier', $modifier)) {
//            $changeValue = (int)$modifyInventory['change_value'];
//            if ($modifyInventory['change_type'] == 'increase') {
//                if ($modifyInventory['change_by'] == 'percentage') {
//                    $qty = ($qty + ($changeValue / 100) * $qty);
//                } elseif ($modifyInventory['change_by'] == 'value') {
//                    $qty = ($qty + $changeValue);
//                }
//            } elseif ($modifyInventory['change_type'] == 'decrease') {
//                if ($modifyInventory['change_by'] == 'percentage') {
//                    $qty = ($qty - ($changeValue / 100) * $qty);
//                } elseif ($modifyInventory['change_by'] == 'value') {
//                    $qty = ($qty - $changeValue);
//                }
//            }
//        }
//        return (int)$qty;
//    }
}
