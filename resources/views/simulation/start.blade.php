@extends('layout-inicial.simulation')

@section('content')

<div class="container simulation-page simulation-start py-3 py-lg-5">
    <section class="simulation-journey" aria-labelledby="simulation-title">
        <div class="simulation-journey__glow simulation-journey__glow--one" aria-hidden="true"></div>
        <div class="simulation-journey__glow simulation-journey__glow--two" aria-hidden="true"></div>

        <header class="simulation-journey__header">
            <div>
                <span class="simulation-eyebrow">Seguro fiança locatícia</span>
                <h1 id="simulation-title" class="simulation-title">Como podemos ajudar?</h1>
                <p>Escolha o perfil que melhor representa esta solicitação. Leva menos de um minuto para começar.</p>
            </div>

            <div class="simulation-journey__step" aria-label="Etapa 1 de 2">
                <span>Etapa</span>
                <strong>1 <small>/ 2</small></strong>
                <i aria-hidden="true"></i>
            </div>
        </header>

        <form action="{{ route('simulation.profile') }}" method="POST" class="simulation-journey__form" id="profileChoiceForm">
            @csrf

            <fieldset>
                <legend>Selecione uma opção para continuar</legend>

                <div class="simulation-options">
                    <div class="simulation-option">
                        <input type="radio" name="tipo_solicitante" value="imobiliaria_cadastrada" id="profile-company" @checked(old('tipo_solicitante') === 'imobiliaria_cadastrada') required>
                        <label for="profile-company" class="simulation-option__card">
                            <span class="simulation-option__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 21h18"/><path d="M5 21V6l7-3 7 3v15"/><path d="M9 9h.01M15 9h.01M9 13h.01M15 13h.01M10 21v-4h4v4"/>
                                </svg>
                            </span>
                            <span class="simulation-option__copy">
                                <strong>Imobiliária cadastrada</strong>
                                <span>Já tenho a chave de acesso para enviar uma nova análise.</span>
                            </span>
                            <span class="simulation-option__meta">Tenho uma chave <b aria-hidden="true"></b></span>
                        </label>
                    </div>

                    <div class="simulation-option">
                        <input type="radio" name="tipo_solicitante" value="imobiliaria_nao_cadastrada" id="profile-independent" @checked(old('tipo_solicitante') === 'imobiliaria_nao_cadastrada')>
                        <label for="profile-independent" class="simulation-option__card">
                            <span class="simulation-option__icon simulation-option__icon--pink" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m3 10 9-7 9 7"/><path d="M5 9v11h14V9"/><path d="M9 20v-6h6v6"/>
                                </svg>
                            </span>
                            <span class="simulation-option__copy">
                                <strong>Imobiliária ou proprietário</strong>
                                <span>Vou solicitar uma análise sem uma chave de acesso.</span>
                            </span>
                            <span class="simulation-option__meta">Nova solicitação <b aria-hidden="true"></b></span>
                        </label>
                    </div>

                    <div class="simulation-option">
                        <input type="radio" name="tipo_solicitante" value="locatario" id="profile-tenant" @checked(old('tipo_solicitante') === 'locatario')>
                        <label for="profile-tenant" class="simulation-option__card">
                            <span class="simulation-option__icon simulation-option__icon--sky" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="8" r="3.25"/><path d="M5.5 21v-1.5a6.5 6.5 0 0 1 13 0V21"/><path d="M19 5.5h2M20 4.5v2"/>
                                </svg>
                            </span>
                            <span class="simulation-option__copy">
                                <strong>Locatário</strong>
                                <span>Quero alugar um imóvel e solicitar meu seguro fiança.</span>
                            </span>
                            <span class="simulation-option__meta">Quero alugar <b aria-hidden="true"></b></span>
                        </label>
                    </div>
                </div>
            </fieldset>

            <div class="simulation-journey__footer">
                <p id="profileChoiceStatus" aria-live="polite">Selecione seu perfil para avançar.</p>
                <button type="submit" class="btn simulation-journey__continue" id="profileChoiceSubmit">
                    Continuar <span aria-hidden="true"></span>
                </button>
            </div>
        </form>
    </section>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('profileChoiceForm');
    const status = document.getElementById('profileChoiceStatus');
    const submit = document.getElementById('profileChoiceSubmit');

    if (!form || !status || !submit) {
        return;
    }

    form.querySelectorAll('input[name="tipo_solicitante"]').forEach(function (input) {
        input.addEventListener('change', function () {
            const option = input.closest('.simulation-option');
            const title = option ? option.querySelector('strong')?.textContent : '';

            status.textContent = title ? `${title} selecionado. Você já pode continuar.` : 'Selecione seu perfil para avançar.';
            submit.classList.add('is-ready');
        });
    });
});
</script>
@endpush

@endsection
