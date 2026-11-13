<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Support\CorretorPermissions;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function (): void {
    $this->withoutVite();
    config(['features.insurance_analysis.enabled' => false, 'services.leadlovers.enabled' => false]);
});

function workspaceAdmin(array $attributes = []): Corretor
{
    return Corretor::query()->create(array_merge([
        'name' => 'Gestor da corretora',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ], $attributes));
}

function workspaceDocument(string $html): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

it('offers the shared theme control on every admin page for both brands', function (string $brand): void {
    config(['branding.active' => $brand]);
    $this->actingAs(workspaceAdmin(), 'admin');
    $member = workspaceAdmin(['role' => Corretor::ROLE_INTEGRANTE]);

    foreach (['Dashboard-Admin', 'admin.config-equipe.index', 'admin.config-equipe.create', 'admin.config-equipe.edit', 'admin.imobiliarias.index', 'admin.imobiliarias.create'] as $route) {
        $parameters = $route === 'admin.config-equipe.edit' ? [$member] : [];
        $response = $this->get(route($route, $parameters))->assertOk();
        $xpath = workspaceDocument($response->getContent());

        expect($xpath->query('//body[@data-brand="'.$brand.'"]')->length)->toBe(1)
            ->and($xpath->query('//header//button[@data-dashboard-theme-toggle][@type="button"][@aria-label="Modo escuro"]')->length)->toBe(1)
            ->and($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " dashboard-shell ")]')->length)->toBe(1);
    }
})->with(['tcc', 'client']);

it('keeps navigation in the sidebar without a duplicate header row', function (string $route, string $title, string $active): void {
    $this->actingAs(workspaceAdmin(), 'admin');
    $response = $this->get(route($route))->assertOk()->assertSee($title);
    $xpath = workspaceDocument($response->getContent());
    $navigation = '//div[@id="dashboardAdminSidebar"]//nav';

    expect($xpath->query('//header//div[contains(@class, "dashboard-client-header__secondary")]')->length)->toBe(0)
        ->and($xpath->query('//header//div[contains(@class, "dashboard-header-breadcrumb")]')->length)->toBe(0)
        ->and($xpath->query('//header//button[@data-bs-target="#dashboardAdminSidebar"]')->length)->toBe(1)
        ->and($xpath->query($navigation)->length)->toBe(1);

    foreach (['Dashboard-Admin', 'admin.leads.index', 'admin.imobiliarias.index', 'admin.config-equipe.index'] as $destination) {
        expect($xpath->query($navigation.'//a[@href="'.route($destination).'"]')->length)->toBe(1);
    }

    expect(trim($xpath->query($navigation.'//a[contains(concat(" ", normalize-space(@class), " "), " active ")]')->item(0)->textContent))->toBe($active)
        ->and($xpath->query('//header//a[@href="#"]')->length)->toBe(0)
        ->and($xpath->query('//form[@action="'.route('admin.logout').'"][@method="POST"]//input[@name="_token"]')->length)->toBe(2);

    $response->assertDontSee('Alternar demo')
        ->assertDontSee('Ver lista preenchida')
        ->assertDontSee('5511999999999')
        ->assertDontSee('cdn.tailwindcss.com', false);
})->with([
    ['Dashboard-Admin', 'Central de leads', 'Dashboard'],
    ['admin.config-equipe.index', 'Gerenciar equipe', 'Equipe'],
    ['admin.imobiliarias.index', 'Imobiliárias cadastradas', 'Imobiliárias'],
]);

it('renders colored left-edge bars on the four dashboard statistic cards', function (): void {
    $this->actingAs(workspaceAdmin(), 'admin');
    $response = $this->get(route('Dashboard-Admin'))->assertOk();
    $xpath = workspaceDocument($response->getContent());
    $stylesheet = file_get_contents(resource_path('css/dashboard-admin.css'));
    $workspaceStylesheet = file_get_contents(resource_path('css/admin-workspace.css'));

    foreach (['clients', 'approved', 'rejected', 'companies'] as $statistic) {
        $cards = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " dashboard-stat-card--'.$statistic.' ")]',
        );

        expect($cards->length)->toBe(1)
            ->and($cards->item(0)->textContent)->not->toContain('●');
    }

    expect($stylesheet)
        ->toMatch('/\.dashboard-stat-card--clients::before,[\s\S]*?\{[^}]*width:\s*5px;[^}]*border-top-left-radius:\s*inherit;[^}]*border-top-right-radius:\s*0;[^}]*border-bottom-right-radius:\s*0;[^}]*border-bottom-left-radius:\s*inherit;/')
        ->toContain('--dashboard-stat-accent: var(--bs-primary);')
        ->toContain('--dashboard-stat-accent: var(--bs-success);')
        ->toContain('--dashboard-stat-accent: var(--bs-danger);')
        ->toContain('--dashboard-stat-accent: var(--bs-info);')
        ->and($workspaceStylesheet)
        ->toMatch('/\.dashboard-admin-body \.admin-leads-page :is\(\.dashboard-stat-card--clients,[^}]*\{\s*border:\s*2px solid #cbd5e1 !important;/')
        ->toContain('border-color: #64748b !important;');
});

it('requires authentication for every redesigned page', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('admin.login'));
})->with(['Dashboard-Admin', 'admin.config-equipe.index', 'admin.imobiliarias.index']);

it('rejects inactive and unverified sessions on every redesigned page', function (string $route): void {
    $this->actingAs(workspaceAdmin(['first_login_verified_at' => null]), 'admin')
        ->get(route($route))->assertRedirect(route('admin.2fa.form'));

    $this->actingAs(workspaceAdmin(['active' => false]), 'admin')
        ->get(route($route))->assertRedirect(route('admin.ceo.login'));
})->with(['Dashboard-Admin', 'admin.config-equipe.index', 'admin.imobiliarias.index']);

it('hides restricted navigation and rejects direct access from a lead viewer', function (): void {
    $this->actingAs(workspaceAdmin([
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => [CorretorPermissions::VIEW_LEADS],
    ]), 'admin');

    $response = $this->get(route('Dashboard-Admin'))->assertOk();
    $xpath = workspaceDocument($response->getContent());

    foreach (['admin.config-equipe.index', 'admin.imobiliarias.index'] as $destination) {
        expect($xpath->query('//a[@href="'.route($destination).'"]')->length)->toBe(0);
        $this->get(route($destination))->assertForbidden();
    }

    $target = workspaceAdmin(['role' => Corretor::ROLE_INTEGRANTE]);
    $this->put(route('admin.config-equipe.update', $target), ['active' => false])->assertForbidden();
    $this->post(route('admin.config-equipe.resend-invitation', $target))->assertForbidden();
    expect($target->fresh()->active)->toBeTrue();
});

it('escapes stored names in the header and all redesigned lists', function (): void {
    $payload = '<img src=x onerror=alert(document.domain)>';
    $this->actingAs(workspaceAdmin(['name' => $payload]), 'admin');
    $company = Imobiliaria::factory()->create(['name' => $payload]);
    Lead::query()->forceCreate([
        'nome' => $payload,
        'email' => fake()->safeEmail(),
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'status' => 'novo',
        'company_id' => $company->id,
    ]);

    foreach (['Dashboard-Admin', 'admin.config-equipe.index', 'admin.imobiliarias.index'] as $route) {
        $response = $this->get(route($route))->assertOk();
        $response->assertSee(e($payload), false)->assertDontSee($payload, false);
        expect(workspaceDocument($response->getContent())->query('//img[@onerror]')->length)->toBe(0);
    }
});

it('escapes reflected search values without creating executable attributes', function (string $route, string $parameter): void {
    $this->actingAs(workspaceAdmin(), 'admin');
    $payload = '"><svg onload=alert(1)>';
    $response = $this->get(route($route, [$parameter => $payload]))->assertOk();
    $response->assertSee(e($payload), false)->assertDontSee($payload, false);
    expect(workspaceDocument($response->getContent())->query('//svg[@onload]')->length)->toBe(0);
})->with([
    ['Dashboard-Admin', 'lead_name'],
    ['admin.config-equipe.index', 'search'],
    ['admin.imobiliarias.index', 'search'],
]);

it('retains actual team controls and prevents editing the CEO', function (): void {
    $ceo = workspaceAdmin();
    $member = workspaceAdmin(['role' => Corretor::ROLE_INTEGRANTE]);
    $this->actingAs($ceo, 'admin');
    $response = $this->get(route('admin.config-equipe.index'))->assertOk();
    $xpath = workspaceDocument($response->getContent());

    expect($xpath->query('//a[@href="'.route('admin.config-equipe.create').'"]')->length)->toBe(1)
        ->and($xpath->query('//a[@href="'.route('admin.config-equipe.edit', $member).'"]')->length)->toBe(1)
        ->and($xpath->query('//form[@action="'.route('admin.config-equipe.update', $member).'"]//input[@name="_token"]')->length)->toBe(1);

    $this->get(route('admin.config-equipe.edit', $ceo))->assertForbidden();
    $this->put(route('admin.config-equipe.update', $ceo), ['active' => false])->assertForbidden();
});

it('rejects state changes without CSRF tokens on all redesigned page flows', function (): void {
    $this->app->bind(ValidateCsrfToken::class, function ($app): ValidateCsrfToken {
        return new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
    });

    $this->actingAs(workspaceAdmin(), 'admin');
    $member = workspaceAdmin(['role' => Corretor::ROLE_INTEGRANTE]);
    $company = Imobiliaria::factory()->create();

    $this->post(route('admin.logout'))->assertStatus(419);
    $this->put(route('admin.config-equipe.update', $member), ['active' => false])->assertStatus(419);
    $this->post(route('admin.config-equipe.resend-invitation', $member))->assertStatus(419);
    $this->patch(route('admin.imobiliarias.update', $company), ['name' => 'Alteração indevida'])->assertStatus(419);
    $this->delete(route('admin.imobiliarias.destroy', $company))->assertStatus(419);
    $this->post(route('admin.leads.update', ['lead' => 1]))->assertStatus(419);

    expect($member->fresh()->active)->toBeTrue()
        ->and($company->fresh()->name)->toBe($company->name);
});

it('offers a real header search and a shortcut to the lead form', function (): void {
    $this->actingAs(workspaceAdmin(), 'admin');
    $response = $this->get(route('Dashboard-Admin', ['lead_name' => 'Anthony']))->assertOk();
    $xpath = workspaceDocument($response->getContent());

    expect($xpath->query('//header//form[@role="search"][@method="GET"][@action="'.route('Dashboard-Admin').'#leads-section"]//input[@name="lead_name"][@value="Anthony"]')->length)->toBe(1)
        ->and($xpath->query('//header//button[@data-bs-target="#adminSimulationModal"]')->length)->toBe(1)
        ->and($xpath->query('//div[@id="adminSimulationModal"]')->length)->toBe(1)
        ->and($xpath->query('//button[@id="dashboardThemeToggle"]')->length)->toBe(0);

    $response = $this->get(route('admin.config-equipe.index'))->assertOk();
    expect(workspaceDocument($response->getContent())->query('//header//a[@href="'.route('Dashboard-Admin').'#new-lead-title"]')->length)->toBe(1);
});

it('uses the transparent NVS logo in the admin header and sidebar', function (): void {
    config(['branding.active' => 'tcc']);
    $this->actingAs(workspaceAdmin(), 'admin');
    $response = $this->get(route('Dashboard-Admin'))->assertOk();
    $xpath = workspaceDocument($response->getContent());
    $logoUrl = asset('imgs/Logo_NVS-transparent.png');

    expect($xpath->query('//header//img[@src="'.$logoUrl.'"]')->length)->toBe(1)
        ->and($xpath->query('//div[@id="dashboardAdminSidebar"]//img[@src="'.$logoUrl.'"]')->length)->toBe(1);

    expect(file_get_contents(resource_path('css/admin-workspace.css')))
        ->toMatch('/#dashboardAdminSidebar \.dashboard-sidebar-logo,[^{]*\{[^}]*border:\s*0;[^}]*background:\s*transparent;/');

    $logo = file_get_contents(public_path('imgs/Logo_NVS-transparent.png'));
    $dimensions = getimagesize(public_path('imgs/Logo_NVS-transparent.png'));

    expect(substr($logo, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(ord($logo[25]))->toBe(6)
        ->and($dimensions[0])->toBe(633)
        ->and($dimensions[1])->toBe(610);
});

it('uses the transparent NVS logo on team member forms', function (string $routeName): void {
    config(['branding.active' => 'tcc']);
    $this->actingAs(workspaceAdmin(), 'admin');
    $parameters = $routeName === 'admin.config-equipe.edit'
        ? [workspaceAdmin(['role' => Corretor::ROLE_INTEGRANTE])]
        : [];
    $response = $this->get(route($routeName, $parameters))->assertOk();
    $xpath = workspaceDocument($response->getContent());

    expect($xpath->query('//span[@class="team-create-brand"]//img[@src="'.asset('imgs/Logo_NVS-transparent.png').'"]')->length)->toBe(1);

    expect(file_get_contents(resource_path('css/admin-workspace.css')))
        ->toMatch('/\.dashboard-admin-body \.team-create-brand img\s*\{\s*width: 56px;\s*height: 56px;\s*object-fit: contain;/');
})->with(['admin.config-equipe.create', 'admin.config-equipe.edit']);
