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

namespace App\Shopifyhome\Components\Product;

use App\Shopifyhome\Components\Core\Common;

#[\AllowDynamicProperties]
class UploadToShopify extends Common
{
    public $_collection = '';

    public $_error = false;

    public $_userId = 1;

    public function prepare($userId = null): void{
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("product_container");

        $this->_collection = $mongo->getPhpCollection();
        $this->_userId = $userId;
    }


    public function prepareQuery($query, $failed_product = false)
    {
        if ($query != '') {
            $filterQuery = [];
            $orConditions = explode('||', (string) $query);
            $orConditionQueries = [];
            foreach ($orConditions as $value) {
                $andConditionQuery = trim($value);
                $andConditionQuery = trim($andConditionQuery, '()');
                $andConditions = explode('&&', $andConditionQuery);
                $andConditionSet = [];

                foreach ($andConditions as $andValue) {
                    $andConditionSet[] = $this->getAndConditions($andValue);
                }

//                print_r($andConditionSet);die;

                $orConditionQueries[] = [
                    '$and' => $andConditionSet
                ];
            }

            if ($failed_product) {
                $orConditionQueries = [
                    '$and' => [
                        [
                            'details.source_product_id' => [
                                '$in' => $failed_product
                            ]
                        ],
                    ],
                    '$or' => $orConditionQueries
                ];
            } else {

                $orConditionQueries = [
                    '$or' => $orConditionQueries
                ];
            }

            //$orConditionQueries['$and'] = $orConditionQueries;
            return $orConditionQueries;
        }

        return false;
    }

    public function getProductsInChunk($userId = false, $source = false, $cursor = null)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $shopId = false;
        $user_details = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $shopDetails =  $user_details->getDataByUserID($userId, $source);
        if ($shopDetails) {
            $shopId = $shopDetails->id;
        }

        $filterQuery = ['source_marketplace'=>$source];
        $customizedFilterQuery = [
            '$and' => [
                ['user_id' => (string)$userId],
                ['visibility'=>"Catalog and Search"],
                $filterQuery
            ]
        ];
        if ($filterQuery) {
            $finalQuery = [
                [
                    '$match' => $customizedFilterQuery
                ],
                [
                    '$limit' => ($cursor + 1) * 10
                ],
                [
                    '$skip' => $cursor * 10
                ]
            ];
            $countFinalQuery = [
                [
                    '$match' => $customizedFilterQuery
                ],
                [
                    '$count' => 'count'
                ]
            ];

            $this->di->getLog()->logContent(' GetProductsByQuery finalQuery  == '.print_r($finalQuery,true),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'product_upload.log');

            $this->di->getLog()->logContent(' GetProductsByQuery countFinalQuery  == '.print_r($finalQuery,true),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'product_upload.log');

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $mongo->setSource("product_container");
            $collection = $mongo->getCollection();
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $response = $collection->aggregate($finalQuery, $options);
            $countResponse = $collection->aggregate($countFinalQuery, $options);
            $countResponse = $countResponse->toArray();
            $count = $countResponse[0]['count'] ?: 0;
            $selectedProducts = $response->toArray();
            return ['success' => true, 'data' => $selectedProducts, 'total_count' => $count];
        }

        return ['success' => false, 'code' => 'no_products_found', 'message' => 'No products found'];
    }


    public function getProductsByQuery($productQueryDetails, $userId = false, $source = false, $cursor = null, $failedProduct = null, $profileId = null)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $filterQuery = $productQueryDetails['query'] ?? '';
        $filterQuery = $this->prepareQuery($filterQuery, $failedProduct);

        $customizedFilterQuery = [
            '$and' => [
                ['user_id' => (string)$userId],
                ["source_marketplace" => $source],
                $filterQuery
            ]
        ];
        if ($filterQuery) {
            $finalQuery = [
                [
                    '$match' => $customizedFilterQuery
                ],
                ['$group' => ['_id' => '$container_id','product' => ['$push' => '$$ROOT']]],
                [
                    '$limit' => ($cursor + 1) * 10
                ],
                [
                    '$skip' => $cursor * 10
                ]
            ];
            $countFinalQuery = [
                [
                    '$match' => $customizedFilterQuery
                ],
                ['$group' => ['_id' => '$container_id','product' => ['$push' => '$$ROOT']]],
                [
                    '$count' => 'count'
                ]
            ];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $mongo->setSource("product_container");
            $collection = $mongo->getCollection();
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $response = $collection->aggregate($finalQuery, $options);
            $countResponse = $collection->aggregate($countFinalQuery, $options);
            $countResponse = $countResponse->toArray();
            $count = $countResponse[0]['count'] ?: 0;
            $selectedProducts = $response->toArray();
            return ['success' => true, 'data' => $selectedProducts, 'total_count' => $count];
        }

        return ['success' => false, 'code' => 'no_products_found', 'message' => 'No products found'];
    }

    public function getAndConditions($andCondition)
    {
        $preparedCondition = [];
        $conditions = ['==', '!=', '!%LIKE%', '%LIKE%', '>=', '<=', '>', '<'];
        $andCondition = trim((string) $andCondition);
        foreach ($conditions as $value) {
            if (str_contains($andCondition, $value)) {
                $keyValue = explode($value, $andCondition);
                $prefix = 'variants';
                if ((trim($keyValue[0]) == 'title') ||
                    (trim($keyValue[0]) == 'site') ||
                    (trim($keyValue[0]) == 'vendor') ||
                    trim($keyValue[0]) == 'primary_category_name' ||
                    (trim($keyValue[0]) == 'primary_category_id') ||
                    (trim($keyValue[0]) == 'source_product_id') ||
                    trim($keyValue[0]) == 'state' || trim($keyValue[0]) == 'site' ||
                    trim($keyValue[0]) == 'listing_type') {
                    $prefix = 'details';
                }

                $valueOfProduct = trim(addslashes($keyValue[1]));

                if (trim($keyValue[0]) == 'price' || trim($keyValue[0]) == 'quantity') {
                    $valueOfProduct = (int)$valueOfProduct;
                }

                switch ($value) {
                    case '==':
                        $preparedCondition[trim($keyValue[0])] = $valueOfProduct;
                        break;
                    case '!=':
                        $preparedCondition[trim($keyValue[0])] = ['$ne' => $valueOfProduct, '$exists' => true];
                        break;
                    case '%LIKE%':
                        $preparedCondition[trim($keyValue[0])] = ['$regex' => ".*" . $valueOfProduct . ".*", '$options' => 'i'];
                        break;
                    case '!%LIKE%':
                        $preparedCondition[trim($keyValue[0])] = ['$regex' => "^((?!" . $valueOfProduct . ").)*$"];
                        break;
                    case '>':
                        $preparedCondition[trim($keyValue[0])] = ['$gt' => (float)$valueOfProduct];
                        break;
                    case '<':
                        $preparedCondition[trim($keyValue[0])] = ['$lt' => (float)$valueOfProduct];
                        break;
                    case '>=':
                        $preparedCondition[trim($keyValue[0])] = ['$gte' => (float)$valueOfProduct];
                        break;
                    case '<=':
                        $preparedCondition[trim($keyValue[0])] = ['$lte' => (float)$valueOfProduct];
                        break;
                }

                break;
            }
        }

        return $preparedCondition;
    }

    public function initProductUploadQueue($data, $failed_product)
    {
        $response = $this->myfetchProductInChunks($data, $failed_product);
        $data['data']['cursor'] = $response['cursor'];
        $this->di->getLog()->logContent("Next Cursor Data = " .print_r($data,true), 'info', 'shopify'.DS. $data['data']['user_id'].DS.'product_upload'.DS.date("Y-m-d").DS.'SQS.log');
        $maxLimit = $data['data']['max_limit'];
        $syncStatus = $data['data']['sync_status'];
        $productUploadedCount = $data['data']['uploaded_product_count'];
        $total_count=$data['data']['total_count'];
        $maxCursorLimit = $maxLimit / 10;
        $indivisual_weight = ceil($total_count / 10);
        $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], (100 / $indivisual_weight), ($data['data']['cursor'] * 10) . ' product Acknowledge by Shopify. Uploading!!!');

        if (isset($response['cursor'])) {
            if ($response['cursor'] >= $maxCursorLimit) {
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                $progress = 100;
                if ($progress && $progress == 100) {
//                    $url = false;
//                    if ($data['data']['file_path'] &&
//                        $data['data']['file_name'] &&
//                        file_exists($data['data']['file_path'])) {
//                        $failedProducts = require $data['data']['file_path'];
//                        unlink($data['data']['file_path']);
//                        $reportFilePath = BP . DS . 'var' . DS . 'failed-products' . DS . $data['data']['file_name'] . '.csv';
//                        $url = $this->prepareReportFile($reportFilePath, $failedProducts);
//                    }
                    $successCount=true;
                    if ($successCount) {
                        if ($productUploadedCount &&
                            $syncStatus == 'enable') {
                            $total_count=(string)$total_count;
                            $data['data']['success_message'] = $total_count . ' ' . $this->getProductMessage($successCount) . ' of ' . $data['data']['source'] . ' uploaded successfully on Shopify. ' . $productUploadedCount . ' ' . $this->getProductMessage($productUploadedCount) . ' successfully updated on Shopify.';
                        } else {
                            $data['data']['success_message'] = $total_count . ' ' . $this->getProductMessage($successCount) . ' of ' . $data['data']['source'] . ' uploaded successfully on Shopify.';
                        }
                    } else {
                        $limitExhaustMessage = 'No new products of ' . $data['data']['source'] . ' to upload on Shopify';
                        if ($productUploadedCount &&
                            $syncStatus == 'enable') {
                            $data['data']['success_message'] = $productUploadedCount . ' ' . $this->getProductMessage($productUploadedCount) . ' successfully updated on Shopify. ' . $limitExhaustMessage;
                        } else {
                            $data['data']['success_message'] = $limitExhaustMessage;
                        }
                    }

                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], $data['data']['success_message'], 'success', '');
                }

                $this->di->getLog()->logContent(' Before Return True | Last Cursor = '.json_encode($response['cursor']), 'info', 'shopify'.DS. $data['data']['user_id'].DS.'product_upload'.DS.date("Y-m-d").DS.'SQS.log');
                return true;
            }

            $handlerData=$this->di->getObjectManager()->get(Helper::class)->shapeSqsDataForUpload($data);
            $this->di->getLog()->logContent(' push_TOQueue  == '.print_r($handlerData,true),'info','shopify'.DS.$data['data']['user_id'].DS.'product_upload'.DS.date("Y-m-d").DS.'push_TOQueue.log');
            $this->pushToQueue($handlerData, 5,'product_upload');
        } else {
            if (isset($response['error'])) {
                return $returnArr[] = ['success' => false, 'message' => $response['message']];
            }
            return true;
        }

        return true;
    }

    public function getProductMessage($count)
    {
        if ($count > 1) {
            return 'products';
        }
        return 'product';
    }

    public function getProductsCountByQuery($userId = false, $profileId = false, $source = false, $productQuery = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $this->_user_id = $userId;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('profiles');
        $profile = $collection->findOne([
            "profile_id" => (int)$profileId
        ]);
        if ($productQuery)
            $filterQuery = $productQuery;

        if ($profile) {
            $filterQuery = $profile->query;
            $marketplace = $profile->source;
        }

        $filterQuery = $this->prepareQuery($filterQuery,false,$userId);
        $uploadedProductIds = $this->getUploadedProductIds($userId);

        $customizedFilterQuery = [
            '$and' => [
                ["source_marketplace" => $source],
                ['user_id' => (string)$userId],
                $filterQuery,
                [
                    'details.source_product_id' => [
                        '$nin' => $uploadedProductIds
                    ]
                ]
            ]
        ];


        $customizedFilterQueryForUploadedProducts = [
            '$and' => [
                ["source_marketplace" => $source],
                ['user_id' => (string)$userId],
                $filterQuery,
                [
                    'details.source_product_id' => [
                        '$in' => $uploadedProductIds
                    ]
                ]
            ]
        ];
        if ($filterQuery) {
            $finalQuery = [
                [
                    '$match' => $customizedFilterQuery
                ],
                [
                    '$count' => 'count'
                ]
            ];
            $uploadedCountFinalQuery = [
                [
                    '$match' => $customizedFilterQueryForUploadedProducts
                ],
                [
                    '$count' => 'count'
                ]
            ];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $mongo->setSource("product_container");
            $collection = $mongo->getCollection();
            $response = $collection->aggregate($finalQuery);
            $response = $response->toArray();
            $count = isset($response[0]) ? $response[0]['count'] : 0;
            $uploadedCountResponse = $collection->aggregate($uploadedCountFinalQuery);
            $uploadedCountResponse = $uploadedCountResponse->toArray();
            $uploadedCount = isset($uploadedCountResponse[0]) ? $uploadedCountResponse[0]['count'] : 0;


            return [
                'product_count' => $count,
                'product_uploaded_count' => $uploadedCount
            ];
        }

        return false;
    }

    public function getUploadedProductIds($userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('product_upload');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $filters=['user_id'=>$userId];
        $response = $collection->aggregate([
            ['$match' => $filters],
            [
                '$project' => [
                    'source_product_id' => 1
                ]
            ]
        ], $options);
        $response = $response->toArray();

        $productIds = [];
        foreach ($response as $value) {
            $productIds[] = $value['source_product_id'];
        }

        return $productIds;
    }

    public function myfetchProductInChunks($data, $failedProduct){
        $userId = $data['data']['user_id'];
        $cursor = $data['data']['cursor'];
        $profileId = $profileId = $data['data']['profileId'];
        $sourceShopId = $data['data']['source_shop_id'];
        $targetShopId = $data['data']['target_shop_id'];
        $source = $data['data']['source'];
        $syncStatus = $data['data']['sync_status'];
        if (isset($data['data']['is_sync'])){
            $is_sync = $data['data']['is_sync'];
        }

        $mongo1 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo1->setSource("product_container");

        $collection1 = $mongo1->getCollection();
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("product_upload");

        $collection = $mongo->getCollection();

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $phpMongoCollection = $mongo->setSource('user_details')->getPhpCollection();
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $Vendor_details = $phpMongoCollection->find(['user_id'=>$userId],$options)->toArray();

//        $Admin_Data['location_id']=$Vendor_details[0]['shops'][0]['warehouses'][1]['id'];
        $Admin_Data['location_id'] = '60899622984';
        $response = $this->fetchProductsByProfile($profileId, $userId, $sourceShopId, $cursor, $failedProduct);


        if (!$response) {
            if (isset($data['data']['product_query'])) {
                $response = $this->getProductsByQuery(['query' => $data['data']['product_query']], $userId, $source, $cursor, $failedProduct = false, $profileId = false);
            } else {
                $response = $this->getProductsByQuery(['query' => '(price > -1)'], $userId, $source, $cursor, $failedProduct = false, $profileId = false);
            }
        }

        $productData = $response['data'];
        $count=$createProductCount=0;
        $allproducts=[];
        if (is_array($productData) && count($productData)) {
            foreach ($productData as $value) {
                $value=$value['product'];
                $singleproduct=[];
                $product_details = json_decode(json_encode($value[0]) , true);
                $count++;
                $productAsin = $product_details['container_id'];
                $exist=$collection->findOne(['$and'=> [
                    ['user_id'=>$userId],
                    ['container_id' => $productAsin]
                ]]);
                if ( isset($exist['upload_status']) && $exist['upload_status']==true) {
                    $product_details['shopify_product_id'] = $exist['admin_container_id'];
                    $product_details['shopify_product_id'] = 'gid://shopify/Product/'.$product_details['shopify_product_id'];
//                    print_r($product_details['shopify_product_id']);
//                    die("dasds");
                    $mode='productUpdate';
                } else {
                    $mode = 'productCreate';
                    $createProductCount++;
                }

                $singleproduct['mode']=$mode;
                $singleproduct['details']=$product_details;
                $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
                $allvariants=[];
                if($product_details['type'] == "variation" && $product_details['visibility'] == "Catalog and Search"){
                    $allvariants = $collection1->find(['container_id' => (string)$product_details['container_id'],'user_id'=>$userId,'visibility'=>"Not Visible Individually","sell" => true],$options)->toArray();
                    $singleproduct['variants'] = $allvariants;
                    $singleproduct['variantcount'] = count($singleproduct['variants']);
                } else if($product_details['type']=="variation" && $product_details['visibility']=="Not Visible Individually"){
                    $allvariants=$collection1->find(['container_id' => (string)$product_details['container_id'],'user_id'=>$userId,'visibility'=>"Not Visible Individually","sell" => true],$options)->toArray();
                    $singleproduct['variants']=$allvariants;
                    $singleproduct['variantcount']=count($singleproduct['variants']);
                }

                if($product_details['type']=="simple" && $product_details['visibility']=="Catalog and Search" /*&& $value['status'] == "approved"*/){
                    $allvariants[]=$product_details;
                    $singleproduct['variantcount']=count($allvariants);
                }else if($product_details['type']=="simple" && $product_details['visibility']=="Not Visible Individually"){
                    $allvariants[]=$product_details;
                    $singleproduct['variantcount']=count($allvariants);
                }

                array_push($allproducts,$singleproduct);
            }
        }

        $pcount=(string)count($productData);
//        $this->di->getLog()->logContent(' PRODUCT COUNT  == '.\GuzzleHttp\json_encode($pcount),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'product_upload.log');



//        print_r($targetShopId);
//        die("qwerty");
//        $this->di->getLog()->logContent(' allproducts  == '.json_encode($allproducts),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'new_product_upload.log');

        $main_response =$this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify',"true")
            ->call('createproduct',[],[
                'shop_id'=>(string)$targetShopId,'product_data'=>json_encode($allproducts), 'call_type' => 'QL','data' => $data,"location" => $Admin_Data['location_id']],
                'POST');
        $main_response = $main_response['body'];
        /*print_r($main_response);
        die("sssssssss");*/

        $this->di->getLog()->logContent(' extension  == '.print_r($main_response,true),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'extension.log');
//        $this->di->getLog()->logContent(' Query  == '.print_r($main_response['query'],true),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'Query.log');
//        $this->di->getLog()->logContent(' Response Body  == '.print_r($main_response,true),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'Res.log');

        if(isset($main_response)){
            foreach ($main_response as $response){
                $response1 = $response['body'];
                echo PHP_EOL;
                echo "THROTTLE EXTENSION";
                print_r($response['extension']);
                foreach ($response1 as $key=>$bodyresult ){
                    $mode_productid =$this->getproductId($key);
                    $mode=$mode_productid[0];
                    $productAsin=$mode_productid[1];
                    $exist_in_upload = $collection->findOne(['$and'=>[
                        ['user_id'=>$userId],
                        ['source_product_id' => $productAsin]
                    ]]);
                    $this->updatecollectionsDataAfterUpload($bodyresult,$mode,$exist_in_upload,$productAsin,$data);
                }
            }

            $cursor++;
            print_r("Cursor = ");
            print_r($cursor);
            echo PHP_EOL;
            return [ 'success'=> true, 'cursor'=> $cursor ];
        }
    }

    public function getproductId($product_id){
        return explode("_",(string) $product_id);
    }

    public function updatecollectionsDataAfterUpload($result,$mode,$exist_in_upload,$productAsin,$data): void{
        //  print_r($productAsin);die();
        $userId = $data['data']['user_id'];
        $profileId = $data['data']['profileId'];
        $sourceShopId = $data['data']['source_shop_id'];
        $filePath = false;
        $fileName = false;
        if (isset($data['data']['file_path']) &&
            isset($data['data']['file_name'])) {
            $filePath = $data['data']['file_path'];
            $fileName = $data['data']['file_name'];
        }

        $mongo1 = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo1->setSource("product_container");

        $collection1 = $mongo1->getCollection();
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("product_upload");

        $collection = $mongo->getCollection();

        $this->di->getLog()->logContent("Product = ".\GuzzleHttp\json_encode($productAsin).' || PRODUCT Exists or Not in product_upload collection  == '.\GuzzleHttp\json_encode($exist_in_upload),'info','shopify'.DS.$userId.DS.'product_upload'.DS.date("Y-m-d").DS.'product_upload.log');

        if(is_null($exist_in_upload)){
            if($mode=="productCreate"){
                if(isset($result['product']) &&
                    !is_null($result['product'])){
                    $variant_nodes=$result['product']['variants']['edges'];
                    if(isset($result['product']['metafields']['edges'])){
                        $meta_nodes=$result['product']['metafields']['edges'];
                    }

                    $meta_items = [];
                    $id=$result['product']['id'];
                    if(isset($meta_nodes)){
                        foreach ($meta_nodes as $nodes){
                            $meta_items[$nodes['node']['key']] = $nodes['node']['id'];
                        }
                    }

                    foreach($variant_nodes as $nodes){
                        $container_data =$collection1->find(['$and'=> [
                            ['user_id'=>$userId],
                            ['sku' => $nodes['node']['sku']]
                        ]])->toArray();
                        $admin_source_product_id = filter_var($nodes['node']['id'], FILTER_SANITIZE_NUMBER_INT);
                        $admin_container_id = filter_var($id, FILTER_SANITIZE_NUMBER_INT);
                        if(isset($nodes['node']['inventoryItem']['id'])){
                            $inventory_item_id = filter_var($nodes['node']['inventoryItem']['id'], FILTER_SANITIZE_NUMBER_INT);
                        }

                        $collection->insertOne(
                            [
                                'container_id'=> (string)$container_data[0]['container_id'],
                                'source_product_id'=>(string)$container_data[0]['source_product_id'],
                                'user_id'=>$userId,
                                'upload_status'=>true,
                                'status'=>'uploaded',
                                'profile'=>(int)$profileId,
                                'admin_container_id'=> $admin_container_id,
                                'admin_source_product_id'=> $admin_source_product_id,
                                'inventory_item_id'=> isset($nodes['node']['inventoryItem']['id']) ? $inventory_item_id : $nodes['node']['inventory_item_id'],
                                'sku'=>$nodes['node']['sku'],
                                'quantity'=> $nodes['node']['inventoryQuantity'] ?? $nodes['node']['inventory_quantity'],
                                'metafields' => $meta_items,
                                'source'=>$sourceShopId
                            ]
                        );
                        $res = $collection1->updateOne(
                            ['$and'=>[
                                ['user_id' => (string)$userId],
                                ['source_product_id' => (string)$container_data[0]['source_product_id']]
                            ]],
                            [ '$set' => [
                                "upload_status" => true,
                                'status'=>'uploaded',
                            ] ]
                        );
                    }

                    $res = $collection1->updateOne(
                        ['$and'=>[
                            ['user_id' => (string)$userId],
                            ['container_id' => $productAsin]
                        ]],
                        [ '$set' => [
                            "upload_status" => true,
                            'status'=>'uploaded',
                        ]]
                    );
                }
            }
        }
        else{
            if(isset($result['product']) && !is_null($result['product'])){
                $variant_nodes=$result['product']['variants']['edges'];
                $meta_nodes=$result['product']['metafields']['edges'];
                $meta_items = [];
                foreach ($meta_nodes as $nodes){
                    $meta_items[$nodes['node']['key']] = $nodes['node']['id'];
                }

                foreach ($variant_nodes as $nodes){
                    $container_data =$collection1->find(['$and'=> [
                        ['user_id'=>$userId],
                        ['sku' => $nodes['node']['sku']]
                    ]])->toArray();

                    $admin_source_product_id = filter_var($nodes['node']['id'], FILTER_SANITIZE_NUMBER_INT);
                    $inventory_item_id = filter_var($nodes['node']['inventoryItem']['id'], FILTER_SANITIZE_NUMBER_INT);

                    $collection->updateOne(
                        ['$and'=>[
                            ['user_id'=>$userId],
                            ['source_product_id' => (string)$container_data[0]['source_product_id']]
                        ]],
                        [ '$set' => [
                            'source'=>$sourceShopId,
                            'inventory_item_id'=>$inventory_item_id,
                            'admin_source_product_id'=>$admin_source_product_id,
                            'sku'=>$nodes['node']['sku'],
                            'quantity'=>$nodes['node']['inventoryQuantity'],
                            'metafields' => $meta_items,
                            "upload_status" => true,
                            'status'=>'uploaded',
                        ]]
                    );
                    $res = $collection1->updateOne(
                        ['$and'=>[
                            ['user_id' => (string)$userId],
                            ['source_product_id' => (string)$container_data[0]['source_product_id']]
                        ]],
                        [ '$set' => [
                            "upload_status" => true,
                            'status'=>'uploaded',
                        ] ]
                    );
                }
            }
        }
    }

    public function handleProductData($data, $userId = null){
        $this->prepare($userId);
        if($this->_error) return $this->_error;

        switch($data['db_action'])
        {
            case 'variant_update' :
                unset($data['db_action']);
                $this->updateProductVariant($data);
                break;

            case 'delete' :
                break;

            default :
                return [
                    'success' => false,
                    'message' => "No action defined"
                ];
        }
    }

    public function deleteProducts($userId = null , $locationId = []): void{
        $this->prepare($userId);
        $this->_collection->drop();
    }

    public function deleteIndividualVariants($containerId, $products = [], $deleteParent = false, $userId = null): void{
        $this->prepare($userId);
        if($deleteParent){
            $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(['$and'=>[
                ['source_product_id' => $containerId],
                ['type' => 'variation']
            ]],$userId);
            $this->_collection->deleteOne($query);
        } else {
            foreach ($products as $variantId){
                $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(["source_product_id" => $variantId],$userId);
                $res = $this->_collection->deleteOne($query);
            }
        }
    }

    public function getallVariantIds($id, $userId = null){
        $this->prepare($userId);
        $ids = $this->_collection->distinct("source_product_id"
            ,["container_id" => $id]
        );
        if (($key = array_search($id, $ids)) !== false) {
            unset($ids[$key]);
        }

        return $ids;
    }

    public function updateProductVariant($data): void{

        $prefix = $data['mode'] ?? '';
        $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(['source_product_id' => $data['source_product_id']],$this->di->getUser()->id);
        $out = $this->_collection->findOne($query, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        $helper = $this->di->getObjectManager()->get(Helper::class);
        $res = $this->_collection->updateOne(
            $query,
            [ '$set' => $data ]
        );


    }

    public function updateWebhookProductVariants($data, $userId = null): void{
        $this->prepare($userId);
        $updateVariant = $this->_collection->updateOne(
            ['details.source_product_id' => $data['source_product_id']],
            ['$set' => [
                'variant_attribute' => $data['attributes']
            ]
            ]
        );
        $this->di->getLog()->logContent('PROCESS 00323 | Data | updateProductVariant ','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'webhook_product_import.log');

    }

    public function checkProductExists($data, $userId = null){
        $this->prepare($userId);
        $query = $this->di->getObjectManager()->get(Helper::class)->getAndQuery(['source_product_id' => $data['source_product_id']],$userId);
        $productExists = $this->_collection->findOne($query);
        $this->di->getLog()->logContent("Product Exists == ".json_encode($productExists),'info','shopify'.DS.'global'.DS.date("Y-m-d").DS.'webhook_product_import.log');

        if($productExists){
            return [
                'success' => true
            ];
        }
        return [
            'success' => false,
            'msg' => 'product does not exist'
        ];
    }

    public function fetchProductsByProfile($profileId, $userId, $sourceShopId = false, $cursor = null, $failedProduct = null)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('profiles');
        $profile = $collection->findOne([
//            "profile_id" => (int)$profileId
            "profile_id" => (string)$profileId
        ]);
        if ($profile) {
            $productQuery = $profile->query;
            $marketplace = $profile->source;
            $productData = $this->getProductsByProfileQuery(['marketplace' => $marketplace, 'query' => $productQuery], $userId, $marketplace, $cursor, $failedProduct, $profileId);
            if ($productData['success']) {
                return $productData;
            }

            return [];
        }
        return false;
    }

    public function getProductsByProfileQuery($productQueryDetails, $userId = false, $source = false, $cursor = null, $failedProduct = null, $profileId = null)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $marketplace = $productQueryDetails['marketplace'] ?? '';
        $filterQuery = $productQueryDetails['query'] ?? '';
        $filterQuery = $this->prepareQuery($filterQuery, $failedProduct);
//        $filterQuery[''] = ['source_marketplace'=>$source];

        $customizedFilterQuery = [
            '$and' => [
                ['user_id' => (string)$userId],
                ['visibility'=>"Catalog and Search"],
                $filterQuery
            ]
        ];
        if ($filterQuery) {
            $finalQuery = [
                [
                    '$match' => $customizedFilterQuery
                ],
                [
                    '$limit' => ($cursor + 1) * 10
                ],
                [
                    '$skip' => $cursor * 10
                ]
            ];
            $countFinalQuery = [
                [
                    '$match' => $customizedFilterQuery
                ],
                [
                    '$count' => 'count'
                ]
            ];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $mongo->setSource("product_container");
            $collection = $mongo->getCollection();
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $response = $collection->aggregate($finalQuery, $options);
            $countResponse = $collection->aggregate($countFinalQuery, $options);
            $countResponse = $countResponse->toArray();
            $count = $countResponse[0]['count'] ?: 0;
            $selectedProducts = $response->toArray();
            return ['success' => true, 'data' => $selectedProducts, 'total_count' => $count];
        }

        return ['success' => false, 'code' => 'no_products_found', 'message' => 'No products found'];
    }

    public function pushToQueue($data, $time = 0, $queueName = 'temp')
    {
        $data['run_after'] = $time;
        if($this->di->getConfig()->get('enable_rabbitmq_internal')){
            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
            $this->di->getLog()->logContent(' push_TOQueue  == '.print_r($queueName,true),'info','shopify'.DS.$data['data']['user_id'].DS.'product_upload'.DS.date("Y-m-d").DS.'queueName.log');
            $this->di->getLog()->logContent(' push_TOQueue  == '.print_r($data,true),'info','shopify'.DS.$data['data']['user_id'].DS.'product_upload'.DS.date("Y-m-d").DS.'queueData.log');
            return $helper->createQueue($queueName,$data);
        }

        return true;
    }

    public function resetKeyParams($user_id): void {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("import_csv_".$user_id);

        $collection = $mongo->getCollection();
        $collection->updateMany([],['$set' => ['is_imported' => "0"]]);
    }

    public function fetchChunkData($user_id, $cursor = 0, $chunk = 10 , $source_product_id = '',  $test = false) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("import_csv_".$user_id);

        $collection = $mongo->getCollection();
        if ( $collection->count() == 0 ) {
            return ['errorFlag' => true, 'message' => 'CSV not uploaded yet.'];
        }

        $aggregateConditions[] = [
            '$match' => [
                'is_imported' => "0",
            ],
        ];
        $aggregateConditions[] = ['$limit' => $chunk];
        if ( $source_product_id != '' ) {
            $aggregateConditions[] = [
                '$group' => [
                    '_id' => '$'.$source_product_id,
                    'data' => ['$push' => '$$ROOT'],
                    'count' => ['$sum' => 1]
                ],
            ];
            $aggregateConditions[] = [
                '$match' => [
                    '_id' => ['$ne' => ""],
                ],
            ];
        }

        $pData = $collection->aggregate($aggregateConditions)->toArray();

        if ( !$test ) foreach ( $pData as $key => $val ) {
            ;
            $collection->updateMany([$source_product_id => $val['_id']],['$set' => ['is_imported' => "1"]]);
        }

        if ( count($pData) == 0 ) {
            return ['data' => $pData, 'count' => count($pData)];
        }
        return ['data' => $pData, 'cursor' => $cursor + 1, 'count' => count($pData)];
    }

    public function getVariantsAttribute($user_id, $options_value, $parentField, $source_product_id) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("import_csv_".$user_id);

        $collection = $mongo->getCollection();
        $conditionAggregate[] = [
            '$match' => [
                '$and' => [
                    [ $options_value => ['$ne' => ""]],
                    [$parentField => $source_product_id]
                ]
            ]
        ];
        $conditionAggregate[] = ['$limit' => 1];
        $conditionAggregate[] = ['$skip' => 0];
        $objData =  $collection->aggregate($conditionAggregate)->toArray();
        return $objData[0];
    }

}