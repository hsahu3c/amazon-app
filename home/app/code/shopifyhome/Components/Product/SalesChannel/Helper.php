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

namespace App\Shopifyhome\Components\Product\SalesChannel;

// use App\Shopifyhome\Components\Core\Common;
use App\Connector\Models\QueuedTasks;
use App\Shopifyhome\Components\Product\SalesChannel\Route\Requestcontrol;
use Phalcon\Logger\Logger;
class Helper extends \App\Shopifyhome\Components\Product\Quickimport\Helper
{
    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public function shapeSqsData($userId, $shop, QueuedTasks $queuedTask)
    {
        $remoteShopId = $shop['remote_shop_id'];

        $handlerData = [
            'type' => 'full_class',
            'class_name' => Requestcontrol::class,
            'method' => 'fetchShopifySalesChannelProduct',
            'queue_name' => 'shopify_product_import',
            'own_weight' => 100,
            'user_id' => $userId,
            'data' => [
                'user_id' => $userId,
                'individual_weight' => 1,
                'feed_id' => (string)$queuedTask->_id,
                // 'pageSize' => 250,//can be max 2000 in case of sales channel app.
                // 'operation' => 'make_request',
                'remote_shop_id' => $remoteShopId,
                'sqs_timeout' => $this->di->getConfig()->throttle->shopify->sqs_timeout
            ]
        ];

        return $handlerData;
    }

    public function createProductImportLog($message, $type='info'): void
    {
        if($type == 'exception') {
            $logFile = 'shopify-saleschannel'. DS . 'exception' . DS . date("Y-m-d").'.log';
            $type = Logger::CRITICAL;
        }
        elseif($type == 'error') {
            $logFile = 'shopify-saleschannel'. DS . 'error' . DS . date("Y-m-d").'.log';
            $type = Logger::CRITICAL;
        }
        else {
            $logFile = 'shopify-saleschannel'. DS . date("Y-m-d").'.log';
        }

        $this->di->getLog()->logContent($message, $type, $logFile);
    }

    public function createUserWiseLogs($userId, $message): void
    {
        $logFile = 'shopify-saleschannel'. DS . 'product_import' . DS . $userId . DS . date("Y-m-d").'.log';

        $this->di->getLog()->logContent($message, Logger::INFO, $logFile);
    }
}