<?php
return [
    'di' => [
        'App\Core\Components\Container\CacheInterface' => 'App\Core\Components\Cache',
        'session' => 'App\Core\Components\Session',
        'log' => 'App\Core\Components\Log',
        'messageManager' => 'App\Core\Components\Message\Handler\Sqs',
        'appCode' => 'App\Core\Components\AppCode',
        'requester' => 'App\Core\Components\Requester',
        'translation' => 'App\Core\Components\Translation'
    ],
    'base_path_removal' => 'home/public/',
    'events' => [
        'application:beforeHandleRequest' => [
            'firewall' => '\App\Core\Middlewares\Firewall'
        ],
        'application:afterStatusChange' => [
            'afterStatusChange' => '\App\Core\Components\UserEvent'
        ]
    ],
    'open_urls' => [
        'module_controller_action' => 1
    ],
    'throttle' => [
        "is_throttle_enabled" => true,
        "time_limit" => 2,
        "throttle_limit" => 100
    ],
    'bans' => [
        "temp_block_duration" => 86400,
        "max_temp_block" => 3,
        "max_cache_ttl" => 86500,
        "ip_block_duration" => 604800
    ],
    'requests' => [
        "track_global_requests" => true,
        "track_requests" => false,
        "threshold" => 1,
        "max_requests_stack" => 100,
    ],
    'token' => [
        'set_audience' => false
    ],
    'user_otp' => [
        'max_otp_limit' => 5
    ],
    'email_templates_path' => [
        'confirmation' => 'core' . DS . 'view' . DS . 'email' . DS . 'userconfirm.volt',
        'acknowledgement' => 'core' . DS . 'view' . DS . 'email' . DS . 'acknowledgement.volt',
        'welcome' => 'core' . DS . 'view' . DS . 'email' . DS . 'installapp.volt',
        'reset_password' => 'core' . DS . 'view' . DS . 'email' . DS . 'userforgotpassword.volt',
        'send_otp' => 'core' . DS . 'view' . DS . 'email' . DS . 'OTPMail.volt',
        'uninstall' => 'core' . DS . 'view' . DS . 'email' . DS . 'uninstallation_app.volt',
        'report_issue' => 'core' . DS . 'view' . DS . 'email' . DS . 'reportissue.volt',
        'account_block' => 'core' . DS . 'view' . DS . 'email' . DS . 'accountBlock.volt',
        'account_deactivate' => 'core' . DS . 'view' . DS . 'email' . DS . 'accountDeactivate.volt',
        'staff_request' => 'core' . DS . 'view' . DS . 'email' . DS . 'staff' . DS . 'staffRequest.volt',
        'staff_approve' => 'core' . DS . 'view' . DS . 'email' . DS . 'staff' . DS . 'staffApprove.volt',
        'staff_reject' => 'core' . DS . 'view' . DS . 'email' . DS . 'staff' . DS . 'staffReject.volt',
        'staff_delete' => 'core' . DS . 'view' . DS . 'email' . DS . 'staff' . DS . 'staffDelete.volt',
    ],
    "ini" => [
        "session.cookie_secure" => 1
    ],
    "mongo" => [
        "log_queries" => false
    ],
    "status_app_update" => [
        "status_count" => 15,
        "api_update_interval" => 900,
        "data_stale" => 300
    ],
    "user" => [
        "last_used_passwords" => 3,
    ],
];
