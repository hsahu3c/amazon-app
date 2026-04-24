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

namespace App\Shopifyhome\Components\Shop;

use App\Shopifyhome\Components\Core\Common;

#[\AllowDynamicProperties]
class ShopQL extends Common
{
    public function _construct()
    {        
        $this->_callName = 'shopify_core';
        parent::_construct();
    }

    /**
     * @return array
     * check here
     */
    public function pullShopInfoandSave()
    {
        $sellerShopDetail = $this->getShopDetail();
        // save seller data into database

        return $sellerShopDetail;
    }

    public function getShopDetail()
    {
        $shopQuery = $this->di->getCache()->get('shopifyShopQuery');
        if(!$shopQuery){
            $shopQuery = $this->di->getObjectManager()->get(queryQL::class)->getShopQuery();
            $this->di->getCache()->set('shopifyShopQuery', $shopQuery);
        }

        $shopData = $this->graph($shopQuery);
        return $this->shapeResponse($shopData);
    }

    public function getShopLocationDetail()
    {
        $shopQuery = $this->di->getCache()->get('shopifyShopLocationQuery');
        if(!$shopQuery){
            $shopQuery = $this->di->getObjectManager()->get(queryQL::class)->getShopLocationQuery();
            $this->di->getCache()->set('shopifyShopLocationQuery', $shopQuery);
        }

        $shopData = $this->graph($shopQuery);
        return $this->shapeResponse($shopData);
    }

    public function getShopShippingZones()
    {
        //$shopQuery = $this->di->getCache()->get('getCarrierServicesQuery');
        $shopQuery = '';
        if(!$shopQuery){
            $shopQuery = $this->di->getObjectManager()->get(queryQL::class)->getCarrierServicesQuery();
            $this->di->getCache()->set('getCarrierServicesQuery', $shopQuery);
        }

        echo $shopQuery;die;

        $shopData = $this->graph($shopQuery);
        return $this->shapeResponse($shopData);
    }

    public function getActiveCarrierServices()
    {
        $shopQuery = '';
        if(!$shopQuery){
            $shopQuery = $this->di->getObjectManager()->get(queryQL::class)->getActiveCarrierServicesQuery();
            $this->di->getCache()->set('getCarrierServicesQuery', $shopQuery);
        }

        $shopData = $this->graph($shopQuery);
        return $this->shapeResponse($shopData);
    }

    public function getShippingRegionsAndZones()
    {
        $shopQuery = '';
        if(!$shopQuery){
            $shopQuery = $this->di->getObjectManager()->get(queryQL::class)->getShippingRegionQuery();
            $this->di->getCache()->set('getShippingRegionQuery', $shopQuery);
        }

        $shopData = $this->graph($shopQuery);
        return $this->shapeResponse($shopData);
    }

    public function shapeResponse($shopData)
    {
        // todo : check the error logging flag where config value will be checked for error logging rather passing non-relavent message  to frontend
        if(!empty($shopData['errors'])) {
            $msg = "";
            if(is_array($shopData['body'])){
                foreach($shopData['body'] as $error)
                {
                    if(empty($msg)){$msg = $error['message'];continue;}

                    $msg = $msg.' , '. $error['message'];
                }
            } elseif (is_string($shopData['body'])){
                return [
                    'is_error' => true,
                    'msg' => $shopData['body']
                ];
            }

            return [
                'is_error' => true,
                'msg' => $msg
            ];
        }

        // header info
        //print_r($shopData['response']->getHeaders());die('sd');
        //print_r($shopData['body']);die('sd');
        return [
            'success' => true,
            'data' => $shopData['body']
        ];
    }
}