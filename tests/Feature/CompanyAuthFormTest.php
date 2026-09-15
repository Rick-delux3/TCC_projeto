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
