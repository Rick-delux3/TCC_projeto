<?php

use App\Jobs\SendLeadToLeadLoversJob;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['features.insurance_analysis.enabled' => false]);

    Queue::fake();
});

function publicOverwriteCompany(array $overrides = []): Imobiliaria
{
    return Imobiliaria::query()->create(array_merge([
        'name' => 'Imobiliária do teste de segurança',
        'email' => 'security-company@example.test',
        'phone' => '11999999999',
        'password' => 'password',
        'city' => 'São Paulo',
        'state' => 'SP',
        'lead_access_code' => 'SEC123',
        'lead_form_active' => true,
    ], $overrides));
}

function publicOverwritePayload(string $email, array $overrides = []): array
{
    return array_merge([
        'tipo_locacao' => 'residencial',
        'aceite_termos' => '1',
        'nome' => 'Dados enviados por terceiro',
        'email' => $email,
        'tel' => '11911112222',
        'estado_civil' => 'solteiro',
        'valor_aluguel' => '9999',
        'cep' => '22041001',
        'logradouro' => 'Endereço enviado por terceiro',
        'numero' => '999',
        'bairro' => 'Bairro alterado',
        'cidade_imovel' => 'Rio de Janeiro',
        'estado' => 'RJ',
        'observacoes' => 'Observação enviada por terceiro',
    ], $overrides);
}

function publicOverwriteProtectedLead(
    string $email,
    string $origin,
    ?Imobiliaria $company = null
): Lead {
    $lead = Lead::query()->create([
        'company_id' => $company?->id,
        'tipo_solicitante' => $origin,
        'nome' => 'Nome protegido',
        'email' => $email,
        'tel' => '11988887777',
        'estado_civil' => 'casado',
        'status' => 'em_analise',
        'origem' => $origin,
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 9001,
        'sent_to_leadlovers_at' => now()->subDay(),
        'aceite_termos' => true,
        'observacoes' => 'Observação protegida',
        'ip' => '192.0.2.10',
        'user_agent' => 'Agente protegido',
    ]);

    $lead->endereco()->create([
        'cep' => '01001000',
        'logradouro' => 'Endereço protegido',
        'numero' => '100',
        'bairro' => 'Bairro protegido',
        'cidade_imovel' => 'São Paulo',
        'estado' => 'SP',
    ]);

    $lead->despesas()->create([
        'valor_aluguel' => 1200,
        'valor_agua' => 120,
        'valor_luz' => 120,
        'valor_total_encargos' => 1440,
    ]);

    $lead->conjuge()->create([
        'nome' => 'Cônjuge protegido',
        'cpf' => null,
    ]);

    return $lead;
}

function assertPublicSubmissionDidNotOverwrite(Lead $lead): void
{
    $lead->refresh()->load(['endereco', 'despesas', 'conjuge']);

    expect($lead)
        ->nome->toBe('Nome protegido')
        ->tel->toBe('11988887777')
        ->estado_civil->toBe('casado')
        ->status->toBe('em_analise')
        ->leadlovers_status->toBe('sent')
        ->leadlovers_lead_id->toBe(9001)
        ->observacoes->toBe('Observação protegida')
        ->ip->toBe('192.0.2.10')
        ->user_agent->toBe('Agente protegido')
        ->and($lead->endereco)->not->toBeNull()
        ->and($lead->endereco->logradouro)->toBe('Endereço protegido')
        ->and($lead->endereco->cep)->toBe('01001000')
        ->and($lead->despesas)->not->toBeNull()
        ->and($lead->despesas->valor_aluguel)->toBe('1200.00')
        ->and($lead->conjuge)->not->toBeNull()
        ->and($lead->conjuge->nome)->toBe('Cônjuge protegido');
}

it('does not let a public registered-company form overwrite an existing lead', function () {
    $company = publicOverwriteCompany();
    $lead = publicOverwriteProtectedLead(
        'linked-victim@example.test',
        'imobiliaria_cadastrada',
        $company
    );

    $this->post(route('simulation.registered-company.verify'), [
        'lead_access_code' => $company->lead_access_code,
    ])->assertRedirect(route('simulation.registered-company.form'));

    $response = $this->post(
        route('simulation.registered-company.store'),
        publicOverwritePayload(' LINKED-VICTIM@EXAMPLE.TEST ', [
            'registered_company_context' => $company->id,
        ])
    );

    $response
        ->assertRedirect(route('simulation.success'))
        ->assertSessionHas('success');

    assertPublicSubmissionDidNotOverwrite($lead);

    expect(Lead::query()->count())->toBe(1);
    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
    Queue::assertNotPushed(BroadcastEvent::class);
});

it('does not let an unlinked public form overwrite an existing lead', function (
    string $origin,
    string $email,
    string $routeName,
    ?string $responsibleType
) {
    $lead = publicOverwriteProtectedLead($email, $origin);

    if ($origin === 'locador') {
        $lead->locador()->create([
            'nome' => 'Locador protegido',
            'telefone' => '11977776666',
            'email' => 'landlord-owner@example.test',
        ]);
    }

    if ($origin === 'imobiliaria_nao_cadastrada') {
        $lead->imobiliariaInformada()->create([
            'nome_imobiliaria_informada' => 'Imobiliária protegida',
            'responsavel_preenchimento' => 'responsavel-protegido@example.test',
            'telefone_responsavel' => '11977776666',
        ]);
    }

    $payload = publicOverwritePayload(' '.strtoupper($email).' ');

    if ($responsibleType !== null) {
        $payload = array_merge($payload, [
            'responsavel_tipo' => $responsibleType,
            'responsavel_nome' => 'Responsável enviado por terceiro',
            'responsavel_email' => 'third-party@example.test',
            'responsavel_telefone' => '21911112222',
        ]);
    }

    $response = $this->post(route($routeName), $payload);

    $response
        ->assertRedirect(route('simulation.success'))
        ->assertSessionHas('success');

    assertPublicSubmissionDidNotOverwrite($lead);

    if ($origin === 'locador') {
        expect($lead->locador()->first())
            ->nome->toBe('Locador protegido')
            ->telefone->toBe('11977776666')
            ->email->toBe('landlord-owner@example.test');
    }

    if ($origin === 'imobiliaria_nao_cadastrada') {
        expect($lead->imobiliariaInformada()->first())
            ->nome_imobiliaria_informada->toBe('Imobiliária protegida')
            ->responsavel_preenchimento->toBe('responsavel-protegido@example.test')
            ->telefone_responsavel->toBe('11977776666');
    }

    expect(Lead::query()->count())->toBe(1);
    Queue::assertNotPushed(SendLeadToLeadLoversJob::class);
    Queue::assertNotPushed(BroadcastEvent::class);
})->with([
    'tenant' => [
        'locatario',
        'tenant-victim@example.test',
        'simulation.tenant.store',
        null,
    ],
    'unregistered company' => [
        'imobiliaria_nao_cadastrada',
        'unregistered-victim@example.test',
        'simulation.unregistered-company.store',
        'imobiliaria_nao_cadastrada',
    ],
    'landlord' => [
        'locador',
        'landlord-victim@example.test',
        'simulation.unregistered-company.store',
        'locador',
    ],
]);

it('preserves the intentional update of an existing lead by an authenticated admin', function () {
    $admin = Corretor::query()->create([
        'name' => 'Administrador do teste de segurança',
        'email' => 'security-admin@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
    $company = publicOverwriteCompany();
    $lead = publicOverwriteProtectedLead(
        'admin-update@example.test',
        'imobiliaria_cadastrada',
        $company
    );

    $response = $this
        ->actingAs($admin, 'admin')
        ->post(
            route('admin.simulations.registered-company.store', $company),
            publicOverwritePayload('admin-update@example.test', [
                'nome' => 'Atualização administrativa legítima',
            ])
        );

    $response->assertRedirect(route('admin.simulations.complete', [
        'lead' => $lead,
    ]));

    $lead->refresh()->load('endereco');

    expect($lead)
        ->nome->toBe('Atualização administrativa legítima')
        ->data_edited_at->not->toBeNull()
        ->updated_by_corretor_id->toBe($admin->id)
        ->and($lead->endereco->logradouro)->toBe('Endereço enviado por terceiro');
});

it('preserves the intentional update of an unlinked lead by an authenticated admin', function () {
    $admin = Corretor::query()->create([
        'name' => 'Administrador sem vínculo',
        'email' => 'unlinked-security-admin@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
    $lead = publicOverwriteProtectedLead(
        'admin-unlinked-update@example.test',
        'locatario'
    );

    $response = $this
        ->actingAs($admin, 'admin')
        ->post(
            route('admin.simulations.unlinked.store', ['tipo' => 'locatario']),
            publicOverwritePayload('admin-unlinked-update@example.test', [
                'nome' => 'Atualização administrativa sem vínculo',
            ])
        );

    $response->assertRedirect(route('admin.simulations.complete', [
        'lead' => $lead,
    ]));

    expect($lead->refresh())
        ->nome->toBe('Atualização administrativa sem vínculo')
        ->data_edited_at->not->toBeNull()
        ->updated_by_corretor_id->toBe($admin->id);
});

it('does not expose existing-lead updates through an unauthenticated admin route', function () {
    $company = publicOverwriteCompany();
    $lead = publicOverwriteProtectedLead(
        'admin-route-victim@example.test',
        'imobiliaria_cadastrada',
        $company
    );

    $this->post(
        route('admin.simulations.registered-company.store', $company),
        publicOverwritePayload('admin-route-victim@example.test')
    )->assertRedirect(route('admin.login'));

    assertPublicSubmissionDidNotOverwrite($lead);
    Queue::assertNothingPushed();
});

it('marks only actual simulation data edits and keeps filter membership consistent', function (string $profile, string $change): void {
    $this->withoutVite();
    $this->freezeTime();
    $admin = Corretor::query()->create([
        'name' => 'Admin regression', 'email' => 'regression@example.test', 'password' => 'password',
        'role' => Corretor::ROLE_CEO, 'active' => true, 'first_login_verified_at' => now(),
    ]);
    $company = publicOverwriteCompany();
    $url = $profile === 'imobiliaria_cadastrada'
        ? route('admin.simulations.registered-company.store', $company)
        : route('admin.simulations.unlinked.store', ['tipo' => $profile]);
    $payload = publicOverwritePayload('regression-lead@example.test', [
        'responsavel_tipo' => in_array($profile, ['locador', 'imobiliaria_nao_cadastrada'], true) ? $profile : null,
        'responsavel_nome' => 'Responsible Person', 'responsavel_email' => 'responsible@example.test',
        'responsavel_telefone' => '11912345678',
    ]);
    if ($change === 'remove spouse') {
        $payload = array_merge($payload, ['estado_civil' => 'casado', 'conjuge_nome' => 'Spouse', 'conjuge_cpf' => '52998224725']);
    }
    if ($change === 'company document') {
        $payload = array_merge($payload, ['cpf' => '11222333000181', 'cpf_responsavel' => '52998224725', 'nome_responsavel' => 'Representative']);
    }
    $this->actingAs($admin, 'admin')->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect();
    $lead = Lead::query()->where('email', 'regression-lead@example.test')->sole();
    expect($lead->data_edited_at)->toBeNull()->and($lead->updated_by_corretor_id)->toBeNull();
    $lead->update(['status' => 'em_analise', 'tags_originais' => 'Origem preservada']);
    $this->travel(5)->minutes();
    $this->withHeader('User-Agent', 'Different browser')->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect();
    expect($lead->fresh()->data_edited_at)->toBeNull()->and($lead->fresh()->updated_by_corretor_id)->toBeNull();
    expect($lead->fresh()->status)->toBe('em_analise')->and($lead->fresh()->tags_originais)->toBe('Origem preservada');
    expect($this->get(route('Dashboard-Admin', ['resultado' => 'sem_resultado']))->viewData('leads')->pluck('id')->all())->toBe([$lead->id]);

    $changes = match ($change) {
        'name' => ['nome' => 'Changed Name'],
        'address' => ['numero' => '1234'],
        'expenses' => ['valor_aluguel' => '2000'],
        'add spouse' => ['estado_civil' => 'casado', 'conjuge_nome' => 'Spouse', 'conjuge_cpf' => '52998224725'],
        'remove spouse' => ['estado_civil' => 'solteiro', 'conjuge_nome' => null, 'conjuge_cpf' => null],
        'company document' => ['nome_responsavel' => 'Another Representative'],
        'responsible' => ['responsavel_telefone' => '21912345678'],
    };
    $payload = array_merge($payload, $changes);
    $this->travel(5)->minutes();
    $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect();
    $editedAt = $lead->fresh()->data_edited_at;
    expect($editedAt)->not->toBeNull()->and($lead->fresh()->updated_by_corretor_id)->toBe($admin->id);
    expect($this->get(route('Dashboard-Admin', ['resultado' => 'sem_resultado']))->viewData('leads')->total())->toBe(0);
    $this->travel(5)->minutes();
    $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect();
    expect($lead->fresh()->data_edited_at->equalTo($editedAt))->toBeTrue();
})->with([
    ['imobiliaria_cadastrada', 'name'], ['locatario', 'name'],
    ['imobiliaria_cadastrada', 'address'], ['locatario', 'expenses'],
    ['imobiliaria_cadastrada', 'add spouse'], ['imobiliaria_cadastrada', 'remove spouse'],
    ['imobiliaria_cadastrada', 'company document'],
    ['locador', 'responsible'], ['imobiliaria_nao_cadastrada', 'responsible'],
]);
