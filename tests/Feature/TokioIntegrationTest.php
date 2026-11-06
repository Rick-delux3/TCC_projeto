<?php

use App\Models\InsuranceQuote;
use App\Services\Insurance\TokioQuoteResponse;
use App\Services\Insurance\TokioService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('completes Tokio through the quote job and keeps manual handling separate from refusal', function (string $remote, string $expected) {
    \Illuminate\Support\Facades\Queue::fake();
    Http::preventStrayRequests();
    config(['features.insurance_analysis.enabled' => true, 'services.tokio.enabled' => true, 'queue.default' => 'database']);
    $template = tokioBuilderQuote('primary', false, false)->lead;
    $lead = \App\Models\Lead::query()->create(array_merge($template->getAttributes(), ['email' => 'test@example.test']));
    $lead->despesas()->create($template->despesas->getAttributes());
    $lead->endereco()->create($template->endereco->getAttributes());
    if ($remote === 'PJ') {
        $lead->forceFill(['cpf' => '11222333000181', 'tipo_pessoa' => 'J'])->save();
        $lead->conjuge()->create(['nome' => 'Dado antigo', 'cpf' => '38329560856']);
    }
    $batch = \App\Models\InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => 1]);
    $analysis = \App\Models\InsuranceAnalysis::query()->create(['lead_id' => $lead->id, 'insurance_analysis_batch_id' => $batch->id,
        'provider' => 'tokio', 'product' => 'fianca_locaticia_residencial', 'status' => 'pending', 'rent_amount' => 400]);
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'tokio-test']]);
    $coordinator = app(\App\Services\Insurance\InsuranceQuoteCoordinator::class);
    $run = $coordinator->prepare($analysis, 'tokio-test', false);
    $quote = $run->scenarios()->first()->quotes()->first();
    $service = $this->mock(TokioService::class);
    if ($remote === 'PJ') {
        $service->shouldNotReceive('quote');
    } else {
        $service->shouldReceive('quote')->once()->andReturn(['success' => true, 'response' => [
            'cotacaoInfo' => ['sqCdCotac' => 123, 'cdSitucCotac' => $remote, 'token' => 'private-quote-token'],
            'mensagens' => ['success' => ['Dados salvos.'], 'danger' => $remote === 'CAL' ? [] : ['Cadastro recusado.']],
            'calculos' => $remote === 'CAL' ? ['N' => ['opcaoAssistencia' => 'Sem assistência', 'opcoesPagamento' => ['credito' => ['prazo' => [
                '30' => ['qtd' => 30, 'vlPremPar' => 40, 'vlPremTot' => 1200],
            ]]]]] : [],
        ]]);
    }
    $job = new \App\Jobs\RunInsuranceQuoteJob($quote->id, 'tokio-test');
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect($analysis->fresh()->status)->toBe($expected);
    expect($run->fresh()->finished_at)->not->toBeNull();
    expect($run->scenarios()->count())->toBe(1);
    expect(json_encode($analysis->fresh()->response_payload))->not->toContain('private-quote-token');
    if ($remote === 'CAL') {
        expect($quote->fresh()->installment_amount)->toBe('40.00');
        expect(app(\App\Services\Insurance\AnalysisQuoteSummary::class)->summarize($analysis->fresh())['price']['installment_amount'])->toBe('40.00');
    }
    (new \App\Jobs\CompleteInsuranceAnalysesBatchJob($batch->id, 'tokio-test'))->handle();
    expect($batch->fresh()->status)->toBe('completed');
})->with([['CAL', 'approved'], ['REC', 'rejected'], ['OTHER', 'rejected'], ['PJ', 'manual_required']]);

function tokioBuilderQuote(string $kind, bool $commercial = true, bool $spouse = true): InsuranceQuote
{
    $lead = new \App\Models\Lead(['cpf' => '09238037698', 'nome' => 'Pretendente de teste',
        'tipo_locacao' => $commercial ? 'comercial' : 'residencial', 'tipo_solicitante' => 'locatario',
        'ramo_atividade' => 21205, 'estado_civil' => $spouse ? 'casado' : 'solteiro']);
    $lead->setRelation('lead_empresa', null);
    $lead->setRelation('despesas', new \App\Models\LeadDespesas(['valor_aluguel' => 400, 'valor_agua' => 100]));
    $lead->setRelation('endereco', new \App\Models\LeadEnderecos(['cep' => '01001000', 'logradouro' => 'Rua de teste', 'numero' => '10', 'bairro' => 'Centro']));
    $lead->setRelation('conjuge', $kind === 'joint' ? new \App\Models\LeadConjugues(['cpf' => '38329560856', 'nome' => 'Cônjuge de teste']) : null);
    $round = new \App\Models\InsuranceAnalysisRound(['lead_snapshot' => ['tokio' => [
        'has_spouse' => $spouse, 'cnae' => '24465',
        'parameters' => ['assistance' => '26203', 'commission' => 15, 'remuneration' => 0, 'discount' => 0, 'surcharge' => 0],
    ]]]);
    $run = (new \App\Models\InsuranceProviderRun)->setRelation('round', $round);
    $scenario = (new \App\Models\InsuranceApplicantScenario(['kind' => $kind]))->setRelation('providerRun', $run);

    return (new InsuranceQuote(['lease_start_date' => '2026-11-03', 'lease_end_date' => '2029-05-03']))
        ->setRelation('lead', $lead)->setRelation('scenario', $scenario);
}

it('keeps business data separate and retains the original participation in individual attempts', function (string $kind, bool $spouse, int $participation, int $count) {
    $payload = app(\App\Services\Insurance\Payloads\TokioQuotePayloadBuilder::class)->build(tokioBuilderQuote($kind, true, $spouse));
    expect($payload['pretendentes'])->toHaveCount($count);
    expect($payload['pretendentes'][0])->toMatchArray(['empresaConstituida' => 'N', 'CNAE' => 24465, 'capitalInicial' => 450000]);
    expect($payload['pretendentes'][0])->not->toHaveKey('cpf')->not->toHaveKey('cnpj')->not->toHaveKey('dataFundacao');
    foreach (array_slice($payload['pretendentes'], 1) as $person) {
        expect($person)->toMatchArray(['tipoVinculo' => '21147', 'percentualParticipacao' => $participation, 'respondeFinanceiramente' => 'S', 'rendaFixaMensal' => '1600.00']);
    }
    expect($payload['coberturas'])->toBe(['vlAluguel' => 400.0]);
    expect($payload['dadosLocacao'])->not->toHaveKey('pinturaNova');
})->with([['primary', true, 50, 2], ['spouse', true, 50, 2], ['joint', true, 50, 3], ['primary', false, 100, 2]]);

it('omits business fields for residential applicants and adds coverages outside tenant origin', function () {
    $quote = tokioBuilderQuote('primary', false, false);
    $quote->lead->tipo_solicitante = 'proprietario';
    $payload = app(\App\Services\Insurance\Payloads\TokioQuotePayloadBuilder::class)->build($quote);
    expect($payload['pretendentes'])->toHaveCount(1);
    expect($payload['pretendentes'][0])->not->toHaveKey('CNAE')->not->toHaveKey('capitalInicial');
    expect($payload['dadosLocacao']['pinturaNova'])->toBe('S');
    expect($payload['coberturas'])->toMatchArray(['icDanosImovel' => 'S', 'icPinturaInterna' => 'S', 'icPinturaExterna' => 'S', 'icMultaRescisao' => 'S']);
});

it('selects the returned installment without adding taxes or dividing totals', function () {
    $quote = new InsuranceQuote(['payment_type' => 'credito', 'response_payload' => ['response' => [
        'calculos' => ['N' => ['opcaoAssistencia' => 'Sem assistência', 'opcoesPagamento' => ['credito' => ['prazo' => [
            '1' => ['qtd' => 12, 'vlPremPar' => 1],
            '2' => ['qtd' => 30, 'vlPremPar' => 54.25, 'vlIofParc' => 4, 'vlJurPar' => 5],
        ]]]]],
    ]]]);
    $result = app(TokioQuoteResponse::class)->payment($quote);
    expect($result['selection']['amount'])->toBe('54.25');
    expect($result['selection']['count'])->toBe(30);
    expect($result['selection']['option_key'])->toBe('2');
});

it('does not substitute another assistance or payment method', function () {
    $quote = new InsuranceQuote(['payment_type' => 'faturado', 'response_payload' => ['response' => [
        'calculos' => ['N' => ['opcaoAssistencia' => 'Plano Completo', 'opcoesPagamento' => ['credito' => ['prazo' => [
            '1' => ['qtd' => 30, 'vlPremPar' => 50],
        ]]]]],
    ]]]);
    expect(app(TokioQuoteResponse::class)->payment($quote)['selection'])->toBeNull();
});

it('authenticates and creates a new quote without recalculation headers', function () {
    Http::preventStrayRequests();
    config(['services.tokio.enabled' => true,
        'services.tokio.base_url' => 'https://apim-act.tokiomarine.com.br/massificados/cotador-fianca-service-w/api',
        'services.tokio.token_url' => 'https://auth.example.test/token',
        'services.tokio.client_id' => 'client', 'services.tokio.client_secret' => 'secret',
        'services.tokio.scope' => 'test/.default', 'services.tokio.grant_type' => 'client_credentials',
        'services.tokio.codigo_corretor' => '1', 'services.tokio.cpf_usuario' => '09238037698',
        'services.tokio.senha_operadora' => 'operator-secret']);
    Http::fake([
        'auth.example.test/*' => Http::response(['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        'apim-act.tokiomarine.com.br/*' => Http::response(['cotacaoInfo' => ['sqCdCotac' => 123, 'cdSitucCotac' => 'CAL']]),
    ]);
    $result = app(TokioService::class)->quote(['tpPessoa' => 'F'], '00123');
    expect($result['success'])->toBeTrue();
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cotar')
        && $request->hasHeader('codigoParceiro', '00123') && $request->hasHeader('tipoParceiro', '5')
        && ! $request->hasHeader('sqCdCotac') && ! $request->hasHeader('token'));
});

it('logs Tokio HTTP outcomes without exposing the response body or credentials', function (int $httpStatus, mixed $body, ?string $providerStatus, bool $success) {
    config(['services.tokio.enabled' => true,
        'services.tokio.base_url' => 'https://apim-act.tokiomarine.com.br/api',
        'services.tokio.codigo_corretor' => '1', 'services.tokio.cpf_usuario' => 'private-cpf',
        'services.tokio.senha_operadora' => 'private-password']);
    $this->mock(\App\Services\Insurance\TokioAuthentication::class)
        ->shouldReceive('requestToken')->once()->andReturn(['success' => true, 'access_token' => 'private-token']);
    Http::preventStrayRequests();
    Http::fake(['apim-act.tokiomarine.com.br/*' => Http::response($body, $httpStatus)]);
    Log::spy();

    $result = app(TokioService::class)->quote(['nome' => 'Private Name'], null);

    expect($result['success'])->toBe($success);
    Log::shouldHaveReceived('log')->with('info', 'Resposta HTTP da Tokio', [
        'http_status' => $httpStatus, 'success' => $httpStatus === 200, 'provider_status' => $providerStatus,
    ])->once();
    if ($success) {
        expect($result['response'])->toBe($body);
        Log::shouldNotHaveReceived('log', ['warning', 'Falha no fluxo da Tokio', Mockery::any()]);
    } else {
        if ($httpStatus >= 400) {
            expect($result['response'])->toBe(is_array($body) ? $body : ['message' => $body]);
        }
        Log::shouldHaveReceived('log')->with('warning', 'Falha no fluxo da Tokio', [
            'http_status' => $httpStatus, 'error' => $result['error'], 'diagnostics' => $result['errors'],
        ])->once();
    }
})->with([
    'refusal is a successful HTTP response' => [200, ['cotacaoInfo' => ['cdSitucCotac' => 'REC', 'sqCdCotac' => 123, 'token' => 'private-token'], 'mensagens' => ['danger' => ['Private Name']]], 'REC', true],
    'approved' => [200, ['cotacaoInfo' => ['cdSitucCotac' => 'CAL', 'sqCdCotac' => 123]], 'CAL', true],
    'unknown status is not logged as free text' => [200, ['cotacaoInfo' => ['cdSitucCotac' => 'private-data']], null, true],
    'bad request' => [400, ['error' => 'private-data'], null, false],
    'server failure' => [500, 'private-data', null, false],
    'invalid JSON' => [200, '<html>private-data</html>', null, false],
]);

it('logs Tokio connection failures and preserves results when logging is unavailable', function (bool $connectionFailure) {
    config(['services.tokio.enabled' => true,
        'services.tokio.base_url' => 'https://apim-act.tokiomarine.com.br/api',
        'services.tokio.codigo_corretor' => '1', 'services.tokio.cpf_usuario' => 'private-cpf',
        'services.tokio.senha_operadora' => 'private-password']);
    $this->mock(\App\Services\Insurance\TokioAuthentication::class)
        ->shouldReceive('requestToken')->once()->andReturn(['success' => true, 'access_token' => 'private-token']);
    Http::preventStrayRequests();
    Http::fake(['*' => $connectionFailure ? Http::failedConnection('Connection refused token=private-data') : Http::response(['cotacaoInfo' => ['cdSitucCotac' => 'REC']])]);
    Log::shouldReceive('log')->once()->with(
        $connectionFailure ? 'warning' : 'info',
        $connectionFailure ? 'Falha no fluxo da Tokio' : 'Resposta HTTP da Tokio',
        Mockery::on(fn (array $context): bool => ! str_contains(json_encode($context), 'private-data')),
    )->andThrow(new RuntimeException('Disk full'));

    $result = app(TokioService::class)->quote([], null);

    expect($result['success'])->toBe(! $connectionFailure);
    Http::assertSentCount(1);
})->with([true, false]);

it('preserves sanitized Tokio transport and HTTP diagnostics without retrying', function (bool $transport) {
    config(['services.tokio.enabled' => true,
        'services.tokio.base_url' => 'https://apim-act.tokiomarine.com.br/api',
        'services.tokio.codigo_corretor' => '12345', 'services.tokio.cpf_usuario' => '09238037698',
        'services.tokio.senha_operadora' => 'secret-password']);
    $this->mock(\App\Services\Insurance\TokioAuthentication::class)
        ->shouldReceive('requestToken')->once()->andReturn(['success' => true, 'access_token' => 'secret-access-token']);
    Log::spy();
    Http::preventStrayRequests();
    $message = 'SSL certificate problem; nome=Pessoa Teste; CPF 09238037698; Bearer secret-access-token';
    $request = new \GuzzleHttp\Psr7\Request('POST', 'https://apim-act.tokiomarine.com.br/api/cotar');
    $previous = new \GuzzleHttp\Exception\ConnectException($message, $request, null, ['errno' => 60]);
    Http::fake(function () use ($transport, $message, $previous) {
        if ($transport) {
            throw new \Illuminate\Http\Client\ConnectionException($message, 0, $previous);
        }

        return Http::response(['errorCode' => 'INVALID_INPUT', 'message' => $message,
            'cpf' => '09238037698', 'access_token' => 'secret-access-token'], 422);
    });
    $result = app(TokioService::class)->quote(['pretendentes' => [['cpf' => '09238037698', 'nome' => 'Pessoa Teste']]], null);
    $safe = json_encode($result['errors']);
    expect($result['success'])->toBeFalse()
        ->and($result['http_status'])->toBe($transport ? null : 422)
        ->and($safe)->toContain('SSL certificate problem', 'REDACTED')
        ->not->toContain('09238037698', 'Pessoa Teste', 'secret-access-token');
    if ($transport) {
        expect($result['errors'][0]['data']['code'])->toBe('60');
    } else {
        expect($safe)->toContain('INVALID_INPUT', '422');
    }
    Log::shouldHaveReceived('log')->with('warning', 'Falha no fluxo da Tokio', Mockery::on(
        fn (array $context): bool => $context['diagnostics'] === $result['errors']
    ))->once();
    $lead = \App\Models\Lead::query()->create(array_merge(tokioBuilderQuote('primary', false, false)->lead->getAttributes(), ['email' => 'test@example.test']));
    $batch = \App\Models\InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => 1]);
    $analysis = \App\Models\InsuranceAnalysis::query()->create(['lead_id' => $lead->id, 'insurance_analysis_batch_id' => $batch->id,
        'provider' => 'tokio', 'product' => 'fianca_locaticia_residencial', 'status' => 'pending', 'rent_amount' => 400]);
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'diagnostic-test']]);
    $run = app(\App\Services\Insurance\InsuranceQuoteCoordinator::class)->prepare($analysis, 'diagnostic-test', false);
    $quote = $run->scenarios()->first()->quotes()->first();
    app(TokioQuoteResponse::class)->apply($quote, $result);
    expect($quote->fresh()->response_payload['errors'])->toBe($result['errors']);
    Http::assertSentCount($transport ? 0 : 1);
})->with([true, false]);
