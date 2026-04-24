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

use App\Shopifyhome\Components\Product\SalesChannel\Route\Requestcontrol;
use App\Shopifyhome\Components\Shop\Shop;
use App\Shopifyhome\Components\Core\Common;

class Collection extends Common
{
    private $userId;

    private $sqsData;

    private $productIdsChunk = [];

    public const CHUNK_SIZE = 10;

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

        $terminate = false;
        $totalTime = 0;
        $openTime = microtime(true);

        $quickImport = $this->di->getObjectManager()->get(Import::class);
        
        $collectionUpdateResponse = [];
        do {
            $remoteResponse = $this->fetchCollectionFromShopify();

            $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0402 | Collection | Fetch Collection From Remote Response : '.print_r($remoteResponse, true));

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

                foreach($newProductCollection as $proId => $collection) {
                    $this->parseResponse($quickImport->updateProductCollection($this->userId, (string)$proId, $collection), $collectionUpdateResponse);
                }

                $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0403 | Collection | Collection Updated Successfully : '.print_r($collectionUpdateResponse, true));
            }
            else
            {
                if(isset($remoteResponse['msg']) && $remoteResponse['msg']=='completed') {
                    
                    $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0405 | Collection | All Collections Updated Successfully.');

                    return ['success' => true, 'message' => 'collections imported.', 'completed' => true];
                }
                $message = $remoteResponse['msg'] ?? $remoteResponse['message'];
                $realMsg = '';
                if(is_string($message)) {
                    $realMsg = $message;
                } elseif(is_array($message)) {
                    if(isset($message['errors'])) $realMsg = $message['errors'];
                }
                if(!empty($realMsg) && (strpos($realMsg, "Reduce request rates") || strpos($realMsg, "Try after sometime"))) {
                    $this->di->getObjectManager()->get(Requestcontrol::class)->pushToQueue($this->sqsData, time() + 8);

                    $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0302 | API throttle, pushing data again in sqs.');

                    return ['success' => true, 'message' => 'API throttle, pushing data again in sqs.'];
                }
                $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0302 | Error obtaining Data from Shopify due to : '.print_r($remoteResponse, true));
                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                return ['success' => false, 'message' => 'Error obtaining Data from Shopify.'];
            }

            $endTime = microtime(true);

            $elapsedtIME = $endTime - $openTime;
            $totalTime += $elapsedtIME;

            $terminate = $this->canTerminateExecution($elapsedtIME, $totalTime);

            if($terminate)
            {
                if(!isset($this->sqsData['is_last'])) {
                    $this->di->getObjectManager()->get(Requestcontrol::class)->pushToQueue($this->sqsData);

                    $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0404 | Collection | Collections Updated Partially, So for remaining products request pushed to queue.'.print_r($this->sqsData, true));

                    return ['success' => true, 'requeue' => true, 'completed' => false];
                }
                $this->getImportHelper()->createUserWiseLogs($this->userId, 'Process 0405 | Collection | All Collections Updated Successfully.');
                return ['success' => true, 'message' => 'collections imported.', 'completed' => true];
            }
        }
        while (!$terminate);
    }

    private function fetchCollectionFromShopify()
    {
        if(!isset($this->sqsData['is_last'])) {
            $product_ids = $this->fetchProductIdsChunk();
            if(empty($product_ids)) {
                return ['success'=>false, 'msg'=>'completed'];
            }
        }
        else {
            return ['success'=>false, 'msg'=>'completed'];
        }

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $remoteShopId = $this->sqsData['data']['remote_shop_id'];
        $subQuery = '';

        foreach ($product_ids as $productId) {
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

        $collectionData = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target, true)
            ->call('/shopifygql/query',[],['shop_id'=> $remoteShopId, 'query' => $query], 'POST');


        return $collectionData;
    }

    private function fetchProductIdsChunk()
    {
        if(empty($this->productIdsChunk))
        {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            // $mongo->setSource("product_container");
            // $collection = $mongo->getPhpCollection();
            $collection = $mongo->getCollection("product_container");

            $quickImport = $this->di->getObjectManager()->get(Import::class);
            $response = $quickImport->fetchIds($this->userId, 'product_id');

            if($response['success'])
            {
                if($response['total'])
                {
                    $this->productIdsChunk = array_chunk($response['ids'], self::CHUNK_SIZE);
                    
                    $arrayChunks = $this->productIdsChunk;

                    foreach ($arrayChunks as $key => $value) {
                        $return = $value;
                        unset($this->productIdsChunk[$key]);
                        if(count($this->productIdsChunk) == 0) {
                            $this->sqsData['is_last'] = true;
                        }

                        break;
                    }

                    return $return;
                }
                return [];
            }
            $errorMessage = \App\Shopifyhome\Components\Product\SalesChannel\Collection::class . '::fetchProductIdsChunk() : something went wrong while fetching products for collection update.' . PHP_EOL . print_r($this->sqsData, true);
            $this->di->getObjectManager()->get(Helper::class)->createProductImportLog($errorMessage, 'error');
            // $return = [
            //     'success' => false,
            //     'message' => 'something went wrong while fetching products for collection update.'
            // ];
            return [];
        }
        $arrayChunks = $this->productIdsChunk;
        foreach ($arrayChunks as $key => $value) {
            $return = $value;
            unset($this->productIdsChunk[$key]);
            if(count($this->productIdsChunk) == 0) {
                $this->sqsData['is_last'] = true;
            }

            break;
        }
        return $return;
    }

    private function canTerminateExecution($currentTime, $totalTime)
    {
        $allocatedTime = $this->sqsData['data']['sqs_timeout'];

        if(($totalTime + (2 * $currentTime)) > $allocatedTime) return true;
        return false;
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

    public function getImportHelper()
    {
        return $this->di->getObjectManager()->get(Helper::class);
    }
}