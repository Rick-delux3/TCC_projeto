<?php

use App\Models\Corretor;

beforeEach(function () {
    config([
        'admin.ceo_registration_enabled' => true,
        'admin.ceo_registration_secret' => 'test-secret',
    ]);
});

it('offers member login from the CEO form only for tcc', function (string $profile) {
    config(['branding.active' => $profile]);

    $response = $this->get(route('admin.ceo.login'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $links = $xpath->query('//form//a[contains(@class, "admin-outline-link")]');

    expect($links->length)->toBe($profile === 'tcc' ? 1 : 0);

    if ($profile === 'tcc') {
        expect($links->item(0)->getAttribute('href'))->toBe(route('admin.login'))
            ->and(trim($links->item(0)->textContent))->toBe('Entrar como integrante');

        $response->assertSee('tcc-ceo-login', false);
        $this->get($links->item(0)->getAttribute('href'))
            ->assertOk()
            ->assertViewIs('corretor.admin-member-login');
    } else {
        $response->assertDontSee('tcc-ceo-login', false);
    }
})->with(['tcc', 'client']);

it('uses the standalone company layout only for tcc broker access forms', function (string $profile, string $routeName, string $formAction) {
    config(['branding.active' => $profile]);

    if ($routeName === 'admin.ceo.register.form') {
        $this->post(route('admin.ceo.register.authorize'), ['key' => 'test-secret'])
            ->assertRedirect(route($routeName));
    }

    $response = $this->get(route($routeName))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $isTcc = $profile === 'tcc';

    $logos = $xpath->query('//aside[contains(@class, "tcc-auth-logo-aside")]/img[contains(@class, "tcc-auth-background-logo")]');
    $originalImage = $routeName === 'admin.login'
        ? 'imgs/segure-chave-a-mao-ao-ar-livre.jpg'
        : 'imgs/divulgar-imoveis-online-site-para-imobiliaria.jpg';

    expect($logos->length)->toBe($isTcc ? 1 : 0);

    if ($isTcc) {
        expect($logos->item(0)->getAttribute('src'))->toBe(asset('imgs/Logo_NVS.png'))
            ->and($logos->item(0)->getAttribute('alt'))->toBe('')
            ->and($logos->item(0)->getAttribute('aria-hidden'))->toBe('true');
        $response->assertDontSee($originalImage, false);
    } else {
        $response->assertSee($originalImage, false);
    }

    expect($xpath->query('//header[contains(@class, "auth-topbar")]')->length)->toBe($isTcc ? 0 : 1)
        ->and($xpath->query('//main[contains(@class, "tcc-company-auth-main--standalone")]')->length)->toBe($isTcc ? 1 : 0)
        ->and($xpath->query('//main/div[contains(@class, "tcc-company-auth")]')->length)->toBe($isTcc ? 1 : 0)
        ->and($xpath->query('//main//h1')->length)->toBe(1);

    $response->assertSee('action="'.route($formAction).'"', false)
        ->assertSee('name="_token"', false);
})->with(['tcc', 'client'])->with([
    ['admin.login', 'admin.member.login.post'],
    ['admin.ceo.login', 'admin.ceo.login.post'],
    ['admin.ceo.register.access', 'admin.ceo.register.authorize'],
    ['admin.ceo.register.form', 'admin.ceo.register.post'],
]);

it('renders contextual icons and accessible password controls on the CEO registration form', function () {
    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.11'])
        ->post(route('admin.ceo.register.authorize'), [
            'key' => 'test-secret',
        ])
        ->assertRedirect(route('admin.ceo.register.form'));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.11'])
        ->get(route('admin.ceo.register.form'));

    $response->assertOk()
        ->assertSee('admin-input-icon', false)
        ->assertSee('data-toggle-password="password"', false)
        ->assertSee('data-toggle-password="password_confirmation"', false)
        ->assertSee('data-password-icon="show"', false)
        ->assertSee('data-password-icon="hide"', false)
        ->assertSee('name="website"', false)
        ->assertSee('minlength="8"', false)
        ->assertDontSee('>Ver</button>', false);

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('renders the CPF icon and accessible password control on the CEO login form', function () {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.12'])
        ->get(route('admin.ceo.login'));

    $response->assertOk()
        ->assertSee('admin-input-icon', false)
        ->assertSee('autocomplete="username"', false)
        ->assertSee('data-toggle-password="password"', false)
        ->assertSee('data-password-icon="show"', false)
        ->assertSee('data-password-icon="hide"', false)
        ->assertDontSee('>Ver</button>', false);
});

it('renders the email icon and accessible password control on the member login form', function () {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.13'])
        ->get(route('admin.login'));

    $response->assertOk()
        ->assertSee('client-input-icon', false)
        ->assertSee('autocomplete="username"', false)
        ->assertSee('data-toggle-password="password"', false)
        ->assertSee('data-password-icon="show"', false)
        ->assertSee('data-password-icon="hide"', false)
        ->assertDontSee('>Ver</button>', false);
});

it('rejects an invalid CPF, weak password, and a filled honeypot on CEO registration', function () {
    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.14'])
        ->post(route('admin.ceo.register.authorize'), [
            'key' => 'test-secret',
        ])
        ->assertRedirect(route('admin.ceo.register.form'));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.14'])
        ->from(route('admin.ceo.register.form'))
        ->post(route('admin.ceo.register.post'), [
            'website' => 'https://spam.example',
            'name' => 'CEO Teste',
            'email' => 'ceo@example.test',
            'cpf' => '11111111111',
            'password' => 'fraca',
            'password_confirmation' => 'fraca',
        ]);

    $response
        ->assertRedirect()
        ->assertSessionHasErrors(['website', 'cpf', 'password']);

    expect(Corretor::query()->where('role', Corretor::ROLE_CEO)->exists())
        ->toBeFalse();
});

it('registers one CEO with normalized and validated credentials', function () {
    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.15'])
        ->post(route('admin.ceo.register.authorize'), [
            'key' => 'test-secret',
        ])
        ->assertRedirect(route('admin.ceo.register.form'));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.15'])
        ->post(route('admin.ceo.register.post'), [
            'website' => '',
            'name' => '  CEO   Teste  ',
            'email' => ' CEO@EXAMPLE.TEST ',
            'cpf' => '529.982.247-25',
            'password' => 'senha1234',
            'password_confirmation' => 'senha1234',
        ]);

    $response
        ->assertRedirect(route('admin.ceo.login'))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('corretores', [
        'name' => 'CEO Teste',
        'email' => 'ceo@example.test',
        'cpf' => '52998224725',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
    ]);

    expect(Corretor::query()->where('role', Corretor::ROLE_CEO)->count())->toBe(1);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.15'])
        ->get(route('admin.ceo.register.access'))
        ->assertForbidden();
});
