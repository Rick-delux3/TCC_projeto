<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Support\CorretorPermissions;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['features.insurance_analysis.enabled' => false, 'services.leadlovers.enabled' => false]);
    $this->actingAs(Corretor::query()->create([
        'name' => 'Helena Azevedo', 'email' => 'helena@example.test', 'password' => 'password',
        'role' => Corretor::ROLE_CEO, 'active' => true, 'first_login_verified_at' => now(),
    ]), 'admin');
    $this->member = Corretor::query()->create([
        'name' => 'Mateus Prado', 'email' => 'mateus@example.test', 'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE, 'permissions' => [CorretorPermissions::VIEW_LEADS],
        'active' => true, 'invite_expires_at' => now()->addDays(2), 'invite_last_sent_at' => now(),
    ]);
    $this->company = Imobiliaria::factory()->create([
        'name' => 'Imobiliária Alameda', 'email' => 'alameda@example.test', 'lead_access_code' => 'ALM724',
        'lead_form_active' => true, 'tokio_partner_code' => '746210', 'porto_partner_code' => '28461',
    ]);
});

it('preserves headings fields and management actions in each visual profile', function (string $page, string $brand): void {
    config(['branding.active' => $brand]);
    $routes = [
        'team-index' => 'admin.config-equipe.index', 'team-create' => 'admin.config-equipe.create',
        'team-edit' => 'admin.config-equipe.edit', 'company-index' => 'admin.imobiliarias.index',
        'company-create' => 'admin.imobiliarias.create',
    ];
    $response = $this->get(route($routes[$page], $page === 'team-edit' ? [$this->member] : []))->assertOk();
    $html = $response->getContent();
    $xpath = managementPresentationDom($html);
    expect($xpath->query('//h1')->length)->toBe(1)
        ->and($xpath->query('//div[contains(@class,"dashboard-shell") and contains(@class,"management-workspace")]')->length)->toBe(1)
        ->and($xpath->query('//header[contains(@class,"management-page-header")]//h1')->length)->toBe(1);

    if (str_starts_with($page, 'team-') && $page !== 'team-index') {
        expect($xpath->query('//form[@data-team-form]//input[@name="_token"]')->length)->toBe(1)
            ->and($xpath->query('//label[@for="nome"]')->length)->toBe(1)
            ->and($xpath->query('//input[@name="permissions[]"]')->length)->toBeGreaterThan(0)
            ->and($xpath->query('//input[@data-team-permission-toggle-all]')->length)->toBe(1)
            ->and($xpath->query('//button[@data-team-submit]')->length)->toBe(1);
        if ($page === 'team-edit') {
            expect($xpath->query('//input[@name="nome" and @value="Mateus Prado"]')->length)->toBe(1)
                ->and($xpath->query('//input[@name="_method" and @value="PUT"]')->length)->toBe(1);
        }
    } elseif ($page === 'team-index') {
        $response->assertSee('Mateus Prado')->assertSee('Gerenciar equipe');
        expect($xpath->query('//*[@data-team-auto-submit]')->length)->toBeGreaterThan(0)
            ->and($xpath->query('//*[@data-dashboard-metric]')->length)->toBe(4)
            ->and($xpath->query('//label[@for="team-search"]')->length)->toBe(1);
    } elseif ($page === 'company-index') {
        $response->assertSee('Imobiliária Alameda');
        expect($xpath->query('//*[@data-company-edit-modal]')->length)->toBe(1)
            ->and($xpath->query('//*[@data-company-password-modal]')->length)->toBe(1)
            ->and($xpath->query('//label[@for="company-search"]')->length)->toBe(1)
            ->and($xpath->query('//input[@name="tokio_partner_code"]')->length)->toBe(1)
            ->and($xpath->query('//input[@name="porto_partner_code"]')->length)->toBe(1)
            ->and($xpath->query('//*[@data-dashboard-metric]')->length)->toBe(3);
    } else {
        expect($xpath->query('//form[@data-company-registration-form]//input[@name="_token"]')->length)->toBe(1)
            ->and($xpath->query('//nav[@aria-label="Etapas do cadastro"]//a')->length)->toBe(5)
            ->and($xpath->query('//input[@name="porto_partner_code"]')->length)->toBe(1)
            ->and($xpath->query('//button[@data-company-submit]')->length)->toBe(1);
    }
    managementPresentationPreview($html, $page, $brand);
})->with(['team-index', 'team-create', 'team-edit', 'company-index', 'company-create'])->with(['tcc', 'client']);

it('keeps validation messages beside the submitted team fields', function (): void {
    $errors = new \Illuminate\Support\ViewErrorBag;
    $errors->put('default', new \Illuminate\Support\MessageBag(['email' => 'Informe um e-mail válido.']));
    $response = $this->withSession(['errors' => $errors, '_old_input' => ['nome' => 'Lívia Campos', 'email' => 'email-invalido']])
        ->get(route('admin.config-equipe.create'))->assertOk();
    $xpath = managementPresentationDom($response->getContent());
    expect($xpath->query('//input[@name="nome" and @value="Lívia Campos"]')->length)->toBe(1)
        ->and($xpath->query('//input[@name="email" and contains(@class,"is-invalid") and @aria-invalid="true" and @aria-describedby="team-email-error"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="team-email-error"]')->length)->toBe(1);
    $response->assertSee('Informe um e-mail válido.');
    managementPresentationPreview($response->getContent(), 'team-error', 'tcc');
});

it('renders useful empty search results without hiding the search controls', function (string $section): void {
    $route = $section === 'team' ? 'admin.config-equipe.index' : 'admin.imobiliarias.index';
    $response = $this->get(route($route, ['search' => 'Busca sem correspondência']))->assertOk();
    $xpath = managementPresentationDom($response->getContent());
    expect($xpath->query('//input[@name="search" and @value="Busca sem correspondência"]')->length)->toBe(1)
        ->and($xpath->query('//h1')->length)->toBe(1);
    $response->assertDontSee('mateus@example.test')->assertDontSee('alameda@example.test');
    managementPresentationPreview($response->getContent(), $section.'-empty', 'tcc');
})->with(['team', 'company']);

function managementPresentationDom(string $html): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

function managementPresentationPreview(string $html, string $page, string $brand): void
{
    if (! $directory = getenv('MANAGEMENT_PREVIEW_DIR')) {
        return;
    }
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $css = '';
    foreach (['header-dashboard-admin', 'dashboard-admin', 'lead-filters', 'leadlovers-correction', 'branding', str_starts_with($page, 'team-') ? 'config-equipe' : 'imobiliarias-admin', 'admin-workspace'] as $entry) {
        $css .= '<style>'.file_get_contents(public_path('build/'.$manifest['resources/css/'.$entry.'.css']['file'])).'</style>';
    }
    $html = str_replace('</head>', $css.'</head>', $html);
    $html = preg_replace_callback('~src="https?://(?:localhost|127\.0\.0\.1:8000)/([^"?]+)"~', function (array $match): string {
        $path = public_path(rawurldecode($match[1]));

        return is_file($path)
            ? 'src="data:'.mime_content_type($path).';base64,'.base64_encode(file_get_contents($path)).'"'
            : $match[0];
    }, $html);
    if (! is_dir($directory.'/build/assets')) {
        mkdir($directory.'/build/assets', 0755, true);
    }
    foreach (glob(public_path('build/assets/*.js')) as $file) {
        copy($file, $directory.'/build/assets/'.basename($file));
    }
    $script = $manifest['resources/js/'.(str_starts_with($page, 'team-') ? 'config-equipe' : 'imobiliarias-admin').'.js']['file'];
    $html = str_replace('</body>', '<script type="module" src="/build/'.$script.'"></script></body>', $html);
    file_put_contents($directory.'/'.$page.'-'.$brand.'.html', $html);
}
