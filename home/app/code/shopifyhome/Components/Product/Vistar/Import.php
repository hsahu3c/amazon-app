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

namespace App\Shopifyhome\Components\Product\Vistar;

use App\Core\Components\Base;
use App\Shopifyhome\Components\Parser\JSONLParser;
use Exception;
use App\Shopifyhome\Components\Parser\Product;
use App\Shopifyhome\Components\Shop\Shop;
class Import extends Base
{
    public const MARKETPLACE_CODE = 'shopify';

    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public const SHOPIFY_QUEUE_INV_IMPORT_MSG = 'SHOPIFY_INVENTORY_SYNC';

    public const PAGE_SIZE = 250;

    public const IMPORT_FEED_WEIGHT = 90;


    private $_apiClient;
    private $_connectorHelper;
    private $_vistarHelper;
    private $_vistarData;
    private $_vistarUtility;
    private $_target;
    private $_queuedTask;

    public function _construct()
    {
        parent::_construct();
    }

    public function init()
    {
        $this->_apiClient = $this->di->getObjectManager()->get('\App\Connector\Components\ApiClient');
        $this->_queuedTask = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
        $this->_vistarHelper = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Vistar\Helper');
        $this->_vistarData = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Vistar\Data');
        $this->_vistarUtility = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Vistar\Utility');
        $this->_target = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Shop\Shop')->getUserMarkeplace();
        return $this;
    }

    public function createRequest($remoteShopId, $sqsData)
    {
        $filter['shop_id'] = $remoteShopId;
        $filter['type'] = $sqsData['data']['extra_data']['filterType'] ?? 'product';
        if (isset($sqsData['data']['extra_data']['updated_at'])) {
            $filter['updated_at'] = $sqsData['data']['extra_data']['updated_at'];
        }

        isset($sqsData['data']['extra_data']['vendor']) && $filter['vendor'] = $sqsData['data']['extra_data']['vendor'];
        isset($sqsData['data']['extra_data']['product_type']) && $filter['product_type'] = $sqsData['data']['extra_data']['product_type'];

        $source_marketplace = $sqsData['data']['shop']['marketplace'];
        $appCode = $sqsData['data']['app_code'] ?? 'default';
        $remoteResponse = $this->_apiClient->init($source_marketplace, false,$appCode)->call('/bulk/operation', [], $filter, 'POST');

        $this->di->getLog()->logContent('PROCESS 0001 | Create Bulk Request on Shopify | Import | createRequest | Response from Remote Server = \n' . print_r([$source_marketplace,$appCode,$filter,$remoteResponse], true), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'remote_response.log');
        if ((isset($remoteResponse['success']) && $remoteResponse['success']) && !isset($remoteResponse['error'])) {
            $msg = Import::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG . ' : Product(s) import Request is accepted by Shopify.';
            $progress = $this->_queuedTask->updateFeedProgress($sqsData['data']['feed_id'], 1, $msg);
            if (isset($sqsData['data']['extra_data']['filterType'])) {
                $sqsData['data']['extra_data']['metafield_status'] = true;
            }

            $sqsData['data']['operation'] = 'get_status';
            $sqsData['data']['id'] = $remoteResponse['data']['id'];
            return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
        }


        if (isset($remoteResponse['error']['message']) && str_contains((string) $remoteResponse['error']['message'], 'A bulk query operation for this app and shop is already in progress:')) {
            $gIdData = explode('A bulk query operation for this app and shop is already in progress:', (string) $remoteResponse['error']['message']);
            $gId = trim(substr($gIdData[1], 0, -1));
            $msg = Import::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG . ' : Product(s) import Request is accepted by Shopify.';
            $progress = $this->_queuedTask->updateFeedProgress($sqsData['data']['feed_id'], 1, $msg);
            $sqsData['data']['operation'] = 'get_status';
            $sqsData['data']['id'] = $gId;
            return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
        }
        else
        {

            if(empty($remoteResponse) || isset($remoteResponse['message']) && $remoteResponse['message'] == 'Error Obtaining token from Remote Server. Kindly contact Remote Host for more details.')
            {
                return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];

            }

            return ['success' => false, 'requeue' => false, 'complete_progress' => true, 'sqs_data' => $sqsData];
        }
    }

    public function requestStatus($remoteShopId, $sqsData)
    {
        $filter['shop_id'] = $remoteShopId;
        $filter['id'] = $sqsData['data']['id'];
        $source_marketplace = $sqsData['data']['shop']['marketplace'];
        $appCode = $sqsData['data']['app_code'] ?? 'default';
        //$filter['save_file'] = 1;
        $remoteResponse = $this->_apiClient->init($source_marketplace,false,$appCode)->call('/bulk/operation', [], $filter, 'GET');

        $this->di->getLog()->logContent('PROCESS 0020 | Vistar Import | Class - Import | Function Name - requestStatus | Response from Remote Server = ' . print_r($remoteResponse, true), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'remote_response.log');
        if (isset($remoteResponse['data']['status']) && (($remoteResponse['data']['status'] == 'RUNNING') || ($remoteResponse['data']['status'] == 'CREATED'))) {
            $retry_count = 0;
            if(isset($sqsData['data']['retry']))
            {
                $retry_count = (int)$sqsData['data']['retry'];
            }

            $retry_count = $retry_count + 1;
            //            $sqsData['data']['retry'] = isset($sqsData['data']['retry']) ? (int)($sqsData['data']['retry'] + 1) : 1;
            $sqsData['data']['retry'] = $retry_count;
            $msg = Import::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG . ' : Fetching product(s) data from Shopify. Objects fetched till now : ' . $remoteResponse['data']['objectCount'];
            $progress = $this->_queuedTask->updateFeedProgress($sqsData['data']['feed_id'], 1, $msg, false);
            return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
        }

        if (isset($remoteResponse['data']['status']) && $remoteResponse['data']['status'] == 'COMPLETED') {
            if (isset($remoteResponse['data']['objectCount']) && $remoteResponse['data']['objectCount'] == 0) {
                $msg = Import::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG . ' : Fetching product(s) data from Shopify. Objects fetched till now : ' . $remoteResponse['data']['objectCount'];
                $progress = $this->_queuedTask->updateFeedProgress($sqsData['data']['feed_id'], 2, $msg, false);
                $sqsData['data']['total'] = 0;
                $sqsData['data']['operation'] = 'import_products_tempdb';
                $sqsData['data']['import_message'] = $msg ?? null;
                $sqsData['method'] = 'handleImport';
                $sqsData['class_name'] =  '\App\Connector\Components\Route\ProductRequestcontrol';
                $sqsData['data']['limit'] = Import::PAGE_SIZE;
                $sqsData['data']['total_variant'] = 0;
                return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
            }

            if(strlen((string) $remoteResponse['data']['url']) == 0)
            {
                return ['success' => false, 'requeue' => false, 'sqs_data' => $sqsData];
            }
            if($sqsData['data']['individual_weight'] == 0)
            {
                $currentWt = 3;
            }
            else
            {
                $currentWt = 4;
            }
            $filterType = $sqsData['data']['extra_data']['filterType'] ?? null;
            $url = $remoteResponse['data']['url'];
            switch ($filterType) {
                case 'bulkinventory':
                    $isFileSaved = $this->_vistarHelper->productsInventorySaveFile($url);
                    break;
                case 'metafield':
                case 'metafieldsync':
                    $isFileSaved = $this->_vistarHelper->productsMetafieldSaveFile($url);
                    break;
                default:
                    $isFileSaved = $this->_vistarHelper->saveFile($url);
                    break;
            }

            if (!$isFileSaved['success'])
            {
                $this->di->getLog()->logContent('PROCESS 00201 | Import | requestStatus save file | ERROR | Response  = ' . print_r($isFileSaved, true), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'vistar_product_import.log');

                return ['success' => false, 'requeue' => false, 'sqs_data' => $sqsData];
            }

            unset($sqsData['data']['retry']);
            $sqsData['data']['operation'] = 'build_progress';
            $sqsData['data']['file_path'] = $isFileSaved['file_path'];
            //$msg = Import::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG . ' : Your Product(s) import Request is successfully processed by Shopify.';
            $msg = Import::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG . ' : Your Product(s) data is successfully fetched from shopify for importing.';
            $progress = $this->_queuedTask->updateFeedProgress($sqsData['data']['feed_id'], $currentWt, $msg);
            return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
        }
        else
        {
            if(empty($remoteResponse) || isset($remoteResponse['message']) && $remoteResponse['message'] == 'Error Obtaining token from Remote Server. Kindly contact Remote Host for more details.')
            {
                return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];

            }

            return ['success' => false, 'requeue' => false, 'sqs_data' => $sqsData];
        }
    }

    public function buildProgressBar($sqsData)
    {
        echo "buildProgressBar funtion start";

        $filePath = $sqsData['data']['file_path'];
        try {
            $remoteResponse = $this->di->getObjectManager()->create(JSONLParser::class, [$filePath])->getItemCount();

            $message = $remoteResponse['data']['Product'] . " product(s) imported successfully from Shopify";
            $remoteResponse['data'] = $remoteResponse['data']['ProductVariant'];
        }
        catch (Exception $e)
        {
            $this->di->getLog()->logContent('PROCESS 00030 | Process - Get Product Count from Shopify | Import | buildProgressBar | Error Message  = ' . $e->getMessage() . ' File Path = ' . $filePath . ' | Probable Error : JSONL file does not exist.', 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'vistar_product_import.log');

            return ['success' => false, 'requeue' => false, 'complete_progress' => true, 'sqs_data' => $sqsData];
        }

        $this->di->getLog()->logContent('PROCESS 0030 | Vistar Import | buildProgressBar |Process - Get Product Count from Shopify | Response from Remote Server = \n' . print_r($remoteResponse, true), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'vistar_product_import.log');

        if ($remoteResponse['data'] == 0)
        {
            return ['success' => false, 'requeue' => false, 'complete_progress' => true, 'notice' => 'No Product Found on your Shopify Store', 'sqs_data' => $sqsData];
        }
        $sqsData['data']['total'] = $remoteResponse['data'];
        $sqsData['data']['operation'] = 'import_products_tempdb';
        $sqsData['data']['import_message'] = $message ?? null;
        $sqsData['method'] = 'handleImport';
        $sqsData['class_name'] =  '\App\Connector\Components\Route\ProductRequestcontrol';
        $sqsData['data']['limit'] = Import::PAGE_SIZE;
        $sqsData['data']['total_variant'] = 0;
        return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
    }

    public function importControl($sqsData){

        $filter['limit'] = $sqsData['data']['limit'];
        $totalTime = $totalVariant = 0;
        $returnParam = [];
        $this->di->getLog()->logContent('', 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'system.log');
        do{
            try{

                $openTime = microtime(true);

                if (isset($sqsData['data']['next'])) $filter['next'] = $sqsData['data']['next'];

                $intermediateResponse = $this->di->getObjectManager()->create(Product::class, [$sqsData['data']['file_path']]);
                $response =  $intermediateResponse->getVariants($filter);
                $remote = $this->_vistarHelper->shapeProductResponse($response);

                $operationStats = $this->pushToProductContainer($remote, 'vistar', $sqsData['data']);

                if(!$operationStats['success']) return [ 'success' => false, 'requeue'=> false , 'complete_progress' => true, 'notice' => $operationStats['message'], 'sqs_data' => $sqsData ];

                $totalVariant = $sqsData['data']['total_variant'] + $remote['count']['variant'];
                $sqsData['data']['total_variant'] = $totalVariant;
                $sqsData['data']['next'] = $remote['cursors']['next'] ?? '';
                $this->_vistarUtility->updateFeedProgress($remote['count']['variant'], $sqsData, Import::IMPORT_FEED_WEIGHT);

                $endTime = microtime(true);     $elapsedtIME = $endTime - $openTime;     $totalTime += $elapsedtIME;
                $generalizedTime = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Utility::class)->secondsToTime($elapsedtIME);

                $this->di->getLog()->logContent('Variant Import Count : '.$sqsData['data']['total_variant'].' | Time Taken : '.$generalizedTime , 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'system.log');
                $this->di->getLog()->logContent('Total Current Loop Time : '.$totalTime , 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'system.log');

                $cutOff = $this->_vistarUtility->canTerminateExecution($elapsedtIME, $totalTime, $sqsData['data']['sqs_timeout'], $sqsData['data']['next']);

                $this->di->getLog()->logContent('CutOff '.json_encode($cutOff).' | Next Cursor : '. $sqsData['data']['next'], 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'system.log');

                if($cutOff && !empty($sqsData['data']['next'])) $returnParam = ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData, 'data' => $operationStats['stats']];
                elseif (empty($sqsData['data']['next'])){
                    $sqsData['data']['operation'] = 'delete_nexisting';
                    unset($sqsData['data']['file_path'], $sqsData['data']['total_variant'], $sqsData['data']['limit'], $sqsData['data']['sqs_timeout'], $sqsData['data']['next']);
                    $returnParam = ['success' => true, 'requeue' => false, 'is_complete' => true, 'sqs_data' => $sqsData, 'data' => $operationStats['stats']];

                    //start match from Amazon
                    $reportComponent = $this->di->getObjectManager()->get('\App\Amazon\Components\Report\Report');
                    $response = $reportComponent->init(['user_id' => $this->di->getUser()->id])->requestReport();
                    $this->di->getLog()->logContent('Report response : '.json_encode($response) ,'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');

                    $productComponent = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper');
                    $productComponent->searchFromAmazon(['run_after' => 1200]);
                    $this->di->getLog()->logContent('Created product search queue ' ,'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');
                }
            } catch (Exception $e){
                $this->di->getLog()->logContent('PROCESS X00400 | Process - Get Product Count from Shopify | Bulkimport | buildProgressBar | Error Message  = '.$e->getMessage().' File Path = '. $sqsData['data']['file_path'].' | Probable Error : JSONL file does not exist.','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'vistar_product_import.log');
                return [ 'success' => false, 'requeue'=> false , 'complete_progress' => true, 'sqs_data' => $sqsData ];
            }

        } while (!$cutOff);

        return $returnParam;
    }

    public function pushToProductContainer($productData, $mode = 'vistar', $additional_data=[])
    {
        $helper = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Helper::class);
        $productImport = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Import::class);
        $productContainer = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection = $mongo->getCollection("product_container");


        $this->di->getLog()->logContent(' PROCESS 003002 | Import | pushToProductContainer | Inside pushToProductContainer ','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.$mode.'_product_import.log');

        $simpleProductCounter = 0;

        $bulkOpArray = [];

        $variantIdsOnShopify = [];

        $newProuctFlag = 0;//initial state, 1-> exists, 2->not exists

        foreach ($productData['data'] as $productData)
        {
            $variantIdsOnShopify[] = $productData['source_product_id'];
            $containerId = $productData['container_id'];

            if($newProuctFlag === 0)
            {
                $productExists = $mongoCollection->find(['container_id' => (string)$containerId, "source_marketplace" => "shopify", 'user_id' => $this->di->getUser()->id])->toArray();
                if(!empty($productExists))
                {
                    $newProuctFlag = 1;
                }
                else{
                    $newProuctFlag = 2;
                }
            }

            $sourceProductIdExists = $mongoCollection->findOne(['source_product_id' => (string)$productData['id'],'user_id' => $this->di->getUser()->id,"source_marketplace" => "shopify", ]);

            if(!empty($sourceProductIdExists))
            {
                $productData['db_action'] = "variant_update";
                $data = $helper->formatCoreDataForVariantData($productData);

                /*if( (isset($exists['variant_attributes']) && !empty($exists['variant_attributes'])) &&
                    (isset($data['variant_attributes']) && empty($data['variant_attributes'])) )
                {
                    //this condition is for when variant product is converted to simple product
                }
                else{}
                print_r($data);die('u r here ere');*/

                if( (isset($exists['variant_attributes']) && !empty($exists['variant_attributes'])) &&
                    (isset($data['variant_attributes']) && empty($data['variant_attributes'])) )
                {
                    //this condition is for when variant product is converted to simple product

                }

                // code by himanshu
                if(isset($additional_data['app_code'])) {
                    $app_code = $additional_data['app_code'];
                    // $existingProduct = $exists->getArrayCopy();
                    $existingProduct = json_decode(json_encode($sourceProductIdExists), true);
                    if(isset($existingProduct['app_codes'])) {
                        if(!in_array($app_code, $existingProduct['app_codes'])) {
                            $existingProduct['app_codes'][] = $app_code;
                            $data['app_codes'] = $existingProduct['app_codes'];
                        }
                    }
                    else {
                        $data['app_codes'] = [$app_code];
                    }
                }

                // end

                $data['type'] = 'simple';

                $variantAttributes = [];
                if(isset($data['variant_attributes']) && !empty($data['variant_attributes']))
                {
                    $variantAttributes = $data['variant_attributes'];
                    $data['variant_attributes'] = [];
                    foreach ($variantAttributes as $attr_name)
                    {
                        $data['variant_attributes'][] = ['key' => $attr_name, 'value' => $data[$attr_name] ?? ''];
                    }

                    $data['visibility'] = "Not Visible Individually";
                }

                if(!empty($variantAttributes))
                {
                    $parentData['variant_attributes'] = $variantAttributes;
                    $query = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Helper::class)->getAndQuery(['container_id' => $data['container_id'],"source_marketplace" => "shopify", 'user_id' => $this->di->getUser()->id],$this->di->getUser()->id);
                    $bulkOpArray[] = [
                        'updateOne' => [
                            $query,
                            [ '$set' => $parentData]
                        ]
                    ];
                }

                $query = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Helper::class)->getAndQuery(['source_product_id' => $data['source_product_id']],$this->di->getUser()->id);
                $bulkOpArray[] = [
                    'updateOne' => [
                        $query,
                        [ '$set' => $data]
                    ]
                ];
            }
            else {
                $data['details'] = $helper->formatCoreDataForDetail($productData);

                $data['variant_attribute'] = $productData['variant_attributes'] ?? [];
                $variants = $helper->formatCoreDataForVariantData($productData);
                // code by himanshu
                if(isset($additional_data['app_code'])) {
                    $app_code = $additional_data['app_code'];

                    $data['details']['app_codes'] = [$app_code];
                    $variants['app_codes'] = [$app_code];
                }


                $variants['type'] = 'simple';
                $variants['visibility'] = 'Not Visible Individually';
                /*if(isset($variants['variant_attributes']) && count($variants['variant_attributes']) > 0)
                {
                    $variants['type'] = 'simple';
                }*/
                //$data['details']['type'] = 'variation';

                // end

                $data['variants'][] = $variants;
                $marketplace = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
                //$productContainer->setSource("product_container");
                $remote_shop_id = $additional_data['remote_shop_id'] ?? 0;

                $connectorHelper = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');

                $res = $connectorHelper->createProductsAndAttributes([$data], $marketplace, $remote_shop_id, $this->di->getUser()->id);
                if(isset($res[0]['success']) && $res[0]['success'])
                {
                    $simpleProductCounter++;
                }

                $this->di->getLog()->logContent(' PROCESS 00310 | Import | pushToProductContainer | Product Import REsponse : '.json_encode($res),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.$mode.'_product_import.log');
            }

            $data = [];
        }

        try{
            if($variantIdsOnShopify !== [] && $newProuctFlag === 1)
            {
                $sourceProductIdsInDb = $mongoCollection->distinct('source_product_id',['user_id' => $this->di->getUser()->id, 'source_product_id' => ['$ne' => (string)$containerId],'source_marketplace' => 'shopify', 'container_id' => (string)$containerId, ]);

                if(!empty($sourceProductIdsInDb))
                {
                    $notOnShopifyVariants = [];
                    foreach ($sourceProductIdsInDb as $sids)
                    {
                        if(!in_array($sids,$variantIdsOnShopify))
                        {
                            $notOnShopifyVariants[] = $sids;
                        }
                    }

                    if($notOnShopifyVariants !== [])
                    {
                        $deleteNotOnShopifyVariants = $mongoCollection->deleteMany([
                            'user_id' => $this->di->getUser()->id,
                            'source_product_id' => [
                                '$in' => $notOnShopifyVariants
                            ],
                            'source_marketplace' => 'shopify',
                            'container_id' => $containerId,

                        ]);
                    }
                }
            }

            if(!empty($bulkOpArray)){
                $bulkObj = $mongoCollection->BulkWrite($bulkOpArray, ['w' => 1]);
                $returenRes = [
                    'acknowledged' => $bulkObj->isAcknowledged(),
                    'inserted' => $bulkObj->getInsertedCount(),
                    'modified' => $bulkObj->getModifiedCount(),
                    'matched' => $bulkObj->getMatchedCount(),
                    'mainProductCount' => $simpleProductCounter
                ];
                $this->di->getLog()->logContent(' PROCESS 00310 | Import | pushToProductContainer | Bulk Product Write Response : '.print_r($returenRes, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.$mode.'_product_import.log');
                return [
                    'success' => true,
                    'stats' => $returenRes
                ];
            }
        } catch (Exception $e)
        {
            return ['success' => false, 'message' => $e->getMessage()];
        }


        return [
            'success' => true,
            'stats' => $simpleProductCounter. ' main product imported successfully'
        ];
    }

}