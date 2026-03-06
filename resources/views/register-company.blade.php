@extends('layout-inicial.app')

@section('content')
<div class="client-register-page">
    <section class="client-register-shell">
        <aside class="client-register-aside">
            <img src="{{ asset('imgs/seguro-fianca-locaticia_fundo_login_cadastro.png') }}" alt="Cadastro de clientes">
            <div class="client-register-overlay">
                <span class="client-badge">Cadastro de Clientes</span>
                <h2>Crie o acesso da sua Empresa</h2>
                <p>
                    Cadastre sua empresa para iniciar as analises de seguro
                    com fluxo digital e acompanhamento centralizado.
                </p>
                <ul>
                    <li>Processo rapido de onboarding.</li>
                    <li>Painel para envio e consulta de analises.</li>
                    <li>Integracao com operação da corretora.</li>
                </ul>
            </div>
        </aside>

        <div class="client-register-card">
            <header class="client-register-header">
                <h1>Cadastro da Empresa</h1>
                <p>Preencha os dados da empresa para liberar o acesso da sua equipe.</p>
            </header>

            <form action="{{ route('empresa.register.post') }}" method="POST" autocomplete="off" class="client-register-form">
                @csrf

                <div class="client-field">
                    <label for="name" class="client-label">Nome da Empresa</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        class="client-input"
                        value="{{ old('name') }}"
                        placeholder="Ex.: Nova Casa Imoveis"
                        required
                    >
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
                            placeholder="(00) 00000-0000"
                            required
                        >
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
                            required
                        >
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
                    </div>

                    <div class="client-field">
                        <label for="state" class="client-label">Estado (UF)</label>
                        <select name="state" id="state" class="client-input" required>
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
                    </div>
                </div>

                <div class="client-grid">
                    <div class="client-field">
                        <label for="password" class="client-label">Senha</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="client-input"
                            placeholder="Minimo de 6 caracteres"
                            required
                        >
                    </div>

                    <div class="client-field">
                        <label for="password_confirmation" class="client-label">Confirmar senha</label>
                        <input
                            type="password"
                            id="password_confirmation"
                            name="password_confirmation"
                            class="client-input"
                            placeholder="Repita a senha"
                            required
                        >
                    </div>
                </div>

                <div class="client-actions">
                    <button type="submit" class="client-submit">Cadastrar Empresa</button>
                    <a href="{{ route('empresa.login') }}" class="client-outline-link">Entrar</a>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
