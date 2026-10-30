<?php

use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\StartInsuranceAnalysesBatchJob;
use App\Models\InsuranceAnalysis;
use App\Models\Lead;
use App\Services\Insurance\Payloads\RentalGuaranteeQuotePayloadBuilder;
use App\Services\Insurance\Payloads\TooRentalGuaranteePayloadBuilder;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use App\Services\Insurance\Providers\TooInsuranceProvider;
use App\Services\Insurance\TooService;
use App\Services\Lookup\CpfLookupService;
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
        'services.too.default_commission_percentage' => 15.0,
        'services.too.default_rent_adjustment_index' => 'IGP_M',
        'services.too.default_indemnity_period' => 30,
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

it('applies the Pottencial agents payment and coverage rules by form origin', function (string $origin) {
    config(['services.pottencial.default_commission' => 0.15, 'services.pottencial.default_policy_type' => 'Unico']);
    $analysis = alignedInsuranceAnalysis();
    $company = \App\Models\Imobiliaria::factory()->create(['cnpj' => '11222333000181']);
    $analysis->lead->update(['tipo_solicitante' => $origin, 'company_id' => $company->id]);
    $analysis->lead->despesas->update(['valor_condominio' => 200, 'valor_iptu' => 100, 'valor_gas' => 50, 'valor_agua' => null, 'valor_luz' => null]);
    $analysis->forceFill(['installments' => 30, 'multiple' => 30, 'lease_start_date' => '2026-10-29', 'lease_end_date' => '2029-04-29', 'plan_key' => 'Traditional']);
    $payload = app(RentalGuaranteeQuotePayloadBuilder::class)->build($analysis);
    $agents = [[
        'documentNumber' => '11222333000181', 'role' => 'Broker',
        'commissionPercentage' => 0.15, 'lead' => true, 'participationPercentage' => 1,
    ]];
    if ($origin === 'imobiliaria_cadastrada') {
        $agents[] = ['documentNumber' => '11222333000181', 'role' => 'PolicyOwner'];
    }
    expect($payload['commissionedAgents'])->toBe($agents)
        ->and($payload['participants'])->toBe([['documentNumber' => '07234828702', 'role' => 'PolicyHolder']])
        ->and($payload['paymentConditions'])->toBe(['paymentType' => $origin === 'imobiliaria_cadastrada' ? 'Invoice' : 'Boleto', 'installments' => 30])
        ->and($payload['policyType'])->toBe('Unique')
        ->and($payload['policyPeriodStart'])->toBe('2026-10-29')
        ->and($payload['policyPeriodEnd'])->toBe('2029-04-29');
    $risk = $payload['riskObjects'][0];
    if ($origin === 'locatario') {
        expect($risk['coverages'])->toBe([['key' => 'basica', 'insuredAmount' => 45000.0]])
            ->and($risk['expenses'])->toBe([['description' => 'VALOR_ALUGUEL', 'value' => 1500.0]]);
    } else {
        expect(array_column($risk['coverages'], 'key'))->toContain('danos', 'pintura', 'multa-rescisao', 'agua', 'luz');
    }
    Http::assertNothingSent();
})->with(['imobiliaria_cadastrada', 'imobiliaria_nao_cadastrada', 'locador', 'locatario']);

it('rejects invalid Pottencial agent and contract settings before any request', function (string $invalid) {
    $analysis = alignedInsuranceAnalysis();
    switch ($invalid) {
        case 'broker':
            config(['services.pottencial.broker_document' => '00000000000000']);
            break;
        case 'company':
            $company = \App\Models\Imobiliaria::factory()->create(['cnpj' => '00000000000000']);
            $analysis->lead->update(['tipo_solicitante' => 'imobiliaria_cadastrada', 'company_id' => $company->id]);
            break;
        case 'missing company':
            $analysis->lead->update(['tipo_solicitante' => 'imobiliaria_cadastrada']);
            break;
        case 'commission':
            config(['services.pottencial.default_commission' => 15]);
            break;
        case 'commission precision':
            config(['services.pottencial.default_commission' => 0.1501]);
            break;
        case 'plan':
            $analysis->plan_key = 'unknown';
            break;
        case 'policy':
            config(['services.pottencial.default_policy_type' => 'unknown']);
            break;
    }
    expect(fn () => app(RentalGuaranteeQuotePayloadBuilder::class)->build($analysis))
        ->toThrow(\App\Exceptions\InvalidInsuranceAnalysisPayload::class);
    Http::assertNothingSent();
})->with(['broker', 'company', 'missing company', 'commission', 'commission precision', 'plan', 'policy']);

it('preserves Pottencial payment rules when creating a legacy execution without configured defaults', function (string $origin) {
    $source = alignedInsuranceAnalysis();
    $source->lead->update(['tipo_solicitante' => $origin]);
    config(['services.pottencial' => \Illuminate\Support\Arr::except(config('services.pottencial'), ['default_installments', 'default_plan_key'])]);
    config(['services.pottencial.default_payment_type' => 'AutomaticDebit']);

    $analysis = app(\App\Services\Insurance\InsuranceAnalysisService::class)->createPendingAnalysis($source->lead);

    expect($analysis->installments)->toBe(30)
        ->and($analysis->plan_key)->toBe('Traditional')
        ->and($analysis->payment_type)->toBe($origin === 'imobiliaria_cadastrada' ? 'Invoice' : 'Boleto')
        ->and($analysis->lease_end_date->toDateString())->toBe($analysis->lease_start_date->copy()->addMonthsNoOverflow(30)->toDateString());
    Http::assertNothingSent();
})->with(['imobiliaria_cadastrada', 'imobiliaria_nao_cadastrada', 'locador', 'locatario']);

it('accepts the documented Pottencial commission precision', function () {
    config(['services.pottencial.default_commission' => 0.155]);
    $payload = app(RentalGuaranteeQuotePayloadBuilder::class)->build(alignedInsuranceAnalysis());
    expect($payload['commissionedAgents'][0]['commissionPercentage'])->toBe(0.155);
    Http::assertNothingSent();
});

it('builds the Pottencial minimal participant without optional contact data and canonicalizes plans', function (string $plan, string $expected) {
    $analysis = alignedInsuranceAnalysis();
    $analysis->lead->forceFill(['nome' => null, 'email' => null]);
    $analysis->plan_key = $plan;
    config(['services.pottencial.default_installments' => 30]);
    $payload = app(RentalGuaranteeQuotePayloadBuilder::class)->build($analysis);
    expect($payload['participants'])->toBe([['documentNumber' => '07234828702', 'role' => 'PolicyHolder']])
        ->and($payload['riskObjects'][0]['planKey'])->toBe($expected)
        ->and($payload['paymentConditions']['installments'])->toBe(30);
    Http::assertNothingSent();
})->with([['basic', 'Basic'], ['Complete', 'Complete'], ['traditional', 'Traditional']]);

it('builds Too coverages and PDF conditions by origin with the frozen lease dates', function (string $origin) {
    $analysis = alignedInsuranceAnalysis();
    $analysis->lead->forceFill(['tipo_solicitante' => $origin, 'company_id' => 1]);
    $analysis->lead->despesas->forceFill(['valor_agua' => null, 'valor_luz' => null, 'valor_condominio' => 300]);
    $analysis->forceFill(['lease_start_date' => '2026-08-31', 'lease_end_date' => '2029-02-28']);
    $builder = app(TooRentalGuaranteePayloadBuilder::class);
    $quote = $builder->buildQuotePayload($analysis, '123');
    expect($quote)->toMatchArray(['numeroFicha' => 123, 'percentualComissao' => 15.0, 'indiceDeReajusteAluguel' => 'IGP_M', 'periodoIndenitario' => 30, 'inicioVigenciaContratoLocacao' => '2026-08-31', 'finalVigenciaContratoLocacao' => '2029-02-28']);
    foreach ([$quote, $builder->buildFichaPayload($analysis), $builder->buildBasicDataPayload($analysis)] as $payload) {
        if ($origin === 'locatario') {
            expect($payload['coberturas'])->toBe(['valorAluguel' => 1500.0]);
        } else {
            expect($payload['coberturas'])->toMatchArray(['valorDanosAoImovel' => 1500.0, 'valorMultasContratuais' => 1500.0, 'valorPinturaExterna' => 1500.0, 'valorPinturaInterna' => 1500.0, 'valorAgua' => 150.0, 'valorLuz' => 150.0]);
        }
    }
    expect($builder->buildQuotePdfPayload($analysis, '123'))->toBe([
        'numeroFicha' => 123,
        'formaPagamento' => ['tipoPagamento' => $origin === 'imobiliaria_cadastrada' ? 2 : 1, 'tipoEntrada' => 2, 'opcaoParcelamento' => [['tipoOpcaoParcelamento' => 2, 'quantidadeParcelas' => 30]]],
    ]);
    Http::assertNothingSent();
})->with(['imobiliaria_cadastrada', 'imobiliaria_nao_cadastrada', 'locador', 'locatario']);

it('rejects invalid Too commission settings before sending a quote', function (mixed $commission) {
    config(['services.too.default_commission_percentage' => $commission]);
    expect(fn () => app(TooRentalGuaranteePayloadBuilder::class)->buildQuotePayload(alignedInsuranceAnalysis(), 123))->toThrow(\App\Exceptions\InvalidInsuranceAnalysisPayload::class);
    Http::assertNothingSent();
})->with([0.15, 9.99, 40.01, 'invalid']);

it('does not count excluded tenant expenses toward the Too limit', function () {
    $analysis = alignedInsuranceAnalysis();
    $analysis->lead->tipo_solicitante = 'locatario';
    $analysis->lead->despesas->forceFill(['valor_aluguel' => 24000, 'valor_condominio' => 10000, 'valor_agua' => 10, 'valor_luz' => null]);
    expect(app(TooRentalGuaranteePayloadBuilder::class)->buildFichaPayload($analysis)['coberturas'])->toBe(['valorAluguel' => 24000.0]);
});

it('posts the Too PDF payment payload and validates the base64 document', function (?string $encoded, bool $valid) {
    config(['services.too.base_url' => 'https://too.example.test', 'services.too.client_id' => 'test', 'services.too.client_secret' => 'test']);
    \Illuminate\Support\Facades\Cache::put('too_access_token', 'test-token', 60);
    Http::fake(['https://too.example.test/fianca/proposta/cotacao/456/pdf' => Http::response(['result' => ['pdfCotacao' => $encoded]])]);
    $payload = app(TooRentalGuaranteePayloadBuilder::class)->buildQuotePdfPayload(alignedInsuranceAnalysis(), 123);
    $result = app(TooService::class)->getQuotePdf(456, $payload);
    expect($result['success'])->toBe($valid);
    if ($valid) {
        expect($result['pdf_content'])->toBe("%PDF-1.7\n%%EOF");
    } else {
        expect($result)->not->toHaveKey('pdf_content');
    }
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->data() === $payload && $request->hasHeader('Authorization', 'Bearer test-token'));
    Http::assertSentCount(1);
})->with([[base64_encode("%PDF-1.7\n%%EOF"), true], [null, false], ['!!!', false], [base64_encode('not a PDF'), false]]);
