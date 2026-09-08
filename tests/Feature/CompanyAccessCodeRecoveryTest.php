<?php

use App\Jobs\RecoverCompanyAccessCodeJob;
use App\Models\Imobiliaria;
use App\Notifications\CompanyAcessCodeNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['app.url' => 'https://app.example.test']);
    Cache::flush();
    Queue::fake();
    Notification::fake();
});

function companyForCodeRecovery(array $overrides = []): Imobiliaria
{
    return Imobiliaria::query()->create(array_merge([
        'name' => 'Imobiliária Recuperação',
        'email' => 'recuperacao@example.test',
        'phone' => '11999999999',
        'password' => 'password',
        'city' => 'São Paulo',
        'state' => 'SP',
        'lead_access_code' => 'REC234',
        'lead_form_active' => true,
    ], $overrides));
}

it('renders the access and recovery forms for each brand', function (string $brand) {
    config(['branding.active' => $brand]);
    $this->get(route('simulation.registered-company.access'))
        ->assertOk()
        ->assertSee('Esqueci meu código')
        ->assertSee(route('simulation.registered-company.code.request'), false);
    $this->get(route('simulation.registered-company.code.request'))
        ->assertOk()
        ->assertSee('data-brand="'.$brand.'"', false)
        ->assertSee('Receba seu código por email')
        ->assertSee('name="email"', false)
        ->assertSee('name="_token"', false)
        ->assertSee(route('simulation.registered-company.code.email'), false);
})->with(['tcc', 'client']);

it('renders the success screen after requesting a code for each brand', function (string $brand) {
    config(['branding.active' => $brand]);
    $this->followingRedirects()
        ->post(route('simulation.registered-company.code.email'), ['email' => 'unknown@example.test'])
        ->assertOk()
        ->assertSee('Solicitação recebida')
        ->assertSee('data-recovery-state="success"', false)
        ->assertDontSee('name="email"', false);
})->with(['tcc', 'client']);

it('renders the rate limit screen for each brand', function (string $brand) {
    config(['branding.active' => $brand]);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->post(route('simulation.registered-company.code.email'), ['email' => 'limited@example.test']);
    }
    $this->followingRedirects()
        ->post(route('simulation.registered-company.code.email'), ['email' => 'limited@example.test'])
        ->assertOk()
        ->assertSee('Aguarde para solicitar novamente')
        ->assertSee('data-recovery-state="limited"', false)
        ->assertDontSee('name="email"', false)
        ->assertDontSee('id="modalErrors"', false);
})->with(['tcc', 'client']);

it('shows accessible inline email validation and retains the entered value', function () {
    $this->from(route('simulation.registered-company.code.request'))
        ->followingRedirects()
        ->post(route('simulation.registered-company.code.email'), ['email' => 'invalid'])
        ->assertOk()
        ->assertSee('aria-describedby="email-error"', false)
        ->assertSee('value="invalid"', false)
        ->assertDontSee('id="modalErrors"', false);
});

it('provides a public endpoint for the recovery view', function () {
    $this->get(route('simulation.registered-company.code.request'))
        ->assertOk()
        ->assertViewIs('simulation.forget-acess-code');

    Queue::assertNothingPushed();
});

it('normalizes the email and resends the existing code only to the registered company', function () {
    $company = companyForCodeRecovery();

    $this->post(route('simulation.registered-company.code.email'), [
        'email' => '  RECUPERACAO@EXAMPLE.TEST  ',
        'company_id' => 999,
        'lead_access_code' => 'ATTACK',
    ])->assertRedirect(route('simulation.registered-company.code.request'))
        ->assertSessionHas('status')
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('simulation.registered_company_access');

    Queue::assertPushed(RecoverCompanyAccessCodeJob::class, function (RecoverCompanyAccessCodeJob $job) use ($company): bool {
        expect($job->email)->toBe($company->email)
            ->and($job)->toBeInstanceOf(ShouldQueue::class)
            ->toBeInstanceOf(ShouldBeEncrypted::class)
            ->and($job->afterCommit)->toBeTrue();

        $job->handle();

        return true;
    });

    Notification::assertSentTo($company, CompanyAcessCodeNotification::class, function (CompanyAcessCodeNotification $notification) use ($company): bool {
        expect($notification->accessCode)->toBe('REC234')
            ->and($notification->companyId)->toBe($company->id)
            ->and($notification->sentByCorretorId)->toBeNull()
            ->and($notification->accessUrl)->toBe('https://app.example.test/simulacao/imobiliaria-cadastrada')
            ->and($notification)->toBeInstanceOf(ShouldQueue::class)
            ->toBeInstanceOf(ShouldBeEncrypted::class);

        return true;
    });
    Notification::assertCount(1);
    expect($company->fresh()->lead_access_code)->toBe('REC234');
});

it('returns the same public response for registered and unknown emails', function () {
    $company = companyForCodeRecovery();
    $route = route('simulation.registered-company.code.email');

    $this->post($route, ['email' => $company->email])->assertRedirect();
    $status = session('status');

    $this->post($route, ['email' => 'unknown@example.test'])
        ->assertRedirect(route('simulation.registered-company.code.request'))
        ->assertSessionHas('status', $status)
        ->assertSessionHasNoErrors();

    Queue::assertPushed(RecoverCompanyAccessCodeJob::class, 2);
    (new RecoverCompanyAccessCodeJob('unknown@example.test'))->handle();
    Notification::assertNothingSent();
});

it('does not send a code for an inactive company or a missing code', function (array $overrides) {
    $company = companyForCodeRecovery();
    $company->forceFill($overrides)->save();

    (new RecoverCompanyAccessCodeJob($company->email))->handle();

    Notification::assertNothingSent();
})->with([
    'inactive' => [['lead_form_active' => false]],
    'null code' => [['lead_access_code' => null]],
    'empty code' => [['lead_access_code' => '']],
    'blank code' => [['lead_access_code' => '   ']],
]);

it('rechecks company eligibility when the recovery job executes', function () {
    $company = companyForCodeRecovery();
    $this->post(route('simulation.registered-company.code.email'), ['email' => $company->email])
        ->assertRedirect();

    $company->update(['lead_form_active' => false]);

    Queue::assertPushed(RecoverCompanyAccessCodeJob::class, function (RecoverCompanyAccessCodeJob $job): bool {
        $job->handle();

        return true;
    });
    Notification::assertNothingSent();
});

it('rejects invalid email input without queuing recovery', function (mixed $email) {
    $this->from(route('simulation.registered-company.code.request'))
        ->post(route('simulation.registered-company.code.email'), ['email' => $email])
        ->assertRedirect(route('simulation.registered-company.code.request'))
        ->assertSessionHasErrors('email');

    Queue::assertNothingPushed();
    Notification::assertNothingSent();
})->with([
    'missing' => [null],
    'empty' => [''],
    'invalid' => ['not-an-email'],
    'array' => [['email@example.test']],
    'number' => [123],
    'too long' => [str_repeat('a', 256).'@example.test'],
]);

it('limits the normalized email across different IP addresses and allows retry after one hour', function () {
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt])
            ->postJson(route('simulation.registered-company.code.email'), ['email' => 'limited@example.test'])
            ->assertRedirect();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])
        ->postJson(route('simulation.registered-company.code.email'), ['email' => ' LIMITED@EXAMPLE.TEST '])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
    Queue::assertPushed(RecoverCompanyAccessCodeJob::class, 3);

    $this->travel(61)->minutes();
    $this->postJson(route('simulation.registered-company.code.email'), ['email' => 'limited@example.test'])
        ->assertRedirect();
    Queue::assertPushed(RecoverCompanyAccessCodeJob::class, 4);
});

it('limits the IP even when requesting different emails', function () {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson(route('simulation.registered-company.code.email'), ['email' => "company{$attempt}@example.test"])
            ->assertRedirect();
    }

    $this->postJson(route('simulation.registered-company.code.email'), ['email' => 'company6@example.test'])
        ->assertTooManyRequests();
    Queue::assertPushed(RecoverCompanyAccessCodeJob::class, 5);
});

it('returns a validation message to the recovery form when throttled', function () {
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->post(route('simulation.registered-company.code.email'), ['email' => 'limited@example.test']);
    }

    $this->post(route('simulation.registered-company.code.email'), ['email' => 'limited@example.test'])
        ->assertRedirect(route('simulation.registered-company.code.request'))
        ->assertSessionHasErrors('email')
        ->assertHeader('Retry-After');
    Queue::assertPushed(RecoverCompanyAccessCodeJob::class, 3);
});

it('records job failures without exposing the email or exception details', function () {
    Log::spy();
    (new RecoverCompanyAccessCodeJob('private@example.test'))
        ->failed(new RuntimeException('provider-secret'));

    Log::shouldHaveReceived('error')->once()->with(
        'Falha ao processar a recuperação do código de acesso da imobiliária.',
        ['exception' => RuntimeException::class],
    );
});
