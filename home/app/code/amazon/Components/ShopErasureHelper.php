<?php
namespace App\Amazon\Components;

use App\Core\Components\Base as Base;

class ShopErasureHelper extends Base
{
	public function OrderEraser($user_id, $home_shop_id=null)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');

        $cursor = $sqsData['data']['cursor'] ?? 0;
        $limit = $sqsData['data']['limit'] ?? 0;

        $unsetKeys = [
            "region" => true,
            "country" => true,
            "url" => true,
            "subtotal_price" => true,
            "total_weight" => true,
            "taxes_included" => true,
            "source_error_message" => true,
            "tags" => true,
            "buyer_accept_marketing" => true,
            "buyer_note" => true,
            "billing_address" => true,
            "shipping_address" => true,
            "payment_method" => true,
            "client_details" => true,
            "shipping_details" => true,
            "ship_by_date" => true,
            "fulfillment_status" => true,
            "tax_lines" => true,
            "shipping_cost_details" => true,
            "total_tax" => true,
            "total_discounts" => true,
            "source_order_data" => true,
            "seller_id" => true,
            "target_error_message" => true,
            "target_errors" => true,
            "target_status" => true,
            "imported_at" => true,
            "shopify_order_name" => true,
            "target_order_data" => true,
        ];

        $i = 0;
        while($i <= 10) {
            $unsetKeys["line_items." . $i . ".tax_lines"] = true;
            $unsetKeys["line_items." . $i . ".fulfillment_service"] = true;
            $unsetKeys["line_items." . $i . ".total_discount"] = true;
            $unsetKeys["line_items." . $i . ".fulfillment_service"] = true;
            $i++;
        }

        if(is_null($home_shop_id)) {
            $res = $collection->updateMany(['user_id' => $user_id], ['$unset' => $unsetKeys, '$set' => ['uninstalled' => true]]);
        }
        else {
            $res = $collection->updateMany(['user_id' => $user_id, 'shop_id' => (string)$home_shop_id], ['$unset' => $unsetKeys, '$set' => ['uninstalled' => true]]);   
        }
        
        return $res->getModifiedCount();
    }
}