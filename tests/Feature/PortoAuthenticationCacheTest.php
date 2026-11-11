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

it('keeps the real transport reason of an authentication failure, sanitized', function (Throwable $exception, int $errno, string $reason) {
    Http::fake(['*' => fn () => throw $exception]);

    $result = app(PortoAuthentication::class)->token();

    expect($result)->toMatchArray(['success' => false, 'http_status' => null]);
    expect($result['message'])->toContain($reason)->toContain("cURL {$errno}");
    expect($result['diagnostics'][0]['data'])->toMatchArray(['type' => 'transport_error', 'operation' => 'authentication', 'code' => (string) $errno]);
    expect(json_encode($result['diagnostics']))->not->toContain('test-secret')->not->toContain('test-client')->not->toContain('https://');
})->with([
    'DNS (código só na mensagem)' => [fn () => new Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: porto.example.test for https://porto.example.test/oauth/v2/access-token'), 6, 'Falha de DNS'],
    'tempo limite (ConnectException)' => [fn () => new Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out', 0,
        new GuzzleHttp\Exception\ConnectException('cURL error 28: Operation timed out', new GuzzleHttp\Psr7\Request('POST', 'https://porto.example.test'), null, ['errno' => 28])), 28, 'Tempo limite esgotado'],
    'certificado (RequestException sem resposta)' => [fn () => new Illuminate\Http\Client\ConnectionException('SSL certificate problem', 0,
        new GuzzleHttp\Exception\RequestException('SSL certificate problem', new GuzzleHttp\Psr7\Request('POST', 'https://porto.example.test'), null, null, ['errno' => 60])), 60, 'Falha de TLS/certificado'],
]);

it('keeps the sanitized OAuth error body of a refused authentication', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_client', 'error_description' => 'Client test-client inválido'], 401)]);

    $result = app(PortoAuthentication::class)->token();

    expect($result)->toMatchArray(['success' => false, 'http_status' => 401]);
    $diagnostics = json_encode($result['diagnostics'], JSON_UNESCAPED_UNICODE);
    expect($diagnostics)->toContain('invalid_client')->toContain('401')->not->toContain('test-client')->not->toContain('test-secret');
});

it('identifies an unexpected exception during authentication', function () {
    Http::fake(['*' => fn () => throw new RuntimeException('falha interna')]);

    $result = app(PortoAuthentication::class)->token();

    expect($result['message'])->toBe('Erro inesperado ao autenticar na Porto (RuntimeException).');
    expect($result['diagnostics'][0]['data'])->toMatchArray(['type' => 'unexpected_error', 'source' => 'RuntimeException', 'message' => 'falha interna']);
});
