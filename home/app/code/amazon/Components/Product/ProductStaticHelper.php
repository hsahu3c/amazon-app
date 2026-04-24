<?php

namespace App\Amazon\Components\Product\ProductStaticHelper;


class ProductStaticHelper
{
    /*Prodcut status constant */
    public const PRODUCT_STATUS_AVAILABLE_FOR_OFFER = 1;

    public const PRODUCT_STATUS_NOT_UPLOADED = 2;

    public const PRODUCT_STATUS_UPLOADED = 3;


    public function getStatusCode($label)
    {
        return match ($label) {
            'PUBLISHED' => static::PRODUCT_STATUS_UPLOADED,
            'NOT UPLOADED' => static::PRODUCT_STATUS_NOT_UPLOADED,
            'AVAILABLE FOR OFFER' => static::PRODUCT_STATUS_AVAILABLE_FOR_OFFER,
            default => static::PRODUCT_STATUS_NOT_UPLOADED,
        };
    }


    public function getAllProductStatus()
    {
        return $statusArray = [
            self::PRODUCT_STATUS_AVAILABLE_FOR_OFFER => $this->getStatusLabel(self::PRODUCT_STATUS_AVAILABLE_FOR_OFFER),
            self::PRODUCT_STATUS_UPLOADED => $this->getStatusLabel(self::PRODUCT_STATUS_UPLOADED),
            self::PRODUCT_STATUS_NOT_UPLOADED => $this->getStatusLabel(self::PRODUCT_STATUS_NOT_UPLOADED),
        ];
    }

    public function getStatusLabel($code): string
    {
        return match ($code) {
            static::PRODUCT_STATUS_AVAILABLE_FOR_OFFER => 'AVAILABLE FOR OFFER',
            static::PRODUCT_STATUS_UPLOADED => 'PUBLISHED',
            static::PRODUCT_STATUS_NOT_UPLOADED => 'NOT UPLOADED',
            default => 'NOT UPLOADED',
        };
    }
}
