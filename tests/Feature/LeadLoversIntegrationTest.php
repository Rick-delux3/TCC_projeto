<?php

use App\Exceptions\LeadLoversRateLimitedException;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Jobs\SyncCompanyLeadLoversLeadsJob;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Models\User;
use App\Services\LeadLoversService;
use App\Services\LeadLoversSyncService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function leadLoversCompany(array $attributes = []): Imobiliaria
{
    return Imobiliaria::create(array_merge([
        'name' => 'Imobiliária Teste',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'São Paulo',
        'state' => 'SP',
    ], $attributes));
}

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.leadlovers.enabled' => false,
        'services.leadlovers.token' => 'secret-token',
    ]);
});

it('does not dispatch synchronization from the dashboard or manual route when disabled', function () {
    Bus::fake();
    $company = leadLoversCompany();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)
        ->withSession(['company_id' => $company->id, '2fa_passed' => true])
        ->get(route('company.dashboard'))
        ->assertOk();

    $this->actingAs($user)
        ->withSession(['company_id' => $company->id, '2fa_passed' => true])
        ->post(route('Dashboard.syncAgain'))
        ->assertRedirect(route('company.dashboard'))
        ->assertSessionHas('warning');

    Bus::assertNotDispatched(SyncCompanyLeadLoversLeadsJob::class);
    Http::assertNothingSent();
});

it('cancels an already queued synchronization without HTTP when disabled', function () {
    $company = leadLoversCompany(['sync_status' => 'queued']);

    (new SyncCompanyLeadLoversLeadsJob($company->id))
        ->handle(app(LeadLoversSyncService::class));

    expect($company->refresh()->sync_status)->toBe('cancelled')
        ->and($company->sync_finished_at)->not->toBeNull();
    Http::assertNothingSent();
});

it('blocks every public LeadLovers service operation when disabled', function () {
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
    Http::fake([
        '*' => Http::response(['Value' => 321], 201),
    ]);

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

it('fails safely when the sync token is missing', function () {
    config(['services.leadlovers.enabled' => true, 'services.leadlovers.token' => null]);

    $result = app(LeadLoversSyncService::class)->syncCompanyLeads(leadLoversCompany());

    expect($result['success'])->toBeFalse()
        ->and($result['stopped_reason'])->toBe('missing_token');
    Http::assertNothingSent();
});

it('signals rate limit after one request and preserves Retry-After', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake(['*' => Http::response('error code: 1015', 429, ['Retry-After' => '45'])]);

    $exception = null;

    try {
        app(LeadLoversSyncService::class)->syncCompanyLeads(leadLoversCompany());
    } catch (LeadLoversRateLimitedException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(LeadLoversRateLimitedException::class)
        ->and($exception->retryAfter)->toBe(45)
        ->and($exception->cloudflareBlocked)->toBeTrue();
    Http::assertSentCount(1);
});

it('returns a rate-limited synchronization job to the queue', function () {
    config(['services.leadlovers.enabled' => true]);
    $company = leadLoversCompany(['sync_status' => 'queued']);
    $service = Mockery::mock(LeadLoversSyncService::class);
    $service->shouldReceive('syncCompanyLeads')
        ->once()
        ->andThrow(new LeadLoversRateLimitedException(45, true));

    $job = (new SyncCompanyLeadLoversLeadsJob($company->id))
        ->withFakeQueueInteractions();
    $job->handle($service);

    $job->assertReleased(45);
    expect($company->refresh()->sync_status)->toBe('queued')
        ->and($company->sync_started_at)->toBeNull()
        ->and($company->sync_finished_at)->toBeNull()
        ->and($company->sync_error)->toContain('45 segundos');
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
    expect($lead->refresh()->leadlovers_status)->toBe('pending');
});

it('returns a rate-limited lead update job to the queue', function () {
    config(['services.leadlovers.enabled' => true]);
    $lead = Lead::create([
        'tipo_solicitante' => 'locatario',
        'nome' => 'Pessoa Teste',
        'email' => 'person@example.test',
    ]);
    $service = Mockery::mock(LeadLoversService::class);
    $service->shouldReceive('updateLead')
        ->once()
        ->andThrow(new LeadLoversRateLimitedException(30, false));

    $job = (new UpdateLeadOnLeadLoversJob($lead->id, $lead->email))
        ->withFakeQueueInteractions();
    $job->handle($service);

    $job->assertReleased(30);
    expect($lead->refresh()->leadlovers_status)->toBe('pending');
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

it('distinguishes authentication server and invalid JSON failures from rate limits', function (int $status, string $body, string $reason) {
    config(['services.leadlovers.enabled' => true]);
    Http::fake(['*' => Http::response($body, $status)]);

    $result = app(LeadLoversSyncService::class)->syncCompanyLeads(leadLoversCompany());

    expect($result['success'])->toBeFalse()
        ->and($result['stopped_reason'])->toBe($reason);
})->with([
    'unauthorized' => [401, '{}', 'authentication_failed'],
    'server error' => [500, '{}', 'server_error'],
    'invalid json' => [200, 'not-json', 'invalid_response'],
]);

it('keeps partial limit completion as a warning and never completes a failed result', function () {
    config(['services.leadlovers.enabled' => true]);
    $company = leadLoversCompany();
    $service = Mockery::mock(LeadLoversSyncService::class);
    $service->shouldReceive('syncCompanyLeads')->once()->andReturn([
        'success' => true,
        'message' => 'partial',
        'stopped_reason' => 'max_pages_reached',
        'imported' => 0,
        'scanned' => 0,
    ]);

    (new SyncCompanyLeadLoversLeadsJob($company->id))->handle($service);
    expect($company->refresh()->sync_status)->toBe('completed_with_warning');

    $service = Mockery::mock(LeadLoversSyncService::class);
    $service->shouldReceive('syncCompanyLeads')->once()->andReturn([
        'success' => false,
        'message' => 'rate limited',
        'stopped_reason' => 'rate_limited',
        'imported' => 0,
        'scanned' => 0,
    ]);

    (new SyncCompanyLeadLoversLeadsJob($company->id))->handle($service);
    expect($company->refresh()->sync_status)->toBe('failed')
        ->and($company->sync_finished_at)->not->toBeNull();
});

it('uses a company-specific unique lock', function () {
    expect((new SyncCompanyLeadLoversLeadsJob(10))->uniqueId())
        ->not->toBe((new SyncCompanyLeadLoversLeadsJob(11))->uniqueId())
        ->and((new SyncCompanyLeadLoversLeadsJob(10))->uniqueId())
        ->toBe((new SyncCompanyLeadLoversLeadsJob(10))->uniqueId());
});

it('prevents the tags command from calling HTTP while disabled', function () {
    $this->artisan('leadlovers:sync-tags')->assertFailed();
    Http::assertNothingSent();
});
