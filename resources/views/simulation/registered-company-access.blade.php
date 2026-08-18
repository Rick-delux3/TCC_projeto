@extends('layout-inicial.simulation')

@section('content')

<div class="container simulation-page py-4 py-lg-5">
    <div class="simulation-panel simulation-panel--compact mx-auto" style="max-width: 520px;">
        <div class="simulation-panel__body p-4 p-md-5">
            <h1 class="simulation-panel__title h4 mb-2">Acesso da imobiliária cadastrada</h1>

            <p class="text-muted">
                Digite a chave de acesso fornecida para sua imobiliária.
            </p>

            @if ($errors->any())
                <div class="alert alert-danger">
                    Verifique a chave informada e tente novamente.
                </div>
            @endif

            <form action="{{ route('simulation.registered-company.verify') }}" method="POST" class="simulation-form">
                @csrf

                <div class="mb-3">
                    <label for="lead_access_code" class="form-label">Chave de acesso</label>
                    <input
                        type="text"
                        name="lead_access_code"
                        id="lead_access_code"
                        class="form-control text-uppercase @error('lead_access_code') is-invalid @enderror"
                        value="{{ old('lead_access_code') }}"
                        placeholder="Ex: 8K2P7A"
                        maxlength="20"
                        required
                    >

                    @error('lead_access_code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn simulation-btn simulation-btn--primary w-100">
                    Acessar formulário
                </button>
            </form>
        </div>
    </div>
</div>

@endsection
