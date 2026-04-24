<?php

namespace App\Amazon\Components\SupplySource\Helpers;

use App\Core\Components\Base;

class CreateHelper extends Base
{
    public function validateCreateInput($data): array
    {
        #todo - needs to be unique
        if (empty($data['supply_source_code'])) {
            return ['success' => false, 'message' => 'Supply Source Code is required'];
        }

        #todo - needs to be unique
        if (empty($data['alias'])) {
            return ['success' => false, 'message' => 'Alias is required'];
        }

        if (empty($data['address'])) {
            return ['success' => false, 'message' => 'Address is required'];
        }

        // Validate required address fields
        $address = $data['address'];
        $requiredAddressFields = ['name', 'address_line_1', 'state_or_region', 'country_code', 'postal_code'];//incorrect in documentation - postal_code

        foreach ($requiredAddressFields as $field) {
            if (empty($address[$field])) {
                return ['success' => false, 'message' => "{$field} is required in Address"];
            }
        }

        // supply source code validation (remove spaces, special chars)
        if (preg_match('/[\'^£$%&*()}{@#~?><>,|=+]/', $data['supply_source_code'])) {
            return ['success' => false, 'message' => 'Please remove these \'^£$%&*()}{@#~?><>,|=+ special characters from Supply Store Code'];
        }

        // address name validation (no HTML tags)
        if (preg_match('/<[^>]*>/', $address['name'])) {
            return ['success' => false, 'message' => 'Please remove these <> special characters from store name'];
        }

        return ['success' => true];
    }

    public function prepareSupplySourceData($data): array
    {
        if (!isset($data['supply_source_code'], $data['alias'], $data['address'])) {
            return ['success' => false, 'message' => 'Required params missing'];
        }

        $prepared = [
            'supplySourceCode' => trim(str_replace(' ', '', $data['supply_source_code'])),
            'alias' => trim($data['alias']),
            'address' => $this->prepareAddress($data['address'])
        ];

        return array_filter($prepared, function($value) {
            return $value !== '' && $value !== null && $value !== [];
        });
    }

    private function prepareAddress($address): array
    {
        $preparedAddress =  [
            'name' => trim(strip_tags($address['name'] ?? '')),
            'addressLine1' => trim($address['address_line_1'] ?? ''),
            'addressLine2' => trim($address['address_line_2'] ?? ''),
            'addressLine3' => trim($address['address_line_3'] ?? ''),
            'city' => trim($address['city'] ?? ''),
            'district' => trim($address['district'] ?? ''),
            'stateOrRegion' => trim($address['state_or_region'] ?? ''),
            'postalCode' => trim($address['postal_code'] ?? ''),
            'countryCode' => strtoupper(trim($address['country_code'] ?? '')),
            'phone' => trim($address['phone'] ?? ''),
        ];

        return array_filter($preparedAddress, fn($value) => $value !== '');
    }
}
