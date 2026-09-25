<?php

use App\Models\LeadLoversTag;

it('renders contextual icons and accessible password controls on the company registration form', function () {
    LeadLoversTag::create([
        'leadlovers_tag_id' => 123,
        'title' => 'Imobiliária Exemplo',
        'key' => 'imobiliaria_exemplo',
        'active' => true,
    ]);

    $response = $this->get(route('empresa.register.form'));

    $response->assertOk()
        ->assertSee('client-input-icon', false)
        ->assertSee('data-toggle-password="password"', false)
        ->assertSee('data-toggle-password="password_confirmation"', false)
        ->assertSee('data-password-icon="show"', false)
        ->assertSee('data-password-icon="hide"', false)
        ->assertSee('name="website"', false)
        ->assertSee('minlength="8"', false)
        ->assertDontSee('>Ver</button>', false);
});

it('renders the email icon and accessible password control on the company login form', function () {
    $response = $this->get(route('empresa.login'));

    $response->assertOk()
        ->assertSee('client-input-icon', false)
        ->assertSee('class="client-input client-input--with-icon', false)
        ->assertSee('data-toggle-password="password"', false)
        ->assertSee('data-password-icon="show"', false)
        ->assertSee('data-password-icon="hide"', false)
        ->assertSee('autocomplete="current-password"', false)
        ->assertDontSee('>Ver</button>', false);
});

it('renders the tcc company access pages with the redesign and working destinations', function () {
    config(['branding.active' => 'tcc']);

    $login = $this->get(route('empresa.login'));
    $registration = $this->get(route('empresa.register.form'));

    $login->assertOk()
        ->assertSee('tcc-company-auth-body', false)
        ->assertSee('tcc-company-auth-main', false)
        ->assertSee('tcc-company-auth', false)
        ->assertSee('imgs/segure-chave-a-mao-ao-ar-livre.jpg', false)
        ->assertSee(route('company.password.request'), false)
        ->assertSee(route('empresa.register.form'), false)
        ->assertSee(route('empresa.login.post'), false)
        ->assertDontSee('images.unsplash.com', false)
        ->assertDontSee('cdn.tailwindcss.com', false);

    $registration->assertOk()
        ->assertSee('tcc-company-auth-body', false)
        ->assertSee('tcc-company-auth-main', false)
        ->assertSee('tcc-company-auth', false)
        ->assertSee('imgs/seguro-fianca-locaticia_fundo_login_cadastro.png', false)
        ->assertSee(route('empresa.register.post'), false)
        ->assertSee(route('empresa.login'), false);
});

it('links the company access header to the imobiliaria login throughout the company auth journey', function () {
    foreach ([
        'registration' => route('empresa.register.form'),
        'login' => route('empresa.login'),
        'password recovery' => route('company.password.request'),
    ] as $page) {
        $response = $this->get($page)->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $accessLink = $xpath->query('//header[contains(@class, "auth-topbar")]//a[contains(concat(" ", normalize-space(@class), " "), " auth-topbar__access ")]')->item(0);

        expect($accessLink)->not->toBeNull()
            ->and($accessLink->getAttribute('href'))->toBe(route('empresa.login'));
    }
});

it('keeps the original company access layout for the client profile', function () {
    config(['branding.active' => 'client']);

    $this->get(route('empresa.login'))
        ->assertOk()
        ->assertDontSee('tcc-company-auth-body', false)
        ->assertDontSee('tcc-company-auth-main', false);
});
