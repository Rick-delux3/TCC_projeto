<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
    Log::spy();
    config(['features.insurance_analysis.enabled' => false]);
    foreach (['pottencial', 'too'] as $provider) {
        config([
            "services.{$provider}.base_url" => "https://{$provider}.example.test",
            "services.{$provider}.client_id" => 'test-client-id',
            "services.{$provider}.client_secret" => 'test-client-secret',
            "services.{$provider}.enabled" => false,
        ]);
    }
});

afterEach(function () {
    expect(Artisan::output())->not->toContain('test-client-secret', 'test-client-id', 'private-access-token', 'cached-token');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('checks the token endpoint directly without modifying the application token cache', function (string $provider) {
    Cache::put("{$provider}_access_token", 'cached-token', 3600);
    Http::fake(['*' => Http::response(['access_token' => 'private-access-token'], 200)]);

    expect(Artisan::call("{$provider}:test-api"))->toBe(0);
    expect(Artisan::output())->toContain('Autenticação confirmada', 'HTTP: 200');
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($provider): bool {
        $endpoint = $provider === 'too' ? '/authentication' : '/oauth/v3/access-token';

        return $request->method() === 'POST'
            && $request->url() === "https://{$provider}.example.test{$endpoint}"
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-client-id:test-client-secret'))
            && ($provider !== 'too' || ($request['grant_type'] === 'client_credentials'
                && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')));
    });
    expect(Cache::get("{$provider}_access_token"))->toBe('cached-token');
})->with(['pottencial', 'too']);

it('supports the Too headers contract without mixing authentication formats', function () {
    Http::fake(['*' => Http::response(['access_token' => 'private-access-token'], 200)]);
    expect(Artisan::call('too:test-api', ['--auth' => 'headers']))->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://too.example.test/authentication'
        && $request->hasHeader('clientid', 'test-client-id')
        && $request->hasHeader('clientsecret', 'test-client-secret')
        && ! $request->hasHeader('Authorization')
        && ! isset($request['grant_type']));
});

it('rejects unsupported auth modes before contacting Too', function () {
    expect(Artisan::call('too:test-api', ['--auth' => 'unknown']))->toBe(2);
    Http::assertNothingSent();
});

it('reports HTTP failures without disclosing response bodies', function (string $provider, int $status) {
    Http::fake(['*' => Http::response(['error' => 'test-client-secret private-access-token'], $status, ['Location' => 'https://other.example.test'])]);
    expect(Artisan::call("{$provider}:test-api"))->toBe(1);
    expect(Artisan::output())->toContain("HTTP: {$status}");
    Http::assertSentCount(1);
})->with(['pottencial', 'too'])->with([302, 401, 403, 429, 500]);

it('does not confuse a successful HTTP response with a usable token', function (string $provider, mixed $body) {
    Http::fake(['*' => Http::response($body, 200)]);
    expect(Artisan::call("{$provider}:test-api"))->toBe(1);
    expect(Artisan::output())->toContain('Resposta inválida');
})->with(['pottencial', 'too'])->with([
    'html' => '<html>private-access-token</html>',
    'missing' => [[]],
    'empty' => [['access_token' => '  ']],
    'wrong type' => [['access_token' => ['private-access-token']]],
]);

it('handles connection failures without leaking exception details', function (string $provider) {
    Http::fake(['*' => Http::failedConnection('test-client-secret private-access-token')]);
    expect(Artisan::call("{$provider}:test-api"))->toBe(1);
    expect(Artisan::output())->toContain('Falha de conexão', 'sem resposta');
})->with(['pottencial', 'too']);

it('fails locally for incomplete or unsafe configuration', function (string $provider, string $key, mixed $value) {
    config(["services.{$provider}.{$key}" => $value]);
    expect(Artisan::call("{$provider}:test-api"))->toBe(1);
    Http::assertNothingSent();
})->with(['pottencial', 'too'])->with([
    'missing client id' => ['client_id', null],
    'missing client secret' => ['client_secret', ''],
    'missing URL' => ['base_url', ''],
    'plaintext URL' => ['base_url', 'http://api.example.test'],
    'credentials in URL' => ['base_url', 'https://test-client-id:test-client-secret@api.example.test'],
]);

it('keeps support for Too token aliases already accepted by its service', function (string $field) {
    Http::fake(['*' => Http::response([$field => 'private-access-token'], 200)]);
    expect(Artisan::call('too:test-api'))->toBe(0);
})->with(['accessToken', 'token']);

it('keeps verification and bounded timeouts enabled without following redirects', function () {
    Http::fake(function (Request $request, array $options) {
        expect($options['connect_timeout'])->toBe(10)
            ->and($options['timeout'])->toBe(30)
            ->and($options['allow_redirects'])->toBeFalse()
            ->and($options['verify'] ?? true)->toBeTrue();

        return Http::response(['access_token' => 'private-access-token']);
    });
    expect(Artisan::call('too:test-api'))->toBe(0);
});
