<?php

return [
    'restapi' => [
        "v1" => [
            "POST" => [
                "routes" => [
                    //User APIs
                    "exec" => [
                        "url" => "exec",
                        "method" => "execute",
                        "resource" => "command/execute",
                        "class" => "\App\Core\Api\Command"
                    ],
                ]
            ],
            "GET" => [
                "routes" => [
                    //User APIs
                    "ping" => [
                        "url" => "ping",
                        "method" => "get",
                        "resource" => "ping/get",
                        "class" => "\App\Core\Api\Ping"
                    ]
                ]
            ]
        ]
    ]
];
