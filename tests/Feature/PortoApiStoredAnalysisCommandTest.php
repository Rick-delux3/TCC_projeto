<?php

use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceQuote;
use App\Models\Lead;
use App\Services\Insurance\InsuranceQuoteCoordinator;
use App\Services\Insurance\PortoAuthentication;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => config(['services.porto.susep' => 'ABC12J', 'services.porto.commission' => 15]));

it('prints complete saved Porto requests, responses and a per stage diagnosis without side effects', function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->mock(PortoAuthentication::class)->shouldNotReceive('requestToken', 'token');
    $lead = Lead::query()->create(['nome' => 'Stored applicant', 'email' => 'stored@example.test', 'cpf' => '52998224725', 'tipo_locacao' => 'residencial', 'tipo_solicitante' => 'locatario']);
    $lead->despesas()->create(['valor_aluguel' => 1000]);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'total_providers' => 1, 'status' => 'processing']);
    $analysis = $batch->analyses()->create(['lead_id' => $lead->id, 'provider' => 'porto', 'status' => 'approved',
        'response_payload' => ['errors' => ['Consolidated error']],
    ]);
    foreach (['first-attempt', 'second-attempt'] as $attempt) {
        $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => $attempt]]);
        app(InsuranceQuoteCoordinator::class)->prepare($analysis, $attempt, false);
    }
    [$approved, $refused] = InsuranceQuote::orderBy('id')->get()->all();
    $request = ['susep' => 'ABC12J', 'cpfCotador' => '52998224725', 'cep' => '01001000', 'tipoLocacao' => '1',
        'locatarios' => [['cpfCnpj' => '52998224725', 'nome' => 'Stored applicant', 'titular' => 'S', 'dadosProfissionais' => ['totalRendimentos' => 4000]]],
        'coberturas' => [['codCobertura' => '001', 'valorIS' => 1000]],
        'contrato' => ['tipoVigenciaContrato' => '1', 'inicioContrato' => '11/11/2026', 'fimContrato' => '11/05/2029', 'codigoReajuste' => '002'],
    ];
    $prices = ['orcamento' => '000000000000123', 'ofertas' => [
        ['seguro' => '0008', 'nomeSeguro' => 'Fiança', 'formasPagamento' => [['formaPagamento' => 'CC', 'nomeFormaPagamento' => 'Cartão', 'condicoesPagamento' => [
            ['condicaoPagamento' => '029', 'quantidadeParcela' => 29, 'valorParcela' => 45.5],
            ['condicaoPagamento' => '001', 'quantidadeParcela' => 1, 'valorParcela' => 1200],
        ]]]],
    ]];
    $selection = ['offer_code' => '0008', 'offer_name' => 'Fiança', 'payment_method' => 'CC', 'payment_method_name' => 'Cartão',
        'condition_code' => '029', 'count' => 29, 'amount' => '45.50', 'payment_type' => 'CC', 'with_entry' => false];
    $approved->update(['status' => 'approved', 'payment_status' => 'ready', 'payment_type' => 'CC', 'quote_id' => '000000000000123',
        'request_payload' => $request,
        'response_payload' => ['http_status' => 201, 'errors' => [], 'response' => ['orcamento' => '000000000000123',
            'ofertas' => [['seguro' => '0008', 'nomeSeguro' => 'Fiança'], ['seguro' => '0001', 'nomeSeguro' => 'Essencial']]],
            'payment' => ['source' => 'offers', 'response' => $prices, 'selection' => $selection, 'alternatives' => [], 'unavailable_reason' => null,
                // 0008 no formato atual (PUT + GET); 0001 como registro do fluxo anterior a 11/11/2026.
                'commission' => ['0008' => ['applied' => true, 'http_status' => 200, 'error' => null, 'requested' => 15, 'returned' => 15],
                    '0001' => ['applied' => false, 'http_status' => 500, 'error' => 'Falha na comissão']]],
        ],
    ]);
    $approved->events()->create(['event_type' => 'payment_consulted', 'response' => ['raw_body' => 'Original price response']]);
    $refused->update(['status' => 'rejected', 'quote_id' => '000000000000456', 'request_payload' => $request,
        'response_payload' => ['http_status' => 409, 'errors' => [], 'budget_missing' => false,
            'response' => ['errorMessage' => 'Houve recusa na aceitação', 'detailedMessage' => '000000000000456']],
    ]);
    config(['features.insurance_analysis.enabled' => false]);
    Log::shouldReceive('info', 'warning', 'error', 'debug', 'log', 'channel')->never();
    Cache::shouldReceive('put', 'add', 'remember', 'forever', 'forget')->never();
    $queries = [];
    $analysis->getConnection()->listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(Artisan::call('porto:test-api', ['--analise' => $analysis->id]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('request_payload', 'response_payload', 'Consolidated error', 'first-attempt', 'second-attempt',
        'Original price response', 'POST /orcamentos', 'tipoLocacao: 1 — Residencial (CPF)', '001 Aluguel = R$ 1.000,00',
        // Etapa 1: decisão interpretada de cada cenário.
        'Decisão interpretada: APROVADO', 'Decisão interpretada: RECUSADO', 'Houve recusa na aceitação',
        // Etapa 2: comissão confirmada pelo PUT, registro legado e o corpo reconstruído do PUT.
        'PUT /orcamentos/000000000000123/ABC12J', 'Oferta 0008: comissão confirmada | HTTP 200 | enviado 15 / GET de ofertas 15',
        'Oferta 0001: NÃO confirmada | HTTP 500 | registro anterior a 11/11/2026: confirmado só pelo HTTP do PUT | Falha na comissão',
        'mais de um PUT no mesmo orçamento', '"codComissao": 15',
        // Etapa 3: preço selecionado e todas as condições retornadas.
        'GET /orcamentos/22/000000000000123/ABC12J/ofertas', 'Selecionada: 0008 Fiança | CC cond. 029 | 29x R$ 45,50', 'R$ 1.200,00',
        // Documentos que podem ser obtidos para cada resultado.
        'pdf/cotacao/0008/CC/029', '/orcamentos/000000000000456/ABC12J/pdf/carta-recusa');
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('explains a technical failure that is not a refusal', function () {
    $lead = Lead::query()->create(['nome' => 'Failure', 'email' => 'failure@example.test']);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'total_providers' => 1, 'status' => 'processing']);
    $analysis = $batch->analyses()->create(['lead_id' => $lead->id, 'provider' => 'porto', 'status' => 'failed']);
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'attempt']]);
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'attempt', false);
    InsuranceQuote::query()->first()->update(['status' => 'failed', 'error_message' => 'A Porto apontou inconsistência nos dados enviados.',
        'response_payload' => ['http_status' => 400, 'response' => [], 'errors' => ['fields' => ['cep']]]]);

    expect(Artisan::call('porto:test-api', ['--analise' => $analysis->id]))->toBe(0);
    expect(Artisan::output())->toContain('FALHA TÉCNICA (não é recusa e não autoriza a análise conjunta)',
        'A Porto apontou inconsistência', '"cep"', 'não executada (somente orçamento aprovado recebe ajuste de comissão)');
});

it('supports a saved legacy analysis and rejects a different provider', function (string $provider, int $exitCode) {
    Http::preventStrayRequests();
    $lead = Lead::query()->create(['nome' => 'Legacy', 'email' => 'legacy@example.test']);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'total_providers' => 1, 'status' => 'completed']);
    $analysis = $batch->analyses()->create(['lead_id' => $lead->id, 'provider' => $provider, 'status' => 'failed',
        'request_payload' => ['body' => ['preserved' => true]], 'response_payload' => ['message' => 'Full legacy response'],
    ]);
    expect(Artisan::call('porto:test-api', ['--analise' => $analysis->id]))->toBe($exitCode);
    if ($provider === 'porto') {
        expect(Artisan::output())->toContain('preserved', 'Full legacy response');
    } else {
        expect(Artisan::output())->toContain('não encontrada')->not->toContain('Full legacy response');
    }
    Http::assertNothingSent();
})->with([['porto', 0], ['pottencial', 1]]);

it('rejects an invalid or absent local analysis without authenticating', function (string $id, int $exitCode) {
    $this->mock(PortoAuthentication::class)->shouldNotReceive('requestToken', 'token');
    expect(Artisan::call('porto:test-api', ['--analise' => $id]))->toBe($exitCode);
})->with([['0', 2], ['abc', 2], ['999999', 1]]);

it('tests the Porto authentication when no analysis option is supplied', function (bool $success) {
    $this->mock(PortoAuthentication::class)->shouldReceive('requestToken')->once()->andReturn($success
        ? ['success' => true, 'message' => 'Token de acesso da Porto gerado com sucesso.', 'http_status' => 200, 'access_token' => 'secret-token', 'expires_in' => 3600, 'diagnostics' => []]
        : ['success' => false, 'message' => 'Autenticação recusada.', 'http_status' => 401, 'access_token' => null, 'expires_in' => null, 'diagnostics' => ['error' => 'invalid_client']]);

    expect(Artisan::call('porto:test-api'))->toBe($success ? 0 : 1);
    $output = Artisan::output();
    expect($output)->not->toContain('secret-token');
    $success
        ? expect($output)->toContain('HTTP: 200', 'Token de acesso da Porto gerado com sucesso.', '3600 segundos')
        : expect($output)->toContain('HTTP: 401', 'Autenticação recusada.', 'invalid_client');
})->with([true, false]);
