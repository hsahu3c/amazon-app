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

namespace App\Shopifyhome\Components\Refund;

use App\Shopifyhome\Components\Core\Common;

class Hook extends Common
{

    public function createRefund($data){
        $this->di->getLog()->logContent('webhook data = '. print_r($data, true), 'info', 'shopify_webhook_product_create.log');

        if ( !isset($data['data']['refund_line_items']) ) return true;

        $refundData = [];
        $refundData['order_id'] = (string)$data['data']['order_id'];
        $refundData['description'] = $data['data']['note'];
        foreach ($data['data']['refund_line_items'] as $key => $refundItem){
                $refundData['items'][$key]['source_id'] = (string)$refundItem['line_item']['variant_id'];
                $refundData['items'][$key]['quantity'] = $refundItem['quantity'];
                $refundData['items'][$key]['source_refund_id'] = (string)$refundItem['id'];
                $refundData['source_refund_id'] = (string)$refundItem['id'];
        }

        return true;
    }

    public function validateFulfillment($orderId, $refundId){
        $orderId = (string)$orderId;
        $refundId = (string)$refundId;

        $mongoCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection->setSource('order_container_'.$this->di->getUser()->id);

        $phpMongoCollection = $mongoCollection->getPhpCollection();

        $count = $phpMongoCollection->count(
            [
                'target_order_id' => $orderId,
                'refund.source_refund_id' => $refundId
            ]
        );

        if($count) return true;
        return false;

    }

}