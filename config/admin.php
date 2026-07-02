<?php

return [
    'ceo_registration_enabled' => env('CEO_REGISTRATION_ENABLED', false),
    'ceo_registration_secret' => env('CEO_REGISTRATION_SECRET'),
    'ceo_registration_path' => env('CEO_REGISTRATION_PATH', 'admin/setup/ceo'),
];