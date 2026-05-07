@extends('layout-inicial.simulation')

@section('content')

<div class="container py-5">
    <div class="card border-0 shadow-sm rounded-4 mx-auto" style="max-width: 950px;">
        <div class="card-body p-4">

            <h2 class="fw-bold mb-4">
                Solicitação por imobiliária não cadastrada
            </h2>

            @include('simulation.partials.alerts')

            <form action="{{ route('simulation.unregistered-company.store') }}" method="POST">
                @csrf

                @include('simulation.partials.honeypot')

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nome</label>
                        <input type="text" name="nome" class="form-control" value="{{ old('nome') }}" placeholder="completo">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email') }}" placeholder="EMAIL">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">CPF</label>
                        <input type="text" name="cpf" class="form-control" value="{{ old('cpf') }}" placeholder="CPF">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Telefone</label>
                        <input type="text" name="telefone" class="form-control" value="{{ old('telefone') }}" placeholder="Celular">
                    </div>

                    <div class="col-12">
                        <label class="form-label d-block">Estado civil</label>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="estado_civil" value="casado" id="estado_civil_casado">
                            <label class="form-check-label" for="estado_civil_casado">
                                CASADO ou residente com companheiro(a)
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="estado_civil" value="solteiro" id="estado_civil_solteiro">
                            <label class="form-check-label" for="estado_civil_solteiro">
                                Solteiro
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="estado_civil" value="divorciado" id="estado_civil_divorciado">
                            <label class="form-check-label" for="estado_civil_divorciado">
                                Separado/divorciado/viúvo
                            </label>
                        </div>
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
                        <input type="text" name="outras_despesas" class="form-control" value="{{ old('outras_despesas') }}" placeholder="Ex IPTU OU CONDOMÍNIO SE SOUBER">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Cidade do imóvel pretendido</label>
                        <input type="text" name="cidade_imovel" class="form-control" value="{{ old('cidade_imovel') }}" placeholder="CEP ou CIDADE">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Nome do responsável pelo preenchimento</label>
                        <input type="text" name="responsavel_preenchimento" class="form-control" value="{{ old('nome_responsavel') }}" placeholder="Nome da imobiliária">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Caso preenchido por Imobiliária/Proprietário</label>
                        <input type="text" name="observacoes" class="form-control" value="{{ old('observacoes') }}" placeholder="INFORMAR NOME E TELEFONE">
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