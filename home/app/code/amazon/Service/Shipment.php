<?php

/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Amazon\Service;

use App\Amazon\Components\Common\Helper;
use Exception;
use App\Connector\Contracts\Sales\Order\ShipInterface;
use App\Amazon\Service\AbstractShipment;
use MongoDB\BSON\UTCDateTime;

/**
 * Interface CurrencyInterface
 * @services
 */
class Shipment extends AbstractShipment implements ShipInterface
{
    private $objectManager;

    private $mongoManager;

    private $orderCollection;

    private ?string $shipmentLog = null;

    /**
     * ship function
     * For preparing Shipment Data and Sending to DynamoDB
     * @param [array] $data
     */
    public function ship($data): array
    {
        if (isset($data['requeue_data'])) {
            $data = $data['requeue_data'];
        }

        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $this->shipmentLog = "shipment/{$userId}/" . date('d-m-Y') . '.log';
        $this->objectManager = $this->di->getObjectManager();
        $this->mongoManager = $this->objectManager->get('\App\Core\Models\User\Details');
        $this->orderCollection = $this->mongoManager->getCollectionForTable('order_container');
        $orderFilter = [
            'user_id' => $userId,
            'object_type' => 'source_order',
            'marketplace' => $data['marketplace'] ?? 'amazon',
            'marketplace_shop_id' => $data['marketplace_shop_id'],
            'marketplace_reference_id' => $data['marketplace_reference_id'],
        ];
        $orderData = $this->getOrderData($orderFilter);
        if (!empty($orderData)) {
            $connectorOrderService = $this->objectManager
                ->get(\App\Connector\Service\Shipment::class, []);
            if (isset($orderData['fulfilled_by']) && $orderData['fulfilled_by'] == 'other') {
                $connectorOrderService->setSourceStatus(
                    $data['marketplace_reference_id'],
                    $data['targets'][0]['marketplace'],
                    true,
                    'Unknown',
                    $userId
                );
                return ['success' => false, 'message' => 'FBA order found'];
            } else if ($orderData['marketplace_status'] == 'Canceled') {
                $this->setShipmentError($orderFilter, 'Failed', 'Order Cancelled on Amazon');
            } else if (in_array('Easy Ship', $orderData['filter_tags'])) {
                $this->setShipmentError($orderFilter, 'Failed', 'This is an Easy Ship order and must be shipped using Amazon’s designated method');
                return ['success' => false, 'message' => 'Easy ship order found'];
            }

        }
        $serverless = false;
        $configuration = $this->checkShipmentSettings($data);
        if (isset($configuration['shipment']) && $configuration['shipment']) {
            if (isset($data['retry']) && $data['retry'] > 3) {
                $throttleFile = "shipment/throttle/" . date('d-m-Y') . '.log';
                $this->di->getLog()->logContent('Throttle Error Data=> '
                    . json_encode([
                        'user_id' => $userId,
                        'order_id' => $data['marketplace_reference_id']
                    ]), 'info', $throttleFile);
                $this->orderCollection->updateOne($orderFilter, ['$set' => ['shipment_throttle' => true, 'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))]]);
                $serverless = true;
            } elseif (isset($data['serverless']) && $data['serverless']) {
                $serverless = true;
            }
            if (isset($configuration['shipment_sync_max_age']) && $configuration['shipment_sync_max_age'] != 0)
                if (!$this->isAllowedToShip($data, $configuration)) {
                    $connectorOrderService->setSourceStatus(
                        $data['marketplace_reference_id'],
                        $data['targets'][0]['marketplace'],
                        false,
                        'Unable to ship this order as shipment sync max age has passed.',
                        $userId
                    );
                }
            $shopData = $this->mongoManager->getShop($data['shop_id'], $userId);
            $remoteShopId = $shopData['remote_shop_id'] ?? "";
            $data['marketplaceId'] = $shopData['warehouses'][0]['marketplace_id'] ?? "";
            if (empty($remoteShopId) || empty($data['marketplaceId'])) {
                $this->di->getLog()->logContent('Remote shop or marketplace_id not found: ', 'info', $this->shipmentLog);
                return ['success' => false, 'message' => 'Remote shop or marketplace_id not found'];
            }

            if (empty($data['tracking']['number'])) {
                $serverless = true;
            }

            if($serverless && !$this->checkAccountIsActive($shopData)) {
                $this->setShipmentError($orderFilter, 'Failed', 'Account is in vacation mode or status is inactive.Kindly resolve the problem to ship the order.');
                return ['success' => false, 'message' => 'Warehouse status is inactive'];
            }

            $fulfillmentData = $this->prepareShipmentData($data, $configuration, $serverless);
            if (!empty($fulfillmentData)) {
                $this->di->getLog()->logContent('Shipment Data send on Amazon: '
                    . json_encode($fulfillmentData, 128), 'info', $this->shipmentLog);
                if ($serverless) {
                    return $this->shipmentFeedCall($data, $fulfillmentData, $remoteShopId);
                }
                return $this->confirmShipmentCall($data, $fulfillmentData, $remoteShopId);
            }
            $message = 'Please provide valid Tracking Details';
        } else {
            $message = 'Shipment Sync setting is off';
        }

        $this->di->getLog()->logContent('Shipment Sync Failed Error: '. json_encode($message), 'info', $this->shipmentLog);
        $this->setShipmentError($orderFilter, 'Failed', $message ?? 'Something went wrong');

        return ['success' => false, 'message' => $message];
    }

    /**
     * isAllowedToShip function
     * To check is shipment sync is allowed based on settings
     * @param [array] $data
     * @param [array] $configuration
     * @return bool
     */
    private function isAllowedToShip($data, $configuration)
    {
        if (!empty($configuration['shipment_sync_max_age'])) {
            if (!empty($data['source_created_at'] && !empty($data['source_updated_at']))) {
                $differenceInSeconds = strtotime($data['source_updated_at']) - strtotime($data['source_created_at']);
                $differenceInDays = floor($differenceInSeconds / (60 * 60 * 24));
                if ($differenceInDays > $configuration['shipment_sync_max_age']) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * getOrderData function
     * To get source order data
     * @param [array] $filter
     * @return array
     */
    private function getOrderData($filter)
    {
        $options = [
            'projection' => [
                'user_id' => 1,
                'marketplace_reference_id' => 1,
                'fulfilled_by' => 1,
                'marketplace_status' => 1,
                'filter_tags' => 1
            ],
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ];
        return $this->orderCollection->findOne($filter, $options);
    }

    /**
     * checkShipmentSettings function
     * Checking shipment sync setting is enabled or not
     * @param [array] $data
     * @return array
     */
    private function checkShipmentSettings($data)
    {
        $configObj = $this->objectManager->get('\App\Core\Models\Config\Config');
        $configObj->setAppTag(null);

        $config = $this->objectManager->get('\App\Connector\Components\Profile\DefaultSettings');
        $configData = $config->getDefaultSettings([
            'settings' => ['order'],
            'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
            'source' => ['shopId' => $data['targets'][0]['shop_id']],
            'target' => ['shopId' => $data['shop_id']]
        ]);
        $configuration = $configData['data'][0]['value'] ?? [];
        $defaultShipmentSetting = Helper::DEFAULT_ORDER_SETTING;
        if (!empty($configuration)) {
            if (!isset($configuration['shipment'])) {
                $configuration['shipment'] = $defaultShipmentSetting['shipment'];
            }

            if (!isset($configuration['tracking_number'])) {
                $configuration['tracking_number'] = $defaultShipmentSetting['tracking_number'];
            }

            return $configuration;
        }

        return $defaultShipmentSetting;
    }

    /**
     * prepareShipmentData function
     * Preparing shipment data to be send on remote
     * @param [array] $data
     * @param [bool] $trackingRequiredSetting
     * @param [array] $configuration
     * @param [bool] $serverless
     * @return array
     */
    private function prepareShipmentData($data, $configuration, $serverless)
    {
        try {
            if (!$this->di->getCache()->get('amazon_carrier_codes')) {
                $shipmentHelper = $this->objectManager->get('\App\Connector\Models\Shipment\Helper');
                $carrierResponse =  $shipmentHelper->fetchCarriers(['target' => 'amazon']);
                $this->di->getCache()->set("amazon_carrier_codes", $carrierResponse['message'] ?? []);
            }
            $carrierCodes = $this->di->getCache()->get('amazon_carrier_codes');
            $trackingRequiredSetting = $configuration['tracking_number'] ?? false;
            $trackingCompanyRequiredSetting = $configuration['tracking_company'] ?? false;
            if ((!$trackingRequiredSetting || !empty($data['tracking']['number'])) && (!$trackingCompanyRequiredSetting || !empty($data['tracking']['company']))) {
                $sourceMarketplace = $data['targets'][0]['marketplace'];
                $shippingMethod = $data['shipping_method']['code'] ?: 'Other';
                $orderCarrierCode = $shippingMethod;
                if (isset($configuration['shipping_carrier_map']) && $configuration['shipping_carrier_map']) {
                    $mappedCarriers = $configuration['carrier_codes'] ?? [];
                    foreach ($mappedCarriers as $value) {
                        if ($value[$sourceMarketplace . '_carrier_code'] == $data['shipping_method']['code']) {
                            $orderCarrierCode = $value['amazon_carrier_code'];
                            if (isset($value['shipping_service'])) {
                                $shippingService = $value['shipping_service'];
                            }

                            if (isset($value['carrier_name'])) {
                                $orderCarrierName = $value['carrier_name'];
                            }

                            break;
                        }
                    }
                }
                if (!in_array($orderCarrierCode, $carrierCodes)) {
                    $orderCarrierCode = "Other";
                    if (!empty($data['shipping_method']['code'])) {
                        if (!empty($configuration['carrier_codes'])) {
                            $this->validateCarrierMapping($data, $configuration['carrier_codes'], $sourceMarketplace);
                        } else {
                            $this->shouldMapUnacceptedCarrier($data, $sourceMarketplace);
                        }
                    }
                }

                if ($serverless) {
                    $shipment[$data['marketplace_shipment_id']] = [
                        'Id' => $data['marketplace_shipment_id'],
                        'AmazonOrderID' => $data['marketplace_reference_id'],
                        'FulfillmentDate' => date('Y-m-d H:i:s P', strtotime((string) $data['source_created_at'])),
                        'FulfillmentData' => [
                            'CarrierCode' => $orderCarrierCode,
                            'ShippingMethod' => $shippingService ?? $shippingMethod,
                            'ShipperTrackingNumber' => $data['tracking']['number'],
                            'validated' => true
                        ]
                    ];
                    if ($orderCarrierCode == 'Other' || isset($orderCarrierName)) {
                        $shipment[$data['marketplace_shipment_id']]['FulfillmentData']['CarrierName'] = $orderCarrierName ?? $shippingMethod;
                    }

                    foreach ($data['items'] as $lineItem) {
                        $shipment[$data['marketplace_shipment_id']]['Item'][] =
                            [
                                'AmazonOrderItemCode' => $lineItem['marketplace_item_id'] ?? "",
                                'Quantity' => $lineItem['shipped_qty']
                            ];
                    }

                    if (isset($configuration['shipping_service'])) {
                        $shipment[$data['marketplace_shipment_id']]['FulfillmentData']['ShippingMethod'] = $configuration['shipping_service'];
                    }
                } else {
                    foreach ($data['items'] as $lineItem) {
                        $orderItems[] =
                            [
                                'orderItemId' => $lineItem['marketplace_item_id'] ?? "",
                                'quantity' => $lineItem['shipped_qty']
                            ];
                    }

                    $shipment[$data['marketplace_shipment_id']] = [
                        "marketplace_reference_id" => $data['marketplace_reference_id'],
                        "marketplaceId" => $data['marketplaceId'],
                        "packageDetail" => [
                            "packageReferenceId" => $data['marketplace_shipment_id'],
                            "carrierCode" => $orderCarrierCode,
                            "carrierName" => $orderCarrierCode,
                            "shippingMethod" => $shippingService ?? $shippingMethod,
                            "trackingNumber" => $data['tracking']['number'],
                            "shipDate" => date('Y-m-d\TH:i:s.u\Z', strtotime((string) $data['source_created_at'])),
                            "orderItems" => $orderItems
                        ]
                    ];
                    if ($orderCarrierCode == 'Other' || isset($orderCarrierName)) {
                        $shipment[$data['marketplace_shipment_id']]["packageDetail"]["carrierName"] = $orderCarrierName ?? $shippingMethod;
                        $mappedCarrierNames = [];
                        if (!$this->di->getCache()->get('amazon_carrier_name_mapping')) {
                            $filePath = CODE . DS . 'amazon' . DS . 'utility' . DS . 'carrier_name_mapping.json';
                            if (file_exists($filePath)) {
                                $mappedCarrierNames = json_decode(file_get_contents($filePath), true);
                                $this->di->getCache()->set("amazon_carrier_name_mapping", $mappedCarrierNames ?? []);
                            }
                        }

                        $mappedCarrierNames = $this->di->getCache()->get('amazon_carrier_name_mapping');
                        if(isset($mappedCarrierNames[$shipment[$data['marketplace_shipment_id']]["packageDetail"]["carrierName"]])) {
                            $shipment[$data['marketplace_shipment_id']]["packageDetail"]["carrierName"] = $mappedCarrierNames[$shipment[$data['marketplace_shipment_id']]["packageDetail"]["carrierName"]];
                        }
                    }

                    if (isset($configuration['shipping_service'])) {
                        $shipment[$data['marketplace_shipment_id']]["packageDetail"]["shippingMethod"] = $configuration['shipping_service'];
                    }
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception Amazon\Service\Shipment.php prepareShipmentData(): '
                . json_encode($e->getMessage()), 'emergency', 'exception.log');
        }

        return $shipment ?? [];
    }

    /**
     * validateCarrierMapping function
     * Preparing mapping data for not accepted carrier code
     * @param [array] $data
     * @param [array] $configuration
     * @param [string] $sourceMarketplace
     */
    private function validateCarrierMapping($data, $configuration, $sourceMarketplace): void
    {
        $carrierMapping = json_decode(json_encode($configuration), true);
        $targetMarketplace = $data['marketplace'];
        $sourceCarrierCode = $data['shipping_method']['code'];
        $mappedSourceCarriers = array_column($carrierMapping, $sourceMarketplace . '_carrier_code');
        if (!in_array($sourceCarrierCode, $mappedSourceCarriers)) {
            $mappingData = [
                $sourceMarketplace . '_carrier_code' => $sourceCarrierCode,
                $targetMarketplace . '_carrier_code' => '',
            ];
            $config = $this->mongoManager->getCollectionForTable('config');
            $config->updateOne(
                [
                    'user_id' => $data['user_id'],
                    'group_code' => 'order',
                    'key' => 'carrier_codes',
                    'target' => $targetMarketplace,
                    'target_shop_id' => $data['shop_id']
                ],
                [
                    '$addToSet' => ['value' => $mappingData]
                ]
            );
        }
    }


    /**
     * shouldMapUnacceptedCarrier function
     * Enabling Carrier Mapping setting in case of unaccepted carrier
     * @param [array] $data
     * @param [string] $sourceMarketplace
     */
    private function shouldMapUnacceptedCarrier($data, $sourceMarketplace)
    {
        $configObj = $this->di->getObjectManager()->get(\App\Core\Models\Config\Config::class);
        $shippingCarrierMapData = [
            'user_id' => $data['user_id'],
            'group_code' => 'order',
            'key' => 'shipping_carrier_map',
            'value' => true,
            'source' => $data['targets'][0]['marketplace'],
            'source_shop_id' => $data['targets'][0]['shop_id'],
            'target' => $data['marketplace'],
            'target_shop_id' => $data['shop_id'],
            'app_tag' => $this->di->getAppCode()->getAppTag()
        ];

        $configObj->setUserId($data['user_id']);
        $configObj->setAppTag($shippingCarrierMapData['app_tag']);
        $configObj->setConfig([$shippingCarrierMapData]);

        $mappingData = [
            $sourceMarketplace . '_carrier_code' => $data['shipping_method']['code'],
            $data['marketplace'] . '_carrier_code' => '',
        ];

        $carrierCodesData = [
            'user_id' => $data['user_id'],
            'group_code' => 'order',
            'key' => 'carrier_codes',
            'source' => $data['targets'][0]['marketplace'],
            'source_shop_id' => $data['targets'][0]['shop_id'],
            'value' => [$mappingData],
            'target' => $data['marketplace'],
            'target_shop_id' => $data['shop_id'],
            'app_tag' => $this->di->getAppCode()->getAppTag()
        ];

        $configObj->setUserId($data['user_id']);
        $configObj->setAppTag($carrierCodesData['app_tag']);
        $configObj->setConfig([$carrierCodesData]);
    }

    /**
     * shipmentFeedCall function
     * Preparing feed content and sending data to DynamoDB
     * @param [array] $data
     * @param [array] $fulfillmentData
     * @param [string] $remoteShopId
     */
    public function shipmentFeedCall($data, $fulfillmentData, $remoteShopId): array
    {
        $specifics = [
            'ids' => array_keys($fulfillmentData),
            'shop_id' => $remoteShopId,
            'sourceShopId' => $data['targets'][0]['shop_id'],
            'home_shop_id' => $data['shop_id'],
            'feedContent' => base64_encode(json_encode($fulfillmentData)),
            'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
            'source_order_id' => $data['targets'][0]['order_id'],
            'target_order_id' => $data['marketplace_reference_id'],
            'operation_type' => 'Update'
        ];
        $commonHelper = $this->objectManager->get(Helper::class);
        $response = $commonHelper->sendRequestToAmazon('shipment-sync', $specifics, 'POST');
        $success = $response['success'] ?? false;
        $message = $success ? 'Shipment Synced Successfully!!' : $response['message'] ?? $response['msg'] ?? 'Something went wrong';
        if (!$success) {
            $this->di->getLog()->logContent('Error from shipment feed call: ' . json_encode($response), 'info', $this->shipmentLog);
            $shipmentMail = $this->checkMailSetting($data);
            $connectorOrderService = $this->objectManager
            ->get(\App\Connector\Service\Shipment::class, []);
            $connectorOrderService->setSourceStatus(
                $data['marketplace_reference_id'],
                $data['targets'][0]['marketplace'],
                false,
                'During shipment sync something went wrong.Kindly contact support',
                $data['user_id'],
                $shipmentMail
            );
        }
        $filter = $this->getShipmentDataFilter($data);
        $preparedData = [
            'status' => $response['success'] ? 'DONE' : 'ERROR',
            'submit_data' => $fulfillmentData[$data['marketplace_shipment_id']],
            'response_data' => ['success' => $success, 'message' => $message],
            'created_at' => date('c')
        ];
        $update = [
            '$set' => [
                'tracking.company' => $fulfillmentData[$data['marketplace_shipment_id']]['FulfillmentData']['CarrierCode'] ?? '',
                'tracking.shipping_service' => $fulfillmentData[$data['marketplace_shipment_id']]['FulfillmentData']['ShippingMethod'] ?? ''
            ],
            '$addToSet' => ['shipment_sync_history' => $preparedData]
        ];
        $this->orderCollection->updateOne($filter, $update);
        return ['success' => $success, 'message' => $message];
    }

    /**
     * confirmShipmentCall function
     * Syncing Shipment details of single order
     * @param [array] $data
     * @param [array] $fulfillmentData
     * @param [string] $remoteShopId
     */
    public function confirmShipmentCall($data, $fulfillmentData, $remoteShopId): array
    {
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $specifics = [
            'shop_id' => $remoteShopId,
            'fulfillmentData' => $fulfillmentData[$data['marketplace_shipment_id']]
        ];
        $connectorOrderService = $this->objectManager
            ->get(\App\Connector\Service\Shipment::class, []);
        $commonHelper = $this->objectManager
            ->get(Helper::class);
        $response = $commonHelper->sendRequestToAmazon('shipment-confirm', $specifics, 'POST');
        if (isset($response['success']) && $response['success']) {
            $connectorOrderService->setSourceStatus(
                $data['marketplace_reference_id'],
                $data['targets'][0]['marketplace'],
                true,
                'Unknown',
                $userId
            );
            $responseData = [
                'success' => true,
                'message' => 'Shipment Synced Successfully!!'
            ];
        } elseif (!empty($response['message']) && is_string($response['message'])) {
            if (str_contains((string) $response['message'], "Api limit exceed") || str_contains((string) $response['message'], "cURL error")) {
                $this->handleThrottle($data);
                return ['success' => false, 'message' => $response['message']];
            }
            if (str_contains((string) $response['message'], "MerchantOrderId : null is invalid") ||
                str_contains((string) $response['message'], "Failed to create package") || str_contains((string) $response['message'], "Access to requested resource is denied")) {
                $data['serverless'] = true;
                return $this->ship($data);
            }
            $shipmentMail = $this->checkMailSetting($data);
            if (isset($data['shipment_mail']) && !$data['shipment_mail']) {
                $shipmentMail = false;
            }
            $connectorOrderService->setSourceStatus(
                $data['marketplace_reference_id'],
                $data['targets'][0]['marketplace'],
                false,
                $response['message'],
                $userId,
                $shipmentMail
            );
            $responseData = [
                'success' => false,
                'message' => $response['message'],
                'code' => (string)($response['code'] ?? ''),
                'errorCode' => (string)($response['errorCode'] ?? '')
            ];
        } else {
            $shipmentMail = $this->checkMailSetting($data);
            $connectorOrderService->setSourceStatus(
                $data['marketplace_reference_id'],
                $data['targets'][0]['marketplace'],
                false,
                'During shipment sync something went wrong.Kindly contact support',
                $userId,
                $shipmentMail
            );
            $responseData = [
                'success' => false,
                'message' => 'Something went wrong',
                'error' => $response
            ];
        }

        $filter = $this->getShipmentDataFilter($data);
        $preparedData = [
            'status' => $response['success'] ? 'DONE' : 'ERROR',
            'submit_data' => $fulfillmentData[$data['marketplace_shipment_id']]['packageDetail'],
            'response_data' => $responseData,
            'created_at' => date('c'),
        ];
        $update = [
            '$set' => [
                'tracking.company' => $fulfillmentData[$data['marketplace_shipment_id']]['packageDetail']['carrierCode'] ?? '',
                'tracking.shipping_service' => $fulfillmentData[$data['marketplace_shipment_id']]['packageDetail']['shippingMethod'] ?? '',
            ],
            '$addToSet' => ['shipment_sync_history' => $preparedData]
        ];
        $this->orderCollection->updateOne($filter, $update);
        return $responseData;
    }

    /**
     * handleThrottle function
     * Handling confirm shipment API throttle by re-queue data
     * @param [array] $data
     */
    private function handleThrottle($data): void
    {
        if (!isset($data['retry'])) {
            $data['retry'] = 1;
        } else {
            $data['retry'] += 1;
        }

        $handlerData = [
            'type' => 'full_class',
            'method' => 'ship',
            'class_name' => \App\Amazon\Service\Shipment::class,
            'queue_name' => 'handle_shipment_throttle',
            'user_id' => $data['user_id'] ?? $this->di->getUser()->id,
            'requeue_data' => $data,
            'delay' => 30,

        ];
        $sqsHelper = $this->objectManager->get('App\Core\Components\Message\Handler\Sqs');
        $sqsHelper->pushMessage($handlerData);
    }

    /**
     * checkMailSetting function
     * Checking shipment mail setting is enabled or not
     * @param [array] $data
     * @return array
     */
    private function checkMailSetting($data): bool
    {
        $configObj = $this->objectManager->get('\App\Core\Models\Config\Config');
        $configObj->setGroupCode('email');
        $configObj->setUserId($data['user_id']);
        $configObj->setTarget($data['marketplace']);
        $configObj->setTargetShopId($data['shop_id']);
        $configObj->setAppTag(null);

        $settingConfiguration = $configObj->getConfig('email_on_failed_shipment');
        if (!empty($settingConfiguration) && $settingConfiguration[0]['value']) {
            return true;
        }

        return false;
    }

    /**
     * getShipmentDataFilter function
     * Preparing source or target shipment doc filter
     * @param [array] $data
     * @return array
     */
    private function getShipmentDataFilter($data)
    {
        return [
            'user_id' => $data['user_id'],
            'object_type' => $data['object_type'],
            'marketplace_shop_id' => $data['shop_id'],
            'marketplace_reference_id' => $data['marketplace_reference_id'],
            'marketplace_shipment_id' => $data['marketplace_shipment_id']
        ];
    }

    /**
     * setShipmentError function
     * Setting shipment error with status
     * @param [array] $data
     * @return array
     */
    private function setShipmentError($orderFilter, $status, $errorMessage)
    {
        $this->orderCollection->updateOne(
            $orderFilter,
            [
                '$set' => ['shipping_status' => $status, 'updated_at_iso' => new UTCDateTime((int)(microtime(true) * 1000))],
                '$addToSet' => ['shipment_error' => $errorMessage]
            ]
        );
    }

    /**
     * checkAccountIsActive function
     * Checking account is active or not
     * @param [array] $shop
     * @return boolean
     */
    private function checkAccountIsActive($shop)
    {
        if (!empty($shop['warehouses'])) {
            foreach ($shop['warehouses'] as $warehouse) {
                if ($warehouse['status'] == 'inactive') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * isShippable function
     * Abstract Function
     */
    public function isShippable(): bool
    {
        return true;
    }

    /**
     * mold function
     * Abstract function
     */
    public function mold($data): array
    {
        return [];
    }
}
