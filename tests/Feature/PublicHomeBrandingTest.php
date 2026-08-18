<?php

use App\Models\Corretor;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutVite();
});

it('keeps the legacy index visually isolated from the active brand', function (string $profile) {
    config([
        'features.public_index_enabled' => true,
        'branding.active' => $profile,
    ]);

    $response = $this->get(route('index'));

    $response
        ->assertOk()
        ->assertViewIs('index')
        ->assertSee('imgs/Logo_NVS.png', false)
        ->assertDontSee('imgs/logo-principal-real.jpg', false)
        ->assertDontSee('data-brand=', false);
})->with(['tcc', 'client']);

it('renders the new public page with the selected brand', function (
    string $profile,
    string $expectedLogo,
    string $unexpectedLogo,
) {
    config([
        'features.public_index_enabled' => false,
        'branding.active' => $profile,
    ]);

    $response = $this->get(route('index'));

    $response
        ->assertOk()
        ->assertViewIs('simulation.start')
        ->assertSee('data-brand="'.$profile.'"', false)
        ->assertSee($expectedLogo, false)
        ->assertDontSee($unexpectedLogo, false)
        ->assertSee('name="tipo_solicitante"', false);
})->with([
    'tcc profile' => ['tcc', 'imgs/Logo_NVS.png', 'imgs/logo-principal-real.jpg'],
    'client profile' => ['client', 'imgs/logo-principal-real.jpg', 'imgs/Logo_NVS.png'],
]);

it('keeps the public header visual and opens one accessible unavailable-access modal', function () {
    config([
        'features.public_index_enabled' => false,
        'branding.active' => 'client',
    ]);

    $response = $this->get(route('index'));
    $html = $response->getContent();

    $response
        ->assertOk()
        ->assertSeeText('Acesso indisponível')
        ->assertSeeText('O acesso ao portal das imobiliárias está indisponível nesta versão.')
        ->assertSeeText('Os formulários de simulação continuam disponíveis normalmente.');

    expect($html)
        ->toMatch('/<div class="auth-topbar__brand">.*data-brand-logo="client"/s')
        ->not->toMatch('/<a[^>]*class="auth-topbar__brand"/s')
        ->and(substr_count($html, 'id="companyAccessUnavailableModal"'))->toBe(1)
        ->and(substr_count($html, 'data-bs-target="#companyAccessUnavailableModal"'))->toBe(2);
});

it('uses the fictitious logo as the safe component fallback', function () {
    config(['branding.active' => 'unknown-profile']);

    $html = Blade::render('<x-brand-logo />');

    expect($html)
        ->toContain('imgs/Logo_NVS.png')
        ->not->toContain('imgs/logo-principal-real.jpg')
        ->toContain('data-brand-logo="tcc"');
});

it('uses the framed client logo in both dashboard headers and sidebars', function (string $profile) {
    $corretor = Corretor::query()->create([
        'name' => 'Corretor de Teste',
        'email' => 'branding-admin@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);

    $this->actingAs($corretor, 'admin');
    config(['branding.active' => $profile]);

    $adminHeader = view('layout-inicial.partials.header_admin', [
        'dashboardStats' => [],
    ])->render();

    $this->actingAs(User::factory()->create());

    $companyHeader = view('layout-inicial.partials.header_imob', [
        'dashboardStats' => [],
    ])->render();

    foreach ([$adminHeader, $companyHeader] as $html) {

        if ($profile === 'client') {
            expect(substr_count($html, 'imgs/logo-header.jpg'))->toBe(2)
                ->and($html)->not->toContain('imgs/logo-principal-real.jpg')
                ->and($html)->not->toContain('imgs/Logo_NVS.png');
        } else {
            expect(substr_count($html, 'imgs/Logo_NVS.png'))->toBe(2)
                ->and($html)->not->toContain('imgs/logo-header.jpg')
                ->and($html)->not->toContain('imgs/logo-principal-real.jpg');
        }
    }
})->with(['tcc', 'client']);

it('hides analysis navigation and keeps lead simulation available when analyses are disabled', function () {
    $this->actingAs(User::factory()->create());
    config(['features.insurance_analysis.enabled' => false]);

    $html = view('layout-inicial.partials.header_imob', [
        'dashboardStats' => [],
    ])->render();

    expect($html)
        ->not->toContain(route('insurance-analyses.index'))
        ->toContain(route('simulation.registered-company.access'))
        ->toContain('Página de simulação');

    foreach ([
        'analise',
        'insurance-analyses.index',
        'insurance-analyses.show',
        'admin.insurance-analyses.index',
        'admin.insurance-analyses.show',
    ] as $routeName) {
        expect(Route::getRoutes()->getByName($routeName)->gatherMiddleware())
            ->not->toContain('analysis.enabled');
    }

    foreach ([
        'dashboard.leads.reanalyze',
        'admin.leads.reanalyze',
        'insurance-analyses.retry',
        'admin.insurance-analyses.retry',
    ] as $routeName) {
        expect(Route::getRoutes()->getByName($routeName)->gatherMiddleware())
            ->toContain('analysis.enabled');
    }
});

it('registers one coherent root route and keeps the original route name', function () {
    $rootRoutes = collect(Route::getRoutes())
        ->filter(fn ($route) => $route->uri() === '/');

    expect($rootRoutes)->toHaveCount(1)
        ->and(Route::getRoutes()->getByName('index'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('index')->uri())->toBe('/');
});
