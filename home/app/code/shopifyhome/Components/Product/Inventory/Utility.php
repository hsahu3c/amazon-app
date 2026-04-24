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

namespace App\Shopifyhome\Components\Product\Inventory;

use App\Shopifyhome\Components\Core\Common;

class Utility extends Common
{
    public function typeCastElement($shopRawData)
    {
        if(!isset($shopRawData[0])) {
            $shopRawData['inventory_item_id'] = (string)$shopRawData['inventory_item_id'];
            $shopRawData['location_id'] = (string)$shopRawData['location_id'];
        } else {
            $count = 0;
            foreach($shopRawData as $raw){
                $shopRawData[$count]['inventory_item_id'] = (string)$raw['inventory_item_id'];
                $shopRawData[$count]['location_id'] = (string)$raw['location_id'];
                $count++;
            }
        }

        return $shopRawData;
    }

    public function testShopifyProductInventory(){
        return [
            'success' => true,
            ];
    }
}