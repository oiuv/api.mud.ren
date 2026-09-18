<?php

return [
    'default' => env('MAIL_MAILER', env('MAIL_DRIVER', 'smtp')),
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME', env('MAIL_ENCRYPTION') === 'ssl' ? 'smtps' : 'smtp'),
            'require_tls' => env('MAIL_REQUIRE_TLS', env('MAIL_ENCRYPTION', 'tls') === 'tls'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 587),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => 15,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],
        'directmail' => ['transport' => 'directmail'],
        'sendmail' => ['transport' => 'sendmail', 'path' => '/usr/sbin/sendmail -bs'],
        'log' => ['transport' => 'log', 'channel' => env('MAIL_LOG_CHANNEL')],
        'array' => ['transport' => 'array'],
    ],
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('MAIL_FROM_USER', 'Example')),
    ],
    'markdown' => [
        'theme' => 'default',
        'paths' => [resource_path('views/vendor/mail')],
    ],
];
