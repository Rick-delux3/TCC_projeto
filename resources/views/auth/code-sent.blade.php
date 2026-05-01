{{-- 
  Pagina: Codigo Enviado
  
  Mensagem informativa: codigo foi enviado para o email do usuario.
  Exibe email do usuario logado.
  Botao de acao: link para pagina de verificacao do codigo.
  
  Layout: layouts.app
  Endpoint: route('2fa') - verificacao do codigo
--}}

@extends('layouts.app')

@section('content')
<div class="container">
    <div class="alert alert-info mt-5">
        Um código foi enviado para o e-mail <strong>{{ auth()->user()->email }}</strong>.
    </div>

    <a href="{{ route('2fa') }}" class="btn btn-primary">Verificar Código</a>
</div>
@endsection
