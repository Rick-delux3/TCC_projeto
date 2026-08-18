@extends('layout-inicial.simulation')

@section('content')

<div class="container simulation-page py-4 py-lg-5">
    <div class="simulation-panel simulation-panel--compact simulation-success mx-auto text-center" style="max-width: 620px;">
        <div class="simulation-panel__body p-4 p-md-5">
            <h1 class="simulation-panel__title h4 mb-3">Solicitação enviada com sucesso!</h1>

            <p class="text-muted">
                Recebemos suas informações. Em breve, nossa equipe entrará em contato para dar continuidade à análise.
            </p>

            <a href="{{ route('simulation.start') }}" class="btn simulation-btn simulation-btn--primary mt-3">
                Voltar para o início
            </a>
        </div>
    </div>
</div>

@endsection
