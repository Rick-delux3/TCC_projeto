<?php

use App\Events\DashboardActivityChanged;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Services\LeadLoversApiClient;
use App\Services\LeadReanalysisService;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([]);

    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.api_url' => 'https://api.leadlovers.test',
        'services.leadlovers.token' => 'stage-three-test-token',
        'services.leadlovers.machine' => 456,
        'services.leadlovers.sequence_1' => 321,
        'services.leadlovers.sequence_2' => 654,
        'services.leadlovers.step' => 2,
        'services.leadlovers.machine_confirmation_delay_seconds' => 15,
        'services.leadlovers.dynamic_fields' => [
            'cpf' => 10,
            'estado_civil' => 11,
            'conjuge_cpf' => null,
            'valor_aluguel' => 12,
            'valor_agua' => null,
            'valor_luz' => null,
            'valor_gas' => 13,
            'valor_condominio' => null,
            'valor_iptu' => null,
            'outras_despesas' => null,
        ],
    ]);

    RateLimiter::clear(
        'leadlovers:requests:'.hash('sha256', 'stage-three-test-token')
    );
});

function leadForInitialLeadLoversSend(array $overrides = []): Lead
{
    LeadLoversTag::query()->updateOrCreate(
        ['leadlovers_tag_id' => 123],
        [
            'title' => 'Locatario',
            'key' => 'locatario',
            'active' => true,
        ]
    );

    $lead = Lead::query()->create(array_merge([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Pessoa Teste',
        'email' => 'person@example.test',
        'tel' => '11999999999',
        'cpf' => '12345678900',
        'estado_civil' => 'solteiro',
        'leadlovers_status' => 'pending',
    ], $overrides));

    $lead->endereco()->create([
        'cidade_imovel' => 'Sao Paulo',
        'estado' => 'SP',
    ]);
    $lead->despesas()->create([
        'valor_aluguel' => 1500,
        'valor_gas' => 0,
    ]);

    return $lead;
}

function machineAssociationForInitialSend(array $overrides = []): array
{
    return array_merge([
        'id' => 456,
        'name' => 'Maquina principal',
        'type' => 1,
        'level' => 2,
        'registerDate' => '2026-08-11T12:00:00Z',
        'status' => 'active',
        'sequence' => [
            'id' => 654,
            'name' => 'Sequencia locatario',
        ],
    ], $overrides);
}

function searchResultForInitialSend(array $records, ?int $total = null): array
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

function initialSendJob(Lead $lead, int $attempts = 1): SendLeadToLeadLoversJob
{
    $job = (new SendLeadToLeadLoversJob((int) $lead->id))
        ->withFakeQueueInteractions();
    $job->job->attempts = $attempts;

    return $job;
}

function initialDashboardBroadcastMatches(
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

it('adds and casts the remote lead identifier', function () {
    expect(Schema::hasColumn('leads', 'leadlovers_lead_id'))->toBeTrue();

    $lead = leadForInitialLeadLoversSend([
        'leadlovers_lead_id' => '501',
    ]);

    expect($lead->refresh()->leadlovers_lead_id)->toBe(501);
});

it('persists leadId before requesting the machine and keeps a 202 pending', function () {
    $lead = leadForInitialLeadLoversSend();

    Http::fake([
        'https://api.leadlovers.test/leads/' => function (Request $request) use ($lead) {
            expect($lead->refresh()->leadlovers_lead_id)->toBeNull();

            return Http::response([
                'success' => true,
                'leadId' => 501,
            ], 200);
        },
        'https://api.leadlovers.test/leads/move' => function (Request $request) use ($lead) {
            expect($lead->refresh()->leadlovers_lead_id)->toBe(501)
                ->and($request->data())->toBe([
                    'machineFrom' => 0,
                    'machineId' => 456,
                    'sequenceId' => 654,
                    'level' => 2,
                    'leadIds' => [501],
                ]);

            return Http::response([
                'actionId' => 9001,
                'status' => 'pending',
                'total' => 1,
            ], 202);
        },
    ]);

    $job = initialSendJob($lead);
    $job->handle(app(LeadLoversApiClient::class));

    $job->assertReleased(15);
    expect($lead->refresh())
        ->leadlovers_lead_id->toBe(501)
        ->leadlovers_status->toBe('processing')
        ->sent_to_leadlovers_at->toBeNull()
        ->and($lead->leadlovers_response)->toMatchArray([
            'success' => false,
            'phase' => 'machine_confirmation_pending',
            'lead_id' => 501,
            'action' => [
                'action_id' => 9001,
                'status' => 'pending',
                'total' => 1,
            ],
        ]);

    $requests = collect(Http::recorded())->map(fn (array $entry) => $entry[0]);
    expect($requests)->toHaveCount(2)
        ->and(parse_url($requests[0]->url(), PHP_URL_PATH))->toBe('/leads/')
        ->and(parse_url($requests[1]->url(), PHP_URL_PATH))->toBe('/leads/move');

    $creation = $requests[0];
    expect($creation->method())->toBe('POST')
        ->and($creation->hasHeader('x-api-token', 'stage-three-test-token'))->toBeTrue()
        ->and(parse_url($creation->url(), PHP_URL_QUERY))->toBeNull()
        ->and($creation->data())->toBe([
            'staticFields' => [
                'email' => 'person@example.test',
                'name' => 'Pessoa Teste',
                'phone' => '11999999999',
                'city' => 'Sao Paulo',
                'state' => 'SP',
                'company' => null,
            ],
            'tags' => [123],
            'dynamicFields' => [
                ['id' => 10, 'value' => '12345678900'],
                ['id' => 11, 'value' => 'solteiro'],
                ['id' => 12, 'value' => '1500.00'],
                ['id' => 13, 'value' => '0.00'],
            ],
        ]);
    expect($creation->body())->not->toContain('stage-three-test-token');
});

it('claims the send before HTTP so a concurrent old job cannot duplicate creation', function () {
    $lead = leadForInitialLeadLoversSend();
    Http::fake([
        'https://api.leadlovers.test/leads/' => function () use ($lead) {
            initialSendJob($lead)->handle(app(LeadLoversApiClient::class));

            return Http::response([
                'success' => true,
                'leadId' => 501,
            ], 200);
        },
        'https://api.leadlovers.test/leads/move' => Http::response([
            'actionId' => 9001,
            'status' => 'pending',
            'total' => 1,
        ], 202),
    ]);

    $job = initialSendJob($lead);
    $job->handle(app(LeadLoversApiClient::class));

    $creationRequests = collect(Http::recorded())->filter(
        fn (array $entry): bool => $entry[0]->method() === 'POST'
            && parse_url($entry[0]->url(), PHP_URL_PATH) === '/leads/'
    );
    expect($creationRequests)->toHaveCount(1)
        ->and($lead->refresh()->leadlovers_lead_id)->toBe(501);
});

it('does not orphan an edit made while the creation request is running', function () {
    Queue::fake();
    config(['features.insurance_analysis.enabled' => false]);
    $lead = leadForInitialLeadLoversSend([
        'nome' => 'Nome anterior',
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/' => function () use ($lead) {
            app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
                $lead,
                ['nome' => 'Nome alterado durante HTTP']
            );

            return Http::response([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                ],
            ], 422);
        },
    ]);

    $job = initialSendJob($lead);
    $job->handle(app(LeadLoversApiClient::class));

    $job->assertFailed();
    expect($lead->refresh())
        ->nome->toBe('Nome alterado durante HTTP')
        ->leadlovers_status->toBe('failed')
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_update_response['requested_fields'])
        ->toBe(['name']);
    Queue::assertNotPushed(UpdateLeadOnLeadLoversJob::class);
});

it('marks the lead sent only after the expected machine state is confirmed', function () {
    Queue::fake();
    $lead = leadForInitialLeadLoversSend([
        'email' => 'changed@example.test',
        'leadlovers_status' => 'processing',
        'leadlovers_lead_id' => 501,
        'leadlovers_update_status' => 'waiting_initial_send',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
        ],
        'leadlovers_response' => [
            'success' => false,
            'phase' => 'machine_confirmation_pending',
            'lead_id' => 501,
            'creation_email_encrypted' => Crypt::encryptString(
                'person@example.test'
            ),
            'action' => [
                'action_id' => 9001,
                'status' => 'done',
                'total' => 1,
            ],
        ],
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/501/machines' => Http::response([
            machineAssociationForInitialSend(),
        ], 200),
    ]);

    $job = initialSendJob($lead, 2);
    $job->handle(app(LeadLoversApiClient::class));

    $job->assertNotReleased();
    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_lead_id->toBe(501)
        ->sent_to_leadlovers_at->not->toBeNull()
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_version->toBe(1)
        ->and($lead->leadlovers_response)->toMatchArray([
            'success' => true,
            'phase' => 'machine_confirmed',
            'lead_id' => 501,
            'action' => [
                'action_id' => 9001,
                'status' => 'done',
                'total' => 1,
            ],
        ]);
    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $queued): bool => $queued->leadId === $lead->id
            && $queued->syncVersion === 1
            && $queued->requestedFields === ['name']
            && $queued->afterCommit === true
            && ! str_contains(serialize($queued), 'person@example.test')
    );
    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        fn (BroadcastEvent $queued): bool => initialDashboardBroadcastMatches(
            $queued,
            $lead,
            'lead.sync.sent',
        )
    );
    Queue::assertPushed(BroadcastEvent::class, 1);
    Http::assertSentCount(1);
});

it('queues a pending update by remote ID even when the frozen creation email is unavailable', function () {
    Queue::fake();
    $lead = leadForInitialLeadLoversSend([
        'email' => 'current@example.test',
        'leadlovers_status' => 'processing',
        'leadlovers_lead_id' => 501,
        'leadlovers_update_status' => 'waiting_initial_send',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
        ],
        'leadlovers_response' => [
            'success' => false,
            'phase' => 'machine_confirmation_pending',
            'lead_id' => 501,
            'creation_email_encrypted' => 'invalid-ciphertext',
        ],
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/501/machines' => Http::response([
            machineAssociationForInitialSend(),
        ], 200),
    ]);

    initialSendJob($lead, 2)->handle(app(LeadLoversApiClient::class));

    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_error->toBeNull();
    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $queued): bool => $queued->leadId === $lead->id
            && $queued->syncVersion === 1
            && $queued->requestedFields === ['name']
            && $queued->afterCommit === true
    );
});

it('releases confirmation when the expected machine is not visible and sends no post', function () {
    $lead = leadForInitialLeadLoversSend([
        'leadlovers_status' => 'processing',
        'leadlovers_lead_id' => 501,
        'leadlovers_response' => [
            'success' => false,
            'phase' => 'machine_confirmation_pending',
            'lead_id' => 501,
            'action' => [
                'action_id' => 9004,
                'status' => 'processing',
                'total' => 1,
            ],
        ],
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/501/machines' => Http::response([
            machineAssociationForInitialSend([
                'id' => 999,
            ]),
        ], 200),
    ]);

    $job = initialSendJob($lead, 2);
    $job->handle(app(LeadLoversApiClient::class));

    $job->assertReleased(30);
    expect($lead->refresh())
        ->leadlovers_status->toBe('processing')
        ->sent_to_leadlovers_at->toBeNull()
        ->and($lead->leadlovers_response['action'])->toBe([
            'action_id' => 9004,
            'status' => 'processing',
            'total' => 1,
        ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    Http::assertSentCount(1);
});

it('preserves the accepted action when confirmation attempts are exhausted', function () {
    $lead = leadForInitialLeadLoversSend([
        'leadlovers_status' => 'processing',
        'leadlovers_lead_id' => 501,
        'leadlovers_response' => [
            'success' => false,
            'phase' => 'machine_confirmation_pending',
            'lead_id' => 501,
            'action' => [
                'action_id' => 9010,
                'status' => 'processing',
                'total' => 1,
            ],
        ],
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/501/machines' => Http::response([], 200),
    ]);

    $job = initialSendJob($lead, 12);
    $job->handle(app(LeadLoversApiClient::class));

    $job->assertFailed();
    expect($lead->refresh())
        ->leadlovers_status->toBe('failed')
        ->sent_to_leadlovers_at->toBeNull()
        ->and($lead->leadlovers_response['action'])->toBe([
            'action_id' => 9010,
            'status' => 'processing',
            'total' => 1,
        ]);
});

it('reconciles EMAIL_EXISTS by exact email and uses leadId instead of record id', function () {
    $lead = leadForInitialLeadLoversSend();
    Http::fake([
        'https://api.leadlovers.test/leads/' => Http::response([
            'success' => false,
            'error' => [
                'code' => 'EMAIL_EXISTS',
            ],
        ], 400),
        'https://api.leadlovers.test/leads/search' => Http::response(
            searchResultForInitialSend([[
                'id' => 999,
                'leadId' => 501,
                'email' => 'person@example.test',
                'createdAt' => '2026-08-11T12:00:00Z',
            ]]),
            200
        ),
        'https://api.leadlovers.test/leads/501/machines' => Http::response([], 200),
        'https://api.leadlovers.test/leads/move' => Http::response([
            'actionId' => 9002,
            'status' => 'mapping',
            'total' => 1,
        ], 202),
    ]);

    $job = initialSendJob($lead);
    $job->handle(app(LeadLoversApiClient::class));

    $job->assertReleased(15);
    expect($lead->refresh()->leadlovers_lead_id)->toBe(501);
    Http::assertSent(fn (Request $request): bool => parse_url(
        $request->url(),
        PHP_URL_PATH
    ) === '/leads/move' && $request->data()['leadIds'] === [501]);
    Http::assertSentCount(4);
});

it('fails closed when EMAIL_EXISTS search is not uniquely identifiable', function (array $records, int $total) {
    $lead = leadForInitialLeadLoversSend();
    Http::fake([
        'https://api.leadlovers.test/leads/' => Http::response([
            'success' => false,
            'error' => [
                'code' => 'EMAIL_EXISTS',
            ],
        ], 400),
        'https://api.leadlovers.test/leads/search' => Http::response(
            searchResultForInitialSend($records, $total),
            200
        ),
    ]);

    $job = initialSendJob($lead);
    $job->handle(app(LeadLoversApiClient::class));

    $job->assertFailed();
    expect($lead->refresh())
        ->leadlovers_status->toBe('failed')
        ->leadlovers_lead_id->toBeNull()
        ->sent_to_leadlovers_at->toBeNull();
    Http::assertSentCount(2);
})->with([
    'two exact records' => [[
        [
            'id' => 900,
            'leadId' => 501,
            'email' => 'person@example.test',
            'createdAt' => '2026-08-11T12:00:00Z',
        ],
        [
            'id' => 901,
            'leadId' => 502,
            'email' => 'person@example.test',
            'createdAt' => '2026-08-11T12:01:00Z',
        ],
    ], 2],
    'missing leadId' => [[
        [
            'id' => 900,
            'leadId' => null,
            'email' => 'person@example.test',
            'createdAt' => '2026-08-11T12:00:00Z',
        ],
    ], 1],
    'different email' => [[
        [
            'id' => 900,
            'leadId' => 501,
            'email' => 'other@example.test',
            'createdAt' => '2026-08-11T12:00:00Z',
        ],
    ], 1],
]);

it('reconciles an ambiguous create on retry without posting another lead', function () {
    $lead = leadForInitialLeadLoversSend();
    Http::fake([
        'https://api.leadlovers.test/leads/' => function () use ($lead) {
            $lead->forceFill([
                'email' => 'changed@example.test',
            ])->save();

            return Http::response([
                'success' => false,
                'error' => [
                    'code' => 'TEMPORARY_FAILURE',
                ],
            ], 500);
        },
        'https://api.leadlovers.test/leads/search' => function (Request $request) {
            expect($request->data()['filters']['staticFields']['email'])
                ->toBe(['person@example.test']);

            return Http::response(searchResultForInitialSend([[
                'id' => 999,
                'leadId' => 501,
                'email' => 'person@example.test',
                'createdAt' => '2026-08-11T12:00:00Z',
            ]]), 200);
        },
        'https://api.leadlovers.test/leads/501/machines' => Http::response([], 200),
        'https://api.leadlovers.test/leads/move' => Http::response([
            'actionId' => 9003,
            'status' => 'processing',
            'total' => 1,
        ], 202),
    ]);

    $firstAttempt = initialSendJob($lead);
    $firstAttempt->handle(app(LeadLoversApiClient::class));
    $firstAttempt->assertReleased(15);

    expect($lead->refresh())
        ->leadlovers_status->toBe('processing')
        ->leadlovers_lead_id->toBeNull()
        ->and($lead->leadlovers_response['phase'])
        ->toBe('lead_reconciliation_pending');
    expect(json_encode($lead->leadlovers_response))
        ->not->toContain('person@example.test')
        ->not->toContain('changed@example.test');

    $secondAttempt = initialSendJob($lead, 2);
    $secondAttempt->handle(app(LeadLoversApiClient::class));
    $secondAttempt->assertReleased(30);

    expect($lead->refresh()->leadlovers_lead_id)->toBe(501);
    $creationRequests = collect(Http::recorded())->filter(
        fn (array $entry): bool => $entry[0]->method() === 'POST'
            && parse_url($entry[0]->url(), PHP_URL_PATH) === '/leads/'
    );
    expect($creationRequests)->toHaveCount(1);
});

it('confirms state after ACTIVE_COPY_BETWEEN_MACHINES before completing', function () {
    $lead = leadForInitialLeadLoversSend([
        'leadlovers_lead_id' => 501,
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/501/machines' => Http::sequence()
            ->push([], 200)
            ->push([machineAssociationForInitialSend()], 200),
        'https://api.leadlovers.test/leads/move' => Http::response([
            'success' => false,
            'error' => [
                'code' => 'ACTIVE_COPY_BETWEEN_MACHINES',
            ],
        ], 409),
    ]);

    $job = initialSendJob($lead);
    $job->handle(app(LeadLoversApiClient::class));

    $job->assertNotReleased();
    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->sent_to_leadlovers_at->not->toBeNull();
    Http::assertSentCount(3);
});

it('backs off after ACTIVE_COPY when the expected state is still absent', function () {
    $lead = leadForInitialLeadLoversSend([
        'leadlovers_lead_id' => 501,
    ]);
    Http::fake([
        'https://api.leadlovers.test/leads/501/machines' => Http::sequence()
            ->push([], 200)
            ->push([], 200),
        'https://api.leadlovers.test/leads/move' => Http::response([
            'success' => false,
            'error' => [
                'code' => 'ACTIVE_COPY_BETWEEN_MACHINES',
            ],
        ], 409),
    ]);

    $job = initialSendJob($lead);
    $job->handle(app(LeadLoversApiClient::class));

    $job->assertReleased(15);
    expect($lead->refresh())
        ->leadlovers_status->toBe('processing')
        ->sent_to_leadlovers_at->toBeNull()
        ->and($lead->leadlovers_response['phase'])
        ->toBe('machine_conflict_pending');
});

it('does no HTTP while disabled and preserves an already known remote id', function () {
    config(['services.leadlovers.enabled' => false]);
    $lead = leadForInitialLeadLoversSend([
        'leadlovers_lead_id' => 501,
    ]);

    initialSendJob($lead)->handle(app(LeadLoversApiClient::class));

    expect($lead->refresh())
        ->leadlovers_status->toBe('disabled')
        ->leadlovers_lead_id->toBe(501);
    Http::assertNothingSent();
});

it('does not broadcast disabled twice when the initial send is already disabled', function () {
    config(['services.leadlovers.enabled' => false]);
    Queue::fake();
    $lead = leadForInitialLeadLoversSend();

    initialSendJob($lead)->handle(app(LeadLoversApiClient::class));

    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        fn (BroadcastEvent $queued): bool => initialDashboardBroadcastMatches(
            $queued,
            $lead,
            'lead.sync.disabled',
        )
    );
    Queue::assertPushed(BroadcastEvent::class, 1);

    Queue::fake();

    initialSendJob($lead->refresh())->handle(app(LeadLoversApiClient::class));

    Queue::assertNotPushed(BroadcastEvent::class);
    Http::assertNothingSent();
});

it('fails before creating a remote lead when machine configuration is invalid', function () {
    config(['services.leadlovers.machine' => null]);
    $lead = leadForInitialLeadLoversSend();

    $job = initialSendJob($lead);
    $job->handle(app(LeadLoversApiClient::class));

    expect($lead->refresh())
        ->leadlovers_status->toBe('sequence_failed')
        ->leadlovers_lead_id->toBeNull();
    Http::assertNothingSent();
});

it('does not let an old job overwrite a lead that is already sent', function () {
    $lead = leadForInitialLeadLoversSend([
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 777,
        'sent_to_leadlovers_at' => now(),
        'leadlovers_response' => [
            'success' => true,
            'phase' => 'machine_confirmed',
            'lead_id' => 777,
        ],
    ]);

    initialSendJob($lead)->handle(app(LeadLoversApiClient::class));

    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_lead_id->toBe(777)
        ->and($lead->leadlovers_response['lead_id'])->toBe(777);
    Http::assertNothingSent();
});

it('broadcasts a newly created company lead after the database transaction commits', function () {
    Bus::fake();
    Queue::fake();
    config(['features.insurance_analysis.enabled' => false]);
    $company = Imobiliaria::query()->create([
        'name' => 'Imobiliaria Formulario',
        'email' => 'company-create@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'lead_access_code' => 'NEW234',
        'lead_form_active' => true,
    ]);

    $response = $this->post(
        route('simulation.registered-company.store', [
            'code' => $company->lead_access_code,
        ]),
        [
            'aceite_termos' => '1',
            'nome' => 'Novo lead',
            'email' => 'new-broadcast@example.test',
            'tel' => '11988887777',
            'estado_civil' => 'solteiro',
            'valor_aluguel' => '1500',
            'cep' => '01001000',
            'logradouro' => 'Praca da Se',
            'numero' => '100',
            'bairro' => 'Se',
            'cidade_imovel' => 'Sao Paulo',
            'estado' => 'SP',
        ]
    );

    $response->assertRedirect(route('simulation.success'));
    $lead = Lead::query()
        ->where('email', 'new-broadcast@example.test')
        ->firstOrFail();

    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        fn (BroadcastEvent $queued): bool => initialDashboardBroadcastMatches(
            $queued,
            $lead,
            'lead.created',
        )
    );
    Queue::assertPushed(BroadcastEvent::class, 1);
});

it('broadcasts a newly created unlinked lead only to the admin dashboard', function () {
    Bus::fake();
    Queue::fake();
    config(['features.insurance_analysis.enabled' => false]);

    $response = $this->post(route('simulation.tenant.store'), [
        'aceite_termos' => '1',
        'nome' => 'Lead sem imobiliaria',
        'email' => 'unlinked-broadcast@example.test',
        'tel' => '11988886666',
        'estado_civil' => 'solteiro',
        'valor_aluguel' => '1500',
        'cep' => '01001000',
        'logradouro' => 'Praca da Se',
        'numero' => '100',
        'bairro' => 'Se',
        'cidade_imovel' => 'Sao Paulo',
        'estado' => 'SP',
    ]);

    $response->assertRedirect(route('simulation.success'));
    $lead = Lead::query()
        ->where('email', 'unlinked-broadcast@example.test')
        ->firstOrFail();

    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        function (BroadcastEvent $queued) use ($lead): bool {
            if (! initialDashboardBroadcastMatches($queued, $lead, 'lead.created')) {
                return false;
            }

            $channels = array_map(
                static fn ($channel): string => $channel->name,
                $queued->event->broadcastOn(),
            );

            return $channels === ['private-admins.dashboard'];
        }
    );
    Queue::assertPushed(BroadcastEvent::class, 1);
});

it('does not reset a confirmed remote identity when the form is submitted again', function () {
    Bus::fake();
    Queue::fake();
    config(['features.insurance_analysis.enabled' => false]);
    $company = Imobiliaria::query()->create([
        'name' => 'Imobiliaria Formulario',
        'email' => 'company-form@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'lead_access_code' => 'ABC234',
        'lead_form_active' => true,
    ]);
    $lead = Lead::query()->create([
        'company_id' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada',
        'origem' => 'imobiliaria_cadastrada',
        'nome' => 'Nome anterior',
        'email' => 'resubmission@example.test',
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 501,
        'sent_to_leadlovers_at' => now(),
    ]);

    $response = $this->post(
        route('simulation.registered-company.store', [
            'code' => $company->lead_access_code,
        ]),
        [
            'aceite_termos' => '1',
            'nome' => 'Nome reenviado',
            'email' => 'resubmission@example.test',
            'tel' => '11988887777',
            'estado_civil' => 'solteiro',
            'valor_aluguel' => '1500',
            'cep' => '01001000',
            'logradouro' => 'Praca da Se',
            'numero' => '100',
            'bairro' => 'Se',
            'cidade_imovel' => 'Sao Paulo',
            'estado' => 'SP',
        ]
    );

    $response->assertRedirect(route('simulation.success'));
    expect($lead->refresh())
        ->nome->toBe('Nome reenviado')
        ->leadlovers_status->toBe('sent')
        ->leadlovers_lead_id->toBe(501)
        ->sent_to_leadlovers_at->not->toBeNull();
    Queue::assertPushedOn(
        'broadcasts',
        BroadcastEvent::class,
        fn (BroadcastEvent $queued): bool => initialDashboardBroadcastMatches(
            $queued,
            $lead,
            'lead.updated',
        )
    );
    Queue::assertPushed(BroadcastEvent::class, 1);
});
