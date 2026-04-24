<?php

namespace App\Amazon\Components\Image;

use App\Amazon\Models\SourceModel;
use Exception;
use App\Core\Components\Base;
use App\Amazon\Components\Common\Helper;


class Image extends Base
{

    private ?string $_user_id = null;



    public function init($request = [])
    {
        if (isset($request['user_id'])) {
            $this->_user_id = (string)$request['user_id'];
        } else {
            $this->_user_id = (string)$this->di->getUser()->id;
        }

        return $this;
    }

    public function upload($sqs_data)
    {
        try {
            $data = $sqs_data['data'];
            $source_marketplace = $data['source_marketplace'];
            $target_marketplace = $data['target_marketplace'];
            $source_shop_id = $data['source_shop_id'];
            $shops = $data['amazon_shops'];
            $returnArray = [];
            $errorArray = [];
            if ($target_marketplace == '' || $source_marketplace == '' || empty($shops)) {
                return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
            }

            $commonHelper = $this->di->getObjectManager()->get(Helper::class);

            $allValidProductStatus = [
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_LISTING_IN_PROGRESS,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_ACTIVE,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INACTIVE,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INCOMPLETE,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_SUPRESSED,
                \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UNKNOWN,
            ];
            foreach ($shops as $shop) {
                $marketplace_id = $shop['warehouses'][0]['marketplace_id'];

                $isEnableImage = $this->checkImageSync();
                if (isset($isEnableImage['status']) && !$isEnableImage['status']) {
                    $errorArray[$marketplace_id] = 'Image Sync Is Disabled';
                } else {
                    $params = [];
                    $feedContent = [];
                    $params['source_marketplace']['marketplace'] = $source_marketplace;
                    $params['target_marketplace']['marketplace'] = $target_marketplace;
                    $params['target_marketplace']['shop_id'] = $shop['_id'];
                    $params['source_marketplace']['shop_id'] = $source_shop_id;
                    $params['source_product_id'] = $data['source_product_ids'];

                    $objectManager = $this->di->getObjectManager();
                    $helper = $objectManager->get('\App\Connector\Models\Product\Index');
                    $mergedData = $helper->getProducts($params);

                    if (!empty($mergedData)) {
                        if (isset($mergedData['data']['rows'])) {
                            $source_product_ids = [];
                            foreach ($mergedData['data']['rows'] as $product) {

                                $productStatus = $this->di->getObjectManager()->get(SourceModel::class)->getProductStatusByShopId($product, $shop['_id']);

                                if (in_array($productStatus, $allValidProductStatus)) {

                                    $preparedData = $this->prepareData($product);
                                    if (!empty($preparedData)) {
                                        $feedContent = array_merge($feedContent, $preparedData);
                                        $source_product_ids[] = $product['source_product_id'];
                                    } else {
                                        $errorArray[$marketplace_id][$product['source_product_id']] = 'No Image found';
                                    }
                                }
                            }

                            if (!empty($feedContent)) {
                                $specifics = [
                                    'ids' => $source_product_ids,
                                    'home_shop_id' => $shop['_id'],
                                    'marketplace_id' => $marketplace_id,
                                    'shop_id' => $shop['remote_shop_id'],
                                    'feedContent' => base64_encode(json_encode($feedContent))
                                ];
                                $response = $commonHelper->sendRequestToAmazon('image-upload', $specifics, 'POST');

                                if (isset($response['success']) && $response['success']) {
                                    $successArray[$marketplace_id] = 'Feed successfully uploaded on s3';
                                } else {
                                    $errorArray[$marketplace_id] = 'Error from remote';
                                }

                            } else {
                                $errorArray[$marketplace_id] = 'No products available for image update';
                            }
                        } else {
                            $errorArray[$marketplace_id] = 'No Products Found';
                        }
                    } else {
                        $errorArray[$marketplace_id] = 'No Products Found';
                    }
                }
            }

            if (!empty($errorArray)) {
                $returnArray['error'] = ['status' => true, 'message' => $errorArray];
            }

            if (!empty($successArray)) {
                $returnArray['success'] = ['status' => true, 'message' => $successArray];
            }

            if (isset($sqs_data['queued_task_id'])) {
                $message = 'Image data successfully uploaded on s3 bucket.';

                $this->di->getObjectManager()->get('App\Connector\Components\Helper')
                    ->updateFeedProgress(
                        $sqs_data['queued_task_id'],
                        100,
                        $message
                    );

                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')
                    ->addNotification((string)$this->_user_id, $message, 'success');
            }

            return $returnArray;
        } catch (Exception $e) {
            $success = false;
            $message = $e->getMessage();
            return ['success' => $success, 'message' => $message];
        }
    }

    public function checkImageSync()
    {
        return ['status' => true];
    }

    public function prepareData($product)
    {
        $feedContent = [];
        $sku = $product['sku'];
        $imageTypes = [
            0 => 'PT1',
            1 => 'PT2',
            2 => 'PT3',
            3 => 'PT4',
            4 => 'PT5',
            5 => 'PT6',
            6 => 'PT7',
            7 => 'PT8'
        ];
        if (isset($product['main_image'])) {
            $mainImage = $product['main_image'];
            $feedContent[$product['source_product_id'] . '0000'] = [
                'Id' => $product['source_product_id'] . '0000',
                'SKU' => $sku,
                'ImageType' => 'Main',
                'Url' => $mainImage
            ];
        }

        if (isset($product['additional_images']) && !empty($product['additional_images'])) {
            $counter = 0;
            foreach ($product['additional_images'] as $imageKey => $image) {
                if ($counter > 7) {
                    break;
                }

                $feedContent[$product['source_product_id'] . $imageKey] = [
                    'Id' => $product['source_product_id'] . $imageKey,
                    'SKU' => $sku,
                    'ImageType' => $imageTypes[$counter++],
                    'Url' => $image
                ];
            }
        }

        return $feedContent;
    }

}