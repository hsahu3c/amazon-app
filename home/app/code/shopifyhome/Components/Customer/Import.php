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

use App\Shopifyhome\Components\Core\Common;

class Import extends Common
{
    public const MARKETPLACE_CODE = 'shopify';

    public function _construct(){
    }

    public function getShopifyCustomers($customerData, $userId, $mode = '')
    {
        $userId = !empty($userId) ? $userId : $this->di->getUser()->id;

        $helper = $this->di->getObjectManager()->get(Helper::class);
        $customerContainer = $this->di->getObjectManager()->create('\App\Connector\Models\CustomerContainer');

        $tempCount = $mainCustomer = $variant = $time = $totalTime = $totalCount = 0;
        foreach ($customerData['data']['customers'] as $customerData){
            $totalCount++;

            $customerData['user_id'] = $userId;
            $data = $helper->formatCoreDataForDetail($customerData);
            $customerContainer->createCustomersAndAttributes([$data], Import::MARKETPLACE_CODE);
        }

        return [
            'success' => true,
            'message' => 'customer imported'
        ];
    }


    public function pushToQueue($data, $time = 0, $queueName = 'temp')
    {
        $data['run_after'] = $time;
        if($this->di->getConfig()->get('enable_rabbitmq_internal')){
            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
            return $helper->createQueue($data['queue_name'],$data);
        }

        return true;
    }


}