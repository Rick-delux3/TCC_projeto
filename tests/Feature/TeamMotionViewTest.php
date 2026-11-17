<?php

use App\Models\Corretor;

beforeEach(function () {
    $this->withoutVite();
});

it('renders the management components and access controls across every team page', function () {
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
        ->assertSee('management-page-header', false)
        ->assertSee('data-dashboard-metric', false)
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

it('keeps team selection states and reduced motion accessible', function () {
    $css = file_get_contents(resource_path('css/config-equipe.css'));
    $sharedCss = file_get_contents(resource_path('css/management-workspace.css'));
    $javascript = file_get_contents(resource_path('js/config-equipe.js'));

    expect($css)
        ->toContain('@import "./management-workspace.css";')
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->toContain('.team-permission-option.is-selected')
        ->toContain('.team-permission-select-all:focus-within')
        ->not->toContain('infinite', 'linear-gradient')
        ->and($sharedCss)
        ->toContain('[data-dashboard-theme="dark"]', ':focus-visible', '--mg-border')
        ->and($javascript)
        ->toContain("matchMedia('(prefers-reduced-motion: reduce)')")
        ->toContain('IntersectionObserver')
        ->toContain('data-team-permission-count')
        ->toContain('data-team-permission-toggle-all');
});
