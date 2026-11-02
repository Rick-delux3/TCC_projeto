<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.porto.token_url' => 'https://porto.example.test/oauth/v2/access-token',
        'services.porto.client_id' => 'test-client',
        'services.porto.client_secret' => 'test-secret']);
});

it('requests a token using basic authentication and form encoding', function (bool $show) {
    Http::fake(['*' => Http::response(['access_token' => 'test-token', 'token_type' => 'Bearer', 'expires_in' => 3600])]);

    expect(Artisan::call('porto:token', $show ? ['--show' => true] : []))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('3600 segundos')->not->toContain('test-secret');
    expect(str_contains($output, 'test-token'))->toBe($show);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://porto.example.test/oauth/v2/access-token'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-client:test-secret'))
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
        && $request->data() === ['grant_type' => 'client_credentials']);
})->with([false, true]);

it('handles authentication errors without exposing response secrets', function (int $status) {
    Http::fake(['*' => Http::response(['error' => 'test-secret'], $status)]);
    expect(Artisan::call('porto:token'))->toBe(1);
    expect(Artisan::output())->toContain('HTTP: '.$status)->not->toContain('test-secret');
    Http::assertSentCount(1);
})->with([302, 400, 401, 429, 500]);

it('rejects incomplete token responses', function (array $response) {
    Http::fake(['*' => Http::response($response)]);
    expect(Artisan::call('porto:token'))->toBe(1);
})->with([
    [[]],
    [['access_token' => 'token', 'token_type' => 'Basic', 'expires_in' => 3600]],
    [['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 0]],
]);

it('does not send requests with missing credentials', function () {
    config(['services.porto.client_secret' => '']);
    expect(Artisan::call('porto:token'))->toBe(1);
    Http::assertNothingSent();
});

it('blocks token display in production', function () {
    app()->instance('env', 'production');
    expect(Artisan::call('porto:token', ['--show' => true]))->toBe(1);
    Http::assertNothingSent();
});

it('handles connection failures', function () {
    Http::fake(['*' => Http::failedConnection()]);
    expect(Artisan::call('porto:token'))->toBe(1);
    expect(Artisan::output())->toContain('DNS');
});
