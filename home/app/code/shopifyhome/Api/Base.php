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

namespace App\Shopifyhome\Api;

/**
 * Manages extra operation in the API logics.
 *
 * @since 1.0.0
 */
class Base extends \App\Apiconnect\Api\Base {

    /**
     * Get filtered params by removing the default params from the params recieved array.
     *
     * @param array $data
     * @since 1.0.0
     * @return array
     */
    public function getFilteredParams( $data ) {
        $default_params = ['_url', 'appId', 'shop_id', 'sAppId', 'sales_channel'];
        foreach ( $default_params as $key ) {
            unset( $data[ $key ] );
        }

        return $data;
    }
}