<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
    Log::spy();
    config(['services.tokio' => [
        'token_url' => 'https://tokio.example.test/oauth2/v2.0/token',
        'client_id' => 'tokio-test-client',
        'client_secret' => 'tokio-test-secret',
        'scope' => 'api://tokio-test-scope/.default',
        'grant_type' => 'client_credentials',
    ]]);
});

afterEach(function () {
    expect(Artisan::output())->not->toContain('tokio-test-secret', 'tokio-test-client', 'api://tokio-test-scope/.default');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('requests a fresh Tokio token with the documented form and hides it by default', function (string $expirationField) {
    Cache::put('tokio_access_token', 'existing-token', 3600);
    config(['features.insurance_analysis.enabled' => false, 'services.tokio.enabled' => false]);
    Http::fake(function (Request $request, array $options) use ($expirationField) {
        expect($options['connect_timeout'])->toBe(10)
            ->and($options['timeout'])->toBe(30)
            ->and($options['allow_redirects'])->toBeFalse()
            ->and($options['verify'] ?? true)->toBeTrue();

        return Http::response(['access_token' => 'private-tokio-token', 'token_type' => 'Bearer', $expirationField => 3600]);
    });

    expect(Artisan::call('tokio:token'))->toBe(0);
    expect(Artisan::output())->toContain('HTTP: 200', 'gerado com sucesso', '3600 segundos')
        ->not->toContain('private-tokio-token', 'existing-token');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://tokio.example.test/oauth2/v2.0/token'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
        && $request->hasHeader('Accept', 'application/json')
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('codigoCorretor')
        && $request->data() === [
            'client_id' => 'tokio-test-client',
            'client_secret' => 'tokio-test-secret',
            'scope' => 'api://tokio-test-scope/.default',
            'grant_type' => 'client_credentials',
        ]);
    expect(Cache::get('tokio_access_token'))->toBe('existing-token');
})->with(['expire_in', 'expires_in']);

it('shows the complete token only when requested and does not invent expiration', function () {
    Http::fake(['*' => Http::response(['access_token' => 'private-tokio-token', 'ext_expires_in' => 7200])]);

    expect(Artisan::call('tokio:token', ['--show' => true]))->toBe(0);
    expect(Artisan::output())->toContain('private-tokio-token', 'Validade informada: não informada');
});

it('blocks token disclosure in production before sending a request', function () {
    $this->app->instance('env', 'production');

    expect(Artisan::call('tokio:token', ['--show' => true]))->toBe(1);
    expect(Artisan::output())->toContain('não está disponível em produção');
    Http::assertNothingSent();
});

it('rejects invalid Tokio configuration without sending credentials', function (string $key, mixed $value) {
    config(["services.tokio.{$key}" => $value]);

    expect(Artisan::call('tokio:token'))->toBe(1);
    Http::assertNothingSent();
})->with([
    'client id' => ['client_id', null],
    'secret' => ['client_secret', ''],
    'scope' => ['scope', '  '],
    'grant' => ['grant_type', 'password'],
    'insecure url' => ['token_url', 'http://tokio.example.test/token'],
    'url credentials' => ['token_url', 'https://tokio-test-client:tokio-test-secret@tokio.example.test/token'],
]);

it('handles HTTP errors without exposing the body or following redirects', function (int $status) {
    Http::fake(['*' => Http::response([
        'error_description' => 'tokio-test-secret private-tokio-token',
    ], $status, ['Location' => 'https://other.example.test/token'])]);

    expect(Artisan::call('tokio:token', ['--show' => true]))->toBe(1);
    expect(Artisan::output())->toContain("HTTP: {$status}")->not->toContain('private-tokio-token');
    Http::assertSentCount(1);
})->with([302, 400, 401, 403, 429, 500]);

it('rejects successful responses that do not contain a usable token', function (mixed $body) {
    Http::fake(['*' => Http::response($body)]);

    expect(Artisan::call('tokio:token', ['--show' => true]))->toBe(1);
    expect(Artisan::output())->toContain('Resposta inválida')->not->toContain('private-tokio-token');
})->with([
    'html' => '<html>private-tokio-token</html>',
    'missing' => [[]],
    'empty' => [['access_token' => '  ']],
    'array token' => [['access_token' => ['private-tokio-token']]],
    'invalid type' => [['access_token' => 'private-tokio-token', 'token_type' => 'Basic']],
    'whitespace' => [['access_token' => "private-tokio-token\r\n"]],
]);

it('handles connection errors without exposing secrets or retrying', function () {
    Http::fake(['*' => Http::failedConnection('tokio-test-secret private-tokio-token')]);

    expect(Artisan::call('tokio:token'))->toBe(1);
    expect(Artisan::output())->toContain('Falha de conexão', 'HTTP: sem resposta')
        ->not->toContain('private-tokio-token');
});
