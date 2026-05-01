{{-- 
  Email: Codigo de Verificacao 2FA
  
  Template de email com codigo de autenticacao de dois fatores.
  Exibe: titulo, instrucoes, codigo em fonte grande (32px) com letter-spacing.
  Aviso: codigo expira em 10 minutos.
  
  Variavel: $code (string - 6 digitos)
--}}

<!DOCTYPE html>
<html>
<body>
    <h2>Seu código de verificação</h2>

    <p>Use o código abaixo para finalizar seu login:</p>

    <h1 style="font-size: 32px; letter-spacing: 5px;">
        {{ $code }}
    </h1>

    <p>Este código expira em 10 minutos.</p>
</body>
</html>