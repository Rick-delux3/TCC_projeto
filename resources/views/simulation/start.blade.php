@extends('layout-inicial.simulation')

@section('content')
@php
    $simulationBrandProfile = config('branding.active', 'tcc');
    $simulationBrandName = config("branding.profiles.{$simulationBrandProfile}.name", 'NVS Seguros');
@endphp

<div class="simulation-page simulation-start" data-simulation-start>
    <div class="simulation-start__shell">
        <nav class="simulation-progress" aria-label="Progresso da solicitação">
            <ol>
                <li class="simulation-progress__step is-active" aria-current="step">
                    <span class="simulation-progress__number">1</span>
                    <strong>Escolha seu perfil</strong>
                </li>

                <li class="simulation-progress__connector" aria-hidden="true"></li>

                <li class="simulation-progress__step">
                    <span class="simulation-progress__number">2</span>
                    <span>Dados da solicitação</span>
                </li>
            </ol>
        </nav>

        <section class="simulation-journey" aria-labelledby="simulation-title">
            <aside class="simulation-journey__aside">
                <div class="simulation-journey__intro">
                    <span class="simulation-eyebrow">Seguro fiança locatícia</span>
                    <h1 id="simulation-title" class="simulation-title">Como podemos ajudar?</h1>
                    <p>Escolha o perfil que melhor representa esta solicitação.</p>
                </div>

                <ul class="simulation-journey__benefits" role="list">
                    <li>
                        <span class="simulation-journey__benefit-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="9"></circle>
                                <path d="M12 7v5l3 2"></path>
                            </svg>
                        </span>
                        <span>Leva menos de 1 minuto</span>
                    </li>

                    <li>
                        <span class="simulation-journey__benefit-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 3 4.5 6v5.2c0 4.5 3.1 8.5 7.5 9.8 4.4-1.3 7.5-5.3 7.5-9.8V6L12 3Z"></path>
                                <path d="m8.8 12 2.1 2.1 4.5-4.6"></path>
                            </svg>
                        </span>
                        <span>Ambiente seguro</span>
                    </li>
                </ul>

                <svg class="simulation-journey__city" viewBox="0 0 500 240" fill="none" aria-hidden="true">
                    <path d="M8 224h484M28 224v-72h54v72M46 224v-46h56v46M71 171h9M71 188h9M71 205h9M105 224l85-64 82 64M133 224v-37h114v37M171 224v-31h37v31M178 181h22M214 181h10M286 224V96l79-42v170M312 121h14v17h-14zM340 121h14v17h-14zM312 150h14v17h-14zM340 150h14v17h-14zM312 179h14v17h-14zM340 179h14v17h-14zM366 224h103V120H366M400 150h38M400 179h38M400 207h38M28 152c0-14-10-25-22-25s-22 11-22 25 10 25 22 25v47M190 160l-24-18-74 56" stroke="currentColor" stroke-width="2"></path>
                </svg>
            </aside>

            <div class="simulation-journey__content">
                <header class="simulation-journey__content-header">
                    <h2 id="profile-choice-title">Qual opção descreve você?</h2>
                    <p id="profile-choice-help">Isso nos ajuda a preparar a próxima etapa.</p>
                </header>

                <form
                    action="{{ route('simulation.profile') }}"
                    method="POST"
                    class="simulation-journey__form"
                    id="profileChoiceForm"
                    aria-describedby="profile-choice-help profileChoiceStatus"
                >
                    @csrf

                    <fieldset>
                        <legend class="visually-hidden">Selecione uma opção para continuar</legend>

                        <div class="simulation-options">
                            <div class="simulation-option">
                                <input
                                    type="radio"
                                    name="tipo_solicitante"
                                    value="imobiliaria_cadastrada"
                                    id="profile-company"
                                    @checked(old('tipo_solicitante') === 'imobiliaria_cadastrada')
                                    required
                                >

                                <label for="profile-company" class="simulation-option__card">
                                    <span class="simulation-option__icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 21h18"></path>
                                            <path d="M5 21V6l7-3 7 3v15"></path>
                                            <path d="M9 9h.01M15 9h.01M9 13h.01M15 13h.01M10 21v-4h4v4"></path>
                                        </svg>
                                    </span>

                                    <span class="simulation-option__copy">
                                        <strong>Imobiliária cadastrada</strong>
                                        <span>Já tenho a chave de acesso para enviar uma nova análise.</span>
                                    </span>

                                    <span class="simulation-option__indicator" aria-hidden="true"></span>
                                </label>
                            </div>

                            <div class="simulation-option simulation-option--accent">
                                <input
                                    type="radio"
                                    name="tipo_solicitante"
                                    value="imobiliaria_nao_cadastrada"
                                    id="profile-independent"
                                    @checked(old('tipo_solicitante') === 'imobiliaria_nao_cadastrada')
                                >

                                <label for="profile-independent" class="simulation-option__card">
                                    <span class="simulation-option__icon simulation-option__icon--accent" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="m3 10 9-7 9 7"></path>
                                            <path d="M5 9v11h14V9"></path>
                                            <path d="M9 20v-6h6v6"></path>
                                        </svg>
                                    </span>

                                    <span class="simulation-option__copy">
                                        <strong>Imobiliária ou proprietário</strong>
                                        <span>Vou solicitar uma análise sem uma chave de acesso.</span>
                                        <small>Nova solicitação</small>
                                    </span>

                                    <span class="simulation-option__indicator" aria-hidden="true"></span>
                                </label>
                            </div>

                            <div class="simulation-option">
                                <input
                                    type="radio"
                                    name="tipo_solicitante"
                                    value="locatario"
                                    id="profile-tenant"
                                    @checked(old('tipo_solicitante') === 'locatario')
                                >

                                <label for="profile-tenant" class="simulation-option__card">
                                    <span class="simulation-option__icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="8" r="3.25"></circle>
                                            <path d="M5.5 21v-1.5a6.5 6.5 0 0 1 13 0V21"></path>
                                        </svg>
                                    </span>

                                    <span class="simulation-option__copy">
                                        <strong>Locatário</strong>
                                        <span>Quero alugar um imóvel e solicitar meu seguro fiança.</span>
                                    </span>

                                    <span class="simulation-option__indicator" aria-hidden="true"></span>
                                </label>
                            </div>
                        </div>
                    </fieldset>

                    <div class="simulation-journey__footer">
                        <div class="simulation-journey__review-note">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="9"></circle>
                                <path d="m8 12 2.6 2.6L16.5 9"></path>
                            </svg>
                            <p id="profileChoiceStatus" aria-live="polite">Você poderá revisar os dados antes de enviar.</p>
                        </div>

                        <button type="submit" class="btn simulation-journey__continue" id="profileChoiceSubmit">
                            <span>Continuar</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M5 12h14M14 7l5 5-5 5"></path>
                            </svg>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <footer class="simulation-start__footer" aria-label="Informações institucionais">
            <p><strong>{{ $simulationBrandName }}</strong><span aria-hidden="true">·</span>Portal Imobiliário</p>
            <p><span>Privacidade</span><i aria-hidden="true"></i><span>Termos</span></p>
        </footer>
    </div>
</div>
@endsection
