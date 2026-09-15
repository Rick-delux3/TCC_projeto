<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Support\CorretorPermissions;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    config(['features.insurance_analysis.enabled' => false, 'services.leadlovers.enabled' => false]);
    $this->admin = Corretor::query()->create([
        'name' => 'Administrador teste', 'email' => 'admin-modal@example.test',
        'cpf' => '52998224725', 'password' => 'password', 'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => [CorretorPermissions::VIEW_LEADS, CorretorPermissions::EDIT_LEADS],
        'active' => true, 'first_login_verified_at' => now(),
    ]);
    $this->lead = Lead::query()->create([
        'nome' => 'Pretendente teste', 'email' => 'pretendente@example.test', 'cpf' => '52998224725',
        'tel' => '11900000000', 'tipo_solicitante' => 'locatario', 'origem' => 'locatario',
        'status' => 'novo', 'estado_civil' => 'solteiro', 'tipo_locacao' => 'residencial',
    ]);
    $this->actingAs($this->admin, 'admin');
});

it('saves commercial company details and normalizes formatted documents', function () {
    $this->post(route('admin.leads.update', $this->lead), [
        'cpf' => '11.222.333/0001-81', 'cpf_responsavel' => '111.444.777-35',
        'nome_responsavel' => 'Representante teste', 'tipo_locacao' => 'comercial',
        'descrever_atividade' => 'Comercio de roupas',
    ])->assertSessionHasNoErrors();

    $lead = $this->lead->fresh();
    expect($lead->cpf)->toBeNull()
        ->and($lead->tipo_locacao->value)->toBe('comercial')
        ->and($lead->descrever_atividade)->toBe('Comercio de roupas')
        ->and($lead->lead_empresa->cnpj)->toBe('11222333000181')
        ->and($lead->lead_empresa->cpf_responsavel)->toBe('11144477735');

    $this->post(route('admin.leads.update', $lead), [
        'cpf_responsavel' => '11144477735', 'nome_responsavel' => 'Representante atualizado',
    ])->assertSessionHasNoErrors();
    expect($lead->fresh()->lead_empresa->nome_responsavel)->toBe('Representante atualizado');
});

it('removes inapplicable details and applies the address fallback without changing consent or email', function () {
    $this->lead->update(['cpf' => null, 'tipo_locacao' => 'comercial', 'descrever_atividade' => 'Loja', 'estado_civil' => 'casado']);
    $this->lead->lead_empresa()->create(['cnpj' => '11222333000181', 'cpf_responsavel' => '11144477735', 'nome_responsavel' => 'Representante teste']);
    $this->lead->conjuge()->create(['nome' => 'Conjuge teste', 'cpf' => '11144477735']);
    $this->lead->endereco()->create(['numero' => '45']);
    $consent = $this->lead->fresh()->aceite_termos;

    $this->post(route('admin.leads.update', $this->lead), [
        'cpf' => '529.982.247-25', 'tipo_locacao' => 'residencial', 'estado_civil' => 'separado',
        'numero' => '', 'email' => 'changed@example.test', 'aceite_termos' => ! $consent,
    ])->assertSessionHasNoErrors();

    $lead = $this->lead->fresh();
    expect($lead->cpf)->toBe('52998224725')->and($lead->lead_empresa)->toBeNull()
        ->and($lead->conjuge)->toBeNull()->and($lead->descrever_atividade)->toBeNull()
        ->and($lead->endereco->numero)->toBe('123')
        ->and($lead->email)->toBe('pretendente@example.test')
        ->and($lead->aceite_termos)->toBe($consent);
});

it('rejects invalid conditional data before saving', function (array $payload, string $error) {
    $this->post(route('admin.leads.update', $this->lead), ['nome' => 'Nao deve salvar', ...$payload])
        ->assertSessionHasErrors($error);
    expect($this->lead->fresh()->nome)->toBe('Pretendente teste')
        ->and($this->lead->fresh()->lead_empresa)->toBeNull();
})->with([
    'activity required' => [['tipo_locacao' => 'comercial'], 'descrever_atividade'],
    'representative required' => [['cpf' => '11222333000181'], 'cpf_responsavel'],
    'representative name required' => [['cpf' => '11222333000181', 'cpf_responsavel' => '11144477735'], 'nome_responsavel'],
    'invalid document' => [['cpf' => '12345678900'], 'cpf'],
    'array document' => [['cpf' => ['123']], 'cpf'],
    'married spouse required' => [['estado_civil' => 'casado'], 'conjuge_nome'],
    'same spouse document' => [['estado_civil' => 'casado', 'conjuge_nome' => 'Conjuge teste', 'conjuge_cpf' => '52998224725'], 'conjuge_cpf'],
    'company profile cannot link itself' => [['tipo_solicitante' => 'imobiliaria_cadastrada'], 'tipo_solicitante'],
]);

it('preserves related data when an unrelated partial update is submitted', function () {
    $this->lead->update(['cpf' => null, 'tipo_locacao' => 'comercial', 'descrever_atividade' => 'Loja', 'estado_civil' => 'casado']);
    $this->lead->lead_empresa()->create(['cnpj' => '11222333000181', 'cpf_responsavel' => '11144477735', 'nome_responsavel' => 'Representante teste']);
    $this->lead->conjuge()->create(['nome' => 'Conjuge teste', 'cpf' => '11144477735']);
    $this->lead->endereco()->create(['numero' => '45']);
    $this->post(route('admin.leads.update', $this->lead), ['nome' => 'Nome atualizado'])->assertSessionHasNoErrors();
    $lead = $this->lead->fresh();
    expect($lead->nome)->toBe('Nome atualizado')->and($lead->lead_empresa->cnpj)->toBe('11222333000181')
        ->and($lead->conjuge->nome)->toBe('Conjuge teste')->and($lead->endereco->numero)->toBe('45')
        ->and($lead->descrever_atividade)->toBe('Loja');
});

it('maps requester fields for the stored profile', function () {
    $data = ['responsavel_nome' => 'Responsavel teste', 'responsavel_email' => 'responsavel@example.test', 'responsavel_telefone' => '(11) 90000-0000'];
    $this->lead->update(['tipo_solicitante' => 'locador']);
    $this->post(route('admin.leads.update', $this->lead), ['tipo_solicitante' => 'locador', ...$data])->assertSessionHasNoErrors();
    expect($this->lead->fresh()->locador->nome)->toBe('Responsavel teste');
    $this->lead->locador()->delete();
    $this->lead->update(['tipo_solicitante' => 'imobiliaria_nao_cadastrada']);
    $this->post(route('admin.leads.update', $this->lead), ['tipo_solicitante' => 'imobiliaria_nao_cadastrada', ...$data])->assertSessionHasNoErrors();
    $lead = $this->lead->fresh();
    expect($lead->locador)->toBeNull()->and($lead->imobiliariaInformada->nome_imobiliaria_informada)->toBe('Responsavel teste')
        ->and($lead->imobiliariaInformada->responsavel_preenchimento)->toBe('responsavel@example.test')
        ->and($lead->imobiliariaInformada->telefone_responsavel)->toBe('11900000000');
});

it('rejects manipulated requester profiles without changing related data', function () {
    $this->lead->update(['tipo_solicitante' => 'locador']);
    $this->lead->locador()->create(['nome' => 'Responsavel original']);
    $this->post(route('admin.leads.update', $this->lead), [
        'tipo_solicitante' => 'imobiliaria_nao_cadastrada', 'responsavel_nome' => 'Nome manipulado',
    ])->assertSessionHasErrors('tipo_solicitante');
    $lead = $this->lead->fresh();
    expect($lead->tipo_solicitante)->toBe('locador')
        ->and($lead->locador->nome)->toBe('Responsavel original')
        ->and($lead->imobiliariaInformada)->toBeNull();
});

it('renders the stored requester profile as readonly despite manipulated old input', function () {
    $this->withSession(['_old_input' => ['lead_context_id' => $this->lead->id, 'tipo_solicitante' => 'locador']]);
    $html = $this->get(route('Dashboard-Admin'))->assertSuccessful()->getContent();
    preg_match('/<input[^>]*name="tipo_solicitante"[^>]*>/s', $html, $matches);
    expect($matches[0])->toContain('readonly')->toContain('value="locatario"');
});

it('does not create empty requester records on an unchanged save', function () {
    $this->lead->update(['tipo_solicitante' => 'locador']);
    $this->post(route('admin.leads.update', $this->lead), ['tipo_solicitante' => 'locador', 'responsavel_nome' => null])->assertSessionHasNoErrors();
    expect($this->lead->fresh()->locador)->toBeNull();
});

it('saves the registered company requester without modifying its company link', function () {
    $company = Imobiliaria::query()->create([
        'name' => 'Imobiliaria teste', 'email' => 'company@example.test', 'password' => 'password',
        'phone' => '11900000000', 'city' => 'Sao Paulo', 'state' => 'SP', 'lead_form_active' => true,
    ]);
    $this->lead->update(['company_id' => $company->id, 'tipo_solicitante' => 'imobiliaria_cadastrada']);
    $this->post(route('admin.leads.update', $this->lead), [
        'tipo_solicitante' => 'imobiliaria_cadastrada', 'responsavel_preenchimento' => 'Funcionario teste',
    ])->assertSessionHasNoErrors();
    expect($this->lead->fresh()->imobiliariaInformada->responsavel_preenchimento)->toBe('Funcionario teste');
    $this->post(route('admin.leads.update', $this->lead), ['tipo_solicitante' => 'locador'])
        ->assertSessionHasErrors('tipo_solicitante');
    expect($this->lead->fresh()->company_id)->toBe($company->id);
});

it('requires edit permission', function () {
    $this->admin->update(['permissions' => [CorretorPermissions::VIEW_LEADS]]);
    $this->post(route('admin.leads.update', $this->lead), ['nome' => 'Nome alterado'])->assertForbidden();
    expect($this->lead->fresh()->nome)->toBe('Pretendente teste');
});

it('renders all simulation additions inside the admin edit form', function () {
    $response = $this->get(route('Dashboard-Admin'))->assertSuccessful();
    foreach (['tipo_locacao', 'descrever_atividade', 'cpf_responsavel', 'nome_responsavel', 'responsavel_nome', 'responsavel_email', 'responsavel_telefone', 'responsavel_preenchimento'] as $field) {
        $response->assertSee('name="'.$field.'"', false);
    }
    $response->assertSee('data-admin-lead-fields', false);
});
