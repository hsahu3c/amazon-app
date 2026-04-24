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

namespace App\Shopifyhome\Components\Product\Bulk;

use App\Shopifyhome\Components\Parser\JSONLParser;
use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Parser\Product;
use App\Shopifyhome\Components\Product\Import;
use App\Shopifyhome\Components\Core\Common;
use Exception;

class Bulkimport extends Common
{
    public const MARKETPLACE_CODE = 'shopify';

    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public const SHOPIFY_QUEUE_INV_IMPORT_MSG = 'SHOPIFY_INVENTORY_SYNC';

    public const PAGE_SIZE = 1000;

    /*const PAGE_SIZE = 100;*/

    public function createRequest($remoteShopId, $sqsData){

        $filter['shop_id'] = $remoteShopId;
        $filter['type'] = 'product';

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target, 'true')
            ->call('/bulk/operation',[],$filter, 'POST');

        $this->di->getLog()->logContent('PROCESS 0001 | Bulk Import | Process - Create Bulk Request on Shopify | Class - App\Shopifyhome\Components\Product\Bulk\Bulkimport | Function Name - createRequest | Response from Remote Server = \n'.print_r($remoteResponse, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');

        if(!isset($remoteResponse['success']) || !$remoteResponse['success']){
            return [ 'success' => false, 'requeue' => false, 'complete_progress' => true, 'sqs_data' => $sqsData ];
        }

        if($remoteResponse['success']){
            $sqsData['data']['operation'] = 'get_status';
            $sqsData['data']['id'] = $remoteResponse['data']['id'];

            $msg = Bulkimport::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : Product(s) import Request is accepted by Shopify.';
            $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($sqsData['data']['feed_id'], 0, $msg);

            return [ 'success' => true, 'requeue' => true, 'sqs_data' => $sqsData ];

        }
        return [ 'success' => false,'requeue' => false, 'complete_progress' => true, 'sqs_data' => $sqsData ];
    }

    public function requestStatus($remoteShopId, $uniqueRequestId, $sqsData){
        $filter['shop_id'] = $remoteShopId;
        $filter['id'] = $uniqueRequestId;
        $filter['save_file'] = 1;

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target, 'true')
            ->call('/bulk/operation',[],$filter, 'GET');

        $this->di->getLog()->logContent('PROCESS 0020 | Bulk Import | Process - Get Bulk Request status from Shopify | Class - App\Shopifyhome\Components\Product\Bulk\Bulkimport | Function Name - requestStatus | Response from Remote Server = '.print_r($remoteResponse, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');

        if(!isset($remoteResponse['success'])){
            return [ 'success' => false, 'requeue'=> false , 'complete_progress' => true, 'sqs_data' => $sqsData ];
        }

        if($remoteResponse['success']){
            if ($remoteResponse['data']['status'] == 'RUNNING') {
                $sqsData['data']['retry'] = (int)isset($sqsData['data']['retry']) ? ($sqsData['data']['retry'] + 1) : 1;
                $this->di->getLog()->logContent('PROCESS 0021 | Request Control | Class - App\Shopifyhome\Components\Product\Bulk\Bulkimport | Function Name - requestStatus | retry count = '.$sqsData['data']['retry'],'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');
                $msg = Bulkimport::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : Please wait.Your Product(s) import Request is being processed by Shopify. Request Weightage : '.$remoteResponse['data']['objectCount'];
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($sqsData['data']['feed_id'], 0, $msg);
                return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
            }
            if ($remoteResponse['data']['status'] == 'COMPLETED') {
                $isFileSaved = $this->di->getObjectManager()->get(Helper::class)->saveFile($remoteResponse['data']['url']);
                if(!$isFileSaved['success']) {
                    $this->di->getLog()->logContent('PROCESS 00201 | Shopifyhome\Components\Product\Bulk\Bulkimport | requestStatus | ERROR | Response  = '.print_r($isFileSaved, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');
                    return ['success' => false, 'requeue' => false, 'sqs_data' => $sqsData];
                }
                unset($sqsData['data']['retry']);
                $sqsData['data']['operation'] = 'build_progress';
                $sqsData['data']['file_path'] = $isFileSaved['file_path'];
                $this->di->getLog()->logContent('PROCESS 00202 | Bulkimport | requestStatus | ERROR | Response  = '.print_r($isFileSaved, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');
                $msg = Bulkimport::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : Your Product(s) import Request is successfully processed by Shopify.';
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($sqsData['data']['feed_id'], 0, $msg);
                return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
            }
            else {
                $this->di->getLog()->logContent('PROCESS 0021 | Request Control | Class - App\Shopifyhome\Components\Product\Bulk\Bulkimport | Function Name - requestStatus | ERROR | Response  = '.print_r($remoteResponse, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');
                return ['success' => false, 'requeue' => false, 'sqs_data' => $sqsData];
            }
         }
    }

    public function buildProgressBar($remoteShopId, $filePath, $sqsData){
        /*$filter['shop_id'] = $remoteShopId;
        $filter['file_path'] = $filePath;*/
        //$filePath = BP.DS.'var'.DS.'file'.DS.$this->di->getUser()->id.DS.'product.jsonl';
        try{
            $remoteResponse = $keepResponse =  $this->di->getObjectManager()->create("\App\Shopifyhome\Components\Parser\JSONLParser", [$filePath])->getItemCount();
            $remoteResponse['data'] = $remoteResponse['data']['ProductVariant'];
        } catch (Exception $e){
            $this->di->getLog()->logContent('PROCESS 00030 | Process - Get Product Count from Shopify | Bulkimport | buildProgressBar | Error Message  = '.$e->getMessage().' File Path = '. $filePath.' | Probable Error : JSONL file does not exist.','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');
            return [ 'success' => false, 'requeue'=> false , 'complete_progress' => true, 'sqs_data' => $sqsData ];
        }

        $this->di->getLog()->logContent('PROCESS 0030 | Bulk Import | Process - Get Product Count from Shopify | Class - App\Shopifyhome\Components\Product\Bulk\Bulkimport | Function Name - buildProgressBar | Response from Remote Server = \n'.print_r($remoteResponse, true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');

        if(!isset($remoteResponse['success']) || !$remoteResponse['success']) return [ 'success' => false, 'requeue'=> false , 'complete_progress' => true, 'sqs_data' => $sqsData ];

        if($remoteResponse['data'] == 0) return [ 'success' => false, 'requeue'=> false , 'complete_progress' => true , 'notice' => 'No Product Found on your Shopify Store', 'sqs_data' => $sqsData];
        $appendData = $this->di->getObjectManager()->get(Utility::class)->splitIntoProgressBar( $remoteResponse['data'], Bulkimport::PAGE_SIZE, 98);
        //$appendData = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Bulk\Utility')->splitIntoProgressBarDynammic( $keepResponse['data']['Product'], Bulkimport::PAGE_SIZE, $keepResponse['data']['ProductVariant']);
        foreach ($appendData as $k => $v) $sqsData['data'][$k] = $v;

        $sqsData['data']['limit'] = Bulkimport::PAGE_SIZE;
        $msg = Bulkimport::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG." {$remoteResponse['data']} Variants(s) found on Shopify store.";
        $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($sqsData['data']['feed_id'], 0, $msg);
        unset($sqsData['data']['operation']);
        return ['success' => true, 'requeue' => true, 'sqs_data' => $sqsData];
    }


    public function importProduct($sqsData)
    {
        /*$filter['shop_id'] = $sqsData['data']['remote_shop_id'];
        $filter['file_path'] = $sqsData['data']['file_path'];*/
        $filter['limit'] = $sqsData['data']['limit'];
        if($sqsData['data']['cursor'] > 0) $filter['next'] = $sqsData['data']['next'];

        //if(isset($sqsData['data']['delete_file_at_last'])) $filter['delete_file_at_last'] = $sqsData['data']['delete_file_at_last'];
        try{
            /*$intermediateResponse = $this->di->getObjectManager()->create("\App\Shopifyhome\Components\Parser\JSONLParser", [$sqsData['data']['file_path']])->getItems($filter);
            $remote = $this->di->getObjectManager()->get("\App\Shopifyhome\Components\Product\Bulk\Helper")->shapeProductResponse($intermediateResponse);*/

            $intermediateResponse = $this->di->getObjectManager()->create(Product::class, [$sqsData['data']['file_path']]);
            $response =  $intermediateResponse->getVariants($filter);
            $remote = $this->di->getObjectManager()->get(Helper::class)->shapeProductResponse($response);

        } catch (Exception $e){
            $this->di->getLog()->logContent('PROCESS X00400 | Process - Get Product Count from Shopify | Bulkimport | buildProgressBar | Error Message  = '.$e->getMessage().' File Path = '. $sqsData['data']['file_path'].' | Probable Error : JSONL file does not exist.','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');
            return [ 'success' => false, 'requeue'=> false , 'complete_progress' => true, 'sqs_data' => $sqsData ];
        }

        if(isset($remote['success']) && $remote['success']){
            $this->di->getLog()->logContent('PROCESS 00400 | Bulk Import | Process - Import Product(s) from Shopify | Class - App\Shopifyhome\Components\Product\Bulk\Bulkimport | Function Name - importProduct | Before looping into products','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');

            $this->di->getObjectManager()->get(Import::class)->getShopifyProducts($remote, 'bulk_');

            /*foreach ($remote['data'] as $productData){
                $this->di->getLog()->logContent('PROCESS 00401 | Remote Response : '.$productData['container_id'],'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.'bulk_product_import.log');
            }*/
        } else {
            $this->di->getLog()->logContent('PROCESS X00041 | Bulk Import | Process - Import Product(s) from Shopify | Class - App\Shopifyhome\Components\Product\Bulk\Bulkimport | Function Name - importProduct | Error Remote Response : '.var_dump($remote),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');
            return [ 'success' => false, 'requeue' => false, 'message' => 'Internal Error Occured.', 'sqs_data' => $sqsData ];
        }

        $sqsData['data']['cursor'] += 1;
        $msg = Bulkimport::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : '.($sqsData['data']['cursor'] * Bulkimport::PAGE_SIZE).' of '.$sqsData['data']['total'].' Shopify Product Variant(s) has been successfully imported.';
        $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($sqsData['data']['feed_id'], $sqsData['data']['individual_weight'], $msg);
        if (isset($sqsData['data']['delete_file_at_last']) || !isset($remote['cursors']['next'])) {
            $this->di->getLog()->logContent('PROCESS 0044 | Bulk Import | Process - Import Product(s) from Shopify | Class - App\Shopifyhome\Components\Product\Bulk\Bulkimport | Function Name - importProduct | THE END ','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');
            return [ 'success' => true, 'requeue' => false, 'is_complete' => true, 'message' => $sqsData['data']['total'].' Product Variants(s) has been successfully imported.' ];
        }
        if ($sqsData['data']['cursor'] == ($sqsData['data']['total_cursor'] - 1)) {
            $this->di->getLog()->logContent('PROCESS 00044 | Bulk Import | Process - Import Product(s) from Shopify | Class - App\Shopifyhome\Components\Product\Bulk\Bulkimport | Function Name - importProduct | SECOND LAST | CURSOR = '.$sqsData['data']['cursor'].' | TOTAL CURSOR = '.$sqsData['data']['total_cursor'],'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'bulk_product_import.log');
            $sqsData['data']['next'] = $remote['cursors']['next'];
            $sqsData['data']['delete_file_at_last'] = 1;
            return [ 'success' => true, 'requeue' => true, 'sqs_data' => $sqsData ];
        }


        if ($sqsData['data']['cursor'] < $sqsData['data']['total_cursor']) {
            $sqsData['data']['next'] = $remote['cursors']['next'];
            return [ 'success' => true, 'requeue' => true, 'sqs_data' => $sqsData ];
        }
        else {
            return [ 'success' => false, 'requeue' => false, 'message' => 'Internal Error Occured.', 'sqs_data' => $sqsData ];
        }

        return true;

        // send back cursor value

    }

}