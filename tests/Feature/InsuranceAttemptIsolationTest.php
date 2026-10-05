<?php

use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\StartInsuranceAnalysesBatchJob;
use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Jobs\SyncTooAnalysisStatusJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Services\Insurance\InsuranceStatusPolling;
use App\Services\Insurance\Payloads\TooRentalGuaranteePayloadBuilder;
use App\Services\Insurance\Providers\InsuranceProviderInterface;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use App\Services\Insurance\Providers\TooInsuranceProvider;
use App\Services\LeadReanalysisService;
use App\Services\TooService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['features.insurance_analysis.enabled' => true, 'services.too.enabled' => true]);
    Queue::fake();
    Http::preventStrayRequests();
});

function isolatedAttemptAnalysis(string $provider = 'pottencial'): InsuranceAnalysis
{
    $lead = Lead::query()->create([
        'nome' => 'Concorrência', 'email' => 'attempt@example.test',
        'cpf' => '52998224725', 'tipo_locacao' => 'residencial',
    ]);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => 1]);
    $analysis = InsuranceAnalysis::query()->create([
        'lead_id' => $lead->id, 'insurance_analysis_batch_id' => $batch->id,
        'provider' => $provider, 'product' => 'fianca_locaticia_residencial',
        'status' => 'pending', 'quote_id' => 'old-quote', 'proposal_id' => 'old-proposal',
    ]);
    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'old']]);

    return $analysis;
}

function supersedeInsuranceAttempt(InsuranceAnalysis $analysis): array
{
    return DB::transaction(function () use ($analysis): array {
        $current = InsuranceAnalysis::query()->lockForUpdate()->findOrFail($analysis->id);
        $current->update([
            'status' => 'pending', 'result' => null, 'quote_id' => 'new-quote', 'proposal_id' => 'new-proposal',
            'request_payload' => ['round' => 'new'], 'response_payload' => ['round' => 'new'],
            'premium_amount' => 777, 'error_message' => null, 'finished_at' => null,
        ]);
        $current->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new', 'is_reanalysis' => true]]);

        return $current->fresh()->getAttributes();
    });
}

it('discards both late decisions and late errors after the round changes during HTTP', function (string $jobType, bool $throws) {
    $analysis = isolatedAttemptAnalysis($jobType === 'too' ? 'too' : 'pottencial');
    $expected = null;
    $response = function () use ($analysis, $throws, &$expected): array {
        $expected = supersedeInsuranceAttempt($analysis);
        if ($throws) {
            throw new RuntimeException('Old HTTP failure');
        }

        return ['success' => true, 'response' => ['status' => 'Approved', 'quoteId' => 'obsolete', 'premiumAmount' => 12]];
    };
    if ($jobType === 'too') {
        $provider = $this->mock(TooInsuranceProvider::class);
        $provider->shouldReceive('getStatus')->once()->andReturnUsing($response);
        (new SyncTooAnalysisStatusJob($analysis->id, 'old'))->handle($provider);
    } else {
        $provider = Mockery::mock(InsuranceProviderInterface::class);
        $provider->shouldReceive($jobType === 'run' ? 'requestAnalysis' : 'getStatus')->once()->andReturnUsing($response);
        $resolver = $this->mock(InsuranceProviderResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn($provider);
        $job = $jobType === 'run'
            ? new RunProviderAnalysisJob($analysis->id, 'old')
            : new SyncProviderAnalysisStatusJob($analysis->id, 'old', automatic: true);
        $job->handle($resolver);
    }
    expect($analysis->fresh()->getAttributes())->toBe($expected)
        ->and($analysis->events()->whereIn('event_type', ['analysis_completed', 'failed'])->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['run', 'sync', 'too'])->with([false, true]);

it('discards queued stale work before contacting a provider', function (string $jobType) {
    $analysis = isolatedAttemptAnalysis($jobType === 'too' ? 'too' : 'pottencial');
    $expected = supersedeInsuranceAttempt($analysis);
    if ($jobType === 'too') {
        $provider = $this->mock(TooInsuranceProvider::class);
        $provider->shouldNotReceive('getStatus');
        (new SyncTooAnalysisStatusJob($analysis->id, 'old'))->handle($provider);
    } else {
        $resolver = $this->mock(InsuranceProviderResolver::class);
        $resolver->shouldNotReceive('resolve');
        $job = $jobType === 'run' ? new RunProviderAnalysisJob($analysis->id, 'old') : new SyncProviderAnalysisStatusJob($analysis->id, 'old');
        $job->handle($resolver);
    }
    expect($analysis->fresh()->getAttributes())->toBe($expected);
    Queue::assertNothingPushed();
})->with(['run', 'sync', 'too']);

it('protects intermediate Too identifiers when proposal creation returns after a new round', function () {
    $analysis = isolatedAttemptAnalysis('too');
    $expected = null;
    $this->mock(TooRentalGuaranteePayloadBuilder::class)->shouldReceive('buildFichaPayload')->once()->andReturn([]);
    $service = $this->mock(TooService::class);
    $service->shouldReceive('registerProposalFicha')->once()->andReturnUsing(function () use ($analysis, &$expected): array {
        $expected = supersedeInsuranceAttempt($analysis);

        return ['success' => true, 'response' => ['numeroProposta' => 'obsolete', 'numeroFicha' => 'obsolete']];
    });
    $service->shouldNotReceive('submitCreditAnalysis');
    (new RunProviderAnalysisJob($analysis->id, 'old'))->handle(app(InsuranceProviderResolver::class));
    expect($analysis->fresh()->getAttributes())->toBe($expected);
    Queue::assertNothingPushed();
});

it('does not schedule another status check for a replaced round', function (string $provider) {
    $analysis = isolatedAttemptAnalysis($provider);
    supersedeInsuranceAttempt($analysis);

    InsuranceStatusPolling::schedule($analysis, 'old', false);

    Queue::assertNothingPushed();
    InsuranceStatusPolling::schedule($analysis->fresh(), 'new', true);
    Queue::assertPushed($provider === 'too' ? SyncTooAnalysisStatusJob::class : SyncProviderAnalysisStatusJob::class, 1);
})->with(['pottencial', 'too']);

it('does not reexecute a duplicated initial job from the same round', function () {
    $analysis = isolatedAttemptAnalysis();
    $provider = Mockery::mock(InsuranceProviderInterface::class);
    $provider->shouldReceive('requestAnalysis')->once()->andReturn(['success' => true, 'response' => ['status' => 'Approved']]);
    $resolver = $this->mock(InsuranceProviderResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn($provider);
    $job = new RunProviderAnalysisJob($analysis->id, 'old');
    $job->handle($resolver);
    $job->handle($resolver);
    expect($analysis->fresh()->status)->toBe('approved');
});

it('does not finalize or schedule notifications for an obsolete batch round', function () {
    $analysis = isolatedAttemptAnalysis();
    supersedeInsuranceAttempt($analysis);
    $analysis->update(['status' => 'approved']);
    (new CompleteInsuranceAnalysesBatchJob($analysis->insurance_analysis_batch_id, 'old'))->handle();
    expect($analysis->batch->fresh()->status)->toBe('processing')
        ->and($analysis->batch->fresh()->finished_at)->toBeNull();
    Queue::assertNothingPushed();
    (new CompleteInsuranceAnalysesBatchJob($analysis->insurance_analysis_batch_id, 'new', true))->handle();
    expect($analysis->batch->fresh()->status)->toBe('completed');
});

it('keeps a concurrently created initial batch intact after acquiring the lead lock', function () {
    Bus::fake();
    $lead = Lead::query()->create(['nome' => 'Initial race', 'email' => 'initial-race@example.test']);
    $reads = 0;
    $batchId = null;
    Event::listen('eloquent.retrieved: '.Lead::class, function (Lead $retrieved) use ($lead, &$reads, &$batchId): void {
        if ($retrieved->id === $lead->id && ++$reads === 2) {
            $batchId = InsuranceAnalysisBatch::query()->create([
                'lead_id' => $lead->id, 'status' => 'completed', 'total_providers' => 1,
                'completed_providers' => 1, 'finished_at' => now(),
            ])->id;
        }
    });
    $resolver = $this->mock(InsuranceProviderResolver::class);
    $resolver->shouldReceive('availableProviders')->once()->andReturn(['pottencial']);
    try {
        (new StartInsuranceAnalysesBatchJob($lead->id))->handle($resolver);
    } finally {
        Event::forget('eloquent.retrieved: '.Lead::class);
    }
    expect(InsuranceAnalysisBatch::query()->findOrFail($batchId)->status)->toBe('completed')
        ->and(InsuranceAnalysis::query()->count())->toBe(0);
    Bus::assertNothingBatched();
});

it('rechecks technical retry eligibility under the lock', function () {
    $analysis = isolatedAttemptAnalysis();
    $analysis->update(['status' => 'failed']);
    $stale = $analysis->fresh();
    $service = app(LeadReanalysisService::class);
    $attemptId = $service->startTechnicalRetry($analysis, 'admin');
    expect(fn () => $service->startTechnicalRetry($stale, 'admin'))->toThrow(DomainException::class)
        ->and($analysis->fresh()->currentAttemptContext()['attempt_id'])->toBe($attemptId);
    Queue::assertPushed(RunProviderAnalysisJob::class, 1);
});

it('serializes all job types only for the same analysis', function () {
    $jobs = [new RunProviderAnalysisJob(10, 'old'), new SyncProviderAnalysisStatusJob(10, 'old'), new SyncTooAnalysisStatusJob(10, 'old')];
    $keys = array_map(fn ($job) => $job->middleware()[0]->getLockKey($job), $jobs);
    expect(array_unique($keys))->toHaveCount(1);
    $other = new RunProviderAnalysisJob(11, 'old');
    expect($other->middleware()[0]->getLockKey($other))->not->toBe($keys[0]);
});

it('routes completion to the latest partial reanalysis when another company finishes later', function () {
    $analysis = isolatedAttemptAnalysis();
    $other = InsuranceAnalysis::query()->create([
        'lead_id' => $analysis->lead_id, 'insurance_analysis_batch_id' => $analysis->insurance_analysis_batch_id,
        'provider' => 'too', 'product' => $analysis->product, 'status' => 'approved',
    ]);
    $other->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new-partial', 'is_reanalysis' => true]]);
    $analysis->batch->update(['total_providers' => 2]);
    $provider = Mockery::mock(InsuranceProviderInterface::class);
    $provider->shouldReceive('getStatus')->once()->andReturn(['success' => true, 'response' => ['status' => 'Denied']]);
    $resolver = $this->mock(InsuranceProviderResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn($provider);
    (new SyncProviderAnalysisStatusJob($analysis->id, 'old'))->handle($resolver);

    Queue::assertPushed(CompleteInsuranceAnalysesBatchJob::class, fn ($job): bool => $job->attemptId === 'new-partial' && $job->isReanalysis);
    (new CompleteInsuranceAnalysesBatchJob($analysis->insurance_analysis_batch_id, 'new-partial', true))->handle();
    expect($analysis->batch->fresh()->status)->toBe('completed');
});
