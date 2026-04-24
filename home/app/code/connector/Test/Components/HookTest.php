<?php

namespace App\Connector\Test\Components;

class HookTest extends \App\Core\Components\UnitTestApp
{
    /**
     * function to test the checkstatus method
     */
    public function testTemporarlyUninstall(): void
    {
        $data =  [
            "shop" => "pirates-of-ecommerce.myshopify.com",
            'data' => [],
            "type" => "full_class",
            "class_name" => "\App\Connector\Models\SourceModel",
            "method" => "triggerWebhooks",
            "user_id" => "632bff05fe195ab84508a298",
            "shop_id" => "76",
            "action" => "app_delete",
            "handle_added" => "1",
            "queue_name" => "onyx_Eraser_product_update",
            "app_code" => "Eraser",
            "marketplace" => "shopify"
        ];
        $result = ['success' => true, 'message' => "Successfully added uninstall key and disconnected fields."];
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->TemporarlyUninstall($data, "632bff05fe195ab84508a298");

        $this->assertEquals(
            $response,
            $result
        );
    }

    public function testAppDelete(): void
    {
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->appDelete();
        $this->assertEquals(
            $response,
            true
        );
    }

    public function testShopEraser(): void
    {
        $arr02 = [
            "type" => "full_class",
            "class_name" => "\App\Connector\Components\Hook",
            "method" => "shopEraser",
            "queue_name" => "onyx__uninstall_shop_eraser",
            "data" => [
                "shop" => [
                    "id" => 65934196959,
                    "name" => "erasershop_",
                    "email" => "utkarshpatel@cedcoss.com",
                    "domain" => "erasershop.myshopify.com",
                    "province" => "Uttar Pradesh",
                    "country" => "IN",
                    "address1" => "Vishwas Khand Gomti Nagar",
                    "zip" => "226010",
                    "city" => "Lucknow",
                    "source" => null,
                    "phone" => "",
                    "latitude" => null,
                    "longitude" => null,
                    "primary_locale" => "hi",
                    "address2" => "3/140",
                    "created_at" => [
                        "date" => [
                            "numberLong" => "1663827720295"
                        ]
                    ],
                    "updated_at" => "2022-09-22 06:23:43",
                    "country_code" => "IN",
                    "country_name" => "India",
                    "currency" => "GEL",
                    "customer_email" => "utkarshpatel@cedcommerce.com",
                    "timezone" => "(GMT+05:30) Asia/Calcutta",
                    "iana_timezone" => "Asia/Calcutta",
                    "shop_owner" => "Utkarsh Patel",
                    "money_format" => "{{amount}} GEL",
                    "money_with_currency_format" => "{{amount}} GEL",
                    "weight_unit" => "lb",
                    "province_code" => "UP",
                    "taxes_included" => false,
                    "auto_configure_tax_inclusivity" => null,
                    "tax_shipping" => null,
                    "county_taxes" => true,
                    "plan_display_name" => "Developer Preview",
                    "plan_name" => "partner_test",
                    "has_discounts" => false,
                    "has_gift_cards" => false,
                    "myshopify_domain" => "erasershop.myshopify.com",
                    "google_apps_domain" => null,
                    "google_apps_login_enabled" => null,
                    "money_in_emails_format" => "{{amount}} GEL",
                    "money_with_currency_in_emails_format" => "{{amount}} GEL",
                    "eligible_for_payments" => false,
                    "requires_extra_payments_agreement" => false,
                    "password_enabled" => true,
                    "has_storefront" => true,
                    "eligible_for_card_reader_giveaway" => false,
                    "finances" => true,
                    "primary_location_id" => 70532628703,
                    "cookie_consent_level" => "implicit",
                    "visitor_tracking_consent_preference" => "allow_all",
                    "checkout_api_supported" => false,
                    "multi_location_enabled" => true,
                    "setup_required" => false,
                    "pre_launch_enabled" => false,
                    "enabled_presentment_currencies" => [
                        "GEL"
                    ],
                    "username" => "erasershop.myshopify.com",
                    "apps" => [
                        [
                            "code" => "Eraser",
                            "app_status" => "uninstall",
                            "webhooks" => [
                                [
                                    "code" => "app_delete",
                                    "dynamo_webhook_id" => "573"
                                ]
                            ],
                            "erase_data_after_date" => "2022-09-23T07:43:23+00:00",
                            "uninstall_date" => "2022-09-23T07:43:23+00:00"
                        ]
                    ],
                    "remote_shop_id" => "36",
                    "marketplace" => "shopify",
                    "last_login_at" => "2022-09-22 06:21:59",
                    "warehouses" => [
                        [
                            "id" => "70660849887",
                            "name" => "Paris",
                            "address1" => "17 Ganpathi Nagar Paharia",
                            "address2" => "124",
                            "city" => "Varanasi",
                            "zip" => "221007",
                            "province" => "Uttar Pradesh",
                            "country" => "IN",
                            "phone" => "+918858710504",
                            "country_code" => "IN",
                            "country_name" => "India",
                            "province_code" => "UP",
                            "legacy" => false,
                            "active" => true,
                            "admin_graphql_api_id" => "gid://shopify/Location/70660849887",
                            "localized_country_name" => "India",
                            "localized_province_name" => "Uttar Pradesh",
                            "_id" => "143"
                        ],
                        [
                            "id" => "70532628703",
                            "name" => "Vishwas Khand, Gomti Nagar",
                            "address1" => "Vishwas Khand, Gomti Nagar",
                            "address2" => "",
                            "city" => "Lucknow",
                            "zip" => "226010",
                            "province" => "Uttar Pradesh",
                            "country" => "IN",
                            "phone" => "",
                            "country_code" => "IN",
                            "country_name" => "India",
                            "province_code" => "UP",
                            "legacy" => false,
                            "active" => true,
                            "admin_graphql_api_id" => "gid://shopify/Location/70532628703",
                            "localized_country_name" => "India",
                            "localized_province_name" => "Uttar Pradesh",
                            "_id" => "144"
                        ]
                    ],
                    "_id" => "76",
                    "targets" => [
                        [
                            "shop_id" => "77",
                            "marketplace" => "amazon",
                            "app_code" => "amazon",
                            "disconnected" => true
                        ]
                    ],
                    "shop_status" => "deactive"
                ]
            ],
            "user_id" => "632bff05fe195ab84508a298",
            "shop_id" => "76",
            "marketplace" => "shopify",
            "handle_added" => 1,
            "appTag" => "default",
            "appCode" => [
                "shopify" => "default"
            ]
        ];
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->shopEraser($arr02);

        $this->assertEquals(
            $response,
            true
        );
    }

    public function testDeleteUninstallShop(): void
    {
        $arr02 = [
            "type" => "full_class",
            "class_name" => "\App\Connector\Components\Hook",
            "method" => "shopEraser",
            "queue_name" => "onyx__uninstall_shop_eraser",
            "data" => [
                "shop" => [
                    "id" => 65934196959,
                    "name" => "erasershop_",
                    "email" => "utkarshpatel@cedcoss.com",
                    "domain" => "erasershop.myshopify.com",
                    "province" => "Uttar Pradesh",
                    "country" => "IN",
                    "address1" => "Vishwas Khand Gomti Nagar",
                    "zip" => "226010",
                    "city" => "Lucknow",
                    "source" => null,
                    "phone" => "",
                    "latitude" => null,
                    "longitude" => null,
                    "primary_locale" => "hi",
                    "address2" => "3/140",
                    "created_at" => [
                        "date" => [
                            "numberLong" => "1663827720295"
                        ]
                    ],
                    "updated_at" => "2022-09-22 06:23:43",
                    "country_code" => "IN",
                    "country_name" => "India",
                    "currency" => "GEL",
                    "customer_email" => "utkarshpatel@cedcommerce.com",
                    "timezone" => "(GMT+05:30) Asia/Calcutta",
                    "iana_timezone" => "Asia/Calcutta",
                    "shop_owner" => "Utkarsh Patel",
                    "money_format" => "{{amount}} GEL",
                    "money_with_currency_format" => "{{amount}} GEL",
                    "weight_unit" => "lb",
                    "province_code" => "UP",
                    "taxes_included" => false,
                    "auto_configure_tax_inclusivity" => null,
                    "tax_shipping" => null,
                    "county_taxes" => true,
                    "plan_display_name" => "Developer Preview",
                    "plan_name" => "partner_test",
                    "has_discounts" => false,
                    "has_gift_cards" => false,
                    "myshopify_domain" => "erasershop.myshopify.com",
                    "google_apps_domain" => null,
                    "google_apps_login_enabled" => null,
                    "money_in_emails_format" => "{{amount}} GEL",
                    "money_with_currency_in_emails_format" => "{{amount}} GEL",
                    "eligible_for_payments" => false,
                    "requires_extra_payments_agreement" => false,
                    "password_enabled" => true,
                    "has_storefront" => true,
                    "eligible_for_card_reader_giveaway" => false,
                    "finances" => true,
                    "primary_location_id" => 70532628703,
                    "cookie_consent_level" => "implicit",
                    "visitor_tracking_consent_preference" => "allow_all",
                    "checkout_api_supported" => false,
                    "multi_location_enabled" => true,
                    "setup_required" => false,
                    "pre_launch_enabled" => false,
                    "enabled_presentment_currencies" => [
                        "GEL"
                    ],
                    "username" => "erasershop.myshopify.com",
                    "apps" => [
                        [
                            "code" => "Eraser",
                            "app_status" => "uninstall",
                            "webhooks" => [
                                [
                                    "code" => "app_delete",
                                    "dynamo_webhook_id" => "573"
                                ]
                            ],
                            "erase_data_after_date" => "2022-09-23T07:43:23+00:00",
                            "uninstall_date" => "2022-09-23T07:43:23+00:00"
                        ]
                    ],
                    "remote_shop_id" => "36",
                    "marketplace" => "shopify",
                    "last_login_at" => "2022-09-22 06:21:59",
                    "warehouses" => [
                        [
                            "id" => "70660849887",
                            "name" => "Paris",
                            "address1" => "17 Ganpathi Nagar Paharia",
                            "address2" => "124",
                            "city" => "Varanasi",
                            "zip" => "221007",
                            "province" => "Uttar Pradesh",
                            "country" => "IN",
                            "phone" => "+918858710504",
                            "country_code" => "IN",
                            "country_name" => "India",
                            "province_code" => "UP",
                            "legacy" => false,
                            "active" => true,
                            "admin_graphql_api_id" => "gid://shopify/Location/70660849887",
                            "localized_country_name" => "India",
                            "localized_province_name" => "Uttar Pradesh",
                            "_id" => "143"
                        ],
                        [
                            "id" => "70532628703",
                            "name" => "Vishwas Khand, Gomti Nagar",
                            "address1" => "Vishwas Khand, Gomti Nagar",
                            "address2" => "",
                            "city" => "Lucknow",
                            "zip" => "226010",
                            "province" => "Uttar Pradesh",
                            "country" => "IN",
                            "phone" => "",
                            "country_code" => "IN",
                            "country_name" => "India",
                            "province_code" => "UP",
                            "legacy" => false,
                            "active" => true,
                            "admin_graphql_api_id" => "gid://shopify/Location/70532628703",
                            "localized_country_name" => "India",
                            "localized_province_name" => "Uttar Pradesh",
                            "_id" => "144"
                        ]
                    ],
                    "_id" => "76",
                    "targets" => [
                        [
                            "shop_id" => "77",
                            "marketplace" => "amazon",
                            "app_code" => "amazon",
                            "disconnected" => true
                        ]
                    ],
                    "shop_status" => "deactive"
                ]
            ],
            "user_id" => "632bff05fe195ab84508a298",
            "shop_id" => "76",
            "marketplace" => "shopify",
            "handle_added" => 1,
            "appTag" => "default",
            "appCode" => [
                "shopify" => "default"
            ]
        ];
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->shopEraser($arr02);

        $this->assertEquals(
            $response,
            true
        );
    }

    public function testHandleWebhooksAfterAppUninstall(): void
    {
        $shop = [
            "id" => 65934196959,
            "name" => "erasershop_",
            "email" => "utkarshpatel@cedcoss.com",
            "domain" => "erasershop.myshopify.com",
            "province" => "Uttar Pradesh",
            "country" => "IN",
            "address1" => "Vishwas Khand Gomti Nagar",
            "zip" => "226010",
            "city" => "Lucknow",
            "source" => null,
            "phone" => "",
            "latitude" => null,
            "longitude" => null,
            "primary_locale" => "hi",
            "address2" => "3/140",
            "created_at" => [
                "date" => [
                    "numberLong" => "1663827720295"
                ]
            ],
            "updated_at" => "2022-09-22 06:23:43",
            "country_code" => "IN",
            "country_name" => "India",
            "currency" => "GEL",
            "customer_email" => "utkarshpatel@cedcommerce.com",
            "timezone" => "(GMT+05:30) Asia/Calcutta",
            "iana_timezone" => "Asia/Calcutta",
            "shop_owner" => "Utkarsh Patel",
            "money_format" => "{{amount}} GEL",
            "money_with_currency_format" => "{{amount}} GEL",
            "weight_unit" => "lb",
            "province_code" => "UP",
            "taxes_included" => false,
            "auto_configure_tax_inclusivity" => null,
            "tax_shipping" => null,
            "county_taxes" => true,
            "plan_display_name" => "Developer Preview",
            "plan_name" => "partner_test",
            "has_discounts" => false,
            "has_gift_cards" => false,
            "myshopify_domain" => "erasershop.myshopify.com",
            "google_apps_domain" => null,
            "google_apps_login_enabled" => null,
            "money_in_emails_format" => "{{amount}} GEL",
            "money_with_currency_in_emails_format" => "{{amount}} GEL",
            "eligible_for_payments" => false,
            "requires_extra_payments_agreement" => false,
            "password_enabled" => true,
            "has_storefront" => true,
            "eligible_for_card_reader_giveaway" => false,
            "finances" => true,
            "primary_location_id" => 70532628703,
            "cookie_consent_level" => "implicit",
            "visitor_tracking_consent_preference" => "allow_all",
            "checkout_api_supported" => false,
            "multi_location_enabled" => true,
            "setup_required" => false,
            "pre_launch_enabled" => false,
            "enabled_presentment_currencies" => [
                "GEL"
            ],
            "username" => "erasershop.myshopify.com",
            "apps" => [
                [
                    "code" => "Eraser",
                    "app_status" => "uninstall",
                    "webhooks" => [
                        [
                            "code" => "app_delete",
                            "dynamo_webhook_id" => "573"
                        ]
                    ],
                    "erase_data_after_date" => "2022-09-23T07:43:23+00:00",
                    "uninstall_date" => "2022-09-23T07:43:23+00:00"
                ]
            ],
            "remote_shop_id" => "36",
            "marketplace" => "shopify",
            "last_login_at" => "2022-09-22 06:21:59",
            "warehouses" => [
                [
                    "id" => "70660849887",
                    "name" => "Paris",
                    "address1" => "17 Ganpathi Nagar Paharia",
                    "address2" => "124",
                    "city" => "Varanasi",
                    "zip" => "221007",
                    "province" => "Uttar Pradesh",
                    "country" => "IN",
                    "phone" => "+918858710504",
                    "country_code" => "IN",
                    "country_name" => "India",
                    "province_code" => "UP",
                    "legacy" => false,
                    "active" => true,
                    "admin_graphql_api_id" => "gid://shopify/Location/70660849887",
                    "localized_country_name" => "India",
                    "localized_province_name" => "Uttar Pradesh",
                    "_id" => "143"
                ],
                [
                    "id" => "70532628703",
                    "name" => "Vishwas Khand, Gomti Nagar",
                    "address1" => "Vishwas Khand, Gomti Nagar",
                    "address2" => "",
                    "city" => "Lucknow",
                    "zip" => "226010",
                    "province" => "Uttar Pradesh",
                    "country" => "IN",
                    "phone" => "",
                    "country_code" => "IN",
                    "country_name" => "India",
                    "province_code" => "UP",
                    "legacy" => false,
                    "active" => true,
                    "admin_graphql_api_id" => "gid://shopify/Location/70532628703",
                    "localized_country_name" => "India",
                    "localized_province_name" => "Uttar Pradesh",
                    "_id" => "144"
                ]
            ],
            "_id" => "76",
            "targets" => [
                [
                    "shop_id" => "77",
                    "marketplace" => "amazon",
                    "app_code" => "amazon",
                    "disconnected" => true
                ]
            ],
            "shop_status" => "deactive"
        ];
        $appCode = "Eraser";
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->handleWebhooksAfterAppUninstall($shop, $appCode);

        $this->assertEquals(
            $response,
            true
        );
    }

    public function testDisconnectAccount(): void
    {
        $userId = "632bff05fe195ab84508a298";
        $data = [
            "source" => [
                "shopId" => "76",
                "marketplace" => "shopify"
            ],
            "target" => [
                "shopId" => "77",
                "marketplace" => "amazon"
            ],
            'disconnected' => [
                'target' => 1
            ]
        ];
        $result = ['success' => true, 'message' => 'Successfully inserted uninstall keys'];
        $erase_data_after_date = date('c');
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->disconnectAccount($userId, $data, $erase_data_after_date);

        $this->assertEquals(
            $response,
            $result
        );
    }

    public function testSetUninstallStatusInAppForDisconnectAccount(): void
    {
        $userId = "632bff05fe195ab84508a298";
        $appCode = "Eraser";
        $shopId = "76";
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->setUninstallStatusInAppForDisconnectAccount($userId, [], $shopId, $appCode, "");

        $this->assertEquals(
            $response,
            ['success' => true, 'message' => 'App status set to uninstall successfully']
        );
    }

    public function testSetStatusInSourcesAndTargets(): void
    {
        $userId = "632bff05fe195ab84508a298";
        $shop = [
            "id" => 65934196959,
            "name" => "erasershop_",
            "email" => "utkarshpatel@cedcoss.com",
            "domain" => "erasershop.myshopify.com",
            "province" => "Uttar Pradesh",
            "country" => "IN",
            "address1" => "Vishwas Khand Gomti Nagar",
            "zip" => "226010",
            "city" => "Lucknow",
            "source" => null,
            "phone" => "",
            "latitude" => null,
            "longitude" => null,
            "primary_locale" => "hi",
            "address2" => "3/140",
            "created_at" => [
                "date" => [
                    "numberLong" => "1663827720295"
                ]
            ],
            "updated_at" => "2022-09-22 06:23:43",
            "country_code" => "IN",
            "country_name" => "India",
            "currency" => "GEL",
            "customer_email" => "utkarshpatel@cedcommerce.com",
            "timezone" => "(GMT+05:30) Asia/Calcutta",
            "iana_timezone" => "Asia/Calcutta",
            "shop_owner" => "Utkarsh Patel",
            "money_format" => "{{amount}} GEL",
            "money_with_currency_format" => "{{amount}} GEL",
            "weight_unit" => "lb",
            "province_code" => "UP",
            "taxes_included" => false,
            "auto_configure_tax_inclusivity" => null,
            "tax_shipping" => null,
            "county_taxes" => true,
            "plan_display_name" => "Developer Preview",
            "plan_name" => "partner_test",
            "has_discounts" => false,
            "has_gift_cards" => false,
            "myshopify_domain" => "erasershop.myshopify.com",
            "google_apps_domain" => null,
            "google_apps_login_enabled" => null,
            "money_in_emails_format" => "{{amount}} GEL",
            "money_with_currency_in_emails_format" => "{{amount}} GEL",
            "eligible_for_payments" => false,
            "requires_extra_payments_agreement" => false,
            "password_enabled" => true,
            "has_storefront" => true,
            "eligible_for_card_reader_giveaway" => false,
            "finances" => true,
            "primary_location_id" => 70532628703,
            "cookie_consent_level" => "implicit",
            "visitor_tracking_consent_preference" => "allow_all",
            "checkout_api_supported" => false,
            "multi_location_enabled" => true,
            "setup_required" => false,
            "pre_launch_enabled" => false,
            "enabled_presentment_currencies" => [
                "GEL"
            ],
            "username" => "erasershop.myshopify.com",
            "apps" => [
                [
                    "code" => "Eraser",
                    "app_status" => "uninstall",
                    "webhooks" => [
                        [
                            "code" => "app_delete",
                            "dynamo_webhook_id" => "573"
                        ]
                    ],
                    "erase_data_after_date" => "2022-09-23T07:43:23+00:00",
                    "uninstall_date" => "2022-09-23T07:43:23+00:00"
                ]
            ],
            "remote_shop_id" => "36",
            "marketplace" => "shopify",
            "last_login_at" => "2022-09-22 06:21:59",
            "warehouses" => [
                [
                    "id" => "70660849887",
                    "name" => "Paris",
                    "address1" => "17 Ganpathi Nagar Paharia",
                    "address2" => "124",
                    "city" => "Varanasi",
                    "zip" => "221007",
                    "province" => "Uttar Pradesh",
                    "country" => "IN",
                    "phone" => "+918858710504",
                    "country_code" => "IN",
                    "country_name" => "India",
                    "province_code" => "UP",
                    "legacy" => false,
                    "active" => true,
                    "admin_graphql_api_id" => "gid://shopify/Location/70660849887",
                    "localized_country_name" => "India",
                    "localized_province_name" => "Uttar Pradesh",
                    "_id" => "143"
                ],
                [
                    "id" => "70532628703",
                    "name" => "Vishwas Khand, Gomti Nagar",
                    "address1" => "Vishwas Khand, Gomti Nagar",
                    "address2" => "",
                    "city" => "Lucknow",
                    "zip" => "226010",
                    "province" => "Uttar Pradesh",
                    "country" => "IN",
                    "phone" => "",
                    "country_code" => "IN",
                    "country_name" => "India",
                    "province_code" => "UP",
                    "legacy" => false,
                    "active" => true,
                    "admin_graphql_api_id" => "gid://shopify/Location/70532628703",
                    "localized_country_name" => "India",
                    "localized_province_name" => "Uttar Pradesh",
                    "_id" => "144"
                ]
            ],
            "_id" => "76",
            "targets" => [
                [
                    "shop_id" => "77",
                    "marketplace" => "amazon",
                    "app_code" => "amazon",
                    "disconnected" => true
                ]
            ],
            "shop_status" => "deactive"
        ];
        $appCode = "Eraser";
        $result = ['success' => true, 'message' => "Disconnected key added successfully."];
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->setStatusInSourcesAndTargets($userId, $shop, $appCode);

        $this->assertEquals(
            $response,
            $result
        );
    }

    public function testSetUninstallDateInApp(): void
    {
        $userId = "632bff05fe195ab84508a298";
        $appCode = "Eraser";
        $erase_data_after_date = "2022-09-23T07:43:23+00:00";
        $shopId = "76";
        $shop = [
            "id" => 65934196959,
            "name" => "erasershop_",
            "email" => "utkarshpatel@cedcoss.com",
            "domain" => "erasershop.myshopify.com",
            "province" => "Uttar Pradesh",
            "country" => "IN",
            "address1" => "Vishwas Khand Gomti Nagar",
            "zip" => "226010",
            "city" => "Lucknow",
            "source" => null,
            "phone" => "",
            "latitude" => null,
            "longitude" => null,
            "primary_locale" => "hi",
            "address2" => "3/140",
            "created_at" => [
                "date" => [
                    "numberLong" => "1663827720295"
                ]
            ],
            "updated_at" => "2022-09-22 06:23:43",
            "country_code" => "IN",
            "country_name" => "India",
            "currency" => "GEL",
            "customer_email" => "utkarshpatel@cedcommerce.com",
            "timezone" => "(GMT+05:30) Asia/Calcutta",
            "iana_timezone" => "Asia/Calcutta",
            "shop_owner" => "Utkarsh Patel",
            "money_format" => "{{amount}} GEL",
            "money_with_currency_format" => "{{amount}} GEL",
            "weight_unit" => "lb",
            "province_code" => "UP",
            "taxes_included" => false,
            "auto_configure_tax_inclusivity" => null,
            "tax_shipping" => null,
            "county_taxes" => true,
            "plan_display_name" => "Developer Preview",
            "plan_name" => "partner_test",
            "has_discounts" => false,
            "has_gift_cards" => false,
            "myshopify_domain" => "erasershop.myshopify.com",
            "google_apps_domain" => null,
            "google_apps_login_enabled" => null,
            "money_in_emails_format" => "{{amount}} GEL",
            "money_with_currency_in_emails_format" => "{{amount}} GEL",
            "eligible_for_payments" => false,
            "requires_extra_payments_agreement" => false,
            "password_enabled" => true,
            "has_storefront" => true,
            "eligible_for_card_reader_giveaway" => false,
            "finances" => true,
            "primary_location_id" => 70532628703,
            "cookie_consent_level" => "implicit",
            "visitor_tracking_consent_preference" => "allow_all",
            "checkout_api_supported" => false,
            "multi_location_enabled" => true,
            "setup_required" => false,
            "pre_launch_enabled" => false,
            "enabled_presentment_currencies" => [
                "GEL"
            ],
            "username" => "erasershop.myshopify.com",
            "apps" => [
                [
                    "code" => "Eraser",
                    "app_status" => "uninstall",
                    "webhooks" => [
                        [
                            "code" => "app_delete",
                            "dynamo_webhook_id" => "573"
                        ]
                    ],
                    "erase_data_after_date" => "2022-09-23T07:43:23+00:00",
                    "uninstall_date" => "2022-09-23T07:43:23+00:00"
                ]
            ],
            "remote_shop_id" => "36",
            "marketplace" => "shopify",
            "last_login_at" => "2022-09-22 06:21:59",
            "warehouses" => [
                [
                    "id" => "70660849887",
                    "name" => "Paris",
                    "address1" => "17 Ganpathi Nagar Paharia",
                    "address2" => "124",
                    "city" => "Varanasi",
                    "zip" => "221007",
                    "province" => "Uttar Pradesh",
                    "country" => "IN",
                    "phone" => "+918858710504",
                    "country_code" => "IN",
                    "country_name" => "India",
                    "province_code" => "UP",
                    "legacy" => false,
                    "active" => true,
                    "admin_graphql_api_id" => "gid://shopify/Location/70660849887",
                    "localized_country_name" => "India",
                    "localized_province_name" => "Uttar Pradesh",
                    "_id" => "143"
                ],
                [
                    "id" => "70532628703",
                    "name" => "Vishwas Khand, Gomti Nagar",
                    "address1" => "Vishwas Khand, Gomti Nagar",
                    "address2" => "",
                    "city" => "Lucknow",
                    "zip" => "226010",
                    "province" => "Uttar Pradesh",
                    "country" => "IN",
                    "phone" => "",
                    "country_code" => "IN",
                    "country_name" => "India",
                    "province_code" => "UP",
                    "legacy" => false,
                    "active" => true,
                    "admin_graphql_api_id" => "gid://shopify/Location/70532628703",
                    "localized_country_name" => "India",
                    "localized_province_name" => "Uttar Pradesh",
                    "_id" => "144"
                ]
            ],
            "_id" => "76",
            "targets" => [
                [
                    "shop_id" => "77",
                    "marketplace" => "amazon",
                    "app_code" => "amazon",
                    "disconnected" => true
                ]
            ],
            "shop_status" => "deactive"
        ];
        $result = ['success' => true, 'message' => "Field Added successfully"];
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->setUninstallDateInApp($userId, $shop, $shopId, $appCode, $erase_data_after_date);

        $this->assertEquals(
            $response,
            $result
        );
    }

    public function testSendDataToMarketplace(): void
    {
        $destinations = [
            [
                'shop_id' => "77",
                'marketplace' => "amazon",
                'app_code' => "amazon",
                'disconnected' => true,
            ]
        ];
        $functionName = 'temporarlyUninstall';
        $data = [
            '_id' => "76",
            'targets' => [
                [
                    'shop_id' => "77",
                    'marketplace' => "amazon",
                    'app_code' => "amazon",
                    'disconnected' => true,
                ]
            ]
        ];
        $result = ['success' => true, 'message' => "Successfully send data to marketplaces"];
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->sendDataToMarketplace($destinations, $functionName, $data);

        $this->assertEquals(
            $response,
            $result
        );
    }

    public function testRemoveDisconnectedTargetsAndSources(): void
    {
        $userId = "632bff05fe195ab84508a298";
        $appCode = "amazon";
        $shop =  [
            "apps" => [
                [
                    "code" => "amazon",
                    "app_status" => "uninstall",
                    "erase_data_after_date" => "2022-09-23T07:43:23+00:00",
                    "uninstall_date" => "2022-09-23T07:43:23+00:00"
                ],
                [
                    "code" => "amazon2"
                ]
            ],
            "remote_shop_id" => "37",
            "marketplace" => "amazon",
            "last_login_at" => "2022-09-22 06:23:36",
            "currency" => "INR",
            "warehouses" => [
                [
                    "region" => "EU",
                    "marketplace_id" => "A21TJRUUN4KGV",
                    "seller_id" => "A3VSMOL1YWESR0",
                    "status" => "active",
                    "token_generated" => true,
                    "_id" => "77"
                ]
            ],
            "_id" => "77",
            "created_at" => [
                "date" => "2022-09-22T06:23:38.734Z"
            ],
            "updated_at" => "2022-09-22 06:23:38",
            "sources" => [
                [
                    "shop_id" => "76",
                    "marketplace" => "shopify",
                    "app_code" => "Eraser",
                    "disconnected" => true
                ],
                [
                    "shop_id" => "76",
                    "marketplace" => "shopify",
                    "app_code" => "Test"
                ]
            ]
        ];

        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->removeDisconnectedTargetsAndSources($userId, $shop, $appCode);

        $this->assertEquals(
            $response,
            true
        );
    }

    public function testDeleteDocumentsOnBasisOfAppCode(): void
    {
        $userId = "632bff05fe195ab84508a298";
        $appCode = "amazon";
        $shop =  [
            "apps" => [
                [
                    "code" => "amazon",
                    "app_status" => "uninstall",
                    "erase_data_after_date" => "2022-09-23T07:43:23+00:00",
                    "uninstall_date" => "2022-09-23T07:43:23+00:00"
                ],
                [
                    "code" => "amazon2"
                ]
            ],
            "remote_shop_id" => "37",
            "marketplace" => "amazon",
            "last_login_at" => "2022-09-22 06:23:36",
            "currency" => "INR",
            "warehouses" => [
                [
                    "region" => "EU",
                    "marketplace_id" => "A21TJRUUN4KGV",
                    "seller_id" => "A3VSMOL1YWESR0",
                    "status" => "active",
                    "token_generated" => true,
                    "_id" => "77"
                ]
            ],
            "_id" => "77",
            "created_at" => [
                "date" => "2022-09-22T06:23:38.734Z"
            ],
            "updated_at" => "2022-09-22 06:23:38",
            "sources" => [
                [
                    "shop_id" => "76",
                    "marketplace" => "shopify",
                    "app_code" => "Eraser",
                    "disconnected" => true
                ],
                [
                    "shop_id" => "76",
                    "marketplace" => "shopify",
                    "app_code" => "Test"
                ]
            ]
        ];

        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->deleteDocumentsOnBasisOfAppCode($userId, $shop, $appCode);

        $this->assertEquals(
            $response,
            true
        );
    }

    public function testPrepareDataToSendMarketplacesForShopEraser(): void
    {
        $userId = "632bff05fe195ab84508a298";
        $appCode = "amazon";
        $shop =  [
            "apps" => [
                [
                    "code" => "amazon",
                    "app_status" => "uninstall",
                    "erase_data_after_date" => "2022-09-23T07:43:23+00:00",
                    "uninstall_date" => "2022-09-23T07:43:23+00:00"
                ],
                [
                    "code" => "amazon2"
                ]
            ],
            "remote_shop_id" => "37",
            "marketplace" => "amazon",
            "last_login_at" => "2022-09-22 06:23:36",
            "currency" => "INR",
            "warehouses" => [
                [
                    "region" => "EU",
                    "marketplace_id" => "A21TJRUUN4KGV",
                    "seller_id" => "A3VSMOL1YWESR0",
                    "status" => "active",
                    "token_generated" => true,
                    "_id" => "77"
                ]
            ],
            "_id" => "77",
            "created_at" => [
                "date" => "2022-09-22T06:23:38.734Z"
            ],
            "updated_at" => "2022-09-22 06:23:38",
            "sources" => [
                [
                    "shop_id" => "76",
                    "marketplace" => "shopify",
                    "app_code" => "Eraser",
                    "disconnected" => true
                ],
                [
                    "shop_id" => "76",
                    "marketplace" => "shopify",
                    "app_code" => "Test"
                ]
            ]
        ];
        $result = ['success' => true, 'shop' =>[
            "apps" => [
                [
                    "code" => "amazon",
                    "app_status" => "uninstall",
                    "erase_data_after_date" => "2022-09-23T07:43:23+00:00",
                    "uninstall_date" => "2022-09-23T07:43:23+00:00"
                ],
                [
                    "code" => "amazon2"
                ]
            ],
            "remote_shop_id" => "37",
            "marketplace" => "amazon",
            "last_login_at" => "2022-09-22 06:23:36",
            "currency" => "INR",
            "warehouses" => [
                [
                    "region" => "EU",
                    "marketplace_id" => "A21TJRUUN4KGV",
                    "seller_id" => "A3VSMOL1YWESR0",
                    "status" => "active",
                    "token_generated" => true,
                    "_id" => "77"
                ]
            ],
            "_id" => "77",
            "created_at" => [
                "date" => "2022-09-22T06:23:38.734Z"
            ],
            "updated_at" => "2022-09-22 06:23:38",
            "sources" => [
                [
                    "shop_id" => "76",
                    "marketplace" => "shopify",
                    "app_code" => "Eraser",
                    "disconnected" => true
                ]
            ]
        ] ];
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->prepareDataToSendMarketplacesForShopEraser($userId, $shop, $appCode);

        $this->assertEquals(
            $response,
            $result
        );
    }

    public function testDeleteDocumentsOnBasisOfAppTagSourceIdTargetId(): void
    {
        $appTag = "test";
        $sourceShopId= "76";
        $targetShopId = "77";
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->deleteDocumentsOnBasisOfAppTagSourceIdTargetId($appTag, $sourceShopId, $targetShopId);

        $this->assertEquals(
            $response,
            true
        );   
    }

    public function testDeleteAppIfNoSourcesTargetsConnnected(): void
    {
        $userId = "632bff05fe195ab84508a298";
        $shopId = "77";
        $result = ['success' => true, 'message' => "Shop contains no active apps, sources and targets."];
        $user = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $response = $user->deleteAppIfNoSourcesTargetsConnnected($userId, $shopId);

        $this->assertEquals(
            $response,
            $result
        );
    }
}
