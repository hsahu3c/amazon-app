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
namespace App\Connector\Models\Order;

class Helper{

    /**
     * Method for getting taxes from connected platforms.
     * Copyright © Cedcommerce, Inc. All rights reserved.
     * See LICENCE.txt for license details.
     * 
     * @param $rawBody
     * @return tax lists.
     */
    public function fetchTaxes(array $rawBody){

        if (isset($rawBody['source']['marketplace']) || isset($rawBody['source']['shopId']) || isset($rawBody['target']['marketplace']) || isset($rawBody['target']['shopId'])) {
            $source = $rawBody['source']['marketplace'] ?? false;
            $sourceShopId = $rawBody['source']['shopId'] ?? false;
            $target = $rawBody['target']['marketplace'] ?? false;
            $targetShopId = $rawBody['target']['shopId'] ?? false;
        } else {
            $source = $this->di->getRequester()->getSourceName() ?? false;
            $sourceShopId = $this->di->getRequester()->getSourceId() ?? false;
            $target = $this->di->getRequester()->getTargetName() ?? false;
            $targetShopId = $this->di->getRequester()->getTargetId() ?? false;
        }

        if( !empty($source) || !empty($sourceShopId) || !empty($target) || !empty($targetShopId) ){
            $target_module = ucfirst($target) . 'home';
            $class = '\App\\' . $target_module . '\Models\SourceModel';
            if (class_exists($class)) {
                if (method_exists($class, 'getTaxes')) {
                   $shops = $this->di->getUser()->shops;
                   $target_remote_shop_id = '';
                   foreach($shops as $shop){
                        if($shop['_id'] == $targetShopId)
                        {
                            $target_remote_shop_id = $shop['remote_shop_id'];
                        }
                    }

                    $retrieve_shop = array('source' => $source , 'source_shop_id' => $sourceShopId , 'target' => $target , 'target_shop_id' =>  $targetShopId , 'target_remote_shop_id' => $target_remote_shop_id );

                    return $this->di->getObjectManager()->get($class)->getTaxes($shops , $retrieve_shop );
                }
                $this->di->getLog()->logContent('Class found, getTaxes method not found. ' . json_encode($rawBody), 'info', 'MethodNotFound.log');
                return [ 'success' => false , 'message' => 'Class found, getTaxes method not found' ];
            }
            $this->di->getLog()->logContent('Class not found. ' . json_encode($rawBody), 'info', 'ClassNotFound.log');
            return [ 'success' => false , 'message' => 'Class not found' ];
        }
        return [ 'success' => false , 'message' => 'Required Data Missing' ];
    }


    /**
     * Method for getting default order from connected platforms.
     * Copyright © Cedcommerce, Inc. All rights reserved.
     * See LICENCE.txt for license details.
     * 
     * @param $rawBody
     * @return order statuses lists.
     */
    public function syncOrderStatus(array $rawBody)
    {

        if (isset($rawBody['source']['marketplace']) || isset($rawBody['source']['shopId']) || isset($rawBody['target']['marketplace']) || isset($rawBody['target']['shopId'])) {
            $source = $rawBody['source']['marketplace'] ?? false;
            $sourceShopId = $rawBody['source']['shopId'] ?? false;
            $target = $rawBody['target']['marketplace'] ?? false;
            $targetShopId = $rawBody['target']['shopId'] ?? false;
        } else {
            $source = $this->di->getRequester()->getSourceName() ?? false;
            $sourceShopId = $this->di->getRequester()->getSourceId() ?? false;
            $target = $this->di->getRequester()->getTargetName() ?? false;
            $targetShopId = $this->di->getRequester()->getTargetId() ?? false;
        }
        
        if( !empty($source) || !empty($sourceShopId) || !empty($target) || !empty($targetShopId) ){

            $target_module = ucfirst($target) . 'home';
            $class = '\App\\' . $target_module . '\Models\SourceModel';
            if (class_exists($class)) {
                if (method_exists($class, 'syncOrderStatuses')) {
                   $shops = $this->di->getUser()->shops;
                   $target_remote_shop_id = '';
                   foreach($shops as $shop){
                        if($shop['_id'] == $targetShopId)
                        {
                            $target_remote_shop_id = $shop['remote_shop_id'];
                        }
                    }

                    $retrieve_shop = array('source' => $source , 'source_shop_id' => $sourceShopId , 'target' => $target , 'target_shop_id' =>  $targetShopId , 'target_remote_shop_id' => $target_remote_shop_id );

                    return $this->di->getObjectManager()->get($class)->syncOrderStatuses($shops , $retrieve_shop );
                }
                $this->di->getLog()->logContent('Class found, syncOrderStatuses method not found. ' . json_encode($rawBody), 'info', 'MethodNotFound.log');
                return [ 'success' => false , 'message' => 'Class found, syncOrderStatuses method not found' ];
            }
            $this->di->getLog()->logContent('Class not found. ' . json_encode($rawBody), 'info', 'ClassNotFound.log');
            return [ 'success' => false , 'message' => 'Class not found' ];
        }
        return [ 'success' => false , 'message' => 'Required Data Missing' ];
    }
}
