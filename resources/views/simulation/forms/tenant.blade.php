@extends('layout-inicial.simulation')

@section('content')

<div class="container py-5">
    <div class="card border-0 shadow-sm rounded-4 mx-auto" style="max-width: 950px;">
        <div class="card-body p-4">

            <h2 class="fw-bold mb-4">
                Cadastro exclusivo para pretendente à locação
            </h2>

            @include('simulation.partials.alerts')

            <form action="{{ route('simulation.tenant.store') }}" method="POST">
                @csrf

                @include('simulation.partials.honeypot')

                <div class="row g-3">
                    <div class="col-12">
                        <h5 class="fw-bold border-bottom pb-2">
                            Seus dados
                        </h5>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nome completo <span class="text-danger">*</span></label>
                        <input type="text" name="nome" class="form-control" value="{{ old('nome') }}" placeholder="Nome completo">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">CPF <span class="text-danger">*</span></label>
                        <input type="text" name="cpf" class="form-control" value="{{ old('cpf') }}" placeholder="Somente números">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Telefone <span class="text-danger">*</span></label>
                        <input type="text" name="tel" class="form-control" value="{{ old('tel') }}" placeholder="Ex: 11997285152">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">E-mail <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" value="{{ old('email') }}" placeholder="email@exemplo.com">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Estado civil</label>
                        <select name="estado_civil" class="form-select">
                            <option value="">Selecione</option>
                            <option value="solteiro" @selected(old('estado_civil') === 'solteiro')>Solteiro(a)</option>
                            <option value="casado" @selected(old('estado_civil') === 'casado')>Casado(a)</option>
                            <option value="uniao_estavel" @selected(old('estado_civil') === 'uniao_estavel')>União estável</option>
                            <option value="divorciado" @selected(old('estado_civil') === 'divorciado')>Divorciado(a)</option>
                            <option value="viuvo" @selected(old('estado_civil') === 'viuvo')>Viúvo(a)</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Nome do cônjuge</label>
                        <input type="text" name="conjuge_nome" class="form-control" value="{{ old('conjuge_nome') }}" placeholder="Se casado ou união estável">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">CPF do cônjuge</label>
                        <input type="text" name="conjuge_cpf" class="form-control" value="{{ old('conjuge_cpf') }}" placeholder="Se casado ou união estável">
                    </div>

                    @include('simulation.partials.property-expenses-address')
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