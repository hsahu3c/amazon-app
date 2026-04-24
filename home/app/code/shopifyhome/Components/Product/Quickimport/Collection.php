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

class Collection extends Common
{
    private $userId;

    private $sqsData;

    public function importCollection($userId, $sqsData)
    {
        $this->userId = $userId;
        $this->sqsData = $sqsData;

        return $this->process();
    }

    public function process()
    {
        $canImportCollection = $this->getDi()->getConfig()->get('can_import_collection') ?? true;
        if($canImportCollection !== true) {
            return [];
        }

        $collectionImportLimit = $this->getDi()->getConfig()->get('import_collection_limit') ?? -1;

        $return = [];

        $quickImport = $this->di->getObjectManager()->get(Import::class);
        $idsResponse = $quickImport->fetchIds($this->userId, 'product_id');

        $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($this->userId, 'Process 0201 | Collection | Fetching ProductIds for collection data update. Json Encoded Response : '.json_encode($idsResponse));

        if($idsResponse['success'])
        {
            if($idsResponse['total'])
            {
                $remoteShopId = $this->sqsData['data']['remote_shop_id'];

                $target = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Shop\Shop')->getUserMarkeplace();

                $idsChunks = $arrayChunks = array_chunk($idsResponse['ids'], 10);

                foreach ($idsChunks as $chunk) 
                {
                    $subQuery = '';

                    foreach ($chunk as $productId) {
                        $subQuery .= <<<SUBQUERY
product_{$productId}: product(id: "gid://shopify/Product/{$productId}") {
...productInformation
}
SUBQUERY;
                    }

                    $query = <<<QUERY
query {
{$subQuery}
}
fragment productInformation on Product {
id
collections (first:95) {
    edges {
        node {
            id
            title
        }
    }
}    
}
QUERY;

                    $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                        ->init($target)
                        ->call('/shopifygql/query',[],['shop_id'=> $remoteShopId, 'query' => $query], 'POST');

                    // $this->di->getLog()->logContent(print_r($remoteResponse, true), 'info', 'collection_response.log');
                    // var_dump($remoteResponse);die('remoteResponse');
                    $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($this->userId, 'Process 0202 | Collection | Fetch Collection From Remote Response : '.print_r($remoteResponse, true));

                    if(isset($remoteResponse['success']) && $remoteResponse['success'])
                    {
                        $newProductCollection = $collectionUpdateResponse = [];
                        foreach($remoteResponse['data'] as $key=>$productCollection) 
                        {
                            $explode = explode('_', (string) $key);

                            $product_id = $explode[1];

                            if(isset($productCollection['collections']['edges']) && count($productCollection['collections']['edges']))
                            {
                                $count = 0;
                                foreach ($productCollection['collections']['edges'] as $collectionInfo) 
                                {
                                    $count++;
                                    $newProductCollection[$product_id][] = [
                                        'collection_id' => str_replace('gid://shopify/Collection/', '', $collectionInfo['node']['id']),
                                        'title'         => $collectionInfo['node']['title'],
                                        'gid'           => $collectionInfo['node']['id'],
                                        '__parentId'    => (string)$product_id
                                    ];

                                    if($collectionImportLimit !== -1 && $count >= $collectionImportLimit) {
                                        break;
                                    }
                                }
                            }
                            else
                            {
                                $newProductCollection[$product_id][] = [];
                            }
                        }

                        $quickImport = $this->di->getObjectManager()->get(Import::class);

                        foreach($newProductCollection as $proId => $collection) {
                            $this->parseResponse($quickImport->updateProductCollection($this->userId, (string)$proId, $collection), $collectionUpdateResponse);
                        }

                        $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($this->userId, 'Process 0203 | Collection | Collection Update Response : '.print_r($collectionUpdateResponse, true));
                    }
                    else
                    {
                        $message = $remoteResponse['msg'] ?? $remoteResponse['message'];
                        $realMsg = '';
                        if(is_string($message)) {
                            $realMsg = $message;
                        } elseif(is_array($message)) {
                            if(isset($message['errors'])) $realMsg = $message['errors'];
                        }

                        if(!empty($realMsg) && (strpos((string) $realMsg, "Reduce request rates") || strpos((string) $realMsg, "Try after sometime"))) {
                            $this->di->getObjectManager()->get(Requestcontrol::class)->pushToQueue($this->sqsData, time() + 8);
                            return true;
                        }

                        return [
                            'success' => false,
                            'message' => 'Error obtaining Data from Shopify.'
                        ];
                    }
                }

                $return = [
                    'success' => true,
                    'message' => 'Collection info updated successfully.'
                ];
            }
            else 
            {
                $return = [
                    'success' => false,
                    'message' => 'no products found for update.'
                ];
            }
        } 
        else {
            $errorMessage = Requestcontrol::class . '::fetchAndSaveCollection() : something went wrong while fetching products for collection update.' . PHP_EOL . print_r($this->sqsData, true);
            $this->di->getObjectManager()->get(Helper::class)->createQuickImportLog($errorMessage, 'error');

            $return = [
                'success' => false,
                'message' => 'something went wrong while fetching products for collection update.'
            ];
        }

        $this->sqsData['data']['operation'] = 'inventory_import';
        $this->di->getObjectManager()->get(Requestcontrol::class)->pushToQueue($this->sqsData);

        $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($this->userId, 'Process 0203 | Collection | All Collection Updated Successfully. Inventory import request pushed to queue.'.print_r($this->sqsData, true));

        return $return;
    }

    private function parseResponse($response, &$parsedResponse): void
    {
        $arr = ['Matched', 'Modified', 'Upserted'];
        foreach ($arr as $key) {
            $method = "get{$key}Count";
            if(isset($parsedResponse[$key])) {
                $parsedResponse[$key] += $response->$method();
            } else {
                $parsedResponse[$key] = $response->$method();
            }            
        }

        // var_dump($response->getMatchedCount());
        // var_dump($response->getModifiedCount());
        // var_dump($response->getUpsertedCount());
    }
}