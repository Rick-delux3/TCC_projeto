<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    $this->withoutVite();
    config(['branding.active' => 'tcc']);
});

function portalAccessAdmin(array $attributes = []): Corretor
{
    return Corretor::query()->create(array_merge([
        'name' => 'Administrador de acesso',
        'email' => 'portal-admin@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
        'first_login_verified_at' => now(),
    ], $attributes));
}

it('keeps company entry routes isolated from the admin session on tcc', function (string $routeName, bool $companySession, bool $adminSession): void {
    if ($adminSession) {
        $this->actingAs(portalAccessAdmin(), 'admin');
    }
    if ($companySession) {
        $this->actingAs(User::factory()->create([
            'company_id' => Imobiliaria::factory()->create()->id,
        ]), 'web');
    }
    Auth::shouldUse('web');

    $response = $this->get(route($routeName));

    if ($companySession) {
        $response->assertRedirect(route('company.dashboard'));
        $this->get(route('company.dashboard'))->assertRedirect(route('2fa'));
        $this->withSession(['2fa_passed' => true])
            ->get(route('company.dashboard'))->assertOk()->assertViewIs('imobiliaria.dashboard-user');
    } else {
        $response->assertOk()->assertViewIs(
            $routeName === 'empresa.login' ? 'imobiliaria.company-login' : 'imobiliaria.register-company'
        );
    }

    expect(Auth::guard('admin')->check())->toBe($adminSession);
})->with(['empresa.login', 'empresa.register.form'])->with([true, false])->with([true, false]);

it('prepares the admin entry without granting administrative access to a company session', function (bool $companySession): void {
    if ($companySession) {
        $this->actingAs(User::factory()->create([
            'company_id' => Imobiliaria::factory()->create()->id,
        ]), 'web');
    }

    $this->get(route('tcc.admin.access', ['redirect' => 'https://example.test']))
        ->assertRedirect(route('admin.ceo.login'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->get(route('admin.ceo.login'))->assertOk();
    $this->get(route('Dashboard-Admin'))->assertRedirect(route('admin.login'));
    $this->assertGuest('admin');
})->with([true, false]);

it('reuses an admin session but preserves active account and two factor checks', function (bool $active, bool $verified): void {
    $this->actingAs(portalAccessAdmin([
        'active' => $active,
        'first_login_verified_at' => $verified ? now() : null,
    ]), 'admin');
    Auth::shouldUse('web');

    $this->get(route('tcc.admin.access'))->assertRedirect(route('Dashboard-Admin'));
    $response = $this->get(route('Dashboard-Admin'));

    if (! $active) {
        $response->assertRedirect(route('admin.ceo.login'));
        $this->assertGuest('admin');
    } elseif (! $verified) {
        $response->assertRedirect(route('admin.2fa.form'));
    } else {
        $response->assertOk()->assertViewIs('corretor.dashboard-admin');
    }
})->with([true, false])->with([true, false]);

it('preserves restricted sections for a verified member without lead permissions', function (): void {
    $this->actingAs(portalAccessAdmin([
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => [],
    ]), 'admin');
    Auth::shouldUse('web');

    $this->get(route('tcc.admin.access'))->assertRedirect(route('Dashboard-Admin'));
    $this->get(route('Dashboard-Admin'))->assertOk()->assertViewHas('canViewLeads', false);
    $this->get(route('admin.leads.index'))->assertForbidden();
});

it('preserves client redirections and hides the new entry outside tcc', function (string $routeName): void {
    config(['branding.active' => 'client']);
    $this->actingAs(portalAccessAdmin(), 'admin');
    $this->actingAs(User::factory()->create([
        'company_id' => Imobiliaria::factory()->create()->id,
    ]), 'web');

    $this->get(route($routeName))->assertRedirect(route('Dashboard-Admin'));
    $this->get(route('tcc.admin.access'))->assertNotFound();
})->with(['empresa.login', 'empresa.register.form']);

it('rate limits the admin entry and keeps the CEO registration closed', function (): void {
    $this->freezeTime();
    config(['admin.ceo_registration_enabled' => false]);

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->get(route('tcc.admin.access'))->assertRedirect(route('admin.ceo.login'));
    }
    $this->get(route('tcc.admin.access'))->assertTooManyRequests()->assertHeader('Retry-After');
    $this->get(route('empresa.login'))->assertTooManyRequests();
    $this->travel(61)->seconds();
    $this->get(route('tcc.admin.access'))->assertRedirect(route('admin.ceo.login'));
    $this->get(route('admin.ceo.register.access'))->assertForbidden();
});
