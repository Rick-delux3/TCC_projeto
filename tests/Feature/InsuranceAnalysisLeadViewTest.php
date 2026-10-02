<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Models\User;
use App\Services\Insurance\InsuranceAnalysisReadService;
use App\Support\CorretorPermissions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;

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
            ->assertViewHas('refreshUrl', route($admin ? 'admin.insurance-analyses.data' : 'insurance-analyses.data', $lead))
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
            'request_payload' => ['private' => 'request-secret'], 'response_payload' => ['private' => 'response-secret'],
            'error_message' => 'technical-secret', 'quote_pdf_path' => 'private/secret.pdf',
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
    $view = $this->get(route($admin ? 'admin.insurance-analyses.lead' : 'insurance-analyses.lead', [
        'lead' => $this->lead, 'batch' => $foreign->id,
    ]))->assertOk()
        ->assertViewHas('batch', fn (InsuranceAnalysisBatch $batch): bool => $batch->is($current) && $batch->finished_at === null)
        ->assertViewHas('analyses', fn ($analyses): bool => $analyses->pluck('status')->all() === ['pending', 'processing', 'approved', 'rejected'])
        ->assertViewHas('analysisAttempts', $attempts)
        ->assertViewHas('awaitingBatch', false)
        ->assertViewHas('lead', fn (Lead $lead): bool => $lead->relationLoaded('endereco') && $lead->relationLoaded('despesas') && $lead->relationLoaded('conjuge'));

    $this->getJson($view->viewData('refreshUrl').'?batch='.$foreign->id.'&status=approved&page=2&search=foreign')
        ->assertOk()
        ->assertJsonPath('data.lead.id', $this->lead->id)
        ->assertJsonPath('data.batch.id', $view->viewData('batch')->id)
        ->assertJsonPath('data.awaiting_batch', false)
        ->assertJsonCount(4, 'data.analyses')
        ->assertJsonPath('data.analyses.*.status', $view->viewData('analyses')->pluck('status')->all())
        ->assertJsonPath('data.analyses.*.attempt', array_values($view->viewData('analysisAttempts')))
        ->assertJsonMissingPath('data.analyses.0.request_payload')
        ->assertJsonMissingPath('data.analyses.0.response_payload')
        ->assertJsonMissingPath('data.analyses.0.error_message')
        ->assertJsonMissingPath('data.analyses.0.quote_pdf_path')
        ->assertJsonMissingPath('data.lead.leadlovers_response')
        ->assertDontSee('request-secret')->assertDontSee('response-secret')
        ->assertDontSee('technical-secret')->assertDontSee('private/secret.pdf');
})->with(['company', 'admin']);

it('refreshes the saved state before during and after processing without writing to the database', function (string $viewer) {
    $admin = $viewer === 'admin';
    $this->actingAs($admin ? $this->corretor : $this->user, $admin ? 'admin' : 'web')->withSession(['2fa_passed' => true]);
    $url = route($admin ? 'admin.insurance-analyses.data' : 'insurance-analyses.data', $this->lead);
    $this->getJson($url)->assertOk()->assertJsonPath('data.awaiting_batch', true)
        ->assertJsonPath('data.batch', null)->assertJsonPath('data.analyses', []);

    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 1]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.awaiting_batch', false)->assertJsonPath('data.analyses', []);
    $analysis = $batch->analyses()->create([
        'lead_id' => $this->lead->id, 'provider' => 'too', 'product' => 'fianca_locaticia', 'status' => 'processing',
        'response_payload' => ['too_analysis_attempt_id' => 'legacy'],
    ]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.analyses.0.status', 'processing')
        ->assertJsonPath('data.analyses.0.attempt.attempt_id', 'legacy')->assertJsonPath('data.batch.finished_at', null);
    $analysis->update(['status' => 'approved', 'premium_amount' => '150.25', 'finished_at' => now()]);
    $batch->update(['status' => 'completed', 'completed_providers' => 1, 'finished_at' => now()]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.batch.status', 'completed')
            ->assertJsonPath('data.batch.finished_at', $batch->finished_at->toIso8601String())
            ->assertJsonPath('data.analyses.0.status', 'approved')
            ->assertJsonPath('data.analyses.0.premium_amount', '150.25');
        expect(collect(DB::getQueryLog())->every(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select')))->toBeTrue();
    } finally {
        DB::disableQueryLog();
    }
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
    app(InsuranceAnalysisReadService::class)->read($lead);
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

it('denies company access to a foreign or unlinked lead', function (bool $unlinked, string $surface) {
    $this->lead->update(['company_id' => $unlinked ? null : Imobiliaria::factory()->create()->id]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.'.$surface, $this->lead))->assertForbidden();
})->with([false, true])->with(['lead', 'data']);

it('denies users without a company even for an unlinked lead', function (string $surface) {
    $this->user->update(['company_id' => null]);
    $this->lead->update(['company_id' => null]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.'.$surface, $this->lead))->assertForbidden();
})->with(['lead', 'data']);

it('requires analysis permission for a broker', function (string $surface) {
    $this->corretor->update(['permissions' => [CorretorPermissions::VIEW_LEADS]]);
    $this->actingAs($this->corretor, 'admin')
        ->get(route('admin.insurance-analyses.'.$surface, $this->lead))->assertForbidden();
})->with(['lead', 'data']);

it('preserves authentication and second factor protection', function (string $viewer, bool $authenticated, string $surface) {
    $admin = $viewer === 'admin';
    $route = ($admin ? 'admin.insurance-analyses.' : 'insurance-analyses.').$surface;
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
})->with(['company', 'admin'])->with([false, true])->with(['lead', 'data']);

it('rejects inactive brokers', function (string $surface) {
    $this->corretor->update(['active' => false]);
    $this->actingAs($this->corretor, 'admin')
        ->get(route('admin.insurance-analyses.'.$surface, $this->lead))->assertRedirect(route('admin.login'));
})->with(['lead', 'data']);

it('returns not found for nonexistent leads', function (string $viewer, string $surface) {
    $admin = $viewer === 'admin';
    $this->actingAs($admin ? $this->corretor : $this->user, $admin ? 'admin' : 'web')
        ->withSession(['2fa_passed' => true])
        ->get(route(($admin ? 'admin.insurance-analyses.' : 'insurance-analyses.').$surface, 999999))->assertNotFound();
})->with(['company', 'admin'])->with(['lead', 'data']);

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

it('passes the same contract to the includes and the refresh endpoint without rendering markup', function (string $viewer) {
    $admin = $viewer === 'admin';
    $this->actingAs($admin ? $this->corretor : $this->user, $admin ? 'admin' : 'web')->withSession(['2fa_passed' => true]);
    $includes = [];
    View::composer('insurance-analyses.partials.*', function ($view) use (&$includes): void {
        $includes[$view->name()] = $view->getData();
    });
    $response = $this->get(route($admin ? 'admin.insurance-analyses.lead' : 'insurance-analyses.lead', $this->lead))->assertOk();
    $page = $response->viewData('pageData');
    expect(trim($response->getContent()))->toBe('')
        ->and($includes)->toHaveCount(6)
        ->and($includes['insurance-analyses.partials.lead']['leadData'])->toBe($page['lead'])
        ->and($includes['insurance-analyses.partials.lead']['navigation']['return_url'])->toBe($response->viewData('returnUrl'))
        ->and($includes['insurance-analyses.partials.batch']['progress'])->toBe($page['progress'])
        ->and($includes['insurance-analyses.partials.companies']['companies'])->toBe([])
        ->and($includes['insurance-analyses.partials.comparison']['comparison']['reason'])->toBe('awaiting_batch')
        ->and($includes['insurance-analyses.partials.actions']['permissions'])->toBe($page['permissions'])
        ->and($includes['insurance-analyses.partials.realtime']['realtime']['should_refresh'])->toBeTrue()
        ->and($page['realtime']['broadcasting']['enabled'])->toBeFalse();
    $this->getJson($response->viewData('refreshUrl'))->assertOk()->assertExactJson(['data' => $page]);
})->with(['company', 'admin']);

it('prepares progress and comparison only for complete consolidated results', function (array $statuses, int $total, bool $finished, ?string $result, ?string $reason) {
    $batch = $this->lead->insuranceAnalysesBatches()->create([
        'status' => $finished ? 'completed' : 'processing', 'total_providers' => $total,
        'finished_at' => $finished ? now() : null,
    ]);
    foreach ($statuses as $index => $status) {
        $batch->analyses()->create([
            'lead_id' => $this->lead->id, 'provider' => 'company-'.$index, 'product' => 'fianca_locaticia',
            'status' => $status, 'gross_premium' => 1200 - $index * 100,
            'lease_start_date' => '2026-10-01', 'lease_end_date' => '2027-10-01',
        ]);
    }
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true]);
    $response = $this->getJson(route('insurance-analyses.data', $this->lead))->assertOk()
        ->assertJsonPath('data.result.status', $result)
        ->assertJsonPath('data.result.is_final', $result !== null)
        ->assertJsonPath('data.progress.total', $total)
        ->assertJsonPath('data.progress.finished', count(array_intersect($statuses, ['approved', 'rejected', 'failed'])))
        ->assertJsonPath('data.realtime.should_refresh', $result === null)
        ->assertJsonPath('data.comparison.reason', $reason)
        ->assertJsonPath('data.comparison.available', $reason === null);
    if ($reason === null) {
        $response->assertJsonPath('data.comparison.best_quote.provider', 'company-1')
            ->assertJsonPath('data.comparison.best_quote.price.total', '1100.00');
    } else {
        $response->assertJsonPath('data.comparison.best_quote', null);
    }
})->with([
    'pending company' => [['approved', 'pending'], 2, true, null, 'awaiting_results'],
    'missing company' => [['approved'], 2, true, null, 'awaiting_results'],
    'unknown state' => [['approved', 'unrecognized'], 2, true, null, 'awaiting_results'],
    'awaiting consolidation' => [['approved', 'failed'], 2, false, null, 'awaiting_consolidation'],
    'all refused' => [['rejected', 'rejected'], 2, true, 'rejected', 'no_approved_quotes'],
    'technical failure' => [['failed', 'rejected'], 2, true, 'failed', 'no_approved_quotes'],
    'best approved' => [['approved', 'approved'], 2, true, 'approved', null],
]);

it('does not offer unconfirmed prices as the best budget', function () {
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 1, 'finished_at' => now()]);
    $batch->analyses()->create([
        'lead_id' => $this->lead->id, 'provider' => 'pottencial', 'product' => 'fianca_locaticia',
        'status' => 'approved', 'premium_amount' => 100,
    ]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true])
        ->getJson(route('insurance-analyses.data', $this->lead))->assertOk()
        ->assertJsonPath('data.comparison.reason', 'missing_confirmed_total')
        ->assertJsonPath('data.comparison.best_quote', null);
});

it('offers company actions only for allowed states ownership and feature configuration', function () {
    config(['features.insurance_analysis.enabled' => true]);
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 1]);
    $analysis = $batch->analyses()->create([
        'lead_id' => $this->lead->id, 'company_id' => $this->company->id,
        'provider' => 'too', 'product' => 'fianca_locaticia', 'status' => 'processing', 'proposal_id' => 'proposal',
        'response_payload' => ['too_status_check_stopped' => true, 'too_manual_sync_available' => true],
    ]);
    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'round']]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true]);
    $url = route('insurance-analyses.data', $this->lead);
    $this->getJson($url)->assertOk()->assertJsonPath('data.analyses.0.actions.sync.url', route('insurance-analyses.sync-status', $analysis))
        ->assertJsonPath('data.analyses.0.actions.retry.available', false)
        ->assertJsonPath('data.analyses.0.actions.reanalysis.available', false)
        ->assertJsonPath('data.actions.update_lead.method', 'PUT');
    $analysis->update(['status' => 'failed']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.analyses.0.actions.retry.available', true)
        ->assertJsonPath('data.analyses.0.actions.sync.available', false);
    $analysis->update(['status' => 'approved']);
    $batch->update(['status' => 'completed', 'finished_at' => now()]);
    $this->lead->forceFill(['last_analysis_batch_id' => $batch->id, 'analysis_final_status' => 'approved', 'reanalysis_unlocked_at' => now()])->save();
    $this->getJson($url)->assertOk()->assertJsonPath('data.actions.reanalysis.available', true)
        ->assertJsonPath('data.analyses.0.actions.reanalysis.available', true)
        ->assertJsonPath('data.analyses.0.actions.reanalysis.requires_data_changes', true);
    $analysis->update(['proposal_id' => null]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.analyses.0.actions.reanalysis.available', false)
        ->assertJsonPath('data.actions.reanalysis.available', false);
    $analysis->update(['status' => 'failed', 'company_id' => null]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.analyses.0.actions.retry.url', null);
    $analysis->update(['company_id' => $this->company->id]);
    config(['features.insurance_analysis.enabled' => false]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.analyses.0.actions.retry.url', null);
});

it('keeps read-only broker actions separate from editing and analysis permissions', function () {
    config(['features.insurance_analysis.enabled' => true]);
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 1]);
    $analysis = $batch->analyses()->create([
        'lead_id' => $this->lead->id, 'provider' => 'pottencial', 'product' => 'fianca_locaticia', 'status' => 'failed', 'quote_id' => 'quote',
    ]);
    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'round']]);
    $this->actingAs($this->corretor, 'admin');
    $url = route('admin.insurance-analyses.data', $this->lead);
    $this->getJson($url)->assertOk()->assertJsonPath('data.permissions.edit_lead', false)
        ->assertJsonPath('data.actions.update_lead.url', null)
        ->assertJsonPath('data.analyses.0.actions.retry.url', null)
        ->assertJsonPath('data.analyses.0.actions.sync.url', route('admin.insurance-analyses.sync-status', $analysis));
    $this->corretor->update(['permissions' => [CorretorPermissions::VIEW_ANALYSIS, CorretorPermissions::VIEW_LEADS, CorretorPermissions::EDIT_LEADS, CorretorPermissions::CREATE_ANALYSIS]]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.permissions.edit_lead', true)
        ->assertJsonPath('data.actions.update_lead.url', route('admin.leads.update', $this->lead))
        ->assertJsonPath('data.actions.update_lead.method', 'POST')
        ->assertJsonPath('data.analyses.0.actions.retry.url', route('admin.insurance-analyses.retry', $analysis));
});
