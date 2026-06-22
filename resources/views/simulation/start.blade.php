@extends('layout-inicial.simulation')

@section('content')

<div class="container py-5">
    <div class="text-center mb-5">
        <h1 class="fw-bold">Simular Seguro</h1>
        <p class="text-muted mb-0">
            Escolha abaixo o perfil que melhor representa sua solicitação.
        </p>
    </div>

    <form action="{{ route('simulation.profile') }}" method="POST">
        @csrf

        <div class="row g-4 justify-content-center">

            <div class="col-md-6 col-lg-4">
                <button 
                    type="submit" 
                    name="tipo_solicitante" 
                    value="imobiliaria_cadastrada"
                    class="card border-0 shadow-sm rounded-4 w-100 h-100 p-4 text-start btn btn-light"
                >
                    <div class="card-body p-0">
                        <h5 class="fw-bold mb-2">Imobiliária cadastrada</h5>
                        <p class="text-muted mb-3">
                            Já possuo uma chave de acesso para solicitar análises.
                        </p>

                        <span class="badge text-bg-danger">
                            Acessar com chave
                        </span>
                    </div>
                </button>
            </div>

            <div class="col-md-6 col-lg-4">
                <button 
                    type="submit" 
                    name="tipo_solicitante" 
                    value="imobiliaria_nao_cadastrada"
                    class="card border-0 shadow-sm rounded-4 w-100 h-100 p-4 text-start btn btn-light"
                >
                    <div class="card-body p-0">
                        <h5 class="fw-bold mb-2">
                            Imobiliária não cadastrada ou proprietário
                        </h5>
                        <p class="text-muted mb-3">
                            Solicite uma análise informando os dados do pretendente à locação.
                        </p>

                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge text-bg-secondary">
                                Imobiliária
                            </span>

                            <span class="badge text-bg-secondary">
                                Proprietário / locador
                            </span>
                        </div>
                    </div>
                </button>
            </div>

            <div class="col-md-6 col-lg-4">
                <button 
                    type="submit" 
                    name="tipo_solicitante" 
                    value="locatario"
                    class="card border-0 shadow-sm rounded-4 w-100 h-100 p-4 text-start btn btn-light"
                >
                    <div class="card-body p-0">
                        <h5 class="fw-bold mb-2">Locatário</h5>
                        <p class="text-muted mb-3">
                            Quero alugar um imóvel e solicitar uma análise de seguro fiança.
                        </p>

                        <span class="badge text-bg-danger">
                            Solicitar análise
                        </span>
                    </div>
                </button>
            </div>

        </div>
    </form>
</div>

@endsection