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
        'enabled' => env('CPF_LOOKUP_ENABLED', false),
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
        'enabled' => env('LEADLOVERS_ENABLED', false),
        'base_url' => env('LEADLOVERS_BASE_URL', 'https://llapi.leadlovers.com/webapi/'),
        'api_url' => env('LEADLOVERS_API_URL', 'https://api.leadlovers.com'),
        'email' => env('LEADLOVERS_EMAIL'),
        'token' => env('LEADLOVERS_TOKEN'),
        'machine' => env('LEADLOVERS_MACHINE'),
        'sequence_1' => env('LEADLOVERS_SEQUENCE'),
        'sequence_2' => env('LEADLOVERS_SEQUENCE_LOCATARIO'),
        'step' => env('LEADLOVERS_STEP'),
        'rate_limit_retry_seconds' => env('LEADLOVERS_RATE_LIMIT_RETRY_SECONDS', 60),
        'rate_limit_max_retry_seconds' => env('LEADLOVERS_RATE_LIMIT_MAX_RETRY_SECONDS', 900),
        'requests_per_minute' => (int) env('LEADLOVERS_REQUESTS_PER_MINUTE', 90),
        'rate_limit_window_seconds' => (int) env('LEADLOVERS_RATE_LIMIT_WINDOW_SECONDS', 60),
        'initial_update_delay_seconds' => (int) env('LEADLOVERS_INITIAL_UPDATE_DELAY_SECONDS', 60),
        'machine_confirmation_delay_seconds' => (int) env('LEADLOVERS_MACHINE_CONFIRMATION_DELAY_SECONDS', 15),
        'tag_confirmation_delay_seconds' => (int) env('LEADLOVERS_TAG_CONFIRMATION_DELAY_SECONDS', 15),
        'tag_uncertain_retry_checks' => (int) env('LEADLOVERS_TAG_UNCERTAIN_RETRY_CHECKS', 2),
        'tag_max_post_attempts' => (int) env('LEADLOVERS_TAG_MAX_POST_ATTEMPTS', 2),
        'tag_posting_stale_seconds' => (int) env('LEADLOVERS_TAG_POSTING_STALE_SECONDS', 60),
        'dynamic_fields' => [
            'cpf' => env('LEADLOVERS_FIELD_CPF'),
            'estado_civil' => env('LEADLOVERS_FIELD_ESTADO_CIVIL'),
            'conjuge_cpf' => env('LEADLOVERS_FIELD_CONJUGE_CPF'),
            'valor_aluguel' => env('LEADLOVERS_FIELD_VALOR_ALUGUEL'),
            'valor_agua' => env('LEADLOVERS_FIELD_VALOR_AGUA'),
            'valor_luz' => env('LEADLOVERS_FIELD_VALOR_LUZ'),
            'valor_gas' => env('LEADLOVERS_FIELD_VALOR_GAS'),
            'valor_condominio' => env('LEADLOVERS_FIELD_VALOR_CONDOMINIO'),
            'valor_iptu' => env('LEADLOVERS_FIELD_VALOR_IPTU'),
            'outras_despesas' => env('LEADLOVERS_FIELD_OUTRAS_DESPESAS'),
        ],

    ],

    'pottencial' => [
        'enabled' => env('POTTENCIAL_ENABLED', false),
        'base_url' => env('POTTENCIAL_API_URL', 'https://api-hml.pottencial.com.br'),
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

    'too' => [
        'enabled' => env('TOO_ENABLED', false),
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
        'status_check_delay_seconds' => env('TOO_STATUS_CHECK_DELAY_SECONDS', 20),
        'status_check_max_attempts' => env('TOO_STATUS_CHECK_MAX_ATTEMPTS', 15),
        'default_reanalysis_reason' => env('TOO_DEFAULT_REANALYSIS_REASON', 10),
    ],

    'porto' => [
        'enabled' => env('PORTO_ENABLED', false),
        'base_url' => env('PORTO_BASE_URL'),
        'client_id' => env('PORTO_CLIENT_ID'),
        'client_secret' => env('PORTO_CLIENT_SECRET'),
        'env' => env('PORTO_ENV', 'sandbox'),
    ],

];
