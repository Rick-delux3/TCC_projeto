<?php

use App\Events\DashboardActivityChanged;
use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversLeadResolver;
use App\Services\LeadReanalysisService;
use App\Support\CorretorPermissions;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([]);
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.api_url' => 'https://api.leadlovers.test',
        'services.leadlovers.token' => 'test-token',
        'features.insurance_analysis.enabled' => false,
    ]);
});

function stageFourLead(array $overrides = []): Lead
{
    return Lead::query()->create(array_merge([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Pessoa Teste',
        'email' => 'person@example.test',
        'tel' => '(11) 99999-0000',
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 501,
        'sent_to_leadlovers_at' => now()->subMinutes(5),
        'leadlovers_update_status' => 'pending',
        'leadlovers_update_version' => 1,
    ], $overrides));
}

function stageFourSearchResult(array $records, ?int $total = null): array
{
    $total ??= count($records);

    return [
        'total' => $total,
        'records' => $records,
        'pagination' => [
            'current' => 1,
            'size' => 10,
            'next' => null,
            'prev' => null,
            'pages' => $total === 0 ? 0 : 1,
        ],
    ];
}

function stageFourSearchRecord(array $overrides = []): array
{
    return array_merge([
        'id' => 9001,
        'leadId' => 501,
        'email' => 'person@example.test',
        'createdAt' => '2026-08-11T12:00:00Z',
    ], $overrides);
}

function runStageFourUpdate(UpdateLeadOnLeadLoversJob $job): void
{
    $job->handle(
        app(LeadLoversApiClient::class),
        app(LeadLoversLeadResolver::class)
    );
}

function stageFourDashboardBroadcastMatches(
    BroadcastEvent $job,
    Lead $lead,
    string $change,
): bool {
    $event = $job->event;

    return $event instanceof DashboardActivityChanged
        && $event->resource === 'lead'
        && $event->resourceId === (int) $lead->id
        && $event->companyId === ($lead->company_id !== null
            ? (int) $lead->company_id
            : null)
        && $event->change === $change;
}

it('updates a lead by authoritative ID with camelCase fields and without tags or email', function () {
    Queue::fake();
    config([
        'services.leadlovers.dynamic_fields.cpf' => 101,
    ]);
    $lead = stageFourLead(['cpf' => '12345678900']);
    $lead->endereco()->create([
        'cidade_imovel' => 'Curitiba',
        'estado' => 'PR',
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/501' => Http::response([
            'success' => true,
        ]),
    ]);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        leadId: $lead->id,
        syncVersion: 1,
        requestedFields: ['name', 'phone', 'city', 'state', 'cpf'],
    ));

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PUT'
            && $request->url() === 'https://api.leadlovers.test/leads/501'
            && $request->hasHeader('x-api-token', 'test-token')
            && parse_url($request->url(), PHP_URL_QUERY) === null
            && $request->data() === [
                'staticFields' => [
                    'name' => 'Pessoa Teste',
                    'phone' => '11999990000',
                    'city' => 'Curitiba',
                    'state' => 'PR',
                ],
                'dynamicFields' => [[
                    'id' => 101,
                    'value' => '12345678900',
                ]],
            ]
            && ! array_key_exists('tags', $request->data())
            && ! array_key_exists('email', $request->data());
    });
    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('synced')
        ->leadlovers_lead_id->toBe(501)
        ->leadlovers_update_at->not->toBeNull();
    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        fn (BroadcastEvent $queued): bool => stageFourDashboardBroadcastMatches(
            $queued,
            $lead,
            'lead.sync.synced',
        )
    );
    Queue::assertPushed(BroadcastEvent::class, 1);
});

it('sends null when a changed static field was cleared', function () {
    $lead = stageFourLead(['tel' => null]);
    Http::fake([
        'https://api.leadlovers.test/leads/501' => Http::response([
            'success' => true,
        ]),
    ]);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['phone']
    ));

    Http::assertSent(
        fn (Request $request): bool => $request->data() === [
            'staticFields' => ['phone' => null],
        ]
    );
    expect($lead->refresh()->leadlovers_update_status)->toBe('synced');
});

it('fails closed without HTTP when a dynamic field is cleared', function () {
    config(['services.leadlovers.dynamic_fields.cpf' => 101]);
    $lead = stageFourLead(['cpf' => null]);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['cpf']
    ));

    Http::assertNothingSent();
    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_update_response)->toMatchArray([
            'operation' => 'local_preflight',
            'requested_fields' => ['cpf'],
            'unsupported_fields' => ['cpf'],
        ]);
});

it('fails locally when a requested dynamic field has no configured ID', function () {
    config(['services.leadlovers.dynamic_fields.cpf' => null]);
    $lead = stageFourLead(['cpf' => '12345678900']);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['cpf']
    ));

    Http::assertNothingSent();
    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_update_response['unsupported_fields'])
        ->toBe(['cpf']);
});

it('reconciles one old lead by exact email and persists leadId before PUT', function () {
    $lead = stageFourLead(['leadlovers_lead_id' => null]);
    Http::fake([
        'https://api.leadlovers.test/leads/search' => Http::response(
            stageFourSearchResult([
                stageFourSearchRecord([
                    'id' => 123,
                    'leadId' => 777,
                ]),
            ])
        ),
        'https://api.leadlovers.test/leads/777' => Http::response([
            'success' => true,
        ]),
    ]);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ));

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://api.leadlovers.test/leads/search') {
            return true;
        }

        return $request->method() === 'POST'
            && $request->data() === [
                'page' => 1,
                'pageSize' => 10,
                'filters' => [
                    'staticFields' => [
                        'email' => ['person@example.test'],
                    ],
                ],
            ];
    });
    Http::assertSentCount(2);
    expect($lead->refresh())
        ->leadlovers_lead_id->toBe(777)
        ->leadlovers_update_status->toBe('synced');
});

it('never falls back to record id when search leadId is missing', function () {
    $lead = stageFourLead(['leadlovers_lead_id' => null]);
    Http::fake([
        'https://api.leadlovers.test/leads/search' => Http::response(
            stageFourSearchResult([
                stageFourSearchRecord([
                    'id' => 777,
                    'leadId' => null,
                ]),
            ])
        ),
    ]);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ));

    Http::assertSentCount(1);
    expect($lead->refresh())
        ->leadlovers_lead_id->toBeNull()
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_update_response['search_outcome'])
        ->toBe('missing_lead_id');
});

it('fails safe when search is ambiguous or the email differs', function (array $records) {
    $lead = stageFourLead(['leadlovers_lead_id' => null]);
    Http::fake([
        'https://api.leadlovers.test/leads/search' => Http::response(
            stageFourSearchResult($records)
        ),
    ]);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ));

    Http::assertSentCount(1);
    expect($lead->refresh())
        ->leadlovers_lead_id->toBeNull()
        ->leadlovers_update_status->toBe('failed');
})->with([
    'two records' => [[
        stageFourSearchRecord(['leadId' => 701]),
        stageFourSearchRecord(['id' => 9002, 'leadId' => 702]),
    ]],
    'different email' => [[
        stageFourSearchRecord(['email' => 'other@example.test']),
    ]],
]);

it('reconciles at most once after LEAD_NOT_FOUND and retries PUT with the new ID', function () {
    $lead = stageFourLead(['leadlovers_lead_id' => 501]);
    Http::fake([
        'https://api.leadlovers.test/leads/501' => Http::response([
            'success' => false,
            'error' => ['code' => 'LEAD_NOT_FOUND'],
        ], 404),
        'https://api.leadlovers.test/leads/search' => Http::response(
            stageFourSearchResult([
                stageFourSearchRecord(['leadId' => 777]),
            ])
        ),
        'https://api.leadlovers.test/leads/777' => Http::response([
            'success' => true,
        ]),
    ]);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ));

    Http::assertSentCount(3);
    expect($lead->refresh())
        ->leadlovers_lead_id->toBe(777)
        ->leadlovers_update_status->toBe('synced');
});

it('does not create a lead when a 404 cannot be reconciled', function () {
    $lead = stageFourLead();
    Http::fake([
        'https://api.leadlovers.test/leads/501' => Http::response([
            'success' => false,
            'error' => ['code' => 'LEAD_NOT_FOUND'],
        ], 404),
        'https://api.leadlovers.test/leads/search' => Http::response(
            stageFourSearchResult([], 0)
        ),
    ]);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ));

    Http::assertSentCount(2);
    Http::assertNotSent(
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.leadlovers.test/leads/'
    );
    expect($lead->refresh()->leadlovers_update_status)->toBe('failed');
});

it('releases transient update failures including TIMEOUT and rate limit', function (
    int $status,
    array $body,
    array $headers,
    int $expectedDelay,
) {
    $lead = stageFourLead();
    Http::fake([
        'https://api.leadlovers.test/leads/501' => Http::response(
            $body,
            $status,
            $headers
        ),
    ]);
    $job = (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ))->withFakeQueueInteractions();

    runStageFourUpdate($job);

    $job->assertReleased($expectedDelay);
    expect($lead->refresh()->leadlovers_update_status)->toBe('processing');
})->with([
    '422 TIMEOUT' => [
        422,
        ['success' => false, 'error' => ['code' => 'TIMEOUT']],
        [],
        30,
    ],
    '429 reset' => [
        429,
        ['error' => 'rate_limit', 'message' => 'Too many requests'],
        ['RateLimit-Reset' => '17'],
        17,
    ],
    '503' => [
        503,
        ['success' => false, 'error' => ['code' => 'UNAVAILABLE']],
        [],
        30,
    ],
]);

it('releases a connection failure without exposing transport details', function () {
    $lead = stageFourLead();
    Http::fake([
        'https://api.leadlovers.test/leads/501' => Http::failedConnection(
            'connection failed for person@example.test and test-token'
        ),
    ]);
    $job = (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ))->withFakeQueueInteractions();

    runStageFourUpdate($job);

    $job->assertReleased(30);
    $stored = json_encode(
        $lead->refresh()->leadlovers_update_response,
        JSON_THROW_ON_ERROR
    );
    expect($lead->leadlovers_update_status)->toBe('processing')
        ->and($stored)->not->toContain('person@example.test')
        ->not->toContain('test-token');
});

it('retries a malformed 200 response as a protocol failure', function () {
    $lead = stageFourLead();
    Http::fake([
        'https://api.leadlovers.test/leads/501' => Http::response([
            'success' => false,
        ]),
    ]);
    $job = (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ))->withFakeQueueInteractions();

    runStageFourUpdate($job);

    $job->assertReleased(30);
    expect($lead->refresh()->leadlovers_update_status)->toBe('processing');
});

it('marks a transient update failed after the configured attempt budget', function () {
    Queue::fake();
    $lead = stageFourLead(['leadlovers_update_status' => 'processing']);
    Http::fake([
        'https://api.leadlovers.test/leads/501' => Http::response([
            'success' => false,
            'error' => ['code' => 'TRANSACTION_FAILED'],
        ], 422),
    ]);
    $job = (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ))->withFakeQueueInteractions();
    $job->job->attempts = 3;

    runStageFourUpdate($job);

    $job->assertNotReleased();
    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->leadlovers_update_error->toContain('tentativas');
    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        fn (BroadcastEvent $queued): bool => stageFourDashboardBroadcastMatches(
            $queued,
            $lead,
            'lead.sync.failed',
        )
    );
    Queue::assertPushed(BroadcastEvent::class, 1);
});

it('marks definitive update errors as failed without changing initial-send state', function (
    int $status,
    string $code,
) {
    $lead = stageFourLead();
    Http::fake([
        'https://api.leadlovers.test/leads/501' => Http::response([
            'success' => false,
            'error' => ['code' => $code],
        ], $status),
    ]);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ));

    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_update_response['error_code'])->toBe($code);
})->with([
    'EMAIL_EXISTS' => [400, 'EMAIL_EXISTS'],
    'PHONE_EXISTS' => [400, 'PHONE_EXISTS'],
    'VALIDATION_FAILED' => [422, 'VALIDATION_FAILED'],
    'unauthorized' => [401, 'UNAUTHORIZED'],
]);

it('allows an explicit retry of the same failed version', function () {
    $lead = stageFourLead(['leadlovers_update_status' => 'failed']);
    Http::fake([
        'https://api.leadlovers.test/leads/501' => Http::response([
            'success' => true,
        ]),
    ]);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ));

    expect($lead->refresh())
        ->leadlovers_update_version->toBe(1)
        ->leadlovers_update_status->toBe('synced');
});

it('does not let a stale job or failed callback overwrite a newer version', function () {
    $lead = stageFourLead([
        'leadlovers_update_version' => 2,
        'leadlovers_update_status' => 'pending',
    ]);
    $job = new UpdateLeadOnLeadLoversJob($lead->id, 1, ['name']);

    runStageFourUpdate($job);
    $job->failed(new RuntimeException('old job'));

    Http::assertNothingSent();
    expect($lead->refresh())
        ->leadlovers_update_version->toBe(2)
        ->leadlovers_update_status->toBe('pending');
});

it('queues a newer reconciliation when an old PUT finishes after a newer local edit', function () {
    Queue::fake();
    $lead = stageFourLead();
    Http::fake(function (Request $request) use ($lead) {
        Lead::query()->whereKey($lead->id)->update([
            'leadlovers_update_version' => 2,
            'leadlovers_update_status' => 'pending',
            'leadlovers_update_response' => json_encode([
                'requested_fields' => ['phone'],
            ], JSON_THROW_ON_ERROR),
        ]);

        return Http::response(['success' => true]);
    });

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ));

    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $queued): bool => $queued->syncVersion === 3
            && $queued->requestedFields === ['name', 'phone']
            && $queued->afterCommit === true
    );
    expect($lead->refresh())
        ->leadlovers_update_version->toBe(3)
        ->leadlovers_update_status->toBe('pending');
});

it('keeps old serialized jobs without version or field context from calling HTTP', function () {
    $lead = stageFourLead([
        'leadlovers_update_version' => 1,
        'leadlovers_update_status' => 'pending',
    ]);

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob($lead->id));

    Http::assertNothingSent();
    expect($lead->refresh()->leadlovers_update_status)->toBe('pending');
});

it('does no HTTP while integration is disabled', function () {
    config(['services.leadlovers.enabled' => false]);
    $lead = stageFourLead();

    runStageFourUpdate(new UpdateLeadOnLeadLoversJob(
        $lead->id,
        1,
        ['name']
    ));

    Http::assertNothingSent();
    expect($lead->refresh()->leadlovers_update_status)->toBe('disabled');
});

it('persists locally and dispatches the ID-based update after commit', function () {
    Queue::fake();
    $lead = stageFourLead([
        'nome' => 'Nome anterior',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);

    $result = app(LeadReanalysisService::class)
        ->updateLeadDataAndMaybeUnlock($lead, [
            'nome' => 'Nome atualizado',
        ]);

    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $queued): bool => $queued->leadId === $lead->id
            && $queued->syncVersion === 1
            && $queued->requestedFields === ['name']
            && ! str_contains(serialize($queued), 'person@example.test')
            && $queued->queue === 'leadlovers'
            && $queued->afterCommit === true
    );
    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        fn (BroadcastEvent $queued): bool => stageFourDashboardBroadcastMatches(
            $queued,
            $lead,
            'lead.sync.processing',
        )
    );
    Queue::assertPushed(BroadcastEvent::class, 1);
    expect($result['message'])->toContain('fila')
        ->and($lead->refresh())
        ->nome->toBe('Nome atualizado')
        ->leadlovers_update_status->toBe('pending');
});

it('records the broker who requested a lead data synchronization', function () {
    Queue::fake();
    $corretor = Corretor::query()->create([
        'name' => 'Carlos da Silva',
        'email' => 'carlos-data-sync@example.test',
        'cpf' => '98765432100',
        'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => [
            CorretorPermissions::VIEW_LEADS,
            CorretorPermissions::EDIT_LEADS,
        ],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
    $lead = stageFourLead([
        'nome' => 'Nome anterior',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);

    $this
        ->actingAs($corretor, 'admin')
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.15'])
        ->withHeader('User-Agent', 'Dashboard data sync test')
        ->post(route('admin.leads.update', $lead), [
            'nome' => 'Nome atualizado pelo corretor',
        ])
        ->assertRedirect();

    $requestLog = CorretorActivityLog::query()
        ->where('action', 'lead_data_update_requested')
        ->where('model_type', Lead::class)
        ->where('model_id', $lead->id)
        ->sole();

    expect($lead->refresh())
        ->nome->toBe('Nome atualizado pelo corretor')
        ->updated_by_corretor_id->toBe($corretor->id)
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_version->toBe(1)
        ->and($requestLog)
        ->corretor_id->toBe($corretor->id)
        ->ip->toBe('192.0.2.15')
        ->user_agent->toBe('Dashboard data sync test')
        ->and($requestLog->old_values)->toMatchArray([
            'leadlovers_update_status' => 'idle',
            'leadlovers_update_version' => 0,
        ])
        ->and($requestLog->new_values)->toMatchArray([
            'leadlovers_update_status' => 'pending',
            'leadlovers_update_version' => 1,
            'requested_fields' => ['name'],
        ]);

    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $job): bool => $job->leadId === $lead->id
            && $job->syncVersion === 1
            && $job->requestedFields === ['name']
    );
    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        fn (BroadcastEvent $event): bool => stageFourDashboardBroadcastMatches(
            $event,
            $lead,
            'lead.sync.processing',
        )
    );
});

it('keeps the local save and marks only the matching version failed when queueing fails', function () {
    $broadcastJobs = [];
    $queue = Mockery::mock(QueueContract::class);
    $queue->shouldReceive('pushOn')
        ->twice()
        ->withArgs(
            fn (string $queueName, mixed $job): bool => $queueName === 'broadcasts'
                && $job instanceof BroadcastEvent
        )
        ->andReturnUsing(function (string $queueName, BroadcastEvent $job) use (&$broadcastJobs) {
            $broadcastJobs[] = $job;

            return null;
        });
    $queue->shouldReceive('push')
        ->once()
        ->andThrow(new RuntimeException('queue unavailable'));
    Queue::shouldReceive('connection')
        ->times(3)
        ->andReturn($queue);
    $lead = stageFourLead([
        'nome' => 'Nome anterior',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);

    $result = app(LeadReanalysisService::class)
        ->updateLeadDataAndMaybeUnlock($lead, [
            'nome' => 'Nome salvo localmente',
        ]);

    expect($result['message'])->toContain('enfileirada')
        ->and($lead->refresh())
        ->nome->toBe('Nome salvo localmente')
        ->leadlovers_update_version->toBe(1)
        ->leadlovers_update_status->toBe('failed')
        ->leadlovers_status->toBe('sent')
        ->and($broadcastJobs)->toHaveCount(2)
        ->and(stageFourDashboardBroadcastMatches(
            $broadcastJobs[0],
            $lead,
            'lead.sync.processing',
        ))->toBeTrue()
        ->and(stageFourDashboardBroadcastMatches(
            $broadcastJobs[1],
            $lead,
            'lead.sync.failed',
        ))->toBeTrue();
    Http::assertNothingSent();
});

it('starts a general reanalysis with all prepared analyses and one dashboard broadcast', function () {
    config(['features.insurance_analysis.enabled' => true]);
    $lead = stageFourLead([
        'status' => 'qualificado',
    ]);
    $lead->forceFill([
        'analysis_final_status' => 'approved',
        'reanalysis_unlocked_at' => now(),
    ])->save();
    $lead->despesas()->create([
        'valor_aluguel' => 1500,
        'valor_total_encargos' => 1750,
    ]);
    $batch = InsuranceAnalysisBatch::query()->create([
        'lead_id' => $lead->id,
        'status' => 'completed',
        'total_providers' => 1,
        'completed_providers' => 1,
        'finished_at' => now(),
    ]);
    $analysis = InsuranceAnalysis::query()->create([
        'insurance_analysis_batch_id' => $batch->id,
        'lead_id' => $lead->id,
        'provider' => 'pottencial',
        'product' => 'seguro_fianca_residencial',
        'status' => 'approved',
        'result' => 'approved',
        'rent_amount' => 1500,
        'charges_amount' => 250,
        'total_monthly_amount' => 1750,
        'finished_at' => now(),
    ]);
    Bus::fake();
    Queue::fake();

    $total = app(LeadReanalysisService::class)->startGeneralReanalysis(
        lead: $lead,
        requestedBy: 'admin',
    );

    expect($total)->toBe(1)
        ->and($lead->refresh()->status)->toBe('em-andamento')
        ->and($lead->reanalysis_unlocked_at)->toBeNull()
        ->and($analysis->refresh()->status)->toBe('pending')
        ->and($analysis->result)->toBeNull()
        ->and($batch->refresh()->status)->toBe('processing')
        ->and($batch->completed_providers)->toBe(0)
        ->and($batch->finished_at)->toBeNull();

    Bus::assertBatched(function (PendingBatch $pendingBatch) use ($analysis): bool {
        if ($pendingBatch->jobs->count() !== 1) {
            return false;
        }

        $job = $pendingBatch->jobs->first();

        return $job instanceof RunProviderAnalysisJob
            && $job->analysisId === (int) $analysis->id
            && $job->isReanalysis === true;
    });
    Bus::assertBatchCount(1);
    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        fn (BroadcastEvent $queued): bool => stageFourDashboardBroadcastMatches(
            $queued,
            $lead,
            'lead.reanalysis.requested',
        )
    );
    Queue::assertPushed(BroadcastEvent::class, 1);
    Http::assertNothingSent();
});

it('unions pending remote fields across consecutive local edits', function () {
    Queue::fake();
    $lead = stageFourLead([
        'nome' => 'Nome anterior',
        'tel' => '11900000000',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);
    $service = app(LeadReanalysisService::class);

    $service->updateLeadDataAndMaybeUnlock($lead, ['nome' => 'Nome novo']);
    $service->updateLeadDataAndMaybeUnlock($lead, ['tel' => '11999990000']);

    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $queued): bool => $queued->syncVersion === 2
            && $queued->requestedFields === ['name', 'phone']
    );
    expect($lead->refresh()->leadlovers_update_response['requested_fields'])
        ->toBe(['name', 'phone']);
});

it('waits for the initial send and keeps accumulated fields', function () {
    Queue::fake();
    $lead = stageFourLead([
        'nome' => 'Nome anterior',
        'leadlovers_status' => 'pending',
        'leadlovers_lead_id' => null,
        'sent_to_leadlovers_at' => null,
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);

    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['nome' => 'Nome atualizado']
    );

    Queue::assertNotPushed(UpdateLeadOnLeadLoversJob::class);
    expect($lead->refresh())
        ->leadlovers_update_status->toBe('waiting_initial_send')
        ->and($lead->leadlovers_update_response['requested_fields'])
        ->toBe(['name']);
});

it('does not disturb a pending remote sync for a local-only edit', function () {
    Queue::fake();
    $lead = stageFourLead([
        'leadlovers_update_status' => 'failed',
        'leadlovers_update_version' => 4,
        'leadlovers_update_error' => 'Falha anterior',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
        ],
    ]);

    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['cep' => '01001-000']
    );

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->leadlovers_update_version->toBe(4)
        ->leadlovers_update_error->toBe('Falha anterior')
        ->and($lead->leadlovers_update_response['requested_fields'])
        ->toBe(['name']);
    Queue::assertNotPushed(UpdateLeadOnLeadLoversJob::class);
});

it('normalizes the legacy sent lifecycle before queueing an ID-based update', function () {
    Queue::fake();
    $lead = stageFourLead([
        'nome' => 'Nome anterior',
        'leadlovers_status' => 'send',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);

    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['nome' => 'Nome atualizado']
    );

    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('pending');
    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $job): bool => $job->requestedFields === ['name']
    );
});

it('runs backfill dry-run for selected local IDs without persisting', function () {
    $lead = stageFourLead(['leadlovers_lead_id' => null]);
    $other = stageFourLead([
        'email' => 'other@example.test',
        'leadlovers_lead_id' => null,
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/search' => Http::response(
            stageFourSearchResult([
                stageFourSearchRecord(['leadId' => 777]),
            ])
        ),
    ]);

    $this->artisan('leadlovers:backfill-lead-ids', [
        '--dry-run' => true,
        '--id' => [(string) $lead->id],
        '--chunk' => 1,
    ])->assertSuccessful();

    Http::assertSentCount(1);
    expect($lead->refresh()->leadlovers_lead_id)->toBeNull()
        ->and($other->refresh()->leadlovers_lead_id)->toBeNull();
});

it('backfills an exact leadId and never overwrites an existing one', function () {
    $lead = stageFourLead(['leadlovers_lead_id' => null]);
    $existing = stageFourLead([
        'email' => 'existing@example.test',
        'leadlovers_lead_id' => 888,
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/search' => Http::response(
            stageFourSearchResult([
                stageFourSearchRecord(['leadId' => 777]),
            ])
        ),
    ]);

    $this->artisan('leadlovers:backfill-lead-ids', [
        '--id' => [(string) $lead->id, (string) $existing->id],
        '--chunk' => 1,
    ])->assertSuccessful();

    Http::assertSentCount(1);
    expect($lead->refresh()->leadlovers_lead_id)->toBe(777)
        ->and($existing->refresh()->leadlovers_lead_id)->toBe(888);
});

it('resumes backfill after a transient interruption without repeating reconciled leads', function () {
    $first = stageFourLead(['leadlovers_lead_id' => null]);
    $second = stageFourLead([
        'email' => 'resume@example.test',
        'leadlovers_lead_id' => null,
    ]);
    $run = 1;
    Http::fake(function (Request $request) use (&$run) {
        $email = data_get($request->data(), 'filters.staticFields.email.0');

        if ($email === 'person@example.test') {
            return Http::response(stageFourSearchResult([
                stageFourSearchRecord(['leadId' => 701]),
            ]));
        }

        if ($run === 1) {
            return Http::response([
                'success' => false,
                'error' => ['code' => 'UNAVAILABLE'],
            ], 503);
        }

        return Http::response(stageFourSearchResult([
            stageFourSearchRecord([
                'id' => 9002,
                'leadId' => 702,
                'email' => 'resume@example.test',
            ]),
        ]));
    });

    $this->artisan('leadlovers:backfill-lead-ids', ['--chunk' => 1])
        ->assertFailed();

    expect($first->refresh()->leadlovers_lead_id)->toBe(701)
        ->and($second->refresh()->leadlovers_lead_id)->toBeNull();

    $run = 2;
    $this->artisan('leadlovers:backfill-lead-ids', ['--chunk' => 1])
        ->assertSuccessful();

    Http::assertSentCount(3);
    expect($first->refresh()->leadlovers_lead_id)->toBe(701)
        ->and($second->refresh()->leadlovers_lead_id)->toBe(702);
});

it('reports ambiguous backfill matches without persisting an ID', function () {
    $lead = stageFourLead(['leadlovers_lead_id' => null]);
    Http::fake([
        'https://api.leadlovers.test/leads/search' => Http::response(
            stageFourSearchResult([
                stageFourSearchRecord(['leadId' => 701]),
                stageFourSearchRecord(['id' => 9002, 'leadId' => 702]),
            ])
        ),
    ]);

    $this->artisan('leadlovers:backfill-lead-ids', [
        '--id' => [(string) $lead->id],
    ])->assertFailed();

    expect($lead->refresh()->leadlovers_lead_id)->toBeNull();
});

it('reports a lead missing from backfill and leaves its ID empty', function () {
    $lead = stageFourLead(['leadlovers_lead_id' => null]);
    Http::fake([
        'https://api.leadlovers.test/leads/search' => Http::response(
            stageFourSearchResult([], 0)
        ),
    ]);

    $this->artisan('leadlovers:backfill-lead-ids', [
        '--id' => [(string) $lead->id],
    ])->expectsOutputToContain('ausente')
        ->assertFailed();

    expect($lead->refresh()->leadlovers_lead_id)->toBeNull();
});

it('validates configured custom-field IDs through the administrative command', function () {
    config([
        'services.leadlovers.dynamic_fields' => [
            'cpf' => 101,
            'estado_civil' => 102,
        ],
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/custom-fields' => Http::response([
            [
                'id' => 101,
                'name' => 'CPF',
                'label' => 'CPF',
                'tag' => 'cpf',
                'typeId' => 1,
                'order' => 1,
                'values' => [],
            ],
            [
                'id' => 102,
                'name' => 'Estado civil',
                'label' => 'Estado civil',
                'tag' => 'estado_civil',
                'typeId' => 1,
                'order' => 2,
                'values' => [],
            ],
        ]),
    ]);

    $this->artisan('leadlovers:validate-custom-fields')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('fails custom-field validation for missing or duplicate configured IDs', function () {
    config([
        'services.leadlovers.dynamic_fields' => [
            'cpf' => 101,
            'estado_civil' => 101,
            'conjuge_cpf' => 999,
        ],
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/custom-fields' => Http::response([
            [
                'id' => 101,
                'name' => 'CPF',
                'label' => 'CPF',
                'tag' => 'cpf',
                'typeId' => 1,
                'order' => null,
                'values' => [],
            ],
        ]),
    ]);

    $this->artisan('leadlovers:validate-custom-fields')
        ->assertFailed();

    Http::assertSentCount(1);
});

it('does not call either administrative endpoint while disabled', function () {
    config(['services.leadlovers.enabled' => false]);

    $this->artisan('leadlovers:backfill-lead-ids', ['--dry-run' => true])
        ->assertFailed();
    $this->artisan('leadlovers:validate-custom-fields')
        ->assertFailed();

    Http::assertNothingSent();
});
