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

class Utility extends Common
{
    public function shopNodeToExcludeAndFormatData($shopRawData)
    {
        $nodeToExclude = ['latitude', 'longitude', 'google_apps_domain', 'google_apps_login_enabled', 'eligible_for_payments', 'requires_extra_payments_agreement', 'force_ssl', 'cookie_consent_level', 'force_ssl', 'checkout_api_supported', 'setup_required', 'pre_launch_enabled'];
        foreach($nodeToExclude as $node){
            unset($shopRawData[$node]);
        }

        $shopRawData['id'] = (string)$shopRawData['id'];

        return $shopRawData;
    }

    public function locationNodeToExcludeAndFormatData($locationRawData)
    {
        $nodeToExclude = ['created_at', 'updated_at'];
        foreach($nodeToExclude as $node){
            unset($locationRawData[$node]);
        }

        $locationRawData['id'] = (string)$locationRawData['id'];
        if(isset($locationRawData['active']) && $locationRawData['active'])
        {
            $locationActive = true;
        }
        else{
            $locationActive = false;
        }

        $locationRawData['active'] = $locationActive;

        return $locationRawData;
    }

    public function testShopifyShopData(){
        return [
            'success' => 1,
            'data' => [
                'shop' => [
                    'id' => "32145440904",
                    'name' => 'satya-ka-store',
                    'domain' => 'satya-ka-store.myshopify.com',
                    'phone' => '',
                    'address1' => '3/460',
                    'address2' => '',
                    'city' => 'Lucknow',
                    'province' => 'Uttar Pradesh',
                    'province_code' => 'UP',
                    'country' => 'IN',
                    'latitude' => 'sdsd',
                    'country_code' => 'IN',
                    'country_name' => 'India',
                    'currency' => 'INR',
                    'weight_unit' => 'kg',                            
                    'zip' => '226010',
                    'primary_locale' => 'en'                                        ,
                    'force_ssl' => 1
                ]
            ]
        ];
    }

    public function testShopifyLocationData(){
        return [
            'success' => 1,
            'data' => [
                'locations' => [
                                [
                                    'id' => "39295058056",
                                    'name' => "name",
                                    'address1' => '3/460',
                                    'address2' => ' ',
                                    'city' => 'Lucknow',
                                    'zip' => '226010',
                                    'province' => 'Uttar Pradesh',
                                    'country' => 'IN',
                                    'phone' => '',
                                    'country_code' => 'IN',
                                    'country_name' => 'India',
                                    'province_code' => 'UP',
                                    'admin_graphql_api_id' => 'gid://shopify/Location/39295058056'
                                ],
                                [
                                    'id' => "39456145544",
                                    'name' => "Vishal Khand",
                                    'address1' => '2/186, Vishal Khand',
                                    'address2' => 'Gomti Nagar',
                                    'city' => 'Lucknow',
                                    'zip' => '226010',
                                    'province' => 'Uttar Pradesh',
                                    'country' => 'IN',
                                    'phone' => '',
                                    'country_code' => 'IN',
                                    'country_name' => 'India',
                                    'province_code' => 'UP',
                                    'admin_graphql_api_id' => 'gid://shopify/Location/39456145544'
                                ]
                    ]
                ]
            ];    
    }
}