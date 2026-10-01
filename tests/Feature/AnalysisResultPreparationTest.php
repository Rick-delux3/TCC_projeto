<?php

use App\Exceptions\ObsoleteInsuranceAnalysisAttempt;
use App\Jobs\SendAnalysisResultsEmailJob;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use App\Models\Lead;
use App\Services\Insurance\AnalysisResultPreparationService;
use App\Services\Insurance\AnalysisResultRecipients;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    config(['features.insurance_analysis.enabled' => true]);
    Http::preventStrayRequests();
});

function preparationBatch(array $statuses = ['approved', 'approved']): InsuranceAnalysisBatch
{
    $lead = Lead::query()->create(['nome' => 'Tenant', 'email' => 'tenant@example.test', 'tipo_solicitante' => 'locatario']);
    $batch = InsuranceAnalysisBatch::query()->create([
        'lead_id' => $lead->id, 'status' => 'completed', 'total_providers' => count($statuses), 'finished_at' => now(),
    ]);
    foreach ($statuses as $index => $status) {
        $analysis = $batch->analyses()->create([
            'lead_id' => $lead->id, 'provider' => $index === 0 ? 'pottencial' : 'too',
            'product' => 'fianca_locaticia_residencial', 'status' => $status,
            'gross_premium' => $index === 0 ? '1200.00' : null,
            'premium_amount' => '900.00', 'insured_amount' => '50000.00',
            'lease_start_date' => '2026-10-01', 'lease_end_date' => '2027-10-01',
            'response_payload' => $index === 0 ? ['response' => [
                'grossPremium' => '1200.00',
                'riskObjects' => [['coverages' => [['key' => 'Rent', 'insuredAmount' => 50000, 'premiumAmount' => 900]]]],
            ]] : ['quote_latest' => ['response' => ['response' => [
                'condicoesPagamento' => ['premioBrutoTotal' => '1.100,50', 'premioLiquido' => 1000, 'quantidadeParcelas' => 12],
                'inicioVigencia' => '2026-10-01', 'fimVigencia' => '2027-10-01',
                'coberturas' => [
                    ['tipo' => 'Aluguel', 'verba' => 1500, 'premioLiquido' => 800, 'periodoIndenitario' => 12, 'contratada' => 'True'],
                    ['tipo' => 'Condominio', 'verba' => 250, 'premioLiquido' => 200, 'contratada' => 'True'],
                ],
            ]]]],
        ]);
        $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'prepare']]);
    }

    return $batch;
}

it('selects the cheapest approved gross total and keeps all of its coverages', function () {
    $prepared = app(AnalysisResultPreparationService::class)->prepare(preparationBatch(), 'prepare');

    expect($prepared['document_type'])->toBe('own_pdf')
        ->and($prepared['best_quote']['provider'])->toBe('too')
        ->and($prepared['best_quote']['price']['total'])->toBe('1100.50')
        ->and($prepared['best_quote']['coverages'])->toHaveCount(2)
        ->and($prepared['best_quote']['coverages'][0]['declared_amount'])->toBe('1500.00')
        ->and($prepared['best_quote']['coverages'][0]['insured_amount'])->toBeNull()
        ->and($prepared['best_quote']['coverages'][0]['contracted'])->toBeTrue()
        ->and($prepared['other_quotes'][0]['price']['total'])->toBe('1200.00')
        ->and($prepared['other_quotes'][0])->not->toHaveKey('coverages')
        ->and($prepared['comparison_issue'])->toBeNull();
    Http::assertNothingSent();
});

it('routes documents only from terminal decisions', function (array $statuses, string $documentType) {
    $prepared = app(AnalysisResultPreparationService::class)->prepare(preparationBatch($statuses), 'prepare');
    expect($prepared['document_type'])->toBe($documentType);
    foreach ($prepared['quotes'] as $quote) {
        if ($quote['status'] !== 'approved') {
            expect($quote['price']['total'])->toBeNull()->and($quote['coverages'])->toBe([]);
        }
    }
})->with([
    [['approved', 'rejected'], 'own_pdf'],
    [['approved', 'failed'], 'own_pdf'],
    [['rejected', 'rejected'], 'refusal_letters'],
    [['rejected', 'failed'], 'none'],
]);

it('does not rank missing or invalid prices as the cheapest quote', function (mixed $total) {
    $batch = preparationBatch();
    $batch->analyses()->where('provider', 'pottencial')->first()->update(['gross_premium' => null, 'response_payload' => ['grossPremium' => $total]]);
    $prepared = app(AnalysisResultPreparationService::class)->prepare($batch, 'prepare');
    expect($prepared['best_quote'])->toBeNull()->and($prepared['comparison_issue'])->toBe('missing_confirmed_total');
})->with([null, 0, -1, 'invalid']);

it('does not compare different periods', function () {
    $batch = preparationBatch();
    $batch->analyses()->where('provider', 'pottencial')->update(['lease_end_date' => '2029-10-01']);
    $prepared = app(AnalysisResultPreparationService::class)->prepare($batch, 'prepare');
    expect($prepared['best_quote'])->toBeNull()->and($prepared['comparison_issue'])->toBe('incomparable_periods');
});

it('does not compare prices in different currencies', function () {
    $batch = preparationBatch();
    $batch->analyses()->where('provider', 'pottencial')->first()->update(['response_payload' => ['grossPremium' => 100, 'currency' => 'USD']]);
    $prepared = app(AnalysisResultPreparationService::class)->prepare($batch, 'prepare');
    expect($prepared['best_quote'])->toBeNull()->and($prepared['comparison_issue'])->toBe('incomparable_currencies');
});

it('addresses only the linked company principal and sectors without duplicate emails or tenant copies', function () {
    $batch = preparationBatch();
    $company = Imobiliaria::factory()->create(['email' => 'Principal@example.test']);
    $company->setores()->createMany([
        ['key' => 'financeiro', 'name' => 'Financeiro', 'email' => 'financeiro@example.test'],
        ['key' => 'comercial', 'name' => 'Comercial', 'email' => ' principal@example.test '],
        ['key' => 'outro', 'name' => 'Outro', 'email' => 'invalid'],
    ]);
    $batch->lead->update(['company_id' => $company->id, 'tipo_solicitante' => 'imobiliaria_cadastrada']);
    $prepared = app(AnalysisResultPreparationService::class)->prepare($batch, 'prepare');
    expect($prepared['recipients'])->toBe(['to' => ['principal@example.test', 'financeiro@example.test'], 'cc' => []]);
});

it('resolves the responsible person for each requester type without falling back to the tenant', function (string $type, array $expected) {
    $batch = preparationBatch();
    $lead = $batch->lead;
    $lead->update(['tipo_solicitante' => $type]);
    $lead->locador()->create(['nome' => 'Owner', 'email' => 'owner@example.test']);
    $lead->imobiliariaInformada()->create(['responsavel_preenchimento' => 'agent@example.test']);
    expect(app(AnalysisResultRecipients::class)->resolve($lead))->toBe(['to' => $expected, 'cc' => []]);
})->with([
    ['locador', ['owner@example.test']],
    ['imobiliaria_nao_cadastrada', ['agent@example.test']],
    ['locatario', ['tenant@example.test']],
    ['imobiliaria_cadastrada', []],
]);

it('keeps the prepared data unchanged on retry and rejects an obsolete attempt', function () {
    $batch = preparationBatch();
    $service = app(AnalysisResultPreparationService::class);
    $first = $service->prepare($batch, 'prepare');
    $batch->lead->update(['email' => 'changed@example.test']);
    $batch->analyses()->update(['premium_amount' => 9999]);
    expect($service->prepare($batch, 'prepare'))->toBe($first)
        ->and(InsuranceAnalysisEvent::query()->where('event_type', 'result_prepared')->count())->toBe(1);
    $batch->analyses()->first()->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new']]);
    expect(fn () => $service->prepare($batch, 'prepare'))->toThrow(ObsoleteInsuranceAnalysisAttempt::class);
    expect($service->prepare($batch, 'new')['recipients']['to'])->toBe(['changed@example.test']);
});

it('refuses incomplete packages even when the package status says completed', function () {
    $batch = preparationBatch(['approved', 'pending']);
    expect(fn () => app(AnalysisResultPreparationService::class)->prepare($batch, 'prepare'))->toThrow(RuntimeException::class)
        ->and(InsuranceAnalysisEvent::query()->where('event_type', 'result_prepared')->exists())->toBeFalse();
});

it('uses the saved recipient and final results in the queued email', function () {
    Storage::fake('local');
    $batch = preparationBatch();
    app(AnalysisResultPreparationService::class)->prepare($batch, 'prepare');
    $batch->lead->update(['email' => 'changed@example.test', 'nome' => 'Changed']);
    $batch->analyses()->update(['premium_amount' => 9999]);
    $this->mock(\App\Services\Insurance\AnalysisDocumentService::class)->shouldReceive('generate')->once()->andReturn([]);
    Mail::shouldReceive('raw')->once()->withArgs(function (string $body, Closure $callback): bool {
        $email = new Email;
        $callback(new Message($email));
        expect($email->getTo()[0]->getAddress())->toBe('tenant@example.test')
            ->and($email->getCc())->toBe([])
            ->and($body)->toContain('Tenant', '900,00')->not->toContain('9.999,00');

        return true;
    });

    (new SendAnalysisResultsEmailJob($batch->id, 'prepare'))->handle();
    expect($batch->fresh()->email_status)->toBe('sent');
});
