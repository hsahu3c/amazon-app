<?php

use App\Amazon\Components\ProductEvent;
use App\Amazon\Components\ConfigurationEvent;
use App\Amazon\Components\ShopEvent;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\HandleEvent;
use App\Amazon\Components\BulletinEvent;
use App\Amazon\Service\Order;
use App\Amazon\Models\SourceModel;
use App\Amazon\Components\Message\Handler\Sqs;
return [
    'events' => [
         'application:afterProductCreate'=>[
            'amazon_after_parent_product_create'=> ProductEvent::class
        ],
        'application:productSaveAfter' => [
            'amazon_product_save_after' => ProductEvent::class,
        ],
        'application:afterreverceAuth' => [
            'afterreverceAuth' => '\App\Connector\Components\ReverceAuthEvent',
        ],
        'application:afterProductImport' => [
            'after_product_import' => ProductEvent::class,
            'after_connector_product_import' => '\App\Connector\Components\Migration\Migration',
        ],
        'application:beforeDisconnect' => [
            'before_disconnect' => ProductEvent::class
        ],
        'application:afterMetafieldImport' => [
            'afterMetafieldImport' => ProductEvent::class
        ],
        'application:afterAccountConnection' => [
            'after_account_connection' => ConfigurationEvent::class
        ],
        "application:shopUpdate" => [
            'amazon_shop_update' => ProductEvent::class
        ],
        "application:beforeAccountDisconnect" => [
            'amazon_shop_disconnect' => ShopEvent::class
        ],
        "application:appReInstall" => [
            'amazon_appReInstall' => ShopEvent::class
        ],
        "application:afterVariantsDelete" => [
            'amazon_variant_delete' => ProductEvent::class
        ],
        'application:afterOrderFetch' => [
            'plan_counter_update' => 'App\Plan\Components\HandleEvent'
        ],
        'application:sourceTargetReconnect' => [
            'target_reconnect' => ConfigurationEvent::class
        ],
        'application:saveSellerName' => [
            'after_save_seller_name' => ConfigurationEvent::class
        ],
        'application:handleNextPrevProductState' => [
            'handle_nxt_prev_rest_all' => Helper::class
        ],
        "application:afterProductUpdate" => [
            'amazon_after_product_update' => ProductEvent::class,
            'bundle_product_inventory_sync' => '\App\Amazon\Components\Product\Inventory\Hook'
        ],
        'application:beforeCommenceHomeAuthAction' => [
            'amazon_token_update' => HandleEvent::class
        ],
        'application:afterProductDelete' => [
            'amazon_after_product_delete' => ProductEvent::class,
        ],
        'application:shopStatusUpdate' => [
            'after_shopStatusUpdate' => ShopEvent::class
        ],
        'application:orderFail' => [
            'bulletin_orderFail' => BulletinEvent::class
        ],
        'application:orderCancelFail' => [
            'bulletin_orderCancelFail' => BulletinEvent::class
        ],
        'application:orderRefundFail' => [
            'bulletin_orderRefundFail' => BulletinEvent::class
        ],
        'application:orderShipmentFail' => [
            'bulletin_orderShipmentFail' => BulletinEvent::class
        ],
        'application:warehouseDisabled' => [
            'bulletin_warehouseDisabled' => BulletinEvent::class
        ],
        'application:inventoryOutOfStock' => [
            'bulletin_inventoryOutOfStock' => BulletinEvent::class
        ],
        'application:afterTargetOrderCreated' => [
            'update_status' => Order::class
        ],
        'application:loginAfter' => [
            'amazon_login_after' => HandleEvent::class,
            'last_login' => '\App\Amazon\Components\LoginHelper'
        ],
        'application:afterProductImportChunkProcessed' => [
            'amazon_backup_sync' => ProductEvent::class
        ]
    ],
    'connectors' => [
        'amazon' => [
            'code' => 'amazon',
            'type' => 'real',
            'is_source' => 0,
            'is_target' => 1,
            'image' => 'marketplace-logos/amazon.png',
            'title' => 'Amazon',
            'description' => 'Amazon Integration',
            'source_model' => SourceModel::class,
            'sendResetPassword' => false,
            'get_assigned_profiles' => true,
            "active_product_statuses" => ["Active", "Inactive", "Incomplete"]
        ],
        'amazon_india' => [
            'code' => 'amazon_india',
            'type' => 'real',
            'is_source' => 0,
            'can_import' => 1,
            'image' => 'marketplace-logos/amazon_india.jpg',
            'title' => 'Amazon India',
            'description' => 'Amazon Integration',
            'source_model' => SourceModel::class
        ]
    ],
    'services' => [
        'amazon_india' => [
            'handler' => 'App\Connector\Models\User\Service',
            'code' => 'amazon_india',
            'title' => 'Amazon',
            'type' => 'uploader',
            'charge_type' => 'prepaid',
            'marketplace' => 'amazon_india',
            'image' => 'marketplace-logos/amazon_india.jpg',
        ],
        'amazon' => [
            'handler' => 'App\Connector\Models\User\Service',
            'code' => 'amazon',
            'title' => 'Amazon',
            'type' => 'uploader',
            'charge_type' => 'prepaid',
            'marketplace' => 'amazon',
            'image' => 'marketplace-logos/amazon.jpg',
        ]
    ],
    'amazon_india' => [
        'sub_app_id' => 2,
    ],
    "di" => [
        //        "amazon_logger" => "\App\Amazon\Components\Util\Logger"
        '\App\Core\Components\Message\Handler\Sqs' => [
            'default' => Sqs::class
        ]

    ],
    'detailed_webhook_response' => true,
    'allowed_filters' => [
        'fulfillment_type',
        'duplicate_sku',
        'process_tags',
        'error',
        'warning',
        'target_listing_id',
        'asin',
        'amazonProductType',
        'last_synced_at',
        'amazonStatus'
    ],
    'webhook' => [
        'amazon' => [
            [
                "topic" => "REPORT_PROCESSING_FINISHED",
                "code" => "REPORT_PROCESSING_FINISHED"
            ],
            [
                "topic" => "ORDER_CHANGE",
                "code" => "ORDER_CHANGE"
            ],
            [
                "topic" => "LISTINGS_ITEM_STATUS_CHANGE",
                "code" => "LISTINGS_ITEM_STATUS_CHANGE"
            ],
            [
                "topic" => "FEED_PROCESSING_FINISHED",
                "code" => "FEED_PROCESSING_FINISHED"
            ],
            [
                "topic" => "ACCOUNT_STATUS_CHANGED",
                "code" => "ACCOUNT_STATUS_CHANGED"
            ],
            [
                "topic" => "LISTINGS_ITEM_ISSUES_CHANGE",
                "code" => "LISTINGS_ITEM_ISSUES_CHANGE"
            ],
            [
                "topic" => "PRODUCT_TYPE_DEFINITIONS_CHANGE",
                "code" => "PRODUCT_TYPE_DEFINITIONS_CHANGE"
            ],
            [
                "topic" => "PRICING_HEALTH",
                "code" => "PRICING_HEALTH"
            ],
            [
                "topic" => "ANY_OFFER_CHANGED",
                "code" => "ANY_OFFER_CHANGED"
            ]
        ]
    ],
    'email_templates_path' => [
        'amazon' => [
            'staff_request' => 'amazon' . DS . 'view' . DS . 'email' . DS . 'staff' . DS . 'staffRequest.volt',
            'staff_approve' => 'amazon' . DS . 'view' . DS . 'email' . DS . 'staff' . DS . 'staffApprove.volt',
            'staff_reject' => 'amazon' . DS . 'view' . DS . 'email' . DS . 'staff' . DS . 'staffReject.volt',
            'staff_delete' => 'amazon' . DS . 'view' . DS . 'email' . DS . 'staff' . DS . 'staffDelete.volt'
        ]
    ],
];
