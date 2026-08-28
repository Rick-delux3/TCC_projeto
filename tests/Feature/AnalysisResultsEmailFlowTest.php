<?php

use App\Jobs\ApplyFinalAnalysisTagToLeadLoversJob;
use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\SendAnalysisResultsEmailJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
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
    ]);

    $analysis = InsuranceAnalysis::query()->create([
        'insurance_analysis_batch_id' => $batch->id,
        'lead_id' => $lead->id,
        'provider' => 'pottencial',
        'product' => 'seguro_fianca_residencial',
        'status' => $analysisStatus,
    ]);

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
    $attemptId = 'recoverable-result-email';
    ['batch' => $batch, 'analysis' => $analysis] = analysisResultsEmailFixture($attemptId);
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
