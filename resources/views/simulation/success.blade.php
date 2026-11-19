@extends('layout-inicial.simulation')

@section('content')

<x-simulation-completion>
    <h1 id="completion-title" class="simulation-panel__title h4 mb-3">Solicitação enviada com sucesso!</h1>

    <p class="text-muted">
        @if (config('branding.active') === 'tcc' && ! config('features.insurance_analysis.enabled', false))
            Recebemos suas informações para acompanhamento no CRM. O módulo de análise está desativado nesta versão; este envio não gera uma análise nem um e-mail de resultado.
        @else
            Recebemos suas informações. Em breve, você receberá um e-mail com o resultado do processo de análise. Caso seja necessário, entraremos em contato para solicitar informações adicionais.
        @endif
    </p>

    <a href="{{ route('simulation.start') }}" class="btn simulation-btn simulation-btn--primary mt-3">
        Voltar para o início
    </a>
</x-simulation-completion>

@endsection
