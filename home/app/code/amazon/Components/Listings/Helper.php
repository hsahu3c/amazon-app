<?php

namespace App\Amazon\Components\Listings;

use Exception;
use App\Amazon\Components\Template\CategoryAttributeCache;
use App\Amazon\Components\Common\Helper as CommonHelper;

use App\Core\Models\User;
use App\Core\Components\Base as Base;
use App\Amazon\Components\Product\Product;


class Helper extends Base
{
    public const DEFAULT_PRODUCT_TYPE = 'PRODUCT';
    public const PRODUCT_TYPE_CHILD = 'child';
    public const LISTING = 'LISTING'; // product facts and sales terms
    public const LISTING_OFFER_ONLY = 'LISTING_OFFER_ONLY';  // sales terms only

    // public function isBetaSeller($userId = null)
    // {
    //     try {
    //         $betaSellersId = $this->di->getConfig()->get('beta_json_listing');
    //         if(!empty($betaSellersId)){
    //             $betaSellersId = $betaSellersId->toArray();
    //             $userId = $userId ?? (string)$this->di->getUser()->id;
    //             if(in_array($userId, $betaSellersId)) {
    //                 return true;
    //             }
    //         }
    //         return false;
    //     } catch(\Exception $e) {
    //         print_r($e->getMessage());
    //         return false;
    //     }
    // }
    /*public function isBetaSeller($userId = null, $marketplaceId = null)
    {
        try {
            $allowedSellersNMarketplaces = $this->di->getConfig()->get('beta_json_listing');
            if(!empty($allowedSellersNMarketplaces)){
                $allowedSellersNMarketplaces = $allowedSellersNMarketplaces->toArray();
                $userId ??= (string)$this->di->getUser()->id;
                if (in_array($userId, $allowedSellersNMarketplaces)) {
                    return true;
                }
                if (!is_null($marketplaceId) && in_array($marketplaceId, $allowedSellersNMarketplaces)) {
                    return true;
                }
            }

            return false;
        } catch(Exception) {
            return false;
        }
    }*/
    public function isBetaSeller($userId = null, $marketplaceId = null)
    {
        try {
            $userId = $userId ?? (string)$this->di->getUser()->id;

            $notAllowedSellers = $this->di->getConfig()->get('beta_json_listing_not_allowed');
            if (!empty($notAllowedSellers)) {
                $notAllowedSellers = $notAllowedSellers->toArray();
                if (in_array($userId, $notAllowedSellers)) {
                    return false;
                }
            }

            $allowedSellersNMarketplaces = $this->di->getConfig()->get('beta_json_listing');
            if (!empty($allowedSellersNMarketplaces)) {
                $allowedSellersNMarketplaces = $allowedSellersNMarketplaces->toArray();
                // $userId = $userId ?? (string)$this->di->getUser()->id;
                if (in_array($userId, $allowedSellersNMarketplaces)) {
                    return true;
                } elseif (!is_null($marketplaceId) && in_array($marketplaceId, $allowedSellersNMarketplaces)) {
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    // public function isBetaMarketplace($marketplaceId = null)
    // {
    //     try {
    //         $betaMarketplaceId = $this->di->getConfig()->get('beta_json_listing');
    //         if(!empty($betaMarketplaceId)){
    //             $betaMarketplaceId = $betaMarketplaceId->toArray();
    //             if(in_array($marketplaceId, $betaMarketplaceId)) {
    //                 return true;
    //             }
    //         }
    //         return false;
    //     } catch(\Exception $e) {
    //         print_r($e->getMessage());
    //         return false;
    //     }
    // }

    /** validate feed by json-validator */
    public function validateJsonFeed($feed, $productType)
    {
        try {
            $url = $this->di->getConfig()->get('json-validator-api-endpoint') ?? null;
            $categoryHelper = $this->di->getObjectManager()->get(CategoryAttributeCache::class);
            $marketplaceId = $feed['marketplace_id'] ?? '';
            unset($feed['marketplace_id']);
            $attributesData = $categoryHelper->getJsonAttributesFromDB($marketplaceId, $productType, true);
            if (isset($attributesData['success'], $attributesData['data']['schema'])) {
                $cacheFilePath = $this->getFeedFilePath($productType);
                $dirname = dirname((string) $cacheFilePath);
                if (!is_dir($dirname)) mkdir($dirname, 0775, true);

                $dataToWriteInFile = [
                    'feed' => $feed,
                    'schema' => base64_encode(gzcompress(serialize($attributesData['data']['schema'])))
                ];
                file_put_contents($cacheFilePath, json_encode($dataToWriteInFile));
            } else {
                return ['success' => false, 'error' => $attributesData['message'] ?? 'Error fetching attributes data.'];
            }

            if ($url && $cacheFilePath) {
                $headers = [];
                $payload = [
                    'filePath' => $cacheFilePath
                ];

                // $response = $this->di->getObjectManager()->get('App\Core\Components\Guzzle')
                //     ->call($url, $headers, $payload, 'POST', 'json');

                $response = $this->di->getObjectManager()->get('App\Amazon\Components\Validator\Validate')->execute($payload);

                // remove filePath
                unlink($cacheFilePath);
                if (isset($response['success'], $response['errors']) && !empty($response['errors'])) {
                    $validatorErrors = [];
                    foreach ($response['errors'] as $sourceProductId => $errorOnProduct) {
                        $errorArr = [];
                        foreach ($errorOnProduct as $errors) {
                            foreach ($errors as $error) {
                                $errorArr[] = ['AmazonValidationError' => $error];
                                // if(!ctype_digit($label)) {
                                //     $errorArr[] = 'AmazonValidationError : ' . $label . ' ' . $error;
                                // } else {
                                //     $errorArr[] = 'AmazonValidationError : ' . $error;
                                // }
                            }
                        }

                        if (!empty($errorArr)) {
                            $validatorErrors[$sourceProductId] = $errorArr;
                        }
                    }

                    return ['success' => true, 'errors' => $validatorErrors];
                }
                return ['success' => false, 'result' => 'feed is valid'];
            }
            return ['success' => false, 'error' => 'Required parameter(s) missing'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    public function getValidateFeed($data)
    {
        if (isset($data['target']['shopId']) && !empty($data['target']['shopId']) && isset($data['source']['shopId']) && !empty($data['source']['shopId'])) {
            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $targetShopId = (string)$data['target']['shopId'];
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
            if (!empty($targetShop['warehouses'])) {
                foreach ($targetShop['warehouses'] as $warehouse) {
                    if ($warehouse['status'] == "active") {
                        $activeAccounts = true;
                        break;
                    }
                }
            }

            if ($activeAccounts) {
                $sourceShopId = $data['source']['shopId'];
                $targetMarketplace = $data['target']['marketplace'];
                $sourceMarketplace = $data['source']['marketplace'];
                $currencyCheck = false;

                $connector = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
                $currencyCheck = $connector->checkCurrency($userId, $targetMarketplace, $sourceMarketplace, $targetShopId, $sourceShopId);
                $configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
                $configObj->setUserId($userId);
                $configObj->setTarget($targetMarketplace);
                $configObj->setTargetShopId($targetShopId);
                $configObj->setGroupCode('currency');
                $sourceCurrency = $configObj->getConfig('source_currency');
                $sourceCurrency = json_decode(json_encode($sourceCurrency, true), true);
                $amazonCurrency = $configObj->getConfig('target_currency');
                $amazonCurrency = json_decode(json_encode($amazonCurrency, true), true);
                if (!empty($sourceCurrency) && !empty($amazonCurrency)) {
                    if ($sourceCurrency[0]['value'] == $amazonCurrency[0]['value']) {
                        $currencyCheck = true;
                    } else {
                        $amazonCurrencyValue = $configObj->getConfig('target_value');
                        $amazonCurrencyValue = json_decode(json_encode($amazonCurrencyValue, true), true);
                        if (isset($amazonCurrencyValue[0]['value'])) {
                            $currencyCheck = true;
                        }
                    }
                }

                if ($currencyCheck) {

                    return $this->directValidate($data, false);
                }
                return ['success' => false, 'message' => 'Currency of' . $sourceMarketplace . 'and Amazon account are not same. Please fill currency settings from Global Price Adjustment in settings page.'];
            } else {
                return ['success' => false, 'message' => 'Target shop is not active.'];
            }
        } else {

            return ['success' => false, 'message' => 'Target shop or source shop is empty.'];
        }
    }
    public function directValidate($data)
    {
        $sourceShopId = $data['source']['shopId'];
        if (isset($data['source_product_ids'])) {
            $productProfile = $this->di->getObjectManager()->get('App\Connector\Components\Profile\GetProductProfileMerge');
            $data['user_id'] = (string) $this->di->getUser()->id;
            $data['limit'] = 2;
            $data['operationType'] = 'product_upload';
            if (!isset($data['activePage'])) {
                $data['activePage'] = 1;
            }

            $data['usePrdOpt'] = false;
            // $data['projectData'] = ['marketplace' => 0];

            $products = $productProfile->getproductsByProductIds($data, true);


            return $this->directFeed($data, $products);
        }
        return ['success' => true, 'message' => 'Validation Completed'];
    }


    public function directFeed($data, $products)
    {

        $productProfile = $this->di->getObjectManager()->get('App\Connector\Components\Profile\GetProductProfileMerge');
        $operationType = $data['operationType'];
        $uniqueKey = $products['unique_key_for_sqs'] ?? [];
        if (!empty($uniqueKey)) {
            $dataInCache = [
                'params' => [
                    'unique_key_for_sqs' => $uniqueKey
                ]
            ];
            $productProfile->clearInfoFromCache(['data' => $dataInCache]);
        }

        $message = $productProfile->getMessage($data);
        $dataToSent = json_decode(json_encode($products), true);
        $dataToSent['data']['operationType'] = $operationType;
        $dataToSent['data']['params'] = $data;
        $response = $this->jsonValidateFeed($dataToSent);
        return $response;
        // return ['success' => true, 'message' => $message, 'data' => ['return_product_direct' => true]];
    }
    public function jsonValidateFeed($data)
    {

        $feedContent = [];
        $productComponent = $this->di->getObjectManager()->get(Product::class);
        $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);

        if (isset($data['data']['params']['target']['shopId']) && !empty($data['data']['params']['target']['shopId']) && isset($data['data']['params']['source']['shopId']) && !empty($data['data']['params']['source']['shopId']) && isset($data['data']['rows']) && !empty($data['data']['rows'])) {
            $preparedContent = [];
            $userId = $data['data']['params']['user_id'];
            $targetShopId = $data['data']['params']['target']['shopId'];
            $sourceShopId = $data['data']['params']['source']['shopId'];
            $targetMarketplace = $data['data']['params']['target']['marketplace'];
            $sourceMarketplace = $data['data']['params']['source']['marketplace'];
            $products = $data['data']['rows'];
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
            if (!empty($targetShop['warehouses'])) {
                foreach ($targetShop['warehouses'] as $warehouse) {
                    if ($warehouse['status'] == "active") {
                        $activeAccounts = true;
                        break;
                    }
                }
            }
            $productTypeHelper = $this->di->getObjectManager()->get(ListingHelper::class);

            foreach ($products as $product) {
                $categorySettings = [];
                $categorySettings = $productComponent->getUploadCategorySettings($product);
                $categorySettings = json_decode(json_encode($categorySettings), true);

                if (!empty($categorySettings['errors'])) {
                    $productErrorList[$product['source_product_id']] = $categorySettings['errors'];
                } elseif (!empty($categorySettings)) {
                    if (isset($categorySettings['product_type']) && !empty($categorySettings['product_type'])) {
                        if ($product['type'] == 'variation') {
                            $type = $productComponent::PRODUCT_TYPE_PARENT;
                        } elseif ($product['visibility'] == 'Catalog and Search') {
                            $type = null;
                        } else {
                            $type = $productComponent::PRODUCT_TYPE_CHILD;
                        }

                        $additionalData['categorySettings'] = $categorySettings;
                        $additionalData['product']  = $product;
                        $additionalData['sourceMarketplace'] = $sourceMarketplace;
                        $additionalData['targetShopId'] = $targetShopId;

                        $productTypeHelper->init($additionalData);
                        $preparedContent = $productTypeHelper->setContentJsonListing($categorySettings, $product, $type, 'Update', $sourceMarketplace);

                        if (empty($preparedContent)) {
                            continue;
                        }

                        if (isset($preparedContent['error']) && !empty($preparedContent['error'])) {
                            $jsonProductErrorList[$product['source_product_id']] = [
                                'error' => $preparedContent['error'],
                                'container_id' => $product['container_id']
                            ];
                            $preparedContent = [];
                            continue;
                        }
                        $productType = $preparedContent['json_product_type'];
                        unset($preparedContent['feedToSave']);
                        // $feedContent[$productType][$product['source_product_id']]['sku'] = $product['edited']['sku'] ?? $product['sku'] ?? $product['source_product_id'];
                        // $feedContent[$productType]['json_product_type'] = $productType;
                        // $feedContent[$productType]['language_tag'] = $preparedContent['language_tag'];
                        // $feedContent[$productType]['marketplace_id'] = $preparedContent['marketplace_id'];


                        $specifics['shop_id'] = $targetShop['remote_shop_id'];

                        $specifics['sku'] = rawurlencode($preparedContent['sku']);
                        $specifics['product_type'] = $preparedContent['json_product_type'];
                        $specifics['mode'] = 'VALIDATION_PREVIEW';
                        if (isset($preparedContent['json_product_type']) && $preparedContent['json_product_type'] == "PRODUCT") {
                            $specifics['requirements'] = 'LISTING_OFFER_ONLY';
                        } else {
                            $specifics['requirements'] = 'LISTING';
                        }

                        $specifics['language'] = $preparedContent['language_tag'];
                        unset($preparedContent['sku']);
                        unset($preparedContent['json_product_type']);
                        unset($preparedContent['language_tag']);
                        unset($preparedContent['marketplace_id']);
                        $specifics['attributes'] = $preparedContent;
                        $response = $commonHelper->sendRequestToAmazon('listing-create', $specifics, 'POST');

                        if (isset($response['success']) && $response['success'] && isset($response['response'])) {
                            $feedContent[$productType][$product['source_product_id']] = $response['response'];
                        }
                    }
                }
            }
            if (!empty($feedContent)) {
                return ['success' => true, 'validation' => $feedContent];
            } else {
                return ['success' => false, 'msg' => 'Some Error Occured'];
            }
        }
    }

    public function getListingFromAmazon($data)
    {
        try {
            $targetShopId = $this->di->getRequester()->getTargetId();
            $soureShopId = $this->di->getRequester()->getSourceId();
            if (isset($data['source_product_id'])) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $userShops = $this->di->getUser()->shops ?? [];
                if (!empty($userShops)) {
                    $targetShop = array_filter($userShops, function (array $shop) use ($targetShopId) {
                        if ($shop['_id'] == $targetShopId) {
                            return $shop;
                        }
                    });
                } else {
                    $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                    $targetShop = $user_details->getShop($targetShopId, $userId);
                }

                if (is_array($targetShop)) {
                    foreach ($targetShop as $shop) {
                        if (isset($shop['remote_shop_id'])) {
                            $remoteShopId = $shop['remote_shop_id'];
                        }
                    }
                }
                $pipeline = [
                    [
                        '$match' => [
                            'user_id' => $userId,
                            'target_shop_id' => $targetShopId,
                            '$or' => [
                                ['items.source_product_id' => $data['source_product_id']],
                                ['container_id' => $data['source_product_id']]
                            ]
                        ]
                    ],
                    [
                        '$project' => [
                            'sku' => [
                                '$map' => [
                                    'input' => [
                                        '$filter' => [
                                            'input' => '$items',
                                            'as' => 'item',
                                            'cond' => [
                                                '$or' => [
                                                    ['$eq' => ['$$item.source_product_id', $data['source_product_id']]],
                                                    ['$eq' => ['$container_id', $data['source_product_id']]]
                                                ]
                                            ]
                                        ]
                                    ],
                                    'as' => 'matched',
                                    'in' => '$$matched.sku'
                                ]
                            ]
                        ]
                    ]
                ];
                $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(CommonHelper::Refine_Product);
                $productData = $collection->aggregate($pipeline);
                $productData = $productData->toArray();

                if (!empty($productData)) {

                    $sku = $productData[0]['sku'][0] ?? "";
                    if (!empty($sku)) {

                        $rawBody['sku'] = rawurlencode($sku);
                        $rawBody['shop_id'] = $remoteShopId;
                        $rawBody['includedData'] = "issues,attributes,summaries,fulfillmentAvailability";
                        $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);
                        $response = $commonHelper->sendRequestToAmazon('listing', $rawBody, 'GET');
                        return  $response;
                    }
                    return ['success' => false, 'message' => 'SKU not found In Product Data'];
                }
                return ['success' => false, 'message' => 'Product Data Not Found In DB'];
            }
            return ['success' => false, 'message' => 'Id required but not supplied'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function closeListingOnAmazon($data)
    {
        try {
            $targetShopId = $this->di->getRequester()->getTargetId();
            $soureShopId = $this->di->getRequester()->getSourceId();
            if (!empty($data['seller-sku']) && !empty($data['asin']) && !empty($data['product_id'])) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $userShops = $this->di->getUser()->shops ?? [];
                if (!empty($userShops)) {
                    $targetShop = array_filter($userShops, function (array $shop) use ($targetShopId) {
                        if ($shop['_id'] == $targetShopId) {
                            return $shop;
                        }
                    });
                } else {
                    $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                    $targetShop = $user_details->getShop($targetShopId, $userId);
                }

                if (is_array($targetShop)) {
                    foreach ($targetShop as $shop) {
                        if (isset($shop['remote_shop_id'])) {
                            $remoteShopId = $shop['remote_shop_id'];
                        }
                    }
                }
                $skus = $data['seller-sku'] ?? [];
                $result = [];
                $sucess = 0;
                $failed = 0;
                $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(CommonHelper::AMAZON_LISTING);

                foreach ($skus as $sku) {
                    $error = [];
                    if (!empty($sku)) {

                        $rawBody['sku'] = rawurlencode($sku);
                        $rawBody['shop_id'] = $remoteShopId;
                        $rawBody['includedData'] = "issues,attributes,summaries,fulfillmentAvailability";
                        $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);
                        $response = $commonHelper->sendRequestToAmazon('listing', $rawBody, 'GET');
                        if (!empty($response['success']) && $response['success']) {
                            $attributes = $response['response']['attributes'] ?? "";
                            $purchasableOffer = $attributes['purchasable_offer'] ?? "";
                            if (!empty($purchasableOffer)) {

                                foreach ($purchasableOffer as $key => $audience) {
                                    if ($audience['audience']  == "ALL") {
                                        // $purchasableOffer[$key]['start_at']['value'] = date('c');
                                        $toUpdatePrice[$key]['audience'] = $audience['audience'];
                                        $toUpdatePrice[$key]['currency'] = $audience['currency'];
                                        $toUpdatePrice[$key]['end_at']['value'] = date('c');
                                        $toUpdatePrice[$key]['marketplace_id'] = $audience['marketplace_id'];
                                        $toUpdatePrice[$key]['our_price'] = $audience['our_price'];

                                        // $purchasableOffer[$key]['end_at']['value'] = date('c');
                                    }
                                }
                                $payload['shop_id'] = $remoteShopId;
                                $payload['product_type'] = 'PRODUCT';
                                $payload['patches'] = [
                                    [
                                        "op" => "replace",
                                        "operation_type" => "PARTIAL_UPDATE",
                                        "path" => "/attributes/purchasable_offer",
                                        "value" => $toUpdatePrice
                                    ]
                                ];
                                $payload['sku'] = rawurlencode($sku);
                                $res = $commonHelper->sendRequestToAmazon('listing-update', $payload, 'POST');
                                if (isset($res['success']) && $res['success'] && isset($res['response']['status']) && $res['response']['status'] == "ACCEPTED") {
                                    $result[$sku] = $res['response'];
                                    $sucess++;
                                    $bulkOpArray[] = [
                                        'updateOne' => [
                                            [
                                                'user_id' => $userId,
                                                'shop_id' => $targetShopId,
                                                'seller-sku' => $sku
                                            ],
                                            [
                                                '$unset' => ['error' => true],
                                            ],
                                            ['upsert' => true]
                                        ]
                                    ];
                                    // return ['success' => true , 'res' => $res['response']];
                                } elseif (isset($res['success']) && $res['success'] && isset($res['response']['issues'])) {
                                    $result[$sku] = $res['response']['issues'];
                                    $error['close'] = $res['response']['issues'];
                                    $bulkOpArray[] = [
                                        'updateOne' => [
                                            [
                                                'user_id' => $userId,
                                                'shop_id' => $targetShopId,
                                                'seller-sku' => $sku
                                            ],
                                            [
                                                '$set' => ['error' => $error],
                                            ],
                                            ['upsert' => true]
                                        ]
                                    ];
                                    $failed++;
                                    // return ['success' => false , 'res' => $res['response']['issues']];
                                } else {
                                    $result[$sku] = 'Listing cannot be closed,Kindly contact support some error occured';
                                    $failed;

                                    // return ['success'=>false, 'res'=> 'Listing cannot be closed,Kindly contact support some error occured'];
                                }
                            }
                        } else {

                            $result[$sku] = 'SKU not found In Product Data';
                        }
                        // return ['success' => false, 'message' => 'SKU not found In Product Data'];
                    }
                }
                if (!empty($bulkOpArray)) {
                    $response = $collection->BulkWrite($bulkOpArray, ['w' => 1]);
                }
                if (!empty($result)) {
                    if ($sucess > 0 && $failed > 0) {
                        return ['success' => true, 'response' => $result, 'message' => $sucess . ' Listings has been successfully closed but ' . $failed . ' Listings has been failed'];
                    } else if ($sucess > 0 && $failed == 0) {
                        return ['success' => true, 'response' => $result, 'message' => $sucess . ' Listings has been successfully closed'];
                    } else if ($sucess == 0 && $failed > 0) {
                        return ['success' => false, 'response' => $result, 'message' => $failed . ' Listings has been failed Kindly re-attempt to close the listing'];
                    }
                }
            }
            return ['success' => false, 'message' => 'Id required but not supplied'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function reactivateListingOnAmazon($data)
    {
        try {
            $targetShopId = $this->di->getRequester()->getTargetId();
            $soureShopId = $this->di->getRequester()->getSourceId();
            if (!empty($data['seller-sku']) && !empty($data['asin']) && !empty($data['product_id'])) {
                $userId = $data['user_id'] ?? $this->di->getUser()->id;
                $userShops = $this->di->getUser()->shops ?? [];
                if (!empty($userShops)) {
                    $targetShop = array_filter($userShops, function (array $shop) use ($targetShopId) {
                        if ($shop['_id'] == $targetShopId) {
                            return $shop;
                        }
                    });
                } else {
                    $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                    $targetShop = $user_details->getShop($targetShopId, $userId);
                }

                if (is_array($targetShop)) {
                    foreach ($targetShop as $shop) {
                        if (isset($shop['remote_shop_id'])) {
                            $remoteShopId = $shop['remote_shop_id'];
                        }
                    }
                }
                $skus = $data['seller-sku'] ?? [];
                $result = [];
                $sucess = 0;
                $failed = 0;
                $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(CommonHelper::AMAZON_LISTING);

                foreach ($skus as $sku) {
                    $error = [];

                    if (!empty($sku)) {

                        $rawBody['sku'] = rawurlencode($sku);
                        $rawBody['shop_id'] = $remoteShopId;
                        $rawBody['includedData'] = "issues,attributes,summaries,fulfillmentAvailability";
                        $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);
                        $response = $commonHelper->sendRequestToAmazon('listing', $rawBody, 'GET');
                        if (!empty($response['success']) && $response['success']) {
                            $attributes = $response['response']['attributes'] ?? "";
                            $purchasableOffer = $attributes['purchasable_offer'] ?? "";
                            if (!empty($purchasableOffer)) {

                                foreach ($purchasableOffer as $key => $audience) {
                                    if ($audience['audience']  == "ALL") {
                                        // $purchasableOffer[$key]['start_at']['value'] = date('c');
                                        $toUpdatePrice[$key]['audience'] = $audience['audience'];
                                        $toUpdatePrice[$key]['currency'] = $audience['currency'];
                                        $currentTimeStamp = date('c');
                                        $toUpdatePrice[$key]['start_at']['value'] = $currentTimeStamp;

                                        $toUpdatePrice[$key]['end_at']['value'] =  date('c', strtotime('+365 days'));
                                        $toUpdatePrice[$key]['marketplace_id'] = $audience['marketplace_id'];
                                        $toUpdatePrice[$key]['our_price'] = $audience['our_price'];

                                        // $purchasableOffer[$key]['end_at']['value'] = date('c');
                                    }
                                }
                                // print_r($toUpdatePrice);die;
                                $payload['shop_id'] = $remoteShopId;
                                $payload['product_type'] = 'PRODUCT';
                                $payload['patches'] = [
                                    [
                                        "op" => "replace",
                                        "operation_type" => "PARTIAL_UPDATE",
                                        "path" => "/attributes/purchasable_offer",
                                        "value" => $toUpdatePrice
                                    ]
                                ];
                                $payload['sku'] = rawurlencode($sku);
                                $res = $commonHelper->sendRequestToAmazon('listing-update', $payload, 'POST');
                                if (isset($res['success']) && $res['success'] && isset($res['response']['status']) && $res['response']['status'] == "ACCEPTED") {
                                    $result[$sku] = $res['response'];
                                    $sucess++;
                                    $bulkOpArray[] = [
                                        'updateOne' => [
                                            [
                                                'user_id' => $userId,
                                                'shop_id' => $targetShopId,
                                                'seller-sku' => $sku
                                            ],
                                            [
                                                '$unset' => ['error' => true],
                                            ],
                                            ['upsert' => true]
                                        ]
                                    ];
                                    // return ['success' => true , 'res' => $res['response']];
                                } elseif (isset($res['success']) && $res['success'] && isset($res['response']['issues'])) {
                                    $result[$sku] = $res['response']['issues'];
                                    $error['reactivate'] = $res['response']['issues'];
                                    $bulkOpArray[] = [
                                        'updateOne' => [
                                            [
                                                'user_id' => $userId,
                                                'shop_id' => $targetShopId,
                                                'seller-sku' => $sku
                                            ],
                                            [
                                                '$set' => ['error' => $error],
                                            ],
                                            ['upsert' => true]
                                        ]
                                    ];
                                    $failed++;
                                    // return ['success' => false , 'res' => $res['response']['issues']];
                                } else {
                                    $result[$sku] = 'Listing cannot be reactivated,Kindly contact support some error occured';
                                    $failed;

                                    // return ['success'=>false, 'res'=> 'Listing cannot be closed,Kindly contact support some error occured'];
                                }
                            }
                        } else {

                            $result[$sku] = 'SKU not found In Product Data';
                        }
                    }
                }
                if (!empty($bulkOpArray)) {
                    $response = $collection->BulkWrite($bulkOpArray, ['w' => 1]);
                }
                if (!empty($result)) {
                    if ($sucess > 0 && $failed > 0) {
                        return ['success' => true, 'response' => $result, 'message' => $sucess . ' Listings has been successfully reactivated but ' . $failed . ' Listings has been failed'];
                    } else if ($sucess > 0 && $failed == 0) {
                        return ['success' => true, 'response' => $result, 'message' => $sucess . ' Listings has been successfully reactivated'];
                    } else if ($sucess == 0 && $failed > 0) {
                        return ['success' => false, 'response' => $result, 'message' => $failed . ' Listings has been failed Kindly re-attempt to reactivate the listing'];
                    }
                }
            }
            return ['success' => false, 'message' => 'Id required but not supplied'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** set error in product received from json-validator */
    public function setErrorInProduct(&$errors, &$product)
    {
        try {
            if (is_array($errors)) {
                $setErrors = [];
                foreach ($errors as $name => $error) {
                    $name = explode('/', $name);
                    if (isset($name[1]) && !in_array($name[1], ['value'])) {
                        $name = $name[1];
                    }

                    if (is_array($error) && !empty($error)) {
                        foreach ($error as $err) {
                            $setErrors[] = [
                                'type' => 'product',
                                'code' => $name,
                                'message' => $name . ' : ' . $err
                            ];
                        }
                    } elseif (is_string($error) && $error != '') {
                        $setErrors[] = [
                            'type' => 'product',
                            'code' => $name,
                            'message' => $name . ' ' . $error
                        ];
                    }
                }

                if (!empty($setErrors)) {
                    return [
                        'user_id' => (string)$product['user_id'],
                        'source_shop_id' => (string)$product['shop_id'],
                        'container_id' => (string) $product['container_id'],
                        'source_product_id' => (string) $product['source_product_id'],
                        'childInfo' => [
                            'source_product_id' => (string) $product['source_product_id'],
                            'shop_id' => (string)$product['edited']['shop_id'],
                            'error' => $setErrors,
                            'target_marketplace' => 'amazon',
                        ],
                    ];
                }
            }

            return [];
        } catch (Exception $e) {
            print_r($e->getMessage());
            return [];
        }
    }

    // file path for feed+schema for json-validator
    public function getFeedFilePath($productType)
    {
        return BP . DS . 'var' . DS . 'amazon-cache' . DS . $this->di->getUser()->id . DS . $this->di->getRequester()->getTargetId() . DS . $productType . DS . microtime(true) . '.txt';
    }

    /** get all available productTypes and store in db by marketplaceId || get new product-type added notification */
    public function searchProductTypes($data)
    {
        try {
            if (
                isset($data['NotificationType'], $data['Payload']['NewProductTypes']) &&
                $data['NotificationType'] == 'PRODUCT_TYPE_DEFINITIONS_CHANGE'
            ) {
                if (!empty($data['Payload']['NewProductTypes'])) {
                    $newProductTypes = $data['Payload']['NewProductTypes'];
                    $marketplaceId = $data['Payload']['MarketplaceId'] ?? null;
                    if ($marketplaceId) {
                        $params = [
                            'new_product_types' => $newProductTypes,
                            'marketplace_id' => $marketplaceId
                        ];
                        $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                        return $commonHelper->sendRequestToAmazon('product-types', $params, 'GET');
                    }
                }

                return ['success' => false, 'msg' => '', 'input' => $data];
            }
            if (isset($data['remote_shop_id'])) {
                $params = [
                    'shop_id' => $data['remote_shop_id'],
                    'keywords' => $data['keywords'] ?? null
                ];
                $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                return $commonHelper->sendRequestToAmazon('product-types', $params, 'GET');
            } else {
                return ['success' => false, 'message' => 'Required param(s): not found', 'params' => $data];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
        }
    }

    public function getTargetShop($userId, $targetShopId)
    {
        // $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        // return $userDetails->getShop($targetShopId, $userId);

        return $this->getUserShop($userId, $targetShopId);
    }

    public function getUserShop($userId, $shopId)
    {
        if ($this->di->getUser()->id === $userId && !empty($this->di->getUser()->shops)) {
            $userDetails = json_decode(json_encode($this->di->getUser()), true);
        } else {
            $userDetails = User::findFirst([['_id' => $userId]]);
            $userDetails = json_decode(json_encode($userDetails), true);
        }

        $shopData = [];
        $shops = $userDetails['shops'];
        foreach ($shops as $shop) {
            if ($shop['_id'] == $shopId) {
                $shopData = $shop;
                break;
            }
        }

        return $shopData;
    }

    public function ifOfferRestricted($data)
    {
        try {
            if (isset($data['asin'], $data['condition_type'], $data['user_id'], $data['shop_id'])) {
                $targetShop = $this->getTargetShop($data['user_id'], $data['shop_id']);
                $remoteShopId = $targetShop['remote_shop_id'] ?? null;
                if ($remoteShopId) {
                    $condition = $data['condition_type'];
                    $params = [
                        'asin' => $data['asin'],
                        'condition_type' => $condition,
                        'shop_id' => $remoteShopId
                    ];
                    $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                    $result = $commonHelper->sendRequestToAmazon('restrctions', $params, 'GET');
                    if (isset($result['success'], $result['response']['restrictions']) && !empty($result['response']['restrictions'])) {
                        $restrictionsArray = $result['response']['restrictions'];
                        $restrictionMessage = $this->di->getObjectManager()->get(Locale::class);
                        foreach ($restrictionsArray as $restrctions) {
                            if (isset($restrctions['conditionType']) && $restrctions['conditionType'] == $condition) {
                                $errors = [];
                                if (isset($restrctions['reasons']) && !empty($restrctions['reasons'])) {
                                    foreach ($restrctions['reasons'] as $reasons) {
                                        $error = 'AmazonError112 : ' . $reasons['message'] ?? $restrictionMessage::OFFER_RESTRCTION_MESSAGE;
                                        if (isset($reasons['links'][0]['resource'])) {
                                            $error = $error . ' ' . ($reasons['links'][0]['title'] ?? '') . ' ' . $reasons['links'][0]['resource'];
                                        }

                                        $errors[] = $error;
                                    }
                                } else {
                                    $errors[] = 'AmazonError112';
                                }

                                // saveErrorInProduct
                                if (!empty($errors)) {
                                    return ['error' => $errors, 'jsonListing' => true];
                                }
                            }
                        }
                    }
                }
            }

            return [];
        } catch (Exception $e) {
            print_r($e->getMessage());
            return [];
        }
    }

    public function getSourceShopId($userId, $targetShopId, $targetShop = null)
    {
        if (!$targetShop) {
            $targetShop = $this->getTargetShop($userId, $targetShopId);
        }

        if (!empty($targetShop) && isset($targetShop['sources']) && is_array($targetShop['sources'])) {
            foreach ($targetShop['sources'] as $source) {
                if (isset($source['shop_id'])) {
                    return $source['shop_id'];
                }
            }
        }

        return false;
    }

    public function queryProduct($userId, $sourceProductId = null, $sourceShopId = false, $targetShopId = false, $projection = [], $sku = false, $unitTest = false)
    {
        $query = false;
        if ($sourceProductId || $sku || $unitTest) {
            if ($targetShopId) {
                $query = ['user_id' => (string)$userId, 'shop_id' => (string)$targetShopId];
            } elseif ($sourceShopId) {
                $query = ['user_id' => (string)$userId, 'shop_id' => (string)$sourceShopId];
            }

            if ($query) {
                if ($sourceProductId) {
                    $query['source_product_id'] = (string)$sourceProductId;
                }

                if ($sku) {
                    $query['sku'] = $sku;
                }

                $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
                if (!empty($projection)) {
                    $options['projection'] = $projection;
                }

                $productCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollection(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);
                return $productCollection->findOne($query, $options);
            }
        }

        return [];
    }

    public function getConfigObject($groupCode, $userId = null, $targetShopId = null)
    {
        $configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
        if ($userId !== null) {
            $configObj->setUserId($userId);
            if ($targetShopId !== null) {
                $configObj->setTargetShopId($targetShopId);
            }
        } else {
            $configObj->setUserId('all');
        }

        $appTag = $this->di->getAppCode()->getAppTag();
        $configObj->setAppTag($appTag);
        $configObj->setGroupCode($groupCode);

        $config = $configObj->getConfig();
        if (!empty($config)) {
            $config = json_decode(json_encode($config, true), true);
        }

        return $config;
    }

    public function handleProductTypeDefinitionsChangeNotifications($data)
    {
        try {
            $productTypeSchemaCollection = $this->di
                ->getObjectManager()->get('\App\Core\Models\BaseMongo')
                ->getCollection('product_type_definitions_schema');

            $filterQuery = [
                'user_id' => $data['user_id'],
                'shop_id' => $data['shop_id'],
                'product_type_version.latest' => true
            ];
            $filterUpdate = [
                '$set' => ['product_type_version.latest' => false]
            ];

            $dbResp = $productTypeSchemaCollection->updateMany($filterQuery, $filterUpdate);
            return ['success' => true, 'data' => $dbResp];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function handleProductTypeNotifications($data)
    {
        try {
            if (!isset($data['data']['NotificationType']) || $data['data']['NotificationType'] != 'PRODUCT_TYPE_DEFINITIONS_CHANGE') {
                return;
            }

            if (!empty($data['data']['Payload']['NewProductTypes'])) {
                return;
            }

            $marketplaceId = $data['data']['Payload']['marketplaceId'];
            //log data to confirm if marketplaceId is coming
            $productTypeSchemaCollection = $this->di
                ->getObjectManager()->get('\App\Core\Models\BaseMongo')
                ->getCollection('product_type_definitions_schema');

            $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];

            $allProductTypeDocs = $productTypeSchemaCollection->find(
                [
                    'marketplace_id' => ['$exists' => true],
                    'product_type_version.version' => $this->di->getConfig()->product_type_definitions_schema_version
                ],
                $options
            )->toArray();

            $allShops = $this->di->getUser()->shops ?? [];
            $remoteShopId = null;
            foreach ($allShops as $shop) {
                if ($shop['_id'] == $data['shop_id']) {
                    $remoteShopId = $shop['remote_shop_id'];
                }
            }

            foreach ($allProductTypeDocs as $productType) {
                $preparedQueueData =  [
                    'type' => 'full_class',
                    'class_name' => \App\Amazon\Components\Listings\Helper::class,
                    'method' => 'updateSchemaForProductTypeDefinition',
                    'user_id' => $data['user_id'],
                    'shop_id' => $data['shop_id'],
                    'remote_shop_id' => $remoteShopId,
                    'queue_name' => 'product_type_definitions_schema_update',
                    'data' => [
                        'product_type' => $productType['product_type'],
                        'marketplace_id' => $marketplaceId,
                        'product_type_definitions_schema_version' => $data['data']['Payload']['ProductTypeVersion'],
                        'seller_id' => $data['data']['Payload']['ProductTypeVersion'],
                    ],
                ];

                $this->pushMessageInQueue($preparedQueueData);
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
        }
    }

    public function pushMessageInQueue($message)
    {
        $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
        try {
            $response = $sqsHelper->pushMessage($message);
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'data' => $response];
    }

    public function updateSchemaForProductTypeDefinition($data): void
    {
        $productTypeSchemaCollection = $this->di
            ->getObjectManager()->get('\App\Core\Models\BaseMongo')
            ->getCollection('product_type_definitions_schema');
        $productTypeData = $productTypeSchemaCollection->fineOneAndUpdate(
            [
                'marketplace_id' => $data['data']['marketplace_id'],
                'product_type_version.version' => $this->di->getConfig()->product_type_definitions_schema_version,
                'product_type' => $data['data']['product_type'],
            ],
            [
                '$set' => [
                    'product_type_version.latest' => false,
                    'updated_at' => date('c'),
                ]
            ]
        );
        $params = [
            'shop_id' => $data['data']['remote_shop_id'],
            'product_type' => $data['data']['product_type'],
            'requirements' => $productTypeData['requirements'] ?? "LISTINGS",
            'product_type_version' => $data['product_type_definitions_schema_version'] ?? null,
        ];
        $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
        $remoteResponse = $commonHelper->sendRequestToAmazon('fetch-schema', $params, 'GET');
        if (empty($remoteResponse['success']) || empty($remoteResponse['data'])) {
            return;
        }

        $remoteData =  $remoteResponse['data'];
        $updatedCheckSum = $remoteData['schema']['checksum'];

        $attributeCacheObj = $this->di
            ->getObjectManager()
            ->get(CategoryAttributeCache::class);

        $schemaContentResponse = $attributeCacheObj->getSchemaContent($remoteData['schema']['link']['resource']);
        if (!$schemaContentResponse['success']) {
            return;
        }

        $schemaContent = $schemaContentResponse['data'];

        $additionalData = [
            'marketplace_id' => $data['data']['marketplace_id'],
            'is_schema_updated' => false,
        ];
        if ($updatedCheckSum != $productTypeData['schema_checksum']) {
            //notify or add entry somewhere
            //add key in - is_schema_updated - t/f
            $additionalData['is_schema_updated'] = true;
        }

        $attributeCacheObj->saveAttributesInDB($remoteData, $additionalData, $schemaContent);
    }
}
