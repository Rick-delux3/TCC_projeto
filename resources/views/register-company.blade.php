@extends('layout-inicial.app')

@section('content')


<div class="back d-flex">

    
    <div class="form row d-flex register-split">
        <div class="col-12 contain">
            <h2>Cadastro da Imobiliária</h2>
            <br>
            <form action="{{ route('empresa.register.post') }}" autocomplete="off" method="POST" class="row">
                @csrf
                
                <div class="col-12">
                    <label for="name" class="form-label">Nome:</label>
                    <input type="text" name="name" id="name" class="form-control" required>
                </div>
            
                <div class="col-12">
                    <label for="phone" class="form-label">Telefone:</label>
                    <input type="tel" name="phone" id="phone" class="form-control" required>

                </div>
            
                <div class="col-md-6">
                    <label for="city" class="form-label">Cidade:</label>
                    <input type="text" name="city" id="city" class="form-control">
                </div>

                <div class="col-md-6">
                    <label for="state" class="form-label">Estado:</label>
                    <select name="state" id="state" class="form-select select">
                        <option selected>Selecionar</option>
                        <option value="SP">SP</option>
                        <option value="AL">AL</option>
                        <option value="AP">AP</option>
                        <option value="AM">AM</option>
                        <option value="BA">BA</option>
                        <option value="CE">CE</option>
                        <option value="DF">DF</option>
                        <option value="ES">ES</option>
                        <option value="GO">GO</option>
                        <option value="MA">MA</option>
                        <option value="MT">MT</option>
                        <option value="MS">MS</option>
                        <option value="MG">MG</option>
                        <option value="PA">PA</option>
                        <option value="PB">PB</option>
                        <option value="PR">PR</option>
                        <option value="PE">PE</option>
                        <option value="PI">PI</option>
                        <option value="RJ">RJ</option>
                        <option value="RN">RN</option>
                        <option value="RS">RS</option>
                        <option value="RR">RR</option>
                        <option value="RO">RO</option>
                        <option value="SC">SC</option>
                        <option value="SE">SE</option>
                        <option value="TO">TO</option>
                        <option value="AC">AC</option>
                    </select>
                </div>



                <div class="col-md-6">
                    <label for="password" class="form-label">Senha:</label>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label for="password-conf" class="form-label">Confirmar Senha:</label>
                    <input type="password" name="password_confirmation" id="password-conf" class="form-control">
                </div>
            
                <div class="col-12">
                    <label for="email" class="form-label">Email:</label>
                    <input type="email" name="email" id="email" class="form-control" required>
                </div>
                
            
            
                <button type="submit" class="botao_cadas">Cadastrar</button>
                
            </form>
            <p>Já tem uma conta?
                <a href="{{ route('empresa.login') }}" class="link-login">Entrar aqui</a>
            </p>
        </div>

        <div class="col-13"> 
        </div>
        
    </div>
</div>


@endsection
