{{-- 
  Pagina de Verificacao 2FA (Dois Fatores)
  
  Interface de autenticacao de dois fatores.
  Entrada de 6 digitos com confirmacao e opcao de reenvio de codigo.
  HTML puro sem layout extension - estilo bootstrap.
  
  Endpoint de dados: definido no form action
--}}

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Verificacao em Duas Etapas</title>
    <link rel="icon" type="image/png" href="{{ asset('imgs/Logo_NVS.png') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    @vite(['resources/css/app.css'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400..900&family=Press+Start+2P&family=Roboto+Condensed:ital,wght@0,100..900;1,100..900&family=Sansation:ital,wght@0,300;0,400;0,700;1,300;1,400;1,700&family=TASA+Explorer:wght@400..800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #030133;
            --secundary-color: #146FB6;
            --terciary-color: #FD1E6E;
            --quartenary-color: #FDFDFD;
            --font-principal: 'Sansation';
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: var(--font-principal);
        }

        .verify-shell {
            width: 100%;
            max-width: 940px;
            display: grid;
            grid-template-columns: minmax(0, 0.96fr) minmax(0, 1.04fr);
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(3, 1, 51, 0.1);
            background: #ffffff;
            box-shadow: 0 24px 55px rgba(15, 23, 42, 0.18);
            animation: shellRise 0.45s ease-out both;
        }

        .verify-aside {
            position: relative;
            min-height: 520px;
            background:
                radial-gradient(circle at 82% 15%, rgba(253, 30, 110, 0.32), transparent 35%),
                radial-gradient(circle at 18% 82%, rgba(255, 255, 255, 0.16), transparent 42%),
                linear-gradient(150deg, #030133 0%, #0a2b78 48%, #146FB6 100%);
        }

        .verify-aside img {
            width: 100%;
            height: 100%;
            min-height: 520px;
            object-fit: cover;
            display: block;
        }

        .verify-overlay {
            position: absolute;
            inset: 0;
            padding: 34px 28px;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            background: linear-gradient(180deg, rgba(3, 1, 51, 0.2), rgba(3, 1, 51, 0.88));
            color: #ffffff;
        }

        .verify-badge {
            width: fit-content;
            margin-bottom: 12px;
            padding: 5px 11px;
            border-radius: 999px;
            background: rgba(253, 30, 110, 0.92);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .verify-overlay h2 {
            margin: 0 0 10px 0;
            font-size: 28px;
            line-height: 1.18;
            font-weight: 800;
        }

        .verify-overlay p {
            margin: 0;
            font-size: 14px;
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.94);
        }

        .verify-card {
            padding: 34px 34px 30px;
            background: #ffffff;
            color: #0f172a;
        }

        .verify-brand {
            width: 118px;
            display: block;
            margin-bottom: 18px;
        }

        .verify-header h1 {
            margin: 0;
            font-size: 29px;
            line-height: 1.1;
            color: var(--primary-color);
            font-weight: 800;
        }

        .verify-header p {
            margin: 10px 0 22px 0;
            font-size: 14px;
            line-height: 1.55;
            color: #475569;
        }

        .status-box {
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 12px;
            border: 1px solid transparent;
        }

        .status-success {
            background: #eefbf3;
            border-color: #b7ebc6;
            color: #166534;
        }

        .status-info {
            background: #eef7ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }

        .status-error {
            background: #fff1f6;
            border-color: #fbcfe8;
            color: #be185d;
        }

        .verify-form {
            width: 100%;
        }

        .verify-label {
            display: block;
            margin-bottom: 6px;
            color: #1e293b;
            font-size: 13px;
            font-weight: 700;
        }

        .code-input {
            width: 100%;
            height: 54px;
            padding: 0 16px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            color: var(--primary-color);
            font-size: 28px;
            font-weight: 800;
            text-align: center;
            letter-spacing: 10px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .code-input:focus {
            outline: none;
            border-color: var(--secundary-color);
            box-shadow: 0 0 0 4px rgba(20, 111, 182, 0.14);
        }

        .verify-note {
            margin: 12px 0 0 0;
            color: #64748b;
            font-size: 13px;
            line-height: 1.55;
        }

        .verify-actions {
            margin-top: 18px;
        }

        .verify-submit {
            width: 100%;
            border: none;
            border-radius: 10px;
            padding: 12px 16px;
            background: linear-gradient(125deg, var(--primary-color), var(--secundary-color));
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.3px;
            transition: transform 0.22s ease, filter 0.22s ease;
        }

        .verify-submit:hover {
            transform: translateY(-1px);
            filter: brightness(1.04);
        }

        .verify-resend {
            width: 100%;
            margin-top: 10px;
            border: 1px solid rgba(3, 1, 51, 0.16);
            border-radius: 10px;
            padding: 11px 16px;
            background: #ffffff;
            color: var(--primary-color);
            font-size: 14px;
            font-weight: 700;
            transition: border-color 0.22s ease, color 0.22s ease, background 0.22s ease;
        }

        .verify-resend:hover {
            border-color: var(--terciary-color);
            color: var(--terciary-color);
            background: #fff5f9;
        }

        .verify-tip {
            margin: 16px 0 0 0;
            font-size: 12px;
            line-height: 1.6;
            color: #64748b;
        }

        @keyframes shellRise {
            from {
                opacity: 0;
                transform: translateY(16px) scale(0.985);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @media (max-width: 991px) {
            .verify-shell {
                grid-template-columns: 1fr;
                max-width: 620px;
            }

            .verify-aside,
            .verify-aside img {
                min-height: 280px;
            }

            .verify-overlay {
                padding: 24px 20px;
            }

            .verify-card {
                padding: 26px 22px 24px;
            }
        }

        @media (max-width: 640px) {
            .verify-overlay h2 {
                font-size: 24px;
            }

            .verify-header h1 {
                font-size: 25px;
            }

            .verify-brand {
                width: 102px;
            }

            .code-input {
                font-size: 24px;
                letter-spacing: 7px;
            }
        }
    </style>
</head>
<body>
    <div class="standalone-auth-page">
        @include('auth.partials.auth-topbar')
        <main class="standalone-auth-main">
            <section class="verify-shell">
                <aside class="verify-aside">
                    <img src="{{ asset('imgs/trans_bg.png') }}" alt="Seguranca de acesso">
                    <div class="verify-overlay">
                        <span class="verify-badge">Etapa de Seguranca</span>
                        <h2>Confirme sua identidade para continuar no portal.</h2>
                        <p>
                            Enviamos um codigo temporario para o e-mail da sua empresa.
                            Essa verificacao protege o acesso e evita entradas indevidas.
                        </p>
                    </div>
                </aside>

                <div class="verify-card">
                    <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="NVS Seguros" class="verify-brand">

                    <header class="verify-header">
                        <h1>Codigo de verificacao</h1>
                        <p>Digite os 6 numeros enviados para o seu e-mail para liberar o acesso ao sistema.</p>
                    </header>

                    @if (session('success'))
                        <div class="status-box status-success" role="alert">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if (session('info'))
                        <div class="status-box status-info" role="alert">
                            {{ session('info') }}
                        </div>
                    @endif

                    @if ($errors->has('code'))
                        <div class="status-box status-error" role="alert">
                            {{ $errors->first('code') }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('2fa.verify.post') }}" class="verify-form">
                        @csrf

                        <label class="verify-label" for="code">Codigo de 6 digitos</label>
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

                        <p class="verify-note">
                            O codigo expira em poucos minutos. Se ele nao chegar, voce pode solicitar um novo envio.
                        </p>

                        <div class="verify-actions">
                            <button type="submit" class="verify-submit">Confirmar e entrar</button>
                        </div>
                    </form>

                    <form action="{{ route('2fa.resend') }}" method="POST">
                        @csrf
                        <button type="submit" class="verify-resend">Reenviar codigo</button>
                    </form>

                    <p class="verify-tip">
                        Nao encontrou o e-mail? Confira a caixa de spam, promocoes ou lixo eletronico.
                    </p>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    @include('partials.page-loader')
</body>
</html>
