@extends('layout-inicial.simulation')

@section('hide-header', 'true')
@section('main-class', 'registered-access-main')
@section('inline-feedback', 'true')

@section('content')
    <x-company-access-panel illustration="imgs/seguro-aluguel-acesso.webp" title-id="registered-access-title">
        <div class="registered-access__intro">
            <p class="registered-access__context">Simulação de seguro aluguel</p>
            <h1 id="registered-access-title">
                {{ \App\Support\BrandingText::choose('Acesso da imobiliária', 'Acesso da empresa') }}
            </h1>
            <p>
                {{ \App\Support\BrandingText::choose(
                    'Use a chave da sua imobiliária para abrir o formulário de simulação.',
                    'Use a chave da sua empresa para abrir o formulário de simulação.',
                ) }}
            </p>
        </div>

        <form action="{{ route('simulation.registered-company.verify') }}" method="POST" class="registered-access__form" data-company-access-form>
            @csrf

            <div class="flex flex-col gap-2">
                <label for="lead_access_code">Chave de acesso</label>
                <input
                    type="text"
                    name="lead_access_code"
                    id="lead_access_code"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    aria-invalid="{{ $errors->has('lead_access_code') ? 'true' : 'false' }}"
                    @error('lead_access_code') aria-describedby="code-error" @enderror
                    value="{{ is_scalar(old('lead_access_code')) ? old('lead_access_code') : '' }}"
                    placeholder="Ex. 8K2P7A"
                    maxlength="20"
                    required
                >

                @error('lead_access_code')
                    <p id="code-error" class="registered-access__error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="registered-access__submit">
                Acessar formulário
            </button>
        </form>

        <div class="registered-access__footer">
            <a href="{{ route('simulation.registered-company.code.request') }}">
                Esqueci meu código
            </a>
        </div>
    </x-company-access-panel>

    @include('simulation.partials.company-access-submit')
@endsection
