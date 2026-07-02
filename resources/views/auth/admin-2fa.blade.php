@extends('layout-inicial.app')

@section('content')
<div class="container min-vh-100 d-flex align-items-center justify-content-center">
    <div class="card shadow-sm border-0" style="max-width: 460px; width: 100%;">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <h4 class="fw-bold mb-1">Verificação de primeiro acesso</h4>
                <p class="text-muted mb-0">
                    Digite o código de 6 dígitos enviado para seu e-mail.
                </p>
            </div>

            @if (session('success'))
                <div class="alert alert-success">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('info'))
                <div class="alert alert-info">
                    {{ session('info') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.2fa.verify') }}">
                @csrf

                <div class="mb-3">
                    <label for="code" class="form-label">Código de verificação</label>
                    <input
                        type="text"
                        name="code"
                        id="code"
                        class="form-control form-control-lg text-center"
                        maxlength="6"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        placeholder="000000"
                        required
                        autofocus
                    >
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    Confirmar código
                </button>
            </form>

            <form method="POST" action="{{ route('admin.2fa.resend') }}" class="mt-3 text-center">
                @csrf

                <button type="submit" class="btn btn-link text-decoration-none">
                    Reenviar código
                </button>
            </form>

            <form method="POST" action="{{ route('admin.logout') }}" class="mt-2 text-center">
                @csrf

                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    Sair
                </button>
            </form>

            <div class="mt-4 small text-muted text-center">
                Por segurança, o código expira em 10 minutos.
            </div>
        </div>
    </div>
</div>
@endsection