<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['features.insurance_analysis.enabled' => false, 'services.leadlovers.enabled' => false, 'features.dashboard_realtime.enabled' => false]);
    $this->company = Imobiliaria::factory()->create(['name' => 'Imobiliária Alameda', 'lead_access_code' => 'DEMO24']);
});

it('preserves dashboard actions and real metrics in both visual profiles', function (string $role, string $brand): void {
    config(['branding.active' => $brand, 'features.insurance_analysis.enabled' => true]);
    foreach (['Aprovado', 'Recusado', 'Novo'] as $index => $result) {
        $lead = Lead::query()->forceCreate([
            'company_id' => $this->company->id, 'nome' => ['Marina Lopes', 'Rafael Pires', 'Bianca Reis'][$index],
            'email' => 'contato'.$index.'@example.test', 'tel' => '11981724630', 'status' => 'novo',
            'tipo_solicitante' => 'imobiliaria_cadastrada', 'origem' => 'imobiliaria_cadastrada',
            'tags_originais' => $result, 'leadlovers_status' => 'sent', 'sent_to_leadlovers_at' => now(),
        ]);
        if ($index < 2) {
            $lead->latestInsuranceAnalysisBatch()->create([
                'company_id' => $this->company->id,
                'status' => $index === 0 ? 'processing' : 'completed',
            ]);
        }
    }
    $foreign = Lead::query()->forceCreate([
        'nome' => 'Contato de outra carteira', 'email' => 'outra-carteira@example.test',
        'company_id' => Imobiliaria::factory()->create()->id, 'status' => 'novo',
        'tipo_solicitante' => 'imobiliaria_cadastrada', 'origem' => 'imobiliaria_cadastrada',
        'leadlovers_status' => 'sent', 'sent_to_leadlovers_at' => now(),
    ]);
    if ($role === 'admin') {
        $admin = Corretor::query()->create([
            'name' => 'Gestor da carteira', 'email' => 'gestor@example.test', 'cpf' => '52998224725',
            'password' => 'password', 'role' => Corretor::ROLE_CEO, 'active' => true, 'first_login_verified_at' => now(),
        ]);
        $response = $this->actingAs($admin, 'admin')->get(route('Dashboard-Admin'));
    } else {
        $response = $this->actingAs(User::factory()->create(['company_id' => $this->company->id]))
            ->withSession(['2fa_passed' => true])->get(route('company.dashboard'));
    }
    $response->assertOk()->assertSee('dashboard-overview', false);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//article[.//div[@role="status" and contains(., "Análise em andamento")]]')->length)->toBe(1)
        ->and($xpath->query('//article[contains(., "Marina Lopes")]//div[@role="status"]/span[contains(@class,"spinner-border")]')->length)->toBe(1);
    expect($xpath->query('//h1')->length)->toBe(1)
        ->and($xpath->query('//*[@data-dashboard-metric]')->length)->toBe(4)
        ->and($xpath->query('//form[@data-lead-filter-form]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="leads-section"]')->length)->toBe(1);
    if ($role === 'company') {
        $response->assertDontSee($foreign->email)->assertSee('Compartilhar formulário');
        expect($xpath->query('//details[contains(@class,"ops-secondary")]/summary')->length)->toBe(1)
            ->and($xpath->query('//dl[@class="base-summary__metrics"]/div/dd')->length)->toBe(3)
            ->and($xpath->query('//section[@class="base-summary__guide"]/ol/li')->length)->toBe(3)
            ->and($xpath->query('//*[@id="dashboardLeadAccessCode" and @value="DEMO24"]')->length)->toBe(1)
            ->and($xpath->query('//*[@id="dashboardLeadFormCopyButton" and @type="button"]')->length)->toBe(1)
            ->and($xpath->query('//*[@data-dashboard-metric][1]/*[contains(@class,"ops-metric__value")]')->item(0)->textContent)->toBe('3');
    } else {
        $response->assertSee('Marina Lopes')->assertSee('Abrir formulário');
        expect($xpath->query('//button[@data-bs-target="#adminSimulationModal"]')->length)->toBeGreaterThan(0);
    }

    if ($directory = getenv('DASHBOARD_PREVIEW_DIR')) {
        $html = $response->getContent();
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $css = '';
        foreach ([$role === 'admin' ? 'header-dashboard-admin' : 'header-dashboard-user', 'dashboard-'.$role, 'lead-filters', 'leadlovers-correction', 'branding', $role === 'admin' ? 'admin-workspace' : 'company-workspace'] as $entry) {
            $entry = $entry === 'dashboard-company' ? 'dashboard-user' : $entry;
            if (isset($manifest['resources/css/'.$entry.'.css'])) {
                $css .= '<style>'.file_get_contents(public_path('build/'.$manifest['resources/css/'.$entry.'.css']['file'])).'</style>';
            }
        }
        $html = str_replace('</head>', $css.'</head>', $html);
        $html = preg_replace_callback('~src="https?://(?:localhost|127\.0\.0\.1:8000)/([^"?]+)"~', function (array $match): string {
            $path = public_path(rawurldecode($match[1]));

            return is_file($path)
                ? 'src="data:'.mime_content_type($path).';base64,'.base64_encode(file_get_contents($path)).'"'
                : $match[0];
        }, $html);
        $html = preg_replace('~https?://localhost(?=[/:"\'])~', 'http://127.0.0.1:8765', $html);
        file_put_contents($directory.'/'.$role.'-'.$brand.'.html', $html);
    }
})->with(['admin', 'company'])->with(['tcc', 'client']);

it('renders the empty company dashboard with usable labels and unavailable access states', function (): void {
    $this->company->update(['lead_form_active' => false, 'lead_access_code' => null]);
    $response = $this->actingAs(User::factory()->create(['company_id' => $this->company->id]))
        ->withSession(['2fa_passed' => true])->get(route('company.dashboard'))->assertOk();
    $response->assertSee('Sem leads cadastrados')->assertSee('Indisponível');
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//*[@id="dashboardLeadFormCopyButton" and @disabled]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="dashboardLeadFormOpenButton" and @aria-disabled="true"]')->length)->toBe(1);
});
