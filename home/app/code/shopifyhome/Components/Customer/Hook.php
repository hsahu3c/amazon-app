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

namespace App\Shopifyhome\Components\Customer;

use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Core\Common;

class Hook extends Common
{
    public function updateCustomer($data, $userId = null)
    {
        $customerData = $data['data'];

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $helper = $this->di->getObjectManager()->get(Helper::class);
        $customerContainer = $this->di->getObjectManager()->create('\App\Connector\Models\CustomerContainer');

        $coreData = $helper->formatCoreDataForDetail($customerData);
        //3664581853282

        $this->di->getLog()->logContent('PRODUCT DATA == '.print_r($coreData,true),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'mywebhook_product_import.log');
        
        $coreData['marketplace'] = $target;
        $coreData['db_action'] = "customer_update";
        $coreData['user_id'] = $this->di->getUser()->id;
        $createCustomer = $customerContainer->createCustomersAndAttributes([$coreData], 'shopify');

        return true;
    }

    public function deleteCustomer($data, $userId = null): void
    {
        $customerData = ['customer_id' => (string)$data['data']['id']];
        $res = $this->di->getObjectManager()->get('\App\Facebookhome\Components\Helper')->cancelCustomer($customerData);
        $this->di->getLog()->logContent('customer data ='.print_r($data, true),'info','customer_hook_delete.log');
        $this->di->getLog()->logContent('response from ANshuman ='.print_r($res, true),'info','customer_hook_delete.log');
    }

    public function cancelCustomer($data, $userId = null)
    {
        foreach ($data['data']['refunds'] as $refund){
            $customerData = [];
            $customerData['customer_id'] = (string)$refund['customer_id'];
            foreach ($refund['refund_line_items'] as $key => $lineItem){
                $customerData['items'][$key]['source_id'] = (string)$lineItem['line_item']['variant_id'];
                $customerData['items'][$key]['quantity'] = $lineItem['quantity'];
            }

            $res = $this->di->getObjectManager()->get('\App\Facebookhome\Components\Helper')->cancelCustomer($customerData);
            $this->di->getLog()->logContent('customer cancel data ='.print_r($data, true),'info','customer_hook_cancel.log');
            $this->di->getLog()->logContent('response from ANshuman ='.print_r($res, true),'info','customer_hook_cancel.log');
        }

        return true;
    }

    public function validateFulfillment($customerId, $fulfillmentId){
        $customerId = (string)$customerId;
        $fulfillmentId = (string)$fulfillmentId;

        $mongoCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection->setSource('customer_container_'.$this->di->getUser()->id);

        $phpMongoCollection = $mongoCollection->getPhpCollection();

        $count = $phpMongoCollection->count(
            [
                'target_customer_id' => $customerId,
                'fulfillments.source_fulfilment_id' => $fulfillmentId
            ]
        );

        if($count) return true;
        return false;

    }

}