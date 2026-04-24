<?php

namespace App\Amazon\Components\AmazonBuyShipping\Helpers;

use App\Core\Components\Base;

class GetRatesHelper extends Base
{
    public const SHIP_TO_REQUIRED_FIELDS = [
        'name',
        'city',
        'addressLine1',
        'postalCode',
        'countryCode',
    ];

    public const SHIP_FROM_REQUIRED_FIELDS = [
        'name',
        'city',
        'addressLine1',
        'postalCode',
        'countryCode',
    ];

    public const RETURN_TO_REQUIRED_FIELDS = [
        'name',
        'city',
        'addressLine1',
        'postalCode',
        'countryCode',
    ];

    public const DIMENSIONS_REQUIRED_FIELDS = [
        'length',
        'width',
        'height',
        'unit',
    ];

    public const ALLOWED_DIMENSION_UNITS = [
        'INCH',
        'CENTIMETER'
    ];

    public const WEIGHT_REQUIRED_FIELDS = [
        'unit',
        'value',
    ];

    public const ALLOWED_WEIGHT_UNITS = [
        'OUNCE',
        'POUND',
        'GRAM',
        'KILOGRAM'
    ];

    public const ITEMS_REQUIRED_FIELDS = [
        'quantity',
        'weight',
        'itemValue', //It is required for ON Amazon label, optional for OFF Amazon label.

    ];

    public const ITEMS_BOOL_PROPERTIES = [
        'isHazmat'
    ];

    public const CHANNEL_DETAILS_REQUIRED_FIELDS = [
        'channelType',
    ];

    public const AMAZON_ORDER_DETAILS_REQUIRED_FIELDS = [
        'orderId',
    ];

    public const CHANNEL_TYPE_AMAZON = 'AMAZON';

    public const CHANNEL_TYPE_EXTERNAL = 'EXTERNAL';

    public function getShipTo($requestData)
    {
        $this->updateAddressLinesIfRequired($requestData);

        $validateShipToResponse = $this->validateShipToFields($requestData);
        if (!$validateShipToResponse['success']) {
            return $validateShipToResponse;
        }

        $validatedData = $validateShipToResponse['data'];

        return ['success' => true, 'data' => $validatedData];
    }

    public function getShipToUsingOrderData($orderData)
    {
        //todo - need to handle empty values as well instead of only fall back
        $shippingData = $orderData['shipping_address'][0] ?? []; //todo - update zero index(need to access dynamic)

        $name = $shippingData['name'] ?? $orderData['customer']['name'] ?? false;
        $postalCode = $shippingData['zip'] ?? false;
        $city = $shippingData['city'] ?? false;
        $countryCode = $shippingData['country_code'] ?? false;
        $phoneNumber = $shippingData['phone'] ?? false;
        if ($phoneNumber) {
            $phoneNumber = explode('ext.', $phoneNumber)[0] ?? false;
        }

        $email = $orderData['customer']['email'] ?? false;
        $stateOrRegion = $shippingData['state'] ?? false;

        $addressLine1 = $shippingData['address_line_1'] ?? false;
        $addressLine2 = $shippingData['address_line_2'] ?? false;
        $addressLine3 = $shippingData['address_line_3'] ?? false;

        $shipToData = compact(
            'name',
            'addressLine1',
            'addressLine2',
            'addressLine3',
            'postalCode',
            'city',
            'countryCode',
            'phoneNumber',
            'email',
            'stateOrRegion'
        );
        return $this->getShipTo($shipToData);
    }

    public function validateShipToFields($params)
    {
        foreach ($params as $field => $value) {
            if (empty($value)) {
                unset($params[$field]);
            }
        }

        foreach (self::SHIP_TO_REQUIRED_FIELDS as $field) {
            if (!isset($params[$field]) || !$params[$field]) {
                return ['success' => false, 'message' => "Required field {$field} missing in shipTo"];
            }
        }

        return ['success' => true, 'data' => $params];
    }

    public function getShipFrom($requestData)
    {
        foreach ($requestData as $field => $value) {
            if (empty($value)) {
                unset($requestData[$field]);
            }
        }

        $this->updateAddressLinesIfRequired($requestData);

        $validateShipFromResponse = $this->validateShipFromFields($requestData);
        if (!$validateShipFromResponse['success']) {
            return $validateShipFromResponse;
        }

        return ['success' => true, 'data' => $requestData];
    }

    public function getShipFromUsingOrderData($orderData)
    {
        $defaultShipFromAddress = $orderData['default_ship_from_address'] ?? [];
        $name = $defaultShipFromAddress['name'] ?? false;
        $addressLine1 = $defaultShipFromAddress['address_line_1'] ?? false;
        $addressLine2 = $defaultShipFromAddress['address_line_2'] ?? false;
        $addressLine3 = $defaultShipFromAddress['address_line_3'] ?? false;
        $companyName = $defaultShipFromAddress['company_name'] ?? false;
        $stateOrRegion = $defaultShipFromAddress['state'] ?? false;
        $city = $defaultShipFromAddress['city'] ?? false;
        $postalCode = $defaultShipFromAddress['zip'] ?? false;
        $countryCode = $defaultShipFromAddress['country_code'] ?? $defaultShipFromAddress['country'] ?? false;
        $phoneNumber = $defaultShipFromAddress['phone'] ?? false;
        $email = $defaultShipFromAddress['email'] ?? false;
        return $this->getShipFrom(compact(
            'name',
            'addressLine1',
            'addressLine2',
            'addressLine3',
            'companyName',
            'stateOrRegion',
            'city',
            'postalCode',
            'countryCode',
            'phoneNumber',
            'email'
        ));
    }

    public function validateShipFromFields($params)
    {
        foreach (self::SHIP_FROM_REQUIRED_FIELDS as $field) {
            if (!isset($params[$field]) || !$params[$field]) {
                return ['success' => false, 'message' => "Required field {$field} missing in shipFrom"];
            }
        }

        return ['success' => true];
    }

    public function updateAddressLinesIfRequired(&$requestData): void
    {
        extract($this->getAddressLines($requestData));

        $addressLine1 && $requestData['addressLine1'] = $addressLine1;
        $addressLine2 && $requestData['addressLine2'] = $addressLine2;
        $addressLine3 && $requestData['addressLine3'] = $addressLine3;
        //check if var name is same when returned from extract then will reference auto update values
    }

    public function getAddressLines($requestData)
    {
        $addressLine1 = $requestData['addressLine1'] ?? $requestData['AddressLine1'] ?? false;
        $addressLine2 = $requestData['addressLine2'] ?? $requestData['AddressLine1'] ?? false;
        $addressLine3 = $requestData['addressLine3'] ?? $requestData['AddressLine1'] ?? false;

        if (!$addressLine1 && $addressLine2) {
            $addressLine1 = $addressLine2;
            $addressLine2 = $addressLine3;
        } elseif (!$addressLine1 && !$addressLine2) {
            $addressLine1 = $addressLine3;
        }

        return compact('addressLine1', 'addressLine2', 'addressLine3');
    }

    public function getReturnTo($requestData)
    {
        $validateReturnToResponse = $this->validateReturnToFields($requestData);
        if (!$validateReturnToResponse['success']) {
            return $validateReturnToResponse;
        }

        $validatedData = $validateReturnToResponse['data'];
        return ['success' => true, 'data' => $validatedData];
    }

    public function getShipDate($requestData)
    {
        $validateShipDateResponse = $this->validateShipDateFields($requestData);
        if (!$validateShipDateResponse['success']) {
            return $validateShipDateResponse;
        }

        $shipDate = $validateShipDateResponse['data'];
        return ['success' => true, 'data' => $shipDate];
    }

    public function validateShipDateFields($params)
    {
        $shipDate = $params['shipDate'] ?? date('Y-m-d\TH:i:s\Z');

        //todo - need to validate format of shipDate to ensure format is correct as per amazon
        return ['success' => true, 'data' => $shipDate];
    }

    public function validateReturnToFields($params)
    {
        foreach (self::RETURN_TO_REQUIRED_FIELDS as $field) {
            if (!isset($params[$field]) || !$params[$field]) {
                return ['success' => false, 'message' => "Required field {$field} missing in returnTo"];
            }
        }

        return ['success' => true, 'data' => $params];
    }

    public function getDimensions($requestData)
    {
        $dimensions = [];
        $dimensions['length'] = (float)($requestData['length'] ?? false);
        $dimensions['width'] = (float)($requestData['width'] ?? false);
        $dimensions['height'] = (float)($requestData['height'] ?? false);
        $dimensions['unit'] = strtoupper($requestData['unit'] ?? false);
        $validateDimensionsResponse = $this->validateDimensionFields($dimensions);
        if (!$validateDimensionsResponse['success']) {
            return $validateDimensionsResponse;
        }

        //can implement dimension profile here
        return ['success' => true, 'data' => $dimensions];
    }

    public function validateDimensionFields($params)
    {
        foreach (self::DIMENSIONS_REQUIRED_FIELDS as $field) {
            if (!isset($params[$field]) || !$params[$field]) {
                return ['success' => false, 'message' => "Required field {$field} missing in dimensions"];
            }

            if (
                'unit' == $field
                && !in_array($params[$field], self::ALLOWED_DIMENSION_UNITS)
            ) {
                return ['success' => false, 'message' => "Invalid unit {$params[$field]}"];
            }
        }

        return ['success' => true];
    }

    public function getWeight($requestData)
    {
        $weight = [];
        $weight['value'] = (float)($requestData['value'] ?? false);
        $weight['unit'] = strtoupper((string) $requestData['unit']) ?? false;
        $validateWeightResponse = $this->validateWeightFields($weight);
        if (!$validateWeightResponse['success']) {
            return $validateWeightResponse;
        }

        return ['success' => true, 'data' => $weight];
    }

    public function validateWeightFields($params)
    {
        foreach (self::WEIGHT_REQUIRED_FIELDS as $field) {
            if (!isset($params[$field]) || !$params[$field]) {
                return ['success' => false, 'message' => "Required field {$field} missing in weight"];
            }

            if (
                'unit' == $field
                && !in_array($params[$field], self::ALLOWED_WEIGHT_UNITS)
            ) {
                return ['success' => false, 'message' => "Invalid unit {$params[$field]}"];
            }
        }

        return ['success' => true];
    }

    public function getPackages($requestData)
    {
        $preparedPackages = [];
        $packages = $requestData['packages'] ?? [];
        foreach ($packages as $package) {
            $preparedPackage = [];
            $getDimensionsResp = $this->getDimensions($package['dimensions'] ?? []);
            if (!$getDimensionsResp['success']) {
                return $getDimensionsResp;
            }

            $preparedPackage['dimensions'] = $getDimensionsResp['data'];
            $getWeightResp = $this->getWeight($package['weight'] ?? []);
            if (!$getWeightResp['success']) {
                return $getWeightResp;
            }

            $preparedPackage['weight'] = $getWeightResp['data'];
            //todo - need to update this as the current implementation will pass on all items in all packages
            $getItemsResp = $this->getItems($requestData['items'], $requestData['marketplace_currency']);
            if (!$getItemsResp['success']) {
                return $getItemsResp;
            }

            $preparedPackage['items'] = $getItemsResp['data'];
            //validate the insuredValue thing
            $insuredValueResp = $this->getInsuredValue($preparedPackage['items']);
            if (!$insuredValueResp['success']) {
                return $insuredValueResp;
            }

            $preparedPackage['insuredValue'] = $insuredValueResp['data'];
            $packageClientReferenceIdRes = $this->getPackageClientReferenceIdFromOrderData($requestData);
            if (!$packageClientReferenceIdRes['success']) {
                return $packageClientReferenceIdRes;
            }

            $preparedPackage['packageClientReferenceId'] = $packageClientReferenceIdRes['data'];

            $hasHazmat = array_reduce(
                $preparedPackage['items'] ?? [],
                fn($carry, $item) => $carry || !empty($item['isHazmat']),
                false
            );
            if ($hasHazmat) {
                $preparedPackage['isHazmat'] = $hasHazmat;
            }

            $preparedPackages[] = $preparedPackage;
        }

        return ['success' => true, 'data' => $preparedPackages];
    }

    public function getItems($items, $currency)
    {
        $preparedItems = [];

        foreach ($items as $item) {
            $newItem = [];
            $newItem = [
                'quantity' => (int)($item['quantity'] ?? $item['qty'] ?? false),
                'itemIdentifier' => $item['itemIdentifier'] ?? $item['marketplace_item_id'] ?? false, //Order Item ID for amazon orders
                'description' => $item['description'] ?? false,
                'isHazmat' => $item['isHamzat'] ?? false,
                'productType' => $item['productType'] ?? false,
                'serialNumbers' => $item['serialNumbers'] ?? false
            ];
            $response = $this->getWeight($item['weight']);
            if (!$response['success']) {
                return $response;
            }

            $newItem['weight'] = $response['data'];
            $newItem['itemValue'] = ['value' => (float)($item['marketplace_price'] ?? false), 'unit' => $currency] ?? [];
            $validateItemResponse = $this->validateItemFields($newItem);
            if (!$validateItemResponse['success']) {
                return $validateItemResponse;
            }

            $preparedItems[] = $validateItemResponse['data'];
        }

        return ['success' => true, 'data' => $preparedItems];
    }

    public function validateItemFields($item)
    {
        foreach (self::ITEMS_REQUIRED_FIELDS as $field) {
            if (!isset($item[$field])) {
                return ['success' => false, 'message' => "Required field {$field} missing in items"];
            }
        }

        foreach ($item as $key => $value) {
            if (!$item[$key] && !in_array($key, self::ITEMS_REQUIRED_FIELDS) && !in_array($key, self::ITEMS_BOOL_PROPERTIES)) {
                unset($item[$key]);
            }
        }

        return ['success' => true, 'data' => $item];
    }

    public function getInsuredValue($items)
    {
        $insuredValue = 0;
        foreach ($items as $item) {
            $itemInsuredValue = $item['marketplace_price'] ?? $item['itemValue']['value'] ?? false;
            if (!$itemInsuredValue) {
                return ['success' => false, 'message' => 'Insured Value is required'];
            }

            $currency = $item['itemValue']['unit'] ?? false;
            $insuredValue += $itemInsuredValue;
        }

        return ['success' => true, 'data' => ['value' => (float)$insuredValue, 'unit' => $currency]];
    }

    public function getPackageClientReferenceId($params)
    {
        $packageClientReferenceId = $params['packageClientReferenceId'] ?? false;
        if (!$packageClientReferenceId) {
            return ['success' => false, 'message' => 'packageClientReferenceId is required'];
        }

        return ['success' => true, 'data' => $packageClientReferenceId];
    }

    public function getPackageClientReferenceIdFromOrderData($orderData)
    {
        $params['packageClientReferenceId'] = $orderData['_id']['$oid'] ?? (string)$orderData['_id'];//todo - will be required to update this in the future when providing multiple shipment for same order
        return $this->getPackageClientReferenceId($params);
    }

    public function getChannelDetails($params)
    {
        $channelDetails = $params['channelDetails'] ?? false;
        if (!$channelDetails) {
            return ['success' => false, 'message' => 'channelDetails is required'];
        }

        $validateChannelResponse = $this->validateChannelDetailsFields($channelDetails);
        if (!$validateChannelResponse['success']) {
            return $validateChannelResponse;
        }

        return ['success' => true, 'data' => $channelDetails];
    }

    public function getChannelDetailsFromOrderData($orderData)
    {
        $marketplace = $orderData['marketplace'] ?? false;

        $channelDetails = [];

        $channelDetails['channelType'] = (self::CHANNEL_TYPE_AMAZON == strtoupper((string) $marketplace))
            ? self::CHANNEL_TYPE_AMAZON
            : self::CHANNEL_TYPE_EXTERNAL;
        if ($channelDetails['channelType'] == self::CHANNEL_TYPE_AMAZON) {
            $channelDetails['amazonOrderDetails']['orderId'] = $orderData['marketplace_reference_id'];
        }

        return $this->getChannelDetails(compact('channelDetails'));
    }

    public function validateChannelDetailsFields($params)
    {
        foreach (self::CHANNEL_DETAILS_REQUIRED_FIELDS as $field) {
            if (!isset($params[$field]) || !$params[$field]) {
                return ['success' => false, 'message' => "Required field {$field} missing in channelDetails"];
            }
        }

        if (self::CHANNEL_TYPE_AMAZON == $params['channelType']) {
            foreach (self::AMAZON_ORDER_DETAILS_REQUIRED_FIELDS as $field) {
                if (!isset($params['amazonOrderDetails'][$field])) {
                    return ['success' => false, 'message' => "Required field {$field} missing in amazonOrderDetails"];
                }
            }
        }

        return ['success' => true];
    }
}
