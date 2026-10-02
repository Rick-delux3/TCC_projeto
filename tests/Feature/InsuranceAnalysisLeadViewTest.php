<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Models\User;
use App\Support\CorretorPermissions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    Bus::fake();
    Http::preventStrayRequests();
    config(['features.insurance_analysis.enabled' => false]);
    $this->company = Imobiliaria::factory()->create();
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
    $this->lead = Lead::query()->create([
        'nome' => 'Lead da pagina', 'email' => 'lead-page@example.test', 'company_id' => $this->company->id,
    ]);
    $this->corretor = Corretor::query()->create([
        'name' => 'Corretor', 'email' => 'lead-view-broker@example.test', 'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE, 'active' => true, 'first_login_verified_at' => now(),
        'permissions' => [CorretorPermissions::VIEW_ANALYSIS],
    ]);
});

afterEach(function () {
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

it('renders the same empty view for the requested lead without requiring a batch', function (string $viewer) {
    $admin = $viewer === 'admin';
    $route = $admin ? 'admin.insurance-analyses.lead' : 'insurance-analyses.lead';
    $this->actingAs($admin ? $this->corretor : $this->user, $admin ? 'admin' : 'web')
        ->withSession(['2fa_passed' => true]);

    foreach ([$this->lead, Lead::query()->create([
        'nome' => 'Outro lead', 'email' => 'other-lead@example.test', 'company_id' => $this->company->id,
    ])] as $lead) {
        $this->get(route($route, $lead))->assertOk()
            ->assertViewIs('insurance-analyses.index')
            ->assertViewHas('lead', fn (Lead $selected): bool => $selected->is($lead))
            ->assertViewHas('viewerType', $viewer)
            ->assertViewHas('returnUrl', route($admin ? 'Dashboard-Admin' : 'company.dashboard').'#leads-section')
            ->assertViewHas('batch', null)
            ->assertViewHas('analyses', fn ($analyses): bool => $analyses->isEmpty())
            ->assertViewHas('analysisAttempts', [])
            ->assertViewHas('awaitingBatch', true)
            ->assertViewMissing('batches');
    }
    expect($this->lead->insuranceAnalysesBatches()->exists())->toBeFalse();
})->with(['company', 'admin']);

it('loads only the latest lead batch with the current round for each company', function (string $viewer) {
    $old = $this->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 1, 'finished_at' => now()]);
    $this->lead->forceFill(['last_analysis_batch_id' => $old->id])->save();
    $current = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 4]);
    $old->update(['updated_at' => now()->addDay()]);
    $foreignLead = Lead::query()->create(['nome' => 'Foreign', 'email' => 'foreign-batch@example.test']);
    $foreign = $foreignLead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 1]);
    $attempts = [];
    foreach (['pending', 'processing', 'approved', 'rejected'] as $index => $status) {
        $analysis = $current->analyses()->create([
            'lead_id' => $this->lead->id, 'provider' => 'company-'.$index, 'product' => 'fianca_locaticia', 'status' => $status,
        ]);
        $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'initial']]);
        if ($index === 0) {
            $analysis->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new-round']]);
        }
        $analysis->events()->create(['event_type' => 'status_checked', 'payload' => ['attempt_id' => 'obsolete-response']]);
        $attempts[$analysis->id] = ['attempt_id' => $index === 0 ? 'new-round' : 'initial', 'is_reanalysis' => $index === 0];
    }
    $admin = $viewer === 'admin';
    $this->actingAs($admin ? $this->corretor : $this->user, $admin ? 'admin' : 'web')->withSession(['2fa_passed' => true]);
    $this->get(route($admin ? 'admin.insurance-analyses.lead' : 'insurance-analyses.lead', [
        'lead' => $this->lead, 'batch' => $foreign->id,
    ]))->assertOk()
        ->assertViewHas('batch', fn (InsuranceAnalysisBatch $batch): bool => $batch->is($current) && $batch->finished_at === null)
        ->assertViewHas('analyses', fn ($analyses): bool => $analyses->pluck('status')->all() === ['pending', 'processing', 'approved', 'rejected'])
        ->assertViewHas('analysisAttempts', $attempts)
        ->assertViewHas('awaitingBatch', false)
        ->assertViewHas('lead', fn (Lead $lead): bool => $lead->relationLoaded('endereco') && $lead->relationLoaded('despesas') && $lead->relationLoaded('conjuge'));
})->with(['company', 'admin']);

it('reads new batch and analysis states on subsequent visits without creating or finalizing records', function () {
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true]);
    $url = route('insurance-analyses.lead', $this->lead);
    $this->get($url)->assertOk()->assertViewHas('awaitingBatch', true);
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 1]);
    $this->get($url)->assertOk()->assertViewHas('awaitingBatch', false)
        ->assertViewHas('analyses', fn ($analyses): bool => $analyses->isEmpty());
    $analysis = $batch->analyses()->create([
        'lead_id' => $this->lead->id, 'provider' => 'too', 'product' => 'fianca_locaticia', 'status' => 'processing',
        'response_payload' => ['too_analysis_attempt_id' => 'legacy'],
    ]);
    $this->get($url)->assertOk()
        ->assertViewHas('analysisAttempts', [$analysis->id => ['attempt_id' => 'legacy', 'is_reanalysis' => false]])
        ->assertViewHas('analyses', fn ($analyses): bool => $analyses->sole()->status === 'processing');
    $analysis->update(['status' => 'approved', 'finished_at' => now()]);
    $this->get($url)->assertOk()->assertViewHas('analyses', fn ($analyses): bool => $analyses->sole()->status === 'approved');
    expect($batch->fresh()->status)->toBe('processing')->and($batch->fresh()->finished_at)->toBeNull()
        ->and($analysis->events()->count())->toBe(0);
});

it('loads attempt contexts without per-company queries but still checks fresh rounds when locking', function () {
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 4]);
    foreach (range(1, 4) as $index) {
        $analysis = $batch->analyses()->create([
            'lead_id' => $this->lead->id, 'provider' => 'company-'.$index, 'product' => 'fianca_locaticia', 'status' => 'processing',
        ]);
        $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'initial']]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $lead = $this->lead->fresh();
    $lead->load('latestInsuranceAnalysisBatch.analyses.latestAttemptEvent');
    $queries = count(DB::getQueryLog());
    $analyses = $lead->latestInsuranceAnalysisBatch->analyses;
    foreach ($analyses as $analysis) {
        expect($analysis->currentAttemptContext()['attempt_id'])->toBe('initial')
            ->and($analysis->relationLoaded('events'))->toBeFalse();
    }
    expect(count(DB::getQueryLog()))->toBe($queries);
    DB::disableQueryLog();
    $analysis = $analyses->first();
    $analysis->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new-round']]);
    expect($analysis->currentAttemptContext(true))->toBe(['attempt_id' => 'new-round', 'is_reanalysis' => true]);
});

it('denies company access to a foreign or unlinked lead', function (bool $unlinked) {
    $this->lead->update(['company_id' => $unlinked ? null : Imobiliaria::factory()->create()->id]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.lead', $this->lead))->assertForbidden();
})->with([false, true]);

it('denies users without a company even for an unlinked lead', function () {
    $this->user->update(['company_id' => null]);
    $this->lead->update(['company_id' => null]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.lead', $this->lead))->assertForbidden();
});

it('requires analysis permission for a broker', function () {
    $this->corretor->update(['permissions' => [CorretorPermissions::VIEW_LEADS]]);
    $this->actingAs($this->corretor, 'admin')
        ->get(route('admin.insurance-analyses.lead', $this->lead))->assertForbidden();
});

it('preserves authentication and second factor protection', function (string $viewer, bool $authenticated) {
    $admin = $viewer === 'admin';
    $route = $admin ? 'admin.insurance-analyses.lead' : 'insurance-analyses.lead';
    if ($authenticated) {
        $this->corretor->update(['first_login_verified_at' => null]);
        $this->actingAs($admin ? $this->corretor : $this->user, $admin ? 'admin' : 'web');
    }
    $response = $this->get(route($route, $this->lead));
    if ($authenticated) {
        $response->assertRedirect($admin ? route('admin.2fa.form') : url('/2fa'));
    } else {
        $response->assertRedirect(route($admin ? 'admin.login' : 'empresa.login'));
    }
})->with(['company', 'admin'])->with([false, true]);

it('rejects inactive brokers', function () {
    $this->corretor->update(['active' => false]);
    $this->actingAs($this->corretor, 'admin')
        ->get(route('admin.insurance-analyses.lead', $this->lead))->assertRedirect(route('admin.login'));
});

it('returns not found for nonexistent leads', function (string $viewer) {
    $admin = $viewer === 'admin';
    $this->actingAs($admin ? $this->corretor : $this->user, $admin ? 'admin' : 'web')
        ->withSession(['2fa_passed' => true])
        ->get(route($admin ? 'admin.insurance-analyses.lead' : 'insurance-analyses.lead', 999999))->assertNotFound();
})->with(['company', 'admin']);

it('redirects legacy lists and batch links without rendering removed views', function (string $viewer) {
    $admin = $viewer === 'admin';
    $prefix = $admin ? 'admin.insurance-analyses.' : 'insurance-analyses.';
    $batch = $this->lead->insuranceAnalysesBatches()->create([
        'company_id' => $this->company->id, 'status' => 'processing', 'total_providers' => 2,
    ]);
    $this->actingAs($admin ? $this->corretor : $this->user, $admin ? 'admin' : 'web')
        ->withSession(['2fa_passed' => true]);

    $this->get(route($prefix.'index'))->assertRedirect(route($admin ? 'Dashboard-Admin' : 'company.dashboard').'#leads-section');
    $this->get(route($prefix.'show', $batch))->assertRedirect(route($prefix.'lead', $this->lead));
})->with(['company', 'admin']);

it('checks current lead ownership before redirecting a legacy batch link', function () {
    $batch = $this->lead->insuranceAnalysesBatches()->create([
        'company_id' => $this->company->id, 'status' => 'completed', 'total_providers' => 2,
    ]);
    $this->lead->update(['company_id' => Imobiliaria::factory()->create()->id]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.show', $batch))->assertForbidden();
});
