@extends('layout-inicial.simulation')

@section('content')

<div class="container py-5">
    <div class="card border-0 shadow-sm rounded-4 mx-auto" style="max-width: 950px;">
        <div class="card-body p-4">

            <h2 class="fw-bold mb-3">
                O E-mail e Telefone do locatário fazem parte da análise do risco.
            </h2>

            <p class="fw-bold">
                Preencher abaixo dados do Locatário - pretendente à locação.
            </p>

            <div class="alert alert-primary">
                <strong>Imobiliária:</strong> {{ $company->name }}
            </div>

            @include('simulation.partials.alerts')

            <form action="{{ route('simulation.registered-company.store', $company->lead_access_code) }}" method="POST">
                @csrf

                @include('simulation.partials.honeypot')

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nome</label>
                        <input type="text" name="nome" class="form-control" value="{{ old('nome') }}" placeholder="completo">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Seu e-mail</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email') }}" placeholder="EMAIL">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">CPF</label>
                        <input type="text" name="cpf" class="form-control" value="{{ old('cpf') }}" placeholder="CPF">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Telefone</label>
                        <input type="text" name="tel" class="form-control" value="{{ old('tel') }}" placeholder="Celular">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Apenas para casado</label>
                        <input type="text" name="conjuge_cpf" class="form-control" value="{{ old('conjuge_cpf') }}" placeholder="CPF do cônjuge">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Valor do aluguel</label>
                        <input type="text" name="valor_aluguel" class="form-control" value="{{ old('valor_aluguel') }}" placeholder="R$">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Outras despesas</label>
                        <input type="text" name="outras_despesas" class="form-control" value="{{ old('outras_despesas') }}" placeholder="Ex IPTU, CONDOMÍNIO OU GÁS SE SOUBER">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Cidade do imóvel pretendido</label>
                        <input type="text" name="cidade_imovel" class="form-control" value="{{ old('cidade_imovel') }}" placeholder="CEP ou CIDADE">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Imobiliária</label>
                        <input type="text" class="form-control" value="{{ $company->name }}" readonly>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nome do responsável pelo preenchimento</label>
                        <input type="text" name="responsavel_preenchimento" class="form-control" value="{{ old('responsavel_preenchimento') }}" placeholder="Nome">
                    </div>
                </div>

                @include('simulation.partials.consent-checkbox')

                <button type="submit" class="btn btn-danger w-100 mt-3">
                    ENVIAR
                </button>
            </form>
        </div>
    </div>
</div>

@endsection
