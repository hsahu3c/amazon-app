<?php

use App\Shopifyhome\Models\SourceModel;
use App\Shopifyhome\Components\UserEvent;
use App\Shopifyhome\Components\Product\BatchImport;
use App\Shopifyhome\Components\Product\BundleComponent;
use App\Shopifyhome\Components\Shop\Shop;
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
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */
return [
    'throttle' => [
        'shopify' => [
            'seconds' => 1,
            'limit' => 2,
            'sqs_timeout' => 240
        ]
    ],
    'security' => [
        'default_shopify_sign' => 'cedp@$$w0rd110'
    ],
    'shopify_redirect_after_install' => 'apps/installedapps',
    'connectors' => [
        'shopify' => [
            'code' => 'shopify',
            'type' => 'real',
            'is_source' => 1,
            'can_import' => 1, /* import feature is available or not */
            'image' => 'marketplace-logos/shopify.png',
            'title' => 'Shopify',
            'description' => 'Shopify integration',
            'source_model' => SourceModel::class,
            'sendResetPassword' => false
        ],
    ],
    'services' => [
        'shopify' => [
            'handler' => 'App\Connector\Models\User\Service',
            'code' => 'shopify',
            'title' => 'Shopify',
            'type' => 'importer',
            'charge_type' => 'prepaid',
            'marketplace' => 'shopify',
            'image' => 'marketplace-logos/shopify.png',
        ]
    ],
    "webhookQueue" => [
        'products/create' => [
            'queue_unique_id' => 'sqs-amazon_shopify_products_create',
            'queue_name' => 'amazon_shopify_products_create',
        ],
        'products/update' => [
            'queue_unique_id' => 'sqs-amazon_shopify_products_update',
            'queue_name' => 'amazon_shopify_products_update',
        ],
        'products/delete' => [
            'queue_unique_id' => 'sqs-amazon_shopify_products_delete',
            'queue_name' => 'amazon_shopify_products_delete',
        ],
        'product_listings/update' => [
            'queue_unique_id' => 'sqs-amazon_shopify_sales_channel_productlistings_update',
            'queue_name' => 'amazon_shopify_sales_channel_productlistings_update',
        ],
        'product_listings/add' => [
            'queue_unique_id' => 'sqs-amazon_shopify_sales_channel_productlistings_add',
            'queue_name' => 'amazon_shopify_sales_channel_productlistings_add',
        ],
        'product_listings/remove' => [
            'queue_unique_id' => 'sqs-amazon_shopify_sales_channel_productlistings_remove',
            'queue_name' => 'amazon_shopify_sales_channel_productlistings_remove',
        ],
        'inventory_levels/update' => [
            'queue_unique_id' => 'sqs-amazon_shopify_inventory_update',
            'queue_name' => 'amazon_shopify_inventory_update',
        ],
        'locations/create' => [
            'queue_unique_id' => 'sqs-amazon_shopify_locations_create',
            'queue_name' => 'amazon_shopify_locations_create',
        ],
        'locations/update' => [
            'queue_unique_id' => 'sqs-amazon_shopify_locations_update',
            'queue_name' => 'amazon_shopify_locations_update',
        ],
        'locations/delete' => [
            'queue_unique_id' => 'sqs-amazon_shopify_locations_delete',
            'queue_name' => 'amazon_shopify_locations_delete',
        ],
        'orders/create' => [
            'queue_unique_id' => 'sqs-amazon_shopify_orders_create',
            'queue_name' => 'amazon_shopify_orders_create',
        ],
        'orders/updated' => [
            'queue_unique_id' => 'sqs-amazon_shopify_orders_updated',
            'queue_name' => 'amazon_shopify_orders_updated',
        ],
        'orders/fulfilled' => [
            'queue_unique_id' => 'sqs-amazon_shopify_orders_fulfilled',
            'queue_name' => 'amazon_shopify_orders_fulfilled',
        ],
        'orders/cancelled' => [
            'queue_unique_id' => 'sqs-amazon_shopify_orders_cancel',
            'queue_name' => 'amazon_shopify_orders_cancel',
        ],
        'orders/delete' => [
            'queue_unique_id' => 'sqs-amazon_shopify_order_delete',
            'queue_name' => 'amazon_shopify_order_delete',
        ],
        'orders/partially_fulfilled' => [
            'queue_unique_id' => 'sqs-amazon_shopify_orders_partial_fulfilled',
            'queue_name' => 'amazon_shopify_orders_partial_fulfilled',
        ],
        'refunds/create' => [
            'queue_unique_id' => 'sqs-amazon_shopify_refund_create',
            'queue_name' => 'amazon_shopify_refund_create',
        ],
        'checkouts/create' => [
            'queue_unique_id' => 'sqs-amazon_shopify_checkouts_create',
            'queue_name' => 'amazon_shopify_checkouts_create',
        ],
        'checkouts/update' => [
            'queue_unique_id' => 'sqs-amazon_shopify_checkouts_update',
            'queue_name' => 'amazon_shopify_checkouts_update',
        ],
        'customers/create' => [
            'queue_unique_id' => 'sqs-amazon_shopify_customers_create',
            'queue_name' => 'amazon_shopify_customers_create',
        ],
        'customers/update' => [
            'queue_unique_id' => 'sqs-amazon_shopify_customers_update',
            'queue_name' => 'amazon_shopify_customers_update',
        ],
        'shop/update' => [
            'queue_unique_id' => 'sqs-amazon_shopify_shop_update',
            'queue_name' => 'amazon_shopify_shop_update',
        ],
        'app/uninstalled' => [
            'queue_unique_id' => 'sqs-amazon_webhook_app_delete',
            'queue_name' => 'amazon_webhook_app_delete',
        ],
        'fulfillments/update' => [
            'queue_unique_id' => 'sqs-amazon_shopify_orders_fulfillments_update',
            'queue_name' => 'amazon_shopify_orders_fulfillments_update',
        ],
        'fulfillments/create' => [
            'queue_unique_id' => 'sqs-amazon_shopify_orders_fulfillments_create',
            'queue_name' => 'amazon_shopify_orders_fulfillments_create',
        ],
        'refunds/create' => [
            'queue_unique_id' => 'sqs-amazon_shopify_orders_refunds_create',
            'queue_name' => 'amazon_shopify_orders_refunds_create',
        ],

    ],
    'webhook' => [
        'default' => [
            [
                'topic' => 'inventory_levels/update',
                'action' => 'inventory_update',
                'marketplace' => 'facebook',
                'app_code' => 'default'
            ],
            [
                'topic' => 'products/update',
                'action' => 'product_update',
                'marketplace' => 'facebook',
                'app_code' => 'default'
            ],
            [
                'topic' => 'products/delete',
                'action' => 'product_delete',
                'marketplace' => 'facebook',
                'app_code' => 'default'
            ],
            [
                'topic' => 'orders/fulfilled',
                'action' => 'order_fulfilled',
                'marketplace' => 'facebook',
                'app_code' => 'default'
            ],
            [
                'topic' => 'orders/create',
                'queue_unique_id' => 'sqs-shopify-order-create',
                'queue_name' => 'facebook_webhook_order_create',
                'action' => 'order_create'
            ],
            [
                'topic' => 'orders/cancelled',
                'action' => 'order_cancel',
                'marketplace' => 'facebook',
                'app_code' => 'default'
            ],
            [
                'topic' => 'orders/delete',
                'action' => 'order_delete',
                'marketplace' => 'facebook',
                'app_code' => 'default'
            ],
            [
                'topic' => 'orders/partially_fulfilled',
                'action' => 'order_partial_fulfilled',
                'marketplace' => 'facebook',
                'app_code' => 'default'
            ],
            [
                'topic' => 'refunds/create',
                'action' => 'refund_create',
                'marketplace' => 'facebook',
                'app_code' => 'default'
            ],
            [
                'topic' => 'locations/create',
                'action' => 'locations_create',
                'marketplace' => 'facebook',
                'app_code' => 'default'
            ],
            [
                'topic' => 'locations/update',
                'action' => 'locations_update',
                'marketplace' => 'facebook',
                'app_code' => 'default'
            ],
            [
                'topic' => 'locations/delete',
                'action' => 'locations_delete',
                'marketplace' => 'facebook',
                'app_code' => 'default'
            ]
        ],
        'shopify_hubspot' => [
            [
                'topic' => 'products/create',
                'action' => 'product_create',
                'marketplace' => 'hubspot',
                'app_code' => 'shopify_hubspot'
            ],
            [
                'topic' => 'products/update',
                'queue_unique_id' => 'sqs-hubspot_webhook_product_update',
                'queue_name' => 'hubspot_webhook_product_update',
                'action' => 'product_update',
                'marketplace' => 'hubspot',
                'app_code' => 'shopify_hubspot'
            ],
            [
                'topic' => 'orders/fulfilled',
                'action' => 'order_fulfilled',
                'marketplace' => 'hubspot',
                'app_code' => 'shopify_hubspot'
            ],
            [
                'topic' => 'orders/cancelled',
                'action' => 'order_cancel',
                'marketplace' => 'hubspot',
                'app_code' => 'shopify_hubspot'
            ],
            [
                'topic' => 'orders/partially_fulfilled',
                'action' => 'order_partial_fulfilled',
                'marketplace' => 'hubspot',
                'app_code' => 'shopify_hubspot'
            ],
            [
                'topic' => 'orders/create',
                'action' => 'orders_create',
                'marketplace' => 'hubspot',
                'app_code' => 'shopify_hubspot'
            ],
            [
                'topic' => 'orders/updated',
                'action' => 'orders_updated',
                'marketplace' => 'hubspot',
                'app_code' => 'shopify_hubspot'
            ],
            [
                'topic' => 'checkouts/create',
                'action' => 'checkouts_create',
                'marketplace' => 'hubspot',
                'app_code' => 'shopify_hubspot'
            ],
            [
                'topic' => 'checkouts/update',
                'action' => 'checkouts_update',
                'marketplace' => 'hubspot',
                'app_code' => 'shopify_hubspot'
            ],
            [
                'topic' => 'customers/create',
                'action' => 'customers_create',
                'marketplace' => 'hubspot',
                'app_code' => 'shopify_hubspot'
            ],
            [
                'topic' => 'customers/update',
                'queue_unique_id' => 'sqs-amazon_shopify_hubspot_customers_update',
                'queue_name' => 'amazon_shopify_hubspot_customers_update',
                'action' => 'customers_update',
                'marketplace' => 'hubspot',
                'app_code' => 'shopify_hubspot'
            ]
        ],
        'amazon_sales_channel' => [
            [
                'topic' => 'product_listings/update',
                'queue_unique_id' => 'sqs-amazon_sales_channel_productlistings_update',
                'queue_name' => 'amazon_sales_channel_productlistings_update',
                'action' => 'product_listings_update',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'product_listings/add',
                'queue_unique_id' => 'sqs-amazon_sales_channel_productlistings_add',
                'queue_name' => 'amazon_sales_channel_productlistings_add',
                'action' => 'product_listings_add',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'product_listings/remove',
                'queue_unique_id' => 'sqs-amazon_sales_channel_productlistings_remove',
                'queue_name' => 'amazon_sales_channel_productlistings_remove',
                'action' => 'product_listings_remove',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'inventory_levels/update',
                'action' => 'inventory_levels_update',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'inventory_levels/connect',
                'action' => 'inventory_levels_connect',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'inventory_levels/disconnect',
                'action' => 'inventory_levels_disconnect',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'locations/create',
                'action' => 'locations_create',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'locations/update',
                'action' => 'locations_update',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'locations/delete',
                'action' => 'locations_delete',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'app/uninstalled',
                'action' => 'app_delete',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel',
                'queue_unique_id' => 'sqs-amazon_sales_channel',
                'queue_name' => 'amazon_webhook_app_delete'
            ],
            [
                'topic' => 'orders/create',
                'action' => 'orders_create',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'fulfillments/update',
                'action' => 'fulfillments_update',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'fulfillments/create',
                'action' => 'fulfillments_create',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'refunds/create',
                'action' => 'refunds_create',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'shop/update',
                'action' => 'shop_update',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
            [
                'topic' => 'app_subscriptions/update',
                'action' => 'app_subscriptions_update',
                'marketplace' => 'amazon',
                'app_code' => 'amazon_sales_channel'
            ],
        ],

    ],
    'api' => [
        'product' => [
            'bulk' => "all", // all , deny , partial
            'vistar' => true,
            'shopify_domain' => [
                "20hazar.myshopify.com",
                "gomezdeal7.myshopify.com",
                "7-4-2020.myshopify.com",
                "maxsupshop.myshopify.com",
                "yoga-clothing-for-you.myshopify.com",
                "jemkim.myshopify.com",
                "superproductsclub.myshopify.com",
                "galu-cell-phones.myshopify.com",
                "barn-mansion.myshopify.com",
                "sparklingselections.myshopify.com",
                "point-supplies.myshopify.com",
                "test-rahul-facebook-local.myshopify.com"
            ]
        ]
    ],
    'required_child_fields' => [
        "additional_images" => [],
        "barcode" => "",
        "brand" => "",
        "container_id" => "",
        'source_created_at' => "",
        'source_updated_at' => "",
        'created_at' => "",
        'updated_at' => "",
        "description" => "",
        //"grams" => "",
        "handle" => "",
        "fulfillment_service" => "",
        "inventory_item_id" => "",
        "inventory_policy" => "",
        "inventory_tracked" => "",
        "locations" => [],
        "low_sku" => "",
        "main_image" => "", //variant image getting from shopify
        "marketplace" => [],
        "position" => "",
        "product_type" => "",
        "published_at" => "",
        "quantity" => null,
        "requires_shipping" => "",
        "shop_id" => "",
        "sku" => "",
        "source_marketplace" => "",
        "source_product_id" => "",
        "source_sku" => "",
        "tags" => "",
        "taxable" => "",
        "title" => "",
        "type" => "",
        "user_id" => "",
        "variant_attributes" => [],
        "variant_title" => "",
        // "visibility" => "",
        "weight" => "",
        "weight_unit" => "",
        "price" => null,
        "sales_price" => null,// "" for shopify
        "compare_at_price" => null, //
    ],
    'required_simple_product_fields' => [
        'additional_images' => [],
        'variant_attributes' => [],
        'marketplace' => [],
        'locations' => [],
        'brand' => "",
        'container_id' => "",
        'source_created_at' => "",
        'source_updated_at' => "",
        'created_at' => "",
        'updated_at' => "",
        'description' => "",
        'handle' => "",
        'main_image' => "",
        'low_sku' => "",
        'product_type' => "",
        'published_at' => "",
        'requires_shipping' => "",
        'shop_id' => "",
        'sku' => "", // never be empty
        'source_marketplace' => "",
        'source_product_id' => "",
        'source_sku' => "", // always same as shopify
        'tags' => "",
        'taxable' => "",
        'title' => "",
        'type' => "",
        'user_id' => "",
        'visibility' => "",
        "currency_code" => "",
        "default_locale" => "",
        "fulfillment_service" => "",
        "inventory_item_id" => "",
        "inventory_policy" => "",
        "inventory_tracked" => "",

    ],
    'required_variant_product_parent_fields' => [
        "additional_images" => [],
        "brand" => "",
        "container_id" => "",
        'source_created_at' => "",
        'source_updated_at' => "",
        'created_at' => "",
        'updated_at' => "",
        "description" => "",
        "handle" => "",
        "low_sku" => "",
        "main_image" => "",
        "marketplace" => [],
        "product_type" => "",
        "published_at" => "",
        "shop_id" => "",
        "sku" => "",
        "source_marketplace" => "",
        "source_product_id" => "",
        "source_sku" => "",
        "tags" => "",
        "taxable" => "",
        "title" => "",
        "type" => "",
        "user_id" => "",
        "variant_attributes" => [],
        // "visibility" => "",
    ],
    'events' => [
        'application:afterAccountConnection' => [
            'shopify_after_account_connection' => UserEvent::class
        ],
        'application:beforeDisconnect' => [
            'shopify_before_disconnect' => UserEvent::class
        ],
        'application:afterProductImport' => [
            'shopify_after_product_import' => UserEvent::class,
            'handle_bundle_component_import' => BundleComponent::class
        ],
        'application:afterProductUpdate' => [
            'shopify_after_product_update' => UserEvent::class,
        ],
        'application:appReInstall' => [
            'shopify_appReInstall' => UserEvent::class,
        ],
        'application:afterQueuedTaskComplete' => [
            'shopify_batch_product_import_complete' => BatchImport::class
        ],
        'application:planServiceUpdate' => [
            'plan_service_update_service' => UserEvent::class
        ],
        'application:afterBatchProductImport' => [
            'handle_bundle_component_import' => BundleComponent::class,
        ],
        'application:shopifyBundleProductSkipped' => [
            'shopify_bundle_product_skipped' => BundleComponent::class,
        ],
        'application:apiFailedStatusCodes' => [
            'shopify_api_failed_status_codes' => Shop::class,
        ],
    ],
    'plan_restrictions' => [
        'amazon_sales_channel' => [
            'product' => [
                'product_import' => [
                    'restricted' => true,
                    'parameter' => 'import_limit',
                    'value' => 10000
                ]
            ]
        ]
    ],
    'user_update_fr_new_product_update' => [
        '6726600aa77bc7b5cd0ca574',
        '689c6a5ec787a3b5660c77e3'
    ],
    'webhooks_to_be_updated_as_per_plan_tier' => [
        'amazon_sales_channel' => [
            'product_listings/update',
            'inventory_levels/update'
        ],
    ],
    // 'di' => [
    //     'App\Connector\Contracts\Sales\Order\ReturnInterface' => [
    //         'shopify' => 'App\Shopifyhome\Service\OrderReturn'
    //     ]
    // ]
];
