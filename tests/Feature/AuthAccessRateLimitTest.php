<?php

beforeEach(function () {
    $this->withoutVite();
    $this->freezeTime();
    config(['branding.active' => 'tcc']);
});

it('shares the page limit across tcc access forms and restores access after a minute', function () {
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->get(route('empresa.login'))->assertOk();
    }

    foreach (['empresa.login', 'empresa.register.form', 'admin.login', 'admin.ceo.login', 'company.password.request'] as $routeName) {
        $this->get(route($routeName))->assertTooManyRequests()->assertHeader('Retry-After');
    }

    $this->get(route('company.password.reset', ['token' => 'test-token']))->assertTooManyRequests();

    config(['admin.ceo_registration_enabled' => true, 'admin.ceo_registration_secret' => 'test-secret']);
    $this->get(route('admin.ceo.register.access'))->assertTooManyRequests();
    $this->post(route('admin.ceo.register.authorize'), ['key' => 'test-secret'])
        ->assertRedirect(route('admin.ceo.register.form'));
    $this->get(route('admin.ceo.register.form'))->assertTooManyRequests();
    $this->postJson(route('empresa.login.post'), [])->assertUnprocessable();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])
        ->get(route('empresa.login'))->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    $this->travel(61)->seconds();
    $this->get(route('empresa.login'))->assertOk();
});

it('keeps broker page views separate from credential submissions on tcc', function () {
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->get(route('admin.login'))->assertOk();
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('admin.member.login.post'), [])->assertUnprocessable();
    }

    $this->postJson(route('admin.member.login.post'), [])->assertTooManyRequests();
    $this->get(route('admin.ceo.login'))->assertOk();
});

it('preserves the existing broker page limit for client', function () {
    config(['branding.active' => 'client']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->get(route('admin.login'))->assertOk();
    }

    $this->get(route('admin.login'))->assertTooManyRequests();
});

it('does not add a company page limit for client', function () {
    config(['branding.active' => 'client']);

    for ($attempt = 0; $attempt < 31; $attempt++) {
        $this->get(route('empresa.login'))->assertOk();
    }
});

it('allows five credential attempts independently of page views and unrelated throttles', function (string $profile, string $form, string $post, array $credentials, string $field) {
    config(['branding.active' => $profile]);

    for ($attempt = 0; $attempt < 4; $attempt++) {
        $this->postJson(route('company.password.email'), [])->assertUnprocessable();
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->get(route($form))->assertOk();
        $this->from(route($form))->post($post, $credentials)
            ->assertRedirect(route($form))
            ->assertSessionHasErrors([$field => $field === 'cpf' ? 'CPF ou senha incorretos.' : 'E-mail ou senha incorretos.']);
        $this->assertGuest('admin');
        $this->assertGuest('web');
    }

    $this->post($post, $credentials)->assertTooManyRequests()->assertHeader('Retry-After');
    $this->travel(301)->seconds();
    $this->from(route($form))->post($post, $credentials)->assertRedirect(route($form))
        ->assertSessionHasErrors([$field => $field === 'cpf' ? 'CPF ou senha incorretos.' : 'E-mail ou senha incorretos.']);
})->with(['tcc', 'client'])->with([
    'company' => ['empresa.login', '/empresa/login/post', ['email' => 'missing@example.test', 'password' => 'wrong-password'], 'email'],
    'legacy company' => ['empresa.login', '/login', ['email' => 'missing@example.test', 'password' => 'wrong-password'], 'email'],
    'broker member' => ['admin.login', '/member/admin/login', ['email' => 'missing@example.test', 'password' => 'wrong-password'], 'email'],
    'broker CEO' => ['admin.ceo.login', '/ceo/admin/login', ['cpf' => '52998224725', 'password' => 'wrong-password'], 'cpf'],
]);

it('shares the five company attempts across both login addresses', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson($attempt % 2 === 0 ? '/login' : '/empresa/login/post', [])->assertUnprocessable();
    }

    $this->postJson('/login', [])->assertTooManyRequests();
    $this->postJson('/empresa/login/post', [])->assertTooManyRequests();
});
