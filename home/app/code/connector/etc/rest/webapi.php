<?php
return [
    'restapi' => [
        'v1' => [
            'POST' => [
                'routes' => [
                    'add-shop' => [
                        'url' => 'add-shop',
                        'class' => 'App\Connector\Api\Shop',
                        'method' => 'addShop',
                        'resource' => 'add-shop',
                    ],
                    "solution/get" => [
                        "url" => "solution",
                        "method" => "get",
                        "resource" => "solution/get",
                        "class" => "\App\Connector\Api\Solution"
                    ],
                    "solution/update" => [
                        "url" => "solution",
                        "method" => "update",
                        "resource" => "solution/update",
                        "class" => "\App\Connector\Api\Solution"
                    ],
                    "solution/create" => [
                        "url" => "solution",
                        "method" => "create",
                        "resource" => "solution/create",
                        "class" => "\App\Connector\Api\Solution"
                    ],
                    "solution/delete" => [
                        "url" => "solution",
                        "method" => "delete",
                        "resource" => "solution/delete",
                        "class" => "\App\Connector\Api\Solution"
                    ],
                    "faq/search" => [
                        "url" => "faq/search",
                        "method" => "search",
                        "resource" => "faq/search",
                        "class" => "\App\Connector\Api\Solution"
                    ],
                    "faq/create" => [
                        "url" => "faq/create",
                        "method" => "createfaq",
                        "resource" => "faq/create",
                        "class" => "\App\Connector\Api\Solution"
                    ],
                    "shop/changeStatus" => [
                        "url" => "shop/changeStatus",
                        "method" => "changeShopStatus",
                        "resource" => "shop/changeStatus",
                        "class" => "\App\Connector\Api\Shop"
                    ],
                    "user/changeStatus" => [
                        "url" => "user/changeStatus",
                        "method" => "changeUserStatus",
                        "resource" => "user/changeStatus",
                        "class" => "\App\Connector\Api\User"
                    ],
                    "app/changeStatus" => [
                        "url" => "app/changeStatus",
                        "method" => "changeAppStatus",
                        "resource" => "UserShopsApps/changeAppStatus",
                        "class" => "\App\Connector\Api\UserShopsApps"
                    ]
                ]
            ], "GET" => [
                "routes" => [
                    "solution/getall" => [
                        "url" => "solution",
                        "method" => "getAll",
                        "resource" => "solution/getall",
                        "class" => "\App\Connector\Api\Solution"
                    ],
                    "faq/marketplaces" => [
                        "url" => "faq/marketplaces",
                        "method" => "marketplaces",
                        "resource" => "faq/marketplaces",
                        "class" => "\App\Connector\Api\Solution"
                    ],
                    "faq/getFaqByCode" => [
                        "url" => "faq/getFaqByCode",
                        "method" => "getFaqByCode",
                        "resource" => "faq/getFaqByCode",
                        "class" => "\App\Connector\Api\Solution"
                    ],
                    "faq/code" => [
                        "url" => "faq/code",
                        "method" => "code",
                        "resource" => "faq/code",
                        "class" => "\App\Connector\Api\Solution"
                    ]
                ]
            ],
            'DELETE' => [
                "routes" => [
                    "faq/delete" => [
                        "url" => "faq/delete",
                        "method" => "deleteFaq",
                        "resource" => "faq/delete",
                        "class" => "\App\Connector\Api\Solution"
                    ],
                ]
            ],
            'PATCH' => [
                "routes" => [
                    "faq/update" => [
                        "url" => "faq/update",
                        "method" => "updateFaq",
                        "resource" => "faq/update",
                        "class" => "\App\Connector\Api\Solution"
                    ],
                    "update/locale" => [
                        "url" => "update/locale",
                        "method" => "updateLocale",
                        "resource" => "update/locale",
                        "class" => "\App\Connector\Api\Locale"
                    ],
                ]
            ]
        ],
    ]
];
