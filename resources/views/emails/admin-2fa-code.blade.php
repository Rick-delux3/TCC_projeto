<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Código de verificação</title>
</head>
<body style="font-family: Arial, sans-serif; color: #222;">
    <h2>Verificação de primeiro acesso</h2>

    <p>Olá, {{ $admin->name ?? 'corretor' }}.</p>

    <p>
        Recebemos uma tentativa de primeiro acesso ao painel administrativo da corretora.
    </p>

    <p>Use o código abaixo para confirmar sua identidade:</p>

    <p style="font-size: 28px; font-weight: bold; letter-spacing: 4px;">
        {{ $code }}
    </p>

    <p>
        Este código expira às <strong>{{ $expiresAt }}</strong>.
    </p>

    <p>
        Se você não tentou acessar o sistema, ignore este e-mail e avise o administrador.
    </p>
</body>
</html>