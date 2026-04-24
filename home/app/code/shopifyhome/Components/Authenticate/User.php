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

namespace App\Shopifyhome\Components\Authenticate;

use App\Shopifyhome\Components\Shop\Shop;
use App\Core\Components\Base;
use App\Shopifyhome\Models\SourceModel;
class User extends Base
{
    public function setupHomeUser($data)
    {
        $this->di->getLog()->logContent('post data = ' . json_encode($data), 'info', 'shopify' . DS . 'User' . DS . date("Y-m-d") . DS . 'User.log');

        $validateUser = $this->di->getObjectManager()->get('App\Core\Models\User\Details')->getUserbyShopId((string) $data['data']['data']['shop_id'], ['user_id' => 1, 'shops' => 1]);

        //$validateUser = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')->getUserbyShopId(8);
        $this->di->getLog()->logContent('', 'info', 'shopify' . DS . 'User' . DS . date("Y-m-d") . DS . 'User.log');
        $this->di->getLog()->logContent('validate user =' . json_encode($validateUser), 'info', 'shopify' . DS . 'User' . DS . date("Y-m-d") . DS . 'User.log');

        $shop = false;

        if (isset($validateUser['shops'])) {
            foreach ($validateUser['shops'] as $user) {
                if (isset($user['marketplace']) && (($user['marketplace'] == 'shopify') || ($user['marketplace'] == 'shopify_ig'))) {
                    if (isset($user['warehouses']) && count($user['warehouses']) > 0) {
                        $shop = true;
                    }

                    break;
                }

            }
        }

        $this->di->getLog()->logContent('shop status =' . json_encode($shop), 'info', 'shopify' . DS . 'User' . DS . date("Y-m-d") . DS . 'User.log');

        if ($validateUser && $shop) {

            $this->checkIfUserIsReInstalled($validateUser);

            $user = \App\Core\Models\User::findFirst([['_id' => $validateUser['user_id']]]);
            $user->id = (string) $user->_id;
            $this->di->setUser($user);
            return [
                'success' => true,
                'redirect_to_dashboard' => 1,
                // 'shop' => $validateUser['shops'][0]['domain'],
                'shop' => $validateUser['shops'][0]['myshopify_domain'],
            ];
        }
        //            $target = $this->di->getObjectManager()->get('App\Shopifyhome\Components\Shop\Shop')->matchMarketplaceWithRemoteSubAppId($data['rawResponse']['sAppId']);
        $target = $data['data']['data']['marketplace'];
        $appCode = $data['rawResponse']['app_code'] ?? 'shopify';
        $this->di->getLog()->logContent('Target Marketplace = ' . json_encode($target), 'info', 'shopify' . DS . 'User' . DS . date("Y-m-d") . DS . 'User.log');
        $shopResponse = $this->di->getObjectManager()->get('\App\Connector\Components\ApiClient')->init($target, true)->call('/shop', [], [
            'shop_id' => $data['data']['data']['shop_id'],
        ]);
        // $this->di->getLog()->logContent('user details. shop response = '.json_encode($shopResponse),'info','user_shop.log');
        $this->di->getLog()->logContent('user details. shop_id='.$data['data']['data']['shop_id'].' shop response = ' . json_encode($shopResponse), 'info', 'shopify' . DS . 'User' . DS . date("Y-m-d") . DS . 'User.log');
        if (!$shopResponse['success'] || is_null($shopResponse) || empty($shopResponse)) {
            return [
                'success' => false,
                'message' => $shopResponse['errors'] ?? ' 1 Error fetching data from Shopify, Please try again later or contact our next available support member.',
            ];
        }
        if (!$validateUser || is_null($validateUser)) {

            // $user = \App\Core\Models\User::findFirst([['username' => $shopResponse['data']['domain']]]);
            $user = \App\Core\Models\User::findFirst([['username' => $shopResponse['data']['myshopify_domain']]]);
            if (empty($user)) {
                $shopData['app_codes'] = [$appCode];
                // $shopData['username'] = $shopResponse['data']['domain'];
                $shopData['username'] = $shopResponse['data']['myshopify_domain'];
                $shopData['email'] = $shopResponse['data']['email'];
                $shopData['password'] = $this->di->getConfig()->security->default_shopify_sign;
                $userModel = $this->di->getObjectManager()->create('\App\Core\Models\User');
                $createUser = $userModel->createUser($shopData, 'customer', true);

                $createUser['mail'] = [
                    'name' => $shopResponse['data']['name'],
                    'email' => $shopResponse['data']['email'],
                ];
                $userModel->id = (string) $userModel->_id;
                $this->di->setUser($userModel);
            } else {
                $user = \App\Core\Models\User::findFirst([['_id' => $user->_id]]);
                $user->id = (string) $user->_id;
                $this->di->setUser($user);
                $createUser = [
                    'success' => true,
                    'successsetupHomeUser' => true,
                ];

            }

            $this->di->getLog()->logContent('Create new User ' . json_encode($createUser), 'info', 'shopify' . DS . 'User' . DS . date("Y-m-d") . DS . 'User.log');

        } else {

            $user = \App\Core\Models\User::findFirst([['user_id' => $validateUser['user_id']]]);
            $user->id = (string) $user->_id;
            $this->di->setUser($user);
            $createUser = [
                'success' => true,
            ];
        }
        if ($createUser['success']) {
//                $target = $this->di->getObjectManager()->get('App\Shopifyhome\Components\Shop\Shop')->matchMarketplaceWithRemoteSubAppId($data['rawResponse']['sAppId']);
            $target = $data['data']['data']['marketplace'];
            $shopResponse['data']['remote_shop_id'] = (string) $data['data']['data']['shop_id'];
            $shopResponse['data']['marketplace'] = $target;
            $shopResponse['data']['app_code'] = $appCode;

            $updateUserwithShop = $this->di->getObjectManager()->get(Shop::class)->addShopToUser($shopResponse);

            $this->di->getLog()->logContent('shop response home = ' . json_encode($updateUserwithShop), 'info', 'shopify' . DS . 'User' . DS . date("Y-m-d") . DS . 'User.log');

            if ($updateUserwithShop['success']) {
                //$target = $this->di->getObjectManager()->get('App\Shopifyhome\Components\Shop\Shop')->matchMarketplaceWithRemoteSubAppId($data['rawResponse']['sAppId']);

                $addShopLocation = $this->di->getObjectManager()->get(Shop::class)->addWarehouseToUser($updateUserwithShop['home_internal_shop'], $data, $target);
                /* $addShopLocation = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Shop\Shop')->addWarehouseToUser(25, $data, 36); */
                if ($addShopLocation['success']) {
                    // $addShopLocation['shop'] = $shopResponse['data']['domain'];
                    $addShopLocation['shop'] = $shopResponse['data']['myshopify_domain'];
                    $addShopLocation['mail'] = [
                        'name' => $shopResponse['data']['name'],
                        'email' => $shopResponse['data']['email'],
                    ];
                    return $addShopLocation;
                }
                return $addShopLocation;

            }
            return $updateUserwithShop;

        }
        // $addShopLocation['shop'] = $shopResponse['data']['domain'];
        $addShopLocation['shop'] = $shopResponse['data']['myshopify_domain'];
        $addShopLocation['success'] = false;
        $addShopLocation['message'] = "We did't expected you to reach here. Kindly contact support or reinstall the app.";
        $addShopLocation['redirect_to_dashboard'] = true;
        /* $this->di->getLog()->logContent('validate user fail ='.json_encode($createUser), 'info', 'user_login.log');

                        $this->di->getLog()->logContent('shop location ='.json_encode($addShopLocation), 'info', 'user_login.log'); */
        return $addShopLocation;

    }

    public function checkIfUserIsReInstalled($validateUser): void
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        if ($collection->findOne([
                'user_id' => $validateUser['user_id'],
                'uninstall_status' => ['$exists' => true]
        ])) {

            // register Uninstall webhook only
            // $createUninstallWebhookRequest = $this->di->getObjectManager()
            // ->get("\App\Shopifyhome\Models\SourceModel")
            // ->registerUninstallWebhook();

            // Re-Create all the webhook
            $createWebhookRequest = $this->di->getObjectManager()
            ->get(SourceModel::class)
            ->registerWebhooks('amazon_sales_channel');

            // remove the uninstall status
            $collection->updateOne(['user_id' => $validateUser['user_id']], [
                '$unset' => [
                    'shop_erase' => true,
                    'uninstall_status' => true,
                    'uninstall_date' => true
                ]
            ]);
        }
    }
}
