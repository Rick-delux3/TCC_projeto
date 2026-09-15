<?php

use App\Http\Requests\CorrectLeadLoversInitialFailureRequest;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadLoversInitialFailureRecoveryService;
use App\Support\LeadLoversInitialFailureCatalog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutVite();

    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();

    config([
        'features.insurance_analysis.enabled' => false,
        'services.leadlovers.enabled' => true,
        'services.leadlovers.token' => 'llifr-secret-token',
    ]);
});

function llifrCompany(array $overrides = []): Imobiliaria
{
    static $sequence = 0;

    $sequence++;

    return Imobiliaria::query()->create(array_merge([
        'name' => "Imobiliaria LLIFR {$sequence}",
        'email' => "llifr-company-{$sequence}@example.test",
        'phone' => '11999999999',
        'password' => 'password',
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'lead_form_active' => true,
    ], $overrides));
}

function llifrCompanyUser(Imobiliaria $company): User
{
    return User::factory()->create([
        'company_id' => $company->id,
    ]);
}

function llifrCorretor(array $overrides = []): Corretor
{
    static $sequence = 0;

    $sequence++;

    return Corretor::query()->create(array_merge([
        'name' => "Corretor LLIFR {$sequence}",
        'email' => "llifr-corretor-{$sequence}@example.test",
        'cpf' => str_pad((string) $sequence, 11, '0', STR_PAD_LEFT),
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ], $overrides));
}

function llifrLead(array $overrides = []): Lead
{
    static $sequence = 0;

    $sequence++;

    $phone = '119'.str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);

    return Lead::query()->create(array_merge([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => "Lead LLIFR {$sequence}",
        'email' => "llifr-lead-{$sequence}@example.test",
        'tel' => $phone,
        'cpf' => str_pad((string) $sequence, 11, '1', STR_PAD_LEFT),
        'status' => 'novo',
        'leadlovers_status' => 'failed',
        'leadlovers_response' => [
            'success' => false,
            'phase' => 'failed',
            'operation' => 'lead_creation',
            'status_code' => 400,
            'error_code' => 'PHONE_EXISTS',
            'safe_reason' => 'A LeadLovers recusou o telefone informado.',
        ],
        'leadlovers_initial_error_status' => 400,
        'leadlovers_initial_error_code' => 'PHONE_EXISTS',
        'leadlovers_initial_error_operation' => 'lead_creation',
        'leadlovers_initial_error_detail' => 'A LeadLovers recusou o telefone informado.',
        'leadlovers_initial_failed_at' => now(),
    ], $overrides));
}

function llifrDescribe(Lead $lead): array
{
    return app(LeadLoversInitialFailureCatalog::class)->describe(
        $lead->fresh()
    );
}

function llifrAdminPost(
    Tests\TestCase $test,
    Corretor $corretor,
    Lead $lead,
    array $payload,
) {
    return $test
        ->actingAs($corretor, 'admin')
        ->from('/Dashboard/Admin')
        ->post(
            route('admin.leads.leadlovers.correct', $lead),
            $payload
        );
}

function llifrAssertSessionHasError(
    TestResponse $response,
    string $field,
): TestResponse {
    return $response->assertSessionHas(
        'errors',
        function ($errors) use ($field): bool {
            foreach ($errors->getBags() as $bag) {
                if ($bag->has($field)) {
                    return true;
                }
            }

            return false;
        }
    );
}

function llifrRulesRequest(
    Lead $lead,
): CorrectLeadLoversInitialFailureRequest {
    $request = CorrectLeadLoversInitialFailureRequest::create(
        '/llifr/leads/'.$lead->id.'/correct',
        'POST'
    );
    $route = new Route(
        'POST',
        '/llifr/leads/{lead}/correct',
        fn () => null
    );
    $route->bind($request);
    $route->setParameter('lead', $lead);
    $request->setRouteResolver(fn (): Route => $route);

    return $request;
}

it('creates every initial failure column and the dashboard filter index', function () {
    expect(Schema::hasColumns('leads', [
        'leadlovers_initial_error_status',
        'leadlovers_initial_error_code',
        'leadlovers_initial_error_operation',
        'leadlovers_initial_error_detail',
        'leadlovers_initial_failed_at',
    ]))->toBeTrue();

    $index = collect(Schema::getIndexes('leads'))->first(
        fn (array $candidate): bool => $candidate['name'] === 'leads_ll_failure_filter_idx'
    );

    expect($index)->not->toBeNull()
        ->and(array_values($index['columns']))->toBe([
            'leadlovers_status',
            'leadlovers_initial_error_status',
            'sent_to_leadlovers_at',
        ])
        ->and($index['unique'])->toBeFalse();
});

it('backfills a legacy initial failure from the safe response summary', function () {
    $migration = require database_path(
        'migrations/2026_08_24_130737_add_leadlovers_initial_fields_to_leads_table.php'
    );

    $migration->down();

    $leadId = DB::table('leads')->insertGetId([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Lead legado LLIFR',
        'email' => 'llifr-legacy@example.test',
        'leadlovers_status' => 'failed',
        'leadlovers_response' => json_encode([
            'success' => false,
            'phase' => 'failed',
            'operation' => 'lead_creation',
            'status_code' => '400',
            'error_code' => ' phone_exists ',
            'safe_reason' => '<strong>Telefone recusado.</strong>',
        ]),
        'created_at' => now()->subDay(),
        'updated_at' => now()->subHour(),
    ]);

    $migration->up();

    $backfilled = DB::table('leads')->find($leadId);

    expect($backfilled)
        ->leadlovers_initial_error_status->toBe(400)
        ->leadlovers_initial_error_code->toBe('PHONE_EXISTS')
        ->leadlovers_initial_error_operation->toBe('lead_creation')
        ->leadlovers_initial_error_detail->toBe('Telefone recusado.')
        ->leadlovers_initial_failed_at->not->toBeNull();
});

it('maps PHONE_EXISTS exclusively to a correctable phone', function () {
    $description = llifrDescribe(llifrLead());

    expect($description)->toMatchArray([
        'failed' => true,
        'not_sent' => true,
        'http_status' => 400,
        'error_code' => 'PHONE_EXISTS',
        'operation' => 'lead_creation',
        'correctable' => true,
        'fields' => ['tel'],
    ])->and(Str::ascii(mb_strtolower($description['message'])))
        ->toContain('telefone informado');
});

it('maps unreconciled EMAIL_EXISTS exclusively to a correctable email', function () {
    $description = llifrDescribe(llifrLead([
        'leadlovers_initial_error_code' => 'EMAIL_EXISTS',
        'leadlovers_response' => [
            'success' => false,
            'phase' => 'failed',
            'operation' => 'lead_search',
            'status_code' => 400,
            'error_code' => 'EMAIL_EXISTS',
        ],
    ]));

    expect($description)->toMatchArray([
        'failed' => true,
        'not_sent' => true,
        'http_status' => 400,
        'error_code' => 'EMAIL_EXISTS',
        'correctable' => true,
        'fields' => ['email'],
    ])->and(Str::ascii(mb_strtolower($description['message'])))
        ->toContain('e-mail informado');
});

it('keeps configuration failures non-correctable even with a mapped HTTP 400 code', function (
    string $leadLoversStatus,
    string $errorCode,
    string $messageFragment,
) {
    $description = llifrDescribe(llifrLead([
        'leadlovers_status' => $leadLoversStatus,
        'leadlovers_initial_error_status' => 400,
        'leadlovers_initial_error_code' => $errorCode,
        'leadlovers_initial_error_operation' => 'configuration',
    ]));

    expect($description)->toMatchArray([
        'failed' => true,
        'not_sent' => true,
        'http_status' => 400,
        'error_code' => $errorCode,
        'correctable' => false,
        'fields' => [],
    ])->and(Str::ascii(mb_strtolower($description['message'])))
        ->toContain($messageFragment);
})->with([
    'tag failure carrying PHONE_EXISTS' => [
        'tag_failed',
        'PHONE_EXISTS',
        'tag principal',
    ],
    'sequence failure carrying EMAIL_EXISTS' => [
        'sequence_failed',
        'EMAIL_EXISTS',
        'maquina',
    ],
]);

it('describes an unknown HTTP 400 safely without offering correction fields', function () {
    $description = llifrDescribe(llifrLead([
        'leadlovers_initial_error_code' => 'NEW_REMOTE_RULE',
        'leadlovers_initial_error_detail' => null,
        'leadlovers_response' => [
            'status_code' => 400,
            'error_code' => 'NEW_REMOTE_RULE',
            'operation' => 'lead_creation',
        ],
    ]));

    expect($description)->toMatchArray([
        'failed' => true,
        'not_sent' => true,
        'http_status' => 400,
        'error_code' => 'NEW_REMOTE_RULE',
        'correctable' => false,
        'fields' => [],
    ])->and($description['message'])
        ->toContain('HTTP 400')
        ->toContain('NEW_REMOTE_RULE');
});

it('gives explicit non-correctable reasons for technical and configuration failures', function (
    array $overrides,
    string $messageFragment,
) {
    $description = llifrDescribe(llifrLead(array_merge([
        'leadlovers_initial_error_detail' => null,
        'leadlovers_response' => [],
    ], $overrides)));

    expect($description['failed'])->toBeTrue()
        ->and($description['correctable'])->toBeFalse()
        ->and($description['fields'])->toBe([])
        ->and(Str::ascii(mb_strtolower($description['message'])))
        ->toContain($messageFragment);
})->with([
    '401 credentials' => [[
        'leadlovers_initial_error_status' => 401,
        'leadlovers_initial_error_code' => 'UNAUTHORIZED',
    ], 'credenciais'],
    '422 timeout' => [[
        'leadlovers_initial_error_status' => 422,
        'leadlovers_initial_error_code' => 'TIMEOUT',
    ], 'tempo esperado'],
    '422 transaction failure' => [[
        'leadlovers_initial_error_status' => 422,
        'leadlovers_initial_error_code' => 'TRANSACTION_FAILED',
    ], 'transacao'],
    '429 rate limit' => [[
        'leadlovers_initial_error_status' => 429,
        'leadlovers_initial_error_code' => 'TOO_MANY_REQUESTS',
    ], 'limite de requisicoes'],
    'server failure' => [[
        'leadlovers_initial_error_status' => 503,
        'leadlovers_initial_error_code' => 'SERVICE_UNAVAILABLE',
    ], 'indisponibilidade interna'],
    'connection failure' => [[
        'leadlovers_initial_error_status' => null,
        'leadlovers_initial_error_code' => 'CONNECTION_FAILED',
        'leadlovers_initial_error_detail' => 'Nao foi possivel conectar a API da LeadLovers.',
    ], 'comunicacao'],
    'tag configuration' => [[
        'leadlovers_status' => 'tag_failed',
        'leadlovers_initial_error_status' => null,
        'leadlovers_initial_error_code' => null,
    ], 'tag principal'],
    'machine and sequence configuration' => [[
        'leadlovers_status' => 'sequence_failed',
        'leadlovers_initial_error_status' => null,
        'leadlovers_initial_error_code' => null,
    ], 'maquina'],
    'local queue dispatch' => [[
        'leadlovers_initial_error_status' => null,
        'leadlovers_initial_error_code' => 'LOCAL_QUEUE_DISPATCH_FAILED',
    ], 'fila'],
]);

it('never presents remote HTML script token email phone or raw payload', function () {
    $description = llifrDescribe(llifrLead([
        'leadlovers_initial_error_code' => 'UNKNOWN_REMOTE_RULE',
        'leadlovers_initial_error_detail' => null,
        'leadlovers_response' => [
            'status_code' => 400,
            'error_code' => 'UNKNOWN_REMOTE_RULE',
            'operation' => 'lead_creation',
            'safe_reason' => '<script>alert("llifr-xss")</script> '
                .'llifr-secret-token victim@example.test 11988887777',
            'remote_payload' => [
                'authorization' => 'Bearer raw-secret',
                'email' => 'raw@example.test',
                'phone' => '11977776666',
            ],
        ],
    ]));

    expect($description['message'])
        ->not->toContain('<script')
        ->not->toContain('llifr-xss')
        ->not->toContain('llifr-secret-token')
        ->not->toContain('victim@example.test')
        ->not->toContain('11988887777')
        ->not->toContain('raw-secret')
        ->not->toContain('raw@example.test')
        ->not->toContain('11977776666');
});

it('normalizes and accepts PHONE_EXISTS corrections with ten or eleven digits', function (
    string $submitted,
    string $expected,
) {
    $admin = llifrCorretor();
    $lead = llifrLead(['tel' => '11911112222']);

    llifrAdminPost($this, $admin, $lead, [
        'tel' => $submitted,
    ])->assertRedirect('/Dashboard/Admin')
        ->assertSessionHasNoErrors();

    expect($lead->refresh()->tel)->toBe($expected);
    Queue::assertPushedOn(
        'leadlovers',
        SendLeadToLeadLoversJob::class,
        fn (SendLeadToLeadLoversJob $job): bool => $job->leadId === $lead->id
    );
    Queue::assertPushed(SendLeadToLeadLoversJob::class, 1);
})->with([
    'ten digits' => ['(11) 3888-7777', '1138887777'],
    'eleven digits' => ['(11) 98888-7777', '11988887777'],
]);

it('rejects an invalid or previously refused phone', function (
    string $submitted,
) {
    $admin = llifrCorretor();
    $lead = llifrLead(['tel' => '(11) 98888-7777']);

    $response = llifrAdminPost($this, $admin, $lead, [
        'tel' => $submitted,
    ])->assertRedirect('/Dashboard/Admin');
    llifrAssertSessionHasError($response, 'tel');

    expect($lead->refresh()->tel)->toBe('(11) 98888-7777')
        ->and($lead->leadlovers_status)->toBe('failed');
    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
})->with([
    'too short' => ['119999999'],
    'too long' => ['119999999999'],
    'same formatted phone' => ['11 98888 7777'],
]);

it('drops malicious fields from a phone correction request', function () {
    $company = llifrCompany();
    $otherCompany = llifrCompany();
    $admin = llifrCorretor();
    $lead = llifrLead([
        'company_id' => $company->id,
        'nome' => 'Nome preservado LLIFR',
        'email' => 'llifr-preserved@example.test',
        'cpf' => '12345678900',
        'status' => 'novo',
    ]);

    llifrAdminPost($this, $admin, $lead, [
        'tel' => '(11) 97777-6666',
        'nome' => 'Nome malicioso',
        'email' => 'malicious@example.test',
        'cpf' => '00000000000',
        'status' => 'recusado',
        'company_id' => $otherCompany->id,
        'leadlovers_status' => 'sent',
        'observacoes' => 'campo indevido',
    ])->assertSessionHasNoErrors();

    expect($lead->refresh())
        ->tel->toBe('11977776666')
        ->nome->toBe('Nome preservado LLIFR')
        ->email->toBe('llifr-preserved@example.test')
        ->cpf->toBe('12345678900')
        ->status->toBe('novo')
        ->company_id->toBe($company->id)
        ->leadlovers_status->toBe('pending')
        ->observacoes->toBeNull();
});

it('normalizes and accepts only a valid different email for EMAIL_EXISTS', function () {
    $admin = llifrCorretor();
    $lead = llifrLead([
        'email' => 'llifr-old-email@example.test',
        'leadlovers_initial_error_code' => 'EMAIL_EXISTS',
    ]);

    llifrAdminPost($this, $admin, $lead, [
        'email' => '  LLIFR.New.Address@Example.COM  ',
        'tel' => '11900000000',
        'nome' => 'Nao deve mudar',
    ])->assertRedirect('/Dashboard/Admin')
        ->assertSessionHasNoErrors();

    expect($lead->refresh())
        ->email->toBe('llifr.new.address@example.com')
        ->tel->not->toBe('11900000000')
        ->nome->not->toBe('Nao deve mudar');
    Queue::assertPushed(SendLeadToLeadLoversJob::class, 1);
});

it('rejects an invalid or previously refused email', function (
    string $submitted,
) {
    $admin = llifrCorretor();
    $lead = llifrLead([
        'email' => 'llifr-refused@example.test',
        'leadlovers_initial_error_code' => 'EMAIL_EXISTS',
    ]);

    $response = llifrAdminPost($this, $admin, $lead, [
        'email' => $submitted,
    ])->assertRedirect('/Dashboard/Admin');
    llifrAssertSessionHasError($response, 'email');

    expect($lead->refresh()->email)->toBe('llifr-refused@example.test');
    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
})->with([
    'invalid syntax' => ['not-an-email'],
    'same case insensitive email' => [' LLIFR-REFUSED@EXAMPLE.TEST '],
]);

it('enforces the project local identity when correcting an unlinked email', function () {
    $admin = llifrCorretor();
    $lead = llifrLead([
        'email' => 'llifr-current-identity@example.test',
        'origem' => 'locatario',
        'leadlovers_initial_error_code' => 'EMAIL_EXISTS',
    ]);
    llifrLead([
        'email' => 'llifr-conflict-identity@example.test',
        'origem' => 'locatario',
        'leadlovers_status' => 'pending',
        'leadlovers_initial_error_status' => null,
        'leadlovers_initial_error_code' => null,
    ]);

    $response = llifrAdminPost($this, $admin, $lead, [
        'email' => 'llifr-conflict-identity@example.test',
    ]);
    llifrAssertSessionHasError($response, 'email');

    expect($lead->refresh()->email)
        ->toBe('llifr-current-identity@example.test');
    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
});

it('allows the same unlinked email in a different project identity', function () {
    $admin = llifrCorretor();
    $lead = llifrLead([
        'email' => 'llifr-current-other-origin@example.test',
        'origem' => 'locatario',
        'leadlovers_initial_error_code' => 'EMAIL_EXISTS',
    ]);
    llifrLead([
        'email' => 'llifr-shared-other-origin@example.test',
        'origem' => 'locador',
        'tipo_solicitante' => 'locador',
        'leadlovers_status' => 'pending',
        'leadlovers_initial_error_status' => null,
        'leadlovers_initial_error_code' => null,
    ]);

    llifrAdminPost($this, $admin, $lead, [
        'email' => 'llifr-shared-other-origin@example.test',
    ])->assertSessionHasNoErrors();

    expect($lead->refresh()->email)
        ->toBe('llifr-shared-other-origin@example.test');
    Queue::assertPushed(SendLeadToLeadLoversJob::class, 1);
});

it('refuses request validation for non-correctable or synchronized failures', function (
    array $overrides,
) {
    $admin = llifrCorretor();
    $lead = llifrLead($overrides);

    $response = llifrAdminPost($this, $admin, $lead, [
        'tel' => '11988887777',
        'email' => 'llifr-new@example.test',
    ]);
    llifrAssertSessionHasError($response, 'leadlovers');

    expect($lead->refresh()->leadlovers_status)
        ->toBe($overrides['leadlovers_status'] ?? 'failed');
    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
})->with([
    '401' => [[
        'leadlovers_initial_error_status' => 401,
        'leadlovers_initial_error_code' => 'UNAUTHORIZED',
    ]],
    '422 timeout' => [[
        'leadlovers_initial_error_status' => 422,
        'leadlovers_initial_error_code' => 'TIMEOUT',
    ]],
    'unknown 400' => [[
        'leadlovers_initial_error_status' => 400,
        'leadlovers_initial_error_code' => 'UNKNOWN_REMOTE_RULE',
    ]],
    'already synchronized' => [[
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 987,
        'sent_to_leadlovers_at' => now(),
    ]],
]);

it('puts direct rules exceptions for non-correctable failures in the correction bag', function (
    int $httpStatus,
    string $errorCode,
) {
    $request = llifrRulesRequest(llifrLead([
        'leadlovers_initial_error_status' => $httpStatus,
        'leadlovers_initial_error_code' => $errorCode,
    ]));

    try {
        $request->rules();
        $this->fail('A non-correctable failure unexpectedly returned rules.');
    } catch (ValidationException $exception) {
        expect($exception->errorBag)
            ->toBe('leadloversCorrection')
            ->not->toBe('default')
            ->and($exception->validator->errors()->has('leadlovers'))
            ->toBeTrue();
    }
})->with([
    'credentials failure' => [401, 'UNAUTHORIZED'],
    'unmapped data failure' => [400, 'UNMAPPED_REMOTE_RULE'],
]);

it('corrects only the mapped field and resets incompatible sync state', function (
    string $errorCode,
    array $data,
    string $changedField,
    string $expectedValue,
) {
    $admin = llifrCorretor();
    $lead = llifrLead([
        'email' => 'llifr-service-old@example.test',
        'tel' => '11911112222',
        'leadlovers_initial_error_code' => $errorCode,
        'leadlovers_update_status' => 'failed',
        'leadlovers_update_version' => 7,
        'leadlovers_update_response' => ['raw' => 'old'],
        'leadlovers_update_error' => 'old error',
        'leadlovers_update_requested_at' => now()->subHour(),
        'leadlovers_update_at' => now()->subMinutes(10),
    ]);

    $result = app(LeadLoversInitialFailureRecoveryService::class)
        ->correctAndRetry(
            lead: $lead,
            data: array_merge($data, [
                'nome' => 'Tentativa indevida',
                'status' => 'recusado',
            ]),
            corretor: $admin,
            companyId: null,
            ip: '127.0.0.9',
            userAgent: 'LLIFR test agent',
        );

    $lead->refresh();

    expect($result['lead_id'])->toBe($lead->id)
        ->and($lead->{$changedField})->toBe($expectedValue)
        ->and($lead->nome)->not->toBe('Tentativa indevida')
        ->and($lead->status)->not->toBe('recusado')
        ->and($lead->leadlovers_status)->toBe('pending')
        ->and($lead->leadlovers_lead_id)->toBeNull()
        ->and($lead->sent_to_leadlovers_at)->toBeNull()
        ->and($lead->leadlovers_initial_error_status)->toBeNull()
        ->and($lead->leadlovers_initial_error_code)->toBeNull()
        ->and($lead->leadlovers_initial_error_operation)->toBeNull()
        ->and($lead->leadlovers_initial_error_detail)->toBeNull()
        ->and($lead->leadlovers_initial_failed_at)->toBeNull()
        ->and($lead->leadlovers_update_status)->toBe('idle')
        ->and($lead->leadlovers_update_version)->toBe(8)
        ->and($lead->leadlovers_update_response)->toBeNull()
        ->and($lead->leadlovers_update_error)->toBeNull()
        ->and($lead->leadlovers_update_requested_at)->toBeNull()
        ->and($lead->leadlovers_update_at)->toBeNull()
        ->and($lead->leadlovers_response['recovery'])
        ->toMatchArray([
            'previous_error_code' => $errorCode,
            'corrected_fields' => [$changedField],
        ]);

    Queue::assertPushedOn(
        'leadlovers',
        SendLeadToLeadLoversJob::class,
        fn (SendLeadToLeadLoversJob $job): bool => $job->leadId === $lead->id && $job->afterCommit === true
    );
    Queue::assertPushed(SendLeadToLeadLoversJob::class, 1);
})->with([
    'phone' => [
        'PHONE_EXISTS',
        ['tel' => '11955554444'],
        'tel',
        '11955554444',
    ],
    'email' => [
        'EMAIL_EXISTS',
        ['email' => 'llifr-service-new@example.test'],
        'email',
        'llifr-service-new@example.test',
    ],
]);

it('keeps correction idempotent across rapid duplicate requests', function () {
    $admin = llifrCorretor();
    $lead = llifrLead();
    $service = app(LeadLoversInitialFailureRecoveryService::class);

    $service->correctAndRetry(
        $lead,
        ['tel' => '11933334444'],
        $admin,
        null,
        '127.0.0.10',
        'LLIFR first click',
    );

    expect(fn () => $service->correctAndRetry(
        $lead,
        ['tel' => '11922223333'],
        $admin,
        null,
        '127.0.0.10',
        'LLIFR second click',
    ))->toThrow(DomainException::class);

    expect($lead->refresh()->tel)->toBe('11933334444');
    Queue::assertPushed(SendLeadToLeadLoversJob::class, 1);
});

it('never restarts creation for a lead with a remote identity or sent timestamp', function (
    array $overrides,
) {
    $lead = llifrLead($overrides);

    expect(fn () => app(LeadLoversInitialFailureRecoveryService::class)
        ->correctAndRetry(
            $lead,
            ['tel' => '11944445555'],
            llifrCorretor(),
            null,
            '127.0.0.11',
            'LLIFR identity guard',
        ))->toThrow(DomainException::class);

    expect($lead->refresh()->leadlovers_status)->toBe('failed');
    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
})->with([
    'remote lead id' => [[
        'leadlovers_lead_id' => 4567,
    ]],
    'confirmed send timestamp' => [[
        'sent_to_leadlovers_at' => now()->subMinute(),
    ]],
]);

it('preserves corrected data and records a safe technical failure when queue dispatch fails', function () {
    Log::spy();

    $admin = llifrCorretor();
    $lead = llifrLead();

    Bus::shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException(
            'queue-password=llifr-do-not-log victim@example.test'
        ));

    expect(fn () => app(LeadLoversInitialFailureRecoveryService::class)
        ->correctAndRetry(
            $lead,
            ['tel' => '11966665555'],
            $admin,
            null,
            '127.0.0.12',
            'LLIFR queue failure',
        ))->toThrow(DomainException::class);

    $lead->refresh();
    $description = llifrDescribe($lead);

    expect($lead)
        ->tel->toBe('11966665555')
        ->leadlovers_status->toBe('failed')
        ->leadlovers_initial_error_status->toBeNull()
        ->leadlovers_initial_error_code->toBe('LOCAL_QUEUE_DISPATCH_FAILED')
        ->leadlovers_initial_error_operation->toBe('queue_dispatch')
        ->leadlovers_initial_failed_at->not->toBeNull()
        ->and($description['correctable'])->toBeFalse()
        ->and(Str::ascii(mb_strtolower($description['message'])))
        ->toContain('fila')
        ->not->toContain('llifr-do-not-log')
        ->not->toContain('victim@example.test');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'fila da LeadLovers')
            && $context['lead_id'] === $lead->id
            && ! str_contains(json_encode($context), 'llifr-do-not-log')
            && ! str_contains(json_encode($context), 'victim@example.test')
    )->once();
});

it('audits actor lead previous code and field names without copying contact values', function () {
    $admin = llifrCorretor();
    $lead = llifrLead([
        'tel' => '11910101010',
    ]);

    app(LeadLoversInitialFailureRecoveryService::class)->correctAndRetry(
        $lead,
        ['tel' => '11920202020'],
        $admin,
        null,
        '127.0.0.13',
        'LLIFR audit agent',
    );

    $audit = CorretorActivityLog::query()->sole();
    $serializedAudit = json_encode($audit->only([
        'old_values',
        'new_values',
        'description',
        'ip',
        'user_agent',
    ]));

    expect($audit)
        ->corretor_id->toBe($admin->id)
        ->action->toBe('leadlovers_initial_send_correction_requested')
        ->model_type->toBe(Lead::class)
        ->model_id->toBe($lead->id)
        ->and($audit->old_values)->toMatchArray([
            'leadlovers_status' => 'failed',
            'error_code' => 'PHONE_EXISTS',
        ])
        ->and($audit->new_values)->toMatchArray([
            'leadlovers_status' => 'pending',
            'corrected_fields' => ['tel'],
        ])
        ->and($serializedAudit)
        ->not->toContain('11910101010')
        ->not->toContain('11920202020')
        ->not->toContain($lead->email);
});

it('scopes only definitive initial HTTP 400 failures from system origins', function () {
    $phone = llifrLead(['nome' => 'LLIFR included phone']);
    $email = llifrLead([
        'nome' => 'LLIFR included email',
        'leadlovers_initial_error_code' => 'EMAIL_EXISTS',
    ]);
    $unknown = llifrLead([
        'nome' => 'LLIFR included unknown 400',
        'leadlovers_initial_error_code' => 'UNKNOWN_REMOTE_RULE',
    ]);

    foreach ([
        ['leadlovers_initial_error_status' => 401],
        ['leadlovers_initial_error_status' => 422],
        ['leadlovers_initial_error_status' => 429],
        ['leadlovers_initial_error_status' => 503],
        ['leadlovers_status' => 'pending'],
        ['leadlovers_status' => 'processing'],
        [
            'leadlovers_status' => 'sent',
            'sent_to_leadlovers_at' => now(),
        ],
        ['leadlovers_lead_id' => 8765],
        ['origem' => 'importacao_externa'],
    ] as $excluded) {
        llifrLead($excluded);
    }

    $ids = Lead::query()
        ->notSentToLeadLoversBecauseOfInvalidData()
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$phone->id, $email->id, $unknown->id]);
});

it('applies the dashboard sync filter before pagination and preserves its query string', function () {
    $admin = llifrCorretor();

    foreach (range(1, 8) as $number) {
        llifrLead([
            'nome' => "LLIFR excluded technical {$number}",
            'leadlovers_initial_error_status' => 503,
        ]);
    }

    $included = collect(range(1, 7))->map(fn (int $number): Lead => llifrLead(['nome' => "LLIFR paginated invalid {$number}"])
    );

    $response = $this
        ->actingAs($admin, 'admin')
        ->get(route('Dashboard-Admin', [
            'leadlovers_sync' => 'not_sent_invalid_data',
        ]));

    $response->assertOk();

    /** @var LengthAwarePaginator $leads */
    $leads = $response->viewData('leads');
    $failures = collect($response->viewData('leadLoversFailures'));
    $options = collect($response->viewData('leadLoversSyncOptions'));
    parse_str((string) parse_url($leads->url(2), PHP_URL_QUERY), $nextQuery);

    expect($leads)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($leads->total())->toBe(7)
        ->and($leads->count())->toBe(6)
        ->and($leads->pluck('id')->diff($included->pluck('id')))
        ->toBeEmpty()
        ->and($response->viewData('selectedLeadLoversSync'))
        ->toBe('not_sent_invalid_data')
        ->and($response->viewData('notSentToLeadLoversCount'))->toBe(7)
        ->and($options->has('not_sent_invalid_data'))->toBeTrue()
        ->and($failures->keys()->map(fn ($id): int => (int) $id)->sort()->values())
        ->toEqual($leads->pluck('id')->sort()->values())
        ->and($nextQuery['leadlovers_sync'] ?? null)
        ->toBe('not_sent_invalid_data');
});

it('scopes the company sync filter counter failures and pagination to its own leads', function () {
    $company = llifrCompany();
    $otherCompany = llifrCompany();
    $user = llifrCompanyUser($company);

    $companyLeads = collect(range(1, 7))->map(
        fn (int $number): Lead => llifrLead([
            'company_id' => $company->id,
            'nome' => "LLIFR company invalid {$number}",
        ])
    );
    $otherCompanyLeads = collect(range(1, 3))->map(
        fn (int $number): Lead => llifrLead([
            'company_id' => $otherCompany->id,
            'nome' => "LLIFR other company invalid {$number}",
        ])
    );
    llifrLead([
        'company_id' => $company->id,
        'nome' => 'LLIFR company technical exclusion',
        'leadlovers_initial_error_status' => 503,
    ]);

    $test = $this
        ->actingAs($user)
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ]);

    $response = $test->get(route('company.dashboard', [
        'leadlovers_sync' => 'not_sent_invalid_data',
    ]));
    $response->assertOk();

    /** @var LengthAwarePaginator $leads */
    $leads = $response->viewData('leads');
    $failures = collect($response->viewData('leadLoversFailures'));
    parse_str((string) parse_url($leads->url(2), PHP_URL_QUERY), $nextQuery);

    expect($leads->total())->toBe(7)
        ->and($leads->count())->toBe(6)
        ->and($leads->pluck('company_id')->unique()->values()->all())
        ->toBe([$company->id])
        ->and($leads->pluck('id')->intersect($otherCompanyLeads->pluck('id')))
        ->toBeEmpty()
        ->and($leads->pluck('id')->diff($companyLeads->pluck('id')))
        ->toBeEmpty()
        ->and($response->viewData('notSentToLeadLoversCount'))->toBe(7)
        ->and($response->viewData('selectedLeadLoversSync'))
        ->toBe('not_sent_invalid_data')
        ->and($failures->keys()->map(fn ($id): int => (int) $id)->sort()->values())
        ->toEqual($leads->pluck('id')->sort()->values())
        ->and($nextQuery['leadlovers_sync'] ?? null)
        ->toBe('not_sent_invalid_data');

    $pageTwoResponse = $test->get(route('company.dashboard', [
        'leadlovers_sync' => 'not_sent_invalid_data',
        'page' => 2,
    ]));
    $pageTwoResponse->assertOk();

    /** @var LengthAwarePaginator $pageTwoLeads */
    $pageTwoLeads = $pageTwoResponse->viewData('leads');

    expect($pageTwoLeads->total())->toBe(7)
        ->and($pageTwoLeads->count())->toBe(1)
        ->and($pageTwoLeads->first()->company_id)->toBe($company->id)
        ->and($pageTwoLeads->pluck('id')->intersect(
            $otherCompanyLeads->pluck('id')
        ))->toBeEmpty();
});

it('combines the sync filter with existing lead company and requester filters', function () {
    $admin = llifrCorretor();
    $company = llifrCompany();
    $otherCompany = llifrCompany();
    $target = llifrLead([
        'company_id' => $company->id,
        'nome' => 'LLIFR Needle target',
        'tipo_solicitante' => 'locatario',
    ]);

    llifrLead([
        'company_id' => $company->id,
        'nome' => 'LLIFR unrelated name',
        'tipo_solicitante' => 'locatario',
    ]);
    llifrLead([
        'company_id' => $company->id,
        'nome' => 'LLIFR Needle wrong requester',
        'tipo_solicitante' => 'locador',
        'origem' => 'locador',
    ]);
    llifrLead([
        'company_id' => $otherCompany->id,
        'nome' => 'LLIFR Needle wrong company',
        'tipo_solicitante' => 'locatario',
    ]);
    llifrLead([
        'company_id' => $company->id,
        'nome' => 'LLIFR Needle wrong status',
        'tipo_solicitante' => 'locatario',
        'leadlovers_initial_error_status' => 503,
    ]);

    $response = $this
        ->actingAs($admin, 'admin')
        ->get(route('Dashboard-Admin', [
            'leadlovers_sync' => 'not_sent_invalid_data',
            'lead_name' => 'Needle',
            'imobiliaria' => (string) $company->id,
            'tipo_solicitante' => 'locatario',
        ]));

    $response->assertOk();

    /** @var LengthAwarePaginator $leads */
    $leads = $response->viewData('leads');

    expect($leads->total())->toBe(1)
        ->and($leads->pluck('id')->all())->toBe([$target->id]);
});

it('ignores an unknown dashboard sync filter value', function () {
    $admin = llifrCorretor();
    $failed = llifrLead();
    $pending = llifrLead([
        'leadlovers_status' => 'pending',
        'leadlovers_initial_error_status' => null,
        'leadlovers_initial_error_code' => null,
    ]);

    $response = $this
        ->actingAs($admin, 'admin')
        ->get(route('Dashboard-Admin', [
            'leadlovers_sync' => 'llifr-unknown-filter',
        ]));

    $response->assertOk();

    /** @var LengthAwarePaginator $leads */
    $leads = $response->viewData('leads');

    expect($response->viewData('selectedLeadLoversSync'))->toBe('')
        ->and($leads->pluck('id')->sort()->values())
        ->toEqual(collect([$failed->id, $pending->id])->sort()->values());
});

it('returns 403 to an admin without edit-leads', function () {
    $admin = llifrCorretor([
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => ['leads.visualizar'],
    ]);

    $this
        ->actingAs($admin, 'admin')
        ->post(
            route('admin.leads.leadlovers.correct', llifrLead()),
            ['tel' => '11988887777']
        )->assertForbidden();

    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
});

it('prevents a company from correcting another company lead', function () {
    $company = llifrCompany();
    $otherCompany = llifrCompany();
    $user = llifrCompanyUser($company);
    $otherLead = llifrLead([
        'company_id' => $otherCompany->id,
    ]);

    $this
        ->actingAs($user)
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])->post(
            route('dashboard.leads.leadlovers.correct', $otherLead),
            ['tel' => '11988887777']
        )->assertForbidden();

    expect($otherLead->refresh()->leadlovers_status)->toBe('failed');
    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
});

it('denies an unauthenticated correction request', function () {
    $this->post(
        route('dashboard.leads.leadlovers.correct', llifrLead()),
        ['tel' => '11988887777']
    )->assertRedirect(route('empresa.login'));

    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
});

it('throttles repeated correction attempts on the company route', function () {
    $company = llifrCompany();
    $user = llifrCompanyUser($company);
    $lead = llifrLead([
        'company_id' => $company->id,
        'leadlovers_initial_error_status' => 401,
        'leadlovers_initial_error_code' => 'UNAUTHORIZED',
    ]);

    $test = $this
        ->actingAs($user)
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ]);

    foreach (range(1, 5) as $attempt) {
        $test->post(
            route('dashboard.leads.leadlovers.correct', $lead),
            ['tel' => '11988887777']
        )->assertRedirect();
    }

    $test->post(
        route('dashboard.leads.leadlovers.correct', $lead),
        ['tel' => '11988887777']
    )->assertTooManyRequests();
});

it('keeps throttle middleware on both correction routes', function () {
    $routes = app('router')->getRoutes();

    expect($routes->getByName(
        'dashboard.leads.leadlovers.correct'
    )->gatherMiddleware())->toContain('throttle:5,1')
        ->and($routes->getByName(
            'admin.leads.leadlovers.correct'
        )->gatherMiddleware())->toContain('throttle:5,1');
});
