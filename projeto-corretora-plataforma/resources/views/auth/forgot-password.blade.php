
@extends('layout-inicial.app')

@section('content')

<div class="back">
    <div class="form-login container row d-flex">
        <div class="col-12_2">
            <h2>Recuperar Senha</h2>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger">
                    {{ $errors->first('email') }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf

                <div class="mb-3">
                    <br>
                    <label for="email" class="form-label">Digite seu Email</label>
                    <input type="email" name="email" class="form-control"
                        value="{{ old('email') }}" required autofocus>
                </div>

                <button class="btn btn-primary w-100">
                    Enviar link de redefinição
                </button>
            </form>
        </div>
        
    </div>
</div>

@endsection

