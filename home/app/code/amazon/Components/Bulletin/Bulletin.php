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
 * @package     Ced_Amazon
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Amazon\Components\Bulletin;

use App\Amazon\Components\Common\Common;
use Exception;
use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base as Base;

class Bulletin extends Base
{
    public const ALLOWED_ITEM_TYPES_WITH_PARAMETERS = [
        'ORDER_SYNC_FAILED' => ['numberOfOrders' => 'integer'],
        'INVENTORY_OUT_OF_STOCK' => ['numberOfProducts' => 'integer'],
        'PLAN_CREDIT_LIMIT_EXHAUST' => ['usedCreditPercentage' => 'integer', 'usageAsOnDate' => 'date'],
        'ENABLE_WAREHOUSE' => [],
        'SHIPMENT_SYNC_FAILED' => ['numberOfShipments' => 'integer'],
        'ORDER_CANCELLATION_SYNC_FAILED' => ['numberOfOrders' => 'integer'],
        'RETURN_SYNC_FAILED' => ['numberOfOrders' => 'integer'],
        'REFUND_SYNC_FAILED' => ['numberOfOrders' => 'integer'],
        'LISTING_PROCESS_FINISHED' => ['successListings' => 'integer', 'failedListings' => 'integer'],
        'BRAND_APPROVAL' => ['numberOfListings' => 'integer'],
        'SYNCING_PAUSED' => [],
        'ENABLE_PRICE_ADJUSTMENT' => [],
        'APP_UNDER_MAINTENENCE' => ['startDate' => 'date', 'endDate' => 'date', 'startHour' => 'integer', 'startMinute' => 'integer', 'endHour' => 'integer', 'endMinute' => 'integer'],
        'PLAN_EXPIRE' => ['expireDate' => 'date'],
        'DISABLE_VACATION_MODE' => [],
        'BETA_SELLER_ENROLLMENT' => [],
        'ORDER_SYNC_FAILED_EMBED' => ['numberOfOrders' => 'integer'],
        'ORDER_CANCELLATION_SYNC_FAILED_EMBED' => ['numberOfOrders' => 'integer'],
        'RETURN_SYNC_FAILED_EMBED' => ['numberOfOrders' => 'integer'],
        'REFUND_SYNC_FAILED_EMBED' => ['numberOfOrders' => 'integer'],
        'SHIPMENT_SYNC_FAILED_EMBED' => ['numberOfShipments' => 'integer'],
    ];

    public const MARKETPLACE = 'amazon';

    public function createBulletin($data)
    {
        $logFile = 'bulletin/'.($data['bulletinItemType'] ?? "common"). '/'. date('Y-m-d').'.log';
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $this->di->getLog()->logContent(print_r('User: '.json_encode($userId, true), true), 'info', $logFile);
        $this->di->getLog()->logContent(print_r('Data received: '.json_encode($data, true), true), 'info', $logFile);
        if (!isset($data['home_shop_id'], $data['bulletinItemType'], $data['bulletinItemParameters'], $data['source_shop_id'])) {
            $this->di->getLog()->logContent(print_r('required params missing!', true), 'info', $logFile);
            $response = [
                'success' => false,
                'message' => 'home_shop_id|source_shop_id|bulletinItemType|bulletinItemParameters missing'
            ];
        } else {
            if ($userId != $this->di->getUser()->id) {
                $diRes = $this->di->getObjectManager()->get(Common::class)->setDiForUser($userId);
                if (!$diRes['success']) {
                    return $diRes;
                }
            }

            //for testing purposes only
            if (!empty($this->di->getConfig()->get("bulletin_allowed_users"))) {
                $allowedUsers = $this->di->getConfig()->get("bulletin_allowed_users")->toArray();
                if (!in_array($userId, $allowedUsers)) {
                    $this->di->getLog()->logContent(print_r('User not allowed to process!', true), 'info', $logFile);
                    return [
                        'success' => false,
                        'message' => 'This user is not allowed to use bulletin for now!'
                    ];
                }
            }

            $shop = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')->findShop([
                '_id' => (string)$data['home_shop_id']
            ], $userId)[0] ?? [];
            if (empty($shop)) {
                $this->di->getLog()->logContent(print_r('shop not found for user!', true), 'info', $logFile);
                $response = [
                    'success' => false,
                    'message' => 'Shop not found!'
                ];
            } else {
                if (isset($shop['reauth_required']) && $shop['reauth_required']) {
                    $this->di->getLog()->logContent(print_r('cannot process bulletin as user requires to perform re-auth!', true), 'info', $logFile);
                    return [
                        'success' => false,
                        'message' => 'Re-auth not done!'
                    ];
                }
                // $embedUser = !empty($this->di->getUser()->linked_amazon_users) ? true : false;
                // if ($embedUser) {
                //     if (!empty($this->di->getConfig()->get("bulletin_embed_allowed_users"))) {
                //         $allowedUsers = $this->di->getConfig()->get("bulletin_embed_allowed_users")->toArray();
                //         if (!in_array($userId, $allowedUsers)) {
                //             $embedUser = false;
                //         }
                //     }
                // }

                // if ($embedUser && in_array($data['bulletinItemType'], ['ORDER_SYNC_FAILED', 'SHIPMENT_SYNC_FAILED', 'ORDER_CANCELLATION_SYNC_FAILED', 'RETURN_SYNC_FAILED', 'REFUND_SYNC_FAILED'])) {
                //     $data['bulletinItemType'] = $data['bulletinItemType'].'_EMBED';
                // }

                $validateRes = $this->checkForValidParameters($data['bulletinItemType'], $data['bulletinItemParameters']);
                if (!$validateRes['success']) {
                    $this->di->getLog()->logContent(print_r('validateRes: '.json_encode($validateRes, true), true), 'info', $logFile);
                    return $validateRes;
                }

                $appTag = $this->di->getAppCode()->getAppTag() ?? 'default';
                if ($appTag == 'default') {
                    $sources = $shop['sources'] ?? ($shop['targets'] ?? []);
                    foreach($sources as $source) {
                        if (($source['shop_id'] == $data['source_shop_id']) && isset($source['app_code'])) {
                            $appTag = $source['app_code'] ?? 'default';
                        }
                    }
                }

                $url = $this->getGridLinkAsPerItemType($appTag, $userId, ($data['process'] ?? ""), ($data['query_params'] ?? ""));
                $params['shop_id'] = $shop['remote_shop_id'] ?? "";
                $params['bulletinItemType'] = $data['bulletinItemType'];
                $params['bulletinItemParameters'] = $data['bulletinItemParameters'];
                $response = $this->di->getObjectManager()->get(Helper::class)->sendRequestToAmazon('bulletin', $params, 'POST');
                $this->di->getLog()->logContent(print_r('response received from remote: '.json_encode($response, true), true), 'info', $logFile);
                if (isset($response['success'], $response['response']['bulletinItemId']) && $response['success']) {
                    $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                    $collection = $baseMongo->getCollection('amazon_bulletin_container');
                    $filter = [
                        'user_id' => $userId,
                        'bulletin_item_type' => $data['bulletinItemType'],
                        'target_shop_id' => $data['home_shop_id'],
                        'source_shop_id' => $data['source_shop_id'],
                    ];
                    $entryExist = $collection->findOne($filter, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
                    if (empty($entryExist)) {
                        $dataToSave = [
                            'user_id' => $userId,
                            'target_shop_id' => $data['home_shop_id'],
                            'source_shop_id' => $data['source_shop_id'],
                            'marketplace' => self::MARKETPLACE,
                            'bulletin_item_type' => $data['bulletinItemType'],
                            'bulletin_item_parameters' => $data['bulletinItemParameters'],
                            'bulletin_item_id' => $response['response']['bulletinItemId'],
                            'path_url' => $url,
                            'app_tag' => $appTag,
                            'created_at' => date('c'),
                            'count' => 1
                        ];
                    } else {
                        $dataToSave = $entryExist;
                        $dataToSave['bulletin_item_type'] =  $data['bulletinItemType'];
                        $dataToSave['bulletin_item_parameters'] =  $data['bulletinItemParameters'];
                        $dataToSave['bulletin_item_id'] =  $response['response']['bulletinItemId'];
                        $dataToSave['path_url'] =  $url;
                        $dataToSave['app_tag'] =  $appTag;
                        $dataToSave['update_at'] =  date('c');
                        $dataToSave['count'] =  ($dataToSave['count'] + 1);
                    }
                    // if ($embedUser) {
                    //     $dataToSave['embed_user'] = $embedUser;
                    //     $dataToSave['path_url'] =  $url['app_link'] ?? "";
                    //     $dataToSave['embed_link'] =  $url['embed_link'] ?? "";
                    // }

                    $this->di->getLog()->logContent(print_r('dataToSave => '.json_encode($dataToSave, true), true), 'info', $logFile);
                    $collection->replaceOne($filter,
                    $dataToSave,
                    ['upsert' => true]);
                } else {
                    if (isset($response['error'], $response['success']) && !$response['success'] && (strpos($response['error'], 'Access to requested resource is denied') !== false) && !empty($shop['remote_shop_id'])) {
                        if (!(isset($shop['reauth_required']) && $shop['reauth_required'])) {
                            $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                            $userCollection = $baseMongo->getCollectionForTable('user_details');
                            $userCollection->updateOne(['user_id' => $userId, 'shops.remote_shop_id' => $shop['remote_shop_id'], 'shops.marketplace' => 'amazon'], ['$set' => ['shops.$.reauth_required' => 1]]);
                        }
                        //this needs to be removed when client successfully perform reauth again
                    }
                    if (empty($response)) {
                        $this->di->getLog()->logContent(print_r('NO response received from server!', true), 'info', $logFile);
                        $response = [
                            'success' => false,
                            'message' => 'Something went wrong at remote!'
                        ];
                    }
                    $this->di->getLog()->logContent(print_r('Process fail!', true), 'info', $logFile);
                }

                return $response;
            }
        }

        return $response;
    }

    public function checkForValidParameters($bulletinItemType, $bulletinItemParameters)
    {
        $response = [];
        if (array_key_exists($bulletinItemType, self::ALLOWED_ITEM_TYPES_WITH_PARAMETERS)) {
            $paramsWithTypes = self::ALLOWED_ITEM_TYPES_WITH_PARAMETERS[$bulletinItemType];
            $values = array_keys($paramsWithTypes);
            if (!empty($values) && (!is_array($bulletinItemParameters) || empty($bulletinItemParameters))) {
                $response = [
                    'success' => false,
                    'message' => 'This item type requires parameters: '.implode(',', $values)
                ];
            } else {
                $missingKeys = array_diff($values, array_keys($bulletinItemParameters));
                if (empty($missingKeys)) {
                    if (!empty($bulletinItemParameters)) {
                        $errors = [];
                        foreach($paramsWithTypes as $param => $type) {
                             if ($this->isValidType($bulletinItemParameters[$param], $type) == false) {
                                $errors[] = "Type of ". $param. " must be '". $type. "'";
                             }
                        }

                        if (!empty($errors)) {
                            return [
                                'success' => false,
                                'message' => implode(', ', $errors)
                            ];
                        }
                    }

                    $response = [
                        'success' => true,
                        'message' => 'Matched'
                    ];
                } else {
                    $response = [
                        'success' => false,
                        'message' => "Missing bulletinItemParameters: " . implode(', ', $missingKeys)
                    ];
                }
            }
        } else {
            $response = [
                'success' => false,
                'message' => 'Invalid item type provided!'
            ];
        }

        return $response;
    }

    /**
     * to handle request if in case the link is redirecting to a specific url so that we can redirect the request at the correct path
     */
    public function handleRequest($rawBody)
    {
        if (isset($rawBody['notificationId'])) {
            $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $baseMongo->getCollectionForTable('amazon_bulletin_container');
            $bulletinInfo = $collection->findOne(['bulletin_item_id' => $rawBody['notificationId']], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            if (!empty($bulletinInfo) && !empty($bulletinInfo['path_url'])) {
                $count = ($bulletinInfo['hit_count'] ?? 0) + 1;
                $collection->updateOne(['bulletin_item_id' => $rawBody['notificationId']], ['$set' => ['hit_count' => $count]]);
                return $bulletinInfo['path_url'];
            }
            if (!empty($bulletinInfo) && isset($bulletinInfo['embed_user']) && $bulletinInfo['embed_user'] && !empty($bulletinInfo['embed_link'])) {
                $count = ($bulletinInfo['hit_count'] ?? 0) + 1;
                $collection->updateOne(['bulletin_item_id' => $rawBody['notificationId']], ['$set' => ['hit_count' => $count]]);
                return $bulletinInfo['embed_link'];
            }
            if (!empty($bulletinInfo) && empty($bulletinInfo['path_url']) && isset($bulletinInfo['user_id'])) {
                $diRes = $this->di->getObjectManager()->get(Common::class)->setDiForUser($bulletinInfo['user_id']);
                if ($diRes['success']) {
                    $count = ($bulletinInfo['hit_count'] ?? 0) + 1;
                    $collection->updateOne(['bulletin_item_id' => $rawBody['notificationId']], ['$set' => ['hit_count' => $count]]);
                    return $this->getGridLinkAsPerItemType($bulletinInfo['app_tag'], $bulletinInfo['user_id']);
                }
            }
        }

        if(!empty($this->di->getConfig()->get('frontend_app_url'))) {
            $link = $this->di->getConfig()->get('frontend_app_url');
            return $link .'show/message?message=Sorry we are unable to find any data for your request!';
        }

        return false;
    }

    /**
     * to get the grid link or the app link
     */
    public function getGridLinkAsPerItemType($appTag, $userId, $process = "default", $queryParams = "", $embedUser = false)
    {
        $link = false;
        if(!empty($this->di->getConfig()->get('grid-link')[$appTag])) {
            $linkInfo = $this->di->getConfig()->get('grid-link')[$appTag]->toArray();
            if (isset($linkInfo['modification_required']) && isset($linkInfo['source']) && $linkInfo['modification_required']) {
                $sourceObject = $this->getSourceModuleObject($linkInfo['source']);
                if (method_exists($sourceObject, 'getAppLink')) {
                    $link = $sourceObject->getAppLink($userId, $linkInfo, $appTag, $process, $queryParams);
                } else {
                    $link = $linkInfo['basepath'] ?? "";
                }
            }
            if (isset($linkInfo['basepath']) && isset($linkInfo['modification_required']) && !$linkInfo['modification_required']) {
                $link = $linkInfo['basepath'];
            }
            if ($embedUser) {
                //here we need to write the code for handling embed user redirectiom url
                $embedLink = "";
                return [
                    'app_link' => $link,
                    'embed_link' => $embedLink
                ];
            }
            return $link;
        }

        return false;
    }

    public function getSourceModuleObject($source)
    {
        $object = null;
        try {
            $moduleHome = ucfirst((string) $source);
            $class = '\App\\' . $moduleHome . '\Components\Helper';
            $object = $this->di->getObjectManager()->get($class);
        } catch (Exception) {
            $moduleHome = ucfirst((string) $source) . "home";
            $class = '\App\\' . $moduleHome . '\Components\Helper';
            $object = $this->di->getObjectManager()->get($class);
        }

        return $object;
    }

    public function isValidType($value, $type)
    {
        if ($type == 'date') {
            return $this->isValidDate($value);
        }
        return  (gettype($value) == $type);

        return true;
    }

    public function isValidDate($dateStr)
    {
        $timestamp = strtotime((string) $dateStr);
        return $timestamp ? true : false;
    }
}
