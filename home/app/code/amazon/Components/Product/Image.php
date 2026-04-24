<?php

namespace App\Amazon\Components\Product;

use Exception;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\Feed\Feed;
use App\Core\Components\Base;
use Aws\S3\S3Client;

class Image extends Base
{
    private ?string $_user_id = null;

    private $configObj;

    public function init($request = [])
    {
        if (isset($request['user_id'])) {
            $this->_user_id = (string)$request['user_id'];
        } else {
            $this->_user_id = (string)$this->di->getUser()->id;
        }

        $this->configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
        return $this;
    }


    public function upload($sqsData)
    {
        $feedContent = [];
        $addTagProductList = [];
        $productErrorList = [];
        $activeAccounts = [];
        $sourceProductId = [];
        $response = [];
        $data = json_decode(json_encode($sqsData, true), true);
        $productComponent = $this->di->getObjectManager()->get(Product::class);
        if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
            $userId = $data['data']['params']['user_id'] ?? $this->_user_id;
            $targetShopId = $data['data']['params']['target']['shopId'];
            $sourceShopId = $data['data']['params']['source']['shopId'];
            $targetMarketplace = $data['data']['params']['target']['marketplace'];
            $sourceMarketplace = $data['data']['params']['source']['marketplace'];
            $specificData = $data['data']['params'];
            $specificData['type'] = 'image';
            $allParentDetails = $data['data']['all_parent_details'] ?? [];
            $allProfileInfo = $data['data']['all_profile_info'] ?? [];
            $date = date('d-m-Y');
            $logFile = "amazon/{$userId}/{$sourceShopId}/SyncImage/{$date}.log";
            $userShops = $this->di->getUser()->shops ?? [];
            if (!empty($userShops)) {
                $targetShop = array_filter($userShops, function (array $shop) use ($targetShopId) {
                    if ($shop['_id'] == $targetShopId) {
                        return $shop;
                    }
                });
                if (!empty($targetShop)) {
                    foreach ($targetShop as $shop) {
                        $targetShop = $shop;
                        break;
                    }
                }
            } else {
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $targetShop = $user_details->getShop($targetShopId, $userId);
            }

            foreach ($targetShop['warehouses'] as $warehouse) {
                if ($warehouse['status'] == "active") {
                    $activeAccounts = $warehouse;
                    $specificData['marketplaceId'] = $warehouse['marketplace_id'];
                    break;
                }
            }

            if (!empty($activeAccounts)) {
                $products = $data['data']['rows'];
                foreach ($products as $product) {
                    $product = json_decode(json_encode($product), true);
                    if (isset($product['parent_details']) && is_string($product['parent_details'])) {
                        $product['parent_details'] = $allParentDetails[$product['parent_details']];
                    }
                    if (isset($product['profile_info']['$oid'])) {
                        $product['profile_info'] = $allProfileInfo[$product['profile_info']['$oid']];
                    }
                    $productComponent->removeTag([$product['source_product_id']], [Helper::PROCESS_TAG_IMAGE_SYNC], $targetShopId, $sourceShopId, $userId, [], []);
                    $content = [];
                    $target = [];
                    // $marketplaces = $product['marketplace'];
                    // foreach ($marketplaces as $marketplace) {
                    //     if(isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == $targetMarketplace && $marketplace['shop_id'] == $targetShopId && $marketplace['source_product_id'] == $product['source_product_id'])
                    //     {
                    //         $target = $marketplace;
                    //     }
                    // }
                    if (isset($product['edited'])) {
                        $target = $product['edited'];
                    } else {
                        $target = $this->di->getObjectManager()->get(Marketplace::class)->getMarketplaceProduct($product['source_product_id'], $targetShopId, $product['container_id']);
                    }

                    if (!isset($target['status']) || $target['status'] == \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER) {
                        $productErrorList[$product['source_product_id']] = ['AmazonError501'];
                    } else {
                        $container_ids[$product['source_product_id']] = $product['container_id'];
                        $alreadySavedActivities[$product['source_product_id']] =
                            $product['edited']['last_activities'] ?? [];
                        $this->configObj->setUserId($userId);
                        $this->configObj->setTargetShopId($targetShopId);
                        $this->configObj->setSourceShopId($sourceShopId);
                        $this->configObj->setTarget($targetMarketplace);
                        $syncingEnabled = $this->isSyncingEnabled($product);
                        if ($syncingEnabled) {
                            $content = $this->prepare($product, Product::PRODUCT_TYPE_PARENT);
                            if (!empty($content)) {
                                $feedContent[$product['source_product_id']] = $content;
                                $sourceProductId[] = $product['source_product_id'];
                            } else {
                                $productErrorList[$product['source_product_id']] = ['AmazonError161'];
                            }
                        } else {
                            $productErrorList[$product['source_product_id']] = ['AmazonError162'];
                        }
                    }

                    if (isset($product['next_prev'])) {
                        unset($productErrorList[$product['source_product_id']]);
                    }
                }

                                // Direct initiate implementation for not more than 10 products
                $customUser = $this->di->getConfig()->get('CustomImagePatch')??[];
                if(!is_array($customUser)){
                    $customUser = $customUser->toArray();
                }
                
                $useDirectInitiate = false;
                if(!empty($feedContent) && count(array_keys($feedContent))<50){
                    $useDirectInitiate = true;
                    $activityFeed = [];
                    $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                    
                    foreach($feedContent as $key => $value)
                    {
                        $payload = [];
                        $payload['shop_id'] = $targetShop['remote_shop_id'];
                        $payload['product_type'] = 'PRODUCT';
                        
                        // Prepare image attributes dynamically
                        $imagePatches = [];
                        $usedImageNumbers = [];
                        if(isset($value['feed']) && !empty($value['feed'])){
                            $imageCounter = 0;
                            foreach($value['feed'] as $imageKey => $imageUrl){
                                $imagePath = '';
                                $imageAttributes = [];
                                
                                // Map images based on the key from feed
                                if($imageKey == 'main_product_image_locator'){
                                    $imagePath = '/attributes/main_product_image_locator';
                                } else {
                                    // For other_product_image_locator_1, other_product_image_locator_2, etc.
                                    $imagePath = '/attributes/' . $imageKey;
                                    // Extract the number from other_product_image_locator_X
                                    if(preg_match('/other_product_image_locator_(\d+)/', $imageKey, $matches)){
                                        $usedImageNumbers[] = (int)$matches[1];
                                    }
                                }
                                
                                $imageAttributes[] = [
                                    'media_location' => $imageUrl,
                                    'marketplace_id' => $activeAccounts['marketplace_id']
                                ];
                                
                                $imagePatches[] = [
                                    "op" => "replace",
                                    "operation_type" => "PARTIAL_UPDATE",
                                    "path" => $imagePath,
                                    "value" => $imageAttributes
                                ];
                            }
                            
                            // Add delete operations for unused image locators (up to 8 additional images)
                            $deleteimageAttributes[] = [
                                    'media_location' => ' https://m.media-amazon.com/images/I/412.jpg',
                                    'marketplace_id' => $activeAccounts['marketplace_id']
                                ];


                            for($i = 1; $i <= 8; $i++){
                                if(!in_array($i, $usedImageNumbers)){
                                    $imagePatches[] = [
                                        "op" => "delete",
                                        "path" => "/attributes/other_product_image_locator_" . $i,
                                        "value" => $deleteimageAttributes
                                    ];
                                }
                            }
                        }
                        
                        if(!empty($imagePatches)){
                            $payload['patches'] = $imagePatches;
                            
                            $payload['sku'] = rawurlencode($value['SKU']);
                            $res = $commonHelper->sendRequestToAmazon('listing-update', $payload, 'POST');
                            
                            if(isset($res['success']) && $res['success'] && !empty($res['response']['issues'])){
                                $productErrorList[$key] = $res['response']['issues'];
                                unset($feedContent[$key]);
                                $activityFeed[$key] = $value;
                            }
                            elseif(isset($res['success']) && $res['success']){
                                unset($feedContent[$key]);
                                $activityFeed[$key] = $value;
                            }
                        }
                    }
                    
                    if(!empty($activityFeed)){
                        $inventoryComponent = $this->di->getObjectManager()->get(Inventory::class);
                        $inventoryComponent->saveFeedInDb($activityFeed, 'image', $container_ids, $data['data']['params'], $alreadySavedActivities);
                    }
                }

                if (!empty($feedContent) && !empty($userId) && !$useDirectInitiate) {

                    $products = $data['data']['rows'];
                    $specifics = [
                        'ids' => $sourceProductId,
                        'home_shop_id' => $targetShopId,
                        'marketplace_id' => $activeAccounts['marketplace_id'],
                        'sourceShopId' => $sourceShopId,
                        'shop_id' => $targetShop['remote_shop_id'],
                        'feedContent' => base64_encode(serialize($feedContent)),
                        'user_id' => $userId,
                        'operation_type' => 'Update',
                        'unified_json_feed' => true
                    ];
                    $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                    $response = $commonHelper->sendRequestToAmazon('image-upload', $specifics, 'POST');
                    if (isset($response['success']) && $response['success']) {
                        $inventoryComponent = $this->di->getObjectManager()->get(Inventory::class);
                        $inventoryComponent->saveFeedInDb($feedContent, 'image', $container_ids, $data['data']['params'], $alreadySavedActivities);
                    } elseif ((empty($response) || (isset($response['success']) && !$response['success'])) && !empty($products)) {
                        $this->di->getLog()->logContent('When processing image sync error occured:' . json_encode($response, true), 'info', 'amazon/exception/' . date('Y-m-d') . '.log');
                        foreach ($products as $product) {
                            isset($product['source_product_id']) && $productErrorList[$product['source_product_id']] = ['AmazonError996'];
                        }
                    }

                    $specificData['tag'] = Helper::PROCESS_TAG_IMAGE_FEED;
                    $specificData['unsetTag'] = Helper::PROCESS_TAG_IMAGE_SYNC;
                    $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);
                    // $productSpecifics = $productComponent->init()
                    //     ->processResponse($response,$feedContent, \App\Amazon\Components\Common\Helper::PROCESS_TAG_IMAGE_FEED, 'image',$targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList,\App\Amazon\Components\Common\Helper::PROCESS_TAG_IMAGE_SYNC);
                    $addTagProductList = $productSpecifics['addTagProductList'];
                    $productErrorList = $productSpecifics['productErrorList'];
                    return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);
                }
                $specificData['tag'] = Helper::PROCESS_TAG_IMAGE_SYNC;
                $specificData['unsetTag'] = false;
                $productSpecifics = $this->di->getObjectManager()->get(Marketplace::class)->init($specificData)->prepareAndProcessSpecifices($response, $productErrorList, $feedContent, $products);
                // $productSpecifics = $productComponent->init()
                // ->processResponse($response,$feedContent, \App\Amazon\Components\Common\Helper::PROCESS_TAG_IMAGE_SYNC, 'image',$targetShop, $targetShopId, $sourceShopId, $userId, $products, $productErrorList);
                return $productComponent->setResponse(Helper::RESPONSE_SUCCESS, "", count($addTagProductList), count($productErrorList), 0);
            }
            return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop is not active.', 0, 0, 0);
        }
        $this->di->getLog()->logContent('sqsData:' . json_encode($sqsData, true), 'info', 'amazon/image_upload/' . date('Y-m-d') . '.log');
        $this->di->getLog()->logContent('data:' . json_encode($data, true), 'info', 'amazon/image_upload/' . date('Y-m-d') . '.log');
        return $productComponent->setResponse(Helper::RESPONSE_TERMINATE, 'Target shop or source shop is empty or Rows data is empty.', 0, 0, 0);
    }

    public function uploadImageOnS3($image, $shopId, $sourceProductId, $type)
    {
        try {
            $bucketName = $this->di->getConfig()->get('imageBucket');
            $config = include BP . '/app/etc/aws.php';
            $s3Client = new S3Client($config);
            $date = date('d-m-Y');
            $logFile = "amazon/{$shopId}/ImageUpload/{$date}.log";
            $bucket = $bucketName ?? 'amazon-product-image-upload-live';

            $imageData = stream_context_create(['http' => ['ignore_errors' => true]]);
            $imageData = file_get_contents($image, false, $imageData);

            $http_response_code = substr($http_response_header[0], 9, 3);
            if ($http_response_code === '200') {

                $imageInfo = getimagesizefromstring($imageData);
                $imageType = $imageInfo['mime'];
                if ($imageType) {
                    $parts = explode('/', $imageType);
                    if ($parts !== []) {
                        $imageType = $parts[1];
                    }
                } else {
                    $imageType = "jpg";
                }

                $imageKey = $type . '.' . $imageType;
                $filePath = "{$shopId}/{$sourceProductId}/";

                $result = $s3Client->putObject([
                    'Bucket' => $bucket,
                    'Key'    => $filePath . $imageKey, // Combine path and object name
                    'Body'   => $imageData,
                    'ACL'    => 'public-read', // Make the object publicly accessible
                ]);
                $response = ['success' => true, 'img' => $result['ObjectURL']];
            } else {
                $this->di->getLog()->logContent('error = ' . json_encode([$sourceProductId, $image, $http_response_code], true), 'info', $logFile);
                $response = ['success' => false];
            }
        } catch (Exception $e) {
            $msg = $e->getMessage();
            $this->di->getLog()->logContent('error = ' . print_r($msg, true), 'info', $logFile);

            $response = ['success' => false, 'msg' => $msg];
        }

        return $response;
    }

    public function isSyncingEnabled($product)
    {
        $syncProductTemplate = false;
        $sync = false;
        if (isset($product['profile_info']) && is_array($product['profile_info'])) {
            $profiles = $product['profile_info'];
            if ($profiles) {
                $syncProductTemplate = false;
                $syncProductStatusEnabled = false;
                foreach ($profiles['data'] as $profile) {
                    if (isset($profile['data_type']) && $profile['data_type'] == "product_settings") {
                        $syncProductTemplate = $profile;
                        break;
                    }
                }

                if ($syncProductTemplate) {
                    $selectedAttributes = $syncProductTemplate['data']['selected_attributes'];
                    $selectedAttributes = json_decode(json_encode($selectedAttributes), true);
                    if (in_array('images', $selectedAttributes) && isset($syncProductTemplate['data']['images']) && $syncProductTemplate['data']['images']) {
                        $sync = true;
                    }
                }
            }
        }

        if (!$syncProductTemplate) {
            $this->configObj->setGroupCode('product');
            $syncProductAttributes = $this->configObj->getConfig("selected_attributes");
            $imageData = $this->configObj->getConfig("images");
            $imageData = json_decode(json_encode($imageData), true);
            $syncProductAttributes = json_decode(json_encode($syncProductAttributes), true);
            if (isset($syncProductAttributes[0]) && $imageData[0]) {
                if (in_array('images', $syncProductAttributes[0]['value']) && $imageData[0]['value']) {
                    $sync = true;
                    $syncProductTemplate = true;
                }
            }
        }

        if (!$syncProductTemplate) {
            $this->configObj->setUserId('all');
            $this->configObj->setGroupCode('product');
            $syncProductAttributes = $this->configObj->getConfig("selected_attributes");
            $imageData = $this->configObj->getConfig("images");
            $imageData = json_decode(json_encode($imageData), true);
            $syncProductAttributes = json_decode(json_encode($syncProductAttributes), true);
            if (isset($syncProductAttributes[0]) && $imageData[0]) {
                if (in_array('images', $syncProductAttributes[0]['value']) && $imageData[0]['value']) {
                    $sync = true;
                    $syncProductTemplate = true;
                }
            }
        }

        return $sync;
    }

    public function prepare($product, $type)
    {
        $sku = false;
        $counter = 0;
        $amazonProduct = [];
        $time = date(DATE_ISO8601);
        $result = [];
        $feedContent = [];
        $data = [];
        if (isset($product['edited'])) {
            $amazonProduct = $product['edited'];
        }

        if ($amazonProduct) {
            if ($type == Product::PRODUCT_TYPE_PARENT && isset($amazonProduct['parent_sku'])) {
                $sku = $amazonProduct['parent_sku'];
            } elseif (isset($amazonProduct['sku'])) {
                $sku = $amazonProduct['sku'];
            }
        }

        if (!$sku) {
            if (isset($product['sku']) && $product['sku']) {
                $sku = $product['sku'];
            } else {
                $sku = $product['source_product_id'];
            }
        }

        if ($sku) {
            $imageTypes = "other_product_image_locator_";


            $mainImage = null;

            if (isset($amazonProduct['main_image']) && !empty($amazonProduct['main_image'])) {
                $mainImage = $amazonProduct['main_image'];
            } elseif ($type == Product::PRODUCT_TYPE_CHILD && isset($amazonProduct['variant_image']) && !empty($product['variant_image'])) {
                $mainImage = $amazonProduct['main_image'];
            } elseif (isset($product['main_image']) && !empty($product['main_image'])) {
                $mainImage = $product['main_image'];
            }

            if ($mainImage) {
                $data['main_product_image_locator'] = $mainImage;
            }

            $additionalImages = [];
            if (isset($amazonProduct['additional_images'])) {
                $additionalImages = $amazonProduct['additional_images'];
            } elseif (!empty($product['parent_details']['edited']['additional_images'])) {
                $additionalImages = $product['parent_details']['edited']['additional_images'];
            } elseif (isset($product['additional_images']) && !empty($product['additional_images'])) {
                $additionalImages = $product['additional_images'];
            }

            if (!empty($additionalImages)) {
                $counter = 0;
                foreach ($additionalImages as $image) {
                    if ($counter > 7) {
                        break;
                    }

                    $counter++;
                    $imageTypeAdditional = "other_product_image_locator_{$counter}";
                    $data[$imageTypeAdditional] = $image;
                }
            }
        }

        if (!empty($data)) {
            $feedContent = [
                'Id' => $product['_id'] . '0000',
                'SKU' => $sku,
                'feed' => $data,
                'time' => $time
            ];
        }

        return $feedContent;
    }

    public function update($data)
    {
        if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
            $ids = $data['source_product_ids'];
            $profileIds = $this->di->getObjectManager()->get(\App\Amazon\Components\Profile\Helper::class)
                ->getProfileIdsByProductIds($data['source_product_ids']);
            if (!empty($profileIds)) {
                //get profiles from profile Ids
                $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                $profileCollection = $baseMongo->getCollectionForTable(Helper::PROFILES);
                $profiles = $profileCollection->find(['profile_id' => ['$in' => $profileIds]], ["typeMap" => ['root' => 'array', 'document' => 'array']]);

                foreach ($profiles as $profile) {
                    $accounts = $this->di->getObjectManager()->get(\App\Amazon\Components\Profile\Helper::class)
                        ->getAccountsByProfile($profile);

                    foreach ($accounts as $homeShopId => $account) {
                        $productIds = $this->di->getObjectManager()->get(\App\Amazon\Components\Profile\Helper::class)
                            ->getAssociatedProductIds($profile, $ids);

                        if (!empty($productIds)) {
                            $categoryTemplateId = $account['templates']['category'];
                            $titleTemplateId = $account['templates']['title'];
                            foreach ($account['warehouses'] as $amazonWarehouse) {
                                $marketplaceIds = $amazonWarehouse['marketplace_id'];
                                if (!empty($marketplaceIds)) {
                                    $specifics = [
                                        'ids' => $productIds,
                                        'home_shop_id' => $homeShopId,
                                        'marketplace_id' => $marketplaceIds,
                                        'profile_id' => $profile['profile_id'],
                                        'title_template' => $titleTemplateId,
                                        'category_template' => $categoryTemplateId,
                                        'shop_id' => $account['remote_shop_id']
                                    ];
                                    $feedContent = $this->prepare($specifics);
                                    if (!empty($feedContent)) {
                                        $specifics['feedContent'] = $feedContent;
                                        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                                        $response = $commonHelper->sendRequestToAmazon('image-upload', $specifics, 'POST');
                                        if (empty($response)) {
                                            return [
                                                'success' => false,
                                                'message' => 'No response received from remote!'
                                            ];
                                        }
                                        if (isset($response['success'])) {
                                            if ($response['success']) {
                                                $feed = $this->di->getObjectManager()
                                                    ->get(Feed::class)
                                                    ->init()
                                                    ->process($response);
                                                return ['success' => true, 'message' => 'Image(s) upload request processed successfully.
                                                       The feed is queued and will be processed later as availability of API.'];
                                            }
                                            return [
                                                'success' => $response['success'],
                                                'message' => $response['msg']
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
