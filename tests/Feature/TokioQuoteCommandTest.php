<?php

use App\Rules\CpfOrCnpj;
use App\Services\Insurance\TokioQuoteDiagnostic;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Log::spy();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-27 12:00:00', 'America/Sao_Paulo'));
    config(['services.tokio' => [
        'token_url' => 'https://tokio-auth.example.test/token',
        'client_id' => 'diagnostic-client',
        'client_secret' => 'diagnostic-secret',
        'scope' => 'api://diagnostic/.default',
        'grant_type' => 'client_credentials',
        'base_url_w' => dirname(TokioQuoteDiagnostic::ENDPOINT),
        'codigo_corretor' => '12345',
        'cpf_usuario' => '111.444.777-35',
        'senha_operadora' => 'primary-password',
        'support' => [
            'codigo_corretor' => '54321',
            'cpf_usuario' => '52998224725',
            'senha_operadora' => 'support-password',
        ],
    ]]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Queue::assertNothingPushed();
    expect(Artisan::output())->not->toContain('oauth-private-token', 'quote-private-token', 'primary-password', 'support-password', 'diagnostic-secret');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('authenticates and sends the approval fixture with each homologated CPF', function (int $index, string $cpf) {
    Http::fake([
        'https://tokio-auth.example.test/token' => Http::response(['access_token' => 'oauth-private-token']),
        TokioQuoteDiagnostic::ENDPOINT => function (Request $request, array $options) {
            expect($options['connect_timeout'])->toBe(10)
                ->and($options['timeout'])->toBe(60)
                ->and($options['allow_redirects'])->toBeFalse()
                ->and($options['verify'] ?? true)->toBeTrue();

            return Http::response([
                'cotacaoInfo' => ['sqCdCotac' => 1234, 'cdSitucCotac' => 'CAL', 'token' => 'quote-private-token'],
                'mensagens' => ['success' => ['Dados do imóvel salvos com sucesso.', 'Cadastro aprovado.']],
                'calculos' => ['N' => ['opcoesPagamento' => (object) []]],
            ]);
        },
    ]);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'aprovada', '--cpf-aprovado' => $index]))->toBe(0);
    $output = Artisan::output();
    expect($output)->not->toContain('oauth-private-token', 'quote-private-token', 'primary-password', 'support-password', 'diagnostic-secret');
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($report['success'])->toBeTrue()
        ->and($report['fallback_used'])->toBeFalse()
        ->and($report['attempts'][0]['response_body']['mensagens']['success'])->toContain('Cadastro aprovado.')
        ->and($report['attempts'][0]['response_body']['cotacaoInfo']['token'])->toBe('[OCULTO]')
        ->and($report['request_body']['pretendentes'][0]['cpf'])->toBe($cpf);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === TokioQuoteDiagnostic::ENDPOINT
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer oauth-private-token')
        && $request->hasHeader('codigoCorretor', '12345')
        && $request->hasHeader('cpfUsuario', '11144477735')
        && $request->hasHeader('senhaOperadora', 'primary-password')
        && $request->hasHeader('Content-Type', 'application/json')
        && ! $request->hasHeader('token')
        && ! $request->hasHeader('sqCdCotac')
        && $request['coberturas']['vlAluguel'] == 400
        && $request['pretendentes'][0]['cpf'] === $cpf
        && $request['tpPessoa'] === 'F'
        && $request['icResidencial'] === 'S'
        && $request['dadosLocacao']['inicioVigenciaSeguro'] === '27/10/2026'
        && $request['dadosLocacao']['fimVigenciaSeguro'] === '27/10/2027'
        && $request['dadosLocacao']['assistenciaDesejada'] === '26206'
        && $request['pcComissao'] === 15);
    expect(json_decode($output)->attempts[0]->response_body->calculos->N->opcoesPagamento)->toBeInstanceOf(stdClass::class);
})->with([
    [1, '09238037698'],
    [2, '38329560856'],
    [3, '38566978889'],
]);

it('generates a valid non-homologated CPF without forcing the requested outcome', function (string $scenario) {
    Http::fake([
        'https://tokio-auth.example.test/token' => Http::response(['access_token' => 'oauth-private-token']),
        TokioQuoteDiagnostic::ENDPOINT => Http::response(['mensagens' => ['warning' => ['Encaminhado para análise.']]]),
    ]);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => $scenario]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $cpf = $report['request_body']['pretendentes'][0]['cpf'];
    expect($report['scenario'])->toBe($scenario)
        ->and(Validator::make(['cpf' => $cpf], ['cpf' => [new CpfOrCnpj]])->passes())->toBeTrue()
        ->and(in_array($cpf, TokioQuoteDiagnostic::APPROVED_CPFS, true))->toBeFalse()
        ->and($report['attempts'][0]['response_body']['mensagens']['warning'])->toBe(['Encaminhado para análise.'])
        ->and($report)->not->toHaveKey('approved');
    Http::assertSentCount(2);
})->with(['recusada', 'encaminhada']);

it('preserves refusal in the encaminhada scenario without creating additional quotations', function () {
    $response = [
        'cotacaoInfo' => ['cdSitucCotac' => 'REC'],
        'mensagens' => [
            'success' => ['Dados do imóvel salvos com sucesso.'],
            'danger' => ['Cadastro Reprovado não passível de reanálise.', 'Análise cadastral não aprovada.'],
        ],
        'calculos' => (object) [],
    ];
    Http::fake([
        'https://tokio-auth.example.test/token' => Http::response(['access_token' => 'oauth-private-token']),
        TokioQuoteDiagnostic::ENDPOINT => Http::response($response),
    ]);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'encaminhada']))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['scenario'])->toBe('encaminhada')
        ->and($report['fallback_used'])->toBeFalse()
        ->and($report['attempts'])->toHaveCount(1)
        ->and($report['attempts'][0]['response_body']['cotacaoInfo']['cdSitucCotac'])->toBe('REC')
        ->and($report['attempts'][0]['response_body']['mensagens'])->toBe($response['mensagens']);
    Http::assertSentCount(2);
});

it('uses the complete support header set when primary credentials are missing', function () {
    config(['services.tokio.cpf_usuario' => null]);
    Http::fake([
        'https://tokio-auth.example.test/token' => Http::response(['access_token' => 'oauth-private-token']),
        TokioQuoteDiagnostic::ENDPOINT => Http::response(['mensagens' => []]),
    ]);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'aprovada']))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['fallback_used'])->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === TokioQuoteDiagnostic::ENDPOINT
        && $request->hasHeader('codigoCorretor', '54321')
        && $request->hasHeader('cpfUsuario', '52998224725')
        && $request->hasHeader('senhaOperadora', 'support-password'));
});

it('falls back once after credential rejection preserving the exact quotation body', function (int $status) {
    Http::fake([
        'https://tokio-auth.example.test/token' => Http::response(['access_token' => 'oauth-private-token']),
        TokioQuoteDiagnostic::ENDPOINT => Http::sequence()->push(['error' => 'not authorized'], $status)->push(['mensagens' => []]),
    ]);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'recusada']))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['fallback_used'])->toBeTrue()
        ->and(array_column($report['attempts'], 'http_status'))->toBe([$status, 200]);
    Http::assertSentCount(3);
    $requests = Http::recorded(fn (Request $request): bool => $request->url() === TokioQuoteDiagnostic::ENDPOINT)->values();
    expect($requests[0][0]->data())->toBe($requests[1][0]->data());
    expect($requests[1][0]->header('senhaOperadora'))->toBe(['support-password']);
})->with([401, 403]);

it('does not retry HTTP responses unrelated to credential rejection', function (int $status) {
    Http::fake([
        'https://tokio-auth.example.test/token' => Http::response(['access_token' => 'oauth-private-token']),
        TokioQuoteDiagnostic::ENDPOINT => Http::response(['mensagens' => ['danger' => ['Resposta de teste.']]], $status, ['Location' => 'https://other.example.test']),
    ]);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'recusada']))->toBe($status < 300 ? 0 : 1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['fallback_used'])->toBeFalse()
        ->and($report['attempts'][0]['http_status'])->toBe($status);
    Http::assertSentCount(2);
})->with([200, 202, 302, 400, 422, 429, 500]);

it('does not repeat a timeout or disclose exception details', function () {
    Http::fake([
        'https://tokio-auth.example.test/token' => Http::response(['access_token' => 'oauth-private-token']),
        TokioQuoteDiagnostic::ENDPOINT => Http::failedConnection('oauth-private-token primary-password'),
    ]);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'aprovada']))->toBe(1);
    expect(Artisan::output())->toContain('Resultado remoto desconhecido', 'não foi repetida');
});

it('stops after failed OAuth without trying the old support bearer', function () {
    Http::fake(['https://tokio-auth.example.test/token' => Http::response(['error' => 'diagnostic-secret'], 401)]);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'aprovada']))->toBe(1);
    Http::assertSentCount(1);
    expect(Artisan::output())->toContain('Falha ao obter novo token OAuth');
});

it('stops after support credentials are also rejected', function () {
    Http::fake([
        'https://tokio-auth.example.test/token' => Http::response(['access_token' => 'oauth-private-token']),
        TokioQuoteDiagnostic::ENDPOINT => Http::response(['error' => 'not authorized'], 403),
    ]);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'aprovada']))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['attempts'])->toHaveCount(2);
    Http::assertSentCount(3);
});

it('does not repeat authentication rejection when both header sets are identical', function () {
    config(['services.tokio.support' => [
        'codigo_corretor' => '12345',
        'cpf_usuario' => '11144477735',
        'senha_operadora' => 'primary-password',
    ]]);
    Http::fake([
        'https://tokio-auth.example.test/token' => Http::response(['access_token' => 'oauth-private-token']),
        TokioQuoteDiagnostic::ENDPOINT => Http::response(['error' => 'not authorized'], 401),
    ]);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'aprovada']))->toBe(1);
    Http::assertSentCount(2);
});

it('preserves non-JSON responses for diagnosis while redacting known secrets', function () {
    Http::fake([
        'https://tokio-auth.example.test/token' => Http::response(['access_token' => 'oauth-private-token']),
        TokioQuoteDiagnostic::ENDPOINT => Http::response('<html>Gateway primary-password oauth-private-token</html>', 502),
    ]);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'aprovada']))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['attempts'][0]['response_format'])->toBe('text')
        ->and($report['attempts'][0]['response_body'])->toBe('<html>Gateway [OCULTO] [OCULTO]</html>');
});

it('rejects invalid options before any request', function (array $options) {
    expect(Artisan::call('tokio:test-quote', $options))->toBe(2);
    Http::assertNothingSent();
})->with([
    'missing scenario' => [[]],
    'invalid scenario' => [['--cenario' => 'anything']],
    'invalid cpf index' => [['--cenario' => 'aprovada', '--cpf-aprovado' => '4']],
]);

it('stops before authentication when no valid header set exists', function () {
    config(['services.tokio.senha_operadora' => '', 'services.tokio.support.senha_operadora' => '']);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'aprovada']))->toBe(1);
    Http::assertNothingSent();
});

it('restricts quotation to the W acceptance endpoint', function () {
    config(['services.tokio.base_url_w' => 'https://apim.tokiomarine.com.br/massificados/cotador-fianca-service/api']);

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'aprovada']))->toBe(1);
    Http::assertNothingSent();
});

it('does not run the diagnostic in production', function () {
    $this->app->instance('env', 'production');

    expect(Artisan::call('tokio:test-quote', ['--cenario' => 'aprovada']))->toBe(1);
    Http::assertNothingSent();
});
