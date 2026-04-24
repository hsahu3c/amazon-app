<?php

namespace App\Connector\Components\AI;

use App\Core\Models\BaseMongo;

class credits extends BaseMongo
{

    public $_user_id;

    public $_mongo_service;

    public $_mongo_click_info;

    public $_target_marketplace;

    public $_ai_service_table;

    public $_product_seo_details_table;

    public function init($data): void
    {
        if (isset($data['user_id'])) {
            $this->_user_id = $data['user_id'];
        } else {
            $this->_user_id = $this->di->getUser()->id;
        }

        $this->_target_marketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->_ai_service_table =  $mongo->getCollectionForTable('ai_services');
        $this->_product_seo_details_table =  $mongo->getCollectionForTable('product_seo_details');
    }


    /**
     *
     * @param [array] $data
     * $data = ["user_id"(optional) => "123" , "target" (optional) => ['shopId' =>'12', 'marketplace' => 'amazon']]
     * @return array
     */
    public function getCreditInfo($data)
    {
        $this->init($data);

        $ai_services = $this->getAiServiceInfo($data);

        if (isset($data['source_product_id'])) {
            $product_click_wise_info = $this->getProductWiseClicksInfo($data)['data'] ?? null;
            $ai_services['product_click_info'] = $product_click_wise_info;
        }



        return ['success' => true, 'message' => 'Fetched Successfully', 'data' => $ai_services];
    }

    public function checkIfEligbleForGPT4($aiDbData)
    {
        if (!isset($aiDbData['use_gpt_4']) || $aiDbData['use_gpt_4'] != true) {
            return ['success' => false, 'message' => 'Not Eligle to use GPT4 contact adminstrator', 'code' => 'access_denied'];
        }

        return ['success' => true];
    }


    /**
     *
     * @param [array] $data
     * $data = ["source_product_id" =>"123" , "user_id"(optional) => "123" , "target" (optional) => ['shopId' =>'12', 'marketplace' => 'amazon']]
     * @return array
     */
    public function updateCredits($data)
    {
        $this->init($data);
        $ai_services = [];
        $target_marketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();

        $check_for_source_product_id = $this->_product_seo_details_table->findOne(
            ['user_id' => $this->_user_id, 'marketplace' => $target_marketplace, 'source_product_id' => $data['source_product_id']]
        );

        if (isset($data['useEngine']) && $data['useEngine'] == 'gpt-4') {
            $ai_services = $this->_ai_service_table->findOne(
                ['user_id' => $this->_user_id, 'marketplace' => $target_marketplace]
            );

            $eligble = $this->checkIfEligbleForGPT4($ai_services);

            if ($eligble['success'] == false) {
                return $eligble;
            }
        }



        if ($check_for_source_product_id) {
            $for = $data['for'];
            if ($for == 'category_suggestions') {
                $res = $this->checkIfCategorySuggesionsAlreadyDone($data, $check_for_source_product_id['history']['category_suggestions']);
                if ($res['generate_new'] == false) {
                    return ['success' => false, 'message' => 'category suggestion with this title and description already done', 'data' => $res['data']];
                }
            }

            if ($check_for_source_product_id['click_left'][$for] <= 0) {
                return ['success' => false, 'message' => $this->di->getLocale()->_('for_clicks_not_left', [
                    'for' => $for
                ])];
            }

            return ['success' => true, 'message' => 'Product Eligable for optimization'];
        }

        $ai_services = $ai_services ? $ai_services :  $this->_ai_service_table->findOne(
            ['user_id' => $this->_user_id, 'marketplace' => $target_marketplace]
        );

        if (!$ai_services) {
            return ['success' => false, 'message' => 'Comming Soon', 'code' =>'encoded'];
        }

        if ($ai_services['credit_left'] <= 0) {

            return ['success' => false, 'message' => 'encoded', 'code' =>'encoded'];
        }

        $this->_ai_service_table->updateOne(
            ['user_id' => $this->_user_id, 'marketplace' => $target_marketplace],
            ['$inc' => ['credit_left' => -1]]
        );
        $this->initializeProductWiseClicksInfo($data);
        return ['success' => true, 'message' => 'Product Eligable for optimization, but since its new product credit will be deducted', 'credit_left' => $ai_services['credit_left'] - 1];
    }


    /**
     *
     * @param [array] $data
     * $data = ["source_product_id" =>"123" , "user_id"(optional) => "123" , "target" (optional) => ['shopId' =>'12', 'marketplace' => 'amazon']]
     * @return array
     */
    public function getProductWiseClicksInfo($data)
    {
        $this->init($data);
        $target_marketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();

        $def_search = ['user_id' => $this->_user_id, 'marketplace' => $target_marketplace, 'source_product_id' => $data['source_product_id']];
        $check_for_source_product_id = $this->_product_seo_details_table->findOne($def_search);

        return ['success' => true, 'data' => $check_for_source_product_id, 'message' => 'Fetched Successfully'];
    }


    /**
     *
     * @param [array] $data
     * $data = ["source_product_id" =>"123" , "for" => "title" , "user_id"(optional) => "123" , "target" (optional) => ['shopId' =>'12', 'marketplace' => 'amazon']]
     * @return array
     */
    public function updateProductWiseClicksInfo($data)
    {
        $this->init($data);
        $target_marketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();


        $def_search = ['user_id' => $this->_user_id, 'marketplace' => $target_marketplace, 'source_product_id' => $data['source_product_id']];
        $check_for_source_product_id = $this->_product_seo_details_table->updateOne(
            $def_search,
            ['$inc' => ["click_left." . $data['for'] => -1]]
        );

        return $check_for_source_product_id;
    }

    public function initializeProductWiseClicksInfo($data)
    {
        $this->init($data);
        $target_marketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
        // TODO add default credits in config
        $check_for_source_product_id = $this->_product_seo_details_table->insertOne(
            [
                'click_left' =>
                [
                    'title' => 100,
                    'description' => 100,
                    'generate_bullet_points' => 100,
                    'bullet_points' => 100,
                    'keywords'=>100,
                    'category_suggestions' => 100,
                    'keyword_generation' => 100,
                    'key_feature'=>100,
                    'tags'=>100,
                    'search_terms'=>100,
                    'subject_matter'=>100,
                    'target_audience'=>100,
                    'intend_use'=>100,
                    'single_bullet_point'=>100,
                ],
                'user_id' => $this->_user_id,
                'marketplace' => $target_marketplace,
                'source_product_id' => $data['source_product_id']
            ]
        );

        return $check_for_source_product_id;
    }

    public function getAiServiceInfo()
    {
        $ai_services = $this->_ai_service_table->findOne(
            ['user_id' => $this->_user_id, 'marketplace' => $this->_target_marketplace]
        );

        return $ai_services;
    }

    public function adjustProductWiseClicksInfo($data)
    {
        $this->init($data);

        if (!isset($data['for'], $data['clicks'])) {
            return ['success' => false, 'message' => 'For or credit info missing', 'code' => 'data_missing'];
        }

        $for = $data['for'];
        $this->_product_seo_details_table->updateOne(['user_id' => $this->_user_id, 'marketplace' => $this->_target_marketplace], ['$set' => ["click_left.{$for}" => (int)$data['clicks']]]);

        return ['success' => true, 'message' => 'data updated'];
    }

    public function adjustCredits($data)
    {
        $this->init($data);

        if (!isset($data['credit'])) {
            return ['success' => false, 'message' => 'credit info not send', 'code' => 'data_missing'];
        }

        $this->_ai_service_table->updateOne([['user_id' => $this->_user_id, 'marketplace' => $this->_target_marketplace]], ['$set' => ['credit_left' => (int)$data['credit']]], ['upsert' => true]);

        return ['success' => true, 'message' => 'data updated'];
    }

    public function checkIfCategorySuggesionsAlreadyDone($promtData, $historyData)
    {
        foreach ($historyData as $v) {
            if (($v['prompt']['title'] == $promtData['title'])  && ($v['prompt']['description'] == $promtData['description'])) {
                return ['generate_new' => false, 'data' => $v['result']['optimized_similar_result'] ?? $v['result']];
            }
        }

        return ['generate_new' => true, 'data' => $v['result']['optimized_similar_result'] ?? $v['result']];
    }

    public function saveEnableConfig($enable = 'approve',$data)
    {
        $config = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');;
        if(isset($data['target']) && isset($data['source'])){
            return $config->saveConfigData([
                'source'=>$data['source'],
                'target'=>$data['target'],
                'user_id'=>$this->_user_id,
                'data' => [
                    ['data'=> [ 'super_charge_listing'=>"approve" ], 'group_code'=> "app_beta_features" ]
                    ]]);
        }

        return $config->saveDefaultSettings(['data' => [['group_code' => 'app_beta_features', 'data' => ['super_charge_listing' => $enable]]]]);
    }


    public function enableAiServiceBeta($data)
    {

        $this->init($data);
        if ($data['enable'] == "disapprove") {
            $this->saveEnableConfig('disapprove',$data);
            return ['success' => true, 'message' => 'Not Enabled'];
        }

        $credit_info = $this->getAiServiceInfo();

        if ($credit_info) {
            return ['success' => false, 'message' => 'AI services Already Enabled', 'code' => 'wrong'];
        }

        $enable = $this->_ai_service_table->insertOne(
            ['user_id' => $this->_user_id, 'marketplace' => $this->_target_marketplace, 'credit_left' => 100]
        );

        if ($enable->getInsertedId()) {

            $this->saveEnableConfig('approve',$data);

            return ['success' => true, 'message' => 'Enabled Successfully, ready to use'];
        }

        return ['success' => false, 'message' => 'Failed to enable please contact support'];
    }
}
