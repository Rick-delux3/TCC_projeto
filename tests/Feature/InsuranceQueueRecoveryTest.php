<?php

use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\StartInsuranceAnalysesBatchJob;
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
use Illuminate\Bus\Events\BatchDispatched;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'features.insurance_analysis.enabled' => true,
        'services.too.enabled' => true,
        'queue.default' => 'database',
        'queue.connections.database.connection' => 'sqlite',
        'queue.batching.database' => 'sqlite',
    ]);
    Http::preventStrayRequests();
});

function recoverableInsuranceAnalysis(string $provider = 'pottencial'): InsuranceAnalysis
{
    $lead = Lead::query()->create(['nome' => 'Recovery', 'email' => 'recovery@example.test', 'cpf' => '52998224725', 'tipo_locacao' => 'residencial']);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => 1]);
    $analysis = $batch->analyses()->create(['lead_id' => $lead->id, 'provider' => $provider, 'product' => 'fianca_locaticia_residencial', 'status' => 'pending']);
    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'recovery']]);

    return $analysis;
}

function recoveryWorker(): \Illuminate\Queue\Worker
{
    return app('queue.worker')->setCache(app('cache')->store());
}

it('rolls back the package and queued jobs if batch dispatch fails and succeeds on retry', function () {
    $lead = Lead::query()->create(['nome' => 'Dispatch', 'email' => 'dispatch@example.test']);
    $resolver = $this->mock(InsuranceProviderResolver::class);
    $resolver->shouldReceive('availableProviders')->twice()->andReturn(['pottencial', 'too']);
    Event::listen(BatchDispatched::class, function (): void {
        expect(DB::table('jobs')->count())->toBe(2);
        throw new RuntimeException('Dispatch interrupted');
    });
    $job = new StartInsuranceAnalysesBatchJob($lead->id);
    try {
        expect(fn () => $job->handle($resolver))->toThrow(RuntimeException::class, 'Dispatch interrupted');
    } finally {
        Event::forget(BatchDispatched::class);
    }
    expect(InsuranceAnalysisBatch::query()->count())->toBe(0)
        ->and(InsuranceAnalysis::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('job_batches')->count())->toBe(0);

    $job->handle($resolver);
    expect(InsuranceAnalysisBatch::query()->count())->toBe(1)
        ->and(InsuranceAnalysis::query()->count())->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(2)
        ->and(DB::table('job_batches')->count())->toBe(1);
});

it('rejects nontransactional initial dispatch without leaving a package', function () {
    config(['queue.default' => 'redis']);
    $lead = Lead::query()->create(['nome' => 'Dispatch', 'email' => 'dispatch@example.test']);
    $resolver = $this->mock(InsuranceProviderResolver::class);
    $resolver->shouldReceive('availableProviders')->once()->andReturn(['pottencial']);

    expect(fn () => (new StartInsuranceAnalysesBatchJob($lead->id))->handle($resolver))->toThrow(LogicException::class)
        ->and(InsuranceAnalysisBatch::query()->count())->toBe(0)
        ->and(InsuranceAnalysis::query()->count())->toBe(0);
});

it('lets the database worker actually retry three transient failures before finalizing', function () {
    $analysis = recoverableInsuranceAnalysis();
    $provider = Mockery::mock(InsuranceProviderInterface::class);
    $provider->shouldReceive('requestAnalysis')->times(3)->andThrow(new ConnectionException('Temporary outage'));
    $this->mock(InsuranceProviderResolver::class)->shouldReceive('resolve')->times(3)->andReturn($provider);
    RunProviderAnalysisJob::dispatch($analysis->id, 'recovery')->beforeCommit();
    $worker = recoveryWorker();
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $queued = Queue::connection('database')->pop();
        expect($queued)->not->toBeNull();
        expect(fn () => $worker->process('database', $queued, new WorkerOptions))->toThrow(ConnectionException::class);
        expect($analysis->fresh()->status)->toBe($attempt < 3 ? 'processing' : 'failed');
        if ($attempt < 3) {
            expect($analysis->fresh()->finished_at)->toBeNull()
                ->and($analysis->batch->fresh()->finished_at)->toBeNull();
            $this->travel(301)->seconds();
        }
    }
    expect($analysis->fresh()->finished_at)->not->toBeNull()
        ->and($analysis->events()->where('event_type', 'failed')->count())->toBe(1);
    (new CompleteInsuranceAnalysesBatchJob($analysis->insurance_analysis_batch_id, 'recovery'))->handle();
    expect($analysis->batch->fresh()->status)->toBe('completed_with_errors');
});

it('retries transient HTTP responses and recovers without prematurely finishing the batch', function (int $status) {
    $analysis = recoverableInsuranceAnalysis();
    $provider = Mockery::mock(InsuranceProviderInterface::class);
    $provider->shouldReceive('requestAnalysis')->twice()->andReturn(
        ['success' => false, 'http_status' => $status],
        ['success' => true, 'http_status' => 200, 'response' => ['status' => 'Approved', 'quoteId' => 'recovered']]
    );
    $this->mock(InsuranceProviderResolver::class)->shouldReceive('resolve')->twice()->andReturn($provider);
    RunProviderAnalysisJob::dispatch($analysis->id, 'recovery')->beforeCommit();
    $worker = recoveryWorker();
    expect(fn () => $worker->process('database', Queue::connection()->pop(), new WorkerOptions))->toThrow(RuntimeException::class);
    expect($analysis->fresh()->status)->toBe('processing')->and($analysis->fresh()->finished_at)->toBeNull();
    $this->travel(31)->seconds();
    $worker->process('database', Queue::connection()->pop(), new WorkerOptions);
    expect($analysis->fresh()->status)->toBe('approved');
})->with([408, 429, 503]);

it('does not retry invalid requests or a business rejection', function (int $status) {
    Queue::fake();
    $analysis = recoverableInsuranceAnalysis();
    $provider = Mockery::mock(InsuranceProviderInterface::class);
    $provider->shouldReceive('requestAnalysis')->once()->andReturn([
        'success' => $status === 200, 'http_status' => $status, 'response' => ['status' => 'Denied'],
    ]);
    $resolver = $this->mock(InsuranceProviderResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn($provider);
    (new RunProviderAnalysisJob($analysis->id, 'recovery'))->handle($resolver);
    expect($analysis->fresh()->status)->toBe($status === 200 ? 'rejected' : 'failed');
    Queue::assertNotPushed(RunProviderAnalysisJob::class);
})->with([200, 422]);

it('finalizes exhausted jobs without overwriting newer rounds or terminal decisions', function (string $jobClass, string $exceptionClass) {
    Queue::fake();
    $analysis = recoverableInsuranceAnalysis();
    $job = new $jobClass($analysis->id, 'recovery');
    $exception = new $exceptionClass('Worker failed');
    $job->failed($exception);
    $job->failed($exception);
    expect($analysis->fresh()->status)->toBe('failed')
        ->and($analysis->events()->where('event_type', 'failed')->count())->toBe(1);
    Queue::assertPushed(CompleteInsuranceAnalysesBatchJob::class, 1);

    $analysis->update(['status' => 'approved', 'result' => 'approved']);
    $job->failed($exception);
    expect($analysis->fresh()->status)->toBe('approved');
    $analysis->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new']]);
    $analysis->update(['status' => 'pending', 'finished_at' => null]);
    $job->failed($exception);
    expect($analysis->fresh()->status)->toBe('pending')->and($analysis->fresh()->finished_at)->toBeNull();
})->with([RunProviderAnalysisJob::class, SyncProviderAnalysisStatusJob::class, SyncTooAnalysisStatusJob::class])
    ->with([TimeoutExceededException::class, MaxAttemptsExceededException::class]);

it('resumes Too from the saved proposal and credit submission after a status failure', function () {
    Queue::fake();
    $analysis = recoverableInsuranceAnalysis('too');
    $this->mock(TooRentalGuaranteePayloadBuilder::class)->shouldReceive('buildFichaPayload')->once()->andReturn([]);
    $service = $this->mock(TooService::class);
    $service->shouldReceive('registerProposalFicha')->once()->andReturn(['success' => true, 'http_status' => 201, 'response' => ['numeroProposta' => '123', 'numeroFicha' => '456']]);
    $service->shouldReceive('submitCreditAnalysis')->once()->andReturn(['success' => true, 'http_status' => 200]);
    $service->shouldReceive('getProposalStatus')->twice()->andReturn(
        ['success' => false, 'http_status' => null, 'url' => 'https://example.test/status'],
        ['success' => true, 'http_status' => 200, 'response' => ['proposta' => ['status' => 5]]]
    );
    $provider = app(TooInsuranceProvider::class);
    $first = $provider->requestAnalysis($analysis, 'recovery');
    expect($first['retryable'])->toBeTrue()->and($first['http_status'])->toBeNull();
    expect($provider->requestAnalysis($analysis->fresh(), 'recovery')['success'])->toBeTrue();
});

it('finalizes a processing analysis when the worker refuses an exhausted job', function () {
    $analysis = recoverableInsuranceAnalysis();
    $analysis->update(['status' => 'processing']);
    $this->mock(InsuranceProviderResolver::class)->shouldNotReceive('resolve');
    RunProviderAnalysisJob::dispatch($analysis->id, 'recovery')->beforeCommit();
    DB::table('jobs')->update(['attempts' => 10]);
    $queued = Queue::connection()->pop();
    expect(fn () => recoveryWorker()->process('database', $queued, new WorkerOptions))->toThrow(MaxAttemptsExceededException::class)
        ->and($analysis->fresh()->status)->toBe('failed')
        ->and($analysis->fresh()->finished_at)->not->toBeNull();
});

it('resubmits failed credit without registering another Too proposal', function () {
    $analysis = recoverableInsuranceAnalysis('too');
    $this->mock(TooRentalGuaranteePayloadBuilder::class)->shouldReceive('buildFichaPayload')->once()->andReturn([]);
    $service = $this->mock(TooService::class);
    $service->shouldReceive('registerProposalFicha')->once()->andReturn(['success' => true, 'response' => ['numeroFicha' => '123']]);
    $service->shouldReceive('submitCreditAnalysis')->twice()->andReturn(
        ['success' => false, 'http_status' => 503], ['success' => true, 'http_status' => 200]
    );
    $service->shouldReceive('getProposalStatus')->once()->andReturn(['success' => true, 'response' => ['proposta' => ['status' => 5]]]);
    $provider = app(TooInsuranceProvider::class);
    expect($provider->requestAnalysis($analysis, 'recovery')['retryable'])->toBeTrue();
    expect($provider->requestAnalysis($analysis->fresh(), 'recovery')['success'])->toBeTrue();
});

it('consults an already submitted Too reanalysis instead of submitting it again', function () {
    $analysis = recoverableInsuranceAnalysis('too');
    $analysis->update(['proposal_id' => '123', 'response_payload' => ['numeroFicha' => '456']]);
    $this->mock(TooRentalGuaranteePayloadBuilder::class)->shouldReceive('buildBasicDataPayload')->once()->andReturn([]);
    $service = $this->mock(TooService::class);
    $service->shouldReceive('getProposalStatus')->twice()->andReturn(['success' => true, 'response' => ['proposta' => ['status' => 5]]]);
    $service->shouldReceive('updateProposalBasicData')->once()->andReturn(['success' => true]);
    $service->shouldReceive('submitReanalysis')->once()->andReturn(['success' => true]);
    $provider = app(TooInsuranceProvider::class);
    expect($provider->requestReanalysis($analysis, 'recovery')['success'])->toBeTrue()
        ->and($provider->requestReanalysis($analysis->fresh(), 'recovery')['success'])->toBeTrue();
});

it('resumes a persisted Pottencial quote through consultation on a worker retry', function () {
    $analysis = recoverableInsuranceAnalysis();
    $analysis->update(['status' => 'processing', 'quote_id' => 'existing']);
    $provider = Mockery::mock(InsuranceProviderInterface::class);
    $provider->shouldNotReceive('requestAnalysis');
    $provider->shouldReceive('getStatus')->once()->andReturn(['success' => true, 'response' => ['status' => 'Approved', 'quoteId' => 'existing']]);
    $this->mock(InsuranceProviderResolver::class)->shouldReceive('resolve')->once()->andReturn($provider);
    RunProviderAnalysisJob::dispatch($analysis->id, 'recovery')->beforeCommit();
    DB::table('jobs')->update(['attempts' => 1]);
    recoveryWorker()->process('database', Queue::connection()->pop(), new WorkerOptions);
    expect($analysis->fresh()->status)->toBe('approved');
});
