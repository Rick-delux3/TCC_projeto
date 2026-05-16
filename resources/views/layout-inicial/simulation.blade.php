<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <link rel="icon" type="image/jpeg" href="{{ asset('imgs/Logo_NVS.png') }}">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" 
        rel="stylesheet" 
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" 
        crossorigin="anonymous"
    >

    @vite(['resources/css/simulation.css', 'resources/js/simulation.js'])

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link 
        href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400..900&family=Press+Start+2P&family=Roboto+Condensed:ital,wght@0,100..900;1,100..900&family=Sansation:ital,wght@0,300;0,400;0,700;1,300;1,400;1,700&family=TASA+Explorer:wght@400..800&display=swap" 
        rel="stylesheet"
    >

    <title>NVS</title>
</head>

<body class="auth-layout-body">

    @include('layout-inicial.partials.header_simulation')

    <main class="auth-layout-main">
        @yield('content')
    </main>

    {{-- Modal de erros de validação --}}
    @if ($errors->any())
        <div class="modal fade" id="modalErrors" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">Erro no formulário</h5>

                        <button 
                            type="button" 
                            class="btn-close btn-close-white" 
                            data-bs-dismiss="modal" 
                            aria-label="Fechar"
                        ></button>
                    </div>

                    <div class="modal-body">
                        @foreach ($errors->all() as $erro)
                            <p class="mb-2">• {{ $erro }}</p>
                        @endforeach
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">
                            Entendi
                        </button>
                    </div>

                </div>
            </div>
        </div>
    @endif

    {{-- Modal de sucesso --}}
    @if (session('success'))
        <div class="modal fade" id="modalSuccess" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">Sucesso</h5>

                        <button 
                            type="button" 
                            class="btn-close btn-close-white" 
                            data-bs-dismiss="modal" 
                            aria-label="Fechar"
                        ></button>
                    </div>

                    <div class="modal-body">
                        {{ session('success') }}
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Fechar
                        </button>
                    </div>

                </div>
            </div>
        </div>
    @endif

    @include('partials.page-loader')

    <script 
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" 
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" 
        crossorigin="anonymous">
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modalErrorsElement = document.getElementById('modalErrors');
            const modalSuccessElement = document.getElementById('modalSuccess');

            if (modalErrorsElement) {
                const modalErrors = new bootstrap.Modal(modalErrorsElement);
                modalErrors.show();
            }

            if (modalSuccessElement) {
                const modalSuccess = new bootstrap.Modal(modalSuccessElement);
                modalSuccess.show();
            }
        });
    </script>
    @stack('scripts')
</body>
</html>