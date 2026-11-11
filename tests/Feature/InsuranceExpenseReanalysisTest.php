<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysisRound;
use App\Models\Lead;
use App\Models\User;
use App\Services\Insurance\LeadReanalysisService;
use App\Support\CorretorPermissions;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutVite();
    Bus::fake();
    Http::preventStrayRequests();
    config(['features.insurance_analysis.enabled' => true, 'services.pottencial.enabled' => true,
        'services.too.enabled' => true, 'services.tokio.enabled' => true, 'services.porto.enabled' => true]);
    $this->company = Imobiliaria::factory()->create();
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
    $this->lead = Lead::query()->create(['nome' => 'Nome preservado', 'cpf' => '52998224725', 'email' => 'expenses@example.test', 'company_id' => $this->company->id]);
    $this->lead->despesas()->create(['valor_aluguel' => 1000, 'valor_agua' => 30, 'valor_total_encargos' => 1030]);
    $this->batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 4]);
    foreach (['pottencial' => 'approved', 'too' => 'rejected', 'tokio' => 'rejected', 'porto' => 'failed'] as $provider => $status) {
        $this->batch->analyses()->create(['lead_id' => $this->lead->id, 'provider' => $provider, 'product' => 'fianca_locaticia', 'status' => $status]);
    }
    $this->payload = array_fill_keys(LeadReanalysisService::EXPENSE_FIELDS, '0.00') + [
        'revision' => app(LeadReanalysisService::class)->expenseRevision($this->lead, $this->batch),
    ];
    $this->payload['valor_aluguel'] = '700.00';
    $this->payload['valor_condominio'] = '50.00';
});

afterEach(fn () => Http::assertNothingSent());

it('saves expenses without starting quotes or changing analysis results in either portal', function (bool $admin) {
    $actor = $admin
        ? Corretor::query()->create(['name' => 'Corretor', 'email' => 'save-broker@example.test', 'password' => 'password', 'active' => true, 'role' => Corretor::ROLE_INTEGRANTE, 'first_login_verified_at' => now(), 'permissions' => [CorretorPermissions::VIEW_LEADS, CorretorPermissions::VIEW_ANALYSIS, CorretorPermissions::CREATE_ANALYSIS, CorretorPermissions::EDIT_LEADS]])
        : $this->user;
    $prefix = $admin ? 'admin.insurance-analyses.' : 'insurance-analyses.';
    $before = $this->batch->analyses()->get()->toArray();
    $status = $this->lead->fresh()->status;
    $this->actingAs($actor, $admin ? 'admin' : 'web')->withSession(['2fa_passed' => true]);

    $response = $this->putJson(route($prefix.'save-expenses', $this->lead), $this->payload + ['nome' => 'Não alterar', 'valor_total_encargos' => 1])
        ->assertOk()->assertJsonPath('expenses.valor_aluguel', '700.00');

    expect($this->lead->fresh()->nome)->toBe('Nome preservado')
        ->and($this->lead->fresh()->status)->toBe($status)
        ->and((float) $this->lead->despesas()->first()->valor_total_encargos)->toBe(750.0)
        ->and((float) $this->lead->despesas()->first()->valor_agua)->toBe(0.0)
        ->and($this->batch->analyses()->get()->toArray())->toBe($before)
        ->and(InsuranceAnalysisRound::count())->toBe(0)
        ->and($response->json('revision'))->not->toBe($this->payload['revision']);
    Bus::assertNothingDispatched();
    Bus::assertNothingBatched();

    $page = $this->get(route($prefix.'lead', $this->lead))->assertOk();
    $page->assertSee('data-save-url="'.route($prefix.'save-expenses', $this->lead).'"', false);
})->with([false, true]);

it('uses saved expenses only when the selected insurer is subsequently requested', function () {
    $analysis = $this->batch->analyses()->where('provider', 'tokio')->first();
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'before-expense-save']]);
    $run = app(\App\Services\Insurance\InsuranceQuoteCoordinator::class)->prepare($analysis, 'before-expense-save', false);
    $this->payload['revision'] = app(LeadReanalysisService::class)->expenseRevision($this->lead->fresh(), $this->batch->fresh());
    $this->actingAs($this->user)->withSession(['2fa_passed' => true]);
    $response = $this->putJson(route('insurance-analyses.save-expenses', $this->lead), $this->payload)->assertOk();
    expect(InsuranceAnalysisRound::count())->toBe(1)
        ->and((float) data_get($run->round->lead_snapshot, 'relations.despesas.valor_aluguel'))->toBe(1000.0);
    Bus::assertNothingBatched();

    $this->postJson(route('insurance-analyses.provider-expense-reanalysis', $analysis), $response->json('expenses'))->assertAccepted();
    expect($analysis->fresh()->status)->toBe('pending')
        ->and($this->batch->analyses()->where('status', 'pending')->count())->toBe(1)
        ->and((float) data_get(InsuranceAnalysisRound::latest('id')->first()->lead_snapshot, 'relations.despesas.valor_aluguel'))->toBe(700.0);
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1 && $batch->jobs->first()->analysisId === $analysis->id);
});

it('rejects invalid or stale expense saves without scheduling work', function (array $override, string $error) {
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->putJson(route('insurance-analyses.save-expenses', $this->lead), array_replace($this->payload, $override))
        ->assertUnprocessable()->assertSee($error);
    expect((float) $this->lead->despesas()->first()->valor_aluguel)->toBe(1000.0);
    Bus::assertNothingDispatched();
    Bus::assertNothingBatched();
})->with([
    'invalid' => [['valor_aluguel' => '-1'], 'valor_aluguel'],
    'stale' => [['revision' => str_repeat('0', 64)], 'message'],
]);

it('denies saving expenses belonging to another company', function () {
    $this->lead->update(['company_id' => Imobiliaria::factory()->create()->id]);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->putJson(route('insurance-analyses.save-expenses', $this->lead), $this->payload)->assertForbidden();
    expect((float) $this->lead->despesas()->first()->valor_aluguel)->toBe(1000.0);
    Bus::assertNothingBatched();
});

it('no longer exposes the general expense reanalysis of the whole lead', function (bool $admin) {
    $actor = $admin
        ? Corretor::query()->create(['name' => 'Corretor', 'email' => 'general-broker@example.test', 'password' => 'password', 'active' => true, 'role' => Corretor::ROLE_INTEGRANTE, 'first_login_verified_at' => now(), 'permissions' => [CorretorPermissions::VIEW_LEADS, CorretorPermissions::VIEW_ANALYSIS, CorretorPermissions::CREATE_ANALYSIS, CorretorPermissions::EDIT_LEADS]])
        : $this->user;
    $prefix = $admin ? 'admin.insurance-analyses.' : 'insurance-analyses.';
    $this->actingAs($actor, $admin ? 'admin' : 'web')->withSession(['2fa_passed' => true]);

    expect(Route::has($prefix.'expense-reanalysis'))->toBeFalse();
    $this->put(($admin ? '/Dashboard/Admin' : '/Dashboard').'/leads/'.$this->lead->id.'/analises/despesas', $this->payload)->assertNotFound();
    $this->getJson(route($prefix.'data', $this->lead))->assertOk()
        ->assertJsonMissingPath('data.actions.expense_reanalysis')
        ->assertJsonMissingPath('data.actions.reanalysis');

    expect((float) $this->lead->despesas()->first()->valor_aluguel)->toBe(1000.0)
        ->and(InsuranceAnalysisRound::count())->toBe(0);
    Bus::assertNothingBatched();
    Bus::assertNothingDispatched();
})->with([false, true]);

it('marks only Porto and Tokio as reanalyzable on the analysis page', function () {
    $this->actingAs($this->user)->withSession(['2fa_passed' => true]);

    $analyses = collect($this->getJson(route('insurance-analyses.data', $this->lead))->assertOk()->json('data.analyses'))->keyBy('provider');

    foreach (['pottencial' => false, 'too' => false, 'tokio' => true, 'porto' => true] as $provider => $supported) {
        expect($analyses[$provider]['reanalysis_supported'])->toBe($supported)
            ->and($analyses[$provider]['actions']['expense_reanalysis']['available'])->toBe($supported);
    }
    // Pottencial aprovada e Too recusada: nem a reanálise com dados alterados fica disponível.
    expect($analyses['pottencial']['actions']['reanalysis']['available'])->toBeFalse()
        ->and($analyses['too']['actions']['reanalysis']['available'])->toBeFalse()
        ->and($analyses['tokio']['actions']['reanalysis']['available'])->toBeTrue();
});

it('renders an expense only modal that saves without starting quotes', function () {
    $this->actingAs($this->user)->withSession(['2fa_passed' => true]);
    $page = route('insurance-analyses.lead', $this->lead);
    $response = $this->get($page)->assertOk()->assertSee('Salvar despesas')
        ->assertDontSee('Salvar e reanalisar')->assertDontSee('reanalysis_mode');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $modal = $xpath->query('//dialog[@id="expense-reanalysis"]')->item(0);
    expect($modal)->not->toBeNull()->and($modal->hasAttribute('open'))->toBeFalse();
    $form = $xpath->query('.//form[@data-expense-reanalysis]', $modal)->item(0);
    expect($form->getAttribute('action'))->toBe(route('insurance-analyses.save-expenses', $this->lead))
        ->and($form->getAttribute('data-save-url'))->toBe(route('insurance-analyses.save-expenses', $this->lead));
    $names = [];
    foreach ($xpath->query('.//input[@name]', $modal) as $input) {
        $names[] = $input->getAttribute('name');
    }
    expect($names)->toEqualCanonicalizing(array_merge(LeadReanalysisService::EXPENSE_FIELDS, ['_token', '_method', 'revision']))
        ->and($xpath->query('.//input[@name="valor_aluguel"]', $modal)->item(0)->getAttribute('value'))->toBe('1000.00')
        ->and($xpath->query('.//input[@name="valor_agua"]', $modal)->item(0)->getAttribute('value'))->toBe('30.00');
    Bus::assertNothingBatched();
});

it('reanalyzes only the chosen insurer with the informed expenses', function () {
    $porto = $this->batch->analyses()->where('provider', 'porto')->first();
    $expenses = collect($this->payload)->except('revision')->all();
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->postJson(route('insurance-analyses.provider-expense-reanalysis', $porto), $expenses)
        ->assertAccepted()->assertJsonPath('analysis_id', $porto->id);
    expect($porto->fresh()->status)->toBe('pending')
        ->and($this->batch->analyses()->where('status', 'pending')->count())->toBe(1)
        ->and((float) $this->lead->despesas()->first()->valor_aluguel)->toBe(700.0);
    // Porto em falha recebe nova análise; as demais companhias ficam intactas.
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1
        && $batch->jobs->first()->analysisId === $porto->id && $batch->jobs->first()->isReanalysis === false);
});

it('refuses a per insurer reanalysis that is blocked, unchanged or still processing', function (string $provider, ?string $status, array $override, string $message) {
    $analysis = $this->batch->analyses()->where('provider', $provider)->first();
    if ($status !== null) {
        $analysis->update(['status' => $status]);
    }
    $before = $analysis->fresh()->toArray();
    $expenses = array_replace(collect($this->payload)->except('revision')->all(), $override);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->postJson(route('insurance-analyses.provider-expense-reanalysis', $analysis), $expenses)
        ->assertUnprocessable()->assertJsonPath('message', fn (string $text): bool => str_contains($text, $message));
    expect((float) $this->lead->despesas()->first()->valor_aluguel)->toBe(1000.0)
        ->and($analysis->fresh()->toArray())->toBe($before)
        ->and(InsuranceAnalysisRound::count())->toBe(0);
    Bus::assertNothingBatched();
    Bus::assertNothingDispatched();
})->with([
    'Pottencial aprovada' => ['pottencial', null, [], 'A Pottencial não permite reanálise'],
    'Pottencial em falha' => ['pottencial', 'failed', [], 'A Pottencial não permite reanálise'],
    'Too recusada' => ['too', null, [], 'A Too Seguros não permite reanálise'],
    'sem alteração' => ['tokio', null, ['valor_aluguel' => '1000.00', 'valor_condominio' => '0.00', 'valor_agua' => '30.00'], 'Altere pelo menos uma despesa'],
    'em andamento' => ['porto', 'processing', [], 'ainda está em análise'],
]);

it('saves expenses and reanalyzes only the chosen card, without a general mode', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createReanalysisModes } from './resources/js/insurance-analysis-reanalysis.js';
const listeners = new Map();
const fields = [{ name: 'valor_aluguel', value: '1000.00' }];
const revision = { value: 'before' };
const submit = { setAttribute() {} };
const error = { classList: { toggle() {} } };
const provider = { dataset: { providerReanalysis: 'tokio', providerName: 'Tokio', url: '/provider' }, setAttribute() {}, querySelector() { return null; } };
const form = {
    dataset: { saveUrl: '/save' },
    reportValidity: () => true,
    querySelectorAll: () => fields,
    querySelector: key => ({ '[type="submit"]': submit, '[name="revision"]': revision }[key] ?? null),
    dispatchEvent() {},
};
const modal = { open: false, showModal() { this.open = true; }, close() { this.open = false; listeners.get('close')({ target: this }); } };
const root = {
    querySelector: key => ({ '[data-expense-reanalysis]': form, '[data-reanalysis-mode-error]': error }[key] ?? null),
    querySelectorAll: key => key === '[data-provider-reanalysis]' ? [provider] : [],
    getElementById: key => ({ 'expense-reanalysis': modal }[key] ?? null),
    addEventListener: (type, callback) => listeners.set(type, callback),
    dispatchEvent() {},
};
const requests = [];
let fail = false;
const modes = createReanalysisModes({ root, fetchJson: async (url, request) => {
    requests.push({ url, ...request });
    return { ok: !fail, json: async () => fail ? { errors: { valor_aluguel: ['Valor inválido'] } } : { expenses: { valor_aluguel: '700.00' }, revision: 'after', message: 'Salvo' } };
} });
assert.equal(modes.mode, 'separate');
assert.equal(submit.textContent, 'Salvar despesas');
assert.equal(listeners.has('change'), false);
modes.setMode('general');
assert.equal(modes.mode, 'separate');

// Sem despesas salvas, o card abre o diálogo e não pede cotação.
listeners.get('click')({ target: { closest: key => key === '[data-provider-reanalysis]' ? provider : null } });
assert.equal(modal.open, true);
assert.equal(requests.length, 0);

fields[0].value = '700.00';
let prevented = false;
const event = { target: form, preventDefault() { prevented = true; } };
listeners.get('submit')(event);
listeners.get('submit')(event);
await new Promise(resolve => setImmediate(resolve));
assert.equal(prevented, true);
assert.equal(requests.length, 1);
assert.equal(requests[0].url, '/save');
assert.equal(requests[0].method, 'PUT');
assert.deepEqual(JSON.parse(requests[0].body), { valor_aluguel: '700.00', revision: 'before' });
assert.equal(modal.open, false);
assert.equal(revision.value, 'after');

modal.open = true;
fields[0].value = '999.00';
modal.close();
assert.equal(fields[0].value, '700.00');
listeners.get('click')({ target: { closest: key => key === '[data-provider-reanalysis]' ? provider : null } });
await new Promise(resolve => setImmediate(resolve));
assert.equal(requests[1].url, '/provider');
assert.equal(JSON.parse(requests[1].body).valor_aluguel, '700.00');

modal.open = true;
fields[0].value = '-1';
fail = true;
listeners.get('submit')(event);
await new Promise(resolve => setImmediate(resolve));
assert.equal(error.textContent, 'Valor inválido');
assert.equal(modal.open, true);
assert.equal(submit.disabled, false);
modal.close();
assert.equal(fields[0].value, '700.00');
JS;
    $process = new \Symfony\Component\Process\Process(['node', '--input-type=module', '-e', $script], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('keeps the reanalysis provider list in sync with the provider contract', function () {
    $classes = [
        'pottencial' => \App\Services\Insurance\Providers\PottencialInsuranceProvider::class,
        'too' => \App\Services\Insurance\Providers\TooInsuranceProvider::class,
        'tokio' => \App\Services\Insurance\Providers\TokioInsuranceProvider::class,
        'porto' => \App\Services\Insurance\Providers\PortoInsuranceProvider::class,
    ];
    expect(array_keys($classes))->toEqualCanonicalizing(\App\Services\Insurance\Providers\InsuranceProviderResolver::PROVIDERS);

    foreach ($classes as $provider => $class) {
        expect(is_subclass_of($class, \App\Services\Insurance\Providers\ReanalyzesInsuranceQuotes::class))
            ->toBe(\App\Services\Insurance\Providers\InsuranceProviderResolver::supportsReanalysis($provider));
    }
    expect(\App\Services\Insurance\Providers\InsuranceProviderResolver::REANALYSIS_PROVIDERS)->toEqualCanonicalizing(['tokio', 'porto']);
});

it('removes the general reanalysis tab from both dashboards', function (bool $admin) {
    $actor = $admin
        ? Corretor::query()->create(['name' => 'Corretor', 'email' => 'tab-broker@example.test', 'password' => 'password', 'active' => true, 'role' => Corretor::ROLE_INTEGRANTE, 'first_login_verified_at' => now(), 'permissions' => [CorretorPermissions::VIEW_LEADS, CorretorPermissions::VIEW_ANALYSIS, CorretorPermissions::CREATE_ANALYSIS, CorretorPermissions::EDIT_LEADS]])
        : $this->user;
    $this->actingAs($actor, $admin ? 'admin' : 'web')->withSession(['2fa_passed' => true]);

    $this->get(route($admin ? 'Dashboard-Admin' : 'company.dashboard'))->assertOk()
        ->assertSee('Nome preservado')
        ->assertSee('eadUpdateForm'.$this->lead->id, false)
        ->assertDontSee('lead-reanalysis-pane-'.$this->lead->id, false)
        ->assertDontSee('Solicitar reanálise')
        ->assertDontSee('/leads/'.$this->lead->id.'/reanalisar', false);
})->with([false, true]);
