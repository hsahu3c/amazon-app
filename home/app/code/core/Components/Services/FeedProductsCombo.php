<?php

namespace App\Core\Components\Services;

class FeedProductsCombo extends \App\Core\Components\Services
{
    public function getBillingPrice($plan, $input = [])
    {
        return $plan['price'];
    }

    public function getMaximumQuantity($plan, $input = [])
    {
        return $plan['max_qty'];
    }

    public function getShortDescription($plan)
    {
        return 'Upload products on merchant center with ease';
    }

    public function getDescription($plan)
    {
        $maxProducts = $plan['max_qty'];
        $price = $plan['price'];
        $priceText = 'for free';
        if ($price > 0) {
            $priceText = 'at only ' . $price . '$ yearly';
        }
        return 'You can generate feeds of upto ' .
            $maxProducts . ' products ' .
            $priceText . ' and upload them to your merchant center.';
    }

    public function getCreatePlanHtml()
    {
        return [
            [
                "title" => "Maximum Quantity",
                "code" => "max_qty",
                "value" => 0,
                "type" => "number"
            ],
            [
                "title" => "Price",
                "code" => "price",
                "value" => 0,
                "type" => "number"
            ]
        ];
    }

    public function editPlanHtml($service)
    {
        return [
            [
                "title" => "Maximum Quantity",
                "code" => "max_qty",
                "value" => $service['max_qty'],
                "type" => "number"
            ],
            [
                "title" => "Price",
                "code" => "price",
                "value" => $service['price'],
                "type" => "number"
            ]
        ];
    }

    public function getInstantPrice($plan)
    {
        if (isset($plan['unit_price'])) {
            return $plan['unit_price'];
        }
        return self::UNIT_PRICE;
    }

    public function preparePlanPaymentDetaills()
    {
        return '';
    }

    public function getPlanType()
    {
        return 'combo';
    }

    public function getPlanDuration()
    {
        return 366;
    }
}
