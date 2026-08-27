<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();

    config(['features.insurance_analysis.enabled' => false]);

    Queue::fake();
});

function adminSimulationTabCorretor(): Corretor
{
    return Corretor::query()->create([
        'name' => 'Administrador das simulações',
        'email' => 'admin-simulation-tab@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
}

function adminSimulationTabCompany(): Imobiliaria
{
    return Imobiliaria::query()->create([
        'name' => 'Imobiliária do fluxo administrativo',
        'email' => 'company-simulation-tab@example.test',
        'phone' => '11999999999',
        'password' => 'password',
        'city' => 'São Paulo',
        'state' => 'SP',
        'lead_access_code' => 'TAB123',
        'lead_form_active' => true,
    ]);
}

function adminSimulationTabPayload(array $overrides = []): array
{
    return array_merge([
        'aceite_termos' => '1',
        'nome' => 'Novo lead administrativo',
        'email' => 'new-admin-lead@example.test',
        'tel' => '11988887777',
        'estado_civil' => 'solteiro',
        'valor_aluguel' => '1500',
        'cep' => '01001000',
        'logradouro' => 'Praça da Sé',
        'numero' => '100',
        'bairro' => 'Sé',
        'cidade_imovel' => 'São Paulo',
        'estado' => 'SP',
    ], $overrides);
}

it('opens the admin form in a reusable named tab with a blocked-popup fallback', function () {
    $admin = adminSimulationTabCorretor();
    adminSimulationTabCompany();

    $html = $this
        ->actingAs($admin, 'admin')
        ->get(route('Dashboard-Admin'))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('target="adminSimulationForm"')
        ->toContain("window.open('', simulationWindowName)")
        ->toContain("selectionForm.removeAttribute('target')")
        ->toContain('event.origin !== expectedOrigin')
        ->toContain('event.source !== simulationWindow')
        ->toContain('window.sessionStorage.setItem(key, value)')
        ->toContain('window.location.reload()');
});

it('carries the opening channel through both kinds of admin form', function () {
    $admin = adminSimulationTabCorretor();
    $company = adminSimulationTabCompany();
    $channel = '7f3e2a2d-6be1-4c77-9d08-3ae17ce61001';

    $registeredUrl = route('admin.simulations.registered-company.form', [
        'company' => $company,
        'admin_simulation_channel' => $channel,
    ]);

    $this
        ->actingAs($admin, 'admin')
        ->get(route('admin.simulations.open', [
            'vinculo' => 'imobiliaria_cadastrada',
            'company_id' => $company->id,
            'admin_simulation_channel' => $channel,
        ]))
        ->assertRedirect($registeredUrl);

    $this
        ->get($registeredUrl)
        ->assertOk()
        ->assertSee('name="admin_simulation_channel"', false)
        ->assertSee('value="'.$channel.'"', false);

    $unlinkedUrl = route('admin.simulations.unlinked.form', [
        'tipo' => 'locatario',
        'admin_simulation_channel' => $channel,
    ]);

    $this
        ->get(route('admin.simulations.open', [
            'vinculo' => 'sem_vinculo',
            'tipo_solicitante' => 'locatario',
            'admin_simulation_channel' => $channel,
        ]))
        ->assertRedirect($unlinkedUrl);

    $this
        ->get($unlinkedUrl)
        ->assertOk()
        ->assertSee('name="admin_simulation_channel"', false)
        ->assertSee('value="'.$channel.'"', false);
});

it('finishes a linked admin submission on the completion page', function () {
    $admin = adminSimulationTabCorretor();
    $company = adminSimulationTabCompany();
    $channel = '7f3e2a2d-6be1-4c77-9d08-3ae17ce61002';

    $response = $this
        ->actingAs($admin, 'admin')
        ->post(
            route('admin.simulations.registered-company.store', $company),
            adminSimulationTabPayload([
                'admin_simulation_channel' => $channel,
            ])
        );

    $lead = Lead::query()
        ->where('email', 'new-admin-lead@example.test')
        ->firstOrFail();

    $completionUrl = route('admin.simulations.complete', [
        'lead' => $lead,
        'admin_simulation_channel' => $channel,
    ]);

    $response
        ->assertRedirect($completionUrl)
        ->assertSessionMissing('success');

    expect($lead)
        ->company_id->toBe($company->id)
        ->created_by_corretor_id->toBe($admin->id);

    $completionHtml = $this
        ->get($completionUrl)
        ->assertOk()
        ->assertSeeText("Solicitação do lead #{$lead->id} adicionada à fila de análises.")
        ->getContent();

    expect($completionHtml)
        ->toContain('window.opener.postMessage(payload, expectedOrigin)')
        ->toContain('window.opener.focus()')
        ->toContain('window.close()')
        ->toContain('if (!window.opener || window.opener.closed)')
        ->toContain('window.location.replace(dashboardUrl)')
        ->toContain('adminSimulationDashboardFallback');
});

it('finishes an unlinked admin submission on the same completion flow', function () {
    $admin = adminSimulationTabCorretor();
    $channel = '7f3e2a2d-6be1-4c77-9d08-3ae17ce61003';

    $response = $this
        ->actingAs($admin, 'admin')
        ->post(
            route('admin.simulations.unlinked.store', ['tipo' => 'locatario']),
            adminSimulationTabPayload([
                'email' => 'unlinked-admin-lead@example.test',
                'admin_simulation_channel' => $channel,
            ])
        );

    $lead = Lead::query()
        ->where('email', 'unlinked-admin-lead@example.test')
        ->firstOrFail();

    $response->assertRedirect(route('admin.simulations.complete', [
        'lead' => $lead,
        'admin_simulation_channel' => $channel,
    ]));

    expect($lead)
        ->company_id->toBeNull()
        ->origem->toBe('locatario')
        ->created_by_corretor_id->toBe($admin->id);
});

it('keeps validation errors and old input in the named admin form', function () {
    $admin = adminSimulationTabCorretor();
    $company = adminSimulationTabCompany();
    $channel = '7f3e2a2d-6be1-4c77-9d08-3ae17ce61004';
    $formUrl = route('admin.simulations.registered-company.form', [
        'company' => $company,
        'admin_simulation_channel' => $channel,
    ]);

    $this
        ->actingAs($admin, 'admin')
        ->from($formUrl)
        ->post(route('admin.simulations.registered-company.store', $company), [
            'admin_simulation_channel' => $channel,
            'nome' => 'Nome preservado',
        ])
        ->assertRedirect($formUrl)
        ->assertSessionHasErrors(['email', 'tel', 'valor_aluguel'])
        ->assertSessionHasInput('nome', 'Nome preservado');

    $this
        ->get($formUrl)
        ->assertOk()
        ->assertSee('value="Nome preservado"', false)
        ->assertSee('value="'.$channel.'"', false);

    expect(Lead::query()->count())->toBe(0);
});

it('does not add the admin channel to public simulation forms', function () {
    $this
        ->get(route('simulation.tenant.form'))
        ->assertOk()
        ->assertDontSee('name="admin_simulation_channel"', false);
});
