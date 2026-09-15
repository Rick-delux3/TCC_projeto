@extends('layout-inicial.simulation')

@section('inline-feedback', 'true')
@section('content')
@include('simulation.partials.company-access-style')

<div class="company-access-page">
    <div class="company-access-layout">
        <aside class="company-access-art" aria-hidden="true">
            <img src="{{ asset('imgs/acesso-chave.png') }}" alt="" width="1024" height="1536" decoding="async">
        </aside>
    <div class="company-access-card">
        <div class="flex flex-col gap-6">
            <h1>Acesso da imobiliária cadastrada</h1>

            <p>
                Digite a chave de acesso fornecida para sua imobiliária.
            </p>

            <form action="{{ route('simulation.registered-company.verify') }}" method="POST" class="flex flex-col gap-6" data-company-access-form>
                @csrf

                <div class="flex flex-col gap-2">
                    <label for="lead_access_code">Chave de acesso</label>
                    <input
                        type="text"
                        name="lead_access_code"
                        id="lead_access_code"
                        class="uppercase"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        aria-invalid="{{ $errors->has('lead_access_code') ? 'true' : 'false' }}"
                        @error('lead_access_code') aria-describedby="code-error" @enderror
                        value="{{ old('lead_access_code') }}"
                        placeholder="Ex: 8K2P7A"
                        maxlength="20"
                        required
                    >

                    @error('lead_access_code')
                        <p id="code-error" class="access-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <a class="access-forgot" href="{{ route('simulation.registered-company.code.request') }}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M15 3a6 6 0 0 0-5 9L3 19v2h4v-3h3l3-3a6 6 0 1 0 2-12Z"/><circle cx="16" cy="8" r="1"/></svg>
                    Esqueci meu código
                </a>

                <button type="submit" class="access-button">
                    Acessar formulário
                </button>
            </form>
        </div>
    </div>
</div>

</div>
@endsection
