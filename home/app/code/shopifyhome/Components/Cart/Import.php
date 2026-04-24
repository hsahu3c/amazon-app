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

namespace App\Shopifyhome\Components\Cart;

use App\Shopifyhome\Components\Core\Common;

class Import extends Common
{
    public const MARKETPLACE_CODE = 'shopify';

    public function _construct(){
    }

    public function getShopifyCarts($cartData, $userId, $mode = '')
    {
        $userId = !empty($userId) ? $userId : $this->di->getUser()->id;

        $helper = $this->di->getObjectManager()->get(Helper::class);
        $cartContainer = $this->di->getObjectManager()->create('\App\Connector\Models\CartContainer');

        $totalCount = 0;
        foreach ($cartData['data']['checkouts'] as $cartData){
            $totalCount++;
            $cartData['user_id'] = $userId;
            $data = $helper->formatCoreDataForDetail($cartData);
            $cartContainer->createCartsAndAttributes([$data], Import::MARKETPLACE_CODE);
        }

        return [
            'success' => true,
            'message' => 'cart imported'
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