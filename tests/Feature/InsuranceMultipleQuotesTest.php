<?php

use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\RunInsuranceQuoteJob;
use App\Jobs\RunProviderAnalysisJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisRound;
use App\Models\InsuranceQuote;
use App\Models\Lead;
use App\Services\Insurance\InsuranceAnalysisAttempt;
use App\Services\Insurance\InsuranceLeadSnapshot;
use App\Services\Insurance\InsuranceQuoteCoordinator;
use App\Services\Insurance\InsuranceQuotePayment;
use App\Services\Insurance\PottencialService;
use App\Services\Insurance\Providers\PottencialInsuranceProvider;
use App\Services\Insurance\TooService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['features.insurance_analysis.enabled' => true, 'services.pottencial.enabled' => true, 'services.too.enabled' => true, 'queue.default' => 'database']);
    Queue::fake();
    Http::preventStrayRequests();
});

function multipleQuoteAnalysis(bool $spouse = true, string $provider = 'pottencial'): InsuranceAnalysis
{
    $lead = Lead::query()->create([
        'nome' => 'Primeiro pretendente', 'cpf' => '52998224725', 'email' => 'quotes@example.test',
        'data_nascimento' => '1990-05-01', 'tipo_locacao' => 'residencial', 'tipo_solicitante' => 'locatario',
    ]);
    $lead->despesas()->create(['valor_aluguel' => 1000]);
    if ($spouse) {
        $lead->conjuge()->create(['nome' => 'Segundo pretendente', 'cpf' => '11144477735']);
    }
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => 1]);
    $analysis = InsuranceAnalysis::query()->create([
        'insurance_analysis_batch_id' => $batch->id, 'lead_id' => $lead->id, 'provider' => $provider,
        'status' => 'pending', 'rent_amount' => 1000, 'product' => 'fianca_locaticia_residencial',
    ]);
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'round-one']]);

    return $analysis;
}

function finishMultipleQuote(InsuranceQuote $quote, string $status, ?float $amount = null): void
{
    $quote->forceFill([
        'status' => $status, 'result' => $status === 'failed' ? null : $status,
        'payment_status' => $amount ? 'ready' : 'unavailable',
        'installment_amount' => $amount, 'quote_id' => 'external-'.$quote->id, 'finished_at' => now(),
    ])->save();
}

it('plans independent quotes without duplicating the provider card or round', function (bool $spouse, string $provider, int $expected): void {
    $analysis = multipleQuoteAnalysis($spouse, $provider);
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $run = $coordinator->prepare($analysis, 'round-one', false);
    $again = $coordinator->prepare($analysis, 'round-one', false);
    expect($run->id)->toBe($again->id)
        ->and(InsuranceAnalysisRound::count())->toBe(1)
        ->and(InsuranceAnalysis::count())->toBe(1)
        ->and(InsuranceQuote::count())->toBe($expected);
    $coordinator->dispatchPending($run);
    $coordinator->dispatchPending($run);
    Queue::assertPushed(RunInsuranceQuoteJob::class, $expected);
})->with([[true, 'pottencial', 4], [false, 'pottencial', 2], [true, 'too', 2], [false, 'too', 1]]);

it('creates the joint attempt once only after all four individual plans are refused', function (): void {
    $analysis = multipleQuoteAnalysis();
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $run = $coordinator->prepare($analysis, 'round-one', false);
    foreach (InsuranceQuote::all() as $quote) {
        finishMultipleQuote($quote, 'rejected');
    }
    $coordinator->consolidate($run->id);
    $coordinator->consolidate($run->id);
    expect(InsuranceQuote::count())->toBe(6)
        ->and($analysis->fresh()->status)->toBe('processing')
        ->and($run->scenarios()->where('kind', 'joint')->count())->toBe(1);
    Queue::assertPushed(RunInsuranceQuoteJob::class, 2);
    Queue::assertNotPushed(CompleteInsuranceAnalysesBatchJob::class);
    foreach (InsuranceQuote::where('status', 'pending')->get() as $quote) {
        finishMultipleQuote($quote, 'rejected');
    }
    $coordinator->consolidate($run->id);
    expect($analysis->fresh()->status)->toBe('rejected')->and(InsuranceQuote::count())->toBe(6);
    Queue::assertPushed(CompleteInsuranceAnalysesBatchJob::class, 1);
});

it('never treats an error or a pending decision as a refusal of both applicants', function (string $lastStatus): void {
    $analysis = multipleQuoteAnalysis();
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $run = $coordinator->prepare($analysis, 'round-one', false);
    foreach (InsuranceQuote::all() as $quote) {
        finishMultipleQuote($quote, 'rejected');
    }
    finishMultipleQuote(InsuranceQuote::latest('id')->first(), $lastStatus, $lastStatus === 'approved' ? 90 : null);
    $coordinator->consolidate($run->id);
    expect(InsuranceQuote::count())->toBe(4)
        ->and($run->scenarios()->where('kind', 'joint')->exists())->toBeFalse();
})->with(['failed', 'processing', 'manual_review', 'approved']);

it('selects the cheapest approved installment and preserves all other quotes', function (): void {
    $analysis = multipleQuoteAnalysis();
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $run = $coordinator->prepare($analysis, 'round-one', false);
    $quotes = InsuranceQuote::orderBy('id')->get();
    foreach ($quotes as $quote) {
        finishMultipleQuote($quote, 'rejected');
    }
    finishMultipleQuote($quotes[0], 'approved', 120);
    finishMultipleQuote($quotes[3], 'approved', 80);
    $coordinator->consolidate($run->id);
    $coordinator->consolidate($run->id);
    expect($run->fresh()->selected_quote_id)->toBe($quotes[3]->id)
        ->and($analysis->fresh()->quote_id)->toBe('external-'.$quotes[3]->id)
        ->and($analysis->fresh()->status)->toBe('approved')
        ->and(InsuranceQuote::count())->toBe(4)
        ->and($analysis->events()->where('event_type', 'analysis_completed')->count())->toBe(1);
});

it('keeps a successful credit decision separate from missing payment data', function (): void {
    $analysis = multipleQuoteAnalysis(false);
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $run = $coordinator->prepare($analysis, 'round-one', false);
    foreach (InsuranceQuote::all() as $quote) {
        finishMultipleQuote($quote, 'approved');
    }
    $first = InsuranceQuote::first();
    $first->update(['payment_status' => 'pending']);
    $coordinator->consolidate($run->id);
    expect($analysis->fresh()->status)->toBe('processing');
    $first->update(['payment_status' => 'failed']);
    $coordinator->consolidate($run->id);
    expect($analysis->fresh()->status)->toBe('approved')
        ->and($run->fresh()->selected_quote_id)->toBeNull();
});

it('freezes personal data and dates without changing the original lead for spouse quotes', function (): void {
    $this->travelTo(now()->setDate(2026, 1, 31));
    $analysis = multipleQuoteAnalysis();
    $run = app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $analysis->lead->update(['nome' => 'Nome alterado']);
    $quote = $run->scenarios()->where('kind', 'spouse')->first()->quotes()->first();
    app(InsuranceLeadSnapshot::class)->attach($quote);
    expect($quote->lead->nome)->toBe('Segundo pretendente')
        ->and($quote->lead->cpf)->toBe('11144477735')
        ->and($quote->lead->data_nascimento)->toBeNull()
        ->and($quote->lead->conjuge)->toBeNull()
        ->and($analysis->lead->fresh()->nome)->toBe('Nome alterado')
        ->and($quote->lease_end_date->format('Y-m-d'))->toBe('2028-07-31')
        ->and($run->round->getRawOriginal('lead_snapshot'))->not->toContain('Primeiro pretendente');
});

it('discards stale quote writes after a newer round replaces the provider run', function (): void {
    $analysis = multipleQuoteAnalysis(false);
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $first = $coordinator->prepare($analysis, 'round-one', false);
    $oldQuote = InsuranceQuote::first();
    // Reenvio técnico: a Pottencial não aceita reanálise, mas a rodada nova invalida a antiga.
    $analysis->events()->create(['event_type' => 'technical_retry_requested', 'payload' => ['attempt_id' => 'round-two']]);
    $second = $coordinator->prepare($analysis, 'round-two', false);
    expect(fn () => InsuranceAnalysisAttempt::run($oldQuote, 'round-one', fn () => $oldQuote->update(['status' => 'approved'])))
        ->toThrow(\App\Exceptions\ObsoleteInsuranceAnalysisAttempt::class);
    expect($first->id)->not->toBe($second->id)
        ->and($oldQuote->fresh()->status)->toBe('pending')
        ->and(InsuranceQuote::count())->toBe(4);
});

it('routes provider loader jobs to persisted quote jobs', function (): void {
    $analysis = multipleQuoteAnalysis(false);
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    app()->call([new RunProviderAnalysisJob($analysis->id, 'round-one'), 'handle']);
    Queue::assertPushed(RunInsuranceQuoteJob::class, 2);
    Http::assertNothingSent();
});

it('executes and resumes an approved quote without creating it again', function (): void {
    $analysis = multipleQuoteAnalysis(false);
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $quote = InsuranceQuote::first();
    $this->mock(PottencialInsuranceProvider::class)->shouldReceive('requestAnalysis')->once()->andReturn([
        'success' => true, 'http_status' => 200, 'response' => ['quoteId' => 'remote-one', 'status' => 'Approved', 'grossPremium' => 3000],
    ]);
    $this->mock(PottencialService::class)->shouldReceive('getPaymentSimulations')->once()->with('remote-one')->andReturn([
        'success' => true, 'response' => [['paymentType' => 'Boleto', 'installments' => [
            ['countInstallments' => 30, 'installments' => array_fill(0, 30, 100), 'grossAmount' => 3000],
        ]]],
    ]);
    app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']);
    app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']);
    expect($quote->fresh()->status)->toBe('approved')
        ->and($quote->fresh()->installment_amount)->toBe('100.00')
        ->and($analysis->fresh()->status)->toBe('processing');
});

it('does not repeat a creation whose external outcome is unknown', function (): void {
    $analysis = multipleQuoteAnalysis(false);
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $quote = InsuranceQuote::first();
    $quote->update(['status' => 'processing', 'requested_at' => now()]);
    app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']);
    expect($quote->fresh()->status)->toBe('failed');
    Http::assertNothingSent();
});

it('quotes Too approved and preapproved credit through payment and consolidation without duplicate submission', function (int $status, bool $quoteSuccess): void {
    $analysis = multipleQuoteAnalysis(false, 'too');
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $quote = InsuranceQuote::first();
    $builder = $this->mock(\App\Services\Insurance\Payloads\TooRentalGuaranteePayloadBuilder::class);
    $builder->shouldReceive('buildFichaPayload')->once()->andReturn([]);
    $builder->shouldReceive('buildQuotePayload')->once()->andReturn(['numeroFicha' => 123]);
    $service = $this->mock(TooService::class);
    $service->shouldReceive('registerProposalFicha')->once()->andReturn(['success' => true, 'response' => ['numeroFicha' => 123]]);
    $service->shouldReceive('submitCreditAnalysis')->once()->andReturn(['success' => true]);
    $description = $status === 16 ? 'Crédito Pré-aprovado' : 'Crédito Aprovado';
    $service->shouldReceive('getProposalStatus')->once()->andReturn([
        'success' => true, 'response' => ['proposta' => ['status' => $status, 'descricaoStatus' => $description]],
    ]);
    $service->shouldReceive('requestQuote')->once()->with(['numeroFicha' => 123])->andReturn([
        'success' => $quoteSuccess, 'http_status' => $quoteSuccess ? 200 : 400,
        'response' => $quoteSuccess ? ['numeroCotacao' => 789, 'premio' => 2175] : [],
    ]);
    if ($quoteSuccess) {
        $service->shouldReceive('getPaymentOptions')->once()->with('789')->andReturn([
            'success' => true, 'response' => [
                'tipoPagamento' => [['tipo' => 1]],
                'opcoesParcelamento' => [['tipo' => 2, 'comEntrada' => false, 'parcelas' => [['numeroParcela' => 29, 'valor' => 75]]]],
            ],
        ]);
    } else {
        $service->shouldNotReceive('getPaymentOptions');
    }

    $job = new RunInsuranceQuoteJob($quote->id, 'round-one');
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect($quote->fresh()->status)->toBe($quoteSuccess ? 'approved' : 'failed')
        ->and($analysis->fresh()->status)->toBe($quoteSuccess ? 'approved' : 'failed');
    if ($quoteSuccess) {
        expect($quote->fresh()->provider_status)->toBe($description)
            ->and($quote->fresh()->response_payload['too_status_info'])->toMatchArray(['status_code' => $status, 'can_quote' => true])
            ->and($quote->fresh()->quote_id)->toBe('789')
            ->and($quote->fresh()->installment_amount)->toBe('75.00')
            ->and($quote->fresh()->installments)->toBe(29)
            ->and($quote->fresh()->payment_status)->toBe('ready')
            ->and($analysis->fresh()->provider_status)->toBe($description);
    } else {
        expect($quote->fresh()->quote_id)->toBeNull();
    }
    Http::assertNothingSent();
})->with([[8, true], [16, true], [16, false]]);

it('selects the Too monthly option without entry using its returned 29 installments', function (): void {
    $analysis = multipleQuoteAnalysis(false, 'too');
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $quote = InsuranceQuote::first();
    $quote->update(['quote_id' => 'too-quote']);
    $this->mock(TooService::class)->shouldReceive('getPaymentOptions')->once()->andReturn([
        'success' => true, 'response' => [
            'tipoPagamento' => [['tipo' => 1]],
            'opcoesParcelamento' => [
                ['tipo' => 2, 'comEntrada' => true, 'parcelas' => [['numeroParcela' => 30, 'valor' => 70]]],
                ['tipo' => 2, 'comEntrada' => false, 'parcelas' => [['numeroParcela' => 29, 'valor' => 75]]],
            ],
        ],
    ]);
    $payment = app(InsuranceQuotePayment::class)->fetch($quote);
    expect($payment['selection']['amount'])->toBe('75.00')->and($payment['selection']['count'])->toBe(29);
});

it('builds one Pottencial payload per plan and includes both documents only for the joint scenario', function (): void {
    config(['services.pottencial.broker_document' => '11222333000181']);
    $analysis = multipleQuoteAnalysis();
    $analysis->lead->update(['tel' => '(11) 99999-8888']);
    $analysis->lead->endereco()->create([
        'logradouro' => 'Rua de Teste', 'numero' => '12', 'bairro' => 'Centro',
        'cidade_imovel' => 'Sao Paulo', 'estado' => 'SP', 'cep' => '01001000',
    ]);
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $run = $coordinator->prepare($analysis, 'round-one', false);
    $snapshots = app(InsuranceLeadSnapshot::class);
    $builder = app(\App\Services\Insurance\Payloads\RentalGuaranteeQuotePayloadBuilder::class);
    foreach (InsuranceQuote::all() as $quote) {
        $snapshots->attach($quote);
        $payload = $builder->build($quote);
        expect($payload['riskObjects'][0]['planKey'])->toBe($quote->plan_key)
            ->and($payload['participants'])->toHaveCount(1)
            ->and($payload['participants'][0]['documentNumber'])->toBe($quote->scenario->kind === 'spouse' ? '11144477735' : '52998224725')
            ->and($payload['participants'][0]['contact'])->toBe(['email' => 'quotes@example.test', 'cellPhoneNumber' => '11999998888'])
            ->and($payload['paymentConditions']['installments'])->toBe(30);
        finishMultipleQuote($quote, 'rejected');
    }
    $coordinator->consolidate($run->id);
    $quote = $run->scenarios()->where('kind', 'joint')->first()->quotes()->first();
    $snapshots->attach($quote);
    expect($builder->build($quote)['participants'])->toBe([
        ['documentNumber' => '52998224725', 'role' => 'PolicyHolder', 'contact' => ['email' => 'quotes@example.test', 'cellPhoneNumber' => '11999998888']],
        ['documentNumber' => '11144477735', 'role' => 'PolicyHolder', 'contact' => ['email' => 'quotes@example.test', 'cellPhoneNumber' => '11999998888']],
    ]);
});

it('retries payment retrieval without issuing another quote', function (): void {
    $analysis = multipleQuoteAnalysis(false);
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $quote = InsuranceQuote::first();
    $this->mock(PottencialInsuranceProvider::class)->shouldReceive('requestAnalysis')->once()->andReturn([
        'success' => true, 'response' => ['quoteId' => 'payment-retry', 'status' => 'Approved'],
    ]);
    $calls = 0;
    $this->mock(PottencialService::class)->shouldReceive('getPaymentSimulations')->twice()->andReturnUsing(function () use (&$calls): array {
        if (++$calls === 1) {
            throw new RuntimeException('Temporary payment failure');
        }

        return ['success' => true, 'response' => ['paymentType' => 'Boleto', 'installments' => [
            ['countInstallments' => 30, 'installments' => array_fill(0, 30, 90), 'grossAmount' => 2700],
        ]]];
    });
    expect(fn () => app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']))->toThrow(RuntimeException::class);
    expect($quote->fresh()->status)->toBe('approved')->and($quote->fresh()->payment_status)->toBe('pending');
    app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']);
    expect($quote->fresh()->installment_amount)->toBe('90.00');
});

it('ignores duplicate polling jobs from an earlier generation', function (): void {
    $analysis = multipleQuoteAnalysis(false);
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $quote = InsuranceQuote::first();
    $this->mock(PottencialInsuranceProvider::class)->shouldReceive('requestAnalysis')->once()->andReturn([
        'success' => true, 'response' => ['quoteId' => 'pending-quote', 'status' => 'UnderAnalysis'],
    ]);
    app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']);
    app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']);
    expect($quote->fresh()->poll_count)->toBe(1);
    Queue::assertPushed(RunInsuranceQuoteJob::class, fn ($job): bool => $job->quoteId === $quote->id && $job->generation === 1);
    expect(Queue::pushed(RunInsuranceQuoteJob::class)->filter(fn ($job) => $job->quoteId === $quote->id)->count())->toBe(1);
});

it('refuses reanalysis rounds for Pottencial and Too without creating a round or a job', function (string $provider): void {
    $analysis = multipleQuoteAnalysis(false, $provider);
    $analysis->update(['status' => 'rejected']);
    $before = $analysis->fresh()->toArray();
    $service = app(\App\Services\Insurance\LeadReanalysisService::class);

    expect(fn () => $service->startProviderReanalysis($analysis, 'admin'))->toThrow(DomainException::class, 'não permite reanálise')
        ->and(fn () => app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'forced-reanalysis', true))->toThrow(DomainException::class);
    expect($analysis->fresh()->toArray())->toBe($before)
        ->and(\App\Models\InsuranceAnalysisRound::count())->toBe(0)
        ->and($analysis->events()->where('event_type', 'reanalysis_requested')->exists())->toBeFalse();
    Queue::assertNotPushed(RunProviderAnalysisJob::class);
    Http::assertNothingSent();
})->with(['pottencial', 'too']);

it('creates a fresh reanalysis round for Tokio, which accepts reanalysis', function (): void {
    config(['services.tokio.enabled' => true]);
    $analysis = multipleQuoteAnalysis(false, 'tokio');
    $analysis->update(['status' => 'rejected']);
    $attempt = app(\App\Services\Insurance\LeadReanalysisService::class)->startProviderReanalysis($analysis, 'admin');
    $run = app(InsuranceQuoteCoordinator::class)->findRun($analysis, $attempt);
    expect($run)->not->toBeNull()
        ->and($run->round->is_reanalysis)->toBeTrue()
        ->and($analysis->fresh()->status)->toBe('pending');
    Queue::assertPushed(RunProviderAnalysisJob::class, fn ($job) => $job->analysisId === $analysis->id && $job->isReanalysis === true);
    Http::assertNothingSent();
});

it('bounds Too pending consultations without converting them to refusals or repeating requests', function (string $lastStatus) {
    config(['services.too.status_check_max_pending_checks' => 2]);
    $analysis = multipleQuoteAnalysis(false, 'too');
    $run = app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $quote = InsuranceQuote::first();
    $quote->update(['proposal_id' => 'pending-proposal']);
    $provider = $this->mock(\App\Services\Insurance\Providers\TooInsuranceProvider::class);
    $provider->shouldReceive('requestAnalysis')->once()->andReturn([
        'success' => true, 'response' => ['quoteId' => 'pending-quote', 'status' => 'UnderAnalysis'],
    ]);
    $provider->shouldReceive('getStatus')->twice()->andReturn(
        ['success' => true, 'response' => ['quoteId' => 'pending-quote', 'status' => 'UnderAnalysis']],
        ['success' => true, 'response' => ['quoteId' => 'pending-quote', 'status' => $lastStatus]],
    );
    if ($lastStatus === 'Approved') {
        $this->mock(TooService::class)->shouldReceive('getPaymentOptions')->once()->andReturn([
            'success' => true, 'response' => [
                'tipoPagamento' => [['tipo' => 1]],
                'opcoesParcelamento' => [['tipo' => 2, 'comEntrada' => false, 'parcelas' => [['numeroParcela' => 29, 'valor' => 40]]]],
            ],
        ]);
    }
    foreach ([0, 1, 2, 2] as $generation) {
        app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one', $generation), 'handle']);
    }
    expect(Queue::pushed(RunInsuranceQuoteJob::class)->filter(fn ($job) => $job->quoteId === $quote->id)->count())->toBe(2);
    expect($run->scenarios()->where('kind', 'joint')->exists())->toBeFalse();
    if ($lastStatus === 'UnderAnalysis') {
        expect($quote->fresh()->status)->toBe('processing')
            ->and($quote->fresh()->response_payload['too_status_check_stop_reason'])->toBe('pending_limit_reached')
            ->and($quote->fresh()->events()->where('event_type', 'too_polling_limit_reached')->count())->toBe(1)
            ->and($analysis->fresh()->status)->toBe('processing');
    } else {
        expect($quote->fresh()->status)->toBe('approved')->and($quote->fresh()->installment_amount)->toBe('40.00');
    }
})->with(['UnderAnalysis', 'Approved']);

it('compares confirmed installments rather than reversing the winner using premium totals', function (): void {
    $comparison = app(\App\Services\Insurance\AnalysisQuoteComparison::class);
    $quotes = collect([
        ['analysis_id' => 1, 'status' => 'approved', 'price' => ['comparison_basis' => 'installment', 'installment_amount' => '80.00', 'total' => 3000, 'currency' => 'BRL', 'period_start' => '2026-10-29', 'period_end' => '2029-04-29']],
        ['analysis_id' => 2, 'status' => 'approved', 'price' => ['comparison_basis' => 'installment', 'installment_amount' => '90.00', 'total' => 2000, 'currency' => 'BRL', 'period_start' => '2026-10-29', 'period_end' => '2029-04-29']],
    ]);
    expect($comparison->compare($quotes)['best_quote']['analysis_id'])->toBe(1);
});

it('keeps batch completion blocked while quote scenarios are unfinished', function (): void {
    $analysis = multipleQuoteAnalysis(false);
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $analysis->update(['status' => 'approved']);
    (new CompleteInsuranceAnalysesBatchJob($analysis->insurance_analysis_batch_id, 'round-one'))->handle();
    expect($analysis->batch->fresh()->status)->toBe('processing')
        ->and($analysis->batch->fresh()->finished_at)->toBeNull();
    Queue::assertNotPushed(\App\Jobs\SendAnalysisResultsEmailJob::class);
});

it('rolls back the new persistence without deleting existing analyses', function (): void {
    $analysis = multipleQuoteAnalysis(false);
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $migration = require database_path('migrations/2026_10_29_121802_create_insurance_quote_orchestration_tables.php');
    $migration->down();
    expect(InsuranceAnalysis::count())->toBe(1)
        ->and(\Illuminate\Support\Facades\Schema::hasTable('insurance_quotes'))->toBeFalse();
    $migration->up();
});

it('exposes quote scenarios through the authorized backend without raw payloads', function (): void {
    $company = \App\Models\Imobiliaria::factory()->create();
    $user = \App\Models\User::factory()->create(['company_id' => $company->id]);
    $analysis = multipleQuoteAnalysis();
    $analysis->lead->update(['company_id' => $company->id]);
    $analysis->update(['company_id' => $company->id]);
    $run = app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $quote = InsuranceQuote::first();
    $quote->update(['request_payload' => ['private' => 'request-secret'], 'response_payload' => ['private' => 'response-secret']]);
    $this->actingAs($user)->withSession(['2fa_passed' => true])
        ->getJson(route('insurance-analyses.data', $analysis->lead_id))
        ->assertOk()
        ->assertJsonCount(1, 'data.analyses')
        ->assertJsonCount(4, 'data.analyses.0.quote_attempts')
        ->assertJsonPath('data.analyses.0.quote_attempts.0.applicants.0', 'Primeiro pretendente')
        ->assertJsonPath('data.analyses.0.quote_attempts.2.applicants.0', 'Segundo pretendente')
        ->assertDontSee('request-secret')->assertDontSee('response-secret');
    expect($run->fresh()->finished_at)->toBeNull();
});

it('uses each persisted birthdate in Too individual and joint snapshot payloads', function () {
    config(['services.too.broker_cnpj' => '11222333000181', 'services.too.broker_name' => 'Corretora', 'services.too.default_employment' => 'Autonomo', 'services.too.default_profession' => 'Consultor']);
    $analysis = multipleQuoteAnalysis(true, 'too');
    $analysis->lead->conjuge()->update(['data_nascimento' => '1994-09-12']);
    $analysis->lead->endereco()->create(['cep' => '01001000', 'logradouro' => 'Praça da Sé', 'numero' => '1', 'bairro' => 'Sé', 'cidade_imovel' => 'São Paulo', 'estado' => 'SP']);
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $run = $coordinator->prepare($analysis, 'round-one', false);
    $analysis->lead->conjuge()->update(['data_nascimento' => '1999-01-01']);
    $builder = app(\App\Services\Insurance\Payloads\TooRentalGuaranteePayloadBuilder::class);
    foreach (InsuranceQuote::all() as $quote) {
        app(InsuranceLeadSnapshot::class)->attach($quote);
        $expected = $quote->scenario->kind === 'spouse' ? '1994-09-12' : '1990-05-01';
        expect($builder->buildFichaPayload($quote)['pretendentes'][0]['dataNascimento'])->toBe($expected);
        finishMultipleQuote($quote, 'rejected');
    }
    $coordinator->consolidate($run->id);
    $joint = $run->scenarios()->where('kind', 'joint')->first()->quotes()->first();
    app(InsuranceLeadSnapshot::class)->attach($joint);
    $payload = $builder->buildFichaPayload($joint);
    expect(array_column($payload['pretendentes'], 'dataNascimento'))->toBe(['1990-05-01', '1994-09-12'])
        ->and(array_column($payload['pretendentes'], 'cpf'))->toBe(['52998224725', '11144477735'])
        ->and(array_column($payload['pretendentes'], 'principal'))->toBe([true, false]);
    Http::assertNothingSent();
});
