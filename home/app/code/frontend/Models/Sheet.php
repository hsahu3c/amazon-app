<?php

namespace App\Frontend\Models;

use App\Core\Models\Base;
use Exception;
/**
+---------------------+-------------+------+-----+-------------------+----------------+
| Field               | Type        | Null | Key | Default           | Extra          |
+---------------------+-------------+------+-----+-------------------+----------------+
| id                  | int(11)     | NO   | PRI | NULL              | auto_increment |
| user_id             | int(11)     | NO   |     | NULL              |                |
| name                | varchar(64) | NO   |     | NULL              |                |
| email               | varchar(64) | NO   |     | NULL              |                |
| shopify_url         | varchar(255)| NO   |     | NULL              |                |
| fb_id               | varchar(64) | NO   |     | NULL              |                | // App\Facebookhome\Components\Shop/Utility
| shopify_plan        | varchar(32) | NO   |     | NULL              |                |
| product_uploaded    | int(11)     | NO   |     | NULL              |                |
| product_imported    | int(11)     | NO   |     | NULL              |                |
| installation_status | varchar(5)  | NO   |     | yes               |                |
| installation_date   | timestamp   | NO   |     | CURRENT_TIMESTAMP |                |
| uninstallation_data | timestamp   | YES  |     | NULL              |                |// App\Shopifyhome\Components\core/hook
| facebok_ad          | varchar(31) | NO   |     | NULL              |                |
| marketplace_status  | varchar(32) | NO   |     | NULL              |                |// App\Facebookhome\Components\Shop/Utility
| order_count         | int(11)     | NO   |     | NULL              |                |
| onborading_status   | varchar(32) | NO   |     | NULL              |                |
| CATEGORY            | text        | NO   |     | NULL              |                |
| store_type          | varchar(50) | YES  |     | Fb/Insta          |                |
+---------------------+-------------+------+-----+-------------------+----------------+
 */

const tableFields = [
    'name',
    'email',
    'shopify_url',
    'fb_id',
    'shopify_plan',
    'product_uploaded',
    'product_imported',
    'installation_status',
    'installation_date',
//    'uninstallation_data',
    'facebok_ad',
    'marketplace_status',
    'order_count',
    'onborading_status',
    'CATEGORY'
];


class Sheet extends Base
{
    protected $table = 'Sheet';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }

    private function getFormFillUsers(): void {
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', 'true')
            ->call('/fbapproval',[],['filters' => ['page' => 1]], 'GET');

        foreach ( $remoteResponse['data'] as $value ) {

            if ( !isset($value['shop_url']) || $value['shop_url'] == '' ) continue;

            $insertData = [
                'name' => $value['info']['name'] ?? '',
                'email' => $value['info']['email'] ?? '',
                'shopify_url' => $value['shop_url'] ?? '',
                'onborading_status' => $value['info']['status'],
                'installation_status' => 'Not Installed'
            ];

            echo $value['info']['name'] . PHP_EOL;

            $this->saveAccountData($value['shop_url'], $insertData);
        }
    }

    public function sync() {

        return true;

        $this->di->getLog()->logContent('GO' , 'info', 'syncrun.log');

        $this->getFormFillUsers();

        $user_details = $this->getMongoUserData();

        foreach ( $user_details as $value ) {

            $storeType = 'Fb/Insta Marketplace';

            if ( isset($value['shops']['form_details']['catalog_sync_only'])
                && $value['shops']['form_details']['catalog_sync_only'] === 1 ) {
                $storeType = 'Catalog Sync';
            }

            $insertData = [
                'user_id' => $value['user_id'],
                'name' => $value['shops']['shop_owner'] ?? '',
                'email' => $value['shops']['email'] ?? '',
                'shopify_url' => $value['shops']['myshopify_domain'] ?? '',
                'shopify_plan' => $value['shops']['plan_display_name'] ?? '',
                'installation_status' => 'Installed',
                'store_type' => $storeType,
            ];

            if ( $insertData['user_id'] == 1539 ) {
                print_r($insertData);die;
            }
            echo $insertData;

//            if (preg_match('/[^A-Za-z0-9 \w]/', $insertData['name'])) // '/[^a-z\d]/i' should also work.
//            {
//                $insertData['name'] = 'NON_ENGLISH';
//                echo $value['user_id'] . PHP_EOL;
//            }

            $insertData += $this->getUserAllMongoDetails($value['user_id']);

            $insertData += $this->getUserAllMySqlDetails($value['user_id']);

            if ( isset($value['fb_user_id'][0]) && $value['fb_user_id'][0] != '' ) {
                $insertData['fb_id'] = $value['fb_user_id'][0];
            } else if ( isset($value['fb_user_id'][1]) && $value['fb_user_id'][1] != '' ) {
                $insertData['fb_id'] = $value['fb_user_id'][1];
            }

            $this->saveAccountData($value['shops']['myshopify_domain'], $insertData);
        }

    }

    public function syncSingleUser($user_id, $additional_array = []) {
        $user_details = $this->getMongoUserData($user_id);

        $this->di->getLog()->logContent('User_id : ' . $user_id ,'info','sheet.log');

        $value = $user_details[0];

        if ( !isset($value) ) return false;

        $insertData = [
            'user_id' => $value['user_id'],
            'name' => $value['shops']['shop_owner'] ?? '',
            'email' => $value['shops']['email'] ?? '',
            'shopify_url' => $value['shops']['myshopify_domain'] ?? '',
            'shopify_plan' => $value['shops']['plan_display_name'] ?? '',
        ];
        $insertData += $this->getUserAllMongoDetails($value['user_id']);
        $insertData += $this->getUserAllMySqlDetails($value['user_id']);
        $insertData += $additional_array;


        $this->di->getLog()->logContent('prepare : ' . json_encode($insertData) ,'info','sheet.log');

        $res = $this->saveAccountData($value['shops']['myshopify_domain'], $insertData);

        $this->di->getLog()->logContent('res : ' . json_encode($res) ,'info','sheet.log');

        return true;
    }

    public function getUserAllMongoDetails($user_id) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');

        $container = $mongo->getCollectionForTable('product_container_' . $user_id)->count();

        $upload = $mongo->getCollectionForTable('product_upload_' . $user_id)->count(['successfully_updated_once' => true]);

        $order = $mongo->getCollectionForTable('order_container_' . $user_id)->count();

        $default = $mongo->getCollectionForTable('default_profiles');
        $cat = $default->findOne([ "user_id" => $user_id, "targetCategory" => [ '$exists' => true ]]);

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $facebook_details = $user_details->getDataByUserID($user_id, 'facebook');

        return [
            'product_imported' => $container,
            'product_uploaded' => $upload,
            'order_count' => $order,
            'CATEGORY' => $cat['targetCategory'] ?? '',
            'marketplace_status' => $facebook_details['warehouses'][0]['setup_status'][0]['marketplace_approval_status'] ?? '',
        ];

    }

    public function getUserAllMySqlDetails($user_id) {

        $res = [];

        $config = $this->di->getObjectManager()->create('\App\Core\Models\User\Config');

        $user = $this->di->getObjectManager()->create('\App\Core\Models\User');

        $user = $user::findFirst("id='{$user_id}'");

        if ($user) {
            $user = $user->toArray();
            if ( isset($user['created_at']) ) {
                $res['installation_date'] = $user['created_at'];
            }
        }


        $config = $config->getConfigByPath($user_id, '/App/User/Step/ActiveStep');

        if ( $config ) {
            $status = match ($config['value']) {
                "1" => 'Step 2, ..Business Manager',
                "2" => 'Step 3, ..Authenticate the Shop',
                "3" => 'Step 4, ..Mapped the Warehouse',
                "4" => 'Step 5, ..Attribute Mapped',
                "5" => 'All the Step Completed',
                default => 'Step 1 ,Waiting to Connect',
            };
            $res['onborading_status'] = $status;
        } else {
            $res['onborading_status'] = 'Step 1 ,Waiting to Connect';
        }
        
        return $res;

    }

    public function getMongoUserData($user_id = false) {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');

        $aggregate = [];

        $aggregate[] = ['$match' => ['user_id' => "1539"]];

        $aggregate[] = ['$addFields' => [
            'fb_user_id' => '$shops.fb_user_id',
        ]];

        $aggregate[] = ['$unwind' => '$shops'];

//        $aggregate[] = ['$unwind' => '$fb_user_id'];

        if ( $user_id ) {
            $aggregate[] = ['$match' => ['user_id' => $user_id]];
        }

        $aggregate[] = ['$match' => ['shops.marketplace' => 'shopify']];

        $aggregate[] = ['$project' => [
            'user_id' => 1,
            'shops.shop_owner' => 1,
            'shops.email' => 1,
            'shops.plan_display_name' => 1,
            'shops.myshopify_domain' => 1,
            'shops.phone' => 1,
            'shops.remote_shop_id' => 1,
            'shops.profile_id' => 1,
            'shops.marketplace' => 1,
            'shops.form_details' => 1,
            'fb_user_id' => 1,
        ]];

        $data = $collection->aggregate($aggregate)->toArray();

        return json_decode(json_encode($data), true);
    }

    private function checkVar($str): bool {
        if ( isset($str) && $str != '' && $str != null ) return true;
        
        return false;
    }

    public function saveAccountData($user_id, $details)
    {
        $this->initialize();

        $shopModel = new \App\Frontend\Models\Sheet;

        $accountExist = $shopModel::findFirst([
            "shopify_url='{$user_id}'"
        ]);

        if ($accountExist) {
            foreach ( $accountExist as $key => $value ) {
                try {
                    if ( isset($details[$key]) && $this->checkVar(($details[$key])) ) {
                        $accountExist->$key = $details[$key];
                    } else if ( array_search($key,\TABLEFIELDS) !== false
                        && !$this->checkVar($accountExist->$key) ) {
                        try {
                            $accountExist->$key = 'N/A';
                        } catch (Exception $e ){
                            echo $e->getMessage();
                        }
                    }
                }catch (Exception $e ){
                    echo $e->getMessage();
                }
            }
            
            try {
                $status = $accountExist->save();
            }catch (Exception $e ){
                $this->di->getLog()->logContent('SAVE ERROR : ' . $e->getMessage(), 'critical', 'syncSheet.log');
                //echo $e->getMessage(); die;
                return ['message' => $e->getMessage(), 'success' => false];
            }

            if ($status) {
                return ['success' => true, 'message' => 'Sheet data Config Updated'];
            }
            echo "FAILED FOR : " . $user_id . PHP_EOL;
        } else {
//            $shopData = [
//                'user_id' => $details['user_id'] ?? '',
//                'name' => $details['name'] ?? '',
//                'email' => $details['email'] ?? '',
//                'shopify_url' => $details['shopify_url'] ?? '',
//                'shopify_plan' => $details['shopify_plan'] ?? '',
//                'product_imported' => $details['product_imported'] ?? 0,
//                'product_uploaded' => $details['product_uploaded'] ?? 0,
//            ];

            if ( $details['user_id'] == 1539 ) {
                print_r($details);
            }

            $shopModel->set($details);
            try {
                $status = $shopModel->save();
            }catch (Exception $e ){
                $this->di->getLog()->logContent('SAVE ERROR : ' . $e->getMessage(), 'critical', 'syncSheet.log');
                //echo $e->getMessage(); die;
                return ['message' => $e->getMessage(), 'success' => false];
            }

            if ($status) {
                return ['success' => true, 'message' => 'Sheet data Config Updated'];
            }
        }
        
        return ['success' => false, 'message' => 'Unable To Save Data'];
    }


}