<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seu código de verificação</title>
</head>
<body>
    <h2>Seu código de verificação</h2>

    <p>Use o código abaixo para finalizar seu login:</p>

    <h1 style="font-size: 32px; letter-spacing: 5px;">
        {{ $code }}
    </h1>

    <p>Este código expira às {{ $expiresAt }}.</p>

    <p>Se você não tentou acessar o sistema, ignore este e-mail.</p>
</body>
</html>
