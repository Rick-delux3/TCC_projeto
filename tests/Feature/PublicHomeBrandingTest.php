<?php

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    $this->withoutVite();
});

it('keeps the legacy index visually isolated from the active brand', function (string $profile) {
    config([
        'features.public_index_enabled' => true,
        'branding.active' => $profile,
    ]);

    $response = $this->get(route('index'));

    $response
        ->assertOk()
        ->assertViewIs('index')
        ->assertSee('imgs/Logo_NVS.png', false)
        ->assertDontSee('imgs/logo-akialuga.jpg', false)
        ->assertDontSee('data-brand=', false);
})->with(['tcc', 'client']);

it('renders the reference landing page only for the tcc index', function (string $profile) {
    config([
        'features.public_index_enabled' => true,
        'branding.active' => $profile,
    ]);

    $response = $this->get(route('index'))->assertOk();

    if ($profile === 'tcc') {
        $response->assertSee('nvs-index', false)
            ->assertSee('<meta name="theme-color" content="'.config('branding.profiles.tcc.colors.primary').'">', false)
            ->assertSee('<meta name="color-scheme" content="dark">', false)
            ->assertSee('nvs-maquete-residencial.webp')
            ->assertSee('nvs-maquete-comercial.webp')
            ->assertSee('id="solucoes"', false)
            ->assertSee('id="recursos"', false)
            ->assertSee('id="suporte"', false)
            ->assertSee('https://wa.me/5511999999999', false)
            ->assertDontSee('images.unsplash.com', false)
            ->assertDontSee('cdn.tailwindcss.com', false);
    } else {
        $response->assertSee('id="hero"', false)
            ->assertDontSee('<meta name="color-scheme" content="dark">', false)
            ->assertDontSee('nvs-index', false)
            ->assertDontSee('nvs-maquete-')
            ->assertDontSee('id="suporte"', false);
    }
})->with(['tcc', 'client']);

it('connects the tcc landing actions and serves local images with valid section anchors', function () {
    config([
        'features.public_index_enabled' => true,
        'features.insurance_analysis.enabled' => true,
        'branding.active' => 'tcc',
    ]);

    $html = $this->get(route('index'))->assertOk()->getContent();
    expect(strip_tags($html))->toContain('Compare as parcelas lado a lado.')
        ->not->toContain('Seguradoras integradas', 'Pottencial', 'Too Seguros', 'Porto Seguro', 'Tokio Marine');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($document);

    foreach ([
        'Cadastrar minha imobiliária' => 'empresa.register.form',
        'Iniciar simulação' => 'simulation.start',
        'Acessar meu painel' => 'empresa.login',
        'Entrar' => 'empresa.login',
        'Administração' => 'tcc.admin.access',
    ] as $label => $routeName) {
        $link = $xpath->query('//a[normalize-space(.)="'.$label.'" or .//h3[normalize-space(.)="'.$label.'"]]')->item(0);

        expect($link)->not->toBeNull()
            ->and($link->getAttribute('href'))->toBe(route($routeName));
    }

    foreach ($xpath->query('//a[starts-with(@href, "#")]') as $anchor) {
        $target = substr($anchor->getAttribute('href'), 1);

        expect($target)->not->toBeEmpty()
            ->and($xpath->query('//*[@id="'.$target.'"]')->length)->toBe(1);
    }

    foreach ($xpath->query('//img') as $image) {
        $path = parse_url($image->getAttribute('src'), PHP_URL_PATH);

        expect($image->getAttribute('alt'))->not->toBeEmpty()
            ->and(is_file(public_path(ltrim($path, '/'))))->toBeTrue();
    }

    expect($xpath->query('//section[@id="recursos"]//a[contains(concat(" ", normalize-space(@class), " "), " nvs-button ")]')->length)->toBe(3);

    foreach (['residencial', 'comercial'] as $type) {
        $field = $xpath->query('//fieldset//input[@id="modelo-'.$type.'"]')->item(0);

        expect($field)->not->toBeNull()
            ->and($xpath->query('//fieldset//label[@for="modelo-'.$type.'"]')->length)->toBe(1)
            ->and($xpath->query('//fieldset//img[@id="'.$field->getAttribute('aria-controls').'" and @class="nvs-model-'.$type.'"]')->length)->toBe(1);
    }

    $navigation = $xpath->query('//header/nav | //header//nav')->item(0);

    expect($xpath->query('//header//button')->length)->toBe(0)
        ->and($xpath->query('//header//nav')->length)->toBe(1)
        ->and($navigation->getAttribute('class'))->not->toContain('hidden')
        ->and($xpath->query('//h1')->length)->toBe(1)
        ->and($xpath->query('//fieldset//input[@type="radio"]')->length)->toBe(2)
        ->and($xpath->query('//input[@id="modelo-residencial" and @checked]')->length)->toBe(1)
        ->and($xpath->query('.//a[starts-with(@href, "#")]', $navigation)->length)->toBe(4)
        ->and($xpath->query('//*[@id="nvs-mobile-menu"]')->length)->toBe(0);
});

it('opens index access and simulation links safely in a new tab only for tcc', function (string $profile) {
    config([
        'features.public_index_enabled' => true,
        'branding.active' => $profile,
    ]);

    $html = $this->get(route('index'))->assertOk()->getContent();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($document);
    $simulationUrl = route('simulation.start');
    $accessLinks = $xpath->query('//a[@href="'.route('empresa.login').'" or @href="'.route('empresa.register.form').'" or @href="'.route('tcc.admin.access').'" or @href="'.$simulationUrl.'"]');

    expect($accessLinks->length)->toBeGreaterThan(0);
    expect($xpath->query('//header//a[@href="'.route('tcc.admin.access').'"]')->length)
        ->toBe($profile === 'tcc' ? 1 : 0)
        ->and($xpath->query('//a[@href="'.$simulationUrl.'"]')->length)
        ->toBe($profile === 'tcc' ? 2 : 1);

    foreach ($accessLinks as $link) {
        if ($profile === 'tcc') {
            $label = trim(($xpath->query('.//h3', $link)->item(0) ?? $link)->textContent);

            expect($link->getAttribute('target'))->toBe('_blank')
                ->and($link->hasAttribute('data-auth-access'))->toBe($link->getAttribute('href') !== $simulationUrl)
                ->and(explode(' ', $link->getAttribute('rel')))->toContain('noopener', 'noreferrer')
                ->and($link->getAttribute('aria-label'))->toBe($label.' (abre em nova guia)');
        } else {
            expect($link->getAttribute('target'))->not->toBe('_blank');
        }
    }

    foreach ($xpath->query('//a[starts-with(@href, "#")]') as $link) {
        expect($link->getAttribute('target'))->not->toBe('_blank');
    }
})->with(['tcc', 'client']);

it('renders the new public page with the selected brand', function (
    string $profile,
    string $expectedLogo,
    string $expectedHeaderLogo,
    string $unexpectedLogo,
) {
    config([
        'features.public_index_enabled' => false,
        'branding.active' => $profile,
    ]);

    $response = $this->get(route('index'));

    $response
        ->assertOk()
        ->assertViewIs('simulation.start')
        ->assertSee('data-brand="'.$profile.'"', false)
        ->assertSee($expectedLogo, false)
        ->assertSee($expectedHeaderLogo, false)
        ->assertDontSee($unexpectedLogo, false)
        ->assertSee('name="tipo_solicitante"', false);
})->with([
    'tcc profile' => ['tcc', 'imgs/Logo_NVS.png', 'imgs/Logo_NVS.png', 'imgs/logo-header.jpg'],
    'client profile' => ['client', 'imgs/logo-akialuga.jpg', 'imgs/logo-header.jpg', 'imgs/Logo_NVS.png'],
]);

it('centralizes the complete visual identity for both brand profiles', function () {
    expect(config('branding.profiles.tcc'))
        ->toMatchArray([
            'logo' => 'imgs/Logo_NVS.png',
            'logo_header' => 'imgs/Logo_NVS.png',
            'logo_email' => 'imgs/Logo_NVS.png',
            'favicon' => 'imgs/Logo_NVS.png',
            'favicon_type' => 'image/png',
        ])
        ->and(config('branding.profiles.tcc.colors'))
        ->toMatchArray([
            'primary' => '#030133',
            'blue' => '#146FB6',
            'accent' => '#FD1E6E',
            'background' => '#F0F5FB',
            'surface' => '#FFFFFF',
            'text' => '#1F2937',
            'text_muted' => '#55658C',
            'border' => '#D8E1EC',
        ])
        ->and(config('branding.profiles.client'))
        ->toMatchArray([
            'logo' => 'imgs/logo-akialuga.jpg',
            'logo_header' => 'imgs/logo-header.jpg',
            'logo_email' => 'imgs/logo-akialuga.jpg',
            'favicon' => 'imgs/logo-akialuga.jpg',
            'favicon_type' => 'image/jpeg',
        ])
        ->and(config('branding.profiles.client.colors'))
        ->toMatchArray([
            'primary' => '#00288F',
            'primary_dark' => '#001650',
            'primary_hover' => '#001F73',
            'accent' => '#E6000B',
            'background' => '#F3F6FC',
            'surface' => '#FFFFFF',
            'text' => '#14213D',
            'text_muted' => '#53617A',
            'border' => '#D5DEEB',
        ]);
});

it('isolates the client logo palette and preserves the original tcc tokens', function () {
    $css = file_get_contents(resource_path('css/branding.css'));
    $clientStart = strpos($css, '[data-brand="client"]');
    $clientOverrideStart = strrpos(substr($css, 0, strpos($css, 'Perfil Client | identidade Aki Aluga / Neves')), '/*');
    $clientStyles = substr($css, $clientStart, $clientOverrideStart - $clientStart);

    expect(preg_match('/\[data-brand="tcc"\]\s*\{(?<tokens>.*?)\}/s', $css, $tccMatch))->toBe(1)
        ->and(preg_match('/\[data-brand="client"\]\s*\{(?<tokens>.*?)\}/s', $css, $clientMatch))->toBe(1);

    expect($tccMatch['tokens'])
        ->toContain('--brand-primary: #030133;')
        ->toContain('--brand-primary-hover: #146fb6;')
        ->toContain('--brand-accent: #fd1e6e;')
        ->not->toContain('#00288f')
        ->not->toContain('#e6000b')
        ->and($clientMatch['tokens'])
        ->toContain('--brand-primary: #00288f;')
        ->toContain('--brand-primary-dark: #001650;')
        ->toContain('--brand-accent: #e6000b;')
        ->toContain('--brand-background: #f3f6fc;')
        ->not->toContain('#030133')
        ->not->toContain('#146fb6')
        ->not->toContain('#fd1e6e')
        ->and($clientStyles)
        ->not->toContain('#0000ff')
        ->not->toContain('#0000b8')
        ->not->toContain('#8a8aff')
        ->not->toContain('rgba(255, 0, 0')
        ->not->toContain('rgba(253, 30, 110')
        ->and($css)
        ->toContain('[data-brand="client"] .lead-filter-submit')
        ->toContain('[data-brand="client"] .simulation-btn--accent')
        ->toMatch('/\[data-brand="client"\] \.btn-primary\s*\{.*?--bs-btn-bg: #00288f;.*?background-image: none !important;/s');
});

it('opens the profile selector directly when the client landing is disabled', function () {
    config([
        'features.public_index_enabled' => false,
        'branding.active' => 'client',
    ]);

    $response = $this->get(route('index'));
    $response
        ->assertOk()
        ->assertViewIs('simulation.start')
        ->assertDontSee('class="auth-topbar', false)
        ->assertDontSee('id="companyAccessUnavailableModal"', false)
        ->assertSee('data-brand-logo="client"', false)
        ->assertSee(route('simulation.profile'));
});

it('renders the public simulation gateway with the reference composition', function (string $profile) {
    config(['branding.active' => $profile]);

    $response = $this->get(route('simulation.start'));
    $html = $response->getContent();

    $response
        ->assertOk()
        ->assertViewIs('simulation.start')
        ->assertSeeText('Escolha seu perfil')
        ->assertSeeText('Dados da solicitação')
        ->assertSeeText($profile === 'tcc' ? 'Simule seu seguro para aluguel' : 'É Online, é Simples, é Seguro')
        ->assertSeeText('Qual opção descreve você?')
        ->assertDontSee('class="auth-topbar', false)
        ->assertSeeText('Você poderá revisar os dados antes de enviar.');

    expect($html)
        ->toContain('data-simulation-start')
        ->toContain('simulation-progress')
        ->toContain('simulation-journey__aside')
        ->toContain($profile === 'tcc' ? 'nvs-maquete-residencial-680.webp' : 'simulation-journey__city')
        ->toContain('simulation-option__indicator')
        ->toContain('aria-live="polite"')
        ->toContain('name="_token"')
        ->and(substr_count($html, 'name="tipo_solicitante"'))->toBe(3);
})->with(['tcc', 'client']);

it('shares public simulation forms between brands with the correct brand identity', function (string $routeName, array $session) {
    if ($routeName === 'simulation.registered-company.form') {
        $company = Imobiliaria::factory()->create(['lead_access_code' => 'BRAND1']);
        $this->post(route('simulation.registered-company.verify'), ['lead_access_code' => $company->lead_access_code])
            ->assertRedirect(route($routeName));
    }

    $formContracts = [];

    foreach (['client', 'tcc'] as $profile) {
        config(['branding.active' => $profile]);

        $response = $this->withSession($session)->get(route($routeName, $routeName === 'simulation.unregistered-company.form'
            ? ['responsavel_tipo' => 'imobiliaria_nao_cadastrada'] : []));

        $response->assertOk()->assertSee('auth-layout-body simulation-public', false);

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        expect($xpath->query('//body/header')->length)->toBe(0);
        $logo = $xpath->query('//main//img[@data-brand-logo]')->item(0);

        expect($logo)->not->toBeNull()
            ->and($logo->getAttribute('data-brand-logo'))->toBe($profile)
            ->and($logo->getAttribute('src'))->toBe(asset(config("branding.profiles.{$profile}.logo_header")))
            ->and($logo->getAttribute('alt'))->toBe(config("branding.profiles.{$profile}.name"));

        if ($routeName === 'simulation.registered-company.form') {
            $profileLink = $xpath->query('//aside//a[normalize-space(.)="Voltar à escolha de perfil"]')->item(0);
            expect($profileLink?->getAttribute('href'))->toBe(route('simulation.start'))
                ->and($xpath->query('//main//a[@href="'.route('simulation.start').'"]')->length)->toBe(1);
        }

        $formContracts[$profile] = [];

        foreach ($xpath->query('//main//form | //main//input | //main//select | //main//button | //main//a') as $element) {
            $formContracts[$profile][] = [
                $element->nodeName,
                $element->getAttribute('name'),
                $element->getAttribute('type'),
                $element->getAttribute('action'),
                $element->getAttribute('method'),
                $element->getAttribute('href'),
                $element->hasAttribute('required'),
            ];
        }
    }

    expect($formContracts['tcc'])->not->toBeEmpty()->toBe($formContracts['client']);
})->with([
    'profile choice' => ['simulation.start', []],
    'access code' => ['simulation.registered-company.access', []],
    'registered form' => ['simulation.registered-company.form', []],
    'landlord form' => ['simulation.unregistered-company.form', []],
    'tenant form' => ['simulation.tenant.form', []],
    'recovery form' => ['simulation.registered-company.code.request', []],
    'recovery success' => ['simulation.registered-company.code.request', ['status' => 'Solicitação recebida']],
    'recovery limited' => ['simulation.registered-company.code.request', ['company_code_retry_after' => 60]],
    'submission success' => ['simulation.success', []],
]);

it('keeps the refactored simulation frontend free from unsafe dynamic html and browser storage', function () {
    $startView = file_get_contents(resource_path('views/simulation/start.blade.php'));
    $topbarView = file_get_contents(resource_path('views/auth/partials/auth-topbar.blade.php'));
    $javascript = file_get_contents(resource_path('js/simulation.js'));

    expect($startView)
        ->not->toContain('{!!')
        ->toContain('@csrf')
        ->and($topbarView)
        ->not->toContain('{!!')
        ->not->toContain('env(')
        ->not->toContain('target="_blank"')
        ->and($javascript)
        ->toContain('textContent')
        ->not->toContain('innerHTML')
        ->not->toContain('eval(')
        ->not->toContain('localStorage')
        ->not->toContain('sessionStorage');
});

it('uses the fictitious logo as the safe component fallback', function () {
    config(['branding.active' => 'unknown-profile']);

    $html = Blade::render('<x-brand-logo />');

    expect($html)
        ->toContain('imgs/Logo_NVS.png')
        ->not->toContain('imgs/logo-akialuga.jpg')
        ->toContain('data-brand-logo="tcc"');
});

it('resolves configured logo variants without leaking assets between brands', function () {
    config(['branding.active' => 'tcc']);

    $tccHeader = Blade::render('<x-brand-logo variant="logo_header" />');
    $tccApplicationLogo = Blade::render('<x-application-logo class="application-mark" />');
    $tccFavicon = Blade::render('<x-brand-favicon />');

    expect($tccHeader)
        ->toContain('imgs/Logo_NVS.png')
        ->not->toContain('imgs/logo-header.jpg')
        ->and($tccApplicationLogo)
        ->toContain('imgs/Logo_NVS.png')
        ->toContain('class="application-mark"')
        ->not->toContain('<svg')
        ->and($tccFavicon)
        ->toContain('type="image/png"')
        ->toContain('imgs/Logo_NVS.png');

    config(['branding.active' => 'client']);

    $clientHeader = Blade::render('<x-brand-logo variant="logo_header" />');
    $clientApplicationLogo = Blade::render('<x-application-logo />');
    $clientFavicon = Blade::render('<x-brand-favicon />');

    expect($clientHeader)
        ->toContain('imgs/logo-header.jpg')
        ->not->toContain('imgs/Logo_NVS.png')
        ->and($clientApplicationLogo)
        ->toContain('imgs/logo-akialuga.jpg')
        ->not->toContain('<svg')
        ->and($clientFavicon)
        ->toContain('type="image/jpeg"')
        ->toContain('imgs/logo-akialuga.jpg');
});

it('uses the configured header logo in the shared authentication topbar', function (
    string $profile,
    string $expectedLogo,
    string $unexpectedLogo,
) {
    config(['branding.active' => $profile]);

    $html = view('auth.partials.auth-topbar')->render();

    expect($html)
        ->toContain($expectedLogo)
        ->not->toContain($unexpectedLogo)
        ->toContain('object-fit: contain;');
})->with([
    'tcc topbar' => ['tcc', 'imgs/Logo_NVS.png', 'imgs/Logo_NVS-transparent-outline.png'],
    'client topbar' => ['client', 'imgs/logo-header.jpg', 'imgs/Logo_NVS.png'],
]);

it('uses active brand tokens in the shared loader without legacy palette leakage', function () {
    $loader = file_get_contents(resource_path('views/partials/page-loader.blade.php'));
    $css = file_get_contents(resource_path('css/branding.css'));

    expect($loader)
        ->toContain('var(--brand-primary-rgb, 3, 1, 51)')
        ->toContain('var(--brand-accent, #FD1E6E)')
        ->toContain('var(--brand-primary, #030133)')
        ->not->toContain('#EE1D23')
        ->not->toContain('#1F1D59')
        ->and($css)
        ->toMatch('/\[data-brand="tcc"\]\s*\{.*?--brand-primary-rgb:\s*3,\s*1,\s*51;/s');
});

it('renders accessible task feedback in the active brand on access and simulation screens', function (string $profile, string $layout, string $successId, string $errorId): void {
    config(['branding.active' => $profile]);
    session()->flash('success', 'Operação concluída.');
    $errors = new ViewErrorBag;
    $errors->put('default', new \Illuminate\Support\MessageBag(['email' => ['Revise <email>.']]));

    $html = view($layout, ['errors' => $errors])->render();
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    foreach ([$successId => 'success', $errorId => 'error'] as $id => $tone) {
        $modal = '//*[@id="'.$id.'" and @data-task-message-auto]';
        $panel = $profile === 'tcc' ? '//*[@data-tone="'.$tone.'"]' : '//*[@class="modal-content"]';
        expect($xpath->query($modal.$panel)->length)->toBe(1)
            ->and($xpath->query($modal.'//button[@data-bs-dismiss="modal"]')->length)->toBeGreaterThan(0)
            ->and($xpath->query($modal)->item(0)->getAttribute('aria-labelledby'))->toBe($id.'-title');
    }

    expect($html)->toContain('Revise &lt;email&gt;.', 'Operação concluída.', 'id="page-loader-modal"')
        ->and($xpath->query('//*[@data-task-message-auto]//*[@class="task-dialog"]')->length)->toBe($profile === 'tcc' ? 2 : 0)
        ->and($xpath->query('//*[@id="companyAccessUnavailableModal" and @data-task-message-auto]')->length)->toBe(0);
})->with(['tcc', 'client'])->with([
    ['layout-inicial.app', 'ModalSucess', 'modalErros'],
    ['layout-inicial.simulation', 'modalSuccess', 'modalErrors'],
]);

it('uses the company access layout for admin verification only on tcc', function (string $profile) {
    config(['branding.active' => $profile]);
    $this->withSession(['info' => 'Código reenviado para seu e-mail.']);
    $html = view('auth.admin-2fa', ['errors' => new Illuminate\Support\ViewErrorBag])->render();
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//body[contains(@class,"tcc-company-auth-body")]')->length)->toBe($profile === 'tcc' ? 1 : 0)
        ->and($xpath->query('//main[contains(@class,"tcc-company-auth-main--standalone")]')->length)->toBe($profile === 'tcc' ? 1 : 0)
        ->and($xpath->query('//body/header')->length)->toBe($profile === 'tcc' ? 0 : 1)
        ->and($xpath->query('//section[contains(@class,"client-auth-shell")]')->length)->toBe($profile === 'tcc' ? 1 : 0)
        ->and($xpath->query('//input[@id="code"][@maxlength="6"][@inputmode="numeric"][@autocomplete="one-time-code"]')->length)->toBe(1);
    foreach (['admin.2fa.verify', 'admin.2fa.resend', 'admin.logout'] as $route) {
        expect($xpath->query('//form[@method="POST"][@action="'.route($route).'"]//input[@name="_token"]')->length)->toBe(1);
    }
    expect($html)->toContain('Código reenviado para seu e-mail.');
})->with(['tcc', 'client']);

it('hides the NVS-only two-factor illustration from the client profile', function () {
    $css = file_get_contents(resource_path('css/branding.css'));

    expect($css)
        ->toMatch('/\[data-brand="client"\] \.verify-aside > img\s*\{\s*display:\s*none;\s*\}/s');
});

it('keeps both web two-factor views isolated to the active brand', function (
    string $profile,
    string $brandName,
    string $expectedPrimaryLogo,
    string $expectedHeaderLogo,
    string $unexpectedLogo,
    string $faviconType,
) {
    config(['branding.active' => $profile]);

    $viewData = ['errors' => new ViewErrorBag];
    $companyHtml = view('auth.2fa', $viewData)->render();
    $adminHtml = view('auth.admin-2fa', $viewData)->render();

    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$companyHtml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//body[contains(@class,"tcc-verification-body")]')->length)->toBe($profile === 'tcc' ? 1 : 0)
        ->and($xpath->query('//aside/img[contains(@class,"tcc-auth-background-logo")]')->length)->toBe($profile === 'tcc' ? 1 : 0)
        ->and($xpath->query('//img[contains(@class,"verify-brand")]')->length)->toBe($profile === 'tcc' ? 0 : 1)
        ->and($xpath->query('//div[contains(@class,"standalone-auth-page")]/header')->length)->toBe($profile === 'tcc' ? 0 : 1)
        ->and($xpath->query('//input[@name="code"][@maxlength="6"][@autocomplete="one-time-code"]')->length)->toBe(1);

    foreach (['2fa.verify.post', '2fa.resend'] as $route) {
        expect($xpath->query('//form[@method="POST"][@action="'.route($route).'"]//input[@name="_token"]')->length)->toBe(1);
    }

    expect($companyHtml)
        ->toContain('data-brand="'.$profile.'"')
        ->toContain($brandName)
        ->toContain($expectedPrimaryLogo)
        ->toContain($expectedHeaderLogo)
        ->toContain('type="'.$faviconType.'"')
        ->not->toContain($unexpectedLogo)
        ->and($adminHtml)
        ->toContain('data-brand="'.$profile.'"')
        ->toContain($brandName)
        ->toContain($expectedHeaderLogo)
        ->toContain('type="'.$faviconType.'"')
        ->not->toContain($unexpectedLogo);
})->with([
    'tcc / NVS' => [
        'tcc',
        'NVS',
        'imgs/Logo_NVS.png',
        'imgs/Logo_NVS.png',
        'imgs/logo-header.jpg',
        'image/png',
    ],
    'client / Aki Aluga' => [
        'client',
        'Aki Aluga',
        'imgs/logo-akialuga.jpg',
        'imgs/logo-header.jpg',
        'imgs/Logo_NVS.png',
        'image/jpeg',
    ],
]);

it('uses the framed client logo in both dashboard headers and sidebars', function (string $profile) {
    $corretor = Corretor::query()->create([
        'name' => 'Corretor de Teste',
        'email' => 'branding-admin@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);

    $this->actingAs($corretor, 'admin');
    config(['branding.active' => $profile]);

    $adminHeader = view('layout-inicial.partials.header_admin', [
        'dashboardStats' => [],
    ])->render();

    $this->actingAs(User::factory()->create());

    $companyHeader = view('layout-inicial.partials.header_imob', [
        'dashboardStats' => [],
    ])->render();

    foreach ([$adminHeader, $companyHeader] as $html) {

        if ($profile === 'client') {
            expect(substr_count($html, 'imgs/logo-header.jpg'))->toBe(2)
                ->and($html)->not->toContain('imgs/logo-akialuga.jpg')
                ->and($html)->not->toContain('imgs/Logo_NVS.png');
        } else {
            expect(substr_count($html, 'imgs/Logo_NVS.png'))->toBe(2)
                ->and($html)->not->toContain('imgs/logo-header.jpg')
                ->and($html)->not->toContain('imgs/logo-akialuga.jpg');
        }
    }
})->with(['tcc', 'client']);

it('renders the dashboard header composition for each brand profile', function (
    string $profile,
    string $operationLabel,
    string $expectedLogo,
) {
    config(['branding.active' => $profile]);
    $corretor = Corretor::query()->create([
        'name' => 'Carlos Alves',
        'email' => "header-{$profile}@example.test",
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);

    $this->actingAs($corretor, 'admin');
    $adminHeader = view('layout-inicial.partials.header_admin', [
        'dashboardStats' => ['newLeads' => 3],
    ])->render();

    $this->actingAs(User::factory()->create());
    $companyHeader = view('layout-inicial.partials.header_imob', [
        'dashboardStats' => ['newLeads' => 3],
    ])->render();

    foreach ([$adminHeader, $companyHeader] as $html) {
        expect($html)
            ->toContain('data-dashboard-header="'.$profile.'"')
            ->toContain('dashboard-client-header__rail')
            ->toContain('dashboard-client-header__primary')
            ->toContain('dashboard-header-status')
            ->toContain($operationLabel)
            ->toContain('dashboard-notification-count');
    }

    expect($adminHeader)
        ->toContain('Painel do CEO')
        ->toContain('Carlos Alves')
        ->toContain($profile === 'tcc' ? 'imgs/Logo_NVS-transparent.png' : $expectedLogo)
        ->not->toContain('dashboard-client-header__secondary')
        ->and($companyHeader)
        ->toContain($profile === 'tcc' ? 'Painel da empresa' : 'Painel da imobiliária')
        ->toContain('x-data="{ isCompact: window.scrollY > 24 }"')
        ->toContain('x-on:scroll.window.throttle.100ms="isCompact = window.scrollY > 24"')
        ->toContain('x-bind:class="{ \'is-compact\': isCompact }"')
        ->toContain('dashboard-client-header__secondary')
        ->toContain('dashboard-header-nav')
        ->toContain('aria-label="Localização atual"')
        ->toContain($expectedLogo)
        ->toContain('Simulação');
})->with([
    'NVS' => ['tcc', 'Sistema operacional', 'imgs/Logo_NVS.png'],
    'Aki Aluga' => ['client', 'Operação ativa', 'imgs/logo-header.jpg'],
]);

it('keeps the branded dashboard header responsive and accessible', function () {
    $adminEntry = file_get_contents(
        resource_path('css/header-dashboard-admin.css'),
    );
    $companyEntry = file_get_contents(
        resource_path('css/header-dashboard-user.css'),
    );
    $stylesheet = file_get_contents(
        resource_path('css/dashboard-header.css'),
    );

    expect($adminEntry)
        ->toContain('@import "./dashboard-header.css";')
        ->and($companyEntry)
        ->toContain('@import "./dashboard-header.css";')
        ->and($stylesheet)
        ->toContain('.dashboard-client-header[data-dashboard-header="tcc"]')
        ->toContain('.dashboard-client-header[data-dashboard-header="client"]')
        ->toContain('linear-gradient(108deg, #030133 0%, #020127 100%)')
        ->toContain('background: #ffffff;')
        ->toContain('background: #e6000b;')
        ->toContain('min-height: 48px;')
        ->toContain('.dashboard-client-header.is-compact')
        ->toContain('@keyframes dashboard-header-enter')
        ->toContain('@keyframes dashboard-status-pulse')
        ->toContain('.dashboard-notification-menu.show')
        ->toContain(':focus-visible')
        ->toContain('@media (min-width: 992px)')
        ->toContain('@media (max-width: 991.98px)')
        ->toContain('@media (max-width: 575.98px)')
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->toContain('@media (forced-colors: active)');
});

it('hides analysis navigation and keeps lead simulation available when analyses are disabled', function () {
    $this->actingAs(User::factory()->create());
    config(['features.insurance_analysis.enabled' => false]);

    $html = view('layout-inicial.partials.header_imob', [
        'dashboardStats' => [],
    ])->render();

    expect($html)
        ->not->toContain(route('insurance-analyses.index'))
        ->toContain(route('simulation.registered-company.access'))
        ->toContain('Página de simulação');

    foreach ([
        'analise',
        'insurance-analyses.index',
        'insurance-analyses.show',
        'admin.insurance-analyses.index',
        'admin.insurance-analyses.show',
    ] as $routeName) {
        expect(Route::getRoutes()->getByName($routeName)->gatherMiddleware())
            ->not->toContain('analysis.enabled');
    }

    foreach ([
        'insurance-analyses.provider-reanalysis',
        'admin.insurance-analyses.provider-reanalysis',
        'insurance-analyses.retry',
        'admin.insurance-analyses.retry',
    ] as $routeName) {
        expect(Route::getRoutes()->getByName($routeName)->gatherMiddleware())
            ->toContain('analysis.enabled');
    }
});

it('registers one coherent root route and keeps the original route name', function () {
    $rootRoutes = collect(Route::getRoutes())
        ->filter(fn ($route) => $route->uri() === '/');

    expect($rootRoutes)->toHaveCount(1)
        ->and(Route::getRoutes()->getByName('index'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('index')->uri())->toBe('/');
});

it('shows the original NVS logo across company authentication pages', function (string $routeName): void {
    config(['branding.active' => 'tcc']);
    $response = $this->get(route($routeName))->assertOk();
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//header//img[@src="'.asset('imgs/Logo_NVS.png').'"]')->length)->toBe(1);
    $response->assertSee('background: #030133;', false)
        ->assertDontSee('imgs/Logo_NVS-transparent-outline.png', false);
})->with(['empresa.login', 'empresa.register.form', 'company.password.request']);
