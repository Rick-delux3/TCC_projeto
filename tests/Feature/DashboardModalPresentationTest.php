<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\User;
use App\Support\CorretorPermissions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Queue::fake();
    config(['features.insurance_analysis.enabled' => false, 'features.dashboard_realtime.enabled' => false, 'services.leadlovers.enabled' => true]);
    $this->company = Imobiliaria::factory()->create(['name' => 'Imobiliária Alameda', 'lead_access_code' => 'ALM724', 'lead_form_active' => true]);
    $this->lead = Lead::query()->forceCreate([
        'company_id' => $this->company->id, 'nome' => 'Marina Cavalcante', 'email' => 'marina@example.test',
        'tel' => '11981724630', 'cpf' => '52998224725', 'status' => 'novo', 'tipo_locacao' => 'residencial',
        'tipo_solicitante' => 'imobiliaria_cadastrada', 'origem' => 'imobiliaria_cadastrada',
        'tags_originais' => 'Imobiliária Alameda, Aprovados', 'leadlovers_status' => 'sent',
        'leadlovers_lead_id' => 914, 'sent_to_leadlovers_at' => now(),
    ]);
    $this->lead->endereco()->create(['cep' => '18270001', 'logradouro' => 'Rua Onze de Agosto', 'numero' => '218', 'bairro' => 'Centro', 'cidade_imovel' => 'Tatuí', 'estado' => 'SP']);
    $this->lead->despesas()->create(['valor_aluguel' => 1870.50, 'valor_condominio' => 326.80, 'valor_iptu' => 92.40, 'valor_agua' => 0, 'valor_luz' => 0]);
    $this->correction = Lead::query()->forceCreate([
        'company_id' => $this->company->id, 'nome' => 'Rafael Abranches', 'email' => 'rafael@example.test',
        'tel' => '11973418260', 'cpf' => '11144477735', 'status' => 'novo',
        'tipo_solicitante' => 'imobiliaria_cadastrada', 'origem' => 'imobiliaria_cadastrada',
        'leadlovers_status' => 'failed', 'leadlovers_initial_error_status' => 400,
        'leadlovers_initial_error_code' => 'PHONE_EXISTS', 'leadlovers_initial_error_operation' => 'lead_creation',
    ]);
});

function dashboardModalAdmin(array $attributes = []): Corretor
{
    return Corretor::query()->create(array_merge([
        'name' => 'Helena Azevedo', 'email' => fake()->unique()->safeEmail(), 'password' => 'password',
        'role' => Corretor::ROLE_CEO, 'active' => true, 'first_login_verified_at' => now(),
    ], $attributes));
}

function dashboardModalDom(string $html): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

it('preserves modal forms tabs and the existing custom dialogs', function (string $role, string $brand): void {
    config(['branding.active' => $brand]);
    if ($role === 'admin') {
        $this->actingAs(dashboardModalAdmin(), 'admin');
        $route = 'Dashboard-Admin';
        $prefix = 'adminLeadModal';
        $formId = 'adminLeadUpdateForm';
        $updateRoute = 'admin.leads.update';
    } else {
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]))->withSession(['2fa_passed' => true]);
        $route = 'company.dashboard';
        $prefix = 'leadModal';
        $formId = 'leadUpdateForm';
        $updateRoute = 'dashboard.leads.update';
    }
    $response = $this->get(route($route))->assertOk();
    $html = $response->getContent();
    $xpath = dashboardModalDom($html);
    $modal = '//*[@id="'.$prefix.$this->lead->id.'"]';
    $form = '//form[@id="'.$formId.$this->lead->id.'"]';
    expect($xpath->query($modal.'[contains(@class,"dashboard-modal--lead")]')->length)->toBe(1)
        ->and($xpath->query($modal.'[@aria-describedby="'.$prefix.'Description'.$this->lead->id.'"]')->length)->toBe(1)
        ->and($xpath->query($modal.'//h2[@id="'.$prefix.'Label'.$this->lead->id.'"]')->length)->toBe(1)
        ->and($xpath->query($modal.'/div/div/div[contains(@class,"dashboard-modal-tabs")]//button[@data-dashboard-editor-tab]')->length)->toBe(1)
        ->and($xpath->query($modal.'//input[@name="email" and @readonly]')->length)->toBe(1)
        ->and($xpath->query($form.'//input[@name="_token"]')->length)->toBe(1)
        ->and($xpath->query($form.'//input[@name="_method" and @value="PUT"]')->length)->toBe($role === 'company' ? 1 : 0)
        ->and($xpath->query($form.'[@action="'.route($updateRoute, $this->lead).'"]')->length)->toBe(1)
        ->and($xpath->query($modal.'//button[@type="submit" and @form="'.$formId.$this->lead->id.'"]')->length)->toBe(1)
        ->and($xpath->query($modal.'//div[contains(@class,"dashboard-modal-section")]')->length)->toBeGreaterThan(2)
        ->and($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " leadlovers-correction-modal ") and not(contains(@class,"dashboard-modal"))]')->length)->toBe(1);
    foreach ($xpath->query($modal.'//button[@role="tab"]') as $tab) {
        expect($xpath->query('//*[@id="'.$tab->getAttribute('aria-controls').'"]')->length)->toBe(1);
    }
    if ($role === 'admin') {
        expect($xpath->query('//*[@id="adminLeadCompanyModal" and not(contains(@class,"dashboard-modal"))]')->length)->toBe(1)
            ->and($xpath->query('//*[@id="adminSimulationModal" and contains(@class,"dashboard-modal--chooser")]')->length)->toBe(1)
            ->and($xpath->query('//form[@id="adminSimulationSelectionForm" and @method="GET" and @target="adminSimulationForm"]')->length)->toBe(1)
            ->and($xpath->query('//*[@id="adminSimulationCompany" and @disabled]')->length)->toBe(1);
    }
    Http::assertNothingSent();
})->with(['admin', 'company'])->with(['tcc', 'client']);

it('keeps the view-only modal disabled without data or status editing', function (): void {
    $this->actingAs(dashboardModalAdmin(['role' => Corretor::ROLE_INTEGRANTE, 'permissions' => [CorretorPermissions::VIEW_LEADS]]), 'admin');
    $response = $this->get(route('Dashboard-Admin'))->assertOk();
    $xpath = dashboardModalDom($response->getContent());
    $modal = '//*[@id="adminLeadModal'.$this->lead->id.'"]';
    expect($xpath->query($modal.'//fieldset[@disabled]')->length)->toBe(1)
        ->and($xpath->query($modal.'//button[@data-lead-submit]')->length)->toBe(0)
        ->and($xpath->query($modal.'//form[contains(@class,"manual-lead-result-form")]')->length)->toBe(0);
});

it('preserves submitted data and field errors for the correct modal', function (string $role): void {
    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag(['nome' => 'Informe o nome completo.']));
    if ($role === 'admin') {
        $this->actingAs(dashboardModalAdmin(), 'admin');
    } else {
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]))->withSession(['2fa_passed' => true]);
    }
    $response = $this->withSession(['errors' => $errors, '_old_input' => ['lead_context_id' => (string) $this->lead->id, 'nome' => 'Marina em edição']])
        ->get(route($role === 'admin' ? 'Dashboard-Admin' : 'company.dashboard'))->assertOk();
    $xpath = dashboardModalDom($response->getContent());
    $inputPrefix = $role === 'admin' ? 'admin-lead' : 'company-lead';
    $fieldId = $inputPrefix.'-'.$this->lead->id.'-nome';
    expect($xpath->query('//input[@id="'.$fieldId.'" and @value="Marina em edição" and @aria-invalid="true"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="'.$fieldId.'-error"]')->item(0)->textContent)->toContain('Informe o nome completo.');
})->with(['admin', 'company']);

it('shows named creation errors beside the chooser field without changing its options', function (): void {
    $errors = new ViewErrorBag;
    $errors->put('adminSimulation', new MessageBag(['vinculo' => 'Selecione como o cadastro será vinculado.']));
    $response = $this->actingAs(dashboardModalAdmin(), 'admin')->withSession(['errors' => $errors])
        ->get(route('Dashboard-Admin'))->assertOk();
    $xpath = dashboardModalDom($response->getContent());
    expect($xpath->query('//select[@id="adminSimulationLinkType" and @aria-invalid="true"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="adminSimulationLinkTypeError"]')->length)->toBe(1)
        ->and($xpath->query('//select[@id="adminSimulationLinkType"]/option')->length)->toBe(3);
});

it('escapes user text in modal headings and the success notification', function (): void {
    $payload = '<img src=x onerror=alert(1)>';
    $this->lead->update(['nome' => $payload]);
    $response = $this->actingAs(dashboardModalAdmin(), 'admin')->withSession(['success' => $payload])
        ->get(route('Dashboard-Admin'))->assertOk()->assertSee(e($payload), false)->assertDontSee($payload, false);
    $xpath = dashboardModalDom($response->getContent());
    expect($xpath->query('//*[@id="ModalSucess"]')->length)->toBe(1)
        ->and($xpath->query('//img[@onerror]')->length)->toBe(0);
});
