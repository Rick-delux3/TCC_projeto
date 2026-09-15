<?php

use App\Models\Corretor;

beforeEach(function () {
    $this->withoutVite();
});

it('renders the motion hooks across every team management page', function () {
    $ceo = Corretor::query()->create([
        'name' => 'CEO da Equipe',
        'email' => 'ceo-equipe@example.test',
        'password' => 'senha1234',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);

    $member = Corretor::query()->create([
        'name' => 'Integrante Dinâmico',
        'email' => 'integrante-dinamico@example.test',
        'password' => 'senha1234',
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => ['imobiliarias.visualizar'],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);

    $this->actingAs($ceo, 'admin');

    $this->get(route('admin.config-equipe.index'))
        ->assertOk()
        ->assertSee('team-motion-page', false)
        ->assertSee('data-team-reveal', false)
        ->assertSee('data-team-count', false)
        ->assertSee('team-member-row', false)
        ->assertSee('data-team-auto-submit', false);

    $this->get(route('admin.config-equipe.create'))
        ->assertOk()
        ->assertSee('team-motion-page', false)
        ->assertSee('data-team-form', false)
        ->assertSee('data-team-panel', false)
        ->assertSee('data-team-permission-count', false)
        ->assertSee('data-team-permission-toggle-all', false)
        ->assertSee('data-team-submit', false);

    $this->get(route('admin.config-equipe.edit', $member))
        ->assertOk()
        ->assertSee('team-motion-page', false)
        ->assertSee('data-team-form', false)
        ->assertSee('data-team-panel', false)
        ->assertSee('data-team-permission-count', false)
        ->assertSee('data-team-permission-toggle-all', false)
        ->assertSee('data-team-submit', false);
});

it('keeps team motion accessible and compatible with both brand profiles', function () {
    $css = file_get_contents(resource_path('css/config-equipe.css'));
    $javascript = file_get_contents(resource_path('js/config-equipe.js'));

    expect($css)
        ->toContain('[data-brand="client"] .team-motion-page')
        ->toContain('--team-primary-rgb: 0, 40, 143;')
        ->toContain('--team-dark-rgb: 0, 22, 80;')
        ->toContain('--team-accent-rgb: 230, 0, 11;')
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->toContain('.team-permission-option.is-selected')
        ->toContain('.team-permission-select-all:focus-within')
        ->and($javascript)
        ->toContain("matchMedia('(prefers-reduced-motion: reduce)')")
        ->toContain('IntersectionObserver')
        ->toContain('data-team-permission-count')
        ->toContain('data-team-permission-toggle-all');
});
