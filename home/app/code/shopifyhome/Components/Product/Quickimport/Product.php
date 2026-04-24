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

namespace App\Shopifyhome\Components\Product\Quickimport;

use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Product\Quickimport\Route\Requestcontrol;
use App\Shopifyhome\Components\Core\Common;

class Product extends Common
{
    private $userId;

    private $sqsData;

    public function importProduct($userId, $sqsData)
    {
        $this->userId = $userId;
        $this->sqsData = $sqsData;

        return $this->process();
    }

    public function process()
    {
        $filter = [
            'limit'   => (isset($this->sqsData['data']['limit']) && $this->sqsData['data']['limit'] <= Helper::QUICK_IMPORT_PRODUCT_LIMIT) ? $this->sqsData['data']['limit'] : Helper::QUICK_IMPORT_PRODUCT_LIMIT,
            'shop_id' => $this->sqsData['data']['remote_shop_id']
        ];

        if(isset($this->sqsData['data']['next'])) {
            $filter['next'] = $this->sqsData['data']['next'];
        }
        
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target)
            ->call('/product',['Response-Modifier'=>'0'],$filter, 'GET');

        // $this->di->getLog()->logContent(print_r($remoteResponse,true), 'info', 'product_response.log');
        $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($this->userId, 'Process 0101 | Product | Fetch Product From Remote Response : '.print_r($remoteResponse, true));

        $quickImportHelper = $this->di->getObjectManager()->get(Helper::class);
        $formattedProductResponse = $quickImportHelper->shapeProductResponse($remoteResponse);
        // $this->di->getLog()->logContent(print_r($formattedProductResponse,true), 'info', 'formatted_response.log');
        if ($formattedProductResponse['success']) {
            $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($this->userId, 'Process 0102 | Product | Product Formatted Successfully');
            $quickImport = $this->di->getObjectManager()->get(Import::class);
            $quickImportResponse = $quickImport->saveProduct($formattedProductResponse);
            $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($this->userId, 'Process 0103 | Product | Product Import Response : '.print_r($quickImportResponse, true));
            $this->sqsData['data']['operation'] = 'collection_import';
            $this->di->getObjectManager()->get(Requestcontrol::class)->pushToQueue($this->sqsData);
            $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($this->userId, 'Process 0104 | Product | Products Imported Successfully. Collection import request pushed to queue.'.print_r($this->sqsData, true));
            return true;
        }
        if (isset($response['msg']) || isset($response['message'])) {
            $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($this->userId, 'Process 0102 | Product | Product Not Formatted : '.print_r($response, true));
            $message = $response['msg'] ?? $response['message'];
            $realMsg = '';
            if(is_string($message)) $realMsg = $message;
            elseif(is_array($message)) {
                if(isset($message['errors'])) $realMsg = $message['errors'];
            }
            if(empty($realMsg)) $error = true;
            else {
                if(strpos((string) $realMsg, "Reduce request rates") || strpos((string) $realMsg, "Try after sometime")) {
                    $this->di->getObjectManager()->get(Requestcontrol::class)->pushToQueue($this->sqsData, time() + 8);
                    return true;
                }

                $error = false;
            }
        }
        else {
            $error = true;
        }

        if($error) {
            $errorMessage = Requestcontrol::class . '::fetchAndSaveProducts() : Failed to fetch Product(s) from Shopify. Please try again later.' . PHP_EOL . print_r($this->sqsData, true) . PHP_EOL . print_r($remoteResponse, true);
            $this->di->getObjectManager()->get(Helper::class)->createQuickImportLog($errorMessage, 'error');

            return false;
        }

        return true;
    }
}