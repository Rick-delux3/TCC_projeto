<?php

use App\Events\InsuranceAnalysisChanged;
use App\Exceptions\ObsoleteInsuranceAnalysisAttempt;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Models\User;
use App\Services\Insurance\InsuranceAnalysisAttempt;
use App\Support\CorretorPermissions;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Http::preventStrayRequests();
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');
    $this->company = Imobiliaria::factory()->create();
    $this->lead = Lead::query()->create(['nome' => 'Realtime', 'email' => 'realtime@example.test', 'company_id' => $this->company->id]);
});

it('queues minimal lead invalidations after commit and discards rolled back notifications', function () {
    DB::transaction(function (): void {
        $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 1]);
        $batch->analyses()->create(['lead_id' => $this->lead->id, 'provider' => 'too', 'product' => 'fianca_locaticia', 'status' => 'pending']);
        Queue::assertNotPushed(BroadcastEvent::class);
    });
    Queue::assertPushed(BroadcastEvent::class, 2);
    Queue::assertPushedOn('broadcasts', BroadcastEvent::class, fn ($job): bool => $job->event instanceof ShouldDispatchAfterCommit
        && $job->event->broadcastWith() === ['lead_id' => $this->lead->id] && $job->tries === 3);
    Queue::fake();
    DB::beginTransaction();
    $this->lead->latestInsuranceAnalysisBatch->analyses->first()->update(['status' => 'approved']);
    DB::rollBack();
    Queue::assertNothingPushed();
});

it('notifies processing decisions failures reanalysis and batch progress but not document delivery metadata', function () {
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 1]);
    $analysis = $batch->analyses()->create(['lead_id' => $this->lead->id, 'provider' => 'too', 'product' => 'fianca_locaticia', 'status' => 'pending']);
    Queue::fake();
    foreach (['processing', 'approved', 'pending', 'failed', 'pending', 'rejected'] as $status) {
        DB::transaction(fn () => $analysis->update(['status' => $status]));
    }
    $batch->update(['completed_providers' => 1, 'status' => 'completed', 'finished_at' => now()]);
    Queue::assertPushed(BroadcastEvent::class, 7);
    Queue::fake();
    $analysis->update(['quote_pdf_path' => 'private/file.pdf', 'email_sent_at' => now()]);
    $batch->update(['email_status' => 'sent']);
    Queue::assertNothingPushed();
    $analysis->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'current']]);
    expect(fn () => InsuranceAnalysisAttempt::run($analysis, 'obsolete', fn () => $analysis->update(['status' => 'approved'])))
        ->toThrow(ObsoleteInsuranceAnalysisAttempt::class);
    Queue::assertNothingPushed();
});

it('preserves committed results if queuing a realtime notification fails', function () {
    Broadcast::shouldReceive('queue')->andThrow(new RuntimeException('Queue unavailable'));
    $batch = DB::transaction(fn () => $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 1]));
    expect(InsuranceAnalysisBatch::query()->find($batch->id)?->status)->toBe('processing');
});

it('resolves current lead ownership when delivering a queued notification', function () {
    $event = new InsuranceAnalysisChanged($this->lead->id);
    $other = Imobiliaria::factory()->create();
    $this->lead->update(['company_id' => $other->id]);
    expect(array_map(strval(...), $event->broadcastOn()))->toBe([
        'private-'.InsuranceAnalysisChanged::adminChannel($this->lead->id),
        'private-'.InsuranceAnalysisChanged::companyChannel($this->lead->id, $other->id),
    ])->and($event->broadcastWith())->toBe(['lead_id' => $this->lead->id]);
    $this->lead->delete();
    expect($event->broadcastOn())->toBe([]);
});

it('authorizes company lead channels using current ownership and second factor', function (string $case, bool $allowed) {
    $user = User::factory()->create(['company_id' => $case === 'unlinked user' ? null : $this->company->id]);
    $leadId = $case === 'missing lead' ? 999999 : $this->lead->id;
    if ($case === 'foreign lead') {
        $this->lead->update(['company_id' => Imobiliaria::factory()->create()->id]);
    }
    if ($case !== 'guest') {
        $this->actingAs($user, 'web');
    }
    $this->withSession(['2fa_passed' => $case !== 'missing 2fa']);
    $channel = $case === 'wrong guard' ? InsuranceAnalysisChanged::adminChannel($leadId)
        : InsuranceAnalysisChanged::companyChannel($leadId, $this->company->id);
    $response = $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-'.$channel]);
    $allowed ? $response->assertOk()->assertJsonStructure(['auth']) : $response->assertForbidden();
})->with([
    ['owner', true], ['foreign lead', false], ['missing 2fa', false], ['missing lead', false],
    ['unlinked user', false], ['guest', false], ['wrong guard', false],
]);

it('requires an active authorized broker and second factor for lead channels', function (string $case, bool $allowed) {
    $broker = Corretor::query()->create([
        'name' => 'Broker', 'email' => 'broker-realtime@example.test', 'password' => 'password', 'role' => Corretor::ROLE_INTEGRANTE,
        'active' => $case !== 'inactive', 'first_login_verified_at' => $case === 'missing 2fa' ? null : now(),
        'permissions' => $case === 'no permission' ? [] : [CorretorPermissions::VIEW_ANALYSIS],
    ]);
    $this->actingAs($broker, 'admin');
    $channel = InsuranceAnalysisChanged::adminChannel($case === 'missing lead' ? 999999 : $this->lead->id);
    $response = $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-'.$channel]);
    $allowed ? $response->assertOk()->assertJsonStructure(['auth']) : $response->assertForbidden();
    if ($allowed) {
        $this->getJson(route('admin.insurance-analyses.data', $this->lead))->assertOk()
            ->assertJsonPath('data.realtime.broadcasting.channel', $channel)
            ->assertJsonPath('data.realtime.broadcasting.event', '.'.InsuranceAnalysisChanged::NAME)
            ->assertJsonPath('data.realtime.transport', 'reverb');
    }
})->with([['authorized', true], ['inactive', false], ['no permission', false], ['missing 2fa', false], ['missing lead', false]]);
