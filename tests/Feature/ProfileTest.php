<?php

use App\Models\User;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

beforeEach(function (): void {
    $this->withSession(['2fa_passed' => true]);
    $this->withoutVite();
});

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk()
        ->assertSee('Minha conta')
        ->assertSee('action="'.route('profile.update').'"', false)
        ->assertSee('action="'.route('password.update').'"', false)
        ->assertSee('action="'.route('profile.destroy').'"', false)
        ->assertSee('<dialog', false);
});

test('profile links the account deletion error to the password in the confirmation dialog', function () {
    $errors = new ViewErrorBag;
    $errors->put('userDeletion', new MessageBag(['password' => 'A senha atual está incorreta.']));

    $response = $this->actingAs(User::factory()->create())
        ->withSession(['errors' => $errors])
        ->get(route('profile.edit'))
        ->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $dialog = '//dialog[@id="deleteAccountModal"]';

    expect($xpath->query($dialog.'//input[@name="password" and @type="password" and @aria-invalid="true" and @aria-describedby="delete-password-error"]')->length)->toBe(1)
        ->and($xpath->query($dialog.'//*[@id="delete-password-error"]')->item(0)->textContent)->toBe('A senha atual está incorreta.')
        ->and($xpath->query('//input[@id="password" and @aria-invalid]')->length)->toBe(0);
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('userDeletion', 'password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});
