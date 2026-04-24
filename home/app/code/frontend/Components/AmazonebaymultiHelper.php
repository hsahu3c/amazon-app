<?php

namespace App\Frontend\Components;

use App\Core\Components\Base;
use Exception;
use MongoDB\BSON\ObjectId;
use App\Amazon\Components\Common\Helper;
class AmazonebaymultiHelper extends Base
{
    public function getShopDetails($data =[]){
        if ( !isset($data['user_id']) ) $user_id = $this->di->getUser()->id;

        $marketplace = $data['marketplace'] ?? "shopify";


        $helper = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Shop\Shop');

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        // $registration_details = $user_details->getConfigByKey('registration_details', $user_id);
        $registration_details = $user_details->getUserDetailsByKey('registration_details', $user_id);

        if($registration_details){
            $shopdetails = $registration_details;
        }else {
            $shopdetails = $helper->getShopDetailByUserID($user_id, $marketplace);
        }

        if(isset($shopdetails['success'])) return  ['success' => false, 'data'=>[], 'message' => 'Shop details not found', 'code' => 'shop_not_found'];

        if(count($shopdetails)) return ['success' => true, 'data'=> $shopdetails, 'message' => 'Shop details found', 'code' => 'shop_found'];

        return  ['success' => false, 'data'=>[], 'message' => 'Shop details not found', 'code' => 'shop_not_found'];

    }

    public function getAccountsConnected($user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        // $shops = $user_details->getConfigByKey('shops', $user_id);
        $shops = $user_details->getUserDetailsByKey('shops', $user_id);
        $marketplaceList = [];
        foreach ($shops as $shop){
            $marketplaceList[] = $shop['marketplace'];
        }

        $accountConnected = in_array('amazon', $marketplaceList) || in_array('ebay', $marketplaceList);
        $message = $accountConnected ? 'Account connected successfully' : 'Account not connected successfully';
        return ['success' => $accountConnected, 'message' => $message, 'account_connected' => $marketplaceList ];
    }

    public function saveRegistrationData($data = [], $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $registration_details = $user_details->setConfigByKey('registration_details', $data, $user_id);
        if($registration_details['success']){
            $response = ['success' => true, 'message' => 'Registration details updated', 'code' => 'registration_updated'];
        }else{
            $response = ['success' => false, 'message' => 'Registration details update failed', 'code' => 'registration_update_failed'];
        }

        return $response;
    }

    public function getfilterdata($user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("import_profile");
        $filters = [];
        $result = $collection->findOne(
            ['user_id' => (string)$user_id],
            [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
                "projection" => ["filters" => 1]
            ]
        );

        if (!empty($result) && isset($result["filters"])) {
            $filters =  $result["filters"];
        }

        return $filters;
    }

    public function saveFilterData($data = [], $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $filterModifiedData = [];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("import_profile");

        $filters = $this->getfilterdata($user_id);

        $data = array_merge(count($filters)? $filters : [], $data);
        $accounts_connected = $this->getAllConnectedAcccounts($user_id);
        if($accounts_connected['success']){
            $shops = $accounts_connected['data'];
            foreach ($shops as $shop){
                if(isset($data[$shop['marketplace']])) {
                    foreach ($data[$shop['marketplace']] as $pos => $attribute) {
                        switch ($shop['marketplace']) {
                            case 'shopify':
                                $filterModifiedData[$shop['marketplace']][$shop['id']][$pos] = [
                                    "type" => "attribute",
                                    "value" => $attribute
                                ];
                                break;
                            case 'ebay':
                                $filterModifiedData[$shop['marketplace']][$shop['id']][$attribute['shopify_attribute']] = [
                                    "type" => "attribute",
                                    "value" => $attribute['marketplace_attribute'] === 'custom' ? $attribute['custom'] : $attribute['marketplace_attribute']
                                ];

                                break;
                            case 'amazon':
                                $filterModifiedData[$shop['marketplace']][$shop['id']][$attribute['shopify_attribute']] = [
                                    "type" => "attribute",
                                    "value" => $attribute['marketplace_attribute'] === 'custom' ? $attribute['custom'] : $attribute['marketplace_attribute']
                                ];
                                break;
                            default:
                                break;
                        }
                    }
                }
            }
        }

        $result = $collection->UpdateOne(
            ['user_id' => (string)$user_id],
            ['$set' => ["filters" => $data, "sources" => $filterModifiedData]],
            ["upsert" => true]
        );

        if ($result) {
            return ['success' => true, 'message' => 'Shop Updated Successfuly'];
        }
        return ['success' => false, 'message' => 'No Record Matched', 'code' => 'no_record_found'];

       // $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
       // /*$presaved_filter_data = $user_details->getConfigByKey('filters', $user_id);*/
       // $presaved_filter_data = $user_details->getUserDetailsByKey('filters', $user_id);

       // $data = array_merge($presaved_filter_data? $presaved_filter_data : [], $data);
       // $filter_data= $user_details->setConfigByKey('filters', $data, $user_id);
       // if($filter_data['success']){
       //     $response = ['success' => true, 'message' => 'Filters updated', 'code' => 'filter_updated'];
       // }else{
       //     $response = ['success' => false, 'message' => 'Filters update failed', 'code' => 'filters update_failed'];
       // }
       // return $response;
    }

    public function getUserData($key, $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        // $presaved_data= $user_details->getConfigByKey($key, $user_id);
        $presaved_data= $user_details->getUserDetailsByKey($key, $user_id);
        return $presaved_data;
    }

    public function getProfilebyId($rawData,  $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        if(!isset($rawData['id'])) return ['success' => false, "message" => "Params missing"];

        $profileid = $rawData['id'];
        $profileData = $this->di->getObjectManager()->get('\App\Ebay\Components\Profile\DB')->init()->findOne(
            ['profile_id' => "{$profileid}", "user_id" => "{$user_id}"],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        );
        if($profileData){
            return ['success' => true, "data" => $profileData];
        }

        return ['success' => false, "data" => []];
    }

    public function deleteProfile($data, $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        if(!isset($data['id'])) return ["success" => false, "message" => "Profile deletion failed, params missing"];

        $filter = ["profile_id" => (string)$data['id'], "user_id" => (string)$user_id];
        try {
            $this->di->getObjectManager()->get('\App\Ebay\Components\Profile\DB')->init()->deleteMany($filter);
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('product_container');
            $collection->updateMany(['profile_id' => (string)$data['id'] ],  [ '$set' => ["profile_id" => "", "profile_name" => "" ] ], []);
            return ['success' => true, "message" => "Profile deleted successfully"];
        }catch (Exception $e){
            return ['success' => false, "message" => $e->getMessage()];
        }
    }

    public function deleteSalesChannelProfile($data, $user_id = false)
    {
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        if(!isset($data['id'])) return ["success" => false, "message" => "Profile deletion failed, params missing"];

        $profileData = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\DB')->init()->findOne(
            ['profile_id' => (string)$data['id'], "user_id" => "{$user_id}"],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        );

        if($profileData) {
            $shopwisetemplateInfo  = $profileData['settings']["amazon"];
            $shopIds = array_keys($shopwisetemplateInfo);
            $shopId = $shopIds[0];
            $templateandIds = $shopwisetemplateInfo[$shopId]["templates"];
            foreach ($templateandIds as $templateId ){
                $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper')->deleteTemplate(["template_id" => $templateId]);
            }

            $profileSavedName = $profileData['name'];
            // $filter = ["profile_id" => (string)$data['id'], "user_id" => (string)$user_id];
            $filter = ['_id' => new ObjectId($profileData['_id'])];

            try {
                $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\DB')->init()->deleteMany($filter);
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollectionForTable('product_container');
                // $collection->updateMany(['profile.profile_id' => (string)$data['id'] ],  [ '$unset' => [ "profile" => 1 ] ], []);
                $collection->updateMany(['user_id' => (string)$user_id, 'profile.profile_id' => (string)$data['id']],  ['$unset' => ['profile' => 1]]);

                $amazon_product_collection = $mongo->getCollectionForTable('amazon_product_container');
                $amazon_product_collection->updateMany(['user_id' => (string)$user_id, 'profile_id' => (string)$data['id']],  ['$unset' => ['profile_id' => 1, 'profile_name' => 1]]);

                return ['success' => true, "message" => "Template deleted successfully"];
            }catch (Exception $e){
                return ['success' => false, "message" => $e->getMessage()];
            }
        }
        else {
            return ["success" => false, "message" => "Template deletion failed, Profile not found"];
        }
    }

    /*public function deleteSalesChannelProfile($data, $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;
        if(!isset($data['id'])) return ["success" => false, "message" => "Profile deletion failed, params missing"];

        $profileData = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\DB')->init()->findOne(
            ['profile_id' => (string)$data['id'], "user_id" => "$user_id"],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        );
        if($profileData){
            $shopwisetemplateInfo  = $profileData['settings']["amazon"];
            $shopIds = array_keys($shopwisetemplateInfo);
            $shopId = $shopIds[0];
            $templateandIds = $shopwisetemplateInfo[$shopId]["templates"];
            foreach ($templateandIds as $key => $templateId ){
                $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper')->deleteTemplate(["template_id" => $templateId]);
            }
        }else{
            return ["success" => false, "message" => "Template deletion failed, Profile not found"];
        }
        $profileSavedName = $profileData['name'];
        $filter = ["profile_id" => (string)$data['id'], "user_id" => (string)$user_id];
        try {
            $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\DB')->init()->deleteMany($filter);
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('product_container');
            $collection->updateMany(['profile.profile_id' => (string)$data['id'] ],  [ '$unset' => [ "profile" => 1 ] ], []);
            return ['success' => true, "message" => "Template Deleted"];
        }catch (\Exception $e){
            return ['success' => false, "message" => $e->getMessage()];
        }
    }*/

    public function updateActiveInactiveShop($data, $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        if(!isset($data['shop_id'])) return ['success' => false, 'message' => "Shop details not found."];

        $getShop = $user_details->getShop( $data['shop_id'], $user_id);
        if($getShop){
            if (isset($getShop['warehouses'][0]['token_generated']) && !$getShop['warehouses'][0]['token_generated'] && $data['state'] == 'active') {
                return ['success' => false, 'message' => 'Shop status not updated, kindly generate token and try again.'];
            }
            $getShop['warehouses'][0]['status'] = $data['state'] ?? "inactive";
            $user_details->addShop($getShop, $user_id, ['_id']);
            return ['success' => true, 'message' => 'Shop status updated.'];
        }

        return ['success' => false, 'message' => 'Shop status not updated.'];
    }

    public function getAllSalesProductTemplates($filter = [], $user_id = false)
    {
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $allproductTemplates = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\DB')->init()->find(
            ["user_id" => $user_id ],
            ["typeMap" => ['root' => 'array', 'document' => 'array'], "projection" => ["name" => 1, "query" => 1, "profile_id" => 1]]
        )->toArray();

        if(count($allproductTemplates)){
            foreach ($allproductTemplates  as $key => $profile){
                // $productsUnderQuery = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')->getProductsByQuery(["query" => $profile['query']['query'], "marketplace" => "shopify", "sendCount" => true]);
                // $allproductTemplates[$key]["product_count"] = $productsUnderQuery['success'] ? $productsUnderQuery['data'] : 0;

                $distinctContainers = true;
                if(isset($filter['distinct_containers'])) {
                    $distinctContainers = $filter['distinct_containers'];
                }

                $req_data = ['profile_name'=>$profile['name'], 'distinct_containers'=>$distinctContainers];
                // $productCountResp = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')->getProductCountByProfileName($user_id, $req_data);
                $productCountResp = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')->getProfileProductsCount($user_id, $req_data);

                $allproductTemplates[$key]['product_count'] = $productCountResp['count'] ?? 0;
            }
        }

        return ['success' => true, 'data' => $allproductTemplates];
    }

    public function getSalesProfileId($rawData = [], $user_id = false)
    {
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        if(!isset($rawData['id'])) return ['success' => false, "message" => "Params missing"];

        $profileid = $rawData['id'];

        if($profileid != 'newTemp')
        {
            $profileData = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\DB')->init()->findOne(
                ['profile_id' => "{$profileid}", "user_id" => "{$user_id}"],
                ["typeMap" => ['root' => 'array', 'document' => 'array']]
            );

            if($profileData)
            {
                $templateName = $profileData['name'];
                $prepareQuery = $profileData['query'];
                $marketplace = "amazon";
                $settings = $profileData['settings'][$marketplace];
                $shopArray = array_keys($settings);
                $amazonsellerid = $shopArray[0];
                $templates = $settings[$amazonsellerid]['templates'];

                $categoryId = $templates['category'];
                $categoryTemplateData = $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper')->getTemplatebyId(['template_id' => (string)$categoryId]);
                $categorydata = [];
                if($categoryTemplateData['success']){
                    $categorydata = $categoryTemplateData['data']['data'];
                }

                $inventoryId = $templates['inventory'];
                $inventoryTemplateData = $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper')->getTemplatebyId(['template_id' => (string)$inventoryId]);
                $inventorydata = [];
                if($inventoryTemplateData['success']){
                    $inventorydata = $inventoryTemplateData['data']['data'];
                }

                $productId = $templates['product'];
                $productTemplateData = $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper')->getTemplatebyId(['template_id' => (string)$productId]);
                $productdata = [];
                if($productTemplateData['success']){
                    $productdata = $productTemplateData['data']['data'];
                }

                $pricingId = $templates['pricing'];
                $pricingTemplateData = $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper')->getTemplatebyId(['template_id' => (string)$pricingId]);
                $pricingdata = [];
                if($pricingTemplateData['success']){
                    $pricingdata = $pricingTemplateData['data']['data'];
                }

                $responsedata = [
                    "id" => $profileData["profile_id"],
                    'amazon_seller_id' => (string)$amazonsellerid,
                    "template_name" => $templateName,
                    "inventory_settings" => $inventorydata,
                    "filter_products" => ["prepareQuery" => $prepareQuery],
                    "pricing_settings" => $pricingdata,
                    "product_settings" => $productdata,
                    "category_settings" => $categorydata
                ];

                if($profileData){
                    return ['success' => true, "data" => $responsedata];
                }
            }
        }

        return ['success' => false, "data" => []];
    }

    public function saveSalesProfile($data, $user_id = false)
    {
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $appTag = 'amazon';
        $marketplace = 'amazon';
        $profile_id = false;

        if(isset($data['id'])){
            $profile_id = $data['id'];
        }

        $allproductTemplates = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\DB')->init()->find(
            ["user_id" => $user_id ],
            ["typeMap" => ['root' => 'array', 'document' => 'array'], "projection" => ["name" => 1]]
        )->toArray();

        if(count($allproductTemplates)){
            foreach ($allproductTemplates as $profile){
                if($profile['name'] == $data['data']['settings']['template_name'] && !$profile_id){
                    return ['success' => false, 'message' => "Kindly enter a unique Name and try again."];
                }
            }
        }

        $inventoryTemplateId = false;
        $pricingTemplateId = false;
        $productTemplateId = false;
        $categoryTemplateId = false;

        $profileData = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\DB')->init()->findOne(
            ['profile_id' => "{$profile_id}", "user_id" => "{$user_id}"],
            ["typeMap" => ['root' => 'array', 'document' => 'array']]
        );
        if($profileData){
            $marketplace = "amazon";
            $settings = $profileData['settings'][$marketplace];
            $shopArray = array_keys($settings);
            $amazonsellerid = $shopArray[0];
            $templates = $settings[$amazonsellerid]['templates'];
            $inventoryTemplateId = $templates["inventory"];
            $pricingTemplateId = $templates["pricing"];
            $productTemplateId = $templates["product"];
            $categoryTemplateId = $templates["category"];
        }

        $data = $data['data'];
        $shopId = $data["settings"]['amazon_seller_id'];
        $templatename = $data['settings']['template_name'];
        $prepareQuery = $data['settings']['filter_products']['prepareQuery'];
        $inventoryTemplate = $data['settings']['inventory_settings'];
        $priceTemplate = $data['settings']['pricing_settings'];
        $productTemplate = $data['settings']['product_settings'];
        $categoryTemplate = $data['settings']['category_settings'];

        $categorydata = [
            "marketplace" => $marketplace,
            "title" => $templatename."_category_template",
            "type" => "category",
            "data" => $categoryTemplate,
        ];
        if($categoryTemplateId){
            $categorydata['_id'] = $categoryTemplateId;
        }

        $saveCategorytemplate = $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper')->saveTemplate($categorydata);
        if($saveCategorytemplate['success']) $categoryTemplateId = $saveCategorytemplate['id'];

        $pricingdata = [
            "marketplace" => $marketplace,
            "title" => $templatename."_pricing_template",
            "type" => "pricing",
            "data" => $priceTemplate,
        ];

        if($pricingTemplateId){
            $pricingdata['_id'] = $pricingTemplateId;
        }

        $savePricingtemplate = $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper')->saveTemplate($pricingdata);
        if($savePricingtemplate['success']) $pricingTemplateId = $savePricingtemplate['id'];

        $inventorydata = [
            "marketplace" => $marketplace,
            "title" => $templatename."_inventory_template",
            "type" => "inventory",
            "data" => $inventoryTemplate,
        ];

        if($inventoryTemplateId){
            $inventorydata['_id'] = $inventoryTemplateId;
        }

        $saveInventorytemplate = $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper')->saveTemplate($inventorydata);
        if($saveInventorytemplate['success']) $inventoryTemplateId = $saveInventorytemplate['id'];

        $productdata = [
            "marketplace" => $marketplace,
            "title" => $templatename."_product_template",
            "type" => "product",
            "data" => $productTemplate,
        ];

        if($productTemplateId){
            $productdata['_id'] = $productTemplateId;
        }

        $saveProducttemplate = $this->di->getObjectManager()->get('\App\Amazon\Components\Template\Helper')->saveTemplate($productdata);
        if($saveProducttemplate['success']) $productTemplateId = $saveProducttemplate['id'];

        $formatDataForSaving = [
            "data" => [
                "name" => $templatename,
                "marketplace" => $marketplace,
                "prepareQuery" => $prepareQuery,
                "settings" => [
                    "{$shopId}" => [
                        "templates" => [
                            "category" => $categoryTemplateId,
                            "inventory" => $inventoryTemplateId,
                            "product" => $productTemplateId,
                            "pricing" => $pricingTemplateId
                        ]
                    ]
                ]
            ]
        ];

        $formattedData = $this->reformatforSaveProfileData($formatDataForSaving, $user_id, $profile_id);       

        if($profile_id) {
            $filter = ['profile_id' => (string)$profile_id];
            $finaldata = ['$set' => $formattedData];
            $options = ['$upsert' => true];

            $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\DB')->init()->update($filter, $finaldata, $options);
        } 
        else {
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $profile_id = $mongo->getCounter('profile_id');

            $formattedData['profile_id'] = (string)$profile_id;

            $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\DB')->init()->insertMany([$formattedData]);
        }


        $getProductids = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')->getProductsByQuery(["query" => $formattedData['query']['query'],'send_product'=>true,'all_product'=>true], false, false, ['source_product_id', 'container_id', 'profile']);

        // $getProductids = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')->getProductsByQuery(["query" => $formattedData['query']['query'],'send_product'=>true,'all_product'=>true], false, false, ['source_product_id', 'container_id', 'profile']);

        $filterQuery = ['user_id' => (string)$this->di->getUser()->id];
        $filterQuery = array_merge($filterQuery, $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Helper')->prepareQuery($formattedData['query']['query']));

        $finalQuery[] = [
            '$match' => $filterQuery
        ];

        $collection = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::PRODUCT_CONTAINER);

        $select_fields = ['container_id', 'source_product_id', 'profile'];

        $_group = ['_id' => '$container_id'];

        foreach ($select_fields as $field) {
            if(!isset($_group[$field])) {
                $_group[$field] = ['$first' => '$'.$field];
            }
        }

        $finalQuery[] = [
            '$group' => $_group
        ];

        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

        $response = $collection->aggregate($finalQuery, $options);
        $filteredProducts = $response->toArray();


        //handle case if products are assigned in some other profile and need to notify seller about the override. 
        $override = $data['override'] ?? true;
        $containerIds = [];
        $alreadyProfiledIds = [];

        // if($getProductids['success'] && count($getProductids['rows']))
        if(!empty($filteredProducts))
        {
            // foreach ($getProductids['rows'] as $key => $value) {
            foreach ($filteredProducts as $value) {                
                $alreadyProfiledFlag = false;

                if(isset($value['profile']) && !empty($value['profile'])) {
                    foreach ($value['profile'] as $profile) {
                        if(isset($profile['app_tag']) && $profile['app_tag']==$appTag) {
                            if($profile['profile_id']!=$profile_id) {
                                $alreadyProfiledFlag = true;

                                if(!isset($alreadyProfiledIds[$value['container_id']])) {
                                    $alreadyProfiledIds[$value['container_id']] = $profile['profile_id'];
                                }

                                break;
                            }
                        }   
                    }
                }

                if($alreadyProfiledFlag && !$override) {
                    continue;
                }
                if(!in_array((string)$value['container_id'], $containerIds)) {
                    $containerIds[] = (string)$value['container_id'];
                }
            }

            $profileassignData = [
                "profile_id"    => (string)$profile_id,
                "profile_name"  => $formattedData['name'],
                "app_tag"       => $appTag
            ];

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('product_container');

            $collection->updateMany(['user_id' => $user_id, 'profile.profile_id' => (string)$profile_id],  [ '$unset' => [ "profile" => 1 ] ], []);

            $collection->updateMany(['user_id' => $user_id, 'container_id' => ['$in' => $containerIds] ],  [ '$set' => [ "profile" => [$profileassignData]] ], []);

            if(isset($marketplace))
            {
                try {
                    $aggregation = [
                        ['$sort' => ['visibility' => 1]],
                        ['$match' => ['container_id' => ['$in' => $containerIds]]],
                        ['$group' => [
                                '_id'       => '$container_id',
                                'product'   => [
                                    '$push' => [
                                        'container_id'      => '$container_id',
                                        'source_product_id' => '$source_product_id',
                                        'sku'               => '$sku',
                                        'variant_attributes'=> '$variant_attributes'
                                    ]
                                ]
                            ]
                        ]
                    ];

                    $options = ['typeMap'   => ['root' => 'array', 'document' => 'array']];
                    $products = $collection->aggregate($aggregation, $options)->toArray();

                    if (!empty($products))
                    {
                        $marketplaceContainer = $mongo->getCollectionForTable("{$marketplace}_product_container");

                        $bulkOpArray = [];
                        foreach ($products as $product) 
                        {
                            $product = $product['product'];

                            $currentProduct = current($product);

                            $variant_attributes = [];
                            if(!empty($currentProduct['variant_attributes'])) {
                                $variant_attributes = $currentProduct['variant_attributes'];
                            }

                            if(count($variant_attributes) > 0) {
                                foreach ($product as $_product) {
                                    $query = [
                                        'user_id'           => $user_id,
                                        'container_id'      => $_product['container_id'],
                                        'source_product_id' => $_product['source_product_id']
                                    ];

                                    $data = [
                                        'profile_id'    => (string)$profile_id,
                                        'profile_name'  => $formattedData['name']
                                    ];
                                    if(array_key_exists('sku', $_product)) {
                                        $data['group_id'] = $_product['container_id'];
                                    }

                                    $bulkOpArray[] = [
                                        'updateOne' => [
                                            $query,
                                            ['$set' => $data],
                                            ['upsert' => true]
                                        ]
                                    ];
                                }
                            }
                            else {
                                foreach ($product as $_product) {
                                    $query = [
                                        'user_id'           => $user_id,
                                        'container_id'      => $_product['container_id'],
                                        'source_product_id' => $_product['source_product_id']
                                    ];

                                    $data = [
                                        'profile_id'        => (string)$profile_id,
                                        'profile_name'      => $formattedData['name']
                                    ];

                                    $bulkOpArray[] = [
                                        'updateOne' => [
                                            $query,
                                            ['$set' => $data],
                                            ['upsert' => true]
                                        ]
                                    ];
                                }
                            }
                        }

                        if(!empty($bulkOpArray))
                        {
                            $this->di->getObjectManager()->get("\App\\".ucfirst($marketplace)."\\Components\Product\Db")
                            ->init()
                            ->updateMany(['profile_id' => (string)$profile_id], ['$unset' => ['profile_id' => 1, 'profile_name' => 1]], []);
                            $bulkObj = $marketplaceContainer->BulkWrite($bulkOpArray, ['w' => 1]);
                            $returenRes = [
                                'acknowledged' => $bulkObj->isAcknowledged(),
                                'inserted' => $bulkObj->getInsertedCount(),
                                'modified' => $bulkObj->getModifiedCount(),
                                'matched' => $bulkObj->getMatchedCount()
                            ];
                        }
                    }
                } catch (Exception $e) {
                    return ['success' => false, 'message' => $e->getMessage()];
                }
            }
        }

        return ['success' => true, 'message' => "Template Saved."];
    }

    public function saveProfile($data, $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $profile_id = false;
        $filterforQuery = [ "user_id" => (string)$user_id, "query.query" => $data['data']['prepareQuery']['query'] ];


        if(isset($data['data']['profile_id'])){
            $profile_id = $data['data']['profile_id'];
            $filterforQuery["profile_id"] = ['$ne' => (string)$profile_id];
        }

        $matchingProfileFound = $this->di->getObjectManager()->get('\App\Ebay\Components\Profile\Helper')->getmatchingProfilebyquery($filterforQuery, $user_id);
        if(count($matchingProfileFound)){
            return ["success" => false, "code" => "duplicate_query", "message" => "Some profiles already contain this query, kindly add accounts to that profile.", "matching_profiles" =>  $matchingProfileFound];
        }

        $marketplace = $data['data']['marketplace'];
        $formattedData = $this->reformatforSaveProfileData($data, $user_id, $profile_id);
        $finaldata = [
            '$set' => $formattedData
        ];
        $filter = [];
        if($profile_id) {
            $filter = [
                'profile_id' => (string)$profile_id,
            ];
        }

        $options = ['$upsert' => true];
        if($profile_id) {
            $this->di->getObjectManager()->get('\App\Ebay\Components\Profile\DB')->init()->update($filter, $finaldata, $options);
        }else{
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $profile_id = $mongo->getCounter('profile_id');
            $formattedData['profile_id'] = (string)$profile_id;
            $this->di->getObjectManager()->get('\App\Ebay\Components\Profile\DB')->init()->insertMany([$formattedData]);
        }

        $getProductids = $this->di->getObjectManager()->get('\App\Ebay\Components\Product\Helper')->getProductsByQuery(["query" => $formattedData['query']['query']], false, false, ['source_product_id']);
        $profileassignData = [];
        $productIds = [];
        if($getProductids['success'] && count($getProductids['rows'])){
            foreach ($getProductids['rows'] as $value){
                if(!in_array((string)$value['source_product_id'], $productIds)) {
                    $productIds[] = (string)$value['source_product_id'];
                }
            }

            $profileassignData = [
                (string)$profile_id => $formattedData['name']
            ];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('product_container');
            $collection->updateMany(['profile_id' => (string)$profile_id ],  [ '$set' => [ "profile" => ["profile_id" => "", "profile_name" => ""] ] ], []);
            $collection->updateMany(['source_product_id' => ['$in' => $productIds] ],  [ '$set' => [ "profile" => $profileassignData] ], []);
            $this->di->getObjectManager()->get("\App\\".ucfirst((string) $marketplace)."\\Components\Product\Db")->init()->updateMany(['profile_id' => (string)$profile_id], ['$set' => ["profile_id" => "", "profile_name" => ""]], []);
            foreach ($productIds as $productId) {
                $this->di->getObjectManager()->get("\App\\" . ucfirst((string) $marketplace) . "\\Components\Product\Db")->init()->updateMany(['source_product_id' => $productId], ['$set' => ['source_product_id' => $productId, "profile_id" => (string)$profile_id, "profile_name" => $formattedData['name']]], ['upsert' => true]);
            }
        }

        return ['success' => true, 'message' => "Profile data saved"];
    }

    public function reformatforSaveProfileData( $data, $user_id = false, $profile_id = false ){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $presavedSettings = [];
        if($profile_id){
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $profile_presaved_data = $this->di->getObjectManager()->get('\App\Amazon\Components\Profile\DB')->init()->findOne(['profile_id' => (string)$profile_id], $options);
            if($profile_presaved_data){
                $presavedSettings = $profile_presaved_data['settings'];
            }
        }

        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $recievedData = $data['data'];
        $marketplacerecieved = $recievedData['marketplace'];
        $profile_name = $recievedData['name'];
        $presavedSettings["{$marketplacerecieved}"] =  $recievedData['settings'];
        $queryDetails = $recievedData['prepareQuery'];
        $profile_data_save = [
            "user_id" => (string)$user_id,
            "name" => $profile_name,
            "query" => $queryDetails,
            "category_id" => "",
            "settings" => $presavedSettings,
        ];
        $site_wise_settings = [];
        foreach ($presavedSettings as $marketplace => $settings_recieved){
            foreach ($settings_recieved as $shop_id => $settings){
                $shop = $userDetails->getShop($shop_id, $user_id);
//                $site_key = $marketplace === 'ebay'? $commonHelper->getGlobalID($shop['warehouses'][0]['site_id']): $shop['warehouses'][0]['region']."-".$shop['warehouses'][0]['seller_id']."-".$shop['warehouses'][0]['marketplace_id'][0] ;
                $site_key = $marketplace;
                $warehouses_to_save = [];
                $settings_data_to_save = [];

                foreach ($settings as $settingType => $Mainsetting){
                    if($settingType !== "shopify_warehouse"){
                        foreach ($Mainsetting as $settingName => $setting){
                            $settings_data_to_save[$settingName."_setting"] = [
                                "data_type" => "template",
                                "id" => $setting
                            ];
                        }
                    }else{
                        foreach ($Mainsetting as $shopifywarehouseId){
                            $warehouses_to_save["{$shopifywarehouseId}"] = [
                                "warehouse_id" => $shopifywarehouseId
                            ];
                        }
                    }
                }

                $site_wise_settings[$site_key] = [
                    "data" => [],
                    "shops" => [
                        "{$shop_id}" => [ "warehouses" =>
                            [
                                "{$shop_id}" => [
                                    "shop_id" => $shop_id,
                                    "warehouse_id" => $shop_id,
                                    "data" => $settings_data_to_save,
                                    "sources" => [
                                        "shopify" => [
                                            "warehouses" => $warehouses_to_save
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
            }
        }

        $profile_data_save['targets'] = $site_wise_settings;
        return $profile_data_save;
    }

    public function sitewiseSettings(){

    }

    public function getConfigurationData($reqData, $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $getFilterData = $this->getfilterdata($user_id);
        return ['success' => true, 'data' => ['filters' => $getFilterData], 'message' => 'Configuration data fetched'];
    }

    public function getRemoteDatabyShopId($remoteShopId = '', $details = []){
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', true)
            ->call('connected-accounts',[],['remote_shop_id' => [$remoteShopId] ], 'GET');

        $this->di->getLog()->logContent('user_id: '.$this->di->getUser()->id, 'info', 'TokenData.log');
        $this->di->getLog()->logContent('Response : '.json_encode($remoteResponse), 'info', 'TokenData.log');
        $returndata = [];
        if($remoteResponse['success']){
            $remoteShops = $remoteResponse['data'];
            foreach ($remoteShops as $key => $remoteshop){
                if((string)$remoteshop['_id'] == (string)$remoteShopId){
                    foreach ( $details as $key){
                        $returndata[$key] = $remoteshop[$key];
                    }
                }
            }
        }

        return $returndata;
    }

    public function getOnboardingChecklist($user_id = false)
    {
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $activeAccount = false;
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        // $shops = $user_details->getConfigByKey('shops', $user_id);
        $shops = $user_details->getUserDetailsByKey('shops', $user_id);
        $shops = $shops ?: [];
        foreach ($shops as $shop){
            if($shop['marketplace'] === 'amazon') {
                foreach ($shop['warehouses'] as $value) {
                    if ($value['status'] == 'active') {
                        $activeAccount = true;
                        break;
                    }
                } 
            }
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("configuration");
        $result = $collection->find(
            ['user_id' => (string)$user_id],
            [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
                "projection" => ["data.order_settings.settings_enabled" => 1, "data.inventory_settings.settings_enabled" => 1, "data.pricing_settings.settings_enabled" => 1, "data.product_settings.settings_enabled" => 1, "data.currency_settings.settings_enabled" => 1, "_id" => 0]
            ]
        )->toArray();
        $checkList = isset($result[0]) ? $result[0]['data'] : $result['data'];
        $checkList['account_settings'] = ['settings_enabled' => $activeAccount];
        return ['success' => true, "message" => $checkList];
    }

    public function getGlobalSettings( $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("configuration");
        $result = $collection->find(
            ['user_id' => (string)$user_id],
            [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
                "projection" => ["data" => 1]
            ]
        )->toArray();
        return ['success' => true, "message" => "Settings fetched", "data" => $result[0] ?? [] ];
    }

    public function saveGlobalSettings($data = [], $user_id = false)
    {
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $saveData["user_id"] = (string)$user_id;
        if (isset($data['type'])) {
            $saveData["data.".$data['type']] = $data["data"];
        } else {
            foreach ($data['data'] as $key => $value) {
                $saveData["data.".$key] = $value;
            }
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("configuration");
        $collection->updateOne(
            ['user_id' => (string)$user_id],
            ['$set' => $saveData ],
            ["upsert" => true]
        );
        return ["success" => true, "message" => "Settings Saved."];
    }

    public function saveSyncSettings($data = [], $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("sync_settings");
        $saveData = [
            "user_id" => (string)$user_id,
            "settings" => $data["settings"]
        ];

        $collection->updateOne(
            ['user_id' => (string)$user_id],
            ['$set' => $saveData ],
            ["upsert" => true]
        );
        return ["success" => true, "message" => "Sync Settings Saved."];
    }

    /*public function deleteAccount($accountId = [], $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("user_details");
        $result = $collection->UpdateOne(
            ['user_id' => (string)$user_id],
            ['$pull' => ["shops" => [ '_id' => $accountId['id']]]]
        );
        return ['success' => true, "message" => "Account deleted successfully"];
    }*/
    public function deleteAccount($accountId = [], $user_id = false)
    {
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $shopId = $accountId['id'];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("user_details");

        $shopData = [];
        $shops = $collection->findOne(['user_id'=>(string)$user_id, 'shops._id'=>$shopId], ['projection'=>['_id' => 0, 'shops' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']]);
        foreach ($shops['shops'] as $shop) {
            if(isset($shop['_id']) && $shop['_id']==$shopId) {
                $shopData = $shop;
            }
        }

        $result = $collection->UpdateOne(
            ['user_id' => (string)$user_id],
            ['$pull' => ["shops" => [ '_id' => $accountId['id']]]]
        );

        if(isset($accountId['marketplace']) && !empty($shopData)) {
            $source_model = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode($accountId['marketplace']);
            if(method_exists($source_model, 'deleteShopData')) {
                $resp = $source_model->deleteShopData($user_id, $shopData);
            }

            return ['success' => true, "message" => "Account deleted successfully"];
        }
        return ['success' => false, "message" => "Shop not found"];
    }

    public function getSyncSettings( $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("sync_settings");
        $result = $collection->find(
            ['user_id' => (string)$user_id],
            [
                "typeMap" => ['root' => 'array', 'document' => 'array'],
                "projection" => ["settings" => 1]
            ]
        )->toArray();
        return ['success' => true, "message" => "Sync settings fetched", "data" => $result[0]];
    }

    public function getDatabyShopId($homeShopId = '', $user_id = false){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shop = $user_details->getShop($homeShopId, $user_id);
        return $shop ?? [];
    }

    public function getAllConnectedAcccounts($user_id = false, $data = []){
        if ( !$user_id ) $user_id = $this->di->getUser()->id;

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        if (!($shops = $this->getUserCache('shops',$user_id))) {
            $shops = $user_details->getUserDetailsByKey('shops', $user_id);
            $this->setUserCache('shops', $shops,$user_id);
        }

        // $shops = $user_details->getConfigByKey('shops', $user_id);

        $shops = $shops ?: [];
        $responseData = [];
        foreach ($shops as $shop){
            if($shop['marketplace'] === 'shopify'){
                $responseData[] = [ "marketplace" => $shop['marketplace'], 'id' => $shop['_id'], 'name'=> $shop['name'], 'country' => $shop['country'], 'shop_url' => $shop['domain'], 'warehouses' => $shop['warehouses'], 'shop_details' => $shop];
            } else {
                if ($shop['marketplace'] == 'amazon' && isset($data['checkMwsToken']) && $data['checkMwsToken']) {
                    $tokenGenerated = false;
                    if (isset($shop['warehouses'][0]['token_generated'])) {
                        if ($shop['warehouses'][0]['token_generated']) {
                            $tokenGenerated = true;
                        } else {
                            $tokenGenerated = false;
                        }
                    } else {
                        $remoteShopWarehouseData = $this->getRemoteDatabyShopId($shop['remote_shop_id'], ['mws_auth_token']);
                        if ($remoteShopWarehouseData && isset($remoteShopWarehouseData['mws_auth_token']) && $remoteShopWarehouseData['mws_auth_token'] != false && $remoteShopWarehouseData['mws_auth_token']!= 'false') {
                            $tokenGenerated = true;
                        }
                    }

                    if ($tokenGenerated) {
                        $responseData[] = ["marketplace" => $shop['marketplace'], 'id' => $shop['_id'], 'warehouses' => $shop['warehouses']];
                    } else {
                    $region = $shop['warehouses'][0]['region'];
                    $responseData[] = ["marketplace" => $shop['marketplace'], 'id' => $shop['_id'], 'warehouses' => $shop['warehouses'],
                        'error' => 'Mws auth Token not generated successfully.Please check if you have a primary sellercentral account.If yes then click on the link below, and copy the generated token in the given textbox.',
                        'link' => Helper::MANUAL_AUTHENTICATION_URLS[$region]];
                }
            } else {
                    $responseData[] = ["marketplace" => $shop['marketplace'], 'id' => $shop['_id'], 'warehouses' => $shop['warehouses']];
                }
            }
        }

        return ['success'=> true, 'data' => $responseData];
    }
}