<?php

use App\Jobs\RunProviderAnalysisJob;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysisRound;
use App\Models\InsuranceQuote;
use App\Models\Lead;
use App\Models\User;
use App\Services\Insurance\LeadReanalysisService;
use App\Support\CorretorPermissions;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    Bus::fake();
    Http::preventStrayRequests();
    config(['features.insurance_analysis.enabled' => true]);
    $this->company = Imobiliaria::factory()->create();
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
    $this->lead = Lead::query()->create(['nome' => 'Nome preservado', 'cpf' => '52998224725', 'email' => 'expenses@example.test', 'company_id' => $this->company->id]);
    $this->lead->despesas()->create(['valor_aluguel' => 1000, 'valor_agua' => 30, 'valor_total_encargos' => 1030]);
    $this->batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 4]);
    foreach (['pottencial' => 'approved', 'too' => 'manual_review', 'tokio' => 'rejected', 'failed_provider' => 'failed'] as $provider => $status) {
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

it('updates only expenses and schedules every finished insurer, including failures, in one new round', function (bool $admin) {
    $actor = $this->user;
    if ($admin) {
        $actor = Corretor::query()->create(['name' => 'Corretor', 'email' => 'expenses-broker@example.test', 'password' => 'password', 'active' => true, 'role' => Corretor::ROLE_INTEGRANTE, 'first_login_verified_at' => now(), 'permissions' => [CorretorPermissions::VIEW_LEADS, CorretorPermissions::VIEW_ANALYSIS, CorretorPermissions::CREATE_ANALYSIS, CorretorPermissions::EDIT_LEADS]]);
    }
    $prefix = $admin ? 'admin.insurance-analyses.' : 'insurance-analyses.';
    $this->actingAs($actor, $admin ? 'admin' : 'web')->withSession(['2fa_passed' => true]);
    $url = route($prefix.'expense-reanalysis', $this->lead);
    $this->put($url, $this->payload + ['nome' => 'Não alterar', 'cpf' => '123', 'valor_total_encargos' => 1])
        ->assertRedirect(route($prefix.'lead', $this->lead))->assertSessionHasNoErrors();
    $lead = $this->lead->fresh('despesas');
    expect($lead->nome)->toBe('Nome preservado')->and($lead->cpf)->toBe('52998224725')
        ->and((float) $lead->despesas->valor_total_encargos)->toBe(750.0)
        ->and((float) $lead->despesas->valor_agua)->toBe(0.0)
        ->and($this->batch->analyses()->where('status', 'pending')->count())->toBe(3)
        ->and($this->batch->analyses()->where('status', 'manual_review')->count())->toBe(1)
        ->and(InsuranceAnalysisRound::count())->toBe(1)
        ->and((float) data_get(InsuranceAnalysisRound::first()->lead_snapshot, 'relations.despesas.valor_aluguel'))->toBe(700.0);
    $failedId = $this->batch->analyses()->where('provider', 'failed_provider')->value('id');
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 3
        && $batch->jobs->every(fn ($job) => $job instanceof RunProviderAnalysisJob && $job->isReanalysis === ($job->analysisId !== $failedId)));
    $this->put($url, $this->payload)->assertSessionHasErrors(['expenses'], null, 'expenseReanalysis');
    expect(InsuranceAnalysisRound::count())->toBe(1);
})->with([false, true]);

it('rejects invalid amounts without changing expenses or creating jobs', function () {
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->put(route('insurance-analyses.expense-reanalysis', $this->lead), array_replace($this->payload, ['valor_aluguel' => '-1']))
        ->assertSessionHasErrors(['valor_aluguel'], null, 'expenseReanalysis');
    expect((float) $this->lead->despesas()->first()->valor_aluguel)->toBe(1000.0)->and(InsuranceQuote::count())->toBe(0);
    Bus::assertNothingBatched();
});

it('does not save expenses while every insurer is still processing', function () {
    $this->batch->analyses()->update(['status' => 'processing']);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->put(route('insurance-analyses.expense-reanalysis', $this->lead), $this->payload)
        ->assertSessionHasErrors(['expenses'], null, 'expenseReanalysis');
    expect((float) $this->lead->despesas()->first()->valor_aluguel)->toBe(1000.0);
    Bus::assertNothingBatched();
});

it('denies access to a lead owned by another company', function () {
    $this->lead->update(['company_id' => Imobiliaria::factory()->create()->id]);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->put(route('insurance-analyses.expense-reanalysis', $this->lead), $this->payload)->assertForbidden();
    Bus::assertNothingBatched();
});

it('renders an expense only modal with saved values and displays validation errors', function () {
    $this->actingAs($this->user)->withSession(['2fa_passed' => true]);
    $page = route('insurance-analyses.lead', $this->lead);
    $response = $this->get($page)->assertOk()->assertDontSee('Editar dados — em breve')->assertSee('Salvar e reanalisar');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $modal = $xpath->query('//dialog[@id="expense-reanalysis"]')->item(0);
    expect($modal)->not->toBeNull()->and($modal->hasAttribute('open'))->toBeFalse();
    $names = [];
    foreach ($xpath->query('.//input[@name]', $modal) as $input) {
        $names[] = $input->getAttribute('name');
    }
    expect($names)->toEqualCanonicalizing(array_merge(LeadReanalysisService::EXPENSE_FIELDS, ['_token', '_method', 'revision', 'reanalysis_mode', 'reanalysis_mode']))
        ->and($xpath->query('.//input[@name="valor_aluguel"]', $modal)->item(0)->getAttribute('value'))->toBe('1000.00')
        ->and($xpath->query('.//input[@name="valor_agua"]', $modal)->item(0)->getAttribute('value'))->toBe('30.00');
    $this->from($page)->put(route('insurance-analyses.expense-reanalysis', $this->lead), array_replace($this->payload, ['valor_aluguel' => 0]))->assertRedirect($page);
    $this->get($page)->assertOk()->assertSee('data-open-on-load', false)->assertSee('O valor de aluguel deve ser no mínimo');
    Bus::assertNothingBatched();
});

it('reanalyzes only the chosen insurer with the informed expenses', function () {
    $failed = $this->batch->analyses()->where('provider', 'failed_provider')->first();
    $expenses = collect($this->payload)->except('revision')->all();
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->postJson(route('insurance-analyses.provider-expense-reanalysis', $failed), $expenses)
        ->assertStatus(202)->assertJsonPath('analysis_id', $failed->id);
    expect($failed->fresh()->status)->toBe('pending')
        ->and($this->batch->analyses()->where('status', 'pending')->count())->toBe(1)
        ->and((float) $this->lead->despesas()->first()->valor_aluguel)->toBe(700.0);
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1
        && $batch->jobs->first()->analysisId === $failed->id && $batch->jobs->first()->isReanalysis === false);
});

it('refuses a per insurer reanalysis that is unchanged or still processing', function (string $provider, array $override, string $message) {
    $analysis = $this->batch->analyses()->where('provider', $provider)->first();
    $expenses = array_replace(collect($this->payload)->except('revision')->all(), $override);
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->postJson(route('insurance-analyses.provider-expense-reanalysis', $analysis), $expenses)
        ->assertUnprocessable()->assertJsonPath('message', fn (string $text): bool => str_contains($text, $message));
    expect((float) $this->lead->despesas()->first()->valor_aluguel)->toBe(1000.0);
    Bus::assertNothingBatched();
})->with([
    'sem alteração' => ['tokio', ['valor_aluguel' => '1000.00', 'valor_condominio' => '0.00', 'valor_agua' => '30.00'], 'Altere pelo menos uma despesa'],
    'em andamento' => ['too', [], 'ainda está em análise'],
]);

it('requires an actual expense change before creating another round', function () {
    $payload = $this->payload;
    $payload['valor_aluguel'] = '1000.00';
    $payload['valor_condominio'] = '0.00';
    $payload['valor_agua'] = '30.00';
    $this->actingAs($this->user)->withSession(['2fa_passed' => true])
        ->put(route('insurance-analyses.expense-reanalysis', $this->lead), $payload)
        ->assertSessionHasErrors(['expenses'], null, 'expenseReanalysis');
    expect(InsuranceAnalysisRound::count())->toBe(0);
    Bus::assertNothingBatched();
});

it('submits separate edits only to the save endpoint and discards cancelled edits', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createReanalysisModes } from './resources/js/insurance-analysis-reanalysis.js';
const listeners = new Map();
const fields = [{ name: 'valor_aluguel', value: '1000.00' }];
const revision = { value: 'before' };
const submit = { setAttribute() {} };
const selector = {};
const help = {};
const error = { classList: { toggle() {} } };
const provider = { dataset: { providerReanalysis: 'tokio', providerName: 'Tokio', url: '/provider' }, setAttribute() {}, querySelector() { return null; } };
const form = {
    dataset: { saveUrl: '/save' },
    reportValidity: () => true,
    querySelectorAll: () => fields,
    querySelector: key => ({ '[type="submit"]': submit, '[name="revision"]': revision, '[data-reanalysis-mode-selector]': selector, '[data-reanalysis-mode-help]': help }[key]),
    dispatchEvent() {},
};
const modal = { open: true, close() { this.open = false; listeners.get('close')({ target: this }); } };
const title = {};
const description = {};
const root = {
    querySelector: key => ({ '[data-expense-reanalysis]': form, '[data-reanalysis-mode-error]': error }[key] ?? null),
    querySelectorAll: key => key === '[data-provider-reanalysis]' ? [provider] : [],
    getElementById: key => ({ 'expense-reanalysis': modal, 'expense-reanalysis-title': title, 'expense-reanalysis-description': description }[key] ?? null),
    addEventListener: (type, callback) => listeners.set(type, callback),
    dispatchEvent() {},
};
const requests = [];
let fail = false;
const modes = createReanalysisModes({ root, fetchJson: async (url, request) => {
    requests.push({ url, ...request });
    return { ok: !fail, json: async () => fail ? { errors: { valor_aluguel: ['Valor inválido'] } } : { expenses: { valor_aluguel: '700.00' }, revision: 'after', message: 'Salvo' } };
} });
listeners.get('change')({ target: { name: 'reanalysis_mode', value: 'separate' } });
assert.equal(modes.mode, 'separate');
assert.equal(submit.textContent, 'Salvar despesas');
assert.equal(selector.hidden, true);
modal.open = true;
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
assert.equal(modes.mode, 'separate');

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
assert.equal(fields[0].value, '-1');
modal.close();
assert.equal(fields[0].value, '700.00');

modes.setMode('general');
prevented = false;
listeners.get('submit')(event);
assert.equal(prevented, false);
assert.equal(submit.textContent, 'Salvar e reanalisar todas');
assert.equal(selector.hidden, false);
JS;
    $process = new \Symfony\Component\Process\Process(['node', '--input-type=module', '-e', $script], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
