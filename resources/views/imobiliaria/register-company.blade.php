@extends('layout-inicial.app')

@section('content')
<div class="client-register-page">
    <section class="client-register-shell">
        <aside class="client-register-aside">
            <img src="{{ asset('imgs/seguro-fianca-locaticia_fundo_login_cadastro.png') }}" alt="Cadastro de imobiliária">
            <x-brand-logo class="auth-media-logo" />

            <div class="client-register-overlay">
                <span class="client-badge">Cadastro de imobiliária</span>
                <h2>Crie o acesso da sua imobiliária</h2>
                <p>
                    Cadastre sua imobiliária para iniciar as análises de seguro
                    com fluxo digital e acompanhamento centralizado.
                </p>

                <div class="client-register-points">
                    <span>Onboarding rápido da imobiliária</span>
                    <span>Painel para envio e consulta de análises</span>
                    <span>Integração com a operação da corretora</span>
                </div>
            </div>
        </aside>

        <div class="client-register-card">
            <header class="client-register-header">
                <span class="client-register-kicker">Novo acesso</span>
                <h1>Cadastro da imobiliária</h1>
                <p>Preencha os dados abaixo para liberar o acesso ao painel.</p>
            </header>

            <form action="{{ route('empresa.register.post') }}" method="POST" autocomplete="on" class="client-register-form">
                @csrf

                <div class="client-honeypot" aria-hidden="true">
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

                <div class="client-field">
                    <label
                        for="{{ $tagsOficiais->isNotEmpty() ? 'leadlovers_tag_id' : 'company_name' }}"
                        class="client-label"
                    >
                        Nome da imobiliária
                    </label>

                    @if($tagsOficiais->isNotEmpty())
                        <div class="client-input-wrap">
                            <span class="client-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M3 21h18M5 21V5l7-3 7 3v16M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/>
                                </svg>
                            </span>
                            <select
                                name="leadlovers_tag_id"
                                id="leadlovers_tag_id"
                                class="client-input client-input--with-icon client-select @error('leadlovers_tag_id') is-invalid @enderror"
                                @error('leadlovers_tag_id') aria-describedby="leadlovers-tag-error" @enderror
                                required
                            >
                                <option value="" disabled @selected(! old('leadlovers_tag_id'))>
                                    Selecione sua imobiliária...
                                </option>

                                @foreach($tagsOficiais as $tagNome)
                                    <option
                                        value="{{ $tagNome->leadlovers_tag_id }}"
                                        @selected(
                                            old('leadlovers_tag_id') == $tagNome->leadlovers_tag_id
                                        )
                                    >
                                        {{ $tagNome->title }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        @error('leadlovers_tag_id')
                            <span id="leadlovers-tag-error" class="client-field-error">{{ $message }}</span>
                        @enderror
                    @else
                        <div class="client-input-wrap">
                            <span class="client-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M3 21h18M5 21V5l7-3 7 3v16M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/>
                                </svg>
                            </span>
                            <input
                                type="text"
                                name="company_name"
                                id="company_name"
                                class="client-input client-input--with-icon @error('company_name') is-invalid @enderror"
                                value="{{ old('company_name') }}"
                                maxlength="255"
                                placeholder="Digite o nome da imobiliária"
                                autocomplete="organization"
                                @error('company_name') aria-describedby="company-name-error" @enderror
                                required
                            >
                        </div>

                        @error('company_name')
                            <span id="company-name-error" class="client-field-error">{{ $message }}</span>
                        @enderror
                    @endif
                </div>

                <div class="client-field">
                    <label for="cnpj" class="client-label">CNPJ da imobiliária</label>
                    <div class="client-input-wrap">
                        <span class="client-input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M6 2h9l5 5v15H6zM14 2v6h6M9 13h6M9 17h6"/>
                            </svg>
                        </span>
                        <input
                            type="text"
                            id="cnpj"
                            name="cnpj"
                            class="client-input client-input--with-icon @error('cnpj') is-invalid @enderror"
                            value="{{ old('cnpj') }}"
                            inputmode="numeric"
                            maxlength="18"
                            placeholder="00.000.000/0000-00"
                            @error('cnpj') aria-describedby="cnpj-error" @enderror
                            required
                        >
                    </div>
                    @error('cnpj')
                        <span id="cnpj-error" class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="client-grid">
                    <div class="client-field">
                        <label for="phone" class="client-label">Telefone</label>
                        <div class="client-input-wrap">
                            <span class="client-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2z"/>
                                </svg>
                            </span>
                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                class="client-input client-input--with-icon @error('phone') is-invalid @enderror"
                                value="{{ old('phone') }}"
                                inputmode="numeric"
                                autocomplete="tel"
                                maxlength="15"
                                placeholder="(00) 00000-0000"
                                @error('phone') aria-describedby="phone-error" @enderror
                                required
                            >
                        </div>
                        @error('phone')
                            <span id="phone-error" class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="client-field">
                        <label for="email" class="client-label">E-mail de acesso</label>
                        <div class="client-input-wrap">
                            <span class="client-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/>
                                    <path d="m22 7-10 6L2 7"/>
                                </svg>
                            </span>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="client-input client-input--with-icon @error('email') is-invalid @enderror"
                                value="{{ old('email') }}"
                                placeholder="contato@imobiliaria.com.br"
                                autocomplete="username"
                                maxlength="255"
                                @error('email') aria-describedby="email-error" @enderror
                                required
                            >
                        </div>
                        @error('email')
                            <span id="email-error" class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="client-grid">
                    <div class="client-field">
                        <label for="cep" class="client-label">CEP</label>
                        <div class="client-input-wrap">
                            <span class="client-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0z"/>
                                    <circle cx="12" cy="10" r="2.5"/>
                                </svg>
                            </span>
                            <input
                                type="text"
                                id="cep"
                                name="cep"
                                class="client-input client-input--with-icon @error('cep') is-invalid @enderror"
                                value="{{ old('cep') }}"
                                inputmode="numeric"
                                autocomplete="postal-code"
                                maxlength="9"
                                placeholder="00000-000"
                                aria-describedby="cep-feedback @error('cep') cep-error @enderror"
                                required
                            >
                        </div>
                        <span
                            id="cep-feedback"
                            class="client-field-error"
                            aria-live="polite"
                            hidden
                        ></span>

                        @error('cep')
                            <span id="cep-error" class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="client-field">
                        <label for="city" class="client-label">Cidade</label>
                        <div class="client-input-wrap">
                            <span class="client-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M3 21h18M5 21V9l5-3v15M10 21V3l9 4v14M7 12h.01M7 16h.01M13 8h.01M16 9h.01M13 12h.01M16 13h.01M13 16h.01M16 17h.01"/>
                                </svg>
                            </span>
                            <input
                                type="text"
                                id="city"
                                name="city"
                                class="client-input client-input--with-icon @error('city') is-invalid @enderror"
                                value="{{ old('city') }}"
                                maxlength="100"
                                placeholder="Preenchida automaticamente"
                                autocomplete="address-level2"
                                @error('city') aria-describedby="city-error" @enderror
                                readonly
                                required
                            >
                        </div>

                        @error('city')
                            <span id="city-error" class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="client-field">
                    <label for="state" class="client-label">
                        Estado (UF)
                    </label>

                    <div class="client-input-wrap">
                        <span class="client-input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M4 6.5 9 4l6 2.5L20 4v13.5L15 20l-6-2.5L4 20zM9 4v13.5M15 6.5V20"/>
                            </svg>
                        </span>
                        <input
                            type="text"
                            id="state"
                            name="state"
                            class="client-input client-input--with-icon @error('state') is-invalid @enderror"
                            value="{{ old('state') }}"
                            maxlength="2"
                            placeholder="UF"
                            autocomplete="address-level1"
                            @error('state') aria-describedby="state-error" @enderror
                            readonly
                            required
                        >
                    </div>

                    @error('state')
                        <span id="state-error" class="client-field-error">
                            {{ $message }}
                        </span>
                    @enderror
                </div>



                <div class="client-grid">
                    <div class="client-field">
                        <label for="password" class="client-label">Senha</label>
                        <div class="password-input-wrap">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="client-input password-input"
                                placeholder="Crie uma senha segura"
                                autocomplete="new-password"
                                minlength="8"
                                maxlength="72"
                                aria-describedby="password-requirements @error('password') password-error @enderror"
                                required
                            >
                            <button
                                type="button"
                                class="password-toggle-button"
                                data-toggle-password="password"
                                aria-label="Mostrar senha"
                                aria-pressed="false"
                                title="Mostrar senha"
                            >
                                <svg data-password-icon="show" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg data-password-icon="hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" hidden>
                                    <path d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M9.9 4.2A10.8 10.8 0 0 1 12 4c6.5 0 10 8 10 8a18 18 0 0 1-2.1 3.2M6.6 6.6C3.6 8.5 2 12 2 12s3.5 8 10 8a10.8 10.8 0 0 0 4.1-.8"/>
                                </svg>
                            </button>
                        </div>
                        <span id="password-requirements" class="client-field-hint">Use de 8 a 72 caracteres, com letras e números.</span>
                        @error('password')
                            <span id="password-error" class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="client-field">
                        <label for="password_confirmation" class="client-label">Confirmar senha</label>
                        <div class="password-input-wrap">
                            <input
                                type="password"
                                id="password_confirmation"
                                name="password_confirmation"
                                class="client-input password-input"
                                placeholder="Repita a senha"
                                autocomplete="new-password"
                                minlength="8"
                                maxlength="72"
                                required
                            >
                            <button
                                type="button"
                                class="password-toggle-button"
                                data-toggle-password="password_confirmation"
                                aria-label="Mostrar confirmação de senha"
                                aria-pressed="false"
                                title="Mostrar confirmação de senha"
                            >
                                <svg data-password-icon="show" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg data-password-icon="hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" hidden>
                                    <path d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M9.9 4.2A10.8 10.8 0 0 1 12 4c6.5 0 10 8 10 8a18 18 0 0 1-2.1 3.2M6.6 6.6C3.6 8.5 2 12 2 12s3.5 8 10 8a10.8 10.8 0 0 0 4.1-.8"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="client-actions">
                    <button type="submit" class="client-submit">Cadastrar imobiliária</button>
                    <a href="{{ route('empresa.login') }}" class="client-outline-link">Entrar</a>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const cepInput = document.getElementById('cep');
            const cityInput = document.getElementById('city');
            const stateInput = document.getElementById('state');
            const feedback = document.getElementById('cep-feedback');

            if (!cepInput || !cityInput || !stateInput) {
                return;
            }

            const endpointTemplate = @json(
                route('cep.show', ['cep' => '00000000'])
            );

            let lastResolvedCep = null;

            function onlyNumbers(value) {
                return value.replace(/\D/g, '').slice(0, 8);
            }

            function formatCep(value) {
                const numbers = onlyNumbers(value);

                if (numbers.length > 5) {
                    return numbers.slice(0, 5)
                        + '-'
                        + numbers.slice(5);
                }

                return numbers;
            }

            function clearAddress() {
                cityInput.value = '';
                stateInput.value = '';
            }

            function clearError() {
                cepInput.classList.remove('is-invalid');
                feedback.textContent = '';
                feedback.hidden = true;
            }

            function showError(message) {
                cepInput.classList.add('is-invalid');
                feedback.textContent = message;
                feedback.hidden = false;
            }

            async function searchCep() {
                const cep = onlyNumbers(cepInput.value);

                if (cep.length !== 8) {
                    clearAddress();

                    if (cep.length > 0) {
                        showError('Informe um CEP com 8 dígitos.');
                    }

                    return;
                }

                if (
                    cep === lastResolvedCep
                    && cityInput.value
                    && stateInput.value
                ) {
                    return;
                }

                clearError();

                cityInput.value = 'Buscando...';
                stateInput.value = '...';

                try {
                    const endpoint = endpointTemplate.replace(
                        '00000000',
                        cep
                    );

                    const response = await fetch(endpoint, {
                        headers: {
                            'Accept': 'application/json',
                        },
                    });

                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        throw new Error(
                            result.message ?? 'CEP não encontrado.'
                        );
                    }

                    const city = String(
                        result.data?.cidade ?? ''
                    ).trim();

                    const state = String(
                        result.data?.estado ?? ''
                    ).trim().toUpperCase();

                    if (!city || !state) {
                        throw new Error(
                            'A consulta não retornou cidade e estado.'
                        );
                    }

                    cityInput.value = city;
                    stateInput.value = state;
                    lastResolvedCep = cep;
                } catch (error) {
                    clearAddress();
                    lastResolvedCep = null;

                    showError(
                        error.message
                            ?? 'Não foi possível consultar o CEP.'
                    );
                }
            }

            cepInput.value = formatCep(cepInput.value);

            cepInput.addEventListener('input', function () {
                cepInput.value = formatCep(cepInput.value);

                const cep = onlyNumbers(cepInput.value);

                if (cep !== lastResolvedCep) {
                    lastResolvedCep = null;
                    clearAddress();
                    clearError();
                }

                if (cep.length === 8) {
                    searchCep();
                }
            });

            cepInput.addEventListener('blur', searchCep);

            const initialCep = onlyNumbers(cepInput.value);

            if (
                initialCep.length === 8
                && (!cityInput.value || !stateInput.value)
            ) {
                searchCep();
            }
        });

    </script>
@endpush
