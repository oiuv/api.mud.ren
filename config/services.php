<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Stripe, Mailgun, SparkPost and others. This file provides a sane
    | default location for this type of information, allowing packages
    | to have a conventional place to find your various credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
    ],

    'ses' => [
        'key' => env('SES_KEY'),
        'secret' => env('SES_SECRET'),
        'region' => env('SES_REGION', 'us-east-1'),
    ],

    'sparkpost' => [
        'secret' => env('SPARKPOST_SECRET'),
    ],

    'stripe' => [
        'model' => App\User::class,
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
    ],

    'directmail' => [
        'key' => env('ALIYUN_ACCESS_KEY_ID'),
        'secret' => env('ALIYUN_ACCESS_KEY_SECRET'),
        'region_id' => env('ALIYUN_REGION_ID', 'cn-hangzhou'),
        'from_address' => env('ALIYUN_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
        'from_alias' => env('ALIYUN_FROM_ALIAS', env('MAIL_FROM_USER')),
    ],

    'captcha' => [
        'publish' => [
            'aid' => env('CAPTCHA_ID_PUBLISH'),
            'secret' => env('CAPTCHA_SECRET_PUBLISH'),
        ],
        'register' => [
            'aid' => env('CAPTCHA_ID_REGISTER'),
            'secret' => env('CAPTCHA_SECRET_REGISTER'),
        ],
    ],
];
