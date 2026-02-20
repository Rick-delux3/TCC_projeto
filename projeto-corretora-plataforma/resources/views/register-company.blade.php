@extends('layout-inicial.app')

@section('content')


<div class="back d-flex">

    
    <div class="form row d-flex">
        <div class="col-12">
            <h2>Cadastro da Imobiliária</h2>
            <br>
            <form action="{{ route('empresa.register.post') }}" autocomplete="off" method="POST">
                @csrf
            
                <label for="name" class="form-label">Nome:</label>
                <input type="text" name="name" id="name" class="form-control" required>
            
                <br>
            
                <label for="phone" class="form-label">Telefone:</label>
                <input type="tel" name="phone" id="phone" class="form-control" required>
            
                <br>
            
                <label for="password" class="form-label ">Criar Senha:</label>
                <input type="password" name="password" id="password" class="form-control" required>
                <br>
                <input type="password" name="password_confirmation" id="password-conf" class="form-control" placeholder="Confirmar Senha" >
            
                <br>
            
                <label for="email" class="form-label">Email</label>
                <input type="email" name="email" id="email" class="form-control" required>
            
                <br>
            
                <button type="submit" class="botao_cadas">Cadastrar</button>
                
            </form>
            <br>
            <p>Já tem uma conta?
                <a href="{{ route('empresa.login') }}" class="link-login">Entrar aqui</a>
            </p>
        </div>
        
    </div>
</div>


@endsection