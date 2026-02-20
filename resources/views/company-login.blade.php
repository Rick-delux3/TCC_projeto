@extends('layout-inicial.app')

@section('content')

<div class="back">
    <div class="form-login row d-flex">
        <div class="col-12_2">
            <h2>Login da Imobiliária</h2>
            <br>
            <form action="{{ route('empresa.login.post') }}" autocomplete="off" method="POST">
                @csrf
                <label for="email" class="form-label">Email:</label>
                <input type="email" name="email" placeholder="exemplo123@gmail.com" class="form-control" required>

                <label for="password" class="form-label">Senha</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="eXemPl031" required>
                <div class="password-recovery-box">
                    <span class="password-recovery-label">Esqueceu sua senha?</span>
                    <a href="{{ route('company.password.request') }}" class="password-recovery-link">Redefinir senha</a>
                </div>

                <button type="submit" class="button-login">Entrar</button>
            </form>
            <br>
            <p>Quer criar outra conta? <a href="{{ route('empresa.register.form') }}" class="link-register">Sim</a></p>
        </div>
    </div>
</div>



@endsection
