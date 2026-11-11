<?php

return [
    'cpf_lookup' => ['enabled' => (bool) env('CPF_LOOKUP_ENABLED', false)],
    'insurance_analysis' => [
        'enabled' => env('INSURANCE_ANALYSIS_ENABLED', false),
    ],

    'public_index_enabled' => (bool) env('PUBLIC_INDEX_ENABLED', true),

];
