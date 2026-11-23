<?php

use App\Events\DashboardActivityChanged;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->withoutVite();
    Queue::fake();
});

function createLeadNotificationFixture(?Imobiliaria $company, array $attributes = []): Lead
{
    return Lead::query()->forceCreate($attributes + [
        'company_id' => $company?->id,
        'origem' => $company ? 'imobiliaria_cadastrada' : 'locatario',
        'nome' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'status' => 'novo',
    ]);
}

function createLeadNotificationBroker(array $attributes = []): Corretor
{
    return Corretor::query()->create($attributes + [
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'cpf' => fake()->unique()->numerify('###########'),
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
}

it('broadcasts committed system lead arrivals once to the authorized dashboard channels', function (): void {
    $company = Imobiliaria::factory()->create();
    $connection = DB::connection();
    $connection->beginTransaction();
    $lead = createLeadNotificationFixture($company);
    Queue::assertNotPushed(BroadcastEvent::class);
    $connection->commit();

    Queue::assertPushedOn('broadcasts', BroadcastEvent::class, function (BroadcastEvent $job) use ($company, $lead): bool {
        $event = $job->event;

        return $event instanceof DashboardActivityChanged
            && $event->change === 'lead.created'
            && $event->resourceId === $lead->id
            && array_map(fn ($channel) => $channel->name, $event->broadcastOn()) === [
                "private-companies.{$company->id}.dashboard",
                'private-admins.dashboard',
            ];
    });

    $connection->beginTransaction();
    createLeadNotificationFixture($company);
    $connection->rollBack();
    $lead->update(['status' => 'aprovado']);
    createLeadNotificationFixture($company, ['origem' => 'leadlovers']);
    Queue::assertPushed(BroadcastEvent::class, 1);
});

it('clears the admin bell per broker and counts later arrivals regardless of commercial status', function (): void {
    $broker = createLeadNotificationBroker();
    $otherBroker = createLeadNotificationBroker();
    $dashboard = route('Dashboard-Admin');
    $markRead = route('admin.lead-notifications.read');
    $lead = createLeadNotificationFixture(null);
    createLeadNotificationFixture(Imobiliaria::factory()->create(), ['status' => 'aprovado']);

    $this->actingAs($broker, 'admin')->get($dashboard)
        ->assertOk()->assertSee('2 novo(s) cliente(s)')->assertSee('action="'.$markRead.'"', false);

    $this->post($markRead)->assertRedirect($dashboard.'#leads-section');
    $this->get($dashboard)->assertOk()->assertDontSee('dashboard-notification-count');
    $this->post($markRead)->assertRedirect($dashboard.'#leads-section');
    expect($lead->fresh()->status)->toBe('novo');

    createLeadNotificationFixture(null);
    $this->get($dashboard)->assertOk()->assertSee('1 novo(s) cliente(s)');
    $this->actingAs($otherBroker, 'admin')->get($dashboard)->assertOk()->assertSee('3 novo(s) cliente(s)');
});

it('only marks the company leads of the signed-in user and keeps coworkers independent', function (): void {
    $company = Imobiliaria::factory()->create();
    $otherCompany = Imobiliaria::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $coworker = User::factory()->create(['company_id' => $company->id]);
    $ownLead = createLeadNotificationFixture($company);
    $otherLead = createLeadNotificationFixture($otherCompany);
    createLeadNotificationFixture(null);
    $dashboard = route('company.dashboard');
    $markRead = route('dashboard.lead-notifications.read');

    $this->actingAs($user)->withSession(['2fa_passed' => true])
        ->get($dashboard)->assertOk()->assertSee('1 lead(s) novo(s)')
        ->assertSee('action="'.$markRead.'"', false);

    $this->post($markRead, ['company_id' => $otherCompany->id, 'user_id' => $coworker->id])
        ->assertRedirect($dashboard.'#leads-section');
    $this->assertDatabaseHas('lead_notification_reads', ['user_id' => $user->id, 'lead_id' => $ownLead->id]);
    $this->assertDatabaseMissing('lead_notification_reads', ['user_id' => $user->id, 'lead_id' => $otherLead->id]);
    $this->get($dashboard)->assertOk()->assertDontSee('dashboard-notification-count');
    $this->actingAs($coworker)->get($dashboard)->assertOk()->assertSee('1 lead(s) novo(s)');
});

it('denies marking admin notifications for a broker without lead access', function (): void {
    $broker = createLeadNotificationBroker(['role' => Corretor::ROLE_INTEGRANTE]);
    createLeadNotificationFixture(null);

    $this->actingAs($broker, 'admin')->post(route('admin.lead-notifications.read'))->assertForbidden();
    $this->assertDatabaseCount('lead_notification_reads', 0);
});
