<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'leadlovers' => [
        'base_url' => env('LEADLOVERS_BASE_URL', 'https://llapi.leadlovers.com/webapi/'),
        'email' => env('LEADLOVERS_EMAIL'),
        'token' => env('LEADLOVERS_TOKEN'),
        'machine' => env('LEADLOVERS_MACHINE'),
        'sequence_1' => env('LEADLOVERS_SEQUENCE'),
        'sequence_2' => env('LEADLOVERS_SEQUENCE_LOCATARIO'),
        'step' => env('LEADLOVERS_STEP'),
        
],

    'pottencial' => [
        'base_url' => env('POTTENCIAL_API_URL', 'https://api.pottencial.com.br'),
        'client_id' => env('POTTENCIAL_CLIENT_ID'),
        'client_secret' => env('POTTENCIAL_CLIENT_SECRET'),

    ]

];
