<?php

use App\Jobs\ApplyFinalAnalysisTagToLeadLoversJob;
use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\SendAnalysisResultsEmailJob;
use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Jobs\SyncTooAnalysisStatusJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Services\Insurance\Payloads\TooRentalGuaranteePayloadBuilder;
use App\Services\Insurance\Providers\InsuranceProviderInterface;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use App\Services\Insurance\Providers\TooInsuranceProvider;
use App\Services\TooService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'features.insurance_analysis.enabled' => true,
        'services.pottencial.enabled' => true,
        'services.too.enabled' => true,
        'services.pottencial.status_check_delay_seconds' => 30,
        'services.pottencial.status_check_max_failures' => 2,
        'services.too.status_check_delay_seconds' => 20,
    ]);
    Queue::fake();
    Http::preventStrayRequests();
});

function waitingProviderAnalysis(string $provider, string $status = 'pending'): InsuranceAnalysis
{
    $lead = Lead::query()->create([
        'nome' => 'Teste de acompanhamento', 'email' => 'polling@example.test',
        'tipo_solicitante' => 'locatario', 'cpf' => '52998224725',
    ]);
    $batch = InsuranceAnalysisBatch::query()->create([
        'lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => 4,
    ]);
    foreach (['approved', 'rejected', 'failed', $status] as $index => $state) {
        $analysis = InsuranceAnalysis::query()->create([
            'insurance_analysis_batch_id' => $batch->id,
            'lead_id' => $lead->id,
            'provider' => $index === 3 ? $provider : 'other-'.$index,
            'product' => 'fianca_locaticia_residencial',
            'status' => $state,
            'quote_id' => 'quote-test',
            'proposal_id' => 'proposal-test',
        ]);
    }
    $analysis->events()->create([
        'event_type' => 'analysis_started',
        'payload' => ['attempt_id' => 'polling-attempt', 'is_reanalysis' => false],
    ]);

    return $analysis;
}

it('keeps a four-company batch open for every nonfinal state', function (string $status) {
    $analysis = waitingProviderAnalysis('pottencial', $status);
    $batch = $analysis->batch;
    $batch->update(['status' => 'completed', 'finished_at' => now()->subMinute()]);

    (new CompleteInsuranceAnalysesBatchJob($batch->id, 'polling-attempt'))->handle();

    expect($batch->fresh()->status)->toBe('processing')
        ->and($batch->fresh()->finished_at)->toBeNull()
        ->and($batch->fresh()->completed_providers)->toBe(2)
        ->and($batch->fresh()->failed_providers)->toBe(1);
    Queue::assertNotPushed(SendAnalysisResultsEmailJob::class);
    Queue::assertNotPushed(ApplyFinalAnalysisTagToLeadLoversJob::class);
})->with(['pending', 'processing', 'Pending', 'UnderAnalysis', 'manual_review', 'quoted']);

it('polls until the last provider decides and only then finishes the batch', function (string $providerName) {
    $analysis = waitingProviderAnalysis($providerName);
    $provider = Mockery::mock(InsuranceProviderInterface::class);
    $provider->shouldReceive('requestAnalysis')->once()->andReturn([
        'success' => true, 'response' => ['status' => 'Pending', 'quoteId' => 'quote-test'],
    ]);
    $resolver = $this->mock(InsuranceProviderResolver::class);
    $resolver->shouldReceive('resolve')->with($providerName)->andReturn($provider);

    (new RunProviderAnalysisJob($analysis->id, 'polling-attempt'))->handle($resolver);
    (new CompleteInsuranceAnalysesBatchJob($analysis->insurance_analysis_batch_id, 'polling-attempt'))->handle();

    expect($analysis->fresh()->status)->toBe('processing')
        ->and($analysis->fresh()->finished_at)->toBeNull()
        ->and($analysis->batch->fresh()->finished_at)->toBeNull();
    $pollClass = $providerName === 'too' ? SyncTooAnalysisStatusJob::class : SyncProviderAnalysisStatusJob::class;
    Queue::assertPushed($pollClass, 1);
    Queue::assertPushed($pollClass, fn ($job): bool => $job->delay->isFuture() && $job->attemptId === 'polling-attempt');
    Queue::assertNotPushed(SendAnalysisResultsEmailJob::class);

    $pollProvider = $providerName === 'too' ? $this->mock(TooInsuranceProvider::class) : $provider;
    $pollProvider->shouldReceive('getStatus')->twice()->andReturn(
        ['success' => true, 'response' => ['status' => 'UnderAnalysis']],
        ['success' => true, 'response' => ['status' => 'Approved', 'quoteId' => 'quote-test']],
    );
    Queue::fake();
    if ($providerName === 'too') {
        (new SyncTooAnalysisStatusJob($analysis->id, 'polling-attempt', false, 16))->handle($pollProvider);
    } else {
        (new SyncProviderAnalysisStatusJob($analysis->id, 'polling-attempt', automatic: true))->handle($resolver);
    }
    expect($analysis->fresh()->finished_at)->toBeNull();
    Queue::assertPushed($pollClass, 1);

    Queue::fake();
    if ($providerName === 'too') {
        (new SyncTooAnalysisStatusJob($analysis->id, 'polling-attempt', false, 17))->handle($pollProvider);
    } else {
        (new SyncProviderAnalysisStatusJob($analysis->id, 'polling-attempt', automatic: true))->handle($resolver);
    }
    Queue::assertNotPushed($pollClass);
    expect($analysis->fresh()->status)->toBe('approved')
        ->and($analysis->fresh()->finished_at)->not->toBeNull();

    $completion = new CompleteInsuranceAnalysesBatchJob($analysis->insurance_analysis_batch_id, 'polling-attempt');
    $completion->handle();
    $completion->handle();
    expect($analysis->batch->fresh()->status)->toBe('completed_with_errors')
        ->and($analysis->batch->fresh()->finished_at)->not->toBeNull();
    Queue::assertPushed(SendAnalysisResultsEmailJob::class, 1);
    Queue::assertPushed(ApplyFinalAnalysisTagToLeadLoversJob::class, 1);
})->with(['pottencial', 'too']);

it('does not finalize a batch with missing provider records', function () {
    $analysis = waitingProviderAnalysis('pottencial', 'approved');
    $analysis->batch->update(['total_providers' => 5]);

    (new CompleteInsuranceAnalysesBatchJob($analysis->insurance_analysis_batch_id, 'polling-attempt'))->handle();

    expect($analysis->batch->fresh()->finished_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('ignores scheduled consultations for an obsolete round or a terminal analysis', function (bool $obsolete, string $provider) {
    $analysis = waitingProviderAnalysis($provider, $obsolete ? 'processing' : 'approved');
    if ($obsolete) {
        $analysis->events()->create([
            'event_type' => 'reanalysis_started', 'payload' => ['attempt_id' => 'new-attempt', 'is_reanalysis' => true],
        ]);
    }
    if ($provider === 'too') {
        $adapter = $this->mock(TooInsuranceProvider::class);
        $adapter->shouldNotReceive('getStatus');
        (new SyncTooAnalysisStatusJob($analysis->id, 'polling-attempt'))->handle($adapter);
    } else {
        $resolver = $this->mock(InsuranceProviderResolver::class);
        $resolver->shouldNotReceive('resolve');
        (new SyncProviderAnalysisStatusJob($analysis->id, 'polling-attempt', automatic: true))->handle($resolver);
    }
    Queue::assertNothingPushed();
})->with([true, false])->with(['pottencial', 'too']);

it('retries Pottencial communication failures before recording a terminal technical failure', function () {
    $analysis = waitingProviderAnalysis('pottencial', 'processing');
    $provider = Mockery::mock(InsuranceProviderInterface::class);
    $provider->shouldReceive('getStatus')->twice()->andReturn(['success' => false, 'response' => ['message' => 'Unavailable']]);
    $resolver = $this->mock(InsuranceProviderResolver::class);
    $resolver->shouldReceive('resolve')->andReturn($provider);

    (new SyncProviderAnalysisStatusJob($analysis->id, 'polling-attempt', automatic: true))->handle($resolver);
    expect($analysis->fresh()->status)->toBe('processing')->and($analysis->fresh()->finished_at)->toBeNull();
    Queue::assertPushed(SyncProviderAnalysisStatusJob::class, fn ($job): bool => $job->consecutiveFailures === 1);

    (new SyncProviderAnalysisStatusJob($analysis->id, 'polling-attempt', automatic: true, consecutiveFailures: 1))->handle($resolver);
    expect($analysis->fresh()->status)->toBe('failed')->and($analysis->fresh()->finished_at)->not->toBeNull();
});

it('keeps Too preapproval open and schedules its next consultation after creation', function () {
    $analysis = waitingProviderAnalysis('too');
    $this->mock(TooRentalGuaranteePayloadBuilder::class)->shouldReceive('buildFichaPayload')->once()->andReturn([]);
    $service = $this->mock(TooService::class);
    $service->shouldReceive('registerProposalFicha')->once()->andReturn([
        'success' => true, 'response' => ['numeroProposta' => 'proposal-test', 'numeroFicha' => 'ficha-test'],
    ]);
    $service->shouldReceive('submitCreditAnalysis')->once()->andReturn(['success' => true]);
    $service->shouldReceive('getProposalStatus')->once()->andReturn([
        'success' => true, 'response' => ['proposta' => ['status' => 16, 'descricaoStatus' => 'Pré-aprovada']],
    ]);
    $service->shouldNotReceive('requestQuote');

    (new RunProviderAnalysisJob($analysis->id, 'polling-attempt'))->handle(app(InsuranceProviderResolver::class));
    (new CompleteInsuranceAnalysesBatchJob($analysis->insurance_analysis_batch_id, 'polling-attempt'))->handle();

    expect($analysis->fresh()->status)->toBe('manual_review')
        ->and($analysis->fresh()->finished_at)->toBeNull()
        ->and($analysis->batch->fresh()->finished_at)->toBeNull();
    Queue::assertPushed(SyncTooAnalysisStatusJob::class, 1);
    Queue::assertNotPushed(SendAnalysisResultsEmailJob::class);
    Http::assertNothingSent();
});

it('records an ineligible Too submission as a failure instead of leaving it processing forever', function () {
    $analysis = waitingProviderAnalysis('too');
    $analysis->lead->update(['tipo_solicitante' => 'locador']);
    $this->mock(TooService::class)->shouldNotReceive('registerProposalFicha');

    (new RunProviderAnalysisJob($analysis->id, 'polling-attempt'))->handle(app(InsuranceProviderResolver::class));

    expect($analysis->fresh()->status)->toBe('failed')->and($analysis->fresh()->finished_at)->not->toBeNull();
    Queue::assertNotPushed(SyncTooAnalysisStatusJob::class);
    Http::assertNothingSent();
});

it('resets consecutive communication failures when the company responds with a pending decision', function (string $providerName) {
    $analysis = waitingProviderAnalysis($providerName, 'processing');
    $result = ['success' => true, 'response' => ['status' => 'UnderAnalysis']];
    if ($providerName === 'too') {
        $provider = $this->mock(TooInsuranceProvider::class);
        $provider->shouldReceive('getStatus')->once()->andReturn($result);
        (new SyncTooAnalysisStatusJob($analysis->id, 'polling-attempt', false, 25, 14))->handle($provider);
        $nextJob = SyncTooAnalysisStatusJob::class;
    } else {
        $provider = Mockery::mock(InsuranceProviderInterface::class);
        $provider->shouldReceive('getStatus')->once()->andReturn($result);
        $resolver = $this->mock(InsuranceProviderResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($provider);
        (new SyncProviderAnalysisStatusJob($analysis->id, 'polling-attempt', automatic: true, consecutiveFailures: 1))->handle($resolver);
        $nextJob = SyncProviderAnalysisStatusJob::class;
    }
    expect($analysis->fresh()->status)->toBe('processing')->and($analysis->fresh()->finished_at)->toBeNull();
    Queue::assertPushed($nextJob, fn ($job): bool => $job->consecutiveFailures === 0);
})->with(['too', 'pottencial']);
