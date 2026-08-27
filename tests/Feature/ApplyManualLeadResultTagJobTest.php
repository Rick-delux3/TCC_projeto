<?php

use App\Events\DashboardActivityChanged;
use App\Exceptions\LeadLoversApiException;
use App\Exceptions\PermanentLeadTagException;
use App\Jobs\ApplyManualLeadResultTagJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Models\LeadLoversTagOperation;
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversResultTagService;
use App\Services\LeadLoversTagOperationCoordinator;
use App\Services\RejectedLeadRetentionService;
use App\Support\ManualLeadResultTags;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

const MANUAL_LEAD_TAG_API_URL = 'https://api.leadlovers.manual.test';
const MANUAL_LEAD_TAG_TOKEN = 'manual-flow-secret-token';
const MANUAL_LEAD_TAG_CONFIRMATION_DELAY = 17;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([]);

    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.api_url' => MANUAL_LEAD_TAG_API_URL,
        'services.leadlovers.token' => MANUAL_LEAD_TAG_TOKEN,
        'services.leadlovers.requests_per_minute' => 90,
        'services.leadlovers.rate_limit_window_seconds' => 60,
        'services.leadlovers.rate_limit_retry_seconds' => 60,
        'services.leadlovers.rate_limit_max_retry_seconds' => 900,
        'services.leadlovers.tag_confirmation_delay_seconds' => MANUAL_LEAD_TAG_CONFIRMATION_DELAY,
    ]);

    RateLimiter::clear(manualLeadTagLimiterKey());
});

afterEach(function () {
    RateLimiter::clear(manualLeadTagLimiterKey());
});

function manualLeadTagLimiterKey(): string
{
    return 'leadlovers:requests:'.hash(
        'sha256',
        (string) config('services.leadlovers.token')
    );
}

function manualLeadTagCorretor(array $overrides = []): Corretor
{
    return Corretor::query()->create(array_merge([
        'name' => 'Corretor de Teste',
        'email' => 'manual-tags@example.test',
        'cpf' => null,
        'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => ['tags.visualizar', 'tags.gerenciar'],
        'active' => true,
        'first_login_verified_at' => now(),
    ], $overrides));
}

function manualLeadTagLead(array $overrides = []): Lead
{
    return Lead::query()->create(array_merge([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Lead de Teste',
        'email' => 'lead@example.test',
        'tags_originais' => 'Imobiliária Azul, Ruim, Origem X',
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 501,
        'sent_to_leadlovers_at' => now(),
    ], $overrides));
}

/**
 * @return array<string, array{id: int, title: string}>
 */
function manualLeadTagDefinitions(): array
{
    return [
        'aprovados' => ['id' => 101, 'title' => 'Aprovados'],
        'ruim' => ['id' => 102, 'title' => 'Ruim'],
        'em_negociacao' => ['id' => 103, 'title' => 'Em negociação'],
        'fechado_aluguel' => ['id' => 104, 'title' => 'Fechado Aluguel'],
        'nao_aluguel_nem_seguro' => [
            'id' => 105,
            'title' => 'Não aluguei nem seguro',
        ],
    ];
}

function manualLeadTagCatalog(): void
{
    foreach (manualLeadTagDefinitions() as $key => $definition) {
        LeadLoversTag::query()->create([
            'leadlovers_tag_id' => $definition['id'],
            'title' => $definition['title'],
            'key' => $key,
            'active' => true,
        ]);
    }
}

function manualLeadTagRequestLog(
    Corretor $corretor,
    Lead $lead,
    string $result
): CorretorActivityLog {
    return CorretorActivityLog::query()->create([
        'corretor_id' => $corretor->id,
        'action' => 'lead_tag_update_requested',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
        'new_values' => [
            'requested_result' => $result,
        ],
    ]);
}

function manualLeadTagPendingLog(
    Corretor $corretor,
    Lead $lead,
    string $result,
    int $requestLogId,
    ?array $bulkAction = null
): CorretorActivityLog {
    return CorretorActivityLog::query()->create([
        'corretor_id' => $corretor->id,
        'action' => 'lead_tag_update_pending_confirmation',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
        'new_values' => [
            'request_log_id' => $requestLogId,
            'requested_result' => $result,
            'phase' => 'pending_confirmation',
            'outcome_uncertain' => false,
            'bulk_action' => $bulkAction,
        ],
    ]);
}

function manualLeadTagRemoteTag(int $id, string $name): array
{
    return [
        'id' => $id,
        'name' => $name,
        'linkedAt' => '2026-08-11T12:00:00Z',
    ];
}

function handleManualLeadTagJob(ApplyManualLeadResultTagJob $job): void
{
    $job->handle(
        app(LeadLoversApiClient::class),
        app(LeadLoversResultTagService::class),
        app(LeadLoversTagOperationCoordinator::class),
        app(RejectedLeadRetentionService::class),
    );
}

it('allows an active member with permission to request each commercial result', function (string $result) {
    Queue::fake();
    manualLeadTagCatalog();

    $corretor = manualLeadTagCorretor();
    $lead = manualLeadTagLead([
        'tags_originais' => 'Imobiliária Azul, Origem X',
    ]);

    $this
        ->actingAs($corretor, 'admin')
        ->from('/Dashboard/Admin')
        ->patch(route('admin.leads.result-tag.update', $lead), [
            'result' => $result,
            'result_context_lead_id' => $lead->id,
            'corretor_id' => 999999,
        ])
        ->assertRedirect('/Dashboard/Admin')
        ->assertSessionHasNoErrors();

    $requestLogId = CorretorActivityLog::query()
        ->where('action', 'lead_tag_update_requested')
        ->where('model_type', Lead::class)
        ->where('model_id', $lead->id)
        ->value('id');

    Queue::assertPushed(
        ApplyManualLeadResultTagJob::class,
        fn (ApplyManualLeadResultTagJob $job): bool => $job->leadId === $lead->id
            && $job->corretorId === $corretor->id
            && $job->result === $result
            && $job->requestLogId === $requestLogId
            && $job->phase === null
    );
})->with([
    'Aprovado' => [ManualLeadResultTags::APPROVED],
    'Recusado' => [ManualLeadResultTags::REJECTED],
    'Em negociação' => [ManualLeadResultTags::IN_NEGOTIATION],
    'Fechado aluguel' => [ManualLeadResultTags::RENTAL_CONFIRMED],
    'Não aluguei nem seguro' => [ManualLeadResultTags::NO_RENT_OR_INSURANCE],
]);

it('rejects a request that repeats the current commercial result', function (
    string $result,
    string $currentTag,
) {
    Queue::fake();

    $corretor = manualLeadTagCorretor();
    $lead = manualLeadTagLead([
        'tags_originais' => "Imobiliária Azul, {$currentTag}, Origem X",
    ]);
    $resultLabel = ManualLeadResultTags::label($result);

    $this
        ->actingAs($corretor, 'admin')
        ->from('/Dashboard/Admin')
        ->patch(route('admin.leads.result-tag.update', $lead), [
            'result' => $result,
            'result_context_lead_id' => $lead->id,
        ])
        ->assertRedirect('/Dashboard/Admin')
        ->assertSessionHasErrors([
            'result' => sprintf(
                'O lead já possui o status "%s". Selecione outro status.',
                $resultLabel
            ),
        ]);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    $this->assertDatabaseMissing('logs_atividades_corretores', [
        'action' => 'lead_tag_update_requested',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
    ]);
})->with([
    'Aprovado' => [ManualLeadResultTags::APPROVED, 'Aprovados'],
    'Recusado' => [ManualLeadResultTags::REJECTED, 'Ruim'],
    'Em negociação' => [ManualLeadResultTags::IN_NEGOTIATION, 'Em negociação'],
    'Fechado aluguel' => [ManualLeadResultTags::RENTAL_CONFIRMED, 'Fechado aluguel'],
    'Não aluguei nem seguro' => [
        ManualLeadResultTags::NO_RENT_OR_INSURANCE,
        'Não aluguei nem seguro',
    ],
]);

it('rejects unauthenticated inactive unverified and unauthorized members', function (string $state) {
    Queue::fake();
    $lead = manualLeadTagLead();

    if ($state === 'guest') {
        $this->patch(route('admin.leads.result-tag.update', $lead), [
            'result' => ManualLeadResultTags::APPROVED,
        ])->assertRedirect(route('admin.login'));
    } else {
        $overrides = match ($state) {
            'inactive' => ['active' => false],
            'unverified' => ['first_login_verified_at' => null],
            'unauthorized' => ['permissions' => []],
        };
        $corretor = manualLeadTagCorretor($overrides);
        $response = $this
            ->actingAs($corretor, 'admin')
            ->patch(route('admin.leads.result-tag.update', $lead), [
                'result' => ManualLeadResultTags::APPROVED,
            ]);

        match ($state) {
            'inactive' => $response->assertRedirect(route('admin.login')),
            'unverified' => $response->assertRedirect(route('admin.2fa.form')),
            'unauthorized' => $response->assertForbidden(),
        };
    }

    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'guest' => ['guest'],
    'inactive member' => ['inactive'],
    'member without 2FA' => ['unverified'],
    'member without permission' => ['unauthorized'],
]);

it('rejects an unknown result before dispatching the job', function () {
    Queue::fake();
    $corretor = manualLeadTagCorretor();
    $lead = manualLeadTagLead();

    $this
        ->actingAs($corretor, 'admin')
        ->from('/Dashboard/Admin')
        ->patch(route('admin.leads.result-tag.update', $lead), [
            'result' => 'not-a-result',
            'result_context_lead_id' => $lead->id,
        ])
        ->assertRedirect('/Dashboard/Admin')
        ->assertSessionHasErrors('result');

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('rejects a controller request when the remote lead id is absent', function () {
    Queue::fake();
    $corretor = manualLeadTagCorretor();
    $lead = manualLeadTagLead(['leadlovers_lead_id' => null]);

    $this
        ->actingAs($corretor, 'admin')
        ->from('/Dashboard/Admin')
        ->patch(route('admin.leads.result-tag.update', $lead), [
            'result' => ManualLeadResultTags::APPROVED,
            'result_context_lead_id' => $lead->id,
        ])
        ->assertRedirect('/Dashboard/Admin')
        ->assertSessionHasErrors('result');

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('fails locally when the lead no longer exists', function () {
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $job = (new ApplyManualLeadResultTagJob(
        999999,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    ))->withFakeQueueInteractions();

    handleManualLeadTagJob($job);

    $job->assertFailedWith(PermanentLeadTagException::class);
    Http::assertNothingSent();
});

it('fails locally when the lead has no valid remote id', function () {
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead(['leadlovers_lead_id' => null]);
    $job = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    ))->withFakeQueueInteractions();

    handleManualLeadTagJob($job);

    $job->assertFailedWith(PermanentLeadTagException::class);
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
    Http::assertNothingSent();
});

it('does not execute a request superseded by a newer decision', function () {
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $olderRequest = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::REJECTED
    );
    $job = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $olderRequest->id,
    );

    handleManualLeadTagJob($job);

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
    Http::assertNothingSent();
    $this->assertDatabaseMissing('logs_atividades_corretores', [
        'action' => 'lead_tag_update_completed',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
    ]);
});

it('does not persist when a newer decision arrives during the remote read', function () {
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $olderRequest = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );

    Http::fake(function (Request $request) use ($corretor, $lead) {
        expect($request->method())->toBe('GET')
            ->and($request->url())->toBe(
                MANUAL_LEAD_TAG_API_URL.'/leads/501/tags'
            );

        manualLeadTagRequestLog(
            $corretor,
            $lead,
            ManualLeadResultTags::REJECTED
        );

        return Http::response([
            manualLeadTagRemoteTag(900, 'Imobiliária Azul'),
            manualLeadTagRemoteTag(101, 'Aprovados'),
        ], 200);
    });

    handleManualLeadTagJob(new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $olderRequest->id,
        phase: 'confirmation',
    ));

    Http::assertSentCount(1);
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
    $this->assertDatabaseMissing('logs_atividades_corretores', [
        'action' => 'lead_tag_update_completed',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
    ]);
});

it('uses the audit request and phase as queue uniqueness versions', function () {
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $firstRequest = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    $secondRequest = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    $firstJob = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $firstRequest->id,
    );
    $secondJob = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $secondRequest->id,
    );
    $confirmationJob = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $firstRequest->id,
        phase: 'confirmation',
    );

    expect($firstJob->uniqueId())->not->toBe($secondJob->uniqueId())
        ->and($firstJob->uniqueId())->not->toBe($confirmationJob->uniqueId())
        ->and($confirmationJob->uniqueId())
        ->toBe($firstJob->uniqueId().':confirmation');
});

it('fails before HTTP when the final tag catalog has unsafe data', function (string $state) {
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();

    LeadLoversTag::query()
        ->where('key', 'ruim')
        ->update(match ($state) {
            'blank' => ['title' => ''],
            'duplicate title' => ['title' => 'Aprovados'],
            'invalid id' => ['leadlovers_tag_id' => 0],
        });

    $job = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    ))->withFakeQueueInteractions();

    handleManualLeadTagJob($job);

    $job->assertFailedWith(PermanentLeadTagException::class);
    Http::assertNothingSent();
})->with([
    'blank title' => ['blank'],
    'duplicate normalized title' => ['duplicate title'],
    'invalid remote id' => ['invalid id'],
]);

it('does not mutate when the selected result is already confirmed remotely', function () {
    Queue::fake();
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $requestLog = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );

    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::response([
            manualLeadTagRemoteTag(900, 'Imobiliária Azul'),
            manualLeadTagRemoteTag(101, 'Aprovados'),
        ], 200),
    ]);

    handleManualLeadTagJob(new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $requestLog->id,
    ));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === MANUAL_LEAD_TAG_API_URL.'/leads/501/tags'
    );
    expect($lead->fresh())
        ->tags_originais->toBe('Imobiliária Azul, Origem X, Aprovados')
        ->updated_by_corretor_id->toBe($corretor->id);
    $this->assertDatabaseHas('logs_atividades_corretores', [
        'action' => 'lead_tag_update_completed',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
    ]);
    $this->assertDatabaseMissing('logs_atividades_corretores', [
        'action' => 'lead_tag_update_pending_confirmation',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
    ]);
    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        fn (BroadcastEvent $job): bool => $job->event instanceof DashboardActivityChanged
            && $job->event->resourceId === $lead->id
            && $job->event->change === 'lead.tags.changed',
    );
});

it('does not reapply a selected tag when only another final tag must be removed', function () {
    Queue::fake();
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $requestLog = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );

    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::response([
            manualLeadTagRemoteTag(900, 'Imobiliária Azul'),
            manualLeadTagRemoteTag(101, 'Aprovados'),
            manualLeadTagRemoteTag(102, 'Ruim'),
        ], 200),
        MANUAL_LEAD_TAG_API_URL.'/leads/tags' => Http::response([
            'actionId' => 7002,
            'status' => 'pending',
            'total' => 1,
        ], 202),
    ]);

    handleManualLeadTagJob(new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $requestLog->id,
    ));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === MANUAL_LEAD_TAG_API_URL.'/leads/tags'
        && $request->data() === [
            'applyTags' => [],
            'removeTags' => [102],
            'leadsIds' => [501],
        ]
    );
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
});

it('uses one bulk mutation for each of the five commercial outcomes', function (
    string $result,
    int $selectedTagId,
    int $oldFinalTagId,
    string $oldFinalTagTitle
) {
    Queue::fake();
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $requestLog = manualLeadTagRequestLog($corretor, $lead, $result);
    $bulkAction = [
        'actionId' => 7001,
        'status' => 'pending',
        'total' => 1,
    ];

    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::response([
            manualLeadTagRemoteTag(900, 'Imobiliária Azul'),
            manualLeadTagRemoteTag($oldFinalTagId, $oldFinalTagTitle),
        ], 200),
        MANUAL_LEAD_TAG_API_URL.'/leads/tags' => Http::response(
            $bulkAction,
            202
        ),
    ]);

    handleManualLeadTagJob(new ApplyManualLeadResultTagJob(
        $lead->id,
        $result,
        $corretor->id,
        requestLogId: $requestLog->id,
    ));

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === MANUAL_LEAD_TAG_API_URL.'/leads/tags'
        && $request->data() === [
            'applyTags' => [$selectedTagId],
            'removeTags' => [$oldFinalTagId],
            'leadsIds' => [501],
        ]
        && ! in_array(900, $request->data()['removeTags'], true)
    );
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
    $pending = CorretorActivityLog::query()
        ->where('action', 'lead_tag_update_pending_confirmation')
        ->sole();
    expect(data_get($pending->new_values, 'request_log_id'))
        ->toBe($requestLog->id)
        ->and(data_get($pending->new_values, 'bulk_action'))
        ->toBe($bulkAction);
    $this->assertDatabaseMissing('logs_atividades_corretores', [
        'action' => 'lead_tag_update_completed',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
    ]);
    Queue::assertPushed(
        ApplyManualLeadResultTagJob::class,
        function (ApplyManualLeadResultTagJob $job) use (
            $lead,
            $result,
            $requestLog,
            $bulkAction
        ): bool {
            $delay = $job->delay;

            return $job->leadId === $lead->id
                && $job->result === $result
                && $job->requestLogId === $requestLog->id
                && $job->phase === 'confirmation'
                && $job->bulkAction === $bulkAction
                && $delay instanceof DateTimeInterface
                && $delay->getTimestamp() >= now()
                    ->addSeconds(MANUAL_LEAD_TAG_CONFIRMATION_DELAY - 1)
                    ->getTimestamp();
        }
    );
})->with([
    'Aprovado' => [ManualLeadResultTags::APPROVED, 101, 102, 'Ruim'],
    'Recusado' => [ManualLeadResultTags::REJECTED, 102, 101, 'Aprovados'],
    'Em negociação' => [
        ManualLeadResultTags::IN_NEGOTIATION,
        103,
        101,
        'Aprovados',
    ],
    'Fechado aluguel' => [
        ManualLeadResultTags::RENTAL_CONFIRMED,
        104,
        101,
        'Aprovados',
    ],
    'Não aluguei nem seguro' => [
        ManualLeadResultTags::NO_RENT_OR_INSURANCE,
        105,
        101,
        'Aprovados',
    ],
]);

it('does not repeat the bulk mutation when the request is already pending', function () {
    Queue::fake();
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $requestLog = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    manualLeadTagPendingLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED,
        $requestLog->id,
        ['actionId' => 7001, 'status' => 'pending', 'total' => 1],
    );

    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::response([
            manualLeadTagRemoteTag(900, 'Imobiliária Azul'),
            manualLeadTagRemoteTag(102, 'Ruim'),
        ], 200),
    ]);

    handleManualLeadTagJob(new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $requestLog->id,
    ));

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
    );
    Queue::assertPushed(
        ApplyManualLeadResultTagJob::class,
        fn (ApplyManualLeadResultTagJob $job): bool => $job->phase === 'confirmation'
            && $job->requestLogId === $requestLog->id
    );
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
});

it('normalizes reordered bulk action properties while confirming the remote state', function () {
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $requestLog = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    $reorderedBulkAction = [
        'status' => 'pending',
        'total' => 1,
        'actionId' => 7001,
    ];
    manualLeadTagPendingLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED,
        $requestLog->id,
        $reorderedBulkAction,
    );

    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::sequence()
            ->push([
                manualLeadTagRemoteTag(900, 'Imobiliária Azul'),
                manualLeadTagRemoteTag(102, 'Ruim'),
            ], 200)
            ->push([
                manualLeadTagRemoteTag(900, 'Imobiliária Azul'),
                manualLeadTagRemoteTag(101, 'Aprovados'),
            ], 200),
    ]);
    $job = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $requestLog->id,
        phase: 'confirmation',
        bulkAction: $reorderedBulkAction,
    ))->withFakeQueueInteractions();

    handleManualLeadTagJob($job);

    $job->assertReleased(MANUAL_LEAD_TAG_CONFIRMATION_DELAY);
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');

    handleManualLeadTagJob($job);

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
    );
    expect($lead->fresh())
        ->tags_originais->toBe('Imobiliária Azul, Origem X, Aprovados')
        ->updated_by_corretor_id->toBe($corretor->id);
    $completed = CorretorActivityLog::query()
        ->where('action', 'lead_tag_update_completed')
        ->sole();
    expect(data_get($completed->new_values, 'request_log_id'))
        ->toBe($requestLog->id)
        ->and(data_get($completed->new_values, 'bulk_action'))
        ->toBe([
            'actionId' => 7001,
            'status' => 'pending',
            'total' => 1,
        ]);
});

it('fails confirmation permanently after its attempt budget without changing local tags', function () {
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $requestLog = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::response([
            manualLeadTagRemoteTag(900, 'Imobiliária Azul'),
            manualLeadTagRemoteTag(102, 'Ruim'),
        ], 200),
    ]);
    $job = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $requestLog->id,
        phase: 'confirmation',
    ))->withFakeQueueInteractions();
    $job->tries = 1;

    handleManualLeadTagJob($job);

    $job->assertFailedWith(PermanentLeadTagException::class);
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
    );
});

it('fails a permanent API error without changing local tags', function () {
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $requestLog = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::response([
            manualLeadTagRemoteTag(102, 'Ruim'),
        ], 200),
        MANUAL_LEAD_TAG_API_URL.'/leads/tags' => Http::response([
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'Payload inválido.',
            ],
        ], 422),
    ]);
    $job = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $requestLog->id,
    ))->withFakeQueueInteractions();

    handleManualLeadTagJob($job);

    $job->assertFailedWith(LeadLoversApiException::class);
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
    $this->assertDatabaseMissing('logs_atividades_corretores', [
        'action' => 'lead_tag_update_pending_confirmation',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
    ]);
    Http::assertSentCount(2);
});

it('releases the job according to the remote rate limit', function () {
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $requestLog = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::response([
            'error' => [
                'code' => 'RATE_LIMITED',
                'message' => 'Tente novamente.',
            ],
        ], 429, ['RateLimit-Reset' => '30']),
    ]);
    $job = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $requestLog->id,
    ))->withFakeQueueInteractions();

    handleManualLeadTagJob($job);

    $job->assertReleased(30);
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
    Http::assertSentCount(1);
});

it('does not persist sensitive exception details in the failure audit', function () {
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $job = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    );

    $job->failed(new RuntimeException(
        'Connection failed for https://api.leadlovers.test?token='.
        MANUAL_LEAD_TAG_TOKEN
    ));

    $failureLog = CorretorActivityLog::query()
        ->where('action', 'lead_tag_update_failed')
        ->sole();
    $serializedAudit = json_encode(
        $failureLog->new_values,
        JSON_THROW_ON_ERROR
    );

    expect($serializedAudit)
        ->not->toContain(MANUAL_LEAD_TAG_TOKEN)
        ->not->toContain('https://api.leadlovers.test');
});

it('uses one shared overlap lock per lead and keeps the configured backoff', function () {
    $firstJob = new ApplyManualLeadResultTagJob(
        10,
        ManualLeadResultTags::APPROVED,
        1,
        requestLogId: 100,
    );
    $secondJob = new ApplyManualLeadResultTagJob(
        10,
        ManualLeadResultTags::REJECTED,
        1,
        requestLogId: 101,
        phase: 'confirmation',
    );
    $firstMiddleware = $firstJob->middleware()[0];
    $secondMiddleware = $secondJob->middleware()[0];

    expect($firstJob->overlapKey())->toBe($secondJob->overlapKey())
        ->and($firstMiddleware->getLockKey($firstJob))
        ->toBe($secondMiddleware->getLockKey($secondJob))
        ->and($firstMiddleware->shareKey)->toBeTrue()
        ->and($firstMiddleware->expiresAfter)->toBeGreaterThan($firstJob->timeout)
        ->and($firstMiddleware->releaseAfter)->toBe(15)
        ->and($firstJob->backoff())->toBe([10, 30, 60, 120, 180]);
});

it('keeps queued jobs serialized before request and phase versioning compatible', function () {
    $job = new ApplyManualLeadResultTagJob(
        10,
        ManualLeadResultTags::APPROVED,
        1,
    );

    unset($job->requestLogId, $job->phase, $job->bulkAction);

    $restoredJob = unserialize(
        serialize($job),
        ['allowed_classes' => true]
    );

    expect($restoredJob)
        ->toBeInstanceOf(ApplyManualLeadResultTagJob::class)
        ->and($restoredJob->uniqueId())
        ->toBe('manual-lead-result-tag:10:legacy:approved');
});

it('stops a legacy queued job when its result is no longer current', function () {
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::REJECTED
    );
    $legacyJob = new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
    );
    unset($legacyJob->requestLogId, $legacyJob->phase, $legacyJob->bulkAction);
    $restoredJob = unserialize(
        serialize($legacyJob),
        ['allowed_classes' => true]
    );

    handleManualLeadTagJob($restoredJob);

    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
    Http::assertNothingSent();
});

it('drains an accepted older decision before submitting a newer decision', function () {
    Queue::fake();
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $coordinator = app(LeadLoversTagOperationCoordinator::class);
    $requestA = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    $stateA = $coordinator->registerManualDesired(
        $lead->id,
        'aprovados',
        ManualLeadResultTags::APPROVED,
        $requestA->id,
        $corretor->id,
    );
    $coordinator->claimBeforePost($lead->id, $stateA->version);
    $coordinator->markAccepted($lead->id, $stateA->version, [
        'actionId' => 8001,
        'status' => 'pending',
        'total' => 1,
    ]);
    $requestB = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::REJECTED
    );
    $stateB = $coordinator->registerManualDesired(
        $lead->id,
        'ruim',
        ManualLeadResultTags::REJECTED,
        $requestB->id,
        $corretor->id,
    );

    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::response([
            manualLeadTagRemoteTag(900, 'Imobiliária Azul'),
            manualLeadTagRemoteTag(101, 'Aprovados'),
        ], 200),
    ]);

    handleManualLeadTagJob(new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::REJECTED,
        $corretor->id,
        requestLogId: $requestB->id,
        phase: 'confirmation',
        version: $stateB->version,
    ));

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    expect($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
    $state = LeadLoversTagOperation::query()->sole();
    expect($state->version)->toBe($stateB->version)
        ->and($state->inflight_version)->toBeNull()
        ->and($state->desired_result)->toBe(ManualLeadResultTags::REJECTED)
        ->and($state->phase)->toBe(LeadLoversTagOperationCoordinator::PHASE_PENDING);
    Queue::assertPushed(
        ApplyManualLeadResultTagJob::class,
        fn (ApplyManualLeadResultTagJob $job): bool => $job->version === $stateB->version
            && $job->result === ManualLeadResultTags::REJECTED
            && $job->phase === null
    );
});

it('confirms before retrying one uncertain mutation for the same decision', function () {
    Queue::fake();
    $this->travelTo('2026-08-13 12:00:00');
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $request = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::APPROVED
    );
    $coordinator = app(LeadLoversTagOperationCoordinator::class);
    $state = $coordinator->registerManualDesired(
        $lead->id,
        'aprovados',
        ManualLeadResultTags::APPROVED,
        $request->id,
        $corretor->id,
    );
    $coordinator->claimBeforePost($lead->id, $state->version);
    $coordinator->markUncertain($lead->id, $state->version);
    config([
        'services.leadlovers.tag_uncertain_retry_checks' => 2,
        'services.leadlovers.tag_posting_stale_seconds' => 30,
        'services.leadlovers.tag_max_post_attempts' => 2,
    ]);

    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::response([
            manualLeadTagRemoteTag(102, 'Ruim'),
        ], 200),
        MANUAL_LEAD_TAG_API_URL.'/leads/tags' => Http::response([
            'actionId' => 8100,
            'status' => 'pending',
            'total' => 1,
        ], 202),
    ]);

    $firstCheck = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $request->id,
        phase: 'confirmation',
        version: $state->version,
    ))->withFakeQueueInteractions();
    handleManualLeadTagJob($firstCheck);
    $firstCheck->assertReleased(MANUAL_LEAD_TAG_CONFIRMATION_DELAY);
    Http::assertSentCount(1);

    $this->travel(31)->seconds();
    $secondCheck = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::APPROVED,
        $corretor->id,
        requestLogId: $request->id,
        phase: 'confirmation',
        version: $state->version,
    ))->withFakeQueueInteractions();
    handleManualLeadTagJob($secondCheck);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $httpRequest): bool => $httpRequest->method() === 'POST'
        && $httpRequest->data() === [
            'applyTags' => [101],
            'removeTags' => [102],
            'leadsIds' => [501],
        ]
    );
    $state = LeadLoversTagOperation::query()->sole();
    expect($state->post_attempts)->toBe(2)
        ->and($state->outcome_uncertain)->toBeFalse()
        ->and($state->action_id)->toBe(8100)
        ->and($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
});

it('blocks a newer decision behind an uncertain predecessor without posting it', function () {
    Queue::fake();
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $coordinator = app(LeadLoversTagOperationCoordinator::class);
    $requestA = manualLeadTagRequestLog($corretor, $lead, ManualLeadResultTags::APPROVED);
    $stateA = $coordinator->registerManualDesired(
        $lead->id,
        'aprovados',
        ManualLeadResultTags::APPROVED,
        $requestA->id,
        $corretor->id,
    );
    $coordinator->claimBeforePost($lead->id, $stateA->version);
    $coordinator->markUncertain($lead->id, $stateA->version);
    $requestB = manualLeadTagRequestLog($corretor, $lead, ManualLeadResultTags::REJECTED);
    $stateB = $coordinator->registerManualDesired(
        $lead->id,
        'ruim',
        ManualLeadResultTags::REJECTED,
        $requestB->id,
        $corretor->id,
    );

    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::response([
            manualLeadTagRemoteTag(900, 'Imobiliária Azul'),
        ], 200),
    ]);
    $job = (new ApplyManualLeadResultTagJob(
        $lead->id,
        ManualLeadResultTags::REJECTED,
        $corretor->id,
        requestLogId: $requestB->id,
        phase: 'confirmation',
        version: $stateB->version,
    ))->withFakeQueueInteractions();
    $job->tries = 1;

    handleManualLeadTagJob($job);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $httpRequest): bool => $httpRequest->method() === 'POST');
    $state = LeadLoversTagOperation::query()->sole();
    expect($state->phase)->toBe(LeadLoversTagOperationCoordinator::PHASE_BLOCKED)
        ->and($state->inflight_version)->toBe($stateA->version)
        ->and($state->version)->toBe($stateB->version)
        ->and($state->blocked_reason)->toBe('uncertain_predecessor')
        ->and($lead->fresh()->tags_originais)
        ->toBe('Imobiliária Azul, Ruim, Origem X');
});

it('does not let a completed stale manual job consume a newer accepted analysis', function () {
    Queue::fake();
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $coordinator = app(LeadLoversTagOperationCoordinator::class);
    $manualRequest = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::REJECTED
    );
    $manualState = $coordinator->registerManualDesired(
        $lead->id,
        'ruim',
        ManualLeadResultTags::REJECTED,
        $manualRequest->id,
        $corretor->id,
    );
    $coordinator->completeWithoutInflight($lead->id, $manualState->version);
    CorretorActivityLog::query()->create([
        'corretor_id' => $corretor->id,
        'action' => 'lead_tag_update_completed',
        'model_type' => Lead::class,
        'model_id' => $lead->id,
        'new_values' => [
            'request_log_id' => $manualRequest->id,
            'result' => ManualLeadResultTags::REJECTED,
        ],
    ]);

    $analysisState = $coordinator->registerAnalysisDesired(
        leadId: $lead->id,
        tagKey: 'aprovados',
        batchId: 9901,
        attemptId: 'analysis-a',
        isReanalysis: false,
    );
    $coordinator->claimBeforePost($lead->id, $analysisState->version);
    $coordinator->markAccepted($lead->id, $analysisState->version, [
        'actionId' => 9902,
        'status' => 'pending',
        'total' => 1,
    ]);

    $stateBefore = LeadLoversTagOperation::query()->sole()->getAttributes();
    $leadBefore = $lead->fresh()->getAttributes();
    $auditCountBefore = CorretorActivityLog::query()
        ->where('model_type', Lead::class)
        ->where('model_id', $lead->id)
        ->count();

    Http::fake([
        MANUAL_LEAD_TAG_API_URL.'/leads/501/tags' => Http::response([
            manualLeadTagRemoteTag(900, 'Imobiliária Azul'),
            manualLeadTagRemoteTag(101, 'Aprovados'),
        ], 200),
    ]);

    handleManualLeadTagJob(new ApplyManualLeadResultTagJob(
        leadId: $lead->id,
        result: ManualLeadResultTags::REJECTED,
        corretorId: $corretor->id,
        requestLogId: $manualRequest->id,
        phase: 'confirmation',
        bulkAction: [
            'actionId' => 8801,
            'status' => 'done',
            'total' => 1,
        ],
        version: $manualState->version,
    ));

    $stateAfter = LeadLoversTagOperation::query()->sole();
    expect($lead->fresh()->getAttributes())->toBe($leadBefore)
        ->and(CorretorActivityLog::query()
            ->where('model_type', Lead::class)
            ->where('model_id', $lead->id)
            ->count())->toBe($auditCountBefore)
        ->and($stateAfter->getAttributes())->toBe($stateBefore)
        ->and($stateAfter->phase)
        ->toBe(LeadLoversTagOperationCoordinator::PHASE_CONFIRMING)
        ->and($stateAfter->inflight_source)->toBe('analysis')
        ->and($stateAfter->inflight_version)->toBe($analysisState->version)
        ->and($stateAfter->action_id)->toBe(9902);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('fails an orphaned pending manual state so a later analysis can advance', function () {
    Queue::fake();
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $request = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::REJECTED
    );
    $coordinator = app(LeadLoversTagOperationCoordinator::class);
    $manualState = $coordinator->registerManualDesired(
        $lead->id,
        'ruim',
        ManualLeadResultTags::REJECTED,
        $request->id,
        $corretor->id,
    );

    $corretor->delete();

    $job = (new ApplyManualLeadResultTagJob(
        leadId: $lead->id,
        result: ManualLeadResultTags::REJECTED,
        corretorId: $corretor->id,
        requestLogId: $request->id,
        version: $manualState->version,
    ))->withFakeQueueInteractions();

    handleManualLeadTagJob($job);

    $job->assertFailedWith(PermanentLeadTagException::class);
    $failedState = LeadLoversTagOperation::query()->sole();
    expect($failedState->version)->toBe($manualState->version)
        ->and($failedState->desired_source)->toBe('manual')
        ->and($failedState->phase)
        ->toBe(LeadLoversTagOperationCoordinator::PHASE_FAILED)
        ->and($failedState->inflight_version)->toBeNull();

    Http::assertNothingSent();
    Queue::assertNothingPushed();

    $analysisState = $coordinator->registerAnalysisDesired(
        leadId: $lead->id,
        tagKey: 'aprovados',
        batchId: 9903,
        attemptId: 'analysis-after-orphan',
        isReanalysis: false,
    );

    expect($analysisState->version)->toBeGreaterThan($manualState->version)
        ->and($analysisState->desired_source)->toBe('analysis')
        ->and($analysisState->desired_tag_key)->toBe('aprovados')
        ->and($analysisState->desired_batch_id)->toBe(9903)
        ->and($analysisState->desired_attempt_id)->toBe('analysis-after-orphan')
        ->and($analysisState->phase)
        ->toBe(LeadLoversTagOperationCoordinator::PHASE_PENDING)
        ->and($analysisState->inflight_version)->toBeNull();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('blocks an orphaned accepted manual action without losing its remote metadata', function () {
    Queue::fake();
    manualLeadTagCatalog();
    $corretor = manualLeadTagCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => null,
    ]);
    $lead = manualLeadTagLead();
    $originalTags = $lead->tags_originais;
    $request = manualLeadTagRequestLog(
        $corretor,
        $lead,
        ManualLeadResultTags::REJECTED
    );
    $coordinator = app(LeadLoversTagOperationCoordinator::class);
    $manualState = $coordinator->registerManualDesired(
        $lead->id,
        'ruim',
        ManualLeadResultTags::REJECTED,
        $request->id,
        $corretor->id,
    );
    $coordinator->claimBeforePost($lead->id, $manualState->version);
    $coordinator->markAccepted($lead->id, $manualState->version, [
        'actionId' => 9904,
        'status' => 'pending',
        'total' => 1,
    ]);

    $corretor->delete();

    $job = (new ApplyManualLeadResultTagJob(
        leadId: $lead->id,
        result: ManualLeadResultTags::REJECTED,
        corretorId: $corretor->id,
        requestLogId: $request->id,
        phase: 'confirmation',
        version: $manualState->version,
    ))->withFakeQueueInteractions();

    handleManualLeadTagJob($job);

    $job->assertFailedWith(PermanentLeadTagException::class);
    $blocked = LeadLoversTagOperation::query()->sole();
    expect($blocked->version)->toBe($manualState->version)
        ->and($blocked->phase)
        ->toBe(LeadLoversTagOperationCoordinator::PHASE_BLOCKED)
        ->and($blocked->blocked_reason)->toBe('local_failure')
        ->and($blocked->inflight_source)->toBe('manual')
        ->and($blocked->inflight_version)->toBe($manualState->version)
        ->and($blocked->action_id)->toBe(9904)
        ->and($blocked->action_status)->toBe('pending')
        ->and($blocked->action_total)->toBe(1)
        ->and($lead->fresh()->tags_originais)
        ->toBe($originalTags);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
