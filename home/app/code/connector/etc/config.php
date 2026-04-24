<?php
return [
    'di' => [
        '\MyInterface' => '\App\Core\Components\Router',
        'suggestionHelper' => '\App\Connector\Components\Suggestor',
        'importHelper' => '\App\Connector\Components\ImportHelper',
        'App\Connector\Contracts\Sales\OrderInterface' => [
            'default' => 'App\Connector\Service\Order',
            'amazon' => 'App\Amazon\Service\Order',
            'shopify' => 'App\Shopifyhome\Service\Order',
            'order_item' => 'App\Connector\Service\OrderItem',
        ],
        'App\Connector\Contracts\Currency\CurrencyInterface' => [
            'default' => 'App\Connector\Service\Currency',
        ],
        'App\Connector\Contracts\Sales\Order\ShipInterface' => [
            'default' => 'App\Connector\Service\Shipment',
            'amazon' => 'App\Amazon\Service\Shipment',
            'shopify' => 'App\Shopifyhome\Service\Shipment',
        ],
        'App\Connector\Contracts\Sales\Order\CancelInterface' => [
            'default' => 'App\Connector\Service\OrderCancel',
            'manual' => 'App\Connector\Service\ManualCancel',
            'amazon' => 'App\Amazon\Service\OrderCancel',
            'shopify' => 'App\Shopifyhome\Service\OrderCancel',
        ],
        'App\Connector\Contracts\Sales\Order\RefundInterface' => [
            'default' => 'App\Connector\Service\OrderRefund',
            'amazon' => 'App\Amazon\Service\OrderRefund',
            'shopify' => 'App\Shopifyhome\Service\OrderRefund',
            'manual' => 'App\Connector\Service\ManualRefund',
        ],
        'App\Connector\Contracts\Sales\Order\ReturnInterface' => [
            'default' => 'App\Connector\Service\OrderReturn',
        ],
    ],
    'routers' => [
        'App\Core\Components\Router',
    ],
    'events' => [
        'application:productSaveBefore' => [
            'product_save_before' => '\App\Connector\Components\ProductEvent',
        ],
        'application:productSaveAfter' => [
            'product_save_after' => '\App\Connector\Components\ProductEvent',
        ],
        'application:createActivityLog' => [
            'createActivityLog' => '\App\Connector\Components\ActivityLogEvent',
        ],
        'application:appReInstall' => [
            'appReInstall' => '\App\Connector\Components\AppReInstallEvent',
        ],
        'application:beforeLastTargetDisconnect' => [
            'beforeLastTargetDisconnect' => '\App\Connector\Components\TargetDisconnectEvent',
        ],
        'application:afterSingleWebhookSubscribe' => [
            'afterSingleWebhookSubscribe' => '\App\Connector\Components\Webhook\Events',
        ],
        'application:afterSingleWebhookUnsubscribe' => [
            'afterSingleWebhookUnsubscribe' => '\App\Connector\Components\Webhook\Events',
        ],
    ],
    'connectors' => [
        'global' => [
            'type' => 'proxy',
            'code' => 'global',
            'is_source' => 1,
            'image' => 'marketplace-logos/shopify.png',
            'title' => 'Global',
            'description' => 'Shopify integration',
            'source_model' => '\App\Core\Models\SourceModel',
            'syncing_config' => [
                'limit' => 100,
                'create_queued_tasks' => true,
                'create_notification' => true,
                'projectData' => ['collection' => 0],
            ],
        ],
    ],
    'warehouse_handle' => [
        'default' => [
            'source' => '\App\Connector\Components\DefaultHandler',
            'code' => 'global',
        ],
    ],
    'payment_methods' => [
        'shopify' => [
            'shopify_payment' => [
                'title' => 'Shopify Payment',
                'source_model' => '\App\Shopify\Components\PaymentHelper',
                'type' => 'redirect',
                'code' => 'shopify_payment',
                'description' => 'Official payment method of shopify',
            ],
        ],
    ],
    'allowed_filters' => [
        "shop_id",
        "source_product_id",
        "status",
        "title",
        "variant_title",
        "sku",
        "shopify_sku",
        "quantity",
        "price",
        "barcode",
        "main_image",
        "errors",
        "is_visible",
        "inventory_tracked",
        "inventory_policy",
        "inventory_management",
        'locale',
        'ai_updated_product',
        'is_bundle'
    ],

    'refine_additional_keys' => [
        'main_image',
        'type',
        'brand',
        'product_type',
        'app_codes',
        "tags",
        "variant_attributes",
        // "collection",
        "profile",
        "categories",
        "is_visible",
        "inventory_tracked",
        "inventory_policy",
        "inventory_management",
        "is_bundle"
    ],
    // 'required_updating_products' => [
    //     'required' => false,
    //     'projection' => [
    //         'user_id',
    //         'container_id',
    //         'source_product_id',
    //         'marketplace',
    //         'sku',
    //         'source_sku',
    //         'visibility',
    //         'type',
    //         'variant_attributes',
    //         'barcode',
    //         'shop_id',
    //         'app_codes',
    //     ],
    // ],
    'abort_refine_syncing' => [],
    "maintenance" => [
        "allowed_endpoints" => [
            "/connector/request/*",
        ],
    ],
    'supported_locales' => ['es', 'fr', 'ga', 'de', 'it'],
];