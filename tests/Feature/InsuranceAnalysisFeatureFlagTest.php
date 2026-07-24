<?php

use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Jobs\StartInsuranceAnalysesBatchJob;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'features.insurance_analysis.enabled' => false,
        'services.leadlovers.enabled' => false,
        'services.pottencial.enabled' => false,
        'services.too.enabled' => false,
    ]);

    Bus::fake();
    Http::fake();
    Http::preventStrayRequests();
});

it('keeps public forms available while insurance analyses are disabled', function (string $route) {
    $this->get(route($route))->assertOk();
})->with([
    'simulation start' => 'simulation.start',
    'registered company access' => 'simulation.registered-company.access',
    'tenant form' => 'simulation.tenant.form',
]);

it('creates a company lead and sends it to LeadLovers without starting analyses', function () {
    $company = Imobiliaria::create([
        'name' => 'Imobiliária Formulário',
        'email' => 'form-company@example.test',
        'phone' => '11999999999',
        'password' => bcrypt('password'),
        'city' => 'São Paulo',
        'state' => 'SP',
        'lead_access_code' => 'ABC234',
        'lead_form_active' => true,
    ]);

    $response = $this->post(
        route('simulation.registered-company.store', ['code' => $company->lead_access_code]),
        [
            'aceite_termos' => '1',
            'nome' => 'Pessoa do Formulário',
            'email' => 'new-lead@example.test',
            'tel' => '11988887777',
            'estado_civil' => 'solteiro',
            'valor_aluguel' => '1500',
            'cep' => '01001000',
            'logradouro' => 'Praça da Sé',
            'numero' => '100',
            'bairro' => 'Sé',
            'cidade_imovel' => 'São Paulo',
            'estado' => 'SP',
        ]
    );

    $response->assertRedirect(route('simulation.success'));

    $lead = Lead::query()
        ->where('email', 'new-lead@example.test')
        ->firstOrFail();

    expect($lead->company_id)->toBe($company->id)
        ->and($lead->origem)->toBe('imobiliaria_cadastrada')
        ->and($lead->tipo_solicitante)->toBe('imobiliaria_cadastrada')
        ->and(InsuranceAnalysisBatch::query()->count())->toBe(0)
        ->and(InsuranceAnalysis::query()->count())->toBe(0);

    Bus::assertDispatched(
        SendLeadToLeadLoversJob::class,
        fn (SendLeadToLeadLoversJob $job) => $job->leadId === $lead->id
    );
    Bus::assertNotDispatched(StartInsuranceAnalysesBatchJob::class);
    Bus::assertNotDispatched(RunProviderAnalysisJob::class);
    Http::assertNothingSent();
});

it('ignores queued analysis jobs that remained in the queue', function () {
    $resolver = app(InsuranceProviderResolver::class);

    (new StartInsuranceAnalysesBatchJob(999))->handle($resolver);
    (new RunProviderAnalysisJob(999, 'disabled-attempt'))->handle($resolver);

    expect(InsuranceAnalysisBatch::query()->count())->toBe(0)
        ->and(InsuranceAnalysis::query()->count())->toBe(0);

    Http::assertNothingSent();
});

it('resolves only individually enabled providers when analyses are reactivated', function () {
    config([
        'features.insurance_analysis.enabled' => true,
        'services.pottencial.enabled' => true,
        'services.too.enabled' => false,
    ]);

    expect(app(InsuranceProviderResolver::class)->availableProviders())
        ->toBe(['pottencial']);
});
