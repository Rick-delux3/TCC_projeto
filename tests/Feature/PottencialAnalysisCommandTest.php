<?php

use App\Events\InsuranceAnalysisChanged;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisEvent;
use App\Models\Lead;
use App\Services\Insurance\InsuranceAnalysisService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Bus::fake();
    Mail::fake();
    Event::fake([InsuranceAnalysisChanged::class]);
    Sleep::fake();
    Http::preventStrayRequests();
    config([
        'features.insurance_analysis.enabled' => true,
        'broadcasting.default' => 'reverb',
        'services.pottencial.enabled' => true,
        'services.pottencial.base_url' => 'https://pottencial.example.test',
        'services.pottencial.client_id' => 'test-client',
        'services.pottencial.client_secret' => 'test-secret',
        'services.pottencial.broker_document' => '11222333000181',
        'services.pottencial.rental_endpoint' => '/insurance/v1/fianca-locaticia-mensalizado-pf/quotes',
    ]);
    Cache::put('pottencial_access_token', 'private-test-token', 3600);
    $this->lead = Lead::query()->create([
        'nome' => 'Pretendente teste', 'email' => 'integration@example.test', 'cpf' => '07234828702',
        'tel' => '11999998888', 'data_nascimento' => '1992-02-29',
        'tipo_locacao' => 'residencial', 'tipo_solicitante' => 'locador',
    ]);
    $this->lead->endereco()->create([
        'cep' => '01001000', 'logradouro' => 'Praça da Sé', 'numero' => '100',
        'bairro' => 'Sé', 'cidade_imovel' => 'São Paulo', 'estado' => 'SP',
    ]);
    $this->lead->despesas()->create(['valor_aluguel' => 1500, 'valor_agua' => 0, 'valor_luz' => 0]);
    $this->batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 1, 'finished_at' => now()]);
    $this->previousAnalysis = $this->batch->analyses()->create([
        'lead_id' => $this->lead->id, 'provider' => 'pottencial', 'product' => $this->lead->rentalGuaranteeProduct(),
        'status' => 'approved', 'quote_id' => 'previous-quote', 'response_payload' => ['status' => 'Approved'],
    ]);
    $this->savedLead = $this->lead->fresh()->getAttributes();
    $this->savedAnalysis = $this->previousAnalysis->fresh()->getAttributes();
    $this->savedBatch = $this->batch->fresh()->getAttributes();
    $this->transactionLevel = $this->lead->getConnection()->transactionLevel();
    $this->endpoint = 'https://pottencial.example.test/insurance/v1/fianca-locaticia-mensalizado-pf/quotes';
    Event::fake([InsuranceAnalysisChanged::class]);
});

afterEach(function () {
    expect(InsuranceAnalysis::query()->count())->toBe(1)
        ->and(InsuranceAnalysisEvent::query()->count())->toBe(0)
        ->and($this->previousAnalysis->fresh()->getAttributes())->toBe($this->savedAnalysis)
        ->and($this->batch->fresh()->getAttributes())->toBe($this->savedBatch)
        ->and($this->lead->fresh()->getAttributes())->toBe($this->savedLead)
        ->and($this->lead->insuranceAnalysesBatches()->count())->toBe(1)
        ->and($this->lead->getConnection()->transactionLevel())->toBe($this->transactionLevel);
    Bus::assertNothingDispatched();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Event::assertNotDispatched(InsuranceAnalysisChanged::class);
});

it('runs the real creation and synchronization flow and discards only temporary records', function (string $providerStatus, string $expectedStatus) {
    Http::fake(function (Request $request, array $options) use ($providerStatus) {
        expect($options['allow_redirects'])->toBeFalse();
        $temporary = InsuranceAnalysis::query()->whereKeyNot($this->previousAnalysis->id)->sole();
        expect($temporary->insurance_analysis_batch_id)->toBeNull()
            ->and($temporary->currentAttemptContext()['attempt_id'])->not->toBeEmpty();

        return $request->method() === 'POST'
            ? Http::response(['quoteId' => 'test-quote', 'status' => 'UnderAnalysis'], 201)
            : Http::response(['quoteId' => 'test-quote', 'status' => $providerStatus, 'premiumAmount' => 150.25], 200);
    });

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(0);
    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($report['success'])->toBeTrue()
        ->and($report['incomplete'])->toBeFalse()
        ->and($report['analysis']['status'])->toBe($expectedStatus)
        ->and($report['analysis']['quote_id'])->toBe('test-quote')
        ->and($report['analysis']['finished_at'])->not->toBeNull()
        ->and($report['steps'])->toHaveCount(2)
        ->and($report['steps'][0]['http_status'])->toBe(201)
        ->and($report['steps'][1]['response']['status'])->toBe($providerStatus)
        ->and($output)->not->toContain('private-test-token', 'test-secret');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === $this->endpoint
        && $request['riskObjects'][0]['occupation'] === 'Residencial'
        && $request['riskObjects'][0]['tenantDocumentNumber'] === '07234828702');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $this->endpoint.'/test-quote');
})->with(['approval' => ['Approved', 'approved'], 'refusal' => ['Denied', 'rejected']]);

it('waits between checks and stops when a final decision arrives', function () {
    Http::fakeSequence()->push(['quoteId' => 'test-quote', 'status' => 'Pending'], 201)
        ->push(['status' => 'UnderAnalysis'])->push(['status' => 'Approved']);

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id, '--consultas' => 5, '--intervalo' => 2]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['analysis']['status'])->toBe('approved')->and($report['steps'])->toHaveCount(3);
    Sleep::assertSleptTimes(1);
    Http::assertSentCount(3);
});

it('returns an incomplete result without pretending a pending analysis has finished', function () {
    Http::fake(['*' => Http::response(['quoteId' => 'test-quote', 'status' => 'Pending'])]);

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id, '--consultas' => 2, '--intervalo' => 1]))->toBe(2);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['success'])->toBeFalse()->and($report['incomplete'])->toBeTrue()
        ->and($report['analysis']['status'])->toBe('processing')
        ->and($report['analysis']['finished_at'])->toBeNull();
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(1);
});

it('consults a quote created with only a Location header', function () {
    Http::fakeSequence()->push('', 201, ['Location' => $this->endpoint.'/test-quote'])
        ->push(['status' => 'Approved']);

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['analysis']['quote_id'])->toBe('test-quote')->and($report['analysis']['status'])->toBe('approved');
    Http::assertSentCount(2);
});

it('reports creation HTTP failures without repeating a potentially completed POST', function (int $status) {
    Http::fake(['*' => Http::response('<error>detalhes da companhia</error>', $status, ['Retry-After' => '60'])]);

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['steps'])->toHaveCount(1)
        ->and($report['steps'][0]['http_status'])->toBe($status)
        ->and($report['steps'][0]['http_description'])->not->toBeEmpty()
        ->and($report['steps'][0]['raw_body'])->toBe('<error>detalhes da companhia</error>')
        ->and($report['analysis']['status'])->toBe('failed');
    Http::assertSentCount(1);
})->with([302, 401, 403, 422, 429, 500, 599]);

it('reports synchronization HTTP errors separately from successful creation', function (int $status) {
    Http::fakeSequence()->push(['quoteId' => 'test-quote', 'status' => 'Pending'], 201)
        ->push(['message' => 'Resultado indisponível'], $status);

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['steps'][0]['http_status'])->toBe(201)
        ->and($report['steps'][1]['http_status'])->toBe($status)
        ->and($report['steps'][1]['operation'])->toBe('get_analysis_result')
        ->and($report['analysis']['status'])->toBe('failed');
    Http::assertSentCount(2);
})->with([302, 503]);

it('preserves an authentication HTTP failure during consultation', function () {
    Http::fake(function (Request $request) {
        if ($request->url() === $this->endpoint) {
            Cache::forget('pottencial_access_token');

            return Http::response(['quoteId' => 'test-quote', 'status' => 'Pending'], 201);
        }

        return Http::response(['error' => 'invalid_client'], 401);
    });

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['steps'][1]['operation'])->toBe('authentication')
        ->and($report['steps'][1]['http_status'])->toBe(401)
        ->and($report['steps'][1]['endpoint'])->toBe('/oauth/v3/access-token');
    Http::assertSentCount(2);
});

it('does not consider HTTP success without a usable quote a completed integration', function () {
    Http::fake(['*' => Http::response('', 204)]);

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['success'])->toBeFalse()
        ->and($report['steps'][0]['http_status'])->toBe(204)
        ->and($report['analysis']['status'])->toBe('failed');
    Http::assertSentCount(1);
});

it('honors the integration feature switch', function () {
    config(['services.pottencial.enabled' => false]);
    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['error'])->toContain('desativado');
    Http::assertNothingSent();
});

it('handles a connection failure without inventing an HTTP status', function () {
    Http::fake(['*' => Http::failedConnection('Timeout')]);

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['steps'][0]['http_status'])->toBeNull()
        ->and($report['steps'][0]['http_label'])->toBe('sem resposta')
        ->and($report['analysis']['status'])->toBe('failed');
});

it('authenticates through the existing service when no token is cached', function () {
    Cache::forget('pottencial_access_token');
    Http::fake([
        '*/oauth/v3/access-token' => Http::response(['access_token' => 'private-test-token']),
        $this->endpoint => Http::response(['quoteId' => 'test-quote', 'status' => 'Pending'], 201),
        $this->endpoint.'/test-quote' => Http::response(['status' => 'Approved']),
    ]);

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(0);
    expect(Artisan::output())->not->toContain('private-test-token', 'test-secret');
    Http::assertSentCount(3);
});

it('identifies authentication errors instead of attributing them to quotation', function () {
    Cache::forget('pottencial_access_token');
    Http::fake(['*' => Http::response(['error' => 'invalid_client'], 401)]);

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['steps'][0]['operation'])->toBe('authentication')
        ->and($report['steps'][0]['http_status'])->toBe(401);
    Http::assertSentCount(1);
});

it('rejects invalid arguments before accessing the insurer', function (array $options) {
    expect(Artisan::call('pottencial:test-analysis', $options))->toBe(2);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['success'])->toBeFalse();
    Http::assertNothingSent();
})->with([
    'missing ID' => [[]],
    'invalid ID' => [['--lead' => 'abc']],
    'unknown lead' => [['--lead' => 999999999]],
    'invalid checks' => [['--lead' => 1, '--consultas' => 0]],
    'invalid interval' => [['--lead' => 1, '--intervalo' => 61]],
]);

it('uses the existing builder validation and cleans up an invalid lead analysis', function () {
    $this->lead->endereco()->delete();
    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['analysis']['status'])->toBe('failed')->and($report['analysis']['error'])->not->toBeEmpty();
    Http::assertNothingSent();
});

it('rolls back even when local initialization throws after inserting an analysis', function () {
    $this->partialMock(InsuranceAnalysisService::class, function ($mock) {
        $mock->shouldReceive('createPendingAnalysis')->once()->andReturnUsing(function (Lead $lead): never {
            $analysis = InsuranceAnalysis::query()->create([
                'lead_id' => $lead->id, 'provider' => 'pottencial', 'product' => $lead->rentalGuaranteeProduct(), 'status' => 'pending',
            ]);
            $analysis->events()->create(['event_type' => 'created', 'status' => 'pending']);
            throw new RuntimeException('Initialization failed');
        });
    });

    expect(Artisan::call('pottencial:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['success'])->toBeFalse();
    Http::assertNothingSent();
});
