<?php

use App\Models\Imobiliaria;
use App\Models\Lead;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['features.insurance_analysis.enabled' => false]);

    Queue::fake();
});

function companyWithPublicSimulationCode(array $overrides = []): Imobiliaria
{
    return Imobiliaria::query()->create(array_merge([
        'name' => 'Imobiliária Código Protegido',
        'email' => 'protected-code-company@example.test',
        'phone' => '11999999999',
        'password' => 'password',
        'city' => 'São Paulo',
        'state' => 'SP',
        'lead_access_code' => 'SEC9XZ',
        'lead_form_active' => true,
    ], $overrides));
}

function validRegisteredCompanySimulationPayload(array $overrides = []): array
{
    return array_merge([
        'aceite_termos' => '1',
        'nome' => 'Lead com acesso protegido',
        'email' => 'protected-code-lead@example.test',
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

it('keeps the verified company access code out of the redirect and form HTML', function () {
    $company = companyWithPublicSimulationCode();

    $response = $this->post(route('simulation.registered-company.verify'), [
        'lead_access_code' => ' sec-9xz ',
    ]);

    $response->assertRedirect();

    $location = $response->headers->get('Location');

    expect($location)
        ->toBeString()
        ->not->toContain($company->lead_access_code)
        ->and(parse_url($location, PHP_URL_PATH))
        ->toBe('/simulacao/imobiliaria-cadastrada/formulario');

    $this->get($location)
        ->assertOk()
        ->assertSee($company->name)
        ->assertDontSee($company->lead_access_code);
});

it('does not accept a company access code directly in the public form URL', function () {
    $company = companyWithPublicSimulationCode();
    $legacyUrl = "/simulacao/imobiliaria-cadastrada/{$company->lead_access_code}";

    $this->get($legacyUrl)
        ->assertNotFound();

    $this->post($legacyUrl, validRegisteredCompanySimulationPayload())
        ->assertNotFound();

    expect(Lead::query()->count())->toBe(0);
});

it('requires a verified server-side session before showing or submitting the form', function () {
    $company = companyWithPublicSimulationCode();

    $this->get(route('simulation.registered-company.form'))
        ->assertRedirect(route('simulation.registered-company.access'))
        ->assertSessionHasErrors('lead_access_code');

    $this->post(
        route('simulation.registered-company.store'),
        validRegisteredCompanySimulationPayload([
            'company_id' => $company->id,
            'lead_access_code' => $company->lead_access_code,
            'registered_company_context' => $company->id,
        ])
    )
        ->assertRedirect(route('simulation.registered-company.access'))
        ->assertSessionHasErrors('lead_access_code');

    expect(Lead::query()->count())->toBe(0);
});

it('links a submission only to the company granted by the server-side session', function () {
    $grantedCompany = companyWithPublicSimulationCode();
    $attackerSelectedCompany = companyWithPublicSimulationCode([
        'name' => 'Imobiliária escolhida pelo payload',
        'email' => 'payload-company@example.test',
        'lead_access_code' => 'ALT8QW',
    ]);

    $this->post(route('simulation.registered-company.verify'), [
        'lead_access_code' => $grantedCompany->lead_access_code,
    ])->assertRedirect(route('simulation.registered-company.form'));

    $response = $this->post(
        route('simulation.registered-company.store', [
            'code' => $attackerSelectedCompany->lead_access_code,
            'company_id' => $attackerSelectedCompany->id,
        ]),
        validRegisteredCompanySimulationPayload([
            'company_id' => $attackerSelectedCompany->id,
            'lead_access_code' => $attackerSelectedCompany->lead_access_code,
            'registered_company_context' => $grantedCompany->id,
        ])
    );

    $response->assertRedirect(route('simulation.success'));

    $lead = Lead::query()
        ->where('email', 'protected-code-lead@example.test')
        ->firstOrFail();

    expect($lead->company_id)->toBe($grantedCompany->id);
});

it('rejects a stale form after another company is verified in the same session', function () {
    $firstCompany = companyWithPublicSimulationCode();
    $secondCompany = companyWithPublicSimulationCode([
        'name' => 'Segunda Imobiliária',
        'email' => 'second-company@example.test',
        'lead_access_code' => 'SEC4YU',
    ]);

    $this->post(route('simulation.registered-company.verify'), [
        'lead_access_code' => $firstCompany->lead_access_code,
    ])->assertRedirect(route('simulation.registered-company.form'));

    $this->get(route('simulation.registered-company.form'))
        ->assertOk()
        ->assertSee('name="registered_company_context"', false)
        ->assertSee('value="'.$firstCompany->id.'"', false);

    $this->post(route('simulation.registered-company.verify'), [
        'lead_access_code' => $secondCompany->lead_access_code,
    ])->assertRedirect(route('simulation.registered-company.form'));

    $this->post(
        route('simulation.registered-company.store'),
        validRegisteredCompanySimulationPayload([
            'registered_company_context' => $firstCompany->id,
        ])
    )
        ->assertRedirect(route('simulation.registered-company.access'))
        ->assertSessionHasErrors('lead_access_code');

    expect(Lead::query()->count())->toBe(0);
});

it('invalidates the session grant when company access is changed', function (array $changes) {
    $company = companyWithPublicSimulationCode();

    $this->post(route('simulation.registered-company.verify'), [
        'lead_access_code' => $company->lead_access_code,
    ])->assertRedirect(route('simulation.registered-company.form'));

    $company->forceFill($changes)->save();

    $this->get(route('simulation.registered-company.form'))
        ->assertRedirect(route('simulation.registered-company.access'))
        ->assertSessionHasErrors('lead_access_code');
})->with([
    'code rotation' => [['lead_access_code' => 'NEW7RT']],
    'form deactivation' => [['lead_form_active' => false]],
]);

it('preserves validation errors and old input in a verified form session', function () {
    $company = companyWithPublicSimulationCode();

    $this->post(route('simulation.registered-company.verify'), [
        'lead_access_code' => $company->lead_access_code,
    ])->assertRedirect(route('simulation.registered-company.form'));

    $this->from(route('simulation.registered-company.form'))
        ->post(
            route('simulation.registered-company.store'),
            validRegisteredCompanySimulationPayload([
                'nome' => 'Nome deve permanecer',
                'email' => 'email-invalido',
                'registered_company_context' => $company->id,
            ])
        )
        ->assertRedirect(route('simulation.registered-company.form'))
        ->assertSessionHasErrors('email')
        ->assertSessionHasInput('nome', 'Nome deve permanecer');

    $this->get(route('simulation.registered-company.form'))
        ->assertOk()
        ->assertSee('value="Nome deve permanecer"', false);

    expect(Lead::query()->count())->toBe(0);
});
