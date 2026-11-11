<?php

use App\Jobs\RunInsuranceQuoteJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceQuote;
use App\Models\Lead;
use App\Services\Insurance\InsuranceQuoteCoordinator;
use App\Services\Insurance\PottencialQuoteResponse;
use App\Services\Insurance\PottencialService;
use App\Services\Insurance\Providers\PottencialInsuranceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['features.insurance_analysis.enabled' => true, 'services.pottencial.enabled' => true, 'queue.default' => 'database']);
    Queue::fake();
    Http::preventStrayRequests();
});

function pottencialPlansAnalysis(bool $spouse = false): InsuranceAnalysis
{
    $lead = Lead::query()->create([
        'nome' => 'Primeiro pretendente', 'cpf' => '52998224725', 'email' => 'plans@example.test',
        'data_nascimento' => '1990-05-01', 'tipo_locacao' => 'residencial', 'tipo_solicitante' => 'locatario',
    ]);
    $lead->despesas()->create(['valor_aluguel' => 1600]);
    if ($spouse) {
        $lead->conjuge()->create(['nome' => 'Segundo pretendente', 'cpf' => '11144477735']);
    }
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => 1]);
    $analysis = InsuranceAnalysis::query()->create([
        'insurance_analysis_batch_id' => $batch->id, 'lead_id' => $lead->id, 'provider' => 'pottencial',
        'status' => 'pending', 'rent_amount' => 1600, 'product' => 'fianca_locaticia_residencial',
    ]);
    $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => 'round-one']]);

    return $analysis;
}

function finishPottencialPlan(InsuranceQuote $quote, string $status, ?float $amount = null): void
{
    $quote->forceFill([
        'status' => $status, 'result' => in_array($status, ['approved', 'rejected'], true) ? $status : null,
        'payment_status' => $amount ? 'ready' : 'unavailable', 'installment_amount' => $amount,
        // Plano indisponível não chega a gerar cotação remota.
        'quote_id' => $status === PottencialQuoteResponse::PLAN_UNAVAILABLE ? null : 'external-'.$quote->plan_key,
        'finished_at' => in_array($status, ['processing'], true) ? null : now(),
    ])->save();
}

/** Simulador real da análise local 3: 1ª parcela 179,58 e 29 × 179,43 (grossAmount 5.383,05). */
function pottencialRealSimulation(array $override = []): array
{
    return [array_replace([
        'paymentType' => 'Invoice', 'firstInstallmentPaymentType' => 'Invoice',
        'installments' => [array_replace([
            'countInstallments' => 30, 'commercialAmount' => 5013.08, 'iofAmount' => 369.97, 'grossAmount' => 5383.05,
            'additionalAmount' => 0, 'financialChargesAmount' => 0,
            'installments' => array_merge([179.58], array_fill(0, 29, 179.43)),
        ], $override)],
    ], array_intersect_key($override, ['paymentType' => true]))];
}

function pottencialPlanUnavailableResult(): array
{
    return ['success' => false, 'http_status' => 400, 'response' => ['errors' => [[
        'code' => '400', 'message' => "The chosen plan is not available for this quote. Please try again by choosing between plans '' or 'Traditional'.",
    ]]]];
}

it('quotes only the Complete and Traditional plans for every applicant scenario', function (bool $spouse, int $expected): void {
    $run = app(InsuranceQuoteCoordinator::class)->prepare(pottencialPlansAnalysis($spouse), 'round-one', false);

    expect(InsuranceQuote::count())->toBe($expected);
    foreach ($run->scenarios as $scenario) {
        expect($scenario->quotes->pluck('plan_key')->all())->toBe(['Complete', 'Traditional']);
    }
})->with(['sem cônjuge' => [false, 2], 'com cônjuge' => [true, 4]]);

it('reads the real installment sequence and prices the plan by its first installment', function (): void {
    $selection = app(PottencialQuoteResponse::class)->paymentSelection(pottencialRealSimulation(), 'Invoice');

    expect($selection)->toMatchArray([
        'amount' => '179.58', 'count' => 30, 'payment_type' => 'Invoice', 'with_entry' => false,
        'first_installment' => '179.58', 'recurring_installment' => '179.43',
    ])->and($selection['details']['installments'])->toHaveCount(30)
        ->and($selection['details']['grossAmount'])->toBe(5383.05);
});

it('leaves the price unavailable when the installment sequence is inconsistent', function (array $override): void {
    expect(app(PottencialQuoteResponse::class)->paymentSelection(pottencialRealSimulation($override), 'Invoice'))->toBeNull();
})->with([
    'soma diferente do grossAmount' => [['grossAmount' => 5383.00]],
    'sem grossAmount' => [['grossAmount' => null]],
    'somente 29 valores' => [['installments' => array_fill(0, 29, 179.43)]],
    'parcela zerada' => [['installments' => array_merge([0], array_fill(0, 29, 185.62))]],
    'quantidade diferente de 30' => [['countInstallments' => 29]],
    'outra forma de pagamento' => [['paymentType' => 'Boleto']],
]);

it('discards an unavailable plan and returns only the approved one with its first installment', function (): void {
    $analysis = pottencialPlansAnalysis();
    $run = app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    InsuranceQuote::query()->update(['payment_type' => 'Invoice']);
    $this->mock(PottencialInsuranceProvider::class)->shouldReceive('requestAnalysis')->twice()
        ->andReturnUsing(fn (InsuranceQuote $quote): array => $quote->plan_key === 'Complete'
            ? pottencialPlanUnavailableResult()
            : ['success' => true, 'http_status' => 201, 'response' => ['quoteId' => 'remote-traditional', 'status' => 'Approved', 'grossPremium' => 5383.05]]);
    $this->mock(PottencialService::class)->shouldReceive('getPaymentSimulations')->once()->with('remote-traditional')
        ->andReturn(['success' => true, 'response' => pottencialRealSimulation()]);

    foreach (InsuranceQuote::orderBy('id')->get() as $quote) {
        app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']);
    }

    $complete = InsuranceQuote::where('plan_key', 'Complete')->first();
    $traditional = InsuranceQuote::where('plan_key', 'Traditional')->first();
    expect($complete->status)->toBe(PottencialQuoteResponse::PLAN_UNAVAILABLE)
        ->and($complete->error_message)->toBeNull()
        ->and($complete->installment_amount)->toBeNull()
        ->and($complete->events()->where('event_type', 'plan_unavailable')->count())->toBe(1)
        ->and($traditional->status)->toBe('approved')
        ->and($traditional->installment_amount)->toBe('179.58')
        ->and(data_get($traditional->response_payload, 'payment.selection.recurring_installment'))->toBe('179.43')
        ->and($run->fresh()->selected_quote_id)->toBe($traditional->id)
        ->and($analysis->fresh()->status)->toBe('approved')
        ->and($analysis->fresh()->plan_key)->toBe('Traditional')
        ->and(data_get($analysis->fresh()->response_payload, 'quote_selection.plan_key'))->toBe('Traditional')
        ->and(data_get($analysis->fresh()->response_payload, 'quote_selection.installment_amount'))->toBe('179.58');
    // Plano descartado não é repetido nem consultado novamente.
    Queue::assertNotPushed(RunInsuranceQuoteJob::class, fn ($job) => $job->quoteId === $complete->id);
});

it('keeps any other HTTP 400 as a technical failure', function (): void {
    $analysis = pottencialPlansAnalysis();
    app(InsuranceQuoteCoordinator::class)->prepare($analysis, 'round-one', false);
    $quote = InsuranceQuote::where('plan_key', 'Complete')->first();
    $this->mock(PottencialInsuranceProvider::class)->shouldReceive('requestAnalysis')->once()->andReturn([
        'success' => false, 'http_status' => 400, 'response' => ['errors' => [['code' => '400', 'message' => 'Coverage multiple is invalid for this coverage.']]],
    ]);

    app()->call([new RunInsuranceQuoteJob($quote->id, 'round-one'), 'handle']);

    expect($quote->fresh()->status)->toBe('failed')
        ->and($quote->fresh()->error_message)->toContain('Coverage multiple is invalid');
});

it('decides the scenario by the remaining plan when one is unavailable', function (string $complete, string $traditional, string $expected, ?string $representative): void {
    $analysis = pottencialPlansAnalysis();
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $run = $coordinator->prepare($analysis, 'round-one', false);
    finishPottencialPlan(InsuranceQuote::where('plan_key', 'Complete')->first(), $complete, $complete === 'approved' ? 150 : null);
    finishPottencialPlan(InsuranceQuote::where('plan_key', 'Traditional')->first(), $traditional, $traditional === 'approved' ? 179.58 : null);

    $coordinator->consolidate($run->id);

    expect($analysis->fresh()->status)->toBe($expected)
        // A carta de recusa da Pottencial usa o quote_id do plano que decidiu.
        ->and($analysis->fresh()->quote_id)->toBe($representative);
})->with([
    'indisponível + aprovado' => ['plan_unavailable', 'approved', 'approved', 'external-Traditional'],
    'aprovado + indisponível' => ['approved', 'plan_unavailable', 'approved', 'external-Complete'],
    'indisponível + recusado' => ['plan_unavailable', 'rejected', 'rejected', 'external-Traditional'],
    'recusado + indisponível' => ['rejected', 'plan_unavailable', 'rejected', 'external-Complete'],
    'ambos indisponíveis' => ['plan_unavailable', 'plan_unavailable', 'rejected', null],
    'indisponível + falha' => ['plan_unavailable', 'failed', 'failed', 'external-Traditional'],
    'indisponível + pendente' => ['plan_unavailable', 'processing', 'processing', null],
]);

it('treats both plans unavailable as a refusal that can trigger the joint analysis', function (): void {
    $analysis = pottencialPlansAnalysis(true);
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $run = $coordinator->prepare($analysis, 'round-one', false);
    foreach ($run->scenarios as $scenario) {
        foreach ($scenario->quotes as $quote) {
            finishPottencialPlan($quote, $scenario->kind === 'primary' ? PottencialQuoteResponse::PLAN_UNAVAILABLE : 'rejected');
        }
    }

    $coordinator->consolidate($run->id);

    $joint = $run->scenarios()->where('kind', 'joint')->first();
    expect($joint)->not->toBeNull()
        ->and($joint->quotes()->pluck('plan_key')->all())->toBe(['Complete', 'Traditional'])
        ->and($analysis->fresh()->status)->toBe('processing');
});

it('returns the cheaper of two approved plans and keeps the pricier one as an alternative', function (): void {
    $analysis = pottencialPlansAnalysis();
    $coordinator = app(InsuranceQuoteCoordinator::class);
    $run = $coordinator->prepare($analysis, 'round-one', false);
    finishPottencialPlan($complete = InsuranceQuote::where('plan_key', 'Complete')->first(), 'approved', 150.25);
    finishPottencialPlan($traditional = InsuranceQuote::where('plan_key', 'Traditional')->first(), 'approved', 179.58);

    $coordinator->consolidate($run->id);

    expect($run->fresh()->selected_quote_id)->toBe($complete->id)
        ->and($analysis->fresh()->plan_key)->toBe('Complete')
        ->and(data_get($analysis->fresh()->response_payload, 'quote_selection.plan_key'))->toBe('Complete')
        ->and(data_get($analysis->fresh()->response_payload, 'quote_selection.installment_amount'))->toBe('150.25')
        ->and($traditional->fresh()->status)->toBe('approved')
        ->and($traditional->fresh()->installment_amount)->toBe('179.58');
});
