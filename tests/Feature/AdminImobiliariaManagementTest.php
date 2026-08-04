<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\LeadLoversTag;
use App\Models\User;
use App\Services\CepService;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;

function createImobiliariaAdmin(array $overrides = []): Corretor
{
    static $sequence = 0;

    $sequence++;

    return Corretor::query()->create(array_merge([
        'name' => 'Corretor '.$sequence,
        'email' => "corretor{$sequence}@example.test",
        'cpf' => str_pad((string) $sequence, 11, '0', STR_PAD_LEFT),
        'password' => 'senha1234',
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ], $overrides));
}

function createManagedImobiliaria(array $overrides = []): Imobiliaria
{
    static $sequence = 0;

    $sequence++;

    return Imobiliaria::query()->create(array_merge([
        'name' => 'Imobiliária Teste '.$sequence,
        'email' => "imobiliaria{$sequence}@example.test",
        'phone' => '1199999'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        'cnpj' => '1122233300'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        'cep' => '01001000',
        'password' => Hash::make('senha1234'),
        'city' => 'São Paulo',
        'state' => 'SP',
        'lead_form_active' => true,
    ], $overrides));
}

function validAdminCompanyPayload(int $tagId, array $overrides = []): array
{
    return array_merge([
        'website' => '',
        'leadlovers_tag_id' => $tagId,
        'email' => 'nova.imobiliaria@example.test',
        'phone' => '(11) 99999-9999',
        'cnpj' => '11.222.333/0001-81',
        'cep' => '01001-000',
        'city' => 'São Paulo',
        'state' => 'SP',
        'password' => 'senha1234',
        'password_confirmation' => 'senha1234',
        'lead_form_active' => '1',
    ], $overrides);
}

it('renders the company index with its exact view contract and without internal tokens', function () {
    $viewer = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar'],
    ]);

    $activeCompany = createManagedImobiliaria([
        'name' => 'Imobiliária Horizonte',
        'email' => 'horizonte@example.test',
        'cnpj' => '11222333000181',
        'lead_form_active' => true,
    ]);

    createManagedImobiliaria([
        'name' => 'Imobiliária Pausada',
        'email' => 'pausada@example.test',
        'cnpj' => '22333444000172',
        'lead_form_active' => false,
    ]);

    $response = $this
        ->actingAs($viewer, 'admin')
        ->get(route('admin.imobiliarias.index'));

    $response
        ->assertOk()
        ->assertViewIs('corretor.imobiliarias.index')
        ->assertViewHas('filters', [])
        ->assertViewHas('summary', [
            'total' => 2,
            'active' => 1,
            'inactive' => 1,
        ])
        ->assertViewHas('companies', fn ($companies) => $companies->total() === 2)
        ->assertSeeText('Imobiliárias cadastradas')
        ->assertSeeText('Imobiliária Horizonte')
        ->assertSeeText('11.222.333/0001-81')
        ->assertSee('data-copy-code="'.$activeCompany->lead_access_code.'"', false)
        ->assertSee('d-none d-lg-block', false)
        ->assertSee('company-mobile-list', false)
        ->assertDontSee($activeCompany->lead_form_token)
        ->assertDontSeeText('Cadastrar imobiliária');
});

it('filters companies while keeping the summary global', function () {
    $viewer = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar'],
    ]);

    createManagedImobiliaria([
        'name' => 'Imobiliária Alfa',
        'email' => 'alfa@example.test',
        'cnpj' => '11222333000181',
        'lead_form_active' => true,
    ]);

    createManagedImobiliaria([
        'name' => 'Imobiliária Beta',
        'email' => 'beta@example.test',
        'cnpj' => '22333444000172',
        'lead_form_active' => false,
    ]);

    $response = $this
        ->actingAs($viewer, 'admin')
        ->get(route('admin.imobiliarias.index', [
            'search' => 'Beta',
            'status' => 'inactive',
        ]));

    $response
        ->assertOk()
        ->assertViewHas('filters', [
            'search' => 'Beta',
            'status' => 'inactive',
        ])
        ->assertViewHas('summary', [
            'total' => 2,
            'active' => 1,
            'inactive' => 1,
        ])
        ->assertViewHas('companies', fn ($companies) => $companies->total() === 1)
        ->assertSeeText('Imobiliária Beta')
        ->assertDontSeeText('Imobiliária Alfa');
});

it('distinguishes the general and filtered empty states', function () {
    $viewer = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar'],
    ]);

    $this
        ->actingAs($viewer, 'admin')
        ->get(route('admin.imobiliarias.index'))
        ->assertOk()
        ->assertSeeText('Nenhuma imobiliária cadastrada');

    $this
        ->actingAs($viewer, 'admin')
        ->get(route('admin.imobiliarias.index', ['search' => 'inexistente']))
        ->assertOk()
        ->assertSeeText('Nenhum resultado para os filtros aplicados');
});

it('applies the client brand theme to the company administration pages', function () {
    $viewer = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar'],
    ]);

    config(['branding.active' => 'client']);

    $this
        ->actingAs($viewer, 'admin')
        ->get(route('admin.imobiliarias.index'))
        ->assertOk()
        ->assertSee('data-brand="client"', false)
        ->assertSee('real-estate-admin', false);

    $themeCss = file_get_contents(resource_path('css/imobiliarias-admin.css'));

    expect($themeCss)
        ->toContain('[data-brand="client"] .real-estate-admin')
        ->toContain('--company-primary-rgb: 0, 0, 255;')
        ->toContain('--company-accent-rgb: 255, 0, 0;')
        ->toContain('--company-navy: var(--brand-primary-dark);');
});

it('allows creators and the CEO to open the registration form', function () {
    $creator = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.cadastrar'],
    ]);

    $ceo = createImobiliariaAdmin([
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
    ]);

    LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 701,
        'title' => 'Imobiliária Disponível',
        'key' => 'imobiliaria_disponivel',
        'active' => true,
    ]);

    $this
        ->actingAs($creator, 'admin')
        ->get(route('admin.imobiliarias.index'))
        ->assertOk()
        ->assertSeeText('Cadastrar imobiliária');

    $this
        ->actingAs($creator, 'admin')
        ->get(route('admin.imobiliarias.create'))
        ->assertOk()
        ->assertViewIs('corretor.imobiliarias.create')
        ->assertSeeText('Cadastrar imobiliária')
        ->assertSee('name="leadlovers_tag_id"', false)
        ->assertSee('name="website"', false)
        ->assertDontSee('lead_form_token');

    $this
        ->actingAs($ceo, 'admin')
        ->get(route('admin.imobiliarias.create'))
        ->assertOk();
});

it('denies company pages according to the assigned permissions', function () {
    $viewer = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar'],
    ]);

    $unauthorized = createImobiliariaAdmin([
        'permissions' => [],
    ]);

    $this
        ->actingAs($viewer, 'admin')
        ->get(route('admin.imobiliarias.index'))
        ->assertOk();

    $this
        ->actingAs($viewer, 'admin')
        ->get(route('admin.imobiliarias.create'))
        ->assertForbidden();

    $this
        ->actingAs($viewer, 'admin')
        ->post(route('admin.imobiliarias.store'), [])
        ->assertForbidden();

    $this
        ->actingAs($unauthorized, 'admin')
        ->get(route('admin.imobiliarias.index'))
        ->assertForbidden();
});

it('applies the inherited validation rules to administrative registration', function () {
    $creator = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.cadastrar'],
    ]);

    $this
        ->actingAs($creator, 'admin')
        ->from(route('admin.imobiliarias.create'))
        ->post(route('admin.imobiliarias.store'), [])
        ->assertRedirect(route('admin.imobiliarias.create'))
        ->assertSessionHasErrors([
            'company_name',
            'email',
            'phone',
            'cnpj',
            'cep',
            'password',
            'city',
            'state',
        ]);

    $this->assertDatabaseCount('imobiliarias', 0);
    $this->assertDatabaseCount('users', 0);
});

it('registers the company and its user in the administrative flow', function () {
    $creator = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.cadastrar'],
    ]);

    $tag = LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 702,
        'title' => 'Imobiliária Nova Parceira',
        'key' => 'imobiliaria_nova_parceira',
        'active' => true,
    ]);

    $this->mock(CepService::class, function (MockInterface $mock) {
        $mock->shouldReceive('find')
            ->with('01001000')
            ->andReturn([
                'cep' => '01001000',
                'cidade' => 'São Paulo',
                'estado' => 'SP',
            ]);
    });

    $response = $this
        ->actingAs($creator, 'admin')
        ->post(
            route('admin.imobiliarias.store'),
            validAdminCompanyPayload($tag->leadlovers_tag_id, [
                'lead_form_active' => '0',
            ]),
        );

    $response
        ->assertRedirect(route('admin.imobiliarias.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'A imobiliária Imobiliária Nova Parceira foi cadastrada com sucesso.');

    $company = Imobiliaria::query()
        ->where('email', 'nova.imobiliaria@example.test')
        ->firstOrFail();

    $user = User::query()
        ->where('email', 'nova.imobiliaria@example.test')
        ->firstOrFail();

    expect($company)
        ->name->toBe('Imobiliária Nova Parceira')
        ->cep->toBe('01001000')
        ->lead_form_active->toBeFalse()
        ->leadlovers_tag_id->toBe(702)
        ->and($user->company_id)->toBe($company->id)
        ->and(Hash::check('senha1234', $company->password))->toBeTrue()
        ->and(Hash::check('senha1234', $user->password))->toBeTrue();

    $this->assertDatabaseHas('logs_atividades_corretores', [
        'corretor_id' => $creator->id,
        'action' => 'imobiliaria_created',
        'model_id' => $company->id,
    ]);
});
