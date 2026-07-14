<?php

return [
    'ceo_registration_enabled' => env('CEO_REGISTRATION_ENABLED', false),
    'ceo_registration_secret' => env('CEO_REGISTRATION_SECRET'),
    'ceo_registration_path' => env('CEO_REGISTRATION_PATH', 'admin/setup/ceo'),

    'member_invitation_hours' => (int) env(
        'CORRETOR_INVITATION_EXPIRATION_HOURS',
        48
    ),

    'member_invitation_resend_cooldown_seconds' => (int) env(
        'CORRETOR_INVITATION_RESEND_COOLDOWN_SECONDS',
        60
    ),
];