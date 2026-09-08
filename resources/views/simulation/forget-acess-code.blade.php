@extends('layout-inicial.simulation')
@section('inline-feedback', 'true')
@section('content')
@include('simulation.partials.company-access-style')
<section class="company-access-page" aria-labelledby="recovery-title">
    <div class="company-access-card flex flex-col gap-6">
        @if (session('company_code_retry_after'))
            @include('simulation.company-code-recovery-failure')
        @elseif (session('status'))
            @include('simulation.company-code-recovery-success')
        @else
            <svg class="access-icon" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m4 24 28-20 28 20v34H4V24Zm0 0 28 21 28-21M4 58l20-19m36 19L40 39M13 30V14h38v16"/><path class="accent" d="M35 20a6 6 0 0 0-5 9L19 40l4 4 4-4v-4h4l4-4a6 6 0 1 0 0-12Z"/><circle class="accent" cx="37" cy="25" r="1"/></svg>
            <div class="flex flex-col gap-3">
                <h1 id="recovery-title">Receba seu código por email</h1>
                <p>Informe o email cadastrado da sua imobiliária para receber novamente o código de acesso aos formulários públicos.</p>
            </div>
            <form action="{{ route('simulation.registered-company.code.email') }}" method="POST" class="flex flex-col gap-6" data-company-access-form>
                @csrf
                <div class="flex flex-col gap-2">
                    <label for="email">E-mail cadastrado</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="contato@imobiliaria.com.br" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" maxlength="255" required aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @error('email') aria-describedby="email-error" @enderror>
                    @error('email')
                        <p id="email-error" class="access-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="access-button">Solicitar</button>
            </form>
            <p>O envio será feito para o email vinculado ao cadastro.</p>
        @endif
        <div class="access-divider">
            <a href="{{ route('simulation.registered-company.access') }}" class="access-back"><span aria-hidden="true">←</span> Voltar para o acesso</a>
        </div>
    </div>
</section>
@endsection
