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

namespace App\Shopifyhome\Components\Product\Vistar;

use App\Shopifyhome\Components\Core\Common;

class Utility extends Common
{
    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public function splitIntoProgressBar($totalCount, $size, $totalWeightage = 100)
    {
        $data['cursor'] = 0;
        $data['total'] = $totalCount;

        return $data;
    }

    public function canTerminateExecution($currentTime, $totalTime, $allocatedTime, $nextCursor)
    {
        if(empty($nextCursor)) return true;

        if(($totalTime + (2 * $currentTime)) > $allocatedTime) return true;
        return false;
    }

    public function updateFeedProgress($importCount, $sqsData, $totalWeightage = 100): void
    {
        //$individualWeight = ($totalWeightage / ceil($sqsData['data']['total'] / $importCount));
        $individualWeight = ($importCount / ceil($sqsData['data']['total'])) * $totalWeightage;
        $individualWeight = round($individualWeight,4,PHP_ROUND_HALF_UP);

        $this->di->getLog()->logContent('PROCESS 003001 | Utility | updateFeedProgress | '.$sqsData['data']['total_variant'].' of '.$sqsData['data']['total'].' Product Variant(s) has been imported.', 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'vistar_product_import.log');
        $this->di->getLog()->logContent('PROCESS 003002 | Utility | updateFeedProgress | Individual Weight : '.$individualWeight.' | Variant Import Count :'.$importCount, 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'vistar_product_import.log');

        $msg = Utility::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : '.$sqsData['data']['total_variant'].' of '.$sqsData['data']['total'].' Product Variant(s) has been imported.';
        $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($sqsData['data']['feed_id'], $individualWeight, $msg);
    }
}