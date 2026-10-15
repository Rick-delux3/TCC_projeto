<?php

use App\Enums\TipoVinculoEmpresa;
use App\Exceptions\InvalidInsuranceAnalysisPayload;
use App\Models\AtividadeEconomicaCodigo;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Services\CompanyApplicantService;
use App\Services\Insurance\Payloads\TokioCompanyApplicantsPayloadBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->withoutVite();
    config(['features.insurance_analysis.enabled' => false, 'services.leadlovers.enabled' => false]);
    Queue::fake();
    Http::preventStrayRequests();
    $this->activityId = AtividadeEconomicaCodigo::factory()->create()->atividade_economica_id;
});

function companyApplicantPayload(array $overrides = []): array
{
    return array_replace([
        'aceite_termos' => '1', 'nome' => 'Empresa pretendente', 'email' => 'applicants@example.test',
        'tel' => '11988887777', 'cpf' => '11.222.333/0001-81', 'data_nascimento' => '1992-02-29',
        'cpf_responsavel' => '111.444.777-35', 'nome_responsavel' => 'Titular da empresa',
        'tipo_locacao' => 'comercial', 'empresa_constituida' => 'S', 'estado_civil' => 'casado',
        'conjuge_nome' => 'Cônjuge da pessoa titular', 'conjuge_cpf' => '529.982.247-25',
        'atividade_economica_id' => test()->activityId,
        'valor_aluguel' => '1500', 'cep' => '01001000', 'logradouro' => 'Praça da Sé',
        'numero' => '100', 'bairro' => 'Sé', 'cidade_imovel' => 'São Paulo', 'estado' => 'SP',
        'tipo_vinculo' => 'socio', 'tipoVinculo' => 21150, 'estadoCivil' => '19901',
        'pretendentePrincipal' => 'N', 'conjuge_tipo_vinculo' => 'titular',
    ], $overrides);
}

function companyApplicantAdmin(): Corretor
{
    return Corretor::query()->create([
        'name' => 'Administrador', 'email' => 'applicants-admin@example.test', 'password' => 'password',
        'role' => Corretor::ROLE_CEO, 'permissions' => [], 'active' => true, 'first_login_verified_at' => now(),
    ]);
}

it('persists automatic company roles and maps only Tokio to numeric domains', function (string $profile, string $status): void {
    $payload = companyApplicantPayload(['estado_civil' => $status]);
    $route = 'simulation.tenant.store';
    if ($profile === 'imobiliaria_cadastrada') {
        $company = Imobiliaria::factory()->create(['lead_access_code' => 'ROLES1']);
        $this->post(route('simulation.registered-company.verify'), ['lead_access_code' => 'ROLES1'])->assertRedirect();
        $payload['registered_company_context'] = $company->id;
        $route = 'simulation.registered-company.store';
    } elseif ($profile !== 'locatario') {
        $route = 'simulation.unregistered-company.store';
        $payload = array_replace($payload, [
            'responsavel_tipo' => $profile, 'responsavel_nome' => 'Responsável',
            'responsavel_email' => 'responsavel@example.test', 'responsavel_telefone' => '11988887777',
        ]);
    }
    $this->post(route($route), $payload)->assertSessionHasNoErrors()->assertRedirect(route('simulation.success'));
    $lead = Lead::query()->sole();
    $hasSpouse = $status !== 'solteiro';
    expect($lead->tipo_vinculo)->toBe(TipoVinculoEmpresa::Titular)
        ->and($lead->estado_civil)->toBe($status);
    $this->assertDatabaseHas('leads', ['id' => $lead->id, 'tipo_vinculo' => 'titular']);
    if ($hasSpouse) {
        expect($lead->conjuge->tipo_vinculo)->toBe(TipoVinculoEmpresa::Socio);
        $this->assertDatabaseHas('lead_conjugues', ['lead_id' => $lead->id, 'tipo_vinculo' => 'socio']);
    } else {
        expect($lead->conjuge)->toBeNull();
    }

    $descriptions = app(CompanyApplicantService::class)->descriptions($lead);
    expect($descriptions[0]['tipo_vinculo'])->toBe('Titular')
        ->and($descriptions[0]['estado_civil'])->toBe(match ($status) {
            'casado' => 'Casado(a)', 'uniao_estavel' => 'União estável', 'solteiro' => 'Solteiro(a)',
        });
    if ($hasSpouse) {
        expect($descriptions[1]['tipo_vinculo'])->toBe('Sócio');
    }

    $shared = ['rendaFixaMensal' => '6000.00', 'respondeFinanceiramente' => 'S', 'CNAE' => 1234567];
    $applicants = app(TokioCompanyApplicantsPayloadBuilder::class)->build($lead, array_merge($shared, [
        'cpf' => '00000000000', 'nome' => 'Nome adulterado', 'tipoVinculo' => 21150,
        'estadoCivil' => '19901', 'pretendentePrincipal' => 'N', 'cnpj' => '00000000000000',
    ]));
    expect($applicants)->toHaveCount($hasSpouse ? 2 : 1)
        ->and($applicants[0])->toMatchArray([
            ...$shared, 'cpf' => '11144477735', 'nome' => 'Titular da empresa', 'cnpj' => '11222333000181',
            'pretendentePrincipal' => 'S', 'tipoVinculo' => 21147, 'vinculoEmpresa' => 'S',
            'estadoCivil' => $hasSpouse ? '18490' : '18485',
        ]);
    if ($hasSpouse) {
        expect($applicants[1])->toBe(array_replace($applicants[0], [
            'cpf' => '52998224725', 'nome' => 'Cônjuge da pessoa titular',
            'pretendentePrincipal' => 'N', 'tipoVinculo' => 21149,
        ]));
    }
    expect(array_keys($applicants[0]))->not->toContain('cpfConjuge');
    Http::assertNothingSent();
})->with(['locatario', 'locador', 'imobiliaria_nao_cadastrada', 'imobiliaria_cadastrada'])
    ->with(['solteiro', 'casado', 'uniao_estavel']);

it('does not assign company roles outside the defined scenarios', function (array $changes): void {
    $this->post(route('simulation.tenant.store'), companyApplicantPayload($changes))->assertSessionHasNoErrors();
    $lead = Lead::query()->sole();
    expect($lead->tipo_vinculo)->toBeNull()->and($lead->conjuge?->tipo_vinculo)->toBeNull();
    expect(fn () => app(TokioCompanyApplicantsPayloadBuilder::class)->build($lead))
        ->toThrow(InvalidInsuranceAnalysisPayload::class);
})->with([
    [['cpf' => '01234567890']], [['tipo_locacao' => 'residencial']],
    [['estado_civil' => 'divorciado']], [['estado_civil' => 'viuvo']], [['estado_civil' => null]],
]);

it('rejects missing and duplicate spouse CPFs for the company representative', function (mixed $cpf): void {
    $this->postJson(route('simulation.tenant.store'), companyApplicantPayload(['conjuge_cpf' => $cpf]))
        ->assertUnprocessable()->assertJsonValidationErrors('conjuge_cpf');
    expect(Lead::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([null, '111.444.777-35', '11111111111']);

it('recomputes company roles when the administrator resubmits a simulation', function (): void {
    $this->actingAs(companyApplicantAdmin(), 'admin');
    $url = route('admin.simulations.unlinked.store', ['tipo' => 'locatario']);
    $this->post($url, companyApplicantPayload())->assertSessionHasNoErrors();
    $lead = Lead::query()->sole();
    foreach ([
        ['estado_civil' => 'solteiro'], ['estado_civil' => 'uniao_estavel'],
        ['tipo_locacao' => 'residencial'], ['cpf' => '01234567890'],
    ] as $changes) {
        $this->post($url, companyApplicantPayload($changes))->assertSessionHasNoErrors();
        $lead->refresh();
        $eligible = ! isset($changes['tipo_locacao']) && ! isset($changes['cpf']);
        expect($lead->tipo_vinculo)->toBe($eligible ? TipoVinculoEmpresa::Titular : null)
            ->and($lead->conjuge?->tipo_vinculo)->toBe($eligible && ($changes['estado_civil'] ?? '') === 'uniao_estavel' ? TipoVinculoEmpresa::Socio : null);
    }
});

it('preserves existing leads on public duplicate submission', function (): void {
    $this->post(route('simulation.tenant.store'), companyApplicantPayload())->assertSessionHasNoErrors();
    $this->post(route('simulation.tenant.store'), companyApplicantPayload(['estado_civil' => 'solteiro']))->assertSessionHasNoErrors();
    $lead = Lead::query()->sole();
    expect($lead->estado_civil)->toBe('casado')->and($lead->conjuge->tipo_vinculo)->toBe(TipoVinculoEmpresa::Socio);
});

it('refreshes identities and clears roles after an admin lead edit', function (): void {
    $this->post(route('simulation.tenant.store'), companyApplicantPayload())->assertSessionHasNoErrors();
    $lead = Lead::query()->sole();
    $this->actingAs(companyApplicantAdmin(), 'admin');
    $url = route('admin.leads.update', $lead);
    $this->post($url, ['cpf_responsavel' => '01234567890', 'nome_responsavel' => 'Novo titular'])->assertSessionHasNoErrors();
    $applicants = app(TokioCompanyApplicantsPayloadBuilder::class)->build($lead->fresh());
    expect($applicants[0]['cpf'])->toBe('01234567890')->and($applicants[0]['nome'])->toBe('Novo titular');
    $this->post($url, ['tipo_locacao' => 'residencial'])->assertSessionHasNoErrors();
    $lead->refresh();
    expect($lead->tipo_vinculo)->toBeNull()->and($lead->conjuge->tipo_vinculo)->toBeNull();
});

it('rejects an admin edit that makes the representative and spouse the same person', function (array $changes): void {
    $this->post(route('simulation.tenant.store'), companyApplicantPayload())->assertSessionHasNoErrors();
    $lead = Lead::query()->sole();
    $this->actingAs(companyApplicantAdmin(), 'admin')->postJson(route('admin.leads.update', $lead), $changes)
        ->assertUnprocessable()->assertJsonValidationErrors('conjuge_cpf');
    expect($lead->fresh()->lead_empresa->cpf_responsavel)->toBe('11144477735')
        ->and($lead->fresh()->conjuge->cpf)->toBe('52998224725');
})->with([
    [['conjuge_cpf' => '11144477735', 'conjuge_nome' => 'Cônjuge']],
    [['cpf_responsavel' => '52998224725', 'nome_responsavel' => 'Titular']],
]);

it('refuses corrupt stored applicants before producing a Tokio payload', function (string $corruption): void {
    $this->post(route('simulation.tenant.store'), companyApplicantPayload())->assertSessionHasNoErrors();
    $lead = Lead::query()->sole();
    if ($corruption === 'missing') {
        $lead->conjuge()->delete();
    } else {
        $lead->conjuge()->update(['cpf' => $corruption]);
    }
    expect(fn () => app(TokioCompanyApplicantsPayloadBuilder::class)->build($lead->fresh()))
        ->toThrow(InvalidInsuranceAnalysisPayload::class);
})->with(['missing', '11144477735', 'invalid']);

it('backfills existing company roles and can reverse the schema change', function (): void {
    $this->post(route('simulation.tenant.store'), companyApplicantPayload())->assertSessionHasNoErrors();
    $this->post(route('simulation.tenant.store'), companyApplicantPayload([
        'email' => 'residential@example.test', 'tipo_locacao' => 'residencial',
    ]))->assertSessionHasNoErrors();
    $migration = require database_path('migrations/2026_10_15_120654_add_company_roles_to_leads_and_spouses.php');
    $migration->down();
    expect(\Illuminate\Support\Facades\Schema::hasColumn('leads', 'tipo_vinculo'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('lead_conjugues', 'tipo_vinculo'))->toBeFalse();
    $migration->up();
    $lead = Lead::query()->where('email', 'applicants@example.test')->sole();
    expect($lead->tipo_vinculo)->toBe(TipoVinculoEmpresa::Titular)
        ->and($lead->conjuge->tipo_vinculo)->toBe(TipoVinculoEmpresa::Socio)
        ->and(Lead::query()->where('email', 'residential@example.test')->sole()->tipo_vinculo)->toBeNull();
});
