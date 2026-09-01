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
        ->assertDontSee('imgs/logo-akialuga.jpg', false)
        ->assertDontSee('data-brand=', false);
})->with(['tcc', 'client']);

it('renders the new public page with the selected brand', function (
    string $profile,
    string $expectedLogo,
    string $expectedHeaderLogo,
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
        ->assertSee($expectedHeaderLogo, false)
        ->assertDontSee($unexpectedLogo, false)
        ->assertSee('name="tipo_solicitante"', false);
})->with([
    'tcc profile' => ['tcc', 'imgs/Logo_NVS.png', 'imgs/Logo_NVS.png', 'imgs/logo-header.jpg'],
    'client profile' => ['client', 'imgs/logo-akialuga.jpg', 'imgs/logo-header.jpg', 'imgs/Logo_NVS.png'],
]);

it('centralizes the complete visual identity for both brand profiles', function () {
    expect(config('branding.profiles.tcc'))
        ->toMatchArray([
            'logo' => 'imgs/Logo_NVS.png',
            'logo_header' => 'imgs/Logo_NVS.png',
            'logo_email' => 'imgs/Logo_NVS.png',
            'favicon' => 'imgs/Logo_NVS.png',
            'favicon_type' => 'image/png',
        ])
        ->and(config('branding.profiles.tcc.colors'))
        ->toMatchArray([
            'primary' => '#030133',
            'blue' => '#146FB6',
            'accent' => '#FD1E6E',
            'background' => '#F0F5FB',
            'surface' => '#FFFFFF',
            'text' => '#1F2937',
            'text_muted' => '#55658C',
            'border' => '#D8E1EC',
        ])
        ->and(config('branding.profiles.client'))
        ->toMatchArray([
            'logo' => 'imgs/logo-akialuga.jpg',
            'logo_header' => 'imgs/logo-header.jpg',
            'logo_email' => 'imgs/logo-akialuga.jpg',
            'favicon' => 'imgs/logo-akialuga.jpg',
            'favicon_type' => 'image/jpeg',
        ])
        ->and(config('branding.profiles.client.colors'))
        ->toMatchArray([
            'primary' => '#00288F',
            'primary_dark' => '#001650',
            'primary_hover' => '#001F73',
            'accent' => '#E6000B',
            'background' => '#F3F6FC',
            'surface' => '#FFFFFF',
            'text' => '#14213D',
            'text_muted' => '#53617A',
            'border' => '#D5DEEB',
        ]);
});

it('isolates the client logo palette and preserves the original tcc tokens', function () {
    $css = file_get_contents(resource_path('css/branding.css'));
    $clientStart = strpos($css, '[data-brand="client"]');
    $clientOverrideStart = strrpos(substr($css, 0, strpos($css, 'Perfil Client | identidade Aki Aluga / Neves')), '/*');
    $clientStyles = substr($css, $clientStart, $clientOverrideStart - $clientStart);

    expect(preg_match('/\[data-brand="tcc"\]\s*\{(?<tokens>.*?)\}/s', $css, $tccMatch))->toBe(1)
        ->and(preg_match('/\[data-brand="client"\]\s*\{(?<tokens>.*?)\}/s', $css, $clientMatch))->toBe(1);

    expect($tccMatch['tokens'])
        ->toContain('--brand-primary: #030133;')
        ->toContain('--brand-primary-hover: #146fb6;')
        ->toContain('--brand-accent: #fd1e6e;')
        ->not->toContain('#00288f')
        ->not->toContain('#e6000b')
        ->and($clientMatch['tokens'])
        ->toContain('--brand-primary: #00288f;')
        ->toContain('--brand-primary-dark: #001650;')
        ->toContain('--brand-accent: #e6000b;')
        ->toContain('--brand-background: #f3f6fc;')
        ->not->toContain('#030133')
        ->not->toContain('#146fb6')
        ->not->toContain('#fd1e6e')
        ->and($clientStyles)
        ->not->toContain('#0000ff')
        ->not->toContain('#0000b8')
        ->not->toContain('#8a8aff')
        ->not->toContain('rgba(255, 0, 0')
        ->not->toContain('rgba(253, 30, 110')
        ->and($css)
        ->toContain('[data-brand="client"] .lead-filter-submit')
        ->toContain('[data-brand="client"] .simulation-btn--accent')
        ->toMatch('/\[data-brand="client"\] \.btn-primary\s*\{.*?--bs-btn-bg: #00288f;.*?background-image: none !important;/s');
});

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
        ->and(substr_count($html, 'data-bs-target="#companyAccessUnavailableModal"'))->toBe(1);
});

it('uses the fictitious logo as the safe component fallback', function () {
    config(['branding.active' => 'unknown-profile']);

    $html = Blade::render('<x-brand-logo />');

    expect($html)
        ->toContain('imgs/Logo_NVS.png')
        ->not->toContain('imgs/logo-akialuga.jpg')
        ->toContain('data-brand-logo="tcc"');
});

it('resolves configured logo variants without leaking assets between brands', function () {
    config(['branding.active' => 'tcc']);

    $tccHeader = Blade::render('<x-brand-logo variant="logo_header" />');
    $tccApplicationLogo = Blade::render('<x-application-logo class="application-mark" />');
    $tccFavicon = Blade::render('<x-brand-favicon />');

    expect($tccHeader)
        ->toContain('imgs/Logo_NVS.png')
        ->not->toContain('imgs/logo-header.jpg')
        ->and($tccApplicationLogo)
        ->toContain('imgs/Logo_NVS.png')
        ->toContain('class="application-mark"')
        ->not->toContain('<svg')
        ->and($tccFavicon)
        ->toContain('type="image/png"')
        ->toContain('imgs/Logo_NVS.png');

    config(['branding.active' => 'client']);

    $clientHeader = Blade::render('<x-brand-logo variant="logo_header" />');
    $clientApplicationLogo = Blade::render('<x-application-logo />');
    $clientFavicon = Blade::render('<x-brand-favicon />');

    expect($clientHeader)
        ->toContain('imgs/logo-header.jpg')
        ->not->toContain('imgs/Logo_NVS.png')
        ->and($clientApplicationLogo)
        ->toContain('imgs/logo-akialuga.jpg')
        ->not->toContain('<svg')
        ->and($clientFavicon)
        ->toContain('type="image/jpeg"')
        ->toContain('imgs/logo-akialuga.jpg');
});

it('uses the configured header logo in the shared authentication topbar', function (
    string $profile,
    string $expectedLogo,
    string $unexpectedLogo,
) {
    config(['branding.active' => $profile]);

    $html = view('auth.partials.auth-topbar')->render();

    expect($html)
        ->toContain($expectedLogo)
        ->not->toContain($unexpectedLogo)
        ->toContain('object-fit: contain;');
})->with([
    'tcc topbar' => ['tcc', 'imgs/Logo_NVS.png', 'imgs/logo-header.jpg'],
    'client topbar' => ['client', 'imgs/logo-header.jpg', 'imgs/Logo_NVS.png'],
]);

it('uses active brand tokens in the shared loader without legacy palette leakage', function () {
    $loader = file_get_contents(resource_path('views/partials/page-loader.blade.php'));
    $css = file_get_contents(resource_path('css/branding.css'));

    expect($loader)
        ->toContain('var(--brand-primary-rgb, 3, 1, 51)')
        ->toContain('var(--brand-accent, #FD1E6E)')
        ->toContain('var(--brand-primary, #030133)')
        ->not->toContain('#EE1D23')
        ->not->toContain('#1F1D59')
        ->and($css)
        ->toMatch('/\[data-brand="tcc"\]\s*\{.*?--brand-primary-rgb:\s*3,\s*1,\s*51;/s');
});

it('hides the NVS-only two-factor illustration from the client profile', function () {
    $css = file_get_contents(resource_path('css/branding.css'));

    expect($css)
        ->toMatch('/\[data-brand="client"\] \.verify-aside > img\s*\{\s*display:\s*none;\s*\}/s');
});

it('keeps both web two-factor views isolated to the active brand', function (
    string $profile,
    string $brandName,
    string $expectedPrimaryLogo,
    string $expectedHeaderLogo,
    string $unexpectedLogo,
    string $faviconType,
) {
    config(['branding.active' => $profile]);

    $viewData = ['errors' => new Illuminate\Support\ViewErrorBag];
    $companyHtml = view('auth.2fa', $viewData)->render();
    $adminHtml = view('auth.admin-2fa', $viewData)->render();

    expect($companyHtml)
        ->toContain('data-brand="'.$profile.'"')
        ->toContain($brandName)
        ->toContain($expectedPrimaryLogo)
        ->toContain($expectedHeaderLogo)
        ->toContain('type="'.$faviconType.'"')
        ->not->toContain($unexpectedLogo)
        ->and($adminHtml)
        ->toContain('data-brand="'.$profile.'"')
        ->toContain($brandName)
        ->toContain($expectedHeaderLogo)
        ->toContain('type="'.$faviconType.'"')
        ->not->toContain($unexpectedLogo);
})->with([
    'tcc / NVS' => [
        'tcc',
        'NVS Seguros',
        'imgs/Logo_NVS.png',
        'imgs/Logo_NVS.png',
        'imgs/logo-header.jpg',
        'image/png',
    ],
    'client / Aki Aluga' => [
        'client',
        'Aki Aluga',
        'imgs/logo-akialuga.jpg',
        'imgs/logo-header.jpg',
        'imgs/Logo_NVS.png',
        'image/jpeg',
    ],
]);

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
                ->and($html)->not->toContain('imgs/logo-akialuga.jpg')
                ->and($html)->not->toContain('imgs/Logo_NVS.png');
        } else {
            expect(substr_count($html, 'imgs/Logo_NVS.png'))->toBe(2)
                ->and($html)->not->toContain('imgs/logo-header.jpg')
                ->and($html)->not->toContain('imgs/logo-akialuga.jpg');
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
