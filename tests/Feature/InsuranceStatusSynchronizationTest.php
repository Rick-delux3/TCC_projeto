<?php

use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Jobs\SyncTooAnalysisStatusJob;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Models\User;
use App\Services\Insurance\Payloads\TooRentalGuaranteePayloadBuilder;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use App\Services\Insurance\Providers\TooInsuranceProvider;
use App\Services\TooService;
use App\Support\CorretorPermissions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    config([
        'features.insurance_analysis.enabled' => true,
        'services.too.enabled' => true,
        'services.too.status_check_delay_seconds' => 20,
        'services.too.status_check_max_failures' => 2,
    ]);
    Queue::fake();
    Http::preventStrayRequests();
    $this->tooService = $this->mock(TooService::class);
    $this->payloadBuilder = $this->mock(TooRentalGuaranteePayloadBuilder::class);
    $this->company = Imobiliaria::factory()->create();
    $this->actingAs(User::factory()->create(['company_id' => $this->company->id]))
        ->withSession(['2fa_passed' => true]);

    $lead = Lead::query()->create([
        'nome' => 'Teste de consulta',
        'email' => 'status-sync@example.test',
        'tipo_solicitante' => 'locatario',
        'cpf' => '52998224725',
        'company_id' => $this->company->id,
    ]);
    $batch = InsuranceAnalysisBatch::query()->create([
        'lead_id' => $lead->id,
        'company_id' => $this->company->id,
        'status' => 'processing',
        'total_providers' => 1,
    ]);
    $this->analysis = InsuranceAnalysis::query()->create([
        'lead_id' => $lead->id,
        'company_id' => $this->company->id,
        'insurance_analysis_batch_id' => $batch->id,
        'provider' => 'too',
        'product' => 'fianca_locaticia_residencial',
        'status' => 'manual_review',
        'proposal_id' => 'proposal-test',
        'quote_id' => 'quote-test',
        'response_payload' => [
            'numeroProposta' => 'proposal-test',
            'numeroFicha' => 'ficha-test',
            'too_analysis_attempt_id' => 'initial-attempt',
            'too_is_reanalysis' => false,
            'too_status_check_stopped' => true,
            'too_manual_sync_available' => true,
        ],
    ]);
    $this->analysis->events()->create([
        'event_type' => 'analysis_started',
        'payload' => ['attempt_id' => 'initial-attempt', 'is_reanalysis' => false],
    ]);
});

function tooStatusResponse(int $status): array
{
    return [
        'success' => true,
        'http_status' => 200,
        'response' => ['proposta' => ['status' => $status, 'descricaoStatus' => 'Status de teste']],
    ];
}

it('queries Too using stored payloads without losing proposal identifiers', function (string $format) {
    $payload = $this->analysis->response_payload;
    if ($format === 'json') {
        $this->analysis->update(['proposal_id' => null, 'response_payload' => json_encode($payload)]);
    } elseif ($format === 'invalid') {
        $this->analysis->update(['response_payload' => 'invalid-json']);
    }
    $this->tooService->shouldReceive('getProposalStatus')->once()
        ->with('52998224725', 'proposal-test')->andReturn(tooStatusResponse(5));

    $result = app(TooInsuranceProvider::class)->getStatus($this->analysis);

    expect($result['success'])->toBeTrue()
        ->and($result['response']['status'])->toBe('UnderAnalysis')
        ->and($result['response']['numeroProposta'])->toBe('proposal-test')
        ->and($this->analysis->fresh()->response_payload['status_latest'])->toBe(tooStatusResponse(5));
    if ($format !== 'invalid') {
        expect($result['response']['numeroFicha'])->toBe('ficha-test')
            ->and($this->analysis->fresh()->response_payload['numeroFicha'])->toBe('ficha-test');
    }
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['array', 'json', 'invalid']);

it('blocks Too queries before accessing the service when disabled or identifiers are missing', function (bool $disabled) {
    $this->tooService->shouldNotReceive('getProposalStatus');
    if ($disabled) {
        config(['services.too.enabled' => false]);
        expect(fn () => app(TooInsuranceProvider::class)->getStatus($this->analysis))
            ->toThrow(LogicException::class);
    } else {
        $this->analysis->update(['proposal_id' => null, 'response_payload' => null]);
        $result = app(TooInsuranceProvider::class)->getStatus($this->analysis);
        expect($result['success'])->toBeFalse()
            ->and($result['response']['step'])->toBe('get_status_validate');
    }
})->with([false, true]);

it('continues automatic polling and completes the same attempt on a final response', function (int $status, string $expected) {
    $this->analysis->update(['status' => 'processing']);
    $this->tooService->shouldReceive('getProposalStatus')->twice()
        ->with('52998224725', 'proposal-test')->andReturn(tooStatusResponse(5), tooStatusResponse($status));
    if ($status === 8) {
        $this->payloadBuilder->shouldReceive('buildQuotePayload')->once()
            ->withArgs(fn (InsuranceAnalysis $analysis, string $number): bool => $analysis->is($this->analysis) && $number === 'ficha-test')
            ->andReturn(['numeroFicha' => 'ficha-test']);
        $this->tooService->shouldReceive('requestQuote')->once()->andReturn([
            'success' => true, 'http_status' => 200,
            'response' => ['numeroCotacao' => 'new-quote', 'premiumAmount' => 150.50],
        ]);
    }
    $provider = app(TooInsuranceProvider::class);
    (new SyncTooAnalysisStatusJob($this->analysis->id, 'initial-attempt'))->handle($provider);

    expect($this->analysis->fresh()->status)->toBe('processing');
    Queue::assertNotPushed(CompleteInsuranceAnalysesBatchJob::class);
    Queue::assertPushed(SyncTooAnalysisStatusJob::class, fn ($job): bool => $job->attemptId === 'initial-attempt'
        && $job->attemptNumber === 2 && $job->delay->isFuture());

    (new SyncTooAnalysisStatusJob($this->analysis->id, 'initial-attempt', false, 2))->handle($provider);

    expect($this->analysis->fresh()->status)->toBe($expected);
    Queue::assertPushed(CompleteInsuranceAnalysesBatchJob::class, fn ($job): bool => $job->attemptId === 'initial-attempt');
    if ($status === 8) {
        expect($this->analysis->fresh()->quote_id)->toBe('new-quote')
            ->and($this->analysis->fresh()->premium_amount)->toBe('150.50');
    }
})->with(['approved' => [8, 'approved'], 'denied' => [6, 'rejected']]);

it('dispatches manual status queries with the latest attempt for both dashboards and providers', function (string $provider, string $dashboard) {
    $this->analysis->update(['provider' => $provider]);
    $this->analysis->events()->create([
        'event_type' => 'reanalysis_requested',
        'payload' => ['attempt_id' => 'latest-attempt', 'is_reanalysis' => true],
    ]);
    $this->analysis->events()->create([
        'event_type' => 'too_waiting_credit_analysis',
        'payload' => ['attempt_id' => 'initial-attempt', 'is_reanalysis' => false],
    ]);
    if ($dashboard === 'admin') {
        $admin = Corretor::query()->create([
            'name' => 'Corretor consulta', 'email' => 'sync-admin@example.test', 'password' => 'password',
            'role' => Corretor::ROLE_INTEGRANTE, 'active' => true, 'first_login_verified_at' => now(),
            'permissions' => [CorretorPermissions::VIEW_ANALYSIS],
        ]);
        $this->actingAs($admin, 'admin');
    }
    $route = $dashboard === 'admin' ? 'admin.insurance-analyses.sync-status' : 'insurance-analyses.sync-status';
    $this->post(route($route, $this->analysis))->assertRedirect()->assertSessionHas('success');

    Queue::assertPushed(SyncProviderAnalysisStatusJob::class, fn ($job): bool => $job->analysisId === $this->analysis->id
        && $job->attemptId === 'latest-attempt' && $job->isReanalysis);
    $event = $this->analysis->events()->latest('id')->first();
    expect($event->payload['attempt_id'])->toBe('latest-attempt')
        ->and($event->payload['is_reanalysis'])->toBeTrue();
})->with(['too', 'pottencial'])->with(['company', 'admin']);

it('does not invent an attempt or enqueue a query without an identifiable round', function () {
    $this->analysis->update(['provider' => 'pottencial']);
    $this->analysis->events()->where('event_type', 'analysis_started')->update(['payload' => []]);

    $this->post(route('insurance-analyses.sync-status', $this->analysis))
        ->assertRedirect()->assertSessionHas('error');

    Queue::assertNothingPushed();
    expect($this->analysis->events()->count())->toBe(1);
});

it('prevents another company from requesting a status query', function () {
    $this->analysis->update(['company_id' => Imobiliaria::factory()->create()->id]);

    $this->post(route('insurance-analyses.sync-status', $this->analysis))->assertForbidden();

    Queue::assertNothingPushed();
});

it('applies a manual Too result and completes the queried reanalysis', function () {
    $this->analysis->update(['proposal_id' => null]);
    $this->analysis->events()->create([
        'event_type' => 'reanalysis_started',
        'payload' => ['attempt_id' => 'latest-attempt', 'is_reanalysis' => true],
    ]);
    $this->tooService->shouldReceive('getProposalStatus')->once()
        ->with('52998224725', 'proposal-test')->andReturn(tooStatusResponse(6));

    (new SyncProviderAnalysisStatusJob($this->analysis->id, 'latest-attempt', true))
        ->handle(app(InsuranceProviderResolver::class));

    expect($this->analysis->fresh()->status)->toBe('rejected');
    $event = $this->analysis->events()->where('event_type', 'reanalysis_completed')->latest('id')->first();
    expect($event->payload['attempt_id'])->toBe('latest-attempt')
        ->and($event->payload['is_reanalysis'])->toBeTrue();
    Queue::assertPushed(CompleteInsuranceAnalysesBatchJob::class, fn ($job): bool => $job->attemptId === 'latest-attempt' && $job->isReanalysis);
});

it('recovers the initial attempt from a legacy Too payload when start events are absent', function () {
    $this->analysis->events()->delete();
    $this->analysis->update([
        'proposal_id' => null,
        'response_payload' => json_encode($this->analysis->response_payload),
    ]);

    $this->post(route('insurance-analyses.sync-status', $this->analysis))
        ->assertRedirect()->assertSessionHas('success');

    Queue::assertPushed(SyncProviderAnalysisStatusJob::class, fn ($job): bool => $job->attemptId === 'initial-attempt' && ! $job->isReanalysis);
});

it('retries automatic query failures and records a technical failure when the limit is reached', function () {
    $this->analysis->update(['status' => 'processing']);
    $this->tooService->shouldReceive('getProposalStatus')->twice()->andReturn([
        'success' => false, 'http_status' => 503, 'response' => [],
    ]);
    $provider = app(TooInsuranceProvider::class);

    (new SyncTooAnalysisStatusJob($this->analysis->id, 'initial-attempt'))->handle($provider);

    expect($this->analysis->fresh()->status)->toBe('processing');
    Queue::assertPushed(SyncTooAnalysisStatusJob::class, fn ($job): bool => $job->attemptNumber === 2);
    Queue::assertNotPushed(CompleteInsuranceAnalysesBatchJob::class);

    (new SyncTooAnalysisStatusJob($this->analysis->id, 'initial-attempt', false, 2, 1))->handle($provider);

    $this->analysis->refresh();
    expect($this->analysis->status)->toBe('failed')
        ->and($this->analysis->finished_at)->not->toBeNull()
        ->and($this->analysis->response_payload['too_status_check_stopped'])->toBeTrue()
        ->and($this->analysis->response_payload['too_manual_sync_available'])->toBeFalse()
        ->and($this->analysis->response_payload['numeroFicha'])->toBe('ficha-test');
});

it('keeps a failed manual query available and records its attempt', function () {
    $this->tooService->shouldReceive('getProposalStatus')->once()->andReturn([
        'success' => false, 'http_status' => 503, 'response' => [],
    ]);

    (new SyncProviderAnalysisStatusJob($this->analysis->id, 'initial-attempt'))
        ->handle(app(InsuranceProviderResolver::class));

    $this->analysis->refresh();
    expect($this->analysis->status)->toBe('manual_review')
        ->and($this->analysis->result)->toBe('manual_review')
        ->and($this->analysis->response_payload['too_manual_sync_available'])->toBeTrue()
        ->and($this->analysis->events()->latest('id')->first()->payload['attempt_id'])->toBe('initial-attempt');
    Queue::assertNotPushed(CompleteInsuranceAnalysesBatchJob::class);
});
