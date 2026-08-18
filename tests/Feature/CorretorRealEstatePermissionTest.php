<?php

use App\Models\Corretor;
use App\Support\CorretorPermissions;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->withoutVite();
});

function createRealEstatePermissionCorretor(array $overrides = []): Corretor
{
    static $sequence = 0;

    $sequence++;

    return Corretor::query()->create(array_merge([
        'name' => 'Corretor de permissões '.$sequence,
        'email' => "corretor-permissoes-{$sequence}@example.test",
        'password' => 'senha1234',
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ], $overrides));
}

it('places every operational permission in exactly one visual group', function () {
    $groupedPermissions = collect(CorretorPermissions::groups())
        ->flatMap(
            static fn (array $group): array => array_keys($group['permissions']),
        )
        ->values()
        ->all();

    expect($groupedPermissions)
        ->toBe(CorretorPermissions::keys())
        ->and(array_unique($groupedPermissions))
        ->toHaveCount(count($groupedPermissions));
});

it('requires visualization together with every real estate company write permission', function () {
    $viewer = createRealEstatePermissionCorretor([
        'permissions' => ['imobiliarias.visualizar'],
    ]);

    expect(Gate::forUser($viewer)->allows('view-real-estate-companies'))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('create-real-estate-company'))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('update-real-estate-company'))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('delete-real-estate-company'))->toBeFalse();

    $abilities = [
        'imobiliarias.cadastrar' => 'create-real-estate-company',
        'imobiliarias.editar' => 'update-real-estate-company',
        'imobiliarias.remover' => 'delete-real-estate-company',
    ];

    foreach ($abilities as $permission => $ability) {
        $actionOnly = createRealEstatePermissionCorretor([
            'permissions' => [$permission],
        ]);

        expect(Gate::forUser($actionOnly)->allows('view-real-estate-companies'))->toBeFalse()
            ->and(Gate::forUser($actionOnly)->allows($ability))->toBeFalse();

        $completePermission = createRealEstatePermissionCorretor([
            'permissions' => ['imobiliarias.visualizar', $permission],
        ]);

        expect(Gate::forUser($completePermission)->allows('view-real-estate-companies'))->toBeTrue()
            ->and(Gate::forUser($completePermission)->allows($ability))->toBeTrue();
    }

    $ceo = createRealEstatePermissionCorretor([
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
    ]);

    expect(Gate::forUser($ceo)->allows('view-real-estate-companies'))->toBeTrue()
        ->and(Gate::forUser($ceo)->allows('create-real-estate-company'))->toBeTrue()
        ->and(Gate::forUser($ceo)->allows('update-real-estate-company'))->toBeTrue()
        ->and(Gate::forUser($ceo)->allows('delete-real-estate-company'))->toBeTrue();
});

it('rejects an inconsistent real estate permission set when creating a team member', function () {
    $ceo = createRealEstatePermissionCorretor([
        'role' => Corretor::ROLE_CEO,
    ]);

    $response = $this
        ->actingAs($ceo, 'admin')
        ->from(route('admin.config-equipe.create'))
        ->post(route('admin.config-equipe.store'), [
            'nome' => 'Integrante inválido',
            'email' => 'integrante-invalido@example.test',
            'password' => 'senha1234',
            'password_confirmation' => 'senha1234',
            'permissions' => ['imobiliarias.editar'],
            'active' => '1',
        ]);

    $response
        ->assertRedirect(route('admin.config-equipe.create'))
        ->assertSessionHasErrors([
            'permissions' => 'Para cadastrar, editar ou remover imobiliárias, selecione também “Visualizar imobiliárias”.',
        ]);

    $this->assertDatabaseMissing('corretores', [
        'email' => 'integrante-invalido@example.test',
    ]);
});

it('rejects an inconsistent update and persists a complete real estate permission set', function () {
    $ceo = createRealEstatePermissionCorretor([
        'role' => Corretor::ROLE_CEO,
    ]);
    $member = createRealEstatePermissionCorretor([
        'permissions' => ['imobiliarias.visualizar'],
    ]);

    $invalidResponse = $this
        ->actingAs($ceo, 'admin')
        ->from(route('admin.config-equipe.edit', $member))
        ->put(route('admin.config-equipe.update', $member), [
            'nome' => $member->name,
            'email' => $member->email,
            'permissions' => ['imobiliarias.remover'],
            'active' => '1',
        ]);

    $invalidResponse
        ->assertRedirect(route('admin.config-equipe.edit', $member))
        ->assertSessionHasErrors('permissions');

    expect($member->refresh()->permissions)->toBe(['imobiliarias.visualizar']);

    $this
        ->actingAs($ceo, 'admin')
        ->put(route('admin.config-equipe.update', $member), [
            'nome' => $member->name,
            'email' => $member->email,
            'permissions' => [
                'imobiliarias.visualizar',
                'imobiliarias.editar',
                'imobiliarias.remover',
            ],
            'active' => '1',
        ])
        ->assertRedirect(route('admin.config-equipe.index'))
        ->assertSessionHasNoErrors();

    expect($member->refresh()->permissions)->toBe([
        'imobiliarias.visualizar',
        'imobiliarias.editar',
        'imobiliarias.remover',
    ]);
});

it('renders grouped permission cards and blocks real estate actions until visualization is selected', function () {
    $ceo = createRealEstatePermissionCorretor([
        'role' => Corretor::ROLE_CEO,
    ]);

    $response = $this
        ->actingAs($ceo, 'admin')
        ->get(route('admin.config-equipe.create'))
        ->assertOk()
        ->assertSeeText('Leads e clientes')
        ->assertSeeText('Análises')
        ->assertSeeText('Imobiliárias')
        ->assertSeeText('Tags dos leads')
        ->assertSee('data-team-permission-groups', false)
        ->assertSee('data-team-permission-group="real-estate-companies"', false)
        ->assertSee('data-team-permission-controller', false)
        ->assertSee('data-team-permission-requires="imobiliarias.visualizar"', false);

    $html = $response->getContent();

    expect(substr_count($html, 'data-team-permission-group='))->toBe(4);

    preg_match('/<input\b[^>]*value="imobiliarias\.visualizar"[^>]*>/s', $html, $viewInput);
    preg_match('/<input\b[^>]*value="imobiliarias\.cadastrar"[^>]*>/s', $html, $createInput);
    preg_match('/<input\b[^>]*value="imobiliarias\.editar"[^>]*>/s', $html, $updateInput);
    preg_match('/<input\b[^>]*value="imobiliarias\.remover"[^>]*>/s', $html, $deleteInput);

    expect($viewInput[0] ?? '')->not->toContain('disabled')
        ->and($createInput[0] ?? '')->toContain('disabled')
        ->and($updateInput[0] ?? '')->toContain('disabled')
        ->and($deleteInput[0] ?? '')->toContain('disabled');
});

it('renders existing dependent permissions enabled only when visualization is present', function () {
    $ceo = createRealEstatePermissionCorretor([
        'role' => Corretor::ROLE_CEO,
    ]);
    $member = createRealEstatePermissionCorretor([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.editar'],
    ]);

    $html = $this
        ->actingAs($ceo, 'admin')
        ->get(route('admin.config-equipe.edit', $member))
        ->assertOk()
        ->getContent();

    preg_match('/<input\b[^>]*value="imobiliarias\.editar"[^>]*>/s', $html, $updateInput);

    expect($updateInput[0] ?? '')->toContain('checked')
        ->not->toContain('disabled');
});

it('preserves an intentionally empty permission selection after another validation error', function () {
    $ceo = createRealEstatePermissionCorretor([
        'role' => Corretor::ROLE_CEO,
    ]);
    $member = createRealEstatePermissionCorretor([
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.editar'],
    ]);

    $this
        ->actingAs($ceo, 'admin')
        ->from(route('admin.config-equipe.edit', $member))
        ->put(route('admin.config-equipe.update', $member), [
            'nome' => '',
            'email' => $member->email,
            'permissions_submitted' => '1',
            'active' => '1',
        ])
        ->assertRedirect(route('admin.config-equipe.edit', $member))
        ->assertSessionHasErrors('nome');

    $html = $this
        ->get(route('admin.config-equipe.edit', $member))
        ->assertOk()
        ->getContent();

    preg_match('/<input\b[^>]*value="imobiliarias\.visualizar"[^>]*>/s', $html, $viewInput);
    preg_match('/<input\b[^>]*value="imobiliarias\.editar"[^>]*>/s', $html, $updateInput);

    expect($viewInput[0] ?? '')->not->toContain('checked')
        ->and($updateInput[0] ?? '')->not->toContain('checked')
        ->toContain('disabled')
        ->and($member->refresh()->permissions)->toBe([
            'imobiliarias.visualizar',
            'imobiliarias.editar',
        ]);
});

it('keeps the permission dependency behavior centralized in the shared assets', function () {
    $javascript = file_get_contents(resource_path('js/config-equipe.js'));
    $css = file_get_contents(resource_path('css/config-equipe.css'));

    expect($javascript)
        ->toContain('syncPermissionDependencies')
        ->toContain('input.dataset.teamPermissionRequires')
        ->toContain('input.checked = false')
        ->toContain('input.disabled = !dependenciesSatisfied')
        ->and($css)
        ->toContain('.team-permission-group')
        ->toContain('.team-permission-option.is-disabled');
});
