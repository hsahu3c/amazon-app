<?php
/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shoifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */


namespace App\Shopifyhome\Models;

use App\Shopifyhome\Components\Mail\Seller;
use App\Shopifyhome\Components\Parser\Product;
use App\Shopifyhome\Components\Authenticate\User;
use Exception;
use App\Shopifyhome\Components\Product\UploadToShopify;
use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Product\Helper;
use App\Shopifyhome\Components\Product\Data;
use App\Shopifyhome\Components\Vendor\Vendor;
use App\Connector\Models\QueuedTasks;
use App\Shopifyhome\Components\Product\Route\Requestcontrol;
use App\Shopifyhome\Components\Attributes\AllAttributes;
use App\Shopifyhome\Components\Product\Hook;
use App\Shopifyhome\Components\Product\Quickimport\Import;
use App\Shopifyhome\Components\Product\Inventory\Utility;
use App\Core\Models\SourceModel as BaseSourceModel;
use App\Shopifyhome\Components\Product\Label as ProductLabel;

class SourceModel extends BaseSourceModel
{
    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public const SHOPIFY_QUEUE_INV_IMPORT_MSG = 'SHOPIFY_INVENTORY_SYNC';

    public const SHOPIFY_QUEUE_ORDER_IMPORT_MSG = 'SHOPIFY_ORDER_IMPORT';

    public const SHOPIFY_QUEUE_CUSTOMER_IMPORT_MSG = 'SHOPIFY_CUSTOMER_IMPORT';

    public const SHOPIFY_QUEUE_CART_IMPORT_MSG = 'SHOPIFY_ABANDONED_CART_IMPORT';

    public const Max_size=250;

    public const MAX_ITEM_LIMIT_PROCESSABLE = 10000;

    public function commenceHomeAuth($remotePostData)
    {
        $validateAndCreateUser = $this->di->getObjectManager()->get(User::class)->setupHomeUser($remotePostData);
        if ($validateAndCreateUser['success'] && !isset($validateAndCreateUser['redirect_to_dashboard'])) {
            $validateAndCreateUser['heading'] = 'Congratulations!!';
            $validateAndCreateUser['message'] = 'Welcome onboard. Your shopify store has been succesfully set up.';
            if (isset($validateAndCreateUser['mail'])) {
                // $this->di->getObjectManager()->get("\App\Shopifyhome\Components\Mail\Seller")->onboard($validateAndCreateUser['mail']);
                $this->di->getObjectManager()->get(Seller::class)->onboardNew($validateAndCreateUser['mail']);
            }

            try {
                $this->registerUninstallWebhook();
            } catch (Exception) {
                $this->di->getLog()->logContent('error registering uninstall webhook for user id : ' . $this->di->getUser()->id, 'info', 'uninstallWebhook.log');
            }

            $validateAndCreateUser['redirect_to_dashboard'] = 1;

            return $validateAndCreateUser;
        }
        return $validateAndCreateUser;
    }

    public function prepareConnectedShopData($shop, $data = [])
    {
        if (!empty($shop)) {
            $responseData = ["marketplace" => $shop['marketplace'], 'id' => $shop['_id'], 'name' => $shop['name'], 'country' => $shop['country'], 'shop_url' => $shop['domain'], 'warehouses' => $shop['warehouses'], 'shop_details' => $shop];
            if (!empty($shop['targets'])) {
                $responseData['target'] = $shop['targets'];
            }
        }

        return $responseData ?? [];
    }

    public function prepareSelectedProductsQuery($selectedProducts)
    {
        $productQuery = '';
        $end = '';
        foreach ($selectedProducts as $productId) {
            $productQuery .= $end . '(source_product_id == ' . $productId . ')';
            $end = ' || ';
        }

        if ($productQuery != '') {
            return $productQuery;
        }

        return false;
    }

    public function selectProductAndUpload($marketplaceDetails, $userId = false)
    {
        if (!$userId) {
                    $userId = $this->di->getUser()->id;
                }

//                $userId = "604c6f2aa13bdb06041aaca5";
                if(!$userId) return [ 'success' => false, 'message' => 'Invalid User. Please check your login credentials' ];

                $source = $marketplaceDetails['source'];
//                $sourceShopId = $marketplaceDetails['source_shop_id'];
//                $targetShopId = $marketplaceDetails['target_shop_id'];
        $profileId = $marketplaceDetails['selected_profile'] ?? null; //not come
                $productsSelected = $marketplaceDetails['selected_products'];
        $productQuery = $this->prepareSelectedProductsQuery($productsSelected);
        if (!$productQuery) {
                    return ['success' => false, 'message' => 'No products selected for upload. Kindly select products then upload them to Shopify.'];
                }

                $prodCount = $this->di->getObjectManager()->get(UploadToShopify::class)->getProductsCountByQuery($userId, $profileId, $source,$productQuery);

//        $this->di->getLog()->logContent(' Total Product Count and Uploaded Product Count == '.print_r($prodCount,true),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'product_upload.log');

        $finalCount = 0;
                if ($prodCount) {
                    $finalCount = $prodCount['product_count'] + $prodCount['product_uploaded_count'];
                } if ($finalCount < 1) {
                    return ['success' => false, 'message' => 'No products found for upload. Kindly import products to app then upload them to Shopify.'];
                }

                $productCount = $prodCount['product_count'];
                $productUploadedCount = $prodCount['product_uploaded_count'];
                $sync_status='enable';
               $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
               $userData = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getDataByUserID($userId, 'shopify');
               if(isset($userData['message'])) return $userData;

               $remoteShopId = $userData->remote_shop_id;
//        print_r($remoteShopId);die("tes033");
        $fileName = 'failed_product_' . $userId . '_' . time();
                $filePath = BP . DS . 'var' . DS . 'failed-products' . DS . $fileName . '.php';
                $handlerData = $this->di ->getObjectManager()->get(Helper::class)->shapeSqsDataForUploadProducts($userId, $remoteShopId,$prodCount['product_count'],$source,$profileId,$remoteShopId,$remoteShopId,$productUploadedCount,$sync_status,$filePath,$fileName,$productQuery);
//        print_r($handlerData);die("select");

                if ($this->di->getConfig()->enable_rabbitmq_internal) {
                    $msg = "Uploading";
                    $msgForSync = "Product Sync Process from Shopify";
//                    $shopifyConfigData = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
//                    $sellerData = $shopifyConfigData::query()->where("user_id = $userId")->andWhere("shop_id = $targetShopId")->andWhere("message LIKE '%$msg%'")->execute()->toArray();
//                    $productSyncData = $shopifyConfigData::query()->where("user_id = $userId")->andWhere("message LIKE '%$msgForSync%'")->execute()->toArray();
//                    if (count($sellerData) || count($productSyncData)) {
//                        return ['success' => false, 'message' => 'Currently either product syncing or upload process is already under progress. Please check notification for updates.'];
//                    }
                    $maxLimit = 1;
                    if ($maxLimit > $productCount) {
                        $maxLimit = $productCount + $productUploadedCount;
                    } else {
                        $maxLimit = $maxLimit + $productUploadedCount;
                    }

//                    $this->di->getLog()->logContent(' MAX LIMIT == '.\GuzzleHttp\json_encode($maxLimit),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'product_upload.log');
                    if ($productCount == 0 &&
                        $sync_status == 'disable') {
                        return ['success' => false, 'message' => 'product syncing is disabled. You can change it in the Settings section.'];
                    }

                    if ($maxLimit > 0) {
//                        $this->di->getLog()->logContent(' initProductUploadQueue DATA  == '.print_r($handlerData['data'],true),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'DATA.log');
                        $response = $this->di->getObjectManager()->get(UploadToShopify::class)->initProductUploadQueue($handlerData, $failed_product = false);
                        $productMessage = 'products';
                        if ($productCount == 1) {
                            $productMessage = 'product';
                        }

                        if (isset($response['success']) && ($response['success'] == false)) {
                            return ['success' => false, 'message' => $response['message']];
                        }

                        return ['success' => true, 'code' => 'product_upload_started', 'message' => 'product upload process started from app to shopify.'];
                    }

                    return ['success' => false, 'message' => 'You have exhausted your limit of uploading products on Shopify. To upload more products kindly buy upload credits to upload more products.', 'code' => 'limit_exhausted'];
                }
                return ['success' => false, 'message' => 'RMQ_DISABLE : Internal Server Error . We are working hard and will be up shortly'];
    }


    public function initiateUpload($marketplaceDetails, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

//        $userId = "604c6f2aa13bdb06041aaca5";
        $source = $marketplaceDetails['source'];
        $sourceShopId = $marketplaceDetails['source_shop_id'];
        $targetShopId = $marketplaceDetails['target_shop_id'];
        $profileId = $marketplaceDetails['selected_profile'] ?? null; //not come
        $prodCount = $this->di->getObjectManager()->get(Data::class)->getProductsCountByQuery($userId, $profileId, $source);
//        $this->di->getLog()->logContent(' Total Product Count and Uploaded Product Count == '.print_r($prodCount,true),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'product_upload.log');
        $finalCount = 0;
        if ($prodCount) {
            $finalCount = $prodCount['product_count'] + $prodCount['product_uploaded_count'];
        }

        if ($finalCount < 1) {
            return ['success' => false, 'message' => 'No products found for upload. Kindly import products to app then upload them to Shopify.'];
        }

        $productCount = $prodCount['product_count'];
        $productUploadedCount = $prodCount['product_uploaded_count'];
        $sync_status='enable';
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $userData = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getDataByUserID($userId, $target);
        if(isset($userData['message'])) return $userData;

        $remoteShopId = $userData->remote_shop_id;
        $fileName = 'failed_product_' . $userId . '_' . time();
        $filePath = BP . DS . 'var' . DS . 'failed-products' . DS . $fileName . '.php';
        $handlerData = $this->di ->getObjectManager()->get(Helper::class)->shapeSqsDataForUploadProducts($userId, $remoteShopId,$prodCount['product_count'],$source,$profileId,$sourceShopId,$targetShopId,$productUploadedCount,$sync_status,$filePath,$fileName);
//        print_r($handlerData);die("test");
        if ($this->di->getConfig()->enable_rabbitmq_internal) {
            $msg = "Uploading";
            $msgForSync = "Product Sync Process from Shopify";
            $shopifyConfigData = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
//            $sellerData = $shopifyConfigData::query()->where("user_id = $userId")->andWhere("shop_id = $targetShopId")->andWhere("message LIKE '%$msg%'")->execute()->toArray();
//            $productSyncData = $shopifyConfigData::query()->where("user_id = $userId")->andWhere("message LIKE '%$msgForSync%'")->execute()->toArray();
//            if (count($sellerData) || count($productSyncData)) {
//                return ['success' => false, 'message' => 'Currently either product syncing or upload process is already under progress. Please check notification for updates.'];
//            }
            $maxLimit = 10;
            if ($maxLimit > $productCount) {
                $maxLimit = $productCount + $productUploadedCount;
            } else {
                $maxLimit = $maxLimit + $productUploadedCount;
            }

//            $this->di->getLog()->logContent(' MAX LIMIT == '.\GuzzleHttp\json_encode($maxLimit),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'product_upload.log');
            if ($productCount == 0 &&
                $sync_status == 'disable') {
                return ['success' => false, 'message' => 'product syncing is disabled. You can change it in the Settings section.'];
            }

            if ($maxLimit > 0) {
//                $this->di->getLog()->logContent(' initProductUploadQueue DATA  == '.print_r($handlerData['data'],true),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'DATA.log');
                $response = $this->di->getObjectManager()->get(UploadToShopify::class)->initProductUploadQueue($handlerData, $failed_product = false);
                $productMessage = 'products';
                if ($productCount == 1) {
                    $productMessage = 'product';
                }

                if (isset($response['success']) && ($response['success'] == false)) {
                    return ['success' => false, 'message' => $response['message']];
                }

                return ['success' => true, 'code' => 'product_upload_started', 'message' => 'product upload process started from app to shopify.'];
            }

            return ['success' => false, 'message' => 'You have exhausted your limit of uploading products on Shopify. To upload more products kindly buy upload credits to upload more products.', 'code' => 'limit_exhausted'];
        }
        return ['success' => false, 'message' => 'RMQ_DISABLE : Internal Server Error . We are working hard and will be up shortly'];
    }

    public function updateProduct($updateData, $userId = false)
    {
        return $this->di->getObjectManager()->get(Data::class)->handleProductData($updateData, $userId);
    }

    public function getsellers($marketplaceDetails = [], $userId = null){
        return $this->di->getObjectManager()->get(Vendor::class)->getvendors($marketplaceDetails, $userId);
    }

    public function getProductHelper($salesChannel=false){
        // if($salesChannel) {
        //     return $this->di->getObjectManager()->get("\App\Shopifyhome\Components\Product\SalesChannel\Helper");
        // } else {
            return $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Vistar\Helper::class);
        // }
    }


    public function initiateInvImport($marketplaceDetails = [], $userId = null)
    {
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $userId ??= $this->di->getUser()->id;
        if (!$userId) {
            return [
                'success' => false,
                'message' => 'Invalid User. Please check your login credentials'
            ];
        }

        $remoteShopId = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getDataByUserID($userId, $target)->remote_shop_id;
        /*
         * todo : add shop_id check in phase-2 development
         * */
        $sMsg = SourceModel::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG;
        $iMsg = SourceModel::SHOPIFY_QUEUE_INV_IMPORT_MSG;
        $shopifyConfigData = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
        $sellerData = $shopifyConfigData::query()->where("user_id = {$userId}")->andWhere("message LIKE '%{$sMsg}%' OR message LIKE '%{$iMsg}%'")->execute()->toArray();
        if (!empty($sellerData)) {
            return ['errorFlag' => true, 'msg' => 'Product Import process OR Location Sync is already under progress. Please check notification for updates.'];
        }

        try {
            $queuedTask = new QueuedTasks;
            $queuedTask->set([
                'user_id' => (int)$userId,
                'message' => SourceModel::SHOPIFY_QUEUE_INV_IMPORT_MSG . ' : Please Wait while locations from Shopify is being synced',
                'progress' => 0.00,
                'shop_id' => (int)$userId
            ]);
            $status = $queuedTask->save();
        } catch (Exception $e) {
            // echo $e->getMessage();
        }

        $handlerData = [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'method' => 'fetchProductInventoryLocationWise',
            'queue_name' => 'shopify_product_inv_import',
            'own_weight' => 100,
            'user_id' => $userId,
            'data' => [
                'user_id' => $userId,
                'individual_weight' => 0,
                'pageSize' => 40,
                'feed_id' => ($status ? $queuedTask->id : false),
                'remote_shop_id' => $remoteShopId,
                'cursor' => 0
            ]
        ];
        $this->di->getLog()->logContent('','info','shopify'.DS.$this->di->getUser()->id.DS.'inventory'.DS.date("Y-m-d").DS.'inventory.log');
        $this->di->getLog()->logContent('************************************************************************************************','info','shopify'.DS.$this->di->getUser()->id.DS.'inventory'.DS.date("Y-m-d").DS.'inventory.log');

        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        //$this->di->getObjectManager()->get("App\Shopifyhome\Components\Product\Inventory\Data")->deleteLocations();
        return [
            'success' => true,
            'message' => 'Shopify Product Inventory Import Initiated',
            'queue_sr_no' => $rmqHelper->createQueue($handlerData['queue_name'], $handlerData)
        ];
    }

    public function saveDetails($data, $userId = null){
        $userId ??= $this->di->getUser()->id;
        if (isset($data['username']) && isset($data['email']) && isset($data['password']) && isset($data['mobno'])){
            $userDetails = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
            $collection = $userDetails->getCollection();
            $details=[
                'username'=>$data['username'],
                'email'=>$data['email'],
                'marketplace'=>'cedcommerce'
            ];
            $details['phone']=$data['mobno'];
            $collection->updateOne(
                ['user_id'=>$userId],
                ['$set'=>['shops'=>[$details]]]);
            return ['success'=>true];
        }
    }

    public function getAttributes($category = '')
    {
        $attributes = [
            [
                'code' => 'title',
                'required' => 1,
                'title' => 'Title',
                'system' => 1,
                'visible' => 0
            ],
            [
                'code' => 'sku',
                'required' => 1,
                'title' => 'Sku',
                'system' => 1,
                'visible' => 0
            ],
            [
                'code' => 'price',
                'required' => 1,
                'title' => 'Price',
                'system' => 1,
                'visible' => 0
            ],
            [
                'code' => 'short_description',
                'required' => 1,
                'title' => 'Description',
                'system' => 1,
                'visible' => 0
            ],
            [
                'code' => 'product_type',
                'required' => 1,
                'title' => 'Product Type',
                'system' => 1,
                'visible' => 0
            ],
            [
                'code' => 'vendor',
                'required' => 1,
                'title' => 'Vendor',
                'system' => 1,
                'visible' => 0
            ],
            [
                'code' => 'tags',
                'required' => 1,
                'title' => 'Tag',
                'system' => 1,
                'visible' => 0
            ],
            [
                'code' => 'quantity',
                'required' => 1,
                'title' => 'Quantity',
                'system' => 1,
                'visible' => 0
            ],
            [
                'code' => 'source_variant_id',
                'required' => 1,
                'title' => 'Variant Id',
                'system' => 1,
               'visible' => 0
            ],
            [
                'code' => 'source_product_id',
                'required' => 1,
                'title' => 'Parent Id',
                'system' => 1,
                'visible' => 0
            ]
        ];
         $collections = $this->getCollectionsOptions();
         if ($collections &&
             count($collections)) {
             $attributes[] = [
                 'code' => 'collection.collection_id',
                 'required' => 1,
                 'title' => 'Collections',
                 'system' => 1,
                 'visible' => 0,
                 'options' => $collections
             ];
         }

        return $attributes;
    }

    public function getFilterAttributes($rawBody = [])
    {
        if ($rawBody['source']['marketplace']) {
            $marketplace = $rawBody['source']['marketplace'];
            $attributes = [];
            switch ($marketplace) {
                case 'shopify':
                    $productTypeoptions = $this->di->getObjectManager()->get(\App\Shopifyhome\Models\SourceModel::class)->getProductTypeOptions($rawBody);
                    $collections = $this->di->getObjectManager()->get(\App\Shopifyhome\Models\SourceModel::class)->getCollectionsOptions($rawBody);
                    $data = $this->di->getObjectManager()->get(\App\Shopifyhome\Models\SourceModel::class)->getVariantAttributes($rawBody);
                    $vendor=$this->di->getObjectManager()->get(\App\Shopifyhome\Models\SourceModel::class)->getVendor($rawBody);
                    $attributes = [
                        [
                            'code' => 'title',
                            'required' => 1,
                            'title' => 'Title',
                            'system' => 1,
                            'visible' => 0
                        ],
                        [
                            'code' => 'tags',
                            'required' => 1,
                            'title' => 'Tags',
                            'system' => 1,
                            'visible' => 0
                        ],
                        [
                            'code' => 'type',
                            'required' => 1,
                            'title' => 'Type',
                            'system' => 1,
                            'visible' => 0,
                            'options' => [
                                ['label' => 'Simple', 'value' => 'Simple'],
                                ['label' => 'Variation', 'value' => 'Variation'],
                            ]
                        ],
                        [
                            'code' => 'barcode_exists',
                            'required' => 1,
                            'title' => 'Barcode Existes',
                            'system' => 1,
                            'visible' => 0,
                            'options' => [
                                ['label' => 'True', 'value' => 'true'],
                                ['label' => 'false', 'value' => 'false'],
                            ]
                        ],
                        // [
                        //     'code' => 'brand',
                        //     'required' => 1,
                        //     'title' => 'Vendor',
                        //     'system' => 1,
                        //     'visible' => 0
                        // ],
                        [
                            'code' => 'price',
                            'required' => 1,
                            'title' => 'Price',
                            'system' => 1,
                            'visible' => 0
                        ],
                        [
                            'code' => 'sku',
                            'required' => 1,
                            'title' => 'Sku',
                            'system' => 1,
                            'visible' => 0
                        ],
                        [
                            'code' => 'quantity',
                            'required' => 1,
                            'title' => 'Quantity',
                            'system' => 1,
                            'visible' => 0
                        ],
                        [
                            'code' => 'status',
                            'required' => 1,
                            'title' => 'Product status',
                            'system' => 1,
                            'visible' => 0,
                            'options' => [
                                ['label' => 'Active', 'value' => 'Active'],
                                ['label' => 'Inactive', 'value' => 'Inactive'],
                                ['label' => 'Incomplete', 'value' => 'Incomplete'],
                                // ['label' => 'Supressed', 'value' => 'Supressed'],
                                // ['label' => 'Disabled', 'value' => 'Not_listed'],
                                ['label' => 'Not Listed', 'value' => 'Not_listed'],
                                ['label' => 'Uploaded', 'value' => 'Uploaded'],
                                ['label' => 'Available for Offer', 'value' => 'Available for Offer'],
                                // ['label' => 'Not Listed', 'value' => 'Not Listed']
                            ]
                        ],
                    ];
                    $productLabelAssignment = $this->di->getConfig()->get('product_label_assignment') ? true : false;
                    if($productLabelAssignment) {
                        $attributes[] = [
                            'code' => 'label',
                            'required' => 1,
                            'title' => 'Label',
                            'system' => 1,
                            'visible' => 0,
                            'options' => $this->getLabelValues()
                        ];
                    }
                    $variantAttributesArr = $data;
                    if (
                        $variantAttributesArr &&
                        count($variantAttributesArr)>0
                    ) {
                        $attributes[] = [
                            'code' => 'variant_attributes',
                            'required' => 1,
                            'title' => 'Variant attributes',
                            'system' => 1,
                            'visible' => 0,
                            'options' => $variantAttributesArr,
                        ];
                    }

                    if (
                        $collections &&
                        count($collections)>0
                    ) {
                        $attributes[] = [
                            'code' => 'collection.collection_id',
                            // 'code' => 'collections',
                            'required' => 1,
                            'title' => 'Collections',
                            'system' => 1,
                            'visible' => 0,
                            'options' => $collections
                        ];
                    }

                    if ($productTypeoptions && count($productTypeoptions)>0) {
                        $attributes[] = [
                            'code' => 'product_type',
                            'required' => 1,
                            'title' => 'Product Type',
                            'system' => 1,
                            'visible' => 0,
                            'options' => $productTypeoptions
                        ];
                    } else {
                        $attributes[] = [
                            'code' => 'product_type',
                            'required' => 1,
                            'title' => 'Product Type',
                            'system' => 1,
                            'visible' => 0
                        ];
                    }

                    if ($vendor && count($vendor)>0) {
                        $attributes[] = [
                            'code' => 'brand',
                            'required' => 1,
                            'title' => 'Vendor',
                            'system' => 1,
                            'visible' => 0,
                            'options' => $vendor
                        ];
                    } else {
                        $attributes[] = [
                            'code' => 'brand',
                            'required' => 1,
                            'title' => 'Vendor',
                            'system' => 1,
                            'visible' => 0
                        ];
                    }

                    break;
            }

            return ['data' => $attributes];
        }
    }


    public function getLabelValues() {
        $response = $this->di->getObjectManager()->get(ProductLabel::class)->getLabels();
        if(!empty($response['data'])) {
            foreach($response['data'] as $label) {
                $preparedData[] = [
                    'label'=> $label['name'],
                    'value' => $label['id']
                ];
            }
            return $preparedData;
        }
        return [];
    }

    public function getProductTypeOptions($data){
        $response = $this->di->getObjectManager()->get(AllAttributes::class)->getProductTypeOptions($data);
        return $response;
    }

    public function getVendor($data){
        $response = $this->di->getObjectManager()->get(AllAttributes::class)->getVendor($data);
        return $response;
    }

    public function getProductAttributes($data)
    {
        $response = $this->di->getObjectManager()->get(AllAttributes::class)->getProductAttributes($data);
        return ['success' => true, 'data' => $response];
    }

    public function getVariantAttributes($data)
    {
        $response = $this->di->getObjectManager()->get(AllAttributes::class)->getVariantAttributes($data);
        return $response;
    }

    public function getCollectionsOptions($data) {
        $response = $this->di->getObjectManager()->get(AllAttributes::class)->getCollectionsOptions($data);
        return $response;
    }

    public function getAttributeDetails()
    {
        return [];
    }

    public function initWebhook($data)
    {
        if($this->di->getUser()->id == "61a884672ff67078b248c15c"){
            $this->di->getLog()->logContent('PROCESS 000002 | SourceModel | fulfillments Update | Function Name - initWebhook | Data = \n'.print_r($data, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'fulfillments_update'.DS.date("Y-m-d").DS.'fulfillments_update_before_init.log');
        }


        $preventProductSyncingMannuallyForUsers = ["61182e715bc78220b814e7e0"];
        $actionToPerform = $data['action'];
        $getConnectedChannels = $this->getSellerConnectedChannels($data);
        switch ($actionToPerform) {
            case 'product_update' :
                //send request to Shopify Base
                $updatedDatabase = $this->di->getObjectManager()->get(Hook::class)->updateProduct($data);

                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Product\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToUpdateProduct')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToUpdateProduct($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToUpdateProduct Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'product_create' :
                //send request to Shopify Base
                $updatedDatabase = $this->di->getObjectManager()->get(Hook::class)->createProduct($data);

                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Product\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToCreateProduct')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToCreateProduct($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToCreateProduct Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'product_delete' :
                //send request to Shopify Base
                $this->di->getObjectManager()->get(Hook::class)->deleteProduct($data);

                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Product\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToDeleteProduct')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToDeleteProduct($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToDeleteProduct Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'app_delete' :

                $this->di->getLog()->logContent('user_id : ' . $this->di->getUser()->id .'Shopify : ' .json_encode($data),'info','uninstalluser.log');

                $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Core\Hook::class)->TemporarlyUninstall($this->di->getUser()->id);
                break;

            case 'shop_eraser' :

                $this->di->getLog()->logContent('user_id : ' . $this->di->getUser()->id .'Shopify : ' .json_encode($data),'info','uninstalluser.log');

                $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Core\Hook::class)->ShopEraserMarking($data);
                break;

            case 'inventory_update':

                //send request to Shopify Base
                $remoteResponse = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Inventory\Hook::class)->updateProductInventory($data,$data['user_id']);

                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Product\Inventory\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToupdateProductInventory')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToupdateProductInventory($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToupdateProductInventory Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'locations_delete' :
                $this->di->getLog()->logContent('PROCESS 000000 | SourceModel | Locations Delete | Function Name - initWebhook | Data = \n'.print_r($data, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'locations'.DS.date("Y-m-d").DS.'webhook_locations.log');

                $deleteLocation = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Shop\Hook::class)->deleteLocation($data);

                 //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Location\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'deleteQueueLocationWebhook')){
                                $deleteQueueLocationWebhook = $this->di->getObjectManager()->get($class)->deleteQueueLocationWebhook($data);
                            }else{
                                $this->di->getLog()->logContent('deleteQueueLocationWebhook Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'locations_update' :
                $this->di->getLog()->logContent('PROCESS 000002 | SourceModel | Locations Update | Function Name - initWebhook | Data = \n'.print_r($data, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'locations'.DS.date("Y-m-d").DS.'webhook_locations.log');
                $locationUpdate = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Shop\Hook::class)->addAndUpdateLocation($data);

                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Location\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'updateQueueLocationWebhook')){
                                $deleteQueueLocationWebhook = $this->di->getObjectManager()->get($class)->updateQueueLocationWebhook($data);
                            }else{
                                $this->di->getLog()->logContent('updateQueueLocationWebhook Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;


            case 'locations_create' :
                $this->di->getLog()->logContent('PROCESS 000003 | SourceModel | Locations Create | Function Name - initWebhook | Data = \n'.print_r($data, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'locations'.DS.date("Y-m-d").DS.'webhook_locations.log');
                $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Shop\Hook::class)->addAndUpdateLocation($data);

                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Location\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueLocationWebhook')){
                                $deleteQueueLocationWebhook = $this->di->getObjectManager()->get($class)->createQueueLocationWebhook($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueLocationWebhook Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;
            case 'order_create' :
                // $response = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Hook')->createOrder($data);
                // if ($response === 2) return 2;
                // else return true;
                break;

            case 'order_fulfilled' :
                //send request to Shopify Base
                // $response = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Hook')->updateOrder($data,$data['user_id']);
                //send request to Connect Channels

                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Order\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToFulfillOrder')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToFulfillOrder($data);
                                $this->di->getLog()->logContent('createQueueToFulfillOrder response :. '.$updatedDatabase, 'info', 'MethodNotFound.log');
                            }else{
                                $this->di->getLog()->logContent('createQueueToFulfillOrder Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }
                    }
                }

                break;

            case 'orders_create' :
                //send request to Shopify Base
                // $response = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Hook')->updateOrder($data);
                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Order\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToCreateOrder')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToCreateOrder($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToCreateOrder Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'orders_updated' :
                //send request to Shopify Base
                // $response = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Hook')->updateOrder($data,$data['user_id']);
                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    if(isset($data['data'],$data['data']['app_id']))
                    {
                        if($data['data']['app_id'] !='5045527')
                        {
                            break;
                        }

                    }

                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Order\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToFulfillOrder')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToFulfillOrder($data);
                                $this->di->getLog()->logContent('createQueueToFulfillOrder response :. '.$updatedDatabase, 'info', 'MethodNotFound.log');
                            }else{
                                $this->di->getLog()->logContent('createQueueToFulfillOrder Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }
                    }
                }

                break;

            case 'order_cancel' :
                //send request to Shopify Base
                // $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Hook')->updateOrder($data);

                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Order\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToCancelOrder')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToCancelOrder($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToCancelOrder Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'order_delete' :
                //send request to Shopify Base
                // $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Hook')->updateOrder($data);

                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Order\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToDeleteOrder')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToDeleteOrder($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToDeleteOrder Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'order_partial_fulfilled' :
                // $response = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Hook')->updateOrder($data,$data['user_id']);

                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Order\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToFulfillOrder')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToFulfillOrder($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToFulfillOrder Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'fulfillments_update' :
                // $response = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Hook')->updateOrder($data,$data['user_id']);

                //send request to Connect Channels
             /*   $this->di->getLog()->logContent('createQueueToFulfillOrder. '.json_encode($data), 'info', 'fulfillments_update_init.log');*/


              $this->di->getLog()->logContent('PROCESS 000002 | SourceModel | fulfillments Update | Function Name - initWebhook | Data = \n'.print_r($data, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'fulfillments_update'.DS.date("Y-m-d").DS.'fulfillments_update_init.log');



                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Order\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToFulfillOrder')){
                                if(isset($data['data'],$data['data']['tracking_number']))
                                {

                                    $prepareData = [];
                                    $prepareData['data'] = [];
                                    $prepareData['data']['name'] = '';
                                    $prepareData['data']['id'] = $data['data']['order_id'];
                                    if(isset($data['data'][0]))
                                    {
                                        $prepareData['data']['fulfillments'] = $data['data'];
                                    } else {
                                        $prepareData['data']['fulfillments'][0] = $data['data'];
                                    }


                                    $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToFulfillOrder($prepareData);

                                    $this->di->getLog()->logContent('createQueueToFulfillOrder. '.json_encode($data), 'info', 'fulfillments_update.log');
                                }

                            }else{
                                $this->di->getLog()->logContent('createQueueToFulfillOrder Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'fulfillments_create' :
                // $response = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Order\Hook')->updateOrder($data,$data['user_id']);

                //send request to Connect Channels
              //  $this->di->getLog()->logContent('createQueueToFulfillOrder. '.json_encode($data), 'info', 'fulfillments_create_init.log');

               // $this->di->getLog()->logContent('PROCESS 000002 | SourceModel | fulfillments Create | Function Name - initWebhook | Data = \n'.print_r($data, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'fulfillments_create'.DS.date("Y-m-d").DS.'fulfillments_create_init.log');


                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Order\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToFulfillOrder')){
                                if(isset($data['data'],$data['data']['tracking_number']))
                                {
                                    $prepareData = [];
                                    $prepareData['data'] = [];
                                    $prepareData['data']['name'] = '';
                                    $prepareData['data']['id'] = $data['data']['order_id'];
                                    if(isset($data['data'][0]))
                                    {
                                        $prepareData['data']['fulfillments'] = $data['data'];
                                    } else {
                                        $prepareData['data']['fulfillments'][0] = $data['data'];
                                    }


                                    $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToFulfillOrder($prepareData);

                                    $this->di->getLog()->logContent('createQueueToFulfillOrder. '.json_encode($data), 'info', 'fulfillments_update.log');
                                } else {
                                    $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToFulfillOrder($data);
                                }

                            }else{
                                $this->di->getLog()->logContent('createQueueToFulfillOrder Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'refund_create':
                //send request to Shopify Base
                // $response = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Refund\Hook')->createRefund($data);

                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Refund\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToCreateRefund')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToCreateRefund($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToCreateRefund Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'checkouts_create':
                //send request to Shopify Base
                $response = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Order\Hook::class)->updateOrderFromCheckout($data);
                //send request to Connect Channels
                if($response && !empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Order\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToUpdateOrderFromCheckout')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToUpdateOrderFromCheckout($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToUpdateOrderFromCheckout Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'checkouts_update':
                $response = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Order\Hook::class)->updateOrder($data);

                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {

                        $class = '\App\\'.$moduleHome.'\Components\Order\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToUpdateOrderFromCheckout')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToUpdateOrderFromCheckout($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToUpdateOrderFromCheckout Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'customers_create':
                //send request to Shoppify Base
                $response = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Customer\Hook::class)->updateCustomer($data);

                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\'.$moduleHome.'\Components\Customer\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToUpdateCustomer')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToUpdateCustomer($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToUpdateCustomer Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                return true;
                break;

            case 'customers_update':
                //send request to Shoppify Base
                $response = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Customer\Hook::class)->updateCustomer($data);
                //send request to Connect Channels
                if(!empty($getConnectedChannels)){
                    foreach ($getConnectedChannels as $moduleHome => $channel) {
                        $class = '\App\\'.$moduleHome.'\Components\Customer\Hook';
                        if(class_exists($class)){
                            if(method_exists($class, 'createQueueToUpdateCustomer')){
                                $updatedDatabase = $this->di->getObjectManager()->get($class)->createQueueToUpdateCustomer($data);
                            }else{
                                $this->di->getLog()->logContent('createQueueToUpdateCustomer Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                            }
                        }else{
                            $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                        }

                    }
                }

                break;

            case 'product_listings_create':
                if(in_array($this->di->getUser()->id, $preventProductSyncingMannuallyForUsers)){
                    return true;
                }

                $this->di->getLog()->logContent('user_id : ' . $this->di->getUser()->id .'Shopify : ' .json_encode($data),'info','product_listings_create.log');
                $processListing = $this->processListing($data, 'product_listing_create');
                if($processListing['success']){
                    $function = $processListing['function'];
                    $this->$function($data);
                    $class = '\App\\'.ucfirst((string) $data['marketplace']).'\Components\Product\Hook';
                    if(class_exists($class)){
                        if(method_exists($class, 'createProductWebhook')){
                            $createProduct = $this->di->getObjectManager()->get($class)->createProductWebhook($data);
                        }else{
                            $this->di->getLog()->logContent('createlistingQueue Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                        }
                    }else{
                        $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                    }
                }

                return true;
                break;

            case 'product_listings_update':
                if(in_array($this->di->getUser()->id, $preventProductSyncingMannuallyForUsers)){
                    return true;
                }

                $processListing = $this->processListing($data);
               // print_r($processListing);die;

                if($processListing['success']){
                    $function = $processListing['function'];
                    $this->$function($data);
                    $data['saved_data'] = $processListing['data'];

                    $class = '\App\\'.ucfirst((string) $data['marketplace']).'\Components\Product\Hook';
                    if(class_exists($class)){
                        if(method_exists($class, 'updateProductWebhook')){
                            $createProduct = $this->di->getObjectManager()->get($class)->updateProductWebhook($data);
                        }else{
                            $this->di->getLog()->logContent('updatelistingQueue Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                        }
                    }else{
                        $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                    }
                }

                return true;
                break;

            case 'product_listings_remove':
                $this->removeListingData($data);
                $class = '\App\\'.ucfirst((string) $data['marketplace']).'\Components\Product\Hook';
                if(class_exists($class)){
                    if(method_exists($class, 'removeProductWebhook')){
                        $createProduct = $this->di->getObjectManager()->get($class)->removeProductWebhook($data);
                    }else{
                        $this->di->getLog()->logContent('removelistingQueue Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                    }
                }else{
                    $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                }

                return true;
                break;
            case 'shop_update':
                $this->di->getLog()->logContent('PROCESS 000003 | SourceModel | shop update | Function Name - initWebhook | Data = \n'.print_r($data, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'locations'.DS.date("Y-m-d").DS.'webhook_locations.log');

                $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Shop\Hook::class)->shopUpdate($data);

                $class = '\App\\'.ucfirst((string) $data['marketplace']).'\Components\Product\Hook';
                if(class_exists($class)){
                    if(method_exists($class, 'shopUpdateWebhook')){
                        $createProduct = $this->di->getObjectManager()->get($class)->shopUpdateWebhook($data);
                    }else{
                        $this->di->getLog()->logContent('removelistingQueue Class found, method not found. '.json_encode($data), 'info', 'MethodNotFound.log');
                    }
                }else{
                    $this->di->getLog()->logContent($class.' Class not found. '.json_encode($data), 'info', 'ClassNotFound.log');
                }

                return true;
                break;
        }

        return true;
    }

    public function removeListingData($data)
    {


        if(isset($data['data'],$data['data']['product_listing'],$data['data']['product_listing']['product_id']))
        {
            $productId = $data['data']['product_listing']['product_id'];
            $userId = $data['user_id'];
            $appCode = $data['app_code'];

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $mongo->setSource("product_container");
            $mongoCollection = $mongo->getPhpCollection();
            $mongoCollection->deleteMany(['container_id' => (string)$productId]);

/*
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

            $productsData = $mongoCollection->find(['container_id' => (string) $productId],$options)->toArray();

            if(!empty($productsData)){
                $appCodes = [];
                foreach ($productsData as $key => $variantProduct) {
                    if(isset($variantProduct['app_codes'])){
                        $appCodes = $variantProduct['app_codes'];
                        if(in_array($appCode, $appCodes)){
                            if (($key = array_search($appCode, $appCodes)) !== false) {
                                unset($appCodes[$key]);
                            }
                            $appCodes = array_values($appCodes);
                        }
                    }
                }

                $query = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Helper')->getAndQuery(['container_id'=> (string) $productId], $userId);
                $mongoCollection->updateMany($query,['$set' => ['app_codes' => $appCodes]]);

            }*/
            return true;

        }
        return true;

    }

    public function processListing($data, $webhook = '')
    {
        try {
            if (isset($data['data'], $data['data']['product_listing'], $data['data']['product_listing']['product_id'])) {
                $containerId = $data['data']['product_listing']['product_id'];
                $userId = $data['user_id'];
                $shopId = $data['shop_id'];

                $notListedConfig = $this->di->getConfig()->get('not_listed_queue_push');
                $notListedEnabled = $notListedConfig?->enabled ?? false;
                $isNotListedEnabled = false;

                if ($notListedEnabled === 'all') {
                    $isNotListedEnabled = true;
                } else {
                    if ($notListedEnabled === 'specific') {
                        $specificUsers = $notListedConfig->get('specific_users')?->toArray() ?? [];
                        if (in_array($userId, $specificUsers)) {
                            $isNotListedEnabled = true;
                        }
                    }
                }

                if (!$isNotListedEnabled) {
                    return ['success' => true, 'function' => 'productListingUpdate', 'process_now' => true];
                }

                $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                // $mongoCollection = $mongo->getCollection("product_container");
                // replace it with refine_product table
                $mongoCollection = $mongo->getCollection("refine_product");
                $options = ["typeMap" => ['root' => 'array', 'document' => 'array'], 'projection' => [
                    'container_id' => 1,
                    'items.status' => 1
                ]];

                $productsData = $mongoCollection->find(
                    [
                        'user_id' => $userId,
                        'source_shop_id' => $shopId,
                        'container_id' => (string) $containerId,
                        // 'source_marketplace' => 'shopify'

                    ],
                    $options
                )->toArray();

                if (!empty($productsData)) {
                    $planTier = $this->di->getUser()->plan_tier ?? 'default';
                    if ($planTier == 'default') {
                        return ['success' => true, 'function' => "productListingUpdate", 'process_now' => true];
                    }
                    // check if any item.status is in inactive ['Active','Inactive','Incomplete'], set FLag true for process now or re-queue
                    $flag = $data['data']['process_now'] ?? false;
                    foreach ($productsData as $product) {
                        if ( $flag ) {
                            break;
                        }
                        // loop through items and use strtolower
                        foreach ($product['items'] as $item) {
                            if (strtolower($item['status']) == 'active' 
                            || strtolower($item['status']) == 'inactive' 
                            || strtolower($item['status']) == 'incomplete') {
                                $flag = true;
                                break;
                            }
                        }
                    }
                    return ['success' => true, 'function' => "productListingUpdate", 'data' => $productsData, 'process_now' => $flag];
                }
                if ($webhook == 'product_listing_create' || $webhook == 'product_listings_add') {
                    return ['success' => true, 'function' => "productListingCreate", 'data' => $data];
                }
                $message = 'product not found for update';
            } else {
                $message = 'Required params missing';
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception, processListing() => ' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    public function productListingUpdate_to_do($data)
    {
        //todo : as per sales channel requirement

        if (isset($data['data']['product_listing']))
        {
            $webhookHelper = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Webhooks\Helper::class);
            $data['data'] = $webhookHelper->prepareProductData($data);
            unset($data['existing_db_data']);

            /*$validateInvAndLoc = $this->validateInvAndLoc($data);
            $data['data'] = $validateInvAndLoc['data'];*/

            $process_data = $this->di->getObjectManager()
                ->get("\App\Connector\Components\Product\Hook")
                ->productCreateOrUpdate($data);

            return ['success' => true, 'requeue' => false, 'data' => $process_data];
        }
        return ['success' => false, 'message' => "['data']['product_listing'] is not set."];
    }

    // public function productListingUpdate($data)
    // {
    //     if (isset($data['data']['product_listing'])) {
    //         $quickImportHelper = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Quickimport\Helper::class);
    //         $formattedProductListing = $quickImportHelper->shapeProductListingResponse($data['data']['product_listing']);
    //         if ($formattedProductListing !== false) {
    //             if (!isset($formattedProductListing['shop_id']) || empty($formattedProductListing['shop_id'])) {
    //                 $formattedProductListing['shop_id'] = $data['shop_id'] ?? null;
    //             }

    //             $formattedProductResponse = [
    //                 'success'   => true,
    //                 'data'      => [
    //                     'Product' => [
    //                         $formattedProductListing
    //                     ]
    //                 ]
    //             ];

    //             $vistarHelper = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Vistar\Helper::class);
    //             $shapeResponse = $vistarHelper->shapeProductResponse($formattedProductResponse);

    //             if (isset($shapeResponse['success'], $shapeResponse['data'])) {
    //                 $data['data'] = $shapeResponse['data'];
    //                 $data = $this->di->getObjectManager()
    //                     ->get("\App\Connector\Components\Product\Hook")
    //                     ->productCreateOrUpdate($data);

    //                 return ['success' => true, 'requeue' => false, 'data' => $data];
    //             }
    //             $message = 'Unable to shape response';
    //         } else {
    //             $message = 'Unable to format response product_listing data is invalid';
    //         }
    //     } else {
    //         $message = "['data']['product_listing'] is not set.";
    //     }
    //     return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    // }

    public function productListingUpdate($data)
    {
        if (!isset($data['data']['product_listing'])) {
            return ['success' => false, 'message' => "['data']['product_listing'] is not set."];
        }

        $connectorFormatResponse = $this->shapeProductListingToConnectorFormat($data['data']['product_listing'], $data['shop_id']);

        if (empty($connectorFormatResponse['success']) || empty($connectorFormatResponse['data'])) {
            return ['success' => false, 'message' => 'Unable to shape response'];
        }

        $data['data'] = $connectorFormatResponse['data'];
        $updatedData = $this->di->getObjectManager()
            ->get("\App\Connector\Components\Product\Hook")
            ->productCreateOrUpdate($data);

        return ['success' => true, 'requeue' => false, 'data' => $updatedData];
    }

    public function shapeProductListingToConnectorFormat(array $product_listing, $shopId = null): array
    {
        if (empty($product_listing['product_id'])) {
            return ['success' => false, 'message' => 'Invalid product_listing payload'];
        }

        $productId = (string)$product_listing['product_id'];
        $variants = $product_listing['variants'] ?? [];
        $options = $product_listing['options'] ?? [];

        $variantAttrNames = [];
        $variantAttrValues = [];
        if (!empty($options)) {
            foreach ($options as $opt) {
                $name = $opt['name'] ?? null;
                if ($name && (strtolower($name) !== 'title' || count($variants) > 1)) {
                    $variantAttrNames[] = $name;
                    $vals = $opt['values'] ?? [];
                    $vals = is_array($vals) ? $vals : [$vals];
                    foreach ($vals as $v) {
                        $variantAttrValues[$name][$v] = true;
                    }
                }
            }
        } else {
            foreach ($variants as $v) {
                foreach (($v['option_values'] ?? []) as $ov) {
                    $name = $ov['name'] ?? null;
                    $val  = $ov['value'] ?? null;
                    if ($name && (strtolower($name) !== 'title' || count($variants) > 1)) {
                        $variantAttrNames[] = $name;
                        if ($val !== null) {
                            $variantAttrValues[$name][$val] = true;
                        }
                    }
                }
            }
            $variantAttrNames = array_values(array_unique($variantAttrNames));
        }

        $variantAttrValuesArr = [];
        foreach ($variantAttrValues as $k => $vals) {
            $variantAttrValuesArr[] = ['key' => $k, 'value' => array_values(array_keys($vals))];
        }

        $base = [
            'type'              => (count($variants) > 1) ? 'variation' : 'simple', //TODO: this needs to be updated, it has error
            'shop_id'           => $shopId ?? ($product_listing['shop_id'] ?? null),
            'container_id'      => $productId,
            'source_product_id' => $productId,
            'title'             => $product_listing['title'] ?? '',
            'product_type'      => $product_listing['product_type'] ?? '',
            'description'       => $product_listing['body_html'] ?? '',
            'brand'             => $product_listing['vendor'] ?? '',
            'handle'            => $product_listing['handle'] ?? '',
            'published_at'      => $product_listing['published_at'] ?? null,
            'source_created_at' => $product_listing['created_at'] ?? null,
            'source_updated_at' => $product_listing['updated_at'] ?? null,
            // 'collection'        => [],
            'tags'              => !empty($product_listing['tags'])
                ? array_map(static fn($t) => trim((string)$t), explode(',', (string)$product_listing['tags']))
                : [],
            'variant_attributes'        => $variantAttrNames,
            'variant_attributes_values' => $variantAttrValuesArr,
        ];

        $images = $product_listing['images'] ?? [];
        $imagesById = [];
        $featured = null;
        foreach ($images as $img) {
            $img['originalSrc'] = $img['src'] ?? ($img['originalSrc'] ?? null);
            $imagesById[$img['id'] ?? null] = $img;
            if (!$featured && (($img['position'] ?? null) === 1 || (string)($img['position'] ?? '') === '1')) {
                $featured = $img;
            }
        }
        if (!$featured && !empty($images)) {
            $first = reset($images);
            $first['originalSrc'] = $first['src'] ?? ($first['originalSrc'] ?? null);
            $featured = $first;
        }
        $base['main_image'] = $featured['originalSrc'] ?? '';
        $additional = [];
        foreach ($images as $img) {
            $src = $img['src'] ?? ($img['originalSrc'] ?? null);
            if ($src && (!isset($featured['id']) || ($img['id'] ?? null) !== ($featured['id'] ?? null))) {
                $additional[] = $src;
            }
        }
        $base['additional_images'] = $additional;

        $mapWeightUnit = static function ($unit) {
            $u = strtoupper((string)$unit);
            return match ($u) {
                'KG', 'KILOGRAM', 'KILOGRAMS' => 'KILOGRAMS',
                'G', 'GRAM', 'GRAMS' => 'GRAMS',
                'LB', 'LBS', 'POUND', 'POUNDS' => 'POUNDS',
                'OZ', 'OUNCE', 'OUNCES' => 'OUNCES',
                default => $u,
            };
        };
        $toGrams = static function ($unit, $weight) {
            if ($weight === null || $weight === '' || !is_numeric($weight)) return null;
            return match ($unit) {
                'KILOGRAMS' => (float)$weight * 1000,
                'GRAMS'     => (float)$weight,
                'POUNDS'    => (float)$weight * 453.6,
                'OUNCES'    => (float)$weight * 28.3,
                default     => null,
            };
        };

        $candidateDates = [];
        if (!empty($product_listing['updated_at'])) {
            $candidateDates[] = $product_listing['updated_at'];
        }
        if (!empty($variants) && is_array($variants)) {
            foreach ($variants as $v) {
                if (!empty($v['updated_at'])) {
                    $candidateDates[] = $v['updated_at'];
                }
            }
        }

        $latestUpdatedAt = null;
        if (!empty($candidateDates)) {
            $timestamps = array_map('strtotime', $candidateDates);

            $latestUpdatedAt = $candidateDates[array_search(max($timestamps), $timestamps)];
        } else {
            $latestUpdatedAt = $product_listing['updated_at'] ?? $product_listing['created_at'] ?? null;
        }

        if ($latestUpdatedAt) {
            $base['source_updated_at'] = $latestUpdatedAt;
        }

        $records = [];
        foreach ($variants as $variant) {
            $row = $base;

            $variantId = (string)($variant['id'] ?? '');
            $row['id'] = $variantId;
            $row['source_product_id'] = $variantId;
            $row['sku'] = !empty($variant['sku']) ? $variant['sku'] : $variantId;
            $row['source_sku'] = null;

            $row['variant_title'] = $variant['title'] ?? '';
            $row['price'] = $variant['price'] ?? null;
            $row['compare_at_price'] = $variant['compare_at_price'] ?? null;
            $row['quantity'] = $variant['inventory_quantity'] ?? null;

            $row['fulfillment_service'] = $variant['fulfillment_service'] ?? null;

            if (!empty($variant['image_id']) && isset($imagesById[$variant['image_id']])) {
                $row['variant_image'] = $imagesById[$variant['image_id']]['originalSrc'] ?? null;
            }

            foreach (['barcode', 'taxable', 'position'] as $key) {
                if (array_key_exists($key, $variant)) {
                    $row[$key] = $variant[$key];
                }
            }

            // Options to attribute keys (skip "Title"/"Default Title")
            $optionValues = $variant['option_values'] ?? [];
            if (!empty($optionValues)) {
                if (!(count($optionValues) === 1
                    && (($optionValues[0]['name'] ?? '') === 'Title')
                    && (($optionValues[0]['value'] ?? '') === 'Default Title'))) {
                    foreach ($optionValues as $opt) {
                        if (isset($opt['name'], $opt['value']) && strtolower($opt['name']) !== 'title') {
                            $row[$opt['name']] = $opt['value'];
                        }
                    }
                } else {
                    $row['variant_attributes'] = [];
                }
            }

            if (!empty($variant['inventory_item_id'])) {
                $row['inventory_item_id'] = (string)$variant['inventory_item_id'];
            }
            $row['inventory_tracked'] = isset($variant['inventory_management']) ? (bool)$variant['inventory_management'] : false;
            $row['inventory_management'] = $variant['inventory_management'] ?? '';
            if (isset($variant['requires_shipping'])) {
                $row['requires_shipping'] = (bool)$variant['requires_shipping'];
            }
            if (isset($variant['inventory_policy'])) {
                $row['inventory_policy'] = strtoupper((string)$variant['inventory_policy']);
            }

            $weight = $variant['weight'] ?? null;
            $weightUnit = $mapWeightUnit($variant['weight_unit'] ?? null);
            if ($weight !== null) {
                $row['weight'] = $weight;
                $row['weight_unit'] = $weightUnit;
                $row['grams'] = $toGrams($weightUnit, $weight);
            }

            if (isset($variant['created_at'])) {
                $row['created_at'] = $variant['created_at'];
            }
            if (isset($variant['updated_at'])) {
                $row['updated_at'] = $variant['updated_at'];
            }

            if($latestUpdatedAt) {
                $row['source_updated_at'] = $latestUpdatedAt;
            }

            $records[] = $row;
        }

        return [
            'success' => true,
            'data'    => $records,
            'count'   => [
                'product' => 1,
                'variant' => count($records)
            ]
        ];
    }

    public function validateInvAndLoc($data, $webhook_sqs_data = [])
    {
        $alreadyCallShopifyProductapi = $inventoryItemIds = [];
        $productData = $data['data'];
        foreach ($productData as $r_value)
        {
            if(($r_value['container_id'] != $r_value['source_product_id']) && (!isset($r_value['inventory_item_id']) || $r_value['inventory_item_id'] == ''))
            {
                $alreadyCallShopifyProductapi = [];
                if(empty($alreadyCallShopifyProductapi))
                {
                    $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();

                    $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');

                    $user_details = $user_details->getDataByUserID($data['user_id'], $target);

                    $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init($target, 'true')
                        ->call('/product',['Response-Modifier'=>'0'],['ids'=>$r_value['container_id'],'shop_id'=>$user_details['remote_shop_id'],'fields' => 'variants','sales_channel'=>0], 'GET');

                    if(isset($remoteResponse['success']) && $remoteResponse['success'] === true && isset($remoteResponse['data']))
                    {
                        $alreadyCallShopifyProductapi = $remoteResponse['data'];

                        $allShopifyProductVar = $alreadyCallShopifyProductapi['products']['0']['variants'];
                        //$inventoryItemIds = [];

                        foreach ($allShopifyProductVar as $oneShopifyProductVar) {

                            if($oneShopifyProductVar['id'] == $r_value['source_product_id'])
                            {
                                $inventoryItemIds[$r_value['source_product_id']] = $oneShopifyProductVar['inventory_item_id'];
                                //$productData[$r_key]['inventory_item_id'] = $oneShopifyProductVar['inventory_item_id'];

                            }

                            //$inventoryItemIds[] = $oneShopifyProductVar['inventory_item_id'];
                        }

                    }

                }
                else
                {
                    $allShopifyProductVar = $alreadyCallShopifyProductapi['products']['0']['variants'];
                    //$inventoryItemIds = [];

                    foreach ($allShopifyProductVar as $oneShopifyProductVar) {

                        if($oneShopifyProductVar['id'] == $r_value['source_product_id'])
                        {
                            $inventoryItemIds[$r_value['source_product_id']] = $oneShopifyProductVar['inventory_item_id'];

                            //$productData[$r_key]['inventory_item_id'] = $oneShopifyProductVar['inventory_item_id'];
                        }

                        //$inventoryItemIds[] = $oneShopifyProductVar['inventory_item_id'];
                    }
                }
            }
        }

        if(!empty($inventoryItemIds))
        {
            $setLocations = $this->prepareProductLocation($data, $inventoryItemIds, $user_details, $target);

            if(isset($setLocations['success']) && $setLocations['success'])
            {
                $setLocAndInvItemId = $this->setLocationsAndInvItemId($productData, $inventoryItemIds, $setLocations['data']);

                return ['success' => true, 'data' => $setLocAndInvItemId['data']];
            }
        }

        return ['success' => true, 'data' => $productData];
    }

    public function setLocationsAndInvItemId($productData, $inventoryItemIds, $setLocAndInvItemId)
    {
        $prepProductData = $productData;
        foreach ($productData as $pd_key => $pd_value)
        {
            $source_product_id = $pd_value['source_product_id'];
            $pd_inventoryItemId = $inventoryItemIds[$source_product_id] ?? '';
            $prepProductData[$pd_key]['inventory_item_id'] = (string)$pd_inventoryItemId;
            if($pd_inventoryItemId != '' && isset($setLocAndInvItemId[$pd_inventoryItemId]))
            {
                $prepProductData[$pd_key]['locations'] = array_values($setLocAndInvItemId[$pd_inventoryItemId]);
            }
        }

        return ['success' => true, 'data' => $prepProductData];

    }

    public function prepareProductLocation($sqsData,$inventoryItemIds, $user_details, $target){
        $userId = $sqsData['user_id'];

        //$target = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Shop\Shop')->getUserMarkeplace();
        //$user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');

        //$user_details = $user_details->getDataByUserID($userId, $target);
        $locationAvailable = $user_details['warehouses'];
        $inventoryItemLocations = [];
        if(!empty($locationAvailable))
        {
            foreach ($locationAvailable as $l_value)
            {
                $location_ids[] = $l_value['id'];
            }

            if(!empty($location_ids) && !empty($inventoryItemIds))
            {
                $remoteInventoryResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init($target, 'true')
                    ->call('/inventory',[
                        'Response-Modifier'=>'0'
                    ],
                        [
                            'shop_id'           =>$user_details['remote_shop_id'],
                            'sales_channel'     =>0,
                            'inventory_item_ids'=>array_values($inventoryItemIds),
                            'location_ids'      =>$location_ids
                        ], 'GET');

                if(isset($remoteInventoryResponse['success']) && $remoteInventoryResponse['success'])
                {
                    foreach ($remoteInventoryResponse['data']['inventory_levels'] as $ri_value)
                    {
                        $ri_value['location_id'] = (string)$ri_value['location_id'];
                        $ri_value['inventory_item_id'] = (string)$ri_value['inventory_item_id'];

                        $inventoryItemLocations[$ri_value['inventory_item_id']][$ri_value['location_id']] = $ri_value;
                    }

                    return ['success' => true, 'data' => $inventoryItemLocations];
                    //$inventoryLevels = array_merge($inventoryLevels,$remoteInventoryResponse['data']['inventory_levels']);
                }
                else{
                    return ['success' => false, 'message' => 'no information found for inventory item ids'];
                }
            }
            else{
                return ['success' => false, 'message' => 'location ids or inventory items array is empty'];
            }
        }
        else{
            return ['success' => false, 'message' => 'no locations found for merchant in shops in user_details'];
        }
    }


    public function newProductListingCreate($productId, $appCode, $marketplace, $userShop)
    {
        $remoteShopId = $userShop['remote_shop_id'];
        $remoteResponse = $this->getRemoteResponse($marketplace, $appCode, ['Response-Modifier' => '0'], ['ids' => $productId, 'shop_id' => $remoteShopId, 'sales_channel' => 0], "/product");
        if (isset($remoteResponse['success'], $remoteResponse['data']['products'])) {
            #Parsing Locations ids and variants inventory items id
            $variants = $remoteResponse['data']['products'][0]['variants'];
            $quickImportHelper = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Quickimport\Helper::class);
            $formattedProductResponse = $quickImportHelper->shapeProductResponse($remoteResponse);
            $productBasicData = $formattedProductResponse['data']['Product'][0];
            $inventoryVariantMapping = $this->mapInventoryIdWithVariantIndex($variants ?? []);
            $variantsInventoryItemId = array_keys($inventoryVariantMapping);

            $locationIds =  array_column($userShop['warehouses'], 'id');
            #Fetching Collection
            $collectionResponse = $this->getCollectionsUsingProductId($productId, $marketplace, $appCode, $remoteShopId);
            $this->di->getLog()->logContent('Get Product from Shopify Response : ' . json_encode($collectionResponse), 'info', 'product_listing_create_test.log');

            if (isset($collectionResponse['success'], $collectionResponse['data']) && $collectionResponse['success']) {
                $productBasicData['Collection'] = $collectionResponse['data'];
            }

            #fetching Inventory and type casting it to string from int
            $locationResponse = $this->getRemoteResponse($marketplace, $appCode, ['Response-Modifier' => '0'], ['shop_id' => $remoteShopId, 'sales_channel' => 0, 'inventory_item_ids' => $variantsInventoryItemId, 'location_ids' => $locationIds], "/inventory");

            if (isset($locationResponse['success'], $locationResponse['data']['inventory_levels']) && $locationResponse['success']) {
                $productInvUtility = $this->di->getObjectManager()->get(Utility::class);
                $inventoryLevels = $productInvUtility->typeCastElement($locationResponse['data']['inventory_levels']);
                $productBasicData = $this->insertInventoriesInProduct($inventoryLevels, $inventoryVariantMapping, $productBasicData);
            }

            #Formatting product and return to hook
            $vistarHelper = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Vistar\Helper::class);
            $data = [
                'success' => true,
                'data' => [
                    'Product' => [$productBasicData]
                ]
            ];
            $formattedProduct = $vistarHelper->shapeProductResponse($data);
            return $formattedProduct['data'];
        }
        return false;
    }

    public function mapInventoryIdWithVariantIndex($variants)
    {
        $inventoryMapping = [];
        foreach ($variants as $key => $value) {
            $inventoryMapping[$value['inventory_item_id']] = $key;
        }

        return $inventoryMapping;
    }

    public function getCollectionsUsingProductId($productId, $marketplace, $appCode, $remoteShopId)
    {
        $remoteResponse = $this->getRemoteResponse($marketplace, $appCode, ['Response-Modifier' => '0'], ['product_id' => $productId, 'shop_id' => $remoteShopId, 'sales_channel' => 0], "/getAllCollections");
        if (isset($remoteResponse['success'], $remoteResponse['data']['body']['product']['collections']['nodes'])) {
            $collections = $remoteResponse['data']['body']['product']['collections']['nodes'];
            foreach($collections as $value) {
                if (preg_match("/[0-9]+/", (string) $value['id'], $matches)) {
                    $id = $matches[0];
                } else {
                    $id = explode("/", (string) $value['id'])[4];
                }

                $value['__parentId'] = $productId;
                $data[$id] = $value;
            }

            return [
                'success' => $remoteResponse['success'],
                'data' => $data ?? []
            ];
        }

        return [
            'success' => false,
            'message' => "Error Fetching Collections of ". $productId
        ];
    }

    public function insertInventoriesInProduct($inventories, $inventoryVariantMapping, $product)
    {
        foreach ($inventories as $value) {
            $variantIndex = $inventoryVariantMapping[$value['inventory_item_id']];
            $product['ProductVariant'][$variantIndex]['locations'][] = $value;
        }

        return $product;
    }

    public function getRemoteResponse($marketplace, $appCode, $headers, $filter, $endPoint, $type = 'GET')
    {
        return $this->di->getObjectManager()->get('\App\Connector\Components\ApiClient')->init($marketplace, 'true', $appCode)->call($endPoint, $headers, $filter, $type);
    }



    public function initiateParallelImporting()
    {
        return [
            'succeed' => true,
            'data' => 2000
        ];
    }

    public function productListingCreate($data)
    {
        $productId = $data['data']['product_listing']['product_id'];
        $userId = $data['user_id'];
        $appCode = $data['app_code'] ?? ($data['app_codes'][0] ?? '');
        $shop_id = $data['shop_id'];

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');

        //$user_details = $user_details->getDataByUserID($userId, $target);
        $user_details = $user_details->getShop($shop_id, $userId);

        // $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
        //     ->init($target, 'true',$appCode)
        //     ->call('/product',['Response-Modifier'=>'0'],['ids'=>$productId,'shop_id'=>$user_details['remote_shop_id'],'sales_channel'=>0], 'GET');
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target, 'true',$appCode)
            ->call('/product',['Response-Modifier'=>'0'],['id'=>$productId,'shop_id'=>$user_details['remote_shop_id'],'sales_channel'=>0,'call_type' => 'QL'], 'GET');

        if(isset($remoteResponse['success']) && $remoteResponse['success'] === true && isset($remoteResponse['data']))
        {
            $quickImportHelper = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Quickimport\Helper::class);
            
            $remoteResponse['data']['products'][$productId] = $remoteResponse['data']['product'];
            unset($remoteResponse['data']['product']);
            // print_r($remoteResponse);die;
            $formattedProductResponse = $quickImportHelper->shapeProductResponse($remoteResponse);

            if ($formattedProductResponse['success']) {

                $quickImport = $this->di->getObjectManager()->get(Import::class);

                $quickImportResponse = $quickImport->saveProduct($formattedProductResponse, $data);

                $canImportCollection = $this->getDi()->getConfig()->get('can_import_collection') ?? true;
                if($canImportCollection === true) {
                    $collectionImportLimit = $this->getDi()->getConfig()->get('import_collection_limit') ?? -1;

                    $subQuery = <<<SUBQUERY
product_{$productId}: product(id: "gid://shopify/Product/{$productId}") {
...productInformation
}
SUBQUERY;


                    $query = <<<QUERY
query {
{$subQuery}
}
fragment productInformation on Product {
id
collections (first:95) {
edges {
    node {
        id
        title
    }
}
}
}
QUERY;

                    $remoteCollectionResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init($target, true, $appCode)
                        ->call('/shopifygql/query', [], ['shop_id' => $user_details['remote_shop_id'], 'query' => $query], 'POST');

                    if (isset($remoteCollectionResponse['success']) && $remoteCollectionResponse['success']) {
                        $newProductCollection = [];
                        foreach ($remoteCollectionResponse['data'] as $key => $productCollection) {
                            $explode = explode('_', $key);

                            $product_id = $explode[1];

                            if (isset($productCollection['collections']['edges']) && count($productCollection['collections']['edges'])) {
                                $count = 0;
                                foreach ($productCollection['collections']['edges'] as $collectionInfo) {
                                    $count++;
                                    $newProductCollection[$product_id][] = [
                                        'collection_id' => str_replace('gid://shopify/Collection/', '', $collectionInfo['node']['id']),
                                        'title' => $collectionInfo['node']['title'],
                                        'gid' => $collectionInfo['node']['id'],
                                        '__parentId' => (string)$product_id
                                    ];

                                    if($collectionImportLimit !== -1 && $count >= $collectionImportLimit) {
                                        break;
                                    }
                                }
                            } else {
                                $newProductCollection[$product_id] = [];
                            }
                        }
                        if (!empty($newProductCollection)) {
                            $quickImport = $this->di->getObjectManager()->get("App\Shopifyhome\Components\Product\Quickimport\Import");

                            foreach ($newProductCollection as $proId => $collection) {
                                $quickImport->updateProductCollection($userId, (string)$proId, $collection);
                            }
                        }
                    }
                }

                /*$remoteLocationResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init($target, 'true', $appCode)
                    ->call('/shop/location', ['Response-Modifier' => '0'], ['shop_id' => $user_details['remote_shop_id'], 'sales_channel' => 0], 'GET');*/

                $user_warehouses = $user_details['warehouses'] ?? [];


                //if (isset($remoteLocationResponse['success']) && $remoteLocationResponse['success']) {
                if ($user_warehouses) {
                    $locationIds = [];
                    $inventoryItemIds = [];
                    /*foreach ($remoteLocationResponse['data']['locations'] as $key => $location) {
                        $locationIds[] = $location['id'];
                    }*/
                    foreach ($user_warehouses as $uw_v)
                    {
                        $locationIds[] = $uw_v['id'];
                    }

                    $variants = current($remoteResponse['data']['products'])['variants'] ?? [];
                    foreach ($variants as $key => $variant) {
                        $inventoryItemIds[] = $variant['inventory_item_id'];
                    }

                    $inventoryItemIdChunk = array_chunk($inventoryItemIds, 50);
                    $inventoryLevels = [];

                    foreach ($inventoryItemIdChunk as $key => $inventoryItemId)
                    {
                        $remoteInventoryResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                            ->init($target, 'true', $appCode)
                            ->call('/inventory', ['Response-Modifier' => '0'], ['shop_id' => $user_details['remote_shop_id'], 'sales_channel' => 0, 'inventory_item_ids' => $inventoryItemId, 'location_ids' => $locationIds], 'GET');

                        if (isset($remoteInventoryResponse['success']) && $remoteInventoryResponse['success'])
                        {
                            $inventoryLevels = array_merge($inventoryLevels, $remoteInventoryResponse['data']['inventory_levels']);
                        }
                    }

                    //  var_dump($inventoryLevels,$inventoryItemIds);die;
                    if (!empty($inventoryLevels))
                    {
                        $inventoryData = [];

                        foreach ($inventoryLevels as $inventoryValue)
                        {
                            $inventoryData[$inventoryValue['inventory_item_id']][] = $inventoryValue;
                        }

                        $productInvUtility = $this->di->getObjectManager()->get(Utility::class);
                        $quickImport = $this->di->getObjectManager()->get(Import::class);

                        foreach ($inventoryData as $invId => $productInv)
                        {
                            $quickImport->updateProductInventory($userId, (string)$invId, $productInvUtility->typeCastElement($productInv));
                        }
                    }

                }

                //this is already saved in saveproduct function called above so no need to do it again
                /*$mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $mongoCollection = $mongo->getCollection("product_container");
                $options = ["typeMap" => ['root' => 'array', 'document' => 'array'],'projection' => ['source_product_id' => 1, 'app_codes' => 1]];

                $productsData = $mongoCollection->find(['container_id' => (string)$productId, 'user_id' => $userId], $options)->toArray();

                if (!empty($productsData)) {
                    $appCodes = [];
                    foreach ($productsData as $key => $variantProduct) {
                        if (isset($variantProduct['app_codes'])) {
                            if (!in_array($appCode, $variantProduct['app_codes'])) {
                                $appCodes = array_merge($variantProduct['app_codes'], [$appCode]);
                            }
                        } else {
                            $appCodes[] = $appCode;
                        }
                        break;
                    }
                    if (!empty($appCodes)) {
                        $query = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Helper')->getAndQuery(['container_id' => (string)$productId], $userId);
                        $mongoCollection->updateMany($query, ['$set' => ['app_codes' => $appCodes]]);
                    }
                }*/


                return [
                    'success' => true,
                    'msg' => $remoteResponse
                ];
            }
        }
        else {
            $this->di->getLog()->logContent('Get Product from Shopify Response : ' . json_encode($remoteResponse), 'info', 'product_listing_create.log');
        }
    }

    public function registerWebhooks($source)
    {
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $userId = $this->di->getUser()->id;

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $user_details = $user_details->getDataByUserID($userId, $target);

        $awsConfig = include_once(BP . DS . 'app' . DS . 'etc' . DS . 'aws.php');
        $sqsConfig = [
            'region' => $awsConfig['region'],
            'key' => $awsConfig['credentials']['key'] ?? null,
            'secret' => $awsConfig['credentials']['secret'] ?? null,
        ];

        $handlerData = [
            'type' => 'full_class',
            'class_name' => \App\Shopifyhome\Components\Webhook\Route\Requestcontrol::class,
            'method' => 'registerWebhooks',
            'queue_name' => 'shopify_register_webhook',
            'user_id' => $userId,
            'data' => [
                'user_id' => $userId,
                'remote_shop_id' => $user_details['remote_shop_id'],
                'sqs' => $sqsConfig,
                'cursor' => 0,
                'source' => $source,
                'target' => $target
            ]
        ];
        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');

        $this->di->getLog()->logContent('webhook SQS data =' . json_encode($handlerData), 'info','shopify'.DS.$this->di->getUser()->id.DS.'webhook_register.log');

        return [
            'success' => true,
            'message' => 'Registering Webhooks ...',
            'queue_sr_no' => $rmqHelper->createQueue($handlerData['queue_name'], $handlerData)
        ];
    }

    public function registerUninstallWebhook($shop=[],$appCode=''): void
    {
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $userId = $this->di->getUser()->id;
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $user_details = $user_details->getDataByUserID($userId, $target);

        $awsConfig = include_once(BP . DS . 'app' . DS . 'etc' . DS . 'aws.php');
        $queueName = $this->di->getConfig()->app_code.'_webhook_app_delete';

        $webhookDataForAppDelete = [
            'type' => 'sqs',
            'queue_config' => [
                'region' => $awsConfig['region'],
                'key' => $awsConfig['credentials']['key'] ?? null,
                'secret' => $awsConfig['credentials']['secret'] ?? null,
            ],
            'topic' => 'app/uninstalled',
            'queue_unique_id' => 'sqs-'.$this->di->getConfig()->app_code,
            'format' => 'json',
            'queue_name' => $queueName,
            'queue_data' => [
                'type' => 'full_class',
                'class_name' => '\App\Connector\Models\SourceModel',
                'method' => 'triggerWebhooks',
                'user_id' => $userId,
                'code' => 'Shopifyhome',
                'action' => 'app_delete',
                'queue_name' => 'webhook_app_delete',
                'app_code'=>$appCode,
                'marketplace'=> $shop['marketplace'] ?? ''
            ]
        ];

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $responseWbhook = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target, 'true')
            ->call('/webhook/register', [], ['shop_id' => $user_details['remote_shop_id'], 'data' => $webhookDataForAppDelete], 'POST');

        $this->di->getLog()->logContent('uninstall webhook triggerred for user id : ' . $userId . ' | response :' . json_encode($responseWbhook), 'info', 'uninstallWebhook.log');

    }

    // function of unregistering product create webhook for all user_ids
    public function productCreateUnregisterWebhook($marketplaceDetails = [], $userId = null)
    {
        $handlerData = [
            'type' => 'full_class',
            'class_name' => \App\Shopifyhome\Components\Shop\Route\Requestcontrol::class,
            'method' => 'UnregisterProductCreateWebhooks',
            'queue_name' => 'webhook_product_create',
            'user_id' => $userId,
            'data' => [
                'topic' =>'products/create',
                'user_id' => $userId,
                'chunkSize' => 20,
                'cursor' => 0
            ]
        ];
        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        return [
            'success' => true,
            'message' => 'Unregister Webhooks Process Initiated',
            'queue_sr_no' => $rmqHelper->createQueue($handlerData['queue_name'], $handlerData)
        ];
    }

    public function getInstallationForm()
    {
        $apiConnector = $this->di->getConfig()->get('apiconnector');
        return [
            'post_type' => 'view_template',
            'viewDir' => '/shopifyhome/view/',
            'template' => 'install',
            'params' => [
                'url' => $apiConnector->base_url . 'apiconnect/request/auth',
                'query_params' => [
                    'sAppId' => $apiConnector->shopify->sub_app_id ,
                    'state' => $this->di->getUser()->id,
                ]
            ],
        ];

        return $data;
    }

    /*
    @ Order Management Starts - 170321
    */
    public function initiateOrderImport($marketplaceDetails = [], $userId = null) {
        $params = $this->di->getRequest()->get();
        $userId ??= $this->di->getUser()->id;
        if(!$userId) return [ 'success' => false, 'message' => 'Invalid User. Please check your login credentials' ];

        $shop = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getDataByUserID($userId, 'shopify');
        if(isset($shop['message'])) return $shop;

        $remoteShopId = $shop->remote_shop_id;

        $queuedTaskModel = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
        $queuedTaskModel->getCollection();
        if (!$queuedTaskModel->count([
            'user_id' => $userId,
            'type' => 'order_import',
            'shop_id' => (string) $shop['_id'],
        ])) {
            if (!$force) {
                return ['success' => false, 'message' => 'Order Import process is already under progress. Please check notification for updates.'];
            }
        }else{

            $queuedTask = new QueuedTasks;
            $queuedTask->set([
                'user_id' => (int)$userId,
                'message' =>'initializing Order Import...',
                'type' => 'order_import',
                'progress' => 0.00,
                'shop_id' => (string) $shop['_id'],
            ]);
            $status = $queuedTask->save();

            $handlerData = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Order\Helper::class)->shapeSqsData($userId, $remoteShopId, $queuedTask);

            $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');

            if(empty($append)) $prefix = '';else $prefix = 'vistar_';

            $this->di->getLog()->logContent('*********************************************************************','info','shopify'.DS.$userId.DS.'order'.DS.date("Y-m-d").DS.$prefix.'order_import.log');
            $this->di->getLog()->logContent('PROCESS 0 CLASS : SourceModel.php | FUNCTION : initiateImport | Start','info','shopify'.DS.$userId.DS.'order'.DS.date("Y-m-d").DS.$prefix.'order_import.log');
            $this->di->getLog()->logContent('*********************************************************************','info','shopify'.DS.$userId.DS.'order'.DS.date("Y-m-d").DS.$prefix.'order_import.log');
            return [
                'success' => true,
                'message' => 'Shopify Order Import Initiated',
                'queue_sr_no' => $rmqHelper->createQueue($handlerData['queue_name'], $handlerData)
            ];
        }

    }

    public function updateOrder($updateData, $userId = false)
    {
        return $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Order\Data::class)->handleOrderData($updateData, $userId);
    }

    /*
    @ Customer Management Starts - 170321
    */
    public function initiateCustomerImport($marketplaceDetails = [], $userId = null) {

        $params = $this->di->getRequest()->get();
        $userId ??= $this->di->getUser()->id;
        if(!$userId) return [ 'success' => false, 'message' => 'Invalid User. Please check your login credentials' ];

        $shop = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getDataByUserID($userId, 'shopify');
        if(isset($shop['message'])) return $shop;

        $remoteShopId = $shop->remote_shop_id;
        $queuedTaskModel = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
        $queuedTaskModel->getCollection();
        if (!$queuedTaskModel->count([
            'user_id' => $userId,
            'type' => 'customer_import',
            'shop_id' => (string) $shop['_id'],
        ])) {
            if (!$force) {
                return ['success' => false, 'message' => 'Customer Import process is already under progress. Please check notification for updates.'];
            }
        }else{

            $queuedTask = new QueuedTasks;
            $queuedTask->set([
                'user_id' => (int)$userId,
                'message' =>'initializing Customer Import...',
                'type' => 'customer_import',
                'progress' => 0.00,
                'shop_id' => (string) $shop['_id'],
            ]);
            $status = $queuedTask->save();

            $handlerData = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Customer\Helper::class)->shapeSqsData($userId, $remoteShopId, $queuedTask);

            $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');

            if(empty($append)) $prefix = '';else $prefix = 'vistar_';

            $this->di->getLog()->logContent('*********************************************************************','info','shopify'.DS.$userId.DS.'order'.DS.date("Y-m-d").DS.$prefix.'customer_import.log');
            $this->di->getLog()->logContent('PROCESS 0 CLASS : SourceModel.php | FUNCTION : initiateImport | Start','info','shopify'.DS.$userId.DS.'order'.DS.date("Y-m-d").DS.$prefix.'customer_import.log');
            $this->di->getLog()->logContent('*********************************************************************','info','shopify'.DS.$userId.DS.'order'.DS.date("Y-m-d").DS.$prefix.'customer_import.log');
            return [
                'success' => true,
                'message' => 'Shopify Customer Import Initiated',
                'queue_sr_no' => $rmqHelper->createQueue($handlerData['queue_name'], $handlerData)
            ];
        }

    }

    public function updateCustomer($updateData, $userId = false)
    {
        return $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Customer\Data::class)->handleCustomerData($updateData, $userId);
    }

    /*
    @ Abandoned Carts Management Starts - 170321
    */
    public function initiateCartImport($marketplaceDetails = [], $userId = null) {
        $params = $this->di->getRequest()->get();
        $userId ??= $this->di->getUser()->id;
        if(!$userId) return [ 'success' => false, 'message' => 'Invalid User. Please check your login credentials' ];

        $shop = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")->getDataByUserID($userId, 'shopify');
        if(isset($shop['message'])) return $shop;

        $remoteShopId = $shop->remote_shop_id;
        $queuedTaskModel = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
        $queuedTaskModel->getCollection();
        if (!$queuedTaskModel->count([
            'user_id' => $userId,
            'type' => 'cart_import',
            'shop_id' => (string) $shop['_id'],
        ])) {
            if (!$force) {
                return ['success' => false, 'message' => 'Checkout Import process is already under progress. Please check notification for updates.'];
            }
        }else{
            $queuedTask = new QueuedTasks;
            $queuedTask->set([
                'user_id' => (int)$userId,
                'message' =>'initializing Checkout Import...',
                'type' => 'cart_import',
                'progress' => 0.00,
                'shop_id' => (string) $shop['_id'],
            ]);
            $status = $queuedTask->save();

            $handlerData = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Cart\Helper::class)->shapeSqsData($userId, $remoteShopId, $queuedTask);

            $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');

            if(empty($append)) $prefix = '';else $prefix = 'vistar_';

            $this->di->getLog()->logContent('*********************************************************************','info','shopify'.DS.$userId.DS.'order'.DS.date("Y-m-d").DS.$prefix.'cart_import.log');
            $this->di->getLog()->logContent('PROCESS 0 CLASS : SourceModel.php | FUNCTION : initiateImport | Start','info','shopify'.DS.$userId.DS.'order'.DS.date("Y-m-d").DS.$prefix.'cart_import.log');
            $this->di->getLog()->logContent('*********************************************************************','info','shopify'.DS.$userId.DS.'order'.DS.date("Y-m-d").DS.$prefix.'cart_import.log');
            return [
                'success' => true,
                'message' => 'Shopify Cart Import Initiated',
                'queue_sr_no' => $rmqHelper->createQueue($handlerData['queue_name'], $handlerData)
            ];
        }
    }

    public function updateCart($updateData, $userId = false)
    {
        return $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Cart\Data::class)->handleCartData($updateData, $userId);
    }

    //Cart Management Ends

    public function getSellerConnectedChannels($data){
        $returnDetails = [];
        $moduleCode = $data['code'];

        $shops = $this->di->getUser()->shops;

        foreach ($shops as $shop) {
            if(is_array($shop)){
                $moduleHome = ucfirst((string) $shop['marketplace']).'home';
                if($moduleHome != $moduleCode){
                    $returnDetails[ucfirst((string) $shop['marketplace'])] = $shop;
                }
            }
        }

        return $returnDetails;
    }

    public function getChannelWebhooks($webhookIdentifier){
        $returnArray = [];
        $webhookActions = $this->di->getConfig()->webhook->$webhookIdentifier;
        foreach ($webhookActions as $webhook) {
            $returnArray[$webhook['topic']] = $webhook['action'];
        }

        return $returnArray;
    }

    public function addWarehouseToShop($data,&$shopData): void
    {

        $addShopLocation = $this->di->getObjectManager()->get(Shop::class)->addWarehouseToUser($data,$shopData,$data['data']['data']['app_code']);

    }

    public function initiateSyncCatalog($data = [], $userId = null, $force = false)
    {
        if (isset($data['marketplace'])) {
            $marketplace = $data['marketplace'];
            $shopId = $data['shop'] ?? false;
            $userId ??= $this->di->getUser()->id;

            if (!$userId) {
                return ['success' => false, 'message' => 'Invalid User. Please check your login credentials'];
            }

            if ($marketplace && $shopId) {
                $shop = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")
                    ->getShop($shopId, $userId);
                /* $this is instance of marketplace source model */
                return $this->pushToSyncCatalogQueue($userId, $shop, $data, $force);

            }
            $shops = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")
                ->findShop([
                    'marketplace' => $marketplace,
                ],
                    $userId
                );
            $result = [];
            if (empty($shops)) {
                return [
                    'success' => false,
                    'message' => 'Marketplace not found, please connect the marketplace first',
                ];
            }

            foreach ($shops as $shop) {
                $result[] = $this->pushToSyncCatalogQueue($userId, $shop, $data, $force);
            }

            return $result[0] ?? ['success' => false, 'message' => 'Message not given by admin'];
        }
        return [
            'success' => false,
            'message' => 'Markeplace is required',
        ];

    }

    public function pushToSyncCatalogQueue($userId, $shop, $data, $force)
    {
        $salesChannel = false;
        if (isset($data['saleschannel']) && $data['saleschannel'] == '1') {
            $salesChannel = true;
        }

        $queueData = [
            'user_id' => $userId,
            'message' => 'initializing SyncCatalog...',
            'type' => $salesChannel ? 'saleschannel_sync_catalog' : 'sync_catalog',
            'progress' => 0.00,
            'shop_id' => (string) $shop['_id'],
            'created_at' => date('c'),
            'sales_channel' => $salesChannel,
        ];
        $queuedTask = new QueuedTasks;
        $queuedTaskId = $queuedTask->setQueuedTask($userId, $queueData);
        if (!$queuedTaskId && !$force) {
            return ['success' => false, 'message' => 'Sync Catalog process is already under progress. Please check notification for updates.'];
        }

        if (isset($queuedTaskId['success']) && !$queuedTaskId['success']) {
            return $queuedTaskId;
        }

        // $handlerData = $this->getProductHelper($salesChannel)->shapeSqsDataForSyncCatalog($userId, $shop, $queuedTaskId);
        $handlerData = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Vistar\Helper::class)->shapeSqsDataForSyncCatalog($userId, $shop, $queuedTaskId);

        if(isset($shop['app_code'])) {
            $handlerData['data']['app_code'] = $shop['app_code'];
        }

        $rmqHelper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
        return [
            'success' => true,
            'message' => $shop['marketplace'] . ' Sync Catalog Initiated',
            'queue_sr_no' => $rmqHelper->createQueue($handlerData['queue_name'], $handlerData),
        ];
    }

    public function prepareProductImportQueueData($handlerData, $shop)
    {
        $handlerData['data']['cursor'] = 1;
        $handlerData['data']['limit'] = \App\Shopifyhome\Components\Product\Vistar\Import::PAGE_SIZE;
        $handlerData['data']['remote_shop_id'] = $shop['remote_shop_id'];
        return $handlerData;
    }

   // Todo : GET Product code starts
    public function getMarketplaceProducts($sqsData)
    {
        if (isset($sqsData['data']['total'], $sqsData['data']['total_variant']) && $sqsData['data']['total'] == 0) {
            $this->di->getLog()->logContent('In zero Product if sourceModel', 'info', 'testproductimport.log');

            return [
                'success' => true,
                'message' => "No active product found in this seller account", 'requeue' => false
            ];
        }

        if (!isset($sqsData['data']['file_path']))
        {

            $sqsData['class_name'] = \App\Shopifyhome\Components\Product\Vistar\Route\Requestcontrol::class;
            $sqsData['method'] = 'handleImport';
            $sqsData['data']['operation'] = 'make_request';
            $this->di->getMessageManager()->pushMessage($sqsData);
            return true;
        }
        $feedId = $sqsData['data']['feed_id'];
        $queuedTaskStatus = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->checkQueuedTaskExists($feedId);
        if (!$queuedTaskStatus && !isset($sqsData['data']['extra_data']['filterType'])) {
            return true;
        }
        $filter['limit'] = $sqsData['data']['limit'];
        $filter['item_limit'] = self::MAX_ITEM_LIMIT_PROCESSABLE;
        try
        {
            if (isset($sqsData['data']['cursor']) && $sqsData['data']['cursor'] != 1) $filter['next'] = $sqsData['data']['cursor'];

            $intermediateResponse = $this->di->getObjectManager()->create(Product::class, [$sqsData['data']['file_path']]);

            $response =  $intermediateResponse->getVariants($filter);
            $vistarHelper = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Vistar\Helper::class);

            $productData = $vistarHelper->shapeProductResponse($response);

            $helper = $this->di->getObjectManager()->get(Helper::class);
            $formatCoreProductData = true;
            if (!empty($sqsData['data']['extra_data']['filterType'])) {
                foreach ($productData['data'] as $key => $product) {
                    if (isset($product['id'])) unset($product['id']);
                    $formatted_product = $product;
                    $productData['data'][$key] = $formatted_product;
                }
                $formatCoreProductData = false;
            }
            if ($formatCoreProductData) {
                //TODO : optimization required in this loop
                foreach ($productData['data'] as $key => $product) {
                    $formatted_product = $helper->formatCoreDataForVariantData($product);
                    if (!empty($formatted_product)) {
                        $productData['data'][$key] = $formatted_product;
                    } else {
                        unset($productData['data'][$key]);
                    }
                }
            }

            $totalVariant = $sqsData['data']['total_variant'] + $productData['count']['variant'];
            $sqsData['data']['total_variant'] = $totalVariant;

            if (isset($productData['cursors']['next'])) {
                $nextcursor = $productData['cursors']['next'];
                $response = ['success' => true, 'products' => ['pushToMaindb' => $productData['data']], 'nextcursor' => $nextcursor];
            } else {
                $response = ['success' => true, 'products' => ['pushToMaindb' => $productData['data']]];
            }

            if (isset($productData['count']['product'])) {
                $product_count = $productData['count']['product'];
                $limit = isset($sqsData['data']['limit']) ? (int)$sqsData['data']['limit'] : 250;

                if ($limit > 0 && $product_count < $limit && isset($response['nextcursor'])) {
                    $total_products = $sqsData['data']['total_count'];
                    $chunk_weight = ($product_count / $total_products) * 100;
                    $response['chunk_individual_weight'] = round($chunk_weight, 4, PHP_ROUND_HALF_UP);
                }
            }

            return $response;
        } catch (Exception $e){
            // var_dump($e->getMessage());var_dump($e->getTraceAsString());die;
            return [ 'success' => false, 'message'=>"An exception occur ".$e->getMessage()];
        }
    }

    // Todo : GET Product code ends
    /**
     * Function to check the marketplace app type
     */
    public function getMarketplaceAppType(array $rawData,&$queueData = []): void
    {
        $sourceShop = $this->di->getRequester()->getSourceName() ?? 'shopify';
        $appCode = $this->di->getAppCode()->get()[$sourceShop];
        $data = $this->di->getConfig()->get('apiconnector')->get($sourceShop)->get($appCode);
        $salesChannel = $data['is_saleschannel'] ?? false;
        if($salesChannel){
            $queueData['sales_channel'] = $salesChannel;
            $queueData['process_code'] = $salesChannel ? 'saleschannel_product_import' : 'product_import';
        }
    }

    /**
     * Function to get the product count of a given marketplace
     * @param array $shop
     * @return array $response
     */
    public function getAllProductCount($shop)
    {
        $marketplace = $shop['marketplace'];
        $headersAppCode = $this->di->getAppCode()->get();
        $appCode = $headersAppCode[$marketplace];
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($marketplace, 'true', $appCode)
            ->call('/product/count',[],['shop_id'=> $shop['remote_shop_id']], 'GET');

        if(isset($remoteResponse['data']) && $remoteResponse['data'] == 0){
            return ['success'=> false, 'message' => 'No Product found for importing', 'count' => 0];
        }

        return $remoteResponse;
    }


    /**
     * Get additional parameters as a state parameter
     * @param array $rawBody
     * @return string $response
     */
    public function getAdditionalParameters($rawBody)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userDetailsCollection = $mongo->getCollection('user_details');
        $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];
        $user_id = $this->di->getUser()->id;
        if (isset($rawBody['targetAppCode'])) {
            $appCode = $rawBody['targetAppCode'];
            $query = [
                ['$match' => ['user_id' => $user_id]],
                ['$unwind' => '$shops'],
                ['$match' => ['shops.apps.code' => $appCode]],
                ['$project' => ['shops._id' => 1,'_id' => 0, 'shops.marketplace' => 1]]
            ];
            $searchResponse = $userDetailsCollection->aggregate($query, $options)->toArray();
            if (count($searchResponse) > 0) {
                return json_encode([
                    'user_id' => $user_id,
                    'target_shop_id' => $searchResponse[0]['shops']['_id'], //ToDo : Needs to be dynamic in future
                    'target' => $searchResponse[0]['shops']['marketplace']
                    ]
                );
            }
        }


        if (isset($rawBody['state'])) {
            $decodedState = json_decode(base64_decode((string) $rawBody['state']), true) ?: (json_decode((string) $rawBody['state'], true) ?? []);
            $state = array_merge($decodedState, ['user_id' => $user_id]);
        } else {
            $state = ['user_id' => $user_id];
        }

        return json_encode($state);
    }

    public function cancelBulkRequestOnMarketplace($sqsData): void
    {
        $requestId = $sqsData['data']['id'];
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($sqsData['data']['shop']['marketplace'], 'true', $sqsData['data']['app_code'])
            ->call('bulk/operation',[],['shop_id'=> $sqsData['data']['shop']['remote_shop_id'], 'id' => $requestId], 'DELETE');
    }

    /**
     * Function to get attributes options
     *
     * @param string $marketplace
     * @return array
     */
    public function getAttributesOptions($marketplace = 'shopify')
    {
        $shopId = $this->di->getRequester()->getSourceId() ?? false;
        if ($marketplace == 'shopify') {
            $data = [
                'source' => [
                    'marketplace' => $marketplace,
                    'shopId' => $shopId
                ]
            ];
            $productAttributes = $this->getProductAttributes($data);
            if ($productAttributes['success'] === true) {
                $response['attr'] = $productAttributes['data']['Shopify Core Attributes'];
                $response['cedcommerce_attr'] = $productAttributes['data']['Cedcommerce Attributes'];
                $response['variant_attr'] = [
                    [
                        'code' => 'Size',
                        'title' => 'Size',
                        'required' => 0,
                    ],
                    [
                        'code' => 'Color',
                        'title' => 'Color',
                        'required' => 0,
                    ],
                    [
                        'code' => 'Material',
                        'title' => 'Material',
                        'required' => 0,
                    ],
                    [
                        'code' => 'Style',
                        'title' => 'Style',
                        'required' => 0,
                    ]
                ];
                $response['meta_attr'] = $productAttributes['data']['Meta attributes'] ?? [];
                foreach ($productAttributes['data']['Variant Attributes'] as $attribute) {
                    if ( !in_array($attribute, $response['variant_attr']) ) {
                        array_push($response['variant_attr'], $attribute);
                    }
                }

                return $response;
            }
        }
    }

    /**
     * Function to get rule group for title optimization
     *
     * @return array
     */
    public function getTitleRuleGroup() {
        return $this->getTitleRuleData();
    }

    /**
     * Function to get rule group data
     *
     * @return array
     */
    public function getTitleRuleData()
    {
        $attributes = $this->getAttributesOptions();
        if ( empty($attributes['attr']) ) {
            return [
                ''              => $this->di->getLocale()->_('None'),
                'product_type'  => $this->di->getLocale()->_('Product Type'),
                'title'         => $this->di->getLocale()->_('Title'),
                'brand'         => $this->di->getLocale()->_('Brand'),
                'sku'           => $this->di->getLocale()->_('SKU')
            ];
        }

        $titleArray = [ '' => $this->di->getLocale()->_('None') ];
        $excludeAttr = [
            'weight',
            'weight_unit',
            'grams',
            'description',
            'price',
            'quantity'
        ];
        foreach ($attributes['attr'] as $attr) {
            if ( !isset($attr['title']) || !isset($attr['code']) || in_array($attr['code'], $excludeAttr)) {
                continue;
            }

            $titleArray[$attr['code']] = $this->di->getLocale()->_(ucfirst((string) $attr['title']));
        }

        return $titleArray;
    }

    /**
     * Will get currency from store and update it in DB
     *
     * @param boolean|string $user_id
     * @param boolean|string $remote_shop_id
     * @return array
     */
    public function getStoreCurrency($user_id = false, $remote_shop_id = false) {
        if ( ! $user_id ) {
            $user_id = $this->di->getUser()->id ?? false;
        }

        if ( ! $remote_shop_id ) {
            $remote_shop_id = $this->getRemoteShopId();
        }

        if ( empty($remote_shop_id) )  {
            return [
                'success' => false,
                'message' => 'Oops! Shop not found.'
            ];
        }

        $appCode = $this->di->getAppCode()->get()['shopify'] ?? 'default';
        $shopResponse = $this->di->getObjectManager()->get('\App\Connector\Components\ApiClient')->init('shopify', true, $appCode)->call('/shop', [], [
            'shop_id'  => $remote_shop_id,
            'app_code' => $appCode,
        ]);
        if ( !empty($shopResponse['success']) ) {
            $currency = $shopResponse['data']['currency'] ?? false;
        } else {
            return $shopResponse;
        }

        $mongo            = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $user_collection  = $mongo->getCollectionForTable('user_details');
        if ( !empty($currency) ) {
            $user_collection->updateOne(
                [
                    'user_id'           => $user_id,
                    'shops.marketplace' => 'shopify'
                ],
                ['$set' => ['shops.$.currency' => $currency]]
            );
            return [
                'success' => true,
                'message' => $this->di->getLocale()->_('Currency code updated successfully')
            ];
        }
        return [
            'success' => false,
            'message' => 'Error getting currency from store.'
        ];
    }

    /**
     * Get remote shop id.
     *
     * @return int
     */
    public function getRemoteShopId(){
        return $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Helper::class)
                ->getRemoteShopId();
    }

    public function getAllProductWithVariantsCount($shop)
    {
        $marketplace = $shop['marketplace'];
        $headersAppCode = $this->di->getAppCode()->get();
        $appCode = $headersAppCode[$marketplace];
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($marketplace, 'true', $appCode)
            ->call('/variants/count',[],['shop_id'=> $shop['remote_shop_id']], 'GET');

        if(isset($remoteResponse['data']) && $remoteResponse['data'] == 0){
            return ['success'=> false, 'message' => 'No count available!'];
        }

        return $remoteResponse;
    }

    /**
     * removeDatafromProductAndRefineContainer
     * Function responsible for deleting data from product and refine container
     * if not sources or targets are connected.
     * @param string $userId
     * @param array $shop
     * @return null
     */
    public function removeDatafromProductAndRefineContainer($userId, $shop)
    {
        try {
            $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
            $productContainer  = $mongo->getCollectionForTable('product_container');
            $refineContainer = $mongo->getCollectionForTable('refine_product');
            $productContainer->deleteMany(
                [
                    'user_id' => $userId,
                    'shop_id' => $shop['_id'],
                    'source_marketplace' => $shop['marketplace']
                ]
            );
            $refineContainer->deleteMany(
                [
                    'user_id' => $userId,
                    'source_shop_id' => $shop['_id']
                ]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception: removeDatafromProductAndRefineContainer(), Error: ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }

    /**
     * deleteDataFromSpecificCollections
     * This function is responsible data from specific
     * collection or custom collection after the shop data has been deleted
     * Function linked with shopEraserAfter Event
     * @param string $userId
     * @param array $shop
     * @return null
     */
    public function deleteDataFromSpecificCollections($userId,  $shop)
    {
        try {
            $mongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
            $productLabelAssignment = $this->di->getConfig()->get('product_label_assignment') ? true : false;
            if ($productLabelAssignment) {
                $labelContainer = $mongo->getCollectionForTable('label_container');
                $labelContainer->deleteMany(
                    [
                        'user_id' => $userId,
                        'source_shop_id' => $shop['_id'],
                        'source_marketplace' => $shop['marketplace']
                    ]
                );
            }
            $batchProductImport = $this->di->getConfig()->get('use_batch_import') ? true : false;
            if ($batchProductImport) {
                $batchImportContainer = $mongo->getCollectionForTable('batch_import_product_container');
                $batchImportQueue = $mongo->getCollectionForTable('batch_import_product_queue');
                $batchImportContainer->deleteMany(
                    [
                        'user_id' => $userId,
                        'shop_id' => $shop['_id']
                    ]
                );

                $batchImportQueue->deleteMany(
                    [
                        'user_id' => $userId,
                        'shop_id' => $shop['_id']
                    ]
                );
            }

            $productMetafieldDefinition = $this->di->getConfig()->get('saveMetafieldDefinitions') ? true : false;
            if ($productMetafieldDefinition) {
                $productMetafieldDefinitionContainer = $mongo->getCollectionForTable('product_metafield_definitions_container');
                $productMetafieldDefinitionContainer->deleteMany(
                    [
                        'user_id' => $userId,
                        'shop_id' => $shop['_id']
                    ]
                );
            }

            $config = $mongo->getCollectionForTable('config');
            $config->deleteMany(
                [
                    'user_id' => $userId,
                    'source_shop_id' => $shop['_id']
                ]
            );

            $restrictedProductsCollection = $mongo->getCollectionForTable('restricted_products');
            $restrictedProductsCollection->deleteMany(
                [
                    'user_id' => $userId,
                    'shop_id' => $shop['_id']
                ]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception: deleteDataFromSpecificCollections(), Error: ' . json_encode($e->getMessage()), 'info', 'exception.log');
        }
    }
}
