@extends('layout-inicial.simulation')

@section('content')

<div class="container py-5">
    <div class="card shadow-sm border-0 rounded-4 mx-auto" style="max-width: 520px;">
        <div class="card-body p-4 p-md-5">
            <h1 class="h4 fw-bold mb-2">Acesso da imobiliária cadastrada</h1>

            <p class="text-muted">
                Digite a chave de acesso fornecida para sua imobiliária.
            </p>

            @if ($errors->any())
                <div class="alert alert-danger">
                    Verifique a chave informada e tente novamente.
                </div>
            @endif

            <form action="{{ route('simulation.registered-company.verify') }}" method="POST">
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

                <button type="submit" class="btn btn-primary w-100">
                    Acessar formulário
                </button>
            </form>
        </div>
    </div>
</div>

@endsection