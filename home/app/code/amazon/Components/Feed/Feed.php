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
 * @package     Ced_Amazon
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Amazon\Components\Feed;

use App\Amazon\Service\Order;
use App\Core\Components\Concurrency;
use Exception;
use App\Amazon\Components\LocalDeliveryHelper;
use App\Amazon\Components\Bulletin\Bulletin;
use App\Core\Components\Base as Base;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\Product\Product;
use Aws\S3\Exception\S3Exception as S3_exception;
use Aws\S3\S3Client;

#[\AllowDynamicProperties]
class Feed extends Base
{
    use Concurrency;

    private ?string $_user_id = null;

    private $configObj;

    private array $_queue_data = [];

    private $_amazon_shop_id;

    private $_amazon_remote_shop_id;

    private $_user_details;

    private $_baseMongo;

    public const NOT_SUBMITTED = '_NOT_SUBMITTED_';

    public const SUBMITTED = '_SUBMITTED_';

    public const IN_PROGRESS = '_IN_PROGRESS_';

    public const FAILED = '_FAILED_';

    public const DONE = '_DONE_';

    public const CANCELLED = '_CANCELLED_';

    public const DONE_NO_DATA = '_DONE_NO_DATA_';

    public const SYNCED = '_SYNCED_';

    public const FEED_TYPE = [
        '_POST_FLAT_FILE_LISTINGS_DATA_' => 'product',
        '_POST_INVENTORY_AVAILABILITY_DATA_' => 'inventory',
        '_POST_PRODUCT_PRICING_DATA_' => 'price',
        '_POST_PRODUCT_IMAGE_DATA_' => 'image',
        '_POST_PRODUCT_DATA_' => 'delete',
        'POST_FLAT_FILE_LISTINGS_DATA' => 'product',
        'POST_INVENTORY_AVAILABILITY_DATA' => 'inventory',
        'POST_PRODUCT_PRICING_DATA' => 'price',
        'POST_PRODUCT_IMAGE_DATA' => 'image',
        'POST_PRODUCT_DATA' => 'delete',
        'JSON_LISTINGS_FEED' => 'inventory',
        'JSON_LISTINGS_FEED_INVENTORY' => 'inventory',
        'JSON_LISTINGS_FEED_IMAGE' => 'image',
        'JSON_LISTINGS_FEED_DELETE' => 'delete',
        'JSON_LISTINGS_FEED_PRICE' => 'price',
        'JSON_LISTINGS_FEED_UPLOAD' => 'upload',
        'JSON_LISTINGS_FEED_UPDATE' => 'update',
        'JSON_LISTING_FEED' => 'JSON Feed'

    ];

    public function init($request = [])
    {
        // print_r($request);die;
        if (isset($request['user_id']))
            $this->_user_id = (string) $request['user_id'];
        else
            $this->_user_id = (string) $this->di->getUser()->id;

        $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $this->_source_shop_id = (string) $request['source']['shopId'];
        if (isset($request['target'])) {
            $this->_shop_id = (string) $request['target']['shopId'];
            $shop = $this->_user_details->getShop($this->_shop_id, $this->_user_id);
        } else {
            $shop = $this->_user_details->getDataByUserID($this->_user_id, 'amazon');
            $this->_shop_id = $shop['_id'];
        }

        $this->_remote_shop_id = $shop['remote_shop_id'];
        //        $this->_site_id = $shop['warehouses'][0]['seller_id'];
        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        return $this;
    }

    public function process($feed): void
    {
        $result = $feed['result'] ?? [];
        foreach ($result as $res) {
            if ($res['success']) {
                $response = $res['response'] ?? [];
                $specifics = $res['specifics'] ?? [];
                if (isset($response['FeedSubmissionId']) && $response['FeedSubmissionId']) {
                    $feedToSave = [
                        'feed_id' => $response['FeedSubmissionId'],
                        'type' => $response['FeedType'],
                        'status' => $response['FeedProcessingStatus'],
                        'feed_created_date' => $response['SubmittedDate'],
                        'feed_executed_date' => '',
                        'feed_file' => str_replace('"', "'", $res['feed']),
                        'response_file' => $response,
                        'marketplace_id' => $feed['data']['marketplace_id'],
                        'profile_id' => '',
                        'shop_id' => $feed['data']['home_shop_id'],
                        'user_id' => $this->_user_id,
                        'specifics' => $specifics
                    ];
                    $this->save($feedToSave);
                }
            }
        }
    }

    public function getAll($rawData, $userId = false)
    {
        $userId = (string) $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::FEED_CONTAINER);
        $conditionalQuery = [];
        if (isset($rawData['filter']) || isset($rawData['search'])) {
            $conditionalQuery = $this->buildFilterAggregate($rawData);
            // $conditionalQuery = $mongo->search($rawData);
        }

        $limit = $rawData['data']['count'] ?? 25;
        $page = $rawData['data']['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;
        $limit = $limit * $rawData['data']['activePage'];

        $conditionalQuery["user_id"] = $userId;
        $conditionalQuery["shop_id"] = $this->_shop_id;
        $conditionalQuery["source_shop_id"] = $this->_source_shop_id;
        if (count($conditionalQuery)) {
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        $countAggregation = $aggregation;

        $countAggregation[] = [
            '$count' => 'count'
        ];

        $aggregation[] = ['$project' => ['response_file' => 0, 'feed_file' => 0]];

        $aggregation[] = ['$sort' => ['feed_created_date' => -1]];

        $countFeeds = $collection->aggregate($countAggregation)->toArray();

        $aggregation[] = ['$sort' => ['feed_created_date' => -1]];

        $aggregation[] = ['$limit' => (int) $limit];

        $aggregation[] = ['$skip' => (int) $offset];


        $rows = $collection->aggregate($aggregation)->toArray();
        $count = count($countFeeds) ? $countFeeds[0]['count'] : 0;
        return ['success' => true, 'data' => ['rows' => $rows, 'count' => $count]];
    }

    public const IS_EQUAL_TO = 1;

    public const IS_CONTAINS = 3;

    public function buildFilterAggregate($filterParams = [])
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {

            foreach ($filterParams['filter'] as $key => $value) {

                $key = trim((string) $key);

                if (is_string($value)) {
                    $conditions['$and'][] = [$key => $value];
                } elseif (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    if (is_bool($value[self::IS_EQUAL_TO])) {
                        $conditions['$and'] = [$key => $value[self::IS_EQUAL_TO]];
                    } else {
                        if ($key == 'ids') {
                            $val1 = trim((string) $value[self::IS_EQUAL_TO], "_");
                            $val2 = "_" . $val1 . "_";
                            $conditions['$and'][] = [
                                '$or' => [
                                    [
                                        'specifics.ids' => [
                                            '$regex' => '^' . $val1 . '$',
                                            '$options' => 'i'
                                        ]
                                    ],
                                    [
                                        'specifics.ids' => [
                                            '$regex' => '^' . $val2 . '$',
                                            '$options' => 'i'
                                        ]
                                    ]
                                ]
                            ];
                        } elseif ($key == 'productId') {
                            $productId = trim((string) $value[self::IS_EQUAL_TO]);
                            if (!empty($productId)) {
                                $conditions['$and'][] = [
                                    "actionData.$productId" => ['$exists' => true]
                                ];
                            }
                        } elseif ($key !== 'feed_id') {
                            $val1 = trim((string) $value[self::IS_EQUAL_TO], "_");
                            $val2 = "_" . $val1 . "_";
                            $conditions['$and'][] = [
                                '$or' => [
                                    [
                                        $key => [
                                            '$regex' => '^' . $val1 . '$',
                                            '$options' => 'i'
                                        ]
                                    ],
                                    [
                                        $key => [
                                            '$regex' => '^' . $val2 . '$',
                                            '$options' => 'i'
                                        ]
                                    ]
                                ]
                            ];
                        } else {
                            $conditions[$key] = [
                                '$regex' => '^' . $value[self::IS_EQUAL_TO] . '$',
                                '$options' => 'i'
                            ];
                        }
                    }
                } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                    if ($key == 'ids') {
                        $conditions['$and'][] =
                            [
                                'specifics.' . $key => [
                                    '$regex' => trim(addslashes((string) $value[self::IS_CONTAINS])),
                                    '$options' => 'i'
                                ]
                            ];
                    } else {
                        $conditions['$and'][] =
                            [
                                $key => [
                                    '$regex' => trim(addslashes((string) $value[self::IS_CONTAINS])),
                                    '$options' => 'i'
                                ]
                            ];
                    }
                }
            }
        }

        if (isset($filterParams['search'])) {
            $conditions['$and'][] = ['$text' => ['$search' => trim(addslashes((string) $filterParams['search']))]];
        }

        return $conditions;
    }

    public function save($feedToSave, $userId = false, $uniqueKeys = ['feed_id', 'user_id'])
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable(Helper::FEED_CONTAINER);
        if ($collection) {
            foreach ($uniqueKeys as $key) {
                if (isset($feedToSave[$key])) {
                    $filters[$key] = $feedToSave[$key];
                }
            }

            try {
                $feedData = $collection->findOne($filters, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
                if ($feedData && count($feedData)) {
                    $feedToSave['_id'] = $feedData['_id'];
                    foreach ($feedData as $key => $value) {
                        if (!isset($feedToSave[$key])) {
                            $feedToSave[$key] = $value;
                        }
                    }

                    $res = $collection->updateOne(['_id' => $feedToSave['_id']], ['$set' => $feedToSave]);
                } else {
                    //  $feedToSave['_id'] = $this->_baseMongo->getCounter('feed_id', $this->_user_id);
                    $res = $collection->insertOne($feedToSave);
                }

                return ['success' => true, 'message' => 'Feed Send Successfully'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => $e->getMessage(), 'code' => 'exception'];
            }
        }
    }

    public function sync($data)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $feedCollection = $mongo->getCollectionForTable(Helper::FEED_CONTAINER);
            $feed_ids = $data['feed_id'] ?? "";
            if(!empty($feed_ids))
            {
                foreach ($feed_ids as $feed_id) {
                    $feeds = [];
                    $messageIds = [];
                    $result = [];
                    if ($feed_id != "") {
                        $feeds = $feedCollection->find(['feed_id' => $feed_id, "user_id" => $this->_user_id, "shop_id" => $this->_shop_id, 'source_shop_id' => $this->_source_shop_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
                        if(!empty($feeds)) {
                            $feeds = $feeds->toArray();
                        } else {
                            $result = ['success' => false, 'message' => 'Feed data not found']; 
                        }
                    } else {
                        $result = ['success' => false, 'message' => 'Feed Id not found'];
                    }

                    if (isset($feeds[0]) && !empty($feeds[0])) {
                        $feed = $feeds[0];
                        $feedType = $feed['type'];
                        $requestData = [];
                        $requestData = [
                            'bucket' => $feed['bucket'],
                            'user_id' => $this->_user_id,
                            'key' => $feed['s3_response_id']
                        ];
                        $response = $this->getBucketData($requestData);
                        $response = json_decode(json_encode($response), true);

                        if (isset($response['data']['response'])) {
                            $responseFile = $response['data']['response'];
                            $submitData = $requestData;
                            $submitData['key'] = $feed['s3_feed_id'];
                            $contentType = false;
                            if (str_contains((string) $feedType, 'JSON')) {
                                $contentType = 'JSON';
                                $submitRes = $this->getBucketData($submitData);
                                $submitRes = json_decode(json_encode($submitRes), true);
                                if(isset($submitRes['success'] , $submitRes['data']) && $submitRes['success'])
                                {
                                    $submitRes = json_decode(json_encode($submitRes['data']) , true);
                                    if(isset($submitRes['identifiers'])) {
                                        $idsInS3 = $submitRes['identifiers'];
                                    } else {
                                        $idsInS3 = $submitRes['ids'] ?? [];
                                    }

                                    foreach ($idsInS3 as $messageId) {
                                        $msgs = explode('_', (string) $messageId);
                                        $messageIds[$msgs[1]] = $msgs[0];
                                    }
                                }
                            }

                            $this->setErrorInProduct($responseFile , $feed , false , false , $contentType , $messageIds , false);
                            $productComponent = $this->getDi()->getObjectManager()->get(Product::class);
                            if($feedType !== 'POST_ORDER_FULFILLMENT_DATA' && $feedType !== 'POST_ORDER_ACKNOWLEDGEMENT_DATA') {
                                $productComponent->removeTag([], [], false, false, false, [], $feed);
                            }

                            $result = ['success' => true, 'message' => 'Feed Synced Successfully'];
                        }
                        else {
                            $result = ['success' => false, 'message' => 'File not found on s3'];
                        }
                    } else {
                        $result = ['success' => false, 'message' => 'Unable to fetch feed'];
                    }
                }
            }
        } catch (Exception $e) {
            $exceptionLogFile = 'exceptions/' . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('Feed sync  exception for userId ' . $data['user_id'] . ':  ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(), 'info', $exceptionLogFile);
            $result = ['success' => false, 'message' => $e->getMessage(), 'code' => 'exception'];
        }

        return $result;
    }

    public function getDataByPrefixUsings3($data)
    {
        $sourceProductIds = [];
        $config = include BP . '/app/etc/aws.php';
        $s3 = new S3Client($config);
        // List objects with the specified prefix
        try{
            $objects = $s3->listObjectsV2([
                'Bucket' => $data['bucket'],
                'Prefix' => $data['prefix'],
            ]);
            // Check if any objects are found
            if (!empty($objects['Contents'])) {
                foreach ($objects['Contents'] as $object) {
                    $objectKey = $object['Key'];

                    // Download the object content
                    $objectData = $s3->getObject([
                        'Bucket' => $data['bucket'],
                        'Key'    => $objectKey,
                    ]);

                    // Now 'body' contains the content of the object
                    $content = $objectData['Body']->getContents();
                    $content = json_decode((string) $content,true);
                    if(!empty($content['feedContent'])) {
                        $ids = array_map('strval', array_keys($content['feedContent']));
                        $sourceProductIds = [...$ids, ...$sourceProductIds];
                    }
                }
            }

            return array_unique($sourceProductIds);
        } catch(Exception $e) {
            $this->di->getLog()->logContent('Exception:getDataByPrefixUsings3 '.json_encode($e->getMessage()), 'info', 'exception.log');
            return $sourceProductIds;
        }
    }

    public function deleteDataFromS3ByPrefix($data)
    {
        $config = include BP . '/app/etc/aws.php';
        $s3 = new S3Client($config);
        try {
            $objects = $s3->listObjectsV2([
                'Bucket' => $data['bucket'],
                'Prefix' => $data['prefix'],
            ]);
            if (!empty($objects['Contents'])) {
                foreach ($objects['Contents'] as $object) {
                    $s3->deleteObject([
                        'Bucket' => $data['bucket'],
                        'Key' => $object['Key'],
                    ]);
                }
            }

            return ['success' => true, 'message' => 'Data deleted successfully'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception, deleteDataFromS3ByPrefix(): ' . print_r($e->getMessage()), 'info', 'exception.log');
        }
    }

    public function delete($data)
    {
        try {
            if (isset($data['feeds']) && !empty($data['feeds'])) {
                $feedIds = array_column($data['feeds'], 'feed_id');
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollectionForTable(Helper::FEED_CONTAINER);
                $status = $collection->deleteMany(['feed_id' => ['$in' => $feedIds]], ['w' => true]);
                if ($status->isAcknowledged()) {
                    return ['success' => true, 'message' => 'Feed Deleted Successfully'];
                }
                return ['success' => false, 'message' => 'Some error occurred in feed deletion.'];
            }

            return ['success' => false, 'message' => 'No feed found.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'exception'];
        }
    }

    public function getFeedResponse($rawData)
    {
        if (isset($rawData['data']['key'], $rawData['data']['bucket'])) {
            $data = [
                'key' => $rawData['data']['key'],
                'bucket' => $rawData['data']['bucket']
            ];
            return $this->getBucketData($data, true);
        }

        return ['succsss' => false, 'message' => 'Required Params Missing/Something Went Wrong'];
    }

    public function setErrorInProduct($response, $feed, $viewFile = false, $marketplace = false, $contentType = false, $messageIds = [], $customError = false)
    {
        $locaDeliveryIds = $feed['specifics']['localDelivery'] ?? [];
        $localDeliverySourceId = [];
        $productComponent = $this->di->getObjectManager()->get(Product::class)->init();
        if ($feed['type'] == '_POST_FLAT_FILE_LISTINGS_DATA_' || $feed['type'] == 'POST_FLAT_FILE_LISTINGS_DATA') {
            if ($customError) {
                $this->setFetalError($feed);
            } else {
                $responseLines = explode("\n", (string) $response);
                //record processed line from response data
                $recordProcessedLine = $responseLines[1];
                $recordProcessedLineArray = explode("\t", $recordProcessedLine);
                $recordsProcessed = $recordProcessedLineArray[3];
                //record successful line from response data
                $recordSuccessfulLine = $responseLines[2];
                $recordsSuccessfulLineArray = explode("\t", $recordSuccessfulLine);
                $recordsSuccessful = $recordsSuccessfulLineArray[3];
                if ($recordsSuccessfulLineArray[1] == "Number of records successful" && $recordProcessedLineArray[1] == "Number of records processed") {
                    if ($recordsSuccessful < $recordsProcessed) {
                        unset($responseLines[0]);
                        unset($responseLines[1]);
                        unset($responseLines[2]);
                        unset($responseLines[3]);

                        $error = [];
                        $heading = [];
                        $skuError = [];
                        $skuWarn = [];
                        $i = 0;
                        foreach ($responseLines as $key => $rLine) {
                            $bits = explode("\t", $rLine);
                            if ($key == 4) {
                                $heading = $bits;
                                continue;
                            }
                            if (count($bits) == count($heading)) {
                                foreach ($heading as $hKey => $hValue) {
                                    $error[$i][$hValue] = $bits[$hKey];
                                }

                                $i++;
                            }
                        }

                        $skuLD = $feed['skuLD'] ?? [];
                        foreach ($error as $err) {
                            if (!empty($skuLD)) {
                                if (array_key_exists($err['sku'], $skuLD)) {
                                    if (isset($err['error-code']) && !empty($err['error-message'])) {
                                        $errorLDList[$skuLD[$err['sku']]][] = $err['error-code'] . ' : ' . mb_convert_encoding($err['error-message'], 'UTF-8', 'ISO-8859-1');
                                    } else {
                                        $errorLDList[$skuLD[$err['sku']]][] = mb_convert_encoding($err['error-message'], 'UTF-8', 'ISO-8859-1');
                                    }
                                }
                            } else {
                                if ($err['sku'] && $err['sku'] != '' && $err['error-type'] == 'Error') {
                                    if ($feed['user_id'] == "6434e415f120a4a82d0aad94" && $feed['shop_id'] == "73543") {
                                        if (isset($err['error-code']) && !empty($err['error-message'])) {
                                            $skuError[$err['sku']][] = $err['error-code'] . ' : ' . mb_convert_encoding($err['error-message'], 'Windows-1252', 'UTF-8');
                                        } else {
                                            $skuError[$err['sku']][] = mb_convert_encoding($err['error-message'], 'Windows-1252', 'UTF-8');
                                        }
                                    } elseif ($feed['user_id'] == "64929b3f5aa0b3c9ef06a393" && $feed['shop_id'] == "86328") {
                                        if (isset($err['error-code']) && !empty($err['error-message'])) {
                                            $skuError[$err['sku']][] = $err['error-code'] . ' : ' . $err['error-message'];
                                        } else {
                                            $skuError[$err['sku']][] = $err['error-message'];
                                        }
                                    } elseif (isset($err['error-code']) && !empty($err['error-message'])) {
                                        $skuError[$err['sku']][] = $err['error-code'] . ' : ' . mb_convert_encoding($err['error-message'], 'UTF-8', 'ISO-8859-1');
                                    } else {
                                        $skuError[$err['sku']][] = mb_convert_encoding($err['error-message'], 'UTF-8', 'ISO-8859-1');
                                    }
                                }

                                if($err['sku'] && $err['sku'] != '' && $err['error-type'] == 'Warning'){
                                    $skuWarn[$err['sku']][] = [
                                        'code' => $err['error-code'],
                                        'message' => $err['error-message']
                                    ];
                                }
                            }
                        }

                        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                        if (!empty($skuError)) {
                            $variantError = [];
                            $productData = false;
                            foreach ($skuError as $sku => $error) {
                                $params = [
                                    "sku" => $sku,
                                    'user_id' => $feed['user_id'],

                                    'target_shop_id' => $feed['shop_id'],
                                    'source_shop_id' => $feed['source_shop_id']

                                ];
                                $productsData = $helper->getMultipleProductBySku($params);
                                foreach ($productsData as $value) {
                                    if (isset($value['shop_id']) && ($value['shop_id'] == $feed['source_shop_id'] || $value['shop_id'] == $feed['shop_id'])) {
                                        $productData = $value;
                                        break;
                                    }
                                }

                                if (isset($productData['source_product_id']) && $productData['source_product_id']) {
                                    $variantError[$productData['source_product_id']] = $error;
                                }
                            }


                            $productComponent = $this->di->getObjectManager()->get(Product::class)->init();

                            $productErrorList = array_keys($variantError);
                            $dataToset['productErrorList'] = $variantError;
                            $productComponent->marketplaceUpdate($dataToset,$productErrorList, [], "", "", "",false,[],false,false, $feed);
                            if ($recordsSuccessful > 0) {
                                $errorVariantList = array_keys($variantError);
                                $feedProductList = $feed['specifics']['ids'];
                                $feedProductList = $feed['specifics']['ids'];
                                $variantList = array_diff($feedProductList, $errorVariantList);
                                $variantList = array_values($variantList);
                                $homeShopId = (string) $feed['specifics']['shop_id'];
                                $productComponent->setUploadedStatus($variantList, $feed);
                            }
                        }

                        // if(!empty($skuWarn)){
                        //     $variantWarn = [];
                        //         $productData = false;

                        //         foreach ($skuWarn as $sku => $warning) {
                        //             $params = [
                        //                 "sku" => (string) $sku,
                        //                 'user_id' => $feed['user_id'],

                        //                 'target_shop_id' => (string)$feed['shop_id'],
                        //                 'source_shop_id' => (string)$feed['source_shop_id']

                        //             ];
                        //             $productsData = $helper->getMultipleProductBySku($params);
                        //             foreach ($productsData as $value) {
                        //                 if (isset($value['shop_id']) && ($value['shop_id'] == $feed['source_shop_id'] || $value['shop_id'] == $feed['shop_id'])) {
                        //                     $productData = $value;
                        //                     break;
                        //                 }
                        //             }
                        //             if (isset($productData['source_product_id']) && $productData['source_product_id']) {
                        //                 $variantWarn[$productData['source_product_id']] = $warning;
                        //             }
                        //             $productWarnList = array_keys($variantWarn);
                        //             $dataTo['productWarnList'] = $variantWarn;
                        //             $productComponent = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')->init();
                        //             $productComponent->marketplaceUpdate($dataTo,[], [], "", "", "",false,[],false,false, $feed,$productWarnList);

                        //             // $productComponent->saveWarningInProduct($variantWarn, false, false, false, false, [], $feed);
                        //         }
                        //     }
                        if (!empty($errorLDList)) {
                            $localDeliveryComponent = $this->di->getObjectManager()->get(LocalDeliveryHelper::class);
                            $localDeliveryComponent->saveErrorInProduct($errorLDList, false, false, false, false, [], $feed, []);
                        }
                    } else {

                        $productComponent = $this->di->getObjectManager()->get(Product::class)->init();

                        $this->removeErrorFromProduct([], $feed);
                        if ($recordsProcessed == $recordsSuccessful && $recordsSuccessful > 0) {
                            $feedProductList = $feed['specifics']['ids'];
                            $homeShopId = (string) $feed['specifics']['shop_id'];
                            $productComponent->setUploadedStatus($feedProductList, $feed);
                        }

                        $this->saveActivityInProduct($feed['specifics']['ids'], $feed, 'Success');
                    }
                } else {
                    $this->setLanguageError($feed);
                }
            }
        } else {
            if ($feed['type'] == '_POST_ORDER_FULFILLMENT_DATA_' || $feed['type'] == 'POST_ORDER_FULFILLMENT_DATA') {
                $orderShipment = $this->di->getObjectManager()->get('App\Connector\Service\Shipment');
                $xml = simplexml_load_string((string) $response);
                $json = json_encode($xml);
                $response = json_decode($json, true);
                $xml_view = simplexml_load_string((string) $viewFile);
                $json_view = json_encode($xml_view);
                $response_view = json_decode($json_view, true);
                $result = [];
                if (isset($response_view['Message'])) {

                    if (!isset($response_view['Message'][0])) {
                        $temp[] = $response_view['Message'];
                        unset($response_view['Message']);
                        $response_view['Message'] = $temp;
                        $temp = [];
                    }

                    foreach ($response_view['Message'] as $amazonId) {
                        $allIds[$amazonId['MessageID']] = $amazonId['OrderFulfillment']['AmazonOrderID'];
                    }
                }

                $this->configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
                $this->configObj->setUserId($feed['user_id']);
                $this->configObj->setTarget('amazon');
                $this->configObj->setTargetShopId($feed['shop_id']);
                $this->configObj->setSourceShopId($feed['source_shop_id']);
                $this->configObj->setGroupCode('email');
                $shipmentMailConfig = $this->configObj->getConfig('email_on_failed_shipment');
                $shipmentMailConfig = json_decode(json_encode($shipmentMailConfig, true), true);
                $mailSetting = $shipmentMailConfig[0]['value'] ?? false;
                $recordsSuccessful = $response['Message']['ProcessingReport']['ProcessingSummary']['MessagesSuccessful'];
                if (isset($response['Message']) && isset($response['Message']['ProcessingReport']['Result'])) {
                    $result = $response['Message']['ProcessingReport']['Result'];
                    if (!isset($result[0])) {
                        $temp[] = $result;
                        unset($result);
                        $result = $temp;
                    }

                    foreach ($result as $value) {
                        if (isset($value['ResultCode']) && $value['ResultCode'] == 'Error') {
                            $messageId = $value['MessageID'];
                            $amzId = $allIds[$messageId];
                            $errorIds[] = $amzId;
                            $errorMessage = $this->getMappedErrorMessage($value['ResultDescription']);
                            $orderShipment->setSourceStatus($amzId, $marketplace, false, $errorMessage, $feed['user_id'], $mailSetting);
                        }
                    }
                }

                $allIdValues = array_values($allIds);
                if (!empty($errorIds)) {
                    $validIds = array_diff($allIdValues, $errorIds);
                } else {
                    $validIds = $allIdValues;
                }

                if ($recordsSuccessful > 0) {
                    foreach ($validIds as $orderId) {
                        $orderShipment->setSourceStatus($orderId, $marketplace, true, 'Unknown', $feed['user_id']);
                    }
                }

                return true;
            }
            if ($contentType == 'JSON') {
                if (isset($response['summary']['messagesProcessed'])) {
                    $recordsProcessed = $response['summary']['messagesProcessed'];
                }
                if (isset($response['summary']['messagesAccepted'])) {
                    $recordsSuccessful = $response['summary']['messagesAccepted'];
                }

                $variantError = [];
                if ($recordsSuccessful < $recordsProcessed) {
                    if (isset($response['issues']) && !empty($response['issues'])) {
                        $errors = $response['issues'];
                        if (!empty($messageIds)) {
                            foreach ($errors as $error) {
                                if (isset($error['severity'], $error['message']) && $error['severity'] == 'ERROR') {
                                    //array_key_exists(): Argument #1 ($key) must be a valid array offset type
                                    // $sourceProductId = $messageIds[$error['messageId']] ?? [];
                                    $sourceProductId = $messageIds[$error['messageId']] ?? null;
                                    $errorCode = $error['code'] ?? $error['messageId'];
                                    if (!is_null($sourceProductId)) {
                                        if (array_key_exists($sourceProductId, $locaDeliveryIds)) {
                                            $localDeliverySourceId[$sourceProductId] = $errorCode . " : " . $error['message'];
                                        } else {
                                            $variantError[$sourceProductId][] = $errorCode . " : " . $error['message'];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                if ($feed['type'] == 'JSON_LISTINGS_FEED_UPLOAD' && $recordsSuccessful > 0) {
                    $errorVariantList = array_keys($variantError);
                    $feedProductList =  $feed['specifics']['ids'];
                    $variantList = array_diff($feedProductList, $errorVariantList);
                    $variantList = array_values($variantList);
                    $homeShopId = (string) $feed['specifics']['shop_id'];
                    $productComponent->setUploadedStatus($variantList, $feed);
                }

                if ($feed['type'] == 'JSON_LISTINGS_FEED_IMAGE') {
                    // $this->removeImageFromS3($feed['specifics']['ids'], $feed['source_shop_id']);
                } elseif ($feed['type'] == 'JSON_LISTINGS_FEED_DELETE') {
                    $sourceProductIds = $feed['specifics']['ids'];
                    $targetShopId = (string) $feed['specifics']['shop_id'];
                    $productComponent = $this->di->getObjectManager()->get(Product::class)->init();
                    $productComponent->removeStatusFromProduct($sourceProductIds, $targetShopId, false, false, $feed);
                }
            }
            elseif ($feed['type'] == '_POST_ORDER_ACKNOWLEDGEMENT_DATA_' || $feed['type'] == 'POST_ORDER_ACKNOWLEDGEMENT_DATA') {
                $orderService = $this->di->getObjectManager()->get(Order::class);
                $responseXml = simplexml_load_string((string) $response);
                $responseJson = json_encode($responseXml);
                $responseFile = json_decode($responseJson, true);
                $submitXml = simplexml_load_string((string) $viewFile);
                $submitJson = json_encode($submitXml);
                $submitFile = json_decode($submitJson, true);
                $orderService->updateAcknowledgedOrder($submitFile, $responseFile, $feed['user_id']);
                return true;
            }
            else {
                $xml = simplexml_load_string((string) $response);

                $json = json_encode($xml);

                $response = json_decode($json, true);

                $recordsProcessed = 0;
                $recordsSuccessful = 0;
                $skuError = [];

                if (isset($response['Message']['ProcessingReport']['ProcessingSummary']['MessagesProcessed'])) {
                    $recordsProcessed = $response['Message']['ProcessingReport']['ProcessingSummary']['MessagesProcessed'];
                }

                if (isset($response['Message']['ProcessingReport']['ProcessingSummary']['MessagesSuccessful'])) {
                    $recordsSuccessful = $response['Message']['ProcessingReport']['ProcessingSummary']['MessagesSuccessful'];
                }

                if ($recordsSuccessful < $recordsProcessed) {
                    if (isset($response['Message']['ProcessingReport']['Result'])) {
                        $resultLines = $response['Message']['ProcessingReport']['Result'];

                        if (isset($resultLines['MessageID'])) {
                            $tempResultLines[] = $resultLines;
                            $resultLines = $tempResultLines;
                        }

                        foreach ($resultLines as $result) {
                            if (isset($result['ResultCode']) && $result['ResultCode'] == 'Error' && isset($result['AdditionalInfo']['SKU'], $result['ResultDescription'],$result['ResultMessageCode'])) {

                                $skuError[$result['AdditionalInfo']['SKU']][] = $result['ResultMessageCode'] .' : '.$result['ResultDescription'];
                            }
                        }
                    }

                    if (!empty($skuError)) {
                        $variantError = [];
                        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
                        foreach ($skuError as $sku => $error) {

                            $params = [
                                "sku" => $sku,
                                'user_id' => $feed['user_id'],
                                'target_shop_id' => $feed['shop_id'],
                                'source_shop_id' => $feed['source_shop_id']
                            ];
                            $productData = $helper->getProductBySku($params);
                            if (isset($productData['source_product_id']) && $productData['source_product_id']) {
                                $variantError[$productData['source_product_id']] = $error;
                            }
                        }
                    }
                }
            }

            if ($recordsSuccessful < $recordsProcessed) {
                $productComponent = $this->di->getObjectManager()->get(Product::class)->init();
                if (!empty($variantError)) {
                    $productErrorList = array_keys($variantError);
                    $dataToset['productErrorList'] = $variantError;
                    $productComponent->marketplaceUpdate($dataToset,$productErrorList, [], "", "", "",false,[],false, false,$feed);
                    // $productComponent->saveErrorInProduct($variantError, false, false, false, false, [], $feed);
                }

                if (!empty($localDeliverySourceId)) {
                    $localDeliveryComponent = $this->di->getObjectManager()->get(LocalDeliveryHelper::class);
                    $localDeliveryComponent->saveErrorInProduct($localDeliverySourceId, false, false, false, false, [], $feed, []);
                }
            } else {
                $this->removeErrorFromProduct([], $feed);
                if (!empty($locaDeliveryIds)) {
                    $localIds = array_keys($locaDeliveryIds);
                    $errorType = self::FEED_TYPE[$feedType];
                    $localDeliveryComponent = $this->di->getObjectManager()->get(LocalDeliveryHelper::class);
                    $localDeliveryComponent->removeErrorQuery($localIds, $errorType,  $feed['specifics']['shop_id'], $feed['source_shop_id'], $feed['user_id'], [], $locaDeliveryIds);
                }
            }

            if ($feed['type'] == '_POST_FLAT_FILE_LISTINGS_DATA_' || $feed['type'] == 'POST_FLAT_FILE_LISTINGS_DATA' || ($contentType == 'JSON' && $feed['type'] == 'JSON_LISTINGS_FEED_UPLOAD')) {
                $failCount = !empty($productErrorList) ? count($productErrorList) : 0;
                $bulletinData = [
                    'user_id' => $feed['user_id'],
                    'home_shop_id' => (string)$feed['specifics']['shop_id'] ?? '',
                    'bulletinItemType' => 'LISTING_PROCESS_FINISHED',
                    'bulletinItemParameters' => ['successListings' => (int)($recordsSuccessful ?? 0), 'failedListings' => $failCount],
                    'source_shop_id' => $feed['source_shop_id'] ?? ''
                ];
                $this->di->getObjectManager()->get(Bulletin::class)->createBulletin($bulletinData);
            }

            if (in_array($feed['type'], ['_POST_PRODUCT_DATA_', 'POST_PRODUCT_DATA'])) {
                $sourceProductIds = $feed['specifics']['ids'];
                $targetShopId = (string) $feed['specifics']['shop_id'];
                $productComponent = $this->di->getObjectManager()->get(Product::class)->init();
                $productComponent->removeStatusFromProduct($sourceProductIds, $targetShopId, false, false, $feed);
            }
        }
    }

    private function getMappedErrorMessage($message) {
        if (!is_string($message)) {
            return $message;
        }
        $errorMappings = [
            'The data you submitted is incomplete or invalid' =>
                'Unable to sync tracking details because order is either already fulfilled on Amazon by creating custom package or the LatestShipByDate has passed. Please contact support for assistance.',
            'We are unable to process the XML feed because one or more items are invalid' =>
                'Unable to sync tracking details because some items in the order are invalid. Please review the order details. If the issue persists, contact support for assistance.'
        ];

        foreach ($errorMappings as $pattern => $mappedMessage) {
            if (str_contains($message, $pattern)) {
                return $mappedMessage;
            }
        }

        return $message;
    }

    public function setFetalError($feed)
    {
        $productComponent = $this->di->getObjectManager()->get(Product::class)->init();
        $sourceProductIds = $feed['specifics']['ids'];
        $variantError = [];

        foreach ($sourceProductIds as $value) {
            $variantError[$value] = ['AmazonError904 : Feed is terminated from amazon, Kindly re-upload the product'];
        }

        $productComponent->saveErrorInProduct($variantError, false, $feed['target_shop_id'], false, false, [], $feed);
        return true;
    }

    public function setLanguageError($feed)
    {
        $productComponent = $this->di->getObjectManager()->get(Product::class)->init();
        $sourceProductIds = $feed['specifics']['ids'];
        $variantError = [];

        foreach ($sourceProductIds as $value) {
            $variantError[$value] = ['AmazonError901 : The feed language is not English. Please change your language preference to English from your Amazon Seller Central account.'];
        }

        $productComponent->saveErrorInProduct($variantError, false, false, false, false, [], $feed);
        return true;
    }

    public function saveActivityInProduct($variantList, $feed, $status): void
    {
        $type = self::FEED_TYPE[$feed['type']];
        $editedData = [];
        $timeArray = $feed['timestamp'];
        $updatedActivity = [];
        $last_activities = [];
        $activityData = [];
        $targetShopId = $feed['shop_id'];
        $userId = $feed['user_id'];
        $product = [
            'user_id' => (string) $userId,
            'target_shop_id' => (string) $targetShopId,
            'source_shop_id' =>  $feed['source_shop_id']
        ];
        $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');

        if (!empty($variantList) && !empty($timeArray)) {
            foreach ($variantList as $sourceProductId) {
                $product["source_product_id"] = (string) $sourceProductId;
                $productData = $helper->getSingleProduct($product);
                if (!isset($sourceMarkeplace)) {
                    $sourceMarkeplace = $productData['source_marketplace'] ?? "";
                }

                if (isset($productData['last_activities']) && !empty($productData['last_activities'])) {
                    $last_activities = $productData['last_activities'];
                    if (isset($timeArray[$sourceProductId])) {
                        $activityData = $this->checkLastActivity($last_activities, $type);
                        $updatedActivity = $this->getActivityData($activityData, $timeArray[$sourceProductId], $status);
                    } else {
                        $updatedActivity = $last_activities;
                    }
                }

                $editedData[] = [
                    'target_marketplace' => 'amazon',
                    'shop_id' => (string) $targetShopId,
                    'container_id' => (string) $productData['container_id'],
                    'source_product_id' => (string)$sourceProductId,
                    'user_id' => $userId,
                    'source_shop_id' => $feed['source_shop_id'],
                    'last_activities' => $updatedActivity
                ];
            }
        }

        $params['source'] = [
            'marketplace' => $sourceMarkeplace ?? "",
            'shopId' => (string) $feed['source_shop_id']
        ];
        $params['target'] = [
            'marketplace' => 'amazon',
            'shopId' => (string) $targetShopId
        ];
        if (!empty($editedData)) {
            $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
            $result = $editHelper->saveProduct($editedData, false, $params);
        }
    }

    public function getActivityData($last_activities, $timestamp, $status)
    {
        $result = [];
        $current_activity = [];
        if (isset($last_activities['data']) && !empty($last_activities['data'])) {
            $current_activity = $last_activities['data'];
            foreach ($current_activity as $key => $activity) {
                if ($activity['updated_at'] == $timestamp) {
                    $current_activity[$key]['status'] = $status;
                } elseif ((!isset($activity['status'])) || strtotime((string) $activity['updated_at']) < strtotime((string) $timestamp) && isset($activity['status']) && $activity['status'] == 'in_progress') {
                    $current_activity[$key]['status'] = 'Overridden';
                }
            }
        }

        if (isset($last_activities['other_activities']) && !empty($last_activities['other_activities'])) {
            $result = array_merge($current_activity, $last_activities['other_activities']);
        } else {
            $result = $current_activity;
        }

        return $result;
    }

    public function checkLastActivity($last_activities, $type)
    {
        $data = false;
        foreach ($last_activities as $key => $activity) {
            if ($activity['type'] == $type) {
                $data[] = $activity;
                unset($last_activities[$key]);
            }
        }

        return [
            'data' => $data,
            'other_activities' => $last_activities
        ];
    }

    public function getAllIds($data)
    {
        $date = date('d-m-Y');
        $logFile = "amazon/serverlessResponse/{$date}.log";
        $config = include BP . '/app/etc/aws.php';
        $s3Client = new S3Client($config);
        $source_identifiers = [];
        foreach ($data['ids'] as $value) {
            $config = $this->di->getConfig();
            // Get the user IDs for S3 test from the configuration
            $bucketForS3Upload = $config->get('bucket_for_S3_upload');
            try {
                $result = $s3Client->getObject(
                    ['Bucket' => $bucketForS3Upload, 'Key' => $value ?? ""]
                );
                if (isset($result['Body']) && !empty($result['Body'])) {
                    $body = $result['Body'];
                    $allIds = json_decode((string) $body, true);
                    $source_identifiers = array_merge($source_identifiers, $allIds['source_identifiers']);
                }
            } catch (S3_exception $e) {
                return ['success' => false, "message" => $e->getMessage()];
            }
        }

        return ["success" => true, "data" => $source_identifiers];
    }

    public function saveErrorInProduct($variantError, $feed, $homeShopId = false, $errorType = false): void
    {
        if (!$homeShopId) {
            $homeShopId = $feed['specifics']['shop_id'];
        }

        if (!$errorType) {
            $feedType = $feed['type'];
            $errorType = array_search($feedType, self::FEED_TYPE);
        }

        $errorVariantList = [];

        $productCollection = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        //  $productCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
        $productComponent = $this->di->getObjectManager()->get(Product::class);

        //removing error before saving new errors
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $product_container = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        $spIds = array_keys($variantError);
        $imploded = implode(',', $spIds);
        $exploded = explode(',', $imploded);
        $ress = $product_container->updateMany(
            ['source_product_id' => ['$in' => $exploded], 'marketplace.amazon.shop_id' => (string) $homeShopId, 'user_id' => $this->_user_id],
            ['$unset' => ['marketplace.amazon.$.error' => '']]
        );


        foreach ($variantError as $sourceProductId => $error) {

            //  $productIds =  $productComponent->init(['user_id' => $this->_user_id])->getVariantIdFromSku($sku);

            //           if (isset($productIds['variant_id']) && $productIds['variant_id']) {
            $errorVariantList[] = $sourceProductId;
            $productData = $productCollection->findOne(['source_product_id' => (string) $sourceProductId, 'user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            $saveArray = [];
            $amazonData = [];
            if (isset($productData['marketplace']['amazon']) && is_array($productData['marketplace']['amazon'])) {
                foreach ($productData['marketplace']['amazon'] as $amazonShops) {
                    if (isset($amazonShops['shop_id']) && $amazonShops['shop_id'] == $homeShopId) {
                        $saveArray = $amazonShops;
                        $saveArray[$errorType] = $error;
                        $bulkOpArray[] = [
                            'updateOne' => [
                                ['source_product_id' => (string) $sourceProductId, 'marketplace.amazon.shop_id' => (string) $amazonShops['shop_id'], 'user_id' => $this->_user_id],
                                [
                                    '$set' => ['marketplace.amazon.$.error.' . $errorType => $error]
                                ],
                            ],
                        ];
                        //                           $productCollection->updateOne(['source_product_id' => $productIds['variant_id'], 'marketplace.amazon.shop_id' => $amazonShops['shop_id'], 'user_id' => $this->_user_id],
                        //                               ['$set' => ['marketplace.amazon.$.error.'.$errorType => $error]]);
                        //                           $this->di->getLog()->logContent('first condition, sku :'.$sku.' variant id :'.$productIds['variant_id'].' Data :'.json_encode($saveArray), 'info', 'error_sync.log');
                        break;
                    }
                }

                if (empty($saveArray)) {
                    $saveArray['shop_id'] = (string) $homeShopId;
                    $saveArray['error'][$errorType] = $error;
                    $bulkOpArray[] = [
                        'updateOne' => [
                            ['source_product_id' => (string) $sourceProductId, 'user_id' => $this->_user_id],
                            [
                                '$push' => ['marketplace.amazon.' => $saveArray]
                            ],
                        ],
                    ];
                    //                           $productCollection->updateOne(['source_product_id' => $productIds['variant_id'], 'user_id' => $this->_user_id], ['$push' => ['marketplace.amazon.' => $saveArray]]);
                    //                           $this->di->getLog()->logContent('second condition, sku :'.$sku.' variant id :'.$productIds['variant_id'].' Data :'.json_encode($saveArray), 'info', 'error_sync.log');

                }
            }

            if (empty($saveArray)) {
                $saveArray['shop_id'] = (string) $homeShopId;
                $saveArray['error'][$errorType] = $error;
                $amazonData['marketplace']['amazon'][] = $saveArray;
                $bulkOpArray[] = [
                    'updateOne' => [
                        ['source_product_id' => (string) $sourceProductId],
                        [
                            '$set' => $amazonData
                        ],
                    ]
                ];
                //                   $productCollection->updateOne(['source_product_id' => $productIds['variant_id'], 'user_id' => $this->_user_id], ['$set' => $amazonData]);
                //                   $this->di->getLog()->logContent('third condition, sku :'.$sku.' variant id :'.$productIds['variant_id'].' Data :'.json_encode($amazonData), 'info', 'error_sync.log');
            }

            //           }

        }

        if (!empty($bulkOpArray)) {
            $productCollection->BulkWrite($bulkOpArray, ['w' => 1]);
        }

        $this->removeErrorFromProduct($errorVariantList, $feed);
    }

    public function removeImageFromS3($ids, $remoteShopId): void
    {
        $config = include BP . '/app/etc/aws.php';
        $imageBucket = false;
        $s3Client = new S3Client($config);
        $imageBucket = $this->di->getConfig()->get('imageBucket');
        $bucketName = $imageBucket ?? 'amazon-product-image-upload-live';
        foreach ($ids as $id) {
            $folderPath = $remoteShopId . "/" . $id . '/';
            $folderPath = rtrim($folderPath, '/') . '/';
            $s3Client->deleteMatchingObjects($bucketName, $folderPath);
        }
    }

    public function removeErrorFromProduct($errorVariantList, $feed): void
    {
        if (isset($feed['specifics']['shop_id'], $feed['specifics']['ids'], $feed['type'])) {
            $homeShopId = (string) $feed['specifics']['shop_id'];
            $feedProductList = $feed['specifics']['ids'];

            $feedType = $feed['type'];
            //            $errorType = array_search($feedType, self::FEED_TYPE);
            $errorType = self::FEED_TYPE[$feedType];
            //          $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            //          $productCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

            $sourceShopId = $feed['source_shop_id'];

            if (!empty($feedProductList)) {
                $variantList = array_diff((array) $feedProductList, $errorVariantList);
                $variantList = array_values($variantList);
                if (!empty($variantList)) {
                    if (isset($feed['specifics'])) {
                        $this->saveActivityInProduct($variantList, $feed, 'Success');
                    }

                    $productComponent = $this->di->getObjectManager()->get(Product::class);
                    $dataToset['removeErrorProductList'] = $variantList;
                    $productComponent->marketplaceUpdate($dataToset, [], [], "", "", "",false,"",[],false, $feed);
                    // $productComponent->removeErrorQuery($variantList, $errorType, $homeShopId, $sourceShopId, $feed['user_id'], []);
                    // $productCollection->updateMany(['source_product_id' => ['$in' => $variantList], 'marketplace.amazon.shop_id' => (int)$homeShopId, 'user_id' => $this->_user_id],
                    // ['$unset' => ['marketplace.amazon.$.error.' . $errorType => '']]);
                } elseif (!empty($errorVariantList)) {
                    if (isset($feed['timestamp'])) {
                        $this->saveActivityInProduct($errorVariantList, $feed, 'Error');
                    }
                }
            }
        } else {
        }
    }

    public function removeErrorQuery($variantList, $homeShopId, $errorType): void
    {
        //changing int of array to string of array
        $imp = implode(',', $variantList);
        $exp = explode(',', $imp);
        $variantList = $exp;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        $productCollection->updateMany(
            ['source_product_id' => ['$in' => $variantList], 'marketplace.amazon.shop_id' => (int) $homeShopId, 'user_id' => $this->_user_id],
            ['$unset' => ['marketplace.amazon.$.error.' . $errorType => '']]
        );
        $productCollection->updateMany(
            ['source_product_id' => ['$in' => $variantList], 'marketplace.amazon.shop_id' => (string) $homeShopId, 'user_id' => $this->_user_id],
            ['$unset' => ['marketplace.amazon.$.error.' . $errorType => '']]
        );
    }

    public function removeTag($feed, $tags = [], $homeShopId = false, $sourceProductIds = []): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);

        if (!$homeShopId) {
            $homeShopId = $feed['specifics']['shop_id'];
        }

        if (empty($sourceProductIds)) {
            $sourceProductIds = $feed['specifics']['ids'];
        }


        if (empty($tags)) {
            $feedType = $feed['type'];
            $action = array_search($feedType, self::FEED_TYPE);
            if ($action == 'product') {
                $tags = [
                    Helper::PROCESS_TAG_UPLOAD_FEED,
                    Helper::PROCESS_TAG_UPDATE_FEED
                ];
            } elseif ($action == 'inventory') {
                $tags = [
                    Helper::PROCESS_TAG_INVENTORY_FEED
                ];
            } elseif ($action == 'price') {
                $tags = [
                    Helper::PROCESS_TAG_PRICE_FEED
                ];
            } elseif ($action == 'image') {
                $tags = [
                    Helper::PROCESS_TAG_IMAGE_FEED
                ];
            } elseif ($action == 'delete') {
                $tags = [
                    Helper::PROCESS_TAG_DELETE_FEED
                ];
            }
        }


        //change array of int to array of string
        $encoded = implode(',', $sourceProductIds);
        $productIds = explode(',', $encoded);
        // print_r($productIds);
        // die("jkl");
        if (!empty($productIds)) {

            $res = $productCollection->updateMany(
                ['source_product_id' => ['$in' => $productIds], 'marketplace.amazon.shop_id' => (int) $homeShopId, 'user_id' => $this->_user_id],
                ['$pull' => ['marketplace.amazon.$.process_tags' => ['$in' => $tags]]]
            );


            $res = $productCollection->updateMany(
                ['source_product_id' => ['$in' => $productIds], 'marketplace.amazon.shop_id' => (string) $homeShopId, 'user_id' => $this->_user_id],
                ['$pull' => ['marketplace.amazon.$.process_tags' => ['$in' => $tags]]]
            );
        }
    }


    public function removeStatusFromProduct($variantList, $homeShopId): void
    {
        $remoteShopId = 0;
        $shop = $this->_user_details->getShop($homeShopId, $this->_user_id);

        if ($shop && isset($shop['remote_shop_id'])) {
            $remoteShopId = $shop['remote_shop_id'];
        }

        // converting int to string in array
        $variantList = implode(',', $variantList);
        $variantList = explode(',', $variantList);

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        $amazonProductContainer = $this->_baseMongo->getCollectionForTable(Helper::AMAZON_PRODUCT_CONTAINER);


        $searchSku = [];
        $notDeletedVariant = [];

        $productArray = $productCollection->find(['source_product_id' => ['$in' => $variantList], 'user_id' => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        $productList = $productArray->toArray();
        if (!empty($productList)) {
            foreach ($productList as $productData) {

                $amazonProduct = $amazonProductContainer->findOne(
                    ['source_product_id' => $productData['source_product_id'], 'user_id' => $this->_user_id],
                    ["typeMap" => ['root' => 'array', 'document' => 'array']]
                );

                if ($amazonProduct && isset($amazonProduct['parent_sku']) && !empty($amazonProduct['parent_sku'])) {
                    $sku = $amazonProduct['parent_sku'];
                } elseif ($amazonProduct && isset($amazonProduct['sku']) && !empty($amazonProduct['sku'])) {
                    $sku = $amazonProduct['sku'];
                } elseif (isset($productData['sku']) && !empty($productData['sku'])) {
                    $sku = $productData['sku'];
                } else {
                    $sku = $productData['source_product_id'];
                }

                $searchSku[$productData['source_product_id']] = $sku;
            }


            if (!empty($searchSku)) {
                $skus = array_values($searchSku);
                $chunk = array_chunk($skus, 5);
                $commonHelper = $this->di->getObjectManager()->get(Helper::class);

                foreach ($chunk as $value) {
                    $specifics['id'] = $value;
                    $specifics['shop_id'] = $remoteShopId;
                    $specifics['home_shop_id'] = $homeShopId;
                    $specifics['id_type'] = 'SellerSKU';
                    $response = $commonHelper->sendRequestToAmazon('product', $specifics, 'GET');
                    if (isset($response['success']) && $response['success']) {
                        if (isset($response['response']) && is_array($response['response'])) {
                            foreach ($response['response'] as $barcode => $asin) {
                                $notDeletedVariant[] = array_search($barcode, $searchSku);
                            }
                        }
                    }
                }
            }
        }

        $deletedVariant = array_diff($variantList, $notDeletedVariant);

        foreach ($productList as $productData) {
            if (in_array($productData['source_product_id'], $deletedVariant) && isset($productData['marketplace']['amazon']) && is_array($productData['marketplace']['amazon'])) {
                foreach ($productData['marketplace']['amazon'] as $amazonShops) {
                    if (isset($amazonShops['shop_id']) && $amazonShops['shop_id'] == $homeShopId) {
                        if (isset($amazonShops['status']) && $amazonShops['status'] != Helper::PRODUCT_STATUS_AVAILABLE_FOR_OFFER) {
                            $filterArray = ['source_product_id' => (string) $productData['source_product_id'], 'user_id' => $this->_user_id];
                            $filterArray['marketplace.amazon.shop_id'] = (string) $amazonShops['shop_id'];
                            $productCollection->updateOne(
                                $filterArray,
                                ['$unset' => ['marketplace.amazon.$.status' => ""]]
                            );
                        }
                    }
                }
            }
        }
    }

    public function getBucketData($data, $check_size = false)
    {
        try {
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $filePath = BP . DS . 'var' . DS . 'file' . 'bucket' . DS . $userId . '_content.txt';
            if (file_exists($filePath)) {
                if (!unlink($filePath)) {
                    return ['success' => false, 'message' => 'Error deleting file. Kindly check permission.'];
                }
            }

            $dirname = dirname($filePath);
            if (!is_dir($dirname)) {
                $oldmask = umask(0);
                mkdir($dirname, 0777, true);
                umask($oldmask);
            }

            $fp = fopen($filePath, 'w+');
            $config = include BP . '/app/etc/aws.php';
            $s3Client = new S3Client($config);
            $result = $s3Client->getObject(
                ['Bucket' => $data['bucket'] ?? "amazon-order-shipment-log-live", 'Key' => $data['key'] ?? "", 'SaveAs' => $fp]
            );
            if (is_resource($fp)) {
                fclose($fp);
            }
            $content = json_decode(file_get_contents($filePath));
            if ($check_size) {
                $fileSize = $result['ContentLength'];
                if ($fileSize > 800 * 1024) { // Check if the file size is greater than 500KB (in bytes)
                    // File is larger than 500KB, you can allow the user to download it.
                    $expires = '+2 minutes'; // Set the expiration time as per your requirements
                    $cmd = $s3Client->getCommand('GetObject', [
                        'Bucket' => $data['bucket'],
                        'Key' => $data['key']
                    ]);
                    $request = $s3Client->createPresignedRequest($cmd, $expires);
                    $presignedUrl = (string)$request->getUri();
                    return ['success' => true, 'url' => $presignedUrl];
                }
            }

            return ['success' => true, 'data' => $content];
        } catch (S3_exception $e) {
            $this->di->getLog()->logContent('S3 Exception from getBucketData() : ' . $e->getMessage() . 'BucketName : ' . $data['bucket'] ?? 'amazon-order-shipment-log-live' . 'ObjectKey :' . $data['key'] ?? '', 'info', 'exception.log');
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from getBucketData() : ' . $e->getMessage(), 'info', 'exception.log');
        }
    }
}