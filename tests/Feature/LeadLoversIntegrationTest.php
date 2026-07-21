<?php

use App\Jobs\SyncCompanyLeadLoversLeadsJob;
use App\Models\Imobiliaria;
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
        ->and($service->createLead([])['StatusCode'])->toBe(503)
        ->and($service->updateLead([])['success'])->toBeFalse()
        ->and($service->addTagToLeadById('person@example.test', 1)['StatusCode'])->toBe(503);

    Http::assertNothingSent();
});

it('fails safely when the sync token is missing', function () {
    config(['services.leadlovers.enabled' => true, 'services.leadlovers.token' => null]);

    $result = app(LeadLoversSyncService::class)->syncCompanyLeads(leadLoversCompany());

    expect($result['success'])->toBeFalse()
        ->and($result['stopped_reason'])->toBe('missing_token');
    Http::assertNothingSent();
});

it('stops after one request on rate limit and preserves Retry-After', function () {
    config(['services.leadlovers.enabled' => true]);
    Http::fake(['*' => Http::response('error code: 1015', 429, ['Retry-After' => '45'])]);

    $result = app(LeadLoversSyncService::class)->syncCompanyLeads(leadLoversCompany());

    expect($result['success'])->toBeFalse()
        ->and($result['stopped_reason'])->toBe('rate_limited')
        ->and($result['retry_after'])->toBe('45');
    Http::assertSentCount(1);
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
