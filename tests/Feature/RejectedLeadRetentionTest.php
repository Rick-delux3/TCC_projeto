<?php

use App\Events\DashboardActivityChanged;
use App\Exceptions\LeadLoversApiException;
use App\Jobs\ApplyFinalAnalysisTagToLeadLoversJob;
use App\Jobs\ApplyManualLeadResultTagJob;
use App\Jobs\DeleteExpiredRejectedLeadJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use App\Models\Lead;
use App\Models\LeadConjugues;
use App\Models\LeadDespesas;
use App\Models\LeadEnderecos;
use App\Models\LeadImobiliariaInformada;
use App\Models\LeadLocadores;
use App\Models\LeadLoversTag;
use App\Models\LeadLoversTagOperation;
use App\Models\LeadRetentionEvent;
use App\Models\User;
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversResultTagService;
use App\Services\LeadLoversTagOperationCoordinator;
use App\Services\RejectedLeadRetentionService;
use App\Support\ManualLeadResultTags;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

const REJECTED_RETENTION_API_URL = 'https://leadlovers-retention.example.test';
const REJECTED_RETENTION_TOKEN = 'retention-test-token';

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-15T12:00:00Z'));
    Http::preventStrayRequests();
    Http::fake([]);

    config([
        'features.insurance_analysis.enabled' => true,
        'services.leadlovers.enabled' => true,
        'services.leadlovers.api_url' => REJECTED_RETENTION_API_URL,
        'services.leadlovers.token' => REJECTED_RETENTION_TOKEN,
        'services.leadlovers.requests_per_minute' => 90,
        'services.leadlovers.rate_limit_window_seconds' => 60,
        'services.leadlovers.rate_limit_retry_seconds' => 60,
        'services.leadlovers.rate_limit_max_retry_seconds' => 900,
        'services.leadlovers.tag_confirmation_delay_seconds' => 1,
    ]);

    RateLimiter::clear(rejectedRetentionLimiterKey());
    rejectedRetentionCatalog();
});

afterEach(function () {
    RateLimiter::clear(rejectedRetentionLimiterKey());
    Carbon::setTestNow();
});

function rejectedRetentionLimiterKey(): string
{
    return 'leadlovers:requests:'.hash(
        'sha256',
        (string) config('services.leadlovers.token')
    );
}

function rejectedRetentionCatalog(): void
{
    foreach ([
        'aprovados' => [101, 'Aprovados'],
        'ruim' => [102, 'Ruim'],
        'em_negociacao' => [103, 'Em negociacao'],
        'fechado_aluguel' => [104, 'Fechado aluguel'],
        'nao_aluguel_nem_seguro' => [105, 'Nao aluguei nem seguro'],
    ] as $key => [$id, $title]) {
        LeadLoversTag::query()->create([
            'leadlovers_tag_id' => $id,
            'title' => $title,
            'key' => $key,
            'active' => true,
        ]);
    }
}

function rejectedRetentionRemoteTag(
    int $id,
    string $name,
    string $linkedAt = '2026-08-15T12:00:00Z',
): array {
    return [
        'id' => $id,
        'name' => $name,
        'linkedAt' => $linkedAt,
    ];
}

function rejectedRetentionLead(array $overrides = []): Lead
{
    static $sequence = 0;
    $sequence++;

    return Lead::query()->create(array_merge([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Lead retencao '.$sequence,
        'email' => 'retention-'.$sequence.'@example.test',
        'tel' => '11999990000',
        'cpf' => '12345678901',
        'tags_originais' => 'Origem X, Ruim',
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 700 + $sequence,
        'sent_to_leadlovers_at' => now()->subMonths(2),
    ], $overrides));
}

function rejectedRetentionOperation(
    Lead $lead,
    array $overrides = [],
): LeadLoversTagOperation {
    return LeadLoversTagOperation::query()->create(array_merge([
        'lead_id' => $lead->id,
        'version' => 1,
        'desired_source' => 'manual',
        'desired_tag_key' => 'ruim',
        'phase' => LeadLoversTagOperationCoordinator::PHASE_SYNCED,
        'inflight_version' => null,
    ], $overrides));
}

function expiredRejectedRetentionLead(
    array $leadOverrides = [],
    array $operationOverrides = [],
    bool $withOperation = true,
): Lead {
    $lead = rejectedRetentionLead(array_merge([
        'leadlovers_confirmed_final_tag_key' => 'ruim',
        'leadlovers_final_tag_confirmed_at' => '2026-08-15 12:00:00',
        'leadlovers_confirmed_tag_version' => $withOperation ? 1 : null,
        'rejected_deletion_due_at' => '2026-09-14 12:00:00',
    ], $leadOverrides));

    if ($withOperation) {
        rejectedRetentionOperation($lead, $operationOverrides);
    }

    return $lead->fresh();
}

function rejectedRetentionJob(Lead $lead): DeleteExpiredRejectedLeadJob
{
    $lead = $lead->fresh();

    return new DeleteExpiredRejectedLeadJob(
        leadId: (int) $lead->id,
        expectedDeletionDueAt: $lead->rejected_deletion_due_at
            ->toImmutable()
            ->utc()
            ->toIso8601String(),
        expectedConfirmedTagVersion: $lead->leadlovers_confirmed_tag_version,
    );
}

function handleRejectedRetentionJob(DeleteExpiredRejectedLeadJob $job): void
{
    $job->handle(
        app(LeadLoversApiClient::class),
        app(LeadLoversResultTagService::class),
        app(RejectedLeadRetentionService::class),
    );
}

function rejectedRetentionCorretor(): Corretor
{
    return Corretor::query()->create([
        'name' => 'CEO Retencao',
        'email' => 'ceo-retention@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
}

function rejectedRetentionCompany(): Imobiliaria
{
    static $sequence = 0;
    $sequence++;

    return Imobiliaria::query()->create([
        'name' => 'Imobiliaria Retencao '.$sequence,
        'email' => 'company-retention-'.$sequence.'@example.test',
        'phone' => '11988887777',
        'password' => 'password',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'lead_form_active' => true,
    ]);
}

it('creates the deletion deadline after a manual rejected tag confirmation', function () {
    Queue::fake();
    $corretor = rejectedRetentionCorretor();
    $lead = rejectedRetentionLead(['tags_originais' => 'Origem X, Aprovados']);
    $request = CorretorActivityLog::query()->create([
        'corretor_id' => $corretor->id,
        'action' => 'lead_tag_update_requested',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
        'new_values' => ['requested_result' => ManualLeadResultTags::REJECTED],
    ]);
    $state = app(LeadLoversTagOperationCoordinator::class)
        ->registerManualDesired(
            $lead->id,
            'ruim',
            ManualLeadResultTags::REJECTED,
            $request->id,
            $corretor->id,
        );

    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([
            rejectedRetentionRemoteTag(
                102,
                'Ruim',
                '2026-08-01T09:30:00Z'
            ),
        ], 200),
    ]);

    (new ApplyManualLeadResultTagJob(
        leadId: $lead->id,
        result: ManualLeadResultTags::REJECTED,
        corretorId: $corretor->id,
        requestLogId: $request->id,
        version: $state->version,
    ))->handle(
        app(LeadLoversApiClient::class),
        app(LeadLoversResultTagService::class),
        app(LeadLoversTagOperationCoordinator::class),
        app(RejectedLeadRetentionService::class),
    );

    expect($lead->fresh())
        ->leadlovers_confirmed_final_tag_key->toBe('ruim')
        ->leadlovers_confirmed_tag_version->toBe($state->version)
        ->and($lead->fresh()->rejected_deletion_due_at->toIso8601String())
        ->toBe('2026-08-31T09:30:00+00:00');
});

it('creates the deletion deadline after an automatic rejected analysis confirmation', function () {
    Queue::fake();
    $lead = rejectedRetentionLead(['tags_originais' => 'Origem X, Aprovados']);
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
        'status' => 'rejected',
    ]);
    $analysis->events()->create([
        'event_type' => 'analysis_started',
        'status' => 'processing',
        'payload' => ['attempt_id' => 'retention-attempt'],
    ]);
    $analysis->events()->create([
        'event_type' => 'email_queued',
        'status' => 'queued',
        'payload' => ['attempt_id' => 'retention-attempt'],
    ]);

    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([
            rejectedRetentionRemoteTag(
                102,
                'Ruim',
                '2026-08-02T10:15:00Z'
            ),
        ], 200),
    ]);

    (new ApplyFinalAnalysisTagToLeadLoversJob(
        batchId: $batch->id,
        attemptId: 'retention-attempt',
    ))->handle(
        app(LeadLoversApiClient::class),
        app(LeadLoversResultTagService::class),
        app(LeadLoversTagOperationCoordinator::class),
        app(RejectedLeadRetentionService::class),
    );

    expect($lead->fresh())
        ->leadlovers_confirmed_final_tag_key->toBe('ruim')
        ->and($lead->fresh()->rejected_deletion_due_at->toIso8601String())
        ->toBe('2026-09-01T10:15:00+00:00');
});

it('calculates exactly thirty days from linkedAt', function () {
    $lead = rejectedRetentionLead();
    $linkedAt = CarbonImmutable::parse('2026-08-10T23:59:59Z');

    app(RejectedLeadRetentionService::class)->applyConfirmedFinalTag(
        lead: $lead,
        tagKey: 'ruim',
        remoteTagId: 102,
        remoteTags: [rejectedRetentionRemoteTag(102, 'Ruim', $linkedAt->toIso8601String())],
        operationVersion: 4,
    );

    expect($lead->fresh()->rejected_deletion_due_at->equalTo(
        $linkedAt->addDays(30)
    ))->toBeTrue();
});

it('does not start the deadline before remote confirmation', function () {
    Queue::fake();
    $corretor = rejectedRetentionCorretor();
    $lead = rejectedRetentionLead(['tags_originais' => 'Origem X, Aprovados']);
    $request = CorretorActivityLog::query()->create([
        'corretor_id' => $corretor->id,
        'action' => 'lead_tag_update_requested',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
        'new_values' => ['requested_result' => ManualLeadResultTags::REJECTED],
    ]);

    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([
            rejectedRetentionRemoteTag(101, 'Aprovados'),
        ], 200),
        REJECTED_RETENTION_API_URL.'/leads/tags' => Http::response([
            'actionId' => 8001,
            'status' => 'pending',
            'total' => 1,
        ], 202),
    ]);

    (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::REJECTED,
        $corretor->id,
        requestLogId: $request->id,
    ))->handle(
        app(LeadLoversApiClient::class),
        app(LeadLoversResultTagService::class),
        app(LeadLoversTagOperationCoordinator::class),
        app(RejectedLeadRetentionService::class),
    );

    expect($lead->fresh()->rejected_deletion_due_at)->toBeNull()
        ->and(LeadRetentionEvent::query()->count())->toBe(0);
});

it('does not restart the deadline for the same linkedAt', function () {
    $lead = rejectedRetentionLead();
    $service = app(RejectedLeadRetentionService::class);
    $remoteTags = [rejectedRetentionRemoteTag(102, 'Ruim', '2026-08-03T08:00:00Z')];

    $service->applyConfirmedFinalTag($lead, 'ruim', 102, $remoteTags, 1);
    $firstDueAt = $lead->fresh()->rejected_deletion_due_at;
    $service->applyConfirmedFinalTag($lead->fresh(), 'ruim', 102, $remoteTags, 1);

    expect($lead->fresh()->rejected_deletion_due_at->equalTo($firstDueAt))->toBeTrue()
        ->and(LeadRetentionEvent::query()
            ->where('event', LeadRetentionEvent::EVENT_SCHEDULED)
            ->count())->toBe(1);
});

it('starts a new deadline when the rejected tag is removed and linked again', function () {
    $lead = rejectedRetentionLead();
    $service = app(RejectedLeadRetentionService::class);

    $service->applyConfirmedFinalTag(
        $lead,
        'ruim',
        102,
        [rejectedRetentionRemoteTag(102, 'Ruim', '2026-08-01T12:00:00Z')],
        1,
    );
    $service->applyConfirmedFinalTag(
        $lead->fresh(),
        'aprovados',
        101,
        [rejectedRetentionRemoteTag(101, 'Aprovados', '2026-08-05T12:00:00Z')],
        2,
    );
    $service->applyConfirmedFinalTag(
        $lead->fresh(),
        'ruim',
        102,
        [rejectedRetentionRemoteTag(102, 'Ruim', '2026-08-20T12:00:00Z')],
        3,
    );

    expect($lead->fresh()->rejected_deletion_due_at->toIso8601String())
        ->toBe('2026-09-19T12:00:00+00:00')
        ->and(LeadRetentionEvent::query()
            ->where('event', LeadRetentionEvent::EVENT_SCHEDULED)
            ->count())->toBe(2);
});

it('cancels the deadline when another final tag is confirmed', function () {
    $lead = expiredRejectedRetentionLead();

    app(RejectedLeadRetentionService::class)->applyConfirmedFinalTag(
        $lead,
        'aprovados',
        101,
        [rejectedRetentionRemoteTag(101, 'Aprovados', '2026-09-15T11:00:00Z')],
        2,
    );

    expect($lead->fresh())
        ->leadlovers_confirmed_final_tag_key->toBe('aprovados')
        ->rejected_deletion_due_at->toBeNull();
    $this->assertDatabaseHas('lead_retention_events', [
        'lead_id' => $lead->id,
        'event' => LeadRetentionEvent::EVENT_CANCELLED,
    ]);
});

it('does not dispatch a lead before thirty days', function () {
    Queue::fake();
    expiredRejectedRetentionLead([
        'rejected_deletion_due_at' => now()->addSecond(),
    ]);

    $this->artisan('leads:dispatch-expired-rejected')->assertSuccessful();

    Queue::assertNotPushed(DeleteExpiredRejectedLeadJob::class);
});

it('dispatches an expired lead with its immutable snapshot', function () {
    Queue::fake();
    $lead = expiredRejectedRetentionLead();

    $this->artisan('leads:dispatch-expired-rejected --limit=10')
        ->expectsOutputToContain('1 lead(s) recusado(s) vencido(s)')
        ->assertSuccessful();

    Queue::assertPushedOn(
        'leadlovers',
        DeleteExpiredRejectedLeadJob::class,
        fn (DeleteExpiredRejectedLeadJob $job): bool => $job->leadId === $lead->id
            && $job->expectedConfirmedTagVersion === 1
            && CarbonImmutable::parse($job->expectedDeletionDueAt)
                ->equalTo($lead->rejected_deletion_due_at)
    );
});

it('does not dispatch or write in dispatcher pretend mode', function () {
    Queue::fake();
    $lead = expiredRejectedRetentionLead();
    $attributes = $lead->getAttributes();

    $this->artisan('leads:dispatch-expired-rejected --pretend')
        ->expectsOutputToContain('nenhum job foi despachado')
        ->assertSuccessful();

    Queue::assertNothingPushed();
    expect($lead->fresh()->getAttributes())->toBe($attributes);
});

it('deletes definitively after a 200 response with only the rejected final tag', function () {
    $lead = expiredRejectedRetentionLead();
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([
            rejectedRetentionRemoteTag(900, 'Origem X'),
            rejectedRetentionRemoteTag(102, 'Ruim'),
        ], 200),
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($lead));

    $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
});

it('preserves the lead and cancels the deadline when 200 has no rejected tag', function () {
    $lead = expiredRejectedRetentionLead();
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([
            rejectedRetentionRemoteTag(900, 'Origem X'),
            rejectedRetentionRemoteTag(101, 'Aprovados'),
        ], 200),
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($lead));

    expect($lead->fresh())->not->toBeNull()
        ->and($lead->fresh()->rejected_deletion_due_at)->toBeNull();
    $this->assertDatabaseHas('lead_retention_events', [
        'lead_id' => $lead->id,
        'event' => LeadRetentionEvent::EVENT_CANCELLED,
    ]);
});

it('preserves the lead when rejected and another final tag conflict remotely', function () {
    $lead = expiredRejectedRetentionLead();
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([
            rejectedRetentionRemoteTag(102, 'Ruim'),
            rejectedRetentionRemoteTag(101, 'Aprovados'),
        ], 200),
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($lead));

    expect($lead->fresh())->not->toBeNull()
        ->and($lead->fresh()->rejected_deletion_due_at)->toBeNull();
});

it('deletes after 404 only while the complete local snapshot remains valid', function () {
    $lead = expiredRejectedRetentionLead();
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response(['message' => 'not found'], 404),
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($lead));

    $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
    $this->assertDatabaseHas('lead_retention_events', [
        'lead_id' => $lead->id,
        'event' => LeadRetentionEvent::EVENT_DELETED,
    ]);
});

it('does not delete on 404 when a newer operation version exists', function () {
    $lead = expiredRejectedRetentionLead();
    $job = rejectedRetentionJob($lead);
    LeadLoversTagOperation::query()
        ->where('lead_id', $lead->id)
        ->update(['version' => 2]);
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response(['message' => 'not found'], 404),
    ]);

    handleRejectedRetentionJob($job);

    expect($lead->fresh())->not->toBeNull();
    Http::assertNothingSent();
});

it('preserves and audits the lead on 401', function () {
    $lead = expiredRejectedRetentionLead();
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($lead));

    expect($lead->fresh())->not->toBeNull();
    $this->assertDatabaseHas('lead_retention_events', [
        'lead_id' => $lead->id,
        'event' => LeadRetentionEvent::EVENT_DEFERRED,
    ]);
});

it('preserves and releases according to Retry-After on 429', function () {
    $lead = expiredRejectedRetentionLead();
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response(['error' => 'rate limited'], 429, [
            'Retry-After' => '45',
        ]),
    ]);
    $job = rejectedRetentionJob($lead)->withFakeQueueInteractions();

    handleRejectedRetentionJob($job);

    $job->assertReleased(45);
    expect($lead->fresh())->not->toBeNull();
});

it('preserves immediately and lets the queue retry transient failures', function (string $failure) {
    $lead = expiredRejectedRetentionLead();
    $url = REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags';

    Http::fake([
        $url => $failure === 'timeout'
            ? Http::failedConnection('timeout')
            : Http::response(['error' => 'temporarily unavailable'], 503),
    ]);

    expect(fn () => handleRejectedRetentionJob(rejectedRetentionJob($lead)))
        ->toThrow(LeadLoversApiException::class);
    expect($lead->fresh())->not->toBeNull();
})->with(['timeout', '5xx']);

it('preserves the lead for an invalid successful response', function () {
    $lead = expiredRejectedRetentionLead();
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response(['unexpected' => true], 200),
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($lead));

    expect($lead->fresh())->not->toBeNull();
    $event = LeadRetentionEvent::query()
        ->where('lead_id', $lead->id)
        ->where('event', LeadRetentionEvent::EVENT_DEFERRED)
        ->sole();
    expect($event->context['verification'])->toBe('invalid_response');
});

it('does not call the API while a tag operation is active', function (
    string $phase,
    ?int $inflightVersion,
) {
    $lead = expiredRejectedRetentionLead([], [
        'phase' => $phase,
        'inflight_version' => $inflightVersion,
        'inflight_tag_key' => $inflightVersion === null ? null : 'aprovados',
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($lead));

    expect($lead->fresh())->not->toBeNull();
    Http::assertNothingSent();
})->with([
    'pending' => [LeadLoversTagOperationCoordinator::PHASE_PENDING, null],
    'posting' => [LeadLoversTagOperationCoordinator::PHASE_POSTING, 1],
    'confirming' => [LeadLoversTagOperationCoordinator::PHASE_CONFIRMING, 1],
    'inflight version' => [LeadLoversTagOperationCoordinator::PHASE_SYNCED, 1],
]);

it('revalidates the operation version after the remote request', function () {
    $lead = expiredRejectedRetentionLead();
    $job = rejectedRetentionJob($lead);

    Http::fake(function (Request $request) use ($lead) {
        LeadLoversTagOperation::query()
            ->where('lead_id', $lead->id)
            ->update(['version' => 2]);

        return Http::response([
            rejectedRetentionRemoteTag(102, 'Ruim'),
        ], 200);
    });

    handleRejectedRetentionJob($job);

    expect($lead->fresh())->not->toBeNull();
    $this->assertDatabaseMissing('lead_retention_events', [
        'lead_id' => $lead->id,
        'event' => LeadRetentionEvent::EVENT_DELETED,
    ]);
});

it('is idempotent when the same deletion job runs twice', function () {
    $lead = expiredRejectedRetentionLead();
    $job = rejectedRetentionJob($lead);
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([
            rejectedRetentionRemoteTag(102, 'Ruim'),
        ], 200),
    ]);

    handleRejectedRetentionJob($job);
    handleRejectedRetentionJob($job);

    $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
    expect(LeadRetentionEvent::query()
        ->where('lead_id', $lead->id)
        ->where('event', LeadRetentionEvent::EVENT_DELETED)
        ->count())->toBe(1);
    Http::assertSentCount(1);
});

it('keeps the technical audit after definitive deletion', function () {
    $lead = expiredRejectedRetentionLead();
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([rejectedRetentionRemoteTag(102, 'Ruim')], 200),
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($lead));

    $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
    $this->assertDatabaseHas('lead_retention_events', [
        'lead_id' => $lead->id,
        'event' => LeadRetentionEvent::EVENT_DELETED,
    ]);
});

it('does not put personal data or raw API content in the retention audit', function () {
    $lead = expiredRejectedRetentionLead([
        'nome' => 'Pessoa Auditada Sensivel',
        'email' => 'sensitive-retention@example.test',
        'tel' => '11912345678',
        'cpf' => '98765432100',
    ]);
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([rejectedRetentionRemoteTag(102, 'Ruim')], 200),
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($lead));

    $serialized = LeadRetentionEvent::query()
        ->where('lead_id', $lead->id)
        ->get()
        ->toJson();

    expect($serialized)
        ->not->toContain('Pessoa Auditada Sensivel')
        ->not->toContain('sensitive-retention@example.test')
        ->not->toContain('11912345678')
        ->not->toContain('98765432100')
        ->not->toContain(REJECTED_RETENTION_TOKEN);
});

it('deletes all current dependent records through database cascades', function () {
    $lead = expiredRejectedRetentionLead();
    LeadEnderecos::query()->create(['lead_id' => $lead->id, 'estado' => 'SP']);
    LeadDespesas::query()->create(['lead_id' => $lead->id, 'valor_aluguel' => 1000]);
    LeadLocadores::query()->create(['lead_id' => $lead->id, 'nome' => 'Locador']);
    LeadConjugues::query()->create(['lead_id' => $lead->id, 'nome' => 'Conjuge']);
    LeadImobiliariaInformada::query()->create([
        'lead_id' => $lead->id,
        'nome_imobiliaria_informada' => 'Imobiliaria informada',
    ]);
    $batch = InsuranceAnalysisBatch::query()->create([
        'lead_id' => $lead->id,
        'status' => 'completed',
    ]);
    $analysis = InsuranceAnalysis::query()->create([
        'insurance_analysis_batch_id' => $batch->id,
        'lead_id' => $lead->id,
        'provider' => 'pottencial',
        'product' => 'seguro_fianca_residencial',
        'status' => 'rejected',
    ]);
    $analysisEvent = InsuranceAnalysisEvent::query()->create([
        'insurance_analysis_id' => $analysis->id,
        'event_type' => 'rejected',
    ]);
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([rejectedRetentionRemoteTag(102, 'Ruim')], 200),
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($lead));

    foreach ([
        ['lead_enderecos', 'lead_id', $lead->id],
        ['lead_despesas', 'lead_id', $lead->id],
        ['lead_locadores', 'lead_id', $lead->id],
        ['lead_conjugues', 'lead_id', $lead->id],
        ['lead_imobiliaria_informada', 'lead_id', $lead->id],
        ['lotes_analises_seguro', 'id', $batch->id],
        ['analises_seguro', 'id', $analysis->id],
        ['eventos_analises_seguro', 'id', $analysisEvent->id],
        ['leadlovers_tag_operations', 'lead_id', $lead->id],
    ] as [$table, $column, $value]) {
        $this->assertDatabaseMissing($table, [$column => $value]);
    }
});

it('emits dashboard removal only after a confirmed deletion', function () {
    Event::fake([DashboardActivityChanged::class]);
    $preserved = expiredRejectedRetentionLead([
        'leadlovers_lead_id' => 8801,
    ]);
    $deleted = expiredRejectedRetentionLead([
        'leadlovers_lead_id' => 8802,
    ]);
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/8801/tags' => Http::response([
            rejectedRetentionRemoteTag(101, 'Aprovados'),
        ], 200),
        REJECTED_RETENTION_API_URL.'/leads/8802/tags' => Http::response([
            rejectedRetentionRemoteTag(102, 'Ruim'),
        ], 200),
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($preserved));
    Event::assertNotDispatched(
        DashboardActivityChanged::class,
        fn (DashboardActivityChanged $event): bool => $event->resourceId === $preserved->id
    );

    handleRejectedRetentionJob(rejectedRetentionJob($deleted));
    Event::assertDispatched(
        DashboardActivityChanged::class,
        fn (DashboardActivityChanged $event): bool => $event->resourceId === $deleted->id
            && $event->change === 'lead.deleted'
    );
});

it('backfills from the remote linkedAt timestamp', function () {
    $lead = rejectedRetentionLead(['tags_originais' => 'Origem X, RUiM']);
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([
            rejectedRetentionRemoteTag(102, 'Ruim', '2026-08-12T14:45:00Z'),
        ], 200),
    ]);

    $this->artisan('leads:backfill-rejected-retention --limit=10')
        ->assertSuccessful();

    expect($lead->fresh()->rejected_deletion_due_at->toIso8601String())
        ->toBe('2026-09-11T14:45:00+00:00');
});

it('records review without inventing a date when backfill receives 404', function () {
    $lead = rejectedRetentionLead();
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response(['message' => 'not found'], 404),
    ]);

    $this->artisan('leads:backfill-rejected-retention')->assertSuccessful();

    expect($lead->fresh()->rejected_deletion_due_at)->toBeNull();
    $this->assertDatabaseHas('lead_retention_events', [
        'lead_id' => $lead->id,
        'event' => LeadRetentionEvent::EVENT_REVIEW_REQUIRED,
        'deletion_due_at' => null,
    ]);
});

it('keeps the backfill idempotent', function () {
    $lead = rejectedRetentionLead();
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([
            rejectedRetentionRemoteTag(102, 'Ruim', '2026-08-10T12:00:00Z'),
        ], 200),
    ]);

    $this->artisan('leads:backfill-rejected-retention')->assertSuccessful();
    $firstDueAt = $lead->fresh()->rejected_deletion_due_at;
    $this->artisan('leads:backfill-rejected-retention')->assertSuccessful();

    expect($lead->fresh()->rejected_deletion_due_at->equalTo($firstDueAt))->toBeTrue()
        ->and(LeadRetentionEvent::query()
            ->where('lead_id', $lead->id)
            ->where('event', LeadRetentionEvent::EVENT_SCHEDULED)
            ->count())->toBe(1);
    Http::assertSentCount(1);
});

it('uses normalized exact local tag matching during backfill', function () {
    $lead = rejectedRetentionLead([
        'tags_originais' => 'Origem X, cliente recusado premium',
    ]);

    $this->artisan('leads:backfill-rejected-retention')->assertSuccessful();

    expect($lead->fresh()->rejected_deletion_due_at)->toBeNull();
    Http::assertNothingSent();
});

it('registers exactly one hourly scheduler entry for the dispatcher', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains(
            (string) $event->command,
            'leads:dispatch-expired-rejected'
        ));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});

it('shows the accessible responsive deadline notice in both dashboards', function () {
    $company = rejectedRetentionCompany();
    $lead = rejectedRetentionLead([
        'company_id' => $company->id,
        'nome' => 'Lead aviso nos dois dashboards',
        'leadlovers_confirmed_final_tag_key' => 'ruim',
        'leadlovers_final_tag_confirmed_at' => now()->subDays(20),
        'leadlovers_confirmed_tag_version' => 1,
        'rejected_deletion_due_at' => now()->addDays(10),
    ]);
    rejectedRetentionOperation($lead);
    $admin = rejectedRetentionCorretor();
    $user = User::factory()->create(['company_id' => $company->id]);

    $adminResponse = $this->actingAs($admin, 'admin')
        ->get(route('Dashboard-Admin'));
    $companyResponse = $this->actingAs($user, 'web')
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])->get(route('company.dashboard'));

    foreach ([$adminResponse, $companyResponse] as $response) {
        $response->assertOk()
            ->assertSee(
                'SerÃ¡ excluÃ­do automaticamente em 10 dias se continuar recusado.',
                escape: false
            )
            ->assertSee('data-rejected-retention-notice', false)
            ->assertSee('role="status"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('flex-column flex-sm-row', false);
    }
});

it('does not show a deadline without remote rejected confirmation or for another tag', function () {
    $company = rejectedRetentionCompany();
    rejectedRetentionLead([
        'company_id' => $company->id,
        'leadlovers_confirmed_final_tag_key' => null,
        'rejected_deletion_due_at' => now()->addDays(10),
    ]);
    rejectedRetentionLead([
        'company_id' => $company->id,
        'leadlovers_confirmed_final_tag_key' => 'aprovados',
        'rejected_deletion_due_at' => now()->addDays(10),
    ]);
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'web')
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])->get(route('company.dashboard'))
        ->assertOk()
        ->assertDontSee('data-rejected-retention-notice', false)
        ->assertDontSee('SerÃ¡ excluÃ­do automaticamente');
});

it('shows safe processing copy while another result awaits confirmation', function () {
    $company = rejectedRetentionCompany();
    $lead = expiredRejectedRetentionLead([
        'company_id' => $company->id,
        'rejected_deletion_due_at' => now()->addDays(10),
    ], [
        'version' => 2,
        'desired_tag_key' => 'aprovados',
        'phase' => LeadLoversTagOperationCoordinator::PHASE_PENDING,
    ]);
    $lead->forceFill(['leadlovers_confirmed_tag_version' => 1])->save();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'web')
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])->get(route('company.dashboard'))
        ->assertOk()
        ->assertSee(
            'AlteraÃ§Ã£o em processamento. A exclusÃ£o serÃ¡ cancelada apÃ³s confirmaÃ§Ã£o da LeadLovers.',
            escape: false
        )
        ->assertDontSee((string) $lead->leadlovers_lead_id);
});

it('shows the local date and time close to the deadline', function () {
    $company = rejectedRetentionCompany();
    $lead = expiredRejectedRetentionLead([
        'company_id' => $company->id,
        'rejected_deletion_due_at' => '2026-09-16 12:00:00',
    ]);
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'web')
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])->get(route('company.dashboard'))
        ->assertOk()
        ->assertSee('ExclusÃ£o automÃ¡tica em 16/09/2026 Ã s 09:00.', false);
});

it('does not list a lead after the confirmed deletion event', function () {
    $company = rejectedRetentionCompany();
    $lead = expiredRejectedRetentionLead([
        'company_id' => $company->id,
        'nome' => 'Lead removido do dashboard',
    ]);
    $user = User::factory()->create(['company_id' => $company->id]);
    Http::fake([
        REJECTED_RETENTION_API_URL.'/leads/'.$lead->leadlovers_lead_id.'/tags' => Http::response([rejectedRetentionRemoteTag(102, 'Ruim')], 200),
    ]);

    handleRejectedRetentionJob(rejectedRetentionJob($lead));

    $this->actingAs($user, 'web')
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])->get(route('company.dashboard'))
        ->assertOk()
        ->assertDontSee('Lead removido do dashboard');
});
