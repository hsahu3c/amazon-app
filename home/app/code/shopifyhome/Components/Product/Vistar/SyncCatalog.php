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
class SyncCatalog extends Base
{
    public const SHOPIFY_QUEUE_SYNC_CATALOG_MSG = 'SHOPIFY_CATALOG_SYNC';

    /*const PAGE_SIZE = 3000;*/
    public const PAGE_SIZE = 500;

    public const IMPORT_FEED_WEIGHT = 90;

    private $_connectorHelper;

    private $_vistarHelper;

    private $_vistarUtility;

    public function _construct()
    {
        parent::_construct();
    }

    public function init()
    {
        $this->_connectorHelper = $this->di->getObjectManager()->get('\App\Connector\Components\Helper');
        $this->_vistarHelper = $this->di->getObjectManager()->get(Helper::class);
        $this->_vistarUtility = $this->di->getObjectManager()->get(Utility::class);

        return $this;
    }

    public function buildProgressBar($sqsData)
    {
        $logFile = 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'vistar_sync_catalog.log';

        $filePath = $sqsData['data']['file_path'];
        try {
            $remoteResponse = $this->di->getObjectManager()->create(JSONLParser::class, [$filePath])->getItemCount();
            $remoteResponse['data'] = $remoteResponse['data']['ProductVariant'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('PROCESS 00030 | Process - Get Product Count from Shopify | Import | buildProgressBar | Error Message  = ' . $e->getMessage() . ' File Path = ' . $filePath . ' | Probable Error : JSONL file does not exist.', 'info', $logFile);
            return ['success' => false, 'requeue' => false, 'complete_progress' => true, 'sqs_data' => $sqsData];
        }

        $this->di->getLog()->logContent('PROCESS 0030 | Vistar Import | buildProgressBar |Process - Get Product Count from Shopify | Response from Remote Server = \n' . print_r($remoteResponse, true), 'info', $logFile);

        if (!isset($remoteResponse['success']) || !$remoteResponse['success']) return ['success' => false, 'requeue' => false, 'complete_progress' => true, 'sqs_data' => $sqsData];

        if ($remoteResponse['data'] == 0) return ['success' => false, 'requeue' => false, 'complete_progress' => true, 'notice' => 'No Product Found on your Shopify Store', 'sqs_data' => $sqsData];
        $sqsData['data']['total'] = $remoteResponse['data'];
        $sqsData['data']['operation'] = 'sync_catalog';
        $sqsData['data']['limit'] = Import::PAGE_SIZE;
        $sqsData['data']['total_variant'] = 0;
        $msg = Import::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG . " : {$remoteResponse['data']} Variant(s) found on your Shopify store.";
        $progress = $this->_connectorHelper->updateFeedProgress($sqsData['data']['feed_id'], $sqsData['data']['individual_weight'], $msg);
        /*unset($sqsData['data']['operation']);*/
        return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
    }

    public function syncData($sqsData)
    {
        $logFile = 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'vistar_sync_catalog.log';

        $filter['limit'] = $sqsData['data']['limit'];
        $totalTime = $totalVariant = 0;
        $returnParam = [];
        $this->di->getLog()->logContent('SyncData Start..', 'info', $logFile);
        do{
            try{

                $openTime = microtime(true);

                if (isset($sqsData['data']['next'])) $filter['next'] = $sqsData['data']['next'];

                $intermediateResponse = $this->di->getObjectManager()->create(Product::class, [$sqsData['data']['file_path']]);
                $response =  $intermediateResponse->getVariants($filter);
                $remote = $this->_vistarHelper->shapeProductResponse($response);

                $operationStats = $this->updateProducts($remote, 'vistar', $sqsData['data']);

                if(!$operationStats['success']) return [ 'success' => false, 'requeue'=> false , 'complete_progress' => true, 'notice' => $operationStats['message'], 'sqs_data' => $sqsData ];

                $totalVariant = $sqsData['data']['total_variant'] + $remote['count']['variant'];
                $sqsData['data']['total_variant'] = $totalVariant;
                $sqsData['data']['next'] = $remote['cursors']['next'] ?? '';
                $this->_vistarUtility->updateFeedProgress($remote['count']['variant'], $sqsData, Import::IMPORT_FEED_WEIGHT);

                $endTime = microtime(true);     $elapsedtIME = $endTime - $openTime;     $totalTime += $elapsedtIME;
                $generalizedTime = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Utility::class)->secondsToTime($elapsedtIME);

                $this->di->getLog()->logContent('Variant Import Count : '.$sqsData['data']['total_variant'].' | Time Taken : '.$generalizedTime , 'info', $logFile);
                $this->di->getLog()->logContent('Total Current Loop Time : '.$totalTime , 'info', $logFile);

                $cutOff = $this->_vistarUtility->canTerminateExecution($elapsedtIME, $totalTime, $sqsData['data']['sqs_timeout'], $sqsData['data']['next']);

                $this->di->getLog()->logContent('CutOff '.json_encode($cutOff).' | Next Cursor : '. $sqsData['data']['next'], 'info', $logFile);

                if($cutOff && !empty($sqsData['data']['next'])) $returnParam = ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData, 'data' => $operationStats['stats']];
                elseif (empty($sqsData['data']['next'])){
                    $sqsData['data']['operation'] = 'delete_nexisting';
                    unset($sqsData['data']['file_path'], $sqsData['data']['total_variant'], $sqsData['data']['limit'], $sqsData['data']['sqs_timeout'], $sqsData['data']['next']);
                    $returnParam = ['success' => true, 'requeue' => false, 'is_complete' => true, 'sqs_data' => $sqsData, 'data' => $operationStats['stats']];

                    //start match from Amazon
                    $reportComponent = $this->di->getObjectManager()->get('\App\Amazon\Components\Report\Report');
                    $response = $reportComponent->init(['user_id' => $this->di->getUser()->id])->requestReport();
                    $this->di->getLog()->logContent('Report response : '.json_encode($response) ,'info',$logFile);

                    $productComponent = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper');
                    $productComponent->searchFromAmazon(['run_after' => 1200]);
                    $this->di->getLog()->logContent('Created product search queue ' ,'info',$logFile);
                }
            } catch (Exception $e){
                $this->di->getLog()->logContent('PROCESS X00400 | Process - Get Product Count from Shopify | Bulkimport | buildProgressBar | Error Message  = '.$e->getMessage().' File Path = '. $sqsData['data']['file_path'].' | Probable Error : JSONL file does not exist.','info',$logFile);
                return [ 'success' => false, 'requeue'=> false , 'complete_progress' => true, 'sqs_data' => $sqsData ];
            }

        } while (!$cutOff);

        return $returnParam;
    }

    public function updateProducts($productData, $mode = '', $additional_data=[])
    {
        $logFile = 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'vistar_sync_catalog.log';

        $helper = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Helper::class);
        $productContainer = $this->di->getObjectManager()->create('\App\Connector\Models\ProductContainer');
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("product_container");

        $mongoCollection = $mongo->getPhpCollection();

        $this->di->getLog()->logContent(' PROCESS 003002 | Import | updateProducts | Inside updateProducts ','info',$logFile);

        $simpleProductCounter = 0;

        $bulkOpArray = [];

        foreach ($productData['data'] as $productData){
            $exists = $mongoCollection->findOne(['source_product_id' => (string)$productData['id']]);

            if(!empty($exists))
            {
                $productData['db_action'] = "variant_update";
                $data = $helper->formatCoreDataForVariantData($productData);

                // code by himanshu
                if(isset($additional_data['app_code'])) {
                    $app_code = $additional_data['app_code'];
                    // $existingProduct = $exists->getArrayCopy();
                    $existingProduct = json_decode(json_encode($exists), true);
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

                $query = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Helper::class)->getAndQuery(['source_product_id' => $data['source_product_id']],$this->di->getUser()->id);
                $bulkOpArray[] = [
                    'updateOne' => [
                        $query,
                        [ '$set' => $data]
                    ]
                ];
            }
            else
            {
                $data['details'] = $helper->formatCoreDataForDetail($productData);
                $data['variant_attribute'] = $productData['variant_attributes'] ?? [];
                $variants = $helper->formatCoreDataForVariantData($productData);

                // code by himanshu
                if(isset($additional_data['app_code'])) {
                    $app_code = $additional_data['app_code'];

                    $data['details']['app_codes'] = [$app_code];
                    $variants['app_codes'] = [$app_code];
                }

                if(isset($variants['variant_attributes']) && count($variants['variant_attributes']) > 0) {
                    if(!isset($variants['group_id']))
                        $variants['group_id'] = $variants['container_id'];

                    $data['details']['type'] = 'variation';
                }

                // end

                $data['variants'][] = $variants;

                $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
                $productContainer->setSource("product_container");
                // $res = $productContainer->createProductsAndAttributes([$data], $target, 0, $this->di->getUser()->id);
                $remote_shop_id = $additional_data['remote_shop_id'] ?? 0;

                $res = $productContainer->createProductsAndAttributes([$data], $target, $remote_shop_id, $this->di->getUser()->id);
                if(isset($res[0]['success']) && $res[0]['success']) $simpleProductCounter++;

                $this->di->getLog()->logContent(' PROCESS 00310 | Import | updateProducts | Product Import REsponse : '.json_encode($res),'info',$logFile);
            }

            $data = [];
        }

        try{
            if(!empty($bulkOpArray)){
                $bulkObj = $mongoCollection->BulkWrite($bulkOpArray, ['w' => 1]);
                $returenRes = [
                    'acknowledged' => $bulkObj->isAcknowledged(),
                    'inserted' => $bulkObj->getInsertedCount(),
                    'modified' => $bulkObj->getModifiedCount(),
                    'matched' => $bulkObj->getMatchedCount(),
                    'mainProductCount' => $simpleProductCounter
                ];
                $this->di->getLog()->logContent(' PROCESS 00310 | Import | updateProducts | Bulk Product Write Response : '.print_r($returenRes, true),'info',$logFile);
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