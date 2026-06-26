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

    'cpf_lookup' => [
        'enabled' => env('CPF_LOOKUP_PROVIDER', false),
        'provider' => env('CPF_LOOKUP_PROVIDER', 'cpfhub'),

        'cpfhub' => [
            'base_url' => env('CPFHUB_BASE_URL', 'https://api.cpfhub.io'),
            'api_key' => env('CPFHUB_API_KEY'),
        ],

        'cache_minutes' => env('CPF_LOOKUP_CACHE_MINUTES', 10080),
        'fallback_birthdate' => env('CPF_LOOKUP_FALLBACK_BIRTHDATE', '1985/12/10'),
        'fail_analysis_if_missing_birthdate' => env('CPF_LOOKUP_FAILS_ANALYSIS', false),
    ],

    'leadlovers' => [
        'base_url' => env('LEADLOVERS_BASE_URL', 'https://llapi.leadlovers.com/webapi/'),
        'email' => env('LEADLOVERS_EMAIL'),
        'token' => env('LEADLOVERS_TOKEN'),
        'machine' => env('LEADLOVERS_MACHINE'),
        'sequence_1' => env('LEADLOVERS_SEQUENCE'),
        'sequence_2' => env('LEADLOVERS_SEQUENCE_LOCATARIO'),
        'step' => env('LEADLOVERS_STEP'),
        'sync_max_pages' => env('SYNC_MAX_PAGES'),
        'sync_page_size' => env('SYNC_PAGE_SIZE'),
        'sync_max_imported_leads' => env('SYNC_MAX_IMPORTED_LEADS'),
        'sync_max_scanned_leads' => env('SYNC_MAX_SCANNED_LEADS'),
        'dynamic_fields' => [
            env('DYNAMIC_FIELD_CPF_LEAD')
        ]

        
    ],

    'pottencial' => [
        'base_url' => env('POTTENCIAL_API_URL', 'https://api-sandbox.pottencial.com.br'),
        'rental_endpoint' => env('POTTENCIAL_RENTAL_GUARANTEE_ENDPOINT', '/insurance/v1/fianca-locaticia-mensalizado-pf/quotes'),
        'client_id' => env('POTTENCIAL_CLIENT_ID'),
        'client_secret' => env('POTTENCIAL_CLIENT_SECRET'),
        'real_estate_product_id' => env('POTTENCIAL_REAL_ESTATE_PRODUCT_ID'),
        'broker_document' => env('POTTENCIAL_BROKER_DOCUMENT'),
        'default_beneficiary_document' => env('POTTENCIAL_DEFAULT_BENEFICIARY_DOCUMENT'),
        'default_commission' => (float) env('POTTENCIAL_DEFAULT_COMMISSION', 0.15),
        'commercial_loading_fee' => (float) env('POTTENCIAL_COMMERCIAL_LOADING_FEE', 0.10),
        'default_payment_type' => env('POTTENCIAL_DEFAULT_PAYMENT_TYPE', 'Boleto'),
        'default_installments' => (int) env('POTTENCIAL_DEFAULT_INSTALLMENTS', 1),
        'default_plan_key' => env('POTTENCIAL_DEFAULT_PLAN_KEY', 'traditional'),
        'default_assistance' => env('POTTENCIAL_DEFAULT_ASSISTANCE', 'Complete'),
        'policy_owner_document' => env('POTTENCIAL_POLICY_OWNER_DOCUMENT'),
        'default_multiple' => env('POTTENCIAL_DEFAULT_MULTIPLE', 30),
        'default_lease_months' => env('POTTENCIAL_DEFAULT_LEASE_MONTHS', 30),
        'default_policy_type' => env('POTTENCIAL_DEFAULT_POLICY_TYPE', 'Unico'),



    ],

    'too' =>[
        'base_url' => env('TOO_BASE_URL', 'https://openapi-uat.tooseguros.com.br'),
        'client_id' => env('TOO_CLIENT_ID'),
        'client_secret' => env('TOO_CLIENT_SECRET'),
        'broker_cnpj' => env('TOO_BROKER_CNPJ'),
        'broker_name' => env('TOO_BROKER_NAME'),
        'default_rental_purpose' => env('TOO_DEFAULT_RENTAL_PURPOSE', 'Residencial'),
        'default_rent_adjustment_index' => env('TOO_DEFAULT_RENT_ADJUSTMENT_INDEX', 'IPCA'),
        'default_indemnity_period' => env('TOO_DEFAULT_INDEMNITY_PERIOD', 30),
        'default_commission_percentage' => env('TOO_DEFAULT_COMMISSION_PERCENTAGE', 0.10),
        'default_birthdate' => env('TOO_DEFAULT_BIRTHDATE', '1985/12/10'),
        'default_monthly_income' => env('TOO_DEFAULT_MONTHLY_INCOME', 12500.24),
        'default_employment' => env('TOO_DEFAULT_EMPLOYMENT', 'Clt'),
        'default_profession' => env('TOO_DEFAULT_PROFESSION', 'Analista'),
        'default_reside_property' => env('TOO_DEFAULT_RESIDE_PROPERTY', true),
        'default_financial_responsible' => env('TOO_DEFAULT_FINANCIAL_RESPONSIBLE', true),
    ],

    'porto' => [
        'base_url' => env('PORTO_BASE_URL'),
        'client_id' => env('PORTO_CLIENT_ID'),
        'client_secret' => env('PORTO_CLIENT_SECRET'),
        'env' => env('PORTO_ENV', 'sandbox')
    ]


];
