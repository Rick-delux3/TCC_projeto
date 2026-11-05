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

it('updates only expenses and schedules all nonfailed insurers in one new round', function (bool $admin) {
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
        ->and($this->batch->analyses()->where('status', 'failed')->count())->toBe(1)
        ->and(InsuranceAnalysisRound::count())->toBe(1)
        ->and(InsuranceQuote::count())->toBe(5)
        ->and((float) data_get(InsuranceAnalysisRound::first()->lead_snapshot, 'relations.despesas.valor_aluguel'))->toBe(700.0);
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 3 && $batch->jobs->every(fn ($job) => $job instanceof RunProviderAnalysisJob && $job->isReanalysis));
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

it('does not save expenses when no insurers are eligible', function () {
    $this->batch->analyses()->update(['status' => 'failed']);
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
    expect($names)->toEqualCanonicalizing(array_merge(LeadReanalysisService::EXPENSE_FIELDS, ['_token', '_method', 'revision']))
        ->and($xpath->query('.//input[@name="valor_aluguel"]', $modal)->item(0)->getAttribute('value'))->toBe('1000.00')
        ->and($xpath->query('.//input[@name="valor_agua"]', $modal)->item(0)->getAttribute('value'))->toBe('30.00');
    $this->from($page)->put(route('insurance-analyses.expense-reanalysis', $this->lead), array_replace($this->payload, ['valor_aluguel' => 0]))->assertRedirect($page);
    $this->get($page)->assertOk()->assertSee('data-open-on-load', false)->assertSee('O valor de aluguel deve ser no mínimo');
    Bus::assertNothingBatched();
});

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
