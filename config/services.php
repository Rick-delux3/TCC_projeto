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
        'real_estate_product_id' => env('POTTENCIAL_REAL_ESTATE_PRODUCT_ID'),
        'broker_document' => env('POTTENCIAL_BROKER_DOCUMENT'),
        'default_beneficiary_document' => env('POTTENCIAL_DEFAULT_BENEFICIARY_DOCUMENT'),
        'default_commission' => (float) env('POTTENCIAL_DEFAULT_COMMISSION', 0.10),
        'commercial_loading_fee' => (float) env('POTTENCIAL_COMMERCIAL_LOADING_FEE', 0.10),
        'default_payment_type' => env('POTTENCIAL_DEFAULT_PAYMENT_TYPE', 'Boleto'),
        'default_installments' => (int) env('POTTENCIAL_DEFAULT_INSTALLMENTS', 1),
        'default_plan_key' => env('POTTENCIAL_DEFAULT_PLAN_KEY', 'traditional'),
        'default_assistance' => env('POTTENCIAL_DEFAULT_ASSISTANCE', 'Complete'),
        'policy_owner_document' => env('POTTENCIAL_POLICY_OWNER_DOCUMENT'),
        'default_multiple' => env('POTTENCIAL_DEFAULT_MULTIPLE', 30),
        'default_lease_months' => env('POTTENCIAL_DEFAULT_LEASE_MONTHS', 30),
        'default_policy_type' => env('POTTENCIAL_DEFAULT_POLICY_TYPE', 'Unique'),



    ],

    'porto' => [
        'base_url' => env('PORTO_BASE_URL'),
        'client_id' => env('PORTO_CLIENT_ID'),
        'client_secret' => env('PORTO_CLIENT_SECRET'),
        'env' => env('PORTO_ENV', 'sandbox')
    ]


];
