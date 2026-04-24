<?php

namespace App\Amazon\Models;

use App\Amazon\Components\ShopErasureHelper;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Amazon\Components\Product\Product;
use App\Amazon\Components\Product\Inventory;
use App\Amazon\Components\Product\Price;
use App\Amazon\Components\Product\BulkInventory;
use App\Amazon\Components\Product\Image;
use Exception;
use App\Amazon\Components\Order\Order;
use App\Amazon\Components\Feed\Feed;
use App\Amazon\Components\Report\Report;
use App\Amazon\Components\Cron\Route\Requestcontrol;
use App\Amazon\Components\Product\Lookup;
use App\Amazon\Components\ValueMapping;
use App\Amazon\Components\Product\saveProductValidation;
use App\Amazon\Components\Product\Helper;
use App\Amazon\Components\Common\Helper as CommonHelper;
use App\Amazon\Components\Product\UnifiedFeed;

use App\Core\Models\SourceModel as BaseSourceModel;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use App\Connector\Models\QueuedTasks;
use App\Amazon\Components\Listings\CategoryAttributes;
use MongoDB\BSON\UTCDateTime;

class SourceModel extends BaseSourceModel
{

    protected $table = '';

    public const PRODUCT_UPLOAD = 'product_upload';

    public const PRODUCT_UPLOAD_NEW = 'product_upload_new';

    public const PRODUCT_UPDATE = 'product_update';

    public const INVENTORY_SYNC = 'inventory_sync';

    public const IMAGE_SYNC = 'image_sync';

    public const PRICE_SYNC = 'price_sync';

    public const DELETE_SYNC = 'product_delete';

    public const GROUP_SYNC = 'groupAction';

    public const PRODUCT_UPLOAD_LOCAL_DELIVERY = 'Local_Delivery_Product_Upload';

    public const INVENTORY_SYNC_LOCAL_DELIVERY = 'Local_Delivery_Inventory_Sync';

    public const PRICE_SYNC_LOCAL_DELIVERY = 'Local_Delivery_Price_Sync';

    protected $default_value = [
        "inventory" => [
            "inventory_settings" => false,
            // "settings_selected" => [
            //     "fixed_inventory" => false,
            //     "threshold_inventory" => false,
            //     "delete_out_of_stock" => false,
            //     "customize_inventory" => false,
            //     "inventory_fulfillment_latency" => true,
            //     "warehouses_settings" => false,
            // ],
            // "fixed_inventory" => "",
            // "threshold_inventory" => "",
            // "customize_inventory" => [
            //     "attribute" => "default",
            //     "value" => "",
            // ],
            "warehouses_settings" => [],
            "inventory_fulfillment_latency" => "2",
        ],
        "price" => [
            "settings_enabled" => false,
            "settings_selected" => [
                "sale_price" => false,
                "business_price" => false,
                "minimum_price" => false,
            ],
            "standard_price" => [
                "attribute" => "default",
                "value" => "",
            ],
            "sale_price" => [
                "attribute" => "default",
                "value" => "",
                "start_date" => "",
                "end_date" => "",
            ],
            "business_price" => [
                "attribute" => "default",
                "value" => "",
            ],
            "minimum_price" => [
                "attribute" => "default",
                "value" => "",
            ],
        ],
        "product" => [
            "settings_enabled" => false,
            "enable_syncing" => false,
            "selected_attributes" => [],
            "sync_status_with" => ['sku']
        ]

    ];

    /*public function productUpload(&$data)
    {
    if (isset($data)) {
    $helper = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product');
    $productReponse = $helper->uploadProduct($data);
    return $productReponse;
    } else {
    return ['success' => false, 'message' => 'Data not found to be upload on Amazon'];
    }
    }*/

    public function productUpload(&$data)
    {
        if (isset($data)) {
            $helper = $this->di->getObjectManager()->get('\App\Amazon\Components\Pr
            oduct\Upload')->init($data);
            $productReponse = $helper->execute($data);
            return $productReponse;
        }
        return ['success' => false, 'message' => 'Data not found to be upload on Amazon'];
    }

    public function validateBeforeProductActivities($data)
    {
        if (isset($data['target']['shopId']) && !empty($data['target']['shopId']) && isset($data['source']['shopId']) && !empty($data['source']['shopId'])) {
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = (string)$data['target']['shopId'];
            if ((isset($data['filter']) && (isset($data['process_type']) && $data['process_type'] !== 'automatic' || !isset($data['process_type']))) || (isset($data['profile_id']) && isset($data['process_type']) && $data['process_type'] !== 'automatic')) {
                // $processCode = ucfirst(implode(" ", explode("_", (string) $data['operationType'])) . ' initiated');
                $processCode = $data['operationType'];
                $ifQueuedTaskExists = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')
                    ->checkQueuedTaskwithProcessTag($processCode, $targetShopId);
                if ($ifQueuedTaskExists) {
                    $operationTypeMsg = ucfirst(implode(" ", explode("_", (string) $data['operationType'])));
                    return ['success' => false, 'message' => $operationTypeMsg . ' for Amazon under progress. For more updates, please check your Notification window.'];
                }
            }

            $userShops = $this->di->getUser()->shops ?? [];
            if (!empty($userShops)) {
                $targetShop = array_filter($userShops, function (array $shop) use ($targetShopId) {
                    if ($shop['_id'] == $targetShopId) {
                        return $shop;
                    }
                });
                if (!empty($targetShop)) {
                    foreach ($targetShop as $shop) {
                        $targetShop = $shop;
                        break;
                    }
                }
            } else {
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $targetShop = $user_details->getShop($targetShopId, $userId);
            }

            if(!empty($targetShop['sources'])) {
                foreach($targetShop['sources'] as $source) {
                    if(isset($source['status']) && $source['status'] == 'closed') {
                        return ['success' => false, "Action cannot be performed because the store is closed."];
                    }
                }
            }

            $activeAccounts = false;
            if (!empty($targetShop['warehouses'])) {
                foreach ($targetShop['warehouses'] as $warehouse) {
                    if ($warehouse['status'] == "active") {
                        $activeAccounts = true;
                        break;
                    }
                }
            }

            if ($activeAccounts) {
                $sourceShopId = $data['source']['shopId'];
                $targetMarketplace = $data['target']['marketplace'];
                $sourceMarketplace = $data['source']['marketplace'];
                $currencyCheck = false;
                if($data['operationType']=="product_upload" || $data['operationType'] == 'price_sync' || $data['operationType'] == 'image_sync' || $data['operationType'] == 'inventory_sync') {
                    $data['usePrdOpt'] = true;
                    $data['projectData'] = ['marketplace'=>0];
                }
                if ($data['operationType'] == 'inventory_sync' || $data['operationType'] == 'product_delete' || $data['operationType'] == 'image_sync') {
                    $currencyCheck = 1;
                } else {
                    $uniqueKey =(string)$userId . 'target_id' . $targetShopId . 'source_id' . $sourceShopId;
                    $data['uniqueKeyForCurrency'] = $uniqueKey;
                    $connector = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
                    $currencyCheck = $connector->checkCurrencyWithCache($userId, $targetMarketplace, $sourceMarketplace, $targetShopId, $sourceShopId, $uniqueKey);
                    $configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
                    $configObj->reset();
                    $configObj->setUserId($userId);
                    $configObj->setTarget($targetMarketplace);
                    $configObj->setTargetShopId($targetShopId);
                    $configObj->setGroupCode('currency');
                    $sourceCurrency = $configObj->getConfig('source_currency');
                    $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);
                    $amazonCurrency = $configObj->getConfig('target_currency');
                    $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);
                    if (!empty($sourceCurrency) && !empty($amazonCurrency)) {
                        if ($sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
                            $currencyCheck = true;
                        } else {
                            $amazonCurrencyValue = $configObj->getConfig('target_value');
                            $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
                            if (isset($amazonCurrencyValue[0]['value'])) {
                                $currencyCheck = true;
                            }
                        }
                    }
                }

                if ($currencyCheck) {
                    return $this->validateProcess($data, false);
                }
                return ['success' => false, 'message' => 'Currency of' . $sourceMarketplace . 'and Amazon account are not same. Please fill currency settings from Global Price Adjustment in settings page.'];
            }
            return ['success' => false, 'message' => 'Target shop is not active.'];
        }
        return ['success' => false, 'message' => 'Target shop or source shop is empty.'];
    }

    public function validateProcess($data, $admin = false)
    {
        $sourceShopId = $data['source']['shopId'];
        if (isset($data['source_product_ids'])) {
            $productProfile = $this->di->getObjectManager()->get('App\Connector\Components\Profile\GetProductProfileMerge');
            $data['user_id'] = (string) $this->di->getUser()->id;
            $data['limit'] = 1000;
            if (!isset($data['activePage'])) {
                $data['activePage'] = 1;
            }
            $sourceIds = $productProfile->getAllSourceIds($data, true);

            // $products = $productProfile->getproductsByProductIds($data, true);
            if (count($sourceIds)<= 50) {
                $products = $productProfile->getproductsByProductIds($data,true);
                return $this->directInitiation($data, $products);
            }
            if ($admin) {
                return ['success' => true, 'message' => 'Validation Completed'];
            } else {
                return $this->messageInitiation($data);
            }
        }
        return ['success' => true, 'message' => 'Validation Completed'];
    }
    /**
     * Get the filter attributes from the raw body.
     *
     * @param array $rawBody
     * @return array
     */
    public function getFilterAttributes($rawBody = [])
    {
        if ($rawBody['target']['marketplace']) {
            $marketplace = $rawBody['target']['marketplace'];
            $attributes = [];
            switch ($marketplace) {
                case 'amazon':
                    $amazonProductTypes=$this->getAllAmazonProductTypes($rawBody);


                    $attributes = [
                        [
                            'code' => 'amazonProductType',
                            'required' => 1,
                            'title' => 'Recommended Amazon Product Type',
                            'system' => 1,
                            'visible' => 0,
                            'options' =>$amazonProductTypes
                        ],
                    ];
                    break;
            }

            return ['data' => $attributes];
        }
    }
    public function getAllAmazonProductTypes($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('product_container');
        $aggregate = [];
        // $aggregate[] = [ '$match' => [ "user_id" => "6267f4954dbe15233438a048"]];
        $aggregate[] = ['$match' => ["user_id" => (string)$this->di->getUser()->id, "shop_id" => $data['target']['shopId']]];
        $aggregate[] = ['$group' => [
            '_id' => '$amazonProductType',
            'title' => ['$addToSet' => '$amazonProductType']
        ]];
        $vendorAttr = $collection->aggregate($aggregate)->toArray();
        $options = [];
        foreach ($vendorAttr as $value) {
            if ($value['_id'] && !empty($value['_id'] && count($value['title']) > 0 && $value['title'][0] && !empty($value['title'][0]))) {
                $options[] = ['label' => $value['title'][0], 'value' => $value['_id']];
            }
        }

        return $options;
    }

    public function messageInitiation($data)
    {
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $targetShopId = $data['target']['shopId'];
        $sourceShopId = $data['source']['shopId'];
        $targetMarketplace = $data['target']['marketplace'];
        $sourceMarketplace = $data['source']['marketplace'];
        $operationType = $data['operationType'];
        $tag = $this->getTag($operationType);
        $sourceProductIds = [];
        $targetData = [];
        $source_product_ids = $data['source_product_ids'];
        $processCode = $data['operationType'];
        $ifQueuedTaskExists = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')
            ->checkQueuedTaskwithProcessTag($processCode, $targetShopId);
        if ($ifQueuedTaskExists) {
            $operationTypeMsg = ucfirst(implode(" ", explode("_", (string) $data['operationType'])));
            return ['success' => false, 'message' => $operationTypeMsg . ' for Amazon under progress. For more updates, please check your Recent Activties on Overview Section.'];
        }
        $params = [];
        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
        $specifics = [
            'source_shop_id' => $sourceShopId,
            'target_shop_id' => $targetShopId,
            'user_id' => $userId,
        ];

        $params = [
            'user_id' => $userId,
            'shop_id' => $targetShopId,
            '$or' => [
                ['container_id' => ['$in' => $source_product_ids]],
                ['source_product_id' => ['$in' => $source_product_ids]],
            ],
            'target_marketplace' => $targetMarketplace,
        ];

        $allEditedProductsData = $helper->getProductByQuery($params);
        $allEditedProductsData = json_decode(json_encode($allEditedProductsData), true);

        $idsForNonEditedProd = [];
        if (in_array($operationType, [self::PRODUCT_UPLOAD])) {
            if (count($source_product_ids) > count($allEditedProductsData)) {
                $editedProductSourceIds = array_column($allEditedProductsData, 'container_id');
                $idsForNonEditedProd = array_values(array_diff($source_product_ids, $editedProductSourceIds));
            }
        }

        $preparedAllEditedProductsData = [];
        $variationProductParents = [];
        $allParentIds = [];
        foreach ($allEditedProductsData as $editedProduct) {
            foreach ($source_product_ids as $sourceProductId) {
                if ($editedProduct['container_id'] == $sourceProductId || $editedProduct['source_product_id'] == $sourceProductId) {
                    $preparedAllEditedProductsData[$sourceProductId][] = $editedProduct;
                    $allParentIds[] = $editedProduct['container_id'];
                    if ($editedProduct['container_id'] == $editedProduct['source_product_id']) {
                        $variationProductParents[] = $editedProduct['container_id'];
                    }
                }
            }
        }

        foreach ($preparedAllEditedProductsData as $source_product_id => $editedProductData) {
            $specifics['source_product_id'] = $source_product_id;
            // $productData = $helper->getSingleProduct($specifics);
            // $product = json_decode(json_encode($productData), true);
            // $marketplace = false;
            // $params = [
            //     'user_id' => $userId,
            //     'shop_id' => $targetShopId,
            //     'container_id' => $source_product_id,
            //     'target_marketplace' => $targetMarketplace,
            // ];
            // $allEditedProductsData = $helper->getProductByQuery($params);
            $ids = $this->getAllSourceIds($editedProductData, $targetShopId, $sourceShopId, $tag, $targetMarketplace);
            $sourceProductIds = array_merge($sourceProductIds, $ids);


            // if (isset($product['marketplace'])) {
            //     $marketplace = $product["marketplace"];
            //     $ids = $this->getAllSourceIds($marketplace, $targetShopId, $sourceShopId, $tag, $targetMarketplace);
            //     $sourceProductIds = array_merge($sourceProductIds, $ids);
            // }
        }

        if (!empty($idsForNonEditedProd)) {
            $sourceProductIds = [...$sourceProductIds, ...$idsForNonEditedProd];
        }

        if (empty($sourceProductIds)) {
            return ['success' => false, 'message' => 'Process already in progress.'];
        }
        $sourceProductIds = [...$sourceProductIds, ...$allParentIds];

        $sourceProductIds = array_unique($sourceProductIds);
        $IdsneedwithTag = [];

        if (in_array($operationType, [self::INVENTORY_SYNC, self::PRICE_SYNC])) {
            $IdsneedwithTag = array_values(array_diff($sourceProductIds, $allParentIds));
        } else {
            $IdsneedwithTag = $sourceProductIds;
        }

        $this->di->getObjectManager()->get(Product::class)->addTags($IdsneedwithTag, $tag, $targetShopId, $sourceShopId, $userId, [], false, false);
        $source_product_ids = [
            'updated_source_product_ids' => $sourceProductIds
        ];
        return ['success' => true, 'message' => 'Validation Completed', 'data' => $source_product_ids];
    }

    public function directInitiation($data, $products)
    {
        $productProfile = $this->di->getObjectManager()->get('App\Connector\Components\Profile\GetProductProfileMerge');
        $operationType = $data['operationType'];
        $uniqueKey = $products['unique_key_for_sqs'] ?? [];
        if (!empty($uniqueKey)) {
            $dataInCache = [
                'params' => [
                    'unique_key_for_sqs' => $uniqueKey
                ]
            ];
            $productProfile->clearInfoFromCache(['data' => $dataInCache]);
        }

        $message = $productProfile->getMessage($data);
        $dataToSent = json_decode(json_encode($products), true);
        $dataToSent['data']['operationType'] = $operationType;
        $dataToSent['data']['params'] = $data;
        $response = $this->startSync($dataToSent);
        if (!empty($response['success']) && !empty($response['count'])) {
            return [
                'success' => $response['success'],
                'message' => !empty($response['message'])?$response['message']:$message,
                'process_details' => $response['count'],
                'data' => ['return_product_direct' => true]
            ];
        }
        return ['success' => true, 'message' => $message, 'data' => ['return_product_direct' => true]];
    }

    public function getAllSourceIds($marketplaces, $targetShopId, $sourceShopId = false, $tag = false, $targetMarketplace = false, $sourceData = false)
    {
        $sourceProductIds = [];
        $flag = 1;
        foreach ($marketplaces as $marketplace) {
            if (isset($marketplace['shop_id']) && $marketplace['shop_id'] == $targetShopId && isset($marketplace['source_product_id'])) {
                $flag = 0;
                if ($tag) {
                    $validateTag = $this->checkTag($marketplace, $targetMarketplace, $targetShopId, $tag);
                    if (!$validateTag) {
                        array_push($sourceProductIds, $marketplace['source_product_id']);
                    }
                } elseif ($sourceData) {
                    array_push($sourceProductIds, $marketplace['source_product_id']);
                }
            }
        }

        if ($flag) {
            $sourceProductIds = $this->getAllSourceIds($marketplaces, $sourceShopId, false, false, false, true);
        }

        return $sourceProductIds;
    }

    public function checkTag($data, $target, $shopId, $tag)
    {
        // return false;
        if (isset($data['process_tags'])) {
            if (isset($data['process_tags'][$tag])) {
                return true;
            }
        }

        return false;
    }

    public function getTag($operationType)
    {
        $tag = false;
        $tag = match ($operationType) {
            self::PRODUCT_UPLOAD => \App\Amazon\Components\Common\Helper::PROCESS_TAG_UPLOAD_PRODUCT,
            self::INVENTORY_SYNC => \App\Amazon\Components\Common\Helper::PROCESS_TAG_INVENTORY_SYNC,
            self::IMAGE_SYNC => \App\Amazon\Components\Common\Helper::PROCESS_TAG_IMAGE_SYNC,
            self::PRICE_SYNC => \App\Amazon\Components\Common\Helper::PROCESS_TAG_PRICE_SYNC,
            self::DELETE_SYNC => \App\Amazon\Components\Common\Helper::PROCESS_TAG_DELETE_PRODUCT,
            self::PRODUCT_UPDATE => \App\Amazon\Components\Common\Helper::PROCESS_TAG_SYNC_PRODUCT,
            self::GROUP_SYNC => \App\Amazon\Components\Common\Helper::PROCESS_TAG_GROUP_SYNC,
            default => $tag,
        };
        return $tag;
    }

    public function fetchOrder($data)
    {
        if (isset($data)) {
            // print_r($data);die("first1");
            $helper = $this->di->getObjectManager()->get(\App\Amazon\Components\Order\Helper::class);
            $orderResponse = $helper->fetchOrdersByShopId($data);
            return $orderResponse;
        }
        return ['success' => false, 'message' => 'Data not found to order import from Amazon'];
    }

    public function getRemoteDatabyShopId($remoteShopId = '', $details = [])
    {
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('amazon', true, 'amazon')
            ->call('connected-accounts', [], ['remote_shop_id' => [$remoteShopId]], 'GET');

        $this->di->getLog()->logContent('user_id: ' . $this->di->getUser()->id, 'info', 'TokenData.log');
        $this->di->getLog()->logContent('Response : ' . json_encode($remoteResponse), 'info', 'TokenData.log');
        $returndata = [];
        if ($remoteResponse['success']) {
            $remoteShops = $remoteResponse['data'];

            foreach ($remoteShops as $key => $remoteshop) {
                if ((string) $remoteshop['_id'] == (string) $remoteShopId) {
                    foreach ($details as $key) {
                        foreach ($remoteshop['apps'] as $value) {
                            $returndata[$key] = $value[$key];
                        }
                    }
                }
            }
        }

        return $returndata;
    }

    public function prepareConnectedShopData($shop, $data = [])
    {
        $responseData = [];
        if (isset($data['checkMwsToken']) && $data['checkMwsToken']) {
            $tokenGenerated = false;

            if (isset($shop['warehouses'][0]['token_generated'])) {
                if ($shop['warehouses'][0]['token_generated']) {
                    $tokenGenerated = true;
                } else {
                    $tokenGenerated = false;
                }
            } else {

                $remoteShopWarehouseData = $this->getRemoteDatabyShopId($shop['remote_shop_id'], ['mws_auth_token']);
                if ($remoteShopWarehouseData && isset($remoteShopWarehouseData['mws_auth_token']) && $remoteShopWarehouseData['mws_auth_token'] != false && $remoteShopWarehouseData['mws_auth_token'] != 'false') {
                    $tokenGenerated = true;
                }
            }

            if ($tokenGenerated) {
                $responseData = ["marketplace" => $shop['marketplace'], 'id' => $shop['_id'], 'warehouses' => $shop['warehouses'], 'sellerName' => $shop['sellerName']];
            } else {
                $region = $shop['warehouses'][0]['region'];
                $responseData = [
                    "marketplace" => $shop['marketplace'],
                    'id' => $shop['_id'],
                    'warehouses' => $shop['warehouses'],
                    'error' => 'Mws auth Token not generated successfully.Please check if you have a primary sellercentral account.If yes then click on the link below, and copy the generated token in the given textbox.',
                    'link' => \App\Amazon\Components\Common\Helper::MANUAL_AUTHENTICATION_URLS[$region],
                ];
            }
        } else {
            $responseData = ["marketplace" => $shop['marketplace'], 'source' => $shop['sources'], 'id' => $shop['_id'], 'warehouses' => $shop['warehouses'], 'sellerName' => $shop['sellerName'], 'shop_details' => ['marketplace' => $shop['marketplace'], 'sellerName' => $shop['sellerName'], 'sources' => $shop['sources']]];
        }

        return $responseData;
    }

    public function checkRegisterForOrderSync($data)
    {
        $userId = $data['data']['params']['user_id'] ?? $this->di->getUser()->id;
        $targetShopId = (string) $data['data']['params']['target']['shopId'];
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $targetShop = $userDetails->getShop($targetShopId, $userId);
        return $targetShop['register_for_order_sync'] ?? false;
    }

    public function startSync($sqsData)
    {
        // Get the configuration object
        if ($this->checkRegisterForOrderSync($sqsData)) {
            $config = $this->di->getConfig();
            $operationType = $sqsData['data']['params']['operationType'];

            if (isset($sqsData['data']['params']['localDelivery']) && $sqsData['data']['params']['localDelivery'] == true && $sqsData['data']['params']['operationType'] == self::PRODUCT_UPLOAD) {
                $sqsData['data']['params']['operationType'] = 'Local_Delivery_Product_Upload';
                $operationType = $sqsData['data']['params']['operationType'];
            }

            if (isset($sqsData['data']['params']['localDelivery']) && $sqsData['data']['params']['localDelivery'] == true && $sqsData['data']['params']['operationType'] == self::INVENTORY_SYNC) {
                $sqsData['data']['params']['operationType'] = 'Local_Delivery_Inventory_Sync';
                $operationType = $sqsData['data']['params']['operationType'];
            }

            if (isset($sqsData['data']['params']['localDelivery']) && $sqsData['data']['params']['localDelivery'] == true && $sqsData['data']['params']['operationType'] == self::PRICE_SYNC) {
                $sqsData['data']['params']['operationType'] = 'Local_Delivery_Price_Sync';
                $operationType = $sqsData['data']['params']['operationType'];
            }

            switch ($operationType) {
                case self::INVENTORY_SYNC_LOCAL_DELIVERY:
                    $product = $this->getDi()->getObjectManager()->get(Inventory::class);
                    $response = $product->init()->upload($sqsData, true);
                    break;
                case self::PRICE_SYNC_LOCAL_DELIVERY:
                    $product = $this->getDi()->getObjectManager()->get(Price::class);
                    $response = $product->init()->upload($sqsData, true);
                    break;

                case self::PRODUCT_UPLOAD_LOCAL_DELIVERY:
                    $product = $this->getDi()->getObjectManager()->get(Product::class);
                    $response = $product->init()->upload($sqsData, true);
                    break;
                case self::PRODUCT_UPLOAD:
                    $product = $this->getDi()->getObjectManager()->get(Product::class);
                    // $response = $product->init()->upload($sqsData);// old function
                    $response = $product->init()->uploadProduct($sqsData); //new function

                    // $response = $product->init()->uploadNew($sqsData); //new function
                    break;

                case self::INVENTORY_SYNC:
                    // $inventory = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Inventory');
                    // $response = $inventory->init()->upload($sqsData);
                    $inventory = $this->di->getObjectManager()->get(BulkInventory::class);
                    $response = $inventory->init($sqsData)->upload($sqsData);
                    break;

                case self::IMAGE_SYNC:
                    $imageComponent = $this->getDi()->getObjectManager()->get(Image::class);
                    $response = $imageComponent->init()->upload($sqsData);
                    break;

                case self::PRICE_SYNC:
                    # code...
                    $priceComponent = $this->getDi()->getObjectManager()->get(Price::class);
                    $response = $priceComponent->init()->upload($sqsData);
                    break;

                case self::DELETE_SYNC:
                    $product = $this->getDi()->getObjectManager()->get(Product::class);
                    $response = $product->init()->delete($sqsData);
                    break;

                case self::PRODUCT_UPDATE:
                    # code...
                    $product = $this->getDi()->getObjectManager()->get(Product::class);
                    // $response = $product->init()->syncProduct($sqsData, "PartialUpdate");

                    // $response = $product->init()->partialUpdate($sqsData); // old method
                    // $response = $product->init()->uploadNew($sqsData, "PartialUpdate"); //new method
                    $response = $product->init()->uploadProduct($sqsData, "PartialUpdate"); //new method

                    break;
              case  self::GROUP_SYNC:
                    $UnifiedFeed = $this->getDi()->getObjectManager()->get(UnifiedFeed::class);
                    $response = $UnifiedFeed->init($sqsData)->groupSync($sqsData, true);
                    return $response;

                default:
                    $response = $this->getDi()->getObjectManager()->get(Product::class)->setResponse(\App\Amazon\Components\Common\Helper::RESPONSE_TERMINATE, 'Operation type : ' . $operationType . ' is invalid.', 0, 0, 0);
                    break;
            }
        } else {
            $response = ['success' => false, 'message' => 'Plan is inactive'];
        }

        return $response;
    }

    public function addWarehouseToShop($data, &$shopData)
    {

        if (!empty($data['rawResponse']['state'])) {
            $state = json_decode((string) $data['rawResponse']['state'], true);
        }

        $appcodeFromrawResponse = false;
        $redirect = [
            'success' => false,
            'message' => 'warehouse not added successfully',
        ];
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shop = $userDetails->getDataByUserID($this->di->getUser()->id, $state['source']);
        $app_code = $shop['apps'][0]['code'];
        try {
            if ($data['data']['data']['shop_id'] && !empty($data['data']['data']['remote_shop_ids'])) {
                $remoteShopIds = $data['data']['data']['remote_shop_ids'];
                if (isset($data['rawResponse']['app_code'])) {
                    $appcodeFromrawResponse = $data['rawResponse']['app_code'];
                }

                foreach ($remoteShopIds as $shopId) {
                    // $details = ['region', 'marketplace_id', 'seller_id', 'mws_auth_token'];
                    $details = ['region', 'marketplace_id', 'seller_id'];
                    $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init($shop['marketplace'], true, $app_code)
                        ->call('connected-accounts', [], ['remote_shop_id' => [$shopId]], 'GET');

                    $remoteShopWarehouseData = [];
                    if ($remoteResponse['success']) {
                        $remoteShops = $remoteResponse['data'];
                        foreach ($remoteShops as $key => $remoteshop) {
                            if ((string) $remoteshop['_id'] == (string) $shopId) {
                                foreach ($remoteshop['apps'] as $apps) {
                                    if ($apps['app_code'] == $appcodeFromrawResponse) {
                                        foreach ($details as $key) {
                                            $remoteShopWarehouseData[$key] = $apps[$key];
                                        }
                                    }
                                }
                            }
                        }
                    }

                    $remoteShopWarehouseData['status'] = 'active';
                    //                    if (isset($remoteShopWarehouseData['mws_auth_token']) && $remoteShopWarehouseData['mws_auth_token']) {
                    //                        $remoteShopWarehouseData['token_generated'] = true;
                    //                    } else {
                    //                        $remoteShopWarehouseData['token_generated'] = false;
                    //                        $remoteShopWarehouseData['status'] = 'inactive';
                    //                    }
                    unset($remoteShopWarehouseData['mws_auth_token']);

                    /** save seller name */
                    $common_helper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                    $seller_name = $common_helper->getAmazonSellerNameById(['amazon_seller_id' => $remoteShopWarehouseData['seller_id']]);
                    if ($seller_name['success']) {
                        $remoteShopWarehouseData['seller_name'] = $seller_name['data'];
                    }

                    /** save currency */
                    $amazonCurrency = \App\Amazon\Components\Common\Helper::MARKETPLACE_CURRENCY[$remoteShopWarehouseData['marketplace_id']];
                    $shopData['currency'] = $amazonCurrency;

                    $shopData['warehouses'] = [];
                    $shopData['warehouses'][] = $remoteShopWarehouseData;
                    $redirect = [
                        'success' => true,
                        'message' => 'Warehouse successfully added in shop',
                    ];
                }
            }
        } catch (Exception $e) {
            $redirect['message'] = $e->getMessage();
        }

        return $redirect;
    }

    public function getInstallationForm($req = [])
    {
        $request = $this->di->getRequest()->get();
        $region = $request['region'];
        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $userDetails = $userDetails->getDataByUserID((string) $this->di->getUser()->id, 'amazon');

        $connected = isset($userDetails['_id']) ? true : false;
        $apiConnector = $this->di->getConfig()->get('apiconnector');
        if (isset($request['state']) && isset($request['marketplace_id'])) {
            $state = $request['state'];
            $marketplace_id = $request['marketplace_id'];
        }

        return [
            'post_type' => 'redirect',
            'connected' => $connected,
            'action' => $apiConnector->base_url . 'apiconnect/request/auth?sAppId=' . $apiConnector->amazon->amazon->sub_app_id . '&sandbox=1&version=new&marketplace_id=' . $marketplace_id . '&state=' . $state . '&region=' . $region,
        ];
    }

    public function initiateOrderSync($userId = false): void
    {
        $logFile = 'amazon/cron/' . date('d-m-Y') . '.log';
        try {
            if ($userId !== false) {
                $data['user_id'] = $userId;
                $response = $this->di->getObjectManager()->get(Order::class)->init($data)->fetchOrders($data);
                $this->di->getLog()->logContent('Initiate order sync response for userId ' . $userId . ':  ' . json_encode($response), 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Initiate order sync exception for userId ' . $userId . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);
        }
    }

    public function initiateShipmentSync($userId = false): void
    {
        $logFile = 'amazon/cron/' . date('d-m-Y') . '.log';
        try {
            if ($userId !== false) {
                $data['user_id'] = $userId;
                $response = $this->di->getObjectManager()->get(Order::class)->init($data)->shipOrders($data);

                $this->di->getLog()->logContent('Initiate shipment sync response for userId ' . $userId . ':  ' . json_encode($response), 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Initiate shipment sync exception for userId ' . $userId . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);
        }
    }

    public function initiateFeedSync($userId = false): void
    {
        $logFile = 'amazon/cron/' . date('d-m-Y') . '.log';
        try {
            if ($userId !== false) {
                $data['user_id'] = $userId;
                $response = $this->di->getObjectManager()->get(Feed::class)->init($data)->sync($data);
                $this->di->getLog()->logContent('Initiate feed sync response for userId ' . $userId . ':  ' . json_encode($response), 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Initiate Feed sync exception for userId ' . $userId . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);
        }
    }

    public function initiateInventorySync($data): void
    {
        $logFile = 'amazon/cron/' . date('d-m-Y') . '.log';
        //        $this->di->getLog()->logContent('Initiate qty sync userId product array :'.json_encode($data) , 'info', 'crontest.log');
        try {
            if ($data['user_id'] !== false) {
                if (!isset($data['limit'])) {
                    $limit = 500;
                } else {
                    $limit = $data['limit'];
                }

                if (!isset($data['page'])) {
                    $skip = 0;
                } else {
                    $skip = $data['page'] * $limit;
                }

                $userId = $data['user_id'];
                $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $productContainer = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
                if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
                    $sourceProductIds = $data['source_product_ids'];
                } else {
                    $sourceProductIds = $productContainer->find(['user_id' => $userId], ['projection' => ['source_product_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array'], 'skip' => $skip, 'limit' => $limit])->toArray();
                    if (!empty($sourceProductIds)) {
                        $sourceProductIds = array_column($sourceProductIds, 'source_product_id');
                    }
                }

                //        $this->di->getLog()->logContent('Initiate qty sync for userId : '.$userId.' product array :'.PHP_EOL.json_encode($sourceProductIds) , 'info', 'inventory_sync.log');

                if (!empty($sourceProductIds)) {
                    $sourceProductChunk = array_chunk($sourceProductIds, 500);
                    foreach ($sourceProductChunk as $sourceProductId) {
                        $products['source_product_ids'] = $sourceProductId;
                        $response = $this->di->getObjectManager()->get(Inventory::class)->init($data)->updateNew($products);
                        //    $this->di->getLog()->logContent('Initiate inventory sync response for userId ' . $userId . ':  ' . json_encode($response), 'info', 'inventory_sync.log');
                    }

                    // Push data in sqs
                    if (isset($data['queue_name'])) {
                        $sqs_data = $data;
                        if (!isset($sqs_data['limit'])) {
                            $sqs_data['limit'] = $limit;
                        }

                        if (!isset($sqs_data['page'])) {
                            $sqs_data['page'] = 1;
                        } else {
                            $sqs_data['page'] = $sqs_data['page'] + 1;
                        }

                        if (count($sourceProductIds) >= $sqs_data['limit']) {
                            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
                            $helper->createQueue($data['queue_name'], $sqs_data);
                        }
                    }

                    // Push data in sqs

                } else {
                    $this->di->getLog()->logContent('Initiate quantity sync userId :' . $userId . ' no product found: ', 'info', $logFile);
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Initiate Inventory sync exception for userId ' . $userId . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);
        }
    }

    public function deleteAccount($data)
    {
        return true;
    }

    public function initiatePriceSync($data): void
    {
        $logFile = 'amazon/cron/' . date('d-m-Y') . '.log';
        try {
            if ($data['user_id'] !== false) {
                $userId = $data['user_id'];

                if (!isset($data['limit'])) {
                    $limit = 500;
                } else {
                    $limit = $data['limit'];
                }

                if (!isset($data['page'])) {
                    $skip = 0;
                } else {
                    $skip = $data['page'] * $limit;
                }

                $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $productContainer = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

                if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
                    $sourceProductIds = $data['source_product_ids'];
                } else {
                    $sourceProductIds = $productContainer->find(['user_id' => $data['user_id']], ['projection' => ['source_product_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array'], 'skip' => $skip, 'limit' => $limit])->toArray();
                    if (!empty($sourceProductIds)) {
                        $sourceProductIds = array_column($sourceProductIds, 'source_product_id');
                    }
                }

                $this->di->getLog()->logContent('Initiate price sync for userId : ' . $userId . ' product array :' . PHP_EOL . json_encode($sourceProductIds), 'info', $logFile);

                if (!empty($sourceProductIds)) {
                    $sourceProductChunk = array_chunk($sourceProductIds, 500);
                    foreach ($sourceProductChunk as $sourceProductId) {
                        $products['source_product_ids'] = $sourceProductId;
                        $response = $this->di->getObjectManager()->get(Price::class)->init($data)->updateNew($products);
                        $this->di->getLog()->logContent('Initiate price sync response for userId ' . $userId . ':  ' . json_encode($response), 'info', $logFile);
                    }

                    // Push data in sqs
                    if (isset($data['queue_name'])) {
                        $sqs_data = $data;
                        if (!isset($sqs_data['limit'])) {
                            $sqs_data['limit'] = $limit;
                        }

                        if (!isset($sqs_data['page'])) {
                            $sqs_data['page'] = 1;
                        } else {
                            $sqs_data['page'] = $sqs_data['page'] + 1;
                        }

                        if (count($sourceProductIds) >= $sqs_data['limit']) {
                            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
                            $helper->createQueue($data['queue_name'], $sqs_data);
                        }
                    }

                    // Push data in sqs
                } else {
                    $this->di->getLog()->logContent('Initiate price sync userId :' . $userId . ' no product found: ', 'info', $logFile);
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Initiate Price sync exception for userId ' . $userId . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);
        }
    }

    public function initiateImageSync($data): void
    {
        $logFile = 'amazon/cron/' . date('d-m-Y') . '.log';
        try {
            if ($data['user_id'] !== false) {
                $userId = $data['user_id'];

                if (!isset($data['limit'])) {
                    $limit = 500;
                } else {
                    $limit = $data['limit'];
                }

                if (!isset($data['page'])) {
                    $skip = 0;
                } else {
                    $skip = $data['page'] * $limit;
                }

                $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $productContainer = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
                if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
                    $sourceProductIds = $data['source_product_ids'];
                } else {
                    $sourceProductIds = $productContainer->find(['user_id' => $data['user_id']], ['projection' => ['source_product_id' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array'], 'skip' => $skip, 'limit' => $limit])->toArray();
                    if (!empty($sourceProductIds)) {
                        $sourceProductIds = array_column($sourceProductIds, 'source_product_id');
                    }
                }

                $this->di->getLog()->logContent('Initiate image sync for userId : ' . $userId . ' product array :' . PHP_EOL . json_encode($sourceProductIds), 'info', $logFile);

                if (!empty($sourceProductIds)) {
                    $sourceProductChunk = array_chunk($sourceProductIds, 500);
                    foreach ($sourceProductChunk as $sourceProductId) {
                        $products['source_product_ids'] = $sourceProductId;
                        $response = $this->di->getObjectManager()->get(Image::class)->init($data)->updateNew($products);
                        $this->di->getLog()->logContent('Initiate image sync response for userId ' . $userId . ':  ' . json_encode($response), 'info', $logFile);
                    }

                    // Push data in sqs
                    if (isset($data['queue_name'])) {
                        $sqs_data = $data;
                        if (!isset($sqs_data['limit'])) {
                            $sqs_data['limit'] = $limit;
                        }

                        if (!isset($sqs_data['page'])) {
                            $sqs_data['page'] = 1;
                        } else {
                            $sqs_data['page'] = $sqs_data['page'] + 1;
                        }

                        if (count($sourceProductIds) >= $sqs_data['limit']) {
                            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
                            $helper->createQueue($data['queue_name'], $sqs_data);
                        }
                    }

                    // Push data in sqs

                } else {
                    $this->di->getLog()->logContent('Initiate image sync userId :' . $userId . ' no product found: ', 'info', $logFile);
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Initiate image sync exception for userId ' . $userId . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);
        }
    }

    public function initiateReportFetch($userId = false): void
    {
        $logFile = 'amazon/cron/' . date('d-m-Y') . '.log';
        try {
            if ($userId !== false) {
                $data['user_id'] = $userId;
                $response = $this->di->getObjectManager()->get(Report::class)->init($data)->getReport();
                $this->di->getLog()->logContent('Initiate get report response for userId ' . $userId . ':  ' . json_encode($response), 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Initiate get Report exception for userId ' . $userId . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);
        }
    }

    public function initiateSearchOnAmazon($data): void
    {
        $logFile = 'amazon/cron/' . date('d-m-Y') . '.log';
        try {
            if ($data['user_id'] !== false) {
                $response = $this->di->getObjectManager()->get(Product::class)->init($data)->search($data);
                $this->di->getLog()->logContent('Initiate search on Amazon response for userId ' . $data['user_id'] . ':  ' . json_encode($response), 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Initiate search on Amazon exception for userId ' . $data['user_id'] . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);
        }
    }

    public function deleteShopData($userId, $shopData)
    {
        $exceptionLogFile = 'exceptions/' . date('d-m-Y') . '.log';
        try {

            if ($userId && isset($shopData['_id'], $shopData['remote_shop_id'])) {
                $shopId = $shopData['_id'];

                $connectedAmazonShopIds = [];
                $helper = $this->di->getObjectManager()->get('\App\Frontend\Components\AmazonebaymultiHelper');
                $shops = $helper->getAllConnectedAcccounts();
                if ($shops['success']) {
                    foreach ($shops['data'] as $shop) {
                        if (isset($shop['marketplace']) && $shop['marketplace'] === 'amazon') {
                            $connectedAmazonShopIds[] = $shop['id'];
                        }
                    }
                }

                if (count($connectedAmazonShopIds) > 1) {
                    $isLast = false;
                } else {
                    $isLast = true;
                }

                $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');

                // delete shop wise category settings from amazon product container
                $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::AMAZON_PRODUCT_CONTAINER);
                $response = $collection->UpdateMany(
                    ['user_id' => (string) $userId, "shops.{$shopId}" => ['$exists' => 1]],
                    ['$unset' => ["shops.{$shopId}" => 1]]
                );

                if ($isLast) {
                    // delete profiles & profile settings
                    $profile_collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILES);
                    $response = $profile_collection->deleteMany(['user_id' => (string) $userId], ['w' => true]);

                    $profile_setting = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILE_SETTINGS);
                    $response = $profile_setting->deleteMany(['user_id' => (string) $userId], ['w' => true]);

                    // delete profiles from product_container
                    $product_collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
                    $response = $product_collection->UpdateMany(
                        ['user_id' => (string) $userId, 'profile.app_tag' => 'amazon'],
                        ['$pull' => ['profile' => ['app_tag' => 'amazon']]]
                    );

                    // delete products from amazon_product_container
                    $amazon_product_collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::AMAZON_PRODUCT_CONTAINER);
                    $response = $amazon_product_collection->deleteMany(
                        ['user_id' => (string) $userId],
                        ['w' => true]
                    );

                    // delete config settings
                    $configuration = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::CONFIGURATION);
                    $configuration->deleteMany(
                        ['user_id' => (string) $userId],
                        ['w' => true]
                    );

                    // remove status from all products
                    $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
                    $collection->UpdateMany(
                        ['user_id' => (string) $userId /*, 'marketplace.amazon.shop_id' => (string)$shopId*/],
                        ['$unset' => ['marketplace.amazon' => 1]]
                    );
                } else {
                    // delete shop wise settings from profile
                    $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILES);
                    $response = $collection->UpdateMany(
                        ['user_id' => (string) $userId /*, "settings.amazon.{$shopId}" => ['$exists'=>1]*/],
                        ['$unset' => ["settings.amazon.{$shopId}" => 1, "targets.amazon.shops.{$shopId}" => 1]]
                    );

                    // delete shop wise products status's
                    $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
                    $collection->UpdateMany(
                        ['user_id' => (string) $userId /*, 'marketplace.amazon.shop_id' => (string)$shopId*/],
                        ['$pull' => ['marketplace.amazon' => ['shop_id' => (int) $shopId]]]
                    );
                    $collection->UpdateMany(
                        ['user_id' => (string) $userId /*, 'marketplace.amazon.shop_id' => (string)$shopId*/],
                        ['$pull' => ['marketplace.amazon' => ['shop_id' => (string) $shopId]]]
                    );
                }

                // delete shop orders
                $shopErasure = $this->di->getObjectManager()->get(ShopErasureHelper::class)->OrderEraser($userId, $shopId);
                // $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::ORDER_CONTAINER);
                // $collection->deleteMany(['user_id' => (string)$userId, 'shop_id' => (string)$shopId]);
                // $collection->deleteMany(['user_id' => (string)$userId, 'shop_id' => (int)$shopId]);

                // delete amazon account from remote
                $user_collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::USER_DETAILS);
                $accountCount = $user_collection->count([
                    '$or' => [
                        ['shops.remote_shop_id' => (int) $shopData['remote_shop_id']],
                        ['shops.remote_shop_id' => (string) $shopData['remote_shop_id']],
                    ]
                ]);

                if ($accountCount == 0) {
                    $amazonAccountDelete = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init('amazon')
                        ->call('app-shop', [], ['shop_id' => $shopData['remote_shop_id']], 'DELETE');
                }

                //unregister from register_for_order_sync
                $helper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                $response = $helper->sendRequestToAmazon('order-sync/unregister', [['home_shop_id' => $shopId, 'remote_shop_id' => $shopData['remote_shop_id']]], 'POST');

                return true;
            }
            return false;
        } catch (Exception $e) {
            $this->di->getLog()->logContent("Delete shop data for userId : {$userId}" . json_encode($shopData) . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $exceptionLogFile);
            return false;
        }
    }

    public function initiateReportRequest($data): void
    {
        $logFile = 'amazon/cron/' . date('d-m-Y') . '.log';
        try {
            if ($data['user_id'] !== false && $data['user_id'] != "61196c4e451531360564c0bc") {
                $response = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\Helper::class)->requestReport($data);
                $this->di->getLog()->logContent('Initiate report request for userId ' . $data['user_id'] . ':  ' . json_encode($response), 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Initiate report request exception for userId ' . $data['user_id'] . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);
        }
    }

    public function initiateMatchProductFromAmazon($data): void
    {
        $logFile = 'amazon/product_match/' . date('d-m-Y') . '.log';
        $type = $this->di->getAppCode()->getAppTag() . '_' . $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getMatchProductQueueName();

        try {
            if ($data['user_id'] !== false && isset($data['shop_id']) && $data['shop_id']) {

                $userId = $data['user_id'];

                if (!isset($data['limit'])) {
                    $limit = 500;
                } else {
                    $limit = $data['limit'];
                }

                if (!isset($data['page'])) {
                    $skip = 0;
                } else {
                    $skip = $data['page'] * $limit;
                }

                $shopId = $data['shop_id'];

                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $amazonCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);
                $amazonListing = $amazonCollection->find(
                    ['user_id' => (string) $userId, 'shop_id' => (string) $shopId],
                    ['projection' => ['product-id' => 1, 'seller-sku' => 1, 'status' => 1, 'asin1' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array'], 'limit' => $limit, 'skip' => $skip]
                );

                $listing = $amazonListing->toArray();

                $sourceProductsToSyncInventory = [];
                foreach ($listing as $product) {
                    $response = $this->di->getObjectManager()->get(Report::class)->init($data)->match($product, ['shop_id' => $shopId]);

                    /*if (isset($response['schedule_for_inventory_sync']) && isset($response['source_product_id']) && isset($response['source_variant_id'])) {
                $sourceProductsToSyncInventory[] = $response['schedule_for_inventory_sync'];
                unset($response['schedule_for_inventory_sync']);
                }*/
                }

                $this->di->getLog()->logContent('Initiate product match for userId ' . $userId . ':  ' . json_encode($data), 'info', $logFile);

                if (isset($data['queue_name'])) {

                    $query = ['user_id' => (string) $userId, 'shop_id' => (string) $shopId];
                    $options = [
                        'limit' => $limit,
                        'skip' => ($skip + $limit),
                    ];

                    $next = $amazonCollection->count($query, $options);

                    if ($next) {
                        $sqs_data = $data;
                        if (!isset($sqs_data['limit'])) {
                            $sqs_data['limit'] = $limit;
                        }

                        if (!isset($sqs_data['page'])) {
                            $sqs_data['page'] = 1;
                        } else {
                            $sqs_data['page'] = $sqs_data['page'] + 1;
                        }

                        $message = 'Match from Amazon is partially completed.';
                        $this->di->getObjectManager()->get('App\Connector\Components\Helper')
                            ->updateFeedProgress(
                                $data['queued_task_id'],
                                $data['individual_weight'],
                                $message
                            );

                        $response = $this->di->getMessageManager()->pushMessage($sqs_data);

                        $this->di->getLog()->logContent('next product match queue for userId : ' . $userId . ', data : ' . json_encode($sqs_data), 'info', $logFile);
                    } else {
                        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                        $report_container = $mongo->getCollection(\App\Amazon\Components\Common\Helper::REPORT_CONTAINER);
                        //                        $report_container->updateOne(['queued_task_id' => $data['queued_task_id']], ['$set' => ['match' => \App\Amazon\Components\Common\Helper::PRODUCT_MATCH_STATE_PARTIALLY_COMPLETED]]);
                        $report_container->updateOne(['queued_task_id' => $data['queued_task_id']], ['$set' => ['match' => \App\Amazon\Components\Common\Helper::PRODUCT_MATCH_STATE_PARTIALLY_COMPLETED]]);

                        $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($userId, "Match from Amazon completed", 'success');
                        $this->di->getObjectManager()->get('App\Connector\Components\Helper')->updateFeedProgress($data['queued_task_id'], 100);

                        $handlerData = [
                            'type' => 'full_class',
                            'class_name' => Requestcontrol::class,
                            'method' => 'updateProductContainerStatus',
                            'queue_name' => $data['queue_name'],
                            'user_id' => $data['user_id'] ?? $this->_user_id,
                            'queued_task_id' => $data['queued_task_id'],
                            'shop_id' => $data['shop_id'] ?? '',
                            'limit' => 1000,
                            'page' => 0,
                            'handle_added' => 1,
                            'own_weight' => 100,
                        ];

                        $response = $this->di->getMessageManager()->pushMessage($handlerData);

                        /*$mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                        $queuedTasks = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::QUEUED_TASKS);
                        $queuedTasks->deleteOne(['user_id' => $data['user_id'], 'shop_id' => (string)$data['shop_id'], 'type' => $type]);*/

                        $this->di->getLog()->logContent('product match is completed for variants, and product_container status update initiated for : ' . $userId . ', ProductContainerStatusUpdate sqs data : ' . json_encode($handlerData), 'info', $logFile);
                    }
                }
            }
        } catch (Exception $e) {
            $exceptionLogFile = 'exceptions/' . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('userId : ' . $data['user_id'] . PHP_EOL . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $exceptionLogFile);

            $this->di->getLog()->logContent('Initiate product match exception for userId ' . $data['user_id'] . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);

            if (isset($data['user_id'], $data['shop_id'])) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $queuedTasks = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::QUEUED_TASKS);
                $queuedTasks->deleteOne(['user_id' => $data['user_id'], 'shop_id' => (string) $data['shop_id'], 'type' => $type]);
            }
        }
    }

    public function initiateProductContainerStatusUpdate($data): void
    {
        try {
            $logFile = 'amazon/product_container_status_update/' . date('d-m-Y') . '.log';

            if (isset($data['user_id'])) {
                $userId = $data['user_id'];

                $operation = $data['operation'] ?? 'prepare_status';
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

                if ($operation == 'prepare_status') {
                    $firstPageFlag = false;

                    $this->di->getLog()->logContent("Start prepare_status of container products for userId {$userId} : " . json_encode($data), 'info', $logFile);

                    if (!isset($data['limit'])) {
                        $limit = 1000;
                    } else {
                        $limit = $data['limit'];
                    }

                    if (!isset($data['page']) || $data['page'] == 0) {
                        $skip = 0;

                        $firstPageFlag = true;
                        $productStatusTemp = $mongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_PRODUCT_STATUS_TEMP);
                        $response = $productStatusTemp->deleteMany([
                            'user_id' => $userId,
                        ], ['w' => true]);
                    } else {
                        $skip = $data['page'] * $limit;
                    }

                    $productCollection = $mongo->getCollection(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

                    $query = [
                        'user_id' => $userId,
                        //                        'marketplace.amazon.status' => ['$exists' => 1],
                        'container_id' => ['$exists' => 1],
                    ];

                    $options = [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'projection' => ['_id' => 1, 'user_id' => 1, 'source_product_id' => 1, 'container_id' => 1, 'group_id' => 1, 'variant_title' => 1, 'variant_attributes' => 1, 'marketplace' => 1],
                        'skip' => $skip,
                        'limit' => $limit,
                    ];

                    $products = $productCollection->find($query, $options)->toArray();

                    $queriedProductCount = count($products);

                    $containerStatusesNew = [];
                    $partialStatusFlag = [];
                    $variantNotUploadedFlag = [];
                    $container_ids = [];
                    print_r($products);

                    foreach ($products as $product) {
                        $container_id = $product['container_id'];

                        $isUploadedProduct = false;

                        foreach ($product['marketplace'] as $statusInfo) {
                            if (isset($statusInfo['target_marketplace']) && $statusInfo['target_marketplace'] == 'amazon') {
                                $isUploadedProduct = true;
                                if (isset($variantNotUploadedFlag[$container_id])) {
                                    $partialStatusFlag[$container_id] = $container_id;
                                }

                                $shopId = $statusInfo['shop_id'];

                                if (isset($statusInfo['status'])) {
                                    if (isset($containerStatusesNew[$container_id][$shopId])) {
                                        $status1 = $containerStatusesNew[$container_id][$shopId]['status'];
                                        $status2 = $statusInfo['status'] ?? '';

                                        if ($status1 != $status2) {
                                            $partialStatusFlag[$container_id] = $container_id;
                                            //                                                $containerStatusesNew[$container_id][$shopId]['status'] = $this->getHigherStatus($status1, $status2);
                                            $containerStatusesNew[$container_id][$shopId]['status'] = 'Some Variants Listed';
                                        }
                                    } else {
                                        $containerStatusesNew[$container_id][$shopId] = [
                                            'status' => 'Some Variants Listed',
                                            'shop_id' => $shopId,
                                            'seller_id' => $statusInfo['seller_id'],
                                        ];

                                        $container_ids[] = (string) $container_id;
                                    }
                                }
                            }
                        }

                        if (!$isUploadedProduct) {
                            $variantNotUploadedFlag[$container_id] = $container_id;

                            if (isset($containerStatusesNew[$container_id])) {
                                $partialStatusFlag[$container_id] = $container_id;
                            }
                        }
                    }

                    $containerStatusesOld = [];

                    if (!$firstPageFlag) {
                        $query = [
                            'container_id' => ['$in' => $container_ids],
                        ];
                        $options = [
                            'typeMap' => ['root' => 'array', 'document' => 'array'],
                        ];

                        $productStatusTemp = $mongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_PRODUCT_STATUS_TEMP);
                        $productStatusTempResp = $productStatusTemp->find($query, $options)->toArray();

                        foreach ($productStatusTempResp as $resp) {
                            $containerStatusesOld[$resp['container_id']] = $resp;
                        }
                    }
                    $bulkInsertArray = [];
                    $bulkOpArray = [];

                    foreach ($containerStatusesNew as $containerId => $shopWiseStatus) {
                        if (empty($containerStatusesOld)) {
                            $insertData = [
                                '_id' => (string) $containerId,
                                'user_id' => $userId,
                                'container_id' => (string) $containerId,
                                'status' => array_values($shopWiseStatus),
                            ];

                            $bulkInsertArray[] = $insertData;
                        } else {
                            foreach ($shopWiseStatus as $shopId => $newStatus) {
                                if (isset($containerStatusesOld[$containerId]['status'])) {
                                    $containerData = $containerStatusesOld[$containerId]['status'];
                                    if (isset($containerData[$shopId])) {
                                        $partial_status_changed = false;
                                        if (isset($containerData[$shopId]['partial_status'])) {
                                            $newPartialStatus = isset($partialStatusFlag[$containerId]) ? true : false;
                                            if ($newPartialStatus != $containerData[$shopId]['partial_status']) {
                                                $partial_status_changed = true;
                                            }
                                        }

                                        if (!isset($containerData[$shopId]['status']) || $containerData[$shopId]['status'] != $newStatus['status'] || $partial_status_changed) {
                                            $query = ['_id' => $containerId, 'status.shop_id' => (string) $shopId];

                                            $_set = ['status.$.status' => $newStatus['status']];

                                            $updateData = [
                                                '$set' => $_set,
                                            ];

                                            $bulkOpArray[] = [
                                                'updateOne' => [
                                                    $query,
                                                    $updateData,
                                                ],
                                            ];
                                        }
                                    } else {
                                        $query = ['_id' => $containerId];

                                        $statusInfo = [
                                            'shop_id' => $shopId,
                                            'seller_id' => $newStatus['seller_id'],
                                            'status' => $newStatus['status'],
                                        ];

                                        $updateData = [
                                            '$push' => [
                                                'status' => $statusInfo,
                                            ],
                                        ];

                                        $bulkOpArray[] = [
                                            'updateOne' => [
                                                $query,
                                                $updateData,
                                            ],
                                        ];
                                    }
                                } else {
                                    $insertData = [
                                        '_id' => (string) $containerId,
                                        'user_id' => $userId,
                                        'container_id' => (string) $containerId,
                                        'status' => array_values($shopWiseStatus),
                                    ];

                                    $bulkInsertArray[] = $insertData;
                                }
                            }
                        }
                    }

                    $this->di->getLog()->logContent("container products prepare_status to be updated in amazon_product_status_temp table for userId {$userId} : " . PHP_EOL . 'insert data : ' . json_encode($bulkInsertArray, true) . PHP_EOL . 'update data : ' . json_encode($bulkOpArray), 'info', $logFile);

                    if (!empty($bulkInsertArray)) {
                        $bulkInsertObj = $productStatusTemp->insertMany($bulkInsertArray);
                        $returenRes = [
                            'inserted' => $bulkInsertObj->getInsertedCount(),
                        ];

                        $this->di->getLog()->logContent('Result of insertion for userId ' . $userId . ':  ' . print_r($returenRes, true), 'info', $logFile);
                    } else {
                        $this->di->getLog()->logContent("Result of insertion for userId {$userId} : No product inserted", 'info', $logFile);
                    }

                    if (!empty($bulkOpArray)) {
                        $bulkObj = $productStatusTemp->BulkWrite($bulkOpArray, ['w' => 1]);
                        $returenRes = [
                            'acknowledged' => $bulkObj->isAcknowledged(),
                            'inserted' => $bulkObj->getInsertedCount(),
                            'modified' => $bulkObj->getModifiedCount(),
                            'matched' => $bulkObj->getMatchedCount(),
                        ];

                        $this->di->getLog()->logContent('Result of updatation for userId ' . $userId . ':  ' . print_r($returenRes, true), 'info', $logFile);
                    } else {
                        $this->di->getLog()->logContent("Result of updatation for userId {$userId} : No product updated", 'info', $logFile);
                    }

                    if (isset($data['queue_name'])) {
                        if ($queriedProductCount == $limit) {
                            $sqs_data = $data;
                            if (!isset($sqs_data['limit'])) {
                                $sqs_data['limit'] = $limit;
                            }

                            if (!isset($sqs_data['page'])) {
                                $sqs_data['page'] = 1;
                            } else {
                                $sqs_data['page'] = $sqs_data['page'] + 1;
                            }

                            if (!isset($sqs_data['handle_added'])) {
                                $sqs_data['handle_added'] = 1;
                            }

                            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
                            $helper->createQueue($data['queue_name'], $sqs_data);

                            $this->di->getLog()->logContent('next product_container_status_update prepare_status queue for userId : ' . $userId . ', data : ' . json_encode($sqs_data), 'info', $logFile);
                        } else {
                            $sqs_data = $data;

                            $sqs_data['page'] = 0;
                            $sqs_data['limit'] = 1000;
                            $sqs_data['own_weight'] = 100;
                            $sqs_data['operation'] = 'sync_status';

                            if (!isset($sqs_data['handle_added'])) {
                                $sqs_data['handle_added'] = 1;
                            }

                            $reponse = $this->di->getMessageManager()->pushMessage($sqs_data);
                            $this->di->getLog()->logContent('prepare_status of container products COMPLETED. sync_status initiated for : ' . $userId . ', ProductContainerStatusUpdate sqs data : ' . json_encode($sqs_data), 'info', $logFile);
                        }
                    }
                } elseif ($operation == 'sync_status') {

                    $this->di->getLog()->logContent("Start sync_status of container products for userId {$userId} : " . json_encode($data), 'info', $logFile);

                    if (!isset($data['limit'])) {
                        $limit = 1000;
                    } else {
                        $limit = $data['limit'];
                    }

                    if (!isset($data['page']) || $data['page'] == 0) {
                        $skip = 0;
                    } else {
                        $skip = $data['page'] * $limit;
                    }

                    $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                    $productStatusTemp = $mongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_PRODUCT_STATUS_TEMP);

                    $query = [
                        'user_id' => $userId,
                    ];

                    $options = [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'skip' => $skip,
                        'limit' => $limit,
                    ];

                    $productsStatus = $productStatusTemp->find($query, $options)->toArray();

                    $queriedProductCount = count($productsStatus);

                    $productCollection = $mongo->getCollection(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

                    $bulkOpArray = [];

                    foreach ($productsStatus as $productStatus) {
                        $container_id = $productStatus['container_id'];

                        $_newStatus = [];
                        foreach ($productStatus['status'] as $statusInfo) {
                            $_newStatus[$statusInfo['shop_id']] = $statusInfo;
                        }

                        $products = $productCollection->find(
                            ['user_id' => $userId, 'container_id' => (string) $container_id, 'source_product_id' => (string) $container_id],
                            [
                                'typeMap' => ['root' => 'array', 'document' => 'array'],
                                'projection' => ['source_product_id' => 1, 'container_id' => 1, 'shop_id' => 1, 'marketplace' => 1],
                            ]
                        )->toArray();

                        $container = [];

                        if (count($products) == 1) {
                            $container = current($products);
                        } else {
                            foreach ($products as $product) {
                                if ($product['source_product_id'] == $product['container_id']) {
                                    $container = $product;
                                    break;
                                }
                            }
                        }

                        if ($container) {
                            $isUploadedProduct = false;
                            foreach ($container['marketplace'] as $amazon_status) {
                                if (isset($amazon_status['target_marketplace']) && $amazon_status['target_marketplace'] != 'amazon') {
                                    $isUploadedProduct = true;

                                    if (isset($amazon_status['shop_id'], $amazon_status['status'])) {
                                        if (isset($_newStatus[$amazon_status['shop_id']])) {
                                            if ($amazon_status['status'] != $_newStatus[$amazon_status['shop_id']]['status']) {
                                                $query = ['_id' => $container['_id'], 'marketplace.target_marketplace' => 'amazon', 'marketplace.seller_id' => (string) $amazon_status['seller_id']];

                                                $updateData = [
                                                    '$set' => ['marketplace.$.status' => $_newStatus[$amazon_status['shop_id']]['status']],
                                                ];

                                                $bulkOpArray[] = [
                                                    'updateOne' => [
                                                        $query,
                                                        $updateData,
                                                    ],
                                                ];
                                            }

                                            unset($_newStatus[$amazon_status['shop_id']]);
                                        } else {
                                            $query = ['_id' => $container['_id'], 'marketplace.target_marketplace' => 'amazon', 'marketplace.seller_id' => (string) $amazon_status['seller_id']];

                                            $updateData = [
                                                '$unset' => ['marketplace.$.status' => 1],
                                            ];

                                            $bulkOpArray[] = [
                                                'updateOne' => [
                                                    $query,
                                                    $updateData,
                                                ],
                                            ];
                                        }
                                    } else if (!isset($amazon_status['status']) && isset($_newStatus[$amazon_status['shop_id']]['status'])) {

                                        $query = ['_id' => $container['_id'], 'marketplace.amazon.shop_id' => (string) $amazon_status['shop_id']];

                                        $updateData = [
                                            '$set' => ['marketplace.amazon.$.status' => $_newStatus[$amazon_status['shop_id']]['status']],
                                        ];

                                        $bulkOpArray[] = [
                                            'updateOne' => [
                                                $query,
                                                $updateData,
                                            ],
                                        ];
                                        unset($_newStatus[$amazon_status['shop_id']]);
                                    }
                                }
                            }

                            if (!$isUploadedProduct) {
                                $updatedData = current($productStatus['status']);
                                $updatedData['shop_id'] = $container['shop_id'];
                                $updatedData['source_product_id'] = $container['source_product_id'];
                                $updatedData['status'] = 'Some Variants Listed';
                                $updatedData['target_marketplace'] = 'amazon';
                                $updatedData['direct'] = false;
                                $query = ['_id' => $container['_id']];

                                $updateData = [
                                    '$push' => [
                                        'marketplace' => $updatedData,
                                    ],
                                ];

                                $bulkOpArray[] = [
                                    'updateOne' => [
                                        $query,
                                        $updateData,
                                    ],
                                ];
                            }
                        }
                    }

                    $this->di->getLog()->logContent("product status to be updated for userId {$userId} : " . PHP_EOL . 'update data : ' . json_encode($bulkOpArray), 'info', $logFile);

                    if (!empty($bulkOpArray)) {
                        $bulkObj = $productCollection->BulkWrite($bulkOpArray, ['w' => 1]);
                        $returenRes = [
                            'acknowledged' => $bulkObj->isAcknowledged(),
                            'inserted' => $bulkObj->getInsertedCount(),
                            'modified' => $bulkObj->getModifiedCount(),
                            'matched' => $bulkObj->getMatchedCount(),
                        ];

                        $this->di->getLog()->logContent('Result of product_container_status_update for userId ' . $userId . ':  ' . print_r($returenRes, true), 'info', $logFile);
                    } else {
                        $this->di->getLog()->logContent("Result of product_container_status_update for userId {$userId} : No product updated", 'info', $logFile);
                    }

                    // Push data in sqs
                    if (isset($data['queue_name'])) {
                        if ($queriedProductCount == $limit) {
                            $sqs_data = $data;
                            if (!isset($sqs_data['limit'])) {
                                $sqs_data['limit'] = $limit;
                            }

                            if (!isset($sqs_data['page'])) {
                                $sqs_data['page'] = 1;
                            } else {
                                $sqs_data['page'] = $sqs_data['page'] + 1;
                            }

                            if (!isset($sqs_data['handle_added'])) {
                                $sqs_data['handle_added'] = 1;
                            }

                            $reponse = $this->di->getMessageManager()->pushMessage($sqs_data);

                            $this->di->getLog()->logContent('next product_container_status_update queue for userId : ' . $userId . ', data : ' . json_encode($sqs_data), 'info', $logFile);
                        } else {
                            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                            $report_container = $mongo->getCollection(\App\Amazon\Components\Common\Helper::REPORT_CONTAINER);
                            $report_container->updateOne(['queued_task_id' => $data['queued_task_id']], ['$set' => ['match' => \App\Amazon\Components\Common\Helper::PRODUCT_MATCH_STATE_COMPLETED]]);
                            $queuedTasks = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::QUEUED_TASKS);
                            $type = $this->di->getObjectManager()->get(Report::class)->getMatchProductQueueType();
                            $queuedTasks->deleteOne(['user_id' => $userId, 'shop_id' => (string) $data['shop_id'], 'type' => $type]);

                            $this->di->getLog()->logContent('product_container_status_update COMPLETED for userId : ' . $userId, 'info', $logFile);
                        }
                    }
                }
            } else {
                $this->di->getLog()->logContent('product_container_status_update exception : user Id not found, data : ' . json_encode($data), 'info', $logFile);
            }
        } catch (Exception $e) {
            $exceptionLogFile = 'exceptions/' . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('userId : ' . $data['user_id'] . PHP_EOL . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $exceptionLogFile);

            $this->di->getLog()->logContent('Initiate product match exception for userId ' . $data['user_id'] . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);

            if (isset($data['user_id'], $data['shop_id'])) {
                //removing notification
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $queuedTasks = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::QUEUED_TASKS);
                $type = $this->di->getObjectManager()->get(Report::class)->getMatchProductQueueType();
                $queuedTasks->deleteOne(['user_id' => $data['user_id'], 'shop_id' => (string) $data['shop_id'], 'type' => $type]);
            }
        }
    }

    public function getHigherStatus($status1, $status2)
    {
        $statusList = [
            'Active' => 1,
            'Inactive' => 2,
            'Available for Offer' => 3,
            'Incomplete' => 4,
            'Supressed' => 5,
            'Submitted' => 6,
            'Disabled' => 7,
        ];

        $statusCode1 = $statusList[$status1] ?? 100;
        $statusCode2 = $statusList[$status2] ?? 100;

        if ($statusCode1 < $statusCode2) {
            $finalStatusCode = $statusCode1;
        } else {
            $finalStatusCode = $statusCode2;
        }

        if ($finalStatusCode == 100) {
            return $status1;
        }
        return array_search($finalStatusCode, $statusList);
    }

    public function getAllProductStatuses()
    {
        $statusArray = $this->di->getObjectManager()->get(Helper::class)->getAllProductStatuses();
        return ['status' => true, 'data' => $statusArray];
    }

    public function getProductAttributes($id)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
        if (isset($id['container_id'])) {
            $response = $productCollection->aggregate([
                [
                    '$match' => ['container_id' => $id['container_id']],
                ],
                [
                    '$unwind' => '$variant_attributes',
                ],
                [
                    '$group' => [
                        '_id' => $id['container_id'],
                        'variant_attributes' => [
                            '$addToSet' => '$variant_attributes',
                        ],
                    ],
                ],
            ])->toArray();
        } else {
            $userId = $this->di->getUser()->id;
            $response = $productCollection->aggregate([
                [
                    '$match' => ['user_id' => $userId],
                ],
                [
                    '$unwind' => '$variant_attributes',
                ],
                [
                    '$group' => [
                        '_id' => $userId,
                        'variant_attributes' => [
                            '$addToSet' => '$variant_attributes',
                        ],
                    ],
                ],
            ])->toArray();
        }

        $newAttribute = [];
        $newAttribute = $response;

        $unique = [];
        $temp = [];

        $temp = array_unique((array) $newAttribute[0]['variant_attributes']);
        foreach ($temp as $value) {
            array_push($unique, ['code' => $value, 'title' => $value, 'required' => 0]);
        }

        $fixedAttributes = [
            [
                'code' => 'handle',
                'title' => 'Handle',
                'required' => 0,
            ],
            [
                'code' => 'product_type',
                'title' => 'Product Type',
                'required' => 0,
            ],
            [
                'code' => 'type',
                'title' => 'type',
                'required' => 0,
            ],
            [
                'code' => 'title',
                'title' => 'title',
                'required' => 0,
            ],
            [
                'code' => 'brand',
                'title' => 'brand',
                'required' => 0,
            ],
            [
                'code' => 'description',
                'title' => 'description',
                'required' => 0,
            ],
            [
                'code' => 'tags',
                'title' => 'tags',
                'required' => 0,
            ],
            [
                'code' => 'sku',
                'title' => 'sku',
                'required' => 0,
            ],
            [
                'code' => 'price',
                'title' => 'price',
                'required' => 0,
            ],
            [
                'code' => 'quantity',
                'title' => 'quantity',
                'required' => 0,
            ],
            [
                'code' => 'weight',
                'title' => 'weight',
                'required' => 0,
            ],
            [
                'code' => 'weight_unit',
                'title' => 'weight_unit',
                'required' => 0,
            ],
            [
                'code' => 'grams',
                'title' => 'grams',
                'required' => 0,
            ],
            [
                'code' => 'barcode',
                'title' => 'barcode',
                'required' => 0,
            ],
        ];
        $fixedAttributes = [...$fixedAttributes, ...$unique];
        return ['success' => true, 'data' => $fixedAttributes];
    }

    public function getProductStatusByShopId($product, $shop_id)
    {
        $status = Helper::PRODUCT_STATUS_NOT_LISTED;
        if (isset($product['marketplace'])) {
            foreach ($product['marketplace'] as $product) {
                if ($product['shop_id'] == $shop_id) {
                    $status = $product['status'] ?? $status;
                }
            }
        }

        return $status;
    }

    public function prepareConfigData($confkey, $confvalue, $sourceShopId = false)
    {
        $warehouses = [];
        $user_details = $this->di->getObjectManager()->get('App\Core\Models\User\Details');
        $shopData = $user_details->getShop($sourceShopId);
        foreach ($shopData['warehouses'] as $key => $warehouse) {
            if ($warehouse['active'] == true && isset($warehouse['name'])) {
                array_push($warehouses, (string) $warehouse['id']);
            }
        }

        if (array_key_exists($confkey, $this->default_value)) {
            $arr = [];
            foreach ($this->default_value[$confkey] as $key => $value) {
                if ($confkey == "inventory") {
                    if ($key == 'inventory_settings') {
                        $arr[] = ['key' => $key, 'value' => $confvalue, 'group_code' => $confkey];
                    } else {
                        if ($key == 'warehouses_settings') {
                            $arr[] = ['key' => $key, 'value' => $warehouses, 'group_code' => $confkey];
                        } else {
                            $arr[] = ['key' => $key, 'value' => $this->default_value[$confkey][$key], 'group_code' => $confkey];
                        }
                    }
                } else {
                    if ($key == 'settings_enabled') {
                        $arr[] = ['key' => $key, 'value' => $confvalue, 'group_code' => $confkey];
                    } else {
                        if ($key == 'warehouses_settings') {
                            $arr[] = ['key' => $key, 'value' => $warehouses, 'group_code' => $confkey];
                        } else {
                            $arr[] = ['key' => $key, 'value' => $this->default_value[$confkey][$key], 'group_code' => $confkey];
                        }
                    }
                }
            }

            return $arr;
        }
        $arr = [];
        $product_selected_attributes = [];
        if ($confkey == 'product_details' || $confkey == 'images' || $confkey == 'sku' || $confkey == 'barcode') {
            if ($confkey == 'product_details' && $confvalue) {
                array_push($this->default_value['product']['selected_attributes'], "product_syncing");
                $arr[] = ['key' => 'product_details', 'value' => $confvalue, 'group_code' => 'product'];
            }

            if ($confkey == 'images' && $confvalue) {
                array_push($this->default_value['product']['selected_attributes'], "images");
                $arr[] = ['key' => 'images', 'value' => $confvalue, 'group_code' => 'product'];
            }

            if ($confkey == 'sku' && $confvalue) {
                array_push($this->default_value['product']['sync_status_with'], "sku");
            }

            if ($confkey == 'barcode' && $confvalue) {
                array_push($this->default_value['product']['sync_status_with'], "barcode");
            }

            foreach ($this->default_value['product'] as $key => $value) {
                if ($key == 'settings_enabled') {
                    $arr[] = ['key' => $key, 'value' => $confvalue, 'group_code' => 'product'];
                } else {
                    $arr[] = ['key' => $key, 'value' => $this->default_value['product'][$key], 'group_code' => 'product'];
                }
            }

            return $arr;
        }
    }

    public function getCategory($data)
    {
        $category = $this->di->getObjectManager()->get(\App\Amazon\Components\Template\Helper::class);
        $data['shop_id'] = $data['target']['shopId'];
        return $category->getCategory($data);
    }

    public function getCategoryAttributes($data)
    {
        try {
            $templateHelper = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Template\Helper::class);
            $response = $templateHelper->getAmazonAttributes($data);
        } catch (Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $response;
    }

    public function categorySearch($data)
    {
        $data['shop_id'] = $data['target']['shopId'];
        try {
            $templateHelper = $this->di->getObjectManager()
                ->get(\App\Amazon\Components\Template\Helper::class);
            $response = $templateHelper->categorySearch($data);
        } catch (Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $response;
    }

    public function statusMatch($data)
    {
        try {
            $res = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\Helper::class)->requestReport($data);
            return $res;
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    public function lookUp($data)
    {
        try {
            $response = $this->di->getObjectManager()->get(Lookup::class)
                ->init($data)->initiateLookup($data);
            return $response;
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    public function initiateFailedOrderSync($userId = false)
    {
        $logFile = 'amazon/cron/' . date('d-m-Y') . '.log';
        try {
            if ($userId !== false) {
                $data['user_id'] = $userId;
                $response = $this->di->getObjectManager()->get(Order::class)->init($data)->fetchFailedOrders($data);

                $this->di->getLog()->logContent('Initiate order sync response for userId ' . $userId . ':  ' . json_encode($response), 'info', $logFile);

                return $response;
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Initiate order sync exception for userId ' . $userId . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $logFile);
        }
    }

    public function deleteValueMappingData($data): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $attributeContainer = $mongo->getCollectionForTable('amazon_value_mapping');
        $userId = $this->di->getUser()->id;
        $profileId = (string)$data['profile_id'] ?? "";
        $attributeContainer->deleteMany(['user_id' => $userId, 'profile_id' => $profileId]);
    }

    public function afterProfileSave($data)
    {
        try {
            // if(isset($data['operation_type']) && $data['operation_type'] === "delete") {
            //     // $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Upload')->removeReadyToUpload($data);
            //     $this->deleteValueMappingData($data);
            //     // return true;
            // } else {
            //     // $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Upload')->setReadyToUpload($data);
            // }
            $data = json_decode(json_encode($data), true);
            $params['target']['shopId'] = $data['shop_details']['target']['shopId'] ?? "";
            $params['source']['shopId'] = $data['shop_details']['source']['shopId'] ?? "";
            $params['target']['marketplace'] = $data['shop_details']['target']['marketplace'] ?? "";
            $params['source']['marketplace'] = $data['shop_details']['source']['marketplace'] ?? "";
            $params['user_id'] = $this->di->getUser()->id;
            if (!empty($data['assigned_profiles'])) {
                $all_assigned_profile_id = array_column($data['assigned_profiles'], '$oid');
                foreach ($all_assigned_profile_id as $value_id) {
                    $params['profile_id'] = $value_id;
                    $this->di->getObjectManager()->get(ValueMapping::class)->valueMapping($params);
                }
            } elseif (!empty($data['profileId'])) {
                $params['profile_id'] = $data['profileId']['$oid'];
                $this->di->getObjectManager()->get(ValueMapping::class)->valueMapping($params);
            }

            if (!empty($data['affected_profiles'])) {
                $affectedProfiles = array_column($data['affected_profiles'], '$oid');
                foreach ($affectedProfiles as $id) {
                    $params['profile_id'] = $id;
                    $this->di->getObjectManager()->get(ValueMapping::class)->valueMapping($params);
                }
            }

            return true;
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    public function validateSaveProduct($data, $user_id = false, $addtionalData = false)
    {
        return $this->di->getObjectManager()->get(saveProductValidation::class)->validateSaveProduct($data, $user_id, $addtionalData);
    }

    public function handleProductDeleteEvent($data)
    {
        try {
            $data = json_decode(json_encode($data), true);
            $userId = $data['user_id'];
            $profile = $data['profile'];
            $prepData = [];

            if (isset($profile['profile_id']) && !empty($profile['profile_id']) && isset($profile['target_shop_id']) && !empty($profile['target_shop_id'])) {
                if (isset($data['target_shop_id']) && !empty($data['target_shop_id'])) {
                    if ($profile['target_shop_id'] == $data['target_shop_id']) {
                        $prepData = ['profile_id' => $profile['profile_id']['$oid'], 'user_id' => $userId];
                        $prepData['target'] = ['shopId' => $profile['target_shop_id'], 'marketplace' => 'amazon'];
                        $prepData['source'] = ['shopId' => $data['shop_id'], 'marketplace' => $data['source_marketplace']];
                        $this->di->getObjectManager()->get(ValueMapping::class)->valueMapping($prepData);
                        return true;
                    }

                    return true;
                }

                return true;
            }

            return true;
        } catch (Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }



    /**
     * Retrieves error messages based on error codes from a given data array.
     *
     * @param array $data An array containing error information, where each element has '_id', 'msg', and 'count'.
     *
     * @return array An array of error details with '_id', 'msg', and 'count'.
     */

    public function getAllErrors($data)
    {
        $errorArray = [
            "8058" => "some attributes are missing in product",
            "AmazonError103" => "Product Descriptions are exceeding the 2000 characters limit",
            'AmazonError101' => "Product Category is not selected.",
            'AmazonError102' => "Sku is a required field, please fill sku and upload again.",
            'AmazonError104' => "SKU lengths are exceeding the 40 character limit.",
            'AmazonError105' => "Price is a required field, please fill price and upload again.",
            'AmazonError106' => "Price should be greater than 0.01, please update it from the shopify listing page.",
            'AmazonError108' => "Quantity is a required field, please fill quantity and upload again.",
            'AmazonError109' => "Barcode is a required field, please fill barcode and upload again.",
            'AmazonError110' => "Barcode is not valid, please provide a valid barcode and upload again.",
            'AmazonError111' => "'condition_type' is required but not supplied.",
            'AmazonError107' => "Product Syncing is Disabled. Please Enable it from the Settings and Try Again",
            'AmazonError121' => "Product you selected is not an FBM product.",
            'AmazonError123' => "Inventory Syncing is disabled. Please check the Settings and Try Again.",
            'AmazonError124' => 'The selected function cannot be performed on this product because it is not fulfilled by merchant (FBM) product.',
            'AmazonError125' => 'Warehouse in the selected Template Disabled - Please choose an active warehouse for this product.',
            'AmazonError141' => "Price Syncing is Disabled. Please Enable it from the Settings and Try Again.",
            'AmazonError161' => "Product does not have an image.",
            'AmazonError162' => "Image Syncing is Disabled. Please Enable it from Settings and Try Again.",
            'AmazonError501' => "Product not listed on Amazon.",
            'AmazonError900' => "Feed not generated. Kindly contact support.",
            'AmazonError901' => "The feed language is not English. Please change your language preference to English from your Amazon Seller Central account.",
            'AmazonError902' => "Feed Content not found.",
            'AmazonError903' => "Shop not found.",
            '99010' => "A value is missing from one or more required columns",
            '1793' => "Conflicting request, Kindly re-upload the product",
            'AmazonError141' => "Price Syncing is Disabled. Please Enable it from the Settings",
            '5995' => "Can not change brand name for this ASIN",
            '8560' => "Some Attributes are missing in this product",
            '90117' => "A invalid value is assigned from one or more products",
            '5887' => "Some of the product details are not allowed in the ASIN, either remove ASIN or create offer for this product",
            '20000' => "Invalid media URL",
            '99006' => "variation theme value is missing",
            '8115' => "Condition type value is not valid",
            '8026' => "Not Authorised to list product in this category",
            '8684' => "SKU is associated to more than 1 GCID",
            '8541' => "Diffrent SKU data provided",
            'AmazonError001' => "Price Syncing is Disabled.",
            'AmazonError904' => "Feed cancelled from Amazon, Kindly re-upload",
            '97778' => "Invalid HSN code",
            '99001' => "Some valued which are required are not present in product",
            '99003' => "A required value is missing",
            'AmazonError123' => "Inventory Syncing is Disabled.",
            "6024" => "Not Authorised to list product in this category",
            "8542" => "Conflicting SKU data",
            "8032" => "Unable to assign Child SKU",
            "8804" => "Inconsistent information for varient SKU",
            "1780" => "Conflict in feed submission kindly re-upload",
            "1793" => "Conflict in feed submission kindly re-upload",
            "5461" => "Cant create ASIN for given brand",
            "5665" => "Request approval",
            "5995" => "Can not change brand name for this ASIN",
            "6039" => "Restricted Sub category, Need Approval for this",
            "8007" => "Parent SKU not found",
            "8016" => "In-sufficient data provided for creating variation",
            "8105" => "Invalid values in product",
            "8603" => "item_classification value can not be changed",
            "90003951" => "Not allowed attributes found in product",
            "90057" => "The Target Gender field contains an invalid value",
            "90111" => "Some field contains invalid value",
            "8056" => "Incorrect ASIN provided as the 'external product ID'",
            "5000" => "Invalid Feed data in one or more of your item fields.",
            "8045" => "Invalid Parent/Child relationships",
            "8005" => "Identity attribute for this SKU is invalid",
            "8066" => "Invalid Parent/Child relationships",
            "5002" => "Amazon server is not currently responding.",
            "8101" => "Invalid Product Type selected in the product",
            "8065" => "Invalid/missing value for variation theme",
            "8008" => "Child SKU is missing",
            "8047" => "Invalid Parent/Child relationships",
            "8040" => "Brand Error- create offer instead of new Listing",
            "4400" => "Your seller account is not approved to dell seller-fulfilled products for this category.",
            "6028" => "Not authorized to sell in selected category.",
            "5561" => "trademarked terms in the keywords field is not valid",
            "6040" => "Not authorized to sell in selected category.",
            "8103" => "Invalid Product Type selected.",
            "6030" => "Not authorized to sell in selected category.",
            "6038" => "Merchant is not authorized to sell products under this GL and product type (PTD)",
            "5462" => "Attribute identity change is not allowed for this SKU",
            "8031" => "SKU is same for variation Child and Parent",
            "5666" => "New ASIN creation is blocked contact Amazon support ",
        ];
        // Initialize an empty array to store the result.
        $result = [];
        // Iterate through the given data array to map error codes to messages.
        foreach ($data as $value) {
            if (array_key_exists($value['_id'], $errorArray)) {
                // If the error code is found in the mapping, use the mapped message.
                $result[] = [
                    '_id'   => $value['_id'],
                    'msg'   => $errorArray[$value['_id']],
                    'count' => $value['count']
                ];
            } else {
                // If the error code is not found in the mapping, use the original message.
                $result[] = [
                    '_id'   => $value['_id'],
                    'msg'   => $value['msg'],
                    'count' => $value['count']
                ];
            }
        }

        // Return the final result array.
        return $result;
    }

    public function uploadfulfilmentFiletoS3($rawBody, $uploadedFile)
    {

        if (!isset($rawBody['fulfillment_type'])) {
            return [
                'success' => false,
                'message' => "please provide fulfilment type "
            ];
        }
        // Validate file type
        if (!$this->isValidExcelFile($uploadedFile)) {
            return $this->errorResponse("Please upload a valid Excel file (.xlsx or .xls)");
        }

        $config = $this->di->getConfig();
        $bucketName = $config->get("bulkUpdate_bucket");
        $keyName =  $uploadedFile->getName(); // File path in S3

        $config = include BP . '/app/etc/aws.php';
        $spreadsheet = IOFactory::load($uploadedFile->getTempName());
        $sheet = $spreadsheet->getActiveSheet();

        // Get the total number of rows
        $highestRow = $sheet->getHighestRow(); // Total number of rows
        $s3Client = new S3Client($config);

        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shop = $userDetails->getShop($sqs_data['data']['target_shop_id'], $sqs_data['user_id']);
        $userId = $this->di->getUser()->id;
        $targetShopId = $this->di->getRequester()->getTargetId();
        $sourceShopId = $this->di->getRequester()->getSourceId();
        $targetMarketplace = $this->di->getRequester()->getTargetName();
        $fulfillment_channel_code = false;
        if ($rawBody['fulfillment_type'] == "FBA") {
            $payload = [
                'product_type' => "PRODUCT",
                'remote_shop_id' => $shop['remote_shop_id'],
                'marketplace_id' => $shop['warehouses'][0]['marketplace_id'],
                'shop_id' => $targetShopId,
                'user_id' => $userId
            ];
            $CategoryAttributes = $this->di->getObjectManager()->get(CategoryAttributes::class);
            $attributes = $CategoryAttributes->getJsonCategoryAttributes($payload, false);

            $schema = $attributes['data']['attributes']['offer'];
            $enums = $schema['Optional']['fulfillment_availability']['items']['properties']['fulfillment_channel_code']['enum'] ?? [];
            $searchString = "AMAZON";
            $index = -1;
            foreach ($enums as $key => $element) {
                if (strpos($element, $searchString) === 0) {
                    $index = $key;
                    break;
                }
            }
            if ($index != -1 && isset($enums[$index])) {
                $fulfillment_channel_code = $enums[$index];
            } else {
                return ['success' => false, 'message' => "You are not Authorised to sell FBA"];
                // $returnData['error'][] = 'AmazonError309';
            }
        }
        $result = $s3Client->putObject([
            'Bucket' => $bucketName,
            'Key' => $keyName,
            'SourceFile' => $uploadedFile->getTempName(), // Upload directly from temp file
        ]);

        $queuedTaskHelper = new QueuedTasks;
        $queuedTaskData = [
            'user_id' => $userId,
            'message' => 'Fulfilment conversion is in progress',
            'process_code' => 'amazon_fulfilment_type_change',
            'marketplace' =>  'amazon',
            'app_tag' => $this->di->getAppCode()->getAppTag()
        ];
        // create new queued task for look-up
        $queuedTaskId = $queuedTaskHelper->setQueuedTask($targetShopId, $queuedTaskData);

        $handlerData = [
            'type' => 'full_class',
            'class_name' => '\App\Amazon\Models\SourceModel',
            'method' => 'fulfillmentConversion',
            'queue_name' => 'amazon_fulfillment_conversion',
            'user_id' => $userId,
            'shop_id' => $sourceShopId,
            'data' => [
                's3Url' => $result['ObjectURL'],
                'total_rows' => $highestRow,
                'bucket' => $bucketName,
                'key' => $keyName,
                'chunkSize' => 200,
                'startRow' => 2,
                'user_id' => $userId,
                'target_shop_id' => $targetShopId,
                'curser' => 1,
                'queued_task_id' => $queuedTaskId,
                'app_tag' => $this->di->getAppCode()->getAppTag(),
                'fulfillment_type' => $rawBody['fulfillment_type'],
                'target_market_place' => $targetMarketplace,
                'fulfillment_channel_code' => $fulfillment_channel_code,
                'modified_count' => 0,

            ],
        ];
        $this->di->getMessageManager()->pushMessage($handlerData);
        return ['success' => true, 'message' => "import initiated"];
    }

    public function fulfillmentConversion($sqs_data)
    {

        $logFile = 'amazon/FBA-FBM/' . date('d-m-Y') . '.log';
        $chunkSize = $sqs_data['data']['chunkSize'];
        $highestRow = $sqs_data['data']['total_rows'];
        $config = include BP . '/app/etc/aws.php';
        $s3Client = new S3Client($config);
        $startRow = $sqs_data['data']['startRow'];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $refineProduct = $mongo->getCollection("refine_product");
        $productContainer = $mongo->getCollection("product_container");

        if ($startRow >= $highestRow) {
            $this->di->getLog()->logContent('Completed ' . $sqs_data['user_id'] . ':  ', 'info', $logFile);
            $notificationData = [
                'message' => "Fulfilment conversion completed successfully, " . $sqs_data['data']['modified_count'] . " " . ($sqs_data['data']['modified_count'] <= 1 ? "product" : "products") . " updated.",
                'severity' => 'success',
                'user_id' => $sqs_data['user_id'],
                'marketplace' => 'amazon',
                'appTag' => $sqs_data['data']['app_tag'],
                'process_code' => 'amazon_fulfilment_type_change'
            ];
            $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($sqs_data['data']['target_shop_id'], $notificationData);
            return true;
        }

        $key = $sqs_data['data']['key'];
        $dirPath = BP . DS . 'var' . DS  . $sqs_data['user_id'];  // The directory path
        $filePath = $dirPath . DS . basename($key);  // Full file path

        // Check if the directory exists, if not create it
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);  // Create the directory recursively with appropriate permissions
        }
        try {
            $s3Client->getObject([
                'Bucket' => $sqs_data['data']['bucket'],
                'Key' => $key,
                'SaveAs' => $filePath,
            ]);
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
        } catch (AwsException $e) {
            // echo "AWS error: " . $e->getMessage();
            die;
        } catch (Exception $e) {
            // echo "General error: " . $e->getMessage();
            die;
        }
        $updateRefine = [];
        $updateProductContainer = [];
        $data = [];
        $allASIN = [];
        $allSKU = [];
        $modifiedCount = $sqs_data['data']['modified_count'];
        for ($row = $startRow; $row < $startRow + $chunkSize; $row++) {
            $asin = $sheet->getCell('A' . $row)->getValue();
            $sku = $sheet->getCell('B' . $row)->getValue();
            if (!empty($asin) && !empty($sku)) {
                $data[] = [
                    "asin" => $asin,
                    "sku" => $sku,
                ];
                $allASIN[] = $asin;
                $allSKU[] = $sku;
                $this->prepareBulkUpdate(
                    $updateRefine,
                    $updateProductContainer,
                    $asin,
                    $sqs_data['data']['fulfillment_type'],
                    $sqs_data['user_id'],
                    $sqs_data['data']['target_shop_id'],
                    $sqs_data['shop_id'],
                    $sqs_data['data']['target_market_place'],
                    $sku
                );
            }
        }

        $return = $refineProduct->bulkWrite($updateRefine, ['w' => 1]);
        $productContainer->bulkWrite($updateProductContainer, ['w' => 1]);

        // Get the number of modified documents
        $modifiedCount += $return->getModifiedCount();

        // Calculate progress
        $processedRows = min($startRow + $chunkSize, $highestRow);
        $progress = ($processedRows / $highestRow) * 100;

        try {
            $queuedTaskHelper = new QueuedTasks;
            // create new queued task for look-up
            $queuedTaskHelper->updateFeedProgress($sqs_data['data']['queued_task_id'], round($progress, 2));;
            $handlerData = [
                'type' => 'full_class',
                'class_name' => '\App\Amazon\Models\SourceModel',
                'method' => 'fulfillmentConversion',
                'queue_name' => 'amazon_fulfillment_conversion',
                'user_id' => $sqs_data['user_id'],
                'shop_id' => $sqs_data['shop_id'],
                'data' => [
                    's3Url' => $sqs_data['data']['s3Url'],
                    'total_rows' => $highestRow,
                    'bucket' => $sqs_data['data']['bucket'],
                    'key' => $sqs_data['data']['key'],
                    'chunkSize' => 200,
                    'startRow' => $startRow + $chunkSize,
                    'user_id' => $sqs_data['user_id'],
                    'target_shop_id' => $sqs_data['data']['target_shop_id'],
                    'curser' => $sqs_data['data']['curser'] + 1,
                    'queued_task_id' => $sqs_data['data']['queued_task_id'],
                    'app_tag' => $sqs_data['data']['app_tag'],
                    'fulfillment_type' => $sqs_data['data']['fulfillment_type'],
                    'target_market_place' => $sqs_data['data']['target_market_place'],
                    'modified_count' => $modifiedCount
                ],
            ];
            $this->di->getLog()->logContent('queue data ' . $sqs_data['user_id'] . ':  ' . json_encode($handlerData), 'info', $logFile);
            $this->di->getMessageManager()->pushMessage($handlerData);
        } catch (Exception $e) {
            // echo $e->getMessage();
        } catch (AwsException $e) {
            // echo "AWS error: " . $e->getMessage();
        }
    }
    // Helper Functions

    private function isValidExcelFile($uploadedFile)
    {
        $validTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
        return in_array($uploadedFile->getType(), $validTypes);
    }

    private function prepareBulkUpdate(&$updateRefine, &$updateProductContainer, $asin, $fulfillmentType, $userId, $targetShopId, $sourceShopId, $targetMarketplace, $sku)
    {
        $queryForRefineProduct = [
            "user_id" => $userId,
            "target_shop_id" => $targetShopId,
            "source_shop_id" => $sourceShopId,
            "items.asin" => $asin
        ];

        $queryForProductContainer = [
            "user_id" => $userId,
            "shop_id" => $targetShopId,
            "target_marketplace" => $targetMarketplace,
            "asin" => $asin
        ];

        $updateValue = ['$set' => ["fulfillment_type" => $fulfillmentType]];

        $updateRefine[] = [
            'updateOne' => [
                $queryForRefineProduct,
                ['$set' => ["items.$.fulfillment_type" => $fulfillmentType]],
                ['multi' => false, 'upsert' => false]
            ]
        ];

        $updateProductContainer[] = [
            'updateOne' => [
                $queryForProductContainer,
                $updateValue,
                ['multi' => false, 'upsert' => false]
            ]
        ];
    }

    private function errorResponse($message)
    {
        return [
            "success" => false,
            "message" => $message
        ];
    }

    /**
     * Get CPU usage for current process as percentage (Linux only)
     * Uses ps command to get instant CPU usage without sleep
     * 
     * @return float CPU usage percentage (e.g., 2.5 means 2.5%)
     */
    private function getCpuUsage()
    {
        // Get process ID
        $pid = getmypid();
        if ($pid === false) {
            return 0;
        }
        
        // Linux: Use ps command to get instant CPU usage
        $cmd = "ps -p {$pid} -o %cpu --no-headers";
        $output = @shell_exec($cmd);
        if ($output !== null) {
            $cpuUsage = trim($output);
            if (is_numeric($cpuUsage)) {
                return round((float)$cpuUsage, 2);
            }
        }
        
        // Fallback: Try with different ps format
        $cmd = "ps -p {$pid} -o pcpu --no-headers";
        $output = @shell_exec($cmd);
        if ($output !== null) {
            $cpuUsage = trim($output);
            if (is_numeric($cpuUsage)) {
                return round((float)$cpuUsage, 2);
            }
        }
        
        return 0;
    }


    public function insertPoisonMessageData($message, $queueName, $processingTime) {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $poisonMessageContainer = $mongo->getCollectionForTable('sqs_poison_message_container');
            $now = new UTCDateTime((int)(microtime(true) * 1000));
            $body = json_decode($message['Body'],true);
            $messagePushedAt = new UTCDateTime((int)($message['Attributes']['SentTimestamp'] ?? 0));
            $nowDT = $now->toDateTime();
            $pushedAtDT = $messagePushedAt->toDateTime();
            $pushedProcessedDiff = $nowDT->getTimestamp() - $pushedAtDT->getTimestamp();
            $cpuUsage = $this->getCpuUsage();

            $filter = [
                'user_id' => $body['user_id'] ?? '',
                'message_id' => $message['MessageId'] ?? ''
            ];

            $setData = [
                'user_id' => $body['user_id'] ?? $this->di->getUser()->id ?? '',
                'username' => $body['username'] ?? $this->di->getUser()->username ?? '',
                'message_id' => $message['MessageId'] ?? '',
                'body' => json_encode($body) ?? '',
                'receive_count' => (int)($message['Attributes']['ApproximateReceiveCount'] ?? 0),
                'queue_name' => $queueName,
                'MD5_of_body' => $message['MD5OfBody'],
                'message_pushed_at' => $messagePushedAt,
                'messaged_pushed_diff' => $pushedProcessedDiff,
                'peak_memory_usage_bytes' => memory_get_peak_usage(true),
                'current_memory_usage_bytes' => memory_get_usage(true),
                'cpu_usage' => $cpuUsage,
                'updated_at' => date('c'),
                'updated_at_iso' => $now,
                'processing_time' => $processingTime
            ];
            $setOnInsertData = [
                'created_at' => date('c'),
                'created_at_iso' => $now
            ];

            $poisonMessageContainer->updateOne(
                $filter,
                [
                    '$set' => $setData,
                    '$setOnInsert' => $setOnInsertData
                ],
                ['upsert' => true]
            );
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception insertPoisonMessageData=> '. json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }
}
