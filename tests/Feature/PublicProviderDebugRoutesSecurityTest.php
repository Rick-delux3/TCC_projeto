<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['features.insurance_analysis.enabled' => true]);

    Cache::forget('pottencial_access_token');
    Cache::forget('too_access_token');

    Http::preventStrayRequests();
});

it('does not expose the Pottencial authentication diagnostic over HTTP', function () {
    config([
        'services.pottencial.enabled' => true,
        'services.pottencial.base_url' => 'https://pottencial-security.test',
        'services.pottencial.client_id' => 'security-client-id',
        'services.pottencial.client_secret' => 'security-client-secret',
    ]);

    Http::fake([
        'https://pottencial-security.test/oauth/v3/access-token' => Http::response([
            'access_token' => 'pottencial-sensitive-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->get('/teste/token_acesso')->assertNotFound();

    Http::assertNothingSent();
});

it('does not expose the Too authentication diagnostic over HTTP', function () {
    config([
        'services.too.enabled' => true,
        'services.too.base_url' => 'https://too-security.test',
        'services.too.client_id' => 'security-client-id',
        'services.too.client_secret' => 'security-client-secret',
    ]);

    Http::fake([
        'https://too-security.test/authentication' => Http::response([
            'access_token' => 'too-sensitive-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
    ]);

    $this->get('/debug/too/auth')->assertNotFound();

    Http::assertNothingSent();
});
