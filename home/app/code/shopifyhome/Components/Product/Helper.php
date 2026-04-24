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
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Product;

use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Product\Route\Requestcontrol;
use DateTime;
use DateTimeZone;
use App\Connector\Models\QueuedTasks;
use App\Shopifyhome\Components\Mail\Seller;
use MongoDB\BSON\ObjectId;
use App\Core\Models\User;
use App\Shopifyhome\Components\Core\Common;
use Exception;

class Helper extends Common
{

    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public const SHOPIFY_QUEUE_INV_IMPORT_MSG = 'SHOPIFY_INVENTORY_SYNC';

    public function getShopifyHomeShopId(){
        $userDetailsData = $this->di->getUser()->getData();
        if(isset($userDetailsData['shops']))
        {
            $userShop = $userDetailsData['shops'];
            if(!empty($userShop))
            {
                foreach ($userShop as $shopData)
                {
                    if(isset($shopData['marketplace']) && strtolower((string) $shopData['marketplace']) == 'shopify')
                    {
                        $homeShopId = $shopData['_id'] ?? '0';
                        return $homeShopId;
                    }
                }
            }
        }

        return '0';
    }

    public function formatCoreDataForDetail($pData)
    {
        if(!is_array($pData)) {
            $this->di->getLog()->logContent(\App\Shopifyhome\Components\Product\Helper::class . '::formatCoreDataForDetail() : '.print_r($pData, true), 'info', 'invalid_product.log');
            $this->di->getLog()->logContent(var_export($pData, true), 'info', 'invalid_product.log');
        }

        $temp['shop_id'] = $pData['shop_id'] ?? $shop_id;
        $temp['user_id'] =$this->di->getUser()->id;
        $temp['source_product_id'] = (string)$pData['container_id'];
        $temp['container_id']  = (string)$pData['container_id'];
        $temp['title'] = $pData['name'] ?? $pData['title'];
        if(isset($pData['sku']))
        {
            $pData['source_sku'] = $pData['sku'];
            $pData['low_sku'] = strtolower((string) $pData['sku']);
        }
        else{
            $pData['sku'] = $pData['container_id'];
            $pData['low_sku'] = strtolower((string) $pData['sku']);
            $pData['source_sku'] = null;
        }

        $temp['description'] = $pData['description'] ?? $pData['descriptionHtml'];
        $temp['type'] =(isset($pData['variant_attributes'])&&!empty($pData['variant_attributes']))?'variation': 'simple';
        $temp['brand'] = $pData['brand'];
        $temp['product_type'] = $pData['product_type'];
        $temp['additional_images'] = $pData['images'] ?? ($pData['additional_images'] ?? []);
        $temp['is_imported'] = 1;
        $temp['visibility'] = "Catalog and Search";

        if(isset($pData['main_image'])){
            $temp['main_image'] = $pData['main_image'];
        }
        else{
            $temp['main_image'] = null;
        }

        if(isset($pData['collection']))
        {
            $temp['collection'] = $pData['collection'];
        }
        else{
            $temp['collection'] = [];
        }

        if(isset($pData['app_codes'])) $temp['app_codes'] = $pData['app_codes'];

        if(isset($pData['variant_attributes']))
        {
            $temp['variant_attributes'] = $pData['variant_attributes'];
            /*foreach ($pData['variant_attributes'] as $index => $keys)
            {
                $temp[$keys] = $pData[$keys];
            }*/
        }
        else{
            $temp['variant_attributes'] = [];
        }

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();

        $temp['source_marketplace'] = $pData['source_marketplace'] ?? ($target ?? '');

        $this->di->getLog()->logContent('SQS Data => ' . print_r($temp, true), 'info', 'webhook'.DS.$this->di->getUser()->id.DS.'webhook_resp'.DS.date("Y-m-d").DS.'webhook_resp.log');

        return $temp;
    }

    public function setRequiredFields($prepare_data,$db_data)
    {

        if(isset($db_data['db_data']) && !empty($db_data['db_data']) && !empty($prepare_data)) {
            $firstIndexData = $db_data['db_data'][0];
            unset($firstIndexData['_id']);
            foreach ($firstIndexData as $key => $value)
            {
                if(!isset($prepare_data[$key]) && $key != "marketplace")
                {
                    $prepare_data[$key] = $value;
                }
            }

            return ['success' => true, 'data' => $prepare_data];
        }
        return ['success' => false, 'message' => 'No db data found in data posted'];

        /*$requiredFields = $type=="parent" ? $this->di->getConfig()->get('required_variant_product_parent_fields') : $this->di->getConfig()->get('required_child_fields');

        //$requiredFields = $type=="parent" ? $this->di->getConfig()->get('required_variant_product_parent_fields')->toArray() : $this->di->getConfig()->get('required_child_fields')->toArray();

//        echo '<pre>';print_r($requiredFields);die('u r herer ');
        $excluded_keys = [
            "grams",
            "gram",
            "template_suffix",
            "templateSuffix"
        ];

        //print_r([$type,$data, $actual_data,$requiredFields]);die(' u r here');

        foreach ($requiredFields as $field => $value)
        {
            $value = json_decode(json_encode($value),true);

            if (!isset($data[$field]))
            {
                if(isset($actual_data[$field]))
                {
                    $data[$field] = $actual_data[$field];
                }
                else{
                    $data[$field] = $value;
                }
            }
            elseif(array_key_exists($field,$excluded_keys))
            {
                unset($data[$field]);
            }

        }

        if($type == "parent")
        {
            $excluded_keys_from_parent = [
                "barcode",
                "fulfillment_service",
                "inventory_item_id",
                "inventory_management",
                "inventory_policy",
                "inventory_tracked",
                "position",
                "templateSuffix",
                "variant_title"
            ];

            foreach ($excluded_keys_from_parent as $K => $ek)
            {
                unset($data[$ek]);
            }

        }*/
    }

    public function shapeSqsDataForUpload($data){
        $handlerData = [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'queue_name' => 'product_upload',
            'own_weight' => 100,
            'user_id' => $data['data']['user_id'],
            'data' => $data['data'],
            'run_after' => 5,
            'method' => 'uploadProduct'
        ];
        return $handlerData;
    }

    public function formatCoreDataForVariantData($vData)
    {
        if (!empty($vData['is_bundle'])) {
            if (!$this->di->getConfig()->enable_bundle_products) {
                //fire event that bundle products are skipped
                $this->di->getEventsManager()->fire('application:shopifyBundleProductSkipped', $this, $vData);
                return [];
            }

            $userId = $this->di->getUser()->id;

            $bundleSettings = $this->di->getConfig()->bundle_product_settings;
            $isRestrictedPerUser = $bundleSettings->restrict_per_user ?? true;
            $allowedUserIds = $bundleSettings->allowed_user_ids ? $bundleSettings->allowed_user_ids->toArray() : [];

            if ($isRestrictedPerUser && !in_array($userId, $allowedUserIds, true)) {
                //fire event that bundle products are skipped
                $this->di->getEventsManager()->fire('application:shopifyBundleProductSkipped', $this, $vData);
                return [];
            }
        }

        if(!is_array($vData)) {
            $this->di->getLog()->logContent(\App\Shopifyhome\Components\Product\Helper::class . '::formatCoreDataForVariantData() : '.print_r($vData, true), 'info', 'invalid_product.log');
        } elseif ( (isset($vData['status']) && (strtolower((string) $vData['status']) == "draft" || strtolower((string) $vData['status']) == "archived") )  && 
            empty($vData['db_data']['db_data']) )
        {
            return [];
        }
        else
        {
            $data_in_db = $vData['db_data'] ?? [];
            unset($vData['db_data']);

            $vData['shop_id'] ??= null;
            $vData['type'] = 'simple';
            $vData['user_id'] = $this->di->getUser()->id;
            $vData['source_product_id'] = $vData['source_variant_id'] ?? (string)$vData['id'];
            $vData['container_id'] = (string)$vData['container_id'];

            if(isset($vData['inventory_item_id'])) $vData['inventory_item_id'] = (string)$vData['inventory_item_id'];

            $vData['source_created_at'] = $vData['source_created_at'] ?? $vData['created_at'];
            // $vData['created_at'] = date('c');
            $vData['source_updated_at'] =  $vData['source_updated_at'] ??  $vData['updated_at'];

            if (isset($vData['price'])) {
                $vData['price'] = floatval($vData['price']);
            }

            $vData['is_imported'] = 1;
            $date = new DateTime("now", new DateTimeZone('GMT') );
            $vData['updated_at'] = $date->format('Y-m-d\TH:i:sO');
            if(array_key_exists('offer_price', $vData))
            {
                $vData['compare_at_price'] = $vData['offer_price'];
                $vData['offer_price'] = floatval($vData['offer_price']);
            }

            if(isset($vData['id'])) unset($vData['id']);

            if(isset($vData['sku']) && !empty($vData['sku']))
            {
                $vData['source_sku'] = $vData['sku'];
            }
            else{
                $vData['sku'] = $vData['source_product_id'];
                $vData['source_sku'] = '';

            }

            if(isset($vData['tags']) && !is_array($vData['tags']))
            {
                //$vData['tags'] = explode(',',$vData['tags']);
                $vData['tags'] = array_map('trim', explode(',', (string) $vData['tags']));
            }

            if(!isset($vData['main_image']))
            {
                $vData['main_image'] = '';
            }

            if(!isset($vData['images']) && !isset($vData['additional_images']))
            {
                $vData['additional_images'] = [];
            }

            //$vData['source_sku'] = !empty($vData['sku']) ? $vData['sku'] : '';

            $rData = [];

            foreach ($vData as $key => $value)
            {
                if ($key == "name") $key = "title";

                if ($key == "images") $key = "additional_images";

                if (strpos($key, '$') && strpos($key, '.')) {
                    $this->di->getLog()->logContent("Key = " . $key . " value = " . $value . ' container_id = ' . $vData['container_id'], 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . 'test.log');
                    $key = str_replace("$", "U+FF04", $key);
                    $key = str_replace(".", "U+FF0E", $key);
                }

                if (strpos($key, '.')) {
                    $this->di->getLog()->logContent("Key = " . $key . " value = " . $value . ' container_id = ' . $vData['container_id'], 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . 'test.log');
                    $key = str_replace(".", "U+FF0E", $key);
                } elseif (strpos($key, '$')) {
                    $this->di->getLog()->logContent("Key = " . $key . " value = " . $value . ' container_id = ' . $vData['container_id'], 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . 'test.log');
                    $key = str_replace("$", "U+FF04", $key);
                }

                $rData[$key] = $value;
            }

            /*if(!empty($data_in_db))
            {
                $prepare_data = $this->setRequiredFields($rData, $data_in_db);
                if(isset($prepare_data['success'],$prepare_data['data']) && !empty($prepare_data['data']))
                {
                    $rData = $prepare_data['data'];
                }
            }*/

            return $rData;
        }
    }

    /**
     * Function for formatting variant attribute values when product is created or updated by webhook
     *
     * @param array $productData
     * @return void
     */
    public function formatVariantAttributeValuesByWebhook($productData, $shopId)
    {
        if (empty($productData)) {
            return [];
        }

        $modifiedProductData = [];
        $finalAttributes = [];
        foreach ($productData as $product) {
            if (!empty($product['variant_attributes']) && $product['variant_title'] != 'Default Title') {
                foreach ($product['variant_attributes'] as $key => $variant_attribute) {
                    if (!empty($finalAttributes)) {
                        foreach ($finalAttributes as $key => $attr) {
                            $all_attributes = array_column($finalAttributes, 'key');
                            if (isset($product[$variant_attribute], $finalAttributes[$key]['value']) && ($attr['key'] == $variant_attribute) && (!in_array($product[$variant_attribute], $finalAttributes[$key]['value']))) {
                                $finalAttributes[$key]['value'][] = $product[$variant_attribute] ?? '';
                            } elseif (!in_array($variant_attribute, $all_attributes)) {
                                $finalAttributes[] = [
                                    'key' => $variant_attribute,
                                    'value' => isset($product[$variant_attribute]) ? [$product[$variant_attribute]] : []
                                ];
                            }
                        }
                    } else {
                        $finalAttributes[] = [
                            'key' => $variant_attribute,
                            'value' => isset($product[$variant_attribute]) ? [$product[$variant_attribute]] : []
                        ];                        
                    }
                }
            } else {
                $finalAttributes = [];
            }
        }

        foreach ($productData as $product)
        {

            $product['variant_attributes_values'] = $finalAttributes;
            $modifiedProductData[] = $product;
        }

        $this->updateParentAttributeValues($finalAttributes, $modifiedProductData, $shopId);
        return $modifiedProductData;
    }

    /**
     * Function for updating variant attrbiute values in parent product or already existing simple product
     *
     * @param array $finalAttributes
     * @param array $modifiedProductData
     */
    public function updateParentAttributeValues($finalAttributes, $modifiedProductData, $shopId): void
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("product_container");
        $filter_for_variation = [
            'user_id' => $modifiedProductData[0]['user_id'],
            'container_id' => $modifiedProductData[0]['container_id'],
            'shop_id' => $shopId,
            'type' => 'variation',
            'visibility' => 'Catalog and Search'
        ];
        $filter_for_simple = [
            'user_id' => $modifiedProductData[0]['user_id'],
            'container_id' => $modifiedProductData[0]['container_id'],
            'shop_id' => $shopId,
            'type' => 'simple',
            'visibility' => 'Catalog and Search'
        ];
        $parent = $collection->findOne($filter_for_variation);
        $simple = $collection->findOne($filter_for_simple);
        if (!empty($parent)) {
            $collection->updateOne(
                $filter_for_variation,
                ['$set' => 
                    [
                        'variant_attributes_values' => $finalAttributes
                    ]
                ]
            );
        } elseif (!empty($simple)) {
            $collection->updateOne(
                $filter_for_simple,
                ['$set' => 
                    [
                        'variant_attributes_values' => $finalAttributes
                    ]
                ]
            );
        }
    }

    public function shapeSqsData($userId, $remoteShopId)
    {
        try{
            $queuedTask = new QueuedTasks;
            $queuedTask->set([
                'user_id' => (int)$userId,
                'message' => Helper::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : initializing ...',
                'progress' => 0.00,
                'shop_id' => (int)$userId
            ]);
            $status = $queuedTask->save();
        } catch (Exception $e){
            // echo $e->getMessage();
        }

        $handlerData = [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'method' => 'fetchShopifyProduct',
            'queue_name' => 'shopify_product_import',
            'own_weight' => 100,
            'user_id' => $userId,
            'data' => [
                'user_id' => $userId,
                'individual_weight' => 0,
                'feed_id' => ($status ? $queuedTask->id : false),
                'pageSize' => 250,
                'remote_shop_id' => $remoteShopId // todo : replace with output function made by anshuman
            ]
        ];

        return $handlerData;
    }

    public function shapeSqsDataForUploadProducts($userId,$remoteShopId,$productCount,$source,$profileId,$sourceShopId,$targetShopId,$productUploadedCount,$sync_status,$filePath,$fileName,$productQuery = false){
        try{
            $queuedTask = new QueuedTasks;
            $queuedTask->set([
                'user_id' => $userId,
                'message' => Helper::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : initializing ...',
                'progress' => 0.00,
                'shop_id' => $userId
            ]);
            $status = $queuedTask->save();
        } catch (Exception $e){
            // echo $e->getMessage();
        }

        $handlerData = [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'method' => 'uploadProduct',
            'queue_name' => 'product_upload',
            'own_weight' => 100,
            'user_id' => $userId,
            'data' => [
                'product_query' => $productQuery,
                'cursor' => 0,
                'uploaded_product_count' => $productUploadedCount,
                'sync_status' => $sync_status,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'success_message' => 'All selected products successfully uploaded to Shopify',
                'failure_message' => 'Failed to upload all products to Shopify',
                'profileId' => $profileId,
                'source_shop_id' => $sourceShopId,
                'target_shop_id' => $targetShopId,
                'total_count' => $productCount,
                'source' => $source,
                'max_limit' => $productCount,
                'page' => 1,
                'user_id' => $userId,
                'individual_weight' => 0,
                'feed_id' => ($status ? $queuedTask->id : false),
                'pageSize' => 250,
                'remote_shop_id' => $remoteShopId // todo : replace with output function made by anshuman
            ]
        ];
        return $handlerData;
    }

    public function getAndQuery($query,$user_id=false){
        $user_id ??= $this->di->getUser()->id;
        $andQuery=[
            '$and'=>[
                ['user_id'=>$user_id],
                $query
            ]
        ];
        return $andQuery;
    }

    public function appendMissingWebhookDataFromContainer($data){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongoCollection = $mongo->setSource("product_container")->getPhpCollection();

        $out = $mongoCollection->aggregate(['$match' => ['source_variant_id' => ['$eq' => $data['source_product_id']]]])->toArray();

        if($out && (count($out) > 0)){
            $temp = json_decode(json_encode($out[0]->variants), true);
            if(isset($temp['collection'])){
                $data['collection'] = $temp['collection'];
            }
        }

        $this->di->getLog()->logContent('PROCESS 0000201 | appendMissingWebhookDataFromContainer ','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'webhook_product_import.log');
        return [
            'data' => $data ,
            'variantAgr' => $out
        ];
    }

    public function ifEligibleSendMail($userId): void
    {
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $mongoCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection->setSource('user_details');

        $phpMongoCollection = $mongoCollection->getPhpCollection();

        $mongoResponse = $phpMongoCollection->findOne(['user_id' => (string)$userId], ['projection' =>
            ['import_mail' => 1, 'user_id' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array']]);

        if(!isset($mongoResponse['import_mail'])){
            $phpMongoCollection->updateOne(['user_id' => (string)$userId], ['$set' =>
                ['import_mail' => 1]]);

            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $user_details_shopify = $user_details->getDataByUserID((string)$userId, $target);

            $this->di->getObjectManager()->get(Seller::class)->imported($user_details_shopify);
        }
    }

    public function resetImportFlag($userId = '')
    {
        $userId = !empty($userId) ? $userId : $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getPhpCollection();
        try{
            $response = $collection->updateMany(['user_id'=>$userId],['$set' => ['is_imported' => 0]], ['ordered' => false]);
        } catch (Exception){}

        return [
            'success' => true,
            'match_count' => isset($response) ? $response->getMatchedCount() : 0,
            'modified_count' => isset($response) ? $response->getModifiedCount() : 0
        ];
    }

    public function deleteNotExistingProduct($userId = '', $append = ''): void
    {
        $userId = !empty($userId) ? $userId : $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container_".$userId)->getPhpCollection();

        $query = ['details.is_imported' => 0]; $options = ['projection' => ['details.source_product_id' => 1 ]  ];
        $cursor = $collection->find($query, $options);

        $sourceProductId = [];
        foreach ($cursor as $document) {
            $sourceProductId[] = $document['details']['source_product_id'];
        }

        $mongoData = $this->di->getObjectManager()->get(Data::class);
        if($sourceProductId !== []){
            $ids = array_chunk($sourceProductId, 75);
            foreach ($ids as $pid){
                $this->di->getLog()->logContent('PROCESS 000000 | Helper | init Product Delete | Batch :'.json_encode($pid),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.$append.'product_import.log');
                $fbResponse = $this->di->getObjectManager()->get('\App\Facebookhome\Components\Helper')->deleteProduct(['selected_products' => $pid]);
                $this->di->getLog()->logContent('facebook response :'.json_encode($fbResponse),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.$append.'product_import.log');
            }

            foreach ($sourceProductId as $id){
                /*$productContainer = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');*/
                $response = $mongoData->deleteIndividualVariants($id, [], true);
            }
        }

        $options = [];
        $pipeline = [
            [
                '$unwind' => [
                    'path' => '$variants'
                ]
            ],
            [
                '$match' => [
                    'variants.is_imported' => 0
                ]
            ],
            [
                '$project' => [
                    'variants.source_variant_id' => 1,
                    'variants.container_id' => 1,
                    'details.source_product_id'=>1
                ]
            ]
        ];
        $cursor = $collection->aggregate($pipeline, $options);
        $sourceVariantId = [];
        $data = [];
        foreach ($cursor as $document) {
            if(isset($document['variants']['source_variant_id'])){
                $sourceVariantId[] = $document['variants']['source_variant_id'];
            }

            if(isset($document['variants']['container_id'])){
                $data[$document['variants']['source_variant_id']] = $document['variants']['container_id'];
            }

            if(!isset($document['variants']['source_variant_id']) && !isset($document['variants']['container_id'])){
                $collection->updateOne(
                    ['details.source_product_id' => $document['details']['source_product_id']],
                    ['$pull' => [
                        "variants" => [
                            "is_imported" =>0
                        ]
                    ]
                    ]
                );
            }

        }

        $this->di->getLog()->logContent('PROCESS 000000 | Helper | deleteNotExistingProduct |Variant Product Id : '.json_encode($sourceVariantId).'  | USER ID :'. $userId,'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.$append.'product_import.log');

        if($sourceVariantId !== []){
            foreach ($data as $variantId => $containerId){
                $mongoData->deleteIndividualVariants($containerId, [(string)$variantId], false, $userId);
                print_r($mongoData->deleteIndividualVariants($containerId, [(string)$variantId], false, $userId));
            }

            /* $fbResponse = $this->di->getObjectManager()->get('\App\Facebookhome\Components\Helper')->deleteVariant(['selected_variants' => $sourceVariantId]);*/
        }

        $this->di->getLog()->logContent('PROCESS 000000 | Helper | deleteNotExistingProduct | Deletion Completed | ','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.$append.'product_import.log');


    }

    public function metafieldSyncSingleUser($params = [])
    {
        $userId = $params['user_id'] ?? $this->di->getUser()->id;
        $logFile = "shopify/metafieldSync/singleUser/{$userId}/" . date('d-m-Y') . '.log';
        if (!empty($userId) && isset($params['value'])) {
            $this->di->getLog()->logContent('Starting Process for UserId: '
                . json_encode($userId, true), 'info', $logFile);
            $response = $this->setDiForUser($userId);
            if (isset($response['success']) && $response['success']) {
                $shops = $this->di->getUser()->shops ?? [];
                $shopData = [];
                $marketplace = $this->di->getObjectManager()
                    ->get(Shop::class)->getUserMarkeplace();
                if (!empty($shops)) {
                    foreach ($shops as $shop) {
                        if ($shop['marketplace'] == $marketplace
                            && $shop['apps'][0]['app_status'] == 'active' &&
                            !isset($shop['store_closed_at'])) {
                            $shopData = $shop;
                            break;
                        }
                    }
                }

                if (!empty($shopData)) {
                    $appCode = $shopData['apps'][0]['code'] ?? 'default';
                    $appTag = $params['app_tag'] ?? $this->di->getAppCode()->getAppTag();
                    $this->di->getAppCode()->setAppTag($appTag);
                    $processCode = 'metafield_import';
                    $queueData = [
                        'user_id' => $userId,
                        'message' => "Just a moment! We're retrieving your products metafield from the source catalog. We'll let you know once it's done.",
                        'process_code' => $processCode,
                        'marketplace' => $shop['marketplace']
                    ];
                    $queuedTask = new QueuedTasks;
                    $queuedTaskId = $queuedTask->setQueuedTask($shopData['_id'], $queueData);
                    $this->di->getLog()->logContent('queuedTaskId: '
                        . json_encode($queuedTaskId), 'info', $logFile);
                    if (!$queuedTaskId) {
                        return ['success' => false, 'message' => 'Metafield Import process is already under progress. Please check notification for updates.'];
                    }
                    if (isset($queuedTaskId['success']) && !$queuedTaskId['success']) {
                        return $queuedTaskId;
                    }

                    $prepareData = [
                        'type' => 'full_class',
                        'appCode' => $appCode,
                        'appTag' => $appTag,
                        'class_name' => \App\Shopifyhome\Components\Product\Vistar\Route\Requestcontrol::class,
                        'method' => 'handleImport',
                        'queue_name' => 'shopify_metafield_import',
                        'own_weight' => 100,
                        'user_id' => $userId,
                        'data' => [
                            'operation' => 'make_request',
                            'user_id' => $userId,
                            'remote_shop_id' => $shopData['remote_shop_id'],
                            'app_code' => $appCode,
                            'individual_weight' => 1,
                            'feed_id' => $queuedTaskId,
                            'webhook_present' => true,
                            'temp_importing' => false,
                            'isImported' => false,
                            'shop' => $shop,
                            'process_code' => 'metafield',
                            'extra_data' => [
                                'filterType' => 'metafield'
                            ]
                        ],
                    ];
                    $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Vistar\Data::class)
                        ->pushToQueue($prepareData);
                    $this->di->getLog()->logContent('Metafield Process Started, Pushed Message =>'.json_encode($prepareData), 'info', $logFile);
                    return ['success' => true, 'message' => 'Metafield Process Initiated'];
                }
                $message = 'Shop not found or inactive or store closed';
                $this->di->getLog()->logContent($message, 'info', $logFile);
            } else {
                $message = 'Unable to set di';
                $this->di->getLog()->logContent($message, 'info', $logFile);
            }
        } else {
            $message = 'Required params missing or user not found';
            $this->di->getLog()->logContent($message, 'info', $logFile);
        }

        return ['success' => false, 'message' => $message ?? 'Something Went Wrong'];
    }

    public function metafieldSyncCRON($params = [])
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $config = $mongo->getCollectionForTable('config');
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $logFile = "shopify/metafieldSync/allUsers/" . date('d-m-Y') . '.log';
        $pipline = [
            [
                '$match' => [
                    'group_code' => 'product',
                    'key' => 'metafield',
                    'value' => true
                ],
            ],
            ['$project' => ['user_id' => 1]],
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

        $userData = $config->aggregate($pipline, $arrayParams)->toArray();
        $message = 'Whole Process Completed';
        if (!empty($userData)) {
            foreach ($userData as $user) {
                $userId = $user['user_id'];
                $priorityMetafieldSyncUsers = $this->di->getConfig()->get('priority_metafield_sync_users') ? $this->di->getConfig()->get('priority_metafield_sync_users')->toArray() : [];
                if(!empty($priorityMetafieldSyncUsers) && in_array($userId, $priorityMetafieldSyncUsers)) {
                    $this->di->getLog()->logContent('Skipping Priority Metafield Sync User: '.json_encode($userId), 'info', $logFile);
                    continue;
                }
                $this->di->getLog()->logContent('Starting Process for UserId: ' .
                    json_encode($userId), 'info', $logFile);
                $response = $this->setDiForUser($userId);
                if (isset($response['success']) && $response['success']) {
                    $shops = $this->di->getUser()->shops ?? [];
                    $shopData = [];
                    if (!empty($shops)) {
                        foreach ($shops as $shop) {
                            if ($shop['marketplace'] == 'shopify'
                                && $shop['apps'][0]['app_status'] == 'active' &&
                                !isset($shop['store_closed_at'])) {
                                $shopData = $shop;
                                break;
                            }
                        }
                    }

                    if (!empty($shopData)) {
                        $appCode = $shopData['apps'][0]['code'] ?? 'default';
                        $appTag = $params['app_tag'] ?? $this->di->getAppCode()->getAppTag();
                        $this->di->getAppCode()->setAppTag($appTag);
                        $processCode = 'metafield_import';
                        $queueData = [
                            'user_id' => $userId,
                            'message' => "Just a moment! We're retrieving your products metafield from the source catalog. We'll let you know once it's done.",
                            'process_code' => $processCode,
                            'marketplace' => $shop['marketplace']
                        ];
                        $queuedTask = new QueuedTasks;
                        $queuedTaskId = $queuedTask->setQueuedTask($shopData['_id'], $queueData);
                        if (!$queuedTaskId) {
                            if ($this->checkMetafieldProgressStuck($userId, $shopData)) {
                                $queuedTask = new QueuedTasks;
                                $queuedTaskId = $queuedTask->setQueuedTask($shopData['_id'], $queueData);
                                $this->di->getLog()->logContent('New queued task created queuedTaskId: '.json_encode($queuedTaskId), 'info', $logFile);
                            } else {
                                $this->di->getLog()->logContent('Metafield Import process is already under progress. Please check notification for updates', 'info', $logFile);
                                continue;
                            }
                        }
                        if (isset($queuedTaskId['success']) && !$queuedTaskId['success']) {
                            $this->di->getLog()->logContent('Success false setQueueTask response: ' . json_encode($queuedTaskId), 'info', $logFile);
                            continue;
                        }

                        $prepareData = [
                            'type' => 'full_class',
                            'appCode' => $appCode,
                            'appTag' => $appTag,
                            'class_name' => \App\Shopifyhome\Components\Product\Vistar\Route\Requestcontrol::class,
                            'method' => 'handleImport',
                            'queue_name' => 'shopify_metafield_import',
                            'own_weight' => 100,
                            'user_id' => $userId,
                            'data' => [
                                'operation' => 'make_request',
                                'user_id' => $userId,
                                'remote_shop_id' => $shopData['remote_shop_id'],
                                'app_code' => $appCode,
                                'individual_weight' => 1,
                                'feed_id' => $queuedTaskId,
                                'webhook_present' => true,
                                'temp_importing' => false,
                                'shop' => $shop,
                                'isImported' => false,
                                'process_code' => 'metafield',
                                'extra_data' => [
                                    'filterType' => 'metafieldsync'
                                ]
                            ],
                        ];
                        $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Vistar\Data::class)
                            ->pushToQueue($prepareData);
                        $this->di->getLog()->logContent('Metafield Process Started, Pushed Message =>'.json_encode($prepareData), 'info', $logFile);

                    } else {
                        $this->di->getLog()->logContent('Shop not found or inactive or store closed', 'info', $logFile);
                    }
                } else {
                    $this->di->getLog()->logContent('Unable to set user in Di UserId: '
                        . json_encode($userId, true), 'info', $logFile);
                }
            }

            if (count($userData) < 500) {
                return ['success' => true, 'message' => 'Process completed for all users'];
            }

            $endUser = end($userData);
            $userId = $endUser['user_id'];
            $handlerData = [
                'type' => 'full_class',
                'class_name' => \App\Shopifyhome\Components\Product\Helper::class,
                'method' => 'metafieldSyncCRON',
                'queue_name' => 'shopify_process_metafield_users',
                'user_id' => $userId,
                '_id' => $endUser['_id']
            ];
            $sqsHelper->pushMessage($handlerData);
            $this->di->getLog()->logContent('Process Completed for these users', 'info', $logFile);
            $message = 'Processing from Queue...';
        }

        $this->di->getLog()->logContent($message, 'info', $logFile);
        return ['success' => true, 'message' => $message];
    }

    public function customMetafieldSyncCron($params = [])
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $config = $mongo->getCollectionForTable('config');
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $logFile = "shopify/metafieldSync/allUsers/" . date('d-m-Y') . '.log';
        $pipline = [
            [
                '$match' => [
                    'group_code' => 'product',
                    'key' => 'metafield',
                    'value' => true
                ],
            ],
            ['$project' => ['user_id' => 1]],
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

        $userData = $config->aggregate($pipline, $arrayParams)->toArray();
        $message = 'Whole Process Completed';
        if (!empty($userData)) {
            $metafieldObj = $this->di->getObjectManager()->get('App\Shopifyhome\Components\Product\Metafield');
            $priorityMetafieldSyncUsers = $this->di->getConfig()->get('priority_metafield_sync_users') ? $this->di->getConfig()->get('priority_metafield_sync_users')->toArray() : [];
            foreach ($userData as $user) {
                if(!empty($priorityMetafieldSyncUsers) && in_array($user['user_id'], $priorityMetafieldSyncUsers)) {
                    $this->di->getLog()->logContent('Skipping Priority Metafield Sync User: '.json_encode($user['user_id']), 'info', $logFile);
                    continue;
                }
                $requestParams = [
                    'user_id' => $user['user_id'],
                    'filter_type' => 'metafieldsync',
                    'app_tag' => $params['app_tag']
                ];
                $metafieldObj->initiateMetafieldSync($requestParams);
            }

            if (count($userData) < 500) {
                return ['success' => true, 'message' => 'Process completed for all users'];
            }

            $endUser = end($userData);
            $handlerData = [
                'type' => 'full_class',
                'class_name' => \App\Shopifyhome\Components\Product\Helper::class,
                'method' => 'customMetafieldSyncCron',
                'queue_name' => 'shopify_process_metafield_users',
                'user_id' => $endUser['user_id'],
                '_id' => $endUser['_id']
            ];
            $sqsHelper->pushMessage($handlerData);
            $this->di->getLog()->logContent('Process Completed for these users', 'info', $logFile);
            $message = 'Processing from Queue...';
        } else {
            $message = 'All users processed';
        }
        $this->di->getLog()->logContent($message, 'info', $logFile);
        return ['success' => true, 'message' => $message];
    }

    /**
     * checkMetafieldProgressStuck function
     * Function to reinitiate metafield process if stuck from last 24 hours
     * @param [string] $userId
     * @param [array] $shop
     * @return bool
    */
    private function checkMetafieldProgressStuck($userId, $shop)
    {
        try {
            $logFile = "shopify/metafieldSync/allUsers/" . date('d-m-Y') . '.log';
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $queuedTaskContainer = $mongo->getCollection("queued_tasks");
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $queuedTaskData = $queuedTaskContainer->findOne([
                'user_id' => $userId,
                'shop_id' => $shop['_id'],
                'process_code' => 'metafield_import',
                'marketplace' => 'shopify'
            ], $options);
            if (isset($queuedTaskData['updated_at'])) {
                $updatedAtDate = \DateTime::createFromFormat('Y-m-d H:i:s', $queuedTaskData['updated_at']);
                $now = new \DateTime();
                if ($updatedAtDate instanceof \DateTime) {
                    $intervalInSeconds = $now->getTimestamp() - $updatedAtDate->getTimestamp();
                    if ($intervalInSeconds > 23 * 3600) {
                        $this->di->getLog()->logContent('Metafield Sync Stuck for user: ' . json_encode($queuedTaskData), 'info', $logFile);
                        $queuedTaskContainer->deleteOne([
                            '_id' => $queuedTaskData['_id']
                        ]);
                        return true;
                    }
                }
            } elseif(isset($queuedTaskData['created_at'])) {
                $createdAtDate = new \DateTime($queuedTaskData['created_at']);
                $now = new \DateTime();
                if ($createdAtDate instanceof \DateTime) {
                    $intervalInSeconds = $now->getTimestamp() - $createdAtDate->getTimestamp();
                    if ($intervalInSeconds > 24 * 3600) {
                        $this->di->getLog()->logContent('Metafield Sync Stuck for user: ' . json_encode($queuedTaskData), 'info', $logFile);
                        $queuedTaskContainer->deleteOne([
                            '_id' => $queuedTaskData['_id']
                        ]);
                        return true;
                    }
                }
            }
            return false;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from global checkMetafieldProgressStuck(): ' . json_encode($e->getMessage()), 'info',  'exception.log');
        }
    }

    public function unsetAndResyncMetafieldDetails()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $config = $mongo->getCollectionForTable('config');
        $productContainer = $mongo->getCollectionForTable('product_container');
        $logFile = "shopify/metafieldReSync/" . date('d-m-Y') . '.log';
        try {
            $options = [
                "projection" => ['_id' => 0, 'user_id' => 1, 'value' => 1, 'source_shop_id' => 1, 'app_tag' => 1],
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ];
            $metafieldUsers = $config->find(
                [
                    'key' => 'metafield',
                    'group_code' => 'product',
                    'source_shop_id' => ['$ne' => null]
                ],
                $options
            )->toArray();
            if (!empty($metafieldUsers)) {
                $marketplace = $this->di->getObjectManager()
                    ->get(Shop::class)->getUserMarkeplace();
                foreach ($metafieldUsers as $user) {
                    $userId = $user['user_id'];
                    $this->di->getLog()->logContent('Starting Process for UserId: ' .
                        json_encode($userId), 'info', $logFile);
                    $productContainer->updateMany(
                        [
                            'user_id' => $userId,
                            'shop_id' => $user['source_shop_id'],
                            'source_marketplace' => $marketplace,
                            'parentMetafield' => ['$exists' => true]
                        ],
                        [
                            '$unset' => ['parentMetafield' => 1]
                        ]
                    );
                    $productContainer->updateMany(
                        [
                            'user_id' => $userId,
                            'shop_id' => $user['source_shop_id'],
                            'source_marketplace' => $marketplace,
                            'variantMetafield' => ['$exists' => true]
                        ],
                        [
                            '$unset' => ['variantMetafield' => 1]
                        ]
                    );
                    if ($user['value']) {
                        $params = [
                            'user_id' => $userId,
                            'value' => $user['value'],
                            'app_tag' => $user['app_tag'] ?? 'default'
                        ];
                        $response = $this->metafieldSyncSingleUser($params);
                        $this->di->getLog()->logContent('Metafield Syncing Initiated' .
                            json_encode($response), 'info', $logFile);
                    } else {
                        $this->di->getLog()->logContent('Metafield Syncing Disabled', 'info', $logFile);
                    }
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception unsetAndResyncMetafieldDetails(), Error : ' .
                print_r($e), 'info', $logFile);
        }
    }

    public function initiateCustomMetafieldSyncAllUsers()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $config = $mongo->getCollectionForTable('config');
        $logFile = "shopify/metafieldReSync/" . date('d-m-Y') . '.log';
        try {
            $options = [
                "projection" => ['_id' => 0, 'user_id' => 1, 'value' => 1, 'source_shop_id' => 1, 'app_tag' => 1],
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ];
            $metafieldUsers = $config->find(
                [
                    'key' => 'metafield',
                    'group_code' => 'product',
                    'source_shop_id' => ['$ne' => null],
                    'value' => true
                ],
                $options
            )->toArray();
            if (!empty($metafieldUsers)) {
                foreach ($metafieldUsers as $user) {
                    $userId = $user['user_id'];
                    $this->di->getLog()->logContent('Starting Process for UserId: ' .
                        json_encode($userId), 'info', $logFile);
                    $response = $this->setDiForUser($userId);
                    if (isset($response['success']) && $response['success']) {
                        $metafieldObj = $this->di->getObjectManager()->get('App\Shopifyhome\Components\Product\Metafield');
                        $requestParams = [
                            'user_id' => $userId,
                            'filter_type' => 'metafield',
                            'app_tag' => $user['app_tag'] ?? 'default'
                        ];
                        $metafieldResponse = $metafieldObj->initiateMetafieldSync($requestParams);
                        $this->di->getLog()->logContent('Metafield Syncing Initiated data: ' .
                            json_encode([
                                'requestParams' => $requestParams,
                                'response' => $metafieldResponse
                            ]), 'info', $logFile);
                    } else {
                        $this->di->getLog()->logContent('Unable to set di for user: ' .
                            json_encode($userId), 'info', $logFile);
                    }
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception unsetAndResyncMetafieldDetails(), Error : ' .
                print_r($e), 'info', $logFile ?? 'exception.log');
        }
    }

    public function checkProductOnShopifyAndChannel($data)
    {
        if (isset($data['user_id'], $data['shop_id']) && !empty($data['attribute_name']) && !empty($data['attribute_value'])) {
            $key = $data['attribute_name'];
            $value = $data['attribute_value'];
            $shopData = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class)
                ->getShop($data['shop_id'], $data['user_id']);

            if (!empty($shopData)) {
                $remoteShopId = $shopData['remote_shop_id'];
                $query = 'query {
                    products(first: 1, query: "' . $key . ':' . $value . '") {
                      edges {
                        node {
                          id
                          publishedOnCurrentPublication
                        }
                      }
                    }
                  }';
                $response = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($shopData['marketplace'], true)
                ->call('/shopifygql/query',[],['shop_id'=> $remoteShopId, 'query' => $query], 'POST');
                if(isset($response['success']) && $response['success'] && !empty($response['data']['products']['edges'][0]['node'])) {
                    $productData = $response['data']['products']['edges'][0]['node'];
                        if($productData['publishedOnCurrentPublication']) {
                            $publicationMessage = "Product is sales_channel assigned";
                        } else {
                            $publicationMessage = "Product is not sales_channel assigned";
                        }
                        $finalMessage = "Product exists on Shopify and " . $publicationMessage;

                    return ['success' => true, 'message' => $finalMessage];
                } else {
                    $message = 'Product not found';
                }
            } else {
                $message = 'Shop not found';
            }
        } else {
            $message = 'Required fields are missing(user_id, shop_id, argument_key, argument_value)';
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong', 'data' => $response ?? []];
    }

    public function duplicateProductOnShopify($data)
    {
        $message = null;
        $response = null;
        if (!isset($data['user_id'], $data['shop_id'], $data['productId']) || (int)($data['duplicate_count'] ?? 0) < 1) {
            return [
                'success' => false,
                'message' => 'Required fields are missing or invalid (user_id, shop_id, productId, duplicate_count)',
                'data' => []
            ];
        }

        $userId = $data['user_id'];
        $shopId = $data['shop_id'];
        $productId = trim((string)$data['productId']);
        $duplicateCount = (int)$data['duplicate_count'];
        $includeImages = array_key_exists('includeImages', $data) ? (bool)$data['includeImages'] : null;
        $newStatus = isset($data['newStatus']) && (string)$data['newStatus'] !== '' ? trim((string)$data['newStatus']) : null;

        if ($duplicateCount > 100) {
            return [
                'success' => false,
                'message' => 'duplicate_count must be between 1 and 100',
                'data' => []
            ];
        }

        $shopData = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class)
            ->getShop($shopId, $userId);
        if (empty($shopData)) {
            return ['success' => false, 'message' => 'Shop not found', 'data' => []];
        }

        $remoteShopId = $shopData['remote_shop_id'];
        $apiClient = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($shopData['marketplace'], true);

        if (strpos($productId, 'gid://') !== 0) {
            $productId = 'gid://shopify/Product/' . $productId;
        }

        $fetchProductQuery = 'query { product(id: "' . addslashes($productId) . '") { id title } }';
        $fetchResponse = $apiClient->call('/shopifygql/query', [], ['shop_id' => $remoteShopId, 'query' => $fetchProductQuery], 'POST');
        if (!isset($fetchResponse['success']) || !$fetchResponse['success']) {
            return [
                'success' => false,
                'message' => 'Failed to fetch product from Shopify',
                'data' => $fetchResponse ?? []
            ];
        }

        $productNode = $fetchResponse['data']['product'] ?? null;
        if (empty($productNode) || empty($productNode['title'])) {
            return [
                'success' => false,
                'message' => 'Product not found or has no title',
                'data' => $fetchResponse ?? []
            ];
        }

        $originalTitle = $productNode['title'];
        $timestamp = (string)time();
        $created = [];
        $errors = [];

        $mutationArgs = [
            'productId: "' . addslashes($productId) . '"',
            'newTitle: "' . addslashes($originalTitle) . '"',
        ];
        if ($includeImages !== null) {
            $mutationArgs[] = 'includeImages: ' . ($includeImages ? 'true' : 'false');
        }
        if ($newStatus !== null) {
            $mutationArgs[] = 'newStatus: ' . $newStatus;
        }

        for ($counter = 1; $counter <= $duplicateCount; $counter++) {
            $newTitle = $timestamp . '_' . $counter . '_' . $originalTitle;
            $mutationArgs[1] = 'newTitle: "' . addslashes($newTitle) . '"';
            $argsStr = implode(', ', $mutationArgs);
            $mutation = 'mutation { productDuplicate(' . $argsStr . ') { newProduct { id title } userErrors { field message } } }';
            $response = $apiClient->call('/shopifygql/query', [], ['shop_id' => $remoteShopId, 'query' => $mutation], 'POST');

            if (isset($response['success']) && $response['success']) {
                $payload = $response['data']['productDuplicate'] ?? [];
                $userErrors = $payload['userErrors'] ?? [];
                if (!empty($userErrors)) {
                    foreach ($userErrors as $err) {
                        $errors[] = ['counter' => $counter, 'title' => $newTitle, 'error' => $err['message'] ?? json_encode($err)];
                    }
                } elseif (!empty($payload['newProduct']['id'])) {
                    $created[] = [
                        'counter' => $counter,
                        'newProductId' => $payload['newProduct']['id'],
                        'title' => $newTitle
                    ];
                }
            } else {
                $errors[] = ['counter' => $counter, 'title' => $newTitle, 'error' => $response['message'] ?? 'API call failed', 'raw' => $response];
            }
        }

        $successCount = count($created);
        if ($successCount === $duplicateCount) {
            return [
                'success' => true,
                'message' => "Created {$successCount} duplicate(s) successfully.",
                'data' => ['created' => $created, 'errors' => $errors]
            ];
        }
        if ($successCount > 0) {
            return [
                'success' => true,
                'message' => "Created {$successCount} of {$duplicateCount} duplicate(s).",
                'data' => ['created' => $created, 'errors' => $errors]
            ];
        }
        return [
            'success' => false,
            'message' => 'No duplicates could be created.',
            'data' => ['created' => $created, 'errors' => $errors]
        ];
    }

    public function setDiForUser($user_id)
    {
        try {
            $getUser = User::findFirst([['_id' => $user_id]]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found.'
            ];
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin.'
            ];
        }

        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }

}