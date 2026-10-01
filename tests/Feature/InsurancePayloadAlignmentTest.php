<?php

use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\StartInsuranceAnalysesBatchJob;
use App\Models\InsuranceAnalysis;
use App\Models\Lead;
use App\Services\CpfLookupService;
use App\Services\Insurance\Payloads\RentalGuaranteeQuotePayloadBuilder;
use App\Services\Insurance\Payloads\TooRentalGuaranteePayloadBuilder;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use App\Services\Insurance\Providers\TooInsuranceProvider;
use App\Services\TooService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    config([
        'queue.default' => 'database',
        'features.insurance_analysis.enabled' => true,
        'services.too.enabled' => true,
        'services.pottencial.enabled' => true,
        'services.too.broker_cnpj' => '11222333000181',
        'services.too.broker_name' => 'Corretora de teste',
        'services.too.default_employment' => 'Autonomo',
        'services.too.default_profession' => 'Consultor',
        'services.too.default_reside_property' => true,
        'services.pottencial.broker_document' => '11222333000181',
    ]);
    $this->mock(CpfLookupService::class)->shouldNotReceive('birthdateForToo');
});

function alignedInsuranceAnalysis(bool $company = false, ?string $rentalType = 'residencial'): InsuranceAnalysis
{
    $lead = Lead::query()->create([
        'nome' => $company ? 'Empresa locatária' : 'Pessoa locatária',
        'email' => 'payload@example.test',
        'tel' => '11999998888',
        'cpf' => $company ? null : '07234828702',
        'data_nascimento' => '1992-02-29',
        'tipo_locacao' => $rentalType,
        'tipo_solicitante' => 'locador',
    ]);
    if ($company) {
        $lead->lead_empresa()->create([
            'cnpj' => '11222333000181',
            'cpf_responsavel' => '07234828702',
            'nome_responsavel' => 'Representante da empresa',
        ]);
    }
    $lead->endereco()->create([
        'cep' => '01001000', 'logradouro' => 'Praça da Sé', 'numero' => '100',
        'bairro' => 'Sé', 'cidade_imovel' => 'São Paulo', 'estado' => 'SP',
    ]);
    $lead->despesas()->create(['valor_aluguel' => 1500, 'valor_agua' => 0, 'valor_luz' => 0]);

    $analysis = InsuranceAnalysis::query()->create([
        'lead_id' => $lead->id, 'provider' => 'too', 'status' => 'pending',
        'product' => $lead->rentalGuaranteeProduct(),
    ]);
    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'alignment-attempt']]);

    return $analysis;
}

it('aligns both insurers with the document and rental purpose selected on the form', function (bool $company, string $rentalType) {
    $analysis = alignedInsuranceAnalysis($company, $rentalType);
    $too = app(TooRentalGuaranteePayloadBuilder::class);
    foreach ([$too->buildFichaPayload($analysis), $too->buildBasicDataPayload($analysis)] as $payload) {
        expect($payload['pretendentes'][0])->toMatchArray([
            'cpf' => '07234828702',
            'nome' => $company ? 'Representante da empresa' : 'Pessoa locatária',
            'dataNascimento' => '1992-02-29',
            'rendaFixaMensal' => 6000.0,
            'vinculoEmpregaticio' => 'Autonomo',
            'profissao' => 'Consultor',
            'residiraImovel' => $rentalType === 'residencial',
        ])->and($payload['locacao']['finalidadeLocacao'])->toBe($rentalType === 'residencial' ? 'Residencial' : 'Comercial');
    }
    $quote = $too->buildQuotePayload($analysis, 123);
    expect($quote['inicioVigenciaContratoLocacao'])->toBe(now()->toDateString())
        ->and($quote['coberturas']['valorAluguel'])->toBe(1500.0)
        ->and($quote['coberturas'])->not->toHaveKey('valorAgua');

    $pottencial = app(RentalGuaranteeQuotePayloadBuilder::class)->build($analysis);
    expect($pottencial['riskObjects'][0]['occupation'])->toBe($rentalType === 'residencial' ? 'Residencial' : 'Commercial')
        ->and($pottencial['riskObjects'][0]['tenantDocumentNumber'])->toBe($company ? '11222333000181' : '07234828702')
        ->and($pottencial['participants'][0]['documentNumber'])->toBe($pottencial['riskObjects'][0]['tenantDocumentNumber'])
        ->and($analysis->lead->canBeSentToToo())->toBeTrue();
    Http::assertNothingSent();
})->with([false, true])->with(['residencial', 'comercial']);

it('uses the representative CPF through Too creation credit analysis and status consultation', function () {
    $analysis = alignedInsuranceAnalysis(true, 'comercial');
    $service = $this->mock(TooService::class);
    $service->shouldReceive('registerProposalFicha')->once()->withArgs(fn (array $payload): bool => $payload['pretendentes'][0]['cpf'] === '07234828702'
        && $payload['locacao']['finalidadeLocacao'] === 'Comercial'
    )->andReturn(['success' => true, 'response' => ['numeroProposta' => 123, 'numeroFicha' => 456]]);
    $service->shouldReceive('submitCreditAnalysis')->once()->with('07234828702', 123)->andReturn(['success' => true]);
    $service->shouldReceive('getProposalStatus')->twice()->with('07234828702', 123)
        ->andReturn(['success' => true, 'response' => ['proposta' => ['status' => 5]]]);

    $provider = app(TooInsuranceProvider::class);
    expect($provider->requestAnalysis($analysis, 'alignment-attempt')['success'])->toBeTrue()
        ->and($provider->getStatus($analysis->fresh())['success'])->toBeTrue();
});

it('uses the same representative and purpose when updating and reanalyzing Too', function () {
    $analysis = alignedInsuranceAnalysis(true, 'comercial');
    $analysis->update(['proposal_id' => '123', 'response_payload' => ['numeroFicha' => 456]]);
    $analysis->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new-attempt', 'is_reanalysis' => true]]);
    $service = $this->mock(TooService::class);
    $service->shouldReceive('getProposalStatus')->once()->with('07234828702', '123')
        ->andReturn(['success' => true, 'response' => ['proposta' => ['status' => 8]]]);
    $service->shouldReceive('updateProposalBasicData')->once()->withArgs(fn ($number, array $payload): bool => $number == 456 && $payload['pretendentes'][0]['nome'] === 'Representante da empresa'
        && $payload['pretendentes'][0]['dataNascimento'] === '1992-02-29'
        && $payload['locacao']['finalidadeLocacao'] === 'Comercial'
    )->andReturn(['success' => true]);
    $service->shouldReceive('submitReanalysis')->once()->with('07234828702', '123', Mockery::type('array'))
        ->andReturn(['success' => true]);

    expect(app(TooInsuranceProvider::class)->requestReanalysis($analysis, 'new-attempt')['success'])->toBeTrue();
});

it('fails Too locally for incomplete or invalid applicant data instead of sending invented data', function (string $missing) {
    $analysis = alignedInsuranceAnalysis(true);
    match ($missing) {
        'birth date' => $analysis->lead->update(['data_nascimento' => null]),
        'future date' => $analysis->lead->update(['data_nascimento' => '2999-01-01']),
        'representative CPF' => $analysis->lead->lead_empresa->update(['cpf_responsavel' => '00000000000']),
        'representative name' => $analysis->lead->lead_empresa->update(['nome_responsavel' => '']),
        'purpose' => $analysis->lead->update(['tipo_locacao' => null]),
        'profession' => config(['services.too.default_profession' => '']),
        'employment' => config(['services.too.default_employment' => 'invalid']),
        'small expense' => $analysis->lead->despesas->update(['valor_agua' => 10]),
    };
    $this->mock(TooService::class)->shouldNotReceive('registerProposalFicha');
    (new RunProviderAnalysisJob($analysis->id, 'alignment-attempt'))->handle(app(InsuranceProviderResolver::class));

    expect($analysis->fresh()->status)->toBe('failed')
        ->and($analysis->fresh()->finished_at)->not->toBeNull();
    Http::assertNothingSent();
})->with(['birth date', 'future date', 'representative CPF', 'representative name', 'purpose', 'profession', 'employment', 'small expense']);

it('rejects a Pottencial applicant without a valid document or rental purpose', function (string $missing) {
    $analysis = alignedInsuranceAnalysis();
    $analysis->lead->update([$missing === 'document' ? 'cpf' : 'tipo_locacao' => null]);
    expect(fn () => app(RentalGuaranteeQuotePayloadBuilder::class)->build($analysis))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with(['document', 'purpose']);

it('selects the rental product for all providers when starting the batch', function (string $rentalType) {
    $analysis = alignedInsuranceAnalysis(true, $rentalType);
    (new StartInsuranceAnalysesBatchJob($analysis->lead_id))->handle(app(InsuranceProviderResolver::class));
    $analyses = InsuranceAnalysis::query()->whereNotNull('insurance_analysis_batch_id')->get();
    expect($analyses)->toHaveCount(2);
    foreach ($analyses as $created) {
        expect($created->product)->toBe('fianca_locaticia_'.$rentalType);
    }
})->with(['residencial', 'comercial']);
