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
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        'leadlovers_lead_code' => '100001',
        'sent_to_leadlovers_at' => now()->subMinutes(5),
        'leadlovers_update_status' => 'pending',
        'leadlovers_update_version' => 1,
    ], $overrides));
}

it('blocks outbound LeadLovers operations while the integration is disabled', function () {
    $service = app(LeadLoversService::class);

    expect($service->updateLead([])['success'])->toBeFalse()
        ->and($service->addTagToLeadById('person@example.test', 1)['StatusCode'])->toBe(503);

    Http::assertNothingSent();
});

it('continues synchronizing the LeadLovers tag catalog', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.api_url' => 'https://api.leadlovers.test',
    ]);
    Http::fake([
        'https://api.leadlovers.test/tags/' => Http::response([[
            'id' => 444,
            'name' => 'Imobiliária Oficial',
            'createdAt' => '2026-08-11T12:00:00Z',
        ]]),
    ]);

    $this->artisan('leadlovers:sync-tags')->assertSuccessful();

    $this->assertDatabaseHas('lead_lovers_tags', [
        'leadlovers_tag_id' => 444,
        'title' => 'Imobiliária Oficial',
        'key' => 'imobiliaria_oficial',
        'active' => true,
    ]);
});

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
    (new SendLeadToLeadLoversJob($lead->id))->handle(
        app(\App\Services\LeadLoversApiClient::class)
    );

    expect($lead->refresh())
        ->leadlovers_status->toBe('failed')
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->leadlovers_response)
        ->toBe([
            'success' => false,
            'phase' => 'failed',
            'operation' => 'integration_disabled',
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
    (new SendLeadToLeadLoversJob($lead->id))->handle(
        app(\App\Services\LeadLoversApiClient::class)
    );

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
    'update lead' => ['updateLead', [[
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]]],
    'add tag' => ['addTagToLeadById', ['person@example.test', 1]],
]);

it('preserves the HTTP error status when a tag response has an empty or misleading body', function (array $body) {
    config(['services.leadlovers.enabled' => true]);
    Http::fake([
        '*' => Http::response($body, 500),
    ]);

    $tagResponse = app(LeadLoversService::class)
        ->addTagToLeadById('person@example.test', 1);

    expect($tagResponse['StatusCode'])->toBe(500);
    Http::assertSentCount(1);
})->with([
    'empty body' => [[]],
    'misleading status' => [['StatusCode' => 200]],
]);

it('fails closed when an update response contains a malformed provider status', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    Http::fake([
        '*' => Http::response([
            'StatusCode' => '500.9',
            'Success' => true,
        ], 200),
    ]);

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);

    expect($result)
        ->success->toBeFalse()
        ->status->toBe(502)
        ->http_status->toBe(200);
    Http::assertSentCount(1);
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

it('waits until a newly sent lead is remotely queryable before PATCH', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response([
            'Message' => 'Novo lead inserido na fila para processamento',
        ], 200),
    ]);
    $job = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );

    expect(fn () => $job->handle(app(LeadLoversService::class)))
        ->toThrow(
            RuntimeException::class,
            'O lead ainda não está disponível para atualização na LeadLovers.'
        );

    $summary = $lead->refresh()->leadlovers_update_response;
    expect($lead)
        ->leadlovers_update_status->toBe('processing')
        ->leadlovers_lead_code->toBeNull()
        ->and($summary)
        ->requested_fields->toBe(['name'])
        ->response_message->toBe('O lead ainda não foi confirmado para atualização na LeadLovers.')
        ->and($summary['readiness_check'])
        ->toBe([
            'confirmed' => false,
            'provider_status' => 200,
            'provider_status_invalid' => false,
            'remote_identity_candidates' => 0,
            'partial_identity_records' => 0,
            'remote_email_matches' => false,
            'explicit_failure' => false,
            'patch_attempt_started' => false,
        ]);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/webapi/Lead';
    });
});

it('confirms the remote lead code before sending the original update payload', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
        'services.leadlovers.dynamic_fields' => [
            'valor_aluguel' => 52664,
            'valor_agua' => 121473,
            'valor_luz' => 121474,
        ],
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    $lead->despesas()->create([
        'valor_aluguel' => 1400,
        'valor_agua' => 140,
        'valor_luz' => 140,
    ]);
    Http::fake(function (Request $request) use ($lead) {
        if ($request->method() === 'GET') {
            return Http::response([
                'Code' => 94169165,
                'Email' => 'person@example.test',
            ], 200);
        }

        expect(data_get(
            $lead->fresh()->leadlovers_update_response,
            'readiness_check.patch_attempt_started'
        ))->toBeTrue();

        return Http::response([], 204);
    });

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name', 'valor_aluguel', 'valor_agua', 'valor_luz']
    ))->handle(app(LeadLoversService::class));

    expect($lead->refresh())
        ->leadlovers_lead_code->toBe('94169165')
        ->leadlovers_update_status->toBe('synced');
    Http::assertSentCount(2);
    $requests = Http::recorded()
        ->map(fn (array $record): Request => $record[0])
        ->values();
    expect($requests[0]->method())->toBe('GET')
        ->and($requests[1]->method())->toBe('PATCH')
        ->and($requests[1]->data())->toBe([
            'Email' => 'person@example.test',
            'Name' => 'Pessoa Teste',
            'DynamicFields' => [
                ['Id' => 52664, 'Value' => '1400.00'],
                ['Id' => 121473, 'Value' => '140.00'],
                ['Id' => 121474, 'Value' => '140.00'],
            ],
        ]);
});

it('fails a permanent readiness client error without PATCH', function (int $status) {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response(['message' => 'Rejected'], $status),
    ]);

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    ))->handle(app(LeadLoversService::class));

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->leadlovers_update_error->toBe(
            'A consulta de prontidão do lead foi recusada pela LeadLovers.'
        );
    Http::assertSentCount(1);
    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'GET'
    );
})->with([400, 401, 403, 422]);

it('does not trust a remote lead code returned with a failed lookup status', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response([
            'Code' => 94169165,
            'message' => 'Internal error',
        ], 500),
    ]);
    $job = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );

    expect(fn () => $job->handle(app(LeadLoversService::class)))
        ->toThrow(RuntimeException::class);
    expect($lead->refresh())
        ->leadlovers_lead_code->toBeNull()
        ->leadlovers_update_status->toBe('processing');
    Http::assertSentCount(1);
    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'GET'
    );
});

it('does not PATCH when the readiness lookup returns a different lead email', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response([
            'Code' => 94169165,
            'Email' => 'another-person@example.test',
        ], 200),
    ]);
    $job = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );

    $job->handle(app(LeadLoversService::class));
    expect($lead->refresh())
        ->leadlovers_lead_code->toBeNull()
        ->leadlovers_update_status->toBe('failed')
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.remote_email_matches'
        ))->toBeFalse();
    Http::assertSentCount(1);
});

it('rejects an explicitly failed readiness body even when it contains an identity', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response([
            'StatusCode' => 200,
            'Success' => false,
            'Code' => 94169165,
            'Email' => 'person@example.test',
        ], 200),
    ]);

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    ))->handle(app(LeadLoversService::class));

    expect($lead->refresh())
        ->leadlovers_lead_code->toBeNull()
        ->leadlovers_update_status->toBe('failed')
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.explicit_failure'
        ))->toBeTrue();
    Http::assertSentCount(1);
});

it('does not accept a non-integer remote lead code', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response([
            'Code' => 'InternalError',
            'Email' => 'person@example.test',
        ], 200),
    ]);
    $job = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );

    expect(fn () => $job->handle(app(LeadLoversService::class)))
        ->toThrow(RuntimeException::class);
    expect($lead->refresh())
        ->leadlovers_lead_code->toBeNull()
        ->leadlovers_update_status->toBe('processing');
    Http::assertSentCount(1);
});

it('preserves an earlier PATCH diagnostic while readiness is still pending', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $providerMessage = 'Erro interno ao tentar atualizar lead.';
    Http::fake([
        '*' => Http::response(['message' => $providerMessage], 500),
    ]);
    $providerDiagnostic = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ])['provider_diagnostic'];
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
        'leadlovers_update_status' => 'failed',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
            'provider_diagnostic' => $providerDiagnostic,
        ],
    ]);
    Http::fake([
        '*' => Http::response([
            'Message' => 'Novo lead inserido na fila para processamento',
        ], 200),
    ]);
    $job = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );

    try {
        $job->handle(app(LeadLoversService::class));
    } catch (RuntimeException) {
        // O worker tentará novamente porque o GET ainda não confirmou o lead.
    }
    $job->failed(new RuntimeException('attempts exhausted'));

    $summary = $lead->refresh()->leadlovers_update_response;
    expect($lead)
        ->leadlovers_update_status->toBe('failed')
        ->and($summary['requested_fields'])->toBe(['name'])
        ->and($summary['previous_patch_diagnostic'])
        ->fingerprint->toBe($providerDiagnostic['fingerprint'])
        ->preserved_from_previous_attempt->toBeTrue()
        ->and(Crypt::decryptString(
            $summary['previous_patch_diagnostic']['ciphertext']
        ))->toBe($providerMessage);
});

it('restarts with a full PATCH attempt budget after delayed readiness confirmation', function () {
    Bus::fake();
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            '_http_status' => 200,
            'Code' => 94169165,
            'Email' => 'person@example.test',
        ]);
    $service->shouldNotReceive('updateLead');
    $job = Mockery::mock(UpdateLeadOnLeadLoversJob::class, [
        $lead->id,
        $lead->email,
        1,
        ['name'],
    ])->makePartial();
    $job->shouldReceive('attempts')->andReturn(3);

    $job->handle($service);

    expect($lead->refresh())
        ->leadlovers_lead_code->toBe('94169165')
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_version->toBe(2)
        ->leadlovers_update_error->toBeNull()
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.confirmed'
        ))->toBeTrue()
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.patch_attempt_started'
        ))->toBeFalse();
    Bus::assertDispatched(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $queued): bool => $queued->leadId === $lead->id
            && $queued->syncVersion === 2
            && $queued->requestedFields === ['name']
            && $queued->tries === 3
            && $queued->queue === 'leadlovers'
    );

    $queued = Bus::dispatched(UpdateLeadOnLeadLoversJob::class)->first();
    $patchService = Mockery::mock(LeadLoversService::class);
    $patchService->shouldNotReceive('getLeadByEmail');
    $patchService->shouldReceive('updateLead')
        ->once()
        ->andReturnUsing(function () use ($job): array {
            $job->failed(new RuntimeException('late predecessor failure'));

            return [
                'success' => true,
                'status' => 204,
                'http_status' => 204,
                'response' => [],
            ];
        });

    $queued->handle($patchService);

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('synced')
        ->leadlovers_update_version->toBe(2)
        ->leadlovers_update_error->toBeNull();
});

it('recovers a confirmed readiness state if the worker stopped before redispatch', function () {
    Bus::fake();
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => '94169165',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
            'readiness_check' => [
                'confirmed' => true,
                'patch_attempt_started' => false,
            ],
        ],
    ]);
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldNotReceive('getLeadByEmail');
    $service->shouldNotReceive('updateLead');
    $job = Mockery::mock(UpdateLeadOnLeadLoversJob::class, [
        $lead->id,
        $lead->email,
        1,
        ['name'],
    ])->makePartial();
    $job->shouldReceive('attempts')->andReturn(2);

    $job->handle($service);

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_version->toBe(2)
        ->leadlovers_update_error->toBeNull();
    Bus::assertDispatched(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $queued): bool => $queued->leadId === $lead->id
            && $queued->syncVersion === 2
            && $queued->requestedFields === ['name']
    );
});

it('keeps a confirmed readiness state when its redispatch fails', function () {
    config(['services.leadlovers.enabled' => true]);
    $providerMessage = 'Erro interno ao tentar atualizar lead.';
    Http::fake([
        '*' => Http::response(['message' => $providerMessage], 500),
    ]);
    $providerDiagnostic = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ])['provider_diagnostic'];
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
        'leadlovers_update_error' => 'Erro anterior.',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
            'provider_diagnostic' => $providerDiagnostic,
        ],
    ]);
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            '_http_status' => 200,
            'Code' => 94169165,
            'Email' => 'person@example.test',
        ]);
    $service->shouldNotReceive('updateLead');
    Bus::shouldReceive('dispatch')
        ->twice()
        ->andThrow(new RuntimeException('queue unavailable'));
    $job = Mockery::mock(UpdateLeadOnLeadLoversJob::class, [
        $lead->id,
        $lead->email,
        1,
        ['name'],
    ])->makePartial();
    $job->shouldReceive('attempts')->andReturn(2);

    expect(fn () => $job->handle($service))
        ->toThrow(RuntimeException::class, 'queue unavailable');

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_version->toBe(2)
        ->leadlovers_update_error->toBeNull()
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.handoff_dispatched'
        ))->toBeFalse();

    $job->failed(new RuntimeException('attempts exhausted'));

    $summary = $lead->refresh()->leadlovers_update_response;
    expect($lead)
        ->leadlovers_lead_code->toBe('94169165')
        ->leadlovers_update_status->toBe('failed')
        ->leadlovers_update_version->toBe(2)
        ->leadlovers_update_error->toBe(
            'A atualização confirmada não pôde ser recolocada na fila.'
        )
        ->and(data_get($summary, 'readiness_check.confirmed'))->toBeTrue()
        ->and(data_get(
            $summary,
            'readiness_check.patch_attempt_started'
        ))->toBeFalse()
        ->and($summary['previous_patch_diagnostic']['fingerprint'])
        ->toBe($providerDiagnostic['fingerprint']);
});

it('recovers a committed readiness handoff when the first queue push is interrupted', function () {
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            '_http_status' => 200,
            'Code' => 94169165,
            'Email' => 'person@example.test',
        ]);
    $service->shouldNotReceive('updateLead');
    Bus::shouldReceive('dispatch')
        ->once()
        ->ordered()
        ->andThrow(new RuntimeException('worker stopped before queue push'));
    Bus::shouldReceive('dispatch')
        ->once()
        ->ordered()
        ->andReturn(null);
    $job = Mockery::mock(UpdateLeadOnLeadLoversJob::class, [
        $lead->id,
        $lead->email,
        1,
        ['name'],
    ])->makePartial();
    $job->shouldReceive('attempts')->andReturn(2);

    expect(fn () => $job->handle($service))
        ->toThrow(RuntimeException::class, 'worker stopped before queue push');

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_version->toBe(2)
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.handoff_dispatched'
        ))->toBeFalse();

    $job->handle($service);

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_version->toBe(2)
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.handoff_dispatched'
        ))->toBeTrue();
});

it('commits a database readiness handoff and its queued job atomically', function () {
    config([
        'services.leadlovers.enabled' => true,
        'queue.default' => 'database',
        'queue.connections.database.connection' => null,
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            '_http_status' => 200,
            'Code' => 94169165,
            'Email' => 'person@example.test',
        ]);
    $service->shouldNotReceive('updateLead');
    $job = Mockery::mock(UpdateLeadOnLeadLoversJob::class, [
        $lead->id,
        $lead->email,
        1,
        ['name'],
    ])->makePartial();
    $job->shouldReceive('attempts')->andReturn(2);

    $job->handle($service);

    $queuedRow = DB::table('jobs')
        ->where('queue', 'leadlovers')
        ->sole();
    $payload = json_decode($queuedRow->payload, true, flags: JSON_THROW_ON_ERROR);
    $queued = unserialize($payload['data']['command']);
    expect($lead->refresh())
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_version->toBe(2)
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.handoff_dispatched'
        ))->toBeTrue()
        ->and($queued)->toBeInstanceOf(UpdateLeadOnLeadLoversJob::class)
        ->and($queued->syncVersion)->toBe(2)
        ->and($queued->requestedFields)->toBe(['name'])
        ->and($queued->queue)->toBe('leadlovers')
        ->and($queued->afterCommit)->toBeFalse();
});

it('rolls back a database readiness handoff when queue insertion does not complete', function () {
    config([
        'services.leadlovers.enabled' => true,
        'queue.default' => 'database',
        'queue.connections.database.connection' => null,
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andReturn([
            'StatusCode' => 200,
            '_http_status' => 200,
            'Code' => 94169165,
            'Email' => 'person@example.test',
        ]);
    $service->shouldNotReceive('updateLead');
    Event::listen(JobQueued::class, function (): never {
        throw new RuntimeException('listener stopped the atomic handoff');
    });
    $job = Mockery::mock(UpdateLeadOnLeadLoversJob::class, [
        $lead->id,
        $lead->email,
        1,
        ['name'],
    ])->makePartial();
    $job->shouldReceive('attempts')->andReturn(2);

    expect(fn () => $job->handle($service))
        ->toThrow(RuntimeException::class, 'listener stopped the atomic handoff');

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('processing')
        ->leadlovers_update_version->toBe(1)
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.confirmed'
        ))->toBeTrue()
        ->and(DB::table('jobs')->where('queue', 'leadlovers')->count())
        ->toBe(0);
});

it('hands off confirmed readiness after the original attempt budget is exhausted', function () {
    Bus::fake();
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => '94169165',
        'leadlovers_update_status' => 'processing',
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
            'readiness_check' => [
                'confirmed' => true,
                'patch_attempt_started' => false,
            ],
        ],
    ]);
    $job = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );

    $job->failed(new RuntimeException('worker stopped after readiness'));

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('pending')
        ->leadlovers_update_version->toBe(2)
        ->leadlovers_update_error->toBeNull();
    Bus::assertDispatched(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $queued): bool => $queued->leadId === $lead->id
            && $queued->syncVersion === 2
            && $queued->requestedFields === ['name']
            && $queued->tries === 3
    );
});

it('does not leave a pending readiness handoff orphaned when integration is disabled', function () {
    config(['services.leadlovers.enabled' => false]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => '94169165',
        'leadlovers_update_status' => 'pending',
        'leadlovers_update_version' => 2,
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
            'readiness_check' => [
                'confirmed' => true,
                'patch_attempt_started' => false,
                'handoff_from_version' => 1,
                'handoff_version' => 2,
                'handoff_dispatched' => false,
            ],
        ],
    ]);
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldNotReceive('getLeadByEmail');
    $service->shouldNotReceive('updateLead');

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    ))->handle($service);

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('disabled')
        ->leadlovers_update_version->toBe(2)
        ->leadlovers_update_error->toBe(
            'Integração com a LeadLovers desativada.'
        );
});

it('returns a rate-limited readiness lookup to the queue without PATCH', function () {
    config(['services.leadlovers.enabled' => true]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('getLeadByEmail')
        ->once()
        ->andThrow(new LeadLoversRateLimitedException(30, false));
    $service->shouldNotReceive('updateLead');
    $job = (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    ))->withFakeQueueInteractions();

    $job->handle($service);

    $job->assertReleased(30);
    expect($lead->refresh())
        ->leadlovers_update_status->toBe('processing')
        ->leadlovers_lead_code->toBeNull();
});

it('fails closed when readiness returns more than one remote identity', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response([
            'Data' => [
                ['Code' => 1001, 'Email' => 'person@example.test'],
                ['Code' => 1002, 'Email' => 'person@example.test'],
            ],
        ], 200),
    ]);

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    ))->handle(app(LeadLoversService::class));

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->leadlovers_lead_code->toBeNull()
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.remote_identity_candidates'
        ))->toBe(2);
    Http::assertSentCount(1);
});

it('keeps a readiness 5xx retryable even with an explicit failure body', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response([
            'Success' => false,
            'Error' => 'Internal error',
        ], 500),
    ]);
    $job = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );

    expect(fn () => $job->handle(app(LeadLoversService::class)))
        ->toThrow(RuntimeException::class);
    expect($lead->refresh())
        ->leadlovers_update_status->toBe('processing')
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.explicit_failure'
        ))->toBeTrue();
    Http::assertSentCount(1);
});

it('does not trust a nested provider failure status during readiness', function (array $body) {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response($body, 200),
    ]);
    $job = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );

    expect(fn () => $job->handle(app(LeadLoversService::class)))
        ->toThrow(RuntimeException::class);
    expect($lead->refresh())
        ->leadlovers_lead_code->toBeNull()
        ->leadlovers_update_status->toBe('processing')
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.provider_status'
        ))->toBe(500);
    Http::assertSentCount(1);
    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'GET'
    );
})->with([
    'nested object' => [[
        'Data' => [
            'StatusCode' => 500,
            'Code' => 94169165,
            'Email' => 'person@example.test',
        ],
    ]],
    'root list' => [[[
        'StatusCode' => 500,
        'Code' => 94169165,
        'Email' => 'person@example.test',
    ]]],
]);

it('does not confuse a lead domain status with a readiness HTTP status', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake(function (Request $request) {
        return $request->method() === 'GET'
            ? Http::response([
                'Code' => 94169165,
                'Email' => 'person@example.test',
                'status' => 'Ativo',
            ], 200)
            : Http::response([], 204);
    });

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    ))->handle(app(LeadLoversService::class));

    expect($lead->refresh())
        ->leadlovers_lead_code->toBe('94169165')
        ->leadlovers_update_status->toBe('synced');
    Http::assertSentCount(2);
});

it('detects nested and conflicting readiness failure flags', function (array $body) {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response($body, 200),
    ]);

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    ))->handle(app(LeadLoversService::class));

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->leadlovers_lead_code->toBeNull();
    Http::assertSentCount(1);
})->with([
    'nested failure' => [[
        'Data' => [
            'Code' => 94169165,
            'Email' => 'person@example.test',
            'Success' => false,
        ],
    ]],
    'conflicting aliases' => [[
        'Code' => 94169165,
        'Email' => 'person@example.test',
        'Success' => true,
        'success' => false,
    ]],
]);

it('fails closed when a readiness identity is accompanied by a partial record', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response([
            'Data' => [
                ['Code' => 1001, 'Email' => 'person@example.test'],
                ['Code' => 1002],
            ],
        ], 200),
    ]);

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    ))->handle(app(LeadLoversService::class));

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->and(data_get(
            $lead->leadlovers_update_response,
            'readiness_check.partial_identity_records'
        ))->toBe(1);
    Http::assertSentCount(1);
});

it('accepts the documented identity from a root list response before PATCH', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake(function (Request $request) {
        return $request->method() === 'GET'
            ? Http::response([[
                'Code' => 94169165,
                'Email' => 'person@example.test',
            ]], 200)
            : Http::response([], 204);
    });

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    ))->handle(app(LeadLoversService::class));

    expect($lead->refresh())
        ->leadlovers_lead_code->toBe('94169165')
        ->leadlovers_update_status->toBe('synced');
    Http::assertSentCount(2);
});

it('does not normalize a malformed provider status into HTTP success', function () {
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.token' => 'test-token',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_lead_code' => null,
    ]);
    Http::fake([
        '*' => Http::response([
            'StatusCode' => '2e2',
            'Code' => 94169165,
            'Email' => 'person@example.test',
        ], 200),
    ]);
    $job = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );

    expect(fn () => $job->handle(app(LeadLoversService::class)))
        ->toThrow(RuntimeException::class);
    expect($lead->refresh())
        ->leadlovers_lead_code->toBeNull()
        ->leadlovers_update_status->toBe('processing');
    Http::assertSentCount(1);
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

it('captures unknown provider errors encrypted without leaking personal data', function () {
    config(['services.leadlovers.enabled' => true]);
    $providerMessage = 'Falha técnica para person@example.test, CPF 123.456.789-00 e chave sk_live_abc123.';
    Http::fake([
        '*' => Http::response(['message' => $providerMessage], 500),
    ]);
    Log::spy();
    $service = app(LeadLoversService::class);

    $first = $service->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);
    $second = $service->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);

    $firstDiagnostic = $first['provider_diagnostic'];
    $secondDiagnostic = $second['provider_diagnostic'];
    expect($first)
        ->success->toBeFalse()
        ->response_message->toBe('A LeadLovers retornou uma mensagem de erro não classificada.')
        ->and($firstDiagnostic)
        ->classification->toBe('unclassified_provider_error')
        ->truncated->toBeFalse()
        ->message_bytes->toBe(strlen($providerMessage))
        ->captured_bytes->toBe(strlen($providerMessage))
        ->and($firstDiagnostic['fingerprint'])
        ->toMatch('/\A[a-f0-9]{64}\z/')
        ->toBe($secondDiagnostic['fingerprint'])
        ->and($firstDiagnostic['ciphertext'])
        ->not->toBe($secondDiagnostic['ciphertext'])
        ->and(Crypt::decryptString($firstDiagnostic['ciphertext']))
        ->toBe($providerMessage)
        ->and(json_encode($firstDiagnostic, JSON_THROW_ON_ERROR))
        ->not->toContain('person@example.test')
        ->not->toContain('123.456.789-00')
        ->not->toContain('sk_live_abc123');

    Log::shouldHaveReceived('warning')
        ->twice()
        ->with(
            'LeadLovers recusou atualização do lead.',
            Mockery::on(function (array $context) use (
                $firstDiagnostic,
                $providerMessage,
                $secondDiagnostic
            ): bool {
                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                return $context['provider_error_classification']
                        === 'unclassified_provider_error'
                    && preg_match(
                        '/\A[a-f0-9]{64}\z/',
                        $context['provider_error_fingerprint']
                    ) === 1
                    && ! str_contains($serialized, $providerMessage)
                    && ! str_contains($serialized, 'person@example.test')
                    && ! str_contains($serialized, '123.456.789-00')
                    && ! str_contains($serialized, 'sk_live_abc123')
                    && ! str_contains(
                        $serialized,
                        $firstDiagnostic['ciphertext']
                    )
                    && ! str_contains(
                        $serialized,
                        $secondDiagnostic['ciphertext']
                    );
            })
        );
});

it('fingerprints the complete provider message beyond the encrypted capture limit', function () {
    config(['services.leadlovers.enabled' => true]);
    $commonPrefix = str_repeat('x', 2048);
    Http::fakeSequence()
        ->push(['message' => $commonPrefix.'A'], 500)
        ->push(['message' => $commonPrefix.'B'], 500);
    $service = app(LeadLoversService::class);

    $first = $service->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);
    $second = $service->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);

    expect($first['provider_diagnostic']['fingerprint'])
        ->not->toBe($second['provider_diagnostic']['fingerprint'])
        ->and(Crypt::decryptString(
            $first['provider_diagnostic']['ciphertext']
        ))
        ->toBe($commonPrefix)
        ->and(Crypt::decryptString(
            $second['provider_diagnostic']['ciphertext']
        ))
        ->toBe($commonPrefix);
});

it('caps encrypted provider diagnostics without breaking UTF-8', function () {
    config(['services.leadlovers.enabled' => true]);
    $providerMessage = str_repeat('€', 1000);
    Http::fake([
        '*' => Http::response(['message' => $providerMessage], 500),
    ]);

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);

    $diagnostic = $result['provider_diagnostic'];
    $captured = Crypt::decryptString($diagnostic['ciphertext']);
    expect($diagnostic)
        ->message_bytes->toBe(3000)
        ->captured_bytes->toBe(2046)
        ->truncated->toBeTrue()
        ->and(strlen($captured))->toBe(2046)
        ->and(mb_check_encoding($captured, 'UTF-8'))->toBeTrue()
        ->and($providerMessage)->toStartWith($captured);
});

it('keeps provider failures operational when diagnostic encryption fails', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake([
        '*' => Http::response(['message' => 'Falha técnica opaca.'], 500),
    ]);
    Crypt::shouldReceive('encryptString')
        ->once()
        ->andThrow(new RuntimeException('encryption unavailable'));

    $result = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);

    expect($result)
        ->success->toBeFalse()
        ->status->toBe(500)
        ->and($result['provider_diagnostic'])
        ->classification->toBe('unclassified_provider_error')
        ->not->toHaveKey('ciphertext');
});

it('preserves encrypted provider diagnostics after job exhaustion', function () {
    config(['services.leadlovers.enabled' => true]);
    $providerMessage = 'Falha técnica para person@example.test e chave sk_live_abc123.';
    $lead = leadForLeadLoversUpdate();
    Http::fake([
        '*' => Http::response(['message' => $providerMessage], 500),
    ]);
    Log::spy();
    $job = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );

    try {
        $job->handle(app(LeadLoversService::class));
    } catch (RuntimeException) {
        // O worker chama failed() quando as tentativas se esgotam.
    }

    $job->failed(new RuntimeException('attempts exhausted'));
    $lead->refresh();
    $diagnostic = $lead->leadlovers_update_response['provider_diagnostic'];
    $rawSummary = (string) DB::table('leads')
        ->where('id', $lead->id)
        ->value('leadlovers_update_response');

    expect($lead)
        ->leadlovers_update_status->toBe('failed')
        ->and($diagnostic)
        ->classification->toBe('unclassified_provider_error')
        ->and(Crypt::decryptString($diagnostic['ciphertext']))
        ->toBe($providerMessage)
        ->and($rawSummary)
        ->not->toContain($providerMessage)
        ->not->toContain('person@example.test')
        ->not->toContain('sk_live_abc123');

    Log::shouldHaveReceived('warning')
        ->with(
            'Atualização do lead falhou transitoriamente na LeadLovers.',
            Mockery::on(function (array $context) use (
                $diagnostic,
                $providerMessage
            ): bool {
                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                return $context['provider_error_fingerprint']
                        === $diagnostic['fingerprint']
                    && $context['provider_error_classification']
                        === 'unclassified_provider_error'
                    && ! str_contains($serialized, $providerMessage)
                    && ! str_contains(
                        $serialized,
                        $diagnostic['ciphertext']
                    );
            })
        )
        ->once();
});

it('preserves an earlier provider diagnostic across a later transient failure', function () {
    config(['services.leadlovers.enabled' => true]);
    $providerMessage = 'Falha técnica opaca da tentativa anterior.';
    Http::fake([
        '*' => Http::response(['message' => $providerMessage], 500),
    ]);
    $previousResult = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);
    $previousDiagnostic = $previousResult['provider_diagnostic'];
    $lead = leadForLeadLoversUpdate([
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
            'provider_diagnostic' => $previousDiagnostic,
        ],
    ]);
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('updateLead')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => null,
            'http_status' => null,
            'response' => [],
        ]);
    $job = new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    );

    try {
        $job->handle($service);
    } catch (RuntimeException) {
        // Simula uma tentativa posterior sem resposta do provedor.
    }

    $job->failed(new RuntimeException('attempts exhausted'));
    $storedDiagnostic = $lead->refresh()
        ->leadlovers_update_response['provider_diagnostic'];
    expect($storedDiagnostic)
        ->fingerprint->toBe($previousDiagnostic['fingerprint'])
        ->ciphertext->toBe($previousDiagnostic['ciphertext'])
        ->preserved_from_previous_attempt->toBeTrue()
        ->and(Crypt::decryptString($storedDiagnostic['ciphertext']))
        ->toBe($providerMessage);
});

it('does not attribute an earlier provider diagnostic to a later classified response', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake([
        '*' => Http::response([
            'message' => 'Falha técnica opaca da tentativa anterior.',
        ], 500),
    ]);
    $previousResult = app(LeadLoversService::class)->updateLead([
        'Email' => 'person@example.test',
        'Name' => 'Pessoa Teste',
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
            'provider_diagnostic' => $previousResult['provider_diagnostic'],
        ],
    ]);
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('updateLead')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 400,
            'http_status' => 400,
            'response' => [],
            'response_message' => 'A LeadLovers informou falha de autenticação.',
        ]);

    (new UpdateLeadOnLeadLoversJob(
        $lead->id,
        $lead->email,
        1,
        ['name']
    ))->handle($service);

    $summary = $lead->refresh()->leadlovers_update_response;
    expect($summary)
        ->status->toBe(400)
        ->http_status->toBe(400)
        ->response_message->toBe('A LeadLovers informou falha de autenticação.')
        ->not->toHaveKey('provider_diagnostic');
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
        ->response->toBe([])
        ->provider_diagnostic->toBeNull();
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

it('queues a newer accumulated update after a confirmed initial send', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.initial_update_delay_seconds' => 60,
    ]);

    $lead = leadForLeadLoversUpdate([
        'nome' => 'Nome já alterado',
        'email' => 'follow-up@example.test',
        'sent_to_leadlovers_at' => now(),
        'leadlovers_update_response' => [
            'requested_fields' => ['name'],
        ],
    ]);

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

it('does not invent remote changes for absent optional relations', function (
    mixed $emptySpouseName,
    mixed $emptySpouseCpf,
    mixed $emptyExpenseValue
) {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.base_url' => 'https://example.test/webapi/',
        'services.leadlovers.dynamic_fields.conjuge_cpf' => 103,
        'features.insurance_analysis.enabled' => false,
    ]);
    $lead = leadForLeadLoversUpdate([
        'nome' => 'Nome anterior',
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);

    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        [
            'nome' => 'Nome atualizado',
            'cidade_imovel' => 'Cidade atualizada',
            'estado' => null,
            'conjuge_nome' => $emptySpouseName,
            'conjuge_cpf' => $emptySpouseCpf,
            'valor_aluguel' => $emptyExpenseValue,
            'valor_agua' => $emptyExpenseValue,
            'valor_luz' => $emptyExpenseValue,
            'valor_gas' => $emptyExpenseValue,
            'valor_condominio' => $emptyExpenseValue,
            'valor_iptu' => $emptyExpenseValue,
            'outras_despesas' => $emptyExpenseValue,
        ]
    );

    $queuedJob = null;
    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        function (UpdateLeadOnLeadLoversJob $job) use (&$queuedJob): bool {
            $queuedJob = $job;

            return $job->requestedFields === ['name', 'city'];
        }
    );
    expect($lead->refresh())
        ->leadlovers_update_status->toBe('pending')
        ->and($lead->conjuge()->count())->toBe(0)
        ->and($lead->despesas()->count())->toBe(0)
        ->and($lead->endereco()->count())->toBe(1);

    Http::fake(['*' => Http::response(['StatusCode' => 200], 200)]);
    $queuedJob->handle(app(LeadLoversService::class));

    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'PATCH'
            && array_keys($request->data()) === ['Email', 'Name', 'City']
            && ! array_key_exists('DynamicFields', $request->data())
    );
    expect($lead->refresh()->leadlovers_update_status)->toBe('synced');
})->with([
    'null values' => [null, null, null],
    'empty strings' => ['', '', ''],
    'whitespace strings' => ['   ', "\t", '   '],
]);

it('keeps a real spouse CPF clear explicit and fail closed', function () {
    Queue::fake();
    config([
        'services.leadlovers.enabled' => true,
        'features.insurance_analysis.enabled' => false,
    ]);
    $lead = leadForLeadLoversUpdate([
        'leadlovers_update_status' => 'idle',
        'leadlovers_update_version' => 0,
    ]);
    $lead->conjuge()->create([
        'nome' => 'Pessoa Cônjuge',
        'cpf' => '98765432100',
    ]);

    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['conjuge_cpf' => null]
    );

    $queuedJob = null;
    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        function (UpdateLeadOnLeadLoversJob $job) use (&$queuedJob): bool {
            $queuedJob = $job;

            return $job->requestedFields === ['conjuge_cpf'];
        }
    );
    $remote = Mockery::mock(LeadLoversService::class);
    $remote->shouldNotReceive('updateLead');
    $queuedJob->handle($remote);

    expect($lead->refresh())
        ->leadlovers_update_status->toBe('failed')
        ->and($lead->conjuge->cpf)->toBeNull()
        ->and($lead->leadlovers_update_response)
        ->toMatchArray([
            'requested_fields' => ['conjuge_cpf'],
            'unsupported_fields' => ['conjuge_cpf'],
        ]);
    Http::assertNothingSent();

    app(LeadReanalysisService::class)->updateLeadDataAndMaybeUnlock(
        $lead,
        ['nome' => 'Nome após o clear']
    );
    Queue::assertPushed(
        UpdateLeadOnLeadLoversJob::class,
        fn (UpdateLeadOnLeadLoversJob $job): bool => $job->syncVersion === 2
            && $job->requestedFields === ['name', 'conjuge_cpf']
    );
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
    Log::spy();
    $queuedJob->handle($remote);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with(
            'Atualização da LeadLovers bloqueada pela validação local; nenhum PATCH foi enviado.',
            Mockery::on(
                fn (array $context): bool => $context['failure_stage'] === 'local_preflight'
                    && $context['http_request_sent'] === false
                    && $context['retryable'] === false
                    && $context['requested_fields'] === ['phone']
                    && $context['unsupported_fields'] === ['phone']
            )
        );

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
        ->provider_diagnostic->toBeNull()
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
