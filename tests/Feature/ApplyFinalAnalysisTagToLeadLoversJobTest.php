<?php

use App\Events\DashboardActivityChanged;
use App\Exceptions\PermanentLeadTagException;
use App\Jobs\ApplyFinalAnalysisTagToLeadLoversJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Models\LeadLoversTagOperation;
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversResultTagService;
use App\Services\LeadLoversTagOperationCoordinator;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const FINAL_ANALYSIS_TAG_API_URL = 'https://leadlovers-final-tag.example.test';
const FINAL_ANALYSIS_TAG_TOKEN = 'final-analysis-fake-token';

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([]);

    config([
        'features.insurance_analysis.enabled' => true,
        'services.leadlovers.enabled' => true,
        'services.leadlovers.api_url' => FINAL_ANALYSIS_TAG_API_URL,
        'services.leadlovers.token' => FINAL_ANALYSIS_TAG_TOKEN,
        'services.leadlovers.tag_confirmation_delay_seconds' => 7,
    ]);
});

/**
 * @return array{lead: Lead, batch: InsuranceAnalysisBatch, analysis: InsuranceAnalysis}
 */
function finalAnalysisTagFixture(string $status = 'approved'): array
{
    $lead = Lead::query()->create([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Lead de análise final',
        'email' => 'final-analysis@example.test',
        'tags_originais' => 'Imobiliária Azul, Ruim, Origem X',
        'leadlovers_lead_id' => 501,
        'leadlovers_status' => 'sent',
        'sent_to_leadlovers_at' => now(),
    ]);

    $batch = InsuranceAnalysisBatch::query()->create([
        'lead_id' => $lead->id,
        'status' => 'completed',
        'total_providers' => 1,
        'completed_providers' => 1,
    ]);

    $analysis = InsuranceAnalysis::query()->create([
        'insurance_analysis_batch_id' => $batch->id,
        'lead_id' => $lead->id,
        'provider' => 'pottencial',
        'product' => 'seguro_fianca_residencial',
        'status' => $status,
    ]);

    return compact('lead', 'batch', 'analysis');
}

function finalAnalysisAttempt(InsuranceAnalysis $analysis, string $attemptId): void
{
    $analysis->events()->create([
        'event_type' => 'analysis_started',
        'status' => 'processing',
        'payload' => ['attempt_id' => $attemptId],
    ]);

    $analysis->events()->create([
        'event_type' => 'email_queued',
        'status' => 'queued',
        'payload' => ['attempt_id' => $attemptId],
    ]);
}

function handleFinalAnalysisTagJob(ApplyFinalAnalysisTagToLeadLoversJob $job): void
{
    $job->handle(
        app(LeadLoversApiClient::class),
        app(LeadLoversResultTagService::class),
        app(LeadLoversTagOperationCoordinator::class),
    );
}

function finalAnalysisTagCatalog(): void
{
    foreach ([
        'aprovados' => [101, 'Aprovados'],
        'ruim' => [102, 'Ruim'],
        'em_negociacao' => [103, 'Em negociação'],
        'fechado_aluguel' => [104, 'Fechado aluguel'],
        'nao_aluguel_nem_seguro' => [105, 'Não aluguei nem seguro'],
    ] as $key => [$id, $title]) {
        LeadLoversTag::query()->create([
            'leadlovers_tag_id' => $id,
            'title' => $title,
            'key' => $key,
            'active' => true,
        ]);
    }
}

function finalAnalysisRemoteTag(int $id, string $name): array
{
    return [
        'id' => $id,
        'name' => $name,
        'linkedAt' => '2026-08-13T12:00:00Z',
    ];
}

it('does not access LeadLovers or dispatch confirmation while analyses are disabled', function () {
    Queue::fake();

    config(['features.insurance_analysis.enabled' => false]);

    (new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: 999999,
        attemptId: 'disabled-attempt',
    ))->handle(
        app(LeadLoversApiClient::class),
        app(LeadLoversResultTagService::class),
        app(LeadLoversTagOperationCoordinator::class),
    );

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('submits one exact bulk mutation and schedules confirmation without changing local tags', function () {
    Queue::fake();
    finalAnalysisTagCatalog();
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'attempt-approved');

    Http::fake([
        FINAL_ANALYSIS_TAG_API_URL.'/leads/501/tags' => Http::response([
            finalAnalysisRemoteTag(900, 'Imobiliária Azul'),
            finalAnalysisRemoteTag(102, 'Ruim'),
        ], 200, ['Content-Type' => 'application/json']),
        FINAL_ANALYSIS_TAG_API_URL.'/leads/tags' => Http::response([
            'actionId' => 7001,
            'status' => 'pending',
            'total' => 1,
        ], 202, ['Content-Type' => 'application/json']),
    ]);

    (new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-approved',
    ))->handle(
        app(LeadLoversApiClient::class),
        app(LeadLoversResultTagService::class),
        app(LeadLoversTagOperationCoordinator::class),
    );

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X')
        ->and($analysis->events()
            ->where('event_type', 'leadlovers_final_tag_applied')
            ->exists())->toBeFalse()
        ->and($analysis->events()
            ->where('event_type', 'leadlovers_final_tag_pending_confirmation')
            ->count())->toBe(1);

    Queue::assertPushed(
        ApplyFinalAnalysisTagToLeadLoversJob::class,
        fn (ApplyFinalAnalysisTagToLeadLoversJob $job): bool => $job->batchId === $batch->id
            && $job->attemptId === 'attempt-approved'
            && $job->phase === 'confirmation'
            && $job->bulkAction === [
                'actionId' => 7001,
                'status' => 'pending',
                'total' => 1,
            ]
    );

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === FINAL_ANALYSIS_TAG_API_URL.'/leads/tags'
            && $request->hasHeader('x-api-token', FINAL_ANALYSIS_TAG_TOKEN)
            && $request->data() === [
                'applyTags' => [101],
                'removeTags' => [102],
                'leadsIds' => [501],
            ];
    });
    Http::assertSentCount(2);
});

it('keeps every accepted terminal-looking bulk status pending until a remote read confirms it', function (string $status) {
    Queue::fake();
    finalAnalysisTagCatalog();
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'attempt-terminal-looking-status');
    $originalTags = $lead->tags_originais;

    Http::fake([
        FINAL_ANALYSIS_TAG_API_URL.'/leads/501/tags' => Http::response([
            finalAnalysisRemoteTag(900, 'Imobiliaria externa'),
            finalAnalysisRemoteTag(102, 'Ruim'),
        ], 200, ['Content-Type' => 'application/json']),
        FINAL_ANALYSIS_TAG_API_URL.'/leads/tags' => Http::response([
            'actionId' => 7002,
            'status' => $status,
            'total' => 1,
        ], 202, ['Content-Type' => 'application/json']),
    ]);

    handleFinalAnalysisTagJob(new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-terminal-looking-status',
    ));

    $operation = LeadLoversTagOperation::query()->sole();

    expect($operation->phase)
        ->toBe(LeadLoversTagOperationCoordinator::PHASE_CONFIRMING)
        ->and($operation->action_status)->toBe($status)
        ->and($lead->fresh()->tags_originais)
        ->toBe($originalTags)
        ->and($analysis->events()
            ->where('event_type', 'leadlovers_final_tag_applied')
            ->exists())->toBeFalse()
        ->and($analysis->events()
            ->where('event_type', 'leadlovers_final_tag_pending_confirmation')
            ->count())->toBe(1);

    Queue::assertPushed(
        ApplyFinalAnalysisTagToLeadLoversJob::class,
        fn (ApplyFinalAnalysisTagToLeadLoversJob $job): bool => $job->phase === 'confirmation'
            && $job->bulkAction === [
                'actionId' => 7002,
                'status' => $status,
                'total' => 1,
            ]
    );
    Http::assertSentCount(2);
})->with(['failed', 'cancelled']);

it('normalizes reordered bulk action properties before persisting a confirmed result', function () {
    Queue::fake();
    finalAnalysisTagCatalog();
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'attempt-approved');

    Http::fake([
        FINAL_ANALYSIS_TAG_API_URL.'/leads/501/tags' => Http::response([
            finalAnalysisRemoteTag(900, 'Imobiliária Azul'),
            finalAnalysisRemoteTag(101, 'Aprovados'),
        ], 200, ['Content-Type' => 'application/json']),
    ]);

    (new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-approved',
        phase: 'confirmation',
        bulkAction: [
            'status' => 'pending',
            'total' => 1,
            'actionId' => 7001,
        ],
    ))->handle(
        app(LeadLoversApiClient::class),
        app(LeadLoversResultTagService::class),
        app(LeadLoversTagOperationCoordinator::class),
    );

    $appliedEvent = $analysis->events()
        ->where('event_type', 'leadlovers_final_tag_applied')
        ->sole();

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Origem X, Aprovados')
        ->and($appliedEvent->status)->toBe('aprovados')
        ->and($appliedEvent->payload)->toMatchArray([
            'attempt_id' => 'attempt-approved',
            'tag_id' => 101,
            'tag_key' => 'aprovados',
            'phase' => 'confirmed',
        ])
        ->and($appliedEvent->response)->toBe([
            'actionId' => 7001,
            'status' => 'pending',
            'total' => 1,
        ]);

    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === FINAL_ANALYSIS_TAG_API_URL.'/leads/501/tags'
    );
    Http::assertSentCount(1);
    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        fn (BroadcastEvent $job): bool => $job->event instanceof DashboardActivityChanged
            && $job->event->resourceId === $lead->id
            && $job->event->change === 'lead.analysis-result.changed',
    );
    Queue::assertNotPushed(ApplyFinalAnalysisTagToLeadLoversJob::class);
});

it('releases a stale confirmation without repeating mutation or changing local tags', function () {
    Queue::fake();
    finalAnalysisTagCatalog();
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'attempt-approved');

    Http::fake([
        FINAL_ANALYSIS_TAG_API_URL.'/leads/501/tags' => Http::response([
            finalAnalysisRemoteTag(900, 'Imobiliária Azul'),
            finalAnalysisRemoteTag(102, 'Ruim'),
        ], 200, ['Content-Type' => 'application/json']),
    ]);

    $job = (new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-approved',
        phase: 'confirmation',
        bulkAction: [
            'actionId' => 7001,
            'status' => 'pending',
            'total' => 1,
        ],
    ))->withFakeQueueInteractions();

    handleFinalAnalysisTagJob($job);

    $job->assertReleased(7);
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X')
        ->and($analysis->events()
            ->where('event_type', 'leadlovers_final_tag_applied')
            ->exists())->toBeFalse();

    Http::assertNotSent(
        fn (Request $request): bool => $request->method() === 'POST'
    );
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
});

it('preserves an accepted action when confirmation is rate limited', function () {
    Queue::fake();
    finalAnalysisTagCatalog();
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'attempt-rate-limited');
    $coordinator = app(LeadLoversTagOperationCoordinator::class);
    $desired = $coordinator->registerAnalysisDesired(
        leadId: $lead->id,
        tagKey: 'aprovados',
        batchId: $batch->id,
        attemptId: 'attempt-rate-limited',
        isReanalysis: false,
    );
    $claimed = $coordinator->claimBeforePost($lead->id, $desired->version);
    $coordinator->markAccepted($lead->id, $claimed->inflight_version, [
        'actionId' => 7701,
        'status' => 'pending',
        'total' => 1,
    ]);
    Http::fake([
        FINAL_ANALYSIS_TAG_API_URL.'/leads/501/tags' => Http::response([
            'error' => 'RATE_LIMITED',
            'message' => 'Tente novamente.',
        ], 429, ['RateLimit-Reset' => '30']),
    ]);
    $job = (new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-rate-limited',
        phase: 'confirmation',
        bulkAction: [
            'actionId' => 7701,
            'status' => 'pending',
            'total' => 1,
        ],
        version: $desired->version,
    ))->withFakeQueueInteractions();

    handleFinalAnalysisTagJob($job);

    $job->assertReleased(30);
    $state = \App\Models\LeadLoversTagOperation::query()->sole();
    expect($state->phase)->toBe(LeadLoversTagOperationCoordinator::PHASE_CONFIRMING)
        ->and($state->inflight_version)->toBe($desired->version)
        ->and($state->action_id)->toBe(7701)
        ->and($state->blocked_reason)->toBeNull()
        ->and($lead->fresh()->tags_originais)->toBe('Imobiliária Azul, Ruim, Origem X');
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
});

it('records one failure when the queue failed callback runs after fail', function () {
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'attempt-with-permanent-failure');
    $lead->forceFill(['leadlovers_lead_id' => null])->save();
    $job = (new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-with-permanent-failure',
    ))->withFakeQueueInteractions();

    handleFinalAnalysisTagJob($job);
    $job->assertFailedWith(PermanentLeadTagException::class);

    $job->failed(new PermanentLeadTagException(
        'Falha entregue novamente pelo callback da fila.'
    ));

    expect($analysis->events()
        ->where('event_type', 'leadlovers_final_tag_failed')
        ->count())->toBe(1);
    Http::assertNothingSent();
});

it('ignores an analysis attempt superseded on the same provider', function () {
    Queue::fake();
    finalAnalysisTagCatalog();
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'older-attempt');
    finalAnalysisAttempt($analysis, 'newer-attempt');

    handleFinalAnalysisTagJob(new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'older-attempt',
    ));

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X')
        ->and($lead->fresh()->analysis_final_tag_key)->toBeNull();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('shares the remote tag overlap lock with a manual job for the same lead', function () {
    ['lead' => $lead, 'batch' => $batch] = finalAnalysisTagFixture();
    $analysisJob = new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-lock',
    );
    $manualJob = new \App\Jobs\ApplyManualLeadResultTagJob(
        leadId: $lead->id,
        result: \App\Support\ManualLeadResultTags::APPROVED,
        corretorId: 1,
    );

    expect($analysisJob->overlapKey())
        ->toBe($manualJob->overlapKey())
        ->and($analysisJob->middleware()[0]->shareKey)->toBeTrue()
        ->and($manualJob->middleware()[0]->shareKey)->toBeTrue();
});

it('does not let an automatic analysis replace a pending manual decision', function () {
    Queue::fake();
    finalAnalysisTagCatalog();
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'attempt-analysis');
    $corretor = \App\Models\Corretor::query()->create([
        'name' => 'Admin manual',
        'email' => 'manual-priority@example.test',
        'password' => 'password',
        'role' => \App\Models\Corretor::ROLE_CEO,
        'active' => true,
    ]);
    $request = \App\Models\CorretorActivityLog::query()->create([
        'corretor_id' => $corretor->id,
        'action' => 'lead_tag_update_requested',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
        'new_values' => ['requested_result' => 'rejected'],
    ]);
    $coordinator = app(LeadLoversTagOperationCoordinator::class);
    $manual = $coordinator->registerManualDesired(
        $lead->id,
        'ruim',
        'rejected',
        $request->id,
        $corretor->id,
    );

    handleFinalAnalysisTagJob(new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-analysis',
    ));

    $state = \App\Models\LeadLoversTagOperation::query()->sole();
    expect($state->version)->toBe($manual->version)
        ->and($state->desired_source)->toBe('manual')
        ->and($state->desired_tag_key)->toBe('ruim')
        ->and($lead->fresh()->analysis_final_tag_key)->toBeNull();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('cannot block or consume an accepted manual operation', function () {
    Queue::fake();
    finalAnalysisTagCatalog();
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'attempt-analysis');
    $corretor = \App\Models\Corretor::query()->create([
        'name' => 'Admin manual em voo',
        'email' => 'manual-inflight@example.test',
        'password' => 'password',
        'role' => \App\Models\Corretor::ROLE_CEO,
        'active' => true,
    ]);
    $request = \App\Models\CorretorActivityLog::query()->create([
        'corretor_id' => $corretor->id,
        'action' => 'lead_tag_update_requested',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
        'new_values' => ['requested_result' => 'rejected'],
    ]);
    $coordinator = app(LeadLoversTagOperationCoordinator::class);
    $manual = $coordinator->registerManualDesired(
        $lead->id,
        'ruim',
        'rejected',
        $request->id,
        $corretor->id,
    );
    $coordinator->claimBeforePost($lead->id, $manual->version);
    $coordinator->markAccepted($lead->id, $manual->version, [
        'actionId' => 8801,
        'status' => 'pending',
        'total' => 1,
    ]);

    $job = (new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-analysis',
    ))->withFakeQueueInteractions();
    handleFinalAnalysisTagJob($job);
    $job->failed(new PermanentLeadTagException('Falha automática simulada.'));

    $state = \App\Models\LeadLoversTagOperation::query()->sole();
    expect($state->version)->toBe($manual->version)
        ->and($state->phase)->toBe(LeadLoversTagOperationCoordinator::PHASE_CONFIRMING)
        ->and($state->inflight_source)->toBe('manual')
        ->and($state->inflight_request_log_id)->toBe($request->id)
        ->and($state->action_id)->toBe(8801)
        ->and($state->blocked_reason)->toBeNull()
        ->and($analysis->events()
            ->where('event_type', 'leadlovers_final_tag_failed')
            ->count())->toBe(1);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('drains an accepted analysis before allowing its superseding analysis to post', function () {
    Queue::fake();
    finalAnalysisTagCatalog();
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'attempt-a');

    $getResponses = [
        [
            finalAnalysisRemoteTag(900, 'Imobiliária Azul'),
            finalAnalysisRemoteTag(102, 'Ruim'),
        ],
        [finalAnalysisRemoteTag(900, 'Imobiliária Azul')],
        [
            finalAnalysisRemoteTag(900, 'Imobiliária Azul'),
            finalAnalysisRemoteTag(101, 'Aprovados'),
        ],
        [
            finalAnalysisRemoteTag(900, 'Imobiliária Azul'),
            finalAnalysisRemoteTag(101, 'Aprovados'),
        ],
    ];
    $nextGet = 0;
    $nextActionId = 9001;

    Http::fake(function (Request $request) use (
        &$getResponses,
        &$nextGet,
        &$nextActionId
    ) {
        if ($request->method() === 'GET') {
            $response = $getResponses[$nextGet] ?? null;
            $nextGet++;

            expect($response)->not->toBeNull();

            return Http::response($response, 200);
        }

        expect($request->method())->toBe('POST')
            ->and($request->url())
            ->toBe(FINAL_ANALYSIS_TAG_API_URL.'/leads/tags');

        return Http::response([
            'actionId' => $nextActionId++,
            'status' => 'pending',
            'total' => 1,
        ], 202);
    });

    handleFinalAnalysisTagJob(new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-a',
    ));

    $stateA = LeadLoversTagOperation::query()->sole();
    expect($stateA->phase)
        ->toBe(LeadLoversTagOperationCoordinator::PHASE_CONFIRMING)
        ->and($stateA->inflight_attempt_id)->toBe('attempt-a')
        ->and($stateA->inflight_tag_key)->toBe('aprovados');

    $analysis->forceFill(['status' => 'rejected'])->save();
    finalAnalysisAttempt($analysis, 'attempt-b');

    handleFinalAnalysisTagJob(new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-b',
    ));

    expect(collect(Http::recorded())
        ->filter(fn (array $record): bool => $record[0]->method() === 'POST')
        ->pluck('0')
        ->map(fn (Request $request): array => $request->data())
        ->values()
        ->all())->toBe([[
            'applyTags' => [101],
            'removeTags' => [102],
            'leadsIds' => [501],
        ]]);

    handleFinalAnalysisTagJob(new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-b',
        phase: 'confirmation',
        bulkAction: [
            'actionId' => 9001,
            'status' => 'pending',
            'total' => 1,
        ],
    ));

    $drained = LeadLoversTagOperation::query()->sole();
    expect($drained->desired_attempt_id)->toBe('attempt-b')
        ->and($drained->desired_tag_key)->toBe('ruim')
        ->and($drained->inflight_version)->toBeNull()
        ->and($drained->phase)
        ->toBe(LeadLoversTagOperationCoordinator::PHASE_PENDING)
        ->and(collect(Http::recorded())
            ->filter(fn (array $record): bool => $record[0]->method() === 'POST')
            ->count())->toBe(1);

    handleFinalAnalysisTagJob(new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-b',
    ));

    $postPayloads = collect(Http::recorded())
        ->filter(fn (array $record): bool => $record[0]->method() === 'POST')
        ->map(fn (array $record): array => $record[0]->data())
        ->values()
        ->all();

    expect($postPayloads)->toBe([
        [
            'applyTags' => [101],
            'removeTags' => [102],
            'leadsIds' => [501],
        ],
        [
            'applyTags' => [102],
            'removeTags' => [101],
            'leadsIds' => [501],
        ],
    ]);
});

it('blocks a superseding analysis while an accepted predecessor never confirms', function () {
    Queue::fake();
    finalAnalysisTagCatalog();
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'attempt-a');

    Http::fake([
        FINAL_ANALYSIS_TAG_API_URL.'/leads/501/tags' => Http::sequence()
            ->push([
                finalAnalysisRemoteTag(900, 'Imobiliária Azul'),
                finalAnalysisRemoteTag(102, 'Ruim'),
            ], 200)
            ->push([
                finalAnalysisRemoteTag(900, 'Imobiliária Azul'),
            ], 200),
        FINAL_ANALYSIS_TAG_API_URL.'/leads/tags' => Http::response([
            'actionId' => 9101,
            'status' => 'pending',
            'total' => 1,
        ], 202),
    ]);

    handleFinalAnalysisTagJob(new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-a',
    ));

    $analysis->forceFill(['status' => 'rejected'])->save();
    finalAnalysisAttempt($analysis, 'attempt-b');
    $coordinator = app(LeadLoversTagOperationCoordinator::class);
    $stateB = $coordinator->registerAnalysisDesired(
        leadId: $lead->id,
        tagKey: 'ruim',
        batchId: $batch->id,
        attemptId: 'attempt-b',
        isReanalysis: true,
    );
    $confirmation = (new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-b',
        isReanalysis: true,
        phase: 'confirmation',
        bulkAction: [
            'actionId' => 9101,
            'status' => 'pending',
            'total' => 1,
        ],
    ))->withFakeQueueInteractions();
    $confirmation->tries = 1;

    handleFinalAnalysisTagJob($confirmation);

    $state = LeadLoversTagOperation::query()->sole();
    $postPayloads = collect(Http::recorded())
        ->filter(fn (array $record): bool => $record[0]->method() === 'POST')
        ->map(fn (array $record): array => $record[0]->data())
        ->values()
        ->all();

    expect($state->version)->toBe($stateB->version)
        ->and($state->desired_attempt_id)->toBe('attempt-b')
        ->and($state->desired_tag_key)->toBe('ruim')
        ->and($state->inflight_attempt_id)->toBe('attempt-a')
        ->and($state->phase)
        ->toBe(LeadLoversTagOperationCoordinator::PHASE_BLOCKED)
        ->and($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X')
        ->and($postPayloads)->toBe([[
            'applyTags' => [101],
            'removeTags' => [102],
            'leadsIds' => [501],
        ]]);
});

it('recovers a posting crash through reads without repeating the mutation', function () {
    Queue::fake();
    finalAnalysisTagCatalog();
    ['lead' => $lead, 'batch' => $batch, 'analysis' => $analysis] = finalAnalysisTagFixture();
    finalAnalysisAttempt($analysis, 'attempt-crash');
    $coordinator = app(LeadLoversTagOperationCoordinator::class);
    $desired = $coordinator->registerAnalysisDesired(
        leadId: $lead->id,
        tagKey: 'aprovados',
        batchId: $batch->id,
        attemptId: 'attempt-crash',
        isReanalysis: false,
    );
    $claimed = $coordinator->claimBeforePost(
        $lead->id,
        $desired->version
    );

    expect($claimed)->not->toBeNull()
        ->and($claimed->phase)
        ->toBe(LeadLoversTagOperationCoordinator::PHASE_POSTING);

    Http::fake([
        FINAL_ANALYSIS_TAG_API_URL.'/leads/501/tags' => Http::sequence()
            ->push([
                finalAnalysisRemoteTag(900, 'Imobiliária Azul'),
                finalAnalysisRemoteTag(102, 'Ruim'),
            ], 200)
            ->push([
                finalAnalysisRemoteTag(900, 'Imobiliária Azul'),
                finalAnalysisRemoteTag(101, 'Aprovados'),
            ], 200),
    ]);

    handleFinalAnalysisTagJob(new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-crash',
    ));

    $recovering = LeadLoversTagOperation::query()->sole();
    expect($recovering->phase)
        ->toBe(LeadLoversTagOperationCoordinator::PHASE_CONFIRMING)
        ->and($recovering->outcome_uncertain)->toBeTrue()
        ->and($recovering->inflight_version)->toBe($desired->version);
    Http::assertNotSent(
        fn (Request $request): bool => $request->method() === 'POST'
    );
    Queue::assertPushed(
        ApplyFinalAnalysisTagToLeadLoversJob::class,
        fn (ApplyFinalAnalysisTagToLeadLoversJob $job): bool => $job->attemptId === 'attempt-crash'
            && $job->phase === 'confirmation'
            && $job->bulkAction === null
    );

    handleFinalAnalysisTagJob(new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'attempt-crash',
        phase: 'confirmation',
    ));

    $synced = LeadLoversTagOperation::query()->sole();
    expect($synced->phase)
        ->toBe(LeadLoversTagOperationCoordinator::PHASE_SYNCED)
        ->and($synced->inflight_version)->toBeNull()
        ->and($lead->fresh()->tags_originais)
        ->toContain('Aprovados')
        ->not->toContain('Ruim');
    Http::assertSentCount(2);
    Http::assertNotSent(
        fn (Request $request): bool => $request->method() === 'POST'
    );
});
