<?php

use App\Services\Insurance\PortoAuthentication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    config(['services.porto.token_url' => 'https://porto.example.test/oauth/v2/access-token',
        'services.porto.client_id' => 'test-client',
        'services.porto.client_secret' => 'test-secret',
        'services.porto.token_cache_margin_seconds' => 60]);
});

function portoTokenResponse(string $token = 'test-token', int $expiresIn = 3600)
{
    return Http::response(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresIn]);
}

it('reuses the token until it is close to expiring', function () {
    Http::fake(['*' => Http::sequence()->push(['access_token' => 'first-token', 'token_type' => 'Bearer', 'expires_in' => 3600])
        ->push(['access_token' => 'second-token', 'token_type' => 'Bearer', 'expires_in' => 3600])]);
    $authentication = app(PortoAuthentication::class);

    expect($authentication->token())->toMatchArray(['success' => true, 'access_token' => 'first-token']);
    expect($authentication->token())->toMatchArray(['success' => true, 'access_token' => 'first-token']);
    Http::assertSentCount(1);

    $this->travel(3539)->seconds();
    expect($authentication->token()['access_token'])->toBe('first-token');

    $this->travel(2)->seconds();
    expect($authentication->token()['access_token'])->toBe('second-token');
    Http::assertSentCount(2);
});

it('stores the cached token encrypted', function () {
    Http::fake(['*' => portoTokenResponse()]);
    app(PortoAuthentication::class)->token();

    $stored = collect((fn () => $this->storage)->call(Cache::getStore()))->pluck('value')->map(fn ($value) => (string) $value);

    expect($stored)->not->toBeEmpty();
    expect($stored->implode('|'))->not->toContain('test-token')->not->toContain('test-secret');
});

it('does not cache failures or tokens that expire inside the safety margin', function (callable $response) {
    Http::fake(['*' => $response()]);
    $authentication = app(PortoAuthentication::class);

    $authentication->token();
    $authentication->token();

    Http::assertSentCount(2);
})->with([
    'falha de autenticação' => [fn () => Http::response(['error' => 'invalid_client'], 401)],
    'validade dentro da margem' => [fn () => portoTokenResponse('short-token', 60)],
]);

it('requests a new token after the cached one is discarded', function () {
    Http::fake(['*' => Http::sequence()->push(['access_token' => 'first-token', 'token_type' => 'Bearer', 'expires_in' => 3600])
        ->push(['access_token' => 'second-token', 'token_type' => 'Bearer', 'expires_in' => 3600])]);
    $authentication = app(PortoAuthentication::class);

    expect($authentication->token()['access_token'])->toBe('first-token');
    $authentication->forgetToken();
    expect($authentication->token()['access_token'])->toBe('second-token');
});

it('isolates the cached token by credential', function () {
    Http::fake(['*' => Http::sequence()->push(['access_token' => 'first-token', 'token_type' => 'Bearer', 'expires_in' => 3600])
        ->push(['access_token' => 'second-token', 'token_type' => 'Bearer', 'expires_in' => 3600])]);
    $authentication = app(PortoAuthentication::class);

    expect($authentication->token()['access_token'])->toBe('first-token');
    config(['services.porto.client_secret' => 'rotated-secret']);
    expect($authentication->token()['access_token'])->toBe('second-token');
});

it('does not request or cache a token when credentials are missing', function () {
    config(['services.porto.client_secret' => '']);

    expect(app(PortoAuthentication::class)->token()['success'])->toBeFalse();
    Http::assertNothingSent();
});
