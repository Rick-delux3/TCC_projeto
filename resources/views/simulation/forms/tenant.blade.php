@extends('layout-inicial.simulation')

@section('content')

@php
    $isAdminSimulation = $isAdminSimulation ?? false;

    $formAction = $formAction ?? route('simulation.tenant.store');


@endphp

<div class="container simulation-page py-3 py-lg-5">
    <div class="simulation-panel simulation-form-panel mx-auto" style="max-width: 950px;">
        <div class="simulation-panel__body p-3 p-md-4 p-lg-5">

            <h2 class="simulation-panel__title mb-4">
                Cadastro exclusivo para pretendente à locação
            </h2>

            @include('simulation.partials.alerts')

            @if ($isAdminSimulation)
                <div class="alert alert-info">
                    <strong>Preenchimento interno:</strong>
                    esta solicitação será registrada como locatário sem vínculo
                    com imobiliária.
                </div>
            @endif

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

                    @include('simulation.partials.document')

                    <div class="col-md-6">
                        <label class="form-label">Telefone <span class="text-danger">*</span></label>
                        <input type="text" name="tel" class="form-control" value="{{ old('tel') }}" placeholder="Ex: 11997285152">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">E-mail <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" value="{{ old('email') }}" placeholder="email@exemplo.com">
                    </div>

                    @include('simulation.partials.marital-status')

                    @include('simulation.partials.property-expenses-address')
                </div>

                @include('simulation.partials.consent-checkbox')

                <button type="submit" class="btn simulation-btn simulation-btn--accent w-100 mt-4">
                    ENVIAR
                </button>
            </form>
        </div>
    </div>
</div>

@endsection
