<?php

namespace App\Amazon\Components\Template;

use App\Amazon\Components\Listings\CategoryAttributes;
use Exception;
use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base;

class Category extends Base
{
    private ?string $_user_id = null;

    private $_user_details;

    private $_baseMongo;

    public function init($request = [])
    {
        if (isset($request['user_id'])) {
            $this->_user_id = (string) $request['user_id'];
        } else {
            $this->_user_id = (string) $this->di->getUser()->id;
        }

        $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');

        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        return $this;
    }

    public function getAmazonCategory($data)
    {
        if (isset($data['shop_id'])) {
            $homeShopId = $data['shop_id'];

            $remoteShop = $this->_user_details->getShop($homeShopId, $this->_user_id);
            if ($remoteShop && !empty($remoteShop)) {
                $remoteShopId = $remoteShop['remote_shop_id'];
                $params = [
                    'shop_id' => $remoteShopId,
                    'home_shop_id' => $homeShopId,
                ];
                $commonHelper = $this->di->getObjectManager()
                    ->get(Helper::class);

                $response = $commonHelper->sendRequestToAmazon('category', $params, 'GET');

                if (isset($response['success'], $response['response']) && $response['success']) {
                    return ['success' => true, "data" => $response['response']];
                }
                return ['success' => false, "message" => $response['msg']];
            }

            return ['success' => false, "message" => 'remote shop not found'];
        }

        return ['success' => false, "message" => 'home shop not found'];
    }

    // public function getCategory($data)
    // {
    //     // die(json_encode($data));
    //     if (isset($data['shop_id'])) {
    //         $homeShopId = $data['shop_id'];
    //         $remoteShop = $this->_user_details->getShop($homeShopId, $this->_user_id);
    //         if ($remoteShop && !empty($remoteShop)) {
    //             $remoteShopId = $remoteShop['remote_shop_id'];
    //             $selected = '';
    //             $params = [
    //                 'shop_id' => $remoteShopId,
    //                 'home_shop_id' => $homeShopId,
    //                 // 'selected' => $selected,
    //                 'hasChildren' => false,
    //             ];
    //             if (isset($data['selected'])) {
    //                 $params['selected'] = $data['selected'];
    //             }
    //             $commonHelper = $this->di->getObjectManager()
    //                 ->get(Helper::class);

    //             // $response = $commonHelper->sendRequestToAmazon($selected ? 'sub-category-new' : 'category-new', $params, 'GET');
    //             $response = $commonHelper->sendRequestToAmazon('category-all', $params, 'GET');
    //             if (isset($response['success'], $response['response']) && $response['success']) {
    //                 if (isset($data['isTemplate']) && $data['isTemplate'] && $data['selected'] == []) {
    //                     $default = ['browseNodeId' => 'default', 'name' => 'Default', 'hasChildren' => false, 'product_type' => ['PRODUCT'], 'category' => ['sub-category' => 'default', 'primary-category' => 'default']];
    //                     array_unshift($response['response'], $default);
    //                 }
    //                 return ['success' => true, "data" => $response['response']];
    //             } else {
    //                 return ['success' => false, "message" => $response['msg']];
    //             }
    //         }
    //         return ['success' => false, "message" => 'remote shop not found'];
    //     }
    //     return ['success' => false, "message" => 'home shop not found'];
    // }
    public function getCategory($data)
    {
        // die(json_encode($data));
        if (isset($data['shop_id'])) {
            $homeShopId = $data['shop_id'];
            $remoteShop = $this->_user_details->getShop($homeShopId, $this->_user_id);
            if ($remoteShop && !empty($remoteShop)) {
                $remoteShopId = $remoteShop['remote_shop_id'];
                $marketplaceId = $remoteShop['warehouses'][0]['marketplace_id'] ?? false;
                $selected = '';
                $params = [
                    'shop_id' => $remoteShopId,
                    'home_shop_id' => $homeShopId,
                    // 'selected' => $selected,
                    'hasChildren' => false,
                ];
                if (isset($data['selected'])) {
                    $params['selected'] = $data['selected'];
                }

                $listingHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Listings\Helper::class);

                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                // $response = $commonHelper->sendRequestToAmazon($selected ? 'sub-category-new' : 'category-new', $params, 'GET');
                $response = $commonHelper->sendRequestToAmazon('category-all', $params, 'GET');
                if (isset($response['success'], $response['response']) && $response['success']) {
                    if (isset($response['response']) && is_array($response['response'])) {
                        foreach ($response['response'] as $key =>  $res) {
                            if ($marketplaceId == "AE08WJ6YKNBMC" || $marketplaceId == "A28R8C7NBKEWEA" || !isset($res['category']['primary-category']) && isset($res['hasChildren']) && !$res['hasChildren']) {
                                // if ($listingHelper->isBetaSeller(null, $marketplaceId) && isset($res['hasChildren']) && !$res['hasChildren']) {
                                    // $response['response'][$key]['category'] = ['sub-category' => 'dummy', 'primary-category' => 'dummy'];
                                    $response['response'][$key]['category'] = ['sub-category' => "sub-dummy-{$key}", 'primary-category' => "primary-dummy-{$key}"];
                                }
                        }
                    }

                    if (isset($data['isTemplate']) && $data['isTemplate'] && $data['selected'] == []) {
                        $default = ['browseNodeId' => '0', 'name' => 'Default', 'hasChildren' => false, 'product_type' => ['PRODUCT'], 'category' => ['sub-category' => 'default', 'primary-category' => 'default']];
                        array_unshift($response['response'], $default);
                    }

                    return ['success' => true, "data" => $response['response']];
                }
                return ['success' => false, "message" => $response['msg']];
            }

            return ['success' => false, "message" => 'remote shop not found'];
        }

        return ['success' => false, "message" => 'home shop not found'];
    }

    // public function categorySearch($data)
    // {
    //     // die(json_encode($data));
    //     if (isset($data['shop_id'])) {
    //         $homeShopId = $data['shop_id'];
    //         $remoteShop = $this->_user_details->getShop($homeShopId, $this->_user_id);
    //         if ($remoteShop && !empty($remoteShop)) {
    //             $remoteShopId = $remoteShop['remote_shop_id'];
    //             $selected = '';
    //             if (isset($data['selected'])) {
    //                 $selected = $data['selected'];
    //             }
    //             $params = [
    //                 'shop_id' => $remoteShopId,
    //                 'home_shop_id' => $homeShopId,
    //                 'selected' => $selected,
    //                 'hasChildren' => false,
    //             ];
    //             $commonHelper = $this->di->getObjectManager()
    //                 ->get(Helper::class);

    //             // $response = $commonHelper->sendRequestToAmazon($selected ? 'sub-category-new' : 'category-new', $params, 'GET');
    //             $response = $commonHelper->sendRequestToAmazon('category-search', $params, 'GET');

    //             //                $response = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
    //             //                ->init('amazon', false, 'default')
    //             //                ->call($selected ? 'sub-category-new':'category-new', [], ['shop_id'=>$remoteShop['remote_shop_id'],'selected'=>$selected], 'GET');

    //             if (isset($response['success'], $response['response']) && $response['success']) {
    //                 if (preg_match("/\b$selected/i", "Default") == 1 ) {
    //                     $default = ['browseNodeId' => 'default','hasChildren'=>false, 'product_type' => ['PRODUCT'], 'category' => ['sub-category' => 'default', 'primary-category' => 'default'],'full_path' =>['Default'],'marketplace' => "Amazon",'name'=>"default"];
    //                     array_unshift($response['response'], $default);
    //                 }
    //                 return ['success' => true, "data" => $response['response']];
    //             } else {
    //                 return ['success' => false, "message" => $response['msg']];
    //             }
    //         }
    //         return ['success' => false, "message" => 'remote shop not found'];
    //     }
    //     return ['success' => false, "message" => 'home shop not found'];
    // }

    public function categorySearch($data)
    {
        if (isset($data['shop_id'])) {
            $homeShopId = $data['shop_id'];
            $remoteShop = $this->_user_details->getShop($homeShopId, $this->_user_id);
            if ($remoteShop && !empty($remoteShop)) {
                $remoteShopId = $remoteShop['remote_shop_id'];
                $marketplaceId = $remoteShop['warehouses'][0]['marketplace_id'] ?? false;
                $selected = '';
                if (isset($data['selected'])) {
                    $selected = $data['selected'];
                }

                $limit = $data['limit'] ?? 50;

                $params = [
                    'shop_id' => $remoteShopId,
                    'home_shop_id' => $homeShopId,
                    'selected' => $selected,
                    'hasChildren' => false,
                    'limit' => $limit
                ];
                $commonHelper = $this->di->getObjectManager()
                    ->get(Helper::class);

                $response = $commonHelper->sendRequestToAmazon('category-search', $params, 'GET');

                if (isset($response['success'], $response['response']) && $response['success']) {
                    if (isset($response['response']) && is_array($response['response'])) {
                        foreach ($response['response'] as $key =>  $res) {
                            if ($marketplaceId == "AE08WJ6YKNBMC" || $marketplaceId == "A28R8C7NBKEWEA" || !isset($res['category']['primary-category']) && isset($res['hasChildren']) && !$res['hasChildren']) {
                                // if ($listingHelper->isBetaSeller(null, $marketplaceId) && isset($res['hasChildren']) && !$res['hasChildren']) {
                                    // $response['response'][$key]['category'] = ['sub-category' => 'dummy', 'primary-category' => 'dummy'];
                                    $response['response'][$key]['category'] = ['sub-category' => "sub-dummy-{$key}", 'primary-category' => "primary-dummy-{$key}"];
                                }
                        }
                    }

                    if (preg_match("/\b{$selected}/i", "Default") == 1 ) {
                        $default = ['browseNodeId' => '0','hasChildren'=>false, 'product_type' => ['PRODUCT'], 'category' => ['sub-category' => 'default', 'primary-category' => 'default'],'full_path' =>['Default'],'marketplace' => "Amazon",'name'=>"default"];
                        array_unshift($response['response'], $default);
                    }

                    return ['success' => true, "data" => $response['response']];
                }
                return ['success' => false, "message" => $response['msg']];
            }

            return ['success' => false, "message" => 'remote shop not found'];
        }

        return ['success' => false, "message" => 'home shop not found'];
    }

    // public function getAllProductTypes($data) {
    //     try {
    //         if (isset($data['target']['shopId'])) {
    //             $homeShopId = $data['target']['shopId'];
    //             $userId = isset($data['user_id']) ? $data['user_id'] : (string)$this->di->getUser()->id;
    //             $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
    //             $remoteShop = $userDetails->getShop($homeShopId, $userId);

    //             if ($remoteShop && !empty($remoteShop)) {
    //                 $remoteShopId = $remoteShop['remote_shop_id'];
    //                 $betaSellersId = $this->di->getConfig()->get('beta_json_listing');
    //                 if(!empty($betaSellersId)){
    //                     $betaSellersId = $betaSellersId->toArray();
    //                 }
    //                 if(!empty($betaSellersId) && in_array($userId, $betaSellersId)) {
    //                     /** get json-attributes if product-type received in payload */
    //                     $params = [
    //                         'shop_id' => $remoteShopId,
    //                     ];
    //                     if (isset($data['keywords'])) {
    //                         $params['keywords'] = $data['keywords'];
    //                     }
    //                     $commonHelper = $this->di->getObjectManager()->get('App\Amazon\Components\Common\Helper');
    //                     $result = $commonHelper->sendRequestToAmazon('fetch-product-types', $params, 'GET');
    //                     if(isset($result['success'], $result['data']) && !empty($result['data'])) {
    //                         return ['success' => true, 'data' => array_column($result['data'], 'name')];
    //                     } else {
    //                         return ['success' => false, 'message' => 'Error fetching product_types'];
    //                     }
    //                 } else {
    //                     return ['success' => false, 'message' => 'User is not present in Beta seller.'];
    //                 }
    //             } else {
    //                 return ['success' => false, 'message' => 'Required Params Missing.'];
    //             }
    //         } else {
    //             return ['success' => false, 'message' => 'Required Params Missing'];
    //         }
    //     } catch(\Exception $e) {
    //         return ['success' => false, 'message' => 'Something went wrong'];
    //     }

    // }
    public function getAllProductTypes($data) {
        try {
            if (isset($data['target']['shopId'])) {
                $homeShopId = $data['target']['shopId'];
                $userId = $data['user_id'] ?? (string)$this->di->getUser()->id;
                $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $remoteShop = $userDetails->getShop($homeShopId, $userId);

                $listingHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Listings\Helper::class);
                $targetShop = $listingHelper->getTargetShop($userId, $homeShopId);
                $marketplaceId = $targetShop['warehouses'][0]['marketplace_id'] ?? '';

                if ($remoteShop && !empty($remoteShop)) {
                    $remoteShopId = $remoteShop['remote_shop_id'];
                    if ($listingHelper->isBetaSeller($userId, $marketplaceId)) {
                        /** get json-attributes if product-type received in payload */
                        $params = [
                            'shop_id' => $remoteShopId,
                        ];
                        if (isset($data['keywords'])) {
                            $params['keywords'] = $data['keywords'];
                        }

                        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                        $result = $commonHelper->sendRequestToAmazon('fetch-product-types', $params, 'GET');
                        if(isset($result['success'], $result['data']) && !empty($result['data'])) {
                            return ['success' => true, 'data' => array_column($result['data'], 'name')];
                        }
                        return ['success' => false, 'message' => 'Error fetching product_types'];
                    }
                    return ['success' => false, 'message' => 'User is not present in Beta seller.'];
                }
                return ['success' => false, 'message' => 'Required Params Missing.'];
            }
            return ['success' => false, 'message' => 'Required Params Missing'];
        } catch(Exception) {
            return ['success' => false, 'message' => 'Something went wrong'];
        }

    }

    // public function getAmazonAttributes($data, $all = false)
    // {
    //     if (isset($data['target']['shopId'], $data['data']['category'], $data['data']['sub_category'], $data['data']['browser_node_id'], $data['data']['barcode_exemption'])) {
    //         $cacheAttributes = false;
    //         $categoryId = $data['data']['category'];
    //         $subCategoryId = $data['data']['sub_category'];
    //         $homeShopId = $data['target']['shopId'];
    //         $browseNodeId = $data['data']['browser_node_id'];
    //         $barcode = $data['data']['barcode_exemption'];
    //         $remoteShop = $this->_user_details->getShop($homeShopId, $this->_user_id);
    //         if ($remoteShop && !empty($remoteShop)) {
    //             $remoteShopId = $remoteShop['remote_shop_id'];
    //             $marketplaceId = $remoteShop['warehouses'][0]['marketplace_id'] ?? false;
    //             if(isset($data['data']['product_type']) && !empty($data['data']['product_type'])) {
    //                 $userId = $data['user_id'] ?? (string)$this->di->getUser()->getId();
    //                 $jsonAttributeHelper = $this->di->getObjectManager()->get('App\Amazon\Components\Listings\CategoryAttributes');
    //                 $payload = [
    //                     'product_type' => $data['data']['product_type'],
    //                     'remote_shop_id' => $remoteShopId,
    //                     'marketplace_id' => $marketplaceId,
    //                     'category' => $categoryId,
    //                     'user_id' => $userId
    //                 ];
    //                 $result = $jsonAttributeHelper->getJsonCategoryAttributes($payload);
    //                 if(isset($result['success'], $result['data']) && !empty($result['data'])) {
    //                     return $result;
    //                 }
    //             }
    //             $params = [
    //                 'category_id' => $categoryId,
    //                 'sub_category_id' => $subCategoryId,
    //                 'barcode' => $barcode,
    //                 'shop_id' => $remoteShopId,
    //                 'home_shop_id' => $homeShopId,
    //                 'browse_node_id' => $browseNodeId,
    //             ];

    //             try {
    //                 $attributeCacheObj = $this->di->getObjectManager()->create('App\Amazon\Components\Template\CategoryAttributeCache');
    //                 $cacheAttributes = $attributeCacheObj->getAttributesFromCache($marketplaceId, $categoryId, $subCategoryId);
    //             }
    //             catch(\Exception $e) {
    //                 $cacheAttributes = false;
    //             }

    //             if($cacheAttributes !== false) {
    //                 $response = $cacheAttributes;
    //             } else {
    //                 $commonHelper = $this->di->getObjectManager()->get(Helper::class);
    //                 $response = $commonHelper->sendRequestToAmazon('category-attribute', $params, 'GET');
    //                 $attributeCacheObj->saveAttributesInCache($marketplaceId, $categoryId, $subCategoryId, $response);
    //             }

    //             if (isset($response['success'], $response['response']) && $response['success'])
    //             {
    //                 $validValues = [];
    //                 $variation_theme = [];
    //                 if (isset($response['valid_values'])) {
    //                     $validValues = $response['valid_values'];
    //                 }

    //                 if (isset($response['variation_theme'])) {
    //                     $variation_theme = $response['variation_theme'];
    //                 }

    //                 foreach ($response['response'] as $attributeGroupKey => $attributeGroups) {
    //                     if ($attributeGroupKey == 'Images' && !$all) {
    //                         unset($response['response'][$attributeGroupKey]);
    //                         continue;
    //                     }
    //                     foreach ($attributeGroups as $attributeKey => $attribute) {
    //                         if (!$all && isset($response['browse_node_attribute'][$attributeKey]) && !empty($response['browse_node_attribute'][$attributeKey])) {
    //                             unset($response['response'][$attributeGroupKey][$attributeKey]);
    //                             continue;
    //                         } else if (isset($response['browse_node_attribute'][$attributeKey]) && !empty($response['browse_node_attribute'][$attributeKey])) {
    //                             $response['response'][$attributeGroupKey][$attributeKey]['premapped_values'] = $response['browse_node_attribute'][$attributeKey];
    //                         }

    //                         if (isset($attribute['productTypeSpecific'][$subCategoryId])) {
    //                             $productTypeSpecific = $attribute['productTypeSpecific'][$subCategoryId];

    //                             if (!empty($validValues)) {
    //                                 if (isset($validValues[$attribute['label']][$subCategoryId]) && !empty($validValues[$attribute['label']][$subCategoryId])) {
    //                                     $response['response'][$attributeGroupKey][$attributeKey]['amazon_recommendation'] = $validValues[$attribute['label']][$subCategoryId];
    //                                 } elseif (isset($validValues[$attribute['label']]['all_cat']) && !empty($validValues[$attribute['label']]['all_cat'])) {
    //                                     $response['response'][$attributeGroupKey][$attributeKey]['amazon_recommendation'] = $validValues[$attribute['label']]['all_cat'];
    //                                 } elseif (isset($validValues[$attributeKey][$subCategoryId]) && !empty($validValues[$attributeKey][$subCategoryId])) {
    //                                     $response['response'][$attributeGroupKey][$attributeKey]['amazon_recommendation'] = $validValues[$attributeKey][$subCategoryId];
    //                                 } elseif (isset($validValues[$attributeKey]['all_cat']) && !empty($validValues[$attributeKey]['all_cat'])) {
    //                                     $response['response'][$attributeGroupKey][$attributeKey]['amazon_recommendation'] = $validValues[$attributeKey]['all_cat'];
    //                                 }
    //                             } elseif (isset($productTypeSpecific['accepted_values'])) {
    //                                 $response['response'][$attributeGroupKey][$attributeKey]['amazon_recommendation'] = $productTypeSpecific['accepted_values'];
    //                             }

    //                             if (!empty($variation_theme) && $attributeKey == 'variation_theme') {
    //                                 if(isset($variation_theme['variation_theme']))
    //                                 {
    //                                 $response['response'][$attributeGroupKey][$attributeKey]['required_variant_attributes'] = $variation_theme['variation_theme'][$subCategoryId];
    //                                 }
    //                                 elseif(isset($variation_theme['variation theme']))
    //                                 {
    //                                     $response['response'][$attributeGroupKey][$attributeKey]['required_variant_attributes'] = $variation_theme['variation theme'][$subCategoryId];
    //                                 }
    //                             }

    //                             if (isset($productTypeSpecific['condition']) && $attributeGroupKey == 'Mandantory' && $productTypeSpecific['condition'] == 'optional') {
    //                                 if(isset($response['response'][$attributeGroupKey][$attributeKey]))
    //                                 {
    //                                     $response['response']['Optional'][$attributeKey] = $response['response'][$attributeGroupKey][$attributeKey];
    //                                     $response['response']['Optional'][$attributeKey]['label'] = $attribute['label'] . ' (' . $attributeKey . ')';
    //                                 }
    //                                 unset($response['response'][$attributeGroupKey][$attributeKey]);
    //                             } else {
    //                                 $response['response'][$attributeGroupKey][$attributeKey]['label'] = $attribute['label'] . ' (' . $attributeKey . ')';
    //                             }
    //                         } else {
    //                             if ($attributeGroupKey == 'Mandantory') {
    //                                 $response['response']['Optional'][$attributeKey] = $attribute;
    //                                 $response['response']['Optional'][$attributeKey]['label'] = $attribute['label'] . ' (' . $attributeKey . ')';
    //                                 unset($response['response'][$attributeGroupKey][$attributeKey]);
    //                             }
    //                         }   
    //                         unset($response['response'][$attributeGroupKey][$attributeKey]['productTypeSpecific']);
    //                         if (!$all) {
    //                             if ($attributeGroupKey == 'Variation' && in_array($attributeKey, ['parent_child', 'parent_sku', 'relationship_type'])) {
    //                                 unset($response['response'][$attributeGroupKey][$attributeKey]);
    //                             }

    //                             unset($response['response'][$attributeGroupKey]['feed_product_type']);
    //                             unset($response['response'][$attributeGroupKey]['item_sku']);
    //                             unset($response['response'][$attributeGroupKey]['item_name']);
    //                             unset($response['response'][$attributeGroupKey]['standard_price']);
    //                             unset($response['response'][$attributeGroupKey]['quantity']);
    //                             unset($response['response'][$attributeGroupKey]['sku']);
    //                             unset($response['response'][$attributeGroupKey]['price']);
    //                             unset($response['response'][$attributeGroupKey]['main_image_url']);
    //                             unset($response['response'][$attributeGroupKey]['recommended_browse_nodes']);
    //                             unset($response['response'][$attributeGroupKey]['recommended_browse_nodes1']);
    //                             unset($response['response'][$attributeGroupKey]['external_product_id_type']);
    //                             unset($response['response'][$attributeGroupKey]['product-id-type']);
    //                             unset($response['response'][$attributeGroupKey]['external_product_id']);
    //                             unset($response['response'][$attributeGroupKey]['product-id']);
    //                         }
    //                     }
    //                 }
    //                 if(isset($response['response']['Mandantory']['brand_name']['amazon_recommendation']))
    //                 {
    //                     unset($response['response']['Mandantory']['brand_name']['amazon_recommendation']);
    //                 }
    //                 if(isset($response['response']['Mandantory']['manufacturer']['amazon_recommendation']))
    //                 {
    //                     unset($response['response']['Mandantory']['manufacturer']['amazon_recommendation']);
    //                 }
    //                 if(isset($response['response']['Mandantory']['part_number']['amazon_recommendation']))
    //                 {
    //                     unset($response['response']['Mandantory']['part_number']['amazon_recommendation']);
    //                 }
    //                 return ['success' => true, "data" => $response['response']];
    //             } else {
    //                 return ['success' => false, "message" => $response['msg'] ?? 'something went wrong on remote'];
    //             }
    //         }
    //         return ['success' => false, "message" => 'remote shop not found'];
    //     }
    //     return ['success' => false, "message" => 'One of Home shop id, category, subcategory, barcode exemption is missing'];
    // }

    public function getAmazonAttributes($data, $all = false)
    {
        if (isset($data['target']['shopId']) && !empty($data['data']['product_type'])) {
            return $this->getJsonCategoryAttributes($data);
            // if (isset($response['success'], $response['data']) && !empty($response['data'])) {
            //     return $response;
            // }
        } else {
            return ['success' => false, 'message' => "Shop Id and product type are required"];
        }

        if (isset($data['target']['shopId'], $data['data']['category'], $data['data']['sub_category'], $data['data']['browser_node_id'], $data['data']['barcode_exemption'])) {
            $cacheAttributes = false;
            $categoryId = $data['data']['category'];
            $subCategoryId = $data['data']['sub_category'];
            $homeShopId = $data['target']['shopId'];
            $browseNodeId = $data['data']['browser_node_id'];
            $barcode = $data['data']['barcode_exemption'];
            $remoteShop = $this->_user_details->getShop($homeShopId, $this->_user_id);
            if ($remoteShop && !empty($remoteShop)) {
                $remoteShopId = $remoteShop['remote_shop_id'];
                $marketplaceId = $remoteShop['warehouses'][0]['marketplace_id'] ?? false;
                // if(isset($data['data']['product_type']) && !empty($data['data']['product_type'])) {
                //     $userId = $data['user_id'] ?? (string)$this->di->getUser()->getId();
                //     $jsonAttributeHelper = $this->di->getObjectManager()->get('App\Amazon\Components\Listings\CategoryAttributes');
                //     $payload = [
                //         'product_type' => $data['data']['product_type'],
                //         'remote_shop_id' => $remoteShopId,
                //         'marketplace_id' => $marketplaceId,
                //         'category' => $categoryId,
                //         'user_id' => $userId
                //     ];
                //     $result = $jsonAttributeHelper->getJsonCategoryAttributes($payload);
                //     if(isset($result['success'], $result['data']) && !empty($result['data'])) {
                //         return $result;
                //     }
                // }
                $params = [
                    'category_id' => $categoryId,
                    'sub_category_id' => $subCategoryId,
                    'barcode' => $barcode,
                    'shop_id' => $remoteShopId,
                    'home_shop_id' => $homeShopId,
                    'browse_node_id' => $browseNodeId,
                ];

                try {
                    $attributeCacheObj = $this->di->getObjectManager()->create(CategoryAttributeCache::class);
                    $cacheAttributes = $attributeCacheObj->getAttributesFromCache($marketplaceId, $categoryId, $subCategoryId);
                }
                catch(Exception) {
                    $cacheAttributes = false;
                }

                if($cacheAttributes !== false) {
                    $response = $cacheAttributes;
                } else {
                    $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                    $response = $commonHelper->sendRequestToAmazon('category-attribute', $params, 'GET');
                    $attributeCacheObj->saveAttributesInCache($marketplaceId, $categoryId, $subCategoryId, $response);
                }

                if (isset($response['success'], $response['response']) && $response['success'])
                {
                    $validValues = [];
                    $variation_theme = [];
                    if (isset($response['valid_values'])) {
                        $validValues = $response['valid_values'];
                    }

                    if (isset($response['variation_theme'])) {
                        $variation_theme = $response['variation_theme'];
                    }

                    foreach ($response['response'] as $attributeGroupKey => $attributeGroups) {
                        if ($attributeGroupKey == 'Images' && !$all) {
                            unset($response['response'][$attributeGroupKey]);
                            continue;
                        }

                        foreach ($attributeGroups as $attributeKey => $attribute) {
                            if (!$all && isset($response['browse_node_attribute'][$attributeKey]) && !empty($response['browse_node_attribute'][$attributeKey])) {
                                unset($response['response'][$attributeGroupKey][$attributeKey]);
                                continue;
                            }
                            if (isset($response['browse_node_attribute'][$attributeKey]) && !empty($response['browse_node_attribute'][$attributeKey])) {
                                $response['response'][$attributeGroupKey][$attributeKey]['premapped_values'] = $response['browse_node_attribute'][$attributeKey];
                            }

                            if (isset($attribute['productTypeSpecific'][$subCategoryId])) {
                                $productTypeSpecific = $attribute['productTypeSpecific'][$subCategoryId];

                                if (!empty($validValues)) {
                                    if (isset($validValues[$attribute['label']][$subCategoryId]) && !empty($validValues[$attribute['label']][$subCategoryId])) {
                                        $response['response'][$attributeGroupKey][$attributeKey]['amazon_recommendation'] = $validValues[$attribute['label']][$subCategoryId];
                                    } elseif (isset($validValues[$attribute['label']]['all_cat']) && !empty($validValues[$attribute['label']]['all_cat'])) {
                                        $response['response'][$attributeGroupKey][$attributeKey]['amazon_recommendation'] = $validValues[$attribute['label']]['all_cat'];
                                    } elseif (isset($validValues[$attributeKey][$subCategoryId]) && !empty($validValues[$attributeKey][$subCategoryId])) {
                                        $response['response'][$attributeGroupKey][$attributeKey]['amazon_recommendation'] = $validValues[$attributeKey][$subCategoryId];
                                    } elseif (isset($validValues[$attributeKey]['all_cat']) && !empty($validValues[$attributeKey]['all_cat'])) {
                                        $response['response'][$attributeGroupKey][$attributeKey]['amazon_recommendation'] = $validValues[$attributeKey]['all_cat'];
                                    }
                                } elseif (isset($productTypeSpecific['accepted_values'])) {
                                    $response['response'][$attributeGroupKey][$attributeKey]['amazon_recommendation'] = $productTypeSpecific['accepted_values'];
                                }

                                if (!empty($variation_theme) && $attributeKey == 'variation_theme') {
                                    if(isset($variation_theme['variation_theme']))
                                    {
                                    $response['response'][$attributeGroupKey][$attributeKey]['required_variant_attributes'] = $variation_theme['variation_theme'][$subCategoryId];
                                    }
                                    elseif(isset($variation_theme['variation theme']))
                                    {
                                        $response['response'][$attributeGroupKey][$attributeKey]['required_variant_attributes'] = $variation_theme['variation theme'][$subCategoryId];
                                    }
                                }

                                if (isset($productTypeSpecific['condition']) && $attributeGroupKey == 'Mandantory' && $productTypeSpecific['condition'] == 'optional') {
                                    if(isset($response['response'][$attributeGroupKey][$attributeKey]))
                                    {
                                        $response['response']['Optional'][$attributeKey] = $response['response'][$attributeGroupKey][$attributeKey];
                                        $response['response']['Optional'][$attributeKey]['label'] = $attribute['label'] . ' (' . $attributeKey . ')';
                                    }

                                    unset($response['response'][$attributeGroupKey][$attributeKey]);
                                } else {
                                    $response['response'][$attributeGroupKey][$attributeKey]['label'] = $attribute['label'] . ' (' . $attributeKey . ')';
                                }
                            } else {
                                if ($attributeGroupKey == 'Mandantory') {
                                    $response['response']['Optional'][$attributeKey] = $attribute;
                                    $response['response']['Optional'][$attributeKey]['label'] = $attribute['label'] . ' (' . $attributeKey . ')';
                                    unset($response['response'][$attributeGroupKey][$attributeKey]);
                                }
                            }

                            unset($response['response'][$attributeGroupKey][$attributeKey]['productTypeSpecific']);
                            if (!$all) {
                                if ($attributeGroupKey == 'Variation' && in_array($attributeKey, ['parent_child', 'parent_sku', 'relationship_type'])) {
                                    unset($response['response'][$attributeGroupKey][$attributeKey]);
                                }

                                unset($response['response'][$attributeGroupKey]['feed_product_type']);
                                unset($response['response'][$attributeGroupKey]['item_sku']);
                                unset($response['response'][$attributeGroupKey]['item_name']);
                                unset($response['response'][$attributeGroupKey]['standard_price']);
                                unset($response['response'][$attributeGroupKey]['quantity']);
                                unset($response['response'][$attributeGroupKey]['sku']);
                                unset($response['response'][$attributeGroupKey]['price']);
                                unset($response['response'][$attributeGroupKey]['main_image_url']);
                                unset($response['response'][$attributeGroupKey]['recommended_browse_nodes']);
                                unset($response['response'][$attributeGroupKey]['recommended_browse_nodes1']);
                                unset($response['response'][$attributeGroupKey]['external_product_id_type']);
                                unset($response['response'][$attributeGroupKey]['product-id-type']);
                                unset($response['response'][$attributeGroupKey]['external_product_id']);
                                unset($response['response'][$attributeGroupKey]['product-id']);
                            }
                        }
                    }

                    if(isset($response['response']['Mandantory']['brand_name']['amazon_recommendation']))
                    {
                        unset($response['response']['Mandantory']['brand_name']['amazon_recommendation']);
                    }

                    if(isset($response['response']['Mandantory']['manufacturer']['amazon_recommendation']))
                    {
                        unset($response['response']['Mandantory']['manufacturer']['amazon_recommendation']);
                    }

                    if(isset($response['response']['Mandantory']['part_number']['amazon_recommendation']))
                    {
                        unset($response['response']['Mandantory']['part_number']['amazon_recommendation']);
                    }

                    return ['success' => true, "data" => $response['response']];
                }
                return ['success' => false, "message" => $response['msg'] ?? 'something went wrong on remote'];
            }

            return ['success' => false, "message" => 'remote shop not found'];
        }

        return ['success' => false, "message" => 'One of Home shop id, category, subcategory, barcode exemption is missing'];
    }

    public function getJsonCategoryAttributes($data)
    {
        if (isset($data['target']['shopId']) && !empty($data['data']['product_type'])) {
            $userId = $data['user_id'] ?? (string)$this->di->getUser()->id;
            $homeShopId = $data['target']['shopId'];
            $remoteShop = $this->_user_details->getShop($homeShopId, $userId);
            if ($remoteShop && !empty($remoteShop)) {
                $remoteShopId = $remoteShop['remote_shop_id'];
                $showCategory = $data['data']['show_category']??false;
                $marketplaceId = $remoteShop['warehouses'][0]['marketplace_id'] ?? false;
                $jsonAttributeHelper = $this->di->getObjectManager()->get(CategoryAttributes::class);
                $payload = [
                    'product_type' => $data['data']['product_type'],
                    'remote_shop_id' => $remoteShopId,
                    'marketplace_id' => $marketplaceId,
                    'user_id' => $userId,
                    'shop_id' => $homeShopId,
                    'showCategory' => $showCategory
                ];
                return $jsonAttributeHelper->getJsonCategoryAttributes($payload);
            }
            return ['success' => false, "message" => 'remote shop not found'];
        }
        return ['success' => false, "message" => 'shop id and product type required'];
    }

    public function saveAttributeInDb($params, $attributes): void
    {
        if (isset($attributes['response'])) {
            $attributes = $attributes['response'];
            if (isset($attributes[0]['value'], $attributes[1]['value'])) {
                $requiredAttributes = $attributes[0]['value'];
                $optionalAttributes = $attributes[1]['value'];
                $allAttributes = array_merge($requiredAttributes, $optionalAttributes);
                $collection = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollectionForTable('category_attributes');
                foreach ($allAttributes as $rId => $rAttribute) {
                    $optionValues = [];
                    if (isset($rAttribute['restriction']['optionValues'])) {
                        $optionValues = $rAttribute['restriction']['optionValues'];
                    }

                    $attribute = [
                        'name' => $rAttribute['name'],
                        'code' => $rId,
                        'marketplace' => 'amazon',
                        'category_id' => $params['category_id'],
                        'sub_category_id' => $params['sub_category_id'],
                        'type' => $rAttribute['dataType'],
                        'required' => $rAttribute['minOccurs'],
                        'sequence' => $rAttribute['sequence'],
                        'values' => $optionValues,
                        'mapping' => [],
                    ];

                    $attributeData = $collection->findOne(['category_id' => $params['category_id'], 'sub_category_id' => $params['sub_category_id'], 'code' => $rId]);
                    if ($attributeData && count($attributeData)) {
                        $collection->updateOne(['category_id' => $params['category_id'], 'sub_category_id' => $params['sub_category_id']], ['$set' => $attribute]);
                    } else {
                        $attribute['_id'] = $this->_baseMongo->getCounter('category_attribute_id', $this->_user_id);
                        $collection->insertOne($attribute);
                    }
                }
            }
        }
    }

    public function getAmazonSubCategory($data)
    {
        if (isset($data['shop_id'])) {
            $homeShopId = $data['shop_id'];
            $remoteShop = $this->_user_details->getShop($homeShopId, $this->_user_id);
            if ($remoteShop && !empty($remoteShop)) {
                $remoteShopId = $remoteShop['remote_shop_id'];
                $params = [
                    'shop_id' => $remoteShopId,
                    'home_shop_id' => $homeShopId,
                    'selected' => $data['category'],
                ];
                $commonHelper = $this->di->getObjectManager()
                    ->get(Helper::class);
                $response = $commonHelper->sendRequestToAmazon('sub-category', $params, 'GET');

                if (isset($response['success'], $response['response']) && $response['success']) {
                    return ['success' => true, "data" => $response['response']];
                }
                return ['success' => false, "message" => $response['msg']];
            }

            return ['success' => false, "message" => 'remote shop not found'];
        }

        return ['success' => false, "message" => 'home shop not found'];
    }

    public function getBrowseNodeIds($data)
    {
        if (isset($data['shop_id'])) {
            $homeShopId = $data['shop_id'];
            $remoteShop = $this->_user_details->getShop($homeShopId, $this->_user_id);
            if ($remoteShop && !empty($remoteShop)) {
                $remoteShopId = $remoteShop['remote_shop_id'];
                $params = [
                    'shop_id' => $remoteShopId,
                    'home_shop_id' => $homeShopId,
                    'selected' => $data['category'],
                ];
                $commonHelper = $this->di->getObjectManager()
                    ->get(Helper::class);
                $response = $commonHelper->sendRequestToAmazon('browse-node', $params, 'GET');

                if (isset($response['success'], $response['response']) && $response['success']) {
                    return ['success' => true, "data" => $response['response']];
                }
                return ['success' => false, "message" => $response['msg']];
            }

            return ['success' => false, "message" => 'remote shop not found'];
        }

        return ['success' => false, "message" => 'home shop not found'];
    }

    public function moldAmazonAttributes($allAttributes)
    {
        $attributes = [];
        foreach ($allAttributes as $allAttribute) {
            $attributes = array_merge($attributes, $allAttribute);
        }

        return $attributes;
    }

    /*
     * This method is used to get selected attributs for required country - category and sub-category
     * used for api path frontend/adminpanelamazonmulti/getTargetSelectedAttributes called from home admin panel.
     * @param  array $data (required) ['remote_shop_id':(string), 'category':(string), 'sub_category':(string), 'attributes':(array)]
     */
    public function getSelectedAttributes($data)
    {
        try {
            if (isset($data['remote_shop_id'], $data['category'], $data['sub_category'], $data['attributes']) && $data['category'] && $data['sub_category'] && !empty($data['attributes'])) {
                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                $params = [
                    'shop_id' => $data['remote_shop_id'],
                    'category' => $data['category'],
                    'sub_category' => $data['sub_category'],
                    'attributes' => $data['attributes']
                ];
                $result = $commonHelper->sendRequestToAmazon('optional-attribute', $params, 'GET');
                if (isset($result['success']) && $result['success']) {
                    return ['success' => true, 'data' => $data, 'response' => $result['response']];
                }

                return ['success' => false, 'data' => $data, 'message' => $result['message'] ?? 'Error in fetching attributes'];
            }
            return ['success' => false, 'data' => $data, 'message' => 'remote_shop_id, category, sub_category and attributes are required'];
        } catch (Exception $e) {
            return [
                'success' => false,
                'data' => $data,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * This method is used to change condition (optional/required) of attributs for required country - category and sub-category
     * @param  array $data (required) : ['shop_id':(string), 'category':(string), 'sub_category':(string), 'updated_attributes':(array)]
     */
    public function changeAttributesRequirement($data)
    {
        try {
            if (isset($data['shop_id'], $data['category'], $data['sub_category'], $data['updated_attributes']) && $data['category'] && $data['sub_category']) {
                if (!empty($data['updated_attributes'])) {
                    $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                    $params = [
                        'shop_id' => $data['shop_id'],
                        'category' => $data['category'],
                        'sub_category' => $data['sub_category'],
                        'attributes' => $data['updated_attributes']
                    ];
                    $result = $commonHelper->sendRequestToAmazon('update-attribute', $params, 'POST');
                    if (isset($result['success']) && $result['success']) {
                        if(isset($result['marketplaceId']))
                        {
                            $this->di->getObjectManager()->get(CategoryAttributeCache::class)->deleteAttributesFromCache($result['marketplaceId'], $data['category'],  $data['sub_category']); 
                        }

                        return ['success' => true, 'message' => "Attribute's condition Changed successfully."];
                    }

                    return ['success' => false, 'message' => 'Error in changing attributes successfully.'];
                }

                return ['success' => true, 'message' => 'Nothing is updated.'];
            }
            return ['success' => false, '$data' => $data, 'message' => 'shop_id, category, sub_category and attributes are requiredd'];
        } catch (Exception $e) {
            return [
                'success' => false,
                'data' => $data,
                'message' => $e->getMessage()
            ];
        }
    }
}