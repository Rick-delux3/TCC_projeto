<?php

use App\Models\Corretor;
use App\Models\Lead;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

it('renders the editable admin lead dashboard without tag-management permission or analysis controls', function () {
    config(['features.insurance_analysis.enabled' => false]);

    $corretor = Corretor::query()->create([
        'name' => 'Corretor de leads',
        'email' => 'dashboard-leads@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => ['leads.editar'],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
    $lead = Lead::query()->create([
        'tipo_solicitante' => 'locatario',
        'origem' => 'locatario',
        'nome' => 'Lead no dashboard',
        'email' => 'readonly-dashboard@example.test',
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
    'waiting initial send' => ['waiting_initial_send', 'Aguardando envio inicial'],
    'disabled' => ['disabled', 'Integração desativada'],
    'idle' => ['idle', 'Sem atualização pendente'],
    'null' => [null, 'Sem atualização pendente'],
]);

it('renders a safe UpdateLead failure message without the remote response', function () {
    $lead = new Lead([
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
