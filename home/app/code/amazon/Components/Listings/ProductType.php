<?php

namespace App\Amazon\Components\Listings;

use App\Amazon\Components\Common\Helper;
use Exception;
use App\Core\Components\Base as Base;
use MongoDB\BSON\ObjectId;

class ProductType extends Base
{
    public $userId;
    public $sourceMarketplace;
    const PRODUCT_CONTAINER = 'product_container';

    /**
     * Initiates the product type using the requested payload.
     *
     * This method initializes the product type by logging the requested payload and sending a message to an SQS queue.
     *
     * @param mixed $requestedPayload The payload containing information about the product type initiation.
     * @throws Exception If there is an error while initiating the product type.
     */
    public function initiateProductType($requestedPayload)
    {
        try {
            $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
            $date = date('d-m-Y');
            $logFile = "amazon/InitiateProductType/{$date}.log";
            $this->di->getLog()->logContent('Product Type initiated = ' . json_encode($requestedPayload, true), 'info', $logFile);

            if (isset($requestedPayload['source_marketplace'], $requestedPayload['target_marketplace'], $requestedPayload['source_marketplace']['source_shop_id'])) {
                $userId = $requestedPayload['user_id'] ?? $this->di->getUser()->id;
                $sourceMarketplace = $requestedPayload['source_marketplace']['marketplace'];
                $SourceShopId = $requestedPayload['source_marketplace']['source_shop_id'];
                $requestData = [
                    'userId' => $userId,
                    'sourceMarketplace' => $sourceMarketplace,
                    'sourceShopId' => $SourceShopId,
                    'targetMarketplace' => $requestedPayload['target_marketplace']['marketplace'],
                    'targetShopId' => $requestedPayload['target_marketplace']['target_shop_id']
                ];
                foreach ($this->di->getUser()->shops as $shop) {
                    if ($shop['_id'] == $SourceShopId) {
                        $appTag = $shop['apps'][0]['code'] ?? 'default';
                    }
                }
                $queueTaskData = [
                    'user_id' => $userId,
                    'message' => 'Amazon Product Type suggesting for products.',
                    'process_code' => 'amazon_fetch_product_type',
                    'marketplace' =>  $requestedPayload['target_marketplace']['marketplace'],
                    'app_tag' => $appTag
                ];
                $queuedTask = $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks');
                $queuedTaskId = $queuedTask->setQueuedTask($requestedPayload['target_marketplace']['target_shop_id'], $queueTaskData);
                if ($queuedTaskId) {

                    $handlerData = [
                        'type' => 'full_class',
                        'method' => 'fetchProductsChunk',
                        'class_name' => ProductType::class,
                        'queue_name' => 'amazon_fetch_product_type',
                        'user_id' => $userId,
                        'requestData' => $requestData,
                        'marketplace' => 'amazon',
                        'shop_id' => $SourceShopId,
                        'appTag' => $appTag,
                        'queuedTaskId' => $queuedTaskId,
                        'process_code' => 'amazon_fetch_product_type'
                    ];
                    $this->di->getLog()->logContent('Handler Data Prepared => ' . json_encode($handlerData), 'info', $logFile);

                    $rmqHelper->pushMessage($handlerData);
                     return ['success' => true, 'message' => 'Product Type suggestion process from Amazon has been started. For more updates, please check your overview section.'];

                } else {
                    return ['success' => false, 'message' => 'Product Type sugesstion process from Amazon under progress. For more updates, please check your Notification window.'];
                }
            }
            //code...
        } catch (\Exception $e) {
            $this->di->getLog()->logContent('Exception=' . json_encode($e), 'info', 'Exception.log');

            //throw $th;
        }
    }
    /**
     * Generate product type suggestions based on the received data.
     *
     * @param mixed $receivedData The data received for generating suggestions.
     * @return array An array of suggested product types.
     */
    public function ProductTypeSuggestion($receivedData)
    {
        try {
            $message = $receivedData['data'] ?? '';
            $this->di->getLog()->logContent('Received=' . json_encode($receivedData), 'info', 'SuggestionType.log');

            if (isset($message['productData'], $message['productData']['variants'])) {
                $productData = $message['productData']['variants'][0] ?? $message['productData']['details'];
                // print_r($productData);die;
                $userId = $message['user_id'];
                $sourceShopId = $message['shop_id'];
                $sourceMarketplace = $message['marketplace'];
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $target_marketplace = 'amazon';
                $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                $commonHelper = $this->di->getObjectManager()->get(Helper::class);

                $user_data = $user_details->getAllConnectedShops($userId, $target_marketplace);
                $targetShops = json_decode(json_encode($user_data), true);
                foreach ($targetShops as $targetShop) {
                    $targetShopId = $targetShop['_id'];
                    $additionalData['source'] = [
                        'marketplace' => $sourceMarketplace,
                        'shopId' => (string)$sourceShopId
                    ];
                    $additionalData['target'] = [
                        'marketplace' => 'amazon',
                        'shopId' => (string)$targetShopId
                    ];
                    $remoteShopId = $targetShop['remote_shop_id'];
                    // print_r($productData);die;
                    $params = [
                        'shop_id' => $remoteShopId,
                    ];
                    if (isset($productData['title'])) {
                        $params['itemName'] = $productData['title'];
                    }
                    $updateData = [];
                    $result = $commonHelper->sendRequestToAmazon('fetch-product-types', $params, 'GET');

                    if (isset($result['success'], $result['data']) && !empty($result['data'])) {
                        // $product_type[$productData['container_id']][$productData['source_product_id']] = array_column($result['data'], 'name')[0]??array_column($result['data'], 'name');
                        $saveData = [
                            "source_product_id" => (string)$productData['source_product_id'],
                            // required
                            'user_id' => $userId,
                            'source_shop_id' => (string) $sourceShopId,
                            'target_marketplace' => 'amazon',
                            'amazonProductType' => array_column($result['data'], 'name')[0] ?? array_column($result['data'], 'name'),
                            'shop_id' => (string)$targetShopId,
                            'container_id' => $productData['container_id']
                        ];
                        array_push($updateData, $saveData);
                        $res = $editHelper->saveProduct($updateData, $userId, $additionalData);
                        // print_r($res);die;

                        return ['success' => true, 'data' => array_column($result['data'], 'name')];
                    } else {
                        return ['success' => false, 'message' => 'Product Type cannot be fetched'];
                    }
                }
            }
            return ['success' => false, 'message' => 'Product Data not found'];
        } catch (\Exception  $e) {
            //throw $th
            $this->di->getLog()->logContent('Exception=' . json_encode($e), 'info', 'Exception.log');
        }
    }
    /**
     * Fetches products in chunks based on the SQS message received.
     *
     * @param mixed $sqsMessage The SQS message containing information about the products to fetch.
     * @return void
     */
     public function fetchProductsChunk($sqsMessage)
    {
        try {
            $date = date('d-m-Y');
            $logFile = "amazon/fetchProductChunk/{$date}.log";
            $this->di->getLog()->logContent('Message Received = '.getmypid() . json_encode($sqsMessage, true), 'info', $logFile);

            $rmqHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');

            $sqsData = $sqsMessage['requestData'] ?? [];
            if (isset($sqsData['userId'], $sqsData['sourceMarketplace'], $sqsData['sourceShopId'])) {

                $userId = $sqsData['userId'];
                $sourceMarketplace = $sqsData['sourceMarketplace'];
                $targetMarketplace = $sqsData['targetMarketplace'];
                $SourceShopId = $sqsData['sourceShopId'];
                $targetShopId = $sqsData['targetShopId'];
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $productContaineCollection = $mongo->getCollection(Helper::Refine_Product);
                $arrayParams = ["typeMap" => ['root' => 'array', 'document' => 'array']];
                $queuedTaskId = $sqsMessage['queuedTaskId'];

                // Initialize progress tracking variables
                $chunksProcessed = $sqsMessage['chunksProcessed'] ?? 0;
                $totalProductCount = $sqsMessage['totalProductCount'] ?? null;
                $chunkSize = 100; // Current chunk size

                // If this is the first chunk, get the total count
                if ($totalProductCount === null) {
                    $countAggregate = [
                        [
                            '$match' => [
                                'user_id' => $userId,
                                'target_shop_id' => (string)$targetShopId,
                            ]
                        ],
                        [
                            '$count' => 'total'
                        ]
                    ];
                    $countResult = $productContaineCollection->aggregate($countAggregate, $arrayParams)->toArray();
                    $totalProductCount = $countResult[0]['total'] ?? 0;
                    $this->di->getLog()->logContent('Total Product Count = ' . $totalProductCount, 'info', $logFile);
                }

                if (isset($sqsMessage['lastUpdatedKey'])) {
                    $aggregate[] = [
                        '$match' => [
                            '_id' => ['$gt' => new ObjectId($sqsMessage['lastUpdatedKey']['$oid'])]
                        ]
                    ];
                }
                $aggregate[] = [
                    '$match' => [
                        'user_id' => $userId,
                        'target_shop_id' => (string)$targetShopId,
                    ]
                ];
                $aggregate[] = [
                    '$sort' => ['_id' => 1],
                ];
                $aggregate[] = [
                    '$limit' => $chunkSize,
                ];
                $aggregate[] = ['$project' => ['title' => 1, 'source_product_id' => 1, 'container_id' => 1]];

                $options = [
                    "typeMap" => ['root' => 'array', 'document' => 'array'],
                ];
                $this->di->getLog()->logContent('Aggregate = '.getmypid() . json_encode($aggregate), 'info', $logFile);

                $productsData = $productContaineCollection->aggregate($aggregate, $options);
                $products = $productsData->toArray();
                // print_r(json_encode($products));die;
                $this->di->getLog()->logContent('Products = '.getmypid().'Count' . print_r(count($products), true), 'info', $logFile);

                if (!empty($products)) {

                    $data['requestData'] = $sqsData;
                    $data['products'] = $products;

                    $response = $this->fetchProductType($data);
                    $this->di->getLog()->logContent('Product Type received = '.getmypid() . json_encode($response, true), 'info', $logFile);
                    if(!$response['success']){
                        $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($queuedTaskId, 100, 'Product Type assignment is completed.', false, null);
                        $notificationData = [
                            'user_id' => $userId,
                            'marketplace' => $targetMarketplace,
                            'appTag' => $this->di->getAppCode()->getAppTag(),
                            'message' => 'Product Type assignment is completed.',
                            'severity' => 'success',
                            'process_code' => 'amazon_fetch_product_type'
                        ];
                        $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($targetShopId, $notificationData);
                        return true;//for success false case
                    }

                    // Increment chunks processed and calculate progress
                    $chunksProcessed++;
                    $totalChunks = $totalProductCount > 0 ? ceil($totalProductCount / $chunkSize) : 1;
                    $currentProgress = ($chunksProcessed / $totalChunks) * 100;
                    
                    // Ensure progress doesn't exceed 99 until complete
                    if ($currentProgress >= 99.9) {
                        $currentProgress = 99;
                    }

                    // Update progress in queued task
                    $progressMessage = "Processing products pages {$chunksProcessed} of {$totalChunks} ( Total Products: " . $totalProductCount . ")";
                    $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($queuedTaskId, $currentProgress, $progressMessage, false, null);
                    $this->di->getLog()->logContent('Progress Updated = ' . $currentProgress . '% - ' . $progressMessage, 'info', $logFile);

                    if (isset($response['lastUpdatedKey'])) {
                        $sqsMessage['lastUpdatedKey'] = $response['lastUpdatedKey'];
                    }
                    
                    // Add progress tracking data to the message for next iteration
                    $sqsMessage['chunksProcessed'] = $chunksProcessed;
                    $sqsMessage['totalProductCount'] = $totalProductCount;
                    
                    // print_r(json_encode($sqsMessage));die("no product");
                    $this->di->getLog()->logContent('Message Pushed back = '.getmypid() . json_encode($sqsMessage, true), 'info', $logFile);

                    $rmqHelper->pushMessage($sqsMessage);
                    return true;
                } else {
                    // No more products to process, mark as complete
                    $this->di->getObjectManager()->get('\App\Connector\Models\QueuedTasks')->updateFeedProgress($queuedTaskId, 100, 'Product Type assignment is completed.', false, null);
                    $notificationData = [
                        'user_id' => $userId,
                        'marketplace' => $targetMarketplace,
                        'appTag' => $this->di->getAppCode()->getAppTag(),
                        'message' => 'Product Type assignment is completed.',
                        'severity' => 'success',
                        'process_code' => 'amazon_fetch_product_type'
                    ];
                    $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')->addNotification($targetShopId, $notificationData);
                    return true;
                }
            }
        } catch (\Exception $e) {
            //throw $th;
            $this->di->getLog()->logContent('Exception=' . json_encode($e), 'info', 'Exception.log');
        }
    }
    /**
     * Fetches the product type based on the provided data.
     *
     * This method retrieves the product type based on the source marketplace, source shop ID, and target shop ID
     * provided in the data array. If the user ID is not specified in the data, it defaults to the currently authenticated user's ID.
     *
     * @param array $data An array containing the necessary data elements.
     * @return string The product type based on the provided data.
     */
    public function fetchProductType($data)
    {
        try {
            if (isset($data['requestData']['sourceMarketplace'], $data['requestData']['sourceShopId'], $data['requestData']['targetShopId'])) {
                $userId = $data['requestData']['userId'] ?? $this->di->getUser()->id;
                // $userId = '66cdbb699c331d2fe701b4d8';
                $sourceShopId = $data['requestData']['sourceShopId'];
                $homeShopId = $data['requestData']['targetShopId'];
                $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $remoteShop = $userDetails->getShop($homeShopId, $userId);
                $product_type = [];
                $lastupadtedKeyid = '';
                $sourceMarketplace = $data['requestData']['sourceMarketplace'];
                $updateData = [];
                $additionalData['source'] = [
                    'marketplace' => $sourceMarketplace,
                    'shopId' => (string)$sourceShopId
                ];
                $additionalData['target'] = [
                    'marketplace' => 'amazon',
                    'shopId' => (string)$homeShopId
                ];
                $products = $data['products'] ?? [];
                if ($remoteShop && !empty($remoteShop)) {
                    $remoteShopId = $remoteShop['remote_shop_id'];
                    $params = [
                        'shop_id' => $remoteShopId,
                    ];

                    if (!empty($products)) {
                        foreach ($products as $product) {

                            if (isset($product['title'])) {
                                $params['itemName'] = $product['title'];
                            }

                            $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                            $result = $commonHelper->sendRequestToAmazon('fetch-product-types', $params, 'GET');

                            if (isset($result['success'], $result['data']) && !empty($result['data'])) {
                                $product_type[$product['container_id']][$product['source_product_id']] = array_column($result['data'], 'name')[0] ?? array_column($result['data'], 'name');
                                $saveData = [
                                    "source_product_id" => (string)$product['source_product_id'],
                                    // required
                                    'user_id' => $userId,
                                    'source_shop_id' => (string) $sourceShopId,
                                    'target_marketplace' => 'amazon',
                                    'amazonProductType' => array_column($result['data'], 'name')[0] ?? array_column($result['data'], 'name'),
                                    'shop_id' => (string)$homeShopId,
                                    'container_id' => $product['container_id']
                                ];
                                $lastupadtedKeyid = $product['_id'] ?? '';
                                array_push($updateData, $saveData);

                                // return ['success' => true, 'data' => array_column($result['data'], 'name')];
                            } else {
                                $lastupadtedKeyid = $product['_id'] ?? '';

                                $error[$product['container_id']][$product['source_product_id']] =  ['success' => false, 'message' => 'Error fetching product_types'];
                            }
                        }

                        $editHelper =  $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                        $res = $editHelper->saveProduct($updateData, $userId, $additionalData);
                        return ['success' => true, 'lastUpdatedKey' => $lastupadtedKeyid];
                    }

                    return ['success' => false, 'message' => 'Products are not present for this user.'];
                }
                return ['success' => false, 'message' => 'Target Shop Not Connected.'];
            }

            return ['success' => false, 'message' => 'Required Params Missing.'];
        } catch (Exception $e) {
            print_r($e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
        }
    }
}
