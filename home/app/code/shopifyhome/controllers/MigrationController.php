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
 * @copyright   Copyright © 2018 CedCommerce. All rights reserved.
 * @license     EULA http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Controllers;

use App\Core\Controllers\BaseController;
use App\Core\Models\User;
use App\Amazon\Components\Common\Helper;
/**
 * Class MigrationController
 * @package App\Shopifyhome\Controllers
 */
class MigrationController extends BaseController
{
    public function isUserExistsInMultiAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody['shop'])) {
            $shop = $rawBody['shop'];

            unset($rawBody['_url']);

            $hmacValidationResponse = $this->verifyHMACFromRemoteDb($rawBody);
            if ($hmacValidationResponse['success'] == true) {
                $userModel = User::findFirst([['shops.myshopify_domain' => $shop]]);

                if ($userModel) {
                    $user = $userModel->toArray();

                    $source_shop_id = $target_shop_id = $target_connected = null;
                    foreach ($user['shops'] as $_shop) {
                        if (isset($_shop['myshopify_domain']) && $_shop['myshopify_domain'] === $shop) {
                            if (isset($_shop['apps'][0]['app_status']) && $_shop['apps'][0]['app_status'] === 'uninstall') {
                                continue;
                            }
                            if ( isset($_shop['target']) && isset($_shop['target'][0]) ) {
                                foreach ( $_shop['target'] as $target ) {
                                    if ( isset($target['disconnected']) && $target['disconnected'] == true ) {
                                        continue;
                                    } else {
                                        $target_connected = true;
                                    }
                                }
                            }
                            $source_shop_id = $_shop['_id'];
                            // $targets = $_shop['targets'];
                            // $target_shop_id = current($targets)['shop_id'];
                            // break;

                        } elseif ($_shop['marketplace'] === 'amazon') {
                            if ($target_shop_id === null) {
                                $target_shop_id = $_shop['_id'];
                            }
                        }
                    }

                    if ($source_shop_id) {
                        $objectManager = $this->di->getObjectManager();
                        // Get Plan Status
                        $validateUserPlan = $this->di->getObjectManager()
                            ->get('\App\Plan\Models\Plan')
                            ->validateUserPlan($user);
                        // End

                        // get the step details from config 
                        $configObj = $objectManager->get('\App\Core\Models\Config\Config');
                        $user_id = $user['user_id'];
                        $configObj->setUserId($user_id);
                        // $configObj->sourceSet($source_shop_id);
                        $configObj->setSourceShopId($source_shop_id);
                        $configObj->setAppTag(null);
                        $configObj->setTargetShopId($target_shop_id);
                        $onboardingStep = $configObj->getConfig(Helper::STEP_COMPLETED);
                        $onboardingStep = json_decode(json_encode($onboardingStep, true), true);

                        $showMaintenance = false;
                        if ($this->di->getConfig()->get('show_maintenance')) {
                            $showMaintenance = $this->di->getConfig()->show_maintenance;
                        }

                        $currentDate = '';
                        if ($this->di->getConfig()->get('maintenance_date')) {
                            $currentDate = $this->di->getConfig()->maintenance_date;
                        }

                        $currentTime = '';
                        if ($this->di->getConfig()->get('maintenance_time')) {
                            $currentTime = $this->di->getConfig()->maintenance_time;
                        }

                        //to update login date
                        if (!$showMaintenance) {
                            $baseMongo = $this->di->getObjectManager()->get('App\Core\Models\BaseMongo');
                            $userDetails = $baseMongo->getCollectionForTable('user_details');
                            $res = $userDetails->updateOne(
                                ['user_id' => $user_id, "shops._id" => $source_shop_id],
                                ['$set' =>
                                    ['shops.$[shop].last_login_at' => date('Y-m-d H:i:s')]
                                ],
                                [
                                    'arrayFilters' => [['shop._id' => $source_shop_id]],
                                ]
                            );
                        }
                        $connectors = $this->di->getObjectManager()
                            ->get('App\Connector\Components\Connectors')
                            ->getConnectorsWithFilter([
                                'type' => 'real',
                            ], $user_id);
                            if ( $user['user_id'] == "676aac11ed82f1c00000ed16" ) {
                                $onboardingStep[0] = [
                                    'value' => 0
                                ];
                            }
                        $res = [
                            'success' => true,
                            'data'    => [
                                'user_id'        => $user['user_id'],
                                'username'       => $user['username'],
                                'source_shop_id' => $source_shop_id,
                                'target_shop_id' => $target_shop_id,
                                // 'targets'        => $targets,
                                'token'          => $userModel->getToken(),
                                'plan'           => $validateUserPlan,
                                'on_boarding_status' => $target_connected && isset($onboardingStep[0]) && $onboardingStep[0]['value'] >= 3,
                                'date'           => $currentDate,
                                'time'           => $currentTime,
                                'connector'     => [
                                    'data' => $connectors
                                ]
                            ],
                            'showMaintenance' => $showMaintenance
                        ];
                    } else {
                        $res = [
                            'success' => false,
                            'message' => 'shopify shop not found.'
                        ];
                    }
                } else {
                    $res = [
                        'success' => false,
                        'message' => 'user does not exists.'
                    ];
                }
            } else {
                $res = [
                    'success' => false,
                    'message' => $hmacValidationResponse['message'] ?? 'invalid request due to hmac validation failed.'
                ];
            }
        } else {
            $res = [
                'success' => false,
                'message' => 'shop is required.'
            ];
        }

        return $this->prepareResponse($res);
    }

    /**
     * Verify the request is from Shopify using the HMAC signature (for public apps).
     *
     * @param array $params The request parameters (ex. $_GET)
     * 
     * @return Array
     */
    public function verifyHMACFromRemoteDb(array $params)
    {
        $dbName = 'remote_db';
        if (isset($params['cif_remote_db_name'])) {
            $dbName = $params['cif_remote_db_name'];
            unset($params['cif_remote_db_name']);
        }

        $env = 'live'; // live | sandbox
        if (isset($params['cif_remote_app_env'])) {
            $env = $params['cif_remote_app_env'];
            unset($params['cif_remote_app_env']);
        }

        $remoteAppInfo = $this->getAppInfoFromRemote($dbName, $env);

        if ($remoteAppInfo['success']) {
            $appInfo = $remoteAppInfo['data'];
            // echo '<pre>';print_r($appInfo);die;

            // Ensure shop, timestamp, and HMAC are in the params
            if (
                array_key_exists('shop', $params)
                && array_key_exists('timestamp', $params)
                && array_key_exists('hmac', $params)
            ) {
                // Grab the HMAC, remove it from the params, then sort the params for hashing
                $hmac = $params['hmac'];
                unset($params['hmac']);
                unset($params['PHPSESSID']);
                //unset($params['timestamp']);
                $existingUserParams = ['embedded', 'locale', 'session', 'id_token'];
                $paramKeys = array_keys($params);
                $intersectingParams = array_intersect($existingUserParams, $paramKeys);
                if (count($intersectingParams) < count($existingUserParams)) {
                    $this->di->getLog()->logContent(json_encode($params), 'info', 'uninstallCheck.log');
                    return [
                        'success' => false,
                        'message' => 'User is Invalid, Need to reAuth.'
                    ];
                }

                $allowed_params = [
                    'shop', 'timestamp', 'code', 'host', 'embedded', 'locale', 'session', 'current_route', 'prefetch', 'force_legacy_domain', 'sAppId', 'state', 'app_code', 'id_token',
                    'isPlan', 'success', 'message' // params added in billing api redirect-url
                ];
                $paramsCopy = $params;
                foreach ($params as $key => $value) {
                    if (!in_array($key, $allowed_params)) {
                        unset($params[$key]);
                    }
                }

                ksort($params);

                // Encode and hash the params (without HMAC), add the API secret, and compare to the HMAC from params
                if ($hmac === hash_hmac('sha256', urldecode(http_build_query($params)), (string) $appInfo[$env]['app_secret'])) {
                    return ['success' => true];
                }
                $notAllowedParams = [];
                foreach ($paramsCopy as $key => $value) {
                    if (in_array($key, $notAllowedParams)) {
                        unset($paramsCopy[$key]);
                    }
                }
                ksort($paramsCopy);
                if ($hmac === hash_hmac('sha256', urldecode(http_build_query($paramsCopy)), (string) $appInfo[$env]['app_secret'])) {
                    return ['success' => true];
                }
            }

            return [
                'success' => false,
                'message' => 'HMAC validation failed.'
            ];
        }
        return $remoteAppInfo;
    }

    /**
     * Get Shopify app info from remote db
     *
     * @param string $dbName Name of key in config in which remote db credentials are saved
     * 
     * @return array
     */
    public function getAppInfoFromRemote($dbName, $env)
    {
        $appId = $this->di->getConfig()->get('remote_shopify_app_id');
        if ($appId) {

            $cache_key = "remote_app_info_{$appId}";
            $cached_app_info = $this->di->getCache()->get($cache_key);

            if ($cached_app_info) {
                // $this->di->getCache()->delete($cache_key);

                if (isset($cached_app_info[$env]['app_secret'])) {
                    return [
                        'success' => true,
                        'data'    => $cached_app_info
                    ];
                }
                return [
                    'success' => false,
                    'message' => "invalid app for '{$env}' environment."
                ];
            }
            $db = $this->di->get($dbName);
            if ($db) {
                $appsCollections = $db->selectCollection('apps');
                $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
                // $appInfo = $appsCollections->findOne(['marketplace' => 'shopify']);
                $appInfo = $appsCollections->findOne(['_id' => (string)$appId], $options);

                if (!empty($appInfo)) {
                    // echo '<pre>';print_r($appInfo);die;

                    if (isset($appInfo[$env]['app_secret'])) {

                        $this->di->getCache()->set($cache_key, $appInfo);

                        return [
                            'success' => true,
                            'data'    => $appInfo
                        ];
                    }
                    return [
                        'success' => false,
                        'message' => $this->di->getLocale()->_(
                            'shopify_invalid_app_env',
                            [
                                'env' => $env
                            ]
                        )
                    ];
                }
                return [
                    'success' => false,
                    'message' => 'remote app not found.'
                ];
            }
            return [
                'success' => false,
                'message' => 'please add credentials of remote db in remote_db key in config.php.'
            ];
        }
        return [
            'success' => false,
            'message' => 'remote_shopify_app_id key is not set in config.php'
        ];
    }
}
