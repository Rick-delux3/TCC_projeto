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

it('renders the same analysis view for the requested lead without requiring a batch', function (string $viewer) {
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

it('renders the bootstrap structure using the same contract as the refresh endpoint', function (string $viewer) {
    $admin = $viewer === 'admin';
    $this->actingAs($admin ? $this->corretor : $this->user, $admin ? 'admin' : 'web')->withSession(['2fa_passed' => true]);
    $includes = [];
    View::composer('insurance-analyses.partials.*', function ($view) use (&$includes): void {
        $includes[$view->name()] = $view->getData();
    });
    $response = $this->get(route($admin ? 'admin.insurance-analyses.lead' : 'insurance-analyses.lead', $this->lead))->assertOk();
    $page = $response->viewData('pageData');
    $response->assertSee('bootstrap@5.3.8/dist/css/bootstrap.min.css', false)
        ->assertSee($this->lead->nome)->assertSee($this->lead->email)
        ->assertSee('Aguardando a criação do pacote')
        ->assertSee($response->viewData('returnUrl'), false)
        ->assertSee('Todas as companhias')
        ->assertDontSee('dashboard-user.css', false)
        ->assertDontSee('class="modal', false);
    expect($includes)->toHaveCount(7)
        ->and($includes['insurance-analyses.partials.live-content']['pageData'])->toBe($page)
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

it('renders four company cards and keeps providers disabled in the environment blocked', function () {
    config(['services.pottencial.enabled' => true, 'services.too.enabled' => true, 'services.tokio.enabled' => true, 'services.porto.enabled' => false]);
    $response = $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.lead', $this->lead))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);

    expect($xpath->query('//article[@data-provider]')->length)->toBe(4);
    foreach (['porto'] as $provider) {
        $card = $xpath->query('//article[@data-provider="'.$provider.'"]')->item(0);
        expect($card->textContent)->toContain('Bloqueada')->toContain('Aguardando testes e validação');
        expect($xpath->query('.//button[@disabled]', $card)->length)->toBe(1)
            ->and($xpath->query('.//a | .//form', $card)->length)->toBe(0);
    }
    $tokio = $xpath->query('//article[@data-provider="tokio"]')->item(0);
    expect($tokio->textContent)->not->toContain('Bloqueada')->toContain('Aguardando análise desta companhia.');
    expect($xpath->query('//form')->length)->toBe(0)
        ->and($xpath->query('//button[not(@disabled)]')->length)->toBe(0);
});

it('renders Tokio installments and manual guidance for each viewer', function (string $viewer, string $status) {
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 1, 'finished_at' => now()]);
    $batch->analyses()->create([
        'lead_id' => $this->lead->id, 'provider' => 'tokio', 'product' => 'fianca_locaticia', 'status' => $status,
        'lease_start_date' => '2026-11-03', 'lease_end_date' => '2029-05-03',
        'response_payload' => ['quote_selection' => ['installment_amount' => '45.50', 'installments' => 30, 'payment_type' => 'credito']],
    ]);
    $admin = $viewer === 'admin';
    $response = $this->actingAs($admin ? $this->corretor : $this->user, $admin ? 'admin' : 'web')
        ->withSession(['2fa_passed' => true])
        ->get(route($admin ? 'admin.insurance-analyses.lead' : 'insurance-analyses.lead', $this->lead))->assertOk();
    if ($status === 'approved') {
        $response->assertSee('30 parcelas de R$ 45,50')->assertSee('Cartão de crédito')->assertSee('Melhor preço');
    } else {
        $response->assertSee($admin ? 'realizar cotação manualmente' : 'entrar em contato com corretor');
    }
})->with([['admin', 'approved'], ['company', 'approved'], ['admin', 'manual_required'], ['company', 'manual_required']]);

it('renders saved coverages and highlights the confirmed best total only after consolidation', function () {
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 2]);
    $batch->analyses()->create([
        'lead_id' => $this->lead->id, 'provider' => 'pottencial', 'product' => 'fianca_locaticia',
        'status' => 'approved', 'gross_premium' => 1200,
        'lease_start_date' => '2026-10-01', 'lease_end_date' => '2027-10-01',
        'response_payload' => ['coverages' => [['name' => 'Aluguel protegido', 'insuredAmount' => 24000, 'premiumAmount' => 1100]]],
    ]);
    $too = $batch->analyses()->create([
        'lead_id' => $this->lead->id, 'provider' => 'too', 'product' => 'fianca_locaticia',
        'status' => 'processing', 'premium_amount' => 1,
    ]);
    $too->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'rodada-teste']]);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true]);
    $url = route('insurance-analyses.lead', $this->lead);
    $this->get($url)->assertOk()
        ->assertSee('Aluguel protegido')->assertSee('R$ 24.000,00')->assertSee('R$ 1.200,00')
        ->assertSee('1 de 2 análises finalizadas (50%)')->assertSee('rodada-teste')
        ->assertSee('Em análise')->assertDontSee('>Melhor preço</div>', false)
        ->assertDontSee('R$ 1,00');

    $too->update([
        'status' => 'approved', 'finished_at' => now(),
        'response_payload' => ['quote_latest' => ['response' => [
            'condicoesPagamento' => ['premioBrutoTotal' => 900],
            'inicioVigencia' => '2026-10-01', 'fimVigencia' => '2027-10-01',
        ]]],
    ]);
    $batch->update(['status' => 'completed', 'finished_at' => now()]);
    $response = $this->get($url)->assertOk()
        ->assertSee('2 de 2 análises finalizadas (100%)')
        ->assertSee('R$ 900,00')->assertSee('Prêmio bruto total da vigência')
        ->assertDontSee('/mês');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $best = (new DOMXPath($document))->query('//article[div[text()="Melhor preço"]]');
    expect($best->length)->toBe(1)
        ->and($best->item(0)->textContent)->toContain('Too Seguros')->toContain('R$ 900,00');
});

it('does not advertise failed rejected or unpriced quotes as offers', function (string $status, ?float $premium) {
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 1, 'finished_at' => now()]);
    $batch->analyses()->create([
        'lead_id' => $this->lead->id, 'provider' => 'pottencial', 'product' => 'fianca_locaticia',
        'status' => $status, 'premium_amount' => $premium,
    ]);
    $this->lead->update(['nome' => '<script>alert("lead")</script>']);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.lead', $this->lead))->assertOk()
        ->assertSee('Nenhuma oferta aprovada com preço confirmado disponível.')
        ->assertDontSee('>Melhor preço</div>', false)
        ->assertDontSee('<script>alert("lead")</script>', false);
})->with([
    'rejected' => ['rejected', 0], 'failed' => ['failed', 0],
    'without price' => ['approved', null], 'unconfirmed price' => ['approved', 50],
]);

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
    config(['features.insurance_analysis.enabled' => true, 'services.too.enabled' => true]);
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
    $this->lead->forceFill(['last_analysis_batch_id' => $batch->id, 'analysis_final_status' => 'approved'])->save();
    // Too aprovada não aceita reanálise; não existe mais reanálise geral do lead.
    $this->getJson($url)->assertOk()->assertJsonMissingPath('data.actions.reanalysis')
        ->assertJsonPath('data.analyses.0.reanalysis_supported', false)
        ->assertJsonPath('data.analyses.0.actions.reanalysis.available', false)
        ->assertJsonPath('data.analyses.0.actions.expense_reanalysis.available', false);
    config(['services.tokio.enabled' => true]);
    $analysis->update(['provider' => 'tokio']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.analyses.0.reanalysis_supported', true)
        ->assertJsonPath('data.analyses.0.actions.reanalysis.available', true)
        ->assertJsonPath('data.analyses.0.actions.reanalysis.requires_data_changes', true);
    $analysis->update(['provider' => 'too']);
    $analysis->update(['status' => 'failed', 'company_id' => null]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.analyses.0.actions.retry.url', route('insurance-analyses.retry', $analysis));
    $analysis->update(['company_id' => $this->company->id]);
    config(['features.insurance_analysis.enabled' => false]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.analyses.0.actions.retry.url', null);
});

it('keeps read-only broker actions separate from editing and analysis permissions', function () {
    config(['features.insurance_analysis.enabled' => true, 'services.pottencial.enabled' => true]);
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

/** Pacote Pottencial concluído com os dois planos de um único cenário. */
function pottencialViewQuotes($test, array $plans): \DOMXPath
{
    $batch = $test->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 1, 'finished_at' => now()]);
    $analysis = $batch->analyses()->create([
        'lead_id' => $test->lead->id, 'provider' => 'pottencial', 'product' => 'fianca_locaticia', 'status' => 'approved',
    ]);
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'plans-display']]);
    $run = app(\App\Services\Insurance\InsuranceQuoteCoordinator::class)->prepare($analysis, 'plans-display', false);
    foreach ($run->scenarios()->first()->quotes()->get() as $quote) {
        [$status, $amount, $recurring] = $plans[$quote->plan_key] + [null, null, null];
        $quote->update([
            'status' => $status, 'installment_amount' => $amount, 'installments' => 30,
            'payment_status' => $amount ? 'ready' : 'unavailable',
            'response_payload' => ['private' => 'secret-response',
                'coverages' => [['name' => 'Pintura interna '.$quote->plan_key, 'insuredAmount' => 4000, 'premiumAmount' => 50]],
                'payment' => ['selection' => ['amount' => $amount, 'recurring_installment' => $recurring]],
            ],
        ]);
    }
    $response = $test->actingAs($test->user)->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.lead', $test->lead))->assertOk()->assertDontSee('secret-response');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

    return new DOMXPath($document);
}

it('shows only the cheaper approved Pottencial plan on the card and both plans in the details', function () {
    $xpath = pottencialViewQuotes($this, [
        'Complete' => ['approved', 179.58, '179.43'],
        'Traditional' => ['approved', 210.00, '210.00'],
    ]);
    $card = $xpath->query('//article[@data-provider="pottencial"]')->item(0);
    expect($card->textContent)->toContain('Visualizar', 'Completo', 'R$ 179,58', '1ª parcela · depois 29× de R$ 179,43')
        ->not->toContain('Tradicional', 'R$ 210,00', 'Pintura interna', 'Exibido no card');
    expect($xpath->query('.//button[@data-analysis-dialog and not(@disabled)]', $card)->length)->toBe(1);

    $details = $xpath->query('//dialog[@id="details-pottencial"]')->item(0);
    $plans = $xpath->query('.//section//h3', $details);
    expect($details->hasAttribute('open'))->toBeFalse()
        ->and($plans->length)->toBe(2)
        ->and($plans->item(0)->textContent)->toBe('Completo')
        ->and($plans->item(1)->textContent)->toBe('Tradicional')
        ->and($details->textContent)->toContain('Exibido no card', 'Pintura interna Complete', 'Pintura interna Traditional',
            '1ª parcela de R$ 179,58 e 29 parcelas de R$ 179,43', 'R$ 210,00', '30 parcelas de R$ 210,00');
});

it('keeps a refused Pottencial plan out of the card and inside the details', function () {
    $xpath = pottencialViewQuotes($this, ['Complete' => ['approved', 100.00, '100.00'], 'Traditional' => ['rejected']]);
    $card = $xpath->query('//article[@data-provider="pottencial"]')->item(0);
    expect($card->textContent)->toContain('Completo', 'R$ 100,00', 'Por parcela · 30 parcelas')
        ->not->toContain('Tradicional', 'Cotação recusada', 'Pintura interna');

    $details = $xpath->query('//dialog[@id="details-pottencial"]')->item(0);
    expect($details->textContent)->toContain('Tradicional', 'Cotação recusada', 'Pintura interna Complete')
        ->not->toContain('Pintura interna Traditional');
});

it('hides an unavailable Pottencial plan from the card and the details', function () {
    $xpath = pottencialViewQuotes($this, [
        'Complete' => [\App\Services\Insurance\PottencialQuoteResponse::PLAN_UNAVAILABLE],
        'Traditional' => ['approved', 179.58, '179.43'],
    ]);
    $card = $xpath->query('//article[@data-provider="pottencial"]')->item(0);
    $details = $xpath->query('//dialog[@id="details-pottencial"]')->item(0);
    expect($card->textContent)->toContain('Tradicional', 'R$ 179,58')->not->toContain('Completo', 'indisponível')
        ->and($xpath->query('.//section//h3', $details)->length)->toBe(1)
        ->and($details->textContent)->toContain('Tradicional')->not->toContain('Completo', 'indisponível', 'Exibido no card');
});

it('places the cheapest confirmed offer between the next two prices', function () {
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 3, 'finished_at' => now()]);
    foreach (['pottencial' => 150, 'too' => 120, 'tokio' => 100] as $provider => $amount) {
        $batch->analyses()->create([
            'lead_id' => $this->lead->id, 'provider' => $provider, 'product' => 'fianca_locaticia', 'status' => 'approved',
            'lease_start_date' => '2026-11-01', 'lease_end_date' => '2029-05-01',
            'response_payload' => ['quote_selection' => ['installment_amount' => $amount, 'installments' => 30, 'payment_type' => 'credito']],
        ]);
    }
    $response = $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.lead', $this->lead))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $cards = (new DOMXPath($document))->query('//section[@aria-labelledby="comparison-title"]//article');
    expect($cards)->toHaveCount(3)
        ->and($cards->item(0)->textContent)->toContain('Too Seguros', 'R$ 120,00')
        ->and($cards->item(1)->textContent)->toContain('Tokio Marine', 'R$ 100,00', 'Melhor preço')
        ->and($cards->item(2)->textContent)->toContain('Pottencial', 'R$ 150,00');
});

it('opens quote details while updating components without reloading the page', function () {
    $source = file_get_contents(resource_path('js/insurance-analyses.js'));
    $source = preg_replace('/^import .*;\R/m', '', $source);
    $script = <<<'JS'
const assert = require('node:assert/strict');
let click, onState, onClose, reloads = 0, open = false;
const updates = [];
const dialog = {
    showModal() { open = true; },
    close() { open = false; },
    addEventListener(event, callback) { if (event === 'close') onClose = callback; }
};
const initial = { realtime: { broadcasting: { enabled: false } }, analyses: [] };
global.window = {
    insuranceAnalysisInitialState: initial,
    location: { reload() { reloads++; } },
    dispatchEvent() {}, addEventListener() {}
};
global.document = {
    addEventListener(event, callback) { if (event === 'click') click = callback; },
    getElementById() { return dialog; },
    querySelectorAll(selector) { return selector === 'dialog' ? [dialog] : []; },
    querySelector(selector) { return selector === 'dialog[open]' && open ? dialog : null; }
};
global.CustomEvent = class {};
function createAnalysisComponents() { return { update(html) { updates.push(html); }, flush() {} }; }
function createReanalysisModes() { return { apply() {}, setMode() {} }; }
function createAnalysisRealtime(state, options) {
    onState = options.onState;
    return { start() { onState(state); }, destroy() {} };
}
JS;
    $script .= "\n".$source.<<<'JS'
assert.equal(reloads, 0);
click({ target: { closest(selector) { return selector === '[data-analysis-dialog]' ? { dataset: { analysisDialog: 'details-too' } } : null; } } });
assert.equal(open, true);
onState({ ...initial, analyses: [{ status: 'approved' }], components: 'updated-cards' });
assert.equal(updates.at(-1), 'updated-cards');
assert.equal(reloads, 0);
click({ target: { closest(selector) { return selector === 'dialog' ? dialog : selector === '[data-close-analysis-dialog]' ? {} : null; } } });
assert.equal(open, false);
assert.equal(reloads, 0);
JS;
    $process = new \Symfony\Component\Process\Process(['node', '-e', $script], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('returns authorized Blade components and individual processing states without exposing raw provider data', function (string $viewer) {
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 1]);
    $analysis = $batch->analyses()->create(['lead_id' => $this->lead->id, 'provider' => 'too', 'product' => 'fianca_locaticia', 'status' => 'processing']);
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'fragment-test']]);
    $run = app(\App\Services\Insurance\InsuranceQuoteCoordinator::class)->prepare($analysis, 'fragment-test', false);
    $quote = $run->scenarios()->first()->quotes()->first();
    $admin = $viewer === 'admin';
    $this->actingAs($admin ? $this->corretor : $this->user, $admin ? 'admin' : 'web')->withSession(['2fa_passed' => true]);
    $url = route($admin ? 'admin.insurance-analyses.data' : 'insurance-analyses.data', $this->lead);
    foreach ([
        ['processing', 'pending', false, 'processing', true],
        ['approved', 'pending', false, 'pricing', true],
        ['approved', 'ready', false, 'approved', false],
        ['rejected', 'pending', false, 'rejected', false],
        ['failed', 'pending', false, 'failed', false],
        ['manual_required', 'pending', false, 'manual_required', false],
        ['manual_review', 'pending', true, 'awaiting_provider', false],
    ] as [$status, $payment, $stopped, $state, $processing]) {
        $quote->update(['status' => $status, 'payment_status' => $payment,
            'installment_amount' => $payment === 'ready' ? 100 : null,
            'response_payload' => ['too_status_check_stopped' => $stopped, 'access_token' => 'secret-provider-token', 'cpf' => '00011122233']]);
        $response = $this->getJson($url, ['X-Analysis-Components' => '1'])->assertOk()
            ->assertJsonPath('data.analyses.0.quote_attempts.0.display_state.state', $state)
            ->assertJsonPath('data.analyses.0.quote_attempts.0.display_state.processing', $processing)
            ->assertJsonPath('data.realtime.round_id', $run->insurance_analysis_round_id)
            ->assertHeader('Cache-Control', 'no-store, private');
        expect($response->json('data.components'))->toContain('data-analysis-component="provider-too"', 'data-analysis-component="comparison"', 'data-analysis-state="'.$state.'"')
            ->not->toContain('Atualizar visualização', 'secret-provider-token', '00011122233', '<script');
    }
    $this->get(route($admin ? 'admin.insurance-analyses.lead' : 'insurance-analyses.lead', $this->lead))->assertOk()->assertDontSee('Atualizar visualização');
})->with(['company', 'admin']);

/**
 * Consulta de preços Porto gravada em payment, no formato real do GET de
 * ofertas: Fiança selecionada (ou sem preço) e Essenciais como alternativas.
 */
function portoViewPayment(bool $fiancaPriced = true): array
{
    $condition = fn (float $amount, float $total, float $net, float $iof): array => [
        'condicaoPagamento' => '029', 'quantidadeParcela' => 29, 'valorParcela' => $amount,
        'primeiroVencimento' => '10/12/2026', 'premioLiquido' => $net, 'iof' => $iof, 'premioTotal' => $total,
    ];
    $priced = fn (string $code, string $name, array $details): array => [
        'amount' => number_format($details['valorParcela'], 2, '.', ''), 'count' => 29, 'payment_type' => 'FAT', 'with_entry' => false,
        'offer_code' => $code, 'offer_name' => $name, 'payment_method' => 'FAT', 'payment_method_name' => 'Fatura sem entrada',
        'condition_code' => '029', 'details' => $details,
    ];
    $offer = fn (string $code, string $name, float $lmg, array $coverages, array $benefits = []): array => [
        'seguro' => $code, 'nomeSeguro' => $name, 'comissao' => ['codComissao' => $code === '0008' ? 15 : 10],
        'calculo' => ['valorLMG' => $lmg], 'beneficios' => $benefits,
        'coberturas' => array_map(fn (array $item): array => array_combine(['codCobertura', 'nomeCobertura', 'valorIS', 'valorLMI', 'valorPremio'], $item), $coverages),
    ];

    return [
        'source' => 'offers',
        'commission' => ['0008' => ['applied' => $fiancaPriced, 'http_status' => $fiancaPriced ? 200 : 400, 'error' => null, 'requested' => 15, 'returned' => 15]],
        'response' => ['orcamento' => '000000011912710', 'ofertas' => [
            $offer('0008', 'FIANÇA', 64679.7, [['001', 'Aluguel', 1400, 42000, 6051.76], ['010', 'MULTA RESCISÓRIA', 1400, 4200, 1106.48]],
                [['codigo' => '003', 'titulo' => 'Serviços para residência']]),
            $offer('0001', 'ESSENCIAL 12', 16800, [['001', 'Aluguel', 1400, 16800, 3474.18]]),
            $offer('0007', 'ESSENCIAL 20', 28000, [['001', 'Aluguel', 1400, 28000, 4250.98]]),
        ]],
        'selection' => $fiancaPriced ? $priced('0008', 'FIANÇA', $condition(617.46, 17906.48, 16675.81, 1230.67)) : null,
        // Ordem gravada por código; a tela ordena as alternativas pelo preço.
        'alternatives' => [
            $priced('0007', 'ESSENCIAL 20', $condition(173.79, 5040.0, 4693.61, 346.39)),
            $priced('0001', 'ESSENCIAL 12', $condition(144.83, 4200.0, 3911.34, 288.66)),
        ],
        'unavailable_reason' => $fiancaPriced ? null : 'A Porto não confirmou a comissão da oferta 0008 (HTTP 400).',
    ];
}

/**
 * Pacote Porto concluído com um cenário aprovado e a consulta de preços gravada.
 * $current usa o formato gravado desde 11/11/2026 (selection = plano mais barato).
 */
function portoViewQuote($test, bool $fiancaPriced = true, bool $current = false, ?array $paymentOverride = null): \DOMXPath
{
    config(['services.porto.enabled' => true]);
    $payment = $paymentOverride ?? ($current ? portoCurrentPayment($fiancaPriced) : portoViewPayment($fiancaPriced));
    $amount = $payment['selection']['amount'] ?? null;
    $batch = $test->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 1, 'finished_at' => now()]);
    $analysis = $batch->analyses()->create([
        'lead_id' => $test->lead->id, 'provider' => 'porto', 'product' => 'fianca_locaticia_residencial', 'status' => 'approved',
        'lease_start_date' => '2026-11-11', 'lease_end_date' => '2029-05-11',
    ]);
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'porto-display']]);
    $run = app(\App\Services\Insurance\InsuranceQuoteCoordinator::class)->prepare($analysis, 'porto-display', false);
    $quote = $run->scenarios()->first()->quotes()->first();
    $quote->update([
        'status' => 'approved', 'quote_id' => '000000011912710', 'payment_type' => 'FAT',
        'payment_status' => $amount !== null ? 'ready' : 'unavailable', 'installment_amount' => $amount, 'installments' => 29,
        'lease_start_date' => '2026-11-11', 'lease_end_date' => '2029-05-11',
        'response_payload' => ['http_status' => 201, 'response' => ['orcamento' => '000000011912710', 'secret' => 'secret-response'],
            'payment' => $payment],
    ]);
    $run->update(['status' => 'approved', 'selected_quote_id' => $amount !== null ? $quote->id : null]);
    if ($current) {
        // O coordenador consolida na análise a consulta e a parcela escolhida.
        $analysis->update(['response_payload' => ['payment' => $payment, 'quote_selection' => [
            'provider_run_id' => $run->id, 'quote_id' => $quote->id, 'installment_amount' => $amount, 'installments' => 29, 'payment_type' => 'FAT',
        ]]]);
    }
    $response = $test->actingAs($test->user)->withSession(['2fa_passed' => true])
        ->get(route('insurance-analyses.lead', $test->lead))->assertOk()->assertDontSee('secret-response');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

    return new DOMXPath($document);
}

it('shows real Porto entry prices for each offer without changing the selected price', function (bool $list) {
    $payment = portoCurrentPayment();
    foreach ($payment['response']['ofertas'] as &$offer) {
        $condition = ['condicaoPagamento' => '229', 'quantidadeParcela' => 29, 'valorParcela' => 222.22];
        $offer['formasPagamento'] = [['formaPagamento' => 'BFT', 'condicoesPagamento' => $list ? [$condition] : $condition]];
    }
    unset($offer);
    $xpath = portoViewQuote($this, current: true, paymentOverride: $payment);
    $options = $xpath->query('//dialog[@id="details-porto"]//*[@data-porto-entry-options]');
    expect($options->length)->toBe(3);
    foreach ($options as $option) {
        expect($option->textContent)->toContain('Sem entrada', 'Com entrada', 'R$ 222,22', '29 parcelas', '1ª em boleto, demais em fatura');
    }
    $card = $xpath->query('//article[@data-provider="porto"]')->item(0);
    expect($card->textContent)->toContain('R$ 144,83', 'R$ 617,46')->not->toContain('222,22');
})->with([false, true]);

it('does not invent Porto entry prices when BFT is missing or invalid', function (?float $amount) {
    $payment = portoCurrentPayment();
    foreach ($payment['response']['ofertas'] as &$offer) {
        $offer['formasPagamento'] = $amount === null ? [] : [['formaPagamento' => 'BFT', 'condicoesPagamento' => [
            'condicaoPagamento' => '229', 'quantidadeParcela' => 29, 'valorParcela' => $amount,
        ]]];
    }
    unset($offer);
    $xpath = portoViewQuote($this, current: true, paymentOverride: $payment);
    $options = $xpath->query('//dialog[@id="details-porto"]//*[@data-entry-option="with_entry"]');
    expect($options->length)->toBe(3);
    foreach ($options as $option) {
        expect($option->textContent)->toContain('Condição não disponibilizada pela seguradora.')->not->toContain('R$');
    }
})->with([null, 0.0, -1.0]);

it('does not expose Porto invoice entry options for a credit card selection', function () {
    $payment = portoCurrentPayment();
    foreach (['selection', 'fianca', 'essential'] as $key) {
        $payment[$key]['payment_method'] = 'CC';
        $payment[$key]['payment_type'] = 'CC';
    }
    foreach ($payment['alternatives'] as &$alternative) {
        $alternative['payment_method'] = 'CC';
        $alternative['payment_type'] = 'CC';
    }
    unset($alternative);
    $xpath = portoViewQuote($this, current: true, paymentOverride: $payment);
    expect($xpath->query('//dialog[@id="details-porto"]//*[@data-porto-entry-options]')->length)->toBe(0);
});

it('shows the Porto Fiança and the cheapest Essencial on the card and every offer in the details', function () {
    // Registro anterior à regra do mais barato: a Fiança é o plano comparado.
    $xpath = portoViewQuote($this);

    $card = $xpath->query('//article[@data-provider="porto"]')->item(0);
    $plans = $xpath->query('.//li[@data-porto-plan]', $card);
    expect($plans->length)->toBe(2)
        ->and($plans->item(0)->getAttribute('data-porto-plan'))->toBe('0008')
        ->and($plans->item(0)->textContent)->toContain('Fiança', 'R$ 617,46', 'Por parcela · 29 parcelas')->not->toContain('Mais barato')
        ->and($plans->item(1)->getAttribute('data-porto-plan'))->toBe('0001')
        ->and($plans->item(1)->textContent)->toContain('Essencial 12', 'R$ 144,83', 'Mais barato');
    expect($card->textContent)->not->toContain('Essencial 20', 'Fiança locatícia');

    $details = $xpath->query('//dialog[@id="details-porto"]')->item(0);
    $titles = $xpath->query('.//section//h3', $details);
    expect($titles->length)->toBe(3)
        ->and($titles->item(0)->textContent)->toBe('Fiança')
        ->and($titles->item(1)->textContent)->toBe('Essencial 12')
        ->and($titles->item(2)->textContent)->toBe('Essencial 20');

    $sections = $xpath->query('.//section', $details);
    expect($sections->item(0)->textContent)->toContain(
        'Exibido no card', '29 parcelas de R$ 617,46', 'Fatura sem entrada', 'Primeiro vencimento', '10/12/2026',
        '11/11/2026 até 11/05/2029', 'R$ 17.906,48', 'Prêmio líquido', 'R$ 16.675,81', 'IOF', 'R$ 1.230,67',
        'Limite máximo de garantia', 'R$ 64.679,70', 'Aluguel', 'Limite de cobertura: R$ 42.000,00', 'Valor declarado: R$ 1.400,00',
        'Custo da cobertura: R$ 6.051,76', 'Multa rescisória', 'Benefícios', 'Serviços para residência',
    )->not->toContain('Alternativa', 'MULTA RESCISÓRIA', 'Não informado');
    expect($sections->item(1)->textContent)->toContain('Exibido no card', '29 parcelas de R$ 144,83', 'Fatura sem entrada',
        'R$ 4.200,00', 'R$ 3.911,34', 'R$ 288,66', 'R$ 16.800,00', 'Custo da cobertura: R$ 3.474,18')
        ->not->toContain('Alternativa', 'Mais barato', 'Benefícios');
    expect($sections->item(2)->textContent)->toContain('Alternativa', 'R$ 173,79', 'R$ 5.040,00')
        ->not->toContain('Exibido no card', 'Mais barato');
});

it('keeps the Porto Fiança on the card with a notice when it has no confirmed price', function () {
    $xpath = portoViewQuote($this, fiancaPriced: false);

    $card = $xpath->query('//article[@data-provider="porto"]')->item(0);
    $plans = $xpath->query('.//li[@data-porto-plan]', $card);
    expect($plans->length)->toBe(2)
        ->and($plans->item(0)->textContent)->toContain('Fiança', 'Preço indisponível no momento.')->not->toContain('R$', 'HTTP 400')
        ->and($plans->item(1)->textContent)->toContain('Essencial 12', 'R$ 144,83');

    $sections = $xpath->query('//dialog[@id="details-porto"]//section');
    expect($sections->length)->toBe(3);
    // A Fiança aparece sem preço e sem custo das coberturas calculado com 10%.
    expect($sections->item(0)->textContent)->toContain('Fiança', 'Exibido no card', 'Aprovada. Preço indisponível.', 'Limite de cobertura: R$ 42.000,00')
        ->not->toContain('R$ 617,46', 'Custo da cobertura', 'Prêmio líquido');
    expect($sections->item(1)->textContent)->toContain('Essencial 12', 'Exibido no card', '29 parcelas de R$ 144,83')->not->toContain('Alternativa');
});

it('marks the cheapest Porto plan on the card and names it in the best offers', function (bool $fiancaPriced) {
    $xpath = portoViewQuote($this, $fiancaPriced, current: true);

    $plans = $xpath->query('//article[@data-provider="porto"]//li[@data-porto-plan]');
    expect($plans->length)->toBe(2)
        ->and($plans->item(0)->textContent)->toContain('Fiança', $fiancaPriced ? 'R$ 617,46' : 'Preço indisponível no momento.')->not->toContain('Mais barato')
        ->and($plans->item(1)->textContent)->toContain('Essencial 12', 'Mais barato', 'R$ 144,83');

    // Card superior: a Porto entra na comparação pelo Essencial 12.
    $comparison = $xpath->query('//section[@data-analysis-component="comparison"]')->item(0);
    expect($xpath->query('.//*[@data-comparison-plan]', $comparison)->item(0)->textContent)->toBe('Essencial 12');
    expect($comparison->textContent)->toContain('Porto Seguro', 'R$ 144,83')->not->toContain('R$ 617,46');

    $sections = $xpath->query('//dialog[@id="details-porto"]//section');
    expect($sections->item(0)->textContent)->toContain('Exibido no card')->not->toContain('Mais barato')
        ->and($sections->item(1)->textContent)->toContain('Essencial 12', 'Mais barato', 'Exibido no card');
})->with(['Fiança com preço' => true, 'Fiança sem preço' => false]);

/** Análise Porto consolidada: o coordenador guarda payment e quote_selection. */
function portoSummaryAnalysis($test, string $status, array $payload): \App\Models\InsuranceAnalysis
{
    return \App\Models\InsuranceAnalysis::query()->create([
        'lead_id' => $test->lead->id, 'provider' => 'porto', 'product' => 'fianca_locaticia_residencial', 'status' => $status,
        'result' => $status === 'approved' ? 'approved' : null, 'quote_id' => '000000011912710',
        'lease_start_date' => '2026-11-11', 'lease_end_date' => '2029-05-11', 'response_payload' => $payload,
    ]);
}

it('summarizes the Porto Fiança and its alternatives while keeping the comparison keys', function () {
    $analysis = portoSummaryAnalysis($this, 'approved', [
        'payment' => portoViewPayment(),
        'quote_selection' => ['provider_run_id' => 1, 'quote_id' => 2, 'installment_amount' => '617.46', 'installments' => 29, 'payment_type' => 'FAT'],
    ]);

    $summary = app(\App\Services\Insurance\AnalysisQuoteSummary::class)->summarize($analysis);

    expect($summary['price'])->toMatchArray([
        'total' => '4200.00', 'basis' => 'gross_total', 'currency' => 'BRL',
        'period_start' => '2026-11-11', 'period_end' => '2029-05-11',
        'comparison_basis' => 'installment', 'installment_amount' => '144.83', 'installments' => 29, 'payment_type' => 'FAT',
        'net' => '3911.34', 'iof' => '288.66', 'first_due' => '2026-12-10', 'guarantee_limit' => '16800.00',
        'payment_label' => 'Fatura sem entrada',
    ]);
    expect(array_column($summary['offers'], 'role', 'code'))->toBe(['0008' => 'comparison', '0001' => 'alternative', '0007' => 'alternative']);
    expect(array_column($summary['coverages'], 'name'))->toBe(['Aluguel']);
    expect($summary['coverages'][0])->toMatchArray(['insured_amount' => '16800.00', 'declared_amount' => '1400.00', 'premium' => '3474.18']);

    // A comparação entre companhias continua pela parcela da Fiança.
    $comparison = app(\App\Services\Insurance\AnalysisQuoteComparison::class)->compare(collect([$summary]));
    expect($comparison['comparison_issue'])->toBeNull();
    expect($comparison['best_quote']['price']['installment_amount'])->toBe('144.83');
});

it('shows no Porto offer or price for a refused or technically failed analysis', function (string $status) {
    $summary = app(\App\Services\Insurance\AnalysisQuoteSummary::class)
        ->summarize(portoSummaryAnalysis($this, $status, ['payment' => portoViewPayment()]));

    expect($summary['offers'])->toBe([])
        ->and($summary['coverages'])->toBe([])
        ->and($summary['price']['total'])->toBeNull()
        ->and($summary['price']['comparison_basis'])->toBe('gross_total');
})->with(['rejected', 'failed']);

/**
 * Formato gravado desde 11/11/2026: "fianca" e "essential" alimentam o card e
 * "selection" é o mais barato dos dois (preço da Porto na comparação).
 */
function portoCurrentPayment(bool $fiancaPriced = true): array
{
    $payment = portoViewPayment($fiancaPriced);
    $essential = collect($payment['alternatives'])->sortBy(fn (array $item): float => (float) $item['amount'])->first();

    return array_merge($payment, [
        'fianca' => $payment['selection'], 'essential' => $essential, 'selection' => $essential,
        'unavailable_reason' => null, 'fianca_unavailable_reason' => $payment['unavailable_reason'],
    ]);
}

it('summarizes the Porto card plans and the cheapest plan used in the comparison', function () {
    $analysis = portoSummaryAnalysis($this, 'approved', [
        'payment' => portoCurrentPayment(),
        'quote_selection' => ['provider_run_id' => 1, 'quote_id' => 2, 'installment_amount' => '144.83', 'installments' => 29, 'payment_type' => 'FAT'],
    ]);

    $summary = app(\App\Services\Insurance\AnalysisQuoteSummary::class)->summarize($analysis);

    // 1) Card: Fiança e o Essencial mais barato lado a lado.
    expect($summary['card']['fianca'])->toMatchArray(['code' => '0008', 'name' => 'Fiança', 'installment_amount' => '617.46', 'best' => false]);
    expect($summary['card']['essential'])->toMatchArray(['code' => '0001', 'name' => 'Essencial 12', 'installment_amount' => '144.83', 'cheapest_essential' => true, 'best' => true]);
    // 2) Comparação: o mais barato entre todos os planos aprovados resume a análise.
    expect($summary['best_offer'])->toBe(['code' => '0001', 'name' => 'Essencial 12', 'role' => 'alternative', 'installment_amount' => '144.83', 'installments' => 29]);
    expect($summary['price'])->toMatchArray(['comparison_basis' => 'installment', 'installment_amount' => '144.83', 'total' => '4200.00', 'net' => '3911.34']);
    expect($summary['fianca_issue'])->toBeNull();
    expect(array_column($summary['offers'], 'best', 'code'))->toBe(['0008' => false, '0001' => true, '0007' => false]);
    expect(array_column($summary['offers'], 'cheapest_essential', 'code'))->toBe(['0008' => false, '0001' => true, '0007' => false]);

    $comparison = app(\App\Services\Insurance\AnalysisQuoteComparison::class)->compare(collect([$summary]));
    expect($comparison['best_quote']['price']['installment_amount'])->toBe('144.83');
});

it('keeps the Fiança on the Porto card without price while the cheapest Essencial is compared', function () {
    $summary = app(\App\Services\Insurance\AnalysisQuoteSummary::class)->summarize(portoSummaryAnalysis($this, 'approved', [
        'payment' => portoCurrentPayment(fiancaPriced: false),
        'quote_selection' => ['installment_amount' => '144.83', 'installments' => 29, 'payment_type' => 'FAT'],
    ]));

    expect($summary['card']['fianca'])->toMatchArray(['code' => '0008', 'installment_amount' => null, 'best' => false]);
    expect($summary['card']['essential']['code'])->toBe('0001');
    expect($summary['best_offer']['code'])->toBe('0001');
    expect($summary['fianca_issue'])->toContain('0008');
    expect($summary['price']['installment_amount'])->toBe('144.83');
});

it('derives the Porto card plans from records saved before the cheapest-plan rule', function () {
    // Formato anterior: "selection" era a Fiança e não havia "fianca"/"essential".
    $summary = app(\App\Services\Insurance\AnalysisQuoteSummary::class)->summarize(portoSummaryAnalysis($this, 'approved', [
        'payment' => portoViewPayment(),
        'quote_selection' => ['installment_amount' => '617.46', 'installments' => 29, 'payment_type' => 'FAT'],
    ]));

    expect($summary['card']['fianca']['installment_amount'])->toBe('617.46');
    expect($summary['card']['essential']['code'])->toBe('0001');
    expect($summary['best_offer']['code'])->toBe('0001');
});
