<?php

$requestedProfile = env('BRAND_PROFILE', 'tcc');
$availableProfiles = ['tcc', 'client'];

$activeProfile = in_array($requestedProfile, $availableProfiles, true)
    ? $requestedProfile
    : 'tcc';

return [
    'active' => $activeProfile,

    'profiles' => [
        'tcc' => [
            'key' => 'tcc',
            'name' => 'NVS Seguros',
            'short_name' => 'NVS',
            'logo' => 'imgs/Logo_NVS.png',
        ],

        'client' => [
            'key' => 'client',
            'name' => 'Aki Aluga',
            'short_name' => 'Aki Aluga',
            'logo' => 'imgs/logo-principal-real.jpg',
        ],
    ],
];
