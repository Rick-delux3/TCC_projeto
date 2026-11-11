<?php

use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Models\User;
use App\Services\Insurance\LeadReanalysisService;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    Bus::fake();
    Http::preventStrayRequests();
    // Flags explícitas: o teste não depende do .env local.
    config([
        'features.insurance_analysis.enabled' => true,
        'services.pottencial.enabled' => false, 'services.too.enabled' => false,
        'services.tokio.enabled' => false, 'services.porto.enabled' => false,
    ]);
    $this->company = Imobiliaria::factory()->create();
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
    $this->lead = Lead::query()->create(['nome' => 'Lead bloqueio', 'email' => 'blocking@example.test', 'company_id' => $this->company->id]);
    $this->actingAs($this->user, 'web')->withSession(['2fa_passed' => true]);
});

afterEach(function () {
    Http::assertNothingSent();
});

function enableProviders(string ...$providers): void
{
    foreach (InsuranceProviderResolver::PROVIDERS as $provider) {
        config(["services.{$provider}.enabled" => in_array($provider, $providers, true)]);
    }
}

function blockingAnalysis(InsuranceAnalysisBatch $batch, string $provider, string $status, array $attributes = []): InsuranceAnalysis
{
    $analysis = $batch->analyses()->create(array_merge([
        'lead_id' => $batch->lead_id, 'company_id' => test()->company->id, 'provider' => $provider,
        'product' => 'fianca_locaticia', 'status' => $status,
    ], $attributes));
    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'round-'.$provider]]);

    return $analysis;
}

/** @return array<string, DOMElement> cards indexados pela seguradora */
function blockingCards(): array
{
    $html = test()->get(route('insurance-analyses.lead', test()->lead))->assertOk()->getContent();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($document);
    $cards = [];
    foreach ($xpath->query('//article[@data-provider]') as $card) {
        $cards[$card->getAttribute('data-provider')] = $card;
    }

    return $cards;
}

it('blocks exactly the providers disabled in the environment', function (array $enabled) {
    enableProviders(...$enabled);

    $cards = blockingCards();

    expect(array_keys($cards))->toEqualCanonicalizing(InsuranceProviderResolver::PROVIDERS);
    foreach ($cards as $provider => $card) {
        if (in_array($provider, $enabled, true)) {
            expect($card->getAttribute('data-analysis-state'))->not->toBe('blocked');
            expect($card->textContent)->not->toContain('Bloqueada')->toContain('Aguardando análise desta companhia.');
        } else {
            expect($card->getAttribute('data-analysis-state'))->toBe('blocked');
            expect($card->textContent)->toContain('Bloqueada')->toContain('Indisponível');
        }
    }
    $this->getJson(route('insurance-analyses.data', $this->lead))->assertOk()
        ->assertJsonPath('data.providers.enabled', array_values(array_intersect(InsuranceProviderResolver::PROVIDERS, $enabled)));
})->with([
    'somente Porto' => [['porto']],
    'todas menos Porto' => [['pottencial', 'too', 'tokio']],
    'nenhuma' => [[]],
    'todas' => [['pottencial', 'too', 'tokio', 'porto']],
]);

it('shows a previous result of a provider disabled later as read-only, without actions', function () {
    enableProviders('pottencial');
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 2]);
    $too = blockingAnalysis($batch, 'too', 'failed');
    $pottencial = blockingAnalysis($batch, 'pottencial', 'failed');

    $cards = blockingCards();

    // Too desligada depois da análise: resultado visível, sem bloqueio nem ações.
    expect($cards['too']->getAttribute('data-provider-read-only'))->toBe('true');
    expect($cards['too']->getAttribute('data-analysis-state'))->toBe('failed');
    expect($cards['too']->textContent)->toContain('Desativada')->not->toContain('Bloqueada')
        ->toContain('Não foi possível concluir a cotação.');
    expect((new DOMXPath($cards['too']->ownerDocument))->query('.//*[@data-provider-reanalysis]', $cards['too'])->length)->toBe(0);
    // Porto e Tokio desligadas e fora do pacote: bloqueadas.
    expect($cards['porto']->getAttribute('data-analysis-state'))->toBe('blocked');
    expect($cards['tokio']->getAttribute('data-analysis-state'))->toBe('blocked');
    // Pottencial ligada segue normal.
    expect($cards['pottencial']->getAttribute('data-provider-read-only'))->toBe('false');

    $data = $this->getJson(route('insurance-analyses.data', $this->lead))->assertOk()->json('data.analyses');
    $byProvider = collect($data)->keyBy('provider');
    expect($byProvider['too']['provider_enabled'])->toBeFalse();
    foreach (['retry', 'reanalysis', 'expense_reanalysis', 'sync'] as $action) {
        expect($byProvider['too']['actions'][$action])->toMatchArray(['available' => false, 'url' => null]);
    }
    expect($byProvider['pottencial']['provider_enabled'])->toBeTrue();
    expect($byProvider['pottencial']['actions']['retry'])->toMatchArray([
        'available' => true, 'url' => route('insurance-analyses.retry', $pottencial),
    ]);
    expect($too->fresh()->status)->toBe('failed');
});

it('keeps an approved result of a disabled provider in the comparison', function () {
    enableProviders('pottencial');
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 1, 'finished_at' => now()]);
    blockingAnalysis($batch, 'porto', 'approved', ['gross_premium' => '1200.00', 'response_payload' => [
        'quote_selection' => ['installment_amount' => '210.50', 'installments' => 29, 'payment_type' => 'CC'],
    ]]);

    $cards = blockingCards();

    expect($cards['porto']->getAttribute('data-provider-read-only'))->toBe('true');
    expect($cards['porto']->textContent)->toContain('Desativada');
    $html = $this->get(route('insurance-analyses.lead', $this->lead))->getContent();
    expect($html)->toContain('R$ 210,50');
});

it('does not leave an enabled provider missing from the current batch waiting forever', function () {
    enableProviders('pottencial', 'porto');
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 1]);
    blockingAnalysis($batch, 'pottencial', 'processing');

    $cards = blockingCards();

    expect($cards['porto']->getAttribute('data-analysis-state'))->toBe('not_included');
    expect($cards['porto']->getAttribute('aria-busy'))->toBe('false');
    expect($cards['porto']->textContent)->toContain('não participou deste pacote')
        ->not->toContain('Bloqueada')->not->toContain('Aguardando análise desta companhia.');
});

it('refuses retry and sync of a disabled provider on the server, even without the button', function () {
    enableProviders('pottencial');
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'processing', 'total_providers' => 1]);
    $failed = blockingAnalysis($batch, 'too', 'failed');
    $processing = blockingAnalysis($batch, 'porto', 'processing', ['quote_id' => '000000010009512']);
    $page = route('insurance-analyses.lead', $this->lead);

    $this->from($page)->post(route('insurance-analyses.retry', $failed))
        ->assertRedirect($page)->assertSessionHas('warning', fn (string $message) => str_contains($message, 'desativada'));
    $this->from($page)->post(route('insurance-analyses.sync-status', $processing))
        ->assertRedirect($page)->assertSessionHas('error', fn (string $message) => str_contains($message, 'desativada'));

    Bus::assertNothingDispatched();
    expect($failed->fresh()->status)->toBe('failed');
    expect($failed->events()->count())->toBe(1);
    expect($processing->fresh()->status)->toBe('processing');
});

it('refuses reanalysis of a disabled provider before touching the lead', function (string $method) {
    enableProviders('pottencial');
    $batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 1, 'finished_at' => now()]);
    $analysis = blockingAnalysis($batch, 'tokio', 'rejected');
    $service = app(LeadReanalysisService::class);

    expect(fn () => $method === 'expenses'
        ? $service->reanalyzeProviderExpenses($analysis, [], 'imobiliaria')
        : $service->startProviderReanalysis($analysis, 'imobiliaria'))
        ->toThrow(DomainException::class, 'desativada');

    Bus::assertNothingDispatched();
    expect($analysis->events()->count())->toBe(1);
})->with(['despesas' => ['expenses'], 'reanálise da companhia' => ['provider']]);
