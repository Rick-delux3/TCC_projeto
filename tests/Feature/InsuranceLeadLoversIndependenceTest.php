<?php

use App\Events\DashboardActivityChanged;
use App\Jobs\ApplyFinalAnalysisTagToLeadLoversJob;
use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\SendAnalysisResultsEmailJob;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'features.insurance_analysis.enabled' => true,
        'services.leadlovers.enabled' => true,
        'services.leadlovers.api_url' => 'https://independent-leads.example.test',
        'services.leadlovers.token' => 'independence-test',
        'services.leadlovers.machine' => 456,
        'services.leadlovers.sequence_2' => 654,
        'services.leadlovers.step' => 2,
    ]);
    Http::preventStrayRequests();
    Queue::fake();
    Event::fake([DashboardActivityChanged::class]);
});

function independentAnalysisBatch(array $statuses): InsuranceAnalysisBatch
{
    $lead = Lead::query()->create([
        'nome' => 'Local result', 'email' => 'local@example.test', 'tipo_solicitante' => 'locatario',
        'origem' => 'locatario', 'leadlovers_status' => 'pending', 'tags_originais' => 'Locatario, Origem X, Imobiliaria Aprovados',
        'reanalysis_unlocked_at' => now(),
    ]);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => count($statuses)]);
    foreach ($statuses as $index => $status) {
        $analysis = $batch->analyses()->create(['lead_id' => $lead->id, 'provider' => 'company-'.$index, 'product' => 'fianca_locaticia_residencial', 'status' => $status]);
        $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'local']]);
    }

    return $batch;
}

it('consolidates results locally even without remote identifiers or enabled integration', function (array $statuses, string $result, bool $enabled) {
    config(['services.leadlovers.enabled' => $enabled]);
    $batch = independentAnalysisBatch($statuses);
    $job = new CompleteInsuranceAnalysesBatchJob($batch->id, 'local');
    $job->handle();
    $firstFinalizedAt = $batch->lead->fresh()->analysis_finalized_at;
    $job->handle();
    $lead = $batch->lead->fresh();
    expect($lead->analysis_final_status)->toBe($result)
        ->and($lead->analysis_finalized_at)->not->toBeNull()
        ->and($lead->analysis_finalized_at)->toBe($firstFinalizedAt)
        ->and($lead->leadlovers_lead_id)->toBeNull()
        ->and($lead->canRequestGeneralReanalysis())->toBe($result !== 'failed')
        ->and($lead->leadlovers_confirmed_final_tag_key)->toBeNull();
    Queue::assertPushed(SendAnalysisResultsEmailJob::class, 1);
    if ($enabled && $result !== 'failed') {
        Queue::assertPushed(SendLeadToLeadLoversJob::class, 1);
    } else {
        Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
    }
    expect($lead->tags_originais)->toContain('Origem X', 'Imobiliaria Aprovados');
    Http::assertNothingSent();
})->with([
    'approval wins' => [['approved', 'failed'], 'approved'],
    'unanimous refusal' => [['rejected', 'rejected'], 'rejected'],
    'technical failure' => [['rejected', 'failed'], 'failed'],
])->with([false, true]);

it('waits for every company before consolidating or creating a remote lead', function () {
    $batch = independentAnalysisBatch(['approved', 'processing']);
    (new CompleteInsuranceAnalysesBatchJob($batch->id, 'local'))->handle();
    app()->call([(new SendLeadToLeadLoversJob($batch->lead_id)), 'handle']);
    expect($batch->lead->fresh()->analysis_final_status)->toBeNull()
        ->and($batch->fresh()->finished_at)->toBeNull();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('queues tag synchronization separately for a lead with a remote ID even if email already finished', function () {
    $batch = independentAnalysisBatch(['approved']);
    $batch->lead->update(['leadlovers_lead_id' => 501]);
    $batch->analyses()->first()->events()->create(['event_type' => 'email_sent', 'payload' => ['attempt_id' => 'local']]);
    (new CompleteInsuranceAnalysesBatchJob($batch->id, 'local'))->handle();
    Queue::assertPushed(ApplyFinalAnalysisTagToLeadLoversJob::class, 1);
    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
    Queue::assertNotPushed(SendAnalysisResultsEmailJob::class);
    expect($batch->lead->fresh()->analysis_final_status)->toBe('approved');
});

it('preserves local completion and email dispatch when enqueueing the integration fails', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite']);
    Queue::fake([SendAnalysisResultsEmailJob::class]);
    $batch = independentAnalysisBatch(['rejected']);
    \Illuminate\Queue\Queue::createPayloadUsing(function ($connection, $queue, $payload): array {
        if ($payload['displayName'] === SendLeadToLeadLoversJob::class) {
            throw new RuntimeException('Integration queue unavailable');
        }

        return [];
    });
    try {
        expect(fn () => (new CompleteInsuranceAnalysesBatchJob($batch->id, 'local'))->handle())->toThrow(RuntimeException::class);
    } finally {
        \Illuminate\Queue\Queue::createPayloadUsing(null);
    }
    expect($batch->fresh()->status)->toBe('completed')
        ->and($batch->lead->fresh()->analysis_final_status)->toBe('rejected')
        ->and($batch->analyses()->first()->events()->where('event_type', 'leadlovers_final_sync_queued')->count())->toBe(0);
    Queue::assertPushed(SendAnalysisResultsEmailJob::class, 1);
    (new CompleteInsuranceAnalysesBatchJob($batch->id, 'local'))->handle();
    expect($batch->analyses()->first()->events()->where('event_type', 'leadlovers_final_sync_queued')->count())->toBe(1);
});

it('creates the remote lead with the final tag and keeps local approval through a LeadLovers outage', function (bool $outage) {
    foreach (['locatario' => 123, 'aprovados' => 101] as $key => $id) {
        LeadLoversTag::query()->create(['key' => $key, 'title' => $key, 'leadlovers_tag_id' => $id, 'active' => true]);
    }
    $batch = independentAnalysisBatch(['approved']);
    (new CompleteInsuranceAnalysesBatchJob($batch->id, 'local'))->handle();
    Http::fake([
        'https://independent-leads.example.test/leads/' => Http::response($outage ? [] : ['success' => true, 'leadId' => 501], $outage ? 503 : 200),
        'https://independent-leads.example.test/leads/move' => Http::response(['actionId' => 9001, 'status' => 'pending', 'total' => 1], 202),
    ]);
    $job = (new SendLeadToLeadLoversJob($batch->lead_id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    Http::assertSent(fn ($request) => $request->url() === 'https://independent-leads.example.test/leads/' && $request['tags'] === [123, 101]);
    $lead = $batch->lead->fresh();
    expect($lead->analysis_final_status)->toBe('approved')
        ->and($lead->tags_originais)->toContain('Aprovado')
        ->and($lead->canRequestGeneralReanalysis())->toBeTrue()
        ->and($lead->leadlovers_confirmed_final_tag_key)->toBeNull();
    if (! $outage) {
        Queue::assertPushed(ApplyFinalAnalysisTagToLeadLoversJob::class, 1);
        expect($lead->leadlovers_status)->toBe('processing');
    }
})->with([false, true]);

it('discards old completion without replacing a newer local result', function () {
    $batch = independentAnalysisBatch(['approved']);
    (new CompleteInsuranceAnalysesBatchJob($batch->id, 'local'))->handle();
    $analysis = $batch->analyses()->first();
    $analysis->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new']]);
    $analysis->update(['status' => 'rejected']);
    (new CompleteInsuranceAnalysesBatchJob($batch->id, 'new', true))->handle();
    (new CompleteInsuranceAnalysesBatchJob($batch->id, 'local'))->handle();
    expect($batch->lead->fresh()->analysis_final_status)->toBe('rejected')
        ->and($batch->lead->fresh()->tags_originais)->toContain('Recusado')->not->toContain(', Aprovado,');
});

it('keeps a local result when the final remote tag is not configured', function () {
    LeadLoversTag::query()->create(['key' => 'locatario', 'title' => 'Locatario', 'leadlovers_tag_id' => 123, 'active' => true]);
    $batch = independentAnalysisBatch(['approved']);
    (new CompleteInsuranceAnalysesBatchJob($batch->id, 'local'))->handle();
    app()->call([(new SendLeadToLeadLoversJob($batch->lead_id)), 'handle']);
    expect($batch->lead->fresh()->analysis_final_status)->toBe('approved')
        ->and($batch->lead->fresh()->leadlovers_status)->toBe('tag_failed')
        ->and($batch->lead->fresh()->canRequestGeneralReanalysis())->toBeTrue();
    Http::assertNothingSent();
});

it('requests remote creation instead of failing a final tag job without a remote ID', function () {
    $batch = independentAnalysisBatch(['rejected']);
    (new CompleteInsuranceAnalysesBatchJob($batch->id, 'local'))->handle();
    Queue::fake();
    $job = (new ApplyFinalAnalysisTagToLeadLoversJob($batch->id, 'local'))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertNotFailed();
    Queue::assertPushed(SendLeadToLeadLoversJob::class, 1);
    expect($batch->lead->fresh()->analysis_final_status)->toBe('rejected');
    Http::assertNothingSent();
});

it('finishes an existing remote send while a new analysis is pending without creating another lead', function () {
    $batch = independentAnalysisBatch(['processing']);
    $batch->lead->update([
        'leadlovers_status' => 'processing', 'leadlovers_lead_id' => 501,
        'leadlovers_response' => ['phase' => 'machine_confirmation_pending'],
    ]);
    Http::fake([
        'https://independent-leads.example.test/leads/501/machines' => Http::response([[
            'id' => 456, 'name' => 'Machine', 'type' => 1, 'level' => 2,
            'registerDate' => '2026-08-11T12:00:00Z', 'status' => 'active',
            'sequence' => ['id' => 654, 'name' => 'Sequence'],
        ]]),
    ]);
    $job = (new SendLeadToLeadLoversJob($batch->lead_id))->withFakeQueueInteractions();
    $job->job->attempts = 2;
    app()->call([$job, 'handle']);
    expect($batch->lead->fresh()->leadlovers_status)->toBe('sent')
        ->and($batch->lead->fresh()->analysis_final_status)->toBeNull();
    Queue::assertNotPushed(ApplyFinalAnalysisTagToLeadLoversJob::class);
    Http::assertSentCount(1);
});

it('releases an unstarted remote request while a new analysis is pending', function () {
    $batch = independentAnalysisBatch(['processing']);
    $batch->lead->update(['leadlovers_status' => 'processing', 'leadlovers_response' => ['phase' => 'ready_to_create']]);
    $job = (new SendLeadToLeadLoversJob($batch->lead_id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertReleased();
    Http::assertNothingSent();
});
