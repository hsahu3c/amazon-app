<?php
namespace App\Frontend\Components;

use App\Core\Components\Base;
class MigrationHelper extends Base
{

    public function migrateConfig($data)
    {

        //TODO
        // change source to single account
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $user_services = $mongo->getCollectionForTable("user_details");
        $configDB = $mongo->getCollectionForTable("configuration");

        if ( !isset($data['user_id']) ) {
            return ['success' => false, 'message' => 'user_id is missing in data'];
        }

        $old_user_id = $data['user_id'];
        $new_user_id = $data['user_id'];

        $user_details = $user_services->findOne(['user_id' => $old_user_id]);
        $config = $configDB->findOne(['user_id' => $old_user_id]);

        if ( !isset($user_details['shops']) || count($user_details) > 0 ) {
            return ['success' => false, 'message' => 'user_id not found'];
        }

        $source = [];
        $target = [];

        $saveConfig = [];

        foreach ($user_details['shops'] as $shop) {
            if ( $shop['marketplace'] == 'shopify' ) {
                $source = $shop;
            } else if ( $shop['marketplace'] == 'amazon' ) {
                $target = $shop;
            } else {
                return [
                    'success' => false, 'message' => 'given marketplace not found'
                ];
            }
        }

        if ( !isset($config['data']) ) {
            return [
                'success' => false, 
                'message' => 'no Config Data Found'
            ];
        } 

        if ( isset($config['data']['order_settings']) ) {
            foreach($config['data']['order_settings'] as $key => $value ) {
                $newKey = $key;

                $newValue= $value;

                switch($key) {
                    case 'tagsInOrder' : 
                        $newKey = 'tagsInOrder'; 
                        $newValue = [
                            "seller_id" => in_array('seller_id', $value),
                            "amazon_order_id" => in_array('amazon_order_id', $value),
                            "amazon" => in_array('amazon', $value)
                        ];
                        break;
                    default:  $newKey = $key;
                }

                $saveConfig[] = [
                    'source_shop_id' => $source['_id'],
                    'target_shop_id' => $target['_id'],
                    'key' => $newKey,
                    'value' => $newValue,
                    'user_id' => $new_user_id,
                    "group_code" => "order_settings",
                    "app_tag" => $data['app_tag'] ?? "default",
                ];
            }
        }

        if ( isset($config['data']['inventory_settings']) ) {
            foreach($config['data']['inventory_settings'] as $key => $value ) {
                $newKey = $key;

                $newValue= $value;

                switch($key) {
                    case 'test' : 
                        break;
                    default:  $newKey = $key;
                }

                $saveConfig[] = [
                    'source_shop_id' => $source['_id'],
                    'target_shop_id' => $target['_id'],
                    'key' => $newKey,
                    'value' => $newValue,
                    'user_id' => $new_user_id,
                    "group_code" => "default_inventory_settings",
                    "app_tag" => $data['app_tag'] ?? "default",
                ];
            }
        }

        if ( isset($config['data']['pricing_settings']) ) {
            foreach($config['data']['pricing_settings'] as $key => $value ) {
                $newKey = $key;

                $newValue= $value;

                switch($key) {
                    case 'tagsInOrder' : 
                        break;
                    default:  $newKey = $key;
                }

                $saveConfig[] = [
                    'source_shop_id' => $source['_id'],
                    'target_shop_id' => $target['_id'],
                    'key' => $newKey,
                    'value' => $newValue,
                    'user_id' => $new_user_id,
                    "group_code" => "default_pricing_settings",
                    "app_tag" => $data['app_tag'] ?? "default",
                ];
            }
        }

        if ( isset($config['data']['product_settings']) ) {
            foreach($config['data']['product_settings'] as $key => $value ) {
                $newKey = $key;

                $newValue= $value;

                switch($key) {
                    case 'tagsInOrder' : 
                        break;
                    default:  $newKey = $key;
                }

                $saveConfig[] = [
                    'source_shop_id' => $source['_id'],
                    'target_shop_id' => $target['_id'],
                    'key' => $newKey,
                    'value' => $newValue,
                    'user_id' => $new_user_id,
                    "group_code" => "default_product_settings",
                    "app_tag" => $data['app_tag'] ?? "default",
                ];
            }
        }

        $mongo = $this->di->getLog()->logContent('data : ' . json_encode($data),'info','config-migration.log');
        $mongo = $this->di->getLog()->logContent('config : ' . json_encode($saveConfig),'info','config-migration.log');
        $mongo = $this->di->getLog()->logContent('x.x.x.x.x.x.x.x.x.x.x','info','config-migration.log');

    
        $objectManager = $this->di->getObjectManager();
        $configObj = $objectManager->get('\App\Core\Models\Config\Config');
        $configObj->setUserId($new_user_id);
        foreach ($saveConfig as $config ) {
            $configObj->setConfig($config);
        }

        return true;
    }
}