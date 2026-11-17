<?php

use App\Exceptions\InsuranceProviderDisabled;
use App\Jobs\RunInsuranceQuoteJob;
use App\Jobs\RunProviderAnalysisJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceQuote;
use App\Models\Lead;
use App\Services\Insurance\InsuranceQuoteCoordinator;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['features.insurance_analysis.enabled' => true, 'queue.default' => 'database']);
    foreach (InsuranceProviderResolver::PROVIDERS as $provider) {
        config(["services.{$provider}.enabled" => false]);
    }
    Queue::fake();
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Http::assertNothingSent();
});

function disabledProviderAnalysis(string $provider): InsuranceAnalysis
{
    $lead = Lead::query()->create([
        'nome' => 'Pretendente', 'cpf' => '52998224725', 'email' => 'disabled@example.test',
        'data_nascimento' => '1990-05-01', 'tipo_locacao' => 'residencial', 'tipo_solicitante' => 'locatario',
    ]);
    $lead->despesas()->create(['valor_aluguel' => 1000]);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => 1]);
    $analysis = InsuranceAnalysis::query()->create([
        'insurance_analysis_batch_id' => $batch->id, 'lead_id' => $lead->id, 'provider' => $provider,
        'status' => 'pending', 'rent_amount' => 1000, 'product' => 'fianca_locaticia_residencial',
    ]);
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'round-one']]);

    return $analysis;
}

it('keeps the resolver exception compatible with LogicException handlers', function (): void {
    expect(fn () => app(InsuranceProviderResolver::class)->resolve('pottencial'))
        ->toThrow(InsuranceProviderDisabled::class, 'O provider pottencial está desativado.')
        ->and(new InsuranceProviderDisabled('too'))->toBeInstanceOf(LogicException::class);
});

it('fails quotes of a disabled provider at once, without retry or polling', function (string $provider, string $label, int $quotes): void {
    $analysis = disabledProviderAnalysis($provider);
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $coordinator->dispatchPending($coordinator->prepare($analysis, 'round-one', false));
    expect(InsuranceQuote::count())->toBe($quotes);
    Queue::assertPushed(RunInsuranceQuoteJob::class, $quotes);

    foreach (InsuranceQuote::all() as $quote) {
        // Não lança: a fila não reagenda nem aplica backoff.
        app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']);
    }

    $message = "A companhia {$label} está desativada no sistema. A cotação foi encerrada sem nova tentativa.";
    foreach (InsuranceQuote::all() as $quote) {
        expect($quote->status)->toBe('failed')
            ->and($quote->error_message)->toBe($message)
            ->and($quote->finished_at)->not->toBeNull()
            ->and($quote->requested_at)->toBeNull()
            ->and($quote->events()->where('event_type', 'execution_failed')->value('message'))->toBe($message);
    }
    expect($analysis->fresh()->status)->toBe('failed');
    // Somente o despacho inicial: nenhuma nova tentativa ou consulta de status.
    Queue::assertPushed(RunInsuranceQuoteJob::class, $quotes);
})->with([
    'pottencial' => ['pottencial', 'Pottencial', 2],
    'too' => ['too', 'Too Seguros', 1],
    'tokio' => ['tokio', 'Tokio Marine', 1],
    'porto' => ['porto', 'Porto Seguro', 1],
]);

it('keeps an approved quote approved and fails only its pending payment', function (): void {
    $analysis = disabledProviderAnalysis('too');
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $quote = InsuranceQuote::first();
    $quote->forceFill([
        'status' => 'approved', 'result' => 'approved', 'payment_status' => 'pending',
        'quote_id' => 'remote-one', 'requested_at' => now(),
    ])->save();

    app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']);

    expect($quote->fresh()->status)->toBe('approved')
        ->and($quote->fresh()->payment_status)->toBe('failed');
    Queue::assertNotPushed(RunInsuranceQuoteJob::class);
});

it('fails a legacy analysis without a provider run at once', function (): void {
    $analysis = disabledProviderAnalysis('pottencial');

    app()->call([new RunProviderAnalysisJob($analysis->id, 'round-one'), 'handle']);

    expect($analysis->fresh()->status)->toBe('failed')
        ->and($analysis->fresh()->error_message)
        ->toBe('A companhia Pottencial está desativada no sistema. A cotação foi encerrada sem nova tentativa.');
});

it('still retries ordinary technical failures through the queue', function (): void {
    config(['services.pottencial.enabled' => true]);
    $analysis = disabledProviderAnalysis('pottencial');
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $quote = InsuranceQuote::first();
    $this->mock(\App\Services\Insurance\Providers\PottencialInsuranceProvider::class)
        ->shouldReceive('requestAnalysis')->once()->andThrow(new RuntimeException('Falha de rede.'));

    expect(fn () => app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']))
        ->toThrow(RuntimeException::class, 'Falha de rede.');
    expect($quote->fresh()->status)->toBe('processing');
});
