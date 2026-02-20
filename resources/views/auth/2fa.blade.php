<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificacao em Duas Etapas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400..900&family=Press+Start+2P&family=Roboto+Condensed:ital,wght@0,100..900;1,100..900&family=Sansation:ital,wght@0,300;0,400;0,700;1,300;1,400;1,700&family=TASA+Explorer:wght@400..800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1F1D59;
            --secundary-color: #2128BF;
            --terciary-color: #EE1D23;
            --quartenary-color: #F2F2F2;
            --font-principal: 'Sansation';
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 20px;
            background: linear-gradient(140deg, #f2f2f2 0%, #dde0f8 100%);
            font-family: var(--font-principal);
        }

        .twofa-card {
            width: 100%;
            max-width: 380px;
            background: #ffffff;
            border: 1px solid rgba(33, 40, 191, 0.15);
            box-shadow: 0 14px 34px rgba(31, 29, 89, 0.18);
            border-radius: 10px;
            overflow: hidden;
            animation: fadeInUp 0.45s ease-out forwards;
        }

        .twofa-head {
            background: linear-gradient(120deg, var(--primary-color), var(--secundary-color));
            color: var(--quartenary-color);
            padding: 14px 16px 10px;
            text-align: center;
        }

        .twofa-head img {
            width: 92px;
            margin: 0 auto 8px;
        }

        .twofa-head h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .twofa-head p {
            margin: 6px 0 0;
            font-size: 13px;
            opacity: 0.95;
        }

        .twofa-body {
            padding: 16px;
        }

        .label-code {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--primary-color);
        }

        .code-input {
            width: 100%;
            height: 46px;
            border: 2px solid rgba(31, 29, 89, 0.25);
            border-radius: 6px;
            text-align: center;
            letter-spacing: 8px;
            font-size: 22px;
            color: var(--primary-color);
            font-weight: 700;
        }

        .code-input:focus {
            outline: none;
            border-color: var(--secundary-color);
            box-shadow: 0 0 0 3px rgba(33, 40, 191, 0.2);
        }

        .btn-confirm {
            width: 100%;
            margin-top: 12px;
            border: none;
            border-radius: 6px;
            padding: 10px 12px;
            font-weight: 700;
            color: #fff;
            background: var(--terciary-color);
            transition: 0.2s ease;
        }

        .btn-confirm:hover {
            transform: translateY(-1px);
            filter: brightness(1.03);
        }

        .btn-resend {
            width: 100%;
            margin-top: 8px;
            border: 1px solid rgba(33, 40, 191, 0.25);
            border-radius: 6px;
            padding: 9px 12px;
            font-weight: 700;
            color: var(--secundary-color);
            background: #ffffff;
            transition: 0.2s ease;
        }

        .btn-resend:hover {
            background: rgba(33, 40, 191, 0.06);
        }

        .helper {
            margin-top: 10px;
            font-size: 12px;
            text-align: center;
            color: #4b5563;
        }

        .alert {
            margin-bottom: 10px;
            font-size: 13px;
            border-radius: 6px;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <div class="twofa-card">
        <div class="twofa-head">
            <img src="{{ asset('imgs/logo-header.jpg') }}" alt="Logo">
            <h1>Verificacao 2FA</h1>
            <p>Digite o codigo de 6 digitos enviado ao seu e-mail.</p>
        </div>

        <div class="twofa-body">
            @if (session('success'))
                <div class="alert alert-success" role="alert">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('info'))
                <div class="alert alert-info" role="alert">
                    {{ session('info') }}
                </div>
            @endif

            @if ($errors->has('code'))
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first('code') }}
                </div>
            @endif

            <form method="POST" action="{{ route('2fa.verify.post') }}">
                @csrf
                <label class="label-code" for="code">Codigo de verificacao</label>
                <input
                    class="code-input"
                    type="text"
                    id="code"
                    name="code"
                    maxlength="6"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    placeholder="000000"
                    required
                >
                <button type="submit" class="btn-confirm">Confirmar codigo</button>
            </form>

            <form action="{{ route('2fa.resend') }}" method="POST">
                @csrf
                <button type="submit" class="btn-resend">Reenviar codigo</button>
            </form>

            <p class="helper">Nao encontrou o e-mail? Verifique spam ou lixo eletronico.</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    @include('partials.page-loader')
</body>
</html>
