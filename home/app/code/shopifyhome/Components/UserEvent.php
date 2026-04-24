<?php

namespace App\Shopifyhome\Components;

use App\Core\Components\Base;
use App\Shopifyhome\Components\Mail\Seller;
use Exception;
use App\Core\Models\Config\Config;
use App\Connector\Models\QueuedTasks;
use App\Shopifyhome\Components\Product\Vistar\Route\Requestcontrol;
use App\Shopifyhome\Components\Product\Vistar\Data;
use App\Shopifyhome\Models\SourceModel;
use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Shop\Utility;
use Phalcon\Events\Event;

class UserEvent extends Base
{
    public const MARKETPLACE = "shopify";

    /**
     * Perform actions required after shopify is connected
     * @param object $event
     * @param object $myComponent
     * @param array $shop
     */
    public function afterAccountConnection(Event $event, $myComponent, $shops): void
    {
        try{
            $date = date('d-m-Y');
            $logFile = "onboarding/shopify/AfterAccountConnection/{$date}.log";
            $user_id = $this->di->getUser()->id;
            //When only shopify is connected as source ( only source is connected )
            $this->di->getLog()->logContent('account connection event =' . json_encode($shops), 'info', $logFile);
            $this->di->getLog()->logContent('account connection event =' . json_encode($user_id), 'info', $logFile);
            $requestData = $this->di->getRequest()->get();
            $configData = $this->getConfigData($requestData);
            if (isset($shops['source_shop']) && !isset($shops['target_shop']) && $shops['source_shop']['marketplace'] === self::MARKETPLACE) {
                //Ex 1 - Email verification
                if (isset($shops['source_shop']) && !empty($shops['source_shop'])) {
                    $mailData = [
                    'email' => $shops['source_shop']['email'] ?? null,
                    'name' => $shops['source_shop']['name'] ?? null
                    ];
                    $this->di->getObjectManager()->get(Seller::class)->onboardNew($mailData, $configData);
                }
            }
    
            //When shopify is connected as target ( both sources and target are connected ) 
            if (isset($shops['source_shop'], $shops['target_shop']) && $shops['target_shop']['marketplace'] === self::MARKETPLACE) {
                //Ex 1 - Initiate Importing of source
            }
    
            //ToDo - Needs to be moved to target marketplace module afterAccountConnection event 
            if (isset($shops['source_shop'], $shops['target_shop']) && $shops['source_shop']['marketplace'] === self::MARKETPLACE) {
                /**for applying restrictions on the plan */
                $response = $this->beforeProductImport($shops);
                $this->di->getLog()->logContent('beforeProductImport res =' . json_encode($response), 'info', $logFile);
                $canProceed = true;
                if($response['success'] && isset($response['data']['can_proceed']) && ($response['data']['can_proceed'] == false)) {
                    $canProceed = false;
                }

                 /**for applying restrictions on the plan */
                if($canProceed) {
                    $this->initiateShopifyImporting($shops);
                }
            }
        }catch (Exception $e) {
            $date = date('d-m-Y');
            $logFile = "onboarding/shopify/AfterAccountConnection/{$date}.log";
            $this->di->getLog()->logContent('after account connection , shopify module exception msg = ' . $e->getMessage().', trace = '.json_encode($e->getTrace()), 'info', $logFile);
        }
    }

    private function getConfigData($data)
    {
        $configData = [];
        if (!empty($data['app_code'])) {
            $marketplace = !empty($data['marketplace']) ? $data['marketplace'] : self::MARKETPLACE;
            $configData = $this->di->getConfig()->get('apiconnector')->get($marketplace)->get($data['app_code']);
        }

        return $configData;
    }


    public function initiateShopifyImporting($shops): void
    {
        //starting importing of products from shopify after account is connected successfully
            $source_shop_id = $shops['source_shop']['_id'] ?? '';
            if (isset($shops['target_shop']['marketplace'], $shops['target_shop']['_id']) && $source_shop_id != '')
            {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollection('product_container');
                $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];
                //$searchResponse = $collection->find(['shop_id' => $source_shop_id], $options)->toArray();
                $searchResponse = $collection->count(['user_id' => $this->di->getUser()->id,'shop_id' => $source_shop_id]);
    
                if(!$searchResponse)
                {
                    $rawBody = [
                        "source" => [
                            "marketplace" => $shops['source_shop']['marketplace'] ?? false,
                            "shopId" => $shops['source_shop']['_id'] ?? false,
                            "data" => $shops['source_shop'] ?? []
                        ],
                        "target" => [
                            "marketplace" => $shops['target_shop']['marketplace'] ?? false,
                            "shopId" => $shops['target_shop']['_id'] ?? false,
                            "data" => $shops['target_shop'] ?? []
                        ],
                    ];
                    $product = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
                    $import = $product->initiateImport($rawBody);
                    if(isset($import['success'], $import['message']) && (!$import['success'] && $import['message'] == 'No Product found for importing'))
                    {
                        //if imported product count is zero which means no active products found on shopify then we will call the function for creating webhooks
                        $eventData['source_marketplace'] = $rawBody['source']['marketplace'];
                        $eventData['source_shop_id'] =  $rawBody['source']['shopId'];
                        $eventData['target_marketplace'] = $rawBody['target']['marketplace'];
                        $eventData['target_shop_id'] = $rawBody['target']['shopId'];
                        $eventData['isImported'] = false;
                        $eventsManager = $this->di->getEventsManager();
                        $eventsManager->fire('application:afterProductImport', $this, $eventData);
                    }
                }
            }
    }


    /**
     * Removes the shop from all the marketplace where its registered as source
     * @param object $myComponent
     * @param array $shopData
     */
    public function beforeDisconnect(Event $event, $myComponent, $shopData): void
    {
        if ($shopData['custom_data']['marketplace'] == self::MARKETPLACE) {
            $shopId = $shopData['custom_data']['_id'];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollection('user_details');
            $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];
            $user_id = $this->di->getUser()->id;
            $searchResponse = $collection->find(['user_id' => $user_id], $options)->toArray();
            foreach ($searchResponse[0]['shops'] as $key => $value) {
                if (isset($value['sources'])) {
                    foreach ($value['sources'] as $index => $val) {
                        if (isset($val['shop_id']) && $val['shop_id'] == $shopId) {
                            array_splice($searchResponse[0]['shops'][$key]['sources'], $index, 1);
                        }
                    }
                }
            }

            $updateResponse = $collection->updateOne(['user_id' => $user_id], ['$set' => $searchResponse[0]]);
        }
    }

    public function afterProductImport(Event $event, $myComponent, $params)
    {
        if (isset($params['source_marketplace']) && $params['source_marketplace'] !== self::MARKETPLACE) {
            return false;
        }

        $logFile = 'shopify/afterProductEvent/' . $this->di->getUser()->id . '/data.log';
        $this->di->getLog()->logContent('In afterProductImport  params => ' . print_r($params, true), 'info', $logFile);
        if (isset($params['additional_data']['filterType'])
            && $params['additional_data']['filterType'] == 'metafield') {
            $this->di->getLog()->logContent('Metafield Import Event Fired...', 'info', $logFile);
            $eventsManager = $this->di->getEventsManager();
            $eventsManager->fire('application:afterMetafieldImport', $this, $params);
            return true;
        }

        $source_shop_id = $params['source_shop_id'];
        $source_marketplace = $params['source_marketplace'];
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shop = $userDetails->getShop($source_shop_id, $this->di->getUser()->id, true);
        $appCode = $this->di->getAppCode()->get()[$source_marketplace];
        if($appCode == 'default') {
            $appCode = $shop['apps'][0]['code'];
            $this->di->getLog()->logContent('Changing AppCode from default to => ' . json_encode($appCode), 'info', $logFile);
        }
        $createDestinationResponse = $this->createDestination($shop);
        $this->di->getLog()->logContent('afterProductImport entered $createDestinationResponse = '
            . print_r($createDestinationResponse, true), 'info', 'shopify/afterProductEvent/'
            . $this->di->getUser()->id . '/data.log');
        $destination_id = $createDestinationResponse['success']
            ? $createDestinationResponse['destination_id'] : false;
        if (!$destination_id) {
            print_r("destination not created");
            return false;
        }

        $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")
            ->routeRegisterWebhooks($shop, $source_marketplace, $appCode, $destination_id);
        $this->di->getLog()->logContent('createWebhookRequest Response = '
            . print_r($createWebhookRequest, true), 'info', $logFile);
        $this->initiateMetafieldProcess($params);
        $this->updateShopAndWarehousesDetails($this->di->getUser()->id, $source_shop_id);
        return true;
    }

    public function initiateMetafieldProcess($eventData)
    {
        if (isset($eventData['additional_data'], $eventData['source_shop_id']) && !isset($eventData['additional_data']['metafield_status'])) {
            $userId = $this->di->getUser()->id;
            $logFile = "shopify/metafieldImport/{$userId}/" . date('d-m-Y') . '.log';
            $userDetailsObj = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class);
            $shopData = $userDetailsObj->getShop($eventData['source_shop_id'], $userId);
            if (!empty($shopData)) {
                $configObj = $this->di->getObjectManager()
                    ->get(Config::class);
                $configObj->setGroupCode('product');
                $configObj->setUserId($userId);
                $configObj->setSource($shopData['marketplace']);
                $configObj->setSourceShopId($shopData['_id']);
                $configObj->setAppTag(null);
                $settingConfiguration = $configObj->getConfig('metafield');
                if (!empty($settingConfiguration) && $settingConfiguration[0]['value']) {
                    $metafieldObj = $this->di->getObjectManager()->get('App\Shopifyhome\Components\Product\Metafield');
                    $params = [
                        'user_id' => $userId,
                        'app_tag' => $settingConfiguration[0]['appTag'],
                        'filter_type' => 'metafield'
                    ];
                    $metafieldObj->initiateMetafieldSync($params);
                }
            } else {
                $this->di->getLog()->logContent('Shop Not found', 'info', $logFile);
            }
        }

        return ['succes' => true, 'message' => 'Process Completed'];
    }

    public function updateShopAndWarehousesDetails($userId, $shopId)
    {
        $logFile = 'shopify/updateShopAndWarehousesDetails/' . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Starting ShopUpdate and LocationsUpdate Process for user :'
            . json_encode($userId), 'info', $logFile);
        $userDetailsObj = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class);
        $shopData = $userDetailsObj->getShop($shopId, $userId);
        if (empty($shopData)) {
            $this->di->getLog()->logContent('Shop not found', 'info', $logFile);
            return ['success' => false, 'message' => 'Shop not found'];
        }

        $remoteShopId = $shopData['remote_shop_id'];
        $appCode = $shopData['apps'][0]['code'] ?? 'default';

        //Fetching shop data
        $getShopResponse = $this->di->getObjectManager()->get(\App\Connector\Components\ApiClient::class)
            ->init(self::MARKETPLACE, true, $appCode)
            ->call('/shop', [], ['shop_id' => $remoteShopId], 'GET');
        if (!empty($getShopResponse['data'])) {
            $getShopResponse['data']['remote_shop_id'] = $remoteShopId;
            $userDetailsObj->addShop($getShopResponse['data'], false, ['remote_shop_id']);
        } else {
            $this->di->getLog()->logContent('Unable to fetch shop data, Error: '
                . json_encode($getShopResponse), 'info', $logFile);
        }

        //Fetching locations
        $getLocationsResponse = $this->di->getObjectManager()->get(\App\Connector\Components\ApiClient::class)
            ->init(self::MARKETPLACE, true, $appCode)
            ->call('shop/location', [], ['shop_id' => $remoteShopId], 'GET');
        if (!empty($getLocationsResponse['data'])) {
            //Removing and Syncing Updated Locations
            $userDetailsCollection = $userDetailsObj->getCollection("user_details");
            $userDetailsCollection->updateOne(
                [
                    'user_id' => $userId,
                    'shops._id' => $shopData['_id'],
                    'shops.marketplace' => self::MARKETPLACE
                ],
                [
                    '$set' => ['shops.$.warehouses' => []]
                ]
            );
            $utilityObj = $this->di->getObjectManager()->get(Utility::class);
            foreach ($getLocationsResponse['data'] as $warehouseData) {
                $warehouse = $utilityObj->locationNodeToExcludeAndFormatData($warehouseData);
                $userDetailsObj->addWarehouse($shopData['_id'], $warehouse, $userId, ['id']);
            }
        } else {
            $this->di->getLog()->logContent('Unable to fetch locations, Error: '
                . json_encode($getLocationsResponse), 'info', $logFile);
        }

        return ['success' => true, 'message' => 'Process completed!!'];
    }

    public function createDestination($sourceShop, $type = 'user')
    {
        $awsClient = require BP . '/app/etc/aws.php';
        $queueName = $this->di->getConfig()->app_code . "_" . "app_delete";
        $sqs = $this->di->getObjectManager()->get('App\Core\Components\Sqs');
        $sqsClient = $sqs->getClient($awsClient['region'], $awsClient['credentials']['key'] ?? null, $awsClient['credentials']['secret'] ?? null);
        $createQueueResponse = $sqs->createQueue($queueName, $sqsClient);

        $data = ['title' => 'User type Event Destination', 'type' =>  $type, 'event_handler' => 'sqs', 'destination_data' => [
            'type' => 'sqs',
            "sqs" => [
                "region" => $awsClient['region'],
                "key" => $awsClient['credentials']['key'] ?? null,
                "secret" => $awsClient['credentials']['secret'] ?? null,
                "queue_base_url" => $createQueueResponse['queue_url'] ? str_replace($queueName, "", $createQueueResponse['queue_url']) : "",
            ]
        ]];

        $remote_shop_id = $sourceShop['remote_shop_id'];
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('cedcommerce', false)
            ->call('event/destination', [], ['shop_id' => $remote_shop_id, 'data' => $data, 'app_code' => "cedcommerce"], 'POST');
        if (isset($remoteResponse['destination_id']) && $remoteResponse['destination_id']) {
            return ['success' => true, 'destination_id' => $remoteResponse['destination_id']];
        }
        return ['success' => false, 'message' => 'Error from remote while creating event destination . please check the log'];
        //            return ['success' => false, 'message' => $remoteResponse];

    }

    public function afterProductUpdate(Event $event, $myComponent, $params)
    {
        if(isset($params['source_marketplace']) && $params['source_marketplace'] !== self::MARKETPLACE ){
            return true;
        }

        $variant_to_simple = $params['variant_to_simple'] ?? [];
        
        if(!empty($variant_to_simple))
        {
            //from this function we only remove entries from refine_products
            $this->di->getLog()->logContent('afterProductImport entered $createWebhookRequest = ' . print_r($this->di->getUser()->id, true), 'info', 'shopify/afterProductUpdate/variant_to_simple_data.log');

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $refineProductCollection = $mongo->getCollection("refine_product");
            $productContainerObj = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');

            $refineInsertData = [];
            foreach ($variant_to_simple as $container_id => $container_data)
            {
                foreach ($container_data as $child_data) {
                    $user_id = $child_data['user_id'];
                    $source_shop_id = $child_data['shop_id'];
                    $sourceMarketplace = $child_data['source_marketplace'] ?? '';
                    if ( !empty($sourceMarketplace) && $sourceMarketplace !== 'shopify' ) {
                        return;
                    }

                    $refineInsertData[] = $productContainerObj->prepareMarketplace($child_data);

                    if(!empty($refineInsertData))
                    {
                        $delete_from_refine = $refineProductCollection->deleteMany(['user_id' => $user_id,'container_id' => (string)$container_id,'source_shop_id' => (string)$source_shop_id]);

                        $this->di->getLog()->logContent('afterProductImport entered  $delete_from_refine = ' . print_r([$delete_from_refine,'user_id' => $user_id,'container_id' => $container_id,'source_shop_id' => $source_shop_id], true), 'info', 'shopify/afterProductUpdateEvent/'.$this->di->getUser()->id.'/data.log');

                        if($delete_from_refine)
                        {
                            $objectManager = $this->di->getObjectManager();
                            $marketplaceObj= $objectManager->get('\App\Connector\Models\Product\Marketplace');
                            $res = $marketplaceObj->marketplaceSaveAndUpdate($refineInsertData);

                            $this->di->getLog()->logContent('afterProductImport entered  $refineInsertData = ' . print_r($refineInsertData, true), 'info', 'shopify/afterProductUpdateEvent/'.$this->di->getUser()->id.'/data.log');

                            $this->di->getLog()->logContent('afterProductImport entered  $res = ' . print_r($res, true), 'info', 'shopify/afterProductUpdateEvent/'.$this->di->getUser()->id.'/data.log');
                        }
                    }
                }
            }

        }

        $new_variant = $params['new_variant'] ?? [];
        if(!empty($new_variant))
        {
            $this->di->getLog()->logContent('afterProductImport entered $createWebhookRequest = ' . print_r($this->di->getUser()->id, true), 'info', 'shopify/afterProductUpdate/new_variant_data.log');

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $mongoCollection = $mongo->getCollection("product_container");

            foreach ($new_variant as $container_data)
            {
                $checkData['data'] = array_values($container_data);
                $checkData['user_id'] = $checkData['data'][0]['user_id'];
                $sourceMarketplace = $checkData['data'][0]['source_marketplace'] ?? '';
                if ( !empty($sourceMarketplace) && $sourceMarketplace !== 'shopify' ) {
                    return;
                }

                $sourceModelObj = $this->di->getObjectManager()->get(SourceModel::class);
                $validateInvAndLoc = $sourceModelObj->validateInvAndLoc($checkData);

                if ($validateInvAndLoc['success'] && !empty($validateInvAndLoc['data']))
                {
                    $bulkUpdate = [];
                    $updated_data = $validateInvAndLoc['data'];
                    foreach ($updated_data as $updated_fields)
                    {
                        $inventory_item_id = $updated_fields['inventory_item_id'] ?? '';
                        $locations = $updated_fields['locations'] ?? [];
                        $source_shop_id = $updated_fields['shop_id'];
                        $user_id = $updated_fields['user_id'];
                        $source_product_id = $updated_fields['source_product_id'];

                        $bulkUpdate[] = [
                            'updateOne' => [
                                ['user_id' => $user_id, 'shop_id' => $source_shop_id,'source_product_id' => $source_product_id],
                                ['$set' => ['inventory_item_id' => $inventory_item_id,'locations' => $locations]]
                            ],
                        ];
                    }

                    $bulkObj = $mongoCollection->BulkWrite($bulkUpdate, ['w' => 1]);
                }
            }
        }
    }

    public function appReInstall(Event $event, $myComponent, $data)
    {
        $logFile = 'shopify/appReInstall/' . $this->di->getUser()->id . '/data.log';
        $message = 'Something Went Wrong';
        $this->di->getLog()->logContent('Starting Process of appReInstall, Data =>' . json_encode($data), 'info', $logFile);
        $marketplace = $this->di->getObjectManager()
            ->get(Shop::class)->getUserMarkeplace();
        if (isset($data['source_shop']['marketplace']) && $data['source_shop']['marketplace'] == $marketplace
            && (!empty($data['source_shop']['targets']) || !empty($data['source_shop']['sources']))) {
            $shop = $data['source_shop'];
            $this->unsetPlanTierKey($this->di->getUser()->id, $shop['_id']);
            unset($shop['plan_tier']);
            $sourceMarketplace = $data['source_shop']['marketplace'];
            $appCode = $data['re_install_app'];
            $createDestinationResponse = $this->createDestination($shop);
            $destinationId = $createDestinationResponse['success'] ? $createDestinationResponse['destination_id'] : false;
            if ($destinationId) {
                $createWebhookRequest = $this->di->getObjectManager()->get("\App\Connector\Models\SourceModel")->routeRegisterWebhooks($shop, $sourceMarketplace, $appCode, $destinationId);
                $this->di->getLog()->logContent('Create Webhook Request Response=> ' . print_r($createWebhookRequest, true), 'info', $logFile);
                return ['success' => true, 'message' => 'Registering Webhooks...'];
            }
            $message = 'Destination Not created';
            $this->di->getLog()->logContent($message, 'info', $logFile);
        } else {
            $message = 'Conditions not matched';
        }

        $this->di->getLog()->logContent('Unable to register webhook ERROR =>' . json_encode($message), 'info', $logFile);
        return ['success' => false, 'message' => $message];
    }

    public function beforeProductImport($shops)
    {
        $date = date('d-m-Y');
        $logFile = "onboarding/shopify/beforeProductImport/{$date}.log";
        try{
            $receivedData = [
                "source" => [
                    "marketplace" => $shops['source_shop']['marketplace'] ?? false,
                    "shopId" => $shops['source_shop']['_id'] ?? false,
                ],
                "target" => [
                    "marketplace" => $shops['target_shop']['marketplace'] ?? false,
                    "shopId" => $shops['target_shop']['_id'] ?? false,
                ],
            ];
            $userId ??= $this->di->getUser()->id;
            $this->di->getLog()->logContent(' ---------------------------------- ', 'info', $logFile);
            $this->di->getLog()->logContent('userId => ' . json_encode($userId), 'info', $logFile);
            $this->di->getLog()->logContent('Processing data => ' . json_encode($receivedData), 'info', $logFile);
            $appTag = $this->di->getAppCode()->getAppTag();//app tag
            $this->di->getLog()->logContent('Appcode found: ' . json_encode($appTag), 'info', $logFile);
            $isRestricted = $this->di->getConfig()->get("plan_restrictions")[$appTag]['product']['product_import']['restricted'] ?? true;
            $this->di->getLog()->logContent('isRestricted => ' . json_encode($isRestricted), 'info', $logFile);
            if ($isRestricted) {
                $parameter = $this->di->getConfig()->get("plan_restrictions")[$appTag]['product']['product_import']['parameter'] ?? null;
                $this->di->getLog()->logContent('parameter => ' . json_encode($parameter), 'info', $logFile);
                if(!is_null($parameter)) {
                    if ($parameter == "import_limit") {
                        $countResponse  = $this->getProductCount($receivedData['source']['marketplace'] ?? "", $receivedData['source']['shopId'] ?? "", $userId);
                        $this->di->getLog()->logContent('countResponse => ' . json_encode($countResponse), 'info', $logFile);
                        $planActiveAndNotFree = true;
                        if(class_exists('App\Plan\Components\Helper')) {
                            $planHelper = $this->di->getObjectManager()->create('App\Plan\Components\Helper');
                            if(method_exists($planHelper, 'isPlanActiveAndNotFree')) {
                                $this->di->getLog()->logContent('isPlanActiveAndNotFree method exist', 'info', $logFile);
                                $planActiveAndNotFree = $planHelper->isPlanActiveAndNotFree($userId);
                            }
                        }
                        $baseMongo = $this->di->getObjectManager()->get('App\Core\Models\BaseMongo');
                        $configCollection = $baseMongo->getCollectionForTable('config');
                        $query = [
                            'user_id' => $userId,
                            'key' => 'restricted_import',
                            'value' => true,
                            'group_code' => 'plan'
                        ];
                        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
                        $configData = $configCollection->findOne($query, $options);
                        $productCount = $countResponse['data']['count']['count'] ?? $countResponse['data']['count'] ?? 0;

                        if(!$planActiveAndNotFree && empty($configData)) {
                            $this->di->getLog()->logContent('planActiveAndNotFree => '.$planActiveAndNotFree, 'info', $logFile);
                                $this->di->getLog()->logContent('restricted => ' . true, 'info', $logFile);
                                    $configData['source'] = $receivedData['source']['marketplace'] ?? "";
                                    $configData['source_shop_id'] = $receivedData['source']['shopId'] ?? "";
                                    $configData['target'] = $receivedData['target']['marketplace'] ?? "";
                                    $configData['target_shop_id'] = $receivedData['target']['shopId'] ?? "";
                                    $configData['group_code'] = "plan";
                                    $configData['key'] = 'restricted_import';
                                    $configData['value'] = true;
                                    $configData['data'] = $shops;
                                    $configData['user_id'] = $userId;
                                    $configData['created_at'] = date('c');
                                    $configData['updated_at'] = date('c');
                                    $this->di->getLog()->logContent('configData => ' . json_encode($configData), 'info', $logFile);
                                    $configCollection->insertOne($configData);

                                return [
                                    'success' => true,
                                    'data' => [
                                        'can_proceed' => false,
                                        'count' => $productCount
                                    ]
                                ];
                        } elseif ($planActiveAndNotFree && !empty($configData)) {
                                $this->di->getLog()->logContent('Not restricted! Deleting entry => ' . json_encode($configData), 'info', $logFile);
                                $configCollection->deleteOne($query);
                                return [
                                    'success' => true,
                                    'data' => [
                                        'can_proceed' => true
                                    ]
                                ];
                        } elseif (!$planActiveAndNotFree && !empty($configData)) {
                            return [
                                'success' => true,
                                'data' => [
                                    'can_proceed' => false,
                                    'count' => $productCount
                                ]
                            ];
                        } else {
                            return [
                                'success' => true,
                                'data' => [
                                    'can_proceed' => true
                                ]
                            ];
                        }
                    }
                }
            }

            return [
                'success' => false,
                'message' => 'No restrictions'
            ];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in before product import=> ' . json_encode($e->getMessage()), 'info', $logFile);
        }

        return [
            'success' => false,
            'message' => 'Issue in processing!'
        ];
    }

    public function getProductCount($marketplace, $shopId, $userId)
    {
        //$logFile = "onboarding/shopify/beforeProductImport/test.log";
        if (!empty($marketplace) && !empty($shopId) && !empty($userId)) {
            $shop = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getShop($shopId, $userId);
            if(!empty($shop)) {
                $productCount = 0;
                $sourceModel = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($shop['marketplace'])->get('source_model'));
                $marketplaceProductCount = method_exists($sourceModel, 'getAllProductCount') ? $sourceModel->getAllProductCount($shop) : false;
                //$this->di->getLog()->logContent('marketplaceProductCount => ' . json_encode($marketplaceProductCount), 'info', $logFile);
                if(!empty($marketplaceProductCount) && isset($marketplaceProductCount['success']) && $marketplaceProductCount['success']) {
                    $productCount = $marketplaceProductCount['data'] ?? 0;
                    $result = [
                        'success' => true,
                        'data' => [
                            'count' => $productCount
                        ]
                    ];
                } else {
                    $result = [
                        'success' => false,
                        'message' => 'Unable to fetch product count!'
                    ];
                }
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Shop not found!'
                ];
            }
        } else {
            $result = [
                'success' => false,
                'message' => 'Required data not found!'
            ];
        }

        return $result;
    }

    public function planServiceUpdate(Event $event, $myComponent, $eventData)
    {
        if (!empty($eventData['data']) && !empty($eventData['shops'])) {
            $supportedServices = $eventData['data'][0] ?? $eventData['data'];
            if ((!isset($supportedServices['services']['additional_services']['supported_features']['metafield_support']))
                || (isset($supportedServices['services']['additional_services']['supported_features']['metafield_support']) &&
                    !$supportedServices['services']['additional_services']['supported_features']['metafield_support'])
            ) {
                    $userId = $eventData['data']['user_id'] ?? $this->di->getUser()->id;
                    $mongo =  $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $configCollection = $mongo->getCollection('config');
                    $marketplace = self::MARKETPLACE;
                    $configCollection->deleteOne([
                        'user_id' => $userId,
                        'key' => 'metafield',
                        'group_code' => 'product',
                        'source' => $marketplace
                    ]);
                    $shopData = [];
                        foreach ($eventData['shops'] as $shop) {
                            if ($shop['marketplace'] == $marketplace) {
                                $shopData = $shop;
                                break;
                            }
                        }

                        if (!empty($shopData)) {
                            $appTag = $eventData['data']['app_tag'] ?? $shopData['apps'][0]['code'] ?? 'default';
                            $notificationData = [
                                'message' => 'Metafield syncing is disabled. Your current plan does not support this feature.',
                                'severity' => 'success',
                                "user_id" => $userId,
                                "marketplace" => $marketplace,
                                "appTag" => $appTag,
                                'process_code' => 'metafield'
                            ];
                            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                                ->addNotification($shopData['_id'], $notificationData);
                            return ['success' => true, 'message' => 'Metafield Syncing Disabled'];
                        }
                        $message = 'Shop not found/Unable to add notification';
            } else {
                $message = 'Metafield supported for this plan';
            }
        } else {
            $message = 'Empty data or shops';
        }

        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    private function unsetPlanTierKey($userId, $shopId) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollection('user_details');
        $userDetailsCollection->updateOne([
            'user_id' => $userId,
            'shops' => ['$elemMatch' => ['_id' => $shopId]]
        ], [
            '$unset' => ['plan_tier' => '']
        ]);
    }
}