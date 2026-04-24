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

 use App\Core\Components\Concurrency;
 use App\Shopifyhome\Components\Shop\Shop;
 use App\Shopifyhome\Components\Utility;
 use function GuzzleHttp\json_decode;
 use App\Shopifyhome\Components\Core\Common;
 use App\Core\Models\User;
 use App\Connector\Components\Route\ProductRequestcontrol;
 use Exception;

class Import extends Common
{
    use Concurrency;

    public const MARKETPLACE_CODE = 'shopify';

    const BATCH_IMPORT_ADD_ACTION = 'product_listings_add';

    public function _construct(): void
    {
        /* $this->_callName = 'shopify_core';
        parent::_construct(); */
        /*$this->di->getLog()->logContent('Home Shopify : indside constructor of Import.php','info','shopify_product_import.log');*/
    }

    public function getShopifyProducts($productData, $mode = '')
    {
        $this->di->getLog()->logContent(' PRODUCT DATA '.print_r($productData,true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.$mode.'product_import1.log');
        $helper = $this->di->getObjectManager()->get(Helper::class);
        $productContainer = $this->di->getObjectManager()->create('\App\Connector\Models\ProductContainer');
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("product_container");

        $mongoCollection = $mongo->getPhpCollection();
        $tempCount = $mainProduct = $variant = $time = $totalTime = $totalCount = 0;
        foreach ($productData['data'] as $productData){
            $totalCount++;
            $this->di->getLog()->logContent(' PROCESS 000030 | Import | getShopifyProducts | Before searching container id '.$productData['container_id'],'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.$mode.'product_import.log');

            $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(['source_product_id' => (string)$productData['id']],$this->di->getUser()->id);
            $exists = $mongoCollection->findOne($query);

            $this->di->getLog()->logContent(' PROCESS 0000301 | Import | getShopifyProducts | After searching container id '.$productData['container_id'],'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.$mode.'product_import.log');

            if(!empty($exists)){
                $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
                $productData['marketplace'] = $target;
                $productData['db_action'] = "variant_update";
                $productData['mode'] = $mode;
                $productContainer->updateProduct($helper->formatCoreDataForVariantData($productData));
            } else {
                $data['details'] = $helper->formatCoreDataForDetail($productData);
                $data['variant_attributes'] = $productData['variant_attributes'] ?? [];
                $data['variants'][] = $helper->formatCoreDataForVariantData($productData);
                $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
                $productContainer->setSource("product_container");

                $res = $productContainer->createProductsAndAttributes([$data], $target, 0, $this->di->getUser()->id);
            }

            $data = [];
        }

        $this->di->getLog()->logContent(' PROCESS 00030 | Import | getShopifyProducts | Product import completed ','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.$mode.'product_import.log');

        return [
            'success' => true,
            'message' => 'product imported'
        ];
    }

    public function updateProductandKeepLocation($productDatas, $sqsData)
    {
        if(!isset($productDatas[0])) {
            $this->di->getLog()->logContent(\App\Shopifyhome\Components\Product\Import::class . '::updateProductandKeepLocation() : '.print_r($productDatas, true), 'info', 'invalid_product.log');
            $this->di->getLog()->logContent(var_export($productDatas, true), 'info', 'invalid_product.log');
        }


        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $helper = $this->di->getObjectManager()->get(Helper::class);
        $productContainer = $this->di->getObjectManager()->create('\App\Connector\Models\ProductContainer');
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection = $mongo->getCollection("product_container");

        $variantIds = [];
        $containerId = '';
        $newProductFlag = false;
        $alreadyCallShopifyProductapi = [];

        try {
            $inventoryItemIds = [];
            $containerExist = [];
            $iswrapperUpdate = false;
            $productTemplateAssigned = false;
            $productTemplateAssignedArray = [];
            $wrapperData = [];
            $shopId = $sqsData['shop_id'];
            $userId = $sqsData['user_id'];
            $allProductExist = [];

            /*if(!empty($productDatas)){
                $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
                $allProductExist = $mongoCollection->find(['container_id' => (string) $productDatas[0]['container_id']],$options)->toArray();

            }*/

            foreach ($productDatas as $productData)
            {
                $containerId = $productData['container_id'];

               /* $coreData = $helper->formatCoreDataForDetail($productData, "update");
                $containerId = $coreData['source_product_id'];*/

                $query = $this->di->getObjectManager()
                    ->get(Helper::class)
                    ->getAndQuery(
                        [
                            'source_product_id' => (string)$productData['source_product_id'],
                            'shop_id' => $shopId,
                        ],$this->di->getUser()->id);

                $ProductExists = $mongoCollection->findOne($query);
                $ProductExists = \GuzzleHttp\json_decode(\GuzzleHttp\json_encode($ProductExists),true);

                if(empty($containerExist))
                {
                    $containerQuery = $this->di->getObjectManager()
                        ->get(Helper::class)
                        ->getAndQuery(
                            [
                                'container_id' => (string)$productData['container_id'],
                                'visibility' => 'Catalog and Search',
                                'shop_id' => $shopId,

                            ],$this->di->getUser()->id
                        );

                    $containerExist = $mongoCollection->findOne($containerQuery);
                    $containerExist=\GuzzleHttp\json_decode(\GuzzleHttp\json_encode($containerExist),true);

                }

                if(!$productTemplateAssigned)
                {
                    $productTemplateAssignedArrayTemp = $ProductExists['profile'] ?? $containerExist['profile'] ?? [];

                    if(!empty($productTemplateAssignedArrayTemp))
                    {
                        $productTemplateAssigned = true;
                        $productTemplateAssignedArray = $productTemplateAssignedArrayTemp;
                    }
                }

                if(!empty($productTemplateAssignedArray))
                {
                    $productData['profile'] = $productTemplateAssignedArray;
                }

                if(isset($sqsData['app_code'])) {
                    $app_code = $sqsData['app_code'];
                    if(isset($ProductExists['app_codes'])) {
                        if(!in_array($app_code, $ProductExists['app_codes'])) {
                            $ProductExists['app_codes'][] = $app_code;
                            $productData['app_codes'] = $ProductExists['app_codes'];
                        }else {
                            $productData['app_codes'] = $ProductExists['app_codes'];
                        }
                    }
                    else {
                        $productData['app_codes'] = [$app_code];
                    }
                }

                $productData['shop_id'] = $sqsData['shop_id'] ?? null;

                if(!empty($containerExist) && empty($wrapperData))
                {

                    $wrapperData['details'] = $helper->formatCoreDataForDetail($productData);

                    $updatWrapperDetails = $this->validateWrapper($containerExist,$wrapperData['details']);

                    if(!empty($updatWrapperDetails))
                        $wrapperData['details'] = array_merge($wrapperData['details'],$updatWrapperDetails);
                }

                if(isset($productData['variant_attributes']) && !empty($productData['variant_attributes']))
                {
                    $productData['type'] =  'variation';
                    $iswrapperUpdate = true;
                }
                else{
                    $productData['type'] = 'simple';
                    $productData['variant_attributes'] = [];
                    $iswrapperUpdate = true;
                }

                //if(empty($ProductExists) || empty($containerExist))
                if(empty($ProductExists))
                {
                    //optimisation required
                    if(isset($productData['inventory_item_id'])){
                        $inventoryItemIds[] = $productData['inventory_item_id'];
                    }
                    else
                    {
                        if(empty($alreadyCallShopifyProductapi))
                        {
                            $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
                            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');

                            $user_details = $user_details->getDataByUserID($sqsData['user_id'], $target);

                            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                                ->init($target, 'true')
                                ->call('/product',['Response-Modifier'=>'0'],['ids'=>$productData['container_id'],'shop_id'=>$user_details['remote_shop_id'],'sales_channel'=>0], 'GET');

                            if(isset($remoteResponse['success']) && $remoteResponse['success'] === true && isset($remoteResponse['data']))
                            {
                                $alreadyCallShopifyProductapi = $remoteResponse['data'];

                                $allShopifyProductVar = $alreadyCallShopifyProductapi['products']['0']['variants'];
                                $inventoryItemIds = [];

                                foreach ($allShopifyProductVar as $oneShopifyProductVar) {

                                    if($oneShopifyProductVar['id'] == $productData['id'])
                                    {
                                        $productData['inventory_item_id'] = $oneShopifyProductVar['inventory_item_id'];
                                    }

                                    $inventoryItemIds[] = $oneShopifyProductVar['inventory_item_id'];
                                }
                            }

                        }
                        else
                        {
                            $allShopifyProductVar = $alreadyCallShopifyProductapi['products']['0']['variants'];
                            $inventoryItemIds = [];

                            foreach ($allShopifyProductVar as $oneShopifyProductVar) {

                                if($oneShopifyProductVar['id'] == $productData['id'])
                                {
                                    $productData['inventory_item_id'] = $oneShopifyProductVar['inventory_item_id'];
                                }

                                $inventoryItemIds[] = $oneShopifyProductVar['inventory_item_id'];
                            }
                        }
                    }

                    $data['details'] = $helper->formatCoreDataForDetail($productData);

                    /*if(!empty($containerExist) && !$iswrapperUpdate){*/

                    if(isset($productData['variant_attributes']) && !empty($productData['variant_attributes']))
                    {
                        $updatWrapperDetails = $this->validateWrapper($containerExist,$data['details']);

                        if(!empty($updatWrapperDetails))
                        {
                            $data['details']['type'] = 'variation';
                            $data['details'] = array_merge($data['details'],$updatWrapperDetails);
                            $data['is_wrapper_update'] = true;

                        }

                        $iswrapperUpdate = true;

//                        $wrapperData  = [];
                    }

                    $data['variant_attributes'] = $productData['variant_attributes'] ?? [];
                    // $productData['type'] =  'variation';
                    if(isset($productData['group_id']))
                    {
                        $productData['type'] =  'variation';
                    }

                    $newPro = $helper->formatCoreDataForVariantData($productData);
                    $newPro['type'] =  'simple';
                    $data['variants'][] = $newPro;
                    $productContainer->setSource("product_container");

                    $res = $productContainer->createProductsAndAttributes([$data], Import::MARKETPLACE_CODE, 0, $this->di->getUser()->id);

                    $newProductFlag = true;

                }
                else
                {
                    $data = $helper->formatCoreDataForVariantData($productData);
                    $data['type'] =  'simple';
                    $data['_id'] = $ProductExists['_id'];

                    $unset = [];

                    $this->di->getLog()->logContent('new product flag request'.print_r($data, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'newpro.log');
                    $res = $this->handleLock('product_container', function() use ($mongoCollection, $query, $data, $unset) {

                        if(!empty($unset))
                        {
                            return $res = $mongoCollection->updateOne($query, [ '$set' => $data, '$unset' => $unset]);
                        }
                        return $res = $mongoCollection->updateOne($query, [ '$set' => $data]);
                    });

                }

                $productData = $helper->formatCoreDataForVariantData($productData);
                $variantIds[] = $productData['source_product_id'];
            }

            if(!empty($wrapperData) && !empty($containerExist))
            {
                $wrapperData['details']['type'] = $containerExist['type'];
                $wrapperData['details']['profile'] = $productTemplateAssignedArray;

            }

            $this->validateAndDeleteVariants($variantIds, $containerId, $wrapperData);

            if( $iswrapperUpdate &&
                (isset($wrapperData['details']['variant_attributes']) &&
                    !empty($wrapperData['details']['variant_attributes']) ) )
            {

//            if(!empty($wrapperData) && !$iswrapperUpdate && !empty($allProductExist) && count($allProductExist) >= 2){
                //$productContainer->setSource("product_container");
                $wrapperData['details']['type'] = 'variation';
                $productContainer->createParentProduct($wrapperData, $this->di->getUser()->id, Import::MARKETPLACE_CODE, 0,[]);
            }

            if($newProductFlag)
            {
                $this->di->getLog()->logContent('new product flag request'.print_r($sqsData, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'newpro.log');
                $this->di->getLog()->logContent('new product flag request'.print_r($sqsData, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'newpro.log');

                $this->setProductCollection($sqsData,$productData['container_id']);
                if(!empty($inventoryItemIds)){
                    $this->setProductLocation($sqsData,$inventoryItemIds);
                }
            }
        }
        catch (Exception $e) {
            $flag=$this->di->getObjectManager()->get(Utility::class)->exceptionHandler($e->getMessage());
            if($flag){
                $this->di->getLog()->logContent('Retry Product Update Webhook while exception occurs'.json_encode($sqsData),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'exception_webhook_product_import.log');
                $runAfterTime= time() + 15*60;
                $this->pushToQueue($sqsData , $runAfterTime);
                return true;
            }
            $this->di->getLog()->logContent('Exception = '.$e->getMessage(),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'another_exception.log');
        }

        return true;
    }

    public function validateWrapper($savedData,$newData){
        $updateDetails = [];
        $allowedkey = [];

        foreach ($newData as $key => $value) {
            if(isset($savedData[$key])){
                if(is_array($value)){
                    if(count($savedData[$key]) != count($value)){
                        $updateDetails[$key] = $value;
                    }
                } else {
                    if($savedData[$key] != $value){
                        $updateDetails[$key] = $value;
                    }
                }
            }
        }

        return $updateDetails;
    }

    public function updateProductInventory($userId, $inventoryItemId, $inventoryLevels)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        // $mongo->setSource("product_container");
        // $productContainerCollection = $mongo->getPhpCollection();
        $productContainerCollection = $mongo->getCollection("product_container");

        $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(['inventory_item_id'=>$inventoryItemId], $userId);
        return $productContainerCollection->updateOne($query,['$set' => ['locations' => $inventoryLevels]]);
    }

    public function updateProductCollection($userId, $productId, $collection)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        // $mongo->setSource("product_container");
        // $productContainerCollection = $mongo->getPhpCollection();
        $productContainerCollection = $mongo->getCollection("product_container");
        
        $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(['container_id'=>$productId], $userId);
        return $productContainerCollection->updateMany($query,['$set' => ['collection' => $collection]]);
    }


    public function setProductLocation($sqsData,$inventoryItemIds): void{
        $userId = $sqsData['user_id'];

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');

        $user_details = $user_details->getDataByUserID($userId, $target);

        $remoteLocationResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target, 'true')
            ->call('/shop/location',['Response-Modifier'=>'0'],['shop_id'=>$user_details['remote_shop_id'],'sales_channel'=>0], 'GET');


        if(isset($remoteLocationResponse['success']) && $remoteLocationResponse['success'])
        {
            $locationIds = [];
            foreach ($remoteLocationResponse['data']['locations'] as $key => $location) {
                $locationIds[] = $location['id'];
            }

            $inventoryItemIdChunk = array_chunk($inventoryItemIds, 50);
            $inventoryLevels = [];

            foreach ($inventoryItemIdChunk as $inventoryItemId) {
                $remoteInventoryResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                    ->init($target, 'true')
                    ->call('/inventory',['Response-Modifier'=>'0'],['shop_id'=>$user_details['remote_shop_id'],'sales_channel'=>0,'inventory_item_ids'=>$inventoryItemId,'location_ids'=>$locationIds], 'GET');
                if(isset($remoteInventoryResponse['success']) && $remoteInventoryResponse['success'])
                {
                    $inventoryLevels = array_merge($inventoryLevels,$remoteInventoryResponse['data']['inventory_levels']);
                }
            }

            if(!empty($inventoryLevels)){
                $inventoryData = [];

                foreach ($inventoryLevels as $inventoryValue) {
                    $inventoryData[$inventoryValue['inventory_item_id']][] = $inventoryValue;
                }

                $productInvUtility = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Inventory\Utility::class);
                foreach($inventoryData as $invId => $productInv) {
                    $this->updateProductInventory($userId, (string)$invId, $productInvUtility->typeCastElement($productInv));
                }
            }
        }
    }

    public function setProductCollection($sqsData,$container_id): void{

        $canImportCollection = $this->getDi()->getConfig()->get('can_import_collection') ?? true;
        if($canImportCollection === true)
        {
            $collectionImportLimit = $this->getDi()->getConfig()->get('import_collection_limit') ?? -1;

            $userId = $sqsData['user_id'];

            $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');

            $user_details = $user_details->getDataByUserID($userId, $target);

            $subQuery = <<<SUBQUERY
product_{$container_id}: product(id: "gid://shopify/Product/{$container_id}") {
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
                ->init($target, true)
                ->call('/shopifygql/query',[],['shop_id'=> $user_details['remote_shop_id'], 'query' => $query], 'POST');


            if(isset($remoteCollectionResponse['success']) && $remoteCollectionResponse['success'])
            {
                $newProductCollection = [];
                foreach($remoteCollectionResponse['data'] as $key=>$productCollection)
                {
                    $explode = explode('_', (string) $key);

                    $product_id = $explode[1];

                    if(isset($productCollection['collections']['edges']) && count($productCollection['collections']['edges']))
                    {
                        $count = 0;
                        foreach ($productCollection['collections']['edges'] as $collectionInfo)
                        {
                            $count++;
                            $newProductCollection[$product_id][] = [
                                'collection_id' => str_replace('gid://shopify/Collection/', '', $collectionInfo['node']['id']),
                                'title'         => $collectionInfo['node']['title'],
                                'gid'           => $collectionInfo['node']['id'],
                                '__parentId'    => (string)$product_id
                            ];

                            if($collectionImportLimit !== -1 && $count >= $collectionImportLimit) {
                                break;
                            }
                        }
                    }
                    else
                    {
                        $newProductCollection[$container_id][] = [];
                    }
                }

                foreach($newProductCollection as $proId => $collection) {
                    $this->updateProductCollection($userId, (string)$proId, $collection);
                }
            }
        }
    }

    public function validateAndDeleteVariants($variantIdsOnShopify, $containerId, $containerExist = []): void
    {
        $mongoData = $this->di->getObjectManager()
            ->get(Data::class);

        $currentVariantIdsInDb = $mongoData->getallVariantIds($containerId);

        $deleteIds = array_diff($currentVariantIdsInDb, $variantIdsOnShopify);

        $this->di->getLog()->logContent('Delete variants(s) Ids'.print_r($deleteIds,true)." incomingWebhookProductIds = ".print_r($variantIdsOnShopify,true)." currentVariantIds = ".print_r($currentVariantIdsInDb,true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'webhook_product_import.log');

        $mongo = $this->di->getObjectManager()
            ->create('\App\Core\Models\BaseMongo');

        //$mongo->setSource("product_container");
        $mongoCollection = $mongo->getCollection("product_container");

        if(empty($containerExist))
        {
            $containerQuery = $this->di->getObjectManager()
                ->get(Helper::class)
                ->getAndQuery(['container_id' => (string)$containerId],$this->di->getUser()->id);

            $containerExist = $mongoCollection->findOne($containerQuery);

            $containerExist = json_decode(\GuzzleHttp\json_encode($containerExist),true);
        }
        else{
            $containerExist = $containerExist['details'] ?? $containerExist;
        }

        if(!empty($deleteIds))
        {

            //$manageSimpleToVariant = $mongoData->manageSimpleToVariant($deleteIds, $currentVariantIdsInDb, $variantIdsOnShopify, $containerId);

            if($containerExist)
            {
                $user_id = $containerExist['user_id'] ?? $this->di->getUser()->id;

                if(isset($containerExist['variant_attributes']) && !empty($containerExist['variant_attributes']))
                {
                    //this condition says that product has variant attributes so 2 json data will be there in db
                    $deleteRequest = $mongoData->deleteNotRequiredJson($containerId, $deleteIds, false, $user_id);
                }
                else
                {

                    //this is a pure simple product which means product does not has variant attributes
                    $deleteRequest = $mongoData->deleteNotRequiredJson($containerId, $deleteIds,true, $user_id);
                }

            }
            else{
                $this->di->getLog()->logContent('$container_data not found','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'webhook_product_import.log');

            }

            /*if(count($currentVariantIdsInDb)-count($deleteIds) == 1)
            {

//                $mongoData->deleteIndividualVariants($containerId, $deleteIds,true);
//                $mongoData->deleteIndividualVariants($containerId, $deleteIds);
            }else{
//                $mongoData->deleteIndividualVariants($containerId, $deleteIds);
            }*/
            /*$this->di->getLog()->logContent('PROCESS 000031 | Import | updateProductandKeepLocation | facebook Response to delete variants(s) ','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'webhook_product_import.log');*/
        }
        else
        {
            $this->di->getLog()->logContent('$containerExist data sent'.json_encode($containerExist),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'test'.DS.'data.log');

            if($containerExist)
            {
                //if( (isset($containerExist['variant_attributes']) && empty($containerExist['variant_attributes'])) || !isset($containerExist['variant_attributes']) || ($containerExist['type'] == 'variation'))
                if( (!isset($containerExist['variant_attributes']) || empty($containerExist['variant_attributes']))
                    && ($containerExist['type'] == 'variation'))
                {
                    $del = $mongoData->deleteNotRequiredJson($containerId, $deleteIds,true, $containerExist['user_id']);

                }
                else{
                    $this->di->getLog()->logContent('validateAndDeleteVariants else','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'webhook_product_import.log');
                }

            }
            else{
                $this->di->getLog()->logContent('$container_data not found in else','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'webhook_product_import.log');

            }

        }
    }

    // public function pushToQueue($data, $time = 0, $queueName = 'temp')
    // {
    //     $data['run_after'] = $time;
    //     if($this->di->getConfig()->get('enable_rabbitmq_internal')){
    //         $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
    //         return $helper->createQueue($data['queue_name'],$data);
    //     }

    //     return true;
    // }
    public function pushToQueue($data, $time = 0, $queueName = 'temp')
    {
        $data['run_after'] = $time;
        $push = $this->di->getMessageManager()->pushMessage($data);
        return true;
    }

    public function importOrUpdateProductById($data)
    {
        try {
            $logFile = 'shopify' . DS . 'manualProductImport'. DS . date("Y-m-d").'.log';
            if (!empty($data['container_id']) && !empty($data['user_id'])) {
                $batchImport = $this->di->getObjectManager()->get(BatchImport::class);
                if (!empty($data['user_id']) && $batchImport->isProductImportRestricted($data['user_id'])) {
                    return ['success' => false, 'message' => 'Product Importing cannot be proceeded due to plan restriction'];
                }
                if (isset($data['direct_batch_import']) && $data['direct_batch_import'] && $this->checkBatchImportRunning($data['user_id'])) {
                    return ['success' => false, 'message' => 'You cannot import this product right now because batch product import is running.'];
                }
                $this->di->getLog()->logContent('Starting single product import for data: ' . json_encode($data), 'info', $logFile);
                $maxVariantCountProcessable = 100;
                $response = $this->setDiForUser($data['user_id']);
                $bundleProduct = false;
                if (isset($response['success']) && $response['success']) {
                    $shop = $this->getShopByMarketplace();
                    if (!empty($shop)) {
                        $shopId = $shop['_id'];
                        $remoteShopId = $shop['remote_shop_id'];
                        $apiClient = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient");
                        $productRequestControlObj = $this->di->getObjectManager()->get(ProductRequestcontrol::class);
                        $batchImportObj = $this->di->getObjectManager()->get(BatchImport::class);
                        $cacheKey2048 = '2048_variant_support_users';
                        $enabledUserIds = $this->di->getCache()->get($cacheKey2048);
                        if ($enabledUserIds === null) {
                            $enabledUserIds = $batchImportObj->fetchAndSet2048VariantSupportUsers();
                        }
                        $userHas2048VariantSupport = !empty($enabledUserIds) && is_array($enabledUserIds)
                            && in_array((string)$data['user_id'], array_map('strval', $enabledUserIds));
                        $apiClient->init($shop['marketplace'], true);
                        // This api will not fetch collections data
                        $remoteResponse = $apiClient->call(
                            'get/productByIdsFragment',
                            ['Response-Modifier' => '0'],
                            ['ids' => [$data['container_id']], 'shop_id' => $remoteShopId, 'call_type' => 'QL'],
                            'GET'
                        );
                        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                            if (!empty($remoteResponse['data']['products'])) {
                                $prepareHandleWebhookData = [
                                    'method' => 'triggerWebhooks',
                                    'user_id' => $data['user_id'],
                                    'shop_id' => $shopId
                                ];
                                $formattedProductsData = $batchImportObj->formatGetProductByIdFragmentResponse($remoteResponse['data']);
                                if (!empty($formattedProductsData)) {
                                    $directProcessingContainerIds = [];
                                    foreach ($formattedProductsData as $containerId => $product) {
                                        if (!empty($data['is_sales_channel_assigned']) && $data['is_sales_channel_assigned']) {
                                            if (!empty($product['publishedOnCurrentPublication'])) {
                                                $variantCount = isset($product['variantCount']) ? (int)$product['variantCount'] : 0;
                                                if ($variantCount > $maxVariantCountProcessable) {
                                                    if (isset($data['direct_batch_import']) && $data['direct_batch_import']) {
                                                        if (!$userHas2048VariantSupport) {
                                                            $message = 'Product variant count exceeds ' . $maxVariantCountProcessable . '. Please upgrade your plan to access the 2048-variant support feature, or contact support for assistance.';
                                                        } else {
                                                            $message = 'Product variant count is greater than ' . $maxVariantCountProcessable . ', so this product cannot be imported instantly. It will be imported automatically in some time.';
                                                        }
                                                        return ['success' => false, 'message' => $message];
                                                    }
                                                    $this->di->getLog()->logContent('Initiating batch product import as variant count is greater than: ' . $maxVariantCountProcessable . ' for container_id: ' . json_encode($containerId), 'info', $logFile);
                                                    $batchImportContainerId = $containerId;
                                                } else {
                                                    unset($product['variantCount'], $product['publishedOnCurrentPublication']);
                                                    $product['manual_imported'] = true;
                                                    $directProcessingContainerIds[$containerId] = $product;
                                                }
                                            } else {
                                                return ['success' => false, 'message' => 'Product is not sales_channel assigned'];
                                            }
                                        } else {
                                            $variantCount = isset($product['variantCount']) ? (int)$product['variantCount'] : 0;
                                            if ($variantCount > $maxVariantCountProcessable) {
                                                // Set False for now, to skip products with large variant count
                                                // $userHas2048VariantSupport = false;

                                                if (!$userHas2048VariantSupport) {
                                                    return ['success' => false, 'message' => 'Product variant count exceeds ' . $maxVariantCountProcessable . '. Please upgrade your plan to access the 2048-variant support feature, or contact support for assistance.'];
                                                }
                                                $batchImportContainerId = $containerId;
                                            } else {
                                                unset($product['variantCount'], $product['publishedOnCurrentPublication']);
                                                $product['manual_imported'] = true;
                                                $directProcessingContainerIds[$containerId] = $product;
                                            }
                                        }
                                    }
                                    if (!empty($directProcessingContainerIds)) {
                                        $formatParams = [
                                            'success' => true,
                                            'data' => [
                                                'Product' => $directProcessingContainerIds
                                            ]
                                        ];
                                        $vistarHelper = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Vistar\Helper');
                                        $cifFormattedProductData = $vistarHelper->shapeProductResponse($formatParams);
                                        if (!empty($cifFormattedProductData['data'])) {
                                            $helper = $this->di->getObjectManager()->get(Helper::class);
                                            foreach ($cifFormattedProductData['data'] as $key => $product) {
                                                $formatted_product = $helper->formatCoreDataForVariantData($product);
                                                if (!empty($formatted_product)) {
                                                    $cifFormattedProductData['data'][$key] = $formatted_product;
                                                } else {
                                                    unset($cifFormattedProductData['data'][$key]);
                                                }
                                            }
                                            if (!empty($cifFormattedProductData['data'])) {
                                                $appCode = $shop['apps'][0]['code'] ?? 'default';
                                                $additionalData = [
                                                    'shop_id' => $shopId,
                                                    'marketplace' => 'shopify',
                                                    'isImported' => true,
                                                    'webhook_present' => true,
                                                    'isWebhook' => true,
                                                    'app_code' => $appCode
                                                ];
                                                $pushToProductContainerRes = $productRequestControlObj->pushToProductContainer($cifFormattedProductData['data'], $additionalData);
                                                if (isset($pushToProductContainerRes['success']) && $pushToProductContainerRes['success']) {
                                                    $prepareHandleWebhookData['data'] = $cifFormattedProductData['data'];
                                                    $deleteVariantResponse = $productRequestControlObj->deleteIsExistsKeyAndVariants($prepareHandleWebhookData);
                                                    if (isset($deleteVariantResponse['success']) && $deleteVariantResponse['success']) {
                                                        $appTag = $this->di->getAppCode()->getAppTag();
                                                        $notificationData = [
                                                            'severity' => 'success',
                                                            'message' => 'Product imported - Title: ' . $cifFormattedProductData['data'][0]['title'],
                                                            'user_id' => $data['user_id'],
                                                            'marketplace' => $shopId,
                                                            'appTag' => $appTag,
                                                            'process_code' => 'shopify_single_product_import'
                                                        ];
                                                        $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
                                                            ->addNotification($shopId, $notificationData);
                                                        if (isset($data['direct_batch_import']) && $data['direct_batch_import']) {
                                                            $this->removeEntryFromBatchImportContainer($data['user_id'], $shopId, $data['container_id']);
                                                        }
                                                        return ['success' => true, 'message' => 'Product imported successfully'];
                                                    } else {
                                                        $batchImportContainerId = $data['container_id'];
                                                        $message = 'Error from deleteIsExistsKeyAndVariants initiating batch import for this product: ';
                                                        $this->di->getLog()->logContent($message . ": " . json_encode($deleteVariantResponse), 'info', $logFile);
                                                    }
                                                } else {
                                                    $batchImportContainerId = $data['container_id'];
                                                    $message = 'Error from pushToProductContainerRespushToProductContainerRes initiating batch import for this product: ';
                                                    $this->di->getLog()->logContent($message . ": " . json_encode($pushToProductContainerRes), 'info', $logFile);
                                                }
                                            } else {
                                                $message = 'Unable to import this bundle product as setting is not enabled for this user';
                                            }
                                        } else {
                                            $batchImportContainerId = $data['container_id'];
                                            $bundleProduct = true;
                                            $message = 'Error from shapeProductResponse initiating batch import for this product: ';
                                            $this->di->getLog()->logContent($message . ": " . json_encode($cifFormattedProductData), 'info', $logFile);
                                        }
                                    }
                                }
                            }
                        } elseif (isset($remoteResponse['message']) && $remoteResponse['message'] == 'Product(s) not found') {
                            $message = 'Product not found';
                        } else {
                            $batchImportContainerId = $data['container_id'];
                            $message = 'Error from remote initiating batch import for this product...';
                            $this->di->getLog()->logContent($message . ": " . json_encode($remoteResponse), 'info', $logFile);
                        }
                    } else {
                        $message = 'Shop not found';
                    }
                } else {
                    $message = 'Unable to set di for this user';
                }
            } else {
                $message = 'Required parameters are missing(container_id or user_id)';
            }
            if (!empty($batchImportContainerId)) {
                if (isset($data['fallback_to_batch_import']) && $data['fallback_to_batch_import']) {
                    $preparedData = [
                        'product_listing' => [
                            'product_id' => $data['container_id'],
                            'title' => 'Default Title'
                        ]
                    ];
                    $batchImportPreparedData = [
                        'data' => $preparedData,
                        'user_id' => $data['user_id'],
                        'shop_id' => $shopId,
                        'action' => self::BATCH_IMPORT_ADD_ACTION
                    ];
                    $this->di->getLog()->logContent('Initiating batch product import for container_id: ' . json_encode($product['container_id']), 'info', $logFile);
                    $batchImportResponse = $batchImport->handleBatchImport($batchImportPreparedData, self::BATCH_IMPORT_ADD_ACTION);
                    if (isset($batchImportResponse['success']) && $batchImportResponse['success']) {
                        if ($bundleProduct) {
                            $message = 'Bundle Product found and intiated batch import';
                            $this->di->getLog()->logContent($message . "Response: " . json_encode($batchImportResponse), 'info', $logFile);
                        } else {
                            $message = 'Direct Import Failed intiated batch import';
                        }
                    } else {
                        $message = 'Direct Import and Batch Import both failed check response';
                    }
                    $responseData = $batchImportResponse;
                } else {
                    $message = 'Direct Import failed and batch import is not initiated for this product as fallback_to_batch_import is not enabled pass fallback_to_batch_import as true';
                }
            }
            return ['success' => false, 'message' => $message, 'data' => $responseData ?? []];
        } catch (Exception $e) {
            $this->di->getLog()->logContent("Exception in importOrUpdateProductById: " . $e->getMessage(), 'info', $logFile);
            throw $e;
        }
    }

    public function importOrUpdateProductsByContainerIds($data)
    {
        try {
            $logFile = 'shopify' . DS . 'manualProductImportByContainerIds'. DS . date("Y-m-d").'.log';
            if (!empty($data['container_ids']) && !empty($data['user_id'])) {
                $this->di->getLog()->logContent('Starting products import for container_ids: ' . json_encode($data['container_ids']), 'info', $logFile);
                $maxVariantCountProcessable = 100;
                $response = $this->setDiForUser($data['user_id']);
                if (isset($response['success']) && $response['success']) {
                    $shop = $this->getShopByMarketplace();
                    if (!empty($shop)) {
                        $shopId = $shop['_id'];
                        $remoteShopId = $shop['remote_shop_id'];
                        $apiClient = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient");
                        $productRequestControlObj = $this->di->getObjectManager()->get(ProductRequestcontrol::class);
                        $batchImportObj = $this->di->getObjectManager()->get(BatchImport::class);
                        $apiClient->init($shop['marketplace'], true);
                        // This api will not fetch collections data
                        $remoteResponse = $apiClient->call(
                            'get/productByIdsFragment',
                            ['Response-Modifier' => '0'],
                            ['ids' => $data['container_ids'], 'shop_id' => $remoteShopId, 'call_type' => 'QL'],
                            'GET'
                        );
                        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
                            if (!empty($remoteResponse['data']['products'])) {
                                $prepareHandleWebhookData = [
                                    'method' => 'triggerWebhooks',
                                    'user_id' => $data['user_id'],
                                    'shop_id' => $shopId
                                ];
                                $formattedProductsData = $batchImportObj->formatGetProductByIdFragmentResponse($remoteResponse['data']);
                                if (!empty($formattedProductsData)) {
                                    $directProcessingContainerIds = [];
                                    foreach ($formattedProductsData as $containerId => $product) {
                                            if (!empty($product['publishedOnCurrentPublication'])) {
                                                $variantCount = isset($product['variantCount']) ? (int)$product['variantCount'] : 0;
                                                if ($variantCount > $maxVariantCountProcessable) {
                                                    $this->di->getLog()->logContent('Variant count is greater than: ' . $maxVariantCountProcessable . ' for container_id: ' . json_encode($containerId), 'info', $logFile);
                                                } else {
                                                    unset($product['variantCount'], $product['publishedOnCurrentPublication']);
                                                    $product['manual_imported'] = true;
                                                    $directProcessingContainerIds[$containerId] = $product;
                                                }
                                            }
                                    }
                                    if (!empty($directProcessingContainerIds)) {
                                        $formatParams = [
                                            'success' => true,
                                            'data' => [
                                                'Product' => $directProcessingContainerIds
                                            ]
                                        ];
                                        $vistarHelper = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Vistar\Helper');
                                        $cifFormattedProductData = $vistarHelper->shapeProductResponse($formatParams);
                                        if (!empty($cifFormattedProductData['data'])) {
                                            $helper = $this->di->getObjectManager()->get(Helper::class);
                                            foreach ($cifFormattedProductData['data'] as $key => $product) {
                                                $formatted_product = $helper->formatCoreDataForVariantData($product);
                                                if (!empty($formatted_product)) {
                                                    $cifFormattedProductData['data'][$key] = $formatted_product;
                                                } else {
                                                    unset($cifFormattedProductData['data'][$key]); 
                                                }
                                            }

                                            $appCodes = $this->di->getAppCode()->get();
                                            $appCode = $appCodes['shopify'];
                                            $additionalData = [
                                                'shop_id' => $shopId,
                                                'marketplace' => 'shopify',
                                                'isImported' => true,
                                                'webhook_present' => true,
                                                'isWebhook' => true,
                                                'app_code' => $appCode
                                            ];
                                            $pushToProductContainerRes = $productRequestControlObj->pushToProductContainer($cifFormattedProductData['data'], $additionalData);
                                            if (isset($pushToProductContainerRes['success']) && $pushToProductContainerRes['success']) {
                                                $prepareHandleWebhookData['data'] = $cifFormattedProductData['data'];
                                                $deleteVariantResponse = $productRequestControlObj->deleteIsExistsKeyAndVariants($prepareHandleWebhookData);
                                                if (isset($deleteVariantResponse['success']) && $deleteVariantResponse['success']) {
                                                    return ['success' => true, 'message' => 'Product Imported successfully'];
                                                } else {
                                                    $message = 'Error from deleteIsExistsKeyAndVariants initiating batch import for this product: ';
                                                    $this->di->getLog()->logContent($message . ": " . json_encode($deleteVariantResponse), 'info', $logFile);
                                                }
                                            } else {
                                                $message = 'Error from pushToProductContainerRespushToProductContainerRes initiating batch import for this product: ';
                                                $this->di->getLog()->logContent($message . ": " . json_encode($pushToProductContainerRes), 'info', $logFile);
                                            }
                                        } else {
                                                $message = 'Error from shapeProductResponse initiating batch import for this product: ';
                                                $this->di->getLog()->logContent($message . ": " . json_encode($cifFormattedProductData), 'info', $logFile);
                                        }
                                    }
                                }
                            }
                        } else {
                            $message = 'Error from remote initiating unable to import product(s)...';
                            $error = $remoteResponse;
                            $this->di->getLog()->logContent($message . ": " . json_encode($remoteResponse), 'info', $logFile);
                        }
                    } else {
                        $message = 'Shop not found';
                    }
                } else {
                    $message = 'Unable to set di for this user';
                }
            } else {
                $message = 'Required parameters are missing(container_ids or user_id)';
            }
            return ['success' => false, 'message' => $message, 'error' => $error ?? []];
        } catch (Exception $e) {
            return ['success' => false, 'message' => "Exception in importOrUpdateProductsByContainerIds: " . $e->getMessage()];
        }
    }

    private function removeEntryFromBatchImportContainer($userId, $shopId, $containerId)
    {
        try {
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $batchProductContainerCollection = $mongo->getCollection("batch_import_product_container");
            $batchProductContainerCollection->deleteOne([
                'user_id' => $userId,
                'shop_id' => $shopId,
                'container_id' => $containerId
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent("Exception in removeEntryFromBatchImportContainer: " . $e->getMessage(), 'info', 'exception.log');
            throw $e;
        }
    }

    private function setDiForUser($userId)
    {
        try {
            $getUser = User::findFirst([['_id' => $userId]]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found'
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

    private function getShopByMarketplace()
    {
        $shops = $this->di->getUser()->shops ?? [];
        if (!empty($shops)) {
            foreach ($shops as $shop) {
                if ($shop['marketplace'] == self::MARKETPLACE_CODE) {
                    return $shop;
                }
            }
        }
        return null;
    }

    private function checkBatchImportRunning($userId)
    {
        try {
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $batchProductContainerCollection = $mongo->getCollection("batch_import_product_queue");
            return $batchProductContainerCollection->countDocuments([
                'user_id' => $userId,
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent("Exception in checkBatchImportRunning: " . $e->getMessage(), 'info', 'exception.log');
            return false;
        }
    }

}