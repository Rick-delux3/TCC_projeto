{{-- 
  Página de Registro de Empresa/Imobiliária
  
  Formulário para cadastro de novas empresas no sistema.
  Coleta informações: nome (seleção de tags), telefone, email, cidade, estado e senha.
  As tags oficiais são passadas do controller.
  
  Dados enviados para: route('empresa.register.post')
  Layout: layout-inicial.app
--}}

@extends('layout-inicial.app')

@section('content')
{{-- Container principal da página de registro --}}
<div class="client-register-page">
    <section class="client-register-shell">
        <aside class="client-register-aside">
            <img src="{{ asset('imgs/seguro-fianca-locaticia_fundo_login_cadastro.png') }}" alt="Cadastro de imobiliaria">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="Logo NVS" class="auth-media-logo">

            <div class="client-register-overlay">
                <span class="client-badge">Cadastro de clientes</span>
                <h2>Crie o acesso da sua empresa</h2>
                <p>
                    Cadastre sua imobiliaria para iniciar as analises de seguro
                    com fluxo digital e acompanhamento centralizado.
                </p>

                <div class="client-register-points">
                    <span>Onboarding rapido da imobiliaria</span>
                    <span>Painel para envio e consulta de analises</span>
                    <span>Integracao com a operacao da corretora</span>
                </div>
            </div>
        </aside>

        <div class="client-register-card">
            <header class="client-register-header">
                <span class="client-register-kicker">Novo acesso</span>
                <h1>Cadastro da empresa</h1>
                <p>Preencha os dados abaixo para liberar o acesso da sua equipe.</p>
            </header>

            <form action="{{ route('empresa.register.post') }}" method="POST" autocomplete="off" class="client-register-form">
                @csrf

                <div class="client-field">
                    <label for="name" class="client-label">Nome da empresa</label>
                    <select name="name" id="name" class="client-input client-select" required>
                        <option value="" disabled selected>Selecione sua imobiliaria...</option>

                        @forelse($tagsOficiais as $tagNome)
                            <option value="{{ $tagNome }}">{{ $tagNome }}</option>
                        @empty
                            <option value="" disabled>Nenhuma tag encontrada no sistema</option>
                        @endforelse
                    </select>
                    @error('name')
                        <span class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="client-grid">
                    <div class="client-field">
                        <label for="phone" class="client-label">Telefone</label>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            class="client-input"
                            value="{{ old('phone') }}"
                            inputmode="numeric"
                            maxlength="15"
                            placeholder="(00) 00000-0000"
                            required
                        >
                        @error('phone')
                            <span class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="client-field">
                        <label for="email" class="client-label">E-mail de acesso</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="client-input"
                            value="{{ old('email') }}"
                            placeholder="contato@imobiliaria.com.br"
                            autocomplete="username"
                            required
                        >
                        @error('email')
                            <span class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="client-grid">
                    <div class="client-field">
                        <label for="city" class="client-label">Cidade</label>
                        <input
                            type="text"
                            id="city"
                            name="city"
                            class="client-input"
                            value="{{ old('city') }}"
                            placeholder="Cidade da matriz"
                            required
                        >
                        @error('city')
                            <span class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="client-field">
                        <label for="state" class="client-label">Estado (UF)</label>
                        <select name="state" id="state" class="client-input client-select" required>
                            <option value="" {{ old('state') ? '' : 'selected' }}>Selecione</option>
                            <option value="SP" {{ old('state') === 'SP' ? 'selected' : '' }}>SP</option>
                            <option value="AL" {{ old('state') === 'AL' ? 'selected' : '' }}>AL</option>
                            <option value="AP" {{ old('state') === 'AP' ? 'selected' : '' }}>AP</option>
                            <option value="AM" {{ old('state') === 'AM' ? 'selected' : '' }}>AM</option>
                            <option value="BA" {{ old('state') === 'BA' ? 'selected' : '' }}>BA</option>
                            <option value="CE" {{ old('state') === 'CE' ? 'selected' : '' }}>CE</option>
                            <option value="DF" {{ old('state') === 'DF' ? 'selected' : '' }}>DF</option>
                            <option value="ES" {{ old('state') === 'ES' ? 'selected' : '' }}>ES</option>
                            <option value="GO" {{ old('state') === 'GO' ? 'selected' : '' }}>GO</option>
                            <option value="MA" {{ old('state') === 'MA' ? 'selected' : '' }}>MA</option>
                            <option value="MT" {{ old('state') === 'MT' ? 'selected' : '' }}>MT</option>
                            <option value="MS" {{ old('state') === 'MS' ? 'selected' : '' }}>MS</option>
                            <option value="MG" {{ old('state') === 'MG' ? 'selected' : '' }}>MG</option>
                            <option value="PA" {{ old('state') === 'PA' ? 'selected' : '' }}>PA</option>
                            <option value="PB" {{ old('state') === 'PB' ? 'selected' : '' }}>PB</option>
                            <option value="PR" {{ old('state') === 'PR' ? 'selected' : '' }}>PR</option>
                            <option value="PE" {{ old('state') === 'PE' ? 'selected' : '' }}>PE</option>
                            <option value="PI" {{ old('state') === 'PI' ? 'selected' : '' }}>PI</option>
                            <option value="RJ" {{ old('state') === 'RJ' ? 'selected' : '' }}>RJ</option>
                            <option value="RN" {{ old('state') === 'RN' ? 'selected' : '' }}>RN</option>
                            <option value="RS" {{ old('state') === 'RS' ? 'selected' : '' }}>RS</option>
                            <option value="RR" {{ old('state') === 'RR' ? 'selected' : '' }}>RR</option>
                            <option value="RO" {{ old('state') === 'RO' ? 'selected' : '' }}>RO</option>
                            <option value="SC" {{ old('state') === 'SC' ? 'selected' : '' }}>SC</option>
                            <option value="SE" {{ old('state') === 'SE' ? 'selected' : '' }}>SE</option>
                            <option value="TO" {{ old('state') === 'TO' ? 'selected' : '' }}>TO</option>
                            <option value="AC" {{ old('state') === 'AC' ? 'selected' : '' }}>AC</option>
                        </select>
                        @error('state')
                            <span class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>
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
                                placeholder="Minimo de 6 caracteres"
                                autocomplete="new-password"
                                required
                            >
                            <button type="button" class="password-toggle-button" data-toggle-password="password" aria-label="Mostrar senha">Ver</button>
                        </div>
                        @error('password')
                            <span class="client-field-error">{{ $message }}</span>
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
                                required
                            >
                            <button type="button" class="password-toggle-button" data-toggle-password="password_confirmation" aria-label="Mostrar senha">Ver</button>
                        </div>
                    </div>
                </div>

                <div class="client-actions">
                    <button type="submit" class="client-submit">Cadastrar empresa</button>
                    <a href="{{ route('empresa.login') }}" class="client-outline-link">Entrar</a>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
