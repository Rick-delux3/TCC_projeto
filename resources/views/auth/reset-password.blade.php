<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Senha</title>
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

        .reset-card {
            width: 100%;
            max-width: 410px;
            background: #fff;
            border: 1px solid rgba(33, 40, 191, 0.15);
            box-shadow: 0 14px 34px rgba(31, 29, 89, 0.18);
            border-radius: 10px;
            overflow: hidden;
        }

        .reset-head {
            background: linear-gradient(120deg, var(--primary-color), var(--secundary-color));
            color: var(--quartenary-color);
            padding: 14px 16px 12px;
            text-align: center;
        }

        .reset-head img {
            width: 92px;
            margin: 0 auto 8px;
        }

        .reset-head h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .reset-head p {
            margin: 6px 0 0;
            font-size: 13px;
            opacity: 0.95;
        }

        .reset-body {
            padding: 16px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--primary-color);
        }

        .form-control {
            border: 2px solid rgba(31, 29, 89, 0.25);
            border-radius: 6px;
            margin-bottom: 10px;
        }

        .form-control:focus {
            border-color: var(--secundary-color);
            box-shadow: 0 0 0 3px rgba(33, 40, 191, 0.2);
        }

        .btn-submit {
            width: 100%;
            margin-top: 6px;
            border: none;
            border-radius: 6px;
            padding: 10px 12px;
            font-weight: 700;
            color: #fff;
            background: var(--terciary-color);
        }

        .alert {
            margin-bottom: 10px;
            font-size: 13px;
            border-radius: 6px;
        }

        .password-field {
            position: relative;
        }

        .password-field .form-control {
            padding-right: 44px;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #1F1D59;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
            padding: 2px;
        }
    </style>
</head>
<body>
    <div class="reset-card">
        <div class="reset-head">
            <img src="{{ asset('imgs/logo-header.jpg') }}" alt="Logo">
            <h1>Definir nova senha</h1>
            <p>Informe seu e-mail e a nova senha para concluir.</p>
        </div>
        <div class="reset-body">
            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.store') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <label for="email" class="form-label">E-mail</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    class="form-control"
                    value="{{ old('email', $request->email) }}"
                    required
                    autocomplete="username"
                    autofocus
                >

                <label for="password" class="form-label">Nova senha</label>
                <div class="password-field">
                    <input
                        id="password"
                        type="password"
                        name="password"
                        class="form-control"
                        required
                        autocomplete="new-password"
                    >
                    <button type="button" class="toggle-password" data-toggle-password="password" aria-label="Mostrar senha">Ver</button>
                </div>

                <label for="password_confirmation" class="form-label">Confirmar nova senha</label>
                <div class="password-field">
                    <input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        class="form-control"
                        required
                        autocomplete="new-password"
                    >
                    <button type="button" class="toggle-password" data-toggle-password="password_confirmation" aria-label="Mostrar senha">Ver</button>
                </div>

                <button type="submit" class="btn-submit">Salvar nova senha</button>
            </form>
        </div>
    </div>
    @include('partials.page-loader')
    <script>
        document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-toggle-password'));
                if (!input) return;
                var showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                button.textContent = showing ? 'Ver' : 'Ocultar';
                button.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
            });
        });
    </script>
</body>
</html>
