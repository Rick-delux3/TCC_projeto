@extends('layout-inicial.simulation')

@section('content')

<div class="container py-5">
    <div class="card shadow-sm border-0 rounded-4 mx-auto text-center" style="max-width: 620px;">
        <div class="card-body p-5">
            <h1 class="h4 fw-bold mb-3">Solicitação enviada com sucesso!</h1>

            <p class="text-muted">
                Recebemos suas informações. Em breve, nossa equipe entrará em contato para dar continuidade à análise.
            </p>

            <a href="{{ route('index') }}" class="btn btn-primary mt-3">
                Voltar para o início
            </a>
        </div>
    </div>
</div>

@endsection