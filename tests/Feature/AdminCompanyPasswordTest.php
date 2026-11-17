<?php

use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Models\TwoFactorCode;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->admin = Corretor::query()->create([
        'name' => 'Gestor de acessos', 'email' => 'gestor@example.test', 'cpf' => '52998224725',
        'password' => 'senha1234', 'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => ['imobiliarias.visualizar', 'imobiliarias.editar'],
        'active' => true, 'first_login_verified_at' => now(),
    ]);
    $this->company = Imobiliaria::factory()->create();
    $this->user = User::factory()->create([
        'company_id' => $this->company->id, 'email' => $this->company->email,
        'password' => $this->company->password, 'remember_token' => 'old-remember-token',
    ]);
    $this->url = route('admin.imobiliarias.password.update', $this->company);
    $this->newPassword = 'AcessoSeguro2026!';
});

it('updates company and linked user credentials without exposing secrets', function (): void {
    $otherCompany = Imobiliaria::factory()->create();
    $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
    $otherHash = $otherUser->password;
    TwoFactorCode::query()->create(['user_id' => $this->user->id, 'code' => Hash::make('482619'), 'expires_at' => now()->addMinutes(10)]);
    Password::broker('companies')->createToken($this->company);
    Password::broker('users')->createToken($this->user);
    $response = $this->actingAs($this->admin, 'admin')->putJson($this->url, [
        'password' => $this->newPassword, 'password_confirmation' => $this->newPassword,
        'email' => 'injected@example.test', 'company_id' => $otherCompany->id,
    ])->assertOk()->assertJsonPath('has_password', true)->assertJsonPath('company_id', $this->company->id)
        ->assertHeader('Cache-Control', 'no-store, private');
    $company = $this->company->fresh();
    $user = $this->user->fresh();
    expect(Hash::check($this->newPassword, $company->password))->toBeTrue()
        ->and($user->password)->toBe($company->password)
        ->and($user->remember_token)->not->toBe('old-remember-token')
        ->and($otherUser->fresh()->password)->toBe($otherHash)
        ->and($company->email)->toBe($this->company->email);
    $response->assertDontSee($this->newPassword)->assertDontSee($company->password);
    $this->assertDatabaseMissing('two_factor_codes', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('company_password_reset_tokens', ['email' => $company->email]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $audit = CorretorActivityLog::query()->where('action', 'imobiliaria_password_updated')->sole();
    expect($audit->new_values)->toBe(['password_changed' => true])
        ->and($audit->toJson())->not->toContain($this->newPassword, $company->password);
    Http::assertNothingSent();
});

it('can define a first password and provision the missing login user', function (): void {
    $company = Imobiliaria::factory()->create(['password' => '']);
    $this->actingAs($this->admin, 'admin')->putJson(route('admin.imobiliarias.password.update', $company), [
        'password' => $this->newPassword, 'password_confirmation' => $this->newPassword,
    ])->assertOk();
    $user = $company->usuarios()->sole();
    expect($user->email)->toBe($company->email)
        ->and(Hash::check($this->newPassword, $user->password))->toBeTrue()
        ->and($company->fresh()->password)->toBe($user->password);
});

it('rejects invalid or unconfirmed passwords without changing credentials', function (mixed $password, mixed $confirmation): void {
    $hash = $this->company->password;
    $this->actingAs($this->admin, 'admin')->putJson($this->url, [
        'password' => $password, 'password_confirmation' => $confirmation,
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
    expect($this->company->fresh()->password)->toBe($hash)
        ->and(CorretorActivityLog::query()->where('action', 'imobiliaria_password_updated')->exists())->toBeFalse();
})->with([
    'missing' => [null, null], 'short' => ['abc123', 'abc123'],
    'no letters' => ['7938462517', '7938462517'], 'no numbers' => ['SenhaSemNumeros', 'SenhaSemNumeros'],
    'mismatch' => ['SenhaValida2026', 'OutraSenha2026'],
    'too long' => [str_repeat('a', 73).'1', str_repeat('a', 73).'1'],
    'bcrypt byte limit' => [str_repeat('á', 36).'1', str_repeat('á', 36).'1'],
]);

it('does not flash new passwords after HTML validation fails', function (): void {
    $this->actingAs($this->admin, 'admin')->from(route('admin.imobiliarias.index'))->put($this->url, [
        'password' => $this->newPassword, 'password_confirmation' => 'inconsistent',
    ])->assertRedirect(route('admin.imobiliarias.index'))->assertSessionHasErrors('password');
    expect(session()->getOldInput())->not->toHaveKeys(['password', 'password_confirmation']);
});

it('requires admin authentication and company editing permission', function (string $actor): void {
    if ($actor === 'company') {
        $this->actingAs($this->user, 'web');
    } elseif ($actor === 'viewer') {
        $this->admin->update(['permissions' => ['imobiliarias.visualizar']]);
        $this->actingAs($this->admin, 'admin');
    }
    $response = $this->putJson($this->url, ['password' => $this->newPassword, 'password_confirmation' => $this->newPassword]);
    $actor === 'viewer' ? $response->assertForbidden() : $response->assertUnauthorized();
    expect($this->company->fresh()->password)->toBe($this->company->password);
})->with(['guest', 'company', 'viewer']);

it('requires complete and unambiguous access data before provisioning a user', function (string $case): void {
    $company = Imobiliaria::factory()->create(['password' => '']);
    if ($case === 'missing-email') {
        $company->update(['email' => null]);
    } else {
        User::factory()->create(['email' => $company->email]);
    }
    $this->actingAs($this->admin, 'admin')->putJson(route('admin.imobiliarias.password.update', $company), [
        'password' => $this->newPassword, 'password_confirmation' => $this->newPassword,
    ])->assertConflict();
    expect($company->fresh()->password)->toBe('')->and($company->usuarios()->exists())->toBeFalse();
})->with(['missing-email', 'email-conflict']);

it('exposes only a password availability flag to the listing view', function (): void {
    $missing = Imobiliaria::factory()->create(['password' => '']);
    $this->actingAs($this->admin, 'admin')->get(route('admin.imobiliarias.index'))->assertOk()
        ->assertViewHas('companies', function ($companies) use ($missing): bool {
            $present = $companies->firstWhere('id', $this->company->id);
            $absent = $companies->firstWhere('id', $missing->id);

            return $present->has_password === true && $absent->has_password === false
                && ! array_key_exists('password', $present->getAttributes());
        })->assertDontSee($this->company->password);
});

it('renders separate password controls for editors without exposing existing credentials', function (): void {
    $missing = Imobiliaria::factory()->create(['password' => '']);
    $response = $this->actingAs($this->admin, 'admin')->get(route('admin.imobiliarias.index'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);

    expect($xpath->query('//*[@data-company-edit and @data-company-password-url and @data-company-has-password="true"]')->length)->toBe(2)
        ->and($xpath->query('//*[@data-company-edit and @data-company-id="'.$missing->id.'" and @data-company-has-password="false"]')->length)->toBe(2)
        ->and($xpath->query('//*[@id="companyEditModal"]//*[@data-company-password-status and not(@name) and @readonly]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="companyPasswordModal"]//form[not(ancestor::form)]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="companyPasswordModal"]//input[@type="password" and @autocomplete="new-password" and not(@value)]')->length)->toBe(2)
        ->and($xpath->query('//*[@id="companyPasswordModal"]//*[@data-company-password-toggle and @aria-pressed="false"]')->length)->toBe(2)
        ->and($xpath->query('//*[@id="companyPasswordModal"]//input[@name="_token"]')->length)->toBe(1);
    $response->assertDontSee($this->company->password)->assertDontSee($this->newPassword);
});

it('hides all password editing controls from read-only administrators', function (): void {
    $this->admin->update(['permissions' => ['imobiliarias.visualizar']]);
    $this->actingAs($this->admin, 'admin')->get(route('admin.imobiliarias.index'))->assertOk()
        ->assertDontSee('data-company-password-open', false)
        ->assertDontSee('data-company-password-modal', false)
        ->assertDontSee('data-company-password-url', false);
});

it('rolls back credential updates when auditing fails and logs no secret', function (): void {
    Log::spy();
    Event::listen('eloquent.creating: '.CorretorActivityLog::class, function (): void {
        throw new RuntimeException('Audit unavailable');
    });
    try {
        $this->actingAs($this->admin, 'admin')->putJson($this->url, [
            'password' => $this->newPassword, 'password_confirmation' => $this->newPassword,
        ])->assertInternalServerError()->assertDontSee($this->newPassword);
    } finally {
        Event::forget('eloquent.creating: '.CorretorActivityLog::class);
    }
    expect($this->company->fresh()->password)->toBe($this->company->password)
        ->and($this->user->fresh()->password)->toBe($this->user->password);
    Log::shouldHaveReceived('error')->once()->with('Falha ao alterar a senha de acesso da imobiliária.', [
        'company_id' => $this->company->id, 'corretor_id' => $this->admin->id, 'exception' => RuntimeException::class,
    ]);
});
