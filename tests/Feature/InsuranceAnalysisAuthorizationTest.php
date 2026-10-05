<?php

use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysis;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadReanalysisService;
use App\Support\CorretorPermissions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Http::preventStrayRequests();
    config(['features.insurance_analysis.enabled' => true]);
    $this->service = $this->mock(LeadReanalysisService::class);
    $this->company = Imobiliaria::factory()->create();
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
    $this->lead = Lead::query()->create(['nome' => 'Autorização', 'email' => 'authorization@example.test', 'company_id' => $this->company->id]);
    $this->batch = $this->lead->insuranceAnalysesBatches()->create(['company_id' => $this->company->id, 'status' => 'completed', 'total_providers' => 1]);
    $this->analysis = $this->batch->analyses()->create([
        'lead_id' => $this->lead->id, 'company_id' => $this->company->id, 'provider' => 'pottencial',
        'product' => 'fianca_locaticia', 'status' => 'failed', 'quote_id' => 'quote',
    ]);
    $this->analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'current']]);
});

afterEach(function () {
    Http::assertNothingSent();
});

it('rejects the former company on every analysis surface even when historical ownership matches', function (bool $unlinked) {
    $this->lead->update(['company_id' => $unlinked ? null : Imobiliaria::factory()->create()->id]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true]);
    foreach (['lead', 'data'] as $surface) {
        $this->getJson(route('insurance-analyses.'.$surface, $this->lead))->assertForbidden();
    }
    $this->get(route('insurance-analyses.show', $this->batch))->assertForbidden();
    foreach (['retry', 'provider-reanalysis', 'sync-status'] as $action) {
        $this->postJson(route('insurance-analyses.'.$action, $this->analysis), ['nome' => 'Intruso'])->assertForbidden();
    }
    $this->postJson(route('dashboard.leads.reanalyze', $this->lead))->assertForbidden();
    $this->putJson(route('dashboard.leads.update', $this->lead), ['nome' => 'Intruso'])->assertForbidden();
    expect($this->lead->fresh()->nome)->toBe('Autorização')
        ->and($this->analysis->fresh()->status)->toBe('failed')
        ->and($this->analysis->events()->count())->toBe(1);
    Queue::assertNothingPushed();
})->with(['transferred' => false, 'unlinked' => true]);

it('allows the current company to read and act on analyses created before the transfer', function (string $action) {
    $newCompany = Imobiliaria::factory()->create();
    $this->lead->update(['company_id' => $newCompany->id]);
    $this->actingAs(User::factory()->create(['company_id' => $newCompany->id]), 'web')->withSession(['2fa_passed' => true]);
    $pageUrl = route('insurance-analyses.lead', $this->lead);
    $this->get($pageUrl)->assertOk();
    $this->get(route('insurance-analyses.show', $this->batch))->assertRedirect($pageUrl);
    $this->getJson(route('insurance-analyses.data', $this->lead))->assertOk()
        ->assertJsonPath('data.analyses.0.actions.retry.url', route('insurance-analyses.retry', $this->analysis));

    if ($action === 'retry') {
        $this->service->shouldReceive('startTechnicalRetry')->once()
            ->withArgs(fn (InsuranceAnalysis $analysis, string $requestedBy): bool => $analysis->is($this->analysis) && $requestedBy === 'imobiliaria');
    } elseif ($action === 'provider-reanalysis') {
        $this->service->shouldReceive('updateLeadDataAndMaybeUnlock')->once()->andReturn(['changed' => true]);
        $this->service->shouldReceive('startProviderReanalysis')->once();
    } elseif ($action === 'general') {
        $this->service->shouldReceive('startGeneralReanalysis')->once()->andReturn(1);
    }
    $url = $action === 'general' ? route('dashboard.leads.reanalyze', $this->lead) : route('insurance-analyses.'.$action, $this->analysis);
    $this->from($pageUrl)->post($url, ['nome' => 'Atualizado'])->assertRedirect($pageUrl)->assertSessionHas('success');
    if ($action === 'sync-status') {
        Queue::assertPushed(SyncProviderAnalysisStatusJob::class, fn ($job): bool => $job->analysisId === $this->analysis->id);
    }
})->with(['retry', 'provider-reanalysis', 'sync-status', 'general']);

it('requires edit permission when a broker reanalysis changes lead data', function (bool $canEdit) {
    $permissions = [CorretorPermissions::VIEW_ANALYSIS, CorretorPermissions::CREATE_ANALYSIS];
    if ($canEdit) {
        $permissions = [...$permissions, CorretorPermissions::VIEW_LEADS, CorretorPermissions::EDIT_LEADS];
    }
    $broker = Corretor::query()->create([
        'name' => 'Corretor', 'email' => 'broker-authorization@example.test', 'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE, 'active' => true, 'first_login_verified_at' => now(), 'permissions' => $permissions,
    ]);
    $this->analysis->update(['status' => 'approved']);
    $this->actingAs($broker, 'admin');
    $this->getJson(route('admin.insurance-analyses.data', $this->lead))->assertOk()
        ->assertJsonPath('data.analyses.0.actions.reanalysis.available', $canEdit);
    if ($canEdit) {
        $this->service->shouldReceive('updateLeadDataAndMaybeUnlock')->once()->andReturn(['changed' => true]);
        $this->service->shouldReceive('startProviderReanalysis')->once();
        $this->post(route('admin.insurance-analyses.provider-reanalysis', $this->analysis), ['nome' => 'Atualizado'])
            ->assertRedirect()->assertSessionHas('success');
    } else {
        $this->postJson(route('admin.insurance-analyses.provider-reanalysis', $this->analysis), ['nome' => 'Intruso'])->assertForbidden();
        expect($this->lead->fresh()->nome)->toBe('Autorização');
    }
    Queue::assertNothingPushed();
})->with([false, true]);

it('keeps readonly brokers from requesting analysis retries or reanalysis', function () {
    $broker = Corretor::query()->create([
        'name' => 'Corretor', 'email' => 'broker-authorization@example.test', 'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE, 'active' => true, 'first_login_verified_at' => now(),
        'permissions' => [CorretorPermissions::VIEW_ANALYSIS],
    ]);
    $this->actingAs($broker, 'admin');
    $this->get(route('admin.insurance-analyses.lead', $this->lead))->assertOk();
    foreach (['retry', 'provider-reanalysis'] as $action) {
        $this->postJson(route('admin.insurance-analyses.'.$action, $this->analysis))->assertForbidden();
    }
    $this->postJson(route('admin.leads.reanalyze', $this->lead))->assertForbidden();
    Queue::assertNothingPushed();
    $this->post(route('admin.insurance-analyses.sync-status', $this->analysis))->assertRedirect()->assertSessionHas('success');
    Queue::assertPushed(SyncProviderAnalysisStatusJob::class);
});

it('does not treat a false second factor flag as verification on any company surface', function () {
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => false]);
    foreach (['lead', 'data'] as $surface) {
        $this->getJson(route('insurance-analyses.'.$surface, $this->lead))->assertRedirect('/2fa');
    }
    $this->get(route('insurance-analyses.show', $this->batch))->assertRedirect('/2fa');
    foreach (['retry', 'provider-reanalysis', 'sync-status'] as $action) {
        $this->postJson(route('insurance-analyses.'.$action, $this->analysis))->assertRedirect('/2fa');
    }
    Queue::assertNothingPushed();
});

it('rejects users without a company instead of matching unlinked leads', function () {
    $this->user->update(['company_id' => null]);
    $this->lead->update(['company_id' => null]);
    $this->analysis->update(['company_id' => null]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true]);
    $this->getJson(route('insurance-analyses.data', $this->lead))->assertForbidden();
    $this->postJson(route('insurance-analyses.retry', $this->analysis))->assertForbidden();
    $this->get(route('analise'))->assertForbidden();
    Queue::assertNothingPushed();
});

it('renders authorized lead links and retires list links in both dashboards', function (bool $admin) {
    if ($admin) {
        $user = Corretor::query()->create([
            'name' => 'Corretor', 'email' => 'broker-authorization@example.test', 'password' => 'password',
            'role' => Corretor::ROLE_INTEGRANTE, 'active' => true, 'first_login_verified_at' => now(),
            'permissions' => [CorretorPermissions::VIEW_ANALYSIS, CorretorPermissions::VIEW_LEADS],
        ]);
    } else {
        $user = $this->user;
    }
    $prefix = $admin ? 'admin.insurance-analyses.' : 'insurance-analyses.';
    $this->actingAs($user, $admin ? 'admin' : 'web')->withSession(['2fa_passed' => true]);
    $this->get(route($admin ? 'Dashboard-Admin' : 'company.dashboard'))->assertOk()
        ->assertSee(route($prefix.'lead', $this->lead), false)
        ->assertDontSee('href="'.route($prefix.'index').'"', false);
    $this->get(route($prefix.'index'))->assertRedirect(route($admin ? 'Dashboard-Admin' : 'company.dashboard').'#leads-section');
    if (! $admin) {
        $this->get(route('analise'))->assertRedirect(route('company.dashboard').'#leads-section');
    }
    Queue::assertNothingPushed();
})->with([false, true]);

it('hides the analysis link from brokers without the view permission', function () {
    $broker = Corretor::query()->create([
        'name' => 'Corretor', 'email' => 'broker-authorization@example.test', 'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE, 'active' => true, 'first_login_verified_at' => now(),
        'permissions' => [CorretorPermissions::VIEW_LEADS],
    ]);
    $this->actingAs($broker, 'admin')->get(route('Dashboard-Admin'))->assertOk()
        ->assertDontSee(route('admin.insurance-analyses.lead', $this->lead), false);
    $this->get(route('admin.insurance-analyses.show', $this->batch))->assertForbidden();
    $this->getJson(route('admin.insurance-analyses.data', $this->lead))->assertForbidden();
    $this->postJson(route('admin.insurance-analyses.sync-status', $this->analysis))->assertForbidden();
    Queue::assertNothingPushed();
});
