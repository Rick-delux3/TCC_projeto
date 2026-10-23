<?php

use App\Jobs\ConfirmLeadLoversMachineJob;
use App\Models\Lead;
use App\Services\LeadLoversApiClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'branding.active' => 'tcc',
        'services.leadlovers.enabled' => true,
        'services.leadlovers.api_url' => 'https://api.leadlovers.test',
        'services.leadlovers.token' => 'confirmation-recovery-test',
        'services.leadlovers.machine' => 456,
        'services.leadlovers.sequence_2' => 654,
        'services.leadlovers.step' => 2,
        'features.insurance_analysis.enabled' => false,
        'queue.default' => 'database',
    ]);
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake([]);
});

function confirmationRecoveryLead(array $overrides = []): Lead
{
    return Lead::withoutEvents(fn () => Lead::query()->create(array_merge([
        'nome' => 'Lead de teste',
        'email' => 'confirmation@example.test',
        'tel' => '11999999999',
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'leadlovers_status' => 'failed',
        'leadlovers_lead_id' => 501,
        'leadlovers_initial_error_operation' => 'machine_confirmation',
        'leadlovers_response' => [
            'phase' => 'failed',
            'operation' => 'machine_confirmation',
            'action' => ['action_id' => 42, 'status' => 'pending'],
        ],
    ], $overrides)))->fresh();
}

it('previews eligible confirmations without changing leads or calling the api', function () {
    $lead = confirmationRecoveryLead();
    $before = $lead->getAttributes();
    $this->artisan('leadlovers:recover-confirmations')
        ->expectsOutput('Elegiveis: 1; agendados: 0.')
        ->assertSuccessful();
    expect($lead->fresh()->getAttributes())->toBe($before);
    Queue::assertNotPushed(ConfirmLeadLoversMachineJob::class);
    Http::assertNothingSent();
});

it('schedules only selected leads once and confirms them without remote writes', function () {
    $this->freezeTime();
    $lead = confirmationRecoveryLead();
    $other = confirmationRecoveryLead(['email' => 'other@example.test', 'leadlovers_lead_id' => 502]);
    $options = ['--apply' => true, '--id' => [$lead->id]];
    $this->artisan('leadlovers:recover-confirmations', $options)->assertSuccessful();
    $this->artisan('leadlovers:recover-confirmations', $options)->assertSuccessful();
    expect($lead->fresh()->leadlovers_status)->toBe('processing')
        ->and($lead->fresh()->leadlovers_recovery['next_retry_at'])->toBe(now()->addMinute()->toIso8601String())
        ->and($lead->fresh()->leadlovers_response['action']['action_id'])->toBe(42)
        ->and($other->fresh()->leadlovers_status)->toBe('failed');
    Queue::assertPushed(ConfirmLeadLoversMachineJob::class, 1);
    Queue::assertPushed(ConfirmLeadLoversMachineJob::class, fn ($job) => $job->delay->equalTo(now()->addMinute()) && $job->queue === 'leadlovers');
    Http::assertNothingSent();

    $this->travel(60)->seconds();
    Http::fake(['https://api.leadlovers.test/leads/501/machines' => Http::response([
        [
            'id' => 456, 'name' => 'Maquina de teste', 'type' => 1,
            'level' => 2, 'registerDate' => '2026-10-21T12:00:00Z', 'status' => 'active',
            'sequence' => ['id' => 654, 'name' => 'Sequencia de teste'],
        ],
    ])]);
    (new ConfirmLeadLoversMachineJob($lead->id))->withFakeQueueInteractions()->handle(app(LeadLoversApiClient::class));
    expect($lead->fresh()->leadlovers_status)->toBe('sent')
        ->and($lead->fresh()->leadlovers_lead_id)->toBe(501);
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('excludes failures that are not proven pending confirmations', function (array $overrides) {
    $lead = confirmationRecoveryLead($overrides);
    $before = $lead->getAttributes();
    $this->artisan('leadlovers:recover-confirmations', ['--apply' => true, '--id' => [$lead->id]])
        ->expectsOutput('Elegiveis: 0; agendados: 0.')->assertSuccessful();
    expect($lead->fresh()->getAttributes())->toBe($before);
    Queue::assertNotPushed(ConfirmLeadLoversMachineJob::class);
    Http::assertNothingSent();
})->with([
    [['leadlovers_lead_id' => null]],
    [['leadlovers_status' => 'sent']],
    [['leadlovers_initial_error_status' => 401]],
    [['leadlovers_initial_error_status' => 400, 'leadlovers_initial_error_code' => 'PHONE_EXISTS']],
    [['leadlovers_initial_error_operation' => 'machine_configuration']],
    [['leadlovers_response' => ['action' => ['action_id' => 42, 'status' => 'failed']]]],
    [['leadlovers_response' => []]],
    [['leadlovers_response' => ['status_code' => 403, 'action' => ['action_id' => 42, 'status' => 'pending']]]],
]);

it('rejects unsafe command options and environments', function (array $configuration, array $options) {
    config($configuration);
    confirmationRecoveryLead();
    $this->artisan('leadlovers:recover-confirmations', $options)->assertFailed();
    Queue::assertNotPushed(ConfirmLeadLoversMachineJob::class);
    Http::assertNothingSent();
})->with([
    [[], ['--apply' => true]],
    [[], ['--id' => ['invalid']]],
    [[], ['--limit' => 1001]],
    [['branding.active' => 'client'], []],
    [['queue.default' => 'sync'], ['--apply' => true, '--id' => [1]]],
    [['services.leadlovers.enabled' => false], ['--apply' => true, '--id' => [1]]],
]);

it('restores the previous state when dispatch fails', function () {
    $lead = confirmationRecoveryLead();
    $before = $lead->only(['leadlovers_status', 'leadlovers_response', 'leadlovers_recovery']);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    $this->artisan('leadlovers:recover-confirmations', ['--apply' => true, '--id' => [$lead->id]])->assertFailed();
    expect($lead->fresh()->only(array_keys($before)))->toBe($before);
    Http::assertNothingSent();
});
