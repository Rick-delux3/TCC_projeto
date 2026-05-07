@extends('layout-inicial.simulation')

@section('content')

<div class="container py-5">
    <div class="text-center mb-5">
        <h1 class="fw-bold">Simular Seguro</h1>
        <p class="text-muted">
            Escolha abaixo o perfil que melhor representa você.
        </p>
    </div>

    <form action="{{ route('simulation.profile') }}" method="POST">
        @csrf

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <button type="submit" name="tipo_solicitante" value="imobiliaria_cadastrada"
                    class="card border-0 shadow-sm rounded-4 w-100 h-100 p-4 text-start">
                    <h5 class="fw-bold">Imobiliária cadastrada</h5>
                    <p class="text-muted mb-0">Tenho uma chave de acesso.</p>
                </button>
            </div>

            <div class="col-md-6 col-lg-3">
                <button type="submit" name="tipo_solicitante" value="imobiliaria_nao_cadastrada"
                    class="card border-0 shadow-sm rounded-4 w-100 h-100 p-4 text-start">
                    <h5 class="fw-bold">Imobiliária não cadastrada</h5>
                    <p class="text-muted mb-0">Quero solicitar análise ou parceria.</p>
                </button>
            </div>

            <div class="col-md-6 col-lg-3">
                <button type="submit" name="tipo_solicitante" value="locatario"
                    class="card border-0 shadow-sm rounded-4 w-100 h-100 p-4 text-start">
                    <h5 class="fw-bold">Locatário</h5>
                    <p class="text-muted mb-0">Quero alugar um imóvel.</p>
                </button>
            </div>

            <div class="col-md-6 col-lg-3">
                <button type="submit" name="tipo_solicitante" value="locador"
                    class="card border-0 shadow-sm rounded-4 w-100 h-100 p-4 text-start">
                    <h5 class="fw-bold">Locador</h5>
                    <p class="text-muted mb-0">Sou proprietário/locador.</p>
                </button>
            </div>
        </div>
    </form>
</div>

@endsection