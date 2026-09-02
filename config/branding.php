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
            'logo_header' => 'imgs/Logo_NVS.png',
            'logo_email' => 'imgs/Logo_NVS.png',
            'favicon' => 'imgs/Logo_NVS.png',
            'favicon_type' => 'image/png',
            'colors' => [
                'primary' => '#030133',
                'primary_dark' => '#030133',
                'primary_hover' => '#146FB6',
                'primary_soft' => '#EEF6FF',
                'blue' => '#146FB6',
                'accent' => '#FD1E6E',
                'accent_hover' => '#D9185D',
                'accent_dark' => '#A80F45',
                'accent_soft' => '#FFF0F6',
                'accent_border' => '#F5B4CB',
                'background' => '#F0F5FB',
                'surface' => '#FFFFFF',
                'text' => '#1F2937',
                'text_muted' => '#55658C',
                'border' => '#D8E1EC',
            ],
        ],

        'client' => [
            'key' => 'client',
            'name' => 'Aki Aluga',
            'short_name' => 'Aki Aluga',
            'logo' => 'imgs/logo-akialuga.jpg',
            'logo_header' => 'imgs/logo-header.jpg',
            'logo_email' => 'imgs/logo-akialuga.jpg',
            'favicon' => 'imgs/logo-akialuga.jpg',
            'favicon_type' => 'image/jpeg',
            'colors' => [
                'primary' => '#00288F',
                'primary_dark' => '#001650',
                'primary_hover' => '#001F73',
                'primary_soft' => '#EDF3FF',
                'blue' => '#00288F',
                'accent' => '#E6000B',
                'accent_hover' => '#C90009',
                'accent_dark' => '#A60007',
                'accent_soft' => '#FFF0F1',
                'accent_border' => '#F1B6BB',
                'background' => '#F3F6FC',
                'surface' => '#FFFFFF',
                'text' => '#14213D',
                'text_muted' => '#53617A',
                'border' => '#D5DEEB',
            ],
        ],
    ],
];
