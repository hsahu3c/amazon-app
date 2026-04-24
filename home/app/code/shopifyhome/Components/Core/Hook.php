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

namespace App\Shopifyhome\Components\Core;

use App\Shopifyhome\Components\Shop\Shop;
use App\Core\Models\SourceModel as BaseSourceModel;

class Hook extends BaseSourceModel
{

    /**
     * for more info follow this link
     * https://docs.google.com/document/d/1ldp3tRbLcYFfZ-KcPa8QPtLAVoinP1RCYfpGumLPBB0/edit
     */

    /**
     * This function will trigger when client uninstall the app
     * This one is responsible only for marking user as UNINSTALL
     */
    public function TemporarlyUninstall($user_id = false)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');

        $userDetails = $collection->findOne(['user_id' => $user_id]);

        $this->di->getLog()->logContent('user_id : ' . $this->di->getUser()->id .', res : ' .json_encode($userDetails),'info','tempuninstalluser.log');
        /**
         * Checking if user has not re-install the app,
         * we are checking if the access token is re-create or not, 
         * if yes the the client has re0install
         */
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify')
            ->call('/shop', [], ['shop_id' => $userDetails['shops'][0]['remote_shop_id']], 'GET');
            $this->di->getLog()->logContent('user_id : ' . $this->di->getUser()->id .', res : ' .json_encode($remoteResponse),'info','tempuninstalluser.log');
        if (isset($remoteResponse['data']['id'])) {
            // Re-Create all the webhook
            $this->di->getObjectManager()
                ->get(\App\Shopifyhome\Models\SourceModel::class)
                ->registerWebhooks('amazon_sales_channel');
        } else {
            if (isset($userDetails['shops'][1]['remote_shop_id'])) {
                $this->removeFromDynomo($userDetails['shops'][1]['remote_shop_id']);
            }

            $days = $this->getPlanRemainingDays($user_id);
            // Marking as uninstall only
            $collection->updateOne(['user_id' => $user_id], ['$set' => [
                'uninstall_status' => 'ready_to_uninstall',
                'uninstall_date' => date('c'),
                'uninstall_info' => [
                    'plan_days_remaining' => $days
                ]
            ]]);
        }

        return true;
    }

    public function getPlanRemainingDays($user_id)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('active_plan');
        $plans = $collection->find(['user_id' => $user_id])->toArray();
        $days = 0;
        if ($plans && count($plans) > 0) {
            foreach ($plans as $plan) {
                $activeDate = $plan['activated_at'];
                $activeDate = explode(",", (string) $activeDate)[0];
                $now = time(); // or your date as well
                $your_date = strtotime($activeDate);
                $datediff = $now - $your_date;

                $datediff = round($datediff / (60 * 60 * 24));
                $validity = $plan['validity'];

                $daysRemaing = $validity - $datediff;
                if ($daysRemaing > 0) {
                    return $daysRemaing;
                }
            }
        }

        return 0;
    }

    /**
     * after 48h shopify trigger shop eraser webhook
     * @ that time we will mark it as 'shop_erase' => true,
     * it will then wait for 28days to fully remove the client data
     */
    public function ShopEraserMarking($sqsData = [])
    {
        if (!isset($sqsData['data']['shop_domain'])) {
            $this->di->getLog()->logContent('Res : ' . json_encode($sqsData), 'info', 'ShopEraserError.log');
            return true;
        }

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('user_details');
        $collection->updateOne(
            ['shops.myshopify_domain' => $sqsData['data']['shop_domain']],
            ['$set' => [
                'shop_erase' => true,
            ]]
        );
        return true;
    }

    /**
     * As the name suggest, it will check if the user date has reached 28 days and then
     * trigger the {shopErarser} function
     */
    public function CheckForIfWeShouldErase()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');

        $date28Days = date('c', strtotime('-27 days'));

        $users = $collection->find([
            'shop_erase' => true,
            'uninstall_date' => ['$lte' => $date28Days],
            'uninstall_status' => ['$exists' => true]
        ])
            ->toArray();
        $user_name = [];
        foreach ($users as $user) {
            $user_name[] = $user['username'];
            $this->shopErarser($user['user_id']);
        }

        $this->di->getLog()->logContent('Erasing this clients : ' . json_encode($user_name), 'info', 'storeEraser.log');
        return true;
    }

    /**
     * This funtion is responsible for removing all the client data
     * it will tigger after 28 days by another funtion {CheckForIfWeShouldErase},
     */
    public function shopErarser($user_id = false)
    {
        if (!$user_id) {
            $user_id = $this->di->getUser()->id;
        }

        $clientData = [
            'user_id' => $user_id,
            'username' => $this->di->getUser()->username,
            'email' => $this->di->getUser()->email,
            'update_at' => date('c'),
            'uninstall_date' => $this->di->getUser()->uninstall_date
        ];

        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $target = $this->di->getObjectManager()->get(Shop::class)
            ->getUserMarkeplace();
        $user_details_shopify = $user_details->getDataByUserID($user_id, $target);
        $user_details_amazon = $user_details->getDataByUserID($user_id, 'amazon');

        $query = ['user_id' => (string)$user_id];

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('product_container');
        $clientData['product_count'] = $collection->count($query);
        $collection->deleteMany($query);

        $this->OrderEraser((string)$user_id);

        $collection = $mongo->getCollectionForTable('amazon_product_container');
        $clientData['amazon_product_container'] = $collection->count($query);
        $collection->deleteMany($query);

        $collection = $mongo->getCollectionForTable('queued_tasks');
        $collection->deleteMany($query);


        $collection = $mongo->getCollectionForTable('configuration');
        $collection->deleteMany($query);

        $collection = $mongo->getCollectionForTable('profiles');
        $clientData['profiles_count'] = $collection->count($query);
        $collection->deleteMany($query);

        $collection = $mongo->getCollectionForTable('profile_settings');
        $collection->deleteMany(['merchant_id' => (string)$user_id]);

        $collection = $mongo->getCollectionForTable('amazon_listing');
        $clientData['amazon_listing_count'] = $collection->count($query);
        $collection->deleteMany($query);

        $collection = $mongo->getCollectionForTable('user_details');
        $clientData['account_count'] = count($collection->findOne($query)['shops'] ?? []);
        $collection->deleteOne($query);

        $collection = $mongo->getCollectionForTable('transaction_log');
        $clientData['transaction_log'] = $collection->find($query)->toArray();
        $collection->deleteMany($query);

        $collection = $mongo->getCollectionForTable('active_plan');
        $clientData['active_plan'] = $collection->find($query)->toArray();
        $collection->deleteMany($query);

        // if ( isset($user_details_amazon['remote_shop_id']) ) {
        //     $this->removeFromDynomo($user_details_amazon['remote_shop_id']);
        // }

        // Set the user data in  new table

        $collection = $mongo->getCollectionForTable('uninstall_users');
        $collection->updateOne(['user_id' => $user_id], [
            '$set' => $clientData,
            '$setOnInsert' => ['created_at' => date('c')]
        ], ['upsert' => true]);

        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $shopifyUninstall = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init($target, 'true')
            ->call('app-shop', [], ['shop_id' => $user_details_shopify['remote_shop_id']], 'DELETE');

        return true;
    }

    /**
     * it will remove the the field which are not required
     */
    public function OrderEraser($user_id)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');

        $cursor = $sqsData['data']['cursor'] ?? 0;
        $limit = $sqsData['data']['limit'] ?? 0;

        $unserKeys = [
            "region" => true,
            "country" => true,
            "url" => true,
            "subtotal_price" => true,
            "total_weight" => true,
            "taxes_included" => true,
            "source_error_message" => true,
            "tags" => true,
            "buyer_accept_marketing" => true,
            "buyer_note" => true,
            "billing_address" => true,
            "shipping_address" => true,
            "payment_method" => true,
            "client_details" => true,
            "shipping_details" => true,
            "ship_by_date" => true,
            "fulfillment_status" => true,
            "tax_lines" => true,
            "shipping_cost_details" => true,
            "total_tax" => true,
            "total_discounts" => true,
            "source_order_data" => true,
            "seller_id" => true,
            "target_error_message" => true,
            "target_errors" => true,
            "target_status" => true,
            "imported_at" => true,
            "shopify_order_name" => true,
            "target_order_data" => true,
        ];

        $i = 0;
        while ($i <= 10) {
            $unserKeys["line_items." . $i . ".tax_lines"] = true;
            $unserKeys["line_items." . $i . ".fulfillment_service"] = true;
            $unserKeys["line_items." . $i . ".total_discount"] = true;
            $unserKeys["line_items." . $i . ".fulfillment_service"] = true;
            $i++;
        }

        $res = $collection->updateMany(['user_id' => $user_id], ['$unset' => $unserKeys, '$set' => ['uninstalled' => true]]);
        return $res->getModifiedCount();
    }

    public function removeFromDynomo($remote_shop_id)
    {
        // $dynamoObj = $this->di->getObjectManager()->get(Dynamo::class);
        // $dynamoObj->setTable('amazon_inventory_shop');
        // $dynamoObj->delete([
        //     'remote_shop_id' => ['S' => (string)$remote_shop_id,]
        // ]);

        // $dynamoObj->setTable('amazon_inventory_response');
        // $dynamoObj->delete([
        //     'remote_shop_id' => ['S' => (string)$remote_shop_id,]
        // ]);

        // $dynamoObj->setTable('amazon_inventory_mgmt');
        // $dynamoObj->delete([
        //     'shop_id' => ['S' => (string)$remote_shop_id,]
        // ]);

        // $dynamoObj->setTable('amazon_order_sync_shops');
        // $dynamoObj->delete([
        //     'id' => ['S' => (string)$remote_shop_id,]
        // ]);

        return true;
    }

    public function checkUser_CatalogSync($userid, $shop_url)
    {
        $mongoCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection->setSource('user_details');

        $phpMongoCollection = $mongoCollection->getPhpCollection();
        $result = $phpMongoCollection->find(
            ['shops.myshopify_domain' => (string)$shop_url]
        )->toArray();
        $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $shop_details = $user_details->getDataByUserID($result[0]['user_id'], 'shopify') ?? [];
        if (isset($shop_details['form_details']['catalog_sync_only']) && $shop_details['form_details']['catalog_sync_only'] == 1) {
            return true;
        }
    }
}
