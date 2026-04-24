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

namespace App\Amazon\Components\Order;

use App\Amazon\Components\Cron\Route\Requestcontrol;
use Exception;
use ZipArchive;
use App\Core\Components\Base as Base;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\Feed\Feed;
use App\Connector\Components\Order\Order as ConnectorOrder;
use App\Amazon\Components\ShopEvent;

class Order extends Base
{
    private $_user_id;



    private $_user_details;



    private $configObj;

    public const COUNTRY_FLAG = [
        "US" => "https://www.countryflags.io/US/shiny/64.png",
        "CA" => "https://www.countryflags.io/CA/shiny/64.png",
        "MX" => "https://www.countryflags.io/MX/shiny/64.png",
        "ES" => "https://www.countryflags.io/ES/shiny/64.png",
        "UK" => "https://www.countryflags.io/GB/shiny/64.png",
        "FR" => "https://www.countryflags.io/FR/shiny/64.png",
        "DE" => "https://www.countryflags.io/DE/shiny/64.png",
        "IT" => "https://www.countryflags.io/IT/shiny/64.png",
        "TR" => "https://www.countryflags.io/TR/shiny/64.png",
        "BR" => "https://www.countryflags.io/BR/shiny/64.png",
        "AE" => "https://www.countryflags.io/AE/shiny/64.png",
        "IN" => "https://www.countryflags.io/IN/shiny/64.png",
        "CN" => "https://www.countryflags.io/CN/shiny/64.png",
        "JP" => "https://www.countryflags.io/JP/shiny/64.png",
        "AU" => "https://www.countryflags.io/AU/shiny/64.png",
        "SG" => "https://www.countryflags.io/SG/shiny/64.png",
        "AT" => "https://www.countryflags.io/AT/shiny/64.png",
        "NL" => "https://www.countryflags.io/NL/shiny/64.png"
    ];

    public function init($request = [])
    {
        if (isset($request['user_id'])) $this->_user_id = (string)$request['user_id'];
        else $this->_user_id = $this->di->getUser()->id;

        //        $this->_user_id = '6065cd371c4d2633c2009b94';

        $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        //        if (isset($request['shop_id'])) {
        //            $this->_shop_id = $request['shop_id'];
        //            $shop = $this->_user_details->getShop($this->_shop_id, $this->_user_id);
        //        } else {
        //            $shop = $this->_user_details->getDataByUserID($this->_user_id, 'amazon');
        //            $this->_shop_id = $shop['_id'];
        //        }
        //        $this->_remote_shop_id = $shop['remote_shop_id'];
        ////        $this->_site_id = $shop['warehouses'][0]['seller_id'];
        $this->configObj = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
        return $this;
    }

    public function getOrderById($data)
    {
        if (!isset($data['source_order_id'])) {
            return ["success" => false, "message" => "Order ID missing"];
        }

        $sourceOrderId = $data['source_order_id'];

        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::ORDER_CONTAINER);
            $orderData = $collection->findOne(['source_order_id' => $sourceOrderId, "user_id" => $this->_user_id], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            if ($orderData) return ["success" => true, "data" => $orderData];

            return ["success" => false, "data" => []];
        } catch (Exception $e) {
            return ["success" => false, "message" => "Some exception has occured :" . $e->getMessage()];
        }
    }





    public function fetchOrders($data)
    {
        if (isset($data['user_id'])) {
            $userId = $data['user_id'];
        } else {
            $userId = $this->_user_id;
        }

        $orderEnabled = false;
        //    $orderStatus = ['Unshipped'];
        $orderStatus = ['Shipped'];
        $amazonOrderChannel = 'MFN';

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $configuration = $mongo->getCollectionForTable("configuration");
        $configurationSettings = $configuration->findOne(['user_id' => $userId]);
        if (isset($configurationSettings['data']['order_settings'])) {
            $orderEnabled = $configurationSettings['data']['order_settings']['settings_enabled'];
            //  $orderStatus = $configurationSettings['data']['order_settings']['order_status'];
            // $amazonOrderChannel  = $configurationSettings['data']['order_settings']['amazon_order_channel'];
        } else {
            $orderEnabled = true;
        }

        if ($orderEnabled) {
            $commonHelper = $this->di->getObjectManager()
                ->get(Helper::class);
            $connectedShops = $commonHelper->getAllAmazonShops($userId);
            foreach ($connectedShops as $shop) {
                    $remoteShopId = $shop['remote_shop_id'];
                    $homeShopId = $shop['_id'];
                    $orderId = $data['order_id'] ?? false;
                    $buyerEmail = $data['buyer_email'] ?? false;
                    // $lower = date('Y-m-d H:i:s O', strtotime('-8 hour'));
                    $lower = date('Y-m-d H:i:s O', strtotime('-3 day'));
                    $upper = null;
                    //                    $lower = date('Y-m-d H:i:s O', strtotime('-90 day'));
                    //                    $upper = date('Y-m-d H:i:s O', strtotime('-80 day'));
                    //   $limit = 10;
                    $types = ['Created'];
                    foreach ($types as $type) {
                        $params = [
                            'shop_id' => $remoteShopId,
                            'amazon_order_id' => $orderId,
                            'buyer_email' => $buyerEmail,
                            'order_status' => $orderStatus,
                            'order_channel' => $amazonOrderChannel,
                            'lower' => $lower,
                            'upper' => $upper,
                            //       'limit' => $limit,
                            'type' => $type,
                            'home_shop_id' => $homeShopId,
                            //      'seller_id' => $shop['warehouses'][0]['seller_id']
                        ];

                        $response = $commonHelper->sendRequestToAmazon('order', $params, 'GET');

                        //                        $this->di->getLog()->logContent(' UserId:. '.$this->_user_id, 'info', 'OrderImport.log');
                        //                        $this->di->getLog()->logContent(' Orders :. '.json_encode($response), 'info', 'OrderImport.log');
                        $this->init(['user_id' => $userId, 'shop_id' => $homeShopId])->saveOrder(json_encode($response));
                    }
            }
        } else {
            return ['success' => false, 'message' => 'Order fetch is disabled'];
        }

        return ['success' => true, 'message' => 'Amazon Order(s) fetched'];
    }

    public function fetchOrdersByShopId($data)
    {
        if (isset($data['target']['marketplace']) && !empty($data['source_order_ids'])) {
            $targetShopId = $data['target']['shopId'];
            $targetMarketplace = $data['target']['marketplace'];
            $amazonOrderId = $data['source_order_ids'];
            $userId = $this->_user_id;
            $shop = $this->_user_details->getShop($targetShopId, $this->_user_id);
            $homeShopId = $shop['_id'];
            foreach ($shop['warehouses'] as $warehouse) {
                    $active = $warehouse;
                    $marketplaceId = $warehouse['marketplace_id'];
            }

            if (isset($active)) {
                $this->configObj->setGroupCode('order');
                $this->configObj->setUserId($userId);
                $this->configObj->setTarget($targetMarketplace);
                $this->configObj->setTargetShopId($targetShopId);
                $settingConfiguration = $this->configObj->getConfig('order_sync');
                $orderEnabled = false;
                if ($settingConfiguration[0]['value']) {
                    $orderEnabled = $settingConfiguration[0]['value'];
                }

                if ($orderEnabled) {
                    $remoteShopId = $shop['remote_shop_id'];
                    $params = [
                        'shop_id' => $remoteShopId,
                        'amazon_order_id' => $amazonOrderId,
                        'home_shop_id' => $homeShopId
                    ];

                    $commonHelper = $this->di->getObjectManager()
                        ->get(Helper::class);
                    $response = $commonHelper->sendRequestToAmazon('order', $params, 'GET');

                    if (isset($response['orders']) && !empty($response['orders'])) {
                        $save['order'] = $response['orders'][0];
                        if (isset($save['order']['data']['FulfillmentChannel'], $save['order']['data']['OrderStatus'])) {
                            if ($save['order']['data']['FulfillmentChannel'] == 'AFN' && $save['order']['data']['OrderStatus'] != 'Shipped') {
                                $message = 'Amazon fulfilled Order is not shipped yet';
                            } elseif ($save['order']['data']['FulfillmentChannel'] == 'MFN'
                                && $save['order']['data']['OrderStatus'] != "Unshipped") {
                                $message = 'Unable to import this order as its status is not Unshipped on Amazon';
                            }
                        }

                        if (isset($save['order']['data']['MarketplaceId'])
                            && $save['order']['data']['MarketplaceId'] != $marketplaceId) {
                            $countryName = \App\Amazon\Components\Common\Helper::MARKETPLACE_NAME[$save['order']['data']['MarketplaceId']];
                            $message = "The order is not linked to this account. It is associated with this country: $countryName.";
                        }

                        if(isset($message)) {
                            return ['success' => false, 'message' => $message];
                        }

                        $save['order']['region'] = $active['region'] ?? "";
                        $save['shop_id'] = $homeShopId;
                        $save['home_shop_id'] = $homeShopId;
                        $save['marketplace'] = $targetMarketplace;
                        $save['region'] = $active['region'] ?? "";
                        $save['order']['data']['manually_imported'] = true;
                        $connectorOrderComponent = $this->di->getObjectManager()->get(ConnectorOrder::class);
                        $saveOrderResponse = $connectorOrderComponent->create($save);
                        if ($saveOrderResponse['success']) {
                            return ['success' => true, 'message' => 'Order imported successfully', 'data' => $saveOrderResponse];
                        }
                        $message = 'Order not imported';
                        $data = $saveOrderResponse;
                    } else {
                        $message = 'Order not found';
                    }
                } else {
                    $message = 'Order fetch is disabled';
                }
            } else {
                $message = 'This account is disabled';
            }
        } else {
            $message = 'Required params missing';
        }

        return ['success' => false, 'message' => $message ?? 'Something went wrong', 'data' => $data ?? []];
    }

    public function fetchSingleOrderData($data)
    {
        if (!empty($data['order_id'])) {
            $userId = $this->di->getUser()->id;
            $targetShopId = $this->di->getRequester()->getTargetId()  ?? $data['target_shop_id'];
            $targetMarkeplace = $this->di->getRequester()->getTargetName() ?? $data['target_marketplace'];
            $shopData = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class)
                ->getShop($targetShopId, $userId);
            $isWarehouseStatusActive = false;
            if (!empty($shopData)) {
                if ($shopData['marketplace'] == $targetMarkeplace) {
                    foreach ($shopData['warehouses'] as $warehouse) {
                        if ($warehouse['status'] == 'active') {
                            $isWarehouseStatusActive = true;
                            $marketplaceId = $warehouse['marketplace_id'];
                            break;
                        }
                    }
                    if ($isWarehouseStatusActive) {
                        $remoteParams = [
                            'shop_id' => $shopData['remote_shop_id'],
                            'amazon_order_id' => $data['order_id'],
                            'home_shop_id' => $shopData['_id']
                        ];

                        $commonHelper = $this->di->getObjectManager()
                            ->get(Helper::class);
                        $remoteResponse = $commonHelper->sendRequestToAmazon('order', $remoteParams, 'GET');
                        if (!empty($remoteResponse['orders'])) {
                            $orderData = $remoteResponse['orders'][0]['data'];
                            if (isset($orderData['MarketplaceId']) && $orderData['MarketplaceId'] != $marketplaceId) {
                                $countryName = \App\Amazon\Components\Common\Helper::MARKETPLACE_NAME[$marketplaceId];
                                return ['success' => false, 'message' => "The order is not linked to this account. It is associated with this country: $countryName."];
                            }
                            return ['success' => true, 'message' => 'Order fetched successfully', 'data' => $orderData];
                        } else {
                            $message = 'Order not found';
                        }
                    } else {
                        $message = 'No active warehouses found';
                    }
                } else {
                    $message = 'Order not belongs to this marketplace';
                }
            } else {
                $message = 'Shop not found';
            }
        } else {
            $message = 'OrderId is required';
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong', 'response' => $remoteResponse ?? []];
    }

    public function fetchSingleOrderDataV2($data)
    {
        $message = null;
        $remoteResponse = null;

        if (empty($data['order_id'])) {
            return ['success' => false, 'message' => 'OrderId is required', 'response' => []];
        }

        $userId = $this->di->getUser()->id;
        $targetShopId = $this->di->getRequester()->getTargetId() ?? $data['target_shop_id'] ?? null;
        $targetMarketplace = $this->di->getRequester()->getTargetName() ?? $data['target_marketplace'] ?? null;

        $shopData = $this->di->getObjectManager()->get(\App\Core\Models\User\Details::class)
            ->getShop($targetShopId, $userId);

        if (empty($shopData)) {
            return ['success' => false, 'message' => 'Shop not found', 'response' => []];
        }

        if ($shopData['marketplace'] != $targetMarketplace) {
            return ['success' => false, 'message' => 'Order not belongs to this marketplace', 'response' => []];
        }

        $isWarehouseStatusActive = false;
        $marketplaceId = null;
        foreach ($shopData['warehouses'] as $warehouse) {
            if (isset($warehouse['status']) && $warehouse['status'] == 'active') {
                $isWarehouseStatusActive = true;
                $marketplaceId = $warehouse['marketplace_id'] ?? null;
                break;
            }
        }

        if (!$isWarehouseStatusActive) {
            return ['success' => false, 'message' => 'No active warehouses found', 'response' => []];
        }

        $remoteParams = [
            'shop_id' => $shopData['remote_shop_id'],
            'orderId' => $data['order_id'],
        ];

        if (!empty($data['includedData']) && is_array($data['includedData'])) {
            $remoteParams['includedData'] = $data['includedData'];
        }

        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        $remoteResponse = $commonHelper->sendRequestToAmazon('get-order-v2', $remoteParams, 'GET');

        if (!empty($remoteResponse['success']) && isset($remoteResponse['data'])) {
            $orderData = $remoteResponse['data'];
            if ($marketplaceId && isset($orderData['marketplaceId']) && $orderData['marketplaceId'] != $marketplaceId) {
                $countryName = \App\Amazon\Components\Common\Helper::MARKETPLACE_NAME[$marketplaceId] ?? $marketplaceId;
                return ['success' => false, 'message' => "The order is not linked to this account. It is associated with this country: $countryName.", 'response' => $remoteResponse];
            }
            return ['success' => true, 'message' => 'Order fetched successfully', 'data' => $orderData];
        }

        $message = $remoteResponse['message'] ?? 'Order not found';
        return ['success' => false, 'message' => $message, 'response' => $remoteResponse ?? []];
    }

    public function getItemCustomizationData($orderData, $itemData)
    {
        if (isset($itemData['BuyerCustomizedInfo']['CustomizedURL'])) {
            $customizedURL = $itemData['BuyerCustomizedInfo']['CustomizedURL'];

            $file_name = $itemData['OrderItemId'];

            $fileLocation = BP . DS . 'var' . DS . 'file' . DS . $this->_user_id . DS . 'order_customization' . DS . $orderData['AmazonOrderId'] . DS . $file_name;
            $filePath = "{$fileLocation}.zip";

            if (!file_exists($filePath)) {
                $dirname = dirname($filePath);
                if (!is_dir($dirname)) {
                    mkdir($dirname, 0777, true);
                }

                file_put_contents($filePath, file_get_contents($customizedURL));
            } else {
                // if (!unlink($filePath)) {
                //     return ['success' => false, 'message' => 'Error deleting file. Kindly check permission.'];
                // }
            }

            if (file_exists($filePath)) {
                $zip = new ZipArchive;
                $res = $zip->open($filePath);
                if ($res === TRUE) {
                    $zip->extractTo("{$fileLocation}/");
                    $zip->close();

                    $customizationFilePath = "{$fileLocation}/{$file_name}.json";
                    if (file_exists($customizationFilePath)) {
                        $customizationJson = file_get_contents($customizationFilePath);

                        if ($customizationJson) {
                            $customizationData = json_decode($customizationJson, true);

                            if (isset($customizationData['version3.0']['customizationInfo']['surfaces']) && is_array($customizationData['version3.0']['customizationInfo']['surfaces'])) {
                                $itemCustomizations = [];

                                $customizationInfoArray = $customizationData['version3.0']['customizationInfo']['surfaces'];

                                foreach ($customizationInfoArray as $customizationInfo) {
                                    $itemCustomizationName = $customizationInfo['name'];

                                    if (isset($customizationInfo['areas']) && is_array($customizationInfo['areas'])) {
                                        foreach ($customizationInfo['areas'] as $area) {
                                            $itemCustomizations[] = $this->getItemCustomizationValue($itemCustomizationName, $area);
                                        }
                                    } else {
                                        $itemCustomizations[] = $this->getItemCustomizationValue($itemCustomizationName, $customizationInfo);
                                    }
                                }

                                return ['success' => true, 'data' => $itemCustomizations];
                            }

                            return ['success' => false, 'message' => 'customization data not found.'];
                        }
                        return ['success' => false, 'message' => 'customization json file not found.'];
                    }
                    return ['success' => false, 'message' => 'customization file not found after extraction.'];
                }
                return ['success' => false, 'message' => 'file not extracted'];
            }
            return ['success' => false, 'message' => 'file not downloaded'];
        }
        return ['success' => false, 'message' => 'CustomizedURL not set'];
    }

    public function getItemCustomizationValue($surfaceName, $customization)
    {
        if (isset($customization['customizationType'])) {
            $customizationValue = [];

            $type = $customization['customizationType'];

            /*switch ($type) {
                case 'Options':
                    $name = $customization['label'] ?? ($customization['name'] ?? '');

                    $skip = ['customizationType', 'priceDelta', 'label', 'name'];

                    $value = $key_value = [];
                    foreach ($customization as $c_key => $c_value) {
                        if(in_array($c_key, $skip)) {
                            continue;
                        }
                        elseif(!empty($c_value) {
                            if(is_array($c_value)) {
                                $value[] = json_encode($c_value);
                                $key_value[] = $c_key. ' : ' .json_encode($c_value);
                            } else {
                                $value[] = $c_value;
                                $key_value[] = "{$c_key} : {$c_value}";
                            }
                        }
                    }

                    $valCount = count($key_value);

                    if($valCount && $name) {
                        if($valCount == 1) {
                            $customizationValue = [
                                'name'  => "[{$surfaceName}] {$name}",
                                'value' => current($value);
                            ];
                        }
                        else {
                            $customizationValue = [
                                'name'  => "[{$surfaceName}] {$name}",
                                'value' => $key_value
                            ];
                        }
                    }
                    break;

                case 'TextPrinting':
                    $name = $customization['label'] ?? ($customization['name'] ?? '');

                    $skip = ['customizationType', 'priceDelta', 'label', 'name'];

                    $value = [];
                    foreach ($customization as $c_key => $c_value) {
                        if(in_array($c_key, $skip)) {
                            continue;
                        }
                        else {
                            if(is_array($c_value)) {
                                $value[] = $c_key. ' : ' .json_encode($c_value);
                            } else {
                                $value[] = "{$c_key} : {$c_value}";
                            }
                        }
                    }

                    $customizationValue = [
                        'name'  => "[{$surfaceName}] {$name}",
                        'value' => implode("\n", $value)
                    ];
                    break;

                default:
                    $skip = ['customizationType', 'priceDelta', 'label', 'name'];

                    $name = $customization['label'] ?? ($customization['name'] ?? '');

                    $value = [];
                    foreach ($customization as $c_key => $c_value) {
                        if(in_array($c_key, $skip)) {
                            continue;
                        }
                        else {
                            if(is_array($c_value)) {
                                $value[] = $c_key. ' : ' .json_encode($c_value);
                            } else {
                                $value[$c_key] = "{$c_key} : {$c_value}";
                            }
                        }
                    }

                    $customizationValue = [
                        'name'  => "[{$surfaceName}] {$name}",
                        'value' => implode("\n", $value)
                    ];

                    return $customizationValue;
                    break;
            }*/

            $name = $customization['label'] ?? ($customization['name'] ?? '');

            $skip = ['customizationType', 'priceDelta', 'label', 'name'];
            $value = [];
            $key_value = [];
            foreach ($customization as $c_key => $c_value) {
                if (in_array($c_key, $skip)) {
                    continue;
                }
                if (!empty($c_value)) {
                    if (is_array($c_value)) {
                        $value[] = json_encode($c_value);
                        $key_value[] = $c_key . ' : ' . json_encode($c_value);
                    } else {
                        $value[] = $c_value;
                        $key_value[] = "{$c_key} : {$c_value}";
                    }
                }
            }

            $valCount = count($key_value);

            if ($valCount && $name) {
                if ($valCount == 1) {
                    $customizationValue = [
                        // 'name'  => "[{$surfaceName}] {$name}",
                        'name' => $name,
                        'value' => current($value)
                    ];
                } else {
                    $customizationValue = [
                        // 'name'  => "[{$surfaceName}] {$name}",
                        'name' => $name,
                        'value' => implode("\n", $key_value)
                    ];
                }
            }

            return $customizationValue;
        }
        return [];
    }





    public function prepareShipment($fulfillments, $order, $tracking_number = false)
    {

        $trackingNumberReq=['614a4eb73efae043be435640','61411cf51f47fc4e2e44b9dc'];
        $shipment = [];
        if (isset($fulfillments) && !empty($fulfillments)) {
            try {
                $carrierCodes = $this->getCarrierCodes();
                foreach ($fulfillments as $fulfillment) {

                    if(in_array($this->_user_id,$trackingNumberReq) && empty($fulfillment['tracking_company']) && empty($fulfillment['tracking_number'])){
                        continue;
                    }

                    if (!$tracking_number || (isset($fulfillment['tracking_number']) && !empty($fulfillment['tracking_number']))) {

                        if(isset($fulfillment['tracking_number']) && $this->_user_id == "6192b6bb03766a427361b1bc"){
                            $trackingString = strval($fulfillment['tracking_number']);
                            if (str_starts_with($trackingString, 'E3') || str_starts_with($trackingString, 'NN') ) {
                            $fulfillment['tracking_company'] = 'GLS';
                            }

                            if (str_starts_with($trackingString, '07') || str_starts_with($trackingString, '09') ) {
                                $fulfillment['tracking_company'] = 'BRT';
                            }
                        }

                        if ($fulfillment['tracking_company'] == 'UPS®') {
                            $fulfillment['tracking_company'] = 'UPS';
                        }

                        if($fulfillment['tracking_company']=='Deutsche Post (DE)'){
                            $fulfillment['tracking_company'] = 'Deutsche Post';
                        }

                        if($fulfillment['tracking_company']=='Australia Post' && $this->_user_id =="615d2e231dba8276787c42a8"){
                            $fulfillment['tracking_company'] = 'Australia Post-Consignment';
                        }

                        if($fulfillment['tracking_company']=='Australia Post' && $this->_user_id =="617230f06feb012bb274391f"){
                            $fulfillment['tracking_company'] = 'Australia Post-ArticleId';
                        }

                        if ($fulfillment['tracking_company'] == 'YANWEN Express') {
                            $fulfillment['tracking_company'] = 'Yanwen';
                        }

                        // Code added to handle Amazon supported Carrier Codes
                        $orderCarrierCode = ($fulfillment['tracking_company'] ?: 'Other');
                        if (!in_array($orderCarrierCode, $carrierCodes)) {
                            $orderCarrierCode = "Other";
                        }

                        $shipment[$fulfillment['id']] = [
                            'Id' => $fulfillment['id'],
                            'AmazonOrderID' => $order['marketplace_order_id'],
                            'FulfillmentDate' => date('Y-m-d H:i:s P', strtotime((string) $fulfillment['created_at'])),
                            'FulfillmentData' => [
                                'CarrierCode' => $orderCarrierCode,
                                'CarrierName' => ($fulfillment['tracking_company'] ?: 'Other'),
                                'ShippingMethod' => ($fulfillment['tracking_company'] ?: 'Other'),
                                'ShipperTrackingNumber' => $fulfillment['tracking_number']
                            ]
                        ];
                        if($this->_user_id=="615d2e231dba8276787c42a8"  || $this->_user_id=="617230f06feb012bb274391f"){
                            $shipment[$fulfillment['id']]['FulfillmentData']['ShippingMethod'] ="Parcel Post Regular parcel";
                        }

                        if($this->_user_id=="615d2e231dba8276787c42a8" && $order['source_order_data']['data']['ShipmentServiceLevelCategory']=="Expedited"){
                            $shipment[$fulfillment['id']]['FulfillmentData']['ShippingMethod'] ="Parcel Post Express Post parcel";
                        }

                        foreach ($fulfillment['line_items'] as $key => $lineItem) {
                            $shipment[$fulfillment['id']]['Item'][] =
                                [
                                    'AmazonOrderItemCode' => (string)$this->getOrderItemCode(
                                        $lineItem['variant_id'],
                                        $order['items']['data'],
                                        $key
                                    ),
                                    'Quantity' => $lineItem['quantity']
                                ];
                        }
                    }
                }
            } catch (Exception) {
                //silence
            }
        }

        return $shipment;
    }

    public function getOrderItemCode($sourceVariantId, $orderLineItems, $key = false)
    {
        if (!empty($orderLineItems)) {
            if (!empty($sourceVariantId)) {
                $searchKey = array_search($sourceVariantId, array_column($orderLineItems, 'product_identifier'));
                if (isset($orderLineItems[$searchKey]['marketplace_item_id'])) {
                    return $orderLineItems[$searchKey]['marketplace_item_id'];
                }
            } elseif ($key !== false && isset($orderLineItems[$key]['marketplace_item_id'])) {
                return $orderLineItems[$key]['marketplace_item_id'];
            }
        }

        return false;
    }

    public function sendShipmentToAmazon($shipmentData): void
    {
        foreach ($shipmentData as $homeShopId => $marketplaceFeedContent) {
            $remoteShop = $this->_user_details->getShop($homeShopId, $this->_user_id);
            $remoteShopId = $remoteShop['remote_shop_id'] ?? false;
            foreach ($marketplaceFeedContent as $marketplace => $feedContent) {
                $params = ['feedContent' => $feedContent, 'marketplace_id' => [$marketplace], 'shop_id' => $remoteShopId, 'home_shop_id' => $homeShopId];
                $commonHelper = $this->di->getObjectManager()
                    ->get(Helper::class);
                $response = $commonHelper->sendRequestToAmazon('ship-order', $params, 'POST');

                $feed = $this->di->getObjectManager()
                    ->get(Feed::class)
                    ->init(['user_id' => $this->_user_id, 'shop_id' => $homeShopId])
                    ->process($response);
            }
        }
    }

    public function isTaxIncluded($amzOrder)
    {
        if ($amzOrder['region'] == 'europe' || $amzOrder['region'] == 'EU') {
            return 1;
        }
        return 0;
    }

    public function getTaxRate($amount, $tax, $region)
    {
        if ($amount == 0 || $tax == 0) {
            return 0;
        }

        if ($region == 'europe' || $region == 'EU') {
            $amount = $amount - $tax;
        }

        $taxPercent = ($tax / $amount);
        if($this->_user_id=="61b9a2b726d3d0233439f357"){
            $taxP = $taxPercent * 100;
            $taxPp = round($taxP);
            $taxPercent = $taxPp / 100;
        }

        return $taxPercent;
    }

    public function canCreateOrder($userId, $orderId, $homeShopId, $processType = '')
    {
        $logFile = "order/amazon/syncOrder/$userId/" . date('d-m-Y') . '.log';
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $ids_collection = $mongo->getCollectionForTable('amazon_order_ids');
            $prepareData = [
                '_id' => $userId . "_" . $orderId . "_" . $homeShopId,
                'source_order_id' => $orderId,
                'user_id' => $userId,
                'home_shop_id' => $homeShopId,
                'created_at' => date('c')
            ];
            if (!empty($processType)) {
                $prepareData['processType'] = $processType ?? "CRON";
            }

            $result = $ids_collection->insertOne($prepareData);
            if ($result->getInsertedCount() == 1) {
                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from canCreate(): ' . json_encode($e->getMessage()), 'info', $logFile);
            if ($e->getCode() == 11000) {
                return false;
            } else {
                throw $e;
            }
        }
    }

    private function checkSourceExists($userId, $homeShopId)
    {
        $targetShop = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")
            ->getShop($homeShopId, $userId);
        if (!empty($targetShop['sources'][0])) {
            $sourceShopId = $targetShop['sources'][0]['shop_id'];
            $sourceShop = $this->di->getObjectManager()->get("\App\Core\Models\User\Details")
                ->getShop($sourceShopId, $userId);
            if (!empty($sourceShop)) {
                return true;
            }
        }

        return false;
    }

    public function syncOrder($sqsData)
    {
        $errorLog = 'amazon/syncOrder/error' . date('d-m-Y') . '.log';
        if (isset($sqsData['user_id'], $sqsData['home_shop_id'], $sqsData['orders'])) {
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $logFile = "order/amazon/syncOrder/$userId/" . date('d-m-Y') . '.log';
            $startTime = microtime(true);
            $this->di->getLog()->logContent("Process started at: " . json_encode($startTime), 'info', $logFile);
            $this->di->getLog()->logContent("Process started for user: " . json_encode([
                'user_id' => $userId,
                'sqs_data' => $sqsData,
            ]), 'info', $logFile);
            $homeShopId = $sqsData['home_shop_id'];
            $processType = $sqsData['processType'] ?? '';
            try {
                $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $shop = $userDetails->getShop($homeShopId, $userId);
                if (!empty($shop)) {
                    $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                    $connectorOrderComponent = $this->di->getObjectManager()->get(ConnectorOrder::class);
                    $orders = [];
                    $someIssueInOrders = [];
                    $successOrderIds = [];
                    $forceQuit = false;
                    if (!empty($shop['apps']) && $shop['apps'][0]['app_status'] == 'active') {
                        foreach ($sqsData['orders'] as $orderId => $orderData) {
                            $this->di->getLog()->logContent('Starting process for orderId: ' . json_encode($orderId), 'info', $logFile);
                            $prepareData = [];
                            $sourceShopExists = $this->checkSourceExists($userId, $homeShopId);
                            if (!$sourceShopExists) {
                                $this->di->getLog()->logContent('Source Shop Not found for user: ' . json_encode([
                                    'user_id' => $userId,
                                    'shop_id' => $homeShopId
                                ]), 'info', $logFile);
                                $prepareData = [
                                    'user_id' => $userId,
                                    'shop_id' => $homeShopId
                                ];
                                $this->di->getObjectManager()->get(ShopEvent::class)->updateRegisterForOrderSync($userId, $homeShopId, false);
                                $this->di->getObjectManager()->get(ShopEvent::class)->sendRemovedShopToAws($prepareData);
                                $forceQuit = true;
                                $this->di->getLog()->logContent('Force quitting the process...', 'info', $logFile);
                                break;
                            }
                            if (isset($sqsData['force_create']) && $sqsData['force_create']) {
                                $canCreate = true;
                                $this->di->getLog()->logContent('Forcefully creating order: ' . json_encode($orderId), 'info', $logFile);
                                $this->setAndUpdateAmazonOrderIds($userId, $orderId, $homeShopId);
                            } else {
                                $canCreate = $this->canCreateOrder($userId, $orderId, $homeShopId, $processType);
                            }
                            if (!$canCreate) {
                                $someIssueInOrders[$orderId] = $canCreate;
                                continue;
                            }
                            if (!empty($orderData['items'])) {
                                $orders[$orderId] = $orderData;
                            } else {
                                $params = [
                                    'shop_id' => $sqsData['remote_shop_id'],
                                    'amazon_order_id' => $orderId,
                                    'home_shop_id' => $homeShopId,
                                ];
                                $response = $commonHelper->sendRequestToAmazon('order', $params, 'GET');
                                if (isset($response['orders'][0]['data'], $response['orders'][0]['items'])) {
                                    $orders[$orderId] = $response['orders'][0];
                                } else {
                                    $someIssueInOrders[$orderId] = $response;
                                }
                            }
                            $prepareData = [
                                'shop_id' => $homeShopId,
                                'marketplace' => 'amazon',
                                'order' => $orders[$orderId]
                            ];
                            $successOrderIds[] = $orderId;
                            $saveOrderResponse = $connectorOrderComponent->create($prepareData);
                            $this->di->getLog()->logContent('Order create response: ' . json_encode($saveOrderResponse), 'info', $logFile);
                        }

                        if (!empty($someIssueInOrders)) {
                            $this->di->getLog()->logContent('Unable to create some orders: ' . json_encode($someIssueInOrders), 'info', $logFile);
                        }
                    }
                    if (!$forceQuit) {
                        $message = 'Order Syncing Completed';
                        $endtime = microtime(true);
                        $this->di->getLog()->logContent('Success OrderIds send to sync: ' . json_encode($successOrderIds), 'info', $logFile);
                        $this->di->getLog()->logContent("Process ended at: " . json_encode($endtime), 'info', $logFile);
                        return ['success' => true, 'message' => $message ?? 'Something went wrong'];
                    } else {
                        $message = 'Process breaked check logs';
                    }
                } else {
                    $message = 'Shops not found';
                }
            } catch (Exception $e) {
                $message = $e->getMessage();
                $this->checkExceptionAndTakeAction($sqsData, $orderId, $message);
                $this->di->getLog()->logContent("Exception from syncOrder(): " . json_encode($message), 'info', $logFile ?? $errorLog);
                throw $e;
            }
        } else {
            $message = 'Required params missing (user_id,home_shop_id,orders)';
        }
        $this->di->getLog()->logContent("Error: " . json_encode($message ?? 'Something went wrong'), 'info', $logFile ?? $errorLog);
        $endtime = microtime(true);
        $this->di->getLog()->logContent("Process ended at: " . json_encode($endtime), 'info', $logFile ?? $errorLog);
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    public function syncOrderV2($sqsData)
    {
        $errorLog = 'amazon/syncOrderV2/error' . date('d-m-Y') . '.log';
        if (isset($sqsData['user_id'], $sqsData['home_shop_id'], $sqsData['orders'])) {
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $logFile = "order/amazon/syncOrderV2/$userId/" . date('d-m-Y') . '.log';
            $startTime = microtime(true);
            $this->di->getLog()->logContent("Process started at: " . json_encode($startTime), 'info', $logFile);
            $this->di->getLog()->logContent("Process started for user: " . json_encode([
                'user_id' => $userId,
                'sqs_data' => $sqsData,
            ]), 'info', $logFile);
            $homeShopId = $sqsData['home_shop_id'];
            $processType = $sqsData['processType'] ?? '';
            try {
                $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $shop = $userDetails->getShop($homeShopId, $userId);
                if (!empty($shop)) {
                    $connectorOrderComponent = $this->di->getObjectManager()->get(ConnectorOrder::class);
                    $orders = [];
                    $someIssueInOrders = [];
                    $successOrderIds = [];
                    $forceQuit = false;
                    if (!empty($shop['apps']) && $shop['apps'][0]['app_status'] == 'active') {
                        foreach ($sqsData['orders'] as $orderId => $orderData) {
                            $this->di->getLog()->logContent('Starting process for orderId: ' . json_encode($orderId), 'info', $logFile);
                            $prepareData = [];
                            $sourceShopExists = $this->checkSourceExists($userId, $homeShopId);
                            if (!$sourceShopExists) {
                                $this->di->getLog()->logContent('Source Shop Not found for user: ' . json_encode([
                                    'user_id' => $userId,
                                    'shop_id' => $homeShopId
                                ]), 'info', $logFile);
                                $prepareData = [
                                    'user_id' => $userId,
                                    'shop_id' => $homeShopId
                                ];
                                $this->di->getObjectManager()->get(ShopEvent::class)->updateRegisterForOrderSync($userId, $homeShopId, false);
                                $this->di->getObjectManager()->get(ShopEvent::class)->sendRemovedShopToAws($prepareData);
                                $forceQuit = true;
                                $this->di->getLog()->logContent('Force quitting the process...', 'info', $logFile);
                                break;
                            }
                            if (isset($sqsData['force_create']) && $sqsData['force_create']) {
                                $canCreate = true;
                                $this->di->getLog()->logContent('Forcefully creating order: ' . json_encode($orderId), 'info', $logFile);
                                $this->setAndUpdateAmazonOrderIds($userId, $orderId, $homeShopId);
                            } else {
                                $canCreate = $this->canCreateOrder($userId, $orderId, $homeShopId, $processType);
                            }
                            if (!$canCreate) {
                                $someIssueInOrders[$orderId] = $canCreate;
                                continue;
                            }
                            if (!empty($orderData['orderItems'])) {
                                $orders[$orderId] = $orderData;
                            } else {
                                $someIssueInOrders[$orderId] = [
                                    'message' => 'syncOrderV2 requires orderItems; Amazon fetch is not performed',
                                ];
                                continue;
                            }
                            $prepareData = [
                                'shop_id' => $homeShopId,
                                'marketplace' => 'amazon',
                                'order' => $orders[$orderId],
                                'version' => 2,
                            ];
                            $successOrderIds[] = $orderId;
                            $saveOrderResponse = $connectorOrderComponent->create($prepareData);
                            $this->di->getLog()->logContent('Order create response: ' . json_encode($saveOrderResponse), 'info', $logFile);
                        }

                        if (!empty($someIssueInOrders)) {
                            $this->di->getLog()->logContent('Unable to create some orders: ' . json_encode($someIssueInOrders), 'info', $logFile);
                        }
                    }
                    if (!$forceQuit) {
                        $message = 'Order Syncing Completed';
                        $endtime = microtime(true);
                        $this->di->getLog()->logContent('Success OrderIds send to sync: ' . json_encode($successOrderIds), 'info', $logFile);
                        $this->di->getLog()->logContent("Process ended at: " . json_encode($endtime), 'info', $logFile);
                        return ['success' => true, 'message' => $message ?? 'Something went wrong'];
                    } else {
                        $message = 'Process breaked check logs';
                    }
                } else {
                    $message = 'Shops not found';
                }
            } catch (Exception $e) {
                $message = $e->getMessage();
                $this->checkExceptionAndTakeAction($sqsData, $orderId, $message);
                $this->di->getLog()->logContent("Exception from syncOrderV2(): " . json_encode($message), 'info', $logFile ?? $errorLog);
                throw $e;
            }
        } else {
            $message = 'Required params missing (user_id,home_shop_id,orders)';
        }
        $this->di->getLog()->logContent("Error: " . json_encode($message ?? 'Something went wrong'), 'info', $logFile ?? $errorLog);
        $endtime = microtime(true);
        $this->di->getLog()->logContent("Process ended at: " . json_encode($endtime), 'info', $logFile ?? $errorLog);
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    private function checkExceptionAndTakeAction($sqsData, $orderId, $message)
    {
        try {
            $userId = $sqsData['user_id'] ?? $this->di->getUser()->id;
            $logFile = "order/amazon/syncOrder/order_sync_reattempt/" . date('Y-m-d') . '/' . $userId . '.log';
            if (is_string($message) && (str_contains($message, "No suitable servers found") ||
                str_contains($message, "Server error")) && !isset($sqsData['force_create'])) {
                if (!empty($sqsData['orders'][$orderId])) {
                    $sqsData['queue_name'] = 'reattempt_order_spapi';
                    $sqsData['orders'] = [$orderId => $sqsData['orders'][$orderId]];
                    $sqsData['force_create'] = true;
                    $sqsData['delay'] = 300;
                    unset($sqsData['handle_added']);
                    $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                    $sqsHelper->pushMessage($sqsData);
                    $this->di->getLog()->logContent("Reattempt Order Data and Exception: " . json_encode([
                        'reattempt_order_data' => $sqsData,
                        'exception' => $message
                    ]), 'info', $logFile);
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent("Exception from checkExceptionAndTakeAction(): " . json_encode($e->getMessage()), 'info', $logFile ?? 'exception.log');
            throw $e;
        }
    }

    private function setAndUpdateAmazonOrderIds($userId, $orderId, $homeShopId)
    {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $amazonOrderIdsCollection = $mongo->getCollectionForTable('amazon_order_ids');
            $setOnInsert = [
                '_id' => $userId . "_" . $orderId . "_" . $homeShopId,
                'source_order_id' => $orderId,
                'user_id' => $userId,
                'home_shop_id' => $homeShopId,
                'created_at' => date('c')
            ];
            $setOnUpdate = [
                'updated_at' => date('c'),
                'force_create' => true
            ];
            $amazonOrderIdsCollection->updateOne(['_id' => $userId . "_" . $orderId . "_" . $homeShopId], ['$setOnInsert' => $setOnInsert, '$set' => $setOnUpdate], ['upsert' => true]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent("Exception from setAndUpdateAmazonOrderIds(): " . json_encode($e->getMessage()), 'info', 'exception.log');
            throw $e;
        }
    }

    public function getCarrierCodes()
    {
        $codes = [
            "DHL eCommerce",
            "WanB Express",
            "Hermes",
            "JCEX",
            "USPS",
            "UPS",
            "UPSMI",
            "FedEx",
            "DHL",
            "Fastway",
            "GLS",
            "GO!",
            "Self Delivery",
            "Hermes Logistik Gruppe",
            "Royal Mail",
            "Parcelforce",
            "City Link",
            "TNT",
            "Target",
            "SagawaExpress",
            "NipponExpress",
            "YamatoTransport",
            "DHL Global Mail",
            "UPS Mail Innovations",
            "FedEx SmartPost",
            "OSM",
            "OnTrac",
            "Streamlite",
            "Newgistics",
            "Canada Post",
            "Blue Package",
            "Chronopost",
            "Deutsche Post",
            "DPD",
            "La Poste",
            "Parcelnet",
            "Poste Italiane",
            "SDA",
            "Smartmail",
            "FEDEX_JP",
            "JP_EXPRESS",
            "NITTSU",
            "SAGAWA",
            "YAMATO",
            "BlueDart",
            "AFL/Fedex",
            "Aramex",
            "India Post",
            "Professional",
            "DTDC",
            "Overnite Express",
            "First Flight",
            "Delhivery",
            "Lasership",
            "Yodel",
            "Other",
            "Amazon Shipping",
            "Seur",
            "SF Express	",
            "Correos",
            "MRW",
            "Endopack",
            "Chrono Express",
            "Nacex",
            "Otro",
            "Correios",
            "Australia Post-Consignment",
            "Australia Post-ArticleId",
            "China Post",
            "4PX",
            "Asendia",
            "Couriers Please",
            "Sendle",
            "SF express",
            "SFC",
            "Singapore Post",
            "Startrack-Consignment",
            "Startrack-ArticleID",
            "Yanwen",
            "YDH",
            "Yun Express",
            "Australia Post",
            "AT POST",
            "BRT"
        ];

        return $codes;
    }

    public function fetchFailedOrders($data)
    {
        if (isset($data['user_id'])) {
            $userId = $data['user_id'];
        } else {
            $userId = $this->_user_id;
        }

        $orderEnabled = false;
        //    $orderStatus = ['Unshipped'];
        $orderStatus = ['Failed'];
        $amazonOrderChannel = 'MFN';

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $configuration = $mongo->getCollectionForTable("configuration");
        $configurationSettings = $configuration->findOne(['user_id' => $userId]);

        if (isset($configurationSettings['data']['order_settings'])) {
            $orderEnabled = $configurationSettings['data']['order_settings']['settings_enabled'];
            //  $orderStatus = $configurationSettings['data']['order_settings']['order_status'];
            // $amazonOrderChannel  = $configurationSettings['data']['order_settings']['amazon_order_channel'];
        } else {
            $orderEnabled = true;
        }

        if ($orderEnabled) {
            $commonHelper = $this->di->getObjectManager()
                ->get(Helper::class);
            $connectedShops = $commonHelper->getAllAmazonShops($userId);

            foreach ($connectedShops as $shop) {
                    $remoteShopId = $shop['remote_shop_id'];
                    $homeShopId = $shop['_id'];
                    $orderId = $data['order_id'] ?? false;
                    $buyerEmail = $data['buyer_email'] ?? false;
                    // $lower = date('Y-m-d H:i:s O', strtotime('-8 hour'));
                    $lower = date('Y-m-d H:i:s O', strtotime('-3 day'));
                    $upper = null;
                    $params['filter']['status']['1'] = 'failed';
                    $params['filter']['object_type']['1'] = 'source_order';

                    $ordersModel = $this->di->getObjectManager()->get('\App\Connector\Models\OrderContainer');
                    $responseData = $ordersModel->getOrders($params);

                    if(isset($responseData['success'], $responseData['data']) && $responseData['success'])
                    {
                        $ordersFailed = $responseData['data']['rows'] ?? [];
                        if(!empty($ordersFailed))
                        {
                            foreach ($ordersFailed as $order)
                            {
                                $connectorOrderHelper = $this->di->getObjectManager()->get('\App\Connector\Components\Order\Order');
                                $dataArray = [
                                    'savedData'    => json_decode(json_encode($order),true),
                                ];


                                $createOrderRequest = $connectorOrderHelper->createSavedOrder($dataArray);
                                return $createOrderRequest;

                            }
                        }
                    }
            }
        } else {
            return ['success' => false, 'message' => 'Order fetch is disabled'];
        }

        return ['success' => true, 'message' => 'Amazon Order(s) fetched'];
    }

    public function tempTestOrder($params)
    {
        $shopId = '64904';
        $response = [];
        if ($params['home_shop_id'] != $shopId || !str_starts_with((string) $params['amazon_order_id'], 'CED_')) {
            return $response;
        }

        $prodSku = '42555294122040';
        $productData = $this->getProduct($params, $prodSku);
        $orderData = $this->getTempOrderData($params, $productData);
        return $orderData;
    }

    public function getTempOrderData($params, $productData)
    {
        $orderData = [
            "region" => "NA",
            "shop_id" => $params['home_shop_id'],
            "home_shop_id" => $params['home_shop_id'],
            "data" => [
                "BuyerInfo" => [
                    "BuyerEmail" => "r5a12nd78033m@marketplace.amazon.com",
                    "BuyerName" => "Bee Smith"
                ],
                "AmazonOrderId" => $params['amazon_order_id'],
                "EarliestDeliveryDate" => date('c', strtotime('+25 days')), //"2023-01-31T08:00:00Z",
                "EarliestShipDate" => date('c', strtotime('+5 days')), //"2023-01-10T08:00:00Z",
                "SalesChannel" => "Amazon.com",
                "AutomatedShippingSettings" => [
                    "HasAutomatedShippingSettings" => false
                ],
                "OrderStatus" => "Unshipped",
                "NumberOfItemsShipped" => 0,
                "OrderType" => "StandardOrder",
                "IsPremiumOrder" => false,
                "IsPrime" => false,
                "FulfillmentChannel" => "MFN",
                "NumberOfItemsUnshipped" => 1,
                "HasRegulatedItems" => false,
                "IsReplacementOrder" => false,
                "IsSoldByAB" => false,
                "LatestShipDate" => date('c', strtotime('+7 days')), //"2023-01-12T07:59:59Z",
                "ShipServiceLevel" => "Std US D2D Dom",
                "DefaultShipFromLocationAddress" => [
                    "AddressLine1" => "3 cité de l'ameublement",
                    "Phone" => "+1 895-225-9463",
                    "PostalCode" => "75011",
                    "City" => "Paris",
                    "CountryCode" => "FR",
                    "Name" => "Bee Smith"
                ],
                "IsISPU" => false,
                "MarketplaceId" => "ATVPDKIKX0DQQ",
                "LatestDeliveryDate" => date('c', strtotime('+30 days')), //"2023-02-23T07:59:59Z",
                "PurchaseDate" => date('c'), //"2023-01-05T06:31:32Z",
                "ShippingAddress" => [
                    "StateOrRegion" => "FL",
                    "PostalCode" => "32720-0940",
                    "City" => "DELAND",
                    "CountryCode" => "US",
                    "AddressLine1" => "3 cité de l'ameublement"
                ],
                "IsAccessPointOrder" => false,
                "PaymentMethod" => "Other",
                "IsBusinessOrder" => false,
                "OrderTotal" => [
                    "CurrencyCode" => "USD",
                    "Amount" => (string)$productData['price']
                ],
                "PaymentMethodDetails" => [
                    "Standard"
                ],
                "IsGlobalExpressEnabled" => false,
                "LastUpdateDate" => date('c'), //"2023-01-05T06:50:29Z",
                "ShipmentServiceLevelCategory" => "Standard"
            ],
            "items" => [
                [
                    "TaxCollection" => [
                        "Model" => "MarketplaceFacilitator",
                        "ResponsibleParty" => "Amazon Services, Inc."
                    ],
                    "ProductInfo" => [
                        "NumberOfItems" => "1"
                    ],
                    "BuyerInfo" => [],
                    "ItemTax" => [
                        "CurrencyCode" => "USD",
                        "Amount" => "0.00"
                    ],
                    "QuantityShipped" => 0,
                    "BuyerRequestedCancel" => [
                        "IsBuyerRequestedCancel" => "false",
                        "BuyerCancelReason" => ""
                    ],
                    "ItemPrice" => [
                        "CurrencyCode" => "USD",
                        "Amount" => (string)$productData['price']
                    ],
                    "ASIN" => "B09NMLVT85",
                    "SellerSKU" => $productData['sku'],
                    "Title" => $productData['title'],
                    "ShippingTax" => [
                        "CurrencyCode" => "USD",
                        "Amount" => "0.00"
                    ],
                    "IsGift" => "false",
                    "ShippingPrice" => [
                        "CurrencyCode" => "USD",
                        "Amount" => "0.00"
                    ],
                    "ConditionSubtypeId" => "New",
                    "ShippingDiscount" => [
                        "CurrencyCode" => "USD",
                        "Amount" => "0.00"
                    ],
                    "ShippingDiscountTax" => [
                        "CurrencyCode" => "USD",
                        "Amount" => "0.00"
                    ],
                    "IsTransparency" => false,
                    "QuantityOrdered" => 1,
                    "PromotionDiscountTax" => [
                        "CurrencyCode" => "USD",
                        "Amount" => "0.00"
                    ],
                    "ConditionId" => "New",
                    "PromotionDiscount" => [
                        "CurrencyCode" => "USD",
                        "Amount" => "0.00"
                    ],
                    "OrderItemId" => "61621206707474"
                ]
            ]
        ];
        $returnData = [
            'orders' => [$orderData],
        ];
        return $returnData;
    }

    public function getBaseMongoAndCollection($collection)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        return $mongo->getCollection($collection);
    }

    public function saveFbaOrders($data): void
    {
        foreach ($data['orders'] as $key => $value) {
           if ($value['data']['FulfillmentChannel']=="AFN") {
            $this->di->getLog()->logContent(PHP_EOL.'entered in fba'.PHP_EOL.$key, 'info', 'fba_orders.log');
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('fba_orders_serverless');
            try {
                $collection->insertOne(['_id'=>$data['user_id'] .'_'. $key, 'amazon_order_id'=>$key,'created_at'=>date('c'),'user_id'=>$data['user_id']]);
            } catch (Exception $th) {
                print_r($th->getMessage());
            }
           }
        }
    }

}
