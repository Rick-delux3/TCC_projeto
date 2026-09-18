@extends('layout-inicial.Dashboard_Admin')

@push('styles')
    @vite('resources/css/imobiliarias-admin.css')
@endpush

@push('scripts')
    @vite('resources/js/imobiliarias-admin.js')
@endpush

@section('content_a')
@php
    $hasAvailableTags = $tagsOficiais->isNotEmpty();
    $indexRoute = route('admin.imobiliarias.index');
    $defaultDepartments = [
        'comercial' => ['Comercial / Vendas', 'comercial'],
        'contratos' => ['Contrato / Conferência', 'contratos'],
        'gerencia' => ['Gerência', 'gerencia'],
        'financeiro' => ['Financeiro / Pagamentos', 'financeiro'],
        'socio' => ['Sócio / Proprietário', 'socio'],
    ];
    $submittedDepartments = collect(is_array(old('setores')) ? old('setores') : [])
        ->filter(fn ($sector) => is_array($sector) && is_string($sector['key'] ?? null));
    $departmentRows = [];
    foreach ($defaultDepartments as $key => [$label, $placeholder]) {
        $submittedIndex = $submittedDepartments->search(fn ($sector) => $sector['key'] === $key);
        $submitted = $submittedIndex !== false ? $submittedDepartments[$submittedIndex] : [];
        $departmentRows[] = [
            'key' => $key, 'name' => $label, 'placeholder' => $placeholder.'@imobiliaria.com.br',
            'email' => is_string($submitted['email'] ?? null) ? $submitted['email'] : '',
            'custom' => false, 'error_index' => $submittedIndex,
        ];
    }
    foreach ($submittedDepartments as $index => $sector) {
        if (! array_key_exists($sector['key'], $defaultDepartments)) {
            $departmentRows[] = [
                'key' => $sector['key'],
                'name' => is_string($sector['name'] ?? null) ? $sector['name'] : '',
                'email' => is_string($sector['email'] ?? null) ? $sector['email'] : '',
                'placeholder' => 'setor@imobiliaria.com.br', 'custom' => true, 'error_index' => $index,
            ];
        }
    }
    $departmentsEnabled = (bool) old('_use_department_emails', $submittedDepartments->isNotEmpty());
    $formSteps = ['identification' => 'Identificação', 'contact' => 'Empresa e contato', 'departments' => 'Emails por setor', 'address' => 'Localização', 'access' => 'Acesso e disponibilidade'];
@endphp

<div class="dashboard-shell real-estate-admin real-estate-create-page">
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="company-form-container">
            <nav aria-label="Navegação estrutural" class="mb-3">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('Dashboard-Admin') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ $indexRoute }}">Imobiliárias</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Novo cadastro</li>
                </ol>
            </nav>

            <header class="company-create-header mb-4" data-reveal>
                <a href="{{ $indexRoute }}" class="company-back-link">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    Voltar para a listagem
                </a>

                <div class="mt-3">
                    <div>
                        <span class="company-eyebrow company-eyebrow--light">Novo parceiro</span>
                        <h1 class="fw-bold mb-1">Cadastrar imobiliária</h1>
                        <p class="mb-0">
                            Informe os dados da empresa e defina as credenciais que serão entregues ao responsável.
                        </p>
                    </div>
                </div>
            </header>

            @if ($errors->any())
                <div class="alert alert-danger company-validation-summary mb-4" role="alert" aria-labelledby="validation-summary-title" data-reveal>
                    <div class="d-flex gap-3 align-items-start">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                        <div>
                            <h2 id="validation-summary-title" class="h6 fw-bold mb-2">Revise os campos destacados</h2>
                            <p class="mb-0">Encontramos {{ $errors->count() }} {{ $errors->count() === 1 ? 'informação que precisa' : 'informações que precisam' }} de ajuste.</p>
                        </div>
                    </div>
                </div>
            @endif

            <div class="company-create-layout">
                <nav class="company-form-steps" aria-label="Etapas do cadastro">
                    <ol>
                        @foreach ($formSteps as $step => $label)
                            <li>
                                <a href="#company-{{ $step }}" data-company-step="company-{{ $step }}" @if ($loop->first) aria-current="step" @endif>
                                    <span aria-hidden="true">{{ $loop->iteration }}</span>{{ $label }}
                                </a>
                            </li>
                        @endforeach
                    </ol>
                </nav>
            <form
                method="POST"
                action="{{ route('admin.imobiliarias.store') }}"
                class="company-registration-form"
                data-company-registration-form
                data-cep-endpoint="{{ route('cep.show', ['cep' => '00000000']) }}"
            >
                @csrf

                <div class="company-honeypot" aria-hidden="true">
                    <label for="website">Não preencha este campo</label>
                    <input
                        type="text"
                        id="website"
                        name="website"
                        value=""
                        tabindex="-1"
                        autocomplete="off"
                    >
                </div>

                <div class="company-form-panel" data-reveal data-reveal-delay="50">
                <section id="company-identification" class="company-form-section is-active" aria-labelledby="company-identification-title" tabindex="-1">
                    <div class="card-body p-3 p-md-4">
                        <div class="company-form-section__heading">
                            <span class="company-form-section__number">1</span>
                            <div>
                                <h2 id="company-identification-title" class="h5 fw-bold mb-1">Identificação da imobiliária</h2>
                                <p class="text-muted small mb-0">Selecione a empresa integrada ou informe um novo nome.</p>
                            </div>
                        </div>

                        @if ($hasAvailableTags)
                            <div>
                                <label for="leadlovers_tag_id" class="form-label fw-semibold">
                                    Imobiliária <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <select
                                    id="leadlovers_tag_id"
                                    name="leadlovers_tag_id"
                                    class="form-select form-select-lg @error('leadlovers_tag_id') is-invalid @enderror"
                                    aria-describedby="company-tag-help @error('leadlovers_tag_id') company-tag-error @enderror"
                                    required
                                >
                                    <option value="">Selecione uma imobiliária</option>
                                    @foreach ($tagsOficiais as $tag)
                                        <option value="{{ $tag->leadlovers_tag_id }}" @selected((string) old('leadlovers_tag_id') === (string) $tag->leadlovers_tag_id)>
                                            {{ $tag->title }}
                                        </option>
                                    @endforeach
                                </select>
                                <div id="company-tag-help" class="form-text">
                                    A lista apresenta somente imobiliárias ativas que ainda não possuem cadastro.
                                </div>
                                @error('leadlovers_tag_id')
                                    <div id="company-tag-error" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        @else
                            <div>
                                <label for="company_name" class="form-label fw-semibold">
                                    Nome da imobiliária <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-buildings"></i></span>
                                    <input
                                        type="text"
                                        id="company_name"
                                        name="company_name"
                                        value="{{ old('company_name') }}"
                                        class="form-control @error('company_name') is-invalid @enderror"
                                        maxlength="255"
                                        placeholder="Ex.: Nova Casa"
                                        autocomplete="organization"
                                        aria-describedby="company-name-help @error('company_name') company-name-error @enderror"
                                        required
                                    >
                                    @error('company_name')
                                        <div id="company-name-error" class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div id="company-name-help" class="form-text">
                                    O prefixo “Imobiliária” será padronizado automaticamente.
                                </div>
                            </div>
                        @endif
                    </div>
                </section>

                <section id="company-contact" class="company-form-section" aria-labelledby="company-contact-title" tabindex="-1">
                    <div class="card-body p-3 p-md-4">
                        <div class="company-form-section__heading">
                            <span class="company-form-section__number">2</span>
                            <div>
                                <h2 id="company-contact-title" class="h5 fw-bold mb-1">Dados da empresa e contato</h2>
                                <p class="text-muted small mb-0">Esses dados identificam a imobiliária e seu acesso principal.</p>
                            </div>
                        </div>

                        <div class="row g-3 g-lg-4">
                            <div class="col-12 col-md-6">
                                <label for="email" class="form-label fw-semibold">
                                    E-mail de acesso <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                                    <input
                                        type="email"
                                        id="email"
                                        name="email"
                                        value="{{ old('email') }}"
                                        class="form-control @error('email') is-invalid @enderror"
                                        maxlength="255"
                                        placeholder="contato@imobiliaria.com.br"
                                        autocomplete="username"
                                        @error('email') aria-describedby="email-error" @enderror
                                        required
                                    >
                                    @error('email')
                                        <div id="email-error" class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="phone" class="form-label fw-semibold">
                                    Telefone <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-telephone"></i></span>
                                    <input
                                        type="tel"
                                        id="phone"
                                        name="phone"
                                        value="{{ old('phone') }}"
                                        class="form-control @error('phone') is-invalid @enderror"
                                        inputmode="numeric"
                                        maxlength="15"
                                        placeholder="(00) 00000-0000"
                                        autocomplete="tel"
                                        @error('phone') aria-describedby="phone-error" @enderror
                                        required
                                    >
                                    @error('phone')
                                        <div id="phone-error" class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="cnpj" class="form-label fw-semibold">
                                    CPF ou CNPJ <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></span>
                                    <input
                                        type="text"
                                        id="cnpj"
                                        name="cnpj"
                                        value="{{ old('cnpj') }}"
                                        class="form-control @error('cnpj') is-invalid @enderror"
                                        inputmode="numeric"
                                        maxlength="18"
                                        placeholder="CPF ou CNPJ"
                                        @error('cnpj') aria-describedby="cnpj-error" @enderror
                                        required
                                    >
                                    @error('cnpj')
                                        <div id="cnpj-error" class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="company-departments" class="company-form-section" aria-labelledby="company-departments-title" tabindex="-1">
                    <div class="card-body p-3 p-md-4">
                        <div class="company-form-section__heading">
                            <span class="company-form-section__number">3</span>
                            <div>
                                <div class="company-departments-title">
                                    <h2 id="company-departments-title" class="h5 fw-bold mb-0">Emails por setor</h2>
                                    <span class="company-optional-badge">Opcional</span>
                                </div>
                                <div class="company-departments-toggle">
                                    <input class="form-check-input" type="checkbox" id="use-department-emails" name="_use_department_emails" value="1"
                                        aria-controls="company-department-fields" aria-describedby="company-departments-help" @checked($departmentsEnabled)>
                                    <div>
                                        <label for="use-department-emails" class="fw-semibold">Cadastrar emails por setor</label>
                                        <p id="company-departments-help" class="form-text mb-0">Ative para informar contatos específicos. Todos os emails de setores são opcionais.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @error('setores')
                            <p class="text-danger small mb-0" role="alert">{{ $message }}</p>
                        @enderror
                        <fieldset id="company-department-fields" class="company-department-fields" @disabled(! $departmentsEnabled) @if (! $departmentsEnabled) hidden @endif>
                            <legend class="visually-hidden">Contatos por setor da imobiliária</legend>
                            <div class="company-department-grid" data-department-list>
                                @foreach ($departmentRows as $index => $sector)
                                    @php
                                        $errorPrefix = $sector['error_index'] !== false ? 'setores.'.$sector['error_index'] : null;
                                        $nameError = $errorPrefix ? $errors->first($errorPrefix.'.name') : '';
                                        $emailError = $errorPrefix ? $errors->first($errorPrefix.'.email') : '';
                                        $keyError = $errorPrefix ? ($errors->first($errorPrefix.'.key') ?: $errors->first($errorPrefix)) : '';
                                    @endphp
                                    <div class="company-department-row {{ $sector['custom'] ? 'company-department-row--custom' : '' }}" data-department-row data-custom="{{ $sector['custom'] ? 'true' : 'false' }}">
                                        <input type="hidden" name="setores[{{ $index }}][key]" value="{{ $sector['key'] }}" data-department-key>
                                        @if ($sector['custom'])
                                            <div class="company-department-custom-heading">
                                                <label for="department-name-{{ $index }}" class="form-label fw-semibold">Nome do setor <span class="text-danger" aria-hidden="true">*</span></label>
                                                <button type="button" class="company-department-remove" data-remove-department aria-label="Remover setor {{ $sector['name'] }}" title="Remover setor"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                            </div>
                                            <input type="text" id="department-name-{{ $index }}" name="setores[{{ $index }}][name]" value="{{ $sector['name'] }}" class="form-control {{ $nameError ? 'is-invalid' : '' }}" maxlength="150" placeholder="Ex.: Vistorias" data-department-name required @if ($nameError) aria-invalid="true" aria-describedby="department-name-error-{{ $index }}" @endif>
                                            @if ($nameError)<div id="department-name-error-{{ $index }}" class="invalid-feedback">{{ $nameError }}</div>@endif
                                        @else
                                            <input type="hidden" name="setores[{{ $index }}][name]" value="{{ $sector['name'] }}" data-department-name>
                                        @endif
                                        <label for="department-email-{{ $index }}" class="form-label fw-semibold">{{ $sector['custom'] ? 'E-mail do setor' : $sector['name'] }}</label>
                                        <div class="input-group">
                                            <span class="input-group-text" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                                            <input type="email" id="department-email-{{ $index }}" name="setores[{{ $index }}][email]" value="{{ $sector['email'] }}" class="form-control {{ $emailError ? 'is-invalid' : '' }}" maxlength="255" placeholder="{{ $sector['placeholder'] }}" autocomplete="off" data-department-email @if ($emailError) aria-invalid="true" aria-describedby="department-email-error-{{ $index }}" @endif>
                                            @if ($emailError)<div id="department-email-error-{{ $index }}" class="invalid-feedback">{{ $emailError }}</div>@endif
                                        </div>
                                        @if ($keyError)<p class="text-danger small mb-0" role="alert">{{ $keyError }}</p>@endif
                                    </div>
                                @endforeach
                            </div>
                            <div class="company-department-add-area">
                                <button type="button" class="btn btn-outline-primary" data-add-department><i class="bi bi-plus-lg" aria-hidden="true"></i> Adicionar setor</button>
                                <p class="form-text mb-0">Crie um setor personalizado e informe seu email.</p>
                            </div>
                        </fieldset>
                        <p class="visually-hidden" data-department-feedback role="status" aria-live="polite"></p>
                        <noscript><p class="form-text">Ative o JavaScript do navegador para cadastrar emails por setor.</p></noscript>
                    </div>
                </section>

                <section id="company-address" class="company-form-section" aria-labelledby="company-address-title" tabindex="-1">
                    <div class="card-body p-3 p-md-4">
                        <div class="company-form-section__heading">
                            <span class="company-form-section__number">4</span>
                            <div>
                                <h2 id="company-address-title" class="h5 fw-bold mb-1">Localização</h2>
                                <p class="text-muted small mb-0">A cidade e a UF serão preenchidas pelo serviço de CEP do sistema.</p>
                            </div>
                        </div>

                        <div class="row g-3 g-lg-4">
                            <div class="col-12 col-md-4">
                                <label for="cep" class="form-label fw-semibold">
                                    CEP <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-geo-alt"></i></span>
                                    <input
                                        type="text"
                                        id="cep"
                                        name="cep"
                                        value="{{ old('cep') }}"
                                        class="form-control @error('cep') is-invalid @enderror"
                                        inputmode="numeric"
                                        maxlength="9"
                                        placeholder="00000-000"
                                        autocomplete="postal-code"
                                        aria-describedby="cep-feedback @error('cep') cep-error @enderror"
                                        required
                                    >
                                    @error('cep')
                                        <div id="cep-error" class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div id="cep-feedback" class="form-text" aria-live="polite">
                                    Digite os 8 números do CEP.
                                </div>
                            </div>

                            <div class="col-12 col-md-5">
                                <label for="city" class="form-label fw-semibold">
                                    Cidade <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="city"
                                    name="city"
                                    value="{{ old('city') }}"
                                    class="form-control @error('city') is-invalid @enderror"
                                    maxlength="100"
                                    placeholder="Preenchida pelo CEP"
                                    autocomplete="address-level2"
                                    @error('city') aria-describedby="city-error" @enderror
                                    readonly
                                    required
                                >
                                @error('city')
                                    <div id="city-error" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-3">
                                <label for="state" class="form-label fw-semibold">
                                    UF <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="state"
                                    name="state"
                                    value="{{ old('state') }}"
                                    class="form-control text-uppercase @error('state') is-invalid @enderror"
                                    maxlength="2"
                                    placeholder="UF"
                                    autocomplete="address-level1"
                                    @error('state') aria-describedby="state-error" @enderror
                                    readonly
                                    required
                                >
                                @error('state')
                                    <div id="state-error" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section id="company-access" class="company-form-section" aria-labelledby="company-access-title" tabindex="-1">
                    <div class="card-body p-3 p-md-4">
                        <div class="company-form-section__heading">
                            <span class="company-form-section__number">5</span>
                            <div>
                                <h2 id="company-access-title" class="h5 fw-bold mb-1">Acesso e disponibilidade</h2>
                                <p class="text-muted small mb-0">Crie a senha inicial e defina se o formulário começa ativo.</p>
                            </div>
                        </div>

                        <div class="company-credentials-note mb-4" role="note">
                            <i class="bi bi-shield-lock" aria-hidden="true"></i>
                            <div>
                                <strong>Entrega segura das credenciais</strong>
                                <p class="mb-0">
                                    O corretor é responsável por entregar o e-mail e a senha diretamente à imobiliária.
                                    A senha não será exibida novamente após o cadastro.
                                </p>
                            </div>
                        </div>

                        <div class="row g-3 g-lg-4">
                            <div class="col-12 col-lg-6">
                                <label for="password" class="form-label fw-semibold">
                                    Senha inicial <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
                                    <input
                                        type="password"
                                        id="password"
                                        name="password"
                                        class="form-control @error('password') is-invalid @enderror"
                                        minlength="8"
                                        maxlength="72"
                                        placeholder="Mínimo de 8 caracteres"
                                        autocomplete="new-password"
                                        aria-describedby="password-help @error('password') password-error @enderror"
                                        required
                                    >
                                    <button
                                        type="button"
                                        class="btn company-password-toggle"
                                        data-toggle-password="password"
                                        aria-label="Mostrar senha"
                                        aria-pressed="false"
                                        title="Mostrar senha"
                                    >
                                        <i class="bi bi-eye" data-password-icon="show" aria-hidden="true"></i>
                                        <i class="bi bi-eye-slash" data-password-icon="hide" aria-hidden="true" hidden></i>
                                    </button>
                                    @error('password')
                                        <div id="password-error" class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div id="password-help" class="form-text">Use pelo menos uma letra e um número.</div>
                            </div>

                            <div class="col-12 col-lg-6">
                                <label for="password_confirmation" class="form-label fw-semibold">
                                    Confirmar senha <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock-fill"></i></span>
                                    <input
                                        type="password"
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        class="form-control"
                                        minlength="8"
                                        maxlength="72"
                                        placeholder="Repita a senha"
                                        autocomplete="new-password"
                                        required
                                    >
                                    <button
                                        type="button"
                                        class="btn company-password-toggle"
                                        data-toggle-password="password_confirmation"
                                        aria-label="Mostrar confirmação de senha"
                                        aria-pressed="false"
                                        title="Mostrar confirmação de senha"
                                    >
                                        <i class="bi bi-eye" data-password-icon="show" aria-hidden="true"></i>
                                        <i class="bi bi-eye-slash" data-password-icon="hide" aria-hidden="true" hidden></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="company-status-control mt-4">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <label for="lead_form_active" class="fw-bold">Formulário ativo</label>
                                    <span
                                        class="company-live-status {{ (bool) old('lead_form_active', true) ? 'is-active' : 'is-inactive' }}"
                                        data-status-state
                                        aria-live="polite"
                                    >
                                        {{ (bool) old('lead_form_active', true) ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </div>
                                <p id="lead-form-status-help" class="text-muted small mb-0">
                                    Quando ativo, o código de acesso gerado poderá receber novos cadastros de leads.
                                </p>
                            </div>
                            <div class="form-check form-switch m-0">
                                <input type="hidden" name="lead_form_active" value="0">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    id="lead_form_active"
                                    name="lead_form_active"
                                    value="1"
                                    aria-describedby="lead-form-status-help"
                                    @checked((bool) old('lead_form_active', true))
                                >
                            </div>
                        </div>
                    </div>
                </section>

                </div>
                <div class="company-form-actions">
                    <a href="{{ $indexRoute }}" class="btn btn-outline-secondary btn-lg">Cancelar</a>
                    <button type="submit" class="btn btn-primary btn-lg" data-company-submit>
                        <span class="spinner-border spinner-border-sm me-2" data-submit-spinner aria-hidden="true" hidden></span>
                        <span data-submit-label>Cadastrar imobiliária</span>
                    </button>
                </div>
            </form>
            </div>
            <template id="company-department-template">
                <div class="company-department-row company-department-row--custom" data-department-row data-custom="true">
                    <input type="hidden" data-department-key>
                    <div class="company-department-custom-heading">
                        <label class="form-label fw-semibold" data-department-name-label>Nome do setor <span class="text-danger" aria-hidden="true">*</span></label>
                        <button type="button" class="company-department-remove" data-remove-department aria-label="Remover setor personalizado" title="Remover setor"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                    </div>
                    <input type="text" class="form-control" maxlength="150" placeholder="Ex.: Vistorias" data-department-name required>
                    <label class="form-label fw-semibold" data-department-email-label>E-mail do setor</label>
                    <div class="input-group">
                        <span class="input-group-text" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" maxlength="255" placeholder="setor@imobiliaria.com.br" autocomplete="off" data-department-email>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
@endsection
