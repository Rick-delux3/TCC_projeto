@extends('layout-inicial.simulation')

@section('hide-header', 'true')
@section('main-class', 'registered-access-main')
@section('inline-feedback', 'true')

@section('content')
    <x-company-access-panel illustration="imgs/seguro-aluguel-recuperacao.webp" title-id="recovery-title" class="registered-access--recovery">
        @if (session('company_code_retry_after'))
            @include('simulation.company-code-recovery-failure')
        @elseif (session('status'))
            @include('simulation.company-code-recovery-success')
        @else
            <div class="registered-access__intro">
                <p class="registered-access__context">Simulação de seguro aluguel</p>
                <h1 id="recovery-title">Recuperar código de acesso</h1>
                <p>
                    {{ \App\Support\BrandingText::choose(
                        'Informe o e-mail cadastrado da sua imobiliária para solicitar o código de acesso.',
                        'Informe o e-mail cadastrado da sua empresa para solicitar o código de acesso.',
                    ) }}
                </p>
            </div>

            <form action="{{ route('simulation.registered-company.code.email') }}" method="POST" class="registered-access__form" data-company-access-form>
                @csrf

                <div class="flex flex-col gap-2">
                    <label for="email">E-mail cadastrado</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        autocomplete="email"
                        inputmode="email"
                        autocapitalize="none"
                        spellcheck="false"
                        aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                        @error('email') aria-describedby="email-error" @enderror
                        value="{{ is_scalar(old('email')) ? old('email') : '' }}"
                        placeholder="contato@exemplo.com.br"
                        maxlength="255"
                        required
                    >

                    @error('email')
                        <p id="email-error" class="registered-access__error" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="registered-access__submit">
                    Solicitar código
                </button>
            </form>

            <div class="registered-access__footer">
                <a href="{{ route('simulation.registered-company.access') }}">Voltar para o acesso</a>
            </div>
        @endif
    </x-company-access-panel>

    @include('simulation.partials.company-access-submit')
@endsection
