<?php

use App\Exceptions\LeadLoversRateLimitedException;
use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Services\LeadLoversService;
use App\Services\LeadReanalysisService;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.leadlovers.enabled' => false,
        'services.leadlovers.token' => 'secret-token',
    ]);
});

function leadForLeadLoversUpdate(array $overrides = []): Lead
{
    return Lead::query()->create(array_merge([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Pessoa Teste',
        'email' => 'person@example.test',
        'leadlovers_status' => 'sent',
        'sent_to_leadlovers_at' => now()->subMinutes(5),
        'leadlovers_update_status' => 'pending',
        'leadlovers_update_version' => 1,
    ], $overrides));
}

it('blocks outbound LeadLovers operations while the integration is disabled', function () {
    $service = app(LeadLoversService::class);

    expect($service->getAllTags())->toBe([])
        ->and($service->createTag('Imobiliária Teste')['status'])->toBe(503)
        ->and($service->createLead([])['StatusCode'])->toBe(503)
        ->and($service->updateLead([])['success'])->toBeFalse()
        ->and($service->addTagToLeadById('person@example.test', 1)['StatusCode'])->toBe(503);

    Http::assertNothingSent();
});

it('uses the id returned when creating a LeadLovers tag', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake(['*' => Http::response(['Value' => 321], 201)]);

    $result = app(LeadLoversService::class)->createTag('Imobiliária Teste');

    expect($result)
        ->success->toBeTrue()
        ->tag_id->toBe(321)
        ->error->toBeNull();

    Http::assertSentCount(1);
});

it('recovers a missing tag id from the LeadLovers tag list', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake([
        '*' => Http::sequence()
            ->push([], 201)
            ->push([
                'Data' => [
                    ['id' => 654, 'Title' => 'Imobiliária Teste'],
                ],
            ], 200),
    ]);

    $result = app(LeadLoversService::class)->createTag('Imobiliária Teste');

    expect($result)
        ->success->toBeTrue()
        ->tag_id->toBe(654)
        ->response->toMatchArray([
            'id' => 654,
            'Title' => 'Imobiliária Teste',
        ])
        ->error->toBeNull();

    Http::assertSentCount(2);
});

it('reconciles an existing remote tag after a failed creation attempt', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake([
        '*' => Http::sequence()
            ->push(['Message' => 'Tag already exists'], 409)
            ->push([
                'Id' => 987,
                'Title' => 'Imobiliária Teste',
            ], 200),
    ]);

    $result = app(LeadLoversService::class)->createTag('Imobiliária Teste');

    expect($result)
        ->success->toBeTrue()
        ->status->toBe(409)
        ->tag_id->toBe(987)
        ->error->toBeNull();

    Http::assertSentCount(2);
});

it('continues synchronizing the LeadLovers tag catalog', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake([
        '*' => Http::response([
            'Data' => [
                ['Id' => 444, 'Title' => 'Imobiliária Oficial'],
            ],
        ]),
    ]);

    $this->artisan('leadlovers:sync-tags')->assertSuccessful();

    $this->assertDatabaseHas('lead_lovers_tags', [
        'leadlovers_tag_id' => 444,
        'title' => 'Imobiliária Oficial',
        'key' => 'imobiliaria_oficial',
        'active' => true,
    ]);
});

it('applies the company tag and sequence when sending a system lead', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.sequence_1' => 321,
        'services.leadlovers.step' => 2,
    ]);

    $company = Imobiliaria::create([
        'name' => 'Imobiliária Teste',
        'email' => 'company@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'São Paulo',
        'state' => 'SP',
        'leadlovers_tag_id' => 123,
    ]);

    $lead = Lead::create([
        'company_id' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada',
        'origem' => 'imobiliaria_cadastrada',
        'nome' => 'Pessoa Teste',
        'email' => 'person@example.test',
    ]);

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('createLead')
        ->once()
        ->with(Mockery::on(fn (array $payload) => $payload['Tag'] === 123
            && $payload['EmailSequenceCode'] === 321
            && $payload['SequenceLevelCode'] === 2))
        ->andReturn(['StatusCode' => 201]);

    (new SendLeadToLeadLoversJob($lead->id))->handle($service);

    expect($lead->refresh()->leadlovers_status)->toBe('sent')
        ->and($lead->sent_to_leadlovers_at)->not->toBeNull();
});

it('claims the initial send before HTTP to prevent duplicate POST requests', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.sequence_1' => 321,
        'services.leadlovers.step' => 2,
    ]);
    $company = Imobiliaria::create([
        'name' => 'Imobiliária Teste',
        'email' => 'company-claim@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'São Paulo',
        'state' => 'SP',
        'leadlovers_tag_id' => 123,
    ]);
    $lead = Lead::create([
        'company_id' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada',
        'origem' => 'imobiliaria_cadastrada',
        'nome' => 'Pessoa Teste',
        'email' => 'claim@example.test',
    ]);
    $remote = Mockery::mock(LeadLoversService::class);
    $remote->shouldReceive('createLead')
        ->once()
        ->andReturnUsing(function () use ($lead, $remote): array {
            (new SendLeadToLeadLoversJob($lead->id))->handle($remote);

            return ['StatusCode' => 201];
        });

    (new SendLeadToLeadLoversJob($lead->id))->handle($remote);

    expect($lead->refresh()->leadlovers_status)->toBe('sent');
});

it('requires positive JSON confirmation before completing the initial send', function (
    mixed $responseBody,
    array $headers
) {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.machine' => 456,
        'services.leadlovers.sequence_1' => 321,
        'services.leadlovers.step' => 2,
    ]);
    $company = Imobiliaria::create([
        'name' => 'ImobiliÃ¡ria Teste',
        'email' => 'company-unconfirmed@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'SÃ£o Paulo',
        'state' => 'SP',
        'leadlovers_tag_id' => 123,
    ]);
    $lead = Lead::create([
        'company_id' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada',
        'origem' => 'imobiliaria_cadastrada',
        'nome' => 'Pessoa Teste',
        'email' => 'unconfirmed@example.test',
    ]);

    Http::fake([
        '*' => Http::response($responseBody, 200, $headers),
    ]);

    (new SendLeadToLeadLoversJob($lead->id))->handle(
        app(LeadLoversService::class)
    );

    expect($lead->refresh())
        ->leadlovers_status->toBe('failed')
        ->sent_to_leadlovers_at->toBeNull()
        ->and($lead->leadlovers_response)
        ->toBe([
            'success' => false,
            'status_code' => 200,
        ]);
})->with([
    'empty body' => ['', []],
    'non-json body' => ['<html>proxy response</html>', [
        'Content-Type' => 'text/html',
    ]],
    'json without a success signal' => [['unrelated' => true], []],
]);

it('completes the initial send after a positive JSON confirmation', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.machine' => 456,
        'services.leadlovers.sequence_1' => 321,
        'services.leadlovers.step' => 2,
    ]);
    $company = Imobiliaria::create([
        'name' => 'Imobiliária Teste',
        'email' => 'company-confirmed@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'São Paulo',
        'state' => 'SP',
        'leadlovers_tag_id' => 123,
    ]);
    $lead = Lead::create([
        'company_id' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada',
        'origem' => 'imobiliaria_cadastrada',
        'nome' => 'Pessoa Teste',
        'email' => 'confirmed@example.test',
    ]);

    Http::fake([
        '*' => Http::response([
            'StatusCode' => 200,
            'Success' => true,
            'Message' => 'Novo lead inserido na fila para processamento',
        ], 200),
    ]);

    (new SendLeadToLeadLoversJob($lead->id))->handle(
        app(LeadLoversService::class)
    );

    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->sent_to_leadlovers_at->not->toBeNull()
        ->and($lead->leadlovers_response)
        ->toBe([
            'success' => true,
            'status_code' => 200,
        ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && parse_url($request->url(), PHP_URL_PATH) === '/webapi/Lead');
});

it('rejects a misleading initial response', function (array $failureResponse) {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.sequence_1' => 321,
        'services.leadlovers.step' => 2,
    ]);
    $company = Imobiliaria::create([
        'name' => 'Imobiliária Teste',
        'email' => 'company-failure@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'São Paulo',
        'state' => 'SP',
        'leadlovers_tag_id' => 123,
    ]);
    $lead = Lead::create([
        'company_id' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada',
        'origem' => 'imobiliaria_cadastrada',
        'nome' => 'Pessoa Teste',
        'email' => 'initial-failure@example.test',
        'leadlovers_update_status' => 'waiting_initial_send',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
        ],
    ]);
    $remote = Mockery::mock(LeadLoversService::class);
    $remote->shouldReceive('createLead')
        ->once()
        ->andReturn($failureResponse);

    (new SendLeadToLeadLoversJob($lead->id))->handle($remote);

    expect($lead->refresh())
        ->leadlovers_status->toBe('failed')
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_response)
        ->toBe([
            'success' => false,
            'status_code' => $failureResponse['StatusCode'],
        ]);
    Queue::assertNotPushed(UpdateLeadOnLeadLoversJob::class);

    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['nome' => 'Nome aguardando conciliação']
    );
    expect($lead->refresh())
        ->leadlovers_status->toBe('failed')
        ->leadlovers_update_status->toBe('failed')
        ->leadlovers_update_error->toContain('conciliado')
        ->and($lead->leadlovers_update_response['requested_fields'])
        ->toBe(['name']);
    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
})->with([
    '2xx with explicit failure' => [[
        'StatusCode' => 200,
        'Success' => false,
        'Error' => 'Campo inválido',
    ]],
    '5xx with misleading success message' => [[
        'StatusCode' => 500,
        'Message' => 'Novo lead inserido na fila para processamento',
    ]],
]);

it('queues a safe initial retry after a pre-HTTP configuration failure', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'features.insurance_analysis.enabled' => false,
    ]);
    $lead = Lead::create([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Nome anterior',
        'email' => 'safe-initial-retry@example.test',
        'leadlovers_status' => 'sequence_failed',
        'leadlovers_update_status' => 'idle',
    ]);

    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['nome' => 'Nome atualizado']
    );

    Queue::assertPushed(
        SendLeadToLeadLoversJob::class,
        fn (SendLeadToLeadLoversJob $job): bool => $job->leadId === $lead->id
            && $job->queue === 'leadlovers'
            && $job->afterCommit === true
    );
    expect($lead->refresh())
        ->leadlovers_status->toBe('pending')
        ->leadlovers_update_status->toBe('waiting_initial_send')
        ->and($lead->leadlovers_update_response['requested_fields'])
        ->toBe(['name']);
});

it('does not orphan an edit made while the initial HTTP request is running', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.sequence_1' => 321,
        'services.leadlovers.step' => 2,
        'features.insurance_analysis.enabled' => false,
    ]);
    $company = Imobiliaria::create([
        'name' => 'Imobiliária Teste',
        'email' => 'company-race@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'São Paulo',
        'state' => 'SP',
        'leadlovers_tag_id' => 123,
    ]);
    $lead = Lead::create([
        'company_id' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada',
        'origem' => 'imobiliaria_cadastrada',
        'nome' => 'Nome anterior',
        'email' => 'initial-race@example.test',
    ]);
    $remote = Mockery::mock(LeadLoversService::class);
    $remote->shouldReceive('createLead')
        ->once()
        ->andReturnUsing(function () use ($lead): array {
            app(LeadReanalysisService::class)
                ->updateLeadDataAndMaybeUnlock(
                    $lead,
                    ['nome' => 'Nome editado durante HTTP']
                );

            return [
                'StatusCode' => 400,
                'Error' => 'Campo inválido',
            ];
        });

    (new SendLeadToLeadLoversJob($lead->id))->handle($remote);

    expect($lead->refresh())
        ->leadlovers_status->toBe('failed')
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_update_response['requested_fields'])
        ->toBe(['name']);
    Queue::assertNotPushed(UpdateLeadOnLeadLoversJob::class);
});

it('does not repeat an initial send interrupted in an ambiguous processing state', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => false,
        'features.insurance_analysis.enabled' => false,
    ]);
    $lead = Lead::create([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Nome anterior',
        'email' => 'disabled-initial@example.test',
        'leadlovers_status' => 'processing',
        'leadlovers_update_status' => 'waiting_initial_send',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
        ],
    ]);
    $remote = Mockery::mock(LeadLoversService::class);
    $remote->shouldNotReceive('createLead');

    (new SendLeadToLeadLoversJob($lead->id))->handle($remote);

    expect($lead->refresh())
        ->leadlovers_status->toBe('failed')
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_response)
        ->toBe([
            'success' => false,
            'status_code' => null,
        ]);

    config(['services.leadlovers.enabled' => true]);
    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['nome' => 'Nome atualizado']
    );

    expect($lead->refresh())
        ->leadlovers_status->toBe('failed')
        ->leadlovers_update_status->toBe('failed')
        ->leadlovers_update_error->toContain('conciliado')
        ->and($lead->leadlovers_update_response['requested_fields'])
        ->toBe(['name']);
    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
});

it('resumes an initial send disabled before any HTTP request', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => false,
        'features.insurance_analysis.enabled' => false,
    ]);
    $lead = Lead::create([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Nome anterior',
        'email' => 'disabled-before-http@example.test',
        'leadlovers_status' => 'pending',
        'leadlovers_update_status' => 'waiting_initial_send',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
        ],
    ]);
    $remote = Mockery::mock(LeadLoversService::class);
    $remote->shouldNotReceive('createLead');

    (new SendLeadToLeadLoversJob($lead->id))->handle($remote);

    expect($lead->refresh())
        ->leadlovers_status->toBe('disabled')
        ->leadlovers_update_status->toBe('disabled');

    config(['services.leadlovers.enabled' => true]);
    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['nome' => 'Nome atualizado']
    );

    expect($lead->refresh())
        ->leadlovers_status->toBe('pending')
        ->leadlovers_update_status->toBe('waiting_initial_send')
        ->and($lead->leadlovers_update_response['requested_fields'])
        ->toBe(['name']);
    Queue::assertPushed(SendLeadToLeadLoversJob::class);
});

it('returns a rate-limited lead creation job to the queue', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.sequence_2' => 10,
    ]);

    LeadLoversTag::create([
        'leadlovers_tag_id' => 20,
        'title' => 'Locatário',
        'key' => 'locatario',
        'active' => true,
    ]);

    $lead = Lead::create([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Pessoa Teste',
        'email' => 'person@example.test',
    ]);

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('createLead')
        ->once()
        ->andThrow(new LeadLoversRateLimitedException(30, false));

    $job = (new SendLeadToLeadLoversJob($lead->id))
        ->withFakeQueueInteractions();
    $job->handle($service);

    $job->assertReleased(30);
    expect($lead->refresh()->leadlovers_status)->toBe('processing');
});

it('returns a rate-limited lead update job to the queue', function () {
    config(['services.leadlovers.enabled' => true]);

    $lead = leadForLeadLoversUpdate();

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('updateLead')
        ->once()
        ->andThrow(new LeadLoversRateLimitedException(30, false));

    $job = (new UpdateLeadOnLeadLoversJob($lead->id, $lead->email, 1, ['name']))
        ->withFakeQueueInteractions();
    $job->handle($service);

    $job->assertReleased(30);
    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('processing');
});

it('propagates rate limits from queued LeadLovers operations', function (string $method, array $arguments) {
    config(['services.leadlovers.enabled' => true]);
    Http::fake(['*' => Http::response('error code: 1015', 429, ['Retry-After' => '30'])]);

    $exception = null;

    try {
        app(LeadLoversService::class)->{$method}(...$arguments);
    } catch (LeadLoversRateLimitedException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(LeadLoversRateLimitedException::class)
        ->and($exception->retryAfter)->toBe(30)
        ->and($exception->cloudflareBlocked)->toBeTrue();
    Http::assertSentCount(1);
})->with([
    'create lead' => ['createLead', [[
        'Name' => 'Pessoa Teste',
        'Email' => 'person@example.test',
        'Tag' => 1,
        'EmailSequenceCode' => 1,
    ]]],
    'update lead' => ['updateLead', [[
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]]],
    'add tag' => ['addTagToLeadById', ['person@example.test', 1]],
]);

it('preserves the HTTP error status when LeadLovers returns an empty or misleading body', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake([
        '*' => Http::sequence()
            ->push([], 500)
            ->push(['StatusCode' => 200], 500),
    ]);

    $service = app(LeadLoversService::class);
    $createResponse = $service->createLead([
        'Name' => 'Pessoa Teste',
        'Email' => 'person@example.test',
        'Tag' => 1,
        'EmailSequenceCode' => 1,
    ]);
    $tagResponse = $service->addTagToLeadById('person@example.test', 1);

    expect($createResponse['StatusCode'])->toBe(500)
        ->and($tagResponse['StatusCode'])->toBe(500);
    Http::assertSentCount(2);
});

it('prevents the tags command from calling HTTP while disabled', function () {
    $this->artisan('leadlovers:sync-tags')->assertFailed();
    Http::assertNothingSent();
});

it('sends lead updates with PATCH, the query token, and the required Email', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);

    Http::fake([
        '*' => Http::response([
            'StatusCode' => 200,
            'Success' => true,
        ], 200),
    ]);

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
        'Phone' => '',
        'DynamicFields' => [
            ['Id' => 10, 'Value' => '123'],
        ],
    ]);

    expect($result)
        ->success->toBeTrue()
        ->status->toBe(200)
        ->http_status->toBe(200);

    Http::assertSent(function (Request $request): bool {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'PATCH'
            && parse_url($request->url(), PHP_URL_PATH) === '/webapi/Lead'
            && ($query['token'] ?? null) === 'test-token'
            && ($request['Email'] ?? null) === 'person@example.test'
            && ($request['Name'] ?? null) === 'Pessoa Teste'
            && ($request['DynamicFields'][0]['Id'] ?? null) === 10
            && ! array_key_exists('Phone', $request->data())
            && ! array_key_exists('token', $request->data());
    });
});

it('uses configured ids and omits blank dynamic fields when creating a lead', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
        'services.leadlovers.machine' => 123,
        'services.leadlovers.sequence_1' => 456,
        'services.leadlovers.step' => 1,
        'services.leadlovers.dynamic_fields' => [
            'cpf' => 9001,
            'conjuge_cpf' => 9002,
        ],
    ]);

    Http::fake([
        '*' => Http::response(['StatusCode' => 200], 200),
    ]);

    $result = app(LeadLoversService::class)->createLead([
        'Name' => 'Pessoa Teste',
        'Email' => 'person@example.test',
        'Tag' => 789,
        'CPF' => '12345678900',
        'conjuge' => null,
    ]);

    expect($result['StatusCode'])->toBe(200);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && ($request['DynamicFields'] ?? null) === [[
                'Id' => 9001,
                'Value' => '12345678900',
            ]];
    });
});

it('rejects a lead update without a valid Email before making HTTP calls', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake();

    $result = app(LeadLoversService::class)->updateLead([
        'Name' => 'Pessoa Teste',
    ]);

    expect($result)
        ->success->toBeFalse()
        ->status->toBe(422);
    Http::assertNothingSent();
});

it('does not report success when a successful HTTP response contains an API error', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake([
        '*' => Http::response([
            'StatusCode' => 422,
            'Success' => false,
        ], 200),
    ]);

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);

    expect($result)
        ->success->toBeFalse()
        ->status->toBe(422)
        ->http_status->toBe(200);
});

it('does not report success when a 2xx update body contains Error', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake(['*' => Http::response([
        'Error' => 'Campo inválido',
    ], 200)]);

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);

    expect($result)
        ->success->toBeFalse()
        ->status->toBe(200)
        ->http_status->toBe(200);
});

it('preserves the HTTP error when the response body claims success', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake([
        '*' => Http::response([
            'StatusCode' => 200,
            'Success' => true,
        ], 500),
    ]);

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);

    expect($result)
        ->success->toBeFalse()
        ->status->toBe(500)
        ->http_status->toBe(500);
});

it('does not confirm a lead update from a non-JSON successful response', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake([
        '*' => Http::response(
            '<html><body>proxy response</body></html>',
            200,
            ['Content-Type' => 'text/html']
        ),
    ]);

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);

    expect($result)
        ->success->toBeFalse()
        ->status->toBe(502)
        ->http_status->toBe(200)
        ->response->toBe([])
        ->raw_body->toBeNull();
});

it('accepts an empty successful response without inventing a response body', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake(['*' => Http::response('', 204)]);

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);

    expect($result)
        ->success->toBeTrue()
        ->status->toBe(204)
        ->http_status->toBe(204)
        ->response->toBe([]);
});

it('returns a retryable result when the LeadLovers connection fails', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake(fn () => throw new ConnectionException('connection failed'));

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);

    expect($result)
        ->success->toBeFalse()
        ->status->toBeNull()
        ->http_status->toBeNull();
});

it('marks a confirmed update as synced without changing the initial-send status', function () {
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate();

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('updateLead')
        ->once()
        ->with(Mockery::on(fn (array $payload): bool => ($payload['Email'] ?? null) === $lead->email
            && ($payload['Name'] ?? null) === $lead->nome))
        ->andReturn([
            'success' => true,
            'status' => 200,
            'http_status' => 200,
            'response' => ['StatusCode' => 200],
        ]);

    (new UpdateLeadOnLeadLoversJob($lead->id, $lead->email, 1, ['name']))
        ->handle($service);

    $lead->refresh();

    expect($lead)
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('synced')
        ->leadlovers_update_at->not->toBeNull()
        ->leadlovers_response->toBeNull()
        ->and($lead->leadlovers_update_response)->toMatchArray([
            'success' => true,
            'status' => 200,
            'http_status' => 200,
        ]);
});

it('sends only the requested filled dynamic fields in a queued lead update', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
        'services.leadlovers.dynamic_fields' => [
            'cpf' => 101,
            'estado_civil' => 102,
            'conjuge_cpf' => 103,
            'valor_aluguel' => 104,
            'valor_agua' => 105,
            'valor_luz' => 106,
            'valor_gas' => 107,
            'valor_condominio' => 108,
            'valor_iptu' => 109,
            'outras_despesas' => 110,
        ],
    ]);

    $lead = leadForLeadLoversUpdate([
        'cpf' => '12345678900',
        'estado_civil' => 'solteiro',
    ]);
    $lead->despesas()->create([
        'valor_aluguel' => 1500,
        'valor_agua' => 100,
        'valor_luz' => 100,
        'valor_gas' => 50,
        'valor_condominio' => 300,
        'valor_iptu' => 80,
        'outras_despesas' => 20,
    ]);

    Http::fake(function (Request $request) {
        $hasBlankDynamicField = collect(
            $request['DynamicFields'] ?? []
        )->contains(
            fn (array $field): bool => blank($field['Value'] ?? null)
        );

        return Http::response(
            [
                'StatusCode' => $hasBlankDynamicField ? 500 : 200,
                'Success' => ! $hasBlankDynamicField,
            ],
            $hasBlankDynamicField ? 500 : 200
        );
    });

    (new UpdateLeadOnLeadLoversJob($lead->id, $lead->email, 1, [
        'cpf',
        'estado_civil',
        'valor_aluguel',
        'valor_agua',
        'valor_luz',
        'valor_gas',
        'valor_condominio',
        'valor_iptu',
        'outras_despesas',
    ]))
        ->handle(app(LeadLoversService::class));

    expect($lead->refresh()->leadlovers_update_status)->toBe('synced');

    Http::assertSent(function (Request $request): bool {
        $dynamicFields = collect($request['DynamicFields'] ?? []);

        return $dynamicFields->count() === 9
            && ! $dynamicFields->contains('Id', 103)
            && ! $dynamicFields->contains(
                fn (array $field): bool => blank($field['Value'] ?? null)
            );
    });
});

it('allows an explicitly retried failed update to claim the same version', function () {
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_update_status' => 'failed',
    ]);

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('updateLead')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'http_status' => 200,
            'response' => ['StatusCode' => 200],
        ]);

    (new UpdateLeadOnLeadLoversJob($lead->id, $lead->email, 1, ['name']))
        ->handle($service);

    expect($lead->refresh())
        ->leadlovers_update_version->toBe(1)
        ->leadlovers_update_status->toBe('synced');
});

it('marks definitive LeadLovers errors as failed without retrying or changing the initial-send status', function (int $status) {
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate();

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('updateLead')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => $status,
            'http_status' => $status,
            'response' => [],
        ]);

    (new UpdateLeadOnLeadLoversJob($lead->id, $lead->email, 1, ['name']))
        ->handle($service);

    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('failed')
        ->leadlovers_update_error->toBe('A LeadLovers recusou a atualização.');
})->with([400, 401, 403, 422]);

it('retries transient failures and records failure after attempts are exhausted', function (?int $status) {
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate();

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('updateLead')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => $status,
            'http_status' => $status,
            'response' => [],
        ]);

    $job = new UpdateLeadOnLeadLoversJob($lead->id, $lead->email, 1, ['name']);
    $exception = null;

    try {
        $job->handle($service);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($lead->refresh()->leadlovers_update_status)->toBe('processing');

    $job->failed($exception);

    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('failed');
})->with([408, 425, 500, 503, null]);

it('does not let a stale job or its failed callback overwrite a newer request', function () {
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_update_version' => 2,
        'leadlovers_update_status' => 'pending',
    ]);

    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldNotReceive('updateLead');

    $job = new UpdateLeadOnLeadLoversJob($lead->id, $lead->email, 1);
    $job->handle($service);
    $job->failed(new RuntimeException('stale'));

    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_version->toBe(2)
        ->leadlovers_update_status->toBe('pending');
});

it('stops a queued job serialized before the sync version was introduced', function () {
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate();
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldNotReceive('updateLead');

    $legacyJob = new UpdateLeadOnLeadLoversJob($lead->id, $lead->email, 1);
    unset($legacyJob->syncVersion);

    $restoredJob = unserialize(
        serialize($legacyJob),
        ['allowed_classes' => true]
    );

    expect($restoredJob->syncVersion)->toBe(0);
    $restoredJob->handle($service);

    expect($lead->refresh())
        ->leadlovers_update_version->toBe(1)
        ->leadlovers_update_status->toBe('pending');
});

it('fails an old serialized update job without field context and without HTTP', function () {
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate();
    $remote = Mockery::mock(LeadLoversService::class);
    $remote->shouldNotReceive('updateLead');

    $legacyJob = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );
    unset($legacyJob->requestedFields);
    $restoredJob = unserialize(
        serialize($legacyJob),
        ['allowed_classes' => true]
    );

    expect($restoredJob->requestedFields)->toBe([]);
    $restoredJob->handle($remote);

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_update_response)
        ->toMatchArray([
            'requested_fields' => [],
            'unsupported_fields' => ['legacy_job_without_field_context'],
        ]);
});

it('reconciles the latest data when an older remote PATCH finishes last', function () {
    Queue::fake();
    config(['services.leadlovers.enabled' => true]);

    $lead = leadForLeadLoversUpdate([
        'nome' => 'Versão um',
        'tel' => '11911111111',
        'leadlovers_update_version' => 1,
        'leadlovers_update_status' => 'pending',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
        ],
    ]);
    $remoteName = null;
    $remotePhone = null;

    $newerService = Mockery::mock(LeadLoversService::class);
    $newerService->shouldReceive('updateLead')
        ->once()
        ->andReturnUsing(function (array $payload) use (
            &$remoteName,
            &$remotePhone
        ): array {
            $remoteName = $payload['Name'];
            $remotePhone = $payload['Phone'];

            return [
                'success' => true,
                'status' => 200,
                'http_status' => 200,
                'response' => ['StatusCode' => 200],
            ];
        });

    $olderService = Mockery::mock(LeadLoversService::class);
    $olderService->shouldReceive('updateLead')
        ->once()
        ->andReturnUsing(function (array $payload) use (
            $lead,
            $newerService,
            &$remoteName,
            &$remotePhone
        ): array {
            Lead::query()->whereKey($lead->id)->update([
                'nome' => 'Versão dois',
                'tel' => '11922222222',
                'leadlovers_update_version' => 2,
                'leadlovers_update_status' => 'pending',
                'leadlovers_update_response' => json_encode([
                    'requested_fields' => ['name', 'phone'],
                ], JSON_THROW_ON_ERROR),
            ]);

            (new UpdateLeadOnLeadLoversJob(
                $lead->id,
                $lead->email,
                2,
                ['name', 'phone']
            ))
                ->handle($newerService);

            expect($remoteName)->toBe('Versão dois');
            expect($remotePhone)->toBe('11922222222');

            $remoteName = $payload['Name'];

            return [
                'success' => true,
                'status' => 200,
                'http_status' => 200,
                'response' => ['StatusCode' => 200],
            ];
        });

    (new UpdateLeadOnLeadLoversJob($lead->id, $lead->email, 1, ['name']))
        ->handle($olderService);

    expect($lead->refresh())
        ->nome->toBe('Versão dois')
        ->tel->toBe('11922222222')
        ->leadlovers_update_version->toBe(3)
        ->leadlovers_update_status->toBe('pending')
        ->and($remoteName)->toBe('Versão um')
        ->and($remotePhone)->toBe('11922222222');

    $reconciliationJob = null;

    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        function (UpdateLeadOnLeadLoversJob $job) use ($lead, &$reconciliationJob): bool {
            $reconciliationJob = $job;

            return $job->leadId === $lead->id
                && $job->syncVersion === 3
                && $job->requestedFields === ['name', 'phone']
                && $job->queue === 'leadlovers'
                && $job->afterCommit === true;
        }
    );

    $reconciliationService = Mockery::mock(LeadLoversService::class);
    $reconciliationService->shouldReceive('updateLead')
        ->once()
        ->andReturnUsing(function (array $payload) use (
            &$remoteName,
            &$remotePhone
        ): array {
            $remoteName = $payload['Name'];
            $remotePhone = $payload['Phone'];

            return [
                'success' => true,
                'status' => 200,
                'http_status' => 200,
                'response' => ['StatusCode' => 200],
            ];
        });

    expect($reconciliationJob)->toBeInstanceOf(UpdateLeadOnLeadLoversJob::class);
    $reconciliationJob->handle($reconciliationService);

    expect($lead->refresh())
        ->leadlovers_update_version->toBe(3)
        ->leadlovers_update_status->toBe('synced')
        ->and($remoteName)->toBe('Versão dois')
        ->and($remotePhone)->toBe('11922222222');
});

it('finishes pending jobs safely when the integration is disabled', function () {
    $lead = leadForLeadLoversUpdate();
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldNotReceive('updateLead');

    (new UpdateLeadOnLeadLoversJob($lead->id, $lead->email, 1))
        ->handle($service);

    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('disabled');
});

it('marks an invalid original email as failed without calling LeadLovers', function () {
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate();
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldNotReceive('updateLead');

    (new UpdateLeadOnLeadLoversJob($lead->id, 'invalid-email', 1))
        ->handle($service);

    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('failed');
});

it('keeps the local save when a sent lead has no valid original email for the remote lookup', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'features.insurance_analysis.enabled' => false,
    ]);

    $lead = leadForLeadLoversUpdate([
        'nome' => 'Nome anterior',
        'email' => '',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);

    $result = app(LeadReanalysisService::class)
        ->updateLeadDataAndMaybeUnlock($lead, [
            'nome' => 'Nome atualizado',
        ]);

    expect($result)
        ->changed->toBeTrue()
        ->message->toContain('não pôde ser enfileirada')
        ->and($lead->refresh())
        ->nome->toBe('Nome atualizado')
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('failed');
    Queue::assertNotPushed(UpdateLeadOnLeadLoversJob::class);
    Queue::assertNotPushed(RunProviderAnalysisJob::class);
    Http::assertNothingSent();
});

it('clears nullable local fields while preserving the readonly email', function () {
    Queue::fake();

    $lead = leadForLeadLoversUpdate([
        'email' => 'readonly@example.test',
        'tel' => '(11) 99999-0000',
        'cpf' => '12345678900',
        'tipo_solicitante' => 'locatario',
        'estado_civil' => 'casado',
    ]);
    $lead->endereco()->create([
        'cep' => '01001000',
        'estado' => 'SP',
        'cidade_imovel' => 'São Paulo',
        'bairro' => 'Centro',
        'logradouro' => 'Praça da Sé',
        'numero' => '1',
        'complemento' => 'Sala 1',
    ]);
    $lead->despesas()->create([
        'valor_aluguel' => 1500,
        'valor_agua' => 100,
        'valor_luz' => 100,
        'valor_gas' => 50,
        'valor_condominio' => 300,
        'valor_iptu' => 80,
        'outras_despesas' => 20,
        'valor_total_encargos' => 2150,
    ]);
    $lead->conjuge()->create([
        'nome' => 'Pessoa Cônjuge',
        'cpf' => '98765432100',
    ]);

    $result = app(LeadReanalysisService::class)
        ->updateLeadDataAndMaybeUnlock($lead, [
            'email' => 'tampered@example.test',
            'tel' => null,
            'cpf' => null,
            'tipo_solicitante' => null,
            'estado_civil' => null,
            'cep' => null,
            'estado' => null,
            'cidade_imovel' => null,
            'bairro' => null,
            'logradouro' => null,
            'numero' => null,
            'complemento' => null,
            'valor_aluguel' => null,
            'valor_agua' => null,
            'valor_luz' => null,
            'valor_gas' => null,
            'valor_condominio' => null,
            'valor_iptu' => null,
            'outras_despesas' => null,
            'conjuge_nome' => null,
            'conjuge_cpf' => null,
        ]);

    $lead->refresh()->load(['endereco', 'despesas', 'conjuge']);

    expect($result['changed'])->toBeTrue()
        ->and($lead)
        ->email->toBe('readonly@example.test')
        ->tel->toBeNull()
        ->cpf->toBeNull()
        ->tipo_solicitante->toBeNull()
        ->estado_civil->toBeNull()
        ->and($lead->endereco->only([
            'cep',
            'estado',
            'cidade_imovel',
            'bairro',
            'logradouro',
            'numero',
            'complemento',
        ]))->each->toBeNull()
        ->and($lead->despesas->only([
            'valor_aluguel',
            'valor_agua',
            'valor_luz',
            'valor_gas',
            'valor_condominio',
            'valor_iptu',
            'outras_despesas',
        ]))->each->toBeNull()
        ->and($lead->despesas->valor_total_encargos)->toBe('0.00')
        ->and($lead->conjuge->nome)->toBeNull()
        ->and($lead->conjuge->cpf)->toBeNull()
        ->and($lead->endereco()->count())->toBe(1)
        ->and($lead->despesas()->count())->toBe(1)
        ->and($lead->conjuge()->count())->toBe(1);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('persists locally and dispatches the LeadLovers update after commit while analyses are disabled', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'features.insurance_analysis.enabled' => false,
    ]);

    $lead = leadForLeadLoversUpdate([
        'nome' => 'Nome anterior',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);
    $lead->forceFill([
        'analysis_final_status' => 'approved',
        'reanalysis_unlocked_at' => null,
    ])->save();

    $result = app(LeadReanalysisService::class)
        ->updateLeadDataAndMaybeUnlock($lead, [
            'nome' => 'Nome atualizado',
            'tel' => '(11) 99999-0000',
            'cep' => '01001-000',
            'cidade_imovel' => 'São Paulo',
            'estado' => 'SP',
            'valor_aluguel' => 1500,
            'conjuge_nome' => 'Pessoa Cônjuge',
        ]);

    $lead->refresh()->load(['endereco', 'despesas', 'conjuge']);

    expect($result)
        ->changed->toBeTrue()
        ->unlocked->toBeFalse()
        ->message->toBe('Dados salvos no sistema. A sincronização com a LeadLovers foi colocada na fila.')
        ->and($lead)
        ->nome->toBe('Nome atualizado')
        ->tel->toBe('(11) 99999-0000')
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_version->toBe(1)
        ->leadlovers_update_requested_at->not->toBeNull()
        ->reanalysis_unlocked_at->toBeNull()
        ->and($lead->endereco->cep)->toBe('01001-000')
        ->and($lead->despesas->valor_aluguel)->toBe('1500.00')
        ->and($lead->conjuge->nome)->toBe('Pessoa Cônjuge');

    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $job): bool => $job->leadId === $lead->id
            && $job->originalEmail === 'person@example.test'
            && $job->syncVersion === 1
            && $job->queue === 'leadlovers'
            && $job->afterCommit === true
    );
    Queue::assertNotPushed(RunProviderAnalysisJob::class);
    Http::assertNothingSent();
});

it('keeps the local save and marks the sync failed when the queue push fails', function () {
    config([
        'services.leadlovers.enabled' => true,
        'features.insurance_analysis.enabled' => false,
    ]);

    $queue = Mockery::mock(QueueContract::class);
    $queue->shouldReceive('push')
        ->once()
        ->andThrow(new RuntimeException('queue unavailable'));
    Queue::shouldReceive('connection')
        ->once()
        ->andReturn($queue);

    $lead = leadForLeadLoversUpdate([
        'nome' => 'Nome anterior',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);

    $result = app(LeadReanalysisService::class)
        ->updateLeadDataAndMaybeUnlock($lead, [
            'nome' => 'Nome salvo localmente',
        ]);

    expect($result)
        ->changed->toBeTrue()
        ->message->toContain('não pôde ser enfileirada')
        ->and($lead->refresh())
        ->nome->toBe('Nome salvo localmente')
        ->leadlovers_update_version->toBe(1)
        ->leadlovers_update_status->toBe('failed')
        ->leadlovers_status->toBe('sent');

    Http::assertNothingSent();
});

it('waits for the initial LeadLovers send before dispatching an update', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'features.insurance_analysis.enabled' => false,
    ]);

    $lead = leadForLeadLoversUpdate([
        'nome' => 'Nome anterior',
        'leadlovers_status' => 'pending',
        'sent_to_leadlovers_at' => null,
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);

    $result = app(LeadReanalysisService::class)
        ->updateLeadDataAndMaybeUnlock($lead, [
            'nome' => 'Nome atualizado',
        ]);

    expect($result)
        ->changed->toBeTrue()
        ->message->toContain('aguarda o envio inicial')
        ->and($lead->refresh()->leadlovers_update_status)
        ->toBe('waiting_initial_send')
        ->and($lead->leadlovers_update_response['requested_fields'])
        ->toBe(['name']);
    Queue::assertNotPushed(UpdateLeadOnLeadLoversJob::class);
    Queue::assertNotPushed(RunProviderAnalysisJob::class);
});

it('queues the accumulated update after the initial send is accepted', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.sequence_1' => 321,
        'services.leadlovers.step' => 2,
        'services.leadlovers.initial_update_delay_seconds' => 60,
    ]);

    $company = Imobiliaria::create([
        'name' => 'Imobiliária Teste',
        'email' => 'company-follow-up@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'São Paulo',
        'state' => 'SP',
        'leadlovers_tag_id' => 123,
    ]);
    $lead = Lead::create([
        'company_id' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada',
        'origem' => 'imobiliaria_cadastrada',
        'nome' => 'Nome já alterado',
        'email' => 'follow-up@example.test',
        'leadlovers_status' => 'pending',
        'leadlovers_update_status' => 'waiting_initial_send',
        'leadlovers_update_version' => 0,
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
        ],
    ]);

    $remote = Mockery::mock(LeadLoversService::class);
    $remote->shouldReceive('createLead')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            'Message' => 'Novo lead inserido na fila para processamento',
        ]);

    (new SendLeadToLeadLoversJob($lead->id))->handle($remote);

    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $job): bool => $job->leadId === $lead->id
            && $job->syncVersion === 1
            && $job->requestedFields === ['name']
            && $job->queue === 'leadlovers'
            && $job->delay !== null
    );
    expect($lead->refresh())
        ->leadlovers_status->toBe('sent')
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_version->toBe(1);

    $settleUntil = $lead->sent_to_leadlovers_at->copy()->addSeconds(60);
    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['tel' => '11999990000']
    );

    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $job): bool => $job->leadId === $lead->id
            && $job->syncVersion === 2
            && $job->requestedFields === ['name', 'phone']
            && $job->delay instanceof \DateTimeInterface
            && $job->delay >= $settleUntil
    );
    expect($lead->refresh()->leadlovers_update_version)->toBe(2);
});

it('sends exactly Email and Name after a name-only edit', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'features.insurance_analysis.enabled' => false,
    ]);

    $lead = leadForLeadLoversUpdate([
        'nome' => 'Nome anterior',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);

    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['nome' => 'Nome mínimo']
    );

    $queuedJob = null;
    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        function (UpdateLeadOnLeadLoversJob $job) use (&$queuedJob): bool {
            $queuedJob = $job;

            return $job->requestedFields === ['name'];
        }
    );

    Http::fake(['*' => Http::response(['StatusCode' => 200], 200)]);
    expect($queuedJob)->toBeInstanceOf(UpdateLeadOnLeadLoversJob::class);
    $queuedJob->handle(app(LeadLoversService::class));

    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'PATCH'
            && array_keys($request->data()) === ['Email', 'Name']
            && $request['Name'] === 'Nome mínimo'
    );

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('synced')
        ->and($lead->leadlovers_update_response['requested_fields'])
        ->toBe(['name']);
});

it('unions pending remote fields across consecutive local edits', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'features.insurance_analysis.enabled' => false,
    ]);

    $lead = leadForLeadLoversUpdate([
        'nome' => 'Nome anterior',
        'tel' => '11900000000',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);
    $service = app(LeadReanalysisService::class);

    $service->updateLeadDataAndMaybeUnlock($lead, ['nome' => 'Nome novo']);
    $service->updateLeadDataAndMaybeUnlock($lead, ['tel' => '11999990000']);

    $latestJob = null;
    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        function (UpdateLeadOnLeadLoversJob $job) use (&$latestJob): bool {
            if ($job->syncVersion === 2) {
                $latestJob = $job;
            }

            return true;
        }
    );

    expect($latestJob)
        ->toBeInstanceOf(UpdateLeadOnLeadLoversJob::class)
        ->requestedFields->toBe(['name', 'phone'])
        ->and($lead->refresh()->leadlovers_update_response['requested_fields'])
        ->toBe(['name', 'phone']);
});

it('keeps permanently failed fields in the next synchronization version', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'features.insurance_analysis.enabled' => false,
    ]);

    $lead = leadForLeadLoversUpdate([
        'nome' => 'Nome pendente',
        'tel' => '11900000000',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
        ],
    ]);
    $remote = Mockery::mock(LeadLoversService::class);
    $remote->shouldReceive('updateLead')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 400,
            'http_status' => 400,
            'response_message' => 'Campo inválido',
        ]);

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    ))->handle($remote);

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_update_response['requested_fields'])
        ->toBe(['name']);

    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['tel' => '11999990000']
    );

    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $job): bool => $job->syncVersion === 2
            && $job->requestedFields === ['name', 'phone']
    );
});

it('sends only one requested DynamicField id', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.dynamic_fields' => ['cpf' => 101],
    ]);
    $lead = leadForLeadLoversUpdate(['cpf' => '12345678900']);
    Http::fake(['*' => Http::response(['StatusCode' => 200], 200)]);

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['cpf']
    ))->handle(app(LeadLoversService::class));

    Http::assertSent(
        fn (Request $request): bool => array_keys($request->data()) === ['Email', 'DynamicFields']
            && $request['DynamicFields'] === [[
                'Id' => 101,
                'Value' => '12345678900',
            ]]
    );
    expect($lead->refresh()->leadlovers_update_status)->toBe('synced');
});

it('fails closed without HTTP when clearing a remote field is unsupported', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'features.insurance_analysis.enabled' => false,
    ]);

    $lead = leadForLeadLoversUpdate([
        'tel' => '11999990000',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);

    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['tel' => null]
    );

    $queuedJob = null;
    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        function (UpdateLeadOnLeadLoversJob $job) use (&$queuedJob): bool {
            $queuedJob = $job;

            return $job->requestedFields === ['phone'];
        }
    );

    $remote = Mockery::mock(LeadLoversService::class);
    $remote->shouldNotReceive('updateLead');
    $queuedJob->handle($remote);

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_update_response)
        ->toMatchArray([
            'requested_fields' => ['phone'],
            'unsupported_fields' => ['phone'],
        ]);
});

it('does not disturb an existing sync state for a local-only edit', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'features.insurance_analysis.enabled' => false,
    ]);

    $lead = leadForLeadLoversUpdate([
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

it('normalizes the legacy send lifecycle before queuing an update', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'features.insurance_analysis.enabled' => false,
    ]);
    $lead = leadForLeadLoversUpdate([
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

it('sanitizes the provider message before exposing update diagnostics', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'private-provider-token',
    ]);

    Http::fake(['*' => Http::response([
        'message' => 'Lead person@example.test Pessoa Secreta CPF 123.456.789-00 telefone (11) 98888-7777 token=private-provider-token inválido',
    ], 400)]);

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Secreta',
        'Phone' => '11988887777',
        'DynamicFields' => [[
            'Id' => 10,
            'Value' => '12345678900',
        ]],
    ]);

    expect($result)
        ->success->toBeFalse()
        ->status->toBe(400)
        ->response_message->toBe('A LeadLovers informou falha de autenticação.')
        ->response_message->not->toContain('person@example.test')
        ->response_message->not->toContain('Pessoa Secreta')
        ->response_message->not->toContain('123.456.789-00')
        ->response_message->not->toContain('(11) 98888-7777')
        ->response_message->not->toContain('private-provider-token');
});

it('sanitizes short known values echoed by the provider', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'tok',
    ]);
    Http::fake(['*' => Http::response([
        'message' => 'Nome Ana rejeitado; token=tok',
    ], 400)]);

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Ana',
    ]);

    expect($result['response_message'])
        ->toBe('A LeadLovers informou falha de autenticação.')
        ->not->toContain('Ana')
        ->not->toContain('tok');
});
