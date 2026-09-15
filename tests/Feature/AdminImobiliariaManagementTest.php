<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Models\User;
use App\Notifications\CompanyAcessCodeNotification;
use App\Services\CepService;
use App\Services\CompanyInvitationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
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

function validCompanyUpdatePayload(Imobiliaria $company, array $overrides = []): array
{
    return array_merge([
        '_editing_company_id' => $company->id,
        'name' => $company->name,
        'email' => $company->email,
        'phone' => $company->phone,
        'cnpj' => $company->cnpj,
        'cep' => $company->cep,
        'city' => $company->city,
        'state' => $company->state,
        'lead_form_active' => $company->lead_form_active ? '1' : '0',
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
        ->assertSee('data-reveal', false)
        ->assertSee('data-count-up', false)
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
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.cadastrar'],
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
        ->assertSee('data-status-state', false)
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
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.cadastrar'],
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

it('registers the company and its user in the administrative flow', function (string $document, string $normalizedDocument) {
    Notification::fake();

    $creator = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.cadastrar'],
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
                'cnpj' => $document,
            ]),
        );

    $response
        ->assertRedirect(route('admin.imobiliarias.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'A imobiliária Imobiliária Nova Parceira foi cadastrada e o e-mail de boas-vindas foi adicionado à fila de envio.');

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
        ->cnpj->toBe($normalizedDocument)
        ->and($user->company_id)->toBe($company->id)
        ->and(Hash::check('senha1234', $company->password))->toBeTrue()
        ->and(Hash::check('senha1234', $user->password))->toBeTrue();

    $this->assertDatabaseHas('logs_atividades_corretores', [
        'corretor_id' => $creator->id,
        'action' => 'imobiliaria_created',
        'model_id' => $company->id,
    ]);

    $this->assertDatabaseHas('logs_atividades_corretores', [
        'corretor_id' => $creator->id,
        'action' => 'imobiliaria_acesso_email_enfileirado',
        'model_id' => $company->id,
    ]);

    Notification::assertSentTo(
        $company,
        CompanyAcessCodeNotification::class,
        fn (CompanyAcessCodeNotification $notification): bool => $notification->companyName === $company->name
            && $notification->accessCode === $company->lead_access_code,
    );
})->with([
    ['11.222.333/0001-81', '11222333000181'],
    ['11222333000181', '11222333000181'],
    ['529.982.247-25', '52998224725'],
    ['52998224725', '52998224725'],
    ['012.345.678-90', '01234567890'],
]);

it('rejects invalid company documents without creating a company or sending mail', function (mixed $document) {
    Notification::fake();
    $creator = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.cadastrar'],
    ]);
    $this->mock(CepService::class, function (MockInterface $mock) {
        $mock->shouldReceive('find')->andReturn([
            'cep' => '01001000', 'cidade' => 'São Paulo', 'estado' => 'SP',
        ]);
    });
    $this->actingAs($creator, 'admin')->post(route('admin.imobiliarias.store'), array_merge(
        validAdminCompanyPayload(702),
        ['cnpj' => $document],
    ))->assertSessionHasErrors('cnpj');

    $this->assertDatabaseCount('imobiliarias', 0);
    Notification::assertNothingSent();
})->with([
    ['52998224724'], ['11222333000182'], ['11111111111'],
    ['00000000000000'], ['123456789012'], [''], [null],
    [['52998224725']], ['abc52998224725'], ['52998224725<script>'],
]);

it('allows editing an existing company registered with a cpf', function () {
    $editor = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.editar'],
    ]);
    $company = createManagedImobiliaria(['cnpj' => '52998224725']);

    $this->actingAs($editor, 'admin')
        ->patch(route('admin.imobiliarias.update', $company), validCompanyUpdatePayload($company, [
            'cnpj' => '529.982.247-25', 'city' => 'Campinas',
        ]))->assertSessionHasNoErrors();

    expect($company->fresh())->cnpj->toBe('52998224725')->city->toBe('Campinas');
});

it('rejects a duplicate cpf after normalizing its punctuation', function () {
    Notification::fake();
    createManagedImobiliaria(['cnpj' => '52998224725']);
    $creator = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.cadastrar'],
    ]);
    $this->mock(CepService::class, function (MockInterface $mock) {
        $mock->shouldReceive('find')->andReturn([
            'cep' => '01001000', 'cidade' => 'São Paulo', 'estado' => 'SP',
        ]);
    });
    $this->actingAs($creator, 'admin')->post(route('admin.imobiliarias.store'), array_merge(
        validAdminCompanyPayload(702), ['cnpj' => '529.982.247-25'],
    ))->assertSessionHasErrors('cnpj');
    $this->assertDatabaseCount('imobiliarias', 1);
    Notification::assertNothingSent();
});

it('keeps the company registered when the welcome email cannot be queued', function () {
    Log::spy();

    $creator = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.cadastrar'],
    ]);

    $tag = LeadLoversTag::query()->create([
        'leadlovers_tag_id' => 703,
        'title' => 'Imobiliária Cadastro Persistente',
        'key' => 'imobiliaria_cadastro_persistente',
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

    $providerDetails = 'MAIL_PASSWORD=segredo resposta privada do provedor';

    $this->mock(CompanyInvitationService::class, function (MockInterface $mock) use ($providerDetails) {
        $mock->shouldReceive('sendWelcomeAccessCode')
            ->once()
            ->andThrow(new RuntimeException($providerDetails));
    });

    $this
        ->actingAs($creator, 'admin')
        ->post(
            route('admin.imobiliarias.store'),
            validAdminCompanyPayload($tag->leadlovers_tag_id, [
                'email' => 'persistente@example.test',
                'phone' => '(11) 98888-7777',
                'cnpj' => '45.723.174/0001-10',
            ]),
        )
        ->assertRedirect(route('admin.imobiliarias.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas(
            'error',
            'A imobiliária Imobiliária Cadastro Persistente foi cadastrada, mas o e-mail de boas-vindas não pôde ser enviado.',
        );

    $this->assertDatabaseHas('imobiliarias', [
        'name' => 'Imobiliária Cadastro Persistente',
        'email' => 'persistente@example.test',
    ]);
    $this->assertDatabaseHas('users', [
        'name' => 'Imobiliária Cadastro Persistente',
        'email' => 'persistente@example.test',
    ]);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($providerDetails): bool {
            $loggedData = json_encode([$message, $context]);

            return is_string($loggedData)
                && ! str_contains($loggedData, $providerDetails)
                && $context['exception'] === RuntimeException::class;
        });
});

it('shows edit and delete controls only for their respective permissions', function () {
    $company = createManagedImobiliaria([
        'cnpj' => '11222333000181',
    ]);
    $viewer = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar'],
    ]);
    $editor = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.editar'],
    ]);
    $remover = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.remover'],
    ]);

    $this
        ->actingAs($viewer, 'admin')
        ->get(route('admin.imobiliarias.index'))
        ->assertOk()
        ->assertDontSee('data-company-edit', false)
        ->assertDontSee('data-company-delete', false);

    $this
        ->actingAs($editor, 'admin')
        ->get(route('admin.imobiliarias.index'))
        ->assertOk()
        ->assertSee('data-company-edit', false)
        ->assertSee('data-company-update-url="'.route('admin.imobiliarias.update', $company).'"', false)
        ->assertSee('name="_method" value="PATCH"', false)
        ->assertDontSee('data-company-delete', false)
        ->assertDontSee($company->lead_form_token)
        ->assertDontSee('name="password"', false);

    $this
        ->actingAs($remover, 'admin')
        ->get(route('admin.imobiliarias.index'))
        ->assertOk()
        ->assertSee('data-company-delete', false)
        ->assertSee('data-company-delete-url="'.route('admin.imobiliarias.destroy', $company).'"', false)
        ->assertSee('name="_method" value="DELETE"', false)
        ->assertSeeText('Esta ação é irreversível')
        ->assertDontSee('data-company-edit', false);
});

it('allows an authorized admin to update a company without external requests', function () {
    Http::fake();

    $editor = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.editar'],
    ]);
    $company = createManagedImobiliaria([
        'name' => 'Imobiliária Horizonte',
        'email' => 'horizonte@example.test',
        'phone' => '11988887777',
        'cnpj' => '11222333000181',
        'cep' => '01001000',
        'city' => 'São Paulo',
        'state' => 'SP',
        'lead_form_active' => true,
    ]);
    $primaryUser = User::query()->create([
        'company_id' => $company->id,
        'name' => $company->name,
        'email' => $company->email,
        'password' => 'senha1234',
    ]);

    $response = $this
        ->actingAs($editor, 'admin')
        ->patch(route('admin.imobiliarias.update', $company), validCompanyUpdatePayload($company, [
            'name' => '  Imobiliária Horizonte Sul  ',
            'email' => '  CONTATO.HORIZONTE@EXAMPLE.TEST ',
            'phone' => '(11) 97777-6666',
            'cnpj' => '11.222.333/0001-81',
            'cep' => '20040-020',
            'city' => '  Rio   de Janeiro ',
            'state' => 'rj',
            'lead_form_active' => '0',
        ]));

    $response
        ->assertRedirect(route('admin.imobiliarias.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'A imobiliária Imobiliária Horizonte Sul foi atualizada com sucesso.');

    $company->refresh();
    $primaryUser->refresh();

    expect($company)
        ->name->toBe('Imobiliária Horizonte Sul')
        ->email->toBe('contato.horizonte@example.test')
        ->phone->toBe('11977776666')
        ->cnpj->toBe('11222333000181')
        ->cep->toBe('20040020')
        ->city->toBe('Rio de Janeiro')
        ->state->toBe('RJ')
        ->lead_form_active->toBeFalse()
        ->and($primaryUser->name)->toBe('Imobiliária Horizonte Sul')
        ->and($primaryUser->email)->toBe('contato.horizonte@example.test');

    $this->assertDatabaseHas('logs_atividades_corretores', [
        'corretor_id' => $editor->id,
        'action' => 'imobiliaria_updated',
        'model_id' => $company->id,
    ]);

    Http::assertNothingSent();
});

it('denies company updates without permission', function () {
    $unauthorized = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar'],
    ]);
    $company = createManagedImobiliaria([
        'name' => 'Imobiliária Protegida',
        'cnpj' => '11222333000181',
    ]);

    $this
        ->actingAs($unauthorized, 'admin')
        ->patch(route('admin.imobiliarias.update', $company), validCompanyUpdatePayload($company, [
            'name' => 'Alteração indevida',
        ]))
        ->assertForbidden();

    expect($company->fresh()->name)->toBe('Imobiliária Protegida');
});

it('rejects invalid update data and reopens the correct modal with submitted values', function () {
    $editor = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.editar'],
    ]);
    $company = createManagedImobiliaria([
        'name' => 'Imobiliária Válida',
        'email' => 'valida@example.test',
        'cnpj' => '11222333000181',
    ]);

    $response = $this
        ->actingAs($editor, 'admin')
        ->from(route('admin.imobiliarias.index'))
        ->followingRedirects()
        ->patch(route('admin.imobiliarias.update', $company), validCompanyUpdatePayload($company, [
            'name' => '',
            'email' => 'email-invalido',
            'phone' => '123',
            'cnpj' => '00.000.000/0000-00',
            'cep' => '123',
            'state' => 'XX',
        ]));

    $response
        ->assertOk()
        ->assertSee('data-reopen-company-id="'.$company->id.'"', false)
        ->assertSee('value="email-invalido"', false)
        ->assertSeeText('As informações enviadas foram preservadas')
        ->assertSeeText('Informe um e-mail válido');

    expect($company->fresh())
        ->name->toBe('Imobiliária Válida')
        ->email->toBe('valida@example.test')
        ->phone->toBe($company->phone)
        ->cnpj->toBe('11222333000181');
});

it('ignores the company and its primary user in unique rules during update', function () {
    $editor = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.editar'],
    ]);
    $company = createManagedImobiliaria([
        'name' => 'Imobiliária Sem Alterações',
        'email' => 'sem.alteracoes@example.test',
        'phone' => '11988887777',
        'cnpj' => '11222333000181',
    ]);
    User::query()->create([
        'company_id' => $company->id,
        'name' => $company->name,
        'email' => $company->email,
        'password' => 'senha1234',
    ]);

    $this
        ->actingAs($editor, 'admin')
        ->patch(route('admin.imobiliarias.update', $company), validCompanyUpdatePayload($company))
        ->assertRedirect(route('admin.imobiliarias.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('info', 'Nenhuma alteração foi realizada na imobiliária Imobiliária Sem Alterações.');
});

it('does not accept sensitive or internal fields during update', function () {
    Http::fake();

    $editor = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.editar'],
    ]);
    $company = createManagedImobiliaria([
        'name' => 'Imobiliária Segura',
        'cnpj' => '11222333000181',
        'lead_form_token' => 'internal-form-token',
        'lead_access_code' => 'SAFE01',
        'leadlovers_tag_id' => null,
        'leadlovers_tag_name' => null,
    ]);
    $originalPassword = $company->password;

    $this
        ->actingAs($editor, 'admin')
        ->patch(route('admin.imobiliarias.update', $company), validCompanyUpdatePayload($company, [
            'city' => 'Campinas',
            'password' => 'senha-injetada',
            'lead_form_token' => 'token-injetado',
            'lead_access_code' => 'HACKED',
            'leadlovers_tag_id' => 999,
            'leadlovers_tag_name' => 'Tag injetada',
        ]))
        ->assertRedirect(route('admin.imobiliarias.index'))
        ->assertSessionHasNoErrors();

    $company->refresh();

    expect($company)
        ->city->toBe('Campinas')
        ->password->toBe($originalPassword)
        ->lead_form_token->toBe('internal-form-token')
        ->lead_access_code->toBe('SAFE01')
        ->leadlovers_tag_id->toBeNull()
        ->leadlovers_tag_name->toBeNull();

    Http::assertNothingSent();
});

it('allows an authorized admin to delete a company while preserving related business records', function () {
    $remover = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.remover'],
    ]);
    $company = createManagedImobiliaria([
        'name' => 'Imobiliária Removível',
        'cnpj' => '11222333000181',
    ]);
    $companyUser = User::query()->create([
        'company_id' => $company->id,
        'name' => $company->name,
        'email' => $company->email,
        'password' => 'senha1234',
    ]);
    $lead = Lead::query()->create([
        'company_id' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada',
        'nome' => 'Cliente preservado',
        'email' => 'cliente@example.test',
        'status' => 'novo',
        'origem' => 'imobiliaria_cadastrada',
    ]);

    $this
        ->actingAs($remover, 'admin')
        ->delete(route('admin.imobiliarias.destroy', $company))
        ->assertRedirect(route('admin.imobiliarias.index'))
        ->assertSessionHas('success', 'A imobiliária Imobiliária Removível foi removida com sucesso.');

    $this->assertDatabaseMissing('imobiliarias', ['id' => $company->id]);
    $this->assertDatabaseMissing('users', ['id' => $companyUser->id]);
    $this->assertDatabaseHas('leads', [
        'id' => $lead->id,
        'company_id' => null,
    ]);
    $this->assertDatabaseHas('logs_atividades_corretores', [
        'corretor_id' => $remover->id,
        'action' => 'imobiliaria_deleted',
        'model_id' => $company->id,
    ]);
});

it('denies company deletion without permission', function () {
    $unauthorized = createImobiliariaAdmin([
        'permissions' => ['imobiliarias.visualizar'],
    ]);
    $company = createManagedImobiliaria([
        'cnpj' => '11222333000181',
    ]);

    $this
        ->actingAs($unauthorized, 'admin')
        ->delete(route('admin.imobiliarias.destroy', $company))
        ->assertForbidden();

    $this->assertDatabaseHas('imobiliarias', ['id' => $company->id]);
});

it('registers the management routes with the expected HTTP methods', function () {
    expect(Route::getRoutes()->getByName('admin.imobiliarias.update')?->methods())
        ->toBe(['PATCH'])
        ->and(Route::getRoutes()->getByName('admin.imobiliarias.destroy')?->methods())
        ->toBe(['DELETE']);
});
