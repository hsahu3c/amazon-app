<?php

namespace App\Amazon\Components\AmazonBuyShipping;

use App\Amazon\Components\AmazonBuyShipping\Helpers\GetRatesHelper;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\AmazonBuyShipping\Helpers\PurchaseShipmentHelper;
use App\Core\Models\Config\Config;
use App\Amazon\Components\AmazonBuyShipping\Helpers\GetTrackingHelper;
use App\Amazon\Components\AmazonBuyShipping\Helpers\CancelShipmentHelper;
use App\Core\Models\BaseMongo;
use MongoDB\BSON\ObjectId;
use App\Connector\Contracts\Sales\OrderInterface;
use App\Connector\Models\Product\Marketplace;
use App\Amazon\Components\AmazonBuyShipping\Helpers\GetShipmentDocumentHelper;
use App\Core\Components\Base;

class AmazonBuyShipping extends Base
{
    public $testUserIds = null;

    public function getRates($params)
    {
        $getRatesHelper = $this->di
            ->getObjectManager()
            ->get(GetRatesHelper::class);

        $shipToResp = $getRatesHelper->getShipToUsingOrderData($params);
        if (!$shipToResp['success']) {
            return $shipToResp;
        }

        $shipTo = $shipToResp['data'];

        $shipFromResp = $getRatesHelper->getShipFromUsingOrderData($params);
        if (!$shipFromResp['success']) {
            return $shipFromResp;
        }

        $shipFrom = $shipFromResp['data'];

        $returnToResponse = $getRatesHelper->getReturnTo($params);
        $returnTo = isset($returnToResponse['success']) && $returnToResponse['success']
            ? $returnToResponse['data'] :
            false;

        $shipDateResponse = $getRatesHelper->getShipDate($params);
        if (!$shipDateResponse['success']) {
            return $shipDateResponse;
        }

        $shipDate = $shipDateResponse['data'];

        $packagesResp = $getRatesHelper->getPackages($params);
        if (!$packagesResp['success']) {
            return $packagesResp;
        }

        $packages = $packagesResp['data'];

        // $valueAddedServices = $getRatesHelper->getValueAddedServices($params);
        // $taxDetails = $getRatesHelper->getTaxDetails($params);

        $channelDetailsResp = $getRatesHelper->getChannelDetailsFromOrderData($params);
        if (!$channelDetailsResp['success']) {
            return $channelDetailsResp;
        }

        $channelDetails = $channelDetailsResp['data'];

        $shopIdRes = $this->getShopIdFromOrder($params);
        if (!$shopIdRes['success']) {
            return $shopIdRes;
        }

        $shopId = $shopIdRes['data'];

        $requestData = array_filter(compact(
            'shipTo',
            'shipFrom',
            'returnTo',
            'shipDate',
            'packages',
            'channelDetails'
        ), fn($value): bool => $value !== null && $value !== false);

        $remoteResponse = $this->getRatesFromAmazon($requestData, $shopId);
        if (!$remoteResponse['success']) {
            if (isset($remoteResponse['error']['errors'][0]['message'])) {
                return [
                    'success' => false,
                    // 'message' => $remoteResponse['error']['errors'][0]['message'],
                    'message' => $remoteResponse['error']['errors'][0]['details']
                ];
            }
        }

        $this->updateSuccessResponse($remoteResponse);
        return $remoteResponse;
        //save data in db
    }

    public function getRatesFromAmazon($params, $shopId)
    {
        $requestData['postParams'] = json_encode($params);
        $requestData['shop_id'] = $shopId;
        $requestData['sandbox'] = $this->useSandbox();

        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        return $commonHelper->sendRequestToAmazon('getRates', $requestData, 'POST');
    }

    public function getShopIdFromOrder($params)
    {
        $shopId = $params['marketplace_shop_id'] ?? $this->di->getRequester()->getTargetId() ?? false;
        if (!$shopId) {
            return ['success' => false, 'message' => 'shop_id not found'];
        }

        $remoteShopId = null;
        $shops = $this->di->getUser()->shops ?? [];
        foreach ($shops as $shop) {
            if ($shop['_id'] == $shopId) {
                $remoteShopId = $shop['remote_shop_id'] ?? '';
                break;
            }
        }

        if ($remoteShopId == null) {
            return ['success' => false, 'message' => 'remote_shop_id not found'];
        }

        return ['success' => true, 'data' => $remoteShopId];
    }

    public function purchaseShipment($params)
    {
        $requestData = $params['request_data'] ?? [];
        $purchaseShipmentHelper = $this->di
            ->getObjectManager()
            ->get(PurchaseShipmentHelper::class);
        $getRequestTokenResp = $purchaseShipmentHelper->getRequestToken($requestData);
        if (!$getRequestTokenResp['success']) {
            return $getRequestTokenResp;
        }

        $requestToken = $getRequestTokenResp['data'];
        $getRateIdResp = $purchaseShipmentHelper->getRateId($requestData);
        if (!$getRateIdResp['success']) {
            return $getRateIdResp;
        }

        $rateId = $getRateIdResp['data'];
        $requestDocSpec = $purchaseShipmentHelper->getRequestedDocumentSpecification($requestData);
        if (!$requestDocSpec['success']) {
            return $requestDocSpec;
        }

        $requestedDocumentSpecification = $requestDocSpec['data'];
        if (is_null($requestedDocumentSpecification['dpi']) || $requestedDocumentSpecification['dpi'] == 0) {
            unset($requestedDocumentSpecification['dpi']);
        }
        $valueAddedServiceResp = $purchaseShipmentHelper->getRequestedValueAddedServices($requestData);
        if (!$valueAddedServiceResp['success']) {
            return $valueAddedServiceResp;
        }

        $requestedValueAddedServices = $valueAddedServiceResp['data'] ?? null;

        $shopIdRes = $this->getShopIdFromOrder($params['additional_data']['order_data']);
        if (!$shopIdRes['success']) {
            return $shopIdRes;
        }

        $shopId = $shopIdRes['data'];

        $remoteResponse = $this->purchaseShipmentOnAmazon(compact(
            'requestToken',
            'rateId',
            'requestedDocumentSpecification',
            'requestedValueAddedServices'
        ), $shopId);
        if (!$remoteResponse['success'] ?? false) {
            if (isset($remoteResponse['error']['errors'][0]['message'])) {
                return [
                    'success' => false,
                    // 'message' => $remoteResponse['error']['errors'][0]['message'],
                    'message' => $remoteResponse['error']['errors'][0]['details']
                ];
            }
        }

        $this->updateSuccessResponse($remoteResponse);
        return $remoteResponse;
        // $this->printShippingLabels($remoteResponse['response_data']['payload']);
        //save response in db
    }

    public function purchaseShipmentOnAmazon($params, $shopId)
    {
        $requestData['postParams'] = json_encode($params);
        $requestData['shop_id'] = $shopId;
        $requestData['sandbox'] = $this->useSandbox();
        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        return $commonHelper->sendRequestToAmazon('purchaseShipment', $requestData, 'POST');
    }

    public function useSandbox()
    {
        $configObj = $this->di->getObjectManager()->get(Config::class);
        $configObj->setGroupCode("sandbox");
        $configObj->setAppTag(null);
        $configObj->setUserId($this->di->getUser()->id);

        $sourceShopId = $this->di->getRequester()->getSourceId() ?? false;
        $targetShopId = $this->di->getRequester()->getTargetId() ?? false;
        $configObj->setSourceShopId($sourceShopId);
        $configObj->setTargetShopId($targetShopId);

        $configResponse = $configObj->getConfig('buy_shipping_setting_enabled');
        return $configResponse[0]['value'] ?? false;
    }

    public function printShippingLabels($params): void
    {
        foreach ($params['packageDocumentDetails'] as $packageDocumentDetail) {
            foreach ($packageDocumentDetail['packageDocuments'] as $packageDocument) {
                if ($packageDocument['type'] == 'LABEL') {
                    $contents = $packageDocument['contents'];
                    if ($packageDocument['format'] == 'PDF') {
                        echo $contents;
                    } elseif ($packageDocument['format'] == 'PNG') {
                        echo "<img src='data:image/png;base64, {$contents}' alt='shipping label' />";
                    }
                }
            }
        }
    }

    public function getTracking($params)
    {
        $getTrackingHelper = $this->di
            ->getObjectManager()
            ->get(GetTrackingHelper::class);

        $getTrackingIdResp = $getTrackingHelper->getTrackingId($params);
        if (!$getTrackingIdResp['success']) {
            return $getTrackingIdResp;
        }

        $trackingId = $getTrackingIdResp['data'];

        $getCarrierIdResp = $getTrackingHelper->getCarrierId($params);
        if (!$getCarrierIdResp['success']) {
            return $getCarrierIdResp;
        }

        $carrierId = $getCarrierIdResp['data'];

        $shopIdResp = $this->getShopIdFromOrder($params);
        if (!$shopIdResp['success']) {
            return $shopIdResp;
        }

        $shopId = $shopIdResp['data'];
        $remoteResponse = $this->getTrackingFromAmazon(compact('trackingId', 'carrierId'), $shopId);
        if (!$remoteResponse['success']) {
            if (isset($remoteResponse['error']['errors'][0]['message'])) {
                return [
                    'success' => false,
                    // 'message' => $remoteResponse['error']['errors'][0]['message'],
                    'message' => $remoteResponse['error']['errors'][0]['details']
                ];
            }
        }

        $this->updateSuccessResponse($remoteResponse);
        return $remoteResponse;
        //todo - improve above code
    }

    public function getTrackingFromAmazon($params, $shopId)
    {
        $requestData['postParams'] = json_encode($params);
        $requestData['shop_id'] = $shopId;
        $requestData['sandbox'] = $this->useSandbox();
        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        return $commonHelper->sendRequestToAmazon('buy-shipping/get-tracking', $requestData, 'GET');
    }

    public function cancelShipment($params)
    {
        $cancelShipmentHelper = $this->di
            ->getObjectManager()
            ->get(CancelShipmentHelper::class);
        $cancelShipmentResp = $cancelShipmentHelper->getShipmentId($params);
        if (!$cancelShipmentResp['success']) {
            return $cancelShipmentResp;
        }

        $shipmentId = $cancelShipmentResp['data'];
        $shopIdRes = $this->getShopIdFromOrder($params);
        if (!$shopIdRes['success']) {
            return $shopIdRes;
        }

        $shopId = $shopIdRes['data'];
        $remoteResponse = $this->cancelShipmentOnAmazon(compact('shipmentId'), $shopId);

        if (!$remoteResponse['success']) {
            if (isset($remoteResponse['error']['errors'][0]['message'])) {
                return [
                    'success' => false,
                    // 'message' => $remoteResponse['error']['errors'][0]['message'],
                    'message' => $remoteResponse['error']['errors'][0]['details']
                ];
                //todo:- update all error cases because if no errors set success case will execute
            }
        }

        $this->updateCancelledShipment($params);
        return ['success' => true, 'message' => 'Shipment cancelled successfully.'];
    }

    public function cancelShipmentOnAmazon($params, $shopId)
    {
        $requestData['postParams'] = json_encode($params);
        $requestData['shop_id'] = $shopId;
        $requestData['sandbox'] = $this->useSandbox();
        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        return $commonHelper->sendRequestToAmazon('buy-shipping/cancel-shipment', $requestData, 'POST');
    }

    public function updateCancelledShipment($params)
    {
        $sourceOrder = $this->getOrderService()->getAll([
            'filter' => [
                'user_id' => [
                    "1" => $params['user_id'] ?? $this->di->getUser()->_id
                ],
                'object_type' => [
                    "1" => 'source_order'
                ],
                'marketplace' => [
                    "1" => 'amazon'
                ],
                'marketplace_shop_id' => [
                    "1" => $params['marketplace_shop_id'] ?? $params['shop_id'] ?? $this->di->getRequester()->getTargetId()
                ],
                'marketplace_reference_id' => [
                    '1' => $params['marketplace_reference_id'] ?? ''
                ]
            ]
        ]);
        if (empty($sourceOrder['data']['rows'])) {
            return ['success' => false, 'message' => 'Order not found!'];
        }

        $sourceOrder = $sourceOrder['data']['rows'][0];
        $filter = [
            '_id' => $sourceOrder['_id']
        ];
        $arrayFilter = [
            'arrayFilters' =>
            [
                [
                    'service.shipment_id' => $params['shipment_id'],
                ]
            ]
        ];
        $updateData = [
            '$set' => [
                'shipping_services.$[service].status' => 'Cancelled',
                'shipping_services.$[service].label_generated' => false,
                'shipping_status' => 'Cancelled',
                'marketplace_status' => 'Unshipped',
            ]
        ];
        $orderContainer = $this->di->getObjectManager()
            ->create(BaseMongo::class)
            ->getCollectionForTable('order_container');

        $response = $orderContainer->updateOne($filter, $updateData, $arrayFilter);

        $shippingService = array_filter($sourceOrder['shipping_services'], fn(array $shippingService): bool => $shippingService['shipment_id'] == $params['shipment_id']);

        $shippingServiceFilter = [
            '_id' => is_string($shippingService['_id'])
                ? new ObjectId($shippingService['_id']) : $shippingService['_id']
        ];
        $updateData = [
            '$set' => [
                'marketplace_status' => 'Cancelled',
                'status' => 'Cancelled'
            ]
        ];
        $orderContainer->updateOne($shippingServiceFilter, $updateData);
    }

    public function removeEmptyVars($params)
    {
        foreach ($params as $key => $value) {
            if (empty($value)) {
                unset($params[$key]);
            }
        }

        return $params;
    }

    public function saveDataAndPurchaseShipment($params)
    {
        $additionalData = $params['additional_data'] ?? [];
        $preparedData = $this->prepareForDb($additionalData, $params['request_data']);
        //save in db
        $purchaseShipmentResp = $this->purchaseShipment($params);
        if (!$purchaseShipmentResp['success']) {
            $this->createFailedShipment($purchaseShipmentResp, $preparedData);
            return $purchaseShipmentResp;
        }

        // $purchaseShipmentResp['response_data'] = $purchaseShipmentResp['response_data']['payload'];
        $this->createSuccessShipment($purchaseShipmentResp, $preparedData);
        return $purchaseShipmentResp;
    }

    public function prepareForDb($additionalData, $requestData)
    {
        $fieldsToRemove = [
            'attributes',
            'billing_address',
            'discount',
            'discounts',
            'marketplace_status',
            'object_type',
            'payment_info',
            'shipping_charge',
            'shipping_details',
            'source_created_at',
            'created_at',
            'status',
            'sub_total',
            'tax',
            'taxes',
            'total',
            'total_weight',
            'targets',
            'taxes_included',
            'fulfilled_by',
            'is_ispu',
            'last_delivery_date',
            'filter_tags',
            'manually_imported',
            'status_updated_at'
        ]; //todo - does need to remove template_data as well

        $preparedData = array_filter($additionalData['order_data'], fn($key): bool => !in_array($key, $fieldsToRemove), ARRAY_FILTER_USE_KEY);

        $preparedData['object_type'] = 'shipping_service';
        $preparedData['shipping_service_type'] = "amazon_buy_shipping";
        $preparedData['marketplace_status'] = 'Pending';
        $preparedData['status'] = 'Pending';
        $preparedData['created_at'] = $preparedData['updated_at'] = date('c');
        $preparedData['ship_date'] = isset($additionalData['shipDate']) && !empty($additionalData['shipDate']) ? $additionalData['shipDate'] : date('Y-m-d\TH:i:s\Z');
        $preparedData['total'] = $additionalData['selected_rate']['totalCharge']['value'];
        $preparedData['marketplace_currency'] = $additionalData['selected_rate']['totalCharge']['unit'];
        $preparedData['total_weight'] = [
            'value' => $additionalData['selected_rate']['billedWeight']['value'] ?? null,
            'unit' => $additionalData['selected_rate']['billedWeight']['unit'] ?? null
        ];
        $preparedData['rate_id'] = $additionalData['selected_rate']['rateId'];
        $preparedData['carrier_id'] = $additionalData['selected_rate']['carrierId'];
        $preparedData['pick_by_window'] = [
            'start' => $additionalData['selected_rate']['promise']['pickupWindow']['start'],
            'end' => $additionalData['selected_rate']['promise']['pickupWindow']['end']
        ];
        $preparedData['delivery_by_window'] = [
            'start' => $additionalData['selected_rate']['promise']['deliveryWindow']['start'],
            'end' => $additionalData['selected_rate']['promise']['deliveryWindow']['end']
        ];
        $attributes = [
            ['key' => 'carrierId', 'value' => $additionalData['selected_rate']['carrierId']],
            ['key' => 'carrierName', 'value' => $additionalData['selected_rate']['carrierName']],
            ['key' => 'serviceId', 'value' => $additionalData['selected_rate']['serviceId']],
            ['key' => 'serviceName', 'value' => $additionalData['selected_rate']['serviceName']],
        ];
        foreach ($preparedData['items'] as $key => $item) {
            $preparedData['items'][$key]['is_hazmat'] = $item['isHamzat'] ?? false;
        }

        $preparedData['ship_from_address'] = $preparedData['default_ship_from_address'];
        unset($preparedData['default_ship_from_address']);
        $preparedData['attributes'] = $attributes;
        $preparedData['request_token'] = $requestData['requestToken'];
        $preparedData['label_format'] = $requestData['documentSpecification']['format'];
        $preparedData['page_layout'] = $requestData['documentSpecification']['pageLayout'] ?? "";
        $preparedData['dpi'] = $requestData['documentSpecification']['dpi'] ?? null;
        $preparedData['value_added_services'] = $requestData['valueAddedServices'] ?? [];
        //todo - maybe need to add package_dimensions
        unset($preparedData['_id']);
        return $preparedData;
    }

    public function createSuccessShipment($purchaseShipmentResp, $preparedData): void
    {
        $preparedData['marketplace_status'] = $preparedData['status'] = 'Shipped';
        $preparedData['package_client_reference_id'] = $purchaseShipmentResp['data']['packageDocumentDetails'][0]['packageClientReferenceId']; //update it in future, can have multiple packages
        $preparedData['tracking_id'] = $purchaseShipmentResp['data']['packageDocumentDetails'][0]['trackingId'];
        $preparedData['shipment_id'] = $purchaseShipmentResp['data']['shipmentId'];
        $this->createShipmentInDb($preparedData);
    }

    public function createFailedShipment($purchaseShipmentResp, $preparedData): void
    {
        $preparedData['marketplace_status'] = $preparedData['status'] = 'Failed';
        $this->createShipmentInDb($preparedData);
    }

    public function createShipmentInDb($preparedData): void
    {
        $orderService = $this->getOrderService();
        $sourceOrder = $orderService->getAll([
            'filter' => [
                'user_id' => [
                    "1" => $preparedData['user_id']
                ],
                'object_type' => [
                    "1" => 'source_order'
                ],
                'marketplace' => [
                    "1" => 'amazon'
                ],
                'marketplace_shop_id' => [
                    "1" => $preparedData['marketplace_shop_id']
                ],
                'marketplace_reference_id' => [
                    '1' => $preparedData['marketplace_reference_id']
                ]
            ]
        ]);
        if (!empty($sourceOrder['data']['rows'])) {
            $sourceOrder = $sourceOrder['data']['rows'][0];
            $preparedData['cif_order_id'] = $sourceOrder['cif_order_id'];
        }

        $preparedData['total'] = [
            'marketplace_price' => $preparedData['total'],
            'price' => $orderService->getConnectorPrice($preparedData['marketplace_currency'], $preparedData['total'])
        ];

        $orderContainer = $this->di->getObjectManager()
            ->create(BaseMongo::class)
            ->getCollectionForTable('order_container');

        $insertResponse = $orderContainer->insertOne($preparedData);
        if ($insertResponse->getInsertedCount()) {
            $preparedData['_id'] = $insertResponse->getInsertedId();
        }

        $this->updateSourceOrder($sourceOrder, $preparedData);
    }

    public function updateSourceOrder($sourceOrder, $shippingData): void
    {
        if (isset($shippingData['marketplace_status']) && $shippingData['marketplace_status'] == 'Failed') {
            return;
        }

        $filter = [
            '_id' => $sourceOrder['_id'],
        ];
        $shippingService[] = [
            'shipment_id' => $shippingData['shipment_id'],
            'tracking_id' => $shippingData['tracking_id'],
            'carrier_id' => $shippingData['carrier_id'],
            'package_client_reference_id' => $shippingData['package_client_reference_id'],
            '_id' => is_string($shippingData['_id'])
                ? new ObjectId($shippingData['_id']) : $shippingData['_id'],
            'status' => 'Shipped',
            'label_generated' => true,
            'label_format' => $shippingData['label_format'],
        ];
        $update = [
            '$set' => [
                'shipping_services' => $shippingService,
                'shipping_status' => 'Shipped'
            ]
        ];
        $orderContainer = $this->di->getObjectManager()
            ->create(BaseMongo::class)
            ->getCollectionForTable('order_container');

        $orderContainer->updateOne($filter, $update);
    }

    public function getOrderService()
    {
        return $this->di->getObjectManager()->get(OrderInterface::class);
    }

    public function getAllData($params)
    {
        $filter = [
            'filter' => [
                'user_id' => [
                    "1" => $params['user_id']
                ],
                'object_type' => [
                    "1" => 'source_order'
                ],
                'marketplace' => [
                    "1" => 'amazon'
                ],
                'marketplace_shop_id' => [
                    "1" => $params['marketplace_shop_id']
                ],
                'marketplace_reference_id' => [
                    '1' => $params['marketplace_reference_id']
                ]
            ]
        ];
        if (isset($params['query_type']) && $params['query_type'] == 'custom' && isset($params['filter'])) {
            $filter['filter'] = $params['filter'];
        }

        $sourceOrder = $this->getOrderService()->getAll($filter);
        if (empty($sourceOrder['data']['rows'])) {
            return ['success' => false, 'message' => 'Order not found!'];
        }

        $responseData = $sourceOrder['data']['rows'][0];

        $sourceShopId = $params['source_shop_id'] ?? $this->di->getRequester()->getSourceId() ?? false;
        $targetShopId = $params['target_shop_id'] ?? $this->di->getRequester()->getTargetId() ?? false;

        $responseData['items'] = array_map(function (array $item) use ($sourceShopId, $targetShopId) {
            $item['weight'] = $this->getProductWeightBySku($sourceShopId, $targetShopId, $item['sku']);
            return $item;
        }, $responseData['items']);

        $templateData = $this->getTemplateData([
            'user_id' => $responseData['user_id'],
            'marketplace' => $responseData['marketplace'],
        ]);
        if (!empty($templateData['data'])) {
            $packages = [];
            $shipFromAddress = [];
            foreach ($templateData['data'] as $data) {
                $packages += $data['packages'] ?? [];
                $shipFromAddress += $data['ship_from_address'] ?? [];
            }

            $responseData['template_data'] = [
                'packages' => $packages,
                'ship_from_address' => $shipFromAddress,
            ];
        }

        $this->addShippingServicesDataIfPresent($responseData);

        return ['success' => true, 'data' => $responseData];
    }

    public function getProductWeightBySku($sourceShopId, $targetShopId, $sku)
    {
        $productBySkuObj = $this->di->getObjectManager()
            ->create(Marketplace::class);

        $params = [
            "sku" => $sku,
            "target_shop_id" => $sourceShopId,
            "source_shop_id" => $targetShopId,
        ];

        $product = $productBySkuObj->getProductBySku($params);

        return [
            'value' => $product['weight'] ?? '',
            'unit' => $product['weight_unit'] ?? '',
        ];
    }

    public function getTemplateData($params)
    {
        $filter = [
            'filters' => [
                'user_id' => $params['user_id'] ?? $this->di->getUser()->id,
                'type' => 'shipping_services',
                'marketplace' => $params['marketplace'],
                'name' => 'shipping_details'
            ]
        ];
        return $this->di->getObjectManager()->get('App\Connector\Models\Profile\Template')->searchTemplates($filter);
    }

    public function addShippingServicesDataIfPresent(&$responseData)
    {
        $getShippingServicesResponse = $this->getShippingServicesData($responseData);
        if (!$getShippingServicesResponse['success']) {
            return $getShippingServicesResponse;
        }

        $shippingServices = $getShippingServicesResponse['data'];

        $shipFromAddresses = [];
        $packages = [];
        $shipDates = [];
        foreach ($responseData['items'] as $key => $item) {
            foreach ($shippingServices as $shippingService) {
                foreach ($shippingService['items'] as $shippingServiceItem) {
                    if ($item['sku'] == $shippingServiceItem['sku']) {
                        $responseData['items'][$key]['weight'] = $shippingServiceItem['weight'];
                        $responseData['items'][$key]['isHamzat'] = $shippingServiceItem['is_hamzat'];
                    }
                }

                array_push($shipFromAddresses, $shippingService['ship_from_address'] ?? []);
                array_push($packages, $shippingService['packages'] ?? []);
                array_push($shipDates, $shippingService['ship_date'] ?? []);

                $selectedService = [
                    'carrierName' => $this->getValuesFromAttributesBasedOnKey($shippingService['attributes'], 'carrierName')['value'] ?? '',
                    'carrierId' => $shippingService['carrier_id'] ?? '',
                    'promise' => [
                        'pickupWindow' => $shippingService['pick_by_window'],
                        'deliveryWindow' => $shippingService['delivery_by_window']
                    ],
                    'totalCharge' => [
                        'value' => $shippingService['total']['marketplace_price'],
                        'unit' => $shippingService['marketplace_currency']
                    ],
                    'rateId' => $shippingService['rate_id'],
                    'requestToken' => $shippingService['request_token'],
                    'label_format' => $shippingService['label_format'],
                    'pageLayout' => $shippingService['page_layout'],
                    'valueAddedServices' => $shippingService['value_added_services'] ?? [],
                ];
                if (!empty($selectedService['valueAddedServices'][0]['valueAddedServices'])) {
                    $selectedService['selectedValueAddedServices'] = $selectedService['valueAddedServices'];
                    $selectedService['valueAddedServices'][0]['valueAddedServices'] = [$selectedService['valueAddedServices'][0]['valueAddedServices']];
                }
                $selectedServices[] = $selectedService;
            }
        }

        $responseData['ship_from_address'] = $shipFromAddresses;
        $responseData['packages'] = $packages;
        $responseData['ship_date'] = $shipDates;
        $responseData['selected_services'] = $selectedServices;
    }

    public function getValuesFromAttributesBasedOnKey($attributes, $keyName)
    {
        return array_values(array_filter($attributes, fn($index): bool => $attributes[$index]['key'] == $keyName, ARRAY_FILTER_USE_KEY))[0];
    }

    public function getShippingServicesData($params)
    {
        $shippingServices = $params['shipping_services'] ?? [];
        $objectIds = array_column($shippingServices, '_id');

        $orderContainer = $this->di->getObjectManager()
            ->create(BaseMongo::class)
            ->getCollectionForTable('order_container');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $shippingServicesData = $orderContainer->find(['_id' => ['$in' => $objectIds]], $options)->toArray();


        if (empty($shippingServicesData)) {
            return ['success' => false, 'message' => 'No services found!'];
        }

        return ['success' => true, 'data' => $shippingServicesData];
    }

    public function updateSuccessResponse(&$remoteResponse): void
    {
        if (empty($remoteResponse)) {
            $remoteResponse = ['success' => false, 'message' => 'Something went wrong. Please try again later.'];
        }

        $remoteResponse['data'] = $remoteResponse['response_data']['payload'] ?? [];
        unset($remoteResponse['response_data']);
        unset($remoteResponse['shop_data']);
        unset($remoteResponse['trace']);
        if (isset($remoteResponse['response_message'])) {
            $remoteResponse['message'] = $remoteResponse['response_message'];
        }
    }

    public function getShipmentDocuments($shippingServices)
    {
        if (empty($shippingServices)) {
            return ['success' => false, 'message' => 'No shipping services found!'];
        }

        if (!isset($shippingServices[0])) {
            $shippingServices = [$shippingServices];
        }

        $getShipmentDocHelper = $this->di
            ->getObjectManager()
            ->get(GetShipmentDocumentHelper::class);

        $hasError = false;
        $returnData = [];
        $errorMsg = 'Something went wrong!';

        foreach ($shippingServices as $shippingService) {
            $getShipmentIdResp = $getShipmentDocHelper->getShipmentId($shippingService);
            if (!$getShipmentIdResp['success']) {
                $hasError = true;
                $errorMsg = $getShipmentIdResp['message'];
                continue;
            }

            $shipmentId = $getShipmentIdResp['data'];
            $packageClientReferenceIdResp = $getShipmentDocHelper->getPackageClientReferenceId($shippingService);
            if (!$packageClientReferenceIdResp['success']) {
                $hasError = true;
                $errorMsg = $packageClientReferenceIdResp['message'];
                continue;
            }

            $packageClientReferenceId = $packageClientReferenceIdResp['data'];
            $shopIdResponse = $this->getShopIdFromOrder($shippingService);
            if (!$shopIdResponse['success']) {
                $hasError = true;
                $errorMsg = $shopIdResponse['message'];
                continue;
            }

            $shopId = $shopIdResponse['data'];
            $format = $shippingService['label_format'] ?? '';
            $dpi = null;
            $remoteResponse = $this->getShipmentDocumentsFromAmazon(compact(
                'shipmentId',
                'packageClientReferenceId'
            ), $shopId);
            if (!$remoteResponse['success']) {
                $hasError = true;
                $errorMsg = $remoteResponse['error']['errors'][0]['details'];
                continue;
            }

            $returnData[] = $remoteResponse['response_data']['payload'];
        }

        if (empty($returnData) && $hasError) {
            return ['success' => false, 'message' => $errorMsg];
        }
        if (!empty($returnData) && $hasError) {
            return [
                'success' => true,
                'message' => 'One of more shipment has errors - ' . $errorMsg,
                'data' => $returnData
            ];
        }
        if (!empty($returnData) && !$hasError) {
            return ['success' => true, 'data' => $returnData];
        }

        return ['success' => true, 'data' => $returnData];
    }

    public function getShipmentDocumentsFromAmazon($params, $shopId)
    {
        $requestData['postParams'] = json_encode($params);
        $requestData['shop_id'] = $shopId;
        $requestData['sandbox'] = $this->useSandbox();
        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        return $commonHelper->sendRequestToAmazon('buy-shipping/get-shipment-documents', $requestData, 'GET');
    }
}
