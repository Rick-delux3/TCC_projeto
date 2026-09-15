<?php

use App\Events\DashboardActivityChanged;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->withoutVite();

    config([
        'features.insurance_analysis.enabled' => false,
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'dashboard-test-key',
            'secret' => 'dashboard-test-secret',
            'app_id' => 'dashboard-test-app',
            'options' => [
                'host' => '127.0.0.1',
                'port' => 8080,
                'scheme' => 'http',
                'useTLS' => false,
            ],
            'client_options' => [],
        ],
    ]);

    // The channel file was initially loaded with the global null test driver.
    // Register its real callbacks on Reverb so HTTP auth exercises them.
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');
});

function dashboardBroadcastingCompany(array $overrides = []): Imobiliaria
{
    static $sequence = 0;

    $sequence++;

    return Imobiliaria::query()->create(array_merge([
        'name' => "Imobiliaria Broadcasting {$sequence}",
        'email' => "broadcasting-company-{$sequence}@example.test",
        'phone' => '11999999999',
        'password' => Hash::make('password'),
        'city' => 'Sao Paulo',
        'state' => 'SP',
    ], $overrides));
}

function dashboardBroadcastingUser(Imobiliaria $company): User
{
    return User::factory()->create([
        'company_id' => $company->id,
    ]);
}

function dashboardBroadcastingCorretor(array $overrides = []): Corretor
{
    static $sequence = 0;

    $sequence++;

    return Corretor::query()->create(array_merge([
        'name' => "Corretor Broadcasting {$sequence}",
        'email' => "broadcasting-admin-{$sequence}@example.test",
        'cpf' => str_pad((string) $sequence, 11, '0', STR_PAD_LEFT),
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ], $overrides));
}

function dashboardBroadcastingAuthPayload(string $channel): array
{
    return [
        'socket_id' => '1234.5678',
        'channel_name' => $channel,
    ];
}

function dashboardBroadcastingViewConfig(string $html): array
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);

    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($document);
    $node = $xpath->query(
        '//*[@id="dashboardUserConfig"]',
    )->item(0);

    if (! $node) {
        throw new RuntimeException('Dashboard realtime config was not rendered.');
    }

    return json_decode(
        (string) $node->textContent,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

it('broadcasts a company event on both private dashboards with a minimal payload', function () {
    $event = new DashboardActivityChanged(
        resource: 'lead',
        resourceId: 42,
        companyId: 7,
        change: 'lead.created',
    );

    expect($event)
        ->toBeInstanceOf(ShouldBroadcast::class)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and(array_map(
            static fn ($channel): string => (string) $channel,
            $event->broadcastOn(),
        ))->toBe([
            'private-companies.7.dashboard',
            'private-admins.dashboard',
        ])
        ->and($event->broadcastAs())->toBe('dashboard.activity.changed')
        ->and($event->broadcastQueue())->toBe('broadcasts')
        ->and($event->broadcastWith())->toBe([
            'resource' => 'lead',
            'resource_id' => 42,
            'company_id' => 7,
            'change' => 'lead.created',
            'occurred_at' => $event->occurredAt,
        ]);
});

it('broadcasts an unlinked event only on the private admin dashboard', function () {
    $event = new DashboardActivityChanged(
        resource: 'lead',
        resourceId: 43,
        companyId: null,
        change: 'lead.updated',
    );

    expect(array_map(
        static fn ($channel): string => (string) $channel,
        $event->broadcastOn(),
    ))->toBe([
        'private-admins.dashboard',
    ]);
});

it('keeps every initial resend state in the minimal Reverb payload', function (string $change) {
    $event = new DashboardActivityChanged(
        resource: 'lead',
        resourceId: 91,
        companyId: 17,
        change: $change,
    );

    expect($event->broadcastAs())->toBe('dashboard.activity.changed')
        ->and($event->broadcastQueue())->toBe('broadcasts')
        ->and($event->broadcastWith())
        ->toMatchArray([
            'resource' => 'lead',
            'resource_id' => 91,
            'company_id' => 17,
            'change' => $change,
        ])
        ->and(array_map(
            static fn ($channel): string => (string) $channel,
            $event->broadcastOn(),
        ))->toBe([
            'private-companies.17.dashboard',
            'private-admins.dashboard',
        ]);
})->with([
    'retrying' => 'lead.sync.retrying',
    'failed' => 'lead.sync.failed',
    'sent' => 'lead.sync.sent',
]);

it('publishes the private channel contract and preserves server-side dirty input', function () {
    $admin = dashboardBroadcastingCorretor();
    $company = dashboardBroadcastingCompany();
    $user = dashboardBroadcastingUser($company);

    $adminResponse = $this
        ->actingAs($admin, 'admin')
        ->withSession([
            '_old_input' => [
                'leadlovers_correction_context_id' => '91',
                'tel' => '11999999999',
            ],
        ])
        ->get(route('Dashboard-Admin'));

    $adminResponse->assertOk();

    $companyResponse = $this
        ->actingAs($user, 'web')
        ->withSession([
            '_old_input' => [],
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])
        ->get(route('company.dashboard'));

    $companyResponse->assertOk();

    $adminConfig = dashboardBroadcastingViewConfig(
        $adminResponse->getContent(),
    );
    $companyConfig = dashboardBroadcastingViewConfig(
        $companyResponse->getContent(),
    );

    expect($adminConfig['realtime'] ?? null)->toBe([
        'channel' => 'admins.dashboard',
        'event' => '.dashboard.activity.changed',
        'hasUnsavedInput' => true,
    ])->and($companyConfig['realtime'] ?? null)->toBe([
        'channel' => "companies.{$company->id}.dashboard",
        'event' => '.dashboard.activity.changed',
        'hasUnsavedInput' => false,
    ]);
});

it('keeps realtime reload event-driven and guarded by dirty correction forms', function () {
    $script = file_get_contents(
        resource_path('js/dashboard-user.js'),
    );

    expect($script)
        ->toMatch(
            '/querySelectorAll\(\s*[\'\"]\.leadlovers-correction-form'
            .'[\'\"]\s*\)/',
        )
        ->toContain('function dashboardHasUnsavedChanges()')
        ->toContain('serverHasUnsavedInput')
        ->toContain("document.querySelector('.leadlovers-correction-modal.show')")
        ->toMatch(
            '/function dashboardHasUnsavedChanges\(\)[\s\S]*?'
            .'document\.querySelector\(\s*[\'"]\.leadlovers-correction-modal\.show[\'"]\s*\)'
            .'[\s\S]*?return true;/',
        )
        ->toContain('form[data-submitting="true"]')
        ->toContain('form[data-realtime-submitting="true"]')
        ->toContain('form[data-realtime-changed="true"]')
        ->toContain('window.Echo')
        ->toContain('.private(realtimeConfig.channel)')
        ->toContain('.listen(realtimeConfig.event')
        ->toMatch(
            '/function reloadDashboard\(\)[\s\S]*?dashboardHasUnsavedChanges\(\)'
            .'[\s\S]*?return;[\s\S]*?window\.location\.reload\(\);/',
        )
        ->not->toContain('setInterval(');
});

it('manages correction busy state and focuses the invalid or first field only after the modal is shown', function () {
    $script = file_get_contents(
        resource_path('js/dashboard-user.js'),
    );

    expect($script)
        ->toContain("form.setAttribute('aria-busy', 'true');")
        ->toContain("form.setAttribute('aria-busy', 'false');")
        ->toContain("label.textContent = 'Salvando e reenviando...';")
        ->toContain("modalElement.addEventListener('shown.bs.modal', function () {")
        ->toMatch(
            '/modalElement\.querySelector\(\s*'
            .'[\'"]\[data-leadlovers-correction-input\]\.is-invalid, '
            .'[\'"]\s*\+\s*'
            .'[\'"]\[data-leadlovers-correction-input\][\'"]\s*\)/',
        )
        ->toContain('preferredField.focus({ preventScroll: true });')
        ->toMatch(
            '/shown\.bs\.modal[\s\S]*?window\.setTimeout\(function \(\) \{'
            .'[\s\S]*?preferredField\.focus\(\{ preventScroll: true \}\);/',
        );
});

it('authorizes the matching company user after two factor authentication', function () {
    $company = dashboardBroadcastingCompany();
    $user = dashboardBroadcastingUser($company);

    $this
        ->actingAs($user, 'web')
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])
        ->postJson('/broadcasting/auth', dashboardBroadcastingAuthPayload(
            "private-companies.{$company->id}.dashboard",
        ))
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

it('denies a company user access to another company dashboard', function () {
    $company = dashboardBroadcastingCompany();
    $otherCompany = dashboardBroadcastingCompany();
    $user = dashboardBroadcastingUser($company);

    $this
        ->actingAs($user, 'web')
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])
        ->postJson('/broadcasting/auth', dashboardBroadcastingAuthPayload(
            "private-companies.{$otherCompany->id}.dashboard",
        ))
        ->assertForbidden();
});

it('denies a company user without the two factor session', function () {
    $company = dashboardBroadcastingCompany();
    $user = dashboardBroadcastingUser($company);

    $this
        ->actingAs($user, 'web')
        ->withSession([
            'company_id' => $company->id,
        ])
        ->postJson('/broadcasting/auth', dashboardBroadcastingAuthPayload(
            "private-companies.{$company->id}.dashboard",
        ))
        ->assertForbidden();
});

it('denies an unauthenticated company channel request', function () {
    $company = dashboardBroadcastingCompany();

    $this
        ->postJson('/broadcasting/auth', dashboardBroadcastingAuthPayload(
            "private-companies.{$company->id}.dashboard",
        ))
        ->assertForbidden();
});

it('authorizes an active verified admin', function () {
    $corretor = dashboardBroadcastingCorretor();

    $this
        ->actingAs($corretor, 'admin')
        ->postJson('/broadcasting/auth', dashboardBroadcastingAuthPayload(
            'private-admins.dashboard',
        ))
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

it('denies a web user access to the admin dashboard channel', function () {
    $company = dashboardBroadcastingCompany();
    $user = dashboardBroadcastingUser($company);

    $this
        ->actingAs($user, 'web')
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])
        ->postJson('/broadcasting/auth', dashboardBroadcastingAuthPayload(
            'private-admins.dashboard',
        ))
        ->assertForbidden();
});

it('denies an inactive admin', function () {
    $corretor = dashboardBroadcastingCorretor([
        'active' => false,
    ]);

    $this
        ->actingAs($corretor, 'admin')
        ->postJson('/broadcasting/auth', dashboardBroadcastingAuthPayload(
            'private-admins.dashboard',
        ))
        ->assertForbidden();
});
