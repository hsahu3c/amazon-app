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

use App\Shopifyhome\Components\Shop\Shopinterface\Queryinterface;
use rdx\graphqlquery\Query as phpGraphQueryBuilder;

class queryQL implements Queryinterface
{
    /**
     * Modifying query here doesn't reflect on live, kindly note this query output is being cached
     * So plz fluch cache once made changes here
     * @return string
     */
    public function getShopQuery()
    {
        $shopQuery = phpGraphQueryBuilder::query('', []);
        $shopQuery->fields('shop');

        $shopQuery->shop->fields('name', 'contactEmail', 'email', 'plan');
        $shopQuery->shop->plan->fields('displayName');
        return $shopQuery->build();
    }

    /**
     * Modifying query here doesn't reflect on live, kindly note this query output is being cached
     * So plz fluch cache once made changes here
     * @return string
     */
    public function getShopLocationQuery()
    {
        $shopQuery = phpGraphQueryBuilder::query('', []);
        $shopQuery->fields('locations');
        // @todo : warehouse count restricted to 250 , more can be fetched using cursor
        $shopQuery->locations->attribute('first' , 250);
        $shopQuery->locations->fields('edges');

        $shopQuery->locations->edges->fields('node');
        $shopQuery->locations->edges->node->fields('id', 'address', 'name', 'isActive');
        $shopQuery->locations->edges->node->address->fields('address1', 'address2', 'city', 'country', 'countryCode');
        return $shopQuery->build();
    }

    public function getActiveCarrierServicesQuery(){

        // https://yanzuly.myshopify.com/admin/api/2019-04/carrier_services.json

        $shopQuery = phpGraphQueryBuilder::query('', []);
        $shopQuery->fields('activatedCarrierServices');
        // @todo : warehouse count restricted to 250 , more can be fetched using cursor
        $shopQuery->activatedCarrierServices->attribute('first' , 250);
        $shopQuery->activatedCarrierServices->fields('edges');

        $shopQuery->activatedCarrierServices->edges->fields('node');
        $shopQuery->activatedCarrierServices->edges->node->fields('name');
        return $shopQuery->build();
    }

    public function getShippingRegionQuery()
    {
        $shopQuery = phpGraphQueryBuilder::query('', []);
        $shopQuery->fields('activatedCarrierServices');
        // @todo : warehouse count restricted to 250 , more can be fetched using cursor
        $shopQuery->activatedCarrierServices->attribute('first' , 250);
        $shopQuery->activatedCarrierServices->fields('edges');

        $shopQuery->activatedCarrierServices->edges->fields('node');
        $shopQuery->activatedCarrierServices->edges->node->fields('name');
        return $shopQuery->build();
    }
}
