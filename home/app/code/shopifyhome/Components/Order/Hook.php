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

namespace App\Shopifyhome\Components\Order;

use App\Shopifyhome\Components\Core\Common;

class Hook extends Common
{
    public function updateOrder($webdata, $userId = null)
    {
        $userId = !empty($userId) ? $userId : $this->di->getUser()->id;

        $helper = $this->di->getObjectManager()->get(Helper::class);
        $orderContainer = $this->di->getObjectManager()->create('\App\Connector\Models\OrderContainer');

        $orderData = $webdata['data'];
        $orderData['user_id'] = $userId;

        $data = $helper->formatCoreDataForDetail($orderData);
        $response = $orderContainer->createUserOrdersAndAttributes([$data], 'shopify');
        return true;
    }

    public function deleteOrder($data, $userId = null): void
    {
        $orderData = ['order_id' => (string)$data['data']['id']];
        $this->di->getLog()->logContent('order data ='.print_r($data, true),'info','order_hook_delete.log');
        $this->di->getLog()->logContent('response from ANshuman ='.print_r($res, true),'info','order_hook_delete.log');
    }

    public function cancelOrder($data, $userId = null)
    {
        foreach ($data['data']['refunds'] as $refund){
            $orderData = [];
            $orderData['order_id'] = (string)$refund['order_id'];
            foreach ($refund['refund_line_items'] as $key => $lineItem){
                $orderData['items'][$key]['source_id'] = (string)$lineItem['line_item']['variant_id'];
                $orderData['items'][$key]['quantity'] = $lineItem['quantity'];
            }

            $this->di->getLog()->logContent('order cancel data ='.print_r($data, true),'info','order_hook_cancel.log');
            $this->di->getLog()->logContent('response from ANshuman ='.print_r($res, true),'info','order_hook_cancel.log');
        }

        return true;
    }

    public function validateFulfillment($orderId, $fulfillmentId){
        $orderId = (string)$orderId;
        $fulfillmentId = (string)$fulfillmentId;

        $mongoCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection->setSource('order_container_'.$this->di->getUser()->id);

        $phpMongoCollection = $mongoCollection->getPhpCollection();

        $count = $phpMongoCollection->count(
            [
                'target_order_id' => $orderId,
                'fulfillments.source_fulfilment_id' => $fulfillmentId
            ]
        );

        if($count) return true;
        return false;

    }

    public function updateOrderFromCheckout($webdata, $userId = null){
        $userId = !empty($userId) ? $userId : $this->di->getUser()->id;

        $orderData = $webdata['data'];

        /*Skip in below Shopify Cases*/
        if(!is_null($orderData['completed_at'])){ //skip if checkout is not abandoned checkout
            return false;
        }

        if(!isset($orderData['customer'])){ //skip if an customer doesn't exists
            return false;
        }

        $helper = $this->di->getObjectManager()->get(Helper::class);
        $orderContainer = $this->di->getObjectManager()->create('\App\Connector\Models\OrderContainer');

        $orderData['user_id'] = $userId;
        $orderData['checkout'] = true;
        $orderData['type'] = "checkout";

        $data = $helper->formatCoreDataForDetail($orderData);
        $response = $orderContainer->createUserOrdersAndAttributes([$data], 'shopify');
        return true;
    }

}