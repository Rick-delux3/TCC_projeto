@extends('layout-inicial.simulation')

@section('content')

@php
    $isAdminSimulation = $isAdminSimulation ?? false;
    $lockResponsavelTipo = $lockResponsavelTipo ?? false;

    $formAction = $formAction
        ?? route('simulation.unregistered-company.store');
@endphp

<div class="container simulation-page py-3 py-lg-5">
    <div class="row justify-content-center">
        <div class="col-12 col-xl-10">
            <div class="simulation-panel simulation-form-panel">
                <div class="simulation-panel__body p-3 p-md-4 p-lg-5">

            <h2 class="simulation-panel__title mb-4">
                Solicitação por imobiliária não cadastrada e Proprietário
            </h2>

            @include('simulation.partials.alerts')

            <form action="{{ $formAction }}" method="POST" class="simulation-form">
                @csrf

                @if ($isAdminSimulation && filled($adminSimulationChannel ?? null))
                    <input
                        type="hidden"
                        name="admin_simulation_channel"
                        value="{{ $adminSimulationChannel }}"
                    >
                @endif

                @include('simulation.partials.honeypot')

                <div class="row g-3 align-items-start">
                    <div class="col-12">
                        <h5 class="fw-bold border-bottom pb-2">
                            Dados do pretendente à locação
                        </h5>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nome completo <span class="text-danger">*</span></label>
                        <input type="text" name="nome" class="form-control" value="{{ old('nome') }}" placeholder="Nome completo">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">E-mail <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" value="{{ old('email') }}" placeholder="email@exemplo.com">
                    </div>

                    @include('simulation.partials.document')

                    <div class="col-md-6">
                        <label class="form-label">Telefone <span class="text-danger">*</span></label>
                        <input type="text" name="tel" class="form-control" value="{{ old('tel') }}" placeholder="Ex: 11997285152">
                    </div>

                    @include('simulation.partials.marital-status')

                    <div class="col-12 mt-3">
                        <h5 class="fw-bold border-bottom pb-2">
                            Dados do responsável pela solicitação
                        </h5>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">
                            Quem está solicitando? <span class="text-danger">*</span>
                        </label>
                        @if ($lockResponsavelTipo)
                            <input
                                type="hidden"
                                name="responsavel_tipo"
                                value="{{ $responsavelTipo }}"
                            >

                            <div class="alert alert-info mb-0">
                                <strong>Tipo selecionado:</strong>

                                {{ $responsavelTipo === 'locador'
                                    ? 'Proprietário / locador'
                                    : 'Imobiliária não cadastrada' }}
                            </div>
                        @else
                            <div class="d-flex flex-column flex-md-row gap-3">
                                <div class="form-check">
                                    <input 
                                        class="form-check-input" 
                                        type="radio" 
                                        name="responsavel_tipo" 
                                        id="responsavel_imobiliaria" 
                                        value="imobiliaria_nao_cadastrada"
                                        @checked(old('responsavel_tipo', $responsavelTipo ?? 'imobiliaria_nao_cadastrada') === 'imobiliaria_nao_cadastrada')
                                    >
                                    <label class="form-check-label" for="responsavel_imobiliaria">
                                        Imobiliária não cadastrada
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input 
                                        class="form-check-input" 
                                        type="radio" 
                                        name="responsavel_tipo" 
                                        id="responsavel_locador" 
                                        value="locador"
                                        @checked(old('responsavel_tipo', $responsavelTipo ?? null) === 'locador')
                                    >
                                    <label class="form-check-label" for="responsavel_locador">
                                        Proprietário / locador
                                    </label>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="col-md-12">
                        <label class="form-label">Nome do responsável</label>
                        <input type="text" name="responsavel_nome" class="form-control" value="{{ old('responsavel_nome') }}" placeholder="Nome do responsável">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Email do responsável</label>
                        <input type="text" name="responsavel_email" class="form-control" value="{{ old('responsavel_email') }}" placeholder="Email do responsável">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Telefone do responsável</label>
                        <input type="text" name="responsavel_telefone" class="form-control" value="{{ old('responsavel_telefone') }}" placeholder="Telefone do responsável">
                    </div>

                    @include('simulation.partials.property-expenses-address')
                </div>

                @include('simulation.partials.consent-checkbox')

                <div class="d-grid mt-4">
                    <button type="submit" class="btn simulation-btn simulation-btn--accent">
                        ENVIAR
                    </button>
                </div>
            </form>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
