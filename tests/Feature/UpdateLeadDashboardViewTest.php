<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\User;
use App\Support\CorretorPermissions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    $this->withoutVite();

    config([
        'features.insurance_analysis.enabled' => false,
        'services.leadlovers.enabled' => true,
    ]);
});

function updateLeadDashboardAdmin(array $overrides = []): Corretor
{
    static $sequence = 0;

    $sequence++;

    return Corretor::query()->create(array_merge([
        'name' => "Corretor dashboard view {$sequence}",
        'email' => "dashboard-view-admin-{$sequence}@example.test",
        'cpf' => str_pad((string) $sequence, 11, '0', STR_PAD_LEFT),
        'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => [
            CorretorPermissions::VIEW_LEADS,
            CorretorPermissions::EDIT_LEADS,
        ],
        'active' => true,
        'first_login_verified_at' => now(),
    ], $overrides));
}

function updateLeadDashboardCompany(array $overrides = []): Imobiliaria
{
    static $sequence = 0;

    $sequence++;

    return Imobiliaria::query()->create(array_merge([
        'name' => "Imobiliaria dashboard view {$sequence}",
        'email' => "dashboard-view-company-{$sequence}@example.test",
        'phone' => '11999999999',
        'password' => Hash::make('password'),
        'city' => 'Sao Paulo',
        'state' => 'SP',
        'lead_form_active' => true,
    ], $overrides));
}

function updateLeadDashboardCompanyUser(Imobiliaria $company): User
{
    return User::factory()->create([
        'company_id' => $company->id,
    ]);
}

function updateLeadDashboardLead(array $overrides = []): Lead
{
    static $sequence = 0;

    $sequence++;

    return Lead::query()->create(array_merge([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => "Lead dashboard view {$sequence}",
        'email' => "dashboard-view-lead-{$sequence}@example.test",
        'tel' => '119'.str_pad((string) $sequence, 8, '0', STR_PAD_LEFT),
        'cpf' => str_pad((string) $sequence, 11, '1', STR_PAD_LEFT),
        'status' => 'novo',
        'leadlovers_status' => 'pending',
    ], $overrides));
}

function updateLeadDashboardFailedLead(
    string $errorCode,
    int $httpStatus = 400,
    array $overrides = [],
): Lead {
    return updateLeadDashboardLead(array_merge([
        'leadlovers_status' => 'failed',
        'leadlovers_response' => [
            'success' => false,
            'operation' => 'lead_creation',
            'status_code' => $httpStatus,
            'error_code' => $errorCode,
        ],
        'leadlovers_initial_error_status' => $httpStatus,
        'leadlovers_initial_error_code' => $errorCode,
        'leadlovers_initial_error_operation' => 'lead_creation',
        'leadlovers_initial_error_detail' => 'Falha segura registrada.',
        'leadlovers_initial_failed_at' => now(),
    ], $overrides));
}

/** @return array{document: DOMDocument, xpath: DOMXPath} */
function updateLeadDashboardDom(string $html): array
{
    $document = new DOMDocument;

    $previous = libxml_use_internal_errors(true);
    $document->loadHTML(
        '<?xml encoding="UTF-8">'.$html,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return [
        'document' => $document,
        'xpath' => new DOMXPath($document),
    ];
}

function updateLeadDashboardNodeHtml(string $html, string $id): string
{
    $dom = updateLeadDashboardDom($html);
    $node = $dom['xpath']->query(
        sprintf('//*[@id=%s]', updateLeadDashboardXpathLiteral($id)),
    )->item(0);

    if (! $node) {
        throw new RuntimeException("Node [{$id}] was not rendered.");
    }

    return (string) $dom['document']->saveHTML($node);
}

function updateLeadDashboardTriggerHtml(
    string $html,
    string $modalId,
): ?string {
    $dom = updateLeadDashboardDom($html);
    $target = '#'.$modalId;
    $node = $dom['xpath']->query(sprintf(
        '//*[@data-bs-target=%s]',
        updateLeadDashboardXpathLiteral($target),
    ))->item(0);

    return $node
        ? (string) $dom['document']->saveHTML($node)
        : null;
}

function updateLeadDashboardXpathLiteral(string $value): string
{
    if (! str_contains($value, "'")) {
        return "'{$value}'";
    }

    if (! str_contains($value, '"')) {
        return '"'.$value.'"';
    }

    $parts = explode("'", $value);

    return 'concat('.implode(", \"'\", ", array_map(
        static fn (string $part): string => "'{$part}'",
        $parts,
    )).')';
}

/** @return list<string> */
function updateLeadDashboardControlNames(string $formHtml): array
{
    $dom = updateLeadDashboardDom($formHtml);
    $names = [];

    foreach ($dom['xpath']->query('//input[@name] | //select[@name] | //textarea[@name]') as $control) {
        $names[] = $control->getAttribute('name');
    }

    sort($names);

    return array_values(array_unique($names));
}

function updateLeadDashboardConfig(string $html): array
{
    $dom = updateLeadDashboardDom($html);
    $node = $dom['xpath']->query(
        '//*[@id="dashboardUserConfig"]',
    )->item(0);

    if (! $node) {
        throw new RuntimeException('Dashboard JSON config was not rendered.');
    }

    return json_decode(
        (string) $node->textContent,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

it('renders the editable admin lead dashboard without tag-management permission or analysis controls', function () {
    config(['features.insurance_analysis.enabled' => false]);

    $corretor = Corretor::query()->create([
        'name' => 'Corretor de leads',
        'email' => 'dashboard-leads@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => [
            CorretorPermissions::VIEW_LEADS,
            CorretorPermissions::EDIT_LEADS,
        ],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
    $lead = Lead::query()->create([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Lead no dashboard',
        'email' => 'readonly-dashboard@example.test',
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 71001,
        'sent_to_leadlovers_at' => now(),
        'leadlovers_update_status' => 'pending',
    ]);

    $response = $this
        ->actingAs($corretor, 'admin')
        ->get(route('Dashboard-Admin'));

    $response
        ->assertOk()
        ->assertSee('Lead no dashboard')
        ->assertSee('adminLeadModal'.$lead->id, false)
        ->assertSee('class="lead-update-form"', false)
        ->assertSee('data-lead-id="'.$lead->id.'"', false)
        ->assertSee('name="lead_context_id"', false)
        ->assertSee('readonly-dashboard@example.test')
        ->assertSee('readonly', false)
        ->assertSee('Sincronização pendente')
        ->assertDontSee('Alterar status')
        ->assertDontSee('Visualizar análises')
        ->assertDontSee('Solicitar reanálise');
});

it('isolates UpdateLead old input and validation feedback by lead context', function () {
    $firstLead = Lead::query()->create([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Primeiro lead',
        'email' => 'first@example.test',
    ]);
    $firstLead->conjuge()->create([
        'nome' => 'Cônjuge original',
        'cpf' => '12345678900',
    ]);

    $secondLead = Lead::query()->create([
        'tipo_solicitante' => 'locador',
        'origem' => 'locador',
        'nome' => 'Segundo lead',
        'email' => 'second@example.test',
    ]);

    $session = app('session')->driver();
    $session->put('_old_input', [
        'lead_context_id' => (string) $firstLead->id,
        'nome' => 'Nome enviado',
        'email' => 'tampered@example.test',
        'conjuge_nome' => 'Cônjuge enviado',
    ]);
    app('request')->setLaravelSession($session);

    $errors = (new ViewErrorBag)->put(
        'default',
        new MessageBag(['nome' => ['O nome informado é inválido.']])
    );

    $firstHtml = view('partials.lead-update-fields', [
        'lead' => $firstLead->fresh(['conjuge', 'endereco', 'despesas']),
        'leadUpdateIdPrefix' => 'company-lead',
        'isLeadValidationContext' => true,
        'errors' => $errors,
    ])->render();

    $secondHtml = view('partials.lead-update-fields', [
        'lead' => $secondLead->fresh(['conjuge', 'endereco', 'despesas']),
        'leadUpdateIdPrefix' => 'company-lead',
        'isLeadValidationContext' => false,
        'errors' => $errors,
    ])->render();

    expect($firstHtml)
        ->toContain('name="lead_context_id"')
        ->toContain('value="'.$firstLead->id.'"')
        ->toContain('id="company-lead-'.$firstLead->id.'-nome"')
        ->toContain('value="Nome enviado"')
        ->toContain('is-invalid')
        ->toContain('O nome informado é inválido.')
        ->toContain('value="Cônjuge enviado"')
        ->toContain('value="first@example.test"')
        ->not->toContain('tampered@example.test')
        ->and($firstHtml)->toMatch('/name="email"[\s\S]*?readonly/')
        ->and($secondHtml)
        ->toContain('id="company-lead-'.$secondLead->id.'-nome"')
        ->toContain('value="Segundo lead"')
        ->toContain('value="second@example.test"')
        ->not->toContain('Nome enviado')
        ->not->toContain('Cônjuge enviado')
        ->not->toContain('is-invalid');
});

it('renders every confirmed LeadLovers update status with text and an icon', function (
    ?string $status,
    string $label
) {
    $lead = new Lead([
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 71002,
        'sent_to_leadlovers_at' => now(),
        'leadlovers_update_status' => $status,
    ]);

    $html = view('partials.leadlovers-sync-status', [
        'lead' => $lead,
    ])->render();

    expect($html)
        ->toContain($label)
        ->toContain('aria-hidden="true"');
})->with([
    'pending' => ['pending', 'Sincronização pendente'],
    'processing' => ['processing', 'Sincronizando com LeadLovers'],
    'synced' => ['synced', 'Sincronizado com LeadLovers'],
    'failed' => ['failed', 'Falha na sincronização'],
    'legacy waiting initial send after confirmation' => [
        'waiting_initial_send',
        'Sincronizado com LeadLovers',
    ],
    'disabled' => ['disabled', 'Integração desativada'],
    'idle' => ['idle', 'Sincronizado com LeadLovers'],
    'null' => [null, 'Sincronizado com LeadLovers'],
]);

it('uses a conservative fallback for an unknown update status after the initial send', function () {
    $lead = new Lead([
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 71004,
        'sent_to_leadlovers_at' => now(),
        'leadlovers_update_status' => 'unexpected_future_status',
    ]);

    $html = view('partials.leadlovers-sync-status', [
        'lead' => $lead,
    ])->render();

    expect($html)
        ->toContain('Status da sincronização desconhecido')
        ->toContain('text-bg-secondary')
        ->toContain('bi-question-circle')
        ->not->toContain('Sincronizado com LeadLovers')
        ->not->toContain('text-bg-success');
});

it('renders a safe UpdateLead failure message without the remote response', function () {
    $lead = new Lead([
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 71003,
        'sent_to_leadlovers_at' => now(),
        'leadlovers_update_status' => 'failed',
        'leadlovers_update_error' => '<script>alert(1)</script>',
        'leadlovers_update_response' => [
            'token' => 'secret-token',
            'Email' => 'person@example.test',
        ],
    ]);

    $html = view('partials.leadlovers-sync-status', [
        'lead' => $lead,
        'showLeadLoversFailureMessage' => true,
    ])->render();

    expect($html)
        ->toContain('Os dados foram salvos no sistema, mas a LeadLovers não confirmou a atualização.')
        ->not->toContain('secret-token')
        ->not->toContain('person@example.test')
        ->not->toContain('<script>');
});

it('shows safe initial failure reasons and keeps technical failures out of the correction flow', function () {
    $admin = updateLeadDashboardAdmin();
    $remoteArtifacts = [
        'token' => 'dashboard-raw-secret-token',
        'payload' => '<script>dashboard-remote-script-marker</script>',
        'Email' => 'remote-payload-person@example.test',
        'Telefone' => '+55 11 98888-7766',
        'html' => '<strong>dashboard-remote-html-marker</strong>',
    ];

    $technicalLeads = [
        updateLeadDashboardFailedLead('UNAUTHORIZED', 401, [
            'leadlovers_response' => array_merge($remoteArtifacts, [
                'status_code' => 401,
                'error_code' => 'UNAUTHORIZED',
            ]),
            'leadlovers_initial_error_detail' => json_encode($remoteArtifacts),
        ]),
        updateLeadDashboardFailedLead('TIMEOUT', 422),
        updateLeadDashboardFailedLead('RATE_LIMITED', 429),
        updateLeadDashboardFailedLead('UPSTREAM_HTML', 503),
    ];

    $response = $this
        ->actingAs($admin, 'admin')
        ->get(route('Dashboard-Admin'));

    $response
        ->assertOk()
        ->assertSee('A LeadLovers recusou as credenciais da integração.')
        ->assertSee('não concluiu a operação dentro do tempo esperado')
        ->assertSee('O limite de requisições da LeadLovers foi atingido.')
        ->assertSee('A LeadLovers apresentou uma indisponibilidade interna.')
        ->assertDontSee('dashboard-raw-secret-token')
        ->assertDontSee('dashboard-remote-script-marker')
        ->assertDontSee('remote-payload-person@example.test')
        ->assertDontSee('+55 11 98888-7766')
        ->assertDontSee('dashboard-remote-html-marker');

    foreach ($technicalLeads as $lead) {
        expect(updateLeadDashboardTriggerHtml(
            $response->getContent(),
            'adminLeadLoversCorrectionModal'.$lead->id,
        ))->toBeNull()
            ->and(updateLeadDashboardTriggerHtml(
                $response->getContent(),
                'adminLeadModal'.$lead->id,
            ))->toContain('Editar')
            ->not->toContain('Corrigir');

        expect(fn () => updateLeadDashboardNodeHtml(
            $response->getContent(),
            'adminLeadLoversCorrectionModal'.$lead->id,
        ))->toThrow(RuntimeException::class);
    }
});

it('shows a machine configuration reason for an HTTP 400 machine request without offering correction', function () {
    $admin = updateLeadDashboardAdmin();
    $lead = updateLeadDashboardFailedLead('MACHINE_REQUEST_FAILED', 400, [
        'leadlovers_response' => [
            'success' => false,
            'operation' => 'machine_request',
            'status_code' => 400,
            'error_code' => 'MACHINE_REQUEST_FAILED',
        ],
        'leadlovers_initial_error_operation' => 'machine_request',
    ]);

    $response = $this
        ->actingAs($admin, 'admin')
        ->get(route('Dashboard-Admin'));

    $response->assertOk();
    $html = $response->getContent();
    $detailsModal = updateLeadDashboardNodeHtml(
        $html,
        'adminLeadModal'.$lead->id,
    );

    expect($detailsModal)
        ->toContain('máquina')
        ->toContain('configura')
        ->not->toContain('A correção ainda não está disponível')
        ->and(updateLeadDashboardTriggerHtml(
            $html,
            'adminLeadLoversCorrectionModal'.$lead->id,
        ))->toBeNull()
        ->and(updateLeadDashboardTriggerHtml(
            $html,
            'adminLeadModal'.$lead->id,
        ))->toContain('Editar')
        ->not->toContain('Corrigir');
});

it('replaces Editar with Corrigir and renders field-exclusive admin correction modals', function () {
    $admin = updateLeadDashboardAdmin();
    $phoneLead = updateLeadDashboardFailedLead('PHONE_EXISTS');
    $emailLead = updateLeadDashboardFailedLead('EMAIL_EXISTS', 400, [
        'email' => 'refused-dashboard-email@example.test',
    ]);
    $normalLead = updateLeadDashboardLead([
        'leadlovers_status' => 'pending',
    ]);

    $response = $this
        ->actingAs($admin, 'admin')
        ->get(route('Dashboard-Admin'));

    $response->assertOk();
    $html = $response->getContent();
    $phoneModalId = 'adminLeadLoversCorrectionModal'.$phoneLead->id;
    $emailModalId = 'adminLeadLoversCorrectionModal'.$emailLead->id;
    $phoneModal = updateLeadDashboardNodeHtml($html, $phoneModalId);
    $emailModal = updateLeadDashboardNodeHtml($html, $emailModalId);
    $phoneTrigger = updateLeadDashboardTriggerHtml($html, $phoneModalId);

    expect($phoneTrigger)
        ->toContain('Corrigir')
        ->toContain('leadlovers-correction-trigger')
        ->toContain('bi-wrench-adjustable-circle')
        ->toContain('aria-controls="'.$phoneModalId.'"')
        ->toContain('aria-haspopup="dialog"')
        ->toContain('aria-label="Corrigir dados de '.$phoneLead->nome.' para reenvio')
        ->not->toContain('Editar')
        ->and(updateLeadDashboardTriggerHtml($html, $emailModalId))
        ->toContain('Corrigir')
        ->not->toContain('Editar')
        ->and(updateLeadDashboardTriggerHtml(
            $html,
            'adminLeadModal'.$normalLead->id,
        ))->toContain('Editar')
        ->not->toContain('Corrigir')
        ->and(updateLeadDashboardControlNames($phoneModal))->toBe([
            '_token',
            'leadlovers_correction_context_id',
            'tel',
        ])
        ->and(updateLeadDashboardControlNames($emailModal))->toBe([
            '_token',
            'email',
            'leadlovers_correction_context_id',
        ]);

    expect($phoneModal)
        ->toContain('Corrigir dados para envio')
        ->toContain('O telefone informado já está cadastrado na LeadLovers.')
        ->toContain('Salvar e reenviar')
        ->toContain('data-lead-id="'.$phoneLead->id.'"')
        ->toContain('aria-describedby="'.$phoneModalId.'Reason '.$phoneModalId.'Instruction"')
        ->toContain('modal-dialog-scrollable')
        ->toContain('aria-busy="false"')
        ->toContain('data-leadlovers-correction-input')
        ->toContain('data-leadlovers-correction-label')
        ->toContain('aria-live="polite"')
        ->toContain('aria-atomic="true"')
        ->toContain('leadlovers-correction-modal__lead')
        ->toContain('<strong>'.$phoneLead->nome.'</strong>')
        ->toContain('do lead '.$phoneLead->nome)
        ->toContain('action="'.route(
            'admin.leads.leadlovers.correct',
            $phoneLead,
        ).'"')
        ->not->toContain('autofocus')
        ->not->toContain('name="email"')
        ->not->toContain('name="nome"')
        ->not->toContain('name="cpf"')
        ->not->toContain('name="status"')
        ->not->toContain('name="company_id"')
        ->and($emailModal)
        ->toContain('Corrigir dados para envio')
        ->toContain('O e-mail informado já está cadastrado na LeadLovers')
        ->toContain('Salvar e reenviar')
        ->toContain('data-lead-id="'.$emailLead->id.'"')
        ->toContain('action="'.route(
            'admin.leads.leadlovers.correct',
            $emailLead,
        ).'"')
        ->not->toContain('name="tel"')
        ->not->toContain('name="nome"')
        ->not->toContain('name="cpf"')
        ->not->toContain('name="status"')
        ->not->toContain('name="company_id"');
});

it('uses the company correction route with the same exclusive modal contract', function () {
    $company = updateLeadDashboardCompany();
    $user = updateLeadDashboardCompanyUser($company);
    $phoneLead = updateLeadDashboardFailedLead('PHONE_EXISTS', 400, [
        'company_id' => $company->id,
    ]);
    $normalLead = updateLeadDashboardLead([
        'company_id' => $company->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->withSession([
            'company_id' => $company->id,
            '2fa_passed' => true,
        ])
        ->get(route('company.dashboard'));

    $response->assertOk();
    $html = $response->getContent();
    $modalId = 'leadLoversCorrectionModal'.$phoneLead->id;
    $modal = updateLeadDashboardNodeHtml($html, $modalId);
    $trigger = updateLeadDashboardTriggerHtml($html, $modalId);

    expect($trigger)
        ->toContain('Corrigir')
        ->toContain('aria-label="Corrigir dados de '.$phoneLead->nome.' para reenvio')
        ->not->toContain('Editar')
        ->and(updateLeadDashboardTriggerHtml(
            $html,
            'leadModal'.$normalLead->id,
        ))->toContain('Editar')
        ->not->toContain('Corrigir')
        ->and(updateLeadDashboardControlNames($modal))->toBe([
            '_token',
            'leadlovers_correction_context_id',
            'tel',
        ])
        ->and($modal)
        ->toContain('action="'.route(
            'dashboard.leads.leadlovers.correct',
            $phoneLead,
        ).'"')
        ->toContain('<strong>'.$phoneLead->nome.'</strong>')
        ->not->toContain('name="email"')
        ->not->toContain('name="nome"')
        ->not->toContain('name="cpf"');
});

it('reopens only the correction modal identified by the named validation context', function () {
    $admin = updateLeadDashboardAdmin();
    $firstLead = updateLeadDashboardFailedLead('PHONE_EXISTS');
    $secondLead = updateLeadDashboardFailedLead('PHONE_EXISTS');

    $validationResponse = $this
        ->actingAs($admin, 'admin')
        ->from(route('Dashboard-Admin'))
        ->post(route(
            'admin.leads.leadlovers.correct',
            $firstLead,
        ), [
            'leadlovers_correction_context_id' => (string) $firstLead->id,
            'tel' => '123',
        ]);

    $validationResponse
        ->assertRedirect(route('Dashboard-Admin'))
        ->assertSessionHasErrorsIn('leadloversCorrection', ['tel']);

    $response = $this
        ->actingAs($admin, 'admin')
        ->get(route('Dashboard-Admin'));

    $response->assertOk();
    $html = $response->getContent();
    $config = updateLeadDashboardConfig($html);
    $targets = $config['leadLoversCorrectionValidationTargets'] ?? null;
    $firstModal = updateLeadDashboardNodeHtml(
        $html,
        'adminLeadLoversCorrectionModal'.$firstLead->id,
    );
    $secondModal = updateLeadDashboardNodeHtml(
        $html,
        'adminLeadLoversCorrectionModal'.$secondLead->id,
    );

    expect($targets)->toBeArray()
        ->and($targets['modal'] ?? null)
        ->toBe('adminLeadLoversCorrectionModal'.$firstLead->id)
        ->and($targets['field'] ?? null)
        ->toContain((string) $firstLead->id)
        ->toContain('tel')
        ->not->toContain((string) $secondLead->id)
        ->and($firstModal)
        ->toContain('value="123"')
        ->toContain('is-invalid')
        ->toContain('O telefone deve conter 10 ou 11 dígitos.')
        ->and($secondModal)
        ->not->toContain('value="123"')
        ->not->toContain('is-invalid')
        ->not->toContain('O telefone deve conter 10 ou 11 dígitos.');
});

it('renders the visual sync filter with its count and preserves it in pagination links', function () {
    $admin = updateLeadDashboardAdmin();

    foreach (range(1, 7) as $number) {
        updateLeadDashboardFailedLead('PHONE_EXISTS', 400, [
            'nome' => "Lead invalid data page {$number}",
        ]);
    }

    updateLeadDashboardFailedLead('TIMEOUT', 422, [
        'nome' => 'Lead excluded from invalid data filter',
    ]);

    $response = $this
        ->actingAs($admin, 'admin')
        ->get(route('Dashboard-Admin', [
            'leadlovers_sync' => 'not_sent_invalid_data',
        ]));

    $response
        ->assertOk()
        ->assertSee('name="leadlovers_sync"', false)
        ->assertSee('value="not_sent_invalid_data"', false)
        ->assertSee('Não enviados à LeadLovers — dados a corrigir')
        ->assertSee('leadlovers_sync=not_sent_invalid_data', false)
        ->assertDontSee('Lead excluded from invalid data filter');

    $dom = updateLeadDashboardDom($response->getContent());
    $option = $dom['xpath']->query(
        '//select[@name="leadlovers_sync"]'
        .'//option[@value="not_sent_invalid_data" and @selected]',
    )->item(0);

    expect($option)->not->toBeNull()
        ->and(trim((string) $option?->textContent))->toContain('(7)');
});

it('returns to the normal synchronized presentation after the resend succeeds', function () {
    $admin = updateLeadDashboardAdmin();
    $lead = updateLeadDashboardLead([
        'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 987654,
        'sent_to_leadlovers_at' => now(),
        'leadlovers_update_status' => 'idle',
        'leadlovers_initial_error_status' => null,
        'leadlovers_initial_error_code' => null,
        'leadlovers_initial_error_operation' => null,
        'leadlovers_initial_error_detail' => null,
        'leadlovers_initial_failed_at' => null,
    ]);

    $response = $this
        ->actingAs($admin, 'admin')
        ->get(route('Dashboard-Admin'));

    $response->assertOk();
    $html = $response->getContent();
    $detailsModal = updateLeadDashboardNodeHtml(
        $html,
        'adminLeadModal'.$lead->id,
    );

    expect(updateLeadDashboardTriggerHtml(
        $html,
        'adminLeadModal'.$lead->id,
    ))->toContain('Editar')
        ->not->toContain('Corrigir')
        ->and(updateLeadDashboardTriggerHtml(
            $html,
            'adminLeadLoversCorrectionModal'.$lead->id,
        ))->toBeNull()
        ->and($detailsModal)
        ->toContain('Sincronizado com')
        ->not->toContain('Não enviado à LeadLovers')
        ->not->toContain('Falha na integração')
        ->not->toContain('Corrigir dados para envio');
});

it('shares the correction stylesheet and preserves dark, mobile, and reduced-motion accessibility states', function () {
    $adminLayout = file_get_contents(
        resource_path('views/layout-inicial/Dashboard_Admin.blade.php'),
    );
    $companyLayout = file_get_contents(
        resource_path('views/layout-inicial/Dashboard_User.blade.php'),
    );
    $stylesheet = file_get_contents(
        resource_path('css/leadlovers-correction.css'),
    );

    expect($adminLayout)
        ->toContain("'resources/css/leadlovers-correction.css'")
        ->and($companyLayout)
        ->toContain("'resources/css/leadlovers-correction.css'")
        ->and($stylesheet)
        ->toMatch(
            '/\.leadlovers-correction-modal\s*\{[\s\S]*?z-index:\s*2060\s*!important;/',
        )
        ->toMatch(
            '/\.leadlovers-correction-modal \.modal-dialog\s*\{[\s\S]*?z-index:\s*2061\s*!important;/',
        )
        ->toContain('.dashboard-admin-body:has(.dashboard-shell[data-dashboard-theme="dark"]) .leadlovers-correction-modal')
        ->toContain('.dashboard-user-body:has(.dashboard-shell[data-dashboard-theme="dark"]) .leadlovers-correction-modal')
        ->toContain('--ll-modal-bg: rgba(15, 23, 42, 0.97);')
        ->toContain('.dashboard-shell[data-dashboard-theme="dark"] .leadlovers-sync-status__message')
        ->toContain('color: #fecaca;')
        ->toContain('.dashboard-shell[data-dashboard-theme="dark"] .leadlovers-sync-status .text-bg-warning.text-dark')
        ->toContain('color: #212529 !important;')
        ->toMatch(
            '/\.leadlovers-correction-trigger\s*\{[\s\S]*?background:\s*linear-gradient\(135deg, #b61251 0%, #d9155f 100%\)\s*!important;/',
        )
        ->toMatch(
            '/\.leadlovers-correction-trigger:focus-visible\s*\{[\s\S]*?outline:\s*3px solid #7b0d38;[\s\S]*?outline-offset:\s*3px;/',
        )
        ->toMatch(
            '/\.leadlovers-correction-modal__submit\s*\{[\s\S]*?background:\s*linear-gradient\(135deg, var\(--leadlovers-brand\), #2058c7\)\s*!important;/',
        )
        ->toMatch(
            '/\.leadlovers-correction-modal__submit:focus-visible,[\s\S]*?outline:\s*3px solid #0b5ed7;/',
        )
        ->toMatch(
            '/data-dashboard-theme="dark"\]\) \.leadlovers-correction-trigger:focus-visible,[\s\S]*?outline-color:\s*#ff8fba;/',
        )
        ->toMatch(
            '/data-dashboard-theme="dark"\]\) \.leadlovers-correction-modal__submit:focus-visible,[\s\S]*?outline-color:\s*#8ec5ff;/',
        )
        ->toContain('@media (max-width: 575.98px)')
        ->toMatch(
            '/@media \(max-width: 575\.98px\)[\s\S]*?\.leadlovers-correction-modal__footer \.btn\s*\{[\s\S]*?width:\s*100%;/',
        )
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->toContain('transition: none !important;')
        ->toMatch(
            '/@media \(prefers-reduced-motion: reduce\)[\s\S]*?\.leadlovers-correction-trigger:hover\s*\{[\s\S]*?transform:\s*none;/',
        )
        ->not->toContain('order: -1;');
});
