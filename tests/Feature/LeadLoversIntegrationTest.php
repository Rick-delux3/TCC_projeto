<?php

use App\Exceptions\LeadLoversRateLimitedException;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Services\LeadLoversService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.leadlovers.enabled' => false,
        'services.leadlovers.token' => 'secret-token',
    ]);
});

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
    expect($lead->refresh()->leadlovers_status)->toBe('pending');
});

it('returns a rate-limited lead update job to the queue', function () {
    config(['services.leadlovers.enabled' => true]);

    $lead = Lead::create([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
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

it('prevents the tags command from calling HTTP while disabled', function () {
    $this->artisan('leadlovers:sync-tags')->assertFailed();
    Http::assertNothingSent();
});
