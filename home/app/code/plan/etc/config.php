<?php

use App\Plan\Components\HandleEvent;
return [
    'events' => [
        'application:afterOrderFetch' => [
            'plan_counter_update' => HandleEvent::class
        ],
        'application:afterAccountConnection'=>[
            'plan_after_account_connection'=> HandleEvent::class
        ],
        'application:appUninstall'=>[
            'plan_app_uninstall'=> HandleEvent::class
        ],
        'application:appReInstall'=>[
            'plan_app_uninstall'=> HandleEvent::class
        ],
        'application:afterProductCreateServiceUpdate'=>[
            'plan_after_product_create_service_update'=> HandleEvent::class
        ],
        'application:afterProductDelete'=>[
            'plan_product_delete'=> HandleEvent::class
        ],
        'application:beforeAppUninstall'=>[
            'plan_before_app_uninstall'=> HandleEvent::class
        ],
        'application:afterAccountDisconnect' => [
            'plan_after_account_disconnect' => HandleEvent::class
        ],
        'application:afterProfileCreated' => [
            'plan_after_profile_created' => HandleEvent::class
        ],
        'application:afterProfileDeleted' => [
            'plan_after_profile_deleted' => HandleEvent::class
        ]
    ],
    'supported_services' => [
        'order_sync' => [],
        'product_import' => [
            'cache_refresh_time' =>  18000
        ]
    ],
    'amazon_priority_user_shop_ids' => ["1875", "18715", "42278", "77891"],
    'recommendations_active' => true,
    'basic_billed_type' => 'monthly',
    'plan_call_type' => 'QL',
    "testUserIds" => [
        "65cc513887dce4195b0ac95c",
        '641adf51fde0c9a1e0054481',
        '641456acb4e8123fcf04d802',
        "6407357d720f13f05f0d87f2",
        "653a4ca2506bd44970069002",
        "653a4cf2f2db47da030607f6",
        "65437a86013bf2eb0f063af2",
        "6531414651e4bdebdf04932c",
        '66a9c1a2ed4499007302e377',
        '6697a544ebe11056bb0555aa'
    ]
];
