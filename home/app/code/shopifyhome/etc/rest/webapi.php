<?php
use App\Shopifyhome\Api\Product;
$marketplace = 'shopify';
return [
    'restapi' => [
        'v1' => [
            'GET' => [
                'routes' => [
                    "{$marketplace}/product/getAttributesOptions" => [
                        'url'       => "{$marketplace}/product/getAttributesOptions",
                        'method'    => 'getAttributesOptions',
                        'resource'  => 'product/getAttributesOptions',
                        'component' => 'Product',
                        'class'     => Product::class
                    ],
                    "{$marketplace}/product/getTitleRule" => [
                        'url'       => "{$marketplace}/product/getTitleRule",
                        'method'    => 'getTitleRule',
                        'resource'  => 'product/getTitleRule',
                        'component' => 'Product',
                        'class'     => Product::class
                    ],
                ]
            ]
        ],
    ]
];
        
