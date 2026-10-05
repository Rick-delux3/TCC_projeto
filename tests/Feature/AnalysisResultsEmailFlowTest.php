<?php

use App\Jobs\ApplyFinalAnalysisTagToLeadLoversJob;
use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\SendAnalysisResultsEmailJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['features.insurance_analysis.enabled' => true]);
});

/**
 * @return array{lead: Lead, batch: InsuranceAnalysisBatch, analysis: InsuranceAnalysis}
 */
function analysisResultsEmailFixture(
    string $attemptId,
    string $email = 'analysis-recipient@example.test',
    string $analysisStatus = 'approved'
): array {
    $lead = Lead::query()->create([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Destinatário da análise',
        'email' => $email,
    ]);

    $batch = InsuranceAnalysisBatch::query()->create([
        'lead_id' => $lead->id,
        'status' => 'completed',
        'total_providers' => 1,
        'completed_providers' => 1,
        'email_status' => 'pending',
        'finished_at' => now(),
    ]);

    $analysis = InsuranceAnalysis::query()->create([
        'insurance_analysis_batch_id' => $batch->id,
        'lead_id' => $lead->id,
        'provider' => 'pottencial',
        'product' => 'seguro_fianca_residencial',
        'status' => $analysisStatus,
    ]);

    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => $attemptId]]);
    $analysis->events()->create([
        'event_type' => 'analysis_completed',
        'status' => $analysisStatus,
        'payload' => [
            'attempt_id' => $attemptId,
            'rent_amount' => 1500,
            'charges_amount' => 250,
            'total_monthly_amount' => 1750,
        ],
        'response' => ['provider_status' => $analysisStatus],
    ]);

    return compact('lead', 'batch', 'analysis');
}

it('queues one completion per active attempt and allows a new queue after terminal failure', function () {
    Queue::fake();
    config(['services.leadlovers.enabled' => true]);
    $attemptId = 'recoverable-result-email';
    ['batch' => $batch, 'analysis' => $analysis] = analysisResultsEmailFixture($attemptId);
    $batch->lead->update(['leadlovers_lead_id' => 501]);
    $job = new CompleteInsuranceAnalysesBatchJob($batch->id, $attemptId);

    $job->handle();
    $job->handle();

    Queue::assertPushed(ApplyFinalAnalysisTagToLeadLoversJob::class, 1);
    Queue::assertPushed(SendAnalysisResultsEmailJob::class, 1);
    expect($analysis->events()->where('event_type', 'email_queued')->count())->toBe(1)
        ->and($batch->fresh()->email_status)->toBe('queued');

    $analysis->events()->create([
        'event_type' => 'email_failed',
        'status' => 'failed',
        'message' => 'Falha terminal segura.',
        'payload' => ['attempt_id' => $attemptId],
    ]);

    $job->handle();

    Queue::assertPushed(ApplyFinalAnalysisTagToLeadLoversJob::class, 1);
    Queue::assertPushed(SendAnalysisResultsEmailJob::class, 2);
    expect($analysis->events()->where('event_type', 'email_queued')->count())->toBe(2);
});

it('defers an already queued email without sending while analyses are disabled', function () {
    Mail::fake();
    $attemptId = 'disabled-result-email';
    ['batch' => $batch, 'analysis' => $analysis] = analysisResultsEmailFixture($attemptId);
    $analysis->events()->create([
        'event_type' => 'email_queued',
        'status' => 'queued',
        'payload' => ['attempt_id' => $attemptId],
    ]);
    $batch->update(['email_status' => 'queued']);
    config(['features.insurance_analysis.enabled' => false]);

    (new SendAnalysisResultsEmailJob($batch->id, $attemptId))->handle();

    Mail::assertNothingSent();
    expect($analysis->events()->where('event_type', 'email_deferred')->count())->toBe(1)
        ->and($batch->fresh()->email_status)->toBe('pending')
        ->and($analysis->events()->where('event_type', 'email_sent')->exists())->toBeFalse();
});

it('rejects an invalid fallback recipient and releases the attempt after the final try', function () {
    Mail::fake();
    Queue::fake();
    $attemptId = 'invalid-result-recipient';
    ['batch' => $batch, 'analysis' => $analysis] = analysisResultsEmailFixture(
        $attemptId,
        'invalid-address'
    );
    $analysis->events()->create([
        'event_type' => 'email_queued',
        'status' => 'queued',
        'payload' => ['attempt_id' => $attemptId],
    ]);
    $batch->update(['email_status' => 'queued']);
    $emailJob = new SendAnalysisResultsEmailJob($batch->id, $attemptId);
    $emailJob->tries = 1;

    expect(fn () => $emailJob->handle())
        ->toThrow(RuntimeException::class, 'Nenhum destinatário válido');

    Mail::assertNothingSent();
    expect($analysis->events()->where('event_type', 'email_failed')->count())->toBe(1)
        ->and($batch->fresh()->email_status)->toBe('failed');

    (new CompleteInsuranceAnalysesBatchJob($batch->id, $attemptId))->handle();

    Queue::assertNotPushed(ApplyFinalAnalysisTagToLeadLoversJob::class);
    Queue::assertPushed(SendAnalysisResultsEmailJob::class, 1);
    expect($analysis->events()->where('event_type', 'email_queued')->count())->toBe(2);
});

it('does not expose provider exception details in the external email body', function () {
    $attemptId = 'sanitized-result-message';
    ['batch' => $batch, 'analysis' => $analysis] = analysisResultsEmailFixture(
        $attemptId,
        analysisStatus: 'failed'
    );
    $secretTechnicalMessage = 'RESEND_API_KEY=re_secret_internal provider endpoint timeout';
    $analysis->update(['error_message' => $secretTechnicalMessage]);
    $event = $analysis->events()->where('event_type', 'analysis_completed')->firstOrFail();
    $event->setRelation('analysis', $analysis->fresh());
    $batch->setRelation('lead', $batch->lead);
    $method = new ReflectionMethod(SendAnalysisResultsEmailJob::class, 'buildMessage');

    $body = $method->invoke(
        new SendAnalysisResultsEmailJob($batch->id, $attemptId),
        $batch,
        collect([$event])
    );

    expect($body)
        ->toContain('Não foi possível concluir esta consulta.')
        ->not->toContain($secretTechnicalMessage)
        ->not->toContain('Erro técnico:');
});

it('does not overwrite the new email state with an old failure or deferred job', function () {
    Mail::fake();
    ['batch' => $batch, 'analysis' => $analysis] = analysisResultsEmailFixture('old-email');
    $analysis->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new-email']]);
    $batch->update(['status' => 'processing', 'email_status' => 'pending']);
    $job = new SendAnalysisResultsEmailJob($batch->id, 'old-email');

    $method = new ReflectionMethod(SendAnalysisResultsEmailJob::class, 'recordTerminalFailure');
    expect(fn () => $method->invoke($job, $batch, 'Old failure', []))
        ->toThrow(\App\Exceptions\ObsoleteInsuranceAnalysisAttempt::class);
    $job->failed(new RuntimeException('Old worker failure'));
    config(['features.insurance_analysis.enabled' => false]);
    $job->handle();
    expect($batch->fresh()->email_status)->toBe('pending')
        ->and($batch->fresh()->email_error)->toBeNull()
        ->and($analysis->events()->whereIn('event_type', ['email_failed', 'email_deferred'])->count())->toBe(0);
    Mail::assertNothingSent();
});

it('dispatches the results job only after the enclosing transaction commits', function () {
    Queue::fake();
    config(['services.leadlovers.enabled' => false]);
    ['batch' => $batch] = analysisResultsEmailFixture('committed-results');

    DB::transaction(function () use ($batch): void {
        (new CompleteInsuranceAnalysesBatchJob($batch->id, 'committed-results', true))->handle();

        expect($batch->lead->fresh()->analysis_final_status)->toBe('approved');
        Queue::assertNotPushed(SendAnalysisResultsEmailJob::class);
    });

    Queue::assertPushed(SendAnalysisResultsEmailJob::class, fn ($job) => $job->batchId === $batch->id
        && $job->attemptId === 'committed-results' && $job->isReanalysis);
    expect($batch->fresh()->finished_at)->not->toBeNull();
});

it('does not dispatch results or preserve completion when the enclosing transaction rolls back', function () {
    Queue::fake();
    config(['services.leadlovers.enabled' => false]);
    ['batch' => $batch, 'analysis' => $analysis] = analysisResultsEmailFixture('rolled-back-results');
    $batch->update(['status' => 'processing', 'finished_at' => null]);

    expect(fn () => DB::transaction(function () use ($batch): void {
        (new CompleteInsuranceAnalysesBatchJob($batch->id, 'rolled-back-results'))->handle();
        throw new RuntimeException('Rollback completion');
    }))->toThrow(RuntimeException::class, 'Rollback completion');

    Queue::assertNothingPushed();
    expect($batch->fresh()->status)->toBe('processing')
        ->and($batch->fresh()->finished_at)->toBeNull()
        ->and($batch->lead->fresh()->analysis_final_status)->toBeNull()
        ->and($analysis->events()->where('event_type', 'email_queued')->exists())->toBeFalse();
});

it('discards a completion dispatch if a new round starts before the outer commit', function () {
    Queue::fake();
    config(['services.leadlovers.enabled' => false]);
    ['batch' => $batch, 'analysis' => $analysis] = analysisResultsEmailFixture('superseded-results');

    DB::transaction(function () use ($batch, $analysis): void {
        (new CompleteInsuranceAnalysesBatchJob($batch->id, 'superseded-results'))->handle();
        $analysis->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new-results']]);
        $analysis->update(['status' => 'pending']);
        $batch->update(['status' => 'processing', 'finished_at' => null, 'email_status' => 'pending']);
    });

    Queue::assertNotPushed(SendAnalysisResultsEmailJob::class);
    expect($batch->fresh()->email_status)->toBe('pending')
        ->and($analysis->events()->where('event_type', 'email_queued')->exists())->toBeFalse();
});

it('recovers a results dispatch failure after commit without losing the local decision', function () {
    $events = Event::getFacadeRoot();
    Event::fake([\App\Events\DashboardActivityChanged::class]);
    config([
        'services.leadlovers.enabled' => false,
        'queue.default' => 'database',
        'queue.connections.database.connection' => 'sqlite',
    ]);
    ['batch' => $batch, 'analysis' => $analysis] = analysisResultsEmailFixture('retry-results-dispatch');
    $job = new CompleteInsuranceAnalysesBatchJob($batch->id, 'retry-results-dispatch');
    Event::listen(JobQueueing::class, function (JobQueueing $event) use ($batch): void {
        if ($event->job instanceof SendAnalysisResultsEmailJob) {
            expect($batch->fresh()->email_status)->toBe('queued')
                ->and($batch->lead->fresh()->analysis_final_status)->toBe('approved');
            throw new RuntimeException('Queue unavailable');
        }
    });

    try {
        expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Queue unavailable');
    } finally {
        $events->forget(JobQueueing::class);
    }

    expect($batch->fresh()->status)->toBe('completed')
        ->and($batch->fresh()->finished_at)->not->toBeNull()
        ->and($batch->fresh()->email_status)->toBe('failed')
        ->and($batch->lead->fresh()->analysis_final_status)->toBe('approved')
        ->and($analysis->events()->where('event_type', 'email_failed')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);

    $job->handle();
    $job->handle();

    expect($batch->fresh()->email_status)->toBe('queued')
        ->and($batch->fresh()->email_failed_at)->toBeNull()
        ->and($batch->fresh()->email_error)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($analysis->events()->where('event_type', 'local_result_consolidated')->count())->toBe(1);
});
